<?php
defined('ABSPATH') || exit;

final class DIP_Mobile_API {
    const TABLE_SUFFIX = 'dip_mobile_tokens';
    const DEFAULT_ACCESS_TTL = 900;
    const DEFAULT_REFRESH_TTL = 2592000;
    const CHALLENGE_TTL = 180;
    /** 6.11.0: quick sign-in keys live in their own table and survive sign-out. */
    const BIOMETRIC_TABLE_SUFFIX = 'dip_mobile_biometric_keys';
    const BIOMETRIC_READY_OPTION = 'dip_mobile_biometric_keys_ready';
    /** Sliding lifetime of a quick sign-in key: every sign-in pushes it 90 days on. */
    const BIOMETRIC_KEY_TTL = 7776000;
    const BIOMETRIC_CEREMONY_TTL = 300;
    const BIOMETRIC_MESSAGE_PREFIX = 'DLC-BIO1';
    /** « Preuve récente » of a mobile session (seconds). */
    const PROOF_TTL = 600;
    /**
     * Flood guard of the session routes (calls per session and 10 minutes),
     * never a usage limit. Owner rule (2026-10-24): « I don't want a client
     * to have a limit using the application ». A person who taps again and
     * again stays far below it; only a script that fills the disk (one
     * ceremony or audit row per call) or the inbox (security e-mails)
     * reaches it. Guessing is stopped by the failure buckets instead.
     */
    const SESSION_FLOOD_LIMIT = 200;
    const ISSUE_METHODS = ['google', 'passkey', 'biometric', 'password_upgrade'];
    private static $settings_cb;
    private static $revoking = [];

