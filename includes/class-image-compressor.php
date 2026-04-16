<?php
/**
 * Image Compressor - Working compression with multiple algorithms
 *
 * Key fixes from original:
 * 1. Actually replaces original file (old version just created _compressed file)
 * 2. Proper quality reduction that produces real file size savings
 * 3. Metadata stripping (EXIF removal) - major size savings
 * 4. Uses Imagick when available (better quality)
 * 5. Memory management for large images
 * 6. Timeout prevention
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Compressor {

    /**
     * Use Imagick if available
     */
    private $use_imagick = false;

    /**
     * Constructor
     */
    public function __construct() {
        $this->use_imagick = Hozio_Image_Optimizer_Helpers::imagick_available();
    }

    /**
     * Compress an image
     *
     * @param string $file_path Path to image file
     * @param array $options Compression options
     * @return array Result with success status and details
     */
    public function compress($file_path, $options = array()) {
        // Start time for timeout management
        $start_time = time();
        $max_execution = 30; // 30 second timeout per image

        // Default options
        $defaults = array(
            'quality' => get_option('hozio_compression_quality', 82),
            'max_width' => get_option('hozio_max_width', 2048),
            'max_height' => get_option('hozio_max_height', 2048),
            'strip_metadata' => get_option('hozio_strip_metadata', false),
            'preserve_original' => false, // We handle backup separately
            'force' => false, // Force re-compression even if already optimized
            'target_filesize' => 0, // Target file size in KB (0 = disabled)
        );

        $options = wp_parse_args($options, $defaults);

        // Determine target file size: use option param or global setting
        $target_kb = intval($options['target_filesize']);
        if ($target_kb <= 0) {
            $target_kb = intval(get_option('hozio_target_filesize', 200));
        }

        // Delegate to multi-pass compression if enabled and target is set
        if (get_option('hozio_enable_multipass', true) && $target_kb > 0) {
            $options['target_filesize'] = $target_kb;
            return $this->compress_multipass($file_path, $options);
        }

        // Validate input
        if (!file_exists($file_path)) {
            return array(
                'success' => false,
                'error' => __('File does not exist', 'hozio-image-optimizer'),
            );
        }

        // Get original file info
        $original_size = filesize($file_path);
        $image_info = @getimagesize($file_path);

        if (!$image_info) {
            return array(
                'success' => false,
                'error' => __('Invalid image file', 'hozio-image-optimizer'),
            );
        }

        $original_width = $image_info[0];
        $original_height = $image_info[1];
        $mime_type = $image_info['mime'];

        // Check if we should compress (skip check if force is enabled)
        $needs_resize = ($original_width > $options['max_width'] || $original_height > $options['max_height']);
        $is_uncompressed = $this->is_compressible($file_path, $mime_type);

        if (!$options['force'] && !$needs_resize && !$is_uncompressed) {
            return array(
                'success' => true,
                'skipped' => true,
                'message' => __('Image already optimized', 'hozio-image-optimizer'),
                'original_size' => $original_size,
                'new_size' => $original_size,
                'savings' => 0,
            );
        }

        Hozio_Image_Optimizer_Helpers::log("Compressing: {$file_path} ({$original_width}x{$original_height}, " .
            Hozio_Image_Optimizer_Helpers::format_bytes($original_size) . ")");

        // Create temp file for output
        $temp_file = $file_path . '.tmp.' . uniqid();

        try {
            // Choose compression method
            if ($this->use_imagick) {
                $result = $this->compress_with_imagick(
                    $file_path,
                    $temp_file,
                    $mime_type,
                    $options
                );
            } else {
                $result = $this->compress_with_gd(
                    $file_path,
                    $temp_file,
                    $mime_type,
                    $options
                );
            }

            if (!$result['success']) {
                @unlink($temp_file);
                return $result;
            }

            // Verify temp file was created
            if (!file_exists($temp_file)) {
                return array(
                    'success' => false,
                    'error' => __('Compression failed - no output file', 'hozio-image-optimizer'),
                );
            }

            $new_size = filesize($temp_file);

            // Only replace if we actually saved space (or file is now smaller format)
            // Unless force is enabled - then always proceed with the operation
            if ($new_size >= $original_size && !$needs_resize && !$options['force']) {
                @unlink($temp_file);
                return array(
                    'success' => true,
                    'skipped' => true,
                    'message' => __('Compression would not reduce file size', 'hozio-image-optimizer'),
                    'original_size' => $original_size,
                    'new_size' => $original_size,
                    'savings' => 0,
                );
            }

            // Replace original with compressed version
            if (!@unlink($file_path)) {
                @unlink($temp_file);
                return array(
                    'success' => false,
                    'error' => __('Failed to remove original file', 'hozio-image-optimizer'),
                );
            }

            if (!@rename($temp_file, $file_path)) {
                return array(
                    'success' => false,
                    'error' => __('Failed to save compressed file', 'hozio-image-optimizer'),
                );
            }

            // Get new dimensions
            $new_info = @getimagesize($file_path);
            $new_width = $new_info ? $new_info[0] : $original_width;
            $new_height = $new_info ? $new_info[1] : $original_height;

            $savings = $original_size - $new_size;
            $savings_percent = $original_size > 0 ? round(($savings / $original_size) * 100, 1) : 0;

            Hozio_Image_Optimizer_Helpers::log("Compression complete: " .
                Hozio_Image_Optimizer_Helpers::format_bytes($original_size) . " -> " .
                Hozio_Image_Optimizer_Helpers::format_bytes($new_size) . " ({$savings_percent}% reduction)");

            return array(
                'success' => true,
                'original_size' => $original_size,
                'new_size' => $new_size,
                'savings' => $savings,
                'savings_percent' => $savings_percent,
                'original_dimensions' => "{$original_width}x{$original_height}",
                'new_dimensions' => "{$new_width}x{$new_height}",
                'method' => $this->use_imagick ? 'imagick' : 'gd',
            );

        } catch (Exception $e) {
            @unlink($temp_file);
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * Check if image is compressible (not already heavily optimized)
     */
    private function is_compressible($file_path, $mime_type) {
        $file_size = filesize($file_path);
        $image_info = @getimagesize($file_path);

        if (!$image_info) {
            return false;
        }

        // Calculate bytes per pixel
        $pixels = $image_info[0] * $image_info[1];
        $bytes_per_pixel = $file_size / $pixels;

        // Thresholds for "already optimized"
        // JPEG: < 0.3 bytes/pixel is well optimized
        // PNG: < 0.5 bytes/pixel is well optimized
        switch ($mime_type) {
            case 'image/jpeg':
                return $bytes_per_pixel > 0.3;
            case 'image/png':
                return $bytes_per_pixel > 0.5;
            case 'image/webp':
                return $bytes_per_pixel > 0.2;
            default:
                return true;
        }
    }

    /**
     * Compress using Imagick (better quality)
     */
    private function compress_with_imagick($source, $dest, $mime_type, $options) {
        try {
            $imagick = new Imagick($source);

            // Auto-orient based on EXIF
            $imagick->autoOrient();

            // Strip metadata if requested (big file size savings)
            if ($options['strip_metadata']) {
                $imagick->stripImage();
            }

            // Calculate new dimensions
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            $new_dimensions = $this->calculate_dimensions(
                $width, $height,
                $options['max_width'], $options['max_height']
            );

            // Resize if needed
            if ($new_dimensions['width'] !== $width || $new_dimensions['height'] !== $height) {
                $imagick->resizeImage(
                    $new_dimensions['width'],
                    $new_dimensions['height'],
                    Imagick::FILTER_LANCZOS,
                    1
                );
            }

            // Set compression based on type
            switch ($mime_type) {
                case 'image/jpeg':
                    $imagick->setImageFormat('jpeg');
                    $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
                    $imagick->setImageCompressionQuality($options['quality']);
                    // Progressive JPEG (better perceived loading)
                    $imagick->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                    // Optimal sampling factor
                    $imagick->setSamplingFactors(array('2x2', '1x1', '1x1'));
                    break;

                case 'image/png':
                    $imagick->setImageFormat('png');
                    // PNG compression level (0-9, higher = smaller but slower)
                    $png_level = round((100 - $options['quality']) / 10);
                    $imagick->setImageCompressionQuality($png_level * 10);
                    break;

                case 'image/webp':
                    $imagick->setImageFormat('webp');
                    $imagick->setImageCompressionQuality($options['quality']);
                    break;

                case 'image/gif':
                    // For GIF, just resize and optimize
                    $imagick->setImageFormat('gif');
                    break;
            }

            // Write output
            $imagick->writeImage($dest);
            $imagick->destroy();

            return array('success' => true);

        } catch (Exception $e) {
            if (isset($imagick)) {
                $imagick->destroy();
            }
            return array(
                'success' => false,
                'error' => 'Imagick error: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Compress using GD (fallback)
     */
    private function compress_with_gd($source, $dest, $mime_type, $options) {
        // Load source image
        switch ($mime_type) {
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
                if (!function_exists('imagecreatefromwebp')) {
                    return array(
                        'success' => false,
                        'error' => __('WebP not supported', 'hozio-image-optimizer'),
                    );
                }
                $image = @imagecreatefromwebp($source);
                break;
            default:
                return array(
                    'success' => false,
                    'error' => __('Unsupported image type', 'hozio-image-optimizer'),
                );
        }

        if (!$image) {
            return array(
                'success' => false,
                'error' => __('Failed to load image', 'hozio-image-optimizer'),
            );
        }

        // Get original dimensions
        $width = imagesx($image);
        $height = imagesy($image);

        // Calculate new dimensions
        $new_dimensions = $this->calculate_dimensions(
            $width, $height,
            $options['max_width'], $options['max_height']
        );

        // Create new image for resizing (or use original if no resize needed)
        if ($new_dimensions['width'] !== $width || $new_dimensions['height'] !== $height) {
            $new_image = imagecreatetruecolor($new_dimensions['width'], $new_dimensions['height']);

            // Preserve transparency for PNG/GIF
            if ($mime_type === 'image/png' || $mime_type === 'image/gif') {
                imagealphablending($new_image, false);
                imagesavealpha($new_image, true);
                $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
                imagefill($new_image, 0, 0, $transparent);
            }

            // High-quality resampling
            imagecopyresampled(
                $new_image, $image,
                0, 0, 0, 0,
                $new_dimensions['width'], $new_dimensions['height'],
                $width, $height
            );

            imagedestroy($image);
            $image = $new_image;
        }

        // Save with compression
        $success = false;
        switch ($mime_type) {
            case 'image/jpeg':
                // JPEG quality is 0-100
                $success = imagejpeg($image, $dest, $options['quality']);
                break;

            case 'image/png':
                // PNG compression is 0-9 (9 = max compression)
                // Convert quality (0-100) to PNG compression (9-0)
                $png_compression = round((100 - $options['quality']) / 11.11);
                $png_compression = max(0, min(9, $png_compression));
                $success = imagepng($image, $dest, $png_compression);
                break;

            case 'image/gif':
                $success = imagegif($image, $dest);
                break;

            case 'image/webp':
                if (function_exists('imagewebp')) {
                    $success = imagewebp($image, $dest, $options['quality']);
                }
                break;
        }

        imagedestroy($image);

        if (!$success) {
            return array(
                'success' => false,
                'error' => __('Failed to save compressed image', 'hozio-image-optimizer'),
            );
        }

        return array('success' => true);
    }

    /**
     * Calculate new dimensions maintaining aspect ratio
     */
    private function calculate_dimensions($width, $height, $max_width, $max_height) {
        // No resize needed
        if ($width <= $max_width && $height <= $max_height) {
            return array('width' => $width, 'height' => $height);
        }

        // Calculate ratio
        $ratio_w = $max_width / $width;
        $ratio_h = $max_height / $height;
        $ratio = min($ratio_w, $ratio_h);

        return array(
            'width' => round($width * $ratio),
            'height' => round($height * $ratio),
        );
    }

    /**
     * Get resolution-aware quality floor
     * Smaller images need higher quality to avoid visible blur
     *
     * @param int $width  Image width
     * @param int $height Image height
     * @param int $base_quality Base quality floor from settings
     * @return int Adjusted quality floor
     */
    private function get_resolution_aware_quality($width, $height, $base_quality) {
        $longest_side = max($width, $height);

        if ($longest_side < 500) {
            return max(85, $base_quality);
        } elseif ($longest_side < 800) {
            return max(78, $base_quality);
        } elseif ($longest_side < 1200) {
            return max(72, $base_quality);
        }

        return $base_quality;
    }

    /**
     * Multi-pass compression: iteratively compress until under target file size
     * Always compresses from the original to avoid generation loss.
     * Uses resolution-aware quality floors to prevent blur on small images.
     *
     * @param string $file_path Path to image file
     * @param array  $options   Compression options including target_filesize (KB)
     * @return array Result with success status and details
     */
    private function compress_multipass($file_path, $options) {
        if (!file_exists($file_path)) {
            return array(
                'success' => false,
                'error' => __('File does not exist', 'hozio-image-optimizer'),
            );
        }

        $original_size = filesize($file_path);
        $image_info = @getimagesize($file_path);

        if (!$image_info) {
            return array(
                'success' => false,
                'error' => __('Invalid image file', 'hozio-image-optimizer'),
            );
        }

        $original_width = $image_info[0];
        $original_height = $image_info[1];
        $mime_type = $image_info['mime'];
        $target_bytes = intval($options['target_filesize']) * 1024;
        $max_passes = intval(get_option('hozio_multipass_max_passes', 5));
        $configured_floor = intval(get_option('hozio_quality_floor', 60));
        $quality_floor = $this->get_resolution_aware_quality($original_width, $original_height, $configured_floor);

        // Even if under target, still try at least one compression pass
        // Only skip if the image is very small (under 20KB) and force is not set
        if ($original_size <= 20480 && !$options['force']) {
            return array(
                'success' => true,
                'skipped' => true,
                'message' => __('Image already very small', 'hozio-image-optimizer'),
                'original_size' => $original_size,
                'new_size' => $original_size,
                'savings' => 0,
            );
        }

        // Calculate how aggressive we need to be based on the ratio
        $size_ratio = $original_size / max($target_bytes, 1);
        // Progressively reduce max dimensions based on how far over target we are
        $effective_max_width = $options['max_width'];
        $effective_max_height = $options['max_height'];
        if ($size_ratio > 5 && $original_width > 1200) {
            // Very oversized (e.g. 500KB vs 80KB target) - aggressively downscale
            $effective_max_width = min($options['max_width'], 1200);
            $effective_max_height = min($options['max_height'], 1200);
        } elseif ($size_ratio > 3 && $original_width > 1400) {
            $effective_max_width = min($options['max_width'], 1400);
            $effective_max_height = min($options['max_height'], 1400);
        } elseif ($size_ratio > 2 && $original_width > 1600) {
            $effective_max_width = min($options['max_width'], 1600);
            $effective_max_height = min($options['max_height'], 1600);
        } elseif ($size_ratio > 1.5 && $original_width > 1920) {
            $effective_max_width = min($options['max_width'], 1920);
            $effective_max_height = min($options['max_height'], 1920);
        }

        Hozio_Image_Optimizer_Helpers::log("Multi-pass compression: {$file_path} " .
            "({$original_width}x{$original_height}, " .
            Hozio_Image_Optimizer_Helpers::format_bytes($original_size) . ") " .
            "target: " . Hozio_Image_Optimizer_Helpers::format_bytes($target_bytes) .
            ", quality floor: {$quality_floor}, ratio: {$size_ratio}x");

        // Keep original for each pass (compress from original each time to avoid generation loss)
        $original_copy = $file_path . '.multipass_orig.' . uniqid();
        if (!@copy($file_path, $original_copy)) {
            return array(
                'success' => false,
                'error' => __('Failed to create working copy for multi-pass compression', 'hozio-image-optimizer'),
            );
        }

        // Start quality: the further over target, the lower we start
        $current_quality = $options['quality'];
        if ($size_ratio > 6) {
            $current_quality = max($quality_floor, min($current_quality, 35));
        } elseif ($size_ratio > 4) {
            $current_quality = max($quality_floor, min($current_quality, 42));
        } elseif ($size_ratio > 3) {
            $current_quality = max($quality_floor, min($current_quality, 50));
        } elseif ($size_ratio > 2) {
            $current_quality = max($quality_floor, min($current_quality, 60));
        }

        $best_file = null;
        $best_size = $original_size;
        $pass_count = 0;
        $temp_files = array($original_copy);
        $compress_start = microtime(true);

        try {
            // Iterative quality reduction with timeout protection
            while ($pass_count < $max_passes && $current_quality >= $quality_floor) {
                // Safety: bail if approaching PHP timeout (25 second limit per image)
                if ((microtime(true) - $compress_start) > 25) {
                    Hozio_Image_Optimizer_Helpers::log("Multi-pass: timeout safety after {$pass_count} passes");
                    break;
                }
                $pass_count++;
                $pass_file = $file_path . '.pass' . $pass_count . '.' . uniqid();
                $temp_files[] = $pass_file;

                // Compress from ORIGINAL at current quality
                $pass_options = array(
                    'quality' => $current_quality,
                    'max_width' => $effective_max_width,
                    'max_height' => $effective_max_height,
                    'strip_metadata' => true,
                );

                if ($this->use_imagick) {
                    $result = $this->compress_with_imagick($original_copy, $pass_file, $mime_type, $pass_options);
                } else {
                    $result = $this->compress_with_gd($original_copy, $pass_file, $mime_type, $pass_options);
                }

                if (!$result['success'] || !file_exists($pass_file)) {
                    @unlink($pass_file);
                    $current_quality -= 8;
                    continue;
                }

                $pass_size = filesize($pass_file);

                // Accept this pass if it reduced size
                if ($pass_size < $best_size) {
                    if ($best_file && $best_file !== $file_path) {
                        @unlink($best_file);
                    }
                    $best_file = $pass_file;
                    $best_size = $pass_size;
                } else {
                    @unlink($pass_file);
                }

                // Check if we've reached the target
                if ($best_size <= $target_bytes) {
                    Hozio_Image_Optimizer_Helpers::log("Multi-pass: Target reached at pass {$pass_count} (quality={$current_quality})");
                    break;
                }

                // More aggressive quality reduction: step by 8, then 5 near the floor
                $step = ($current_quality > $quality_floor + 15) ? 8 : 5;
                $current_quality = max($quality_floor, $current_quality - $step);

                // Stop if we've hit the floor and already tried it
                if ($current_quality <= $quality_floor && $pass_count > 1) {
                    break;
                }
            }

            // If we found a better result, replace original
            if ($best_file && $best_size < $original_size) {
                if (!@unlink($file_path)) {
                    $this->cleanup_temp_files($temp_files);
                    return array(
                        'success' => false,
                        'error' => __('Failed to remove original file', 'hozio-image-optimizer'),
                    );
                }

                if (!@rename($best_file, $file_path)) {
                    $this->cleanup_temp_files($temp_files);
                    return array(
                        'success' => false,
                        'error' => __('Failed to save compressed file', 'hozio-image-optimizer'),
                    );
                }

                // Remove from temp list since it's now the real file
                $temp_files = array_diff($temp_files, array($best_file));
            }

            // Clean up all remaining temp files
            $this->cleanup_temp_files($temp_files);

            $new_size = filesize($file_path);
            $savings = $original_size - $new_size;
            $savings_percent = $original_size > 0 ? round(($savings / $original_size) * 100, 1) : 0;

            // Get new dimensions
            $new_info = @getimagesize($file_path);
            $new_width = $new_info ? $new_info[0] : $original_width;
            $new_height = $new_info ? $new_info[1] : $original_height;

            Hozio_Image_Optimizer_Helpers::log("Multi-pass complete ({$pass_count} passes): " .
                Hozio_Image_Optimizer_Helpers::format_bytes($original_size) . " -> " .
                Hozio_Image_Optimizer_Helpers::format_bytes($new_size) . " ({$savings_percent}% reduction)");

            return array(
                'success' => true,
                'original_size' => $original_size,
                'new_size' => $new_size,
                'savings' => $savings,
                'savings_percent' => $savings_percent,
                'original_dimensions' => "{$original_width}x{$original_height}",
                'new_dimensions' => "{$new_width}x{$new_height}",
                'method' => ($this->use_imagick ? 'imagick' : 'gd') . '_multipass',
                'passes' => $pass_count,
            );

        } catch (Exception $e) {
            $this->cleanup_temp_files($temp_files);
            if ($best_file && file_exists($best_file)) {
                @unlink($best_file);
            }
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * Clean up temporary files created during multi-pass compression
     *
     * @param array $files Array of file paths to remove
     */
    private function cleanup_temp_files($files) {
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Compress attachment by ID
     */
    public function compress_attachment($attachment_id, $options = array()) {
        $file_path = get_attached_file($attachment_id);

        if (!$file_path) {
            return array(
                'success' => false,
                'error' => __('Attachment not found', 'hozio-image-optimizer'),
            );
        }

        $result = $this->compress($file_path, $options);

        if ($result['success'] && !isset($result['skipped'])) {
            // Regenerate WordPress metadata
            $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
            wp_update_attachment_metadata($attachment_id, $metadata);

            // Update statistics
            $this->update_stats($result['savings']);
        }

        return $result;
    }

    /**
     * Update compression statistics
     */
    private function update_stats($bytes_saved) {
        $total_saved = get_option('hozio_total_bytes_saved', 0);
        $total_processed = get_option('hozio_total_images_processed', 0);

        update_option('hozio_total_bytes_saved', $total_saved + $bytes_saved);
        update_option('hozio_total_images_processed', $total_processed + 1);
    }

    /**
     * Get estimated compression for an image
     */
    public function estimate_savings($file_path) {
        if (!file_exists($file_path)) {
            return null;
        }

        $file_size = filesize($file_path);
        $image_info = @getimagesize($file_path);

        if (!$image_info) {
            return null;
        }

        $quality = get_option('hozio_compression_quality', 82);

        // Rough estimation based on image type
        switch ($image_info['mime']) {
            case 'image/jpeg':
                // JPEG typically can save 30-60% with good compression
                $estimated_ratio = 0.5 + ($quality / 200); // Higher quality = less savings
                break;
            case 'image/png':
                // PNG savings vary wildly (10-70%)
                $estimated_ratio = 0.6;
                break;
            default:
                $estimated_ratio = 0.7;
        }

        $estimated_new_size = $file_size * $estimated_ratio;
        $estimated_savings = $file_size - $estimated_new_size;

        return array(
            'original_size' => $file_size,
            'estimated_size' => round($estimated_new_size),
            'estimated_savings' => round($estimated_savings),
            'estimated_percent' => round((1 - $estimated_ratio) * 100),
        );
    }
}
