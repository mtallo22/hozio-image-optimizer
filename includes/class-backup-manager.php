<?php
/**
 * Backup Manager - Physical file backup and restore system
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Backup_Manager {

    /**
     * Create a physical backup of an image
     *
     * @param int $attachment_id WordPress attachment ID
     * @param string $file_path Path to the file to backup
     * @return array Result with success status
     */
    public function create_backup($attachment_id, $file_path) {
        if (!file_exists($file_path)) {
            return array(
                'success' => false,
                'error' => __('Source file does not exist', 'hozio-image-optimizer'),
            );
        }

        // Create backup directory structure: /backups/YYYY/MM/attachment_id/
        $date = current_time('Y/m');
        $backup_dir = HOZIO_IMAGE_OPTIMIZER_BACKUP_DIR . $date . '/' . $attachment_id . '/';

        if (!file_exists($backup_dir)) {
            if (!wp_mkdir_p($backup_dir)) {
                return array(
                    'success' => false,
                    'error' => __('Failed to create backup directory', 'hozio-image-optimizer'),
                );
            }
        }

        // Generate backup filename with timestamp
        $filename = basename($file_path);
        $timestamp = current_time('Y-m-d_H-i-s');
        $backup_filename = $timestamp . '_' . $filename;
        $backup_path = $backup_dir . $backup_filename;

        // Copy the file (not move, we want to keep the original for now)
        if (!copy($file_path, $backup_path)) {
            return array(
                'success' => false,
                'error' => __('Failed to copy file to backup location', 'hozio-image-optimizer'),
            );
        }

        // Store backup metadata
        $backup_meta = array(
            'attachment_id' => $attachment_id,
            'original_path' => $file_path,
            'original_filename' => $filename,
            'backup_path' => $backup_path,
            'backup_filename' => $backup_filename,
            'file_size' => filesize($file_path),
            'mime_type' => mime_content_type($file_path),
            'backup_date' => current_time('mysql'),
            'post_title' => get_the_title($attachment_id),
            'post_alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
        );

        // Get image dimensions
        $image_info = @getimagesize($file_path);
        if ($image_info) {
            $backup_meta['width'] = $image_info[0];
            $backup_meta['height'] = $image_info[1];
        }

        // Save metadata as JSON
        $meta_path = $backup_dir . $timestamp . '_metadata.json';
        file_put_contents($meta_path, wp_json_encode($backup_meta, JSON_PRETTY_PRINT));

        // Store in database for quick lookup
        $this->save_backup_record($attachment_id, $backup_meta);

        Hozio_Image_Optimizer_Helpers::log("Backup created for attachment {$attachment_id}: {$backup_path}");

        return array(
            'success' => true,
            'backup_path' => $backup_path,
            'meta_path' => $meta_path,
            'backup_data' => $backup_meta,
        );
    }

    /**
     * Restore an image from backup
     *
     * @param int $attachment_id WordPress attachment ID
     * @param string $backup_date Optional specific backup date to restore
     * @return array Result with success status
     */
    public function restore_backup($attachment_id, $backup_date = null) {
        // Get backup info
        $backup = $this->get_backup($attachment_id, $backup_date);

        if (!$backup) {
            return array(
                'success' => false,
                'error' => __('No backup found for this image', 'hozio-image-optimizer'),
            );
        }

        $backup_path = $backup['backup_path'];
        $original_path = $backup['original_path'];
        $original_mime = isset($backup['mime_type']) ? $backup['mime_type'] : '';

        // Check backup file exists
        if (!file_exists($backup_path)) {
            return array(
                'success' => false,
                'error' => __('Backup file not found', 'hozio-image-optimizer'),
            );
        }

        // Get current file path (may have changed due to format conversion)
        $current_path = get_attached_file($attachment_id);

        // Delete current file if it exists and is different from original
        if ($current_path && file_exists($current_path) && $current_path !== $original_path) {
            @unlink($current_path);

            // Also delete any thumbnails for the current (converted) file
            $current_dir = dirname($current_path);
            $current_name = pathinfo($current_path, PATHINFO_FILENAME);
            $current_ext = pathinfo($current_path, PATHINFO_EXTENSION);

            // Find and delete converted thumbnails (e.g., image-150x150.webp)
            $thumbnail_pattern = $current_dir . '/' . $current_name . '-*.' . $current_ext;
            foreach (glob($thumbnail_pattern) as $thumbnail) {
                @unlink($thumbnail);
            }
        }

        // Ensure the original directory exists
        $original_dir = dirname($original_path);
        if (!file_exists($original_dir)) {
            wp_mkdir_p($original_dir);
        }

        // Copy backup to original location
        if (!copy($backup_path, $original_path)) {
            return array(
                'success' => false,
                'error' => __('Failed to restore file from backup', 'hozio-image-optimizer'),
            );
        }

        // Update WordPress database with original path
        update_attached_file($attachment_id, $original_path);

        // Update MIME type back to original
        if (!empty($original_mime)) {
            wp_update_post(array(
                'ID' => $attachment_id,
                'post_mime_type' => $original_mime,
            ));
        }

        // Regenerate metadata (this will create new thumbnails in original format)
        $metadata = wp_generate_attachment_metadata($attachment_id, $original_path);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Restore post title if saved
        if (!empty($backup['post_title'])) {
            wp_update_post(array(
                'ID' => $attachment_id,
                'post_title' => $backup['post_title'],
            ));
        }

        // Restore alt text if saved
        if (!empty($backup['post_alt'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $backup['post_alt']);
        }

        // Delete the backup file and metadata after successful restore
        @unlink($backup_path);

        // Delete the metadata JSON file if it exists
        $meta_path = preg_replace('/\.[^.]+$/', '_metadata.json', $backup_path);
        $meta_path = str_replace('__metadata', '_metadata', $meta_path); // Fix double underscore
        if (file_exists($meta_path)) {
            @unlink($meta_path);
        }

        // Also try the timestamp-based metadata path
        $backup_dir = dirname($backup_path);
        $backup_filename = basename($backup_path);
        if (preg_match('/^(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})_/', $backup_filename, $matches)) {
            $timestamp = $matches[1];
            $meta_file = $backup_dir . '/' . $timestamp . '_metadata.json';
            if (file_exists($meta_file)) {
                @unlink($meta_file);
            }
        }

        // Remove backup directory if empty
        $backup_dir = dirname($backup_path);
        if (is_dir($backup_dir) && count(glob($backup_dir . '/*')) === 0) {
            @rmdir($backup_dir);
        }

        // Remove backup record from database
        $this->remove_backup_record($attachment_id);

        // Clear optimization flags so the image shows as "Not Optimized"
        delete_post_meta($attachment_id, '_hozio_optimized');
        delete_post_meta($attachment_id, '_hozio_original_size');
        delete_post_meta($attachment_id, '_hozio_optimized_size');
        delete_post_meta($attachment_id, '_hozio_savings_bytes');
        // Mark as restored so the card can show a "Restored" indicator
        update_post_meta($attachment_id, '_hozio_restored', current_time('mysql'));

        Hozio_Image_Optimizer_Helpers::log("Restored attachment {$attachment_id} from backup and cleaned up backup files");

        return array(
            'success' => true,
            'restored_path' => $original_path,
            'backup_used' => $backup_path,
        );
    }

    /**
     * Save backup record to database
     */
    private function save_backup_record($attachment_id, $backup_data) {
        $backups = get_option('hozio_image_backups', array());

        // Store by attachment ID, keeping history
        if (!isset($backups[$attachment_id])) {
            $backups[$attachment_id] = array();
        }

        // Add to beginning of array (newest first)
        array_unshift($backups[$attachment_id], $backup_data);

        // Keep only last 5 backups per image
        $backups[$attachment_id] = array_slice($backups[$attachment_id], 0, 5);

        update_option('hozio_image_backups', $backups, false);
    }

    /**
     * Remove backup record from database
     */
    private function remove_backup_record($attachment_id) {
        $backups = get_option('hozio_image_backups', array());

        if (isset($backups[$attachment_id])) {
            unset($backups[$attachment_id]);
            update_option('hozio_image_backups', $backups, false);
        }
    }

    /**
     * Get backup info for an attachment
     */
    public function get_backup($attachment_id, $backup_date = null) {
        $backups = get_option('hozio_image_backups', array());

        if (!isset($backups[$attachment_id]) || empty($backups[$attachment_id])) {
            return null;
        }

        // If specific date requested, find that backup
        if ($backup_date) {
            foreach ($backups[$attachment_id] as $backup) {
                if ($backup['backup_date'] === $backup_date) {
                    return $backup;
                }
            }
            return null;
        }

        // Return most recent backup
        return $backups[$attachment_id][0];
    }

    /**
     * Get all backups for an attachment
     */
    public function get_all_backups($attachment_id) {
        $backups = get_option('hozio_image_backups', array());
        return isset($backups[$attachment_id]) ? $backups[$attachment_id] : array();
    }

    /**
     * Check if backup exists for attachment
     */
    public function has_backup($attachment_id) {
        $backup = $this->get_backup($attachment_id);
        return $backup !== null && file_exists($backup['backup_path']);
    }

    /**
     * Get backup info including original size for comparison
     *
     * @param int $attachment_id
     * @return array|null Backup info with original_size key
     */
    public function get_backup_info($attachment_id) {
        $backup = $this->get_backup($attachment_id);

        if (!$backup) {
            return null;
        }

        return array(
            'original_size' => isset($backup['file_size']) ? $backup['file_size'] : 0,
            'original_filename' => isset($backup['original_filename']) ? $backup['original_filename'] : '',
            'backup_date' => isset($backup['backup_date']) ? $backup['backup_date'] : '',
            'original_path' => isset($backup['original_path']) ? $backup['original_path'] : '',
            'width' => isset($backup['width']) ? $backup['width'] : 0,
            'height' => isset($backup['height']) ? $backup['height'] : 0,
        );
    }

    /**
     * Delete backup for an attachment
     */
    public function delete_backup($attachment_id, $backup_date = null) {
        $backups = get_option('hozio_image_backups', array());

        if (!isset($backups[$attachment_id])) {
            return false;
        }

        if ($backup_date) {
            // Delete specific backup
            foreach ($backups[$attachment_id] as $key => $backup) {
                if ($backup['backup_date'] === $backup_date) {
                    // Delete the file
                    if (file_exists($backup['backup_path'])) {
                        unlink($backup['backup_path']);
                    }

                    // Delete metadata file
                    $meta_path = dirname($backup['backup_path']) . '/' .
                        pathinfo($backup['backup_filename'], PATHINFO_FILENAME) . '_metadata.json';
                    if (file_exists($meta_path)) {
                        unlink($meta_path);
                    }

                    unset($backups[$attachment_id][$key]);
                    break;
                }
            }

            // Re-index array
            $backups[$attachment_id] = array_values($backups[$attachment_id]);
        } else {
            // Delete all backups for this attachment
            foreach ($backups[$attachment_id] as $backup) {
                if (file_exists($backup['backup_path'])) {
                    unlink($backup['backup_path']);
                }
            }

            // Try to remove empty directory
            $backup_dir = dirname($backups[$attachment_id][0]['backup_path']);
            @rmdir($backup_dir);

            unset($backups[$attachment_id]);
        }

        update_option('hozio_image_backups', $backups, false);
        return true;
    }

    /**
     * Clean up old backups based on retention setting
     */
    public function cleanup_old_backups() {
        $retention_days = get_option('hozio_backup_retention_days', 30);
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        $backups = get_option('hozio_image_backups', array());
        $cleaned = 0;

        foreach ($backups as $attachment_id => $attachment_backups) {
            foreach ($attachment_backups as $key => $backup) {
                if ($backup['backup_date'] < $cutoff_date) {
                    // Delete the file
                    if (file_exists($backup['backup_path'])) {
                        unlink($backup['backup_path']);
                        $cleaned++;
                    }

                    unset($backups[$attachment_id][$key]);
                }
            }

            // Re-index and clean empty
            $backups[$attachment_id] = array_values($backups[$attachment_id]);
            if (empty($backups[$attachment_id])) {
                unset($backups[$attachment_id]);
            }
        }

        update_option('hozio_image_backups', $backups, false);

        // Clean up empty directories
        $this->cleanup_empty_directories(HOZIO_IMAGE_OPTIMIZER_BACKUP_DIR);

        Hozio_Image_Optimizer_Helpers::log("Cleaned up {$cleaned} old backups");

        return $cleaned;
    }

    /**
     * Remove empty backup directories
     */
    private function cleanup_empty_directories($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanup_empty_directories($path);

                // Try to remove if empty
                if (count(scandir($path)) === 2) { // Only . and ..
                    @rmdir($path);
                }
            }
        }
    }

    /**
     * Get total backup storage size
     */
    public function get_total_backup_size() {
        $total_size = 0;
        $backups = get_option('hozio_image_backups', array());

        foreach ($backups as $attachment_backups) {
            foreach ($attachment_backups as $backup) {
                if (file_exists($backup['backup_path'])) {
                    $total_size += filesize($backup['backup_path']);
                }
            }
        }

        return $total_size;
    }

    /**
     * Get backup statistics
     */
    public function get_backup_stats() {
        $backups = get_option('hozio_image_backups', array());

        $stats = array(
            'total_images' => count($backups),
            'total_backups' => 0,
            'total_size' => 0,
            'oldest_backup' => null,
            'newest_backup' => null,
        );

        foreach ($backups as $attachment_backups) {
            $stats['total_backups'] += count($attachment_backups);

            foreach ($attachment_backups as $backup) {
                if (file_exists($backup['backup_path'])) {
                    $stats['total_size'] += filesize($backup['backup_path']);
                }

                // Track oldest/newest
                if ($stats['oldest_backup'] === null || $backup['backup_date'] < $stats['oldest_backup']) {
                    $stats['oldest_backup'] = $backup['backup_date'];
                }
                if ($stats['newest_backup'] === null || $backup['backup_date'] > $stats['newest_backup']) {
                    $stats['newest_backup'] = $backup['backup_date'];
                }
            }
        }

        $stats['total_size_formatted'] = Hozio_Image_Optimizer_Helpers::format_bytes($stats['total_size']);

        return $stats;
    }

    /**
     * Get list of all images with backups
     */
    public function get_backed_up_images($page = 1, $per_page = 20) {
        $backups = get_option('hozio_image_backups', array());

        $total = count($backups);
        $offset = ($page - 1) * $per_page;

        $attachment_ids = array_keys($backups);
        $attachment_ids = array_slice($attachment_ids, $offset, $per_page);

        $images = array();
        foreach ($attachment_ids as $attachment_id) {
            $backup = $backups[$attachment_id][0]; // Most recent backup
            $current_path = get_attached_file($attachment_id);

            // Try multiple methods to get a working thumbnail
            $thumbnail = wp_get_attachment_image_url($attachment_id, 'medium');
            if (!$thumbnail) {
                $thumbnail = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            }
            if (!$thumbnail) {
                $thumbnail = wp_get_attachment_url($attachment_id);
            }
            // If still no thumbnail, try to build URL from actual file on disk
            if (!$thumbnail && $current_path && file_exists($current_path)) {
                $upload_dir = wp_upload_dir();
                $thumbnail = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $current_path);
            }

            // Get file size for display
            $file_size = ($current_path && file_exists($current_path)) ? filesize($current_path) : 0;
            $mime_type = get_post_mime_type($attachment_id);

            $images[] = array(
                'attachment_id' => $attachment_id,
                'title' => get_the_title($attachment_id),
                'current_filename' => $current_path ? basename($current_path) : 'Unknown',
                'original_filename' => $backup['original_filename'],
                'backup_count' => count($backups[$attachment_id]),
                'last_backup_date' => $backup['backup_date'],
                'thumbnail' => $thumbnail,
                'has_changes' => $current_path ? (basename($current_path) !== $backup['original_filename']) : false,
                'file_size' => $file_size,
                'file_size_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($file_size),
                'mime_type' => $mime_type ? strtoupper(str_replace('image/', '', $mime_type)) : '',
            );
        }

        return array(
            'images' => $images,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page,
        );
    }

    /**
     * Create a ZIP archive of all backups for download
     *
     * @return array Result with download URL or error
     */
    public function create_backup_archive() {
        $backups = get_option('hozio_image_backups', array());

        if (empty($backups)) {
            return array(
                'success' => false,
                'error' => __('No backups to download', 'hozio-image-optimizer'),
            );
        }

        // Check if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            return array(
                'success' => false,
                'error' => __('ZIP extension not available on this server', 'hozio-image-optimizer'),
            );
        }

        // Create temp directory for the zip file (protected from public access)
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/hozio-temp/';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
            // Protect directory from public access
            file_put_contents($temp_dir . '.htaccess', "Order deny,allow\nDeny from all");
            file_put_contents($temp_dir . 'index.php', '<?php // Silence is golden');
        }

        // Generate unique filename
        $timestamp = current_time('Y-m-d_H-i-s');
        $zip_filename = 'hozio-backups-' . $timestamp . '.zip';
        $zip_path = $temp_dir . $zip_filename;

        // Create ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return array(
                'success' => false,
                'error' => __('Failed to create ZIP archive', 'hozio-image-optimizer'),
            );
        }

        $file_count = 0;
        $used_filenames = array();

        // Add only the most recent backup file per image, flat at root level
        foreach ($backups as $attachment_id => $attachment_backups) {
            $backup = $attachment_backups[0]; // Most recent backup
            $backup_path = $backup['backup_path'];

            if (!file_exists($backup_path)) {
                continue;
            }

            // Use original filename, handle duplicates by appending ID
            $filename = $backup['original_filename'];
            if (isset($used_filenames[$filename])) {
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $filename = $name . '-' . $attachment_id . '.' . $ext;
            }
            $used_filenames[$filename] = true;

            if ($zip->addFile($backup_path, $filename)) {
                $file_count++;
            }
        }

        $zip->close();

        if ($file_count === 0) {
            @unlink($zip_path);
            return array(
                'success' => false,
                'error' => __('No valid backup files found', 'hozio-image-optimizer'),
            );
        }

        // Generate download URL
        $download_url = $upload_dir['baseurl'] . '/hozio-temp/' . $zip_filename;

        // Schedule cleanup of the temp file after 1 hour
        wp_schedule_single_event(time() + 3600, 'hozio_cleanup_temp_zip', array($zip_path));

        Hozio_Image_Optimizer_Helpers::log("Created backup archive with {$file_count} files: {$zip_path}");

        return array(
            'success' => true,
            'download_url' => $download_url,
            'filename' => $zip_filename,
            'file_count' => $file_count,
            'file_path' => $zip_path,
        );
    }
}
