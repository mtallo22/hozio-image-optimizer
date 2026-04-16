/**
 * Hozio Image Optimizer - Upload Progress Modal
 * Shows optimization progress when images are uploaded
 */

(function($) {
    'use strict';

    // Track uploads and optimization state
    var uploadState = {
        totalImages: 0,
        completedImages: 0,
        totalSaved: 0,
        startTime: null,
        isActive: false,
        uploadQueue: []
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if (typeof hozioUploadOptimizer === 'undefined' || !hozioUploadOptimizer.enabled) {
            return;
        }

        initUploadMonitoring();
        initModalEvents();
    });

    /**
     * Initialize upload monitoring
     */
    function initUploadMonitoring() {
        // Hook into WordPress plupload
        if (typeof wp !== 'undefined' && wp.Uploader) {
            // Override the init to add our hooks
            var originalInit = wp.Uploader.prototype.init;
            wp.Uploader.prototype.init = function() {
                originalInit.apply(this, arguments);

                var uploader = this.uploader;

                // When files are added to queue
                uploader.bind('FilesAdded', function(up, files) {
                    var imageFiles = files.filter(function(file) {
                        return file.type && file.type.indexOf('image/') === 0;
                    });

                    if (imageFiles.length > 0) {
                        uploadState.totalImages += imageFiles.length;
                        uploadState.uploadQueue = uploadState.uploadQueue.concat(imageFiles);

                        if (!uploadState.isActive) {
                            showModal();
                            uploadState.startTime = Date.now();
                            uploadState.isActive = true;
                        }

                        updateModalStatus();
                    }
                });

                // When a file upload completes
                uploader.bind('FileUploaded', function(up, file, response) {
                    if (file.type && file.type.indexOf('image/') === 0) {
                        uploadState.completedImages++;

                        // Parse response to get attachment ID
                        try {
                            var data = JSON.parse(response.response);
                            if (data && data.data && data.data.id) {
                                // Poll for optimization result
                                pollOptimizationResult(data.data.id);
                            }
                        } catch (e) {
                            // Response parsing failed, still update progress
                        }

                        updateModalProgress();
                    }
                });

                // When all uploads complete
                uploader.bind('UploadComplete', function() {
                    // Wait a moment for optimization to complete
                    setTimeout(function() {
                        if (uploadState.completedImages >= uploadState.totalImages) {
                            showCompleteState();
                        }
                    }, 1500);
                });

                // Handle upload errors
                uploader.bind('Error', function(up, error) {
                    console.log('Upload error:', error);
                });
            };
        }

        // Also listen for media library uploads via AJAX
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url && settings.url.indexOf('async-upload.php') !== -1) {
                // An upload just completed
                if (uploadState.isActive) {
                    updateModalProgress();
                }
            }
        });
    }

    /**
     * Initialize modal events
     */
    function initModalEvents() {
        // Close button
        $(document).on('click', '.hozio-modal-close', function() {
            hideModal();
            resetState();
        });

        // Click outside to close (only when complete)
        $(document).on('click', '#hozio-optimization-modal', function(e) {
            if ($(e.target).is('#hozio-optimization-modal') && $('.hozio-modal-complete').hasClass('active')) {
                hideModal();
                resetState();
            }
        });

        // ESC key to close (only when complete)
        $(document).on('keydown', function(e) {
            if (e.keyCode === 27 && $('#hozio-optimization-modal').hasClass('active') && $('.hozio-modal-complete').hasClass('active')) {
                hideModal();
                resetState();
            }
        });
    }

    /**
     * Show the modal
     */
    function showModal() {
        $('#hozio-optimization-modal').addClass('active');
        $('.hozio-modal-processing').show();
        $('.hozio-modal-complete').removeClass('active');
        updateStep(hozioUploadOptimizer.strings.preparing);
    }

    /**
     * Hide the modal
     */
    function hideModal() {
        $('#hozio-optimization-modal').removeClass('active');
    }

    /**
     * Reset upload state
     */
    function resetState() {
        uploadState = {
            totalImages: 0,
            completedImages: 0,
            totalSaved: 0,
            startTime: null,
            isActive: false,
            uploadQueue: []
        };

        // Reset modal UI
        $('.hozio-progress-fill').css('width', '0%');
        $('.current-count').text('0');
        $('.total-count').text('0');
        $('.hozio-modal-time').text('');
        $('.hozio-modal-processing').show();
        $('.hozio-modal-complete').removeClass('active');
    }

    /**
     * Update modal status text
     */
    function updateModalStatus() {
        $('.total-count').text(uploadState.totalImages);
        $('.current-count').text(uploadState.completedImages);
    }

    /**
     * Update modal progress
     */
    function updateModalProgress() {
        var progress = uploadState.totalImages > 0
            ? (uploadState.completedImages / uploadState.totalImages) * 100
            : 0;

        $('.hozio-progress-fill').css('width', progress + '%');
        $('.current-count').text(uploadState.completedImages);

        // Update step text based on settings
        if (uploadState.completedImages < uploadState.totalImages) {
            updateStepBasedOnSettings();
        }

        // Update time estimate
        updateTimeEstimate();
    }

    /**
     * Update step text based on enabled settings
     */
    function updateStepBasedOnSettings() {
        var settings = hozioUploadOptimizer.settings;
        var steps = [];

        if (settings.compression) {
            steps.push(hozioUploadOptimizer.strings.compressing);
        }
        if (settings.webp || settings.avif) {
            steps.push(hozioUploadOptimizer.strings.converting);
        }
        if (settings.aiAlt || settings.aiRename) {
            steps.push(hozioUploadOptimizer.strings.aiProcessing);
        }

        // Cycle through steps
        var stepIndex = uploadState.completedImages % steps.length;
        if (steps.length > 0) {
            updateStep(steps[stepIndex]);
        }
    }

    /**
     * Update step text
     */
    function updateStep(text) {
        $('.hozio-modal-step').text(text);
    }

    /**
     * Update time estimate
     */
    function updateTimeEstimate() {
        if (!uploadState.startTime || uploadState.completedImages === 0) {
            $('.hozio-modal-time').text('');
            return;
        }

        var elapsed = Date.now() - uploadState.startTime;
        var avgTimePerImage = elapsed / uploadState.completedImages;
        var remainingImages = uploadState.totalImages - uploadState.completedImages;
        var estimatedRemaining = avgTimePerImage * remainingImages;

        if (remainingImages > 0) {
            var seconds = Math.ceil(estimatedRemaining / 1000);
            var timeText = '';

            if (seconds < 60) {
                timeText = seconds + ' second' + (seconds !== 1 ? 's' : '');
            } else {
                var minutes = Math.ceil(seconds / 60);
                timeText = minutes + ' minute' + (minutes !== 1 ? 's' : '');
            }

            $('.hozio-modal-time').text(hozioUploadOptimizer.strings.estimatedTime + ' ' + timeText);
        } else {
            $('.hozio-modal-time').text('');
        }
    }

    /**
     * Poll for optimization result
     */
    function pollOptimizationResult(attachmentId) {
        $.post(hozioUploadOptimizer.ajaxUrl, {
            action: 'hozio_get_optimization_result',
            nonce: hozioUploadOptimizer.nonce,
            attachment_id: attachmentId
        }, function(response) {
            if (response.success && response.data) {
                uploadState.totalSaved += response.data.saved_bytes || 0;
                updateSavingsDisplay();
            }
        });
    }

    /**
     * Update savings display
     */
    function updateSavingsDisplay() {
        var savedText = formatBytes(uploadState.totalSaved);
        $('.total-saved').text(savedText);
    }

    /**
     * Show complete state
     */
    function showCompleteState() {
        $('.hozio-modal-processing').hide();
        $('.hozio-modal-complete').addClass('active');
        $('.images-count').text(uploadState.completedImages);
        updateSavingsDisplay();
    }

    /**
     * Format bytes to human readable
     */
    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';

        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));

        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

})(jQuery);
