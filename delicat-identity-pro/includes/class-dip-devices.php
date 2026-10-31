<?php
defined('ABSPATH') || exit;

final class DIP_Devices {
    const CRON_HOOK = 'dip_device_cleanup';
    const COOKIE = 'dip_device_id';
    private static $cookie_sent = false;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'dip_devices';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            device_hash char(64) NOT NULL,
            browser varchar(40) NOT NULL DEFAULT '',
            platform varchar(40) NOT NULL DEFAULT '',
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            ip_hash char(64) NOT NULL DEFAULT '',
            trusted tinyint(1) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY user_device (user_id, device_hash),
            KEY user_id (user_id),
            KEY last_seen (last_seen),
            KEY trusted (trusted)
        ) {$charset};";
        dbDelta($sql);
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
    }

    public static function uninstall_schedule() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);
    }

    private static function raw_ua() {
        return substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? 'unknown')), 0, 500);
    }

    private static function browser_device_id($create = false) {
        $value = isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? trim((string) wp_unslash($_COOKIE[self::COOKIE])) : '';
        $valid = (bool) preg_match('/^[A-Za-z0-9_-]{32,128}$/', $value);
        if ($valid && (!$create || self::$cookie_sent)) return $value;
        if (!$create || headers_sent()) return $valid ? $value : '';
        self::$cookie_sent = true;
        if (!$valid) {
            try {
                $value = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            } catch (Throwable $e) {
                $value = wp_generate_password(48, false, false);
            }
        }
        // (Re)issued at each sign-in so a browser used at least once a year
        // keeps the same identity instead of turning into a « new device ».
        $secure = is_ssl();
        setcookie(self::COOKIE, $value, [
            'expires' => time() + YEAR_IN_SECONDS,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $value;
        return $value;
    }

    /**
     * Device identity. With the first-party device cookie it is the cookie
     * alone (v2): browser and OS updates change the User-Agent every few
     * weeks and must not turn the same browser into a « new device » with a
     * new alert. The User-Agent is kept for the browser/platform columns only.
     * Without the cookie, the historical HMAC(User-Agent | marker) is used.
     */
    public static function current_hash($create_cookie = false) {
        $device_id = self::browser_device_id((bool) $create_cookie);
        if ($device_id !== '') return hash_hmac('sha256', 'v2|' . $device_id, wp_salt('secure_auth'));
        return self::legacy_hash('');
    }

    /** Hash used up to 6.11.0: HMAC(full User-Agent | device cookie). */
    private static function legacy_hash($device_id) {
        $material = self::raw_ua() . '|' . ($device_id !== '' ? $device_id : 'legacy-no-device-cookie');
        return hash_hmac('sha256', $material, wp_salt('secure_auth'));
    }

    /** {browser, platform} labels for a User-Agent string (also used by App Sync's pairing preview). */
    public static function describe_user_agent($ua) {
        $ua = substr(sanitize_text_field((string) $ua), 0, 500);
        return ['browser' => self::browser($ua), 'platform' => self::platform($ua)];
    }

    /**
     * The Delicat app signs in over REST with its installation header or a
     * Bearer token and never keeps the browser device cookie: its new-device
     * alert comes from the App API (first installation only), not from here.
     */
    private static function is_app_request() {
        if (!(defined('REST_REQUEST') && REST_REQUEST)) return false;
        if (!empty($_SERVER['HTTP_X_INSTALLATION_ID'])) return true;
        if (strpos(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '')), 'delicat-identity/v1/mobile/') !== false) return true;
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        return (bool) preg_match('/^Bearer\s+/i', $auth);
    }

    private static function ip_hash() {
        $ip = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::client_ip() : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return hash_hmac('sha256', $ip ?: 'unknown', wp_salt('auth'));
    }

    private static function browser($ua) {
        foreach (['Edg'=>'Edge','OPR'=>'Opera','CriOS'=>'Chrome iOS','Chrome'=>'Chrome','FxiOS'=>'Firefox iOS','Firefox'=>'Firefox','Version/'=>'Safari'] as $needle=>$name) {
            if (stripos($ua, $needle) !== false) return $name;
        }
        return 'Autre navigateur';
    }

    private static function platform($ua) {
        foreach (['iPhone'=>'iPhone','iPad'=>'iPad','Android'=>'Android','Windows'=>'Windows','Macintosh'=>'macOS','Linux'=>'Linux'] as $needle=>$name) {
            if (stripos($ua, $needle) !== false) return $name;
        }
        return 'Autre appareil';
    }

    public static function is_known($user_id) {
        global $wpdb;
        $hashes = array_values(array_unique([self::current_hash(), self::legacy_hash(self::browser_device_id(false))]));
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id=%d AND device_hash IN (%s,%s)', absint($user_id), $hashes[0], $hashes[1] ?? $hashes[0]));
        return $count > 0;
    }

    public static function register_login($user_id, array $settings = []) {
        global $wpdb;
        $user_id = absint($user_id);
        if (!$user_id || self::is_app_request()) return false;
        $hash = self::current_hash(true);
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE user_id=%d AND device_hash=%s', $user_id, $hash), ARRAY_A);
        $ua = self::raw_ua();
        $now = current_time('mysql', true);
        $data = ['browser'=>self::browser($ua), 'platform'=>self::platform($ua), 'last_seen'=>$now, 'ip_hash'=>self::ip_hash()];
        $device_id = self::browser_device_id(false);
        if (!$existing && $device_id !== '') {
            // A row recorded by 6.11.0 or earlier for this browser: move it to
            // the v2 identity (keeping trust and history), without an alert.
            $legacy = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE user_id=%d AND device_hash=%s', $user_id, self::legacy_hash($device_id)), ARRAY_A);
            if ($legacy) {
                $wpdb->update(self::table(), array_merge($data, ['device_hash'=>$hash]), ['id'=>(int) $legacy['id']], ['%s','%s','%s','%s','%s'], ['%d']);
                return false;
            }
        }
        if ($existing) {
            $wpdb->update(self::table(), $data, ['id'=>(int)$existing['id']], ['%s','%s','%s','%s'], ['%d']);
            return false;
        }
        $wpdb->insert(self::table(), array_merge($data, ['user_id'=>$user_id,'device_hash'=>$hash,'first_seen'=>$now,'trusted'=>0]), ['%s','%s','%s','%s','%d','%s','%s','%d']);
        DIP_Audit::record('new_device_login', 'notice', $user_id, ['browser'=>$data['browser'],'platform'=>$data['platform']]);
        if (($settings['new_device_email'] ?? 'no') === 'yes') self::email_new_device($user_id, $data);
        return true;
    }

    private static function email_new_device($user_id, array $data) {
        $user = get_userdata($user_id);
        if (!$user || !is_email($user->user_email)) return;
        $subject = sprintf('[%s] Nouvelle connexion détectée', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $message = "Une nouvelle connexion a été détectée sur votre compte Delicat Store.\nNavigateur : {$data['browser']}\nAppareil : {$data['platform']}\nDate : " . wp_date('Y-m-d H:i');
        if (class_exists('DIP_Email_Hub')) {
            DIP_Email_Hub::send_user_security($user->ID, $subject, $message, ['title'=>'Nouvel appareil détecté','badge'=>'Nouvelle connexion','accent'=>'#d97706','advice'=>'Si ce n’était pas vous, modifiez immédiatement votre mot de passe, déconnectez les autres appareils et contactez le support.']);
        } else {
            wp_mail($user->user_email, $subject, $message . "\n\nSi ce n’était pas vous, modifiez immédiatement votre mot de passe, déconnectez les autres appareils depuis votre compte et contactez le support.");
        }
    }

    public static function user_devices($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE user_id=%d ORDER BY last_seen DESC LIMIT 50', absint($user_id)), ARRAY_A);
    }

    /** Account deletion / privacy erasure. Returns the number of rows removed. */
    public static function delete_for_user($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        if (!$user_id) return 0;
        return (int) $wpdb->delete(self::table(), ['user_id'=>$user_id], ['%d']);
    }

    public static function set_trusted($user_id, $device_id, $trusted) {
        global $wpdb;
        return $wpdb->update(self::table(), ['trusted'=>$trusted ? 1 : 0], ['id'=>absint($device_id),'user_id'=>absint($user_id)], ['%d'], ['%d','%d']);
    }

    public static function forget($user_id, $device_id) {
        global $wpdb;
        return $wpdb->delete(self::table(), ['id'=>absint($device_id),'user_id'=>absint($user_id)], ['%d','%d']);
    }

    public static function cleanup($days = 90) {
        global $wpdb;
        $days = min(365, max(30, absint($days)));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        // Trusted devices unused for a year are forgotten too.
        $trusted_cutoff = gmdate('Y-m-d H:i:s', time() - YEAR_IN_SECONDS);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE (trusted=0 AND last_seen < %s) OR (trusted=1 AND last_seen < %s)', $cutoff, $trusted_cutoff));
    }

    public static function summary($days = 30) {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, absint($days)) * DAY_IN_SECONDS);
        $total = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . ' WHERE last_seen >= %s', $cutoff));
        $new = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . ' WHERE first_seen >= %s', $cutoff));
        $trusted = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . ' WHERE trusted=1');
        $browsers = $wpdb->get_results($wpdb->prepare('SELECT browser, COUNT(*) total FROM ' . self::table() . ' WHERE last_seen >= %s GROUP BY browser ORDER BY total DESC LIMIT 6', $cutoff), ARRAY_A);
        $platforms = $wpdb->get_results($wpdb->prepare('SELECT platform, COUNT(*) total FROM ' . self::table() . ' WHERE last_seen >= %s GROUP BY platform ORDER BY total DESC LIMIT 6', $cutoff), ARRAY_A);
        return compact('total','new','trusted','browsers','platforms');
    }
}
