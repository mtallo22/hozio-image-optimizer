/**
 * Hozio Image Optimizer - Bulk Optimizer JavaScript
 */

(function($) {
    'use strict';

    var HozioBulkOptimizer = {
        // State
        currentPage: 1,
        totalPages: 1,
        totalImages: 0,
        allImages: [],
        selectedImages: [],
        isProcessing: false,
        isPaused: false,
        processQueue: [],
        processIndex: 0,
        results: [],
        isLoading: false,
        runningTotalSaved: 0,
        runningSuccessCount: 0,
        runningErrorCount: 0,
        lastClickedIndex: -1, // Track last clicked image for shift-select

        // Background queue state
        backgroundQueue: {
            active: false,
            state: null,
            pollInterval: null,
            sessionId: null,        // Unique ID for this optimization session
            completionShown: false  // Whether we've shown the completion modal for this session
        },

        // Initialize
        init: function() {
            // Initialize global modal lock
            window.hozioModalShowing = false;

            this.bindEvents();
            this.loadSavedContext();
            this.loadImages(true);
            this.requestNotificationPermission();
            this.checkBackgroundQueue(); // Check for any running queue on page load
        },

        // Request notification permission
        requestNotificationPermission: function() {
            if ('Notification' in window && Notification.permission === 'default') {
                // We'll request permission when they start processing
            }
        },

        // Send browser notification when processing completes
        sendNotification: function() {
            var self = this;

            // Check if notifications are supported and permitted
            if (!('Notification' in window)) {
                return;
            }

            // If permission not granted, don't show
            if (Notification.permission !== 'granted') {
                return;
            }

            // Only notify if tab is not focused
            if (document.hasFocus()) {
                return;
            }

            var successCount = this.runningSuccessCount;
            var savedFormatted = this.formatBytes(this.runningTotalSaved);
            var errorCount = this.runningErrorCount;

            var title = 'Optimization Complete!';
            var body = successCount + ' images optimized';
            if (this.runningTotalSaved > 0) {
                body += ', ' + savedFormatted + ' saved';
            }
            if (errorCount > 0) {
                body += ' (' + errorCount + ' errors)';
            }

            try {
                var notification = new Notification(title, {
                    body: body,
                    icon: hozioImageOptimizerData.pluginUrl + 'assets/images/icon-128.png',
                    tag: 'hozio-optimization-complete',
                    requireInteraction: false
                });

                // Close after 5 seconds
                setTimeout(function() {
                    notification.close();
                }, 5000);

                // Focus window when clicked
                notification.onclick = function() {
                    window.focus();
                    notification.close();
                };
            } catch (e) {
                // Notification failed, that's okay
            }
        },

        // Load saved location and keyword from localStorage
        loadSavedContext: function() {
            var self = this;
            var savedLocation = localStorage.getItem('hozio_location');
            var savedKeyword = localStorage.getItem('hozio_keyword');
            var savedCompression = localStorage.getItem('hozio_compression_level');

            if (savedLocation) {
                // Check if we have a location selector dropdown
                var $selector = $('#location-selector');
                if ($selector.length) {
                    // Try to find saved location in dropdown
                    var $option = $selector.find('option[value="' + savedLocation + '"]');
                    if ($option.length && $option.data('lat')) {
                        // It's a custom location - select it
                        $selector.val(savedLocation).trigger('change');
                    } else {
                        // It's a custom entry - select "custom" and fill input
                        $selector.val('custom');
                        $('#custom-location-wrap').show();
                        $('#location-input').val(savedLocation);
                        setTimeout(function() {
                            if (savedLocation.length >= 3) {
                                self.geocodeLocation(savedLocation);
                            }
                        }, 500);
                    }
                } else {
                    // No selector, just use the input
                    $('#location-input').val(savedLocation);
                    setTimeout(function() {
                        if (savedLocation.length >= 3) {
                            self.geocodeLocation(savedLocation);
                        }
                    }, 500);
                }
            }
            if (savedKeyword) {
                $('#keyword-input').val(savedKeyword);
            }
            // Default to lossy if no saved value
            var compressionLevel = savedCompression || 'lossy';
            $('#compression-level').val(compressionLevel);
            this.updateCompressionDescription(compressionLevel);
        },

        // Save context to localStorage
        saveContext: function() {
            localStorage.setItem('hozio_location', $('#location-input').val());
            localStorage.setItem('hozio_keyword', $('#keyword-input').val());
            localStorage.setItem('hozio_compression_level', $('#compression-level').val());
        },

        // Update compression description
        updateCompressionDescription: function(level) {
            var desc = hozioImageOptimizerData.compressionDescriptions[level] || '';
            $('#compression-desc').text(desc);
        },

        // Autocomplete state
        autocompleteIndex: -1,
        autocompleteResults: [],

        // Current coordinates (for manual pinning and view link)
        currentCoords: null,

        // Set the View Location link
        setViewLocationLink: function(lat, lng) {
            var url = 'https://www.google.com/maps?q=' + lat + ',' + lng;
            $('#view-location-btn').attr('href', url).show();
        },

        // Geocode a location and show preview
        geocodeLocation: function(location) {
            var self = this;
            var $status = $('#geocode-status');
            var $preview = $('#geocode-preview');
            var $coords = $('#geocode-coords');

            // Show loading indicator
            $status.html('<span class="dashicons dashicons-update spin" style="color: #6366f1;"></span>').show();

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_test_geocoding',
                nonce: hozioImageOptimizerData.nonce,
                location: location
            }, function(response) {
                if (response.success) {
                    var lat = parseFloat(response.data.latitude);
                    var lng = parseFloat(response.data.longitude);
                    $status.html('<span class="dashicons dashicons-yes" style="color: #22c55e;"></span>');
                    $coords.html(
                        '<strong>GPS:</strong> ' +
                        lat.toFixed(6) + ', ' +
                        lng.toFixed(6)
                    );
                    $preview.show();
                    self.currentCoords = { lat: lat, lng: lng };
                    self.setViewLocationLink(lat, lng);
                } else {
                    $status.html('<span class="dashicons dashicons-warning" style="color: #f59e0b;" title="Could not geocode"></span>');
                    $preview.hide();
                    $('#view-location-btn').hide();
                    self.currentCoords = null;
                }
            }).fail(function() {
                $status.html('<span class="dashicons dashicons-no" style="color: #ef4444;"></span>');
                $preview.hide();
                $('#view-location-btn').hide();
                self.currentCoords = null;
            });
        },

        // Search for US locations (autocomplete)
        searchLocations: function(query) {
            var self = this;
            var $autocomplete = $('#location-autocomplete');

            if (query.length < 2) {
                $autocomplete.hide();
                return;
            }

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_search_locations',
                nonce: hozioImageOptimizerData.nonce,
                query: query
            }, function(response) {
                if (response.success && response.data.locations && response.data.locations.length > 0) {
                    self.autocompleteResults = response.data.locations;
                    self.autocompleteIndex = -1;

                    var html = '';
                    response.data.locations.forEach(function(loc, index) {
                        html += '<div class="location-autocomplete-item" data-index="' + index + '">';
                        html += '<span class="dashicons dashicons-location"></span>';
                        html += '<span class="location-name">' + loc.name + '</span>';
                        html += '</div>';
                    });

                    $autocomplete.html(html).show();
                } else {
                    $autocomplete.hide();
                    self.autocompleteResults = [];
                }
            }).fail(function() {
                $autocomplete.hide();
                self.autocompleteResults = [];
            });
        },

        // Select autocomplete item
        selectAutocompleteItem: function(index) {
            if (index >= 0 && index < this.autocompleteResults.length) {
                var location = this.autocompleteResults[index];
                $('#location-input').val(location.name);
                $('#location-autocomplete').hide();
                this.autocompleteResults = [];
                this.autocompleteIndex = -1;
                this.saveContext();
                this.geocodeLocation(location.name);
            }
        },

        // Update autocomplete highlight
        updateAutocompleteHighlight: function() {
            var $items = $('.location-autocomplete-item');
            $items.removeClass('highlighted');
            if (this.autocompleteIndex >= 0 && this.autocompleteIndex < $items.length) {
                $items.eq(this.autocompleteIndex).addClass('highlighted');
            }
        },

        // Bind all event handlers
        bindEvents: function() {
            var self = this;

            // Location selector dropdown (for custom locations)
            $('#location-selector').on('change', function() {
                var selected = $(this).val();
                var $option = $(this).find('option:selected');
                var lat = $option.data('lat');
                var lng = $option.data('lng');

                if (selected === 'custom') {
                    // Show custom input
                    $('#custom-location-wrap').show();
                    $('#location-input').focus();
                    $('#geocode-preview').hide();
                    $('#view-location-btn').hide();
                } else if (selected && lat && lng) {
                    // Custom location selected - show coords directly
                    $('#custom-location-wrap').hide();
                    $('#location-input').val(selected); // Store the name for AI context
                    $('#geocode-status').html('<span class="dashicons dashicons-yes" style="color: #22c55e;"></span>').show();
                    $('#geocode-coords').html(parseFloat(lat).toFixed(4) + ', ' + parseFloat(lng).toFixed(4));
                    $('#geocode-preview').show();
                    self.setViewLocationLink(lat, lng);
                    self.currentCoords = { lat: lat, lng: lng };
                    self.saveContext();
                } else {
                    // No location
                    $('#custom-location-wrap').hide();
                    $('#location-input').val('');
                    $('#geocode-preview').hide();
                    $('#geocode-status').hide();
                    $('#view-location-btn').hide();
                    self.currentCoords = null;
                    self.saveContext();
                }
            });

            // Pin Coordinates button
            $('#pin-coordinates-btn').on('click', function() {
                $('#pin-coordinates-modal').show();
                // Pre-fill with current coords if available
                if (self.currentCoords) {
                    $('#manual-lat').val(self.currentCoords.lat);
                    $('#manual-lng').val(self.currentCoords.lng);
                }
            });

            // Close modal
            $(document).on('click', '.mini-modal-close', function() {
                $('#pin-coordinates-modal').hide();
            });

            // Close modal on backdrop click
            $('#pin-coordinates-modal').on('click', function(e) {
                if ($(e.target).is('#pin-coordinates-modal')) {
                    $(this).hide();
                }
            });

            // Parse pasted coordinates
            $('#paste-coords').on('input', function() {
                var val = $(this).val().trim();
                // Try to parse various formats: "40.7128, -74.0060" or "40.7128 -74.0060" or "(40.7128, -74.0060)"
                val = val.replace(/[()]/g, ''); // Remove parentheses
                var parts = val.split(/[,\s]+/).filter(function(p) { return p.length > 0; });
                if (parts.length >= 2) {
                    var lat = parseFloat(parts[0]);
                    var lng = parseFloat(parts[1]);
                    if (!isNaN(lat) && !isNaN(lng)) {
                        $('#manual-lat').val(lat);
                        $('#manual-lng').val(lng);
                    }
                }
            });

            // Apply coordinates
            $('#apply-coords-btn').on('click', function() {
                var lat = parseFloat($('#manual-lat').val());
                var lng = parseFloat($('#manual-lng').val());

                if (isNaN(lat) || isNaN(lng)) {
                    alert('Please enter valid coordinates');
                    return;
                }

                // Validate range
                if (lat < -90 || lat > 90) {
                    alert('Latitude must be between -90 and 90');
                    return;
                }
                if (lng < -180 || lng > 180) {
                    alert('Longitude must be between -180 and 180');
                    return;
                }

                // Apply the coordinates
                self.currentCoords = { lat: lat, lng: lng };
                $('#geocode-status').html('<span class="dashicons dashicons-yes" style="color: #22c55e;"></span>').show();
                $('#geocode-coords').html(lat.toFixed(4) + ', ' + lng.toFixed(4) + ' <em style="color:#64748b;font-size:9px;">(manual)</em>');
                $('#geocode-preview').show();
                self.setViewLocationLink(lat, lng);

                // Save manual coords to localStorage
                localStorage.setItem('hozio_manual_coords', JSON.stringify({ lat: lat, lng: lng }));

                // Close modal
                $('#pin-coordinates-modal').hide();
            });

            // Auto-save location and keyword on change
            $('#location-input, #keyword-input').on('change blur', function() {
                self.saveContext();
            });

            // Location autocomplete with debounce
            var searchTimeout;
            $('#location-input').on('input', function() {
                clearTimeout(searchTimeout);
                var query = $(this).val().trim();

                if (query.length < 2) {
                    $('#location-autocomplete').hide();
                    $('#geocode-preview').hide();
                    $('#geocode-status').hide();
                    return;
                }

                searchTimeout = setTimeout(function() {
                    self.searchLocations(query);
                }, 300);
            });

            // Keyboard navigation for autocomplete
            $('#location-input').on('keydown', function(e) {
                var $autocomplete = $('#location-autocomplete');
                if (!$autocomplete.is(':visible')) return;

                var itemCount = self.autocompleteResults.length;

                switch (e.keyCode) {
                    case 40: // Down arrow
                        e.preventDefault();
                        self.autocompleteIndex = Math.min(self.autocompleteIndex + 1, itemCount - 1);
                        self.updateAutocompleteHighlight();
                        break;
                    case 38: // Up arrow
                        e.preventDefault();
                        self.autocompleteIndex = Math.max(self.autocompleteIndex - 1, -1);
                        self.updateAutocompleteHighlight();
                        break;
                    case 13: // Enter
                        e.preventDefault();
                        if (self.autocompleteIndex >= 0) {
                            self.selectAutocompleteItem(self.autocompleteIndex);
                        } else if (itemCount > 0) {
                            self.selectAutocompleteItem(0);
                        }
                        break;
                    case 27: // Escape
                        $autocomplete.hide();
                        self.autocompleteResults = [];
                        self.autocompleteIndex = -1;
                        break;
                }
            });

            // Click on autocomplete item
            $(document).on('click', '.location-autocomplete-item', function() {
                var index = parseInt($(this).data('index'));
                self.selectAutocompleteItem(index);
            });

            // Hover on autocomplete item
            $(document).on('mouseenter', '.location-autocomplete-item', function() {
                self.autocompleteIndex = parseInt($(this).data('index'));
                self.updateAutocompleteHighlight();
            });

            // Hide autocomplete when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#location-input, #location-autocomplete').length) {
                    $('#location-autocomplete').hide();
                    self.autocompleteResults = [];
                    self.autocompleteIndex = -1;
                }
            });

            // Geocode on blur if autocomplete not visible
            $('#location-input').on('blur', function() {
                var location = $(this).val().trim();
                // Delay to allow click on autocomplete item
                setTimeout(function() {
                    if (!$('#location-autocomplete').is(':visible') && location.length >= 3) {
                        self.geocodeLocation(location);
                    }
                }, 200);
            });

            // Compression level change
            $('#compression-level').on('change', function() {
                var level = $(this).val();
                self.updateCompressionDescription(level);
                self.saveContext();
            });

            // Select All button - selects ALL images, not just loaded ones
            $('#select-all-btn').on('click', function() {
                self.selectAllImages();
            });

            // Individual image selection (checkbox change - no shift handling here)
            $(document).on('change', '.hozio-image-card .card-checkbox', function(e) {
                // Only process if not triggered programmatically from card click
                if (e.originalEvent && !e.originalEvent.shiftKey) {
                    var id = $(this).val();
                    var card = $(this).closest('.hozio-image-card');
                    var currentIndex = $('.hozio-image-card').index(card);

                    if ($(this).is(':checked')) {
                        if (self.selectedImages.indexOf(id) === -1) {
                            self.selectedImages.push(id);
                        }
                    } else {
                        self.selectedImages = self.selectedImages.filter(function(imgId) {
                            return imgId !== id;
                        });
                    }

                    self.lastClickedIndex = currentIndex;
                    self.updateSelectionUI();
                }
            });

            // Image card click (toggle selection with shift-click support)
            $(document).on('click', '.hozio-image-card', function(e) {
                if ($(e.target).is('input') || $(e.target).closest('.view-details-btn').length) return;

                var card = $(this);
                var checkbox = card.find('.card-checkbox');
                var id = checkbox.val();
                var currentIndex = $('.hozio-image-card').index(card);

                // Shift-click: select range
                if (e.shiftKey && self.lastClickedIndex !== -1) {
                    var startIndex = Math.min(self.lastClickedIndex, currentIndex);
                    var endIndex = Math.max(self.lastClickedIndex, currentIndex);

                    $('.hozio-image-card').slice(startIndex, endIndex + 1).each(function() {
                        var rangeId = $(this).find('.card-checkbox').val();
                        if (self.selectedImages.indexOf(rangeId) === -1) {
                            self.selectedImages.push(rangeId);
                        }
                        $(this).find('.card-checkbox').prop('checked', true);
                        $(this).addClass('selected');
                    });

                    self.updateSelectionUI();
                } else {
                    // Normal click: toggle single selection
                    var isChecked = checkbox.is(':checked');
                    checkbox.prop('checked', !isChecked);

                    if (!isChecked) {
                        if (self.selectedImages.indexOf(id) === -1) {
                            self.selectedImages.push(id);
                        }
                    } else {
                        self.selectedImages = self.selectedImages.filter(function(imgId) {
                            return imgId !== id;
                        });
                    }

                    self.lastClickedIndex = currentIndex;
                    self.updateSelectionUI();
                }
            });

            // View details button
            $(document).on('click', '.view-details-btn', function(e) {
                e.stopPropagation();
                var id = $(this).closest('.hozio-image-card').data('id');
                self.showImageDetails(id);
            });

            // Search
            var searchTimeout;
            $('#search-input').on('keyup', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    self.currentPage = 1;
                    self.allImages = [];
                    self.loadImages(true);
                }, 300);
            });

            // Filter by type
            $('#filter-type').on('change', function() {
                self.currentPage = 1;
                self.allImages = [];
                self.loadImages(true);
            });

            // Filter by status
            $('#filter-status').on('change', function() {
                self.currentPage = 1;
                self.allImages = [];
                self.loadImages(true);
            });

            // Sort
            $('#sort-by').on('change', function() {
                self.currentPage = 1;
                self.allImages = [];
                self.loadImages(true);
            });

            // Refresh
            $('#refresh-btn').on('click', function() {
                self.currentPage = 1;
                self.allImages = [];
                self.selectedImages = [];
                self.loadImages(true);
            });

            // Load More button
            $(document).on('click', '#load-more-btn', function() {
                if (!self.isLoading && self.currentPage < self.totalPages) {
                    self.currentPage++;
                    self.loadImages(false);
                }
            });

            // Preview button
            $('#preview-btn').on('click', function() {
                self.showPreview();
            });

            // Optimize selected button
            $('#optimize-selected-btn').on('click', function() {
                self.checkLocationAndOptimize();
            });

            // Pause button
            $('#pause-btn').on('click', function() {
                self.togglePause();
            });

            // Modal close
            $('.hozio-modal-close, .hozio-modal-overlay').on('click', function() {
                $(this).closest('.hozio-modal').hide();
                // Release the global modal lock when any modal is closed
                window.hozioModalShowing = false;
            });

            // Cancel preview
            $('#cancel-preview').on('click', function() {
                $('#preview-modal').hide();
            });

            // Confirm optimize from preview
            $('#confirm-optimize').on('click', function() {
                $('#preview-modal').hide();
                self.startOptimization();
            });

            // Single image optimize from detail modal
            $('#optimize-single-btn').on('click', function() {
                var id = $(this).data('image-id');
                var isOptimized = $(this).data('is-optimized');

                // Check if image is already optimized and force re-optimization is not enabled
                if (isOptimized && !$('#opt-force-reoptimize').is(':checked')) {
                    alert('This image has already been optimized.\n\nTo re-optimize it, check the "Force Re-optimization" option in the sidebar.');
                    return;
                }

                if (id) {
                    $('#image-detail-modal').hide();
                    self.selectedImages = [id.toString()];
                    self.checkLocationAndOptimize();
                }
            });

            // Restore single image
            $('#restore-single-btn').on('click', function() {
                var id = $(this).data('image-id');
                if (id) {
                    self.restoreImage(id);
                }
            });

            // Location warning modal buttons
            $('#location-warning-back').on('click', function() {
                $('#location-warning-modal').hide();
                $('#location-input').focus();
            });

            $('#location-warning-continue').on('click', function() {
                $('#location-warning-modal').hide();
                self.proceedWithOptimization();
            });

            // Select Unoptimized button
            $('#select-unoptimized-btn').on('click', function() {
                self.selectUnoptimized();
            });

            // Deselect All button
            $('#deselect-all-btn').on('click', function() {
                self.deselectAll();
            });

            // Select Recommended button (all unoptimized images over 100KB)
            $('#select-recommended-btn').on('click', function() {
                self.selectRecommended();
            });

            // Reset Stats button
            $('#reset-stats-btn').on('click', function() {
                self.resetStats();
            });

            // Backup toggle - syncs with settings
            $('#opt-backup').on('change', function() {
                var enabled = $(this).is(':checked');
                self.toggleBackupSetting(enabled);
            });
        },

        // Load images from server
        loadImages: function(reset) {
            var self = this;
            var grid = $('#image-grid');

            if (this.isLoading) return;
            this.isLoading = true;

            if (reset) {
                this.allImages = [];
                this.currentPage = 1;
                grid.html('<div class="loading-spinner"><span class="spinner is-active"></span><p>Loading images...</p></div>');
            } else {
                $('#load-more-btn').prop('disabled', true).text('Loading...');
            }

            var sortVal = ($('#sort-by').val() || 'date-DESC').split('-');

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_get_images',
                nonce: hozioImageOptimizerData.nonce,
                page: this.currentPage,
                per_page: 50,
                search: $('#search-input').val(),
                filter: $('#filter-type').val(),
                status_filter: $('#filter-status').val(),
                sort_by: sortVal[0],
                sort_order: sortVal[1]
            }, function(response) {
                self.isLoading = false;

                if (response.success) {
                    response.data.images.forEach(function(img) {
                        var exists = self.allImages.some(function(existing) {
                            return existing.id === img.id;
                        });
                        if (!exists) {
                            self.allImages.push(img);
                        }
                    });

                    self.totalPages = response.data.pages;
                    self.totalImages = response.data.total;

                    if (reset) {
                        self.renderImages();
                    } else {
                        self.appendImages(response.data.images);
                    }

                    self.updateLoadMoreButton();
                } else {
                    if (reset) {
                        grid.html('<div class="hozio-empty-state"><span class="dashicons dashicons-warning"></span><p>' + (response.data.message || 'Error loading images') + '</p></div>');
                    }
                }
            }).fail(function() {
                self.isLoading = false;
                if (reset) {
                    grid.html('<div class="hozio-empty-state"><span class="dashicons dashicons-warning"></span><p>Failed to load images</p></div>');
                }
            });
        },

        // Render all images to grid
        renderImages: function() {
            var grid = $('#image-grid');

            if (this.allImages.length === 0) {
                grid.html('<div class="hozio-empty-state"><span class="dashicons dashicons-format-image"></span><h3>' + hozioImageOptimizerData.strings.noImages + '</h3></div>');
                $('#pagination').hide();
                return;
            }

            var html = this.generateImageCardsHTML(this.allImages);
            grid.html(html);
            this.restoreSelectionState();
        },

        // Append new images to existing grid
        appendImages: function(newImages) {
            var grid = $('#image-grid');
            var html = this.generateImageCardsHTML(newImages);
            $('#load-more-wrap').remove();
            grid.append(html);
            this.restoreSelectionState();
        },

        // Generate HTML for image cards
        generateImageCardsHTML: function(images) {
            var self = this;
            var html = '';

            images.forEach(function(image) {
                var typeLabel = image.mime_type.split('/')[1].toUpperCase();
                var isSelected = self.selectedImages.indexOf(image.id.toString()) !== -1;
                var isOptimized = image.is_optimized || image.has_backup;
                var statusClass = isOptimized ? 'optimized' : 'unoptimized';
                var dimensions = (image.width && image.height) ? (image.width + ' × ' + image.height) : '';

                html += '<div class="hozio-image-card ' + statusClass + (isSelected ? ' selected' : '') + '" data-id="' + image.id + '" data-size="' + image.file_size + '">';
                html += '<input type="checkbox" class="card-checkbox" value="' + image.id + '"' + (isSelected ? ' checked' : '') + '>';

                // Image area with badges
                html += '<div class="card-image-wrap">';
                html += '<img src="' + image.thumbnail + '" alt="" class="card-image" loading="lazy">';

                // Type badge (top-left)
                html += '<span class="card-type-badge">' + typeLabel + '</span>';

                // Status badge (top-right)
                if (image.is_restored) {
                    html += '<span class="card-status-badge restored"><span class="dashicons dashicons-undo"></span></span>';
                } else if (isOptimized) {
                    html += '<span class="card-status-badge optimized"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></span>';
                } else {
                    html += '<span class="card-status-badge not-optimized"></span>';
                }

                html += '<button type="button" class="view-details-btn" title="View Details"><span class="dashicons dashicons-visibility"></span></button>';
                html += '</div>';

                // Dark info area
                html += '<div class="card-body">';

                // Title (WordPress title, truncated)
                var displayTitle = image.title || image.filename;
                html += '<div class="card-title" title="' + displayTitle + '">' + displayTitle + '</div>';

                // Dimensions
                if (dimensions) {
                    html += '<div class="card-dimensions">' + dimensions + '</div>';
                }

                // File size
                if (isOptimized && image.original_size_formatted && image.savings_percent > 0) {
                    html += '<div class="card-size-line">' + image.original_size_formatted + ' → ' + image.file_size_formatted + '</div>';
                    html += '<div class="card-savings">↓ ' + (image.savings_bytes_formatted || '') + ' (' + image.savings_percent + '% smaller)</div>';
                } else {
                    html += '<div class="card-size-line">' + image.file_size_formatted + '</div>';
                }

                // Bottom row: status
                html += '<div class="card-bottom-row">';
                if (image.is_restored) {
                    html += '<span class="card-process-status restored"><span class="dashicons dashicons-undo"></span> Restored</span>';
                } else if (isOptimized) {
                    html += '<span class="card-process-status done"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Optimized</span>';
                } else {
                    html += '<span class="card-process-status pending">Not Optimized</span>';
                }
                html += '</div>';

                html += '</div>'; // .card-body
                html += '</div>'; // .hozio-image-card
            });

            return html;
        },

        // Restore checkbox selection state
        restoreSelectionState: function() {
            var self = this;
            this.selectedImages.forEach(function(id) {
                $('.hozio-image-card[data-id="' + id + '"]').addClass('selected')
                    .find('.card-checkbox').prop('checked', true);
            });
        },

        // Update Load More button
        updateLoadMoreButton: function() {
            var pagination = $('#pagination');

            if (this.currentPage < this.totalPages) {
                var remaining = this.totalImages - this.allImages.length;
                pagination.html(
                    '<div id="load-more-wrap">' +
                    '<button type="button" class="button button-primary" id="load-more-btn">' +
                    'Load More (' + remaining + ' remaining)' +
                    '</button>' +
                    '<p class="images-loaded-count">Showing ' + this.allImages.length + ' of ' + this.totalImages + ' images</p>' +
                    '</div>'
                );
                pagination.show();
            } else {
                if (this.allImages.length > 0) {
                    pagination.html('<p class="images-loaded-count">Showing all ' + this.allImages.length + ' images</p>');
                    pagination.show();
                } else {
                    pagination.hide();
                }
            }
        },

        // Update selection UI
        updateSelectionUI: function() {
            var self = this;

            $('.hozio-image-card').each(function() {
                var id = $(this).data('id').toString();
                var isSelected = self.selectedImages.indexOf(id) !== -1;
                $(this).toggleClass('selected', isSelected);
                $(this).find('.card-checkbox').prop('checked', isSelected);
            });

            $('#selected-count').text(this.selectedImages.length);
            var hasSelection = this.selectedImages.length > 0;

            // Don't re-enable optimize button if queue is actively running
            var $optBtn = $('#optimize-selected-btn');
            var isActivelyRunning = this.backgroundQueue.active && this.backgroundQueue.state === 'running';

            // Fallback: if button says "Optimizing..." but queue isn't running, unstick it
            if (!isActivelyRunning && $optBtn.text().indexOf('Optimizing') !== -1) {
                isActivelyRunning = false;
            }

            if (isActivelyRunning) {
                // Don't touch optimize button - it's showing "Optimizing..."
            } else {
                $optBtn.prop('disabled', !hasSelection);
                $('#preview-btn').prop('disabled', !hasSelection);

                // Update button text with count
                if (hasSelection) {
                    $optBtn.html('<span class="dashicons dashicons-image-rotate"></span> Optimize ' + this.selectedImages.length + ' Image' + (this.selectedImages.length > 1 ? 's' : ''));
                } else {
                    $optBtn.html('<span class="dashicons dashicons-image-rotate"></span> Optimize');
                }
            }

            // Update selection badge, count display, and Select All label
            var $badge = $('#selected-count-badge');
            var $selInfo = $('#hz-selection-info');
            if (hasSelection) {
                $badge.text(this.selectedImages.length).show();
                $('#selected-count').text(this.selectedImages.length);
                $selInfo.show();
                $('#deselect-all-btn').show();
            } else {
                $badge.hide();
                $selInfo.hide();
                $('#deselect-all-btn').hide();
            }

            // Update the "Optimize More" button text in queue panel (if visible)
            var $moreBtn = $('#background-queue-status .queue-optimize-more-btn');
            if ($moreBtn.is(':visible') && hasSelection) {
                $moreBtn.text('Optimize ' + this.selectedImages.length + ' More');
            } else if (!hasSelection) {
                $moreBtn.hide();
            }

            // Calculate estimated savings
            this.calculateEstimatedSavings();
        },

        // Calculate estimated savings
        calculateEstimatedSavings: function() {
            var self = this;
            var totalSize = 0;

            this.selectedImages.forEach(function(id) {
                var card = $('.hozio-image-card[data-id="' + id + '"]');
                var size = parseInt(card.data('size')) || 0;
                totalSize += size;
            });

            if (totalSize > 0) {
                // Estimate 30-60% savings based on compression level
                var level = $('#compression-level').val();
                var savingsPercent = level === 'lossy' ? 0.55 : (level === 'glossy' ? 0.40 : 0.20);
                var estimatedSavings = Math.round(totalSize * savingsPercent);

                $('#savings-estimate').text(this.formatBytes(estimatedSavings));
                $('#estimated-savings').show();
            } else {
                $('#estimated-savings').hide();
            }
        },

        // Show image details modal
        showImageDetails: function(imageId) {
            var modal = $('#image-detail-modal');
            var content = $('#image-detail-content');
            content.html('<div class="loading-spinner"><span class="spinner is-active"></span><p>Loading...</p></div>');
            modal.show();

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_get_image_info',
                nonce: hozioImageOptimizerData.nonce,
                attachment_id: imageId
            }, function(response) {
                if (response.success) {
                    var data = response.data;
                    var html = '<div class="image-detail-grid">';
                    html += '<div class="image-preview"><img src="' + data.thumbnail + '" alt=""></div>';
                    html += '<div class="image-info">';
                    html += '<h3>' + data.filename + '</h3>';

                    // File size with optimization savings if available
                    var fileSizeHtml = data.file_size_formatted;
                    if (data.has_backup && data.original_size_formatted) {
                        fileSizeHtml = '<span class="size-comparison">';
                        fileSizeHtml += '<span class="original-size">' + data.original_size_formatted + '</span>';
                        fileSizeHtml += ' <span class="size-arrow">→</span> ';
                        fileSizeHtml += '<span class="new-size">' + data.file_size_formatted + '</span>';
                        if (data.savings_percent > 0) {
                            fileSizeHtml += ' <span class="savings-badge">-' + data.savings_percent + '%</span>';
                        }
                        fileSizeHtml += '</span>';
                    }

                    html += '<table class="detail-table">';
                    html += '<tr><th>Dimensions</th><td>' + data.width + ' x ' + data.height + '</td></tr>';
                    html += '<tr><th>File Size</th><td>' + fileSizeHtml + '</td></tr>';
                    html += '<tr><th>Type</th><td>' + data.mime_type + '</td></tr>';
                    html += '<tr><th>Title</th><td>' + (data.title || '<span class="not-set">Not set</span>') + '</td></tr>';
                    html += '<tr><th>Alt Text</th><td>' + (data.alt_text || '<span class="not-set">Not set</span>') + '</td></tr>';
                    html += '<tr><th>Caption</th><td>' + (data.caption || '<span class="not-set">Not set</span>') + '</td></tr>';
                    html += '<tr><th>Description</th><td class="description-cell">' + (data.description || '<span class="not-set">Not set</span>') + '</td></tr>';
                    html += '<tr><th>Optimized</th><td>' + (data.has_backup ? '<span class="status-yes">Yes</span>' : '<span class="status-no">No</span>') + '</td></tr>';
                    html += '<tr><th>References</th><td>' + data.reference_count + ' locations</td></tr>';
                    html += '</table>';
                    html += '</div>';
                    html += '</div>';

                    content.html(html);

                    // Update buttons
                    $('#optimize-single-btn').data('image-id', imageId);
                    // Store whether image is already optimized for warning check
                    $('#optimize-single-btn').data('is-optimized', data.is_optimized || false);
                    if (data.has_backup) {
                        $('#restore-single-btn').data('image-id', imageId).show();
                    } else {
                        $('#restore-single-btn').hide();
                    }
                } else {
                    content.html('<p style="color:red;">' + (response.data.message || 'Failed to load details') + '</p>');
                }
            });
        },

        // Restore image from backup
        restoreImage: function(imageId) {
            if (!confirm('Are you sure you want to restore this image to its original?')) {
                return;
            }

            var self = this;

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_restore_image',
                nonce: hozioImageOptimizerData.nonce,
                attachment_id: imageId
            }, function(response) {
                if (response.success) {
                    alert('Image restored successfully!');
                    $('#image-detail-modal').hide();
                    self.loadImages(true);
                } else {
                    alert('Failed to restore: ' + (response.data.message || 'Unknown error'));
                }
            });
        },

        // Show preview modal
        showPreview: function() {
            var self = this;
            if (this.selectedImages.length === 0) {
                alert(hozioImageOptimizerData.strings.selectImages);
                return;
            }

            var isRenameEnabled = $('#opt-rename').is(':checked');
            var modal = $('#preview-modal');
            var content = $('#preview-content');

            // If AI rename is disabled, show a quick summary instead of calling the API
            if (!isRenameEnabled) {
                var totalSelected = this.selectedImages.length;
                var ops = [];
                if ($('#opt-compress').is(':checked')) ops.push('Compress');
                if ($('#opt-convert').is(':checked')) ops.push('Convert to WebP');

                var html = '<div style="padding: 10px 0;">';
                html += '<p style="font-size: 15px; margin-bottom: 15px;"><strong>' + totalSelected + '</strong> image' + (totalSelected > 1 ? 's' : '') + ' selected for optimization:</p>';
                html += '<div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px;">';
                ops.forEach(function(op) {
                    html += '<span style="background: #f1f5f9; padding: 6px 14px; border-radius: 6px; font-size: 13px; font-weight: 500;">' + op + '</span>';
                });
                html += '</div>';

                var targetSize = $('#target-filesize').val() || '200';
                html += '<p style="color: #64748b; font-size: 13px;">Target: under <strong>' + targetSize + ' KB</strong> per image</p>';
                html += '<p style="color: #64748b; font-size: 13px;">Compression: <strong>' + $('#compression-level option:selected').text() + '</strong></p>';
                html += '</div>';

                content.html(html);
                modal.show();
                return;
            }

            content.html('<div class="loading-spinner"><span class="spinner is-active"></span><p>Generating preview...</p></div>');
            modal.show();

            var previewIds = this.selectedImages.slice(0, 10);

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_preview_rename',
                nonce: hozioImageOptimizerData.nonce,
                attachment_ids: previewIds,
                location: $('#location-input').val(),
                keyword_hint: $('#keyword-input').val()
            }, function(response) {
                if (response.success) {
                    var html = '<table class="preview-table">';
                    html += '<thead><tr><th>Image</th><th>Current</th><th></th><th>Proposed</th></tr></thead>';
                    html += '<tbody>';

                    response.data.previews.forEach(function(preview) {
                        if (preview.success) {
                            html += '<tr>';
                            html += '<td><img src="' + preview.thumbnail + '" width="50" height="50" style="object-fit:cover;"></td>';
                            html += '<td>' + preview.current.filename + '</td>';
                            html += '<td class="change-arrow">→</td>';
                            html += '<td>' + preview.proposed.filename + '</td>';
                            html += '</tr>';
                        }
                    });

                    html += '</tbody></table>';

                    if (HozioBulkOptimizer.selectedImages.length > 10) {
                        html += '<p style="margin-top:15px; color:#666;">Showing preview for first 10 images. ' +
                                (HozioBulkOptimizer.selectedImages.length - 10) + ' more will be processed.</p>';
                    }

                    content.html(html);
                } else {
                    content.html('<p style="color:red;">' + (response.data.message || 'Preview failed') + '</p>');
                }
            }).fail(function() {
                content.html('<p style="color:red;">Failed to generate preview</p>');
            });
        },

        // Check location and show warning if empty
        checkLocationAndOptimize: function() {
            if (this.selectedImages.length === 0) {
                alert(hozioImageOptimizerData.strings.selectImages);
                return;
            }

            // Block if optimization is already running
            if (this.backgroundQueue.active && this.backgroundQueue.state === 'running') {
                alert('Please wait for the current optimization to finish before starting another.');
                return;
            }

            var location = $('#location-input').val().trim();
            var isRenameEnabled = $('#opt-rename').is(':checked');

            // Only show warning if AI rename is enabled and location is empty
            if (isRenameEnabled && !location) {
                $('#location-warning-modal').show();
                return;
            }

            this.proceedWithOptimization();
        },

        // Proceed with optimization (after location check)
        proceedWithOptimization: function() {
            // Use background processing for larger batches or all optimizations
            this.startBackgroundOptimization();
        },

        // Start optimization process
        startOptimization: function() {
            if (this.selectedImages.length === 0) {
                alert(hozioImageOptimizerData.strings.selectImages);
                return;
            }

            // Save context before starting
            this.saveContext();

            this.isProcessing = true;
            this.isPaused = false;

            // Filter out already optimized if option is checked
            // BUT: if force re-optimization is checked, include all images regardless
            var skipOptimized = $('#opt-skip-optimized').is(':checked');
            var forceReoptimize = $('#opt-force-reoptimize').is(':checked');

            if (skipOptimized && !forceReoptimize) {
                var self = this;
                this.processQueue = this.selectedImages.filter(function(id) {
                    var card = $('.hozio-image-card[data-id="' + id + '"]');
                    return !card.hasClass('optimized');
                });
            } else {
                this.processQueue = this.selectedImages.slice();
            }

            if (this.processQueue.length === 0) {
                alert('All selected images are already optimized! Check "Force re-optimization" to process them again.');
                return;
            }

            this.processIndex = 0;
            this.results = [];
            this.runningTotalSaved = 0;
            this.runningSuccessCount = 0;
            this.runningErrorCount = 0;

            // Show progress panel
            $('#progress-panel').show();
            $('#optimize-selected-btn, #preview-btn').prop('disabled', true);
            this.updateProgress(0);

            // Reset progress stats
            $('#progress-success').text('0');
            $('#progress-saved').text('0 KB');
            $('#progress-errors').text('0');

            // Start processing
            this.processNext();
        },

        // Process next image in queue
        processNext: function() {
            var self = this;

            if (this.isPaused) {
                return;
            }

            if (this.processIndex >= this.processQueue.length) {
                this.finishProcessing();
                return;
            }

            var imageId = this.processQueue[this.processIndex];
            var card = $('.hozio-image-card[data-id="' + imageId + '"]');
            card.addClass('processing');

            var progress = Math.round((this.processIndex / this.processQueue.length) * 100);
            this.updateProgress(progress, 'Processing image ' + (this.processIndex + 1) + ' of ' + this.processQueue.length);

            // Get compression level quality
            var compressionLevel = $('#compression-level').val();
            var quality = compressionLevel === 'lossy' ? 70 : (compressionLevel === 'glossy' ? 82 : 95);

            // Build data object
            var postData = {
                action: 'hozio_optimize_image',
                nonce: hozioImageOptimizerData.nonce,
                attachment_id: imageId,
                compress: $('#opt-compress').is(':checked') ? 1 : 0,
                convert: $('#opt-convert').is(':checked') ? 1 : 0,
                rename: $('#opt-rename').is(':checked') ? 1 : 0,
                location: $('#location-input').val(),
                keyword_hint: $('#keyword-input').val(),
                quality: quality,
                force: $('#opt-force-reoptimize').is(':checked') ? 1 : 0,
                target_filesize: $('#target-filesize').val() || 0
            };

            // Add manual coordinates if set (for more reliable GPS embedding)
            if (self.currentCoords) {
                postData.manual_lat = self.currentCoords.lat;
                postData.manual_lng = self.currentCoords.lng;
            }

            $.post(hozioImageOptimizerData.ajaxUrl, postData, function(response) {
                card.removeClass('processing');

                if (response.success) {
                    card.removeClass('unoptimized').addClass('completed optimized just-completed');

                    // Add checkmark overlay if not present
                    if (card.find('.card-status-overlay').length === 0) {
                        card.find('.card-image-wrap').before(
                            '<div class="card-status-overlay optimized-overlay">' +
                            '<svg class="checkmark-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                            '</div>'
                        );
                    }

                    // Remove animation class after it finishes
                    setTimeout(function() { card.removeClass('just-completed'); }, 700);

                    self.results.push({
                        id: imageId,
                        success: true,
                        data: response.data
                    });

                    // Update running totals
                    self.runningSuccessCount++;
                    if (response.data.total_savings > 0) {
                        self.runningTotalSaved += response.data.total_savings;
                    }
                    $('#progress-success').text(self.runningSuccessCount);
                    $('#progress-saved').text(self.formatBytes(self.runningTotalSaved));

                    // Update card with new info
                    if (response.data.new_filename) {
                        card.find('.card-title').text(response.data.new_filename);
                    }
                    if (response.data.thumbnail) {
                        card.find('.card-image').attr('src', response.data.thumbnail + '?t=' + Date.now());
                    }

                    // Update size line
                    if (response.data.original_size && response.data.savings_percent > 0) {
                        card.find('.card-size-line').html(
                            self.formatBytes(response.data.original_size) + ' → ' + self.formatBytes(response.data.new_size || 0)
                        );
                        // Add or update savings line
                        var savingsBytes = response.data.original_size - (response.data.new_size || 0);
                        var savingsHtml = '↓ ' + self.formatBytes(savingsBytes) + ' (' + response.data.savings_percent + '% smaller)';
                        if (card.find('.card-savings').length) {
                            card.find('.card-savings').html(savingsHtml);
                        } else {
                            card.find('.card-size-line').after('<div class="card-savings">' + savingsHtml + '</div>');
                        }
                    } else {
                        card.find('.card-size-line').text(self.formatBytes(response.data.new_size || 0));
                    }

                    // Update status badge
                    card.find('.card-status-badge').removeClass('not-optimized').addClass('optimized')
                        .html('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>');

                    // Update bottom status
                    card.find('.card-process-status').removeClass('pending').addClass('done')
                        .html('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Optimized');
                } else {
                    card.addClass('error');
                    self.results.push({
                        id: imageId,
                        success: false,
                        error: response.data.message
                    });
                    self.runningErrorCount++;
                    $('#progress-errors').text(self.runningErrorCount);
                }

                self.processIndex++;
                self.processNext();
            }).fail(function() {
                card.removeClass('processing').addClass('error');
                self.results.push({
                    id: imageId,
                    success: false,
                    error: 'Request failed'
                });
                self.runningErrorCount++;
                $('#progress-errors').text(self.runningErrorCount);
                self.processIndex++;
                self.processNext();
            });
        },

        // Update progress display
        updateProgress: function(percent, status) {
            $('#progress-fill').css('width', percent + '%');
            $('#progress-text').text(percent + '%');
            if (status) {
                $('#progress-status').text(status);
            }
        },

        // Toggle pause/resume
        togglePause: function() {
            var btn = $('#pause-btn');

            if (this.isPaused) {
                this.isPaused = false;
                btn.html('<span class="dashicons dashicons-controls-pause"></span> ' + hozioImageOptimizerData.strings.pause);
                this.processNext();
            } else {
                this.isPaused = true;
                btn.html('<span class="dashicons dashicons-controls-play"></span> ' + hozioImageOptimizerData.strings.resume);
                $('#progress-status').text(hozioImageOptimizerData.strings.paused);
            }
        },

        // Finish processing and show results
        finishProcessing: function() {
            var self = this;

            this.isProcessing = false;
            this.updateProgress(100, hozioImageOptimizerData.strings.complete);

            // Send browser notification
            this.sendNotification();

            // Calculate totals
            var successCount = this.runningSuccessCount;
            var totalSaved = this.runningTotalSaved;
            var savedFormatted = this.formatBytes(totalSaved);
            var errorCount = this.runningErrorCount;

            // Show results modal with detailed breakdown
            var html = '<div class="results-summary">';
            html += '<div class="results-hero">';
            html += '<div class="big-number success-color">' + successCount + '</div>';
            html += '<div class="stat-label">Images Optimized</div>';
            html += '</div>';
            html += '<div class="results-grid">';
            html += '<div class="result-item"><span class="dashicons dashicons-performance"></span><strong>' + savedFormatted + '</strong><span>Space Saved</span></div>';
            html += '<div class="result-item"><span class="dashicons dashicons-warning"></span><strong>' + errorCount + '</strong><span>Errors</span></div>';
            html += '</div>';

            // Show individual results
            if (this.results.length > 0) {
                html += '<div class="results-details">';
                html += '<h4>Details</h4>';
                html += '<div class="results-list">';

                this.results.slice(0, 20).forEach(function(result) {
                    var statusClass = result.success ? 'success' : 'error';
                    var statusIcon = result.success ? 'yes-alt' : 'dismiss';
                    var detail = result.success ?
                        (result.data.savings_percent > 0 ? '-' + result.data.savings_percent + '%' : 'Optimized') :
                        result.error;

                    html += '<div class="result-row ' + statusClass + '">';
                    html += '<span class="dashicons dashicons-' + statusIcon + '"></span>';
                    html += '<span class="result-filename">' + (result.data ? result.data.new_filename || result.data.original_filename : 'Image') + '</span>';
                    html += '<span class="result-detail">' + detail + '</span>';
                    html += '</div>';
                });

                if (this.results.length > 20) {
                    html += '<p class="more-results">+ ' + (this.results.length - 20) + ' more images</p>';
                }

                html += '</div>';
                html += '</div>';
            }

            html += '</div>';

            $('#results-content').html(html);
            $('#results-modal').show();

            // Update page stats
            var currentOptimized = parseInt($('#stat-optimized').text()) || 0;
            var currentSaved = $('#stat-saved').text();
            $('#stat-optimized').text(currentOptimized + successCount);

            // Reset UI
            setTimeout(function() {
                $('#progress-panel').hide();
                $('#optimize-selected-btn, #preview-btn').prop('disabled', false);
                self.selectedImages = [];
                self.allImages = [];
                self.currentPage = 1;
                self.loadImages(true);
            }, 1000);
        },

        // Format bytes to human readable
        formatBytes: function(bytes) {
            if (bytes === 0) return '0 B';
            if (bytes < 0) return '-' + this.formatBytes(-bytes);
            var k = 1024;
            var sizes = ['B', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        },

        // Select ALL images (not just loaded ones)
        selectAllImages: function() {
            var self = this;
            var $btn = $('#select-all-btn');

            // Show loading state
            $btn.prop('disabled', true).text('Loading...');

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_get_all_image_ids',
                nonce: hozioImageOptimizerData.nonce,
                search: $('#search-input').val(),
                filter: $('#filter-type').val(),
                status_filter: $('#filter-status').val()
            }, function(response) {
                $btn.prop('disabled', false).text('Select All');

                if (response.success && response.data.ids) {
                    self.selectedImages = response.data.ids.map(function(id) {
                        return id.toString();
                    });

                    // Update checkboxes for visible images
                    $('.hozio-image-card .card-checkbox').prop('checked', true);
                    $('.hozio-image-card').addClass('selected');

                    self.updateSelectionUI();

                    // Show count if more than visible
                    if (self.selectedImages.length > self.allImages.length) {
                        $('#selected-count-badge').text(self.selectedImages.length);
                    }
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Select All');
                alert('Failed to select all images. Please try again.');
            });
        },

        // Select all unoptimized images (not just loaded ones)
        selectUnoptimized: function() {
            var self = this;
            var $btn = $('#select-unoptimized-btn');

            // Show loading state
            $btn.prop('disabled', true);
            var originalText = $btn.html();
            $btn.html('<span class="dashicons dashicons-update spin"></span> Loading...');

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_get_all_image_ids',
                nonce: hozioImageOptimizerData.nonce,
                search: $('#search-input').val(),
                filter: $('#filter-type').val(),
                status_filter: $('#filter-status').val(),
                only_unoptimized: 'true'
            }, function(response) {
                $btn.prop('disabled', false).html(originalText);

                if (response.success && response.data.ids) {
                    self.selectedImages = response.data.ids.map(function(id) {
                        return id.toString();
                    });

                    // Update checkboxes for visible images
                    $('.hozio-image-card').each(function() {
                        var card = $(this);
                        var id = card.data('id').toString();
                        var isSelected = self.selectedImages.indexOf(id) !== -1;
                        card.toggleClass('selected', isSelected);
                        card.find('.card-checkbox').prop('checked', isSelected);
                    });

                    self.updateSelectionUI();

                    // Show feedback
                    var count = self.selectedImages.length;
                    if (count === 0) {
                        alert('All images are already optimized!');
                    } else if (count > self.allImages.length) {
                        // More selected than visible
                        var hiddenCount = count - self.allImages.filter(function(img) {
                            return self.selectedImages.indexOf(img.id.toString()) !== -1;
                        }).length;
                        if (hiddenCount > 0) {
                            // Some selected are not loaded yet, that's fine
                        }
                    }
                }
            }).fail(function() {
                $btn.prop('disabled', false).html(originalText);
                alert('Failed to select unoptimized images. Please try again.');
            });
        },

        // Deselect all images
        deselectAll: function() {
            this.selectedImages = [];
            this.lastClickedIndex = -1;

            $('.hozio-image-card').each(function() {
                $(this).removeClass('selected');
                $(this).find('.card-checkbox').prop('checked', false);
            });

            this.updateSelectionUI();
        },

        // Select Recommended - all unoptimized images over 100KB across entire library
        selectRecommended: function() {
            var self = this;
            var $btn = $('#select-recommended-btn');

            $btn.prop('disabled', true);
            var originalText = $btn.html();
            $btn.html('<span class="dashicons dashicons-update spin"></span> Scanning...');

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_get_recommended_ids',
                nonce: hozioImageOptimizerData.nonce,
                threshold_kb: 100
            }, function(response) {
                $btn.prop('disabled', false).html(originalText);

                if (response.success && response.data.ids) {
                    var count = response.data.total;
                    var sizeFormatted = response.data.total_size_formatted;

                    if (count === 0) {
                        alert('No unoptimized images over 100 KB found. Your library looks good!');
                        return;
                    }

                    // Confirm with user
                    if (!confirm('Found ' + count + ' unoptimized images over 100 KB (' + sizeFormatted + ' total).\n\nThis includes images across your entire media library, not just what\'s currently loaded.\n\nSelect all ' + count + ' images?')) {
                        return;
                    }

                    self.selectedImages = response.data.ids.map(function(id) {
                        return id.toString();
                    });

                    // Update visible checkboxes
                    $('.hozio-image-card').each(function() {
                        var card = $(this);
                        var id = card.data('id').toString();
                        var isSelected = self.selectedImages.indexOf(id) !== -1;
                        card.toggleClass('selected', isSelected);
                        card.find('.card-checkbox').prop('checked', isSelected);
                    });

                    self.updateSelectionUI();
                }
            }).fail(function() {
                $btn.prop('disabled', false).html(originalText);
                alert('Failed to scan for recommended images.');
            });
        },

        // Reset optimization statistics
        resetStats: function() {
            if (!confirm('Are you sure you want to reset all optimization statistics? This cannot be undone.')) {
                return;
            }

            var $btn = $('#reset-stats-btn');
            $btn.prop('disabled', true);

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_reset_usage_stats',
                nonce: hozioImageOptimizerData.nonce
            }, function(response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    // Update the stats display
                    $('#stat-optimized').text('0');
                    $('#stat-saved').text('0 B');

                    // Update the progress bar
                    var $progressFill = $('.stat-progress-mini .progress-fill');
                    var $progressText = $('.stat-progress-mini .progress-text');
                    if ($progressFill.length) {
                        $progressFill.css('width', '0%');
                    }
                    if ($progressText.length) {
                        $progressText.text('0%');
                    }

                    // Show success feedback
                    alert('Statistics have been reset successfully.');
                } else {
                    alert('Failed to reset statistics: ' + (response.data.message || 'Unknown error'));
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                alert('Failed to reset statistics. Please try again.');
            });
        },

        // Toggle backup setting (syncs with Settings page)
        toggleBackupSetting: function(enabled) {
            var $checkbox = $('#opt-backup');
            $checkbox.prop('disabled', true);

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_toggle_backup',
                nonce: hozioImageOptimizerData.nonce,
                enabled: enabled ? 'true' : 'false'
            }, function(response) {
                $checkbox.prop('disabled', false);

                if (!response.success) {
                    // Revert checkbox if failed
                    $checkbox.prop('checked', !enabled);
                    alert('Failed to update backup setting');
                }
            }).fail(function() {
                $checkbox.prop('disabled', false);
                $checkbox.prop('checked', !enabled);
                alert('Failed to update backup setting. Please try again.');
            });
        },

        // ==================== BACKGROUND QUEUE FUNCTIONS ====================

        // Check for an existing background queue on page load
        checkBackgroundQueue: function() {
            var self = this;

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_get_queue_status',
                nonce: hozioImageOptimizerData.nonce
            }, function(response) {
                if (response.success && response.data.active) {
                    // Only resume if actually running or paused
                    // Don't show UI for completed/cancelled queues found on page load
                    if (response.data.state === 'completed' || response.data.state === 'cancelled') {
                        console.log('Hozio: Found old ' + response.data.state + ' queue on page load, clearing it');
                        // Clear the stale queue data
                        $.post(hozioImageOptimizerData.ajaxUrl, {
                            action: 'hozio_cancel_queue',
                            nonce: hozioImageOptimizerData.nonce
                        });
                        return;
                    }

                    self.backgroundQueue.active = true;
                    self.backgroundQueue.state = response.data.state;
                    self.backgroundQueue.startedAt = Date.now(); // Track when we resumed
                    self.backgroundQueue.completionShown = false; // Reset for resumed queue
                    self.backgroundQueue.sessionId = 'resumed_' + Date.now();
                    self.showBackgroundQueueUI(response.data);

                    if (response.data.state === 'running') {
                        // Immediately trigger processing and start polling
                        self.pollQueueStatus();
                        self.startQueuePolling();
                    }
                }
            });
        },

        // Start background optimization
        startBackgroundOptimization: function() {
            var self = this;

            if (this.selectedImages.length === 0) {
                alert('Please select images to optimize.');
                return;
            }

            // Collect options
            var options = {
                compress: $('#opt-compress').is(':checked'),
                convert_webp: $('#opt-convert').is(':checked'),  // Use correct selector
                convert_avif: false,  // No separate AVIF checkbox currently
                ai_rename: $('#opt-rename').is(':checked'),
                ai_alt: $('#opt-rename').is(':checked'), // Same as rename for now
                location: $('#location-input').val(),
                keyword: $('#keyword-input').val(),
                manual_lat: this.currentCoords ? this.currentCoords.lat : '',
                manual_lng: this.currentCoords ? this.currentCoords.lng : '',
                skip_optimized: $('#opt-skip-optimized').is(':checked'),
                force_reoptimize: $('#opt-force-reoptimize').is(':checked'),
                create_backup: $('#opt-backup').is(':checked'),
                target_filesize: $('#target-filesize').val() || 0
            };

            // Disable the optimize button while optimization is running
            var $optimizeBtn = $('#optimize-selected-btn');
            var originalBtnHtml = $optimizeBtn.html();
            $optimizeBtn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Optimizing...');

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_start_background_queue',
                nonce: hozioImageOptimizerData.nonce,
                image_ids: this.selectedImages,
                compress: options.compress ? 'true' : 'false',
                convert_webp: options.convert_webp ? 'true' : 'false',
                convert_avif: options.convert_avif ? 'true' : 'false',
                ai_rename: options.ai_rename ? 'true' : 'false',
                ai_alt: options.ai_alt ? 'true' : 'false',
                location: options.location,
                keyword: options.keyword,
                manual_lat: options.manual_lat,
                manual_lng: options.manual_lng,
                skip_optimized: options.skip_optimized ? 'true' : 'false',
                force_reoptimize: options.force_reoptimize ? 'true' : 'false',
                target_filesize: options.target_filesize,
                create_backup: options.create_backup ? 'true' : 'false'
            }, function(response) {
                if (response.success) {
                    var data = response.data;

                    // Re-enable button only if skipped (no actual processing)
                    // Otherwise keep disabled until queue completes

                    // Check if all images were skipped (already optimized)
                    // Handle both cases: direct skipped response or state=completed with total=0
                    if ((data.total === 0 && data.skipped > 0) || (data.state === 'completed' && data.total === 0)) {
                        var skippedCount = data.skipped || 1;
                        self.showSkippedNotification(skippedCount);
                        $optimizeBtn.prop('disabled', false).html(originalBtnHtml);
                        return;
                    }

                    // Check if there's a message (partial skip)
                    if (data.skipped > 0) {
                        // Some images were skipped, show notification
                        console.log('Skipped ' + data.skipped + ' already-optimized images');
                    }

                    self.backgroundQueue.active = true;
                    self.backgroundQueue.state = 'running';
                    self.backgroundQueue.completionShown = false; // Reset for new optimization
                    self.backgroundQueue.sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    self.backgroundQueue.startedAt = Date.now(); // Track when optimization started
                    self.backgroundQueue.expectedTotal = data.total; // Track expected total for validation
                    window.hozioModalShowing = false; // Reset global modal lock for new optimization
                    console.log('Hozio: Started new optimization session:', self.backgroundQueue.sessionId, 'total:', data.total);

                    // Now show the queue UI since we have actual images to process
                    self.showBackgroundQueueUI({
                        state: 'running',
                        total: data.total,
                        completed: 0,
                        bytes_saved_formatted: '0 B'
                    });

                    // Mark ALL selected cards as "queued" for visual feedback, first one as "processing"
                    self.selectedImages.forEach(function(id, index) {
                        var $card = $('.hozio-image-card[data-id="' + id + '"]');
                        if (index === 0) {
                            $card.addClass('bg-processing');
                        } else {
                            $card.addClass('bg-queued');
                        }
                    });
                    self.backgroundQueue.lastSeenImageId = null;

                    // Small delay before first poll to ensure server has saved the queue
                    setTimeout(function() {
                        // Immediately trigger first batch processing
                        self.pollQueueStatus();

                        // Then start regular polling
                        self.startQueuePolling();
                    }, 500);

                    // Clear selection
                    self.selectedImages = [];
                    self.updateSelectionUI();
                } else {
                    $optimizeBtn.prop('disabled', false).html(originalBtnHtml);
                    alert('Failed to start optimization: ' + (response.data.message || 'Unknown error'));
                }
            }).fail(function() {
                $optimizeBtn.prop('disabled', false).html(originalBtnHtml);
                alert('Failed to start optimization. Please try again.');
            });
        },

        // Start polling for queue status
        startQueuePolling: function() {
            var self = this;

            // Clear any existing interval
            if (this.backgroundQueue.pollInterval) {
                clearInterval(this.backgroundQueue.pollInterval);
            }

            // Poll every 3 seconds
            this.backgroundQueue.pollInterval = setInterval(function() {
                self.pollQueueStatus();
            }, 3000);
        },

        // Stop polling
        stopQueuePolling: function() {
            if (this.backgroundQueue.pollInterval) {
                clearInterval(this.backgroundQueue.pollInterval);
                this.backgroundQueue.pollInterval = null;
            }
        },

        // Poll for queue status and trigger processing
        pollQueueStatus: function() {
            var self = this;

            // Prevent overlapping requests
            if (this._pollInFlight) return;
            this._pollInFlight = true;

            // Call process_queue_batch to actually process images while page is open
            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_process_queue_batch',
                nonce: hozioImageOptimizerData.nonce
            }, function(response) {
                self._pollInFlight = false;

                if (response.success && response.data.active) {
                    self.updateBackgroundQueueUI(response.data);

                    if (response.data.state === 'completed') {
                        self.stopQueuePolling();
                        var data = response.data;
                        var isValidCompletion = data.total > 0 && data.completed >= data.total;

                        if (isValidCompletion) {
                            self.onQueueComplete(data);
                        } else {
                            // Invalid completion: DON'T hide UI - retry once via status check
                            console.log('Hozio: Ambiguous completion, retrying status check');
                            setTimeout(function() { self.checkQueueStatusOnly(); }, 2000);
                        }
                    } else if (response.data.state === 'cancelled') {
                        self.stopQueuePolling();
                        self.hideBackgroundQueueUI();
                    } else if (response.data.state === 'paused') {
                        self.stopQueuePolling();
                    }
                    // If state is 'running', do nothing - let polling continue
                } else if (response.success && response.data.processed === false) {
                    // Queue not running: increment a miss counter instead of immediately hiding
                    self._pollMissCount = (self._pollMissCount || 0) + 1;
                    if (self._pollMissCount >= 3) {
                        // Only hide after 3 consecutive misses (9 seconds of no activity)
                        self.stopQueuePolling();
                        self.hideBackgroundQueueUI();
                        self._pollMissCount = 0;
                    }
                } else {
                    // Reset miss counter on any other response
                    self._pollMissCount = 0;
                }
            }).fail(function() {
                self._pollInFlight = false;
                // On error, don't hide - just fall back to status check
                self.checkQueueStatusOnly();
            });
        },

        // Check queue status without processing (fallback)
        checkQueueStatusOnly: function() {
            var self = this;

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_get_queue_status',
                nonce: hozioImageOptimizerData.nonce
            }, function(response) {
                if (response.success && response.data.active) {
                    self.updateBackgroundQueueUI(response.data);

                    if (response.data.state === 'completed') {
                        self.stopQueuePolling();
                        var data = response.data;
                        var isValidCompletion = data.total > 0 && data.completed >= data.total;

                        if (isValidCompletion) {
                            self.onQueueComplete(data);
                        } else {
                            // Don't hide on ambiguous completion - just log and leave UI visible
                            console.log('Hozio: Ambiguous completion state (fallback), leaving UI visible', data);
                        }
                    } else if (response.data.state === 'cancelled') {
                        self.stopQueuePolling();
                        self.hideBackgroundQueueUI();
                    } else if (response.data.state === 'paused') {
                        self.stopQueuePolling();
                    } else if (response.data.state === 'running') {
                        // Queue still running - resume polling if we stopped
                        if (!self.backgroundQueue.pollInterval) {
                            self.startQueuePolling();
                        }
                    }
                } else {
                    // Only hide after confirming no active queue
                    self._statusMissCount = (self._statusMissCount || 0) + 1;
                    if (self._statusMissCount >= 2) {
                        self.stopQueuePolling();
                        self.hideBackgroundQueueUI();
                        self._statusMissCount = 0;
                    }
                }
            }).fail(function() {
                // Network error: don't hide, just let next poll retry
                console.log('Hozio: Status check failed, will retry');
            });
        },

        // Show background queue UI
        showBackgroundQueueUI: function(data) {
            var $container = $('#background-queue-status');

            if ($container.length === 0) {
                var html = '<div id="background-queue-status" class="queue-panel">';

                // Header
                html += '<div class="queue-header">';
                html += '<div class="queue-header-left">';
                html += '<div class="queue-status-dot running"></div>';
                html += '<div><div class="queue-title">Optimizing</div>';
                html += '<div class="queue-subtitle">Starting...</div></div>';
                html += '</div>';
                html += '<span class="queue-state-badge running">Running</span>';
                html += '</div>';

                // Progress bar
                html += '<div class="queue-progress">';
                html += '<div class="queue-progress-bar"><div class="queue-progress-fill" style="width:0%"></div></div>';
                html += '<div class="queue-progress-meta">';
                html += '<span><strong class="completed">0</strong>/<strong class="total">0</strong></span>';
                html += '<span class="queue-progress-percent">0%</span>';
                html += '</div>';
                html += '</div>';

                // Current file being processed
                html += '<div class="queue-current">';
                html += '<div class="queue-current-label">Processing:</div>';
                html += '<div class="queue-current-file">';
                html += '<span class="dashicons dashicons-update spin"></span>';
                html += '<span class="queue-current-filename">Starting...</span>';
                html += '</div>';
                html += '</div>';

                // Stats
                html += '<div class="queue-stats">';
                html += '<div class="queue-stat"><span class="queue-stat-value bytes-value">0 B</span><span class="queue-stat-label">Saved</span></div>';
                html += '<div class="queue-stat"><span class="queue-stat-value success-count">0</span><span class="queue-stat-label">Done</span></div>';
                html += '<div class="queue-stat"><span class="queue-stat-value error-count">0</span><span class="queue-stat-label">Errors</span></div>';
                html += '</div>';

                // Actions - ALL buttons live in DOM, toggled by state
                html += '<div class="queue-actions">';
                html += '<button type="button" class="queue-btn queue-pause-btn"><span class="dashicons dashicons-controls-pause"></span> Pause</button>';
                html += '<button type="button" class="queue-btn queue-resume-btn" style="display:none"><span class="dashicons dashicons-controls-play"></span> Resume</button>';
                html += '<button type="button" class="queue-btn queue-cancel-btn"><span class="dashicons dashicons-no-alt"></span></button>';
                html += '<button type="button" class="queue-btn queue-optimize-more-btn" style="display:none">Optimize More</button>';
                html += '<button type="button" class="queue-btn queue-done-btn" style="display:none">Dismiss</button>';
                html += '</div>';
                html += '<p class="queue-bg-hint" style="margin:8px 0 0;font-size:10px;color:#9ca3af;"><span class="dashicons dashicons-info-outline" style="font-size:12px;width:12px;height:12px;margin-right:3px;"></span>You can leave this page — optimization continues in the background.</p>';

                html += '</div>';

                $('.hozio-options-panel').before(html);

                var self = this;
                $('#background-queue-status .queue-pause-btn').on('click', function() { self.pauseBackgroundQueue(); });
                $('#background-queue-status .queue-resume-btn').on('click', function() { self.resumeBackgroundQueue(); });
                $('#background-queue-status .queue-cancel-btn').on('click', function() {
                    if (confirm('Are you sure you want to cancel the optimization?')) { self.cancelBackgroundQueue(); }
                });
                $('#background-queue-status .queue-done-btn').on('click', function() { self.dismissBackgroundQueue(); });
                $('#background-queue-status .queue-optimize-more-btn').on('click', function() {
                    $.post(hozioImageOptimizerData.ajaxUrl, { action: 'hozio_clear_queue', nonce: hozioImageOptimizerData.nonce });
                    self.backgroundQueue.active = false;
                    self.backgroundQueue.state = null;
                    self.backgroundQueue.completionShown = false;
                    self.checkLocationAndOptimize();
                });
            }

            this.updateBackgroundQueueUI(data);
            $('#background-queue-status').show();
        },

        // Update background queue UI
        updateBackgroundQueueUI: function(data) {
            var $container = $('#background-queue-status');
            if ($container.length === 0) return;

            var progress = data.total > 0 ? Math.round((data.completed / data.total) * 100) : 0;

            // Update progress bar
            $container.find('.queue-progress-fill').css('width', progress + '%');
            $container.find('.completed').text(data.completed);
            $container.find('.total').text(data.total);
            $container.find('.queue-progress-percent').text(progress + '%');

            // Update stats with pulse animation
            var self = this;
            var newBytes = data.bytes_saved_formatted || '0 B';
            var newSuccess = data.completed - (data.errors || 0);
            var $bytesEl = $container.find('.bytes-value');
            var $successEl = $container.find('.success-count');
            var $errorEl = $container.find('.error-count');

            if ($bytesEl.text() !== newBytes) {
                $bytesEl.text(newBytes).addClass('stat-updated');
                setTimeout(function() { $bytesEl.removeClass('stat-updated'); }, 400);
            }
            if (parseInt($successEl.text()) !== newSuccess) {
                $successEl.text(newSuccess).addClass('stat-updated');
                setTimeout(function() { $successEl.removeClass('stat-updated'); }, 400);
            }
            $errorEl.text(data.errors || 0);

            // Update current filename in the panel
            if (data.current_filename && data.state === 'running') {
                $container.find('.queue-current-filename').text(data.current_filename);
                $container.find('.queue-current').show();
            }

            // Update card processing indicators
            if (data.state === 'running' && data.current_image_id) {
                var currentId = data.current_image_id.toString();
                var checkSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

                // Mark ALL cards that had bg-processing but aren't the current one as completed
                $('.hozio-image-card.bg-processing').each(function() {
                    var cardId = $(this).data('id').toString();
                    if (cardId !== currentId) {
                        $(this).removeClass('bg-processing bg-queued').addClass('completed optimized just-completed');
                        $(this).find('.card-status-badge').removeClass('not-optimized').addClass('optimized').html(checkSvg);
                        $(this).find('.card-process-status').removeClass('pending').addClass('done').html(checkSvg + ' Optimized');
                        var $c = $(this);
                        setTimeout(function() { $c.removeClass('just-completed'); }, 700);
                    }
                });

                // Ensure current card has bg-processing (upgrade from queued/paused)
                var $currentCard = $('.hozio-image-card[data-id="' + currentId + '"]');
                if (!$currentCard.hasClass('bg-processing') && !$currentCard.hasClass('completed')) {
                    $currentCard.removeClass('bg-queued bg-paused').addClass('bg-processing');
                }

                this.backgroundQueue.lastSeenImageId = currentId;
            } else if (data.state === 'paused') {
                $('.hozio-image-card.bg-processing').removeClass('bg-processing').addClass('bg-paused');
            } else if (data.state === 'completed' || data.state === 'cancelled') {
                $('.hozio-image-card').removeClass('bg-processing bg-queued bg-paused');
            }

            // Update filename if it changed (AI rename)
            if (data.current_image_id && data.current_filename) {
                $('.hozio-image-card[data-id="' + data.current_image_id + '"]').find('.card-title').text(data.current_filename);
            }
            if (data.current_image_id) {
                this.backgroundQueue.lastProcessedId = data.current_image_id;
            }

            // Update state
            var $dot = $container.find('.queue-status-dot');
            var $badge = $container.find('.queue-state-badge');
            var $subtitle = $container.find('.queue-subtitle');

            // Hide ALL action buttons first, then show only what's needed for this state
            $container.find('.queue-pause-btn, .queue-resume-btn, .queue-cancel-btn, .queue-optimize-more-btn, .queue-done-btn').hide();

            switch (data.state) {
                case 'running':
                    $dot.removeClass('paused completed').addClass('running');
                    $badge.removeClass('paused completed').addClass('running').text('Running');
                    var currentNum = Math.min(data.completed + 1, data.total);
                    $subtitle.text('Image ' + currentNum + ' of ' + data.total);
                    $container.find('.queue-pause-btn').show();
                    $container.find('.queue-cancel-btn').show();
                    $container.find('.queue-bg-hint').show();
                    // Resume paused cards back to processing
                    $('.hozio-image-card.bg-paused').removeClass('bg-paused').addClass('bg-processing');
                    break;
                case 'paused':
                    $dot.removeClass('running completed').addClass('paused');
                    $badge.removeClass('running completed').addClass('paused').text('Paused');
                    $subtitle.text('Paused at ' + data.completed + '/' + data.total);
                    $container.find('.queue-resume-btn').show();
                    $container.find('.queue-cancel-btn').show();
                    $container.find('.queue-current .dashicons').removeClass('spin');
                    break;
                case 'completed':
                    $dot.removeClass('running paused').addClass('completed');
                    $badge.removeClass('running paused').addClass('completed').text('Done');
                    $subtitle.text('All ' + data.total + ' images processed');
                    $container.find('.queue-done-btn').show();
                    $container.find('.queue-bg-hint').hide();
                    $container.find('.queue-done-btn').show();
                    $container.find('.queue-current').hide();
                    $('.hozio-image-card').removeClass('bg-processing');
                    // Mark last card
                    if (this.backgroundQueue.lastProcessedId) {
                        var $lastCard = $('.hozio-image-card[data-id="' + this.backgroundQueue.lastProcessedId + '"]');
                        $lastCard.removeClass('unoptimized').addClass('completed optimized');
                        $lastCard.find('.card-status-badge').removeClass('not-optimized').addClass('optimized')
                            .html('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>');
                        $lastCard.find('.card-process-status').removeClass('pending').addClass('done')
                            .html('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Optimized');
                    }
                    break;
                case 'starting':
                    $dot.addClass('running');
                    $badge.addClass('running').text('Starting');
                    $subtitle.text('Initializing...');
                    break;
            }
        },

        // Hide background queue UI
        hideBackgroundQueueUI: function() {
            var $container = $('#background-queue-status');
            if ($container.length) {
                $container.slideUp(function() {
                    $(this).remove();
                });
            }
            this.backgroundQueue.active = false;
            this.backgroundQueue.state = null;
        },

        // Transition queue UI to completed/idle state (instead of hiding)
        showQueueCompletedState: function(data) {
            var $container = $('#background-queue-status');
            if ($container.length === 0) return;

            var successCount = (data.completed || 0) - (data.errors || 0);
            var savedFormatted = data.bytes_saved_formatted || '0 B';

            $container.addClass('queue-completed');
            $container.find('.queue-status-dot').removeClass('running paused').addClass('completed');
            $container.find('.queue-title').text('Complete');
            $container.find('.queue-subtitle').text('Saved ' + savedFormatted + ' across ' + successCount + ' images');
            $container.find('.queue-state-badge').removeClass('running paused').addClass('completed').text('Done');
            $container.find('.queue-progress-fill').css('width', '100%');
            $container.find('.queue-progress-percent').text('100%');
            $container.find('.queue-current').hide();
            $container.find('.queue-bg-hint').hide();

            // Toggle buttons: hide running buttons, show completed buttons
            $container.find('.queue-pause-btn, .queue-resume-btn, .queue-cancel-btn').hide();
            $container.find('.queue-done-btn').show();

            // Show "Optimize More" if images are selected
            if (this.selectedImages.length > 0) {
                $container.find('.queue-optimize-more-btn').text('Optimize ' + this.selectedImages.length + ' More').show();
            } else {
                $container.find('.queue-optimize-more-btn').hide();
            }

            // Clear processing/queued indicators and mark last card as completed
            var lastId = this.backgroundQueue.lastSeenImageId;
            if (lastId) {
                var $lastCard = $('.hozio-image-card[data-id="' + lastId + '"]');
                $lastCard.removeClass('bg-processing bg-queued').addClass('completed optimized just-completed');
                $lastCard.find('.card-status-badge').removeClass('not-optimized').addClass('optimized')
                    .html('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>');
                $lastCard.find('.card-process-status').removeClass('pending').addClass('done')
                    .html('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Optimized');
                setTimeout(function() { $lastCard.removeClass('just-completed'); }, 700);
            }
            $('.hozio-image-card').removeClass('bg-processing bg-queued bg-paused processing');

            // Re-enable the optimize button and update based on current selections
            this.backgroundQueue.state = 'completed';
            $('#optimize-selected-btn').html('<span class="dashicons dashicons-image-rotate"></span> Optimize');
            this.updateSelectionUI();
        },

        // Dismiss and clear background queue
        dismissBackgroundQueue: function() {
            var self = this;

            // Clear the queue data on server
            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_clear_queue',
                nonce: hozioImageOptimizerData.nonce
            }, function(response) {
                self.hideBackgroundQueueUI();
            });
        },

        // Show notification when images are skipped (already optimized)
        showSkippedNotification: function(count) {
            // Remove any existing notification
            $('.hozio-skipped-notification').remove();

            var html = '<div class="hozio-skipped-notification">';
            html += '<div class="skipped-notification-content">';
            html += '<span class="dashicons dashicons-info-outline"></span>';
            html += '<div class="skipped-notification-text">';
            html += '<strong>' + count + ' image' + (count > 1 ? 's' : '') + ' already optimized</strong>';
            html += '<p>To re-optimize, enable <strong>"Force Re-optimization"</strong> in the sidebar options.</p>';
            html += '</div>';
            html += '<button type="button" class="skipped-notification-close">&times;</button>';
            html += '</div>';
            html += '</div>';

            // Insert at top of main content area
            $('.hozio-bulk-optimizer').prepend(html);

            // Auto-hide after 8 seconds
            setTimeout(function() {
                $('.hozio-skipped-notification').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 8000);

            // Close button
            $(document).on('click', '.skipped-notification-close', function() {
                $(this).closest('.hozio-skipped-notification').fadeOut(300, function() {
                    $(this).remove();
                });
            });
        },

        // Queue completed
        onQueueComplete: function(data) {
            var self = this;

            // Always mark queue as completed regardless of guards
            this.backgroundQueue.state = 'completed';

            // PRIMARY GUARD: Check if we've already shown completion for this session
            if (this.backgroundQueue.completionShown === true) {
                console.log('Hozio: BLOCKED - Completion already shown for this session');
                // Still update the button even if modal is blocked
                this.updateSelectionUI();
                return;
            }

            // SECONDARY GUARD: Global window lock for extra protection
            if (window.hozioModalShowing === true) {
                console.log('Hozio: BLOCKED - Global modal lock active');
                this.updateSelectionUI();
                return;
            }

            // Guard: Only show modal if we have valid data
            if (!data || typeof data.total === 'undefined') {
                console.log('Hozio: Invalid completion data, skipping');
                this.updateSelectionUI();
                return;
            }

            // SET BOTH LOCKS IMMEDIATELY after guards pass
            this.backgroundQueue.completionShown = true;
            window.hozioModalShowing = true;

            console.log('Hozio: Showing completion modal', {
                sessionId: this.backgroundQueue.sessionId,
                total: data.total,
                completed: data.completed,
                queueLength: data.queue ? data.queue.length : 0,
                bytesSaved: data.bytes_saved
            });

            // Set running totals for notification
            this.runningSuccessCount = data.completed - (data.errors || 0);
            this.runningErrorCount = data.errors || 0;
            this.runningTotalSaved = data.bytes_saved || 0;

            // Send browser notification
            this.sendNotification();

            // Transition queue panel to completed state (don't hide it)
            this.showQueueCompletedState(data);

            // Show results modal
            this.showBackgroundResultsModal(data);

            // Refresh the image list
            this.loadImages(true);

            // Release window lock after 30 seconds as a safety fallback
            // (session lock stays until new optimization starts)
            setTimeout(function() {
                window.hozioModalShowing = false;
            }, 30000);
        },

        // Show results modal for background queue completion
        showBackgroundResultsModal: function(data) {
            var self = this;
            var successCount = data.completed - (data.errors || 0);
            var errorCount = data.errors || 0;
            var savedBytes = data.bytes_saved || 0;
            var savedFormatted = data.bytes_saved_formatted || this.formatBytes(savedBytes);

            var html = '<div class="rm-container">';

            // Header with checkmark
            html += '<div class="rm-header">';
            html += '<div class="rm-check"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>';
            html += '<div class="rm-header-text">Optimization Complete</div>';
            html += '</div>';

            // Summary stats row
            html += '<div class="rm-stats">';
            html += '<div class="rm-stat"><div class="rm-stat-val rm-stat-success">' + successCount + '</div><div class="rm-stat-lbl">Optimized</div></div>';
            html += '<div class="rm-stat"><div class="rm-stat-val rm-stat-saved">' + savedFormatted + '</div><div class="rm-stat-lbl">Space Saved</div></div>';
            if (errorCount > 0) {
                html += '<div class="rm-stat"><div class="rm-stat-val rm-stat-error">' + errorCount + '</div><div class="rm-stat-lbl">Errors</div></div>';
            }
            html += '</div>';

            // Per-image details
            if (data.queue && data.queue.length > 0) {
                html += '<div class="rm-details">';
                html += '<div class="rm-details-header">Image Details</div>';

                data.queue.slice(0, 30).forEach(function(item) {
                    var filename = item.filename || ('Image #' + item.id);
                    var isError = item.status === 'error';
                    var isSkipped = !!item.skipped;
                    var hasResult = item.result && !isSkipped && !isError;

                    html += '<div class="rm-row ' + (isError ? 'rm-error' : isSkipped ? 'rm-skipped' : 'rm-success') + '">';

                    // Thumbnail
                    html += '<div class="rm-thumb">';
                    if (item.thumbnail) {
                        html += '<img src="' + item.thumbnail + '" alt="">';
                    } else {
                        html += '<span class="dashicons dashicons-format-image"></span>';
                    }
                    html += '</div>';

                    // Info
                    html += '<div class="rm-info">';
                    html += '<div class="rm-filename">' + filename + '</div>';

                    if (isError) {
                        html += '<div class="rm-error-msg">' + (item.error || 'Error') + '</div>';
                    } else if (isSkipped) {
                        html += '<div class="rm-skip-msg">' + (item.result && item.result.detail ? item.result.detail : 'Already optimized') + '</div>';
                    } else if (hasResult) {
                        var origSize = item.result.original_size || 0;
                        var newSize = item.result.new_size || origSize;
                        var itemSaved = item.result.saved || 0;
                        var percent = origSize > 0 ? Math.round((itemSaved / origSize) * 100 * 10) / 10 : 0;

                        html += '<div class="rm-sizes">';
                        html += self.formatBytes(origSize) + ' → ' + self.formatBytes(newSize);
                        if (itemSaved > 0) {
                            html += ' <span class="rm-savings-badge">-' + percent + '%</span>';
                        }
                        html += '</div>';

                        // Operations performed
                        if (item.result.operations && item.result.operations.length > 0) {
                            html += '<div class="rm-ops">';
                            item.result.operations.forEach(function(op) {
                                html += '<span class="rm-op-tag">' + op + '</span>';
                            });
                            html += '</div>';
                        }
                    }

                    html += '</div>';

                    // Status icon
                    html += '<div class="rm-status">';
                    if (isError) {
                        html += '<span class="dashicons dashicons-dismiss" style="color:#ef4444"></span>';
                    } else if (isSkipped) {
                        html += '<span class="dashicons dashicons-minus" style="color:#94a3b8"></span>';
                    } else {
                        html += '<span class="dashicons dashicons-yes-alt" style="color:#22c55e"></span>';
                    }
                    html += '</div>';

                    html += '</div>'; // .rm-row
                });

                if (data.queue.length > 30) {
                    html += '<div class="rm-more">+ ' + (data.queue.length - 30) + ' more images</div>';
                }

                html += '</div>'; // .rm-details
            }

            html += '</div>'; // .rm-container

            $('#results-content').html(html);
            $('#results-modal').show();

            // Update page stats
            var currentOptimized = parseInt($('#stat-optimized').text()) || 0;
            $('#stat-optimized').text(currentOptimized + successCount);
        },

        // Pause the background queue
        pauseBackgroundQueue: function() {
            var self = this;

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_pause_queue',
                nonce: hozioImageOptimizerData.nonce
            }, function(response) {
                if (response.success) {
                    self.stopQueuePolling();
                    self.backgroundQueue.state = 'paused';
                    self.updateBackgroundQueueUI({ state: 'paused', total: response.data.total, completed: response.data.completed, bytes_saved_formatted: self.formatBytes(response.data.bytes_saved || 0) });
                }
            });
        },

        // Resume the background queue
        resumeBackgroundQueue: function() {
            var self = this;

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_resume_queue',
                nonce: hozioImageOptimizerData.nonce
            }, function(response) {
                if (response.success) {
                    self.backgroundQueue.state = 'running';
                    self.startQueuePolling();
                    self.updateBackgroundQueueUI({ state: 'running', total: response.data.total, completed: response.data.completed, bytes_saved_formatted: self.formatBytes(response.data.bytes_saved || 0) });
                }
            });
        },

        // Cancel the background queue
        cancelBackgroundQueue: function() {
            var self = this;

            $.post(hozioImageOptimizerData.ajaxUrl, {
                action: 'hozio_cancel_queue',
                nonce: hozioImageOptimizerData.nonce
            }, function(response) {
                self.stopQueuePolling();
                self.hideBackgroundQueueUI();
                self.loadImages(true);
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        HozioBulkOptimizer.init();
    });

})(jQuery);
