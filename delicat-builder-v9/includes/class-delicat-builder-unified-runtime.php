<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* RC18: the admin and runtime variants of this controller share one class
 * name; a second unconditional declaration is an uncatchable compile fatal. */
if ( ! class_exists( 'Delicat_Builder_V9_Unified_Modules', false ) ) :

/**
 * Safe migration/coexistence controller for functionality moved out of the
 * historical Delicat Store Builder.
 */
final class Delicat_Builder_V9_Unified_Modules {
	public const OPTION = 'delicat_builder_v9_unified_modules';
	private static bool $booted = false;
	private static array $runtime = array();

	public static function defaults(): array {
		return array(
			'pwa'            => 1,
			'notifications'  => 1,
			'swatches'       => 1,
			'multi_currency' => 1,
			'product_fields' => 1,
		);
	}

	public static function settings(): array {
		$value = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	public static function boot(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;
		self::maybe_load_web_push_transport();
		self::load_modules();
	}

	public static function legacy_builder_active(): bool {
		return defined( 'DSB_VERSION' ) || function_exists( 'dsb_available_modules' ) || function_exists( 'dsb_asset' );
	}

	public static function legacy_pwa_active(): bool {
		return function_exists( 'dsb_pwa_manifest_payload' )
			|| function_exists( 'dsb_pwa_manifest_response' )
			|| shortcode_exists( 'delicat_install_app' );
	}

	public static function legacy_notifications_active(): bool {
		return function_exists( 'dsbnh_render' ) || function_exists( 'dsbnh_items' );
	}

	public static function legacy_swatches_active(): bool {
		return class_exists( 'Delicat_Direct_Variation_Swatches', false );
	}

	public static function legacy_multi_currency_active(): bool {
		return function_exists( 'dmc_bootstrap' )
			|| class_exists( 'DMC_Plugin', false )
			|| class_exists( 'DMC_Currencies', false )
			|| defined( 'DMC_FILE' );
	}

	public static function legacy_product_fields_active(): bool {
		/*
		 * RC37.2 FATAL RECOVERY
		 * ---------------------
		 * The historical Builder registers dmc_bootstrap() at plugins_loaded:1,
		 * then declares its calculator later at plugins_loaded:20. RC37.1 checked
		 * only whether DMC_Calculator already existed at plugins_loaded:10, so it
		 * could load a second calculator first and the legacy priority-20 include
		 * then fatally redeclared the class.
		 *
		 * Treat the *pending bootstrap* as ownership too. This keeps V9 in Shadow
		 * before the legacy calculator class is declared and prevents the race.
		 */
		return class_exists( 'DMC_Calculator', false )
			|| function_exists( 'dmc_bootstrap' )
			|| defined( 'DMC_VERSION' )
			|| class_exists( 'DMC', false );
	}

	public static function legacy_notification_hidden_guard(): void {
		if ( is_admin() ) { return; }
		// RC37.5: V9 owns the visible notification UI during migration. Hide the
		// complete historical bell/panel so a stale portal/open-state can never
		// appear above the hero. The old module may still write to the shared
		// history option until it is disabled, but it no longer renders UI.
		echo '<style id="dbv9-legacy-notification-takeover">.dsb541-notifications,.dsb541-panel,.dsb541-panel.dsb541-panel-portal,body>.dsb541-panel.dsb541-panel-portal{display:none!important;visibility:hidden!important;opacity:0!important;pointer-events:none!important}</style>';
	}

	public static function suppress_legacy_notification_assets(): void {
		if ( is_admin() ) { return; }
		wp_dequeue_style( 'dsb541-notifications' );
		wp_dequeue_script( 'dsb541-notifications' );
	}

	private static function record_failure( string $module, Throwable $error ): void {
		$events = get_option( 'delicat_builder_v9_unified_failures', array() );
		$events = is_array( $events ) ? $events : array();
		$events[] = array(
			'time'   => gmdate( 'c' ),
			'module' => sanitize_key( $module ),
			'type'   => sanitize_text_field( get_class( $error ) ),
			'hash'   => hash( 'sha256', $module . '|' . $error->getMessage() . '|' . $error->getFile() . '|' . $error->getLine() ),
		);
		update_option( 'delicat_builder_v9_unified_failures', array_slice( $events, -10 ), false );
	}

	private static function set_runtime( string $module, string $status, string $detail = '' ): void {
		self::$runtime[ $module ] = array( 'status' => $status, 'detail' => $detail );
	}

	/**
	 * The 29 KB cryptographic Web Push transport is not needed to render normal
	 * storefront HTML. Load it only for its worker/REST endpoints and background
	 * delivery jobs; the bell receives a compact configuration below.
	 */
	private static function maybe_load_web_push_transport(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$rest_route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		$push_request = wp_doing_cron()
			|| ! empty( $_GET['delicat_v9_push_sw'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public worker route.
			|| false !== strpos( $uri, '/delicat-builder-v9/v1/push/subscription' )
			|| false !== strpos( $rest_route, '/delicat-builder-v9/v1/push/subscription' );
		if ( $push_request && function_exists( 'delicat_builder_v9_safe_require' ) ) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-web-push.php' );
		}
	}

	/** Lightweight page-side Web Push configuration; no crypto transport parse. */
	public static function web_push_client_config(): array {
		$enabled = (bool) get_option( 'delicat_builder_v9_web_push_enabled', 1 );
		$public = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) get_option( 'delicat_builder_v9_vapid_public', '' ) );
		if ( ! is_string( $public ) || strlen( $public ) < 80 || strlen( $public ) > 100 ) {
			$public = '';
		}
		$home_scheme = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		$available = $enabled && '' !== $public && function_exists( 'openssl_pkey_derive' ) && ( is_ssl() || 'https' === $home_scheme );
		$base = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$scope = is_string( $base ) && '' !== $base ? trailingslashit( $base ) : '/';
		$scope .= 'delicat-push/';
		$worker = add_query_arg(
			array( 'delicat_v9_push_sw' => '1', 'v' => defined( 'DELICAT_BUILDER_V9_VERSION' ) ? DELICAT_BUILDER_V9_VERSION : '1' ),
			home_url( '/' )
		);
		return array(
			'enabled'       => $enabled ? 1 : 0,
			'available'     => $available ? 1 : 0,
			'publicKey'     => $public,
			'endpoint'      => rest_url( Delicat_Builder_V9_Notifications::UI_REST_NAMESPACE . '/push/subscription' ),
			'nonce'         => wp_create_nonce( 'dbv9_web_push' ),
			'serviceWorker' => wp_make_link_relative( $worker ),
			'scope'         => $scope,
		);
	}

