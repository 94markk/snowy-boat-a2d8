<?php
defined('ABSPATH') || exit;

/**
 * Delicat Identity 6.4 security architecture.
 *
 * Provides a deny-by-default boundary around the plugin REST namespace and a
 * central action policy registry. Individual endpoint/action nonce and
 * ownership checks remain in place; this class is an additional independent
 * authorization layer.
 */
final class DIP_Security_Architecture {
    const REST_NAMESPACE = '/delicat-identity/v1/';

    private static $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;

        // Run before route callbacks. WordPress' own permission_callback still
        // executes as a second independent boundary.
        add_filter('rest_pre_dispatch', [__CLASS__, 'rest_firewall'], 1, 3);
        add_filter('rest_post_dispatch', [__CLASS__, 'rest_security_headers'], 20, 3);

        // admin-post.php fires admin_init before dispatching the requested
        // action. Validate the action class centrally before module code runs.
        add_action('admin_init', [__CLASS__, 'ajax_firewall'], -30);
        add_action('admin_init', [__CLASS__, 'action_firewall'], -20);
    }

    private static function settings() {
        if (!class_exists('DIP_Plugin')) return [];
        return wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
    }

    private static function strict_rest_enabled() {
        $s = self::settings();
        return ($s['strict_rest_firewall'] ?? 'yes') === 'yes';
    }

    /**
     * Exact public REST surface. Public means authentication is intentionally
     * performed by the endpoint itself (OAuth, refresh, biometric proof or
     * one-time pairing secret), not that the route is unrestricted.
     */
    private static function public_rest_rules() {
        return apply_filters('dip_public_rest_rules', [
            ['#^/delicat-identity/v1/passkeys/login/options$#', ['POST']],
            ['#^/delicat-identity/v1/passkeys/login/verify$#', ['POST']],
            ['#^/delicat-identity/v1/mobile/google$#', ['POST']],
            ['#^/delicat-identity/v1/mobile/refresh$#', ['POST']],
            ['#^/delicat-identity/v1/mobile/capabilities$#', ['GET']],
            ['#^/delicat-identity/v1/headless/status$#', ['GET']],
            ['#^/delicat-identity/v1/mobile/biometric/challenge$#', ['POST']],
            ['#^/delicat-identity/v1/mobile/biometric/verify$#', ['POST']],
            ['#^/delicat-identity/v1/pairing/create$#', ['POST']],
            ['#^/delicat-identity/v1/pairing/status$#', ['POST']],
            ['#^/delicat-identity/v1/pairing/exchange$#', ['POST']],
        ]);
    }

    /** Cookie-authenticated customer self-service routes. */
    private static function customer_rest_rules() {
        return apply_filters('dip_customer_rest_rules', [
            ['#^/delicat-identity/v1/passkeys/register/options$#', ['POST']],
            ['#^/delicat-identity/v1/passkeys/register/verify$#', ['POST']],
            ['#^/delicat-identity/v1/passkeys/register/proof$#', ['POST']],
            ['#^/delicat-identity/v1/passkeys/reauth/options$#', ['POST']],
            ['#^/delicat-identity/v1/passkeys/reauth/verify$#', ['POST']],
            ['#^/delicat-identity/v1/passkeys/[A-Za-z0-9_-]{20,160}$#', ['DELETE']],
        ]);
    }

    /** Routes requiring a device-bound Delicat mobile session. */
    private static function mobile_rest_rules() {
        return apply_filters('dip_mobile_rest_rules', [
            ['#^/delicat-identity/v1/mobile/logout$#', ['POST']],
            ['#^/delicat-identity/v1/mobile/profile$#', ['GET']],
            ['#^/delicat-identity/v1/mobile/sessions$#', ['GET']],
            ['#^/delicat-identity/v1/mobile/sessions/\d+$#', ['DELETE']],
            ['#^/delicat-identity/v1/mobile/device$#', ['POST']],
            ['#^/delicat-identity/v1/mobile/push$#', ['POST','DELETE']],
            ['#^/delicat-identity/v1/mobile/biometric/register$#', ['POST']],
            ['#^/delicat-identity/v1/pairing/approve$#', ['POST']],
        ]);
    }

    private static function matches_rule($route, $method, array $rules) {
        $method = strtoupper((string) $method);
        foreach ($rules as $rule) {
            $pattern = isset($rule[0]) ? (string) $rule[0] : '';
            $methods = isset($rule[1]) ? array_map('strtoupper', (array) $rule[1]) : [];
            if ($pattern && preg_match($pattern, $route) && in_array($method, $methods, true)) return true;
        }
        return false;
    }

    private static function is_identity_route($route) {
        return strpos((string) $route, self::REST_NAMESPACE) === 0;
    }

    private static function known_rest_route($route) {
        foreach (array_merge(self::public_rest_rules(), self::customer_rest_rules(), self::mobile_rest_rules()) as $rule) {
            $pattern = isset($rule[0]) ? (string) $rule[0] : '';
            if ($pattern && preg_match($pattern, (string) $route)) return true;
        }
        return false;
    }

    private static function audit_denied($event, array $meta = []) {
        if (!class_exists('DIP_Audit')) return;
        $safe = [];
        foreach ($meta as $key => $value) {
            $key = sanitize_key((string) $key);
            if (!$key) continue;
            if (is_scalar($value)) $safe[$key] = substr(sanitize_text_field((string) $value), 0, 180);
        }
        DIP_Audit::record($event, 'warning', get_current_user_id(), $safe);
    }

    /**
     * Deny-by-default REST firewall for the Delicat Identity namespace.
     * Unknown routes cannot silently become public after a future module update.
     */
    public static function rest_firewall($result, $server, $request) {
        if ($result !== null) return $result;
        if (!$request instanceof WP_REST_Request) return $result;
        $route = (string) $request->get_route();
        if (!self::is_identity_route($route)) return $result;

        $method = strtoupper((string) $request->get_method());
        // REST OPTIONS is metadata/preflight only. Permit it for known routes
        // so browser/mobile clients can negotiate without opening unknown APIs.
        if ($method === 'OPTIONS' && self::known_rest_route($route)) return $result;
        if (self::matches_rule($route, $method, self::public_rest_rules())) return $result;

        if (self::matches_rule($route, $method, self::customer_rest_rules())) {
            if (!is_user_logged_in()) {
                self::audit_denied('rest_customer_login_required', ['route'=>$route,'method'=>$method]);
                return new WP_Error('dip_login_required', __('Connexion requise.', 'delicat-google-login'), ['status'=>401]);
            }
            $uid = get_current_user_id();
            if (!$uid || (class_exists('DIP_Access_Guard') && !DIP_Access_Guard::can_self_service($uid))) {
                self::audit_denied('rest_customer_access_denied', ['route'=>$route,'method'=>$method]);
                return new WP_Error('dip_self_service_denied', __('Accès limité à votre propre compte.', 'delicat-google-login'), ['status'=>403]);
            }
            return $result;
        }

        if (self::matches_rule($route, $method, self::mobile_rest_rules())) {
            if (!class_exists('DIP_Mobile_API') || !DIP_Mobile_API::logged_in($request)) {
                self::audit_denied('rest_mobile_access_denied', ['route'=>$route,'method'=>$method]);
                return new WP_Error('dip_mobile_auth_required', __('Authentification mobile sécurisée requise.', 'delicat-google-login'), ['status'=>401]);
            }
            $uid = get_current_user_id();
            if (!$uid || (class_exists('DIP_Access_Guard') && !DIP_Access_Guard::can_self_service($uid))) {
                self::audit_denied('rest_mobile_identity_mismatch', ['route'=>$route,'method'=>$method]);
                return new WP_Error('dip_mobile_identity_mismatch', __('Session mobile invalide.', 'delicat-google-login'), ['status'=>403]);
            }
            if (class_exists('DIP_Account_Sync')) {
                $guard = DIP_Account_Sync::login_guard($uid);
                if (is_wp_error($guard)) {
                    if (method_exists('DIP_Mobile_API', 'revoke_all_for_user')) DIP_Mobile_API::revoke_all_for_user($uid, 'rest_policy_denied');
                    self::audit_denied('rest_mobile_account_policy_denied', ['route'=>$route,'code'=>$guard->get_error_code()]);
                    return new WP_Error('dip_account_policy_denied', __('Ce compte ne peut pas utiliser cette fonction.', 'delicat-google-login'), ['status'=>403]);
                }
            }
            return $result;
        }

        if (self::strict_rest_enabled()) {
            self::audit_denied('rest_unclassified_route_denied', ['route'=>$route,'method'=>$method]);
            return new WP_Error('dip_rest_route_denied', __('Route Delicat Identity non autorisée.', 'delicat-google-login'), ['status'=>403]);
        }
        return $result;
    }

    /** Private identity REST responses must never be cached by a shared proxy. */
    public static function rest_security_headers($response, $server, $request) {
        if (!$request instanceof WP_REST_Request || !$response instanceof WP_REST_Response) return $response;
        if (!self::is_identity_route((string) $request->get_route())) return $response;
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Referrer-Policy', 'no-referrer');
        $response->header('X-Frame-Options', 'DENY');
        return $response;
    }

    /** Central policy for Delicat Identity AJAX actions. */
    public static function ajax_firewall() {
        if (!is_admin() || !wp_doing_ajax()) return;
        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? ''));
        if ($action === '' || strpos($action, 'dip_') !== 0) return;

        $public = [
            'dip_session_status',
            'dip_session_logout',
            'dip_native_modal_fragment_v1',
            'dip_native_fresh_nonces_v2',
            'dip_native_do_login_v2',
            'dip_native_do_register_v2',
            'dip_native_resend_verification_v2',
        ];
        $self = ['dip_connected_accounts_status'];
        $public = array_values(array_unique(array_map('sanitize_key', (array) apply_filters('dip_public_ajax_actions', $public))));
        $self = array_values(array_unique(array_map('sanitize_key', (array) apply_filters('dip_self_service_ajax_actions', $self))));
        if (in_array($action, $public, true)) return;
        if (in_array($action, $self, true)) {
            if (!is_user_logged_in()) {
                self::audit_denied('ajax_self_service_denied', ['action'=>$action]);
                wp_send_json_error(['message'=>__('Connexion requise.', 'delicat-google-login')], 401);
            }
            return;
        }
        $external = array_values(array_unique(array_map('sanitize_key', (array) apply_filters('dip_external_ajax_actions', []))));
        if (in_array($action, $external, true)) return;
        self::audit_denied('ajax_unclassified_denied', ['action'=>$action]);
        wp_send_json_error(['message'=>__('Action AJAX Delicat Identity non classifiée.', 'delicat-google-login')], 403);
    }

    /**
     * Central policy for all admin-post actions owned by this plugin.
     * Module-level capability and nonce checks remain mandatory.
     */
    public static function action_firewall() {
        if (!is_admin()) return;
        $script = isset($_SERVER['PHP_SELF']) ? basename((string) wp_unslash($_SERVER['PHP_SELF'])) : '';
        if ($script !== 'admin-post.php') return;

        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? ''));
        if ($action === '') return;

        if (class_exists('DIP_Access_Guard')) {
            if (in_array($action, DIP_Access_Guard::admin_only_actions(), true)) {
                if (!DIP_Access_Guard::can_manage_identity()) {
                    self::audit_denied('admin_action_denied', ['action'=>$action]);
                    DIP_Access_Guard::require_admin();
                }
                return;
            }
            if (in_array($action, DIP_Access_Guard::self_service_actions(), true)) {
                if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                    wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), esc_html__('Accès protégé', 'delicat-google-login'), ['response'=>405]);
                }
                if (!DIP_Access_Guard::can_self_service()) {
                    self::audit_denied('self_service_action_denied', ['action'=>$action]);
                    DIP_Access_Guard::require_login();
                }
                return;
            }
            if (in_array($action, DIP_Access_Guard::public_auth_actions(), true)) return;

            // Reserve the dip_* admin-post namespace for explicitly classified
            // actions. Extensions can intentionally opt in through the filter.
            if (strpos($action, 'dip_') === 0) {
                $external = array_map('sanitize_key', (array) apply_filters('dip_external_admin_post_actions', []));
                if (in_array($action, $external, true)) return;
                self::audit_denied('admin_action_unclassified_denied', ['action'=>$action]);
                wp_die(esc_html__('Action Delicat Identity non classifiée.', 'delicat-google-login'), esc_html__('Accès refusé', 'delicat-google-login'), ['response'=>403]);
            }
        }
    }

    /** Generic ownership helper for WooCommerce order objects. */
    public static function can_access_order($order, $user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id || !is_user_logged_in()) return false;
        if (class_exists('DIP_Access_Guard') && DIP_Access_Guard::can_manage_identity()) return true;
        if (!is_object($order) || !method_exists($order, 'get_customer_id')) return false;
        return absint($order->get_customer_id()) === $user_id;
    }

    /** Generic ownership helper for user-scoped records. */
    public static function can_access_user_record($record_user_id, $current_user_id = 0) {
        $record_user_id = absint($record_user_id);
        $current_user_id = absint($current_user_id ?: get_current_user_id());
        if (!$record_user_id || !$current_user_id || !is_user_logged_in()) return false;
        if (class_exists('DIP_Access_Guard') && DIP_Access_Guard::can_manage_identity()) return true;
        return $record_user_id === $current_user_id;
    }

    /** Small deterministic security self-test shown only to administrators. */
    public static function health() {
        $s = self::settings();
        $checks = [
            'https' => ['label'=>'HTTPS', 'ok'=>is_ssl()],
            'strict_rest' => ['label'=>'Pare-feu REST Identity', 'ok'=>($s['strict_rest_firewall'] ?? 'yes') === 'yes'],
            'private_pages' => ['label'=>'Protection des pages privées', 'ok'=>class_exists('DIP_Access_Guard')],
            'admin_capability' => ['label'=>'Contrôles globaux réservés aux administrateurs', 'ok'=>class_exists('DIP_Access_Guard') && DIP_Access_Guard::ADMIN_CAP === 'manage_options'],
            'mobile_binding' => ['label'=>'Session mobile liée appareil + installation', 'ok'=>class_exists('DIP_Mobile_API')],
            'security_log' => ['label'=>'Journal de sécurité', 'ok'=>($s['security_log_enabled'] ?? 'yes') === 'yes'],
            'lockout' => ['label'=>'Protection brute-force', 'ok'=>($s['lockout_enabled'] ?? 'yes') === 'yes'],
            'two_factor_engine' => ['label'=>'Moteur TOTP + récupération', 'ok'=>class_exists('DIP_Two_Factor') && class_exists('DIP_Crypto') && DIP_Crypto::is_available()],
            'passkeys' => ['label'=>'Passkeys / WebAuthn', 'ok'=>class_exists('DIP_Passkeys') && DIP_Passkeys::available()],
            'admin_google_secure' => ['label'=>'Google Secure Mode Administrateur', 'ok'=>class_exists('DIP_Privileged_Social') && DIP_Privileged_Social::secure_mode_enabled()],
            'recent_auth' => ['label'=>'Ré-authentification des actions sensibles', 'ok'=>class_exists('DIP_Reauth')],
        ];
        $ok = 0;
        foreach ($checks as $check) if (!empty($check['ok'])) $ok++;
        return ['score'=>$checks ? (int) round(($ok / count($checks)) * 100) : 0, 'checks'=>$checks];
    }
}
