/**
 * Shared admin logic
 * The script handles shared utility functions and the "Manual Send" functionality found on the Entry Detail page.
 */

(function ($) {
    'use strict';

    // Initialize the global namespace for the plugin to avoid variable collisions
    window.GFGS = window.GFGS || {};

    /**
     * Simple HTML Escaping Utility
     * Converts special characters to HTML entities to prevent XSS.
     * @param {string} s - The string to escape.
     * @returns {string} - The safely escaped string.
     */
    console.log(window.GFGS);
    window.GFGS.esc = function (s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    /**
     * Avatar Initials Generator
     * Takes a string (Name or Email) and returns a 2-letter uppercase initial.
     * @param {string} str - The string to process. 
     * @returns {string} - e.g., "John Doe" -> "JD" "test@gmail.com" -> "TE"
     */
    window.GFGS.getInitials = function (str) {
        return String(str || '?')
            .replace(/[^a-zA-Z\s@.]/g, '')
            .split(/[\s@.]+/)
            .filter(Boolean)
            .slice(0, 2)
            .map(w => w[0].toUpperCase())
            .join('') || '?';
    };

    /**
     * Event Handler: Manual Send Button
     * Located in the Gravity Forms Entry Detail sidebar (Meta Box).
     * Sends a specific entry to Google Sheets on demand.
     */
    $(document).on('click', '.gfgs-manual-send-btn', function () {
        const $btn = $(this);
        const entryId = $btn.data('entry-id');
        const nonce = $btn.data('nonce');
        const $status = $btn.siblings('.gfgs-send-status');

        // Detect which localized data object is available (Settings page vs Feed page)
        const ajaxUrl = (window.gfgsSettings || window.gfgsData || {}).ajaxUrl;

        // UI State: Disable button to prevent double-clicks
        $btn.prop('disabled', true).text('Sending…');

        // Perform the AJAX POST request to WordPress
        $.post(ajaxUrl, { action: 'gfgs_manual_send', entry_id: entryId, nonce }, res => {
            $btn.prop('disabled', false).text('Send to Google Sheets');
            $status.show().removeClass('success error');

            if (res.success) {
                $status.addClass('success').text('✓ Sent to ' + res.data.sent + ' feed(s).');
            } else {
                $status.addClass('error').text('✗ ' + (res.data.message || 'Error'));
            }
        }).fail(() => {
            $btn.prop('disabled', false).text('Send to Google Sheets');
            $status.show().addClass('error').text('✗ Request failed.');
        });
    });

}(jQuery));