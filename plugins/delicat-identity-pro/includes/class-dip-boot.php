<?php
defined('ABSPATH') || exit;

/**
 * V10 boot helpers: which parts of Identity a request really needs, and the
 * minified copy of a front-end script.
 *
 * Nothing here decides who is signed in or what a customer may do: the app
 * layer still authenticates exactly as before, it is only compiled on the
 * requests where it can act.
 */
final class DIP_Boot {
    /** Scripts that have a minified twin (assets/<name>.min.js, built from the readable source). */
    const MINIFIED = [
        'account-theme.js', 'auth-frame.js', 'auth-loader.js', 'auth-panel.js', 'auth-shell.js', 'checkout-restore.js',
        'financial-confirmation.js', 'my-account-modern.js', 'passkeys.js', 'security-center.js', 'session-router.js',
    ];

    private static $mobile_started = false;

    /**
     * URL of a front-end asset: the .min.js twin when it ships, the readable
     * source with SCRIPT_DEBUG (as WordPress does for its own scripts).
     */
    public static function asset_url($file) {
        return DIP_URL . 'assets/' . self::asset_name($file);
    }

    /** Local path of the same file (for code printed inline). */
    public static function asset_path($file) {
        return DIP_DIR . 'assets/' . self::asset_name($file);
    }

    private static function asset_name($file) {
        static $names = [];
        $file = (string) $file;
        if (isset($names[$file])) return $names[$file];
        $name = $file;
        if (in_array($file, self::MINIFIED, true) && !(defined('SCRIPT_DEBUG') && SCRIPT_DEBUG)) {
            $min = substr($file, 0, -3) . '.min.js';
            if (is_readable(DIP_DIR . 'assets/' . $min)) $name = $min;
        }
        return $names[$file] = $name;
    }

    /** Requests that keep the plugin's schedules and schema in order: wp-admin screens, WP-Cron, WP-CLI. */
    public static function housekeeping_request() {
        return (is_admin() && !wp_doing_ajax()) || wp_doing_cron() || (defined('WP_CLI') && WP_CLI);
    }

    /**
     * The request carries a Delicat app credential (Authorization or
     * X-Delicat-Identity-Token): the only requests on which
     * DIP_Mobile_API::authenticate_bearer() can return a user.
     */
    public static function app_token_request() {
        if (!empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return true;
        return isset($_SERVER['HTTP_X_DELICAT_IDENTITY_TOKEN']) && trim((string) $_SERVER['HTTP_X_DELICAT_IDENTITY_TOKEN']) !== '';
    }

    /**
     * plugins_loaded 21. The app layer (DIP_Mobile_API, DIP_Mobile_Passkeys:
     * about 150 KB of PHP) starts right away on requests that carry an app
     * credential and on housekeeping requests, as before. Elsewhere it starts
     * with the REST API (rest_api_init), and the three events that can fire on
     * any request load the class only when they fire. Browser page views no
     * longer compile it.
     */
    public static function mobile_layer() {
        if (self::app_token_request() || self::housekeeping_request()) {
            self::start_mobile_layer();
            return;
        }
        add_action('rest_api_init', [__CLASS__, 'start_mobile_layer'], 0);
        add_action('dip_mobile_cleanup', ['DIP_Mobile_API', 'cleanup']);
        add_action('delicat_app_revoke_all_external_sessions', ['DIP_Mobile_API', 'on_app_revoke_all'], 10, 1);
        add_action('delicat_app_erase_customer_data', ['DIP_Mobile_API', 'on_app_erase_customer_data'], 10, 1);
    }

    /** The historical plugins_loaded 21 boot of the app layer (same order, same hooks). */
    public static function start_mobile_layer() {
        if (self::$mobile_started) return;
        self::$mobile_started = true;
        DIP_Mobile_API::init(['DIP_Plugin', 'runtime_settings']);
        DIP_Mobile_Passkeys::init();
    }
}
