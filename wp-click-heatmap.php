<?php
/**
 * Plugin Name: WP Click Heatmap
 * Description: Collects frontend click coordinates and renders a heatmap in WordPress admin.
 * Version: 1.0.0
 * Author: WP Click Heatmap
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Text Domain: wp-click-heatmap
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCH_PLUGIN_VERSION', '1.0.0');
define('WCH_PLUGIN_FILE', __FILE__);
define('WCH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCH_TABLE', $GLOBALS['wpdb']->prefix . 'wch_clicks');

/**
 * Create plugin DB table.
 */
function wch_activate(): void
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_name      = $wpdb->prefix . 'wch_clicks';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        page_key VARCHAR(191) NOT NULL,
        url_path TEXT NOT NULL,
        post_id BIGINT UNSIGNED NULL,
        x_ratio DECIMAL(6,5) NOT NULL,
        y_ratio DECIMAL(6,5) NOT NULL,
        viewport_w INT UNSIGNED NOT NULL,
        viewport_h INT UNSIGNED NOT NULL,
        doc_w INT UNSIGNED NOT NULL,
        doc_h INT UNSIGNED NOT NULL,
        device_type VARCHAR(20) NOT NULL DEFAULT 'unknown',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY page_key (page_key),
        KEY created_at (created_at),
        KEY post_id (post_id),
        KEY device_type (device_type)
    ) {$charset_collate};";

    dbDelta($sql);

    add_option('wch_delete_data_on_uninstall', 0);
}
register_activation_hook(__FILE__, 'wch_activate');

/**
 * Add admin menu page.
 */
function wch_admin_menu(): void
{
    add_menu_page(
        __('Click Heatmap', 'wp-click-heatmap'),
        __('Click Heatmap', 'wp-click-heatmap'),
        'manage_options',
        'wch-click-heatmap',
        'wch_render_admin_page',
        'dashicons-chart-area',
        80
    );
}
add_action('admin_menu', 'wch_admin_menu');

/**
 * Enqueue frontend click tracker on public pages.
 */
function wch_enqueue_frontend_assets(): void
{
    if (is_admin()) {
        return;
    }

    if (is_user_logged_in() && current_user_can('edit_pages')) {
        return;
    }

    wp_enqueue_script(
        'wch-tracker',
        WCH_PLUGIN_URL . 'assets/tracker.js',
        [],
        WCH_PLUGIN_VERSION,
        true
    );

    $path = wp_parse_url(home_url(add_query_arg([], $GLOBALS['wp']->request ?? '')), PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/';
    }

    wp_localize_script(
        'wch-tracker',
        'wchTracker',
        [
            'restUrl' => esc_url_raw(rest_url('wch/v1/click')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'path'    => wch_normalize_path($path),
            'postId'  => is_singular() ? get_the_ID() : null,
        ]
    );
}
add_action('wp_enqueue_scripts', 'wch_enqueue_frontend_assets');

/**
 * Enqueue admin page assets.
 */
function wch_enqueue_admin_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_wch-click-heatmap') {
        return;
    }

    wp_enqueue_style(
        'wch-admin',
        WCH_PLUGIN_URL . 'assets/admin.css',
        [],
        WCH_PLUGIN_VERSION
    );

    wp_enqueue_script(
        'wch-heatmap-lib',
        WCH_PLUGIN_URL . 'vendor/heatmap.min.js',
        [],
        WCH_PLUGIN_VERSION,
        true
    );

    wp_enqueue_script(
        'wch-admin',
        WCH_PLUGIN_URL . 'assets/admin.js',
        ['wch-heatmap-lib'],
        WCH_PLUGIN_VERSION,
        true
    );

    wp_localize_script(
        'wch-admin',
        'wchAdmin',
        [
            'heatmapUrl' => esc_url_raw(rest_url('wch/v1/heatmap')),
            'defaultPath' => '/',
            'nonce' => wp_create_nonce('wp_rest'),
        ]
    );
}
add_action('admin_enqueue_scripts', 'wch_enqueue_admin_assets');

/**
 * Register REST routes.
 */
function wch_register_rest_routes(): void
{
    register_rest_route(
        'wch/v1',
        '/click',
        [
            'methods' => 'POST',
            'callback' => 'wch_rest_collect_click',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'wch/v1',
        '/heatmap',
        [
            'methods' => 'GET',
            'callback' => 'wch_rest_get_heatmap',
            'permission_callback' => static function (): bool {
                return current_user_can('manage_options');
            },
        ]
    );
}
add_action('rest_api_init', 'wch_register_rest_routes');

/**
 * Normalize incoming path values.
 */
function wch_normalize_path(string $path): string
{
    $path = wp_strip_all_tags($path);
    $path = trim($path);

    if ($path === '') {
        return '/';
    }

    $parts = wp_parse_url($path);
    if ($parts !== false && isset($parts['path'])) {
        $path = (string) $parts['path'];
    }

    $path = '/' . ltrim($path, '/');
    $path = preg_replace('#/+#', '/', $path) ?? '/';

    return untrailingslashit($path) === '' ? '/' : untrailingslashit($path);
}

/**
 * Build a compact key for grouping clicks by path.
 */
function wch_make_page_key(string $path): string
{
    return md5(wch_normalize_path($path));
}

/**
 * Guess device type from user-agent.
 */
function wch_detect_device_type(): string
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string) $_SERVER['HTTP_USER_AGENT']) : '';

    if ($ua === '') {
        return 'unknown';
    }

    if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
        return 'tablet';
    }

    if (str_contains($ua, 'mobile') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
        return 'mobile';
    }

    return 'desktop';
}

