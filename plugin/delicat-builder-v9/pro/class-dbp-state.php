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
		/*
		 * PRO41: honour maintenance mode's API lock.
		 *
		 * This endpoint answers on init:5 and exits, so template_redirect never
		 * runs and Maintenance::guard_frontend() never sees the request.
		 * guard_early() runs on init:1 but returns unless wp_doing_ajax() or
		 * XMLRPC_REQUEST, and `GET /?dbp_state=1` is neither. So with the store
		 * closed and "Fermer aussi les API" on -- which the admin screen promises
		 * means "REST, admin-ajax, wc-ajax et XML-RPC repondent 503" -- this kept
		 * serving identity, wallet balance, cart totals and fresh nonces,
		 * including wp_rest. The sibling ?wc-ajax=delicat_session was correctly
		 * 503'd, which is the behaviour this now matches.
		 *
		 * No privilege escalation either way: it only ever served the caller's own
		 * session. The cost was that a shutdown the owner relies on during an
		 * incident did not actually stop scripted reads or nonce minting.
		 */
		if (
			class_exists( 'Delicat_Builder_V9_Maintenance', false )
			&& is_callable( array( 'Delicat_Builder_V9_Maintenance', 'api_locked' ) )
			&& Delicat_Builder_V9_Maintenance::api_locked()
		) {
			status_header( 503 );
			if ( ! headers_sent() ) {
				header( 'Retry-After: 300' );
			}
			self::send( array( 'ok' => false, 'error' => 'maintenance' ) );
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

		/*
		 * PRO41: Security::has_private_cookie() deliberately does NOT count the
		 * WooCommerce cart cookies -- pro.30 removed them so a guest carrying a
		 * basket can still be served the shared cached copy. That is right for
		 * the cache, but it means the flag cannot be used on its own to decide
		 * whether a guest has state worth fetching. Check the basket cookies
		 * separately: a guest with items must still have their badge corrected,
		 * while a guest with no cookies at all has nothing to correct.
		 */
		$has_basket = false;
		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			$name = strtolower( sanitize_key( (string) $cookie_name ) );
			if (
				0 === strpos( $name, 'woocommerce_items_in_cart' )
				|| 0 === strpos( $name, 'woocommerce_cart_hash' )
				|| 0 === strpos( $name, 'wp_woocommerce_session' )
				|| 0 === strpos( $name, 'dbv9_fav' )
			) {
				$has_basket = true;
				break;
			}
		}
		$config = array(
			/*
			 * PRO41: 'product' used to be in this list, which meant a brand-new
			 * guest -- no login, no cart, no cookie of any kind -- still fired
			 * /?dbp_state=1 on every product view. That endpoint is a full
			 * WordPress + WooCommerce bootstrap, so the store's most-visited
			 * route cost roughly twice the origin PHP requests it needed, and the
			 * answer for such a visitor is always "guest, empty cart" -- exactly
			 * what shared_document() already rendered into the cached HTML.
			 *
			 * It is idle-scheduled so it does not hurt LCP on a fast connection,
			 * but on a shared host it lengthens TTFB for everyone, including the
			 * visitors being served from cache. Cold ad traffic lands on product
			 * pages, so this was the worst place to spend a request.
			 *
			 * A shopper who actually has state -- signed in, or carrying a cart,
			 * favourites or currency cookie -- still probes here, because
			 * is_user_logged_in() and $private_cookie cover them. The purchase
			 * surfaces stay unconditional: on cart, checkout, account and wallet
			 * the session is the point of the page.
			 */
			'initial'  => ( is_user_logged_in() || $private_cookie || $has_basket || in_array( $route, array( 'cart', 'checkout', 'account', 'wallet' ), true ) ) ? 1 : 0,
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
