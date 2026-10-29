<?php
defined('ABSPATH') || exit;

/**
 * Delicat App Sync 2.0
 * One-time web/app pairing layered on top of DIP_Mobile_API.
 */
final class DIP_App_Sync_V2 {
    const TABLE_SUFFIX = 'dip_app_pairings';
    const OPTION = 'dip_app_sync_v2_settings';
    const PAIR_TTL = 300;
    // Updates done by replacing the ZIP never run the activation hook: the
    // table is (re)installed once per schema version (autoloaded flag).
    const SCHEMA_OPTION = 'dip_app_pairings_schema';
    const SCHEMA_VERSION = '1';
    // HttpOnly, SameSite=Strict cookie set by /pairing/create. Status and
    // exchange require it next to the page's browser token, so a cross-site
    // form can never complete someone else's pairing in this browser.
    const BIND_COOKIE = 'dip_pair_bind';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 40);
        add_action('admin_post_dip_app_sync_v2_save', [__CLASS__, 'save_settings']);
        add_action('admin_post_dip_app_sync_v2_cleanup', [__CLASS__, 'cleanup_action']);
        add_action('admin_post_dip_app_session_revoke', [__CLASS__, 'handle_session_revoke']);
        add_action('admin_post_dip_app_sessions_revoke_all', [__CLASS__, 'handle_sessions_revoke_all']);
        add_filter('dip_self_service_actions', [__CLASS__, 'self_service_actions']);
        add_filter('dip_mobile_rest_rules', [__CLASS__, 'mobile_rest_rules']);
        add_shortcode('delicat_app_pairing', [__CLASS__, 'shortcode']);
        add_action('dip_app_pairing_cleanup', [__CLASS__, 'cleanup']);
        // Account deletion and privacy erasure: per-user pairing and device rows.
        add_action('deleted_user', [__CLASS__, 'user_deleted']);
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_erasers']);
        if (!wp_next_scheduled('dip_app_pairing_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dip_app_pairing_cleanup');
        }
        if ((string) get_option(self::SCHEMA_OPTION, '') !== self::SCHEMA_VERSION) self::install();
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            pairing_id char(36) NOT NULL,
            secret_hash char(64) NOT NULL,
            browser_hash char(64) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            requested_ip_hash char(64) NOT NULL DEFAULT '',
            requested_ua varchar(255) NOT NULL DEFAULT '',
            approved_device_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            approved_at datetime DEFAULT NULL,
            consumed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY pairing_id (pairing_id),
            KEY status_expires (status, expires_at),
            KEY user_id (user_id)
        ) {$charset};");
        if (false === get_option(self::OPTION, false)) {
            add_option(self::OPTION, self::defaults(), '', false);
        }
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, true);
    }

    public static function defaults() {
        return [
            'enabled' => 'no',
            'allow_web_pairing' => 'yes',
            // Empty = the WooCommerce My Account page (whatever its slug).
            'redirect_after_pairing' => '',
            'button_text' => 'Ouvrir l’app Delicat',
            'deep_link_scheme' => 'delicat',
        ];
    }

    private static function settings() {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    private static function enabled() {
        $settings = self::settings();
        return $settings['enabled'] === 'yes' && $settings['allow_web_pairing'] === 'yes' && is_ssl();
    }

    private static function hash_value($value) {
        return hash_hmac('sha256', (string) $value, wp_salt('secure_auth'));
    }

    private static function token($bytes = 32) {
        try {
            return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
        } catch (Exception $e) {
            return wp_generate_password($bytes * 2, false, false);
        }
    }

    /** String request parameter; arrays/objects are refused without notices. */
    private static function param(WP_REST_Request $request, $name) {
        $value = $request->get_param($name);
        return is_string($value) ? $value : '';
    }

    /** Browser binding: the page's per-tab token AND this browser's Strict bind cookie. */
    private static function browser_hash(WP_REST_Request $request, $bind) {
        $value = self::param($request, 'browser_token');
        if ($bind === '' || !preg_match('/^[A-Za-z0-9\-_]{24,255}$/', $value)) return '';
        return self::hash_value($value . '|' . $bind);
    }

    private static function bind_secret($create = false) {
        $value = isset($_COOKIE[self::BIND_COOKIE]) && is_string($_COOKIE[self::BIND_COOKIE]) ? trim(wp_unslash($_COOKIE[self::BIND_COOKIE])) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', $value)) {
            if (!$create) return '';
            $value = self::token(32);
        }
        if ($create) {
            if (headers_sent()) return '';
            setcookie(self::BIND_COOKIE, $value, [
                'expires' => time() + self::PAIR_TTL + 5 * MINUTE_IN_SECONDS,
                'path' => COOKIEPATH ?: '/',
                'domain' => COOKIE_DOMAIN ?: '',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            $_COOKIE[self::BIND_COOKIE] = $value;
        }
        return $value;
    }

    /**
     * Browser-called routes (create, status, exchange) refuse cross-site
     * requests: a present Origin must be this site, and Sec-Fetch-Site (when
     * the browser sends it) must say same-origin.
     */
    private static function same_origin(WP_REST_Request $request) {
        $fetch_site = strtolower(trim((string) $request->get_header('sec_fetch_site')));
        if ($fetch_site !== '' && !in_array($fetch_site, ['same-origin', 'none'], true)) return false;
        $origin = trim((string) $request->get_header('origin'));
        if ($origin === '') return true;
        $o = wp_parse_url($origin);
        if (!is_array($o) || empty($o['host'])) return false;
        foreach ([home_url('/'), site_url('/')] as $url) {
            $h = wp_parse_url($url);
            if (is_array($h) && !empty($h['host']) && strtolower($h['host']) === strtolower($o['host']) && self::port($h) === self::port($o)) return true;
        }
        return false;
    }

    private static function port(array $parts) {
        if (!empty($parts['port'])) return (int) $parts['port'];
        return strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 80 : 443;
    }

    private static function ip_hash() {
        $ip = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::client_ip() : (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '');
        return $ip ? self::hash_value($ip) : '';
    }

    /**
     * 6.11.0 (owner rule of 2026-10-24). The per-address count used to live
     * in a transient rewritten with a full TTL on every hit (with LiteSpeed,
     * in the object cache), so a busy carrier address never left its window:
     * the pattern behind the 2.32.2 « Trop de tentatives » incident. It now
     * counts in Identity Pro's counter table (DIP_Lockout::counter_hit(),
     * fixed window, never the object cache), on the visitor address behind
     * Cloudflare (throttle_ip(), like the mobile routes), at 20 times the old
     * figure (SHARED_ADDRESS): many customers share one carrier address.
     * Pairing secrets are random, so these are flood ceilings, not guessing
     * guards.
     */
    const SHARED_ADDRESS = 20;

    private static function rate_key($bucket) {
        $ip = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::throttle_ip() : (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '');
        return 'dip_pair_' . sanitize_key($bucket) . '_' . substr(self::hash_value($ip), 0, 32);
    }

    private static function rate_limit($bucket, $max = 12, $window = 600) {
        return DIP_Lockout::counter_hit(self::rate_key($bucket), max(1, (int) $window)) <= max(1, (int) $max) * self::SHARED_ADDRESS;
    }

    public static function routes() {
        register_rest_route('delicat-identity/v1', '/pairing/create', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_pairing'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('delicat-identity/v1', '/pairing/status', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'pairing_status'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('delicat-identity/v1', '/pairing/preview', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'preview_pairing'],
            'permission_callback' => [__CLASS__, 'logged_in'],
        ]);
        register_rest_route('delicat-identity/v1', '/pairing/approve', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'approve_pairing'],
            'permission_callback' => [__CLASS__, 'logged_in'],
        ]);
        register_rest_route('delicat-identity/v1', '/pairing/exchange', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'exchange_pairing'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** The strict REST firewall classes /pairing/preview with the other device-bound app routes. */
    public static function mobile_rest_rules($rules) {
        $rules = is_array($rules) ? $rules : [];
        $rules[] = ['#^/delicat-identity/v1/pairing/preview$#', ['POST']];
        return $rules;
    }

    public static function logged_in($request = null) {
        return class_exists('DIP_Mobile_API') && DIP_Mobile_API::logged_in($request instanceof WP_REST_Request ? $request : null);
    }

    public static function create_pairing(WP_REST_Request $request) {
        if (!self::enabled()) return new WP_Error('pairing_disabled', 'La connexion avec l’app est désactivée ou HTTPS est indisponible.', ['status' => 403]);
        if (!self::same_origin($request)) return new WP_Error('pairing_origin_refused', 'Demande refusée : elle doit venir de ce site.', ['status' => 403]);
        if (!self::rate_limit('create')) return new WP_Error('rate_limited', 'Trop de demandes. Réessayez plus tard.', ['status' => 429]);
        $browser_hash = self::browser_hash($request, self::bind_secret(true));
        if (!$browser_hash) return new WP_Error('invalid_browser', 'Session navigateur invalide. Activez les cookies puis réessayez.', ['status' => 400]);

        global $wpdb;
        $pairing_id = wp_generate_uuid4();
        $secret = self::token(32);
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + self::PAIR_TTL);
        $inserted = $wpdb->insert(self::table(), [
            'pairing_id' => $pairing_id,
            'secret_hash' => self::hash_value($secret),
            'browser_hash' => $browser_hash,
            'status' => 'pending',
            'requested_ip_hash' => self::ip_hash(),
            'requested_ua' => substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
            'created_at' => $now,
            'expires_at' => $expires,
        ]);
        if (!$inserted) return new WP_Error('pairing_create_failed', 'Impossible de créer la demande.', ['status' => 500]);

        $settings = self::settings();
        $scheme = strtolower(trim((string) $settings['deep_link_scheme']));
        if (!preg_match('/^[a-z][a-z0-9+.-]{1,31}$/', $scheme)) $scheme = 'delicat';
        $deep_link = $scheme . '://identity/pair?pairing_id=' . rawurlencode($pairing_id) . '&secret=' . rawurlencode($secret);
        // No audit row per anonymous create (each poll-able request would
        // flood the security log): approvals and sign-ins are audited.
        return self::response([
            'pairing_id' => $pairing_id,
            'secret' => $secret,
            'deep_link' => $deep_link,
            'expires_in' => self::PAIR_TTL,
            'display_code' => self::display_code($pairing_id),
            'qr' => self::qr_payload($deep_link),
        ]);
    }

    private static function display_code($pairing_id) {
        return strtoupper(substr(str_replace('-', '', (string) $pairing_id), 0, 8));
    }

    private static function locate(WP_REST_Request $request, $require_browser = false) {
        global $wpdb;
        $pairing_id = sanitize_text_field(self::param($request, 'pairing_id'));
        $secret = self::param($request, 'secret');
        if (!wp_is_uuid($pairing_id) || !preg_match('/^[A-Za-z0-9\-_]{24,255}$/', $secret)) return null;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE pairing_id=%s LIMIT 1', $pairing_id));
        if (!$row || !hash_equals((string) $row->secret_hash, self::hash_value($secret))) return null;
        if ($require_browser) {
            $browser_hash = self::browser_hash($request, self::bind_secret(false));
            if (!$browser_hash || !hash_equals((string) $row->browser_hash, $browser_hash)) return null;
        }
        return $row;
    }

    private static function is_expired($row) {
        return strtotime($row->expires_at . ' UTC') < time();
    }

    public static function pairing_status(WP_REST_Request $request) {
        if (!self::enabled()) return new WP_Error('pairing_disabled', 'Connexion avec l’app désactivée.', ['status' => 403]);
        if (!self::same_origin($request)) return new WP_Error('pairing_origin_refused', 'Demande refusée : elle doit venir de ce site.', ['status' => 403]);
        // Polling a live pairing is normal use and never counts: only failed
        // lookups (unknown pairing, secret or browser) fill the address bucket,
        // and one pairing has its own flood ceiling far above its 5 minutes.
        $failures = self::rate_key('status_fail');
        if (DIP_Lockout::counter_peek($failures)['count'] >= 220 * self::SHARED_ADDRESS) return new WP_Error('rate_limited', 'Trop de demandes.', ['status' => 429]);
        $row = self::locate($request, true);
        if (!$row) {
            DIP_Lockout::counter_hit($failures, 600);
            return new WP_Error('pairing_not_found', 'Demande introuvable. Recommencez la connexion.', ['status' => 404]);
        }
        if (DIP_Lockout::counter_hit('dip_pair_poll_' . (int) $row->id, self::PAIR_TTL + 120) > 400) return new WP_Error('rate_limited', 'Trop de demandes.', ['status' => 429]);
        if (self::is_expired($row) && $row->status === 'pending') {
            global $wpdb;
            $wpdb->update(self::table(), ['status' => 'expired'], ['id' => (int) $row->id]);
            $row->status = 'expired';
        }
        return self::response(['status' => sanitize_key($row->status), 'expires_at' => mysql_to_rfc3339($row->expires_at)]);
    }

    /**
     * POST /pairing/preview (app, device-bound Bearer session): what the app
     * shows before the customer approves — which browser asks, from where and
     * when, and the code the web page displays — so a link received from
     * someone else is recognisable as such.
     */
    public static function preview_pairing(WP_REST_Request $request) {
        if (!self::enabled()) return new WP_Error('pairing_disabled', 'Connexion avec l’app désactivée.', ['status' => 403]);
        if (!self::rate_limit('preview', 60, 600)) return new WP_Error('rate_limited', 'Trop de demandes.', ['status' => 429]);
        $session = class_exists('DIP_Mobile_API') ? DIP_Mobile_API::current_session_for_request($request) : null;
        if (!$session || (int) $session->user_id !== get_current_user_id()) return new WP_Error('mobile_session_required', 'Une session mobile Delicat valide est obligatoire.', ['status' => 401]);
        $row = self::locate($request, false);
        if (!$row) return new WP_Error('pairing_invalid', 'Demande invalide ou déjà utilisée.', ['status' => 404]);
        $status = sanitize_key($row->status);
        if ($status === 'pending' && self::is_expired($row)) $status = 'expired';
        $created = strtotime($row->created_at . ' UTC');
        $device = class_exists('DIP_Devices') && method_exists('DIP_Devices', 'describe_user_agent')
            ? DIP_Devices::describe_user_agent((string) $row->requested_ua)
            : ['browser' => 'Navigateur', 'platform' => 'Appareil'];
        $same_network = $row->requested_ip_hash !== '' && hash_equals((string) $row->requested_ip_hash, self::ip_hash());
        return self::response([
            'status' => $status,
            'display_code' => self::display_code($row->pairing_id),
            'browser' => $device['browser'],
            'platform' => $device['platform'],
            'requested_at' => gmdate('c', $created),
            'age_seconds' => max(0, time() - $created),
            'expires_in' => max(0, strtotime($row->expires_at . ' UTC') - time()),
            'same_network' => $same_network,
            'site' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            'requires_recent_proof' => class_exists('DIP_Mobile_API') && method_exists('DIP_Mobile_API', 'recently_proven') ? !DIP_Mobile_API::recently_proven((int) $session->id) : false,
            'message' => sprintf('Connexion demandée depuis %1$s · %2$s, il y a %3$s. Approuvez seulement si vous êtes devant ce navigateur et qu’il affiche le code %4$s.', $device['browser'], $device['platform'], human_time_diff($created, time()), self::display_code($row->pairing_id)),
        ]);
    }

    public static function approve_pairing(WP_REST_Request $request) {
        if (!self::enabled()) return new WP_Error('pairing_disabled', 'Connexion avec l’app désactivée.', ['status' => 403]);
        if (!self::rate_limit('approve', 20, 600)) return new WP_Error('rate_limited', 'Trop de demandes.', ['status' => 429]);
        $row = self::locate($request, false);
        if (!$row || $row->status !== 'pending') return new WP_Error('pairing_invalid', 'Demande invalide ou déjà utilisée.', ['status' => 409]);
        if (self::is_expired($row)) return new WP_Error('pairing_expired', 'La demande a expiré.', ['status' => 410]);

        $session = class_exists('DIP_Mobile_API') ? DIP_Mobile_API::current_session_for_request($request) : null;
        if (!$session || (int) $session->user_id !== get_current_user_id()) return new WP_Error('mobile_session_required', 'Une session mobile Delicat valide est obligatoire.', ['status' => 401]);
        $session_id = (int) $session->id;
        if (class_exists('DIP_Account_Sync')) {
            if (DIP_Account_Sync::privileged_mobile_blocked(get_current_user_id())) return new WP_Error('pairing_role_blocked', 'Ce type de compte ne peut pas approuver un appairage mobile.', ['status' => 403]);
            $guard = DIP_Account_Sync::login_guard(get_current_user_id());
            if (is_wp_error($guard)) return new WP_Error($guard->get_error_code(), $guard->get_error_message(), ['status'=>403]);
        }
        // Approving signs a browser in for 14 days (and, for a 2FA account,
        // stands in for the second factor): like enrolling a credential, it
        // needs an app session that proved itself in the last minutes.
        // Otherwise 403 reauth_required with data.methods, as for enroll.
        if (class_exists('DIP_Mobile_API') && method_exists('DIP_Mobile_API', 'require_recent_proof')) {
            $proof = DIP_Mobile_API::require_recent_proof($session);
            if ($proof) return $proof;
        }
        global $wpdb;
        $updated = $wpdb->update(self::table(), [
            'user_id' => get_current_user_id(),
            'status' => 'approved',
            'approved_device_id' => $session_id,
            'approved_at' => current_time('mysql', true),
        ], ['id' => (int) $row->id, 'status' => 'pending']);
        if (!$updated) return new WP_Error('pairing_approve_failed', 'La demande n’a pas pu être approuvée.', ['status' => 409]);
        if (class_exists('DIP_Audit')) DIP_Audit::record('app_pairing_approved', 'info', get_current_user_id(), ['session_id'=>$session_id]);
        do_action('dip_app_pairing_approved', get_current_user_id(), $row->pairing_id);
        return self::response(['approved' => true]);
    }

    public static function exchange_pairing(WP_REST_Request $request) {
        if (!self::enabled()) return new WP_Error('pairing_disabled', 'Connexion avec l’app désactivée.', ['status' => 403]);
        if (!self::same_origin($request)) return new WP_Error('pairing_origin_refused', 'Demande refusée : elle doit venir de ce site.', ['status' => 403]);
        if (!self::rate_limit('exchange', 30, 600)) return new WP_Error('rate_limited', 'Trop de demandes.', ['status' => 429]);
        $row = self::locate($request, true);
        if (!$row || $row->status !== 'approved' || !$row->user_id) return new WP_Error('pairing_not_ready', 'La demande n’est pas encore approuvée.', ['status' => 409]);
        if (self::is_expired($row)) return new WP_Error('pairing_expired', 'La demande a expiré.', ['status' => 410]);

        $user = get_userdata((int) $row->user_id);
        if (!$user) return new WP_Error('user_not_found', 'Compte introuvable.', ['status' => 404]);
        if (class_exists('DIP_Account_Sync')) {
            if (DIP_Account_Sync::privileged_mobile_blocked($user->ID)) return new WP_Error('pairing_role_blocked', 'Ce type de compte ne peut pas être connecté par appairage.', ['status'=>403]);
            $guard = DIP_Account_Sync::login_guard($user->ID);
            if (is_wp_error($guard)) return new WP_Error($guard->get_error_code(), $guard->get_error_message(), ['status'=>403]);
        }
        // The app session that approved must still be live at exchange time,
        // for every account: « déconnecter les autres », a password reset,
        // 2FA on/off or removing that phone between the approval and this
        // call cancels the browser sign-in.
        $approved_session_id = absint($row->approved_device_id ?? 0);
        if (!class_exists('DIP_Mobile_API') || !DIP_Mobile_API::session_is_active($approved_session_id, $user->ID)) {
            $two_factor = class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user->ID);
            if (class_exists('DIP_Audit')) DIP_Audit::record($two_factor ? 'app_pairing_blocked_stale_2fa_session' : 'app_pairing_blocked_stale_session', 'warning', $user->ID);
            return $two_factor
                ? new WP_Error('two_factor_mobile_session_required', 'La session mobile ayant approuvé cette connexion doit être réauthentifiée.', ['status'=>401])
                : new WP_Error('approving_session_inactive', 'La session de l’app qui a approuvé cette connexion n’est plus active. Recommencez depuis l’app.', ['status'=>401]);
        }

        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status='consumed', consumed_at=%s WHERE id=%d AND status='approved'",
            current_time('mysql', true), (int) $row->id
        ));
        if (!$updated) return new WP_Error('pairing_consumed', 'Cette demande a déjà été utilisée.', ['status' => 409]);

        wp_set_current_user($user->ID);
        if (class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user->ID)) {
            DIP_Two_Factor::authorize_cookie_once($user->ID);
        }
        wp_set_auth_cookie($user->ID, true, is_ssl());
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::fire_wp_login($user, 'delicat_app_pairing');
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_login($user->ID);
        if (class_exists('DIP_Audit')) DIP_Audit::record('app_pairing_consumed', 'info', $user->ID);
        do_action('dip_login_success', $user->ID, ['provider' => 'delicat_app_pairing']);

        return self::response(['authenticated' => true, 'redirect' => self::redirect_target()]);
    }

    private static function account_url() {
        if (function_exists('wc_get_page_permalink')) {
            $url = wc_get_page_permalink('myaccount');
            if (is_string($url) && $url !== '') return $url;
        }
        return home_url('/');
    }

    /** Configured destination, or the real My Account page (/mon-compte/ …) when empty or left at the old '/my-account/' default. */
    private static function redirect_target() {
        $settings = self::settings();
        $target = trim((string) $settings['redirect_after_pairing']);
        if ($target === '' || untrailingslashit($target) === '/my-account') return self::account_url();
        if (strpos($target, '/') === 0 && strpos($target, '//') !== 0) $target = home_url($target);
        return wp_validate_redirect($target, self::account_url());
    }

    private static function response(array $data, $status = 200) {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    // ------------------------------------------------------------------
    // Account area: the customer's phones (logged-in view of the shortcode).
    // ------------------------------------------------------------------

    public static function self_service_actions($actions) {
        $actions = is_array($actions) ? $actions : [];
        $actions[] = 'dip_app_session_revoke';
        $actions[] = 'dip_app_sessions_revoke_all';
        return array_values(array_unique($actions));
    }

    /** Live app sessions grouped per phone installation (most recent first). */
    private static function phones($user_id) {
        global $wpdb;
        if (!class_exists('DIP_Mobile_API')) return [];
        $table = $wpdb->prefix . DIP_Mobile_API::TABLE_SUFFIX;
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, installation_hash, device_name, platform, app_version, created_at, last_used_at FROM {$table} WHERE user_id=%d AND revoked_at IS NULL AND refresh_expires_at > %s ORDER BY last_used_at DESC LIMIT 40",
            absint($user_id), gmdate('Y-m-d H:i:s')
        ));
        $wpdb->suppress_errors($suppress);
        $keyed = method_exists('DIP_Mobile_API', 'biometric_installations') ? array_flip(DIP_Mobile_API::biometric_installations($user_id)) : [];
        $phones = [];
        foreach ((array) $rows as $row) {
            $install = (string) $row->installation_hash;
            if ($install === '' || isset($phones[$install])) continue;
            $phones[$install] = [
                'id' => (int) $row->id,
                'name' => trim((string) $row->device_name),
                'platform' => sanitize_key((string) $row->platform),
                'version' => trim((string) $row->app_version),
                'since' => strtotime($row->created_at . ' UTC'),
                'last' => strtotime($row->last_used_at . ' UTC'),
                'quick' => isset($keyed[$install]),
            ];
        }
        return array_values(array_slice($phones, 0, 20));
    }

    private static function store_url() {
        $url = '';
        if (class_exists('DIP_Plugin') && class_exists('DIP_Android_App') && method_exists('DIP_Android_App', 'valid_package')) {
            $s = wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
            $package = DIP_Android_App::valid_package($s['mobile_android_package'] ?? '');
            if ($package !== '') $url = 'https://play.google.com/store/apps/details?id=' . rawurlencode($package);
        }
        $url = (string) apply_filters('dip_app_store_url', $url);
        return wp_http_validate_url($url) ? $url : '';
    }

    public static function render_account_panel($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id || $user_id !== get_current_user_id()) return '';
        $api = class_exists('DIP_Mobile_API') && DIP_Mobile_API::enabled();
        $phones = $api ? self::phones($user_id) : [];
        $counts = ($api && method_exists('DIP_Mobile_API', 'credential_counts')) ? DIP_Mobile_API::credential_counts($user_id) : ['passkeys' => 0, 'biometric' => 0];
        $quick = $api && method_exists('DIP_Mobile_API', 'biometric_available') && DIP_Mobile_API::biometric_available();
        $store = self::store_url();
        $status = sanitize_key(wp_unslash($_GET['dip_app_status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
        $messages = [
            'revoked' => ['ok', 'Le téléphone a été déconnecté. Sa connexion rapide est désactivée.'],
            'revoked_all' => ['ok', 'Tous les téléphones ont été déconnectés de votre compte.'],
            'not_found' => ['error', 'Ce téléphone n’est plus connecté.'],
        ];
        $platforms = ['android' => 'Android', 'ios' => 'iPhone', 'iphone' => 'iPhone', 'ipad' => 'iPad'];
        $action = admin_url('admin-post.php');
        ob_start();
        ?>
        <section class="dip-app-panel" id="dip-app-panel" aria-labelledby="dip-app-panel-title">
          <header class="dip-app-panel__head">
            <span class="dip-app-panel__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M10.5 18.5h3"/></svg></span>
            <div><h3 id="dip-app-panel-title">Application Delicat</h3><p><?php echo $api ? esc_html__('Les téléphones connectés à votre compte, et leur connexion rapide.', 'delicat-google-login') : esc_html__('L’application Delicat n’est pas encore disponible pour les comptes du site.', 'delicat-google-login'); ?></p></div>
          </header>
          <?php if (isset($messages[$status])): ?><p class="dip-app-panel__notice is-<?php echo esc_attr($messages[$status][0]); ?>" role="<?php echo $messages[$status][0] === 'error' ? 'alert' : 'status'; ?>"><?php echo esc_html($messages[$status][1]); ?></p><?php endif; ?>
          <?php if ($api): ?>
          <div class="dip-app-panel__stats">
            <div><strong><?php echo esc_html(count($phones)); ?></strong><span><?php echo esc_html(_n('téléphone connecté', 'téléphones connectés', max(1, count($phones)), 'delicat-google-login')); ?></span></div>
            <?php if ($quick): ?><div><strong><?php echo esc_html((int) $counts['biometric']); ?></strong><span><?php esc_html_e('connexion rapide par empreinte', 'delicat-google-login'); ?></span></div><?php endif; ?>
            <div><strong><?php echo esc_html((int) $counts['passkeys']); ?></strong><span><?php echo esc_html(_n('clé d’accès', 'clés d’accès', max(1, (int) $counts['passkeys']), 'delicat-google-login')); ?></span></div>
          </div>
          <?php if ($phones): ?>
          <ul class="dip-app-panel__list">
            <?php foreach ($phones as $phone):
              $label = $phone['name'] !== '' ? $phone['name'] : __('Téléphone', 'delicat-google-login');
              $meta = array_filter([
                  $platforms[$phone['platform']] ?? '',
                  $phone['version'] !== '' ? sprintf(__('app %s', 'delicat-google-login'), $phone['version']) : '',
                  $phone['last'] ? sprintf(__('actif il y a %s', 'delicat-google-login'), human_time_diff($phone['last'], time())) : '',
              ]); ?>
            <li>
              <div class="dip-app-panel__device"><strong><?php echo esc_html($label); ?></strong><span><?php echo esc_html(implode(' · ', $meta)); ?></span><?php if ($phone['quick']): ?><em><?php esc_html_e('Connexion rapide active', 'delicat-google-login'); ?></em><?php endif; ?></div>
              <form method="post" action="<?php echo esc_url($action); ?>" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Déconnecter ce téléphone ? Il devra se reconnecter et sa connexion rapide sera désactivée.', 'delicat-google-login'))); ?>)">
                <input type="hidden" name="action" value="dip_app_session_revoke"><input type="hidden" name="session_id" value="<?php echo esc_attr($phone['id']); ?>">
                <?php wp_nonce_field('dip_app_session_revoke_' . $user_id . '_' . $phone['id'], '_wpnonce', false); ?>
                <button type="submit" class="dip-app-panel__danger"><?php esc_html_e('Déconnecter', 'delicat-google-login'); ?></button>
              </form>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($phones) > 1): ?>
          <form method="post" action="<?php echo esc_url($action); ?>" class="dip-app-panel__all" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Déconnecter tous les téléphones de votre compte ?', 'delicat-google-login'))); ?>)">
            <input type="hidden" name="action" value="dip_app_sessions_revoke_all"><?php wp_nonce_field('dip_app_sessions_revoke_all_' . $user_id, '_wpnonce', false); ?>
            <button type="submit" class="dip-app-panel__danger"><?php esc_html_e('Déconnecter tous les téléphones', 'delicat-google-login'); ?></button>
          </form>
          <?php endif; ?>
          <?php else: ?>
          <p class="dip-app-panel__empty"><?php esc_html_e('Aucun téléphone n’est connecté à votre compte pour le moment.', 'delicat-google-login'); ?></p>
          <?php endif; ?>
          <div class="dip-app-panel__how">
            <h4><?php esc_html_e('Se connecter dans l’app', 'delicat-google-login'); ?></h4>
            <ol>
              <li><?php if ($store): ?><a href="<?php echo esc_url($store); ?>" target="_blank" rel="noopener"><?php esc_html_e('Installez l’app Delicat', 'delicat-google-login'); ?></a><?php else: esc_html_e('Installez l’app Delicat sur votre téléphone.', 'delicat-google-login'); endif; ?></li>
              <li><?php esc_html_e('Connectez-vous avec la même adresse e-mail, avec Google ou avec une clé d’accès.', 'delicat-google-login'); ?></li>
              <?php if ($quick): ?><li><?php esc_html_e('Activez la connexion rapide par empreinte dans les réglages de l’app.', 'delicat-google-login'); ?></li><?php endif; ?>
            </ol>
            <p><?php esc_html_e('Un téléphone perdu ? Déconnectez-le ici : il ne pourra plus ouvrir votre compte sans se reconnecter.', 'delicat-google-login'); ?></p>
          </div>
          <?php endif; ?>
        </section>
        <style>
        .dip-app-panel{--dap-ink:#0f172a;--dap-muted:#64748b;--dap-line:rgba(15,23,42,.10);--dap-soft:#f8fafc;--dap-accent:#1d4ed8;max-width:720px;padding:24px;border:1px solid var(--dap-line);border-radius:22px;background:#fff;color:var(--dap-ink);box-shadow:0 18px 50px rgba(15,23,42,.08)}
        .dip-app-panel__head{display:flex;gap:14px;align-items:flex-start;margin-bottom:16px}.dip-app-panel__head h3{margin:0 0 4px;font-size:20px;line-height:1.25}.dip-app-panel__head p{margin:0;color:var(--dap-muted)}
        .dip-app-panel__icon{flex:0 0 46px;width:46px;height:46px;border-radius:14px;display:grid;place-items:center;background:linear-gradient(135deg,#1d4ed8,#6d28d9);color:#fff}
        .dip-app-panel__notice{margin:0 0 14px;padding:11px 14px;border-radius:12px;background:#ecfdf5;color:#065f46}.dip-app-panel__notice.is-error{background:#fef2f2;color:#991b1b}
        .dip-app-panel__stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin:0 0 16px}.dip-app-panel__stats div{padding:14px;border-radius:16px;background:var(--dap-soft)}.dip-app-panel__stats strong{display:block;font-size:24px;line-height:1.1}.dip-app-panel__stats span{color:var(--dap-muted);font-size:13px}
        .dip-app-panel__list{list-style:none;margin:0 0 12px;padding:0;display:grid;gap:10px}.dip-app-panel__list li{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:14px;border:1px solid var(--dap-line);border-radius:16px}
        .dip-app-panel__device{display:grid;gap:3px;min-width:0}.dip-app-panel__device strong{overflow-wrap:anywhere}.dip-app-panel__device span{color:var(--dap-muted);font-size:13px}.dip-app-panel__device em{justify-self:start;font-style:normal;font-size:12px;font-weight:700;padding:3px 9px;border-radius:999px;background:#eef2ff;color:#3730a3}
        .dip-app-panel form{margin:0}.dip-app-panel__danger{min-height:42px;padding:0 16px;border:1px solid #fecaca;border-radius:12px;background:#fff;color:#b91c1c;font-weight:700;cursor:pointer}.dip-app-panel__danger:hover{background:#fef2f2}.dip-app-panel__danger:focus-visible{outline:3px solid rgba(220,38,38,.25);outline-offset:2px}
        .dip-app-panel__all{margin:0 0 16px!important}.dip-app-panel__empty{margin:0 0 16px;padding:14px;border-radius:16px;background:var(--dap-soft);color:var(--dap-muted)}
        .dip-app-panel__how{padding:16px;border-radius:16px;background:var(--dap-soft)}.dip-app-panel__how h4{margin:0 0 8px;font-size:15px}.dip-app-panel__how ol{margin:0 0 8px;padding-left:20px;display:grid;gap:4px}.dip-app-panel__how p{margin:0;color:var(--dap-muted);font-size:13px}.dip-app-panel__how a{color:var(--dap-accent);font-weight:700}
        @media(max-width:560px){.dip-app-panel{padding:18px 16px;border-radius:18px}.dip-app-panel__list li{flex-direction:column;align-items:stretch}.dip-app-panel__danger{width:100%}}
        </style>
        <?php
        return ob_get_clean();
    }

    private static function back_to_panel($status) {
        $back = wp_get_referer();
        $back = $back ? remove_query_arg(['dip_app_status'], $back) : self::account_url();
        wp_safe_redirect(add_query_arg('dip_app_status', sanitize_key($status), $back) . '#dip-app-panel');
        exit;
    }

    private static function require_post_login() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        if (!is_user_logged_in()) wp_die(esc_html__('Connexion requise.', 'delicat-google-login'), '', ['response' => 403]);
        return get_current_user_id();
    }

    /** Sign one phone out of the account: all its sessions, and its quick sign-in key. */
    public static function handle_session_revoke() {
        $uid = self::require_post_login();
        $sid = absint($_POST['session_id'] ?? 0);
        check_admin_referer('dip_app_session_revoke_' . $uid . '_' . $sid);
        global $wpdb;
        $table = class_exists('DIP_Mobile_API') ? $wpdb->prefix . DIP_Mobile_API::TABLE_SUFFIX : '';
        $row = ($table && $sid) ? $wpdb->get_row($wpdb->prepare("SELECT id, installation_hash FROM {$table} WHERE id=%d AND user_id=%d AND revoked_at IS NULL LIMIT 1", $sid, $uid)) : null;
        if (!$row) self::back_to_panel('not_found');
        $install = (string) $row->installation_hash;
        $count = $install !== ''
            ? $wpdb->query($wpdb->prepare("UPDATE {$table} SET revoked_at=%s WHERE user_id=%d AND installation_hash=%s AND revoked_at IS NULL", gmdate('Y-m-d H:i:s'), $uid, $install))
            : $wpdb->query($wpdb->prepare("UPDATE {$table} SET revoked_at=%s WHERE id=%d AND user_id=%d", gmdate('Y-m-d H:i:s'), $sid, $uid));
        $keys = ($install !== '' && method_exists('DIP_Mobile_API', 'revoke_installation_keys')) ? DIP_Mobile_API::revoke_installation_keys($uid, $install, 'web_revoke') : 0;
        if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_session_revoked_from_web', 'warning', $uid, ['sessions'=>(int) $count, 'keys'=>(int) $keys]);
        do_action('dip_mobile_sessions_revoked', $uid, $install, 'session', 'web_revoke');
        self::back_to_panel('revoked');
    }

    public static function handle_sessions_revoke_all() {
        $uid = self::require_post_login();
        check_admin_referer('dip_app_sessions_revoke_all_' . $uid);
        if (class_exists('DIP_Mobile_API')) DIP_Mobile_API::revoke_all_for_user($uid, 'web_revoke_all');
        self::back_to_panel('revoked_all');
    }

    // ------------------------------------------------------------------
    // Account deletion / privacy erasure.
    // ------------------------------------------------------------------

    public static function user_deleted($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        if (!$user_id) return;
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->delete(self::table(), ['user_id' => $user_id], ['%d']);
        $wpdb->suppress_errors($suppress);
        if (class_exists('DIP_Devices') && method_exists('DIP_Devices', 'delete_for_user')) DIP_Devices::delete_for_user($user_id);
    }

    public static function register_erasers($erasers) {
        $erasers = is_array($erasers) ? $erasers : [];
        $erasers['delicat-identity-devices'] = [
            'eraser_friendly_name' => __('Delicat Identity — appareils et appairages', 'delicat-google-login'),
            'callback' => [__CLASS__, 'erase_personal_data'],
        ];
        return $erasers;
    }

    public static function erase_personal_data($email_address, $page = 1) {
        global $wpdb;
        $user = get_user_by('email', sanitize_email((string) $email_address));
        $removed = 0;
        if ($user) {
            $suppress = $wpdb->suppress_errors(true);
            $removed += (int) $wpdb->delete(self::table(), ['user_id' => (int) $user->ID], ['%d']);
            $wpdb->suppress_errors($suppress);
            if (class_exists('DIP_Devices') && method_exists('DIP_Devices', 'delete_for_user')) $removed += (int) DIP_Devices::delete_for_user($user->ID);
        }
        return ['items_removed' => $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    // ------------------------------------------------------------------
    // Shortcode: logged-out pairing widget, logged-in phones panel.
    // ------------------------------------------------------------------

    public static function shortcode($atts = []) {
        if (is_user_logged_in()) return self::render_account_panel(get_current_user_id());
        $settings = self::settings();
        if (!self::enabled()) {
            if (!current_user_can('manage_options')) return '';
            if ($settings['enabled'] !== 'yes' || $settings['allow_web_pairing'] !== 'yes') return '<div class="dip-pairing-notice">Activez App Sync 2.0 et la connexion web/app dans Réglages → Identity App Sync.</div>';
            return '<div class="dip-pairing-notice">HTTPS est obligatoire pour App Sync 2.0.</div>';
        }
        $id = 'dip-pairing-' . wp_generate_password(8, false, false);
        $rest = esc_url_raw(rest_url('delicat-identity/v1/pairing/'));
        $script = self::widget_script_html();
        ob_start();
        ?>
        <div id="<?php echo esc_attr($id); ?>" class="dip-app-pairing" data-rest="<?php echo esc_attr($rest); ?>">
            <div class="dip-app-pairing__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M10.5 18.5h3"/></svg></div>
            <h3>Connexion avec l’app Delicat</h3>
            <p class="dip-app-pairing__lead">Connectez ce navigateur en approuvant la demande depuis l’app Delicat sur votre téléphone.</p>
            <button type="button" class="dip-app-pairing__start"><?php echo esc_html($settings['button_text']); ?></button>
            <div class="dip-app-pairing__session" hidden>
                <div class="dip-app-pairing__qr" hidden></div>
                <p class="dip-app-pairing__hint"></p>
                <p class="dip-app-pairing__label">Code de vérification</p><strong class="dip-app-pairing__code">—</strong>
                <small class="dip-app-pairing__compare">L’app doit afficher le même code. Sinon, refusez la demande.</small>
                <a class="dip-app-pairing__open" href="#" hidden>Ouvrir dans l’app</a>
            </div>
            <p class="dip-app-pairing__status" role="status" aria-live="polite"></p>
        </div>
        <style>
        #<?php echo esc_html($id); ?>{max-width:460px;padding:28px;border:1px solid rgba(15,23,42,.12);border-radius:22px;background:#fff;box-shadow:0 18px 55px rgba(15,23,42,.10);text-align:center;color:#0f172a}#<?php echo esc_html($id); ?> [hidden]{display:none!important}#<?php echo esc_html($id); ?> .dip-app-pairing__icon{width:54px;height:54px;margin:0 auto 14px;border-radius:17px;display:grid;place-items:center;background:linear-gradient(135deg,#0f2b5b,#1d4ed8);color:#fff}#<?php echo esc_html($id); ?> h3{margin:0 0 8px;font-size:22px}#<?php echo esc_html($id); ?> .dip-app-pairing__lead{margin:0 0 20px;color:#64748b}#<?php echo esc_html($id); ?> button,#<?php echo esc_html($id); ?> .dip-app-pairing__open{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 20px;border:0;border-radius:14px;background:#0f2b5b;color:#fff;font-weight:700;text-decoration:none;cursor:pointer}#<?php echo esc_html($id); ?> button[disabled]{opacity:.6;cursor:progress}#<?php echo esc_html($id); ?> .dip-app-pairing__session{margin-top:20px;padding:18px;border-radius:16px;background:#f8fafc}#<?php echo esc_html($id); ?> .dip-app-pairing__qr{display:flex;justify-content:center;margin:0 auto 12px;line-height:0}#<?php echo esc_html($id); ?> .dip-app-pairing__qr svg{padding:8px;border-radius:14px;background:#fff;border:1px solid #e2e8f0;max-width:100%;height:auto}#<?php echo esc_html($id); ?> .dip-app-pairing__hint{margin:0 0 12px;color:#334155}#<?php echo esc_html($id); ?> .dip-app-pairing__label{margin:0 0 5px;color:#64748b}#<?php echo esc_html($id); ?> .dip-app-pairing__code{display:block;font-size:30px;letter-spacing:.16em;margin:6px 0 6px;font-variant-numeric:tabular-nums}#<?php echo esc_html($id); ?> .dip-app-pairing__compare{display:block;margin:0 0 14px;color:#64748b;font-size:13px}#<?php echo esc_html($id); ?> .dip-app-pairing__status{min-height:1.4em;margin:14px 0 0;color:#64748b;font-size:14px}#<?php echo esc_html($id); ?> .dip-app-pairing__status[data-kind="error"]{color:#b91c1c}#<?php echo esc_html($id); ?> .dip-app-pairing__status[data-kind="ok"]{color:#047857}#<?php echo esc_html($id); ?> button:focus-visible,#<?php echo esc_html($id); ?> a:focus-visible{outline:3px solid rgba(37,99,235,.28);outline-offset:3px}@media(max-width:520px){#<?php echo esc_html($id); ?>{padding:20px 16px;border-radius:18px}#<?php echo esc_html($id); ?> .dip-app-pairing__code{font-size:25px;letter-spacing:.11em;overflow-wrap:anywhere}#<?php echo esc_html($id); ?> button,#<?php echo esc_html($id); ?> .dip-app-pairing__open{width:100%}}
        </style>
        <?php
        return ob_get_clean() . $script;
    }

    private static $widget_script_queued = false;

    /**
     * The widget script is printed once, in the footer, outside the_content:
     * content filters would otherwise rewrite characters such as « && ».
     * Rendered after wp_footer (or in an AJAX/REST fragment), it is returned
     * for the caller to print in place.
     */
    private static function widget_script_html() {
        if (self::$widget_script_queued) return '';
        self::$widget_script_queued = true;
        if (did_action('wp_footer') || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            ob_start();
            self::print_widget_script();
            return (string) ob_get_clean();
        }
        add_action('wp_footer', [__CLASS__, 'print_widget_script'], 20);
        return '';
    }

    public static function print_widget_script() {
        $text = [
            'hintDesktop' => 'Scannez ce QR code avec l’appareil photo de votre téléphone, puis approuvez la connexion dans l’app Delicat.',
            'hintNoQr' => 'Ouvrez l’app Delicat sur votre téléphone pour approuver cette connexion.',
            'hintTouch' => 'Touchez « Ouvrir dans l’app », puis approuvez la connexion.',
            'waiting' => 'En attente d’approbation dans l’app…',
            'creating' => 'Création de la connexion…',
            'approved' => 'Approuvé. Connexion en cours…',
            'expired' => 'Code expiré. Recommencez la connexion.',
            'used' => 'Ce code a déjà été utilisé. Recommencez la connexion.',
            'retry' => 'Recommencer',
            'offline' => 'Connexion momentanément indisponible, nouvelle tentative…',
            'error' => 'Erreur de connexion. Réessayez.',
            'qr' => 'QR code de connexion avec l’app Delicat',
        ];
        ?>
        <script>
        (()=>{"use strict";const T=<?php echo wp_json_encode($text); ?>;const init=root=>{if(root.dataset.dipPairingReady)return;root.dataset.dipPairingReady="1";const q=s=>root.querySelector(s);
        const start=q('.dip-app-pairing__start'),session=q('.dip-app-pairing__session'),code=q('.dip-app-pairing__code'),open=q('.dip-app-pairing__open'),qrBox=q('.dip-app-pairing__qr'),hint=q('.dip-app-pairing__hint'),status=q('.dip-app-pairing__status'),base=root.dataset.rest;
        let pair=null,timer=null,failures=0,startedAt=0,busy=false;const memory={};
        const touch=(()=>{try{return window.matchMedia('(pointer: coarse)').matches}catch(e){return false}})();
        const store={get:k=>{try{return window.sessionStorage.getItem(k)}catch(e){return memory[k]||null}},set:(k,v)=>{try{window.sessionStorage.setItem(k,v)}catch(e){memory[k]=v}}};
        const randomBrowser=()=>{try{const b=new Uint8Array(32);crypto.getRandomValues(b);return Array.from(b,x=>x.toString(16).padStart(2,'0')).join('')}catch(e){return Date.now().toString(36)+'-'+Math.random().toString(36).slice(2)+'-'+Math.random().toString(36).slice(2)+'-'+Math.random().toString(36).slice(2)}};
        const browser=(()=>{let v=store.get('dip_pair_browser');if(!v||!/^[A-Za-z0-9_-]{24,255}$/.test(v)){v=randomBrowser();store.set('dip_pair_browser',v)}return v})();
        const say=(t,kind)=>{status.textContent=t||'';status.dataset.kind=kind||''};
        const post=async(path,data)=>{const r=await fetch(base+path,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(data)});let j={};try{j=await r.json()}catch(e){}if(!r.ok){const err=new Error(j&&j.message?j.message:T.error);err.status=r.status;throw err}return j};
        const stop=()=>{if(timer){clearTimeout(timer);timer=null}};
        const restart=(msg,kind)=>{stop();pair=null;session.hidden=true;start.hidden=false;start.disabled=false;start.textContent=T.retry;say(msg,kind)};
        const drawQr=d=>{qrBox.textContent='';if(!d||!d.size||!d.path){qrBox.hidden=true;return false}const ns='http://www.w3.org/2000/svg',svg=document.createElementNS(ns,'svg'),bg=document.createElementNS(ns,'rect'),p=document.createElementNS(ns,'path');svg.setAttribute('viewBox','0 0 '+d.size+' '+d.size);svg.setAttribute('width','196');svg.setAttribute('height','196');svg.setAttribute('shape-rendering','crispEdges');svg.setAttribute('role','img');svg.setAttribute('aria-label',T.qr);bg.setAttribute('width',d.size);bg.setAttribute('height',d.size);bg.setAttribute('fill','#fff');p.setAttribute('d',d.path);p.setAttribute('fill','#0f172a');svg.appendChild(bg);svg.appendChild(p);qrBox.appendChild(svg);qrBox.hidden=false;return true};
        const schedule=ms=>{stop();if(pair&&!document.hidden)timer=setTimeout(poll,ms)};
        const pace=()=>Date.now()-startedAt>30000?4000:2500;
        async function poll(){timer=null;if(!pair||busy||document.hidden)return;if(Date.now()-startedAt>((pair.expires_in||300)+5)*1000){restart(T.expired,'error');return}busy=true;
        try{const s=await post('status',{pairing_id:pair.pairing_id,secret:pair.secret,browser_token:browser});failures=0;
        if(s.status==='approved'){say(T.approved,'ok');const x=await post('exchange',{pairing_id:pair.pairing_id,secret:pair.secret,browser_token:browser});if(x&&x.redirect){window.location.assign(x.redirect);return}restart(T.error,'error');return}
        if(s.status==='expired'){restart(T.expired,'error');return}if(s.status==='consumed'){restart(T.used,'error');return}say(T.waiting);busy=false;schedule(pace())}
        catch(e){busy=false;if(e.status&&e.status>=400&&e.status<500&&e.status!==429){restart(e.message,'error');return}failures++;say(failures<4?T.offline:e.message,'error');schedule(Math.min(12000,2500+failures*1500))}finally{busy=false}}
        start.addEventListener('click',async()=>{if(busy)return;stop();busy=true;start.disabled=true;say(T.creating);
        try{pair=await post('create',{browser_token:browser});startedAt=Date.now();failures=0;code.textContent=pair.display_code||'—';
        if(touch){open.href=pair.deep_link;open.hidden=false;qrBox.hidden=true;hint.textContent=T.hintTouch}else{open.hidden=true;hint.textContent=drawQr(pair.qr)?T.hintDesktop:T.hintNoQr}
        session.hidden=false;start.hidden=true;say(T.waiting);busy=false;schedule(pace())}
        catch(e){busy=false;pair=null;start.disabled=false;say(e.message||T.error,'error')}});
        document.addEventListener('visibilitychange',()=>{if(document.hidden)stop();else if(pair&&!timer)poll()});
        window.addEventListener('pagehide',stop);window.addEventListener('pageshow',()=>{if(pair&&!timer)poll()});};const boot=()=>document.querySelectorAll('.dip-app-pairing[data-rest]').forEach(init);if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();})();
        </script>
        <?php
    }

    // ------------------------------------------------------------------
    // QR code of the deep link, drawn on the server (byte mode, error
    // correction M, versions 1-10): no third-party service or library.
    // ------------------------------------------------------------------

    /** Per version (level M): [EC codewords per block, blocks group 1, data codewords group 1, blocks group 2, data codewords group 2]. */
    private static function qr_blocks($version) {
        $table = [1=>[10,1,16,0,0],[16,1,28,0,0],[26,1,44,0,0],[18,2,32,0,0],[24,2,43,0,0],[16,4,27,0,0],[18,4,31,0,0],[22,2,38,2,39],[22,3,36,2,37],[26,4,43,1,44]];
        return $table[$version] ?? null;
    }

    private static function qr_gf_mul($x, $y) {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function qr_ecc(array $data, $degree) {
        $divisor = array_fill(0, $degree, 0);
        $divisor[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $divisor[$j] = self::qr_gf_mul($divisor[$j], $root);
                if ($j + 1 < $degree) $divisor[$j] ^= $divisor[$j + 1];
            }
            $root = self::qr_gf_mul($root, 2);
        }
        $result = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $degree; $i++) $result[$i] ^= self::qr_gf_mul($divisor[$i], $factor);
        }
        return $result;
    }

    /** Module matrix ($m[row][col], true = dark) or null when the text does not fit. */
    public static function qr_matrix($text) {
        $bytes = array_values(unpack('C*', (string) $text) ?: []);
        $len = count($bytes);
        $version = 0;
        for ($v = 1; $v <= 10; $v++) {
            $b = self::qr_blocks($v);
            if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= ($b[1] * $b[2] + $b[3] * $b[4]) * 8) { $version = $v; break; }
        }
        if (!$version) return null;
        list($ec, $g1, $d1, $g2, $d2) = self::qr_blocks($version);
        $capacity = ($g1 * $d1 + $g2 * $d2) * 8;
        $bits = '0100' . str_pad(decbin($len), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach ($bytes as $byte) $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $data = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($data) < $capacity / 8; $pad ^= 0xEC ^ 0x11) $data[] = $pad;

        $blocks = []; $eccs = []; $offset = 0;
        foreach (array_merge(array_fill(0, $g1, $d1), $g2 ? array_fill(0, $g2, $d2) : []) as $size) {
            $block = array_slice($data, $offset, $size);
            $offset += $size;
            $blocks[] = $block;
            $eccs[] = self::qr_ecc($block, $ec);
        }
        $stream = [];
        for ($i = 0; $i < max($d1, $d2); $i++) foreach ($blocks as $block) if (isset($block[$i])) $stream[] = $block[$i];
        for ($i = 0; $i < $ec; $i++) foreach ($eccs as $e) $stream[] = $e[$i];

        $n = $version * 4 + 17;
        $m = array_fill(0, $n, array_fill(0, $n, false));
        $f = $m; // function modules
        $set = function ($r, $c, $dark) use (&$m, &$f, $n) { if ($r >= 0 && $c >= 0 && $r < $n && $c < $n) { $m[$r][$c] = $dark; $f[$r][$c] = true; } };
        for ($i = 0; $i < $n; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
        foreach ([[3, 3], [3, $n - 4], [$n - 4, 3]] as $center) {
            for ($dr = -4; $dr <= 4; $dr++) for ($dc = -4; $dc <= 4; $dc++) {
                $dist = max(abs($dr), abs($dc));
                $set($center[0] + $dr, $center[1] + $dc, $dist !== 2 && $dist !== 4);
            }
        }
        $align = $version === 1 ? [] : ($version < 7 ? [6, $version * 4 + 10] : [6, [7=>22, 8=>24, 9=>26, 10=>28][$version], [7=>38, 8=>42, 9=>46, 10=>50][$version]]);
        $last = count($align) - 1;
        foreach ($align as $i => $r) foreach ($align as $j => $c) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) continue;
            for ($dr = -2; $dr <= 2; $dr++) for ($dc = -2; $dc <= 2; $dc++) $set($r + $dr, $c + $dc, max(abs($dr), abs($dc)) !== 1);
        }
        $format = function ($mask) use ($set, $n) {
            $data = $mask; // level M = 00
            $rem = $data;
            for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
            $word = (($data << 10) | $rem) ^ 0x5412;
            $bit = function ($i) use ($word) { return (($word >> $i) & 1) === 1; };
            for ($i = 0; $i <= 5; $i++) $set($i, 8, $bit($i));
            $set(7, 8, $bit(6)); $set(8, 8, $bit(7)); $set(8, 7, $bit(8));
            for ($i = 9; $i < 15; $i++) $set(8, 14 - $i, $bit($i));
            for ($i = 0; $i < 8; $i++) $set(8, $n - 1 - $i, $bit($i));
            for ($i = 8; $i < 15; $i++) $set($n - 15 + $i, 8, $bit($i));
            $set($n - 8, 8, true);
        };
        $format(0);
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $word = ($version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($word >> $i) & 1) === 1;
                $a = $n - 11 + $i % 3; $b = intdiv($i, 3);
                $set($b, $a, $dark); $set($a, $b, $dark);
            }
        }
        $i = 0; $total = count($stream) * 8;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $n; $vert++) for ($j = 0; $j < 2; $j++) {
                $c = $right - $j;
                $r = (($right + 1) & 2) === 0 ? $n - 1 - $vert : $vert;
                if (!$f[$r][$c] && $i < $total) { $m[$r][$c] = (($stream[$i >> 3] >> (7 - ($i & 7))) & 1) === 1; $i++; }
            }
        }
        $masked = function ($mask) use (&$m, $f, $n) {
            $out = $m;
            for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) {
                if ($f[$r][$c]) continue;
                switch ($mask) {
                    case 0: $flip = ($r + $c) % 2 === 0; break;
                    case 1: $flip = $r % 2 === 0; break;
                    case 2: $flip = $c % 3 === 0; break;
                    case 3: $flip = ($r + $c) % 3 === 0; break;
                    case 4: $flip = (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0; break;
                    case 5: $flip = ($r * $c) % 2 + ($r * $c) % 3 === 0; break;
                    case 6: $flip = (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0; break;
                    default: $flip = (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0;
                }
                if ($flip) $out[$r][$c] = !$out[$r][$c];
            }
            return $out;
        };
        $best = null; $best_score = PHP_INT_MAX; $best_mask = 0;
        for ($mask = 0; $mask < 8; $mask++) {
            $format($mask);
            $candidate = $masked($mask);
            $score = self::qr_penalty($candidate, $n);
            if ($score < $best_score) { $best_score = $score; $best = $candidate; $best_mask = $mask; }
        }
        $m = $best;
        $format($best_mask);
        return $m;
    }

    private static function qr_penalty(array $m, $n) {
        $score = 0; $dark = 0; $lines = [];
        for ($r = 0; $r < $n; $r++) {
            $row = ''; $col = '';
            for ($c = 0; $c < $n; $c++) { $row .= $m[$r][$c] ? '1' : '0'; $col .= $m[$c][$r] ? '1' : '0'; }
            $lines[] = $row; $lines[] = $col;
            $dark += substr_count($row, '1');
        }
        foreach ($lines as $line) {
            if (preg_match_all('/0{5,}|1{5,}/', $line, $runs)) foreach ($runs[0] as $run) $score += 3 + strlen($run) - 5;
            $score += 40 * preg_match_all('/(?=10111010000|00001011101)/', $line);
        }
        for ($r = 0; $r < $n - 1; $r++) for ($c = 0; $c < $n - 1; $c++) {
            $v = $m[$r][$c];
            if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) $score += 3;
        }
        return $score + 10 * intdiv(abs($dark * 20 - $n * $n * 10), $n * $n);
    }

    /** {size, path} for an SVG drawn by the page (4-module quiet zone), or null. */
    private static function qr_payload($text) {
        $m = self::qr_matrix($text);
        if (!$m) return null;
        $n = count($m);
        $path = '';
        for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) {
            if (!$m[$r][$c]) continue;
            for ($run = 1; $c + $run < $n && $m[$r][$c + $run]; $run++);
            $path .= 'M' . ($c + 4) . ' ' . ($r + 4) . 'h' . $run . 'v1h-' . $run . 'z';
            $c += $run - 1;
        }
        return ['size' => $n + 8, 'path' => $path];
    }

    // ------------------------------------------------------------------
    // Administration.
    // ------------------------------------------------------------------

    public static function admin_menu() {
        add_options_page('Identity App Sync', 'Identity App Sync', 'manage_options', 'dip-app-sync-v2', [__CLASS__, 'admin_page']);
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dip_app_sync_v2_save');
        $old = self::settings();
        $input = isset($_POST['settings']) ? (array) wp_unslash($_POST['settings']) : [];
        $new = $old;
        $new['enabled'] = !empty($input['enabled']) ? 'yes' : 'no';
        $new['allow_web_pairing'] = !empty($input['allow_web_pairing']) ? 'yes' : 'no';
        $redirect = trim(is_scalar($input['redirect_after_pairing'] ?? '') ? (string) ($input['redirect_after_pairing'] ?? '') : '');
        $new['redirect_after_pairing'] = $redirect === '' ? '' : esc_url_raw($redirect);
        $new['button_text'] = sanitize_text_field(is_scalar($input['button_text'] ?? null) ? (string) $input['button_text'] : self::defaults()['button_text']);
        $scheme = strtolower(trim(is_scalar($input['deep_link_scheme'] ?? null) ? (string) $input['deep_link_scheme'] : 'delicat'));
        $new['deep_link_scheme'] = preg_match('/^[a-z][a-z0-9+.-]{1,31}$/', $scheme) ? $scheme : 'delicat';
        update_option(self::OPTION, $new, false);
        if (class_exists('DIP_Audit')) DIP_Audit::record('app_sync_v2_settings_updated', 'info', get_current_user_id());
        wp_safe_redirect(add_query_arg('updated', '1', admin_url('options-general.php?page=dip-app-sync-v2')));
        exit;
    }

    public static function cleanup_action() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dip_app_sync_v2_cleanup');
        self::cleanup();
        wp_safe_redirect(add_query_arg('cleaned', '1', admin_url('options-general.php?page=dip-app-sync-v2')));
        exit;
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $settings = self::settings();
        $table = self::table();
        $now = gmdate('Y-m-d H:i:s');
        $stats = [
            'pending' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status='pending' AND expires_at > %s", $now)),
            'approved' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status='approved' AND expires_at > %s", $now)),
            'consumed' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status='consumed' AND consumed_at > %s", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS))),
        ];
        $mobile = wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
        ?>
        <div class="wrap"><h1>Delicat Identity — App Sync 2.0</h1>
        <?php if (isset($_GET['updated'])) echo '<div class="notice notice-success"><p>Réglages enregistrés.</p></div>'; ?>
        <?php if (isset($_GET['cleaned'])) echo '<div class="notice notice-success"><p>Demandes expirées supprimées.</p></div>'; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;max-width:920px;margin:20px 0">
          <div class="card"><h2>API mobile</h2><p><strong><?php echo $mobile['mobile_api_enabled']==='yes'?'Active':'Inactive'; ?></strong></p></div>
          <div class="card"><h2>En attente</h2><p><strong><?php echo esc_html($stats['pending']); ?></strong></p></div>
          <div class="card"><h2>Approuvées</h2><p><strong><?php echo esc_html($stats['approved']); ?></strong></p></div>
          <div class="card"><h2>Connexions 30 j</h2><p><strong><?php echo esc_html($stats['consumed']); ?></strong></p></div>
        </div>
        <?php if (!is_ssl()): ?><div class="notice notice-error"><p>HTTPS est obligatoire pour App Sync 2.0.</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:820px;background:#fff;padding:24px;border:1px solid #dcdcde;border-radius:12px">
          <input type="hidden" name="action" value="dip_app_sync_v2_save"><?php wp_nonce_field('dip_app_sync_v2_save'); ?>
          <table class="form-table"><tbody>
            <tr><th>App Sync 2.0</th><td><label><input type="checkbox" name="settings[enabled]" value="1" <?php checked($settings['enabled'],'yes'); ?>> Activer le système</label></td></tr>
            <tr><th>Connexion web/app</th><td><label><input type="checkbox" name="settings[allow_web_pairing]" value="1" <?php checked($settings['allow_web_pairing'],'yes'); ?>> Autoriser les codes d’appairage à usage unique</label></td></tr>
            <tr><th>Schéma de lien app</th><td><input class="regular-text" name="settings[deep_link_scheme]" value="<?php echo esc_attr($settings['deep_link_scheme']); ?>"><p class="description">Exemple : delicat://identity/pair</p></td></tr>
            <tr><th>Texte du bouton</th><td><input class="regular-text" name="settings[button_text]" value="<?php echo esc_attr($settings['button_text']); ?>"></td></tr>
            <tr><th>Redirection</th><td><input class="regular-text" name="settings[redirect_after_pairing]" value="<?php echo esc_attr($settings['redirect_after_pairing']); ?>" placeholder="Page Mon compte"><p class="description">Laissez vide pour ouvrir la page Mon compte de WooCommerce.</p></td></tr>
          </tbody></table><?php submit_button('Enregistrer'); ?>
        </form>
        <div class="card" style="max-width:820px;margin-top:18px"><h2>Intégration</h2><p>Ajoutez le shortcode <code>[delicat_app_pairing]</code> sur votre page de connexion. Connecté, le même shortcode affiche au client ses téléphones connectés.</p><p>L’app affiche la demande avec <code>POST /wp-json/delicat-identity/v1/pairing/preview</code>, puis l’approuve avec <code>POST /wp-json/delicat-identity/v1/pairing/approve</code> en utilisant son jeton Bearer mobile. L’approbation demande une session de l’app confirmée récemment (sinon 403 <code>reauth_required</code>).</p></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('dip_app_sync_v2_cleanup'); ?><input type="hidden" name="action" value="dip_app_sync_v2_cleanup"><?php submit_button('Nettoyer les demandes expirées','secondary'); ?></form>
        </div><?php
    }

    /** Daily: expired or unused requests go after an hour, completed sign-ins after 30 days. */
    public static function cleanup() {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . " WHERE (status='consumed' AND consumed_at < %s) OR (status<>'consumed' AND expires_at < %s)",
            gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS),
            gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)
        ));
    }
}
