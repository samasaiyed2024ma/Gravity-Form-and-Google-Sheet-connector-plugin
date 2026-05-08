(function ($) {
    'use strict';

    var DATA   = window.gfgsDeactivate;
    var $modal = $( '#gfgs-deactivate-modal' );

    // Find the deactivation link for this specific plugin only.
    function getDeactivateLink() {
        return $( 'tr[data-plugin="' + DATA.pluginFile + '"] .deactivate a' );
    }

    // Intercept the deactivation link click.
    $( document ).on( 'click', 'tr[data-plugin="' + DATA.pluginFile + '"] .deactivate a', function ( e ) {
        e.preventDefault();
        $modal._deactivateHref = $( this ).attr( 'href' );
        $modal.show();
    } );

    // Cancel — close modal, do nothing.
    $( '#gfgs-deactivate-cancel, #gfgs-deactivate-overlay' ).on( 'click', function () {
        $modal.hide();
        $( '#gfgs-remove-data' ).prop( 'checked', false );
    } );

    // Confirm — set flag via AJAX then follow the original deactivate link.
    $( '#gfgs-deactivate-confirm' ).on( 'click', function () {
        var $btn      = $( this ).prop( 'disabled', true ).text( 'Please wait…' );
        var removeData = $( '#gfgs-remove-data' ).is( ':checked' ) ? 1 : 0;

        $.post(
            DATA.ajaxUrl,
            {
                action:      'gfgs_set_remove_data_flag',
                nonce:       DATA.nonce,
                remove_data: removeData,
            },
            function () {
                // Follow WordPress's own deactivation link.
                window.location.href = $modal._deactivateHref;
            }
        ).fail( function () {
            $btn.prop( 'disabled', false ).text( 'Deactivate' );
            alert( 'Something went wrong. Please try again.' );
        } );
    } );

}( jQuery ));