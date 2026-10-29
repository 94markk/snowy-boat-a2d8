<?php
defined('ABSPATH') || exit;

/**
 * 6.11.0 — Passkeys in the Android app (Credential Manager).
 *
 * Same RP ID, same credential store (user meta _dip_passkeys_v1) and same
 * WebAuthn checks as the website (DIP_Passkeys' CBOR decoder, COSE → PEM,
 * attestation, authenticator data and assertion verification): a passkey
 * created in the app works on the site and the other way round.
 *
 * What differs from the website:
 *  - ceremonies carry channel 'mobile' and are bound to the app installation
 *    and device (HMAC hashes, as the sessions) instead of a browser cookie,
 *    and registration/reauth ceremonies to the session row as well;
 *  - the accepted origins are ONLY android:apk-key-hash:<…> values computed
 *    from the « Application Android » fingerprints (DIP_Android_App), never
 *    https://<site>: a ceremony opened by the app cannot be finished by a web
 *    page, and the other way round;
 *  - sign-in is discoverable (no e-mail typed): the account is found by the
 *    userHandle, then the credential id must belong to that account;
 *  - registration is ONE system sheet: fmt none, AT, UP, UV, rpIdHash, origin
 *    and the ceremony binding are checked, there is no second « proof » sheet;
 *  - a successful sign-in opens an Identity mobile session
 *    (DIP_Mobile_API::issue_for_user(…, 'passkey')). User verification is
 *    required, so the assertion satisfies the account's second factor, as on
 *    the website.
 *  - the routes accept the standard WebAuthn JSON that Credential Manager
 *    returns ({id, rawId, type, response:{…}}) and flatten it here.
 */
final class DIP_Mobile_Passkeys {
    const NS = 'delicat-identity/v1';
    const CEREMONY_PREFIX = 'dip_mpk_c_';
    const LOCK_PREFIX = 'dip_mpk_l_';
    const CEREMONY_TTL = 300;
    const TIMEOUT_MS = 120000;
    const TRANSPORTS = ['usb', 'nfc', 'ble', 'internal', 'hybrid', 'smart-card'];
    /**
     * Refused sign-in assertions per account and 10 minutes (DESIGN-5.6
     * §3.3: 18). FAILURES only: a successful passkey sign-in is never
     * counted (owner rule, 2026-10-24: no usage limit).
     */
    const ACCOUNT_FAILURE_LIMIT = 18;
    const ACCOUNT_FAILURE_PREFIX = 'dip_mob_passkey_login_verify_user_';
    /**
     * 6.11.1: every login/options call stores a ceremony, so the address
     * keeps an all-attempts flood ceiling (disk-filling guard, as /mobile/google's
     * 500 and the website's 600), far above a carrier NAT address's human use.
     */
    const LOGIN_OPTIONS_ADDRESS_FLOOD = 1000;
    /** WebAuthn credential ids reach 1023 bytes: 1366 base64url characters (as the website's route). */
    const CREDENTIAL_ID_PATTERN = '[A-Za-z0-9_-]{20,1366}';

