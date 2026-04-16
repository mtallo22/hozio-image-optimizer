<?php
/**
 * WordPress Dashboard Widget for Hozio Image Optimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Dashboard_Widget {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
    }

    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        if (!current_user_can('upload_files')) {
            return;
        }

        wp_add_dashboard_widget(
            'hozio_image_optimizer_widget',
            __('Image Optimizer Stats', 'hozio-image-optimizer'),
            array($this, 'render_widget'),
            null,
            null,
            'normal',
            'high'
        );
    }

    /**
     * Render widget content
     */
    public function render_widget() {
        global $wpdb;

        // Get statistics
        $total_images = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image/%'"
        );

        $total_processed = get_option('hozio_total_images_processed', 0);
        $total_saved = get_option('hozio_total_bytes_saved', 0);
        $api_configured = !empty(get_option('hozio_openai_api_key', ''));

        // Get backup stats
        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        $backup_stats = $backup_manager->get_backup_stats();

        // Calculate percentage optimized
        $percent_optimized = $total_images > 0 ? round(($total_processed / $total_images) * 100) : 0;
        ?>
        <style>
            .hozio-widget-stats {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
                margin-bottom: 15px;
            }
            .hozio-widget-stat {
                background: #f6f7f7;
                padding: 15px;
                border-radius: 8px;
                text-align: center;
            }
            .hozio-widget-stat .stat-value {
                font-size: 24px;
                font-weight: 700;
                color: #1d2327;
                display: block;
            }
            .hozio-widget-stat .stat-label {
                font-size: 12px;
                color: #646970;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .hozio-widget-stat.highlight {
                background: linear-gradient(135deg, #6366f1, #8b5cf6);
                color: #fff;
            }
            .hozio-widget-stat.highlight .stat-value,
            .hozio-widget-stat.highlight .stat-label {
                color: #fff;
            }
            .hozio-widget-progress {
                background: #e2e4e7;
                border-radius: 10px;
                height: 8px;
                margin-bottom: 15px;
                overflow: hidden;
            }
            .hozio-widget-progress-bar {
                background: linear-gradient(90deg, #6366f1, #8b5cf6);
                height: 100%;
                border-radius: 10px;
                transition: width 0.3s ease;
            }
            .hozio-widget-actions {
                display: flex;
                gap: 10px;
            }
            .hozio-widget-actions a {
                flex: 1;
                text-align: center;
                padding: 8px 12px;
                background: #f6f7f7;
                border-radius: 6px;
                text-decoration: none;
                color: #1d2327;
                font-size: 13px;
                transition: all 0.2s;
            }
            .hozio-widget-actions a:hover {
                background: #6366f1;
                color: #fff;
            }
            .hozio-widget-actions a.primary {
                background: #6366f1;
                color: #fff;
            }
            .hozio-widget-actions a.primary:hover {
                background: #4f46e5;
            }
            .hozio-widget-status {
                margin-top: 15px;
                padding: 10px;
                border-radius: 6px;
                font-size: 13px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .hozio-widget-status.success {
                background: #d1fae5;
                color: #065f46;
            }
            .hozio-widget-status.warning {
                background: #fef3c7;
                color: #92400e;
            }
        </style>

        <div class="hozio-widget-stats">
            <div class="hozio-widget-stat">
                <span class="stat-value"><?php echo esc_html(number_format($total_images)); ?></span>
                <span class="stat-label"><?php esc_html_e('Total Images', 'hozio-image-optimizer'); ?></span>
            </div>
            <div class="hozio-widget-stat">
                <span class="stat-value"><?php echo esc_html(number_format($total_processed)); ?></span>
                <span class="stat-label"><?php esc_html_e('Optimized', 'hozio-image-optimizer'); ?></span>
            </div>
            <div class="hozio-widget-stat highlight">
                <span class="stat-value"><?php echo esc_html(Hozio_Image_Optimizer_Helpers::format_bytes($total_saved)); ?></span>
                <span class="stat-label"><?php esc_html_e('Space Saved', 'hozio-image-optimizer'); ?></span>
            </div>
            <div class="hozio-widget-stat">
                <span class="stat-value"><?php echo esc_html($backup_stats['total_backups']); ?></span>
                <span class="stat-label"><?php esc_html_e('Backups', 'hozio-image-optimizer'); ?></span>
            </div>
        </div>

        <div class="hozio-widget-progress">
            <div class="hozio-widget-progress-bar" style="width: <?php echo esc_attr($percent_optimized); ?>%;"></div>
        </div>
        <p style="text-align: center; margin: 0 0 15px; color: #646970; font-size: 13px;">
            <?php printf(
                esc_html__('%d%% of images optimized', 'hozio-image-optimizer'),
                $percent_optimized
            ); ?>
        </p>

        <div class="hozio-widget-actions">
            <a href="<?php echo esc_url(admin_url('upload.php?page=hozio-image-optimizer')); ?>" class="primary">
                <?php esc_html_e('Optimize Images', 'hozio-image-optimizer'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=hozio-image-optimizer-settings')); ?>">
                <?php esc_html_e('Settings', 'hozio-image-optimizer'); ?>
            </a>
        </div>

        <?php if ($api_configured) : ?>
            <div class="hozio-widget-status success">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php esc_html_e('AI features enabled', 'hozio-image-optimizer'); ?>
            </div>
        <?php else : ?>
            <div class="hozio-widget-status warning">
                <span class="dashicons dashicons-warning"></span>
                <?php esc_html_e('Configure API key to enable AI features', 'hozio-image-optimizer'); ?>
            </div>
        <?php endif; ?>
        <?php
    }
}
