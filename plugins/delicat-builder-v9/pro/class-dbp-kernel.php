<?php
/**
 * Delicat Builder V9 Pro — Kernel
 *
 * The Pro Kernel takes ownership of the four layers that V9 spread across
 * ~20 independent classes: route detection, asset delivery, navigation and
 * session state. Feature modules are untouched; only the coordination layers
 * are replaced, so every V9 shortcode, option key and render path is preserved.
 *
 * Requires PHP 8.3 (gated in the main plugin file).
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
	 * Bump the generation and, for the explicit Delicat Pro actions (save, purge button), purge the caches that
	 * keep pages and combined assets.
	 *
	 * V10: never wp_cache_flush(). It emptied the WHOLE object cache (Redis): every plugin's and WooCommerce's
	 * data, Identity Pro's sign-in and OAuth state, every rate-limit window, so each release restarted the store
	 * cold and reset its throttles. Builder's own cached fragments and queries are keyed on its cache version,
	 * which Delicat_Builder_V9_Cache::bump_version() renews; nothing else needs to go.
	 *
	 * @param bool $purge False to only renew the token (the deploy check: the release purge is owned by
	 *                    Delicat_Builder_V9_Cache::maybe_purge_after_upgrade(), once per version).
	 * @return string New generation token.
	 */
	public static function bump_generation( $purge = true ) {
		/* pro.37: wp_rand() lives in pluggable.php, which WordPress loads AFTER
		 * plugin files are included. Calling it from the boot path threw
		 * "Call to undefined function wp_rand()", the main file caught the
		 * Throwable, and the whole Pro layer — route-scoped assets, instant
		 * navigation, critical CSS, the state store — stayed off. random_int()
		 * is native, always available and cryptographically secure. */
		$entropy    = function_exists( 'wp_rand' ) ? wp_rand( 0, PHP_INT_MAX ) : random_int( 0, PHP_INT_MAX );
		$generation = substr( md5( (string) microtime( true ) . $entropy ), 0, 10 );
		update_option( self::GENERATION, $generation, false );

		if ( ! $purge ) {
			return $generation;
		}

		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}

		/* LiteSpeed keeps a combined CSS/JS artefact that survives a plugin
		 * update, so a version bump alone does not reach the browser. Its
		 * purge-all sends the page-cache purge header itself. */
		do_action( 'litespeed_purge_all' );
		do_action( 'litespeed_purge_all_cssjs' );
		do_action( 'litespeed_purge_all_ccssucss' );

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
	 * Was this request the old navigation engine asking for a page fragment?
	 *
	 * V10: never. The fragment protocol (?dbp_nav=1 with an X-Delicat-Pro-Nav header) had no client left: the
	 * engine fetches ordinary HTML, and no shipped script or app sends that header. Kept for callers outside this
	 * plugin.
	 *
	 * @return bool
	 */
	public static function is_fragment_request() {
		return false;
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
		 * layer is loaded only if it is actually on disk.
		 *
		 * V10: the security layer answers every request type; navigation, assets and type only shape pages, so
		 * the REST, wc-ajax, session, WP-Cron, admin-ajax and wp-admin profiles no longer parse them (each of
		 * them returned at once outside the storefront anyway). The state layer is retired (see state_gone()). */
		$page_layers = ! defined( 'DELICAT_BUILDER_V9_PROFILE' ) || 'storefront' === DELICAT_BUILDER_V9_PROFILE;
		foreach ( $page_layers ? array( 'security', 'nav', 'assets', 'type' ) : array( 'security' ) as $layer ) {
			$path = $dir . 'class-dbp-' . $layer . '.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
		unset( $layer, $path, $page_layers );

		if ( self::on( 'security' ) && class_exists( 'DBP_Security', false ) ) {
			DBP_Security::boot();
		}
		if ( isset( $_GET['dbp_state'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
			add_action( 'init', array( __CLASS__, 'state_gone' ), 5 );
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

		/* V10: its menu and admin-post actions only; admin-ajax (heartbeat) never needs it. */
		if ( is_admin() && ! wp_doing_ajax() && file_exists( $dir . 'class-dbp-admin.php' ) ) {
			require_once $dir . 'class-dbp-admin.php';
			if ( class_exists( 'DBP_Admin', false ) ) {
				DBP_Admin::boot();
			}
		}

		/* The legacy scripts are quieted last so one enqueued on an earlier hook is
		 * still removed cleanly. V10: Shell Nav and Front Slim's dequeue passes are gone,
		 * so nothing has to be unhooked at `wp` any more. */
		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'quiet_legacy_layers' ), PHP_INT_MAX );
			add_action( 'wp_print_footer_scripts', array( __CLASS__, 'quiet_legacy_layers' ), 0 );
		}
	}

	/**
	 * V10: the ?dbp_state=1 snapshot endpoint is retired. It answered anyone with the display name, the wallet
	 * balance, the cart totals and fresh nonces, and no client calls it any more (the engine reads
	 * wc-ajax=delicat_session). For one release it answers 410 Gone, uncached, so a forgotten caller gets a clear
	 * refusal instead of a full uncached page render.
	 *
	 * @return void
	 */
	public static function state_gone() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'LITESPEED_NO_OPTM' ) ) {
			define( 'LITESPEED_NO_OPTM', true );
		}
		add_filter( 'litespeed_comment', '__return_false', PHP_INT_MAX );
		do_action( 'litespeed_control_set_nocache', 'Delicat state endpoint retired' );
		nocache_headers();
		status_header( 410 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		echo '{"gone":true}';
		exit;
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
		/* V10: the token only. The release purge (one LiteSpeed purge-all, the Builder cache version, LiteSpeed's
		 * combined CSS/JS) is done once per version by Delicat_Builder_V9_Cache::maybe_purge_after_upgrade() at
		 * init 11; this used to flush the whole object cache and purge LiteSpeed a second time. */
		self::bump_generation( false );
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
			/* V10: shell-nav.js (Shell_Nav) and the never-registered 'delicat-builder-v9-navigation' are gone;
			 * only the legacy prefetcher can still be enqueued (engine off). */
			wp_dequeue_script( 'delicat-builder-v9-prefetch' );
			wp_deregister_script( 'delicat-builder-v9-prefetch' );
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
