<?php
/**
 * Backups management page with modern UI and Unused Images tab
 */

if (!defined('ABSPATH')) {
    exit;
}

$backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
$backup_stats = $backup_manager->get_backup_stats();
$page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$backed_up_images = $backup_manager->get_backed_up_images($page, 20);

// Get current tab
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'backups';

// Get unused detector stats
$unused_detector = new Hozio_Image_Optimizer_Unused_Detector();
$unused_stats = $unused_detector->get_stats();
?>

<?php
$broken_count = 0;
$saved_broken = null;
if (class_exists('Hozio_Image_Optimizer_Broken_Detector')) {
    $broken_detector = new Hozio_Image_Optimizer_Broken_Detector();
    $saved_broken = $broken_detector->get_saved_results();
    $broken_count = $saved_broken ? intval($saved_broken['total_broken'] ?? 0) : 0;
}
?>

<div class="wrap hozio-backups-page">

    <!-- Top Bar -->
    <div class="hz-topbar">
        <div class="hz-topbar-left">
            <span class="hz-logo-wrap"><img src="<?php echo esc_url(HOZIO_IMAGE_OPTIMIZER_URL . 'assets/images/logo.png'); ?>" alt="Hozio" class="hz-logo-img"></span>
            <div class="hz-topbar-title">
                <span class="hz-title"><?php esc_html_e('Image Management', 'hozio-image-optimizer'); ?></span>
            </div>
        </div>
        <div class="hz-topbar-right">
            <a href="<?php echo esc_url(admin_url('upload.php?page=hozio-image-optimizer')); ?>" class="hz-nav-link">
                <span class="dashicons dashicons-images-alt2"></span> <?php esc_html_e('Optimizer', 'hozio-image-optimizer'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=hozio-image-optimizer-settings')); ?>" class="hz-nav-link">
                <span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Settings', 'hozio-image-optimizer'); ?>
            </a>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="hz-tab-nav">
        <a href="<?php echo esc_url(add_query_arg('tab', 'backups', remove_query_arg('paged'))); ?>"
           class="hz-tab <?php echo $current_tab === 'backups' ? 'active' : ''; ?>">
            <?php esc_html_e('Backups', 'hozio-image-optimizer'); ?>
            <?php if ($backup_stats['total_images'] > 0) : ?>
                <span class="hz-tab-count"><?php echo esc_html($backup_stats['total_images']); ?></span>
            <?php endif; ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'unused', remove_query_arg('paged'))); ?>"
           class="hz-tab <?php echo $current_tab === 'unused' ? 'active' : ''; ?>">
            <?php esc_html_e('Unused', 'hozio-image-optimizer'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'broken', remove_query_arg('paged'))); ?>"
           class="hz-tab <?php echo $current_tab === 'broken' ? 'active' : ''; ?>">
            <?php esc_html_e('Broken', 'hozio-image-optimizer'); ?>
            <?php if ($broken_count > 0) : ?>
                <span class="hz-tab-count hz-tab-count-red"><?php echo esc_html($broken_count); ?></span>
            <?php endif; ?>
        </a>
    </div>

    <?php if ($current_tab === 'backups') : ?>
        <!-- Backups Tab Content -->
        <div class="hozio-tab-content" id="backups-tab">

            <!-- Stats Row -->
            <div class="hz-dashboard">
                <div class="hz-stats-row">
                    <div class="hz-stat">
                        <div class="hz-stat-num"><?php echo esc_html($backup_stats['total_images']); ?></div>
                        <div class="hz-stat-label"><?php esc_html_e('Backed Up', 'hozio-image-optimizer'); ?></div>
                    </div>
                    <div class="hz-stat-divider"></div>
                    <div class="hz-stat">
                        <div class="hz-stat-num hz-stat-blue"><?php echo esc_html($backup_stats['total_size_formatted']); ?></div>
                        <div class="hz-stat-label"><?php esc_html_e('Storage Used', 'hozio-image-optimizer'); ?></div>
                    </div>
                    <div class="hz-stat-divider"></div>
                    <div class="hz-stat">
                        <div class="hz-stat-num"><?php echo esc_html(get_option('hozio_backup_retention_days', 30)); ?> <?php esc_html_e('days', 'hozio-image-optimizer'); ?></div>
                        <div class="hz-stat-label"><?php esc_html_e('Retention', 'hozio-image-optimizer'); ?></div>
                    </div>
                    <div class="hz-stat-divider"></div>
                    <div class="hz-stat">
                        <div class="hz-stat-num"><?php echo esc_html($backup_stats['total_backups']); ?></div>
                        <div class="hz-stat-label"><?php esc_html_e('Files', 'hozio-image-optimizer'); ?></div>
                    </div>
                </div>
                <div class="hz-actions-row">
                    <div class="hz-actions-left">
                        <button type="button" class="hz-btn hz-btn-primary" id="download-all-btn" <?php disabled($backup_stats['total_images'] < 1); ?>>
                            <span class="dashicons dashicons-download"></span> <?php esc_html_e('Download All', 'hozio-image-optimizer'); ?>
                        </button>
                        <button type="button" class="hz-btn hz-btn-ghost" id="cleanup-old-btn">
                            <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Clean Up Old', 'hozio-image-optimizer'); ?>
                        </button>
                    </div>
                    <?php if (!empty($backed_up_images['images'])) : ?>
                        <span style="font-size:11px;color:#9ca3af;"><?php printf(esc_html__('Showing %d of %d', 'hozio-image-optimizer'), count($backed_up_images['images']), $backed_up_images['total']); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Content -->
            <div class="hz-content-card">
                <?php if (!empty($backed_up_images['images'])) : ?>
                <!-- Filter Bar -->
                <div class="hz-filter-bar">
                    <div class="hz-search-wrap">
                        <span class="dashicons dashicons-search"></span>
                        <input type="text" id="backup-search" placeholder="<?php esc_attr_e('Search backups...', 'hozio-image-optimizer'); ?>">
                    </div>
                    <div class="hz-filters">
                        <select id="backup-filter" class="hz-select">
                            <option value="all"><?php esc_html_e('All', 'hozio-image-optimizer'); ?></option>
                            <option value="changed"><?php esc_html_e('Changed', 'hozio-image-optimizer'); ?></option>
                            <option value="unchanged"><?php esc_html_e('Unchanged', 'hozio-image-optimizer'); ?></option>
                        </select>
                        <select id="backup-sort" class="hz-select">
                            <option value="newest"><?php esc_html_e('Newest', 'hozio-image-optimizer'); ?></option>
                            <option value="oldest"><?php esc_html_e('Oldest', 'hozio-image-optimizer'); ?></option>
                            <option value="name"><?php esc_html_e('A-Z', 'hozio-image-optimizer'); ?></option>
                        </select>
                    </div>
                </div>

                <!-- Selection Bar -->
                <div class="hz-selection-bar">
                    <label class="hz-check-label" id="backup-select-all-label">
                        <input type="checkbox" id="select-all-backups">
                        <span id="backup-select-all-text"><?php esc_html_e('Select All', 'hozio-image-optimizer'); ?></span>
                    </label>
                    <button type="button" class="hz-pill-btn" id="bulk-restore-btn" style="display:none;"><?php esc_html_e('Restore Selected', 'hozio-image-optimizer'); ?></button>
                    <span class="hz-selection-count" id="backup-selection-info" style="display:none;">
                        <strong id="backup-selected-count">0</strong> <?php esc_html_e('selected', 'hozio-image-optimizer'); ?>
                    </span>
                </div>
                <?php endif; ?>

                <div style="padding:0;">
                    <?php if (empty($backed_up_images['images'])) : ?>
                        <!-- Empty State -->
                        <div class="hozio-empty-state large">
                            <div class="empty-icon">
                                <span class="dashicons dashicons-backup"></span>
                            </div>
                            <h3><?php esc_html_e('No Backups Yet', 'hozio-image-optimizer'); ?></h3>
                            <p><?php esc_html_e('Backups are created automatically when you optimize images. Start optimizing to see your backups here.', 'hozio-image-optimizer'); ?></p>
                            <a href="<?php echo esc_url(admin_url('upload.php?page=hozio-image-optimizer')); ?>" class="hozio-btn hozio-btn-primary">
                                <span class="dashicons dashicons-images-alt2"></span>
                                <?php esc_html_e('Optimize Images', 'hozio-image-optimizer'); ?>
                            </a>
                        </div>
                    <?php else : ?>
                        <!-- Backups Grid (Dark themed like optimizer) -->
                        <div class="hozio-image-grid" style="padding:16px;">
                            <?php foreach ($backed_up_images['images'] as $image) : ?>
                                <?php $dimensions = ''; if (!empty($image['width']) && !empty($image['height'])) { $dimensions = $image['width'] . ' x ' . $image['height']; } ?>
                                <div class="hozio-image-card hozio-backup-item" data-attachment-id="<?php echo esc_attr($image['attachment_id']); ?>" data-id="<?php echo esc_attr($image['attachment_id']); ?>" data-title="<?php echo esc_attr(strtolower($image['title'])); ?>" data-filename="<?php echo esc_attr(strtolower($image['current_filename'])); ?>" data-changed="<?php echo $image['has_changes'] ? '1' : '0'; ?>" data-date="<?php echo esc_attr($image['last_backup_date']); ?>">
                                    <input type="checkbox" class="backup-checkbox card-checkbox" data-id="<?php echo esc_attr($image['attachment_id']); ?>">
                                    <div class="card-image-wrap">
                                        <?php if ($image['thumbnail']) : ?>
                                            <img src="<?php echo esc_url($image['thumbnail']); ?>" alt="" class="card-image" loading="lazy">
                                        <?php else : ?>
                                            <div style="width:100%;height:140px;background:#1a1c22;display:flex;align-items:center;justify-content:center;"><span class="dashicons dashicons-format-image" style="color:#475569;font-size:32px;width:32px;height:32px;"></span></div>
                                        <?php endif; ?>
                                        <?php if ($image['mime_type']) : ?>
                                            <span class="card-type-badge"><?php echo esc_html($image['mime_type']); ?></span>
                                        <?php endif; ?>
                                        <span class="card-status-badge" style="background:rgba(99,102,241,0.8);"><span class="dashicons dashicons-backup" style="font-size:10px;width:10px;height:10px;color:#fff;"></span></span>
                                    </div>
                                    <div class="card-body">
                                        <div class="card-title" title="<?php echo esc_attr($image['title']); ?>"><?php echo esc_html($image['title']); ?></div>
                                        <?php if (!empty($image['file_size_formatted'])) : ?>
                                            <div class="card-size-line"><?php echo esc_html($image['file_size_formatted']); ?></div>
                                        <?php endif; ?>
                                        <div style="font-size:10px;color:#64748b;margin-top:2px;"><?php echo esc_html(Hozio_Image_Optimizer_Helpers::time_elapsed($image['last_backup_date'])); ?></div>
                                        <?php if ($image['has_changes']) : ?>
                                            <div style="font-size:9px;color:#f59e0b;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php esc_html_e('Was:', 'hozio-image-optimizer'); ?> <?php echo esc_html($image['original_filename']); ?></div>
                                        <?php endif; ?>
                                        <div class="card-bottom-row">
                                            <button type="button" class="bkp-restore-btn restore-btn" data-id="<?php echo esc_attr($image['attachment_id']); ?>">
                                                <span class="dashicons dashicons-undo"></span> <?php esc_html_e('Restore', 'hozio-image-optimizer'); ?>
                                            </button>
                                            <button type="button" class="bkp-delete-btn delete-backup-btn" data-id="<?php echo esc_attr($image['attachment_id']); ?>" title="<?php esc_attr_e('Delete backup', 'hozio-image-optimizer'); ?>">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($backed_up_images['pages'] > 1) : ?>
                            <div class="hozio-pagination">
                                <div class="pagination-info">
                                    <?php printf(
                                        esc_html__('Page %d of %d', 'hozio-image-optimizer'),
                                        $page,
                                        $backed_up_images['pages']
                                    ); ?>
                                </div>
                                <div class="pagination-nav">
                                    <?php if ($page > 1) : ?>
                                        <a class="hozio-btn hozio-btn-outline" href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>">
                                            <span class="dashicons dashicons-arrow-left-alt2"></span>
                                            <?php esc_html_e('Previous', 'hozio-image-optimizer'); ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($page < $backed_up_images['pages']) : ?>
                                        <a class="hozio-btn hozio-btn-outline" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>">
                                            <?php esc_html_e('Next', 'hozio-image-optimizer'); ?>
                                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php elseif ($current_tab === 'unused') : ?>
        <!-- Unused Images Tab Content -->
        <div class="hozio-tab-content" id="unused-tab">
            <!-- Actions Bar -->
            <div class="hozio-actions-bar">
                <button type="button" class="hozio-btn hozio-btn-primary" id="scan-unused-btn">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e('Scan for Unused Images', 'hozio-image-optimizer'); ?>
                </button>
                <label class="hozio-btn hozio-btn-secondary" id="restore-zip-btn">
                    <span class="dashicons dashicons-upload"></span>
                    <?php esc_html_e('Restore from ZIP', 'hozio-image-optimizer'); ?>
                    <input type="file" id="restore-zip-input" accept=".zip" style="display: none;">
                </label>
            </div>

            <!-- Statistics Cards -->
            <div class="hozio-stats-grid" id="unused-stats">
                <div class="hozio-stat-card">
                    <div class="stat-icon images">
                        <span class="dashicons dashicons-format-gallery"></span>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value" id="stat-total-images"><?php echo esc_html($unused_stats['total_images']); ?></span>
                        <span class="stat-label"><?php esc_html_e('Total Images', 'hozio-image-optimizer'); ?></span>
                    </div>
                </div>

                <div class="hozio-stat-card">
                    <div class="stat-icon warning">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value" id="stat-unused-count">-</span>
                        <span class="stat-label"><?php esc_html_e('Unused Images', 'hozio-image-optimizer'); ?></span>
                    </div>
                </div>

                <div class="hozio-stat-card">
                    <div class="stat-icon storage">
                        <span class="dashicons dashicons-database"></span>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value" id="stat-potential-savings">-</span>
                        <span class="stat-label"><?php esc_html_e('Potential Savings', 'hozio-image-optimizer'); ?></span>
                    </div>
                </div>

                <div class="hozio-stat-card">
                    <div class="stat-icon protected">
                        <span class="dashicons dashicons-shield"></span>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value" id="stat-protected"><?php echo esc_html($unused_stats['protected_count']); ?></span>
                        <span class="stat-label"><?php esc_html_e('Protected Images', 'hozio-image-optimizer'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Main Content Card -->
            <div class="hozio-card">
                <div class="hozio-card-header">
                    <div>
                        <h2><?php esc_html_e('Unused Images', 'hozio-image-optimizer'); ?></h2>
                        <p><?php esc_html_e('Images not referenced in any posts, pages, widgets, or theme settings', 'hozio-image-optimizer'); ?></p>
                    </div>
                </div>

                <!-- Bulk actions bar (shown after scan results load) -->
                <div class="unused-bulk-bar" id="unused-bulk-actions" style="display: none;">
                    <button type="button" class="hozio-btn hozio-btn-outline btn-sm" id="select-all-unused">
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e('Select All', 'hozio-image-optimizer'); ?>
                    </button>
                    <button type="button" class="hozio-btn hozio-btn-secondary btn-sm" id="protect-selected-btn" style="display: none;">
                        <span class="dashicons dashicons-shield"></span>
                        <?php esc_html_e('Protect', 'hozio-image-optimizer'); ?>
                    </button>
                    <button type="button" class="hozio-btn hozio-btn-outline btn-sm" id="download-selected-btn" style="display: none;">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Download ZIP', 'hozio-image-optimizer'); ?>
                    </button>
                    <button type="button" class="hozio-btn hozio-btn-danger btn-sm" id="delete-selected-btn" style="display: none;">
                        <span class="dashicons dashicons-trash"></span>
                        <?php esc_html_e('Delete', 'hozio-image-optimizer'); ?>
                        <span class="bulk-count-badge">0</span>
                    </button>
                </div>

                <div class="hozio-card-body no-padding">
                    <!-- Initial State - Before Scan -->
                    <div class="hozio-empty-state large" id="unused-initial-state">
                        <div class="empty-icon">
                            <span class="dashicons dashicons-search"></span>
                        </div>
                        <h3><?php esc_html_e('Scan Your Media Library', 'hozio-image-optimizer'); ?></h3>
                        <p><?php esc_html_e('Click "Scan for Unused Images" to find images that are not being used anywhere on your site. You can then safely delete them to free up space.', 'hozio-image-optimizer'); ?></p>
                        <?php if ($unused_stats['last_scan']) : ?>
                            <p class="last-scan-info">
                                <?php printf(
                                    esc_html__('Last scan: %s', 'hozio-image-optimizer'),
                                    Hozio_Image_Optimizer_Helpers::time_elapsed($unused_stats['last_scan'])
                                ); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Scanning State -->
                    <div class="hozio-empty-state large" id="unused-scanning-state" style="display: none;">
                        <div class="empty-icon scanning">
                            <span class="dashicons dashicons-update spin"></span>
                        </div>
                        <h3><?php esc_html_e('Scanning Media Library...', 'hozio-image-optimizer'); ?></h3>
                        <p><?php esc_html_e('This may take a few moments depending on the size of your media library.', 'hozio-image-optimizer'); ?></p>
                        <div class="scan-progress">
                            <div class="scan-progress-bar" id="unused-scan-progress-fill"></div>
                        </div>
                        <div class="scan-progress-label" id="unused-scan-progress-label"><?php esc_html_e('Starting scan…', 'hozio-image-optimizer'); ?></div>
                    </div>

                    <!-- No Unused Images State -->
                    <div class="hozio-empty-state large success" id="unused-clean-state" style="display: none;">
                        <div class="empty-icon">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <h3><?php esc_html_e('Your Media Library is Clean!', 'hozio-image-optimizer'); ?></h3>
                        <p><?php esc_html_e('No unused images were found. All images in your library are being used somewhere on your site.', 'hozio-image-optimizer'); ?></p>
                    </div>

                    <!-- Results Grid -->
                    <div class="hozio-image-grid" id="unused-results-grid" style="display:none;padding:16px;"></div>
                </div>
            </div>
        </div>
    <?php elseif ($current_tab === 'broken') : ?>
        <!-- Broken Images Tab Content -->
        <div class="hozio-tab-content" id="broken-tab">

            <!-- Stats + Actions -->
            <div class="hz-dashboard">
                <div class="hz-stats-row">
                    <div class="hz-stat">
                        <div class="hz-stat-num" style="color:#ef4444;" id="stat-broken-total"><?php echo esc_html($broken_count); ?></div>
                        <div class="hz-stat-label"><?php esc_html_e('Broken', 'hozio-image-optimizer'); ?></div>
                    </div>
                    <div class="hz-stat-divider"></div>
                    <div class="hz-stat">
                        <div class="hz-stat-num" id="stat-broken-locations"><?php echo $saved_broken ? esc_html($saved_broken['total_locations']) : '0'; ?></div>
                        <div class="hz-stat-label"><?php esc_html_e('Affected Pages', 'hozio-image-optimizer'); ?></div>
                    </div>
                    <div class="hz-stat-divider"></div>
                    <div class="hz-stat">
                        <div class="hz-stat-num" id="stat-broken-attachments">-</div>
                        <div class="hz-stat-label"><?php esc_html_e('Missing Files', 'hozio-image-optimizer'); ?></div>
                    </div>
                    <div class="hz-stat-divider"></div>
                    <div class="hz-stat">
                        <div class="hz-stat-num" id="stat-broken-urls">-</div>
                        <div class="hz-stat-label"><?php esc_html_e('Broken URLs', 'hozio-image-optimizer'); ?></div>
                    </div>
                </div>
                <div class="hz-actions-row">
                    <div class="hz-actions-left">
                        <button type="button" class="hz-btn hz-btn-primary" id="scan-broken-btn">
                            <span class="dashicons dashicons-search"></span> <?php esc_html_e('Scan for Broken Images', 'hozio-image-optimizer'); ?>
                        </button>
                    </div>
                    <span id="broken-last-scan" style="font-size:11px;color:#9ca3af;">
                        <?php if ($saved_broken && isset($saved_broken['scan_time'])) : ?>
                            <?php printf(esc_html__('Last scan: %s', 'hozio-image-optimizer'), esc_html(Hozio_Image_Optimizer_Helpers::time_elapsed($saved_broken['scan_time']))); ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Content -->
            <div class="hz-content-card">
                <!-- States -->
                <div class="hz-empty-state" id="broken-initial-state" <?php echo ($saved_broken && $saved_broken['total_broken'] > 0) ? 'style="display:none;"' : ''; ?>>
                    <div class="hz-empty-icon"><span class="dashicons dashicons-shield"></span></div>
                    <div class="hz-empty-title"><?php esc_html_e('Check for Broken Images', 'hozio-image-optimizer'); ?></div>
                    <div class="hz-empty-desc"><?php esc_html_e('Scan your site to find images whose files are missing or inaccessible. We\'ll help you fix them.', 'hozio-image-optimizer'); ?></div>
                </div>

                <div class="hz-empty-state" id="broken-scanning-state" style="display:none;">
                    <div class="hz-empty-icon"><span class="dashicons dashicons-update spin"></span></div>
                    <div class="hz-empty-title"><?php esc_html_e('Scanning...', 'hozio-image-optimizer'); ?></div>
                    <div class="hz-empty-desc"><?php esc_html_e('Checking all attachments and post content.', 'hozio-image-optimizer'); ?></div>
                </div>

                <div class="hz-empty-state" id="broken-clean-state" style="display:none;">
                    <div class="hz-empty-icon" style="color:#22c55e;"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div class="hz-empty-title"><?php esc_html_e('All Clear!', 'hozio-image-optimizer'); ?></div>
                    <div class="hz-empty-desc"><?php esc_html_e('No broken images found. All images on your site are working correctly.', 'hozio-image-optimizer'); ?></div>
                </div>

                <!-- Results -->
                <div id="broken-results-list" <?php echo ($saved_broken && $saved_broken['total_broken'] > 0) ? '' : 'style="display:none;"'; ?>></div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Unused Image Item Template -->
<script type="text/template" id="unused-image-template">
    <div class="hozio-image-card unused-item" data-attachment-id="{{id}}" data-id="{{id}}">
        <input type="checkbox" class="unused-checkbox card-checkbox" data-id="{{id}}">
        <div class="card-image-wrap">
            <img src="{{thumbnail}}" alt="" class="card-image" loading="lazy">
            <span class="card-type-badge">{{mime_type}}</span>
            {{#is_protected}}
            <span class="card-status-badge" style="background:rgba(16,185,129,0.8);"><span class="dashicons dashicons-shield" style="font-size:10px;width:10px;height:10px;color:#fff;"></span></span>
            {{/is_protected}}
        </div>
        <div class="card-body">
            <div class="card-title" title="{{title}}">{{title}}</div>
            <div class="card-size-line">{{file_size_formatted}}</div>
            <div style="font-size:10px;color:#64748b;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{filename}}</div>
            <div class="card-bottom-row">
                <button type="button" class="unused-protect-btn protect-btn {{#is_protected}}active{{/is_protected}}" data-id="{{id}}" title="<?php esc_attr_e('Protect', 'hozio-image-optimizer'); ?>">
                    <span class="dashicons dashicons-shield"></span>
                </button>
                <button type="button" class="unused-delete-btn delete-single-btn" data-id="{{id}}">
                    <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Delete', 'hozio-image-optimizer'); ?>
                </button>
            </div>
        </div>
    </div>
</script>

<script>
jQuery(document).ready(function($) {
    // ===== BACKUPS TAB FUNCTIONALITY =====

    // Highlight a specific backup card if ?highlight=ID is in URL
    var urlParams = new URLSearchParams(window.location.search);
    var highlightId = urlParams.get('highlight');
    if (highlightId) {
        var $target = $('.hozio-backup-item[data-attachment-id="' + highlightId + '"]');
        if ($target.length) {
            // Scroll to it
            $('html, body').animate({ scrollTop: $target.offset().top - 100 }, 500);
            // Highlight with pulsing animation
            $target.css({
                'box-shadow': '0 0 0 3px #f59e0b, 0 0 20px rgba(245,158,11,0.3)',
                'border-color': '#f59e0b'
            });
            // Remove highlight after 5 seconds
            setTimeout(function() {
                $target.css({ 'box-shadow': '', 'border-color': '' });
            }, 5000);
        }
    }

    // Click anywhere on backup card to toggle checkbox
    $(document).on('click', '.hozio-backup-item', function(e) {
        if ($(e.target).is('input') || $(e.target).closest('.restore-btn, .delete-backup-btn').length) return;
        var $cb = $(this).find('.backup-checkbox');
        $cb.prop('checked', !$cb.is(':checked')).trigger('change');
    });

    // Visual selection state for backup cards
    $(document).on('change', '.backup-checkbox', function() {
        $(this).closest('.hozio-backup-item').toggleClass('selected', $(this).is(':checked'));
    });

    // Backup search/filter/sort
    $('#backup-search').on('input', filterBackups);
    $('#backup-filter').on('change', filterBackups);
    $('#backup-sort').on('change', filterBackups);

    function filterBackups() {
        var search = ($('#backup-search').val() || '').toLowerCase();
        var filter = $('#backup-filter').val();
        var sort = $('#backup-sort').val();

        var $items = $('.hozio-backup-item');

        // Filter
        $items.each(function() {
            var $item = $(this);
            var title = $item.data('title') || '';
            var filename = $item.data('filename') || '';
            var changed = $item.data('changed');

            var matchSearch = !search || title.indexOf(search) !== -1 || filename.indexOf(search) !== -1;
            var matchFilter = filter === 'all' || (filter === 'changed' && changed == 1) || (filter === 'unchanged' && changed == 0);

            $item.toggle(matchSearch && matchFilter);
        });

        // Sort
        var $grid = $('.hozio-backups-grid');
        var $visible = $grid.children('.hozio-backup-item:visible').detach();
        $visible.sort(function(a, b) {
            if (sort === 'newest') return ($(b).data('date') || '').localeCompare($(a).data('date') || '');
            if (sort === 'oldest') return ($(a).data('date') || '').localeCompare($(b).data('date') || '');
            if (sort === 'name') return ($(a).data('title') || '').localeCompare($(b).data('title') || '');
            return 0;
        });
        $grid.prepend($visible);
    }

    // Restore button
    $('.restore-btn').on('click', function(e) {
        e.stopPropagation();
        var btn = $(this);
        var id = btn.data('id');
        var item = btn.closest('.hozio-backup-item');

        if (!confirm('<?php echo esc_js(__('Are you sure you want to restore this image to its original? This will overwrite the current optimized version.', 'hozio-image-optimizer')); ?>')) {
            return;
        }

        btn.prop('disabled', true).addClass('loading');
        btn.find('.dashicons').removeClass('dashicons-undo').addClass('dashicons-update spin');

        $.post(ajaxurl, {
            action: 'hozio_restore_image',
            nonce: hozioImageOptimizer.nonce,
            attachment_id: id
        }, function(response) {
            if (response.success) {
                item.addClass('restored');
                setTimeout(function() {
                    location.reload();
                }, 500);
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Error restoring image', 'hozio-image-optimizer')); ?>');
                btn.prop('disabled', false).removeClass('loading');
                btn.find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-undo');
            }
        }).fail(function() {
            alert('<?php echo esc_js(__('Error restoring image', 'hozio-image-optimizer')); ?>');
            btn.prop('disabled', false).removeClass('loading');
            btn.find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-undo');
        });
    });

    // Delete backup button
    $('.delete-backup-btn').on('click', function(e) {
        e.stopPropagation();
        var btn = $(this);
        var id = btn.data('id');
        var item = btn.closest('.hozio-backup-item');

        if (!confirm('<?php echo esc_js(__('Are you sure you want to delete this backup? You will not be able to restore the original image after this.', 'hozio-image-optimizer')); ?>')) {
            return;
        }

        btn.prop('disabled', true);
        btn.find('.dashicons').addClass('spin');

        $.post(ajaxurl, {
            action: 'hozio_delete_backup',
            nonce: hozioImageOptimizer.nonce,
            attachment_id: id
        }, function(response) {
            if (response.success) {
                item.addClass('removing');
                setTimeout(function() {
                    item.slideUp(300, function() {
                        $(this).remove();
                        if ($('.hozio-backups-grid .hozio-backup-item').length === 0) {
                            location.reload();
                        }
                    });
                }, 200);
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Error deleting backup', 'hozio-image-optimizer')); ?>');
                btn.prop('disabled', false);
                btn.find('.dashicons').removeClass('spin');
            }
        }).fail(function() {
            alert('<?php echo esc_js(__('Error deleting backup', 'hozio-image-optimizer')); ?>');
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('spin');
        });
    });

    // Cleanup button
    $('#cleanup-old-btn').on('click', function() {
        var btn = $(this);

        if (!confirm('<?php echo esc_js(__('This will delete all backups older than the retention period. This action cannot be undone. Continue?', 'hozio-image-optimizer')); ?>')) {
            return;
        }

        btn.prop('disabled', true).addClass('loading');
        btn.find('.dashicons').addClass('spin');

        $.post(ajaxurl, {
            action: 'hozio_cleanup_backups',
            nonce: hozioImageOptimizer.nonce
        }, function(response) {
            alert(response.data.message);
            location.reload();
        }).fail(function() {
            alert('<?php echo esc_js(__('Error cleaning up backups', 'hozio-image-optimizer')); ?>');
            btn.prop('disabled', false).removeClass('loading');
            btn.find('.dashicons').removeClass('spin');
        });
    });

    // Download all backups
    $('#download-all-btn').on('click', function() {
        var btn = $(this);
        var originalHtml = btn.html();

        btn.prop('disabled', true).addClass('loading');
        btn.html('<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Preparing...', 'hozio-image-optimizer')); ?>');

        $.post(ajaxurl, {
            action: 'hozio_download_all_backups',
            nonce: hozioImageOptimizer.nonce
        }, function(response) {
            if (response.success && response.data.download_url) {
                var link = document.createElement('a');
                link.href = response.data.download_url;
                link.download = response.data.filename || 'hozio-backups.zip';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                btn.prop('disabled', false).removeClass('loading').html(originalHtml);
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Failed to create backup archive', 'hozio-image-optimizer')); ?>');
                btn.prop('disabled', false).removeClass('loading').html(originalHtml);
            }
        }).fail(function() {
            alert('<?php echo esc_js(__('Failed to create backup archive', 'hozio-image-optimizer')); ?>');
            btn.prop('disabled', false).removeClass('loading').html(originalHtml);
        });
    });

    // Backup Select All checkbox
    $('#select-all-backups').on('change', function() {
        var isChecked = $(this).is(':checked');
        $('.backup-checkbox').prop('checked', isChecked);
        updateBackupSelection();
    });

    $(document).on('change', '.backup-checkbox', function() {
        updateBackupSelection();
    });

    function updateBackupSelection() {
        var total = $('.backup-checkbox').length;
        var checked = $('.backup-checkbox:checked').length;

        if (checked > 0) {
            $('#bulk-restore-btn').show();
            $('#backup-selection-info').show();
            $('#backup-selected-count').text(checked);
        } else {
            $('#bulk-restore-btn').hide();
            $('#backup-selection-info').hide();
        }

        // Update Select All text without changing checkbox state
        if (checked > 0 && checked === total) {
            $('#backup-select-all-text').text('Deselect All');
        } else {
            $('#backup-select-all-text').text('Select All');
        }
    }

    $('#bulk-restore-btn').on('click', function() {
        var btn = $(this);
        var ids = [];
        $('.backup-checkbox:checked').each(function() {
            ids.push($(this).data('id'));
        });

        if (ids.length === 0) return;

        if (!confirm('<?php echo esc_js(__('Are you sure you want to restore these images to their original state?', 'hozio-image-optimizer')); ?>')) {
            return;
        }

        btn.prop('disabled', true).addClass('loading');

        $.post(ajaxurl, {
            action: 'hozio_bulk_restore',
            nonce: hozioImageOptimizer.nonce,
            attachment_ids: ids
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Error restoring images', 'hozio-image-optimizer')); ?>');
                btn.prop('disabled', false).removeClass('loading');
            }
        });
    });

    // Compare button
    $('.compare-btn').on('click', function(e) {
        e.stopPropagation();
        var btn = $(this);
        var id = btn.data('id');

        btn.prop('disabled', true);
        btn.find('.dashicons').addClass('spin');

        $.post(ajaxurl, {
            action: 'hozio_get_image_comparison',
            nonce: hozioImageOptimizer.nonce,
            attachment_id: id
        }, function(response) {
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('spin');

            if (response.success) {
                showCompareModal(response.data);
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Could not load comparison', 'hozio-image-optimizer')); ?>');
            }
        });
    });

    function showCompareModal(data) {
        $('#hozio-compare-modal').remove();

        var modalHtml = '<div id="hozio-compare-modal" class="hozio-modal">' +
            '<div class="hozio-modal-overlay"></div>' +
            '<div class="hozio-modal-content compare-modal">' +
            '<button class="hozio-modal-close">&times;</button>' +
            '<h2><?php echo esc_js(__('Image Comparison', 'hozio-image-optimizer')); ?></h2>' +
            '<div class="compare-stats">' +
            '<div class="compare-stat"><span class="stat-label"><?php echo esc_js(__('Original Size', 'hozio-image-optimizer')); ?></span><span class="stat-value">' + data.original.size_formatted + '</span></div>' +
            '<div class="compare-stat"><span class="stat-label"><?php echo esc_js(__('Current Size', 'hozio-image-optimizer')); ?></span><span class="stat-value">' + data.current.size_formatted + '</span></div>' +
            '<div class="compare-stat highlight"><span class="stat-label"><?php echo esc_js(__('Saved', 'hozio-image-optimizer')); ?></span><span class="stat-value">' + data.savings.formatted + ' (' + data.savings.percent + '%)</span></div>' +
            '</div>' +
            '<div class="compare-images">' +
            '<div class="compare-image original"><h4><?php echo esc_js(__('Original', 'hozio-image-optimizer')); ?></h4>' + (data.original.url ? '<img src="' + data.original.url + '" alt="Original">' : '<p class="no-preview"><?php echo esc_js(__('Preview not available', 'hozio-image-optimizer')); ?></p>') + '</div>' +
            '<div class="compare-image current"><h4><?php echo esc_js(__('Optimized', 'hozio-image-optimizer')); ?></h4><img src="' + data.current.url + '" alt="Current"></div>' +
            '</div></div></div>';

        $('body').append(modalHtml);

        $('#hozio-compare-modal .hozio-modal-overlay, #hozio-compare-modal .hozio-modal-close').on('click', function() {
            $('#hozio-compare-modal').fadeOut(200, function() { $(this).remove(); });
        });

        $('#hozio-compare-modal').fadeIn(200);
    }

    // ===== UNUSED IMAGES TAB FUNCTIONALITY =====

    var unusedImages = [];

    // Load saved results on page load (if any exist)
    <?php
    $saved_unused = method_exists($unused_detector, 'get_saved_results') ? $unused_detector->get_saved_results() : null;
    if ($saved_unused && !empty($saved_unused['images'])) :
    ?>
    (function() {
        var savedResults = <?php echo wp_json_encode($saved_unused); ?>;
        if (savedResults && savedResults.images && savedResults.images.length > 0) {
            unusedImages = savedResults.images;
            $('#stat-unused-count').text(savedResults.total);
            $('#stat-potential-savings').text(formatBytes(savedResults.total_size));
            $('#unused-initial-state').hide();
            renderUnusedImages(savedResults.images);
            $('#unused-results-grid').show();
            $('#unused-bulk-actions').show();
        }
    })();
    <?php endif; ?>

    // Simulated progress for unused-image scan
    var unusedScanTimer = null;
    var unusedScanPct   = 0;
    var unusedScanLabels = [
        '<?php echo esc_js(__('Indexing attachments…', 'hozio-image-optimizer')); ?>',
        '<?php echo esc_js(__('Checking post content…', 'hozio-image-optimizer')); ?>',
        '<?php echo esc_js(__('Scanning widgets &amp; menus…', 'hozio-image-optimizer')); ?>',
        '<?php echo esc_js(__('Cross-referencing files…', 'hozio-image-optimizer')); ?>',
        '<?php echo esc_js(__('Almost done…', 'hozio-image-optimizer')); ?>'
    ];
    function startUnusedScanProgress() {
        unusedScanPct = 0;
        $('#unused-scan-progress-fill').css('width', '0%');
        $('#unused-scan-progress-label').text(unusedScanLabels[0]);
        unusedScanTimer = setInterval(function() {
            unusedScanPct += (88 - unusedScanPct) * 0.04;
            $('#unused-scan-progress-fill').css('width', unusedScanPct.toFixed(1) + '%');
            var labelIdx = Math.min(Math.floor(unusedScanPct / 20), unusedScanLabels.length - 1);
            $('#unused-scan-progress-label').text(unusedScanLabels[labelIdx]);
        }, 120);
    }
    function finishUnusedScanProgress(cb) {
        clearInterval(unusedScanTimer);
        $('#unused-scan-progress-fill').css('width', '100%');
        $('#unused-scan-progress-label').text('<?php echo esc_js(__('Done!', 'hozio-image-optimizer')); ?>');
        setTimeout(function() {
            $('#unused-scan-progress-fill').css('width', '0%');
            if (cb) { cb(); }
        }, 400);
    }

    // Scan for unused images
    $('#scan-unused-btn').on('click', function() {
        var btn = $(this);
        var originalHtml = btn.html();

        btn.prop('disabled', true);
        btn.html('<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Scanning...', 'hozio-image-optimizer')); ?>');

        $('#unused-initial-state').hide();
        $('#unused-clean-state').hide();
        $('#unused-results-grid').hide();
        $('#unused-scanning-state').show();
        startUnusedScanProgress();

        $.post(ajaxurl, {
            action: 'hozio_scan_unused_images',
            nonce: hozioImageOptimizer.nonce
        }, function(response) {
            btn.prop('disabled', false).html(originalHtml);
            finishUnusedScanProgress(function() {
                $('#unused-scanning-state').hide();

                if (response.success) {
                    unusedImages = response.data.images;

                    // Update stats
                    $('#stat-unused-count').text(response.data.total);
                    $('#stat-potential-savings').text(formatBytes(response.data.total_size));
                    $('#stat-protected').text(response.data.stats.protected_count);

                    if (response.data.total === 0) {
                        $('#unused-clean-state').show();
                        $('#unused-bulk-actions').hide();
                    } else {
                        renderUnusedImages(response.data.images);
                        $('#unused-results-grid').show();
                        $('#unused-bulk-actions').show();
                    }
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Error scanning images', 'hozio-image-optimizer')); ?>');
                    $('#unused-initial-state').show();
                }
            });
        }).fail(function() {
            btn.prop('disabled', false).html(originalHtml);
            clearInterval(unusedScanTimer);
            $('#unused-scanning-state').hide();
            $('#unused-initial-state').show();
            alert('<?php echo esc_js(__('Error scanning images', 'hozio-image-optimizer')); ?>');
        });
    });

    function renderUnusedImages(images) {
        var template = $('#unused-image-template').html();
        var grid = $('#unused-results-grid');
        grid.empty();

        images.forEach(function(image) {
            var html = template
                .replace(/\{\{id\}\}/g, image.id)
                .replace(/\{\{title\}\}/g, image.title || image.filename)
                .replace(/\{\{filename\}\}/g, image.filename)
                .replace(/\{\{thumbnail\}\}/g, image.thumbnail || '')
                .replace(/\{\{file_size_formatted\}\}/g, image.file_size_formatted)
                .replace(/\{\{total_size_formatted\}\}/g, image.total_size_formatted)
                .replace(/\{\{mime_type\}\}/g, image.mime_type);

            // Handle protected status
            if (image.is_protected) {
                html = html.replace(/\{\{#is_protected\}\}([\s\S]*?)\{\{\/is_protected\}\}/g, '$1');
            } else {
                html = html.replace(/\{\{#is_protected\}\}[\s\S]*?\{\{\/is_protected\}\}/g, '');
            }

            grid.append(html);
        });
    }

    // Select all unused (skip protected images)
    $('#select-all-unused').on('click', function() {
        var $checkboxes = $('.unused-checkbox:visible');
        var $unprotected = $checkboxes.filter(function() {
            return !$(this).closest('.unused-item').find('.protect-btn').hasClass('active');
        });
        var allChecked = $unprotected.filter(':checked').length === $unprotected.length;
        $unprotected.prop('checked', !allChecked);
        updateUnusedBulkButtons();
    });

    $(document).on('change', '.unused-checkbox', function() {
        // Toggle visual selection on card
        $(this).closest('.unused-item').toggleClass('selected-unused', $(this).is(':checked'));
        updateUnusedBulkButtons();
    });

    function updateUnusedBulkButtons() {
        var checked = $('.unused-checkbox:checked').length;
        if (checked > 0) {
            $('#delete-selected-btn').show().find('.bulk-count-badge').text(checked);
            $('#protect-selected-btn').show();
            $('#download-selected-btn').show();
        } else {
            $('#delete-selected-btn').hide();
            $('#protect-selected-btn').hide();
            $('#download-selected-btn').hide();
        }
    }

    // Delete selected unused images
    $('#delete-selected-btn').on('click', function() {
        var ids = [];
        $('.unused-checkbox:checked').each(function() {
            ids.push($(this).data('id'));
        });

        if (ids.length === 0) return;

        if (!confirm('<?php echo esc_js(__('Are you sure you want to permanently delete these images? A backup ZIP will be downloaded first.', 'hozio-image-optimizer')); ?>')) {
            return;
        }

        deleteImages(ids);
    });

    // Delete single image
    $(document).on('click', '.delete-single-btn', function() {
        var id = $(this).data('id');

        if (!confirm('<?php echo esc_js(__('Are you sure you want to permanently delete this image? A backup ZIP will be downloaded first.', 'hozio-image-optimizer')); ?>')) {
            return;
        }

        deleteImages([id]);
    });

    function deleteImages(ids) {
        var btn = $('#delete-selected-btn');
        btn.prop('disabled', true);
        btn.find('.dashicons').removeClass('dashicons-trash').addClass('dashicons-update spin');

        $.post(ajaxurl, {
            action: 'hozio_delete_unused_images',
            nonce: hozioImageOptimizer.nonce,
            image_ids: ids
        }, function(response) {
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-trash');

            if (response.success) {
                // Trigger download
                if (response.data.download_url) {
                    var link = document.createElement('a');
                    link.href = response.data.download_url;
                    link.download = response.data.archive.filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }

                // Remove deleted items from grid
                ids.forEach(function(id) {
                    $('.unused-item[data-attachment-id="' + id + '"]').slideUp(300, function() {
                        $(this).remove();
                        if ($('.unused-item').length === 0) {
                            $('#unused-results-grid').hide();
                            $('#unused-clean-state').show();
                            $('#unused-bulk-actions').hide();
                        }
                    });
                });

                // Update stats
                var remaining = parseInt($('#stat-unused-count').text()) - response.data.deleted.deleted_count;
                $('#stat-unused-count').text(remaining);

                alert('<?php echo esc_js(__('Images deleted successfully. Backup ZIP downloaded.', 'hozio-image-optimizer')); ?>');
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Error deleting images', 'hozio-image-optimizer')); ?>');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-trash');
            alert('<?php echo esc_js(__('Error deleting images', 'hozio-image-optimizer')); ?>');
        });
    }

    // Download ZIP of selected unused images (without deleting)
    $('#download-selected-btn').on('click', function() {
        var ids = [];
        $('.unused-checkbox:checked').each(function() {
            ids.push($(this).data('id'));
        });

        if (ids.length === 0) return;

        var btn = $(this);
        btn.prop('disabled', true);
        btn.find('.dashicons').removeClass('dashicons-download').addClass('dashicons-update spin');

        $.post(ajaxurl, {
            action: 'hozio_download_unused_images',
            nonce: hozioImageOptimizer.nonce,
            image_ids: ids
        }, function(response) {
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-download');

            if (response.success && response.data.download_url) {
                var link = document.createElement('a');
                link.href = response.data.download_url;
                link.download = response.data.filename || 'unused-images.zip';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } else {
                alert(response.data.message || 'Error creating ZIP');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-download');
            alert('Error creating ZIP');
        });
    });

    // Toggle protection
    // Click anywhere on unused card to toggle checkbox
    $(document).on('click', '.unused-item', function(e) {
        if ($(e.target).is('input') || $(e.target).closest('.protect-btn, .delete-single-btn, .view-refs-btn').length) return;
        var $cb = $(this).find('.unused-checkbox');
        $cb.prop('checked', !$cb.is(':checked')).trigger('change');
    });

    $(document).on('click', '.protect-btn', function(e) {
        e.stopPropagation();
        var btn = $(this);
        var id = btn.data('id');

        btn.prop('disabled', true);
        btn.find('.dashicons').addClass('spin');

        $.post(ajaxurl, {
            action: 'hozio_toggle_image_protection',
            nonce: hozioImageOptimizer.nonce,
            attachment_id: id
        }, function(response) {
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('spin');

            if (response.success) {
                var item = btn.closest('.unused-item');
                if (response.data.is_protected) {
                    btn.addClass('active');
                    // Add shield badge to card image area
                    if (item.find('.card-status-badge').length === 0) {
                        item.find('.card-image-wrap').append('<span class="card-status-badge" style="background:rgba(16,185,129,0.8);"><span class="dashicons dashicons-shield" style="font-size:10px;width:10px;height:10px;color:#fff;"></span></span>');
                    }
                } else {
                    btn.removeClass('active');
                    item.find('.card-status-badge').remove();
                }
            }
        });
    });

    // Restore from ZIP
    $('#restore-zip-input').on('change', function() {
        var file = this.files[0];
        if (!file) return;

        if (!confirm('<?php echo esc_js(__('This will restore all images from the ZIP archive. Continue?', 'hozio-image-optimizer')); ?>')) {
            $(this).val('');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'hozio_restore_from_zip');
        formData.append('nonce', hozioImageOptimizer.nonce);
        formData.append('archive', file);

        var btn = $('#restore-zip-btn');
        var originalHtml = btn.html();
        btn.html('<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Restoring...', 'hozio-image-optimizer')); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                btn.html(originalHtml);
                $('#restore-zip-input').val('');

                if (response.success) {
                    alert('<?php echo esc_js(__('Restored', 'hozio-image-optimizer')); ?> ' + response.data.restored_count + ' <?php echo esc_js(__('images successfully!', 'hozio-image-optimizer')); ?>');
                    location.reload();
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Error restoring images', 'hozio-image-optimizer')); ?>');
                }
            },
            error: function() {
                btn.html(originalHtml);
                $('#restore-zip-input').val('');
                alert('<?php echo esc_js(__('Error restoring images', 'hozio-image-optimizer')); ?>');
            }
        });
    });

    // ===== BROKEN IMAGES TAB FUNCTIONALITY =====

    // Load saved broken results on page load
    <?php
    if (isset($saved_broken) && $saved_broken && !empty($saved_broken['total_broken']) && $saved_broken['total_broken'] > 0) :
    ?>
    (function() {
        var savedBroken = <?php echo wp_json_encode($saved_broken); ?>;
        if (savedBroken && savedBroken.total_broken > 0) {
            renderBrokenImages(savedBroken);
        }
    })();
    <?php endif; ?>

    // Scan for broken images
    $('#scan-broken-btn').on('click', function() {
        var btn = $(this);
        var originalHtml = btn.html();
        btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Scanning...', 'hozio-image-optimizer')); ?>');

        $('#broken-initial-state').hide();
        $('#broken-clean-state').hide();
        $('#broken-results-list').hide();
        $('#broken-scanning-state').show();

        $.post(ajaxurl, {
            action: 'hozio_scan_broken_images',
            nonce: hozioImageOptimizer.nonce
        }, function(response) {
            btn.prop('disabled', false).html(originalHtml);
            $('#broken-scanning-state').hide();

            if (response.success) {
                var data = response.data;
                $('#stat-broken-total').text(data.total_broken);
                $('#stat-broken-locations').text(data.total_locations);
                $('#stat-broken-attachments').text(data.broken_attachments.length);
                $('#stat-broken-urls').text(data.broken_content_urls.length);
                $('#broken-last-scan').text('Last scan: Just now');

                if (data.total_broken === 0) {
                    $('#broken-clean-state').show();
                } else {
                    renderBrokenImages(data);
                    $('#broken-results-list').show();
                }
            } else {
                alert(response.data.message || 'Error scanning');
                $('#broken-initial-state').show();
            }
        }).fail(function() {
            btn.prop('disabled', false).html(originalHtml);
            $('#broken-scanning-state').hide();
            $('#broken-initial-state').show();
            alert('Error scanning for broken images');
        });
    });

    function renderBrokenImages(data) {
        var list = $('#broken-results-list');
        list.empty();

        var allBroken = (data.broken_attachments || []).concat(data.broken_content_urls || []);

        allBroken.forEach(function(item) {
            var opts = item.resolution_options || [];
            var typeLabel = item.type === 'attachment' ? 'Missing File' : 'Broken URL';

            var viewUrl = (item.locations && item.locations.length > 0 && item.locations[0].view_url) ? item.locations[0].view_url : '';
            var html = '<div class="brk-row" data-url="' + escapeHtml(item.url) + '" data-id="' + (item.attachment_id || 0) + '" data-view-url="' + escapeHtml(viewUrl) + '">';

            // Left: icon + info
            html += '<div class="brk-main">';
            html += '<div class="brk-icon"><span class="dashicons dashicons-warning"></span></div>';
            html += '<div class="brk-info">';
            html += '<div class="brk-name">' + escapeHtml(item.filename || item.title) + '</div>';
            html += '<div class="brk-url">' + escapeHtml(item.url) + '</div>';
            html += '<span class="brk-type-badge">' + typeLabel + '</span>';
            html += '</div>';
            html += '</div>';

            // Center: locations
            html += '<div class="brk-locations">';
            (item.locations || []).slice(0, 3).forEach(function(loc) {
                html += '<a href="' + escapeHtml(loc.edit_url) + '" class="brk-loc-link" target="_blank">';
                html += '<span class="dashicons dashicons-edit" style="font-size:11px;width:11px;height:11px;"></span> ';
                html += escapeHtml(loc.post_title);
                html += ' <span class="brk-loc-type">' + escapeHtml(loc.post_type) + '</span>';
                html += '</a>';
            });
            if ((item.locations || []).length > 3) {
                html += '<span class="brk-loc-more">+' + (item.locations.length - 3) + ' more</span>';
            }
            html += '</div>';

            // Right: actions
            html += '<div class="brk-actions">';
            if (opts.indexOf('restore_backup') !== -1) {
                html += '<button type="button" class="brk-btn brk-btn-fix resolve-btn" data-strategy="restore_backup">Restore Backup</button>';
            }
            if (opts.indexOf('update_references') !== -1) {
                html += '<button type="button" class="brk-btn brk-btn-fix resolve-btn" data-strategy="update_references">Fix URLs</button>';
            }
            if (item.has_backup && item.attachment_id) {
                html += '<a href="<?php echo esc_url(admin_url('upload.php?page=hozio-image-backups&tab=backups')); ?>&highlight=' + item.attachment_id + '" class="brk-btn brk-btn-backup">View Backup</a>';
            }
            html += '<button type="button" class="brk-btn brk-btn-replace replace-image-btn">Replace</button>';
            if (item.locations && item.locations.length > 0) {
                html += '<a href="' + escapeHtml(item.locations[0].edit_url) + '" class="brk-btn brk-btn-edit" target="_blank">Edit Post</a>';
            }
            html += '</div>';

            html += '</div>';
            list.append(html);
        });
    }

    // Resolve broken image
    $(document).on('click', '.resolve-btn', function() {
        var btn = $(this);
        var item = btn.closest('.brk-row');
        var strategy = btn.data('strategy');
        var brokenUrl = item.data('url');
        var attachmentId = item.data('id');

        btn.prop('disabled', true).text('Fixing...');

        $.post(ajaxurl, {
            action: 'hozio_resolve_broken_image',
            nonce: hozioImageOptimizer.nonce,
            attachment_id: attachmentId,
            broken_url: brokenUrl,
            strategy: strategy
        }, function(response) {
            btn.prop('disabled', false);

            if (response.success) {
                item.slideUp(300, function() {
                    $(this).remove();
                    var remaining = $('.brk-row').length;
                    $('#stat-broken-total').text(remaining);
                    if (remaining === 0) {
                        $('#broken-results-list').hide();
                        $('#broken-clean-state').show();
                    }
                });
            } else {
                alert(response.data.error || response.data.message || 'Error resolving');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('spin');
            alert('Error resolving broken image');
        });
    });

    // Replace image - open WordPress media library picker
    $(document).on('click', '.replace-image-btn', function() {
        var item = $(this).closest('.brk-row');
        var brokenUrl = item.data('url');
        var attachmentId = item.data('id');

        var frame = wp.media({
            title: '<?php echo esc_js(__('Choose Replacement Image', 'hozio-image-optimizer')); ?>',
            button: { text: '<?php echo esc_js(__('Use This Image', 'hozio-image-optimizer')); ?>' },
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            var newUrl = attachment.url;
            var thumbUrl = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

            // Show fixing state on the row
            item.addClass('brk-fixing');
            item.find('.brk-actions').html('<span style="font-size:11px;color:#6b7280;"><span class="dashicons dashicons-update spin" style="font-size:14px;width:14px;height:14px;margin-right:4px;"></span> Replacing...</span>');

            $.post(ajaxurl, {
                action: 'hozio_resolve_broken_image',
                nonce: hozioImageOptimizer.nonce,
                attachment_id: attachmentId,
                broken_url: brokenUrl,
                strategy: 'replace_image',
                new_url: newUrl
            }, function(response) {
                if (response.success) {
                    // Show success state with preview of new image
                    item.removeClass('brk-fixing').addClass('brk-fixed');
                    item.find('.brk-icon').html('<span class="dashicons dashicons-yes-alt" style="color:#22c55e;font-size:18px;width:18px;height:18px;"></span>');
                    item.find('.brk-name').text('Fixed!');
                    item.find('.brk-url').text(newUrl).css('color', '#22c55e');
                    item.find('.brk-type-badge').text('Replaced').css({'background':'#dcfce7','color':'#16a34a'});

                    // Show new image thumbnail + verification links
                    var verifyHtml = '<div class="brk-fixed-preview">';
                    verifyHtml += '<img src="' + thumbUrl + '" style="width:36px;height:36px;border-radius:4px;object-fit:cover;">';
                    verifyHtml += '<span style="font-size:11px;color:#16a34a;font-weight:600;">Image replaced in ' + (response.data.updates || 0) + ' location(s)</span>';
                    verifyHtml += '</div>';
                    item.find('.brk-locations').html(verifyHtml);

                    // Show view page links
                    var pageUrl = item.data('view-url') || '#';
                    item.find('.brk-actions').html('<a href="' + pageUrl + '" target="_blank" class="brk-btn" style="background:#22c55e;color:#fff;border-color:#22c55e;">View Page</a>');

                    // Auto-remove after 5 seconds
                    setTimeout(function() {
                        item.slideUp(300, function() {
                            $(this).remove();
                            var remaining = $('.brk-row:not(.brk-fixed)').length;
                            $('#stat-broken-total').text(remaining);
                            if (remaining === 0 && $('.brk-row').length === 0) {
                                $('#broken-results-list').hide();
                                $('#broken-clean-state').show();
                            }
                        });
                    }, 5000);
                } else {
                    item.removeClass('brk-fixing');
                    item.find('.brk-actions').html('<span style="font-size:11px;color:#ef4444;">Failed: ' + (response.data.error || 'Unknown error') + '</span>');
                }
            }).fail(function() {
                item.removeClass('brk-fixing');
                alert('Error replacing image');
            });
        });

        frame.open();
    });

    // Helper functions
    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
});
</script>

