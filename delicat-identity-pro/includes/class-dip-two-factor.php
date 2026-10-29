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

    const FAILURE_DAY_LIMIT = 50;
    const FAILURE_ALERT_AT = 10;

    private static $password_gate_verified_user = 0;
    private static $cookie_authorized_user = 0;
    private static $last_failure = '';
    private static $cookie_valid_user = 0;
    /** This request's password sign-in was answered "code required/invalid" (the password was right). */
    private static $login_needs_code = false;

    public static function init() {
        add_filter('authenticate', [__CLASS__, 'enforce_password_second_factor'], PHP_INT_MAX - 20, 3);
        // A final boundary prevents a later third-party authenticator from replacing
        // a 2FA WP_Error with a WP_User object.
        add_filter('authenticate', [__CLASS__, 'enforce_final_password_gate'], PHP_INT_MAX - 1, 3);
        // Block direct wp_set_auth_cookie() paths for 2FA-enabled users unless
        // this request has already completed the Delicat second factor.
        add_filter('send_auth_cookies', [__CLASS__, 'filter_auth_cookies'], 30, 6);
        add_action('auth_cookie_valid', [__CLASS__, 'remember_cookie_user'], 10, 2);
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
        // Support: an administrator can reset the 2FA of a customer account
        // (lost phone and recovery codes). Staff accounts stay WP-CLI only.
        add_action('edit_user_profile', [__CLASS__, 'render_admin_reset']);
        add_action('admin_footer-user-edit.php', [__CLASS__, 'render_admin_reset_form']);
        add_action('admin_post_dip_2fa_admin_reset', [__CLASS__, 'admin_reset']);
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
        if (preg_match('/^[A-Z2-7]{16,64}$/D', $plain)) return $plain;
        // Still fail closed, but say why: rotated salts or a missing crypto
        // engine make every Authenticator code "incorrect" with no hint.
        self::$last_failure = 'unreadable';
        $flag = 'dip_2fa_unreadable_' . absint($user_id);
        if (!get_transient($flag)) {
            set_transient($flag, 1, DAY_IN_SECONDS);
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_secret_unreadable', 'critical', absint($user_id));
        }
        return '';
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

    /** Why the last verify_totp()/verify_factor() call of this request failed: 'replay', 'unreadable' or ''. */
    public static function last_failure() {
        return self::$last_failure;
    }

    public static function verify_totp($user_id, $code) {
        $user_id = absint($user_id);
        if (!$user_id) return false;
        $secret = self::secret($user_id);
        if ($secret === '') return false;
        $counter = self::matching_counter($secret, $code, 1);
        if ($counter === false) return false;

        // Serialize replay-state updates with a real mutex, so two requests
        // presenting the same TOTP can never both pass the counter check.
        if (!self::acquire_lock('totp', $user_id, 6)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_concurrent_attempt_blocked', 'warning', $user_id, ['factor'=>'totp']);
            return false;
        }
        try {
            // The meta cache of this request may predate a concurrent
            // request's write: read the replay counter from the database.
            wp_cache_delete($user_id, 'user_meta');
            $last = (int) get_user_meta($user_id, self::META_LAST_COUNTER, true);
            if ($last > 0 && $counter <= $last) {
                self::$last_failure = 'replay';
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
        // A real mutex (INSERT IGNORE). A concurrent request waits briefly for
        // the holder instead of being refused outright, then reads fresh state.
        if (class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'mutex_acquire')) return DIP_Lockout::mutex_acquire($key, $ttl, 1500);
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
        $key = self::lock_key($scope, $user_id);
        if (class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'mutex_release')) DIP_Lockout::mutex_release($key);
        else delete_option($key);
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
            // Fresh read under the lock: a stale cached copy would accept a
            // code consumed a moment ago, or write it back to life.
            if ($consume) wp_cache_delete($user_id, 'user_meta');
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
        self::$last_failure = '';
        if (!$user_id || !self::is_enabled($user_id)) return false;
        if (self::verify_totp($user_id, $code) || self::verify_recovery($user_id, $code, $consume_recovery)) {
            self::clear_failure_day($user_id);
            return true;
        }
        if (trim((string) $code) !== '') self::record_failure($user_id);
        return false;
    }

    /**
     * Long window, failures only (owner rule: a customer who succeeds is never
     * limited). The 10-minute buckets alone allow ~2,880 guesses a day once
     * the password is known. The bucket is tied to the current password
     * hash, so changing or resetting the password both locks the guesser out
     * and gives the real customer a fresh allowance.
     */
    private static function failure_day_key($user_id) {
        $user = get_userdata(absint($user_id));
        $pass = $user instanceof WP_User ? (string) $user->user_pass : '';
        return 'dip_2fa_fail_day_' . absint($user_id) . '_' . substr(hash_hmac('sha256', $pass, wp_salt('secure_auth')), 0, 16);
    }

    public static function failures_exhausted($user_id) {
        if (!class_exists('DIP_Lockout') || !method_exists('DIP_Lockout', 'counter_peek')) return false;
        return DIP_Lockout::counter_peek(self::failure_day_key($user_id))['count'] >= self::FAILURE_DAY_LIMIT;
    }

    private static function record_failure($user_id) {
        if (!class_exists('DIP_Lockout') || !method_exists('DIP_Lockout', 'counter_hit')) return;
        $count = DIP_Lockout::counter_hit(self::failure_day_key($user_id), DAY_IN_SECONDS);
        if ($count === self::FAILURE_ALERT_AT || $count === self::FAILURE_DAY_LIMIT) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_repeated_failures', 'critical', $user_id, ['failures'=>$count]);
            self::security_email(
                $user_id,
                __('Codes 2FA incorrects sur votre compte Delicat', 'delicat-google-login'),
                sprintf(__('%d codes de vérification 2FA incorrects ont été saisis pour votre compte depuis le dernier code accepté. Si ce n’était pas vous, quelqu’un connaît probablement votre mot de passe (ou a accès à une de vos sessions) : changez votre mot de passe et déconnectez les autres sessions dès maintenant.', 'delicat-google-login'), $count)
            );
        }
    }

    private static function clear_failure_day($user_id) {
        if (class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'counter_clear')) DIP_Lockout::counter_clear(self::failure_day_key($user_id));
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
        if (self::failures_exhausted($user_id)) return false;
        // Serialize bucket updates so parallel guesses cannot all read the same
        // pre-increment counter. A concurrent request waits for the lock and
        // is denied only if it stays held, never let through a race window.
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
        // Never spend a TOTP step or a one-time recovery code on a login the
        // later gates refuse anyway (active lockout, pending/unverified
        // account). The final 2FA gate and enforce_lockout() still reject it.
        if (class_exists('DIP_Native_Auth') && method_exists('DIP_Native_Auth', 'is_locked') && (string) $username !== '' && DIP_Native_Auth::is_locked(sanitize_text_field((string) $username))) return $user;
        if (class_exists('DIP_Account_Sync') && method_exists('DIP_Account_Sync', 'login_guard')) {
            $guard = DIP_Account_Sync::login_guard($user->ID);
            if (is_wp_error($guard)) return $guard;
        }
        $code = sanitize_text_field(wp_unslash($_POST['dip_2fa_code'] ?? ''));
        self::$login_needs_code = true;
        if ($code === '') return new WP_Error('dip_2fa_required', __('Code de vérification à deux facteurs requis.', 'delicat-google-login'));
        if (!self::factor_attempt_allowed($user->ID)) return new WP_Error('dip_2fa_rate_limited', self::rate_limited_message($user->ID));
        if (!self::verify_factor($user->ID, $code, true)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_login_failed', 'warning', $user->ID);
            return new WP_Error('dip_2fa_invalid', self::invalid_message());
        }
        self::$password_gate_verified_user = (int) $user->ID;
        self::$login_needs_code = false;
        self::clear_factor_rate($user->ID);
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_login_success', 'info', $user->ID);
        return $user;
    }

    public static function rate_limited_message($user_id) {
        if (self::failures_exhausted($user_id)) return __('Trop de codes 2FA incorrects aujourd’hui. Connectez-vous avec une Passkey, ou réinitialisez votre mot de passe pour débloquer la vérification.', 'delicat-google-login');
        return __('Trop de tentatives 2FA. Réessayez dans quelques minutes.', 'delicat-google-login');
    }

    /** Message for the last refused code (replayed step, unreadable secret or plain mistake). */
    public static function invalid_message() {
        if (self::$last_failure === 'replay') return __('Ce code vient déjà d’être utilisé. Attendez le code suivant de votre application (30 secondes).', 'delicat-google-login');
        if (self::$last_failure === 'unreadable') return __('Votre configuration 2FA ne peut pas être lue sur ce serveur. Utilisez un code de récupération, puis reconfigurez la 2FA.', 'delicat-google-login');
        return __('Code 2FA ou code de récupération invalide.', 'delicat-google-login');
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
        $allowed = self::password_gate_verified($user_id) || self::$cookie_authorized_user === $user_id || self::is_session_reissue($user_id, (string) $token);
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

    /**
     * WordPress re-issues the cookie of the signed-in user when they change
     * their own password (wp_update_user(): Woo "Détails du compte",
     * profile.php). The browser then already carries a live session of this
     * exact user, and every live session of a 2FA account passed the second
     * factor (other cookies are refused and their tokens destroyed here).
     * A direct new login has no such session and stays blocked.
     */
    public static function remember_cookie_user($cookie_elements, $user) {
        if ($user instanceof WP_User) self::$cookie_valid_user = (int) $user->ID;
    }

    private static function is_session_reissue($user_id, $new_token) {
        // This request arrived with a valid auth cookie of this exact user
        // (WordPress either keeps that session's token or creates a new one).
        if (!$user_id || self::$cookie_valid_user !== $user_id || get_current_user_id() !== $user_id || !class_exists('WP_Session_Tokens')) return false;
        $old = function_exists('wp_get_session_token') ? (string) wp_get_session_token() : '';
        if ($old === '') return false;
        try { return WP_Session_Tokens::get_instance($user_id)->verify($old); }
        catch (Throwable $e) { return false; }
    }

    public static function block_application_password_auth($error, $user, $item, $password) {
        if (!($error instanceof WP_Error) || !($user instanceof WP_User) || !self::is_enabled($user->ID)) return;
        $allow = (bool) apply_filters('dip_two_factor_allow_application_passwords', false, $user, $item);
        if ($allow) return;
        $error->add('dip_2fa_application_password_blocked', __('Les Application Passwords sont bloqués pour ce compte car 2FA est activée.', 'delicat-google-login'));
        // A valid but blocked credential is not a guess: one audit row per
        // application password and hour, so a polling integration cannot
        // flood the security history.
        $uuid_hash = !empty($item['uuid']) ? hash('sha256', (string)$item['uuid']) : '';
        $flag = 'dip_2fa_apppw_' . substr(hash('sha256', $user->ID . '|' . $uuid_hash), 0, 32);
        if (!get_transient($flag)) {
            set_transient($flag, 1, HOUR_IN_SECONDS);
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_application_password_blocked', 'warning', $user->ID, ['app_uuid_hash'=>$uuid_hash]);
        }
    }

    /**
     * Folded by default: most customers have no 2FA. It opens by itself once
     * the password was accepted and the account asks for its code, and a
     * 2FA user can open it beforehand to sign in in one step.
     */
    public static function render_login_field() {
        if (is_user_logged_in()) return;
        $open = self::$login_needs_code;
        ?>
        <details class="dip-2fa-login-field"<?php echo $open ? ' open' : ''; ?> style="margin:0 0 16px">
          <summary style="cursor:pointer;font-weight:600;margin:0 0 8px"><?php esc_html_e('Vérification en deux étapes activée ? Saisir le code', 'delicat-google-login'); ?></summary>
          <label for="dip_2fa_code"><?php esc_html_e('Code de l’application Authenticator ou code de récupération', 'delicat-google-login'); ?></label>
          <input type="text" name="dip_2fa_code" id="dip_2fa_code" inputmode="text" autocomplete="one-time-code" maxlength="32" class="input" value=""<?php echo $open ? ' autofocus' : ''; ?>>
        </details>
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
        // Back on the 2FA card itself, so the result is in view.
        if ($status !== '') $url = add_query_arg('dip_2fa_status', sanitize_key($status), $url) . '#dip-2fa';
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
        $counter = $secret !== '' ? self::matching_counter($secret, $code, 1) : false;
        if ($counter === false) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_enrollment_failed', 'warning', $user_id);
            wp_safe_redirect(self::security_url('invalid_setup_code'));
            exit;
        }
        $encrypted = DIP_Crypto::encrypt($secret);
        if ($encrypted === '') { wp_safe_redirect(self::security_url('setup_failed')); exit; }
        update_user_meta($user_id, self::META_SECRET, $encrypted);
        update_user_meta($user_id, self::META_ENABLED, 'yes');
        self::clear_factor_rate($user_id);
        // The code typed to confirm the setup is spent: it cannot open a login
        // (or a reauthentication) within its remaining validity window.
        update_user_meta($user_id, self::META_LAST_COUNTER, (int) $counter);
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
        if (!self::verify_factor($user_id, $code, true)) { wp_safe_redirect(self::security_url(self::$last_failure === 'replay' ? 'code_replayed' : 'invalid_factor')); exit; }
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
        self::$last_failure = '';
        if (!self::verify_totp($user_id, $code)) {
            self::record_failure($user_id);
            wp_safe_redirect(self::security_url(self::$last_failure === 'replay' ? 'code_replayed' : 'invalid_factor'));
            exit;
        }
        self::clear_factor_rate($user_id);
        self::clear_failure_day($user_id);
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

    // ------------------------------------------------------------------
    // QR code of the otpauth URI, drawn on the server as inline SVG (byte
    // mode, error correction M, versions 1-20): no third-party service and
    // no script ever sees the secret.
    // ------------------------------------------------------------------

    /** Per version (index 1-20, level M): [EC codewords per block, blocks group 1, data cw group 1, blocks group 2, data cw group 2]. */
    private static function qr_blocks($version) {
        $table = [1=>[10,1,16,0,0],[16,1,28,0,0],[26,1,44,0,0],[18,2,32,0,0],[24,2,43,0,0],[16,4,27,0,0],[18,4,31,0,0],[22,2,38,2,39],[22,3,36,2,37],[26,4,43,1,44],
            [30,1,50,4,51],[22,6,36,2,37],[22,8,37,1,38],[24,4,40,5,41],[24,5,41,5,42],[28,7,45,3,46],[28,10,46,1,47],[26,9,43,4,44],[26,3,44,11,45],[26,3,41,13,42]];
        return $table[$version] ?? null;
    }

    private static function qr_alignment($version) {
        $table = [2=>[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50],[6,30,54],[6,32,58],[6,34,62],[6,26,46,66],[6,26,48,70],[6,26,50,74],[6,30,54,78],[6,30,56,82],[6,30,58,86],[6,34,62,90]];
        return $table[$version] ?? [];
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

    /** Module matrix ($m[row][col] true = dark) or null when the text is too long. */
    private static function qr_matrix($text) {
        $bytes = array_values(unpack('C*', (string) $text) ?: []);
        $len = count($bytes);
        $version = 0;
        for ($v = 1; $v <= 20; $v++) {
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
        foreach (array_merge(array_fill(0, $g1, $d1), array_fill(0, $g2, $d2)) as $size) {
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
        $align = self::qr_alignment($version);
        $last = count($align) - 1;
        foreach ($align as $i => $r) foreach ($align as $j => $c) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) continue;
            for ($dr = -2; $dr <= 2; $dr++) for ($dc = -2; $dc <= 2; $dc++) $set($r + $dr, $c + $dc, max(abs($dr), abs($dc)) !== 1);
        }
        $format = function ($mask) use ($set, $n) {
            $data = (0 << 3) | $mask; // level M = 00
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
        $score = 0; $dark = 0;
        $lines = [];
        for ($r = 0; $r < $n; $r++) {
            $row = ''; $col = '';
            for ($c = 0; $c < $n; $c++) { $row .= $m[$r][$c] ? '1' : '0'; $col .= $m[$c][$r] ? '1' : '0'; }
            $lines[] = $row; $lines[] = $col;
            $dark += substr_count($row, '1');
        }
        foreach ($lines as $line) {
            if (preg_match_all('/0{5,}|1{5,}/', $line, $runs)) foreach ($runs[0] as $run) $score += 3 + strlen($run) - 5;
            $score += 40 * (preg_match_all('/(?=10111010000|00001011101)/', $line));
        }
        for ($r = 0; $r < $n - 1; $r++) for ($c = 0; $c < $n - 1; $c++) {
            $v = $m[$r][$c];
            if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) $score += 3;
        }
        return $score + 10 * intdiv(abs($dark * 20 - $n * $n * 10), $n * $n);
    }

    private static function qr_svg($text) {
        $m = self::qr_matrix($text);
        if (!$m) return '';
        $n = count($m);
        $path = '';
        for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) {
            if (!$m[$r][$c]) continue;
            for ($run = 1; $c + $run < $n && $m[$r][$c + $run]; $run++);
            $path .= 'M' . ($c + 4) . ' ' . ($r + 4) . 'h' . $run . 'v1h-' . $run . 'z';
            $c += $run - 1;
        }
        $size = $n + 8;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" width="184" height="184" shape-rendering="crispEdges" role="img" aria-label="' . esc_attr__('QR code de configuration 2FA', 'delicat-google-login') . '"><rect width="' . $size . '" height="' . $size . '" fill="#fff"/><path fill="#11162a" d="' . $path . '"/></svg>';
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
            'rate_limited'=>$status === 'rate_limited' && self::failures_exhausted($user_id) ? self::rate_limited_message($user_id) : __('Trop de tentatives de code. Réessayez dans quelques minutes.', 'delicat-google-login'),
            'https_required'=>__('HTTPS est requis avant de configurer ou utiliser 2FA.', 'delicat-google-login'),
            'crypto_unavailable'=>__('Le chiffrement serveur requis pour stocker le secret 2FA n’est pas disponible.', 'delicat-google-login'),
            'setup_failed'=>__('Impossible de préparer 2FA de façon sécurisée.', 'delicat-google-login'),
            'recovery_regenerated'=>__('Nouveaux codes de récupération générés. Les anciens ne fonctionnent plus.', 'delicat-google-login'),
            'disabled_by_admin'=>__('L’enrôlement 2FA est actuellement désactivé par l’administrateur. Une 2FA déjà active reste toutefois obligatoire.', 'delicat-google-login'),
            'code_replayed'=>__('Ce code vient déjà d’être utilisé. Attendez le code suivant de votre application (30 secondes).', 'delicat-google-login'),
        ];
        $is_error = !in_array($status, ['enabled', 'disabled', 'recovery_regenerated', 'setup'], true);
        ob_start();
        if (class_exists('DIP_Reauth') && method_exists('DIP_Reauth', 'card_styles')) echo DIP_Reauth::card_styles(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS
        ?>
        <div class="dip-security-card dip-2fa-card" id="dip-2fa">
          <div class="dip-security-card-title"><span class="dip-security-card-icon" aria-hidden="true">2</span><div><h3><?php esc_html_e('Authentification à deux facteurs', 'delicat-google-login'); ?></h3><p><?php echo $enabled ? esc_html__('Active : un second code protège vos connexions.', 'delicat-google-login') : esc_html__('Ajoutez un code TOTP compatible Google Authenticator, Microsoft Authenticator, 1Password et applications similaires.', 'delicat-google-login'); ?></p></div></div>
          <?php if (isset($messages[$status])): ?><div class="dip-security-notice<?php echo $is_error ? ' is-error' : ''; ?>" role="<?php echo $is_error ? 'alert' : 'status'; ?>"><?php echo esc_html($messages[$status]); ?></div><?php endif; ?>
          <?php if ($codes): ?>
            <div class="dip-recovery-box"><strong><?php esc_html_e('Codes de récupération — à enregistrer maintenant', 'delicat-google-login'); ?></strong><p><?php esc_html_e('Ils sont affichés temporairement dans cette session. Conservez-les hors ligne : chaque code ne peut être utilisé qu’une seule fois.', 'delicat-google-login'); ?></p><div class="dip-recovery-grid"><?php foreach ($codes as $code): ?><code><?php echo esc_html($code); ?></code><?php endforeach; ?></div></div>
          <?php endif; ?>
          <?php if (!$enabled && $pending): ?>
            <?php $uri = self::provisioning_uri($user_id, $pending); $qr = self::qr_svg($uri); ?>
            <div class="dip-2fa-setup">
              <p><strong><?php echo $qr ? esc_html__('1. Scannez ce QR code avec votre application Authenticator', 'delicat-google-login') : esc_html__('1. Ajoutez ce compte dans votre application Authenticator', 'delicat-google-login'); ?></strong></p>
              <?php if ($qr): ?><div class="dip-2fa-qr" style="display:flex;justify-content:center;margin:6px 0 10px"><div style="padding:8px;border-radius:14px;background:#fff;border:1px solid #e3e8f2;line-height:0"><?php echo $qr; // phpcs:ignore WordPress.Security.EscapeOutput -- generated SVG, numeric path only ?></div></div><?php endif; ?>
              <p class="dip-2fa-open"><a class="dip-btn" href="<?php echo esc_url($uri, ['otpauth']); ?>"><?php esc_html_e('Sur ce téléphone : ouvrir l’application Authenticator', 'delicat-google-login'); ?></a></p>
              <p><?php esc_html_e('Ou saisissez cette clé dans l’application :', 'delicat-google-login'); ?></p>
              <div class="dip-secret-key"><code style="user-select:all;-webkit-user-select:all;letter-spacing:.06em"><?php echo esc_html(trim(chunk_split($pending, 4, ' '))); ?></code></div>
              <details><summary><?php esc_html_e('Afficher l’URI de configuration', 'delicat-google-login'); ?></summary><code class="dip-otpauth-uri"><?php echo esc_html($uri); ?></code></details>
              <p><strong><?php esc_html_e('2. Entrez le code à 6 chiffres généré', 'delicat-google-login'); ?></strong></p>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_2fa_confirm"><?php wp_nonce_field(self::NONCE); ?><input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123 456" aria-label="<?php echo esc_attr__('Code à 6 chiffres', 'delicat-google-login'); ?>" required><button type="submit" class="dip-btn primary"><?php esc_html_e('Activer 2FA', 'delicat-google-login'); ?></button></form>
            </div>
          <?php elseif (!$enabled && self::enrollment_available() && is_ssl()): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_2fa_begin"><?php wp_nonce_field(self::NONCE); ?><button type="submit" class="dip-btn primary"><?php esc_html_e('Configurer 2FA', 'delicat-google-login'); ?></button></form>
          <?php elseif (!$enabled): ?>
            <div class="dip-security-notice"><?php echo is_ssl() ? esc_html__('L’administrateur a désactivé les nouveaux enrôlements 2FA.', 'delicat-google-login') : esc_html__('HTTPS est requis avant d’activer 2FA.', 'delicat-google-login'); ?></div>
          <?php else: ?>
            <div class="dip-2fa-actions">
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_2fa_recovery_regen"><?php wp_nonce_field(self::NONCE); ?><label><span><?php esc_html_e('Code Authenticator', 'delicat-google-login'); ?></span><input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123 456" required></label><button type="submit" class="dip-btn"><?php esc_html_e('Régénérer les codes de récupération', 'delicat-google-login'); ?></button></form>
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

    /** Challenge responses carry a nonce bound to one browser: never cacheable, by any layer. */
    private static function private_nocache($reason) {
        nocache_headers();
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
            header('X-LiteSpeed-Cache-Control: no-cache');
        }
        if (has_action('litespeed_control_set_nocache')) do_action('litespeed_control_set_nocache', $reason);
    }

    /**
     * CSP form-action also applies to every redirect that follows the form
     * submission (Chromium): allow the origins the completed challenge may
     * legitimately continue to, e.g. the headless storefront callback.
     */
    private static function form_action_sources(array $state) {
        $sources = ["'self'"];
        $candidates = [(string) ($state['redirect'] ?? '')];
        if (class_exists('DIP_Headless_Google') && defined('DIP_Headless_Google::DEFAULT_ORIGIN')) {
            foreach ((array) apply_filters('dip_headless_allowed_origins', [DIP_Headless_Google::DEFAULT_ORIGIN]) as $origin) $candidates[] = (string) $origin;
        }
        foreach ($candidates as $url) {
            $parts = $url !== '' ? wp_parse_url($url) : false;
            if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) continue;
            $source = 'https://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . absint($parts['port']) : '');
            if (preg_match('#^https://[a-z0-9.-]+(:\d{1,5})?$#D', $source)) $sources[] = $source;
        }
        return implode(' ', array_unique($sources));
    }

    private static function challenge_url(array $args = []) {
        return add_query_arg(array_merge(['dip_2fa_challenge'=>'1'], $args), home_url('/'));
    }

    public static function maybe_render_challenge() {
        if (empty($_GET['dip_2fa_challenge'])) return;
        if (!is_ssl()) { wp_safe_redirect(add_query_arg('dip_auth_error','two_factor_https_required',home_url('/')) . '#delicat-login'); exit; }
        self::private_nocache('Delicat 2FA challenge');
        list($state, $key, $token) = self::preauth_state();
        if (!$state) {
            self::clear_preauth_cookie();
            wp_safe_redirect(add_query_arg('dip_auth_error','two_factor_expired',home_url('/')) . '#delicat-login');
            exit;
        }
        $nonce_action = 'dip_2fa_challenge_' . substr(hash('sha256',$token),0,24);
        // "Annuler": forget this pending sign-in in this browser.
        if (!empty($_GET['dip_2fa_cancel'])) {
            if (wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'] ?? '')), $nonce_action)) { delete_transient($key); self::clear_preauth_cookie(); }
            wp_safe_redirect(home_url('/') . '#delicat-login');
            exit;
        }
        $nonce = wp_create_nonce($nonce_action);
        try { $csp_nonce = base64_encode(random_bytes(18)); } catch (Throwable $e) { $csp_nonce = base64_encode(wp_generate_password(24, true, true)); }
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'nonce-" . $csp_nonce . "'; script-src 'nonce-" . $csp_nonce . "'; form-action " . self::form_action_sources($state) . "; base-uri 'none'; frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        $challenge_error = sanitize_key(wp_unslash($_GET['dip_2fa_error'] ?? ''));
        $recovery = sanitize_key(wp_unslash($_GET['dip_2fa_mode'] ?? '')) === 'recovery';
        $minutes = max(1, (int) ceil(max(0, (int) $state['expires'] - time()) / 60));
        $errors = [
            'invalid' => $recovery ? __('Code de récupération incorrect. Vérifiez-le et réessayez.', 'delicat-google-login') : __('Code incorrect. Vérifiez l’heure de votre téléphone et réessayez.', 'delicat-google-login'),
            'replay' => __('Ce code vient déjà d’être utilisé. Attendez le code suivant de votre application (30 secondes).', 'delicat-google-login'),
            'unreadable' => __('Votre configuration 2FA ne peut pas être lue sur ce serveur. Utilisez un code de récupération, puis reconfigurez la 2FA.', 'delicat-google-login'),
            'rate_limited' => self::rate_limited_message(absint($state['user_id'])),
        ];
        $post_action = wp_make_link_relative(admin_url('admin-post.php'));
        if (!is_string($post_action) || strpos($post_action, '/') !== 0) $post_action = '/wp-admin/admin-post.php';
        $toggle_url = $recovery ? self::challenge_url() : self::challenge_url(['dip_2fa_mode'=>'recovery']);
        $cancel_url = self::challenge_url(['dip_2fa_cancel'=>'1','nonce'=>$nonce]);
        ?><!doctype html><html lang="fr"><head><meta charset="<?php echo esc_attr(get_option('blog_charset')); ?>"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><meta name="color-scheme" content="light dark"><title><?php esc_html_e('Vérification en deux étapes', 'delicat-google-login'); ?></title><style nonce="<?php echo esc_attr($csp_nonce); ?>">*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#f5f7ff;color:#11162a}body{min-height:100vh;min-height:100dvh;display:grid;place-items:center;padding:max(18px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(18px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left))}.c{width:min(100%,430px);padding:30px 26px;border:1px solid #e2e7f2;border-radius:28px;background:#fff;box-shadow:0 28px 80px rgba(23,29,73,.17)}.m{width:62px;height:62px;margin:0 auto 18px;border-radius:20px;background:linear-gradient(135deg,#1459ff,#6b35ef);display:grid;place-items:center;color:#fff;font-size:22px;font-weight:900}.c h1{text-align:center;margin:0 0 9px;font-size:25px}.c p{text-align:center;color:#71788d;line-height:1.5;margin:0 0 22px}.e{margin:0 0 14px;padding:11px 12px;border:1px solid #f2b7bd;border-radius:12px;background:#fff3f4;color:#a92f40;font-size:13px;font-weight:750;line-height:1.45}.c label{display:block;font-size:13px;font-weight:800;margin-bottom:8px}.c input{width:100%;height:58px;border:1.5px solid #d9e0ef;border-radius:16px;padding:0 16px;font-size:22px;letter-spacing:.18em;text-align:center;outline:0;background:#fff;color:inherit}.c input.r{font-size:17px;letter-spacing:.06em;text-transform:uppercase}.c input:focus{border-color:#5a70ed;box-shadow:0 0 0 4px rgba(74,91,226,.11)}.c button{width:100%;min-height:58px;margin-top:14px;border:0;border-radius:16px;background:linear-gradient(100deg,#1459ff,#6b35ef 58%,#d92391);color:#fff;font-size:16px;font-weight:850;cursor:pointer}.c button[disabled]{opacity:.7;cursor:progress}.l{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;margin-top:16px;font-size:13px}.l a{color:#3b4fd8;font-weight:750;text-decoration:none}.l a:hover,.l a:focus{text-decoration:underline}.c small{display:block;margin-top:16px;text-align:center;color:#8a91a3;line-height:1.45}@media (prefers-color-scheme:dark){html,body{background:#0d1020;color:#eef1fb}.c{background:#161a2e;border-color:#262c47;box-shadow:none}.c p,.c small{color:#a3aac2}.c input{background:#0f1326;border-color:#30385a}.l a{color:#9fb0ff}.e{background:#3a1820;border-color:#6b2a37;color:#ffc2cc}}</style></head><body><main class="c"><div class="m" aria-hidden="true">2FA</div><h1><?php esc_html_e('Vérification en deux étapes', 'delicat-google-login'); ?></h1><p><?php echo $recovery ? esc_html__('Entrez l’un de vos codes de récupération. Chaque code ne fonctionne qu’une seule fois.', 'delicat-google-login') : esc_html__('Entrez le code à 6 chiffres affiché par votre application Authenticator.', 'delicat-google-login'); ?></p><?php if (isset($errors[$challenge_error])): ?><div class="e" role="alert"><?php echo esc_html($errors[$challenge_error]); ?></div><?php endif; ?><form method="post" action="<?php echo esc_url($post_action); ?>" id="dip-2fa-form"><input type="hidden" name="action" value="dip_2fa_complete_challenge"><input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>"><input type="hidden" name="mode" value="<?php echo $recovery ? 'recovery' : 'totp'; ?>"><label for="dip-factor-code"><?php echo $recovery ? esc_html__('Code de récupération', 'delicat-google-login') : esc_html__('Code de sécurité', 'delicat-google-login'); ?></label><?php if ($recovery): ?><input id="dip-factor-code" class="r" name="factor_code" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="32" placeholder="XXXX-XXXX-XXXX-XXXX" autofocus required><?php else: ?><input id="dip-factor-code" name="factor_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="9" placeholder="123 456" autofocus required><?php endif; ?><button type="submit"><?php esc_html_e('Vérifier et continuer', 'delicat-google-login'); ?></button></form><div class="l"><a href="<?php echo esc_url($toggle_url); ?>"><?php echo $recovery ? esc_html__('Utiliser l’application Authenticator', 'delicat-google-login') : esc_html__('Utiliser un code de récupération', 'delicat-google-login'); ?></a><a href="<?php echo esc_url($cancel_url); ?>"><?php esc_html_e('Annuler', 'delicat-google-login'); ?></a></div><small><?php echo esc_html(sprintf(_n('Cette étape expire dans %d minute et reste liée à ce navigateur.', 'Cette étape expire dans %d minutes et reste liée à ce navigateur.', $minutes, 'delicat-google-login'), $minutes)); ?></small></main><script nonce="<?php echo esc_attr($csp_nonce); ?>">(function(){var f=document.getElementById('dip-2fa-form');if(!f)return;f.addEventListener('submit',function(e){var b=f.querySelector('button');if(f.getAttribute('data-busy')==='1'){e.preventDefault();return;}f.setAttribute('data-busy','1');if(b){b.disabled=true;b.textContent=<?php echo wp_json_encode(__('Vérification…', 'delicat-google-login')); ?>;}});})();</script></body></html><?php
        exit;
    }

    public static function complete_web_challenge() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response'=>405]);
        if (!is_ssl()) { wp_safe_redirect(add_query_arg('dip_auth_error','two_factor_https_required',home_url('/')) . '#delicat-login'); exit; }
        self::private_nocache('Delicat 2FA challenge');
        list($state, $key, $token) = self::preauth_state();
        if (!$state) { self::clear_preauth_cookie(); wp_safe_redirect(add_query_arg('dip_auth_error','two_factor_expired',home_url('/')) . '#delicat-login'); exit; }
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'dip_2fa_challenge_' . substr(hash('sha256',$token),0,24))) { wp_safe_redirect(add_query_arg('dip_auth_error','two_factor_expired',home_url('/')) . '#delicat-login'); exit; }
        $user_id = absint($state['user_id']);
        $mode = sanitize_key(wp_unslash($_POST['mode'] ?? '')) === 'recovery' ? ['dip_2fa_mode'=>'recovery'] : [];
        if (!self::factor_attempt_allowed($user_id)) { wp_safe_redirect(self::challenge_url(array_merge($mode, ['dip_2fa_error'=>'rate_limited']))); exit; }
        $code = sanitize_text_field(wp_unslash($_POST['factor_code'] ?? ''));
        if (!self::verify_factor($user_id, $code, true)) {
            $reason = in_array(self::$last_failure, ['replay','unreadable'], true) ? self::$last_failure : 'invalid';
            $state['attempts'] = absint($state['attempts'] ?? 0) + 1;
            if ($state['attempts'] >= 6) { delete_transient($key); self::clear_preauth_cookie(); }
            else set_transient($key, $state, max(1, (int)$state['expires']-time()));
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_challenge_failed', 'warning', $user_id);
            wp_safe_redirect(self::challenge_url(array_merge($mode, ['dip_2fa_error'=>$reason])));
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

    /** Customer accounts only: staff 2FA can be reset from the server console alone. */
    private static function admin_resettable($user) {
        if (!($user instanceof WP_User)) return false;
        foreach (['manage_options', 'manage_woocommerce', 'edit_posts', 'edit_users', 'list_users', 'promote_users'] as $cap) {
            if (user_can($user, $cap)) return false;
        }
        return !class_exists('DIP_Account_Sync') || !method_exists('DIP_Account_Sync', 'is_customer_account') || DIP_Account_Sync::is_customer_account($user->ID);
    }

    public static function render_admin_reset($user) {
        if (!($user instanceof WP_User) || (int) $user->ID === get_current_user_id() || !current_user_can('manage_options') || !current_user_can('edit_user', $user->ID)) return;
        $enabled = self::is_enabled($user->ID);
        $status = sanitize_key(wp_unslash($_GET['dip_2fa_admin'] ?? ''));
        $notices = [
            'reset' => __('2FA réinitialisée : les sessions Web et App du client ont été révoquées et il a été prévenu par e-mail.', 'delicat-google-login'),
            'not_enabled' => __('La 2FA n’est pas active sur ce compte.', 'delicat-google-login'),
            'privileged' => __('Compte d’équipe : la 2FA ne peut être réinitialisée que depuis le serveur (WP-CLI).', 'delicat-google-login'),
        ];
        echo '<h2>' . esc_html__('Delicat — Vérification en deux étapes', 'delicat-google-login') . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__('État', 'delicat-google-login') . '</th><td>';
        if (isset($notices[$status])) echo '<div class="notice notice-' . ($status === 'reset' ? 'success' : 'warning') . ' inline"><p>' . esc_html($notices[$status]) . '</p></div>';
        if (!$enabled) {
            echo '<p>' . esc_html__('Non activée.', 'delicat-google-login') . '</p>';
        } elseif (!self::admin_resettable($user)) {
            echo '<p>' . esc_html__('Activée.', 'delicat-google-login') . '</p><p class="description">' . esc_html(sprintf(__('Compte d’équipe : réinitialisation uniquement depuis le serveur, par exemple « wp delicat identity-2fa-reset %d ».', 'delicat-google-login'), (int) $user->ID)) . '</p>';
        } else {
            echo '<p>' . esc_html__('Activée.', 'delicat-google-login') . '</p>';
            echo '<p><button type="submit" class="button" form="dip-2fa-admin-reset-form" onclick="return window.confirm(' . esc_attr(wp_json_encode(__('Réinitialiser la 2FA de ce client ? Toutes ses sessions Web et App seront révoquées.', 'delicat-google-login'))) . ');">' . esc_html__('Réinitialiser la 2FA du client', 'delicat-google-login') . '</button></p>';
            echo '<p class="description">' . esc_html__('À utiliser seulement après avoir vérifié l’identité du client (téléphone et codes de récupération perdus). Il pourra ensuite se connecter sans code et reconfigurer la 2FA.', 'delicat-google-login') . '</p>';
        }
        echo '</td></tr></table>';
    }

    /** Printed outside the profile form (a form cannot be nested); the button targets it through form="…". */
    public static function render_admin_reset_form() {
        $user_id = absint($_GET['user_id'] ?? 0);
        if (!$user_id || $user_id === get_current_user_id() || !current_user_can('manage_options') || !current_user_can('edit_user', $user_id)) return;
        $user = get_userdata($user_id);
        if (!self::is_enabled($user_id) || !self::admin_resettable($user)) return;
        echo '<form id="dip-2fa-admin-reset-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" hidden><input type="hidden" name="action" value="dip_2fa_admin_reset"><input type="hidden" name="user_id" value="' . esc_attr($user_id) . '">';
        wp_nonce_field('dip_2fa_admin_reset_' . $user_id);
        echo '</form>';
    }

    public static function admin_reset() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response'=>405]);
        if (!current_user_can('manage_options')) wp_die(esc_html__('Cette fonction est réservée aux administrateurs.', 'delicat-google-login'), '', ['response'=>403]);
        $user_id = absint($_POST['user_id'] ?? 0);
        check_admin_referer('dip_2fa_admin_reset_' . $user_id);
        $user = get_userdata($user_id);
        if (!($user instanceof WP_User) || $user_id === get_current_user_id() || !current_user_can('edit_user', $user_id)) wp_die(esc_html__('Accès refusé.', 'delicat-google-login'), '', ['response'=>403]);
        $back = add_query_arg('user_id', $user_id, admin_url('user-edit.php'));
        if (!self::admin_resettable($user)) { wp_safe_redirect(add_query_arg('dip_2fa_admin', 'privileged', $back)); exit; }
        if (!self::is_enabled($user_id)) { wp_safe_redirect(add_query_arg('dip_2fa_admin', 'not_enabled', $back)); exit; }
        // A stolen administrator cookie alone must not strip a customer's 2FA.
        if (class_exists('DIP_Reauth') && !DIP_Reauth::is_recent()) DIP_Reauth::require_recent_or_redirect();
        self::wipe_state($user_id);
        if (class_exists('WP_Session_Tokens')) WP_Session_Tokens::get_instance($user_id)->destroy_all();
        $mobile = class_exists('DIP_Mobile_API') ? DIP_Mobile_API::revoke_all_for_user($user_id, 'two_factor_admin_reset') : 0;
        if (class_exists('DIP_Audit')) {
            DIP_Audit::record('two_factor_admin_reset', 'critical', $user_id, ['admin_id'=>get_current_user_id(),'mobile_revoked'=>(int) $mobile]);
            DIP_Audit::record('two_factor_admin_reset_performed', 'warning', get_current_user_id(), ['customer_id'=>$user_id]);
        }
        do_action('dip_two_factor_disabled', $user_id);
        self::security_email($user_id, __('2FA réinitialisée sur votre compte Delicat', 'delicat-google-login'), __('À votre demande, l’équipe Delicat Store a réinitialisé la vérification en deux étapes de votre compte et fermé toutes vos sessions. Connectez-vous puis réactivez la 2FA depuis Mon compte › Sécurité.', 'delicat-google-login'));
        wp_safe_redirect(add_query_arg('dip_2fa_admin', 'reset', $back));
        exit;
    }

    private static function wipe_state($user_id) {
        $user_id = absint($user_id);
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
        self::clear_failure_day($user_id);
    }

    /**
     * Server-console recovery (any account). Customer accounts can also be
     * reset by an administrator from their profile screen.
     * Example: wp delicat identity-2fa-reset 123
     */
    public static function cli_reset($args, $assoc_args = []) {
        if (!(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))) return;
        $identifier = isset($args[0]) ? trim((string)$args[0]) : '';
        $user = ctype_digit($identifier) ? get_userdata(absint($identifier)) : get_user_by('login', $identifier);
        if (!$user && is_email($identifier)) $user = get_user_by('email', sanitize_email($identifier));
        if (!($user instanceof WP_User)) { \WP_CLI::error('User not found.'); return; }
        $user_id = (int)$user->ID;
        self::wipe_state($user_id);
        if (class_exists('WP_Session_Tokens')) WP_Session_Tokens::get_instance($user_id)->destroy_all();
        if (class_exists('DIP_Mobile_API')) DIP_Mobile_API::revoke_all_for_user($user_id, 'two_factor_cli_reset');
        if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_cli_reset', 'critical', $user_id);
        // Same consequences as a self-service disable (e.g. the admin Google
        // Secure Mode approval is revoked and must be granted again).
        do_action('dip_two_factor_disabled', $user_id);
        \WP_CLI::success('Delicat 2FA reset for user ' . $user_id . '. All WordPress/mobile sessions were revoked.');
    }
}
