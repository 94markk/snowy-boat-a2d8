<?php

defined( 'ABSPATH' ) || exit;

final class DST2T_Webhook {
	const REST_NAMESPACE    = 'delicat-shop2topup/v1';
	const REST_ROUTE        = '/webhook';
	const PRIVATE_NAMESPACE = 'store-callbacks/v1';
	const OPTION_TOKEN      = 'dst2t_webhook_token';
	const OPTION_LAST_EVENT = 'dst2t_last_webhook_event';
	const MAX_BODY          = 262144;

	/** Signature headers accepted, in preference order. */
	const SIGNATURE_HEADERS = array( 'x-shop2topup-signature', 'x-signature', 'x-webhook-signature', 'x-hub-signature-256' );

	/** @var DST2T_Settings */
	private $settings;

	/** @var DST2T_Repository */
	private $repository;

	/** @var DST2T_Fulfillment */
	private $fulfillment;

	public function __construct( DST2T_Settings $settings, DST2T_Repository $repository, DST2T_Fulfillment $fulfillment ) {
		$this->settings    = $settings;
		$this->repository  = $repository;
		$this->fulfillment = $fulfillment;
	}

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route() {
		// The original endpoint stays registered so an already-configured supplier
		// panel keeps working after an upgrade. Stealth mode hides it from the
		// public REST index but never disables it.
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);

		if ( ! self::private_enabled() ) {
			return;
		}