	private static function load_modules(): void {
		$settings = self::settings();

		self::load_one(
			'pwa',
			! empty( $settings['pwa'] ),
			self::legacy_pwa_active(),
			static function (): void {
				if ( ! class_exists( 'Delicat_Builder_V9_PWA', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
					delicat_builder_v9_safe_require( 'includes/class-delicat-builder-pwa.php' );
				}
				if ( class_exists( 'Delicat_Builder_V9_PWA', false ) ) { Delicat_Builder_V9_PWA::boot(); }
			}
		);

		if ( ! empty( $settings['notifications'] ) ) {
			$legacy_notifications = self::legacy_notifications_active();
			try {
				if ( function_exists( 'delicat_builder_v9_safe_require' ) ) {
					if ( ! class_exists( 'Delicat_Builder_V9_Notifications', false ) ) {
						delicat_builder_v9_safe_require( 'includes/class-delicat-builder-notifications.php' );
					}
				}
				if ( class_exists( 'Delicat_Builder_V9_Notifications', false ) ) {
					// During coexistence the historical module keeps its existing event
					// writers, while V9 owns REST + bell/panel UI. This prevents duplicate
					// WooCommerce order notifications and removes the broken legacy panel.
					Delicat_Builder_V9_Notifications::boot( ! $legacy_notifications );
				}
				self::set_runtime( 'notifications', $legacy_notifications ? 'active_bridge' : 'active', $legacy_notifications ? 'V9 owns notification UI; legacy writers may continue temporarily.' : 'Builder V9 owns this system.' );
			} catch ( Throwable $error ) {
				self::record_failure( 'notifications', $error );
				self::set_runtime( 'notifications', 'error', 'V9 kept notifications offline after a boot exception.' );
			}
			// RC37.6: suppress the historical notification UI unconditionally while
			// V9 notifications are enabled. Load-order detection is not trusted for
			// presentation ownership because legacy modules may bootstrap later.
			add_action( 'wp_head', array( __CLASS__, 'legacy_notification_hidden_guard' ), 2 );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'suppress_legacy_notification_assets' ), PHP_INT_MAX );
		} else {
			self::set_runtime( 'notifications', 'disabled', 'Disabled in Builder V9.' );
		}

