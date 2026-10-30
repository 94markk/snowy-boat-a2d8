<?php
/**
 * Plugin Name:       FlashTopup Reseller
 * Plugin URI:        https://flashtopup.com/reseller
 * Description:       Sell FlashTopup game top-ups, vouchers and gift cards on your WordPress site. Imports the catalog as native WooCommerce variable products with each package as a variation, validates player IDs (check ID), places orders through the signed FlashTopup Reseller API, and receives order.updated webhooks back so WooCommerce order status stays in sync with the upstream provider.
 * Version:           1.7.1
 * Author:            FlashTopup
 * Author URI:        https://flashtopup.com
 * License:           GPL-2.0-or-later
 * Text Domain:       flashtopup-reseller
 * Requires PHP:      7.4
 * Requires at least: 5.6
 *
 * All API calls are signed server-side (HMAC-SHA256). The API secret never
 * reaches the browser. Incoming webhooks are verified with HMAC over a
 * canonical request + single-use nonce + +/- 5 minute timestamp window.
 */

if (!defined('ABSPATH')) {
    exit; // No direct access.
}

define('FTR_VERSION', '1.7.1');
define('FTR_FILE', __FILE__);
define('FTR_DIR', plugin_dir_path(__FILE__));
define('FTR_URL', plugin_dir_url(__FILE__));
define('FTR_OPTION', 'ftr_settings');
define('FTR_PRODUCTS', 'ftr_products');
define('FTR_WEBHOOK_LOG', 'ftr_webhook_log');           // ring-buffered delivery log

require_once FTR_DIR . 'includes/class-ftr-api.php';
require_once FTR_DIR . 'includes/class-ftr-webhook.php';
require_once FTR_DIR . 'includes/class-ftr-admin.php';
require_once FTR_DIR . 'includes/class-ftr-ajax.php';
require_once FTR_DIR . 'includes/class-ftr-shortcode.php';
require_once FTR_DIR . 'includes/class-ftr-woo.php';

/** Default settings + first-install webhook secret. */
function ftr_activate()
{
    $existing = get_option(FTR_OPTION, array());
    if (!is_array($existing)) {
        $existing = array();
    }
    $defaults = ftr_default_settings();
    // Always preserve user-set keys; only fill in missing ones.
    // update_option also repairs an existing but incomplete settings row.
    update_option(FTR_OPTION, wp_parse_args($existing, $defaults), false);
}
register_activation_hook(__FILE__, 'ftr_activate');

function ftr_default_settings()
{
    return array(
        'api_id'              => '',
        'api_key'             => '',
        'base_url'            => 'https://api.flashtopup.com/api/reseller/v2',
        'sandbox'             => 0,
        'check_id'            => 1,
        'markup'              => 0,         // % added on top of provider price (display only)
        'conversion_rate'     => 1.0,       // multiplier applied to imported prices (e.g. USD->NPR)
        'conversion_currency' => 'USD',     // currency label resellers display in WC
        'auto_complete'       => 1,         // mark WC orders Completed when webhook says Completed
        'currency'            => 'USD',     // legacy field, kept for back-compat
    );
}

/** Boot. */
function ftr_boot()
{
    new FTR_Admin();
    new FTR_Ajax();
    new FTR_Shortcode();
    new FTR_Woo();
    new FTR_Webhook();
}
add_action('plugins_loaded', 'ftr_boot');

/**
 * Run small, safe upgrade tasks after a plugin update. The repair job only
 * inspects WooCommerce orders that already contain a final FlashTopup item
 * status, then lets FTR_Woo::maybe_complete_order() apply all normal safety
 * checks before changing the WooCommerce order status.
 */
function ftr_maybe_upgrade()
{
    $installed = (string) get_option('ftr_installed_version', '');
    if ($installed === FTR_VERSION) {
        return;
    }

    // v1.6.8 makes provider-confirmed order completion the safe default.
    // This one-time migration also enables it on existing installations so
    // already-fulfilled orders do not remain stuck in Processing. The admin
    // can still turn it off later from FlashTopup > WooCommerce settings.
    if (version_compare($installed !== '' ? $installed : '0.0.0', '1.6.8', '<')) {
        $settings = get_option(FTR_OPTION, array());
        if (!is_array($settings)) {
            $settings = array();
        }
        $settings = wp_parse_args($settings, ftr_default_settings());
        $settings['auto_complete'] = 1;
        update_option(FTR_OPTION, $settings, false);
    }

    update_option('ftr_installed_version', FTR_VERSION, false);

    $timestamp = time() + 15;
    if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
        if (!as_next_scheduled_action('ftr_repair_completed_orders', array(), 'flashtopup-reseller')) {
            as_schedule_single_action($timestamp, 'ftr_repair_completed_orders', array(), 'flashtopup-reseller');
        }
    } elseif (!wp_next_scheduled('ftr_repair_completed_orders')) {
        wp_schedule_single_event($timestamp, 'ftr_repair_completed_orders');
    }
}
add_action('plugins_loaded', 'ftr_maybe_upgrade', 30);

/** Settings / Import action links on the Plugins page. */
function ftr_action_links($links)
{
    $custom = array(
        '<a href="' . esc_url(admin_url('admin.php?page=ftr-settings')) . '">Settings</a>',
        '<a href="' . esc_url(admin_url('admin.php?page=ftr-import')) . '">Import</a>',
    );
    return array_merge($custom, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ftr_action_links');

/** Convenience accessor for settings. */
function ftr_settings()
{
    return wp_parse_args(get_option(FTR_OPTION, array()), ftr_default_settings());
}

/**
 * Public callback URL for this WordPress install. Paste into FlashTopup's
 * Reseller panel under "callback_url" so order.updated webhooks land here.
 */
function ftr_webhook_url()
{
    return esc_url_raw(rest_url('flashtopup/v1/callback'));
}

require_once FTR_DIR . 'includes/class-ftr-delicat-app-sync.php';
add_action('plugins_loaded', array('FTR_Delicat_App_Sync','init'), 25);