		register_rest_route(
			self::PRIVATE_NAMESPACE,
			'/(?P<token>[a-f0-9]{32})',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receive_private' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && preg_match( '/^[a-f0-9]{32}$/', $value );
						},
					),
				),
			)
		);
	}

	public function receive_private( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		if ( ! hash_equals( self::token(), $token ) ) {
			return $this->reject( 'INVALID_ENDPOINT', 404 );
		}
		return $this->receive( $request );
	}

	public function receive( WP_REST_Request $request ) {
		// Everything before a valid signature is answered generically while stealth
		// mode is on: a prober who guesses the URL learns nothing about what runs
		// here. The real reason is written to the WooCommerce log instead.
		$secret = $this->settings->webhook_secret();
		if ( '' === $secret ) {
			return $this->reject( 'WEBHOOK_NOT_CONFIGURED', 503 );
		}

		$raw = (string) $request->get_body();
		if ( '' === $raw || strlen( $raw ) > self::MAX_BODY ) {
			return $this->reject( 'INVALID_BODY', 400 );
		}

		if ( ! $this->signature_valid( $request, $raw, $secret ) ) {
			return $this->reject( 'INVALID_SIGNATURE', 401 );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || empty( $payload['event'] ) || ! isset( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'INVALID_PAYLOAD' ), 400 );
		}

		if ( ! is_string( $payload['event'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'INVALID_EVENT' ), 400 );
		}
		$event = strtolower( trim( sanitize_text_field( $payload['event'] ) ) );

		// The event header is cross-checked when the supplier sends it, and is not
		// required when it does not: the HMAC already covers the event name.
		$header_event = strtolower( trim( sanitize_text_field( (string) $request->get_header( 'x-shop2topup-event' ) ) ) );
		if ( '' === $header_event ) {
			$header_event = strtolower( trim( sanitize_text_field( (string) $request->get_header( 'x-event' ) ) ) );
		}
		if ( '' !== $header_event && ! hash_equals( $event, $header_event ) ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'EVENT_MISMATCH' ), 400 );
		}

		if ( 'webhook.test' === $event || 'test' === $event || 'ping' === $event ) {
			update_option( 'dst2t_last_webhook_test', gmdate( 'c' ), false );
			self::record_event( $event, true );
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		// Every order lifecycle event is handled the same way: the payload only
		// tells us which supplier order moved, and the authoritative status is
		// then read from the API by the background worker.
		if ( 0 !== strpos( $event, 'order.' ) && 0 !== strpos( $event, 'orders.' ) ) {
			self::record_event( $event, false );
			return new WP_REST_Response( array( 'success' => true, 'ignored' => true ), 200 );
		}

		$order_id = $this->extract_order_id( $payload['data'] );
		if ( '' === $order_id ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'INVALID_ORDER_ID' ), 400 );
		}

		$row = $this->repository->get( $order_id );
		if ( ! $row ) {
			// A valid but unknown callback is acknowledged so the supplier does not
			// treat an unrelated/staging order as a broken production endpoint.
			self::record_event( $event, false );
			return new WP_REST_Response( array( 'success' => true, 'matched' => false ), 200 );
		}

		$event_timestamp = 0;
		if ( isset( $payload['timestamp'] ) && is_scalar( $payload['timestamp'] ) && '' !== trim( (string) $payload['timestamp'] ) ) {
			$parsed = is_numeric( $payload['timestamp'] ) ? (int) $payload['timestamp'] : strtotime( (string) $payload['timestamp'] );
			if ( false === $parsed || $parsed <= 0 ) {
				return new WP_REST_Response( array( 'success' => false, 'code' => 'INVALID_TIMESTAMP' ), 400 );
			}
			if ( $parsed > time() + 600 ) {
				return new WP_REST_Response( array( 'success' => false, 'code' => 'FUTURE_TIMESTAMP' ), 400 );
			}
			$event_timestamp = $parsed;
		}

		if ( $event_timestamp && $this->repository->is_stale_event( $order_id, $event_timestamp ) ) {
			self::record_event( $event, true );
			return new WP_REST_Response( array( 'success' => true, 'stale' => true ), 200 );
		}

		try {
			$this->fulfillment->queue_webhook( $order_id, $event_timestamp );
		} catch ( Throwable $error ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Verified webhook could not be applied.',
					array( 'source' => 'delicat-shop2topup', 'provider_order_id' => $order_id, 'type' => get_class( $error ) )
				);
			}
			return new WP_REST_Response( array( 'success' => false, 'code' => 'PROCESSING_ERROR' ), 500 );
		}

		self::record_event( $event, true );
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/* ------------------------------------------------------------- helpers */

	/**
	 * Answers a pre-signature rejection.
	 *
	 * @param string $code   Internal reason, logged but not returned while stealth mode is on.
	 * @param int    $status HTTP status used when stealth mode is off.
	 */
	private function reject( $code, $status ) {
		if ( ! DST2T_Brand::stealth() ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => $code ), (int) $status );
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				'A delivery callback was rejected before signature verification.',
				array( 'source' => 'delicat-shop2topup', 'reason' => $code )
			);
		}

		return new WP_REST_Response(
			array(
				'code'    => 'rest_no_route',
				'message' => __( 'No route was found matching the URL and request method.', 'delicat-shop2topup' ),
				'data'    => array( 'status' => 404 ),
			),
			404
		);
	}

	private function signature_valid( WP_REST_Request $request, $raw, $secret ) {
		$expected = hash_hmac( 'sha256', $raw, $secret );
		foreach ( self::SIGNATURE_HEADERS as $header ) {
			$provided = strtolower( trim( (string) $request->get_header( $header ) ) );
			if ( '' === $provided ) {
				continue;
			}
			if ( 0 === strpos( $provided, 'sha256=' ) ) {
				$provided = substr( $provided, 7 );
			}
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $provided ) ) {
				continue;
			}
			if ( hash_equals( $expected, $provided ) ) {
				return true;
			}
		}
		return false;
	}

	private function extract_order_id( $data ) {
		foreach ( array( 'order_id', 'orderId', 'uuid', 'id', 'client_order_id' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				continue;
			}
			$value = trim( $data[ $key ] );
			if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ) {
				return strtolower( $value );
			}
		}
		return '';
	}

	public static function record_event( $event, $matched ) {
		update_option(
			self::OPTION_LAST_EVENT,
			array(
				'event'   => substr( sanitize_text_field( (string) $event ), 0, 64 ),
				'at'      => time(),
				'matched' => (bool) $matched,
			),
			false
		);
	}

	public static function last_event() {
		$stored = get_option( self::OPTION_LAST_EVENT, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), array( 'event' => '', 'at' => 0, 'matched' => false ) );
	}

	public static function private_enabled() {
		$settings = DST2T_Brand::settings();
		return $settings ? $settings->enabled( 'private_webhook' ) : false;
	}

	/** Per-site secret path segment for the unbranded callback URL. */
	public static function token() {
		$token = (string) get_option( self::OPTION_TOKEN, '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			$token = self::rotate_token();
		}
		return $token;
	}

	public static function rotate_token() {
		$token = md5( wp_generate_password( 64, true, true ) . microtime( true ) . wp_rand( 0, PHP_INT_MAX ) );
		update_option( self::OPTION_TOKEN, $token, false );
		return $token;
	}

	/** The callback URL the operator registers upstream. */
	public static function url() {
		if ( self::private_enabled() ) {
			return rest_url( self::PRIVATE_NAMESPACE . '/' . self::token() );
		}
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	public static function legacy_url() {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/** True when the REST namespace belongs to this plugin. */
	public static function owns_namespace( $namespace ) {
		$namespace = trim( (string) $namespace, '/' );
		if ( '' === $namespace ) {
			return false;
		}
		foreach ( array( self::REST_NAMESPACE, self::PRIVATE_NAMESPACE ) as $owned ) {
			if ( $namespace === $owned || 0 === strpos( $namespace, $owned . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
