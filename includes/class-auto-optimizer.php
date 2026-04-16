<?php
/**
 * Auto-optimize images on upload
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Auto_Optimizer {

    /**
     * Flag to track if we're currently processing to prevent infinite loops
     */
    private static $processing = array();

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into attachment creation - use a later priority to ensure file is fully saved
        add_filter('wp_generate_attachment_metadata', array($this, 'optimize_on_upload'), 99, 2);

        // Add admin notice for optimization results
        add_action('admin_notices', array($this, 'show_optimization_notice'));

        // Add modal and scripts for upload pages
        add_action('admin_enqueue_scripts', array($this, 'enqueue_upload_scripts'));

        // Add modal HTML to footer
        add_action('admin_footer', array($this, 'render_optimization_modal'));
    }

    /**
     * Enqueue scripts for upload pages
     */
    public function enqueue_upload_scripts($hook) {
        // Only load on media pages
        if (!in_array($hook, array('upload.php', 'media-new.php', 'post.php', 'post-new.php'))) {
            return;
        }

        // Check if auto-optimize is enabled
        if (!get_option('hozio_auto_optimize_on_upload', false)) {
            return;
        }

        // Enqueue the upload optimizer script
        wp_enqueue_script(
            'hozio-upload-optimizer',
            HOZIO_IMAGE_OPTIMIZER_URL . 'assets/js/upload-optimizer.js',
            array('jquery', 'wp-plupload'),
            HOZIO_IMAGE_OPTIMIZER_VERSION,
            true
        );

        // Localize script with settings
        wp_localize_script('hozio-upload-optimizer', 'hozioUploadOptimizer', array(
            'enabled' => true,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hozio_image_optimizer_nonce'),
            'strings' => array(
                'title' => __('Optimizing Images', 'hozio-image-optimizer'),
                'preparing' => __('Preparing optimization...', 'hozio-image-optimizer'),
                'compressing' => __('Compressing image...', 'hozio-image-optimizer'),
                'converting' => __('Converting format...', 'hozio-image-optimizer'),
                'aiProcessing' => __('AI processing...', 'hozio-image-optimizer'),
                'complete' => __('Optimization complete!', 'hozio-image-optimizer'),
                'saved' => __('Saved', 'hozio-image-optimizer'),
                'processing' => __('Processing', 'hozio-image-optimizer'),
                'of' => __('of', 'hozio-image-optimizer'),
                'images' => __('images', 'hozio-image-optimizer'),
                'estimatedTime' => __('Estimated time remaining:', 'hozio-image-optimizer'),
            ),
            'settings' => array(
                'compression' => get_option('hozio_enable_compression', true),
                'webp' => get_option('hozio_convert_to_webp', true),
                'avif' => get_option('hozio_convert_to_avif', false),
                'aiAlt' => get_option('hozio_enable_ai_alt_text', true) && !empty(get_option('hozio_openai_api_key', '')),
                'aiRename' => get_option('hozio_auto_ai_rename', false) && !empty(get_option('hozio_openai_api_key', '')),
            ),
        ));

        // Add inline styles for the modal
        wp_add_inline_style('wp-admin', $this->get_modal_styles());
    }

    /**
     * Get modal CSS styles
     */
    private function get_modal_styles() {
        return '
        #hozio-optimization-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 999999;
            align-items: center;
            justify-content: center;
        }
        #hozio-optimization-modal.active {
            display: flex;
        }
        .hozio-modal-content {
            background: #fff;
            border-radius: 8px;
            padding: 30px 40px;
            max-width: 500px;
            width: 90%;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .hozio-modal-content h2 {
            margin: 0 0 20px;
            color: #1d2327;
            font-size: 22px;
        }
        .hozio-modal-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: hozio-pulse 2s infinite;
        }
        .hozio-modal-icon svg {
            width: 30px;
            height: 30px;
            fill: #fff;
        }
        @keyframes hozio-pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
        }
        .hozio-progress-bar {
            background: #e0e0e0;
            border-radius: 10px;
            height: 20px;
            overflow: hidden;
            margin: 20px 0;
        }
        .hozio-progress-fill {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            height: 100%;
            width: 0%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        .hozio-modal-status {
            color: #666;
            font-size: 14px;
            margin: 10px 0;
        }
        .hozio-modal-step {
            color: #1d2327;
            font-weight: 500;
            font-size: 15px;
            margin: 15px 0;
        }
        .hozio-modal-time {
            color: #888;
            font-size: 13px;
            margin-top: 15px;
        }
        .hozio-modal-complete {
            display: none;
        }
        .hozio-modal-complete.active {
            display: block;
        }
        .hozio-modal-complete .hozio-modal-icon {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            animation: none;
        }
        .hozio-savings-display {
            background: #f0f7f0;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
        }
        .hozio-savings-display strong {
            color: #11998e;
            font-size: 24px;
            display: block;
            margin-bottom: 5px;
        }
        .hozio-modal-close {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            margin-top: 15px;
        }
        .hozio-modal-close:hover {
            opacity: 0.9;
        }
        ';
    }

    /**
     * Render the optimization modal HTML
     */
    public function render_optimization_modal() {
        // Only on relevant pages
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->base, array('upload', 'media', 'post', 'post-new'))) {
            return;
        }

        // Only if auto-optimize is enabled
        if (!get_option('hozio_auto_optimize_on_upload', false)) {
            return;
        }
        ?>
        <div id="hozio-optimization-modal">
            <div class="hozio-modal-content">
                <!-- Processing State -->
                <div class="hozio-modal-processing">
                    <div class="hozio-modal-icon">
                        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                    </div>
                    <h2><?php esc_html_e('Optimizing Images', 'hozio-image-optimizer'); ?></h2>
                    <div class="hozio-progress-bar">
                        <div class="hozio-progress-fill"></div>
                    </div>
                    <div class="hozio-modal-status">
                        <span class="current-count">0</span> <?php esc_html_e('of', 'hozio-image-optimizer'); ?>
                        <span class="total-count">0</span> <?php esc_html_e('images', 'hozio-image-optimizer'); ?>
                    </div>
                    <div class="hozio-modal-step"><?php esc_html_e('Preparing optimization...', 'hozio-image-optimizer'); ?></div>
                    <div class="hozio-modal-time"></div>
                </div>

                <!-- Complete State -->
                <div class="hozio-modal-complete">
                    <div class="hozio-modal-icon">
                        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                    </div>
                    <h2><?php esc_html_e('Optimization Complete!', 'hozio-image-optimizer'); ?></h2>
                    <div class="hozio-savings-display">
                        <strong class="total-saved">0 KB</strong>
                        <span><?php esc_html_e('Total space saved', 'hozio-image-optimizer'); ?></span>
                    </div>
                    <div class="hozio-images-processed">
                        <span class="images-count">0</span> <?php esc_html_e('images optimized', 'hozio-image-optimizer'); ?>
                    </div>
                    <button class="hozio-modal-close" type="button"><?php esc_html_e('Done', 'hozio-image-optimizer'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Show admin notice after auto-optimization
     */
    public function show_optimization_notice() {
        $notice = get_transient('hozio_auto_optimize_notice');
        if ($notice) {
            delete_transient('hozio_auto_optimize_notice');
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Hozio Image Optimizer:</strong> <?php echo esc_html($notice); ?></p>
            </div>
            <?php
        }

        $error = get_transient('hozio_auto_optimize_error');
        if ($error) {
            delete_transient('hozio_auto_optimize_error');
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong>Hozio Image Optimizer Error:</strong> <?php echo esc_html($error); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Optimize image immediately after upload
     *
     * @param array $metadata Attachment metadata
     * @param int $attachment_id Attachment ID
     * @return array Modified metadata
     */
    public function optimize_on_upload($metadata, $attachment_id) {
        // Always return metadata - never cause upload to fail
        try {
            return $this->do_optimization($metadata, $attachment_id);
        } catch (Exception $e) {
            if (function_exists('error_log')) {
                error_log('Hozio Auto-Optimizer Exception: ' . $e->getMessage());
            }
            set_transient('hozio_auto_optimize_error', 'Error: ' . $e->getMessage(), 60);
            return $metadata;
        } catch (Error $e) {
            if (function_exists('error_log')) {
                error_log('Hozio Auto-Optimizer Fatal Error: ' . $e->getMessage());
            }
            set_transient('hozio_auto_optimize_error', 'Fatal Error: ' . $e->getMessage(), 60);
            return $metadata;
        }
    }

    /**
     * Perform the actual optimization
     */
    private function do_optimization($metadata, $attachment_id) {
        // Skip if auto-optimize is disabled
        $auto_optimize_enabled = get_option('hozio_auto_optimize_on_upload', false);
        if (!$auto_optimize_enabled) {
            return $metadata;
        }

        // Verify it's an image
        if (!wp_attachment_is_image($attachment_id)) {
            return $metadata;
        }

        // Prevent infinite loops
        if (isset(self::$processing[$attachment_id])) {
            return $metadata;
        }

        // Mark as processing
        self::$processing[$attachment_id] = true;

        // Get file path
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            set_transient('hozio_auto_optimize_error', 'File not found for attachment ' . $attachment_id, 60);
            unset(self::$processing[$attachment_id]);
            return $metadata;
        }

        $original_size = filesize($file_path);
        $operations_performed = array();

        // Create backup first
        if (get_option('hozio_backup_enabled', false) && class_exists('Hozio_Image_Optimizer_Backup_Manager')) {
            $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
            $backup_manager->create_backup($attachment_id, $file_path);
            $operations_performed[] = 'backup';
        }

        // Compress if enabled - FIXED: Use correct class name
        if (get_option('hozio_enable_compression', true) && class_exists('Hozio_Image_Optimizer_Compressor')) {
            $compressor = new Hozio_Image_Optimizer_Compressor();
            $compress_result = $compressor->compress($file_path, array(
                'quality' => get_option('hozio_compression_quality', 82),
                'max_width' => get_option('hozio_max_width', 2048),
                'max_height' => get_option('hozio_max_height', 2048),
                'strip_metadata' => get_option('hozio_strip_metadata', false),
            ));
            if (!empty($compress_result['success']) && empty($compress_result['skipped'])) {
                $operations_performed[] = 'compressed';
            }
        }

        // Convert format if enabled
        $convert_to_webp = get_option('hozio_convert_to_webp', true);
        $convert_to_avif = get_option('hozio_convert_to_avif', false);

        if (($convert_to_webp || $convert_to_avif) && class_exists('Hozio_Image_Optimizer_Format_Converter')) {
            $converter = new Hozio_Image_Optimizer_Format_Converter();

            if ($convert_to_avif && Hozio_Image_Optimizer_Format_Converter::avif_supported()) {
                $converter->convert_attachment($attachment_id, 'avif');
                $operations_performed[] = 'converted to AVIF';
            } elseif ($convert_to_webp && Hozio_Image_Optimizer_Format_Converter::webp_supported()) {
                $converter->convert_attachment($attachment_id, 'webp');
                $operations_performed[] = 'converted to WebP';
            }
        }

        // Inject geolocation if default location is set
        $default_location = get_option('hozio_default_upload_location', '');
        $default_lat = get_option('hozio_default_upload_lat', '');
        $default_lng = get_option('hozio_default_upload_lng', '');

        if (get_option('hozio_enable_geolocation', true) && class_exists('Hozio_Image_Optimizer_Geolocation')) {
            $file_path = get_attached_file($attachment_id);

            // Use saved coordinates if available (more reliable)
            if (!empty($default_lat) && !empty($default_lng)) {
                $geo_result = Hozio_Image_Optimizer_Geolocation::inject_coordinates(
                    $file_path,
                    floatval($default_lat),
                    floatval($default_lng)
                );
                if (!empty($geo_result['success'])) {
                    $operations_performed[] = 'geolocation added';
                }
            } elseif (!empty($default_location)) {
                // Fall back to geocoding from location string
                $geo_result = Hozio_Image_Optimizer_Geolocation::inject_geolocation($file_path, $default_location);
                if (!empty($geo_result['success'])) {
                    $operations_performed[] = 'geolocation added';
                }
            }
        }

        // AI features require API key
        $api_key = get_option('hozio_openai_api_key', '');
        if (!empty($api_key)) {
            // Generate alt text if enabled
            if (get_option('hozio_enable_ai_alt_text', true) && class_exists('Hozio_Image_Optimizer_Analyzer')) {
                $analyzer = new Hozio_Image_Optimizer_Analyzer();
                $analyzer->generate_and_apply_alt_text($attachment_id);
                $operations_performed[] = 'AI alt text';
            }

            // AI rename if enabled for auto-upload
            if (get_option('hozio_auto_ai_rename', false) && class_exists('Hozio_Image_Optimizer_File_Renamer')) {
                $renamer = new Hozio_Image_Optimizer_File_Renamer();
                $renamer->rename_with_ai($attachment_id, $default_location, '');
                $operations_performed[] = 'AI renamed';
            }
        }

        // Calculate savings
        $new_file_path = get_attached_file($attachment_id);
        $new_size = file_exists($new_file_path) ? filesize($new_file_path) : $original_size;
        $saved_bytes = $original_size - $new_size;
        $saved_percent = $original_size > 0 ? round(($saved_bytes / $original_size) * 100, 1) : 0;

        // Update statistics
        $total_processed = get_option('hozio_total_images_processed', 0);
        update_option('hozio_total_images_processed', $total_processed + 1);

        $total_saved = get_option('hozio_total_bytes_saved', 0);
        update_option('hozio_total_bytes_saved', $total_saved + max(0, $saved_bytes));

        // Mark as optimized and clear any restored flag
        update_post_meta($attachment_id, '_hozio_optimized', true);
        update_post_meta($attachment_id, '_hozio_optimized_date', current_time('mysql'));
        update_post_meta($attachment_id, '_hozio_original_size', $original_size);
        update_post_meta($attachment_id, '_hozio_optimized_size', $new_size);
        update_post_meta($attachment_id, '_hozio_savings_bytes', max(0, $saved_bytes));
        delete_post_meta($attachment_id, '_hozio_restored');

        // Create success message
        $filename = basename($new_file_path);
        $ops_text = !empty($operations_performed) ? implode(', ', $operations_performed) : 'optimized';
        $notice_message = sprintf(
            'Image "%s" auto-optimized (%s). Saved %s (%s%%).',
            $filename,
            $ops_text,
            size_format(max(0, $saved_bytes)),
            $saved_percent
        );
        set_transient('hozio_auto_optimize_notice', $notice_message, 60);

        // Store result for modal display
        set_transient('hozio_last_optimize_result_' . $attachment_id, array(
            'filename' => $filename,
            'original_size' => $original_size,
            'new_size' => $new_size,
            'saved_bytes' => max(0, $saved_bytes),
            'saved_percent' => $saved_percent,
            'operations' => $operations_performed,
        ), 300);

        // Clean up processing flag
        unset(self::$processing[$attachment_id]);

        return $metadata;
    }
}
