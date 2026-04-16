/**
 * Feed List Controller
 * This script handles the Feed List table, AJAX toggling of feed status, and the transition into the Feed Editor View.
 */

(function ($) {
    'use strict';

    // Shortcut aliases for shared utilities and localized data
    const esc    = window.GFGS.esc; // HTML escape helper from admin.js
    const DATA   = window.gfgsData; // Localized data from PHP wp_localize_script
    const AJAX   = DATA && DATA.ajaxUrl; // Wordpress AJAX endpoint
    const NONCE  = DATA && DATA.nonce; // Security token
    const I18N   = (DATA && DATA.i18n)       || {}; // Translated strings
    const EVENTS = (DATA && DATA.feedEvents) || {}; // Feed trigger events (e.g. 'form submit')

    // Safety check: Exit if we aren't on the correct admin page
    if ( ! DATA || ! $('#gfgs-app').length ) return;

    // Define $app
    const $app = $('#gfgs-app');

    // Robust form ID detection: try gfgsData first, then URL param
    function getFormId() {
        if ( DATA && DATA.formId && parseInt( DATA.formId ) > 0 ) {
            return parseInt( DATA.formId );
        }
        // Fallback: read from URL ?id=123
        const params = new URLSearchParams( window.location.search );
        return parseInt( params.get('id') || params.get('form_id') || 0 );
    }

    /**
     * App State Object
     * Keep track of data currently loaded in the browser to avoid unnecessary API calls.
     */
    let state = {
        feeds:        DATA.feeds    || [], // List of existing feeds
        accounts:     DATA.accounts || [], // Connected Google Accounts
        fields:       DATA.fields   || [], // Form fields for mapping
        formId:       getFormId(), // Current Gravity Form Id
        editing:      null, // The feed currently being edited
        spreadsheets: [], // Cached list of Google Sheets for the dropdown
        sheets:       [], // Cached list of individual tabs/sheets
        headers:      [], // Cached header row from the selected sheet
        notice:       null, // Success/Error notifications
    };

    // $app is defined now, this is safe
    if ( ! state.formId ) {
        $app.html('<div class="gfgs-notice error">Could not determine form ID. Please refresh the page.</div>');
        return;
    }

    renderFeedList();

    // ── Feed List UI ─────────────────────────────────────────────────────────────

    /**
     * Renders the main dashboard showing all feeds for the current form.
     */
    function renderFeedList() {

        // Header with "Add New Feed" and "Connect Account" logic
        let html = `
        <div class="gfgs-header">
            <h2>${esc(I18N.feedList || 'Google Sheets Feeds')}</h2>
            <div style="display:flex; gap:10px; align-items:center;">
                ${ ! state.accounts.length
                    ? `<a href="${esc(DATA.addAccountUrl)}" class="button">+ Connect Google Account</a>`
                    : '' }
                <button class="button button-primary" id="gfgs-add-feed">
                    ${esc(I18N.addFeed || 'Add New Feed')}
                </button>
            </div>
        </div>`;

        // Display temporary success/error notices
        if ( state.notice ) {
            html += `<div class="gfgs-notice ${state.notice.type}">${esc(state.notice.msg)}</div>`;
            state.notice = null; // Clear notice after rendering
        }

        // Empty State UI
        if ( ! state.feeds.length ) {
            html += `
            <div class="gfgs-empty">
                <p>${esc(I18N.noFeeds || 'No feeds yet.')}</p>
                <button class="button button-primary" id="gfgs-add-feed-empty">
                    ${esc(I18N.addFeed || 'Add New Feed')}
                </button>
            </div>`;
        } else {
            // Data Table UI
            html += `
            <table class="gfgs-feed-table">
                <thead>
                    <tr>
                        <th>Feed Name</th>
                        <th>Sheet</th>
                        <th>Send On</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>`;

            // Loop through feeds in state and build table rows
            state.feeds.forEach(feed => {
                html += `
                <tr data-feed-id="${feed.id}">
                    <td><strong>${esc(feed.feed_name)}</strong></td>
                    <td>${esc(feed.sheet_name || '—')}</td>
                    <td>${esc(EVENTS[feed.send_event] || feed.send_event)}</td>
                    <td>
                        <label class="gfgs-toggle">
                            <input type="checkbox" class="gfgs-toggle-feed" data-id="${feed.id}" ${feed.is_active ? 'checked' : ''}>
                            <span class="gfgs-slider"></span>
                        </label>
                    </td>
                    <td>
                        <button class="gfgs-action-btn gfgs-edit-feed" data-id="${feed.id}">Edit</button>
                        <button class="gfgs-action-btn danger gfgs-delete-feed" data-id="${feed.id}">Delete</button>
                    </td>
                </tr>`;
            });

            html += '</tbody></table>';
        }

        $app.html(html);
        bindListEvents(); // Attack click listeners to new HTML
    }

    /**
     * Attach event listeners for the list view using Event Delegation.
     */
    function bindListEvents() {
        $app.off(); // Prevent duplicate event listeners

        // "Add Feed" buttons
        $app.on('click', '#gfgs-add-feed, #gfgs-add-feed-empty', () => startEditing(newFeed()));

        // "Edit" button: Find feed in state and pass it to editor
        $app.on('click', '.gfgs-edit-feed', function () {
            const id   = $(this).data('id');
            const feed = state.feeds.find(f => f.id == id);

            // Use JSON parse/stringify to create a "deep clone" so we don't modify the original list until the user clicks "Save"
            if ( feed ) startEditing(JSON.parse(JSON.stringify(feed)));
        });

        // "Delete" button logic
        $app.on('click', '.gfgs-delete-feed', function () {
            if ( ! confirm(I18N.confirmDel || 'Delete this feed?') ) return;
            const id = $(this).data('id');

            // Call PHP to delete from DB
            feedAjax('gfgs_delete_feed', { feed_id: id }, () => {
                state.feeds  = state.feeds.filter(f => f.id != id);
                state.notice = { type: 'success', msg: 'Feed deleted.' };
                renderFeedList();
            });
        });

        // "Active/Inactive" toggle switch logic
        $app.on('change', '.gfgs-toggle-feed', function () {
            const id     = $(this).data('id');
            const active = $(this).prop('checked') ? 1 : 0;

            // Update Database
            feedAjax('gfgs_toggle_feed', { feed_id: id, active }, () => {
                const f = state.feeds.find(f => f.id == id);
                if ( f ) f.is_active = active;
            });
        });
    }

    // ── Feed Editor Logic ───────────────────────────────────────────────────────────

    /**
     * Transition from the List view to Editor view.
     * @param {Object} feed - The feed object to edit. 
     */
    function startEditing(feed) {
        state.editing      = feed;
        state.spreadsheets = []; // Clear old cache
        state.sheets       = [];
        state.headers      = [];

        // Load editor via AJAX from PHP template
        $.post(AJAX, {
            action:  'gfgs_render_feed_editor',
            nonce:   NONCE,
            feed_id: feed.id || 0,
            form_id: state.formId,
        }, res => {
            if ( res.success ) {
                $app.html(res.data.html);
                bindEditorEvents(); 

                // Waterfall: load spreadsheets → sheets → headers for existing feeds
                if ( feed.account_id && feed.spreadsheet_id ) {
                    loadSpreadsheets(feed.account_id, () => {
                        loadSheets(feed.account_id, feed.spreadsheet_id, () => {
                            if ( feed.sheet_name ) {
                                loadHeaders(feed.account_id, feed.spreadsheet_id, feed.sheet_name);
                            }
                        });
                    });
                } else if ( feed.account_id ) {
                    loadSpreadsheets(feed.account_id);
                }
            } else {
                console.error('Feed editor error:', res.data);
                alert((res.data && res.data.message) || 'Could not load feed editor. Please try again.');
            }
        }).fail(function (jqXHR) {
            console.error('AJAX failed:', jqXHR.responseText);
            alert('Could not load feed editor. Please try again.');
        });
    }

    /**
     * Helper to create a skeleton for a new feed.
     */
    function newFeed() {
        return {
            id: 0,
            feed_name: '',
            is_active: 1,
            account_id: state.accounts.length ? state.accounts[0].id : '',
            send_event: 'form_submit',
            field_map: [],
            conditions: { enabled: false, action: 'send', logic: 'all', rules: [] }
        };
    }

    /**
     * Generic wrapper for basic Feed AJAX calls (Delete/Toggle)
     */
    function feedAjax(action, data, callback) {
        $.post(AJAX, { action, nonce: NONCE, ...data }, res => {
            if ( res.success && callback ) callback(res);
        });
    }
})(jQuery);