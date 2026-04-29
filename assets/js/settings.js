/**
 * GFGS Settings Page Controller
 *
 * Registered as the 'gfgs-admin' script handle (depends on 'gfgs-common').
 * Handles account management on the plugin settings page:
 *   - Copy-to-clipboard for the redirect URI.
 *   - Disconnect (delete) a connected account.
 *   - Toggle client-secret visibility.
 *   - Save credentials and redirect to Google's OAuth consent screen (new accounts).
 *   - Update credentials without OAuth (existing connected accounts).
 *   - Test an existing connection.
 *
 * All data comes from `window.gfgsSettings` injected via wp_localize_script().
 * Key properties used from gfgsSettings:
 *   S.ajaxUrl      — WordPress AJAX endpoint.
 *   S.nonce        — Security nonce.
 *   S.pendingId    — Account ID when editing (0 for new).
 *   S.isAuthorized — 1 if the account already has a refresh token, 0 otherwise.
 *   S.addAccountUrl — URL to the add-account view (used for redirect after update).
 *
 * @package GFGS
 */

( function ( $ ) {
	'use strict';

	/** Localized settings object from PHP. Only present on the settings page. */
	var S = window.gfgsSettings;

	// Bail early if not on the settings page.
	if ( ! S ) {
		return;
	}

	// ── Copy redirect URI ─────────────────────────────────────────────────────

	/**
	 * Copy the element's data-copy value to the clipboard.
	 * Temporarily shows "Copied!" feedback on the button.
	 */
	$( document ).on( 'click', '.gfgs-copy-btn', function () {
		var $btn = $( this );
		var text = $btn.data( 'copy' );
		var orig = $btn.html();

		if ( ! navigator.clipboard ) {
			return; // Clipboard API not supported (non-HTTPS).
		}

		navigator.clipboard.writeText( text ).then( function () {
			$btn.text( 'Copied!' );
			setTimeout( function () {
				$btn.html( orig );
			}, 2000 );
		} );
	} );

	// ── Disconnect account ────────────────────────────────────────────────────

	/**
	 * Delete an account record after user confirmation.
	 * Removes the account card from the DOM and updates the badge count.
	 * Replaces the list with an empty-state block when the last account is removed.
	 */
	$( document ).on( 'click', '.gfgs-disconnect-account', function () {
		if ( ! confirm( 'Disconnect this Google account? Any feeds using it will stop working.' ) ) {
			return;
		}

		var $btn = $( this );
		var id   = $btn.data( 'id' );

		$btn.text( 'Removing\u2026' ).prop( 'disabled', true );

		$.post(
			S.ajaxUrl,
			{
				action:     'gfgs_delete_account',
				nonce:      S.nonce,
				account_id: id,
			},
			function ( res ) {
				if ( ! res.success ) {
					return;
				}

				$btn.closest( '.gfgs-account-card' ).fadeOut( 300, function () {
					$( this ).remove();

					var remaining = $( '.gfgs-account-card' ).length;

					// Update badge counter.
					$( '.gfgs-badge-count' ).text(
						remaining + ' account' + ( remaining !== 1 ? 's' : '' )
					);

					// Show empty-state when all accounts are removed.
					if ( remaining === 0 ) {
						$( '.gfgs-account-list' ).replaceWith(
							'<div class="gfgs-empty-accounts">' +
							'<p>No accounts connected yet.</p>' +
							'<a href="' + S.addAccountUrl + '" class="gfgs-btn gfgs-btn-primary">Connect Your First Account</a>' +
							'</div>'
						);
					}
				} );
			}
		);
	} );

	// ── Toggle client-secret visibility ───────────────────────────────────────

	/**
	 * Toggle the client-secret input between password and text mode.
	 * Swaps the eye-show / eye-hide SVG icons accordingly.
	 */
	$( document ).on( 'click', '.gfgs-toggle-secret', function () {
		var $input = $( '#gfgs-client-secret' );
		var isPass = $input.attr( 'type' ) === 'password';

		$input.attr( 'type', isPass ? 'text' : 'password' );
		$( this ).find( '.eye-show' ).toggle( isPass );
		$( this ).find( '.eye-hide' ).toggle( ! isPass );
	} );

	// ── Save credentials & start OAuth flow (NEW accounts only) ──────────────

	/**
	 * Saves credentials for a brand-new (or incomplete) account, then redirects
	 * the user to Google's OAuth consent screen.
	 *
	 * This button is only rendered by the template when S.isAuthorized === 0.
	 */
	$( document ).on( 'click', '#gfgs-save-connect-btn', function () {
		var $btn     = $( this );
		var name     = $( '#gfgs-account-name' ).val().trim();
		var clientId = $( '#gfgs-client-id' ).val().trim();
		var secret   = $( '#gfgs-client-secret' ).val().trim();

		if ( ! clientId || ! secret ) {
			showConnectionResult( 'error', 'Client ID and Client Secret are required.' );
			return;
		}

		$btn.prop( 'disabled', true ).html( '<span class="gfgs-spinner"></span> Saving\u2026' );

		$.post(
			S.ajaxUrl,
			{
				action:        'gfgs_save_account_creds',
				nonce:         S.nonce,
				account_id:    S.pendingId || 0,
				account_name:  name,
				client_id:     clientId,
				client_secret: secret,
			},
			function ( res ) {
				if ( res.success ) {
					$btn.html( '<span class="gfgs-spinner"></span> Redirecting to Google\u2026' );
					showConnectionResult( 'info', 'Redirecting you to Google for authorization.' );

					// Small delay so the user can read the status message.
					setTimeout( function () {
						window.location.href = res.data.auth_url;
					}, 600 );
				} else {
					$btn.prop( 'disabled', false ).html( 'Save &amp; Connect with Google' );
					showConnectionResult( 'error', ( res.data && res.data.message ) || 'An error occurred.' );
				}
			}
		).fail( function () {
			$btn.prop( 'disabled', false ).html( 'Save &amp; Connect with Google' );
			showConnectionResult( 'error', 'Request failed. Please try again.' );
		} );
	} );

	// ── Update existing account (no OAuth) ────────────────────────────────────

	/**
	 * Updates an already-connected account's name and/or credentials.
	 * Does NOT redirect to Google — the refresh token is preserved as-is.
	 *
	 * This button is only rendered by the template when S.isAuthorized === 1.
	 * The account ID is read from S.pendingId (injected via wp_localize_script)
	 * so it works even if the template's data-account-id attribute is missing.
	 */
	$( document ).on( 'click', '#gfgs-update-account-btn', function () {
		var $btn      = $( this );
		var name      = $( '#gfgs-account-name' ).val().trim();
		var clientId  = $( '#gfgs-client-id' ).val().trim();
		var secret    = $( '#gfgs-client-secret' ).val().trim();

		// Prefer data-account-id on the button; fall back to S.pendingId.
		var accountId = parseInt( $btn.data( 'account-id' ), 10 ) || S.pendingId || 0;

		if ( ! accountId ) {
			showConnectionResult( 'error', 'Could not determine account ID. Please refresh and try again.' );
			return;
		}

		if ( ! clientId || ! secret ) {
			showConnectionResult( 'error', 'Client ID and Client Secret are required.' );
			return;
		}

		$btn.prop( 'disabled', true ).html( '<span class="gfgs-spinner"></span> Saving\u2026' );

		$.post(
			S.ajaxUrl,
			{
				action:        'gfgs_update_account',
				nonce:         S.nonce,
				account_id:    accountId,
				account_name:  name,
				client_id:     clientId,
				client_secret: secret,
			},
			function ( res ) {
				if ( res.success ) {
					showConnectionResult( 'success', ( res.data && res.data.message ) || 'Account updated successfully.' );
					// Redirect back to the account list after a short pause.
					setTimeout( function () {
						window.location.href = ( res.data && res.data.redirect_url )
							? res.data.redirect_url
							: S.addAccountUrl.replace( '&gfgs_view=add_account', '' );
					}, 1200 );
				} else {
					$btn.prop( 'disabled', false ).html( 'Save Changes' );
					showConnectionResult( 'error', ( res.data && res.data.message ) || 'An error occurred.' );
				}
			}
		).fail( function () {
			$btn.prop( 'disabled', false ).html( 'Save Changes' );
			showConnectionResult( 'error', 'Request failed. Please try again.' );
		} );
	} );

	// ── Test connection ───────────────────────────────────────────────────────

	/**
	 * Verify that the saved credentials can obtain a valid token.
	 * Only enabled when the account already has a refresh_token (S.isAuthorized).
	 */
	$( document ).on( 'click', '#gfgs-test-btn', function () {
		var $btn = $( this );

		$btn.prop( 'disabled', true ).html( '<span class="gfgs-spinner"></span> Testing\u2026' );

		$.post(
			S.ajaxUrl,
			{
				action:        'gfgs_test_connection',
				nonce:         S.nonce,
				account_id:    S.pendingId || 0,
				client_id:     $( '#gfgs-client-id' ).val().trim(),
				client_secret: $( '#gfgs-client-secret' ).val().trim(),
			},
			function ( res ) {
				$btn.prop( 'disabled', false ).text( 'Test Connection' );

				if ( res.success ) {
					showConnectionResult( 'success', ( res.data && res.data.message ) || 'Connection successful!' );
				} else {
					showConnectionResult( 'error', ( res.data && res.data.message ) || 'Connection failed.' );
				}
			}
		).fail( function () {
			$btn.prop( 'disabled', false ).text( 'Test Connection' );
			showConnectionResult( 'error', 'Request failed.' );
		} );
	} );

	// ── Helper: connection result alert ──────────────────────────────────────

	/**
	 * Display a styled result message below the action buttons.
	 *
	 * @param {string} type - 'success' | 'error' | 'info'
	 * @param {string} msg  - Message text to display.
	 */
	function showConnectionResult( type, msg ) {
		var icons = {
			success: '\u2713',  // ✓
			error:   '\u2717',  // ✗
			info:    '\u2139',  // ℹ
		};

		$( '#gfgs-connection-result' )
			.attr( 'class', 'gfgs-connection-result gfgs-result-' + type )
			.html( ( icons[ type ] || '' ) + ' ' + msg )
			.show();
	}

}( jQuery ) );