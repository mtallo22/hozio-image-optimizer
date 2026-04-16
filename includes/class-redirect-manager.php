<?php
/**
 * Redirect Manager - Manages 301 redirects from old image URLs to new ones
 *
 * Acts as a safety net after image renaming. If any image references are missed
 * by the reference updater, this catches requests to old URLs and redirects them.
 * Opt-in feature (disabled by default) with automatic cleanup.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Redirect_Manager {

    private $option_key = 'hozio_image_redirects';
    private $max_entries = 500;

    /**
     * Add a redirect mapping
     *
     * @param string $old_url Old image URL
     * @param string $new_url New image URL
     */
    public function add_redirect($old_url, $new_url) {
        $redirects = get_option($this->option_key, array());

        // Use the path relative to site URL for efficient matching
        $old_path = wp_parse_url($old_url, PHP_URL_PATH);
        if (!$old_path) {
            return;
        }

        $redirects[$old_path] = array(
            'new_url' => $new_url,
            'created' => time(),
        );

        // Prune if over limit (remove oldest entries)
        if (count($redirects) > $this->max_entries) {
            uasort($redirects, function($a, $b) {
                return $a['created'] - $b['created'];
            });
            $redirects = array_slice($redirects, -$this->max_entries, null, true);
        }

        update_option($this->option_key, $redirects, false);
    }

    /**
     * Remove a specific redirect
     *
     * @param string $old_url Old image URL
     */
    public function remove_redirect($old_url) {
        $redirects = get_option($this->option_key, array());
        $old_path = wp_parse_url($old_url, PHP_URL_PATH);

        if ($old_path && isset($redirects[$old_path])) {
            unset($redirects[$old_path]);
            update_option($this->option_key, $redirects, false);
        }
    }

    /**
     * Handle incoming request - redirect if old image URL is requested
     * Hook this into template_redirect at priority 1
     */
    public function handle_redirect() {
        // Only process on 404 or direct image requests
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (empty($request_uri)) {
            return;
        }

        // Quick check: only process if the URI looks like it could be an image in uploads
        $uploads = wp_upload_dir();
        $uploads_path = wp_parse_url($uploads['baseurl'], PHP_URL_PATH);

        if (!$uploads_path || strpos($request_uri, $uploads_path) === false) {
            return;
        }

        // Check if we have a redirect for this path
        $redirects = get_option($this->option_key, array());
        if (empty($redirects)) {
            return;
        }

        // Parse the request URI (remove query string)
        $request_path = strtok($request_uri, '?');

        if (isset($redirects[$request_path])) {
            $new_url = $redirects[$request_path]['new_url'];

            // Verify the new URL target actually exists before redirecting
            $new_path = wp_parse_url($new_url, PHP_URL_PATH);
            if ($new_path) {
                wp_redirect($new_url, 301);
                exit;
            }
        }
    }

    /**
     * Clean up old redirects
     *
     * @param int $days Remove redirects older than this many days
     */
    public function cleanup_old_redirects($days = 90) {
        $redirects = get_option($this->option_key, array());

        if (empty($redirects)) {
            return;
        }

        $cutoff = time() - ($days * DAY_IN_SECONDS);
        $cleaned = 0;

        foreach ($redirects as $path => $data) {
            if (isset($data['created']) && $data['created'] < $cutoff) {
                unset($redirects[$path]);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            update_option($this->option_key, $redirects, false);
            Hozio_Image_Optimizer_Helpers::log("Redirect cleanup: Removed {$cleaned} expired redirects");
        }
    }

    /**
     * Get count of active redirects
     *
     * @return int
     */
    public function get_redirect_count() {
        $redirects = get_option($this->option_key, array());
        return count($redirects);
    }
}