/**
 * Save click via REST API.
 */
function wch_rest_collect_click(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;

    $path = wch_normalize_path((string) $request->get_param('path'));

    $post_id = $request->get_param('post_id');
    $post_id = is_numeric($post_id) ? absint($post_id) : null;

    $x_ratio = (float) $request->get_param('x_ratio');
    $y_ratio = (float) $request->get_param('y_ratio');

    if ($x_ratio < 0 || $x_ratio > 1 || $y_ratio < 0 || $y_ratio > 1) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'Invalid ratio values. Must be between 0 and 1.',
        ], 400);
    }

    $viewport_w = absint($request->get_param('viewport_w'));
    $viewport_h = absint($request->get_param('viewport_h'));
    $doc_w      = absint($request->get_param('doc_w'));
    $doc_h      = absint($request->get_param('doc_h'));

    $device_type = sanitize_key((string) $request->get_param('device_type'));
    $allowed_device_types = ['desktop', 'mobile', 'tablet', 'unknown'];
    if (!in_array($device_type, $allowed_device_types, true)) {
        $device_type = wch_detect_device_type();
    }

    $inserted = $wpdb->insert(
        WCH_TABLE,
        [
            'page_key' => wch_make_page_key($path),
            'url_path' => $path,
            'post_id' => $post_id ?: null,
            'x_ratio' => $x_ratio,
            'y_ratio' => $y_ratio,
            'viewport_w' => $viewport_w,
            'viewport_h' => $viewport_h,
            'doc_w' => $doc_w,
            'doc_h' => $doc_h,
            'device_type' => $device_type,
            'created_at' => current_time('mysql', true),
        ],
        [
            '%s',
            '%s',
            '%d',
            '%f',
            '%f',
            '%d',
            '%d',
            '%d',
            '%d',
            '%s',
            '%s',
        ]
    );

    if ($inserted === false) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'Failed to store click.',
        ], 500);
    }

    return new WP_REST_Response(['ok' => true], 201);
}

/**
 * Get aggregated heatmap points for admin.
 *
 * Ready for future filters:
 * - device_type
 * - date_from/date_to
 */
function wch_rest_get_heatmap(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;

    $path = wch_normalize_path((string) $request->get_param('path'));

    // Future extension (currently optional).
    $device_type = sanitize_key((string) $request->get_param('device_type'));
    $date_from   = sanitize_text_field((string) $request->get_param('date_from'));
    $date_to     = sanitize_text_field((string) $request->get_param('date_to'));

    $where = 'WHERE page_key = %s';
    $params = [wch_make_page_key($path)];

    if (in_array($device_type, ['desktop', 'mobile', 'tablet', 'unknown'], true)) {
        $where .= ' AND device_type = %s';
        $params[] = $device_type;
    }

    if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $where .= ' AND created_at >= %s';
        $params[] = $date_from . ' 00:00:00';
    }

    if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $where .= ' AND created_at <= %s';
        $params[] = $date_to . ' 23:59:59';
    }

    $sql = "
        SELECT
            ROUND(x_ratio, 3) AS x_ratio,
            ROUND(y_ratio, 3) AS y_ratio,
            COUNT(*) AS weight
        FROM " . WCH_TABLE . "
        {$where}
        GROUP BY ROUND(x_ratio, 3), ROUND(y_ratio, 3)
        ORDER BY weight DESC
        LIMIT 5000
    ";

    $prepared = $wpdb->prepare($sql, $params);
    $rows = $wpdb->get_results($prepared, ARRAY_A);

    $items = array_map(
        static function (array $row): array {
            return [
                'x_ratio' => (float) $row['x_ratio'],
                'y_ratio' => (float) $row['y_ratio'],
                'weight' => (int) $row['weight'],
            ];
        },
        $rows ?: []
    );

    return new WP_REST_Response([
        'path' => $path,
        'items' => $items,
    ]);
}

/**
 * Render admin page markup.
 */
function wch_render_admin_page(): void
{
    ?>
    <div class="wrap wch-admin-wrap">
        <h1><?php echo esc_html__('Click Heatmap', 'wp-click-heatmap'); ?></h1>
        <p><?php echo esc_html__('Load any path and render click intensity over the page preview.', 'wp-click-heatmap'); ?></p>

        <div class="wch-controls">
            <label for="wch-path"><?php echo esc_html__('Path', 'wp-click-heatmap'); ?></label>
            <input type="text" id="wch-path" class="regular-text" value="/" placeholder="/sample-page" />
            <button id="wch-load" class="button button-primary"><?php echo esc_html__('Показать тепловую карту', 'wp-click-heatmap'); ?></button>
        </div>

        <div class="wch-preview-shell">
            <iframe id="wch-preview" src="about:blank" title="Page preview"></iframe>
            <div id="wch-heatmap-layer" aria-hidden="true"></div>
        </div>

        <p id="wch-status" class="description"></p>
    </div>
    <?php
}

/**
 * Optional data cleanup on uninstall.
 */
function wch_uninstall(): void
{
    global $wpdb;

    $delete = (int) get_option('wch_delete_data_on_uninstall', 0);
    if ($delete !== 1) {
        return;
    }

    $table_name = $wpdb->prefix . 'wch_clicks';
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
    delete_option('wch_delete_data_on_uninstall');
}
register_uninstall_hook(__FILE__, 'wch_uninstall');
