<?php
/**
 * Plugin Name: Delicat Storefront V10 Pro
 * Plugin URI: https://delicastoreha.com/
 * Description: A storefront that behaves like an installed app. Built on the platform's own navigation primitives — cross-document view transitions, speculative prerendering and a server-rendered app shell — so page changes are instant without a JavaScript router. WooCommerce stays authoritative for every cart, order, stock and payment decision.
 * Version: 10.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Delicat Store
 * Text Domain: delicat-v10
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Why this file is short
 * -----------------------------------------------------------------------------
 * V9's bootstrap grew to 1,400 lines because it decided, inline, which of 85
 * classes to parse for every shape of request — and the lists drifted: entries
 * that named handles which no longer existed, modules loaded twice, a search
 * index that only ever loaded on admin screens so the storefront shipped an
 * empty one.
 *
 * V10 inverts that. A module declares the contexts it wants; the kernel asks
 * the request what it is, once, and loads the matching set. Nothing here needs
 * editing to add a feature, so nothing here can drift.
 */

define( 'DELICAT_V10_VERSION', '10.0.0' );
define( 'DELICAT_V10_FILE', __FILE__ );
define( 'DELICAT_V10_DIR', plugin_dir_path( __FILE__ ) );
define( 'DELICAT_V10_URL', plugin_dir_url( __FILE__ ) );
define( 'DELICAT_V10_MIN_PHP', '7.4' );

require_once DELICAT_V10_DIR . 'src/Support/Autoloader.php';
require_once DELICAT_V10_DIR . 'src/Support/Guard.php';

\Delicat\V10\Support\Autoloader::register( DELICAT_V10_DIR . 'src/', 'Delicat\\V10\\' );

/*
 * One try/catch around the entire boot. A Throwable anywhere below records
 * itself and disables V10 for subsequent requests rather than white-screening
 * the store: WordPress and WooCommerce keep serving, unstyled but working.
 * That is the whole safety model, in one place, instead of V9's eleven.
 */
\Delicat\V10\Support\Guard::run(
	'boot',
	static function () {
		\Delicat\V10\Kernel::instance()->boot();
	}
);

register_activation_hook( __FILE__, static function () {
	\Delicat\V10\Support\Guard::run( 'activate', static function () {
		\Delicat\V10\Install::activate();
	} );
} );

register_deactivation_hook( __FILE__, static function () {
	\Delicat\V10\Support\Guard::run( 'deactivate', static function () {
		\Delicat\V10\Install::deactivate();
	} );
} );