<style>
/* New Tab Navigation */
.hz-tab-nav {
    display: flex;
    gap: 2px;
    margin-bottom: 16px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 3px;
    width: fit-content;
}
.hz-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 16px;
    text-decoration: none;
    color: #6b7280;
    font-size: 12px;
    font-weight: 600;
    border-radius: 6px;
    transition: all 0.12s;
}
.hz-tab:hover {
    color: #111827;
    background: #f3f4f6;
}
.hz-tab.active {
    background: #111827;
    color: #fff;
}
.hz-tab-count {
    background: rgba(255,255,255,0.2);
    padding: 1px 6px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 700;
}
.hz-tab:not(.active) .hz-tab-count {
    background: #e5e7eb;
    color: #6b7280;
}
.hz-tab-count-red {
    background: #fef2f2 !important;
    color: #ef4444 !important;
}
.hz-tab.active .hz-tab-count-red {
    background: rgba(239,68,68,0.3) !important;
    color: #fff !important;
}

/* Content card */
.hz-content-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
}

/* Old tabs hidden */
.hozio-tabs { display: none; }
.hozio-tab.active { background: #111827; color: #fff;
}
.hozio-tab .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}
.tab-count {
    background: rgba(255,255,255,0.2);
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
}
.hozio-tab.active .tab-count {
    background: rgba(255,255,255,0.3);
}

