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

        // -------------------------------------------------------------------
        // v2 settings page — only runs when .hz-v2 is present on the wrapper
        // -------------------------------------------------------------------
        var $v2 = $('.hozio-settings-page.hz-v2');
        if (!$v2.length) {
            return;
        }

        var TAB_TITLES = {};
        $v2.find('.hz-sidenav-item').each(function() {
            var id = $(this).data('tab');
            TAB_TITLES[id] = $(this).find('.hz-sidenav-label').text().trim();
        });

        // --- Tab switching (no reload) ---------------------------------------
        function activateTab(tabId, pushState) {
            var $target = $v2.find('.hz-tab-panel[data-tab="' + tabId + '"]');
            if (!$target.length) {
                return false;
            }
            $v2.find('.hz-tab-panel').removeClass('hz-active');
            $target.addClass('hz-active');
            $v2.find('.hz-sidenav-item').removeClass('active');
            $v2.find('.hz-sidenav-item[data-tab="' + tabId + '"]').addClass('active');

            if (pushState !== false && window.history && window.history.pushState) {
                var url = new URL(window.location.href);
                url.searchParams.set('tab', tabId);
                window.history.pushState({ hzTab: tabId }, '', url.toString());
            }

            return true;
        }

        $v2.on('click', '.hz-sidenav-item', function(e) {
            var tabId = $(this).data('tab');
            if (!tabId) {
                return;
            }
            e.preventDefault();
            activateTab(tabId, true);
        });

        window.addEventListener('popstate', function(e) {
            var tabId = (e.state && e.state.hzTab)
                ? e.state.hzTab
                : (new URL(window.location.href)).searchParams.get('tab') || 'api';
            activateTab(tabId, false);
        });

        // --- Live naming preview ---------------------------------------------
        var SAMPLE = {
            keyword: 'modern-kitchen',
            keywordTitle: 'Modern Kitchen',
            location: 'new-york',
            locationTitle: 'New York',
            timestamp: (function() {
                var d = new Date();
                return d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
            })(),
            random: Math.random().toString(36).slice(2, 7)
        };
        var localized = (typeof hozioImageOptimizer !== 'undefined') ? hozioImageOptimizer : {};
        var SITE_TITLE      = localized.siteTitle      || 'My Site';
        var SITE_TITLE_SLUG = localized.siteTitleSlug  || 'my-site';

        function interpolate(template, forTitle) {
            if (!template) {
                return '';
            }
            var sampleTitle = SAMPLE.keywordTitle + ' in ' + SAMPLE.locationTitle;
            var map = forTitle ? {
                '{keyword}':    SAMPLE.keywordTitle,
                '{location}':   SAMPLE.locationTitle,
                '{site_title}': SITE_TITLE,
                '{timestamp}':  SAMPLE.timestamp,
                '{random}':     SAMPLE.random,
                '{title}':      sampleTitle
            } : {
                '{keyword}':    SAMPLE.keyword,
                '{location}':   SAMPLE.location,
                '{site_title}': SITE_TITLE_SLUG,
                '{timestamp}':  SAMPLE.timestamp,
                '{random}':     SAMPLE.random,
                '{title}':      SAMPLE.keyword + '-' + SAMPLE.location
            };
            return template.replace(/\{keyword\}|\{location\}|\{site_title\}|\{timestamp\}|\{random\}|\{title\}/g, function(m) {
                return map[m] || '';
            });
        }

        function truncateFilename(base, maxLen) {
            if (!maxLen || base.length <= maxLen) {
                return base;
            }
            return base.slice(0, maxLen).replace(/-+$/, '');
        }

        function updateNamingPreview() {
            var $namingPanel = $v2.find('.hz-tab-panel[data-tab="naming"]');
            if (!$namingPanel.length) {
                return;
            }
            var filenameTpl = ($namingPanel.find('input[name="hozio_naming_template"]:checked').val()
                || $namingPanel.find('input[name="hozio_custom_naming_template"]').val()
                || '').trim();
            if (!filenameTpl) {
                filenameTpl = $('#hz-preview-filename-template').text().trim() || '{keyword}-{location}';
            }

            var titleTpl = ($namingPanel.find('input[name="hozio_title_template"]:checked').val()
                || $namingPanel.find('input[name="hozio_custom_title_template"]').val()
                || $namingPanel.find('input[name="hozio_title_template"][type="text"]').val()
                || '').trim();
            if (!titleTpl) {
                titleTpl = $('#hz-preview-title-template').text().trim() || 'Professional {keyword} in {location}';
            }

            var maxLen = parseInt($namingPanel.find('input[name="hozio_max_filename_length"]').val(), 10);
            if (isNaN(maxLen)) maxLen = 0;

            var filenameBase = interpolate(filenameTpl, false).toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
            filenameBase = truncateFilename(filenameBase, maxLen);

            $('#hz-preview-filename-template').text(filenameTpl);
            $('#hz-preview-filename-output').text(filenameBase + '.webp');
            $('#hz-preview-title-template').text(titleTpl);
            $('#hz-preview-title-output').text(interpolate(titleTpl, true));
        }

        var previewDebounce;
        $v2.on('input change',
            '.hz-tab-panel[data-tab="naming"] :input',
            function() {
                clearTimeout(previewDebounce);
                previewDebounce = setTimeout(updateNamingPreview, 80);
            }
        );
        updateNamingPreview();

        // --- Command palette --------------------------------------------------
        var paletteIndex = [];
        function buildPaletteIndex() {
            paletteIndex = [];
            $v2.find('.hz-tab-panel').each(function() {
                var $panel = $(this);
                var tabId = $panel.data('tab');
                var tabTitle = TAB_TITLES[tabId] || tabId;

                // Card headings
                $panel.find('.hozio-card-header h2').each(function() {
                    paletteIndex.push({
                        label: $(this).text().trim(),
                        tab: tabId,
                        tabLabel: tabTitle,
                        anchor: this,
                        icon: 'dashicons-admin-generic'
                    });
                });

                // Field labels
                $panel.find('.hozio-field-label').each(function() {
                    var label = $(this).clone().children('.required').remove().end().text().trim();
                    if (!label) return;
                    paletteIndex.push({
                        label: label,
                        tab: tabId,
                        tabLabel: tabTitle,
                        anchor: this,
                        icon: 'dashicons-admin-settings'
                    });
                });

                // Feature toggles
                $panel.find('.hozio-feature-toggle .feature-label').each(function() {
                    paletteIndex.push({
                        label: $(this).text().trim(),
                        tab: tabId,
                        tabLabel: tabTitle,
                        anchor: $(this).closest('.hozio-feature-toggle').get(0),
                        icon: 'dashicons-controls-repeat'
                    });
                });

                // Extra keywords
                $panel.find('[data-search-keywords]').each(function() {
                    paletteIndex.push({
                        label: $(this).data('search-keywords').toString(),
                        tab: tabId,
                        tabLabel: tabTitle,
                        anchor: this,
                        icon: 'dashicons-search'
                    });
                });
            });
        }

        var $palette      = $('#hz-palette');
        var $paletteInput = $('#hz-palette-input');
        var $paletteList  = $('#hz-palette-results');
        var paletteActive = 0;
        var paletteMatches = [];
        var paletteLastFocus = null;
        var paletteMoved = false;

        function openPalette() {
            if (!$palette.length) return;
            // Teleport to <body> once so position:fixed isn't clipped by WP containers
            if (!paletteMoved) {
                $palette.appendTo('body');
                paletteMoved = true;
            }
            if (!paletteIndex.length) buildPaletteIndex();
            paletteLastFocus = document.activeElement;
            $palette.removeAttr('hidden').addClass('hz-palette-open');
            $paletteInput.val('').trigger('input');
            setTimeout(function() { $paletteInput.focus(); }, 30);
        }
        function closePalette() {
            if (!$palette.length) return;
            $palette.attr('hidden', true).removeClass('hz-palette-open');
            if (paletteLastFocus && paletteLastFocus.focus) {
                try { paletteLastFocus.focus(); } catch (err) {}
            }
        }
        function renderPaletteResults(q) {
            $paletteList.empty();
            paletteActive = 0;
            if (!q) {
                paletteMatches = paletteIndex.slice(0, 20);
            } else {
                var lower = q.toLowerCase();
                paletteMatches = paletteIndex.filter(function(entry) {
                    return entry.label.toLowerCase().indexOf(lower) !== -1
                        || entry.tabLabel.toLowerCase().indexOf(lower) !== -1;
                }).slice(0, 30);
            }
            if (!paletteMatches.length) {
                $paletteList.append('<div class="hz-palette-empty">No matching settings.</div>');
                return;
            }
            paletteMatches.forEach(function(m, i) {
                var $row = $('<div class="hz-palette-item" role="option"></div>')
                    .attr('data-hz-index', i)
                    .append($('<span class="dashicons"></span>').addClass(m.icon))
                    .append($('<span class="hz-palette-item-label"></span>').text(m.label))
                    .append($('<span class="hz-palette-item-tab"></span>').text(m.tabLabel));
                if (i === 0) $row.addClass('hz-active');
                $paletteList.append($row);
            });
        }
        function gotoPaletteMatch(i) {
            var m = paletteMatches[i];
            if (!m) return;
            activateTab(m.tab, true);
            closePalette();
            setTimeout(function() {
                if (m.anchor && m.anchor.scrollIntoView) {
                    m.anchor.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                var $a = $(m.anchor);
                var $target = $a.closest('.hozio-field-group, .hozio-feature-toggle, .hozio-card, .hz-preview-card');
                if (!$target.length) $target = $a;
                $target.addClass('hz-flash');
                setTimeout(function() { $target.removeClass('hz-flash'); }, 1400);
            }, 120);
        }

        $paletteInput.on('input', function() {
            renderPaletteResults($(this).val().trim());
        });
        $paletteInput.on('keydown', function(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                paletteActive = Math.min(paletteActive + 1, paletteMatches.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                paletteActive = Math.max(paletteActive - 1, 0);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                gotoPaletteMatch(paletteActive);
                return;
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closePalette();
                return;
            } else {
                return;
            }
            $paletteList.find('.hz-palette-item').removeClass('hz-active');
            var $row = $paletteList.find('.hz-palette-item[data-hz-index="' + paletteActive + '"]');
            $row.addClass('hz-active');
            if ($row.length && $row[0].scrollIntoView) {
                $row[0].scrollIntoView({ block: 'nearest' });
            }
        });
        $paletteList.on('click', '.hz-palette-item', function() {
            gotoPaletteMatch(parseInt($(this).attr('data-hz-index'), 10));
        });
        $palette.on('click', '[data-hz-palette-close]', function() { closePalette(); });

        $('#hz-palette-open, #hz-palette-open-mini').on('click', function(e) {
            e.preventDefault();
            openPalette();
        });

        // Global Cmd/Ctrl+K to open palette
        $(document).on('keydown', function(e) {
            if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
                if (!$v2.length) return;
                e.preventDefault();
                if ($palette.attr('hidden')) {
                    openPalette();
                } else {
                    closePalette();
                }
            } else if (e.key === 'Escape' && !$palette.attr('hidden')) {
                closePalette();
            }
        });

        buildPaletteIndex();

    });

})(jQuery);
