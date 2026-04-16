<?php
/**
 * Optimization Report Generator
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Report_Generator {

    /**
     * Generate optimization report data
     */
    public static function generate_report_data() {
        global $wpdb;

        // Get basic statistics
        $total_images = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image/%'"
        );

        $total_processed = get_option('hozio_total_images_processed', 0);
        $total_saved = get_option('hozio_total_bytes_saved', 0);

        // Get backup stats
        $backup_manager = new Hozio_Image_Optimizer_Backup_Manager();
        $backup_stats = $backup_manager->get_backup_stats();

        // Get API usage stats
        $api_usage = get_option('hozio_api_usage_stats', array(
            'total_requests' => 0,
            'total_tokens' => 0,
            'estimated_cost' => 0,
        ));

        // Get image breakdown by type
        $image_types = $wpdb->get_results(
            "SELECT post_mime_type, COUNT(*) as count
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image/%'
            GROUP BY post_mime_type
            ORDER BY count DESC"
        );

        // Get server capabilities
        $capabilities = Hozio_Image_Optimizer::get_server_capabilities();

        return array(
            'generated_at' => current_time('mysql'),
            'site_name' => get_bloginfo('name'),
            'site_url' => get_site_url(),
            'statistics' => array(
                'total_images' => $total_images,
                'total_processed' => $total_processed,
                'total_saved' => $total_saved,
                'total_saved_formatted' => Hozio_Image_Optimizer_Helpers::format_bytes($total_saved),
                'percent_optimized' => $total_images > 0 ? round(($total_processed / $total_images) * 100, 1) : 0,
            ),
            'backups' => array(
                'total_backups' => $backup_stats['total_backups'],
                'total_images_backed_up' => $backup_stats['total_images'],
                'storage_used' => $backup_stats['total_size_formatted'],
            ),
            'api_usage' => array(
                'total_requests' => $api_usage['total_requests'],
                'total_tokens' => $api_usage['total_tokens'],
                'estimated_cost' => $api_usage['estimated_cost'],
            ),
            'image_types' => $image_types,
            'server' => array(
                'gd' => $capabilities['gd'] ? 'Available' : 'Not Available',
                'imagick' => $capabilities['imagick'] ? 'Available' : 'Not Available',
                'webp' => $capabilities['webp'] ? 'Supported' : 'Not Supported',
                'avif' => $capabilities['avif'] ? 'Supported' : 'Not Supported',
                'exiftool' => $capabilities['exiftool'] ? ($capabilities['exiftool'] === 'bundled' ? 'Bundled' : 'System') : 'Not Available',
                'memory_limit' => $capabilities['memory_limit'],
            ),
            'settings' => array(
                'compression_enabled' => get_option('hozio_enable_compression', true),
                'compression_quality' => get_option('hozio_compression_quality', 82),
                'webp_conversion' => get_option('hozio_convert_to_webp', true),
                'avif_conversion' => get_option('hozio_convert_to_avif', false),
                'ai_rename' => get_option('hozio_enable_ai_rename', true),
                'ai_alt_text' => get_option('hozio_enable_ai_alt_text', true),
                'geolocation' => get_option('hozio_enable_geolocation', true),
                'auto_optimize' => get_option('hozio_auto_optimize_on_upload', false),
            ),
        );
    }

    /**
     * Generate HTML report
     */
    public static function generate_html_report() {
        $data = self::generate_report_data();

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php printf(__('Image Optimization Report - %s', 'hozio-image-optimizer'), esc_html($data['site_name'])); ?></title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; color: #1e293b; line-height: 1.6; }
                .container { max-width: 800px; margin: 0 auto; padding: 40px 20px; }
                .header { text-align: center; margin-bottom: 40px; }
                .header h1 { font-size: 28px; font-weight: 700; color: #6366f1; margin-bottom: 8px; }
                .header p { color: #64748b; }
                .card { background: #fff; border-radius: 16px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
                .card h2 { font-size: 18px; font-weight: 600; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
                .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
                .stat-item { text-align: center; padding: 20px; background: #f8fafc; border-radius: 12px; }
                .stat-value { font-size: 32px; font-weight: 700; color: #6366f1; display: block; }
                .stat-label { font-size: 13px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
                .highlight-stat { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
                .highlight-stat .stat-value, .highlight-stat .stat-label { color: #fff; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
                th { font-weight: 600; color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
                .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
                .badge-success { background: #dcfce7; color: #166534; }
                .badge-warning { background: #fef3c7; color: #92400e; }
                .badge-info { background: #dbeafe; color: #1e40af; }
                .footer { text-align: center; margin-top: 40px; color: #94a3b8; font-size: 13px; }
                .progress-bar { background: #e2e8f0; border-radius: 10px; height: 12px; overflow: hidden; margin: 10px 0; }
                .progress-fill { background: linear-gradient(90deg, #6366f1, #8b5cf6); height: 100%; border-radius: 10px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1><?php esc_html_e('Image Optimization Report', 'hozio-image-optimizer'); ?></h1>
                    <p><?php echo esc_html($data['site_name']); ?> &bull; <?php echo esc_html(date('F j, Y', strtotime($data['generated_at']))); ?></p>
                </div>

                <div class="card">
                    <h2><?php esc_html_e('Optimization Summary', 'hozio-image-optimizer'); ?></h2>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <span class="stat-value"><?php echo esc_html(number_format($data['statistics']['total_images'])); ?></span>
                            <span class="stat-label"><?php esc_html_e('Total Images', 'hozio-image-optimizer'); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?php echo esc_html(number_format($data['statistics']['total_processed'])); ?></span>
                            <span class="stat-label"><?php esc_html_e('Optimized', 'hozio-image-optimizer'); ?></span>
                        </div>
                        <div class="stat-item highlight-stat">
                            <span class="stat-value"><?php echo esc_html($data['statistics']['total_saved_formatted']); ?></span>
                            <span class="stat-label"><?php esc_html_e('Space Saved', 'hozio-image-optimizer'); ?></span>
                        </div>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo esc_attr($data['statistics']['percent_optimized']); ?>%;"></div>
                    </div>
                    <p style="text-align: center; color: #64748b; font-size: 14px;">
                        <?php printf(esc_html__('%s%% of images optimized', 'hozio-image-optimizer'), $data['statistics']['percent_optimized']); ?>
                    </p>
                </div>

                <div class="card">
                    <h2><?php esc_html_e('Image Types', 'hozio-image-optimizer'); ?></h2>
                    <table>
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Format', 'hozio-image-optimizer'); ?></th>
                                <th><?php esc_html_e('Count', 'hozio-image-optimizer'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['image_types'] as $type) : ?>
                            <tr>
                                <td><?php echo esc_html(strtoupper(str_replace('image/', '', $type->post_mime_type))); ?></td>
                                <td><?php echo esc_html(number_format($type->count)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <h2><?php esc_html_e('API Usage', 'hozio-image-optimizer'); ?></h2>
                    <table>
                        <tr>
                            <td><?php esc_html_e('Total API Requests', 'hozio-image-optimizer'); ?></td>
                            <td><strong><?php echo esc_html(number_format($data['api_usage']['total_requests'])); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Total Tokens Used', 'hozio-image-optimizer'); ?></td>
                            <td><strong><?php echo esc_html(number_format($data['api_usage']['total_tokens'])); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Estimated Cost', 'hozio-image-optimizer'); ?></td>
                            <td><strong>$<?php echo esc_html(number_format($data['api_usage']['estimated_cost'], 4)); ?></strong></td>
                        </tr>
                    </table>
                </div>

                <div class="card">
                    <h2><?php esc_html_e('Current Settings', 'hozio-image-optimizer'); ?></h2>
                    <table>
                        <tr>
                            <td><?php esc_html_e('Compression', 'hozio-image-optimizer'); ?></td>
                            <td>
                                <?php if ($data['settings']['compression_enabled']) : ?>
                                    <span class="badge badge-success"><?php esc_html_e('Enabled', 'hozio-image-optimizer'); ?></span>
                                    (<?php echo esc_html($data['settings']['compression_quality']); ?>%)
                                <?php else : ?>
                                    <span class="badge badge-warning"><?php esc_html_e('Disabled', 'hozio-image-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('WebP Conversion', 'hozio-image-optimizer'); ?></td>
                            <td>
                                <span class="badge <?php echo $data['settings']['webp_conversion'] ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo $data['settings']['webp_conversion'] ? esc_html__('Enabled', 'hozio-image-optimizer') : esc_html__('Disabled', 'hozio-image-optimizer'); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('AI Renaming', 'hozio-image-optimizer'); ?></td>
                            <td>
                                <span class="badge <?php echo $data['settings']['ai_rename'] ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo $data['settings']['ai_rename'] ? esc_html__('Enabled', 'hozio-image-optimizer') : esc_html__('Disabled', 'hozio-image-optimizer'); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('AI Alt Text', 'hozio-image-optimizer'); ?></td>
                            <td>
                                <span class="badge <?php echo $data['settings']['ai_alt_text'] ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo $data['settings']['ai_alt_text'] ? esc_html__('Enabled', 'hozio-image-optimizer') : esc_html__('Disabled', 'hozio-image-optimizer'); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Auto-Optimize on Upload', 'hozio-image-optimizer'); ?></td>
                            <td>
                                <span class="badge <?php echo $data['settings']['auto_optimize'] ? 'badge-success' : 'badge-info'; ?>">
                                    <?php echo $data['settings']['auto_optimize'] ? esc_html__('Enabled', 'hozio-image-optimizer') : esc_html__('Disabled', 'hozio-image-optimizer'); ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card">
                    <h2><?php esc_html_e('Server Capabilities', 'hozio-image-optimizer'); ?></h2>
                    <table>
                        <tr>
                            <td><?php esc_html_e('GD Library', 'hozio-image-optimizer'); ?></td>
                            <td><span class="badge <?php echo $data['server']['gd'] === 'Available' ? 'badge-success' : 'badge-warning'; ?>"><?php echo esc_html($data['server']['gd']); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('ImageMagick', 'hozio-image-optimizer'); ?></td>
                            <td><span class="badge <?php echo $data['server']['imagick'] === 'Available' ? 'badge-success' : 'badge-info'; ?>"><?php echo esc_html($data['server']['imagick']); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('WebP Support', 'hozio-image-optimizer'); ?></td>
                            <td><span class="badge <?php echo $data['server']['webp'] === 'Supported' ? 'badge-success' : 'badge-warning'; ?>"><?php echo esc_html($data['server']['webp']); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('ExifTool', 'hozio-image-optimizer'); ?></td>
                            <td><span class="badge <?php echo $data['server']['exiftool'] !== 'Not Available' ? 'badge-success' : 'badge-info'; ?>"><?php echo esc_html($data['server']['exiftool']); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Memory Limit', 'hozio-image-optimizer'); ?></td>
                            <td><strong><?php echo esc_html($data['server']['memory_limit']); ?></strong></td>
                        </tr>
                    </table>
                </div>

                <div class="footer">
                    <p><?php esc_html_e('Generated by Hozio Image Optimizer', 'hozio-image-optimizer'); ?></p>
                    <p><?php echo esc_html($data['site_url']); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Send report via email
     */
    public static function send_email_report($email) {
        $html = self::generate_html_report();
        $site_name = get_bloginfo('name');

        $subject = sprintf(__('[%s] Image Optimization Report', 'hozio-image-optimizer'), $site_name);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        );

        $sent = wp_mail($email, $subject, $html, $headers);

        return $sent;
    }
}
