<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Beta 8 critical-path and intent-prediction engine.
 *
 * Security model:
 * - critical CSS comes only from plugin-owned allow-listed files;
 * - no user CSS is inlined;
 * - no third-party origin is preconnected automatically;
 * - high-confidence preloads are same-origin plugin assets only;
 * - predictive navigation is constrained client-side to safe same-origin GET pages.
 */
final class Delicat_Builder_V9_Performance {
	public const OPTION = 'delicat_builder_v9_performance';

	/**
	 * Smallest inline critical-CSS budget that still works.
	 *
	 * critical_css() appends whole files and skips any that would cross the
	 * budget, so a budget slightly too small does not trim the tail -- it drops
	 * an entire route stylesheet and says nothing. At the old 7000 floor the
	 * archive lost exactly that: shell minifies to 5309 bytes and woo-archive to
	 * 2246, so the 7555-byte pair overflowed and the archive sheet was dropped
	 * on every shop and category page. That sheet is what carries
	 * `display:grid` and grid-template-columns, so archives painted in
	 * WooCommerce's float layout and snapped into a grid only once woo-ui.css
	 * arrived. Product pages lost purchase-single the same way (7344).
	 *
	 * PRO37: 8000 did NOT clear the largest route. The builder homepage needs
	 * shell (5309) + products-home (3621) = 8930, so products-home was over the
	 * floor by 930 bytes and was dropped on every homepage request — the exact
	 * failure described above, still live, on the store's most-visited page.
	 * The product grid painted unstyled and reflowed once the full sheet landed.
	 *
	 * 9500 clears the homepage with room to spare and stays far below the ~14 KB
	 * first-flight window, so the inline block still arrives in the first round
	 * trip. tests/test-critical-budget.php fails if any route stops fitting.
	 */
	public const MIN_CRITICAL_BYTES = 9500;

	private static ?array $settings_cache = null;
	private static int $builder_page_id = 0;
	private static array $layout = array();
	private static array $manifest = array();
	private static array $critical_keys = array();
	private static $critical_runtime_cache = null;

	public static function boot(): void {
		self::maybe_upgrade_server_turbo_ready();
		add_action( 'wp', array( __CLASS__, 'detect_context' ), 30 );
		add_action( 'wp', array( __CLASS__, 'apply_managed_cleanup' ), 40 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_managed_noncritical' ), 999 );
		add_action( 'wp_head', array( __CLASS__, 'print_critical_css' ), 2 );
		add_action( 'wp_head', array( __CLASS__, 'print_high_confidence_preloads' ), 4 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 50 );
	}

	private static function maybe_upgrade_server_turbo_ready(): void {
		$upgrade_key = 'delicat_builder_v9_server_turbo_ready_version';
		if ( '51.50' === (string) get_option( $upgrade_key, '' ) ) {
			return;
		}

		$saved = get_option( self::OPTION, array() );
		$merged = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		$merged['server_shell_mode'] = 'v9';
		update_option( self::OPTION, $merged, false );
		update_option( $upgrade_key, '51.50', false );
		self::$settings_cache = null;
	}

	public static function defaults(): array {
		return array(
			'enabled'                  => 1,
			'critical_css'             => 1,
			'critical_max_bytes'       => 10000,
			'high_confidence_preloads' => 1,
			'predictive_navigation'    => 1,
			'intent_delay_ms'          => 140,
			'prefetch_budget'          => 3,
			'prefetch_max_bytes'       => 262144,
			'network_aware'            => 1,
			'instant_product_launch'   => 1,
			'instant_touch_delay_ms'   => 24,
			'server_rendered_pages'    => 1,
			'server_fragment_cache'    => 1,
			'server_fragment_ttl'      => 300,
			'server_page_cache_hint'   => 1,
			'server_shell_mode'        => 'v9',
			'islands_mode'             => 1,
			'zero_global_js'           => 1,
			'query_cache_enabled'      => 1,
			'query_cache_ttl'          => 600,
			'turbo_diagnostics'        => 1,
		);
	}

	public static function settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$saved = get_option( self::OPTION, array() );
		self::$settings_cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		return self::$settings_cache;
	}

