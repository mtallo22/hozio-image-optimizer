/**
 * Hozio Image Optimizer - Global Queue Progress Banner
 * Lightweight script that loads on ALL WordPress admin pages.
 * Shows a progress banner when image optimization is running.
 */
(function($) {
    'use strict';

    var pollInterval = null;
    var isPluginPage = window.hozioImageOptimizerData !== undefined;

    // Don't run on the plugin's own pages (bulk-optimizer.js handles it there)
    if (isPluginPage) return;

    function checkQueue() {
        $.post(hozioGlobalBanner.ajaxUrl, {
            action: 'hozio_get_queue_status',
            nonce: hozioGlobalBanner.nonce
        }, function(response) {
            if (response.success && response.data.active && response.data.state === 'running') {
                showBanner(response.data);
                // Only start interval polling when queue is actively running
                startPolling();
            } else if (response.success && response.data.active && response.data.state === 'completed') {
                showCompletedBanner(response.data);
                stopPolling();
            } else {
                // No active queue -- don't poll, don't show banner
                stopPolling();
            }
        }).fail(function() {
            // Silently fail - don't show errors on non-plugin pages
        });
    }

    function showBanner(data) {
        var $banner = $('#hozio-global-banner');
        if ($banner.length === 0) return;

        var progress = data.total > 0 ? Math.round((data.completed / data.total) * 100) : 0;
        var currentNum = Math.min(data.completed + 1, data.total);

        $banner.find('.hgb-text').text('Optimizing image ' + currentNum + ' of ' + data.total);
        $banner.find('.hgb-percent').text(progress + '%');
        $banner.find('.hgb-fill').css('width', progress + '%');
        $banner.removeClass('hgb-complete').show();
    }

    function showCompletedBanner(data) {
        var $banner = $('#hozio-global-banner');
        if ($banner.length === 0) return;

        var successCount = (data.completed || 0) - (data.errors || 0);
        $banner.find('.hgb-text').text('Optimization complete — ' + successCount + ' images processed');
        $banner.find('.hgb-percent').text('Done');
        $banner.find('.hgb-fill').css('width', '100%');
        $banner.addClass('hgb-complete').show();

        // Auto-hide after 10 seconds
        setTimeout(function() { hideBanner(); }, 10000);
    }

    function hideBanner() {
        $('#hozio-global-banner').slideUp(200);
    }

    function startPolling() {
        if (pollInterval) return;
        checkQueue(); // Immediate first check
        pollInterval = setInterval(checkQueue, 5000);
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    // Dismiss optimization banner
    $(document).on('click', '.hgb-dismiss', function() {
        hideBanner();
        stopPolling();
    });

    // Dismiss broken images alert banner
    $(document).on('click', '.hgb-dismiss-broken', function() {
        $('#hozio-broken-alert').slideUp(200);
        $.post(hozioGlobalBanner.ajaxUrl, {
            action: 'hozio_dismiss_broken_banner',
            nonce: hozioGlobalBanner.nonce
        });
    });

    // Only check once on page load, then start polling only if queue is active
    $(document).ready(function() {
        // Single check -- don't start interval polling unless needed
        checkQueue();
    });

})(jQuery);
