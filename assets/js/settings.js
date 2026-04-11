/**
 * Settings Page Controller
 * This script handles account management, including copying redirect URI, diconnecting accounts, and the OAuth credential handshaking process.
 */

(function ($) {
    'use strict';

    // Global settings localized from PHP (comtains ajaxUrl, nonce, etc.)
    const S = window.gfgsSettings;

    // Safety check: Only run if we are on the plugin settings page
    if ( ! S ) return;

    // ── Account List events ──────────────────────────────────────────────
    // Logic for the dashboard where all connected Google accounts are listed
    
    /**
     * Copy Button - Copy to Clipboard
     * Used for the Google Redirect URI so users can easily paste it into Google Cloud Console.
     */
    $(document).on('click', '.gfgs-copy-btn', function () {
        const text = $(this).data('copy');
        const $btn = $(this);
        const orig = $btn.html();
        navigator.clipboard.writeText(text).then(() => {
            $btn.text('Copied!');
            setTimeout(() => $btn.html(orig), 2000);
        });
    });

    /**
     * Disconnect Google Account
     * Remove the account record and tokens from the database via AJAX.
     */
    $(document).on('click', '.gfgs-disconnect-account', function () {
        if ( ! confirm('Disconnect this Google account? Any feeds using it will stop working.') ) return;

        const id   = $(this).data('id');
        const $btn = $(this);

        // UI State: Loading
        $btn.text('Removing…').prop('disabled', true);

        $.post(S.ajaxUrl, { action: 'gfgs_delete_account', account_id: id, nonce: S.nonce }, res => {
            if ( res.success ) {
                $btn.closest('.gfgs-account-card').fadeOut(300, function () {
                    $(this).remove();

                    // Update the "Total Accounts" counter badge
                    const $count = $('.gfgs-badge-count');
                    const remaining = $('.gfgs-account-card').length;
                    $count.text(remaining + ' account' + (remaining !== 1 ? 's' : ''));

                    // If no accounts are left, swap the list for the "Empty State" UI
                    if ( remaining === 0 ) {
                        $('.gfgs-account-list').replaceWith(`
                            <div class="gfgs-empty-accounts">
                                <p>No accounts connected yet.</p>
                                <a href="${S.addAccountUrl}" class="gfgs-btn gfgs-btn-primary">
                                    Connect Your First Account
                                </a>
                            </div>`);
                    }
                });
            }
        });
    });

    // ── Add Account Form events ───────────────────────────────────────────────
    // Logic for the form where users enter their Google Client ID and Secret.

    /**
     * Toggle secret visibility
     * Masks/Unmasks the Client Secret input field(password vs text)
     */
    $(document).on('click', '.gfgs-toggle-secret', function () {
        const $input = $('#gfgs-client-secret');
        const isPass = $input.attr('type') === 'password';
        $input.attr('type', isPass ? 'text' : 'password');
        $(this).find('.eye-show').toggle(isPass);
        $(this).find('.eye-hide').toggle( ! isPass);
    });

    /**
     * Save & Connect with Google
     * This is the most critical step: It saves the credentials to a "pending" record in WordPress, then redirects the user to Google's OAuth screen.
     */
    $(document).on('click', '#gfgs-save-connect-btn', function () {
        const $btn         = $(this);
        const name         = $('#gfgs-account-name').val().trim();
        const clientId     = $('#gfgs-client-id').val().trim();
        const secret       = $('#gfgs-client-secret').val().trim();
        const pendingId    = S.pendingId || 0;

        // Client-side validation
        if ( ! clientId || ! secret ) {
            showConnectionResult('error', 'Client ID and Client Secret are required.');
            return;
        }

        // UI State: Show spinner
        $btn.prop('disabled', true).html('<span class="gfgs-spinner"></span> Saving…');

        $.post(S.ajaxUrl, {
            action:        'gfgs_save_account_creds',
            nonce:         S.nonce,
            account_id:    pendingId,
            account_name:  name,
            client_id:     clientId,
            client_secret: secret,
        }, res => {
            if ( res.success ) {
                // Success: The server returned a Google Auth URL, now we redirect
                $btn.html('<span class="gfgs-spinner"></span> Redirecting to Google…');
                showConnectionResult('info', 'Redirecting you to Google for authorization.');
                
                // Slight delay so the user can read the "Redirecting" status
                setTimeout(() => { window.location.href = res.data.auth_url; }, 600);
            } else {
                $btn.prop('disabled', false).html('Save &amp; Connect with Google');
                showConnectionResult('error', res.data.message || 'An error occurred.');
            }
        }).fail(() => {
            $btn.prop('disabled', false).html('Save &amp; Connect with Google');
            showConnectionResult('error', 'Request failed. Please try again.');
        });
    });

    /** 
     * Test Connection
     * Allows users to verify if their Client ID/Secret work BEFORE they leave the page to do the Google OAuth login.
     */
    $(document).on('click', '#gfgs-test-btn', function () {
        const $btn      = $(this);
        const pendingId = S.pendingId || 0;
        
        $btn.prop('disabled', true).html('<span class="gfgs-spinner"></span> Testing…');

        $.post(S.ajaxUrl, {
            action:        'gfgs_test_connection',
            nonce:         S.nonce,
            account_id:    pendingId,
            client_id:     $('#gfgs-client-id').val().trim(),
            client_secret: $('#gfgs-client-secret').val().trim(),
        }, res => {
            $btn.prop('disabled', false).text('Test Connection');
            if ( res.success ) {
                showConnectionResult('success', res.data.message || 'Connection successful!');
            } else {
                showConnectionResult('error', res.data.message || 'Connection failed.');
            }
        }).fail(() => {
            $btn.prop('disabled', false).text('Test Connection');
            showConnectionResult('error', 'Request failed.');
        });
    });

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Display success/error alerts in the connection form.
     * @param {string} type - success, error, or info.
     * @param {string} msg - The message to display.
     */
    function showConnectionResult(type, msg) {
        const icons = {
            success: '✓',
            error:   '✕',
            info:    'ℹ',
        };
        $('#gfgs-connection-result')
            .attr('class', 'gfgs-connection-result gfgs-result-' + type)
            .html(icons[type] + ' ' + msg)
            .show();
    }

}(jQuery));