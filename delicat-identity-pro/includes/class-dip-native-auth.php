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

        // Right after WordPress' own password check (20), before the
        // verification (35), 2FA and lockout gates, which all still apply.
        add_filter('authenticate', [__CLASS__, 'password_slash_compat'], 21, 3);
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
        add_action('wp_head', [__CLASS__, 'print_trigger_fallback'], 2);
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
        // Dual-stack sockets report IPv4 peers as ::ffff:a.b.c.d.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/iD', $value, $m)) $value = $m[1];
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    /** Cloudflare's published edge ranges (https://www.cloudflare.com/ips/). */
    const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    private static function ip_in_cidr($ip, $cidr) {
        list($subnet, $bits) = explode('/', $cidr, 2);
        $ip_bin = @inet_pton($ip);
        $subnet_bin = @inet_pton($subnet);
        if ($ip_bin === false || $subnet_bin === false || strlen($ip_bin) !== strlen($subnet_bin)) return false;
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes && substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) return false;
        $rest = $bits % 8;
        if (!$rest) return true;
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($ip_bin[$bytes]) & $mask) === (ord($subnet_bin[$bytes]) & $mask);
    }

    private static function is_cloudflare_edge($ip) {
        foreach (self::CLOUDFLARE_RANGES as $cidr) {
            if (self::ip_in_cidr($ip, $cidr)) return true;
        }
        return false;
    }

    /**
     * Does this request come straight from a Cloudflare edge? Only then are
     * Cloudflare's visitor headers (CF-Connecting-IP, CF-IPCountry) believed.
     * Define DIP_TRUST_CLOUDFLARE_HEADERS as false to never believe them.
     */
    public static function from_cloudflare_edge() {
        if (defined('DIP_TRUST_CLOUDFLARE_HEADERS') && DIP_TRUST_CLOUDFLARE_HEADERS === false) return false;
        $remote = self::normalize_ip(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        return $remote !== '' && self::is_cloudflare_edge($remote);
    }

    /**
     * Visitor address. REMOTE_ADDR is the default. 6.17.5 (IDN-03): the
     * Cloudflare visitor header is believed only when the connection itself
     * comes from Cloudflare's published ranges, whether or not the
     * DIP_TRUST_CLOUDFLARE_* constants are defined. Before, with a constant
     * defined, anyone reaching the origin directly could send any
     * CF-Connecting-IP and reset every per-address limit at will.
     */
    public static function client_ip() {
        $remote = self::normalize_ip(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')) ?: '0.0.0.0';
        $ip = $remote;
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && self::from_cloudflare_edge()) {
            $cf = self::normalize_ip(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
            if ($cf) $ip = $cf;
        }
        /** Site-specific reverse proxies can safely override the resolved address. */
        $filtered = apply_filters('dip_native_auth_client_ip', $ip, $remote);
        return self::normalize_ip($filtered) ?: $remote;
    }

    /**
     * Address that buckets the Google/OAuth, storefront, mobile, password
     * lockout, registration and verification-resend throttles: the visitor
     * address of client_ip() (Cloudflare-aware), with IPv6 grouped by /64
     * (one subscriber), so rotating addresses inside a prefix does not
     * create fresh buckets.
     */
    public static function throttle_ip() {
        $ip = self::client_ip();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) $ip = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));
        }
        return $ip;
    }

    /**
     * Network of an address: the /24 for IPv4, the /64 for IPv6. App pairing
     * compares the browser that asks with the phone that approves (IDN-05).
     */
    public static function network_prefix($ip) {
        $ip = self::normalize_ip($ip);
        $packed = $ip !== '' ? @inet_pton($ip) : false;
        if ($packed === false) return '';
        if (strlen($packed) === 4) return inet_ntop(substr($packed, 0, 3) . "\0") . '/24';
        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
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

    /**
     * Address of the lockout/registration/resend buckets. 6.12.0: the
     * visitor address (throttle_ip()) instead of the connection address.
     * Behind Cloudflare the connection address is a shared edge: 20 wrong
     * passwords from any visitor locked every customer routed through it,
     * and three sign-ups an hour closed registration for all of them. IPv6
     * is grouped per /64, so rotating inside one prefix no longer opens a
     * fresh bucket. A forged CF-Connecting-IP sent straight to the origin is
     * still ignored (only honoured from Cloudflare's published ranges).
     */
    private static function bucket_ip() {
        return self::throttle_ip();
    }

    private static function key($scope, $login = '') {
        $scope = sanitize_key($scope);
        $material = self::bucket_ip();
        if ($login !== '') $material .= '|' . self::account_identity($login);
        return 'dip_native_' . $scope . '_' . substr(hash_hmac('sha256', $material, wp_salt('auth')), 0, 40);
    }

    /**
     * Opaque id of this browser: Identity's one-year device cookie, else
     * the modal's browser cookie, else the User-Agent. Many customers share
     * one Digicel/Natcom address; their phones do not share these cookies.
     */
    private static function device_material() {
        if (class_exists('DIP_Devices') && method_exists('DIP_Devices', 'browser_key')) {
            $device = (string) DIP_Devices::browser_key();
            if ($device !== '') return 'device:' . $device;
        }
        $browser = self::browser_token(false);
        if ($browser !== '') return 'browser:' . hash_hmac('sha256', $browser, wp_salt('nonce'));
        return 'ua:' . substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300);
    }

    /** Bucket of this address + this browser (+ this account when $login is given). */
    private static function device_key($scope, $login = '') {
        $scope = sanitize_key($scope);
        $material = self::bucket_ip() . '|' . self::device_material();
        if ($login !== '') $material .= '|' . self::account_identity($login);
        return 'dip_native_' . $scope . '_dev_' . substr(hash_hmac('sha256', $material, wp_salt('auth')), 0, 40);
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

    /**
     * 6.17.5 (IDN-02, IDN-11). Buckets of the password lockout:
     * - device: this address + this browser, any account (20 failures/hour);
     * - ip: the whole address, a looser backstop against scripts that drop
     *   their cookies (60 failures/hour): one customer's typos no longer
     *   lock a shared Digicel/Natcom address for everyone;
     * - pair: this address + browser + account (the strict setting, 5);
     * - account: the account from anywhere (20 failures/24 h) against
     *   guessing spread over many addresses. It does not apply to a browser
     *   that has already signed in to that account (device cookie), so a
     *   stranger who knows a customer's e-mail cannot keep them out.
     * Windows were 24 hours for every bucket.
     */
    private static function scopes($login = '') {
        $pair_threshold = self::threshold();
        $keys = [[
            'fails'=>self::device_key('fails_ip'), 'lock'=>self::device_key('lock_ip'),
            'strikes'=>self::device_key('strikes_ip'), 'threshold'=>min(100, max(20, $pair_threshold * 4)), 'window'=>HOUR_IN_SECONDS, 'type'=>'device',
        ], [
            'fails'=>self::key('fails_ip'), 'lock'=>self::key('lock_ip'),
            'strikes'=>self::key('strikes_ip'), 'threshold'=>min(300, max(60, $pair_threshold * 12)), 'window'=>HOUR_IN_SECONDS, 'type'=>'ip',
        ]];
        if ($login !== '') {
            $keys[] = [
                'fails'=>self::device_key('fails_pair', $login),
                'lock'=>self::device_key('lock_pair', $login),
                'strikes'=>self::device_key('strikes_pair', $login),
                'threshold'=>$pair_threshold, 'window'=>HOUR_IN_SECONDS, 'type'=>'pair',
            ];
            $keys[] = [
                'fails'=>self::account_key('fails', $login),
                'lock'=>self::account_key('lock', $login),
                'strikes'=>self::account_key('strikes', $login),
                'threshold'=>min(60, max(20, $pair_threshold * 4)), 'window'=>DAY_IN_SECONDS, 'type'=>'account',
            ];
        }
        return $keys;
    }

    /** The account-wide lock never applies to a browser that already signed in to this account (IDN-11). */
    private static function known_browser_for($login) {
        $identity = self::account_identity($login);
        if (strpos($identity, 'uid:') !== 0 || !class_exists('DIP_Devices') || !method_exists('DIP_Devices', 'is_known_browser')) return false;
        return DIP_Devices::is_known_browser((int) substr($identity, 4));
    }

    public static function remaining_seconds($login = '') {
        $remaining = 0;
        $known = null;
        foreach (self::scopes($login) as $scope) {
            $until = (int) get_transient($scope['lock']);
            if ($until <= time()) continue;
            if ($scope['type'] === 'account') {
                if ($known === null) $known = self::known_browser_for($login);
                if ($known) continue;
            }
            $remaining = max($remaining, $until - time());
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

    /**
     * One failure in a bucket, returning the new total. 6.12.0: counted by
     * DIP_Lockout::counter_hit() (one atomic statement, 24 h window) where
     * the transient read-then-write lost most of a burst of simultaneous
     * guesses, so the lock and its escalation never engaged.
     */
    private static function count_failure($key, $window = DAY_IN_SECONDS) {
        if (class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'counter_hit')) return (int) DIP_Lockout::counter_hit($key, $window);
        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, $window);
        return $count;
    }

    private static function clear_count($key) {
        if (class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'counter_clear')) DIP_Lockout::counter_clear($key);
        else delete_transient($key);
    }

    /** Lock one bucket and escalate its strike once, even when several failures reach the threshold together. */
    private static function engage_lock(array $scope) {
        $mutex = class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'mutex_acquire');
        $name = 'dip_native_lockm_' . substr(md5((string) $scope['lock']), 0, 24);
        // Another request is locking this bucket right now.
        if ($mutex && !DIP_Lockout::mutex_acquire($name, 5)) return;
        try {
            if ((int) get_transient($scope['lock']) > time()) return;
            $strike = (int) get_transient($scope['strikes']) + 1;
            $minutes = self::lock_minutes_for_strike($strike);
            set_transient($scope['strikes'], $strike, DAY_IN_SECONDS);
            set_transient($scope['lock'], time() + ($minutes * MINUTE_IN_SECONDS), $minutes * MINUTE_IN_SECONDS);
            self::clear_count($scope['fails']);
        } finally {
            if ($mutex) DIP_Lockout::mutex_release($name);
        }
    }

    public static function record_failure($login = '', $reason = 'password') {
        foreach (self::scopes($login) as $scope) {
            $threshold = max(3, absint($scope['threshold'] ?? self::threshold()));
            if (self::count_failure($scope['fails'], (int) ($scope['window'] ?? DAY_IN_SECONDS)) >= $threshold) self::engage_lock($scope);
        }
        if (class_exists('DIP_Audit')) {
            DIP_Audit::record('login_failed', 'warning', 0, ['method'=>'password','reason'=>sanitize_key($reason)]);
        }
    }

    public static function clear_failures($login = '', $include_ip = false) {
        foreach (self::scopes($login) as $scope) {
            if (!$include_ip && in_array($scope['type'] ?? '', ['ip', 'device'], true)) continue;
            self::clear_count($scope['fails']);
            delete_transient($scope['lock']);
            delete_transient($scope['strikes']);
        }
    }

    public static function enforce_lockout($user, $username, $password) {
        $username = sanitize_text_field((string) $username);
        if ($username !== '' && self::is_locked($username)) {
            return new WP_Error(
                'delicat_locked',
                sprintf(__('Trop de tentatives. Réessayez dans environ %d minutes.', 'delicat-google-login'), self::lockout_minutes($username))
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

    /**
     * WordPress and WooCommerce forms hash and check the password exactly as
     * posted, i.e. with magic-quote slashes (« l\'été »); this modal (before
     * 6.12.0), REST, the app and passwordless sign-up stored the typed text.
     * A password with ' " or \ set on one side was therefore refused on the
     * other. When WordPress' own check failed, try the other form of the
     * same typed password for that account only. The verification, 2FA and
     * lockout gates run after this filter as usual.
     */
    public static function password_slash_compat($user, $username, $password) {
        if (!is_wp_error($user) || $user->get_error_code() !== 'incorrect_password' || !is_string($password) || $password === '') return $user;
        $candidates = [];
        foreach ([wp_unslash($password), wp_slash($password)] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $candidate !== $password) $candidates[] = $candidate;
        }
        if (!$candidates || strlen($password) > 4096) return $user;
        $login = is_string($username) ? trim($username) : '';
        if ($login === '') return $user;
        $account = get_user_by('login', $login);
        if (!$account && is_email($login)) $account = get_user_by('email', $login);
        if (!($account instanceof WP_User)) return $user;
        foreach (array_unique($candidates) as $candidate) {
            if (wp_check_password($candidate, $account->user_pass, $account->ID)) return $account;
        }
        return $user;
    }

    public static function record_failed_login($username, $error = null) {
        $username = sanitize_text_field((string) $username);
        // Policy failures are not password guesses and must not punish a real
        // customer by advancing the brute-force lockout counter. A valid but
        // blocked application password (2FA account, XML-RPC/REST) is not one
        // either: it is only reported after the credential matched.
        if ($error instanceof WP_Error) {
            $codes = $error->get_error_codes();
            if (array_intersect($codes, ['dip_account_banned','dip_email_not_verified','account_pending_approval','delicat_locked','dip_2fa_required','dip_2fa_invalid','dip_2fa_rate_limited','dip_2fa_https_required','dip_2fa_application_password_blocked'])) return;
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

    /** One posted text field, unslashed; arrays/objects read as '' (sanitize_email() of an array was a fatal TypeError). */
    private static function posted($field) {
        return isset($_POST[$field]) && is_scalar($_POST[$field]) ? (string) wp_unslash($_POST[$field]) : '';
    }

    public static function verify_browser_nonce($base, $field = 'nonce') {
        $token = self::browser_token(false);
        $nonce = sanitize_text_field(self::posted($field));
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
        ] + self::lazy_modal_payload());
    }

    /**
     * 6.17.5 (IDN-09): the sign-in window (~31 KB) is no longer printed in
     * every guest page. auth-shell.js asks for it here, with the page's
     * nonces, on the first tap (or hover/focus) on a sign-in button.
     */
    private static function lazy_modal_payload() {
        if (self::posted('with_modal') !== '1' || is_user_logged_in()) return [];
        $s = self::settings();
        if (($s['native_modal_enabled'] ?? 'yes') !== 'yes') return [];
        return ['modal_html' => self::modal_markup(self::safe_fragment_redirect(self::posted('redirect')))];
    }

    /** Print the window in the page (pre-6.17.5 behaviour) only on request. */
    private static function inline_modal() {
        return isset($_GET['dip_identity_eager']) || (bool) apply_filters('dip_native_modal_inline', false); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presentation flag.
    }

    private static function account_page_url() {
        $url = function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : '';
        return $url !== '' ? $url : wp_login_url();
    }

    /**
     * A tap on a sign-in button before auth-shell.js has loaded (slow phone,
     * slow network) opens the account page instead of doing nothing.
     * 6.17.7: also when the shell that runs is outdated (a phone's cached copy older than the page, which
     * cannot open the window this page does not print): only a shell declaring protocol 2 takes over.
     */
    public static function print_trigger_fallback() {
        if (is_admin() || is_user_logged_in() || !wp_script_is('dip-auth-shell', 'enqueued')) return;
        $url = self::account_page_url();
        echo '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false">(function(){var u=' . wp_json_encode(esc_url_raw($url)) . ';document.addEventListener("click",function(e){if(window.DIPAuthShell&&window.DIPAuthShellProtocol>=2)return;var t=e.target&&e.target.closest&&e.target.closest("[data-dip-auth-open],[data-dl-open],.dsb-account-login,a[href$=\"#delicat-login\"],a[href$=\"#dip-identity-modal\"],[aria-controls=\"dip-identity-modal\"]");if(!t)return;e.preventDefault();var r=u;try{var x=new URL(u,location.href);x.searchParams.set("redirect_to",location.href);r=x.href;}catch(_){}location.href=r;},true);})();</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON-encoded URL.
    }

    public static function ajax_resend_verification() {
        nocache_headers();
        if (!self::verify_browser_nonce('delicat_resend_verification_action')) {
            self::json_error(__('Session expirée. Nouvelle tentative…', 'delicat-google-login'), 'bad_nonce');
        }
        $email = sanitize_email(self::posted('email'));
        if (!is_email($email)) self::json_error(__('Adresse email invalide.', 'delicat-google-login'), 'invalid_email');

        // Mail-bombing guards. The cooldown and the hourly cap belong to the
        // mailbox, whatever the sender's address (6.12.0: they were per
        // address, so rotating addresses sent one e-mail per request); the
        // per-address bucket still caps one sender across many mailboxes.
        $recent = ['message'=>__('Un e-mail a déjà été demandé récemment. Vérifiez votre boîte de réception et les indésirables.', 'delicat-google-login')];
        $cooldown_key = self::account_key('verify_resend_cooldown', $email);
        if (get_transient($cooldown_key)) wp_send_json_success($recent);
        // Per address + browser (8 per 10 minutes), the shared address as a
        // looser backstop (80): one carrier address serves many customers.
        $device_key = self::device_key('verify_resend_ip');
        $global_key = self::key('verify_resend_ip');
        $device_state = self::window_state($device_key);
        $global_state = self::window_state($global_key);
        if ((int) $device_state['count'] >= 8 || (int) $global_state['count'] >= 80) {
            $wait = max(1, (int) ceil((max((int) $device_state['reset'], (int) $global_state['reset']) - time()) / MINUTE_IN_SECONDS));
            self::json_error(sprintf(__('Trop de demandes. Patientez %d min avant de redemander un e-mail.', 'delicat-google-login'), $wait), 'rate_limited');
        }
        self::window_add($device_key, 10 * MINUTE_IN_SECONDS);
        self::window_add($global_key, 10 * MINUTE_IN_SECONDS);
        set_transient($cooldown_key, 1, 90);
        $hourly_key = self::account_key('verify_resend_hour', $email);
        $hourly = class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'counter_hit') ? (int) DIP_Lockout::counter_hit($hourly_key, HOUR_IN_SECONDS) : 0;
        if ($hourly > 5) wp_send_json_success($recent);

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
        return add_query_arg('dip_auth_sync', DIP_Session_Router::sync_marker(), remove_query_arg('dip_auth_sync', $url));
    }

    public static function ajax_login() {
        nocache_headers();
        if (is_user_logged_in()) {
            $return = self::posted_return();
            wp_send_json_success(['message'=>__('Vous êtes déjà connecté.', 'delicat-google-login'), 'redirect'=>self::auth_transition_redirect($return !== '' ? $return : self::login_redirect())]);
        }
        if (!self::verify_browser_nonce('delicat_login_action')) {
            self::json_error(__('Session expirée. Nouvelle tentative…', 'delicat-google-login'), 'bad_nonce');
        }
        if (!empty($_POST['dl_hp'])) {
            self::record_failure('', 'honeypot');
            self::json_error(__('Erreur de validation.', 'delicat-google-login'));
        }
        $email = sanitize_email(self::posted('email'));
        // Typed text for the checks below; WordPress itself (wp-login.php,
        // WooCommerce) authenticates the posted, slashed value, so wp_signon()
        // gets that one (see password_slash_compat()).
        $pass = self::posted('password');
        $posted_pass = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passwords are never altered.
        $remember = !empty($_POST['remember']);
        $s = self::settings();
        $max = min(256, max(32, absint($s['native_password_max'] ?? 100)));

        if (self::is_locked($email)) {
            self::json_error(sprintf(__('Trop de tentatives. Réessayez dans environ %d minutes.', 'delicat-google-login'), self::lockout_minutes($email)), 'locked');
        }
        if (!is_email($email) || $pass === '' || strlen($pass) > $max * 4) {
            self::record_failure($email, 'invalid_input');
            self::json_error(__('Email ou mot de passe invalide.', 'delicat-google-login'));
        }

        $user = wp_signon([
            'user_login' => $email,
            'user_password' => $posted_pass,
            'remember' => $remember,
        ], is_ssl());
        if (is_wp_error($user)) {
            // wp_signon fires wp_login_failed; account-policy errors are safe to
            // surface because they help the legitimate customer recover.
            $code = $user->get_error_code();
            $safe_policy = ['dip_account_banned','delicat_locked','dip_email_not_verified','account_pending_approval','dip_2fa_required','dip_2fa_invalid','dip_2fa_rate_limited','dip_2fa_https_required'];
            $message = in_array($code, $safe_policy, true)
                ? wp_strip_all_tags($user->get_error_message())
                : __('Identifiants incorrects. Vérifiez votre email et votre mot de passe.', 'delicat-google-login');
            self::json_error($message, $code === 'delicat_locked' ? 'locked' : (in_array($code, $safe_policy, true) ? $code : 'invalid_credentials'));
        }

        wp_set_current_user($user->ID);
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_login($user->ID);
        $return = self::posted_return();
        wp_send_json_success([
            'message' => __('Connexion réussie. Redirection…', 'delicat-google-login'),
            'redirect' => self::auth_transition_redirect($return !== '' ? $return : self::login_redirect()),
        ]);
    }

    private static function registration_allowed() {
        $s = self::settings();
        if (($s['allow_registration'] ?? 'yes') !== 'yes') return false;
        return DIP_Account_Sync::storefront_registration_enabled();
    }

    /**
     * Successful sign-ups per hour. 6.17.5 (IDN-02): the setting applies to
     * this address + browser; the whole address gets ten times as many
     * (at least 30), so one carrier address no longer closes sign-up for
     * everyone after three accounts.
     */
    private static function registration_limits() {
        $s = self::settings();
        $device = min(20, max(1, absint($s['native_reg_max_per_hour'] ?? 3)));
        return [$device, max(30, $device * 10)];
    }

    private static function window_state($key) {
        $state = get_transient($key);
        return is_array($state) && !empty($state['reset']) && (int) $state['reset'] > time() ? $state : ['count'=>0, 'reset'=>0];
    }

    private static function window_add($key, $window) {
        $state = self::window_state($key);
        if (empty($state['reset'])) $state = ['count'=>0, 'reset'=>time() + $window];
        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        set_transient($key, $state, max(1, (int) $state['reset'] - time()));
        return $state;
    }

    public static function registration_rate_limited() {
        [$device, $address] = self::registration_limits();
        return (int) self::window_state(self::device_key('registrations'))['count'] >= $device
            || (int) self::window_state(self::key('registrations'))['count'] >= $address;
    }

    public static function record_registration() {
        self::window_add(self::device_key('registrations'), HOUR_IN_SECONDS);
        self::window_add(self::key('registrations'), HOUR_IN_SECONDS);
    }

    /** Short-window abuse bucket for every public registration attempt (address + browser, and the address). */
    public static function registration_attempt_allowed() {
        [$success_limit] = self::registration_limits();
        $limit = min(60, max(12, $success_limit * 4));
        $device_key = self::device_key('registration_attempts');
        $address_key = self::key('registration_attempts');
        if ((int) self::window_state($device_key)['count'] >= $limit || (int) self::window_state($address_key)['count'] >= $limit * 10) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('registration_attempt_rate_limited', 'warning', 0);
            return false;
        }
        self::window_add($device_key, 10 * MINUTE_IN_SECONDS);
        self::window_add($address_key, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    public static function ajax_register() {
        nocache_headers();
        if (is_user_logged_in()) {
            $return = self::posted_return();
            wp_send_json_success(['message'=>__('Votre compte est déjà connecté.', 'delicat-google-login'), 'redirect'=>self::auth_transition_redirect($return !== '' ? $return : self::register_redirect())]);
        }
        if (!self::verify_browser_nonce('delicat_register_action')) {
            self::json_error(__('Session expirée. Nouvelle tentative…', 'delicat-google-login'), 'bad_nonce');
        }
        if (!empty($_POST['dl_hp'])) {
            self::record_failure('', 'honeypot');
            self::json_error(__('Erreur de validation.', 'delicat-google-login'));
        }
        if (!self::registration_attempt_allowed()) self::json_error(__('Trop de demandes d’inscription. Réessayez dans quelques minutes.', 'delicat-google-login'), 'registration_attempt_rate_limited');
        if (self::is_locked()) self::json_error(__('Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'), 'locked');
        if (!self::registration_allowed()) self::json_error(__('Les inscriptions sont actuellement fermées.', 'delicat-google-login'));
        if (self::registration_rate_limited()) self::json_error(__('Limite d’inscriptions atteinte. Réessayez dans une heure.', 'delicat-google-login'), 'registration_rate_limited');

        $s = self::settings();
        $name = sanitize_text_field(self::posted('name'));
        $email = sanitize_email(self::posted('email'));
        $pass = self::posted('password');
        $name_len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        $min = min(64, max(8, absint($s['native_password_min'] ?? 8)));
        $max = min(256, max($min, absint($s['native_password_max'] ?? 100)));

        if ($name === '' || $name_len > 80) self::json_error(__('Veuillez entrer votre nom complet.', 'delicat-google-login'));
        if (!is_email($email)) self::json_error(__('Adresse email invalide.', 'delicat-google-login'));
        $pass_len = function_exists('mb_strlen') ? mb_strlen($pass, 'UTF-8') : strlen($pass);
        if (strlen($pass) > 1024 || $pass_len < $min) self::json_error(sprintf(__('Le mot de passe doit contenir au moins %d caractères.', 'delicat-google-login'), $min));
        if ($pass_len > $max) self::json_error(__('Le mot de passe est trop long.', 'delicat-google-login'));
        if (!preg_match('/\p{L}/u', $pass) || !preg_match('/\p{N}/u', $pass)) {
            self::json_error(__('Le mot de passe doit contenir au moins une lettre et un chiffre.', 'delicat-google-login'));
        }
        // Checked after every field rule, so the answer never depends on whether the address exists.
        if (email_exists($email)) {
            $requires = class_exists('DIP_Passwordless_Registration') && DIP_Passwordless_Registration::requires_email_verification();
            if (!$requires) self::json_error(__('Impossible de finaliser l’inscription. Si vous avez déjà un compte, utilisez Connexion ou Mot de passe oublié.', 'delicat-google-login'), 'registration_failed');
            // IDN-10: the same answer as a new sign-up, whether the address has
            // an account or not; its owner gets an e-mail explaining how to sign in.
            DIP_Passwordless_Registration::notify_existing_account_signup($email);
            self::record_registration();
            wp_send_json_success([
                'message' => __('Compte créé. Vérifiez maintenant votre e-mail pour activer la connexion.', 'delicat-google-login'),
                'verificationRequired' => true,
            ]);
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
        // Stored as WordPress and WooCommerce forms store it (the posted,
        // slashed value), so WooCommerce's own current-password check in
        // « Détails du compte » accepts it too. Accounts registered before
        // 6.12.0 keep the typed form: password_slash_compat() covers both.
        $user_id = DIP_Account_Sync::create_customer($email, $username, wp_slash($pass), [
            'display_name' => $name,
            'first_name' => $names[0] ?? $name,
            'last_name' => $names[1] ?? '',
        ], $role);
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
            // The browser that chose this password may confirm it and keep it (IDN-01).
            DIP_Email_Proof::bind_registration_browser($user_id);
            if (class_exists('DIP_Audit')) DIP_Audit::record('native_registration_pending_verification', 'notice', $user_id);
            wp_send_json_success([
                'message' => __('Compte créé. Vérifiez maintenant votre e-mail pour activer la connexion.', 'delicat-google-login'),
                'verificationRequired' => true,
            ]);
        }

        // Verification is switched off: the mailbox was never proven, so this
        // only allows the login (no proof marker, no guest-order linking).
        DIP_Email_Proof::prove($user_id, $email, 'unproven');
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());
        $user = get_user_by('id', $user_id);
        if ($user) {
            DIP_Account_Sync::fire_wp_login($user, 'native_registration');
            do_action('dip_login_success', $user_id, ['provider'=>'native_registration']);
        }
        DIP_Account_Sync::after_login($user_id);
        $return = self::posted_return();

        wp_send_json_success([
            'message' => __('Compte créé avec succès. Redirection…', 'delicat-google-login'),
            'redirect' => self::auth_transition_redirect($return !== '' ? $return : self::register_redirect()),
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
        if (!in_array($handle, ['dip-auth-shell','dip-session-router','dip-passkeys'], true)) return $tag;
        foreach (['data-cfasync="false"', 'data-no-optimize="1"', 'data-no-delay="1"', 'data-no-defer="1"'] as $attribute) {
            $name = substr($attribute, 0, strpos($attribute, '='));
            if (strpos($tag, $name . '=') === false) $tag = str_replace('<script ', '<script ' . $attribute . ' ', $tag);
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
        return ($s['native_modal_enabled'] ?? 'yes') === 'yes';
    }

    private static function ajax_url() {
        $ajax_url = wp_make_link_relative(admin_url('admin-ajax.php'));
        if (!is_string($ajax_url) || $ajax_url === '' || strpos($ajax_url, '/') !== 0) return '/wp-admin/admin-ajax.php';
        return $ajax_url;
    }

    private static function passkey_frontend_config() {
        if (!class_exists('DIP_Passkeys') || !method_exists('DIP_Passkeys', 'frontend_config')) return [];
        return DIP_Passkeys::frontend_config();
    }

    public static function enqueue_assets() {
        if (is_admin() || is_user_logged_in()) return;
        $s = self::settings();
        if (($s['native_modal_enabled'] ?? 'yes') !== 'yes') return;

        // 6.17.5 (IDN-09): login.css only where Identity draws buttons in the
        // page itself (account, checkout, cart, shortcodes force it when they
        // render); the window carries its own CSS inside its frame.
        DIP_Plugin::instance()->assets(false);
        // The site theme probe is needed in the head only where the account
        // area is painted; on catalogue pages it runs at the end of the page.
        $account_area = class_exists('DIP_Performance') && DIP_Performance::should_load_front_assets();
        wp_enqueue_script('dip-account-theme', DIP_URL . 'assets/account-theme.js', [], DIP_VERSION, !$account_area);
        wp_enqueue_script('dip-auth-shell', DIP_URL . 'assets/auth-shell.js', ['dip-account-theme'], DIP_VERSION, true);
        wp_localize_script('dip-auth-shell', 'DIPIdentityModal', [
            'frameJsUrl' => add_query_arg('ver', DIP_VERSION, DIP_URL . 'assets/auth-frame.js'),
            'passkeysJsUrl' => add_query_arg('ver', DIP_VERSION, DIP_URL . 'assets/passkeys.js'),
            'passkeys' => self::passkey_frontend_config(),
            'ajaxUrl' => self::ajax_url(),
            'redirectUrl' => self::login_redirect(),
            'nonceAction' => self::AJAX_NONCE_ACTION,
            'loginAction' => self::AJAX_LOGIN_ACTION,
            'registerAction' => self::AJAX_REGISTER_ACTION,
            'resendAction' => self::AJAX_RESEND_ACTION,
            'modalId' => 'dip-identity-modal',
                'modalCssUrl' => add_query_arg('ver', DIP_VERSION, DIP_URL . 'assets/auth-frame.css'),
            'legacyDetected' => self::legacy_snippet_active() ? 1 : 0,
            'registrationEnabled' => self::registration_allowed() ? 1 : 0,
            'lazyModal' => self::inline_modal() ? 0 : 1,
            'accountUrl' => esc_url_raw(self::account_page_url()),
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
        $redirect = self::safe_fragment_redirect(self::posted('redirect'));
        wp_send_json_success(['html' => self::modal_markup($redirect)]);
    }

    private static function current_page_url() {
        $uri = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        if ($uri === '' || strpos($uri, '/') !== 0 || strpos($uri, '//') === 0 || strlen($uri) > 4096) $uri = '/';
        // REQUEST_URI already holds the install sub-folder: home_url($uri)
        // doubled it (/shop/shop/...) on sites installed below the root.
        $home = wp_parse_url(home_url('/'));
        $origin = ($home['scheme'] ?? 'https') . '://' . ($home['host'] ?? '') . (isset($home['port']) ? ':' . (int) $home['port'] : '');
        $url = wp_validate_redirect($origin . $uri, home_url('/'));
        return remove_query_arg(array('dip_identity_eager', 'dip_identity_open'), $url);
    }

    /**
     * The checkout or cart page the modal was opened on, so a buyer who signs
     * in there goes back to pay (« paiement → retour au paiement », as the
     * Google flow does); '' anywhere else, where the configured landing page
     * applies. Same-site storefront URLs only (DIP_Session_Router::destination).
     */
    private static function checkout_return($url) {
        if (!is_string($url) || $url === '' || strlen($url) > 2048 || !function_exists('wc_get_checkout_url')) return '';
        $safe = DIP_Session_Router::destination($url, '');
        $path = untrailingslashit((string) wp_parse_url($safe, PHP_URL_PATH));
        $received = trim((string) get_option('woocommerce_checkout_order_received_endpoint', 'order-received'), '/');
        if ($received !== '' && preg_match('~/' . preg_quote($received, '~') . '(?:/|$)~i', $path . '/')) return '';
        foreach ([wc_get_checkout_url(), function_exists('wc_get_cart_url') ? wc_get_cart_url() : ''] as $page) {
            $page_path = untrailingslashit((string) wp_parse_url((string) $page, PHP_URL_PATH));
            if ($page_path === '' || $page_path === untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH))) continue;
            if ($path === $page_path || strpos($path . '/', $page_path . '/') === 0) return remove_query_arg(self::ONE_SHOT_ARGS, $safe);
        }
        return '';
    }

    /**
     * Cart actions and markers that must not run again when the customer is
     * sent back to a page after signing in (« ?add-to-cart= » would add the
     * product a second time).
     */
    const ONE_SHOT_ARGS = ['add-to-cart', 'quantity', 'variation_id', 'remove_item', 'undo_item', 'removed_item', 'order_again', 'apply_coupon', 'remove_coupon', 'cancel_order', 'add-to-wishlist', 'remove_from_wishlist', '_wpnonce', '_wp_http_referer', 'redirect', 'redirect_to', 'dip_auth_sync', 'dip_identity_open', 'dip_identity_eager'];

    /**
     * A destination the customer asked for (?redirect_to= on the account page,
     * as the checkout panel's « Email ou mot de passe » and protected pages
     * send it), limited to same-site storefront pages by
     * DIP_Session_Router::destination(); '' when refused.
     */
    private static function requested_return($url) {
        if (!is_string($url) || trim($url) === '' || strlen($url) > 2048) return '';
        $safe = DIP_Session_Router::destination($url, '');
        // destination() answers the home page for a refused address.
        if ($safe === '' || untrailingslashit($safe) === untrailingslashit(home_url('/'))) return '';
        return remove_query_arg(self::ONE_SHOT_ARGS, $safe);
    }

    /**
     * Where a modal sign-in opened on this page returns instead of the
     * configured landing page ('' = landing page): its checkout/cart page, or
     * the page its address names with ?redirect_to=.
     */
    private static function return_target($page_url) {
        $checkout = self::checkout_return($page_url);
        if ($checkout !== '') return $checkout;
        $query = (string) wp_parse_url((string) $page_url, PHP_URL_QUERY);
        if ($query === '') return '';
        parse_str($query, $args);
        return isset($args['redirect_to']) && is_string($args['redirect_to']) ? self::requested_return($args['redirect_to']) : '';
    }

    /** The return target the modal form posted back (only ever rendered by return_target()), validated again. */
    private static function posted_return() {
        $posted = self::posted('redirect');
        $checkout = self::checkout_return($posted);
        return $checkout !== '' ? $checkout : self::requested_return($posted);
    }

    public static function render_modal() {
        if (self::$modal_rendered || is_admin() || is_user_logged_in()) return;
        $s = self::settings();
        if (($s['native_modal_enabled'] ?? 'yes') !== 'yes') return;
        // Loaded on demand (fresh_nonces with_modal=1) unless asked inline.
        if (!self::inline_modal()) return;
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
        $allowed['a'] = array_merge($allowed['a'] ?? [], ['class' => true, 'href' => true, 'rel' => true, 'aria-label' => true, 'target' => true, 'data-dip-location' => true, 'data-dip-provider' => true, 'data-delicat-no-app' => true, 'data-no-instant' => true, 'data-no-prefetch' => true]);
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
        foreach (['auth-frame.css', 'passkeys.css', 'auth-modern.css'] as $asset) {
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
        // Checkout/cart or an explicit ?redirect_to= only; read by the password
        // forms and passkeys.js, and given to Google too.
        $return_to = self::return_target($redirect_url);
        $return_field = $return_to !== '' ? '<input type="hidden" name="redirect" value="' . esc_url($return_to) . '">' : '';

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
                'redirect' => $return_to !== '' ? $return_to : $redirect_url,
                'suppress_divider' => true,
            ]);
        }
        ob_start();
        ?>
        <template id="dip-identity-modal" data-dip-identity-modal>
          <style data-dip-modal-style data-no-optimize="1"><?php echo self::modal_styles(); // Trusted plugin assets only. ?></style>
          <div class="dipx-backdrop" data-dip-auth-close></div>
          <section class="dipx-card" tabindex="-1">
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

            <?php if (class_exists('DIP_Passkeys') && DIP_Passkeys::available()) : ?>
            <div class="dipx-quick" data-dipx-quick>
              <?php echo DIP_Passkeys::render_login_button(); ?>
            </div>
            <?php endif; ?>

            <?php if (trim($google) !== '') : ?>
            <div class="dipx-divider"><span><?php echo esc_html__('ou continuer avec', 'delicat-google-login'); ?></span></div>
            <div class="dipx-social"><?php echo self::sanitize_provider_markup($google); ?></div>
            <?php elseif (current_user_can('manage_options') && class_exists('DIP_Plugin')) : ?>
            <div class="dipx-social dipx-social-diagnostic" role="note"><small><?php echo esc_html__('Visible uniquement par un administrateur — bouton Google masqué :', 'delicat-google-login'); ?> <?php echo esc_html(DIP_Plugin::google_availability_label($google_state)); ?></small></div>
            <?php endif; ?>

            <div class="dipx-message" role="alert" aria-live="polite" hidden></div>
            <input type="hidden" class="dipx-nonce-resend" value="">
            <button type="button" class="dipx-resend" data-dipx-resend hidden><?php echo esc_html__('Renvoyer l’e-mail de vérification', 'delicat-google-login'); ?></button>

            <?php $methods = wp_parse_args((array)get_option(DIP_Passwordless_Registration::OPTION,[]),DIP_Passwordless_Registration::defaults()); if (($methods['magic_enabled'] ?? 'yes') === 'yes') : ?>
            <form class="dipx-magic-form" data-dipx-magic method="post" action="<?php echo esc_url(self::ajax_url()); ?>">
              <details class="dipx-password-method"><summary>Recevoir un lien par e-mail</summary><div class="dipx-password-content">
                <p class="dipx-help">Recevez un lien à usage unique pour vous connecter sans mot de passe.</p>
                <label class="dipx-label" for="dipx-magic-email">Adresse e-mail</label><div class="dipx-field"><input id="dipx-magic-email" type="email" name="email" autocomplete="email" inputmode="email" maxlength="254" required></div>
                <button type="submit" class="dipx-submit">Envoyer le lien sécurisé</button>
              </div></details>
            </form>
            <?php endif; ?>

            <form id="dipx-panel-login" class="dipx-form dipx-form-login" method="post" action="<?php echo esc_url(self::ajax_url()); ?>" autocomplete="on" novalidate role="tabpanel" aria-labelledby="dipx-tab-login">
              <details class="dipx-password-method"<?php if (!class_exists('DIP_Passkeys') || !DIP_Passkeys::available()) echo ' open'; ?>>
              <summary><?php esc_html_e('E-mail et mot de passe', 'delicat-google-login'); ?></summary>
              <div class="dipx-password-content">
              <label class="dipx-label" for="dipx-login-email"><span class="dipx-i dipx-i-mail" aria-hidden="true"></span><span><?php echo esc_html__('Email', 'delicat-google-login'); ?></span></label>
              <div class="dipx-field">
                <input id="dipx-login-email" type="email" name="email" placeholder="Votre@email.com" autocomplete="username webauthn" inputmode="email" maxlength="254" required>
              </div>

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
              <?php echo $return_field; // Escaped above. ?>
              <button type="submit" class="dipx-submit"><span class="dipx-i dipx-i-lock" aria-hidden="true"></span><span class="dipx-submit-label"><?php echo esc_html__('Connexion', 'delicat-google-login'); ?></span><span class="dipx-spinner" hidden aria-hidden="true"></span></button>
              </div></details>
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
              <?php echo $return_field; // Escaped above. ?>
              <button type="submit" class="dipx-submit"><span class="dipx-i dipx-i-user-plus" aria-hidden="true"></span><span class="dipx-submit-label"><?php echo esc_html__('Créer mon compte', 'delicat-google-login'); ?></span><span class="dipx-spinner" hidden aria-hidden="true"></span></button>
            </form>
            <?php endif; ?>

            <footer class="dipx-security"><details class="dipx-privacy"><summary><svg class="dipx-identity-shield" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2 21 6v6c0 5-5 9-9 10-4-1-9-5-9-10V6z" fill="currentColor" opacity=".14"/><path d="m12 2 9 4v6c0 5-5 9-9 10-4-1-9-5-9-10V6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="m8 12 3 3 5-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span><?php esc_html_e('Sécurisé par Delicat Identity', 'delicat-google-login'); ?></span></summary><p><?php esc_html_e('Avec une clé d’accès, votre appareil vérifie votre identité. Delicat Store ne reçoit ni votre biométrie ni le code de votre appareil. Utilisez une clé déjà enregistrée pour Delicat Store.', 'delicat-google-login'); ?></p></details></footer>
          </section>
        </template>
        <?php
        return ob_get_clean();
    }

}
