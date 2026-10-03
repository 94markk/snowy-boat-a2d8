<?php
/**
 * Delicat Builder V9 — Engine delivery (9.3).
 *
 * The storefront runtime is one ES module (assets/dist/engine.<hash>.js, built
 * from src/engine by tools/build.mjs) plus three stylesheets per page:
 *
 *   chrome   theme + header + drawer + bottom bar + small always-on widgets
 *   route    the owner of the page type (Builder components, native product,
 *            archive, documents, cart/checkout …)
 *   polish   the late correction layers (app-polish, app-tuning,
 *            storefront-polish, dbp-app, dbp-type) that decide the final look
 *
 * Every file inside a bundle is concatenated in the order WordPress used to
 * print it, so the cascade is unchanged. This class does the bookkeeping:
 *
 *   1. it picks the route bundle for the request;
 *   2. it dequeues every legacy stylesheet whose file is inside the chosen
 *      bundles and re-prints their inline additions after the route bundle
 *      (merchant colours, radii, max widths …); a stylesheet that is not part
 *      of the chosen bundles is left exactly as it was;
 *   3. it dequeues the fourteen legacy scripts the engine replaces and carries
 *      their configuration blocks (window.DelicaBuilderV9, DelicatExpress …)
 *      into one inline block the engine reads, so every feature keeps the
 *      configuration object it had;
 *   4. it prints the module script with modulepreload hints, early in <head>.
 *
 * Nothing here changes what a feature renders. Switch the engine off with the
 * `delicat_builder_v9_engine_active` filter (or `?dbv9_engine=0` as an
 * administrator) and the storefront falls back to the previous per-file
 * delivery, bytes for bytes.
 *
 * @package Delicat_Builder_V9
 */

defined( 'ABSPATH' ) || exit;

final class Delicat_Builder_V9_Engine {

	const VERSION       = '9.3.0';
	const DIST          = 'assets/dist/';
	const HANDLE_CHROME = 'delicat-engine-chrome';
	const HANDLE_ROUTE  = 'delicat-engine-route';
	const HANDLE_POLISH = 'delicat-engine-polish';

	/** Legacy script handles whose behaviour now lives inside the engine module. */
	const SCRIPTS = array(
		'delicat-builder-v9-core', 'delicat-builder-v9-carousel', 'delicat-builder-v9-islands', 'delicat-builder-v9-heart',
		'delicat-builder-v9-hero-search', 'delicat-builder-v9-prefetch', 'delicat-builder-v9-navigation', 'delicat-builder-v9-shell',
		'delicat-builder-v9-theme', 'delicat-builder-v9-motion', 'delicat-builder-v9-header-studio-8', 'delicat-builder-v9-drawer',
		'delicat-builder-v9-session', 'delicat-builder-v9-shell-nav', 'delicat-builder-v9-mobile-dock', 'delicat-builder-v9-purchase-native',
		'delicat-builder-v9-purchase', 'delicat-builder-v9-express', 'delicat-builder-v9-wallet-guard', 'delicat-v9-native-product',
		'delicat-builder-v9-design-product', 'delicat-builder-v9-pwa-runtime', 'delicat-builder-v9-notifications', 'delicat-builder-v9-currency',
		'delicat-builder-v9-live-selling', 'delicat-builder-v9-announcement', 'delicat-builder-v9-reviews', 'delicat-builder-v9-storefront-commerce',
		'delicat-builder-v9-wallet-live', 'delicat-product-switcher', 'dmc-calc', 'delicat-android-app-link', 'dbp-nav', 'dbp-state',
		'delicat-app-polish-pull-refresh',
	);

	/** The release bundle header-runtime enqueues; the engine knows what it contains. */
	const CHROME_BUNDLE_FILES = array( 'assets/css/theme-system.css', 'assets/dsb8-beta2-header.css', 'assets/css/drawer.css', 'assets/css/bottom-nav.css' );

