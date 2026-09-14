<?php
/**
 * Plugin Name: Delicat Builder V9 Pro — App-Speed Kernel
 * Plugin URI: https://delicastoreha.com/
 * Description: Application-speed storefront kernel for WordPress + WooCommerce. Every V9 feature, rebuilt on one navigation engine, one asset pipeline and one session store.
 * Version: 9.2.0-pro.17

 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Delicat Store
 * Text Domain: delicat-builder-v9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * RC25 BOOTSTRAP CIRCUIT BREAKER
 * ------------------------------
 * This guard runs before any Delicat Builder class is required.
 *
 * 1. If another Builder copy already defined the active file constant, this
 *    copy exits instead of redeclaring the same classes.
 * 2. If a fatal later originates from a Delicat Builder file/class during the
 *    current request, all active Delicat Builder V9 plugin copies are removed
 *    from the active-plugins list. The next request therefore falls back to
 *    normal WordPress/WooCommerce instead of looping on a fatal screen.
 */

$delicat_builder_v9_this_file = __FILE__;
$delicat_builder_v9_emergency_memory = str_repeat( ' ', 65536 );

if (
	defined( 'DELICAT_BUILDER_V9_FILE' )
	&& realpath( (string) DELICAT_BUILDER_V9_FILE ) !== realpath( $delicat_builder_v9_this_file )
) {
	return;
}

register_shutdown_function(
	static function () use ( $delicat_builder_v9_this_file, &$delicat_builder_v9_emergency_memory ) {
		/* Release the reserve first so an out-of-memory fatal can still record
		 * Safe Mode and complete the recovery decision. */
		$delicat_builder_v9_emergency_memory = '';
		$error = error_get_last();
		if ( ! is_array( $error ) ) {
			return;
		}

		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
		if ( ! in_array( (int) ( $error['type'] ?? 0 ), $fatal_types, true ) ) {
			return;
		}

		$file = wp_normalize_path( (string) ( $error['file'] ?? '' ) );
		$message = (string) ( $error['message'] ?? '' );
		$this_dir = wp_normalize_path( dirname( $delicat_builder_v9_this_file ) );

		$builder_related = (
			'' !== $file
			&& (
				0 === strpos( $file, $this_dir . '/' )
				|| false !== stripos( $file, '/delicat-builder-v9' )
			)
		) || false !== stripos( $message, 'Delicat_Builder_V9_' );

		if ( ! $builder_related || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		// Record a short sanitized message so the exact server-side cause remains
		// visible after recovery, without retaining a full stack trace.
		$kind = 'fatal';
		$message_lc = strtolower( $message );
		if ( false !== strpos( $message_lc, 'undefined function' ) ) {
			$kind = 'undefined_function';
		} elseif ( false !== strpos( $message_lc, 'undefined constant' ) ) {
			$kind = 'undefined_constant';
		} elseif ( false !== strpos( $message_lc, 'parse error' ) || false !== strpos( $message_lc, 'syntax error' ) ) {
			$kind = 'parse_error';
		} elseif ( false !== strpos( $message_lc, 'uncaught typeerror' ) || false !== strpos( $message_lc, 'must be of type' ) ) {
			$kind = 'type_error';
		} elseif ( false !== strpos( $message_lc, 'undefined method' ) ) {
			$kind = 'undefined_method';
		}

		$event = array(
			'time'        => gmdate( 'c' ),
			'time_unix'   => time(),
			'type'        => absint( $error['type'] ?? 0 ),
			'kind'        => sanitize_key( $kind ),
			'php_version' => PHP_VERSION,
			'wp_version'  => function_exists( 'get_bloginfo' ) ? sanitize_text_field( (string) get_bloginfo( 'version' ) ) : '',
			'hash'        => hash( 'sha256', $file . '|' . $message . '|' . (string) ( $error['line'] ?? 0 ) ),
			'file'        => sanitize_file_name( basename( $file ) ),
			'line'        => absint( $error['line'] ?? 0 ),
			'message'     => sanitize_text_field( function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 180 ) : substr( $message, 0, 180 ) ),
		);
		update_option( 'delicat_builder_v9_last_bootstrap_fatal', $event, false );
		update_option( 'delicat_builder_v9_safe_mode', 1, false );
		update_option(
			'delicat_builder_v9_safe_mode_meta',
			array(
				'version' => defined( 'DELICAT_BUILDER_V9_VERSION' ) ? DELICAT_BUILDER_V9_VERSION : 'bootstrap',
				'tripped' => gmdate( 'c' ),
				'context' => 'bootstrap_fatal',
			),
			false
		);

		/* Optional-module failures are quarantined in Safe Mode. Only a failure
		 * in the bootstrap/minimal kernel may deactivate this exact copy. */
		$fatal_file = strtolower( basename( $file ) );
		$critical_files = array(
			'delicat-builder-v9.php',
			'class-delicat-builder-core.php',
			'class-delicat-builder-cache.php',
			'class-delicat-builder-security.php',
			'class-delicat-builder-runtime-router.php',
		);
		if ( ! in_array( $fatal_file, $critical_files, true ) ) {
			return;
		}

		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			return;
		}
		$current_plugin = function_exists( 'plugin_basename' )
			? strtolower( wp_normalize_path( plugin_basename( $delicat_builder_v9_this_file ) ) )
			: '';
		if ( '' === $current_plugin ) {
			return;
		}

		$filtered = array_values(
			array_filter(
				$active,
				static function ( $plugin ) use ( $current_plugin ) {
					$plugin = strtolower( wp_normalize_path( (string) $plugin ) );
					return $plugin !== $current_plugin;
				}
			)
		);

		if ( count( $filtered ) !== count( $active ) ) {
			update_option( 'active_plugins', $filtered );
			update_option( 'delicat_builder_v9_circuit_breaker_tripped', time(), false );
		}
	}
);

define( 'DELICAT_BUILDER_V9_VERSION', '9.2.0-pro.17' );

/* RC32: no theme/plugin file editing from wp-admin — a compromised admin session must not become code execution. */
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}
define( 'DELICAT_BUILDER_V9_FILE', __FILE__ );
define( 'DELICAT_BUILDER_V9_DIR', plugin_dir_path( __FILE__ ) );
require_once DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-transport.php';
Delicat_Builder_V9_Transport::boot();
define( 'DELICAT_BUILDER_V9_URL', set_url_scheme( plugin_dir_url( __FILE__ ), 'https' ) );

/*
 * V9 PRO KERNEL
 * -------------
 * The Pro Kernel owns route detection, asset delivery, navigation and session
 * state. It loads before the V9 modules so it can remove the legacy layers it
 * replaces (three navigation engines, two prefetchers and the Front Slim
 * dequeue pass) instead of running alongside them.
 *
 * Feature modules, option keys, shortcodes and render paths are untouched.
 * Switching the kernel off in Delicat Pro returns the storefront to V9's
 * original behaviour without a rollback.
 */
/* pro.4: the Pro boot is contained.
 *
 * DBP_Kernel::boot() is called from this file, which is on the bootstrap
 * circuit breaker's critical list. An uncaught Throwable escaping the Pro
 * layer therefore risks being attributed here and deactivating the plugin.
 * Containing it means a Pro fault degrades to plain V9 instead. */
if ( file_exists( DELICAT_BUILDER_V9_DIR . 'pro/class-dbp-kernel.php' ) ) {
	try {
		require_once DELICAT_BUILDER_V9_DIR . 'pro/class-dbp-kernel.php';
		if ( class_exists( 'DBP_Kernel', false ) ) {
			DBP_Kernel::boot();
		}
	} catch ( Throwable $delicat_builder_v9_pro_error ) {
		update_option(
			'delicat_builder_v9_pro_boot_error',
			array(
				'time'    => gmdate( 'c' ),
				'version' => DELICAT_BUILDER_V9_VERSION,
				'file'    => basename( (string) $delicat_builder_v9_pro_error->getFile() ),
				'line'    => (int) $delicat_builder_v9_pro_error->getLine(),
				'message' => substr( (string) $delicat_builder_v9_pro_error->getMessage(), 0, 180 ),
			),
			false
		);
		unset( $delicat_builder_v9_pro_error );
	}
}

/** Normalize routing-only request values without allowing array input to throw
 * a PHP 8 TypeError in sanitize_key(), sanitize_text_field() or basename(). */
function delicat_builder_v9_request_scalar( $value ): string {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	return wp_unslash( (string) $value );
}

/*
 * RC53 OPTION CACHE PRIMING
 * -------------------------
 * Every Builder option is written with autoload disabled (`update_option( …,
 * false )`) — 30 of the 32 the plugin owns. That is the right call for storage,
 * because none of them belong in the `alloptions` blob that WordPress loads on
 * every request. The cost is that each `get_option()` on a non-autoloaded name
 * is its own `SELECT`, and this store runs on LiteSpeed with no persistent
 * object cache, so nothing survives between requests to absorb them.
 *
 * A guest storefront request reads nine distinct Builder options before
 * WordPress has routed anything: Safe Mode and its stamp, maintenance, the
 * Cloudflare/production/shell/announcement load gates, live selling, and core
 * settings. That is nine round trips on the critical path of an uncached page.
 *
 * `wp_prime_option_caches()` (WordPress 6.4, which this plugin already
 * requires) fetches a whole set in one query and populates the options cache,
 * so the reads below become cache hits. This is a read-path change only: it
 * alters no value, no autoload flag, and no behaviour. Guarded by
 * function_exists so a host pinned below 6.4 simply keeps the old behaviour.
 */
