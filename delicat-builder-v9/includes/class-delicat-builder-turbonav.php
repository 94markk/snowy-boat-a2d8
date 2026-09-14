<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC51.20 — TURBONAV: native instant navigation layer.
 *
 * Three browser-native accelerations the fetch-warming engine cannot provide,
 * plus two sitewide cleanups previously limited to managed surfaces:
 *
 * 1. SPECULATION RULES (guests only). Chrome/Android — the dominant browser in
 *    Haiti — prerenders the next page in the background on hover/touch intent.
 *    Navigation to a prerendered page is effectively 0 ms: HTML, CSS, JS and
 *    layout are already done. `prefetch` covers additional links cheaply.
 *    Logged-in visitors are excluded entirely: their pages carry private
 *    no-store wallet balances, which makes speculative documents both
 *    uncacheable and undesirable. iOS Safari ignores the JSON script tag and
 *    keeps using the existing fetch-warming engine — no double work, because
 *    Chrome's rules and prefetch.js populate the same HTTP cache.
 *
 * 2. CROSS-DOCUMENT VIEW TRANSITIONS (all visitors). A ~300-byte inline CSS
 *    opt-in gives every full page navigation a fast 160 ms crossfade on
 *    Chrome 126+ / Safari 18.2+ instead of the white flash. Pure progressive
 *    enhancement; disabled automatically for prefers-reduced-motion.
 *
 * 3. SITEWIDE HEAD TRIM. Emoji detection script/styles, wp-embed, RSD /
 *    wlwmanifest / shortlink / generator were removed only on managed
 *    homepage/archive/fast-single requests. Every storefront page now sheds
 *    that legacy overhead.
 *
 * 4. GUEST CART-FRAGMENTS. wc-cart-fragments fires an uncacheable admin-ajax
 *    POST on every page view. For a logged-out visitor with an empty cart it
 *    synchronizes nothing. It is now dropped sitewide for that visitor
 *    (except on Cart/Checkout), extending the managed-page rule that already
 *    proved safe in production.
 *
 * Safety model:
 * - Same-origin links only (Speculation Rules cannot leave the origin).
 * - Cart, Checkout, My Account, My Wallet, wp-admin, wp-login, wp-json and
 *   every URL carrying a query string are excluded from speculation — the
 *   same private-surface map the service worker and prefetch engine use.
 * - `a[data-no-turbo]` / `.no-prerender` opt out any link.
 * - Save-Data requests receive no speculation rules at all.
 * - Kill switch: option delicat_builder_v9_turbonav['enabled'] = 0.
 */
final class Delicat_Builder_V9_TurboNav {

	public const OPTION = 'delicat_builder_v9_turbonav';

	/** @var array<string,mixed>|null */
	private static $settings_cache = null;