	/** @var bool */
	private static $booted = false;
	/** @var bool|null */
	private static $active = null;
	/** @var array|null */
	private static $manifest = null;
	/** @var bool */
	private static $shaped = false;
	/** @var string */
	private static $route = 'page';
	/** @var string */
	private static $route_bundle = 'page';
	/** @var string */
	private static $polish_bundle = 'polish';
	/** @var array<string,string> rel path => bundle */
	private static $covered = array();
	/** @var array<string,bool> */
	private static $absorbed = array();
	/** @var string[] */
	private static $code = array();
	/** @var string[] */
	private static $globals = array();
	/** @var string[] */
	private static $absorbed_styles = array();

	/* ------------------------------------------------------------ boot */

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_chrome' ), 0 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'shape' ), 100500 );
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 2 );
		add_action( 'wp_body_open', array( __CLASS__, 'progress_bar' ), 1 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'absorb_late' ), 1 );
		foreach ( array( 'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc', 'litespeed_optm_gm_js_exc', 'litespeed_optimize_css_excludes', 'litespeed_optm_css_async_exc', 'litespeed_optm_ucss_exc' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'litespeed_exclusions' ), PHP_INT_MAX );
		}
		add_filter( 'delicat_builder_v9_session_payload', array( __CLASS__, 'session_payload' ), 5 );
	}

	/**
	 * Is the engine delivering this request?
	 */
	public static function active(): bool {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$active = ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( $active && function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			$active = false;
		}
		if ( $active && did_action( 'wp' ) && ( is_feed() || is_embed() ) ) {
			$active = false;
		}
		if ( $active && class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) ) && ! Delicat_Builder_V9_Core::is_enabled() ) {
			$active = false;
		}
		if ( $active && null === self::manifest() ) {
			$active = false;
		}
		if ( $active && isset( $_GET['dbv9_engine'] ) && '0' === (string) $_GET['dbv9_engine'] && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- administrator debugging switch, read only.
			$active = false;
		}
		$active = (bool) apply_filters( 'delicat_builder_v9_engine_active', $active );
		if ( did_action( 'wp' ) ) {
			self::$active = $active;
		}
		return $active;
	}

	/** The build manifest, or null when no build is present. */
	private static function manifest(): ?array {
		if ( null !== self::$manifest ) {
			return self::$manifest ? self::$manifest : null;
		}
		$file = DELICAT_BUILDER_V9_DIR . self::DIST . 'manifest.json';
		$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local build artefact.
		if ( ! is_array( $data ) || empty( $data['js']['engine']['file'] ) || empty( $data['css']['chrome']['file'] ) || empty( $data['css']['polish']['file'] ) ) {
			self::$manifest = array();
			return null;
		}
		self::$manifest = $data;
		return $data;
	}

	private static function url( string $file ): string {
		return DELICAT_BUILDER_V9_URL . self::DIST . ltrim( $file, '/' );
	}

	/** Plugin-relative path of an asset URL, or '' when it is not a plugin asset. */
	private static function relative( string $url ): string {
		static $base = null;
		if ( null === $base ) {
			$base = (string) preg_replace( '#^https?://#i', '//', DELICAT_BUILDER_V9_URL );
		}
		$bare = (string) preg_replace( '#^https?://#i', '//', $url );
		if ( '' === $bare || 0 !== strpos( $bare, $base ) ) {
			return '';
		}
		$rel = substr( $bare, strlen( $base ) );
		$rel = (string) strtok( (string) $rel, '?#' );
		return ltrim( $rel, '/' );
	}

	/* ----------------------------------------------------------- styles */

	public static function enqueue_chrome(): void {
		if ( ! self::active() ) {
			return;
		}
		$manifest = self::manifest();
		wp_register_style( self::HANDLE_CHROME, self::url( (string) $manifest['css']['chrome']['file'] ), array(), null );
		wp_enqueue_style( self::HANDLE_CHROME );
	}

	/** Route family of the request: decides the route bundle. */
	private static function detect_route(): string {
		$route = 'page';
		if ( function_exists( 'is_product' ) && is_product() ) {
			$route = 'product';
		} elseif ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			$route = 'page';
		} elseif ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			$route = 'purchase';
		} elseif ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) {
			$route = ( wp_style_is( 'delicat-builder-v9-woo-ui', 'enqueued' ) && ! wp_style_is( 'delicat-builder-v9-native-archive', 'enqueued' ) ) ? 'woo' : 'shop';
		} elseif ( is_singular( 'page' ) && function_exists( 'delicat_builder_v9_is_managed_page' ) && delicat_builder_v9_is_managed_page( (int) get_queried_object_id() ) ) {
			$route = 'home';
		} elseif ( self::builder_page() ) {
			$route = 'builder';
		} elseif ( wp_style_is( 'delicat-builder-v9-woo-ui', 'enqueued' ) ) {
			$route = 'woo';
		}
		return (string) apply_filters( 'delicat_builder_v9_engine_route', $route );
	}

	/**
	 * The route bundle for this request: the route name with the variants the
	 * page needs (`-lite` when its compiled component sheet is fresh and stays,
	 * `-design` when Design Studio enqueued its sheet), falling back to the
	 * plain name and then to `page`.
	 */
	private static function resolve_bundle( string $route, array $css ): string {
		$name = $route;
		if ( 'home' === $route && ! self::home_components_covered( $css ) ) {
			$name = 'builder';
		}
		$lite   = wp_style_is( 'delicat-builder-v9-compiled', 'enqueued' ) && in_array( $name, array( 'home', 'builder' ), true );
		$design = wp_style_is( 'delicat-builder-v9-design', 'enqueued' );
		$candidates = array();
		if ( $lite && $design ) {
			$candidates[] = $name . '-lite-design';
		}
		if ( $lite ) {
			$candidates[] = $name . '-lite';
		}
		if ( $design ) {
			$candidates[] = $name . '-design';
		}
		$candidates[] = $name;
		$candidates[] = 'page';
		foreach ( $candidates as $candidate ) {
			if ( isset( $css[ $candidate ]['file'] ) ) {
				return $candidate;
			}
		}
		return 'page';
	}

	/** Does the `home` bundle carry every component the homepage layout uses? */
	private static function home_components_covered( array $css ): bool {
		if ( ! class_exists( 'Delicat_Builder_V9_Assets', false ) || ! is_callable( array( 'Delicat_Builder_V9_Assets', 'page_components' ) ) ) {
			return true;
		}
		$needed = Delicat_Builder_V9_Assets::page_components();
		if ( empty( $needed ) ) {
			return true;
		}
		$have = array();
		foreach ( (array) ( $css['home']['sources'] ?? array() ) as $file ) {
			if ( preg_match( '#^assets/components/([a-z0-9-]+)\.css$#', (string) $file, $m ) ) {
				$have[ $m[1] ] = true;
			}
		}
		foreach ( $needed as $component ) {
			if ( ! isset( $have[ sanitize_key( (string) $component ) ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** A Builder page (any page whose Builder component CSS is on the queue). */
	private static function builder_page(): bool {
		if ( wp_style_is( 'delicat-builder-v9-components', 'enqueued' ) || wp_style_is( 'delicat-builder-v9-compiled', 'enqueued' ) ) {
			return true;
		}
		if ( is_singular() && class_exists( 'Delicat_Builder_V9_Pages', false ) && defined( 'Delicat_Builder_V9_Pages::META_ENABLED' ) && get_post_meta( (int) get_queried_object_id(), (string) Delicat_Builder_V9_Pages::META_ENABLED, true ) ) {
			return true;
		}
		$styles = wp_styles();
		foreach ( $styles->queue as $handle ) {
			$obj = $styles->registered[ $handle ] ?? null;
			if ( $obj && is_string( $obj->src ) && 0 === strpos( self::relative( $obj->src ), 'assets/components/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Component files, in bundle order, read from the manifest. */
	private static function component_files(): array {
		$manifest = self::manifest();
		$out      = array();
		foreach ( (array) ( $manifest['css']['home']['sources'] ?? array() ) as $file ) {
			if ( 0 === strpos( (string) $file, 'assets/components/' ) ) {
				$out[] = (string) $file;
			}
		}
		return $out;
	}

	/** Source files a legacy style handle stands for, or [] when it must be left alone. */
	private static function style_files( string $handle ): array {
		$styles = wp_styles();
		$obj    = $styles->registered[ $handle ] ?? null;
		if ( ! $obj || ! is_string( $obj->src ) || '' === $obj->src ) {
			return array(); /* inline-only handles (swatches) print as they always did */
		}
		if ( 'delicat-builder-v9-compiled' === $handle ) {
			return self::component_files();
		}
		$rel = self::relative( $obj->src );
		if ( '' === $rel ) {
			return array();
		}
		if ( 'assets/css/storefront-chrome.min.css' === $rel ) {
			return self::CHROME_BUNDLE_FILES;
		}
		if ( 'assets/components/all-components.min.css' === $rel ) {
			return self::component_files();
		}
		$rel = (string) preg_replace( '/\.min\.css$/', '.css', $rel );
		return array( $rel );
	}

	/**
	 * Choose the bundles, fold the legacy stylesheets into them and absorb the
	 * legacy scripts. Runs after every module has enqueued.
	 */
	public static function shape(): void {
		if ( self::$shaped || ! self::active() ) {
			return;
		}
		self::$shaped = true;
		$manifest = self::manifest();
		$css      = $manifest['css'];

		self::$route = self::detect_route();
		$bundle      = self::resolve_bundle( self::$route, $css );
		self::$route_bundle  = $bundle;
		self::$polish_bundle = ( wp_style_is( 'delicat-builder-v9-storefront-polish', 'enqueued' ) && isset( $css['polish-storefront'] ) ) ? 'polish-storefront' : 'polish';

		self::$covered = array();
		foreach ( array( 'chrome', $bundle, self::$polish_bundle ) as $name ) {
			foreach ( (array) ( $css[ $name ]['sources'] ?? array() ) as $file ) {
				self::$covered[ (string) $file ] = $name;
			}
		}

		$inline = self::fold_styles();

		wp_register_style( self::HANDLE_ROUTE, self::url( (string) $css[ $bundle ]['file'] ), array( self::HANDLE_CHROME ), null );
		wp_enqueue_style( self::HANDLE_ROUTE );
		if ( '' !== $inline ) {
			wp_add_inline_style( self::HANDLE_ROUTE, $inline );
		}
		wp_register_style( self::HANDLE_POLISH, self::url( (string) $css[ self::$polish_bundle ]['file'] ), array( self::HANDLE_ROUTE ), null );
		wp_enqueue_style( self::HANDLE_POLISH );

		$absorbed      = self::absorb_scripts();
		self::$code    = $absorbed['code'];
		self::$globals = $absorbed['names'];
	}

	/**
	 * Dequeue every queued legacy stylesheet whose files are inside the chosen
	 * bundles; return their inline additions, in queue order.
	 */
	private static function fold_styles(): string {
		$styles = wp_styles();
		$inline = array();
		$folded = array();
		foreach ( $styles->queue as $handle ) {
			if ( in_array( $handle, array( self::HANDLE_CHROME, self::HANDLE_ROUTE, self::HANDLE_POLISH ), true ) || isset( self::$absorbed_styles[ $handle ] ) ) {
				continue;
			}
			$files = self::style_files( $handle );
			if ( empty( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				if ( ! isset( self::$covered[ $file ] ) ) {
					continue 2;
				}
			}
			$after = $styles->get_data( $handle, 'after' );
			if ( is_array( $after ) && ! empty( $after ) ) {
				$inline[] = "/* {$handle} */\n" . implode( "\n", array_map( 'strval', $after ) );
			}
			$folded[] = $handle;
		}
		foreach ( $folded as $handle ) {
			self::$absorbed_styles[ $handle ] = true;
			wp_dequeue_style( $handle );
		}
		/* A dependent must not pull a folded sheet back in, and must not be
		 * dropped because its dependency is gone. */
		if ( $folded ) {
			foreach ( $styles->registered as $name => $obj ) {
				if ( isset( self::$absorbed_styles[ $name ] ) || empty( $obj->deps ) ) {
					continue;
				}
				$obj->deps = array_values( array_diff( (array) $obj->deps, $folded ) );
			}
		}
		return implode( "\n", $inline );
	}

	/* ---------------------------------------------------------- scripts */

	/**
	 * Dequeue the legacy scripts the engine replaces and collect the
	 * configuration they carried (localize data, before/after inline code).
	 *
	 * @return array{code:string[],names:string[]}
	 */
	private static function absorb_scripts(): array {
		$scripts = wp_scripts();
		$code    = array();
		$deps    = array();
		$taken   = array();
		foreach ( self::SCRIPTS as $handle ) {
			if ( ! in_array( $handle, $scripts->queue, true ) ) {
				continue;
			}
			$obj = $scripts->registered[ $handle ] ?? null;
			if ( ! $obj ) {
				continue;
			}
			if ( isset( self::$absorbed[ $handle ] ) ) {
				/* Enqueued again while rendering (the header re-enqueues itself
				 * at wp_body_open): its configuration was already carried. */
				wp_dequeue_script( $handle );
				continue;
			}
			self::$absorbed[ $handle ] = true;
			$taken[]                   = $handle;
			$data = $scripts->get_data( $handle, 'data' );
			if ( is_string( $data ) && '' !== trim( $data ) ) {
				$code[] = trim( $data );
			}
			foreach ( array( 'before', 'after' ) as $position ) {
				$chunks = $scripts->get_data( $handle, $position );
				if ( is_array( $chunks ) ) {
					foreach ( $chunks as $chunk ) {
						if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
							$code[] = trim( $chunk );
						}
					}
				}
			}
			foreach ( (array) $obj->deps as $dep ) {
				if ( ! in_array( $dep, self::SCRIPTS, true ) ) {
					$deps[] = (string) $dep;
				}
			}
			wp_dequeue_script( $handle );
		}
		if ( $taken ) {
			foreach ( $scripts->registered as $name => $obj ) {
				if ( isset( self::$absorbed[ $name ] ) || empty( $obj->deps ) ) {
					continue;
				}
				$obj->deps = array_values( array_diff( (array) $obj->deps, $taken ) );
			}
			/* jQuery, the variation form … stay on the page exactly as before. */
			foreach ( array_unique( $deps ) as $dep ) {
				if ( isset( $scripts->registered[ $dep ] ) && ! in_array( $dep, $scripts->queue, true ) ) {
					wp_enqueue_script( $dep );
				}
			}
		}
		$names = array();
		if ( $code && preg_match_all( '/(?:^|[;\s{(])(?:window\.|var\s+)([A-Za-z_$][\w$]*)\s*=(?!=)/', implode( "\n", $code ), $m ) ) {
			$names = array_values( array_unique( $m[1] ) );
		}
		return array( 'code' => $code, 'names' => $names );
	}

	/* ------------------------------------------------------------- head */

	/** Engine configuration printed as window.DelicatEngine. */
	private static function config(): array {
		$nav = array(
			'enabled'     => true,
			'products'    => true,
			'prefetch'    => true,
			'transitions' => true,
			'budget'      => 8,
			'cacheTtl'    => 60000,
			'cacheMax'    => 14,
			'generation'  => self::generation(),
			'home'        => home_url( '/' ),
			'hardPaths'   => self::hard_paths(),
			'productPaths'=> self::product_paths(),
		);
		if ( class_exists( 'DBP_Kernel', false ) && is_callable( array( 'DBP_Kernel', 'settings' ) ) ) {
			$settings           = (array) DBP_Kernel::settings();
			$nav['enabled']     = DBP_Kernel::on( 'navigation' );
			$nav['prefetch']    = DBP_Kernel::on( 'prefetch' );
			$nav['transitions'] = DBP_Kernel::on( 'transitions' );
			$nav['budget']      = max( 2, min( 16, (int) ( $settings['prefetch_budget'] ?? 6 ) + 2 ) );
			$nav['cacheTtl']    = max( 15, (int) ( $settings['nav_cache_ttl'] ?? 120 ) ) * 1000;
			$nav['cacheMax']    = max( 4, (int) ( $settings['nav_cache_max'] ?? 24 ) );
		}
		$session = array(
			'url'      => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'delicat_session' ) : home_url( '/?wc-ajax=delicat_session' ),
			'resetUrl' => class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'auth_reset_url' ) ) ? Delicat_Builder_V9_Identity_Bridge::auth_reset_url() : home_url( '/' ),
		);
		$config = array(
			'version'     => self::VERSION,
			'debug'       => (bool) ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ),
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'homeUrl'     => home_url( '/' ),
			'route'       => self::$route,
			'nav'         => $nav,
			'session'     => $session,
			'pullRefresh' => (bool) apply_filters( 'delicat_builder_v9_app_polish_pull_refresh', false ),
			'globals'     => self::$globals,
			'chunks'      => self::chunk_map(),
		);
		return (array) apply_filters( 'delicat_builder_v9_engine_config', $config );
	}

	private static function generation(): string {
		if ( class_exists( 'DBP_Kernel', false ) && is_callable( array( 'DBP_Kernel', 'generation' ) ) ) {
			return (string) DBP_Kernel::generation();
		}
		return (string) DELICAT_BUILDER_V9_VERSION;
	}

	/** Paths the engine always loads as full documents (session-bound surfaces). */
	private static function hard_paths(): array {
		$paths = array( '/cart/', '/panier/', '/checkout/', '/commande/', '/my-account/', '/mon-compte/', '/order-pay/', '/order-received/', '/customer-logout/', '/lost-password/', '/my-wallet/', '/wallet/', '/mon-portefeuille/' );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$path = wp_parse_url( (string) wc_get_page_permalink( $page ), PHP_URL_PATH );
				if ( is_string( $path ) && '/' !== $path && '' !== $path ) {
					$paths[] = trailingslashit( $path );
				}
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/** Product permalink bases, so the engine can tell a product tap apart. */
	private static function product_paths(): array {
		$paths      = array( '/product/', '/produit/' );
		$permalinks = get_option( 'woocommerce_permalinks', array() );
		if ( is_array( $permalinks ) && ! empty( $permalinks['product_base'] ) ) {
			$base = '/' . trim( (string) $permalinks['product_base'], '/' ) . '/';
			if ( '/' !== $base && false === strpos( $base, '%' ) ) {
				$paths[] = $base;
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/** Logical chunk name => URL, from the manifest. */
	private static function chunk_map(): array {
		$manifest = self::manifest();
		$map      = array();
		foreach ( (array) ( $manifest['chunks'] ?? array() ) as $file => $meta ) {
			if ( ! empty( $meta['name'] ) ) {
				$map[ (string) $meta['name'] ] = self::url( (string) $file );
			}
		}
		return $map;
	}

	/** Chunks this route is about to need: preloaded so the first mount does not wait. */
	private static function preload_chunks(): array {
		$map  = self::chunk_map();
		$want = array();
		if ( 'product' === self::$route ) {
			$want[] = 'product';
		} elseif ( 'purchase' === self::$route ) {
			$want[] = 'purchase-native';
		}
		if ( class_exists( 'Delicat_Builder_V9_Announcement', false ) || class_exists( 'Delicat_Builder_V9_Live_Selling', false ) ) {
			$want[] = 'widgets';
		}
		$out = array();
		foreach ( $want as $name ) {
			if ( isset( $map[ $name ] ) ) {
				$out[] = $map[ $name ];
			}
		}
		return $out;
	}

	public static function print_head(): void {
		if ( ! self::active() ) {
			return;
		}
		if ( ! self::$shaped ) {
			self::shape();
		}
		$manifest = self::manifest();
		$code     = 'window.DelicatEngine=' . wp_json_encode( self::config() ) . ';';
		if ( self::$code ) {
			$code .= "\n" . implode( "\n", self::$code );
		}
		if ( ! ( class_exists( 'DBP_Nav', false ) && class_exists( 'DBP_Kernel', false ) && DBP_Kernel::on( 'navigation' ) ) ) {
			echo '<meta name="delicat-generation" content="' . esc_attr( self::generation() ) . '">' . "\n";
		}
		echo '<script id="delicat-engine-js-before" data-no-optimize="1" data-no-delay="1" data-cfasync="false">' . $code . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON and module configuration built above.
		$engine = self::url( (string) $manifest['js']['engine']['file'] );
		echo '<link rel="modulepreload" href="' . esc_url( $engine ) . '">' . "\n";
		/* The shared chunks the module imports at start-up: preloaded with it, so
		 * the browser does not discover them one round trip later. */
		foreach ( (array) ( $manifest['js']['engine']['imports'] ?? array() ) as $import ) {
			echo '<link rel="modulepreload" href="' . esc_url( self::url( (string) $import ) ) . '">' . "\n";
		}
		foreach ( self::preload_chunks() as $chunk ) {
			echo '<link rel="modulepreload" href="' . esc_url( $chunk ) . '">' . "\n";
		}
		echo '<script type="module" src="' . esc_url( $engine ) . '" id="delicat-engine-js" data-no-optimize="1" data-no-delay="1" data-no-defer="1" data-cfasync="false"></script>' . "\n";
		echo '<!-- delicat-engine ' . esc_html( self::VERSION . ' route=' . self::$route . ' css=' . self::$route_bundle . '+' . self::$polish_bundle . ' folded=' . implode( ',', array_keys( self::$absorbed_styles ) ) . ' scripts=' . implode( ',', array_keys( self::$absorbed ) ) ) . ' -->' . "\n";
	}

	/** The thin progress bar, unless the Pro navigation layer already prints it. */
	public static function progress_bar(): void {
		if ( ! self::active() ) {
			return;
		}
		if ( class_exists( 'DBP_Nav', false ) && class_exists( 'DBP_Kernel', false ) && DBP_Kernel::on( 'navigation' ) ) {
			return;
		}
		echo '<div class="dbp-progress dlc-progress" id="dbp-progress" aria-hidden="true"><i></i></div>';
	}

	/* ----------------------------------------------------------- footer */

	/**
	 * Scripts and styles enqueued while the page rendered (shortcodes, the
	 * currency switcher, wallet cards) get the same treatment as the head pass.
	 */
	public static function absorb_late(): void {
		if ( ! self::active() || ! self::$shaped ) {
			return;
		}
		$inline = self::fold_styles();
		if ( '' !== $inline ) {
			echo '<style id="delicat-engine-late-inline-css">' . $inline . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- stylesheet data the modules registered themselves.
		}
		$absorbed = self::absorb_scripts();
		if ( empty( $absorbed['code'] ) ) {
			return;
		}
		$code = implode( "\n", $absorbed['code'] );
		if ( $absorbed['names'] ) {
			$code .= "\n(function(e){e.globals=(e.globals||[]).concat(" . wp_json_encode( $absorbed['names'] ) . ');})(window.DelicatEngine=window.DelicatEngine||{});';
		}
		echo '<script id="delicat-engine-footer-js-before" data-no-optimize="1" data-no-delay="1" data-cfasync="false">' . $code . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- configuration the modules registered themselves.
	}

	/* ---------------------------------------------------------- session */

	/**
	 * The session endpoint also carries the unread count, the cart total and
	 * fresh nonces, so a page served from a cache can act with current tokens.
	 *
	 * @param array $payload Session payload.
	 * @return array
	 */
	public static function session_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return $payload;
		}
		$logged = ! empty( $payload['loggedIn'] );
		try {
			if ( function_exists( 'WC' ) && is_object( WC() ) && is_object( WC()->cart ) && ! empty( $payload['cart']['count'] ) ) {
				$payload['cart']['total']    = wp_strip_all_tags( (string) WC()->cart->get_cart_total() );
				$payload['cart']['subtotal'] = wp_strip_all_tags( (string) WC()->cart->get_cart_subtotal() );
			}
		} catch ( Throwable $error ) {
			unset( $error );
		}
		$alerts = 0;
		if ( $logged && class_exists( 'Delicat_Builder_V9_Notifications', false ) && method_exists( 'Delicat_Builder_V9_Notifications', 'unread_count' ) ) {
			try {
				$alerts = (int) call_user_func( array( 'Delicat_Builder_V9_Notifications', 'unread_count' ) );
			} catch ( Throwable $error ) {
				unset( $error );
			}
		}
		$payload['alerts'] = array( 'count' => max( 0, $alerts ) );
		$nonces = array(
			'wc'        => wp_create_nonce( 'woocommerce-cart' ),
			'addToCart' => wp_create_nonce( 'woocommerce-add-to-cart' ),
			'rest'      => wp_create_nonce( 'wp_rest' ),
			'push'      => wp_create_nonce( 'dbv9_web_push' ),
			'clearCart' => wp_create_nonce( 'delicat_builder_v9_clear_cart' ),
			'headerCart'=> wp_create_nonce( 'delicat_builder_v9_header_cart_remove' ),
			'shell'     => wp_create_nonce( 'delicat_builder_v9_shell_interaction' ),
		);
		if ( $logged ) {
			$nonces['notifications'] = wp_create_nonce( 'dbv9_notifications' );
			$nonces['walletLive']    = wp_create_nonce( 'delicat_builder_v9_wallet_live' );
			$nonces['expressAdd']    = wp_create_nonce( 'delicat_express_add' );
			$nonces['review']        = wp_create_nonce( 'delicat_builder_v9_review' );
			if ( class_exists( 'Delicat_Builder_V9_Heart_Engine', false ) && defined( 'Delicat_Builder_V9_Heart_Engine::NONCE_ACTION' ) ) {
				$nonces['heart'] = wp_create_nonce( (string) Delicat_Builder_V9_Heart_Engine::NONCE_ACTION );
			}
		}
		$payload['nonces'] = $nonces;
		return $payload;
	}

	/* --------------------------------------------------------- service worker */

	/**
	 * Plugin-relative files the service worker precaches as the app shell:
	 * the engine module and the two stylesheets every page shares. Empty when
	 * no build is present or the engine is switched off.
	 */
	public static function precache_files(): array {
		$manifest = self::manifest();
		if ( null === $manifest || ! (bool) apply_filters( 'delicat_builder_v9_engine_active', true ) ) {
			return array();
		}
		$files = array( self::DIST . $manifest['js']['engine']['file'], self::DIST . $manifest['css']['chrome']['file'], self::DIST . $manifest['css']['polish']['file'] );
		return array_values( array_filter( $files, static function ( $file ) { return is_file( DELICAT_BUILDER_V9_DIR . $file ); } ) );
	}

	/* -------------------------------------------------------- litespeed */

	public static function litespeed_exclusions( $list ): array {
		$list = is_array( $list ) ? $list : array();
		foreach ( array( 'delicat-builder-v9/assets/dist/', 'delicat-engine-js-before', 'delicat-engine-footer-js-before', 'delicat-engine-js', 'DelicatEngine' ) as $item ) {
			$list[] = $item;
		}
		return array_values( array_unique( $list ) );
	}
}

Delicat_Builder_V9_Engine::boot();
