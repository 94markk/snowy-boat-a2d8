<?php
defined('ABSPATH') || exit;

/**
 * TOTP two-factor authentication with encrypted secrets and hashed one-time
 * recovery codes. No third-party service is required.
 */
final class DIP_Two_Factor {
    const META_ENABLED = '_dip_totp_enabled';
    const META_SECRET = '_dip_totp_secret_v1';
    const META_PENDING = '_dip_totp_pending_v1';
    const META_PENDING_EXPIRES = '_dip_totp_pending_expires';
    const META_RECOVERY = '_dip_totp_recovery_hashes_v1';
    const META_LAST_COUNTER = '_dip_totp_last_counter';
    const LOCK_PREFIX = 'dip_2fa_atomic_';
    const NONCE = 'dip_two_factor_action';
    const PREAUTH_COOKIE = 'dip_2fa_pre';
    const STEP = 30;
    const DIGITS = 6;

    private static $password_gate_verified_user = 0;
    private static $cookie_authorized_user = 0;

    public static function init() {
        add_filter('authenticate', [__CLASS__, 'enforce_password_second_factor'], PHP_INT_MAX - 20, 3);
        // A final boundary prevents a later third-party authenticator from replacing
        // a 2FA WP_Error with a WP_User object.
        add_filter('authenticate', [__CLASS__, 'enforce_final_password_gate'], PHP_INT_MAX - 1, 3);
        // Block direct wp_set_auth_cookie() paths for 2FA-enabled users unless
        // this request has already completed the Delicat second factor.
        add_filter('send_auth_cookies', [__CLASS__, 'filter_auth_cookies'], 30, 6);
        // Application Passwords are separate API credentials and cannot prompt
        // for TOTP. Keep their management UI visible, but reject their use for
        // 2FA-enabled accounts unless a developer explicitly opts in.
        add_action('wp_authenticate_application_password_errors', [__CLASS__, 'block_application_password_auth'], 100, 4);
        add_action('login_form', [__CLASS__, 'render_login_field']);
        add_action('woocommerce_login_form', [__CLASS__, 'render_login_field']);
        add_action('template_redirect', [__CLASS__, 'maybe_render_challenge'], 1);
        add_action('admin_post_nopriv_dip_2fa_complete_challenge', [__CLASS__, 'complete_web_challenge']);
        add_action('admin_post_dip_2fa_complete_challenge', [__CLASS__, 'complete_web_challenge']);
        add_action('admin_post_dip_2fa_begin', [__CLASS__, 'begin_enrollment']);
        add_action('admin_post_dip_2fa_confirm', [__CLASS__, 'confirm_enrollment']);
        add_action('admin_post_dip_2fa_disable', [__CLASS__, 'disable']);
        add_action('admin_post_dip_2fa_recovery_regen', [__CLASS__, 'regenerate_recovery']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('delicat identity-2fa-reset', [__CLASS__, 'cli_reset']);
        }
    }

