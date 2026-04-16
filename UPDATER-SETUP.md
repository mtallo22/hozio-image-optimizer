# Auto-Update Setup — Hozio Image Optimizer

This doc explains everything needed to add GitHub-based auto-updates to the image optimizer,
using the same system as Hozio Pro.

---

## Files You Need

| File | Action |
|------|--------|
| `includes/plugin-updater.php` | **Create** — copy the adapted version below |
| `includes/hozio-logger.php` | **Copy** from Hozio Pro (optional but recommended) |
| `hozio-image-optimizer.php` | **Modify** — add require + version constant (see Step 2) |

---

## Step 1 — Create the GitHub Repo

Create a new GitHub repo: `Mtuozzo86/hozio-image-optimizer`
(or whatever slug you want — just match it in the updater file)

The plugin folder on client sites must match the repo slug exactly:
`hozio-image-optimizer/hozio-image-optimizer.php`

---

## Step 2 — Modify the Main Plugin File

In `hozio-image-optimizer.php`, you need:

**1. Plugin header must have a Version line:**
```php
/*
Plugin Name:     Hozio Image Optimizer
Version:         1.0.0
Author:          Hozio Web Dev
Author URI:      https://hozio.com
*/
```

**2. A version constant (makes it easy to reference elsewhere):**
```php
define('HOZIO_IMG_VERSION', '1.0.0');
```
> Version must be bumped in BOTH places for every release — same rule as Hozio Pro.

**3. Load the updater:**
```php
require_once plugin_dir_path(__FILE__) . 'includes/plugin-updater.php';
```

---

## Step 3 — Create `includes/plugin-updater.php`

Create this file in the image optimizer's `includes/` folder.
Everything plugin-specific is marked with `// CHANGE THIS`.

```php
<?php
/**
 * Hozio Image Optimizer — Self-Hosted Plugin Updater
 * Checks GitHub Releases for updates - no third-party plugin required
 */

if (!defined('ABSPATH')) exit;

class Hozio_ImgOpt_Updater {

    private $plugin_slug;
    private $plugin_file;
    private $plugin_name = 'Hozio Image Optimizer'; // CHANGE THIS if needed

    // CHANGE THIS — your GitHub username and the repo name you created
    private $github_username = 'Mtuozzo86';
    private $github_repo     = 'hozio-image-optimizer';

    // CHANGE THIS — MD5 of your license key
    // Generate with: echo md5('your-license-key-here');
    // Use the SAME hash as Hozio Pro if you want one shared license key
    private $valid_license_hash = 'e00b3bdbd1afe6e17d67e3f074da0203';

    private $cache_key    = 'hozio_imgopt_update_cache'; // unique — don't share with Hozio Pro
    private $cache_expiry = 43200; // 12 hours

    private $current_version;
    private $github_response;

    public function __construct() {
        // Auto-detect plugin slug (handles folder name variations)
        $this->plugin_slug = basename(dirname(__DIR__));
        $this->plugin_file = $this->plugin_slug . '/hozio-image-optimizer.php'; // CHANGE filename if different

        $this->current_version = $this->get_current_version();

        if (!is_admin()) return;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api',                           [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection',             [$this, 'fix_source_directory'], 10, 4);
        add_filter('upgrader_post_install',                 [$this, 'after_install'], 10, 3);
        add_filter('auto_update_plugin',                    [$this, 'enable_auto_updates'], 10, 2);
        add_filter('plugin_action_links_' . $this->plugin_file, [$this, 'add_action_links']);
    }

    private function get_current_version() {
        // Method 1: read directly from the main plugin file header
        $main_file = dirname(__DIR__) . '/hozio-image-optimizer.php'; // CHANGE filename if different
        if (file_exists($main_file)) {
            $data = get_file_data($main_file, ['Version' => 'Version']);
            if (!empty($data['Version'])) return $data['Version'];
        }

        // Method 2: WordPress plugin data API
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
     *
     * Reuses the same hozio_license_key option as Hozio Pro so clients
     * only need one license key for all Hozio plugins.
     *
     * If Hozio Pro is NOT installed on the site, the local MD5 check is
     * used as a fallback (same behavior as Hozio Pro legacy mode).
     */
    public function is_license_valid() {
        // If Hozio Pro's Hub client is available, defer to it
        if (class_exists('Hozio_Hub_Client') && Hozio_Hub_Client::is_connected()) {
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
        // Uses its own auto-update toggle, separate from Hozio Pro's toggle
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
        // Prefer the ZIP asset you uploaded (not GitHub's auto-generated zipball)
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
     * GitHub zipballs extract to "Mtuozzo86-hozio-image-optimizer-{hash}/"
     * which must be renamed to "hozio-image-optimizer/" before install.
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

        // Reactivate and clear cache — do NOT touch directories here
        activate_plugin($this->plugin_file);
        delete_transient($this->cache_key);

        return $response;
    }

    public function add_action_links($links) {
        // CHANGE THIS — point to your plugin's settings page slug
        $settings_url  = admin_url('options-general.php?page=hozio-image-optimizer-settings');
        $optimizer_url = admin_url('upload.php?page=hozio-image-optimizer');

        array_unshift($links, '<a href="' . $settings_url . '">Settings</a>');
        array_unshift($links, '<a href="' . $optimizer_url . '">Optimize Images</a>');

        if (!$this->is_license_valid()) {
            array_unshift($links, '<a href="' . $settings_url . '" style="color:#d63638;">Enter License Key</a>');
        }

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
```