/* Hide old elements */
.hozio-backups-header { display: none; }
.hozio-stats-grid { display: none; }
.hozio-card-header { display: none; }
.hozio-card { border: none; box-shadow: none; background: transparent; }
.hozio-card-body.no-padding { padding: 0; }

/* Actions Bar */
.hozio-actions-bar {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

/* Danger button */
.hozio-btn-danger {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    border: none;
}
.hozio-btn-danger:hover {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    color: #fff;
}

/* Protected badge */
.backup-badge.protected {
    background: linear-gradient(135deg, #10b981, #059669);
}
.protect-btn.active {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
    border-color: #10b981;
}

/* Unused item specific */
.unused-item .unused-checkbox {
    position: absolute;
    top: 10px;
    left: 10px;
    z-index: 10;
    width: 18px;
    height: 18px;
}

/* Stat card warning color */
.stat-icon.warning {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}
.stat-icon.protected {
    background: linear-gradient(135deg, #10b981, #059669);
}
.stat-icon.cleanup {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}

/* Card header actions */
.card-header-actions {
    display: flex;
    gap: 10px;
    margin-left: auto;
}

/* Backups toolbar */
.backups-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--hozio-border-light);
    background: #fafbfc;
}

.backup-search-input {
    padding: 5px 10px;
    border: 1px solid var(--hozio-border);
    border-radius: 6px;
    font-size: 12px;
    width: 180px;
    transition: border-color 0.15s;
}

.backup-search-input:focus {
    border-color: var(--hozio-primary);
    outline: none;
}

.backups-toolbar select {
    padding: 5px 10px;
    border: 1px solid var(--hozio-border);
    border-radius: 6px;
    font-size: 12px;
    background: #fff;
}

/* Bulk actions bar */
.unused-bulk-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: #f8fafc;
    border-bottom: 1px solid var(--hozio-border-light);
    flex-wrap: wrap;
}

