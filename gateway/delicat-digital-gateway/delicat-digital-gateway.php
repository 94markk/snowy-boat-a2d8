<?php
/**
 * Plugin Name: Delicat Digital Gateway
 * Description: Security-hardened WooCommerce digital fulfillment gateway with immutable idempotent fulfillment snapshots, signed real-time webhook synchronization, protected HTG pricing, dynamic UID/account-name validation, encrypted digital delivery, Catalog Studio, Mes Achats synchronization, and a wallet-funded Delicat Reseller API.
 * Version: 4.15.2
 * Author: Delicat Store
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: delicat-fazercards
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DFR_VERSION', '4.15.2');
define('DFR_FILE', __FILE__);
define('DFR_DIR', plugin_dir_path(__FILE__));
define('DFR_URL', plugin_dir_url(__FILE__));

require_once DFR_DIR . 'includes/class-dfr-crypto.php';
require_once DFR_DIR . 'includes/class-dfr-api.php';
require_once DFR_DIR . 'includes/class-dfr-plugin.php';
require_once DFR_DIR . 'includes/class-dfr-catalog.php';
require_once DFR_DIR . 'includes/class-dfr-reseller-api.php';


add_action('before_woocommerce_init', static function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

register_activation_hook(__FILE__, array('DFR_Plugin', 'activate'));
register_activation_hook(__FILE__, array('DFR_Reseller_API', 'activate'));
register_deactivation_hook(__FILE__, array('DFR_Plugin', 'deactivate'));
register_deactivation_hook(__FILE__, array('DFR_Reseller_API', 'deactivate'));

add_action('plugins_loaded', static function () {
    DFR_Plugin::instance()->boot();
    DFR_Reseller_API::instance()->boot();
});
