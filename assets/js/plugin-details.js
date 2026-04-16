(function($){
    'use strict';

    // Only run on plugin page
    if( !$('#gfgs-plugin-details').length ) return;

    // ── Tab switching ─────────────────────────────────────────────────────────
    $(document).on('click', '.gfgs-tab-btn', function(){
        const tab = $(this).data('tab');

        // Update buttons
        $('.gfgs-tab-btn').removeClass('active');
        $(this).addClass('active');

        // Update content
        $('.gfgs-tab-content').removeClass('active');
        $('#gfgs-tab-' + tab).addClass('active');
    });

    // ── FAQ accordion ─────────────────────────────────────────────────────────
    $(document).on('click', '.gfgs-faq-question', function () {
        const $item   = $(this).closest('.gfgs-faq-item');
        const $answer = $item.find('.gfgs-faq-answer');
        const isOpen  = $item.hasClass('open');

        // Close all
        $('.gfgs-faq-item').removeClass('open');
        $('.gfgs-faq-answer').slideUp(200);

        // Open clicked if it was closed
        if ( ! isOpen ) {
            $item.addClass('open');
            $answer.slideDown(200);
        }
    });

}(jQuery));