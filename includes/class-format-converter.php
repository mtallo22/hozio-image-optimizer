<?php
/**
 * Format Converter - WebP and AVIF conversion
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Format_Converter {

    /**
     * Supported source formats for conversion
     */
    private $convertible_formats = array('jpg', 'jpeg', 'png', 'gif');

    /**
     * Check if WebP is supported on this server
     */
    public static function webp_supported() {
        return function_exists('imagewebp') ||
            (extension_loaded('imagick') && in_array('WEBP', Imagick::queryFormats()));
    }

    /**
     * Check if AVIF is supported on this server
     */
    public static function avif_supported() {
        return function_exists('imageavif') ||
            (extension_loaded('imagick') && in_array('AVIF', Imagick::queryFormats()));
    }

    /**
     * Convert image to WebP format
     *
     * @param string $source_path Source image path
     * @param string $dest_path Destination path (optional, will use source path with .webp)
     * @param array $options Conversion options
     * @return array Result
     */
    public function convert_to_webp($source_path, $dest_path = null, $options = array()) {
        if (!self::webp_supported()) {
            return array(
                'success' => false,
                'error' => __('WebP is not supported on this server', 'hozio-image-optimizer'),
            );
        }

        // Default options
        $defaults = array(
            'quality' => get_option('hozio_webp_quality', 82),
            'delete_original' => false,
        );
        $options = wp_parse_args($options, $defaults);

        // Validate source
        if (!file_exists($source_path)) {
            return array(
                'success' => false,
                'error' => __('Source file not found', 'hozio-image-optimizer'),
            );
        }

        // Check if source can be converted
        $extension = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));

        // If already WebP, skip gracefully instead of reporting an error
        if ($extension === 'webp') {
            return array(
                'success' => true,
                'skipped' => true,
                'message' => __('Image is already in WebP format', 'hozio-image-optimizer'),
                'original_size' => filesize($source_path),
                'new_size' => filesize($source_path),
                'savings' => 0,
            );
        }

        if (!in_array($extension, $this->convertible_formats)) {
            return array(
                'success' => false,
                'error' => __('Source format cannot be converted to WebP', 'hozio-image-optimizer'),
            );
        }

        // Generate destination path if not provided
        if (!$dest_path) {
            $dest_path = preg_replace('/\.[^.]+$/', '.webp', $source_path);
        }

        // Get original file size
        $original_size = filesize($source_path);

        // Convert using best available method
        $use_imagick = Hozio_Image_Optimizer_Helpers::imagick_available();

        if ($use_imagick) {
            $result = $this->convert_webp_imagick($source_path, $dest_path, $options['quality']);
        } else {
            $result = $this->convert_webp_gd($source_path, $dest_path, $options['quality']);
        }

        if (!$result['success']) {
            return $result;
        }

        // Verify conversion
        if (!file_exists($dest_path)) {
            return array(
                'success' => false,
                'error' => __('WebP file was not created', 'hozio-image-optimizer'),
            );
        }

        $new_size = filesize($dest_path);

        // Delete original if requested
        if ($options['delete_original'] && file_exists($source_path)) {
            @unlink($source_path);
        }

        return array(
            'success' => true,
            'source_path' => $source_path,
            'dest_path' => $dest_path,
            'original_size' => $original_size,
            'new_size' => $new_size,
            'savings' => $original_size - $new_size,
            'savings_percent' => round((($original_size - $new_size) / $original_size) * 100, 1),
        );
    }

    /**
     * Convert to WebP using Imagick
     */
    private function convert_webp_imagick($source, $dest, $quality) {
        try {
            $imagick = new Imagick($source);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($quality);

            // Preserve transparency
            $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
            $imagick->setBackgroundColor(new ImagickPixel('transparent'));

            $imagick->writeImage($dest);
            $imagick->destroy();

            return array('success' => true);
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Imagick: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Convert to WebP using GD
     */
    private function convert_webp_gd($source, $dest, $quality) {
        $source_info = @getimagesize($source);
        if (!$source_info) {
            return array(
                'success' => false,
                'error' => __('Could not read source image', 'hozio-image-optimizer'),
            );
        }

        // Create image resource based on type
        switch ($source_info['mime']) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($source);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($source);
                break;
            default:
                return array(
                    'success' => false,
                    'error' => __('Unsupported source format', 'hozio-image-optimizer'),
                );
        }

        if (!$image) {
            return array(
                'success' => false,
                'error' => __('Failed to load source image', 'hozio-image-optimizer'),
            );
        }

        // Preserve transparency for PNG/GIF
        if ($source_info['mime'] === 'image/png' || $source_info['mime'] === 'image/gif') {
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }

        // Convert to WebP
        $result = @imagewebp($image, $dest, $quality);
        imagedestroy($image);

        if (!$result) {
            return array(
                'success' => false,
                'error' => __('Failed to save WebP image', 'hozio-image-optimizer'),
            );
        }

        return array('success' => true);
    }

    /**
     * Convert image to AVIF format
     */
    public function convert_to_avif($source_path, $dest_path = null, $options = array()) {
        if (!self::avif_supported()) {
            return array(
                'success' => false,
                'error' => __('AVIF is not supported on this server (requires PHP 8.1+)', 'hozio-image-optimizer'),
            );
        }

        // Default options
        $defaults = array(
            'quality' => get_option('hozio_avif_quality', 75),
            'delete_original' => false,
        );
        $options = wp_parse_args($options, $defaults);

        // Validate source
        if (!file_exists($source_path)) {
            return array(
                'success' => false,
                'error' => __('Source file not found', 'hozio-image-optimizer'),
            );
        }

        // If already AVIF, skip gracefully instead of processing
        $extension = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));
        if ($extension === 'avif') {
            return array(
                'success' => true,
                'skipped' => true,
                'message' => __('Image is already in AVIF format', 'hozio-image-optimizer'),
                'original_size' => filesize($source_path),
                'new_size' => filesize($source_path),
                'savings' => 0,
            );
        }

        // Generate destination path
        if (!$dest_path) {
            $dest_path = preg_replace('/\.[^.]+$/', '.avif', $source_path);
        }

        $original_size = filesize($source_path);

        // Convert using best available method
        $use_imagick = Hozio_Image_Optimizer_Helpers::imagick_available() &&
            in_array('AVIF', Imagick::queryFormats());

        if ($use_imagick) {
            $result = $this->convert_avif_imagick($source_path, $dest_path, $options['quality']);
        } else {
            $result = $this->convert_avif_gd($source_path, $dest_path, $options['quality']);
        }

        if (!$result['success']) {
            return $result;
        }

        if (!file_exists($dest_path)) {
            return array(
                'success' => false,
                'error' => __('AVIF file was not created', 'hozio-image-optimizer'),
            );
        }

        $new_size = filesize($dest_path);

        if ($options['delete_original']) {
            @unlink($source_path);
        }

        return array(
            'success' => true,
            'source_path' => $source_path,
            'dest_path' => $dest_path,
            'original_size' => $original_size,
            'new_size' => $new_size,
            'savings' => $original_size - $new_size,
            'savings_percent' => round((($original_size - $new_size) / $original_size) * 100, 1),
        );
    }

    /**
     * Convert to AVIF using Imagick
     */
    private function convert_avif_imagick($source, $dest, $quality) {
        try {
            $imagick = new Imagick($source);
            $imagick->setImageFormat('avif');
            $imagick->setImageCompressionQuality($quality);
            $imagick->writeImage($dest);
            $imagick->destroy();

            return array('success' => true);
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Imagick: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Convert to AVIF using GD (PHP 8.1+)
     */
    private function convert_avif_gd($source, $dest, $quality) {
        if (!function_exists('imageavif')) {
            return array(
                'success' => false,
                'error' => __('AVIF requires PHP 8.1+', 'hozio-image-optimizer'),
            );
        }

        $source_info = @getimagesize($source);
        if (!$source_info) {
            return array(
                'success' => false,
                'error' => __('Could not read source image', 'hozio-image-optimizer'),
            );
        }

        switch ($source_info['mime']) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($source);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($source);
                break;
            case 'image/webp':
                $image = @imagecreatefromwebp($source);
                break;
            default:
                return array(
                    'success' => false,
                    'error' => __('Unsupported source format', 'hozio-image-optimizer'),
                );
        }

        if (!$image) {
            return array(
                'success' => false,
                'error' => __('Failed to load source image', 'hozio-image-optimizer'),
            );
        }

        $result = @imageavif($image, $dest, $quality);
        imagedestroy($image);

        return array('success' => $result);
    }

    /**
     * Convert attachment to specified format
     */
    public function convert_attachment($attachment_id, $format = 'webp', $options = array()) {
        $file_path = get_attached_file($attachment_id);

        if (!$file_path) {
            return array(
                'success' => false,
                'error' => __('Attachment not found', 'hozio-image-optimizer'),
            );
        }

        // Generate new path with new extension
        $new_path = preg_replace('/\.[^.]+$/', '.' . $format, $file_path);

        // Merge options
        $options = wp_parse_args($options, array(
            'delete_original' => true, // Default to replacing
        ));

        // Convert
        if ($format === 'webp') {
            $result = $this->convert_to_webp($file_path, $new_path, $options);
        } elseif ($format === 'avif') {
            $result = $this->convert_to_avif($file_path, $new_path, $options);
        } else {
            return array(
                'success' => false,
                'error' => __('Unsupported target format', 'hozio-image-optimizer'),
            );
        }

        if (!$result['success']) {
            return $result;
        }

        // Update WordPress database
        update_attached_file($attachment_id, $new_path);

        // Update post mime type
        wp_update_post(array(
            'ID' => $attachment_id,
            'post_mime_type' => 'image/' . $format,
        ));

        // Regenerate metadata
        $metadata = wp_generate_attachment_metadata($attachment_id, $new_path);
        wp_update_attachment_metadata($attachment_id, $metadata);

        return array_merge($result, array(
            'attachment_id' => $attachment_id,
            'old_path' => $file_path,
            'new_path' => $new_path,
        ));
    }

    /**
     * Get best format for an image
     */
    public function recommend_format($file_path) {
        if (!file_exists($file_path)) {
            return null;
        }

        $current_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $image_info = @getimagesize($file_path);

        if (!$image_info) {
            return null;
        }

        // Already WebP or AVIF
        if (in_array($current_ext, array('webp', 'avif'))) {
            return array(
                'format' => $current_ext,
                'reason' => __('Already in optimal format', 'hozio-image-optimizer'),
            );
        }

        // Check for transparency
        $has_transparency = false;
        if ($current_ext === 'png' || $current_ext === 'gif') {
            $has_transparency = $this->detect_transparency($file_path, $image_info['mime']);
        }

        // Recommend based on support and image type
        if (self::avif_supported() && get_option('hozio_convert_to_avif', false)) {
            return array(
                'format' => 'avif',
                'reason' => __('AVIF provides best compression', 'hozio-image-optimizer'),
                'estimated_savings' => '50-70%',
            );
        }

        if (self::webp_supported() && get_option('hozio_convert_to_webp', true)) {
            return array(
                'format' => 'webp',
                'reason' => __('WebP provides excellent compression with wide browser support', 'hozio-image-optimizer'),
                'estimated_savings' => '25-35%',
            );
        }

        return array(
            'format' => $current_ext,
            'reason' => __('No better format available', 'hozio-image-optimizer'),
        );
    }

    /**
     * Detect if image has transparency
     */
    private function detect_transparency($file_path, $mime_type) {
        if ($mime_type === 'image/png') {
            // Check PNG for alpha channel
            $contents = file_get_contents($file_path);
            // Check color type in IHDR chunk (offset 25)
            if (strlen($contents) > 25) {
                $color_type = ord($contents[25]);
                // Color type 4 or 6 includes alpha
                return ($color_type === 4 || $color_type === 6);
            }
        }

        if ($mime_type === 'image/gif') {
            // GIF can have transparency
            $image = @imagecreatefromgif($file_path);
            if ($image) {
                $transparent_index = imagecolortransparent($image);
                imagedestroy($image);
                return $transparent_index >= 0;
            }
        }

        return false;
    }
}
