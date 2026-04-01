<?php
/**
 * Plugin Name: WP Click Heatmap
 * Description: Collects frontend click coordinates and renders a heatmap in WordPress admin.
 * Version: 1.1.0
 * Author: WP Click Heatmap
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Text Domain: wp-click-heatmap
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCH_PLUGIN_VERSION', '1.1.0');
define('WCH_DB_SCHEMA_VERSION', '2');
define('WCH_TOP_SELECTORS_LIMIT', 15);
define('WCH_PLUGIN_FILE', __FILE__);
define('WCH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCH_TABLE', $GLOBALS['wpdb']->prefix . 'wch_clicks');
define('WCH_PATH_MAX_LEN', 512);
define('WCH_RATE_LIMIT_PER_MINUTE', 120);

function wch_get_options(): array
{
    return [
        'delete_data_on_uninstall' => (int) get_option('wch_delete_data_on_uninstall', 0),
        'require_consent' => (int) get_option('wch_require_consent', 0),
        'retention_days' => max(1, (int) get_option('wch_retention_days', 180)),
        'ignore_query_string' => (int) get_option('wch_ignore_query_string', 1),
    ];
}

/**
 * Create plugin DB table and schedule cleanup.
 */
function wch_maybe_migrate_schema(bool $force = false): void
{
    global $wpdb;

    $schema_version = (string) get_option('wch_db_schema_version', '0');
    if (!$force && version_compare($schema_version, WCH_DB_SCHEMA_VERSION, '>=')) {
        return;
    }

    $table_name = $wpdb->prefix . 'wch_clicks';

    $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
    if ($table_exists !== $table_name) {
        update_option('wch_db_schema_version', WCH_DB_SCHEMA_VERSION, false);
        return;
    }

    $column_exists = (string) $wpdb->get_var($wpdb->prepare(
        'SHOW COLUMNS FROM ' . $table_name . ' LIKE %s',
        'target_selector'
    ));
    if ($column_exists !== 'target_selector') {
        $wpdb->query('ALTER TABLE ' . $table_name . ' ADD COLUMN target_selector VARCHAR(255) NULL AFTER device_type');
    }

    $index_exists = (string) $wpdb->get_var('SHOW INDEX FROM ' . $table_name . " WHERE Key_name = 'page_key_created_at'");
    if ($index_exists === '') {
        $wpdb->query('ALTER TABLE ' . $table_name . ' ADD INDEX page_key_created_at (page_key, created_at)');
    }

    update_option('wch_db_schema_version', WCH_DB_SCHEMA_VERSION, false);
}

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
        target_selector VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY page_key (page_key),
        KEY created_at (created_at),
        KEY post_id (post_id),
        KEY device_type (device_type),
        KEY page_key_created_at (page_key, created_at)
    ) {$charset_collate};";

    dbDelta($sql);

    add_option('wch_delete_data_on_uninstall', 0);
    add_option('wch_require_consent', 0);
    add_option('wch_retention_days', 180);
    add_option('wch_ignore_query_string', 1);

    wch_maybe_migrate_schema(true);

    if (!wp_next_scheduled('wch_cleanup_cron')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wch_cleanup_cron');
    }
}
register_activation_hook(__FILE__, 'wch_activate');

function wch_deactivate(): void
{
    wp_clear_scheduled_hook('wch_cleanup_cron');
}
register_deactivation_hook(__FILE__, 'wch_deactivate');

add_action('plugins_loaded', 'wch_maybe_migrate_schema');

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

    add_submenu_page(
        'wch-click-heatmap',
        __('Settings', 'wp-click-heatmap'),
        __('Settings', 'wp-click-heatmap'),
        'manage_options',
        'wch-click-heatmap-settings',
        'wch_render_settings_page'
    );
}
add_action('admin_menu', 'wch_admin_menu');

