<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Delicat Builder V9 notification hub.
 *
 * Preserves the legacy Builder data contract / Android REST aliases while
 * keeping the notification shell lightweight. RC37.9 restores the reliable
 * v12.8.5 pattern by embedding a privacy-filtered server snapshot for first
 * open, while REST/AJAX remain available for freshness and cross-device state.
 */
final class Delicat_Builder_V9_Notifications {
	public const HISTORY_OPTION       = 'dsb_header_notifications';
	public const ENABLED_OPTION       = 'dsb_header_notifications_enabled';
	public const PERSONAL_OPTION      = 'dsb_bell_personal';
	public const DEVICES_OPTION       = 'dsb_notification_devices';
	public const STATS_OPTION         = 'dsb_notification_stats';
	public const APP_KEY_OPTION       = 'dsb_notification_app_key';
	public const LEGACY_SERVICE       = 'dsb_notification_service_account';
	public const LEGACY_FCM_KEY       = 'dsb_notification_fcm_server_key';
	public const ENCRYPTED_SERVICE    = 'delicat_builder_v9_notification_service_account_enc';
	public const PRIVACY_MIGRATION    = 'dsb_notification_privacy_migrated';
	public const REST_NAMESPACE       = 'delicat/v1';
	public const UI_REST_NAMESPACE    = 'delicat-builder-v9/v1';
	private const TOKEN_TRANSIENT     = 'dbv9_notification_google_access_token';

	private static bool $booted = false;
	private static bool $event_hooks = false;
	private static bool $legacy_bridge = false;
	private static bool $assets_registered = false;
	private static bool $shortcode_assets = false;
	private static ?array $items_cache = null;

	public static function boot( bool $attach_event_hooks = true ): void {
		if ( ! $attach_event_hooks ) { self::$legacy_bridge = true; }
		if ( ! self::$booted ) {
			self::$booted = true;

			add_action( 'init', array( __CLASS__, 'migrate_privacy' ), 5 );
			add_action( 'init', array( __CLASS__, 'migrate_service_secret' ), 6 );
			// RC37.6: V9 owns these two notification shortcodes regardless of which
			// historical Builder callback was registered first/last. The shortcode
			// pre-filter prevents legacy notification markup from ever reaching HTML.
			add_action( 'init', array( __CLASS__, 'register_shortcodes' ), PHP_INT_MAX );
			add_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'intercept_notification_shortcode' ), PHP_INT_MAX, 4 );
			add_action( 'wp_head', array( __CLASS__, 'critical_frontend_guard' ), 1 );
			add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
			if ( class_exists( 'Delicat_Builder_V9_Web_Push', false ) ) { Delicat_Builder_V9_Web_Push::boot(); }

			// RC37.8: same-origin AJAX fallback for hosts/security layers that block
			// or rewrite wp-json. Public list remains read-only; mutations require
			// an authenticated WordPress session plus a dedicated CSRF nonce.
			add_action( 'wp_ajax_delicat_builder_v9_notifications_list', array( __CLASS__, 'ajax_list' ) );
			add_action( 'wp_ajax_nopriv_delicat_builder_v9_notifications_list', array( __CLASS__, 'ajax_list' ) );
			add_action( 'wp_ajax_delicat_builder_v9_notifications_read', array( __CLASS__, 'ajax_read' ) );
			add_action( 'wp_ajax_delicat_builder_v9_notifications_dismiss', array( __CLASS__, 'ajax_dismiss' ) );

			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_shell_assets' ), 28 );
			add_action( 'delicat_builder_v9_deliver_notification', array( __CLASS__, 'deliver_queued' ), 10, 1 );