.unused-bulk-bar .btn-sm {
    padding: 5px 12px;
    font-size: 12px;
}

.unused-bulk-bar .btn-sm .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
}

/* Scanning animation */
.empty-icon.scanning {
    animation: pulse 1.5s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Scan progress bar */
.scan-progress {
    width: 200px;
    height: 4px;
    background: #e2e8f0;
    border-radius: 2px;
    margin-top: 15px;
    overflow: hidden;
}
.scan-progress-bar {
    width: 30%;
    height: 100%;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border-radius: 2px;
    animation: progress 1.5s ease-in-out infinite;
}
@keyframes progress {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(400%); }
}

/* Success empty state */
.hozio-empty-state.success .empty-icon {
    background: linear-gradient(135deg, #10b981, #059669);
}

/* Last scan info */
.last-scan-info {
    margin-top: 10px;
    font-size: 13px;
    color: #94a3b8;
}

/* Compare Modal */
.hozio-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 100000;
}
.hozio-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.7);
}
.hozio-modal-content {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    border-radius: 16px;
    padding: 30px;
    max-width: 900px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
}
.hozio-modal-close {
    position: absolute;
    top: 15px;
    right: 15px;
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #64748b;
    padding: 5px 10px;
    border-radius: 4px;
}
.hozio-modal-close:hover {
    background: #f1f5f9;
    color: #1e293b;
}
.compare-modal h2 {
    margin: 0 0 20px;
    font-size: 20px;
    font-weight: 600;
}
.compare-stats {
    display: flex;
    gap: 15px;
    margin-bottom: 25px;
}
.compare-stat {
    flex: 1;
    background: #f8fafc;
    padding: 15px;
    border-radius: 10px;
    text-align: center;
}
.compare-stat.highlight {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
}
.compare-stat .stat-label {
    display: block;
    font-size: 12px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
}
.compare-stat.highlight .stat-label {
    color: rgba(255,255,255,0.8);
}
.compare-stat .stat-value {
    display: block;
    font-size: 18px;
    font-weight: 600;
}
.compare-images {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.compare-image {
    text-align: center;
}
.compare-image h4 {
    margin: 0 0 10px;
    font-size: 14px;
    color: #64748b;
    text-transform: uppercase;
}
.compare-image img {
    max-width: 100%;
    max-height: 400px;
    border-radius: 8px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
}
.compare-image .no-preview {
    padding: 60px 20px;
    background: #f8fafc;
    border-radius: 8px;
    color: #94a3b8;
}
.bulk-count-badge {
    background: #fff;
    color: #6366f1;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 600;
    margin-left: 5px;
}
.hozio-btn-danger .bulk-count-badge {
    background: rgba(255,255,255,0.2);
    color: #fff;
}

/* ===== Empty States ===== */
.hz-empty-state {
    padding: 48px 24px;
    text-align: center;
}

.hz-empty-icon {
    font-size: 32px;
    color: #d1d5db;
    margin-bottom: 12px;
}

.hz-empty-icon .dashicons {
    font-size: 32px;
    width: 32px;
    height: 32px;
}

.hz-empty-title {
    font-size: 15px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 4px;
}

.hz-empty-desc {
    font-size: 12px;
    color: #9ca3af;
    max-width: 400px;
    margin: 0 auto;
}

/* ===== Broken Images (Redesigned) ===== */
.brk-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 20px;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.1s;
}