/*
 * RC55: the RC53 set covered only the reads the bootstrap itself performs. A
 * normal storefront request goes on to read another ~30 plugin-owned options
 * (performance, purchase/woo UI, footer, motion, TurboNav, PWA, unified
 * modules, the menu/header/currency/notification settings this plugin
 * inherited, the failure ledgers, the archive/product presets) — each one its
 * own SELECT on this host. Every name below is read on the guest path by a
 * module that is always parsed or is routed on the common surfaces. Priming
 * a name that is already autoloaded costs nothing (wp_prime_option_caches
 * skips cache hits), and priming a missing one just records `notoptions`.
 */
if ( function_exists( 'wp_prime_option_caches' ) ) {
	wp_prime_option_caches(
		array(
			'delicat_builder_v9_safe_mode',
			'delicat_builder_v9_safe_mode_meta',
			'delicat_builder_v9_settings',
			'delicat_builder_v9_maintenance',
			'delicat_builder_v9_cloudflare',
			'delicat_builder_v9_production',
			'delicat_builder_v9_shell',
			'delicat_builder_v9_announcement',
			'delicat_builder_v9_live_selling',
			'delicat_builder_v9_cache_version',
			/* RC55 additions: read by the always-on kernel and the common routes. */
			'delicat_builder_v9_schema',
			'delicat_builder_v9_native_only_version',
			'delicat_builder_v9_performance',
			'delicat_builder_v9_server_turbo_ready_version',
			'delicat_builder_v9_purchase_ui',
			'delicat_builder_v9_woo_ui',
			'delicat_builder_v9_site',
			'delicat_builder_v9_native_product',
			'delicat_builder_v9_native_pages',
			'delicat_builder_v9_footer',
			'delicat_builder_v9_motion',
			'delicat_builder_v9_turbonav',
			'delicat_builder_v9_front_slim',
			'delicat_builder_v9_storefront_fix',
			'delicat_builder_v9_design',
			'delicat_builder_v9_unified_modules',
			'delicat_builder_v9_pwa',
			/* RC78: written with autoload off by RC77 but read on every init(). */
			'delicat_builder_v9_pwa_rules',
			'delicat_builder_v9_app_tuning',
			/* RC80: read on every init() by the upgrade purge. */
			'delicat_builder_v9_purged_version',
			'delicat_builder_v9_legal',
			'delicat_builder_v9_web_push_enabled',
			'delicat_builder_v9_vapid_public',
			'delicat_builder_v9_archive_builder',
			'delicat_builder_v9_native_archive_preset',
			'delicat_builder_v9_compiled_stamp',
			'delicat_builder_v9_spb_v7411_settings',
			'delicat_builder_v9_boot_failures',
			'delicat_builder_v9_module_load_failures',
			'delicat_builder_v9_shell_failures',
			'delicat_builder_v9_unified_failures',
			'delicat_builder_v9_last_runtime_failure',
			'delicat_builder_v9_last_server_engine_failure',
			'delicat_builder_v9_dependency_gate',
			'delicat_builder_v9_private_page_cache',
			'dsb8_beta2_header',
			'dsb_menu_builder_settings',
			'dsb_menu_builder_items',
			'dsb_menu_quick_items',
			'dsb_header_notifications_enabled',
			'dsb_header_notifications',
			'dsb_bell_personal',
			'dmc_settings',
			'dmc_currencies',
			'delicat_direct_swatches_settings',
		)
	);
}

/* Resolve Safe Mode before selecting a class graph. Trips from an older build
 * are stale after an update; current-build trips receive a truly minimal boot. */
$delicat_builder_v9_boot_safe_mode = (bool) get_option( 'delicat_builder_v9_safe_mode', false );
if ( $delicat_builder_v9_boot_safe_mode ) {
	$delicat_builder_v9_boot_safe_meta = get_option( 'delicat_builder_v9_safe_mode_meta', array() );
	$delicat_builder_v9_boot_safe_stamp = is_array( $delicat_builder_v9_boot_safe_meta )
		? (string) ( $delicat_builder_v9_boot_safe_meta['version'] ?? '' )
		: '';
	if ( DELICAT_BUILDER_V9_VERSION !== $delicat_builder_v9_boot_safe_stamp ) {
		$delicat_builder_v9_boot_safe_mode = false;
		update_option( 'delicat_builder_v9_safe_mode', 0, false );
		update_option(
			'delicat_builder_v9_safe_mode_meta',
			array(
				'version'  => DELICAT_BUILDER_V9_VERSION,
				'cleared'  => gmdate( 'c' ),
				'context'  => 'bootstrap_upgrade_recovery',
				'previous' => sanitize_text_field( $delicat_builder_v9_boot_safe_stamp ),
			),
			false
		);
	}
}

/**
 * Lightweight public homepage-context check.
 *
 * RC39.11 keeps the 39 KB admin-driven Homepage builder class off normal
 * storefront requests. Runtime modules only need these two boolean meta flags.
 */
function delicat_builder_v9_is_managed_page( int $page_id ): bool {
	static $managed_cache = array();

	if ( $page_id <= 0 ) {
		return false;
	}
	if ( array_key_exists( $page_id, $managed_cache ) ) {
		return (bool) $managed_cache[ $page_id ];
	}

	$managed_cache[ $page_id ] = (
		(bool) get_post_meta( $page_id, '_delicat_builder_v9_homepage_managed', true )
		|| (bool) get_post_meta( $page_id, '_delicat_builder_v9_homepage_starter_backup', true )
	);
	return (bool) $managed_cache[ $page_id ];
}

/**
 * Load an optional Builder class without allowing one broken module to take the
 * whole storefront down. ParseError/Error implement Throwable on supported PHP.
 * The failing module is skipped for the request and Safe Mode is enabled.
 */
function delicat_builder_v9_safe_require( string $relative ): bool {
	$relative = ltrim( str_replace( array( '../', '..\\' ), '', $relative ), '/\\' );
	$path = DELICAT_BUILDER_V9_DIR . $relative;
	if ( ! is_file( $path ) ) {
		return false;
	}

	try {
		require_once $path;
		return true;
	} catch ( Throwable $error ) {
		if ( function_exists( 'update_option' ) ) {
			update_option( 'delicat_builder_v9_safe_mode', 1, false );
			update_option(
				'delicat_builder_v9_safe_mode_meta',
				array(
					'version' => DELICAT_BUILDER_V9_VERSION,
					'tripped' => gmdate( 'c' ),
					'context' => 'safe_require',
				),
				false
			);
			$events = get_option( 'delicat_builder_v9_module_load_failures', array() );
			$events = is_array( $events ) ? $events : array();
			$events[] = array(
				'time' => gmdate( 'c' ),
				'file' => sanitize_file_name( basename( $path ) ),
				'line' => absint( $error->getLine() ),
				'type' => sanitize_text_field( get_class( $error ) ),
				'message' => sanitize_text_field( function_exists( 'mb_substr' ) ? mb_substr( (string) $error->getMessage(), 0, 160 ) : substr( (string) $error->getMessage(), 0, 160 ) ),
				'hash' => hash( 'sha256', $path . '|' . $error->getMessage() . '|' . $error->getLine() ),
			);
			update_option( 'delicat_builder_v9_module_load_failures', array_slice( $events, -5 ), false );
		}
		return false;
	}
}

/*
 * RC40 MAINTENANCE GATE
 * ---------------------
 * Loaded before every other decision in this file and outside the Core graph
 * on purpose. Safe Mode, the dependency gate and foreign AJAX all skip Core;
 * a closed store that reopens itself in any of those states is not closed.
 *
 * Cost when maintenance is off: one autoloaded option read, no class parsed.
 * wp-admin, wp-login.php and WP-Cron are never gated by the module itself, so
 * an administrator can always sign in and switch it back off.
 */
$delicat_builder_v9_maintenance = get_option( 'delicat_builder_v9_maintenance', array() );
$delicat_builder_v9_maintenance_on = is_array( $delicat_builder_v9_maintenance ) && ! empty( $delicat_builder_v9_maintenance['enabled'] );
/* The studio's "Aperçu" links open a storefront URL while the store is still
 * open, so the preview owner has to be loaded for that request too. The
 * capability and nonce are checked inside the handler. */
