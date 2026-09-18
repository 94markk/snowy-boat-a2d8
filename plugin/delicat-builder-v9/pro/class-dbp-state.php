<?php
/**
 * Delicat Builder V9 Pro — Session state.
 *
 * V9 let the header, drawer, bottom bar, wallet card and notification bell each
 * discover the session for themselves. On a phone that is four or five
 * uncached round trips before the page settles, every one of them behind
 * admin-ajax and the full admin bootstrap.
 *
 * Pro answers all of it once, from an endpoint that short-circuits on `init`
 * and never loads the theme, the main query or the template.
 *
 * It also solves the cached-nonce problem: guest HTML served from LiteSpeed
 * carries whatever nonce was minted when the page was cached, which goes stale
 * at the tick. The state response carries fresh nonces and the client patches
 * them into the document.
 *
 * Requires PHP 8.3 (gated in the main plugin file).
 *
 * @package Delicat_Builder_V9_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'DBP_State', false ) ) {
	return;
}

final class DBP_State {

	const HANDLE   = 'dbp-state';
	const FLAG     = 'dbp_state';
	const NONCE    = 'dbp_state_v1';
	const RATE_KEY = 'dbp_state_rate_';

	/**
	 * @return void
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'maybe_respond' ), 5 );

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 21 );
		}
	}

	/**
	 * Endpoint URL.
	 *
	 * @return string
	 */
	public static function endpoint() {
		return add_query_arg( self::FLAG, '1', home_url( '/' ) );
	}

	/**
	 * Answer a state request and stop. Runs before the query is parsed, so the
	 * cost is WordPress core plus WooCommerce's session, nothing else.
	 *
	 * @return void
	 */
	public static function maybe_respond() {
		if ( ! isset( $_GET[ self::FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only session probe.
			return;
		}
		if ( is_admin() || wp_doing_cron() ) {
			return;
		}
		$fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_key( wp_unslash( (string) $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		if ( '' !== $fetch_site && 'same-origin' !== $fetch_site ) {
			status_header( 403 );
			self::send( array( 'ok' => false, 'error' => 'origin' ) );
		}
		if ( ! defined( 'LITESPEED_NO_OPTM' ) ) { define( 'LITESPEED_NO_OPTM', true ); }
		add_filter( 'litespeed_comment', '__return_false', PHP_INT_MAX );

		if ( ! self::within_rate_limit() ) {
			status_header( 429 );
			self::send( array( 'ok' => false, 'error' => 'rate' ) );
		}

		self::send( self::snapshot() );
	}

	/**
	 * Per-visitor rate limit. The endpoint is cheap but it is unauthenticated,
	 * so it gets a ceiling rather than an open door.
	 *
	 * @return bool
	 */
	private static function within_rate_limit() {
		if ( class_exists( 'Delicat_Builder_V9_Security', false ) && is_callable( array( 'Delicat_Builder_V9_Security', 'rate_limit_allowed' ) ) ) {
			return Delicat_Builder_V9_Security::rate_limit_allowed( 'pro_state', 60, 60 );
		}
		return true;
	}

	/**
	 * Emit JSON and stop the request.
	 *
	 * @param array $payload Response body.
	 * @return void
	 */
	private static function send( $payload ) {
		if ( ! headers_sent() ) {
			if ( function_exists( 'header_remove' ) ) { header_remove( 'X-Powered-By' ); }
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'Cross-Origin-Resource-Policy: same-origin' );
			header( 'Vary: Cookie, Accept-Encoding' );
		}

		echo wp_json_encode( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response.
		exit;
	}

	/**
	 * The whole session, in one object.
	 *
	 * @return array
	 */
	public static function snapshot() {
		$user_id  = get_current_user_id();
		$loggedin = $user_id > 0;

		$state = array(
			'ok'     => true,
			'time'   => time(),
			'user'   => array(
				'id'        => $user_id,
				'loggedIn'  => $loggedin,
				'name'      => '',
				'firstName' => '',
			),
			'cart'   => array(
				'count'    => 0,
				'subtotal' => '',
				'total'    => '',
				'url'      => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			),
			'wallet' => array(
				'enabled' => false,
				'balance' => '',
				'raw'     => 0,
			),
			'alerts' => array(
				'count' => 0,
			),
			'nonces' => self::nonces(),
		);

		if ( $loggedin ) {
			$user = wp_get_current_user();
			if ( $user instanceof WP_User ) {
				$state['user']['name']      = sanitize_text_field( $user->display_name );
				$state['user']['firstName'] = sanitize_text_field( $user->first_name ? $user->first_name : $user->display_name );
			}
		}

		$state['cart']   = array_merge( $state['cart'], self::cart() );
		$state['wallet'] = self::wallet( $user_id );
		$state['alerts'] = self::alerts( $user_id );

		return $state;
	}

	/**
	 * Cart summary.
	 *
	 * @return array
	 */
	private static function cart() {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}

		$woo = WC();
		if ( ! is_object( $woo ) || ! isset( $woo->cart ) || ! is_object( $woo->cart ) ) {
			return array();
		}

		$count    = (int) $woo->cart->get_cart_contents_count();
		$subtotal = '';
		$total    = '';

		if ( $count > 0 ) {
			$subtotal = wp_strip_all_tags( (string) $woo->cart->get_cart_subtotal() );
			$total    = wp_strip_all_tags( (string) $woo->cart->get_cart_total() );
		}

		return array(
			'count'    => $count,
			'subtotal' => $subtotal,
			'total'    => $total,
		);
	}

	/**
	 * Wallet balance, when a wallet plugin is present.
	 *
	 * @param int $user_id Current user.
	 * @return array
	 */
	private static function wallet( $user_id ) {
		$wallet = array(
			'enabled' => false,
			'balance' => '',
			'raw'     => 0,
		);

		if ( $user_id < 1 || ! function_exists( 'woo_wallet' ) ) {
			return $wallet;
		}

		$api = woo_wallet();
		if ( ! is_object( $api ) || ! isset( $api->wallet ) || ! is_object( $api->wallet ) ) {
			return $wallet;
		}

		$balance = $api->wallet->get_wallet_balance( $user_id, 'edit' );
		if ( ! is_numeric( $balance ) ) {
			return $wallet;
		}

		$wallet['enabled'] = true;
		$wallet['raw']     = (float) $balance;
		$wallet['balance'] = function_exists( 'wc_price' )
			? wp_strip_all_tags( wc_price( (float) $balance ) )
			: (string) $balance;

		return $wallet;
	}

	/**
	 * Unread notification count, when the V9 notification module is active.
	 *
	 * @param int $user_id Current user.
	 * @return array
	 */
	private static function alerts( $user_id ) {
		$count = 0;

		if ( $user_id > 0 && class_exists( 'Delicat_Builder_V9_Notifications', false )
			&& method_exists( 'Delicat_Builder_V9_Notifications', 'unread_count' ) ) {
			$count = (int) call_user_func( array( 'Delicat_Builder_V9_Notifications', 'unread_count' ), $user_id );
		}

		return array( 'count' => max( 0, $count ) );
	}

	/**
	 * Fresh nonces for the actions a cached page needs to perform.
	 *
	 * @return array
	 */
	private static function nonces() {
		return array(
			'state'    => wp_create_nonce( self::NONCE ),
			'wc'       => wp_create_nonce( 'woocommerce-cart' ),
			'addToCart'=> wp_create_nonce( 'woocommerce-add-to-cart' ),
			'rest'     => wp_create_nonce( 'wp_rest' ),
		);
	}

	/**
	 * @return void
	 */
	public static function enqueue() {
		if ( DBP_Kernel::is_fragment_request() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			DBP_Kernel::asset_url( 'pro/assets/dbp-state.js' ),
			array(),
			DBP_Kernel::asset_version(),
			true
		);

		$route = DBP_Kernel::route();
		$private_cookie = class_exists( 'Delicat_Builder_V9_Security', false ) && Delicat_Builder_V9_Security::has_private_cookie();
		$config = array(
			'initial'  => ( is_user_logged_in() || $private_cookie || in_array( $route, array( 'product', 'cart', 'checkout', 'account', 'wallet' ), true ) ) ? 1 : 0,
			'url'      => self::endpoint(),
			'loggedIn' => is_user_logged_in() ? 1 : 0,
			'interval' => 0,
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.DBPStateConfig=' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
