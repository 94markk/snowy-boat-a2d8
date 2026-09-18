<?php
/**
 * Delicat Builder V9 Pro — Kernel
 *
 * The Pro Kernel takes ownership of the four layers that V9 spread across
 * ~20 independent classes: route detection, asset delivery, navigation and
 * session state. Feature modules are untouched; only the coordination layers
 * are replaced, so every V9 shortcode, option key and render path is preserved.
 *
 * Requires PHP 8.5 (gated in the main plugin file).
 *
 * @package Delicat_Builder_V9_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'DBP_Kernel', false ) ) {
	return;
}

final class DBP_Kernel {

	const OPTION      = 'delicat_builder_v9_pro';
	const GENERATION  = 'delicat_builder_v9_pro_generation';
	const VERSION_KEY = 'delicat_builder_v9_pro_version';

	/** @var array|null */
	private static $settings = null;

	/** @var string */
	private static $route = '';

	/** @var bool */
	private static $booted = false;

	/**
	 * Feature defaults. Every layer can be switched off independently so a
	 * regression can be isolated to one subsystem without disabling Pro.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'            => 1,
			'navigation'         => 1,  /* single instant-navigation engine   */
			'assets'             => 1,  /* asset layer master switch          */
			'route_assets'       => 1,  /* stop shipping product-only bundles */
			'critical_css'       => 1,  /* inline the shell CSS (additive)    */
			'type_system'        => 1,  /* one type/colour/radius scale       */
			'defer_js'           => 0,  /* OFF: inline jQuery blocks break    */
			'state'              => 1,  /* single session/state store         */
			'security'           => 1,  /* hardened headers + endpoint limits */
			'boot_gate'          => 1,  /* route-aware PHP module loading     */
			'prefetch'           => 1,
			'transitions'        => 1,
			'skeletons'          => 1,
			'low_data_mode'      => 1,  /* degrade gracefully on 2G/3G        */
			'nav_cache_ttl'      => 120,
			'nav_cache_max'      => 24,
			'prefetch_budget'    => 6,
			'legacy_nav_off'     => 1,  /* silence V9's competing nav layers  */
			'legacy_slim_off'    => 1,  /* silence the front-slim dequeue war */
			'diagnostics'        => 0,
		);
	}

	/**
	 * Settings accessor. Reads once per request; unknown keys fall back to
	 * the defaults so a partially written option can never fatal a page.
	 *
	 * @return array
	 */
	public static function settings() {
		if ( null !== self::$settings ) {
			return self::$settings;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$defaults = self::defaults();
		$merged   = array();

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				$merged[ $key ] = $default;
				continue;
			}
			if ( is_int( $default ) && ! is_int( $stored[ $key ] ) ) {
				$merged[ $key ] = (int) $stored[ $key ];
			} else {
				$merged[ $key ] = $stored[ $key ];
			}
		}

		/* Numeric guards keep a hand-edited option from producing an unbounded
		 * client cache or a prefetch storm on a metered connection. */
		$merged['nav_cache_ttl']   = max( 15, min( 900, (int) $merged['nav_cache_ttl'] ) );
		$merged['nav_cache_max']   = max( 4, min( 60, (int) $merged['nav_cache_max'] ) );
		$merged['prefetch_budget'] = max( 0, min( 20, (int) $merged['prefetch_budget'] ) );

		self::$settings = $merged;

		return self::$settings;
	}

	/**
	 * Is a given Pro layer active?
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public static function on( $feature ) {
		if ( self::disabled_for_request() ) {
			return false;
		}

		$settings = self::settings();

		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		if ( '' === $feature || 'enabled' === $feature ) {
			return true;
		}

		return ! empty( $settings[ $feature ] );
	}

	/**
	 * Per-request escape hatch.
	 *
	 * Appending ?dbp=off to any URL loads that one page with every Pro layer
	 * inert. It exists so a bad build can never lock anyone out of the site
	 * while it is being diagnosed, including out of wp-admin.
	 *
	 * @return bool
	 */
	public static function disabled_for_request() {
		if ( defined( 'DBP_DISABLE' ) && DBP_DISABLE ) {
			return true;
		}
		if ( ! isset( $_GET['dbp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only recovery switch.
			return false;
		}

		return function_exists( 'wp_get_current_user' ) && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
			&& 'off' === sanitize_key( wp_unslash( (string) $_GET['dbp'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only recovery switch.
	}

	/**
	 * Cache generation. Bumping this invalidates every Pro-owned cache entry
	 * and every asset URL at once, which is how a deploy reaches a browser
	 * sitting behind LiteSpeed's combined stylesheet.
	 *
	 * @return string
	 */
	public static function generation() {
		$generation = get_option( self::GENERATION, '' );

		if ( ! is_string( $generation ) || '' === $generation ) {
			$generation = substr( md5( (string) time() ), 0, 10 );
			update_option( self::GENERATION, $generation, false );
		}

		return $generation;
	}

	/**
	 * Bump the generation and flush every cache layer we can reach.
	 *
	 * @return string New generation token.
	 */
	public static function bump_generation() {
		/* pro.37: wp_rand() lives in pluggable.php, which WordPress loads AFTER
		 * plugin files are included. Calling it from the boot path threw
		 * "Call to undefined function wp_rand()", the main file caught the
		 * Throwable, and the whole Pro layer — route-scoped assets, instant
		 * navigation, critical CSS, the state store — stayed off. random_int()
		 * is native, always available and cryptographically secure. */
		$entropy    = function_exists( 'wp_rand' ) ? wp_rand( 0, PHP_INT_MAX ) : random_int( 0, PHP_INT_MAX );
		$generation = substr( md5( (string) microtime( true ) . $entropy ), 0, 10 );
		update_option( self::GENERATION, $generation, false );

		wp_cache_flush();

		/* LiteSpeed keeps a combined CSS/JS artefact that survives a plugin
		 * update, so a version bump alone does not reach the browser. */
		do_action( 'litespeed_purge_all' );
		do_action( 'litespeed_purge_all_cssjs' );
		do_action( 'litespeed_purge_all_ccssucss' );

		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Purge: *' );
		}

		return $generation;
	}

	/**
	 * Classify the current request into one route bucket. Everything the Pro
	 * layers do — which PHP parses, which CSS ships, whether navigation may
	 * swap — keys off this single value.
	 *
	 * @return string
	 */
	public static function route() {
		if ( '' !== self::$route ) {
			return self::$route;
		}

		if ( is_admin() ) {
			self::$route = 'admin';
			return self::$route;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			self::$route = 'checkout';
		} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
			self::$route = 'cart';
		} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
			self::$route = 'account';
		} elseif ( is_page( array( 'my-wallet', 'wallet', 'woo-wallet', 'mon-portefeuille' ) ) ) {
			self::$route = 'wallet';
		} elseif ( function_exists( 'is_product' ) && is_product() ) {
			self::$route = 'product';
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			self::$route = 'shop';
		} elseif ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			self::$route = 'archive';
		} elseif ( is_search() ) {
			self::$route = 'search';
		} elseif ( is_front_page() || is_home() ) {
			self::$route = 'home';
		} elseif ( is_singular() ) {
			self::$route = 'page';
		} else {
			self::$route = 'other';
		}

		return self::$route;
	}

	/**
	 * Routes where navigation must never swap in place: anything holding a
	 * WooCommerce form, a payment step or a session mutation. These stay full
	 * document loads, which is correct, not a limitation.
	 *
	 * @return array
	 */
	public static function locked_routes() {
		return array( 'checkout', 'cart', 'account', 'wallet', 'product' );
	}

	/**
	 * Is this request the navigation engine asking for a page fragment?
	 *
	 * @return bool
	 */
	public static function is_fragment_request() {
		// Require both the dedicated URL and our fetch header. A normal browser
		// visit to ?dbp_nav=1 must remain an HTML document.
		return 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' )
			&& isset( $_GET['dbp_nav'], $_SERVER['HTTP_X_DELICAT_PRO_NAV'] )
			&& is_string( $_GET['dbp_nav'] )
			&& '1' === $_GET['dbp_nav']
			&& '1' === $_SERVER['HTTP_X_DELICAT_PRO_NAV'];
	}

	/**
	 * Connection profile, derived from request hints. The storefront's real
	 * audience is on slow mobile data and low-end handsets, so the server
	 * decides to ship less before the client ever gets a chance to.
	 *
	 * @return array
	 */
	public static function connection() {
		$save_data = false;
		$downlink  = 0.0;
		$ect       = '';

		if ( isset( $_SERVER['HTTP_SAVE_DATA'] ) ) {
			$header    = strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_SAVE_DATA'] ) ) );
			$save_data = ( false !== strpos( $header, 'on' ) );
		}
		if ( isset( $_SERVER['HTTP_DOWNLINK'] ) ) {
			$downlink = (float) sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_DOWNLINK'] ) );
		}
		if ( isset( $_SERVER['HTTP_ECT'] ) ) {
			$ect = sanitize_key( wp_unslash( (string) $_SERVER['HTTP_ECT'] ) );
		}

		$slow = $save_data
			|| ( $downlink > 0 && $downlink < 1.0 )
			|| ( '' !== $ect && in_array( $ect, array( 'slow-2g', '2g', '3g' ), true ) );

		return array(
			'save_data' => $save_data,
			'downlink'  => $downlink,
			'ect'       => $ect,
			'slow'      => $slow,
		);
	}

	/**
	 * Boot the Pro layers. Called from the plugin bootstrap after the V9
	 * constants exist and before any V9 module registers its hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		if ( ! self::on( '' ) ) {
			return;
		}

		/* pro.37: the deploy bump fires cache purges. At plugin-include time the
		 * object cache and LiteSpeed have not booted, so those purges reached
		 * nothing and a deploy never actually invalidated the browser's copy.
		 * Run it on init, where the cache layers exist and a purge is real. */
		add_action( 'init', array( __CLASS__, 'deploy_check' ), 1 );

		$dir = DELICAT_BUILDER_V9_DIR . 'pro/';

		/* pro.4: require_once on a missing file is E_COMPILE_ERROR, and the
		 * error is attributed to THIS file, not the missing one. An
		 * incomplete upload would therefore look like a kernel fault. Each
		 * layer is loaded only if it is actually on disk. */
		foreach ( array( 'security', 'state', 'nav', 'assets', 'type' ) as $layer ) {
			$path = $dir . 'class-dbp-' . $layer . '.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
		unset( $layer, $path );

		if ( self::on( 'security' ) && class_exists( 'DBP_Security', false ) ) {
			DBP_Security::boot();
		}
		if ( self::on( 'state' ) && class_exists( 'DBP_State', false ) ) {
			DBP_State::boot();
		}
		if ( self::on( 'navigation' ) && class_exists( 'DBP_Nav', false ) ) {
			DBP_Nav::boot();
		}
		if ( self::on( 'assets' ) && class_exists( 'DBP_Assets', false ) ) {
			DBP_Assets::boot();
		}
		if ( self::on( 'type_system' ) && class_exists( 'DBP_Type', false ) ) {
			DBP_Type::boot();
		}

		/* pro.37: the recorded boot failure is sticky — nothing ever cleared it,
		 * so Delicat Pro kept reporting a fault that had already been fixed and
		 * kept claiming scoped assets were off. Reaching this line means every
		 * layer above booted, so the record is stale. Cleared on admin requests
		 * only: that is where it is read, and the storefront pays nothing. */
		if ( is_admin() && '' !== (string) get_option( 'delicat_builder_v9_pro_boot_error', '' ) ) {
			delete_option( 'delicat_builder_v9_pro_boot_error' );
		}

		if ( is_admin() && file_exists( $dir . 'class-dbp-admin.php' ) ) {
			require_once $dir . 'class-dbp-admin.php';
			if ( class_exists( 'DBP_Admin', false ) ) {
				DBP_Admin::boot();
			}
		}

		/* The legacy layers are quieted last so a module that registered on an
		 * earlier hook is still removed cleanly. */
		add_action( 'wp', array( __CLASS__, 'quiet_legacy_layers' ), PHP_INT_MAX );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'quiet_legacy_layers' ), PHP_INT_MAX );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'quiet_legacy_layers' ), 0 );
	}

	/**
	 * Detect a version change and flush. Without this a new build sits on
	 * disk while the browser keeps receiving the previous combined asset.
	 *
	 * @return void
	 */
	public static function deploy_check() {
		$running  = defined( 'DELICAT_BUILDER_V9_VERSION' ) ? (string) DELICAT_BUILDER_V9_VERSION : '';
		$recorded = (string) get_option( self::VERSION_KEY, '' );

		if ( '' === $running || $running === $recorded ) {
			return;
		}

		update_option( self::VERSION_KEY, $running, false );
		self::$settings = null;
		self::bump_generation();
	}

	/**
	 * Remove the V9 layers the Pro Kernel replaces.
	 *
	 * V9 ran three navigation engines (shell-nav, navigation, shell), two
	 * prefetchers and a dequeue pass at priority 999/9999 that fought the
	 * enqueues at priority 1-45. Leaving any of them attached alongside the
	 * Pro engine reproduces exactly the races this rebuild exists to end.
	 *
	 * @return void
	 */
	public static function quiet_legacy_layers() {
		if ( is_admin() ) {
			return;
		}

		if ( self::on( 'navigation' ) && self::on( 'legacy_nav_off' ) ) {
			wp_dequeue_script( 'delicat-builder-v9-shell-nav' );
			wp_dequeue_script( 'delicat-builder-v9-prefetch' );
			wp_dequeue_script( 'delicat-builder-v9-navigation' );
			wp_deregister_script( 'delicat-builder-v9-shell-nav' );
			wp_deregister_script( 'delicat-builder-v9-prefetch' );
			wp_deregister_script( 'delicat-builder-v9-navigation' );

			/* pro.16: TurboNav's speculation rules stay. They bind no clicks;
			 * with the Pro engine active TurboNav restricts them to the routes
			 * the engine never swaps (product pages), which is exactly where a
			 * prerendered document makes the open instant. */
			if ( class_exists( 'Delicat_Builder_V9_Shell_Nav', false ) ) {
				remove_action( 'wp_enqueue_scripts', array( 'Delicat_Builder_V9_Shell_Nav', 'assets' ), 9 );
			}
		}

		if ( self::on( 'assets' ) && self::on( 'legacy_slim_off' ) && class_exists( 'Delicat_Builder_V9_Front_Slim', false ) ) {
			remove_action( 'wp_enqueue_scripts', array( 'Delicat_Builder_V9_Front_Slim', 'slim_scripts' ), 999 );
			remove_action( 'wp_enqueue_scripts', array( 'Delicat_Builder_V9_Front_Slim', 'defer_plugin_scripts' ), 9999 );
		}
	}

	/**
	 * Versioned asset URL for a Pro asset.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	public static function asset_url( $relative ) {
		return DELICAT_BUILDER_V9_URL . ltrim( (string) $relative, '/' );
	}

	/**
	 * Asset version string: plugin version plus cache generation.
	 *
	 * @return string
	 */
	public static function asset_version() {
		$version = defined( 'DELICAT_BUILDER_V9_VERSION' ) ? (string) DELICAT_BUILDER_V9_VERSION : '9';

		return $version . '.' . self::generation();
	}
}
