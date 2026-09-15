<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Delicat Session (RC33) — one state for every surface.
 *
 * `wc-ajax=delicat_session` answers one compact JSON for the current cookie
 * session: sign-in state, identity, wallet balance and cart count. RC71 keeps
 * this endpoint deliberately small because it is the shared synchronization
 * transport for header, drawer and bottom navigation. Cart line rendering and
 * notifications already have their own purpose-built transports, so repeating
 * those payloads here only adds database/product work and response bytes.
 * Read-only, same-site, throttled, never cached. Nothing is persisted by JS.
 */
final class Delicat_Builder_V9_Session {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'wc_ajax_delicat_session', array( __CLASS__, 'respond' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 7 );
	}

	public static function assets(): void {
		if ( is_admin() || wp_doing_ajax() || ! class_exists( 'WC_AJAX' ) ) {
			return;
		}
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) ) && ! Delicat_Builder_V9_Core::is_enabled() ) {
			return;
		}
		wp_enqueue_script( 'delicat-builder-v9-session', DELICAT_BUILDER_V9_URL . 'assets/js/session.js', array(), DELICAT_BUILDER_V9_VERSION, true );
		/* The page never carries user data here — the store asks the endpoint. */
		wp_add_inline_script(
			'delicat-builder-v9-session',
			'window.DelicatSessionConfig=' . wp_json_encode(
				array(
					'url'      => WC_AJAX::get_endpoint( 'delicat_session' ),
					'resetUrl' => class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) ? Delicat_Builder_V9_Identity_Bridge::auth_reset_url() : home_url( '/' ),
				)
			) . ';',
			'before'
		);
	}

	private static function plain( $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	private static function wallet_url(): string {
		if ( class_exists( 'Delicat_Builder_V9_Purchase_Native', false ) && is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'wallet_url' ) ) ) {
			$url = (string) preg_replace( '/#.*$/', '', (string) Delicat_Builder_V9_Purchase_Native::wallet_url() );
			return (string) apply_filters( 'delicat_builder_v9_session_wallet_url', $url );
		}
		/* Delicat's wallet page is a stable route. Avoid a get_page_by_path() DB
		 * lookup on the hottest uncached endpoint; sites that use another wallet
		 * route can override this tiny URL through the filter. */
		$url = home_url( '/my-wallet/' );
		return (string) apply_filters( 'delicat_builder_v9_session_wallet_url', $url );
	}

	private static function client_code( int $user_id ): string {
		$code = (string) get_user_meta( $user_id, 'delicat_user_code', true );
		if ( '' === $code && function_exists( 'delicat_cs_get_code' ) ) {
			try {
				$code = (string) delicat_cs_get_code( $user_id );
			} catch ( Throwable $error ) {
				unset( $error );
				$code = '';
			}
		}
		return ( '' !== $code && '—' !== $code ) ? sanitize_text_field( $code ) : '';
	}

	public static function payload(): array {
		$logged = is_user_logged_in();
		$out    = array(
			'loggedIn'      => $logged,
			'name'          => '',
			'initial'       => '',
			'email'         => '',
			'code'          => '',
			'wallet'   => array( 'text' => '', 'raw' => null, 'url' => self::wallet_url() ),
			'cart'     => array( 'count' => 0, 'url' => function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : home_url( '/cart/' ) ),
			'urls'          => array(
				'account' => function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ),
				'logout'  => $logged ? ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) ? Delicat_Builder_V9_Identity_Bridge::logout_url() : wp_logout_url( home_url( '/' ) ) ) : '',
			),
			'ts'            => time(),
		);
		if ( $logged ) {
			$u    = wp_get_current_user();
			$full = trim( $u->first_name . ' ' . $u->last_name );
			if ( '' === $full ) {
				$full = $u->display_name ? $u->display_name : $u->user_login;
			}
			$initial        = function_exists( 'mb_substr' ) ? mb_substr( $full, 0, 1, 'UTF-8' ) : substr( $full, 0, 1 );
			$out['name']    = $full;
			$out['initial'] = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initial, 'UTF-8' ) : strtoupper( $initial );
			$out['email']   = (string) $u->user_email;
			$out['code'] = self::client_code( (int) $u->ID );
			try {
				if ( function_exists( 'woo_wallet' ) && is_object( woo_wallet() ) && isset( woo_wallet()->wallet ) && is_callable( array( woo_wallet()->wallet, 'get_wallet_balance' ) ) ) {
					/* TeraWallet already knows how to format its own balance. The `edit`
					 * value is retained for consumers that need a numeric amount. */
					$out['wallet']['text'] = self::plain( woo_wallet()->wallet->get_wallet_balance( $u->ID ) );
					$out['wallet']['raw']  = (float) woo_wallet()->wallet->get_wallet_balance( $u->ID, 'edit' );
					if ( '' === $out['wallet']['text'] && function_exists( 'wc_price' ) ) {
						$out['wallet']['text'] = self::plain( wc_price( $out['wallet']['raw'] ) );
					}
				}
			} catch ( Throwable $error ) {
				unset( $error );
			}
		}
		try {
			if ( function_exists( 'WC' ) && is_object( WC() ) && is_object( WC()->cart ) ) {
				$out['cart']['count'] = (int) WC()->cart->get_cart_contents_count();
			}
		} catch ( Throwable $error ) {
			unset( $error );
		}
		return (array) apply_filters( 'delicat_builder_v9_session_payload', $out );
	}

	public static function respond(): void {
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'Delicat Builder session state' );
		if ( ! headers_sent() ) {
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		$site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? strtolower( sanitize_key( (string) $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		if ( '' !== $site && ! in_array( $site, array( 'same-origin', 'same-site', 'none' ), true ) ) {
			status_header( 403 );
			exit;
		}
		if ( class_exists( 'Delicat_Builder_V9_Security', false ) && is_callable( array( 'Delicat_Builder_V9_Security', 'rate_limit_allowed' ) ) && ! Delicat_Builder_V9_Security::rate_limit_allowed( 'session_state', 90, 60 ) ) {
			status_header( 429 );
			exit;
		}
		wp_send_json( self::payload() );
	}
}
Delicat_Builder_V9_Session::boot();
