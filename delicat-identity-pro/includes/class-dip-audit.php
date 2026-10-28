<?php
defined('ABSPATH') || exit;

final class DIP_Audit {
    const CRON_HOOK = 'dip_audit_cleanup';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'dip_security_events';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            event_type varchar(64) NOT NULL,
            severity varchar(16) NOT NULL DEFAULT 'info',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            fingerprint char(64) NOT NULL DEFAULT '',
            context longtext NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY created_at (created_at),
            KEY user_id (user_id),
            KEY severity (severity)
        ) {$charset};";
        dbDelta($sql);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function uninstall_schedule() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);
    }

    public static function fingerprint() {
        $ip = class_exists('DIP_Native_Auth')
            ? DIP_Native_Auth::client_ip()
            : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $ua = substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? 'unknown')), 0, 512);
        return hash_hmac('sha256', $ip . '|' . $ua, wp_salt('auth'));
    }

    public static function record($event_type, $severity = 'info', $user_id = 0, array $context = []) {
        global $wpdb;
        $allowed = ['info','notice','warning','critical'];
        $severity = in_array($severity, $allowed, true) ? $severity : 'info';
        $safe = [];
        foreach ($context as $key => $value) {
            $key = sanitize_key($key);
            if (!$key || in_array($key, ['token','access_token','id_token','client_secret','password','email','ip'], true)) continue;
            if (is_scalar($value) || null === $value) $safe[$key] = sanitize_text_field((string) $value);
        }
        $wpdb->insert(self::table(), [
            'created_at' => current_time('mysql', true),
            'event_type' => substr(sanitize_key($event_type), 0, 64),
            'severity' => $severity,
            'user_id' => absint($user_id),
            'fingerprint' => self::fingerprint(),
            'context' => $safe ? wp_json_encode($safe) : null,
        ], ['%s','%s','%s','%d','%s','%s']);
    }

    /** Backward-compatible alias used by older Identity modules. */
    public static function log($event_type, $user_id = 0, array $context = []) {
        self::record($event_type, 'info', absint($user_id), $context);
    }

    public static function cleanup($days = 30) {
        global $wpdb;
        $days = min(180, max(7, absint($days)));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff));
    }

    public static function recent($limit = 50) {
        global $wpdb;
        $limit = min(200, max(1, absint($limit)));
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A);
    }

    public static function summary($days = 30) {
        global $wpdb;
        $days = min(180, max(1, absint($days)));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT event_type, severity, COUNT(*) total FROM ' . self::table() . ' WHERE created_at >= %s GROUP BY event_type, severity', $cutoff), ARRAY_A);
        $out = ['total' => 0, 'success' => 0, 'blocked' => 0, 'warning' => 0, 'critical' => 0];
        foreach ($rows as $row) {
            $count = (int) $row['total'];
            $out['total'] += $count;
            if ($row['event_type'] === 'login_success') $out['success'] += $count;
            if (strpos($row['event_type'], 'login_') === 0 && $row['event_type'] !== 'login_success') $out['blocked'] += $count;
            if ($row['severity'] === 'warning') $out['warning'] += $count;
            if ($row['severity'] === 'critical') $out['critical'] += $count;
        }
        return $out;
    }
}
