<?php
/**
 * Broken Image Detector
 *
 * Detects broken images across the entire WordPress site:
 * 1. WordPress attachments whose physical files are missing
 * 2. Hardcoded <img> URLs in post content pointing to missing files
 * 3. Images in Elementor data, postmeta, and options
 *
 * Provides resolution strategies: restore from backup, update references,
 * or seamless image replacement via the media library.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Broken_Detector {

    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Run a full scan for broken images
     *
     * @return array Scan results with broken_attachments and broken_content_urls
     */
    public function scan() {
        $results = array(
            'broken_attachments' => array(),
            'broken_content_urls' => array(),
            'total_broken' => 0,
            'total_locations' => 0,
            'scan_time' => current_time('mysql'),
        );

        // Phase 1: Check all image attachments for missing files
        $attachment_results = $this->scan_attachments();
        $results['broken_attachments'] = $attachment_results;

        // Phase 2: Scan post content for broken image URLs
        $content_results = $this->scan_content_urls();
        $results['broken_content_urls'] = $content_results;

        $results['total_broken'] = count($results['broken_attachments']) + count($results['broken_content_urls']);

        $total_locations = 0;
        foreach ($results['broken_attachments'] as $item) {
            $total_locations += count($item['locations']);
        }
        foreach ($results['broken_content_urls'] as $item) {
            $total_locations += count($item['locations']);
        }
        $results['total_locations'] = $total_locations;

        return $results;
    }

    /**
     * Scan all image attachments for missing physical files
     *
     * @return array Broken attachments with location data
     */
    private function scan_attachments() {
        $broken = array();

        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'),
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
        );

        $query = new WP_Query($args);

        foreach ($query->posts as $attachment_id) {
            $file_path = get_attached_file($attachment_id);

            if (!$file_path || !file_exists($file_path)) {
                $url = wp_get_attachment_url($attachment_id);
                $locations = $this->find_image_locations($attachment_id, $url);
                $resolution_options = $this->get_resolution_options($attachment_id, $url);

                $broken[] = array(
                    'type' => 'attachment',
                    'attachment_id' => $attachment_id,
                    'url' => $url ?: '',
                    'expected_path' => $file_path ?: '',
                    'filename' => $file_path ? basename($file_path) : 'Unknown',
                    'title' => get_the_title($attachment_id),
                    'locations' => $locations,
                    'location_count' => count($locations),
                    'resolution_options' => $resolution_options,
                    'has_backup' => $this->has_backup($attachment_id),
                );
            }
        }

        return $broken;
    }

    /**
     * Scan all post content for broken image URLs
     * Finds <img src="..."> and background-image: url(...) that point to local missing files
     *
     * @return array Broken content URLs with location data
     */
    private function scan_content_urls() {
        $broken = array();
        $uploads = wp_upload_dir();
        $uploads_url = $uploads['baseurl'];
        $uploads_dir = $uploads['basedir'];

        // Find all posts with image references to our uploads directory
        $posts = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT ID, post_title, post_type, post_content FROM {$this->wpdb->posts}
            WHERE post_content LIKE %s
            AND post_type NOT IN ('revision', 'attachment', 'nav_menu_item')
            AND post_status IN ('publish', 'draft', 'private', 'pending')",
            '%' . $this->wpdb->esc_like($uploads_url) . '%'
        ));

        $checked_urls = array(); // Avoid duplicate checks
        $broken_map = array();   // URL -> broken data

        foreach ($posts as $post) {
            // Extract image URLs from content
            $urls = $this->extract_image_urls($post->post_content, $uploads_url);

            foreach ($urls as $url) {
                // Skip if already checked
                if (isset($checked_urls[$url])) {
                    // If it was broken, add this post as another location
                    if (isset($broken_map[$url])) {
                        $broken_map[$url]['locations'][] = array(
                            'post_id' => $post->ID,
                            'post_title' => $post->post_title,
                            'post_type' => $post->post_type,
                            'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
                            'view_url' => get_permalink($post->ID),
                        );
                    }
                    continue;
                }

                $checked_urls[$url] = true;

                // Convert URL to file path
                $file_path = str_replace($uploads_url, $uploads_dir, $url);

                // Check if file exists
                if (!file_exists($file_path)) {
                    // Check if this is a known attachment
                    $attachment_id = attachment_url_to_postid($url);

                    // Skip if already captured as a broken attachment
                    if ($attachment_id > 0) {
                        continue;
                    }

                    $broken_map[$url] = array(
                        'type' => 'content_url',
                        'attachment_id' => 0,
                        'url' => $url,
                        'expected_path' => $file_path,
                        'filename' => basename($url),
                        'title' => basename($url),
                        'locations' => array(
                            array(
                                'post_id' => $post->ID,
                                'post_title' => $post->post_title,
                                'post_type' => $post->post_type,
                                'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
                                'view_url' => get_permalink($post->ID),
                            ),
                        ),
                        'location_count' => 0, // Updated below
                        'resolution_options' => array('replace_image', 'remove_references'),
                        'has_backup' => false,
                    );
                }
            }
        }

        // Finalize location counts
        foreach ($broken_map as $url => &$data) {
            $data['location_count'] = count($data['locations']);
        }
        unset($data);

        return array_values($broken_map);
    }

    /**
     * Extract image URLs from HTML content
     *
     * @param string $content HTML content
     * @param string $uploads_url Base uploads URL to filter for local images
     * @return array Unique image URLs found
     */
    private function extract_image_urls($content, $uploads_url) {
        $urls = array();

        // Match <img src="...">
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, $uploads_url) !== false) {
                    $urls[] = $url;
                }
            }
        }

        // Match background-image: url(...)
        if (preg_match_all('/background-image\s*:\s*url\(["\']?([^"\')\s]+)["\']?\)/', $content, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, $uploads_url) !== false) {
                    $urls[] = $url;
                }
            }
        }

        // Match Gutenberg image block URLs
        if (preg_match_all('/"url"\s*:\s*"([^"]+)"/', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $url = wp_unslash($url);
                if (strpos($url, $uploads_url) !== false) {
                    $urls[] = $url;
                }
            }
        }

        return array_unique($urls);
    }

    /**
     * Find all locations where an image is referenced
     *
     * @param int    $attachment_id
     * @param string $url
     * @return array Locations with post data and edit links
     */
    private function find_image_locations($attachment_id, $url) {
        $locations = array();

        if (!$url) {
            return $locations;
        }

        // Check post_content
        $posts = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT ID, post_title, post_type FROM {$this->wpdb->posts}
            WHERE post_content LIKE %s
            AND post_type NOT IN ('revision', 'attachment')
            AND post_status IN ('publish', 'draft', 'private', 'pending')",
            '%' . $this->wpdb->esc_like($url) . '%'
        ));

        foreach ($posts as $post) {
            $locations[] = array(
                'post_id' => $post->ID,
                'post_title' => $post->post_title,
                'post_type' => $post->post_type,
                'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
                'view_url' => get_permalink($post->ID),
                'source' => 'content',
            );
        }

        // Check as featured image
        $featured_posts = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT pm.post_id, p.post_title, p.post_type
            FROM {$this->wpdb->postmeta} pm
            JOIN {$this->wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_thumbnail_id'
            AND pm.meta_value = %s",
            $attachment_id
        ));

        foreach ($featured_posts as $post) {
            $locations[] = array(
                'post_id' => $post->post_id,
                'post_title' => $post->post_title,
                'post_type' => $post->post_type,
                'edit_url' => admin_url('post.php?post=' . $post->post_id . '&action=edit'),
                'view_url' => get_permalink($post->post_id),
                'source' => 'featured_image',
            );
        }

        // Check Elementor data
        if (Hozio_Image_Optimizer_Elementor_Handler::is_elementor_active()) {
            $url_escaped = str_replace('/', '\\/', $url);
            $elementor_posts = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT pm.post_id, p.post_title, p.post_type
                FROM {$this->wpdb->postmeta} pm
                JOIN {$this->wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key = '_elementor_data'
                AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)",
                '%' . $this->wpdb->esc_like($url) . '%',
                '%' . $this->wpdb->esc_like($url_escaped) . '%'
            ));

            foreach ($elementor_posts as $post) {
                // Avoid duplicates
                $already_added = false;
                foreach ($locations as $loc) {
                    if ($loc['post_id'] == $post->post_id) {
                        $already_added = true;
                        break;
                    }
                }
                if (!$already_added) {
                    $locations[] = array(
                        'post_id' => $post->post_id,
                        'post_title' => $post->post_title,
                        'post_type' => $post->post_type,
                        'edit_url' => admin_url('post.php?post=' . $post->post_id . '&action=edit'),
                        'view_url' => get_permalink($post->post_id),
                        'source' => 'elementor',
                    );
                }
            }
        }

        return $locations;
    }

    /**
     * Get available resolution options for a broken image
     *
     * @param int    $attachment_id
     * @param string $url
     * @return array Available resolution strategies
     */
    public function get_resolution_options($attachment_id, $url) {
        $options = array();

        // Check if backup exists
        if ($attachment_id > 0 && $this->has_backup($attachment_id)) {
            $options[] = 'restore_backup';
        }

        // Check if redirect mapping exists (image was renamed)
        $redirects = get_option('hozio_image_redirects', array());
        $url_path = wp_parse_url($url, PHP_URL_PATH);
        if ($url_path && isset($redirects[$url_path])) {
            $options[] = 'update_references';
        }

        // Always offer replace and remove
        $options[] = 'replace_image';
        $options[] = 'remove_references';

        return $options;
    }

    /**
     * Check if an attachment has a backup
     *
     * @param int $attachment_id
     * @return bool
     */
    private function has_backup($attachment_id) {
        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        return $backup_manager->has_backup($attachment_id);
    }

    /**
     * Resolve a broken image using the specified strategy
     *
     * @param int    $attachment_id
     * @param string $broken_url    The broken URL
     * @param string $strategy      Resolution strategy
     * @param array  $options       Additional options (e.g., new_url for replace)
     * @return array Result
     */
    public function resolve($attachment_id, $broken_url, $strategy, $options = array()) {
        switch ($strategy) {
            case 'restore_backup':
                return $this->resolve_restore_backup($attachment_id);

            case 'update_references':
                return $this->resolve_update_references($broken_url);

            case 'replace_image':
                return $this->resolve_replace_image($broken_url, $options['new_url'] ?? '', $attachment_id);

            case 'remove_references':
                return $this->resolve_remove_references($broken_url);

            default:
                return array('success' => false, 'error' => __('Unknown resolution strategy', 'hozio-image-optimizer'));
        }
    }

    /**
     * Restore from backup
     */
    private function resolve_restore_backup($attachment_id) {
        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        $result = $backup_manager->restore_backup($attachment_id);

        if ($result) {
            return array('success' => true, 'message' => __('Image restored from backup', 'hozio-image-optimizer'));
        }
        return array('success' => false, 'error' => __('Failed to restore from backup', 'hozio-image-optimizer'));
    }

    /**
     * Update references using redirect mapping (image was renamed)
     */
    private function resolve_update_references($broken_url) {
        $redirects = get_option('hozio_image_redirects', array());
        $url_path = wp_parse_url($broken_url, PHP_URL_PATH);

        if (!$url_path || !isset($redirects[$url_path])) {
            return array('success' => false, 'error' => __('No redirect mapping found', 'hozio-image-optimizer'));
        }

        $new_url = $redirects[$url_path]['new_url'];
        $reference_updater = new Hozio_Image_Optimizer_Reference_Updater();
        $result = $reference_updater->update_references($broken_url, $new_url, 0);

        return array(
            'success' => true,
            'message' => sprintf(__('Updated %d references to new URL', 'hozio-image-optimizer'),
                $result['posts_updated'] + $result['meta_updated'] + $result['options_updated']),
            'new_url' => $new_url,
        );
    }

    /**
     * Replace broken image URL with a new image URL throughout the database
     */
    private function resolve_replace_image($broken_url, $new_url, $attachment_id = 0) {
        if (empty($new_url)) {
            return array('success' => false, 'error' => __('New image URL is required', 'hozio-image-optimizer'));
        }

        $reference_updater = new Hozio_Image_Optimizer_Reference_Updater();
        $result = $reference_updater->update_references($broken_url, $new_url, $attachment_id);

        $total_updated = $result['posts_updated'] + $result['meta_updated'] + $result['options_updated'];

        return array(
            'success' => true,
            'message' => sprintf(__('Replaced broken image in %d locations', 'hozio-image-optimizer'), $total_updated),
            'updates' => $total_updated,
        );
    }

    /**
     * Remove all references to the broken image URL from content
     */
    private function resolve_remove_references($broken_url) {
        global $wpdb;

        $count = 0;

        // Remove <img> tags containing this URL from post_content
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts}
            WHERE post_content LIKE %s
            AND post_type NOT IN ('revision', 'attachment')",
            '%' . $wpdb->esc_like($broken_url) . '%'
        ));

        foreach ($posts as $post) {
            $new_content = $post->post_content;

            // Remove <img> tags with this URL
            $new_content = preg_replace(
                '/<img[^>]*src=["\']' . preg_quote($broken_url, '/') . '["\'][^>]*\/?>/i',
                '',
                $new_content
            );

            if ($new_content !== $post->post_content) {
                $wpdb->update(
                    $wpdb->posts,
                    array('post_content' => $new_content),
                    array('ID' => $post->ID),
                    array('%s'),
                    array('%d')
                );
                clean_post_cache($post->ID);
                $count++;
            }
        }

        return array(
            'success' => true,
            'message' => sprintf(__('Removed broken image references from %d posts', 'hozio-image-optimizer'), $count),
        );
    }

    /**
     * Save scan results for persistence
     *
     * @param array $results
     */
    public function save_scan_results($results) {
        update_option('hozio_broken_scan_results', $results, false);
    }

    /**
     * Get saved scan results
     *
     * @return array|null
     */
    public function get_saved_results() {
        return get_option('hozio_broken_scan_results', null);
    }

    /**
     * Clear saved scan results
     */
    public function clear_saved_results() {
        delete_option('hozio_broken_scan_results');
    }

    /**
     * Schedule the daily broken image scan
     */
    public static function schedule_daily_scan() {
        if (!wp_next_scheduled('hozio_daily_broken_scan')) {
            wp_schedule_event(time(), 'daily', 'hozio_daily_broken_scan');
        }
    }

    /**
     * Unschedule the daily scan
     */
    public static function unschedule_daily_scan() {
        wp_clear_scheduled_hook('hozio_daily_broken_scan');
    }

    /**
     * Run the daily scheduled scan (lightweight - attachments only)
     * Batched: processes 500 attachments at a time to avoid timeouts
     */
    public static function run_scheduled_scan() {
        $broken_count = 0;
        $batch_size = 500;
        $offset = 0;
        $start_time = microtime(true);
        $max_time = 25; // seconds, leave headroom before PHP timeout

        do {
            $args = array(
                'post_type' => 'attachment',
                'post_mime_type' => array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'),
                'post_status' => 'inherit',
                'posts_per_page' => $batch_size,
                'offset' => $offset,
                'fields' => 'ids',
                'no_found_rows' => true,
            );

            $ids = get_posts($args);
            if (empty($ids)) break;

            foreach ($ids as $attachment_id) {
                $file_path = get_attached_file($attachment_id);
                if (!$file_path || !file_exists($file_path)) {
                    $broken_count++;
                }
            }

            $offset += $batch_size;

            // Safety: bail if approaching timeout
            if ((microtime(true) - $start_time) > $max_time) break;

        } while (count($ids) === $batch_size);

        update_option('hozio_daily_broken_count', $broken_count, false);
        update_option('hozio_daily_broken_scan_time', current_time('mysql'), false);

        Hozio_Image_Optimizer_Helpers::log("Daily broken scan: found {$broken_count} broken images (scanned {$offset} attachments)");
    }

    /**
     * Get the daily scan broken count
     *
     * @return int
     */
    public static function get_daily_broken_count() {
        return intval(get_option('hozio_daily_broken_count', 0));
    }
}