    private static function enrollment_available() {
        if (!class_exists('DIP_Plugin')) return true;
        $s = wp_parse_args((array)get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
        return ($s['two_factor_available'] ?? 'yes') === 'yes';
    }

    public static function is_enabled($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        // Fail closed: once the account has explicitly enabled 2FA, an
        // unavailable/corrupted crypto engine must never silently downgrade the
        // login to one factor. Recovery codes still work without decrypting the
        // TOTP secret.
        return $user_id > 0 && get_user_meta($user_id, self::META_ENABLED, true) === 'yes';
    }

    private static function secret($user_id) {
        $stored = (string) get_user_meta(absint($user_id), self::META_SECRET, true);
        if ($stored === '') return '';
        $plain = class_exists('DIP_Crypto') ? DIP_Crypto::decrypt($stored) : '';
        return preg_match('/^[A-Z2-7]{16,64}$/D', $plain) ? $plain : '';
    }

    private static function pending_secret($user_id) {
        $expires = absint(get_user_meta(absint($user_id), self::META_PENDING_EXPIRES, true));
        if (!$expires || time() > $expires) {
            delete_user_meta($user_id, self::META_PENDING);
            delete_user_meta($user_id, self::META_PENDING_EXPIRES);
            return '';
        }
        $stored = (string) get_user_meta(absint($user_id), self::META_PENDING, true);
        $plain = class_exists('DIP_Crypto') ? DIP_Crypto::decrypt($stored) : '';
        return preg_match('/^[A-Z2-7]{16,64}$/D', $plain) ? $plain : '';
    }

    private static function base32_encode($data) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0, $len = strlen($data); $i < $len; $i++) $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        $out = '';
        for ($i = 0, $len = strlen($bits); $i < $len; $i += 5) {
            $chunk = substr($bits, $i, 5);
            if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= $alphabet[bindec($chunk)];
        }
        return $out;
    }

    private static function base32_decode($value) {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', (string) $value));
        if ($value === '') return '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $pos = strpos($alphabet, $value[$i]);
            if ($pos === false) return '';
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0, $len = strlen($bits) - 7; $i < $len; $i += 8) $out .= chr(bindec(substr($bits, $i, 8)));
        return $out;
    }

    private static function generate_secret() {
        try { return self::base32_encode(random_bytes(20)); }
        catch (Throwable $e) { return ''; }
    }

    private static function hotp($secret, $counter) {
        $key = self::base32_decode($secret);
        if ($key === '') return '';
        $counter = max(0, (int) $counter);
        $high = (int) floor($counter / 4294967296);
        $low = $counter % 4294967296;
        $binary_counter = pack('N2', $high, $low);
        $hash = hash_hmac('sha1', $binary_counter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        $otp = $binary % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function matching_counter($secret, $code, $window = 1) {
        $code = preg_replace('/\D+/', '', (string) $code);
        if (!preg_match('/^\d{6}$/D', $code)) return false;
        $counter = (int) floor(time() / self::STEP);
        $window = min(2, max(0, absint($window)));
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = $counter + $i;
            $expected = self::hotp($secret, $candidate);
            if ($expected !== '' && hash_equals($expected, $code)) return $candidate;
        }
        return false;
    }

    public static function verify_totp_secret($secret, $code, $window = 1) {
        return self::matching_counter($secret, $code, $window) !== false;
    }

    public static function verify_totp($user_id, $code) {
        $user_id = absint($user_id);
        if (!$user_id) return false;
        $secret = self::secret($user_id);
        if ($secret === '') return false;
        $counter = self::matching_counter($secret, $code, 1);
        if ($counter === false) return false;

        // Serialize replay-state updates. add_option() relies on WordPress'
        // unique option_name index, which is substantially safer than a
        // transient get/set race when two requests present the same TOTP.
        if (!self::acquire_lock('totp', $user_id, 6)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_concurrent_attempt_blocked', 'warning', $user_id, ['factor'=>'totp']);
            return false;
        }
        try {
            $last = (int) get_user_meta($user_id, self::META_LAST_COUNTER, true);
            if ($last > 0 && $counter <= $last) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_replay_blocked', 'warning', $user_id);
                return false;
            }
            update_user_meta($user_id, self::META_LAST_COUNTER, (int)$counter);
            return true;
        } finally {
            self::release_lock('totp', $user_id);
        }
    }

    private static function lock_key($scope, $user_id) {
        return self::LOCK_PREFIX . sanitize_key($scope) . '_' . absint($user_id);
    }

    private static function acquire_lock($scope, $user_id, $ttl = 6) {
        $key = self::lock_key($scope, $user_id);
        $now = time();
        $ttl = min(30, max(2, absint($ttl)));
        if (add_option($key, $now, '', false)) return true;
        $existing = (int) get_option($key, 0);
        if ($existing > 0 && ($existing + $ttl) < $now) {
            delete_option($key);
            return add_option($key, $now, '', false);
        }
        return false;
    }

    private static function release_lock($scope, $user_id) {
        delete_option(self::lock_key($scope, $user_id));
    }

    private static function normalize_recovery($code) {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $code));
    }

    private static function recovery_hashes($user_id) {
        $raw = get_user_meta(absint($user_id), self::META_RECOVERY, true);
        return is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
    }

    private static function verify_recovery($user_id, $code, $consume = true) {
        $user_id = absint($user_id);
        $normalized = self::normalize_recovery($code);
        if (!$user_id || strlen($normalized) < 8 || strlen($normalized) > 24) return false;
        if ($consume && !self::acquire_lock('recovery', $user_id, 8)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_concurrent_attempt_blocked', 'warning', $user_id, ['factor'=>'recovery']);
            return false;
        }
        try {
            $hashes = self::recovery_hashes($user_id);
            foreach ($hashes as $index => $hash) {
                if (wp_check_password($normalized, $hash, $user_id)) {
                    if ($consume) {
                        unset($hashes[$index]);
                        update_user_meta($user_id, self::META_RECOVERY, array_values($hashes));
                        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_recovery_code_used', 'warning', $user_id, ['remaining'=>count($hashes)]);
                    }
                    return true;
                }
            }
            return false;
        } finally {
            if ($consume) self::release_lock('recovery', $user_id);
        }
    }

    public static function verify_factor($user_id, $code, $consume_recovery = true) {
        $user_id = absint($user_id);
        if (!$user_id || !self::is_enabled($user_id)) return false;
        if (self::verify_totp($user_id, $code)) return true;
        return self::verify_recovery($user_id, $code, $consume_recovery);
    }

    private static function factor_rate_keys($user_id) {
        $user_id = absint($user_id);
        $ip = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::client_ip() : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        return [
            ['key'=>'dip_2fa_rate_pair_' . substr(hash_hmac('sha256', $user_id . '|' . $ip, wp_salt('nonce')), 0, 40), 'limit'=>8],
            // An account-wide bucket prevents an attacker from bypassing the
            // pair limit by rotating source IPs while guessing a 6-digit code.
            ['key'=>'dip_2fa_rate_account_' . substr(hash_hmac('sha256', (string)$user_id, wp_salt('secure_auth')), 0, 40), 'limit'=>20],
        ];
    }

    public static function factor_attempt_allowed($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return false;
        // Serialize bucket updates so parallel guesses cannot all read the same
        // pre-increment counter. A concurrent request is denied rather than
        // allowed through a race window.
        if (!self::acquire_lock('rate', $user_id, 4)) return false;
        try {
            $states = [];
            foreach (self::factor_rate_keys($user_id) as $bucket) {
                $state = get_transient($bucket['key']);
                if (!is_array($state) || empty($state['reset']) || (int)$state['reset'] <= time()) {
                    $state = ['count'=>0,'reset'=>time()+10*MINUTE_IN_SECONDS];
                }
                if ((int)($state['count'] ?? 0) >= (int)$bucket['limit']) return false;
                $states[] = [$bucket, $state];
            }
            foreach ($states as $entry) {
                list($bucket, $state) = $entry;
                $state['count'] = (int)($state['count'] ?? 0) + 1;
                set_transient($bucket['key'], $state, max(1, (int)$state['reset'] - time()));
            }
            return true;
        } finally {
            self::release_lock('rate', $user_id);
        }
    }

    public static function clear_factor_rate($user_id) {
        foreach (self::factor_rate_keys(absint($user_id)) as $bucket) delete_transient($bucket['key']);
    }

    public static function enforce_password_second_factor($user, $username, $password) {
        if (!($user instanceof WP_User) || !self::is_enabled($user->ID)) return $user;
        if (!is_ssl()) return new WP_Error('dip_2fa_https_required', __('HTTPS est requis pour la vérification à deux facteurs.', 'delicat-google-login'));
        $code = sanitize_text_field(wp_unslash($_POST['dip_2fa_code'] ?? ''));
        if ($code === '') return new WP_Error('dip_2fa_required', __('Code de vérification à deux facteurs requis.', 'delicat-google-login'));
        if (!self::factor_attempt_allowed($user->ID)) return new WP_Error('dip_2fa_rate_limited', __('Trop de tentatives 2FA. Réessayez dans quelques minutes.', 'delicat-google-login'));
        if (!self::verify_factor($user->ID, $code, true)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_login_failed', 'warning', $user->ID);
            return new WP_Error('dip_2fa_invalid', __('Code 2FA ou code de récupération invalide.', 'delicat-google-login'));
        }
        self::$password_gate_verified_user = (int) $user->ID;
        self::clear_factor_rate($user->ID);
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_login_success', 'info', $user->ID);
        return $user;
    }

    public static function password_gate_verified($user_id) {
        return absint($user_id) > 0 && self::$password_gate_verified_user === absint($user_id);
    }

    public static function enforce_final_password_gate($user, $username, $password) {
        if (!($user instanceof WP_User) || !self::is_enabled($user->ID)) return $user;
        // Only guard interactive/password authentication here. Social and
        // passwordless flows are held before cookie issuance by the dedicated
        // browser challenge and do not provide the WordPress password.
        if ((string)$password === '') return $user;
        if (!is_ssl()) return new WP_Error('dip_2fa_https_required', __('HTTPS est requis pour la vérification à deux facteurs.', 'delicat-google-login'));
        if (self::password_gate_verified($user->ID)) return $user;
        $code = sanitize_text_field(wp_unslash($_POST['dip_2fa_code'] ?? ''));
        return new WP_Error(
            $code === '' ? 'dip_2fa_required' : 'dip_2fa_invalid',
            $code === '' ? __('Code de vérification à deux facteurs requis.', 'delicat-google-login') : __('Code 2FA ou code de récupération invalide.', 'delicat-google-login')
        );
    }

    public static function authorize_cookie_once($user_id) {
        self::$cookie_authorized_user = absint($user_id);
    }

    public static function filter_auth_cookies($send, $expire, $expiration, $user_id, $scheme, $token) {
        $user_id = absint($user_id);
        if (!$send || !$user_id || !self::is_enabled($user_id)) return $send;
        $allowed = self::password_gate_verified($user_id) || self::$cookie_authorized_user === $user_id;
        $allowed = (bool) apply_filters('dip_two_factor_allow_auth_cookie', $allowed, $user_id, $scheme);
        if ($allowed) {
            self::$cookie_authorized_user = 0;
            return $send;
        }
        // wp_set_auth_cookie() creates the session token before this filter.
        // Remove the unused token when denying a direct cookie path.
        if ($token !== '' && class_exists('WP_Session_Tokens')) {
            try { WP_Session_Tokens::get_instance($user_id)->destroy((string)$token); } catch (Throwable $e) {}
        }
        if (get_current_user_id() === $user_id) wp_set_current_user(0);
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_auth_cookie_blocked', 'critical', $user_id, ['scheme'=>sanitize_key((string)$scheme)]);
        return false;
    }

    public static function block_application_password_auth($error, $user, $item, $password) {
        if (!($error instanceof WP_Error) || !($user instanceof WP_User) || !self::is_enabled($user->ID)) return;
        $allow = (bool) apply_filters('dip_two_factor_allow_application_passwords', false, $user, $item);
        if ($allow) return;
        $error->add('dip_2fa_application_password_blocked', __('Les Application Passwords sont bloqués pour ce compte car 2FA est activée.', 'delicat-google-login'));
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_application_password_blocked', 'critical', $user->ID, ['app_uuid_hash'=>!empty($item['uuid']) ? hash('sha256', (string)$item['uuid']) : '']);
    }

    public static function render_login_field() {
        if (is_user_logged_in()) return;
        ?>
        <p class="dip-2fa-login-field">
          <label for="dip_2fa_code"><?php esc_html_e('Code 2FA (si activé)', 'delicat-google-login'); ?></label>
          <input type="text" name="dip_2fa_code" id="dip_2fa_code" inputmode="text" autocomplete="one-time-code" maxlength="32" class="input" value="">
        </p>
        <?php
    }

    private static function security_email($user_id, $subject, $message) {
        $user = get_userdata(absint($user_id));
        if (!$user || !is_email($user->user_email)) return false;
        $subject = sanitize_text_field((string)$subject);
        if (class_exists('DIP_Email_Hub')) {
            return DIP_Email_Hub::send_user_security($user->ID, $subject, (string)$message, ['advice'=>__('Si vous n’êtes pas à l’origine de cette action, changez immédiatement votre mot de passe et contactez le support Delicat Store.', 'delicat-google-login')]);
        }
        $body = '<p>' . esc_html((string)$message) . '</p><p>' . esc_html__('Si vous n’êtes pas à l’origine de cette action, changez immédiatement votre mot de passe et contactez le support Delicat Store.', 'delicat-google-login') . '</p>';
        return (bool) wp_mail($user->user_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    private static function require_self_service() {
        if (class_exists('DIP_Access_Guard')) DIP_Access_Guard::require_self_service();
        elseif (!is_user_logged_in()) auth_redirect();
    }

    private static function security_url($status = '') {
        $url = home_url('/my-account/');
        if (class_exists('DIP_Customer_Dashboard') && function_exists('wc_get_account_endpoint_url')) {
            $url = add_query_arg('dip_tab', 'security', wc_get_account_endpoint_url(DIP_Customer_Dashboard::ENDPOINT));
        }
        if ($status !== '') $url = add_query_arg('dip_2fa_status', sanitize_key($status), $url);
        return $url;
    }

    private static function require_recent() {
        if (class_exists('DIP_Reauth') && !DIP_Reauth::is_recent()) DIP_Reauth::require_recent_or_redirect();
    }

    public static function begin_enrollment() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response'=>405]);
        self::require_self_service();
        check_admin_referer(self::NONCE);
        self::require_recent();
        $user_id = get_current_user_id();
        if (!is_ssl()) { wp_safe_redirect(self::security_url('https_required')); exit; }
        if (!self::enrollment_available()) { wp_safe_redirect(self::security_url('disabled_by_admin')); exit; }
        if (self::is_enabled($user_id)) { wp_safe_redirect(self::security_url('already_enabled')); exit; }
        if (!class_exists('DIP_Crypto') || !DIP_Crypto::is_available()) { wp_safe_redirect(self::security_url('crypto_unavailable')); exit; }
        $secret = self::generate_secret();
        $encrypted = $secret !== '' ? DIP_Crypto::encrypt($secret) : '';
        if ($encrypted === '') { wp_safe_redirect(self::security_url('setup_failed')); exit; }
        update_user_meta($user_id, self::META_PENDING, $encrypted);
        update_user_meta($user_id, self::META_PENDING_EXPIRES, time() + 15 * MINUTE_IN_SECONDS);
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_enrollment_started', 'notice', $user_id);
        wp_safe_redirect(self::security_url('setup'));
        exit;
    }

    private static function generate_recovery_codes() {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            try { $raw = strtoupper(bin2hex(random_bytes(8))); }
            catch (Throwable $e) { $raw = strtoupper(wp_generate_password(16, false, false)); }
            $raw = preg_replace('/[^A-Z0-9]/', '', $raw);
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4) . '-' . substr($raw, 12, 4);
        }
        return $codes;
    }

    private static function store_recovery_codes($user_id, array $codes) {
        $hashes = [];
        foreach ($codes as $code) $hashes[] = wp_hash_password(self::normalize_recovery($code));
        update_user_meta($user_id, self::META_RECOVERY, $hashes);
        $payload = class_exists('DIP_Crypto') ? DIP_Crypto::encrypt(wp_json_encode($codes)) : '';
        if ($payload !== '') set_transient('dip_2fa_codes_' . absint($user_id) . '_' . substr(hash_hmac('sha256', (string) wp_get_session_token(), wp_salt('nonce')),0,20), $payload, 10 * MINUTE_IN_SECONDS);
    }

    private static function one_time_codes($user_id) {
        $key = 'dip_2fa_codes_' . absint($user_id) . '_' . substr(hash_hmac('sha256', (string) wp_get_session_token(), wp_salt('nonce')),0,20);
        $payload = (string) get_transient($key);
        if ($payload === '' || !class_exists('DIP_Crypto')) return [];
        $decoded = json_decode(DIP_Crypto::decrypt($payload), true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private static function clear_one_time_codes($user_id) {
        $key = 'dip_2fa_codes_' . absint($user_id) . '_' . substr(hash_hmac('sha256', (string) wp_get_session_token(), wp_salt('nonce')),0,20);
        delete_transient($key);
    }

    public static function confirm_enrollment() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response'=>405]);
        self::require_self_service();
        check_admin_referer(self::NONCE);
        self::require_recent();
        $user_id = get_current_user_id();
        $secret = self::pending_secret($user_id);
        $code = sanitize_text_field(wp_unslash($_POST['totp_code'] ?? ''));
        if (!self::factor_attempt_allowed($user_id)) { wp_safe_redirect(self::security_url('rate_limited')); exit; }
        if ($secret === '' || !self::verify_totp_secret($secret, $code, 1)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_enrollment_failed', 'warning', $user_id);
            wp_safe_redirect(self::security_url('invalid_setup_code'));
            exit;
        }
        $encrypted = DIP_Crypto::encrypt($secret);
        if ($encrypted === '') { wp_safe_redirect(self::security_url('setup_failed')); exit; }
        update_user_meta($user_id, self::META_SECRET, $encrypted);
        update_user_meta($user_id, self::META_ENABLED, 'yes');
        self::clear_factor_rate($user_id);
        delete_user_meta($user_id, self::META_LAST_COUNTER);
        delete_user_meta($user_id, self::META_PENDING);
        delete_user_meta($user_id, self::META_PENDING_EXPIRES);
        $codes = self::generate_recovery_codes();
        self::store_recovery_codes($user_id, $codes);
        if (class_exists('DIP_Reauth')) DIP_Reauth::mark_recent($user_id);
        if (class_exists('WP_Session_Tokens')) WP_Session_Tokens::get_instance($user_id)->destroy_others(wp_get_session_token());
        $mobile_revoked = class_exists('DIP_Mobile_API') ? DIP_Mobile_API::revoke_all_for_user($user_id, 'two_factor_enabled') : 0;
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_enabled', 'warning', $user_id, ['recovery_codes'=>count($codes),'mobile_revoked'=>(int)$mobile_revoked]);
        self::security_email($user_id, __('2FA activée sur votre compte Delicat', 'delicat-google-login'), __('La vérification en deux étapes vient d’être activée. Vos autres sessions ont été révoquées par précaution.', 'delicat-google-login'));
        wp_safe_redirect(self::security_url('enabled'));
        exit;
    }

    public static function disable() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response'=>405]);
        self::require_self_service();
        check_admin_referer(self::NONCE);
        self::require_recent();
        $user_id = get_current_user_id();
        if (!self::is_enabled($user_id)) { wp_safe_redirect(self::security_url('disabled')); exit; }
        $code = sanitize_text_field(wp_unslash($_POST['factor_code'] ?? ''));
        if (!self::factor_attempt_allowed($user_id)) { wp_safe_redirect(self::security_url('rate_limited')); exit; }
        if (!self::verify_factor($user_id, $code, true)) { wp_safe_redirect(self::security_url('invalid_factor')); exit; }
        self::clear_factor_rate($user_id);
        delete_user_meta($user_id, self::META_ENABLED);
        delete_user_meta($user_id, self::META_SECRET);
        delete_user_meta($user_id, self::META_PENDING);
        delete_user_meta($user_id, self::META_PENDING_EXPIRES);
        delete_user_meta($user_id, self::META_RECOVERY);
        delete_user_meta($user_id, self::META_LAST_COUNTER);
        self::release_lock('totp', $user_id);
        self::release_lock('recovery', $user_id);
        self::release_lock('rate', $user_id);
        self::clear_factor_rate($user_id);
        self::clear_one_time_codes($user_id);
        if (class_exists('WP_Session_Tokens')) WP_Session_Tokens::get_instance($user_id)->destroy_others(wp_get_session_token());
        $mobile = class_exists('DIP_Mobile_API') ? DIP_Mobile_API::revoke_all_for_user($user_id, 'two_factor_disabled') : 0;
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_disabled', 'critical', $user_id, ['mobile_revoked'=>(int)$mobile]);
        do_action('dip_two_factor_disabled', $user_id);
        self::security_email($user_id, __('2FA désactivée sur votre compte Delicat', 'delicat-google-login'), __('La vérification en deux étapes vient d’être désactivée et les autres sessions ont été révoquées.', 'delicat-google-login'));
        wp_safe_redirect(self::security_url('disabled'));
        exit;
    }

    public static function regenerate_recovery() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response'=>405]);
        self::require_self_service();
        check_admin_referer(self::NONCE);
        self::require_recent();
        $user_id = get_current_user_id();
        if (!self::is_enabled($user_id)) { wp_safe_redirect(self::security_url('not_enabled')); exit; }
        $code = sanitize_text_field(wp_unslash($_POST['totp_code'] ?? ''));
        if (!self::factor_attempt_allowed($user_id)) { wp_safe_redirect(self::security_url('rate_limited')); exit; }
        if (!self::verify_totp($user_id, $code)) { wp_safe_redirect(self::security_url('invalid_factor')); exit; }
        self::clear_factor_rate($user_id);
        $codes = self::generate_recovery_codes();
        self::store_recovery_codes($user_id, $codes);
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_recovery_codes_regenerated', 'warning', $user_id, ['count'=>count($codes)]);
        self::security_email($user_id, __('Nouveaux codes de récupération Delicat', 'delicat-google-login'), __('Vos codes de récupération 2FA ont été régénérés. Les anciens ne sont plus valides.', 'delicat-google-login'));
        wp_safe_redirect(self::security_url('recovery_regenerated'));
        exit;
    }

    private static function provisioning_uri($user_id, $secret) {
        $user = get_userdata(absint($user_id));
        $email = $user ? sanitize_email($user->user_email) : ('user-' . absint($user_id));
        $issuer = 'Delicat Store';
        $label = rawurlencode($issuer . ':' . $email);
        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function render_settings($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id || !is_user_logged_in() || $user_id !== get_current_user_id()) return '';
        $enabled = self::is_enabled($user_id);
        $pending = $enabled ? '' : self::pending_secret($user_id);
        $codes = self::one_time_codes($user_id);
        $status = sanitize_key(wp_unslash($_GET['dip_2fa_status'] ?? ''));
        $messages = [
            'enabled'=>__('2FA activée. Enregistrez immédiatement vos codes de récupération.', 'delicat-google-login'),
            'disabled'=>__('2FA désactivée. Les autres sessions ont été révoquées.', 'delicat-google-login'),
            'invalid_setup_code'=>__('Le code de l’application Authenticator est incorrect.', 'delicat-google-login'),
            'invalid_factor'=>__('Le code de sécurité est incorrect.', 'delicat-google-login'),
            'rate_limited'=>__('Trop de tentatives de code. Réessayez dans quelques minutes.', 'delicat-google-login'),
            'https_required'=>__('HTTPS est requis avant de configurer ou utiliser 2FA.', 'delicat-google-login'),
            'crypto_unavailable'=>__('Le chiffrement serveur requis pour stocker le secret 2FA n’est pas disponible.', 'delicat-google-login'),
            'setup_failed'=>__('Impossible de préparer 2FA de façon sécurisée.', 'delicat-google-login'),
            'recovery_regenerated'=>__('Nouveaux codes de récupération générés. Les anciens ne fonctionnent plus.', 'delicat-google-login'),
            'disabled_by_admin'=>__('L’enrôlement 2FA est actuellement désactivé par l’administrateur. Une 2FA déjà active reste toutefois obligatoire.', 'delicat-google-login'),
        ];
        ob_start();
        ?>
        <div class="dip-security-card dip-2fa-card">
          <div class="dip-security-card-title"><span class="dip-security-card-icon" aria-hidden="true">2</span><div><h3><?php esc_html_e('Authentification à deux facteurs', 'delicat-google-login'); ?></h3><p><?php echo $enabled ? esc_html__('Active : un second code protège vos connexions.', 'delicat-google-login') : esc_html__('Ajoutez un code TOTP compatible Google Authenticator, Microsoft Authenticator, 1Password et applications similaires.', 'delicat-google-login'); ?></p></div></div>
          <?php if (isset($messages[$status])): ?><div class="dip-security-notice" role="status"><?php echo esc_html($messages[$status]); ?></div><?php endif; ?>
          <?php if ($codes): ?>
            <div class="dip-recovery-box"><strong><?php esc_html_e('Codes de récupération — à enregistrer maintenant', 'delicat-google-login'); ?></strong><p><?php esc_html_e('Ils sont affichés temporairement dans cette session. Conservez-les hors ligne : chaque code ne peut être utilisé qu’une seule fois.', 'delicat-google-login'); ?></p><div class="dip-recovery-grid"><?php foreach ($codes as $code): ?><code><?php echo esc_html($code); ?></code><?php endforeach; ?></div></div>
          <?php endif; ?>
          <?php if (!$enabled && $pending): ?>
            <div class="dip-2fa-setup"><p><strong><?php esc_html_e('1. Ajoutez ce compte dans votre application Authenticator', 'delicat-google-login'); ?></strong></p><div class="dip-secret-key"><code><?php echo esc_html($pending); ?></code></div><details><summary><?php esc_html_e('Afficher l’URI de configuration', 'delicat-google-login'); ?></summary><code class="dip-otpauth-uri"><?php echo esc_html(self::provisioning_uri($user_id, $pending)); ?></code></details><p><strong><?php esc_html_e('2. Entrez le code à 6 chiffres généré', 'delicat-google-login'); ?></strong></p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_2fa_confirm"><?php wp_nonce_field(self::NONCE); ?><input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required><button type="submit" class="dip-btn primary"><?php esc_html_e('Activer 2FA', 'delicat-google-login'); ?></button></form></div>
          <?php elseif (!$enabled && self::enrollment_available() && is_ssl()): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_2fa_begin"><?php wp_nonce_field(self::NONCE); ?><button type="submit" class="dip-btn primary"><?php esc_html_e('Configurer 2FA', 'delicat-google-login'); ?></button></form>
          <?php elseif (!$enabled): ?>
            <div class="dip-security-notice"><?php echo is_ssl() ? esc_html__('L’administrateur a désactivé les nouveaux enrôlements 2FA.', 'delicat-google-login') : esc_html__('HTTPS est requis avant d’activer 2FA.', 'delicat-google-login'); ?></div>
          <?php else: ?>
            <div class="dip-2fa-actions">
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_2fa_recovery_regen"><?php wp_nonce_field(self::NONCE); ?><label><span><?php esc_html_e('Code Authenticator', 'delicat-google-login'); ?></span><input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></label><button type="submit" class="dip-btn"><?php esc_html_e('Régénérer les codes de récupération', 'delicat-google-login'); ?></button></form>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-dip-confirm="<?php echo esc_attr__('Désactiver 2FA et révoquer les autres sessions ?', 'delicat-google-login'); ?>"><input type="hidden" name="action" value="dip_2fa_disable"><?php wp_nonce_field(self::NONCE); ?><label><span><?php esc_html_e('Code 2FA ou récupération', 'delicat-google-login'); ?></span><input type="text" name="factor_code" autocomplete="one-time-code" maxlength="32" required></label><button type="submit" class="dip-btn danger"><?php esc_html_e('Désactiver 2FA', 'delicat-google-login'); ?></button></form>
            </div>
          <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function browser_binding() {
        $token = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::browser_token(true) : '';
        return $token !== '' ? hash_hmac('sha256', $token, wp_salt('secure_auth')) : '';
    }

    private static function preauth_key($token) {
        return 'dip_2fa_pre_' . substr(hash_hmac('sha256', (string) $token, wp_salt('auth')), 0, 48);
    }

    private static function set_preauth_cookie($token, $expires) {
        if (headers_sent()) return false;
        setcookie(self::PREAUTH_COOKIE, $token, [
            'expires'=>$expires,'path'=>COOKIEPATH ?: '/','domain'=>COOKIE_DOMAIN ?: '',
            'secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'
        ]);
        $_COOKIE[self::PREAUTH_COOKIE] = $token;
        return true;
    }

    private static function clear_preauth_cookie() {
        if (!headers_sent()) setcookie(self::PREAUTH_COOKIE, '', ['expires'=>time()-3600,'path'=>COOKIEPATH ?: '/','domain'=>COOKIE_DOMAIN ?: '','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
        unset($_COOKIE[self::PREAUTH_COOKIE]);
    }

    public static function begin_web_challenge($user_id, $redirect, $remember = true, $method = 'second_factor') {
        $user_id = absint($user_id);
        if (!$user_id || !self::is_enabled($user_id) || !is_ssl()) return '';
        try { $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
        catch (Throwable $e) { return ''; }
        $expires = time() + 5 * MINUTE_IN_SECONDS;
        $binding = self::browser_binding();
        if ($binding === '') return '';
        $redirect = wp_validate_redirect((string) $redirect, home_url('/my-account/'));
        set_transient(self::preauth_key($token), [
            'user_id'=>$user_id,'redirect'=>$redirect,'remember'=>$remember?1:0,
            'method'=>sanitize_key($method),'browser'=>$binding,'expires'=>$expires,'attempts'=>0,
        ], 5 * MINUTE_IN_SECONDS);
        if (!self::set_preauth_cookie($token, $expires)) {
            delete_transient(self::preauth_key($token));
            return '';
        }
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_challenge_started', 'notice', $user_id, ['method'=>sanitize_key($method)]);
        return add_query_arg('dip_2fa_challenge', '1', home_url('/'));
    }

    private static function preauth_state() {
        $token = sanitize_text_field(wp_unslash($_COOKIE[self::PREAUTH_COOKIE] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token)) return [null, null, ''];
        $key = self::preauth_key($token);
        $state = get_transient($key);
        if (!is_array($state) || empty($state['user_id']) || empty($state['expires']) || time() > (int)$state['expires']) return [null, null, ''];
        $binding = self::browser_binding();
        if ($binding === '' || empty($state['browser']) || !hash_equals((string)$state['browser'], $binding)) return [null, null, ''];
        return [$state, $key, $token];
    }

    public static function maybe_render_challenge() {
        if (empty($_GET['dip_2fa_challenge'])) return;
        if (!is_ssl()) { wp_safe_redirect(add_query_arg('dip_auth_error','two_factor_https_required',home_url('/')) . '#delicat-login'); exit; }
        nocache_headers();
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        list($state, $key, $token) = self::preauth_state();
        if (!$state) {
            self::clear_preauth_cookie();
            wp_safe_redirect(add_query_arg('dip_auth_error','two_factor_expired',home_url('/')) . '#delicat-login');
            exit;
        }
        $nonce = wp_create_nonce('dip_2fa_challenge_' . substr(hash('sha256',$token),0,24));
        try { $style_nonce = base64_encode(random_bytes(18)); } catch (Throwable $e) { $style_nonce = base64_encode(wp_generate_password(24, true, true)); }
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'nonce-" . $style_nonce . "'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        $challenge_error = sanitize_key(wp_unslash($_GET['dip_2fa_error'] ?? ''));
        $post_action = wp_make_link_relative(admin_url('admin-post.php'));
        if (!is_string($post_action) || strpos($post_action, '/') !== 0) $post_action = '/wp-admin/admin-post.php';
        ?><!doctype html><html lang="fr"><head><meta charset="<?php echo esc_attr(get_option('blog_charset')); ?>"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title><?php esc_html_e('Vérification en deux étapes', 'delicat-google-login'); ?></title><style nonce="<?php echo esc_attr($style_nonce); ?>">*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#f5f7ff;color:#11162a}body{min-height:100vh;min-height:100dvh;display:grid;place-items:center;padding:max(18px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(18px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left))}.c{width:min(100%,430px);padding:30px 26px;border:1px solid #e2e7f2;border-radius:28px;background:#fff;box-shadow:0 28px 80px rgba(23,29,73,.17)}.m{width:62px;height:62px;margin:0 auto 18px;border-radius:20px;background:linear-gradient(135deg,#1459ff,#6b35ef);display:grid;place-items:center;color:#fff;font-size:26px;font-weight:900}.c h1{text-align:center;margin:0 0 9px;font-size:25px}.c p{text-align:center;color:#71788d;line-height:1.5;margin:0 0 22px}.e{margin:0 0 14px;padding:11px 12px;border:1px solid #f2b7bd;border-radius:12px;background:#fff3f4;color:#a92f40;font-size:13px;font-weight:750}.c label{display:block;font-size:13px;font-weight:800;margin-bottom:8px}.c input{width:100%;height:58px;border:1.5px solid #d9e0ef;border-radius:16px;padding:0 16px;font-size:18px;letter-spacing:.08em;outline:0}.c input:focus{border-color:#5a70ed;box-shadow:0 0 0 4px rgba(74,91,226,.11)}.c button{width:100%;min-height:58px;margin-top:14px;border:0;border-radius:16px;background:linear-gradient(100deg,#1459ff,#6b35ef 58%,#d92391);color:#fff;font-size:16px;font-weight:850}.c small{display:block;margin-top:16px;text-align:center;color:#8a91a3;line-height:1.45}</style></head><body><main class="c"><div class="m">2FA</div><h1><?php esc_html_e('Vérification en deux étapes', 'delicat-google-login'); ?></h1><p><?php esc_html_e('Entrez le code de votre application Authenticator ou un code de récupération.', 'delicat-google-login'); ?></p><?php if ($challenge_error === 'invalid'): ?><div class="e" role="alert"><?php esc_html_e('Code incorrect. Réessayez.', 'delicat-google-login'); ?></div><?php elseif ($challenge_error === 'rate_limited'): ?><div class="e" role="alert"><?php esc_html_e('Trop de tentatives. Réessayez dans quelques minutes.', 'delicat-google-login'); ?></div><?php endif; ?><form method="post" action="<?php echo esc_url($post_action); ?>"><input type="hidden" name="action" value="dip_2fa_complete_challenge"><input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>"><label for="dip-factor-code"><?php esc_html_e('Code de sécurité', 'delicat-google-login'); ?></label><input id="dip-factor-code" name="factor_code" type="text" inputmode="text" autocomplete="one-time-code" maxlength="32" autofocus required><button type="submit"><?php esc_html_e('Vérifier et continuer', 'delicat-google-login'); ?></button></form><small><?php esc_html_e('Cette étape expire dans quelques minutes et est liée à ce navigateur.', 'delicat-google-login'); ?></small></main></body></html><?php
        exit;
    }

    public static function complete_web_challenge() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response'=>405]);
        if (!is_ssl()) { wp_safe_redirect(add_query_arg('dip_auth_error','two_factor_https_required',home_url('/')) . '#delicat-login'); exit; }
        nocache_headers();
        list($state, $key, $token) = self::preauth_state();
        if (!$state) { self::clear_preauth_cookie(); wp_safe_redirect(add_query_arg('dip_auth_error','two_factor_expired',home_url('/')) . '#delicat-login'); exit; }
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'dip_2fa_challenge_' . substr(hash('sha256',$token),0,24))) { wp_safe_redirect(add_query_arg('dip_auth_error','two_factor_expired',home_url('/')) . '#delicat-login'); exit; }
        $user_id = absint($state['user_id']);
        if (!self::factor_attempt_allowed($user_id)) { wp_safe_redirect(add_query_arg(['dip_2fa_challenge'=>'1','dip_2fa_error'=>'rate_limited'],home_url('/'))); exit; }
        $code = sanitize_text_field(wp_unslash($_POST['factor_code'] ?? ''));
        if (!self::verify_factor($user_id, $code, true)) {
            $state['attempts'] = absint($state['attempts'] ?? 0) + 1;
            if ($state['attempts'] >= 6) { delete_transient($key); self::clear_preauth_cookie(); }
            else set_transient($key, $state, max(1, (int)$state['expires']-time()));
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_challenge_failed', 'warning', $user_id);
            wp_safe_redirect(add_query_arg(['dip_2fa_challenge'=>'1','dip_2fa_error'=>'invalid'],home_url('/')));
            exit;
        }
        $user = get_userdata($user_id);
        if (!$user) { delete_transient($key); self::clear_preauth_cookie(); wp_safe_redirect(home_url('/')); exit; }
        delete_transient($key);
        self::clear_preauth_cookie();
        self::clear_factor_rate($user_id);
        wp_clear_auth_cookie();
        wp_set_current_user($user_id);
        self::authorize_cookie_once($user_id);
        wp_set_auth_cookie($user_id, !empty($state['remember']), is_ssl());
        $method = sanitize_key((string)($state['method'] ?? 'two_factor'));
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::fire_wp_login($user, $method);
        else do_action('wp_login', $user->user_login, $user);
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_login($user_id);
        do_action('dip_login_success', $user_id, ['provider'=>$method,'two_factor'=>1]);
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_challenge_success', 'info', $user_id, ['method'=>$method]);
        $redirect = wp_validate_redirect((string)($state['redirect'] ?? ''), home_url('/my-account/'));
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Server-console recovery only. This deliberately has no web endpoint.
     * Example: wp delicat identity-2fa-reset 123
     */
    public static function cli_reset($args, $assoc_args = []) {
        if (!(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))) return;
        $identifier = isset($args[0]) ? trim((string)$args[0]) : '';
        $user = ctype_digit($identifier) ? get_userdata(absint($identifier)) : get_user_by('login', $identifier);
        if (!$user && is_email($identifier)) $user = get_user_by('email', sanitize_email($identifier));
        if (!($user instanceof WP_User)) { \WP_CLI::error('User not found.'); return; }
        $user_id = (int)$user->ID;
        delete_user_meta($user_id, self::META_ENABLED);
        delete_user_meta($user_id, self::META_SECRET);
        delete_user_meta($user_id, self::META_PENDING);
        delete_user_meta($user_id, self::META_PENDING_EXPIRES);
        delete_user_meta($user_id, self::META_RECOVERY);
        delete_user_meta($user_id, self::META_LAST_COUNTER);
        self::release_lock('totp', $user_id);
        self::release_lock('recovery', $user_id);
        self::release_lock('rate', $user_id);
        self::clear_factor_rate($user_id);
        if (class_exists('WP_Session_Tokens')) WP_Session_Tokens::get_instance($user_id)->destroy_all();
        if (class_exists('DIP_Mobile_API')) DIP_Mobile_API::revoke_all_for_user($user_id, 'two_factor_cli_reset');
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_cli_reset', 'critical', $user_id);
        \WP_CLI::success('Delicat 2FA reset for user ' . $user_id . '. All WordPress/mobile sessions were revoked.');
    }
}
