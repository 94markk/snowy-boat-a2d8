<?php
/**
 * Plugin Name:       Delicat Top-Up Connector
 * Description:       Sell game top-ups, vouchers and gift cards from your wholesale supplier through WooCommerce. Imports the catalog as variable products, validates player IDs, places signed supplier orders on payment and keeps catalog + order status synchronized automatically (webhooks, background catalog sync and pending-order sweeps).
 * Version:           1.8.1
 * Author:            Delicat Store
 * License:           GPL-2.0-or-later
 * Text Domain:       delicat-topup-connector
 * Requires PHP:      7.4
 * Requires at least: 5.6
 *
 * All supplier API calls are signed server-side (HMAC-SHA256). The API secret
 * never reaches the browser, and nothing customer-facing names the supplier.
 */

if (!defined('ABSPATH')) {
    exit; // No direct access.
}

// The pre-1.8 build lived in a folder named after the supplier. If both copies
// are active at once, keep the first one loaded and ask the admin to remove
// the other instead of fataling on duplicate classes.
if (defined('FTR_VERSION')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Delicat Top-Up Connector:</strong> another copy of this plugin (the old supplier-named folder) is still active. Deactivate and delete the old copy; your settings and products are kept.</p></div>';
    });
    return;
}

define('FTR_VERSION', '1.8.1');
define('FTR_DEFAULT_BASE_URL', 'https://flashtopup.com/api/reseller/v2');
define('FTR_FILE', __FILE__);
define('FTR_DIR', plugin_dir_path(__FILE__));
define('FTR_URL', plugin_dir_url(__FILE__));
define('FTR_OPTION', 'ftr_settings');
define('FTR_PRODUCTS', 'ftr_products');
define('FTR_WEBHOOK_LOG', 'ftr_webhook_log');           // ring-buffered delivery log
define('FTR_AS_GROUP', 'topup-connector');              // Action Scheduler group

require_once FTR_DIR . 'includes/class-ftr-privacy.php';
require_once FTR_DIR . 'includes/class-ftr-api.php';
require_once FTR_DIR . 'includes/class-ftr-sync.php';
require_once FTR_DIR . 'includes/class-ftr-webhook.php';
require_once FTR_DIR . 'includes/class-ftr-admin.php';
require_once FTR_DIR . 'includes/class-ftr-ajax.php';
require_once FTR_DIR . 'includes/class-ftr-shortcode.php';
require_once FTR_DIR . 'includes/class-ftr-woo.php';

// Custom cron intervals must exist before any schedule call, including the
// activation hook, which runs after plugins_loaded has already fired.
add_filter('cron_schedules', array('FTR_Sync', 'cron_schedules'));

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
    $settings = wp_parse_args($existing, $defaults);
    if (empty($settings['webhook_token'])) {
        $settings['webhook_token'] = ftr_generate_token();
    }
    update_option(FTR_OPTION, $settings, false);
    FTR_Sync::schedule();
}
register_activation_hook(__FILE__, 'ftr_activate');

function ftr_deactivate()
{
    FTR_Sync::unschedule();
}
register_deactivation_hook(__FILE__, 'ftr_deactivate');

/** Random, URL-safe token used for the private webhook URL. */
function ftr_generate_token()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(16));
    }
    return strtolower(wp_generate_password(32, false, false));
}

function ftr_default_settings()
{
    return array(
        'api_id'              => '',
        'api_key'             => '',
        'base_url'            => FTR_DEFAULT_BASE_URL,
        'sandbox'             => 0,
        'check_id'            => 1,
        'markup'              => 0,         // % added on top of provider price (display only)
        'conversion_rate'     => 1.0,       // multiplier applied to imported prices (e.g. USD->NPR)
        'conversion_currency' => 'USD',     // currency label resellers display in WC
        'auto_complete'       => 1,         // mark WC orders Completed when webhook says Completed
        'currency'            => 'USD',     // legacy field, kept for back-compat
        'auto_sync'           => 1,         // background catalog sync (prices, stock, packages)
        'sync_interval'       => 5,         // minutes between background catalog syncs
        'webhook_token'       => '',        // secret path segment of the private webhook URL
        'legacy_webhook'      => 1,         // keep accepting the old supplier-named webhook URL
        'public_name'         => '',        // name shown to customers instead of the supplier ('' = site name)
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
    new FTR_Sync();
    FTR_Privacy::init();
    // Cheap (reads the cron option): restores the sync jobs if the cron
    // table was reset by a migration, cache plugin or host.
    FTR_Sync::schedule();
}
add_action('plugins_loaded', 'ftr_boot');

/**
 * Run small, safe upgrade tasks after a plugin update. The repair job only
 * inspects WooCommerce orders that already contain a final supplier item
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
    // can still turn it off later from the plugin's WooCommerce settings tab.
    if (version_compare($installed !== '' ? $installed : '0.0.0', '1.6.8', '<')) {
        $settings = get_option(FTR_OPTION, array());
        if (!is_array($settings)) {
            $settings = array();
        }
        $settings = wp_parse_args($settings, ftr_default_settings());
        $settings['auto_complete'] = 1;
        update_option(FTR_OPTION, $settings, false);
    }

    // v1.8.0: private webhook token + background synchronization schedules.
    $settings = get_option(FTR_OPTION, array());
    if (!is_array($settings)) {
        $settings = array();
    }
    $settings = wp_parse_args($settings, ftr_default_settings());
    $dirty = false;
    if (empty($settings['webhook_token'])) {
        $settings['webhook_token'] = ftr_generate_token();
        $dirty = true;
    }
    // v1.8.1: the supplier's documented base URL is flashtopup.com, not the
    // api. subdomain earlier builds shipped as default (requests to it fail
    // with "Connection failed").
    $host = strtolower((string) wp_parse_url((string) $settings['base_url'], PHP_URL_HOST));
    if ($host === 'api.flashtopup.com' || trim((string) $settings['base_url']) === '') {
        $settings['base_url'] = FTR_DEFAULT_BASE_URL;
        $dirty = true;
    }
    if ($dirty) {
        update_option(FTR_OPTION, $settings, false);
    }
    FTR_Sync::schedule();

    update_option('ftr_installed_version', FTR_VERSION, false);

    $timestamp = time() + 15;
    if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
        if (!as_next_scheduled_action('ftr_repair_completed_orders', array(), FTR_AS_GROUP)) {
            as_schedule_single_action($timestamp, 'ftr_repair_completed_orders', array(), FTR_AS_GROUP);
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
 * Private callback URL for this WordPress install. Paste it into the
 * supplier's reseller panel under "callback_url". It does not contain the
 * supplier's name and is not listed in the public REST API index.
 */
function ftr_webhook_url()
{
    $s = ftr_settings();
    $token = isset($s['webhook_token']) ? preg_replace('/[^a-z0-9]/', '', strtolower((string) $s['webhook_token'])) : '';
    return esc_url_raw(rest_url(FTR_Webhook::ROUTE_NAMESPACE . '/n/' . $token));
}

/** Pre-1.8 callback URL, still accepted while "legacy_webhook" is enabled. */
function ftr_legacy_webhook_url()
{
    return esc_url_raw(rest_url(FTR_Webhook::LEGACY_NAMESPACE . FTR_Webhook::LEGACY_ROUTE));
}

require_once FTR_DIR . 'includes/class-ftr-delicat-app-sync.php';
add_action('plugins_loaded', array('FTR_Delicat_App_Sync','init'), 25);
