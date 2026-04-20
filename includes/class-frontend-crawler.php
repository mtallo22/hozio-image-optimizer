<?php
/**
 * Frontend Crawler — fetches public pages and extracts all image references
 * found in the rendered HTML.
 *
 * Used as the ground-truth layer for unused-image detection. If an image URL
 * does not appear in ANY rendered frontend page, it is genuinely unused from
 * a visitor's perspective — regardless of what the database says.
 *
 * @package Hozio_Image_Optimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Frontend_Crawler {

    private $wpdb;
    private $upload_baseurl;
    private $upload_basedir;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $upload = wp_upload_dir();
        $this->upload_baseurl = trailingslashit($upload['baseurl']);
        $this->upload_basedir = trailingslashit($upload['basedir']);
    }

    /**
     * Discover all public URLs on the site that should be crawled.
     *
     * Strategy:
     * 1. Try sitemap sources (Yoast, RankMath, WP core)
     * 2. Fallback: direct DB query for all published posts of public post types
     * 3. Add archive URLs (home, categories, tags, date archives, term archives)
     *
     * @return array Deduplicated list of absolute URLs.
     */
    public function discover_urls() {
        $urls = array();
        $home = home_url('/');

        // Always include the homepage and common archive URLs
        $urls[] = $home;

        // Try sitemaps first — fastest path if the site has one
        $sitemap_urls = $this->parse_sitemaps();
        if (!empty($sitemap_urls)) {
            $urls = array_merge($urls, $sitemap_urls);
        } else {
            // Fallback: build URLs from DB
            $urls = array_merge($urls, $this->discover_from_db());
        }

        // Always include term archives even if sitemaps were used — some plugins
        // omit category/tag pages from sitemaps but they often render images.
        $urls = array_merge($urls, $this->get_term_archive_urls());

        // Dedupe and return
        $urls = array_values(array_unique(array_filter($urls)));
        return $urls;
    }

    /**
     * Try known sitemap endpoints and parse out all URLs.
     *
     * @return array
     */
    private function parse_sitemaps() {
        $home = untrailingslashit(home_url());
        $candidates = array(
            $home . '/sitemap_index.xml',   // Yoast / RankMath default
            $home . '/sitemap.xml',          // Generic
            $home . '/wp-sitemap.xml',       // WP 5.5+ core
        );

        $all_urls = array();
        foreach ($candidates as $sitemap_url) {
            $urls = $this->fetch_sitemap($sitemap_url);
            if (!empty($urls)) {
                $all_urls = array_merge($all_urls, $urls);
                break; // Stop after the first working sitemap
            }
        }

        return $all_urls;
    }

    /**
     * Fetch and recursively parse a sitemap (handles sitemap indexes).
     *
     * @param string $url
     * @return array
     */
    private function fetch_sitemap($url) {
        $response = wp_remote_get($url, array(
            'timeout'     => 10,
            'redirection' => 3,
            'sslverify'   => false,
            'user-agent'  => 'Hozio-Image-Optimizer/1.0 (sitemap fetch)',
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return array();
        }

        // Detect if this is a sitemap index (list of sitemaps) or a sitemap (list of URLs)
        if (stripos($body, '<sitemapindex') !== false) {
            // It's an index — recursively fetch each sub-sitemap
            $sub_urls = array();
            if (preg_match_all('/<loc>([^<]+)<\/loc>/i', $body, $matches)) {
                foreach ($matches[1] as $sub_sitemap_url) {
                    $sub_urls = array_merge($sub_urls, $this->fetch_sitemap(trim($sub_sitemap_url)));
                }
            }
            return $sub_urls;
        }

        // Regular sitemap — extract <loc> entries
        $page_urls = array();
        if (preg_match_all('/<loc>([^<]+)<\/loc>/i', $body, $matches)) {
            foreach ($matches[1] as $page_url) {
                $page_urls[] = trim($page_url);
            }
        }

        return $page_urls;
    }

    /**
     * Fallback URL discovery: query published posts directly from DB.
     *
     * @return array
     */
    private function discover_from_db() {
        $post_types = $this->get_public_post_types();
        if (empty($post_types)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT ID FROM {$this->wpdb->posts}
             WHERE post_status = 'publish'
             AND post_type IN ($placeholders)",
            $post_types
        ));

        $urls = array();
        foreach ($ids as $id) {
            $permalink = get_permalink($id);
            if ($permalink) {
                $urls[] = $permalink;
            }
        }
        return $urls;
    }

    /**
     * Get list of public, non-attachment post types.
     *
     * @return array
     */
    private function get_public_post_types() {
        $types = get_post_types(array('public' => true), 'names');
        unset($types['attachment']);
        return array_values($types);
    }

    /**
     * Get term archive URLs (categories, tags, custom taxonomies).
     *
     * @return array
     */
    private function get_term_archive_urls() {
        $urls = array();
        $taxonomies = get_taxonomies(array('public' => true), 'names');

        foreach ($taxonomies as $tax) {
            $terms = get_terms(array(
                'taxonomy'   => $tax,
                'hide_empty' => true,
                'number'     => 500, // Cap to avoid runaway
            ));
            if (is_wp_error($terms)) continue;
            foreach ($terms as $term) {
                $link = get_term_link($term);
                if (!is_wp_error($link)) {
                    $urls[] = $link;
                }
            }
        }

        return $urls;
    }

    /**
     * Crawl a slice of URLs and extract all image references found.
     *
     * @param array $urls       All URLs to crawl (full list).
     * @param int   $offset     Starting index.
     * @param int   $batch_size How many URLs to fetch this batch.
     * @return array {
     *     @type array $found_urls      Full image URLs seen in the rendered HTML.
     *     @type array $found_filenames Basenames (size-suffix stripped) of images seen.
     *     @type array $errors          URLs that failed to fetch, with reasons.
     *     @type int   $fetched         Number of URLs successfully fetched.
     * }
     */
    public function crawl_batch(array $urls, $offset = 0, $batch_size = 10) {
        $slice = array_slice($urls, $offset, $batch_size);
        $found_urls      = array();
        $found_filenames = array();
        $errors          = array();
        $fetched         = 0;

        foreach ($slice as $url) {
            $response = wp_remote_get($url, array(
                'timeout'     => 15,
                'redirection' => 3,
                'sslverify'   => false,
                'user-agent'  => 'Hozio-Image-Optimizer/1.0 (frontend scan)',
            ));

            if (is_wp_error($response)) {
                $errors[] = array('url' => $url, 'reason' => $response->get_error_message());
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                $errors[] = array('url' => $url, 'reason' => 'HTTP ' . $code);
                continue;
            }

            $html = wp_remote_retrieve_body($response);
            if (empty($html)) {
                $errors[] = array('url' => $url, 'reason' => 'Empty response');
                continue;
            }

            $fetched++;
            $refs = $this->extract_image_references($html);

            foreach ($refs['urls'] as $u) {
                $found_urls[$u] = true;
            }
            foreach ($refs['filenames'] as $f) {
                $found_filenames[$f] = true;
            }
        }

        return array(
            'found_urls'      => array_keys($found_urls),
            'found_filenames' => array_keys($found_filenames),
            'errors'          => $errors,
            'fetched'         => $fetched,
        );
    }

    /**
     * Parse a page's HTML and extract every image-like reference found.
     *
     * Covers:
     * - <img src>, <img data-src>, <img data-lazy-src>
     * - <source src>, <source srcset> (picture element)
     * - srcset multi-URL format
     * - CSS background-image url() in inline and embedded styles
     * - <meta property="og:image">
     * - <link rel="image_src">
     * - JSON-LD "url" fields under image schemas
     * - <video poster>
     *
     * @param string $html
     * @return array {urls, filenames}
     */
    public function extract_image_references($html) {
        $urls = array();

        // 1. img / source src, data-src, data-lazy-src, data-original (lazy-load plugins)
        if (preg_match_all('/<(?:img|source|video)\b[^>]*?\s(?:src|data-src|data-lazy-src|data-original|poster)\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) { $urls[] = $u; }
        }

        // 2. srcset — comma-separated list of "url width" pairs
        if (preg_match_all('/\ssrcset\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $srcset) {
                foreach (explode(',', $srcset) as $part) {
                    $part = trim($part);
                    if ($part === '') continue;
                    // "url width" — take the URL portion
                    $parts = preg_split('/\s+/', $part, 2);
                    if (!empty($parts[0])) {
                        $urls[] = $parts[0];
                    }
                }
            }
        }

        // 3. CSS url(...) in inline styles and <style> blocks
        if (preg_match_all('/url\(\s*["\']?([^)"\'\s]+)["\']?\s*\)/i', $html, $m)) {
            foreach ($m[1] as $u) { $urls[] = $u; }
        }

        // 4. og:image meta tag
        if (preg_match_all('/<meta[^>]+(?:property|name)\s*=\s*["\'](?:og:image(?::secure_url)?|twitter:image)["\'][^>]+content\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) { $urls[] = $u; }
        }

        // Same selectors but with content-first attribute ordering
        if (preg_match_all('/<meta[^>]+content\s*=\s*["\']([^"\']+)["\'][^>]+(?:property|name)\s*=\s*["\'](?:og:image(?::secure_url)?|twitter:image)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) { $urls[] = $u; }
        }

        // 5. link rel=image_src / apple-touch-icon / icon
        if (preg_match_all('/<link[^>]+rel\s*=\s*["\'](?:image_src|apple-touch-icon|icon|shortcut icon)["\'][^>]+href\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) { $urls[] = $u; }
        }

        // 6. JSON-LD schema "image":"..." and "url":"..." — naive but effective
        if (preg_match_all('/"(?:image|url|contentUrl|thumbnailUrl)"\s*:\s*"([^"]+)"/i', $html, $m)) {
            foreach ($m[1] as $u) {
                // Unescape common JSON escapes
                $u = str_replace(array('\\/', '\\\\'), array('/', '\\'), $u);
                $urls[] = $u;
            }
        }

        // 7. Generic fallback: any URL that points into the uploads directory
        $uploads_pattern = preg_quote($this->upload_baseurl, '/');
        if (preg_match_all('/' . $uploads_pattern . '[^\s"\'<>)]+/i', $html, $m)) {
            foreach ($m[0] as $u) { $urls[] = $u; }
        }

        // Dedupe and filter to only uploads-directory URLs
        $filtered_urls      = array();
        $filtered_filenames = array();
        $seen_urls          = array();

        foreach ($urls as $u) {
            $u = $this->clean_url($u);
            if ($u === '' || isset($seen_urls[$u])) continue;
            $seen_urls[$u] = true;

            // Only keep URLs that point into our uploads dir
            if (strpos($u, $this->upload_baseurl) !== 0) continue;

            $filtered_urls[] = $u;

            // Also extract the normalized filename (strip size suffix + query)
            $filename = $this->normalize_filename($u);
            if ($filename !== '') {
                $filtered_filenames[] = $filename;
            }
        }

        return array(
            'urls'      => array_values(array_unique($filtered_urls)),
            'filenames' => array_values(array_unique($filtered_filenames)),
        );
    }

    /**
     * Strip query strings, fragments, and HTML entities from a URL.
     */
    private function clean_url($url) {
        $url = trim($url);
        if ($url === '') return '';
        // Unescape HTML entities
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Strip query string and fragment
        $url = preg_replace('/[?#].*$/', '', $url);
        return $url;
    }

    /**
     * Normalize a URL to its canonical filename:
     * - strip size suffix (-300x200)
     * - strip -scaled suffix (WP 5.3+)
     * - return just the basename
     *
     * e.g. "https://.../uploads/2024/01/hero-300x200.jpg" → "hero.jpg"
     */
    private function normalize_filename($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) return '';
        $basename = basename($path);

        // Strip -scaled before extension
        $basename = preg_replace('/-scaled(\.[a-zA-Z0-9]+)$/', '$1', $basename);

        // Strip -WIDTHxHEIGHT size suffix (e.g. -300x200)
        $basename = preg_replace('/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $basename);

        return $basename;
    }

    /**
     * Crawl every public frontend page and report exactly where a single
     * attachment's image URLs appear (or confirm it appears nowhere).
     *
     * This is the ground-truth check for "is this image actually used on the
     * frontend?" — bypasses all database guessing.
     *
     * @param int $attachment_id
     * @param int $max_pages Cap on pages to crawl (0 = no cap).
     * @return array {
     *     @type int   $checked_pages Number of pages successfully crawled.
     *     @type array $found_in      URLs of pages where this image was found.
     *     @type array $target_urls   The URL variants we searched for.
     *     @type array $target_files  Filename variants we searched for.
     *     @type bool  $is_used       True if found in any page.
     * }
     */
    public function verify_single_image(int $attachment_id, int $max_pages = 0) {
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return array(
                'checked_pages' => 0,
                'found_in'      => array(),
                'target_urls'   => array(),
                'target_files'  => array(),
                'is_used'       => false,
                'error'         => 'Attachment has no URL',
            );
        }

        // Build the set of URL/filename variants that count as "this image"
        $target_urls  = array($url => true);
        $target_files = array();
        $main_file = $this->normalize_filename($url);
        if ($main_file !== '') $target_files[$main_file] = true;

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $base_url = trailingslashit(dirname($url));
            foreach ($meta['sizes'] as $size) {
                if (empty($size['file'])) continue;
                $target_urls[$base_url . $size['file']] = true;
                $f = $this->normalize_filename($size['file']);
                if ($f !== '') $target_files[$f] = true;
            }
        }

        // Discover all public URLs and crawl them
        $urls = $this->discover_urls();
        if ($max_pages > 0 && count($urls) > $max_pages) {
            $urls = array_slice($urls, 0, $max_pages);
        }

        $found_in = array();
        $checked  = 0;

        foreach ($urls as $page_url) {
            $response = wp_remote_get($page_url, array(
                'timeout'     => 15,
                'redirection' => 3,
                'sslverify'   => false,
                'user-agent'  => 'Hozio-Image-Optimizer/1.0 (single-image verify)',
            ));

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                continue;
            }

            $html = wp_remote_retrieve_body($response);
            if (empty($html)) continue;

            $checked++;
            $refs = $this->extract_image_references($html);

            // Fast membership test — any URL or filename overlap means hit
            $hit = false;
            foreach ($refs['urls'] as $u) {
                if (isset($target_urls[$u])) { $hit = true; break; }
            }
            if (!$hit) {
                foreach ($refs['filenames'] as $f) {
                    if (isset($target_files[$f])) { $hit = true; break; }
                }
            }
            if ($hit) {
                $found_in[] = $page_url;
            }
        }

        return array(
            'checked_pages' => $checked,
            'found_in'      => $found_in,
            'target_urls'   => array_keys($target_urls),
            'target_files'  => array_keys($target_files),
            'is_used'       => !empty($found_in),
        );
    }

    /**
     * Check whether an attachment's URL/filename appears in a set of frontend-used refs.
     *
     * @param int   $attachment_id
     * @param array $used_urls_lookup      Hash of url => true.
     * @param array $used_filenames_lookup Hash of filename => true.
     * @return bool
     */
    public function attachment_is_used(int $attachment_id, array $used_urls_lookup, array $used_filenames_lookup) {
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) return false;

        if (isset($used_urls_lookup[$url])) return true;

        $filename = $this->normalize_filename($url);
        if ($filename !== '' && isset($used_filenames_lookup[$filename])) return true;

        // Also check resized variants — query attachment metadata for all sizes
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_data) {
                if (empty($size_data['file'])) continue;
                $size_filename = $this->normalize_filename($size_data['file']);
                if ($size_filename !== '' && isset($used_filenames_lookup[$size_filename])) {
                    return true;
                }
            }
        }

        return false;
    }
}
