<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Dependency-free standards-based Web Push transport for Builder V9.
 *
 * - Push API subscriptions are stored in a dedicated non-autoloaded DB table.
 * - VAPID uses P-256 / ES256 and never exposes the private key to the browser.
 * - Payload encryption follows RFC 8291 (aes128gcm) using OpenSSL only.
 * - All outbound push endpoints use wp_safe_remote_post() to retain WordPress
 *   SSRF protections.
 */
final class Delicat_Builder_V9_Web_Push {
	public const ENABLED_OPTION = 'delicat_builder_v9_web_push_enabled';
	private const DB_VERSION_OPTION = 'delicat_builder_v9_web_push_db_version';
	private const DB_VERSION = '1.0.0';
	private const VAPID_PUBLIC_OPTION = 'delicat_builder_v9_vapid_public';
	private const VAPID_PRIVATE_OPTION = 'delicat_builder_v9_vapid_private_enc';
	private const MAX_ENDPOINT_LENGTH = 2048;
	private const MAX_PAYLOAD_BYTES = 1800;
	private const BATCH_SIZE = 12;
	private const PUSH_SW_QUERY = 'delicat_v9_push_sw';

	private static bool $booted = false;
	private static ?array $vapid_cache = null;

	public static function boot(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;
		add_action( 'init', array( __CLASS__, 'maybe_install_schema' ), 4 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_push_service_worker' ), -10 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'delicat_builder_v9_deliver_web_push_batch', array( __CLASS__, 'deliver_batch_action' ), 10, 2 );
	}

	public static function activate(): void {
		self::install_schema();
		self::ensure_vapid_keys();
		if ( false === get_option( self::ENABLED_OPTION, false ) ) {
			add_option( self::ENABLED_OPTION, 1, '', false );
		}
	}

	public static function enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, 1 );
	}

	public static function set_enabled( bool $enabled ): void {
		update_option( self::ENABLED_OPTION, $enabled ? 1 : 0, false );
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'delicat_push_subscriptions';
	}

	public static function maybe_install_schema(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			self::install_schema();
		}
		if ( self::enabled() ) { self::ensure_vapid_keys(); }
	}

	private static function install_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			endpoint_hash char(64) NOT NULL,
			endpoint text NOT NULL,
			p256dh varchar(180) NOT NULL,
			auth varchar(120) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			platform varchar(24) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			last_sent_at datetime NULL,
			last_error varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY endpoint_hash (endpoint_hash),
			KEY user_status (user_id,status),
			KEY status_id (status,id)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function count_active(): int {
		global $wpdb;
		$table = self::table();
		$value = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return absint( $value );
	}

	private static function b64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( string $value ): string {
		$value = strtr( trim( $value ), '-_', '+/' );
		$pad = strlen( $value ) % 4;
		if ( $pad ) { $value .= str_repeat( '=', 4 - $pad ); }
		$decoded = base64_decode( $value, true );
		return is_string( $decoded ) ? $decoded : '';
	}

	private static function local_secret(): string {
		$material = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' );
		return hash( 'sha256', (string) $material . '|delicat-builder-v9-web-push-v1', true );
	}

	private static function encrypt_private_pem( string $pem ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) { return ''; }
		$iv = random_bytes( 12 );
		$tag = '';
		$cipher = openssl_encrypt( $pem, 'aes-256-gcm', self::local_secret(), OPENSSL_RAW_DATA, $iv, $tag, 'dbv9-vapid-v1', 16 );
		if ( ! is_string( $cipher ) || 16 !== strlen( $tag ) ) { return ''; }
		return self::b64url_encode( $iv . $tag . $cipher );
	}

	private static function decrypt_private_pem( string $encoded ): string {
		if ( ! function_exists( 'openssl_decrypt' ) ) { return ''; }
		$raw = self::b64url_decode( $encoded );
		if ( strlen( $raw ) < 29 ) { return ''; }
		$iv = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$plain = openssl_decrypt( $cipher, 'aes-256-gcm', self::local_secret(), OPENSSL_RAW_DATA, $iv, $tag, 'dbv9-vapid-v1' );
		return is_string( $plain ) ? $plain : '';
	}

	private static function normalize_32( string $value ): string {
		if ( strlen( $value ) > 32 ) { $value = substr( $value, -32 ); }
		return str_pad( $value, 32, "\0", STR_PAD_LEFT );
	}

	private static function public_from_details( array $details ): string {
		if ( empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) { return ''; }
		return "\x04" . self::normalize_32( (string) $details['ec']['x'] ) . self::normalize_32( (string) $details['ec']['y'] );
	}

	private static function ensure_vapid_keys(): array {
		if ( null !== self::$vapid_cache ) { return self::$vapid_cache; }
		$public = (string) get_option( self::VAPID_PUBLIC_OPTION, '' );
		$encrypted = (string) get_option( self::VAPID_PRIVATE_OPTION, '' );
		$pem = $encrypted ? self::decrypt_private_pem( $encrypted ) : '';
		if ( $public && $pem ) {
			self::$vapid_cache = array( 'public' => $public, 'private_pem' => $pem );
			return self::$vapid_cache;
		}

		// Do not silently rotate a key if a persisted public key exists but its
		// private half can no longer be decrypted (for example after salt rotation).
		if ( $public && ! $pem ) {
			self::$vapid_cache = array();
			return self::$vapid_cache;
		}
		if ( ! function_exists( 'openssl_pkey_new' ) || ! defined( 'OPENSSL_KEYTYPE_EC' ) ) {
			self::$vapid_cache = array();
			return self::$vapid_cache;
		}
		$key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		if ( ! $key ) { self::$vapid_cache = array(); return self::$vapid_cache; }
		$private_pem = '';
		if ( ! openssl_pkey_export( $key, $private_pem ) ) { self::$vapid_cache = array(); return self::$vapid_cache; }
		$details = openssl_pkey_get_details( $key );
		$public_raw = is_array( $details ) ? self::public_from_details( $details ) : '';
		$encrypted_private = self::encrypt_private_pem( $private_pem );
		if ( 65 !== strlen( $public_raw ) || ! $encrypted_private ) { self::$vapid_cache = array(); return self::$vapid_cache; }
		$public = self::b64url_encode( $public_raw );
		update_option( self::VAPID_PUBLIC_OPTION, $public, false );
		update_option( self::VAPID_PRIVATE_OPTION, $encrypted_private, false );
		self::$vapid_cache = array( 'public' => $public, 'private_pem' => $private_pem );
		return self::$vapid_cache;
	}

	public static function public_key(): string {
		$keys = self::ensure_vapid_keys();
		return (string) ( $keys['public'] ?? '' );
	}

	public static function available(): bool {
		if ( ! self::enabled() || '' === self::public_key() || ! function_exists( 'openssl_pkey_derive' ) ) {
			return false;
		}

		/*
		 * RC42.1: Web Push no longer depends on V9 owning the site's root PWA.
		 * A legacy Builder/PWA may legitimately own scope "/" during migration.
		 * Browser Push uses its own narrow service-worker scope so both systems can
		 * coexist without replacing the customer's currently installed PWA worker.
		 *
		 * Some reverse proxies terminate TLS before PHP and leave is_ssl() false;
		 * the canonical HTTPS home URL is therefore accepted as the second signal.
		 */
		$home_scheme = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		return is_ssl() || 'https' === $home_scheme;
	}

	private static function push_scope(): string {
		$base = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$base = is_string( $base ) && '' !== $base ? trailingslashit( $base ) : '/';
		return $base . 'delicat-push/';
	}

	private static function push_service_worker_url(): string {
		$url = add_query_arg(
			array(
				self::PUSH_SW_QUERY => '1',
				'v'                 => defined( 'DELICAT_BUILDER_V9_VERSION' ) ? DELICAT_BUILDER_V9_VERSION : '1',
			),
			home_url( '/' )
		);
		return wp_make_link_relative( $url );
	}

	public static function maybe_serve_push_service_worker(): void {
		if ( empty( $_GET[ self::PUSH_SW_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public JS endpoint.
			return;
		}
		self::push_service_worker_response();
	}

	private static function push_service_worker_response(): void {
		$scope = self::push_scope();
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . $scope );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cross-Origin-Resource-Policy: same-origin' );
		?>
'use strict';
self.addEventListener('install',event=>event.waitUntil(self.skipWaiting()));
self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));
self.addEventListener('push',event=>{
  let data={};
  try{data=event.data?event.data.json():{};}catch(_){try{data={body:event.data?event.data.text():''};}catch(__){data={};}}
  const title=String(data.title||'Delicat Store').slice(0,120);
  const body=String(data.body||'').slice(0,360);
  let url=self.location.origin+'/';
  try{const parsed=new URL(String(data.url||'/'),self.location.origin);if(parsed.origin===self.location.origin)url=parsed.href;}catch(_){}
  let icon;try{if(typeof data.icon==='string'&&data.icon){const parsedIcon=new URL(data.icon,self.location.origin);if(parsedIcon.origin===self.location.origin)icon=parsedIcon.href;}}catch(_){}
  const options={
    body,
    tag:String(data.tag||data.notification_id||'delicat-update').slice(0,120),
    data:{url,notification_id:String(data.notification_id||'')},
    icon,
    badge:icon,
    renotify:!!data.urgent,
    timestamp:Number(data.timestamp)||Date.now()
  };
  event.waitUntil(self.registration.showNotification(title,options).then(()=>self.clients.matchAll({type:'window',includeUncontrolled:true})).then(list=>{for(const client of list){try{client.postMessage({type:'dbv9-push',item:{...data,url}});}catch(_){}}}));
});
self.addEventListener('notificationclick',event=>{
  event.notification.close();
  let target=self.location.origin+'/';
  try{const parsed=new URL(String((event.notification.data&&event.notification.data.url)||'/'),self.location.origin);if(parsed.origin===self.location.origin)target=parsed.href;}catch(_){}
  event.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(list=>{
    for(const client of list){
      try{if(new URL(client.url).origin===self.location.origin){if('navigate'in client)client.navigate(target).catch(()=>{});return client.focus();}}catch(_){}
    }
    return self.clients.openWindow?self.clients.openWindow(target):undefined;
  }));
});
		<?php
		exit;
	}

	private static function same_origin_request( WP_REST_Request $request ): bool {
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$saw_origin = false;
		foreach ( array( 'origin', 'referer' ) as $header ) {
			$value = (string) $request->get_header( $header );
			if ( '' === $value ) { continue; }
			$saw_origin = true;
			$host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
			if ( '' === $host || ! hash_equals( $home_host, $host ) ) { return false; }
		}
		return $saw_origin;
	}

	public static function register_rest_routes(): void {
		register_rest_route(
			Delicat_Builder_V9_Notifications::UI_REST_NAMESPACE,
			'/push/subscription',
			array(
				array(
					'methods' => 'POST',
					'permission_callback' => array( __CLASS__, 'rest_mutation_allowed' ),
					'callback' => array( __CLASS__, 'rest_subscribe' ),
				),
				array(
					'methods' => 'DELETE',
					'permission_callback' => array( __CLASS__, 'rest_mutation_allowed' ),
					'callback' => array( __CLASS__, 'rest_unsubscribe' ),
				),
			)
		);
	}

	public static function rest_mutation_allowed( WP_REST_Request $request ): bool {
		if ( ! self::enabled() || ! self::same_origin_request( $request ) ) { return false; }
		$nonce = sanitize_text_field( (string) $request->get_header( 'x-delicat-push-nonce' ) );
		if ( '' === $nonce ) { $nonce = sanitize_text_field( (string) $request->get_param( 'nonce' ) ); }
		return (bool) wp_verify_nonce( $nonce, 'dbv9_web_push' );
	}

	private static function validate_subscription( array $data ) {
		$endpoint = esc_url_raw( (string) ( $data['endpoint'] ?? '' ) );
		$keys = isset( $data['keys'] ) && is_array( $data['keys'] ) ? $data['keys'] : array();
		$p256dh = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $keys['p256dh'] ?? '' ) );
		$auth = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $keys['auth'] ?? '' ) );
		if ( ! $endpoint || strlen( $endpoint ) > self::MAX_ENDPOINT_LENGTH || 'https' !== strtolower( (string) wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) || ! wp_http_validate_url( $endpoint ) ) {
			return new WP_Error( 'invalid_push_endpoint', 'Endpoint Web Push invalide.', array( 'status' => 400 ) );
		}
		$pub_raw = self::b64url_decode( $p256dh );
		$auth_raw = self::b64url_decode( $auth );
		if ( 65 !== strlen( $pub_raw ) || "\x04" !== substr( $pub_raw, 0, 1 ) || 16 !== strlen( $auth_raw ) ) {
			return new WP_Error( 'invalid_push_keys', 'Clés Web Push invalides.', array( 'status' => 400 ) );
		}
		return array( 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth );
	}

	public static function rest_subscribe( WP_REST_Request $request ) {
		if ( class_exists( 'Delicat_Builder_V9_Security', false ) && ! Delicat_Builder_V9_Security::rate_limit_allowed( 'web_push_subscribe', 20, 60 ) ) {
			return new WP_Error( 'push_rate_limited', 'Trop de requêtes Web Push.', array( 'status' => 429 ) );
		}
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$subscription = isset( $data['subscription'] ) && is_array( $data['subscription'] ) ? $data['subscription'] : $data;
		$valid = self::validate_subscription( $subscription );
		if ( is_wp_error( $valid ) ) { return $valid; }
		global $wpdb;
		$table = self::table();
		$now = current_time( 'mysql', true );
		$hash = hash( 'sha256', $valid['endpoint'] );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,created_at,auth,p256dh FROM {$table} WHERE endpoint_hash=%s LIMIT 1", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// An endpoint URL alone is not proof of subscription ownership. Require
		// the existing browser key pair before changing its account association.
		if ( $existing && (
			! hash_equals( (string) ( $existing['auth'] ?? '' ), $valid['auth'] )
			|| ! hash_equals( (string) ( $existing['p256dh'] ?? '' ), $valid['p256dh'] )
		) ) {
			return new WP_Error( 'push_subscription_owner_mismatch', 'Abonnement Web Push non autorisé.', array( 'status' => 403 ) );
		}
		$row = array(
			'endpoint_hash' => $hash,
			'endpoint' => $valid['endpoint'],
			'p256dh' => $valid['p256dh'],
			'auth' => $valid['auth'],
			'user_id' => get_current_user_id(),
			'platform' => substr( sanitize_key( (string) ( $data['platform'] ?? 'web' ) ), 0, 24 ),
			'status' => 'active',
			'created_at' => $existing && ! empty( $existing['created_at'] ) ? $existing['created_at'] : $now,
			'updated_at' => $now,
			'last_error' => '',
		);
		$formats = array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );
		if ( $existing ) {
			$written = $wpdb->update( $table, $row, array( 'id' => absint( $existing['id'] ) ), $formats, array( '%d' ) );
		} else {
			$written = $wpdb->insert( $table, $row, $formats );
		}
		if ( false === $written ) {
			return new WP_Error( 'push_subscription_write_failed', 'Impossible d’enregistrer cet appareil pour le Web Push.', array( 'status' => 500 ) );
		}
		return self::no_store( array( 'ok' => true, 'subscribed' => true, 'user_id' => get_current_user_id() ) );
	}

	public static function rest_unsubscribe( WP_REST_Request $request ) {
		if ( class_exists( 'Delicat_Builder_V9_Security', false ) && ! Delicat_Builder_V9_Security::rate_limit_allowed( 'web_push_unsubscribe', 30, 60 ) ) {
			return new WP_Error( 'push_rate_limited', 'Trop de requêtes Web Push.', array( 'status' => 429 ) );
		}
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$endpoint = esc_url_raw( (string) ( $data['endpoint'] ?? '' ) );
		if ( ! $endpoint ) { return new WP_Error( 'missing_endpoint', 'Endpoint manquant.', array( 'status' => 400 ) ); }
		$auth = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $data['auth'] ?? '' ) );
		global $wpdb;
		$table = self::table();
		$hash = hash( 'sha256', $endpoint );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,user_id,auth FROM {$table} WHERE endpoint_hash=%s LIMIT 1", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_array( $row ) ) {
			$user_owns = get_current_user_id() > 0 && absint( $row['user_id'] ?? 0 ) === get_current_user_id();
			$key_owns = '' !== $auth && isset( $row['auth'] ) && hash_equals( (string) $row['auth'], $auth );
			if ( ! $user_owns && ! $key_owns ) {
				return new WP_Error( 'push_subscription_owner_mismatch', 'Abonnement Web Push non autorisé.', array( 'status' => 403 ) );
			}
			$wpdb->delete( $table, array( 'id' => absint( $row['id'] ) ), array( '%d' ) );
		}
		return self::no_store( array( 'ok' => true, 'subscribed' => false ) );
	}

	private static function no_store( array $data ) {
		$response = rest_ensure_response( $data );
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Vary', 'Cookie' );
		}
		return $response;
	}

	public static function client_config(): array {
		return array(
			'enabled'       => self::enabled() ? 1 : 0,
			'available'     => self::available() ? 1 : 0,
			'publicKey'     => self::public_key(),
			'endpoint'      => rest_url( Delicat_Builder_V9_Notifications::UI_REST_NAMESPACE . '/push/subscription' ),
			'nonce'         => wp_create_nonce( 'dbv9_web_push' ),
			'serviceWorker' => self::push_service_worker_url(),
			'scope'         => self::push_scope(),
		);
	}

	private static function pem_for_public_point( string $public ): string {
		if ( 65 !== strlen( $public ) || "\x04" !== substr( $public, 0, 1 ) ) { return ''; }
		$prefix = hex2bin( '3059301306072A8648CE3D020106082A8648CE3D030107034200' );
		if ( ! is_string( $prefix ) ) { return ''; }
		$der = $prefix . $public;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	private static function hkdf( string $salt, string $ikm, string $info, int $length ): string {
		$prk = hash_hmac( 'sha256', $ikm, $salt, true );
		return substr( hash_hmac( 'sha256', $info . "\x01", $prk, true ), 0, $length );
	}

	/**
	 * Returns an RFC8291 aes128gcm body (binary) or WP_Error.
	 */
	public static function encrypt_payload( string $payload, string $user_public_b64, string $auth_b64 ) {
		if ( strlen( $payload ) > self::MAX_PAYLOAD_BYTES ) {
			return new WP_Error( 'push_payload_too_large', 'Payload Web Push trop volumineux.' );
		}
		$user_public = self::b64url_decode( $user_public_b64 );
		$user_auth = self::b64url_decode( $auth_b64 );
		if ( 65 !== strlen( $user_public ) || 16 !== strlen( $user_auth ) || ! function_exists( 'openssl_pkey_derive' ) ) {
			return new WP_Error( 'push_crypto_input', 'Paramètres cryptographiques Web Push invalides.' );
		}
		$peer_pem = self::pem_for_public_point( $user_public );
		$peer_key = $peer_pem ? openssl_pkey_get_public( $peer_pem ) : false;
		$local_key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		if ( ! $peer_key || ! $local_key ) { return new WP_Error( 'push_crypto_key', 'Impossible de créer la clé Web Push.' ); }
		$details = openssl_pkey_get_details( $local_key );
		$local_public = is_array( $details ) ? self::public_from_details( $details ) : '';
		if ( 65 !== strlen( $local_public ) ) { return new WP_Error( 'push_crypto_public', 'Clé Web Push locale invalide.' ); }
		$shared = openssl_pkey_derive( $peer_key, $local_key, 32 );
		if ( ! is_string( $shared ) || 32 !== strlen( $shared ) ) { return new WP_Error( 'push_crypto_ecdh', 'Échec ECDH Web Push.' ); }
		$ikm = self::hkdf( $user_auth, $shared, "WebPush: info\x00" . $user_public . $local_public, 32 );
		$salt = random_bytes( 16 );
		$cek = self::hkdf( $salt, $ikm, "Content-Encoding: aes128gcm\x00", 16 );
		$nonce = self::hkdf( $salt, $ikm, "Content-Encoding: nonce\x00", 12 );
		// Fixed modest padding obscures common short notification sizes without
		// turning every push into a multi-kilobyte transfer.
		$target = max( strlen( $payload ) + 1, min( 768, ( (int) ceil( ( strlen( $payload ) + 1 ) / 128 ) ) * 128 ) );
		$plaintext = str_pad( $payload . "\x02", $target, "\x00", STR_PAD_RIGHT );
		$tag = '';
		$cipher = openssl_encrypt( $plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( ! is_string( $cipher ) || 16 !== strlen( $tag ) ) { return new WP_Error( 'push_crypto_encrypt', 'Échec du chiffrement Web Push.' ); }
		$body = $salt . pack( 'N', 4096 ) . pack( 'C', 65 ) . $local_public . $cipher . $tag;
		return array( 'body' => $body, 'salt' => $salt, 'local_public' => $local_public );
	}

	private static function der_signature_to_raw( string $der ) {
		$len = strlen( $der );
		if ( $len < 8 || "\x30" !== $der[0] ) { return false; }
		$offset = 1;
		$seq_len = ord( $der[ $offset++ ] );
		if ( $seq_len & 0x80 ) {
			$count = $seq_len & 0x7f;
			if ( $count < 1 || $count > 2 || $offset + $count >= $len ) { return false; }
			$seq_len = 0;
			for ( $i = 0; $i < $count; $i++ ) { $seq_len = ( $seq_len << 8 ) | ord( $der[ $offset++ ] ); }
		}
		if ( $offset >= $len || "\x02" !== $der[ $offset++ ] ) { return false; }
		if ( $offset >= $len ) { return false; }
		$r_len = ord( $der[ $offset++ ] );
		$r = substr( $der, $offset, $r_len ); $offset += $r_len;
		if ( $offset >= $len || "\x02" !== $der[ $offset++ ] ) { return false; }
		if ( $offset >= $len ) { return false; }
		$s_len = ord( $der[ $offset++ ] );
		$s = substr( $der, $offset, $s_len );
		$r = ltrim( $r, "\x00" ); $s = ltrim( $s, "\x00" );
		if ( strlen( $r ) > 32 || strlen( $s ) > 32 ) { return false; }
		return str_pad( $r, 32, "\x00", STR_PAD_LEFT ) . str_pad( $s, 32, "\x00", STR_PAD_LEFT );
	}

	private static function vapid_header( string $endpoint ) {
		$keys = self::ensure_vapid_keys();
		$public = (string) ( $keys['public'] ?? '' );
		$private_pem = (string) ( $keys['private_pem'] ?? '' );
		$scheme = strtolower( (string) wp_parse_url( $endpoint, PHP_URL_SCHEME ) );
		$host = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		$port = wp_parse_url( $endpoint, PHP_URL_PORT );
		if ( 'https' !== $scheme || ! $host || ! $public || ! $private_pem ) { return new WP_Error( 'push_vapid_config', 'Configuration VAPID indisponible.' ); }
		$audience = 'https://' . $host . ( $port ? ':' . absint( $port ) : '' );
		$header = self::b64url_encode( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$claims = self::b64url_encode( wp_json_encode( array( 'aud' => $audience, 'exp' => time() + 43200, 'sub' => home_url( '/' ) ), JSON_UNESCAPED_SLASHES ) );
		$input = $header . '.' . $claims;
		$der = '';
		if ( ! openssl_sign( $input, $der, $private_pem, OPENSSL_ALGO_SHA256 ) ) { return new WP_Error( 'push_vapid_sign', 'Signature VAPID impossible.' ); }
		$raw = self::der_signature_to_raw( $der );
		if ( ! is_string( $raw ) || 64 !== strlen( $raw ) ) { return new WP_Error( 'push_vapid_signature', 'Signature VAPID invalide.' ); }
		$jwt = $input . '.' . self::b64url_encode( $raw );
		return 'vapid t=' . $jwt . ', k=' . $public;
	}

	private static function payload_for_item( array $item ): string {
		$title = substr( sanitize_text_field( (string) ( $item['title'] ?? 'Delicat Store' ) ), 0, 120 );
		$body = substr( sanitize_textarea_field( (string) ( $item['message'] ?? '' ) ), 0, 320 );
		$url = Delicat_Builder_V9_Notifications::clean_link( $item['link'] ?? '' );
		$icon = get_site_icon_url( 192 );
		$payload = array(
			'title' => $title ?: 'Delicat Store',
			'body' => $body,
			'url' => $url ?: home_url( '/' ),
			'icon' => $icon ? esc_url_raw( $icon ) : '',
			'tag' => 'dbv9-' . sanitize_key( (string) ( $item['id'] ?? wp_generate_uuid4() ) ),
			'notification_id' => sanitize_key( (string) ( $item['id'] ?? '' ) ),
			'category' => sanitize_key( (string) ( $item['category'] ?? 'general' ) ),
			'urgent' => empty( $item['urgent'] ) ? 0 : 1,
			'timestamp' => absint( $item['created'] ?? time() ) * 1000,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	private static function target_user_id( array $item ): int {
		$audience = sanitize_key( (string) ( $item['audience'] ?? 'broadcast' ) );
		if ( in_array( $audience, array( 'user', 'private' ), true ) ) { return absint( $item['user_id'] ?? 0 ); }
		return 0;
	}

	private static function query_batch( int $after_id, int $target_user_id ): array {
		global $wpdb;
		$table = self::table();
		if ( $target_user_id > 0 ) {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status='active' AND user_id=%d AND id>%d ORDER BY id ASC LIMIT %d", $target_user_id, $after_id, self::BATCH_SIZE ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status='active' AND id>%d ORDER BY id ASC LIMIT %d", $after_id, self::BATCH_SIZE ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	private static function update_result( int $id, bool $ok, string $error = '', bool $expired = false ): void {
		global $wpdb;
		$table = self::table();
		$data = array(
			'updated_at' => current_time( 'mysql', true ),
			'last_error' => substr( sanitize_text_field( wp_strip_all_tags( $error ) ), 0, 191 ),
		);
		if ( $ok ) { $data['last_sent_at'] = current_time( 'mysql', true ); }
		if ( $expired ) { $data['status'] = 'expired'; }
		$wpdb->update( $table, $data, array( 'id' => $id ) );
	}

	private static function send_row( array $row, string $payload ): array {
		$endpoint = (string) ( $row['endpoint'] ?? '' );
		$encrypted = self::encrypt_payload( $payload, (string) ( $row['p256dh'] ?? '' ), (string) ( $row['auth'] ?? '' ) );
		if ( is_wp_error( $encrypted ) ) { return array( 'ok' => false, 'expired' => false, 'error' => $encrypted->get_error_message() ); }
		$vapid = self::vapid_header( $endpoint );
		if ( is_wp_error( $vapid ) ) { return array( 'ok' => false, 'expired' => false, 'error' => $vapid->get_error_message() ); }
		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout' => 8,
				'redirection' => 0,
				'headers' => array(
					'Authorization' => $vapid,
					'Content-Encoding' => 'aes128gcm',
					'Content-Type' => 'application/octet-stream',
					'TTL' => '86400',
					'Urgency' => 'normal',
				),
				'body' => $encrypted['body'],
				'data_format' => 'body',
			)
		);
		if ( is_wp_error( $response ) ) { return array( 'ok' => false, 'expired' => false, 'error' => $response->get_error_message() ); }
		$code = wp_remote_retrieve_response_code( $response );
		$ok = $code >= 200 && $code < 300;
		$expired = in_array( $code, array( 404, 410 ), true );
		$error = $ok ? '' : ( 'HTTP ' . $code . ' ' . substr( wp_remote_retrieve_body( $response ), 0, 150 ) );
		return compact( 'ok', 'expired', 'error' );
	}

	public static function send_item( array $item ): array {
		if ( ! self::available() ) { return array( 'sent' => 0, 'failed' => 0, 'queued_more' => 0 ); }
		$target_user_id = self::target_user_id( $item );
		$audience = sanitize_key( (string) ( $item['audience'] ?? 'broadcast' ) );
		// Private/user notifications without an authenticated owner cannot be
		// routed safely to a browser subscription, so fail closed rather than
		// broadcasting private order data.
		if ( in_array( $audience, array( 'user', 'private' ), true ) && $target_user_id <= 0 ) {
			return array( 'sent' => 0, 'failed' => 0, 'queued_more' => 0 );
		}
		return self::send_batch( $item, 0 );
	}

	private static function send_batch( array $item, int $after_id ): array {
		$target_user_id = self::target_user_id( $item );
		$rows = self::query_batch( $after_id, $target_user_id );
		if ( ! $rows ) { return array( 'sent' => 0, 'failed' => 0, 'queued_more' => 0 ); }
		$payload = self::payload_for_item( $item );
		if ( '' === $payload ) { return array( 'sent' => 0, 'failed' => count( $rows ), 'queued_more' => 0 ); }
		$sent = 0; $failed = 0; $last_id = $after_id;
		foreach ( $rows as $row ) {
			$id = absint( $row['id'] ?? 0 );
			if ( $id <= 0 ) { continue; }
			$last_id = max( $last_id, $id );
			$result = self::send_row( $row, $payload );
			if ( ! empty( $result['ok'] ) ) { $sent++; } else { $failed++; }
			self::update_result( $id, ! empty( $result['ok'] ), (string) ( $result['error'] ?? '' ), ! empty( $result['expired'] ) );
		}
		$queued_more = 0;
		if ( count( $rows ) >= self::BATCH_SIZE && $last_id > $after_id ) {
			self::queue_batch( $item, $last_id );
			$queued_more = 1;
		}
		return compact( 'sent', 'failed', 'queued_more' );
	}

	private static function queue_batch( array $item, int $after_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'delicat_builder_v9_deliver_web_push_batch', array( $item, $after_id ), 'delicat-builder-v9-notifications' );
			return;
		}
		wp_schedule_single_event( time() + 3, 'delicat_builder_v9_deliver_web_push_batch', array( $item, $after_id ) );
	}

	public static function deliver_batch_action( $item, $after_id ): void {
		if ( ! is_array( $item ) ) { return; }
		$result = self::send_batch( $item, absint( $after_id ) );
		if ( class_exists( 'Delicat_Builder_V9_Notifications', false ) && is_callable( array( 'Delicat_Builder_V9_Notifications', 'record_web_push_result' ) ) ) {
			Delicat_Builder_V9_Notifications::record_web_push_result( absint( $result['sent'] ?? 0 ), absint( $result['failed'] ?? 0 ) );
		}
	}
}
