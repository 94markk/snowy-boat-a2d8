<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Privacy-safe live selling / social-proof feed.
 *
 * Only genuine WooCommerce order events are recorded. Customer names, emails,
 * addresses, order IDs and totals are never exposed or stored in the feed.
 */
final class Delicat_Builder_V9_Live_Selling {
	public const OPTION = 'delicat_builder_v9_live_selling';
	private const REST_NAMESPACE = 'delicat-builder-v9/v1';
	private const EVENTS_OPTION = 'delicat_builder_v9_live_sale_events';
	private static bool $booted = false;
	private static bool $assets_registered = false;
	private static ?array $settings_cache = null;

	public static function defaults(): array {
		return array(
			/* RC51.59: opt-in. The polling popup must be a deliberate merchant
			 * choice, not a default cost on every visitor's session. */
			'enabled'          => 0,
			'position'         => 'bottom_left',
			'display_scope'    => 'site',
			'show_mobile'      => 1,
			'show_desktop'     => 1,
			'show_close'       => 1,
			'label'            => '🔥 Vente en direct',
			'message_template' => 'Un client vient d’acheter {product}',
			'max_age'          => 21600,
			'initial_delay'    => 9000,
			'display_duration' => 6200,
			'poll_interval'    => 45000,
		);
	}