function wch_register_settings(): void
{
    register_setting('wch_settings', 'wch_delete_data_on_uninstall', [
        'type' => 'integer',
        'sanitize_callback' => static function ($value): int {
            return $value ? 1 : 0;
        },
        'default' => 0,
    ]);

    register_setting('wch_settings', 'wch_require_consent', [
        'type' => 'integer',
        'sanitize_callback' => static function ($value): int {
            return $value ? 1 : 0;
        },
        'default' => 0,
    ]);

    register_setting('wch_settings', 'wch_ignore_query_string', [
        'type' => 'integer',
        'sanitize_callback' => static function ($value): int {
            return $value ? 1 : 0;
        },
        'default' => 1,
    ]);

    register_setting('wch_settings', 'wch_retention_days', [
        'type' => 'integer',
        'sanitize_callback' => static function ($value): int {
            $days = (int) $value;
            return max(1, min(3650, $days));
        },
        'default' => 180,
    ]);
}
add_action('admin_init', 'wch_register_settings');

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

    $options = wch_get_options();

    wp_localize_script(
        'wch-tracker',
        'wchTracker',
        [
            'restUrl' => esc_url_raw(rest_url('wch/v1/click')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'path'    => wch_normalize_path($path),
            'postId'  => is_singular() ? get_the_ID() : null,
            'requireConsent' => (bool) $options['require_consent'],
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

    $pages = get_posts([
        'post_type' => ['post', 'page'],
        'post_status' => 'publish',
        'numberposts' => 200,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    $page_options = array_map(
        static function (WP_Post $post): array {
            return [
                'id' => (int) $post->ID,
                'title' => html_entity_decode(get_the_title($post), ENT_QUOTES),
                'path' => wch_normalize_path((string) wp_parse_url(get_permalink($post), PHP_URL_PATH)),
            ];
        },
        $pages
    );

    wp_localize_script(
        'wch-admin',
        'wchAdmin',
        [
            'heatmapUrl' => esc_url_raw(rest_url('wch/v1/heatmap')),
            'defaultPath' => '/',
            'nonce' => wp_create_nonce('wp_rest'),
            'pages' => $page_options,
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
function wch_normalize_path(string $path, ?bool $ignore_query = null): string
{
    $path = wp_strip_all_tags($path);
    $path = trim($path);

    if ($path === '') {
        return '/';
    }

    if ($ignore_query === null) {
        $ignore_query = (bool) get_option('wch_ignore_query_string', 1);
    }

    $parts = wp_parse_url($path);
    if ($parts !== false && isset($parts['path'])) {
        $path = (string) $parts['path'];

        if (!$ignore_query && isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
    }

    $path = '/' . ltrim($path, '/');
    $path = preg_replace('#/+#', '/', $path) ?? '/';
    $path = substr($path, 0, WCH_PATH_MAX_LEN);

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

function wch_is_suspicious_request(WP_REST_Request $request): bool
{
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return true;
    }

    $bot_markers = ['bot', 'crawler', 'spider', 'headless', 'curl/', 'wget/', 'python-requests'];
    foreach ($bot_markers as $marker) {
        if (str_contains($ua, $marker)) {
            return true;
        }
    }

    $viewport_w = absint($request->get_param('viewport_w'));
    $viewport_h = absint($request->get_param('viewport_h'));
    $doc_h      = absint($request->get_param('doc_h'));

    if ($viewport_w < 200 || $viewport_h < 200 || $viewport_w > 8000 || $viewport_h > 8000 || $doc_h > 50000) {
        return true;
    }

    return false;
}

function wch_rate_limit_key(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        $ip = 'unknown';
    }

    return 'wch_rl_' . md5($ip . '|' . gmdate('YmdHi'));
}

function wch_is_rate_limited(): bool
{
    $key = wch_rate_limit_key();
    $hits = (int) get_transient($key);

    if ($hits >= WCH_RATE_LIMIT_PER_MINUTE) {
        return true;
    }

    set_transient($key, $hits + 1, MINUTE_IN_SECONDS + 5);
    return false;
}

function wch_is_duplicate_event(string $event_id): bool
{
    if ($event_id === '') {
        return false;
    }

    $event_key = 'wch_evt_' . md5($event_id);
    if (get_transient($event_key)) {
        return true;
    }

    set_transient($event_key, 1, 3);
    return false;
}


function wch_normalize_selector(?string $selector): ?string
{
    if (!is_string($selector) || $selector === '') {
        return null;
    }

    $selector = wp_strip_all_tags($selector);
    $selector = preg_replace('/\s+/', ' ', $selector) ?? '';
    $selector = trim($selector);

    if ($selector === '') {
        return null;
    }

    $selector = preg_replace("/[^a-zA-Z0-9_\\-\\.#:\\[\\]=\"'\\s>+~\\(\\),]/", '', $selector) ?? '';
    $selector = trim($selector);

    if ($selector === '') {
        return null;
    }

    return substr($selector, 0, 255);
}

/**
 * Save click via REST API.
 */
function wch_rest_collect_click(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;

    if (wch_is_rate_limited()) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'Too many events. Slow down.',
        ], 429);
    }

    if (wch_is_suspicious_request($request)) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'Suspicious request rejected.',
        ], 400);
    }

    $options = wch_get_options();
    if ($options['require_consent'] === 1 && !$request->get_param('consent')) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'Consent required before tracking.',
        ], 403);
    }

    $path = wch_normalize_path((string) $request->get_param('path'));
    if (strlen($path) > WCH_PATH_MAX_LEN) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'Path too long.',
        ], 400);
    }

    $event_id = sanitize_text_field((string) $request->get_param('event_id'));
    if (wch_is_duplicate_event($event_id)) {
        return new WP_REST_Response(['ok' => true, 'duplicate' => true], 200);
    }

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

    $target_selector = wch_normalize_selector($request->get_param('target_selector'));

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
            'target_selector' => $target_selector,
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

