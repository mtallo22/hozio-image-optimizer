<?php
/**
 * Elementor Handler - Handles Elementor-specific data updates when images are renamed
 *
 * Elementor stores page builder data in _elementor_data postmeta as JSON-encoded strings.
 * URLs within this JSON use escaped forward slashes (e.g., https:\/\/example.com\/image.jpg).
 * This class ensures those references are properly updated when images are renamed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Elementor_Handler {

    /**
     * Check if Elementor is active
     *
     * @return bool
     */
    public static function is_elementor_active() {
        return defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin');
    }

    /**
     * Update Elementor data when an image URL changes
     *
     * @param string $old_url Old image URL
     * @param string $new_url New image URL
     * @return array Results with count of updates
     */
    public function update_elementor_data($old_url, $new_url) {
        global $wpdb;

        $count = 0;
        $affected_post_ids = array();

        // Prepare both normal and escaped-slash versions
        $old_url_escaped = str_replace('/', '\\/', $old_url);
        $new_url_escaped = str_replace('/', '\\/', $new_url);

        // Find all _elementor_data entries containing the old URL
        // Search for both normal and escaped versions
        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_elementor_data'
            AND (meta_value LIKE %s OR meta_value LIKE %s)",
            '%' . $wpdb->esc_like($old_url_escaped) . '%',
            '%' . $wpdb->esc_like($old_url) . '%'
        ));

        foreach ($meta_rows as $row) {
            $data = json_decode($row->meta_value, true);

            if (!is_array($data)) {
                // Not valid JSON, try plain string replacement as fallback
                $updated = str_replace($old_url, $new_url, $row->meta_value);
                $updated = str_replace($old_url_escaped, $new_url_escaped, $updated);

                if ($updated !== $row->meta_value) {
                    $wpdb->update(
                        $wpdb->postmeta,
                        array('meta_value' => $updated),
                        array('meta_id' => $row->meta_id),
                        array('%s'),
                        array('%d')
                    );
                    $count++;
                    $affected_post_ids[] = $row->post_id;
                }
                continue;
            }

            // Recursively replace URLs in the decoded JSON structure
            $updated_data = $data;
            $this->replace_urls_recursive($updated_data, $old_url, $new_url, $old_url_escaped, $new_url_escaped);

            $updated_json = wp_json_encode($updated_data);

            if ($updated_json !== $row->meta_value) {
                $wpdb->update(
                    $wpdb->postmeta,
                    array('meta_value' => $updated_json),
                    array('meta_id' => $row->meta_id),
                    array('%s'),
                    array('%d')
                );
                $count++;
                $affected_post_ids[] = $row->post_id;
            }
        }

        // Also update _elementor_page_settings if it contains URLs
        $page_settings = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_elementor_page_settings'
            AND (meta_value LIKE %s OR meta_value LIKE %s)",
            '%' . $wpdb->esc_like($old_url) . '%',
            '%' . $wpdb->esc_like($old_url_escaped) . '%'
        ));

        foreach ($page_settings as $row) {
            $value = maybe_unserialize($row->meta_value);

            if (is_array($value)) {
                $this->replace_urls_recursive($value, $old_url, $new_url, $old_url_escaped, $new_url_escaped);
                $wpdb->update(
                    $wpdb->postmeta,
                    array('meta_value' => maybe_serialize($value)),
                    array('meta_id' => $row->meta_id),
                    array('%s'),
                    array('%d')
                );
                $count++;
                $affected_post_ids[] = $row->post_id;
            }
        }

        $affected_post_ids = array_unique($affected_post_ids);

        Hozio_Image_Optimizer_Helpers::log("Elementor: Updated {$count} entries across " . count($affected_post_ids) . " posts");

        return array(
            'count' => $count,
            'post_ids' => $affected_post_ids,
        );
    }

    /**
     * Recursively replace URLs in an array structure
     *
     * @param array  &$data             Data to modify (by reference)
     * @param string $old_url           Normal URL to find
     * @param string $new_url           Normal URL to replace with
     * @param string $old_url_escaped   Escaped-slash URL to find
     * @param string $new_url_escaped   Escaped-slash URL to replace with
     */
    private function replace_urls_recursive(&$data, $old_url, $new_url, $old_url_escaped, $new_url_escaped) {
        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                if (is_string($value)) {
                    $value = str_replace($old_url, $new_url, $value);
                    $value = str_replace($old_url_escaped, $new_url_escaped, $value);
                } elseif (is_array($value)) {
                    $this->replace_urls_recursive($value, $old_url, $new_url, $old_url_escaped, $new_url_escaped);
                }
            }
            unset($value);
        }
    }

    /**
     * Regenerate Elementor CSS for specific posts
     *
     * @param array $post_ids Post IDs to regenerate CSS for
     */
    public function regenerate_elementor_css($post_ids = array()) {
        if (!self::is_elementor_active()) {
            return;
        }

        try {
            // Clear all Elementor CSS cache first
            if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
                Hozio_Image_Optimizer_Helpers::log("Elementor: Cleared global CSS cache");
            }

            // Regenerate CSS for specific posts
            if (!empty($post_ids) && class_exists('\Elementor\Core\Files\CSS\Post')) {
                foreach ($post_ids as $post_id) {
                    $css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
                    $css_file->update();
                }
                Hozio_Image_Optimizer_Helpers::log("Elementor: Regenerated CSS for " . count($post_ids) . " posts");
            }
        } catch (\Exception $e) {
            Hozio_Image_Optimizer_Helpers::log("Elementor CSS regeneration error: " . $e->getMessage());
        }
    }

    /**
     * Clear all Elementor caches
     */
    public function clear_elementor_cache() {
        if (!self::is_elementor_active()) {
            return;
        }

        try {
            // Clear files manager cache
            if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            }

            // Clear Elementor-related transients (using prepared statements)
            global $wpdb;
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_elementor%'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_elementor%'));

            Hozio_Image_Optimizer_Helpers::log("Elementor: Cleared all caches");
        } catch (\Exception $e) {
            Hozio_Image_Optimizer_Helpers::log("Elementor cache clear error: " . $e->getMessage());
        }
    }

    /**
     * Get post IDs that reference a URL in Elementor data
     *
     * @param string $url URL to search for
     * @return array Post IDs
     */
    public function get_affected_elementor_posts($url) {
        global $wpdb;

        $url_escaped = str_replace('/', '\\/', $url);

        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_elementor_data'
            AND (meta_value LIKE %s OR meta_value LIKE %s)",
            '%' . $wpdb->esc_like($url) . '%',
            '%' . $wpdb->esc_like($url_escaped) . '%'
        ));

        return array_map('intval', $post_ids);
    }
}
