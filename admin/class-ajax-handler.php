<?php
/**
 * AJAX Handler for all plugin operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Ajax_Handler {

    /**
     * Constructor - Register AJAX handlers
     */
    public function __construct() {
        // Optimization actions
        add_action('wp_ajax_hozio_optimize_image', array($this, 'optimize_image'));
        add_action('wp_ajax_hozio_compress_image', array($this, 'compress_image'));
        add_action('wp_ajax_hozio_convert_image', array($this, 'convert_image'));
        add_action('wp_ajax_hozio_rename_image', array($this, 'rename_image'));

        // Preview actions
        add_action('wp_ajax_hozio_preview_rename', array($this, 'preview_rename'));
        add_action('wp_ajax_hozio_get_image_info', array($this, 'get_image_info'));

        // Backup actions
        add_action('wp_ajax_hozio_restore_image', array($this, 'restore_image'));
        add_action('wp_ajax_hozio_delete_backup', array($this, 'delete_backup'));
        add_action('wp_ajax_hozio_cleanup_backups', array($this, 'cleanup_backups'));
        add_action('wp_ajax_hozio_download_all_backups', array($this, 'download_all_backups'));

        // Settings actions
        add_action('wp_ajax_hozio_test_api', array($this, 'test_api'));
        add_action('wp_ajax_hozio_get_models', array($this, 'get_models'));
        add_action('wp_ajax_hozio_reset_usage_stats', array($this, 'reset_usage_stats'));
        add_action('wp_ajax_hozio_test_geocoding', array($this, 'test_geocoding'));
        add_action('wp_ajax_hozio_search_locations', array($this, 'search_locations'));
        add_action('wp_ajax_hozio_toggle_backup', array($this, 'toggle_backup'));

        // Bulk actions
        add_action('wp_ajax_hozio_get_images', array($this, 'get_images'));
        add_action('wp_ajax_hozio_get_all_image_ids', array($this, 'get_all_image_ids'));
        add_action('wp_ajax_hozio_get_recommended_ids', array($this, 'get_recommended_ids'));
        add_action('wp_ajax_hozio_get_statistics', array($this, 'get_statistics'));
        add_action('wp_ajax_hozio_bulk_restore', array($this, 'bulk_restore'));
        add_action('wp_ajax_hozio_generate_report', array($this, 'generate_report'));
        add_action('wp_ajax_hozio_send_report_email', array($this, 'send_report_email'));
        add_action('wp_ajax_hozio_get_image_comparison', array($this, 'get_image_comparison'));

        // Auto-optimize result for modal
        add_action('wp_ajax_hozio_get_optimization_result', array($this, 'get_optimization_result'));

        // Background processing queue
        add_action('wp_ajax_hozio_start_background_queue', array($this, 'start_background_queue'));
        add_action('wp_ajax_hozio_get_queue_status', array($this, 'get_queue_status'));
        add_action('wp_ajax_hozio_pause_queue', array($this, 'pause_queue'));
        add_action('wp_ajax_hozio_resume_queue', array($this, 'resume_queue'));
        add_action('wp_ajax_hozio_cancel_queue', array($this, 'cancel_queue'));
        add_action('wp_ajax_hozio_clear_queue', array($this, 'clear_queue'));
        add_action('wp_ajax_hozio_process_queue_batch', array($this, 'process_queue_batch'));

        // Unused image cleanup
        add_action('wp_ajax_hozio_scan_unused_images', array($this, 'scan_unused_images'));
        add_action('wp_ajax_hozio_delete_unused_images', array($this, 'delete_unused_images'));
        add_action('wp_ajax_hozio_download_unused_images', array($this, 'download_unused_images'));
        add_action('wp_ajax_hozio_restore_from_zip', array($this, 'restore_from_zip'));
        add_action('wp_ajax_hozio_toggle_image_protection', array($this, 'toggle_image_protection'));
        add_action('wp_ajax_hozio_get_image_references', array($this, 'get_image_references'));

        // Broken image detection
        add_action('wp_ajax_hozio_scan_broken_images', array($this, 'scan_broken_images'));
        add_action('wp_ajax_hozio_resolve_broken_image', array($this, 'resolve_broken_image'));
        add_action('wp_ajax_hozio_dismiss_broken_banner', array($this, 'dismiss_broken_banner'));
        add_action('wp_ajax_hozio_force_update_check', array($this, 'force_update_check'));
        add_action('wp_ajax_hozio_save_auto_update', array($this, 'save_auto_update'));
    }

    /**
     * Get optimization result for an attachment (used by upload modal)
     */
    public function get_optimization_result() {
        $this->verify_request();

        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID', 'hozio-image-optimizer')));
        }

        // Get stored optimization data
        $original_size = get_post_meta($attachment_id, '_hozio_original_size', true);
        $optimized_size = get_post_meta($attachment_id, '_hozio_optimized_size', true);
        $saved_bytes = get_post_meta($attachment_id, '_hozio_savings_bytes', true);
        $is_optimized = get_post_meta($attachment_id, '_hozio_optimized', true);

        if (!$is_optimized) {
            // Not yet optimized, return zeros
            wp_send_json_success(array(
                'optimized' => false,
                'saved_bytes' => 0,
            ));
        }

        wp_send_json_success(array(
            'optimized' => true,
            'original_size' => intval($original_size),
            'optimized_size' => intval($optimized_size),
            'saved_bytes' => intval($saved_bytes),
        ));
    }

    /**
     * Verify nonce and permissions
     */
    private function verify_request() {
        check_ajax_referer('hozio_image_optimizer_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array(
                'message' => __('Insufficient permissions', 'hozio-image-optimizer'),
            ));
        }
    }

    /**
     * Full optimization: compress + convert + rename
     */
    public function optimize_image() {
        $this->verify_request();

        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID', 'hozio-image-optimizer')));
        }

        $options = array(
            'compress' => isset($_POST['compress']) ? (bool) $_POST['compress'] : true,
            'convert' => isset($_POST['convert']) ? (bool) $_POST['convert'] : true,
            'rename' => isset($_POST['rename']) ? (bool) $_POST['rename'] : true,
            'location' => sanitize_text_field($_POST['location'] ?? ''),
            'keyword_hint' => sanitize_text_field($_POST['keyword_hint'] ?? ''),
            'quality' => intval($_POST['quality'] ?? 82),
            'compression_level' => sanitize_key($_POST['compression_level'] ?? 'glossy'),
            'force' => isset($_POST['force']) ? (bool) $_POST['force'] : false,
            'manual_lat' => isset($_POST['manual_lat']) ? floatval($_POST['manual_lat']) : null,
            'manual_lng' => isset($_POST['manual_lng']) ? floatval($_POST['manual_lng']) : null,
        );

        // CRITICAL SAFETY CHECK: Never re-optimize without explicit force flag
        $is_already_optimized = get_post_meta($attachment_id, '_hozio_optimized', true);
        if ($is_already_optimized && !$options['force']) {
            wp_send_json_error(array(
                'message' => __('This image has already been optimized. Enable "Force Re-optimization" to process again.', 'hozio-image-optimizer'),
                'already_optimized' => true
            ));
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error(array('message' => __('Image file not found', 'hozio-image-optimizer')));
        }

        // Validate the image file is actually valid before any operations
        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            wp_send_json_error(array('message' => __('Invalid or corrupted image file', 'hozio-image-optimizer')));
        }

        $results = array(
            'attachment_id' => $attachment_id,
            'original_filename' => basename($file_path),
            'operations' => array(),
        );

        // Get original info
        $original_size = filesize($file_path);
        $results['original_size'] = $original_size;

        // Use safety wrapper
        $operation_result = Hozio_Image_Optimizer_Safety::safe_operation(
            function() use ($attachment_id, $options, &$results, &$file_path) {
                // Step 1: Compress
                if ($options['compress'] && get_option('hozio_enable_compression', true)) {
                    $compressor = new Hozio_Image_Optimizer_Compressor();
                    // Use quality from options if provided, otherwise use default from settings
                    $quality = $options['quality'] > 0 ? $options['quality'] : get_option('hozio_compression_quality', 82);
                    $compress_options = array(
                        'quality' => $quality,
                        'force' => $options['force'], // Allow re-compression
                        'target_filesize' => intval($_POST['target_filesize'] ?? 0),
                    );
                    $compress_result = $compressor->compress_attachment($attachment_id, $compress_options);
                    $results['operations']['compress'] = $compress_result;

                    // Update file path if compression changed it
                    $file_path = get_attached_file($attachment_id);
                }

                // Step 2: Convert format first
                $was_converted = false;
                if ($options['convert']) {
                    $converter = new Hozio_Image_Optimizer_Format_Converter();

                    $convert_to_webp = get_option('hozio_convert_to_webp', true);
                    $convert_to_avif = get_option('hozio_convert_to_avif', false);

                    if ($convert_to_avif && Hozio_Image_Optimizer_Format_Converter::avif_supported()) {
                        $convert_result = $converter->convert_attachment($attachment_id, 'avif');
                        $was_converted = !empty($convert_result['success']) && empty($convert_result['skipped']);
                    } elseif ($convert_to_webp && Hozio_Image_Optimizer_Format_Converter::webp_supported()) {
                        $convert_result = $converter->convert_attachment($attachment_id, 'webp');
                        $was_converted = !empty($convert_result['success']) && empty($convert_result['skipped']);
                    } else {
                        $convert_result = array('success' => true, 'skipped' => true);
                    }

                    $results['operations']['convert'] = $convert_result;
                    $file_path = get_attached_file($attachment_id);
                }

                // Step 3: Inject geolocation - ONLY for JPEG files to prevent WebP/AVIF corruption
                // WebP files are particularly prone to corruption from exiftool GPS injection
                $geo_mime_type = mime_content_type($file_path);
                $is_jpeg_for_geo = in_array($geo_mime_type, array('image/jpeg', 'image/jpg'));

                if ($is_jpeg_for_geo && get_option('hozio_enable_geolocation', true)) {
                    if ($options['manual_lat'] !== null && $options['manual_lng'] !== null) {
                        // Use exact manual coordinates
                        $geo_result = Hozio_Image_Optimizer_Geolocation::inject_coordinates(
                            $file_path,
                            $options['manual_lat'],
                            $options['manual_lng']
                        );
                        $results['operations']['geolocation'] = $geo_result;
                    } elseif (!empty($options['location'])) {
                        // Geocode from location string
                        $geo_result = Hozio_Image_Optimizer_Geolocation::inject_geolocation(
                            $file_path,
                            $options['location']
                        );
                        $results['operations']['geolocation'] = $geo_result;
                    }
                }

                // Step 4: Rename with AI
                if ($options['rename'] && get_option('hozio_enable_ai_rename', true)) {
                    $renamer = new Hozio_Image_Optimizer_File_Renamer();
                    $rename_result = $renamer->rename_with_ai(
                        $attachment_id,
                        $options['location'],
                        $options['keyword_hint']
                    );
                    $results['operations']['rename'] = $rename_result;
                    $file_path = get_attached_file($attachment_id);
                }

                // Step 5: Generate alt text if enabled
                if (get_option('hozio_enable_ai_alt_text', true)) {
                    $analyzer = new Hozio_Image_Optimizer_Analyzer();
                    $alt_result = $analyzer->generate_and_apply_alt_text($attachment_id);
                    $results['operations']['alt_text'] = $alt_result;
                }

                return array(
                    'success' => true,
                    'new_path' => $file_path,
                );
            },
            $file_path,
            $attachment_id
        );

        if (!$operation_result['success']) {
            wp_send_json_error(array(
                'message' => $operation_result['error'],
                'stage' => $operation_result['stage'] ?? 'unknown',
                'restored' => $operation_result['restored_from_backup'] ?? false,
            ));
        }

        // Calculate totals
        $new_size = file_exists($file_path) ? filesize($file_path) : $original_size;
        $results['new_size'] = $new_size;
        $results['original_size'] = $original_size;
        $results['new_filename'] = basename($file_path);
        $results['total_savings'] = $original_size - $new_size;
        $results['savings_percent'] = $original_size > 0 ?
            round(($results['total_savings'] / $original_size) * 100, 1) : 0;
        $results['thumbnail'] = wp_get_attachment_image_url($attachment_id, 'medium');
        if (!$results['thumbnail']) {
            $results['thumbnail'] = wp_get_attachment_url($attachment_id);
        }

        // Save per-image optimization meta (only mark as optimized if we actually saved space)
        if ($results['total_savings'] > 0) {
            update_post_meta($attachment_id, '_hozio_optimized', true);
            update_post_meta($attachment_id, '_hozio_optimized_date', current_time('mysql'));
            update_post_meta($attachment_id, '_hozio_original_size', $original_size);
            update_post_meta($attachment_id, '_hozio_optimized_size', $new_size);
            update_post_meta($attachment_id, '_hozio_savings_bytes', $results['total_savings']);
            delete_post_meta($attachment_id, '_hozio_restored');
        }

        // Update global statistics
        $this->update_statistics($results['total_savings']);

        wp_send_json_success($results);
    }

    /**
     * Update global optimization statistics
     */
    private function update_statistics($bytes_saved) {
        // Increment processed count
        $total_processed = get_option('hozio_total_images_processed', 0);
        update_option('hozio_total_images_processed', $total_processed + 1);

        // Add bytes saved (only if positive)
        if ($bytes_saved > 0) {
            $total_saved = get_option('hozio_total_bytes_saved', 0);
            update_option('hozio_total_bytes_saved', $total_saved + $bytes_saved);
        }
    }

    /**
     * Compress image only
     */
    public function compress_image() {
        $this->verify_request();

        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID', 'hozio-image-optimizer')));
        }

        $compressor = new Hozio_Image_Optimizer_Compressor();
        $result = $compressor->compress_attachment($attachment_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Convert image format
     */
    public function convert_image() {
        $this->verify_request();

        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        $format = sanitize_key($_POST['format'] ?? 'webp');

        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID', 'hozio-image-optimizer')));
        }

        if (!in_array($format, array('webp', 'avif'))) {
            wp_send_json_error(array('message' => __('Invalid format', 'hozio-image-optimizer')));
        }

        $converter = new Hozio_Image_Optimizer_Format_Converter();
        $result = $converter->convert_attachment($attachment_id, $format);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Rename image with AI
     */
    public function rename_image() {
        $this->verify_request();

        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        $location = sanitize_text_field($_POST['location'] ?? '');
        $keyword_hint = sanitize_text_field($_POST['keyword_hint'] ?? '');

        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID', 'hozio-image-optimizer')));
        }

        $renamer = new Hozio_Image_Optimizer_File_Renamer();
        $result = $renamer->rename_with_ai($attachment_id, $location, $keyword_hint);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Preview rename without applying
     */
    public function preview_rename() {
        $this->verify_request();

        $attachment_ids = isset($_POST['attachment_ids']) ?
            array_map('intval', (array) $_POST['attachment_ids']) : array();
        $location = sanitize_text_field($_POST['location'] ?? '');
        $keyword_hint = sanitize_text_field($_POST['keyword_hint'] ?? '');

        if (empty($attachment_ids)) {
            wp_send_json_error(array('message' => __('No images selected', 'hozio-image-optimizer')));
        }

        $analyzer = new Hozio_Image_Optimizer_Analyzer();
        $previews = array();

        foreach ($attachment_ids as $attachment_id) {
            $previews[] = $analyzer->preview($attachment_id, $location, $keyword_hint);
        }

        wp_send_json_success(array('previews' => $previews));
    }

    /**
     * Get image info
     */
    public function get_image_info() {
        $this->verify_request();

        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID', 'hozio-image-optimizer')));
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error(array('message' => __('Image not found', 'hozio-image-optimizer')));
        }

        // Get attachment post data for caption and description
        $attachment = get_post($attachment_id);

        $info = Hozio_Image_Optimizer_Helpers::get_image_info($file_path);
        $info['title'] = get_the_title($attachment_id);
        $info['alt_text'] = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $info['caption'] = $attachment ? $attachment->post_excerpt : '';
        $info['description'] = $attachment ? $attachment->post_content : '';
        $info['thumbnail'] = wp_get_attachment_image_url($attachment_id, 'medium');

        // Add formatted file size
        $info['file_size_formatted'] = Hozio_Image_Optimizer_Helpers::format_bytes($info['file_size']);

        // Check backup status and get original size if available
        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        $info['has_backup'] = $backup_manager->has_backup($attachment_id);

        // Check if optimized via post meta
        $info['is_optimized'] = (bool) get_post_meta($attachment_id, '_hozio_optimized', true);

        // Get original file size from backup metadata if available
        $original_size = 0;
        if ($info['has_backup']) {
            $backup_info = $backup_manager->get_backup_info($attachment_id);
            if ($backup_info && isset($backup_info['original_size'])) {
                $original_size = $backup_info['original_size'];
            }
        }

        // Also check post meta for original size (set during optimization even without backup)
        if ($original_size === 0) {
            $meta_original_size = get_post_meta($attachment_id, '_hozio_original_size', true);
            if ($meta_original_size) {
                $original_size = intval($meta_original_size);
            }
        }

        // If we have an original size that's different from current, show savings
        if ($original_size > 0 && $original_size != $info['file_size']) {
            $info['original_size'] = $original_size;
            $info['original_size_formatted'] = Hozio_Image_Optimizer_Helpers::format_bytes($original_size);
            $info['savings'] = $original_size - $info['file_size'];
            $info['savings_formatted'] = Hozio_Image_Optimizer_Helpers::format_bytes($info['savings']);
            $info['savings_percent'] = $original_size > 0
                ? round(($info['savings'] / $original_size) * 100, 1)
                : 0;
        }

        // Reference count
        $reference_updater = new Hozio_Image_Optimizer_Reference_Updater();
        $info['reference_count'] = $reference_updater->count_references($attachment_id);

        wp_send_json_success($info);
    }

    /**
     * Restore image from backup
     */
    public function restore_image() {
        $this->verify_request();

        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID', 'hozio-image-optimizer')));
        }

        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        $result = $backup_manager->restore_backup($attachment_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Delete backup
     */
    public function delete_backup() {
        $this->verify_request();

        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID', 'hozio-image-optimizer')));
        }

        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        $result = $backup_manager->delete_backup($attachment_id);

        if ($result) {
            wp_send_json_success(array('message' => __('Backup deleted', 'hozio-image-optimizer')));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete backup', 'hozio-image-optimizer')));
        }
    }

    /**
     * Clean up old backups
     */
    public function cleanup_backups() {
        $this->verify_request();

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        $cleaned = $backup_manager->cleanup_old_backups();

        wp_send_json_success(array(
            'message' => sprintf(__('%d old backups cleaned up', 'hozio-image-optimizer'), $cleaned),
            'cleaned' => $cleaned,
        ));
    }

    /**
     * Test API connection
     */
    public function test_api() {
        $this->verify_request();

        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $use_existing = isset($_POST['use_existing']) && $_POST['use_existing'] === '1';

        // If no key provided and use_existing flag is set, use the stored key
        if (empty($api_key) && $use_existing) {
            $api_key = get_option('hozio_openai_api_key', '');
        }

        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('API key is required', 'hozio-image-optimizer')));
        }

        $client = new Hozio_Image_Optimizer_OpenAI_Client($api_key);
        $result = $client->test_connection();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Get available AI models
     */
    public function get_models() {
        $this->verify_request();

        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        if (empty($api_key)) {
            $api_key = get_option('hozio_openai_api_key', '');
        }

        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('API key is required', 'hozio-image-optimizer')));
        }

        try {
            $client = new Hozio_Image_Optimizer_OpenAI_Client($api_key);
            $models = $client->get_models();
            wp_send_json_success(array('models' => $models));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Get images for bulk optimizer
     */
    public function get_images() {
        $this->verify_request();

        $page = intval($_POST['page'] ?? 1);
        $per_page = intval($_POST['per_page'] ?? 20);
        $search = sanitize_text_field($_POST['search'] ?? '');
        $filter = sanitize_key($_POST['filter'] ?? 'all');
        $status_filter = sanitize_key($_POST['status_filter'] ?? 'all');
        $sort_by = sanitize_key($_POST['sort_by'] ?? 'date');
        $sort_order = strtoupper(sanitize_key($_POST['sort_order'] ?? 'DESC'));

        if (!in_array($sort_order, array('ASC', 'DESC'))) {
            $sort_order = 'DESC';
        }
        if (!in_array($sort_by, array('date', 'name', 'size', 'status'))) {
            $sort_by = 'date';
        }

        // Exclude SVGs and common patterns to filter out
        $excluded_mime_types = array('image/svg+xml');

        // Patterns to exclude from filenames (Elementor screenshots, etc.)
        $excluded_patterns = array(
            'elementor',
            'screenshot',
            'screenshoot',
        );

        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'),
            'post_status' => 'inherit',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        );

        // Apply sort (for non-size sorts, use WP_Query orderby)
        switch ($sort_by) {
            case 'name':
                $args['orderby'] = 'title';
                $args['order'] = $sort_order;
                break;
            case 'date':
                $args['orderby'] = 'date';
                $args['order'] = $sort_order;
                break;
            case 'size':
            case 'status':
                // These require post-query sorting; fetch all and paginate in PHP
                $args['posts_per_page'] = -1;
                unset($args['paged']);
                break;
        }

        // Search filter
        if (!empty($search)) {
            $args['s'] = $search;
        }

        // Type filter
        if ($filter !== 'all') {
            switch ($filter) {
                case 'jpeg':
                    $args['post_mime_type'] = 'image/jpeg';
                    break;
                case 'png':
                    $args['post_mime_type'] = 'image/png';
                    break;
                case 'webp':
                    $args['post_mime_type'] = 'image/webp';
                    break;
                case 'gif':
                    $args['post_mime_type'] = 'image/gif';
                    break;
            }
        }

        $query = new WP_Query($args);
        $images = array();
        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();

        foreach ($query->posts as $attachment) {
            $file_path = get_attached_file($attachment->ID);
            $filename = basename($file_path);
            $filename_lower = strtolower($filename);

            // Skip files matching excluded patterns
            $skip = false;
            foreach ($excluded_patterns as $pattern) {
                if (strpos($filename_lower, $pattern) !== false) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            $has_backup = $backup_manager->has_backup($attachment->ID);
            $is_optimized_meta = get_post_meta($attachment->ID, '_hozio_optimized', true);
            $is_optimized = $has_backup || $is_optimized_meta;

            // Filter by optimization status
            if ($status_filter === 'optimized' && !$is_optimized) {
                continue;
            }
            if ($status_filter === 'unoptimized' && $is_optimized) {
                continue;
            }

            $file_size = file_exists($file_path) ? filesize($file_path) : 0;

            // Get original size from backup or post meta
            $original_size = 0;
            $savings_percent = 0;

            if ($has_backup) {
                $backup_info = $backup_manager->get_backup_info($attachment->ID);
                if ($backup_info && isset($backup_info['original_size']) && $backup_info['original_size'] > 0) {
                    $original_size = $backup_info['original_size'];
                }
            }

            // Also check post meta for original size (when backup is disabled)
            if ($original_size === 0) {
                $meta_original_size = get_post_meta($attachment->ID, '_hozio_original_size', true);
                if ($meta_original_size) {
                    $original_size = intval($meta_original_size);
                }
            }

            if ($original_size > 0 && $file_size < $original_size) {
                $savings_percent = round((($original_size - $file_size) / $original_size) * 100, 1);
            }

            // Get image dimensions
            $metadata = wp_get_attachment_metadata($attachment->ID);
            $img_width = isset($metadata['width']) ? $metadata['width'] : 0;
            $img_height = isset($metadata['height']) ? $metadata['height'] : 0;

            $images[] = array(
                'id' => $attachment->ID,
                'title' => $attachment->post_title,
                'filename' => $filename,
                'file_size' => $file_size,
                'file_size_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($file_size),
                'original_size' => $original_size,
                'original_size_formatted' => $original_size > 0 ? Hozio_Image_Optimizer_Helpers::format_bytes($original_size) : '',
                'savings_percent' => $savings_percent,
                'savings_bytes' => $original_size > 0 ? ($original_size - $file_size) : 0,
                'savings_bytes_formatted' => $original_size > 0 ? Hozio_Image_Optimizer_Helpers::format_bytes($original_size - $file_size) : '',
                'mime_type' => $attachment->post_mime_type,
                'thumbnail' => wp_get_attachment_image_url($attachment->ID, 'medium'),
                'has_backup' => $has_backup,
                'is_optimized' => $is_optimized,
                'is_restored' => (bool) get_post_meta($attachment->ID, '_hozio_restored', true),
                'width' => $img_width,
                'height' => $img_height,
                'date' => $attachment->post_date,
            );
        }

        // PHP-side sorting for size and status (since WP_Query can't sort by file size)
        if ($sort_by === 'size') {
            usort($images, function($a, $b) use ($sort_order) {
                $diff = $a['file_size'] - $b['file_size'];
                return $sort_order === 'DESC' ? -$diff : $diff;
            });
        } elseif ($sort_by === 'status') {
            usort($images, function($a, $b) use ($sort_order) {
                $a_opt = $a['is_optimized'] ? 1 : 0;
                $b_opt = $b['is_optimized'] ? 1 : 0;
                $diff = $a_opt - $b_opt;
                return $sort_order === 'DESC' ? -$diff : $diff;
            });
        }

        // For size/status sorts, we fetched all and must paginate in PHP
        $total_images = count($images);
        $total_pages = 1;

        if (in_array($sort_by, array('size', 'status'))) {
            $total_pages = ceil($total_images / $per_page);
            $offset = ($page - 1) * $per_page;
            $images = array_slice($images, $offset, $per_page);
        } else {
            $total_images = $query->found_posts;
            $total_pages = $query->max_num_pages;
        }

        wp_send_json_success(array(
            'images' => $images,
            'total' => $total_images,
            'pages' => $total_pages,
            'current_page' => $page,
        ));
    }

    /**
     * Check if an image has been optimized by this plugin
     * Checks both backup existence and optimization meta flag
     *
     * @param int $attachment_id
     * @return bool
     */
    private function is_image_optimized($attachment_id) {
        // Check post meta flag (set during optimization)
        $is_optimized = get_post_meta($attachment_id, '_hozio_optimized', true);
        if ($is_optimized) {
            return true;
        }

        // Also check if backup exists (legacy check)
        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        return $backup_manager->has_backup($attachment_id);
    }

    /**
     * Get all image IDs (for select all functionality)
     * Returns IDs matching current filters without pagination
     */
    public function get_all_image_ids() {
        $this->verify_request();

        $search = sanitize_text_field($_POST['search'] ?? '');
        $filter = sanitize_key($_POST['filter'] ?? 'all');
        $status_filter = sanitize_key($_POST['status_filter'] ?? 'all');
        $only_unoptimized = isset($_POST['only_unoptimized']) && $_POST['only_unoptimized'] === 'true';

        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'),
            'post_status' => 'inherit',
            'posts_per_page' => -1, // Get all
            'fields' => 'ids', // Only get IDs for performance
            'orderby' => 'date',
            'order' => 'DESC',
        );

        // Search filter
        if (!empty($search)) {
            $args['s'] = $search;
        }

        // Type filter
        if ($filter !== 'all') {
            switch ($filter) {
                case 'jpeg':
                    $args['post_mime_type'] = 'image/jpeg';
                    break;
                case 'png':
                    $args['post_mime_type'] = 'image/png';
                    break;
                case 'webp':
                    $args['post_mime_type'] = 'image/webp';
                    break;
                case 'gif':
                    $args['post_mime_type'] = 'image/gif';
                    break;
            }
        }

        $query = new WP_Query($args);
        $ids = array();

        // Patterns to exclude from filenames
        $excluded_patterns = array('elementor', 'screenshot', 'screenshoot');

        foreach ($query->posts as $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            $filename = basename($file_path);
            $filename_lower = strtolower($filename);

            // Skip files matching excluded patterns
            $skip = false;
            foreach ($excluded_patterns as $pattern) {
                if (strpos($filename_lower, $pattern) !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $is_optimized = $this->is_image_optimized($attachment_id);

            // Filter by optimization status
            if ($status_filter === 'optimized' && !$is_optimized) {
                continue;
            }
            if ($status_filter === 'unoptimized' && $is_optimized) {
                continue;
            }

            // If only_unoptimized is true, skip optimized images
            if ($only_unoptimized && $is_optimized) {
                continue;
            }

            $ids[] = $attachment_id;
        }

        wp_send_json_success(array(
            'ids' => $ids,
            'total' => count($ids),
        ));
    }

    /**
     * Get recommended image IDs - all images over a certain file size threshold
     * Returns unoptimized images over 100KB for optimization
     */
    public function get_recommended_ids() {
        $this->verify_request();

        $threshold_kb = intval($_POST['threshold_kb'] ?? 100);
        $threshold_bytes = $threshold_kb * 1024;

        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'),
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
        );

        $query = new WP_Query($args);
        $ids = array();
        $total_size = 0;
        $excluded_patterns = array('elementor', 'screenshot', 'screenshoot');

        foreach ($query->posts as $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if (!$file_path) continue;

            $filename_lower = strtolower(basename($file_path));

            // Skip excluded patterns
            $skip = false;
            foreach ($excluded_patterns as $pattern) {
                if (strpos($filename_lower, $pattern) !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // Skip already optimized
            if ($this->is_image_optimized($attachment_id)) continue;

            // Check file size
            $file_size = file_exists($file_path) ? filesize($file_path) : 0;
            if ($file_size > $threshold_bytes) {
                $ids[] = $attachment_id;
                $total_size += $file_size;
            }
        }

        wp_send_json_success(array(
            'ids' => $ids,
            'total' => count($ids),
            'total_size' => $total_size,
            'total_size_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($total_size),
            'threshold_kb' => $threshold_kb,
        ));
    }

    /**
     * Get statistics
     */
    public function get_statistics() {
        $this->verify_request();

        $stats = Hozio_Image_Optimizer_Admin::get_statistics();
        wp_send_json_success($stats);
    }

    /**
     * Reset API usage statistics
     */
    public function reset_usage_stats() {
        $this->verify_request();

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        // Reset API usage stats
        $default_stats = array(
            'total_requests' => 0,
            'total_prompt_tokens' => 0,
            'total_completion_tokens' => 0,
            'total_tokens' => 0,
            'estimated_cost' => 0,
            'history' => array(),
        );

        update_option('hozio_api_usage_stats', $default_stats);

        // Also reset optimization stats
        update_option('hozio_total_images_processed', 0);
        update_option('hozio_total_bytes_saved', 0);

        wp_send_json_success(array('message' => __('Statistics reset successfully', 'hozio-image-optimizer')));
    }

    /**
     * Toggle backup setting on/off
     */
    public function toggle_backup() {
        $this->verify_request();

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
        update_option('hozio_backup_enabled', $enabled);

        wp_send_json_success(array(
            'enabled' => $enabled,
            'message' => $enabled
                ? __('Backups enabled', 'hozio-image-optimizer')
                : __('Backups disabled', 'hozio-image-optimizer')
        ));
    }

    /**
     * Download all backups as a ZIP file
     */
    public function download_all_backups() {
        $this->verify_request();

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        $result = $backup_manager->create_backup_archive();

        if ($result['success']) {
            wp_send_json_success(array(
                'download_url' => $result['download_url'],
                'filename' => $result['filename'],
                'file_count' => $result['file_count'],
            ));
        } else {
            wp_send_json_error(array('message' => $result['error']));
        }
    }

    /**
     * Test geocoding functionality
     */
    public function test_geocoding() {
        $this->verify_request();

        $location = sanitize_text_field($_POST['location'] ?? '');

        if (empty($location)) {
            wp_send_json_error(array('message' => __('Location is required', 'hozio-image-optimizer')));
        }

        $result = Hozio_Image_Optimizer_Geolocation::test_geocoding($location);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Search for US locations (autocomplete)
     */
    public function search_locations() {
        $this->verify_request();

        $query = sanitize_text_field($_POST['query'] ?? '');

        if (empty($query) || strlen($query) < 2) {
            wp_send_json_success(array('locations' => array()));
        }

        $result = Hozio_Image_Optimizer_Geolocation::search_us_locations($query);

        wp_send_json_success(array('locations' => $result));
    }

    /**
     * Bulk restore multiple images
     */
    public function bulk_restore() {
        $this->verify_request();

        $attachment_ids = isset($_POST['attachment_ids']) ? array_map('absint', $_POST['attachment_ids']) : array();

        if (empty($attachment_ids)) {
            wp_send_json_error(array('message' => __('No images selected', 'hozio-image-optimizer')));
        }

        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        $results = array(
            'success' => 0,
            'failed' => 0,
            'details' => array(),
        );

        foreach ($attachment_ids as $attachment_id) {
            $result = $backup_manager->restore_backup($attachment_id);
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            $results['details'][$attachment_id] = $result;
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Restored %d images, %d failed', 'hozio-image-optimizer'),
                $results['success'],
                $results['failed']
            ),
            'results' => $results,
        ));
    }

    /**
     * Generate optimization report
     */
    public function generate_report() {
        $this->verify_request();

        $format = sanitize_text_field($_POST['format'] ?? 'html');

        if ($format === 'html') {
            $html = Hozio_Image_Optimizer_Report_Generator::generate_html_report();
            wp_send_json_success(array(
                'html' => $html,
            ));
        } else {
            $data = Hozio_Image_Optimizer_Report_Generator::generate_report_data();
            wp_send_json_success($data);
        }
    }

    /**
     * Send report via email
     */
    public function send_report_email() {
        $this->verify_request();

        $email = sanitize_email($_POST['email'] ?? '');

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => __('Please enter a valid email address', 'hozio-image-optimizer')));
        }

        $sent = Hozio_Image_Optimizer_Report_Generator::send_email_report($email);

        if ($sent) {
            wp_send_json_success(array('message' => __('Report sent successfully!', 'hozio-image-optimizer')));
        } else {
            wp_send_json_error(array('message' => __('Failed to send email. Please check your server mail settings.', 'hozio-image-optimizer')));
        }
    }

    /**
     * Get image comparison (original vs optimized)
     */
    public function get_image_comparison() {
        $this->verify_request();

        $attachment_id = absint($_POST['attachment_id'] ?? 0);

        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID', 'hozio-image-optimizer')));
        }

        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        $backup = $backup_manager->get_backup($attachment_id);
        $current_path = get_attached_file($attachment_id);

        if (!$backup || !$current_path || !file_exists($current_path)) {
            wp_send_json_error(array('message' => __('Could not load image comparison', 'hozio-image-optimizer')));
        }

        // Get original backup file info
        $original_size = $backup['file_size'];
        $original_url = '';

        // Try to get a web-accessible URL for the backup
        if (file_exists($backup['file_path'])) {
            // Create a temporary copy in uploads for viewing
            $upload_dir = wp_upload_dir();
            $temp_filename = 'hozio-compare-' . $attachment_id . '-' . basename($backup['file_path']);
            $temp_path = $upload_dir['path'] . '/' . $temp_filename;

            if (copy($backup['file_path'], $temp_path)) {
                $original_url = $upload_dir['url'] . '/' . $temp_filename;
                // Schedule cleanup
                wp_schedule_single_event(time() + 300, 'hozio_cleanup_temp_file', array($temp_path));
            }
        }

        // Current image info
        $current_size = filesize($current_path);
        $current_url = wp_get_attachment_url($attachment_id);

        // Calculate savings
        $saved = $original_size - $current_size;
        $percent_saved = $original_size > 0 ? round(($saved / $original_size) * 100, 1) : 0;

        wp_send_json_success(array(
            'original' => array(
                'url' => $original_url,
                'size' => $original_size,
                'size_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($original_size),
            ),
            'current' => array(
                'url' => $current_url,
                'size' => $current_size,
                'size_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($current_size),
            ),
            'savings' => array(
                'bytes' => $saved,
                'formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($saved),
                'percent' => $percent_saved,
            ),
        ));
    }

    /**
     * Start background optimization queue
     */
    public function start_background_queue() {
        $this->verify_request();

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        $image_ids = isset($_POST['image_ids']) ? array_map('intval', (array) $_POST['image_ids']) : array();
        if (empty($image_ids)) {
            wp_send_json_error(array('message' => __('No images selected', 'hozio-image-optimizer')));
        }

        $options = array(
            'compress' => isset($_POST['compress']) && $_POST['compress'] === 'true',
            'convert_webp' => isset($_POST['convert_webp']) && $_POST['convert_webp'] === 'true',
            'convert_avif' => isset($_POST['convert_avif']) && $_POST['convert_avif'] === 'true',
            'ai_rename' => isset($_POST['ai_rename']) && $_POST['ai_rename'] === 'true',
            'ai_alt' => isset($_POST['ai_alt']) && $_POST['ai_alt'] === 'true',
            'location' => sanitize_text_field($_POST['location'] ?? ''),
            'keyword' => sanitize_text_field($_POST['keyword'] ?? ''),
            'manual_lat' => sanitize_text_field($_POST['manual_lat'] ?? ''),
            'manual_lng' => sanitize_text_field($_POST['manual_lng'] ?? ''),
            'skip_optimized' => isset($_POST['skip_optimized']) && $_POST['skip_optimized'] === 'true',
            'force_reoptimize' => isset($_POST['force_reoptimize']) && $_POST['force_reoptimize'] === 'true',
            'create_backup' => isset($_POST['create_backup']) && $_POST['create_backup'] === 'true',
            'target_filesize' => intval($_POST['target_filesize'] ?? 0),
        );

        $processor = new Hozio_Image_Optimizer_Background_Processor();
        $status = $processor->start_queue($image_ids, $options);

        wp_send_json_success($status);
    }

    /**
     * Get background queue status
     */
    public function get_queue_status() {
        $this->verify_request();

        // Clear cache before reading to ensure fresh data
        wp_cache_delete('hozio_optimization_queue', 'options');
        wp_cache_delete('hozio_optimization_status', 'options');

        $processor = new Hozio_Image_Optimizer_Background_Processor();
        $status = $processor->get_status();

        // No status at all
        if (!$status) {
            wp_send_json_success(array('active' => false));
        }

        // For completed state, return full data so modal can be shown
        if ($status['state'] === 'completed') {
            $queue = $processor->get_queue();
            // Enrich queue items with filenames
            foreach ($queue as &$item) {
                $item['filename'] = basename(get_attached_file($item['id']));
            }

            wp_send_json_success(array(
                'active' => true,
                'state' => 'completed',
                'total' => $status['total'],
                'completed' => $status['completed'],
                'errors' => $status['errors'] ?? 0,
                'bytes_saved' => $status['bytes_saved'] ?? 0,
                'bytes_saved_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($status['bytes_saved'] ?? 0),
                'queue' => $queue,
            ));
        }

        // For cancelled state, just return inactive
        if ($status['state'] === 'cancelled') {
            wp_send_json_success(array('active' => false));
        }

        // For running or paused states
        if (!in_array($status['state'], array('running', 'paused'))) {
            wp_send_json_success(array('active' => false));
        }

        wp_send_json_success(array(
            'active' => true,
            'state' => $status['state'],
            'total' => $status['total'],
            'completed' => $status['completed'],
            'errors' => $status['errors'] ?? 0,
            'bytes_saved' => $status['bytes_saved'] ?? 0,
            'bytes_saved_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($status['bytes_saved'] ?? 0),
            'started_at' => $status['started_at'] ?? '',
            'last_activity' => $status['last_activity'] ?? '',
            'current_image_id' => $status['current_image_id'] ?? null,
        ));
    }

    /**
     * Pause background queue
     */
    public function pause_queue() {
        $this->verify_request();

        $processor = new Hozio_Image_Optimizer_Background_Processor();
        $status = $processor->pause_queue();

        wp_send_json_success($status);
    }

    /**
     * Resume background queue
     */
    public function resume_queue() {
        $this->verify_request();

        $processor = new Hozio_Image_Optimizer_Background_Processor();
        $status = $processor->resume_queue();

        wp_send_json_success($status);
    }

    /**
     * Cancel background queue
     */
    public function cancel_queue() {
        $this->verify_request();

        $processor = new Hozio_Image_Optimizer_Background_Processor();
        $processor->cancel_queue();
        // Clear the queue data after cancelling
        $processor->clear_queue();

        wp_send_json_success(array('cleared' => true));
    }

    /**
     * Clear background queue data
     */
    public function clear_queue() {
        $this->verify_request();

        $processor = new Hozio_Image_Optimizer_Background_Processor();
        $processor->clear_queue();

        wp_send_json_success(array('cleared' => true));
    }

    /**
     * Process a batch of the queue via AJAX
     * This is called by the frontend while the page is open
     */
    public function process_queue_batch() {
        $this->verify_request();

        // Clear cache before reading to ensure fresh data
        wp_cache_delete('hozio_optimization_queue', 'options');
        wp_cache_delete('hozio_optimization_status', 'options');

        $processor = new Hozio_Image_Optimizer_Background_Processor();

        // Check if queue is active
        $status = $processor->get_status();
        if (!$status || $status['state'] !== 'running') {
            wp_send_json_success(array(
                'processed' => false,
                'message' => 'Queue not running'
            ));
            return;
        }

        // Process a batch
        $processor->process_queue();

        // Clear cache again after processing to get fresh data
        wp_cache_delete('hozio_optimization_queue', 'options');
        wp_cache_delete('hozio_optimization_status', 'options');

        // Get updated status
        $status = $processor->get_status();

        // Get the filename of the just-processed image (may have changed due to rename)
        $current_image_id = $status['current_image_id'] ?? null;
        $current_filename = null;
        if ($current_image_id) {
            $file_path = get_attached_file($current_image_id);
            if ($file_path) {
                $current_filename = basename($file_path);
            }
        }

        $response = array(
            'processed' => true,
            'active' => in_array($status['state'], array('running', 'paused', 'completed')),
            'state' => $status['state'],
            'total' => $status['total'],
            'completed' => $status['completed'],
            'errors' => $status['errors'] ?? 0,
            'bytes_saved' => $status['bytes_saved'] ?? 0,
            'bytes_saved_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($status['bytes_saved'] ?? 0),
            'current_image_id' => $current_image_id,
            'current_filename' => $current_filename,
            'last_saved' => $status['last_saved'] ?? 0,
            'last_saved_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($status['last_saved'] ?? 0),
        );

        // If completed, include the queue details for the results modal
        if ($status['state'] === 'completed') {
            $queue = $processor->get_queue();
            $queue_bytes_saved = 0;

            // Enrich queue items with filenames and thumbnails, calculate fallback bytes_saved
            foreach ($queue as &$item) {
                $item['filename'] = basename(get_attached_file($item['id']));
                $item['thumbnail'] = wp_get_attachment_image_url($item['id'], 'thumbnail');

                // Accumulate per-item savings for fallback
                if (isset($item['result']['saved'])) {
                    $queue_bytes_saved += intval($item['result']['saved']);
                }
            }
            unset($item);

            $response['queue'] = $queue;

            // Use queue-calculated bytes_saved as fallback if status bytes_saved is 0
            if (($response['bytes_saved'] ?? 0) == 0 && $queue_bytes_saved > 0) {
                $response['bytes_saved'] = $queue_bytes_saved;
                $response['bytes_saved_formatted'] = Hozio_Image_Optimizer_Helpers::format_bytes($queue_bytes_saved);
            }
        }

        wp_send_json_success($response);
    }

    /**
     * Scan for unused images
     */
    public function scan_unused_images() {
        $this->verify_request();

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 50;

        $detector = new Hozio_Image_Optimizer_Unused_Detector();
        $results = $detector->scan_all_images($page, $per_page);

        // Persist results in database so they survive page navigation
        $detector->save_scan_results($results);

        $stats = $detector->get_stats();
        $results['stats'] = $stats;

        wp_send_json_success($results);
    }

    /**
     * Delete unused images and create ZIP archive
     */
    public function delete_unused_images() {
        $this->verify_request();

        if (!current_user_can('delete_posts')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        $image_ids = isset($_POST['image_ids']) ? array_map('intval', (array) $_POST['image_ids']) : array();
        if (empty($image_ids)) {
            wp_send_json_error(array('message' => __('No images selected', 'hozio-image-optimizer')));
        }

        // Create the archive first
        $exporter = new Hozio_Image_Optimizer_Cleanup_Exporter();
        $archive_result = $exporter->create_cleanup_archive($image_ids);

        if (is_wp_error($archive_result)) {
            wp_send_json_error(array('message' => $archive_result->get_error_message()));
        }

        // Delete the images
        $delete_result = $exporter->delete_images($image_ids);

        // Generate download URL
        $upload_dir = wp_upload_dir();
        $download_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $archive_result['path']);

        wp_send_json_success(array(
            'archive' => $archive_result,
            'download_url' => $download_url,
            'deleted' => $delete_result,
        ));
    }

    /**
     * Download unused images as ZIP without deleting them
     */
    public function download_unused_images() {
        $this->verify_request();

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        $image_ids = isset($_POST['image_ids']) ? array_map('intval', (array) $_POST['image_ids']) : array();
        if (empty($image_ids)) {
            wp_send_json_error(array('message' => __('No images selected', 'hozio-image-optimizer')));
        }

        $exporter = new Hozio_Image_Optimizer_Cleanup_Exporter();
        $archive = $exporter->create_cleanup_archive($image_ids);

        if (!$archive || !isset($archive['path'])) {
            wp_send_json_error(array('message' => __('Failed to create ZIP archive', 'hozio-image-optimizer')));
        }

        // Schedule cleanup of the temp ZIP after 1 hour
        wp_schedule_single_event(time() + 3600, 'hozio_cleanup_temp_zip', array($archive['path']));

        wp_send_json_success(array(
            'download_url' => str_replace(ABSPATH, site_url('/'), $archive['path']),
            'filename' => $archive['filename'] ?? 'unused-images.zip',
            'size' => $archive['size'] ?? 0,
            'image_count' => count($image_ids),
        ));
    }

    /**
     * Restore images from uploaded ZIP archive
     */
    public function restore_from_zip() {
        $this->verify_request();

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        if (empty($_FILES['archive']) || $_FILES['archive']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => __('No file uploaded or upload error', 'hozio-image-optimizer')));
        }

        $uploaded_file = $_FILES['archive'];

        // Verify it's a ZIP file
        $file_type = wp_check_filetype($uploaded_file['name']);
        if ($file_type['ext'] !== 'zip') {
            wp_send_json_error(array('message' => __('Please upload a ZIP file', 'hozio-image-optimizer')));
        }

        // Move to temp location
        $exporter = new Hozio_Image_Optimizer_Cleanup_Exporter();
        $temp_path = $exporter->get_temp_path('restore-' . time() . '.zip');

        if (!move_uploaded_file($uploaded_file['tmp_name'], $temp_path)) {
            wp_send_json_error(array('message' => __('Failed to process uploaded file', 'hozio-image-optimizer')));
        }

        // Restore from archive
        $result = $exporter->restore_from_archive($temp_path);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    /**
     * Toggle image protection status
     */
    public function toggle_image_protection() {
        $this->verify_request();

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID', 'hozio-image-optimizer')));
        }

        $detector = new Hozio_Image_Optimizer_Unused_Detector();
        $is_protected = $detector->toggle_protection($attachment_id);

        wp_send_json_success(array(
            'attachment_id' => $attachment_id,
            'is_protected' => $is_protected,
        ));
    }

    /**
     * Get references for an image (where it's used)
     */
    public function get_image_references() {
        $this->verify_request();

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID', 'hozio-image-optimizer')));
        }

        $detector = new Hozio_Image_Optimizer_Unused_Detector();
        $references = $detector->get_image_references($attachment_id);

        wp_send_json_success(array(
            'attachment_id' => $attachment_id,
            'references' => $references,
            'count' => count($references),
        ));
    }

    /**
     * Scan for broken images across the site
     */
    public function scan_broken_images() {
        $this->verify_request();

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        $detector = new Hozio_Image_Optimizer_Broken_Detector();
        $results = $detector->scan();

        // Persist results
        $detector->save_scan_results($results);

        wp_send_json_success($results);
    }

    /**
     * Resolve a broken image using a specified strategy
     */
    public function resolve_broken_image() {
        $this->verify_request();

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'hozio-image-optimizer')));
        }

        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        $broken_url = esc_url_raw($_POST['broken_url'] ?? '');
        $strategy = sanitize_key($_POST['strategy'] ?? '');
        $new_url = esc_url_raw($_POST['new_url'] ?? '');

        if (empty($strategy)) {
            wp_send_json_error(array('message' => __('Resolution strategy is required', 'hozio-image-optimizer')));
        }

        $detector = new Hozio_Image_Optimizer_Broken_Detector();
        $result = $detector->resolve($attachment_id, $broken_url, $strategy, array(
            'new_url' => $new_url,
        ));

        if ($result['success']) {
            // Clear cached scan results since they're now stale
            $detector->clear_saved_results();
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Dismiss the broken images banner for the current user
     */
    public function force_update_check() {
        check_ajax_referer('hozio_image_optimizer_nonce', 'nonce');
        if (!current_user_can('update_plugins')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        global $hozio_imgopt_updater;
        if ($hozio_imgopt_updater) {
            $hozio_imgopt_updater->force_update_check();
        }

        // Check if update is available
        $update_plugins = get_site_transient('update_plugins');
        $plugin_file = basename(HOZIO_IMAGE_OPTIMIZER_DIR) . '/hozio-image-optimizer.php';
        $update_available = isset($update_plugins->response[$plugin_file]);
        $latest_version = $update_available ? $update_plugins->response[$plugin_file]->new_version : HOZIO_IMAGE_OPTIMIZER_VERSION;

        wp_send_json_success(array(
            'update_available' => $update_available,
            'latest_version' => $latest_version,
            'current_version' => HOZIO_IMAGE_OPTIMIZER_VERSION,
        ));
    }

    public function save_auto_update() {
        check_ajax_referer('hozio_image_optimizer_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        $enabled = sanitize_text_field($_POST['enabled'] ?? '0');
        update_option('hozio_imgopt_auto_updates_enabled', $enabled);
        wp_send_json_success();
    }

    public function dismiss_broken_banner() {
        check_ajax_referer('hozio_image_optimizer_nonce', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        $last_scan = get_option('hozio_daily_broken_scan_time', '');
        update_user_meta(get_current_user_id(), 'hozio_broken_banner_dismissed', $last_scan);
        wp_send_json_success();
    }
}
