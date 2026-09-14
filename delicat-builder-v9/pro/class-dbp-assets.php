<?php
/**
 * Delicat Builder V9 Pro — Asset pipeline.
 *
 * V9 enqueued from roughly twenty classes at priorities 1 through 9999, then
 * ran a dequeue pass at 999 and a defer pass at 9999 to undo some of it. No
 * single place knew what a page would ship, so every route shipped close to
 * everything. Pro takes ownership after every module has spoken.
 *
 * WHAT CHANGED IN pro.2
 * ---------------------
 * pro.1 rewrote every non-critical stylesheet to media="print" with an inline
 * onload that swapped it back to "all". The technique is common and it is
 * wrong on this stack: LiteSpeed combines the site's stylesheets into one
 * artefact and does not carry the inline onload through, so the combined sheet
 * loaded as print-only and the storefront rendered with no CSS at all.
 *
 * The swap is removed outright. It is not worth keeping behind a flag — a
 * technique that can blank the store is a defect, not a setting.
 *
 * pro.1 also moved jQuery to the footer. V9's header, drawer and session
 * scripts read jQuery at parse time in the head, so that broke them. Removed.
 *
 * What remains is where the real weight was and where the risk is not:
 * route-scoped shipping, additive inline shell CSS, and a narrow opt-in defer.
 *
 * PHP 7.4 compatible.
 *
 * @package Delicat_Builder_V9_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'DBP_Assets', false ) ) {
	return;
}

final class DBP_Assets {

	/**
	 * @return void
	 */
	public static function boot() {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'shape' ), 100000 );
		add_action( 'wp_head', array( __CLASS__, 'critical_css' ), 1 );
		add_action( 'init', array( __CLASS__, 'trim_core' ), 20 );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'resource_hints' ), 10, 2 );

		/* Deferring is opt-in and off by default. WooCommerce and V9 print
		 * inline jQuery(...) blocks that would run before a deferred file,
		 * throwing before the storefront finishes wiring itself. */
		if ( DBP_Kernel::on( 'defer_js' ) ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 20, 3 );
		}
	}

	/**
	 * Bundles that belong to exactly one family of routes.
	 *
	 * Anything absent from this map is treated as global and left alone, so a
	 * module added later is never silently starved of its assets. Filterable,
	 * so one wrong entry can be corrected on the live site without a release.
	 *
	 * @return array handle => routes permitted to load it.
	 */
	public static function route_map() {
		$map = array(
			/* Product surfaces: variation form, swatches, field calculator and
			 * the express sheet. Roughly 108 KB with no reason to be on the
			 * homepage. */
			'delicat-v9-native-product'         => array( 'product' ),
			'delicat-direct-swatches'           => array( 'product' ),
			'delicat-product-switcher'          => array( 'product' ),
			'dmc-calc'                          => array( 'product' ),
			'wc-add-to-cart-variation'          => array( 'product' ),
			'delicat-builder-v9-express'        => array( 'product', 'shop', 'archive' ),

			/* Listing surfaces. */
			'delicat-builder-v9-native-archive' => array( 'shop', 'archive', 'search' ),

			/* Purchase surfaces. */
			'delicat-builder-v9-wallet-guard'   => array( 'checkout', 'cart', 'account', 'wallet' ),
			'delicat-wallet-tour'               => array( 'wallet' ),
		);

		return apply_filters( 'dbp_route_map', $map );
	}

	/**
	 * Apply the route decision.
	 *
	 * @return void
	 */
	public static function shape() {
		if ( DBP_Kernel::on( 'route_assets' ) ) {
			$route = DBP_Kernel::route();

			foreach ( self::route_map() as $handle => $routes ) {
				if ( in_array( $route, $routes, true ) ) {
					continue;
				}
				wp_dequeue_style( $handle );
				wp_dequeue_script( $handle );
			}
		}

		if ( DBP_Kernel::is_fragment_request() ) {
			return;
		}

		wp_enqueue_style(
			'dbp-app',
			DBP_Kernel::asset_url( 'pro/assets/dbp-app.css' ),
			array(),
			DBP_Kernel::asset_version()
		);

		self::preload_hero();
	}

	/**
	 * Preload the LCP image where it is predictable.
	 *
	 * @return void
	 */
	private static function preload_hero() {
		if ( 'product' !== DBP_Kernel::route() ) {
			return;
		}

		$id = get_post_thumbnail_id( get_the_ID() );
		if ( ! $id ) {
			return;
		}

		$src = wp_get_attachment_image_url( $id, 'woocommerce_single' );
		if ( ! $src ) {
			return;
		}

		add_action(
			'wp_head',
			static function () use ( $src ) {
				echo '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url( $src ) . '">' . "\n";
			},
			2
		);
	}

	/**
	 * Defer a narrow set of scripts. Opt-in, and deliberately timid.
	 *
	 * A script is deferred only when it declares no dependencies and is not
	 * jQuery or the theme bootstrap. Anything depending on jQuery, and
	 * jQuery's inline consumers, are left exactly as WordPress printed them.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Source.
	 * @return string
	 */
	public static function script_tag( $tag, $handle, $src ) {
		unset( $src );

		if ( is_admin() || DBP_Kernel::is_fragment_request() ) {
			return $tag;
		}
		if ( false === strpos( $tag, ' src=' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
			return $tag;
		}

		$reserved = array( 'jquery', 'jquery-core', 'jquery-migrate', 'delicat-builder-v9-theme' );
		if ( in_array( $handle, $reserved, true ) ) {
			return $tag;
		}

		$scripts = wp_scripts();
		if ( ! isset( $scripts->registered[ $handle ] ) || ! is_object( $scripts->registered[ $handle ] ) ) {
			return $tag;
		}

		$deps = $scripts->registered[ $handle ]->deps;
		if ( ! empty( $deps ) ) {
			return $tag;
		}

		return str_replace( ' src=', ' defer src=', $tag );
	}

	/**
	 * Remove core payloads the storefront never uses.
	 *
	 * @return void
	 */
	public static function trim_core() {
		if ( is_admin() ) {
			return;
		}

		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_generator' );

		add_filter( 'emoji_svg_url', '__return_false' );
	}

	/**
	 * @param array  $hints    Existing hints.
	 * @param string $relation Relation type.
	 * @return array
	 */
	public static function resource_hints( $hints, $relation ) {
		if ( 'preconnect' !== $relation ) {
			return $hints;
		}

		$hints[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous' );

		return $hints;
	}

	/**
	 * Inline the shell's critical CSS.
	 *
	 * Additive only. It describes the progress bar, transition states, the
	 * skeleton and the reserved height of the header and app dock. It never
	 * replaces or defers a stylesheet, so a failure here cannot leave the page
	 * unstyled.
	 *
	 * @return void
	 */
	public static function critical_css() {
		if ( DBP_Kernel::is_fragment_request() ) {
			return;
		}
		if ( ! DBP_Kernel::on( 'critical_css' ) ) {
			return;
		}

		$file = DELICAT_BUILDER_V9_DIR . 'pro/assets/dbp-critical.css';
		if ( ! file_exists( $file ) ) {
			return;
		}

		$css = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin asset.
		if ( ! is_string( $css ) || '' === $css ) {
			return;
		}

		echo '<style id="dbp-critical">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static stylesheet.
	}
}
