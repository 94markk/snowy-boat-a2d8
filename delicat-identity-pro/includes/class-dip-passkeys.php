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
 *
 * 6.11.0: the WebAuthn helpers marked @internal are public so that
 * DIP_Mobile_Passkeys (the app's Credential Manager routes) reuses the same
 * CBOR, COSE, authenticator-data and assertion checks.
 *
 * 6.12.0 (website):
 * - sign-in can be discoverable: without an e-mail, login/options returns an
 *   empty allowCredentials and login/verify finds the account from the
 *   assertion's userHandle (exactly one account), then requires the
 *   credential to belong to it — the app's model. With an e-mail it works as
 *   before, with deterministic padding of the descriptor list. Passkey
 *   autofill (conditional mediation) uses the discoverable ceremony;
 * - enrolment is ONE system sheet: register/verify stores the credential.
 *   Recent authentication is checked at register/options and recorded in the
 *   ceremony (single use, bound to the user, the browser and 5 minutes);
 *   register/proof keeps answering for pages still running the old script;
 * - stored passkeys are never truncated: the per-account maximum is enforced
 *   only when adding one;
 * - only refused assertions are counted (owner rule: no usage limit), plus
 *   flood ceilings that no person reaches;
 * - CSS/JS/config are enqueued by the markup that needs them.
 */
final class DIP_Passkeys {
    const META_CREDENTIALS = '_dip_passkeys_v1';
    const META_USER_HANDLE = '_dip_passkey_user_handle_v1';
    const CEREMONY_PREFIX = 'dip_pk_ceremony_';
    const LOCK_PREFIX = 'dip_pk_lock_';
    const REST_NAMESPACE = 'delicat-identity/v1';
    const CEREMONY_TTL = 300;
    /** WebAuthn prompt timeout, as in the app (DIP_Mobile_Passkeys::TIMEOUT_MS). */
    const TIMEOUT_MS = 120000;
    /** Sanity bound on stored entries (corrupted meta); the real limit is max_per_user() at enrolment. */
    const STORED_SANITY_CAP = 50;
    const TRANSPORTS = ['usb','nfc','ble','internal','hybrid','smart-card'];

    private static $initialized = false;
    private static $runtime_enqueued = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 45);
        add_action('login_enqueue_scripts', [__CLASS__, 'assets'], 45);
        add_action('login_form', [__CLASS__, 'render_standard_login_button'], 40);
        add_action('woocommerce_login_form_end', [__CLASS__, 'render_standard_login_button'], 40);
        // wp_login_form() (themes, widgets, [delicat_auth_panel]) never fires the login_form action.
        add_filter('login_form_middle', [__CLASS__, 'login_form_middle'], 40, 2);
        add_filter('script_loader_tag', [__CLASS__, 'protect_script_tag'], 10, 2);
        add_filter('wp_inline_script_attributes', [__CLASS__, 'protect_inline_config'], 10, 2);
        add_filter('dip_customer_rest_rules', [__CLASS__, 'firewall_rules']);
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

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function max_per_user() {
        $s = self::settings();
        return min(12, max(1, absint($s['passkeys_max_per_user'] ?? 8)));
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function b64u_encode($bytes) {
        return rtrim(strtr(base64_encode((string) $bytes), '+/', '-_'), '=');
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function b64u_decode($value) {
        $value = preg_replace('/\s+/', '', (string) $value);
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) return false;
        $pad = strlen($value) % 4;
        if ($pad) $value .= str_repeat('=', 4 - $pad);
        $raw = base64_decode(strtr($value, '-_', '+/'), true);
        return $raw === false ? false : $raw;
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function random_b64u($bytes = 32) {
        try { return self::b64u_encode(random_bytes(max(16, absint($bytes)))); }
        catch (Throwable $e) { return self::b64u_encode(hash('sha256', wp_generate_password(64, true, true) . microtime(true), true)); }
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function rp_id() {
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

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function user_handle($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return '';
        $handle = (string) get_user_meta($user_id, self::META_USER_HANDLE, true);
        if ($handle !== '' && self::b64u_decode($handle) !== false) return $handle;
        $handle = self::random_b64u(32);
        update_user_meta($user_id, self::META_USER_HANDLE, $handle);
        return $handle;
    }

    /**
     * Every valid stored passkey of the account. 6.12.0: never truncated to
     * max_per_user() — lowering the setting used to hide, then delete at the
     * next sign-in, the passkeys above the new limit.
     */
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
            $entry['transports'] = array_values(array_intersect(array_map('sanitize_key', array_filter($transports, 'is_scalar')), self::TRANSPORTS));
            // Optional since 6.12.0 (backup eligibility/state, credProps.rk): absent on older entries.
            foreach (['be', 'bs', 'discoverable'] as $flag) {
                if (isset($entry[$flag])) $entry[$flag] = empty($entry[$flag]) ? 0 : 1;
            }
            $out[] = $entry;
            if (count($out) >= self::STORED_SANITY_CAP) break;
        }
        return $out;
    }

    public static function count($user_id = 0) {
        return count(self::credentials($user_id));
    }

    public static function has_passkeys($user_id = 0) {
        return self::count($user_id) > 0;
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys. 6.12.0: no max_per_user() truncation. */
    public static function save_credentials($user_id, array $credentials) {
        $user_id = absint($user_id);
        if (!$user_id) return false;
        $credentials = array_slice(array_values($credentials), 0, self::STORED_SANITY_CAP);
        return update_user_meta($user_id, self::META_CREDENTIALS, $credentials) !== false
            || get_user_meta($user_id, self::META_CREDENTIALS, true) === $credentials;
    }

    /** Scalar request field as a string ('' for arrays/objects: no PHP warning on hostile JSON). */
    private static function str_field(array $body, $key) {
        return isset($body[$key]) && is_scalar($body[$key]) ? (string) $body[$key] : '';
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

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function acquire_user_lock($user_id, $ttl = 8) {
        $user_id = absint($user_id); if (!$user_id) return false;
        $key = self::user_lock_key($user_id); $now = time(); $ttl = min(20, max(3, absint($ttl)));
        if (add_option($key, $now, '', false)) return true;
        $existing = (int) get_option($key, 0);
        if ($existing > 0 && ($existing + $ttl) < $now) { delete_option($key); return add_option($key, $now, '', false); }
        return false;
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function release_user_lock($user_id) { delete_option(self::user_lock_key(absint($user_id))); }

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
        // Sensitive ceremonies belong to this exact authenticated session, not
        // just to a reusable browser cookie (logout/re-login must invalidate them).
        if ($purpose !== 'login') {
            $token = wp_get_session_token();
            if ($token === '') return false;
            $state['session'] = hash_hmac('sha256', $token, wp_salt('secure_auth'));
        }
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
            if (isset($state['session'])) {
                $token = wp_get_session_token();
                if ($token === '' || !hash_equals((string) $state['session'], hash_hmac('sha256', $token, wp_salt('secure_auth')))) {
                    return new WP_Error('dip_passkey_session_changed', __('Votre session a changé. Recommencez la vérification.', 'delicat-google-login'), ['status'=>403]);
                }
            }
            return $state;
        } finally {
            delete_option($lock);
        }
    }

    /**
     * 6.11.0 (owner rule of 2026-10-24: « I don't want a client to have a
     * limit using the application »): the per-address buckets of the website
     * passkey sign-in. They count in Identity Pro's counter table
     * (DIP_Lockout::counter_hit(), never the object cache), in a fixed
     * 10-minute window, on the visitor address behind Cloudflare
     * (DIP_Native_Auth::throttle_ip(), like the mobile routes) instead of the
     * shared edge address client_ip() returns without the opt-in. login/options
     * stores a ceremony on every call, so the address keeps a flood ceiling,
     * 20 times the old 30; login/verify counts refused assertions only (100
     * per address, 18 per account and address), read before the check.
     *
     * 6.12.0: the last transient buckets (12 option requests per e-mail, 18
     * per account, 12 confirmations) counted successful uses, answered 429
     * under lock contention and let anyone lock a customer's passkey sign-in
     * by naming the e-mail. They are gone: confirmations count refused
     * assertions only (per account), and option routes keep flood ceilings.
     */
    const LOGIN_ADDRESS_WINDOW = 600;
    const LOGIN_OPTIONS_ADDRESS_LIMIT = 600;
    const LOGIN_VERIFY_ADDRESS_FAILURES = 100;
    const LOGIN_VERIFY_ACCOUNT_FAILURES = 18;
    const REAUTH_OPTIONS_FLOOD = 200;
    const REAUTH_VERIFY_FAILURES = 12;

    private static function login_counter_key($scope, $identifier = '') {
        $material = DIP_Native_Auth::throttle_ip() . '|' . strtolower(trim((string) $identifier));
        return 'dip_pk_' . sanitize_key($scope) . '_' . substr(hash_hmac('sha256', $material, wp_salt('nonce')), 0, 32);
    }

    /** Per-account bucket of the logged-in confirmation routes (the session already names the account). */
    private static function account_counter_key($scope, $user_id) {
        return 'dip_pk_' . sanitize_key($scope) . '_' . substr(hash_hmac('sha256', 'uid:' . absint($user_id), wp_salt('nonce')), 0, 32);
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/passkeys/login/options', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_login_options'],'permission_callback'=>[__CLASS__,'permission_browser'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/passkeys/login/verify', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_login_verify'],'permission_callback'=>[__CLASS__,'permission_browser'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/passkeys/register/options', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_register_options'],'permission_callback'=>[__CLASS__,'permission_sensitive_customer'],
        ]);
        // Recent authentication was checked by register/options and is carried by the ceremony.
        register_rest_route(self::REST_NAMESPACE, '/passkeys/register/verify', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_register_verify'],'permission_callback'=>[__CLASS__,'permission_customer'],
        ]);
        // Kept for pages still running the pre-6.12.0 script (two-step enrolment).
        register_rest_route(self::REST_NAMESPACE, '/passkeys/register/proof', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_register_proof'],'permission_callback'=>[__CLASS__,'permission_sensitive_customer'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/passkeys/reauth/options', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_reauth_options'],'permission_callback'=>[__CLASS__,'permission_customer'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/passkeys/reauth/verify', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_reauth_verify'],'permission_callback'=>[__CLASS__,'permission_customer'],
        ]);
        // WebAuthn credential ids reach 1023 bytes (1366 base64url characters).
        register_rest_route(self::REST_NAMESPACE, '/passkeys/(?P<credential>[A-Za-z0-9_-]{20,1366})', [
            'methods'=>'DELETE','callback'=>[__CLASS__,'rest_remove'],'permission_callback'=>[__CLASS__,'permission_sensitive_customer'],
        ]);
    }

    /**
     * Strict REST firewall (DIP_Security_Architecture): the removal route
     * accepts every WebAuthn credential id length (its own pattern stops at
     * 160 characters, i.e. ids up to 120 bytes).
     */
    public static function firewall_rules($rules) {
        $rules = is_array($rules) ? $rules : [];
        $rules[] = ['#^/delicat-identity/v1/passkeys/[A-Za-z0-9_-]{20,1366}$#', ['DELETE']];
        return $rules;
    }

    /** Same-origin browser ceremonies; native apps use the separate /mobile routes. */
    public static function permission_browser($request = null) {
        if (!$request instanceof WP_REST_Request) return true;
        $origin = trim((string) $request->get_header('origin'));
        $site = strtolower(trim((string) $request->get_header('sec-fetch-site')));
        if (($origin !== '' && !hash_equals(self::expected_origin(), $origin)) || $site === 'cross-site') {
            return new WP_Error('dip_passkey_request_origin', __('Ouvrez la connexion depuis le site officiel Delicat Store.', 'delicat-google-login'), ['status'=>403]);
        }
        return true;
    }

    public static function permission_customer($request = null) {
        $browser = self::permission_browser($request);
        if (is_wp_error($browser)) return $browser;
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
        if (class_exists('DIP_Reauth') && !DIP_Reauth::is_recent()) return self::reauth_required_error();
        return true;
    }

    private static function reauth_required_error() {
        return new WP_Error('dip_reauth_required', __('Confirmez d’abord votre identité pour continuer.', 'delicat-google-login'), ['status'=>428]);
    }

    private static function json_body($request) {
        if ($request instanceof WP_REST_Request) {
            $data = $request->get_json_params();
            return is_array($data) ? $data : [];
        }
        return [];
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function credential_descriptors(array $credentials) {
        $out = [];
        foreach ($credentials as $entry) {
            $id = sanitize_text_field((string) ($entry['id'] ?? ''));
            if ($id === '') continue;
            $descriptor = ['type'=>'public-key','id'=>$id];
            $transports = isset($entry['transports']) && is_array($entry['transports']) ? array_values(array_intersect($entry['transports'], self::TRANSPORTS)) : [];
            if ($transports) $descriptor['transports'] = $transports;
            $out[] = $descriptor;
        }
        return $out;
    }

    /**
     * E-mail-first sign-in: a transport-free descriptor list padded to the
     * per-account maximum. 6.12.0: the padding is derived from the typed
     * identifier (same answer on every call, for known and unknown accounts),
     * its lengths follow real authenticators (16 to 64 bytes) and the list is
     * ordered by a keyed hash, so neither repeated calls, lengths nor
     * positions tell real credentials from padding. Real ids are never
     * dropped. Discoverable sign-in (no identifier) needs no list at all.
     */
    private static function login_descriptors(array $credentials, $identifier) {
        $seed = strtolower(trim((string) $identifier));
        $key = wp_salt('secure_auth');
        $out = [];
        foreach ($credentials as $entry) {
            $id = sanitize_text_field((string) ($entry['id'] ?? ''));
            if ($id !== '') $out[$id] = ['type'=>'public-key','id'=>$id];
        }
        $lengths = [16, 20, 20, 32, 32, 48, 64];
        for ($i = count($out); $i < self::max_per_user(); $i++) {
            $mac = hash_hmac('sha256', 'pk_pad_len|' . $i . '|' . $seed, $key, true);
            $length = $lengths[ord($mac[0]) % count($lengths)];
            $bytes = '';
            for ($block = 0; strlen($bytes) < $length; $block++) $bytes .= hash_hmac('sha256', 'pk_pad|' . $i . '|' . $block . '|' . $seed, $key, true);
            $id = self::b64u_encode(substr($bytes, 0, $length));
            $out[$id] = ['type'=>'public-key','id'=>$id];
        }
        $list = array_values($out);
        usort($list, function ($a, $b) use ($key) {
            return strcmp(hash_hmac('sha256', 'pk_order|' . $a['id'], $key), hash_hmac('sha256', 'pk_order|' . $b['id'], $key));
        });
        return $list;
    }

    private static function options_response($ceremony, array $publicKey, array $extra = []) {
        return new WP_REST_Response(array_merge(['ceremony'=>$ceremony['id'],'publicKey'=>$publicKey], $extra), 200);
    }

    public static function rest_register_options($request) {
        $uid = get_current_user_id();
        $credentials = self::credentials($uid);
        if (count($credentials) >= self::max_per_user()) return self::limit_error();
        $user = get_userdata($uid);
        if (!$user) return new WP_Error('dip_user_missing', __('Compte introuvable.', 'delicat-google-login'), ['status'=>404]);
        $body = self::json_body($request);
        $label = sanitize_text_field(self::str_field($body, 'label'));
        if ($label === '') $label = __('Mon appareil', 'delicat-google-login');
        $label = function_exists('mb_substr') ? mb_substr($label, 0, 60) : substr($label, 0, 60);
        // permission_sensitive_customer() checked recent authentication: the
        // single-use ceremony (user, browser, 5 minutes) carries it to verify.
        $ceremony = self::create_ceremony('register', $uid, ['label'=>$label, 'recent'=>1]);
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
            'timeout'=>self::TIMEOUT_MS,
            'excludeCredentials'=>self::credential_descriptors($credentials),
            // 'preferred' keeps FIDO2 security keys with full resident storage
            // working; credProps tells whether the new one is discoverable.
            'authenticatorSelection'=>['residentKey'=>'preferred','userVerification'=>'required'],
            'attestation'=>'none',
            'extensions'=>['credProps'=>true],
        ];
        return self::options_response($ceremony, $publicKey);
    }

    private static function limit_error() {
        return new WP_Error('dip_passkey_limit', sprintf(__('Nombre maximal de Passkeys atteint (%d). Supprimez-en une pour en ajouter une autre.', 'delicat-google-login'), self::max_per_user()), ['status'=>409]);
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

    /**
     * Accounts whose passkey user handle this is, at most two (same lookup as
     * DIP_Mobile_Passkeys): sign-in needs exactly one.
     */
    private static function users_by_handle($handle) {
        $handle = (string) $handle;
        if ($handle === '' || strlen($handle) > 128 || self::b64u_decode($handle) === false) return [];
        $ids = get_users(['meta_key'=>self::META_USER_HANDLE, 'meta_value'=>$handle, 'number'=>2, 'fields'=>'ID', 'count_total'=>false]);
        return array_values(array_filter(array_map('absint', (array) $ids)));
    }

    private static function safe_redirect($value, $fallback = '') {
        return DIP_Session_Router::destination($value, $fallback ?: home_url('/my-account/'));
    }

    private static function default_login_redirect() {
        $s = self::settings();
        return self::safe_redirect($s['native_login_redirect'] ?? '/my-account/', home_url('/my-account/'));
    }

    private static function is_staff($user) {
        return $user instanceof WP_User && (user_can($user, 'edit_posts') || user_can($user, 'manage_woocommerce') || user_can($user, 'manage_options'));
    }

    /**
     * Where a passkey sign-in lands. Customers: the requested page when it is
     * a storefront page (DIP_Session_Router::destination), else the configured
     * landing page, plus the dip_auth_sync cache-buster. Staff keep their
     * same-host target, wp-admin included (as the password path does in
     * DIP_Session_Router::login_destination), wp-admin by default.
     */
    private static function login_destination(array $state, WP_User $user) {
        if (self::is_staff($user)) {
            $raw = (string) ($state['redirect_raw'] ?? '');
            $target = $raw !== '' ? wp_validate_redirect($raw, '') : '';
            $path = $target !== '' ? (string) wp_parse_url($target, PHP_URL_PATH) : '';
            if ($target === '' || preg_match('~/wp-login\.php$~i', $path)) return admin_url();
            if (!preg_match('~/wp-admin(?:/|$)~i', $path)) $target = add_query_arg('dip_auth_sync', DIP_Session_Router::sync_marker(), remove_query_arg('dip_auth_sync', $target));
            return $target;
        }
        $redirect = self::safe_redirect((string) ($state['redirect'] ?? ''), self::default_login_redirect());
        return add_query_arg('dip_auth_sync', DIP_Session_Router::sync_marker(), remove_query_arg('dip_auth_sync', $redirect));
    }

    public static function rest_login_options($request) {
        if (!self::available()) return new WP_Error('dip_passkeys_unavailable', __('Connexion Passkey indisponible.', 'delicat-google-login'), ['status'=>503]);
        $body = self::json_body($request);
        $identifier = trim(sanitize_text_field(self::str_field($body, 'identifier')));
        if ($identifier === '') $identifier = trim(sanitize_text_field(self::str_field($body, 'email')));
        // Every call stores a ceremony: a per-address flood ceiling only (no person reaches it).
        if (DIP_Lockout::counter_hit(self::login_counter_key('login_options_ip'), self::LOGIN_ADDRESS_WINDOW) > self::LOGIN_OPTIONS_ADDRESS_LIMIT) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_login_rate_limited', 'warning', 0);
            return new WP_Error('dip_passkey_rate_limited', __('Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'), ['status'=>429]);
        }
        $remember = !empty($body['remember']);
        $raw_redirect = substr(trim(self::str_field($body, 'redirect')), 0, 2048);
        $extra = [
            'remember'=>$remember ? 1 : 0,
            'redirect'=>self::safe_redirect($raw_redirect, self::default_login_redirect()),
            'redirect_raw'=>$raw_redirect,
        ];
        if ($identifier === '') {
            // Discoverable: the authenticator offers this site's passkeys and
            // login/verify finds the account from the userHandle.
            $ceremony = self::create_ceremony('login', 0, $extra + ['discoverable'=>1]);
            if (!$ceremony) return new WP_Error('dip_passkey_start_failed', __('Impossible de démarrer la connexion Passkey.', 'delicat-google-login'), ['status'=>500]);
            return self::options_response($ceremony, [
                'challenge'=>$ceremony['challenge'],
                'rpId'=>self::rp_id(),
                'allowCredentials'=>[],
                'userVerification'=>'required',
                'timeout'=>self::TIMEOUT_MS,
            ]);
        }
        $user = self::resolve_login_user($identifier);
        $uid = $user instanceof WP_User ? (int) $user->ID : 0;
        $credentials = $uid ? self::credentials($uid) : [];
        // Keep the options shape generic for unknown accounts / accounts without
        // passkeys so this endpoint is not a simple account-enumeration API.
        $descriptors = self::login_descriptors($credentials, $identifier);
        $ceremony = self::create_ceremony('login', $credentials ? $uid : 0, $extra);
        if (!$ceremony) return new WP_Error('dip_passkey_start_failed', __('Impossible de démarrer la connexion Passkey.', 'delicat-google-login'), ['status'=>500]);
        return self::options_response($ceremony, [
            'challenge'=>$ceremony['challenge'],
            'rpId'=>self::rp_id(),
            'allowCredentials'=>$descriptors,
            'userVerification'=>'required',
            'timeout'=>self::TIMEOUT_MS,
        ]);
    }

    public static function rest_reauth_options($request) {
        $uid = get_current_user_id();
        $credentials = self::credentials($uid);
        if (!$credentials) return new WP_Error('dip_passkey_none', __('Aucune Passkey enregistrée pour ce compte.', 'delicat-google-login'), ['status'=>404]);
        // Flood guard only (one stored ceremony per call), never reached by a person.
        if (DIP_Lockout::counter_hit(self::account_counter_key('reauth_options_flood', $uid), self::LOGIN_ADDRESS_WINDOW) > self::REAUTH_OPTIONS_FLOOD) return new WP_Error('dip_passkey_rate_limited', __('Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'), ['status'=>429]);
        $ceremony = self::create_ceremony('reauth', $uid);
        if (!$ceremony) return new WP_Error('dip_passkey_start_failed', __('Impossible de démarrer la confirmation Passkey.', 'delicat-google-login'), ['status'=>500]);
        return self::options_response($ceremony, [
            'challenge'=>$ceremony['challenge'],'rpId'=>self::rp_id(),
            'allowCredentials'=>self::credential_descriptors($credentials),'userVerification'=>'required','timeout'=>self::TIMEOUT_MS,
        ]);
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function verify_client_data($encoded, $state, $type) {
        $raw = self::b64u_decode($encoded);
        if ($raw === false || strlen($raw) < 20 || strlen($raw) > 65536) return new WP_Error('dip_passkey_client_data', __('Données Passkey invalides.', 'delicat-google-login'), ['status'=>400]);
        $json = json_decode($raw, true);
        if (!is_array($json)) return new WP_Error('dip_passkey_client_json', __('Données Passkey invalides.', 'delicat-google-login'), ['status'=>400]);
        if (!isset($json['type']) || !is_string($json['type']) || !hash_equals((string)$type, $json['type'])) return new WP_Error('dip_passkey_type', __('Type de vérification Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
        if (empty($json['challenge']) || !is_string($json['challenge']) || !hash_equals((string)$state['challenge'], $json['challenge'])) return new WP_Error('dip_passkey_challenge', __('Challenge Passkey invalide.', 'delicat-google-login'), ['status'=>403]);
        if (empty($json['origin']) || !is_string($json['origin']) || !hash_equals((string)$state['origin'], $json['origin'])) return new WP_Error('dip_passkey_origin', __('Origine Passkey refusée.', 'delicat-google-login'), ['status'=>403]);
        if ((isset($json['crossOrigin']) && $json['crossOrigin'] !== false) || isset($json['topOrigin'])) return new WP_Error('dip_passkey_cross_origin', __('Vérification Passkey cross-origin refusée.', 'delicat-google-login'), ['status'=>403]);
        return ['raw'=>$raw,'json'=>$json];
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function parse_authenticator_data($raw, $rp_id, $require_attested = false) {
        if (!is_string($raw) || strlen($raw) < 37 || strlen($raw) > 262144) return new WP_Error('dip_passkey_auth_data', __('Données authenticator invalides.', 'delicat-google-login'), ['status'=>400]);
        $rp_hash = substr($raw, 0, 32);
        if (!hash_equals(hash('sha256', (string)$rp_id, true), $rp_hash)) return new WP_Error('dip_passkey_rp', __('Cette Passkey appartient à un autre domaine.', 'delicat-google-login'), ['status'=>403]);
        $flags = ord($raw[32]);
        if (($flags & 0x01) !== 0x01) return new WP_Error('dip_passkey_user_presence', __('Présence utilisateur requise.', 'delicat-google-login'), ['status'=>403]);
        if (($flags & 0x04) !== 0x04) return new WP_Error('dip_passkey_user_verification', __('Face ID, Touch ID, biométrie ou PIN appareil requis.', 'delicat-google-login'), ['status'=>403]);
        if ($require_attested && (($flags & 0x40) !== 0x40)) return new WP_Error('dip_passkey_attested_missing', __('Clé publique Passkey absente.', 'delicat-google-login'), ['status'=>400]);
        if (($flags & 0x10) && !($flags & 0x08)) return new WP_Error('dip_passkey_backup_flags', __('Indicateurs de sécurité Passkey incohérents.', 'delicat-google-login'), ['status'=>403]);
        if (!$require_attested && ($flags & 0x40)) return new WP_Error('dip_passkey_assertion_flags', __('Données de connexion Passkey invalides.', 'delicat-google-login'), ['status'=>400]);
        if (!$require_attested) {
            $offset = 37;
            if ($flags & 0x80) {
                $extensions = self::cbor_decode_item($raw, $offset, 0);
                if (!is_array($extensions)) return new WP_Error('dip_passkey_extensions', __('Extensions Passkey invalides.', 'delicat-google-login'), ['status'=>400]);
            }
            if ($offset !== strlen($raw)) return new WP_Error('dip_passkey_trailing_data', __('Données Passkey inattendues.', 'delicat-google-login'), ['status'=>400]);
        }
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

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function cbor_decode_item($data, &$offset, $depth = 0) {
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
                $map=[]; for($i=0;$i<$length;$i++){ $k=self::cbor_decode_item($data,$offset,$depth+1); if($k===false||(!is_int($k)&&!is_string($k))) return false; $v=self::cbor_decode_item($data,$offset,$depth+1); if($v===false) return false; if(array_key_exists($k,$map)) return false; $map[$k]=$v; } return $map;
            case 6: return self::cbor_decode_item($data,$offset,$depth+1);
            case 7:
                if ($additional === 20) return false;
                if ($additional === 21) return true;
                if ($additional === 22 || $additional === 23) return null;
                return false;
        }
        return false;
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function cbor_decode($data) {
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

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function cose_to_pem($cose) {
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
            if (strlen(ltrim($n, "\x00")) < 256 || $e === '' || strlen($n) > 1024 || strlen($e) > 8) return false;
            $rsa = self::der_sequence(self::der_integer($n) . self::der_integer($e));
            $alg_id = hex2bin('300D06092A864886F70D0101010500');
            $spki = self::der_sequence($alg_id . self::der_bit_string($rsa));
            return ['pem'=>self::pem_public_key($spki),'alg'=>-257];
        }
        return false;
    }

    /**
     * @internal 6.11.0: shared with DIP_Mobile_Passkeys (which keeps the
     * strict fmt 'none' rule). 6.12.0: also returns the authenticator flags;
     * the website passes $any_format: a client or security key may still
     * answer a self-attested 'packed' statement although 'none' was asked.
     * The statement is never verified nor stored either way (no device
     * attestation is collected).
     */
    public static function parse_attestation($encoded, $state, $any_format = false) {
        $raw = self::b64u_decode($encoded);
        if ($raw === false || strlen($raw) < 50 || strlen($raw) > 262144) return new WP_Error('dip_passkey_attestation', __('Attestation Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
        $att = self::cbor_decode($raw);
        if (!is_array($att) || !isset($att['fmt'],$att['authData'],$att['attStmt']) || !is_string($att['fmt']) || !is_string($att['authData']) || !is_array($att['attStmt'])) return new WP_Error('dip_passkey_attestation_cbor', __('Attestation Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
        // We explicitly request no attestation to avoid collecting device attestation
        // identity. For fmt=none the statement must be empty.
        $format_ok = $any_format ? preg_match('/^[a-z0-9-]{1,32}$/D', (string) $att['fmt']) === 1 : ((string)$att['fmt'] === 'none' && empty($att['attStmt']));
        if (!$format_ok) return new WP_Error('dip_passkey_attestation_format', __('Format d’attestation non attendu. Réessayez avec le navigateur à jour.', 'delicat-google-login'), ['status'=>400]);
        $auth = self::parse_authenticator_data($att['authData'], (string)$state['rp_id'], true);
        if (is_wp_error($auth)) return $auth;
        $data = $att['authData'];
        $offset = 37;
        if ($offset + 18 > strlen($data)) return new WP_Error('dip_passkey_attested_data', __('Données Passkey incomplètes.', 'delicat-google-login'), ['status'=>400]);
        $offset += 16; // AAGUID
        $v = unpack('nlen', substr($data,$offset,2)); $offset += 2;
        $cred_len = (int)($v['len'] ?? 0);
        if ($cred_len < 16 || $cred_len > 1023 || $offset + $cred_len > strlen($data)) return new WP_Error('dip_passkey_credential_id', __('Identifiant Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
        $credential_id = substr($data,$offset,$cred_len); $offset += $cred_len;
        $cose_offset = $offset;
        $cose = self::cbor_decode_item($data, $cose_offset, 0);
        if (!is_array($cose)) return new WP_Error('dip_passkey_public_key', __('Clé publique Passkey invalide.', 'delicat-google-login'), ['status'=>400]);
        if ($auth['flags'] & 0x80) {
            $extensions = self::cbor_decode_item($data, $cose_offset, 0);
            if (!is_array($extensions)) return new WP_Error('dip_passkey_extensions', __('Extensions Passkey invalides.', 'delicat-google-login'), ['status'=>400]);
        }
        if ($cose_offset !== strlen($data)) return new WP_Error('dip_passkey_trailing_data', __('Données Passkey inattendues.', 'delicat-google-login'), ['status'=>400]);
        $converted = self::cose_to_pem($cose);
        if (!$converted || !openssl_pkey_get_public($converted['pem'])) return new WP_Error('dip_passkey_public_key_unsupported', __('Type de Passkey non pris en charge sur ce serveur.', 'delicat-google-login'), ['status'=>400]);
        return [
            'credential_id'=>self::b64u_encode($credential_id),
            'public_key'=>$converted['pem'],'alg'=>$converted['alg'],
            'sign_count'=>$auth['sign_count'],
            'flags'=>$auth['flags'],
        ];
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function request_credential_id(array $body) {
        $raw_id = sanitize_text_field(self::str_field($body, 'rawId'));
        $id = sanitize_text_field(self::str_field($body, 'id'));
        if ($raw_id !== '' && self::b64u_decode($raw_id) === false) return '';
        if ($id !== '' && self::b64u_decode($id) === false) return '';
        // PublicKeyCredential.id is the base64url representation of rawId.
        // Reject contradictory client fields rather than silently preferring one.
        if ($raw_id !== '' && $id !== '' && !hash_equals($raw_id, $id)) return '';
        $value = $raw_id !== '' ? $raw_id : $id;
        return ($value !== '' && self::b64u_decode($value) !== false) ? $value : '';
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function find_credential($user_id, $credential_id) {
        $credentials = self::credentials($user_id);
        foreach ($credentials as $index=>$entry) {
            if (!empty($entry['id']) && hash_equals((string)$entry['id'], (string)$credential_id)) return [$entry,$index,$credentials];
        }
        return [null,-1,$credentials];
    }

    /**
     * @internal 6.11.0: shared with DIP_Mobile_Passkeys. 6.12.0: the full
     * (untruncated) list is saved back, and the backup flags (BE/BS) of the
     * latest assertion are kept for the « Synchronisée » badge.
     */
    public static function verify_assertion(array $body, array $state, $credential_override = null) {
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
            $client = self::verify_client_data(self::str_field($body, 'clientDataJSON'), $state, 'webauthn.get');
            if (is_wp_error($client)) return $client;
            $auth_raw = self::b64u_decode(self::str_field($body, 'authenticatorData'));
            $signature = self::b64u_decode(self::str_field($body, 'signature'));
            if ($auth_raw === false || $signature === false || strlen($signature) < 8 || strlen($signature) > 4096) return new WP_Error('dip_passkey_assertion', __('Signature Passkey invalide.', 'delicat-google-login'), ['status'=>401]);
            $auth = self::parse_authenticator_data($auth_raw, (string)$state['rp_id'], false);
            if (is_wp_error($auth)) return $auth;
            $signed = $auth_raw . hash('sha256', $client['raw'], true);
            $public = openssl_pkey_get_public((string)$credential['public_key']);
            if (!$public) return new WP_Error('dip_passkey_key_read', __('Clé Passkey invalide.', 'delicat-google-login'), ['status'=>500]);
            $verified = openssl_verify($signed, $signature, $public, OPENSSL_ALGO_SHA256);
            if ($verified !== 1) return new WP_Error('dip_passkey_signature', __('Signature Passkey refusée.', 'delicat-google-login'), ['status'=>401]);
            $user_handle = self::str_field($body, 'userHandle');
            if ($user_handle !== '' && !hash_equals(self::user_handle($uid), $user_handle)) return new WP_Error('dip_passkey_user_handle', __('Passkey liée à un autre compte.', 'delicat-google-login'), ['status'=>403]);
            if (isset($credential['be']) && (bool) $credential['be'] !== (bool) ($auth['flags'] & 0x08)) return new WP_Error('dip_passkey_backup_changed', __('La propriété de sécurité de cette Passkey a changé. Utilisez une autre méthode.', 'delicat-google-login'), ['status'=>403]);
            $stored_count = max(0,(int)($credential['sign_count'] ?? 0));
            $new_count = max(0,(int)$auth['sign_count']);
            if (($stored_count > 0 || $new_count > 0) && $new_count <= $stored_count) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_counter_replay_blocked','critical',$uid,['credential'=>substr(hash('sha256',$credential_id),0,16)]);
                return new WP_Error('dip_passkey_counter', __('Cette Passkey présente un compteur de sécurité incohérent. Utilisez une autre méthode et vérifiez vos appareils.', 'delicat-google-login'), ['status'=>403]);
            }
            $credential['sign_count'] = max($stored_count,$new_count);
            $credential['last_used'] = time();
            $credential['be'] = ($auth['flags'] & 0x08) ? 1 : 0;
            $credential['bs'] = ($auth['flags'] & 0x10) ? 1 : 0;
            if (!$is_pending) {
                $credentials[$index] = $credential;
                if (!self::save_credentials($uid,$credentials)) return new WP_Error('dip_passkey_save', __('Impossible de sauvegarder la vérification. Réessayez.', 'delicat-google-login'), ['status'=>503]);
            }
            return ['user_id'=>$uid,'credential'=>$credential];
        } finally {
            self::release_user_lock($uid);
        }
    }

    /** One-step enrolment: verified attestation, then the credential is stored (same checks as the app). */
    public static function rest_register_verify($request) {
        $body = self::json_body($request);
        $state = self::consume_ceremony(self::str_field($body, 'ceremony'), 'register');
        if (is_wp_error($state)) return $state;
        $uid = get_current_user_id();
        if (!$uid || absint($state['user_id'] ?? 0) !== $uid) return new WP_Error('dip_passkey_user_mismatch', __('Session Passkey liée à un autre compte.', 'delicat-google-login'), ['status'=>403]);
        // A ceremony opened before 6.12.0 does not carry the recent-auth flag.
        if (empty($state['recent']) && class_exists('DIP_Reauth') && !DIP_Reauth::is_recent()) return self::reauth_required_error();
        $client = self::verify_client_data(self::str_field($body, 'clientDataJSON'), $state, 'webauthn.create');
        if (is_wp_error($client)) return $client;
        $att = self::parse_attestation(self::str_field($body, 'attestationObject'), $state, true);
        if (is_wp_error($att)) return $att;
        $request_id = self::request_credential_id($body);
        if ($request_id === '' || !hash_equals((string)$att['credential_id'], $request_id)) return new WP_Error('dip_passkey_id_mismatch', __('Identifiant Passkey incohérent.', 'delicat-google-login'), ['status'=>400]);
        $transports = isset($body['transports']) && is_array($body['transports']) ? array_values(array_intersect(array_map('sanitize_key', array_filter($body['transports'], 'is_scalar')), self::TRANSPORTS)) : [];
        $entry = [
            'id'=>$att['credential_id'],'public_key'=>$att['public_key'],'alg'=>(int)$att['alg'],'sign_count'=>(int)$att['sign_count'],
            // AAGUID is intentionally not persisted: it is unnecessary for our
            // verification policy and would add avoidable authenticator metadata.
            'transports'=>$transports,
            'label'=>sanitize_text_field((string)($state['label'] ?? __('Mon appareil', 'delicat-google-login'))),
            'created'=>time(),'last_used'=>0,
            'be'=>((int)$att['flags'] & 0x08) ? 1 : 0,
            'bs'=>((int)$att['flags'] & 0x10) ? 1 : 0,
        ];
        if (isset($body['discoverable']) && is_bool($body['discoverable'])) $entry['discoverable'] = $body['discoverable'] ? 1 : 0;
        $saved = self::store_new_credential($uid, $entry);
        if (is_wp_error($saved)) return $saved;
        self::registered($uid, $entry, 0);
        return self::changed_response($uid, __('Passkey ajoutée. Vous pouvez maintenant vous connecter en un geste.', 'delicat-google-login'));
    }

    /** Duplicate and limit checks plus the save, under the account lock. */
    private static function store_new_credential($uid, array $entry) {
        if (!self::acquire_user_lock($uid, 10)) return new WP_Error('dip_passkey_account_busy', __('Une autre modification de sécurité est en cours. Réessayez.', 'delicat-google-login'), ['status'=>409]);
        try {
            $credentials = self::credentials($uid);
            foreach ($credentials as $existing) if (!empty($existing['id']) && hash_equals((string)$existing['id'], (string)$entry['id'])) return new WP_Error('dip_passkey_exists', __('Cette Passkey est déjà enregistrée.', 'delicat-google-login'), ['status'=>409]);
            if (count($credentials) >= self::max_per_user()) return self::limit_error();
            $credentials[] = $entry;
            if (!self::save_credentials($uid, $credentials)) return new WP_Error('dip_passkey_save', __('Impossible d’enregistrer cette Passkey.', 'delicat-google-login'), ['status'=>500]);
            return true;
        } finally { self::release_user_lock($uid); }
    }

    private static function registered($uid, array $entry, $proof) {
        if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_registered', 'notice', $uid, ['alg'=>(int)$entry['alg'], 'transport'=>implode(',', (array)$entry['transports']), 'proof'=>$proof ? 1 : 0, 'synced'=>!empty($entry['bs']) ? 1 : 0]);
        self::security_email($uid, __('Nouvelle Passkey ajoutée à votre compte Delicat', 'delicat-google-login'), sprintf(__('Une nouvelle Passkey (« %s ») a été enregistrée sur votre compte. Si vous n’êtes pas à l’origine de cette action, changez votre mot de passe et supprimez immédiatement cette Passkey depuis le Centre de sécurité.', 'delicat-google-login'), (string)$entry['label']));
    }

    /** Success answer of the card actions: the refreshed card (no page reload) and the Signal API payload. */
    private static function changed_response($uid, $message) {
        return new WP_REST_Response([
            'success'=>true,
            'message'=>$message,
            'count'=>self::count($uid),
            'card'=>self::render_settings($uid),
            'signal'=>self::signal_payload($uid),
        ], 200);
    }

    /** Pre-6.12.0 second step (proof assertion), kept for ceremonies opened by the old script. */
    public static function rest_register_proof($request) {
        $body=self::json_body($request);
        $state=self::consume_ceremony(self::str_field($body, 'ceremony'),'register_proof');
        if(is_wp_error($state)) return $state;
        $uid=get_current_user_id();
        if(!$uid || absint($state['user_id']??0)!==$uid || empty($state['pending_credential']) || !is_array($state['pending_credential'])) return new WP_Error('dip_passkey_user_mismatch',__('Session Passkey liée à un autre compte.','delicat-google-login'),['status'=>403]);
        $result=self::verify_assertion($body,$state,$state['pending_credential']);
        if(is_wp_error($result)){if(class_exists('DIP_Audit'))DIP_Audit::record('passkey_registration_proof_failed','warning',$uid);return $result;}
        $entry=$result['credential'];
        // The proof is not a sign-in: present the passkey as newly enrolled.
        $entry['created']=time();
        $entry['last_used']=0;
        $saved=self::store_new_credential($uid,$entry);
        if(is_wp_error($saved)) return $saved;
        self::registered($uid,$entry,1);
        return self::changed_response($uid,__('Passkey ajoutée et vérifiée avec succès.','delicat-google-login'));
    }

    public static function rest_login_verify($request) {
        $body = self::json_body($request);
        $state = self::consume_ceremony(self::str_field($body, 'ceremony'), 'login');
        if (is_wp_error($state)) return $state;
        $address_failures = self::login_counter_key('login_verify_fail');
        if (DIP_Lockout::counter_peek($address_failures)['count'] >= self::LOGIN_VERIFY_ADDRESS_FAILURES) return new WP_Error('dip_passkey_rate_limited', __('Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'), ['status'=>429]);
        $state_uid = absint($state['user_id'] ?? 0);
        if (!empty($state['discoverable'])) {
            // The account is the one the userHandle names; the credential must be one of ITS passkeys.
            $handle_ids = self::users_by_handle(self::str_field($body, 'userHandle'));
            $state_uid = count($handle_ids) === 1 ? (int) $handle_ids[0] : 0;
            $credential_id = self::request_credential_id($body);
            $known = $state_uid && $credential_id !== '' && is_array(self::find_credential($state_uid, $credential_id)[0]);
            if (!$known) {
                DIP_Lockout::counter_hit($address_failures, self::LOGIN_ADDRESS_WINDOW);
                if ($state_uid) DIP_Lockout::counter_hit(self::login_counter_key('login_verify_uid_fail', 'uid:' . $state_uid), self::LOGIN_ADDRESS_WINDOW);
                if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_login_failed', 'warning', $state_uid, ['reason'=>'unknown_credential']);
                // 'unknown_credential' lets the page ask the browser to forget a passkey this site
                // no longer accepts: only when nothing on the site matches it (never for a handle
                // shared by two accounts, e.g. copied user meta).
                $data = ['status'=>401];
                if (count($handle_ids) < 2) $data['unknown_credential'] = 1;
                return new WP_Error('dip_passkey_unknown', __('Cette Passkey n’est plus reconnue par Delicat Store. Utilisez une autre méthode de connexion.', 'delicat-google-login'), $data);
            }
            $state['user_id'] = $state_uid;
        }
        $account_failures = $state_uid ? self::login_counter_key('login_verify_uid_fail', 'uid:' . $state_uid) : '';
        if ($account_failures !== '' && DIP_Lockout::counter_peek($account_failures)['count'] >= self::LOGIN_VERIFY_ACCOUNT_FAILURES) return new WP_Error('dip_passkey_rate_limited', __('Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'), ['status'=>429]);
        $result = self::verify_assertion($body,$state);
        if (is_wp_error($result)) {
            DIP_Lockout::counter_hit($address_failures, self::LOGIN_ADDRESS_WINDOW);
            if ($account_failures !== '') DIP_Lockout::counter_hit($account_failures, self::LOGIN_ADDRESS_WINDOW);
            if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_login_failed','warning',$state_uid);
            return $result;
        }
        $uid = absint($result['user_id']);
        $user = get_userdata($uid);
        if (!$user) return new WP_Error('dip_passkey_user_missing', __('Connexion Passkey impossible.', 'delicat-google-login'), ['status'=>401]);
        if (class_exists('DIP_Account_Sync')) {
            $guard = DIP_Account_Sync::login_guard($uid);
            if (is_wp_error($guard)) return new WP_Error('dip_account_policy_denied', __('Ce compte ne peut pas se connecter.', 'delicat-google-login'), ['status'=>403]);
        }
        // The page may send the « Se souvenir de moi » choice made after the options were fetched (autofill).
        $remember = array_key_exists('remember', $body) ? !empty($body['remember']) : !empty($state['remember']);
        wp_clear_auth_cookie();
        wp_set_current_user($uid);
        if (class_exists('DIP_Two_Factor')) DIP_Two_Factor::authorize_cookie_once($uid);
        wp_set_auth_cookie($uid, $remember, is_ssl());
        DIP_Account_Sync::fire_wp_login($user,'passkey');
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_login($uid);
        do_action('dip_login_success',$uid,['provider'=>'passkey','user_verification'=>1]);
        if (class_exists('DIP_Reauth')) DIP_Reauth::mark_recent($uid);
        if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_login_success','info',$uid,['credential'=>substr(hash('sha256',(string)$result['credential']['id']),0,16),'discoverable'=>!empty($state['discoverable']) ? 1 : 0]);
        return new WP_REST_Response(['success'=>true,'redirect'=>self::login_destination($state, $user)], 200);
    }

    public static function rest_reauth_verify($request) {
        $body=self::json_body($request);
        $state=self::consume_ceremony(self::str_field($body, 'ceremony'),'reauth');
        if(is_wp_error($state)) return $state;
        $uid=get_current_user_id();
        if(!$uid || absint($state['user_id']??0)!==$uid) return new WP_Error('dip_passkey_user_mismatch',__('Session Passkey liée à un autre compte.','delicat-google-login'),['status'=>403]);
        // Refused assertions only (read before the check); confirmations themselves are never limited.
        $failures=self::account_counter_key('reauth_verify_fail',$uid);
        if(DIP_Lockout::counter_peek($failures)['count']>=self::REAUTH_VERIFY_FAILURES) return new WP_Error('dip_passkey_rate_limited',__('Trop de tentatives. Réessayez plus tard.','delicat-google-login'),['status'=>429]);
        $result=self::verify_assertion($body,$state);
        if(is_wp_error($result)){ DIP_Lockout::counter_hit($failures,self::LOGIN_ADDRESS_WINDOW); if(class_exists('DIP_Audit'))DIP_Audit::record('passkey_reauth_failed','warning',$uid); return $result; }
        if(class_exists('DIP_Reauth')) DIP_Reauth::mark_recent($uid);
        if(class_exists('DIP_Audit')) DIP_Audit::record('passkey_reauth_success','info',$uid);
        return new WP_REST_Response(['success'=>true,'message'=>__('Identité confirmée avec votre Passkey.','delicat-google-login')],200);
    }

    /**
     * Customers can always sign in with a code or a link sent to their
     * mailbox while one of those methods is on (customers only: e-mail
     * sign-in refuses staff accounts).
     */
    private static function email_login_available($user_id) {
        if (!class_exists('DIP_Passwordless_Registration')) return false;
        if (method_exists('DIP_Passwordless_Registration', 'email_login_available')) return (bool) DIP_Passwordless_Registration::email_login_available($user_id);
        $user = get_userdata(absint($user_id));
        if (!$user || !is_email($user->user_email) || self::is_staff($user)) return false;
        $s = wp_parse_args((array) get_option(DIP_Passwordless_Registration::OPTION, []), DIP_Passwordless_Registration::defaults());
        return ($s['otp_enabled'] ?? 'yes') === 'yes' || ($s['magic_enabled'] ?? 'yes') === 'yes';
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys. 6.12.0: e-mail code / magic link counts. */
    public static function has_other_primary_login_method($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return false;
        if (class_exists('DIP_Identity') && get_user_meta($user_id, DIP_Identity::META_PASSWORD_MANAGED, true) !== 'yes') return true;
        if (class_exists('DIP_Connected_Accounts')) {
            foreach ((array) DIP_Connected_Accounts::status($user_id) as $status) {
                if (!empty($status['connected'])) return true;
            }
        }
        return self::email_login_available($user_id);
    }

    public static function rest_remove($request) {
        $uid=get_current_user_id();
        $params=$request->get_url_params();
        $id=isset($params['credential']) && is_string($params['credential']) ? sanitize_text_field($params['credential']) : '';
        if($id==='') return new WP_Error('dip_passkey_not_found',__('Passkey introuvable.','delicat-google-login'),['status'=>404]);
        if(!self::acquire_user_lock($uid,10)) return new WP_Error('dip_passkey_account_busy',__('Une autre modification de sécurité est en cours. Réessayez.','delicat-google-login'),['status'=>409]);
        try {
            $credentials=self::credentials($uid); $kept=[]; $removed=null;
            foreach($credentials as $entry){ if(!$removed && !empty($entry['id']) && hash_equals((string)$entry['id'],$id)){$removed=$entry;continue;} $kept[]=$entry; }
            if(!$removed) return new WP_Error('dip_passkey_not_found',__('Passkey introuvable.','delicat-google-login'),['status'=>404]);
            if(!$kept && !self::has_other_primary_login_method($uid)) return new WP_Error('dip_passkey_last_method',__('Ajoutez d’abord un mot de passe utilisable ou reliez Google/Microsoft avant de supprimer votre dernière Passkey.','delicat-google-login'),['status'=>409]);
            if(!self::save_credentials($uid,$kept)) return new WP_Error('dip_passkey_save',__('Impossible de supprimer cette Passkey. Réessayez.','delicat-google-login'),['status'=>503]);
        } finally { self::release_user_lock($uid); }
        if(class_exists('DIP_Audit'))DIP_Audit::record('passkey_removed','warning',$uid,['credential'=>substr(hash('sha256',$id),0,16)]);
        self::security_email($uid,__('Passkey supprimée de votre compte Delicat','delicat-google-login'),sprintf(__('La Passkey « %s » a été supprimée de votre compte. Si vous n’êtes pas à l’origine de cette action, changez votre mot de passe et vérifiez immédiatement vos sessions.','delicat-google-login'),(string)$removed['label']));
        return self::changed_response($uid,__('Passkey supprimée.','delicat-google-login'));
    }

    /** @internal 6.11.0: shared with DIP_Mobile_Passkeys, behaviour unchanged. */
    public static function security_email($user_id,$subject,$message){
        $user=get_userdata(absint($user_id)); if(!$user||!is_email($user->user_email)) return false;
        return DIP_Email_Hub::send_user_security($user->ID,$subject,$message,['label'=>__('Passkeys','delicat-google-login')]);
    }

    /**
     * WebAuthn Signal API payload for the owner's own page: every passkey the
     * site accepts for this user handle (website and app share the store), so
     * iCloud Keychain / Google Password Manager can hide deleted ones. Empty
     * when the account never had a handle (no passkey was ever created).
     */
    private static function signal_payload($user_id) {
        $user = get_userdata(absint($user_id));
        $handle = $user ? (string) get_user_meta($user->ID, self::META_USER_HANDLE, true) : '';
        if (!$user || $handle === '' || self::b64u_decode($handle) === false) return null;
        $ids = [];
        foreach (self::credentials($user->ID) as $entry) $ids[] = (string) $entry['id'];
        return [
            'rpId'=>self::rp_id(),
            'userId'=>$handle,
            'ids'=>$ids,
            'name'=>sanitize_email($user->user_email) ?: sanitize_user($user->user_login),
            'displayName'=>sanitize_text_field($user->display_name ?: $user->user_login),
        ];
    }

    public static function protect_script_tag($tag,$handle){
        if($handle!=='dip-passkeys') return $tag;
        foreach(['data-cfasync="false"','data-no-optimize="1"','data-no-defer="1"','data-no-delay="1"'] as $attribute){
            $name=substr($attribute,0,strpos($attribute,'='));
            if(strpos($tag,$name.'=')===false) $tag=str_replace('<script ','<script '.$attribute.' ',$tag);
        }
        return $tag;
    }

    /**
     * Configs printed by wp_localize_script() are inline scripts that
     * script_loader_tag never sees: keep Rocket Loader / LiteSpeed from
     * deferring them after their (protected) scripts — passkeys.js and the
     * identity modal shell that hands its passkey config to the frame.
     */
    public static function protect_inline_config($attributes, $data = '') {
        if (!is_array($attributes) || !in_array($attributes['id'] ?? '', ['dip-passkeys-js-extra', 'dip-auth-shell-js-extra', 'dip-session-router-js-extra'], true)) return $attributes;
        $attributes['data-cfasync'] = 'false';
        $attributes['data-no-optimize'] = '1';
        $attributes['data-no-defer'] = '1';
        $attributes['data-no-delay'] = '1';
        return $attributes;
    }

    /** The identity confirmation card (DIP_Reauth, anchor #dip-reauth), returning to this page afterwards. */
    private static function confirm_url() {
        // A site path; DIP_Reauth keeps it only when it is a page of this site.
        $here = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (class_exists('DIP_Reauth') && method_exists('DIP_Reauth', 'confirm_url')) return (string) DIP_Reauth::confirm_url($here);
        $url = home_url('/my-account/');
        if (function_exists('wc_get_account_endpoint_url') && class_exists('DIP_Customer_Dashboard')) $url = wc_get_account_endpoint_url(DIP_Customer_Dashboard::ENDPOINT);
        return add_query_arg('dip_tab', 'security', $url) . '#dip-reauth';
    }

    public static function frontend_config(){
        $logged_in = is_user_logged_in();
        return [
            'rest'=>untrailingslashit(wp_make_link_relative(rest_url(self::REST_NAMESPACE))),
            'nonce'=>$logged_in?wp_create_nonce('wp_rest'):'',
            'loggedIn'=>$logged_in?1:0,
            'available'=>self::available()?1:0,
            'redirect'=>self::default_login_redirect(),
            'timeout'=>self::TIMEOUT_MS,
            'rpId'=>self::rp_id(),
            /** Passkey autofill (conditional mediation) on login forms. */
            'autofill'=>apply_filters('dip_passkeys_autofill', true) ? 1 : 0,
            'securityUrl'=>$logged_in ? self::confirm_url() : '',
            'strings'=>[
                'unsupported'=>__('Ce navigateur ou cet appareil ne prend pas en charge les Passkeys.','delicat-google-login'),
                'inApp'=>__('Pour utiliser une Passkey, ouvrez le site dans Safari ou Chrome.','delicat-google-login'),
                'emailRequired'=>__('Entrez d’abord votre adresse e-mail.','delicat-google-login'),
                'cancelled'=>__('Vérification Passkey annulée ou indisponible.','delicat-google-login'),
                'cancelledLogin'=>__('Vérification annulée, ou aucune Passkey de ce compte sur cet appareil. Réessayez ou utilisez une autre méthode.','delicat-google-login'),
                'cancelledNoEmail'=>__('Vérification annulée, ou aucune Passkey trouvée. Si votre Passkey est sur une clé de sécurité, saisissez d’abord votre e-mail, puis réessayez.','delicat-google-login'),
                'exists'=>__('Cet appareil possède déjà une Passkey pour ce compte.','delicat-google-login'),
                'origin'=>__('Les Passkeys fonctionnent uniquement sur l’adresse officielle du site.','delicat-google-login'),
                'expired'=>__('Vérification Passkey expirée. Réessayez.','delicat-google-login'),
                'constraint'=>__('Activez le verrouillage de l’écran (Face ID, empreinte ou code) de cet appareil, puis réessayez.','delicat-google-login'),
                'device'=>__('Votre appareil n’a pas pu terminer la vérification. Réessayez, ou utilisez une autre méthode.','delicat-google-login'),
                'stale'=>__('Cette page a expiré. Actualisez-la puis réessayez.','delicat-google-login'),
                'network'=>__('Impossible de joindre le service Passkey. Vérifiez votre connexion puis réessayez.','delicat-google-login'),
                'slow'=>__('Le serveur met trop de temps à répondre. Réessayez.','delicat-google-login'),
                'blocked'=>__('Le service Passkey est momentanément inaccessible. Actualisez la page puis réessayez.','delicat-google-login'),
                'rateLimited'=>__('Trop de tentatives. Réessayez plus tard.','delicat-google-login'),
                'server'=>__('Le service Passkey a rencontré une erreur. Réessayez dans un instant.','delicat-google-login'),
                'loading'=>__('Les Passkeys se chargent. Réessayez dans un instant.','delicat-google-login'),
                'removeConfirm'=>__('Supprimer cette Passkey de votre compte ?','delicat-google-login'),
                'loginSuccess'=>__('Connexion Passkey réussie.','delicat-google-login'),
                'loginSlow'=>__('Connexion réussie. Actualisez la page pour continuer.','delicat-google-login'),
                'reauthNeeded'=>__('Pour votre sécurité, confirmez d’abord votre identité.','delicat-google-login'),
                'reauthPasskey'=>__('Confirmer avec une Passkey','delicat-google-login'),
                'reauthOther'=>__('Confirmer mon identité','delicat-google-login'),
                'reauthOtherShort'=>__('Autre méthode','delicat-google-login'),
                'reauthDone'=>__('Identité confirmée.','delicat-google-login'),
                'continueRegister'=>__('Ajouter la Passkey maintenant','delicat-google-login'),
                'defaultLabel'=>__('Mon appareil','delicat-google-login'),
            ],
        ];
    }

    /**
     * Pages whose content renders passkey markup for this visitor (security
     * card when signed in, sign-in button when not): load the stylesheet in
     * <head> there (no unstyled flash).
     */
    private static function page_has_passkey_markup() {
        if (!is_singular()) return false;
        $post = get_post();
        if (!($post instanceof WP_Post)) return false;
        $shortcodes = is_user_logged_in() ? ['delicat_security_center', 'delicat_identity_dashboard'] : ['delicat_auth_panel'];
        foreach ($shortcodes as $shortcode) {
            if (has_shortcode((string) $post->post_content, $shortcode)) return true;
        }
        return false;
    }

    /**
     * CSS, script and config, once. Called by every render function, so any
     * surface gets working buttons; enqueued during the body they print in
     * the footer (wp-login.php prints its footer scripts too).
     */
    public static function enqueue_runtime(){
        $s=self::settings(); if(($s['passkeys_available']??'yes')!=='yes') return false;
        if(defined('REST_REQUEST') && REST_REQUEST) return false;
        wp_enqueue_style('dip-passkeys',DIP_URL.'assets/passkeys.css',[],DIP_VERSION);
        wp_enqueue_script('dip-passkeys',DIP_URL.'assets/passkeys.js',[],DIP_VERSION,true);
        if(!self::$runtime_enqueued){
            self::$runtime_enqueued=true;
            wp_localize_script('dip-passkeys','DIPPasskeys',self::frontend_config());
        }
        return true;
    }

    /**
     * Early (head) loading where passkey markup is known to appear. 6.12.0:
     * anonymous storefront pages no longer load the files: the identity modal
     * runs its own copy inside its frame (auth-shell.js) with CSS inlined.
     * A theme that prints its own [data-dip-passkey-login] button in the page
     * can opt in with the dip_passkeys_enqueue_storefront filter.
     */
    public static function assets(){
        $s=self::settings(); if(($s['passkeys_available']??'yes')!=='yes') return;
        $is_wp_login=did_action('login_enqueue_scripts') || (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow']==='wp-login.php');
        $is_account=function_exists('is_account_page') && is_account_page();
        $load=$is_wp_login || $is_account || self::page_has_passkey_markup();
        if(!$load && !is_user_logged_in() && apply_filters('dip_passkeys_enqueue_storefront', false)) $load=true;
        if($load) self::enqueue_runtime();
    }

    private static function date_label($timestamp) {
        return wp_date(__('j M Y', 'delicat-google-login'), (int) $timestamp);
    }

    public static function render_settings($user_id=0){
        $user_id=absint($user_id?:get_current_user_id()); if(!$user_id||!is_user_logged_in()||$user_id!==get_current_user_id()) return '';
        self::enqueue_runtime();
        $credentials=self::credentials($user_id); $available=self::available(); $max=self::max_per_user();
        $count=count($credentials); $full=$count>=$max;
        $signal=self::signal_payload($user_id);
        $default_label=__('Mon appareil','delicat-google-login');
        ob_start(); ?>
        <div class="dip-security-card dip-passkeys-card" id="dip-passkeys" data-dip-passkeys-card data-dip-passkeys-count="<?php echo esc_attr($count); ?>"<?php if($signal): ?> data-dip-passkeys-signal="<?php echo esc_attr(wp_json_encode($signal)); ?>"<?php endif; ?>>
          <div class="dip-security-card-title"><span class="dip-security-card-icon dip-passkey-icon" aria-hidden="true"></span><div><h3><?php esc_html_e('Passkeys','delicat-google-login'); ?></h3><p><?php esc_html_e('Connectez-vous en un geste avec Face ID, Touch ID, l’empreinte Android, Windows Hello ou une clé de sécurité. Votre biométrie reste sur votre appareil. La clé d’accès peut être synchronisée par votre gestionnaire de mots de passe.','delicat-google-login'); ?></p></div></div>
          <?php if(!$available): ?><div class="dip-security-notice"><?php esc_html_e('Passkeys nécessite HTTPS, OpenSSL et un navigateur compatible.','delicat-google-login'); ?></div><?php endif; ?>
          <?php if($credentials): ?><ul class="dip-passkey-list" aria-label="<?php echo esc_attr__('Vos Passkeys','delicat-google-login'); ?>"><?php foreach($credentials as $entry): ?>
            <li class="dip-passkey-item">
              <span class="dip-passkey-item-icon<?php echo !empty($entry['bs']) ? ' is-synced' : ''; ?>" aria-hidden="true"></span>
              <div class="dip-passkey-item-body">
                <strong><?php echo esc_html($entry['label']); ?></strong>
                <?php if(!empty($entry['bs']) || (isset($entry['discoverable']) && !$entry['discoverable'])): ?><span class="dip-passkey-badges">
                  <?php if(!empty($entry['bs'])): ?><span class="dip-passkey-badge is-synced"><?php esc_html_e('Synchronisée','delicat-google-login'); ?></span><?php endif; ?>
                  <?php if(isset($entry['discoverable']) && !$entry['discoverable']): ?><span class="dip-passkey-badge"><?php esc_html_e('Avec votre e-mail','delicat-google-login'); ?></span><?php endif; ?>
                </span><?php endif; ?>
                <span class="dip-passkey-meta"><?php
                    $parts=[];
                    if($entry['created']) $parts[]=sprintf(__('Ajoutée le %s','delicat-google-login'),self::date_label($entry['created']));
                    $parts[]=$entry['last_used'] ? sprintf(__('Dernière utilisation : %s','delicat-google-login'),self::date_label($entry['last_used']).' '.wp_date('H:i',$entry['last_used'])) : __('Jamais utilisée pour se connecter','delicat-google-login');
                    echo esc_html(implode(' · ',$parts));
                ?></span>
              </div>
              <button type="button" class="dip-btn ghost dip-passkey-remove" data-dip-passkey-remove="<?php echo esc_attr($entry['id']); ?>" aria-label="<?php echo esc_attr(sprintf(__('Supprimer la Passkey « %s »','delicat-google-login'),$entry['label'])); ?>"<?php disabled(!$available); ?>><?php esc_html_e('Supprimer','delicat-google-login'); ?></button>
            </li>
          <?php endforeach; ?></ul><?php else: ?><div class="dip-security-empty dip-passkey-empty"><strong><?php esc_html_e('Aucune Passkey pour l’instant.','delicat-google-login'); ?></strong> <?php esc_html_e('Ajoutez-en une : plus besoin de mot de passe ni de code sur cet appareil.','delicat-google-login'); ?></div><?php endif; ?>
          <div class="dip-passkey-actions">
            <?php if(!$full): ?><label class="dip-passkey-label"><span><?php esc_html_e('Nom de la Passkey','delicat-google-login'); ?></span><input type="text" maxlength="60" value="<?php echo esc_attr($default_label); ?>" data-dip-passkey-label data-dip-default-label="<?php echo esc_attr($default_label); ?>" autocomplete="off"></label><?php endif; ?>
            <button type="button" class="dip-btn primary" data-dip-passkey-register<?php disabled(!$available || $full); ?>><?php esc_html_e('Ajouter une Passkey','delicat-google-login'); ?></button>
            <?php if($credentials): ?><button type="button" class="dip-btn" data-dip-passkey-reauth<?php disabled(!$available); ?>><?php esc_html_e('Confirmer avec une Passkey','delicat-google-login'); ?></button><?php endif; ?>
          </div>
          <details class="dip-passkey-guidance"><summary><?php esc_html_e('Comment fonctionne Face ID / le code appareil ?', 'delicat-google-login'); ?></summary><p><?php esc_html_e('Votre navigateur ouvre la fenêtre de sécurité du téléphone. Choisissez Face ID, Touch ID ou le code de déverrouillage de l’appareil. Ce code ne doit jamais être saisi sur le site. Conservez une autre méthode de connexion et vos codes de récupération 2FA dans un endroit sûr.', 'delicat-google-login'); ?></p></details>
          <?php if($full): ?><p class="dip-passkey-hint"><?php echo esc_html(sprintf(__('Maximum de %d Passkeys atteint : supprimez-en une pour en ajouter une autre.','delicat-google-login'),$max)); ?></p><?php endif; ?>
          <div class="dip-passkey-status" data-dip-passkey-status role="status" aria-live="polite" hidden></div>
        </div>
        <?php return ob_get_clean();
    }

    public static function render_standard_login_button(){
        if(is_user_logged_in() || !self::available()) return;
        self::enqueue_runtime();
        echo '<div class="dip-passkey-standard"><button type="button" class="dip-passkey-external" data-dip-passkey-login><span aria-hidden="true"></span><strong>'.esc_html__('Se connecter avec une Passkey','delicat-google-login').'</strong></button><div class="dip-passkey-status" data-dip-passkey-status role="status" aria-live="polite" hidden></div></div>';
    }

    /** wp_login_form(): the button inside the form, after the password field (as on wp-login.php). */
    public static function login_form_middle($html, $args = []) {
        ob_start();
        self::render_standard_login_button();
        return (string) $html . ob_get_clean();
    }

    /** The identity modal's <template> runs its own copy of passkeys.js inside its frame. */
    private static function rendering_identity_modal() {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5) as $frame) {
            if (($frame['class'] ?? '') === 'DIP_Native_Auth' && ($frame['function'] ?? '') === 'modal_markup') return true;
        }
        return false;
    }

    public static function render_login_button(){
        if(!self::available()) return '';
        if(!self::rendering_identity_modal()) self::enqueue_runtime();
        return '<button type="button" class="dipx-passkey-login" data-dip-passkey-login><span class="dipx-biometric-icons" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M7 17V11a4 4 0 0 1 4-4h6M31 7h6a4 4 0 0 1 4 4v6M41 31v6a4 4 0 0 1-4 4h-6M17 41h-6a4 4 0 0 1-4-4v-6M17 19v4M31 19v4M24 19v9h-3M17 32q7 6 14 0"/></svg><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M5 6a9 9 0 0 1 14 0M3 12a9 9 0 0 1 1-4M21 12a9 9 0 0 0-1-4M7 19c1-3 1-5 1-7a4 4 0 0 1 8 0c0 5-1 7-2 10M4 17c1-2 1-4 1-5a7 7 0 0 1 14 0c0 3 0 5-1 8M10 22c2-4 2-7 2-10"/></svg></span><strong>'.esc_html__('Connexion par clé d’accès','delicat-google-login').'</strong><small>'.esc_html__('Face ID · Touch ID · Code','delicat-google-login').'</small></button>';
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
