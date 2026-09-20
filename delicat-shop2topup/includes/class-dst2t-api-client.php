<?php

defined( 'ABSPATH' ) || exit;

final class DST2T_API_Client {
	const BASE_URL = 'https://shop2topup.com/api/endpoints/v1';

	/**
	 * Credential shapes this client can present, in detection order.
	 *
	 * Reseller panels issue either a single API key or a key-id/secret pair, and
	 * present it over one of a few conventional headers. Rather than assume one,
	 * the connection test walks these against the read-only account endpoint and
	 * remembers whichever the account actually accepts.
	 */
	const AUTH_MODES = array( 'bearer_key', 'bearer_pair', 'bearer_secret', 'x_api_key', 'raw_key' );

	/** Error codes that mean "the credential was rejected", not "the call failed". */
	const AUTH_ERRORS = array( 'INVALID_API_KEY', 'MISSING_API_KEY', 'UNAUTHORIZED', 'FORBIDDEN', 'INVALID_CREDENTIALS', 'AUTHENTICATION_FAILED', 'HTTP_401', 'HTTP_403' );

	/** @var DST2T_Settings */
	private $settings;

	public function __construct( DST2T_Settings $settings ) {
		$this->settings = $settings;
	}

	public function account() {
		$data = $this->request( 'GET', '/account' );
		return isset( $data['account'] ) && is_array( $data['account'] ) ? $data['account'] : array();
	}

	/**
	 * Reads the account endpoint with one specific credential shape.
	 *
	 * Used only by the connection test. GET /account is read-only and idempotent,
	 * so trying a handful of shapes cannot spend anything.
	 *
	 * @param string $mode One of self::AUTH_MODES.
	 */
	public function probe( $mode ) {
		$data = $this->request( 'GET', '/account', null, array(), $mode );
		return isset( $data['account'] ) && is_array( $data['account'] ) ? $data['account'] : array();
	}

	/**
	 * Builds the authentication headers for a credential shape.
	 *
	 * @param string $mode One of self::AUTH_MODES, or '' for the configured mode.
	 * @return array Empty when the configured credentials cannot form this shape.
	 */
	public function auth_headers( $mode = '' ) {
		$mode   = $mode ? $mode : $this->settings->auth_mode();
		$key    = $this->settings->api_key_id();
		$secret = $this->settings->api_secret();

		switch ( $mode ) {
			case 'bearer_pair':
				return $key && $secret ? array( 'Authorization' => 'Bearer ' . $key . '.' . $secret ) : array();
			case 'bearer_secret':
				return $secret ? array( 'Authorization' => 'Bearer ' . $secret ) : array();
			case 'x_api_key':
				return $key ? array( 'X-API-Key' => $key ) : array();
			case 'raw_key':
				return $key ? array( 'Authorization' => $key ) : array();
			case 'bearer_key':
			default:
				return $key ? array( 'Authorization' => 'Bearer ' . $key ) : array();
		}
	}

	public function big_categories( $for_ui = true ) {
		$data = $this->request( 'GET', '/catalog/big-categories', null, array( 'for_ui' => $for_ui ? 'true' : 'false' ) );
		return isset( $data['big_categories'] ) && is_array( $data['big_categories'] ) ? $data['big_categories'] : array();
	}

	public function categories( $big_category_id = 0, $for_ui = true ) {
		$query = array( 'for_ui' => $for_ui ? 'true' : 'false' );
		if ( $big_category_id ) {
			$query['bigCategoryId'] = absint( $big_category_id );
		}
		$data = $this->request( 'GET', '/catalog/categories', null, $query );
		return isset( $data['categories'] ) && is_array( $data['categories'] ) ? $data['categories'] : array();
	}

	public function subcategories( $category_id = 0 ) {
		$query = $category_id ? array( 'categoryId' => absint( $category_id ) ) : array();
		$data  = $this->request( 'GET', '/catalog/subcategories', null, $query );
		return isset( $data['subcategories'] ) && is_array( $data['subcategories'] ) ? $data['subcategories'] : array();
	}

