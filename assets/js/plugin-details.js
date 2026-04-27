/**
 * GFGS Plugin Details Modal
 *
 * Registered as 'gfgs-plugin-details', loaded only on plugins.php.
 * Handles tab switching and FAQ accordion in the thickbox modal.
 *
 * @package GFGS
 */

( function ( $ ) {
	'use strict';

	// Only execute when the modal container is present on the page.
	if ( ! $( '#gfgs-plugin-details' ).length ) {
		return;
	}

	// ── Tab switching ─────────────────────────────────────────────────────────

	/**
	 * Activate the clicked tab button and show the corresponding content panel.
	 * Uses data-tab attribute to match button → content by ID convention
	 * (#gfgs-tab-{key}).
	 */
	$( document ).on( 'click', '.gfgs-tab-btn', function () {
		var tab = $( this ).data( 'tab' );

		$( '.gfgs-tab-btn' ).removeClass( 'active' );
		$( this ).addClass( 'active' );

		$( '.gfgs-tab-content' ).removeClass( 'active' );
		$( '#gfgs-tab-' + tab ).addClass( 'active' );
	} );

	// ── FAQ accordion ─────────────────────────────────────────────────────────

	/**
	 * Toggle a FAQ answer open/closed.
	 * Closes any other open item first (accordion behaviour).
	 * The +/- icon in the button is toggled via the parent's 'open' class.
	 */
	$( document ).on( 'click', '.gfgs-faq-question', function () {
		var $item   = $( this ).closest( '.gfgs-faq-item' );
		var $answer = $item.find( '.gfgs-faq-answer' );
		var isOpen  = $item.hasClass( 'open' );

		// Close all items.
		$( '.gfgs-faq-item' ).removeClass( 'open' );
		$( '.gfgs-faq-answer' ).slideUp( 200 );

		// Re-open the clicked item if it was previously closed.
		if ( ! isOpen ) {
			$item.addClass( 'open' );
			$answer.slideDown( 200 );
		}
	} );

}( jQuery ) );