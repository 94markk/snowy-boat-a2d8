<?php
defined('ABSPATH') || exit;

final class DIP_Lockout {
    private static function identity() {
        $ip = class_exists('DIP_Native_Auth')
            ? DIP_Native_Auth::client_ip()
            : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return substr(hash_hmac('sha256', (string) $ip, wp_salt('auth')), 0, 40);
    }

    public static function key() {
        return 'dip_lock_' . self::identity();
    }

    public static function is_locked() {
        return (int) get_transient(self::key()) > time();
    }

    public static function remaining() {
        return max(0, (int) get_transient(self::key()) - time());
    }

    public static function register_failure(array $settings) {
        $window = min(60, max(5, absint($settings['lockout_window'] ?? 15)));
        $threshold = min(25, max(3, absint($settings['lockout_threshold'] ?? 7)));
        $duration = min(120, max(5, absint($settings['lockout_duration'] ?? 20)));
        $counter_key = 'dip_fail_' . self::identity();
        $count = (int) get_transient($counter_key) + 1;
        set_transient($counter_key, $count, $window * MINUTE_IN_SECONDS);
        if ($count >= $threshold) {
            set_transient(self::key(), time() + ($duration * MINUTE_IN_SECONDS), $duration * MINUTE_IN_SECONDS);
            delete_transient($counter_key);
            DIP_Audit::record('temporary_lockout', 'critical', 0, ['duration_minutes' => $duration]);
            return true;
        }
        return false;
    }

    public static function clear_failures() {
        delete_transient('dip_fail_' . self::identity());
        delete_transient(self::key());
    }
}