	public static function boot() {
		if ( is_admin() ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'trim_legacy_head' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_view_transition_css' ), 3 );
		add_action( 'wp_footer', array( __CLASS__, 'print_speculation_rules' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'drop_guest_cart_fragments' ), 998 );
		// WordPress 6.8+ ships its own speculative loading (conservative
		// prefetch). Rather than printing duplicate rules there, upgrade the
		// core configuration to moderate prerender — same behavior as our own
		// rules, one single source of truth.
		add_filter( 'wp_speculation_rules_configuration', array( __CLASS__, 'upgrade_core_speculation' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'extend_core_exclusions' ), 10, 2 );
	}

	public static function defaults() {
		return array(
			'enabled'          => 1,
			'prerender'        => 1, // guests: prerender + prefetch document rules
			'view_transitions' => 1,
			'trim_head'        => 1,
			'guest_fragments'  => 1,
		);
	}

	public static function settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}
		$saved = get_option( self::OPTION, array() );
		self::$settings_cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		return self::$settings_cache;
	}

	private static function active() {
		if ( empty( self::settings()['enabled'] ) ) {
			return false;
		}
		if ( is_feed() || is_embed() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) ) {
			if ( is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) ) && ! Delicat_Builder_V9_Core::is_enabled() ) {
				return false;
			}
			if ( is_callable( array( 'Delicat_Builder_V9_Core', 'is_safe_mode' ) ) && Delicat_Builder_V9_Core::is_safe_mode() ) {
				return false;
			}
		}
		return true;
	}

	private static function save_data_requested() {
		$value = isset( $_SERVER['HTTP_SAVE_DATA'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SAVE_DATA'] ) ) ) : '';
		return 'on' === $value;
	}

	/** True when the Pro navigation engine swaps catalogue pages in place. */
	private static function pro_navigation_active() {
		return class_exists( 'DBP_Kernel', false )
			&& is_callable( array( 'DBP_Kernel', 'on' ) )
			&& DBP_Kernel::on( 'navigation' );
	}

	/**
	 * pro.16: which links may be prerendered.
	 *
	 * Alone, TurboNav prerenders every same-origin document. Next to the Pro
	 * engine that was double work on WordPress 6.8+: Core printed prerender
	 * rules for every guest link, the engine intercepted the same click and
	 * fetched a fragment instead, and the prerendered document was thrown
	 * away — a full page of bandwidth per hover on the 3G links this store
	 * runs on. With the engine active the rules cover only the routes it
	 * never swaps, product pages, where a prerendered document is the one
	 * thing that makes the open instant.
	 *
	 * @return array<int,string> URL patterns, empty when nothing can be targeted.
	 */
	private static function prerender_targets() {
		if ( ! self::pro_navigation_active() ) {
			return array( '/*' );
		}
		$permalinks = get_option( 'woocommerce_permalinks', array() );
		$base       = is_array( $permalinks ) && ! empty( $permalinks['product_base'] ) ? trim( (string) $permalinks['product_base'], '/' ) : 'product';
		if ( '' === $base || false !== strpos( $base, '%' ) ) {
			/* A category-based product permalink cannot be matched as a literal path. */
			return array();
		}
		return array( '/' . $base . '/*' );
	}

	/**
	 * Sitewide removal of legacy head overhead. Same set the Performance module
	 * applies to managed surfaces; remove_action is idempotent so both may run.
	 */
	public static function trim_legacy_head() {
		if ( ! self::active() || empty( self::settings()['trim_head'] ) ) {
			return;
		}
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_legacy_scripts' ), 997 );
	}

	public static function dequeue_legacy_scripts() {
		wp_dequeue_script( 'wp-embed' );
		wp_dequeue_style( 'wp-emoji-styles' );
	}

	/**
	 * wc-cart-fragments POSTs to admin-ajax on every page view and bypasses
	 * every cache layer. A logged-out visitor with an empty cart has nothing
	 * to synchronize; the badge is 0 either way.
	 */
	public static function drop_guest_cart_fragments() {
		if ( ! self::active() || empty( self::settings()['guest_fragments'] ) ) {
			return;
		}
		if ( is_user_logged_in() ) {
			return;
		}
		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			return;
		}
		$count = isset( $_COOKIE['woocommerce_items_in_cart'] ) ? absint( wp_unslash( $_COOKIE['woocommerce_items_in_cart'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $count > 0 ) {
			return;
		}
		wp_dequeue_script( 'wc-cart-fragments' );
	}

	/**
	 * ~300 bytes inline, present on every page so both sides of a navigation
	 * opt in (a cross-document transition requires the opt-in on old AND new
	 * page). 160 ms keeps it feeling instant rather than animated.
	 */
	public static function print_view_transition_css() {
		if ( ! self::active() || empty( self::settings()['view_transitions'] ) ) {
			return;
		}
		echo '<style id="delicat-v9-turbonav-vt">'
			. '@media (prefers-reduced-motion: no-preference){'
			. '@view-transition{navigation:auto}'
			. '::view-transition-old(root){animation:dbv9VtOut .14s ease both}'
			. '::view-transition-new(root){animation:dbv9VtIn .16s ease both}'
			. '@keyframes dbv9VtOut{to{opacity:0}}'
			. '@keyframes dbv9VtIn{from{opacity:0}}'
			. '}'
			. '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Private and stateful surfaces, mirrored from the service worker map,
	 * plus a blanket exclusion of every query-string URL (add-to-cart,
	 * wc-ajax, nonce and logout links all live there).
	 *
	 * @return array<int,string>
	 */
	private static function excluded_patterns() {
		return array(
			'/wp-admin/*',
			'/wp-login.php*',
			'/wp-json/*',
			'/cart/*',
			'/panier/*',
			'/checkout/*',
			'/commande/*',
			'/my-account/*',
			'/mon-compte/*',
			'/my-wallet/*',
			'/*\\?*',
		);
	}

	/**
	 * WordPress 6.8+ owns the speculation-rules script, but its default private
	 * path list does not know Delicat's translated checkout/account/wallet
	 * routes. Extend Core's list so the native fast path keeps the same safety
	 * boundary as the legacy fallback rules below.
	 *
	 * @param array<int,string> $paths Existing Core/plugin exclusions.
	 * @param string            $mode  prefetch or prerender.
	 * @return array<int,string>
	 */
	public static function extend_core_exclusions( $paths, $mode = '' ) {
		if ( ! self::active() || ! is_array( $paths ) ) {
			return $paths;
		}
		return array_values( array_unique( array_merge( $paths, self::excluded_patterns() ) ) );
	}

	public static function upgrade_core_speculation( $config ) {
		if ( ! self::active() || empty( self::settings()['prerender'] ) || self::save_data_requested() ) {
			return $config;
		}
		if ( self::pro_navigation_active() ) {
			/* Core's rules would prerender the very links the Pro engine swaps
			 * as fragments. Switch Core off; print_speculation_rules() prints
			 * the product-only set instead. */
			return null;
		}
		if ( is_array( $config ) ) {
			$config['mode']      = 'prerender';
			$config['eagerness'] = 'moderate';
		}
		return $config;
	}

	public static function print_speculation_rules() {
		if (
			! self::active()
			|| empty( self::settings()['prerender'] )
			|| self::save_data_requested()
			|| ( is_user_logged_in() && ! ( class_exists( 'Delicat_Builder_V9_Security', false ) && is_callable( array( 'Delicat_Builder_V9_Security', 'private_cache_allowed' ) ) && Delicat_Builder_V9_Security::private_cache_allowed() ) ) /* RC32: signed-in customers prerender too */
		) {
			return;
		}

		// WordPress core (6.8+) prints its own rules for guests; it stays silent for
		// signed-in users, so RC32 prints ours for privately-cached customers.
		// With the Pro engine active Core is switched off (see
		// upgrade_core_speculation) and this block owns every visitor.
		if ( ! self::pro_navigation_active() && function_exists( 'wp_get_speculation_rules' ) && ! is_user_logged_in() ) {
			return;
		}

		$targets = self::prerender_targets();
		if ( empty( $targets ) ) {
			return;
		}

		$not = array(
			array( 'href_matches' => self::excluded_patterns() ),
			array( 'selector_matches' => 'a[data-no-turbo], .no-prerender a, a.no-prerender' ),
			array( 'selector_matches' => 'a[rel~="nofollow"]' ),
		);

		$rules = array(
			'prerender' => array(
				array(
					'source'    => 'document',
					'where'     => array(
						'and' => array_merge(
							array( array( 'href_matches' => $targets ) ),
							array_map(
								static function ( $condition ) {
									return array( 'not' => $condition );
								},
								$not
							)
						),
					),
					'eagerness' => 'moderate',
				),
			),
			'prefetch'  => array(
				array(
					'source'    => 'document',
					'where'     => array(
						'and' => array_merge(
							array( array( 'href_matches' => $targets ) ),
							array_map(
								static function ( $condition ) {
									return array( 'not' => $condition );
								},
								$not
							)
						),
					),
					'eagerness' => 'moderate',
				),
			),
		);

		$json = wp_json_encode( $rules, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}

		/* RC79: the rules ship inert and are promoted only on a link that can
		 * afford them.
		 *
		 * Prerendering downloads a second complete document — HTML, CSS, JS and
		 * images — over the same connection the shopper is using for the page
		 * in front of them. On 4G that is free speed. On the 3G links this
		 * store actually runs on it is a straight loss: the current page gets
		 * slower so a page nobody has asked for can be ready.
		 *
		 * The old gate was the Save-Data request header alone, which almost
		 * nobody sends — Chrome defaults it off and newer versions dropped it
		 * entirely — so in practice every 3G visitor was prerendering. The
		 * connection can only be measured in the browser, and the cached HTML
		 * has to stay byte-identical for every guest, so the decision is made
		 * here, client-side, from the type the Builder's boot script already
		 * resolved before the first paint.
		 *
		 * A script element only registers its rules when it is parsed with the
		 * speculationrules type, so an inert copy costs nothing until swapped. */
		$inert = '<script type="delicat/speculationrules" id="dbv9-speculation">' . $json . '</script>';
		$swap  = '<script id="dbv9-speculation-gate">(function(){'
			. 'var r=document.documentElement;'
			. 'if(r.className.indexOf("delicat-slow-net")>-1)return;'
			. 'var s=document.getElementById("dbv9-speculation");if(!s)return;'
			. 'var n=document.createElement("script");n.type="speculationrules";'
			. 'n.textContent=s.textContent;'
			. 's.parentNode.replaceChild(n,s);'
			. '}());</script>';

		echo $inert . "\n" . $swap . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $json is wp_json_encode output; the gate is a fixed literal.
	}
}

Delicat_Builder_V9_TurboNav::boot();