			if ( is_admin() ) {
				add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 39 );
				add_action( 'admin_post_delicat_builder_v9_notifications_save', array( __CLASS__, 'admin_save_settings' ) );
				add_action( 'admin_post_delicat_builder_v9_notifications_publish', array( __CLASS__, 'admin_publish' ) );
			}
		}

		if ( $attach_event_hooks && ! self::$event_hooks ) {
			self::$event_hooks = true;
			add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'order_processing' ) );
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'order_completed' ) );
			add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'order_cancelled' ) );
			add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'order_refunded' ) );
		}
	}

	public static function register_assets(): void {
		if ( self::$assets_registered ) { return; }
		self::$assets_registered = true;
		wp_register_style(
			'delicat-builder-v9-notifications',
			DELICAT_BUILDER_V9_URL . 'assets/css/notifications.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
		wp_register_script(
			'delicat-builder-v9-notifications',
			DELICAT_BUILDER_V9_URL . 'assets/js/notifications.js',
			array(),
			DELICAT_BUILDER_V9_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	public static function maybe_enqueue_shell_assets(): void {
		if ( is_admin() || ! class_exists( 'Delicat_Builder_V9_Shell', false ) || ! Delicat_Builder_V9_Shell::header_expected() ) { return; }
		$settings = Delicat_Builder_V9_Shell::settings();
		if ( empty( $settings['show_notifications'] ) ) { return; }
		self::register_assets();
		wp_enqueue_style( 'delicat-builder-v9-notifications' );
		wp_enqueue_script( 'delicat-builder-v9-notifications' );
	}

	public static function register_shortcodes(): void {
		remove_shortcode( 'delicat_header_notifications' );
		remove_shortcode( 'delicat_notifications' );
		add_shortcode( 'delicat_header_notifications', array( __CLASS__, 'render_bell' ) );
		add_shortcode( 'delicat_notifications', array( __CLASS__, 'render_bell' ) );
	}

	/**
	 * Prevent any historical Builder callback from rendering notification UI.
	 * Returning a string from pre_do_shortcode_tag bypasses the registered
	 * shortcode callback entirely.
	 */
	public static function intercept_notification_shortcode( $return, $tag, $attr = array(), $m = array() ) {
		unset( $attr, $m );
		if ( in_array( $tag, array( 'delicat_header_notifications', 'delicat_notifications' ), true ) ) {
			return self::render_bell();
		}
		return $return;
	}

	/**
	 * First-paint guard. This intentionally contains no theme-dependent rules.
	 * Legacy UI is never allowed to flash above the hero, even if its stylesheet
	 * loads after V9 or an optimization plugin reorders CSS.
	 */
	public static function critical_frontend_guard(): void {
		if ( is_admin() ) { return; }
		echo '<style id="dbv9-notification-critical">'
			. '.dsb541-notifications,.dsb541-panel,.dsb541-panel-portal,[data-dsb-notifications]{display:none!important;visibility:hidden!important;opacity:0!important;pointer-events:none!important}'
			. '.dbv9-notifications__panel[hidden],.dbv9-notifications__count[hidden],.dbv9-notifications__read[hidden],.dbv9-notifications__push-button[hidden]{display:none!important}'
			. '</style>';
	}

	public static function defaults(): array {
		return array(
			array(
				'id'       => 'welcome',
				'title'    => 'Bienvenue chez Delicat Store',
				'message'  => 'Recevez nos promotions, commandes et mises à jour ici.',
				'category' => 'general',
				'code'     => '',
				'link'     => home_url( '/' ),
				'created'  => time(),
				'enabled'  => 1,
				'source'   => 'admin',
				'audience' => 'broadcast',
				'bell'     => 1,
				'user_id'  => 0,
			),
		);
	}

	private static function category( $value ): string {
		$map = array( 'order' => 'orders', 'promo' => 'promotions', 'alert' => 'security', 'info' => 'general' );
		$value = sanitize_key( $value ?: 'general' );
		if ( isset( $map[ $value ] ) ) { $value = $map[ $value ]; }
		return in_array( $value, array( 'orders', 'wallet', 'chat', 'promotions', 'security', 'general' ), true ) ? $value : 'general';
	}

	private static function icon( string $category ): string {
		$icons = array( 'orders' => '📦', 'wallet' => '💰', 'chat' => '💬', 'promotions' => '🎁', 'security' => '🛡️', 'general' => '🔔' );
		return $icons[ $category ] ?? '🔔';
	}

	public static function normalize_item( $item ): array {
		if ( ! is_array( $item ) ) { return array(); }
		$item = wp_parse_args(
			$item,
			array(
				'id' => '', 'title' => '', 'message' => '', 'category' => 'general', 'code' => '', 'link' => '',
				'urgent' => 0, 'created' => time(), 'enabled' => 1, 'user_id' => 0, 'source' => '', 'audience' => '', 'bell' => null,
			)
		);
		$item['id']       = sanitize_key( (string) $item['id'] );
		$item['title']    = sanitize_text_field( (string) $item['title'] );
		$item['message']  = sanitize_textarea_field( (string) $item['message'] );
		$item['category'] = self::category( $item['category'] ?? 'general' );
		$item['code']     = sanitize_text_field( (string) ( $item['code'] ?? '' ) );
		$item['user_id']  = absint( $item['user_id'] );
		$item['created']  = absint( $item['created'] ?: time() );
		$item['urgent']   = empty( $item['urgent'] ) ? 0 : 1;

		$sources   = array( 'admin', 'system', 'chat' );
		$audiences = array( 'broadcast', 'user', 'private' );
		if ( ! in_array( $item['source'], $sources, true ) ) {
			if ( 0 === strpos( (string) $item['id'], 'chat_' ) || 'chat' === $item['category'] ) { $item['source'] = 'chat'; }
			elseif ( $item['user_id'] > 0 ) { $item['source'] = 'system'; }
			else { $item['source'] = 'admin'; }
		}
		if ( ! in_array( $item['audience'], $audiences, true ) ) {
			if ( 'chat' === $item['source'] ) { $item['audience'] = 'private'; }
			elseif ( $item['user_id'] > 0 ) { $item['audience'] = 'user'; }
			else { $item['audience'] = 'broadcast'; }
		}

		// Privacy invariants. These are deliberately not filterable.
		if ( 'chat' === $item['source'] ) { $item['audience'] = 'private'; $item['bell'] = 0; }
		if ( 'private' === $item['audience'] ) { $item['bell'] = 0; }
		if ( $item['user_id'] > 0 && 'broadcast' === $item['audience'] ) { $item['audience'] = 'user'; }
		if ( null === $item['bell'] ) { $item['bell'] = 1; }
		$item['bell']    = empty( $item['bell'] ) ? 0 : 1;
		$item['enabled'] = empty( $item['enabled'] ) ? 0 : 1;
		$item['link']    = self::clean_link( $item['link'] ?? '' );
		return $item;
	}

	public static function items(): array {
		if ( null !== self::$items_cache ) { return self::$items_cache; }
		$items = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $items ) || ! $items ) { $items = self::defaults(); }
		$items = array_values( array_filter( array_map( array( __CLASS__, 'normalize_item' ), $items ) ) );
		usort( $items, static function ( $a, $b ) { return (int) ( $b['created'] ?? 0 ) <=> (int) ( $a['created'] ?? 0 ); } );
		self::$items_cache = array_slice( $items, 0, 100 );
		return self::$items_cache;
	}

	public static function dismissed( int $user_id = 0 ): array {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) { return array(); }
		$value = get_user_meta( $user_id, 'dsb_notification_dismissed', true );
		return is_array( $value ) ? array_values( array_filter( array_map( 'sanitize_key', $value ) ) ) : array();
	}

	public static function bell_items( ?int $user_id = null ): array {
		$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
		$allow_personal = (int) get_option( self::PERSONAL_OPTION, 1 );
		$dismissed = $user_id ? self::dismissed( $user_id ) : array();
		$out = array();
		foreach ( self::items() as $item ) {
			if ( empty( $item['enabled'] ) || empty( $item['bell'] ) || 'private' === $item['audience'] ) { continue; }
			if ( 'user' === $item['audience'] ) {
				if ( ! $allow_personal || ! $user_id || absint( $item['user_id'] ) !== $user_id ) { continue; }
			} elseif ( 'admin' !== $item['source'] ) {
				continue;
			}
			if ( $item['id'] && in_array( $item['id'], $dismissed, true ) ) { continue; }
			$out[] = $item;
		}
		return $out;
	}

	public static function migrate_privacy(): void {
		$target = '7.8.1-v9';
		if ( $target === get_option( self::PRIVACY_MIGRATION ) ) { return; }
		$stored = get_option( self::HISTORY_OPTION, array() );
		if ( is_array( $stored ) && $stored ) {
			$clean = array();
			foreach ( $stored as $item ) {
				$normalized = self::normalize_item( $item );
				if ( ! $normalized ) { continue; }

				// Preserve rollback/history data, but enforce the privacy boundary in storage
				// as well as at render time. Chat/private records can never enter the bell.
				if ( 'chat' === ( $normalized['source'] ?? '' ) || 'private' === ( $normalized['audience'] ?? '' ) ) {
					$normalized['audience'] = 'private';
					$normalized['bell']     = 0;
				}
				$clean[] = $normalized;
			}
			update_option( self::HISTORY_OPTION, array_slice( $clean, 0, 100 ), false );
			self::$items_cache = null;
		}
		update_option( self::PRIVACY_MIGRATION, $target, false );
	}


	/**
	 * Upgrade any historical/plain Firebase service-account JSON to Identity Pro's
	 * authenticated encryption whenever Identity is available. A failed upgrade
	 * is non-destructive; the existing secret remains server-side only.
	 */
	public static function migrate_service_secret(): void {
		if ( ! class_exists( 'DIP_Crypto' ) || ! is_callable( array( 'DIP_Crypto', 'is_available' ) ) || ! DIP_Crypto::is_available() ) { return; }

		$current = trim( (string) get_option( self::ENCRYPTED_SERVICE, '' ) );
		$legacy  = trim( (string) get_option( self::LEGACY_SERVICE, '' ) );

		// If the current value is already decryptable, it is authoritative. Remove
		// any stale plaintext legacy copy instead of ever overwriting the newer key.
		if ( '' !== $current && is_callable( array( 'DIP_Crypto', 'decrypt' ) ) ) {
			$decrypted = (string) DIP_Crypto::decrypt( $current );
			if ( '' !== $decrypted && is_array( json_decode( $decrypted, true ) ) ) {
				if ( '' !== $legacy ) { delete_option( self::LEGACY_SERVICE ); }
				return;
			}
		}

		// Historical RCs could place raw JSON in the encrypted option as a fallback.
		// Prefer that current value; only fall back to the older legacy option when
		// there is no valid current JSON.
		$plain = is_array( json_decode( $current, true ) ) ? $current : '';
		if ( '' === $plain && is_array( json_decode( $legacy, true ) ) ) { $plain = $legacy; }
		if ( '' === $plain ) { return; }

		$encrypted = (string) DIP_Crypto::encrypt( $plain );
		if ( '' === $encrypted ) { return; }
		update_option( self::ENCRYPTED_SERVICE, $encrypted, false );
		delete_option( self::LEGACY_SERVICE );
		delete_transient( self::TOKEN_TRANSIENT );
	}

	public static function clean_link( $url ): string {
		$url = esc_url_raw( (string) $url );
		if ( ! $url ) { return ''; }
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		}
		return ( $host && hash_equals( $home_host, $host ) && wp_http_validate_url( $url ) ) ? $url : '';
	}

	public static function app_key(): string {
		$key = (string) get_option( self::APP_KEY_OPTION, '' );
		if ( strlen( $key ) < 24 ) {
			$key = wp_generate_password( 40, false, false );
			update_option( self::APP_KEY_OPTION, $key, false );
		}
		return $key;
	}

	public static function rest_auth( WP_REST_Request $request ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) { return true; }
		$given = (string) $request->get_header( 'x-delicat-token' );
		$key   = self::app_key();
		return '' !== $given && '' !== $key && hash_equals( $key, $given );
	}

	public static function devices(): array {
		$value = get_option( self::DEVICES_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	private static function save_devices( array $devices ): void {
		update_option( self::DEVICES_OPTION, array_slice( $devices, -200, null, true ), false );
	}

	public static function stats(): array {
		$value = get_option( self::STATS_OPTION, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), array( 'queued' => 0, 'sent' => 0, 'failed' => 0, 'web_sent' => 0, 'web_failed' => 0, 'opened' => 0, 'read' => 0 ) );
	}

	private static function bump( string $key, int $amount = 1 ): void {
		$stats = self::stats();
		if ( ! array_key_exists( $key, $stats ) ) { return; }
		$stats[ $key ] = max( 0, (int) $stats[ $key ] + $amount );
		update_option( self::STATS_OPTION, $stats, false );
	}

	public static function user_state( int $user_id = 0 ): array {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) { return array(); }
		$value = get_user_meta( $user_id, 'dsb_notification_state', true );
		return is_array( $value ) ? $value : array();
	}

	private static function set_state( array $ids, string $state = 'read', int $user_id = 0 ): array {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) { return array(); }
		$value = self::user_state( $user_id );
		$state = in_array( $state, array( 'read', 'archived' ), true ) ? $state : 'read';
		$now   = time();
		foreach ( $ids as $id ) {
			$id = sanitize_key( (string) $id );
			if ( $id ) { $value[ $id ] = array( 'state' => $state, 'updated' => $now ); }
		}
		$value = array_slice( $value, -500, null, true );
		update_user_meta( $user_id, 'dsb_notification_state', $value );
		return $value;
	}

	private static function dismiss( array $ids, int $user_id = 0, bool $restore = false ): array {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) { return array(); }
		$current = self::dismissed( $user_id );
		$ids = array_values( array_filter( array_map( 'sanitize_key', $ids ) ) );
		if ( $restore ) {
			$current = array_values( array_diff( $current, $ids ) );
		} else {
			$current = array_values( array_unique( array_merge( $current, $ids ) ) );
			$current = array_slice( $current, -500 );
		}
		update_user_meta( $user_id, 'dsb_notification_dismissed', $current );
		return $current;
	}

	public static function register_rest_routes(): void {
		// V9's storefront bell always uses its own namespace so it cannot race
		// the historical Builder's /delicat/v1 registration during migration.
		register_rest_route(
			self::UI_REST_NAMESPACE,
			'/notifications',
			array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'rest_list' ) )
		);
		register_rest_route(
			self::UI_REST_NAMESPACE,
			'/notifications/read',
			array( 'methods' => 'POST', 'permission_callback' => static function () { return is_user_logged_in(); }, 'callback' => array( __CLASS__, 'rest_read' ) )
		);
		register_rest_route(
			self::UI_REST_NAMESPACE,
			'/notifications/dismiss',
			array( 'methods' => 'POST', 'permission_callback' => static function () { return is_user_logged_in(); }, 'callback' => array( __CLASS__, 'rest_dismiss' ) )
		);

		if ( self::$legacy_bridge ) { return; }

		// Full-owner mode keeps the historical Android/app aliases intact.
		register_rest_route( self::REST_NAMESPACE, '/notifications/device', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'rest_auth' ), 'callback' => array( __CLASS__, 'rest_device' ) ) );
		register_rest_route( self::REST_NAMESPACE, '/notifications', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'rest_list' ) ) );
		register_rest_route( self::REST_NAMESPACE, '/notifications/read', array( 'methods' => 'POST', 'permission_callback' => static function () { return is_user_logged_in(); }, 'callback' => array( __CLASS__, 'rest_read' ) ) );
		register_rest_route( self::REST_NAMESPACE, '/notifications/dismiss', array( 'methods' => 'POST', 'permission_callback' => static function () { return is_user_logged_in(); }, 'callback' => array( __CLASS__, 'rest_dismiss' ) ) );
		register_rest_route( self::REST_NAMESPACE, '/notifications/state', array( 'methods' => array( 'GET', 'POST' ), 'permission_callback' => array( __CLASS__, 'rest_auth' ), 'callback' => array( __CLASS__, 'rest_state' ) ) );
		register_rest_route( self::REST_NAMESPACE, '/notifications/click', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'rest_auth' ), 'callback' => array( __CLASS__, 'rest_click' ) ) );
	}

	public static function rest_device( WP_REST_Request $request ) {
		$token = sanitize_text_field( (string) $request->get_param( 'fcm_token' ) );
		if ( strlen( $token ) < 40 || strlen( $token ) > 4096 ) {
			return new WP_Error( 'bad_token', 'Token FCM invalide', array( 'status' => 400 ) );
		}
		$devices = self::devices();
		$id = hash( 'sha256', $token );
		$devices[ $id ] = array(
			'token' => $token,
			'name' => substr( sanitize_text_field( (string) $request->get_param( 'device_name' ) ), 0, 100 ),
			'platform' => 'android',
			'app_version' => substr( sanitize_text_field( (string) $request->get_param( 'app_version' ) ), 0, 40 ),
			'os_version' => substr( sanitize_text_field( (string) $request->get_param( 'os_version' ) ), 0, 40 ),
			'language' => substr( sanitize_key( (string) $request->get_param( 'language' ) ), 0, 12 ),
			'permission' => substr( sanitize_key( (string) $request->get_param( 'permission' ) ), 0, 20 ),
			'user_id' => get_current_user_id(),
			'agent' => 1,
			'updated' => time(),
			'last_error' => '',
		);
		self::save_devices( $devices );
		return rest_ensure_response( array( 'ok' => true, 'device_id' => $id, 'notifications' => count( self::items() ) ) );
	}

	private static function no_store_response( $data ) {
		$response = rest_ensure_response( $data );
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Vary', 'Cookie' );
		}
		return $response;
	}

	/** Guest lists contain broadcast-safe admin rows only and may be cached briefly. */
	private static function list_response( array $data ) {
		if ( is_user_logged_in() ) {
			return self::no_store_response( $data );
		}
		$response = rest_ensure_response( $data );
		if ( $response instanceof WP_REST_Response ) {
			$etag = '"dbv9-' . substr( hash( 'sha256', (string) wp_json_encode( $data ) ), 0, 20 ) . '"';
			$response->header( 'Cache-Control', 'public, max-age=15, stale-while-revalidate=30' );
			$response->header( 'ETag', $etag );
			$response->header( 'Vary', 'Accept-Encoding' );
			$incoming = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
			if ( '' !== $incoming && hash_equals( $etag, $incoming ) ) {
				$response->set_status( 304 );
				$response->set_data( null );
			}
		}
		return $response;
	}

	/** RC33: unread count for the session store (same rule as the bell). */
	public static function unread_count(): int {
		if ( ! is_user_logged_in() ) {
			return 0;
		}
		$state  = self::user_state();
		$unread = 0;
		foreach ( self::bell_items() as $item ) {
			$id = (string) ( $item['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			if ( empty( $state[ $id ] ) || ! in_array( $state[ $id ]['state'] ?? '', array( 'read', 'archived' ), true ) ) {
				$unread++;
			}
		}
		return $unread;
	}

	private static function list_payload(): array {
		$state = is_user_logged_in() ? self::user_state() : array();
		$out = array();
		foreach ( self::bell_items() as $item ) {
			$id = $item['id'];
			$out[] = array(
				'id'       => $id,
				'title'    => sanitize_text_field( $item['title'] ),
				'body'     => sanitize_textarea_field( $item['message'] ),
				'category' => self::category( $item['category'] ),
				'icon'     => self::icon( self::category( $item['category'] ) ),
				'url'      => self::clean_link( $item['link'] ),
				'created'  => absint( $item['created'] ),
				'code'     => sanitize_text_field( (string) ( $item['code'] ?? '' ) ),
				'read'     => isset( $state[ $id ] ) && in_array( $state[ $id ]['state'] ?? '', array( 'read', 'archived' ), true ) ? 1 : 0,
			);
			if ( count( $out ) >= 40 ) { break; }
		}
		return $out;
	}

	public static function rest_list() {
		return self::list_response( self::list_payload() );
	}

	/**
	 * Storefront transport fallback. The list endpoint is intentionally read-only.
	 * For logged-in users the payload is derived solely from the current WP session.
	 */
	public static function ajax_list(): void {
		if ( is_user_logged_in() ) {
			nocache_headers();
		} elseif ( ! headers_sent() ) {
			header( 'Cache-Control: public, max-age=15, stale-while-revalidate=30', true );
			header( 'Vary: Accept-Encoding', true );
			if ( function_exists( 'header_remove' ) ) { header_remove( 'Pragma' ); }
		}
		wp_send_json_success( self::list_payload(), 200 );
	}

	private static function ajax_mutation_allowed(): bool {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'authentication_required' ), 401 );
			return false;
		}
		if ( ! check_ajax_referer( 'dbv9_notifications', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'invalid_nonce' ), 403 );
			return false;
		}
		return true;
	}

	public static function ajax_read(): void {
		if ( ! self::ajax_mutation_allowed() ) { return; }
		$raw = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
		$ids = array_slice( array_values( array_filter( array_map( 'sanitize_key', $raw ) ) ), 0, 100 );
		$state = self::set_state( $ids, 'read' );
		self::bump( 'read', count( $ids ) );
		nocache_headers();
		wp_send_json_success( array( 'ok' => true, 'state' => $state ), 200 );
	}

	public static function ajax_dismiss(): void {
		if ( ! self::ajax_mutation_allowed() ) { return; }
		$raw = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
		$ids = array_slice( array_values( array_filter( array_map( 'sanitize_key', $raw ) ) ), 0, 100 );
		$list = self::dismiss( $ids );
		nocache_headers();
		wp_send_json_success( array( 'ok' => true, 'dismissed' => $list ), 200 );
	}

	public static function rest_read( WP_REST_Request $request ) {
		$ids = array_map( 'sanitize_key', (array) $request->get_param( 'ids' ) );
		$ids = array_slice( array_values( array_filter( $ids ) ), 0, 100 );
		$state = self::set_state( $ids, 'read' );
		self::bump( 'read', count( $ids ) );
		return self::no_store_response( array( 'ok' => true, 'state' => $state ) );
	}

	public static function rest_dismiss( WP_REST_Request $request ) {
		$ids = array_slice( array_values( array_filter( array_map( 'sanitize_key', (array) $request->get_param( 'ids' ) ) ) ), 0, 100 );
		$list = self::dismiss( $ids, 0, (bool) $request->get_param( 'restore' ) );
		return self::no_store_response( array( 'ok' => true, 'dismissed' => $list ) );
	}

	public static function rest_state( WP_REST_Request $request ) {
		$current_user_id = get_current_user_id();
		$requested_id    = absint( $request->get_param( 'user_id' ) );
		$is_admin        = current_user_can( 'manage_woocommerce' );
		$user_id         = $is_admin && $requested_id ? $requested_id : $current_user_id;

		// App-key authentication may synchronize device-level state, but it is
		// never allowed to select/mutate another WordPress customer's user-meta.
		if ( ! $is_admin && $requested_id && $requested_id !== $current_user_id ) {
			return new WP_Error( 'forbidden_user_state', 'User notification state is owner-only.', array( 'status' => 403 ) );
		}

		if ( 'POST' === $request->get_method() && $user_id ) {
			self::set_state( array_slice( (array) $request->get_param( 'ids' ), 0, 100 ), sanitize_key( (string) $request->get_param( 'state' ) ) ?: 'read', $user_id );
		}
		return self::no_store_response( array( 'ok' => true, 'state' => $user_id ? self::user_state( $user_id ) : array() ) );
	}

	public static function rest_click() {
		self::bump( 'opened' );
		return self::no_store_response( array( 'ok' => true ) );
	}

	public static function render_bell(): string {
		if ( ! (int) get_option( self::ENABLED_OPTION, 1 ) ) { return ''; }
		self::register_assets();
		wp_enqueue_style( 'delicat-builder-v9-notifications' );
		wp_enqueue_script( 'delicat-builder-v9-notifications' );
		self::$shortcode_assets = true;

		$visible = self::bell_items();
		$state = is_user_logged_in() ? self::user_state() : array();
		$unread = 0;
		foreach ( $visible as $item ) {
			if ( empty( $state[ $item['id'] ] ) || ! in_array( $state[ $item['id'] ]['state'] ?? '', array( 'read', 'archived' ), true ) ) { $unread++; }
		}

		$panel_id = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'dbv9-notification-panel-' ) : 'dbv9-notification-panel-' . wp_rand( 1000, 999999 );
		// RC37.9: v12.8.5-style server snapshot. This removes the storefront's
		// hard dependency on a second HTTP request just to open the bell. The
		// snapshot is already privacy-filtered by bell_items()/list_payload().
		// Logged-in pages are normally excluded from full-page caches; public
		// guest snapshots contain broadcast-safe rows only. Keep the snapshot
		// compact and let REST/AJAX refresh it after opening.
		$initial = array_slice( self::list_payload(), 0, 20 );
		$config = array(
			'list'      => rest_url( self::UI_REST_NAMESPACE . '/notifications' ),
			'read'      => rest_url( self::UI_REST_NAMESPACE . '/notifications/read' ),
			'dismiss'   => rest_url( self::UI_REST_NAMESPACE . '/notifications/dismiss' ),
			'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'ajaxNonce' => is_user_logged_in() ? wp_create_nonce( 'dbv9_notifications' ) : '',
			'user'      => get_current_user_id(),
			'panelId'   => $panel_id,
			'initial'   => $initial,
			'push'      => class_exists( 'Delicat_Builder_V9_Web_Push', false )
				? Delicat_Builder_V9_Web_Push::client_config()
				: ( is_callable( array( 'Delicat_Builder_V9_Unified_Modules', 'web_push_client_config' ) ) ? Delicat_Builder_V9_Unified_Modules::web_push_client_config() : array( 'enabled' => 0, 'available' => 0 ) ),
		);

		// The panel itself is still created after a real click to prevent theme
		// flashes. Only a compact JSON snapshot rides with the bell markup.
		return '<div class="dbv9-notifications" data-dbv9-notifications data-config="' . esc_attr( wp_json_encode( $config ) ) . '">' .
			'<button type="button" class="dbv9-notifications__bell' . ( $unread ? ' has-unread' : '' ) . '" data-dbv9-notification-open aria-expanded="false" aria-controls="' . esc_attr( $panel_id ) . '" aria-label="' . esc_attr__( 'Notifications', 'delicat-builder-v9' ) . '"><span class="dbv9-notifications__bell-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg></span><span class="dbv9-notifications__live-dot" aria-hidden="true"></span><span class="dbv9-notifications__count"' . ( $unread ? '' : ' hidden' ) . '>' . esc_html( (string) min( 99, $unread ) ) . '</span></button>' .
			'</div>';
	}

	public static function templates(): array {
		return array(
			'order_processing' => array( 'title' => 'Commande #{order_number} en traitement', 'message' => 'Bonjour {customer_name}, votre commande {product_name} est en cours de traitement.', 'category' => 'orders' ),
			'order_completed'  => array( 'title' => 'Commande #{order_number} terminée', 'message' => 'Votre commande {product_name} a été livrée avec succès.', 'category' => 'orders' ),
			'order_cancelled'  => array( 'title' => 'Commande #{order_number} annulée', 'message' => 'Votre commande a été annulée. Consultez les détails pour plus d’informations.', 'category' => 'orders' ),
			'order_refunded'   => array( 'title' => 'Remboursement effectué', 'message' => 'Le remboursement de la commande #{order_number} a été traité.', 'category' => 'wallet' ),
			'wallet_credit'    => array( 'title' => 'Wallet crédité', 'message' => 'Votre portefeuille a été crédité de {amount} {currency}.', 'category' => 'wallet' ),
		);
	}

	private static function apply_vars( string $text, array $vars ): string {
		foreach ( $vars as $key => $value ) { $text = str_replace( '{' . $key . '}', (string) $value, $text ); }
		return $text;
	}

	public static function store_item( array $item ): array {
		$extra = isset( $item['extra'] ) && is_array( $item['extra'] ) ? $item['extra'] : array();
		$item = wp_parse_args(
			$item,
			array(
				'id' => 'dn_' . wp_generate_uuid4(), 'title' => 'Delicat Store', 'message' => '', 'category' => 'general', 'code' => '',
				'link' => home_url( '/' ), 'urgent' => 0, 'created' => time(), 'enabled' => 1, 'user_id' => 0,
				'source' => 'system', 'audience' => 'broadcast', 'bell' => 1,
			)
		);
		$item = self::normalize_item( $item );
		if ( $extra ) {
			$safe_extra = array();
			foreach ( $extra as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( $key && is_scalar( $value ) ) { $safe_extra[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 500 ); }
			}
			if ( $safe_extra ) { $item['extra'] = $safe_extra; }
		}
		$items = self::items();
		array_unshift( $items, $item );
		update_option( self::HISTORY_OPTION, array_slice( $items, 0, 100 ), false );
		self::$items_cache = null;
		return $item;
	}

	public static function enqueue_delivery( array $item ): void {
		self::bump( 'queued' );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'delicat_builder_v9_deliver_notification', array( $item ), 'delicat-builder-v9-notifications' );
			return;
		}
		wp_schedule_single_event( time() + 2, 'delicat_builder_v9_deliver_notification', array( $item ) );
	}

	public static function deliver_queued( $item ): void {
		if ( ! is_array( $item ) ) { return; }
		$result = self::send_fcm( $item );
		self::bump( 'sent', absint( $result['sent'] ?? 0 ) );
		self::bump( 'failed', absint( $result['failed'] ?? 0 ) );

		$web = array( 'sent' => 0, 'failed' => 0 );
		if ( class_exists( 'Delicat_Builder_V9_Web_Push', false ) && is_callable( array( 'Delicat_Builder_V9_Web_Push', 'send_item' ) ) ) {
			$web = Delicat_Builder_V9_Web_Push::send_item( $item );
			self::record_web_push_result( absint( $web['sent'] ?? 0 ), absint( $web['failed'] ?? 0 ) );
		}
		$result['web_push'] = $web;
		set_transient( 'dbv9_notification_last_result', $result, 120 );
	}

	public static function record_web_push_result( int $sent, int $failed ): void {
		if ( $sent > 0 ) { self::bump( 'web_sent', $sent ); }
		if ( $failed > 0 ) { self::bump( 'web_failed', $failed ); }
	}

	private static function service_account_json(): string {
		$encrypted = (string) get_option( self::ENCRYPTED_SERVICE, '' );
		if ( $encrypted && class_exists( 'DIP_Crypto' ) && is_callable( array( 'DIP_Crypto', 'decrypt' ) ) ) {
			$plain = (string) DIP_Crypto::decrypt( $encrypted );
			if ( $plain && is_array( json_decode( $plain, true ) ) ) { return $plain; }
		}
		$legacy = (string) get_option( self::LEGACY_SERVICE, '' );
		return is_array( json_decode( $legacy, true ) ) ? $legacy : '';
	}

	private static function save_service_account( string $json ): bool {
		$json = trim( $json );
		if ( '' === $json ) {
			delete_option( self::ENCRYPTED_SERVICE );
			delete_option( self::LEGACY_SERVICE );
			delete_transient( self::TOKEN_TRANSIENT );
			return true;
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) || empty( $decoded['project_id'] ) ) { return false; }
		if ( class_exists( 'DIP_Crypto' ) && is_callable( array( 'DIP_Crypto', 'is_available' ) ) && DIP_Crypto::is_available() ) {
			$encrypted = (string) DIP_Crypto::encrypt( $json );
			if ( '' === $encrypted ) { return false; }
			update_option( self::ENCRYPTED_SERVICE, $encrypted, false );
			// Remove any historical plaintext copy immediately after a successful
			// encrypted save; do not wait for the next migration request.
			delete_option( self::LEGACY_SERVICE );
			delete_transient( self::TOKEN_TRANSIENT );
			return true;
		}
		// Strong-security mode: never persist a Firebase private key as plaintext.
		// Identity Pro is the authority for encryption; fail closed if unavailable.
		return false;
	}

	private static function b64url( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function google_access_token(): string {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && $cached ) { return $cached; }
		$raw = self::service_account_json();
		$sa = json_decode( $raw, true );
		if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) { return ''; }
		$now = time();
		$header = self::b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::b64url( wp_json_encode( array( 'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3500 ) ) );
		$unsigned = $header . '.' . $claims;
		$signature = '';
		if ( ! function_exists( 'openssl_sign' ) || ! openssl_sign( $unsigned, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) { return ''; }
		$jwt = $unsigned . '.' . self::b64url( $signature );
		$response = wp_safe_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 131072,
				'headers'             => array( 'Accept' => 'application/json' ),
				'body'                => array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt ),
			)
		);
		if ( is_wp_error( $response ) ) { return ''; }
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = is_array( $body ) ? (string) ( $body['access_token'] ?? '' ) : '';
		if ( $token ) { set_transient( self::TOKEN_TRANSIENT, $token, 3300 ); }
		return $token;
	}

	public static function send_fcm( array $item ): array {
		$extra = isset( $item['extra'] ) && is_array( $item['extra'] ) ? $item['extra'] : array();
		$item = self::normalize_item( $item );
		$sa = json_decode( self::service_account_json(), true );
		$access = self::google_access_token();
		$legacy = trim( (string) get_option( self::LEGACY_FCM_KEY, '' ) );
		if ( ! $access && ! $legacy ) { return array( 'sent' => 0, 'failed' => 0, 'error' => 'Identifiants Firebase absents' ); }

		$sent = 0; $failed = 0; $stale = array(); $devices = self::devices();
		foreach ( $devices as $id => $device ) {
			if ( empty( $device['token'] ) ) { $stale[] = $id; continue; }
			$audience = sanitize_key( (string) ( $item['audience'] ?? 'broadcast' ) );
			$target_user = absint( $item['user_id'] ?? 0 );
			$device_user = absint( $device['user_id'] ?? 0 );
			if ( in_array( $audience, array( 'user', 'private' ), true ) ) {
				if ( $target_user <= 0 || $device_user !== $target_user ) { continue; }
			}
			$data = array(
				'notification_id' => (string) $item['id'], 'title' => (string) $item['title'], 'body' => (string) $item['message'],
				'url' => (string) $item['link'], 'category' => (string) $item['category'], 'source' => (string) $item['source'],
				'audience' => (string) $item['audience'], 'bell' => ! empty( $item['bell'] ) ? '1' : '0', 'urgent' => ! empty( $item['urgent'] ) ? '1' : '0',
			);
			foreach ( $extra as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( $key && is_scalar( $value ) ) { $data[ $key ] = substr( (string) $value, 0, 1000 ); }
			}
			if ( $access && is_array( $sa ) && ! empty( $sa['project_id'] ) ) {
				$endpoint = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode( $sa['project_id'] ) . '/messages:send';
				$payload = array( 'message' => array( 'token' => $device['token'], 'data' => $data, 'android' => array( 'priority' => 'HIGH' ) ) );
				$headers = array( 'Authorization' => 'Bearer ' . $access, 'Content-Type' => 'application/json' );
			} else {
				$endpoint = 'https://fcm.googleapis.com/fcm/send';
				$payload = array( 'to' => $device['token'], 'priority' => 'high', 'data' => $data );
				$headers = array( 'Authorization' => 'key=' . $legacy, 'Content-Type' => 'application/json' );
			}
			$response = wp_safe_remote_post(
				$endpoint,
				array(
					'timeout'             => 8,
					'redirection'         => 0,
					'limit_response_size' => 131072,
					'headers'             => $headers,
					'body'                => wp_json_encode( $payload ),
				)
			);
			$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				$sent++; $devices[ $id ]['last_error'] = ''; $devices[ $id ]['last_sent'] = time();
			} else {
				$failed++;
				$body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
				$devices[ $id ]['last_error'] = sanitize_text_field( wp_strip_all_tags( substr( (string) $body, 0, 300 ) ) );
				if ( 404 === $code || 410 === $code || false !== strpos( (string) $body, 'UNREGISTERED' ) ) { $stale[] = $id; }
			}
		}
		foreach ( array_unique( $stale ) as $id ) { unset( $devices[ $id ] ); }
		self::save_devices( $devices );
		return compact( 'sent', 'failed' );
	}

	private static function notify_order( int $order_id, string $key ): void {
		if ( ! function_exists( 'wc_get_order' ) ) { return; }
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }
		$templates = self::templates();
		if ( ! isset( $templates[ $key ] ) ) { return; }
		$template = $templates[ $key ];
		$names = array();
		foreach ( $order->get_items() as $order_item ) { $names[] = $order_item->get_name(); }
		$vars = array(
			'order_number' => $order->get_order_number(),
			'customer_name' => $order->get_billing_first_name() ?: 'client',
			'product_name' => implode( ', ', array_slice( $names, 0, 3 ) ),
			'amount' => $order->get_total(),
			'currency' => $order->get_currency(),
		);
		$user_id = (int) $order->get_user_id();
		$item = self::store_item(
			array(
				'title' => self::apply_vars( $template['title'], $vars ),
				'message' => self::apply_vars( $template['message'], $vars ),
				'category' => $template['category'],
				'link' => $order->get_view_order_url(),
				'user_id' => $user_id,
				'source' => 'system',
				'audience' => $user_id ? 'user' : 'private',
				'bell' => $user_id ? 1 : 0,
			)
		);
		self::enqueue_delivery( $item );
	}

	public static function order_processing( $order_id ): void { self::notify_order( absint( $order_id ), 'order_processing' ); }
	public static function order_completed( $order_id ): void { self::notify_order( absint( $order_id ), 'order_completed' ); }
	public static function order_cancelled( $order_id ): void { self::notify_order( absint( $order_id ), 'order_cancelled' ); }
	public static function order_refunded( $order_id ): void { self::notify_order( absint( $order_id ), 'order_refunded' ); }

	public static function admin_menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Notifications', 'delicat-builder-v9' ),
			__( 'Notifications', 'delicat-builder-v9' ),
			'manage_woocommerce',
			'delicat-notifications',
			array( __CLASS__, 'admin_page' )
		);
	}

	public static function admin_save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission refusée.', 'delicat-builder-v9' ) ); }
		check_admin_referer( 'delicat_builder_v9_notifications_settings' );
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'require_recent_for_security_change' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::require_recent_for_security_change( 'notifications' );
		}
		update_option( self::ENABLED_OPTION, empty( $_POST['enabled'] ) ? 0 : 1, false );
		update_option( self::PERSONAL_OPTION, empty( $_POST['bell_personal'] ) ? 0 : 1, false );
		if ( class_exists( 'Delicat_Builder_V9_Web_Push', false ) ) { Delicat_Builder_V9_Web_Push::set_enabled( ! empty( $_POST['browser_push'] ) ); }
		if ( class_exists( 'Delicat_Builder_V9_Live_Selling', false ) ) { Delicat_Builder_V9_Live_Selling::set_enabled( ! empty( $_POST['live_selling'] ) ); }
		$app_key = sanitize_text_field( wp_unslash( $_POST['app_key'] ?? '' ) );
		if ( strlen( $app_key ) >= 24 ) { update_option( self::APP_KEY_OPTION, $app_key, false ); }
		$service = trim( (string) wp_unslash( $_POST['service_account'] ?? '' ) );
		if ( '' !== $service && ! self::save_service_account( $service ) ) {
			set_transient( 'dbv9_notification_admin_error', 'Compte de service Firebase invalide.', 60 );
		}
		$legacy_key = sanitize_text_field( wp_unslash( $_POST['fcm_key'] ?? '' ) );
		if ( '' !== $legacy_key ) { update_option( self::LEGACY_FCM_KEY, $legacy_key, false ); }
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) { Delicat_Builder_V9_Identity_Bridge::audit( 'builder_notification_settings_saved', 'notice' ); }
		wp_safe_redirect( admin_url( 'admin.php?page=delicat-notifications&settings=1' ) );
		exit;
	}

	public static function admin_publish(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission refusée.', 'delicat-builder-v9' ) ); }
		check_admin_referer( 'delicat_builder_v9_notifications_publish' );
		$title   = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		if ( ! $title || ! $message ) {
			wp_safe_redirect( admin_url( 'admin.php?page=delicat-notifications&error=missing' ) ); exit;
		}
		$item = self::store_item(
			array(
				'id' => 'dn_' . wp_generate_uuid4(),
				'title' => $title,
				'message' => $message,
				'category' => self::category( $_POST['category'] ?? 'general' ),
				'code' => sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ),
				'link' => self::clean_link( wp_unslash( $_POST['link'] ?? '' ) ),
				'urgent' => empty( $_POST['urgent'] ) ? 0 : 1,
				'created' => time(), 'enabled' => 1, 'source' => 'admin', 'audience' => 'broadcast', 'bell' => 1, 'user_id' => 0,
			)
		);
		if ( ! empty( $_POST['send_app'] ) ) { self::enqueue_delivery( $item ); }
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) { Delicat_Builder_V9_Identity_Bridge::audit( 'builder_notification_published', 'notice', array( 'category' => $item['category'] ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=delicat-notifications&published=1' ) );
		exit;
	}

	public static function admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$stats = self::stats(); $devices = self::devices(); $web_subscribers = class_exists( 'Delicat_Builder_V9_Web_Push', false ) ? Delicat_Builder_V9_Web_Push::count_active() : 0; $web_push_ready = class_exists( 'Delicat_Builder_V9_Web_Push', false ) && Delicat_Builder_V9_Web_Push::available();
		$error = get_transient( 'dbv9_notification_admin_error' );
		if ( $error ) { delete_transient( 'dbv9_notification_admin_error' ); }
		$has_encrypted = (bool) get_option( self::ENCRYPTED_SERVICE, '' );
		$has_legacy = (bool) get_option( self::LEGACY_SERVICE, '' );
		?>
		<div class="wrap">
			<h1>🔔 <?php esc_html_e( 'Delicat V9 Notifications', 'delicat-builder-v9' ); ?></h1>
			<p><?php echo esc_html( sprintf( 'Queue %d · App envoyées %d · Web Push %d · Échecs %d · Ouvertures %d · Lues %d', $stats['queued'], $stats['sent'], $stats['web_sent'], (int) $stats['failed'] + (int) $stats['web_failed'], $stats['opened'], $stats['read'] ) ); ?></p>
			<div class="notice notice-info"><p><strong>Confidentialité :</strong> les messages de chat privés ne sont jamais rendus dans la cloche. Les commandes sont visibles uniquement par leur propriétaire.</p></div>
			<?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['published'] ) ) : ?><div class="notice notice-success"><p>Notification publiée.</p></div><?php endif; ?>
			<div style="display:grid;grid-template-columns:minmax(320px,1fr) minmax(320px,1fr);gap:20px;max-width:1200px">
				<div class="card"><h2>Publier</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="delicat_builder_v9_notifications_publish"><?php wp_nonce_field( 'delicat_builder_v9_notifications_publish' ); ?>
					<p><label>Titre<br><input class="regular-text" required name="title"></label></p>
					<p><label>Message<br><textarea class="large-text" rows="4" required name="message"></textarea></label></p>
					<p><label>Catégorie <select name="category"><option value="orders">📦 Commandes</option><option value="wallet">💰 Wallet</option><option value="promotions">🎁 Promotions</option><option value="security">🛡️ Sécurité</option><option value="general">🔔 Général</option></select></label></p>
					<p><label>Lien interne<br><input class="large-text" type="url" name="link" placeholder="<?php echo esc_attr( home_url( '/my-account/' ) ); ?>"></label></p>
					<p><label>Code promo <input name="code"></label></p>
					<p><label><input type="checkbox" name="send_app" value="1" checked> Envoyer en Push (navigateur + app)</label><br><label><input type="checkbox" name="urgent" value="1"> Urgent</label></p>
					<p><button class="button button-primary">Publier et synchroniser</button></p>
				</form></div>
				<div class="card"><h2>Configuration</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="delicat_builder_v9_notifications_save"><?php wp_nonce_field( 'delicat_builder_v9_notifications_settings' ); ?>
					<p><label><input type="checkbox" name="enabled" value="1" <?php checked( get_option( self::ENABLED_OPTION, 1 ), 1 ); ?>> Activer la cloche</label></p>
					<p><label><input type="checkbox" name="bell_personal" value="1" <?php checked( get_option( self::PERSONAL_OPTION, 1 ), 1 ); ?>> Afficher les notifications personnelles au client concerné</label></p>
					<p><label><input type="checkbox" name="browser_push" value="1" <?php checked( class_exists( 'Delicat_Builder_V9_Web_Push', false ) ? Delicat_Builder_V9_Web_Push::enabled() : false ); ?>> Web Push navigateur en arrière-plan</label><br><small><?php echo esc_html( sprintf( '%d navigateur(s) abonné(s). %s', $web_subscribers, $web_push_ready ? 'Prêt (HTTPS + Push Worker dédié).' : 'En attente de HTTPS/OpenSSL pour le Web Push.' ) ); ?></small></p>
					<p><label><input type="checkbox" name="live_selling" value="1" <?php checked( class_exists( 'Delicat_Builder_V9_Live_Selling', false ) ? Delicat_Builder_V9_Live_Selling::enabled() : false ); ?>> Live Selling — afficher uniquement les ventes WooCommerce réelles</label><br><small><a href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-live-selling' ) ); ?>">Configurer position, délai, durée, pages et texte →</a></small></p>
					<p><label>Clé App / Builder<br><input class="large-text code" name="app_key" value="<?php echo esc_attr( self::app_key() ); ?>"></label></p>
					<p><label>Compte Firebase HTTP v1 JSON<br><textarea class="large-text code" rows="5" name="service_account" placeholder="<?php echo esc_attr( $has_encrypted || $has_legacy ? 'Configuré — collez uniquement pour remplacer' : 'Collez le JSON Firebase Admin SDK' ); ?>"></textarea></label><br><small><?php echo $has_encrypted ? 'Stockage V9 chiffré par Identity Pro.' : ( $has_legacy ? 'Ancien secret détecté; V9 le lit pendant la migration.' : 'Jamais envoyé au navigateur.' ); ?></small></p>
					<details><summary>Ancienne clé FCM</summary><p><input class="large-text code" type="password" name="fcm_key" placeholder="Laisser vide pour conserver"></p></details>
					<p><button class="button button-primary">Enregistrer</button></p>
				</form><p><strong><?php echo esc_html( (string) count( $devices ) ); ?></strong> appareil(s) liés.</p></div>
			</div>
			<h2>Historique récent</h2><table class="widefat striped"><thead><tr><th>Date</th><th>Visibilité</th><th>Catégorie</th><th>Titre</th><th>Message</th></tr></thead><tbody>
			<?php foreach ( array_slice( self::items(), 0, 30 ) as $item ) : $visibility = empty( $item['bell'] ) ? '🔒 Push privé' : ( 'user' === $item['audience'] ? '👤 Client concerné' : '🌍 Public' ); ?>
				<tr><td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $item['created'] ) ); ?></td><td><?php echo esc_html( $visibility ); ?></td><td><?php echo esc_html( self::icon( $item['category'] ) . ' ' . $item['category'] ); ?></td><td><strong><?php echo esc_html( $item['title'] ); ?></strong></td><td><?php echo esc_html( $item['message'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<p><code>[delicat_notifications]</code> / <code>[delicat_header_notifications]</code></p>
		</div>
		<?php
	}
}
