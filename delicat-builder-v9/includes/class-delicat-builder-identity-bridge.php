<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delicat Identity Pro integration.
 *
 * Delicat Builder intentionally does not implement a second authentication
 * system. When Identity Pro is active, this bridge delegates customer login and
 * security authority to its public SDK / Access Guard / Audit / Reauth engines.
 */
final class Delicat_Builder_V9_Identity_Bridge {
	public const OPTION = 'delicat_builder_v9_identity_sync';
	public const MIN_IDENTITY_VERSION = '6.9.8';
	public const SDK_API = '1.0';

	private static ?array $settings_cache = null;

	public static function boot(): void {
		add_filter( 'dip_identity_extensions', array( __CLASS__, 'register_identity_extension' ) );
		add_filter( 'dip_admin_page_slugs', array( __CLASS__, 'register_admin_pages' ) );
		add_filter( 'dip_admin_only_actions', array( __CLASS__, 'register_admin_actions' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_identity_login' ), 24 );
		add_action( 'dip_sdk_ready', array( __CLASS__, 'sdk_ready' ), 20, 2 );

		/* RC71.3: a failed/expired Identity Google callback must recover into the
		 * storefront modal, never strand a customer on wp-login.php. This hook only
		 * reacts to Identity's own public error query and does not weaken OAuth state,
		 * PKCE, nonce, account-policy or WordPress authentication checks. */
		add_action( 'login_init', array( __CLASS__, 'recover_identity_login_error' ), 1 );
	}

	public static function defaults(): array {
		return array(
			'identity_authority'   => 1,
			'shell_login_modal'    => 1,
			'audit_bridge'         => 1,
			'admin_guard_bridge'   => 1,
			'reauth_security_save' => 1,
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

	public static function identity_available(): bool {
		return defined( 'DIP_VERSION' )
			&& class_exists( 'DIP_SDK' )
			&& class_exists( 'DIP_Access_Guard' );
	}

	public static function identity_version(): string {
		return defined( 'DIP_VERSION' ) ? sanitize_text_field( (string) DIP_VERSION ) : '';
	}

	public static function compatible(): bool {
		if ( ! self::identity_available() ) {
			return false;
		}

		$api = defined( 'DIP_SDK::API_VERSION' ) ? (string) DIP_SDK::API_VERSION : '';
		return version_compare( self::identity_version(), self::MIN_IDENTITY_VERSION, '>=' )
			&& '' !== $api
			&& version_compare( $api, self::SDK_API, '>=' );
	}

	public static function authority_enabled(): bool {
		return ! empty( self::settings()['identity_authority'] ) && self::compatible();
	}

	/**
	 * Call a public static method owned by Identity Pro without assuming that a
	 * method discovered by method_exists() is actually public/callable. Older
	 * Identity builds exposed some helpers as private/protected; calling those
	 * directly from Builder can trigger a frontend fatal when Safe Mode is off.
	 */
	private static function identity_static_callable( string $class, string $method ): bool {
		return class_exists( $class ) && is_callable( array( $class, $method ) );
	}

	private static function identity_native_modal_enabled(): bool {
		if ( ! class_exists( 'DIP_Plugin' ) || ! defined( 'DIP_Plugin::OPTION' ) ) {
			return false;
		}

		$defaults = array();
		if ( self::identity_static_callable( 'DIP_Plugin', 'defaults' ) ) {
			try {
				$value = DIP_Plugin::defaults();
				$defaults = is_array( $value ) ? $value : array();
			} catch ( Throwable $error ) {
				$defaults = array();
			}
		}

		$saved = (array) get_option( DIP_Plugin::OPTION, array() );
		$settings = wp_parse_args( $saved, $defaults );

		return ( $settings['enabled'] ?? 'yes' ) === 'yes'
			&& ( $settings['native_modal_enabled'] ?? 'yes' ) === 'yes';
	}

	public static function guest_login_url(): string {
		return home_url( '/#delicat-login' );
	}

	public static function auth_reset_url(): string {
		return (string) add_query_arg( 'delicat_auth_reset', '1', home_url( '/' ) );
	}

	public static function logout_url(): string {
		return wp_logout_url( self::auth_reset_url() );
	}

	public static function recover_identity_login_error(): void {
		if ( is_user_logged_in() || ! self::authority_enabled() ) {
			return;
		}

		/* Identity's wp-login.php error parameter is `dip_error`; storefront pages
		 * use `dip_auth_error`. RC71.2 listened for the latter on wp-login.php and
		 * therefore never recovered the real expired-Google callback. Accept both
		 * spellings, then normalize to the storefront contract. */
		$raw_error = isset( $_GET['dip_error'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only recovery flag.
			? delicat_builder_v9_request_scalar( $_GET['dip_error'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: delicat_builder_v9_request_scalar( $_GET['dip_auth_error'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = sanitize_key( $raw_error );
		if ( '' === $error ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'Delicat Identity login recovery' );
		$target = (string) add_query_arg(
			array(
				'delicat_auth_recover' => '1',
				'dip_auth_error'       => $error,
			),
			home_url( '/' )
		);
		wp_safe_redirect( $target . '#delicat-login', 302, 'Delicat Builder V9' );
		exit;
	}

	public static function frontend_login_available(): bool {
		/* RC88: the public login modal is a frontend capability, not a privileged
		 * security-authority action. Requiring DIP_SDK + DIP_Access_Guard here made
		 * Builder render WooCommerce's second login form even while Identity's
		 * native modal was healthy and already visible. Keep the strict SDK check
		 * in authority_enabled(); for presentation, require only a supported
		 * Identity version, its Native Auth class and the enabled modal setting. */
		if (
			empty( self::settings()['shell_login_modal'] )
			|| is_user_logged_in()
			|| ! defined( 'DIP_VERSION' )
			|| version_compare( (string) DIP_VERSION, self::MIN_IDENTITY_VERSION, '<' )
			|| ! class_exists( 'DIP_Native_Auth' )
			|| ! self::identity_native_modal_enabled()
		) {
			return false;
		}

		return true;
	}

	public static function maybe_enqueue_identity_login(): void {
		if ( ! self::frontend_login_available() || ! Delicat_Builder_V9_Core::is_enabled() ) {
			return;
		}

		/*
		 * Identity Pro 6.9.8+ registers its own wp_enqueue_scripts callback. Do not
		 * execute that callback a second time from Builder: repeated localization
		 * and modal asset setup adds PHP work and can create optimizer ordering
		 * differences. Only use this bridge as a fallback for an unusual compatible
		 * build that exposes enqueue_assets() but did not register its own hook.
		 */
		if ( false !== has_action( 'wp_enqueue_scripts', array( 'DIP_Native_Auth', 'enqueue_assets' ) ) ) {
			return;
		}
		if ( ! self::identity_static_callable( 'DIP_Native_Auth', 'enqueue_assets' ) ) {
			return;
		}

		try {
			DIP_Native_Auth::enqueue_assets();
		} catch ( Throwable $error ) {
			// Identity UI enhancement failure falls back to the native account URL.
		}
	}

	public static function render_shell_login_button( string $icon_html ): string {
		if ( ! self::frontend_login_available() ) {
			return '';
		}

		return sprintf(
			'<button type="button" class="delicat-shell__icon-button delicat-shell__identity-login" data-dip-auth-open data-dl-open aria-haspopup="dialog" aria-controls="dip-identity-modal" aria-label="%1$s">%2$s</button>',
			esc_attr__( 'Connexion sécurisée', 'delicat-builder-v9' ),
			$icon_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	public static function register_identity_extension( $extensions ): array {
		$extensions = is_array( $extensions ) ? $extensions : array();

		$extensions['delicat-builder-v9'] = array(
			'name'            => 'Delicat Builder V9',
			'version'         => DELICAT_BUILDER_V9_VERSION,
			'requires_api'    => self::SDK_API,
			'health_callback' => array( __CLASS__, 'extension_health' ),
		);

		return $extensions;
	}

	public static function extension_health(): array {
		if ( ! self::identity_available() ) {
			return array(
				'status'  => 'warning',
				'message' => __( 'Identity Pro is not active; Builder is using WordPress/WooCommerce fallback authentication.', 'delicat-builder-v9' ),
			);
		}

		if ( ! self::compatible() ) {
			return array(
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %s: Identity Pro version. */
					__( 'Identity Pro %s detected; update to a compatible Identity SDK release.', 'delicat-builder-v9' ),
					self::identity_version()
				),
			);
		}

		$lazy = self::safe_identity_bool( 'DIP_Native_Auth', 'lazy_modal_supported' );
		return array(
			'status'  => 'healthy',
			'message' => $lazy
				? __( 'Builder security authority is synchronized with Identity Pro; public login UI is pre-mounted for instant opening.', 'delicat-builder-v9' )
				: __( 'Builder login/security is synchronized with Delicat Identity Pro. Identity 6.9.8+ is recommended for lazy public login UI.', 'delicat-builder-v9' ),
		);
	}

	public static function register_admin_pages( $pages ): array {
		$pages = is_array( $pages ) ? $pages : array();

		if ( ! self::authority_enabled() || empty( self::settings()['admin_guard_bridge'] ) ) {
			return $pages;
		}

		// Page Builder editor is intentionally excluded because it uses edit_pages
		// and edit_post rather than global administrator permissions.
		$builder_pages = array(
			'delicat-builder-v9',
			'delicat-builder-v9-shell',
			'delicat-builder-v9-woo',
			'delicat-builder-v9-purchase',
			'delicat-builder-v9-performance',
			'delicat-builder-v9-security',
			'delicat-builder-v9-production',
			'delicat-builder-v9-release',
			'delicat-builder-v9-design',
			'delicat-builder-v9-integrated',
			'delicat-notifications',
			'delicat-direct-swatches',
		);

		return array_values( array_unique( array_merge( $pages, $builder_pages ) ) );
	}

	public static function register_admin_actions( $actions ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( ! self::authority_enabled() || empty( self::settings()['admin_guard_bridge'] ) ) {
			return $actions;
		}

		$builder_actions = array(
			'delicat_builder_v9_purge',
			'delicat_builder_v9_compile_all',
			'delicat_builder_v9_cleanup_compiled',
			'delicat_builder_v9_security_sync_save',
			'delicat_builder_v9_production_save',
			'delicat_builder_v9_safe_mode',
			'delicat_builder_v9_integrity_scan',
			'delicat_builder_v9_export_config',
			'delicat_builder_v9_snapshot_import',
			'delicat_builder_v9_restore_pre_rc',
			'delicat_builder_v9_undo_restore',
			'delicat_builder_v9_rc2_self_test',
			'delicat_builder_v9_rc2_manual_gate',
			'delicat_builder_v9_export_release_evidence',
			'delicat_builder_v9_unified_save',
			'delicat_builder_v9_notifications_save',
			'delicat_builder_v9_notifications_publish',
		);

		return array_values( array_unique( array_merge( $actions, $builder_actions ) ) );
	}

	private static function safe_identity_bool( string $class, string $method ): bool {
		if ( ! self::identity_static_callable( $class, $method ) ) {
			return false;
		}
		try {
			return (bool) call_user_func( array( $class, $method ) );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	public static function can_manage_global_security(): bool {
		if ( self::authority_enabled() && self::identity_static_callable( 'DIP_Access_Guard', 'can_manage_security' ) ) {
			try {
				return (bool) DIP_Access_Guard::can_manage_security();
			} catch ( Throwable $error ) {
				// Fall through to WordPress capability authority.
			}
		}

		return current_user_can( 'manage_options' );
	}

	public static function require_recent_for_security_change( string $area = 'security_sync' ): void {
		if (
			! self::authority_enabled()
			|| empty( self::settings()['reauth_security_save'] )
			|| ! class_exists( 'DIP_Reauth' )
		) {
			return;
		}

		if ( ! self::identity_static_callable( 'DIP_Reauth', 'is_recent' ) ) {
			return;
		}

		try {
			$recent = (bool) DIP_Reauth::is_recent();
		} catch ( Throwable $error ) {
			return;
		}

		if ( ! $recent ) {
			self::audit(
				'builder_security_reauth_required',
				'notice',
				array( 'area' => substr( sanitize_key( $area ), 0, 40 ) )
			);
			if ( self::identity_static_callable( 'DIP_Reauth', 'require_recent_or_redirect' ) ) {
				try {
					DIP_Reauth::require_recent_or_redirect();
				} catch ( Throwable $error ) {
					// Do not fatal; WordPress capability checks still protect the action.
				}
			}
		}
	}

	public static function audit( string $event, string $severity = 'info', array $context = array(), int $user_id = 0 ): void {
		if (
			empty( self::settings()['audit_bridge'] )
			|| ! self::authority_enabled()
			|| ! class_exists( 'DIP_Audit' )
		) {
			return;
		}

		$safe = array();
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! $key || in_array( $key, array( 'token', 'secret', 'password', 'email', 'ip', 'nonce' ), true ) ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$safe[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		if ( ! self::identity_static_callable( 'DIP_Audit', 'record' ) ) {
			return;
		}

		try {
			DIP_Audit::record(
				substr( sanitize_key( $event ), 0, 64 ),
				in_array( $severity, array( 'info', 'notice', 'warning', 'critical' ), true ) ? $severity : 'info',
				absint( $user_id ?: get_current_user_id() ),
				$safe
			);
		} catch ( Throwable $error ) {
			// Audit bridge failure must never interrupt the requested WordPress action.
		}
	}

	public static function sdk_ready( $api_version, $extensions ): void {
		if ( ! self::identity_available() ) {
			return;
		}

		// Do not log every frontend request. The hook is used only as a stable
		// integration point; health is reported through DIP_SDK::health().
	}

	public static function security_health(): array {
		$checks = array(
			'identity_detected' => array(
				'label' => __( 'Delicat Identity Pro', 'delicat-builder-v9' ),
				'ok'    => self::identity_available(),
				'text'  => self::identity_available()
					? sprintf( 'v%s', self::identity_version() )
					: __( 'Not detected', 'delicat-builder-v9' ),
			),
			'sdk' => array(
				'label' => __( 'Identity SDK', 'delicat-builder-v9' ),
				'ok'    => class_exists( 'DIP_SDK' ),
				'text'  => class_exists( 'DIP_SDK' ) && defined( 'DIP_SDK::API_VERSION' )
					? 'API ' . sanitize_text_field( (string) DIP_SDK::API_VERSION )
					: __( 'Unavailable', 'delicat-builder-v9' ),
			),
			'access_guard' => array(
				'label' => __( 'Access Guard', 'delicat-builder-v9' ),
				'ok'    => class_exists( 'DIP_Access_Guard' ),
				'text'  => class_exists( 'DIP_Access_Guard' ) ? __( 'Synchronized', 'delicat-builder-v9' ) : __( 'Fallback only', 'delicat-builder-v9' ),
			),
			'audit' => array(
				'label' => __( 'Security audit log', 'delicat-builder-v9' ),
				'ok'    => class_exists( 'DIP_Audit' ),
				'text'  => class_exists( 'DIP_Audit' ) ? __( 'Identity audit authority', 'delicat-builder-v9' ) : __( 'Unavailable', 'delicat-builder-v9' ),
			),
			'reauth' => array(
				'label' => __( 'Sensitive-action reauthentication', 'delicat-builder-v9' ),
				'ok'    => class_exists( 'DIP_Reauth' ),
				'text'  => class_exists( 'DIP_Reauth' ) ? __( 'Identity reauth authority', 'delicat-builder-v9' ) : __( 'Unavailable', 'delicat-builder-v9' ),
			),
			'lazy_login' => array(
				'label' => __( 'Interaction-mounted login', 'delicat-builder-v9' ),
				'ok'    => self::safe_identity_bool( 'DIP_Native_Auth', 'lazy_modal_supported' ),
				'text'  => self::safe_identity_bool( 'DIP_Native_Auth', 'lazy_modal_supported' )
					? __( 'Identity lazy secure modal', 'delicat-builder-v9' )
					: __( 'Use Identity 6.9.8+ for best public-page performance', 'delicat-builder-v9' ),
			),
			'two_factor' => array(
				'label' => __( 'TOTP / recovery codes', 'delicat-builder-v9' ),
				'ok'    => class_exists( 'DIP_Two_Factor' ),
				'text'  => class_exists( 'DIP_Two_Factor' ) ? __( 'Identity Pro', 'delicat-builder-v9' ) : __( 'Unavailable', 'delicat-builder-v9' ),
			),
			'passkeys' => array(
				'label' => __( 'Passkeys / WebAuthn', 'delicat-builder-v9' ),
				'ok'    => self::safe_identity_bool( 'DIP_Passkeys', 'available' ),
				'text'  => self::safe_identity_bool( 'DIP_Passkeys', 'available' )
					? __( 'Available', 'delicat-builder-v9' )
					: __( 'Not currently available', 'delicat-builder-v9' ),
			),
			'admin_google' => array(
				'label' => __( 'Admin Google Secure Mode', 'delicat-builder-v9' ),
				'ok'    => self::safe_identity_bool( 'DIP_Privileged_Social', 'secure_mode_enabled' ),
				'text'  => self::safe_identity_bool( 'DIP_Privileged_Social', 'secure_mode_enabled' )
					? __( 'Enforced by Identity Pro', 'delicat-builder-v9' )
					: __( 'Review Identity policy', 'delicat-builder-v9' ),
			),
		);

		if ( self::identity_static_callable( 'DIP_Security_Architecture', 'health' ) ) {
			try {
				$identity_health = DIP_Security_Architecture::health();
			} catch ( Throwable $error ) {
				$identity_health = array();
			}
			if ( is_array( $identity_health ) && ! empty( $identity_health ) ) {
				$checks['identity_architecture'] = array(
					'label' => __( 'Identity security architecture', 'delicat-builder-v9' ),
					'ok'    => absint( $identity_health['score'] ?? 0 ) >= 80,
					'text'  => sprintf(
						/* translators: %d: Identity security score. */
						__( '%d%% Identity health score', 'delicat-builder-v9' ),
						absint( $identity_health['score'] ?? 0 )
					),
				);
			}
		}

		return $checks;
	}
}
