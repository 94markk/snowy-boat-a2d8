<?php
defined('ABSPATH') || exit;

/**
 * Existing Identity Pro Google OAuth → one-time Delicat storefront session.
 *
 * This module is part of Delicat Identity Pro, not a separate WordPress plugin.
 * Identity Pro remains the only Google/OIDC account and security authority.
 */
final class DIP_Headless_Google {
    // Matches DIP_Flow_Store::TTL: the storefront flow wraps the Google flow.
    const FLOW_TTL = 1800;
    const RATE_WINDOW = 600;
    const TICKET_TTL = 120;
    const PROVIDER = 'google_headless';
    const COOKIE_PREFIX = 'dip_hg_';
    const TICKET_PREFIX = 'dip_hg_ticket_';
    const CLEANUP_HOOK = 'dip_headless_google_cleanup';
    const FLOW_PREFIX = 'dip_hg_flow_';
    // Storefront origin trusted before the setting existed; still the default
    // until an administrator saves the Fournisseurs page.
    const DEFAULT_ORIGIN = 'https://delicat-store-ultra-fast.louismarkenricky.chatgpt.site';
    const CALLBACK_PATH = '/api/commerce/auth/google/callback';
    const OPTION = 'dip_headless_google';

    public static function init() {
        add_action('init', [__CLASS__, 'route'], 0);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('dip_account_linked', [__CLASS__, 'synchronize_verified_subject'], 35, 2);
        add_action('dip_login_success', [__CLASS__, 'synchronize_verified_subject'], 35, 2);
        add_action('dip_login_success', [__CLASS__, 'capture_google_login'], 90, 2);
        add_action('dip_identity_resolved', [__CLASS__, 'record_provider_proof'], 10, 3);
        add_action('dip_account_disconnected', [__CLASS__, 'clear_subject'], 20, 2);
        add_action('dip_account_unlinked', [__CLASS__, 'clear_subject'], 20, 2);
        add_filter('delicat_app_exchange_identity_credential', [__CLASS__, 'exchange_credential'], 20, 4);
        add_action(self::CLEANUP_HOOK, [__CLASS__, 'cleanup_tickets']);
    }