function wch_parse_date(string $value, bool $end_of_day = false): ?string
{
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    return $end_of_day ? ($value . ' 23:59:59') : ($value . ' 00:00:00');
}

/**
 * Get heatmap/click-point data and summary for admin.
 */
function wch_rest_get_heatmap(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;

    $raw_path = (string) $request->get_param('path');
    if (trim($raw_path) === '') {
        return new WP_REST_Response([
            'path' => '/',
            'mode' => 'heatmap',
            'items' => [],
            'summary' => [
                'total_clicks' => 0,
                'unique_buckets' => 0,
                'hottest_zones_count' => 0,
            ],
            'top_selectors' => [],
            'empty' => true,
        ]);
    }

    $path = wch_normalize_path($raw_path);
    $device_type = sanitize_key((string) $request->get_param('device_type'));
    $date_from = wch_parse_date(sanitize_text_field((string) $request->get_param('date_from')));
    $date_to = wch_parse_date(sanitize_text_field((string) $request->get_param('date_to')), true);
    $mode = sanitize_key((string) $request->get_param('mode'));
    $mode = in_array($mode, ['heatmap', 'click-points'], true) ? $mode : 'heatmap';
    $min_weight = max(1, absint($request->get_param('min_weight')));

    $where = 'WHERE page_key = %s';
    $params = [wch_make_page_key($path)];

    if (in_array($device_type, ['desktop', 'mobile', 'tablet', 'unknown'], true)) {
        $where .= ' AND device_type = %s';
        $params[] = $device_type;
    }

    if ($date_from !== null) {
        $where .= ' AND created_at >= %s';
        $params[] = $date_from;
    }

    if ($date_to !== null) {
        $where .= ' AND created_at <= %s';
        $params[] = $date_to;
    }

    $base_params = $params;

    if ($mode === 'click-points') {
        $sql = "
            SELECT
                x_ratio,
                y_ratio,
                target_selector,
                created_at
            FROM " . WCH_TABLE . " FORCE INDEX (page_key_created_at)
            {$where}
            ORDER BY id DESC
            LIMIT 5000
        ";
    } else {
        $sql = "
            SELECT
                ROUND(x_ratio, 3) AS x_ratio,
                ROUND(y_ratio, 3) AS y_ratio,
                COUNT(*) AS weight
            FROM " . WCH_TABLE . " FORCE INDEX (page_key_created_at)
            {$where}
            GROUP BY ROUND(x_ratio, 3), ROUND(y_ratio, 3)
            HAVING COUNT(*) >= %d
            ORDER BY weight DESC
            LIMIT 5000
        ";
        $params[] = $min_weight;
    }

    $prepared = $wpdb->prepare($sql, $params);
    $rows = $wpdb->get_results($prepared, ARRAY_A);

    $items = array_map(
        static function (array $row) use ($mode): array {
            $result = [
                'x_ratio' => (float) $row['x_ratio'],
                'y_ratio' => (float) $row['y_ratio'],
            ];

            if ($mode === 'click-points') {
                $result['target_selector'] = $row['target_selector'] ?: null;
                $result['created_at'] = (string) $row['created_at'];
                $result['weight'] = 1;
            } else {
                $result['weight'] = (int) $row['weight'];
            }

            return $result;
        },
        $rows ?: []
    );

    $summary_sql = "
        SELECT
            COUNT(*) AS total_clicks,
            COUNT(DISTINCT CONCAT(ROUND(x_ratio, 3), ':', ROUND(y_ratio, 3))) AS unique_buckets
        FROM " . WCH_TABLE . " FORCE INDEX (page_key_created_at)
        {$where}
    ";
    $summary_prepared = $wpdb->prepare($summary_sql, $base_params);
    $summary = $wpdb->get_row($summary_prepared, ARRAY_A);

    $hot_sql = "
        SELECT COUNT(*) FROM (
            SELECT 1
            FROM " . WCH_TABLE . " FORCE INDEX (page_key_created_at)
            {$where}
            GROUP BY ROUND(x_ratio, 3), ROUND(y_ratio, 3)
            HAVING COUNT(*) >= 5
        ) h
    ";
    $hot_prepared = $wpdb->prepare($hot_sql, $base_params);
    $hottest_zones_count = (int) $wpdb->get_var($hot_prepared);

    $selectors_sql = "
        SELECT target_selector, COUNT(*) AS clicks
        FROM " . WCH_TABLE . " FORCE INDEX (page_key_created_at)
        {$where}
        AND target_selector IS NOT NULL
        AND target_selector <> ''
        GROUP BY target_selector
        ORDER BY clicks DESC
        LIMIT " . (int) WCH_TOP_SELECTORS_LIMIT . "
    ";
    $selectors_prepared = $wpdb->prepare($selectors_sql, $base_params);
    $selectors_rows = $wpdb->get_results($selectors_prepared, ARRAY_A);

    return new WP_REST_Response([
        'path' => $path,
        'mode' => $mode,
        'items' => $items,
        'summary' => [
            'total_clicks' => (int) ($summary['total_clicks'] ?? 0),
            'unique_buckets' => (int) ($summary['unique_buckets'] ?? 0),
            'hottest_zones_count' => $hottest_zones_count,
        ],
        'top_selectors' => array_map(static function (array $row): array {
            return [
                'selector' => (string) $row['target_selector'],
                'clicks' => (int) $row['clicks'],
            ];
        }, $selectors_rows ?: []),
        'empty' => empty($items),
    ]);
}