.brk-row:hover {
    background: #fafbfc;
}

.brk-row:last-child {
    border-bottom: none;
}

.brk-main {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
}

.brk-icon {
    width: 36px;
    height: 36px;
    background: #fef2f2;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.brk-icon .dashicons {
    color: #ef4444;
    font-size: 18px;
    width: 18px;
    height: 18px;
}

.brk-info {
    min-width: 0;
}

.brk-name {
    font-size: 12px;
    font-weight: 600;
    color: #111827;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 280px;
}

.brk-url {
    font-size: 10px;
    color: #ef4444;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 280px;
    margin-top: 1px;
}

.brk-type-badge {
    display: inline-block;
    font-size: 9px;
    font-weight: 600;
    color: #9ca3af;
    background: #f3f4f6;
    padding: 1px 6px;
    border-radius: 3px;
    margin-top: 3px;
}

.brk-locations {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    flex: 1;
    min-width: 150px;
}

.brk-loc-link {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 11px;
    font-weight: 500;
    color: #4f46e5;
    text-decoration: none;
    padding: 3px 8px;
    background: #f5f3ff;
    border-radius: 4px;
    transition: background 0.1s;
}

.brk-loc-link:hover {
    background: #ede9fe;
    color: #4338ca;
}

.brk-loc-type {
    font-size: 9px;
    color: #9ca3af;
    text-transform: uppercase;
    font-weight: 600;
}