	public static function ultra_fast_profile(): array {
		return array(
			'enabled'                  => 1,
			'critical_css'             => 1,
			'critical_max_bytes'       => 10000,
			'high_confidence_preloads' => 1,
			'predictive_navigation'    => 1,
			'intent_delay_ms'          => 160,
			'prefetch_budget'          => 2,
			'prefetch_max_bytes'       => 262144,
			'network_aware'            => 1,
			'instant_product_launch'   => 1,
			'instant_touch_delay_ms'   => 20,
			'server_rendered_pages'    => 1,
			'server_fragment_cache'    => 1,
			'server_fragment_ttl'      => 300,
			'server_page_cache_hint'   => 1,
			'server_shell_mode'        => 'v9',
			'islands_mode'             => 1,
			'zero_global_js'           => 1,
			'query_cache_enabled'      => 1,
			'query_cache_ttl'          => 600,
			'turbo_diagnostics'        => 1,
		);
	}

	/**
	 * RC51.34: production-safe Turbo Engine 2.0 profile.
	 *
	 * Merge into the current performance configuration so existing custom
	 * preload/prediction budgets are not destroyed. Only the server-rendering
	 * safety baseline and critical-path switches are enforced.
	 */
	public static function apply_server_turbo_safe_profile(): void {
		$current = self::settings();
		$current['enabled'] = 1;
		$current['critical_css'] = 1;
		$current['high_confidence_preloads'] = 1;
		$current['network_aware'] = 1;
		$current['server_rendered_pages'] = 1;
		$current['server_fragment_cache'] = 1;
		$current['server_fragment_ttl'] = 300;
		$current['server_page_cache_hint'] = 1;
		// RC51.50: Builder V9 owns the document; the active theme is only an
		// emergency fallback when the plugin is disabled.
		$current['server_shell_mode'] = 'v9';
		$current['islands_mode'] = 1;
		$current['zero_global_js'] = 1;
		$current['query_cache_enabled'] = 1;
		$current['query_cache_ttl'] = 600;
		$current['turbo_diagnostics'] = 1;

		update_option( self::OPTION, $current, false );
		self::$settings_cache = null;

		$core = Delicat_Builder_V9_Core::settings();
		$core['prefetch'] = 1;
		$core['low_power_mode'] = 1;
		$core['compiled_assets'] = 1;
		$core['carousel_progressive'] = 1;
		$core['app_navigation'] = 0;
		$core['navigation_scope'] = 'marked';
		update_option( 'delicat_builder_v9_settings', $core, false );
		if ( is_callable( array( 'Delicat_Builder_V9_Core', 'reset_settings_cache' ) ) ) {
			Delicat_Builder_V9_Core::reset_settings_cache();
		}
	}

	public static function apply_ultra_fast_profile(): void {
		update_option( self::OPTION, self::ultra_fast_profile(), false );
		self::$settings_cache = null;

		$core = Delicat_Builder_V9_Core::settings();
		$core['prefetch'] = 1;
		$core['low_power_mode'] = 1;
		$core['compiled_assets'] = 1;
		$core['carousel_progressive'] = 1;
		$core['carousel_initial'] = 3;
		// Keep partial app navigation disabled in the speed profile. Native full
		// navigation is the safest baseline for Woo/account/payment integrations.
		$core['app_navigation'] = 0;
		$core['navigation_scope'] = 'marked';
		update_option( 'delicat_builder_v9_settings', $core, false );
		if ( is_callable( array( 'Delicat_Builder_V9_Core', 'reset_settings_cache' ) ) ) {
			Delicat_Builder_V9_Core::reset_settings_cache();
		}
	}

	public static function enabled(): bool {
		return Delicat_Builder_V9_Core::is_enabled()
			&& ! Delicat_Builder_V9_Core::is_safe_mode()
			&& ! empty( self::settings()['enabled'] );
	}

