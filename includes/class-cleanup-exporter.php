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
     * Restore images from a ZIP archive created by create_cleanup_archive().
     *
     * Reads manifest.json from the ZIP root, restores each image to its original
     * upload path, creates a new WP attachment record, and updates all ID-based
     * references (featured images, galleries, ACF/page-builder postmeta, options,
     * termmeta) from the old attachment ID to the new one so broken frontend
     * references are healed automatically.
     *
     * @param string $zip_path Path to the uploaded ZIP file.
     * @return array|WP_Error Restore results or error.
     */
    public function restore_from_archive($zip_path) {
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
        if (!$manifest_content) {
            $zip->close();
            return new WP_Error('invalid_archive', __('Invalid archive: manifest.json not found', 'hozio-image-optimizer'));
        }

        $manifest = json_decode($manifest_content, true);
        if (!$manifest || empty($manifest['images'])) {
            $zip->close();
            return new WP_Error('invalid_manifest', __('Invalid manifest data', 'hozio-image-optimizer'));
        }

        $upload_dir = wp_upload_dir();
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $restored = array();
        $failed   = array();

        foreach ($manifest['images'] as $image_info) {
            $old_id       = (int) $image_info['id'];
            $zip_filename = $image_info['filename'];
            $orig_name    = $image_info['original_filename'];

            // Determine the restore path using saved relative_path (e.g. "2023/06/image.jpg").
            // ZIPs created before v1.7.0 won't have this field; fall back to current year/month.
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

            // If original file path is already occupied, append -restored-N suffix.
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

            // Extract file from ZIP root.
            $file_content = $zip->getFromName($zip_filename);
            if ($file_content === false) {
                $failed[] = array('id' => $old_id, 'filename' => $zip_filename, 'reason' => 'Not found in archive');
                continue;
            }

            if (file_put_contents($target_path, $file_content) === false) {
                $failed[] = array('id' => $old_id, 'filename' => $zip_filename, 'reason' => 'Write failed');
                continue;
            }

            // Security: ensure the extracted file is a valid image.
            $image_check = @getimagesize($target_path);
            if (!$image_check) {
                @unlink($target_path);
                $failed[] = array('id' => $old_id, 'filename' => $zip_filename, 'reason' => 'Not a valid image');
                continue;
            }

            // Create the WP attachment post.
            $title = pathinfo($orig_name, PATHINFO_FILENAME);
            $guid  = $upload_dir['baseurl'] . '/' . ltrim($rel_path, '/');

            $new_id = wp_insert_attachment(array(
                'post_title'     => $title,
                'post_mime_type' => $image_check['mime'],
                'post_status'    => 'inherit',
                'guid'           => $guid,
            ), $target_path);

            if (is_wp_error($new_id)) {
                @unlink($target_path);
                $failed[] = array('id' => $old_id, 'filename' => $zip_filename, 'reason' => $new_id->get_error_message());
                continue;
            }

            update_post_meta($new_id, '_wp_attached_file', $rel_path);

            $attach_meta = wp_generate_attachment_metadata($new_id, $target_path);
            wp_update_attachment_metadata($new_id, $attach_meta);

            if (!empty($image_info['alt_text'])) {
                update_post_meta($new_id, '_wp_attachment_image_alt', sanitize_text_field($image_info['alt_text']));
            }

            // Heal all broken ID references on the frontend.
            if ($old_id !== $new_id) {
                $this->update_id_references($old_id, $new_id);
            }

            $restored[] = array(
                'original_id' => $old_id,
                'new_id'      => $new_id,
                'filename'    => $target_name,
                'url'         => $guid,
            );
        }

        $zip->close();
        @unlink($zip_path);

        return array(
            'restored'       => $restored,
            'failed'         => $failed,
            'restored_count' => count($restored),
            'failed_count'   => count($failed),
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
