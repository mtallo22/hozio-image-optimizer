<?php
/**
 * Image Safety System - Triple-check validation to ensure images never break
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Safety {

    /**
     * Validate image before any operation
     *
     * @param string $file_path Path to the image file
     * @return array Validation result with 'valid' bool and 'errors' array
     */
    public static function validate_before_operation($file_path) {
        $errors = array();

        // Check 1: File exists
        if (!file_exists($file_path)) {
            $errors[] = __('File does not exist', 'hozio-image-optimizer');
            return array('valid' => false, 'errors' => $errors);
        }

        // Check 2: File is readable
        if (!is_readable($file_path)) {
            $errors[] = __('File is not readable', 'hozio-image-optimizer');
            return array('valid' => false, 'errors' => $errors);
        }

        // Check 3: Directory is writable (for operations that modify files)
        $directory = dirname($file_path);
        if (!is_writable($directory)) {
            $errors[] = __('Directory is not writable', 'hozio-image-optimizer');
            return array('valid' => false, 'errors' => $errors);
        }

        // Check 4: Valid image file
        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            $errors[] = __('File is not a valid image', 'hozio-image-optimizer');
            return array('valid' => false, 'errors' => $errors);
        }

        // Check 5: Supported mime type
        $supported_types = array(
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/avif',
        );

        if (!in_array($image_info['mime'], $supported_types)) {
            $errors[] = sprintf(__('Unsupported image type: %s', 'hozio-image-optimizer'), $image_info['mime']);
            return array('valid' => false, 'errors' => $errors);
        }

        // Check 6: File is not corrupted (try to load it)
        $corruption_check = self::check_image_integrity($file_path, $image_info['mime']);
        if (!$corruption_check['valid']) {
            $errors[] = $corruption_check['error'];
            return array('valid' => false, 'errors' => $errors);
        }

        // Check 7: Sufficient disk space (need at least 3x file size for temp operations)
        $file_size = filesize($file_path);
        $required_space = $file_size * 3;
        $free_space = @disk_free_space($directory);

        if ($free_space !== false && $free_space < $required_space) {
            $errors[] = __('Insufficient disk space', 'hozio-image-optimizer');
            return array('valid' => false, 'errors' => $errors);
        }

        // Check 8: Memory availability
        $estimated_memory = Hozio_Image_Optimizer_Helpers::estimate_image_memory(
            $image_info[0],
            $image_info[1]
        );

        if (!Hozio_Image_Optimizer_Helpers::has_enough_memory($estimated_memory)) {
            $errors[] = __('Insufficient memory for processing this image', 'hozio-image-optimizer');
            return array('valid' => false, 'errors' => $errors);
        }

        // All checks passed
        return array(
            'valid' => true,
            'errors' => array(),
            'image_info' => array(
                'width' => $image_info[0],
                'height' => $image_info[1],
                'mime_type' => $image_info['mime'],
                'file_size' => $file_size,
            ),
        );
    }

    /**
     * Check image integrity by attempting to load it
     */
    private static function check_image_integrity($file_path, $mime_type) {
        try {
            switch ($mime_type) {
                case 'image/jpeg':
                    $image = @imagecreatefromjpeg($file_path);
                    break;
                case 'image/png':
                    $image = @imagecreatefrompng($file_path);
                    break;
                case 'image/gif':
                    $image = @imagecreatefromgif($file_path);
                    break;
                case 'image/webp':
                    if (function_exists('imagecreatefromwebp')) {
                        $image = @imagecreatefromwebp($file_path);
                    } else {
                        return array('valid' => false, 'error' => __('WebP not supported on this server', 'hozio-image-optimizer'));
                    }
                    break;
                case 'image/avif':
                    if (function_exists('imagecreatefromavif')) {
                        $image = @imagecreatefromavif($file_path);
                    } else {
                        return array('valid' => false, 'error' => __('AVIF not supported on this server', 'hozio-image-optimizer'));
                    }
                    break;
                default:
                    return array('valid' => false, 'error' => __('Unsupported image type', 'hozio-image-optimizer'));
            }

            if (!$image) {
                return array('valid' => false, 'error' => __('Image file appears to be corrupted', 'hozio-image-optimizer'));
            }

            // Clean up
            imagedestroy($image);

            return array('valid' => true);
        } catch (Exception $e) {
            return array('valid' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Validate image after operation
     *
     * @param string $file_path Path to the processed image
     * @param array $expected Expected properties (optional)
     * @return array Validation result
     */
    public static function validate_after_operation($file_path, $expected = array()) {
        $errors = array();

        // Clear file stat cache to ensure we see current file state
        clearstatcache(true, $file_path);

        // Check 1: File was created
        if (!file_exists($file_path)) {
            // Log detailed info for debugging
            error_log("Hozio Safety: Post-validation failed - file not found at: {$file_path}");
            $errors[] = __('Output file was not created', 'hozio-image-optimizer');
            return array('valid' => false, 'errors' => $errors);
        }

        // Check 2: File has content
        $file_size = filesize($file_path);
        if ($file_size === 0) {
            $errors[] = __('Output file is empty', 'hozio-image-optimizer');
            return array('valid' => false, 'errors' => $errors);
        }

        // Check 3: File is valid image
        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            $errors[] = __('Output file is not a valid image', 'hozio-image-optimizer');
            return array('valid' => false, 'errors' => $errors);
        }

        // Check 4: Can load the image (not corrupted)
        $corruption_check = self::check_image_integrity($file_path, $image_info['mime']);
        if (!$corruption_check['valid']) {
            $errors[] = $corruption_check['error'];
            return array('valid' => false, 'errors' => $errors);
        }

        // Check 5: Dimensions are reasonable (if expected provided)
        if (!empty($expected['min_width']) && $image_info[0] < $expected['min_width']) {
            $errors[] = sprintf(__('Image width too small: %dpx', 'hozio-image-optimizer'), $image_info[0]);
        }

        if (!empty($expected['min_height']) && $image_info[1] < $expected['min_height']) {
            $errors[] = sprintf(__('Image height too small: %dpx', 'hozio-image-optimizer'), $image_info[1]);
        }

        // Check 6: File size is reasonable
        if ($file_size < 100) { // Less than 100 bytes is suspiciously small
            $errors[] = __('Output file is suspiciously small', 'hozio-image-optimizer');
        }

        if (!empty($errors)) {
            return array('valid' => false, 'errors' => $errors);
        }

        return array(
            'valid' => true,
            'errors' => array(),
            'image_info' => array(
                'width' => $image_info[0],
                'height' => $image_info[1],
                'mime_type' => $image_info['mime'],
                'file_size' => $file_size,
            ),
        );
    }

    /**
     * Compare original and processed images to ensure quality
     */
    public static function compare_images($original_path, $processed_path) {
        $original_info = @getimagesize($original_path);
        $processed_info = @getimagesize($processed_path);

        if (!$original_info || !$processed_info) {
            return array(
                'valid' => false,
                'error' => __('Cannot compare images - invalid files', 'hozio-image-optimizer'),
            );
        }

        $original_size = filesize($original_path);
        $processed_size = filesize($processed_path);

        $result = array(
            'valid' => true,
            'original' => array(
                'width' => $original_info[0],
                'height' => $original_info[1],
                'file_size' => $original_size,
                'mime_type' => $original_info['mime'],
            ),
            'processed' => array(
                'width' => $processed_info[0],
                'height' => $processed_info[1],
                'file_size' => $processed_size,
                'mime_type' => $processed_info['mime'],
            ),
            'size_reduction' => $original_size - $processed_size,
            'size_reduction_percent' => $original_size > 0 ?
                round((($original_size - $processed_size) / $original_size) * 100, 1) : 0,
            'dimensions_changed' => ($original_info[0] !== $processed_info[0]) ||
                ($original_info[1] !== $processed_info[1]),
            'format_changed' => $original_info['mime'] !== $processed_info['mime'],
        );

        // Warning if file got bigger
        if ($processed_size > $original_size) {
            $result['warning'] = __('Processed file is larger than original', 'hozio-image-optimizer');
        }

        return $result;
    }

    /**
     * Perform a safe file operation with automatic rollback on failure
     *
     * @param callable $operation The operation to perform
     * @param string $original_path Path to original file
     * @param int $attachment_id WordPress attachment ID
     * @return array Result of operation
     */
    public static function safe_operation($operation, $original_path, $attachment_id) {
        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();

        // Step 1: Validate before operation
        $pre_validation = self::validate_before_operation($original_path);
        if (!$pre_validation['valid']) {
            return array(
                'success' => false,
                'error' => implode(', ', $pre_validation['errors']),
                'stage' => 'pre_validation',
            );
        }

        // Step 2: Create backup
        $backup_created = false;
        if (get_option('hozio_backup_enabled', false)) {
            $backup_result = $backup_manager->create_backup($attachment_id, $original_path);
            if ($backup_result['success']) {
                $backup_created = true;
            } else {
                Hozio_Image_Optimizer_Helpers::log('Backup creation failed: ' . $backup_result['error'], 'warning');
            }
        }

        // Step 3: Perform the operation
        try {
            $result = call_user_func($operation);

            if (!$result['success']) {
                // Operation failed - restore from backup if possible
                if ($backup_created) {
                    $backup_manager->restore_backup($attachment_id);
                }

                return array(
                    'success' => false,
                    'error' => $result['error'] ?? __('Operation failed', 'hozio-image-optimizer'),
                    'stage' => 'operation',
                    'restored_from_backup' => $backup_created,
                );
            }

            // Step 4: Validate after operation
            $new_path = $result['new_path'] ?? $original_path;
            if (get_option('hozio_validate_after_operation', true)) {
                // Debug logging for troubleshooting
                error_log("Hozio Safety: Post-validation checking path: {$new_path}");

                // Small delay to ensure filesystem operations complete (especially on Windows)
                usleep(50000); // 50ms

                $post_validation = self::validate_after_operation($new_path);

                if (!$post_validation['valid']) {
                    // Post-validation failed - restore from backup
                    if ($backup_created) {
                        $backup_manager->restore_backup($attachment_id);
                    }

                    return array(
                        'success' => false,
                        'error' => implode(', ', $post_validation['errors']),
                        'stage' => 'post_validation',
                        'restored_from_backup' => $backup_created,
                    );
                }
            }

            // Success!
            return array_merge($result, array(
                'backup_created' => $backup_created,
            ));

        } catch (Exception $e) {
            // Exception occurred - restore from backup
            if ($backup_created) {
                $backup_manager->restore_backup($attachment_id);
            }

            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'stage' => 'exception',
                'restored_from_backup' => $backup_created,
            );
        }
    }

    /**
     * Check if a file operation would be safe
     */
    public static function would_be_safe($file_path, $operation_type = 'compress') {
        $validation = self::validate_before_operation($file_path);

        if (!$validation['valid']) {
            return array(
                'safe' => false,
                'reasons' => $validation['errors'],
            );
        }

        $warnings = array();

        // Additional checks based on operation type
        $image_info = $validation['image_info'];

        switch ($operation_type) {
            case 'compress':
                // Already optimized? Check if file is very small
                if ($image_info['file_size'] < 10000) { // Less than 10KB
                    $warnings[] = __('Image is already very small', 'hozio-image-optimizer');
                }
                break;

            case 'convert':
                // Check if format conversion is supported
                if (!function_exists('imagewebp')) {
                    $warnings[] = __('WebP conversion not supported', 'hozio-image-optimizer');
                }
                break;

            case 'resize':
                // Check dimensions
                if ($image_info['width'] < 100 || $image_info['height'] < 100) {
                    $warnings[] = __('Image is already very small', 'hozio-image-optimizer');
                }
                break;
        }

        return array(
            'safe' => true,
            'warnings' => $warnings,
            'image_info' => $image_info,
        );
    }
}