		self::load_one(
			'swatches',
			! empty( $settings['swatches'] ),
			self::legacy_swatches_active(),
			static function (): void {
				// Swatches only affect variation forms. Keep their PHP/CSS/JS out of
				// homepage/archive/cart requests and attach after WP resolves the query.
				if ( is_admin() ) {
					self::boot_swatches_runtime();
				} else {
					add_action( 'wp', array( __CLASS__, 'boot_swatches_frontend' ), 2 );
				}
			}
		);

		self::load_one(
			'multi_currency',
			! empty( $settings['multi_currency'] ),
			self::legacy_multi_currency_active(),
			static function (): void {
				if ( ! class_exists( 'Delicat_Builder_V9_Multi_Currency', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
					delicat_builder_v9_safe_require( 'includes/class-delicat-builder-multi-currency.php' );
				}
				if ( class_exists( 'Delicat_Builder_V9_Multi_Currency', false ) ) { Delicat_Builder_V9_Multi_Currency::boot(); }
			}
		);

		$product_fields_enabled = ! empty( $settings['product_fields'] );
		$product_fields_legacy  = self::legacy_product_fields_active();
		if ( ! $product_fields_enabled ) {
			self::set_runtime( 'product_fields', 'disabled', 'Disabled in Builder V9.' );
		} elseif ( $product_fields_legacy ) {
			self::set_runtime( 'product_fields', 'shadow', 'Legacy implementation detected; V9 did not attach duplicate hooks.' );
		} elseif ( is_admin() || self::product_fields_need_early_boot() ) {
			self::load_one( 'product_fields', true, false, array( __CLASS__, 'load_product_fields' ) );
		} else {
			/*
			 * RC51.11: guests with an empty cart do not need ~50 KB of calculator
			 * PHP on the homepage/archive. Delay ownership until WP knows whether
			 * this is actually a product/cart/checkout request. Logged-in users,
			 * carts and add-to-cart/WC AJAX requests still boot early so Woo's
			 * cart-session restoration lifecycle is never missed.
			 */
			self::set_runtime( 'product_fields', 'deferred', 'Waiting for WooCommerce page context.' );
			add_action( 'wp', array( __CLASS__, 'boot_product_fields_frontend' ), -90 );
		}
	}

	public static function boot_swatches_frontend(): void {
		if ( function_exists( 'is_product' ) && is_product() ) {
			self::boot_swatches_runtime();
		}
	}