---

## Step 4 — Logger (Optional but Recommended)

The updater above does NOT call `hozio_log()` — all logging calls were removed to keep
this file standalone. If you later want logging, either:

**Option A:** Copy `includes/hozio-logger.php` from Hozio Pro into this plugin and
`require_once` it before the updater.

**Option B:** Add a simple inline fallback at the top of the updater file:
```php
if (!function_exists('hozio_log')) {
    function hozio_log($message, $context = '') {
        // no-op or error_log($message) for debugging
    }
}
```

---

## Step 5 — Release Process

Same rules as Hozio Pro (see `RELEASE.md` in that repo):

1. Bump version in **two places** in `hozio-image-optimizer.php`:
   - Plugin header: `Version: X.X.X`
   - Constant: `define('HOZIO_IMG_VERSION', 'X.X.X');`

2. Commit and push to `main`

3. Build ZIP using `System.IO.Compression.ZipFile` (NOT `Compress-Archive`)
   - Top-level folder must be `hozio-image-optimizer/` (matches the plugin slug)
   - Use the same `build-zip.ps1` pattern from Hozio Pro, just change the paths

4. Create GitHub release:
   ```
   gh release create v1.0.0 hozio-image-optimizer.zip --title "v1.0.0: Description"
   ```

5. Sites will auto-update within 12 hours (WordPress background update check)
   or immediately when someone visits Plugins > Updates in wp-admin.

---

## License Key Sharing

Both plugins use the same `hozio_license_key` WordPress option and the same MD5 hash.
So if a client already has Hozio Pro licensed and activated, the image optimizer will
also be licensed automatically — no extra setup needed on their end.

If Hozio Pro is NOT installed on a site, the image optimizer checks the same
`hozio_license_key` option directly and validates it with the same MD5 hash.

---

## What Changed vs Hozio Pro's Updater

| Thing | Hozio Pro | Image Optimizer |
|-------|-----------|-----------------|
| Class name | `Hozio_Plugin_Updater` | `Hozio_ImgOpt_Updater` |
| Global var | `$hozio_updater` | `$hozio_imgopt_updater` |
| Cache key | `hozio_plugin_update_cache` | `hozio_imgopt_update_cache` |
| Auto-update option | `hozio_auto_updates_enabled` | `hozio_imgopt_auto_updates_enabled` |
| Last check option | `hozio_last_update_check` | `hozio_imgopt_last_update_check` |
| GitHub repo | `hozio-dynamic-tags` | `hozio-image-optimizer` |
| Main PHP file | `hozio-dynamic-tags.php` | `hozio-image-optimizer.php` |
| Logging | Uses `hozio_log()` | Removed (standalone safe) |
| Hub integration | Full | Reads Hub if available, falls back gracefully |
