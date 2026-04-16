<?php
/**
 * Cache Purger - Clears caches from popular WordPress caching plugins after image operations
 *
 * Supports: WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache,
 * WP Fastest Cache, Autoptimize, SG Optimizer, Hummingbird, Cloudflare
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Cache_Purger {

    /**
     * Purge all detected caches
     *
     * @return array Results of which caches were purged
     */
    public function purge_all_caches() {
        $results = array();

        // WordPress object cache
        wp_cache_flush();
        $results['object_cache'] = true;

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $results['wp_super_cache'] = true;
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $results['w3_total_cache'] = true;
        }

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $results['wp_rocket'] = true;
        }

        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API') || has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
            $results['litespeed'] = true;
        }

        // WP Fastest Cache
        if (class_exists('WpFastestCache')) {
            global $wp_fastest_cache;
            if (is_object($wp_fastest_cache) && method_exists($wp_fastest_cache, 'deleteCache')) {
                $wp_fastest_cache->deleteCache();
                $results['wp_fastest_cache'] = true;
            }
        }

        // Autoptimize
        if (class_exists('autoptimizeCache')) {
            autoptimizeCache::clearall();
            $results['autoptimize'] = true;
        }

        // SG Optimizer
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            $results['sg_optimizer'] = true;
        }

        // Hummingbird
        if (has_action('wphb_clear_page_cache')) {
            do_action('wphb_clear_page_cache');
            $results['hummingbird'] = true;
        }

        // Cloudflare (via various plugins)
        if (has_action('cloudflare_purge_everything')) {
            do_action('cloudflare_purge_everything');
            $results['cloudflare'] = true;
        }

        // Breeze (Cloudways)
        if (class_exists('Breeze_PurgeCache') && method_exists('Breeze_PurgeCache', 'breeze_cache_flush')) {
            Breeze_PurgeCache::breeze_cache_flush();
            $results['breeze'] = true;
        }

        // Nginx Helper
        if (has_action('rt_nginx_helper_purge_all')) {
            do_action('rt_nginx_helper_purge_all');
            $results['nginx_helper'] = true;
        }

        $purged_count = count($results);
        Hozio_Image_Optimizer_Helpers::log("Cache purge: Cleared {$purged_count} cache system(s): " . implode(', ', array_keys($results)));

        return $results;
    }

    /**
     * Purge cache for specific URLs
     *
     * @param array $urls URLs to purge
     * @return array Results
     */
    public function purge_specific_urls($urls) {
        $results = array();

        if (empty($urls)) {
            return $results;
        }

        // WP Rocket - URL-specific purge
        if (function_exists('rocket_clean_files')) {
            rocket_clean_files($urls);
            $results['wp_rocket'] = true;
        }

        // LiteSpeed - URL-specific purge
        if (class_exists('LiteSpeed_Cache_API') || has_action('litespeed_purge_url')) {
            foreach ($urls as $url) {
                do_action('litespeed_purge_url', $url);
            }
            $results['litespeed'] = true;
        }

        return $results;
    }
}