	public static function detect_context(): void {
		self::$builder_page_id = 0;
		self::$layout = array();
		self::$manifest = array();
		self::$critical_keys = array();

		if ( ! self::enabled() || is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
			return;
		}

		if (
			class_exists( 'Delicat_Builder_V9_Shell' )
			&& is_callable( array( 'Delicat_Builder_V9_Shell', 'header_expected' ) )
			&& Delicat_Builder_V9_Shell::header_expected()
		) {
			self::$critical_keys[] = 'shell';
		}

		if (
			is_singular( 'page' )
			&& class_exists( 'Delicat_Builder_V9_Pages' )
			&& is_callable( array( 'Delicat_Builder_V9_Pages', 'is_enabled_for_page' ) )
			&& is_callable( array( 'Delicat_Builder_V9_Pages', 'get_layout' ) )
		) {
			$page_id = get_queried_object_id();
			if ( $page_id > 0 && Delicat_Builder_V9_Pages::is_enabled_for_page( $page_id ) ) {
				$layout = Delicat_Builder_V9_Pages::get_layout( $page_id );
				if ( ! empty( $layout ) ) {
					self::$builder_page_id = $page_id;
					self::$layout = $layout;

					try {
						if (
							class_exists( 'Delicat_Builder_V9_Compiler' )
							&& is_callable( array( 'Delicat_Builder_V9_Compiler', 'get_manifest' ) )
							&& is_callable( array( 'Delicat_Builder_V9_Compiler', 'analyze_layout' ) )
						) {
							/* RC39.11: public requests are read-only; never compile CSS on TTFB. */
							self::$manifest = Delicat_Builder_V9_Compiler::get_manifest( $page_id );
							$manifest_valid = ! empty( self::$manifest )
								&& hash_equals( Delicat_Builder_V9_Compiler::layout_hash( $layout ), (string) ( self::$manifest['layout_hash'] ?? '' ) )
								&& (string) ( self::$manifest['version'] ?? '' ) === DELICAT_BUILDER_V9_VERSION;
							if ( ! $manifest_valid ) {
								self::$manifest = array();
							}
							$graph = $manifest_valid && is_array( self::$manifest['resource_graph'] ?? null )
								? self::$manifest['resource_graph']
								: ( Delicat_Builder_V9_Compiler::analyze_layout( $layout )['resource_graph'] ?? array() );

							foreach ( (array) ( $graph['critical_css'] ?? array() ) as $asset ) {
								self::$critical_keys[] = 'component:' . sanitize_key( (string) $asset );
							}
						}
					} catch ( Throwable $error ) {
						self::$manifest = array();
						if ( class_exists( 'Delicat_Builder_V9_Renderer' ) && is_callable( array( 'Delicat_Builder_V9_Renderer', 'runtime_failure' ) ) ) {
							Delicat_Builder_V9_Renderer::runtime_failure(
								'performance_detection',
								$error,
								array( 'page_id' => $page_id )
							);
						}
						// Optimization failure must never become a frontend fatal.
					}
				}
			}
		}

		if ( class_exists( 'Delicat_Builder_V9_Woo_UI' ) ) {
			if ( is_callable( array( 'Delicat_Builder_V9_Woo_UI', 'archive_active' ) ) && Delicat_Builder_V9_Woo_UI::archive_active() ) {
				self::$critical_keys[] = 'woo-archive';
			}
			if ( is_callable( array( 'Delicat_Builder_V9_Woo_UI', 'single_active' ) ) && Delicat_Builder_V9_Woo_UI::single_active() ) {
				self::$critical_keys[] = 'woo-single';
			}
		}

		if ( class_exists( 'Delicat_Builder_V9_Purchase_UI' ) ) {
			if ( is_callable( array( 'Delicat_Builder_V9_Purchase_UI', 'single_active' ) ) && Delicat_Builder_V9_Purchase_UI::single_active() ) {
				self::$critical_keys[] = 'purchase-single';
			}
			if ( is_callable( array( 'Delicat_Builder_V9_Purchase_UI', 'cart_active' ) ) && Delicat_Builder_V9_Purchase_UI::cart_active() ) {
				self::$critical_keys[] = 'purchase-cart';
			}
			if ( is_callable( array( 'Delicat_Builder_V9_Purchase_UI', 'checkout_active' ) ) && Delicat_Builder_V9_Purchase_UI::checkout_active() ) {
				self::$critical_keys[] = 'purchase-checkout';
			}
		}

		self::$critical_keys = array_values( array_unique( self::$critical_keys ) );
	}

