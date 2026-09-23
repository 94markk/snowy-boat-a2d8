<?php
defined('ABSPATH') || exit;

/**
 * Delicat Passkeys / WebAuthn.
 *
 * Security model:
 * - exact configured RP ID + origin validation;
 * - user presence + user verification are required;
 * - one-time, browser-bound ceremonies with atomic consumption locks;
 * - public-key-only storage in user meta (private keys never leave authenticator);
 * - passkey enrollment/removal requires current-user ownership + recent auth;
 * - passwordless login is account-policy checked and can satisfy Delicat 2FA
 *   because WebAuthn user verification is required for the assertion.
 */
final class DIP_Passkeys {
    const META_CREDENTIALS = '_dip_passkeys_v1';
    const META_USER_HANDLE = '_dip_passkey_user_handle_v1';
    const CEREMONY_PREFIX = 'dip_pk_ceremony_';
    const LOCK_PREFIX = 'dip_pk_lock_';
    const REST_NAMESPACE = 'delicat-identity/v1';
    const CEREMONY_TTL = 300;

    private static $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 45);
        add_action('login_enqueue_scripts', [__CLASS__, 'assets'], 45);
        add_action('login_form', [__CLASS__, 'render_standard_login_button'], 40);
        add_action('woocommerce_login_form_end', [__CLASS__, 'render_standard_login_button'], 40);
        add_filter('script_loader_tag', [__CLASS__, 'protect_script_tag'], 10, 2);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('delicat identity-passkeys-reset', [__CLASS__, 'cli_reset']);
        }
    }

    private static function settings() {
        return wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
    }

    public static function available() {
        $s = self::settings();
        if (($s['passkeys_available'] ?? 'yes') !== 'yes') return false;
        if (!function_exists('openssl_verify') || !function_exists('openssl_pkey_get_public')) return false;
        return self::https_ok();
    }

    private static function https_ok() {
        if (is_ssl()) return true;
        return (bool) apply_filters('dip_passkeys_allow_insecure_localhost', false);
    }

    private static function max_per_user() {
        $s = self::settings();
        return min(12, max(1, absint($s['passkeys_max_per_user'] ?? 8)));
    }

    private static function b64u_encode($bytes) {
        return rtrim(strtr(base64_encode((string) $bytes), '+/', '-_'), '=');
    }

    private static function b64u_decode($value) {
        $value = preg_replace('/\s+/', '', (string) $value);
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) return false;
        $pad = strlen($value) % 4;
        if ($pad) $value .= str_repeat('=', 4 - $pad);
        $raw = base64_decode(strtr($value, '-_', '+/'), true);
        return $raw === false ? false : $raw;
    }

    private static function random_b64u($bytes = 32) {
        try { return self::b64u_encode(random_bytes(max(16, absint($bytes)))); }
        catch (Throwable $e) { return self::b64u_encode(hash('sha256', wp_generate_password(64, true, true) . microtime(true), true)); }
    }

    private static function rp_id() {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = trim($host, '.');
        if ($host === '') return '';
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host)) {
            $ascii = idn_to_ascii($host, defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0, defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0);
            if (is_string($ascii) && $ascii !== '') $host = strtolower($ascii);
        }
        if (!preg_match('/^[a-z0-9.-]+$/D', $host)) return '';
        return $host;
    }

    private static function expected_origin() {
        $parts = wp_parse_url(home_url('/'));
        $rp_id = self::rp_id();
        if (!is_array($parts) || $rp_id === '') return '';
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($scheme !== 'https' && !apply_filters('dip_passkeys_allow_insecure_localhost', false)) return '';
        // Use the same canonical ASCII host as RP ID so IDN/punycode handling
        // cannot make registration and assertion origin checks disagree.
        $origin = $scheme . '://' . $rp_id;
        if (!empty($parts['port'])) {
            $port = absint($parts['port']);
            if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) $origin .= ':' . $port;
        }
        return $origin;
    }

    private static function user_handle($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return '';
        $handle = (string) get_user_meta($user_id, self::META_USER_HANDLE, true);
        if ($handle !== '' && self::b64u_decode($handle) !== false) return $handle;
        $handle = self::random_b64u(32);
        update_user_meta($user_id, self::META_USER_HANDLE, $handle);
        return $handle;
    }

    public static function credentials($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id) return [];
        $raw = get_user_meta($user_id, self::META_CREDENTIALS, true);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) continue;
            $id = sanitize_text_field((string) ($entry['id'] ?? ''));
            $pem = (string) ($entry['public_key'] ?? '');
            $alg = (int) ($entry['alg'] ?? 0);
            if ($id === '' || self::b64u_decode($id) === false || $pem === '' || !in_array($alg, [-7, -257], true)) continue;
            $entry['id'] = $id;
            $entry['alg'] = $alg;
            $entry['sign_count'] = max(0, (int) ($entry['sign_count'] ?? 0));
            $entry['created'] = max(0, (int) ($entry['created'] ?? 0));
            $entry['last_used'] = max(0, (int) ($entry['last_used'] ?? 0));
            $entry['label'] = sanitize_text_field((string) ($entry['label'] ?? __('Passkey', 'delicat-google-login')));
            $transports = isset($entry['transports']) && is_array($entry['transports']) ? $entry['transports'] : [];
            $entry['transports'] = array_values(array_intersect(array_map('sanitize_key', $transports), ['usb','nfc','ble','internal','hybrid','smart-card']));
            $out[] = $entry;
        }
        return array_slice($out, 0, self::max_per_user());
    }

    public static function count($user_id = 0) {
        return count(self::credentials($user_id));
    }

    public static function has_passkeys($user_id = 0) {
        return self::count($user_id) > 0;
    }

    private static function save_credentials($user_id, array $credentials) {
        $user_id = absint($user_id);
        if (!$user_id) return false;
        $credentials = array_slice(array_values($credentials), 0, self::max_per_user());
        return update_user_meta($user_id, self::META_CREDENTIALS, $credentials) !== false;
    }

    private static function browser_binding($create = false) {
        $token = DIP_Native_Auth::browser_token($create);
        if ($token === '') return '';
        return substr(hash_hmac('sha256', $token, wp_salt('secure_auth')), 0, 48);
    }

    private static function ceremony_key($id) {
        return self::CEREMONY_PREFIX . substr(hash_hmac('sha256', (string) $id, wp_salt('nonce')), 0, 40);
    }

    private static function lock_key($id) {
        return self::LOCK_PREFIX . substr(hash_hmac('sha256', (string) $id, wp_salt('secure_auth')), 0, 40);
    }

    private static function user_lock_key($user_id) {
        return self::LOCK_PREFIX . 'user_' . substr(hash_hmac('sha256', (string) absint($user_id), wp_salt('secure_auth')), 0, 36);
    }

    private static function acquire_user_lock($user_id, $ttl = 8) {
        $user_id = absint($user_id); if (!$user_id) return false;
        $key = self::user_lock_key($user_id); $now = time(); $ttl = min(20, max(3, absint($ttl)));
        if (add_option($key, $now, '', false)) return true;
        $existing = (int) get_option($key, 0);
        if ($existing > 0 && ($existing + $ttl) < $now) { delete_option($key); return add_option($key, $now, '', false); }
        return false;
    }

    private static function release_user_lock($user_id) { delete_option(self::user_lock_key(absint($user_id))); }

    private static function create_ceremony($purpose, $user_id, array $extra = []) {
        $binding = self::browser_binding(true);
        $rp_id = self::rp_id();
        $origin = self::expected_origin();
        if ($binding === '' || $rp_id === '' || $origin === '') return false;
        $id = self::random_b64u(24);
        $challenge = self::random_b64u(32);
        $state = array_merge([
            'purpose' => sanitize_key((string) $purpose),
            'user_id' => absint($user_id),
            'challenge' => $challenge,
            'browser' => $binding,
            'rp_id' => $rp_id,
            'origin' => $origin,
            'expires' => time() + self::CEREMONY_TTL,
        ], $extra);
        set_transient(self::ceremony_key($id), $state, self::CEREMONY_TTL);
        return ['id'=>$id, 'challenge'=>$challenge, 'state'=>$state];
    }

    private static function consume_ceremony($id, $purpose) {
        $id = sanitize_text_field((string) $id);
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{20,160}$/D', $id)) return new WP_Error('dip_passkey_ceremony_invalid', __('Session Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
        $lock = self::lock_key($id);
        $now = time();
        if (!add_option($lock, $now, '', false)) {
            $existing = (int) get_option($lock, 0);
            if ($existing > 0 && ($existing + 10) < $now) { delete_option($lock); }
            if (!add_option($lock, $now, '', false)) return new WP_Error('dip_passkey_ceremony_busy', __('Cette vérification est déjà en cours.', 'delicat-google-login'), ['status'=>409]);
        }
        try {
            $key = self::ceremony_key($id);
            $state = get_transient($key);
            delete_transient($key); // one-time regardless of outcome
            if (!is_array($state) || empty($state['purpose']) || empty($state['expires']) || time() > (int) $state['expires']) {
                return new WP_Error('dip_passkey_ceremony_expired', __('La vérification Passkey a expiré. Réessayez.', 'delicat-google-login'), ['status'=>410]);
            }
            if (!hash_equals((string) $state['purpose'], sanitize_key((string) $purpose))) {
                return new WP_Error('dip_passkey_ceremony_mismatch', __('Session Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
            }
            $binding = self::browser_binding(false);
            if ($binding === '' || empty($state['browser']) || !hash_equals((string) $state['browser'], $binding)) {
                return new WP_Error('dip_passkey_browser_mismatch', __('Cette vérification doit être terminée dans le même navigateur.', 'delicat-google-login'), ['status'=>403]);
            }
            return $state;
        } finally {
            delete_option($lock);
        }
    }

    private static function rate_key($scope, $identifier = '') {
        $ip = DIP_Native_Auth::client_ip();
        $material = $ip . '|' . strtolower(trim((string) $identifier));
        return 'dip_pk_rate_' . sanitize_key($scope) . '_' . substr(hash_hmac('sha256', $material, wp_salt('auth')), 0, 38);
    }

    private static function rate_allowed($scope, $identifier = '', $limit = 12, $window = 600) {
        $key = self::rate_key($scope, $identifier);
        $limit = min(200, max(1, absint($limit)));
        $window = min(DAY_IN_SECONDS, max(30, absint($window)));
        // Serialize the transient read-modify-write so parallel PHP workers (or
        // a persistent object cache) cannot all observe the same pre-increment
        // count and bypass the intended rate boundary.
        $lock = self::LOCK_PREFIX . 'rate_' . substr(hash_hmac('sha256', $key, wp_salt('secure_auth')), 0, 36);
        $now = time();
        if (!add_option($lock, $now, '', false)) {
            $existing = (int) get_option($lock, 0);
            if ($existing > 0 && ($existing + 5) < $now) { delete_option($lock); }
            if (!add_option($lock, $now, '', false)) return false;
        }
        try {
            $state = get_transient($key);
            if (!is_array($state) || empty($state['reset']) || (int) $state['reset'] <= $now) $state = ['count'=>0,'reset'=>$now+$window];
            if ((int) ($state['count'] ?? 0) >= $limit) return false;
            $state['count'] = (int) ($state['count'] ?? 0) + 1;
            set_transient($key, $state, max(1, (int)$state['reset'] - $now));
            return true;
        } finally {
            delete_option($lock);
        }
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/passkeys/login/options', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_login_options'],'permission_callback'=>'__return_true',
        ]);
        register_rest_route(self::REST_NAMESPACE, '/passkeys/login/verify', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_login_verify'],'permission_callback'=>'__return_true',
        ]);
        register_rest_route(self::REST_NAMESPACE, '/passkeys/register/options', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_register_options'],'permission_callback'=>[__CLASS__,'permission_sensitive_customer'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/passkeys/register/verify', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_register_verify'],'permission_callback'=>[__CLASS__,'permission_sensitive_customer'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/passkeys/register/proof', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_register_proof'],'permission_callback'=>[__CLASS__,'permission_sensitive_customer'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/passkeys/reauth/options', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_reauth_options'],'permission_callback'=>[__CLASS__,'permission_customer'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/passkeys/reauth/verify', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_reauth_verify'],'permission_callback'=>[__CLASS__,'permission_customer'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/passkeys/(?P<credential>[A-Za-z0-9_-]{20,160})', [
            'methods'=>'DELETE','callback'=>[__CLASS__,'rest_remove'],'permission_callback'=>[__CLASS__,'permission_sensitive_customer'],
        ]);
    }

    public static function permission_customer($request = null) {
        if (!is_user_logged_in()) return new WP_Error('dip_login_required', __('Connexion requise.', 'delicat-google-login'), ['status'=>401]);
        $uid = get_current_user_id();
        if (class_exists('DIP_Access_Guard') && !DIP_Access_Guard::can_self_service($uid)) return new WP_Error('dip_self_service_denied', __('Accès limité à votre propre compte.', 'delicat-google-login'), ['status'=>403]);
        if (class_exists('DIP_Account_Sync')) {
            $guard = DIP_Account_Sync::login_guard($uid);
            if (is_wp_error($guard)) return new WP_Error('dip_account_policy_denied', __('Ce compte ne peut pas utiliser cette fonction.', 'delicat-google-login'), ['status'=>403]);
        }
        if (!self::available()) return new WP_Error('dip_passkeys_unavailable', __('Passkeys nécessite HTTPS et OpenSSL.', 'delicat-google-login'), ['status'=>503]);
        return true;
    }

    public static function permission_sensitive_customer($request = null) {
        $base = self::permission_customer($request);
        if (is_wp_error($base)) return $base;
        if (class_exists('DIP_Reauth') && !DIP_Reauth::is_recent()) return new WP_Error('dip_reauth_required', __('Confirmez d’abord votre identité dans l’onglet Sécurité.', 'delicat-google-login'), ['status'=>428]);
        return true;
    }

    private static function json_body($request) {
        if ($request instanceof WP_REST_Request) {
            $data = $request->get_json_params();
            return is_array($data) ? $data : [];
        }
        return [];
    }

    private static function credential_descriptors(array $credentials) {
        $out = [];
        foreach ($credentials as $entry) {
            $id = sanitize_text_field((string) ($entry['id'] ?? ''));
            if ($id === '') continue;
            $descriptor = ['type'=>'public-key','id'=>$id];
            $transports = isset($entry['transports']) && is_array($entry['transports']) ? array_values(array_intersect($entry['transports'], ['usb','nfc','ble','internal','hybrid','smart-card'])) : [];
            if ($transports) $descriptor['transports'] = $transports;
            $out[] = $descriptor;
        }
        return $out;
    }

    /** Fixed-size, transport-free login descriptor list to reduce account/passkey-count enumeration. */
    private static function login_descriptors(array $credentials) {
        $out = [];
        foreach ($credentials as $entry) {
            $id = sanitize_text_field((string) ($entry['id'] ?? ''));
            if ($id !== '') $out[] = ['type'=>'public-key','id'=>$id];
        }
        while (count($out) < self::max_per_user()) $out[] = ['type'=>'public-key','id'=>self::random_b64u(32)];
        return array_slice($out, 0, self::max_per_user());
    }

    private static function options_response($ceremony, array $publicKey, array $extra = []) {
        return new WP_REST_Response(array_merge(['ceremony'=>$ceremony['id'],'publicKey'=>$publicKey], $extra), 200);
    }

    public static function rest_register_options($request) {
        $uid = get_current_user_id();
        $credentials = self::credentials($uid);
        if (count($credentials) >= self::max_per_user()) return new WP_Error('dip_passkey_limit', __('Nombre maximal de Passkeys atteint.', 'delicat-google-login'), ['status'=>409]);
        $user = get_userdata($uid);
        if (!$user) return new WP_Error('dip_user_missing', __('Compte introuvable.', 'delicat-google-login'), ['status'=>404]);
        $body = self::json_body($request);
        $label = sanitize_text_field((string) ($body['label'] ?? ''));
        if ($label === '') $label = __('Mon appareil', 'delicat-google-login');
        $label = function_exists('mb_substr') ? mb_substr($label, 0, 60) : substr($label, 0, 60);
        $ceremony = self::create_ceremony('register', $uid, ['label'=>$label]);
        if (!$ceremony) return new WP_Error('dip_passkey_start_failed', __('Impossible de démarrer l’enregistrement Passkey.', 'delicat-google-login'), ['status'=>500]);
        $publicKey = [
            'challenge'=>$ceremony['challenge'],
            'rp'=>['name'=>sanitize_text_field(get_bloginfo('name') ?: 'Delicat Store'),'id'=>self::rp_id()],
            'user'=>[
                'id'=>self::user_handle($uid),
                'name'=>sanitize_email($user->user_email) ?: sanitize_user($user->user_login),
                'displayName'=>sanitize_text_field($user->display_name ?: $user->user_login),
            ],
            'pubKeyCredParams'=>[
                ['type'=>'public-key','alg'=>-7],
                ['type'=>'public-key','alg'=>-257],
            ],
            'timeout'=>60000,
            'excludeCredentials'=>self::credential_descriptors($credentials),
            'authenticatorSelection'=>['residentKey'=>'preferred','userVerification'=>'required'],
            'attestation'=>'none',
        ];
        return self::options_response($ceremony, $publicKey);
    }

    private static function resolve_login_user($identifier) {
        $identifier = trim((string) $identifier);
        if ($identifier === '') return null;
        $user = is_email($identifier) ? get_user_by('email', sanitize_email($identifier)) : get_user_by('login', sanitize_user($identifier));
        if (!($user instanceof WP_User)) return null;
        if (class_exists('DIP_Account_Sync')) {
            $guard = DIP_Account_Sync::login_guard($user->ID);
            if (is_wp_error($guard)) return null;
        }
        return $user;
    }

    private static function safe_redirect($value, $fallback = '') {
        return DIP_Session_Router::destination($value, $fallback ?: home_url('/my-account/'));
    }

    private static function default_login_redirect() {
        $s = self::settings();
        return self::safe_redirect($s['native_login_redirect'] ?? '/my-account/', home_url('/my-account/'));
    }

    public static function rest_login_options($request) {
        if (!self::available()) return new WP_Error('dip_passkeys_unavailable', __('Connexion Passkey indisponible.', 'delicat-google-login'), ['status'=>503]);
        $body = self::json_body($request);
        $identifier = sanitize_text_field((string) ($body['identifier'] ?? $body['email'] ?? ''));
        if (!self::rate_allowed('login_options_ip', '', 30, 600) || !self::rate_allowed('login_options_account', $identifier, 12, 600)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_login_rate_limited', 'warning', 0);
            return new WP_Error('dip_passkey_rate_limited', __('Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'), ['status'=>429]);
        }
        $user = self::resolve_login_user($identifier);
        $uid = $user instanceof WP_User ? (int) $user->ID : 0;
        // A canonical account bucket prevents switching between username and
        // email from creating fresh Passkey-option quotas for the same account.
        if ($uid && !self::rate_allowed('login_options_uid', 'uid:' . $uid, 18, 600)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_login_rate_limited', 'warning', $uid);
            return new WP_Error('dip_passkey_rate_limited', __('Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'), ['status'=>429]);
        }
        $credentials = $uid ? self::credentials($uid) : [];
        // Keep the options shape generic for unknown accounts / accounts without
        // passkeys so this endpoint is not a simple account-enumeration API.
        $descriptors = self::login_descriptors($credentials);
        $remember = !empty($body['remember']);
        $redirect = self::safe_redirect((string) ($body['redirect'] ?? ''), self::default_login_redirect());
        $ceremony = self::create_ceremony('login', $credentials ? $uid : 0, ['remember'=>$remember?1:0,'redirect'=>$redirect]);
        if (!$ceremony) return new WP_Error('dip_passkey_start_failed', __('Impossible de démarrer la connexion Passkey.', 'delicat-google-login'), ['status'=>500]);
        return self::options_response($ceremony, [
            'challenge'=>$ceremony['challenge'],
            'rpId'=>self::rp_id(),
            'allowCredentials'=>$descriptors,
            'userVerification'=>'required',
            'timeout'=>60000,
        ]);
    }

    public static function rest_reauth_options($request) {
        $uid = get_current_user_id();
        $credentials = self::credentials($uid);
        if (!$credentials) return new WP_Error('dip_passkey_none', __('Aucune Passkey enregistrée pour ce compte.', 'delicat-google-login'), ['status'=>404]);
        if (!self::rate_allowed('reauth_options', (string)$uid, 12, 600)) return new WP_Error('dip_passkey_rate_limited', __('Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'), ['status'=>429]);
        $ceremony = self::create_ceremony('reauth', $uid);
        if (!$ceremony) return new WP_Error('dip_passkey_start_failed', __('Impossible de démarrer la confirmation Passkey.', 'delicat-google-login'), ['status'=>500]);
        return self::options_response($ceremony, [
            'challenge'=>$ceremony['challenge'],'rpId'=>self::rp_id(),
            'allowCredentials'=>self::credential_descriptors($credentials),'userVerification'=>'required','timeout'=>60000,
        ]);
    }

    private static function verify_client_data($encoded, $state, $type) {
        $raw = self::b64u_decode($encoded);
        if ($raw === false || strlen($raw) < 20 || strlen($raw) > 65536) return new WP_Error('dip_passkey_client_data', __('Données Passkey invalides.', 'delicat-google-login'), ['status'=>400]);
        $json = json_decode($raw, true);
        if (!is_array($json)) return new WP_Error('dip_passkey_client_json', __('Données Passkey invalides.', 'delicat-google-login'), ['status'=>400]);
        if (!isset($json['type']) || !hash_equals((string)$type, (string)$json['type'])) return new WP_Error('dip_passkey_type', __('Type de vérification Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
        if (empty($json['challenge']) || !hash_equals((string)$state['challenge'], (string)$json['challenge'])) return new WP_Error('dip_passkey_challenge', __('Challenge Passkey invalide.', 'delicat-google-login'), ['status'=>403]);
        if (empty($json['origin']) || !hash_equals((string)$state['origin'], rtrim((string)$json['origin'], '/'))) return new WP_Error('dip_passkey_origin', __('Origine Passkey refusée.', 'delicat-google-login'), ['status'=>403]);
        if (!empty($json['crossOrigin'])) return new WP_Error('dip_passkey_cross_origin', __('Vérification Passkey cross-origin refusée.', 'delicat-google-login'), ['status'=>403]);
        return ['raw'=>$raw,'json'=>$json];
    }

    private static function parse_authenticator_data($raw, $rp_id, $require_attested = false) {
        if (!is_string($raw) || strlen($raw) < 37) return new WP_Error('dip_passkey_auth_data', __('Données authenticator invalides.', 'delicat-google-login'), ['status'=>400]);
        $rp_hash = substr($raw, 0, 32);
        if (!hash_equals(hash('sha256', (string)$rp_id, true), $rp_hash)) return new WP_Error('dip_passkey_rp', __('Cette Passkey appartient à un autre domaine.', 'delicat-google-login'), ['status'=>403]);
        $flags = ord($raw[32]);
        if (($flags & 0x01) !== 0x01) return new WP_Error('dip_passkey_user_presence', __('Présence utilisateur requise.', 'delicat-google-login'), ['status'=>403]);
        if (($flags & 0x04) !== 0x04) return new WP_Error('dip_passkey_user_verification', __('Face ID, Touch ID, biométrie ou PIN appareil requis.', 'delicat-google-login'), ['status'=>403]);
        if ($require_attested && (($flags & 0x40) !== 0x40)) return new WP_Error('dip_passkey_attested_missing', __('Clé publique Passkey absente.', 'delicat-google-login'), ['status'=>400]);
        $count = unpack('Ncount', substr($raw, 33, 4));
        return ['flags'=>$flags,'sign_count'=>(int)($count['count'] ?? 0),'raw'=>$raw];
    }

    /** Minimal bounded CBOR decoder for WebAuthn attestation/COSE structures. */
    private static function cbor_read_length($data, &$offset, $additional) {
        $len = strlen($data);
        if ($additional < 24) return $additional;
        if ($additional === 24) { if ($offset + 1 > $len) return false; return ord($data[$offset++]); }
        if ($additional === 25) { if ($offset + 2 > $len) return false; $v=unpack('n',substr($data,$offset,2));$offset+=2;return (int)$v[1]; }
        if ($additional === 26) { if ($offset + 4 > $len) return false; $v=unpack('N',substr($data,$offset,4));$offset+=4;return (int)$v[1]; }
        if ($additional === 27) {
            if ($offset + 8 > $len) return false;
            $v=unpack('Nhi/Nlo',substr($data,$offset,8));$offset+=8;
            if (!isset($v['hi'],$v['lo']) || (int)$v['hi'] !== 0) return false; // reject unreasonably large values
            return (int)$v['lo'];
        }
        return false; // indefinite lengths intentionally unsupported
    }

    private static function cbor_decode_item($data, &$offset, $depth = 0) {
        if ($depth > 12 || $offset >= strlen($data)) return false;
        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $additional = $initial & 31;
        if ($major <= 6) {
            $length = self::cbor_read_length($data, $offset, $additional);
            if ($length === false || $length > 1048576) return false;
        }
        switch ($major) {
            case 0: return $length;
            case 1: return -1 - $length;
            case 2:
            case 3:
                if ($offset + $length > strlen($data)) return false;
                $value = substr($data, $offset, $length); $offset += $length; return $value;
            case 4:
                $arr=[]; for($i=0;$i<$length;$i++){ $v=self::cbor_decode_item($data,$offset,$depth+1); if($v===false) return false; $arr[]=$v; } return $arr;
            case 5:
                $map=[]; for($i=0;$i<$length;$i++){ $k=self::cbor_decode_item($data,$offset,$depth+1); if($k===false||(!is_int($k)&&!is_string($k))) return false; $v=self::cbor_decode_item($data,$offset,$depth+1); if($v===false) return false; $map[$k]=$v; } return $map;
            case 6: return self::cbor_decode_item($data,$offset,$depth+1);
            case 7:
                if ($additional === 20) return false;
                if ($additional === 21) return true;
                if ($additional === 22 || $additional === 23) return null;
                return false;
        }
        return false;
    }

    private static function cbor_decode($data) {
        $offset = 0;
        $value = self::cbor_decode_item($data, $offset, 0);
        if ($value === false || $offset !== strlen($data)) return false;
        return $value;
    }

    private static function der_length($len) {
        $len = (int) $len;
        if ($len < 128) return chr($len);
        $bytes=''; while($len>0){$bytes=chr($len&0xff).$bytes;$len>>=8;}
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function der_integer($bytes) {
        $bytes = ltrim((string)$bytes, "\x00");
        if ($bytes === '') $bytes = "\x00";
        if ((ord($bytes[0]) & 0x80) !== 0) $bytes = "\x00" . $bytes;
        return "\x02" . self::der_length(strlen($bytes)) . $bytes;
    }

    private static function der_sequence($bytes) { return "\x30" . self::der_length(strlen($bytes)) . $bytes; }
    private static function der_bit_string($bytes) { $bytes="\x00".$bytes; return "\x03".self::der_length(strlen($bytes)).$bytes; }
    private static function pem_public_key($der) { return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n"; }

    private static function cose_to_pem($cose) {
        if (!is_array($cose)) return false;
        $kty = isset($cose[1]) ? (int)$cose[1] : 0;
        $alg = isset($cose[3]) ? (int)$cose[3] : 0;
        if ($kty === 2 && $alg === -7) {
            $crv = isset($cose[-1]) ? (int)$cose[-1] : 0;
            $x = isset($cose[-2]) && is_string($cose[-2]) ? $cose[-2] : '';
            $y = isset($cose[-3]) && is_string($cose[-3]) ? $cose[-3] : '';
            if ($crv !== 1 || strlen($x)!==32 || strlen($y)!==32) return false;
            // SubjectPublicKeyInfo for id-ecPublicKey + prime256v1.
            $prefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');
            return ['pem'=>self::pem_public_key($prefix . "\x04" . $x . $y),'alg'=>-7];
        }
        if ($kty === 3 && $alg === -257) {
            $n = isset($cose[-1]) && is_string($cose[-1]) ? $cose[-1] : '';
            $e = isset($cose[-2]) && is_string($cose[-2]) ? $cose[-2] : '';
            if ($n === '' || $e === '' || strlen($n) > 1024 || strlen($e) > 8) return false;
            $rsa = self::der_sequence(self::der_integer($n) . self::der_integer($e));
            $alg_id = hex2bin('300D06092A864886F70D0101010500');
            $spki = self::der_sequence($alg_id . self::der_bit_string($rsa));
            return ['pem'=>self::pem_public_key($spki),'alg'=>-257];
        }
        return false;
    }

    private static function parse_attestation($encoded, $state) {
        $raw = self::b64u_decode($encoded);
        if ($raw === false || strlen($raw) < 50 || strlen($raw) > 262144) return new WP_Error('dip_passkey_attestation', __('Attestation Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
        $att = self::cbor_decode($raw);
        if (!is_array($att) || !isset($att['fmt'],$att['authData'],$att['attStmt']) || !is_string($att['authData']) || !is_array($att['attStmt'])) return new WP_Error('dip_passkey_attestation_cbor', __('Attestation Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
        // We explicitly request no attestation to avoid collecting device attestation
        // identity. For fmt=none the statement must be empty; credential possession
        // is proven immediately afterward with a UV-required assertion.
        if ((string)$att['fmt'] !== 'none' || !empty($att['attStmt'])) return new WP_Error('dip_passkey_attestation_format', __('Format d’attestation non attendu. Réessayez avec le navigateur à jour.', 'delicat-google-login'), ['status'=>400]);
        $auth = self::parse_authenticator_data($att['authData'], (string)$state['rp_id'], true);
        if (is_wp_error($auth)) return $auth;
        $data = $att['authData'];
        $offset = 37;
        if ($offset + 18 > strlen($data)) return new WP_Error('dip_passkey_attested_data', __('Données Passkey incomplètes.', 'delicat-google-login'), ['status'=>400]);
        $offset += 16; // AAGUID
        $v = unpack('nlen', substr($data,$offset,2)); $offset += 2;
        $cred_len = (int)($v['len'] ?? 0);
        if ($cred_len < 16 || $cred_len > 1024 || $offset + $cred_len > strlen($data)) return new WP_Error('dip_passkey_credential_id', __('Identifiant Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
        $credential_id = substr($data,$offset,$cred_len); $offset += $cred_len;
        $cose_offset = $offset;
        $cose = self::cbor_decode_item($data, $cose_offset, 0);
        if (!is_array($cose)) return new WP_Error('dip_passkey_public_key', __('Clé publique Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
        $converted = self::cose_to_pem($cose);
        if (!$converted || !openssl_pkey_get_public($converted['pem'])) return new WP_Error('dip_passkey_public_key_unsupported', __('Type de Passkey non pris en charge sur ce serveur.', 'delicat-google-login'), ['status'=>400]);
        return [
            'credential_id'=>self::b64u_encode($credential_id),
            'public_key'=>$converted['pem'],'alg'=>$converted['alg'],
            'sign_count'=>$auth['sign_count'],
        ];
    }

    private static function request_credential_id(array $body) {
        $raw_id = sanitize_text_field((string) ($body['rawId'] ?? ''));
        $id = sanitize_text_field((string) ($body['id'] ?? ''));
        if ($raw_id !== '' && self::b64u_decode($raw_id) === false) return '';
        if ($id !== '' && self::b64u_decode($id) === false) return '';
        // PublicKeyCredential.id is the base64url representation of rawId.
        // Reject contradictory client fields rather than silently preferring one.
        if ($raw_id !== '' && $id !== '' && !hash_equals($raw_id, $id)) return '';
        $value = $raw_id !== '' ? $raw_id : $id;
        return ($value !== '' && self::b64u_decode($value) !== false) ? $value : '';
    }

    private static function find_credential($user_id, $credential_id) {
        $credentials = self::credentials($user_id);
        foreach ($credentials as $index=>$entry) {
            if (!empty($entry['id']) && hash_equals((string)$entry['id'], (string)$credential_id)) return [$entry,$index,$credentials];
        }
        return [null,-1,$credentials];
    }

    private static function verify_assertion(array $body, array $state, $credential_override = null) {
        $uid = absint($state['user_id'] ?? 0);
        if (!$uid) return new WP_Error('dip_passkey_login_failed', __('Connexion Passkey impossible.', 'delicat-google-login'), ['status'=>401]);
        $credential_id = self::request_credential_id($body);
        if ($credential_id === '') return new WP_Error('dip_passkey_credential_id', __('Connexion Passkey impossible.', 'delicat-google-login'), ['status'=>401]);
        if (!self::acquire_user_lock($uid, 10)) return new WP_Error('dip_passkey_account_busy', __('Une autre vérification de sécurité est en cours. Réessayez.', 'delicat-google-login'), ['status'=>409]);
        try {
            $is_pending = is_array($credential_override);
            if ($is_pending) {
                $credential = $credential_override;
                $index = -1;
                $credentials = [];
                if (empty($credential['id']) || !hash_equals((string)$credential['id'], (string)$credential_id)) return new WP_Error('dip_passkey_unknown', __('Connexion Passkey impossible.', 'delicat-google-login'), ['status'=>401]);
            } else {
                list($credential,$index,$credentials)=self::find_credential($uid,$credential_id);
                if (!is_array($credential)) return new WP_Error('dip_passkey_unknown', __('Connexion Passkey impossible.', 'delicat-google-login'), ['status'=>401]);
            }
            $client = self::verify_client_data((string)($body['clientDataJSON'] ?? ''), $state, 'webauthn.get');
            if (is_wp_error($client)) return $client;
            $auth_raw = self::b64u_decode((string)($body['authenticatorData'] ?? ''));
            $signature = self::b64u_decode((string)($body['signature'] ?? ''));
            if ($auth_raw === false || $signature === false || strlen($signature) < 8 || strlen($signature) > 4096) return new WP_Error('dip_passkey_assertion', __('Signature Passkey invalide.', 'delicat-google-login'), ['status'=>401]);
            $auth = self::parse_authenticator_data($auth_raw, (string)$state['rp_id'], false);
            if (is_wp_error($auth)) return $auth;
            $signed = $auth_raw . hash('sha256', $client['raw'], true);
            $public = openssl_pkey_get_public((string)$credential['public_key']);
            if (!$public) return new WP_Error('dip_passkey_key_read', __('Clé Passkey invalide.', 'delicat-google-login'), ['status'=>500]);
            $verified = openssl_verify($signed, $signature, $public, OPENSSL_ALGO_SHA256);
            if ($verified !== 1) return new WP_Error('dip_passkey_signature', __('Signature Passkey refusée.', 'delicat-google-login'), ['status'=>401]);
            $user_handle = (string)($body['userHandle'] ?? '');
            if ($user_handle !== '' && !hash_equals(self::user_handle($uid), $user_handle)) return new WP_Error('dip_passkey_user_handle', __('Passkey liée à un autre compte.', 'delicat-google-login'), ['status'=>403]);
            $stored_count = max(0,(int)($credential['sign_count'] ?? 0));
            $new_count = max(0,(int)$auth['sign_count']);
            if ($stored_count > 0 && $new_count > 0 && $new_count <= $stored_count) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_counter_replay_blocked','critical',$uid,['credential'=>substr(hash('sha256',$credential_id),0,16)]);
                return new WP_Error('dip_passkey_counter', __('Cette Passkey présente un compteur de sécurité incohérent. Utilisez une autre méthode et vérifiez vos appareils.', 'delicat-google-login'), ['status'=>403]);
            }
            $credential['sign_count'] = max($stored_count,$new_count);
            $credential['last_used'] = time();
            if (!$is_pending) {
                $credentials[$index] = $credential;
                self::save_credentials($uid,$credentials);
            }
            return ['user_id'=>$uid,'credential'=>$credential];
        } finally {
            self::release_user_lock($uid);
        }
    }

    public static function rest_register_verify($request) {
        $body = self::json_body($request);
        $state = self::consume_ceremony((string)($body['ceremony'] ?? ''), 'register');
        if (is_wp_error($state)) return $state;
        $uid = get_current_user_id();
        if (!$uid || absint($state['user_id'] ?? 0) !== $uid) return new WP_Error('dip_passkey_user_mismatch', __('Session Passkey liée à un autre compte.', 'delicat-google-login'), ['status'=>403]);
        $client = self::verify_client_data((string)($body['clientDataJSON'] ?? ''), $state, 'webauthn.create');
        if (is_wp_error($client)) return $client;
        $att = self::parse_attestation((string)($body['attestationObject'] ?? ''), $state);
        if (is_wp_error($att)) return $att;
        $request_id = self::request_credential_id($body);
        if ($request_id === '' || !hash_equals((string)$att['credential_id'], $request_id)) return new WP_Error('dip_passkey_id_mismatch', __('Identifiant Passkey incohérent.', 'delicat-google-login'), ['status'=>400]);
        $credentials = self::credentials($uid);
        foreach ($credentials as $entry) if (hash_equals((string)$entry['id'], $request_id)) return new WP_Error('dip_passkey_exists', __('Cette Passkey est déjà enregistrée.', 'delicat-google-login'), ['status'=>409]);
        if (count($credentials) >= self::max_per_user()) return new WP_Error('dip_passkey_limit', __('Nombre maximal de Passkeys atteint.', 'delicat-google-login'), ['status'=>409]);
        $transports = isset($body['transports']) && is_array($body['transports']) ? array_values(array_intersect(array_map('sanitize_key',$body['transports']), ['usb','nfc','ble','internal','hybrid','smart-card'])) : [];
        $entry = [
            'id'=>$att['credential_id'],'public_key'=>$att['public_key'],'alg'=>(int)$att['alg'],'sign_count'=>(int)$att['sign_count'],
            // AAGUID is intentionally not persisted: it is unnecessary for our
            // verification policy and would add avoidable authenticator metadata.
            'transports'=>$transports,
            'label'=>sanitize_text_field((string)($state['label'] ?? __('Mon appareil', 'delicat-google-login'))),
            'created'=>time(),'last_used'=>0,
        ];
        // Privacy-preserving "none" attestation carries no device attestation
        // signature. Require an immediate assertion from the newly-created
        // credential before persisting it, proving possession of the private key.
        $proof = self::create_ceremony('register_proof', $uid, ['pending_credential'=>$entry]);
        if (!$proof) return new WP_Error('dip_passkey_proof_start', __('Impossible de vérifier la possession de cette Passkey.', 'delicat-google-login'), ['status'=>500]);
        return self::options_response($proof, [
            'challenge'=>$proof['challenge'],'rpId'=>self::rp_id(),
            'allowCredentials'=>self::credential_descriptors([$entry]),'userVerification'=>'required','timeout'=>60000,
        ], ['requiresProof'=>true]);
    }

    public static function rest_register_proof($request) {
        $body=self::json_body($request);
        $state=self::consume_ceremony((string)($body['ceremony']??''),'register_proof');
        if(is_wp_error($state)) return $state;
        $uid=get_current_user_id();
        if(!$uid || absint($state['user_id']??0)!==$uid || empty($state['pending_credential']) || !is_array($state['pending_credential'])) return new WP_Error('dip_passkey_user_mismatch',__('Session Passkey liée à un autre compte.','delicat-google-login'),['status'=>403]);
        $entry=$state['pending_credential'];
        $result=self::verify_assertion($body,$state,$entry);
        if(is_wp_error($result)){if(class_exists('DIP_Audit'))DIP_Audit::record('passkey_registration_proof_failed','warning',$uid);return $result;}
        if(!self::acquire_user_lock($uid,10)) return new WP_Error('dip_passkey_account_busy',__('Une autre modification de sécurité est en cours. Réessayez.','delicat-google-login'),['status'=>409]);
        try {
            $credentials=self::credentials($uid);
            foreach($credentials as $existing) if(!empty($existing['id'])&&hash_equals((string)$existing['id'],(string)$entry['id'])) return new WP_Error('dip_passkey_exists',__('Cette Passkey est déjà enregistrée.','delicat-google-login'),['status'=>409]);
            if(count($credentials)>=self::max_per_user()) return new WP_Error('dip_passkey_limit',__('Nombre maximal de Passkeys atteint.','delicat-google-login'),['status'=>409]);
            $entry=$result['credential'];
            // The proof itself is the first use, but present it as newly enrolled.
            $entry['created']=time();
            $credentials[]=$entry;
            if(!self::save_credentials($uid,$credentials)) return new WP_Error('dip_passkey_save',__('Impossible d’enregistrer cette Passkey.','delicat-google-login'),['status'=>500]);
        } finally { self::release_user_lock($uid); }
        if(class_exists('DIP_Audit'))DIP_Audit::record('passkey_registered','notice',$uid,['alg'=>(int)$entry['alg'],'transport'=>implode(',',(array)$entry['transports']),'proof'=>1]);
        self::security_email($uid,__('Nouvelle Passkey ajoutée à votre compte Delicat','delicat-google-login'),__('Une nouvelle Passkey a été enregistrée et sa clé privée a été vérifiée. Si vous n’êtes pas à l’origine de cette action, changez votre mot de passe et supprimez immédiatement cette Passkey depuis le Centre de sécurité.','delicat-google-login'));
        return new WP_REST_Response(['success'=>true,'message'=>__('Passkey ajoutée et vérifiée avec succès.','delicat-google-login'),'count'=>count($credentials)],200);
    }

    public static function rest_login_verify($request) {
        $body = self::json_body($request);
        $state = self::consume_ceremony((string)($body['ceremony'] ?? ''), 'login');
        if (is_wp_error($state)) return $state;
        if (!self::rate_allowed('login_verify_ip','',30,600)) return new WP_Error('dip_passkey_rate_limited', __('Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'), ['status'=>429]);
        $state_uid = absint($state['user_id'] ?? 0);
        if ($state_uid && !self::rate_allowed('login_verify_uid','uid:' . $state_uid,18,600)) return new WP_Error('dip_passkey_rate_limited', __('Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'), ['status'=>429]);
        $result = self::verify_assertion($body,$state);
        if (is_wp_error($result)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_login_failed','warning',absint($state['user_id'] ?? 0));
            return $result;
        }
        $uid = absint($result['user_id']);
        $user = get_userdata($uid);
        if (!$user) return new WP_Error('dip_passkey_user_missing', __('Connexion Passkey impossible.', 'delicat-google-login'), ['status'=>401]);
        if (class_exists('DIP_Account_Sync')) {
            $guard = DIP_Account_Sync::login_guard($uid);
            if (is_wp_error($guard)) return new WP_Error('dip_account_policy_denied', __('Ce compte ne peut pas se connecter.', 'delicat-google-login'), ['status'=>403]);
        }
        wp_clear_auth_cookie();
        wp_set_current_user($uid);
        if (class_exists('DIP_Two_Factor')) DIP_Two_Factor::authorize_cookie_once($uid);
        wp_set_auth_cookie($uid, !empty($state['remember']), is_ssl());
        DIP_Account_Sync::fire_wp_login($user,'passkey');
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_login($uid);
        do_action('dip_login_success',$uid,['provider'=>'passkey','user_verification'=>1]);
        if (class_exists('DIP_Reauth')) DIP_Reauth::mark_recent($uid);
        if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_login_success','info',$uid,['credential'=>substr(hash('sha256',(string)$result['credential']['id']),0,16)]);
        $redirect = self::safe_redirect((string)($state['redirect'] ?? ''),self::default_login_redirect());
        $redirect = add_query_arg('dip_auth_sync', DIP_Session_Router::sync_marker(), remove_query_arg('dip_auth_sync', $redirect));
        return new WP_REST_Response(['success'=>true,'redirect'=>$redirect], 200);
    }

    public static function rest_reauth_verify($request) {
        $body=self::json_body($request);
        $state=self::consume_ceremony((string)($body['ceremony']??''),'reauth');
        if(is_wp_error($state)) return $state;
        $uid=get_current_user_id();
        if(!$uid || absint($state['user_id']??0)!==$uid) return new WP_Error('dip_passkey_user_mismatch',__('Session Passkey liée à un autre compte.','delicat-google-login'),['status'=>403]);
        if(!self::rate_allowed('reauth_verify',(string)$uid,12,600)) return new WP_Error('dip_passkey_rate_limited',__('Trop de tentatives. Réessayez plus tard.','delicat-google-login'),['status'=>429]);
        $result=self::verify_assertion($body,$state);
        if(is_wp_error($result)){ if(class_exists('DIP_Audit'))DIP_Audit::record('passkey_reauth_failed','warning',$uid); return $result; }
        if(class_exists('DIP_Reauth')) DIP_Reauth::mark_recent($uid);
        if(class_exists('DIP_Audit')) DIP_Audit::record('passkey_reauth_success','info',$uid);
        return new WP_REST_Response(['success'=>true,'message'=>__('Identité confirmée avec votre Passkey.','delicat-google-login')],200);
    }

    private static function has_other_primary_login_method($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return false;
        if (class_exists('DIP_Identity') && get_user_meta($user_id, DIP_Identity::META_PASSWORD_MANAGED, true) !== 'yes') return true;
        if (class_exists('DIP_Connected_Accounts')) {
            foreach ((array) DIP_Connected_Accounts::status($user_id) as $status) {
                if (!empty($status['connected'])) return true;
            }
        }
        return false;
    }

    public static function rest_remove($request) {
        $uid=get_current_user_id();
        $id=sanitize_text_field((string)$request->get_param('credential'));
        if(!self::acquire_user_lock($uid,10)) return new WP_Error('dip_passkey_account_busy',__('Une autre modification de sécurité est en cours. Réessayez.','delicat-google-login'),['status'=>409]);
        try {
            $credentials=self::credentials($uid); $kept=[]; $removed=null;
            foreach($credentials as $entry){ if(!$removed && !empty($entry['id']) && hash_equals((string)$entry['id'],$id)){$removed=$entry;continue;} $kept[]=$entry; }
            if(!$removed) return new WP_Error('dip_passkey_not_found',__('Passkey introuvable.','delicat-google-login'),['status'=>404]);
            if(!$kept && !self::has_other_primary_login_method($uid)) return new WP_Error('dip_passkey_last_method',__('Ajoutez d’abord un mot de passe utilisable ou reliez Google/Microsoft avant de supprimer votre dernière Passkey.','delicat-google-login'),['status'=>409]);
            self::save_credentials($uid,$kept);
        } finally { self::release_user_lock($uid); }
        if(class_exists('DIP_Audit'))DIP_Audit::record('passkey_removed','warning',$uid,['credential'=>substr(hash('sha256',$id),0,16)]);
        self::security_email($uid,__('Passkey supprimée de votre compte Delicat','delicat-google-login'),__('Une Passkey a été supprimée de votre compte. Si vous n’êtes pas à l’origine de cette action, changez votre mot de passe et vérifiez immédiatement vos sessions.','delicat-google-login'));
        return new WP_REST_Response(['success'=>true,'message'=>__('Passkey supprimée.','delicat-google-login'),'count'=>count($kept)],200);
    }

    private static function security_email($user_id,$subject,$message){
        $user=get_userdata(absint($user_id)); if(!$user||!is_email($user->user_email)) return false;
        return DIP_Email_Hub::send_user_security($user->ID,$subject,$message,['label'=>__('Passkeys','delicat-google-login')]);
    }

    public static function protect_script_tag($tag,$handle){
        if($handle!=='dip-passkeys') return $tag;
        if(strpos($tag,'data-cfasync=')===false) $tag=str_replace('<script ','<script data-cfasync="false" data-no-optimize="1" ',$tag);
        return $tag;
    }

    public static function frontend_config(){
        return [
            'rest'=>untrailingslashit(wp_make_link_relative(rest_url(self::REST_NAMESPACE))),
            'nonce'=>is_user_logged_in()?wp_create_nonce('wp_rest'):'',
            'loggedIn'=>is_user_logged_in()?1:0,
            'available'=>self::available()?1:0,
            'redirect'=>self::default_login_redirect(),
            'strings'=>[
                'unsupported'=>__('Ce navigateur ou cet appareil ne prend pas en charge les Passkeys.','delicat-google-login'),
                'emailRequired'=>__('Entrez d’abord votre adresse e-mail.','delicat-google-login'),
                'cancelled'=>__('Vérification Passkey annulée ou indisponible.','delicat-google-login'),
                'network'=>__('Impossible de joindre le service Passkey. Réessayez.','delicat-google-login'),
                'removeConfirm'=>__('Supprimer cette Passkey de votre compte ?','delicat-google-login'),
            ],
        ];
    }

    public static function assets(){
        $s=self::settings(); if(($s['passkeys_available']??'yes')!=='yes') return;
        $is_account=function_exists('is_account_page') && is_account_page();
        $is_wp_login=did_action('login_enqueue_scripts') || (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow']==='wp-login.php');
        $modal_eager=DIP_Native_Auth::should_use_eager_assets();
        $is_login_modal=!is_user_logged_in() && (($s['native_modal_enabled']??'yes')==='yes') && $modal_eager;
        if(!$is_account && !$is_wp_login && !$is_login_modal) return;
        wp_enqueue_style('dip-passkeys',DIP_URL.'assets/passkeys.css',[],DIP_VERSION);
        wp_enqueue_script('dip-passkeys',DIP_URL.'assets/passkeys.js',[],DIP_VERSION,true);
        wp_localize_script('dip-passkeys','DIPPasskeys',self::frontend_config());
    }

    public static function render_settings($user_id=0){
        $user_id=absint($user_id?:get_current_user_id()); if(!$user_id||!is_user_logged_in()||$user_id!==get_current_user_id()) return '';
        $credentials=self::credentials($user_id); $available=self::available();
        ob_start(); ?>
        <div class="dip-security-card dip-passkeys-card" data-dip-passkeys-card>
          <div class="dip-security-card-title"><span class="dip-security-card-icon dip-passkey-icon" aria-hidden="true">◆</span><div><h3><?php esc_html_e('Passkeys','delicat-google-login'); ?></h3><p><?php esc_html_e('Face ID, Touch ID, biométrie Android, Windows Hello ou clé de sécurité. La clé privée reste sur votre appareil.','delicat-google-login'); ?></p></div></div>
          <?php if(!$available): ?><div class="dip-security-notice"><?php esc_html_e('Passkeys nécessite HTTPS, OpenSSL et un navigateur compatible.','delicat-google-login'); ?></div><?php endif; ?>
          <?php if($credentials): ?><div class="dip-passkey-list"><?php foreach($credentials as $entry): ?>
            <div class="dip-passkey-item"><div><strong><?php echo esc_html($entry['label']); ?></strong><span><?php echo esc_html($entry['last_used']?sprintf(__('Dernière utilisation : %s','delicat-google-login'),wp_date('j M Y H:i',$entry['last_used'])):sprintf(__('Ajoutée : %s','delicat-google-login'),wp_date('j M Y H:i',$entry['created']))); ?></span></div><button type="button" class="dip-btn ghost" data-dip-passkey-remove="<?php echo esc_attr($entry['id']); ?>"<?php disabled(!$available); ?>><?php esc_html_e('Supprimer','delicat-google-login'); ?></button></div>
          <?php endforeach; ?></div><?php else: ?><p class="dip-security-empty"><?php esc_html_e('Aucune Passkey enregistrée.','delicat-google-login'); ?></p><?php endif; ?>
          <div class="dip-passkey-actions">
            <label class="dip-passkey-label"><span><?php esc_html_e('Nom de l’appareil','delicat-google-login'); ?></span><input type="text" maxlength="60" value="<?php echo esc_attr__('Mon appareil','delicat-google-login'); ?>" data-dip-passkey-label></label>
            <button type="button" class="dip-btn primary" data-dip-passkey-register<?php disabled(!$available || count($credentials)>=self::max_per_user()); ?>><?php esc_html_e('Ajouter une Passkey','delicat-google-login'); ?></button>
            <?php if($credentials): ?><button type="button" class="dip-btn" data-dip-passkey-reauth<?php disabled(!$available); ?>><?php esc_html_e('Confirmer avec une Passkey','delicat-google-login'); ?></button><?php endif; ?>
          </div>
          <div class="dip-passkey-status" data-dip-passkey-status role="status" aria-live="polite" hidden></div>
        </div>
        <?php return ob_get_clean();
    }

    public static function render_standard_login_button(){
        if(is_user_logged_in() || !self::available()) return;
        echo '<div class="dip-passkey-standard"><button type="button" class="dip-passkey-external" data-dip-passkey-login><span aria-hidden="true"></span><strong>'.esc_html__('Se connecter avec une Passkey','delicat-google-login').'</strong></button><div class="dip-passkey-status" data-dip-passkey-status role="status" aria-live="polite" hidden></div></div>';
    }

    public static function render_login_button(){
        if(!self::available()) return '';
        return '<button type="button" class="dipx-passkey-login" data-dip-passkey-login><span aria-hidden="true"></span><strong>'.esc_html__('Continuer avec une Passkey','delicat-google-login').'</strong><small>'.esc_html__('Face ID • Touch ID • Biométrie','delicat-google-login').'</small></button>';
    }

    public static function cli_reset($args,$assoc_args=[]){
        if(!(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))) return;
        $identifier=isset($args[0])?trim((string)$args[0]):'';
        $user=ctype_digit($identifier)?get_userdata(absint($identifier)):get_user_by('login',$identifier);
        if(!$user&&is_email($identifier))$user=get_user_by('email',sanitize_email($identifier));
        if(!($user instanceof WP_User)){\WP_CLI::error('User not found.');return;}
        delete_user_meta($user->ID,self::META_CREDENTIALS); delete_user_meta($user->ID,self::META_USER_HANDLE);
        if(class_exists('WP_Session_Tokens'))WP_Session_Tokens::get_instance($user->ID)->destroy_all();
        if(class_exists('DIP_Mobile_API'))DIP_Mobile_API::revoke_all_for_user($user->ID,'passkeys_cli_reset');
        if(class_exists('DIP_Audit'))DIP_Audit::record('passkeys_cli_reset','critical',$user->ID);
        \WP_CLI::success('Delicat Passkeys reset for user '.$user->ID.'. All sessions were revoked.');
    }
}
