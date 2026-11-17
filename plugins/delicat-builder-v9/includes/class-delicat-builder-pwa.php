<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Delicat_Builder_V9_PWA {
	public const OPTION = 'delicat_builder_v9_pwa';
	private static bool $booted = false;
	private static bool $shortcode_css_printed = false;

	public static function defaults(): array {
		return array(
			'enabled'       => 1,
			'install_ui'    => 1,
			'static_cache'  => 1,
			'max_entries'   => 64,
			'theme_color'   => '#11104a',
			'background'    => '#ffffff',
			'short_name'    => 'Delicat',
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function boot(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) { return; }

		add_action( 'init', array( __CLASS__, 'rewrites' ), 8 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 9 );
		/* RC77: serve the two app endpoints straight from the request path too.
		 * On sites whose permalink rules were never re-saved after activation the
		 * rewrite rules are absent and both files answered 404, which broke the
		 * installable app and offline caching. */
		add_action( 'parse_request', array( __CLASS__, 'direct_endpoint' ), 0 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'endpoint' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_runtime' ), 20 );
		add_shortcode( 'delicat_install_app', array( __CLASS__, 'shortcode' ) );
	}

	public static function activate(): void {
		self::rewrites();
		flush_rewrite_rules( false );
		update_option( 'delicat_builder_v9_pwa_rules', DELICAT_BUILDER_V9_VERSION, true );
	}

	public static function rewrites(): void {
		add_rewrite_rule( '^delicat-v9-manifest\.webmanifest$', 'index.php?delicat_v9_manifest=1', 'top' );
		add_rewrite_rule( '^delicat-v9-sw\.js$', 'index.php?delicat_v9_sw=1', 'top' );
	}

	/* Flush once per plugin version so a plugin update repairs missing rules. */
	public static function maybe_flush(): void {
		if ( wp_doing_ajax() || wp_doing_cron() ) { return; }
		/* 9.13.0 (speed): read on every page view, so autoloaded (was one query per view) */
		if ( get_option( 'delicat_builder_v9_pwa_rules' ) === DELICAT_BUILDER_V9_VERSION ) { delicat_builder_v9_autoload_stamp( 'delicat_builder_v9_pwa_rules' ); return; }
		update_option( 'delicat_builder_v9_pwa_rules', DELICAT_BUILDER_V9_VERSION, true );
		flush_rewrite_rules( false );
	}

	/* Path-based fallback: independent of rewrite rules and permalink state. */
	public static function direct_endpoint(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $uri ) { return; }
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$file = strtolower( ltrim( (string) strrchr( '/' . ltrim( $path, '/' ), '/' ), '/' ) );
		if ( 'delicat-v9-sw.js' === $file ) { self::service_worker_response(); }
		if ( 'delicat-v9-manifest.webmanifest' === $file ) { self::manifest_response(); }
		if ( preg_match( '/^delicat-v9-splash-(\d{3,4})x(\d{3,4})\.png$/', $file, $m ) ) { self::splash_response( (int) $m[1], (int) $m[2] ); }
	}

	/* ------------------------------------------------------------------ */
	/* iPhone launch screens                                               */
	/* ------------------------------------------------------------------ */

	/** Portrait launch screens iOS asks for, as [css width, css height, pixel ratio]. */
	private const SPLASH_DEVICES = array(
		array( 375, 667, 2 ), array( 414, 736, 3 ), array( 375, 812, 3 ), array( 414, 896, 2 ), array( 414, 896, 3 ),
		array( 390, 844, 3 ), array( 428, 926, 3 ), array( 393, 852, 3 ), array( 430, 932, 3 ), array( 402, 874, 3 ),
		array( 440, 956, 3 ), array( 810, 1080, 2 ), array( 820, 1180, 2 ), array( 834, 1194, 2 ), array( 1024, 1366, 2 ),
	);

	/** The icon the launch screen is built from: the site icon, else the drawer's logo. */
	private static function splash_icon_path(): string {
		/* 9.5.5: asked twice per page head (supported + stamp); resolve it once per request. */
		static $memo = null;
		if ( null !== $memo ) { return $memo; }
		$memo = '';
		$id = (int) get_option( 'site_icon' );
		if ( $id > 0 ) {
			$file = (string) get_attached_file( $id );
			if ( '' !== $file && is_file( $file ) ) { return $memo = $file; }
		}
		if ( function_exists( 'delicat_builder_v9_menu_get_menu_builder_settings' ) ) {
			$menu = delicat_builder_v9_menu_get_menu_builder_settings();
			$url  = is_array( $menu ) ? (string) ( $menu['custom_header_image'] ?? '' ) : '';
			if ( '' !== $url && function_exists( 'attachment_url_to_postid' ) ) {
				/* 9.5.5: attachment_url_to_postid() scans every _wp_attached_file row; the answer
				 * for a given logo URL is kept for a day (a new logo is a new URL). */
				$key = 'dbv9_splash_att_' . substr( md5( $url ), 0, 16 );
				$att = get_transient( $key );
				if ( false === $att ) {
					$att = (int) attachment_url_to_postid( $url );
					set_transient( $key, $att, DAY_IN_SECONDS );
				}
				$att  = (int) $att;
				$file = $att > 0 ? (string) get_attached_file( $att ) : '';
				if ( '' !== $file && is_file( $file ) ) { return $memo = $file; }
			}
		}
		return $memo;
	}

	private static function splash_dir(): array {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) { return array( '', '' ); }
		return array( trailingslashit( $uploads['basedir'] ) . 'delicat-builder-v9/splash', trailingslashit( $uploads['baseurl'] ) . 'delicat-builder-v9/splash' );
	}

	/** A short signature of what the images are built from; a change renames them. */
	private static function splash_stamp(): string {
		$s = self::settings();
		$icon = self::splash_icon_path();
		return substr( hash( 'sha256', ( $icon ? $icon . ':' . (string) filemtime( $icon ) : 'none' ) . '|' . (string) ( $s['background'] ?? '' ) ), 0, 10 );
	}

	/** True when launch screens can be produced on this server. */
	private static function splash_supported(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagepng' ) && function_exists( 'imagecreatefromstring' ) && '' !== self::splash_icon_path();
	}

	/**
	 * Draw one launch screen: the background colour with the icon centred,
	 * which is how the app's own icon fills the screen while iOS starts it.
	 * Written once into uploads/delicat-builder-v9/splash and served from there.
	 */
	private static function splash_build( int $w, int $h ): string {
		list( $dir, ) = self::splash_dir();
		if ( '' === $dir || ! self::splash_supported() ) { return ''; }
		$path = $dir . '/' . $w . 'x' . $h . '-' . self::splash_stamp() . '.png';
		if ( is_file( $path ) ) { return $path; }
		if ( ! wp_mkdir_p( $dir ) ) { return ''; }
		$s = self::settings();
		$bg = sanitize_hex_color( (string) ( $s['background'] ?? '' ) ) ?: '#ffffff';
		$rgb = sscanf( $bg, '#%02x%02x%02x' );
		$canvas = imagecreatetruecolor( $w, $h );
		if ( ! $canvas ) { return ''; }
		imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2] ) );
		$blob = file_get_contents( self::splash_icon_path() ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file.
		$icon = $blob ? @imagecreatefromstring( $blob ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $icon ) {
			$size = (int) round( min( $w, $h ) * 0.27 );
			$iw = imagesx( $icon ); $ih = imagesy( $icon );
			$scaled = imagecreatetruecolor( $size, $size );
			imagealphablending( $scaled, false ); imagesavealpha( $scaled, true );
			imagefill( $scaled, 0, 0, imagecolorallocatealpha( $scaled, 0, 0, 0, 127 ) );
			imagecopyresampled( $scaled, $icon, 0, 0, 0, 0, $size, $size, $iw, $ih );
			imagealphablending( $canvas, true );
			imagecopy( $canvas, $scaled, (int) ( ( $w - $size ) / 2 ), (int) ( ( $h - $size ) / 2 ) - (int) round( $h * 0.03 ), 0, 0, $size, $size );
			imagedestroy( $scaled ); imagedestroy( $icon );
		}
		$ok = imagepng( $canvas, $path, 6 );
		imagedestroy( $canvas );
		return $ok ? $path : '';
	}

	/** Serve (building on first request) one launch screen. */
	private static function splash_response( int $w, int $h ): void {
		$known = false;
		foreach ( self::SPLASH_DEVICES as $d ) { if ( $d[0] * $d[2] === $w && $d[1] * $d[2] === $h ) { $known = true; break; } }
		if ( ! $known ) { return; }
		$path = self::splash_build( $w, $h );
		if ( '' === $path ) { status_header( 404 ); exit; }
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: public, max-age=604800' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * V10: where the full launch-screen set is printed. It was 15 links (about 4.1 KB of HTML) and
	 * 15 file checks on every page; now only the home page, the installed app's start page. Any
	 * other page gives the engine the device list (splash_config) and the engine adds the one link
	 * that matches the iPhone in Safari, so « Sur l'écran d'accueil » from any page keeps its
	 * launch screen and other phones download nothing.
	 */
	private static function splash_in_head(): bool {
		return (bool) apply_filters( 'delicat_builder_v9_pwa_splash_in_head', is_front_page() );
	}

	/**
	 * V10: what the engine needs to add the matching launch-screen link on an iPhone: the image
	 * endpoint prefix and the supported portrait sizes as "width x height x ratio" (CSS pixels).
	 *
	 * @return array<string,string> Empty when launch screens cannot be built here.
	 */
	private static function splash_config(): array {
		if ( ! self::splash_supported() ) { return array(); }
		$sizes = array();
		foreach ( self::SPLASH_DEVICES as $d ) {
			$sizes[] = (int) $d[0] . 'x' . (int) $d[1] . 'x' . (int) $d[2];
		}
		return array( 'u' => esc_url_raw( home_url( '/delicat-v9-splash-' ) ), 'd' => implode( ' ', $sizes ) );
	}

	/** The <link rel="apple-touch-startup-image"> set, built files served directly. */
	private static function splash_links(): string {
		if ( ! self::splash_supported() ) { return ''; }
		list( $dir, $url ) = self::splash_dir();
		if ( '' === $dir ) { return ''; }
		$stamp = self::splash_stamp();
		$out = '';
		foreach ( self::SPLASH_DEVICES as $d ) {
			$w = $d[0] * $d[2]; $h = $d[1] * $d[2];
			$file = $w . 'x' . $h . '-' . $stamp . '.png';
			$href = is_file( $dir . '/' . $file ) ? $url . '/' . $file : home_url( '/delicat-v9-splash-' . $w . 'x' . $h . '.png' );
			$out .= '<link rel="apple-touch-startup-image" media="screen and (device-width: ' . (int) $d[0] . 'px) and (device-height: ' . (int) $d[1] . 'px) and (-webkit-device-pixel-ratio: ' . (int) $d[2] . ') and (orientation: portrait)" href="' . esc_url( $href ) . '">' . "\n";
		}
		return $out;
	}



	public static function query_vars( $vars ): array {
		$vars = is_array( $vars ) ? $vars : array();
		$vars[] = 'delicat_v9_manifest';
		$vars[] = 'delicat_v9_sw';
		return $vars;
	}

	private static function icon_url( int $size ): string {
		$url = get_site_icon_url( $size );
		if ( ! $url && 512 !== $size ) { $url = get_site_icon_url( 512 ); }
		return $url ? esc_url_raw( $url ) : '';
	}

	public static function endpoint(): void {
		if ( get_query_var( 'delicat_v9_manifest' ) ) {
			self::manifest_response();
		}
		if ( get_query_var( 'delicat_v9_sw' ) ) {
			self::service_worker_response();
		}
	}

	private static function manifest_response(): void {
		$s = self::settings();
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		$icons = array();
		foreach ( array( 192, 512 ) as $size ) {
			$url = self::icon_url( $size );
			if ( $url ) {
				/* One entry per purpose: a combined "any maskable" makes Android
				 * crop the plain icon into the maskable shape. */
				$icons[] = array( 'src' => $url, 'sizes' => $size . 'x' . $size, 'type' => 'image/png', 'purpose' => 'any' );
				$icons[] = array( 'src' => $url, 'sizes' => $size . 'x' . $size, 'type' => 'image/png', 'purpose' => 'maskable' );
			}
		}
		$shortcuts = array();
		$shop = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		$cart = function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '';
		$account = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';
		foreach ( array( array( 'Boutique', $shop ), array( 'Panier', $cart ), array( 'Mon compte', $account ) ) as $entry ) {
			if ( '' !== $entry[1] ) {
				$shortcuts[] = array( 'name' => $entry[0], 'url' => $entry[1] );
			}
		}
		$payload = array(
			'name'             => get_bloginfo( 'name' ) ?: 'Delicat Store Haiti',
			'short_name'       => substr( sanitize_text_field( $s['short_name'] ), 0, 32 ) ?: 'Delicat',
			'description'      => 'Delicat Store Haiti — top up, gift cards et services numériques.',
			'id'               => wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/',
			'start_url'        => home_url( '/?utm_source=pwa' ),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'display_override' => array( 'standalone', 'minimal-ui' ),
			/* 9.5.0: tapping the home-screen icon while the app is already open brings
			 * that window back where the customer left it, as a native app resumes,
			 * instead of opening a second window or reloading the home page. */
			'launch_handler'   => array( 'client_mode' => array( 'focus-existing', 'auto' ) ),
			'background_color' => sanitize_hex_color( $s['background'] ) ?: '#ffffff',
			'theme_color'      => sanitize_hex_color( $s['theme_color'] ) ?: '#11104a',
			'orientation'      => 'portrait-primary',
			'lang'             => 'fr',
			'dir'              => 'ltr',
			'icons'            => $icons,
			'shortcuts'        => $shortcuts,
			'prefer_related_applications' => false,
			'categories'       => array( 'shopping', 'games', 'finance' ),
		);
		echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * V10: what the installed app keeps from install: the engine module and its imports, the chrome
	 * and polish sheets every page uses, and the product route, the screen opened most from all the
	 * others. The home, shop and page route sheets were downloaded at install even for a shopper who
	 * never opened those screens (about 27 KB compressed on a budget phone, after every release); they
	 * now load the first time they are used, from the browser cache or the network, like any route.
	 *
	 * @param array<int,string> $files Plugin-relative files from Engine::precache_files().
	 * @return array<int,string>
	 */
	private static function shell_files( array $files ): array {
		return array_values(
			array_filter(
				$files,
				static function ( $rel ) {
					$rel = (string) $rel;
					if ( 0 !== strpos( $rel, 'assets/dist/' ) || '.css' !== substr( $rel, -4 ) ) {
						return true;
					}
					return (bool) preg_match( '#^assets/dist/(?:chrome|polish|polish-storefront|product)\.[0-9a-f]{12}\.css$#', $rel );
				}
			)
		);
	}

	private static function service_worker_response(): void {
		$s = self::settings();
		$scope = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$scope = is_string( $scope ) && '' !== $scope ? trailingslashit( $scope ) : '/';
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . $scope );
		/* 9.7.8 (FE-17): the browser checks this script on every page load of the installed app, and with
		 * no-store each check started WordPress. Nothing in it depends on the visitor (settings, version,
		 * maintenance state only), so Cloudflare may answer those checks for five minutes; the browser itself
		 * still revalidates every time (max-age=0). */
		if ( class_exists( 'Delicat_Builder_V9_Maintenance', false ) && Delicat_Builder_V9_Maintenance::is_enabled() ) {
			nocache_headers(); // while the store is closed the app must learn it at once
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		} else {
			header( 'Cache-Control: public, max-age=0, s-maxage=300, stale-while-revalidate=60' );
			do_action( 'litespeed_control_set_ttl', 300 );
		}
		header( 'X-Content-Type-Options: nosniff' );
		/* V10: cache names no longer carry the plugin version. A release used to rename both caches,
		 * so every installed app downloaded its whole shell again (and lost its runtime copies) even
		 * though the built files are content-hashed and the unchanged ones keep their URL. The runtime
		 * cache keeps one name; the shell cache is named after its file list (below), and a new list
		 * reuses every copy the device already holds. */
		$cache_name = 'delicat-v9-static';
		$max = min( 120, max( 20, absint( $s['max_entries'] ?? 64 ) ) );
		$cache_enabled = empty( $s['static_cache'] ) ? 'false' : 'true';
		/* V10: the page (document) cache code is gone from the worker (dbv9Document, its in-flight map,
		 * TTL, currency key and the dbv9-clear-docs message): since 9.12.1 every page comes from the
		 * network (offline page kept), so none of it could run. The wallet, order-pay and order-received
		 * routes join the private paths the worker never intercepts. */
		/* RC36: the storefront shell is precached at install so repeat visits and the first offline open already have it. */
		$precache = array();
		$chrome_css = array( 'assets/css/theme-system.css', 'assets/dsb8-beta2-header.css', 'assets/css/drawer.css', 'assets/css/bottom-nav.css' );
		$precache_files = array_merge( $chrome_css, array( 'assets/dsb8-beta2-header.js', 'assets/js/drawer.js', 'assets/js/session.js', 'assets/js/theme.js', 'assets/js/pwa-runtime.js' ) );
		/* 9.3: with the engine delivering the storefront, the shell is the engine
		 * module plus the chrome and polish stylesheets; the legacy files above
		 * are never requested by a page, so precaching them would only cost data. */
		$engine_precache = ( class_exists( 'Delicat_Builder_V9_Engine', false ) && is_callable( array( 'Delicat_Builder_V9_Engine', 'precache_files' ) ) ) ? (array) Delicat_Builder_V9_Engine::precache_files() : array();
		if ( ! empty( $engine_precache ) ) {
			$precache_files = self::shell_files( $engine_precache );
		}
		/*
		 * pro.17: every one of these files has a content-addressed twin, and the
		 * page requests the twin — style_loader_src/script_loader_src are rewritten
		 * to `<name>.<hash>.<ext>` with the query string dropped. The precache was
		 * building `<name>.<ext>?ver=<plugin version>` instead, so the service worker
		 * downloaded six files at install that no page would ever ask for, and every
		 * request still went to the network. Resolve through the same map the page
		 * uses; a file with no twin keeps its ?ver URL, which is what WordPress emits
		 * for it.
		 */
		foreach ( $precache_files as $rel ) {
			if ( ! is_file( DELICAT_BUILDER_V9_DIR . $rel ) ) {
				continue;
			}
			if ( 0 === strpos( $rel, 'assets/dist/' ) ) {
				/* 9.3: built files carry their hash in the name and the page requests
				 * them without a query string; the precache entry must match that URL. */
				$precache[] = DELICAT_BUILDER_V9_URL . $rel;
				continue;
			}
			$url = add_query_arg( 'ver', DELICAT_BUILDER_V9_VERSION, DELICAT_BUILDER_V9_URL . $rel );
			if ( is_callable( array( 'Delicat_Builder_V9_Audit_Fixes', 'asset_url' ) ) ) {
				$url = (string) Delicat_Builder_V9_Audit_Fixes::asset_url( $url );
			}
			$precache[] = $url;
		}
		/* 9.3.2: the product route's WordPress/WooCommerce scripts (recorded by
		 * the engine with their exact version query) ride along, so the first
		 * product opened in a session does not wait for jQuery and the variation
		 * form over the network. */
		if ( class_exists( 'Delicat_Builder_V9_Engine', false ) && is_callable( array( 'Delicat_Builder_V9_Engine', 'precache_urls' ) ) ) {
			$precache = array_merge( $precache, (array) Delicat_Builder_V9_Engine::precache_urls() );
		}
		$precache = (array) apply_filters( 'delicat_builder_v9_pwa_precache', array_values( array_unique( array_map( 'esc_url_raw', $precache ) ) ) );
		/* 9.7.8 (FE-06): the app shell has a cache of its own that is never trimmed. It shared the 64-entry
		 * runtime cache with product pictures, where the oldest entries (the shell) went first.
		 * V10: named after the file list, so the worker script (and the cache) only change when the list does. */
		$shell_cache = 'delicat-v9-shell-' . substr( md5( (string) wp_json_encode( array_values( $precache ) ) ), 0, 12 );
		?>
'use strict';
const DBV9_CACHE=<?php echo wp_json_encode( $cache_name ); ?>;
const DBV9_SHELL=<?php echo wp_json_encode( $shell_cache ); ?>;
const DBV9_MAX=<?php echo (int) $max; ?>;
const DBV9_STATIC_CACHE=<?php echo $cache_enabled; ?>;
const DBV9_PRECACHE=<?php echo wp_json_encode( $precache ); ?>;
const DBV9_OFFLINE='<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hors ligne</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7fb;color:#15172a;text-align:center;padding:24px}h1{font-size:22px;margin:0 0 8px}p{color:#6b7285;margin:0 0 18px}a{display:inline-block;padding:12px 20px;border-radius:14px;background:#2563eb;color:#fff;text-decoration:none;font-weight:700}@media(prefers-color-scheme:dark){body{background:#0d1020;color:#e9ebf5}p{color:#a5abc0}}</style></head><body><div><h1>Vous êtes hors ligne</h1><p>Reconnectez-vous pour continuer vos achats.<br>Ou pa konekte : tcheke entènèt ou.</p><a href="">Réessayer</a></div></body></html>';
const DBV9_PRIVATE=/(?:\/wp-admin(?:\/|$)|\/wp-login\.php(?:\/|$)|\/cart(?:\/|$)|\/panier(?:\/|$)|\/checkout(?:\/|$)|\/commande(?:\/|$)|\/my-account(?:\/|$)|\/mon-compte(?:\/|$)|\/my-wallet(?:\/|$)|\/woo-wallet(?:\/|$)|\/wallet(?:\/|$)|\/mon-portefeuille(?:\/|$)|\/portefeuille(?:\/|$)|\/order-pay(?:\/|$)|\/order-received(?:\/|$)|\/wp-json(?:\/|$)|admin-ajax\.php)/i;
const DBV9_SENSITIVE_Q=/(?:^|&)(?:delicat_product|delicat_product_fragment|wc-ajax|add-to-cart|remove_item|undo_item|_wpnonce|nonce|action|dip_action|logout|customer-logout)=/i;
async function dbv9Trim(){const c=await caches.open(DBV9_CACHE);const keys=await c.keys();if(keys.length>DBV9_MAX){await Promise.all(keys.slice(0,keys.length-DBV9_MAX).map(k=>c.delete(k)));}}
/* 9.7.8 (FE-04): the shell is copied through the browser's normal HTTP cache. Every file in it has its content hash or ?ver
   in the URL, so the copy the page already downloaded is the right one; forcing a reload downloaded all of it again.
   V10: a copy already in one of the site's caches is reused; only new files are downloaded. */
async function dbv9Shell(c,u){try{if(await c.match(u))return;const hit=await caches.match(u);if(hit&&hit.ok){await c.put(u,hit);return;}await c.add(u);}catch(_){}}
self.addEventListener('install',e=>e.waitUntil((async()=>{try{if(DBV9_STATIC_CACHE&&DBV9_PRECACHE.length){const c=await caches.open(DBV9_SHELL);await Promise.all(DBV9_PRECACHE.map(u=>dbv9Shell(c,u)));}}catch(_){}await self.skipWaiting();})()));
self.addEventListener('activate',e=>e.waitUntil((async()=>{try{if(self.registration.navigationPreload)await self.registration.navigationPreload.enable();}catch(_){}const keys=await caches.keys();await Promise.all(keys.filter(k=>k.startsWith('delicat-v9-')&&k!==DBV9_CACHE&&k!==DBV9_SHELL).map(k=>caches.delete(k)));await self.clients.claim();})()));
self.addEventListener('push',e=>{
  let d={};try{d=e.data?e.data.json():{};}catch(_){try{d={body:e.data?e.data.text():''};}catch(__){d={};}}
  const title=String(d.title||'Delicat Store').slice(0,120),body=String(d.body||'').slice(0,360);
  let url=self.location.origin+'/';try{const u=new URL(String(d.url||'/'),self.location.origin);if(u.origin===self.location.origin)url=u.href;}catch(_){}
  const opts={body,tag:String(d.tag||d.notification_id||'delicat-update').slice(0,120),data:{url,notification_id:String(d.notification_id||'')},icon:d.icon||undefined,badge:d.icon||undefined,renotify:!!d.urgent,timestamp:Number(d.timestamp)||Date.now()};
  e.waitUntil(self.registration.showNotification(title,opts).then(()=>self.clients.matchAll({type:'window',includeUncontrolled:true}).then(list=>{list.forEach(c=>{try{c.postMessage({type:'dbv9-push',item:{...d,url}});}catch(_){}});})));
});
self.addEventListener('notificationclick',e=>{
  e.notification.close();let target=self.location.origin+'/';try{const u=new URL(String((e.notification.data&&e.notification.data.url)||'/'),self.location.origin);if(u.origin===self.location.origin)target=u.href;}catch(_){}
  e.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(list=>{for(const c of list){try{if(new URL(c.url).origin===self.location.origin){c.navigate(target).catch(()=>{});return c.focus();}}catch(_){}}return self.clients.openWindow?self.clients.openWindow(target):undefined;}));
});
function dbv9Stamp(resp){const h=new Headers(resp.headers);h.set('x-dbv9-cached-at',String(Date.now()));return new Response(resp.body,{status:resp.status,statusText:resp.statusText,headers:h});}
/* 9.7.8 (FE-05): a storefront page opened without a connection shows the store's own offline page (Réessayer)
   instead of the browser's error screen. Nothing is stored; private pages are never intercepted. */
async function dbv9Network(e,r){try{return (e.preloadResponse&&await e.preloadResponse)||await fetch(r);}catch(_){return new Response(DBV9_OFFLINE,{status:503,headers:{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'}});}}
self.addEventListener('fetch',e=>{
  const r=e.request;if(r.method!=='GET')return;
  let u;try{u=new URL(r.url);}catch(_){return;}
  if(u.origin!==self.location.origin||DBV9_PRIVATE.test(u.pathname)||DBV9_SENSITIVE_Q.test(u.search.replace(/^\?/,'')))return;
  /* 9.12.1: a page always comes from the network, never from a cached copy, so a returning shopper never sees an
     older build after an update; offline page kept. */
  if(r.mode==='navigate'){e.respondWith(dbv9Network(e,r));return;}
  if(!DBV9_STATIC_CACHE)return;
  const d=r.destination||'';
  const isStatic=['style','script','image','font'].includes(d)||/\.(?:css|js|png|jpe?g|gif|webp|avif|svg|woff2?)(?:$|\?)/i.test(u.pathname);
  if(!isStatic)return;
  /* RC55: a cached hit used to be returned AND re-fetched from the network every
     time (stale-while-revalidate) — for `?ver=`-stamped plugin/WordPress/Woo files
     and media-library images that is pure data waste on a metered phone. A
     versioned URL never changes; an upload is revalidated at most once per 6 h.
     Everything else keeps the previous stale-while-revalidate behaviour. */
  /* pro.17: every Builder asset is served as `<name>.<12 hex>.<ext>` with no
     query string, so the ?ver test alone called the plugin's own CSS and JS
     unversioned and re-fetched each one on every page view behind its own cache
     hit. A content-addressed name is immutable by construction — a changed file
     gets a different name — so treat it exactly like a ?ver stamp. */
  const versioned=/[?&]ver=/.test(u.search)||/\.[0-9a-f]{12}\.(?:css|js)$/i.test(u.pathname);
  const upload=/\/wp-content\/uploads\//.test(u.pathname);
  e.respondWith(caches.open(DBV9_SHELL).then(s=>s.match(r)).catch(()=>null).then(shell=>shell||caches.open(DBV9_CACHE).then(async c=>{
    const hit=await c.match(r);
    if(hit&&versioned)return hit;
    if(hit&&upload){const at=Number(hit.headers.get('x-dbv9-cached-at'))||0;if(at&&(Date.now()-at)<21600000)return hit;}
    const net=fetch(r).then(resp=>{
      const cc=(resp.headers.get('cache-control')||'').toLowerCase();
      if(resp.ok&&resp.type!=='opaque'&&!/(?:no-store|private)/.test(cc)){c.put(r,dbv9Stamp(resp.clone())).then(dbv9Trim).catch(()=>{});}
      return resp;
    }).catch(()=>hit);
    return hit||net;
  })));
});
		<?php
		exit;
	}

	public static function head(): void {
		if ( is_admin() ) { return; }
		$s = self::settings();
		$theme = sanitize_hex_color( $s['theme_color'] ) ?: '#11104a';
		echo '<link rel="manifest" href="' . esc_url( home_url( '/delicat-v9-manifest.webmanifest' ) ) . '">' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( $theme ) . '">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		/* `default`: the status bar keeps its system look above the white
		 * header, the way a light native app reads; the page starts below it. */
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( substr( sanitize_text_field( (string) ( $s['short_name'] ?? 'Delicat' ) ), 0, 32 ) ?: 'Delicat' ) . '">' . "\n";
		echo '<meta name="application-name" content="' . esc_attr( get_bloginfo( 'name' ) ?: 'Delicat Store' ) . '">' . "\n";
		$icon = self::icon_url( 192 );
		if ( $icon ) {
			echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( self::icon_url( 180 ) ?: $icon ) . '">' . "\n";
		}
		if ( self::splash_in_head() ) {
			echo self::splash_links(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}

	public static function enqueue_runtime(): void {
		if ( is_admin() ) { return; }
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) { return; }
		$css = DELICAT_BUILDER_V9_DIR . 'assets/css/pwa-runtime.css';
		$js = DELICAT_BUILDER_V9_DIR . 'assets/js/pwa-runtime.js';
		if ( ! empty( $s['install_ui'] ) && is_file( $css ) ) {
			wp_enqueue_style( 'delicat-builder-v9-pwa-runtime', DELICAT_BUILDER_V9_URL . 'assets/css/pwa-runtime.css', array(), DELICAT_BUILDER_V9_VERSION );
		}
		if ( ! is_file( $js ) ) { return; }
		wp_enqueue_script( 'delicat-builder-v9-pwa-runtime', DELICAT_BUILDER_V9_URL . 'assets/js/pwa-runtime.js', array(), DELICAT_BUILDER_V9_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'delicat-builder-v9-pwa-runtime', 'strategy', 'defer' );
		}
		$sw = wp_make_link_relative( home_url( '/delicat-v9-sw.js' ) );
		$scope = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$scope = is_string( $scope ) && '' !== $scope ? trailingslashit( $scope ) : '/';
		$config = array( 'serviceWorker' => $sw, 'scope' => $scope, 'installUI' => empty( $s['install_ui'] ) ? 0 : 1 );
		if ( ! self::splash_in_head() ) {
			$splash = self::splash_config();
			if ( $splash ) {
				$config['splash'] = $splash;
			}
		}
		wp_add_inline_script( 'delicat-builder-v9-pwa-runtime', 'window.DelicaPWARuntime=' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function shortcode( $atts = array() ): string {
		$app_url = class_exists( 'Delicat_Builder_V9_App_Download', false ) ? Delicat_Builder_V9_App_Download::page_url() : '';
		if ( $app_url ) {
			$args = shortcode_atts( array( 'text' => 'Télécharger Delicat Store', 'class' => '' ), $atts, 'delicat_install_app' );
			return '<a class="delicat-builder-v9-install-app ' . esc_attr( sanitize_html_class( $args['class'] ) ) . '" href="' . esc_url( $app_url ) . '">' . esc_html( $args['text'] ) . '</a>';
		}
		$s = self::settings();
		if ( empty( $s['enabled'] ) || empty( $s['install_ui'] ) ) { return ''; }
		$atts = shortcode_atts( array( 'text' => 'Installer Delicat Store', 'class' => '' ), $atts, 'delicat_install_app' );
		$class = 'delicat-builder-v9-install-app';
		if ( '' !== $atts['class'] ) { $class .= ' ' . sanitize_html_class( $atts['class'] ); }
		return '<button type="button" class="' . esc_attr( $class ) . '" data-db-v9-pwa-open>' . esc_html( sanitize_text_field( $atts['text'] ) ) . '</button>';
	}
}
