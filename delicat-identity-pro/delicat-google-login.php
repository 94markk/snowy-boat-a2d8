<?php
/**
 * Plugin Name: Delicat Identity Pro
 * Description: Unified secure identity, authentication, account lifecycle, and synchronized WordPress/WooCommerce transactional email studio.
 * Version:     6.9.18
 * Author: Delicat Store
 * Text Domain: delicat-google-login
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 9.8
 * WC tested up to: 11.0.1
 */

defined('ABSPATH') || exit;

define('DIP_VERSION', '6.9.18');
define('DIP_FILE', __FILE__);
define('DIP_DIR', plugin_dir_path(__FILE__));
define('DIP_URL', plugin_dir_url(__FILE__));

/**
 * 6.9.9 lean class loader.
 *
 * Identity previously parsed every module (including admin/diagnostic helpers)
 * on every storefront request.  Keep the public API identical while loading a
 * class only when WordPress actually invokes one of its hooks/callbacks.
 */
spl_autoload_register(static function ($class) {
    if (!is_string($class) || strpos($class, 'DIP_') !== 0) return;

    // Historical provider interface/implementations intentionally share one file.
    if (in_array($class, ['DIP_Provider', 'DIP_Provider_Registry', 'DIP_Google_Provider', 'DIP_Microsoft_Provider'], true)) {
        $provider_path = DIP_DIR . 'includes/class-dip-provider.php';
        if (is_readable($provider_path)) require_once $provider_path;
        return;
    }

    $slug = strtolower(str_replace('_', '-', substr($class, 4)));
    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/D', $slug)) return;
    $path = DIP_DIR . 'includes/class-dip-' . $slug . '.php';
    if (is_readable($path)) require_once $path;
});

register_activation_hook(__FILE__, function () { DIP_Plugin::activate(); DIP_WooCommerce::activate(); DIP_Foundation::activate(); DIP_App_Sync_V2::install(); DIP_Customer_Dashboard::activate(); DIP_Analytics::install(); DIP_Email_Studio_Bridge::install(); DIP_Passwordless_Registration::install(); DIP_UI_Stability::install(); DIP_Stability_Patch::install(); DIP_Headless_Google::activate(); });
add_action('before_woocommerce_init', static function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

register_deactivation_hook(__FILE__, ['DIP_Plugin', 'deactivate']);
register_deactivation_hook(__FILE__, ['DIP_Headless_Google', 'deactivate']);
add_action('plugins_loaded', ['DIP_Plugin', 'instance']);
add_action('profile_update', ['DIP_Account_Sync', 'profile_email_changed'], 5, 2);
add_action('after_password_reset', ['DIP_Account_Sync', 'password_reset'], 5, 2);
add_action('user_register', ['DIP_Account_Sync', 'external_user_registered'], 20, 2);
add_filter('woocommerce_registration_auth_new_customer', ['DIP_Account_Sync', 'woocommerce_registration_auth'], 20, 2);
add_filter('send_auth_cookies', ['DIP_Account_Sync', 'filter_auth_cookies'], 20, 6);
add_filter('wp_is_application_passwords_available_for_user', ['DIP_Account_Sync', 'filter_application_passwords'], 20, 2);
add_action('plugins_loaded', ['DIP_Access_Guard', 'init'], 16);
add_action('plugins_loaded', ['DIP_Reauth', 'init'], 16);
add_action('plugins_loaded', ['DIP_Two_Factor', 'init'], 17);
add_action('plugins_loaded', ['DIP_Passkeys', 'init'], 17);
add_action('plugins_loaded', ['DIP_Privileged_Social', 'init'], 17);
add_action('plugins_loaded', ['DIP_Security_Architecture', 'init'], 16);
add_action('plugins_loaded', ['DIP_Connected_Accounts', 'init'], 18);
add_action('plugins_loaded', ['DIP_Native_Auth', 'init'], 18);
// Logout belongs to the storefront; wp-login.php is never a customer destination.
add_action('plugins_loaded', ['DIP_Logout', 'init'], 18);
add_action('plugins_loaded', ['DIP_Visual_Builder_Pro', 'init'], 18);
add_action('plugins_loaded', ['DIP_Security_Center', 'init'], 18);
add_action('plugins_loaded', ['DIP_WooCommerce_Pro', 'init'], 24);
add_action('plugins_loaded', ['DIP_App_Sync_V2', 'init'], 25);
add_action('plugins_loaded', ['DIP_Customer_Dashboard', 'init'], 26);
add_action('plugins_loaded', ['DIP_My_Account_Modern', 'init'], 27);
add_action('plugins_loaded', ['DIP_Analytics', 'init'], 27);
add_action('plugins_loaded', ['DIP_Email_Studio_Bridge', 'init'], 28);
add_action('plugins_loaded', ['DIP_Email_Hub', 'init'], 29);
add_action('plugins_loaded', ['DIP_Passwordless_Registration', 'init'], 30);
add_action('plugins_loaded', ['DIP_Stability_Patch', 'init'], 32);

// Admin-only control-plane modules stay completely off public HTML/AJAX boots.
if (is_admin() && !wp_doing_ajax()) {
    add_action('plugins_loaded', ['DIP_Foundation', 'init'], 17);
    add_action('plugins_loaded', ['DIP_Provider_Manager', 'init'], 18);
    add_action('plugins_loaded', ['DIP_UI_Stability', 'init'], 31);
}
add_action('plugins_loaded', ['DIP_SDK', 'init'], 18);
add_action('plugins_loaded', ['DIP_WooCommerce', 'init'], 20);
add_action('plugins_loaded', function () { DIP_Nextend_Parity::init(function () { return wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults()); }); }, 22);
add_action('plugins_loaded', function () { DIP_Performance::init(function () { return wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults()); }); }, 19);
add_action('plugins_loaded', function () { DIP_Mobile_API::init(function () { return wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults()); }); }, 21);

add_action('plugins_loaded', array('DIP_Delicat_App_Sync','init'), 30);
