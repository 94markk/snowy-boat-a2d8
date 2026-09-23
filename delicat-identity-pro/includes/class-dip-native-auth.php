<?php
defined('ABSPATH') || exit;

/**
 * Unified native email/password authentication for Delicat Identity Pro.
 *
 * Consolidates the former Code Snippets modal into the Identity engine so
 * WordPress, WooCommerce and the modal share the same lockout state, audit
 * events, sessions, devices and registration policy.
 */
final class DIP_Native_Auth {
    // Internal v2 actions are deliberately namespaced. The former public
    // `delicat_*` action names were also used by the legacy Code Snippets
    // implementation, so sharing them allowed whichever callback ran first to
    // terminate admin-ajax.php with an incompatible response.
    const AJAX_LOGIN_ACTION    = 'dip_native_do_login_v2';
    const AJAX_REGISTER_ACTION = 'dip_native_do_register_v2';
    const AJAX_NONCE_ACTION    = 'dip_native_fresh_nonces_v2';
    const AJAX_RESEND_ACTION   = 'dip_native_resend_verification_v2';
    const AJAX_MODAL_ACTION    = 'dip_native_modal_fragment_v1';
    const LEGACY_AJAX_LOGIN_ACTION    = 'delicat_do_login';
    const LEGACY_AJAX_REGISTER_ACTION = 'delicat_do_register';
    const LEGACY_AJAX_NONCE_ACTION    = 'delicat_fresh_nonces';
    const LEGACY_AJAX_RESEND_ACTION   = 'delicat_resend_verification';
    const BROWSER_COOKIE       = 'dip_native_browser';

    private static $initialized = false;
    private static $modal_rendered = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;

        self::register_ajax_action(self::AJAX_NONCE_ACTION, 'fresh_nonces');
        self::register_ajax_action(self::AJAX_LOGIN_ACTION, 'ajax_login');
        self::register_ajax_action(self::AJAX_REGISTER_ACTION, 'ajax_register');
        self::register_ajax_action(self::AJAX_RESEND_ACTION, 'ajax_resend_verification');
        self::register_ajax_action(self::AJAX_MODAL_ACTION, 'ajax_modal_fragment');

        // Keep the historical endpoints only when the old global snippet is
        // not present. This preserves backward compatibility without allowing
        // two independent authentication engines to race on the same action.
        if (!self::legacy_snippet_active()) {
            self::register_ajax_action(self::LEGACY_AJAX_NONCE_ACTION, 'fresh_nonces');
            self::register_ajax_action(self::LEGACY_AJAX_LOGIN_ACTION, 'ajax_login');
            self::register_ajax_action(self::LEGACY_AJAX_REGISTER_ACTION, 'ajax_register');
            self::register_ajax_action(self::LEGACY_AJAX_RESEND_ACTION, 'ajax_resend_verification');
        } else {
            add_action('init', [__CLASS__, 'detach_legacy_ajax_handlers'], PHP_INT_MAX);
            add_action('wp_loaded', [__CLASS__, 'detach_legacy_ajax_handlers'], PHP_INT_MAX);
        }