$delicat_builder_v9_maintenance_preview = ! is_admin()
	&& ! wp_doing_ajax()
	&& isset( $_GET['delicat_maintenance_preview'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only; the handler verifies capability and nonce.

if ( $delicat_builder_v9_maintenance_on || $delicat_builder_v9_maintenance_preview ) {
	if ( delicat_builder_v9_safe_require( 'includes/class-delicat-builder-maintenance.php' ) && class_exists( 'Delicat_Builder_V9_Maintenance', false ) ) {
		try {
			if ( $delicat_builder_v9_maintenance_on ) {
				Delicat_Builder_V9_Maintenance::boot();
			}
			if ( $delicat_builder_v9_maintenance_preview ) {
				delicat_builder_v9_safe_require( 'includes/class-delicat-builder-maintenance-admin.php' );
				if ( class_exists( 'Delicat_Builder_V9_Maintenance_Admin', false ) ) {
					Delicat_Builder_V9_Maintenance_Admin::boot();
				}
			}
		} catch ( Throwable $delicat_builder_v9_maintenance_error ) {
			/* A failure here must not take the site down, but it must also not
			 * silently reopen a store the owner closed: record and continue. */
			update_option( 'delicat_builder_v9_maintenance_boot_failure', array( 'time' => gmdate( 'c' ), 'message' => sanitize_text_field( substr( (string) $delicat_builder_v9_maintenance_error->getMessage(), 0, 160 ) ) ), false );
			unset( $delicat_builder_v9_maintenance_error );
		}
	}
}

/*
 * RC51.58 WOO CHECKOUT REQUEST BRIDGE
 * -----------------------------------
 * Classic checkout renders fields on the page request, then rebuilds and
 * validates the field schema on WooCommerce's separate `wc-ajax` requests.
 * Load only the small native checkout contract for those two official Woo
 * endpoints so hidden address fields cannot return as required at submission.
 * This registers filters only; Woo owns the endpoint, nonce, totals, gateway,
 * payment and order creation from start to finish.
 */
$delicat_builder_v9_wc_ajax = isset( $_REQUEST['wc-ajax'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- endpoint routing only.
	? sanitize_key( delicat_builder_v9_request_scalar( $_REQUEST['wc-ajax'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- endpoint routing only.
	: '';
if ( ! $delicat_builder_v9_boot_safe_mode && in_array( $delicat_builder_v9_wc_ajax, array( 'checkout', 'update_order_review' ), true ) ) {
	delicat_builder_v9_safe_require( 'includes/class-delicat-builder-purchase-native.php' );
	if (
		class_exists( 'Delicat_Builder_V9_Purchase_Native', false )
		&& is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'activate_checkout_request' ) )
	) {
		try {
			Delicat_Builder_V9_Purchase_Native::activate_checkout_request();
		} catch ( Throwable $error ) {
			update_option( 'delicat_builder_v9_safe_mode', 1, false );
			update_option( 'delicat_builder_v9_safe_mode_meta', array( 'version' => DELICAT_BUILDER_V9_VERSION, 'tripped' => gmdate( 'c' ), 'context' => 'checkout_activation' ), false );
			unset( $error );
		}
		/* Register after every plugin so the PHP_INT_MAX schema filter is truly last. */
		add_action(
			'plugins_loaded',
			static function () {
				try {
					Delicat_Builder_V9_Purchase_Native::boot();
				} catch ( Throwable $error ) {
					update_option( 'delicat_builder_v9_safe_mode', 1, false );
					update_option( 'delicat_builder_v9_safe_mode_meta', array( 'version' => DELICAT_BUILDER_V9_VERSION, 'tripped' => gmdate( 'c' ), 'context' => 'checkout_bridge' ), false );
					unset( $error );
				}
			},
			PHP_INT_MAX
		);
	}
}

/**
 * One-time RC51.58 migration for this storefront's native-only architecture.
 * Existing Header Studio design values, menus and page layouts are preserved;
 * only engine ownership/performance switches are promoted.
 */
function delicat_builder_v9_maybe_upgrade_native_only(): bool {
	$native_only_version = (string) get_option( 'delicat_builder_v9_native_only_version', '' );
	/*
	 * 9.1 RC1: the per-version stamp chain is replaced by an integer schema.
	 * Step bodies below are the proven 51.5x blocks, untouched; only the
	 * gates changed. Legacy stamps convert once, then the stamp is retired.
	 */
	$schema = absint( get_option( 'delicat_builder_v9_schema', 0 ) );
	if ( $schema >= 7 ) {
		return false;
	}
	if ( 0 === $schema && '' !== $native_only_version ) {
		$legacy_map = array(
			'51.52' => 2, '51.53' => 2, '51.54' => 2, '51.55' => 2,
			'51.56' => 2, '51.57' => 2, '51.58' => 2, '51.59' => 3,
			'51.60' => 4, '51.61' => 4, '51.62' => 4, '51.63' => 5,
		);
		$schema = isset( $legacy_map[ $native_only_version ] ) ? (int) $legacy_map[ $native_only_version ] : 0;
		/* RC19: legacy stamps map at most to 5; step 6 below still has to run. */
	}

	/*
	 * Sites already migrated by RC51.52-RC51.58 need only the Purchase ownership gate
	 * below. Do not rewrite unrelated performance or shell preferences again.
	 */
	if ( $schema < 2 ) { /* step 1+2: native-only baseline + purchase contract seed */
		$core = get_option( 'delicat_builder_v9_settings', array() );
		$core = is_array( $core ) ? $core : array();
		$core['enabled'] = 1;
		$core['app_navigation'] = 0;
		$core['navigation_scope'] = 'marked';
		$core['prefetch'] = 1;
		$core['low_power_mode'] = 1;
		$core['compiled_assets'] = 1;
		$core['carousel_progressive'] = 1;
		$core['carousel_initial'] = 3;
		update_option( 'delicat_builder_v9_settings', $core, false );

		$performance = get_option( 'delicat_builder_v9_performance', array() );
		$performance = is_array( $performance ) ? $performance : array();
		$performance['enabled'] = 1;
		$performance['critical_css'] = 1;
		$performance['high_confidence_preloads'] = 1;
		$performance['network_aware'] = 1;
		$performance['server_rendered_pages'] = 1;
		$performance['server_fragment_cache'] = 1;
		$performance['server_page_cache_hint'] = 1;
		$performance['server_shell_mode'] = 'v9';
		$performance['islands_mode'] = 1;
		$performance['zero_global_js'] = 1;
		$performance['query_cache_enabled'] = 1;
		update_option( 'delicat_builder_v9_performance', $performance, false );

		$native_product = get_option( 'delicat_builder_v9_native_product', array() );
		$native_product = is_array( $native_product ) ? $native_product : array();
		$native_product['enabled'] = 1;
		$native_product['force_native_template'] = 1;
		update_option( 'delicat_builder_v9_native_product', $native_product, false );

		$site = get_option( 'delicat_builder_v9_site', array() );
		$site = is_array( $site ) ? $site : array();
		$site['enabled'] = 1;
		$site['account_style'] = 1;
		$site['thankyou_style'] = 1;
		$site['fourofour_enabled'] = 1;
		$site['search_style'] = 1;
		update_option( 'delicat_builder_v9_site', $site, false );

		$shell = get_option( 'delicat_builder_v9_shell', array() );
		$shell = is_array( $shell ) ? $shell : array();
		$shell['enabled'] = 1;
		$shell['scope'] = 'all_frontend';
		$shell['header_enabled'] = 0;
		$shell['mobile_bottom_nav'] = 1;
		$shell['mobile_nav_scope'] = 'all_frontend';
		update_option( 'delicat_builder_v9_shell', $shell, false );
	}

	/* RC51.58: preserve the identical page/AJAX contract and refresh presentation assets. */
	$purchase = get_option( 'delicat_builder_v9_purchase_ui', array() );
	$purchase = is_array( $purchase ) ? $purchase : array();
	$purchase['enabled'] = 1;
	$purchase['native_cart'] = 1;
	$purchase['native_checkout'] = 1;
	update_option( 'delicat_builder_v9_purchase_ui', $purchase, false );

	/*
	 * RC51.59: Live Selling becomes opt-in. The stored option was seeded to
	 * enabled=1 by activation defaults, not by a merchant decision, so it is
	 * switched off exactly once here. Re-enabling it in the Live Selling admin
	 * screen is an explicit save and is never touched by a later migration.
	 */
	if ( $schema < 3 ) { /* step 3: Live Selling becomes opt-in (once) */
		$live = get_option( 'delicat_builder_v9_live_selling', array() );
		$live = is_array( $live ) ? $live : array();
		$live['enabled'] = 0;
		update_option( 'delicat_builder_v9_live_selling', $live, false );
	}

	/*
	 * RC51.60: Elementor is removed from the site. Builder V9 is the sole
	 * storefront engine; make the native archive takeover deterministic so
	 * /shop/ and product categories never fall back to the bare theme loop.
	 * WooCommerce remains the commerce engine; this publishes presentation only.
	 */
	if ( $schema < 4 ) { /* step 4: deterministic native archive */
	try {
		if ( ! class_exists( 'Delicat_Builder_V9_Archive_Builder', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-archive-builder.php' );
		}
		if ( class_exists( 'Delicat_Builder_V9_Archive_Builder', false ) && is_callable( array( 'Delicat_Builder_V9_Archive_Builder', 'maybe_install_native_preset' ) ) ) {
			Delicat_Builder_V9_Archive_Builder::maybe_install_native_preset();
		}
	} catch ( Throwable $delicat_builder_v9_preset_error ) {
		/* Presentation preset only; a failure here must never gate the upgrade. */
		unset( $delicat_builder_v9_preset_error );
	}
	$woo_ui = get_option( 'delicat_builder_v9_woo_ui', array() );
	$woo_ui = is_array( $woo_ui ) ? $woo_ui : array();
	$woo_ui['enabled'] = 1;
	$woo_ui['archive_enabled'] = 1;
	update_option( 'delicat_builder_v9_woo_ui', $woo_ui, false );
	}

	if ( $schema < 5 ) { /* step 5: hot-path autoload consolidation */
	/*
	 * RC51.63: hot-path options were stored with autoload=no, so every
	 * frontend request paid one extra database query per option read —
	 * a dozen-plus queries before any page work. These options are read on
	 * (nearly) every request; loading them with alloptions removes those
	 * queries outright. Values are untouched; only the autoload flag moves.
	 */
	if ( function_exists( 'wp_set_option_autoload_values' ) ) {
		try {
			wp_set_option_autoload_values(
				array_fill_keys(
					array(
						'delicat_builder_v9_safe_mode',
						'delicat_builder_v9_safe_mode_meta',
						'delicat_builder_v9_native_only_version',
						'delicat_builder_v9_settings',
						'delicat_builder_v9_shell',
						'delicat_builder_v9_woo_ui',
						'delicat_builder_v9_purchase_ui',
						'delicat_builder_v9_production',
						'delicat_builder_v9_native_product',
						'delicat_builder_v9_native_pages',
						'delicat_builder_v9_site',
						'delicat_builder_v9_performance',
						'delicat_builder_v9_motion',
						'delicat_builder_v9_footer',
						'delicat_builder_v9_storefront_fix',
						'delicat_builder_v9_front_slim',
						'delicat_builder_v9_turbonav',
						'delicat_builder_v9_dependency_gate',
						'delicat_builder_v9_cache_version',
						'delicat_builder_v9_live_selling',
						'delicat_builder_v9_cf',
						'delicat_builder_v9_cloudflare',
						'delicat_builder_v9_boot_failures',
						'delicat_builder_v9_module_load_failures',
						'delicat_builder_v9_shell_failures',
						'dsb8_beta2_header',
						'dsb_menu_builder_settings',
						'dsb_menu_builder_items',
						'dsb_menu_quick_items',
					),
					true
				)
			);
		} catch ( Throwable $delicat_builder_v9_autoload_error ) {
			unset( $delicat_builder_v9_autoload_error );
		}
	}
	}

	if ( $schema < 6 ) { /* step 6: RC19 — WooCommerce notice toasts become opt-in */
		/*
		 * The floating "toast" mirror of every WooCommerce notice stacked
		 * added-to-cart, coupon and no-gateway messages on top of the checkout.
		 * The native cart/checkout never mirror notices any more; elsewhere the
		 * option is switched off exactly once and stays an explicit Purchase
		 * Studio choice from here on.
		 */
		$purchase_toasts = get_option( 'delicat_builder_v9_purchase_ui', array() );
		$purchase_toasts = is_array( $purchase_toasts ) ? $purchase_toasts : array();
		$purchase_toasts['notice_toasts'] = 0;
		update_option( 'delicat_builder_v9_purchase_ui', $purchase_toasts, false );
	}

	if ( $schema < 7 ) { /* step 7: pro.17 — free every customer the retired payment lock is still holding */
		/*
		 * The express payment module kept a durable per-customer mutex in wp_options
		 * and released it only when an administrator ticked a box. Any customer whose
		 * last attempt ended uncertainly is still locked out of checkout right now,
		 * and the module that could release them has been deleted. Free them here, and
		 * keep a copy of what was released so nothing is lost: these rows name a
		 * customer and, where one was created, an order id, which is what anyone
		 * reconciling a past payment would need.
		 */
		global $wpdb;
		try {
			$held = $wpdb->get_results(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'delicat\\_payment\\_%' ORDER BY option_id ASC LIMIT 500"
			);
			if ( $held ) {
				$archive = array();
				foreach ( $held as $row ) {
					$archive[] = array(
						'name'  => (string) $row->option_name,
						'value' => maybe_unserialize( $row->option_value ),
					);
				}
				update_option(
					'delicat_builder_v9_retired_payment_locks',
					array( 'retired' => gmdate( 'c' ), 'count' => count( $archive ), 'rows' => array_slice( $archive, 0, 200 ) ),
					false
				);
				foreach ( $held as $row ) {
					delete_option( (string) $row->option_name );
				}
			}
		} catch ( Throwable $delicat_builder_v9_lock_error ) {
			unset( $delicat_builder_v9_lock_error );
		}
		wp_clear_scheduled_hook( 'delicat_builder_v9_payment_cleanup' );
	}

	if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
		Delicat_Builder_V9_Cache::bump_version();
	}
	do_action( 'litespeed_purge_all' );

	/* Keep emergency Safe Mode as-is; the Woo-native presentation is now safe-mode resilient. */
	update_option( 'delicat_builder_v9_schema', 7, true );
	/* Legacy stamp kept readable for older-build rollbacks; no longer authoritative. */
	update_option( 'delicat_builder_v9_native_only_version', 'schema-7', false );
	return true;
}

/*
 * RC51 NATIVE PERFORMANCE BOOTSTRAP
 * ---------------------------------
 * Public requests now load a small always-on kernel. Heavy page builders are
 * resolved after WordPress knows the request type. Admin keeps the full graph,
 * and known AJAX endpoints receive only their owning runtime.
 */
$delicat_builder_v9_ajax_action = '';
if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $delicat_builder_v9_ajax_action = sanitize_key( delicat_builder_v9_request_scalar( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/* Plugin activation must parse only the minimal kernel. Loading every admin,
 * editor, PWA and Woo renderer inside WordPress's activation sandbox made an
 * optional integration capable of aborting activation before its own guard ran. */
$delicat_builder_v9_admin_action = isset( $_REQUEST['action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request routing only.
	? sanitize_key( delicat_builder_v9_request_scalar( $_REQUEST['action'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	: '';
$delicat_builder_v9_plugin_arg = isset( $_REQUEST['plugin'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request routing only.
	? sanitize_text_field( delicat_builder_v9_request_scalar( $_REQUEST['plugin'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	: '';
$delicat_builder_v9_activating = is_admin()
	&& ! wp_doing_ajax()
	&& in_array( $delicat_builder_v9_admin_action, array( 'activate', 'activate-selected' ), true )
	&& ( '' === $delicat_builder_v9_plugin_arg || plugin_basename( __FILE__ ) === $delicat_builder_v9_plugin_arg );
$delicat_builder_v9_admin_page = isset( $_REQUEST['page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request routing only.
	? sanitize_key( delicat_builder_v9_request_scalar( $_REQUEST['page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	: '';
$delicat_builder_v9_option_group = isset( $_POST['option_page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- settings routing only; Options API verifies its nonce.
	? sanitize_key( delicat_builder_v9_request_scalar( $_POST['option_page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
	: '';
$delicat_builder_v9_admin_script = isset( $_SERVER['SCRIPT_NAME'] )
	? sanitize_file_name( basename( delicat_builder_v9_request_scalar( $_SERVER['SCRIPT_NAME'] ) ) )
	: '';
$delicat_builder_v9_builder_admin_request = (
	0 === strpos( $delicat_builder_v9_admin_page, 'delicat-builder-v9' )
	|| in_array( $delicat_builder_v9_admin_page, array( 'delicat-direct-swatches', 'delicat-notifications', 'delicat-live-selling' ), true )
	|| 0 === strpos( $delicat_builder_v9_admin_action, 'delicat_builder_v9_' )
	|| 0 === strpos( $delicat_builder_v9_option_group, 'delicat_builder_v9' )
	|| 'dsb8_beta2' === $delicat_builder_v9_option_group
);

// Builder must be invisible on foreign, missing and unknown admin-ajax actions.
// An arbitrary `delicat_builder_v9_*` string is not authority to parse the full
// admin graph: only handlers actually registered by this release are admitted.
$delicat_builder_v9_known_ajax_actions = array(
	'delicat_builder_v9_product_search',
	'delicat_builder_v9_cart_snapshot',
	'delicat_builder_v9_header_remove_cart_item',
	'delicat_builder_v9_wallet_balance',
	'delicat_builder_v9_favorite',
	'delicat_builder_v9_notifications_list',
	'delicat_builder_v9_notifications_read',
	'delicat_builder_v9_notifications_dismiss',
	'delicat_builder_v9_review_prompt',
	'delicat_builder_v9_review_submit',
	'delicat_builder_v9_review_snooze',
	'delicat_builder_v9_review_products',
	'delicat_builder_v9_clear_cart',
);
$delicat_builder_v9_known_ajax_actions = apply_filters( 'delicat_builder_v9_known_ajax_actions', $delicat_builder_v9_known_ajax_actions );
$delicat_builder_v9_known_ajax_actions = is_array( $delicat_builder_v9_known_ajax_actions )
	? array_values( array_unique( array_filter( array_map( 'sanitize_key', $delicat_builder_v9_known_ajax_actions ) ) ) )
	: array();
if ( ! defined( 'DELICAT_BUILDER_V9_FOREIGN_AJAX' ) ) {
    define(
        'DELICAT_BUILDER_V9_FOREIGN_AJAX',
        wp_doing_ajax()
		&& ! in_array( $delicat_builder_v9_ajax_action, $delicat_builder_v9_known_ajax_actions, true )
    );
}

/*
 * RC66 REVIEW INVITATION AFTER DELIVERY
 * -------------------------------------
 * Orders reach "completed" from admin, gateway callbacks, the digital
 * gateway and cron — none of which load the storefront module graph. This
 * bridge stays cheap (one closure, no include) until an order actually
 * completes, then loads the reviews module and queues one product review
 * invitation for the buyer (user meta only; the popup does the asking).
 */
if ( ! $delicat_builder_v9_boot_safe_mode ) {
	add_action(
		'woocommerce_order_status_completed',
		static function ( $order_id ) {
			if ( ! function_exists( 'delicat_builder_v9_safe_require' ) ) {
				return;
			}
			if ( ! class_exists( 'Delicat_Builder_V9_Reviews', false ) ) {
				delicat_builder_v9_safe_require( 'includes/class-delicat-builder-reviews.php' );
			}
			if ( class_exists( 'Delicat_Builder_V9_Reviews', false ) && is_callable( array( 'Delicat_Builder_V9_Reviews', 'order_completed' ) ) ) {
				try {
					Delicat_Builder_V9_Reviews::order_completed( absint( $order_id ) );
				} catch ( Throwable $error ) {
					unset( $error );
				}
			}
		},
		20
	);
}

/*
 * RC51.7 VARIATION BADGE ADMIN BRIDGE
 * ------------------------------------
 * Product variation rows are frequently rendered and saved by WooCommerce's
 * own AJAX endpoints. RC51.2 intentionally bypasses the complete Builder graph
 * on foreign AJAX requests, which also meant the V9 variation badge hooks were
 * absent on Woo's load/add/save-variation requests. The result was intermittent
 * missing badge controls and values that appeared to save but were not persisted.
 *
 * Load only the small swatch/badge module for the product editor and for
 * variation-related WooCommerce AJAX. The public swatch ownership rules remain
 * unchanged, so this does not re-enable duplicate storefront swatch engines.
 */
$delicat_builder_v9_badge_admin_request = is_admin()
	&& ! wp_doing_ajax()
	&& ! $delicat_builder_v9_activating
	&& ! $delicat_builder_v9_boot_safe_mode
	&& (
		'delicat-direct-swatches' === $delicat_builder_v9_admin_page
		|| in_array( $delicat_builder_v9_admin_script, array( 'post.php', 'post-new.php' ), true )
	);
$delicat_builder_v9_badge_ajax_request  = false;
if ( wp_doing_ajax() ) {
    $delicat_builder_v9_badge_fields_present = false;
    foreach ( array(
        '_ddsw_badges_present', '_ddsw_delivery_preset', '_ddsw_delivery_text', '_ddsw_delivery_bg', '_ddsw_delivery_color',
        '_ddsw_recommended', '_ddsw_recommended_label',
        '_ddsw_badge2_preset', '_ddsw_badge2_text', '_ddsw_badge2_bg', '_ddsw_badge2_color', '_ddsw_features',
    ) as $delicat_builder_v9_badge_field ) {
        if ( isset( $_REQUEST[ $delicat_builder_v9_badge_field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- detection only; Woo owns the save nonce.
            $delicat_builder_v9_badge_fields_present = true;
            break;
        }
    }
    $delicat_builder_v9_woo_variation_ajax = (
        '' !== $delicat_builder_v9_ajax_action
        && 0 === strpos( $delicat_builder_v9_ajax_action, 'woocommerce_' )
        && false !== strpos( $delicat_builder_v9_ajax_action, 'variation' )
    );
    $delicat_builder_v9_badge_ajax_request = $delicat_builder_v9_badge_fields_present || $delicat_builder_v9_woo_variation_ajax;
}
if ( $delicat_builder_v9_badge_admin_request || $delicat_builder_v9_badge_ajax_request ) {
    delicat_builder_v9_safe_require( 'includes/class-delicat-builder-swatches.php' );
    if ( class_exists( 'Delicat_Builder_V9_Swatches', false ) && is_callable( array( 'Delicat_Builder_V9_Swatches', 'instance' ) ) ) {
		try {
			Delicat_Builder_V9_Swatches::instance();
		} catch ( Throwable $error ) {
			update_option( 'delicat_builder_v9_safe_mode', 1, false );
			update_option( 'delicat_builder_v9_safe_mode_meta', array( 'version' => DELICAT_BUILDER_V9_VERSION, 'tripped' => gmdate( 'c' ), 'context' => 'swatches_bridge' ), false );
			unset( $error );
		}
    }
}

/*
 * RC45/RC46 PRODUCT EDITOR BRIDGE
 * -------------------------------
 * Every Builder panel that attaches to `add_meta_boxes_product` was stranded on
 * the WooCommerce product editor, and for one shared reason.
 *
 * The runtime router returns immediately on is_admin(), and the Builder admin
 * bundle below is gated behind $delicat_builder_v9_builder_admin_request, which
 * is false on post.php / post-new.php (it only matches `page=delicat-builder-v9*`
 * and the Builder's own actions/option groups). So on the one screen where a
 * merchant edits a product, none of these classes were ever parsed:
 * `add_meta_boxes_product` and `save_post_product` never registered, the panels
 * did not exist, and there was no save path behind them either.
 *
 * RC45 fixed only the Product / Service / Region Switcher. That was an
 * incomplete reading: the gate excludes the whole family, not one module. The
 * "Delicat — Important / À savoir" panel (Native Product) and the "Product
 * Fields & Calculator" panel (Unified Modules) were dark for the same reason —
 * which is why an already-configured IMPORTANT block kept rendering on the
 * storefront from stored meta while its editor had disappeared.
 *
 * Each module is loaded and booted independently. A panel that throws is
 * skipped and the rest of the editor still loads; Safe Mode is deliberately not
 * triggered from here, because taking the whole storefront down over an admin
 * metabox is a worse outcome than one missing panel. Every boot() carries its
 * own idempotence guard, so Core booting the same class later is a no-op.
 */
$delicat_builder_v9_product_editor_request = is_admin()
	&& ! wp_doing_ajax()
	&& ! $delicat_builder_v9_activating
	&& ! $delicat_builder_v9_boot_safe_mode
	&& in_array( $delicat_builder_v9_admin_script, array( 'post.php', 'post-new.php' ), true );

if ( $delicat_builder_v9_product_editor_request ) {
	/* `post` is the edit-screen GET; `post_ID` is what post.php receives on the
	 * save POST. Both must resolve, or the panels would render and then never
	 * persist because their save hooks were absent on the save request. */
	$delicat_builder_v9_editor_post_type = '';
	if ( isset( $_REQUEST['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only; each module owns its own save nonce.
		$delicat_builder_v9_editor_post_type = sanitize_key( (string) wp_unslash( $_REQUEST['post_type'] ) );
	} else {
		$delicat_builder_v9_editor_post_id = 0;
		foreach ( array( 'post_ID', 'post' ) as $delicat_builder_v9_editor_key ) {
			if ( isset( $_REQUEST[ $delicat_builder_v9_editor_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only.
				$delicat_builder_v9_editor_post_id = absint( wp_unslash( $_REQUEST[ $delicat_builder_v9_editor_key ] ) );
				if ( $delicat_builder_v9_editor_post_id > 0 ) {
					break;
				}
			}
		}
		if ( $delicat_builder_v9_editor_post_id > 0 ) {
			$delicat_builder_v9_editor_post_type = (string) get_post_type( $delicat_builder_v9_editor_post_id );
		}
		unset( $delicat_builder_v9_editor_post_id, $delicat_builder_v9_editor_key );
	}

	/* post-new.php with no post_type is a normal Post; only pay for products. */
	if ( 'product' === $delicat_builder_v9_editor_post_type ) {
		/* file => class to boot, or '' when the file boots itself on include. */
		foreach ( array(
			'includes/class-delicat-builder-native-product.php'   => 'Delicat_Builder_V9_Native_Product',
			'includes/class-delicat-builder-product-switcher.php' => '',
			'includes/class-delicat-builder-unified-modules.php'  => 'Delicat_Builder_V9_Unified_Modules',
		) as $delicat_builder_v9_editor_file => $delicat_builder_v9_editor_class ) {
			delicat_builder_v9_safe_require( $delicat_builder_v9_editor_file );
			if (
				'' !== $delicat_builder_v9_editor_class
				&& class_exists( $delicat_builder_v9_editor_class, false )
				&& is_callable( array( $delicat_builder_v9_editor_class, 'boot' ) )
			) {
				try {
					$delicat_builder_v9_editor_class::boot();
				} catch ( Throwable $error ) {
					$delicat_builder_v9_editor_events = get_option( 'delicat_builder_v9_boot_failures', array() );
					$delicat_builder_v9_editor_events = is_array( $delicat_builder_v9_editor_events ) ? $delicat_builder_v9_editor_events : array();
					$delicat_builder_v9_editor_events[] = array(
						'time'    => gmdate( 'c' ),
						'module'  => sanitize_key( strtolower( str_replace( 'Delicat_Builder_V9_', '', $delicat_builder_v9_editor_class ) ) ),
						'type'    => sanitize_text_field( get_class( $error ) ),
						'message' => sanitize_text_field( substr( (string) $error->getMessage(), 0, 160 ) ),
						'where'   => sanitize_text_field( basename( (string) $error->getFile() ) . ':' . (int) $error->getLine() ),
						'context' => 'product_editor_bridge',
					);
					update_option( 'delicat_builder_v9_boot_failures', array_slice( $delicat_builder_v9_editor_events, -5 ), false );
					unset( $delicat_builder_v9_editor_events, $error );
				}
			}
		}
		unset( $delicat_builder_v9_editor_file, $delicat_builder_v9_editor_class );
	}
	unset( $delicat_builder_v9_editor_post_type );
}

$delicat_builder_v9_admin_modules = array(
    'includes/class-delicat-builder-cache.php','includes/class-delicat-builder-query-cache.php','includes/class-delicat-builder-turbo-diagnostics.php','includes/class-delicat-builder-security.php','includes/class-delicat-builder-server-engine.php','includes/class-delicat-builder-compiler.php',
    'includes/class-delicat-builder-media.php','includes/class-delicat-builder-hero-search.php','includes/class-delicat-builder-shell.php','includes/class-delicat-builder-footer.php',
    'includes/class-delicat-builder-archive-builder.php','includes/class-delicat-builder-native-product.php','includes/class-delicat-builder-checkout-sheet.php','includes/class-delicat-builder-product-switcher.php','includes/class-delicat-builder-menu-builder.php',
    'includes/class-delicat-builder-header-studio-8.php','includes/class-delicat-builder-woo-ui.php','includes/class-delicat-builder-purchase-ui.php',
    'includes/class-delicat-builder-performance.php','includes/class-delicat-builder-identity-bridge.php','includes/class-delicat-builder-production.php',
    'includes/class-delicat-builder-release.php','includes/class-delicat-builder-design.php','includes/class-delicat-builder-assets.php',
    'includes/class-delicat-builder-badges.php','includes/class-delicat-builder-heart-engine.php','includes/class-delicat-builder-carousel.php',
    'includes/class-delicat-builder-schema.php','includes/class-delicat-builder-renderer.php','includes/class-delicat-builder-pages.php','includes/class-delicat-builder-native-pages.php','includes/class-delicat-builder-reviews.php','includes/class-delicat-builder-cloudflare.php','includes/class-delicat-builder-front-slim.php','includes/class-delicat-builder-storefront-fix.php',
    'includes/class-delicat-builder-unified-modules.php','includes/class-delicat-builder-site.php',
    /*
     * RC52: Live Selling was a dead end. Core parses this file only when
     * `delicat_builder_v9_live_selling['enabled']` is already truthy, and that
     * option is written from the module's own admin page — which is registered
     * by the class Core was declining to load. There was no menu entry from
     * which to switch the feature on. Loading it on Builder admin requests
     * makes the class exist in time for `admin_menu`; boot() still gates every
     * storefront behaviour behind enabled(), so a disabled campaign costs the
     * frontend nothing.
     *
     * Swatches is deliberately NOT listed here. Unified Modules owns whether a
     * V9 swatch engine may attach at all (it stands down when a legacy swatch
     * plugin is present), and force-loading the class from here would bypass
     * that ownership check and risk duplicate product-editor fields.
     */
    'includes/class-delicat-builder-live-selling.php','includes/class-delicat-builder-announcement.php',
    'includes/class-delicat-builder-core.php',
);

if ( $delicat_builder_v9_activating ) {
	/* Intentionally load no class files. WordPress's activation callback below
	 * only uses core WordPress functions and primitive option values. */
} elseif ( is_admin() && ! wp_doing_ajax() ) {
	$delicat_builder_v9_safe_admin = $delicat_builder_v9_boot_safe_mode;
	if ( $delicat_builder_v9_builder_admin_request && ! $delicat_builder_v9_safe_admin ) {
		foreach ( $delicat_builder_v9_admin_modules as $delicat_builder_v9_module ) delicat_builder_v9_safe_require( $delicat_builder_v9_module );
		foreach ( array(
			'includes/class-delicat-builder-design-admin.php','includes/class-delicat-builder-homepage.php','includes/class-delicat-builder-shell-admin.php',
			'includes/class-delicat-builder-woo-admin.php','includes/class-delicat-builder-purchase-admin.php','includes/class-delicat-builder-performance-admin.php',
			'includes/class-delicat-builder-motion-admin.php','includes/class-delicat-builder-security-admin.php','includes/class-delicat-builder-production-admin.php',
			'includes/class-delicat-builder-self-test.php',
			'includes/class-delicat-builder-release-admin.php','includes/class-delicat-builder-homepage-admin.php','includes/class-delicat-builder-site-admin.php',
			'includes/class-delicat-builder-editor.php','includes/class-delicat-builder-admin.php',
			'includes/class-delicat-builder-maintenance.php','includes/class-delicat-builder-maintenance-admin.php',
		) as $delicat_builder_v9_module ) delicat_builder_v9_safe_require( $delicat_builder_v9_module );
	} else {
		/* Dashboard, Plugins, Orders and other unrelated admin screens only need
		 * the small menu/settings kernel. This prevents activation redirects from
		 * parsing the entire Builder feature graph. */
		foreach ( array(
			'includes/class-delicat-builder-cache.php',
			'includes/class-delicat-builder-query-cache.php',
			'includes/class-delicat-builder-security.php',
			'includes/class-delicat-builder-core.php',
			'includes/class-delicat-builder-admin.php',
			/* RC29: the "Avis" dashboard is the reviews post type's own list screen;
			 * its class must register the type on every admin request or the menu
			 * entry and edit.php?post_type=delicat_review disappear. */
			'includes/class-delicat-builder-reviews.php',
		) as $delicat_builder_v9_module ) delicat_builder_v9_safe_require( $delicat_builder_v9_module );

		/* RC40: while the store is closed, the warning notice and admin-bar flag
		 * must appear on every admin screen — but cost nothing when it is open. */
		if ( is_array( $delicat_builder_v9_maintenance ) && ! empty( $delicat_builder_v9_maintenance['enabled'] ) ) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-maintenance.php' );
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-maintenance-admin.php' );
		}
	}
} elseif ( wp_doing_ajax() ) {
    $ajax_modules = array();
    if ( DELICAT_BUILDER_V9_FOREIGN_AJAX ) {
        // No Builder class graph on foreign admin-ajax actions.
    } else {
		$ajax_modules = array('includes/class-delicat-builder-cache.php','includes/class-delicat-builder-security.php');
    if ( in_array( $delicat_builder_v9_ajax_action, array('delicat_builder_v9_product_search','delicat_builder_v9_cart_snapshot'), true ) ) {
        $ajax_modules[] = 'includes/class-delicat-builder-shell.php';
    } elseif ( 'delicat_builder_v9_header_remove_cart_item' === $delicat_builder_v9_ajax_action ) {
        $ajax_modules[] = 'includes/class-delicat-builder-menu-runtime.php';
        $ajax_modules[] = 'includes/class-delicat-builder-header-runtime.php';
    } elseif ( 'delicat_builder_v9_wallet_balance' === $delicat_builder_v9_ajax_action ) {
        $ajax_modules[] = 'includes/class-delicat-builder-menu-runtime.php';
    } elseif ( 'delicat_builder_v9_favorite' === $delicat_builder_v9_ajax_action ) {
        $ajax_modules[] = 'includes/class-delicat-builder-heart-engine.php';
    } elseif ( 0 === strpos( $delicat_builder_v9_ajax_action, 'delicat_builder_v9_notifications_' ) ) {
        $ajax_modules[] = 'includes/class-delicat-builder-unified-runtime.php';
    } elseif ( 0 === strpos( $delicat_builder_v9_ajax_action, 'delicat_builder_v9_review_' ) ) {
        // RC51.28: client review prompt/submit/snooze only needs the Reviews module.
        $ajax_modules[] = 'includes/class-delicat-builder-reviews.php';
	} elseif ( 'delicat_builder_v9_clear_cart' === $delicat_builder_v9_ajax_action ) {
		$ajax_modules[] = 'includes/class-delicat-builder-storefront-fix.php';
    }
        $ajax_modules[] = 'includes/class-delicat-builder-core.php';
    }
    foreach ( array_unique($ajax_modules) as $delicat_builder_v9_module ) delicat_builder_v9_safe_require( $delicat_builder_v9_module );
} elseif ( ! $delicat_builder_v9_boot_safe_mode && 'delicat_session' === $delicat_builder_v9_wc_ajax ) {
	/*
	 * RC71 ULTRA-LIGHT SESSION PROFILE
	 * --------------------------------
	 * This is the hottest uncached Builder endpoint. Session now returns only
	 * identity, wallet and cart count; cart lines and notification state have
	 * dedicated transports. Keep the bootstrap to four small dependencies and
	 * never parse Header/Menu/Unified/Router for a JSON response.
	 */
	foreach ( array(
		'includes/class-delicat-builder-cache.php',
		'includes/class-delicat-builder-security.php',
		'includes/class-delicat-builder-session.php',
		'includes/class-delicat-builder-core.php',
	) as $delicat_builder_v9_module ) delicat_builder_v9_safe_require( $delicat_builder_v9_module );
} else {
    // Always-on storefront kernel: security/integration systems that must attach
    // before query resolution plus Header/Menu, which are global UI.
	$delicat_builder_v9_public_modules = $delicat_builder_v9_boot_safe_mode
		? array(
			'includes/class-delicat-builder-cache.php',
			'includes/class-delicat-builder-query-cache.php',
			'includes/class-delicat-builder-security.php',
			'includes/class-delicat-builder-core.php',
		)
		: array(
			'includes/class-delicat-builder-cache.php','includes/class-delicat-builder-query-cache.php','includes/class-delicat-builder-security.php','includes/class-delicat-builder-performance.php','includes/class-delicat-builder-server-engine.php',
			'includes/class-delicat-builder-unified-runtime.php',
			'includes/class-delicat-builder-menu-runtime.php','includes/class-delicat-builder-header-runtime.php','includes/class-delicat-builder-drawer.php','includes/class-delicat-builder-bottom-nav.php','includes/class-delicat-builder-session.php','includes/class-delicat-builder-shell-nav.php',
			'includes/class-delicat-builder-footer.php',
			'includes/class-delicat-builder-runtime-router.php','includes/class-delicat-builder-reviews-kernel.php','includes/class-delicat-builder-front-slim.php','includes/class-delicat-builder-storefront-fix.php',
			'includes/class-delicat-builder-core.php',
			'includes/class-delicat-builder-turbonav.php',
			'includes/class-delicat-builder-app-polish.php',
			'includes/class-delicat-builder-app-tuning.php',
			'includes/class-delicat-builder-legal.php',
			'includes/class-delicat-builder-stability.php',

		);
	foreach ( $delicat_builder_v9_public_modules as $delicat_builder_v9_module ) delicat_builder_v9_safe_require( $delicat_builder_v9_module );

	/* RC71: diagnostics are a developer-only overlay. Do not parse its class on
	 * every shopper request; the class performs the capability check at render. */
	$delicat_builder_v9_diag = isset( $_GET['dbv9_turbo_diag'] ) ? sanitize_text_field( delicat_builder_v9_request_scalar( $_GET['dbv9_turbo_diag'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only debug switch.
	if ( '1' === $delicat_builder_v9_diag ) {
		delicat_builder_v9_safe_require( 'includes/class-delicat-builder-turbo-diagnostics.php' );
	}
	unset( $delicat_builder_v9_diag );

	// Parse the Cloudflare API client only when a complete edge-purge profile is active.
	$delicat_builder_v9_cf = get_option( 'delicat_builder_v9_cloudflare', array() );
	if ( ! $delicat_builder_v9_boot_safe_mode && is_array( $delicat_builder_v9_cf ) && ! empty( $delicat_builder_v9_cf['enabled'] ) && ! empty( $delicat_builder_v9_cf['zone_id'] ) && ( ! empty( $delicat_builder_v9_cf['api_token'] ) || ! empty( $delicat_builder_v9_cf['api_token_encrypted'] ) ) ) {
		delicat_builder_v9_safe_require( 'includes/class-delicat-builder-cloudflare.php' );
	}

    // Production headers must attach before WordPress sends headers, but the
    // 14 KB hardening module is skipped completely when its feature is disabled.
    $delicat_builder_v9_production = get_option( 'delicat_builder_v9_production', array() );
    if ( ! $delicat_builder_v9_boot_safe_mode && is_array($delicat_builder_v9_production) && ! empty($delicat_builder_v9_production['enabled']) ) {
        delicat_builder_v9_safe_require( 'includes/class-delicat-builder-production.php' );
    }
    /* RC52: the announcement popup is opt-in. A disabled campaign must not cost
     * the storefront a class parse, so the runtime loads only once the merchant
     * has actually switched one on. */
    $delicat_builder_v9_announcement = get_option( 'delicat_builder_v9_announcement', array() );
    if ( ! $delicat_builder_v9_boot_safe_mode && is_array( $delicat_builder_v9_announcement ) && ! empty( $delicat_builder_v9_announcement['enabled'] ) ) {
        delicat_builder_v9_safe_require( 'includes/class-delicat-builder-announcement.php' );
    }

    // Shell shortcodes are registered during init; load it early only when used.
    $delicat_builder_v9_shell = get_option( 'delicat_builder_v9_shell', array() );
    if ( ! $delicat_builder_v9_boot_safe_mode && is_array($delicat_builder_v9_shell) && ! empty($delicat_builder_v9_shell['enabled']) ) {
        delicat_builder_v9_safe_require( 'includes/class-delicat-builder-shell.php' );
    }
}

/* RC51 dependency gate: only the small kernel is fatal-critical. Heavy modules
 * are quarantined independently by the runtime router. */
if ( ! $delicat_builder_v9_activating && ! DELICAT_BUILDER_V9_FOREIGN_AJAX ) {
    $delicat_builder_v9_critical = array('Delicat_Builder_V9_Core','Delicat_Builder_V9_Cache');
    if ( ! is_admin() && ! wp_doing_ajax() && ! $delicat_builder_v9_boot_safe_mode ) {
        $delicat_builder_v9_critical[] = 'Delicat_Builder_V9_Security';
        /* wc-ajax is not marked DOING_AJAX until Woo init. The session JSON
         * profile deliberately skips Runtime Router and never renders a page. */
        if ( 'delicat_session' !== $delicat_builder_v9_wc_ajax ) {
            $delicat_builder_v9_critical[] = 'Delicat_Builder_V9_Runtime_Router';
        }
    }
    $delicat_builder_v9_missing_critical = array();
    foreach ( $delicat_builder_v9_critical as $delicat_builder_v9_dep ) if ( ! class_exists($delicat_builder_v9_dep, false) ) $delicat_builder_v9_missing_critical[] = $delicat_builder_v9_dep;
    if ( $delicat_builder_v9_missing_critical ) {
        define( 'DELICAT_BUILDER_V9_DORMANT', true );
        update_option( 'delicat_builder_v9_safe_mode', 1, false );
        update_option( 'delicat_builder_v9_dependency_gate', array('time'=>gmdate('c'),'missing'=>array_map('sanitize_text_field',$delicat_builder_v9_missing_critical),'context'=>is_admin()?(wp_doing_ajax()?'ajax':'admin'):'frontend'), false );
    } elseif ( is_admin() && is_array( get_option('delicat_builder_v9_dependency_gate', false) ) ) {
        delete_option( 'delicat_builder_v9_dependency_gate' );
    }
}


/* Product Builder buy-now intent must exist before WooCommerce processes
 * add-to-cart at wp_loaded. The heavy Product Builder runtime remains lazy. */
add_filter(
    'woocommerce_add_to_cart_redirect',
    static function ( $url ) {
        $intent = isset($_REQUEST['dsb_purchase_intent']) ? sanitize_key(delicat_builder_v9_request_scalar($_REQUEST['dsb_purchase_intent'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        // RC51.60: the native product form and its mobile dock submit
        // `delicat_native_buy_now`; honor it here at plugins_loaded because the
        // Native Product module itself only attaches at `wp`, after Woo has
        // already chosen the post-add redirect (the checkout-goes-to-cart bug).
        if ( ! empty($_REQUEST['dsb_buy_now']) || 'buy' === $intent || ! empty($_REQUEST['delicat_native_buy_now']) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : $url;
        }
        return $url;
    },
    999
);

/* RC19: a buy-now purchase lands on the native checkout, where Woo's
 * "X a été ajouté à votre panier" success notice is pure noise. Returning an
 * empty message makes wc_add_notice() store nothing, so the notice never
 * reaches the checkout page (or any cached toast layer). */
add_filter(
	'wc_add_to_cart_message_html',
	static function ( $message ) {
		$intent = isset( $_REQUEST['dsb_purchase_intent'] ) ? sanitize_key( delicat_builder_v9_request_scalar( $_REQUEST['dsb_purchase_intent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['dsb_buy_now'] ) || 'buy' === $intent || ! empty( $_REQUEST['delicat_native_buy_now'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		return $message;
	},
	999
);

/*
 * pro.17: the express payment transport is gone.
 *
 * It was a custom wc-ajax endpoint that called WC_Checkout directly, guarded by a
 * durable per-customer mutex in wp_options. The merchant asked for the opposite:
 * when a customer has no funds, decline the payment and let WooCommerce and
 * WordPress run everything. "Acheter maintenant" is a plain submit inside
 * form.cart, so with this layer removed it posts to WooCommerce's own add-to-cart
 * handler and the filter above redirects it to the real checkout, which is also
 * exactly what happened with JavaScript disabled.
 *
 * Removed with it: delicat_builder_v9_express_request(), the two wp_loaded guards,
 * the woocommerce_add_to_cart_redirect JSON short-circuit, and the
 * wc_ajax_delicat_express_form endpoint that rendered a signed-in customer's
 * checkout fields and process-checkout nonce into a fetch response.
 */

/*
 * RC28: Builder's stylesheets and scripts are excluded from LiteSpeed Cache's
 * guest-only optimizers (CSS/JS combine, unique CSS, deferred/delayed JS).
 * Those rewrites are keyed on crawls that predate a release, and a signed-out
 * visitor then receives new markup with old or stripped CSS. Builder ships its
 * own critical CSS and load order; leaving its files untouched is the safe
 * default. The filters are no-ops without LiteSpeed.
 */
foreach ( array( 'litespeed_optimize_css_excludes', 'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc', 'litespeed_optm_gm_js_exc', 'litespeed_optm_css_async_exc' ) as $delicat_builder_v9_ls_filter ) {
	add_filter(
		$delicat_builder_v9_ls_filter,
		static function ( $list ) {
			$list   = is_array( $list ) ? $list : array();
			$list[] = 'delicat-builder-v9/assets/';
			$list[] = 'delicat-builder-v9/pro/assets/';
			// Inline config must execute with its external runtime, before first interaction.
			foreach ( array( 'DBPNavConfig', 'DBPStateConfig', 'DelicatShellNavConfig', 'DelicaBuilderV9Config' ) as $config_name ) {
				$list[] = $config_name;
			}
			return array_values( array_unique( $list ) );
		}
	);
}
unset( $delicat_builder_v9_ls_filter );
add_filter(
	'litespeed_ucss_whitelist',
	static function ( $list ) {
		$list = is_array( $list ) ? $list : array();
		foreach ( array( '.dpn-', '.dnp-', '.dbv9-', '.delicat-', '.dsb-', '.dsb8-', '.dlc-', '.dap-', '.ddsw-', '.dmc-', '.dcn-' ) as $prefix ) {
			$list[] = $prefix;
		}
		return array_values( array_unique( $list ) );
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			try {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'cart_checkout_blocks',
					DELICAT_BUILDER_V9_FILE,
					true
				);
			} catch ( Throwable $error ) {
				update_option( 'delicat_builder_v9_safe_mode', 1, false );
				update_option( 'delicat_builder_v9_safe_mode_meta', array( 'version' => DELICAT_BUILDER_V9_VERSION, 'tripped' => gmdate( 'c' ), 'context' => 'woocommerce_compatibility' ), false );
				unset( $error );
			}
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Delicat Builder V9 requires PHP 7.4 or newer.', 'delicat-builder-v9' ) );
		}

		/* RC17: activation is deliberately boring. Seed only primitive options;
		 * no feature class or optional installer is parsed in the activation sandbox. */
		$core_defaults = array(
			'enabled' => 1, 'app_navigation' => 0, 'navigation_scope' => 'marked',
			'content_selector' => 'main', 'prefetch' => 1, 'cache_ttl' => 600,
			'safe_headers' => 1, 'low_power_mode' => 1, 'compiled_assets' => 1,
			'hero_preload' => 1, 'carousel_progressive' => 1, 'carousel_initial' => 8,
		);
		$core_current = get_option( 'delicat_builder_v9_settings', array() );
		update_option( 'delicat_builder_v9_settings', wp_parse_args( is_array( $core_current ) ? $core_current : array(), $core_defaults ), false );
		if ( false === get_option( 'delicat_builder_v9_cache_version', false ) ) {
			add_option( 'delicat_builder_v9_cache_version', 1, '', false );
		}
		$safe_meta = get_option( 'delicat_builder_v9_safe_mode_meta', array() );
		$safe_stamp = is_array( $safe_meta ) ? (string) ( $safe_meta['version'] ?? '' ) : '';
		if ( (bool) get_option( 'delicat_builder_v9_safe_mode', false ) && DELICAT_BUILDER_V9_VERSION !== $safe_stamp ) {
			update_option( 'delicat_builder_v9_safe_mode', 0, false );
			update_option(
				'delicat_builder_v9_safe_mode_meta',
				array(
					'version'  => DELICAT_BUILDER_V9_VERSION,
					'cleared'  => gmdate( 'c' ),
					'context'  => 'activation_upgrade_recovery',
					'previous' => sanitize_text_field( $safe_stamp ),
				),
				false
			);
		}
		update_option( 'delicat_builder_v9_activation_version', DELICAT_BUILDER_V9_VERSION, false );
	}
);

/**
 * Rebuild each Builder page's compiled stylesheet after a version change.
 *
 * pro.17. Every Builder page has a stylesheet compiled from only the components
 * that page actually uses - for this store's homepage, roughly half the size of
 * the catch-all bundle. The public path refuses to serve it when its manifest
 * names a different plugin version, and nothing rebuilt it except saving the
 * page by hand in the Builder.
 *
 * So every update silently downgraded the homepage from its own stylesheet to
 * the 28KB fallback: render-blocking, on every visit, for every visitor, until
 * somebody happened to re-save the page. On a 2G connection that is seconds
 * before anything can paint, and nothing anywhere said so.
 *
 * This is deliberately NOT part of the schema migration. A schema step runs
 * once, ever; this has to run once per VERSION, because that is what
 * invalidates the manifest. Admin-only, because compiling writes files and the
 * public path is designed never to.
 */
function delicat_builder_v9_maybe_recompile_pages(): void {
	if ( (string) get_option( 'delicat_builder_v9_compiled_version', '' ) === DELICAT_BUILDER_V9_VERSION ) {
		return;
	}
	if ( ! class_exists( 'Delicat_Builder_V9_Compiler', false ) || ! class_exists( 'Delicat_Builder_V9_Pages', false ) ) {
		return;
	}

	/* Claim the version first. A compile that fails must not be retried on every
	 * admin page load for the rest of the release. */
	update_option( 'delicat_builder_v9_compiled_version', DELICAT_BUILDER_V9_VERSION, false );

	$report = array( 'at' => time(), 'version' => DELICAT_BUILDER_V9_VERSION, 'recompiled' => 0, 'failed' => array() );

	try {
		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'private', 'draft' ),
				'numberposts'      => 60,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- once per release, in admin.
					array(
						'key'     => Delicat_Builder_V9_Pages::META_LAYOUT,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( (array) $pages as $page_id ) {
			$layout = Delicat_Builder_V9_Pages::get_layout( (int) $page_id );
			if ( empty( $layout ) ) {
				continue;
			}
			$result = Delicat_Builder_V9_Compiler::compile_page( (int) $page_id, $layout );
			if ( ! empty( $result['file'] ) ) {
				$report['recompiled']++;
			} else {
				$report['failed'][] = (int) $page_id;
			}
		}

		$report['failed'] = array_slice( $report['failed'], 0, 20 );
	} catch ( Throwable $error ) {
		$report['error'] = substr( $error->getMessage(), 0, 180 );
		unset( $error );
	}

	/* Recorded so the health panel can say so. A page still on the fallback is
	 * not an error, but it is slower than it needs to be, and until now there
	 * was no way to find that out. */
	update_option( 'delicat_builder_v9_last_recompile', $report, false );
}

add_action( 'admin_init', 'delicat_builder_v9_maybe_recompile_pages', 20 );

add_action(
	'plugins_loaded',
	static function () {
        if ( defined( 'DELICAT_BUILDER_V9_FOREIGN_AJAX' ) && DELICAT_BUILDER_V9_FOREIGN_AJAX ) {
            return;
        }
		if ( defined( 'DELICAT_BUILDER_V9_DORMANT' ) && DELICAT_BUILDER_V9_DORMANT ) {
			// RC39.13 dependency gate: a critical module was quarantined. Booting
			// would fatal on an unguarded cross-reference, so stay dormant and let
			// the storefront fall back to plain WordPress/WooCommerce.
			return;
		}
		$delicat_builder_v9_did_upgrade = false;
		try {
			$delicat_builder_v9_did_upgrade = delicat_builder_v9_maybe_upgrade_native_only();
		} catch ( Throwable $error ) {
			update_option( 'delicat_builder_v9_safe_mode', 1, false );
			update_option( 'delicat_builder_v9_safe_mode_meta', array( 'version' => DELICAT_BUILDER_V9_VERSION, 'tripped' => gmdate( 'c' ), 'context' => 'schema_upgrade' ), false );
			unset( $error );
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Core', false ) || ! is_callable( array( 'Delicat_Builder_V9_Core', 'instance' ) ) ) {
			// The guarded loader has already recorded the failure and enabled Safe Mode.
			// Leaving the plugin dormant is safer than taking down WordPress.
			return;
		}
		// Identity Pro declares its SDK/classes before this callback. Sites without
		// that plugin avoid parsing an integration bridge they cannot use.
		if (
			! is_admin()
			&& ! wp_doing_ajax()
			&& ( defined( 'DIP_VERSION' ) || class_exists( 'DIP_Plugin', false ) || class_exists( 'DIP_SDK', false ) )
		) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-identity-bridge.php' );
		}
		try {
			$core = Delicat_Builder_V9_Core::instance();
		} catch ( Throwable $error ) {
			update_option( 'delicat_builder_v9_safe_mode', 1, false );
			update_option( 'delicat_builder_v9_safe_mode_meta', array( 'version' => DELICAT_BUILDER_V9_VERSION, 'tripped' => gmdate( 'c' ), 'context' => 'core_instance' ), false );
			unset( $error );
			return;
		}
		if ( is_object( $core ) && is_callable( array( $core, 'boot' ) ) ) {
			try {
				$core->boot();
			} catch ( Throwable $error ) {
				update_option( 'delicat_builder_v9_safe_mode', 1, false );
				update_option( 'delicat_builder_v9_safe_mode_meta', array( 'version' => DELICAT_BUILDER_V9_VERSION, 'tripped' => gmdate( 'c' ), 'context' => 'core_boot' ), false );
				unset( $error );
			}
		}
		if ( $delicat_builder_v9_did_upgrade ) {
			/* Cloudflare's LiteSpeed bridge is attached by Core::boot(), so purge again at the edge. */
			do_action( 'litespeed_purge_all' );
		}
	}
);

/*
 * pro.16: this PRO14 module was the only file pulled in with a bare
 * require_once — on every request, including plugin activation and Safe Mode,
 * and outside the guarded loader every other module goes through. A damaged
 * upload of either file would have fataled every request without the
 * circuit breaker being able to isolate it. Load them through the same guard
 * (a Throwable quarantines the module and records Safe Mode) and never
 * during the activation sandbox, which parses no class files by design.
 */
if ( ! $delicat_builder_v9_activating ) {
	delicat_builder_v9_safe_require( 'includes/class-delicat-builder-audit-fixes.php' );
}
