<?php
/**
 * Image Analyzer - Orchestrates AI analysis and generates filenames/titles
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Analyzer {

    /**
     * @var Hozio_Image_Optimizer_OpenAI_Client
     */
    private $openai_client;

    /**
     * Constructor
     */
    public function __construct() {
        $this->openai_client = new Hozio_Image_Optimizer_OpenAI_Client();
    }

    /**
     * Analyze an image and generate filename, title, alt text, etc.
     *
     * @param int $attachment_id WordPress attachment ID
     * @param string $location Location hint
     * @param string $keyword_hint Keyword hint
     * @return array Analysis results
     */
    public function analyze($attachment_id, $location = '', $keyword_hint = '') {
        $file_path = get_attached_file($attachment_id);

        if (!$file_path || !file_exists($file_path)) {
            return array(
                'success' => false,
                'error' => __('Image file not found', 'hozio-image-optimizer'),
            );
        }

        // Build options
        $options = array(
            'location' => $location,
            'keyword_hint' => $keyword_hint,
            'generate_alt_text' => get_option('hozio_enable_ai_alt_text', true),
            'generate_caption' => get_option('hozio_enable_ai_caption', true),
            'generate_tags' => get_option('hozio_enable_ai_tagging', false),
        );

        // Call OpenAI API
        $result = $this->openai_client->analyze_image($file_path, $options);

        if (!$result['success']) {
            return $result;
        }

        $parsed = $result['parsed'];

        // Apply templates
        $filename = $this->apply_filename_template($parsed, $location);
        $title = $this->apply_title_template($parsed, $location);

        // Enforce max filename length
        $max_length = get_option('hozio_max_filename_length', 50);
        $filename = Hozio_Image_Optimizer_Helpers::truncate_at_word($filename, $max_length);

        return array(
            'success' => true,
            'filename' => $filename,
            'title' => $title,
            'alt_text' => $parsed['alt_text'] ?? '',
            'caption' => $parsed['caption'] ?? '',
            'description' => $parsed['description'] ?? '',
            'tags' => $parsed['tags'] ?? array(),
            'raw_analysis' => $parsed,
        );
    }

    /**
     * Apply filename template
     */
    private function apply_filename_template($analysis, $location) {
        $template = get_option('hozio_naming_template', '{keyword}-{location}');

        // Validate template - must contain at least one valid placeholder
        $valid_placeholders = array('{site_title}', '{keyword}', '{location}', '{timestamp}', '{random}');
        $has_valid_placeholder = false;
        foreach ($valid_placeholders as $placeholder) {
            if (strpos($template, $placeholder) !== false) {
                $has_valid_placeholder = true;
                break;
            }
        }

        // Fall back to default if template is invalid (e.g., "custom" or empty)
        if (!$has_valid_placeholder) {
            $template = '{keyword}-{location}';
        }

        // Get components
        $site_title = Hozio_Image_Optimizer_Helpers::sanitize_seo_filename(get_bloginfo('name'));
        $keyword = isset($analysis['filename']) ? $analysis['filename'] : 'image';
        $location_clean = Hozio_Image_Optimizer_Helpers::sanitize_seo_filename($location);

        // Template replacements
        $replacements = array(
            '{site_title}' => $site_title,
            '{keyword}' => $keyword,
            '{location}' => $location_clean,
            '{timestamp}' => date('Ymd'),
            '{random}' => Hozio_Image_Optimizer_Helpers::generate_random_string(6),
        );

        $filename = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template
        );

        // Clean up
        $filename = preg_replace('/-+/', '-', $filename); // Remove multiple hyphens
        $filename = trim($filename, '-'); // Remove leading/trailing hyphens

        // Remove empty template parts (when location is empty)
        $filename = preg_replace('/-{2,}/', '-', $filename);
        $filename = trim($filename, '-');

        return $filename;
    }

    /**
     * Apply title template
     */
    private function apply_title_template($analysis, $location) {
        $template = get_option('hozio_title_template', '{title}');

        // Validate template - must contain at least one valid placeholder
        $valid_placeholders = array('{site_title}', '{title}', '{keyword}', '{location}');
        $has_valid_placeholder = false;
        foreach ($valid_placeholders as $placeholder) {
            if (strpos($template, $placeholder) !== false) {
                $has_valid_placeholder = true;
                break;
            }
        }

        // Fall back to default if template is invalid (e.g., "custom" or empty)
        if (!$has_valid_placeholder) {
            $template = '{title}';
        }

        // Get components
        $site_title = get_bloginfo('name');
        $title = isset($analysis['title']) ? $analysis['title'] : '';
        $keyword = isset($analysis['filename']) ? ucwords(str_replace('-', ' ', $analysis['filename'])) : '';

        // Template replacements
        $replacements = array(
            '{site_title}' => $site_title,
            '{title}' => $title,
            '{keyword}' => $keyword,
            '{location}' => $location,
        );

        $result = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template
        );

        // Clean up
        $result = preg_replace('/\s+-\s+$/', '', $result); // Remove trailing " - "
        $result = preg_replace('/^\s+-\s+/', '', $result); // Remove leading " - "
        $result = trim($result);

        return $result ?: $title;
    }

    /**
     * Generate alt text for an image
     *
     * @param int $attachment_id
     * @return array Result with alt_text
     */
    public function generate_alt_text($attachment_id) {
        $file_path = get_attached_file($attachment_id);

        if (!$file_path) {
            return array(
                'success' => false,
                'error' => __('Image not found', 'hozio-image-optimizer'),
            );
        }

        $options = array(
            'generate_alt_text' => true,
            'generate_caption' => false,
            'generate_tags' => false,
        );

        $result = $this->openai_client->analyze_image($file_path, $options);

        if (!$result['success']) {
            return $result;
        }

        $alt_text = $result['parsed']['alt_text'] ?? '';

        if (empty($alt_text)) {
            return array(
                'success' => false,
                'error' => __('Failed to generate alt text', 'hozio-image-optimizer'),
            );
        }

        return array(
            'success' => true,
            'alt_text' => $alt_text,
        );
    }

    /**
     * Apply alt text to an attachment
     */
    public function apply_alt_text($attachment_id, $alt_text) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);

        return array(
            'success' => true,
            'alt_text' => $alt_text,
        );
    }

    /**
     * Generate and apply alt text
     */
    public function generate_and_apply_alt_text($attachment_id) {
        $result = $this->generate_alt_text($attachment_id);

        if (!$result['success']) {
            return $result;
        }

        return $this->apply_alt_text($attachment_id, $result['alt_text']);
    }

    /**
     * Batch analyze multiple images
     */
    public function batch_analyze($attachment_ids, $location = '', $keyword_hint = '') {
        $results = array();

        foreach ($attachment_ids as $attachment_id) {
            $results[$attachment_id] = $this->analyze($attachment_id, $location, $keyword_hint);

            // Rate limiting - 0.5 second delay between API calls
            usleep(500000);
        }

        return $results;
    }

    /**
     * Preview what the filename would be
     */
    public function preview($attachment_id, $location = '', $keyword_hint = '') {
        $analysis = $this->analyze($attachment_id, $location, $keyword_hint);

        if (!$analysis['success']) {
            return $analysis;
        }

        $current_path = get_attached_file($attachment_id);
        $current_filename = basename($current_path);
        $current_title = get_the_title($attachment_id);

        return array(
            'success' => true,
            'attachment_id' => $attachment_id,
            'current' => array(
                'filename' => $current_filename,
                'title' => $current_title,
            ),
            'proposed' => array(
                'filename' => $analysis['filename'] . '.' . pathinfo($current_path, PATHINFO_EXTENSION),
                'title' => $analysis['title'],
                'alt_text' => $analysis['alt_text'],
            ),
            'thumbnail' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
        );
    }
}