function wch_cleanup_old_clicks(): void
{
    global $wpdb;

    $days = max(1, (int) get_option('wch_retention_days', 180));
    $threshold = gmdate('Y-m-d H:i:s', time() - (DAY_IN_SECONDS * $days));

    $wpdb->query(
        $wpdb->prepare(
            'DELETE FROM ' . WCH_TABLE . ' WHERE created_at < %s',
            $threshold
        )
    );
}
add_action('wch_cleanup_cron', 'wch_cleanup_old_clicks');

/**
 * Render admin page markup.
 */
function wch_render_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'wp-click-heatmap'));
    }
    ?>
    <div class="wrap wch-admin-wrap">
        <h1><?php echo esc_html__('Click Heatmap', 'wp-click-heatmap'); ?></h1>
        <p><?php echo esc_html__('Load any path and render click intensity over the page preview.', 'wp-click-heatmap'); ?></p>

        <div class="wch-controls-grid">
            <div>
                <label for="wch-page-select"><?php echo esc_html__('Post/Page', 'wp-click-heatmap'); ?></label>
                <select id="wch-page-select">
                    <option value=""><?php echo esc_html__('Manual path', 'wp-click-heatmap'); ?></option>
                </select>
            </div>

            <div>
                <label for="wch-path"><?php echo esc_html__('Path', 'wp-click-heatmap'); ?></label>
                <input type="text" id="wch-path" class="regular-text" value="/" placeholder="/sample-page" maxlength="512" />
            </div>

            <div>
                <label for="wch-date-from"><?php echo esc_html__('Date from', 'wp-click-heatmap'); ?></label>
                <input type="date" id="wch-date-from" />
            </div>

            <div>
                <label for="wch-date-to"><?php echo esc_html__('Date to', 'wp-click-heatmap'); ?></label>
                <input type="date" id="wch-date-to" />
            </div>

            <div>
                <label for="wch-device-type"><?php echo esc_html__('Device', 'wp-click-heatmap'); ?></label>
                <select id="wch-device-type">
                    <option value="all"><?php echo esc_html__('All', 'wp-click-heatmap'); ?></option>
                    <option value="desktop"><?php echo esc_html__('Desktop', 'wp-click-heatmap'); ?></option>
                    <option value="tablet"><?php echo esc_html__('Tablet', 'wp-click-heatmap'); ?></option>
                    <option value="mobile"><?php echo esc_html__('Mobile', 'wp-click-heatmap'); ?></option>
                </select>
            </div>

            <div>
                <label for="wch-min-weight"><?php echo esc_html__('Min weight', 'wp-click-heatmap'); ?></label>
                <input type="number" id="wch-min-weight" min="1" step="1" value="1" />
            </div>

            <div>
                <label for="wch-mode"><?php echo esc_html__('Mode', 'wp-click-heatmap'); ?></label>
                <select id="wch-mode">
                    <option value="heatmap"><?php echo esc_html__('Heatmap', 'wp-click-heatmap'); ?></option>
                    <option value="click-points"><?php echo esc_html__('Click points', 'wp-click-heatmap'); ?></option>
                </select>
            </div>
        </div>

        <div class="wch-buttons">
            <button id="wch-load" class="button button-primary"><?php echo esc_html__('Show analytics', 'wp-click-heatmap'); ?></button>
            <button id="wch-reset" class="button"><?php echo esc_html__('Reset filters', 'wp-click-heatmap'); ?></button>
            <span id="wch-loader" class="spinner"></span>
        </div>

        <div class="wch-summary">
            <div><strong><?php echo esc_html__('Total clicks', 'wp-click-heatmap'); ?>:</strong> <span id="wch-total-clicks">0</span></div>
            <div><strong><?php echo esc_html__('Unique buckets', 'wp-click-heatmap'); ?>:</strong> <span id="wch-unique-buckets">0</span></div>
            <div><strong><?php echo esc_html__('Hottest zones', 'wp-click-heatmap'); ?>:</strong> <span id="wch-hot-zones">0</span></div>
        </div>

        <div class="wch-top-selectors">
            <h2><?php echo esc_html__('Top selectors', 'wp-click-heatmap'); ?></h2>
            <ol id="wch-top-selectors-list"></ol>
        </div>

        <div class="wch-legend" aria-hidden="true">
            <span><?php echo esc_html__('Low', 'wp-click-heatmap'); ?></span>
            <div class="wch-legend-bar"></div>
            <span><?php echo esc_html__('High', 'wp-click-heatmap'); ?></span>
        </div>

        <div class="wch-preview-shell">
            <iframe id="wch-preview" src="about:blank" title="Page preview"></iframe>
            <div id="wch-heatmap-layer" aria-hidden="true"></div>
            <div id="wch-preview-notice" class="wch-preview-notice" hidden></div>
        </div>

        <p id="wch-status" class="description"></p>
    </div>
    <?php
}

