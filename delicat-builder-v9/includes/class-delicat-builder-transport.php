<?php
/** Mandatory transport boundary. WordPress remains the TLS/auth authority. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Delicat_Builder_V9_Transport {
	public static function boot(): void {
		force_ssl_admin( true );
		add_action( 'plugins_loaded', array( __CLASS__, 'enforce_request' ), -1000 );
		foreach ( array( 'home_url', 'site_url', 'admin_url', 'rest_url', 'plugins_url', 'content_url', 'script_loader_src', 'style_loader_src', 'woocommerce_get_checkout_url', 'woocommerce_get_cart_url' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'secure_site_url' ), PHP_INT_MAX );
		}
		add_filter( 'http_request_args', array( __CLASS__, 'verify_tls' ), PHP_INT_MAX );
		add_filter( 'https_ssl_verify', '__return_true', PHP_INT_MAX );
		add_filter( 'https_local_ssl_verify', '__return_true', PHP_INT_MAX );
		add_filter( 'pre_http_request', array( __CLASS__, 'require_https' ), PHP_INT_MAX, 3 );
		add_action( 'requests-requests.before_redirect', array( __CLASS__, 'validate_redirect' ), PHP_INT_MAX );
	}

	public static function secure_site_url( $url ) {
		if ( ! is_string( $url ) || 'http' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) { return $url; }
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		foreach ( array( 'home', 'siteurl' ) as $option ) {
			if ( $host && $host === strtolower( (string) wp_parse_url( (string) get_option( $option ), PHP_URL_HOST ) ) ) {
				return set_url_scheme( $url, 'https' );
			}
		}
		return $url;
	}

	/** Fixed configured authority, never the caller's Host/forwarded headers. */
	public static function https_target( string $uri ): string {
		$home = wp_parse_url( (string) get_option( 'home' ) );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) { return ''; }
		if ( ! preg_match( '#^/(?!/)#', $uri ) || preg_match( '/[\\\\\x00-\x20\x7f]/', $uri ) ) { $uri = '/'; }
		$port = isset( $home['port'] ) && ! in_array( (int) $home['port'], array( 80, 443 ), true ) ? ':' . (int) $home['port'] : '';
		return 'https://' . $home['host'] . $port . $uri;
	}

	public static function read_only_request(): bool {
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) { return false; }
		foreach ( array( 'add-to-cart', 'remove_item', 'undo_item', 'wc-ajax', 'wc-api', 'action', '_wpnonce', 'nonce', 'key', 'token', 'code', 'dip_action', 'logout', 'customer-logout' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) { return false; }
		}
		return true;
	}

	public static function enforce_request(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || PHP_SAPI === 'cli' || is_ssl() ) { return; }
		nocache_headers();
		// Never redirect/replay a plaintext login, order, payment or API mutation.
		if ( ! self::read_only_request() ) {
			wp_die( 'HTTPS is required. Open the secure site before submitting again.', 'HTTPS required', array( 'response' => 403 ) );
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		$target = self::https_target( $uri );
		if ( ! $target ) { wp_die( 'Configure a valid HTTPS WordPress address.', 'HTTPS required', array( 'response' => 503 ) ); }
		wp_safe_redirect( $target, 302, 'Delicat HTTPS' );
		exit;
	}

	public static function verify_tls( $args ): array {
		$args = is_array( $args ) ? $args : array();
		$args['sslverify'] = true;
		return $args;
	}

	public static function require_https( $pre, $args, $url ) {
		if ( 'https' !== strtolower( (string) wp_parse_url( (string) $url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'delicat_https_required', 'Outbound requests require HTTPS; plaintext transport is blocked.' );
		}
		return $pre;
	}

	public static function validate_redirect( $location ): void {
		if ( 'https' !== strtolower( (string) wp_parse_url( (string) $location, PHP_URL_SCHEME ) ) ) {
			// WordPress HTTP API catches Requests exceptions and returns WP_Error.
			throw new \WpOrg\Requests\Exception( 'HTTPS downgrade redirect blocked.', 'delicat_https_required' );
		}
	}
}
