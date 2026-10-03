<?php
/**
 * Delicat Builder V9 Pro — Control panel.
 *
 * Every Pro layer has its own switch. If a regression appears on the live
 * store, the layer responsible can be turned off in one click without
 * disabling Pro or rolling back the plugin.
 *
 * Requires PHP 8.3 (gated in the main plugin file).
 *
 * @package Delicat_Builder_V9_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'DBP_Admin', false ) ) {
	return;
}

final class DBP_Admin {

	const SLUG  = 'delicat-builder-pro';
	const NONCE = 'dbp_admin_save';

	/**
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_post_dbp_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_dbp_purge', array( __CLASS__, 'purge' ) );
	}

	/**
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'Delicat Pro', 'delicat-builder-v9' ),
			__( 'Delicat Pro', 'delicat-builder-v9' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-performance',
			3
		);
	}

	/**
	 * Toggle definitions: key => label, help.
	 *
	 * @return array
	 */
	private static function toggles() {
		return array(
			'enabled'        => array(
				__( 'Pro kernel', 'delicat-builder-v9' ),
				__( 'Master switch. Off returns the storefront to the V9 layers exactly as they were.', 'delicat-builder-v9' ),
			),
			'navigation'     => array(
				__( 'Instant navigation', 'delicat-builder-v9' ),
				__( 'One engine handles link taps, prefetching and page swaps. Cart, checkout and account always load normally.', 'delicat-builder-v9' ),
			),
			'assets'         => array(
				__( 'Asset pipeline', 'delicat-builder-v9' ),
				__( 'Master switch for everything below that touches CSS and JavaScript.', 'delicat-builder-v9' ),
			),
			'route_assets'   => array(
				__( 'Route-scoped bundles', 'delicat-builder-v9' ),
				__( 'Product, archive and purchase bundles stop shipping to pages that do not use them. Roughly 108 KB off every non-product page.', 'delicat-builder-v9' ),
			),
			'critical_css'   => array(
				__( 'Inline shell CSS', 'delicat-builder-v9' ),
				__( 'Reserves the header and app dock height before the stylesheets arrive, which removes the late layout jump. Additive — it never replaces a stylesheet.', 'delicat-builder-v9' ),
			),
			'type_system'    => array(
				__( 'One type and colour scale', 'delicat-builder-v9' ),
				__( 'Collapses 275 font sizes, 925 colours and 143 corner radii onto one scale, and puts every surface on one typeface. Off returns each module to its own sizes.', 'delicat-builder-v9' ),
			),
			'defer_js'       => array(
				__( 'Defer scripts (leave off)', 'delicat-builder-v9' ),
				__( 'Defers only scripts with no dependencies. WooCommerce and V9 print inline jQuery blocks that run before a deferred file, so this is off by default. Turn it on one deploy at a time and test checkout.', 'delicat-builder-v9' ),
			),
			'state'          => array(
				__( 'Single session store', 'delicat-builder-v9' ),
				__( 'Cart count, wallet balance and alerts come from one request, and cached pages get fresh nonces.', 'delicat-builder-v9' ),
			),
			'security'       => array(
				__( 'Security headers', 'delicat-builder-v9' ),
				__( 'Frame, referrer, sniffing and permissions policies, plus author-enumeration and REST user-listing blocks.', 'delicat-builder-v9' ),
			),
			'prefetch'       => array(
				__( 'Prefetch', 'delicat-builder-v9' ),
				__( 'Loads the next page on tap intent. Turns itself off when the visitor is on Save-Data.', 'delicat-builder-v9' ),
			),
			'transitions'    => array(
				__( 'Page transitions', 'delicat-builder-v9' ),
				__( 'Animated screen changes where the browser supports them. Respects reduced-motion.', 'delicat-builder-v9' ),
			),
			'low_data_mode'  => array(
				__( 'Slow-connection mode', 'delicat-builder-v9' ),
				__( 'On 2G/3G and Save-Data, drops shadows, autoplay and animation so the content arrives first.', 'delicat-builder-v9' ),
			),
			'legacy_nav_off' => array(
				__( 'Retire the old navigation layers', 'delicat-builder-v9' ),
				__( 'Removes shell-nav, prefetch and navigation scripts. Leave this on — running them beside Pro recreates the original conflict.', 'delicat-builder-v9' ),
			),
			'legacy_slim_off' => array(
				__( 'Retire Front Slim', 'delicat-builder-v9' ),
				__( 'Stops the old dequeue pass from fighting the Pro pipeline for ownership of the same handles.', 'delicat-builder-v9' ),
			),
			'diagnostics'    => array(
				__( 'Diagnostics', 'delicat-builder-v9' ),
				__( 'Logs navigation timings to the browser console. Development only.', 'delicat-builder-v9' ),
			),
		);
	}

	/**
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'delicat-builder-v9' ) );
		}

		$settings = DBP_Kernel::settings();
		$toggles  = self::toggles();
		$saved    = isset( $_GET['dbp'] ) ? sanitize_key( wp_unslash( (string) $_GET['dbp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Delicat Builder V9 Pro', 'delicat-builder-v9' ) . '</h1>';
		echo '<p>' . esc_html__( 'Version', 'delicat-builder-v9' ) . ': <code>' . esc_html( DELICAT_BUILDER_V9_VERSION ) . '</code> &middot; '
			. esc_html__( 'Cache generation', 'delicat-builder-v9' ) . ': <code>' . esc_html( DBP_Kernel::generation() ) . '</code></p>';

		if ( 'saved' === $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved and caches purged.', 'delicat-builder-v9' ) . '</p></div>';
		} elseif ( 'purged' === $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Caches purged.', 'delicat-builder-v9' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="dbp_save">';
		wp_nonce_field( self::NONCE );

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $toggles as $key => $meta ) {
			$checked = ! empty( $settings[ $key ] ) ? ' checked' : '';
			echo '<tr><th scope="row">' . esc_html( $meta[0] ) . '</th><td>';
			echo '<label><input type="checkbox" name="dbp[' . esc_attr( $key ) . ']" value="1"' . esc_attr( $checked ) . '> ';
			echo esc_html__( 'Active', 'delicat-builder-v9' ) . '</label>';
			echo '<p class="description">' . esc_html( $meta[1] ) . '</p>';
			echo '</td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'Cache de navigation', 'delicat-builder-v9' ) . '</th><td>';
		echo esc_html__( 'Les pages utilisent le cache HTTP et LiteSpeed. Aucun document client n’est conservé en mémoire JavaScript.', 'delicat-builder-v9' );
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Prefetch budget', 'delicat-builder-v9' ) . '</th><td>';
		echo '<input type="number" min="0" max="20" name="dbp[prefetch_budget]" value="' . esc_attr( (string) $settings['prefetch_budget'] ) . '"> ';
		echo esc_html__( 'pages per screen', 'delicat-builder-v9' );
		echo '<p class="description">' . esc_html__( 'Préchargement des produits uniquement, plafonné à trois documents. Désactivé sur connexion lente.', 'delicat-builder-v9' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save and purge caches', 'delicat-builder-v9' ) );
		echo '</form>';

		echo '<hr>';
		echo '<h2>' . esc_html__( 'Recovery', 'delicat-builder-v9' ) . '</h2>';
		echo '<p>' . esc_html__( 'Add ?dbp=off to any URL to load that page with every Pro layer inert. Nothing is saved and nothing is cached, so it always works even if a setting on this screen is wrong.', 'delicat-builder-v9' ) . '</p>';
		echo '<p><code>' . esc_html( home_url( '/?dbp=off' ) ) . '</code></p>';

		echo '<hr>';
		echo '<h2>' . esc_html__( 'Deployment', 'delicat-builder-v9' ) . '</h2>';
		echo '<p>' . esc_html__( 'LiteSpeed keeps one combined stylesheet per site. A new build does not reach a browser until that artefact is purged.', 'delicat-builder-v9' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="dbp_purge">';
		wp_nonce_field( self::NONCE );
		submit_button( __( 'Purge every cache now', 'delicat-builder-v9' ), 'secondary' );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * @return void
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'delicat-builder-v9' ) );
		}
		check_admin_referer( self::NONCE );

		$input    = isset( $_POST['dbp'] ) && is_array( $_POST['dbp'] ) ? wp_unslash( $_POST['dbp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per key below.
		$defaults = DBP_Kernel::defaults();
		$clean    = array();

		foreach ( $defaults as $key => $default ) {
			if ( 'nav_cache_ttl' === $key || 'nav_cache_max' === $key || 'prefetch_budget' === $key ) {
				$clean[ $key ] = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : (int) $default;
				continue;
			}
			$clean[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		update_option( DBP_Kernel::OPTION, $clean, false );
		DBP_Kernel::bump_generation();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&dbp=saved' ) );
		exit;
	}

	/**
	 * @return void
	 */
	public static function purge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'delicat-builder-v9' ) );
		}
		check_admin_referer( self::NONCE );

		DBP_Kernel::bump_generation();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&dbp=purged' ) );
		exit;
	}
}
