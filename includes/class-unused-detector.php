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
        // Stateless batching: every request re-queries everything from scratch.
        // This eliminates cross-request state (transients, object cache) that
        // caused inconsistent results between scans on hosts with persistent
        // object caches.
        $all_ids = $this->get_all_attachment_ids();
        $total_ids = count($all_ids);

        if ($total_ids === 0) {
            return array(
                'done'         => true,
                'total_ids'    => 0,
                'scanned'      => 0,
                'next_offset'  => 0,
                'batch_unused' => array(),
            );
        }

        $ids_cache   = $this->preload_id_maps($all_ids);
        $blobs_cache = $this->load_url_blobs();
        $batch       = array_slice($all_ids, $batch_offset, $batch_size);

        $batch_unused = array();
        foreach ($batch as $attachment_id) {
            if ($this->is_image_unused_fast($attachment_id, $ids_cache, $blobs_cache)) {
                $batch_unused[] = $this->get_image_data($attachment_id);
            }
        }

        $next_offset = $batch_offset + count($batch);
        $done        = $next_offset >= $total_ids;

        return array(
            'done'         => $done,
            'total_ids'    => $total_ids,
            'scanned'      => $next_offset,
            'next_offset'  => $next_offset,
            'batch_unused' => $batch_unused,
        );
    }

    /**
     * Get all image attachment IDs via direct DB query (bypasses all filters).
     * ORDER BY ID ASC guarantees deterministic ordering across batches.
     *
     * @return int[]
     */
    private function get_all_attachment_ids() {
        $ids = $this->wpdb->get_col(
            "SELECT ID FROM {$this->wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_mime_type IN ('image/jpeg','image/png','image/gif','image/webp','image/avif')
             AND post_status = 'inherit'
             ORDER BY ID ASC"
        );
        return array_map('intval', $ids);
    }

    /**
     * One-shot, single-request, fully-deterministic unused scan.
     *
     * All heavy work (preload hash maps + URL blobs + attachment loop) happens
     * inside one PHP request, so there are no cross-request transient races
     * and no chance of different batches seeing different DB snapshots.
     *
     * Takes an optional frontend-used set (from the crawler). If provided,
     * any image found on the frontend is excluded from the unused list —
     * even if the DB check would have flagged it. This is the safety net
     * against false positives from database-only heuristics.
     *
     * @param array $used_urls      List of URLs seen on the frontend.
     * @param array $used_filenames List of normalized filenames seen on the frontend.
     * @return array {images, total, total_size, stats}
     */
    public function scan_final(array $used_urls = array(), array $used_filenames = array()) {
        @set_time_limit(300);

        $all_ids = $this->get_all_attachment_ids();
        if (empty($all_ids)) {
            return array(
                'images'     => array(),
                'total'      => 0,
                'total_size' => 0,
                'stats'      => $this->get_stats(),
            );
        }

        $ids_cache   = $this->preload_id_maps($all_ids);
        $blobs_cache = $this->load_url_blobs();

        // Pre-compute frontend lookup hashes (O(1) membership test)
        $used_urls_lookup  = array_fill_keys($used_urls, true);
        $used_files_lookup = array_fill_keys($used_filenames, true);
        $has_frontend_data = !empty($used_urls_lookup) || !empty($used_files_lookup);

        $crawler = $has_frontend_data ? new Hozio_Image_Optimizer_Frontend_Crawler() : null;

        $unused = array();
        foreach ($all_ids as $attachment_id) {
            // DB check first (fast)
            if (!$this->is_image_unused_fast($attachment_id, $ids_cache, $blobs_cache)) {
                continue;
            }

            // Frontend safety net: if crawl data is available and this image
            // appears anywhere on the frontend, it is NOT unused regardless
            // of what the DB said.
            if ($crawler && $crawler->attachment_is_used($attachment_id, $used_urls_lookup, $used_files_lookup)) {
                continue;
            }

            $unused[] = $this->get_image_data($attachment_id);
        }

        // Sort biggest-savings first
        usort($unused, function($a, $b) { return $b['file_size'] - $a['file_size']; });

        return array(
            'images'     => $unused,
            'total'      => count($unused),
            'total_size' => array_sum(array_column($unused, 'file_size')),
            'stats'      => $this->get_stats(),
        );
    }

    /**
     * Filter a list of DB-candidate unused images by checking them against a
     * frontend-used URL/filename lookup set. Returns only images that do NOT
     * appear anywhere in the crawled frontend HTML.
     *
     * @param array $db_candidates  Image data arrays from scan_batch.
     * @param array $used_urls      Hash: url => true.
     * @param array $used_filenames Hash: normalized filename => true.
     * @return array {truly_unused, rescued} — rescued are images DB-flagged but
     *               found on frontend (kept for display/logging).
     */
    public function filter_by_frontend_usage(array $db_candidates, array $used_urls, array $used_filenames) {
        $crawler = new Hozio_Image_Optimizer_Frontend_Crawler();
        $truly_unused = array();
        $rescued      = array();

        foreach ($db_candidates as $img) {
            if ($crawler->attachment_is_used((int) $img['id'], $used_urls, $used_filenames)) {
                $rescued[] = $img;
            } else {
                $truly_unused[] = $img;
            }
        }

        return array(
            'truly_unused' => $truly_unused,
            'rescued'      => $rescued,
        );
    }

    /**
     * Pre-load all ID-based reference hash maps.
     * Stored in a transient and reused across batches.
     * Does NOT include URL blob data — those are fetched fresh per batch via load_url_blobs().
     *
     * @param array $all_ids All attachment IDs being scanned.
     * @return array ids_cache: {urls, protected, used_by_id}
     */
    private function preload_id_maps(array $all_ids) {
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

        // Build a lookup set of all known attachment IDs so we can filter out integers
        // that happen to appear in non-image contexts (view counts, layout numbers, etc.)
        $all_ids_set = array_fill_keys($all_ids, true);

        // Filter meta_int and term_int to only actual attachment IDs to avoid false negatives
        // from unrelated integers stored in postmeta (e.g. view_count, quantity fields).
        $meta_int = array_intersect_key($meta_int, $all_ids_set);
        $term_int = array_intersect_key($term_int, $all_ids_set);

        // 7. JSON-quoted IDs in non-private postmeta (ACF, page builders, etc.)
        // Values like {"image_id":"123"} that don't contain the uploads URL.
        // Intersect with $all_ids_set so random JSON integers ({"columns":"3"}) are ignored.
        $meta_json_rows = $this->wpdb->get_col(
            "SELECT meta_value FROM {$this->wpdb->postmeta}
             WHERE meta_key NOT LIKE '\\_%%'
             AND meta_key != '_thumbnail_id'
             AND meta_key != '_product_image_gallery'
             AND meta_value REGEXP '\"[0-9]+\"'"
        );
        $meta_json_ids = array();
        foreach ($meta_json_rows as $v) {
            preg_match_all('/"(\d+)"/', $v, $m);
            foreach ($m[1] as $id) {
                $meta_json_ids[(int) $id] = true;
            }
        }
        $meta_json_ids = array_intersect_key($meta_json_ids, $all_ids_set);

        // 8. JSON-quoted IDs in non-transient options (widgets, customizer, etc.)
        $opts_json_rows = $this->wpdb->get_col(
            "SELECT option_value FROM {$this->wpdb->options}
             WHERE option_name NOT LIKE '\\_transient%%'
             AND option_name NOT LIKE '\\_site\_transient%%'
             AND option_value REGEXP '\"[0-9]+\"'"
        );
        $opts_json_ids = array();
        foreach ($opts_json_rows as $v) {
            preg_match_all('/"(\d+)"/', $v, $m);
            foreach ($m[1] as $id) {
                $opts_json_ids[(int) $id] = true;
            }
        }
        $opts_json_ids = array_intersect_key($opts_json_ids, $all_ids_set);

        // 9. JSON-quoted IDs in termmeta (rare but possible in some category plugins)
        $term_json_rows = $this->wpdb->get_col(
            "SELECT meta_value FROM {$this->wpdb->termmeta}
             WHERE meta_value REGEXP '\"[0-9]+\"'"
        );
        $term_json_ids = array();
        foreach ($term_json_rows as $v) {
            preg_match_all('/"(\d+)"/', $v, $m);
            foreach ($m[1] as $id) {
                $term_json_ids[(int) $id] = true;
            }
        }
        $term_json_ids = array_intersect_key($term_json_ids, $all_ids_set);

        // 10. WordPress core options that store a single attachment ID as their value.
        // These never match the REGEXP or blob checks because the value is a bare integer,
        // so we pull them explicitly to avoid flagging the site icon / custom logo as unused.
        $core_option_ids = $this->get_core_option_attachment_ids();
        $core_option_ids = array_intersect_key($core_option_ids, $all_ids_set);

        // Combined O(1) "used by ID" map — only contains actual attachment IDs
        $used_by_id = $featured + $woo + $meta_int + $term_int
                    + $meta_json_ids + $opts_json_ids + $term_json_ids
                    + $core_option_ids;

        return array(
            'urls'       => $urls,
            'protected'  => $protected,
            'used_by_id' => $used_by_id,
        );
    }

    /**
     * Collect attachment IDs referenced by WordPress core and common theme
     * options that store an ID as a bare integer (not JSON-quoted).
     *
     * Covers:
     * - `site_icon`          → Appearance → Customize → Site Identity → Site Icon (favicon)
     * - `theme_mods_*`       → `custom_logo`, `header_image_data`, background image ID
     * - `page_on_front`      → front-page attachment (very rare but possible)
     * - `woocommerce_placeholder_image` → WooCommerce placeholder
     *
     * @return array Hash map of attachment_id => true.
     */
    private function get_core_option_attachment_ids() {
        $ids = array();

        // 1. Site icon / favicon
        $site_icon = (int) get_option('site_icon', 0);
        if ($site_icon > 0) $ids[$site_icon] = true;

        // 2. WooCommerce placeholder image
        $wc_placeholder = (int) get_option('woocommerce_placeholder_image', 0);
        if ($wc_placeholder > 0) $ids[$wc_placeholder] = true;

        // 3. Theme mods — custom_logo, header_image_data, background image ID
        $current_theme = get_stylesheet();
        $theme_mods = get_option('theme_mods_' . $current_theme, array());
        if (is_array($theme_mods)) {
            if (!empty($theme_mods['custom_logo'])) {
                $ids[(int) $theme_mods['custom_logo']] = true;
            }
            if (!empty($theme_mods['background_image']) && is_numeric($theme_mods['background_image'])) {
                $ids[(int) $theme_mods['background_image']] = true;
            }
            if (!empty($theme_mods['header_image_data']) && is_object($theme_mods['header_image_data'])) {
                $hid = isset($theme_mods['header_image_data']->attachment_id) ? (int) $theme_mods['header_image_data']->attachment_id : 0;
                if ($hid > 0) $ids[$hid] = true;
            }
        }

        // 4. Allow other plugins to register additional attachment-ID options
        return apply_filters('hozio_used_attachment_ids_from_options', $ids);
    }

    /**
     * Fetch URL-reference blobs fresh from the database.
     * Called on every batch (not cached) so results are always current and not
     * subject to transient size limits or object-cache corruption.
     *
     * @return array {content, meta, opts, terms} — each a concatenated string.
     */
    private function load_url_blobs() {
        $upload_dir  = wp_upload_dir();
        $uploads_esc = $this->wpdb->esc_like($upload_dir['baseurl']);

        $content_rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT post_content FROM {$this->wpdb->posts}
             WHERE post_type NOT IN ('revision', 'attachment', 'nav_menu_item')
             AND post_status NOT IN ('trash', 'auto-draft')
             AND post_content LIKE %s",
            '%' . $uploads_esc . '%'
        ));

        $meta_rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->postmeta}
             WHERE meta_key NOT LIKE '\\_%%'
             AND meta_key != '_thumbnail_id'
             AND meta_key != '_product_image_gallery'
             AND meta_value LIKE %s",
            '%' . $uploads_esc . '%'
        ));

        $opts_rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT option_value FROM {$this->wpdb->options}
             WHERE option_name NOT LIKE '\_transient%'
             AND option_name NOT LIKE '\_site\_transient%'
             AND option_value LIKE %s",
            '%' . $uploads_esc . '%'
        ));

        $terms_rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->termmeta}
             WHERE meta_value LIKE %s",
            '%' . $uploads_esc . '%'
        ));

        return array(
            'content' => implode(' ', $content_rows),
            'meta'    => implode(' ', $meta_rows),
            'opts'    => implode(' ', $opts_rows),
            'terms'   => implode(' ', $terms_rows),
        );
    }

    /**
     * Check if an image is unused using pre-loaded hash maps + fresh URL blobs.
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

        // Featured image, WooCommerce gallery, integer meta, JSON-quoted ID in meta/opts/termmeta
        if (isset($ids_cache['used_by_id'][$attachment_id])) {
            return false;
        }

        // Post content (full URL, relative path, or bare filename)
        $content = $blobs_cache['content'];
        if (
            strpos($content, $url) !== false ||
            strpos($content, $path) !== false ||
            strpos($content, $filename) !== false
        ) {
            return false;
        }

        // Custom postmeta URL references (JSON-quoted IDs already in used_by_id)
        $meta = $blobs_cache['meta'];
        if (strpos($meta, $url) !== false) {
            return false;
        }

        // Options URL/filename references (JSON-quoted IDs already in used_by_id)
        $opts = $blobs_cache['opts'];
        if (strpos($opts, $url) !== false || strpos($opts, $filename) !== false) {
            return false;
        }

        // Termmeta URL references (integer and JSON-quoted IDs already in used_by_id)
        $terms = $blobs_cache['terms'];
        if (strpos($terms, $url) !== false) {
            return false;
        }

        return true;
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