	private static function managed_homepage_request(): bool {
		return self::$builder_page_id > 0
			&& function_exists( 'delicat_builder_v9_is_managed_page' )
			&& delicat_builder_v9_is_managed_page( self::$builder_page_id );
	}

	private static function ultra_archive_request(): bool {
		return class_exists( 'Delicat_Builder_V9_Woo_UI' )
			&& method_exists( 'Delicat_Builder_V9_Woo_UI', 'archive_fast_active' )
			&& Delicat_Builder_V9_Woo_UI::archive_fast_active();
	}

	private static function fast_single_request(): bool {
		/*
		 * Native Product Builder intentionally owns its product page without loading
		 * the generic Woo UI runtime. Treat that native builder surface as a fast
		 * single too so legacy emoji/oEmbed overhead is removed, while Woo's
		 * variation/cart scripts remain untouched below.
		 */
		if (
			function_exists( 'is_product' )
			&& is_product()
			&& class_exists( 'Delicat_Builder_V9_Native_Product' )
			&& is_callable( array( 'Delicat_Builder_V9_Native_Product', 'is_active_product' ) )
			&& Delicat_Builder_V9_Native_Product::is_active_product( get_queried_object_id() )
		) {
			return true;
		}

		if (
			! class_exists( 'Delicat_Builder_V9_Woo_UI' )
			|| ! is_callable( array( 'Delicat_Builder_V9_Woo_UI', 'single_active' ) )
			|| ! Delicat_Builder_V9_Woo_UI::single_active()
			|| ! is_callable( array( 'Delicat_Builder_V9_Woo_UI', 'effective_single_settings' ) )
		) {
			return false;
		}
		$settings = Delicat_Builder_V9_Woo_UI::effective_single_settings();
		return ! empty( $settings['single_fast_mode'] );
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( self::enabled() ) {
			$classes[] = 'delicat-performance-v8';
			if ( self::managed_homepage_request() ) {
				$classes[] = 'delicat-performance-ultra-home';
			}
			if ( self::ultra_archive_request() ) {
				$classes[] = 'delicat-performance-ultra-archive';
			}
			if ( self::fast_single_request() ) {
				$classes[] = 'delicat-performance-fast-single';
			}
		}
		return $classes;
	}

	public static function apply_managed_cleanup(): void {
		if ( ! self::enabled() || ( ! self::managed_homepage_request() && ! self::ultra_archive_request() && ! self::fast_single_request() ) ) {
			return;
		}

		// Modern iOS/Android/Desktop browsers render Unicode emoji natively.
		// Remove WordPress compatibility detection/style overhead only on the
		// managed Builder homepage.
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );

