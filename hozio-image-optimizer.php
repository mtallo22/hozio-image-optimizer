<?php
/**
 * Plugin Name: Hozio Image Optimizer
 * Description: AI-powered image optimization with smart compression, WebP/AVIF conversion, AI renaming, alt text generation, and bulk processing.
 * Version: 1.6.7
 * Author: Hozio
 * Author URI: https://hozio.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hozio-image-optimizer
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('HOZIO_IMAGE_OPTIMIZER_VERSION', '1.6.7');
define('HOZIO_IMG_VERSION', '1.6.7'); // For updater compatibility
define('HOZIO_IMAGE_OPTIMIZER_FILE', __FILE__);
define('HOZIO_IMAGE_OPTIMIZER_DIR', plugin_dir_path(__FILE__));
define('HOZIO_IMAGE_OPTIMIZER_URL', plugin_dir_url(__FILE__));
define('HOZIO_IMAGE_OPTIMIZER_BASENAME', plugin_basename(__FILE__));

// Backup directory constant
define('HOZIO_IMAGE_OPTIMIZER_BACKUP_DIR', WP_CONTENT_DIR . '/hozio-image-backups/');

// Load plugin updater
require_once plugin_dir_path(__FILE__) . 'includes/plugin-updater.php';

/**
 * Main plugin class
 */
class Hozio_Image_Optimizer {

    /**
     * Plugin instance
     */
    private static $instance = null;

