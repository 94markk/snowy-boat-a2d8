<?php
defined('ABSPATH') || exit;

final class DIP_Lockout {
    const COUNTER_TABLE = 'dip_rate_counters';
    const COUNTER_OPTION = 'dip_rate_counters_ready';
    private static $counter_failed = false;

    private static function identity() {
        $ip = DIP_Native_Auth::throttle_ip();
        return substr(hash_hmac('sha256', (string) $ip, wp_salt('auth')), 0, 40);
    }

    public static function key() {
        return 'dip_lock_' . self::identity();
    }

    public static function is_locked() {
        return (int) get_transient(self::key()) > time();
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

    /**
     * Count one hit in a fixed window and return the new total. Unlike
     * re-saving a counter with a fresh TTL on every hit, retries never extend
     * the wait.
     */
    public static function window_hit($key, $window) {
        $now = time();
        $bucket = get_transient($key);
        if (!is_array($bucket) || (int) ($bucket['until'] ?? 0) <= $now) $bucket = ['count' => 0, 'until' => $now + (int) $window];
        $bucket['count'] = (int) $bucket['count'] + 1;
        set_transient($key, $bucket, max(1, (int) $bucket['until'] - $now));
        return $bucket['count'];
    }

    /**
     * Read a fixed-window bucket without counting a hit. Returns the current
     * count and the window end, or a zero count once the window is over.
     */
    public static function window_peek($key) {
        $bucket = get_transient($key);
        if (!is_array($bucket) || (int) ($bucket['until'] ?? 0) <= time()) return ['count' => 0, 'until' => 0];
        return ['count' => (int) ($bucket['count'] ?? 0), 'until' => (int) $bucket['until']];
    }

    /**
     * 6.10.2: table behind counter_hit(). Installed with the runtime schema;
     * the option only turns it on once the table is really there.
     */
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::COUNTER_TABLE;
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            bucket char(64) NOT NULL,
            hits int(10) unsigned NOT NULL DEFAULT 0,
            window_end bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (bucket),
            KEY window_end (window_end)
        ) {$charset};");
        $found = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        update_option(self::COUNTER_OPTION, $found === $table ? '1' : '0', true);
    }

    private static function counter_table() {
        global $wpdb;
        if (self::$counter_failed || !is_object($wpdb) || get_option(self::COUNTER_OPTION) !== '1') return '';
        return $wpdb->prefix . self::COUNTER_TABLE;
    }

    /**
     * Fixed-window counter like window_hit(), but counted by one atomic
     * statement (INSERT … ON DUPLICATE KEY UPDATE), so concurrent hits are
     * never lost the way a transient read-then-write loses them. Returns the
     * total after this hit; it can only be higher than this request's own
     * count when other requests hit at the same time. Without the table, or
     * after a database error, it falls back to window_hit() for the rest of
     * the request.
     *
     * Not the object cache: wp_cache_incr() is atomic in some drop-ins and a
     * get-then-set in others, and one that drops the expiry would keep a
     * shared carrier address blocked for good.
     */
    public static function counter_hit($key, $window) {
        global $wpdb;
        $window = max(1, (int) $window);
        $table = self::counter_table();
        if ($table !== '') {
            $now = time();
            $bucket = hash('sha256', (string) $key);
            $suppress = $wpdb->suppress_errors(true);
            // MySQL applies these assignments left to right: hits is computed
            // from the old window_end, which is only reassigned after it.
            $written = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (bucket, hits, window_end) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE hits = IF(window_end <= %d, 1, hits + 1), window_end = IF(window_end <= %d, %d, window_end)",
                $bucket,
                $now + $window,
                $now,
                $now,
                $now + $window
            ));
            $hits = false === $written ? null : $wpdb->get_var($wpdb->prepare("SELECT hits FROM {$table} WHERE bucket = %s", $bucket));
            $wpdb->suppress_errors($suppress);
            if (null !== $hits) return max(1, (int) $hits);
            self::$counter_failed = true;
        }
        return self::window_hit($key, $window);
    }

    /** Read a counter_hit() bucket without counting a hit (see window_peek()). */
    public static function counter_peek($key) {
        global $wpdb;
        $table = self::counter_table();
        if ($table !== '') {
            $suppress = $wpdb->suppress_errors(true);
            $wpdb->last_error = '';
            $row = $wpdb->get_row($wpdb->prepare("SELECT hits, window_end FROM {$table} WHERE bucket = %s", hash('sha256', (string) $key)));
            $error = (string) $wpdb->last_error;
            $wpdb->suppress_errors($suppress);
            if ($error === '') {
                if (!$row || (int) $row->window_end <= time()) return ['count' => 0, 'until' => 0];
                return ['count' => (int) $row->hits, 'until' => (int) $row->window_end];
            }
            self::$counter_failed = true;
        }
        return self::window_peek($key);
    }

    /** Forget a counter_hit() bucket (for example after a success). */
    public static function counter_clear($key) {
        global $wpdb;
        $table = self::counter_table();
        if ($table !== '') {
            $suppress = $wpdb->suppress_errors(true);
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE bucket = %s", hash('sha256', (string) $key)));
            $wpdb->suppress_errors($suppress);
        }
        delete_transient($key);
    }

    /**
     * Short exclusive lock on one options row. INSERT IGNORE inserts the row
     * for exactly one request: add_option() is not a mutex (its INSERT … ON
     * DUPLICATE KEY UPDATE also "succeeds" for a second writer whose value
     * differs). A lock older than $ttl seconds is stale and taken over with
     * a compare-and-delete, so a crashed request never blocks for good.
     * $wait_ms waits a little for a concurrent holder instead of failing.
     */
    public static function mutex_acquire($name, $ttl = 6, $wait_ms = 0) {
        global $wpdb;
        $name = substr(sanitize_key((string) $name), 0, 170);
        if ($name === '' || !is_object($wpdb)) return false;
        $ttl = min(30, max(2, absint($ttl)));
        $deadline = microtime(true) + min(2000, absint($wait_ms)) / 1000;
        $suppress = $wpdb->suppress_errors(true);
        $rows = 0;
        try {
            for ($i = 0; $i < 60; $i++) {
                $now = time();
                $rows = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now));
                if ($rows === 1) return true;
                if ($rows === false) break;
                $held = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
                if ($held !== null && (int) $held + $ttl < $now && $i < 3) {
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $held));
                    continue;
                }
                if (microtime(true) >= $deadline) break;
                usleep(50000);
            }
        } finally {
            $wpdb->suppress_errors($suppress);
            wp_cache_delete($name, 'options');
        }
        // The database refused the raw statement: keep the previous behaviour.
        return $rows === false ? (bool) add_option($name, time(), '', false) : false;
    }

    public static function mutex_release($name) {
        global $wpdb;
        $name = substr(sanitize_key((string) $name), 0, 170);
        if ($name === '' || !is_object($wpdb)) return;
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $name));
        $wpdb->suppress_errors($suppress);
        wp_cache_delete($name, 'options');
    }

    /** Daily: drop counters whose window ended more than an hour ago. */
    public static function purge_counters() {
        global $wpdb;
        $table = self::counter_table();
        if ($table === '') return;
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE window_end < %d", time() - HOUR_IN_SECONDS));
        $wpdb->suppress_errors($suppress);
    }

    public static function clear_failures() {
        delete_transient('dip_fail_' . self::identity());
        delete_transient(self::key());
    }
}
