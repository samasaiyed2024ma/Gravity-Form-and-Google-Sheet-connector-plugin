/**
 * Feed List Controller
 * Handles the Feed List table, AJAX toggling of feed status,
 * and the Feed Editor view.
 *
 * Custom template syntax (when field type = "Custom Value"):
 *   {28}        → full formatted value of field 28
 *   {28.3}      → raw sub-field value (e.g. quantity, price)
 *   {28:label}  → choice label(s)
 *   {28:value}  → choice value(s)
 *
 * Multi-line templates: write one expression per line.
 * Each line is resolved independently. Empty results are skipped.
 *
 * Examples:
 *   {5} - {7}
 *   {26:label} - {28.3}
 *   {26:label} - {29.3}
 *   {26:label} - {30.3}
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
        formId:       getFormId(),          // Current Gravity Form Id
        editing:      null,                 // The feed currently being edited
        spreadsheets: [],                   // Cached list of Google Sheets for the dropdown
        sheets:       [],                   // Cached list of individual tabs/sheets
        headers:      [],                   // Cached header row from the selected sheet
        notice:       null,                 // Success/Error notifications
    };

    if ( ! state.formId ) {
        $app.html('<div class="gfgs-notice error">Could not determine form ID. Please refresh the page.</div>');
        return;
    }

    renderFeedList();

    // ── Feed List UI ──────────────────────────────────────────────────────────

    /**
     * Renders the main dashboard showing all feeds for the current form.
     */
    function renderFeedList() {
        let html = `<div class="gfgs-header">
            <h2>${esc(I18N.feedList || 'Google Sheets Feeds')}</h2>
            <div class="gfgs-header-actions">
                ${!state.accounts.length ? `<a href="${esc(DATA.addAccountUrl)}" class="gfgs-btn gfgs-btn-outline">+ Connect Google Account</a>` : ''}
                <button class="gfgs-btn gfgs-btn-primary" id="gfgs-add-feed">+ Add New Feed</button>
            </div>
        </div>`;

        if (state.notice) {
            html += `<div class="gfgs-notice ${esc(state.notice.type)}"><p>${esc(state.notice.msg)}</p></div>`;
            state.notice = null;
        }

        if (!state.feeds.length) {
            html += `<div class="gfgs-empty-state">
                <div class="gfgs-empty-icon">📊</div>
                <h3>No feeds yet</h3>
                <p>Create a feed to start sending form entries to Google Sheets.</p>
                <button class="gfgs-btn gfgs-btn-primary" id="gfgs-add-feed-empty">Create Your First Feed</button>
            </div>`;
        } else {
            html += `<div class="gfgs-table-wrap"><table class="gfgs-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Feed Name</th>
                        <th>Spreadsheet / Sheet</th>
                        <th>Send On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>`;
            state.feeds.forEach(feed => {
                const eventLabel = EVENTS[feed.send_event] || feed.send_event || '—';
                const sheetInfo  = feed.sheet_name
                    ? `<span class="gfgs-sheet-badge">${esc(feed.sheet_name)}</span>`
                    : '<span class="gfgs-muted">—</span>';
                html += `<tr data-feed-id="${feed.id}">
                    <td>
                        <button
                            class="gfgs-status-badge ${feed.is_active ? 'gfgs-status-active' : 'gfgs-status-inactive'} gfgs-toggle-feed"
                            data-id="${feed.id}"
                            data-active="${feed.is_active ? 1 : 0}"
                        >${feed.is_active ? 'Active' : 'Inactive'}</button>
                    </td>
                    <td><a href="#" class="gfgs-edit-feed gfgs-feed-name-link" data-id="${feed.id}"><strong>${esc(feed.feed_name)}</strong></a></td>
                    <td>${sheetInfo}</td>
                    <td><span class="gfgs-event-badge">${esc(eventLabel)}</span></td>
                    <td class="gfgs-actions-cell">
                        <button class="gfgs-btn gfgs-btn-sm gfgs-edit-feed" data-id="${feed.id}">Edit</button>
                        <button class="gfgs-btn gfgs-btn-sm gfgs-btn-danger gfgs-delete-feed" data-id="${feed.id}">Delete</button>
                    </td>
                </tr>`;
            });
            html += '</tbody></table></div>';
        }

        $app.html(html);
        bindListEvents();
    }

    /**
     * Attach event listeners for the list view using Event Delegation.
     */
    function bindListEvents() {
        $app.off();

        $app.on('click', '#gfgs-add-feed, #gfgs-add-feed-empty', () => startEditing(newFeed()));

        $app.on('click', '.gfgs-edit-feed', function () {
            const id   = $(this).data('id');
            const feed = state.feeds.find(f => f.id == id);
            if (feed) startEditing(JSON.parse(JSON.stringify(feed)));
        });

        $app.on('click', '.gfgs-delete-feed', function () {
            if (!confirm(I18N.confirmDel || 'Delete this feed?')) return;
            const id = $(this).data('id');
            feedAjax('gfgs_delete_feed', { feed_id: id }, () => {
                state.feeds = state.feeds.filter(f => f.id != id);
                state.notice = { type: 'success', msg: 'Feed deleted successfully.' };
                renderFeedList();
            });
        });

        // Toggle status on the Feed List page
        $app.on('click', '.gfgs-toggle-feed', function () {
            const $btn      = $(this);
            const id        = $btn.data('id');
            const newActive = $btn.data('active') == 1 ? 0 : 1; // flip current state
            feedAjax('gfgs_toggle_feed', { feed_id: id, active: newActive }, () => {
                // Sync state
                const f = state.feeds.find(f => f.id == id);
                if (f) f.is_active = newActive;
                // Update button in-place without full re-render
                $btn.data('active', newActive)
                    .text(newActive ? 'Active' : 'Inactive')
                    .removeClass('gfgs-status-active gfgs-status-inactive')
                    .addClass(newActive ? 'gfgs-status-active' : 'gfgs-status-inactive');
            });
        });
    }

    // ── Feed Editor Logic ─────────────────────────────────────────────────────

    /**
     * Returns a blank feed object for the "Add New Feed" flow.
     */
    function newFeed() {
        return {
            id:             0,
            form_id:        state.formId,
            feed_name:      '',
            is_active:      1,
            account_id:     state.accounts.length ? state.accounts[0].id : '',
            spreadsheet_id: '',
            sheet_id:       '',
            sheet_name:     '',
            send_event:     'form_submit',
            field_map:      [],
            date_formats:   {},
            conditions:     { enabled: false, action: 'send', logic: 'all', rules: [] },
        };
    }

    /**
     * Transition from the List view to the Editor view.
     * @param {Object} feed - The feed object to edit.
     */
    function startEditing(feed) {
        state.editing      = feed;
        state.spreadsheets = [];
        state.sheets       = [];
        state.headers      = [];
        renderEditor();
    }

    function renderEditor() {
        const feed        = state.editing;
        const isNew       = !feed.id;
        const accounts    = state.accounts;
        const dateFormats = feed.date_formats || {};

        // ── Build select option strings ──────────────────────────────────────
        let accountOptions = `<option value="">— Select Account —</option>`;
        accounts.forEach(a => {
            accountOptions += `<option value="${a.id}" ${feed.account_id == a.id ? 'selected' : ''}>${esc(a.account_name || a.email)}</option>`;
        });

        let ssOptions = `<option value="">— Select Spreadsheet —</option>`;
        state.spreadsheets.forEach(ss => {
            ssOptions += `<option value="${esc(ss.id)}" ${feed.spreadsheet_id == ss.id ? 'selected' : ''}>${esc(ss.name)}</option>`;
        });

        let sheetOptions = `<option value="">— Select Sheet Tab —</option>`;
        state.sheets.forEach(sh => {
            sheetOptions += `<option value="${esc(sh.title)}" ${feed.sheet_name == sh.title ? 'selected' : ''}>${esc(sh.title)}</option>`;
        });

        const eventOptions = [
            { value: 'form_submit',          label: 'Form Submission' },
            { value: 'payment_completed',    label: 'Payment Completed' },
            { value: 'payment_refunded',     label: 'Payment Refunded' },
            { value: 'submission_completed', label: 'After All Notifications Sent' },
            { value: 'entry_updated',        label: 'Entry Updated' },
        ].map(e => `<option value="${e.value}" ${feed.send_event === e.value ? 'selected' : ''}>${e.label}</option>`).join('');

        const fieldMapHtml   = renderFieldMap(feed.field_map, state.headers, state.fields, dateFormats);
        const conditionsHtml = renderConditions(feed.conditions, state.fields);

        // ── is_active: read from state.editing, not a checkbox ───────────────
        const isActive = feed.is_active ? 1 : 0;

        const html = `
        <div class="gfgs-editor">
            <div class="gfgs-editor-header">
                <button class="gfgs-btn gfgs-btn-ghost" id="gfgs-back">← Back to Feeds</button>
                <h2>${isNew ? 'New Feed' : 'Edit Feed'}</h2>
                <button
                    class="gfgs-status-badge ${isActive ? 'gfgs-status-active' : 'gfgs-status-inactive'} gfgs-toggle-feed"
                    data-id="${feed.id}"
                    data-active="${isActive}"
                    id="gfgs-status-toggle"
                >${isActive ? 'Active' : 'Inactive'}</button>
            </div>

            <div class="gfgs-editor-body">

                <!-- Feed Name -->
                <div class="gfgs-section">
                    <div class="gfgs-section-title">Feed Name</div>
                    <input type="text" id="gfgs-feed-name" class="gfgs-input" value="${esc(feed.feed_name)}" placeholder="e.g. Contact Form Responses">
                </div>

                <!-- Google Account -->
                <div class="gfgs-section">
                    <div class="gfgs-section-title">Google Account</div>
                    ${accounts.length === 0
                        ? `<p class="gfgs-hint">No accounts connected. <a href="${esc(DATA.addAccountUrl)}">Connect one here</a>.</p>`
                        : `<select id="gfgs-account-select" class="gfgs-select">${accountOptions}</select>`
                    }
                </div>

                <!-- Spreadsheet -->
                <div class="gfgs-section">
                    <div class="gfgs-section-title">Spreadsheet</div>
                    <div class="gfgs-row-inline">
                        <select id="gfgs-spreadsheet-select" class="gfgs-select" ${!accounts.length ? 'disabled' : ''}>${ssOptions}</select>
                        <button class="gfgs-btn gfgs-btn-refresh" id="gfgs-refresh-spreadsheets">↻ Refresh Spreadsheets</button>
                        <span class="gfgs-spinner" id="gfgs-ss-spinner" style="display:none"></span>
                    </div>
                </div>

                <!-- Sheet Tab -->
                <div class="gfgs-section">
                    <div class="gfgs-section-title">Sheet Tab</div>
                    <div class="gfgs-row-inline">
                        <select id="gfgs-sheet-select" class="gfgs-select" ${!feed.spreadsheet_id ? 'disabled' : ''}>${sheetOptions}</select>
                        <span class="gfgs-spinner" id="gfgs-sh-spinner" style="display:none"></span>
                    </div>
                </div>

                <!-- Send Trigger -->
                <div class="gfgs-section">
                    <div class="gfgs-section-title">When to Send</div>
                    <select id="gfgs-send-event" class="gfgs-select">${eventOptions}</select>
                </div>

                <!-- Field Mapping -->
                <div class="gfgs-section">
                    <div class="gfgs-section-title">
                        Field Mapping
                        <div class="gfgs-section-title-actions">
                            <button class="gfgs-btn gfgs-btn-refresh" id="gfgs-refresh-fields">↻ Refresh Sheet Columns</button>
                            <span class="gfgs-spinner" id="gfgs-hd-spinner" style="display:none"></span>
                        </div>
                    </div>
                    <div id="gfgs-field-mapper">${fieldMapHtml}</div>
                    <div id="gfgs-add-field-row" class="gfgs-add-field-row" style="${state.headers.length ? '' : 'display:none'}">
                        <select id="gfgs-new-column-select" class="gfgs-select gfgs-select-sm">
                            <option value="">— Add Column —</option>
                            ${renderUnmappedColumnOptions(feed.field_map, state.headers)}
                        </select>
                        <button class="gfgs-btn gfgs-btn-outline gfgs-btn-sm" id="gfgs-add-mapping">+ Add Field</button>
                    </div>
                </div>

                <!-- Conditional Logic -->
                <div class="gfgs-section">
                    <div class="gfgs-section-title">Conditional Logic</div>
                    <div id="gfgs-conditions">${conditionsHtml}</div>
                </div>

            </div><!-- .gfgs-editor-body -->

            <div class="gfgs-editor-footer">
                <button class="gfgs-btn gfgs-btn-primary" id="gfgs-save-feed">Save Feed</button>
                <span class="gfgs-save-status" id="gfgs-save-status"></span>
            </div>
        </div>`;

        $app.html(html);
        bindEditorEvents();

        // Auto-load dropdowns for existing feeds
        if (feed.account_id && !state.spreadsheets.length) {
            loadSpreadsheets(feed.account_id, () => {
                if (feed.spreadsheet_id && !state.sheets.length) {
                    loadSheets(feed.account_id, feed.spreadsheet_id, () => {
                        if (feed.sheet_name && !state.headers.length) {
                            loadHeaders(feed.account_id, feed.spreadsheet_id, feed.sheet_name);
                        }
                    });
                }
            });
        }
    }

    // ── Field Map Rendering ───────────────────────────────────────────────────

    function renderFieldMap(fieldMap, headers, formFields, dateFormats) {
        if (!headers.length && !fieldMap.length) {
            return `<p class="gfgs-hint">Select a sheet tab above to load columns and map your form fields.</p>`;
        }

        // If headers loaded, use them as the master list; else use saved field_map
        const columns = headers.length ? headers : fieldMap.map(m => m.sheet_column || m.column);
        let html = '';

        columns.forEach(col => {
            const mapping    = fieldMap.find(m => (m.sheet_column || m.column) === col) || {};
            const fieldId    = mapping.field_id   || mapping.gf_field || '';
            const fieldType  = mapping.field_type || 'standard';
            const dateFormat = (dateFormats && dateFormats[col]) || 'Y-m-d';

            html += `<div class="gfgs-mapper-row" data-column="${esc(col)}">
                <div class="gfgs-mapper-col-name">${esc(col)}</div>
                <div class="gfgs-mapper-controls">
                    <select class="gfgs-select gfgs-select-sm gfgs-field-type-select">
                        <option value="standard" ${fieldType === 'standard' ? 'selected' : ''}>Standard Field</option>
                        <option value="meta"     ${fieldType === 'meta'     ? 'selected' : ''}>Entry Meta</option>
                        <option value="custom"   ${fieldType === 'custom'   ? 'selected' : ''}>Custom Value</option>
                    </select>
                    ${renderFieldSelector(fieldType, fieldId, formFields, col)}
                </div>
                ${renderDateFormatRow(fieldId, fieldType, formFields, dateFormat, col)}
                <button class="gfgs-btn-icon gfgs-remove-mapping" data-col="${esc(col)}" title="Remove mapping">✕</button>
            </div>`;
        });

        return html || `<p class="gfgs-hint">No columns found. Refresh the sheet columns above.</p>`;
    }

    function renderFieldSelector(fieldType, fieldId, formFields, col) {
        if (fieldType === 'custom') {
            return `<textarea
                class="gfgs-input gfgs-textarea-sm gfgs-custom-value"
                rows="3"
                placeholder="e.g. {5} or multi-line:&#10;{26:label} - {28.3}&#10;{26:label} - {29.3}"
            >${esc(fieldId)}</textarea>`;
        }

        if (fieldType === 'meta') {
            const metaFields = [
                { id: 'entry_id',       label: 'Entry ID' },
                { id: 'date_created',   label: 'Date Created' },
                { id: 'source_url',     label: 'Source URL' },
                { id: 'user_ip',        label: 'User IP' },
                { id: 'created_by',     label: 'Created By (User ID)' },
                { id: 'payment_status', label: 'Payment Status' },
            ];
            let opts = `<option value="">— Select Meta —</option>`;
            metaFields.forEach(m => {
                opts += `<option value="${m.id}" ${fieldId === m.id ? 'selected' : ''}>${m.label}</option>`;
            });
            return `<select class="gfgs-select gfgs-select-sm gfgs-field-select">${opts}</select>`;
        }

        // Standard
        let opts = `<option value="">— Select Form Field —</option>`;
        formFields.forEach(f => {
            opts += `<option value="${esc(f.id)}" ${fieldId == f.id ? 'selected' : ''}>${esc(f.label)}</option>`;
        });
        return `<select class="gfgs-select gfgs-select-sm gfgs-field-select">${opts}</select>`;
    }

    function renderDateFormatRow(fieldId, fieldType, formFields, dateFormat, col) {
        const field  = formFields.find(f => f.id == fieldId);
        const isDate = field && field.type === 'date';
        if (!isDate || fieldType !== 'standard') return '';

        const formats = [
            { value: 'Y-m-d',     label: 'YYYY-MM-DD (2024-01-31)' },
            { value: 'm/d/Y',     label: 'MM/DD/YYYY (01/31/2024)' },
            { value: 'd/m/Y',     label: 'DD/MM/YYYY (31/01/2024)' },
            { value: 'd-m-Y',     label: 'DD-MM-YYYY (31-01-2024)' },
            { value: 'F j, Y',    label: 'January 31, 2024' },
            { value: 'j F Y',     label: '31 January 2024' },
            { value: 'timestamp', label: 'Unix Timestamp' },
        ];
        const opts = formats.map(f =>
            `<option value="${f.value}" ${dateFormat === f.value ? 'selected' : ''}>${f.label}</option>`
        ).join('');

        return `<div class="gfgs-date-format-row">
            <span class="gfgs-hint-inline">📅 Date format:</span>
            <select class="gfgs-select gfgs-select-xs gfgs-date-format" data-col="${esc(col)}">${opts}</select>
        </div>`;
    }

    function renderUnmappedColumnOptions(fieldMap, headers) {
        const mappedCols = fieldMap.map(m => m.sheet_column || m.column);
        return headers
            .filter(h => !mappedCols.includes(h))
            .map(h => `<option value="${esc(h)}">${esc(h)}</option>`)
            .join('');
    }

    // ── Conditions Rendering ──────────────────────────────────────────────────

    function renderConditions(conditions, formFields) {
        const enabled = conditions && conditions.enabled;
        const logic   = (conditions && conditions.logic) || 'all';
        const rules   = (conditions && conditions.rules) || [];

        let rulesHtml = '';
        rules.forEach((rule, i) => {
            rulesHtml += renderConditionRule(rule, i, formFields);
        });

        return `<div class="gfgs-conditions-wrap">
            <label class="gfgs-checkbox-label">
                <input type="checkbox" id="gfgs-cond-enabled" ${enabled ? 'checked' : ''}>
                Enable conditional logic (only send when conditions are met)
            </label>
            <div id="gfgs-cond-body" style="${enabled ? '' : 'display:none'}">
                <div class="gfgs-cond-header">
                    Send this feed when
                    <select id="gfgs-cond-logic" class="gfgs-select gfgs-select-xs">
                        <option value="all" ${logic === 'all' ? 'selected' : ''}>ALL</option>
                        <option value="any" ${logic === 'any' ? 'selected' : ''}>ANY</option>
                    </select>
                    of the following match:
                </div>
                <div id="gfgs-cond-rules">${rulesHtml}</div>
                <button class="gfgs-btn gfgs-btn-outline gfgs-btn-sm" id="gfgs-add-rule">+ Add Condition</button>
            </div>
        </div>`;
    }

    function renderConditionRule(rule, index, formFields) {
        const fieldId  = rule.field_id || '';
        const operator = rule.operator || 'is';
        const value    = rule.value    || '';

        let fieldOpts = `<option value="">— Field —</option>`;
        formFields.forEach(f => {
            fieldOpts += `<option value="${esc(f.id)}" ${fieldId == f.id ? 'selected' : ''}>${esc(f.label)}</option>`;
        });

        const operators = [
            { v: 'is',          l: 'is' },
            { v: 'isnot',       l: 'is not' },
            { v: 'contains',    l: 'contains' },
            { v: 'starts_with', l: 'starts with' },
            { v: 'ends_with',   l: 'ends with' },
            { v: '>',           l: 'greater than' },
            { v: '<',           l: 'less than' },
        ];
        const opOpts = operators.map(o =>
            `<option value="${o.v}" ${operator === o.v ? 'selected' : ''}>${o.l}</option>`
        ).join('');

        const field      = state.fields.find(f => f.id == fieldId);
        const valueInput = renderConditionValueInput(field, value, index);

        return `<div class="gfgs-cond-rule" data-index="${index}">
            <select class="gfgs-select gfgs-select-sm gfgs-cond-field">${fieldOpts}</select>
            <select class="gfgs-select gfgs-select-sm gfgs-cond-operator">${opOpts}</select>
            <div class="gfgs-cond-value-wrap">${valueInput}</div>
            <button class="gfgs-btn-icon gfgs-remove-rule" title="Remove">✕</button>
        </div>`;
    }

    function renderConditionValueInput(field, value, index) {
        if (field && field.choices && field.choices.length) {
            let opts = `<option value="">— Select —</option>`;
            field.choices.forEach(c => {
                opts += `<option value="${esc(c.value)}" ${value === c.value ? 'selected' : ''}>${esc(c.text || c.value)}</option>`;
            });
            return `<select class="gfgs-select gfgs-select-sm gfgs-cond-value">${opts}</select>`;
        }
        return `<input type="text" class="gfgs-input gfgs-cond-value" value="${esc(value)}" placeholder="Value">`;
    }

    // ── Editor Events ─────────────────────────────────────────────────────────

    function bindEditorEvents() {
        $app.off();

        // Back to list
        $app.on('click', '#gfgs-back', () => {
            state.editing = null;
            renderFeedList();
        });

        // ── STATUS TOGGLE in editor header ───────────────────────────────────
        // Does NOT call the AJAX toggle endpoint (the feed isn't saved yet / we
        // just keep it in state and persist it on Save Feed).
        $app.on('click', '#gfgs-status-toggle', function () {
            const $btn      = $(this);
            const newActive = $btn.data('active') == 1 ? 0 : 1;
            // Update state
            state.editing.is_active = newActive;
            // Update button UI
            $btn.data('active', newActive)
                .text(newActive ? 'Active' : 'Inactive')
                .removeClass('gfgs-status-active gfgs-status-inactive')
                .addClass(newActive ? 'gfgs-status-active' : 'gfgs-status-inactive');
        });

        // Account change → load spreadsheets
        $app.on('change', '#gfgs-account-select', function () {
            const accountId = $(this).val();
            state.editing.account_id     = accountId;
            state.editing.spreadsheet_id = '';
            state.editing.sheet_name     = '';
            state.spreadsheets = [];
            state.sheets       = [];
            state.headers      = [];
            if (accountId) loadSpreadsheets(accountId);
        });

        // Refresh spreadsheets button
        $app.on('click', '#gfgs-refresh-spreadsheets', function () {
            const accountId = $('#gfgs-account-select').val();
            if (!accountId) return;
            state.spreadsheets = [];
            loadSpreadsheets(accountId);
        });

        // Spreadsheet change → load sheet tabs
        $app.on('change', '#gfgs-spreadsheet-select', function () {
            const ssId      = $(this).val();
            const accountId = $('#gfgs-account-select').val();
            state.editing.spreadsheet_id = ssId;
            state.editing.sheet_name     = '';
            state.sheets   = [];
            state.headers  = [];
            $('#gfgs-sheet-select').html('<option value="">— Select Sheet Tab —</option>').prop('disabled', !ssId);
            refreshFieldMapper();
            if (ssId && accountId) loadSheets(accountId, ssId);
        });

        // Sheet tab change → load headers
        $app.on('change', '#gfgs-sheet-select', function () {
            const sheetName = $(this).val();
            const ssId      = $('#gfgs-spreadsheet-select').val();
            const accountId = $('#gfgs-account-select').val();
            state.editing.sheet_name = sheetName;
            state.headers = [];
            refreshFieldMapper();
            if (sheetName && ssId && accountId) loadHeaders(accountId, ssId, sheetName);
        });

        // Refresh sheet columns button
        $app.on('click', '#gfgs-refresh-fields', function () {
            const accountId = $('#gfgs-account-select').val();
            const ssId      = $('#gfgs-spreadsheet-select').val();
            const sheetName = $('#gfgs-sheet-select').val();
            if (!accountId || !ssId || !sheetName) return;
            state.headers = [];
            loadHeaders(accountId, ssId, sheetName);
        });

        // Field type change in mapper
        $app.on('change', '.gfgs-field-type-select', function () {
            const $row      = $(this).closest('.gfgs-mapper-row');
            const col       = $row.data('column');
            const type      = $(this).val();
            const $controls = $row.find('.gfgs-mapper-controls');
            $controls.find('.gfgs-field-select, .gfgs-custom-value, .gfgs-textarea-sm, .gfgs-hint').remove();
            $controls.append(renderFieldSelector(type, '', state.fields, col));
            $row.find('.gfgs-date-format-row').remove();
        });

        // Field select change in mapper (show date format row when needed)
        $app.on('change', '.gfgs-field-select', function () {
            const $row    = $(this).closest('.gfgs-mapper-row');
            const col     = $row.data('column');
            const fieldId = $(this).val();
            const field   = state.fields.find(f => f.id == fieldId);
            $row.find('.gfgs-date-format-row').remove();
            if (field && field.type === 'date') {
                const dateFormat = (state.editing.date_formats && state.editing.date_formats[col]) || 'Y-m-d';
                $row.find('.gfgs-mapper-controls').after(
                    renderDateFormatRow(fieldId, 'standard', state.fields, dateFormat, col)
                );
            }
        });

        // Remove a mapping row
        $app.on('click', '.gfgs-remove-mapping', function () {
            const col = $(this).data('col');
            state.editing.field_map = state.editing.field_map.filter(
                m => (m.sheet_column || m.column) !== col
            );
            $(this).closest('.gfgs-mapper-row').remove();
        });

        // Add a new column mapping
        $app.on('click', '#gfgs-add-mapping', function () {
            const col = $('#gfgs-new-column-select').val();
            if (!col) return;
            state.editing.field_map.push({ sheet_column: col, field_id: '', field_type: 'standard' });
            const rowHtml = `<div class="gfgs-mapper-row" data-column="${esc(col)}">
                <div class="gfgs-mapper-col-name">${esc(col)}</div>
                <div class="gfgs-mapper-controls">
                    <select class="gfgs-select gfgs-select-sm gfgs-field-type-select">
                        <option value="standard" selected>Standard Field</option>
                        <option value="meta">Entry Meta</option>
                        <option value="custom">Custom Value</option>
                    </select>
                    ${renderFieldSelector('standard', '', state.fields, col)}
                </div>
                <button class="gfgs-btn-icon gfgs-remove-mapping" data-col="${esc(col)}" title="Remove">✕</button>
            </div>`;
            $('#gfgs-field-mapper').append(rowHtml);
            $('#gfgs-field-mapper .gfgs-hint').remove();
        });

        // Conditions toggle
        $app.on('change', '#gfgs-cond-enabled', function () {
            $('#gfgs-cond-body').toggle($(this).is(':checked'));
        });

        // Add a condition rule
        $app.on('click', '#gfgs-add-rule', function () {
            const newRule = { field_id: '', operator: 'is', value: '' };
            const index   = $('#gfgs-cond-rules .gfgs-cond-rule').length;
            $('#gfgs-cond-rules').append(renderConditionRule(newRule, index, state.fields));
        });

        // Remove a condition rule
        $app.on('click', '.gfgs-remove-rule', function () {
            $(this).closest('.gfgs-cond-rule').remove();
        });

        // Condition field change → re-render value input
        $app.on('change', '.gfgs-cond-field', function () {
            const $rule   = $(this).closest('.gfgs-cond-rule');
            const index   = $rule.data('index');
            const fieldId = $(this).val();
            const field   = state.fields.find(f => f.id == fieldId);
            $rule.find('.gfgs-cond-value-wrap').html(renderConditionValueInput(field, '', index));
        });

        // Save feed
        $app.on('click', '#gfgs-save-feed', saveFeed);
    }

    function refreshFieldMapper() {
        const feed        = state.editing;
        const dateFormats = feed.date_formats || {};
        $('#gfgs-field-mapper').html(renderFieldMap(feed.field_map, state.headers, state.fields, dateFormats));
    }

    // ── Data Collection ───────────────────────────────────────────────────────

    function collectFieldMap() {
        const rows = [];
        $('#gfgs-field-mapper .gfgs-mapper-row').each(function () {
            const col       = $(this).data('column');
            const fieldType = $(this).find('.gfgs-field-type-select').val() || 'standard';
            let fieldId     = '';

            if (fieldType === 'custom') {
                fieldId = $(this).find('.gfgs-custom-value').val() || '';
            } else {
                fieldId = $(this).find('.gfgs-field-select').val() || '';
            }

            if (col) rows.push({ sheet_column: col, field_id: fieldId, field_type: fieldType });
        });
        return rows;
    }

    function collectDateFormats() {
        const formats = {};
        $('.gfgs-date-format').each(function () {
            const col = $(this).data('col');
            if (col) formats[col] = $(this).val();
        });
        return formats;
    }

    function collectConditions() {
        const enabled = $('#gfgs-cond-enabled').is(':checked');
        const logic   = $('#gfgs-cond-logic').val() || 'all';
        const rules   = [];
        $('#gfgs-cond-rules .gfgs-cond-rule').each(function () {
            const fieldId  = $(this).find('.gfgs-cond-field').val();
            const operator = $(this).find('.gfgs-cond-operator').val();
            const value    = $(this).find('.gfgs-cond-value').val();
            if (fieldId) rules.push({ field_id: fieldId, operator, value });
        });
        return { enabled, logic, action: 'send', rules };
    }

    function saveFeed() {
        const $btn    = $('#gfgs-save-feed');
        const $status = $('#gfgs-save-status');
        const feed    = state.editing;

        const feedName = $('#gfgs-feed-name').val().trim();
        if (!feedName) { alert('Please enter a feed name.'); return; }

        const fieldMap    = collectFieldMap();
        const dateFormats = collectDateFormats();
        const conditions  = collectConditions();

        // ── Read is_active from state.editing (kept in sync by the toggle btn) ──
        const isActive = state.editing.is_active ? 1 : 0;

        $btn.prop('disabled', true).text('Saving…');
        $status.text('').removeClass('success error');

        $.post(AJAX, {
            action:         'gfgs_save_feed',
            nonce:          NONCE,
            id:             feed.id || 0,
            form_id:        state.formId,
            feed_name:      feedName,
            account_id:     $('#gfgs-account-select').val() || feed.account_id,
            spreadsheet_id: $('#gfgs-spreadsheet-select').val() || feed.spreadsheet_id,
            sheet_id:       feed.sheet_id || '',
            sheet_name:     $('#gfgs-sheet-select').val() || feed.sheet_name,
            send_event:     $('#gfgs-send-event').val(),
            is_active:      isActive,
            field_map:      JSON.stringify(fieldMap),
            date_formats:   JSON.stringify(dateFormats),
            conditions:     JSON.stringify(conditions),
        }, res => {
            $btn.prop('disabled', false).text('Save Feed');
            if (res.success) {
                const savedId     = res.data.feed_id;
                const existingIdx = state.feeds.findIndex(f => f.id == feed.id);
                const updatedFeed = {
                    ...feed,
                    id:             savedId,
                    feed_name:      feedName,
                    account_id:     $('#gfgs-account-select').val(),
                    spreadsheet_id: $('#gfgs-spreadsheet-select').val(),
                    sheet_name:     $('#gfgs-sheet-select').val(),
                    send_event:     $('#gfgs-send-event').val(),
                    is_active:      isActive,
                    field_map:      fieldMap,
                    date_formats:   dateFormats,
                    conditions,
                };
                if (existingIdx > -1) {
                    state.feeds[existingIdx] = updatedFeed;
                } else {
                    state.feeds.push(updatedFeed);
                }
                state.editing = updatedFeed;
                $status.text('✓ Saved!').addClass('success');
                setTimeout(() => $status.text('').removeClass('success'), 3000);
            } else {
                const msg = (res.data && res.data.message) || 'Save failed.';
                $status.text('✗ ' + msg).addClass('error');
            }
        }).fail(() => {
            $btn.prop('disabled', false).text('Save Feed');
            $status.text('✗ Network error.').addClass('error');
        });
    }

    // ── AJAX Loaders ──────────────────────────────────────────────────────────

    function loadSpreadsheets(accountId, callback) {
        const $spinner = $('#gfgs-ss-spinner');
        $spinner.show();
        $('#gfgs-spreadsheet-select').prop('disabled', true);

        $.post(AJAX, { action: 'gfgs_get_spreadsheets', nonce: NONCE, account_id: accountId }, res => {
            $spinner.hide();
            if (res.success) {
                state.spreadsheets = res.data || [];
                const feed = state.editing;
                let opts = `<option value="">— Select Spreadsheet —</option>`;
                state.spreadsheets.forEach(ss => {
                    const sel = feed.spreadsheet_id == ss.id ? 'selected' : '';
                    opts += `<option value="${esc(ss.id)}" ${sel}>${esc(ss.name)}</option>`;
                });
                $('#gfgs-spreadsheet-select').html(opts).prop('disabled', false);
                if (callback) callback();
            } else {
                $('#gfgs-spreadsheet-select').prop('disabled', false);
                alert((res.data && res.data.message) || 'Could not load spreadsheets.');
            }
        });
    }

    function loadSheets(accountId, spreadsheetId, callback) {
        const $spinner = $('#gfgs-sh-spinner');
        $spinner.show();
        $('#gfgs-sheet-select').prop('disabled', true);

        $.post(AJAX, { action: 'gfgs_get_sheets', nonce: NONCE, account_id: accountId, spreadsheet_id: spreadsheetId }, res => {
            $spinner.hide();
            if (res.success) {
                state.sheets = res.data || [];
                const feed = state.editing;
                let opts = `<option value="">— Select Sheet Tab —</option>`;
                state.sheets.forEach(sh => {
                    const sel = feed.sheet_name == sh.title ? 'selected' : '';
                    opts += `<option value="${esc(sh.title)}" ${sel}>${esc(sh.title)}</option>`;
                });
                $('#gfgs-sheet-select').html(opts).prop('disabled', false);
                if (callback) callback();
            } else {
                $('#gfgs-sheet-select').prop('disabled', false);
                alert((res.data && res.data.message) || 'Could not load sheet tabs.');
            }
        });
    }

    function loadHeaders(accountId, spreadsheetId, sheetName, callback) {
        const $spinner = $('#gfgs-hd-spinner');
        $spinner.show();

        $.post(AJAX, { action: 'gfgs_get_sheet_headers', nonce: NONCE, account_id: accountId, spreadsheet_id: spreadsheetId, sheet_name: sheetName }, res => {
            $spinner.hide();
            if (res.success) {
                state.headers = res.data || [];
                // Merge: keep existing mappings, add new columns
                const feed         = state.editing;
                const existingCols = (feed.field_map || []).map(m => m.sheet_column || m.column);
                state.headers.forEach(h => {
                    if (!existingCols.includes(h)) {
                        feed.field_map.push({ sheet_column: h, field_id: '', field_type: 'standard' });
                    }
                });
                refreshFieldMapper();
                if (callback) callback();
            } else {
                alert((res.data && res.data.message) || 'Could not load sheet headers.');
            }
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function feedAjax(action, data, callback) {
        $.post(AJAX, { action, nonce: NONCE, ...data }, res => {
            if (res.success && callback) callback(res);
            else if (!res.success) console.error('gfgs ajax error', action, res);
        });
    }

})(jQuery);