	public function requirements( $category_id ) {
		$data = $this->request( 'GET', '/catalog/category/' . absint( $category_id ) . '/requirements' );
		if ( ! isset( $data['requirements'] ) || ! is_array( $data['requirements'] ) ) {
			throw new DST2T_API_Exception( 'Requirements response is incomplete.', 'INVALID_RESPONSE' );
		}
		return $data['requirements'];
	}

	public function price( $item_id ) {
		$data = $this->request( 'GET', '/catalog/subcategory/' . absint( $item_id ) . '/price' );
		if ( empty( $data['price'] ) || ! is_array( $data['price'] ) || ! isset( $data['price']['unit_price'] ) ) {
			throw new DST2T_API_Exception( 'Price payload is missing unit_price.', 'INVALID_RESPONSE' );
		}
		if ( isset( $data['price']['currency'] ) && 'USD' !== strtoupper( (string) $data['price']['currency'] ) ) {
			throw new DST2T_API_Exception( 'Unexpected supplier currency.', 'UNEXPECTED_CURRENCY' );
		}
		$data['price']['unit_price'] = DST2T_Decimal::normalize( $data['price']['unit_price'] );
		return $data['price'];
	}

	public function validate_player( $item_id, $requirements ) {
		$body = array_merge(
			is_array( $requirements ) ? $requirements : array(),
			array( 'sub_category_id' => absint( $item_id ) )
		);
		$data = $this->request( 'POST', '/player/validate', $body );
		// Both public reference pages' documented envelopes are supported.
		$player = isset( $data['player'] ) ? $data['player'] : ( isset( $data['data'] ) ? $data['data'] : null );
		if ( ! is_array( $player ) || empty( $player['player_name'] ) ) {
			throw new DST2T_API_Exception( 'Player validation response is incomplete.', 'INVALID_RESPONSE' );
		}
		return $player;
	}

	public function create_order( $order_id, $item_id, $quantity, $requirements, $expected_unit_price ) {
		$body = array(
			'order_id'            => (string) $order_id,
			'sub_category_id'     => absint( $item_id ),
			'quantity'            => max( 1, absint( $quantity ) ),
			'requirements'        => (object) ( is_array( $requirements ) ? $requirements : array() ),
			'expected_unit_price' => DST2T_Decimal::normalize( $expected_unit_price ),
		);
		$data = $this->request( 'POST', '/orders/create', $body );
		if ( empty( $data['order'] ) || ! is_array( $data['order'] ) ) {
			throw new DST2T_API_Exception( 'Order payload is missing.', 'INVALID_RESPONSE' );
		}
		return $data['order'];
	}

	public function order( $order_id ) {
		$data = $this->request( 'GET', '/orders/' . rawurlencode( (string) $order_id ) );
		if ( empty( $data['order'] ) || ! is_array( $data['order'] ) ) {
			throw new DST2T_API_Exception( 'Order payload is missing.', 'INVALID_RESPONSE' );
		}
		return $data['order'];
	}

	public function batch_orders( $order_ids ) {
		$order_ids = array_values( array_unique( array_filter( array_map( 'strval', (array) $order_ids ) ) ) );
		if ( count( $order_ids ) > 50 ) {
			$order_ids = array_slice( $order_ids, 0, 50 );
		}
		$data = $this->request( 'POST', '/orders/batch', array( 'order_ids' => $order_ids ) );
		return array(
			'orders'    => isset( $data['orders'] ) && is_array( $data['orders'] ) ? $data['orders'] : array(),
			'not_found' => isset( $data['not_found'] ) && is_array( $data['not_found'] ) ? $data['not_found'] : array(),
		);
	}

