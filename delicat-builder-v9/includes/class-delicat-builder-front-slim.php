<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC51.36 — Front Slim.
 *
 * Complements TurboNav's head trim with the front-end bloat it does not touch:
 *
 * 1. GOOGLE FONTS. Playfair/Poppins load from fonts.googleapis.com without a
 *    preconnect to fonts.gstatic.com, costing a full extra connection setup
 *    (DNS+TCP+TLS, easily 100–300 ms on Haitian mobile RTTs) before text can
 *    swap in. Adds both preconnects only when a Google Fonts stylesheet is
 *    actually queued, and enforces display=swap so text never blocks on fonts.
 *
 * 2. CART FRAGMENTS DELAY. TurboNav already drops wc-cart-fragments for
 *    guests with an empty cart. For everyone else it still fires an
 *    uncacheable admin-ajax POST during page load. Since those visitors get a
 *    server-rendered cart badge anyway (they bypass page caches), the script
 *    can load after first interaction or idle instead of competing with LCP.
 *
 * 3. JQUERY MIGRATE (~12 KB) removed on the front end — nothing in the
 *    Elementor/Woo/V9 stack needs 1.x shims.
 *
 * 4. HEARTBEAT disabled on the front end (it exists for wp-admin post
 *    locking; on a storefront it is pure idle polling).
 *
 * 5. DASHICONS dequeued for logged-out visitors (only the admin bar needs it).
 *
 * Every behavior is filterable via delicat_builder_v9_front_slim_{feature}
 * and the whole module respects Core enable/safe-mode. Front-end only.
 * PHP 7.4; the inline loader is ES5.
 */
final class Delicat_Builder_V9_Front_Slim {

