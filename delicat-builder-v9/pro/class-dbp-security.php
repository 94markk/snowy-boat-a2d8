<?php
/**
 * Delicat Builder V9 Pro — Security.
 *
 * Deliberately narrow. A storefront taking real payments through MonCash and
 * NatCash cannot afford a hardening layer that breaks a checkout, so this
 * closes the openings that cost nothing to close and leaves WooCommerce's own
 * flows completely alone.
 *
 * No script-src content policy is set: V9, WooCommerce and the payment bridges
 * all emit inline configuration, and a policy that blocks them would break the
 * store rather than protect it.
 *
 * PHP 7.4 compatible.
 *
 * @package Delicat_Builder_V9_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'DBP_Security', false ) ) {
	return;
}

final class DBP_Security {

	/**
	 * @return void
	 */
	public static function boot() {
		add_filter( 'wp_headers', array( __CLASS__, 'headers' ), 20 );
		add_action( 'send_headers', array( __CLASS__, 'send_headers' ), PHP_INT_MAX );

		/* Author enumeration: ?author=1 redirects to a slug and hands an
		 * attacker a valid username to spray against the login form. */
		add_action( 'template_redirect', array( __CLASS__, 'block_enumeration' ), 0 );

		add_filter( 'rest_endpoints', array( __CLASS__, 'close_user_routes' ) );
		add_filter( 'xmlrpc_methods', array( __CLASS__, 'drop_pingback' ) );
		add_filter( 'wp_die_ajax_handler', array( __CLASS__, 'quiet_die' ), 1 );
		add_filter( 'login_errors', array( __CLASS__, 'generic_login_error' ) );

		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
	}

	/**
	 * Response headers for normal page loads.
	 *
	 * @param array $headers Existing headers.
	 * @return array
	 */
	public static function headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			$headers = array();
		}

		$headers['X-Content-Type-Options'] = 'nosniff';
		$headers['Referrer-Policy']        = 'strict-origin-when-cross-origin';
		$headers['X-Frame-Options']        = 'SAMEORIGIN';

		return $headers;
	}

	/**
	 * Headers that need the request context.
	 *
	 * @return void
	 */
	public static function send_headers() {
		if ( is_admin() || headers_sent() ) {
			return;
		}

		// Append a separate enforcing policy: never collapse/replace host policies.
		$policy = "frame-ancestors 'self'; base-uri 'self'; object-src 'none'";
		if ( is_ssl() ) { $policy .= '; upgrade-insecure-requests'; }
		header( 'Content-Security-Policy: ' . $policy, false );

		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self), interest-cohort=()' );
		header( 'Cross-Origin-Resource-Policy: same-site' );

		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}
	}

	/**
	 * Stop ?author=N from resolving for anonymous visitors.
	 *
	 * @return void
	 */
	public static function block_enumeration() {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}
		if ( ! isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only guard.
			return;
		}

		$author = sanitize_text_field( wp_unslash( (string) $_GET['author'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only guard.
		if ( '' === $author || ! is_numeric( $author ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}

	/**
	 * Remove the REST user listing for anonymous callers. Logged-in staff keep
	 * every route they had.
	 *
	 * @param array $endpoints Registered routes.
	 * @return array
	 */
	public static function close_user_routes( $endpoints ) {
		if ( is_user_logged_in() || ! is_array( $endpoints ) ) {
			return $endpoints;
		}

		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

		return $endpoints;
	}

	/**
	 * @param array $methods XML-RPC methods.
	 * @return array
	 */
	public static function drop_pingback( $methods ) {
		if ( ! is_array( $methods ) ) {
			return $methods;
		}

		unset( $methods['pingback.ping'] );
		unset( $methods['pingback.extensions.getPingbacks'] );

		return $methods;
	}

	/**
	 * @param callable $handler Existing handler.
	 * @return callable
	 */
	public static function quiet_die( $handler ) {
		return $handler;
	}

	/**
	 * A login error that distinguishes "no such user" from "wrong password"
	 * confirms which accounts exist.
	 *
	 * @param string $error Original message.
	 * @return string
	 */
	public static function generic_login_error( $error ) {
		unset( $error );

		return esc_html__( 'Identifiants incorrects. Veuillez réessayer.', 'delicat-builder-v9' );
	}
}