    /**
     * Get plugin instance (Singleton)
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Core classes
        require_once __DIR__ . '/includes/class-helpers.php';
        require_once __DIR__ . '/includes/class-image-safety.php';
        require_once __DIR__ . '/includes/class-backup-manager.php';
        require_once __DIR__ . '/includes/class-image-compressor.php';
        require_once __DIR__ . '/includes/class-format-converter.php';
        require_once __DIR__ . '/includes/class-geolocation.php';
        require_once __DIR__ . '/includes/class-openai-client.php';
        require_once __DIR__ . '/includes/class-image-analyzer.php';
        require_once __DIR__ . '/includes/class-file-renamer.php';
        require_once __DIR__ . '/includes/class-reference-updater.php';
        require_once __DIR__ . '/includes/class-elementor-handler.php';
        require_once __DIR__ . '/includes/class-cache-purger.php';
        require_once __DIR__ . '/includes/class-redirect-manager.php';
        require_once __DIR__ . '/includes/class-broken-detector.php';
        require_once __DIR__ . '/includes/class-auto-optimizer.php';
        require_once __DIR__ . '/includes/class-dashboard-widget.php';
        require_once __DIR__ . '/includes/class-report-generator.php';
        require_once __DIR__ . '/includes/class-background-processor.php';
        require_once __DIR__ . '/includes/class-unused-detector.php';
        require_once __DIR__ . '/includes/class-cleanup-exporter.php';

        // Admin classes
        if (is_admin()) {
            require_once __DIR__ . '/admin/class-admin.php';
            require_once __DIR__ . '/admin/class-settings.php';
            require_once __DIR__ . '/admin/class-ajax-handler.php';

            // Initialize admin
            new Hozio_Image_Optimizer_Admin();
            new Hozio_Image_Optimizer_Settings();
            new Hozio_Image_Optimizer_Ajax_Handler();
            new Hozio_Image_Optimizer_Dashboard_Widget();
        }

        // Initialize auto-optimizer (works on both admin and frontend uploads)
        new Hozio_Image_Optimizer_Auto_Optimizer();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'load_textdomain'));
        add_action('admin_init', array($this, 'maybe_redirect_after_update'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_global_banner'));
        add_action('admin_notices', array($this, 'render_global_banner'));

        // Scheduled cleanup for temp files
        add_action('hozio_cleanup_temp_zip', array($this, 'cleanup_temp_zip'));
        add_action('hozio_cleanup_temp_file', array($this, 'cleanup_temp_file'));

        // Daily broken image scan
        add_action('hozio_daily_broken_scan', array('Hozio_Image_Optimizer_Broken_Detector', 'run_scheduled_scan'));

        // 301 redirects for renamed images (safety net, opt-in)
        if (get_option('hozio_enable_redirects', false)) {
            $redirect_manager = new Hozio_Image_Optimizer_Redirect_Manager();
            add_action('template_redirect', array($redirect_manager, 'handle_redirect'), 1);
        }
    }

    /**
     * Redirect to settings page after a plugin update completes.
     * Transient is set by the updater's after_install() hook.
     */
    public function maybe_redirect_after_update() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( get_transient( 'hozio_redirect_to_settings' ) ) {
            delete_transient( 'hozio_redirect_to_settings' );
            // Use window.top so the redirect escapes the thickbox iframe WP uses for updates
            $url = esc_url( admin_url( 'admin.php?page=hozio-image-optimizer' ) );
            add_action( 'admin_head', function() use ( $url ) {
                echo "<script>window.top.location.href='" . $url . "';</script>\n";
            });
        }
    }

    /**
     * Clean up temporary ZIP files
     */
    public function cleanup_temp_zip($zip_path) {
        if (file_exists($zip_path)) {
            @unlink($zip_path);
        }
    }

    /**
     * Clean up temporary comparison files
     */
    public function cleanup_temp_file($file_path) {
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'hozio-image-optimizer',
            false,
            dirname(HOZIO_IMAGE_OPTIMIZER_BASENAME) . '/languages'
        );
    }

    /**
     * Enqueue admin assets
     */
    /**
     * Enqueue global banner on ALL admin pages (lightweight)
     */
    public function enqueue_global_banner() {
        wp_enqueue_style(
            'hozio-global-banner',
            HOZIO_IMAGE_OPTIMIZER_URL . 'assets/css/global-banner.css',
            array(),
            HOZIO_IMAGE_OPTIMIZER_VERSION
        );

        wp_enqueue_script(
            'hozio-global-banner',
            HOZIO_IMAGE_OPTIMIZER_URL . 'assets/js/global-banner.js',
            array('jquery'),
            HOZIO_IMAGE_OPTIMIZER_VERSION,
            true
        );

        wp_localize_script('hozio-global-banner', 'hozioGlobalBanner', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hozio_image_optimizer_nonce'),
            'optimizerUrl' => admin_url('upload.php?page=hozio-image-optimizer'),
            'logoUrl' => HOZIO_IMAGE_OPTIMIZER_URL . 'assets/images/logo.png',
        ));
    }

    /**
     * Render global banner HTML on all admin pages
     */
    public function render_global_banner() {
        // Optimization progress banner
        ?>
        <div id="hozio-global-banner" style="display:none;">
            <div class="hgb-inner">
                <div class="hgb-icon">
                    <img src="<?php echo esc_url(HOZIO_IMAGE_OPTIMIZER_URL . 'assets/images/logo.png'); ?>" alt="">
                </div>
                <div class="hgb-content">
                    <div class="hgb-text">Checking optimization status...</div>
                    <div class="hgb-bar"><div class="hgb-fill" style="width:0%"></div></div>
                </div>
                <span class="hgb-percent">0%</span>
                <a href="<?php echo esc_url(admin_url('upload.php?page=hozio-image-optimizer')); ?>" class="hgb-link">View Details</a>
                <button type="button" class="hgb-dismiss">&times;</button>
            </div>
        </div>
        <?php

        // Broken images alert banner (from daily scan)
        if (!method_exists('Hozio_Image_Optimizer_Broken_Detector', 'get_daily_broken_count')) {
            return;
        }
        $broken_count = Hozio_Image_Optimizer_Broken_Detector::get_daily_broken_count();
        $user_dismissed = get_user_meta(get_current_user_id(), 'hozio_broken_banner_dismissed', true);
        $last_scan = get_option('hozio_daily_broken_scan_time', '');

        // Show if broken images found and not dismissed since last scan
        if ($broken_count > 0 && $user_dismissed !== $last_scan && current_user_can('upload_files')) :
        ?>
        <div id="hozio-broken-alert" class="hgb-broken-alert">
            <div class="hgb-inner">
                <div class="hgb-icon" style="background:#fef2f2;">
                    <span class="dashicons dashicons-warning" style="color:#ef4444;font-size:16px;width:16px;height:16px;"></span>
                </div>
                <div class="hgb-content">
                    <div class="hgb-text" style="color:#991b1b;">
                        <?php printf(
                            esc_html(_n('%d broken image detected on your site', '%d broken images detected on your site', $broken_count, 'hozio-image-optimizer')),
                            $broken_count
                        ); ?>
                    </div>
                </div>
                <a href="<?php echo esc_url(admin_url('upload.php?page=hozio-image-backups&tab=broken')); ?>" class="hgb-link" style="border-color:#fecaca;color:#dc2626;">View & Fix</a>
                <button type="button" class="hgb-dismiss hgb-dismiss-broken">&times;</button>
            </div>
        </div>
        <!-- Dismiss handler is in global-banner.js -->
        <?php endif;
    }

    public function enqueue_admin_assets($hook) {
        // Only load on our plugin pages
        $plugin_pages = array(
            'media_page_hozio-image-optimizer',
            'settings_page_hozio-image-optimizer-settings',
            'media_page_hozio-image-backups'
        );

        if (!in_array($hook, $plugin_pages)) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'hozio-image-optimizer-admin',
            HOZIO_IMAGE_OPTIMIZER_URL . 'assets/css/admin.css',
            array(),
            HOZIO_IMAGE_OPTIMIZER_VERSION
        );

        // Enqueue scripts
        wp_enqueue_script(
            'hozio-image-optimizer-admin',
            HOZIO_IMAGE_OPTIMIZER_URL . 'assets/js/admin.js',
            array('jquery'),
            HOZIO_IMAGE_OPTIMIZER_VERSION,
            true
        );

        // Enqueue media library on backups page (for broken image replacement)
        if ($hook === 'media_page_hozio-image-backups') {
            wp_enqueue_media();
        }

        // Load bulk optimizer script on main page
        if ($hook === 'media_page_hozio-image-optimizer') {
            wp_enqueue_script(
                'hozio-image-optimizer-bulk',
                HOZIO_IMAGE_OPTIMIZER_URL . 'assets/js/bulk-optimizer.js',
                array('jquery'),
                HOZIO_IMAGE_OPTIMIZER_VERSION,
                true
            );
        }

        // Localize script - used by both admin.js and bulk-optimizer.js
        $site_title_raw = get_bloginfo('name');
        $site_title_slug = function_exists('sanitize_title') ? sanitize_title($site_title_raw) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $site_title_raw));
        $localize_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hozio_image_optimizer_nonce'),
            'pluginUrl' => HOZIO_IMAGE_OPTIMIZER_URL,
            'siteTitle' => $site_title_raw,
            'siteTitleSlug' => $site_title_slug,
            'strings' => array(
                'processing' => __('Processing...', 'hozio-image-optimizer'),
                'completed' => __('Completed!', 'hozio-image-optimizer'),
                'complete' => __('Complete!', 'hozio-image-optimizer'),
                'error' => __('Error occurred', 'hozio-image-optimizer'),
                'selectImages' => __('Please select at least one image', 'hozio-image-optimizer'),
                'confirmOptimize' => __('Are you sure you want to optimize the selected images?', 'hozio-image-optimizer'),
                'confirmRestore' => __('Are you sure you want to restore this image to its original?', 'hozio-image-optimizer'),
                'noBackup' => __('No backup available for this image', 'hozio-image-optimizer'),
                'restoreSuccess' => __('Image restored successfully!', 'hozio-image-optimizer'),
                'optimizing' => __('Optimizing', 'hozio-image-optimizer'),
                'compressing' => __('Compressing', 'hozio-image-optimizer'),
                'converting' => __('Converting', 'hozio-image-optimizer'),
                'renaming' => __('Renaming', 'hozio-image-optimizer'),
                'noImages' => __('No images found', 'hozio-image-optimizer'),
                'pause' => __('Pause', 'hozio-image-optimizer'),
                'resume' => __('Resume', 'hozio-image-optimizer'),
                'paused' => __('Paused', 'hozio-image-optimizer'),
            ),
            'compressionDescriptions' => array(
                'lossy' => __('Maximum compression, slight quality loss. Best for web.', 'hozio-image-optimizer'),
                'glossy' => __('Balanced compression with minimal visible quality loss.', 'hozio-image-optimizer'),
                'lossless' => __('No quality loss, smaller file size reduction.', 'hozio-image-optimizer'),
            ),
        );

        wp_localize_script('hozio-image-optimizer-admin', 'hozioImageOptimizer', $localize_data);

        // Also localize for bulk-optimizer script with same data
        if ($hook === 'media_page_hozio-image-optimizer') {
            wp_localize_script('hozio-image-optimizer-bulk', 'hozioImageOptimizerData', $localize_data);
        }
    }

    /**
     * Plugin activation
     */
    public static function activate() {
        // Create backup directory
        if (!file_exists(HOZIO_IMAGE_OPTIMIZER_BACKUP_DIR)) {
            wp_mkdir_p(HOZIO_IMAGE_OPTIMIZER_BACKUP_DIR);

            // Add .htaccess to protect backups
            $htaccess_content = "Order deny,allow\nDeny from all";
            file_put_contents(HOZIO_IMAGE_OPTIMIZER_BACKUP_DIR . '.htaccess', $htaccess_content);

            // Add index.php for extra security
            file_put_contents(HOZIO_IMAGE_OPTIMIZER_BACKUP_DIR . 'index.php', '<?php // Silence is golden');
        }

        // Set default options
        $defaults = array(
            // API Settings
            'hozio_openai_api_key' => '',
            'hozio_openai_model' => 'gpt-4o',

            // Compression Settings
            'hozio_enable_compression' => true,
            'hozio_compression_quality' => 82,
            'hozio_max_width' => 1600,
            'hozio_max_height' => 1600,
            'hozio_strip_metadata' => false,

            // Geolocation Settings
            'hozio_enable_geolocation' => true,

            // Format Conversion
            'hozio_convert_to_webp' => true,
            'hozio_convert_to_avif' => false,
            'hozio_webp_quality' => 82,
            'hozio_avif_quality' => 75,
            'hozio_keep_original_backup' => true,

            // AI Renaming
            'hozio_enable_ai_rename' => true,
            'hozio_naming_template' => '{keyword}-{location}',
            'hozio_title_template' => '{keyword} - {location}',
            'hozio_max_filename_length' => 50,
            'hozio_keyword_word_count' => 5,

            // AI Features
            'hozio_enable_ai_alt_text' => true,
            'hozio_enable_ai_caption' => false,
            'hozio_enable_ai_tagging' => false,

            // Safety Settings
            'hozio_backup_enabled' => true,
            'hozio_backup_retention_days' => 30,
            'hozio_validate_after_operation' => true,

            // Statistics
            'hozio_total_images_processed' => 0,
            'hozio_total_bytes_saved' => 0,

            // Rename Safety
            'hozio_enable_redirects' => false,
            'hozio_redirect_retention_days' => 90,
            'hozio_clear_caches_on_rename' => true,

            // Multi-Pass Compression
            'hozio_enable_multipass' => true,
            'hozio_target_filesize' => 80,
            'hozio_multipass_max_passes' => 5,
            'hozio_quality_floor' => 35,

            // Auto-updates enabled by default
            'hozio_imgopt_auto_updates_enabled' => '1',
        );

        foreach ($defaults as $option => $value) {
            add_option($option, $value);
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Schedule daily broken image scan
        Hozio_Image_Optimizer_Broken_Detector::schedule_daily_scan();
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clean up scheduled events
        wp_clear_scheduled_hook('hozio_cleanup_old_backups');
        Hozio_Image_Optimizer_Broken_Detector::unschedule_daily_scan();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Get server capabilities
     */
    public static function get_server_capabilities() {
        return array(
            'gd' => extension_loaded('gd'),
            'imagick' => extension_loaded('imagick'),
            'webp' => function_exists('imagewebp'),
            'avif' => function_exists('imageavif'),
            'exif' => extension_loaded('exif'),
            'exiftool' => self::check_exiftool_available(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
        );
    }

    /**
     * Check if exiftool is available (bundled or system)
     */
    private static function check_exiftool_available() {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        // Check if shell execution is available (many shared hosts disable it)
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            return false;
        }

        // First, check for bundled exiftool in plugin directory
        $bundled_exiftool = HOZIO_IMAGE_OPTIMIZER_DIR . 'vendor/exiftool/exiftool';
        if (file_exists($bundled_exiftool)) {
            // Try to run bundled exiftool with perl
            $perl_paths = array('perl', '/usr/bin/perl', '/usr/local/bin/perl');
            foreach ($perl_paths as $perl) {
                $output = array();
                $return_var = -1;
                $test_cmd = escapeshellcmd($perl) . ' ' . escapeshellarg($bundled_exiftool) . ' -ver 2>&1';
                @exec($test_cmd, $output, $return_var);
                if ($return_var === 0) {
                    $available = 'bundled';
                    return 'bundled';
                }
            }
        }

        // Fall back to system-installed exiftool
        $possible_paths = array(
            'exiftool',
            '/usr/bin/exiftool',
            '/usr/local/bin/exiftool',
            '/opt/local/bin/exiftool',
        );

        foreach ($possible_paths as $path) {
            $output = array();
            $return_var = -1;
            @exec($path . ' -ver 2>&1', $output, $return_var);
            if ($return_var === 0) {
                $available = true;
                return true;
            }
        }

        $available = false;
        return false;
    }
}

// Register activation/deactivation hooks (must be at top level)
register_activation_hook(__FILE__, array('Hozio_Image_Optimizer', 'activate'));
register_deactivation_hook(__FILE__, array('Hozio_Image_Optimizer', 'deactivate'));

// Initialize the plugin
Hozio_Image_Optimizer::get_instance();
