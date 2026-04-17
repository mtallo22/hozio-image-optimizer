<?php
/**
 * Hozio Image Optimizer — Self-Hosted Plugin Updater
 * Checks GitHub Releases for updates - no third-party plugin required
 */

if (!defined('ABSPATH')) exit;

class Hozio_ImgOpt_Updater {

    private $plugin_slug;
    private $plugin_file;
    private $plugin_name = 'Hozio Image Optimizer';

    private $github_username = 'mtallo22';
    private $github_repo     = 'hozio-image-optimizer';

    // MD5 of license key (shared with Hozio Pro)
    private $valid_license_hash = 'e00b3bdbd1afe6e17d67e3f074da0203';

    private $cache_key    = 'hozio_imgopt_update_cache';
    private $cache_expiry = 43200; // 12 hours

    private $current_version;
    private $github_response;

    public function __construct() {
        $this->plugin_slug = basename(dirname(__DIR__));
        $this->plugin_file = $this->plugin_slug . '/hozio-image-optimizer.php';

        $this->current_version = $this->get_current_version();

        if (!is_admin()) return;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api',                           [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection',             [$this, 'fix_source_directory'], 10, 4);
        add_filter('upgrader_post_install',                 [$this, 'after_install'], 10, 3);
        add_filter('auto_update_plugin',                    [$this, 'enable_auto_updates'], 10, 2);
        add_filter('plugin_action_links_' . $this->plugin_file, [$this, 'add_action_links']);

        // Handle "Check for Updates" link click
        if (isset($_GET['hozio_imgopt_check_update']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'hozio_check_update')) {
            $this->force_update_check();
            set_transient('hozio_imgopt_update_notice', 'checked', 30);
            wp_redirect(admin_url('plugins.php'));
            exit;
        }

