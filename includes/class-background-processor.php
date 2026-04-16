<?php
/**
 * Background Processor for Hozio Image Optimizer
 * Handles optimization queue that persists across page loads
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Background_Processor {

    /**
     * Option name for the queue
     */
    const QUEUE_OPTION = 'hozio_optimization_queue';

    /**
     * Option name for queue status
     */
    const STATUS_OPTION = 'hozio_optimization_status';

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'hozio_process_optimization_queue';

    /**
     * Batch size - how many images to process per cron run
     */
    const BATCH_SIZE = 5;

    /**
     * Constructor
     */
    public function __construct() {
        add_action(self::CRON_HOOK, array($this, 'process_queue'));
    }

    /**
     * Start a new optimization queue
     *
     * @param array $image_ids Array of attachment IDs to optimize
     * @param array $options Optimization options
     * @return array Status info
     */
    public function start_queue($image_ids, $options = array()) {
        // Clear any existing queue
        $this->clear_queue();

        // Default options
        $default_options = array(
            'compress' => true,
            'convert_webp' => get_option('hozio_convert_to_webp', true),
            'convert_avif' => get_option('hozio_convert_to_avif', false),
            'ai_rename' => false,
            'ai_alt' => false,
            'location' => '',
            'keyword' => '',
            'manual_lat' => '',
            'manual_lng' => '',
            'skip_optimized' => true,
            'force_reoptimize' => false,
            'create_backup' => get_option('hozio_backup_enabled', false),
        );
        $options = wp_parse_args($options, $default_options);

        // Create queue items - filter out already optimized images unless force is enabled
        $queue = array();
        $skipped_count = 0;
        foreach ($image_ids as $id) {
            $id = intval($id);

            // Check if already optimized (unless force re-optimization is enabled)
            if (!$options['force_reoptimize'] && $options['skip_optimized']) {
                $is_optimized = get_post_meta($id, '_hozio_optimized', true);
                if ($is_optimized) {
                    $skipped_count++;
                    continue; // Skip this image
                }
            }

            $queue[] = array(
                'id' => $id,
                'status' => 'pending', // pending, processing, completed, error
                'error' => '',
                'result' => null,
            );
        }

        // If all images were skipped, return early with message
        if (empty($queue)) {
            return array(
                'state' => 'completed',
                'total' => 0,
                'completed' => 0,
                'skipped' => $skipped_count,
                'message' => 'All selected images have already been optimized. Enable "Force Re-optimization" to optimize them again.',
            );
        }

        // Save queue
        update_option(self::QUEUE_OPTION, $queue, false);

        // Save status
        $status = array(
            'state' => 'running', // running, paused, completed, cancelled
            'total' => count($queue), // Only count images that will be processed
            'completed' => 0,
            'errors' => 0,
            'skipped' => $skipped_count, // Track skipped images
            'bytes_saved' => 0,
            'started_at' => current_time('mysql'),
            'last_activity' => current_time('mysql'),
            'options' => $options,
        );
        update_option(self::STATUS_OPTION, $status, false);

        // Schedule immediate processing
        $this->schedule_processing();

        return $status;
    }

    /**
     * Schedule the next processing run
     */
    private function schedule_processing() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        }
    }

    /**
     * Process the queue (called by cron or AJAX)
     */
    public function process_queue() {
        $status = $this->get_status();

        if (!$status || $status['state'] !== 'running') {
            return;
        }

        $queue = get_option(self::QUEUE_OPTION, array());
        if (empty($queue)) {
            $this->complete_queue();
            return;
        }

        $options = $status['options'];
        $processed = 0;

        // Find next pending item
        $next_index = -1;
        foreach ($queue as $idx => $item) {
            if ($item['status'] === 'pending') {
                $next_index = $idx;
                break;
            }
        }

        // No pending items - check if all are done
        if ($next_index === -1) {
            $this->complete_queue();
            return;
        }

        // Process one item at a time for better UI updates
        $item = $queue[$next_index];
        $attachment_id = $item['id'];

        // Update status to show current image being processed
        $status['current_image_id'] = $attachment_id;
        $status['last_activity'] = current_time('mysql');
        update_option(self::STATUS_OPTION, $status, false);

        // Mark as processing in queue
        $queue[$next_index]['status'] = 'processing';
        update_option(self::QUEUE_OPTION, $queue, false);

        // Process the image
        $result = $this->optimize_single_image($attachment_id, $options);

        // Update queue with result
        $item_saved = 0;
        $is_skipped = !empty($result['skipped']);
        $is_success = $result['success'] || $is_skipped; // Treat skipped as success for UI purposes

        if ($is_success) {
            $queue[$next_index]['status'] = 'completed';
            $queue[$next_index]['result'] = $result;
            $item_saved = isset($result['saved']) ? intval($result['saved']) : 0;
            // Mark skipped items so UI can display them differently
            if ($is_skipped) {
                $queue[$next_index]['skipped'] = true;
                $queue[$next_index]['result']['detail'] = $result['message'] ?? 'Already optimized';
            }
        } else {
            $queue[$next_index]['status'] = 'error';
            $queue[$next_index]['error'] = isset($result['message']) ? $result['message'] : 'Unknown error';
        }
        update_option(self::QUEUE_OPTION, $queue, false);

        // Update status with results
        $status = $this->get_status(); // Re-fetch to get latest
        $status['completed'] = ($status['completed'] ?? 0) + 1;
        // Only count as error if it's a real error, not a skip
        $status['errors'] = ($status['errors'] ?? 0) + ($is_success ? 0 : 1);
        $status['bytes_saved'] = ($status['bytes_saved'] ?? 0) + $item_saved;
        $status['last_activity'] = current_time('mysql');
        $status['current_image_id'] = $attachment_id;
        $status['last_saved'] = $item_saved; // Track last item's savings for UI
        update_option(self::STATUS_OPTION, $status, false);

        // Check if there are more pending items and set next image ID
        $queue = get_option(self::QUEUE_OPTION, array()); // Re-fetch
        $has_pending = false;
        $next_pending_id = null;
        foreach ($queue as $q_item) {
            if ($q_item['status'] === 'pending') {
                $has_pending = true;
                $next_pending_id = $q_item['id'];
                break;
            }
        }

        if (!$has_pending) {
            $this->complete_queue();
        } else {
            // Update current_image_id to the NEXT pending item so the UI can show it
            $status = $this->get_status();
            if ($status && $next_pending_id) {
                $status['current_image_id'] = $next_pending_id;
                update_option(self::STATUS_OPTION, $status, false);
                wp_cache_delete(self::STATUS_OPTION, 'options');
            }

            // Reschedule cron so processing continues even if browser is closed
            $this->schedule_processing();
        }
    }

    /**
     * Optimize a single image - mirrors the AJAX handler logic for consistency
     *
     * @param int $attachment_id
     * @param array $options
     * @return array Result
     */
    private function optimize_single_image($attachment_id, $options) {
        try {
            // CRITICAL SAFETY CHECK: Never re-optimize without explicit force flag
            $is_already_optimized = get_post_meta($attachment_id, '_hozio_optimized', true);
            if ($is_already_optimized && empty($options['force_reoptimize'])) {
                error_log("Hozio Background: BLOCKED re-optimization of already-optimized image $attachment_id (force_reoptimize not set)");
                return array(
                    'success' => false,
                    'message' => 'Image already optimized. Enable force re-optimization to process again.',
                    'skipped' => true
                );
            }

            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !file_exists($file_path)) {
                return array('success' => false, 'message' => 'File not found');
            }

            // Validate the image file is actually valid before any operations
            $image_info = @getimagesize($file_path);
            if (!$image_info) {
                error_log("Hozio Background: Image $attachment_id is not a valid image file");
                return array('success' => false, 'message' => 'Invalid image file');
            }

            $original_size = filesize($file_path);
            $operations = array();

            // Process operations directly without the safety wrapper
            // The safety wrapper's post-validation was causing false "file not created" errors
            // We do our own validation at the end of this function instead

            // Step 1: Compress
            if ($options['compress'] && get_option('hozio_enable_compression', true)) {
                $compressor = new Hozio_Image_Optimizer_Compressor();
                $quality = get_option('hozio_compression_quality', 82);
                $compress_options = array(
                    'quality' => $quality,
                    'force' => !empty($options['force_reoptimize']),
                    'target_filesize' => intval($options['target_filesize'] ?? 0),
                );
                $compress_result = $compressor->compress_attachment($attachment_id, $compress_options);
                if (!empty($compress_result['success']) && empty($compress_result['skipped'])) {
                    $operations[] = 'compressed';
                }
                $file_path = get_attached_file($attachment_id);
            }

            // Step 2: Convert format
            if ($options['convert_webp'] || $options['convert_avif']) {
                $converter = new Hozio_Image_Optimizer_Format_Converter();

                if ($options['convert_avif'] && Hozio_Image_Optimizer_Format_Converter::avif_supported()) {
                    $convert_result = $converter->convert_attachment($attachment_id, 'avif');
                    if (!empty($convert_result['success']) && empty($convert_result['skipped'])) {
                        $operations[] = 'converted to AVIF';
                    }
                } elseif ($options['convert_webp'] && Hozio_Image_Optimizer_Format_Converter::webp_supported()) {
                    $convert_result = $converter->convert_attachment($attachment_id, 'webp');
                    if (!empty($convert_result['success']) && empty($convert_result['skipped'])) {
                        $operations[] = 'converted to WebP';
                    }
                }
                $file_path = get_attached_file($attachment_id);
            }

            // Step 3: Inject geolocation - ONLY for JPEG files to avoid corrupting WebP/AVIF
            $mime_type = mime_content_type($file_path);
            $is_jpeg = in_array($mime_type, array('image/jpeg', 'image/jpg'));

            if ($is_jpeg && get_option('hozio_enable_geolocation', true)) {
                if (!empty($options['manual_lat']) && !empty($options['manual_lng'])) {
                    $geo_result = Hozio_Image_Optimizer_Geolocation::inject_coordinates(
                        $file_path,
                        floatval($options['manual_lat']),
                        floatval($options['manual_lng'])
                    );
                    if (!empty($geo_result['success'])) {
                        $operations[] = 'geolocation added';
                    }
                } elseif (!empty($options['location'])) {
                    $geo_result = Hozio_Image_Optimizer_Geolocation::inject_geolocation(
                        $file_path,
                        $options['location']
                    );
                    if (!empty($geo_result['success'])) {
                        $operations[] = 'geolocation added';
                    }
                }
            }

            // Step 4: Rename with AI
            if ($options['ai_rename'] && get_option('hozio_enable_ai_rename', true)) {
                $renamer = new Hozio_Image_Optimizer_File_Renamer();
                $rename_result = $renamer->rename_with_ai(
                    $attachment_id,
                    $options['location'] ?? '',
                    $options['keyword'] ?? ''
                );
                if (!empty($rename_result['success'])) {
                    $operations[] = 'AI renamed';
                }
                $file_path = get_attached_file($attachment_id);
            }

            // Step 5: Generate alt text
            if ($options['ai_alt'] && get_option('hozio_enable_ai_alt_text', true)) {
                $analyzer = new Hozio_Image_Optimizer_Analyzer();
                $alt_result = $analyzer->generate_and_apply_alt_text($attachment_id);
                if (!empty($alt_result['success'])) {
                    $operations[] = 'AI alt text';
                }
            }

            // Get new size - get fresh path from WordPress
            $new_file_path = get_attached_file($attachment_id);
            clearstatcache(true, $new_file_path);
            $new_size = file_exists($new_file_path) ? filesize($new_file_path) : $original_size;
            $saved = max(0, $original_size - $new_size);

            // Debug: Log the savings calculation
            error_log("Hozio Background: attachment $attachment_id - original: $original_size, new: $new_size, saved: $saved");

            // Final validation: ensure the image is still valid
            $final_check = @getimagesize($new_file_path);
            if (!$final_check) {
                error_log("Hozio Background: Image corrupted after optimization for attachment $attachment_id");
                return array(
                    'success' => false,
                    'message' => 'Image was corrupted during optimization'
                );
            }

            // Mark as optimized and clear any restored flag
            update_post_meta($attachment_id, '_hozio_optimized', true);
            update_post_meta($attachment_id, '_hozio_optimized_date', current_time('mysql'));
            update_post_meta($attachment_id, '_hozio_original_size', $original_size);
            update_post_meta($attachment_id, '_hozio_optimized_size', $new_size);
            delete_post_meta($attachment_id, '_hozio_restored');

            return array(
                'success' => true,
                'saved' => $saved,
                'original_size' => $original_size,
                'new_size' => $new_size,
                'operations' => $operations,
            );

        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Complete the queue
     */
    private function complete_queue() {
        // Clear cache FIRST to prevent stale reads during the update
        wp_cache_delete(self::QUEUE_OPTION, 'options');
        wp_cache_delete(self::STATUS_OPTION, 'options');

        $status = $this->get_status();
        if ($status) {
            $status['state'] = 'completed';
            $status['completed_at'] = current_time('mysql');
            update_option(self::STATUS_OPTION, $status, false);

            // Clear cache AGAIN after the write to ensure next read gets fresh data
            wp_cache_delete(self::STATUS_OPTION, 'options');
        }

        // Clear the scheduled event
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Pause the queue
     */
    public function pause_queue() {
        $status = $this->get_status();
        if ($status && $status['state'] === 'running') {
            $status['state'] = 'paused';
            $status['paused_at'] = current_time('mysql');
            update_option(self::STATUS_OPTION, $status, false);
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
        return $this->get_status();
    }

    /**
     * Resume the queue
     */
    public function resume_queue() {
        $status = $this->get_status();
        if ($status && $status['state'] === 'paused') {
            $status['state'] = 'running';
            $status['resumed_at'] = current_time('mysql');
            update_option(self::STATUS_OPTION, $status, false);
            $this->schedule_processing();
        }
        return $this->get_status();
    }

    /**
     * Cancel the queue
     */
    public function cancel_queue() {
        $status = $this->get_status();
        if ($status) {
            $status['state'] = 'cancelled';
            $status['cancelled_at'] = current_time('mysql');
            update_option(self::STATUS_OPTION, $status, false);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
        return $this->get_status();
    }

    /**
     * Clear the queue completely
     */
    public function clear_queue() {
        delete_option(self::QUEUE_OPTION);
        delete_option(self::STATUS_OPTION);
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Get current queue status
     *
     * @return array|null
     */
    public function get_status() {
        return get_option(self::STATUS_OPTION, null);
    }

    /**
     * Get queue items
     *
     * @return array
     */
    public function get_queue() {
        return get_option(self::QUEUE_OPTION, array());
    }

    /**
     * Check if a queue is active (running or paused)
     *
     * @return bool
     */
    public function has_active_queue() {
        $status = $this->get_status();
        return $status && in_array($status['state'], array('running', 'paused'));
    }
}