		// Metadata intended for legacy publishing clients is not needed by the
		// managed storefront page.
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
	}

	private static function guest_cart_cookie_has_items(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}
		if ( empty( $_COOKIE['woocommerce_items_in_cart'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return false;
		}
		return absint( wp_unslash( $_COOKIE['woocommerce_items_in_cart'] ) ) > 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	public static function dequeue_managed_noncritical(): void {
		if ( ! self::enabled() || ( ! self::managed_homepage_request() && ! self::ultra_archive_request() && ! self::fast_single_request() ) ) {
			return;
		}

		// Managed storefront surfaces do not host WordPress oEmbed content.
		wp_dequeue_script( 'wp-embed' );
		wp_dequeue_style( 'wp-emoji-styles' );

		/*
		 * RC39.11 live audit: the managed homepage contains navigation CTAs only,
		 * not Woo AJAX add-to-cart buttons. Do not boot Woo add-to-cart there. For
		 * logged-out visitors with no Woo cart cookie, cart-fragments is also pure
		 * overhead and can be skipped without initializing a Woo session.
		 */
		if ( self::managed_homepage_request() ) {
			wp_dequeue_script( 'wc-add-to-cart' );
			if ( ! self::guest_cart_cookie_has_items() ) {
				wp_dequeue_script( 'wc-cart-fragments' );
			}
		}

		// Native single-product fast mode stops here: only legacy publishing/emoji
		// overhead is removed. Woo variation/cart scripts remain untouched.
		if ( self::fast_single_request() && ! self::ultra_archive_request() ) {
			return;
		}

		if ( ! self::ultra_archive_request() ) {
			return;
		}

		// RC16's Ultra Fast archive CTA opens the product page. Woo's archive
		// AJAX add-to-cart runtime is therefore unnecessary on this surface.
		$woo_settings = (
			class_exists( 'Delicat_Builder_V9_Woo_UI' )
			&& is_callable( array( 'Delicat_Builder_V9_Woo_UI', 'effective_archive_settings' ) )
		) ? Delicat_Builder_V9_Woo_UI::effective_archive_settings() : array();
		if ( 'product' === ( $woo_settings['archive_cta_mode'] ?? 'product' ) ) {
			wp_dequeue_script( 'wc-add-to-cart' );
		}

		// Cart fragments are useful only when a real cart needs live fragment
		// synchronization. For a logged-out empty cart, avoid booting the
		// fragments runtime on a public archive.
		if ( ! self::guest_cart_cookie_has_items() ) {
			wp_dequeue_script( 'wc-cart-fragments' );
		}
	}

	public static function predictive_enabled_for_request(): bool {
		if ( ! self::enabled() || empty( self::settings()['predictive_navigation'] ) ) {
			return false;
		}

		// Personalized or action-heavy commerce pages are intentionally excluded.
		$purchase_cart = false;
		$purchase_checkout = false;
		if ( class_exists( 'Delicat_Builder_V9_Purchase_UI' ) ) {
			$purchase_cart = is_callable( array( 'Delicat_Builder_V9_Purchase_UI', 'cart_active' ) ) && Delicat_Builder_V9_Purchase_UI::cart_active();
			$purchase_checkout = is_callable( array( 'Delicat_Builder_V9_Purchase_UI', 'checkout_active' ) ) && Delicat_Builder_V9_Purchase_UI::checkout_active();
		}
		if (
			$purchase_cart
			|| $purchase_checkout
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
		) {
			return false;
		}

		return true;
	}

	public static function predictive_config(): array {
		$s = self::settings();
		$intent = min( 350, max( 40, absint( $s['intent_delay_ms'] ?? 140 ) ) );
		$budget = min( 12, max( 1, absint( $s['prefetch_budget'] ?? 3 ) ) );
		$max_bytes = min( 1048576, max( 131072, absint( $s['prefetch_max_bytes'] ?? 262144 ) ) );

		if ( self::managed_homepage_request() ) {
			// Product-card swipes are excluded client-side. Keep the remaining
			// page warming conservative so LCP images keep bandwidth priority.
			$intent = max( 140, $intent );
			$budget = min( 2, $budget );
			$max_bytes = min( 262144, $max_bytes );
		} elseif ( self::ultra_archive_request() ) {
			// Archive cards are direct navigation surfaces. A slightly faster
			// intent window makes taps feel immediate while keeping a small
			// low-priority budget.
			$intent = min( 100, max( 55, $intent ) );
			$budget = min( 4, max( 2, $budget ) );
			$max_bytes = min( 393216, $max_bytes );
		}

		return array(
			'predictiveMode'       => self::predictive_enabled_for_request(),
			'intentDelay'          => $intent,
			'prefetchBudget'       => $budget,
			'prefetchMaxBytes'     => $max_bytes,
			'networkAware'         => ! empty( $s['network_aware'] ),
			'instantProductLaunch' => ! empty( $s['instant_product_launch'] ),
			'instantTouchDelay'    => min( 80, max( 0, absint( $s['instant_touch_delay_ms'] ?? 24 ) ) ),
		);
	}

	private static function critical_source_map(): array {
		/*
		 * RC39.5 fatal fix: do not create executable closures here. The previous
		 * arrow-function source was the last recorded fatal location on production.
		 * Build a plain path table instead and tolerate a missing bootstrap constant.
		 */
		$base = defined( 'DELICAT_BUILDER_V9_DIR' ) ? (string) DELICAT_BUILDER_V9_DIR : dirname( __DIR__ ) . '/';
		$base = trailingslashit( $base );
		$component = $base . 'assets/components/';
		$critical  = $base . 'assets/critical/';

		return array(
			'component:base'           => $component . 'base.css',
			'component:hero'           => $component . 'hero.css',
			'component:text'           => $component . 'text.css',
			'component:banner'         => $component . 'banner.css',
			'component:products'       => $critical . 'products-home.css',
			'component:category-chips' => $component . 'category-chips.css',
			'component:how-it-works'   => $component . 'how-it-works.css',
			'component:why-delicat'    => $component . 'why-delicat.css',
			'component:favorites'      => $component . 'favorites.css',
			'component:bon-kliyan'     => $component . 'bon-kliyan.css',
			'component:testimonials'   => $component . 'testimonials.css',
			'component:faq'            => $component . 'faq.css',
			'component:newsletter'     => $component . 'newsletter.css',
			'component:trust-strip'    => $component . 'trust-strip.css',
			'component:spacer'         => $component . 'spacer.css',
			'shell'                    => $critical . 'shell.css',
			'woo-archive'              => $critical . 'woo-archive.css',
			'woo-single'               => $critical . 'woo-single.css',
			'purchase-single'          => $critical . 'purchase-single.css',
			'purchase-cart'            => $critical . 'purchase-cart.css',
			'purchase-checkout'        => $critical . 'purchase-checkout.css',
		);
	}

	private static function minify_css( string $css ): string {
		$css = preg_replace( '#/\*[^!][\s\S]*?\*/#', '', $css );
		$css = preg_replace( '/\s+/', ' ', (string) $css );
		$css = preg_replace( '/\s*([{}:;,>])\s*/', '$1', (string) $css );
		return trim( (string) $css );
	}

	public static function critical_css(): string {
		if ( ! self::enabled() || empty( self::settings()['critical_css'] ) || empty( self::$critical_keys ) ) {
			return '';
		}

		$budget = min( 20000, max( self::MIN_CRITICAL_BYTES, absint( self::settings()['critical_max_bytes'] ?? 10000 ) ) );
		// Preserve discovery order because CSS cascade order is intentional.
		$keys = array_values( array_unique( array_map( 'strval', self::$critical_keys ) ) );

		/*
		 * RC39.11: critical CSS is assembled only from plugin-owned, allow-listed
		 * files, so the result is safe to cache across anonymous requests. The
		 * cache key includes the plugin version, selected source keys and byte
		 * budget. This removes repeated filesystem reads + regex minification
		 * from homepage/product/archive TTFB without caching user-specific data.
		 */
		$cache_key = 'dbv9_cc_' . md5( DELICAT_BUILDER_V9_VERSION . '|' . implode( ',', $keys ) . '|' . (string) $budget );
		if ( is_array( self::$critical_runtime_cache ) && isset( self::$critical_runtime_cache[ $cache_key ] ) ) {
			return (string) self::$critical_runtime_cache[ $cache_key ];
		}

		$external_object_cache = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		$cached = wp_cache_get( $cache_key, 'delicat_builder_v9_critical' );
		if ( false === $cached && ! $external_object_cache ) {
			$cached = get_transient( $cache_key );
		}
		if ( is_string( $cached ) ) {
			$value = '__DBV9_EMPTY__' === $cached ? '' : $cached;
			if ( ! is_array( self::$critical_runtime_cache ) ) {
				self::$critical_runtime_cache = array();
			}
			self::$critical_runtime_cache[ $cache_key ] = $value;
			return $value;
		}

		$map = self::critical_source_map();
		$output = '';

		foreach ( $keys as $key ) {
			if ( ! isset( $map[ $key ] ) ) {
				continue;
			}

			$path = wp_normalize_path( $map[ $key ] );
			if (
				0 !== strpos( $path, wp_normalize_path( DELICAT_BUILDER_V9_DIR ) )
				|| ! is_file( $path )
				|| ! is_readable( $path )
			) {
				continue;
			}

			$raw = file_get_contents( $path );
			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				continue;
			}

			$chunk = self::minify_css( $raw );
			if ( '' === $chunk ) {
				continue;
			}

			if ( strlen( $output ) + strlen( $chunk ) > $budget ) {
				continue;
			}

			$output .= $chunk;
		}

		$stored = '' === $output ? '__DBV9_EMPTY__' : $output;
		wp_cache_set( $cache_key, $stored, 'delicat_builder_v9_critical', DAY_IN_SECONDS );
		if ( ! $external_object_cache ) {
			set_transient( $cache_key, $stored, DAY_IN_SECONDS );
		}
		if ( ! is_array( self::$critical_runtime_cache ) ) {
			self::$critical_runtime_cache = array();
		}
		self::$critical_runtime_cache[ $cache_key ] = $output;

		return $output;
	}

	public static function print_critical_css(): void {
		$css = self::critical_css();
		if ( '' === $css ) {
			return;
		}

		echo '<style id="delicat-builder-v9-critical">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function save_data_requested(): bool {
		$value = isset( $_SERVER['HTTP_SAVE_DATA'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SAVE_DATA'] ) ) ) : '';
		return 'on' === $value;
	}

	public static function print_high_confidence_preloads(): void {
		if (
			! self::enabled()
			|| empty( self::settings()['high_confidence_preloads'] )
			|| self::save_data_requested()
		) {
			return;
		}

		$urls = array();

		if (
			! self::managed_homepage_request()
			&& class_exists( 'Delicat_Builder_V9_Shell' )
			&& is_callable( array( 'Delicat_Builder_V9_Shell', 'header_expected' ) )
			&& is_callable( array( 'Delicat_Builder_V9_Shell', 'needs_shell_js' ) )
			&& Delicat_Builder_V9_Shell::header_expected()
			&& Delicat_Builder_V9_Shell::needs_shell_js()
		) {
			// RC35: on the managed homepage the hero/LCP resource keeps network
			// priority. Shell JS is still deferred normally, just not preloaded.
			$path = DELICAT_BUILDER_V9_DIR . 'assets/js/shell.js';
			if ( is_file( $path ) ) {
				$urls[] = add_query_arg(
					'ver',
					rawurlencode( DELICAT_BUILDER_V9_VERSION ),
					DELICAT_BUILDER_V9_URL . 'assets/js/shell.js'
				);
			}
		}

		foreach ( array_slice( array_values( array_unique( $urls ) ), 0, 2 ) as $url ) {
			echo '<link rel="preload" as="script" href="' . esc_url( $url ) . '" fetchpriority="high">' . "\n";
		}
	}

	public static function self_test(): array {
		$map = self::critical_source_map();
		$required = array( 'component:base', 'shell', 'woo-archive', 'woo-single' );
		$missing = array();
		foreach ( $required as $key ) {
			if ( empty( $map[ $key ] ) || ! is_file( $map[ $key ] ) ) {
				$missing[] = $key;
			}
		}

		return array(
			'ok'      => empty( $missing ),
			'missing' => $missing,
			'php'     => PHP_VERSION,
		);
	}

	public static function diagnostic_graph(): array {
		return array(
			'builder_page_id' => self::$builder_page_id,
			'critical_keys'   => self::$critical_keys,
			'manifest_graph'  => is_array( self::$manifest['resource_graph'] ?? null )
				? self::$manifest['resource_graph']
				: array(),
			'critical_bytes'  => strlen( self::critical_css() ),
		);
	}
}