        // Show success notice after check
        if (get_transient('hozio_imgopt_update_notice') === 'checked') {
            add_action('admin_notices', function() {
                delete_transient('hozio_imgopt_update_notice');
                echo '<div class="notice notice-success is-dismissible"><p><strong>Hozio Image Optimizer:</strong> Update check complete. You are running v' . esc_html($this->current_version) . '.</p></div>';
            });
        }
    }

    private function get_current_version() {
        $main_file = dirname(__DIR__) . '/hozio-image-optimizer.php';
        if (file_exists($main_file)) {
            $data = get_file_data($main_file, ['Version' => 'Version']);
            if (!empty($data['Version'])) return $data['Version'];
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_file = WP_PLUGIN_DIR . '/' . $this->plugin_file;
        if (file_exists($plugin_file)) {
            $data = get_plugin_data($plugin_file);
            if (!empty($data['Version'])) return $data['Version'];
        }

        return '0.0.0';
    }

    /**
     * License validation.
     * Reuses the same hozio_license_key option as Hozio Pro.
     */
    public function is_license_valid() {
        // If Hozio Pro's Hub client is available, defer to it
        if (class_exists('Hozio_Hub_Client') && method_exists('Hozio_Hub_Client', 'is_connected') && Hozio_Hub_Client::is_connected()) {
            $status = Hozio_Hub_Client::get_license_status();
            if ($status === 'active') return true;
            if ($status === 'revoked' || $status === 'suspended') return false;
            return false;
        }

        // Standalone: check the shared license key option
        $entered_key = get_option('hozio_license_key', '');
        return md5(trim($entered_key)) === $this->valid_license_hash;
    }

    public function enable_auto_updates($update, $item) {
        if (!isset($item->slug) || $item->slug !== $this->plugin_slug) return $update;
        if (!$this->is_license_valid()) return false;
        if (get_option('hozio_imgopt_auto_updates_enabled', '1') !== '1') return false;
        return true;
    }

    private function get_github_release() {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) return $cached;

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_username,
            $this->github_repo
        );

        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
            ],
            'timeout' => 10
        ]);

        if (is_wp_error($response)) return false;
        if (wp_remote_retrieve_response_code($response) !== 200) return false;

        $data = json_decode(wp_remote_retrieve_body($response));
        if (empty($data) || !isset($data->tag_name)) return false;

        set_transient($this->cache_key, $data, $this->cache_expiry);
        update_option('hozio_imgopt_last_update_check', time());

        return $data;
    }

    private function get_download_url($release) {
        if (!empty($release->assets)) {
            foreach ($release->assets as $asset) {
                if (strpos($asset->name, '.zip') !== false) {
                    return $asset->browser_download_url;
                }
            }
        }
        return !empty($release->zipball_url) ? $release->zipball_url : false;
    }

    private function parse_version($tag) {
        return ltrim($tag, 'vV');
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) return $transient;
        if (!$this->is_license_valid()) return $transient;

        $release = $this->get_github_release();
        if (!$release) return $transient;

        $remote_version = $this->parse_version($release->tag_name);

        if (version_compare($this->current_version, $remote_version, '<')) {
            $download_url = $this->get_download_url($release);
            if ($download_url) {
                $transient->response[$this->plugin_file] = (object) [
                    'slug'         => $this->plugin_slug,
                    'plugin'       => $this->plugin_file,
                    'new_version'  => $remote_version,
                    'url'          => $release->html_url,
                    'package'      => $download_url,
                    'icons'        => [],
                    'banners'      => [],
                    'banners_rtl'  => [],
                    'tested'       => '',
                    'requires_php' => '7.4',
                    'compatibility' => new stdClass()
                ];
            }
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) return $result;

        $release = $this->get_github_release();
        if (!$release) return $result;

        $remote_version = $this->parse_version($release->tag_name);
        $download_url   = $this->get_download_url($release);

        $info = new stdClass();
        $info->name         = $this->plugin_name;
        $info->slug         = $this->plugin_slug;
        $info->version      = $remote_version;
        $info->author       = '<a href="https://hozio.com">Hozio, Inc.</a>';
        $info->homepage     = 'https://github.com/' . $this->github_username . '/' . $this->github_repo;
        $info->requires     = '5.0';
        $info->tested       = '6.6';
        $info->requires_php = '7.4';
        $info->downloaded   = 0;
        $info->last_updated = $release->published_at;
        $info->download_link = $download_url;
        $info->sections = [
            'description' => '<p>Hozio Image Optimizer — AI-powered image renaming, compression, WebP/AVIF conversion, and geolocation tagging for WordPress sites.</p>',
            'changelog'   => '<pre>' . esc_html(isset($release->body) ? $release->body : 'No changelog available.') . '</pre>',
            'installation' => '<p>Upload the plugin ZIP via WordPress admin or deploy via the Hozio Hub updater.</p>'
        ];

        return $info;
    }

    /**
     * Rename the extracted folder to match the expected plugin slug.
     */
    public function fix_source_directory($source, $remote_source, $upgrader, $hook_extra) {
        global $wp_filesystem;

        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $source;
        }

        $source_basename = untrailingslashit(basename(untrailingslashit($source)));

        if ($source_basename !== $this->plugin_slug) {
            $corrected = trailingslashit($remote_source) . trailingslashit($this->plugin_slug);
            if ($wp_filesystem->move($source, $corrected)) {
                return $corrected;
            }
        }

        return $source;
    }

    public function after_install($response, $hook_extra, $result) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $response;
        }

        activate_plugin($this->plugin_file);
        delete_transient($this->cache_key);

        return $response;
    }

    public function add_action_links($links) {
        $settings_url = admin_url('options-general.php?page=hozio-image-optimizer-settings&tab=license');

        // Only add updater-specific links (Optimize Images + Settings are added by class-admin.php)
        if (!$this->is_license_valid()) {
            $links[] = '<a href="' . $settings_url . '" style="color:#d63638;">Enter License</a>';
        }

        $links[] = '<a href="' . wp_nonce_url(admin_url('plugins.php?hozio_imgopt_check_update=1'), 'hozio_check_update') . '">Check for Updates</a>';

        return $links;
    }

    public function force_update_check() {
        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }
}

// Initialize
function hozio_imgopt_init_updater() {
    global $hozio_imgopt_updater;
    $hozio_imgopt_updater = new Hozio_ImgOpt_Updater();
}
add_action('init', 'hozio_imgopt_init_updater');

// Helper: is license valid?
function hozio_imgopt_is_license_valid() {
    global $hozio_imgopt_updater;
    return $hozio_imgopt_updater ? $hozio_imgopt_updater->is_license_valid() : false;
}

// Helper: time since last update check
function hozio_imgopt_get_last_update_check() {
    $ts = get_option('hozio_imgopt_last_update_check', 0);
    return $ts ? human_time_diff($ts, time()) . ' ago' : 'Never';
}
