<?php
defined('ABSPATH') || exit;

/**
 * Signing out belongs to the storefront, not to wp-login.php.
 *
 * Two separate failures put a Delicat customer on a bare WordPress page, and
 * they look the same to the customer, so both are handled here.
 *
 * 1. WHERE LOGOUT LANDS. WordPress' own default destination after wp_logout()
 *    is `wp-login.php?loggedout=true`. Nothing in this plugin ever filtered it,
 *    so every customer who signed out was handed the grey WordPress login form
 *    with "Votre déconnexion a bien été effectuée." - and then, naturally, tried
 *    to sign back in there instead of in the Delicat modal.
 *
 * 2. THE STALE LOGOUT NONCE. wp_logout_url() signs the link with a nonce bound
 *    to the user's session and to a 12-hour tick. That link then sits inside a
 *    page - one the browser restored from history, one LiteSpeed held, one the
 *    customer left open overnight. By the time it is clicked the nonce no longer
 *    verifies, and WordPress answers with its own interstitial: "Vous êtes en
 *    train de vous déconnecter de Delicat Store. Voulez-vous réellement vous
 *    déconnecter ?" - a dead end that looks like a bug, because it is one.
 *
 *    The nonce is still checked first and still wins. Only when it fails does
 *    this ask a second question: did this request come from our own site, as a
 *    top-level navigation, from a signed-in browser? Sec-Fetch-Site is set by
 *    the browser itself and cannot be forged from script, so a cross-site page
 *    cannot satisfy it and neither can an <img> or an <iframe>. That is the
 *    whole threat a logout nonce defends against, and it is still defended -
 *    without the dead end. See DIP_Request::same_origin_navigation().
 */
final class DIP_Logout {

    private static $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;