    private static $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('dip_mobile_rest_rules', [__CLASS__, 'firewall_rules']);
    }

    /** Strict REST firewall: the removal route accepts every credential id length (the built-in pattern stops at 160 characters). */
    public static function firewall_rules($rules) {
        $rules = is_array($rules) ? $rules : [];
        $rules[] = ['#^/delicat-identity/v1/mobile/passkeys/' . self::CREDENTIAL_ID_PATTERN . '$#', ['DELETE']];
        return $rules;
    }

    public static function routes() {
        $session = ['DIP_Mobile_API', 'logged_in'];
        register_rest_route(self::NS, '/mobile/passkeys/login/options', ['methods' => 'POST', 'callback' => [__CLASS__, 'login_options'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/mobile/passkeys/login/verify', ['methods' => 'POST', 'callback' => [__CLASS__, 'login_verify'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/mobile/passkeys/register/options', ['methods' => 'POST', 'callback' => [__CLASS__, 'register_options'], 'permission_callback' => $session]);
        register_rest_route(self::NS, '/mobile/passkeys/register/verify', ['methods' => 'POST', 'callback' => [__CLASS__, 'register_verify'], 'permission_callback' => $session]);
        register_rest_route(self::NS, '/mobile/passkeys', ['methods' => 'GET', 'callback' => [__CLASS__, 'list_passkeys'], 'permission_callback' => $session]);
        register_rest_route(self::NS, '/mobile/passkeys/(?P<credential>' . self::CREDENTIAL_ID_PATTERN . ')', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'remove'], 'permission_callback' => $session]);
        register_rest_route(self::NS, '/mobile/reauth/passkey/options', ['methods' => 'POST', 'callback' => [__CLASS__, 'reauth_options'], 'permission_callback' => $session]);
        register_rest_route(self::NS, '/mobile/reauth/passkey/verify', ['methods' => 'POST', 'callback' => [__CLASS__, 'reauth_verify'], 'permission_callback' => $session]);
    }

    /**
     * Passkeys allowed, mobile API on, HTTPS, OpenSSL, at least one valid app
     * fingerprint, and a /.well-known/assetlinks.json that really grants them
     * (DIP_Android_App::published()): without it Credential Manager refuses
     * every ceremony, so the app must not offer passkeys at all.
     */
    public static function enabled() {
        return class_exists('DIP_Passkeys') && DIP_Passkeys::available()
            && DIP_Mobile_API::enabled()
            && class_exists('DIP_Android_App') && (bool) DIP_Android_App::origins()
            && DIP_Android_App::published();
    }

    private static function gate() {
        if (!DIP_Mobile_API::enabled()) return new WP_Error('mobile_api_disabled', 'Mobile authentication is disabled.', ['status' => 403]);
        if (!is_ssl()) return new WP_Error('https_required', 'HTTPS is required.', ['status' => 403]);
        if (!self::enabled()) return new WP_Error('passkeys_disabled', 'Les clés d’accès ne sont pas disponibles.', ['status' => 403]);
        return true;
    }

    private static function session_or_error(WP_REST_Request $r) {
        $session = DIP_Mobile_API::current_session_for_request($r);
        return $session ?: new WP_Error('session_not_found', 'Mobile session not found.', ['status' => 401]);
    }

    // -------------------------------------------------------------------------
    // Ceremonies (channel 'mobile')
    // -------------------------------------------------------------------------

    private static function ceremony_key($id) {
        return self::CEREMONY_PREFIX . substr(hash_hmac('sha256', (string) $id, wp_salt('nonce')), 0, 48);
    }

    private static function create_ceremony($purpose, WP_REST_Request $r, array $extra = []) {
        $device = DIP_Mobile_API::request_device($r);
        $id = DIP_Passkeys::random_b64u(24);
        $challenge = DIP_Passkeys::random_b64u(32);
        $state = array_merge([
            'channel' => 'mobile',
            'purpose' => sanitize_key($purpose),
            'challenge' => $challenge,
            'rp_id' => DIP_Passkeys::rp_id(),
            'installation_hash' => (string) $device['installation_hash'],
            'device_hash' => (string) $device['device_hash'],
            'expires' => time() + self::CEREMONY_TTL,
        ], $extra);
        set_transient(self::ceremony_key($id), $state, self::CEREMONY_TTL);
        return ['id' => $id, 'challenge' => $challenge, 'state' => $state];
    }

    /**
     * One-time use: the add_option lock of the website's ceremonies, a claim
     * on the atomic counter table, then read-and-delete. The ceremony must be
     * a mobile one for $purpose, unexpired, and finished by the SAME
     * installation and device that opened it. WP_Error otherwise.
     * 6.11.1: an id with no stored ceremony is refused before any write (no
     * lock option, no counter row for random ids).
     */
    private static function consume_ceremony($id, $purpose, WP_REST_Request $r) {
        $id = is_string($id) ? trim($id) : '';
        $invalid = new WP_Error('passkey_ceremony_invalid', 'Cette vérification a expiré. Réessayez.', ['status' => 400]);
        if (!preg_match('/^[A-Za-z0-9_-]{20,160}$/D', $id)) return $invalid;
        if (!is_array(get_transient(self::ceremony_key($id)))) return $invalid;
        $lock = self::LOCK_PREFIX . substr(hash_hmac('sha256', $id, wp_salt('secure_auth')), 0, 40);
        $now = time();
        if (!add_option($lock, $now, '', false)) {
            $existing = (int) get_option($lock, 0);
            if ($existing > 0 && ($existing + 10) < $now) delete_option($lock);
            if (!add_option($lock, $now, '', false)) return new WP_Error('passkey_ceremony_busy', 'Cette vérification est déjà en cours.', ['status' => 409]);
        }
        try {
            $key = self::ceremony_key($id);
            if (DIP_Lockout::counter_hit($key . '_used', 2 * self::CEREMONY_TTL) !== 1) { delete_transient($key); return $invalid; }
            $state = get_transient($key);
            delete_transient($key); // one-time regardless of outcome
            if (!is_array($state) || ($state['channel'] ?? '') !== 'mobile' || ($state['purpose'] ?? '') !== sanitize_key($purpose) || (int) ($state['expires'] ?? 0) < time()) return $invalid;
            $device = DIP_Mobile_API::request_device($r);
            if (!hash_equals((string) $state['installation_hash'], (string) $device['installation_hash']) || !hash_equals((string) $state['device_hash'], (string) $device['device_hash'])) {
                return new WP_Error('passkey_ceremony_device', 'Cette vérification a été commencée sur un autre appareil.', ['status' => 403]);
            }
            return $state;
        } finally {
            delete_option($lock);
        }
    }

    // -------------------------------------------------------------------------
    // Credential Manager JSON
    // -------------------------------------------------------------------------

    /** Canonical base64url (no padding) of a base64 / base64url value, '' when not one. */
    private static function b64u($value) {
        if (!is_string($value)) return '';
        $value = preg_replace('/\s+/', '', $value);
        if ($value === '' || strlen($value) > 400000 || !preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/D', $value)) return '';
        return rtrim(strtr($value, '+/', '-_'), '=');
    }

    /**
     * The standard WebAuthn JSON ({id, rawId, type, authenticatorAttachment?,
     * response:{clientDataJSON, attestationObject | authenticatorData +
     * signature + userHandle, transports?}}) flattened into the shape
     * DIP_Passkeys reads. A JSON string is accepted too. Null when unusable.
     */
    public static function normalize_credential($raw) {
        if (is_string($raw)) {
            if (strlen($raw) > 400000) return null;
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) return null;
        if (isset($raw['type']) && $raw['type'] !== 'public-key') return null;
        $response = isset($raw['response']) && is_array($raw['response']) ? $raw['response'] : $raw;
        $transports = isset($response['transports']) && is_array($response['transports']) ? $response['transports'] : [];
        $flat = [
            'id' => self::b64u($raw['id'] ?? ''),
            'rawId' => self::b64u($raw['rawId'] ?? ''),
            'clientDataJSON' => self::b64u($response['clientDataJSON'] ?? ''),
            'attestationObject' => self::b64u($response['attestationObject'] ?? ''),
            'authenticatorData' => self::b64u($response['authenticatorData'] ?? ''),
            'signature' => self::b64u($response['signature'] ?? ''),
            'userHandle' => self::b64u($response['userHandle'] ?? ''),
            'transports' => array_values(array_intersect(array_map('sanitize_key', array_filter($transports, 'is_string')), self::TRANSPORTS)),
        ];
        if (($flat['id'] === '' && $flat['rawId'] === '') || $flat['clientDataJSON'] === '') return null;
        return $flat;
    }

    /** The configured app origin named by clientDataJSON, or '' (a web origin is never accepted here). */
    private static function matched_origin($client_data) {
        $raw = DIP_Passkeys::b64u_decode($client_data);
        if ($raw === false || strlen($raw) > 65536) return '';
        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['origin']) || !is_string($json['origin'])) return '';
        $origin = rtrim($json['origin'], '/');
        foreach (DIP_Android_App::origins() as $allowed) {
            if (hash_equals($allowed, $origin)) return $allowed;
        }
        return '';
    }

    /** The one account whose passkey user handle this is, or 0. */
    private static function user_by_handle($handle) {
        if ($handle === '' || DIP_Passkeys::b64u_decode($handle) === false) return 0;
        $ids = get_users(['meta_key' => DIP_Passkeys::META_USER_HANDLE, 'meta_value' => $handle, 'number' => 2, 'fields' => 'ID', 'count_total' => false]);
        $ids = array_values(array_map('absint', (array) $ids));
        return count($ids) === 1 ? (int) $ids[0] : 0;
    }

    private static function entry_payload(array $entry) {
        return [
            'id' => (string) $entry['id'],
            'label' => (string) ($entry['label'] ?? ''),
            'created' => (int) ($entry['created'] ?? 0),
            'last_used' => !empty($entry['last_used']) ? (int) $entry['last_used'] : null,
            'transports' => array_values((array) ($entry['transports'] ?? [])),
        ];
    }

    // -------------------------------------------------------------------------
    // Sign-in (public)
    // -------------------------------------------------------------------------

    /** POST /mobile/passkeys/login/options — a discoverable sign-in, no e-mail typed. */
    public static function login_options(WP_REST_Request $r) {
        // Failures only (owner rule, 2026-10-24): 10 per installation, 100 per
        // address; every request counts only against the flood guard
        // (DIP_Mobile_API::SESSION_FLOOD_LIMIT per installation) and, since
        // each call stores a ceremony, the address flood ceiling (6.11.1).
        $gate = DIP_Mobile_API::throttle('passkey_login_options', 10, 10 * MINUTE_IN_SECONDS, $r, self::LOGIN_OPTIONS_ADDRESS_FLOOD, null, true);
        if (is_wp_error($gate)) return $gate;
        $ok = self::gate();
        if (is_wp_error($ok)) return $ok;
        if (!DIP_Mobile_API::valid_device_request($r)) { if ($e = DIP_Mobile_API::proof_failed($gate, 'device_required')) return $e; return new WP_Error('device_required', 'A valid device and installation identifier are required.', ['status' => 400]); }
        $limited = DIP_Mobile_API::proof_accepted($gate);
        if ($limited) return $limited;
        $ceremony = self::create_ceremony('login', $r);
        return new WP_REST_Response(['ceremony_id' => $ceremony['id'], 'publicKey' => [
            'challenge' => $ceremony['challenge'],
            'rpId' => DIP_Passkeys::rp_id(),
            'timeout' => self::TIMEOUT_MS,
            'userVerification' => 'required',
            'allowCredentials' => [],
        ]], 200);
    }

    /**
     * Every refused sign-in: counted against the address, one generic 401.
     * Once the account is known (its user handle resolved), the refusal also
     * counts against that account's failure bucket (ACCOUNT_FAILURE_LIMIT).
     * 6.11.1: while the address failure ceiling holds, the 429 at once and
     * nothing written.
     */
    private static function login_failed($gate, $reason, $user_id = 0) {
        $blocked = DIP_Mobile_API::proof_failed($gate, $reason);
        if ($blocked) return $blocked;
        if ($user_id > 0) DIP_Lockout::counter_hit(self::account_failure_key($user_id), 10 * MINUTE_IN_SECONDS);
        if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_passkey_login_failed', 'warning', absint($user_id), ['reason' => sanitize_key($reason)]);
        return new WP_Error('authentication_failed', 'Mobile authentication failed.', ['status' => 401]);
    }

    /**
     * 6.11.1: the account's failure bucket is per account AND address, as on
     * the website: a third party holding a customer's user handle can no
     * longer lock that customer's app passkey sign-in everywhere.
     */
    private static function account_failure_key($user_id) {
        return self::ACCOUNT_FAILURE_PREFIX . substr(hash_hmac('sha256', DIP_Native_Auth::throttle_ip() . '|uid:' . absint($user_id), wp_salt('nonce')), 0, 32);
    }

    /** POST /mobile/passkeys/login/verify {ceremony_id, credential}. */
    public static function login_verify(WP_REST_Request $r) {
        // Failures only: 10 per installation, 100 per address, 18 per account
        // and address (below); every request counts only against the flood guard.
        $gate = DIP_Mobile_API::throttle('passkey_login_verify', 10, 10 * MINUTE_IN_SECONDS, $r, 0, null, true);
        if (is_wp_error($gate)) return $gate;
        $ok = self::gate();
        if (is_wp_error($ok)) return $ok;
        if (!DIP_Mobile_API::valid_device_request($r)) return self::login_failed($gate, 'device_required');
        $state = self::consume_ceremony($r->get_param('ceremony_id'), 'login', $r);
        if (is_wp_error($state)) return self::login_failed($gate, $state->get_error_code());
        $credential = self::normalize_credential($r->get_param('credential'));
        if (!$credential || $credential['authenticatorData'] === '' || $credential['signature'] === '') return self::login_failed($gate, 'credential');
        $origin = self::matched_origin($credential['clientDataJSON']);
        if ($origin === '') return self::login_failed($gate, 'origin');
        $uid = self::user_by_handle($credential['userHandle']);
        if (!$uid) return self::login_failed($gate, 'unknown_user');
        // Read here, counted by login_failed() only: successful sign-ins never
        // touch it. Like the address ceiling, requests already in flight when
        // it is reached still finish.
        $account_key = self::account_failure_key($uid);
        if (DIP_Lockout::counter_peek($account_key)['count'] >= self::ACCOUNT_FAILURE_LIMIT) return DIP_Mobile_API::rate_limited($gate, 'account', $account_key);
        $credential_id = DIP_Passkeys::request_credential_id($credential);
        list($entry) = DIP_Passkeys::find_credential($uid, $credential_id);
        if ($credential_id === '' || !is_array($entry)) return self::login_failed($gate, 'unknown_credential', $uid);
        $state['user_id'] = $uid;
        $state['origin'] = $origin;
        $result = DIP_Passkeys::verify_assertion($credential, $state);
        if (is_wp_error($result)) return self::login_failed($gate, $result->get_error_code(), $uid);
        if (!DIP_Mobile_API::mobile_user_allowed($uid)) return DIP_Mobile_API::denied('mobile_account_not_allowed');
        $limited = DIP_Mobile_API::proof_accepted($gate);
        if ($limited) return $limited;
        // UV was required and verified: the assertion satisfies the second factor (see issue_for_user()).
        $issued = DIP_Mobile_API::issue_for_user($uid, $r, 'passkey');
        if (is_wp_error($issued)) return $issued;
        if (class_exists('DIP_Audit')) {
            DIP_Audit::record('mobile_passkey_login', 'info', $uid, ['credential' => substr(hash('sha256', (string) $credential_id), 0, 16)]);
            DIP_Audit::record('login_success', 'info', $uid, ['method' => 'mobile_passkey']);
        }
        return $issued;
    }

    // -------------------------------------------------------------------------
    // Registration and management (session)
    // -------------------------------------------------------------------------

    /** POST /mobile/passkeys/register/options {label} (session + recent proof). */
    public static function register_options(WP_REST_Request $r) {
        $ok = self::gate();
        if (is_wp_error($ok)) return $ok;
        $session = self::session_or_error($r);
        if (is_wp_error($session)) return $session;
        $proof = DIP_Mobile_API::require_recent_proof($session);
        if ($proof) return $proof;
        // Flood guard only (one stored ceremony per call), after the proof: a 403 is never counted.
        $limited = DIP_Mobile_API::session_route_limit('passkey_register', (int) $session->id);
        if ($limited) return $limited;
        $uid = (int) $session->user_id;
        $user = get_userdata($uid);
        if (!$user) return new WP_Error('session_not_found', 'Mobile session not found.', ['status' => 401]);
        $credentials = DIP_Passkeys::credentials($uid);
        $max = DIP_Passkeys::max_per_user();
        if (count($credentials) >= $max) return new WP_Error('passkey_limit', sprintf('Vous avez déjà %d clés d’accès. Supprimez-en une d’abord.', $max), ['status' => 409]);
        $label = $r->get_param('label');
        $label = is_string($label) ? sanitize_text_field($label) : '';
        if ($label === '') $label = sanitize_text_field((string) $session->device_name);
        if ($label === '') $label = 'Mon téléphone';
        $label = function_exists('mb_substr') ? mb_substr($label, 0, 60) : substr($label, 0, 60);
        $ceremony = self::create_ceremony('register', $r, ['user_id' => $uid, 'session_id' => (int) $session->id, 'label' => $label]);
        return new WP_REST_Response(['ceremony_id' => $ceremony['id'], 'publicKey' => [
            'rp' => ['id' => DIP_Passkeys::rp_id(), 'name' => sanitize_text_field(get_bloginfo('name') ?: 'Delicat Store')],
            'user' => [
                'id' => DIP_Passkeys::user_handle($uid),
                'name' => sanitize_email($user->user_email) ?: sanitize_user($user->user_login),
                'displayName' => sanitize_text_field($user->display_name ?: $user->user_login),
            ],
            'challenge' => $ceremony['challenge'],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'timeout' => self::TIMEOUT_MS,
            'attestation' => 'none',
            'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials' => DIP_Passkeys::credential_descriptors($credentials),
        ]], 200);
    }

    /** POST /mobile/passkeys/register/verify {ceremony_id, credential}: one step on mobile. */
    public static function register_verify(WP_REST_Request $r) {
        $ok = self::gate();
        if (is_wp_error($ok)) return $ok;
        $session = self::session_or_error($r);
        if (is_wp_error($session)) return $session;
        // 6.11.1: flood guard only (a ceremony claim per call), as register/options.
        $limited = DIP_Mobile_API::session_route_limit('passkey_register_verify', (int) $session->id);
        if ($limited) return $limited;
        $uid = (int) $session->user_id;
        $state = self::consume_ceremony($r->get_param('ceremony_id'), 'register', $r);
        if (is_wp_error($state)) return $state;
        if ((int) ($state['session_id'] ?? 0) !== (int) $session->id || (int) ($state['user_id'] ?? 0) !== $uid) {
            return new WP_Error('passkey_ceremony_mismatch', 'Cette vérification appartient à une autre session.', ['status' => 403]);
        }
        $credential = self::normalize_credential($r->get_param('credential'));
        if (!$credential || $credential['attestationObject'] === '') return new WP_Error('passkey_invalid', 'Clé d’accès invalide.', ['status' => 400]);
        $origin = self::matched_origin($credential['clientDataJSON']);
        if ($origin === '') {
            if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_passkey_origin_refused', 'critical', $uid);
            return new WP_Error('passkey_origin', 'Origine de la clé d’accès refusée.', ['status' => 403]);
        }
        $state['origin'] = $origin;
        $client = DIP_Passkeys::verify_client_data($credential['clientDataJSON'], $state, 'webauthn.create');
        if (is_wp_error($client)) return $client;
        $attestation = DIP_Passkeys::parse_attestation($credential['attestationObject'], $state);
        if (is_wp_error($attestation)) return $attestation;
        $request_id = DIP_Passkeys::request_credential_id($credential);
        if ($request_id === '' || !hash_equals((string) $attestation['credential_id'], $request_id)) return new WP_Error('dip_passkey_id_mismatch', 'Identifiant Passkey incohérent.', ['status' => 400]);
        if (!DIP_Passkeys::acquire_user_lock($uid, 10)) return new WP_Error('passkey_busy', 'Une autre modification de sécurité est en cours. Réessayez.', ['status' => 409]);
        try {
            $credentials = DIP_Passkeys::credentials($uid);
            foreach ($credentials as $existing) {
                if (!empty($existing['id']) && hash_equals((string) $existing['id'], $request_id)) return new WP_Error('passkey_exists', 'Cette clé d’accès est déjà enregistrée.', ['status' => 409]);
            }
            $max = DIP_Passkeys::max_per_user();
            if (count($credentials) >= $max) return new WP_Error('passkey_limit', sprintf('Vous avez déjà %d clés d’accès. Supprimez-en une d’abord.', $max), ['status' => 409]);
            $entry = [
                'id' => $attestation['credential_id'], 'public_key' => $attestation['public_key'], 'alg' => (int) $attestation['alg'],
                'sign_count' => (int) $attestation['sign_count'],
                'transports' => $credential['transports'],
                'label' => sanitize_text_field((string) ($state['label'] ?? 'Mon téléphone')),
                'created' => time(), 'last_used' => 0,
            ];
            $credentials[] = $entry;
            if (!DIP_Passkeys::save_credentials($uid, $credentials)) return new WP_Error('passkey_save', 'Impossible d’enregistrer cette clé d’accès.', ['status' => 500]);
        } finally {
            DIP_Passkeys::release_user_lock($uid);
        }
        if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_passkey_registered', 'notice', $uid, ['alg' => (int) $entry['alg'], 'credential' => substr(hash('sha256', (string) $entry['id']), 0, 16)]);
        DIP_Passkeys::security_email($uid, __('Nouvelle Passkey ajoutée à votre compte Delicat', 'delicat-google-login'), sprintf('Une nouvelle Passkey (« %s ») a été enregistrée depuis l’application Delicat Store. Si vous n’êtes pas à l’origine de cette action, changez votre mot de passe et supprimez immédiatement cette Passkey depuis le Centre de sécurité.', $entry['label']));
        do_action('dip_mobile_credential_changed', $uid, 'passkey', 'added', (string) $entry['label']);
        return new WP_REST_Response(['registered' => true, 'passkey' => self::entry_payload($entry)], 200);
    }

    /** GET /mobile/passkeys. */
    public static function list_passkeys(WP_REST_Request $r) {
        $ok = self::gate();
        if (is_wp_error($ok)) return $ok;
        $session = self::session_or_error($r);
        if (is_wp_error($session)) return $session;
        $out = [];
        foreach (DIP_Passkeys::credentials((int) $session->user_id) as $entry) $out[] = self::entry_payload($entry);
        return new WP_REST_Response(['passkeys' => $out, 'max' => DIP_Passkeys::max_per_user()], 200);
    }

    /** DELETE /mobile/passkeys/{id} (session + recent proof). The last way in is kept. */
    public static function remove(WP_REST_Request $r) {
        $ok = self::gate();
        if (is_wp_error($ok)) return $ok;
        $session = self::session_or_error($r);
        if (is_wp_error($session)) return $session;
        $proof = DIP_Mobile_API::require_recent_proof($session);
        if ($proof) return $proof;
        $uid = (int) $session->user_id;
        // The path only: a body field « credential » never picks another passkey.
        $url = $r->get_url_params();
        $id = isset($url['credential']) && is_string($url['credential']) ? sanitize_text_field($url['credential']) : '';
        if ($id === '') return new WP_Error('passkey_not_found', 'Cette clé d’accès n’existe plus.', ['status' => 404]);
        if (!DIP_Passkeys::acquire_user_lock($uid, 10)) return new WP_Error('passkey_busy', 'Une autre modification de sécurité est en cours. Réessayez.', ['status' => 409]);
        try {
            $kept = [];
            $removed = null;
            foreach (DIP_Passkeys::credentials($uid) as $entry) {
                if (!$removed && !empty($entry['id']) && hash_equals((string) $entry['id'], $id)) { $removed = $entry; continue; }
                $kept[] = $entry;
            }
            if (!$removed) return new WP_Error('passkey_not_found', 'Cette clé d’accès n’existe plus.', ['status' => 404]);
            if (!$kept && !DIP_Passkeys::has_other_primary_login_method($uid)) return new WP_Error('passkey_last_method', 'Gardez au moins un moyen de vous connecter.', ['status' => 409]);
            DIP_Passkeys::save_credentials($uid, $kept);
        } finally {
            DIP_Passkeys::release_user_lock($uid);
        }
        if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_passkey_removed', 'warning', $uid, ['credential' => substr(hash('sha256', $id), 0, 16)]);
        DIP_Passkeys::security_email($uid, __('Passkey supprimée de votre compte Delicat', 'delicat-google-login'), __('Une Passkey a été supprimée de votre compte. Si vous n’êtes pas à l’origine de cette action, changez votre mot de passe et vérifiez immédiatement vos sessions.', 'delicat-google-login'));
        do_action('dip_mobile_credential_changed', $uid, 'passkey', 'removed', (string) ($removed['label'] ?? ''));
        return new WP_REST_Response(['removed' => true, 'count' => count($kept)], 200);
    }

    // -------------------------------------------------------------------------
    // Re-authentication with a passkey (session)
    // -------------------------------------------------------------------------

    /** POST /mobile/reauth/passkey/options: one of THIS account's passkeys. */
    public static function reauth_options(WP_REST_Request $r) {
        $ok = self::gate();
        if (is_wp_error($ok)) return $ok;
        $session = self::session_or_error($r);
        if (is_wp_error($session)) return $session;
        // Flood guard only (one stored ceremony per call).
        $limited = DIP_Mobile_API::session_route_limit('passkey_reauth_options', (int) $session->id);
        if ($limited) return $limited;
        $uid = (int) $session->user_id;
        $credentials = DIP_Passkeys::credentials($uid);
        if (!$credentials) return new WP_Error('passkey_none', 'Aucune clé d’accès sur ce compte.', ['status' => 404]);
        $ceremony = self::create_ceremony('reauth', $r, ['user_id' => $uid, 'session_id' => (int) $session->id]);
        return new WP_REST_Response(['ceremony_id' => $ceremony['id'], 'publicKey' => [
            'challenge' => $ceremony['challenge'],
            'rpId' => DIP_Passkeys::rp_id(),
            'timeout' => self::TIMEOUT_MS,
            'userVerification' => 'required',
            'allowCredentials' => DIP_Passkeys::credential_descriptors($credentials),
        ]], 200);
    }

    /** POST /mobile/reauth/passkey/verify {ceremony_id, credential} → {proven_until}. */
    public static function reauth_verify(WP_REST_Request $r) {
        $ok = self::gate();
        if (is_wp_error($ok)) return $ok;
        $session = self::session_or_error($r);
        if (is_wp_error($session)) return $session;
        $gate = DIP_Mobile_API::reauth_gate($session);
        if (is_wp_error($gate)) return $gate;
        $uid = (int) $session->user_id;
        $refuse = static function ($reason) use ($gate, $uid) {
            DIP_Mobile_API::proof_failed($gate, $reason);
            if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_reauth_failed', 'warning', $uid, ['method' => 'passkey', 'reason' => sanitize_key($reason)]);
            return new WP_Error('authentication_failed', 'Mobile authentication failed.', ['status' => 401]);
        };
        $state = self::consume_ceremony($r->get_param('ceremony_id'), 'reauth', $r);
        if (is_wp_error($state)) return $refuse($state->get_error_code());
        if ((int) ($state['session_id'] ?? 0) !== (int) $session->id || (int) ($state['user_id'] ?? 0) !== $uid) return $refuse('session_mismatch');
        $credential = self::normalize_credential($r->get_param('credential'));
        if (!$credential || $credential['authenticatorData'] === '' || $credential['signature'] === '') return $refuse('credential');
        $origin = self::matched_origin($credential['clientDataJSON']);
        if ($origin === '') return $refuse('origin');
        $state['user_id'] = $uid;
        $state['origin'] = $origin;
        $result = DIP_Passkeys::verify_assertion($credential, $state);
        if (is_wp_error($result)) return $refuse($result->get_error_code());
        return DIP_Mobile_API::proven_response($session, 'passkey');
    }
}