	private function request( $method, $path, $body = null, $query = array(), $auth_mode = '' ) {
		$auth = $this->auth_headers( $auth_mode );
		if ( ! $auth ) {
			throw new DST2T_API_Exception( 'Top-up API credentials are not configured.', 'MISSING_API_KEY', 401 );
		}
		if ( ! preg_match( '#^/[A-Za-z0-9_./:-]+$#', $path ) ) {
			throw new DST2T_API_Exception( 'Unsafe API path rejected.', 'CLIENT_PATH_REJECTED' );
		}

		$url = self::BASE_URL . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'      => strtoupper( (string) $method ),
			'timeout'     => 20,
			'redirection' => 0,
			'sslverify'   => true,
			'limit_response_size' => 4194304,
			'headers'     => array_merge(
				$auth,
				array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => 'Delicat-Shop2TopUp/' . DST2T_VERSION . '; ' . home_url( '/' ),
				)
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
			if ( false === $args['body'] ) {
				throw new DST2T_API_Exception( 'The request body could not be encoded.', 'CLIENT_JSON_ERROR' );
			}
		}

		// Space status reads and batch calls, including administrator-triggered calls.
		$interval = '/orders/batch' === $path ? 31 : ( preg_match( '#^/orders/[0-9a-f-]{36}$#i', $path ) ? 21 : 0 );
		$cooldown_key = 'dst2t_api_' . md5( implode( '|', $auth ) . '|' . $path );
		$remaining = (int) get_transient( $cooldown_key ) - time();
		if ( $remaining > 0 ) {
			throw new DST2T_API_Exception( 'API call deferred by local rate limiter.', 'RATE_LIMIT_EXCEEDED', 429, $remaining );
		}
		if ( $interval ) { set_transient( $cooldown_key, time() + $interval, $interval ); }
		$started  = microtime( true );
		$response = wp_safe_remote_request( $url, $args );
		$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$this->log( 'warning', 'API transport error.', array( 'path' => $path, 'error' => $response->get_error_code(), 'ms' => $elapsed ) );
			throw new DST2T_API_Exception( $response->get_error_message(), 'TRANSPORT_ERROR', 0, 20 );
		}

		$status      = (int) wp_remote_retrieve_response_code( $response );
		$raw         = (string) wp_remote_retrieve_body( $response );
		$retry_after = absint( wp_remote_retrieve_header( $response, 'retry-after' ) );
		$data        = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			$this->log( 'error', 'API returned invalid JSON.', array( 'path' => $path, 'http' => $status, 'ms' => $elapsed ) );
			throw new DST2T_API_Exception( 'The provider returned an invalid JSON response.', 'INVALID_RESPONSE', $status, $retry_after );
		}

		if ( $status < 200 || $status >= 300 || ( ! isset( $data['success'] ) || true !== $data['success'] ) ) {
			$error   = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
			$code    = isset( $error['code'] ) ? (string) $error['code'] : 'HTTP_' . $status;
			$message = isset( $error['message'] ) ? (string) $error['message'] : 'The provider request failed.';
			$details = isset( $error['details'] ) && is_array( $error['details'] ) ? $error['details'] : array();
			if ( isset( $error['retry_after'] ) ) {
				$retry_after = max( $retry_after, absint( $error['retry_after'] ) );
			}
			if ( ! $retry_after && isset( $details['retry_after'] ) ) {
				$retry_after = absint( $details['retry_after'] );
			}
			if ( $retry_after ) { set_transient( $cooldown_key, time() + $retry_after, $retry_after ); }
			$this->log( 'warning', 'API request rejected.', array( 'path' => $path, 'http' => $status, 'code' => $code, 'ms' => $elapsed ) );
			throw new DST2T_API_Exception( $message, $code, $status, $retry_after, $details );
		}

		$this->log( 'debug', 'API request succeeded.', array( 'path' => $path, 'http' => $status, 'ms' => $elapsed ) );
		return $data;
	}

	private function log( $level, $message, $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		if ( 'debug' === $level && ! $this->settings->enabled( 'debug_logging' ) ) {
			return;
		}
		$context['source'] = 'delicat-shop2topup';
		wc_get_logger()->log( $level, $message, $context );
	}
}