        add_filter('logout_url', [__CLASS__, 'logout_url'], 20, 2);
        add_filter('logout_redirect', [__CLASS__, 'logout_redirect'], 20, 3);
        /* Fires inside wp-login.php BEFORE check_admin_referer('log-out'). */
        add_action('login_form_logout', [__CLASS__, 'rescue_stale_logout'], 0);
        add_action('login_init', [__CLASS__, 'leave_login_page'], 0);
    }

    /** Where a customer should find themselves after signing out. */
    public static function destination() {
        $url = (string) apply_filters('dip_logout_redirect_url', home_url('/'));
        $url = wp_validate_redirect($url, home_url('/'));
        return add_query_arg('dip_auth_sync', '1', remove_query_arg('dip_auth_sync', $url));
    }

    /**
     * Does this user belong in wp-admin rather than on the storefront?
     *
     * An administrator signing out of the dashboard reasonably expects the
     * WordPress login form back. A customer never does.
     */
    private static function is_staff($user) {
        if (!$user instanceof WP_User || !$user->exists()) return false;
        return user_can($user, 'edit_posts') || user_can($user, 'manage_woocommerce');
    }

    /**
     * Every logout link carries a destination, whoever built it.
     *
     * WooCommerce, the theme and the menu builder each construct their own; a
     * filter on logout_url is the one place all of them pass through.
     */
    public static function logout_url($url, $redirect) {
        if (!is_string($url) || $url === '') return $url;
        if ($redirect !== '' && $redirect !== null) return $url;   // caller was explicit
        if (is_admin()) return $url;
        return add_query_arg('redirect_to', rawurlencode(self::destination()), $url);
    }

    /**
     * @param string  $redirect_to           WordPress' chosen destination.
     * @param string  $requested_redirect_to What the link asked for.
     * @param WP_User $user                  Who is signing out.
     */
    public static function logout_redirect($redirect_to, $requested_redirect_to, $user) {
        if (self::is_staff($user)) return $redirect_to;

        $requested = trim((string) $requested_redirect_to);
        if ($requested !== '') {
            $safe = wp_validate_redirect($requested, '');
            if ($safe !== '' && !self::would_ask_to_sign_in($safe)) {
                return add_query_arg('dip_auth_sync', '1', remove_query_arg('dip_auth_sync', $safe));
            }
        }
        return self::destination();
    }

    /** wp-login.php or wp-admin: WordPress' own screens. */
    private static function is_wordpress_screen($url) {
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        if ($path === '') return false;
        if (preg_match('~/wp-login\.php$~i', untrailingslashit($path))) return true;
        return false !== strpos($path, '/wp-admin');
    }

    /**
     * Would a just-signed-out visitor be asked to sign in again here?
     *
     * WooCommerce's My Account page is the one that is easy to miss. It is a
     * perfectly good storefront page, and it is where WooCommerce's own logout
     * link points - but to somebody who has just signed out it renders a login
     * form. Signing out and immediately being asked to sign in is the whole
     * complaint, whether the form is WordPress' grey one or WooCommerce's.
     */
    private static function would_ask_to_sign_in($url) {
        if (self::is_wordpress_screen($url)) return true;
        if (!function_exists('wc_get_page_permalink')) return false;
        $account = (string) wc_get_page_permalink('myaccount');
        if ($account === '') return false;
        $here = untrailingslashit((string) wp_parse_url((string) $url, PHP_URL_PATH));
        $there = untrailingslashit((string) wp_parse_url($account, PHP_URL_PATH));
        return $there !== '' && $here === $there;
    }

    /**
     * Complete a logout whose nonce a cache outlived.
     *
     * Runs before wp-login.php reaches check_admin_referer('log-out'). When the
     * nonce verifies we do nothing at all and WordPress takes its normal course;
     * this only intervenes on the failure that would otherwise be a dead end.
     */
    public static function rescue_stale_logout() {
        if (!is_user_logged_in()) return;

        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        if ($nonce !== '' && wp_verify_nonce($nonce, 'log-out')) return;   // WordPress will handle it

        if (!DIP_Request::same_origin_navigation()) return;                 // let WordPress ask

        $user = wp_get_current_user();
        if (self::is_staff($user)) return;                                  // the dashboard keeps its own flow

        $requested = isset($_REQUEST['redirect_to']) ? (string) wp_unslash($_REQUEST['redirect_to']) : '';
        $destination = self::logout_redirect(self::destination(), $requested, $user);

        if (class_exists('DIP_Audit')) {
            DIP_Audit::record('logout_nonce_recovered', 'notice', $user->ID, ['reason' => $nonce === '' ? 'missing' : 'stale']);
        }

        nocache_headers();
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        wp_logout();
        wp_safe_redirect($destination);
        exit;
    }

    /**
     * A signed-out customer has no business on wp-login.php.
     *
     * Only the two states this plugin can create are redirected - the
     * "?loggedout=true" landing and a social-login error code. A real login
     * screen, a password reset, a registration, an admin's `redirect_to` into
     * wp-admin and anything with an explicit action are all left alone, so the
     * dashboard stays reachable exactly as before.
     */
    public static function leave_login_page() {
        if (is_user_logged_in()) return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action !== '' && $action !== 'login') return;

        $loggedout = !empty($_GET['loggedout']);
        $error     = isset($_GET['dip_error']) ? sanitize_key(wp_unslash($_GET['dip_error'])) : '';
        if (!$loggedout && $error === '') return;

        /* An administrator sent here from the dashboard must still see the form. */
        $requested = isset($_REQUEST['redirect_to']) ? (string) wp_unslash($_REQUEST['redirect_to']) : '';
        if ($requested !== '' && self::is_wordpress_screen($requested)) return;

        if (!(bool) apply_filters('dip_logout_leave_login_page', true, $action, $error)) return;

        $url = home_url('/');
        if ($error !== '') {
            /* Hand the code to the storefront modal, which has the French text
               for every one of them, instead of showing a WordPress notice. */
            $url = add_query_arg('dip_auth_error', $error, $url) . '#delicat-login';
        } else {
            $url = add_query_arg('dip_auth_sync', '1', $url);
        }

        nocache_headers();
        wp_safe_redirect($url);
        exit;
    }
}
