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
 * Requires PHP 8.5 (gated in the main plugin file).
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
	 * This is a SAFETY NET, not a byte-saving layer. Every handle listed here is
	 * already gated at its own enqueue site — native-product, swatches, the product
	 * switcher, the field calculator, the native archive, the wallet guard and the
	 * express sheet each return early off their route — so on a healthy install this
	 * loop dequeues nothing at all. It exists to catch a module that forgets, and a
	 * future reader should not assume it is what keeps product CSS off the homepage.
	 *
	 * pro.16 removed two entries that could never do anything: `delicat-wallet-tour`
	 * is not a registered handle anywhere in the plugin (the tour prints inline, which
	 * is why Audit_Fixes::document() strips it with a regex instead), and
	 * `wc-add-to-cart-variation` is enqueued by WooCommerce during template rendering,
	 * after wp_enqueue_scripts has already finished, so a dequeue here can never reach
	 * it. The always-on sheets are deliberately absent: dequeuing them here would break
	 * their cascade.
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

			/* Listing surfaces. */
			'delicat-builder-v9-native-archive' => array( 'shop', 'archive', 'search' ),

			/* Purchase surfaces. */
			'delicat-builder-v9-wallet-guard'   => array( 'checkout', 'cart', 'account', 'wallet' ),
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

		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}

		$src    = '';
		$srcset = '';
		$sizes  = '';

		/*
		 * Ask the native product engine what it is actually going to paint. This used
		 * to guess — featured image at 'woocommerce_single' — while the page painted a
		 * per-product banner, or the same image at 'large' chosen from a srcset. The
		 * preload then fetched a full-size image at high priority that was never used,
		 * on every product view, competing with the real LCP for connections.
		 */
		if (
			class_exists( 'Delicat_Builder_V9_Native_Product', false )
			&& is_callable( array( 'Delicat_Builder_V9_Native_Product', 'hero_source' ) )
			&& is_callable( array( 'Delicat_Builder_V9_Native_Product', 'is_active_product' ) )
			&& Delicat_Builder_V9_Native_Product::is_active_product( $post_id )
		) {
			$hero   = Delicat_Builder_V9_Native_Product::hero_source( $post_id );
			$src    = (string) ( $hero['url'] ?? '' );
			$srcset = (string) ( $hero['srcset'] ?? '' );
			$sizes  = (string) ( $hero['sizes'] ?? '' );
		} else {
			/* A product the native template does not own still renders WooCommerce's
			 * own single-product image. */
			$id = get_post_thumbnail_id( $post_id );
			if ( ! $id ) {
				return;
			}
			$src    = (string) wp_get_attachment_image_url( $id, 'woocommerce_single' );
			$srcset = (string) wp_get_attachment_image_srcset( $id, 'woocommerce_single' );
		}

		if ( '' === $src ) {
			return;
		}

		add_action(
			'wp_head',
			static function () use ( $src, $srcset, $sizes ) {
				$tag = '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url( $src ) . '"';
				/* Without these the browser preloads the href while the <img> picks a
				 * different candidate from its srcset — two downloads, one painted. */
				if ( '' !== $srcset ) {
					$tag .= ' imagesrcset="' . esc_attr( $srcset ) . '"';
					if ( '' !== $sizes ) {
						$tag .= ' imagesizes="' . esc_attr( $sizes ) . '"';
					}
				}
				echo $tag . '>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part escaped above.
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
