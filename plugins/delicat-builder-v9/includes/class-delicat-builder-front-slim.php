<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC51.36 — Front Slim.
 *
 * 1. GOOGLE FONTS. Playfair/Poppins load from fonts.googleapis.com without a
 *    preconnect to fonts.gstatic.com, costing a full extra connection setup
 *    (DNS+TCP+TLS, easily 100–300 ms on Haitian mobile RTTs) before text can
 *    swap in. Adds both preconnects only when a Google Fonts stylesheet is
 *    actually queued, and enforces display=swap so text never blocks on fonts.
 *
 * 2. JQUERY MIGRATE (~12 KB) removed on the front end — nothing in the
 *    Elementor/Woo/V9 stack needs 1.x shims.
 *
 * V10: the script diet (heartbeat, guest dashicons, managed-home fonts, head
 * cleanup, the cart-fragments delay) and the defer pass are gone. With the Pro
 * asset layer on (the default) DBP_Kernel removed both at `wp` on every page, so
 * they never ran in production; the head trims live in TurboNav and DBP_Assets,
 * and the engine owns script delivery.
 *
 * Every behavior is filterable via delicat_builder_v9_front_slim_{feature}
 * and the whole module respects Core enable/safe-mode. Front-end only.
 * Requires PHP 8.3 (gated in the main plugin file).
 */
final class Delicat_Builder_V9_Front_Slim {

	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_default_scripts', array( __CLASS__, 'remove_jquery_migrate' ) );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'font_preconnects' ), 10, 2 );
		add_filter( 'style_loader_src', array( __CLASS__, 'force_font_display_swap' ), 20 );
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
	/* 2. jQuery Migrate                                                   */
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
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) && ( ! Delicat_Builder_V9_Core::is_enabled() || Delicat_Builder_V9_Core::is_safe_mode() ) ) {
			return $tag; /* 9.3: off with the master switch and in Safe Mode, like every other layer */
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
