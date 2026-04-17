<?php
/**
 * Settings page with tabbed interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Settings {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Register all settings
     */
    public function register_settings() {
        // License Settings
        register_setting('hozio_license_settings', 'hozio_license_key', array(
            'sanitize_callback' => 'sanitize_text_field',
        ));
        register_setting('hozio_license_settings', 'hozio_imgopt_auto_updates_enabled', array(
            'sanitize_callback' => 'sanitize_text_field',
        ));

        // API Settings
        register_setting('hozio_api_settings', 'hozio_openai_api_key', array(
            'sanitize_callback' => array($this, 'sanitize_api_key'),
        ));
        register_setting('hozio_api_settings', 'hozio_openai_model', array(
            'sanitize_callback' => 'sanitize_text_field',
        ));

        // AI Feature Settings (in api settings group)
        register_setting('hozio_api_settings', 'hozio_enable_ai_rename', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
        register_setting('hozio_api_settings', 'hozio_enable_ai_alt_text', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
        register_setting('hozio_api_settings', 'hozio_enable_ai_caption', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
        register_setting('hozio_api_settings', 'hozio_enable_ai_tagging', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));

        // Custom AI Prompts (in api settings group)
        register_setting('hozio_api_settings', 'hozio_prompt_filename', array(
            'sanitize_callback' => 'sanitize_textarea_field',
        ));
        register_setting('hozio_api_settings', 'hozio_prompt_title', array(
            'sanitize_callback' => 'sanitize_textarea_field',
        ));
        register_setting('hozio_api_settings', 'hozio_prompt_alt_text', array(
            'sanitize_callback' => 'sanitize_textarea_field',
        ));
        register_setting('hozio_api_settings', 'hozio_prompt_caption', array(
            'sanitize_callback' => 'sanitize_textarea_field',
        ));
        register_setting('hozio_api_settings', 'hozio_prompt_description', array(
            'sanitize_callback' => 'sanitize_textarea_field',
        ));

        // Compression Settings
        register_setting('hozio_compression_settings', 'hozio_enable_compression', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
        register_setting('hozio_compression_settings', 'hozio_compression_quality', array(
            'sanitize_callback' => 'absint',
        ));
        register_setting('hozio_compression_settings', 'hozio_max_width', array(
            'sanitize_callback' => 'absint',
        ));
        register_setting('hozio_compression_settings', 'hozio_max_height', array(
            'sanitize_callback' => 'absint',
        ));
        register_setting('hozio_compression_settings', 'hozio_strip_metadata', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));

        // Format Settings
        register_setting('hozio_format_settings', 'hozio_convert_to_webp', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
        register_setting('hozio_format_settings', 'hozio_convert_to_avif', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
        register_setting('hozio_format_settings', 'hozio_webp_quality', array(
            'sanitize_callback' => 'absint',
        ));
        register_setting('hozio_format_settings', 'hozio_avif_quality', array(
            'sanitize_callback' => 'absint',
        ));
        register_setting('hozio_format_settings', 'hozio_keep_original_backup', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));

        // Naming Settings
        register_setting('hozio_naming_settings', 'hozio_enable_ai_rename', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
        register_setting('hozio_naming_settings', 'hozio_naming_template', array(
            'sanitize_callback' => 'sanitize_text_field',
        ));
        register_setting('hozio_naming_settings', 'hozio_title_template', array(
            'sanitize_callback' => 'sanitize_text_field',
        ));
        register_setting('hozio_naming_settings', 'hozio_max_filename_length', array(
            'sanitize_callback' => 'absint',
        ));
        register_setting('hozio_naming_settings', 'hozio_keyword_word_count', array(
            'sanitize_callback' => 'absint',
        ));

        // Geolocation Settings (NEW TAB)
        register_setting('hozio_geolocation_settings', 'hozio_enable_geolocation', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
        register_setting('hozio_geolocation_settings', 'hozio_custom_locations', array(
            'sanitize_callback' => array($this, 'sanitize_custom_locations'),
        ));

        // Auto-Optimize Settings (NEW TAB)
        register_setting('hozio_auto_optimize_settings', 'hozio_auto_optimize_on_upload', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
        register_setting('hozio_auto_optimize_settings', 'hozio_auto_ai_rename', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
        register_setting('hozio_auto_optimize_settings', 'hozio_default_upload_location', array(
            'sanitize_callback' => 'sanitize_text_field',
        ));
        register_setting('hozio_auto_optimize_settings', 'hozio_default_upload_lat', array(
            'sanitize_callback' => array($this, 'sanitize_coordinate'),
        ));
        register_setting('hozio_auto_optimize_settings', 'hozio_default_upload_lng', array(
            'sanitize_callback' => array($this, 'sanitize_coordinate'),
        ));

        // Backup Settings
        register_setting('hozio_backup_settings', 'hozio_backup_enabled', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
        register_setting('hozio_backup_settings', 'hozio_backup_retention_days', array(
            'sanitize_callback' => 'absint',
        ));
        register_setting('hozio_backup_settings', 'hozio_validate_after_operation', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
        ));
    }

    /**
     * Sanitize a coordinate value
     */
    public function sanitize_coordinate($value) {
        if (empty($value)) {
            return '';
        }
        return floatval($value);
    }

    /**
     * Sanitize custom locations array
     */
    public function sanitize_custom_locations($value) {
        if (!is_array($value)) {
            return array();
        }

        $sanitized = array();
        foreach ($value as $location) {
            if (isset($location['name']) && isset($location['lat']) && isset($location['lng'])) {
                $sanitized[] = array(
                    'name' => sanitize_text_field($location['name']),
                    'lat' => floatval($location['lat']),
                    'lng' => floatval($location['lng']),
                );
            }
        }

        return $sanitized;
    }

    /**
     * Get settings tabs
     */
    public static function get_tabs() {
        return array(
            'api' => array(
                'title' => __('AI Configuration', 'hozio-image-optimizer'),
                'icon' => 'dashicons-cloud',
            ),
            'compression' => array(
                'title' => __('Compression', 'hozio-image-optimizer'),
                'icon' => 'dashicons-images-alt2',
            ),
            'format' => array(
                'title' => __('Format Conversion', 'hozio-image-optimizer'),
                'icon' => 'dashicons-format-image',
            ),
            'naming' => array(
                'title' => __('Naming', 'hozio-image-optimizer'),
                'icon' => 'dashicons-edit',
            ),
            'geolocation' => array(
                'title' => __('Geolocation', 'hozio-image-optimizer'),
                'icon' => 'dashicons-location-alt',
            ),
            'auto_optimize' => array(
                'title' => __('Auto-Optimize', 'hozio-image-optimizer'),
                'icon' => 'dashicons-upload',
            ),
            'backup' => array(
                'title' => __('Backup & Safety', 'hozio-image-optimizer'),
                'icon' => 'dashicons-backup',
            ),
            'usage' => array(
                'title' => __('API Usage', 'hozio-image-optimizer'),
                'icon' => 'dashicons-chart-area',
            ),
            'license' => array(
                'title' => __('License & Updates', 'hozio-image-optimizer'),
                'icon' => 'dashicons-admin-network',
            ),
        );
    }

    /**
     * Get current tab
     */
    public static function get_current_tab() {
        return isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';
    }

    /**
     * Get per-tab status for sidebar chips.
     * Levels: ok | warn | err | off. label is a short human hint.
     */
    public static function get_tab_statuses() {
        $api_key = get_option('hozio_openai_api_key', '');
        $has_api = !empty($api_key);

        $compression_on = (bool) get_option('hozio_enable_compression', true);
        $webp_on        = (bool) get_option('hozio_convert_to_webp', false);
        $avif_on        = (bool) get_option('hozio_convert_to_avif', false);
        $geo_on         = (bool) get_option('hozio_enable_geolocation', false);
        $auto_on        = (bool) get_option('hozio_auto_optimize_on_upload', false);
        $auto_ai        = (bool) get_option('hozio_auto_ai_rename', false);
        $backup_on      = (bool) get_option('hozio_backup_enabled', true);
        $retention      = (int) get_option('hozio_backup_retention_days', 30);

        $license_key = get_option('hozio_license_key', '');
        $licensed    = !empty($license_key);

        // Auto-optimize warns if AI rename is on but no API key
        $auto_level = 'off';
        $auto_label = __('Disabled', 'hozio-image-optimizer');
        if ($auto_on) {
            if ($auto_ai && !$has_api) {
                $auto_level = 'warn';
                $auto_label = __('API key missing', 'hozio-image-optimizer');
            } else {
                $auto_level = 'ok';
                $auto_label = __('Enabled', 'hozio-image-optimizer');
            }
        }

        $format_level = 'off';
        $format_label = __('Original only', 'hozio-image-optimizer');
        if ($webp_on && $avif_on) {
            $format_level = 'ok';
            $format_label = 'WebP + AVIF';
        } elseif ($webp_on) {
            $format_level = 'ok';
            $format_label = 'WebP';
        } elseif ($avif_on) {
            $format_level = 'ok';
            $format_label = 'AVIF';
        }

        return array(
            'api'           => $has_api
                ? array('level' => 'ok',   'label' => __('Connected', 'hozio-image-optimizer'))
                : array('level' => 'err',  'label' => __('No API key', 'hozio-image-optimizer')),
            'compression'   => $compression_on
                ? array('level' => 'ok',   'label' => (int) get_option('hozio_compression_quality', 82) . '%')
                : array('level' => 'off',  'label' => __('Disabled', 'hozio-image-optimizer')),
            'format'        => array('level' => $format_level, 'label' => $format_label),
            'naming'        => (bool) get_option('hozio_enable_ai_rename', true)
                ? array('level' => $has_api ? 'ok' : 'warn', 'label' => $has_api ? __('Configured', 'hozio-image-optimizer') : __('Needs API key', 'hozio-image-optimizer'))
                : array('level' => 'off',  'label' => __('AI rename off', 'hozio-image-optimizer')),
            'geolocation'   => $geo_on
                ? array('level' => 'ok',   'label' => __('Embedding GPS', 'hozio-image-optimizer'))
                : array('level' => 'off',  'label' => __('Disabled', 'hozio-image-optimizer')),
            'auto_optimize' => array('level' => $auto_level, 'label' => $auto_label),
            'backup'        => $backup_on
                ? array('level' => 'ok',   'label' => sprintf(__('On, %dd', 'hozio-image-optimizer'), $retention))
                : array('level' => 'warn', 'label' => __('Disabled', 'hozio-image-optimizer')),
            'usage'         => array('level' => 'ok',   'label' => null),
            'license'       => $licensed
                ? array('level' => 'ok',   'label' => __('Licensed', 'hozio-image-optimizer'))
                : array('level' => 'warn', 'label' => __('Unlicensed', 'hozio-image-optimizer')),
        );
    }

    /**
     * Get localized status level label ("OK", "WARN", "ERR", "OFF").
     */
    public static function get_status_chip_label($level) {
        switch ($level) {
            case 'ok':   return __('OK', 'hozio-image-optimizer');
            case 'warn': return __('WARN', 'hozio-image-optimizer');
            case 'err':  return __('ERR', 'hozio-image-optimizer');
            default:     return __('OFF', 'hozio-image-optimizer');
        }
    }

    /**
     * Render a field
     */
    public static function render_field($args) {
        $type = $args['type'] ?? 'text';
        $name = $args['name'];
        $value = get_option($name, $args['default'] ?? '');
        $description = $args['description'] ?? '';

        switch ($type) {
            case 'text':
            case 'password':
                $input_type = $type === 'password' ? 'password' : 'text';
                printf(
                    '<input type="%s" name="%s" id="%s" value="%s" class="regular-text" %s />',
                    esc_attr($input_type),
                    esc_attr($name),
                    esc_attr($name),
                    esc_attr($value),
                    isset($args['placeholder']) ? 'placeholder="' . esc_attr($args['placeholder']) . '"' : ''
                );
                break;

            case 'number':
                printf(
                    '<input type="number" name="%s" id="%s" value="%s" class="small-text" min="%s" max="%s" step="%s" />',
                    esc_attr($name),
                    esc_attr($name),
                    esc_attr($value),
                    esc_attr($args['min'] ?? 0),
                    esc_attr($args['max'] ?? 9999),
                    esc_attr($args['step'] ?? 1)
                );
                if (isset($args['suffix'])) {
                    echo ' <span class="description">' . esc_html($args['suffix']) . '</span>';
                }
                break;

            case 'checkbox':
                printf(
                    '<label><input type="checkbox" name="%s" id="%s" value="1" %s /> %s</label>',
                    esc_attr($name),
                    esc_attr($name),
                    checked($value, true, false),
                    isset($args['label']) ? esc_html($args['label']) : ''
                );
                break;

            case 'select':
                printf('<select name="%s" id="%s">', esc_attr($name), esc_attr($name));
                foreach ($args['options'] as $option_value => $option_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($option_value),
                        selected($value, $option_value, false),
                        esc_html($option_label)
                    );
                }
                echo '</select>';
                break;

            case 'range':
                printf(
                    '<input type="range" name="%s" id="%s" value="%s" min="%s" max="%s" step="%s" oninput="document.getElementById(\'%s_value\').textContent=this.value" />',
                    esc_attr($name),
                    esc_attr($name),
                    esc_attr($value),
                    esc_attr($args['min'] ?? 0),
                    esc_attr($args['max'] ?? 100),
                    esc_attr($args['step'] ?? 1),
                    esc_attr($name)
                );
                printf(
                    ' <span id="%s_value" class="range-value">%s</span>',
                    esc_attr($name),
                    esc_html($value)
                );
                if (isset($args['suffix'])) {
                    echo '<span class="description">' . esc_html($args['suffix']) . '</span>';
                }
                break;
        }

        if ($description) {
            echo '<p class="description">' . wp_kses_post($description) . '</p>';
        }
    }

    /**
     * Sanitize API key - preserve existing if new one is empty
     */
    public function sanitize_api_key($new_value) {
        $new_value = sanitize_text_field($new_value);

        // If new value is empty, preserve the existing key
        if (empty($new_value)) {
            $existing_key = get_option('hozio_openai_api_key', '');
            if (!empty($existing_key)) {
                return $existing_key;
            }
        }

        return $new_value;
    }
}
