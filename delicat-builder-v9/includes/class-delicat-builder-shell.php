<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight application shell.
 *
 * Application Shell safety model (preserved in Beta 6):
 * - auto injection is opt-in;
 * - the default scope is Delicat Builder pages only;
 * - sensitive WooCommerce pages are excluded from automatic injection;
 * - read-only search/cart helpers are same-origin, nonce-checked and rate-limited;
 * - cart/order/payment mutations stay entirely WooCommerce-controlled;
 * - navigation/menu output stays WordPress-controlled.
 */
final class Delicat_Builder_V9_Shell {
	public const OPTION = 'delicat_builder_v9_shell';

	private static bool $header_rendered = false;
	private static bool $header_shortcode_present = false;
	private static bool $mobile_nav_rendered = false;
	private static bool $late_mobile_fallback = false;
	private static ?array $settings_cache = null;

	public static function boot(): void {
		add_shortcode( 'delicat_app_header', array( __CLASS__, 'header_shortcode' ) );
		add_shortcode( 'delicat_app_footer', array( __CLASS__, 'footer_shortcode' ) );

		add_action( 'wp', array( __CLASS__, 'detect_shortcodes' ), 5 );
		add_action( 'wp_body_open', array( __CLASS__, 'auto_header' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'auto_mobile_fallback' ), 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_mobile_nav_assets' ), 80 );
		add_action( 'wp_head', array( __CLASS__, 'theme_boot' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_config' ), 35 );
		add_action( 'wp_ajax_delicat_builder_v9_product_search', array( __CLASS__, 'ajax_product_search' ) );
		add_action( 'wp_ajax_nopriv_delicat_builder_v9_product_search', array( __CLASS__, 'ajax_product_search' ) );
		add_action( 'wp_ajax_delicat_builder_v9_cart_snapshot', array( __CLASS__, 'ajax_cart_snapshot' ) );
		add_action( 'wp_ajax_nopriv_delicat_builder_v9_cart_snapshot', array( __CLASS__, 'ajax_cart_snapshot' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	public static function defaults(): array {
		return array(
			'enabled'           => 1,
			'scope'             => 'all_frontend',
			'header_enabled'    => 0,
			'logo_id'           => 0,
			'menu_id'           => 0,
			'sticky'            => 1,
			'show_search'       => 1,
			'search_suggestions'=> 1,
			'search_categories' => 1,
			'search_limit'      => 6,
			'popular_searches'  => "Free Fire\nRoblox\nNetflix\nPUBG Mobile\nRazer Gold\nCapCut",
			'show_notifications'=> 1,
			'show_cart'         => 1,
			'cart_drawer'       => 1,
			'show_account'      => 1,
			'show_theme_toggle' => 1,
			'mobile_bottom_nav' => 0,
			'mobile_nav_scope'  => 'all_frontend',
			'theme_default'     => 'light',
			'accent_color'      => '#6d5dfc',
			'max_width'         => 1320,
			'announcement_text' => '',
			'announcement_url'  => '',
			'mobile_menu_label' => __( 'Menu', 'delicat-builder-v9' ),
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

	public static function reset_settings_cache(): void {
		self::$settings_cache = null;
	}

	/**
	 * Header Studio v8 is the primary Delicat header runtime. The optional V9
	 * application shell must never auto-inject a second header around it.
	 * The header shortcode remains compatible; the retired footer shortcode is
	 * a no-op because the universal owner renders at the document boundary.
	 */
	private static function header_v8_main_engine(): bool {
		return class_exists( 'Delicat_Builder_V9_Header_Studio_8', false );
	}

	private static function is_checkout_flow(): bool {
		return function_exists( 'is_checkout' ) && is_checkout();
	}

	public static function detect_shortcodes(): void {
		if ( is_admin() ) {
			return;
		}

		global $post;
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$content = (string) $post->post_content;
		self::$header_shortcode_present = has_shortcode( $content, 'delicat_app_header' );
	}

	public static function header_expected(): bool {
		return Delicat_Builder_V9_Core::is_enabled() && ( self::$header_shortcode_present || self::will_render_header() || self::mobile_nav_expected() );
	}

	/**
	 * RC18: RC14 retired the Shell footer but Assets (and possibly third-party
	 * code) still probe this method; its absence was a "Call to undefined
	 * method" fatal. The Universal Footer is the only footer owner now, so this
	 * reports whether that owner will render on the current request.
	 */
	public static function footer_expected(): bool {
		return Delicat_Builder_V9_Core::is_enabled()
			&& class_exists( 'Delicat_Builder_V9_Footer', false )
			&& is_callable( array( 'Delicat_Builder_V9_Footer', 'is_enabled' ) )
			&& Delicat_Builder_V9_Footer::is_enabled();
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( self::header_expected() || self::mobile_nav_expected() ) {
			$classes[] = 'delicat-app-shell-active';
		}
		if ( self::mobile_nav_expected() ) {
			$classes[] = 'delicat-shell-mobile-nav-active';
		}
		return array_values( array_unique( $classes ) );
	}

	public static function is_sensitive_woocommerce_page(): bool {
		return ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );
	}

	private static function basic_request_allows(): bool {
		return Delicat_Builder_V9_Core::is_enabled()
			&& ! is_admin()
			&& ! wp_doing_ajax()
			&& ! is_feed()
			&& ! is_embed();
	}

	private static function base_request_allows(): bool {
		return self::basic_request_allows() && ! Delicat_Builder_V9_Core::is_safe_mode();
	}

	private static function scope_allows(): bool {
		if ( ! self::base_request_allows() ) {
			return false;
		}

		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || self::is_sensitive_woocommerce_page() ) {
			return false;
		}

		if ( 'all_frontend' === $settings['scope'] ) {
			return true;
		}

		if ( ! is_singular( 'page' ) ) {
			return false;
		}

		$page_id = (int) get_queried_object_id();
		if ( $page_id <= 0 ) {
			return false;
		}
		/* RC18: Pages is a route-loaded module; never assume it was parsed here. */
		if ( class_exists( 'Delicat_Builder_V9_Pages', false ) && is_callable( array( 'Delicat_Builder_V9_Pages', 'is_enabled_for_page' ) ) ) {
			return (bool) Delicat_Builder_V9_Pages::is_enabled_for_page( $page_id );
		}
		return (bool) get_post_meta( $page_id, '_delicat_builder_v9_enabled', true );
	}

	public static function mobile_nav_expected(): bool {
		/* RC29: the fixed bottom bar is retired. The header drawer (account, wallet,
		 * shop, cart, orders) replaces every entry it carried; nothing reserves
		 * bottom padding or offsets docks/launchers for it any more. */
		return false;
	}

	private static function mobile_nav_expected_legacy(): bool {
		/*
		 * RC39.3 recovery path: the navigation bar remains available in Safe Mode
		 * as a links-only shell. This lets administrators keep the storefront usable
		 * while a heavier frontend integration is quarantined.
		 */
		if ( ! self::basic_request_allows() ) {
			return false;
		}

		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['mobile_bottom_nav'] ) || self::is_checkout_flow() ) {
			return false;
		}

		if ( Delicat_Builder_V9_Core::is_safe_mode() ) {
			return true;
		}

		if ( 'follow_shell' === ( $settings['mobile_nav_scope'] ?? 'all_frontend' ) ) {
			return self::scope_allows();
		}

		return true;
	}

	public static function will_render_header(): bool {
		$settings = self::settings();
		if ( self::header_v8_main_engine() ) {
			return false;
		}
		return self::scope_allows() && ! empty( $settings['header_enabled'] );
	}

	public static function needs_theme_boot(): bool {
		// RC51.9: establish the palette before paint on every V9 frontend page.
		return Delicat_Builder_V9_Core::is_enabled() && ! is_admin();
	}

	public static function needs_theme(): bool {
		$settings = self::settings();
		return ( self::$header_shortcode_present || self::will_render_header() ) && ! empty( $settings['show_theme_toggle'] );
	}

	public static function needs_shell_js(): bool {
		$settings = self::settings();
		$full_header_surface = self::$header_shortcode_present || self::will_render_header();
		if ( ! $full_header_surface ) {
			return false;
		}

		/*
		 * RC39.5 Safe Search: Safe Mode still quarantines cart/Identity/full shell,
		 * but the read-only product finder is deliberately allowed to run. This
		 * keeps the storefront usable while a separate module is being diagnosed.
		 */
		if ( Delicat_Builder_V9_Core::is_safe_mode() ) {
			return self::mobile_nav_expected() && ! empty( $settings['show_search'] );
		}

		return ! empty( $settings['menu_id'] )
			|| ! empty( $settings['show_search'] )
			|| ( ! empty( $settings['show_cart'] ) && ! empty( $settings['cart_drawer'] ) );
	}

	/**
	 * Header Studio v8 + the standalone mobile dock do not need the 27 KB Shell
	 * stylesheet. Load it only for an explicit/full V9 shell surface.
	 */
	public static function needs_full_shell_css(): bool {
		return self::$header_shortcode_present
			|| self::will_render_header()
			|| self::needs_shell_js();
	}

	public static function enqueue_mobile_nav_assets(): void {
		if ( ! self::mobile_nav_expected() ) {
			return;
		}

		$path = DELICAT_BUILDER_V9_DIR . 'assets/css/mobile-dock.css';
		if ( ! is_file( $path ) ) {
			return;
		}

		wp_enqueue_style(
			'delicat-builder-v9-mobile-dock',
			DELICAT_BUILDER_V9_URL . 'assets/css/mobile-dock.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);

		$script_path = DELICAT_BUILDER_V9_DIR . 'assets/js/mobile-dock.js';
		if ( is_file( $script_path ) ) {
			wp_enqueue_script(
				'delicat-builder-v9-mobile-dock',
				DELICAT_BUILDER_V9_URL . 'assets/js/mobile-dock.js',
				array(),
				DELICAT_BUILDER_V9_VERSION,
				true
			);
			if ( function_exists( 'wp_script_add_data' ) ) {
				wp_script_add_data( 'delicat-builder-v9-mobile-dock', 'strategy', 'defer' );
			}
		}
	}

	public static function frontend_config(): void {
		if ( is_admin() || ! self::header_expected() || ! self::needs_shell_js() ) {
			return;
		}

		$handle = 'delicat-builder-v9-shell';
		if ( ! wp_script_is( $handle, 'enqueued' ) && ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}

		$settings = self::settings();
		$cache_version = class_exists( 'Delicat_Builder_V9_Cache' ) ? Delicat_Builder_V9_Cache::version() : 1;
		$config = array(
			'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
			'nonce'               => wp_create_nonce( 'delicat_builder_v9_shell_interaction' ),
			'searchSuggestions'   => ! empty( $settings['show_search'] ) && ! empty( $settings['search_suggestions'] ),
			'searchLimit'         => min( 8, max( 4, absint( $settings['search_limit'] ?? 6 ) ) ),
			'cartDrawer'          => ! Delicat_Builder_V9_Core::is_safe_mode() && ! empty( $settings['show_cart'] ) && ! empty( $settings['cart_drawer'] ) && class_exists( 'WooCommerce' ),
			'safeSearchMode'      => Delicat_Builder_V9_Core::is_safe_mode(),
			'minQuery'            => 2,
			'recentKey'           => 'delicat-builder-v9-recent-searches',
			// Include the plugin build so browser session caches are invalidated on
			// search-format hotfixes even when the product cache version is unchanged.
			'searchCacheKey'      => 'delicat-builder-v9-search-cache-' . $cache_version . '-' . DELICAT_BUILDER_V9_VERSION,
			'cacheVersion'        => $cache_version,
			'requestTimeout'      => 4500,
			'strings'             => array(
				'loading'       => __( 'Chargement…', 'delicat-builder-v9' ),
				'noResults'     => __( 'Aucun produit trouvé.', 'delicat-builder-v9' ),
				'searchError'   => __( 'Recherche temporairement indisponible. Vous pouvez toujours lancer la recherche complète.', 'delicat-builder-v9' ),
				'cartError'     => __( 'Impossible de charger le panier maintenant.', 'delicat-builder-v9' ),
				'emptyCart'     => __( 'Votre panier est vide.', 'delicat-builder-v9' ),
				'quantity'      => __( 'Qté', 'delicat-builder-v9' ),
				'recent'        => __( 'Recherches récentes', 'delicat-builder-v9' ),
			),
		);

		wp_add_inline_script(
			$handle,
			'window.DelicaShellV9=' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	private static function rate_limit_allowed( string $bucket, int $limit, int $window ): bool {
		$limit  = max( 10, min( 240, $limit ) );
		$window = max( 10, min( 300, $window ) );
		$user_id = get_current_user_id();
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$actor = $user_id > 0 ? 'u:' . $user_id : 'ip:' . $remote;
		$key = 'dbv9rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . $actor, wp_salt( 'nonce' ) ), 0, 30 );
		$now = time();
		$state = get_transient( $key );

		if ( ! is_array( $state ) || empty( $state['started'] ) || $now - absint( $state['started'] ) >= $window ) {
			set_transient( $key, array( 'started' => $now, 'count' => 1 ), $window );
			return true;
		}

		$count = absint( $state['count'] ?? 0 );
		if ( $count >= $limit ) {
			return false;
		}

		$state['count'] = $count + 1;
		set_transient( $key, $state, max( 1, $window - ( $now - absint( $state['started'] ) ) ) );
		return true;
	}

	private static function plain_price( string $html ): string {
		$text = wp_strip_all_tags( $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) ?: $text );
	}

	/**
	 * Search suggestions need a compact, visual-only price string.
	 *
	 * get_price_html() can contain accessibility text added by WooCommerce or a
	 * currency plugin (for example "Price range:"). Stripping HTML alone makes
	 * that hidden text visible and caused the duplicated/overflowing price seen
	 * in the mobile search sheet. Build the display value from Woo's numeric
	 * product prices instead, while WooCommerce remains the price authority.
	 */
	private static function search_price( WC_Product $product ): string {
		if ( ! function_exists( 'wc_price' ) ) {
			return '';
		}

		if ( $product->is_type( 'variable' ) && $product instanceof WC_Product_Variable ) {
			$min = (float) $product->get_variation_price( 'min', true );
			$max = (float) $product->get_variation_price( 'max', true );
			if ( $min <= 0 && $max <= 0 ) {
				return '';
			}
			$min_text = self::plain_price( wc_price( $min ) );
			$max_text = self::plain_price( wc_price( $max ) );
			return $max > $min ? $min_text . ' – ' . $max_text : $min_text;
		}

		$raw_price = $product->get_price();
		if ( '' === $raw_price || ! is_numeric( $raw_price ) ) {
			return '';
		}
		$display_price = function_exists( 'wc_get_price_to_display' )
			? wc_get_price_to_display( $product, array( 'price' => (float) $raw_price ) )
			: (float) $raw_price;

		return self::plain_price( wc_price( $display_price ) );
	}

	public static function ajax_product_search(): void {
		if ( ! Delicat_Builder_V9_Core::is_enabled() || ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Search unavailable.', 'delicat-builder-v9' ) ), 503 );
		}

		check_ajax_referer( 'delicat_builder_v9_shell_interaction', 'nonce' );
		$settings = self::settings();
		if ( empty( $settings['show_search'] ) || empty( $settings['search_suggestions'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Search suggestions are disabled.', 'delicat-builder-v9' ) ), 403 );
		}
		$search_rate = Delicat_Builder_V9_Core::is_safe_mode() ? 90 : 120;
		if ( ! self::rate_limit_allowed( 'search', $search_rate, 60 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many searches. Please wait a moment.', 'delicat-builder-v9' ) ), 429 );
		}

		$query = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$query = trim( preg_replace( '/\s+/u', ' ', $query ) ?: $query );
		if ( function_exists( 'mb_substr' ) ) {
			$query = function_exists( 'mb_substr' ) ? mb_substr( $query, 0, 60 ) : substr( $query, 0, 60 );
		} else {
			$query = substr( $query, 0, 60 );
		}
		$query_len = function_exists( 'mb_strlen' ) ? mb_strlen( $query ) : strlen( $query );
		if ( $query_len < 2 ) {
			wp_send_json_success( array( 'query' => $query, 'results' => array() ) );
		}

		$limit = min( 8, max( 4, absint( $settings['search_limit'] ?? 6 ) ) );
		$normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
		$cache_version = class_exists( 'Delicat_Builder_V9_Cache' ) ? Delicat_Builder_V9_Cache::version() : 1;
		// Bump the format key whenever the suggestion payload shape/format changes
		// so a stale server cache cannot reintroduce old malformed prices.
		$cache_key = 'v411_' . md5( $normalized . '|' . get_locale() . '|' . $limit . '|' . $cache_version );
		$external_object_cache = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		$cached = wp_cache_get( $cache_key, 'delicat_builder_v9_search' );
		if ( ! is_array( $cached ) && ! $external_object_cache ) {
			$cached = get_transient( 'dbv9_search_' . $cache_key );
		}
		if ( is_array( $cached ) ) {
			nocache_headers();
			wp_send_json_success( array( 'query' => $query, 'results' => $cached, 'cached' => true ) );
		}

		/* RC51.63: uncached searches hit the database; throttle per client. */
		if ( class_exists( 'Delicat_Builder_V9_Security', false ) && ! Delicat_Builder_V9_Security::rate_limit_allowed( 'product_search', 60, 60 ) ) {
			wp_send_json_error( array( 'message' => 'Trop de requêtes.' ), 429 );
		}

		global $wpdb;
		$contains = '%' . $wpdb->esc_like( $query ) . '%';
		$prefix = $wpdb->esc_like( $query ) . '%';
		$fetch_limit = min( 30, max( 12, $limit * 3 ) );
		$ids = array();

		/*
		 * Fast path: product slugs are indexed by WordPress and commonly mirror
		 * customer search terms (for example "Free Fire" -> "free-fire").
		 * Use that prefix first; only fall back to the broader title contains
		 * query when it cannot fill the visible suggestion list.
		 */
		$slug_query = sanitize_title( $query );
		if ( '' !== $slug_query ) {
			$slug_prefix = $wpdb->esc_like( $slug_query ) . '%';
			$slug_sql = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'product' AND post_status = 'publish' AND post_name LIKE %s
				 ORDER BY menu_order ASC, post_title ASC
				 LIMIT %d",
				$slug_prefix,
				$fetch_limit
			);
			$ids = array_values( array_map( 'absint', (array) $wpdb->get_col( $slug_sql ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( count( $ids ) < $limit ) {
			$title_sql = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'product' AND post_status = 'publish' AND post_title LIKE %s
				 ORDER BY CASE WHEN post_title LIKE %s THEN 0 ELSE 1 END, menu_order ASC, post_title ASC
				 LIMIT %d",
				$contains,
				$prefix,
				$fetch_limit
			);
			$title_ids = array_values( array_map( 'absint', (array) $wpdb->get_col( $title_sql ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ids = array_values( array_unique( array_merge( $ids, $title_ids ) ) );
		}
		if ( ! empty( $ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, true, true );
		}

		$results = array();
		foreach ( $ids as $product_id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
				continue;
			}
			$url = get_permalink( $product_id );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$image_id = $product->get_image_id();
			$image = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
			if ( ! $image && function_exists( 'wc_placeholder_img_src' ) ) {
				$image = wc_placeholder_img_src( 'woocommerce_thumbnail' );
			}
			$categories = get_the_terms( $product_id, 'product_cat' );
			$category = is_array( $categories ) && ! empty( $categories ) ? (string) $categories[0]->name : '';

			$results[] = array(
				'id'       => $product_id,
				'name'     => sanitize_text_field( $product->get_name() ),
				'url'      => esc_url_raw( $url ),
				'image'    => esc_url_raw( (string) $image ),
				'price'    => self::search_price( $product ),
				'category' => sanitize_text_field( $category ),
				'in_stock' => $product->is_in_stock(),
			);
			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		wp_cache_set( $cache_key, $results, 'delicat_builder_v9_search', 30 * MINUTE_IN_SECONDS );
		if ( ! $external_object_cache ) {
			set_transient( 'dbv9_search_' . $cache_key, $results, 30 * MINUTE_IN_SECONDS );
		}
		nocache_headers();
		wp_send_json_success( array( 'query' => $query, 'results' => $results, 'cached' => false ) );
	}

	public static function ajax_cart_snapshot(): void {
		if ( ! Delicat_Builder_V9_Core::is_enabled() || Delicat_Builder_V9_Core::is_safe_mode() || ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Cart unavailable.', 'delicat-builder-v9' ) ), 503 );
		}

		check_ajax_referer( 'delicat_builder_v9_shell_interaction', 'nonce' );
		$settings = self::settings();
		if ( empty( $settings['show_cart'] ) || empty( $settings['cart_drawer'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Cart drawer is disabled.', 'delicat-builder-v9' ) ), 403 );
		}
		if ( ! self::rate_limit_allowed( 'cart', 60, 60 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many cart requests. Please wait a moment.', 'delicat-builder-v9' ) ), 429 );
		}

		if ( function_exists( 'wc_load_cart' ) && ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) ) {
			wc_load_cart();
		}
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! $wc || ! $wc->cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart session unavailable.', 'delicat-builder-v9' ) ), 503 );
		}

		$items = array();
		foreach ( array_slice( $wc->cart->get_cart(), 0, 8, true ) as $cart_item ) {
			$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ? $cart_item['data'] : false;
			$quantity = max( 1, absint( $cart_item['quantity'] ?? 1 ) );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$url = $product->is_visible() ? $product->get_permalink( $cart_item ) : '';
			$image_id = $product->get_image_id();
			if ( ! $image_id && $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				$image_id = $parent instanceof WC_Product ? $parent->get_image_id() : 0;
			}
			$image = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
			if ( ! $image && function_exists( 'wc_placeholder_img_src' ) ) {
				$image = wc_placeholder_img_src( 'woocommerce_thumbnail' );
			}

			$items[] = array(
				'name'      => sanitize_text_field( $product->get_name() ),
				'url'       => esc_url_raw( (string) $url ),
				'image'     => esc_url_raw( (string) $image ),
				'quantity'  => $quantity,
				'lineTotal' => self::plain_price( $wc->cart->get_product_subtotal( $product, $quantity ) ),
			);
		}

		$count = max( 0, (int) $wc->cart->get_cart_contents_count() );
		$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
		$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $cart_url;
		$shop_url = self::shop_url();
		nocache_headers();
		wp_send_json_success(
			array(
				'count'       => $count,
				'items'       => $items,
				'subtotal'    => self::plain_price( $wc->cart->get_cart_subtotal() ),
				'cartUrl'     => esc_url_raw( (string) $cart_url ),
				'checkoutUrl' => esc_url_raw( (string) $checkout_url ),
				'shopUrl'     => esc_url_raw( (string) $shop_url ),
			)
		);
	}

	public static function self_test(): array {
		$settings = self::settings();
		$home = home_url( '/' );
		$search_ok = isset( $settings['show_search'], $settings['search_suggestions'] );
		return array(
			'ok'        => is_array( $settings ) && is_string( $home ) && '' !== $home && $search_ok,
			'search_ok' => $search_ok,
			'home_ok'   => is_string( $home ) && '' !== $home,
		);
	}

	public static function theme_boot(): void {
		// RC51.9: Header Runtime is always-on and owns the global pre-paint theme
		// boot. Keep this fallback only for contexts where Header Runtime is absent.
		if ( class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) ) {
			return;
		}
		if ( ! self::needs_theme_boot() ) {
			return;
		}

		$settings = self::settings();
		$default = in_array( $settings['theme_default'], array( 'light', 'dark', 'system' ), true )
			? $settings['theme_default']
			: 'light';
		$allow_saved = true;

		// Fixed plugin-owned script; only sanitized allow-list values are inserted.
		?>
		<script id="delicat-builder-v9-theme-boot">
		(function(){var r=document.documentElement,k='dbv9_theme',d=<?php echo wp_json_encode( $default ); ?>,a=<?php echo $allow_saved ? 'true' : 'false'; ?>,p=d,t='light';try{if(a){var s=decodeURIComponent((document.cookie.match(new RegExp('(?:^|;\\s*)'+k+'=([^;]*)'))||[])[1]||'');if(s==='light'||s==='dark'||s==='system'){p=s}}if(p==='dark'){t='dark'}else if(p==='system'){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light'}}catch(e){p=d;t=d==='dark'?'dark':'light'}r.dataset.delicatThemePreference=p;r.dataset.delicatTheme=t;r.classList.toggle('dlc-theme-dark',t==='dark');r.classList.toggle('dlc-theme-light',t==='light');r.style.colorScheme=t})();
		</script>
		<?php
	}

	private static function record_shell_failure( string $stage, Throwable $error ): void {
		$events = get_option( 'delicat_builder_v9_shell_failures', array() );
		$events = is_array( $events ) ? $events : array();
		$events[] = array(
			'time'  => gmdate( 'c' ),
			'stage' => sanitize_key( $stage ),
			'type'  => sanitize_text_field( get_class( $error ) ),
			'hash'  => hash( 'sha256', $stage . '|' . $error->getMessage() . '|' . $error->getFile() . '|' . $error->getLine() ),
		);
		update_option( 'delicat_builder_v9_shell_failures', array_slice( $events, -5 ), false );
	}

	private static function safe_header_markup( string $stage ): string {
		try {
			return self::render_header();
		} catch ( Throwable $error ) {
			self::record_shell_failure( $stage, $error );
			self::$header_rendered = false;
			try {
				return self::render_recovery_mobile_bar();
			} catch ( Throwable $fallback_error ) {
				self::record_shell_failure( $stage . '_fallback', $fallback_error );
				return '';
			}
		}
	}

	public static function auto_header(): void {
		if ( self::will_render_header() ) {
			echo self::safe_header_markup( 'auto_header' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public static function auto_mobile_fallback(): void {
		// The mobile dock is independent from the desktop header. Header Studio v8
		// remains the single header engine and the dock is emitted once at wp_footer.
		if ( ! self::mobile_nav_expected() ) {
			return;
		}

		echo self::render_mobile_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function header_shortcode(): string {
		return self::safe_header_markup( 'header_shortcode' );
	}

	public static function footer_shortcode(): string {
		/* The universal footer is emitted once at wp_footer, never inside page
		 * content where this retired shortcode may still be cached. */
		return '';
	}

	private static function menu_html( int $menu_id, string $class ): string {
		if ( $menu_id > 0 && wp_get_nav_menu_object( $menu_id ) ) {
			$html = wp_nav_menu(
				array(
					'menu'            => $menu_id,
					'container'       => false,
					'menu_class'      => $class,
					'menu_id'         => '',
					'echo'            => false,
					'fallback_cb'     => false,
					'depth'           => 2,
					'item_spacing'    => 'discard',
				)
			);
			return is_string( $html ) ? $html : '';
		}

		return '<ul class="' . esc_attr( $class ) . '"><li><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Accueil', 'delicat-builder-v9' ) . '</a></li></ul>';
	}

	private static function logo_html( int $logo_id ): string {
		$site_name = get_bloginfo( 'name' );

		$logo_is_image = $logo_id > 0 && (
			( class_exists( 'Delicat_Builder_V9_Media', false ) && is_callable( array( 'Delicat_Builder_V9_Media', 'is_image_attachment' ) ) )
				? Delicat_Builder_V9_Media::is_image_attachment( $logo_id )
				: wp_attachment_is_image( $logo_id )
		);
		if ( $logo_is_image ) {
			$image = wp_get_attachment_image(
				$logo_id,
				'medium',
				false,
				array(
					'class'         => 'delicat-shell__logo-image',
					'loading'       => 'eager',
					'decoding'      => 'async',
					'fetchpriority' => 'auto',
					'alt'           => $site_name,
				)
			);
			if ( $image ) {
				return '<a class="delicat-shell__brand" href="' . esc_url( home_url( '/' ) ) . '" aria-label="' . esc_attr( $site_name ) . '">' . $image . '</a>';
			}
		}

		return '<a class="delicat-shell__brand delicat-shell__brand--text" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $site_name ) . '</a>';
	}

	private static function cart_count(): int {
		// Logged-out pages are commonly full-page cached. Render a neutral zero for
		// guests and let shell.js hydrate the badge from WooCommerce's own cart
		// cookie. This prevents one visitor's server-rendered badge leaking through
		// a misconfigured shared page cache.
		if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return 0;
		}
		return max( 0, (int) WC()->cart->get_cart_contents_count() );
	}

	private static function account_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'myaccount' );
			if ( $url ) {
				return $url;
			}
		}
		return is_user_logged_in() ? admin_url( 'profile.php' ) : wp_login_url();
	}

	private static function cart_url(): string {
		if ( function_exists( 'wc_get_cart_url' ) ) {
			$url = wc_get_cart_url();
			if ( $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	private static function shop_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'shop' );
			if ( $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	private static function wallet_url(): string {
		$page = get_page_by_path( 'my-wallet', OBJECT, 'page' );
		if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
			return get_permalink( $page );
		}

		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			$url = wc_get_account_endpoint_url( 'woo-wallet' );
			if ( $url ) {
				return $url;
			}
		}

		return self::account_url();
	}

	private static function is_wallet_page(): bool {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'woo-wallet' ) ) {
			return true;
		}

		if ( is_singular( 'page' ) ) {
			$page = get_queried_object();
			if ( $page instanceof WP_Post && 'my-wallet' === $page->post_name ) {
				return true;
			}
		}

		return false;
	}

	private static function popular_search_shortcuts(): string {
		$settings = self::settings();
		if ( empty( $settings['search_categories'] ) ) {
			return '';
		}
		$raw = (string) ( $settings['popular_searches'] ?? '' );
		$terms = preg_split( '/[\r\n,]+/', $raw ) ?: array();
		$terms = array_values( array_filter( array_map( 'sanitize_text_field', $terms ) ) );
		$terms = array_slice( array_unique( $terms ), 0, 10 );
		if ( empty( $terms ) ) {
			return '';
		}

		$out = '<div class="delicat-shell__popular" data-delicat-search-popular>';
		$out .= '<strong class="delicat-shell__popular-label">↗ ' . esc_html__( 'RECHERCHES POPULAIRES', 'delicat-builder-v9' ) . '</strong>';
		$out .= '<div class="delicat-shell__popular-row">';
		foreach ( $terms as $term ) {
			$term = function_exists( 'mb_substr' ) ? mb_substr( $term, 0, 40 ) : substr( $term, 0, 40 );
			$out .= '<button type="button" class="delicat-shell__search-chip" data-delicat-search-term="' . esc_attr( $term ) . '">' . esc_html( $term ) . '</button>';
		}
		$out .= '</div></div>';
		return $out;
	}

	private static function search_category_shortcuts(): string {
		$settings = self::settings();
		if ( empty( $settings['search_categories'] ) || ! taxonomy_exists( 'product_cat' ) ) {
			return '';
		}

		$cache_key = class_exists( 'Delicat_Builder_V9_Cache' )
			? Delicat_Builder_V9_Cache::key( 'shell_search_categories', array( 'locale' => get_locale() ) )
			: 'dbv9_shell_search_categories_' . md5( get_locale() );
		$cached = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => 0,
				'number'     => 6,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			set_transient( $cache_key, '', 5 * MINUTE_IN_SECONDS );
			return '';
		}

		$out = '<div class="delicat-shell__search-shortcuts" aria-label="' . esc_attr__( 'Catégories populaires', 'delicat-builder-v9' ) . '">';
		foreach ( $terms as $term ) {
			$url = get_term_link( $term );
			if ( is_wp_error( $url ) ) {
				continue;
			}
			$out .= '<a class="delicat-shell__search-chip" href="' . esc_url( $url ) . '" data-delicat-prefetch>' . esc_html( $term->name ) . '</a>';
		}
		$out .= '</div>';
		set_transient( $cache_key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	private static function mobile_nav_item_class( string $item ): string {
		$active = false;
		switch ( $item ) {
			case 'home':
				$active = is_front_page() || is_home();
				break;
			case 'wallet':
				$active = self::is_wallet_page();
				break;
			case 'shop':
				$active = ( function_exists( 'is_shop' ) && is_shop() )
					|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
					|| ( function_exists( 'is_product' ) && is_product() );
				break;
			case 'cart':
				$active = function_exists( 'is_cart' ) && is_cart();
				break;
			case 'account':
				$active = function_exists( 'is_account_page' ) && is_account_page();
				break;
		}

		return 'delicat-shell__bottom-item' . ( $active ? ' is-active' : '' );
	}

	private static function icon( string $name ): string {
		$icons = array(
			'menu' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
			'home' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 10.5 12 3l8.5 7.5v9a1.5 1.5 0 0 1-1.5 1.5H5a1.5 1.5 0 0 1-1.5-1.5Z"/><path d="M9 21v-7h6v7"/></svg>',
			'wallet' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 9h18"/><path d="M15.5 13h3"/></svg>',
			'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>',
			'store' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h16l-1.2-4H5.2Z"/><path d="M5 9v10h14V9M8 19v-5h8v5"/><path d="M4 9c0 1.4 1 2.5 2.4 2.5S9 10.4 9 9c0 1.4 1.1 2.5 2.5 2.5S14 10.4 14 9c0 1.4 1.1 2.5 2.5 2.5S19 10.4 19 9"/></svg>',
			'account' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/></svg>',
			'cart' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.3 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.6L21 8H7"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>',
			'box' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9M8 5.25l8 4.5"/></svg>',
			'theme' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.8A8.2 8.2 0 0 1 9.2 3.5 8.5 8.5 0 1 0 20.5 14.8Z"/></svg>',
			'close' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>',
		);
		return $icons[ $name ] ?? '';
	}

	private static function render_mobile_nav(): string {
		if ( self::$mobile_nav_rendered || ! self::mobile_nav_expected() ) {
			return '';
		}
		self::$mobile_nav_rendered = true;

		$settings = self::settings();
		$accent = sanitize_hex_color( (string) ( $settings['accent_color'] ?? '' ) ) ?: '#c72e69';
		$cart_count = self::cart_count();

		ob_start();
		?>
		<nav class="delicat-shell__bottom-nav delicat-mobile-dock" aria-label="<?php esc_attr_e( 'Navigation mobile principale', 'delicat-builder-v9' ); ?>" style="--delicat-shell-accent:<?php echo esc_attr( $accent ); ?>" data-delicat-mobile-dock>
			<a class="<?php echo esc_attr( self::mobile_nav_item_class( 'home' ) ); ?>" href="<?php echo esc_url( home_url( '/' ) ); ?>"<?php echo ( is_front_page() || is_home() ) ? ' aria-current="page"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<span class="delicat-shell__bottom-icon"><?php echo self::icon( 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span><?php esc_html_e( 'Accueil', 'delicat-builder-v9' ); ?></span>
			</a>
			<a class="<?php echo esc_attr( self::mobile_nav_item_class( 'wallet' ) ); ?>" href="<?php echo esc_url( self::wallet_url() ); ?>"<?php echo self::is_wallet_page() ? ' aria-current="page"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<span class="delicat-shell__bottom-icon"><?php echo self::icon( 'wallet' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span><?php esc_html_e( 'Wallet', 'delicat-builder-v9' ); ?></span>
			</a>
			<a class="<?php echo esc_attr( self::mobile_nav_item_class( 'shop' ) ); ?> delicat-shell__bottom-item--center" href="<?php echo esc_url( self::shop_url() ); ?>"<?php echo ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) || ( function_exists( 'is_product' ) && is_product() ) ) ? ' aria-current="page"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<span class="delicat-shell__bottom-icon delicat-shell__bottom-icon--center"><?php echo self::icon( 'store' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span><?php esc_html_e( 'Boutique', 'delicat-builder-v9' ); ?></span>
			</a>
			<a class="<?php echo esc_attr( self::mobile_nav_item_class( 'cart' ) ); ?>" href="<?php echo esc_url( self::cart_url() ); ?>"<?php echo ( function_exists( 'is_cart' ) && is_cart() ) ? ' aria-current="page"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<span class="delicat-shell__bottom-icon">
					<?php echo self::icon( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="delicat-shell__bottom-count" data-delicat-cart-count<?php echo $cart_count > 0 ? '' : ' hidden'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( (string) $cart_count ); ?></span>
				</span>
				<span><?php esc_html_e( 'Panier', 'delicat-builder-v9' ); ?></span>
			</a>
			<a class="<?php echo esc_attr( self::mobile_nav_item_class( 'account' ) ); ?>" href="<?php echo esc_url( self::account_url() ); ?>"<?php echo ( function_exists( 'is_account_page' ) && is_account_page() ) ? ' aria-current="page"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<span class="delicat-shell__bottom-icon"><?php echo self::icon( 'account' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span><?php esc_html_e( 'Compte', 'delicat-builder-v9' ); ?></span>
			</a>
		</nav>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_recovery_mobile_bar(): string {
		return self::render_mobile_nav();
	}

	public static function render_header(): string {
		if ( ! Delicat_Builder_V9_Core::is_enabled() || self::$header_rendered ) {
			return '';
		}

		if ( Delicat_Builder_V9_Core::is_safe_mode() ) {
			return self::render_recovery_mobile_bar();
		}

		self::$header_rendered = true;

		$settings = self::settings();
		$accent   = sanitize_hex_color( (string) $settings['accent_color'] ) ?: '#6d5dfc';
		$max      = in_array( (int) $settings['max_width'], array( 1200, 1320, 1440 ), true ) ? (int) $settings['max_width'] : 1320;
		$sticky   = ! empty( $settings['sticky'] );
		$menu_id  = absint( $settings['menu_id'] );

		$classes = array( 'delicat-shell', 'delicat-shell--header' );
		if ( self::$late_mobile_fallback ) {
			$classes[] = 'delicat-shell--late-mobile-fallback';
		}
		if ( $sticky && ! self::$late_mobile_fallback ) {
			$classes[] = 'is-sticky';
		}

		ob_start();
		?>
		<header class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-delicat-shell-header style="--delicat-shell-accent:<?php echo esc_attr( $accent ); ?>;--delicat-shell-max:<?php echo esc_attr( (string) $max ); ?>px">
			<?php if ( '' !== trim( (string) $settings['announcement_text'] ) ) : ?>
				<div class="delicat-shell__announcement">
					<?php if ( '' !== trim( (string) $settings['announcement_url'] ) ) : ?>
						<a href="<?php echo esc_url( $settings['announcement_url'] ); ?>"><?php echo esc_html( $settings['announcement_text'] ); ?></a>
					<?php else : ?>
						<span><?php echo esc_html( $settings['announcement_text'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="delicat-shell__bar">
				<div class="delicat-shell__inner">
					<div class="delicat-shell__left">
						<?php echo self::logo_html( absint( $settings['logo_id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>

					<nav class="delicat-shell__desktop-nav" aria-label="<?php esc_attr_e( 'Navigation principale', 'delicat-builder-v9' ); ?>">
						<?php echo self::menu_html( $menu_id, 'delicat-shell__menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</nav>

					<div class="delicat-shell__actions">
						<?php if ( ! empty( $settings['show_search'] ) ) : ?>
							<button type="button" class="delicat-shell__icon-button" data-delicat-search-open aria-controls="delicat-shell-search" aria-expanded="false">
								<?php echo self::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span class="screen-reader-text"><?php esc_html_e( 'Rechercher', 'delicat-builder-v9' ); ?></span>
							</button>
						<?php endif; ?>

						<?php if (
							! empty( $settings['show_notifications'] )
							&& class_exists( 'Delicat_Builder_V9_Unified_Modules', false )
							&& Delicat_Builder_V9_Unified_Modules::module_active( 'notifications' )
							&& class_exists( 'Delicat_Builder_V9_Notifications', false )
						) : ?>
							<?php echo Delicat_Builder_V9_Notifications::render_bell(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>

						<?php if ( ! empty( $settings['show_theme_toggle'] ) ) : ?>
							<button type="button" class="delicat-shell__icon-button" data-delicat-theme-toggle aria-label="<?php esc_attr_e( 'Changer le thème', 'delicat-builder-v9' ); ?>">
								<?php echo self::icon( 'theme' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</button>
						<?php endif; ?>

						<?php if ( ! empty( $settings['show_account'] ) ) : ?>
							<?php
							$identity_login = ! is_user_logged_in() && class_exists( 'Delicat_Builder_V9_Identity_Bridge', false )
								? Delicat_Builder_V9_Identity_Bridge::render_shell_login_button( self::icon( 'account' ) )
								: '';
							?>
							<?php if ( '' !== $identity_login ) : ?>
								<?php echo $identity_login; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php else : ?>
								<a class="delicat-shell__icon-button" href="<?php echo esc_url( self::account_url() ); ?>" aria-label="<?php esc_attr_e( 'Mon compte', 'delicat-builder-v9' ); ?>">
									<?php echo self::icon( 'account' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</a>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( ! empty( $settings['show_cart'] ) && class_exists( 'WooCommerce' ) ) : ?>
							<?php $header_cart_count = self::cart_count(); ?>
							<?php if ( ! empty( $settings['cart_drawer'] ) ) : ?>
								<button type="button" class="delicat-shell__icon-button delicat-shell__cart" data-delicat-cart-open aria-controls="delicat-shell-cart" aria-expanded="false" aria-label="<?php esc_attr_e( 'Ouvrir le panier', 'delicat-builder-v9' ); ?>">
									<?php echo self::icon( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span class="delicat-shell__cart-count" data-delicat-cart-count<?php echo $header_cart_count > 0 ? '' : ' hidden'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( (string) $header_cart_count ); ?></span>
								</button>
							<?php else : ?>
								<a class="delicat-shell__icon-button delicat-shell__cart" href="<?php echo esc_url( self::cart_url() ); ?>" aria-label="<?php esc_attr_e( 'Panier', 'delicat-builder-v9' ); ?>">
									<?php echo self::icon( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span class="delicat-shell__cart-count" data-delicat-cart-count<?php echo $header_cart_count > 0 ? '' : ' hidden'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( (string) $header_cart_count ); ?></span>
								</a>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( $menu_id > 0 ) : ?>
							<button type="button" class="delicat-shell__icon-button delicat-shell__mobile-trigger" data-delicat-menu-toggle aria-controls="delicat-shell-mobile-menu" aria-expanded="false">
								<?php echo self::icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span class="screen-reader-text"><?php echo esc_html( $settings['mobile_menu_label'] ); ?></span>
							</button>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( $menu_id > 0 ) : ?>
					<div id="delicat-shell-mobile-menu" class="delicat-shell__mobile-panel" data-delicat-mobile-panel hidden>
						<nav aria-label="<?php esc_attr_e( 'Navigation mobile', 'delicat-builder-v9' ); ?>">
							<?php echo self::menu_html( $menu_id, 'delicat-shell__mobile-menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</nav>
					</div>
				<?php endif; ?>
			</div>

			<?php echo self::render_mobile_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( ! empty( $settings['show_cart'] ) && ! empty( $settings['cart_drawer'] ) && class_exists( 'WooCommerce' ) ) : ?>
				<div id="delicat-shell-cart" class="delicat-shell__drawer-layer" data-delicat-cart-layer hidden>
					<aside class="delicat-shell__drawer" role="dialog" aria-modal="true" aria-labelledby="delicat-shell-cart-title">
						<div class="delicat-shell__drawer-head">
							<div>
								<strong id="delicat-shell-cart-title"><?php esc_html_e( 'Mon panier', 'delicat-builder-v9' ); ?></strong>
								<small><?php esc_html_e( 'Synchronisé avec WooCommerce', 'delicat-builder-v9' ); ?></small>
							</div>
							<button type="button" class="delicat-shell__icon-button" data-delicat-cart-close aria-label="<?php esc_attr_e( 'Fermer le panier', 'delicat-builder-v9' ); ?>">
								<?php echo self::icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</button>
						</div>
						<div class="delicat-shell__drawer-body" data-delicat-cart-body aria-live="polite">
							<div class="delicat-shell__drawer-state is-loading"><?php esc_html_e( 'Chargement du panier…', 'delicat-builder-v9' ); ?></div>
						</div>
					</aside>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $settings['show_search'] ) ) : ?>
				<div id="delicat-shell-search" class="delicat-shell__search-layer" data-delicat-search-layer hidden>
					<div class="delicat-shell__search-panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Recherche de produits', 'delicat-builder-v9' ); ?>">
						<form class="delicat-shell__search-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" data-delicat-search-form>
							<span class="delicat-shell__search-input-icon" aria-hidden="true"><?php echo self::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<label class="screen-reader-text" for="delicat-shell-search-input"><?php esc_html_e( 'Rechercher des produits', 'delicat-builder-v9' ); ?></label>
							<input id="delicat-shell-search-input" type="search" name="s" autocomplete="off" autocapitalize="none" spellcheck="false" enterkeyhint="search" placeholder="<?php esc_attr_e( 'Rechercher un jeu, une carte cadeau…', 'delicat-builder-v9' ); ?>" aria-controls="delicat-shell-search-results" aria-autocomplete="list">
							<input type="hidden" name="post_type" value="product">
							<button type="button" class="delicat-shell__search-close" data-delicat-search-close aria-label="<?php esc_attr_e( 'Fermer la recherche', 'delicat-builder-v9' ); ?>"><?php echo self::icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
						</form>
						<?php echo self::popular_search_shortcuts(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( ! empty( $settings['search_suggestions'] ) ) : ?>
							<div class="delicat-shell__recent" data-delicat-search-recent hidden></div>
							<div class="delicat-shell__search-status" data-delicat-search-status role="status" aria-live="polite"></div>
							<div id="delicat-shell-search-results" class="delicat-shell__search-results" data-delicat-search-results role="listbox" hidden></div>
						<?php endif; ?>
						<p class="delicat-shell__search-note"><?php esc_html_e( 'Tapez pour rechercher dans le catalogue Delicat.', 'delicat-builder-v9' ); ?></p>
					</div>
				</div>
			<?php endif; ?>
		</header>
		<?php
		return (string) ob_get_clean();
	}

}
