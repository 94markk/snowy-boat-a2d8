<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC51.34 — Native Server Page Engine / Turbo Engine 2.0.
 *
 * Goals:
 * - render managed Builder pages directly from PHP instead of routing through
 *   the theme page template + the_content filter;
 * - cache only the anonymous, non-personalized Builder body fragment;
 * - keep cart/checkout/account/wallet/session state out of shared caches;
 * - retain a compatibility shell using the active theme header/footer;
 * - provide an optional V9-owned document shell for the lightest path.
 */
final class Delicat_Builder_V9_Server_Engine {
	private static bool $native_request = false;
	private static string $cache_state = 'BYPASS';

	public static function boot(): void {
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99990 );
		add_action( 'template_redirect', array( __CLASS__, 'prepare_cache_policy' ), 3 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 90 );
		add_action( 'wp_head', array( __CLASS__, 'print_shell_css' ), 0 );
		add_action( 'send_headers', array( __CLASS__, 'send_runtime_headers' ), 50 );
	}

	private static function settings(): array {
		if ( ! class_exists( 'Delicat_Builder_V9_Performance', false ) || ! is_callable( array( 'Delicat_Builder_V9_Performance', 'settings' ) ) ) {
			return array();
		}
		return Delicat_Builder_V9_Performance::settings();
	}

	private static function enabled(): bool {
		$s = self::settings();
		return class_exists( 'Delicat_Builder_V9_Core', false )
			&& Delicat_Builder_V9_Core::is_enabled()
			&& ! Delicat_Builder_V9_Core::is_safe_mode()
			&& ! empty( $s['enabled'] )
			&& ! empty( $s['server_rendered_pages'] );
	}

	/**
	 * Sensitive commerce/private surfaces are never taken over by this engine.
	 */
	private static function sensitive_request(): bool {
		return ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
			|| ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() );
	}

	public static function current_page_id(): int {
		if ( ! self::request_allowed() || ! is_singular( 'page' ) ) {
			return 0;
		}
		$page_id = absint( get_queried_object_id() );
		if ( $page_id <= 0 || ! class_exists( 'Delicat_Builder_V9_Pages', false ) || ! Delicat_Builder_V9_Pages::is_enabled_for_page( $page_id ) ) {
			return 0;
		}
		if ( post_password_required( $page_id ) ) {
			return 0;
		}
		$layout = Delicat_Builder_V9_Pages::get_layout( $page_id );
		return empty( $layout ) ? 0 : $page_id;
	}

	/**
	 * RC51.43 Product Turbo: single product pages owned by Native Product Builder
	 * ride the same native fast path as managed pages.
	 */
	public static function current_product_id(): int {
		if ( ! self::request_allowed() || ! is_singular( 'product' ) ) {
			return 0;
		}
		$s = self::settings();
		$products_on = array_key_exists( 'server_rendered_products', $s )
			? ! empty( $s['server_rendered_products'] )
			: ! empty( $s['server_rendered_pages'] ); // Inherit until Performance Studio saves its own toggle.
		if ( ! apply_filters( 'delicat_builder_v9_server_products', $products_on ) ) {
			return 0;
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Native_Product', false ) ) { return 0; }
		$product_id = absint( get_queried_object_id() );
		if ( $product_id <= 0 || ! Delicat_Builder_V9_Native_Product::is_active_product( $product_id ) ) {
			return 0;
		}
		if ( post_password_required( $product_id ) ) {
			return 0;
		}
		return $product_id;
	}

	/** Page or product currently owned by the native engine. */
	public static function current_target_id(): int {
		$page_id = self::current_page_id();
		return $page_id > 0 ? $page_id : self::current_product_id();
	}

	/** Shared request-level gates for every native-render candidate. */
	private static function request_allowed(): bool {
		if ( ! self::enabled() || is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( self::sensitive_request() ) {
			return false;
		}
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );
		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	public static function template_include( $template ) {
		if ( ! is_string( $template ) ) {
			return $template; /* RC18: an earlier filter may return false/null. */
		}
		try {
			if ( self::current_target_id() <= 0 ) {
				return $template;
			}

			$s = self::settings();
			$mode = isset( $s['server_shell_mode'] ) && 'v9' === $s['server_shell_mode'] ? 'v9' : 'theme';
			$candidate = DELICAT_BUILDER_V9_DIR . ( 'v9' === $mode ? 'templates/server-v9-shell.php' : 'templates/server-page.php' );
			if ( ! is_readable( $candidate ) ) {
				return $template;
			}

			self::$native_request = true;
			return $candidate;
		} catch ( Throwable $error ) {
			self::record_failure( 'template', $error );
			return $template;
		}
	}

	/** Template entry point: renders whichever surface owns this request. */
	public static function render_current(): string {
		if ( self::current_page_id() > 0 ) {
			return self::render_current_page();
		}
		if ( self::current_product_id() > 0 ) {
			return self::render_current_product();
		}
		return '';
	}

	/**
	 * RC51.37: native Product Builder 74 render with an anonymous fragment
	 * cache. The cache key embeds price, stock status and the product's
	 * modified time, so a price/stock edit rotates the key on the very next
	 * request — no manual purge needed. As defense-in-depth, any fragment
	 * whose markup contains a nonce-like token is never cached: whatever a
	 * checkout bridge injects into the form can never leak between visitors.
	 */
	public static function render_current_product(): string {
		$product_id = self::current_product_id();
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return '';
		}

		$cacheable = self::fragment_cache_allowed();
		$key = '';
		if ( $cacheable ) {
			$spb_settings = function_exists( 'delicat_builder_v9_spb_effective' ) ? delicat_builder_v9_spb_effective( $product_id ) : array();
			$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
			$modified = $product->get_date_modified();
			$payload = array(
				'product'  => $product_id,
				'modified' => $modified ? $modified->getTimestamp() : 0,
				'price'    => (string) $product->get_price(),
				'stock'    => (string) $product->get_stock_status(),
				/* RC65: the fragment shows the product's reviews (RC63 block +
				 * Product JSON-LD). Approval changes the review count; the
				 * reviews module stamps `_dlc_reviews_stamp` on every approval,
				 * edit or insert, so an edited review misses the cache too. */
				'reviews'  => (string) $product->get_review_count() . ':' . (string) $product->get_average_rating() . ':' . (string) get_post_meta( $product_id, '_dlc_reviews_stamp', true ),
				'spb'      => hash( 'sha256', (string) wp_json_encode( $spb_settings ) ),
				'locale'   => determine_locale(),
				'currency' => $currency,
				'version'  => defined( 'DELICAT_BUILDER_V9_VERSION' ) ? DELICAT_BUILDER_V9_VERSION : '',
			);
			$key = class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'key' ) )
				? Delicat_Builder_V9_Cache::key( 'server_product', $payload )
				: 'dbv9_srvp_' . substr( hash( 'sha256', (string) wp_json_encode( $payload ) ), 0, 40 );

			$cached = wp_cache_get( $key, 'delicat_builder_v9_server' );
			if ( false === $cached && ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) ) {
				$cached = get_transient( $key );
			}
			if ( is_string( $cached ) && '' !== $cached ) {
				self::$cache_state = 'HIT';
				return $cached;
			}
		}

		try {
			$html = (string) delicat_builder_v9_spb_render( array( 'product_id' => $product_id ) );
		} catch ( Throwable $error ) {
			self::record_failure( 'render_product', $error );
			return '';
		}
		if ( '' === $html ) {
			return '';
		}

		$has_token = false !== stripos( $html, 'nonce' ) || false !== stripos( $html, '_wpnonce' );
		if ( $cacheable && '' !== $key && ! $has_token ) {
			$ttl = self::fragment_ttl();
			wp_cache_set( $key, $html, 'delicat_builder_v9_server', $ttl );
			if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
				set_transient( $key, $html, $ttl );
			}
			self::$cache_state = 'MISS';
		} else {
			self::$cache_state = 'BYPASS';
		}

		return $html;
	}

	/**
	 * Render the managed page body. The cache contains only the Builder body,
	 * never the header, footer, cart, account state, nonces or admin bar.
	 */
	public static function render_current_page(): string {
		$page_id = self::current_page_id();
		if ( $page_id <= 0 || ! class_exists( 'Delicat_Builder_V9_Renderer', false ) ) {
			return '';
		}

		$layout = Delicat_Builder_V9_Pages::get_layout( $page_id );
		if ( empty( $layout ) ) {
			return '';
		}

		$cacheable = self::fragment_cache_allowed() && self::layout_cache_safe( $layout );
		$key = $cacheable ? self::fragment_cache_key( $page_id, $layout ) : '';
		if ( $cacheable && '' !== $key ) {
			$cached = wp_cache_get( $key, 'delicat_builder_v9_server' );
			if ( false === $cached ) {
				$cached = get_transient( $key );
			}
			if ( is_string( $cached ) && '' !== $cached ) {
				self::$cache_state = 'HIT';
				return $cached;
			}
		}

		try {
			$html = Delicat_Builder_V9_Renderer::render_layout( $layout, true );
		} catch ( Throwable $error ) {
			self::record_failure( 'render', $error );
			return '';
		}

		if ( '' === $html ) {
			return '';
		}

		if ( $cacheable && '' !== $key ) {
			$ttl = self::fragment_ttl();
			wp_cache_set( $key, $html, 'delicat_builder_v9_server', $ttl );
			/* RC51.35: with Redis/Memcached active, WP already routes transients
			 * into the object cache — a second set_transient just stores the
			 * same fragment twice. Only fall back to transients without one. */
			if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
				set_transient( $key, $html, $ttl );
			}
			self::$cache_state = 'MISS';
		} else {
			self::$cache_state = 'BYPASS';
		}

		return $html;
	}

	private static function fragment_ttl(): int {
		$s = self::settings();
		return min( 1800, max( 60, absint( $s['server_fragment_ttl'] ?? 300 ) ) );
	}

	/**
	 * Conservative anonymous fragment-cache gate.
	 */
	private static function fragment_cache_allowed(): bool {
		$s = self::settings();
		if ( empty( $s['server_fragment_cache'] ) || is_user_logged_in() || self::sensitive_request() ) {
			return false;
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}
		if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- cache bypass only.
			return false;
		}
		if (
			class_exists( 'Delicat_Builder_V9_Security', false )
			&& is_callable( array( 'Delicat_Builder_V9_Security', 'public_cache_allowed' ) )
			&& ! Delicat_Builder_V9_Security::public_cache_allowed()
		) {
			return false;
		}

		// Any Woo session/cart/currency cookie can make product prices or actions
		// visitor-specific. Bypass rather than trying to guess every plugin's vary.
		return ! self::has_private_cookie();
	}

	private static function has_private_cookie(): bool {
		if (
			class_exists( 'Delicat_Builder_V9_Security', false )
			&& is_callable( array( 'Delicat_Builder_V9_Security', 'has_private_cookie' ) )
		) {
			return Delicat_Builder_V9_Security::has_private_cookie();
		}
		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			$name = strtolower( sanitize_key( (string) $cookie_name ) );
			if (
				false !== strpos( $name, 'woocommerce' )
				|| false !== strpos( $name, 'wp_woocommerce_session' )
				|| false !== strpos( $name, 'wordpress_logged_in' )
				|| false !== strpos( $name, 'comment_author' )
				|| false !== strpos( $name, 'currency' )
				|| false !== strpos( $name, 'aelia' )
				|| false !== strpos( $name, 'wallet' )
				|| false !== strpos( $name, 'tera' )
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reject fragment caching if a future/custom layout contains obviously
	 * private dynamic surface identifiers. Current first-party components are
	 * public, but this guard protects custom/migrated layouts too.
	 */
	private static function layout_cache_safe( array $layout ): bool {
		$private_types = array( 'account', 'my-account', 'my_account', 'wallet', 'order-history', 'orders', 'checkout', 'cart', 'redeem', 'pin-history', 'customer' );
		$private_keys  = array( 'customer_id', 'user_id', 'user_email', 'nonce', 'order_id', 'wallet_balance', 'account_id' );

		$walk = static function ( $value ) use ( &$walk, $private_types, $private_keys ): bool {
			if ( ! is_array( $value ) ) {
				return true;
			}
			foreach ( $value as $key => $child ) {
				$key_name = sanitize_key( (string) $key );
				if ( in_array( $key_name, $private_keys, true ) ) {
					return false;
				}
				if ( in_array( $key_name, array( 'type', 'component', 'component_type', 'widget', 'source' ), true ) && is_scalar( $child ) ) {
					$type = sanitize_key( (string) $child );
					if ( in_array( $type, $private_types, true ) ) {
						return false;
					}
				}
				if ( is_array( $child ) && ! $walk( $child ) ) {
					return false;
				}
			}
			return true;
		};

		return $walk( $layout );
	}

	private static function page_cache_hint_safe(): bool {
		$customer_location = sanitize_key( (string) get_option( 'woocommerce_default_customer_address', 'base' ) );
		// Woo's geolocation_ajax mode is explicitly the page-cache-compatible
		// variant. Plain server-side geolocation still varies one URL by IP.
		if ( 'geolocation' === $customer_location ) {
			return false;
		}
		return true;
	}

	private static function fragment_cache_key( int $page_id, array $layout ): string {
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$country = '';
		if ( function_exists( 'WC' ) && WC() && isset( WC()->customer ) && WC()->customer ) {
			$country = (string) WC()->customer->get_shipping_country();
			if ( '' === $country ) {
				$country = (string) WC()->customer->get_billing_country();
			}
		}
		$payload = array(
			'page'     => $page_id,
			'layout'   => hash( 'sha256', (string) wp_json_encode( $layout ) ),
			'locale'   => determine_locale(),
			'currency' => $currency,
			'country'  => strtoupper( sanitize_text_field( $country ) ),
			'version'  => defined( 'DELICAT_BUILDER_V9_VERSION' ) ? DELICAT_BUILDER_V9_VERSION : '',
		);
		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'key' ) ) ) {
			return Delicat_Builder_V9_Cache::key( 'server_fragment', $payload );
		}
		return 'dbv9_srv_' . substr( hash( 'sha256', wp_json_encode( $payload ) ), 0, 40 );
	}

	/**
	 * Tell compatible page-cache layers that a clean anonymous Builder page is
	 * public. Private/session-bearing requests are explicitly marked no-cache.
	 */
	public static function prepare_cache_policy(): void {
		if ( self::current_target_id() <= 0 ) {
			return;
		}
		$s = self::settings();
		if ( empty( $s['server_page_cache_hint'] ) ) {
			return;
		}
		/* RC32: a signed-in customer's copy may be cached privately by LiteSpeed. */
		if ( is_user_logged_in() && ! self::sensitive_request() && empty( $_GET ) && class_exists( 'Delicat_Builder_V9_Security', false ) && is_callable( array( 'Delicat_Builder_V9_Security', 'private_cache_allowed' ) ) && Delicat_Builder_V9_Security::private_cache_allowed() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			Delicat_Builder_V9_Security::hint_private_cache( 'Delicat V9 native server page (signed-in)' );
			return;
		}
		$private = is_user_logged_in() || self::sensitive_request() || ! empty( $_GET ) || self::has_private_cookie(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- cache policy only.
		if ( $private ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			do_action( 'litespeed_control_set_nocache', 'Delicat V9 private/session page' );
			return;
		}

		$public_cache_safe = class_exists( 'Delicat_Builder_V9_Security', false )
			&& is_callable( array( 'Delicat_Builder_V9_Security', 'public_cache_allowed' ) )
			&& Delicat_Builder_V9_Security::public_cache_allowed();
		if ( $public_cache_safe && self::page_cache_hint_safe() ) {
			do_action( 'litespeed_control_set_cacheable', 'Delicat V9 native server page' );
		} else {
			do_action( 'litespeed_control_set_nocache', self::page_cache_hint_safe() ? 'Delicat V9 currency/private page' : 'Delicat V9 geolocation-aware page' );
		}
	}


	public static function send_runtime_headers(): void {
		if ( headers_sent() || self::current_target_id() <= 0 ) {
			return;
		}
		header( 'X-Delicat-V9-SSR: 1' );
		$s = self::settings();
		header( 'X-Delicat-V9-Shell: ' . ( ( isset( $s['server_shell_mode'] ) && 'v9' === $s['server_shell_mode'] ) ? 'native' : 'theme' ) );
		header( 'X-Delicat-V9-Islands: ' . ( ! empty( $s['islands_mode'] ) ? '1' : '0' ) );
		$object_cache = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ? 'persistent' : 'transient';
		header( 'X-Delicat-V9-Object-Cache: ' . $object_cache );
	}

	public static function print_shell_css(): void {
		if ( self::current_target_id() <= 0 ) {
			return;
		}
		echo '<style id="delicat-v9-server-critical">'
			. '.delicat-v9-server-main{display:block;width:100%;max-width:none;margin:0;padding:0;min-width:0}'
			. '.delicat-v9-native-document{margin:0;min-height:100vh;background:var(--delicat-page-bg,#fff)}'
			. '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( self::current_target_id() > 0 ) {
			$classes[] = 'delicat-v9-server-rendered';
			$s = self::settings();
			$classes[] = ( isset( $s['server_shell_mode'] ) && 'v9' === $s['server_shell_mode'] ) ? 'delicat-v9-native-shell' : 'delicat-v9-theme-shell';
		}
		return array_values( array_unique( $classes ) );
	}

	public static function cache_state(): string {
		return self::$cache_state;
	}

	private static function record_failure( string $stage, Throwable $error ): void {
		update_option(
			'delicat_builder_v9_last_server_engine_failure',
			array(
				'time'  => gmdate( 'c' ),
				'stage' => sanitize_key( $stage ),
				'type'  => sanitize_text_field( get_class( $error ) ),
				'file'  => sanitize_file_name( basename( $error->getFile() ) ),
				'line'  => absint( $error->getLine() ),
				'hash'  => hash( 'sha256', $stage . '|' . $error->getMessage() . '|' . $error->getFile() . '|' . $error->getLine() ),
			),
			false
		);
	}
}