	private static $delayed_fragments = null;

	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_default_scripts', array( __CLASS__, 'remove_jquery_migrate' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'slim_scripts' ), 999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'defer_plugin_scripts' ), 9999 );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'font_preconnects' ), 10, 2 );
		add_filter( 'style_loader_src', array( __CLASS__, 'force_font_display_swap' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'print_fragments_loader' ), 99 );
	}

	private static function active(): bool {
		return self::core_active()
			&& ! Delicat_Builder_V9_Core::is_safe_mode();
	}

	private static function core_active(): bool {
		return class_exists( 'Delicat_Builder_V9_Core', false )
			&& Delicat_Builder_V9_Core::is_enabled();
	}

	private static function feature( string $name, bool $default = true ): bool {
		return (bool) apply_filters( 'delicat_builder_v9_front_slim_' . $name, $default );
	}

	/* ------------------------------------------------------------------ */
	/* 1. Google Fonts                                                     */
	/* ------------------------------------------------------------------ */

	private static function google_fonts_queued(): bool {
		$styles = wp_styles();
		if ( ! $styles ) {
			return false;
		}
		foreach ( (array) $styles->queue as $handle ) {
			$src = isset( $styles->registered[ $handle ] ) ? (string) $styles->registered[ $handle ]->src : '';
			if ( false !== strpos( $src, 'fonts.googleapis.com' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function font_preconnects( $urls, $relation_type ) {
		if ( 'preconnect' !== $relation_type || ! self::active() || ! self::feature( 'font_preconnect' ) ) {
			return $urls;
		}
		if ( ! self::google_fonts_queued() ) {
			return $urls;
		}
		$urls = is_array( $urls ) ? $urls : array();
		$seen = array();
		foreach ( $urls as $url ) {
			$href = is_array( $url ) ? (string) ( $url['href'] ?? '' ) : (string) $url;
			if ( '' !== $href ) $seen[ $href ] = true;
		}
		if ( empty( $seen['https://fonts.googleapis.com'] ) ) $urls[] = array( 'href' => 'https://fonts.googleapis.com' );
		if ( empty( $seen['https://fonts.gstatic.com'] ) ) {
			$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous' );
		}
		return $urls;
	}

	public static function force_font_display_swap( $src ) {
		if ( ! is_string( $src ) || ! self::active() || ! self::feature( 'font_display_swap' ) ) {
			return $src;
		}
		if ( false === strpos( $src, 'fonts.googleapis.com' ) || false !== strpos( $src, 'display=' ) ) {
			return $src;
		}
		return add_query_arg( 'display', 'swap', $src );
	}

	/* ------------------------------------------------------------------ */
	/* 2–5. Script diet                                                    */
	/* ------------------------------------------------------------------ */

	public static function remove_jquery_migrate( $scripts ): void {
		if ( ! self::active() || ! self::feature( 'no_jquery_migrate' ) || ! isset( $scripts->registered['jquery'] ) ) {
			return;
		}
		$jquery = $scripts->registered['jquery'];
		if ( ! empty( $jquery->deps ) && is_array( $jquery->deps ) ) {
			$jquery->deps = array_values( array_diff( $jquery->deps, array( 'jquery-migrate' ) ) );
		}
	}

	public static function slim_scripts(): void {
		if ( ! self::active() ) {
			return;
		}

		if ( self::feature( 'no_front_heartbeat' ) ) {
			wp_deregister_script( 'heartbeat' );
		}

		if ( self::feature( 'no_guest_dashicons' ) && ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
		}

		/* 9.1 RC4: a managed V9 homepage uses the system-font token from
		 * theme-system/components. Any theme/legacy Google Font stylesheet is
		 * therefore dead weight there. Native documents already remove these in
		 * Storefront Fix; this closes the homepage gap without touching ordinary
		 * theme pages that may genuinely depend on the font. */
		if (
			self::feature( 'no_managed_home_google_fonts' )
			&& function_exists( 'is_front_page' )
			&& is_front_page()
			&& function_exists( 'delicat_builder_v9_is_managed_page' )
			&& delicat_builder_v9_is_managed_page( absint( get_queried_object_id() ) )
		) {
			$styles = wp_styles();
			if ( $styles ) {
				foreach ( (array) $styles->queue as $handle ) {
					$registered = $styles->registered[ $handle ] ?? null;
					$src = is_object( $registered ) ? (string) $registered->src : '';
					if ( false !== strpos( $src, 'fonts.googleapis.com' ) || false !== strpos( $src, 'fonts.gstatic.com' ) ) {
						wp_dequeue_style( $handle );
					}
				}
			}
		}

		/* 9.1 RC2: classic head cleanup — emoji shims, oEmbed discovery, RSD,
		 * wlwmanifest, shortlinks and the REST discovery link cost bytes or a
		 * request on every page and serve nothing on this storefront. */
		if ( self::feature( 'head_cleanup' ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			add_filter( 'emoji_svg_url', '__return_false' );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			add_filter( 'show_recent_comments_widget_style', '__return_false' );
		}

		self::maybe_delay_cart_fragments();
	}


	/**
	 * Apply the defer strategy after normal component enqueues have finished.
	 * Keeping this as a real late hook avoids registering a hook while the same
	 * hook is already executing, which is harder to reason about across WP versions.
	 */
	public static function defer_plugin_scripts(): void {
		if ( ! self::active() || ! self::feature( 'defer_plugin_scripts' ) || ! function_exists( 'wp_scripts' ) ) {
			return;
		}
		$scripts = wp_scripts();
		if ( ! $scripts ) return;
		foreach ( (array) $scripts->queue as $handle ) {
			if ( 0 === strpos( (string) $handle, 'delicat' ) || 0 === strpos( (string) $handle, 'dsb' ) ) {
				wp_script_add_data( $handle, 'strategy', 'defer' );
			}
		}
	}

	/**
	 * Convert wc-cart-fragments into an interaction/idle-deferred load.
	 * Never on Cart/Checkout (fragments drive real UI there). If TurboNav
	 * already dropped the handle (guest + empty cart) there is nothing to do.
	 */
	private static function maybe_delay_cart_fragments(): void {
		if ( ! self::feature( 'delay_cart_fragments' ) ) {
			return;
		}
		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			return;
		}

		$scripts = wp_scripts();
		if ( ! $scripts || ! wp_script_is( 'wc-cart-fragments', 'enqueued' ) || empty( $scripts->registered['wc-cart-fragments'] ) ) {
			return;
		}

		$registered = $scripts->registered['wc-cart-fragments'];
		$src = (string) $registered->src;
		if ( '' === $src ) {
			return;
		}
		if ( 0 === strpos( $src, '//' ) ) {
			$src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
		} elseif ( 0 === strpos( $src, '/' ) ) {
			$src = site_url( $src );
		}
		$ver = (string) ( $registered->ver ? $registered->ver : '' );
		if ( '' !== $ver ) {
			$src = add_query_arg( 'ver', rawurlencode( $ver ), $src );
		}

		self::$delayed_fragments = array(
			'src'  => esc_url_raw( $src ),
			/* Localized wc_cart_fragments_params must exist before the script runs. */
			'data' => (string) $scripts->get_data( 'wc-cart-fragments', 'data' ),
		);
		wp_dequeue_script( 'wc-cart-fragments' );
	}

	public static function print_fragments_loader(): void {
		if ( empty( self::$delayed_fragments ) || ! self::active() ) {
			return;
		}
		$src  = (string) self::$delayed_fragments['src'];
		$data = (string) self::$delayed_fragments['data'];
		if ( '' === $src ) {
			return;
		}

		if ( '' !== $data ) {
			echo '<script id="dbv9-fragments-params">' . $data . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_scripts localized data, printed verbatim as core would.
		}
		?>
		<script id="dbv9-fragments-delay">
		(function () {
			'use strict';
			var loaded = false;
			function go() {
				if (loaded) { return; }
				loaded = true;
				var s = document.createElement('script');
				s.src = <?php echo wp_json_encode( $src ); ?>;
				s.defer = true;
				document.body.appendChild(s);
				document.removeEventListener('pointerdown', go, true);
				document.removeEventListener('keydown', go, true);
				document.removeEventListener('scroll', go, true);
			}
			document.addEventListener('pointerdown', go, { passive: true, capture: true });
			document.addEventListener('keydown', go, { passive: true, capture: true });
			document.addEventListener('scroll', go, { passive: true, capture: true });
			if ('requestIdleCallback' in window) {
				window.requestIdleCallback(go, { timeout: 5000 });
			} else {
				window.setTimeout(go, 5000);
			}
		})();
		</script>
		<?php
	}
}

/*
 * RC32 — third-party tag delay. Google's gtag/Site Kit scripts (~110 KB) are
 * not needed to paint or to sell; they start on the first tap/scroll/key or
 * after 4 s of idle, whichever comes first. Patterns are filterable
 * (delicat_builder_v9_delay_script_patterns); `?nodelay=1` disables for a test.
 */
final class Delicat_Builder_V9_Script_Delay {
	private static bool $printed = false;

	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_filter( 'script_loader_tag', array( __CLASS__, 'tag' ), 20, 3 );
		add_action( 'wp_footer', array( __CLASS__, 'loader' ), 200 );
	}

	private static function patterns(): array {
		return (array) apply_filters( 'delicat_builder_v9_delay_script_patterns', array( 'googletagmanager.com', 'google-analytics.com', 'googlesitekit', 'gtag/js' ) );
	}

	public static function tag( $tag, $handle, $src ) {
		if ( ! is_string( $tag ) || ! is_string( $src ) || '' === $src || isset( $_GET['nodelay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $tag;
		}
		foreach ( self::patterns() as $needle ) {
			if ( '' !== $needle && false !== strpos( $src, $needle ) ) {
				self::$printed = true;
				$tag = preg_replace( '/\s(async|defer)(=("|\')?[^"\'\s>]*("|\')?)?/i', '', $tag );
				return str_replace( ' src=', ' type="text/plain" data-delicat-delay-src=', $tag );
			}
		}
		return $tag;
	}

	public static function loader(): void {
		if ( ! self::$printed ) {
			return;
		}
		?>
		<script>(function(){var run=false;function go(){if(run)return;run=true;var list=document.querySelectorAll('script[data-delicat-delay-src]');for(var i=0;i<list.length;i++){var o=list[i],n=document.createElement('script');n.src=o.getAttribute('data-delicat-delay-src');n.async=true;if(o.id)n.id=o.id;for(var a=0;a<o.attributes.length;a++){var at=o.attributes[a];if(at.name.indexOf('data-')===0&&at.name!=='data-delicat-delay-src')n.setAttribute(at.name,at.value);}document.body.appendChild(n);}}
		var ev=['pointerdown','keydown','touchstart','scroll','wheel'];function on(){for(var i=0;i<ev.length;i++)window.removeEventListener(ev[i],on,{passive:true});go();}for(var i=0;i<ev.length;i++)window.addEventListener(ev[i],on,{passive:true});
		var idle=window.requestIdleCallback||function(cb){return setTimeout(cb,1)};window.addEventListener('load',function(){setTimeout(function(){idle(go)},4000)});}());</script>
		<?php
	}
}
Delicat_Builder_V9_Script_Delay::boot();
