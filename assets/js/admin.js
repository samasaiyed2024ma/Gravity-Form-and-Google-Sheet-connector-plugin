/**
 * GFGS Shared Admin Utilities
 *
 * Registered as the 'gfgs-common' script handle.
 * Loaded on every GFGS admin page and on the entry detail page
 * for the manual-send meta-box interaction.
 *
 * Exposes a global `window.GFGS` namespace consumed by settings.js and
 * feed-list.js so utilities are defined only once.
 *
 * @package GFGS
 */

( function ( $ ) {
	'use strict';

	// Initialise the plugin namespace — prevents collisions if loaded twice.
	window.GFGS = window.GFGS || {};

	// ── Utility: HTML escaping ────────────────────────────────────────────────

	/**
	 * Escape a value so it is safe to insert into HTML as text content or
	 * as an attribute value. Converts the five special HTML characters to entities.
	 *
	 * @param  {*}      s  Any value; coerced to string.
	 * @return {string}    HTML-safe string.
	 */
	window.GFGS.esc = function ( s ) {
		return String( s || '' )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	};

	// ── Utility: Avatar initials ──────────────────────────────────────────────

	/**
	 * Derive two uppercase initials from a name or email address.
	 *
	 * Examples:
	 *   "John Doe"       → "JD"
	 *   "test@gmail.com" → "TE"
	 *   ""               → "?"
	 *
	 * @param  {string} str  Name or email address.
	 * @return {string}      Two-character initials string, or "?".
	 */
	window.GFGS.getInitials = function ( str ) {
		return String( str || '?' )
			.replace( /[^a-zA-Z\s@.]/g, '' )
			.split( /[\s@.]+/ )
			.filter( Boolean )
			.slice( 0, 2 )
			.map( function ( w ) { return w[ 0 ].toUpperCase(); } )
			.join( '' ) || '?';
	};

	// ── Entry detail: Manual Send meta-box ───────────────────────────────────

	/**
	 * Handle clicks on the "Send to Google Sheets" button in the entry
	 * detail sidebar meta-box (templates/entry/meta-box.php).
	 *
	 * The button carries data-entry-id and data-nonce attributes set
	 * server-side by GFGS_Addon::render_entry_meta_box().
	 */
	$( document ).on( 'click', '.gfgs-manual-send-btn', function () {
		var $btn    = $( this );
		var $status = $btn.siblings( '.gfgs-send-status' );
		var entryId = $btn.data( 'entry-id' );
		var nonce   = $btn.data( 'nonce' );
		var feedId  = $( '#gfgs-feed-select' ).val() || 'all';

		// Resolve ajaxUrl from whichever localized data object is present.
		var ajaxUrl = (
			( window.gfgsEntryData  && window.gfgsEntryData.ajaxUrl  ) ||
			( window.gfgsSettings   && window.gfgsSettings.ajaxUrl   ) ||
			( window.gfgsData       && window.gfgsData.ajaxUrl       ) ||
			''
		);
		if ( ! ajaxUrl ) {
			return;
		}

		// Lock button to prevent duplicate submissions.
		$btn.prop( 'disabled', true ).text( 'Sending\u2026' );
		$status.hide().removeClass( 'success error' ).text( '' );

		$.post(
			ajaxUrl,
			{
				action:   'gfgs_manual_send',
				nonce:    nonce,
				entry_id: entryId,
				feed_id:  feedId,
			},
			function ( res ) {
				$btn.prop( 'disabled', false ).text( 'Send to Google Sheets' );
				$status.show().removeClass( 'success error' );

				if ( res.success ) {
					$status.addClass( 'success' ).text( '\u2713 Sent to ' + res.data.sent + ' feed(s).' );
				} else {
					$status.addClass( 'error' ).text( '\u2717 ' + ( ( res.data && res.data.message ) || 'Error' ) );
				}
			}
		).fail( function () {
			$btn.prop( 'disabled', false ).text( 'Send to Google Sheets' );
			$status.show().addClass( 'error' ).text( '\u2717 Request failed.' );
		} );
	} );

}( jQuery ) );