	public static function settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}
		$value = get_option( self::OPTION, array() );
		self::$settings_cache = self::sanitize_settings( wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() ) );
		return self::$settings_cache;
	}

	private static function sanitize_settings( array $raw ): array {
		$defaults = self::defaults();
		$position = sanitize_key( (string) ( $raw['position'] ?? $defaults['position'] ) );
		if ( ! in_array( $position, array( 'bottom_left', 'bottom_right' ), true ) ) { $position = $defaults['position']; }
		$scope = sanitize_key( (string) ( $raw['display_scope'] ?? $defaults['display_scope'] ) );
		if ( ! in_array( $scope, array( 'site', 'shop', 'home' ), true ) ) { $scope = $defaults['display_scope']; }
		$label = sanitize_text_field( (string) ( $raw['label'] ?? $defaults['label'] ) );
		if ( '' === $label ) { $label = $defaults['label']; }
		$message = sanitize_text_field( (string) ( $raw['message_template'] ?? $defaults['message_template'] ) );
		if ( '' === $message || false === strpos( $message, '{product}' ) ) { $message = $defaults['message_template']; }

		return array(
			'enabled'          => empty( $raw['enabled'] ) ? 0 : 1,
			'position'         => $position,
			'display_scope'    => $scope,
			'show_mobile'      => empty( $raw['show_mobile'] ) ? 0 : 1,
			'show_desktop'     => empty( $raw['show_desktop'] ) ? 0 : 1,
			'show_close'       => empty( $raw['show_close'] ) ? 0 : 1,
			'label'            => $label,
			'message_template' => $message,
			'max_age'          => min( DAY_IN_SECONDS, max( HOUR_IN_SECONDS, absint( $raw['max_age'] ?? $defaults['max_age'] ) ) ),
			'initial_delay'    => min( 60000, max( 4000, absint( $raw['initial_delay'] ?? $defaults['initial_delay'] ) ) ),
			'display_duration' => min( 15000, max( 3000, absint( $raw['display_duration'] ?? $defaults['display_duration'] ) ) ),
			'poll_interval'    => min( 180000, max( 30000, absint( $raw['poll_interval'] ?? $defaults['poll_interval'] ) ) ),
		);
	}

	public static function enabled(): bool {
		$s = self::settings();
		return ! empty( $s['enabled'] );
	}

	public static function set_enabled( bool $enabled ): void {
		$s = self::settings();
		$was = ! empty( $s['enabled'] );
		$s['enabled'] = $enabled ? 1 : 0;
		update_option( self::OPTION, self::sanitize_settings( $s ), false );
		self::$settings_cache = null;
		self::after_settings_change( $was, $enabled );
	}

	/**
	 * RC55: what every save path must do once the option is written.
	 *
	 * 1. Cached documents. Enabling the popup changes the storefront HTML (the
	 *    runtime script and its configuration are printed only while enabled),
	 *    but the previous save wrote the option and nothing else, so LiteSpeed
	 *    kept serving guests the copy rendered before the switch until its TTL
	 *    ran out. The administrator, who is never served from cache, saw the
	 *    feature "on" while every client still saw it off. Bump the fragment
	 *    cache generation and purge the page cache whenever the setting moved.
	 *
	 * 2. Something to show. The feed only ever contained orders paid AFTER
	 *    activation, so a freshly enabled popup had nothing to display until
	 *    the next sale — indistinguishable from "still disabled". Seed it from
	 *    the store's own recent paid orders (real orders only, real timestamps;
	 *    the "ancienneté maximale" window still decides what a visitor sees).
	 */
	private static function after_settings_change( bool $was_enabled, bool $now_enabled ): void {
		if ( $now_enabled && ( ! $was_enabled || empty( self::events() ) ) ) {
			try {
				self::seed_events_from_orders();
			} catch ( Throwable $error ) {
				unset( $error );
			}
		}
		if ( $was_enabled !== $now_enabled ) {
			if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
				Delicat_Builder_V9_Cache::bump_version();
			}
			do_action( 'litespeed_purge_all' );
		}
	}

	/**
	 * pro.15: only a real customer sale is social proof. Free reward orders, reseller-API
	 * mirrors and staff test orders are not.
	 */
	private static function eligible( WC_Order $order ): bool {
		if ( (float) $order->get_total() <= 0 ) { return false; }
		$excluded = (array) apply_filters( 'delicat_builder_v9_live_sale_excluded_sources', array( 'delicat-rewards', 'delicat_rewards', 'rewards', 'delicat_reseller_api' ) );
		$via = (string) $order->get_created_via();
		if ( '' !== $via && in_array( $via, $excluded, true ) ) { return false; }
		$customer = (int) $order->get_customer_id();
		if ( $customer > 0 && function_exists( 'user_can' ) && user_can( $customer, 'manage_woocommerce' ) ) { return false; }
		return (bool) apply_filters( 'delicat_builder_v9_live_sale_eligible', true, $order );
	}

	/** Build one feed entry from a paid order, or null when it has nothing showable. */
	private static function event_from_order( WC_Order $order, int $created = 0 ): ?array {
		$status = $order->get_status();
		if ( ! $order->is_paid() && ! in_array( $status, array( 'processing', 'completed' ), true ) ) { return null; }
		if ( ! self::eligible( $order ) ) { return null; }
		$product = self::product_from_order( $order );
		if ( ! $product ) { return null; }
		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$link_product = $product_id ? wc_get_product( $product_id ) : $product;
		$link = $link_product instanceof WC_Product ? get_permalink( $link_product->get_id() ) : home_url( '/shop/' );
		$image_id = $product->get_image_id();
		if ( ! $image_id && $link_product instanceof WC_Product ) { $image_id = $link_product->get_image_id(); }
		$image = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
		$hash = self::order_hash( (int) $order->get_id() );
		if ( $created <= 0 ) {
			$paid = $order->get_date_paid();
			$made = $order->get_date_created();
			$created = $paid instanceof DateTimeInterface ? (int) $paid->getTimestamp() : ( $made instanceof DateTimeInterface ? (int) $made->getTimestamp() : time() );
		}
		return array(
			'id' => 'sale_' . substr( hash( 'sha256', $hash . '|' . $created ), 0, 20 ),
			'product_id' => absint( $product_id ?: $product->get_id() ),
			'title' => sanitize_text_field( $product->get_name() ),
			'url' => self::clean_link( $link ),
			'image' => $image ? esc_url_raw( $image ) : '',
			'created' => $created,
			'order_hash' => $hash,
		);
	}

	/** RC55: fill the feed from the last real paid orders (7 days, at most 20). Returns the number added. */
	private static function seed_events_from_orders(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) { return 0; }
		$events = self::events();
		$known = array();
		foreach ( $events as $existing ) {
			if ( ! empty( $existing['order_hash'] ) ) { $known[ (string) $existing['order_hash'] ] = true; }
		}
		$orders = wc_get_orders( array(
			'limit'        => 20,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'status'       => array( 'wc-processing', 'wc-completed' ),
			'date_created' => '>' . ( time() - 7 * DAY_IN_SECONDS ),
			'return'       => 'objects',
		) );
		$added = 0;
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$event = self::event_from_order( $order );
			if ( ! $event || isset( $known[ $event['order_hash'] ] ) ) { continue; }
			$known[ $event['order_hash'] ] = true;
			$events[] = $event;
			$added++;
		}
		if ( $added > 0 ) {
			usort( $events, static function ( $a, $b ) { return absint( $b['created'] ?? 0 ) <=> absint( $a['created'] ?? 0 ); } );
			self::save_events( $events );
		}
		return $added;
	}

	public static function boot(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;
		// pro.15: capture and withdrawal are registered by the always-on bridge in
		// delicat-builder-v9.php, so orders validated from the WooCommerce orders
		// list, Action Scheduler or the Digital Gateway loopback are seen too.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ), 34 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 40 );
			add_action( 'admin_post_delicat_builder_v9_live_selling_save', array( __CLASS__, 'admin_save' ) );
		}
	}

	public static function register_assets(): void {
		if ( self::$assets_registered ) { return; }
		self::$assets_registered = true;
		wp_register_style( 'delicat-builder-v9-live-selling', DELICAT_BUILDER_V9_URL . 'assets/css/live-selling.css', array(), DELICAT_BUILDER_V9_VERSION );
		wp_register_script(
			'delicat-builder-v9-live-selling',
			DELICAT_BUILDER_V9_URL . 'assets/js/live-selling.js',
			array(),
			DELICAT_BUILDER_V9_VERSION,
			array( 'in_footer' => true, 'strategy' => 'defer' )
		);
	}

	private static function page_scope_allowed( string $scope ): bool {
		if ( 'site' === $scope ) { return true; }
		$is_home = ( function_exists( 'is_front_page' ) && is_front_page() ) || ( function_exists( 'is_home' ) && is_home() );
		if ( 'home' === $scope ) { return $is_home; }
		if ( 'shop' === $scope ) {
			return $is_home
				|| ( function_exists( 'is_shop' ) && is_shop() )
				|| ( function_exists( 'is_product' ) && is_product() )
				|| ( function_exists( 'is_product_category' ) && is_product_category() )
				|| ( function_exists( 'is_product_tag' ) && is_product_tag() );
		}
		return true;
	}

	private static function frontend_allowed(): bool {
		if ( is_admin() || ! self::enabled() || ! class_exists( 'WooCommerce' ) ) { return false; }
		$s = self::settings();
		// A deliberately hidden popup has no frontend work to do. Keep paid-order
		// capture active, but do not ship its runtime, configuration or late CSS.
		if ( empty( $s['show_mobile'] ) && empty( $s['show_desktop'] ) ) { return false; }
		if ( function_exists( 'is_cart' ) && is_cart() ) { return false; }
		if ( function_exists( 'is_checkout' ) && is_checkout() ) { return false; }
		if ( function_exists( 'is_account_page' ) && is_account_page() ) { return false; }
		// Never over a money step, in the browser or in the app's Custom Tabs: any
		// WooCommerce endpoint, the Pro Kernel wallet route, and the top-up, send-money
		// and PIN pages (filterable list).
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) { return false; }
		if ( class_exists( 'DBP_Kernel', false ) && is_callable( array( 'DBP_Kernel', 'route' ) ) && in_array( (string) DBP_Kernel::route(), self::blocked_routes(), true ) ) { return false; }
		if ( self::path_blocked( self::request_path() ) ) { return false; }
		return self::page_scope_allowed( (string) ( $s['display_scope'] ?? 'site' ) );
	}

	/** Pro Kernel routes where the popup never shows. */
	public static function blocked_routes(): array {
		return array( 'cart', 'checkout', 'account', 'wallet' );
	}

	/** Routes the display scope allows after an in-place navigation; null means every route. */
	private static function scope_routes( string $scope ): ?array {
		if ( 'home' === $scope ) { return array( 'home' ); }
		if ( 'shop' === $scope ) { return array( 'home', 'shop', 'product', 'archive' ); }
		return null;
	}

	/** Site-relative money pages (lower case, trailing slash) where the popup never shows. */
	public static function blocked_paths(): array {
		$paths = apply_filters( 'delicat_builder_v9_live_sale_blocked_paths', array( '/recharge/', '/envoyer-argent/', '/free-fire-redeem/', '/mon-portefeuille/', '/my-wallet/', '/wallet/', '/woo-wallet/' ) );
		$out   = array();
		foreach ( is_array( $paths ) ? $paths : array() as $path ) {
			$path = is_scalar( $path ) ? trim( strtolower( (string) $path ), " \t\n\r\0\x0B/" ) : '';
			if ( '' !== $path ) { $out[] = '/' . $path . '/'; } // A bare '/' would hide the popup everywhere.
		}
		return array_values( array_unique( $out ) );
	}

	/** The requested path relative to the site root, lower case, with a trailing slash. */
	public static function request_path( string $uri = '' ): string {
		if ( '' === $uri ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		}
		$path = strtolower( rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) ) );
		$base = rtrim( strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) { $path = substr( $path, strlen( $base ) ); }
		$path = trim( $path, '/' );
		return '' === $path ? '/' : '/' . $path . '/';
	}

	public static function path_blocked( string $path ): bool {
		foreach ( self::blocked_paths() as $blocked ) {
			if ( 0 === strpos( $path, $blocked ) ) { return true; }
		}
		return false;
	}

	public static function maybe_enqueue(): void {
		if ( ! self::frontend_allowed() ) { return; }
		self::register_assets();
		// RC44: the popup cannot appear before initial_delay, so its stylesheet
		// must not block first paint. The deferred runtime loads CSS after the
		// critical page has settled and guarantees it is ready before showing.
		wp_enqueue_script( 'delicat-builder-v9-live-selling' );
		$s = self::settings();
		$style     = DELICAT_BUILDER_V9_URL . 'assets/css/live-selling.css';
		$style_url = $style . '?ver=' . rawurlencode( DELICAT_BUILDER_V9_VERSION );
		if ( class_exists( 'Delicat_Builder_V9_Audit_Fixes', false ) && is_callable( array( 'Delicat_Builder_V9_Audit_Fixes', 'asset_url' ) ) ) {
			// The content-addressed copy, like every enqueued Builder asset.
			$mapped = (string) Delicat_Builder_V9_Audit_Fixes::asset_url( $style );
			if ( '' !== $mapped && $mapped !== $style ) { $style_url = $mapped; }
		}
		$config = array(
			'endpoint'        => rest_url( self::REST_NAMESPACE . '/live-sales' ),
			'styleUrl'        => $style_url,
			// Pro Kernel in-place navigation: the runtime re-checks where it may show.
			'blockedRoutes'   => self::blocked_routes(),
			'allowedRoutes'   => self::scope_routes( (string) ( $s['display_scope'] ?? 'site' ) ),
			'blockedPaths'    => self::blocked_paths(),
			'basePath'        => rtrim( strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ), '/' ),
			'initialDelay'    => absint( $s['initial_delay'] ?? 9000 ),
			'displayDuration' => absint( $s['display_duration'] ?? 6200 ),
			'pollInterval'    => absint( $s['poll_interval'] ?? 45000 ),
			'position'        => (string) ( $s['position'] ?? 'bottom_left' ),
			'showMobile'      => ! empty( $s['show_mobile'] ),
			'showDesktop'     => ! empty( $s['show_desktop'] ),
			'showClose'       => ! empty( $s['show_close'] ),
			'label'           => (string) ( $s['label'] ?? '🔥 Vente en direct' ),
			'messageTemplate' => (string) ( $s['message_template'] ?? 'Un client vient d’acheter {product}' ),
		);
		wp_add_inline_script( 'delicat-builder-v9-live-selling', 'window.DelicatLiveSelling=' . wp_json_encode( $config ) . ';', 'before' );
	}

	private static function order_hash( int $order_id ): string {
		return hash_hmac( 'sha256', (string) $order_id, wp_salt( 'nonce' ) );
	}

	private static function clean_link( $url ): string {
		$url = esc_url_raw( (string) $url );
		if ( ! $url ) { return ''; }
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		}
		return ( $host && hash_equals( $home_host, $host ) && wp_http_validate_url( $url ) ) ? $url : '';
	}

	private static function events(): array {
		$value = get_option( self::EVENTS_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	private static function save_events( array $events ): void {
		update_option( self::EVENTS_OPTION, array_slice( $events, 0, 40 ), false );
	}

	/**
	 * pro.15: publicly listed products only. `is_visible()` is true for a private or
	 * draft product when the current user can edit it, so an administrator completing
	 * an order could publish a customer's private product.
	 */
	private static function product_is_public( WC_Product $product ): bool {
		$check = $product;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent instanceof WC_Product ) { return false; }
			if ( 'publish' !== $product->get_status() ) { return false; }
			$check = $parent;
		}
		if ( 'publish' !== $check->get_status() ) { return false; }
		if ( ! in_array( $check->get_catalog_visibility(), array( 'visible', 'catalog' ), true ) ) { return false; }
		return '' === (string) get_post_field( 'post_password', $check->get_id() );
	}

	private static function product_from_order( WC_Order $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) { continue; }
			$product = $item->get_product();
			if ( $product instanceof WC_Product && self::product_is_public( $product ) ) { return $product; }
		}
		return false;
	}

	/** Serialise writes to the shared events list (two checkouts at once lost one). */
	private static function with_lock( callable $callback ): void {
		global $wpdb;
		$locked = isset( $wpdb ) && 1 === (int) $wpdb->get_var( "SELECT GET_LOCK('dbv9_live_sales', 2)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $locked ) { return; }
		try {
			$callback();
		} finally {
			$wpdb->get_var( "SELECT RELEASE_LOCK('dbv9_live_sales')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	public static function capture_order( $order_id ): void {
		try {
			if ( ! self::enabled() || ! function_exists( 'wc_get_order' ) ) { return; }
			$order_id = absint( $order_id );
			if ( $order_id <= 0 ) { return; }
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) { return; }
			// Marked on the order: a sale pushed out of the 40-event list is not
			// announced again as "À l'instant" when it later completes.
			if ( $order->get_meta( '_dbv9_live_sale', true ) ) { return; }
			$event = self::event_from_order( $order );
			if ( ! $event ) { return; }
			// Announced with its payment time; a sale validated long after payment is
			// not presented as happening now.
			$s = self::settings();
			if ( absint( $event['created'] ) < time() - absint( $s['max_age'] ?? 21600 ) ) { return; }
			$hash = (string) $event['order_hash'];
			$written = false;
			self::with_lock( static function () use ( $event, $hash, &$written ) {
				$events = self::events();
				foreach ( $events as $existing ) {
					if ( isset( $existing['order_hash'] ) && hash_equals( (string) $existing['order_hash'], $hash ) ) { return; }
				}
				array_unshift( $events, $event );
				// Dated by payment time: a sale captured late (lock busy, validated after
				// an upgrade) must not sit above newer ones. Readers expect newest first.
				usort( $events, static function ( $a, $b ) { return absint( $b['created'] ?? 0 ) <=> absint( $a['created'] ?? 0 ); } );
				self::save_events( $events );
				$written = true;
			} );
			if ( $written ) {
				$order->update_meta_data( '_dbv9_live_sale', (string) $event['id'] );
				$order->save_meta_data();
				do_action( 'litespeed_purge', 'dbv9_live_sales' );
			}
		} catch ( Throwable $error ) {
			// Never let social proof break a payment request.
			unset( $error );
		}
	}

	/** pro.15: a cancelled, refunded, failed or deleted order leaves the feed. */
	public static function withdraw_order( $order_id ): void {
		try {
			$order_id = absint( $order_id );
			if ( $order_id <= 0 ) { return; }
			$hash = self::order_hash( $order_id );
			$removed = false;
			self::with_lock( static function () use ( $hash, &$removed ) {
				$events = self::events();
				$kept = array();
				foreach ( $events as $existing ) {
					if ( isset( $existing['order_hash'] ) && hash_equals( (string) $existing['order_hash'], $hash ) ) {
						$removed = true;
						continue;
					}
					$kept[] = $existing;
				}
				if ( $removed ) { self::save_events( $kept ); }
			} );
			if ( $removed ) {
				do_action( 'litespeed_purge', 'dbv9_live_sales' );
			}
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	/** Settings a client needs to present the feed (no admin-only values). */
	public static function public_settings(): array {
		$s = self::settings();
		return array(
			'label'               => (string) $s['label'],
			'message_template'    => (string) $s['message_template'],
			'initial_delay_ms'    => absint( $s['initial_delay'] ),
			'display_duration_ms' => absint( $s['display_duration'] ),
			'poll_interval_ms'    => absint( $s['poll_interval'] ),
			'max_age_s'           => absint( $s['max_age'] ),
			'scope'               => (string) $s['display_scope'],
			'show_mobile'         => ! empty( $s['show_mobile'] ),
			'show_close'          => true,
		);
	}

	/**
	 * pro.15: one source for the website popup and the native app.
	 *
	 * @param string $after   Last event id the client showed.
	 * @param int    $limit   1-5.
	 * @param bool   $for_app Adds product_id; ages instead of exact timestamps.
	 */
	public static function public_feed( string $after = '', int $limit = 5, bool $for_app = false ): array {
		$enabled = self::enabled();
		$out = array(
			'enabled'     => $enabled,
			'server_time' => time(),
			'settings'    => self::public_settings(),
			'items'       => array(),
		);
		if ( ! $enabled ) { return $out; }
		$limit = max( 1, min( 5, $limit ) );
		$s = self::settings();
		$now = time();
		$cutoff = $now - absint( $s['max_age'] ?? 21600 );
		$recent = array();
		foreach ( self::events() as $event ) {
			if ( ! is_array( $event ) || absint( $event['created'] ?? 0 ) < $cutoff ) { continue; }
			$product_id = absint( $event['product_id'] ?? 0 );
			// Unpublished since the sale: no longer shown.
			if ( $product_id > 0 && function_exists( 'get_post_status' ) && 'publish' !== get_post_status( $product_id ) ) { continue; }
			$recent[] = $event;
		}
		$picked = array();
		$after = sanitize_key( $after );
		if ( '' === $after ) {
			if ( ! empty( $recent[0] ) ) { $picked[] = $recent[0]; }
		} else {
			$found = false;
			foreach ( $recent as $event ) {
				$id = sanitize_key( (string) ( $event['id'] ?? '' ) );
				if ( $id && hash_equals( $id, $after ) ) { $found = true; break; }
				$picked[] = $event;
				if ( count( $picked ) >= $limit ) { break; }
			}
			if ( ! $found && ! empty( $recent[0] ) ) { $picked = array( $recent[0] ); }
			if ( $found && count( $picked ) > 1 ) { $picked = array_reverse( $picked ); }
		}
		foreach ( $picked as $event ) {
			$item = self::public_event( $event );
			if ( $for_app ) {
				unset( $item['created'] );
				$item['product_id'] = absint( $event['product_id'] ?? 0 );
				// Minute precision: the exact second of every sale is not public data.
				$item['age_s'] = (int) ( floor( max( 0, $now - absint( $event['created'] ?? $now ) ) / 60 ) * 60 );
			}
			$out['items'][] = $item;
		}
		$out['revision'] = substr( sha1( wp_json_encode( array( $out['items'], $out['settings'] ) ) ), 0, 12 );
		return $out;
	}

	public static function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/live-sales',
			array(
				'methods' => 'GET',
				'permission_callback' => '__return_true',
				'callback' => array( __CLASS__, 'rest_list' ),
				'args' => array(
					'after' => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);
	}

	private static function public_event( array $event ): array {
		return array(
			'id' => sanitize_key( (string) ( $event['id'] ?? '' ) ),
			'title' => sanitize_text_field( (string) ( $event['title'] ?? '' ) ),
			'url' => self::clean_link( $event['url'] ?? '' ),
			'image' => esc_url_raw( (string) ( $event['image'] ?? '' ) ),
			'created' => absint( $event['created'] ?? 0 ),
		);
	}

	public static function rest_list( WP_REST_Request $request ) {
		$feed = self::public_feed( (string) $request->get_param( 'after' ), 5, false );
		$response = rest_ensure_response( array( 'items' => $feed['items'], 'serverTime' => $feed['server_time'] ) );
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'public, max-age=15, stale-while-revalidate=30' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
			// Revalidation: an unchanged feed answers 304 without a body. serverTime is
			// left out of the tag (the popup does not read it).
			$etag = '"' . substr( sha1( (string) wp_json_encode( array( $feed['items'], $feed['settings'], ! empty( $feed['enabled'] ) ) ) ), 0, 20 ) . '"';
			$response->header( 'ETag', $etag );
			if ( self::etag_matches( (string) $request->get_header( 'if_none_match' ), $etag ) ) {
				$response->set_status( 304 );
				$response->set_data( null );
			}
			// LiteSpeed keeps REST answers for its own, much longer TTL unless told:
			// hold this copy to the 15 seconds announced above.
			do_action( 'litespeed_control_set_ttl', 15 );
			do_action( 'litespeed_tag_add', 'dbv9_live_sales' );
		}
		return $response;
	}

	/** If-None-Match: a list of weak or strong tags, or "*". */
	private static function etag_matches( string $header, string $etag ): bool {
		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( 0 === strpos( $candidate, 'W/' ) ) {
				$candidate = substr( $candidate, 2 );
			}
			if ( '' !== $candidate && ( '*' === $candidate || hash_equals( $etag, $candidate ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function admin_menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Live Selling', 'delicat-builder-v9' ),
			__( 'Live Selling', 'delicat-builder-v9' ),
			'manage_woocommerce',
			'delicat-live-selling',
			array( __CLASS__, 'admin_page' )
		);
	}

	public static function admin_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission refusée.', 'delicat-builder-v9' ) ); }
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { wp_die( esc_html__( 'Requête non autorisée.', 'delicat-builder-v9' ) ); }
		check_admin_referer( 'delicat_builder_v9_live_selling_settings' );
		/*
		 * RC55: the Identity Pro re-authentication gate is deliberately NOT
		 * applied here. It belongs to security changes (Security Sync,
		 * Production headers, notification credentials). Live Selling is a
		 * storefront presentation toggle in the same class as the Announcement,
		 * Shell and Design studios, which save through the Options API with no
		 * re-authentication. When the gate fired on this admin-post submission
		 * it redirected to the confirmation screen and the POST body — the
		 * toggle itself — was gone by the time the merchant came back: the page
		 * reloaded with the old value and nothing was saved. Capability, nonce
		 * and POST method remain, exactly as for every Options API studio.
		 */
		$before = self::settings();
		$input = isset( $_POST['live_selling'] ) && is_array( $_POST['live_selling'] ) ? wp_unslash( $_POST['live_selling'] ) : array();
		$settings = array(
			'enabled'          => empty( $input['enabled'] ) ? 0 : 1,
			'position'         => $input['position'] ?? 'bottom_left',
			'display_scope'    => $input['display_scope'] ?? 'site',
			'show_mobile'      => empty( $input['show_mobile'] ) ? 0 : 1,
			'show_desktop'     => empty( $input['show_desktop'] ) ? 0 : 1,
			'show_close'       => empty( $input['show_close'] ) ? 0 : 1,
			'label'            => $input['label'] ?? '',
			'message_template' => $input['message_template'] ?? '',
			'max_age'          => absint( $input['max_age_hours'] ?? 6 ) * HOUR_IN_SECONDS,
			'initial_delay'    => absint( $input['initial_delay_seconds'] ?? 9 ) * 1000,
			'display_duration' => absint( $input['display_duration_seconds'] ?? 6 ) * 1000,
			'poll_interval'    => absint( $input['poll_interval_seconds'] ?? 45 ) * 1000,
		);
		$clean = self::sanitize_settings( $settings );
		update_option( self::OPTION, $clean, false );
		self::$settings_cache = null;
		if ( wp_json_encode( $before ) !== wp_json_encode( $clean ) ) {
			/* Any change reaches guests only after the page cache is purged. */
			if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
				Delicat_Builder_V9_Cache::bump_version();
			}
			do_action( 'litespeed_purge_all' );
		}
		self::after_settings_change( ! empty( $before['enabled'] ), ! empty( $clean['enabled'] ) );
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit( 'builder_live_selling_settings_saved', 'notice' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=delicat-live-selling&settings=1&state=' . ( ! empty( $clean['enabled'] ) ? 'on' : 'off' ) ) );
		exit;
	}

	public static function admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$s = self::settings();
		$events = self::events();
		$last = ! empty( $events[0] ) && is_array( $events[0] ) ? $events[0] : array();
		?>
		<div class="wrap">
			<h1>🔥 <?php esc_html_e( 'Live Selling', 'delicat-builder-v9' ); ?></h1>
			<p>Affiche une preuve sociale légère basée uniquement sur de vraies commandes WooCommerce payées. Aucune identité client, adresse, e-mail, numéro de commande ou total n’est exposé.</p>
			<?php if ( isset( $_GET['settings'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Paramètres Live Selling enregistrés — état actuel : <strong><?php echo self::enabled() ? 'actif' : 'désactivé'; ?></strong><?php if ( self::enabled() && $events ) : ?>, <?php echo esc_html( sprintf( '%d vente(s) réelle(s) prête(s) à afficher.', count( $events ) ) ); ?><?php endif; ?></p></div><?php endif; ?>
			<div style="display:grid;grid-template-columns:minmax(340px,2fr) minmax(280px,1fr);gap:20px;max-width:1180px;align-items:start">
				<div class="card" style="max-width:none">
					<h2>Paramètres Live Selling</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="delicat_builder_v9_live_selling_save">
						<?php wp_nonce_field( 'delicat_builder_v9_live_selling_settings' ); ?>

						<p><label><input type="checkbox" name="live_selling[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <strong>Activer Live Selling</strong></label><br><small>Capture uniquement les commandes WooCommerce réellement payées / en traitement / terminées.</small></p>
						<hr>
						<table class="form-table" role="presentation"><tbody>
						<tr><th scope="row"><label for="dbv9-live-position">Position</label></th><td><select id="dbv9-live-position" name="live_selling[position]"><option value="bottom_left" <?php selected( $s['position'], 'bottom_left' ); ?>>Bas gauche</option><option value="bottom_right" <?php selected( $s['position'], 'bottom_right' ); ?>>Bas droite</option></select></td></tr>
						<tr><th scope="row"><label for="dbv9-live-scope">Pages</label></th><td><select id="dbv9-live-scope" name="live_selling[display_scope]"><option value="site" <?php selected( $s['display_scope'], 'site' ); ?>>Tout le site sauf panier / checkout / compte</option><option value="shop" <?php selected( $s['display_scope'], 'shop' ); ?>>Accueil + boutique + produits</option><option value="home" <?php selected( $s['display_scope'], 'home' ); ?>>Accueil uniquement</option></select></td></tr>
						<tr><th scope="row">Appareils</th><td><label><input type="checkbox" name="live_selling[show_mobile]" value="1" <?php checked( ! empty( $s['show_mobile'] ) ); ?>> Mobile</label>&nbsp;&nbsp;<label><input type="checkbox" name="live_selling[show_desktop]" value="1" <?php checked( ! empty( $s['show_desktop'] ) ); ?>> Desktop / tablette</label></td></tr>
						<tr><th scope="row"><label for="dbv9-live-label">Petit titre</label></th><td><input id="dbv9-live-label" class="regular-text" maxlength="60" name="live_selling[label]" value="<?php echo esc_attr( (string) $s['label'] ); ?>"><p class="description">Exemple : 🔥 Vente en direct</p></td></tr>
						<tr><th scope="row"><label for="dbv9-live-message">Message</label></th><td><input id="dbv9-live-message" class="large-text" maxlength="140" name="live_selling[message_template]" value="<?php echo esc_attr( (string) $s['message_template'] ); ?>"><p class="description">Utilisez obligatoirement <code>{product}</code> pour insérer le nom du produit.</p></td></tr>
						<tr><th scope="row"><label for="dbv9-live-initial">Premier affichage</label></th><td><input id="dbv9-live-initial" type="number" min="4" max="60" name="live_selling[initial_delay_seconds]" value="<?php echo esc_attr( (string) round( (int) $s['initial_delay'] / 1000 ) ); ?>"> secondes</td></tr>
						<tr><th scope="row"><label for="dbv9-live-duration">Durée d’affichage</label></th><td><input id="dbv9-live-duration" type="number" min="3" max="15" name="live_selling[display_duration_seconds]" value="<?php echo esc_attr( (string) round( (int) $s['display_duration'] / 1000 ) ); ?>"> secondes</td></tr>
						<tr><th scope="row"><label for="dbv9-live-poll">Vérification nouvelles ventes</label></th><td><input id="dbv9-live-poll" type="number" min="30" max="180" name="live_selling[poll_interval_seconds]" value="<?php echo esc_attr( (string) round( (int) $s['poll_interval'] / 1000 ) ); ?>"> secondes<p class="description">45 s recommandé pour rester léger.</p></td></tr>
						<tr><th scope="row"><label for="dbv9-live-age">Ancienneté maximale</label></th><td><input id="dbv9-live-age" type="number" min="1" max="24" name="live_selling[max_age_hours]" value="<?php echo esc_attr( (string) round( (int) $s['max_age'] / HOUR_IN_SECONDS ) ); ?>"> heures<p class="description">Une vente plus ancienne ne sera plus montrée.</p></td></tr>
						<tr><th scope="row">Bouton fermer</th><td><label><input type="checkbox" name="live_selling[show_close]" value="1" <?php checked( ! empty( $s['show_close'] ) ); ?>> Permettre au visiteur de fermer la notification</label></td></tr>
						</tbody></table>
						<?php submit_button( 'Enregistrer Live Selling' ); ?>
					</form>
				</div>

				<div>
					<div class="card" style="max-width:none"><h2>État</h2><p><strong><?php echo self::enabled() ? '✅ Actif' : '⏸️ Désactivé'; ?></strong></p><p><?php echo esc_html( sprintf( '%d vente(s) réelle(s) mémorisée(s).', count( $events ) ) ); ?></p><?php if ( $last ) : ?><p><strong>Dernière :</strong><br><?php echo esc_html( (string) ( $last['title'] ?? '' ) ); ?><br><small><?php echo esc_html( wp_date( 'd/m/Y H:i', absint( $last['created'] ?? 0 ) ) ); ?></small></p><?php else : ?><p><small>Aucune vente capturée depuis l’activation.</small></p><?php endif; ?></div>
					<div class="card" style="max-width:none"><h2>Aperçu</h2><div style="border:1px solid #dcdcde;border-radius:16px;padding:14px;background:#fff"><small style="display:block;color:#6c64e8;font-weight:700;margin-bottom:4px"><?php echo esc_html( (string) $s['label'] ); ?></small><strong style="display:block"><?php echo esc_html( str_replace( '{product}', 'Free Fire (LATAM)', (string) $s['message_template'] ) ); ?></strong><small style="display:block;color:#777;margin-top:5px">À l’instant</small></div><p class="description">Aperçu administratif seulement : aucune fausse vente n’est publiée.</p></div>
				</div>
			</div>
		</div>
		<?php
	}
}
