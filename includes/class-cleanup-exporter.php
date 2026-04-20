<?php
/**
 * Cleanup Exporter
 *
 * Handles creating ZIP archives of deleted images and restoring from archives.
 *
 * @package Hozio_Image_Optimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Cleanup_Exporter {

    /**
     * Temporary directory for building archives
     */
    private $temp_dir;

    /**
     * Constructor
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->temp_dir = $upload_dir['basedir'] . '/hozio-temp/';

        // Ensure temp directory is protected from public access
        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
            file_put_contents($this->temp_dir . '.htaccess', "Order deny,allow\nDeny from all");
            file_put_contents($this->temp_dir . 'index.php', '<?php // Silence is golden');
        }
    }

    /**
     * Create a cleanup archive containing all specified images
     *
     * @param array $image_ids Array of attachment IDs to archive
     * @return array|WP_Error Archive info or error
     */
    public function create_cleanup_archive($image_ids) {
        if (empty($image_ids)) {
            return new WP_Error('no_images', __('No images specified', 'hozio-image-optimizer'));
        }

        // Ensure ZipArchive is available
        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_zip', __('ZipArchive extension is not available', 'hozio-image-optimizer'));
        }

        // Create temp directory
        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
        }

        // Generate archive filename
        $date_string = function_exists('wp_date') ? wp_date('Y-m-d') : date_i18n('Y-m-d');
        $archive_filename = 'Hozio-Deleted-Images-' . $date_string . '.zip';
        $archive_path = $this->temp_dir . $archive_filename;

        // Remove existing file if present
        if (file_exists($archive_path)) {
            unlink($archive_path);
        }

        $zip = new ZipArchive();
        $result = $zip->open($archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            return new WP_Error('zip_create_failed', __('Failed to create ZIP archive', 'hozio-image-optimizer'));
        }

        $manifest = array(
            'created' => current_time('mysql'),
            'plugin_version' => HOZIO_IMAGE_OPTIMIZER_VERSION,
            'site_url' => get_site_url(),
            'image_count' => count($image_ids),
            'total_size' => 0,
            'images' => array(),
        );

        $used_filenames = array();

        foreach ($image_ids as $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !file_exists($file_path)) {
                continue;
            }

            $filename = basename($file_path);
            $file_size = filesize($file_path);

            // Handle duplicate filenames by prepending ID
            if (isset($used_filenames[$filename])) {
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $filename = $name . '-' . $attachment_id . '.' . $ext;
            }
            $used_filenames[$filename] = true;

            $manifest['images'][] = array(
                'id'                => $attachment_id,
                'filename'          => $filename,
                'original_filename' => basename($file_path),
                'size'              => $file_size,
                'relative_path'     => get_post_meta($attachment_id, '_wp_attached_file', true),
                'alt_text'          => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            );
            $manifest['total_size'] += $file_size;

            // Add only the original image file (no thumbnails, no nested folders)
            $zip->addFile($file_path, $filename);
        }

        // Add manifest for restore capability
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $zip->close();

        if (!file_exists($archive_path)) {
            return new WP_Error('zip_not_created', __('ZIP file was not created', 'hozio-image-optimizer'));
        }

        return array(
            'path' => $archive_path,
            'filename' => $archive_filename,
            'size' => filesize($archive_path),
            'size_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes(filesize($archive_path)),
            'image_count' => count($image_ids),
        );
    }

    /**
     * Get complete data for an image including all files and metadata
     *
     * @param int $attachment_id The attachment ID
     * @return array|false Image data or false
     */
    public function get_complete_image_data($attachment_id) {
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return false;
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $metadata = wp_get_attachment_metadata($attachment_id);

        // Collect all files (original + thumbnails)
        $all_files = array();
        $total_size = 0;

        // Main file
        $main_size = filesize($file_path);
        $all_files[] = array(
            'name' => basename($file_path),
            'path' => $file_path,
            'size' => $main_size,
            'type' => 'original',
        );
        $total_size += $main_size;

        // Thumbnail files
        if (!empty($metadata['sizes'])) {
            $base_dir = dirname($file_path);
            foreach ($metadata['sizes'] as $size_name => $size_info) {
                $thumb_path = $base_dir . '/' . $size_info['file'];
                if (file_exists($thumb_path)) {
                    $thumb_size = filesize($thumb_path);
                    $all_files[] = array(
                        'name' => $size_info['file'],
                        'path' => $thumb_path,
                        'size' => $thumb_size,
                        'type' => 'thumbnail',
                        'size_name' => $size_name,
                        'width' => $size_info['width'],
                        'height' => $size_info['height'],
                    );
                    $total_size += $thumb_size;
                }
            }
        }

        // Get all post meta
        $all_meta = get_post_meta($attachment_id);

        // Calculate relative path from uploads directory
        $relative_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $original_upload_path = dirname($relative_file);

        return array(
            'attachment_id' => $attachment_id,
            'post_title' => $attachment->post_title,
            'post_content' => $attachment->post_content,
            'post_excerpt' => $attachment->post_excerpt,
            'post_name' => $attachment->post_name,
            'post_date' => $attachment->post_date,
            'post_modified' => $attachment->post_modified,
            'post_mime_type' => $attachment->post_mime_type,
            'post_parent' => $attachment->post_parent,
            'guid' => $attachment->guid,
            'menu_order' => $attachment->menu_order,
            'filename' => basename($file_path),
            '_wp_attached_file' => $relative_file,
            '_wp_attachment_metadata' => $metadata,
            '_wp_attachment_image_alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'original_upload_path' => $original_upload_path,
            'all_files' => $all_files,
            'total_size' => $total_size,
            'all_meta' => $all_meta,
        );
    }

    /**
     * Delete images permanently
     *
     * @param array $image_ids Array of attachment IDs to delete
     * @return array Results
     */
    public function delete_images($image_ids) {
        $deleted = array();
        $failed = array();

        foreach ($image_ids as $attachment_id) {
            // Use WordPress function to fully delete attachment and files
            $result = wp_delete_attachment($attachment_id, true); // true = force delete, skip trash
            if ($result) {
                $deleted[] = $attachment_id;
            } else {
                $failed[] = $attachment_id;
            }
        }

        return array(
            'deleted' => $deleted,
            'failed' => $failed,
            'deleted_count' => count($deleted),
            'failed_count' => count($failed),
        );
    }

    /**
     * Restore images from a ZIP archive (single-request, legacy path).
     * For large archives use restore_session_start() + restore_session_batch() instead.
     *
     * @param string $zip_path Path to the uploaded ZIP file.
     * @return array|WP_Error
     */
    public function restore_from_archive($zip_path) {
        $init = $this->restore_session_start($zip_path);
        if (is_wp_error($init)) {
            return $init;
        }

        $token      = $init['token'];
        $total      = $init['total'];
        $batch_size = 20;
        $offset     = 0;
        $restored   = array();
        $failed     = array();

        while ($offset < $total) {
            $batch = $this->restore_session_batch($token, $offset, $batch_size);
            if (is_wp_error($batch)) {
                return $batch;
            }
            $restored = array_merge($restored, $batch['restored']);
            $failed   = array_merge($failed,   $batch['failed']);
            $offset   = $batch['next_offset'];
            if ($batch['done']) break;
        }

        return array(
            'restored'       => $restored,
            'failed'         => $failed,
            'restored_count' => count($restored),
            'failed_count'   => count($failed),
        );
    }

    /**
     * Start a batched restore session.
     *
     * Reads manifest.json from the ZIP and stores the session in a transient.
     * Returns a token the caller should pass to restore_session_batch().
     *
     * @param string $zip_path Path to the uploaded ZIP (must already be on disk).
     * @return array|WP_Error {token, total} or error.
     */
    public function restore_session_start($zip_path) {
        if (!file_exists($zip_path)) {
            return new WP_Error('file_not_found', __('Archive file not found', 'hozio-image-optimizer'));
        }

        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_zip', __('ZipArchive extension is not available', 'hozio-image-optimizer'));
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return new WP_Error('zip_open_failed', __('Failed to open ZIP archive', 'hozio-image-optimizer'));
        }

        $manifest_content = $zip->getFromName('manifest.json');
        $zip->close();

        if (!$manifest_content) {
            return new WP_Error('invalid_archive', __('Invalid archive: manifest.json not found', 'hozio-image-optimizer'));
        }

        $manifest = json_decode($manifest_content, true);
        if (!$manifest || empty($manifest['images'])) {
            return new WP_Error('invalid_manifest', __('Invalid manifest data', 'hozio-image-optimizer'));
        }

        $token = 'hozio_restore_' . get_current_user_id() . '_' . uniqid();
        set_transient($token, array(
            'zip_path' => $zip_path,
            'manifest' => $manifest,
        ), HOUR_IN_SECONDS);

        return array(
            'token' => $token,
            'total' => count($manifest['images']),
        );
    }

    /**
     * Process one batch of images from an active restore session.
     *
     * @param string $token     Session token from restore_session_start().
     * @param int    $offset    Number of images already processed.
     * @param int    $batch_size Images to process in this request.
     * @return array|WP_Error
     */
    public function restore_session_batch($token, $offset, $batch_size = 5) {
        $session = get_transient($token);
        if (!$session) {
            return new WP_Error('session_expired', __('Restore session expired — please re-upload the ZIP.', 'hozio-image-optimizer'));
        }

        $zip_path = $session['zip_path'];
        $manifest = $session['manifest'];

        if (!file_exists($zip_path)) {
            delete_transient($token);
            return new WP_Error('zip_missing', __('Restore archive not found on server.', 'hozio-image-optimizer'));
        }

        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_zip', __('ZipArchive extension is not available', 'hozio-image-optimizer'));
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return new WP_Error('zip_open_failed', __('Failed to open ZIP archive', 'hozio-image-optimizer'));
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $upload_dir = wp_upload_dir();

        $images = $manifest['images'];
        $total  = count($images);
        $batch  = array_slice($images, $offset, $batch_size);

        $restored = array();
        $failed   = array();

        foreach ($batch as $image_info) {
            $result = $this->restore_single_image($zip, $image_info, $upload_dir);
            if (isset($result['error'])) {
                $failed[] = $result;
            } else {
                $restored[] = $result;
            }
        }

        $zip->close();

        $next_offset = $offset + count($batch);
        $done        = $next_offset >= $total;

        if ($done) {
            @unlink($zip_path);
            delete_transient($token);
        }

        return array(
            'done'           => $done,
            'restored'       => $restored,
            'failed'         => $failed,
            'restored_count' => count($restored),
            'failed_count'   => count($failed),
            'next_offset'    => $next_offset,
            'total'          => $total,
        );
    }

    /**
     * Restore a single image from an open ZipArchive to the uploads directory.
     *
     * @param ZipArchive $zip        Open archive.
     * @param array      $image_info Entry from manifest['images'].
     * @param array      $upload_dir wp_upload_dir() result.
     * @return array {original_id, new_id, filename, url} or {error, id, filename, reason}.
     */
    private function restore_single_image($zip, $image_info, $upload_dir) {
        $old_id       = (int) $image_info['id'];
        $zip_filename = $image_info['filename'];
        $orig_name    = $image_info['original_filename'];

        // Restore to original path if available (v1.7.0+ ZIPs); fall back to current month.
        if (!empty($image_info['relative_path'])) {
            $rel_path = $image_info['relative_path'];
        } else {
            $rel_path = date('Y/m') . '/' . $orig_name;
        }

        $target_dir  = trailingslashit($upload_dir['basedir'] . '/' . dirname($rel_path));
        $target_name = basename($rel_path);
        $target_path = $target_dir . $target_name;

        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        if (file_exists($target_path)) {
            $pi      = pathinfo($target_name);
            $counter = 1;
            do {
                $target_name = $pi['filename'] . '-restored-' . $counter . '.' . $pi['extension'];
                $target_path = $target_dir . $target_name;
                $rel_path    = dirname($rel_path) . '/' . $target_name;
                $counter++;
            } while (file_exists($target_path));
        }

        $file_content = $zip->getFromName($zip_filename);
        if ($file_content === false) {
            return array('error' => true, 'id' => $old_id, 'filename' => $zip_filename, 'reason' => 'Not found in archive');
        }

        if (file_put_contents($target_path, $file_content) === false) {
            return array('error' => true, 'id' => $old_id, 'filename' => $zip_filename, 'reason' => 'Write failed');
        }

        $image_check = @getimagesize($target_path);
        if (!$image_check) {
            @unlink($target_path);
            return array('error' => true, 'id' => $old_id, 'filename' => $zip_filename, 'reason' => 'Not a valid image');
        }

        $title = pathinfo($orig_name, PATHINFO_FILENAME);
        $guid  = $upload_dir['baseurl'] . '/' . ltrim($rel_path, '/');

        // Try to preserve the original attachment ID. wp_insert_post (which
        // wp_insert_attachment calls under the hood) honors `import_id` iff
        // no post currently occupies that ID. When the slot is free, every
        // existing DB/theme/cache reference keeps working with zero rewrites.
        $new_id = wp_insert_attachment(array(
            'import_id'      => $old_id,
            'post_title'     => $title,
            'post_mime_type' => $image_check['mime'],
            'post_status'    => 'inherit',
            'guid'           => $guid,
        ), $target_path);

        if (is_wp_error($new_id)) {
            @unlink($target_path);
            return array('error' => true, 'id' => $old_id, 'filename' => $zip_filename, 'reason' => $new_id->get_error_message());
        }

        $id_preserved = ((int) $new_id === (int) $old_id);

        update_post_meta($new_id, '_wp_attached_file', $rel_path);

        $attach_meta = wp_generate_attachment_metadata($new_id, $target_path);
        wp_update_attachment_metadata($new_id, $attach_meta);

        if (!empty($image_info['alt_text'])) {
            update_post_meta($new_id, '_wp_attachment_image_alt', sanitize_text_field($image_info['alt_text']));
        }

        // Only rewrite DB references when the original ID couldn't be reclaimed.
        if (!$id_preserved) {
            $this->update_id_references($old_id, $new_id);
        }

        return array(
            'original_id'  => $old_id,
            'new_id'       => (int) $new_id,
            'filename'     => $target_name,
            'url'          => wp_get_attachment_url($new_id),
            'thumbnail'    => wp_get_attachment_image_url($new_id, 'thumbnail'),
            'id_preserved' => $id_preserved,
        );
    }

    /**
     * Update every place on the site that references $old_id to point to $new_id.
     *
     * Covers: featured images, WooCommerce galleries, JSON-quoted ID references
     * in postmeta and options (ACF, Elementor, page builders, widgets, customizer),
     * and exact-integer termmeta values (category image IDs).
     *
     * @param int $old_id Original attachment ID that no longer exists.
     * @param int $new_id Newly created attachment ID.
     */
    private function update_id_references($old_id, $new_id) {
        global $wpdb;
        $old = (int) $old_id;
        $new = (int) $new_id;

        // Featured image.
        $wpdb->update(
            $wpdb->postmeta,
            array('meta_value' => $new),
            array('meta_key' => '_thumbnail_id', 'meta_value' => $old)
        );

        // WooCommerce product gallery (comma-separated IDs).
        $galleries = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
             AND (meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)",
            $old,
            $old . ',%',
            '%,' . $old . ',%',
            '%,' . $old
        ));
        foreach ($galleries as $row) {
            $ids     = array_filter(explode(',', $row->meta_value), 'is_numeric');
            $updated = implode(',', array_map(function ($v) use ($old, $new) {
                return (int) $v === $old ? $new : (int) $v;
            }, $ids));
            $wpdb->update($wpdb->postmeta, array('meta_value' => $updated), array('meta_id' => $row->meta_id));
        }

        // JSON-quoted ID in any postmeta (ACF, Elementor, page builders, etc.).
        // REPLACE() is safe: '"123"' cannot be a substring of '"1234"' or '"12"'.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta}
             SET meta_value = REPLACE(meta_value, %s, %s)
             WHERE meta_value LIKE %s",
            '"' . $old . '"',
            '"' . $new . '"',
            '%"' . $old . '"%'
        ));

        // JSON-quoted ID in options (widgets, customizer, theme settings).
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = REPLACE(option_value, %s, %s)
             WHERE option_value LIKE %s
             AND option_name NOT LIKE '\_transient%'
             AND option_name NOT LIKE '\_site\_transient%'",
            '"' . $old . '"',
            '"' . $new . '"',
            '%"' . $old . '"%'
        ));

        // Termmeta exact integer (category/term image IDs stored as bare integers).
        $wpdb->update(
            $wpdb->termmeta,
            array('meta_value' => $new),
            array('meta_value' => $old)
        );
    }

    /**
     * Clean up temporary files
     */
    public function cleanup_temp_files() {
        if (file_exists($this->temp_dir)) {
            $files = glob($this->temp_dir . '*');
            foreach ($files as $file) {
                if (is_file($file) && (time() - filemtime($file)) > 3600) { // Older than 1 hour
                    unlink($file);
                }
            }
        }
    }

    /**
     * Get the path to a temporary archive file
     *
     * @param string $filename The filename
     * @return string Full path
     */
    public function get_temp_path($filename) {
        return $this->temp_dir . $filename;
    }
}
