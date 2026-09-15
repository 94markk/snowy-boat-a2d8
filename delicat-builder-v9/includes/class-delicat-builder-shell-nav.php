<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Delicat Shell Navigation (RC35).
 *
 * Public storefront pages (home, shop, categories, products, information,
 * search) switch like an app: the next document is fetched (or already in
 * memory from a touch prefetch), only `<main>` and the page-level sheets are
 * swapped under a View Transition, and the header, drawer, bottom bar, footer
 * and chat launcher stay mounted. Cart, checkout, account, wallet and any
 * page with a mutating query string keep full WooCommerce navigations.
 *
 * Page scripts follow WordPress's own contract: the engine executes the next
 * page's `<handle>-js-extra` / `-js-before` inline data and loads any missing
 * `<handle>-js` file in document order (allow-listed handles only), then
 * fires `dsb:content-updated` — the same event the product dock, product
 * fields, product switcher and express sheet already listen to. Anything
 * unexpected falls back to a normal navigation; never a broken page.
 */
final class Delicat_Builder_V9_Shell_Nav {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 9 );
	}

	/**
	 * Complex commerce/account documents deliberately keep full navigations.
	 * Do not download or execute the 26 KB shell swap runtime on a source page
	 * where it is guaranteed to stand down anyway. This also prevents global
	 * click/touch/history listeners from sharing the page with Woo checkout,
	 * variation forms, authentication or wallet state.
	 */
	private static function source_surface_blocked(): bool {
		$blocked = ( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
			|| ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
			|| ( function_exists( 'is_page' ) && is_page( array( 'my-wallet', 'wallet', 'woo-wallet' ) ) );

		return (bool) apply_filters( 'delicat_builder_v9_shell_nav_source_blocked', $blocked );
	}

	public static function enabled(): bool {
		if ( is_admin() || wp_doing_ajax() || self::source_surface_blocked() ) {
			return false;
		}
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) ) && ! Delicat_Builder_V9_Core::is_enabled() ) {
			return false;
		}
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_safe_mode' ) ) && Delicat_Builder_V9_Core::is_safe_mode() ) {
			return false;
		}
		$core = class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'settings' ) ) ? (array) Delicat_Builder_V9_Core::settings() : array();
		if ( isset( $core['shell_navigation'] ) && empty( $core['shell_navigation'] ) ) {
			return false;
		}
		return (bool) apply_filters( 'delicat_builder_v9_shell_nav', true );
	}

	/** Small critical subset of the product surface, warmed only after page idle. */
	private static function warm_list(): array {
		$urls = array();
		$plugin = array(
			'assets/css/native-product.css', 'assets/js/native-product.js',
			'modules/product-fields/assets/css/calc.css', 'modules/product-fields/assets/js/calc-front.js',
		);
		foreach ( $plugin as $rel ) {
			if ( is_file( DELICAT_BUILDER_V9_DIR . $rel ) ) {
				$urls[] = add_query_arg( 'ver', DELICAT_BUILDER_V9_VERSION, DELICAT_BUILDER_V9_URL . $rel );
			}
		}
		return array_values( array_unique( array_map( 'esc_url_raw', $urls ) ) );
	}

	/** WooCommerce product permalink base(s): those documents always full-load. */
	private static function product_paths(): array {
		$paths = array( '/product/', '/produit/' );
		$perma = get_option( 'woocommerce_permalinks' );
		if ( is_array( $perma ) && ! empty( $perma['product_base'] ) ) {
			$base = '/' . trim( (string) $perma['product_base'], '/' ) . '/';
			if ( '/' !== $base ) {
				$paths[] = strtolower( $base );
			}
		}
		return array_values( array_unique( $paths ) );
	}

	public static function assets(): void {
		if ( ! self::enabled() ) {
			return;
		}
		wp_enqueue_script( 'delicat-builder-v9-shell-nav', DELICAT_BUILDER_V9_URL . 'assets/js/shell-nav.js', array(), DELICAT_BUILDER_V9_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'delicat-builder-v9-shell-nav', 'strategy', 'defer' );
		}
		$config = array(
			'blockedPaths'  => (array) apply_filters( 'delicat_builder_v9_shell_nav_blocked_paths', array( '/wp-admin', '/wp-login', '/wp-json', '/cart', '/panier', '/checkout', '/commande', '/my-account', '/mon-compte', '/my-wallet', '/woo-wallet', '/wallet', '/order', '/wc-api', '/xmlrpc', '/feed' ) ),
			'blockedParams' => array( 'add-to-cart', 'remove_item', 'undo_item', 'wc-ajax', '_wpnonce', 'nonce', 'action', 'dip_action', 'logout', 'customer-logout', 'key', 'token', 'code', 'session', 'payment_method', 'dnp_express_debug', 'delicat_product_fragment', 'preview', 'delicat_builder_preview' ),
			'handles'       => '^(jquery|underscore|wp-util|wp-hooks|wp-i18n|wp-polyfill|woocommerce|wc-|js-cookie|delicat|dmc-|ddsw|dbv9|dsb)',
			'warm'          => self::warm_list(),
			'warmLimit'     => 4,
			'productPaths'  => self::product_paths(),
			'transition'    => 220,
		);
		wp_add_inline_script( 'delicat-builder-v9-shell-nav', 'window.DelicatShellNavConfig=' . wp_json_encode( $config ) . ';', 'before' );
	}
}
Delicat_Builder_V9_Shell_Nav::boot();
