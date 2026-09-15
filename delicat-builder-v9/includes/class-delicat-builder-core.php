<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Core {
	private static ?self $instance = null;
	private static ?array $settings_cache = null;
	private static ?array $motion_settings_cache = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		// Live Selling is optional. Parse its runtime only when enabled, rather
		// than making every storefront request pay for a disabled feature.
		/* RC51.59: Live Selling is opt-in. A missing or malformed option means
		 * disabled; only an explicit admin-saved enabled=1 parses the runtime. */
		$live_settings = get_option( 'delicat_builder_v9_live_selling', array() );
		$live_enabled  = is_array( $live_settings ) && ! empty( $live_settings['enabled'] );
		if ( $live_enabled && ! class_exists( 'Delicat_Builder_V9_Live_Selling', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-live-selling.php' );
		}

		$frontend_modules = array(
			'Delicat_Builder_V9_Cache',
			'Delicat_Builder_V9_Query_Cache',
			'Delicat_Builder_V9_Turbo_Diagnostics',
			'Delicat_Builder_V9_Runtime_Router',
			'Delicat_Builder_V9_Security',
			'Delicat_Builder_V9_Compiler',
			'Delicat_Builder_V9_Shell',
			'Delicat_Builder_V9_Archive_Builder',
			'Delicat_Builder_V9_Native_Product',
			'Delicat_Builder_V9_Menu_Engine',
			'Delicat_Builder_V9_Menu_Builder',
			'Delicat_Builder_V9_Woo_UI',
			'Delicat_Builder_V9_Purchase_UI',
			'Delicat_Builder_V9_Performance',
			'Delicat_Builder_V9_Server_Engine',
			'Delicat_Builder_V9_Identity_Bridge',
			'Delicat_Builder_V9_Unified_Modules',
			'Delicat_Builder_V9_Live_Selling',
			'Delicat_Builder_V9_Production',
			'Delicat_Builder_V9_Release',
			'Delicat_Builder_V9_Design',
			'Delicat_Builder_V9_Assets',
			'Delicat_Builder_V9_Heart_Engine',
			'Delicat_Builder_V9_Carousel',
			'Delicat_Builder_V9_Pages',
			'Delicat_Builder_V9_Native_Pages',
			'Delicat_Builder_V9_Site',
			'Delicat_Builder_V9_Reviews_Kernel',
			'Delicat_Builder_V9_Bottom_Nav',
			'Delicat_Builder_V9_Reviews',
			'Delicat_Builder_V9_Cloudflare',
			'Delicat_Builder_V9_Front_Slim',
			'Delicat_Builder_V9_Storefront_Fix',
			'Delicat_Builder_V9_Announcement',
			/* RC40: idempotent — the bootstrap has usually booted it already. */
			'Delicat_Builder_V9_Maintenance',
		);

		foreach ( $frontend_modules as $module ) {
			self::boot_module( $module );
		}

		if ( is_admin() ) {
			$admin_modules = array(
				'Delicat_Builder_V9_Admin',
				'Delicat_Builder_V9_Editor',
				'Delicat_Builder_V9_Shell_Admin',
				'Delicat_Builder_V9_Woo_Admin',
				'Delicat_Builder_V9_Purchase_Admin',
				'Delicat_Builder_V9_Performance_Admin',
				'Delicat_Builder_V9_Motion_Admin',
				'Delicat_Builder_V9_Security_Admin',
				'Delicat_Builder_V9_Production_Admin',
				'Delicat_Builder_V9_Release_Admin',
				'Delicat_Builder_V9_Design_Admin',
				'Delicat_Builder_V9_Homepage_Admin',
				'Delicat_Builder_V9_Site_Admin',
				'Delicat_Builder_V9_Maintenance_Admin',
			);
			foreach ( $admin_modules as $module ) {
				self::boot_module( $module );
			}
		}
	}

	private static function boot_module( string $module ): void {
		if ( ! class_exists( $module, false ) || ! is_callable( array( $module, 'boot' ) ) ) {
			return;
		}

		try {
			$module::boot();
		} catch ( Throwable $error ) {
			$events = get_option( 'delicat_builder_v9_boot_failures', array() );
			$events = is_array( $events ) ? $events : array();
			$events[] = array(
				'time'   => gmdate( 'c' ),
				'module' => sanitize_key( strtolower( str_replace( 'Delicat_Builder_V9_', '', $module ) ) ),
				'type'   => sanitize_text_field( get_class( $error ) ),
				/* RC6/RC8: the hash alone made past incidents undiagnosable (the
				 * Aug-21 'performance' trips). Keep the hash for grouping and
				 * store a truncated message + file:line for forensics. */
				'message' => sanitize_text_field( function_exists( 'mb_substr' ) ? mb_substr( (string) $error->getMessage(), 0, 160 ) : substr( (string) $error->getMessage(), 0, 160 ) ),
				'where'   => sanitize_text_field( basename( (string) $error->getFile() ) . ':' . (int) $error->getLine() ),
				'hash'   => hash( 'sha256', $module . '|' . $error->getMessage() . '|' . $error->getFile() . '|' . $error->getLine() ),
			);
			$events = array_slice( $events, -5 );
			update_option( 'delicat_builder_v9_boot_failures', $events, false );
			self::enter_safe_mode( 'boot_module' );
		}
	}

	public static function settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$defaults = array(
			'enabled'          => 1,
			'app_navigation'   => 0,
			'shell_navigation' => 1,
			'navigation_scope' => 'marked',
			'content_selector' => 'main',
			'prefetch'         => 1,
			'cache_ttl'        => 600,
			'safe_headers'     => 1,
			'low_power_mode'   => 1,
			'compiled_assets'  => 1,
			'hero_preload'      => 1,
			'carousel_progressive' => 1,
			'carousel_initial'  => 8,
		);

		$saved = get_option( 'delicat_builder_v9_settings', array() );
		self::$settings_cache = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
		return self::$settings_cache;
	}


	public static function motion_defaults(): array {
		return array(
			'enabled'             => 1,
			'effect'              => 'fade-up',
			'duration_ms'         => 440,
			'intensity'           => 45,
			'stagger_ms'          => 55,
			'base_delay_ms'       => 0,
			'threshold_percent'   => 4,
			'desktop_enabled'     => 1,
			'tablet_enabled'      => 1,
			'mobile_enabled'      => 1,
			'mobile_intensity'    => 70,
			'page_transitions'    => 1,
			'page_transition'     => 'fade-slide',
			'page_duration_ms'    => 220,
		);
	}

	public static function sanitize_motion_settings( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$effects = array( 'fade-up', 'fade', 'scale', 'slide-left', 'slide-right', 'soft-zoom' );
		$transitions = array( 'fade', 'fade-slide', 'scale', 'none' );

		return array(
			'enabled'             => empty( $input['enabled'] ) ? 0 : 1,
			'effect'              => in_array( $input['effect'] ?? 'fade-up', $effects, true ) ? $input['effect'] : 'fade-up',
			'duration_ms'         => min( 900, max( 180, absint( $input['duration_ms'] ?? 440 ) ) ),
			'intensity'           => min( 100, max( 0, absint( $input['intensity'] ?? 45 ) ) ),
			'stagger_ms'          => min( 150, max( 0, absint( $input['stagger_ms'] ?? 55 ) ) ),
			'base_delay_ms'       => min( 300, max( 0, absint( $input['base_delay_ms'] ?? 0 ) ) ),
			'threshold_percent'   => min( 30, max( 1, absint( $input['threshold_percent'] ?? 4 ) ) ),
			'desktop_enabled'     => empty( $input['desktop_enabled'] ) ? 0 : 1,
			'tablet_enabled'      => empty( $input['tablet_enabled'] ) ? 0 : 1,
			'mobile_enabled'      => empty( $input['mobile_enabled'] ) ? 0 : 1,
			'mobile_intensity'    => min( 100, max( 20, absint( $input['mobile_intensity'] ?? 70 ) ) ),
			'page_transitions'    => empty( $input['page_transitions'] ) ? 0 : 1,
			'page_transition'     => in_array( $input['page_transition'] ?? 'fade-slide', $transitions, true ) ? $input['page_transition'] : 'fade-slide',
			'page_duration_ms'    => min( 600, max( 120, absint( $input['page_duration_ms'] ?? 220 ) ) ),
		);
	}

	public static function motion_settings(): array {
		if ( null !== self::$motion_settings_cache ) {
			return self::$motion_settings_cache;
		}
		$saved = get_option( 'delicat_builder_v9_motion', null );
		if ( ! is_array( $saved ) ) {
			self::$motion_settings_cache = self::motion_defaults();
			return self::$motion_settings_cache;
		}
		self::$motion_settings_cache = self::sanitize_motion_settings( $saved );
		return self::$motion_settings_cache;
	}

	public static function reset_motion_settings_cache(): void {
		self::$motion_settings_cache = null;
	}

	public static function reset_settings_cache(): void {
		self::$settings_cache = null;
	}

	public static function is_enabled(): bool {
		$settings = self::settings();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * RC51.59 self-healing Safe Mode.
	 *
	 * Safe Mode is written automatically after a fatal/module failure but was
	 * only ever cleared by the manual Production button. A trip from an older
	 * build therefore outlived the fix that shipped for it, permanently
	 * disabling Front Slim, TurboNav, Server Engine, Shell, Site, prefetch and
	 * critical CSS ("stale Safe Mode"). Every trip is now stamped with the
	 * plugin version that tripped it; a stamp from a different build is
	 * evidence about code that is no longer running, so it self-heals once and
	 * the performance/style stack returns immediately after an update.
	 * A trip recorded by THIS build (including the manual admin toggle, which
	 * stamps the current version) still holds until fixed or manually cleared.
	 */
	public static function is_safe_mode(): bool {
		if ( ! (bool) get_option( 'delicat_builder_v9_safe_mode', false ) ) {
			return false;
		}

		$meta  = get_option( 'delicat_builder_v9_safe_mode_meta', array() );
		$stamp = is_array( $meta ) ? (string) ( $meta['version'] ?? '' ) : '';
		if ( $stamp === DELICAT_BUILDER_V9_VERSION ) {
			/*
			 * RC51.61: automatic trips are a 10-minute circuit breaker, not a
			 * permanent global kill-switch. Each failing module is already
			 * quarantined individually by its own guarded loader; keeping every
			 * HEALTHY system (reviews, Front Slim, TurboNav, Shell extras,
			 * checkout dock ecosystem) disabled forever amplified one fault
			 * into "half the site stopped working". Manual admin trips are
			 * respected for the lifetime of the build. Re-trips preserve the
			 * ORIGINAL trip time, so a persistent fault degrades in bounded
			 * 10-minute windows instead of permanently.
			 */
			if ( 'manual_admin' === (string) ( $meta['context'] ?? '' ) ) {
				return true;
			}
			$tripped_at = strtotime( (string) ( $meta['tripped'] ?? '' ) );
			if ( $tripped_at && ( time() - $tripped_at ) > 600 ) {
				update_option( 'delicat_builder_v9_safe_mode', 0, false );
				update_option(
					'delicat_builder_v9_safe_mode_meta',
					array(
						'version'      => DELICAT_BUILDER_V9_VERSION,
						'cleared'      => gmdate( 'c' ),
						'auto_expired' => 1,
						'previous'     => sanitize_text_field( (string) ( $meta['context'] ?? '' ) ),
					),
					false
				);
				return false;
			}
			return true;
		}

		update_option( 'delicat_builder_v9_safe_mode', 0, false );
		update_option(
			'delicat_builder_v9_safe_mode_meta',
			array(
				'version'     => DELICAT_BUILDER_V9_VERSION,
				'cleared'     => gmdate( 'c' ),
				'self_healed' => 1,
				'previous'    => sanitize_text_field( $stamp ),
			),
			false
		);
		/* Pages cached while degraded carry Safe-Mode markup; refresh them once. */
		if ( is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}
		do_action( 'litespeed_purge_all' );
		return false;
	}

	/** Single stamped writer used by every automatic Safe-Mode trip site. */
	public static function enter_safe_mode( string $context = '' ): void {
		/* RC51.61: a re-trip keeps the ORIGINAL trip time so the 10-minute
		 * breaker can expire even while a module keeps failing. */
		$prev    = get_option( 'delicat_builder_v9_safe_mode_meta', array() );
		$tripped = gmdate( 'c' );
		if (
			is_array( $prev )
			&& DELICAT_BUILDER_V9_VERSION === (string) ( $prev['version'] ?? '' )
			&& ! empty( $prev['tripped'] )
			&& false !== strtotime( (string) $prev['tripped'] )
		) {
			$tripped = (string) $prev['tripped'];
		}
		update_option( 'delicat_builder_v9_safe_mode', 1, false );
		update_option(
			'delicat_builder_v9_safe_mode_meta',
			array(
				'version' => DELICAT_BUILDER_V9_VERSION,
				'tripped' => $tripped,
				'last'    => gmdate( 'c' ),
				'context' => sanitize_key( $context ),
			),
			false
		);
	}
}
