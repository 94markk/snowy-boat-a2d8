<?php
defined('ABSPATH') || exit;

/**
 * Phase 14 performance and maintenance layer.
 * It deliberately limits maintenance to Delicat Identity data and uses
 * ANALYZE TABLE instead of destructive/locking optimization operations.
 */
final class DIP_Performance {
    const CRON_HOOK = 'dip_performance_maintenance';
    const CACHE_GROUP = 'delicat_identity';
    const HEALTH_KEY = 'phase14_health';
    private static $settings_cb;

    public static function init($settings_cb) {
        self::$settings_cb = $settings_cb;
        add_action(self::CRON_HOOK, [__CLASS__, 'scheduled_maintenance']);
        add_action('update_option_' . DIP_Plugin::OPTION, [__CLASS__, 'settings_changed'], 10, 2);
        add_action('upgrader_process_complete', [__CLASS__, 'purge_cache'], 20, 2);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 3 * HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function uninstall_schedule() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);
    }

    private static function settings() {
        return is_callable(self::$settings_cb) ? (array) call_user_func(self::$settings_cb) : [];
    }

    public static function should_load_front_assets() {
        if (is_admin()) return false;
        if (function_exists('is_account_page') && is_account_page()) return true;
        if (function_exists('is_checkout') && is_checkout()) return true;
        if (function_exists('is_cart') && is_cart()) return true;
        return false;
    }

    public static function cache_get($key) {
        $found = false;
        $value = wp_cache_get($key, self::CACHE_GROUP, false, $found);
        if ($found) return $value;
        $value = get_transient('dip_' . $key);
        if (false !== $value) wp_cache_set($key, $value, self::CACHE_GROUP, 300);
        return $value;
    }

    public static function cache_set($key, $value, $ttl = 300) {
        $ttl = min(HOUR_IN_SECONDS, max(30, absint($ttl)));
        wp_cache_set($key, $value, self::CACHE_GROUP, $ttl);
        set_transient('dip_' . $key, $value, $ttl);
    }

    public static function purge_cache() {
        wp_cache_delete(self::HEALTH_KEY, self::CACHE_GROUP);
        delete_transient('dip_' . self::HEALTH_KEY);
        delete_transient('dip_provider_usage_30');
        do_action('dip_identity_cache_purged');
    }

    public static function settings_changed($old, $new) {
        self::purge_cache();
        if (class_exists('DIP_Audit')) DIP_Audit::record('performance_cache_purged', 'info', get_current_user_id(), ['reason'=>'settings_changed']);
    }

    public static function health($force = false) {
        $settings = self::settings();
        $cache_enabled = ($settings['performance_health_cache'] ?? 'yes') === 'yes';
        if (!$force && $cache_enabled) {
            $cached = self::cache_get(self::HEALTH_KEY);
            if (is_array($cached)) return $cached;
        }
        global $wpdb;
        $tables = [
            'audit' => class_exists('DIP_Audit') ? DIP_Audit::table() : '',
            'devices' => class_exists('DIP_Devices') ? DIP_Devices::table() : '',
            'mobile' => $wpdb->prefix . 'dip_mobile_tokens',
        ];
        $sizes = [];
        foreach ($tables as $name => $table) {
            if (!$table) continue;
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
            $count = $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sizes[$name] = ['table'=>$table, 'exists'=>$exists, 'rows'=>$count];
        }
        $autoload_bytes = 0;
        $option = $wpdb->get_row($wpdb->prepare("SELECT LENGTH(option_value) size_bytes FROM {$wpdb->options} WHERE option_name=%s", DIP_Plugin::OPTION), ARRAY_A);
        if ($option) $autoload_bytes = (int) $option['size_bytes'];
        $result = [
            'generated_at' => time(),
            'persistent_object_cache' => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache(),
            'page_cache' => self::page_cache_name(),
            'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'maintenance_scheduled' => (bool) wp_next_scheduled(self::CRON_HOOK),
            'audit_cleanup_scheduled' => class_exists('DIP_Audit') && (bool) wp_next_scheduled(DIP_Audit::CRON_HOOK),
            'device_cleanup_scheduled' => class_exists('DIP_Devices') && (bool) wp_next_scheduled(DIP_Devices::CRON_HOOK),
            'mobile_cleanup_scheduled' => (bool) wp_next_scheduled('dip_mobile_cleanup'),
            'settings_bytes' => $autoload_bytes,
            'tables' => $sizes,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
        ];
        if ($cache_enabled) self::cache_set(self::HEALTH_KEY, $result, 300);
        return $result;
    }

    private static function page_cache_name() {
        if (defined('LSCWP_V')) return 'LiteSpeed Cache';
        if (defined('WP_ROCKET_VERSION')) return 'WP Rocket';
        if (defined('WPCACHEHOME')) return 'WP Super Cache / compatible';
        if (!empty($_SERVER['HTTP_CF_RAY'])) return 'Cloudflare';
        return 'Non détecté';
    }

    public static function scheduled_maintenance() {
        $s = self::settings();
        if (($s['performance_maintenance_enabled'] ?? 'yes') !== 'yes') return;
        if (class_exists('DIP_Audit')) DIP_Audit::cleanup($s['security_retention_days'] ?? 30);
        if (class_exists('DIP_Devices')) DIP_Devices::cleanup($s['device_retention_days'] ?? 90);
        if (class_exists('DIP_Mobile_API') && method_exists('DIP_Mobile_API', 'cleanup')) DIP_Mobile_API::cleanup();
        self::purge_cache();
        update_option('dip_last_performance_maintenance', time(), false);
        if (class_exists('DIP_Audit')) DIP_Audit::record('performance_maintenance_completed', 'info', 0);
    }

    public static function analyze_tables() {
        global $wpdb;
        $allowed = array_filter([
            class_exists('DIP_Audit') ? DIP_Audit::table() : '',
            class_exists('DIP_Devices') ? DIP_Devices::table() : '',
            $wpdb->prefix . 'dip_mobile_tokens',
        ]);
        $results = [];
        foreach ($allowed as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) continue;
            $row = $wpdb->get_row("ANALYZE TABLE `{$table}`", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results[$table] = is_array($row) ? sanitize_text_field($row['Msg_text'] ?? 'Done') : 'Done';
        }
        self::purge_cache();
        if (class_exists('DIP_Audit')) DIP_Audit::record('performance_tables_analyzed', 'notice', get_current_user_id(), ['tables'=>count($results)]);
        return $results;
    }
}
