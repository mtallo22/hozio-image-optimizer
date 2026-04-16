/**
 * Hozio Image Optimizer - Admin Settings JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        // Test API connection
        $('#test-api-btn').on('click', function() {
            var btn = $(this);
            var resultSpan = $('#api-test-result');
            var apiKey = $('#hozio_openai_api_key').val();

            if (!apiKey) {
                resultSpan.removeClass('success').addClass('error').text('Please enter an API key');
                return;
            }

            btn.prop('disabled', true).text('Testing...');
            resultSpan.removeClass('success error').text('');

            $.post(hozioImageOptimizer.ajaxUrl, {
                action: 'hozio_test_api',
                nonce: hozioImageOptimizer.nonce,
                api_key: apiKey
            }, function(response) {
                btn.prop('disabled', false).text('Test Connection');

                if (response.success) {
                    resultSpan.removeClass('error').addClass('success').text('✓ Connected successfully!');
                } else {
                    resultSpan.removeClass('success').addClass('error').text('✗ ' + (response.data.message || 'Connection failed'));
                }
            }).fail(function() {
                btn.prop('disabled', false).text('Test Connection');
                resultSpan.removeClass('success').addClass('error').text('✗ Request failed');
            });
        });

        // Cleanup backups
        $('#cleanup-backups-btn').on('click', function() {
            var btn = $(this);

            if (!confirm('This will delete all backups older than the retention period. Continue?')) {
                return;
            }

            btn.prop('disabled', true).text('Cleaning up...');

            $.post(hozioImageOptimizer.ajaxUrl, {
                action: 'hozio_cleanup_backups',
                nonce: hozioImageOptimizer.nonce
            }, function(response) {
                btn.prop('disabled', false).text('Clean Up Old Backups');
                alert(response.data.message || 'Cleanup complete');
                location.reload();
            }).fail(function() {
                btn.prop('disabled', false).text('Clean Up Old Backups');
                alert('Cleanup failed');
            });
        });

        // Range input value display
        $('input[type="range"]').on('input', function() {
            var id = $(this).attr('id');
            $('#' + id + '_value').text($(this).val());
        });

        // Toggle AI context panel based on rename checkbox
        $('#opt-rename').on('change', function() {
            if ($(this).is(':checked')) {
                $('#ai-context-panel').slideDown();
            } else {
                $('#ai-context-panel').slideUp();
            }
        });

    });

})(jQuery);
