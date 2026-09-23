<?php
defined('ABSPATH') || exit;

/**
 * Existing Identity Pro Google OAuth → one-time Delicat storefront session.
 *
 * This module is part of Delicat Identity Pro, not a separate WordPress plugin.
 * Identity Pro remains the only Google/OIDC account and security authority.
 */
final class DIP_Headless_Google {
    const FLOW_TTL = 600;
    const TICKET_TTL = 120;
    const PROVIDER = 'google_headless';
    const COOKIE_PREFIX = 'dip_hg_';
    const TICKET_PREFIX = 'dip_hg_ticket_';
    const CLEANUP_HOOK = 'dip_headless_google_cleanup';
    const DEFAULT_ORIGIN = 'https://delicat-store-ultra-fast.louismarkenricky.chatgpt.site';
    const CALLBACK_PATH = '/api/commerce/auth/google/callback';

    public static function init() {
        add_action('init', [__CLASS__, 'route'], 0);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('dip_account_linked', [__CLASS__, 'synchronize_verified_subject'], 35, 2);
        add_action('dip_login_success', [__CLASS__, 'synchronize_verified_subject'], 35, 2);
        add_action('dip_login_success', [__CLASS__, 'capture_google_login'], 90, 2);
        add_filter('delicat_app_exchange_identity_credential', [__CLASS__, 'exchange_credential'], 20, 4);
        add_action(self::CLEANUP_HOOK, [__CLASS__, 'cleanup_tickets']);
    }

    public static function activate() {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
    }

