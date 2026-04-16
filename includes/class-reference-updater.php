<?php
/**
 * Reference Updater - Updates image URLs in database when files are renamed
 *
 * This is critical for preventing broken images after renaming.
 * It scans and updates:
 * - post_content (Gutenberg blocks, classic editor)
 * - post_excerpt
 * - postmeta (custom fields, ACF, etc.)
 * - WordPress widgets
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Reference_Updater {

    /**
     * Update all references when an image URL changes
     *
     * @param string $old_url Old image URL
     * @param string $new_url New image URL
     * @param int $attachment_id Attachment ID
     * @return array Results of update operation
     */
    public function update_references($old_url, $new_url, $attachment_id) {
        global $wpdb;

        $results = array(
            'posts_updated' => 0,
            'meta_updated' => 0,
            'options_updated' => 0,
            'details' => array(),
        );

        // Skip if URLs are the same
        if ($old_url === $new_url) {
            return $results;
        }

        // Get the old and new filenames (without full URL for partial matching)
        $old_filename = basename($old_url);
        $new_filename = basename($new_url);

        // Get URL path without domain for more flexible matching
        $uploads = wp_upload_dir();
        $old_path = str_replace($uploads['baseurl'], '', $old_url);
        $new_path = str_replace($uploads['baseurl'], '', $new_url);

        Hozio_Image_Optimizer_Helpers::log("Updating references: {$old_url} -> {$new_url}");

        // 1. Update post_content
        $posts_result = $this->update_post_content($old_url, $new_url, $old_path, $new_path);
        $results['posts_updated'] = $posts_result['count'];
        $results['details']['posts'] = $posts_result['posts'];

        // 2. Update postmeta
        $meta_result = $this->update_postmeta($old_url, $new_url, $old_path, $new_path);
        $results['meta_updated'] = $meta_result['count'];

        // 3. Update options (widgets, customizer, etc.)
        $options_result = $this->update_options($old_url, $new_url);
        $results['options_updated'] = $options_result['count'];

        // 4. Update attachment metadata (for thumbnails)
        $this->update_attachment_metadata($attachment_id, $old_filename, $new_filename);

        // 5. Update GUID
        $this->update_attachment_guid($attachment_id, $new_url);

        // 6. Update Elementor data (JSON-encoded with escaped slashes)
        $results['elementor_updated'] = 0;
        if (Hozio_Image_Optimizer_Elementor_Handler::is_elementor_active()) {
            $elementor_handler = new Hozio_Image_Optimizer_Elementor_Handler();
            $elementor_result = $elementor_handler->update_elementor_data($old_url, $new_url);
            $results['elementor_updated'] = $elementor_result['count'];

            // Regenerate Elementor CSS for affected posts
            if (!empty($elementor_result['post_ids'])) {
                $elementor_handler->regenerate_elementor_css($elementor_result['post_ids']);
            }

            // Clear Elementor cache
            $elementor_handler->clear_elementor_cache();
        }

        // 7. Clear all caches (page cache, CDN, object cache)
        if (get_option('hozio_clear_caches_on_rename', true)) {
            $cache_purger = new Hozio_Image_Optimizer_Cache_Purger();
            $cache_results = $cache_purger->purge_all_caches();
            $results['caches_purged'] = $cache_results;
        }

        // 8. Add 301 redirect for old URL (safety net, opt-in)
        if (get_option('hozio_enable_redirects', false)) {
            $redirect_manager = new Hozio_Image_Optimizer_Redirect_Manager();
            $redirect_manager->add_redirect($old_url, $new_url);
        }

        $total = $results['posts_updated'] + $results['meta_updated'] + $results['options_updated'] + $results['elementor_updated'];
        Hozio_Image_Optimizer_Helpers::log("Reference update complete: {$total} references updated");

        return $results;
    }

    /**
     * Update post_content with new URLs
     */
    private function update_post_content($old_url, $new_url, $old_path, $new_path) {
        global $wpdb;

        $updated_posts = array();

        // Find all posts containing the old URL
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts}
            WHERE post_content LIKE %s
            AND post_type NOT IN ('revision', 'attachment')",
            '%' . $wpdb->esc_like($old_url) . '%'
        ));

        // Also search for relative paths
        if ($old_path !== $old_url) {
            $posts_by_path = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                WHERE post_content LIKE %s
                AND post_type NOT IN ('revision', 'attachment')
                AND ID NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s)",
                '%' . $wpdb->esc_like($old_path) . '%',
                '%' . $wpdb->esc_like($old_url) . '%'
            ));
            $posts = array_merge($posts, $posts_by_path);
        }

        foreach ($posts as $post) {
            $new_content = $post->post_content;

            // Replace full URL
            $new_content = str_replace($old_url, $new_url, $new_content);

            // Replace path (for relative URLs)
            if ($old_path !== $old_url) {
                $new_content = str_replace($old_path, $new_path, $new_content);
            }

            // Only update if content changed
            if ($new_content !== $post->post_content) {
                $wpdb->update(
                    $wpdb->posts,
                    array('post_content' => $new_content),
                    array('ID' => $post->ID),
                    array('%s'),
                    array('%d')
                );

                $updated_posts[] = array(
                    'ID' => $post->ID,
                    'title' => get_the_title($post->ID),
                );

                // Clear post cache
                clean_post_cache($post->ID);
            }
        }

        // Also update post_excerpt
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts}
            SET post_excerpt = REPLACE(post_excerpt, %s, %s)
            WHERE post_excerpt LIKE %s",
            $old_url, $new_url, '%' . $wpdb->esc_like($old_url) . '%'
        ));

        return array(
            'count' => count($updated_posts),
            'posts' => $updated_posts,
        );
    }

    /**
     * Update postmeta with new URLs
     */
    private function update_postmeta($old_url, $new_url, $old_path, $new_path) {
        global $wpdb;

        $count = 0;

        // Update meta values containing the old URL
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta}
            SET meta_value = REPLACE(meta_value, %s, %s)
            WHERE meta_value LIKE %s",
            $old_url, $new_url, '%' . $wpdb->esc_like($old_url) . '%'
        ));

        $count += $affected;

        // Also handle JSON-escaped URLs (used by Elementor, Beaver Builder, some page builders)
        // These store URLs with escaped slashes: https:\/\/example.com\/path\/image.jpg
        $old_url_escaped = str_replace('/', '\\/', $old_url);
        $new_url_escaped = str_replace('/', '\\/', $new_url);

        if ($old_url_escaped !== $old_url) {
            $affected_escaped = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta}
                SET meta_value = REPLACE(meta_value, %s, %s)
                WHERE meta_value LIKE %s",
                $old_url_escaped, $new_url_escaped, '%' . $wpdb->esc_like($old_url_escaped) . '%'
            ));
            $count += $affected_escaped;
        }

        // Also handle JSON-escaped paths
        if ($old_path !== $old_url) {
            $old_path_escaped = str_replace('/', '\\/', $old_path);
            $new_path_escaped = str_replace('/', '\\/', $new_path);

            if ($old_path_escaped !== $old_path) {
                $affected_path_escaped = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->postmeta}
                    SET meta_value = REPLACE(meta_value, %s, %s)
                    WHERE meta_value LIKE %s",
                    $old_path_escaped, $new_path_escaped, '%' . $wpdb->esc_like($old_path_escaped) . '%'
                ));
                $count += $affected_path_escaped;
            }
        }

        // Also update serialized data (more complex)
        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_value LIKE %s",
            '%' . $wpdb->esc_like(serialize($old_url)) . '%'
        ));

        foreach ($meta_rows as $row) {
            $meta_value = maybe_unserialize($row->meta_value);
            if (is_array($meta_value) || is_object($meta_value)) {
                $updated_value = $this->replace_in_array($meta_value, $old_url, $new_url);
                if ($updated_value !== $meta_value) {
                    $wpdb->update(
                        $wpdb->postmeta,
                        array('meta_value' => maybe_serialize($updated_value)),
                        array('meta_id' => $row->meta_id),
                        array('%s'),
                        array('%d')
                    );
                    $count++;
                }
            }
        }

        return array('count' => $count);
    }

    /**
     * Recursively replace values in array/object
     */
    private function replace_in_array($data, $old_value, $new_value) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $data[$key] = str_replace($old_value, $new_value, $value);
                } elseif (is_array($value) || is_object($value)) {
                    $data[$key] = $this->replace_in_array($value, $old_value, $new_value);
                }
            }
        } elseif (is_object($data)) {
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $data->$key = str_replace($old_value, $new_value, $value);
                } elseif (is_array($value) || is_object($value)) {
                    $data->$key = $this->replace_in_array($value, $old_value, $new_value);
                }
            }
        }

        return $data;
    }

    /**
     * Update options table (widgets, customizer, etc.)
     */
    private function update_options($old_url, $new_url) {
        global $wpdb;

        $count = 0;

        // Get all options that might contain URLs
        $options = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options}
            WHERE option_value LIKE %s
            AND option_name NOT LIKE %s",
            '%' . $wpdb->esc_like($old_url) . '%',
            '_transient%'
        ));

        foreach ($options as $option) {
            $value = maybe_unserialize($option->option_value);

            if (is_string($value)) {
                $new_value = str_replace($old_url, $new_url, $value);
            } elseif (is_array($value) || is_object($value)) {
                $new_value = $this->replace_in_array($value, $old_url, $new_url);
            } else {
                continue;
            }

            if ($new_value !== $value) {
                update_option($option->option_name, $new_value);
                $count++;
            }
        }

        return array('count' => $count);
    }

    /**
     * Update attachment metadata (thumbnail URLs)
     */
    private function update_attachment_metadata($attachment_id, $old_filename, $new_filename) {
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (!$metadata) {
            return;
        }

        // Update the file path
        if (isset($metadata['file'])) {
            $metadata['file'] = str_replace(
                pathinfo($metadata['file'], PATHINFO_FILENAME),
                pathinfo($new_filename, PATHINFO_FILENAME),
                $metadata['file']
            );
        }

        // Update thumbnail sizes
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $data) {
                if (isset($data['file'])) {
                    // Thumbnail filenames include dimensions, so we need to handle them
                    $old_base = pathinfo($old_filename, PATHINFO_FILENAME);
                    $new_base = pathinfo($new_filename, PATHINFO_FILENAME);

                    $metadata['sizes'][$size]['file'] = preg_replace(
                        '/^' . preg_quote($old_base, '/') . '/',
                        $new_base,
                        $data['file']
                    );
                }
            }
        }

        wp_update_attachment_metadata($attachment_id, $metadata);
    }

    /**
     * Update attachment GUID
     */
    private function update_attachment_guid($attachment_id, $new_url) {
        global $wpdb;

        $wpdb->update(
            $wpdb->posts,
            array('guid' => $new_url),
            array('ID' => $attachment_id),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Find all references to an image in the database
     */
    public function find_references($attachment_id) {
        global $wpdb;

        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return array();
        }

        $references = array(
            'posts' => array(),
            'meta' => array(),
            'options' => array(),
        );

        // Find in post_content
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_type FROM {$wpdb->posts}
            WHERE post_content LIKE %s
            AND post_type NOT IN ('revision', 'attachment')",
            '%' . $wpdb->esc_like($url) . '%'
        ));

        foreach ($posts as $post) {
            $references['posts'][] = array(
                'ID' => $post->ID,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'edit_link' => get_edit_post_link($post->ID),
            );
        }

        // Find in postmeta
        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, pm.meta_key, p.post_title
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_value LIKE %s",
            '%' . $wpdb->esc_like($url) . '%'
        ));

        foreach ($meta as $row) {
            $references['meta'][] = array(
                'post_id' => $row->post_id,
                'post_title' => $row->post_title,
                'meta_key' => $row->meta_key,
            );
        }

        // Find in options
        $options = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
            WHERE option_value LIKE %s
            AND option_name NOT LIKE %s",
            '%' . $wpdb->esc_like($url) . '%',
            '_transient%'
        ));

        $references['options'] = $options;

        return $references;
    }

    /**
     * Get count of references for an attachment
     */
    public function count_references($attachment_id) {
        $references = $this->find_references($attachment_id);

        return count($references['posts']) +
            count($references['meta']) +
            count($references['options']);
    }
}
