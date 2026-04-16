<?php
/**
 * File Renamer - Safely rename image files with full database integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_File_Renamer {

    /**
     * @var Hozio_Image_Optimizer_Reference_Updater
     */
    private $reference_updater;

    /**
     * @var Hozio_Image_Optimizer_Backup_Manager
     */
    private $backup_manager;

    /**
     * Constructor
     */
    public function __construct() {
        $this->reference_updater = new Hozio_Image_Optimizer_Reference_Updater();
        $this->backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
    }

    /**
     * Rename an image file
     *
     * @param int $attachment_id WordPress attachment ID
     * @param string $new_filename New filename (without extension)
     * @param array $options Additional options
     * @return array Result
     */
    public function rename($attachment_id, $new_filename, $options = array()) {
        // Default options
        $defaults = array(
            'new_title' => '',
            'update_references' => true,
            'create_backup' => get_option('hozio_backup_enabled', false),
        );
        $options = wp_parse_args($options, $defaults);

        // Get current file path
        $old_path = get_attached_file($attachment_id);

        if (!$old_path || !file_exists($old_path)) {
            return array(
                'success' => false,
                'error' => __('Original file not found', 'hozio-image-optimizer'),
            );
        }

        // Sanitize new filename
        $new_filename = Hozio_Image_Optimizer_Helpers::sanitize_seo_filename($new_filename);

        if (empty($new_filename)) {
            return array(
                'success' => false,
                'error' => __('Invalid filename', 'hozio-image-optimizer'),
            );
        }

        // Get file info
        $path_info = pathinfo($old_path);
        $directory = $path_info['dirname'];
        $old_filename = $path_info['filename'];
        $extension = $path_info['extension'];

        // Skip if filename is the same
        if ($old_filename === $new_filename) {
            return array(
                'success' => true,
                'skipped' => true,
                'message' => __('Filename unchanged', 'hozio-image-optimizer'),
            );
        }

        // Generate new path (ensure unique)
        $new_path = Hozio_Image_Optimizer_Helpers::get_unique_filename(
            $directory,
            $new_filename,
            $extension
        );

        // Get actual filename used (in case it was made unique)
        $actual_new_filename = pathinfo($new_path, PATHINFO_FILENAME);

        // Get old URL for reference updating
        $old_url = wp_get_attachment_url($attachment_id);

        // Create backup if enabled
        if ($options['create_backup']) {
            $backup_result = $this->backup_manager->create_backup($attachment_id, $old_path);
            if (!$backup_result['success']) {
                Hozio_Image_Optimizer_Helpers::log('Warning: Backup failed: ' . $backup_result['error'], 'warning');
            }
        }

        // Perform the rename
        if (!@rename($old_path, $new_path)) {
            return array(
                'success' => false,
                'error' => __('Failed to rename file', 'hozio-image-optimizer'),
            );
        }

        // Rename thumbnails
        $this->rename_thumbnails($attachment_id, $old_filename, $actual_new_filename, $directory);

        // Update WordPress database
        update_attached_file($attachment_id, $new_path);

        // Regenerate metadata
        $metadata = wp_generate_attachment_metadata($attachment_id, $new_path);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Get new URL
        $new_url = wp_get_attachment_url($attachment_id);

        // Update title, caption (excerpt), and description (content) if provided
        $post_update = array(
            'ID' => $attachment_id,
            'post_name' => $actual_new_filename,
        );

        if (!empty($options['new_title'])) {
            $post_update['post_title'] = $options['new_title'];
        }

        if (!empty($options['caption'])) {
            $post_update['post_excerpt'] = $options['caption'];
        }

        if (!empty($options['description'])) {
            $post_update['post_content'] = $options['description'];
        }

        wp_update_post($post_update);

        // Update references in database
        $references_updated = array();
        if ($options['update_references']) {
            $references_updated = $this->reference_updater->update_references(
                $old_url,
                $new_url,
                $attachment_id
            );
        }

        Hozio_Image_Optimizer_Helpers::log("Renamed: {$old_filename}.{$extension} -> {$actual_new_filename}.{$extension}");

        return array(
            'success' => true,
            'old_path' => $old_path,
            'new_path' => $new_path,
            'old_filename' => $old_filename . '.' . $extension,
            'new_filename' => $actual_new_filename . '.' . $extension,
            'old_url' => $old_url,
            'new_url' => $new_url,
            'references_updated' => $references_updated,
        );
    }

    /**
     * Rename thumbnail files
     */
    private function rename_thumbnails($attachment_id, $old_filename, $new_filename, $directory) {
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (!$metadata || !isset($metadata['sizes'])) {
            return;
        }

        foreach ($metadata['sizes'] as $size => $data) {
            if (!isset($data['file'])) {
                continue;
            }

            $old_thumb_path = $directory . '/' . $data['file'];

            if (!file_exists($old_thumb_path)) {
                continue;
            }

            // Generate new thumbnail filename
            // Thumbnails are named like: filename-WIDTHxHEIGHT.ext
            $new_thumb_file = str_replace($old_filename, $new_filename, $data['file']);
            $new_thumb_path = $directory . '/' . $new_thumb_file;

            @rename($old_thumb_path, $new_thumb_path);
        }
    }

    /**
     * Rename with AI-generated name
     *
     * @param int $attachment_id Attachment ID
     * @param string $location Location hint for AI
     * @param string $keyword_hint Keyword hint for AI
     * @param array $options Additional options
     * @return array Result
     */
    public function rename_with_ai($attachment_id, $location = '', $keyword_hint = '', $options = array()) {
        // Get AI-generated name
        $analyzer = new Hozio_Image_Optimizer_Analyzer();
        $analysis = $analyzer->analyze($attachment_id, $location, $keyword_hint);

        if (!$analysis['success']) {
            return array(
                'success' => false,
                'error' => $analysis['error'] ?? __('AI analysis failed', 'hozio-image-optimizer'),
            );
        }

        // Merge analysis results into options
        $options['new_title'] = $analysis['title'] ?? '';
        $options['caption'] = $analysis['caption'] ?? '';
        $options['description'] = $analysis['description'] ?? '';

        // Perform rename
        $result = $this->rename($attachment_id, $analysis['filename'], $options);

        // Add AI analysis data to result for reference
        if ($result['success']) {
            $result['ai_analysis'] = array(
                'title' => $options['new_title'],
                'caption' => $options['caption'],
                'description' => $options['description'],
                'alt_text' => $analysis['alt_text'] ?? '',
            );
        }

        return $result;
    }

    /**
     * Batch rename multiple images
     *
     * @param array $renames Array of attachment_id => new_filename
     * @param array $options Options for all renames
     * @return array Results for each image
     */
    public function batch_rename($renames, $options = array()) {
        $results = array();

        foreach ($renames as $attachment_id => $new_filename) {
            try {
                $results[$attachment_id] = $this->rename($attachment_id, $new_filename, $options);
            } catch (Exception $e) {
                $results[$attachment_id] = array(
                    'success' => false,
                    'error' => $e->getMessage(),
                );
            }
        }

        return $results;
    }

    /**
     * Check if a rename would be safe
     */
    public function would_rename_be_safe($attachment_id, $new_filename) {
        $old_path = get_attached_file($attachment_id);

        if (!$old_path) {
            return array(
                'safe' => false,
                'reason' => __('Attachment not found', 'hozio-image-optimizer'),
            );
        }

        // Validate new filename
        $sanitized = Hozio_Image_Optimizer_Helpers::sanitize_seo_filename($new_filename);
        if (empty($sanitized)) {
            return array(
                'safe' => false,
                'reason' => __('Invalid filename', 'hozio-image-optimizer'),
            );
        }

        // Check if filename would change
        $current_filename = pathinfo($old_path, PATHINFO_FILENAME);
        if ($current_filename === $sanitized) {
            return array(
                'safe' => true,
                'would_change' => false,
                'reason' => __('Filename would not change', 'hozio-image-optimizer'),
            );
        }

        // Check references that would need updating
        $reference_count = $this->reference_updater->count_references($attachment_id);

        return array(
            'safe' => true,
            'would_change' => true,
            'current_filename' => $current_filename,
            'new_filename' => $sanitized,
            'references_to_update' => $reference_count,
        );
    }

    /**
     * Restore an image to its original filename
     */
    public function restore($attachment_id) {
        return $this->backup_manager->restore_backup($attachment_id);
    }
}