	private static function product_fields_need_early_boot(): bool {
		if ( is_admin() || wp_doing_ajax() ) { return true; }

		/*
		 * RC51.59: being logged in is not evidence of a cart. The blanket
		 * is_user_logged_in() branch parsed ~50 KB of calculator PHP on every
		 * homepage/archive request for every signed-in customer. Woo only has
		 * cart state to rehydrate when its session exists on this browser, and
		 * a session always travels as a cookie — so cookie presence is the
		 * exact early-boot condition. Add-to-cart, wc-ajax and POSTs (login can
		 * restore a persistent cart mid-request) still boot early so the
		 * session-rehydration filters are never missed.
		 */
		// Presence checks only; request values are never trusted or executed here.
		if ( ! empty( $_COOKIE['woocommerce_items_in_cart'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return true;
		}
		foreach ( array_keys( (array) $_COOKIE ) as $cookie_name ) {
			if ( 0 === strpos( (string) $cookie_name, 'wp_woocommerce_session_' ) ) {
				return true;
			}
		}
		if ( isset( $_REQUEST['add-to-cart'] ) || isset( $_REQUEST['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			return true;
		}
		return false;
	}

	public static function boot_product_fields_frontend(): void {
		$needed = ( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() );
		if ( ! $needed ) {
			self::set_runtime( 'product_fields', 'idle', 'Not required for this public request.' );
			return;
		}
		try {
			self::load_product_fields();
			if ( ! self::legacy_product_fields_active() ) {
				self::set_runtime( 'product_fields', 'active', 'Builder V9 owns this system.' );
			}
		} catch ( Throwable $error ) {
			self::record_failure( 'product_fields', $error );
			self::set_runtime( 'product_fields', 'error', 'V9 kept the module offline after a boot exception.' );
		}
	}

	private static function boot_swatches_runtime(): void {
		if ( ! class_exists( 'Delicat_Builder_V9_Swatches', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-swatches.php' );
		}
		if ( class_exists( 'Delicat_Builder_V9_Swatches', false ) ) {
			Delicat_Builder_V9_Swatches::instance();
		}
	}

	private static function load_one( string $module, bool $enabled, bool $legacy_active, callable $loader ): void {
		if ( ! $enabled ) { self::set_runtime( $module, 'disabled', 'Disabled in Builder V9.' ); return; }
		if ( $legacy_active ) { self::set_runtime( $module, 'shadow', 'Legacy implementation detected; V9 did not attach duplicate hooks.' ); return; }
		try {
			$loader();
			self::set_runtime( $module, 'active', 'Builder V9 owns this system.' );
		} catch ( Throwable $error ) {
			self::record_failure( $module, $error );
			self::set_runtime( $module, 'error', 'V9 kept the module offline after a boot exception.' );
		}
	}

	public static function load_product_fields(): void {
		if ( ! class_exists( 'WooCommerce' ) ) { return; }
		// Second guard closes any load-order race between the initial ownership
		// check and the actual module include.
		if ( self::legacy_product_fields_active() ) {
			self::set_runtime( 'product_fields', 'shadow', 'Legacy/pending DMC calculator owner detected; V9 did not attach duplicate hooks.' );
			return;
		}
		$base = DELICAT_BUILDER_V9_DIR . 'modules/product-fields/';
		$files = array( 'shim.php', 'class-dmc-calc-eval.php', 'class-dmc-calculator.php' );
		foreach ( $files as $file ) {
			$path = $base . $file;
			if ( ! is_readable( $path ) ) { throw new RuntimeException( 'Missing product-fields runtime file.' ); }
			require_once $path;
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Product_Fields_Calculator', false ) ) { throw new RuntimeException( 'Calculator class unavailable.' ); }
		$calculator = new Delicat_Builder_V9_Product_Fields_Calculator();
		$calculator->init();

		if ( is_admin() ) {
			$path = $base . 'class-dmc-calc-admin.php';
			if ( ! is_readable( $path ) ) { throw new RuntimeException( 'Missing calculator admin file.' ); }
			require_once $path;
			if ( class_exists( 'Delicat_Builder_V9_Product_Fields_Admin', false ) ) {
				$admin = new Delicat_Builder_V9_Product_Fields_Admin();
				$admin->init();
			}
		}
	}

	public static function module_active( string $module ): bool {
		$module = sanitize_key( $module );
		if ( '' === $module ) { return false; }
		$status = self::runtime_status();
		return in_array( (string) ( $status[ $module ]['status'] ?? '' ), array( 'active', 'active_bridge' ), true );
	}

	public static function runtime_status(): array {
		if ( ! self::$runtime ) {
			$settings = self::settings();
			foreach ( array_keys( self::defaults() ) as $module ) {
				if ( empty( $settings[ $module ] ) ) { self::set_runtime( $module, 'disabled' ); }
			}
		}
		return self::$runtime;
	}


}

endif; /* RC18 single-declaration guard */
