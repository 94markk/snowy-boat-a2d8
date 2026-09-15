<?php
/**
 * Runs when a merchant deletes the plugin, not when they deactivate it.
 * Everything V10 has ever written is listed in one place, in Install::options().
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Support/Autoloader.php';
\Delicat\V10\Support\Autoloader::register( __DIR__ . '/src/', 'Delicat\\V10\\' );

if ( ! defined( 'DELICAT_V10_DIR' ) ) {
	define( 'DELICAT_V10_DIR', __DIR__ . '/' );
}
if ( ! defined( 'DELICAT_V10_VERSION' ) ) {
	define( 'DELICAT_V10_VERSION', '10.0.0' );
}

\Delicat\V10\Install::uninstall();
