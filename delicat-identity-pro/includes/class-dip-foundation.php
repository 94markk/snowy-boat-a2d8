<?php
defined('ABSPATH') || exit;

/**
 * Phase 1 foundation services: schema management, safe upgrades and diagnostics.
 * This class intentionally does not replace the existing authentication flow.
 */
final class DIP_Foundation {
    const DB_VERSION = '5.4.0';
    const DB_OPTION = 'dip_db_version';
    const BACKUP_OPTION = 'dip_upgrade_settings_backup';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 30);
        add_action('admin_init', [__CLASS__, 'maybe_upgrade']);
        add_action('admin_post_dip_foundation_repair', [__CLASS__, 'repair']);
        add_filter('plugin_action_links_' . plugin_basename(DIP_FILE), [__CLASS__, 'action_links']);
    }

    public static function activate() {
        self::backup_settings();
        self::install_schema();
        update_option(self::DB_OPTION, self::DB_VERSION, false);
        update_option('dip_last_successful_activation', time(), false);
    }

    public static function maybe_upgrade() {
        if (!current_user_can('manage_options')) return;
        if (version_compare((string) get_option(self::DB_OPTION, '0'), self::DB_VERSION, '>=')) return;
        self::activate();
    }

    private static function backup_settings() {
        $settings = get_option(DIP_Plugin::OPTION, []);
        if (!is_array($settings)) $settings = [];
        update_option(self::BACKUP_OPTION, [
            'created_at' => time(),
            'plugin_version' => defined('DIP_VERSION') ? DIP_VERSION : '',
            'settings' => $settings,
        ], false);
    }

    public static function install_schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sessions = $wpdb->prefix . 'dip_sessions';
        $tokens = $wpdb->prefix . 'dip_tokens';
        $events = $wpdb->prefix . 'dip_events';

        dbDelta("CREATE TABLE {$sessions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            session_hash char(64) NOT NULL,
            device_hash char(64) NOT NULL DEFAULT '',
            ip_hash char(64) NOT NULL DEFAULT '',
            user_agent varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            expires_at datetime NULL,
            revoked_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_hash (session_hash),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$tokens} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            token_hash char(64) NOT NULL,
            token_type varchar(32) NOT NULL DEFAULT 'access',
            scope varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            revoked_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(64) NOT NULL,
            severity varchar(16) NOT NULL DEFAULT 'info',
            provider varchar(32) NOT NULL DEFAULT '',
            ip_hash char(64) NOT NULL DEFAULT '',
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};");
    }

    public static function health() {
        global $wpdb;
        $checks = [];
        $checks['wordpress'] = version_compare(get_bloginfo('version'), '6.2', '>=');
        $checks['php'] = version_compare(PHP_VERSION, '7.4', '>=');
        $checks['https'] = DIP_Request::is_secure();
        $checks['rest_api'] = (bool) get_option('permalink_structure');
        $checks['woocommerce'] = class_exists('WooCommerce');
        $checks['crypto'] = function_exists('openssl_encrypt') || function_exists('sodium_crypto_secretbox');
        $checks['cron'] = !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON;
        $checks['settings'] = is_array(get_option(DIP_Plugin::OPTION, []));
        foreach (['dip_sessions','dip_tokens','dip_events'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            $checks['table_' . $suffix] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        }
        $passed = count(array_filter($checks));
        return ['checks' => $checks, 'passed' => $passed, 'total' => count($checks), 'score' => (int) round(($passed / max(1, count($checks))) * 100)];
    }

    public static function admin_menu() {
        add_submenu_page('options-general.php', 'Delicat Identity Health', 'Identity Health', 'manage_options', 'dip-foundation-health', [__CLASS__, 'render_page']);
    }

    public static function action_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=dip-foundation-health')) . '">Health</a>');
        return $links;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        $health = self::health();
        $labels = [
            'wordpress'=>'WordPress 6.2+','php'=>'PHP 7.4+','https'=>'HTTPS','rest_api'=>'Permalinks / REST readiness',
            'woocommerce'=>'WooCommerce detected','crypto'=>'Encryption support','cron'=>'WP-Cron available','settings'=>'Settings readable',
            'table_dip_sessions'=>'Sessions table','table_dip_tokens'=>'Tokens table','table_dip_events'=>'Events table',
        ];
        echo '<div class="wrap"><h1>Delicat Identity Pro — Foundation Health</h1>';
        echo '<p><strong>Phase 1 score: ' . esc_html($health['score']) . '%</strong> (' . esc_html($health['passed']) . '/' . esc_html($health['total']) . ' checks)</p>';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Check</th><th>Status</th></tr></thead><tbody>';
        foreach ($health['checks'] as $key=>$ok) echo '<tr><td>' . esc_html($labels[$key] ?? $key) . '</td><td><strong>' . ($ok ? '✓ OK' : '⚠ Attention') . '</strong></td></tr>';
        echo '</tbody></table><p style="margin-top:18px">';
        echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_foundation_repair'), 'dip_foundation_repair')) . '">Repair schema and schedules</a>';
        echo '</p><p>This repair is non-destructive. It recreates missing tables and preserves existing settings and account links.</p></div>';
    }

    public static function repair() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('dip_foundation_repair');
        self::backup_settings();
        self::install_schema();
        update_option(self::DB_OPTION, self::DB_VERSION, false);
        wp_safe_redirect(add_query_arg(['page'=>'dip-foundation-health','repaired'=>'1'], admin_url('options-general.php')));
        exit;
    }
}