    public static function init($settings_cb) {
        self::$settings_cb = $settings_cb;
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_post_dispatch', [__CLASS__, 'secure_rest_response'], 10, 3);
        add_filter('determine_current_user', [__CLASS__, 'authenticate_bearer'], 25);
        add_action('dip_mobile_cleanup', [__CLASS__, 'cleanup']);
        // 6.11.0: the App API's account events reach the quick sign-in keys too.
        add_action('delicat_app_revoke_all_external_sessions', [__CLASS__, 'on_app_revoke_all'], 10, 1);
        add_action('delicat_app_erase_customer_data', [__CLASS__, 'on_app_erase_customer_data'], 10, 1);
        if (!wp_next_scheduled('dip_mobile_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dip_mobile_cleanup');
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            refresh_hash char(64) DEFAULT NULL,
            device_hash char(64) NOT NULL,
            installation_hash char(64) NOT NULL DEFAULT '',
            device_name varchar(100) NOT NULL DEFAULT '',
            platform varchar(20) NOT NULL DEFAULT 'unknown',
            app_version varchar(32) NOT NULL DEFAULT '',
            locale varchar(16) NOT NULL DEFAULT '',
            push_provider varchar(20) NOT NULL DEFAULT '',
            push_token_enc longtext DEFAULT NULL,
            biometric_public_key longtext DEFAULT NULL,
            biometric_key_hash char(64) NOT NULL DEFAULT '',
            biometric_enabled tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            last_used_at datetime NOT NULL,
            access_expires_at datetime NOT NULL,
            refresh_expires_at datetime NOT NULL,
            revoked_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            UNIQUE KEY refresh_hash (refresh_hash),
            KEY user_id (user_id),
            KEY device_hash (device_hash),
            KEY installation_hash (installation_hash),
            KEY biometric_key_hash (biometric_key_hash)
        ) {$charset};");
        // 6.11.0: one row per quick sign-in key (fingerprint or face). The
        // biometric_* columns above stay for old rows but are no longer read.
        $keys = $wpdb->prefix . self::BIOMETRIC_TABLE_SUFFIX;
        dbDelta("CREATE TABLE {$keys} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            installation_hash char(64) NOT NULL,
            device_hash char(64) NOT NULL,
            public_key text NOT NULL,
            key_hash char(64) NOT NULL,
            alg varchar(16) NOT NULL DEFAULT 'ES256',
            attestation_level varchar(16) NOT NULL DEFAULT 'none',
            label varchar(100) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            last_used_at datetime DEFAULT NULL,
            expires_at datetime NOT NULL,
            revoked_at datetime DEFAULT NULL,
            revoke_reason varchar(32) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY installation_hash (installation_hash),
            KEY key_hash (key_hash)
        ) {$charset};");
        $found = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($keys)));
        update_option(self::BIOMETRIC_READY_OPTION, $found === $keys ? '1' : '0', true);
    }

    public static function routes() {
        register_rest_route('delicat-identity/v1', '/mobile/google', ['methods'=>'POST','callback'=>[__CLASS__,'google_login'],'permission_callback'=>'__return_true']);
        register_rest_route('delicat-identity/v1', '/mobile/refresh', ['methods'=>'POST','callback'=>[__CLASS__,'refresh'],'permission_callback'=>'__return_true']);
        register_rest_route('delicat-identity/v1', '/mobile/logout', ['methods'=>'POST','callback'=>[__CLASS__,'logout'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/profile', ['methods'=>'GET','callback'=>[__CLASS__,'profile'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/capabilities', ['methods'=>'GET','callback'=>[__CLASS__,'capabilities'],'permission_callback'=>'__return_true']);
        register_rest_route('delicat-identity/v1', '/mobile/sessions', ['methods'=>'GET','callback'=>[__CLASS__,'sessions'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/sessions/(?P<id>\d+)', ['methods'=>'DELETE','callback'=>[__CLASS__,'revoke_session'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/device', ['methods'=>'POST','callback'=>[__CLASS__,'update_device'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/push', ['methods'=>'POST','callback'=>[__CLASS__,'register_push'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/push', ['methods'=>'DELETE','callback'=>[__CLASS__,'remove_push'],'permission_callback'=>[__CLASS__,'logged_in']]);
        // 6.11.0: quick sign-in (fingerprint or face) — same paths, new contract.
        register_rest_route('delicat-identity/v1', '/mobile/biometric/enroll', ['methods'=>'POST','callback'=>[__CLASS__,'biometric_enroll'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/biometric/register', ['methods'=>'POST','callback'=>[__CLASS__,'biometric_register'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/biometric/challenge', ['methods'=>'POST','callback'=>[__CLASS__,'biometric_challenge'],'permission_callback'=>'__return_true']);
        register_rest_route('delicat-identity/v1', '/mobile/biometric/verify', ['methods'=>'POST','callback'=>[__CLASS__,'biometric_verify'],'permission_callback'=>'__return_true']);
        register_rest_route('delicat-identity/v1', '/mobile/biometric', ['methods'=>'GET','callback'=>[__CLASS__,'biometric_status'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/biometric', ['methods'=>'DELETE','callback'=>[__CLASS__,'biometric_delete_this'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/biometric/(?P<id>\d+)', ['methods'=>'DELETE','callback'=>[__CLASS__,'biometric_delete_key'],'permission_callback'=>[__CLASS__,'logged_in']]);
        // 6.11.0: « preuve récente » of a mobile session. The passkey variant is DIP_Mobile_Passkeys'.
        register_rest_route('delicat-identity/v1', '/mobile/reauth/password', ['methods'=>'POST','callback'=>[__CLASS__,'reauth_password'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/reauth/google', ['methods'=>'POST','callback'=>[__CLASS__,'reauth_google'],'permission_callback'=>[__CLASS__,'logged_in']]);
    }

    public static function logged_in($request = null) {
        $session = self::current_session_for_request($request instanceof WP_REST_Request ? $request : null);
        if (!$session || !self::mobile_user_allowed((int) $session->user_id)) return false;
        wp_set_current_user((int) $session->user_id);
        return true;
    }
    /** @internal 6.11.0: also read by DIP_Mobile_Passkeys. */
    public static function settings() { return is_callable(self::$settings_cb) ? (array) call_user_func(self::$settings_cb) : []; }
    /** @internal 6.11.0: also read by DIP_Mobile_Passkeys. */
    public static function enabled() { return (self::settings()['mobile_api_enabled'] ?? 'no') === 'yes'; }
    private static function table() { global $wpdb; return $wpdb->prefix . self::TABLE_SUFFIX; }
    private static function hash_token($token) { return hash_hmac('sha256', (string)$token, wp_salt('auth')); }
    private static function random_token($bytes=48) { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
    private static function access_ttl() { return min(3600,max(300,absint(self::settings()['mobile_access_ttl'] ?? self::DEFAULT_ACCESS_TTL))); }
    private static function refresh_ttl() { return min(7776000,max(86400,absint(self::settings()['mobile_refresh_ttl'] ?? self::DEFAULT_REFRESH_TTL))); }
    private static function device_hash($value) { return hash_hmac('sha256', substr((string)$value,0,255), wp_salt('secure_auth')); }
    private static function server_authorization_header() {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return trim((string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return trim((string) wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        return '';
    }

    public static function secure_rest_response($response, $server, $request) {
        if ((strpos((string) $request->get_route(), '/delicat-identity/v1/mobile/') === 0 || strpos((string) $request->get_route(), '/delicat-identity/v1/pairing/') === 0) && $response instanceof WP_REST_Response) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->header('Pragma', 'no-cache');
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('Referrer-Policy', 'no-referrer');
            // 6.11.0: the site runs LiteSpeed Cache, which kept the public
            // GET /mobile/capabilities for 7 days despite no-store. The app
            // sends no cookie, so to a cookie-keyed page cache every phone is
            // the same guest: a private GET (/mobile/passkeys, /mobile/biometric)
            // must opt out explicitly, as the App API's private answers do.
            $response->header('X-LiteSpeed-Cache-Control', 'no-cache, no-store');
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            do_action('litespeed_control_set_nocache', 'Delicat Identity mobile response');
        }
        return $response;
    }

    /**
     * Fixed-window throttle for the public mobile routes (6.10.2).
     *
     * Most customers in Haiti reach the site through a few carrier-grade NAT
     * addresses. A per-address ceiling that counted every request therefore
     * stopped session renewal for every customer behind the address at busy
     * times. The buckets are now:
     *  - address|installation: every attempt counts, up to $limit per window;
     *  - address, failures only: only attempts that did not prove possession
     *    of a secret count (see proof_failed()), up to $limit * 10, checked
     *    before anything is processed. Installation IDs are chosen by the
     *    client, so this ceiling is what still stops a guesser who rotates
     *    them, while successful sign-ins and renewals never touch it;
     *  - address, every attempt: only with $address_limit (Google sign-in
     *    backstop) and, at $limit, for requests without a valid installation
     *    ID, which keep the strict pre-installation behaviour;
     *  - session (session_throttle()): once a refresh token or biometric
     *    signature is valid, its session gets its own ceiling, whatever
     *    headers or address the holder uses.
     *
     * $failures_only (6.11.0, owner rule of 2026-10-24: « I don't want a
     * client to have a limit using the application »): for the sign-in routes
     * a customer uses again and again (quick sign-in challenge and verify,
     * app passkey sign-in, Google sign-in, session renewal by /mobile/refresh),
     * address|installation counts only the attempts
     * that failed (proof_failed()), up to $limit, read before processing;
     * every attempt counts only against the SESSION_FLOOD_LIMIT flood guard
     * of the installation, which a person never reaches.
     *
     * The installation bucket is keyed on the canonical installation ID, the
     * value the session binding compares, so header variants that bind to the
     * same session share one bucket. $installation overrides the header for a
     * route that looks up another value (biometric challenge: body param).
     * Counters are atomic (DIP_Lockout::counter_hit()). The failure ceiling
     * is read before processing and counted after it, so requests already in
     * flight when it is reached still finish: the overshoot is at most the
     * number of requests a caller has running at that moment, once a window.
     *
     * Returns the gate to pass to proof_failed(), or the 429 WP_Error.
     *
     * @internal 6.11.0: public so that DIP_Mobile_Passkeys uses the same buckets.
     */
    public static function throttle($bucket, $limit, $window, WP_REST_Request $request, $address_limit = 0, $installation = null, $failures_only = false) {
        $ip = DIP_Native_Auth::throttle_ip();
        $prefix = 'dip_mob_' . sanitize_key($bucket) . '_';
        $address = substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 32);
        $installation = null === $installation ? self::installation_id($request) : self::canonical_installation($installation);
        $gate = [
            'bucket' => sanitize_key($bucket),
            'window' => (int) $window,
            'failures' => $prefix . 'fail_' . $address,
            'failure_limit' => (int) $limit * 10,
            'suspects' => $prefix . 'suspects_' . $address,
            // Audit references only: the address as bucketed, and the
            // installation as stored in the session table (installation_hash
            // starts with it), so a lockout can be traced to a device.
            'address' => substr($address, 0, 12),
            'installation' => $installation === '' ? 'none' : substr(self::device_hash($installation), 0, 12),
        ];
        if (DIP_Lockout::counter_peek($gate['failures'])['count'] >= $gate['failure_limit']) {
            return self::rate_limited($gate, 'address_failures', $gate['failures']);
        }
        if ($installation === '') {
            $key = $prefix . 'noinst_' . $address;
            return DIP_Lockout::counter_hit($key, $window) <= $limit ? $gate : self::rate_limited($gate, 'address', $key);
        }
        $pair = substr(hash_hmac('sha256', $ip . '|' . $installation, wp_salt('nonce')), 0, 32);
        $key = $prefix . 'inst_' . $pair;
        if ($failures_only) {
            $gate['installation_failures'] = $prefix . 'instfail_' . $pair;
            if (DIP_Lockout::counter_peek($gate['installation_failures'])['count'] >= (int) $limit) {
                return self::rate_limited($gate, 'installation_failures', $gate['installation_failures']);
            }
            if (DIP_Lockout::counter_hit($key, $window) > self::SESSION_FLOOD_LIMIT) return self::rate_limited($gate, 'installation', $key);
        } elseif (DIP_Lockout::counter_hit($key, $window) > $limit) {
            return self::rate_limited($gate, 'installation', $key);
        }
        if ($address_limit > 0) {
            $key = $prefix . 'all_' . $address;
            if (DIP_Lockout::counter_hit($key, $window) > (int) $address_limit) return self::rate_limited($gate, 'address', $key);
        }
        return $gate;
    }

    /**
     * Count an attempt that did not prove possession of a valid secret
     * (refresh token, Google ID token, biometric key) against the address
     * (and, for a $failures_only gate, against address|installation), and
     * remember which installations failed (first 10 per window) so the
     * lockout audit can name them.
     *
     * @internal 6.11.0: public for DIP_Mobile_Passkeys.
     */
    public static function proof_failed($gate, $reason = '') {
        if (!is_array($gate) || empty($gate['failures'])) return;
        DIP_Lockout::counter_hit($gate['failures'], $gate['window']);
        if (!empty($gate['installation_failures'])) DIP_Lockout::counter_hit($gate['installation_failures'], $gate['window']);
        $now = time();
        $suspects = get_transient($gate['suspects']);
        if (!is_array($suspects) || (int) ($suspects['until'] ?? 0) <= $now) $suspects = ['until' => $now + (int) $gate['window'], 'list' => [], 'other' => 0];
        $id = (string) $gate['installation'];
        if (isset($suspects['list'][$id])) {
            $suspects['list'][$id][0]++;
        } elseif (count($suspects['list']) < 10) {
            $suspects['list'][$id] = [1, sanitize_key($reason)];
        } else {
            $suspects['other']++;
        }
        set_transient($gate['suspects'], $suspects, max(1, (int) $suspects['until'] - $now));
    }

    /**
     * Per-session ceiling for a request whose refresh token or biometric
     * signature is valid: header variants or another address do not open a
     * new bucket. Returns the 429 or null. 6.11.0: $kind 'key' buckets a
     * quick sign-in key the same way.
     */
    private static function session_throttle(array $gate, $session_id, $limit, $kind = 'sess') {
        $key = 'dip_mob_' . $gate['bucket'] . '_' . sanitize_key($kind) . '_' . absint($session_id);
        return DIP_Lockout::counter_hit($key, $gate['window']) > (int) $limit ? self::rate_limited($gate, 'session', $key) : null;
    }

    /**
     * Same 429 as before. The audit event is written once per bucket and
     * window, not on every refused request, so a burst cannot flood the log.
     * A failure lockout also names the installations that failed most.
     *
     * @internal 6.11.0: public for DIP_Mobile_Passkeys.
     */
    public static function rate_limited(array $gate, $scope, $key) {
        if (class_exists('DIP_Audit')) {
            $flag = $key . '_logged';
            if (!get_transient($flag)) {
                $until = DIP_Lockout::counter_peek($key)['until'];
                set_transient($flag, 1, $until > time() ? $until - time() : $gate['window']);
                $context = ['bucket' => $gate['bucket'], 'scope' => $scope, 'address' => $gate['address'], 'installation' => $gate['installation']];
                if ($scope === 'address_failures') {
                    $suspects = get_transient($gate['suspects']);
                    $list = is_array($suspects) ? (array) ($suspects['list'] ?? []) : [];
                    uasort($list, static function ($a, $b) { return (int) $b[0] - (int) $a[0]; });
                    $top = [];
                    foreach (array_slice($list, 0, 5, true) as $id => $entry) $top[] = $id . '=' . (int) $entry[0] . '/' . $entry[1];
                    $context['failed_installations'] = implode(';', $top);
                    $context['failed_other'] = is_array($suspects) ? (int) ($suspects['other'] ?? 0) : 0;
                }
                DIP_Audit::record('mobile_rate_limited', in_array($scope, ['installation', 'session', 'account'], true) ? 'warning' : 'critical', 0, $context);
            }
        }
        return new WP_Error('rate_limited', 'Too many requests.', ['status' => 429]);
    }

    /**
     * 6.11.0: the flood guard (SESSION_FLOOD_LIMIT) of the session routes
     * that store something on every call (enrol, passkey options…). Callers
     * run it after the recent-proof check, so a 403 reauth_required is never
     * counted. Returns the 429 or null.
     *
     * @internal public for DIP_Mobile_Passkeys.
     */
    public static function session_route_limit($bucket, $session_id, $limit = self::SESSION_FLOOD_LIMIT, $window = 600) {
        $bucket = sanitize_key($bucket);
        $key = 'dip_mob_' . $bucket . '_sess_' . absint($session_id);
        if (DIP_Lockout::counter_hit($key, $window) <= (int) $limit) return null;
        $gate = ['bucket' => $bucket, 'window' => (int) $window, 'address' => '', 'installation' => '', 'suspects' => ''];
        return self::rate_limited($gate, 'session', $key);
    }

    /**
     * The installation ID as the session binding sees it: request_device()
     * hashes sanitize_text_field() of the same value, so two raw values are
     * one throttle bucket exactly when they bind to the same session.
     */
    private static function canonical_installation($value) {
        $installation = trim(sanitize_text_field((string) $value));
        return strlen($installation) >= 16 && strlen($installation) <= 255 ? $installation : '';
    }

    private static function installation_id(WP_REST_Request $r) {
        return self::canonical_installation($r->get_header('X-Installation-ID') ?: $r->get_param('installation_id'));
    }

    /** @internal 6.11.0: public for DIP_Mobile_Passkeys. */
    public static function valid_device_request(WP_REST_Request $r) {
        $device = trim(sanitize_text_field((string) ($r->get_header('X-Device-ID') ?: $r->get_param('device_id'))));
        return strlen($device) >= 8 && strlen($device) <= 255 && self::installation_id($r) !== '';
    }


    /**
     * Resolve a Delicat Identity mobile access token for trusted sibling plugins.
     * Accepts Authorization: Bearer and X-Delicat-Identity-Token.
     * Returns only the matching user ID and never exposes stored token hashes.
     */
    public static function resolve_request_user($request = null) {
        if (!self::enabled()) return 0;
        // The supported bridge is request-bound: sibling Delicat plugins must
        // pass the WP_REST_Request so token + device + installation are checked.
        if ($request instanceof WP_REST_Request) {
            $session = self::current_session_for_request($request, true);
            return $session ? (int) $session->user_id : 0;
        }
        // Legacy unbound resolution is disabled by default because a copied
        // Bearer token must not be enough to impersonate a customer from an
        // arbitrary WordPress hook. It can be temporarily re-enabled only by a
        // deliberate compatibility filter while an older sibling plugin migrates.
        if (!apply_filters('dip_mobile_allow_legacy_unbound_token_resolution', false)) return 0;
        $token = '';
        $auth = self::server_authorization_header();
        if (preg_match('/^Bearer\s+([A-Za-z0-9\-_]{40,512})$/i', $auth, $m)) $token = $m[1];
        if ($token === '' && !empty($_SERVER['HTTP_X_DELICAT_IDENTITY_TOKEN'])) $token = trim((string) wp_unslash($_SERVER['HTTP_X_DELICAT_IDENTITY_TOKEN']));
        if (!preg_match('/^[A-Za-z0-9\-_]{40,512}$/', $token)) return 0;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,user_id FROM '.self::table().' WHERE token_hash=%s AND revoked_at IS NULL AND access_expires_at > UTC_TIMESTAMP() LIMIT 1',
            self::hash_token($token)
        ));
        if (!$row || !self::mobile_user_allowed((int) $row->user_id)) return 0;
        $wpdb->update(self::table(), ['last_used_at'=>current_time('mysql',true)], ['id'=>(int)$row->id], ['%s'], ['%d']);
        return (int) $row->user_id;
    }

    private static function automatic_bearer_route_allowed() {
        if (!defined('REST_REQUEST') || !REST_REQUEST) return false;
        $route = '';
        if (isset($_GET['rest_route'])) {
            $route = (string) wp_unslash($_GET['rest_route']);
        } elseif (!empty($_SERVER['REQUEST_URI'])) {
            $path = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
            $marker = '/wp-json/';
            $pos = strpos($path, $marker);
            if ($pos !== false) $route = '/' . ltrim(substr($path, $pos + strlen($marker)), '/');
        }
        $route = '/' . ltrim(sanitize_text_field($route), '/');
        $prefixes = apply_filters('dip_mobile_bearer_auto_auth_prefixes', ['/delicat-identity/v1/']);
        foreach ((array) $prefixes as $prefix) {
            $prefix = '/' . ltrim((string) $prefix, '/');
            if ($prefix !== '/' && strpos($route, $prefix) === 0) return true;
        }
        return false;
    }

    public static function authenticate_bearer($user_id) {
        if ($user_id || !self::enabled()) return $user_id;
        // A Delicat mobile Bearer token must never become a WordPress REST
        // identity by itself. Even on the Identity namespace, automatic auth
        // requires the same device + installation binding as protected routes.
        if (!self::automatic_bearer_route_allowed() || !class_exists('WP_REST_Request')) return $user_id;
        $auth = self::server_authorization_header();
        $identity_token = isset($_SERVER['HTTP_X_DELICAT_IDENTITY_TOKEN']) ? trim((string) wp_unslash($_SERVER['HTTP_X_DELICAT_IDENTITY_TOKEN'])) : '';
        $device = isset($_SERVER['HTTP_X_DEVICE_ID']) ? trim((string) wp_unslash($_SERVER['HTTP_X_DEVICE_ID'])) : '';
        $installation = isset($_SERVER['HTTP_X_INSTALLATION_ID']) ? trim((string) wp_unslash($_SERVER['HTTP_X_INSTALLATION_ID'])) : '';
        if ($auth === '' && $identity_token === '') return $user_id;
        $request = new WP_REST_Request();
        if ($auth !== '') $request->set_header('Authorization', $auth);
        if ($identity_token !== '') $request->set_header('X-Delicat-Identity-Token', $identity_token);
        if ($device !== '') $request->set_header('X-Device-ID', $device);
        if ($installation !== '') $request->set_header('X-Installation-ID', $installation);
        $session = self::current_session_for_request($request, true);
        return $session ? (int) $session->user_id : $user_id;
    }

    /** Google sign-in on the app is fully configured (also the condition for « Utiliser Google » in a reauth). */
    private static function google_mobile_enabled() {
        $s = self::settings();
        return self::enabled()
            && ($s['enabled'] ?? 'no') === 'yes'
            && self::oauth_client_id($s['client_id'] ?? '') !== ''
            && (self::oauth_client_id($s['android_client_id'] ?? '') !== '' || self::oauth_client_id($s['ios_client_id'] ?? '') !== '');
    }

    public static function capabilities() {
        $s=self::settings();
        $server_client_id = self::oauth_client_id($s['client_id'] ?? '');
        $android_client_id = self::oauth_client_id($s['android_client_id'] ?? '');
        $ios_client_id = self::oauth_client_id($s['ios_client_id'] ?? '');
        $google_enabled = self::google_mobile_enabled();
        return new WP_REST_Response([
            'enabled'=>self::enabled(),'https'=>is_ssl(),'version'=>DIP_VERSION,
            'google_android_configured'=>$android_client_id !== '',
            'google_ios_configured'=>$ios_client_id !== '',
            // OAuth client IDs are public identifiers. The client secret is
            // deliberately never returned. Flutter uses the Web/server client
            // ID as the requested ID-token audience, then exchanges that
            // short-lived proof exclusively with Identity Pro.
            'google'=>[
                'enabled'=>$google_enabled,
                'server_client_id'=>$server_client_id,
                'android_client_id'=>$android_client_id,
                'ios_client_id'=>$ios_client_id,
            ],
            'push_enabled'=>($s['mobile_push_enabled']??'no')==='yes',
            'biometric_enabled'=>self::biometric_available(),
            // 6.11.0: the passkey and quick sign-in contract of DESIGN-5.6 §3.3
            // exists. The app shows none of it without this flag, which a 6.10
            // server (old biometric contract) never sends.
            'mobile_quick_sign_in'=>1,
            'passkeys_enabled'=>class_exists('DIP_Mobile_Passkeys') && DIP_Mobile_Passkeys::enabled(),
            'rp_id'=>self::rp_id(),
            'two_factor_supported'=>class_exists('DIP_Two_Factor'),
            'access_ttl'=>self::access_ttl(),'refresh_ttl'=>self::refresh_ttl(),
        ],200);
    }

    /** @internal 6.11.0: public for DIP_Mobile_Passkeys. */
    public static function mobile_user_allowed($user_id) {
        $user_id = absint($user_id);
        if (!$user_id || !get_userdata($user_id)) return false;
        if (!class_exists('DIP_Account_Sync')) return false;
        if (DIP_Account_Sync::privileged_mobile_blocked($user_id)) return false;
        if (is_wp_error(DIP_Account_Sync::login_guard($user_id))) return false;
        return true;
    }

    public static function google_login(WP_REST_Request $request) {
        // 6.11.0 (owner rule of 2026-10-24, successful use never counts): 10
        // FAILED proofs per installation and 100 per address; every attempt
        // counts only against the SESSION_FLOOD_LIMIT flood guard of the
        // installation. The address keeps one all-attempts backstop of 500 per
        // window (50 times the installation figure, far above the SHARED_ADDRESS
        // 20 times rule): a verified Google token can create an account, so this
        // is the disk-filling guard, not a usage limit.
        $gate = self::throttle('google_login', 10, 10 * MINUTE_IN_SECONDS, $request, 500, null, true);
        if (is_wp_error($gate)) return $gate;
        if (!self::enabled()) return new WP_Error('mobile_api_disabled','Mobile authentication is disabled.',['status'=>403]);
        if (!is_ssl()) return new WP_Error('https_required','HTTPS is required.',['status'=>403]);
        if (!self::valid_device_request($request)) { self::proof_failed($gate, 'device_required'); return new WP_Error('device_required','A valid device and installation identifier are required.',['status'=>400]); }
        $id_token=trim((string)$request->get_param('id_token'));
        if (strlen($id_token)<100 || strlen($id_token)>65536) { self::proof_failed($gate, 'invalid_token'); return new WP_Error('invalid_token','Invalid Google token.',['status'=>400]); }
        $s=self::settings();
        if (($s['enabled']??'no')!=='yes') return self::denied('mobile_google_disabled');
        // Native Google Sign-In is configured with the Web/server client ID.
        // Google therefore signs the backend ID token for this audience. Do
        // not accept arbitrary Firebase/Android audiences here: Identity Pro
        // is the sole service allowed to turn a Google proof into an app
        // session.
        $server_client_id=self::oauth_client_id($s['client_id']??'');
        if ($server_client_id==='') return self::denied('mobile_google_server_audience_not_configured');
        $p = class_exists('DIP_Google') ? DIP_Google::verify_id_token($id_token, [$server_client_id], '') : new WP_Error('google_verifier_missing','Google verifier unavailable.');
        if (is_wp_error($p)) {
            // Verifier outages (keys unreachable or unusable, OpenSSL missing)
            // are not the caller's failure and must not lock out the shared
            // address. google_key_missing still counts: a forged kid causes it.
            if (!in_array($p->get_error_code(), self::google_outage_codes(), true)) self::proof_failed($gate, $p->get_error_code());
            if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_google_token_rejected','critical',0,['reason'=>sanitize_key($p->get_error_code())]);
            return self::denied('google_token_rejected');
        }
        $platform=sanitize_key((string)($request->get_header('X-Platform')?:$request->get_param('platform')?:'unknown'));
        $platform_client_id = $platform==='android'
            ? self::oauth_client_id($s['android_client_id']??'')
            : ($platform==='ios' ? self::oauth_client_id($s['ios_client_id']??'') : '');
        if ($platform_client_id==='') return self::denied('mobile_google_platform_not_configured');
        // When Google supplies azp (authorized party), it must identify this
        // exact native client. This prevents another native OAuth client in the
        // same project from reusing a token against the Delicat mobile route.
        $azp=trim((string)($p['azp']??''));
        if ($azp!=='' && !hash_equals($platform_client_id,$azp)) { self::proof_failed($gate, 'azp_mismatch'); return self::denied('mobile_google_authorized_party_mismatch'); }
        $profile=[
            'provider'=>'google',
            'sub'=>(string)($p['sub']??''),
            'email'=>sanitize_email($p['email']??''),
            'email_verified'=>true,
            'email_authoritative'=>DIP_Google::email_is_authoritative($p),
            'hd'=>sanitize_text_field($p['hd']??''),
            'name'=>sanitize_text_field($p['name']??''),
            'given_name'=>sanitize_text_field($p['given_name']??''),
            'family_name'=>sanitize_text_field($p['family_name']??''),
            'picture'=>esc_url_raw($p['picture']??'')
        ];
        $policy = DIP_Policy::enforce_profile($profile, $s);
        if (is_wp_error($policy)) return self::denied('mobile_' . $policy->get_error_code());
        $resolved=(new DIP_Identity($s))->resolve($profile,0);
        if (is_wp_error($resolved)) return new WP_Error($resolved->get_error_code(),$resolved->get_error_message(),['status'=>403]);
        if (!self::mobile_user_allowed($resolved)) return self::denied('mobile_account_not_allowed');
        $risk = DIP_Policy::assess($resolved, $profile, $s);
        if (is_wp_error($risk)) return self::denied('mobile_' . $risk->get_error_code());
        $factor_error = self::second_factor((int) $resolved, $request, 'mobile');
        if ($factor_error) return $factor_error;
        if (class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled((int)$resolved)) $profile['two_factor'] = 1;
        do_action('dip_login_success',(int)$resolved,$profile);
        if (class_exists('DIP_Audit')) DIP_Audit::record('login_success','info',(int)$resolved,['method'=>'mobile_google']);
        return self::issue_for_user((int)$resolved,$request,'google');
    }

    /** Google verifier outages: never the caller's failure (6.10.2). */
    private static function google_outage_codes() {
        return ['google_verifier_missing','google_openssl_missing','google_keys_unavailable','google_keys_too_large','google_keys_invalid','google_audience_missing','google_key_type','google_key_use','google_key_alg','google_key_invalid'];
    }

    /**
     * The account's second factor, exactly as /mobile/google always asked it:
     * 428 two_factor_required, 429 two_factor_rate_limited, 401
     * two_factor_invalid, or null when satisfied (or not enabled). $audit is
     * the event prefix ('mobile' keeps 6.10.2's event names).
     */
    private static function second_factor($user_id, WP_REST_Request $request, $audit = 'mobile') {
        $user_id = absint($user_id);
        if (!class_exists('DIP_Two_Factor') || !DIP_Two_Factor::is_enabled($user_id)) return null;
        $prefix = sanitize_key($audit);
        $factor = sanitize_text_field((string)$request->get_param('two_factor_code'));
        if ($factor === '') {
            if (class_exists('DIP_Audit')) DIP_Audit::record($prefix . '_two_factor_required', 'notice', $user_id);
            return new WP_Error('two_factor_required','Two-factor verification is required.',['status'=>428]);
        }
        if (!DIP_Two_Factor::factor_attempt_allowed($user_id)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record($prefix . '_two_factor_rate_limited', 'warning', $user_id);
            return new WP_Error('two_factor_rate_limited','Too many two-factor attempts. Try again later.',['status'=>429]);
        }
        if (!DIP_Two_Factor::verify_factor($user_id, $factor, true)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record($prefix . '_two_factor_failed', 'warning', $user_id);
            return new WP_Error('two_factor_invalid','Invalid two-factor or recovery code.',['status'=>401]);
        }
        DIP_Two_Factor::clear_factor_rate($user_id);
        if (class_exists('DIP_Audit')) DIP_Audit::record($prefix . '_two_factor_success', 'info', $user_id);
        return null;
    }

    /** @internal 6.11.0: public for DIP_Mobile_Passkeys (generic 401). */
    public static function denied($event) { if(class_exists('DIP_Audit'))DIP_Audit::record($event,'critical',0); return new WP_Error('authentication_failed','Mobile authentication failed.',['status'=>401]); }

    private static function oauth_client_id($value) {
        $value=trim(sanitize_text_field((string)$value));
        return preg_match('/^[0-9]+-[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/D',$value)?$value:'';
    }

    /** @internal 6.11.0: public for DIP_Mobile_Passkeys (ceremony binding). */
    public static function request_device(WP_REST_Request $r) {
        $id=sanitize_text_field((string)($r->get_header('X-Device-ID')?:$r->get_param('device_id')));
        $install=sanitize_text_field((string)($r->get_header('X-Installation-ID')?:$r->get_param('installation_id')));
        $platform=sanitize_key((string)($r->get_header('X-Platform')?:$r->get_param('platform')?:'unknown'));
        if(!in_array($platform,['android','ios','web','unknown'],true))$platform='unknown';
        return [
            'device_hash'=>self::device_hash($id?:'anonymous'),
            'installation_hash'=>self::device_hash($install?:($id?:'anonymous')),
            'device_name'=>substr(sanitize_text_field((string)($r->get_header('X-Device-Name')?:$r->get_param('device_name'))),0,100),
            'platform'=>$platform,
            'app_version'=>substr(sanitize_text_field((string)($r->get_header('X-App-Version')?:$r->get_param('app_version'))),0,32),
            'locale'=>substr(sanitize_text_field((string)($r->get_header('X-Locale')?:$r->get_param('locale'))),0,16),
        ];
    }

    /**
     * Write a session row: a new one, or (refresh) the row being rotated.
     * Returns [access, refresh, device, row_id] or a WP_Error.
     */
    private static function issue_row($user_id,WP_REST_Request $request,$replace_id=0,$expected_refresh_hash='') {
        global $wpdb; $access=self::random_token(); $refresh=self::random_token(); $d=self::request_device($request); $now=current_time('mysql',true);
        $data=array_merge($d,['user_id'=>$user_id,'token_hash'=>self::hash_token($access),'refresh_hash'=>self::hash_token($refresh),'created_at'=>$now,'last_used_at'=>$now,'access_expires_at'=>gmdate('Y-m-d H:i:s',time()+self::access_ttl()),'refresh_expires_at'=>gmdate('Y-m-d H:i:s',time()+self::refresh_ttl()),'revoked_at'=>null]);
        if($replace_id){
            unset($data['created_at']);
            $where=['id'=>$replace_id];
            if($expected_refresh_hash!=='') $where['refresh_hash']=$expected_refresh_hash;
            $ok=$wpdb->update(self::table(),$data,$where);
            if($expected_refresh_hash!=='' && $ok!==1) return new WP_Error('refresh_replayed','Refresh token has already been rotated.',['status'=>401]);
        }
        else { $ok=$wpdb->insert(self::table(),$data); }
        if($ok===false) return new WP_Error('token_issue_failed','Unable to create a mobile session.',['status'=>500]);
        $row_id = $replace_id ? (int) $replace_id : (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE token_hash=%s LIMIT 1', $data['token_hash']));
        return ['access'=>$access,'refresh'=>$refresh,'device'=>$d,'row_id'=>$row_id];
    }

    private static function session_payload($user_id, array $issued) {
        return ['access_token'=>$issued['access'],'token_type'=>'Bearer','expires_in'=>self::access_ttl(),'refresh_token'=>$issued['refresh'],'refresh_expires_in'=>self::refresh_ttl(),'user'=>self::user_payload($user_id),'device'=>$issued['device']];
    }

    /** Session renewal (refresh): the same row, no alert, no proof. */
    private static function issue($user_id,WP_REST_Request $request,$replace_id=0,$expected_refresh_hash='') {
        $issued = self::issue_row($user_id, $request, $replace_id, $expected_refresh_hash);
        if (is_wp_error($issued)) return $issued;
        if(class_exists('DIP_Audit'))DIP_Audit::record('mobile_session_issued','info',$user_id);
        return new WP_REST_Response(self::session_payload($user_id, $issued),200);
    }

    /**
     * 6.11.0 — the one way a mobile sign-in becomes a session.
     *
     * $method: google, passkey, biometric, password_upgrade. A NEW row is
     * inserted, and the user's other live rows on the same installation are
     * revoked (replaced). When $interactive, the new session is « recently
     * proven » for PROOF_TTL. Fires
     *   do_action('dip_mobile_session_issued', $uid, $row_id, $method, $installation_hash, $is_new_installation)
     * where $is_new_installation is true when this user never had a row for
     * this installation. Returns issue()'s payload plus 'method'.
     *
     * INVARIANT: call it only once the account's second factor is satisfied:
     *  - google: two_factor_code is enforced by google_login();
     *  - passkey: WebAuthn user verification (UV) counts as the second factor,
     *    as on the website;
     *  - biometric: the key was enrolled under a recent proof that itself
     *    satisfied the second factor (password + code, Google + code, passkey);
     *  - password_upgrade (App API /auth/identity-session): password + code.
     * Session renewal (refresh) never comes here: no alert, no proof.
     */
    public static function issue_for_user($user_id, WP_REST_Request $request, $method, $interactive = true) {
        global $wpdb;
        $user_id = absint($user_id);
        $method = sanitize_key((string) $method);
        if (!in_array($method, self::ISSUE_METHODS, true)) return new WP_Error('invalid_session_method','Unknown sign-in method.',['status'=>500]);
        if (!self::enabled()) return new WP_Error('mobile_api_disabled','Mobile authentication is disabled.',['status'=>403]);
        if (!self::mobile_user_allowed($user_id)) return self::denied('mobile_account_not_allowed');
        $d = self::request_device($request);
        $prior = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE user_id=%d AND installation_hash=%s', $user_id, $d['installation_hash']));
        $issued = self::issue_row($user_id, $request);
        if (is_wp_error($issued)) return $issued;
        $row_id = (int) $issued['row_id'];
        $replaced = 0;
        if ($row_id > 0) {
            $replaced = (int) $wpdb->query($wpdb->prepare(
                'UPDATE '.self::table().' SET revoked_at=UTC_TIMESTAMP() WHERE user_id=%d AND installation_hash=%s AND id<>%d AND revoked_at IS NULL',
                $user_id, $d['installation_hash'], $row_id
            ));
        }
        if ($interactive && $row_id > 0) self::mark_proven($row_id);
        if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_session_issued','info',$user_id,['method'=>$method,'new_installation'=>$prior === 0 ? 1 : 0,'replaced'=>$replaced]);
        do_action('dip_mobile_session_issued', $user_id, $row_id, $method, $d['installation_hash'], $prior === 0);
        $payload = self::session_payload($user_id, $issued);
        $payload['method'] = $method;
        return new WP_REST_Response($payload, 200);
    }

    public static function refresh(WP_REST_Request $request) {
        // 6.11.0 (owner rule of 2026-10-24, successful use never counts): only
        // FAILED proofs count, 20 per installation and 200 per address. Every
        // attempt counts only against the SESSION_FLOOD_LIMIT flood guard, per
        // installation and, once the token is valid, per session. Password
        // customers upgraded to Identity sessions (quick sign-in, passkeys) all
        // renew here, so a valid refresh must never meet a usage limit.
        $gate = self::throttle('refresh', 20, 10 * MINUTE_IN_SECONDS, $request, 0, null, true);
        if (is_wp_error($gate)) return $gate;
        if (!self::valid_device_request($request)) { self::proof_failed($gate, 'device_required'); return new WP_Error('device_required','A valid device and installation identifier are required.',['status'=>400]); }
        if(!self::enabled()||!is_ssl())return new WP_Error('forbidden','Request denied.',['status'=>403]);
        $token=trim((string)$request->get_param('refresh_token'));
        if(!preg_match('/^[A-Za-z0-9\-_]{40,512}$/',$token)){ self::proof_failed($gate, 'malformed_refresh'); return new WP_Error('invalid_refresh','Invalid refresh token.',['status'=>401]); }
        global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE refresh_hash=%s AND revoked_at IS NULL AND refresh_expires_at > UTC_TIMESTAMP() LIMIT 1',self::hash_token($token)));
        if(!$row){ self::proof_failed($gate, 'invalid_refresh'); return new WP_Error('invalid_refresh','Refresh token expired or revoked.',['status'=>401]); }
        $d=self::request_device($request);
        if(!hash_equals((string)$row->installation_hash,(string)$d['installation_hash']) || !hash_equals((string)$row->device_hash,(string)$d['device_hash'])){
            $wpdb->update(self::table(),['revoked_at'=>current_time('mysql',true)],['id'=>(int)$row->id]);
            self::proof_failed($gate, 'device_mismatch');
            return self::denied('mobile_refresh_device_mismatch');
        }
        if (!self::mobile_user_allowed((int) $row->user_id)) { $wpdb->update(self::table(), ['revoked_at'=>current_time('mysql',true)], ['id'=>(int)$row->id]); return self::denied('mobile_account_not_allowed'); }
        $limited = self::session_throttle($gate, (int) $row->id, self::SESSION_FLOOD_LIMIT);
        if ($limited) return $limited;
        $issued = self::issue((int)$row->user_id,$request,(int)$row->id,self::hash_token($token));
        if (is_wp_error($issued) && $issued->get_error_code() === 'refresh_replayed') self::proof_failed($gate, 'refresh_replayed');
        return $issued;
    }

    public static function current_session_for_request($request = null, $require_device_binding = true) {
        if (!self::enabled()) return null;
        $token = '';
        if ($request instanceof WP_REST_Request) {
            $token = trim((string) $request->get_header('x_delicat_identity_token'));
            if ($token === '') {
                $auth = trim((string) $request->get_header('authorization'));
                if (preg_match('/^Bearer\s+([A-Za-z0-9\-_]{40,512})$/i', $auth, $m)) $token = $m[1];
            }
        }
        if ($token === '') {
            $auth = self::server_authorization_header();
            if (preg_match('/^Bearer\s+([A-Za-z0-9\-_]{40,512})$/i', $auth, $m)) $token = $m[1];
        }
        if (!preg_match('/^[A-Za-z0-9\-_]{40,512}$/', $token)) return null;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE token_hash=%s AND revoked_at IS NULL AND access_expires_at > UTC_TIMESTAMP() LIMIT 1', self::hash_token($token)));
        if (!$row || !self::mobile_user_allowed((int) $row->user_id)) return null;

        // Protected Identity endpoints are bound to the app installation that
        // received the token. A copied access token alone is therefore not
        // sufficient to use profile/session/push/biometric/pairing endpoints.
        if ($require_device_binding && $request instanceof WP_REST_Request) {
            if (!self::valid_device_request($request)) return null;
            $device = self::request_device($request);
            if (!hash_equals((string) $row->installation_hash, (string) $device['installation_hash'])
                || !hash_equals((string) $row->device_hash, (string) $device['device_hash'])) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_access_device_mismatch', 'critical', (int) $row->user_id, ['session_id'=>(int) $row->id]);
                return null;
            }
        }
        $wpdb->update(self::table(), ['last_used_at'=>current_time('mysql',true)], ['id'=>(int)$row->id], ['%s'], ['%d']);
        return $row;
    }

    /** Check a previously authenticated mobile session by internal ID/user. */
    public static function session_is_active($session_id, $user_id) {
        $session_id = absint($session_id);
        $user_id = absint($user_id);
        if (!$session_id || !$user_id) return false;
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table() . ' WHERE id=%d AND user_id=%d AND revoked_at IS NULL AND refresh_expires_at > UTC_TIMESTAMP() LIMIT 1',
            $session_id,
            $user_id
        ));
        return absint($found) === $session_id;
    }

    /** Signing out ends the session only: the phone's quick sign-in key stays (signing out is not forgetting the phone). */
    public static function logout(WP_REST_Request $request) { $row=self::current_session_for_request($request); if($row){global $wpdb;$wpdb->update(self::table(),['revoked_at'=>current_time('mysql',true)],['id'=>(int)$row->id]);} return new WP_REST_Response(['logged_out'=>true],200); }

    /**
     * Revoke every Delicat mobile refresh/access session for an account.
     * 6.11.0: the account's quick sign-in keys go with them (password change
     * or reset, e-mail change, two-step verification on/off, refused account
     * policy, « déconnecter les autres »), and the revocation is announced:
     *   do_action('dip_mobile_sessions_revoked', $uid, '', 'all', $reason)
     */
    public static function revoke_all_for_user($user_id, $reason = 'security_change') {
        $user_id = absint($user_id);
        if (!$user_id) return 0;
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE '.self::table().' SET revoked_at=UTC_TIMESTAMP() WHERE user_id=%d AND revoked_at IS NULL',
            $user_id
        ));
        if ($updated === false) return 0;
        $keys = self::revoke_keys_where('user_id=%d', [$user_id], $reason);
        if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_sessions_revoked', 'warning', $user_id, [
            'reason'=>sanitize_key($reason),
            'count'=>(int) $updated,
            'keys'=>(int) $keys,
        ]);
        do_action('dip_mobile_sessions_revoked', $user_id, '', 'all', sanitize_key($reason));
        return (int) $updated;
    }

    /** App API: « Mes données » / account deletion asked to end every external session. */
    public static function on_app_revoke_all($user_id) {
        $user_id = absint($user_id);
        if (!$user_id || isset(self::$revoking[$user_id])) return;
        self::$revoking[$user_id] = true;
        try { self::revoke_all_for_user($user_id, 'app_revoke_all'); } finally { unset(self::$revoking[$user_id]); }
    }

    /** App API: the customer's data is being erased (account deletion). */
    public static function on_app_erase_customer_data($user_id) {
        $user_id = absint($user_id);
        if ($user_id) self::erase_credentials($user_id, false);
    }

    public static function profile(){return new WP_REST_Response(self::user_payload(get_current_user_id()),200);}
    private static function user_payload($id){
        $id=absint($id);
        if(class_exists('Delicat_App_Auth')&&is_callable(['Delicat_App_Auth','user_payload'])){
            $payload=(array)Delicat_App_Auth::user_payload($id);
            if($payload)return $payload;
        }
        $u=get_userdata($id);
        if(!$u)return[];
        $code=sanitize_text_field((string)apply_filters('delicat_app_user_code','',$id));
        return[
            'id'=>$id,
            'email'=>sanitize_email($u->user_email),
            'name'=>sanitize_text_field($u->display_name),
            'display_name'=>sanitize_text_field($u->display_name),
            'username'=>sanitize_user($u->user_login),
            'phone'=>sanitize_text_field((string)get_user_meta($id,'billing_phone',true)),
            'avatar'=>get_avatar_url($id,['size'=>192]),
            'user_code'=>$code,
            'roles'=>array_values((array)$u->roles),
        ];
    }

    /** 6.11.0: biometric_enabled now says whether the session's installation holds a live quick sign-in key. */
    public static function sessions(){
        global $wpdb;
        $uid = get_current_user_id();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,installation_hash,device_name,platform,app_version,locale,created_at,last_used_at,access_expires_at,refresh_expires_at FROM '.self::table().' WHERE user_id=%d AND revoked_at IS NULL ORDER BY last_used_at DESC LIMIT 50',$uid),ARRAY_A);
        $keyed = array_flip(self::biometric_installations($uid));
        $out = [];
        foreach ((array) $rows as $row) {
            $row = (array) $row;
            $row['biometric_enabled'] = isset($keyed[(string) ($row['installation_hash'] ?? '')]) ? 1 : 0;
            unset($row['installation_hash']);
            $out[] = $row;
        }
        return new WP_REST_Response(['sessions'=>$out],200);
    }

    /**
     * End one of the customer's sessions. 6.11.0: announced as
     *   do_action('dip_mobile_sessions_revoked', $uid, $installation_hash, 'session', 'user_revoke')
     * and, when the caller ends ANOTHER phone's session from a phone that is
     * itself identified, that phone's quick sign-in key goes too (remote
     * revocation). Called without a device-bound caller (the App API), the key
     * decision stays with the caller (revoke_installation_keys()).
     */
    public static function revoke_session(WP_REST_Request $r){
        global $wpdb;
        $uid = get_current_user_id();
        $id = absint($r['id']);
        $row = $id ? $wpdb->get_row($wpdb->prepare('SELECT id,installation_hash FROM '.self::table().' WHERE id=%d AND user_id=%d AND revoked_at IS NULL LIMIT 1', $id, $uid)) : null;
        $updated = $wpdb->update(self::table(),['revoked_at'=>current_time('mysql',true)],['id'=>$id,'user_id'=>$uid]);
        if ($updated && $row) {
            $caller = self::current_session_for_request($r);
            if ($caller && (int) $caller->user_id === (int) $uid && !hash_equals((string) $caller->installation_hash, (string) $row->installation_hash)) {
                self::revoke_installation_keys($uid, (string) $row->installation_hash, 'remote_revoke');
            }
            do_action('dip_mobile_sessions_revoked', $uid, (string) $row->installation_hash, 'session', 'user_revoke');
        }
        return new WP_REST_Response(['revoked'=>(bool)$updated],200);
    }

    public static function update_device(WP_REST_Request $r){$row=self::current_session_for_request($r);if(!$row)return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);$d=self::request_device($r);global $wpdb;$wpdb->update(self::table(),['device_name'=>$d['device_name'],'app_version'=>$d['app_version'],'locale'=>$d['locale']],['id'=>(int)$row->id]);return new WP_REST_Response(['updated'=>true],200);}

    public static function register_push(WP_REST_Request $r){
        if((self::settings()['mobile_push_enabled']??'no')!=='yes')return new WP_Error('push_disabled','Push registration is disabled.',['status'=>403]);
        $row=self::current_session_for_request($r);if(!$row)return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);
        $token=trim((string)$r->get_param('push_token'));$provider=sanitize_key((string)$r->get_param('provider'));
        if(!in_array($provider,['fcm','apns'],true)||strlen($token)<20||strlen($token)>4096)return new WP_Error('invalid_push','Invalid push registration.',['status'=>400]);
        $encrypted = DIP_Crypto::encrypt($token);
        if ($encrypted === '') return new WP_Error('encryption_unavailable','Secure encryption is unavailable.',['status'=>503]);
        global $wpdb;$wpdb->update(self::table(),['push_provider'=>$provider,'push_token_enc'=>$encrypted],['id'=>(int)$row->id]);
        do_action('dip_mobile_push_registered',(int)$row->user_id,(int)$row->id,$provider);
        return new WP_REST_Response(['registered'=>true],200);
    }
    public static function remove_push(WP_REST_Request $r){ $row=self::current_session_for_request($r);if(!$row)return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);global $wpdb;$wpdb->update(self::table(),['push_provider'=>'','push_token_enc'=>null],['id'=>(int)$row->id]);return new WP_REST_Response(['removed'=>true],200); }

    // =========================================================================
    // 6.11.0 — « Preuve récente » and re-authentication
    // =========================================================================

    /** Mark a session as recently proven (interactive sign-in or reauth; never a refresh). Returns the end timestamp. */
    public static function mark_proven($row_id) {
        $row_id = absint($row_id);
        if (!$row_id) return 0;
        $until = time() + self::PROOF_TTL;
        set_transient('dip_mproof_' . $row_id, $until, self::PROOF_TTL);
        return $until;
    }

    /** The session (row id) proved who it is within the last 10 minutes. */
    public static function recently_proven($row_id) {
        $row_id = absint($row_id);
        if (!$row_id) return false;
        return (int) get_transient('dip_mproof_' . $row_id) > time();
    }

    /** What a 403 reauth_required offers: password always, Google if linked, a passkey if the account has one. */
    public static function reauth_methods($user_id) {
        $user_id = absint($user_id);
        $methods = ['password'];
        $meta_sub = class_exists('DIP_Identity') ? DIP_Identity::META_SUB : '_dglp_google_sub';
        if (self::google_mobile_enabled() && (string) get_user_meta($user_id, $meta_sub, true) !== '') $methods[] = 'google';
        if (class_exists('DIP_Mobile_Passkeys') && DIP_Mobile_Passkeys::enabled() && class_exists('DIP_Passkeys') && DIP_Passkeys::count($user_id) > 0) $methods[] = 'passkey';
        return $methods;
    }

    /** Null when the session is recently proven, else the 403 reauth_required with data.methods. */
    public static function require_recent_proof($session) {
        if (is_object($session) && self::recently_proven((int) $session->id)) return null;
        $uid = is_object($session) ? (int) $session->user_id : 0;
        return new WP_Error('reauth_required', 'Confirmez votre identité pour continuer.', ['status' => 403, 'methods' => self::reauth_methods($uid)]);
    }

    /**
     * Re-authentication limits, per 10 minutes: 10 FAILURES per installation
     * (wrong password, wrong code, refused Google token or passkey assertion:
     * the guessing guard), and the SESSION_FLOOD_LIMIT flood guard per session
     * (each call writes an audit row). A successful confirmation never brings
     * a customer near either (owner rule, 2026-10-24: no usage limit).
     * Returns the gate for proof_failed() or the 429.
     *
     * @internal public for DIP_Mobile_Passkeys.
     */
    public static function reauth_gate($session) {
        $installation = substr(hash_hmac('sha256', (string) $session->installation_hash, wp_salt('nonce')), 0, 32);
        $gate = [
            'bucket' => 'reauth',
            'window' => 10 * MINUTE_IN_SECONDS,
            'failures' => 'dip_mob_reauth_fail_' . $installation,
            'failure_limit' => 10,
            'suspects' => 'dip_mob_reauth_suspects_' . $installation,
            'address' => substr(hash_hmac('sha256', DIP_Native_Auth::throttle_ip(), wp_salt('nonce')), 0, 12),
            'installation' => substr((string) $session->installation_hash, 0, 12),
        ];
        if (DIP_Lockout::counter_peek($gate['failures'])['count'] >= $gate['failure_limit']) return self::rate_limited($gate, 'installation_failures', $gate['failures']);
        $key = 'dip_mob_reauth_sess_' . absint($session->id);
        if (DIP_Lockout::counter_hit($key, $gate['window']) > self::SESSION_FLOOD_LIMIT) return self::rate_limited($gate, 'session', $key);
        return $gate;
    }

    /** {proven_until, expires_in} after a successful reauth. */
    public static function proven_response($session, $method) {
        $until = self::mark_proven((int) $session->id);
        if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_reauth', 'info', (int) $session->user_id, ['method' => sanitize_key($method), 'session_id' => (int) $session->id]);
        return new WP_REST_Response(['proven_until' => gmdate('c', $until), 'expires_in' => self::PROOF_TTL], 200);
    }

    private static function reauth_refused($gate, $session, $method, $reason, WP_Error $error) {
        self::proof_failed($gate, $reason);
        if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_reauth_failed', 'warning', (int) $session->user_id, ['method' => sanitize_key($method), 'reason' => sanitize_key($reason)]);
        return $error;
    }

    /** POST /mobile/reauth/password {password, two_factor_code?}. */
    public static function reauth_password(WP_REST_Request $r) {
        $session = self::current_session_for_request($r);
        if (!$session) return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);
        $gate = self::reauth_gate($session);
        if (is_wp_error($gate)) return $gate;
        $uid = (int) $session->user_id;
        $user = get_userdata($uid);
        $password = (string) $r->get_param('password');
        if (!$user || $password === '' || strlen($password) > 4096 || !wp_check_password($password, (string) $user->user_pass, $uid)) {
            return self::reauth_refused($gate, $session, 'password', 'password', new WP_Error('invalid_password', 'Mot de passe incorrect.', ['status' => 401]));
        }
        $factor = self::second_factor($uid, $r, 'mobile_reauth');
        if ($factor) {
            if ($factor->get_error_code() === 'two_factor_invalid') self::proof_failed($gate, 'two_factor');
            return $factor;
        }
        return self::proven_response($session, 'password');
    }

    /** POST /mobile/reauth/google {id_token, two_factor_code?}: the same customer's Google account. */
    public static function reauth_google(WP_REST_Request $r) {
        $session = self::current_session_for_request($r);
        if (!$session) return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);
        $gate = self::reauth_gate($session);
        if (is_wp_error($gate)) return $gate;
        if (!self::google_mobile_enabled() || !class_exists('DIP_Google')) return new WP_Error('reauth_method_unavailable', 'Cette méthode de confirmation n’est pas disponible.', ['status' => 403]);
        $uid = (int) $session->user_id;
        $id_token = trim((string) $r->get_param('id_token'));
        if (strlen($id_token) < 100 || strlen($id_token) > 65536) return self::reauth_refused($gate, $session, 'google', 'invalid_token', new WP_Error('invalid_token','Invalid Google token.',['status'=>400]));
        $s = self::settings();
        $p = DIP_Google::verify_id_token($id_token, [self::oauth_client_id($s['client_id'] ?? '')], '');
        if (is_wp_error($p)) {
            if (in_array($p->get_error_code(), self::google_outage_codes(), true)) return new WP_Error('google_unavailable', 'Google est momentanément indisponible. Réessayez.', ['status' => 503]);
            return self::reauth_refused($gate, $session, 'google', $p->get_error_code(), self::denied('mobile_reauth_google_rejected'));
        }
        $platform = sanitize_key((string) ($r->get_header('X-Platform') ?: $r->get_param('platform') ?: 'unknown'));
        $platform_client_id = $platform === 'android' ? self::oauth_client_id($s['android_client_id'] ?? '') : ($platform === 'ios' ? self::oauth_client_id($s['ios_client_id'] ?? '') : '');
        $azp = trim((string) ($p['azp'] ?? ''));
        if ($platform_client_id === '' || ($azp !== '' && !hash_equals($platform_client_id, $azp))) return self::reauth_refused($gate, $session, 'google', 'azp_mismatch', self::denied('mobile_reauth_google_party_mismatch'));
        $meta_sub = class_exists('DIP_Identity') ? DIP_Identity::META_SUB : '_dglp_google_sub';
        $linked = (string) get_user_meta($uid, $meta_sub, true);
        $sub = (string) ($p['sub'] ?? '');
        if ($linked === '' || $sub === '' || !hash_equals($linked, $sub)) return self::reauth_refused($gate, $session, 'google', 'other_account', self::denied('mobile_reauth_google_other_account'));
        $factor = self::second_factor($uid, $r, 'mobile_reauth');
        if ($factor) {
            if ($factor->get_error_code() === 'two_factor_invalid') self::proof_failed($gate, 'two_factor');
            return $factor;
        }
        return self::proven_response($session, 'google');
    }

    // =========================================================================
    // 6.11.0 — Quick sign-in keys (fingerprint or face)
    // =========================================================================

    public static function biometric_table() { global $wpdb; return $wpdb->prefix . self::BIOMETRIC_TABLE_SUFFIX; }
    private static function biometric_ready() { return get_option(self::BIOMETRIC_READY_OPTION) === '1'; }

    /** The owner's switch is on and the key store works: what capabilities() calls biometric_enabled. */
    public static function biometric_available() {
        return self::enabled()
            && (self::settings()['mobile_biometric_enabled'] ?? 'no') === 'yes'
            && self::biometric_ready()
            && function_exists('openssl_verify') && function_exists('openssl_pkey_get_public');
    }

    private static function biometric_gate() {
        if (!self::enabled() || !is_ssl()) return new WP_Error('forbidden','Request denied.',['status'=>403]);
        if ((self::settings()['mobile_biometric_enabled'] ?? 'no') !== 'yes') return new WP_Error('quick_sign_in_disabled', 'La connexion rapide est désactivée.', ['status' => 403]);
        if (!function_exists('openssl_verify') || !function_exists('openssl_pkey_get_public')) return new WP_Error('crypto_unavailable','Biometric verification is unavailable.',['status'=>503]);
        if (!self::biometric_ready()) return new WP_Error('quick_sign_in_unavailable', 'La connexion rapide n’est pas encore disponible.', ['status' => 503]);
        return true;
    }

    /** Current UTC time as a timestamp, through current_time() (the clock every stored datetime uses). */
    private static function now_ts() {
        $ts = strtotime(current_time('mysql', true) . ' UTC');
        return $ts ? (int) $ts : time();
    }

    private static function mysql_at($ts) { return gmdate('Y-m-d H:i:s', (int) $ts); }

    private static function iso_or_null($datetime) {
        $datetime = trim((string) $datetime);
        if ($datetime === '' || strpos($datetime, '0000-00-00') === 0) return null;
        $ts = strtotime($datetime . ' UTC');
        return $ts ? gmdate('c', $ts) : null;
    }

    /** The RP ID the signed messages name (the passkeys' RP ID, the site's host). */
    public static function rp_id() {
        if (class_exists('DIP_Passkeys')) return (string) DIP_Passkeys::rp_id();
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return preg_match('/^[a-z0-9.-]+$/D', $host) ? trim($host, '.') : '';
    }

    /** Base64 or base64url, padding optional; false when invalid. */
    private static function decode_b64($value) {
        if (!is_string($value)) return false;
        $value = preg_replace('/\s+/', '', $value);
        if ($value === '' || !preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/D', $value)) return false;
        $value = strtr(rtrim($value, '='), '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad === 1) return false;
        if ($pad) $value .= str_repeat('=', 4 - $pad);
        return base64_decode($value, true);
    }

    private static function der_to_pem($der, $label) {
        return '-----BEGIN ' . $label . "-----\n" . chunk_split(base64_encode($der), 64, "\n") . '-----END ' . $label . "-----\n";
    }

    private static function pem_body($pem) {
        if (!preg_match('/-----BEGIN [A-Z ]+-----(.+?)-----END [A-Z ]+-----/s', (string) $pem, $m)) return '';
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        return $der === false ? '' : $der;
    }

    /** A base64 DER SubjectPublicKeyInfo that must be EC prime256v1 (Android Keystore, ES256). */
    private static function parse_ec_public_key($value) {
        $der = self::decode_b64($value);
        $invalid = new WP_Error('invalid_public_key', 'Clé de connexion rapide invalide : seule une clé EC P-256 est acceptée.', ['status' => 400]);
        if ($der === false || strlen($der) < 60 || strlen($der) > 1024) return $invalid;
        $pem = self::der_to_pem($der, 'PUBLIC KEY');
        $key = @openssl_pkey_get_public($pem);
        if (!$key) return $invalid;
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || (int) ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_EC || (string) ($details['ec']['curve_name'] ?? '') !== 'prime256v1') return $invalid;
        return ['pem' => $pem, 'der' => $der, 'hash' => hash('sha256', $der)];
    }

    /**
     * Android Key Attestation chain: only LOGGED. 'leaf_match' when the leaf
     * certificate carries the registered public key, otherwise 'none'. Never
     * required (many phones, emulators and some ROMs send none).
     */
    private static function attestation_level($chain, $der) {
        if ($chain === null || $chain === '' || $chain === []) return 'none';
        if (!is_array($chain) || count($chain) > 5) return new WP_Error('invalid_attestation', 'Attestation invalide.', ['status' => 400]);
        $leaf = '';
        foreach (array_values($chain) as $i => $certificate) {
            $bytes = self::decode_b64($certificate);
            if ($bytes === false || strlen($bytes) < 16 || strlen($bytes) > 8192) return new WP_Error('invalid_attestation', 'Attestation invalide.', ['status' => 400]);
            if ($i === 0) $leaf = $bytes;
        }
        if ($leaf === '' || !function_exists('openssl_x509_read')) return 'none';
        $x509 = @openssl_x509_read(self::der_to_pem($leaf, 'CERTIFICATE'));
        if (!$x509) return 'none';
        $public = @openssl_pkey_get_public($x509);
        $details = $public ? openssl_pkey_get_details($public) : false;
        $leaf_der = is_array($details) ? self::pem_body((string) ($details['key'] ?? '')) : '';
        return ($leaf_der !== '' && hash_equals($leaf_der, (string) $der)) ? 'leaf_match' : 'none';
    }

    private static function bio_ceremony_key($id) { return 'dip_bio_' . hash_hmac('sha256', (string) $id, wp_salt('nonce')); }

    private static function bio_store_ceremony($id, array $state) {
        $state['expires'] = time() + self::BIOMETRIC_CEREMONY_TTL;
        set_transient(self::bio_ceremony_key($id), $state, self::BIOMETRIC_CEREMONY_TTL);
    }

    /**
     * Atomic one-time use of a quick sign-in ceremony: the add_option lock
     * (as for the website's passkeys), a one-time claim on the atomic counter
     * table, then the transient is read and deleted whatever happens next.
     */
    private static function bio_consume_ceremony($id, $purpose) {
        $id = trim((string) $id);
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/D', $id)) return null;
        $lock = 'dip_bio_lock_' . substr(hash_hmac('sha256', $id, wp_salt('secure_auth')), 0, 40);
        $now = time();
        if (!add_option($lock, $now, '', false)) {
            $existing = (int) get_option($lock, 0);
            if ($existing > 0 && ($existing + 10) < $now) delete_option($lock);
            if (!add_option($lock, $now, '', false)) return null;
        }
        try {
            $key = self::bio_ceremony_key($id);
            if (DIP_Lockout::counter_hit($key . '_used', 2 * self::BIOMETRIC_CEREMONY_TTL) !== 1) { delete_transient($key); return null; }
            $state = get_transient($key);
            delete_transient($key);
            if (!is_array($state) || ($state['purpose'] ?? '') !== $purpose || (int) ($state['expires'] ?? 0) < time()) return null;
            return $state;
        } finally {
            delete_option($lock);
        }
    }

    private static function active_key_clause() {
        return 'revoked_at IS NULL AND expires_at > %s';
    }

    /**
     * Revoke the live keys matching $where (prepared fragment + args); notifies
     * and audits per key. Expired keys are already unusable: left to cleanup().
     */
    private static function revoke_keys_where($where, array $args, $reason, $notify = true) {
        global $wpdb;
        if (!self::biometric_ready()) return 0;
        $table = self::biometric_table();
        $args[] = self::mysql_at(self::now_ts());
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,user_id,label FROM ' . $table . ' WHERE ' . $where . ' AND ' . self::active_key_clause(), $args));
        $wpdb->suppress_errors($suppress);
        $count = 0;
        foreach ((array) $rows as $row) {
            $done = $wpdb->update($table, ['revoked_at' => self::mysql_at(self::now_ts()), 'revoke_reason' => substr(sanitize_key($reason), 0, 32)], ['id' => (int) $row->id, 'revoked_at' => null]);
            if (!$done) continue;
            $count++;
            if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_biometric_removed', 'notice', (int) $row->user_id, ['key_id' => (int) $row->id, 'reason' => sanitize_key($reason)]);
            if ($notify) do_action('dip_mobile_credential_changed', (int) $row->user_id, 'biometric', 'removed', (string) $row->label);
        }
        return $count;
    }

    /** Installation hashes (as in the session table) where the user holds a live quick sign-in key. */
    public static function biometric_installations($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        if (!$user_id || !self::biometric_ready()) return [];
        $suppress = $wpdb->suppress_errors(true);
        $hashes = $wpdb->get_results($wpdb->prepare('SELECT DISTINCT installation_hash FROM ' . self::biometric_table() . ' WHERE user_id=%d AND ' . self::active_key_clause(), $user_id, self::mysql_at(self::now_ts())));
        $wpdb->suppress_errors($suppress);
        $out = [];
        foreach ((array) $hashes as $row) $out[] = (string) $row->installation_hash;
        return $out;
    }

    /** Revoke the user's quick sign-in key(s) on one installation. Returns how many. */
    public static function revoke_installation_keys($user_id, $installation_hash, $reason = 'remote_revoke') {
        $user_id = absint($user_id);
        $installation_hash = strtolower(trim((string) $installation_hash));
        if (!$user_id || !preg_match('/^[a-f0-9]{64}$/', $installation_hash)) return 0;
        return self::revoke_keys_where('user_id=%d AND installation_hash=%s', [$user_id, $installation_hash], $reason);
    }

    /** {passkeys, biometric}: what « Mes données » shows. */
    public static function credential_counts($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        $passkeys = ($user_id && class_exists('DIP_Passkeys')) ? (int) DIP_Passkeys::count($user_id) : 0;
        $biometric = 0;
        if ($user_id && self::biometric_ready()) {
            $suppress = $wpdb->suppress_errors(true);
            $biometric = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::biometric_table() . ' WHERE user_id=%d AND ' . self::active_key_clause(), $user_id, self::mysql_at(self::now_ts())));
            $wpdb->suppress_errors($suppress);
        }
        return ['passkeys' => $passkeys, 'biometric' => $biometric];
    }

    /**
     * Erase the account's passkeys and quick sign-in keys (« Mes données »,
     * account deletion). The password and Google stay. Returns the counts.
     */
    public static function erase_credentials($user_id, $notify = true) {
        global $wpdb;
        $user_id = absint($user_id);
        if (!$user_id) return ['passkeys' => 0, 'biometric' => 0];
        $counts = self::credential_counts($user_id);
        if (class_exists('DIP_Passkeys')) {
            delete_user_meta($user_id, DIP_Passkeys::META_CREDENTIALS);
            delete_user_meta($user_id, DIP_Passkeys::META_USER_HANDLE);
            if ($notify && $counts['passkeys'] > 0) do_action('dip_mobile_credential_changed', $user_id, 'passkey', 'removed', '');
        }
        if (self::biometric_ready()) {
            self::revoke_keys_where('user_id=%d', [$user_id], 'erased', $notify);
            $suppress = $wpdb->suppress_errors(true);
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::biometric_table() . ' WHERE user_id=%d', $user_id));
            $wpdb->suppress_errors($suppress);
        }
        if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_credentials_erased', 'warning', $user_id, ['passkeys' => (int) $counts['passkeys'], 'biometric' => (int) $counts['biometric']]);
        return $counts;
    }

    /** POST /mobile/biometric/enroll (session + recent proof): the message the phone signs with its new key. */
    public static function biometric_enroll(WP_REST_Request $r) {
        $ready = self::biometric_gate();
        if (is_wp_error($ready)) return $ready;
        $session = self::current_session_for_request($r);
        if (!$session) return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);
        $proof = self::require_recent_proof($session);
        if ($proof) return $proof;
        // Flood guard only (one stored ceremony per call), after the proof: a 403 is never counted.
        $limited = self::session_route_limit('biometric_enroll', (int) $session->id);
        if ($limited) return $limited;
        $installation = self::installation_id($r);
        if ($installation === '') return new WP_Error('device_required','A valid device and installation identifier are required.',['status'=>400]);
        $id = self::random_token(24);
        $challenge = self::random_token(32);
        $message = implode('|', [self::BIOMETRIC_MESSAGE_PREFIX, 'enroll', $challenge, $installation, self::rp_id(), (string) (int) $session->user_id]);
        self::bio_store_ceremony($id, [
            'purpose' => 'enroll', 'user_id' => (int) $session->user_id, 'session_id' => (int) $session->id,
            'installation_hash' => (string) $session->installation_hash, 'device_hash' => (string) $session->device_hash,
            'message' => $message,
        ]);
        return new WP_REST_Response(['challenge_id' => $id, 'challenge' => $challenge, 'message' => $message, 'expires_in' => self::BIOMETRIC_CEREMONY_TTL], 200);
    }

    /**
     * POST /mobile/biometric/register (session): {challenge_id, public_key
     * (base64 DER SPKI, EC P-256), signature (base64 DER over the enrol
     * message), attestation?}. The 6.10 body (a PEM alone) is refused.
     */
    public static function biometric_register(WP_REST_Request $r){
        $ready = self::biometric_gate();
        if (is_wp_error($ready)) return $ready;
        $session = self::current_session_for_request($r);
        if (!$session) return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);
        $challenge_id = trim((string) $r->get_param('challenge_id'));
        $signature_raw = $r->get_param('signature');
        if ($challenge_id === '' || !is_string($signature_raw) || trim($signature_raw) === '') {
            return new WP_Error('biometric_proof_required', 'Mettez l’application à jour pour activer la connexion rapide.', ['status' => 400]);
        }
        // Flood guard only (a key row and a security e-mail per success).
        $limited = self::session_route_limit('biometric_register', (int) $session->id);
        if ($limited) return $limited;
        $key = self::parse_ec_public_key($r->get_param('public_key'));
        if (is_wp_error($key)) return $key;
        $signature = self::decode_b64($signature_raw);
        if ($signature === false || strlen($signature) < 8 || strlen($signature) > 512) return new WP_Error('invalid_biometric', 'Invalid biometric proof.', ['status' => 400]);
        $attestation = self::attestation_level($r->get_param('attestation'), $key['der']);
        if (is_wp_error($attestation)) return $attestation;
        $state = self::bio_consume_ceremony($challenge_id, 'enroll');
        if (!$state) return new WP_Error('challenge_expired', 'Biometric challenge expired.', ['status' => 401]);
        if ((int) $state['session_id'] !== (int) $session->id || (int) $state['user_id'] !== (int) $session->user_id
            || !hash_equals((string) $state['installation_hash'], (string) $session->installation_hash)
            || !hash_equals((string) $state['device_hash'], (string) $session->device_hash)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_biometric_context_mismatch', 'critical', (int) $session->user_id);
            return new WP_Error('biometric_context_mismatch', 'Cette activation a été commencée sur un autre appareil.', ['status' => 403]);
        }
        if (openssl_verify((string) $state['message'], $signature, $key['pem'], OPENSSL_ALGO_SHA256) !== 1) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_biometric_proof_failed', 'warning', (int) $session->user_id);
            return new WP_Error('biometric_proof_invalid', 'La signature de la clé ne correspond pas.', ['status' => 400]);
        }
        global $wpdb;
        $uid = (int) $session->user_id;
        $now = self::now_ts();
        // Optional label (the phone's name); else the session's device name.
        $label = sanitize_text_field((string) $r->get_param('label'));
        if ($label === '') $label = sanitize_text_field((string) ($session->device_name !== '' ? $session->device_name : self::request_device($r)['device_name']));
        $label = function_exists('mb_substr') ? mb_substr($label, 0, 100) : substr($label, 0, 100);
        if ($label === '') $label = 'Téléphone';
        $table = self::biometric_table();
        $ok = $wpdb->insert($table, [
            'user_id' => $uid, 'installation_hash' => (string) $session->installation_hash, 'device_hash' => (string) $session->device_hash,
            'public_key' => $key['pem'], 'key_hash' => $key['hash'], 'alg' => 'ES256', 'attestation_level' => $attestation,
            'label' => $label, 'created_at' => self::mysql_at($now), 'last_used_at' => null,
            'expires_at' => self::mysql_at($now + self::BIOMETRIC_KEY_TTL), 'revoked_at' => null, 'revoke_reason' => '',
        ]);
        if ($ok === false) return new WP_Error('biometric_save_failed', 'La connexion rapide n’a pas pu être enregistrée.', ['status' => 500]);
        $key_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $table . ' WHERE installation_hash=%s AND key_hash=%s AND revoked_at IS NULL ORDER BY id DESC LIMIT 1', (string) $session->installation_hash, $key['hash']));
        // One active key per installation: the phone's Keystore alias holds only the new one.
        self::revoke_keys_where('installation_hash=%s AND id<>%d', [(string) $session->installation_hash, $key_id], 'replaced');
        if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_biometric_enrolled', 'notice', $uid, ['key_id' => $key_id, 'key' => substr($key['hash'], 0, 16), 'attestation' => $attestation]);
        if (class_exists('DIP_Email_Hub')) {
            DIP_Email_Hub::send_user_security($uid, 'Connexion rapide activée sur votre compte Delicat', sprintf('La connexion par empreinte ou visage a été activée sur « %s ». Si vous n’êtes pas à l’origine de cette action, changez votre mot de passe : la connexion rapide sera désactivée sur tous vos appareils.', $label), ['title' => 'Connexion rapide activée', 'label' => 'Connexion rapide']);
        }
        do_action('dip_mobile_credential_changed', $uid, 'biometric', 'added', $label);
        return new WP_REST_Response(['registered' => true, 'key_id' => $key_id, 'expires_at' => gmdate('c', $now + self::BIOMETRIC_KEY_TTL)], 200);
    }

    public static function biometric_challenge(WP_REST_Request $r){
        // Owner rule (2026-10-24): no usage limit. Only failed lookups count:
        // 10 per installation, 100 per address; every challenge counts only
        // against the flood guard (SESSION_FLOOD_LIMIT per installation), so a
        // customer who cancels the fingerprint prompt and tries again is
        // never refused. The bucket is the installation this route looks up
        // (the body param, canonical), never a header that could be rotated
        // freely; a header that names another installation is a failure.
        $requested = self::canonical_installation($r->get_param('installation_id'));
        $gate = self::throttle('biometric_challenge', 10, 10 * MINUTE_IN_SECONDS, $r, 0, $requested, true);
        if (is_wp_error($gate)) return $gate;
        if ($requested === '') { self::proof_failed($gate, 'invalid_installation'); return new WP_Error('invalid_installation','Invalid installation.',['status'=>400]); }
        $header = trim((string) $r->get_header('X-Installation-ID'));
        if ($header !== '' && !hash_equals($requested, self::canonical_installation($header))) { self::proof_failed($gate, 'installation_mismatch'); return new WP_Error('invalid_installation','Invalid installation.',['status'=>400]); }
        $ready = self::biometric_gate();
        if (is_wp_error($ready)) return $ready;
        $installation=self::device_hash($requested);
        // 6.11.0: the key table, not a live session: quick sign-in works after sign-out.
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT id,user_id FROM '.self::biometric_table().' WHERE installation_hash=%s AND '.self::active_key_clause().' ORDER BY id DESC LIMIT 1',$installation,self::mysql_at(self::now_ts())));
        if(!$row){ self::proof_failed($gate, 'biometric_not_enrolled'); return new WP_Error('biometric_not_enrolled','La connexion rapide n’est plus active sur ce téléphone.',['status'=>404]); }
        $id = self::random_token(24);
        $challenge = self::random_token(32);
        $message = implode('|', [self::BIOMETRIC_MESSAGE_PREFIX, 'login', $challenge, $requested, self::rp_id()]);
        self::bio_store_ceremony($id, ['purpose' => 'login', 'key_id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'installation_hash' => $installation, 'message' => $message]);
        return new WP_REST_Response(['challenge_id'=>$id,'challenge'=>$challenge,'message'=>$message,'expires_in'=>self::BIOMETRIC_CEREMONY_TTL,'algorithm'=>'SHA256withECDSA'],200);
    }

    public static function biometric_verify(WP_REST_Request $r){
        // Failed proofs only: 10 per installation, 100 per address; a valid
        // signature's key, and every attempt of an installation, count only
        // against the flood guard (SESSION_FLOOD_LIMIT). Owner rule, 2026-10-24.
        $gate = self::throttle('biometric_verify', 10, 10 * MINUTE_IN_SECONDS, $r, 0, null, true);
        if (is_wp_error($gate)) return $gate;
        if (!self::valid_device_request($r)) { self::proof_failed($gate, 'device_required'); return new WP_Error('device_required','A valid device and installation identifier are required.',['status'=>400]); }
        $ready = self::biometric_gate();
        if (is_wp_error($ready)) return $ready;
        $challenge_id = trim((string) $r->get_param('challenge_id'));
        $signature = self::decode_b64($r->get_param('signature'));
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/D', $challenge_id) || $signature === false || strlen($signature) < 8 || strlen($signature) > 2048) { self::proof_failed($gate, 'invalid_biometric'); return new WP_Error('invalid_biometric','Invalid biometric proof.',['status'=>400]); }
        $state = self::bio_consume_ceremony($challenge_id, 'login');
        if (!is_array($state)) { self::proof_failed($gate, 'challenge_expired'); return new WP_Error('challenge_expired','Biometric challenge expired.',['status'=>401]); }
        $request_device = self::request_device($r);
        if (!hash_equals((string) $state['installation_hash'], (string) $request_device['installation_hash'])) { self::proof_failed($gate, 'installation_mismatch'); return self::denied('mobile_biometric_installation_mismatch'); }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::biometric_table().' WHERE id=%d AND '.self::active_key_clause().' LIMIT 1', absint($state['key_id'] ?? 0), self::mysql_at(self::now_ts())));
        if (!$row) { self::proof_failed($gate, 'biometric_not_enrolled'); return new WP_Error('biometric_not_enrolled','La connexion rapide n’est plus active sur ce téléphone.',['status'=>404]); }
        if (!hash_equals((string) $row->installation_hash, (string) $request_device['installation_hash']) || !hash_equals((string) $row->device_hash, (string) $request_device['device_hash'])) { self::proof_failed($gate, 'device_mismatch'); return self::denied('mobile_biometric_device_mismatch'); }
        if (openssl_verify((string) $state['message'], $signature, (string) $row->public_key, OPENSSL_ALGO_SHA256) !== 1) { self::proof_failed($gate, 'biometric_failed'); return self::denied('mobile_biometric_failed'); }
        if (!self::mobile_user_allowed((int) $row->user_id)) return self::denied('mobile_account_not_allowed');
        $limited = self::session_throttle($gate, (int) $row->id, self::SESSION_FLOOD_LIMIT, 'key');
        if ($limited) return $limited;
        $issued = self::issue_for_user((int) $row->user_id, $r, 'biometric');
        if (is_wp_error($issued)) return $issued;
        $now = self::now_ts();
        $wpdb->update(self::biometric_table(), ['last_used_at' => self::mysql_at($now), 'expires_at' => self::mysql_at($now + self::BIOMETRIC_KEY_TTL)], ['id' => (int) $row->id]);
        if (class_exists('DIP_Audit')) {
            DIP_Audit::record('mobile_biometric_login', 'info', (int) $row->user_id, ['key_id' => (int) $row->id]);
            DIP_Audit::record('login_success', 'info', (int) $row->user_id, ['method' => 'mobile_biometric']);
        }
        return $issued;
    }

    /** GET /mobile/biometric: this phone's key and the account's other phones. */
    public static function biometric_status(WP_REST_Request $r) {
        $session = self::current_session_for_request($r);
        if (!$session) return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);
        $uid = (int) $session->user_id;
        $devices = [];
        $here = null;
        if (self::biometric_ready()) {
            global $wpdb;
            $suppress = $wpdb->suppress_errors(true);
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id,installation_hash,label,created_at,last_used_at,expires_at FROM ' . self::biometric_table() . ' WHERE user_id=%d AND ' . self::active_key_clause() . ' ORDER BY id DESC LIMIT 20', $uid, self::mysql_at(self::now_ts())));
            $wpdb->suppress_errors($suppress);
            foreach ((array) $rows as $row) {
                $mine = hash_equals((string) $session->installation_hash, (string) $row->installation_hash);
                if ($mine && $here === null) {
                    $here = ['enrolled' => true, 'key_id' => (int) $row->id, 'created_at' => self::iso_or_null($row->created_at), 'last_used_at' => self::iso_or_null($row->last_used_at), 'expires_at' => self::iso_or_null($row->expires_at)];
                }
                $devices[] = ['id' => (int) $row->id, 'label' => (string) $row->label, 'created_at' => self::iso_or_null($row->created_at), 'last_used_at' => self::iso_or_null($row->last_used_at), 'this_device' => $mine];
            }
        }
        return new WP_REST_Response(['enabled' => self::biometric_available(), 'this_device' => $here, 'devices' => $devices], 200);
    }

    /** DELETE /mobile/biometric: this phone's key, no proof (a way in is removed, none added). */
    public static function biometric_delete_this(WP_REST_Request $r) {
        $session = self::current_session_for_request($r);
        if (!$session) return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);
        $count = self::revoke_keys_where('user_id=%d AND installation_hash=%s', [(int) $session->user_id, (string) $session->installation_hash], 'user');
        return new WP_REST_Response(['removed' => $count > 0, 'count' => $count], 200);
    }

    /** DELETE /mobile/biometric/{id} (session + recent proof): another phone's key. */
    public static function biometric_delete_key(WP_REST_Request $r) {
        $session = self::current_session_for_request($r);
        if (!$session) return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);
        $proof = self::require_recent_proof($session);
        if ($proof) return $proof;
        $count = self::revoke_keys_where('id=%d AND user_id=%d', [absint($r['id']), (int) $session->user_id], 'user_remote');
        if ($count < 1) return new WP_Error('biometric_key_not_found', 'Cette connexion rapide n’existe plus.', ['status' => 404]);
        return new WP_REST_Response(['removed' => true, 'count' => $count], 200);
    }

    public static function cleanup(){
        global $wpdb;
        $wpdb->query('DELETE FROM '.self::table().' WHERE (revoked_at IS NOT NULL AND revoked_at < UTC_TIMESTAMP() - INTERVAL 30 DAY) OR refresh_expires_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        if (self::biometric_ready()) {
            $cutoff = self::mysql_at(self::now_ts() - 30 * DAY_IN_SECONDS);
            $suppress = $wpdb->suppress_errors(true);
            $wpdb->query($wpdb->prepare('DELETE FROM '.self::biometric_table().' WHERE (revoked_at IS NOT NULL AND revoked_at < %s) OR expires_at < %s', $cutoff, $cutoff));
            $wpdb->suppress_errors($suppress);
        }
        DIP_Lockout::purge_counters();
    }
}
