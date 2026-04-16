<?php
/**
 * Bulk Optimizer page view
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get statistics
$stats = Hozio_Image_Optimizer_Admin::get_statistics();
$settings_url = admin_url('options-general.php?page=hozio-image-optimizer-settings');
?>

<div class="wrap hozio-bulk-optimizer">

    <!-- Top Bar: Logo + Nav -->
    <div class="hz-topbar">
        <div class="hz-topbar-left">
            <span class="hz-logo-wrap"><img src="<?php echo esc_url(HOZIO_IMAGE_OPTIMIZER_URL . 'assets/images/logo.png'); ?>" alt="Hozio" class="hz-logo-img"></span>
            <div class="hz-topbar-title">
                <span class="hz-title"><?php esc_html_e('Image Optimizer', 'hozio-image-optimizer'); ?></span>
                <span class="hz-version">v<?php echo esc_html(HOZIO_IMAGE_OPTIMIZER_VERSION); ?></span>
            </div>
        </div>
        <div class="hz-topbar-right">
            <a href="<?php echo esc_url(admin_url('upload.php?page=hozio-image-backups')); ?>" class="hz-nav-link">
                <span class="dashicons dashicons-backup"></span> <?php esc_html_e('Backups', 'hozio-image-optimizer'); ?>
            </a>
            <a href="<?php echo esc_url($settings_url); ?>" class="hz-nav-link">
                <span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Settings', 'hozio-image-optimizer'); ?>
            </a>
        </div>
    </div>

    <!-- Stats + Actions Bar -->
    <?php $percent = $stats['total_images'] > 0 ? round(($stats['images_processed'] / $stats['total_images']) * 100) : 0; ?>
    <div class="hz-dashboard">
        <div class="hz-stats-row">
            <div class="hz-stat">
                <div class="hz-stat-num" id="stat-total"><?php echo esc_html(number_format($stats['total_images'])); ?></div>
                <div class="hz-stat-label"><?php esc_html_e('Total Images', 'hozio-image-optimizer'); ?></div>
            </div>
            <div class="hz-stat-divider"></div>
            <div class="hz-stat">
                <div class="hz-stat-num hz-stat-green" id="stat-optimized"><?php echo esc_html(number_format($stats['images_processed'])); ?></div>
                <div class="hz-stat-label"><?php esc_html_e('Optimized', 'hozio-image-optimizer'); ?></div>
                <div class="hz-stat-bar"><div class="hz-stat-bar-fill" style="width:<?php echo esc_attr($percent); ?>%"></div></div>
            </div>
            <div class="hz-stat-divider"></div>
            <div class="hz-stat">
                <div class="hz-stat-num hz-stat-blue" id="stat-saved"><?php echo esc_html($stats['bytes_saved_formatted']); ?></div>
                <div class="hz-stat-label"><?php esc_html_e('Space Saved', 'hozio-image-optimizer'); ?></div>
            </div>
            <div class="hz-stat-divider"></div>
            <div class="hz-stat">
                <div class="hz-stat-num"><?php echo esc_html($percent); ?>%</div>
                <div class="hz-stat-label"><?php esc_html_e('Library Health', 'hozio-image-optimizer'); ?></div>
            </div>
            <div class="hz-stat-divider"></div>
            <div class="hz-stat hz-stat-api <?php echo $stats['api_configured'] ? 'connected' : 'disconnected'; ?>" onclick="window.location='<?php echo esc_url($settings_url); ?>'" style="cursor:pointer;">
                <div class="hz-api-dot"></div>
                <div class="hz-stat-label"><?php echo $stats['api_configured'] ? esc_html__('AI Connected', 'hozio-image-optimizer') : esc_html__('AI Not Set', 'hozio-image-optimizer'); ?></div>
            </div>
        </div>
        <div class="hz-actions-row">
            <div class="hz-actions-left">
                <button type="button" class="hz-btn hz-btn-primary" id="optimize-selected-btn" disabled>
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php esc_html_e('Optimize', 'hozio-image-optimizer'); ?>
                    <span class="selection-badge" id="selected-count-badge" style="display: none;">0</span>
                </button>
                <!-- Preview removed -->
            </div>
            <button type="button" class="hz-btn-text" id="reset-stats-btn" title="<?php esc_attr_e('Reset optimization statistics', 'hozio-image-optimizer'); ?>">
                <?php esc_html_e('Reset Stats', 'hozio-image-optimizer'); ?>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="hozio-main-layout">
        <!-- Sidebar -->
        <div class="hozio-sidebar">

            <!-- Progress (hidden initially) -->
            <div class="hozio-panel hozio-progress-panel" id="progress-panel" style="display: none;">
                <h3><?php esc_html_e('Progress', 'hozio-image-optimizer'); ?></h3>

                <div class="progress-bar-wrap">
                    <div class="progress-bar">
                        <div class="progress-bar-fill" id="progress-fill"></div>
                    </div>
                    <span class="progress-text" id="progress-text">0%</span>
                </div>

                <div class="progress-details">
                    <p id="progress-status"><?php esc_html_e('Starting...', 'hozio-image-optimizer'); ?></p>
                    <div class="progress-stats">
                        <span><strong id="progress-success">0</strong> <?php esc_html_e('optimized', 'hozio-image-optimizer'); ?></span>
                        <span><strong id="progress-saved">0 KB</strong> <?php esc_html_e('saved', 'hozio-image-optimizer'); ?></span>
                        <span><strong id="progress-errors">0</strong> <?php esc_html_e('errors', 'hozio-image-optimizer'); ?></span>
                    </div>
                </div>

                <button type="button" class="button" id="pause-btn" style="margin-top: 10px;">
                    <span class="dashicons dashicons-controls-pause"></span>
                    <?php esc_html_e('Pause', 'hozio-image-optimizer'); ?>
                </button>
            </div>

            <!-- Optimization Options -->
            <div class="hozio-panel hozio-options-panel">
                <div class="panel-header">
                    <div class="panel-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                        </svg>
                    </div>
                    <h3><?php esc_html_e('Optimization Options', 'hozio-image-optimizer'); ?></h3>
                </div>

                <!-- Compression Level -->
                <div class="option-group">
                    <label class="option-group-label" for="compression-level"><?php esc_html_e('Compression Level', 'hozio-image-optimizer'); ?></label>
                    <select id="compression-level" class="hozio-select-styled">
                        <option value="lossy" selected><?php esc_html_e('Lossy - Maximum compression', 'hozio-image-optimizer'); ?></option>
                        <option value="glossy"><?php esc_html_e('Glossy - Balanced', 'hozio-image-optimizer'); ?></option>
                        <option value="lossless"><?php esc_html_e('Lossless - No quality loss', 'hozio-image-optimizer'); ?></option>
                    </select>
                    <p class="option-hint" id="compression-desc"><?php esc_html_e('Maximum compression, slight quality loss. Best for web.', 'hozio-image-optimizer'); ?></p>
                </div>

                <div class="option-group">
                    <label class="option-group-label" for="target-filesize"><?php esc_html_e('Target Max Size (KB)', 'hozio-image-optimizer'); ?></label>
                    <input type="number" id="target-filesize" class="hozio-input-sm" min="20" max="500" value="<?php echo esc_attr(get_option('hozio_target_filesize', 80)); ?>" placeholder="80" style="width: 80px;">
                    <p class="option-hint"><?php esc_html_e('80 KB recommended for fast page loads (Core Web Vitals)', 'hozio-image-optimizer'); ?></p>
                </div>

                <div class="options-divider"></div>

                <div class="options-checklist">
                    <label class="option-checkbox">
                        <input type="checkbox" id="opt-compress" checked>
                        <span class="checkbox-custom"></span>
                        <span class="checkbox-label"><?php esc_html_e('Compress Images', 'hozio-image-optimizer'); ?></span>
                    </label>

                    <label class="option-checkbox">
                        <input type="checkbox" id="opt-convert" checked>
                        <span class="checkbox-custom"></span>
                        <span class="checkbox-label"><?php esc_html_e('Convert to WebP', 'hozio-image-optimizer'); ?></span>
                    </label>

                    <label class="option-checkbox <?php echo !$stats['api_configured'] ? 'disabled' : ''; ?>">
                        <input type="checkbox" id="opt-rename" <?php echo $stats['api_configured'] ? 'checked' : 'disabled'; ?>>
                        <span class="checkbox-custom"></span>
                        <span class="checkbox-label"><?php esc_html_e('AI Rename & Alt Text', 'hozio-image-optimizer'); ?></span>
                        <?php if (!$stats['api_configured']) : ?>
                            <a href="<?php echo esc_url($settings_url); ?>" class="option-config-link"><?php esc_html_e('Configure', 'hozio-image-optimizer'); ?></a>
                        <?php endif; ?>
                    </label>
                </div>

                <div class="options-divider"></div>

                <div class="options-advanced">
                    <label class="option-checkbox">
                        <input type="checkbox" id="opt-skip-optimized" checked>
                        <span class="checkbox-custom"></span>
                        <span class="checkbox-label"><?php esc_html_e('Skip already optimized', 'hozio-image-optimizer'); ?></span>
                    </label>
                    <p class="option-hint indent"><?php esc_html_e('Skip images that have backups', 'hozio-image-optimizer'); ?></p>

                    <label class="option-checkbox">
                        <input type="checkbox" id="opt-force-reoptimize">
                        <span class="checkbox-custom"></span>
                        <span class="checkbox-label"><?php esc_html_e('Force re-optimization', 'hozio-image-optimizer'); ?></span>
                    </label>
                    <p class="option-hint indent"><?php esc_html_e('Re-optimize even if compression detects the image is already optimized', 'hozio-image-optimizer'); ?></p>

                    <div class="options-divider" style="margin: 12px 0;"></div>

                    <label class="option-checkbox backup-toggle">
                        <input type="checkbox" id="opt-backup" <?php checked(get_option('hozio_backup_enabled', false)); ?>>
                        <span class="checkbox-custom"></span>
                        <span class="checkbox-label">
                            <span class="dashicons dashicons-backup" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px; color: #6366f1;"></span>
                            <?php esc_html_e('Create backups', 'hozio-image-optimizer'); ?>
                        </span>
                    </label>
                    <p class="option-hint indent"><?php esc_html_e('Save original files before optimization (allows restore)', 'hozio-image-optimizer'); ?></p>
                </div>
            </div>

            <!-- AI Context -->
            <div class="hozio-panel hozio-context-panel" id="ai-context-panel">
                <div class="panel-header">
                    <div class="panel-icon panel-icon-purple">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                    </div>
                    <h3><?php esc_html_e('AI Context', 'hozio-image-optimizer'); ?></h3>
                    <span class="panel-badge"><?php esc_html_e('Auto-saved', 'hozio-image-optimizer'); ?></span>
                </div>

                <div class="context-field">
                    <label class="context-label" for="location-input">
                        <span class="label-text"><?php esc_html_e('Location', 'hozio-image-optimizer'); ?></span>
                        <?php if (get_option('hozio_enable_geolocation', true)) : ?>
                            <span class="label-icon location-icon" title="<?php esc_attr_e('GPS coordinates will be embedded', 'hozio-image-optimizer'); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                    <circle cx="12" cy="10" r="3"></circle>
                                </svg>
                            </span>
                        <?php endif; ?>
                    </label>
                    <?php
                    $custom_locations = get_option('hozio_custom_locations', array());
                    ?>
                    <?php if (!empty($custom_locations)) : ?>
                        <div class="location-selector-wrap">
                            <select id="location-selector" class="context-input">
                                <option value=""><?php esc_html_e('-- Select location --', 'hozio-image-optimizer'); ?></option>
                                <optgroup label="<?php esc_attr_e('Custom Locations', 'hozio-image-optimizer'); ?>">
                                    <?php foreach ($custom_locations as $location) : ?>
                                        <option value="<?php echo esc_attr($location['name']); ?>"
                                                data-lat="<?php echo esc_attr($location['lat']); ?>"
                                                data-lng="<?php echo esc_attr($location['lng']); ?>">
                                            <?php echo esc_html($location['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="<?php esc_attr_e('Other', 'hozio-image-optimizer'); ?>">
                                    <option value="custom"><?php esc_html_e('Enter custom location...', 'hozio-image-optimizer'); ?></option>
                                </optgroup>
                            </select>
                            <div class="input-with-status" id="custom-location-wrap" style="display: none; margin-top: 8px;">
                                <input type="text" id="location-input" class="context-input" placeholder="<?php esc_attr_e('Enter city, state...', 'hozio-image-optimizer'); ?>" autocomplete="off">
                                <span id="geocode-status" class="input-status"></span>
                                <div id="location-autocomplete" class="location-autocomplete"></div>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="input-with-status">
                            <input type="text" id="location-input" class="context-input" placeholder="<?php esc_attr_e('Enter city, state...', 'hozio-image-optimizer'); ?>" autocomplete="off">
                            <span id="geocode-status" class="input-status"></span>
                            <div id="location-autocomplete" class="location-autocomplete"></div>
                        </div>
                        <p class="context-hint" style="margin-top: 5px;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=hozio-image-optimizer-settings&tab=geolocation')); ?>" target="_blank">
                                <?php esc_html_e('Add custom locations in Settings', 'hozio-image-optimizer'); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                    <p class="context-hint"><?php esc_html_e('Used for AI naming AND GPS coordinate embedding', 'hozio-image-optimizer'); ?></p>
                    <div id="geocode-preview" class="geocode-success">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                        <span id="geocode-coords"></span>
                        <a href="#" id="view-location-btn" class="view-location-link" target="_blank" style="display: none;">
                            <span class="dashicons dashicons-external"></span>
                            <?php esc_html_e('View on Map', 'hozio-image-optimizer'); ?>
                        </a>
                    </div>
                    <button type="button" id="pin-coordinates-btn" class="pin-coords-btn">
                        <span class="dashicons dashicons-location"></span>
                        <?php esc_html_e('Set Exact Coordinates', 'hozio-image-optimizer'); ?>
                    </button>

                    <!-- Pin Coordinates Modal -->
                    <div id="pin-coordinates-modal" class="hozio-mini-modal" style="display: none;">
                        <div class="mini-modal-content">
                            <div class="mini-modal-header">
                                <h4><?php esc_html_e('Set Exact GPS Coordinates', 'hozio-image-optimizer'); ?></h4>
                                <button type="button" class="mini-modal-close">&times;</button>
                            </div>
                            <div class="mini-modal-body">
                                <p class="modal-hint"><?php esc_html_e('Enter exact coordinates or paste from Google Maps. Right-click on Google Maps and click the coordinates to copy them.', 'hozio-image-optimizer'); ?></p>
                                <div class="coords-input-group">
                                    <div class="coord-field">
                                        <label for="manual-lat"><?php esc_html_e('Latitude', 'hozio-image-optimizer'); ?></label>
                                        <input type="text" id="manual-lat" class="hozio-input" placeholder="e.g., 40.7128">
                                    </div>
                                    <div class="coord-field">
                                        <label for="manual-lng"><?php esc_html_e('Longitude', 'hozio-image-optimizer'); ?></label>
                                        <input type="text" id="manual-lng" class="hozio-input" placeholder="e.g., -74.0060">
                                    </div>
                                </div>
                                <div class="paste-coords-wrap">
                                    <label for="paste-coords"><?php esc_html_e('Or paste coordinates (e.g., "40.7128, -74.0060")', 'hozio-image-optimizer'); ?></label>
                                    <input type="text" id="paste-coords" class="hozio-input" placeholder="<?php esc_attr_e('Paste coordinates here...', 'hozio-image-optimizer'); ?>">
                                </div>
                                <div class="mini-modal-actions">
                                    <button type="button" id="apply-coords-btn" class="hozio-btn hozio-btn-primary"><?php esc_html_e('Apply Coordinates', 'hozio-image-optimizer'); ?></button>
                                    <button type="button" class="hozio-btn hozio-btn-secondary mini-modal-close"><?php esc_html_e('Cancel', 'hozio-image-optimizer'); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="context-field">
                    <label class="context-label" for="keyword-input">
                        <span class="label-text"><?php esc_html_e('Keyword/Context Hint', 'hozio-image-optimizer'); ?></span>
                    </label>
                    <input type="text" id="keyword-input" class="context-input" placeholder="<?php esc_attr_e('e.g., plumbing services, modern kitchen', 'hozio-image-optimizer'); ?>">
                    <p class="context-hint"><?php esc_html_e('Primary topic or business context for better naming', 'hozio-image-optimizer'); ?></p>
                </div>
            </div>

        </div>

        <!-- Main Content Area -->
        <div class="hozio-content">
            <!-- Row 1: Search + Filters -->
            <div class="hz-filter-bar">
                <div class="hz-search-wrap">
                    <span class="dashicons dashicons-search"></span>
                    <input type="text" id="search-input" placeholder="<?php esc_attr_e('Search images...', 'hozio-image-optimizer'); ?>">
                </div>
                <div class="hz-filters">
                    <select id="filter-type" class="hz-select">
                        <option value="all"><?php esc_html_e('All Types', 'hozio-image-optimizer'); ?></option>
                        <option value="jpeg"><?php esc_html_e('JPEG', 'hozio-image-optimizer'); ?></option>
                        <option value="png"><?php esc_html_e('PNG', 'hozio-image-optimizer'); ?></option>
                        <option value="webp"><?php esc_html_e('WebP', 'hozio-image-optimizer'); ?></option>
                        <option value="gif"><?php esc_html_e('GIF', 'hozio-image-optimizer'); ?></option>
                    </select>
                    <select id="filter-status" class="hz-select">
                        <option value="all"><?php esc_html_e('All Status', 'hozio-image-optimizer'); ?></option>
                        <option value="unoptimized"><?php esc_html_e('Not Optimized', 'hozio-image-optimizer'); ?></option>
                        <option value="optimized"><?php esc_html_e('Optimized', 'hozio-image-optimizer'); ?></option>
                    </select>
                    <select id="sort-by" class="hz-select">
                        <option value="date-DESC"><?php esc_html_e('Newest', 'hozio-image-optimizer'); ?></option>
                        <option value="date-ASC"><?php esc_html_e('Oldest', 'hozio-image-optimizer'); ?></option>
                        <option value="size-DESC"><?php esc_html_e('Largest', 'hozio-image-optimizer'); ?></option>
                        <option value="size-ASC"><?php esc_html_e('Smallest', 'hozio-image-optimizer'); ?></option>
                        <option value="name-ASC"><?php esc_html_e('A-Z', 'hozio-image-optimizer'); ?></option>
                        <option value="name-DESC"><?php esc_html_e('Z-A', 'hozio-image-optimizer'); ?></option>
                    </select>
                    <button type="button" class="hz-icon-btn" id="refresh-btn" title="<?php esc_attr_e('Refresh', 'hozio-image-optimizer'); ?>">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
            </div>

            <!-- Row 2: Selection Actions -->
            <div class="hz-selection-bar">
                <label class="hz-check-label" id="select-all-label">
                    <input type="checkbox" id="select-all">
                    <span id="select-all-text"><?php esc_html_e('Select All', 'hozio-image-optimizer'); ?></span>
                </label>
                <button type="button" class="hz-pill-btn hz-pill-orange" id="select-unoptimized-btn"><?php esc_html_e('Unoptimized', 'hozio-image-optimizer'); ?></button>
                <button type="button" class="hz-pill-btn hz-pill-blue" id="select-recommended-btn"><?php esc_html_e('Recommended', 'hozio-image-optimizer'); ?></button>
                <button type="button" class="hz-pill-btn" id="deselect-all-btn" style="display:none;"><?php esc_html_e('Clear', 'hozio-image-optimizer'); ?></button>
                <span class="hz-selection-count" id="hz-selection-info" style="display:none;">
                    <strong id="selected-count">0</strong> <?php esc_html_e('selected', 'hozio-image-optimizer'); ?>
                </span>
            </div>

            <!-- Image Grid -->
            <div class="hozio-image-grid" id="image-grid">
                <div class="loading-spinner">
                    <span class="spinner is-active"></span>
                    <p><?php esc_html_e('Loading images...', 'hozio-image-optimizer'); ?></p>
                </div>
            </div>

            <!-- Load More -->
            <div class="hozio-pagination" id="pagination">
                <!-- Load More button will be inserted here by JavaScript -->
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="hozio-modal" id="preview-modal" style="display: none;">
    <div class="hozio-modal-overlay"></div>
    <div class="hozio-modal-content">
        <div class="hozio-modal-header">
            <h2><?php esc_html_e('Preview Changes', 'hozio-image-optimizer'); ?></h2>
            <button type="button" class="hozio-modal-close">&times;</button>
        </div>
        <div class="hozio-modal-body" id="preview-content">
            <!-- Preview content will be loaded here -->
        </div>
        <div class="hozio-modal-footer">
            <button type="button" class="button" id="cancel-preview"><?php esc_html_e('Cancel', 'hozio-image-optimizer'); ?></button>
            <button type="button" class="button button-primary" id="confirm-optimize"><?php esc_html_e('Confirm & Optimize', 'hozio-image-optimizer'); ?></button>
        </div>
    </div>
</div>

<!-- Results Modal -->
<div class="hozio-modal" id="results-modal" style="display: none;">
    <div class="hozio-modal-overlay"></div>
    <div class="hozio-modal-content hozio-modal-large">
        <div class="hozio-modal-header">
            <h2><?php esc_html_e('Optimization Complete', 'hozio-image-optimizer'); ?></h2>
            <button type="button" class="hozio-modal-close">&times;</button>
        </div>
        <div class="hozio-modal-body" id="results-content">
            <!-- Results will be loaded here -->
        </div>
        <div class="hozio-modal-footer">
            <button type="button" class="button button-primary hozio-modal-close"><?php esc_html_e('Done', 'hozio-image-optimizer'); ?></button>
        </div>
    </div>
</div>

<!-- Image Detail Modal -->
<div class="hozio-modal" id="image-detail-modal" style="display: none;">
    <div class="hozio-modal-overlay"></div>
    <div class="hozio-modal-content hozio-modal-large">
        <div class="hozio-modal-header">
            <h2><?php esc_html_e('Image Details', 'hozio-image-optimizer'); ?></h2>
            <button type="button" class="hozio-modal-close">&times;</button>
        </div>
        <div class="hozio-modal-body" id="image-detail-content">
            <!-- Image details will be loaded here -->
        </div>
        <div class="hozio-modal-footer">
            <button type="button" class="button" id="restore-single-btn" style="display:none;">
                <span class="dashicons dashicons-backup"></span>
                <?php esc_html_e('Restore Original', 'hozio-image-optimizer'); ?>
            </button>
            <button type="button" class="button button-primary" id="optimize-single-btn">
                <span class="dashicons dashicons-image-rotate"></span>
                <?php esc_html_e('Optimize This Image', 'hozio-image-optimizer'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Location Warning Modal -->
<div class="hozio-modal" id="location-warning-modal" style="display: none;">
    <div class="hozio-modal-overlay"></div>
    <div class="hozio-modal-content" style="max-width: 500px;">
        <div class="hozio-modal-header">
            <h2>
                <span class="dashicons dashicons-warning" style="color: #dba617;"></span>
                <?php esc_html_e('Location Not Set', 'hozio-image-optimizer'); ?>
            </h2>
            <button type="button" class="hozio-modal-close">&times;</button>
        </div>
        <div class="hozio-modal-body">
            <p><?php esc_html_e('You haven\'t entered a location. Adding a location helps the AI generate better, more contextual filenames and alt text for your images.', 'hozio-image-optimizer'); ?></p>
            <p style="color: #666; font-size: 13px;"><?php esc_html_e('Examples: "New York", "Kitchen", "Office Building", "Beach Resort"', 'hozio-image-optimizer'); ?></p>
        </div>
        <div class="hozio-modal-footer" style="display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" class="button" id="location-warning-back">
                <span class="dashicons dashicons-edit" style="margin-top: 3px;"></span>
                <?php esc_html_e('Go Back & Add Location', 'hozio-image-optimizer'); ?>
            </button>
            <button type="button" class="button button-primary" id="location-warning-continue">
                <?php esc_html_e('Continue Anyway', 'hozio-image-optimizer'); ?>
            </button>
        </div>
    </div>
</div>

<script>
// Pass data to JavaScript
var hozioImageOptimizerData = {
    nonce: '<?php echo esc_js(wp_create_nonce('hozio_image_optimizer_nonce')); ?>',
    ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
    settingsUrl: '<?php echo esc_js($settings_url); ?>',
    apiConfigured: <?php echo $stats['api_configured'] ? 'true' : 'false'; ?>,
    strings: {
        optimizing: '<?php echo esc_js(__('Optimizing...', 'hozio-image-optimizer')); ?>',
        complete: '<?php echo esc_js(__('Complete!', 'hozio-image-optimizer')); ?>',
        error: '<?php echo esc_js(__('Error', 'hozio-image-optimizer')); ?>',
        noImages: '<?php echo esc_js(__('No images found', 'hozio-image-optimizer')); ?>',
        selectImages: '<?php echo esc_js(__('Please select at least one image', 'hozio-image-optimizer')); ?>',
        processing: '<?php echo esc_js(__('Processing', 'hozio-image-optimizer')); ?>',
        paused: '<?php echo esc_js(__('Paused', 'hozio-image-optimizer')); ?>',
        resume: '<?php echo esc_js(__('Resume', 'hozio-image-optimizer')); ?>',
        pause: '<?php echo esc_js(__('Pause', 'hozio-image-optimizer')); ?>',
        skipped: '<?php echo esc_js(__('Skipped (already optimized)', 'hozio-image-optimizer')); ?>',
        estimatedSavings: '<?php echo esc_js(__('Est. savings:', 'hozio-image-optimizer')); ?>',
    },
    compressionDescriptions: {
        lossy: '<?php echo esc_js(__('Maximum compression, slight quality reduction', 'hozio-image-optimizer')); ?>',
        glossy: '<?php echo esc_js(__('Good balance between quality and file size', 'hozio-image-optimizer')); ?>',
        lossless: '<?php echo esc_js(__('No quality loss, smaller compression', 'hozio-image-optimizer')); ?>'
    }
};
</script>
