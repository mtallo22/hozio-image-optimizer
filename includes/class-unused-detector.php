<?php
/**
 * Unused Image Detector
 *
 * Detects images in the media library that are not referenced anywhere on the site.
 *
 * @package Hozio_Image_Optimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Unused_Detector {

    /**
     * @var wpdb WordPress database instance
     */
    private $wpdb;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Scan all images and return unused ones
     *
     * @param int $page Page number for pagination
     * @param int $per_page Items per page
     * @return array Array with 'images' and 'total' keys
     */
    public function scan_all_images($page = 1, $per_page = 50) {
        // Get all image attachments
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'),
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
        );

        $query = new WP_Query($args);
        $all_image_ids = $query->posts;
        $unused_images = array();

        foreach ($all_image_ids as $attachment_id) {
            if ($this->is_image_unused($attachment_id)) {
                $unused_images[] = $this->get_image_data($attachment_id);
            }
        }

        // Sort by file size descending (biggest savings first)
        usort($unused_images, function($a, $b) {
            return $b['file_size'] - $a['file_size'];
        });

        $total = count($unused_images);
        $offset = ($page - 1) * $per_page;
        $paginated = array_slice($unused_images, $offset, $per_page);

        return array(
            'images' => $paginated,
            'total' => $total,
            'total_size' => array_sum(array_column($unused_images, 'file_size')),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        );
    }

    /**
     * Scan a batch of images, accumulating results across multiple requests.
     *
     * On the first call (batch_offset=0) all reference data is pre-loaded with
     * ~9 bulk queries and cached in transients. Subsequent batches do zero DB
     * queries per image — just fast PHP strpos/isset checks against the cached
     * blobs. This reduces ~10,800 queries (6/image × 1,800 images) to ~9 total,
     * cutting scan time from 10+ minutes to under a minute.
     *
     * @param int $batch_offset Number of images already scanned.
     * @param int $batch_size   Images to scan per request (default 300).
     * @return array Progress/result data.
     */
    public function scan_batch($batch_offset = 0, $batch_size = 300) {
        $user_id   = get_current_user_id();
        $ids_key   = 'hozio_scan_ids_'   . $user_id;
        $acc_key   = 'hozio_scan_acc_'   . $user_id;
        $data_key  = 'hozio_scan_data_'  . $user_id;
        $blobs_key = 'hozio_scan_blobs_' . $user_id;

        if ($batch_offset === 0) {
            $all_ids = (new WP_Query(array(
                'post_type'      => 'attachment',
                'post_mime_type' => array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'),
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            )))->posts;

            set_transient($ids_key, $all_ids, HOUR_IN_SECONDS);
            set_transient($acc_key, array(), HOUR_IN_SECONDS);

            list($ids_cache, $blobs_cache) = $this->preload_scan_data($all_ids);
            set_transient($data_key,  $ids_cache,   HOUR_IN_SECONDS);
            set_transient($blobs_key, $blobs_cache, HOUR_IN_SECONDS);
        } else {
            $all_ids     = get_transient($ids_key);
            $ids_cache   = get_transient($data_key);
            $blobs_cache = get_transient($blobs_key);
            if ($all_ids === false || $ids_cache === false || $blobs_cache === false) {
                return array('error' => __('Scan session expired — please restart the scan.', 'hozio-image-optimizer'));
            }
        }

        $total_ids   = count($all_ids);
        $batch       = array_slice($all_ids, $batch_offset, $batch_size);
        $accumulated = get_transient($acc_key) ?: array();

        foreach ($batch as $attachment_id) {
            if ($this->is_image_unused_fast($attachment_id, $ids_cache, $blobs_cache)) {
                $accumulated[] = $this->get_image_data($attachment_id);
            }
        }
        set_transient($acc_key, $accumulated, HOUR_IN_SECONDS);

        $next_offset = $batch_offset + count($batch);
        $done        = $next_offset >= $total_ids;

        $response = array(
            'done'        => $done,
            'total_ids'   => $total_ids,
            'scanned'     => $next_offset,
            'next_offset' => $next_offset,
        );

        if ($done) {
            usort($accumulated, function($a, $b) {
                return $b['file_size'] - $a['file_size'];
            });
            $response['images']     = $accumulated;
            $response['total']      = count($accumulated);
            $response['total_size'] = array_sum(array_column($accumulated, 'file_size'));

            $this->clear_scan_data($user_id);
        }

        return $response;
    }

    /**
     * Pre-load all image reference data in bulk so per-image checks need zero DB queries.
     *
     * @param array $all_ids All attachment IDs being scanned.
     * @return array Two-element array: [$ids_cache, $blobs_cache]
     */
    private function preload_scan_data(array $all_ids) {
        $upload_dir  = wp_upload_dir();
        $base_url    = trailingslashit($upload_dir['baseurl']);
        $uploads_esc = $this->wpdb->esc_like($upload_dir['baseurl']);

        // 1. URL map: attachment_id => {url, path, file}
        $urls = array();
        if (!empty($all_ids)) {
            $id_list = implode(',', array_map('intval', $all_ids));
            $rows    = $this->wpdb->get_results(
                "SELECT post_id, meta_value FROM {$this->wpdb->postmeta}
                 WHERE meta_key = '_wp_attached_file' AND post_id IN ($id_list)"
            );
            foreach ($rows as $row) {
                $path = ltrim($row->meta_value, '/');
                $urls[(int) $row->post_id] = array(
                    'url'  => $base_url . $path,
                    'path' => $path,
                    'file' => basename($path),
                );
            }
        }

        // 2. Protected IDs hash map
        $protected = array();
        if (!empty($all_ids)) {
            $id_list = implode(',', array_map('intval', $all_ids));
            $rows    = $this->wpdb->get_col(
                "SELECT post_id FROM {$this->wpdb->postmeta}
                 WHERE meta_key = '_hozio_protected' AND meta_value = '1'
                 AND post_id IN ($id_list)"
            );
            $protected = array_fill_keys(array_map('intval', $rows), true);
        }

        // 3. Featured image IDs (_thumbnail_id)
        $featured = array_fill_keys(array_map('intval', $this->wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$this->wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id'"
        )), true);

        // 4. WooCommerce gallery IDs (comma-separated values)
        $woo = array();
        foreach ($this->wpdb->get_col(
            "SELECT meta_value FROM {$this->wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery' AND meta_value != ''"
        ) as $gallery) {
            foreach (array_filter(array_map('trim', explode(',', $gallery))) as $gid) {
                if (is_numeric($gid)) $woo[(int) $gid] = true;
            }
        }

        // 5. Postmeta pure-integer values (ACF image fields storing ID directly)
        $meta_int = array_fill_keys(array_map('intval', $this->wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$this->wpdb->postmeta}
             WHERE meta_key NOT LIKE '\\_%%'
             AND meta_key != '_thumbnail_id'
             AND meta_key != '_product_image_gallery'
             AND meta_value REGEXP '^[0-9]+$'
             AND meta_value != '0'"
        )), true);

        // 6. Termmeta pure-integer values (category image IDs)
        $term_int = array_fill_keys(array_map('intval', $this->wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$this->wpdb->termmeta}
             WHERE meta_value REGEXP '^[0-9]+$' AND meta_value != '0'"
        )), true);

        // Combined O(1) "used by ID" map
        $used_by_id = $featured + $woo + $meta_int + $term_int;

        // 7. Post content blob (only posts referencing uploads — filtered for size)
        $content_rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT post_content FROM {$this->wpdb->posts}
             WHERE post_type NOT IN ('revision', 'attachment', 'nav_menu_item')
             AND post_status NOT IN ('trash', 'auto-draft')
             AND post_content LIKE %s",
            '%' . $uploads_esc . '%'
        ));
        $content_blob = implode(' ', $content_rows);

        // 8. Postmeta URL blob (custom fields referencing uploads by URL)
        $meta_url_rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->postmeta}
             WHERE meta_key NOT LIKE '\\_%%'
             AND meta_key != '_thumbnail_id'
             AND meta_key != '_product_image_gallery'
             AND meta_value LIKE %s",
            '%' . $uploads_esc . '%'
        ));
        $meta_blob = implode(' ', $meta_url_rows);

        // 9. Options blob (widget/theme settings referencing uploads)
        $opts_rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT option_value FROM {$this->wpdb->options}
             WHERE option_name NOT LIKE '\_transient%'
             AND option_name NOT LIKE '\_site\_transient%'
             AND option_value LIKE %s",
            '%' . $uploads_esc . '%'
        ));
        $opts_blob = implode(' ', $opts_rows);

        // 10. Termmeta URL blob (category image URLs)
        $terms_rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->termmeta}
             WHERE meta_value LIKE %s",
            '%' . $uploads_esc . '%'
        ));
        $terms_blob = implode(' ', $terms_rows);

        return array(
            array(
                'urls'       => $urls,
                'protected'  => $protected,
                'used_by_id' => $used_by_id,
            ),
            array(
                'content' => $content_blob,
                'meta'    => $meta_blob,
                'opts'    => $opts_blob,
                'terms'   => $terms_blob,
            ),
        );
    }

    /**
     * Check if an image is unused using only pre-loaded in-memory data.
     * Zero DB queries — all lookups are PHP strpos() or isset().
     *
     * @param int   $attachment_id
     * @param array $ids_cache   Hash maps: urls, protected, used_by_id.
     * @param array $blobs_cache String blobs: content, meta, opts, terms.
     * @return bool True if unused.
     */
    private function is_image_unused_fast($attachment_id, array $ids_cache, array $blobs_cache) {
        if (isset($ids_cache['protected'][$attachment_id])) {
            return false;
        }

        $entry = isset($ids_cache['urls'][$attachment_id]) ? $ids_cache['urls'][$attachment_id] : null;
        if (!$entry) {
            return false; // No _wp_attached_file meta — skip invalid attachment
        }

        $url      = $entry['url'];
        $path     = $entry['path'];
        $filename = $entry['file'];
        $id_json  = '"' . $attachment_id . '"';

        // Featured image / WooCommerce gallery / ACF integer field / term integer field
        if (isset($ids_cache['used_by_id'][$attachment_id])) {
            return false;
        }

        // Post content
        $content = $blobs_cache['content'];
        if (
            strpos($content, $url) !== false ||
            strpos($content, $path) !== false ||
            strpos($content, $filename) !== false
        ) {
            return false;
        }

        // Custom postmeta (URL or JSON-quoted ID)
        $meta = $blobs_cache['meta'];
        if (strpos($meta, $url) !== false || strpos($meta, $id_json) !== false) {
            return false;
        }

        // Options / widgets / customizer
        $opts = $blobs_cache['opts'];
        if (
            strpos($opts, $url) !== false ||
            strpos($opts, $filename) !== false ||
            strpos($opts, $id_json) !== false
        ) {
            return false;
        }

        // Termmeta URL references
        $terms = $blobs_cache['terms'];
        if (strpos($terms, $url) !== false || strpos($terms, $id_json) !== false) {
            return false;
        }

        return true;
    }

    /**
     * Delete all transients created during a batched scan.
     *
     * @param int $user_id
     */
    private function clear_scan_data($user_id) {
        delete_transient('hozio_scan_ids_'   . $user_id);
        delete_transient('hozio_scan_acc_'   . $user_id);
        delete_transient('hozio_scan_data_'  . $user_id);
        delete_transient('hozio_scan_blobs_' . $user_id);
    }

    /**
     * Check if an image is unused (not referenced anywhere)
     *
     * @param int $attachment_id The attachment ID
     * @return bool True if unused, false if used somewhere
     */
    public function is_image_unused($attachment_id) {
        // Skip if protected
        if (get_post_meta($attachment_id, '_hozio_protected', true)) {
            return false;
        }

        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return false; // Invalid attachment
        }

        $relative_path = get_post_meta($attachment_id, '_wp_attached_file', true);

        // Get just the filename for broader matching
        $filename = basename($url);

        // Escape for SQL LIKE queries
        $url_escaped = $this->wpdb->esc_like($url);
        $path_escaped = $this->wpdb->esc_like($relative_path);
        $filename_escaped = $this->wpdb->esc_like($filename);
        $id_pattern = $this->wpdb->esc_like('"' . $attachment_id . '"');

        // 1. Check post_content for URL references (posts, pages, CPTs)
        $content_refs = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->posts}
            WHERE (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s)
            AND post_type NOT IN ('revision', 'attachment')
            AND post_status != 'trash'",
            '%' . $url_escaped . '%',
            '%' . $path_escaped . '%',
            '%' . $filename_escaped . '%'
        ));
        if ($content_refs > 0) {
            return false;
        }

        // 2. Check featured images (_thumbnail_id)
        $featured_refs = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->postmeta}
            WHERE meta_key = '_thumbnail_id'
            AND meta_value = %s",
            $attachment_id
        ));
        if ($featured_refs > 0) {
            return false;
        }

        // 3. Check WooCommerce product gallery
        $woo_gallery_refs = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->postmeta}
            WHERE meta_key = '_product_image_gallery'
            AND (meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)",
            $attachment_id,
            $attachment_id . ',%',
            '%,' . $attachment_id . ',%',
            '%,' . $attachment_id
        ));
        if ($woo_gallery_refs > 0) {
            return false;
        }

        // 4. Check postmeta for ID references (ACF, page builders, etc.)
        // Look for the ID in various formats: "123", 123, i:123, s:3:"123"
        $meta_refs = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->postmeta}
            WHERE meta_key NOT LIKE '\\_%%'
            AND meta_key != '_thumbnail_id'
            AND meta_key != '_product_image_gallery'
            AND (
                meta_value = %s
                OR meta_value LIKE %s
                OR meta_value LIKE %s
            )",
            $attachment_id,
            '%' . $id_pattern . '%',
            '%' . $url_escaped . '%'
        ));
        if ($meta_refs > 0) {
            return false;
        }

        // 5. Check options (widgets, theme settings, customizer)
        $option_refs = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->options}
            WHERE option_name NOT LIKE '\\_transient%%'
            AND option_name NOT LIKE '\\_site_transient%%'
            AND (
                option_value LIKE %s
                OR option_value LIKE %s
                OR option_value LIKE %s
            )",
            '%' . $url_escaped . '%',
            '%' . $id_pattern . '%',
            '%' . $filename_escaped . '%'
        ));
        if ($option_refs > 0) {
            return false;
        }

        // 6. Check term meta (category images, etc.)
        $term_refs = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->termmeta}
            WHERE meta_value = %s
            OR meta_value LIKE %s
            OR meta_value LIKE %s",
            $attachment_id,
            '%' . $url_escaped . '%',
            '%' . $id_pattern . '%'
        ));
        if ($term_refs > 0) {
            return false;
        }

        // No references found - image is unused
        return true;
    }

    /**
     * Get detailed data for an image
     *
     * @param int $attachment_id The attachment ID
     * @return array Image data
     */
    public function get_image_data($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        $file_size = file_exists($file_path) ? filesize($file_path) : 0;
        $attachment = get_post($attachment_id);

        // Calculate total size including thumbnails
        $total_size = $file_size;
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes'])) {
            $upload_dir = wp_upload_dir();
            $base_dir = dirname($file_path);
            foreach ($metadata['sizes'] as $size => $size_info) {
                $thumb_path = $base_dir . '/' . $size_info['file'];
                if (file_exists($thumb_path)) {
                    $total_size += filesize($thumb_path);
                }
            }
        }

        return array(
            'id' => $attachment_id,
            'title' => $attachment ? $attachment->post_title : '',
            'filename' => basename($file_path),
            'file_path' => $file_path,
            'file_size' => $file_size,
            'total_size' => $total_size,
            'file_size_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($file_size),
            'total_size_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($total_size),
            'thumbnail' => wp_get_attachment_image_url($attachment_id, 'medium'),
            'mime_type' => $attachment ? $attachment->post_mime_type : '',
            'date' => $attachment ? $attachment->post_date : '',
            'is_protected' => (bool) get_post_meta($attachment_id, '_hozio_protected', true),
        );
    }

    /**
     * Find where an image IS used (for reference display)
     *
     * @param int $attachment_id The attachment ID
     * @return array Array of references
     */
    public function get_image_references($attachment_id) {
        $references = array();
        $url = wp_get_attachment_url($attachment_id);
        $relative_path = get_post_meta($attachment_id, '_wp_attached_file', true);
        $filename = basename($url);

        $url_escaped = $this->wpdb->esc_like($url);
        $path_escaped = $this->wpdb->esc_like($relative_path);
        $filename_escaped = $this->wpdb->esc_like($filename);

        // Check post content
        $posts = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT ID, post_title, post_type FROM {$this->wpdb->posts}
            WHERE (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s)
            AND post_type NOT IN ('revision', 'attachment')
            AND post_status != 'trash'
            LIMIT 20",
            '%' . $url_escaped . '%',
            '%' . $path_escaped . '%',
            '%' . $filename_escaped . '%'
        ));
        foreach ($posts as $post) {
            $references[] = array(
                'type' => 'content',
                'location' => ucfirst($post->post_type) . ': ' . $post->post_title,
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
            );
        }

        // Check featured images
        $featured = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type
            FROM {$this->wpdb->postmeta} pm
            JOIN {$this->wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_thumbnail_id'
            AND pm.meta_value = %s
            LIMIT 20",
            $attachment_id
        ));
        foreach ($featured as $post) {
            $references[] = array(
                'type' => 'featured',
                'location' => 'Featured Image: ' . $post->post_title,
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
            );
        }

        // Check WooCommerce galleries
        $galleries = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT p.ID, p.post_title
            FROM {$this->wpdb->postmeta} pm
            JOIN {$this->wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_product_image_gallery'
            AND (pm.meta_value = %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s)
            LIMIT 20",
            $attachment_id,
            $attachment_id . ',%',
            '%,' . $attachment_id . ',%',
            '%,' . $attachment_id
        ));
        foreach ($galleries as $post) {
            $references[] = array(
                'type' => 'gallery',
                'location' => 'Product Gallery: ' . $post->post_title,
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
            );
        }

        // Check widgets/options
        $options = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT option_name FROM {$this->wpdb->options}
            WHERE option_name NOT LIKE '\\_transient%%'
            AND (option_value LIKE %s OR option_value LIKE %s)
            LIMIT 10",
            '%' . $url_escaped . '%',
            '%' . $filename_escaped . '%'
        ));
        foreach ($options as $option) {
            $references[] = array(
                'type' => 'option',
                'location' => 'Site Option: ' . $option->option_name,
                'edit_url' => admin_url('options.php'),
            );
        }

        return $references;
    }

    /**
     * Protect an image from cleanup
     *
     * @param int $attachment_id The attachment ID
     * @return bool Success
     */
    public function protect_image($attachment_id) {
        return update_post_meta($attachment_id, '_hozio_protected', true);
    }

    /**
     * Unprotect an image
     *
     * @param int $attachment_id The attachment ID
     * @return bool Success
     */
    public function unprotect_image($attachment_id) {
        return delete_post_meta($attachment_id, '_hozio_protected');
    }

    /**
     * Toggle protection status
     *
     * @param int $attachment_id The attachment ID
     * @return bool New protection status
     */
    public function toggle_protection($attachment_id) {
        $is_protected = get_post_meta($attachment_id, '_hozio_protected', true);
        if ($is_protected) {
            $this->unprotect_image($attachment_id);
            return false;
        } else {
            $this->protect_image($attachment_id);
            return true;
        }
    }

    /**
     * Get count of protected images
     *
     * @return int Count of protected images
     */
    public function get_protected_count() {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->postmeta}
            WHERE meta_key = '_hozio_protected'
            AND meta_value = '1'"
        );
    }

    /**
     * Get statistics for the cleanup page
     *
     * @return array Statistics
     */
    public function get_stats() {
        // Total images in library
        $total_images = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image/%'"
        );

        return array(
            'total_images' => (int) $total_images,
            'protected_count' => $this->get_protected_count(),
            'last_scan' => get_option('hozio_last_unused_scan', ''),
        );
    }

    /**
     * Save scan timestamp
     */
    public function save_scan_time() {
        update_option('hozio_last_unused_scan', current_time('mysql'));
    }

    /**
     * Save scan results to database for persistence across page loads
     *
     * @param array $results Scan results with images and metadata
     */
    public function save_scan_results($results) {
        update_option('hozio_unused_scan_results', $results, false);
        $this->save_scan_time();
    }

    /**
     * Get previously saved scan results
     *
     * @return array|null Saved results or null if no previous scan
     */
    public function get_saved_results() {
        return get_option('hozio_unused_scan_results', null);
    }

    /**
     * Clear saved scan results
     */
    public function clear_saved_results() {
        delete_option('hozio_unused_scan_results');
    }
}