.brk-loc-more {
    font-size: 10px;
    color: #9ca3af;
    padding: 3px 6px;
}

.brk-actions {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}

.brk-btn {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid #e5e7eb;
    border-radius: 5px;
    background: #fff;
    color: #374151;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.1s;
}

.brk-btn:hover {
    background: #f3f4f6;
    border-color: #d1d5db;
}

.brk-btn-fix {
    background: #4f46e5;
    color: #fff;
    border-color: #4f46e5;
}

.brk-btn-fix:hover {
    background: #4338ca;
    color: #fff;
}

.brk-btn-replace {
    color: #6b7280;
}

.brk-btn-backup {
    background: #fffbeb;
    border-color: #fde68a;
    color: #b45309;
    text-decoration: none;
}

.brk-btn-backup:hover {
    background: #fef3c7;
    border-color: #fbbf24;
    color: #92400e;
}

.brk-btn-edit {
    color: #6b7280;
    text-decoration: none;
}

/* Broken row states */
.brk-fixing {
    opacity: 0.7;
    pointer-events: none;
}

.brk-fixed {
    background: #f0fdf4 !important;
    border-left: 3px solid #22c55e;
}

.brk-fixed-preview {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Make location links more obviously clickable */
.brk-loc-link {
    border: 1px solid #e0e7ff;
}

.brk-loc-link:hover {
    border-color: #c7d2fe;
    box-shadow: 0 1px 3px rgba(79,70,229,0.1);
}

@media (max-width: 960px) {
    .brk-row {
        flex-direction: column;
        align-items: flex-start;
    }
    .brk-locations, .brk-actions {
        width: 100%;
    }
}
</style>
