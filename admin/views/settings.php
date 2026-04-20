<?php
/**
 * Settings page view with modern UI design
 */

if (!defined('ABSPATH')) {
    exit;
}

$tabs = Hozio_Image_Optimizer_Settings::get_tabs();
$current_tab = Hozio_Image_Optimizer_Settings::get_current_tab();
$tab_statuses = Hozio_Image_Optimizer_Settings::get_tab_statuses();

// Server capabilities for display
$capabilities = Hozio_Image_Optimizer::get_server_capabilities();

// System status strip values
$usage_stats = get_option('hozio_api_usage_stats', array());
$system_stats = array(
    'api_requests' => isset($usage_stats['total_requests']) ? (int) $usage_stats['total_requests'] : 0,
    'api_cost'     => isset($usage_stats['estimated_cost']) ? (float) $usage_stats['estimated_cost'] : 0,
    'quality'      => (int) get_option('hozio_compression_quality', 82),
    'max_dim'      => (int) get_option('hozio_max_width', 2048),
    'retention'    => (int) get_option('hozio_backup_retention_days', 30),
    'model'        => get_option('hozio_openai_model', 'gpt-4o'),
);
?>

<div class="wrap hozio-settings-page hz-v2">

    <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
    <div class="hz-saved-banner" id="hz-saved-banner" role="status" aria-live="polite">
        <span class="dashicons dashicons-yes-alt"></span>
        <?php esc_html_e( 'Settings saved successfully.', 'hozio-image-optimizer' ); ?>
    </div>
    <?php endif; ?>

    <!-- Top Bar -->
    <div class="hz-topbar">
        <div class="hz-topbar-left">
            <span class="hz-logo-wrap"><img src="<?php echo esc_url(HOZIO_IMAGE_OPTIMIZER_URL . 'assets/images/logo.png'); ?>" alt="Hozio" class="hz-logo-img"></span>
            <div class="hz-topbar-title">
                <span class="hz-title"><?php esc_html_e('Settings', 'hozio-image-optimizer'); ?></span>
                <span class="hz-version">v<?php echo esc_html(HOZIO_IMAGE_OPTIMIZER_VERSION); ?></span>
            </div>
        </div>
        <div class="hz-topbar-right">
            <button type="button" class="hz-palette-trigger" id="hz-palette-open" title="<?php esc_attr_e('Search settings', 'hozio-image-optimizer'); ?>">
                <span class="dashicons dashicons-search"></span>
                <span class="hz-palette-trigger-label"><?php esc_html_e('Search settings', 'hozio-image-optimizer'); ?></span>
                <span class="hz-kbd"><?php echo esc_html(strpos(strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 'mac') !== false ? '&#8984;K' : 'Ctrl K'); ?></span>
            </button>
            <a href="<?php echo esc_url(admin_url('upload.php?page=hozio-image-optimizer')); ?>" class="hz-nav-link">
                <span class="dashicons dashicons-images-alt2"></span> <?php esc_html_e('Optimizer', 'hozio-image-optimizer'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('upload.php?page=hozio-image-backups')); ?>" class="hz-nav-link">
                <span class="dashicons dashicons-backup"></span> <?php esc_html_e('Management', 'hozio-image-optimizer'); ?>
            </a>
        </div>
    </div>

    <!-- System Status Strip (Grafana-style compact metrics) -->
    <div class="hz-status-strip" role="group" aria-label="<?php esc_attr_e('System status', 'hozio-image-optimizer'); ?>">
        <div class="hz-stat">
            <span class="hz-stat-label"><?php esc_html_e('API calls', 'hozio-image-optimizer'); ?></span>
            <span class="hz-stat-value"><?php echo esc_html(number_format_i18n($system_stats['api_requests'])); ?></span>
        </div>
        <div class="hz-stat">
            <span class="hz-stat-label"><?php esc_html_e('Est. cost', 'hozio-image-optimizer'); ?></span>
            <span class="hz-stat-value">$<?php echo esc_html(number_format($system_stats['api_cost'], 2)); ?></span>
        </div>
        <div class="hz-stat">
            <span class="hz-stat-label"><?php esc_html_e('Model', 'hozio-image-optimizer'); ?></span>
            <span class="hz-stat-value hz-stat-mono"><?php echo esc_html($system_stats['model']); ?></span>
        </div>
        <div class="hz-stat">
            <span class="hz-stat-label"><?php esc_html_e('Quality', 'hozio-image-optimizer'); ?></span>
            <span class="hz-stat-value"><?php echo esc_html($system_stats['quality']); ?>%</span>
        </div>
        <div class="hz-stat">
            <span class="hz-stat-label"><?php esc_html_e('Max dim', 'hozio-image-optimizer'); ?></span>
            <span class="hz-stat-value"><?php echo esc_html($system_stats['max_dim']); ?>px</span>
        </div>
        <div class="hz-stat">
            <span class="hz-stat-label"><?php esc_html_e('Backups', 'hozio-image-optimizer'); ?></span>
            <span class="hz-stat-value"><?php echo esc_html($system_stats['retention']); ?>d</span>
        </div>
    </div>

    <!-- Main Shell: Sidebar + Content -->
    <div class="hz-shell">

        <!-- Sidebar Navigation -->
        <aside class="hz-sidebar" aria-label="<?php esc_attr_e('Settings navigation', 'hozio-image-optimizer'); ?>">
            <nav class="hz-sidebar-nav">
                <?php foreach ($tabs as $tab_id => $tab) :
                    $status = isset($tab_statuses[$tab_id]) ? $tab_statuses[$tab_id] : array('level' => 'off', 'label' => null);
                    $is_active = $current_tab === $tab_id;
                    ?>
                    <a href="<?php echo esc_url(add_query_arg('tab', $tab_id)); ?>"
                       class="hz-sidenav-item <?php echo $is_active ? 'active' : ''; ?>"
                       id="hz-sidenav-<?php echo esc_attr($tab_id); ?>"
                       data-tab="<?php echo esc_attr($tab_id); ?>">
                        <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
                        <span class="hz-sidenav-label"><?php echo esc_html($tab['title']); ?></span>
                        <span class="hz-chip hz-chip-<?php echo esc_attr($status['level']); ?>" title="<?php echo esc_attr($status['label'] ?? ''); ?>">
                            <?php echo esc_html(Hozio_Image_Optimizer_Settings::get_status_chip_label($status['level'])); ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="hz-sidebar-foot">
                <button type="button" class="hz-palette-trigger hz-palette-trigger-mini" id="hz-palette-open-mini">
                    <span class="dashicons dashicons-search"></span>
                    <span><?php esc_html_e('Quick find', 'hozio-image-optimizer'); ?></span>
                    <span class="hz-kbd"><?php echo esc_html(strpos(strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 'mac') !== false ? '&#8984;K' : 'Ctrl K'); ?></span>
                </button>
            </div>
        </aside>

        <!-- Settings Content Panels -->
        <div class="hz-settings-content">
            <div class="hozio-settings-main">
                <?php /* Tab: api */ ?>
                <div class="hz-tab-panel <?php echo $current_tab === 'api' ? 'hz-active' : ''; ?>" data-tab="api" id="hz-tab-api" role="tabpanel" aria-labelledby="hz-sidenav-api">
                <!-- AI Configuration Tab -->
                <form method="post" action="options.php" class="hozio-settings-form">
                    <?php settings_fields('hozio_api_settings'); ?>

                    <!-- API Configuration Card -->
                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon api">
                                <span class="dashicons dashicons-admin-network"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('API Configuration', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Connect to OpenAI for AI-powered image analysis', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <div class="hozio-field-group">
                                <label class="hozio-field-label">
                                    <?php esc_html_e('OpenAI API Key', 'hozio-image-optimizer'); ?>
                                    <span class="required">*</span>
                                </label>
                                <?php
                                $api_key = get_option('hozio_openai_api_key', '');
                                $has_key = !empty($api_key);
                                ?>

                                <?php if ($has_key) : ?>
                                    <!-- Key is configured - show secure status -->
                                    <div class="hozio-api-key-status" id="api-key-status">
                                        <div class="api-key-secure">
                                            <span class="secure-icon">
                                                <span class="dashicons dashicons-lock"></span>
                                            </span>
                                            <span class="secure-text"><?php esc_html_e('API Key Configured', 'hozio-image-optimizer'); ?></span>
                                            <span class="key-preview">••••••••<?php echo esc_html(substr($api_key, -4)); ?></span>
                                        </div>
                                        <button type="button" id="change-api-key-btn" class="hozio-btn hozio-btn-outline hozio-btn-sm">
                                            <span class="dashicons dashicons-edit"></span>
                                            <?php esc_html_e('Change Key', 'hozio-image-optimizer'); ?>
                                        </button>
                                    </div>

                                    <!-- Hidden input form for changing key -->
                                    <div class="hozio-api-key-input" id="api-key-input" style="display: none;">
                                        <div class="hozio-input-group">
                                            <input type="password"
                                                   id="hozio_openai_api_key"
                                                   name="hozio_openai_api_key"
                                                   value=""
                                                   class="hozio-input"
                                                   placeholder="sk-..."
                                                   autocomplete="off">
                                        </div>
                                        <div class="api-key-actions">
                                            <button type="button" id="cancel-change-key-btn" class="hozio-btn hozio-btn-outline hozio-btn-sm">
                                                <?php esc_html_e('Cancel', 'hozio-image-optimizer'); ?>
                                            </button>
                                            <p class="hozio-field-hint"><?php esc_html_e('Enter a new API key to replace the current one', 'hozio-image-optimizer'); ?></p>
                                        </div>
                                    </div>
                                    <!-- Hidden field to preserve existing key if not changed -->
                                    <input type="hidden" id="hozio_openai_api_key_existing" name="hozio_openai_api_key_preserve" value="1">
                                <?php else : ?>
                                    <!-- No key configured - show input -->
                                    <div class="hozio-api-key-status not-configured" id="api-key-status">
                                        <div class="api-key-warning">
                                            <span class="warning-icon">
                                                <span class="dashicons dashicons-warning"></span>
                                            </span>
                                            <span class="warning-text"><?php esc_html_e('No API Key Configured', 'hozio-image-optimizer'); ?></span>
                                        </div>
                                    </div>
                                    <div class="hozio-api-key-input" id="api-key-input">
                                        <div class="hozio-input-group">
                                            <input type="password"
                                                   id="hozio_openai_api_key"
                                                   name="hozio_openai_api_key"
                                                   value=""
                                                   class="hozio-input"
                                                   placeholder="sk-..."
                                                   autocomplete="off">
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <p class="hozio-field-hint">
                                    <?php printf(
                                        esc_html__('Get your API key from %s', 'hozio-image-optimizer'),
                                        '<a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>'
                                    ); ?>
                                </p>
                                <div class="hozio-api-test">
                                    <button type="button" id="test-api-btn" class="hozio-btn hozio-btn-secondary">
                                        <span class="dashicons dashicons-admin-plugins"></span>
                                        <?php esc_html_e('Test Connection', 'hozio-image-optimizer'); ?>
                                    </button>
                                    <span id="api-test-result" class="api-result"></span>
                                </div>
                            </div>

                            <div class="hozio-field-group">
                                <label for="hozio_openai_model" class="hozio-field-label">
                                    <?php esc_html_e('AI Model', 'hozio-image-optimizer'); ?>
                                </label>
                                <div class="hozio-model-select">
                                    <?php
                                    $current_model = get_option('hozio_openai_model', 'gpt-4o');
                                    $models = array(
                                        'gpt-4.1' => array(
                                            'name' => 'GPT-4.1',
                                            'desc' => 'Latest model, best quality & speed',
                                            'badge' => 'Recommended',
                                        ),
                                        'gpt-4.1-mini' => array(
                                            'name' => 'GPT-4.1 Mini',
                                            'desc' => 'Fast & affordable, great quality',
                                            'badge' => 'Best Value',
                                        ),
                                        'gpt-4.1-nano' => array(
                                            'name' => 'GPT-4.1 Nano',
                                            'desc' => 'Ultra-fast, lowest cost',
                                            'badge' => 'Budget',
                                        ),
                                        'gpt-4o' => array(
                                            'name' => 'GPT-4o',
                                            'desc' => 'Previous gen, excellent quality',
                                            'badge' => '',
                                        ),
                                        'gpt-4o-mini' => array(
                                            'name' => 'GPT-4o Mini',
                                            'desc' => 'Previous gen, affordable',
                                            'badge' => '',
                                        ),
                                    );
                                    foreach ($models as $model_id => $model) :
                                    ?>
                                        <label class="hozio-model-option <?php echo $current_model === $model_id ? 'selected' : ''; ?>">
                                            <input type="radio" name="hozio_openai_model" value="<?php echo esc_attr($model_id); ?>"
                                                   <?php checked($current_model, $model_id); ?>>
                                            <div class="model-content">
                                                <span class="model-name"><?php echo esc_html($model['name']); ?></span>
                                                <span class="model-desc"><?php echo esc_html($model['desc']); ?></span>
                                                <?php if ($model['badge']) : ?>
                                                    <span class="model-badge"><?php echo esc_html($model['badge']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="model-check">
                                                <span class="dashicons dashicons-yes-alt"></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AI Features Card -->
                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon features">
                                <span class="dashicons dashicons-lightbulb"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('AI Features', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Enable or disable specific AI capabilities', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <div class="hozio-features-grid">
                                <?php
                                $features = array(
                                    'hozio_enable_ai_rename' => array(
                                        'label' => __('AI Renaming', 'hozio-image-optimizer'),
                                        'desc' => __('Generate SEO-friendly filenames', 'hozio-image-optimizer'),
                                        'icon' => 'dashicons-edit',
                                        'default' => true,
                                    ),
                                    'hozio_enable_ai_alt_text' => array(
                                        'label' => __('Alt Text Generation', 'hozio-image-optimizer'),
                                        'desc' => __('Create accessible alt text', 'hozio-image-optimizer'),
                                        'icon' => 'dashicons-universal-access-alt',
                                        'default' => true,
                                    ),
                                    'hozio_enable_ai_caption' => array(
                                        'label' => __('Caption Generation', 'hozio-image-optimizer'),
                                        'desc' => __('Generate engaging image captions', 'hozio-image-optimizer'),
                                        'icon' => 'dashicons-format-quote',
                                        'default' => true,
                                    ),
                                    'hozio_enable_ai_tagging' => array(
                                        'label' => __('Image Tagging', 'hozio-image-optimizer'),
                                        'desc' => __('Auto-generate relevant tags', 'hozio-image-optimizer'),
                                        'icon' => 'dashicons-tag',
                                        'default' => false,
                                    ),
                                );
                                foreach ($features as $key => $feature) :
                                    $checked = get_option($key, $feature['default']);
                                ?>
                                    <label class="hozio-feature-toggle">
                                        <div class="feature-info">
                                            <span class="dashicons <?php echo esc_attr($feature['icon']); ?>"></span>
                                            <div>
                                                <span class="feature-label"><?php echo esc_html($feature['label']); ?></span>
                                                <span class="feature-desc"><?php echo esc_html($feature['desc']); ?></span>
                                            </div>
                                        </div>
                                        <div class="hozio-toggle">
                                            <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked($checked); ?>>
                                            <span class="toggle-slider"></span>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- AI Prompts Card -->
                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon prompts">
                                <span class="dashicons dashicons-editor-code"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('Custom AI Prompts', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Customize the instructions given to AI for each field. Leave blank for defaults.', 'hozio-image-optimizer'); ?></p>
                            </div>
                            <button type="button" class="hozio-btn hozio-btn-text toggle-prompts">
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                                <?php esc_html_e('Expand', 'hozio-image-optimizer'); ?>
                            </button>
                        </div>
                        <div class="hozio-card-body hozio-prompts-section" style="display: none;">
                            <?php
                            $prompts = array(
                                'hozio_prompt_filename' => array(
                                    'label' => __('Filename Prompt', 'hozio-image-optimizer'),
                                    'default_value' => 'Generate an SEO-friendly filename for this image. Use 3-6 descriptive words that include the main subject and context. Format: lowercase, hyphen-separated, no file extension. If a keyword is provided, incorporate it naturally. If a location is provided, include it at the end.',
                                    'hint' => __('Variables: {keyword}, {location}', 'hozio-image-optimizer'),
                                ),
                                'hozio_prompt_title' => array(
                                    'label' => __('Title Prompt', 'hozio-image-optimizer'),
                                    'default_value' => 'Create a descriptive title for this image using 5-10 words with proper capitalization. Include the main subject and any relevant context. If a keyword is provided, incorporate it naturally for SEO. Make it readable and engaging.',
                                    'hint' => __('Variables: {keyword}, {location}', 'hozio-image-optimizer'),
                                ),
                                'hozio_prompt_alt_text' => array(
                                    'label' => __('Alt Text Prompt', 'hozio-image-optimizer'),
                                    'default_value' => 'Write concise, descriptive alt text for this image (max 125 characters). Describe what is shown in the image for accessibility purposes. Do not start with "Image of" or "Picture of". Include relevant context and keywords naturally.',
                                    'hint' => __('Variables: {keyword}, {location}', 'hozio-image-optimizer'),
                                ),
                                'hozio_prompt_caption' => array(
                                    'label' => __('Caption Prompt', 'hozio-image-optimizer'),
                                    'default_value' => 'Write a brief, engaging caption for this image in 1-2 sentences. The tone should be conversational but professional. Describe what makes this image interesting or relevant. Include the location or keyword context if provided.',
                                    'hint' => __('Variables: {keyword}, {location}', 'hozio-image-optimizer'),
                                ),
                                'hozio_prompt_description' => array(
                                    'label' => __('Description Prompt', 'hozio-image-optimizer'),
                                    'default_value' => 'Write a detailed description for this image in 2-4 sentences. Include relevant keywords naturally for SEO purposes. Describe the main subject, setting, and any notable details. If a keyword or location is provided, incorporate them contextually.',
                                    'hint' => __('Variables: {keyword}, {location}', 'hozio-image-optimizer'),
                                ),
                            );
                            foreach ($prompts as $key => $prompt) :
                                $saved_value = get_option($key, '');
                                $display_value = !empty($saved_value) ? $saved_value : $prompt['default_value'];
                            ?>
                                <div class="hozio-field-group">
                                    <label for="<?php echo esc_attr($key); ?>" class="hozio-field-label">
                                        <?php echo esc_html($prompt['label']); ?>
                                    </label>
                                    <textarea name="<?php echo esc_attr($key); ?>"
                                              id="<?php echo esc_attr($key); ?>"
                                              rows="3"
                                              class="hozio-textarea"><?php echo esc_textarea($display_value); ?></textarea>
                                    <p class="hozio-field-hint"><?php echo esc_html($prompt['hint']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="hozio-form-actions">
                        <?php submit_button(__('Save Settings', 'hozio-image-optimizer'), 'hozio-btn hozio-btn-primary', 'submit', false); ?>
                    </div>
                </form>

                </div><?php /* /panel api */ ?>

                <?php /* Tab: compression */ ?>
                <div class="hz-tab-panel <?php echo $current_tab === 'compression' ? 'hz-active' : ''; ?>" data-tab="compression" id="hz-tab-compression" role="tabpanel" aria-labelledby="hz-sidenav-compression">
                <!-- Compression Tab -->
                <form method="post" action="options.php" class="hozio-settings-form">
                    <?php settings_fields('hozio_compression_settings'); ?>

                    <!-- Compression Settings Card -->
                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon compression">
                                <span class="dashicons dashicons-image-filter"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('Compression Settings', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Optimize image file sizes while maintaining quality', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <label class="hozio-feature-toggle single">
                                <div class="feature-info">
                                    <span class="dashicons dashicons-performance"></span>
                                    <div>
                                        <span class="feature-label"><?php esc_html_e('Enable Compression', 'hozio-image-optimizer'); ?></span>
                                        <span class="feature-desc"><?php esc_html_e('Compress images to reduce file size', 'hozio-image-optimizer'); ?></span>
                                    </div>
                                </div>
                                <div class="hozio-toggle">
                                    <input type="checkbox" name="hozio_enable_compression" value="1" <?php checked(get_option('hozio_enable_compression', true)); ?>>
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>

                            <div class="hozio-field-group">
                                <label class="hozio-field-label">
                                    <?php esc_html_e('Compression Quality', 'hozio-image-optimizer'); ?>
                                </label>
                                <div class="hozio-range-container">
                                    <input type="range"
                                           name="hozio_compression_quality"
                                           min="50" max="100"
                                           value="<?php echo esc_attr(get_option('hozio_compression_quality', 82)); ?>"
                                           class="hozio-range">
                                    <div class="range-labels">
                                        <span>Smaller Files</span>
                                        <span class="range-value"><span id="quality-value"><?php echo esc_html(get_option('hozio_compression_quality', 82)); ?></span>%</span>
                                        <span>Higher Quality</span>
                                    </div>
                                </div>
                                <p class="hozio-field-hint"><?php esc_html_e('82% is recommended for most cases', 'hozio-image-optimizer'); ?></p>
                            </div>

                            <div class="hozio-field-row">
                                <div class="hozio-field-group half">
                                    <label for="hozio_max_width" class="hozio-field-label">
                                        <?php esc_html_e('Maximum Width', 'hozio-image-optimizer'); ?>
                                    </label>
                                    <div class="hozio-input-addon">
                                        <input type="number"
                                               id="hozio_max_width"
                                               name="hozio_max_width"
                                               min="100" max="6000"
                                               value="<?php echo esc_attr(get_option('hozio_max_width', 2048)); ?>"
                                               class="hozio-input">
                                        <span class="addon">px</span>
                                    </div>
                                </div>
                                <div class="hozio-field-group half">
                                    <label for="hozio_max_height" class="hozio-field-label">
                                        <?php esc_html_e('Maximum Height', 'hozio-image-optimizer'); ?>
                                    </label>
                                    <div class="hozio-input-addon">
                                        <input type="number"
                                               id="hozio_max_height"
                                               name="hozio_max_height"
                                               min="100" max="6000"
                                               value="<?php echo esc_attr(get_option('hozio_max_height', 2048)); ?>"
                                               class="hozio-input">
                                        <span class="addon">px</span>
                                    </div>
                                </div>
                            </div>
                            <p class="hozio-field-hint" style="margin-top: -10px;"><?php esc_html_e('Images larger than these dimensions will be resized', 'hozio-image-optimizer'); ?></p>

                            <label class="hozio-feature-toggle single">
                                <div class="feature-info">
                                    <span class="dashicons dashicons-database-remove"></span>
                                    <div>
                                        <span class="feature-label"><?php esc_html_e('Strip Metadata', 'hozio-image-optimizer'); ?></span>
                                        <span class="feature-desc"><?php esc_html_e('Remove EXIF data (saves ~10-20% file size)', 'hozio-image-optimizer'); ?></span>
                                        <span class="feature-desc" style="color:#f59e0b;margin-top:2px;"><?php esc_html_e('Warning: This will also remove GPS coordinates from images', 'hozio-image-optimizer'); ?></span>
                                    </div>
                                </div>
                                <div class="hozio-toggle">
                                    <input type="checkbox" name="hozio_strip_metadata" value="1" <?php checked(get_option('hozio_strip_metadata', false)); ?>>
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Server Capabilities Card -->
                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon server">
                                <span class="dashicons dashicons-cloud"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('Server Capabilities', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Your server\'s image processing capabilities', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <div class="hozio-capabilities-grid">
                                <div class="capability-item <?php echo $capabilities['gd'] ? 'available' : 'unavailable'; ?>">
                                    <span class="cap-status">
                                        <span class="dashicons <?php echo $capabilities['gd'] ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                                    </span>
                                    <span class="cap-name"><?php esc_html_e('GD Library', 'hozio-image-optimizer'); ?></span>
                                    <span class="cap-desc"><?php echo $capabilities['gd'] ? __('Required', 'hozio-image-optimizer') : __('Missing', 'hozio-image-optimizer'); ?></span>
                                </div>
                                <div class="capability-item <?php echo $capabilities['imagick'] ? 'available' : 'optional'; ?>">
                                    <span class="cap-status">
                                        <span class="dashicons <?php echo $capabilities['imagick'] ? 'dashicons-yes' : 'dashicons-minus'; ?>"></span>
                                    </span>
                                    <span class="cap-name"><?php esc_html_e('ImageMagick', 'hozio-image-optimizer'); ?></span>
                                    <span class="cap-desc"><?php echo $capabilities['imagick'] ? __('Better quality', 'hozio-image-optimizer') : __('Optional', 'hozio-image-optimizer'); ?></span>
                                </div>
                                <div class="capability-item <?php echo $capabilities['exiftool'] ? 'available' : 'optional'; ?>">
                                    <span class="cap-status">
                                        <span class="dashicons <?php echo $capabilities['exiftool'] ? 'dashicons-yes' : 'dashicons-minus'; ?>"></span>
                                    </span>
                                    <span class="cap-name"><?php esc_html_e('ExifTool', 'hozio-image-optimizer'); ?></span>
                                    <span class="cap-desc"><?php
                                        if ($capabilities['exiftool'] === 'bundled') {
                                            esc_html_e('Bundled (GPS+WebP)', 'hozio-image-optimizer');
                                        } elseif ($capabilities['exiftool']) {
                                            esc_html_e('System (GPS+WebP)', 'hozio-image-optimizer');
                                        } else {
                                            esc_html_e('Need Perl', 'hozio-image-optimizer');
                                        }
                                    ?></span>
                                </div>
                                <div class="capability-item available">
                                    <span class="cap-status">
                                        <span class="dashicons dashicons-dashboard"></span>
                                    </span>
                                    <span class="cap-name"><?php esc_html_e('Memory Limit', 'hozio-image-optimizer'); ?></span>
                                    <span class="cap-desc"><?php echo esc_html($capabilities['memory_limit']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hozio-form-actions">
                        <?php submit_button(__('Save Settings', 'hozio-image-optimizer'), 'hozio-btn hozio-btn-primary', 'submit', false); ?>
                    </div>
                </form>

                </div><?php /* /panel compression */ ?>

                <?php /* Tab: format */ ?>
                <div class="hz-tab-panel <?php echo $current_tab === 'format' ? 'hz-active' : ''; ?>" data-tab="format" id="hz-tab-format" role="tabpanel" aria-labelledby="hz-sidenav-format">
                <!-- Format Conversion Tab -->
                <form method="post" action="options.php" class="hozio-settings-form">
                    <?php settings_fields('hozio_format_settings'); ?>

                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon format">
                                <span class="dashicons dashicons-format-image"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('Format Conversion', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Convert images to modern, efficient formats', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <?php
                            $webp_supported = Hozio_Image_Optimizer_Format_Converter::webp_supported();
                            $avif_supported = Hozio_Image_Optimizer_Format_Converter::avif_supported();
                            ?>

                            <div class="hozio-format-options">
                                <label class="hozio-format-option <?php echo !$webp_supported ? 'disabled' : ''; ?>">
                                    <div class="format-header">
                                        <div class="format-icon webp">WebP</div>
                                        <div class="hozio-toggle">
                                            <input type="checkbox" name="hozio_convert_to_webp" value="1"
                                                   <?php checked(get_option('hozio_convert_to_webp', true)); ?>
                                                   <?php disabled(!$webp_supported); ?>>
                                            <span class="toggle-slider"></span>
                                        </div>
                                    </div>
                                    <div class="format-details">
                                        <span class="format-savings">25-35% smaller</span>
                                        <span class="format-compat">~97% browser support</span>
                                    </div>
                                    <?php if (!$webp_supported) : ?>
                                        <span class="format-warning"><?php esc_html_e('Not supported on this server', 'hozio-image-optimizer'); ?></span>
                                    <?php endif; ?>
                                </label>

                                <label class="hozio-format-option <?php echo !$avif_supported ? 'disabled' : ''; ?>">
                                    <div class="format-header">
                                        <div class="format-icon avif">AVIF</div>
                                        <div class="hozio-toggle">
                                            <input type="checkbox" name="hozio_convert_to_avif" value="1"
                                                   <?php checked(get_option('hozio_convert_to_avif', false)); ?>
                                                   <?php disabled(!$avif_supported); ?>>
                                            <span class="toggle-slider"></span>
                                        </div>
                                    </div>
                                    <div class="format-details">
                                        <span class="format-savings">50%+ smaller</span>
                                        <span class="format-compat">~92% browser support</span>
                                    </div>
                                    <?php if (!$avif_supported) : ?>
                                        <span class="format-warning"><?php esc_html_e('Requires PHP 8.1+', 'hozio-image-optimizer'); ?></span>
                                    <?php endif; ?>
                                </label>
                            </div>

                            <div class="hozio-field-group" style="margin-top: 20px;">
                                <label class="hozio-field-label">
                                    <?php esc_html_e('WebP Quality', 'hozio-image-optimizer'); ?>
                                </label>
                                <div class="hozio-range-container">
                                    <input type="range"
                                           name="hozio_webp_quality"
                                           min="50" max="100"
                                           value="<?php echo esc_attr(get_option('hozio_webp_quality', 82)); ?>"
                                           class="hozio-range">
                                    <div class="range-labels">
                                        <span>Smaller</span>
                                        <span class="range-value"><span id="webp-quality-value"><?php echo esc_html(get_option('hozio_webp_quality', 82)); ?></span>%</span>
                                        <span>Higher</span>
                                    </div>
                                </div>
                            </div>

                            <label class="hozio-feature-toggle single" style="margin-top: 20px;">
                                <div class="feature-info">
                                    <span class="dashicons dashicons-backup"></span>
                                    <div>
                                        <span class="feature-label"><?php esc_html_e('Keep Original Backup', 'hozio-image-optimizer'); ?></span>
                                        <span class="feature-desc"><?php esc_html_e('Backup original before format conversion', 'hozio-image-optimizer'); ?></span>
                                    </div>
                                </div>
                                <div class="hozio-toggle">
                                    <input type="checkbox" name="hozio_keep_original_backup" value="1" <?php checked(get_option('hozio_keep_original_backup', true)); ?>>
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="hozio-form-actions">
                        <?php submit_button(__('Save Settings', 'hozio-image-optimizer'), 'hozio-btn hozio-btn-primary', 'submit', false); ?>
                    </div>
                </form>

                </div><?php /* /panel format */ ?>

                <?php /* Tab: naming */ ?>
                <div class="hz-tab-panel <?php echo $current_tab === 'naming' ? 'hz-active' : ''; ?>" data-tab="naming" id="hz-tab-naming" role="tabpanel" aria-labelledby="hz-sidenav-naming">

                <!-- Live Naming Preview -->
                <div class="hz-preview-card" id="hz-naming-preview" data-search-keywords="preview filename example template live">
                    <div class="hz-preview-head">
                        <span class="dashicons dashicons-visibility"></span>
                        <span class="hz-preview-label"><?php esc_html_e('Live Preview', 'hozio-image-optimizer'); ?></span>
                        <span class="hz-chip hz-chip-ok hz-chip-sm"><?php esc_html_e('Sample image', 'hozio-image-optimizer'); ?></span>
                    </div>
                    <div class="hz-preview-body">
                        <div class="hz-preview-col">
                            <div class="hz-preview-col-head">
                                <span class="dashicons dashicons-media-default"></span>
                                <?php esc_html_e('Filename', 'hozio-image-optimizer'); ?>
                            </div>
                            <code class="hz-preview-template" id="hz-preview-filename-template"><?php echo esc_html(get_option('hozio_naming_template', '{keyword}-{location}')); ?></code>
                            <span class="hz-preview-arrow">&#x2193;</span>
                            <code class="hz-preview-output" id="hz-preview-filename-output">&mdash;</code>
                        </div>
                        <div class="hz-preview-sep"></div>
                        <div class="hz-preview-col">
                            <div class="hz-preview-col-head">
                                <span class="dashicons dashicons-editor-textcolor"></span>
                                <?php esc_html_e('Alt Title', 'hozio-image-optimizer'); ?>
                            </div>
                            <code class="hz-preview-template" id="hz-preview-title-template"><?php echo esc_html(get_option('hozio_title_template', 'Professional {keyword} in {location}')); ?></code>
                            <span class="hz-preview-arrow">&#x2193;</span>
                            <span class="hz-preview-title-output" id="hz-preview-title-output">&mdash;</span>
                        </div>
                    </div>
                </div>

                <!-- Naming Tab -->
                <form method="post" action="options.php" class="hozio-settings-form hozio-settings-form-full">
                    <?php settings_fields('hozio_naming_settings'); ?>

                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon naming">
                                <span class="dashicons dashicons-tag"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('Naming Conventions', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Configure how your images are named and titled', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <?php
                            $current_naming = get_option('hozio_naming_template', '{keyword}-{location}');
                            $naming_templates = array(
                                '{keyword}-{location}' => array(
                                    'label' => __('Keyword + Location', 'hozio-image-optimizer'),
                                    'example' => 'modern-kitchen-new-york',
                                    'icon' => 'location-alt',
                                ),
                                '{keyword}' => array(
                                    'label' => __('Keyword Only', 'hozio-image-optimizer'),
                                    'example' => 'modern-kitchen-design',
                                    'icon' => 'tag',
                                ),
                                '{site_title}-{keyword}' => array(
                                    'label' => __('Site + Keyword', 'hozio-image-optimizer'),
                                    'example' => 'mysite-modern-kitchen',
                                    'icon' => 'admin-home',
                                ),
                                '{site_title}-{keyword}-{location}' => array(
                                    'label' => __('Site + Keyword + Location', 'hozio-image-optimizer'),
                                    'example' => 'mysite-modern-kitchen-new-york',
                                    'icon' => 'admin-site',
                                ),
                                '{location}-{keyword}' => array(
                                    'label' => __('Location + Keyword', 'hozio-image-optimizer'),
                                    'example' => 'new-york-modern-kitchen',
                                    'icon' => 'location',
                                ),
                                '{keyword}-{timestamp}' => array(
                                    'label' => __('Keyword + Date', 'hozio-image-optimizer'),
                                    'example' => 'modern-kitchen-20241201',
                                    'icon' => 'calendar-alt',
                                ),
                            );
                            ?>

                            <!-- Filename Template Section -->
                            <div class="naming-section">
                                <div class="naming-section-header">
                                    <div class="naming-section-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"></path>
                                            <polyline points="14 2 14 8 20 8"></polyline>
                                        </svg>
                                    </div>
                                    <div class="naming-section-title">
                                        <h3><?php esc_html_e('Filename Template', 'hozio-image-optimizer'); ?></h3>
                                        <p><?php esc_html_e('Choose how your image files will be named', 'hozio-image-optimizer'); ?></p>
                                    </div>
                                </div>

                                <div class="template-options-grid">
                                    <?php foreach ($naming_templates as $value => $template) : ?>
                                        <label class="template-option <?php echo ($current_naming === $value) ? 'selected' : ''; ?>">
                                            <input type="radio" name="hozio_naming_template" value="<?php echo esc_attr($value); ?>" <?php checked($current_naming, $value); ?>>
                                            <div class="template-option-content">
                                                <span class="dashicons dashicons-<?php echo esc_attr($template['icon']); ?>"></span>
                                                <div class="template-option-text">
                                                    <span class="template-label"><?php echo esc_html($template['label']); ?></span>
                                                    <code class="template-example"><?php echo esc_html($template['example']); ?>.webp</code>
                                                </div>
                                                <span class="template-check">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="20 6 9 17 4 12"></polyline>
                                                    </svg>
                                                </span>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>

                                    <label class="template-option template-option-custom <?php echo (!array_key_exists($current_naming, $naming_templates)) ? 'selected' : ''; ?>">
                                        <input type="radio" name="hozio_naming_template" value="custom" <?php checked(!array_key_exists($current_naming, $naming_templates)); ?>>
                                        <div class="template-option-content">
                                            <span class="dashicons dashicons-edit"></span>
                                            <div class="template-option-text">
                                                <span class="template-label"><?php esc_html_e('Custom Template', 'hozio-image-optimizer'); ?></span>
                                                <span class="template-hint"><?php esc_html_e('Create your own pattern', 'hozio-image-optimizer'); ?></span>
                                            </div>
                                            <span class="template-check">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="20 6 9 17 4 12"></polyline>
                                                </svg>
                                            </span>
                                        </div>
                                    </label>
                                </div>

                                <div id="custom-naming-wrap" class="custom-template-wrap" style="<?php echo array_key_exists($current_naming, $naming_templates) ? 'display:none;' : ''; ?>">
                                    <div class="custom-template-input-group">
                                        <input type="text" id="hozio_naming_template_custom" class="hozio-input"
                                               value="<?php echo esc_attr(array_key_exists($current_naming, $naming_templates) ? '' : $current_naming); ?>"
                                               placeholder="e.g., {site_title}-{keyword}">
                                        <div class="template-tokens">
                                            <span class="token-label"><?php esc_html_e('Available tokens:', 'hozio-image-optimizer'); ?></span>
                                            <code class="token">{site_title}</code>
                                            <code class="token">{keyword}</code>
                                            <code class="token">{location}</code>
                                            <code class="token">{timestamp}</code>
                                            <code class="token">{random}</code>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="naming-divider"></div>

                            <!-- Title Template Section -->
                            <div class="naming-section">
                                <div class="naming-section-header">
                                    <div class="naming-section-icon" style="background: linear-gradient(135deg, #8b5cf6, #a855f7);">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 20h9"></path>
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                        </svg>
                                    </div>
                                    <div class="naming-section-title">
                                        <h3><?php esc_html_e('Title Template', 'hozio-image-optimizer'); ?></h3>
                                        <p><?php esc_html_e('Format for the image title attribute', 'hozio-image-optimizer'); ?></p>
                                    </div>
                                </div>

                                <?php
                                $current_title = get_option('hozio_title_template', '{title}');
                                $title_templates = array(
                                    '{title}' => array(
                                        'label' => __('AI Generated Title', 'hozio-image-optimizer'),
                                        'example' => 'Modern Kitchen Design',
                                    ),
                                    '{title} - {location}' => array(
                                        'label' => __('Title + Location', 'hozio-image-optimizer'),
                                        'example' => 'Modern Kitchen - New York',
                                    ),
                                    '{title} | {site_title}' => array(
                                        'label' => __('Title + Site Name', 'hozio-image-optimizer'),
                                        'example' => 'Modern Kitchen | My Site',
                                    ),
                                );
                                ?>

                                <div class="title-options-row">
                                    <?php foreach ($title_templates as $value => $template) : ?>
                                        <label class="title-option <?php echo ($current_title === $value) ? 'selected' : ''; ?>">
                                            <input type="radio" name="hozio_title_template" value="<?php echo esc_attr($value); ?>" <?php checked($current_title, $value); ?>>
                                            <div class="title-option-content">
                                                <span class="title-option-label"><?php echo esc_html($template['label']); ?></span>
                                                <code class="title-option-example"><?php echo esc_html($template['example']); ?></code>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>

                                    <label class="title-option title-option-custom <?php echo (!array_key_exists($current_title, $title_templates)) ? 'selected' : ''; ?>">
                                        <input type="radio" name="hozio_title_template" value="custom" <?php checked(!array_key_exists($current_title, $title_templates)); ?>>
                                        <div class="title-option-content">
                                            <span class="title-option-label"><?php esc_html_e('Custom', 'hozio-image-optimizer'); ?></span>
                                            <span class="dashicons dashicons-edit"></span>
                                        </div>
                                    </label>
                                </div>

                                <div id="custom-title-wrap" class="custom-template-wrap" style="<?php echo array_key_exists($current_title, $title_templates) ? 'display:none;' : ''; ?>">
                                    <div class="custom-template-input-group">
                                        <input type="text" id="hozio_title_template_custom" class="hozio-input"
                                               value="<?php echo esc_attr(array_key_exists($current_title, $title_templates) ? '' : $current_title); ?>"
                                               placeholder="e.g., {title} - {site_title}">
                                        <div class="template-tokens">
                                            <span class="token-label"><?php esc_html_e('Available tokens:', 'hozio-image-optimizer'); ?></span>
                                            <code class="token">{site_title}</code>
                                            <code class="token">{title}</code>
                                            <code class="token">{keyword}</code>
                                            <code class="token">{location}</code>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="naming-divider"></div>

                            <!-- Advanced Options -->
                            <div class="naming-section naming-advanced">
                                <div class="naming-section-header">
                                    <div class="naming-section-icon" style="background: linear-gradient(135deg, #64748b, #475569);">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="3"></circle>
                                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                                        </svg>
                                    </div>
                                    <div class="naming-section-title">
                                        <h3><?php esc_html_e('Advanced Options', 'hozio-image-optimizer'); ?></h3>
                                        <p><?php esc_html_e('Fine-tune filename generation', 'hozio-image-optimizer'); ?></p>
                                    </div>
                                </div>

                                <div class="advanced-options-grid">
                                    <div class="advanced-option">
                                        <label for="hozio_keyword_word_count" class="advanced-option-label">
                                            <span class="dashicons dashicons-editor-textcolor"></span>
                                            <?php esc_html_e('Keyword Word Count', 'hozio-image-optimizer'); ?>
                                        </label>
                                        <div class="advanced-option-input">
                                            <input type="number"
                                                   id="hozio_keyword_word_count"
                                                   name="hozio_keyword_word_count"
                                                   min="2" max="10"
                                                   value="<?php echo esc_attr(get_option('hozio_keyword_word_count', 5)); ?>"
                                                   class="hozio-input-small">
                                            <span class="input-suffix"><?php esc_html_e('words', 'hozio-image-optimizer'); ?></span>
                                        </div>
                                        <p class="advanced-option-hint"><?php esc_html_e('AI-generated keyword length (2-10)', 'hozio-image-optimizer'); ?></p>
                                    </div>

                                    <div class="advanced-option">
                                        <label for="hozio_max_filename_length" class="advanced-option-label">
                                            <span class="dashicons dashicons-editor-contract"></span>
                                            <?php esc_html_e('Max Filename Length', 'hozio-image-optimizer'); ?>
                                        </label>
                                        <div class="advanced-option-input">
                                            <input type="number"
                                                   id="hozio_max_filename_length"
                                                   name="hozio_max_filename_length"
                                                   min="10" max="200"
                                                   value="<?php echo esc_attr(get_option('hozio_max_filename_length', 50)); ?>"
                                                   class="hozio-input-small">
                                            <span class="input-suffix"><?php esc_html_e('chars', 'hozio-image-optimizer'); ?></span>
                                        </div>
                                        <p class="advanced-option-hint"><?php esc_html_e('Truncated at word boundaries', 'hozio-image-optimizer'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hozio-form-actions">
                        <?php submit_button(__('Save Settings', 'hozio-image-optimizer'), 'hozio-btn hozio-btn-primary', 'submit', false); ?>
                    </div>
                </form>

                <script>
                jQuery(function($) {
                    // Handle template option selection (radio buttons)
                    $('.template-option input[type="radio"]').on('change', function() {
                        var $parent = $(this).closest('.template-options-grid, .title-options-row');
                        $parent.find('.template-option, .title-option').removeClass('selected');
                        $(this).closest('.template-option, .title-option').addClass('selected');

                        // Show/hide custom input for naming template
                        if ($(this).attr('name') === 'hozio_naming_template') {
                            if ($(this).val() === 'custom') {
                                $('#custom-naming-wrap').slideDown();
                            } else {
                                $('#custom-naming-wrap').slideUp();
                            }
                        }
                    });

                    // Handle title option selection
                    $('.title-option input[type="radio"]').on('change', function() {
                        var $parent = $(this).closest('.title-options-row');
                        $parent.find('.title-option').removeClass('selected');
                        $(this).closest('.title-option').addClass('selected');

                        // Show/hide custom input for title template
                        if ($(this).val() === 'custom') {
                            $('#custom-title-wrap').slideDown();
                        } else {
                            $('#custom-title-wrap').slideUp();
                        }
                    });

                    // Token click to insert
                    $('.token').on('click', function() {
                        var token = $(this).text();
                        var $wrap = $(this).closest('.custom-template-wrap');
                        var $input = $wrap.find('input[type="text"]');
                        var curVal = $input.val();
                        $input.val(curVal + token);
                        $input.focus();
                    });

                    // Before form submit, update values if custom is selected
                    $('form.hozio-settings-form').on('submit', function() {
                        var namingVal = $('input[name="hozio_naming_template"]:checked').val();
                        if (namingVal === 'custom') {
                            var customVal = $('#hozio_naming_template_custom').val().trim();
                            if (customVal) {
                                // Create a hidden input with the custom value
                                $('<input>').attr({
                                    type: 'hidden',
                                    name: 'hozio_naming_template',
                                    value: customVal
                                }).appendTo($(this));
                                // Disable the radio to prevent conflict
                                $('input[name="hozio_naming_template"][value="custom"]').prop('disabled', true);
                            }
                        }

                        var titleVal = $('input[name="hozio_title_template"]:checked').val();
                        if (titleVal === 'custom') {
                            var customVal = $('#hozio_title_template_custom').val().trim();
                            if (customVal) {
                                // Create a hidden input with the custom value
                                $('<input>').attr({
                                    type: 'hidden',
                                    name: 'hozio_title_template',
                                    value: customVal
                                }).appendTo($(this));
                                // Disable the radio to prevent conflict
                                $('input[name="hozio_title_template"][value="custom"]').prop('disabled', true);
                            }
                        }
                    });
                });
                </script>

                </div><?php /* /panel naming */ ?>

                <?php /* Tab: geolocation */ ?>
                <div class="hz-tab-panel <?php echo $current_tab === 'geolocation' ? 'hz-active' : ''; ?>" data-tab="geolocation" id="hz-tab-geolocation" role="tabpanel" aria-labelledby="hz-sidenav-geolocation">
                <!-- Geolocation Tab -->
                <form method="post" action="options.php" class="hozio-settings-form hozio-settings-form-full" id="geolocation-form">
                    <?php settings_fields('hozio_geolocation_settings'); ?>

                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon geolocation">
                                <span class="dashicons dashicons-location-alt"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('Geolocation Settings', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Inject GPS coordinates into image EXIF data for local SEO', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <label class="hozio-feature-toggle single">
                                <div class="feature-info">
                                    <span class="dashicons dashicons-location"></span>
                                    <div>
                                        <span class="feature-label"><?php esc_html_e('Enable Geolocation', 'hozio-image-optimizer'); ?></span>
                                        <span class="feature-desc"><?php esc_html_e('Embed GPS coordinates when location is provided', 'hozio-image-optimizer'); ?></span>
                                    </div>
                                </div>
                                <div class="hozio-toggle">
                                    <input type="checkbox" name="hozio_enable_geolocation" value="1" <?php checked(get_option('hozio_enable_geolocation', true)); ?>>
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>

                            <div class="hozio-info-box" style="margin-top: 15px;">
                                <span class="dashicons dashicons-info"></span>
                                <div>
                                    <strong><?php esc_html_e('How it works:', 'hozio-image-optimizer'); ?></strong>
                                    <p><?php esc_html_e('When you provide a location during optimization, the plugin will embed GPS coordinates into the image\'s EXIF metadata. This helps with local SEO and image discoverability in Google Images.', 'hozio-image-optimizer'); ?></p>
                                    <?php
                                    $exiftool_available = $capabilities['exiftool'] ?? false;
                                    if ($exiftool_available === 'bundled') : ?>
                                        <p class="success-text"><span class="dashicons dashicons-yes-alt" style="color: #22c55e;"></span> <?php esc_html_e('ExifTool (bundled) active - GPS coordinates will be embedded reliably.', 'hozio-image-optimizer'); ?></p>
                                    <?php elseif ($exiftool_available) : ?>
                                        <p class="success-text"><span class="dashicons dashicons-yes-alt" style="color: #22c55e;"></span> <?php esc_html_e('ExifTool (system) detected - GPS coordinates will be embedded reliably.', 'hozio-image-optimizer'); ?></p>
                                    <?php else : ?>
                                        <p class="warning-text"><span class="dashicons dashicons-warning"></span> <?php esc_html_e('ExifTool not available. GPS embedding may be limited.', 'hozio-image-optimizer'); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="hozio-field-group hz-geocode-test-wrap" style="margin-top: 15px;">
                                <label class="hozio-field-label"><?php esc_html_e('Test Geocoding', 'hozio-image-optimizer'); ?></label>
                                <div style="display:flex;gap:10px;">
                                    <input type="text" id="test-geocode-location" class="hozio-input" placeholder="<?php esc_attr_e('Enter a location (e.g., Boston, MA)', 'hozio-image-optimizer'); ?>" style="flex:1;">
                                    <button type="button" id="test-geocode-btn" class="hozio-btn hozio-btn-secondary">
                                        <span class="dashicons dashicons-location"></span>
                                        <?php esc_html_e('Test', 'hozio-image-optimizer'); ?>
                                    </button>
                                </div>
                                <div id="geocode-result" class="hz-geocode-msg" hidden></div>
                                <div id="geocode-map" class="hz-loc-map" hidden></div>
                            </div>
                        </div>
                    </div>

                    <!-- Custom Locations Card -->
                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                                <span class="dashicons dashicons-admin-multisite"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('Custom Locations', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Add your own locations with custom names and coordinates', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <div class="hozio-info-box" style="margin-bottom: 20px;">
                                <span class="dashicons dashicons-info"></span>
                                <div>
                                    <p><?php esc_html_e('Custom locations will appear in the location dropdown on the Image Optimizer page. They use exact coordinates you specify, which is useful for specific business locations.', 'hozio-image-optimizer'); ?></p>
                                </div>
                            </div>

                            <div id="custom-locations-list">
                                <?php
                                $custom_locations = get_option('hozio_custom_locations', array());
                                if (!empty($custom_locations)) :
                                    foreach ($custom_locations as $index => $location) :
                                ?>
                                    <div class="custom-location-row" data-index="<?php echo $index; ?>">
                                        <div class="location-fields">
                                            <input type="text"
                                                   name="hozio_custom_locations[<?php echo $index; ?>][name]"
                                                   value="<?php echo esc_attr($location['name']); ?>"
                                                   class="hozio-input location-name"
                                                   placeholder="<?php esc_attr_e('Location Name', 'hozio-image-optimizer'); ?>">
                                            <input type="text"
                                                   name="hozio_custom_locations[<?php echo $index; ?>][lat]"
                                                   value="<?php echo esc_attr($location['lat']); ?>"
                                                   class="hozio-input location-lat"
                                                   placeholder="<?php esc_attr_e('Latitude', 'hozio-image-optimizer'); ?>">
                                            <input type="text"
                                                   name="hozio_custom_locations[<?php echo $index; ?>][lng]"
                                                   value="<?php echo esc_attr($location['lng']); ?>"
                                                   class="hozio-input location-lng"
                                                   placeholder="<?php esc_attr_e('Longitude', 'hozio-image-optimizer'); ?>">
                                        </div>
                                        <button type="button" class="hozio-btn hozio-btn-secondary hz-view-loc-btn"
                                                data-lat="<?php echo esc_attr($location['lat']); ?>"
                                                data-lng="<?php echo esc_attr($location['lng']); ?>"
                                                title="<?php esc_attr_e('Preview on map', 'hozio-image-optimizer'); ?>">
                                            <span class="dashicons dashicons-location"></span>
                                        </button>
                                        <button type="button" class="hozio-btn hozio-btn-danger remove-location">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                        <div class="hz-loc-map hz-row-map" hidden></div>
                                    </div>
                                <?php
                                    endforeach;
                                endif;
                                ?>
                            </div>

                            <!-- Add New Location -->
                            <div class="hz-add-location-section">
                                <h4 class="hz-add-loc-heading"><?php esc_html_e('Add New Location', 'hozio-image-optimizer'); ?></h4>

                                <!-- Search autocomplete -->
                                <div class="hz-loc-search-wrap">
                                    <div class="hz-loc-search-field">
                                        <span class="dashicons dashicons-search"></span>
                                        <input type="text" id="hz-loc-search" class="hozio-input"
                                               placeholder="<?php esc_attr_e('Search for a location (e.g., Huntington, NY)…', 'hozio-image-optimizer'); ?>"
                                               autocomplete="off">
                                        <span class="dashicons dashicons-update spin hz-loc-spinner" id="hz-loc-spinner" hidden></span>
                                    </div>
                                    <div id="hz-loc-dropdown" class="hz-loc-dropdown" hidden></div>
                                </div>

                                <!-- Name + Coords row (editable, auto-filled on selection) -->
                                <div class="hz-loc-fields">
                                    <input type="text" id="new-location-name" class="hozio-input hz-loc-name" placeholder="<?php esc_attr_e('Display Name', 'hozio-image-optimizer'); ?>">
                                    <input type="text" id="new-location-lat" class="hozio-input hz-loc-coord" placeholder="<?php esc_attr_e('Latitude', 'hozio-image-optimizer'); ?>">
                                    <input type="text" id="new-location-lng" class="hozio-input hz-loc-coord" placeholder="<?php esc_attr_e('Longitude', 'hozio-image-optimizer'); ?>">
                                    <button type="button" id="hz-preview-new-loc" class="hozio-btn hozio-btn-secondary" disabled title="<?php esc_attr_e('Preview on map', 'hozio-image-optimizer'); ?>">
                                        <span class="dashicons dashicons-location"></span>
                                    </button>
                                </div>

                                <!-- Map preview for new location -->
                                <div id="hz-new-loc-map" class="hz-loc-map" hidden></div>

                                <div class="hz-add-loc-footer">
                                    <button type="button" id="add-custom-location" class="hozio-btn hozio-btn-primary" disabled>
                                        <span class="dashicons dashicons-plus-alt2"></span>
                                        <?php esc_html_e('Add Location', 'hozio-image-optimizer'); ?>
                                    </button>
                                    <p class="hozio-field-hint"><?php esc_html_e('Search above to auto-fill coordinates, or enter latitude and longitude manually.', 'hozio-image-optimizer'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hozio-form-actions">
                        <?php submit_button(__('Save Settings', 'hozio-image-optimizer'), 'hozio-btn hozio-btn-primary', 'submit', false); ?>
                    </div>
                </form>

                <script>
                jQuery(document).ready(function($) {
                    var locationIndex = <?php echo count($custom_locations); ?>;

                    /* ---- Shared map helper ---- */
                    function buildMapHtml(lat, lng, displayName) {
                        lat = parseFloat(lat); lng = parseFloat(lng);
                        var m = 0.018;
                        var bbox = (lng-m)+','+(lat-m)+','+(lng+m)+','+(lat+m);
                        var osmSrc = 'https://www.openstreetmap.org/export/embed.html?bbox='+bbox+'&layer=mapnik&marker='+lat+','+lng;
                        var gmUrl = 'https://www.google.com/maps?q='+lat+','+lng;
                        return '<div class="hz-map-card">' +
                            '<iframe class="hz-map-frame" src="' + osmSrc + '" allowfullscreen loading="lazy"></iframe>' +
                            '<div class="hz-map-footer">' +
                            (displayName ? '<span class="hz-map-name">' + $('<span>').text(displayName).html() + '</span>' : '') +
                            '<span class="hz-map-coords">' + lat.toFixed(6) + ', ' + lng.toFixed(6) + '</span>' +
                            '<a href="' + gmUrl + '" target="_blank" rel="noopener" class="hz-map-gmaps-link"><span class="dashicons dashicons-external"></span> Google Maps</a>' +
                            '</div></div>';
                    }

                    /* ---- Remove location ---- */
                    $(document).on('click', '.remove-location', function() {
                        $(this).closest('.custom-location-row').remove();
                    });

                    /* ---- View map on existing rows ---- */
                    $(document).on('click', '.hz-view-loc-btn', function() {
                        var $btn  = $(this);
                        var $row  = $btn.closest('.custom-location-row');
                        var $map  = $row.find('.hz-row-map');
                        var lat   = $btn.data('lat') || $row.find('.location-lat').val();
                        var lng   = $btn.data('lng') || $row.find('.location-lng').val();
                        if (!lat || !lng) { return; }
                        if (!$map.prop('hidden')) { $map.attr('hidden', true).empty(); return; }
                        $map.html(buildMapHtml(lat, lng, $row.find('.location-name').val())).removeAttr('hidden');
                    });

                    /* ---- Add location ---- */
                    $('#add-custom-location').on('click', function() {
                        var name = $('#new-location-name').val().trim();
                        var lat  = $('#new-location-lat').val().trim();
                        var lng  = $('#new-location-lng').val().trim();
                        if (!name || !lat || !lng) { return; }

                        var html = '<div class="custom-location-row" data-index="' + locationIndex + '">' +
                            '<div class="location-fields">' +
                            '<input type="text" name="hozio_custom_locations[' + locationIndex + '][name]" value="' + $('<span>').text(name).html() + '" class="hozio-input location-name" placeholder="<?php echo esc_js(__('Location Name', 'hozio-image-optimizer')); ?>">' +
                            '<input type="text" name="hozio_custom_locations[' + locationIndex + '][lat]" value="' + $('<span>').text(lat).html() + '" class="hozio-input location-lat" placeholder="<?php echo esc_js(__('Latitude', 'hozio-image-optimizer')); ?>">' +
                            '<input type="text" name="hozio_custom_locations[' + locationIndex + '][lng]" value="' + $('<span>').text(lng).html() + '" class="hozio-input location-lng" placeholder="<?php echo esc_js(__('Longitude', 'hozio-image-optimizer')); ?>">' +
                            '</div>' +
                            '<button type="button" class="hozio-btn hozio-btn-secondary hz-view-loc-btn" data-lat="'+lat+'" data-lng="'+lng+'" title="<?php echo esc_js(__('Preview on map', 'hozio-image-optimizer')); ?>"><span class="dashicons dashicons-location"></span></button>' +
                            '<button type="button" class="hozio-btn hozio-btn-danger remove-location"><span class="dashicons dashicons-trash"></span></button>' +
                            '<div class="hz-loc-map hz-row-map" hidden></div>' +
                            '</div>';

                        $('#custom-locations-list').append(html);
                        locationIndex++;
                        $('#new-location-name, #new-location-lat, #new-location-lng').val('');
                        $('#hz-loc-search').val('');
                        $('#hz-new-loc-map').attr('hidden', true).empty();
                        $('#add-custom-location, #hz-preview-new-loc').prop('disabled', true);
                    });

                    /* ---- Location search autocomplete ---- */
                    var searchTimer, lastQuery = '';
                    $('#hz-loc-search').on('input', function() {
                        var q = $(this).val().trim();
                        clearTimeout(searchTimer);
                        if (q.length < 2) {
                            $('#hz-loc-dropdown').attr('hidden', true).empty();
                            lastQuery = '';
                            return;
                        }
                        if (q === lastQuery) { return; }
                        lastQuery = q;
                        $('#hz-loc-spinner').removeAttr('hidden');
                        searchTimer = setTimeout(function() {
                            $.post(ajaxurl, {
                                action: 'hozio_search_locations',
                                nonce: hozioImageOptimizer.nonce,
                                query: q
                            }, function(res) {
                                $('#hz-loc-spinner').attr('hidden', true);
                                var $dd = $('#hz-loc-dropdown');
                                if (!res.success || !res.data.locations || !res.data.locations.length) {
                                    $dd.attr('hidden', true).empty();
                                    return;
                                }
                                var html = '';
                                res.data.locations.forEach(function(loc, i) {
                                    html += '<div class="hz-loc-item" data-i="'+i+'" data-name="'+$('<span>').text(loc.name).html()+'" data-lat="'+loc.lat+'" data-lng="'+loc.lng+'">' +
                                        '<span class="dashicons dashicons-location"></span>' +
                                        '<span>'+$('<span>').text(loc.name).html()+'</span>' +
                                        '</div>';
                                });
                                $dd.html(html).removeAttr('hidden');
                            }).fail(function() {
                                $('#hz-loc-spinner').attr('hidden', true);
                            });
                        }, 300);
                    });

                    /* Select from dropdown */
                    $(document).on('click', '.hz-loc-item', function() {
                        var $item = $(this);
                        $('#new-location-name').val($item.data('name'));
                        $('#new-location-lat').val($item.data('lat'));
                        $('#new-location-lng').val($item.data('lng'));
                        $('#hz-loc-search').val($item.data('name'));
                        $('#hz-loc-dropdown').attr('hidden', true);
                        checkNewLocReady();
                    });

                    /* Close dropdown on outside click */
                    $(document).on('click', function(e) {
                        if (!$(e.target).closest('.hz-loc-search-wrap').length) {
                            $('#hz-loc-dropdown').attr('hidden', true);
                        }
                    });

                    /* Enable/disable Add + Preview buttons when coords are filled */
                    function checkNewLocReady() {
                        var lat = $('#new-location-lat').val().trim();
                        var lng = $('#new-location-lng').val().trim();
                        var name = $('#new-location-name').val().trim();
                        var hasCoords = lat && lng && !isNaN(parseFloat(lat)) && !isNaN(parseFloat(lng));
                        $('#hz-preview-new-loc').prop('disabled', !hasCoords);
                        $('#add-custom-location').prop('disabled', !(name && hasCoords));
                    }
                    $('#new-location-name, #new-location-lat, #new-location-lng').on('input', checkNewLocReady);

                    /* Preview new location on map */
                    $('#hz-preview-new-loc').on('click', function() {
                        var lat = $('#new-location-lat').val().trim();
                        var lng = $('#new-location-lng').val().trim();
                        var name = $('#new-location-name').val().trim();
                        if (!lat || !lng) { return; }
                        var $map = $('#hz-new-loc-map');
                        $map.html(buildMapHtml(lat, lng, name)).removeAttr('hidden');
                    });
                });
                </script>

                </div><?php /* /panel geolocation */ ?>

                <?php /* Tab: auto_optimize */ ?>
                <div class="hz-tab-panel <?php echo $current_tab === 'auto_optimize' ? 'hz-active' : ''; ?>" data-tab="auto_optimize" id="hz-tab-auto_optimize" role="tabpanel" aria-labelledby="hz-sidenav-auto_optimize">
                <!-- Auto-Optimize Tab -->
                <form method="post" action="options.php" class="hozio-settings-form">
                    <?php settings_fields('hozio_auto_optimize_settings'); ?>

                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                                <span class="dashicons dashicons-upload"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('Auto-Optimize on Upload', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Automatically optimize images when they are uploaded to WordPress', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <label class="hozio-feature-toggle single">
                                <div class="feature-info">
                                    <span class="dashicons dashicons-controls-repeat"></span>
                                    <div>
                                        <span class="feature-label"><?php esc_html_e('Enable Auto-Optimization', 'hozio-image-optimizer'); ?></span>
                                        <span class="feature-desc"><?php esc_html_e('Automatically compress and convert images on upload', 'hozio-image-optimizer'); ?></span>
                                    </div>
                                </div>
                                <div class="hozio-toggle">
                                    <input type="checkbox" name="hozio_auto_optimize_on_upload" value="1" <?php checked(get_option('hozio_auto_optimize_on_upload', false)); ?>>
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>

                            <label class="hozio-feature-toggle single" style="margin-top: 15px;">
                                <div class="feature-info">
                                    <span class="dashicons dashicons-edit"></span>
                                    <div>
                                        <span class="feature-label"><?php esc_html_e('Auto AI Rename & Alt Text', 'hozio-image-optimizer'); ?></span>
                                        <span class="feature-desc"><?php esc_html_e('Use AI to rename files and generate alt text on upload (requires API key)', 'hozio-image-optimizer'); ?></span>
                                    </div>
                                </div>
                                <div class="hozio-toggle">
                                    <input type="checkbox" name="hozio_auto_ai_rename" value="1" <?php checked(get_option('hozio_auto_ai_rename', false)); ?>>
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>

                            <div class="hozio-field-group" style="margin-top: 20px;">
                                <label class="hozio-field-label">
                                    <?php esc_html_e('Default Location for Uploads', 'hozio-image-optimizer'); ?>
                                    <?php if (get_option('hozio_enable_geolocation', true)) : ?>
                                        <span class="dashicons dashicons-location-alt" style="color: #10b981; font-size: 16px;" title="<?php esc_attr_e('GPS coordinates will be embedded', 'hozio-image-optimizer'); ?>"></span>
                                    <?php endif; ?>
                                </label>
                                <?php
                                $custom_locations = get_option('hozio_custom_locations', array());
                                $default_location = get_option('hozio_default_upload_location', '');
                                $default_lat = get_option('hozio_default_upload_lat', '');
                                $default_lng = get_option('hozio_default_upload_lng', '');
                                $is_saved_location = false;
                                $saved_location_data = null;

                                // Check if default_location matches a saved location
                                if (!empty($default_location) && !empty($custom_locations)) {
                                    foreach ($custom_locations as $loc) {
                                        if ($loc['name'] === $default_location) {
                                            $is_saved_location = true;
                                            $saved_location_data = $loc;
                                            break;
                                        }
                                    }
                                }
                                $has_custom_coords = !empty($default_lat) && !empty($default_lng) && !$is_saved_location;
                                ?>

                                <!-- Location Selector Dropdown -->
                                <?php if (!empty($custom_locations)) : ?>
                                    <select id="auto-location-selector" class="hozio-input" style="width: 100%;">
                                        <option value=""><?php esc_html_e('-- No location (skip geolocation) --', 'hozio-image-optimizer'); ?></option>
                                        <optgroup label="<?php esc_attr_e('Saved Locations', 'hozio-image-optimizer'); ?>">
                                            <?php foreach ($custom_locations as $location) : ?>
                                                <option value="<?php echo esc_attr($location['name']); ?>"
                                                        data-lat="<?php echo esc_attr($location['lat']); ?>"
                                                        data-lng="<?php echo esc_attr($location['lng']); ?>"
                                                        <?php selected($is_saved_location && $default_location === $location['name']); ?>>
                                                    <?php echo esc_html($location['name']); ?> (<?php echo esc_html(round($location['lat'], 4)); ?>, <?php echo esc_html(round($location['lng'], 4)); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <optgroup label="<?php esc_attr_e('Other', 'hozio-image-optimizer'); ?>">
                                            <option value="custom" <?php selected($has_custom_coords); ?>>
                                                <?php esc_html_e('Enter custom coordinates...', 'hozio-image-optimizer'); ?>
                                            </option>
                                        </optgroup>
                                    </select>
                                <?php else : ?>
                                    <select id="auto-location-selector" class="hozio-input" style="width: 100%;">
                                        <option value=""><?php esc_html_e('-- No location (skip geolocation) --', 'hozio-image-optimizer'); ?></option>
                                        <option value="custom" <?php selected($has_custom_coords); ?>>
                                            <?php esc_html_e('Enter custom coordinates...', 'hozio-image-optimizer'); ?>
                                        </option>
                                    </select>
                                    <p class="hozio-field-hint" style="margin-top: 5px;">
                                        <a href="<?php echo esc_url(add_query_arg('tab', 'geolocation')); ?>">
                                            <span class="dashicons dashicons-plus-alt2" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                            <?php esc_html_e('Add saved locations in the Geolocation tab', 'hozio-image-optimizer'); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>

                                <!-- Hidden fields to store the actual values -->
                                <input type="hidden" name="hozio_default_upload_location" id="auto-location-name" value="<?php echo esc_attr($default_location); ?>">
                                <input type="hidden" name="hozio_default_upload_lat" id="auto-location-lat" value="<?php echo esc_attr($default_lat); ?>">
                                <input type="hidden" name="hozio_default_upload_lng" id="auto-location-lng" value="<?php echo esc_attr($default_lng); ?>">

                                <!-- Coordinates Preview (shown when a location is selected) -->
                                <div id="auto-coords-preview" class="geocode-success" style="margin-top: 15px; padding: 12px 15px; background: linear-gradient(to right, #dcfce7, #d1fae5); border-radius: 8px; <?php echo (!empty($default_lat) && !empty($default_lng)) ? 'display: flex;' : 'display: none;'; ?> align-items: center; gap: 10px; flex-wrap: wrap;">
                                    <span class="dashicons dashicons-yes-alt" style="color: #22c55e;"></span>
                                    <span id="auto-coords-display"><strong>GPS:</strong> <?php echo esc_html($default_lat); ?>, <?php echo esc_html($default_lng); ?></span>
                                    <a href="https://www.google.com/maps?q=<?php echo esc_attr($default_lat); ?>,<?php echo esc_attr($default_lng); ?>" id="auto-view-map" target="_blank" class="view-map-link" style="margin-left: auto; color: #166534; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: rgba(22, 101, 52, 0.1); border-radius: 6px;">
                                        <span class="dashicons dashicons-external" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                        <?php esc_html_e('View on Map', 'hozio-image-optimizer'); ?>
                                    </a>
                                </div>

                                <!-- Custom coordinates section (only shown when "custom" is selected) -->
                                <div id="auto-custom-coords" class="custom-coords-section" style="margin-top: 15px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; <?php echo $has_custom_coords ? '' : 'display:none;'; ?>">
                                    <div class="coords-row" style="margin-bottom: 12px;">
                                        <label class="coord-label" style="display: block; font-weight: 500; margin-bottom: 5px; color: #374151;"><?php esc_html_e('Location Name (for AI context)', 'hozio-image-optimizer'); ?></label>
                                        <input type="text" id="auto-custom-name" class="hozio-input" style="width: 100%;"
                                               value="<?php echo esc_attr($has_custom_coords ? $default_location : ''); ?>"
                                               placeholder="<?php esc_attr_e('e.g., Huntington NY, Main Office', 'hozio-image-optimizer'); ?>">
                                    </div>
                                    <div class="coords-row" style="display: flex; gap: 10px; margin-bottom: 12px;">
                                        <div style="flex: 1;">
                                            <label class="coord-label" style="display: block; font-weight: 500; margin-bottom: 5px; color: #374151;"><?php esc_html_e('Latitude', 'hozio-image-optimizer'); ?></label>
                                            <input type="text" id="auto-custom-lat" class="hozio-input" style="width: 100%;"
                                                   value="<?php echo esc_attr($has_custom_coords ? $default_lat : ''); ?>"
                                                   placeholder="<?php esc_attr_e('e.g., 40.8682', 'hozio-image-optimizer'); ?>">
                                        </div>
                                        <div style="flex: 1;">
                                            <label class="coord-label" style="display: block; font-weight: 500; margin-bottom: 5px; color: #374151;"><?php esc_html_e('Longitude', 'hozio-image-optimizer'); ?></label>
                                            <input type="text" id="auto-custom-lng" class="hozio-input" style="width: 100%;"
                                                   value="<?php echo esc_attr($has_custom_coords ? $default_lng : ''); ?>"
                                                   placeholder="<?php esc_attr_e('e.g., -73.4257', 'hozio-image-optimizer'); ?>">
                                        </div>
                                    </div>
                                    <div class="paste-coords-wrap" style="margin-bottom: 12px;">
                                        <label class="coord-label" style="display: block; font-weight: 500; margin-bottom: 5px; color: #374151;"><?php esc_html_e('Or paste coordinates', 'hozio-image-optimizer'); ?></label>
                                        <input type="text" id="auto-paste-coords" class="hozio-input" style="width: 100%;"
                                               placeholder="<?php esc_attr_e('Paste: 40.8682, -73.4257', 'hozio-image-optimizer'); ?>">
                                        <p class="hozio-field-hint" style="margin-top: 5px; font-size: 12px; color: #64748b;">
                                            <span class="dashicons dashicons-info" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                            <?php esc_html_e('Tip: Right-click on Google Maps and click the coordinates to copy them.', 'hozio-image-optimizer'); ?>
                                        </p>
                                    </div>
                                </div>

                                <p class="hozio-field-hint" style="margin-top: 10px;"><?php esc_html_e('This location will be used for GPS embedding and AI naming context.', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- What Gets Applied Card -->
                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                                <span class="dashicons dashicons-yes-alt"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('What Gets Applied', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('These optimizations are applied based on your other settings', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <div class="auto-optimize-checklist">
                                <div class="checklist-item <?php echo get_option('hozio_enable_compression', true) ? 'enabled' : 'disabled'; ?>">
                                    <span class="dashicons <?php echo get_option('hozio_enable_compression', true) ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span>
                                    <span><?php esc_html_e('Image Compression', 'hozio-image-optimizer'); ?></span>
                                    <a href="<?php echo esc_url(add_query_arg('tab', 'compression')); ?>" class="edit-link"><?php esc_html_e('Edit', 'hozio-image-optimizer'); ?></a>
                                </div>
                                <div class="checklist-item <?php echo get_option('hozio_convert_to_webp', true) ? 'enabled' : 'disabled'; ?>">
                                    <span class="dashicons <?php echo get_option('hozio_convert_to_webp', true) ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span>
                                    <span><?php esc_html_e('WebP Conversion', 'hozio-image-optimizer'); ?></span>
                                    <a href="<?php echo esc_url(add_query_arg('tab', 'format')); ?>" class="edit-link"><?php esc_html_e('Edit', 'hozio-image-optimizer'); ?></a>
                                </div>
                                <div class="checklist-item <?php echo get_option('hozio_enable_geolocation', true) ? 'enabled' : 'disabled'; ?>">
                                    <span class="dashicons <?php echo get_option('hozio_enable_geolocation', true) ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span>
                                    <span><?php esc_html_e('GPS Embedding', 'hozio-image-optimizer'); ?></span>
                                    <a href="<?php echo esc_url(add_query_arg('tab', 'geolocation')); ?>" class="edit-link"><?php esc_html_e('Edit', 'hozio-image-optimizer'); ?></a>
                                </div>
                                <div class="checklist-item <?php echo get_option('hozio_enable_ai_alt_text', true) && !empty(get_option('hozio_openai_api_key')) ? 'enabled' : 'disabled'; ?>">
                                    <span class="dashicons <?php echo get_option('hozio_enable_ai_alt_text', true) && !empty(get_option('hozio_openai_api_key')) ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span>
                                    <span><?php esc_html_e('AI Alt Text', 'hozio-image-optimizer'); ?></span>
                                    <a href="<?php echo esc_url(add_query_arg('tab', 'api')); ?>" class="edit-link"><?php esc_html_e('Edit', 'hozio-image-optimizer'); ?></a>
                                </div>
                                <div class="checklist-item <?php echo get_option('hozio_enable_ai_caption', true) && !empty(get_option('hozio_openai_api_key')) ? 'enabled' : 'disabled'; ?>">
                                    <span class="dashicons <?php echo get_option('hozio_enable_ai_caption', true) && !empty(get_option('hozio_openai_api_key')) ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span>
                                    <span><?php esc_html_e('AI Caption', 'hozio-image-optimizer'); ?></span>
                                    <a href="<?php echo esc_url(add_query_arg('tab', 'api')); ?>" class="edit-link"><?php esc_html_e('Edit', 'hozio-image-optimizer'); ?></a>
                                </div>
                                <div class="checklist-item <?php echo get_option('hozio_backup_enabled', false) ? 'enabled' : 'disabled'; ?>">
                                    <span class="dashicons <?php echo get_option('hozio_backup_enabled', false) ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span>
                                    <span><?php esc_html_e('Backup Original', 'hozio-image-optimizer'); ?></span>
                                    <a href="<?php echo esc_url(add_query_arg('tab', 'backup')); ?>" class="edit-link"><?php esc_html_e('Edit', 'hozio-image-optimizer'); ?></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hozio-form-actions">
                        <?php submit_button(__('Save Settings', 'hozio-image-optimizer'), 'hozio-btn hozio-btn-primary', 'submit', false); ?>
                    </div>
                </form>

                <style>
                .auto-optimize-checklist {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                }
                .checklist-item {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    padding: 12px 15px;
                    background: #f8fafc;
                    border-radius: 8px;
                    border: 1px solid #e5e7eb;
                }
                .checklist-item.enabled .dashicons {
                    color: #22c55e;
                }
                .checklist-item.disabled .dashicons {
                    color: #94a3b8;
                }
                .checklist-item .edit-link {
                    margin-left: auto;
                    font-size: 12px;
                    text-decoration: none;
                    color: #667eea;
                }
                .checklist-item .edit-link:hover {
                    text-decoration: underline;
                }
                </style>

                <script>
                jQuery(document).ready(function($) {
                    // Helper function to update the coordinates preview
                    function updateCoordsPreview(lat, lng) {
                        if (lat && lng && !isNaN(parseFloat(lat)) && !isNaN(parseFloat(lng))) {
                            var latNum = parseFloat(lat).toFixed(6);
                            var lngNum = parseFloat(lng).toFixed(6);
                            $('#auto-coords-display').html('<strong>GPS:</strong> ' + latNum + ', ' + lngNum);
                            $('#auto-view-map').attr('href', 'https://www.google.com/maps?q=' + latNum + ',' + lngNum);
                            $('#auto-coords-preview').css('display', 'flex');
                        } else {
                            $('#auto-coords-preview').hide();
                        }
                    }

                    // Helper function to parse pasted coordinates
                    function parseCoordinates(input) {
                        input = input.trim();
                        // Try different formats: "lat, lng" or "lat lng" or "lat,lng"
                        var parts = input.split(/[\s,]+/).filter(function(p) { return p.length > 0; });
                        if (parts.length >= 2) {
                            var lat = parseFloat(parts[0]);
                            var lng = parseFloat(parts[1]);
                            if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
                                return { lat: lat, lng: lng };
                            }
                        }
                        return null;
                    }

                    // Location selector change
                    $('#auto-location-selector').on('change', function() {
                        var selected = $(this).val();
                        var $option = $(this).find('option:selected');
                        var lat = $option.data('lat');
                        var lng = $option.data('lng');

                        if (selected === '') {
                            // No location selected - hide everything and clear fields
                            $('#auto-custom-coords').hide();
                            $('#auto-location-name').val('');
                            $('#auto-location-lat').val('');
                            $('#auto-location-lng').val('');
                            $('#auto-custom-name').val('');
                            $('#auto-custom-lat').val('');
                            $('#auto-custom-lng').val('');
                            $('#auto-paste-coords').val('');
                            updateCoordsPreview('', '');
                        } else if (selected === 'custom') {
                            // Show custom coords section for manual entry
                            $('#auto-custom-coords').show();
                            // Update hidden fields from custom inputs
                            var customName = $('#auto-custom-name').val();
                            var customLat = $('#auto-custom-lat').val();
                            var customLng = $('#auto-custom-lng').val();
                            $('#auto-location-name').val(customName);
                            $('#auto-location-lat').val(customLat);
                            $('#auto-location-lng').val(customLng);
                            updateCoordsPreview(customLat, customLng);
                        } else if (selected && lat && lng) {
                            // Saved location selected - hide manual entry, set hidden fields, show preview
                            $('#auto-custom-coords').hide();
                            $('#auto-location-name').val(selected);
                            $('#auto-location-lat').val(lat);
                            $('#auto-location-lng').val(lng);
                            // Clear custom fields
                            $('#auto-custom-name').val('');
                            $('#auto-custom-lat').val('');
                            $('#auto-custom-lng').val('');
                            $('#auto-paste-coords').val('');
                            // Show the preview with the selected location's coords
                            updateCoordsPreview(lat, lng);
                        }
                    });

                    // Update hidden fields when custom name changes
                    $('#auto-custom-name').on('input', function() {
                        $('#auto-location-name').val($(this).val());
                    });

                    // Update hidden fields when custom lat/lng changes
                    $('#auto-custom-lat, #auto-custom-lng').on('input', function() {
                        var lat = $('#auto-custom-lat').val();
                        var lng = $('#auto-custom-lng').val();
                        $('#auto-location-lat').val(lat);
                        $('#auto-location-lng').val(lng);
                        updateCoordsPreview(lat, lng);
                    });

                    // Handle paste coordinates field
                    $('#auto-paste-coords').on('input paste', function() {
                        var self = this;
                        // Small delay to ensure pasted content is available
                        setTimeout(function() {
                            var input = $(self).val();
                            var coords = parseCoordinates(input);
                            if (coords) {
                                $('#auto-custom-lat').val(coords.lat);
                                $('#auto-custom-lng').val(coords.lng);
                                $('#auto-location-lat').val(coords.lat);
                                $('#auto-location-lng').val(coords.lng);
                                updateCoordsPreview(coords.lat, coords.lng);
                                // Clear paste field after successful parse
                                $(self).val('').attr('placeholder', '<?php echo esc_js(__('Coordinates applied!', 'hozio-image-optimizer')); ?>');
                                setTimeout(function() {
                                    $(self).attr('placeholder', '<?php echo esc_js(__('Paste: 40.8682, -73.4257', 'hozio-image-optimizer')); ?>');
                                }, 2000);
                            }
                        }, 50);
                    });
                });
                </script>

                </div><?php /* /panel auto_optimize */ ?>

                <?php /* Tab: backup */ ?>
                <div class="hz-tab-panel <?php echo $current_tab === 'backup' ? 'hz-active' : ''; ?>" data-tab="backup" id="hz-tab-backup" role="tabpanel" aria-labelledby="hz-sidenav-backup">
                <!-- Backup & Safety Tab -->
                <form method="post" action="options.php" class="hozio-settings-form">
                    <?php settings_fields('hozio_backup_settings'); ?>

                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon backup">
                                <span class="dashicons dashicons-backup"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('Backup & Safety', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Protect your original images with automatic backups', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <label class="hozio-feature-toggle single">
                                <div class="feature-info">
                                    <span class="dashicons dashicons-shield-alt"></span>
                                    <div>
                                        <span class="feature-label"><?php esc_html_e('Enable Backups', 'hozio-image-optimizer'); ?></span>
                                        <span class="feature-desc"><?php esc_html_e('Create backup before any optimization', 'hozio-image-optimizer'); ?></span>
                                    </div>
                                </div>
                                <div class="hozio-toggle">
                                    <input type="checkbox" name="hozio_backup_enabled" value="1" <?php checked(get_option('hozio_backup_enabled', false)); ?>>
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>

                            <div class="hozio-field-group">
                                <label for="hozio_backup_retention_days" class="hozio-field-label">
                                    <?php esc_html_e('Backup Retention Period', 'hozio-image-optimizer'); ?>
                                </label>
                                <div class="hozio-input-addon" style="max-width: 200px;">
                                    <input type="number"
                                           id="hozio_backup_retention_days"
                                           name="hozio_backup_retention_days"
                                           min="1" max="365"
                                           value="<?php echo esc_attr(get_option('hozio_backup_retention_days', 30)); ?>"
                                           class="hozio-input">
                                    <span class="addon"><?php esc_html_e('days', 'hozio-image-optimizer'); ?></span>
                                </div>
                                <p class="hozio-field-hint"><?php esc_html_e('Backups older than this will be automatically deleted', 'hozio-image-optimizer'); ?></p>
                            </div>

                            <label class="hozio-feature-toggle single">
                                <div class="feature-info">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <div>
                                        <span class="feature-label"><?php esc_html_e('Validate After Operations', 'hozio-image-optimizer'); ?></span>
                                        <span class="feature-desc"><?php esc_html_e('Auto-restore if corruption is detected', 'hozio-image-optimizer'); ?></span>
                                    </div>
                                </div>
                                <div class="hozio-toggle">
                                    <input type="checkbox" name="hozio_validate_after_operation" value="1" <?php checked(get_option('hozio_validate_after_operation', true)); ?>>
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <?php
                    $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
                    $backup_stats = $backup_manager->get_backup_stats();
                    ?>

                    <div class="hozio-card">
                        <div class="hozio-card-header">
                            <div class="hozio-card-icon storage">
                                <span class="dashicons dashicons-database"></span>
                            </div>
                            <div>
                                <h2><?php esc_html_e('Backup Storage', 'hozio-image-optimizer'); ?></h2>
                                <p><?php esc_html_e('Overview of your backup storage usage', 'hozio-image-optimizer'); ?></p>
                            </div>
                        </div>
                        <div class="hozio-card-body">
                            <div class="hozio-stats-row">
                                <div class="hozio-stat-item">
                                    <span class="stat-value"><?php echo esc_html($backup_stats['total_images']); ?></span>
                                    <span class="stat-label"><?php esc_html_e('Images Backed Up', 'hozio-image-optimizer'); ?></span>
                                </div>
                                <div class="hozio-stat-item">
                                    <span class="stat-value"><?php echo esc_html($backup_stats['total_backups']); ?></span>
                                    <span class="stat-label"><?php esc_html_e('Total Backup Files', 'hozio-image-optimizer'); ?></span>
                                </div>
                                <div class="hozio-stat-item">
                                    <span class="stat-value"><?php echo esc_html($backup_stats['total_size_formatted']); ?></span>
                                    <span class="stat-label"><?php esc_html_e('Storage Used', 'hozio-image-optimizer'); ?></span>
                                </div>
                            </div>
                            <div class="hozio-actions-row">
                                <button type="button" id="cleanup-backups-btn" class="hozio-btn hozio-btn-secondary">
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php esc_html_e('Clean Up Old Backups', 'hozio-image-optimizer'); ?>
                                </button>
                                <a href="<?php echo esc_url(admin_url('upload.php?page=hozio-image-backups')); ?>" class="hozio-btn hozio-btn-outline">
                                    <span class="dashicons dashicons-list-view"></span>
                                    <?php esc_html_e('Manage Backups', 'hozio-image-optimizer'); ?>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="hozio-form-actions">
                        <?php submit_button(__('Save Settings', 'hozio-image-optimizer'), 'hozio-btn hozio-btn-primary', 'submit', false); ?>
                    </div>
                </form>

                </div><?php /* /panel backup */ ?>

                <?php /* Tab: usage */ ?>
                <div class="hz-tab-panel <?php echo $current_tab === 'usage' ? 'hz-active' : ''; ?>" data-tab="usage" id="hz-tab-usage" role="tabpanel" aria-labelledby="hz-sidenav-usage">
                <!-- API Usage Tab -->
                <?php
                $usage_stats = get_option('hozio_api_usage_stats', array(
                    'total_requests' => 0,
                    'total_prompt_tokens' => 0,
                    'total_completion_tokens' => 0,
                    'total_tokens' => 0,
                    'estimated_cost' => 0,
                    'history' => array(),
                ));
                ?>

                <div class="hozio-card">
                    <div class="hozio-card-header">
                        <div class="hozio-card-icon usage">
                            <span class="dashicons dashicons-chart-bar"></span>
                        </div>
                        <div>
                            <h2><?php esc_html_e('API Usage Overview', 'hozio-image-optimizer'); ?></h2>
                            <p><?php esc_html_e('Monitor your OpenAI API usage and costs', 'hozio-image-optimizer'); ?></p>
                        </div>
                    </div>
                    <div class="hozio-card-body">
                        <div class="hozio-usage-cards">
                            <div class="hozio-usage-card">
                                <span class="usage-icon">
                                    <span class="dashicons dashicons-admin-plugins"></span>
                                </span>
                                <span class="usage-value"><?php echo esc_html(number_format($usage_stats['total_requests'])); ?></span>
                                <span class="usage-label"><?php esc_html_e('Total Requests', 'hozio-image-optimizer'); ?></span>
                            </div>
                            <div class="hozio-usage-card">
                                <span class="usage-icon">
                                    <span class="dashicons dashicons-editor-code"></span>
                                </span>
                                <span class="usage-value"><?php echo esc_html(number_format($usage_stats['total_tokens'])); ?></span>
                                <span class="usage-label"><?php esc_html_e('Tokens Used', 'hozio-image-optimizer'); ?></span>
                            </div>
                            <div class="hozio-usage-card highlight">
                                <span class="usage-icon">
                                    <span class="dashicons dashicons-chart-line"></span>
                                </span>
                                <span class="usage-value">$<?php echo esc_html(number_format($usage_stats['estimated_cost'], 4)); ?></span>
                                <span class="usage-label"><?php esc_html_e('Estimated Cost', 'hozio-image-optimizer'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hozio-card">
                    <div class="hozio-card-header">
                        <div class="hozio-card-icon tokens">
                            <span class="dashicons dashicons-analytics"></span>
                        </div>
                        <div>
                            <h2><?php esc_html_e('Token Breakdown', 'hozio-image-optimizer'); ?></h2>
                            <p><?php esc_html_e('Input vs output token usage', 'hozio-image-optimizer'); ?></p>
                        </div>
                    </div>
                    <div class="hozio-card-body">
                        <div class="hozio-token-stats">
                            <div class="token-stat">
                                <span class="token-label"><?php esc_html_e('Input Tokens', 'hozio-image-optimizer'); ?></span>
                                <span class="token-value"><?php echo esc_html(number_format($usage_stats['total_prompt_tokens'])); ?></span>
                            </div>
                            <div class="token-stat">
                                <span class="token-label"><?php esc_html_e('Output Tokens', 'hozio-image-optimizer'); ?></span>
                                <span class="token-value"><?php echo esc_html(number_format($usage_stats['total_completion_tokens'])); ?></span>
                            </div>
                            <div class="token-stat">
                                <span class="token-label"><?php esc_html_e('Avg per Request', 'hozio-image-optimizer'); ?></span>
                                <span class="token-value"><?php echo $usage_stats['total_requests'] > 0 ? esc_html(number_format($usage_stats['total_tokens'] / $usage_stats['total_requests'], 0)) : '0'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hozio-card">
                    <div class="hozio-card-header">
                        <div class="hozio-card-icon history">
                            <span class="dashicons dashicons-backup"></span>
                        </div>
                        <div>
                            <h2><?php esc_html_e('Recent API Calls', 'hozio-image-optimizer'); ?></h2>
                            <p><?php esc_html_e('Last 20 API requests', 'hozio-image-optimizer'); ?></p>
                        </div>
                    </div>
                    <div class="hozio-card-body no-padding">
                        <?php if (empty($usage_stats['history'])) : ?>
                            <div class="hozio-empty-state">
                                <span class="dashicons dashicons-chart-pie"></span>
                                <p><?php esc_html_e('No API calls recorded yet', 'hozio-image-optimizer'); ?></p>
                            </div>
                        <?php else : ?>
                            <table class="hozio-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Date/Time', 'hozio-image-optimizer'); ?></th>
                                        <th><?php esc_html_e('Model', 'hozio-image-optimizer'); ?></th>
                                        <th><?php esc_html_e('Tokens', 'hozio-image-optimizer'); ?></th>
                                        <th><?php esc_html_e('Cost', 'hozio-image-optimizer'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $history = array_reverse($usage_stats['history']);
                                    $history = array_slice($history, 0, 20);
                                    foreach ($history as $entry) :
                                    ?>
                                        <tr>
                                            <td><?php echo esc_html($entry['timestamp']); ?></td>
                                            <td><code><?php echo esc_html($entry['model']); ?></code></td>
                                            <td><?php echo esc_html(number_format($entry['prompt_tokens'] + $entry['completion_tokens'])); ?></td>
                                            <td>$<?php echo esc_html(number_format($entry['cost'], 6)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="hozio-card">
                    <div class="hozio-card-header">
                        <div class="hozio-card-icon pricing">
                            <span class="dashicons dashicons-money-alt"></span>
                        </div>
                        <div>
                            <h2><?php esc_html_e('Pricing Reference', 'hozio-image-optimizer'); ?></h2>
                            <p><?php esc_html_e('OpenAI model pricing (per 1M tokens)', 'hozio-image-optimizer'); ?></p>
                        </div>
                    </div>
                    <div class="hozio-card-body no-padding">
                        <table class="hozio-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Model', 'hozio-image-optimizer'); ?></th>
                                    <th><?php esc_html_e('Input', 'hozio-image-optimizer'); ?></th>
                                    <th><?php esc_html_e('Output', 'hozio-image-optimizer'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <strong>GPT-4.1</strong>
                                        <span class="model-tag recommended"><?php esc_html_e('Recommended', 'hozio-image-optimizer'); ?></span>
                                    </td>
                                    <td>$2.00</td>
                                    <td>$8.00</td>
                                </tr>
                                <tr>
                                    <td>
                                        <strong>GPT-4.1 Mini</strong>
                                        <span class="model-tag budget"><?php esc_html_e('Best Value', 'hozio-image-optimizer'); ?></span>
                                    </td>
                                    <td>$0.40</td>
                                    <td>$1.60</td>
                                </tr>
                                <tr>
                                    <td><strong>GPT-4.1 Nano</strong></td>
                                    <td>$0.10</td>
                                    <td>$0.40</td>
                                </tr>
                                <tr>
                                    <td><strong>GPT-4o</strong></td>
                                    <td>$2.50</td>
                                    <td>$10.00</td>
                                </tr>
                                <tr>
                                    <td><strong>GPT-4o Mini</strong></td>
                                    <td>$0.15</td>
                                    <td>$0.60</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Optimization Report Card -->
                <div class="hozio-card">
                    <div class="hozio-card-header">
                        <div class="hozio-card-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                            <span class="dashicons dashicons-media-document"></span>
                        </div>
                        <div>
                            <h2><?php esc_html_e('Optimization Report', 'hozio-image-optimizer'); ?></h2>
                            <p><?php esc_html_e('Generate a detailed report of your optimization results', 'hozio-image-optimizer'); ?></p>
                        </div>
                    </div>
                    <div class="hozio-card-body">
                        <div class="hozio-report-actions" style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <button type="button" id="generate-report-btn" class="hozio-btn hozio-btn-primary">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e('Download HTML Report', 'hozio-image-optimizer'); ?>
                            </button>
                            <button type="button" id="view-report-btn" class="hozio-btn hozio-btn-secondary">
                                <span class="dashicons dashicons-visibility"></span>
                                <?php esc_html_e('Preview Report', 'hozio-image-optimizer'); ?>
                            </button>
                        </div>

                        <div class="hozio-email-report" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                            <label class="hozio-field-label"><?php esc_html_e('Email Report', 'hozio-image-optimizer'); ?></label>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <input type="email" id="report-email" class="hozio-input"
                                       placeholder="<?php esc_attr_e('Enter email address', 'hozio-image-optimizer'); ?>"
                                       value="<?php echo esc_attr(get_option('admin_email')); ?>"
                                       style="flex: 1; min-width: 250px;">
                                <button type="button" id="email-report-btn" class="hozio-btn hozio-btn-outline">
                                    <span class="dashicons dashicons-email"></span>
                                    <?php esc_html_e('Send Report', 'hozio-image-optimizer'); ?>
                                </button>
                            </div>
                            <p class="hozio-field-hint" style="margin-top: 8px;"><?php esc_html_e('Send a copy of the optimization report to this email address.', 'hozio-image-optimizer'); ?></p>
                        </div>

                        <div id="report-status" style="margin-top: 15px; display: none;"></div>
                    </div>
                </div>

                <!-- Report Preview Modal -->
                <div id="report-preview-modal" class="hozio-modal" style="display: none;">
                    <div class="hozio-modal-overlay"></div>
                    <div class="hozio-modal-content" style="max-width: 900px; max-height: 90vh;">
                        <div class="hozio-modal-header">
                            <h3><?php esc_html_e('Optimization Report Preview', 'hozio-image-optimizer'); ?></h3>
                            <button type="button" class="hozio-modal-close">&times;</button>
                        </div>
                        <div class="hozio-modal-body" id="report-preview-content" style="max-height: 70vh; overflow-y: auto; padding: 0;">
                            <!-- Report content will be loaded here -->
                        </div>
                        <div class="hozio-modal-footer">
                            <button type="button" id="download-from-preview" class="hozio-btn hozio-btn-primary">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e('Download', 'hozio-image-optimizer'); ?>
                            </button>
                            <button type="button" class="hozio-btn hozio-btn-outline hozio-modal-close">
                                <?php esc_html_e('Close', 'hozio-image-optimizer'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="hozio-form-actions">
                    <button type="button" id="reset-usage-stats" class="hozio-btn hozio-btn-secondary">
                        <span class="dashicons dashicons-trash"></span>
                        <?php esc_html_e('Reset Statistics', 'hozio-image-optimizer'); ?>
                    </button>
                </div>

                <script>
                jQuery(function($) {
                    // Generate and download report
                    $('#generate-report-btn').on('click', function() {
                        var btn = $(this);
                        var status = $('#report-status');

                        btn.prop('disabled', true);
                        status.show().html('<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Generating report...', 'hozio-image-optimizer')); ?>');

                        $.post(ajaxurl, {
                            action: 'hozio_generate_report',
                            nonce: hozioImageOptimizer.nonce,
                            format: 'html'
                        }, function(response) {
                            btn.prop('disabled', false);

                            if (response.success) {
                                status.html('<span class="dashicons dashicons-yes" style="color: #22c55e;"></span> <?php echo esc_js(__('Report generated!', 'hozio-image-optimizer')); ?>');

                                // Create download link
                                var blob = new Blob([response.data.html], {type: 'text/html'});
                                var url = window.URL.createObjectURL(blob);
                                var a = document.createElement('a');
                                a.href = url;
                                a.download = 'hozio-optimization-report-' + new Date().toISOString().slice(0,10) + '.html';
                                document.body.appendChild(a);
                                a.click();
                                document.body.removeChild(a);
                                window.URL.revokeObjectURL(url);

                                setTimeout(function() { status.fadeOut(); }, 3000);
                            } else {
                                status.html('<span class="dashicons dashicons-no" style="color: #ef4444;"></span> ' + (response.data.message || '<?php echo esc_js(__('Failed to generate report', 'hozio-image-optimizer')); ?>'));
                            }
                        }).fail(function() {
                            btn.prop('disabled', false);
                            status.html('<span class="dashicons dashicons-no" style="color: #ef4444;"></span> <?php echo esc_js(__('Request failed', 'hozio-image-optimizer')); ?>');
                        });
                    });

                    // Preview report
                    $('#view-report-btn').on('click', function() {
                        var btn = $(this);
                        var modal = $('#report-preview-modal');
                        var content = $('#report-preview-content');

                        btn.prop('disabled', true);
                        content.html('<div style="text-align: center; padding: 40px;"><span class="dashicons dashicons-update spin" style="font-size: 32px;"></span><p><?php echo esc_js(__('Loading report...', 'hozio-image-optimizer')); ?></p></div>');
                        modal.show();

                        $.post(ajaxurl, {
                            action: 'hozio_generate_report',
                            nonce: hozioImageOptimizer.nonce,
                            format: 'html'
                        }, function(response) {
                            btn.prop('disabled', false);

                            if (response.success) {
                                // Create iframe to display report
                                content.html('<iframe id="report-iframe" style="width: 100%; height: 60vh; border: none;"></iframe>');
                                var iframe = document.getElementById('report-iframe');
                                iframe.contentDocument.open();
                                iframe.contentDocument.write(response.data.html);
                                iframe.contentDocument.close();
                            } else {
                                content.html('<div style="text-align: center; padding: 40px; color: #ef4444;"><span class="dashicons dashicons-warning"></span><p>' + (response.data.message || '<?php echo esc_js(__('Failed to generate report', 'hozio-image-optimizer')); ?>') + '</p></div>');
                            }
                        }).fail(function() {
                            btn.prop('disabled', false);
                            content.html('<div style="text-align: center; padding: 40px; color: #ef4444;"><span class="dashicons dashicons-warning"></span><p><?php echo esc_js(__('Request failed', 'hozio-image-optimizer')); ?></p></div>');
                        });
                    });

                    // Download from preview modal
                    $('#download-from-preview').on('click', function() {
                        $('#generate-report-btn').click();
                    });

                    // Email report
                    $('#email-report-btn').on('click', function() {
                        var btn = $(this);
                        var email = $('#report-email').val();
                        var status = $('#report-status');

                        if (!email || !email.includes('@')) {
                            status.show().html('<span class="dashicons dashicons-warning" style="color: #f59e0b;"></span> <?php echo esc_js(__('Please enter a valid email address', 'hozio-image-optimizer')); ?>');
                            return;
                        }

                        btn.prop('disabled', true);
                        status.show().html('<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Sending report...', 'hozio-image-optimizer')); ?>');

                        $.post(ajaxurl, {
                            action: 'hozio_send_report_email',
                            nonce: hozioImageOptimizer.nonce,
                            email: email
                        }, function(response) {
                            btn.prop('disabled', false);

                            if (response.success) {
                                status.html('<span class="dashicons dashicons-yes" style="color: #22c55e;"></span> <?php echo esc_js(__('Report sent successfully!', 'hozio-image-optimizer')); ?>');
                                setTimeout(function() { status.fadeOut(); }, 3000);
                            } else {
                                status.html('<span class="dashicons dashicons-no" style="color: #ef4444;"></span> ' + (response.data.message || '<?php echo esc_js(__('Failed to send report', 'hozio-image-optimizer')); ?>'));
                            }
                        }).fail(function() {
                            btn.prop('disabled', false);
                            status.html('<span class="dashicons dashicons-no" style="color: #ef4444;"></span> <?php echo esc_js(__('Request failed', 'hozio-image-optimizer')); ?>');
                        });
                    });

                    // Close modal
                    $('.hozio-modal-close, .hozio-modal-overlay').on('click', function() {
                        $(this).closest('.hozio-modal').hide();
                    });

                    $('#reset-usage-stats').on('click', function() {
                        if (confirm('<?php echo esc_js(__('Are you sure you want to reset all API usage statistics?', 'hozio-image-optimizer')); ?>')) {
                            var btn = $(this);
                            btn.prop('disabled', true);

                            $.post(ajaxurl, {
                                action: 'hozio_reset_usage_stats',
                                nonce: '<?php echo esc_js(wp_create_nonce('hozio_image_optimizer_nonce')); ?>'
                            }, function(response) {
                                if (response.success) {
                                    location.reload();
                                } else {
                                    alert('<?php echo esc_js(__('Failed to reset statistics', 'hozio-image-optimizer')); ?>');
                                    btn.prop('disabled', false);
                                }
                            });
                        }
                    });
                });
                </script>

                </div><?php /* /panel usage */ ?>

                <?php /* Tab: license */ ?>
                <div class="hz-tab-panel <?php echo $current_tab === 'license' ? 'hz-active' : ''; ?>" data-tab="license" id="hz-tab-license" role="tabpanel" aria-labelledby="hz-sidenav-license">
                <!-- License & Updates Tab -->
                <?php
                $license_key = get_option('hozio_license_key', '');
                $is_licensed = function_exists('hozio_imgopt_is_license_valid') ? hozio_imgopt_is_license_valid() : false;
                $last_check = function_exists('hozio_imgopt_get_last_update_check') ? hozio_imgopt_get_last_update_check() : 'Never';
                $auto_updates = get_option('hozio_imgopt_auto_updates_enabled', '1');
                ?>

                <div class="hz-license-section">
                    <!-- Version Info -->
                    <div class="hz-license-card">
                        <div class="hz-license-header">
                            <span class="hz-license-icon"><img src="<?php echo esc_url(HOZIO_IMAGE_OPTIMIZER_URL . 'assets/images/logo.png'); ?>" alt="" style="height:20px;width:auto;"></span>
                            <div>
                                <div class="hz-license-title">Hozio Image Optimizer</div>
                                <div class="hz-license-version">Version <?php echo esc_html(HOZIO_IMAGE_OPTIMIZER_VERSION); ?></div>
                            </div>
                            <span class="hz-license-status <?php echo $is_licensed ? 'active' : 'inactive'; ?>">
                                <?php echo $is_licensed ? esc_html__('Licensed', 'hozio-image-optimizer') : esc_html__('Unlicensed', 'hozio-image-optimizer'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- License Key -->
                    <div class="hz-license-card">
                        <h3 style="margin:0 0 12px;font-size:13px;font-weight:700;color:#111827;"><?php esc_html_e('License Key', 'hozio-image-optimizer'); ?></h3>
                        <?php
                        // Re-check Hub status fresh every time (not cached)
                        $is_hub_connected = class_exists('Hozio_Hub_Client') && method_exists('Hozio_Hub_Client', 'is_connected') && Hozio_Hub_Client::is_connected();
                        $has_local_key = !empty($license_key);
                        ?>

                        <?php if ($is_hub_connected && $is_licensed) : ?>
                            <!-- Licensed via Hub - locked, can't edit -->
                            <p style="font-size:11px;color:#9ca3af;margin:0 0 12px;"><?php esc_html_e('License is managed by Hozio Pro Hub. No action needed.', 'hozio-image-optimizer'); ?></p>
                            <div style="display:flex;gap:8px;align-items:center;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
                                <span class="dashicons dashicons-lock" style="color:#16a34a;font-size:16px;width:16px;height:16px;"></span>
                                <span style="font-size:12px;color:#16a34a;font-weight:600;"><?php esc_html_e('Licensed via Hozio Pro', 'hozio-image-optimizer'); ?></span>
                            </div>

                        <?php elseif ($has_local_key && $is_licensed) : ?>
                            <!-- Licensed via local key - show masked, allow change -->
                            <p style="font-size:11px;color:#9ca3af;margin:0 0 12px;"><?php esc_html_e('License key is active.', 'hozio-image-optimizer'); ?></p>
                            <div style="display:flex;gap:8px;align-items:center;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin-bottom:10px;">
                                <span class="dashicons dashicons-yes-alt" style="color:#16a34a;font-size:16px;width:16px;height:16px;"></span>
                                <span style="font-size:12px;color:#16a34a;font-weight:600;"><?php esc_html_e('License is valid and active', 'hozio-image-optimizer'); ?></span>
                                <span style="font-size:11px;color:#9ca3af;margin-left:8px;">••••••••<?php echo esc_html(substr($license_key, -4)); ?></span>
                            </div>
                            <form method="post" action="options.php">
                                <?php settings_fields('hozio_license_settings'); ?>
                                <details style="margin-top:4px;">
                                    <summary style="font-size:11px;color:#6b7280;cursor:pointer;user-select:none;"><?php esc_html_e('Change license key', 'hozio-image-optimizer'); ?></summary>
                                    <div style="display:flex;gap:8px;align-items:center;margin-top:8px;">
                                        <input type="text" name="hozio_license_key" value="" class="hozio-input" style="flex:1;max-width:400px;" placeholder="<?php esc_attr_e('Enter new license key...', 'hozio-image-optimizer'); ?>">
                                        <?php submit_button(__('Save', 'hozio-image-optimizer'), 'hz-btn hz-btn-primary', 'submit', false); ?>
                                    </div>
                                </details>
                            </form>

                        <?php else : ?>
                            <!-- No license - show input -->
                            <p style="font-size:11px;color:#9ca3af;margin:0 0 12px;"><?php esc_html_e('Enter your Hozio license key to enable updates. Same key as Hozio Pro.', 'hozio-image-optimizer'); ?></p>
                            <form method="post" action="options.php">
                                <?php settings_fields('hozio_license_settings'); ?>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input type="text" name="hozio_license_key" value="<?php echo esc_attr($license_key); ?>" class="hozio-input" style="flex:1;max-width:400px;" placeholder="<?php esc_attr_e('Enter license key...', 'hozio-image-optimizer'); ?>">
                                    <?php submit_button(__('Save Key', 'hozio-image-optimizer'), 'hz-btn hz-btn-primary', 'submit', false); ?>
                                </div>
                                <?php if ($has_local_key && !$is_licensed) : ?>
                                    <p style="margin:8px 0 0;font-size:11px;color:#ef4444;font-weight:600;">&#10007; <?php esc_html_e('Invalid license key', 'hozio-image-optimizer'); ?></p>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Updates -->
                    <div class="hz-license-card">
                        <h3 style="margin:0 0 12px;font-size:13px;font-weight:700;color:#111827;"><?php esc_html_e('Updates', 'hozio-image-optimizer'); ?></h3>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                            <div>
                                <div style="font-size:12px;color:#374151;"><?php esc_html_e('Last checked:', 'hozio-image-optimizer'); ?> <strong><?php echo esc_html($last_check); ?></strong></div>
                            </div>
                            <button type="button" id="hozio-check-update-btn" class="hz-btn hz-btn-ghost" style="font-size:11px;">
                                <span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Check Now', 'hozio-image-optimizer'); ?>
                            </button>
                        </div>
                        <div id="hozio-update-result" style="margin-top:8px;"></div>
                        <script>
                        jQuery('#hozio-check-update-btn').on('click', function() {
                            var btn = jQuery(this);
                            var result = jQuery('#hozio-update-result');
                            btn.prop('disabled', true).find('.dashicons').addClass('spin');
                            result.html('<span style="font-size:11px;color:#6b7280;">Checking for updates...</span>');

                            jQuery.post(ajaxurl, {
                                action: 'hozio_force_update_check',
                                nonce: '<?php echo esc_js(wp_create_nonce('hozio_image_optimizer_nonce')); ?>'
                            }, function(response) {
                                btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                                btn.closest('.hz-license-card').find('strong').first().text('Just now');

                                if (response.success) {
                                    if (response.data.update_available) {
                                        result.html(
                                            '<div style="padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;display:flex;align-items:center;justify-content:space-between;">' +
                                            '<span style="font-size:12px;color:#16a34a;font-weight:600;">Update available: v' + response.data.latest_version + ' (you have v' + response.data.current_version + ')</span>' +
                                            '<a href="' + response.data.update_url + '" class="hz-btn hz-btn-primary" style="font-size:11px;padding:6px 14px;text-decoration:none;">Update Now</a>' +
                                            '</div>'
                                        );
                                    } else {
                                        result.html('<span style="font-size:11px;color:#16a34a;font-weight:600;">&#10003; You are running the latest version (v' + response.data.current_version + '). Latest on GitHub: v' + response.data.latest_version + '.</span>');
                                    }
                                } else {
                                    var msg = (response.data && response.data.message) ? response.data.message : 'Failed to check for updates';
                                    result.html('<span style="font-size:11px;color:#ef4444;">' + msg + '</span>');
                                }
                            }).fail(function() {
                                btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                                result.html('<span style="font-size:11px;color:#ef4444;">Connection error</span>');
                            });
                        });
                        </script>
                        </div>

                        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-top:1px solid #f3f4f6;">
                            <div>
                                <div style="font-size:12px;font-weight:600;color:#374151;"><?php esc_html_e('Auto-Updates', 'hozio-image-optimizer'); ?></div>
                                <div style="font-size:11px;color:#9ca3af;"><?php esc_html_e('Automatically install new versions when available', 'hozio-image-optimizer'); ?></div>
                            </div>
                            <label class="hozio-toggle">
                                <input type="checkbox" id="hozio-auto-update-toggle" value="1" <?php checked($auto_updates, '1'); ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <script>
                        jQuery('#hozio-auto-update-toggle').on('change', function() {
                            var enabled = jQuery(this).is(':checked') ? '1' : '0';
                            jQuery.post(ajaxurl, {
                                action: 'hozio_save_auto_update',
                                nonce: '<?php echo esc_js(wp_create_nonce('hozio_image_optimizer_nonce')); ?>',
                                enabled: enabled
                            });
                        });
                        </script>
                    </div>
                </div>

                </div><?php /* /panel license */ ?>
            </div><!-- /.hozio-settings-main -->
        </div><!-- /.hz-settings-content -->
    </div><!-- /.hz-shell -->

    <!-- Command Palette Modal -->
    <div class="hz-palette" id="hz-palette" role="dialog" aria-modal="true" aria-labelledby="hz-palette-label" hidden>
        <div class="hz-palette-backdrop" data-hz-palette-close></div>
        <div class="hz-palette-panel" role="document">
            <div class="hz-palette-search">
                <span class="dashicons dashicons-search"></span>
                <input type="text" id="hz-palette-input" autocomplete="off" spellcheck="false"
                       placeholder="<?php esc_attr_e('Search settings, fields, sections…', 'hozio-image-optimizer'); ?>"
                       aria-label="<?php esc_attr_e('Search settings', 'hozio-image-optimizer'); ?>">
                <button type="button" class="hz-palette-close" data-hz-palette-close aria-label="<?php esc_attr_e('Close search', 'hozio-image-optimizer'); ?>">
                    <span class="hz-kbd">Esc</span>
                </button>
            </div>
            <div class="hz-palette-results" id="hz-palette-results" role="listbox" aria-label="<?php esc_attr_e('Search results', 'hozio-image-optimizer'); ?>"></div>
            <div class="hz-palette-hint">
                <span><span class="hz-kbd">&uarr;</span><span class="hz-kbd">&darr;</span> <?php esc_html_e('Navigate', 'hozio-image-optimizer'); ?></span>
                <span><span class="hz-kbd">&crarr;</span> <?php esc_html_e('Open', 'hozio-image-optimizer'); ?></span>
                <span><span class="hz-kbd">Esc</span> <?php esc_html_e('Close', 'hozio-image-optimizer'); ?></span>
            </div>
        </div>
    </div>
</div><!-- /.wrap -->

<script>
jQuery(function($) {
    // Toggle API key input visibility
    $('#change-api-key-btn').on('click', function() {
        $('#api-key-status').slideUp(200, function() {
            $('#api-key-input').slideDown(200);
            $('#hozio_openai_api_key').focus();
        });
    });

    $('#cancel-change-key-btn').on('click', function() {
        $('#api-key-input').slideUp(200, function() {
            $('#hozio_openai_api_key').val('');
            $('#api-key-status').slideDown(200);
        });
    });

    // Test API connection
    $('#test-api-btn').on('click', function() {
        var btn = $(this);
        var result = $('#api-test-result');
        var $input = $('#hozio_openai_api_key');
        var apiKeyInput = $input.length ? $input.val() : '';

        btn.prop('disabled', true).find('.dashicons').addClass('spin');
        result.removeClass('success error').text('<?php echo esc_js(__('Testing...', 'hozio-image-optimizer')); ?>');

        $.post(ajaxurl, {
            action: 'hozio_test_api',
            nonce: hozioImageOptimizer.nonce,
            api_key: apiKeyInput || '',
            use_existing: (!apiKeyInput || apiKeyInput === '') ? '1' : '0'
        }, function(response) {
            btn.prop('disabled', false).find('.dashicons').removeClass('spin');

            if (response.success) {
                result.addClass('success').text('<?php echo esc_js(__('Connection successful!', 'hozio-image-optimizer')); ?>');
            } else {
                result.addClass('error').text(response.data.message || '<?php echo esc_js(__('Connection failed', 'hozio-image-optimizer')); ?>');
            }
        });
    });

    // Range slider value updates
    $('input[type="range"]').on('input', function() {
        var id = $(this).attr('name').replace('hozio_', '').replace('_', '-') + '-value';
        var valueSpan = $(this).closest('.hozio-range-container').find('.range-value span');
        valueSpan.text($(this).val());
    });

    // Toggle prompts section
    $('.toggle-prompts').on('click', function() {
        var section = $(this).closest('.hozio-card').find('.hozio-prompts-section');
        var icon = $(this).find('.dashicons');
        var text = $(this).contents().filter(function() { return this.nodeType === 3; });

        section.slideToggle();
        icon.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
        text[0].textContent = section.is(':visible') ? ' <?php echo esc_js(__('Collapse', 'hozio-image-optimizer')); ?>' : ' <?php echo esc_js(__('Expand', 'hozio-image-optimizer')); ?>';
    });

    // Model selection highlight
    $('.hozio-model-option input').on('change', function() {
        $('.hozio-model-option').removeClass('selected');
        $(this).closest('.hozio-model-option').addClass('selected');
    });

    // Template selection highlight
    $('.hozio-template-option input').on('change', function() {
        $(this).closest('.hozio-template-options').find('.hozio-template-option').removeClass('selected');
        $(this).closest('.hozio-template-option').addClass('selected');
    });

    // Cleanup backups button
    $('#cleanup-backups-btn').on('click', function() {
        if (confirm('<?php echo esc_js(__('This will delete all backups older than the retention period. Continue?', 'hozio-image-optimizer')); ?>')) {
            var btn = $(this);
            btn.prop('disabled', true).find('.dashicons').addClass('spin');

            $.post(ajaxurl, {
                action: 'hozio_cleanup_backups',
                nonce: hozioImageOptimizer.nonce
            }, function(response) {
                alert(response.data.message);
                location.reload();
            });
        }
    });

    // Test geocoding button
    $('#test-geocode-btn').on('click', function() {
        var btn = $(this);
        var loc = $('#test-geocode-location').val().trim();
        var $msg = $('#geocode-result');
        var $map = $('#geocode-map');

        if (!loc) {
            $msg.removeAttr('hidden').removeClass('hz-msg-ok hz-msg-err').addClass('hz-msg-err')
                .text('<?php echo esc_js(__('Please enter a location.', 'hozio-image-optimizer')); ?>');
            return;
        }

        btn.prop('disabled', true).find('.dashicons').addClass('spin');
        $msg.removeAttr('hidden').removeClass('hz-msg-ok hz-msg-err')
            .text('<?php echo esc_js(__('Geocoding…', 'hozio-image-optimizer')); ?>');
        $map.attr('hidden', true).empty();

        $.post(ajaxurl, {
            action: 'hozio_test_geocoding',
            nonce: hozioImageOptimizer.nonce,
            location: loc
        }, function(response) {
            btn.prop('disabled', false).find('.dashicons').removeClass('spin');
            if (response.success) {
                var lat = parseFloat(response.data.latitude);
                var lng = parseFloat(response.data.longitude);
                var name = response.data.display_name || '';
                var m = 0.018;
                var bbox = (lng-m)+','+(lat-m)+','+(lng+m)+','+(lat+m);
                var osmSrc = 'https://www.openstreetmap.org/export/embed.html?bbox='+bbox+'&layer=mapnik&marker='+lat+','+lng;
                var gmUrl  = 'https://www.google.com/maps?q='+lat+','+lng;
                $msg.addClass('hz-msg-ok').text('<?php echo esc_js(__('Found!', 'hozio-image-optimizer')); ?> ' + lat.toFixed(6) + ', ' + lng.toFixed(6));
                $map.html(
                    '<div class="hz-map-card">' +
                    '<iframe class="hz-map-frame" src="' + osmSrc + '" allowfullscreen loading="lazy"></iframe>' +
                    '<div class="hz-map-footer">' +
                    (name ? '<span class="hz-map-name">' + $('<span>').text(name).html() + '</span>' : '') +
                    '<span class="hz-map-coords">' + lat.toFixed(6) + ', ' + lng.toFixed(6) + '</span>' +
                    '<a href="' + gmUrl + '" target="_blank" rel="noopener" class="hz-map-gmaps-link"><span class="dashicons dashicons-external"></span> Google Maps</a>' +
                    '</div></div>'
                ).removeAttr('hidden');
            } else {
                $msg.addClass('hz-msg-err')
                    .text('<?php echo esc_js(__('Could not geocode that location. Try a different search term.', 'hozio-image-optimizer')); ?>');
            }
        }).fail(function() {
            btn.prop('disabled', false).find('.dashicons').removeClass('spin');
            $msg.addClass('hz-msg-err')
                .text('<?php echo esc_js(__('Request failed. Check your connection and try again.', 'hozio-image-optimizer')); ?>');
        });
    });
});
</script>