    public static function routes() {
        register_rest_route('delicat-identity/v1', '/headless/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'status'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function status() {
        $app = class_exists('Delicat_App_Auth');
        $settings = wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
        $google = ($settings['enabled'] ?? 'no') === 'yes'
            && !empty($settings['client_id'])
            && !empty($settings['client_secret']);

        $response = new WP_REST_Response([
            'enabled' => $app && $google && is_ssl(),
            'version' => defined('DIP_VERSION') ? DIP_VERSION : '',
            'session_exchange' => $app,
            'browser_recovery' => true,
            'google' => [
                'enabled' => $google,
                'provider' => 'Delicat Identity Pro',
                'nextend_available' => class_exists('NextendSocialLogin') || defined('NSL_PATH_FILE'),
            ],
        ], 200);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private static function random_hex() {
        try {
            return bin2hex(random_bytes(32));
        } catch (Throwable $exception) {
            return '';
        }
    }

    private static function valid_state($value) {
        return is_string($value) && (bool) preg_match('/^[a-f0-9]{64}$/D', $value);
    }

    private static function flow_key($state) {
        return 'dip_hg_flow_' . substr(hash_hmac('sha256', $state, wp_salt('nonce')), 0, 40);
    }

    private static function cookie_name($state) {
        return self::COOKIE_PREFIX . substr($state, 0, 16);
    }

    private static function host_cookie_name($state) {
        return self::cookie_name($state) . '_h';
    }

    private static function home_cookie_path() {
        $path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if ($path === '') $path = '/';
        if ($path[0] !== '/') $path = '/' . $path;
        return trailingslashit($path);
    }

    private static function ticket_option($credential) {
        return self::TICKET_PREFIX . hash_hmac('sha256', $credential, wp_salt('auth'));
    }

    private static function flow_cookie($state, $secret, $clear = false) {
        if (headers_sent()) return false;
        $value = $clear ? '' : $secret;
        $expires = $clear ? time() - HOUR_IN_SECONDS : time() + self::FLOW_TTL;
        $options = [
            'expires' => $expires,
            'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        $primary_name = self::cookie_name($state);
        $host_name = self::host_cookie_name($state);
        $primary = setcookie($primary_name, $value, $options);

        // Match the hardened OAuth engine's host-only fallback. Safari and
        // embedded browsers can discard a configured COOKIE_DOMAIN/COOKIEPATH
        // that does not match WordPress's public Home URL exactly.
        $options['domain'] = '';
        $options['path'] = self::home_cookie_path();
        $host_only = setcookie($host_name, $value, $options);

        if ($clear) {
            unset($_COOKIE[$primary_name], $_COOKIE[$host_name]);
        } else {
            if ($primary) $_COOKIE[$primary_name] = $secret;
            if ($host_only) $_COOKIE[$host_name] = $secret;
        }
        return $primary || $host_only;
    }

    private static function trusted_callback($callback) {
        if (!is_string($callback) || strlen($callback) < 20 || strlen($callback) > 512) return '';
        $parts = wp_parse_url($callback);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || (string) ($parts['path'] ?? '') !== self::CALLBACK_PATH
            || isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) return '';

        $incoming = 'https://' . strtolower((string) $parts['host']);
        $allowed = apply_filters('dip_headless_allowed_origins', [self::DEFAULT_ORIGIN]);
        foreach ((array) $allowed as $origin) {
            $trusted = wp_parse_url((string) $origin);
            if (!is_array($trusted)
                || strtolower((string) ($trusted['scheme'] ?? '')) !== 'https'
                || empty($trusted['host'])
                || isset($trusted['port'])
                || isset($trusted['user'])
                || isset($trusted['pass'])
            ) continue;
            $normalized = 'https://' . strtolower((string) $trusted['host']);
            if (hash_equals($normalized, $incoming)) return $incoming . self::CALLBACK_PATH;
        }
        return '';
    }

    private static function safe_next($next) {
        if (!is_string($next) || $next === '' || strlen($next) > 400) return '/account';
        if (substr($next, 0, 1) !== '/'
            || substr($next, 0, 2) === '//'
            || strpos($next, '\\') !== false
            || preg_match('/[\x00-\x1F\x7F]/', $next)
        ) return '/account';
        return $next;
    }

    private static function rate_limited() {
        $address = class_exists('DIP_Native_Auth')
            ? (string) DIP_Native_Auth::client_ip()
            : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $key = 'dip_hg_rate_' . substr(hash_hmac('sha256', $address, wp_salt('nonce')), 0, 32);
        $count = (int) get_transient($key);
        if ($count >= 12) return true;
        set_transient($key, $count + 1, self::FLOW_TTL);
        return false;
    }

    public static function route() {
        $action = sanitize_key(wp_unslash($_GET['delicat_headless_auth'] ?? ''));
        if (!in_array($action, ['start', 'complete'], true)) return;

        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (has_action('litespeed_control_set_nocache')) {
            do_action('litespeed_control_set_nocache', 'Delicat Identity Google storefront authentication');
        }
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        if (!is_ssl() || !class_exists('Delicat_App_Auth')) {
            wp_die(esc_html__('Le service Identity Google sécurisé est indisponible.', 'delicat-google-login'), '', ['response' => 503]);
        }
        if ($action === 'start') {
            self::start();
            return;
        }
        self::complete();
    }

    private static function start() {
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $callback = self::trusted_callback(wp_unslash($_GET['return_to'] ?? ''));
        $provider = sanitize_key(wp_unslash($_GET['provider'] ?? ''));
        $next = self::safe_next(wp_unslash($_GET['next'] ?? ''));
        if (!self::valid_state($state) || $callback === '' || $provider !== 'google') {
            wp_die(esc_html__('Demande Google Identity non autorisée.', 'delicat-google-login'), '', ['response' => 400]);
        }
        if (self::rate_limited()) {
            wp_die(esc_html__('Trop de tentatives Google. Réessayez plus tard.', 'delicat-google-login'), '', ['response' => 429]);
        }

        $browser = self::random_hex();
        $resume = self::random_hex();
        if ($browser === '' || $resume === '' || !self::flow_cookie($state, $browser)) {
            wp_die(esc_html__('Impossible de protéger cette connexion Google.', 'delicat-google-login'), '', ['response' => 503]);
        }
        $flow = [
            'state' => $state,
            'callback' => $callback,
            'next' => $next,
            'browser' => hash_hmac('sha256', $browser, wp_salt('secure_auth')),
            'resume' => hash_hmac('sha256', $resume, wp_salt('auth')),
            'expires' => time() + self::FLOW_TTL,
        ];
        if (!set_transient(self::flow_key($state), $flow, self::FLOW_TTL)) {
            self::failure($flow, 'authentication_failed');
        }

        $complete = add_query_arg([
            'delicat_headless_auth' => 'complete',
            'state' => $state,
            'resume' => $resume,
            'return_to' => $callback,
        ], home_url('/'));
        $login_args = [
            'dip_action' => 'login',
            'provider' => 'google',
            'redirect' => $complete,
            'tracker' => 'headless_' . $state,
        ];
        if (is_user_logged_in()) {
            $current_user = get_current_user_id();
            $linked_subject = (string) get_user_meta($current_user, '_dglp_google_sub', true);
            if ($linked_subject === '' && self::customer_allowed($current_user)) {
                $login_args['link'] = '1';
                $login_args['_dip_nonce'] = wp_create_nonce('dip_link_' . $current_user);
            }
        }
        $login = add_query_arg($login_args, home_url('/'));
        wp_safe_redirect($login, 302, 'Delicat Identity Pro');
        exit;
    }

    private static function current_flow($state, $resume = '') {
        if (!self::valid_state($state)) return null;
        $flow = get_transient(self::flow_key($state));
        if (!is_array($flow)
            || empty($flow['state'])
            || !hash_equals((string) $flow['state'], $state)
            || empty($flow['browser'])
            || time() > (int) ($flow['expires'] ?? 0)
            || self::trusted_callback((string) ($flow['callback'] ?? '')) === ''
        ) return null;

        foreach ([self::host_cookie_name($state), self::cookie_name($state)] as $name) {
            $browser = sanitize_text_field(wp_unslash($_COOKIE[$name] ?? ''));
            if (self::valid_state($browser)
                && hash_equals((string) $flow['browser'], hash_hmac('sha256', $browser, wp_salt('secure_auth')))
            ) return $flow;
        }

        // Embedded Safari can discard both freshly issued cookies when the
        // Google flow switches browser containers. Recovery still requires an
        // existing authenticated WordPress session and the exact single-use,
        // 256-bit continuation secret kept inside the same-origin OAuth flow.
        if (!is_user_logged_in()
            || !self::valid_state($resume)
            || empty($flow['resume'])
            || !hash_equals((string) $flow['resume'], hash_hmac('sha256', $resume, wp_salt('auth')))
        ) return null;

        if (class_exists('DIP_Audit')) {
            DIP_Audit::record('headless_google_browser_recovered', 'notice', get_current_user_id());
        }
        return $flow;
    }

    private static function customer_allowed($user_id) {
        $user_id = absint($user_id);
        if (!$user_id || !class_exists('DIP_Account_Sync') || !class_exists('Delicat_App_Auth')) return false;
        if (!DIP_Account_Sync::is_customer_account($user_id) || DIP_Account_Sync::privileged_mobile_blocked($user_id)) return false;
        $guard = DIP_Account_Sync::login_guard($user_id);
        return !is_wp_error($guard) && Delicat_App_Auth::is_user_allowed($user_id);
    }

    private static function sync_subject($user_id, $expected = '') {
        $subject = (string) get_user_meta($user_id, '_dglp_google_sub', true);
        if (!preg_match('/^[0-9]{6,64}$/D', $subject)) return false;
        if ($expected !== '' && !hash_equals($subject, (string) $expected)) return false;

        $identity_owners = array_map('absint', (array) get_users([
            'meta_key' => '_dglp_google_sub',
            'meta_value' => $subject,
            'number' => 2,
            'fields' => 'ids',
        ]));
        if (count($identity_owners) !== 1 || (int) $identity_owners[0] !== (int) $user_id) return false;

        $existing = (string) get_user_meta($user_id, '_delicat_google_sub', true);
        if ($existing !== '' && !hash_equals($existing, $subject)) return false;
        $api_owners = array_map('absint', (array) get_users([
            'meta_key' => '_delicat_google_sub',
            'meta_value' => $subject,
            'number' => 2,
            'fields' => 'ids',
        ]));
        foreach ($api_owners as $owner) {
            if ((int) $owner !== (int) $user_id) return false;
        }
        if ($existing === '') update_user_meta($user_id, '_delicat_google_sub', $subject);
        return hash_equals($subject, (string) get_user_meta($user_id, '_delicat_google_sub', true));
    }

    public static function synchronize_verified_subject($user_id, $profile = []) {
        if (!is_array($profile) || sanitize_key((string) ($profile['provider'] ?? 'google')) !== 'google') return;
        $user_id = absint($user_id);
        if (!self::customer_allowed($user_id)) return;
        $expected = sanitize_text_field((string) ($profile['sub'] ?? ''));
        if (!self::sync_subject($user_id, $expected) && class_exists('DIP_Audit')) {
            DIP_Audit::record('headless_google_subject_conflict', 'warning', $user_id);
        }
    }

    public static function capture_google_login($user_id, $profile) {
        if (!is_array($profile) || sanitize_key((string) ($profile['provider'] ?? 'google')) !== 'google') return;
        $tracker = (string) ($profile['_tracker'] ?? '');
        if (strpos($tracker, 'headless_') !== 0) return;
        $state = substr($tracker, 9);
        $flow = self::current_flow($state);
        if (!$flow) return;
        self::finish($flow, absint($user_id), sanitize_text_field((string) ($profile['sub'] ?? '')));
    }

    private static function complete() {
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $resume = sanitize_text_field(wp_unslash($_GET['resume'] ?? ''));
        $flow = self::current_flow($state, $resume);
        if (!$flow) {
            $callback = self::trusted_callback(wp_unslash($_GET['return_to'] ?? ''));
            if ($callback !== '' && self::valid_state($state)) {
                self::flow_cookie($state, '', true);
                wp_redirect(add_query_arg(['state' => $state, 'error' => 'browser_expired'], $callback), 302, 'Delicat Identity Pro');
                exit;
            }
            wp_safe_redirect(add_query_arg('dip_auth_error', 'headless_session_expired', home_url('/')) . '#delicat-login');
            exit;
        }
        if (!is_user_logged_in()) self::failure($flow, 'authentication_failed');
        self::finish($flow, get_current_user_id(), '');
    }

    private static function finish(array $flow, $user_id, $expected_subject) {
        if (!self::customer_allowed($user_id) || !self::sync_subject($user_id, $expected_subject)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('headless_google_customer_blocked', 'warning', absint($user_id));
            self::failure($flow, 'account_blocked');
        }
        $credential = self::random_hex();
        if ($credential === '') self::failure($flow, 'authentication_failed');
        $record = wp_json_encode([
            'user_id' => absint($user_id),
            'state' => (string) $flow['state'],
            'expires' => time() + self::TICKET_TTL,
            'subject' => (string) get_user_meta($user_id, '_dglp_google_sub', true),
        ]);
        if (!is_string($record) || !add_option(self::ticket_option($credential), $record, '', false)) {
            self::failure($flow, 'authentication_failed');
        }

        delete_transient(self::flow_key((string) $flow['state']));
        self::flow_cookie((string) $flow['state'], '', true);
        if (class_exists('DIP_Audit')) DIP_Audit::record('headless_google_ticket_created', 'info', absint($user_id));
        $target = add_query_arg([
            'state' => (string) $flow['state'],
            'credential' => $credential,
            'next' => self::safe_next((string) $flow['next']),
        ], (string) $flow['callback']);
        wp_redirect($target, 302, 'Delicat Identity Pro'); // Strict exact-origin HTTPS allowlist validated above.
        exit;
    }

    private static function failure(array $flow, $error) {
        $state = (string) ($flow['state'] ?? '');
        delete_transient(self::flow_key($state));
        self::flow_cookie($state, '', true);
        $target = add_query_arg([
            'state' => $state,
            'error' => $error === 'account_blocked' ? 'account_blocked' : 'authentication_failed',
        ], (string) $flow['callback']);
        wp_redirect($target, 302, 'Delicat Identity Pro');
        exit;
    }

    public static function exchange_credential($resolved, $credential, $provider, $request) {
        if (sanitize_key((string) $provider) !== self::PROVIDER) return $resolved;
        if (!($request instanceof WP_REST_Request)
            || (string) $request->get_route() !== '/delicat-app/v1/auth/identity/exchange'
            || strtoupper((string) $request->get_method()) !== 'POST'
            || !self::valid_state((string) $credential)
        ) return new WP_Error('dip_headless_credential_invalid', 'Justificatif Google invalide.', ['status' => 401]);

        $option = self::ticket_option((string) $credential);
        $raw = get_option($option, false);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        $state = sanitize_text_field((string) $request->get_param('state'));
        if (!is_array($record)
            || !self::valid_state($state)
            || empty($record['state'])
            || !hash_equals((string) $record['state'], $state)
            || time() > (int) ($record['expires'] ?? 0)
        ) return new WP_Error('dip_headless_credential_expired', 'La confirmation Google a expiré ou a déjà été utilisée.', ['status' => 401]);

        global $wpdb;
        $claimed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $option,
            $raw
        ));
        if ((int) $claimed !== 1) {
            return new WP_Error('dip_headless_credential_replayed', 'Cette confirmation Google a déjà été utilisée.', ['status' => 409]);
        }
        wp_cache_delete($option, 'options');

        $user_id = absint($record['user_id'] ?? 0);
        if (!self::customer_allowed($user_id) || !self::sync_subject($user_id, (string) ($record['subject'] ?? ''))) {
            return new WP_Error('dip_headless_customer_blocked', 'Ce compte ne peut pas utiliser la boutique cliente.', ['status' => 403]);
        }
        if (class_exists('DIP_Audit')) DIP_Audit::record('headless_google_ticket_consumed', 'info', $user_id);
        return $user_id;
    }

    public static function cleanup_tickets() {
        global $wpdb;
        $pattern = $wpdb->esc_like(self::TICKET_PREFIX) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 200",
            $pattern
        ), ARRAY_A);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $record = json_decode((string) ($row['option_value'] ?? ''), true);
            if (!is_array($record) || time() > (int) ($record['expires'] ?? 0)) {
                delete_option((string) $row['option_name']);
            }
        }
    }
}