    /** https://host (no path, port or credentials), or '' when invalid. */
    public static function normalize_origin($origin) {
        $parts = wp_parse_url(trim((string) $origin));
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
            || !preg_match('/^[a-z0-9.-]+$/D', strtolower((string) $parts['host']))
        ) return '';
        return 'https://' . strtolower((string) $parts['host']);
    }

    /** Storefront hand-off switch and trusted origins (administrator setting). */
    public static function settings() {
        $raw = get_option(self::OPTION, null);
        if (!is_array($raw)) return ['enabled' => 'yes', 'origins' => [self::DEFAULT_ORIGIN]];
        $origins = array_values(array_unique(array_filter(array_map([__CLASS__, 'normalize_origin'], (array) ($raw['origins'] ?? [])))));
        return ['enabled' => ($raw['enabled'] ?? 'yes') === 'no' ? 'no' : 'yes', 'origins' => $origins];
    }

    public static function enabled() {
        return self::settings()['enabled'] === 'yes';
    }

    /** Saves the switch and the origins (one per line); returns the rejected lines. */
    public static function save_settings($enabled, $origins_text) {
        $origins = [];
        $rejected = [];
        foreach (preg_split('/[\r\n,]+/', (string) $origins_text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $origin = self::normalize_origin($line);
            if ($origin === '') $rejected[] = $line;
            elseif (!in_array($origin, $origins, true)) $origins[] = $origin;
        }
        update_option(self::OPTION, ['enabled' => $enabled ? 'yes' : 'no', 'origins' => array_slice($origins, 0, 10)], false);
        return $rejected;
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
            'enabled' => $app && $google && is_ssl() && self::enabled(),
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
        return self::FLOW_PREFIX . substr(hash_hmac('sha256', $state, wp_salt('nonce')), 0, 40);
    }

    /**
     * Flows live in non-autoloaded option rows, like DIP_Flow_Store: an
     * object-cache flush during the Google step no longer loses the flow.
     */
    private static function load_flow($state) {
        $raw = get_option(self::flow_key($state), false);
        $flow = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($flow)) $flow = get_transient(self::flow_key($state)); // flow started before the update
        return is_array($flow) ? $flow : null;
    }

    private static function save_flow(array $flow) {
        $json = wp_json_encode($flow);
        return is_string($json) && update_option(self::flow_key((string) $flow['state']), $json, false);
    }

    private static function delete_flow($state) {
        delete_option(self::flow_key($state));
        delete_transient(self::flow_key($state));
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

        // The administrator's switch is a kill switch, also for in-flight flows.
        if (!self::enabled()) return '';
        $incoming = 'https://' . strtolower((string) $parts['host']);
        $allowed = apply_filters('dip_headless_allowed_origins', self::settings()['origins']);
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

    /** 12 starts per device (address + browser), 120 per shared address, fixed window. */
    private static function rate_limited() {
        $address = DIP_Native_Auth::throttle_ip();
        $agent = substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300);
        $device = DIP_Lockout::window_hit('dip_hg_rate_' . substr(hash_hmac('sha256', $address . '|' . $agent, wp_salt('nonce')), 0, 32), self::RATE_WINDOW);
        $shared = DIP_Lockout::window_hit('dip_hg_rate_ip_' . substr(hash_hmac('sha256', $address, wp_salt('nonce')), 0, 32), self::RATE_WINDOW);
        return $device > 12 || $shared > 120;
    }

    public static function route() {
        $action = sanitize_key(wp_unslash($_GET['delicat_headless_auth'] ?? ''));
        if (!in_array($action, ['start', 'confirm', 'complete'], true)) return;

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
        if ($action === 'confirm') {
            self::confirm();
            return;
        }
        self::complete();
    }

    private static function start() {
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $callback = self::trusted_callback(wp_unslash($_GET['return_to'] ?? ''));
        $provider = sanitize_key(wp_unslash($_GET['provider'] ?? ''));
        $next = self::safe_next(wp_unslash($_GET['next'] ?? ''));
        // Optional PKCE-style binding (S256): when the storefront sends a
        // challenge, the credential can only be redeemed with its verifier.
        $challenge = sanitize_text_field(wp_unslash($_GET['code_challenge'] ?? ''));
        $challenge_method = sanitize_text_field(wp_unslash($_GET['code_challenge_method'] ?? ''));
        $challenge_ok = $challenge === '' || ($challenge_method === 'S256' && preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge));
        if (!self::valid_state($state) || $callback === '' || $provider !== 'google' || !$challenge_ok) {
            wp_die(esc_html__('Demande Google Identity non autorisée.', 'delicat-google-login'), '', ['response' => 400]);
        }
        if (self::rate_limited()) {
            wp_die(esc_html__('Trop de tentatives Google. Réessayez plus tard.', 'delicat-google-login'), '', ['response' => 429]);
        }
        // Updates that replace the ZIP never run the activation hook.
        self::activate();

        $browser = self::random_hex();
        if ($browser === '' || !self::flow_cookie($state, $browser)) {
            wp_die(esc_html__('Impossible de protéger cette connexion Google.', 'delicat-google-login'), '', ['response' => 503]);
        }
        $flow = [
            'state' => $state,
            'callback' => $callback,
            'next' => $next,
            'browser' => hash_hmac('sha256', $browser, wp_salt('secure_auth')),
            'challenge' => $challenge,
            'expires' => time() + self::FLOW_TTL,
        ];
        if (!self::save_flow($flow)) {
            self::failure($flow, 'authentication_failed');
        }

        // An existing WordPress session is never turned into a storefront
        // credential by itself (a page on the trusted origin could otherwise
        // do it with a mere navigation). The visitor confirms, and Google
        // signs them in again; linking Google stays in « Comptes connectés ».
        if (is_user_logged_in()) self::confirmation_page($flow);
        self::continue_to_google($flow);
    }

    /** Sends the browser to the Identity Google sign-in for this flow. */
    private static function continue_to_google(array $flow) {
        $state = (string) $flow['state'];
        $resume = self::random_hex();
        if ($resume === '') self::failure($flow, 'authentication_failed');
        $flow['resume'] = hash_hmac('sha256', $resume, wp_salt('auth'));
        if (!self::save_flow($flow)) self::failure($flow, 'authentication_failed');
        $callback = (string) $flow['callback'];

        $complete = add_query_arg([
            'delicat_headless_auth' => 'complete',
            'state' => $state,
            'resume' => $resume,
            'return_to' => rawurlencode($callback),
        ], home_url('/'));
        $login_args = [
            'dip_action' => 'login',
            'provider' => 'google',
            // Encoded: unencoded, the completion URL's own state/resume/return_to
            // parameters leaked into the login URL, so the cookie-less and 2FA
            // recovery paths lost the flow and ended in "session expired".
            'redirect' => rawurlencode($complete),
            'tracker' => 'headless_' . $state,
        ];
        $login = add_query_arg($login_args, home_url('/'));
        wp_safe_redirect($login, 302, 'Delicat Identity Pro');
        exit;
    }

    private static function masked_email($email) {
        $email = (string) $email;
        $at = strpos($email, '@');
        if ($at === false || $at < 1) return '•••';
        return substr($email, 0, 1) . str_repeat('•', min(6, max(3, $at - 1))) . substr($email, $at);
    }

    /** Signed-in visitor: confirm before Google signs them in for the storefront. */
    private static function confirmation_page(array $flow) {
        $user = wp_get_current_user();
        $state = (string) $flow['state'];
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        // No form-action directive: browsers apply it to the redirects that
        // follow the POST (to Google, or back to the storefront on cancel).
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'");
        $action = add_query_arg('delicat_headless_auth', 'confirm', home_url('/'));
        ?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?php esc_html_e('Continuer vers la boutique', 'delicat-google-login'); ?></title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f7f9;color:#111827;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}main{box-sizing:border-box;width:min(440px,calc(100% - 32px));background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:28px;box-shadow:0 10px 30px rgba(17,24,39,.08)}h1{font-size:1.3rem;margin:0 0 12px}p{margin:0 0 12px;color:#374151}form{display:flex;flex-direction:column;gap:10px;margin-top:20px}button{font:inherit;font-weight:600;border-radius:10px;padding:12px 16px;border:1px solid #d1d5db;background:#fff;color:#111827;cursor:pointer}button.primary{background:#111827;border-color:#111827;color:#fff}button:focus-visible{outline:3px solid #2563eb;outline-offset:2px}@media (prefers-color-scheme:dark){body{background:#0b0f17;color:#f3f4f6}main{background:#111827;border-color:#1f2937}p{color:#d1d5db}button{background:#1f2937;border-color:#374151;color:#f3f4f6}button.primary{background:#f3f4f6;border-color:#f3f4f6;color:#111827}}</style>
</head><body><main>
<h1><?php esc_html_e('Continuer vers la boutique Delicat', 'delicat-google-login'); ?></h1>
<p><?php echo wp_kses(sprintf(__('Vous êtes connecté en tant que %s.', 'delicat-google-login'), '<strong>' . esc_html(self::masked_email($user->user_email)) . '</strong>'), ['strong' => []]); ?></p>
<p><?php esc_html_e('Pour ouvrir la boutique, confirmez avec Google : cette session sera d’abord fermée, puis Google vous reconnectera au compte que vous choisirez.', 'delicat-google-login'); ?></p>
<form method="post" action="<?php echo esc_url($action); ?>">
<input type="hidden" name="state" value="<?php echo esc_attr($state); ?>">
<input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('dip_hg_confirm_' . $state)); ?>">
<button type="submit" class="primary" name="decision" value="continue"><?php esc_html_e('Continuer avec Google', 'delicat-google-login'); ?></button>
<button type="submit" name="decision" value="cancel"><?php esc_html_e('Annuler', 'delicat-google-login'); ?></button>
</form>
</main></body></html><?php
        exit;
    }

    private static function confirm() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        }
        $state = sanitize_text_field(wp_unslash($_POST['state'] ?? ''));
        $flow = self::current_flow($state);
        if (!$flow) {
            wp_safe_redirect(add_query_arg('dip_auth_error', 'headless_session_expired', home_url('/')) . '#delicat-login');
            exit;
        }
        $decision = sanitize_key(wp_unslash($_POST['decision'] ?? ''));
        if ($decision !== 'continue') self::failure($flow, 'authentication_failed');
        if (is_user_logged_in()) {
            $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
            if (!wp_verify_nonce($nonce, 'dip_hg_confirm_' . $state)) self::failure($flow, 'authentication_failed');
            wp_logout();
        }
        self::continue_to_google($flow);
    }

    private static function current_flow($state, $resume = '') {
        if (!self::valid_state($state)) return null;
        $flow = self::load_flow($state);
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
        $replace = false;
        if ($existing !== '' && !hash_equals($existing, $subject)) {
            // A mirror left by an earlier Google account (disconnected, then
            // another one linked) is replaced only once no account still holds
            // that old subject in Identity.
            $old_owners = get_users(['meta_key' => '_dglp_google_sub', 'meta_value' => $existing, 'number' => 1, 'fields' => 'ids']);
            if ($old_owners) return false;
            $replace = true;
        }
        $api_owners = array_map('absint', (array) get_users([
            'meta_key' => '_delicat_google_sub',
            'meta_value' => $subject,
            'number' => 2,
            'fields' => 'ids',
        ]));
        foreach ($api_owners as $owner) {
            if ((int) $owner !== (int) $user_id) return false;
        }
        if ($existing === '' || $replace) {
            update_user_meta($user_id, '_delicat_google_sub', $subject);
            if ($replace && class_exists('DIP_Audit')) DIP_Audit::record('headless_google_subject_replaced', 'notice', $user_id);
        }
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

    /** The mirror follows the Identity link: disconnecting Google clears it. */
    public static function clear_subject($user_id, $provider = '') {
        if (sanitize_key((string) $provider) !== 'google') return;
        $user_id = absint($user_id);
        if ($user_id && (string) get_user_meta($user_id, '_dglp_google_sub', true) === '') delete_user_meta($user_id, '_delicat_google_sub');
    }

    /**
     * Google has just proven this user in the callback of a storefront flow:
     * remember it in the flow. Only that proof (not a session that existed
     * before the flow) can later become a storefront credential.
     */
    public static function record_provider_proof($user_id, $profile = [], $link_user = 0) {
        if (absint($link_user) || !is_array($profile) || ($profile['provider'] ?? '') !== 'google') return;
        if (!preg_match('/^headless_([a-f0-9]{64})$/D', (string) ($profile['_tracker'] ?? ''), $m)) return;
        $flow = self::load_flow($m[1]);
        if (!is_array($flow) || empty($flow['state']) || !hash_equals((string) $flow['state'], $m[1]) || time() > (int) ($flow['expires'] ?? 0)) return;
        $flow['proof_user'] = absint($user_id);
        $flow['proof_sub'] = sanitize_text_field((string) ($profile['sub'] ?? ''));
        self::save_flow($flow);
    }

    private static function proven_for($flow, $user_id) {
        return is_array($flow) && absint($user_id) > 0
            && (int) ($flow['proof_user'] ?? 0) === absint($user_id)
            && (string) ($flow['proof_sub'] ?? '') !== '';
    }

    public static function capture_google_login($user_id, $profile) {
        if (!is_array($profile) || sanitize_key((string) ($profile['provider'] ?? 'google')) !== 'google') return;
        $tracker = (string) ($profile['_tracker'] ?? '');
        if (strpos($tracker, 'headless_') !== 0) return;
        $state = substr($tracker, 9);
        $flow = self::current_flow($state);
        if (!$flow || !self::proven_for($flow, $user_id)) return;
        self::finish($flow, absint($user_id), (string) $flow['proof_sub']);
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
        // The Google step failed or was cancelled: never fall back to a
        // WordPress session that existed before this flow.
        if (isset($_GET['dip_auth_error'])) self::failure($flow, 'authentication_failed');
        if (!is_user_logged_in()) self::failure($flow, 'authentication_failed');
        // Only the account Google proved in this flow's callback (session
        // created by that sign-in or its 2FA step) gets a credential.
        if (!self::proven_for($flow, get_current_user_id())) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('headless_google_unproven_session_refused', 'warning', get_current_user_id());
            self::failure($flow, 'authentication_failed');
        }
        self::finish($flow, get_current_user_id(), (string) $flow['proof_sub']);
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
            'challenge' => (string) ($flow['challenge'] ?? ''),
        ]);
        if (!is_string($record) || !add_option(self::ticket_option($credential), $record, '', false)) {
            self::failure($flow, 'authentication_failed');
        }

        self::delete_flow((string) $flow['state']);
        self::flow_cookie((string) $flow['state'], '', true);
        if (class_exists('DIP_Audit')) DIP_Audit::record('headless_google_ticket_created', 'info', absint($user_id));
        $target = add_query_arg([
            'state' => (string) $flow['state'],
            'credential' => $credential,
            'next' => rawurlencode(self::safe_next((string) $flow['next'])),
        ], (string) $flow['callback']);
        wp_redirect($target, 302, 'Delicat Identity Pro'); // Strict exact-origin HTTPS allowlist validated above.
        exit;
    }

    private static function failure(array $flow, $error) {
        $state = (string) ($flow['state'] ?? '');
        if (self::valid_state($state)) self::delete_flow($state);
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
        if (!self::enabled()) return new WP_Error('dip_headless_disabled', 'La connexion Google de la boutique est désactivée.', ['status' => 403]);
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
        if (!empty($record['challenge'])) {
            $verifier = $request->get_param('code_verifier');
            $verifier = is_string($verifier) ? $verifier : '';
            $computed = preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)
                ? rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')
                : '';
            if ($computed === '' || !hash_equals((string) $record['challenge'], $computed)) {
                return new WP_Error('dip_headless_verifier_invalid', 'Justificatif Google invalide.', ['status' => 401]);
            }
        }

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
        foreach ([self::TICKET_PREFIX, self::FLOW_PREFIX] as $prefix) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 200",
                $wpdb->esc_like($prefix) . '%'
            ), ARRAY_A);
            foreach (is_array($rows) ? $rows : [] as $row) {
                $record = json_decode((string) ($row['option_value'] ?? ''), true);
                if (!is_array($record) || time() > (int) ($record['expires'] ?? 0)) {
                    delete_option((string) $row['option_name']);
                }
            }
        }
    }
}
