<?php
/**
 * Delicat Store — bootstrap.
 *
 * Every feature lives in its own file under inc/. Files that need WooCommerce
 * are only loaded when WooCommerce is active, so the theme never fatals on a
 * site where the plugin is missing or temporarily disabled.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

define( 'DS_VERSION', '1.0.0' );
define( 'DS_DIR', trailingslashit( get_template_directory() ) );
define( 'DS_URI', trailingslashit( get_template_directory_uri() ) );

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>Delicat Store nécessite PHP 7.4 ou plus récent. Changez la version de PHP dans hPanel (Hostinger) &gt; Avancé &gt; Configuration PHP.</p></div>';
		}
	);
	return;
}

require DS_DIR . 'inc/helpers.php';
require DS_DIR . 'inc/icons.php';
require DS_DIR . 'inc/setup.php';
require DS_DIR . 'inc/assets.php';
require DS_DIR . 'inc/customizer.php';
require DS_DIR . 'inc/legal.php';
require DS_DIR . 'inc/pwa.php';
require DS_DIR . 'inc/install.php';
require DS_DIR . 'inc/admin.php';

if ( ds_is_woo() ) {
	require DS_DIR . 'inc/badges.php';
	require DS_DIR . 'inc/product-fields.php';
	require DS_DIR . 'inc/woocommerce.php';
	require DS_DIR . 'inc/wallet.php';
	require DS_DIR . 'inc/bon-kliyan.php';
	require DS_DIR . 'inc/homepage.php';
} else {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'install_plugins' ) ) {
				return;
			}
			$url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=woocommerce' ), 'install-plugin_woocommerce' );
			echo '<div class="notice notice-warning"><p><strong>Delicat Store</strong> : WooCommerce n\'est pas actif. La boutique, le panier et le paiement ont besoin de WooCommerce. <a href="' . esc_url( $url ) . '">Installer WooCommerce</a></p></div>';
		}
	);
}