        // Run after WordPress' password authenticators so a lockout cannot be
        // replaced by a later authentication callback.
        add_filter('authenticate', [__CLASS__, 'enforce_lockout'], PHP_INT_MAX, 3);
        add_action('wp_login_failed', [__CLASS__, 'record_failed_login'], 10, 2);
        add_action('wp_login', [__CLASS__, 'clear_after_login'], 5, 2);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 30);
        add_action('template_redirect', [__CLASS__, 'protect_auth_ui_response'], 0);
        add_filter('script_loader_tag', [__CLASS__, 'protect_modal_script_tag'], 10, 2);
        // Render before WordPress prints footer scripts. The previous priority 99
        // Render the isolated Identity modal before footer scripts. wp_body_open gives
        // modern themes an earlier insertion point; wp_footer is a guarded fallback.
        add_action('wp_body_open', [__CLASS__, 'render_modal'], 99);
        add_action('wp_footer', [__CLASS__, 'render_modal'], 5);
        add_action('admin_notices', [__CLASS__, 'legacy_snippet_notice']);
    }

    private static function register_ajax_action($action, $method) {
        $action = sanitize_key((string) $action);
        if ($action === '' || !method_exists(__CLASS__, $method)) return;
        add_action('wp_ajax_nopriv_' . $action, [__CLASS__, $method]);
        add_action('wp_ajax_' . $action, [__CLASS__, $method]);
    }

    public static function legacy_snippet_active() {
        return function_exists('delicat_fresh_nonces')
            || function_exists('delicat_do_login')
            || function_exists('delicat_do_register')
            || function_exists('delicat_login_ip');
    }

    /**
     * The old Code Snippets implementation exposes named AJAX callbacks. When
     * it is still enabled, remove those specific endpoints so login/registration
     * requests cannot bypass the unified Identity engine. Anonymous legacy
     * footer callbacks cannot be removed safely, so the frontend isolates and
     * suppresses their duplicate modal instead.
     */
    public static function detach_legacy_ajax_handlers() {
        $map = [
            self::LEGACY_AJAX_NONCE_ACTION    => 'delicat_fresh_nonces',
            self::LEGACY_AJAX_LOGIN_ACTION    => 'delicat_do_login',
            self::LEGACY_AJAX_REGISTER_ACTION => 'delicat_do_register',
        ];
        foreach ($map as $action => $callback) {
            if (!function_exists($callback)) continue;
            remove_action('wp_ajax_nopriv_' . $action, $callback);
            remove_action('wp_ajax_' . $action, $callback);
        }
    }

    public static function legacy_snippet_notice() {
        if (!current_user_can('manage_options')) return;
        if (!function_exists('delicat_do_login') && !function_exists('delicat_login_ip')) return;
        echo '<div class="notice notice-warning"><p><strong>Delicat Identity:</strong> ' . esc_html__('un ancien snippet de connexion Delicat semble encore actif. Désactivez sa copie dans Code Snippets afin d’éviter des hooks AJAX, lockouts et modals en double.', 'delicat-google-login') . '</p></div>';
    }

    private static function settings() {
        return wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
    }

    private static function normalize_ip($value) {
        $value = trim((string) $value);
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    /**
     * REMOTE_ADDR is the trusted default. Cloudflare's visitor header is only
     * honored when the site owner explicitly opts in, avoiding header spoofing
     * on origins that are reachable directly.
     */
    public static function client_ip() {
        $remote = self::normalize_ip(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')) ?: '0.0.0.0';
        $ip = $remote;
        $trust_cf = (defined('DIP_TRUST_CLOUDFLARE_CONNECTING_IP') && DIP_TRUST_CLOUDFLARE_CONNECTING_IP)
            || (defined('DIP_TRUST_CLOUDFLARE_HEADERS') && DIP_TRUST_CLOUDFLARE_HEADERS);
        if ($trust_cf && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cf = self::normalize_ip(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
            if ($cf) $ip = $cf;
        }
        /** Site-specific reverse proxies can safely override the resolved address. */
        $filtered = apply_filters('dip_native_auth_client_ip', $ip, $remote);
        return self::normalize_ip($filtered) ?: $remote;
    }

    private static function account_identity($login) {
        $login = strtolower(trim((string) $login));
        if ($login === '') return '';
        $user = is_email($login) ? get_user_by('email', sanitize_email($login)) : get_user_by('login', $login);
        // Existing accounts are canonicalized to one opaque internal identity so
        // alternating between email and user_login cannot create fresh buckets.
        // Unknown identifiers remain generic and are never exposed to the caller.
        return $user instanceof WP_User ? 'uid:' . (int) $user->ID : 'login:' . $login;
    }

    private static function key($scope, $login = '') {
        $scope = sanitize_key($scope);
        $material = self::client_ip();
        if ($login !== '') $material .= '|' . self::account_identity($login);
        return 'dip_native_' . $scope . '_' . substr(hash_hmac('sha256', $material, wp_salt('auth')), 0, 40);
    }

    private static function account_key($scope, $login) {
        $scope = sanitize_key($scope);
        $identity = self::account_identity($login);
        return 'dip_native_' . $scope . '_acct_' . substr(hash_hmac('sha256', $identity, wp_salt('secure_auth')), 0, 40);
    }

    private static function threshold() {
        $s = self::settings();
        return min(20, max(3, absint($s['native_max_attempts'] ?? 5)));
    }

    private static function base_lock_minutes() {
        $s = self::settings();
        return min(60, max(5, absint($s['native_lockout_base'] ?? 15)));
    }

    private static function max_lock_minutes() {
        $s = self::settings();
        return min(1440, max(self::base_lock_minutes(), absint($s['native_lockout_max'] ?? 120)));
    }

    private static function lock_minutes_for_strike($strike) {
        $strike = max(1, absint($strike));
        $mins = self::base_lock_minutes() * (int) pow(2, min(8, $strike - 1));
        return min($mins, self::max_lock_minutes());
    }

    private static function scopes($login = '') {
        $pair_threshold = self::threshold();
        // Keep the requested account-level threshold strict while giving shared
        // carrier/NAT addresses room for legitimate users. The IP bucket still
        // stops distributed credential stuffing across many account names.
        $ip_threshold = min(100, max(20, $pair_threshold * 4));
        $keys = [[
            'fails'=>self::key('fails_ip'), 'lock'=>self::key('lock_ip'),
            'strikes'=>self::key('strikes_ip'), 'threshold'=>$ip_threshold, 'type'=>'ip'
        ]];
        if ($login !== '') {
            $keys[] = [
                'fails'=>self::key('fails_pair', $login),
                'lock'=>self::key('lock_pair', $login),
                'strikes'=>self::key('strikes_pair', $login),
                'threshold'=>$pair_threshold, 'type'=>'pair',
            ];
            // Distributed credential stuffing can rotate source IPs. Keep an
            // account-wide bucket as a backstop, with a higher threshold than
            // the per-IP/account pair to reduce lockout-denial abuse.
            $keys[] = [
                'fails'=>self::account_key('fails', $login),
                'lock'=>self::account_key('lock', $login),
                'strikes'=>self::account_key('strikes', $login),
                'threshold'=>min(60, max(20, $pair_threshold * 4)), 'type'=>'account',
            ];
        }
        return $keys;
    }

    public static function remaining_seconds($login = '') {
        $remaining = 0;
        foreach (self::scopes($login) as $scope) {
            $until = (int) get_transient($scope['lock']);
            if ($until > time()) $remaining = max($remaining, $until - time());
        }
        return $remaining;
    }

    public static function is_locked($login = '') {
        return self::remaining_seconds($login) > 0;
    }

    public static function lockout_minutes($login = '') {
        $remaining = self::remaining_seconds($login);
        return max(1, (int) ceil(($remaining ?: self::base_lock_minutes() * MINUTE_IN_SECONDS) / MINUTE_IN_SECONDS));
    }

    public static function record_failure($login = '', $reason = 'password') {
        foreach (self::scopes($login) as $scope) {
            $threshold = max(3, absint($scope['threshold'] ?? self::threshold()));
            $count = (int) get_transient($scope['fails']) + 1;
            set_transient($scope['fails'], $count, DAY_IN_SECONDS);
            if ($count >= $threshold) {
                $strike = (int) get_transient($scope['strikes']) + 1;
                $minutes = self::lock_minutes_for_strike($strike);
                set_transient($scope['strikes'], $strike, DAY_IN_SECONDS);
                set_transient($scope['lock'], time() + ($minutes * MINUTE_IN_SECONDS), $minutes * MINUTE_IN_SECONDS);
                delete_transient($scope['fails']);
            }
        }
        if (class_exists('DIP_Audit')) {
            DIP_Audit::record('login_failed', 'warning', 0, ['method'=>'password','reason'=>sanitize_key($reason)]);
        }
    }

    public static function clear_failures($login = '', $include_ip = false) {
        foreach (self::scopes($login) as $scope) {
            if (!$include_ip && ($scope['type'] ?? '') === 'ip') continue;
            delete_transient($scope['fails']);
            delete_transient($scope['lock']);
            delete_transient($scope['strikes']);
        }
    }

    public static function enforce_lockout($user, $username, $password) {
        $username = sanitize_text_field((string) $username);
        if ($username !== '' && self::is_locked($username)) {
            return new WP_Error(
                'delicat_locked',
                sprintf(__('🔒 Trop de tentatives. Réessayez dans environ %d minutes.', 'delicat-google-login'), self::lockout_minutes($username))
            );
        }
        // Re-apply the Delicat customer policy at the end of WordPress' normal
        // authentication chain. This prevents a later third-party authenticator
        // from replacing an earlier verification/pending-approval WP_Error with
        // a customer object. The send_auth_cookies filter remains a second,
        // independent boundary for direct WooCommerce cookie paths.
        if ($user instanceof WP_User && class_exists('DIP_Account_Sync')) {
            $guard = DIP_Account_Sync::login_guard($user->ID);
            if (is_wp_error($guard)) return $guard;
        }
        return $user;
    }

    public static function record_failed_login($username, $error = null) {
        $username = sanitize_text_field((string) $username);
        // Policy failures are not password guesses and must not punish a real
        // customer by advancing the brute-force lockout counter.
        if ($error instanceof WP_Error) {
            $codes = $error->get_error_codes();
            if (array_intersect($codes, ['dip_email_not_verified','account_pending_approval','delicat_locked','dip_2fa_required','dip_2fa_invalid','dip_2fa_rate_limited','dip_2fa_https_required'])) return;
        }
        // Do not turn an already-active lock into another failed attempt.
        if (self::is_locked($username)) return;
        self::record_failure($username, 'credentials');
    }

    public static function clear_after_login($user_login, $user) {
        self::clear_failures((string) $user_login);
        if ($user instanceof WP_User && $user->user_email) self::clear_failures($user->user_email);
    }

    /**
     * Logged-out WordPress nonces are otherwise shared by all anonymous
     * visitors for the same time tick. Bind modal nonces to a random,
     * HttpOnly first-party browser cookie so a nonce harvested in another
     * browser cannot be used for login/registration CSRF against this one.
     */
    public static function browser_token($create = false) {
        $token = isset($_COOKIE[self::BROWSER_COOKIE]) ? trim((string) wp_unslash($_COOKIE[self::BROWSER_COOKIE])) : '';
        if (preg_match('/^[A-Za-z0-9_-]{40,128}$/', $token)) return $token;
        if (!$create || headers_sent()) return '';
        try {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (Throwable $e) {
            $token = wp_generate_password(48, false, false);
        }
        setcookie(self::BROWSER_COOKIE, $token, [
            'expires' => time() + DAY_IN_SECONDS,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::BROWSER_COOKIE] = $token;
        return $token;
    }

    public static function browser_nonce_action($base, $token = '') {
        $token = $token !== '' ? $token : self::browser_token(false);
        if ($token === '') return '';
        $binding = substr(hash_hmac('sha256', $token, wp_salt('nonce')), 0, 32);
        return sanitize_key($base) . '|' . $binding;
    }

    public static function verify_browser_nonce($base, $field = 'nonce') {
        $token = self::browser_token(false);
        $nonce = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        $action = self::browser_nonce_action($base, $token);
        return $token !== '' && $nonce !== '' && $action !== '' && (bool) wp_verify_nonce($nonce, $action);
    }

    public static function issue_browser_nonce($base) {
        $token = self::browser_token(true);
        if ($token === '') return '';
        return wp_create_nonce(self::browser_nonce_action($base, $token));
    }

    public static function fresh_nonces() {
        nocache_headers();
        $token = self::browser_token(true);
        if ($token === '') self::json_error(__('Impossible d’établir une session sécurisée. Activez les cookies puis réessayez.', 'delicat-google-login'), 'browser_binding_failed');
        wp_send_json_success([
            'login' => wp_create_nonce(self::browser_nonce_action('delicat_login_action', $token)),
            'register' => wp_create_nonce(self::browser_nonce_action('delicat_register_action', $token)),
            'resend' => wp_create_nonce(self::browser_nonce_action('delicat_resend_verification_action', $token)),
            'modal' => wp_create_nonce(self::browser_nonce_action('dip_native_modal_fragment_action', $token)),
            'otp_request' => wp_create_nonce(self::browser_nonce_action('dip_request_otp', $token)),
            'otp_verify' => wp_create_nonce(self::browser_nonce_action('dip_verify_otp', $token)),
            'magic_request' => wp_create_nonce(self::browser_nonce_action('dip_request_magic', $token)),
            'customer_register' => wp_create_nonce(self::browser_nonce_action('dip_register_customer', $token)),
        ]);
    }

    public static function ajax_resend_verification() {
        nocache_headers();
        if (!self::verify_browser_nonce('delicat_resend_verification_action')) {
            self::json_error(__('Session expirée. Nouvelle tentative…', 'delicat-google-login'), 'bad_nonce');
        }
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!is_email($email)) self::json_error(__('Adresse email invalide.', 'delicat-google-login'), 'invalid_email');

        // Per-address+IP cooldown plus a wider IP bucket prevents mail bombing.
        $cooldown_key = self::key('verify_resend_pair', $email);
        if (get_transient($cooldown_key)) {
            wp_send_json_success(['message'=>__('Un e-mail a déjà été demandé récemment. Vérifiez votre boîte de réception et les indésirables.', 'delicat-google-login')]);
        }
        $global_key = self::key('verify_resend_ip');
        $state = get_transient($global_key);
        if (!is_array($state) || empty($state['reset']) || (int) $state['reset'] <= time()) $state = ['count'=>0,'reset'=>time() + 10 * MINUTE_IN_SECONDS];
        if ((int) ($state['count'] ?? 0) >= 8) self::json_error(__('Trop de demandes. Réessayez plus tard.', 'delicat-google-login'), 'rate_limited');
        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        set_transient($global_key, $state, max(1, (int)$state['reset'] - time()));
        set_transient($cooldown_key, 1, 90);

        $sent = false;
        $user = get_user_by('email', $email);
        if ($user instanceof WP_User
            && class_exists('DIP_Account_Sync') && DIP_Account_Sync::is_customer_account($user->ID)
            && get_user_meta($user->ID, 'dip_email_verified', true) === 'no'
            && class_exists('DIP_Passwordless_Registration')) {
            $sent = DIP_Passwordless_Registration::send_email_verification($user->ID, $email);
        }
        if (class_exists('DIP_Audit')) DIP_Audit::record('verification_email_resend_requested', 'notice', $user instanceof WP_User ? $user->ID : 0, ['sent'=>$sent ? 1 : 0]);

        // Deliberately generic so this endpoint does not become an account finder.
        wp_send_json_success(['message'=>__('Si cette adresse doit être vérifiée, un nouveau lien vient d’être demandé. Vérifiez aussi les indésirables.', 'delicat-google-login')]);
    }

    private static function json_error($message, $code = '') {
        nocache_headers();
        $data = ['message'=>(string) $message];
        if ($code !== '') $data['code'] = sanitize_key($code);
        wp_send_json_error($data);
    }

    private static function safe_redirect($value, $fallback = '') {
        return DIP_Session_Router::destination($value, $fallback);
    }

    private static function login_redirect() {
        $s = self::settings();
        return self::safe_redirect($s['native_login_redirect'] ?? '/my-wallet/', home_url('/'));
    }

    private static function register_redirect() {
        $s = self::settings();
        return self::safe_redirect($s['native_register_redirect'] ?? '/my-wallet/', self::login_redirect());
    }

    private static function auth_transition_redirect($url) {
        $url = self::safe_redirect($url, home_url('/'));
        return add_query_arg('dip_auth_sync', '1', remove_query_arg('dip_auth_sync', $url));
    }

    public static function ajax_login() {
        nocache_headers();
        if (is_user_logged_in()) {
            wp_send_json_success(['message'=>__('✅ Vous êtes déjà connecté.', 'delicat-google-login'), 'redirect'=>self::auth_transition_redirect(self::login_redirect())]);
        }
        if (!self::verify_browser_nonce('delicat_login_action')) {
            self::json_error(__('Session expirée. Nouvelle tentative…', 'delicat-google-login'), 'bad_nonce');
        }
        if (!empty($_POST['dl_hp'])) {
            self::record_failure('', 'honeypot');
            self::json_error(__('Erreur de validation.', 'delicat-google-login'));
        }
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $pass = (string) wp_unslash($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);
        $s = self::settings();
        $max = min(256, max(32, absint($s['native_password_max'] ?? 100)));

        if (self::is_locked($email)) {
            self::json_error(sprintf(__('🔒 Trop de tentatives. Réessayez dans environ %d minutes.', 'delicat-google-login'), self::lockout_minutes($email)), 'locked');
        }
        if (!is_email($email) || $pass === '' || strlen($pass) > $max * 4) {
            self::record_failure($email, 'invalid_input');
            self::json_error(__('Email ou mot de passe invalide.', 'delicat-google-login'));
        }

        $user = wp_signon([
            'user_login' => $email,
            'user_password' => $pass,
            'remember' => $remember,
        ], is_ssl());
        if (is_wp_error($user)) {
            // wp_signon fires wp_login_failed; account-policy errors are safe to
            // surface because they help the legitimate customer recover.
            $code = $user->get_error_code();
            $safe_policy = ['delicat_locked','dip_email_not_verified','account_pending_approval','dip_2fa_required','dip_2fa_invalid','dip_2fa_rate_limited','dip_2fa_https_required'];
            $message = in_array($code, $safe_policy, true)
                ? wp_strip_all_tags($user->get_error_message())
                : __('❌ Identifiants incorrects. Vérifiez votre email et votre mot de passe.', 'delicat-google-login');
            self::json_error($message, $code === 'delicat_locked' ? 'locked' : (in_array($code, $safe_policy, true) ? $code : 'invalid_credentials'));
        }

        wp_set_current_user($user->ID);
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_login($user->ID);
        wp_send_json_success([
            'message' => __('✅ Connexion réussie. Redirection…', 'delicat-google-login'),
            'redirect' => self::auth_transition_redirect(self::login_redirect()),
        ]);
    }

    private static function registration_allowed() {
        $s = self::settings();
        if (($s['allow_registration'] ?? 'yes') !== 'yes') return false;
        if (class_exists('DIP_Account_Sync')) return DIP_Account_Sync::storefront_registration_enabled();
        return (bool) get_option('users_can_register')
            || 'yes' === get_option('woocommerce_enable_myaccount_registration')
            || 'yes' === get_option('woocommerce_enable_signup_and_login_from_checkout');
    }

    public static function registration_rate_limited() {
        $s = self::settings();
        $max = min(20, max(1, absint($s['native_reg_max_per_hour'] ?? 3)));
        $state = get_transient(self::key('registrations'));
        if (!is_array($state) || empty($state['reset']) || (int) $state['reset'] <= time()) return false;
        return (int) ($state['count'] ?? 0) >= $max;
    }

    public static function record_registration() {
        $key = self::key('registrations');
        $state = get_transient($key);
        if (!is_array($state) || empty($state['reset']) || (int) $state['reset'] <= time()) {
            $state = ['count'=>0, 'reset'=>time() + HOUR_IN_SECONDS];
        }
        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        set_transient($key, $state, max(1, (int) $state['reset'] - time()));
    }

    /** Short-window abuse bucket for every public registration attempt. */
    public static function registration_attempt_allowed() {
        $s = self::settings();
        $success_limit = min(20, max(1, absint($s['native_reg_max_per_hour'] ?? 3)));
        $limit = min(60, max(12, $success_limit * 4));
        $key = self::key('registration_attempts');
        $state = get_transient($key);
        if (!is_array($state) || empty($state['reset']) || (int) $state['reset'] <= time()) {
            $state = ['count'=>0, 'reset'=>time() + 10 * MINUTE_IN_SECONDS];
        }
        if ((int) ($state['count'] ?? 0) >= $limit) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('registration_attempt_rate_limited', 'warning', 0);
            return false;
        }
        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        set_transient($key, $state, max(1, (int) $state['reset'] - time()));
        return true;
    }

    public static function ajax_register() {
        nocache_headers();
        if (is_user_logged_in()) {
            wp_send_json_success(['message'=>__('✅ Votre compte est déjà connecté.', 'delicat-google-login'), 'redirect'=>self::auth_transition_redirect(self::register_redirect())]);
        }
        if (!self::verify_browser_nonce('delicat_register_action')) {
            self::json_error(__('Session expirée. Nouvelle tentative…', 'delicat-google-login'), 'bad_nonce');
        }
        if (!empty($_POST['dl_hp'])) {
            self::record_failure('', 'honeypot');
            self::json_error(__('Erreur de validation.', 'delicat-google-login'));
        }
        if (!self::registration_attempt_allowed()) self::json_error(__('Trop de demandes d’inscription. Réessayez dans quelques minutes.', 'delicat-google-login'), 'registration_attempt_rate_limited');
        if (self::is_locked()) self::json_error(__('🔒 Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'), 'locked');
        if (!self::registration_allowed()) self::json_error(__('Les inscriptions sont actuellement fermées.', 'delicat-google-login'));
        if (self::registration_rate_limited()) self::json_error(__('Limite d’inscriptions atteinte. Réessayez dans une heure.', 'delicat-google-login'), 'registration_rate_limited');

        $s = self::settings();
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $pass = (string) wp_unslash($_POST['password'] ?? '');
        $name_len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        $min = min(64, max(8, absint($s['native_password_min'] ?? 8)));
        $max = min(256, max($min, absint($s['native_password_max'] ?? 100)));

        if ($name === '' || $name_len > 80) self::json_error(__('Veuillez entrer votre nom complet.', 'delicat-google-login'));
        if (!is_email($email)) self::json_error(__('Adresse email invalide.', 'delicat-google-login'));
        if (email_exists($email)) self::json_error(__('Impossible de finaliser l’inscription. Si vous avez déjà un compte, utilisez Connexion ou Mot de passe oublié.', 'delicat-google-login'), 'registration_failed');
        $pass_len = function_exists('mb_strlen') ? mb_strlen($pass, 'UTF-8') : strlen($pass);
        if (strlen($pass) > 1024 || $pass_len < $min) self::json_error(sprintf(__('Le mot de passe doit contenir au moins %d caractères.', 'delicat-google-login'), $min));
        if ($pass_len > $max) self::json_error(__('Le mot de passe est trop long.', 'delicat-google-login'));
        if (!preg_match('/\p{L}/u', $pass) || !preg_match('/\p{N}/u', $pass)) {
            self::json_error(__('Le mot de passe doit contenir au moins une lettre et un chiffre.', 'delicat-google-login'));
        }

        $parts = explode('@', $email, 2);
        $base = sanitize_user($parts[0] ?? '', true);
        if ($base === '') $base = 'client' . wp_rand(1000, 9999);
        $username = $base;
        for ($i = 1; username_exists($username) && $i < 10000; $i++) $username = $base . $i;
        if (username_exists($username)) $username = 'client' . wp_generate_password(12, false, false);

        $names = preg_split('/\s+/', trim($name), 2);
        $role = get_role('customer') ? 'customer' : 'subscriber';
        $profile = ['provider'=>'native','email'=>$email,'name'=>$name];
        $user_id = class_exists('DIP_Account_Sync')
            ? DIP_Account_Sync::create_customer($email, $username, $pass, [
                'display_name' => $name,
                'first_name' => $names[0] ?? $name,
                'last_name' => $names[1] ?? '',
            ], $role)
            : wp_insert_user([
                'user_login'=>$username,'user_email'=>$email,'user_pass'=>$pass,'display_name'=>$name,
                'first_name'=>$names[0] ?? $name,'last_name'=>$names[1] ?? '','role'=>$role,
            ]);
        if (is_wp_error($user_id)) {
            self::json_error(__('Impossible de créer le compte. Réessayez ou contactez le support.', 'delicat-google-login'), 'registration_failed');
        }
        self::record_registration();
        do_action('dip_user_created', $user_id, $profile);

        $requires_verification = class_exists('DIP_Passwordless_Registration') && DIP_Passwordless_Registration::requires_email_verification();
        if ($requires_verification) {
            update_user_meta($user_id, 'dip_email_verified', 'no');
            if (!DIP_Passwordless_Registration::send_email_verification($user_id, $email)) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($user_id);
                self::json_error(__('L’e-mail de vérification n’a pas pu être envoyé. Vérifiez la configuration SMTP puis réessayez.', 'delicat-google-login'), 'verification_email_failed');
            }
            if (class_exists('DIP_Audit')) DIP_Audit::record('native_registration_pending_verification', 'notice', $user_id);
            wp_send_json_success([
                'message' => __('✅ Compte créé. Vérifiez maintenant votre e-mail pour activer la connexion.', 'delicat-google-login'),
                'verificationRequired' => true,
            ]);
        }

        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_verified_registration($user_id, $email);
        else update_user_meta($user_id, 'dip_email_verified', 'yes');
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());
        $user = get_user_by('id', $user_id);
        if ($user) {
            if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::fire_wp_login($user, 'native_registration');
            else do_action('wp_login', $user->user_login, $user);
            do_action('dip_login_success', $user_id, ['provider'=>'native_registration']);
        }
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_login($user_id);

        wp_send_json_success([
            'message' => __('✅ Compte créé avec succès. Redirection…', 'delicat-google-login'),
            'redirect' => self::auth_transition_redirect(self::register_redirect()),
        ]);
    }

    public static function protect_auth_ui_response() {
        if (is_admin()) return;
        $has_auth_state = isset($_GET['dip_auth_error']) || isset($_GET['dip_verified']) || isset($_GET['dip_identity_open']) || isset($_GET['dip_identity_eager']) || isset($_GET['dip_auth_sync']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presentation flags.
        if (!$has_auth_state) return;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
        nocache_headers();
        if (has_action('litespeed_control_set_nocache')) do_action('litespeed_control_set_nocache', 'Delicat Identity auth state');
    }

    public static function protect_modal_script_tag($tag, $handle) {
        if (!in_array($handle, ['dip-identity-modal-v4','dip-identity-loader','dip-session-router','dip-passkeys'], true)) return $tag;
        // Keep authentication bootstrap out of JS-delay/Rocket Loader pipelines.
        // The small lazy loader may still use native `defer`, avoiding parser
        // blocking without risking a user-visible login race.
        if (strpos($tag, 'data-cfasync=') === false) {
            $attrs = 'data-cfasync="false" data-no-optimize="1" data-no-delay="1" ';
            if ('dip-identity-loader' === $handle) {
                $attrs .= 'defer ';
            } else {
                $attrs .= 'data-no-defer="1" data-pagespeed-no-defer ';
            }
            $tag = str_replace('<script ', '<script ' . $attrs, $tag);
        }
        return $tag;
    }

    public static function lazy_modal_supported() {
        return true;
    }

    /** Public integration signal used by Builder V9 and Passkeys. */
    public static function should_use_eager_assets() {
        if (is_admin() || is_user_logged_in()) return false;
        $s = self::settings();
        if (($s['native_modal_enabled'] ?? 'yes') !== 'yes') return false;

        // 6.9.11 instant-modal path: render the small auth markup/CSS/JS with the
        // storefront response so a click never waits on admin-ajax before the
        // dialog can appear. Security nonces are still browser-bound and are
        // refreshed asynchronously by identity-modal-v4.js after the modal is
        // already visible. This removes the former two-request lazy waterfall.
        if ((bool) apply_filters('dip_native_auth_instant_modal', true)) return true;

        if (did_action('login_enqueue_scripts') || (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php')) return true;
        if (function_exists('is_account_page') && is_account_page()) return true;
        if (function_exists('is_cart') && is_cart()) return true;
        if (function_exists('is_checkout') && is_checkout()) return true;
        // 6.9.9: the lazy loader captures legacy Builder login triggers
        // ([data-dl-open], .dsb-account-login) in the capture phase, closes the
        // old drawer, mounts a browser-bound secure fragment, and has a same-page
        // eager recovery path.  Do not force the entire hidden auth modal/CSS/JS
        // into every catalog/home request merely because a legacy drawer exists.
        // A site can still opt back into eager compatibility explicitly.
        if (
            (function_exists('dsb_render_profile_card') || function_exists('dsb_get_menu_builder_settings'))
            && (bool) apply_filters('dip_native_auth_legacy_eager_compat', false)
        ) return true;
        if (isset($_GET['dip_identity_eager']) && '1' === sanitize_text_field(wp_unslash($_GET['dip_identity_eager']))) return true;
        $uri = strtolower((string) wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        if (strpos($uri, '/wp-login.php') !== false) return true;
        return (bool) apply_filters('dip_native_auth_force_eager_modal', false);
    }

    private static function should_lazy_modal() {
        return !self::should_use_eager_assets();
    }

    private static function ajax_url() {
        $ajax_url = wp_make_link_relative(admin_url('admin-ajax.php'));
        if (!is_string($ajax_url) || $ajax_url === '' || strpos($ajax_url, '/') !== 0) return '/wp-admin/admin-ajax.php';
        return $ajax_url;
    }

    private static function login_css_vars(array $s) {
        return ':root{--dip-width:' . absint($s['button_width'] ?? 100) . '%;--dip-height:' . absint($s['button_height'] ?? 48) . 'px;--dip-radius:' . absint($s['button_radius'] ?? 14) . 'px;--dip-font:' . absint($s['button_font_size'] ?? 15) . 'px;--dip-weight:' . absint($s['button_weight'] ?? 700) . ';--dip-bg:' . sanitize_hex_color($s['button_bg'] ?? '#ffffff') . ';--dip-color:' . sanitize_hex_color($s['button_color'] ?? '#111827') . ';--dip-border:' . sanitize_hex_color($s['button_border'] ?? '#d7dbea') . ';}';
    }

    private static function passkey_frontend_config() {
        if (!class_exists('DIP_Passkeys') || !method_exists('DIP_Passkeys', 'frontend_config')) return [];
        return DIP_Passkeys::frontend_config();
    }

    public static function enqueue_assets() {
        if (is_admin() || is_user_logged_in()) return;
        $s = self::settings();
        if (($s['native_modal_enabled'] ?? 'yes') !== 'yes') return;

        if (self::should_lazy_modal()) {
            wp_enqueue_script('dip-identity-loader', DIP_URL . 'assets/identity-loader.js', [], DIP_VERSION, false);
            wp_localize_script('dip-identity-loader', 'DIPIdentityLoader', [
                'ajaxUrl' => self::ajax_url(),
                'action' => self::AJAX_MODAL_ACTION,
                'nonceAction' => self::AJAX_NONCE_ACTION,
                'redirectUrl' => self::login_redirect(),
                'fragmentRedirect' => self::current_page_url(),
                // Recovery never sends a storefront customer to wp-login.php.
                // The loader reloads the same safe page once in eager-modal mode.
                'recoveryUrl' => self::current_page_url(),
                'modalId' => 'dip-identity-modal',
                'modalCssUrl' => add_query_arg('ver', DIP_VERSION, DIP_URL . 'assets/identity-modal-v3.css'),
                'assets' => [
                    'loginCss' => add_query_arg('ver', DIP_VERSION, DIP_URL . 'assets/login.css'),
                    'modalCss' => add_query_arg('ver', DIP_VERSION, DIP_URL . 'assets/identity-modal-v3.css'),
                    'modalJs' => add_query_arg('ver', DIP_VERSION, DIP_URL . 'assets/identity-modal-v4.js'),
                    'passkeysCss' => add_query_arg('ver', DIP_VERSION, DIP_URL . 'assets/passkeys.css'),
                    'passkeysJs' => add_query_arg('ver', DIP_VERSION, DIP_URL . 'assets/passkeys.js'),
                ],
                'cssVars' => self::login_css_vars($s),
                'modalConfig' => [
                    'ajaxUrl' => self::ajax_url(),
                    'redirectUrl' => self::login_redirect(),
                    'nonceAction' => self::AJAX_NONCE_ACTION,
                    'loginAction' => self::AJAX_LOGIN_ACTION,
                    'registerAction' => self::AJAX_REGISTER_ACTION,
                    'resendAction' => self::AJAX_RESEND_ACTION,
                    'modalId' => 'dip-identity-modal',
                'modalCssUrl' => add_query_arg('ver', DIP_VERSION, DIP_URL . 'assets/identity-modal-v3.css'),
                    'legacyDetected' => self::legacy_snippet_active() ? 1 : 0,
                    'registrationEnabled' => self::registration_allowed() ? 1 : 0,
                ],
                'passkeys' => self::passkey_frontend_config(),
            ]);
            return;
        }

        // Auth-critical pages keep the original eager path.
        DIP_Plugin::instance()->assets(true);
        // Modal CSS travels inside its root, avoiding a separate blocking request.
        wp_enqueue_script('dip-identity-modal-v4', DIP_URL . 'assets/identity-modal-v4.js', [], DIP_VERSION, true);
        wp_localize_script('dip-identity-modal-v4', 'DIPIdentityModal', [
            'ajaxUrl' => self::ajax_url(),
            'redirectUrl' => self::login_redirect(),
            'nonceAction' => self::AJAX_NONCE_ACTION,
            'loginAction' => self::AJAX_LOGIN_ACTION,
            'registerAction' => self::AJAX_REGISTER_ACTION,
            'resendAction' => self::AJAX_RESEND_ACTION,
            'modalId' => 'dip-identity-modal',
                'modalCssUrl' => add_query_arg('ver', DIP_VERSION, DIP_URL . 'assets/identity-modal-v3.css'),
            'legacyDetected' => self::legacy_snippet_active() ? 1 : 0,
            'registrationEnabled' => self::registration_allowed() ? 1 : 0,
        ]);
    }

    private static function safe_fragment_redirect($candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') return self::current_page_url();
        if (strlen($candidate) > 4096) return home_url('/');
        $safe = wp_validate_redirect($candidate, home_url('/'));
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = strtolower((string) wp_parse_url($safe, PHP_URL_HOST));
        if (!$host || !$home_host || !hash_equals($home_host, $host)) return home_url('/');
        return $safe;
    }

    public static function ajax_modal_fragment() {
        nocache_headers();
        if (is_user_logged_in()) self::json_error(__('Vous êtes déjà connecté.', 'delicat-google-login'), 'already_authenticated');
        $s = self::settings();
        if (($s['native_modal_enabled'] ?? 'yes') !== 'yes') self::json_error(__('Connexion modale désactivée.', 'delicat-google-login'), 'modal_disabled');
        if (!self::verify_browser_nonce('dip_native_modal_fragment_action')) self::json_error(__('Session de présentation expirée. Actualisez la page.', 'delicat-google-login'), 'modal_nonce_failed');
        $redirect = self::safe_fragment_redirect(wp_unslash($_POST['redirect'] ?? ''));
        wp_send_json_success(['html' => self::modal_markup($redirect)]);
    }

    private static function current_page_url() {
        $uri = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        if ($uri === '' || strpos($uri, '/') !== 0 || strpos($uri, '//') === 0 || strlen($uri) > 4096) $uri = '/';
        $url = wp_validate_redirect(home_url($uri), home_url('/'));
        return remove_query_arg(array('dip_identity_eager', 'dip_identity_open'), $url);
    }

    public static function render_modal() {
        if (self::$modal_rendered || is_admin() || is_user_logged_in()) return;
        $s = self::settings();
        if (($s['native_modal_enabled'] ?? 'yes') !== 'yes' || self::should_lazy_modal()) return;
        self::$modal_rendered = true;
        echo self::modal_markup(self::current_page_url()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * 6.9.15 — Provider markup is generated and escaped by Identity Pro itself,
     * never by user input. wp_kses_post() silently stripped the inline <svg>
     * icon (SVG is not in WordPress' allowed post HTML), leaving an empty icon
     * slot. Keep kses as defence in depth, but allow the exact SVG shape the
     * plugin emits.
     */
    private static function sanitize_provider_markup($html) {
        $allowed = wp_kses_allowed_html('post');
        $allowed['svg'] = ['viewbox' => true, 'width' => true, 'height' => true, 'xmlns' => true, 'fill' => true, 'aria-hidden' => true, 'focusable' => true, 'role' => true, 'class' => true];
        $allowed['path'] = ['d' => true, 'fill' => true, 'fill-rule' => true, 'clip-rule' => true, 'opacity' => true];
        $allowed['g'] = ['fill' => true, 'transform' => true];
        $allowed['a'] = array_merge($allowed['a'] ?? [], ['class' => true, 'href' => true, 'rel' => true, 'aria-label' => true, 'target' => true]);
        $allowed['span'] = array_merge($allowed['span'] ?? [], ['class' => true, 'aria-hidden' => true]);
        $allowed['div'] = array_merge($allowed['div'] ?? [], ['class' => true]);
        return wp_kses($html, $allowed);
    }

    private static function modal_styles() {
        // Ship presentation with the fragment itself. CSS optimizers and client
        // navigations may not preserve the page's original stylesheet links.
        static $css = null;
        if ($css !== null) return $css;
        $css = '';
        foreach (['identity-modal-v3.css', 'passkeys.css'] as $asset) {
            $path = DIP_DIR . 'assets/' . $asset;
            if (is_readable($path)) $css .= (string)file_get_contents($path) . "\n";
        }
        // Only trusted packaged CSS is included; prevent accidental style termination.
        return $css = str_ireplace('</style', '<\/style', $css);
    }

    private static function modal_markup($redirect_url = '') {
        $s = self::settings();
        if (($s['native_modal_enabled'] ?? 'yes') !== 'yes') return '';
        $redirect_url = self::safe_fragment_redirect($redirect_url ?: self::current_page_url());
        $pass_min = min(64, max(8, absint($s['native_password_min'] ?? 8)));
        $pass_max = min(256, max($pass_min, absint($s['native_password_max'] ?? 100)));
        $registration_enabled = self::registration_allowed();

        $google = '';
        $google_state = 'ok';
        if (class_exists('DIP_Plugin')) {
            $google_state = DIP_Plugin::instance()->google_availability();
            $google = DIP_Plugin::instance()->render_button([
                'provider' => 'google',
                'text' => 'Continuer avec Google',
                'layout' => 'row',
                'align' => 'stretch',
                'tracker' => 'native_modal',
                'redirect' => $redirect_url,
                'suppress_divider' => true,
            ]);
        } elseif (shortcode_exists('delicat_social_login_buttons')) {
            $google = do_shortcode('[delicat_social_login_buttons providers="google" layout="row" align="stretch" trackerdata="native_modal" google_text="Continuer avec Google"]');
        }
        ob_start();
        ?>
        <div id="dip-identity-modal" class="dipx-root" data-dip-identity-modal style="display:none" hidden aria-hidden="true">
          <style data-dip-modal-style data-no-optimize="1"><?php echo self::modal_styles(); // Trusted plugin assets only. ?></style>
          <div class="dipx-backdrop" data-dip-auth-close></div>
          <section class="dipx-card" role="dialog" aria-modal="true" aria-labelledby="dipx-title" aria-describedby="dipx-subtitle" tabindex="-1">
            <button type="button" class="dipx-close" data-dip-auth-close aria-label="<?php echo esc_attr__('Fermer', 'delicat-google-login'); ?>"><span aria-hidden="true"></span></button>

            <header class="dipx-header">
              <div class="dipx-brand-mark" aria-hidden="true"></div>
              <h2 id="dipx-title" class="dipx-brand"><span>DELICAT</span><strong>STORE</strong></h2>
              <p id="dipx-subtitle" class="dipx-subtitle"><?php echo esc_html__('Connectez-vous pour continuer', 'delicat-google-login'); ?></p>
            </header>

            <div class="dipx-tabs<?php echo $registration_enabled ? '' : ' is-single'; ?>" role="tablist" aria-label="<?php echo esc_attr__('Authentification', 'delicat-google-login'); ?>">
              <button type="button" id="dipx-tab-login" class="dipx-tab is-active" data-dipx-tab="login" role="tab" aria-selected="true" aria-controls="dipx-panel-login" tabindex="0"><span class="dipx-i dipx-i-user" aria-hidden="true"></span><span><?php echo esc_html__('Connexion', 'delicat-google-login'); ?></span></button>
              <?php if ($registration_enabled) : ?>
              <button type="button" id="dipx-tab-register" class="dipx-tab" data-dipx-tab="register" role="tab" aria-selected="false" aria-controls="dipx-panel-register" tabindex="-1"><span class="dipx-i dipx-i-user-plus" aria-hidden="true"></span><span><?php echo esc_html__('Inscription', 'delicat-google-login'); ?></span></button>
              <?php endif; ?>
            </div>

            <?php if (trim($google) !== '') : ?>
            <div class="dipx-divider"><span><?php echo esc_html__('ou continuer avec', 'delicat-google-login'); ?></span></div>
            <div class="dipx-social"><?php echo self::sanitize_provider_markup($google); ?></div>
            <?php elseif (current_user_can('manage_options') && class_exists('DIP_Plugin')) : ?>
            <div class="dipx-social dipx-social-diagnostic" role="note"><small><?php echo esc_html__('Visible uniquement par un administrateur — bouton Google masqué :', 'delicat-google-login'); ?> <?php echo esc_html(DIP_Plugin::google_availability_label($google_state)); ?></small></div>
            <?php endif; ?>

            <div class="dipx-message" role="alert" aria-live="polite" hidden></div>
            <input type="hidden" class="dipx-nonce-resend" value="">
            <button type="button" class="dipx-resend" data-dipx-resend hidden><?php echo esc_html__('Renvoyer l’e-mail de vérification', 'delicat-google-login'); ?></button>

            <form id="dipx-panel-login" class="dipx-form dipx-form-login" method="post" action="<?php echo esc_url(self::ajax_url()); ?>" autocomplete="on" novalidate role="tabpanel" aria-labelledby="dipx-tab-login">
              <label class="dipx-label" for="dipx-login-email"><span class="dipx-i dipx-i-mail" aria-hidden="true"></span><span><?php echo esc_html__('Email', 'delicat-google-login'); ?></span></label>
              <div class="dipx-field">
                <input id="dipx-login-email" type="email" name="email" placeholder="Votre@email.com" autocomplete="username webauthn" inputmode="email" maxlength="254" required>
              </div>
              <?php if (class_exists('DIP_Passkeys')) echo DIP_Passkeys::render_login_button(); ?>

              <label class="dipx-label" for="dipx-login-pass"><span class="dipx-i dipx-i-lock" aria-hidden="true"></span><span><?php echo esc_html__('Mot de passe', 'delicat-google-login'); ?></span></label>
              <div class="dipx-field dipx-password">
                <input id="dipx-login-pass" type="password" name="password" placeholder="Votre mot de passe" autocomplete="current-password" required>
                <button type="button" class="dipx-eye" data-dipx-eye aria-label="<?php echo esc_attr__('Afficher le mot de passe', 'delicat-google-login'); ?>"><span aria-hidden="true"></span></button>
              </div>

              <div class="dipx-two-factor" data-dipx-2fa hidden>
                <label class="dipx-label" for="dipx-login-2fa"><span class="dipx-i dipx-i-shield" aria-hidden="true"></span><span><?php echo esc_html__('Code de vérification', 'delicat-google-login'); ?></span></label>
                <div class="dipx-field"><input id="dipx-login-2fa" type="text" name="dip_2fa_code" placeholder="123456 ou code de récupération" autocomplete="one-time-code" inputmode="text" maxlength="32"></div>
                <p class="dipx-help"><?php echo esc_html__('Entrez le code de votre application Authenticator. Un code de récupération peut aussi être utilisé une seule fois.', 'delicat-google-login'); ?></p>
              </div>

              <div class="dipx-options">
                <label class="dipx-remember"><input type="checkbox" name="remember" value="1" checked><span><?php echo esc_html__('Rester connecté', 'delicat-google-login'); ?></span></label>
                <a href="<?php echo esc_url(function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('lost-password') : wp_lostpassword_url()); ?>"><?php echo esc_html__('Mot de passe oublié ?', 'delicat-google-login'); ?></a>
              </div>

              <input type="text" name="dl_hp" class="dipx-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
              <input type="hidden" name="action" value="<?php echo esc_attr(self::AJAX_LOGIN_ACTION); ?>">
              <input type="hidden" name="nonce" class="dipx-nonce-login" value="">
              <button type="submit" class="dipx-submit"><span class="dipx-i dipx-i-lock" aria-hidden="true"></span><span class="dipx-submit-label"><?php echo esc_html__('Connexion', 'delicat-google-login'); ?></span><span class="dipx-spinner" hidden aria-hidden="true"></span></button>
            </form>

            <?php if ($registration_enabled) : ?>
            <form id="dipx-panel-register" class="dipx-form dipx-form-register" method="post" action="<?php echo esc_url(self::ajax_url()); ?>" autocomplete="on" novalidate hidden role="tabpanel" aria-labelledby="dipx-tab-register">
              <label class="dipx-label" for="dipx-reg-name"><span class="dipx-i dipx-i-user" aria-hidden="true"></span><span><?php echo esc_html__('Nom complet', 'delicat-google-login'); ?></span></label>
              <div class="dipx-field"><input id="dipx-reg-name" type="text" name="name" placeholder="Votre nom complet" autocomplete="name" maxlength="80" required></div>

              <label class="dipx-label" for="dipx-reg-email"><span class="dipx-i dipx-i-mail" aria-hidden="true"></span><span><?php echo esc_html__('Email', 'delicat-google-login'); ?></span></label>
              <div class="dipx-field"><input id="dipx-reg-email" type="email" name="email" placeholder="Votre@email.com" autocomplete="email" inputmode="email" maxlength="254" required></div>

              <label class="dipx-label" for="dipx-reg-pass"><span class="dipx-i dipx-i-lock" aria-hidden="true"></span><span><?php echo esc_html__('Mot de passe', 'delicat-google-login'); ?></span></label>
              <div class="dipx-field dipx-password">
                <input id="dipx-reg-pass" type="password" name="password" placeholder="<?php echo esc_attr(sprintf(__('Minimum %d caractères', 'delicat-google-login'), $pass_min)); ?>" autocomplete="new-password" minlength="<?php echo esc_attr($pass_min); ?>" maxlength="<?php echo esc_attr($pass_max); ?>" required>
                <button type="button" class="dipx-eye" data-dipx-eye aria-label="<?php echo esc_attr__('Afficher le mot de passe', 'delicat-google-login'); ?>"><span aria-hidden="true"></span></button>
              </div>
              <p class="dipx-help"><?php echo esc_html(sprintf(__('Utilisez au moins %d caractères avec des lettres et des chiffres.', 'delicat-google-login'), $pass_min)); ?></p>
              <input type="text" name="dl_hp" class="dipx-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
              <input type="hidden" name="action" value="<?php echo esc_attr(self::AJAX_REGISTER_ACTION); ?>">
              <input type="hidden" name="nonce" class="dipx-nonce-register" value="">
              <button type="submit" class="dipx-submit"><span class="dipx-i dipx-i-user-plus" aria-hidden="true"></span><span class="dipx-submit-label"><?php echo esc_html__('Créer mon compte', 'delicat-google-login'); ?></span><span class="dipx-spinner" hidden aria-hidden="true"></span></button>
            </form>
            <?php endif; ?>

            <footer class="dipx-security"><span class="dipx-i dipx-i-shield" aria-hidden="true"></span><span><?php echo esc_html__('Connexion sécurisée et protégée', 'delicat-google-login'); ?></span></footer>
          </section>
        </div>
        <?php
        return ob_get_clean();
    }

}
