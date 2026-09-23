<?php
defined('ABSPATH') || exit;

/** Storefront transitions; WordPress remains the session authority. */
final class DIP_Session_Router {
    public static function init() {
        add_filter('logout_redirect', [__CLASS__, 'logout_destination'], PHP_INT_MAX);
        add_filter('woocommerce_logout_default_redirect_url', [__CLASS__, 'logout_destination'], PHP_INT_MAX);
        add_filter('login_redirect', [__CLASS__, 'login_destination'], PHP_INT_MAX, 3);
        add_filter('woocommerce_login_redirect', [__CLASS__, 'wc_login_destination'], PHP_INT_MAX, 2);
        add_action('login_init', [__CLASS__, 'login_screen'], 1);
        add_action('wp_login_failed', [__CLASS__, 'login_failed'], PHP_INT_MAX, 2);
        add_action('template_redirect', [__CLASS__, 'storefront_logout'], -10);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 25);
        add_action('wp_ajax_dip_session_status', [__CLASS__, 'status']);
        add_action('wp_ajax_nopriv_dip_session_status', [__CLASS__, 'status']);
        add_action('wp_ajax_dip_session_logout', [__CLASS__, 'logout']);
        add_action('wp_ajax_nopriv_dip_session_logout', [__CLASS__, 'logout']);
        add_action('wp_logout', [__CLASS__, 'clear_return_cookie']);
    }

    public static function destination($url, $fallback = '') {
        $fallback = $fallback ? self::destination($fallback) : home_url('/');
        if (!is_string($url) || strlen($url) > 4096) return $fallback;
        $url = trim($url);
        if ($url !== '' && $url[0] === '/' && substr($url, 0, 2) !== '//') $url = home_url($url);
        $url = wp_validate_redirect($url, '');
        if (!$url) return $fallback;
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!$parts || !empty($parts['user']) || !empty($parts['pass']) ||
            strtolower($parts['host'] ?? '') !== strtolower($home['host'] ?? '') ||
            strtolower($parts['scheme'] ?? '') !== strtolower($home['scheme'] ?? '') ||
            (int)($parts['port'] ?? 0) !== (int)($home['port'] ?? 0)) return $fallback;
        $path = rawurldecode($parts['path'] ?? '/');
        parse_str($parts['query'] ?? '', $query);
        $action = is_string($query['action'] ?? '') ? strtolower($query['action'] ?? '') : '';
        $wc_logout = trim((string)get_option('woocommerce_logout_endpoint', 'customer-logout'), '/');
        if (preg_match('~(?:^|/)(?:wp-admin|wp-login\.php|customer-logout|lost-password)(?:/|$)~i', $path) ||
            ($wc_logout !== '' && preg_match('~(?:^|/)' . preg_quote($wc_logout, '~') . '(?:/|$)~i', $path)) ||
            in_array($action, ['login','logout','lostpassword','retrievepassword','resetpass','rp','register','reauth'], true) ||
            array_intersect(['dip_logout_confirm','dip_logout','loginSocial','dip_auth_error','dip_verified','key','dip_2fa_challenge'], array_keys($query))) return $fallback;
        return $url;
    }

    /**
     * Value for the dip_auth_sync marker. Unique per sign-in/out so that an
     * edge cache ignoring no-cache can never share one landing page between
     * visitors; readers only test that the parameter is present.
     */
    public static function sync_marker() {
        return wp_generate_password(10, false, false);
    }

    public static function logout_destination($unused = '') {
        return add_query_arg('dip_auth_sync', self::sync_marker(), home_url('/'));
    }

    public static function login_destination($redirect, $requested, $user) {
        if (!($user instanceof WP_User) || is_wp_error($user)) return $redirect;
        // Preserve intentional dashboard access for staff.
        if (user_can($user, 'edit_posts') || user_can($user, 'manage_woocommerce') || user_can($user, 'manage_options')) return $redirect;
        $s = wp_parse_args((array)get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
        return add_query_arg('dip_auth_sync', self::sync_marker(), self::destination($redirect, $s['native_login_redirect'] ?? '/my-wallet/'));
    }
    public static function wc_login_destination($redirect, $user) { return self::login_destination($redirect, '', $user); }

    private static function private_response() {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
    }
    private static function require_post() {
        self::private_response();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_send_json_error(['message'=>'Méthode non autorisée.'], 405);
        // A nonce is still mandatory for logout. Reject explicit foreign origins too.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $home = wp_parse_url(home_url('/'));
        $expected = ($home['scheme'] ?? 'https') . '://' . ($home['host'] ?? '') . (isset($home['port']) ? ':' . $home['port'] : '');
        if (($origin !== '' && $origin !== $expected) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            wp_send_json_error(['message'=>'Origine refusée.'], 403);
        }
    }
    public static function status() {
        self::require_post();
        wp_send_json_success(['loggedIn'=>is_user_logged_in(), 'logoutNonce'=>is_user_logged_in() ? wp_create_nonce('dip_session_logout') : '', 'redirect'=>self::logout_destination()]);
    }
    public static function logout() {
        self::require_post();
        if (is_user_logged_in()) {
            if (!check_ajax_referer('dip_session_logout', 'nonce', false)) wp_send_json_error(['code'=>'bad_nonce','message'=>'Session expirée. Réessayez.'], 403);
            wp_logout();
        }
        wp_send_json_success(['redirect'=>self::logout_destination()]);
    }
    public static function clear_return_cookie() {
        if (!headers_sent()) setcookie('dip_return_url', '', ['expires'=>time()-HOUR_IN_SECONDS, 'path'=>COOKIEPATH ?: '/', 'domain'=>COOKIE_DOMAIN ?: '', 'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Lax']);
        unset($_COOKIE['dip_return_url']);
    }
    private static function process_logout($nonce_action) {
        self::private_response();
        $nonce = isset($_REQUEST['_wpnonce']) && is_string($_REQUEST['_wpnonce']) ? wp_unslash($_REQUEST['_wpnonce']) : '';
        if (!is_user_logged_in()) $target = self::logout_destination();
        elseif ($nonce && wp_verify_nonce($nonce, $nonce_action)) {
            wp_logout();
            $target = self::logout_destination();
        } else {
            // Stale links never authorize logout. Show a fresh, explicit confirmation.
            $target = add_query_arg('dip_logout_confirm', '1', home_url('/'));
        }
        wp_safe_redirect($target); exit;
    }
    public static function storefront_logout() {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('customer-logout')) self::process_logout('customer-logout');
        if (isset($_GET['dip_logout_confirm'])) {
            self::private_response();
            if (is_user_logged_in()) {
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') self::process_logout('log-out');
                header('Content-Type: text/html; charset=UTF-8');
                header('X-Frame-Options: DENY');
                header('Referrer-Policy: no-referrer');
                echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Déconnexion — Delicat Store</title><style>body{margin:0;background:#f3f5fc;font:16px system-ui;color:#171b32;min-height:100vh;display:grid;place-items:center}main{box-sizing:border-box;width:min(420px,calc(100% - 32px));padding:28px;background:white;border:1px solid #dfe4ef;border-radius:20px}h1{font-size:22px}button,a{display:block;box-sizing:border-box;width:100%;padding:14px;text-align:center;border:0;border-radius:12px;font:inherit}button{background:#4a55ee;color:white;cursor:pointer}a{color:#3458c7;text-decoration:none;margin-top:8px}</style><main><strong>DELICAT STORE</strong><h1>Confirmer la déconnexion</h1><p>Votre ancien lien a expiré. Confirmez pour terminer cette session.</p><form method="post" action="' . esc_url(add_query_arg('dip_logout_confirm', '1', home_url('/'))) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('log-out')) . '"><button type="submit">Se déconnecter</button></form><a href="' . esc_url(home_url('/')) . '">Rester connecté</a></main></html>';
                exit;
            }
            wp_safe_redirect(self::logout_destination()); exit;
        }
    }
    public static function login_screen() {
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? $_REQUEST['action'] : 'login';
        if ($action === 'logout') self::process_logout('log-out');
        if (isset($_GET['loggedout']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            wp_safe_redirect(self::logout_destination()); exit;
        }
        // OAuth, reset, 2FA, interim and deliberate wp-admin sign-in remain native.
    }
    public static function login_failed($username, $error = null) {
        if (($GLOBALS['pagenow'] ?? '') !== 'wp-login.php' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        if (isset($_REQUEST['interim-login']) || isset($_REQUEST['dip_admin_login']) || isset($_REQUEST['loginSocial'])) return;
        $action = $_REQUEST['action'] ?? 'login';
        if ($action !== 'login') return;
        $user = get_user_by('login', $username);
        if (!$user && is_email($username)) $user = get_user_by('email', $username);
        if ($user && (user_can($user, 'manage_options') || user_can($user, 'edit_posts') || user_can($user, 'manage_woocommerce'))) return;
        self::private_response();
        // No submitted identifiers, passwords, provider tokens or raw errors in URLs.
        wp_safe_redirect(add_query_arg('dip_auth_error', 'invalid_credentials', home_url('/')) . '#delicat-login'); exit;
    }
    public static function assets() {
        if (is_admin()) return;
        wp_enqueue_script('dip-session-router', DIP_URL . 'assets/session-router.js', [], DIP_VERSION, true);
        wp_localize_script('dip-session-router', 'DIPSession', ['ajaxUrl'=>wp_make_link_relative(admin_url('admin-ajax.php')), 'home'=>home_url('/'), 'logoutEndpoint'=>(string)get_option('woocommerce_logout_endpoint', 'customer-logout')]);
    }
}
