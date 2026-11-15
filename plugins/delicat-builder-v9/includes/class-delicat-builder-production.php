<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Production-readiness and release-hardening layer.
 *
 * No enforcement header that can break payments/OAuth is enabled by default.
 * CSP is Report-Only, limited to non-sensitive frontend pages, and has no
 * public reporting endpoint.
 */
final class Delicat_Builder_V9_Production {
	public const OPTION = 'delicat_builder_v9_production';
	public const SAFE_MODE_OPTION = 'delicat_builder_v9_safe_mode';
	public const INTEGRITY_FILE = 'integrity-manifest.json';

	private static ?array $settings_cache = null;

	public static function boot(): void {
		add_filter( 'wp_headers', array( __CLASS__, 'headers' ), 80 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 80 );
	}

	public static function defaults(): array {
		return array(
			'enabled'                  => 0,
			'private_cache_headers'    => 1,
			'csp_report_only'          => 0,
			'csp_profile'              => 'compatibility',
			'integrity_scan_cache_min' => 15,
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

	public static function enabled(): bool {
		return Delicat_Builder_V9_Core::is_enabled() && ! empty( self::settings()['enabled'] );
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( Delicat_Builder_V9_Core::is_safe_mode() ) {
			$classes[] = 'delicat-builder-safe-mode';
		}
		if ( self::enabled() ) {
			$classes[] = 'delicat-builder-production-hardening';
		}
		return $classes;
	}

	public static function is_sensitive_page(): bool {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
			return true;
		}

		$dip_action = isset( $_GET['dip_action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['dip_action'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		if ( in_array( $dip_action, array( 'login', 'callback' ), true ) ) {
			return true;
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		$path = strtolower( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) );
		foreach ( array( '/wp-login.php', '/wp-admin', '/wp-json', '/wc-api', '/oauth', '/callback', '/webhook' ) as $blocked ) {
			if ( false !== strpos( $path, $blocked ) ) {
				return true;
			}
		}

		return false;
	}

	private static function header_value( array $headers, string $name ): string {
		$name = strtolower( $name );
		foreach ( $headers as $key => $value ) {
			if ( strtolower( (string) $key ) === $name ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function has_header( array $headers, string $name ): bool {
		$name = strtolower( $name );
		foreach ( $headers as $key => $value ) {
			if ( strtolower( (string) $key ) === $name && '' !== trim( (string) $value ) ) {
				return true;
			}
		}
		return false;
	}

	private static function csp_policy( string $profile ): string {
		if ( 'strict-monitor' === $profile ) {
			return implode(
				'; ',
				array(
					"default-src 'self'",
					"script-src 'self' 'unsafe-inline'",
					"style-src 'self' 'unsafe-inline'",
					"img-src 'self' data: blob: https:",
					"font-src 'self' data:",
					"connect-src 'self' wss:",
					"frame-src 'self'",
					"worker-src 'self' blob:",
					"manifest-src 'self'",
					"object-src 'none'",
					"base-uri 'self'",
					"form-action 'self'",
					"frame-ancestors 'self'",
				)
			);
		}

		// Compatibility monitor: broad HTTPS support but still observes unsafe
		// object/base/frame/form behavior and insecure/nonstandard sources.
		return implode(
			'; ',
			array(
				"default-src 'self' https: data: blob:",
				"script-src 'self' 'unsafe-inline' https: blob:",
				"style-src 'self' 'unsafe-inline' https:",
				"img-src 'self' data: blob: https:",
				"font-src 'self' data: https:",
				"connect-src 'self' https: wss:",
				"frame-src 'self' https:",
				"worker-src 'self' blob:",
				"manifest-src 'self' https:",
				"object-src 'none'",
				"base-uri 'self'",
				"form-action 'self' https:",
				"frame-ancestors 'self'",
			)
		);
	}

	public static function headers( $headers ): array {
		$headers = is_array( $headers ) ? $headers : array();
		if ( ! self::enabled() ) {
			return $headers;
		}

		$settings = self::settings();

		// Private commerce/account pages are dynamic. Do not overwrite a stricter
		// cache policy that another trusted component has already emitted.
		if ( ! empty( $settings['private_cache_headers'] ) && self::is_sensitive_page() ) {
			$current_cache = strtolower( self::header_value( $headers, 'Cache-Control' ) );
			if ( '' === $current_cache || false === strpos( $current_cache, 'no-store' ) ) {
				$headers['Cache-Control'] = 'private, no-store, no-cache, must-revalidate, max-age=0';
			}
			if ( ! self::has_header( $headers, 'Pragma' ) ) {
				$headers['Pragma'] = 'no-cache';
			}
			if ( ! self::has_header( $headers, 'Expires' ) ) {
				$headers['Expires'] = 'Wed, 11 Jan 1984 05:00:00 GMT';
			}
			return $headers;
		}

		// CSP monitoring never runs on checkout/account/login/API/callback pages.
		if (
			! empty( $settings['csp_report_only'] )
			&& ! self::is_sensitive_page()
			&& ! self::has_header( $headers, 'Content-Security-Policy-Report-Only' )
		) {
			$profile = in_array( $settings['csp_profile'] ?? '', array( 'compatibility', 'strict-monitor' ), true )
				? $settings['csp_profile']
				: 'compatibility';

			$headers['Content-Security-Policy-Report-Only'] = self::csp_policy( $profile );
		}

		return $headers;
	}

	public static function integrity_manifest_path(): string {
		return DELICAT_BUILDER_V9_DIR . self::INTEGRITY_FILE;
	}

	public static function load_integrity_manifest(): array {
		$path = self::integrity_manifest_path();
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return array();
		}

		$raw = file_get_contents( $path );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	public static function integrity_scan( bool $force = false ): array {
		$cache_key = 'delicat_builder_v9_integrity_' . md5( DELICAT_BUILDER_V9_VERSION );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$manifest = self::load_integrity_manifest();
		$files = is_array( $manifest['files'] ?? null ) ? $manifest['files'] : array();
		$result = array(
			'ok'       => false,
			'checked'  => 0,
			'missing'  => array(),
			'modified' => array(),
			'unknown'  => array(),
		);

		if ( empty( $files ) ) {
			$result['unknown'][] = 'integrity-manifest.json';
			return $result;
		}

		foreach ( $files as $relative => $expected ) {
			$relative = ltrim( wp_normalize_path( (string) $relative ), '/' );
			if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
				continue;
			}

			$path = wp_normalize_path( DELICAT_BUILDER_V9_DIR . $relative );
			if ( 0 !== strpos( $path, wp_normalize_path( DELICAT_BUILDER_V9_DIR ) ) ) {
				continue;
			}

			$result['checked']++;
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				$result['missing'][] = $relative;
				continue;
			}

			$expected_hash = is_array( $expected ) ? (string) ( $expected['sha256'] ?? '' ) : (string) $expected;
			$actual = hash_file( 'sha256', $path );
			if ( '' === $expected_hash || ! is_string( $actual ) || ! hash_equals( strtolower( $expected_hash ), strtolower( $actual ) ) ) {
				$result['modified'][] = $relative;
			}
		}

		$result['ok'] = empty( $result['missing'] ) && empty( $result['modified'] ) && empty( $result['unknown'] );

		$minutes = min( 60, max( 5, absint( self::settings()['integrity_scan_cache_min'] ?? 15 ) ) );
		set_transient( $cache_key, $result, $minutes * MINUTE_IN_SECONDS );

		return $result;
	}

	public static function config_snapshot(): array {
		// These options contain presentation/performance policy only. The snapshot
		// deliberately excludes users, tokens, API credentials, orders and Identity
		// provider secrets.
		$options = array(
			'delicat_builder_v9_settings',
			'delicat_builder_v9_motion',
			class_exists( 'Delicat_Builder_V9_Shell', false ) ? Delicat_Builder_V9_Shell::OPTION : 'delicat_builder_v9_shell',
			class_exists( 'Delicat_Builder_V9_Footer', false ) ? Delicat_Builder_V9_Footer::OPTION : 'delicat_builder_v9_footer',
			class_exists( 'Delicat_Builder_V9_Woo_UI', false ) ? Delicat_Builder_V9_Woo_UI::OPTION : 'delicat_builder_v9_woo_ui',
			class_exists( 'Delicat_Builder_V9_Native_Product', false ) ? Delicat_Builder_V9_Native_Product::OPTION : 'delicat_builder_v9_native_product',
			'dsb8_beta2_header',
			'dsb_menu_builder_settings',
			'dsb_menu_builder_items',
			'dsb_menu_quick_items',
			class_exists( 'Delicat_Builder_V9_Archive_Builder', false ) ? Delicat_Builder_V9_Archive_Builder::OPTION : 'delicat_builder_v9_archive_builder',
			class_exists( 'Delicat_Builder_V9_Archive_Builder', false ) ? Delicat_Builder_V9_Archive_Builder::DRAFT_OPTION : 'delicat_builder_v9_archive_builder_drafts',
			class_exists( 'Delicat_Builder_V9_Purchase_UI', false ) ? Delicat_Builder_V9_Purchase_UI::OPTION : 'delicat_builder_v9_purchase_ui',
			class_exists( 'Delicat_Builder_V9_Performance', false ) ? Delicat_Builder_V9_Performance::OPTION : 'delicat_builder_v9_performance',
			class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) ? Delicat_Builder_V9_Identity_Bridge::OPTION : 'delicat_builder_v9_identity_sync',
			class_exists( 'Delicat_Builder_V9_Design', false ) ? Delicat_Builder_V9_Design::OPTION : 'delicat_builder_v9_design',
			self::OPTION,
		);

		$data = array();
		foreach ( $options as $option ) {
			$value = get_option( $option, array() );
			$data[ $option ] = is_array( $value ) ? $value : array();
		}

		return array(
			'format'       => 'delicat-builder-v9-config',
			'version'      => DELICAT_BUILDER_V9_VERSION,
			'generated_at' => gmdate( 'c' ),
			'site_host'    => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'safe_mode'    => Delicat_Builder_V9_Core::is_safe_mode(),
			'schema_version'=> class_exists( 'Delicat_Builder_V9_Release' ) ? Delicat_Builder_V9_Release::schema_version() : '',
			'options'      => $data,
		);
	}

	public static function diagnostics(): array {
		$compiler = class_exists( 'Delicat_Builder_V9_Compiler', false ) && is_callable( array( 'Delicat_Builder_V9_Compiler', 'storage_status' ) )
			? Delicat_Builder_V9_Compiler::storage_status()
			: array( 'mode' => 'unavailable', 'writable' => false );
		$integrity = self::integrity_scan();

		$wp_debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$wp_debug_display = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
		$file_edit_disabled = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$release_gate = class_exists( 'Delicat_Builder_V9_Release' ) ? Delicat_Builder_V9_Release::gate_summary() : array();

		return array(
			'https' => array(
				'label' => 'HTTPS',
				'ok'    => is_ssl(),
				'text'  => is_ssl() ? 'Active' : 'Not detected',
			),
			'identity' => array(
				'label' => 'Identity Pro authority',
				'ok'    => class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'compatible' ) ) && Delicat_Builder_V9_Identity_Bridge::compatible(),
				'text'  => class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'compatible' ) ) && Delicat_Builder_V9_Identity_Bridge::compatible()
					? 'Synchronized v' . ( is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'identity_version' ) ) ? Delicat_Builder_V9_Identity_Bridge::identity_version() : '' )
					: 'Review Identity integration',
			),
			'wp_debug' => array(
				'label' => 'WP_DEBUG',
				'ok'    => 'production' !== $env || ! $wp_debug,
				'text'  => $wp_debug ? 'ON' : 'OFF',
			),
			'wp_debug_display' => array(
				'label' => 'WP_DEBUG_DISPLAY',
				'ok'    => 'production' !== $env || ! $wp_debug_display,
				'text'  => $wp_debug_display ? 'ON' : 'OFF',
			),
			'file_editor' => array(
				'label' => 'Dashboard file editor',
				'ok'    => $file_edit_disabled,
				'text'  => $file_edit_disabled ? 'Disabled' : 'Consider DISALLOW_FILE_EDIT',
			),
			'environment' => array(
				'label' => 'WordPress environment',
				'ok'    => in_array( $env, array( 'production', 'staging', 'development', 'local' ), true ),
				'text'  => $env,
			),
			'compiler' => array(
				'label' => 'Compiled CSS storage',
				'ok'    => ! empty( $compiler['ok'] ),
				'text'  => (string) ( $compiler['text'] ?? 'Unknown' ),
			),
			'integrity' => array(
				'label' => 'Builder code integrity',
				'ok'    => ! empty( $integrity['ok'] ),
				'text'  => ! empty( $integrity['ok'] )
					? sprintf( '%d files verified', absint( $integrity['checked'] ?? 0 ) )
					: sprintf(
						'%d modified / %d missing',
						count( $integrity['modified'] ?? array() ),
						count( $integrity['missing'] ?? array() )
					),
			),
			'object_cache' => array(
				'label' => 'Persistent object cache',
				'ok'    => wp_using_ext_object_cache(),
				'text'  => wp_using_ext_object_cache() ? 'Detected' : 'Optional',
			),
			'app_navigation' => array(
				'label' => 'App Navigation',
				'ok'    => empty( Delicat_Builder_V9_Core::settings()['app_navigation'] ),
				'text'  => empty( Delicat_Builder_V9_Core::settings()['app_navigation'] )
					? 'OFF — safe default'
					: 'ON — requires staging validation',
			),
			'release_gate' => array(
				'label' => 'RC release gate',
				'ok'    => empty( $release_gate ) || ! empty( $release_gate['ready'] ),
				'text'  => ! empty( $release_gate )
					? sprintf(
						'%d blocker(s) / %d warning(s)',
						absint( $release_gate['blockers'] ?? 0 ),
						absint( $release_gate['warnings'] ?? 0 )
					)
					: 'Release coordinator unavailable',
			),
			'safe_mode' => array(
				'label' => 'Compatibility Safe Mode',
				'ok'    => true,
				'text'  => Delicat_Builder_V9_Core::is_safe_mode() ? 'ACTIVE' : 'Normal operation',
			),
		);
	}
}
