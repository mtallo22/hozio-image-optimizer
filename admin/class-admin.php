<?php
/**
 * Admin menu and page registration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'register_menus'));
        add_filter('plugin_action_links_' . HOZIO_IMAGE_OPTIMIZER_BASENAME, array($this, 'add_action_links'));
    }

    /**
     * Register admin menus
     */
    public function register_menus() {
        // Main optimizer page under Media
        add_media_page(
            __('Hozio Image Optimizer', 'hozio-image-optimizer'),
            __('Image Optimizer', 'hozio-image-optimizer'),
            'upload_files',
            'hozio-image-optimizer',
            array($this, 'render_optimizer_page')
        );

        // Backups page under Media
        add_media_page(
            __('Image Backups', 'hozio-image-optimizer'),
            __('Image Backups', 'hozio-image-optimizer'),
            'upload_files',
            'hozio-image-backups',
            array($this, 'render_backups_page')
        );

        // Settings page
        add_options_page(
            __('Hozio Image Optimizer Settings', 'hozio-image-optimizer'),
            __('Image Optimizer', 'hozio-image-optimizer'),
            'manage_options',
            'hozio-image-optimizer-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Add action links to plugins page
     */
    public function add_action_links($links) {
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=hozio-image-optimizer-settings')) . '">' .
            __('Settings', 'hozio-image-optimizer') . '</a>';

        $optimizer_link = '<a href="' . esc_url(admin_url('upload.php?page=hozio-image-optimizer')) . '">' .
            __('Optimize Images', 'hozio-image-optimizer') . '</a>';

        array_unshift($links, $settings_link, $optimizer_link);

        return $links;
    }

    /**
     * Render main optimizer page
     */
    public function render_optimizer_page() {
        include HOZIO_IMAGE_OPTIMIZER_DIR . 'admin/views/bulk-optimizer.php';
    }

    /**
     * Render backups page
     */
    public function render_backups_page() {
        include HOZIO_IMAGE_OPTIMIZER_DIR . 'admin/views/backups.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        include HOZIO_IMAGE_OPTIMIZER_DIR . 'admin/views/settings.php';
    }

    /**
     * Get statistics for dashboard
     */
    public static function get_statistics() {
        global $wpdb;

        // Total images
        $total_images = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image/%'"
        );

        // Optimization stats
        $total_processed = get_option('hozio_total_images_processed', 0);
        $total_saved = get_option('hozio_total_bytes_saved', 0);

        // Backup stats
        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        $backup_stats = $backup_manager->get_backup_stats();

        // Server capabilities
        $capabilities = Hozio_Image_Optimizer::get_server_capabilities();

        return array(
            'total_images' => $total_images,
            'images_processed' => $total_processed,
            'bytes_saved' => $total_saved,
            'bytes_saved_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($total_saved),
            'backup_stats' => $backup_stats,
            'capabilities' => $capabilities,
            'api_configured' => !empty(get_option('hozio_openai_api_key', '')),
        );
    }
}
