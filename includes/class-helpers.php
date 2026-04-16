<?php
/**
 * Helper utilities for Hozio Image Optimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Helpers {

    /**
     * Log a message to the debug log
     */
    public static function log($message, $level = 'info') {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $prefix = strtoupper($level);
        $log_message = "[{$timestamp}] Hozio Image Optimizer {$prefix}: {$message}";

        error_log($log_message);
    }

    /**
     * Format bytes to human readable size
     */
    public static function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Parse bytes from human readable string
     */
    public static function parse_bytes($size_str) {
        $size_str = trim($size_str);
        $last = strtolower($size_str[strlen($size_str) - 1]);
        $value = intval($size_str);

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Get available memory in bytes
     */
    public static function get_available_memory() {
        $memory_limit = self::parse_bytes(ini_get('memory_limit'));
        $memory_usage = memory_get_usage(true);

        return $memory_limit - $memory_usage;
    }

    /**
     * Check if there's enough memory for an operation
     */
    public static function has_enough_memory($required_bytes) {
        $available = self::get_available_memory();
        // Keep 20% buffer
        return $available > ($required_bytes * 1.2);
    }

    /**
     * Estimate memory needed for image processing
     */
    public static function estimate_image_memory($width, $height, $channels = 4) {
        // Each pixel needs ~4 bytes (RGBA), plus overhead for processing
        // We multiply by 2.5 to account for source + destination + overhead
        return $width * $height * $channels * 2.5;
    }

    /**
     * Sanitize filename for SEO
     */
    public static function sanitize_seo_filename($filename) {
        // Convert to lowercase
        $filename = strtolower($filename);

        // Replace spaces and underscores with hyphens
        $filename = preg_replace('/[\s_]+/', '-', $filename);

        // Remove special characters (keep alphanumeric and hyphens)
        $filename = preg_replace('/[^a-z0-9\-]/', '', $filename);

        // Remove consecutive hyphens
        $filename = preg_replace('/-+/', '-', $filename);

        // Trim hyphens from start and end
        $filename = trim($filename, '-');

        return $filename;
    }

    /**
     * Check if file is a valid image
     */
    public static function is_valid_image($file_path) {
        if (!file_exists($file_path)) {
            return false;
        }

        $valid_mime_types = array(
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/avif',
        );

        $mime_type = mime_content_type($file_path);
        return in_array($mime_type, $valid_mime_types);
    }

    /**
     * Get image info (dimensions, size, type)
     */
    public static function get_image_info($file_path) {
        if (!file_exists($file_path)) {
            return false;
        }

        $size = getimagesize($file_path);
        if (!$size) {
            return false;
        }

        return array(
            'width' => $size[0],
            'height' => $size[1],
            'mime_type' => $size['mime'],
            'file_size' => filesize($file_path),
            'file_path' => $file_path,
            'filename' => basename($file_path),
            'extension' => strtolower(pathinfo($file_path, PATHINFO_EXTENSION)),
        );
    }

    /**
     * Get supported image extensions
     */
    public static function get_supported_extensions() {
        return array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif');
    }

    /**
     * Check if current user can manage images
     */
    public static function current_user_can_manage_images() {
        return current_user_can('upload_files') && current_user_can('edit_posts');
    }

    /**
     * Get the WordPress uploads directory info
     */
    public static function get_uploads_dir() {
        return wp_upload_dir();
    }

    /**
     * Generate a unique filename in a directory
     */
    public static function get_unique_filename($directory, $filename, $extension) {
        $full_path = trailingslashit($directory) . $filename . '.' . $extension;

        if (!file_exists($full_path)) {
            return $full_path;
        }

        $counter = 1;
        do {
            $new_filename = $filename . '-' . $counter . '.' . $extension;
            $full_path = trailingslashit($directory) . $new_filename;
            $counter++;
        } while (file_exists($full_path));

        return $full_path;
    }

    /**
     * Truncate text at word boundary
     */
    public static function truncate_at_word($text, $max_length, $append = '') {
        if (strlen($text) <= $max_length) {
            return $text;
        }

        $text = substr($text, 0, $max_length);
        $last_space = strrpos($text, '-');

        if ($last_space !== false && $last_space > $max_length / 2) {
            $text = substr($text, 0, $last_space);
        }

        return trim($text, '-') . $append;
    }

    /**
     * Generate a random string
     */
    public static function generate_random_string($length = 8) {
        return substr(md5(uniqid(mt_rand(), true)), 0, $length);
    }

    /**
     * Get time elapsed since a timestamp
     */
    public static function time_elapsed($timestamp) {
        $diff = current_time('timestamp') - strtotime($timestamp);

        if ($diff < 60) {
            return __('Just now', 'hozio-image-optimizer');
        }

        $intervals = array(
            31536000 => array('year', 'years'),
            2592000 => array('month', 'months'),
            604800 => array('week', 'weeks'),
            86400 => array('day', 'days'),
            3600 => array('hour', 'hours'),
            60 => array('minute', 'minutes'),
        );

        foreach ($intervals as $seconds => $labels) {
            $count = floor($diff / $seconds);
            if ($count >= 1) {
                $label = $count === 1 ? $labels[0] : $labels[1];
                return sprintf(__('%d %s ago', 'hozio-image-optimizer'), $count, $label);
            }
        }

        return __('Just now', 'hozio-image-optimizer');
    }

    /**
     * Check if Imagick is available and working
     */
    public static function imagick_available() {
        if (!extension_loaded('imagick')) {
            return false;
        }

        try {
            $imagick = new Imagick();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if GD is available with required functions
     */
    public static function gd_available() {
        if (!extension_loaded('gd')) {
            return false;
        }

        $required_functions = array(
            'imagecreatefromjpeg',
            'imagecreatefrompng',
            'imagejpeg',
            'imagepng',
        );

        foreach ($required_functions as $func) {
            if (!function_exists($func)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the best available image library
     */
    public static function get_best_image_library() {
        if (self::imagick_available()) {
            return 'imagick';
        }

        if (self::gd_available()) {
            return 'gd';
        }

        return false;
    }
}
