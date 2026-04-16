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
                'id' => $attachment_id,
                'filename' => $filename,
                'original_filename' => basename($file_path),
                'size' => $file_size,
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
     * Restore images from a ZIP archive
     *
     * @param string $zip_path Path to the ZIP file
     * @return array|WP_Error Restore results or error
     */
    public function restore_from_archive($zip_path) {
        if (!file_exists($zip_path)) {
            return new WP_Error('file_not_found', __('Archive file not found', 'hozio-image-optimizer'));
        }

        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_zip', __('ZipArchive extension is not available', 'hozio-image-optimizer'));
        }

        $zip = new ZipArchive();
        $result = $zip->open($zip_path);

        if ($result !== true) {
            return new WP_Error('zip_open_failed', __('Failed to open ZIP archive', 'hozio-image-optimizer'));
        }

        // Read manifest
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
        $restored = array();
        $failed = array();

        foreach ($manifest['images'] as $image_info) {
            $attachment_id = $image_info['id'];

            // Read metadata for this image
            $metadata_content = $zip->getFromName('images/' . $attachment_id . '/metadata.json');
            if (!$metadata_content) {
                $failed[] = array('id' => $attachment_id, 'reason' => 'Missing metadata');
                continue;
            }

            $metadata = json_decode($metadata_content, true);
            if (!$metadata) {
                $failed[] = array('id' => $attachment_id, 'reason' => 'Invalid metadata');
                continue;
            }

            // Restore files
            $restore_result = $this->restore_image_files($zip, $attachment_id, $metadata, $upload_dir);
            if (is_wp_error($restore_result)) {
                $failed[] = array('id' => $attachment_id, 'reason' => $restore_result->get_error_message());
                continue;
            }

            // Create attachment post
            $new_attachment_id = $this->create_attachment_post($metadata, $restore_result['main_file']);
            if (is_wp_error($new_attachment_id)) {
                $failed[] = array('id' => $attachment_id, 'reason' => $new_attachment_id->get_error_message());
                // Clean up restored files
                foreach ($restore_result['restored_files'] as $file) {
                    if (file_exists($file)) {
                        unlink($file);
                    }
                }
                continue;
            }

            // Restore metadata
            $this->restore_attachment_metadata($new_attachment_id, $metadata);

            $restored[] = array(
                'original_id' => $attachment_id,
                'new_id' => $new_attachment_id,
                'filename' => $metadata['filename'],
            );
        }

        $zip->close();

        // Clean up the uploaded ZIP file
        unlink($zip_path);

        return array(
            'restored' => $restored,
            'failed' => $failed,
            'restored_count' => count($restored),
            'failed_count' => count($failed),
        );
    }

    /**
     * Restore image files from ZIP to upload directory
     *
     * @param ZipArchive $zip The ZIP archive
     * @param int $attachment_id Original attachment ID
     * @param array $metadata Image metadata
     * @param array $upload_dir WordPress upload directory info
     * @return array|WP_Error Restored file paths or error
     */
    private function restore_image_files($zip, $attachment_id, $metadata, $upload_dir) {
        $original_path = $metadata['original_upload_path'];
        $target_dir = $upload_dir['basedir'] . '/' . $original_path;

        // Create directory if needed
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        $restored_files = array();
        $main_file = '';

        foreach ($metadata['all_files'] as $file_info) {
            $zip_path = 'images/' . $attachment_id . '/files/' . $file_info['name'];
            $target_path = $target_dir . '/' . $file_info['name'];

            // Check if file already exists
            if (file_exists($target_path)) {
                // Generate unique filename
                $pathinfo = pathinfo($file_info['name']);
                $counter = 1;
                do {
                    $new_name = $pathinfo['filename'] . '-restored-' . $counter . '.' . $pathinfo['extension'];
                    $target_path = $target_dir . '/' . $new_name;
                    $counter++;
                } while (file_exists($target_path));
            }

            // Extract file
            $file_content = $zip->getFromName($zip_path);
            if ($file_content === false) {
                continue; // Skip missing files but don't fail
            }

            $written = file_put_contents($target_path, $file_content);
            if ($written === false) {
                return new WP_Error('write_failed', sprintf(__('Failed to write file: %s', 'hozio-image-optimizer'), $file_info['name']));
            }

            // Security: verify the extracted file is actually an image
            $image_check = @getimagesize($target_path);
            if (!$image_check) {
                @unlink($target_path); // Delete non-image file
                continue; // Skip this file
            }

            $restored_files[] = $target_path;

            if ($file_info['type'] === 'original') {
                $main_file = $target_path;
            }
        }

        if (empty($main_file)) {
            return new WP_Error('no_main_file', __('Original file not found in archive', 'hozio-image-optimizer'));
        }

        return array(
            'main_file' => $main_file,
            'restored_files' => $restored_files,
        );
    }

    /**
     * Create WordPress attachment post for restored image
     *
     * @param array $metadata Original metadata
     * @param string $file_path Path to the main image file
     * @return int|WP_Error New attachment ID or error
     */
    private function create_attachment_post($metadata, $file_path) {
        $upload_dir = wp_upload_dir();

        // Calculate relative path
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);

        $attachment_data = array(
            'post_title' => $metadata['post_title'],
            'post_content' => $metadata['post_content'],
            'post_excerpt' => $metadata['post_excerpt'],
            'post_name' => $metadata['post_name'],
            'post_mime_type' => $metadata['post_mime_type'],
            'post_status' => 'inherit',
            'post_parent' => 0, // Don't restore parent relationship
            'guid' => $upload_dir['baseurl'] . '/' . $relative_path,
        );

        $attachment_id = wp_insert_attachment($attachment_data, $file_path);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Generate attachment metadata (creates thumbnails if they don't exist)
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        return $attachment_id;
    }

    /**
     * Restore additional metadata for an attachment
     *
     * @param int $attachment_id The new attachment ID
     * @param array $metadata Original metadata
     */
    private function restore_attachment_metadata($attachment_id, $metadata) {
        // Restore alt text
        if (!empty($metadata['_wp_attachment_image_alt'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $metadata['_wp_attachment_image_alt']);
        }

        // Restore any custom Hozio meta (but not the protected flag)
        $hozio_meta_keys = array(
            '_hozio_optimized',
            '_hozio_original_size',
            '_hozio_optimized_size',
            '_hozio_savings_bytes',
        );

        if (!empty($metadata['all_meta'])) {
            foreach ($hozio_meta_keys as $key) {
                if (isset($metadata['all_meta'][$key])) {
                    $value = is_array($metadata['all_meta'][$key]) ? $metadata['all_meta'][$key][0] : $metadata['all_meta'][$key];
                    update_post_meta($attachment_id, $key, $value);
                }
            }
        }
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