function wch_render_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'wp-click-heatmap'));
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('WP Click Heatmap Settings', 'wp-click-heatmap'); ?></h1>

        <form method="post" action="options.php">
            <?php settings_fields('wch_settings'); ?>
            <?php wp_nonce_field('wch_settings_nonce_action', 'wch_settings_nonce'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Delete data on uninstall', 'wp-click-heatmap'); ?></th>
                    <td><label><input type="checkbox" name="wch_delete_data_on_uninstall" value="1" <?php checked((int) get_option('wch_delete_data_on_uninstall', 0), 1); ?> /> <?php echo esc_html__('Delete clicks table and options when plugin is uninstalled.', 'wp-click-heatmap'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Require consent before tracking', 'wp-click-heatmap'); ?></th>
                    <td><label><input type="checkbox" name="wch_require_consent" value="1" <?php checked((int) get_option('wch_require_consent', 0), 1); ?> /> <?php echo esc_html__('Track only when client sends consent=true.', 'wp-click-heatmap'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Retention days', 'wp-click-heatmap'); ?></th>
                    <td><input type="number" name="wch_retention_days" min="1" max="3650" value="<?php echo esc_attr((string) get_option('wch_retention_days', 180)); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Ignore query string', 'wp-click-heatmap'); ?></th>
                    <td><label><input type="checkbox" name="wch_ignore_query_string" value="1" <?php checked((int) get_option('wch_ignore_query_string', 1), 1); ?> /> <?php echo esc_html__('Default enabled; groups /page?a=1 and /page?b=2 under same path.', 'wp-click-heatmap'); ?></label></td>
                </tr>
            </table>

            <?php submit_button(__('Save settings', 'wp-click-heatmap')); ?>
        </form>
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
    if ($delete === 1) {
        $table_name = $wpdb->prefix . 'wch_clicks';
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
    }

    delete_option('wch_delete_data_on_uninstall');
    delete_option('wch_require_consent');
    delete_option('wch_retention_days');
    delete_option('wch_ignore_query_string');
    delete_option('wch_db_schema_version');
}
register_uninstall_hook(__FILE__, 'wch_uninstall');
