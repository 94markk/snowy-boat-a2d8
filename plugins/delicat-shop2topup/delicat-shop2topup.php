<?php
/**
 * Plugin Name: Delicat – Top-Up Fulfillment for WooCommerce
 * Plugin URI:  https://delicastoreha.com/
 * Description: Rebuilt white-label WooCommerce fulfillment integration using the documented SHOP2TOPUP v1 API contract, strict Bearer authentication, signed webhooks, idempotent orders, player validation, grouped variation catalog import, protected manual HTG pricing, balance monitoring, and catalog synchronization.
 * Version:     2.8.3
 * Author:      Delicat Store
 * Text Domain: delicat-shop2topup
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'DST2T_VERSION', '2.8.3' );
define( 'DST2T_FILE', __FILE__ );
define( 'DST2T_PATH', plugin_dir_path( __FILE__ ) );
define( 'DST2T_URL', plugin_dir_url( __FILE__ ) );

$dst2t_files = array(
	'class-dst2t-api-exception.php',
	'class-dst2t-scheduler.php',
	'class-dst2t-decimal.php',
	'class-dst2t-vault.php',
	'class-dst2t-settings.php',
	'class-dst2t-brand.php',
	'class-dst2t-api-client.php',
	'class-dst2t-repository.php',
	'class-dst2t-product.php',
	'class-dst2t-catalog.php',
	'class-dst2t-player-history.php',
	'class-dst2t-fulfillment.php',
	'class-dst2t-balance.php',
	'class-dst2t-sync.php',
	'class-dst2t-privacy.php',
	'class-dst2t-webhook.php',
	'class-dst2t-admin.php',
	'class-dst2t-cli.php',
	'class-dst2t-plugin.php',
);

foreach ( $dst2t_files as $dst2t_file ) {
	require_once DST2T_PATH . 'includes/' . $dst2t_file;
}

unset( $dst2t_files, $dst2t_file );

register_activation_hook( __FILE__, array( 'DST2T_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DST2T_Plugin', 'deactivate' ) );

add_action( 'before_woocommerce_init', array( 'DST2T_Plugin', 'declare_compatibility' ) );
add_action( 'plugins_loaded', array( 'DST2T_Plugin', 'instance' ), 20 );

require_once __DIR__ . '/includes/class-delicat-custom-packages.php';

require_once __DIR__ . '/includes/class-delicat-package-product-editor.php';

require_once __DIR__ . '/includes/class-delicat-package-selection.php';
