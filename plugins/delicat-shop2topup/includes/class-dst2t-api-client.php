<?php

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-delicat-player-region.php';

/** Exact implementation of the public SHOP2TOPUP v1 REST contract. */
final class DST2T_API_Client {
	const BASE_URL = 'https://shop2topup.com/api/endpoints/v1';
	const AUTH_MODES = array( 'bearer_pair' ); // Kept for backwards compatibility with old admin code/tests.
	const AUTH_ERRORS = array( 'INVALID_API_KEY', 'MISSING_API_KEY', 'HTTP_401' );

	/** @var DST2T_Settings */
	private $settings;

	public function __construct( DST2T_Settings $settings ) { $this->settings = $settings; }

	public function auth_headers( $mode = '' ) {
		$credential = $this->settings->credential();
		return $credential ? array( 'Authorization' => 'Bearer ' . $credential ) : array();
	}

	public function account() {
		$data = $this->request( 'GET', '/account' );
		return isset( $data['account'] ) && is_array( $data['account'] ) ? $data['account'] : array();
	}

	public function probe( $mode = 'bearer_pair' ) { return $this->account(); }

	public function big_categories( $for_ui = true ) {
		$query = $for_ui ? array( 'for_ui' => 'true' ) : array();
		$rows  = $this->list_all( '/catalog/big-categories', array( 'big_categories', 'bigCategories', 'categories' ), $query );
		// Some reseller accounts return an empty list when image expansion is requested.
		// Retry the same documented endpoint without for_ui before treating it as empty.
		if ( ! $rows && $for_ui ) {
			$rows = $this->list_all( '/catalog/big-categories', array( 'big_categories', 'bigCategories', 'categories' ), array() );
		}
		return $this->normalize_category_rows( $rows, true );
	}

	public function categories( $big_category_id = 0, $for_ui = true ) {
		$query = array();
		if ( $big_category_id ) { $query['bigCategoryId'] = absint( $big_category_id ); }
		if ( $for_ui ) { $query['for_ui'] = 'true'; }
		$rows = $this->list_all( '/catalog/categories', array( 'categories', 'category_list', 'items' ), $query );

		if ( ! $rows && $for_ui ) {
			$fallback = $query;
			unset( $fallback['for_ui'] );
			$rows = $this->list_all( '/catalog/categories', array( 'categories', 'category_list', 'items' ), $fallback );
		}

		// The public API documents categoryId as optional on /catalog/subcategories.
		// If a reseller account returns products but an empty category index, derive
		// the category chooser from the product rows instead of blocking imports.
		if ( ! $rows ) {
			$rows = $this->derive_categories_from_items( $this->subcategories( 0 ), $big_category_id );
		}

		$rows = $this->normalize_category_rows( $rows, false );

		if ( $big_category_id ) {
			$rows = array_values( array_filter( $rows, function( $row ) use ( $big_category_id ) {
				if ( ! is_array( $row ) || ! isset( $row['big_category_id'] ) ) { return true; }
				return absint( $row['big_category_id'] ) === absint( $big_category_id );
			} ) );
		}
		return $rows;
	}

	public function subcategories( $category_id = 0 ) {
		$query = $category_id ? array( 'categoryId' => absint( $category_id ) ) : array();
		$rows = $this->list_all( '/catalog/subcategories', array( 'subcategories', 'products', 'items' ), $query );

		// A few provider deployments have ignored/changed the filter parameter while
		// still returning the complete catalog correctly. Use the documented unfiltered
		// endpoint and filter locally as a safe compatibility fallback.
		if ( ! $rows && $category_id ) {
			$all = $this->list_all( '/catalog/subcategories', array( 'subcategories', 'products', 'items' ), array() );
			foreach ( $all as $candidate ) {
				if ( ! is_array( $candidate ) ) { continue; }
				$remote_category = isset( $candidate['category_id'] ) ? absint( $candidate['category_id'] ) : ( isset( $candidate['categoryId'] ) ? absint( $candidate['categoryId'] ) : 0 );
				if ( $remote_category === absint( $category_id ) ) { $rows[] = $candidate; }
			}
		}

		$out = array();
		foreach ( $rows as $row ) {
			$row = $this->normalize_catalog_item( $row );
			if ( ! $row ) { $this->invalid_payload( '/catalog/subcategories', 'invalid_catalog_item' ); }
			$out[] = $row;
		}
		return $out;
	}

	/** Normalize both current and deprecated catalog type fields without guessing stock. */
	public function normalize_catalog_item( $row ) {
		if ( ! is_array( $row ) ) { return array(); }
		$item_id = isset( $row['item_id'] ) ? absint( $row['item_id'] ) : ( isset( $row['id'] ) ? absint( $row['id'] ) : 0 );
		if ( ! $item_id ) { return array(); }
		$row['item_id'] = $item_id;
		if ( empty( $row['name'] ) && ! empty( $row['item_name'] ) ) { $row['name'] = $row['item_name']; }
		if ( empty( $row['name'] ) && ! empty( $row['product_name'] ) ) { $row['name'] = $row['product_name']; }
		if ( ! isset( $row['description'] ) && isset( $row['item_description'] ) ) { $row['description'] = $row['item_description']; }
		if ( ! isset( $row['price'] ) && isset( $row['unit_price'] ) ) { $row['price'] = $row['unit_price']; }
		if ( ! isset( $row['category_id'] ) && isset( $row['categoryId'] ) ) { $row['category_id'] = absint( $row['categoryId'] ); }
		if ( ! isset( $row['category_name'] ) && isset( $row['categoryName'] ) ) { $row['category_name'] = $row['categoryName']; }
		if ( ! isset( $row['big_category_id'] ) && isset( $row['bigCategoryId'] ) ) { $row['big_category_id'] = absint( $row['bigCategoryId'] ); }
		if ( ! isset( $row['big_category_name'] ) && isset( $row['bigCategoryName'] ) ) { $row['big_category_name'] = $row['bigCategoryName']; }
		$type = isset( $row['product_type'] ) ? strtolower( sanitize_key( (string) $row['product_type'] ) ) : '';
		if ( ! in_array( $type, array( 'topup', 'voucher' ), true ) ) {
			$type = ! empty( $row['returns_voucher'] ) || ( isset( $row['fulfillment_type'] ) && 'voucher' === strtolower( (string) $row['fulfillment_type'] ) ) ? 'voucher' : 'topup';
		}
		$row['product_type'] = $type;
		$row['returns_voucher'] = 'voucher' === $type;
		return $row;
	}

	public function requirements( $category_id ) {
		$data = $this->request( 'GET', '/catalog/category/' . absint( $category_id ) . '/requirements' );
		if ( ! isset( $data['requirements'] ) || ! is_array( $data['requirements'] ) ) {
			throw new DST2T_API_Exception( 'Requirements response is incomplete.', 'INVALID_RESPONSE' );
		}
		return $data['requirements'];
	}

	public function price( $item_id ) {
		$last_error = null;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			try {
				$data = $this->request( 'GET', '/catalog/subcategory/' . absint( $item_id ) . '/price', null, array(), 8 );
				if ( empty( $data['price'] ) || ! is_array( $data['price'] ) || ! isset( $data['price']['unit_price'] ) ) {
					$this->invalid_payload( '/catalog/subcategory/' . absint( $item_id ) . '/price', 'missing_unit_price' );
				}
				try {
					if ( ! is_scalar( $data['price']['unit_price'] ) ) { throw new InvalidArgumentException(); }
					$data['price']['unit_price'] = DST2T_Decimal::normalize( $data['price']['unit_price'] );
				} catch ( InvalidArgumentException $error ) {
					$this->invalid_payload( '/catalog/subcategory/' . absint( $item_id ) . '/price', 'invalid_unit_price' );
				}
				if ( isset( $data['price']['currency'] ) && 'USD' !== $data['price']['currency'] ) { $this->invalid_payload( '/catalog/subcategory/' . absint( $item_id ) . '/price', 'unexpected_currency' ); }
				return $data['price'];
			} catch ( DST2T_API_Exception $error ) {
				$last_error = $error;
				$retryable_now = in_array( $error->get_api_code(), array( 'INVALID_RESPONSE', 'TRANSPORT_ERROR', 'INTERNAL_ERROR', 'SERVICE_UNAVAILABLE' ), true )
					&& 0 === $error->get_retry_after();
				if ( ! $retryable_now || $attempt >= 2 ) {
					throw $error;
				}
				// Price is an idempotent GET. A short retry is safe for malformed/transport
				// responses, but explicit rate-limit/retry-after signals are propagated so
				// the scheduler can back off correctly.
				usleep( 250000 * ( $attempt + 1 ) );
			}
		}
		throw $last_error ?: new DST2T_API_Exception( 'Price lookup failed.', 'INVALID_RESPONSE' );
	}


	/**
	 * Compatibility-only price fallback from the catalog list.
	 *
	 * expected_unit_price is a supplier-side ceiling, so this value cannot make the
	 * provider charge above what we send. Fulfillment uses this only when the dedicated
	 * price endpoint returned a structurally invalid/temporary response.
	 */
	public function catalog_price( $item_id, $category_id = 0 ) {
		$item_id = absint( $item_id );
		foreach ( $this->subcategories( absint( $category_id ) ) as $row ) {
			if ( absint( isset( $row['item_id'] ) ? $row['item_id'] : 0 ) !== $item_id ) { continue; }
			$value = isset( $row['price'] ) ? $row['price'] : ( isset( $row['unit_price'] ) ? $row['unit_price'] : '' );
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) { break; }
			$price = DST2T_Decimal::normalize( $value );
			if ( DST2T_Decimal::compare( $price, '0' ) <= 0 ) { break; }
			return $price;
		}
		throw new DST2T_API_Exception( 'Catalog item price is unavailable.', 'INVALID_RESPONSE', 200 );
	}

	public function validate_player( $item_id, $requirements, $product_context = '' ) {
		$requirements = is_array( $requirements ) ? $requirements : array();

		// SHOP2TOPUP's player validation endpoint always requires the canonical
		// player_id field, while some catalog requirement payloads and migrated
		// products may call the same identifier UID, user_id, userid, or playerid.
		// Preserve the original dynamic field for the provider and also send the
		// required canonical player_id alongside it.
		$player_id = '';
		foreach ( array( 'player_id', 'uid', 'user_id', 'userid', 'playerid' ) as $key ) {
			if ( isset( $requirements[ $key ] ) && is_scalar( $requirements[ $key ] ) ) {
				$value = trim( (string) $requirements[ $key ] );
				if ( '' !== $value ) {
					$player_id = $value;
					break;
				}
			}
		}
		if ( '' === $player_id ) {
			throw new DST2T_API_Exception( 'UID / Player ID is required for player verification.', 'MISSING_PLAYER_ID', 400 );
		}

		$body = array_merge( $requirements, array(
			'sub_category_id' => absint( $item_id ),
			'player_id'       => $player_id,
		) );
		$data = $this->request( 'POST', '/player/validate', $body, array(), 10 );
		$envelope = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
		$player = isset( $data['player'] ) && is_array( $data['player'] ) ? $data['player'] : ( isset( $envelope['player'] ) && is_array( $envelope['player'] ) ? $envelope['player'] : $envelope );
		// Keep IDs as strings: Free Fire UIDs exceed 32-bit integers. Never accept
		// a response for another account, even when the envelope says success.
		foreach ( array( $data, $envelope, $player ) as $identity ) {
			foreach ( array( 'player_id', 'uid', 'user_id', 'userid', 'playerid' ) as $key ) {
				if ( array_key_exists( $key, $identity ) && ( ! is_scalar( $identity[ $key ] ) || ! hash_equals( $player_id, trim( (string) $identity[ $key ] ) ) ) ) {
					throw new DST2T_API_Exception( 'The provider returned a different player account. Please retry or contact support.', 'PLAYER_ID_MISMATCH', 502 );
				}
			}
			foreach ( array( 'code', 'error_code', 'error' ) as $key ) {
				if ( isset( $identity[ $key ] ) && is_scalar( $identity[ $key ] ) && Delicat_Player_Region_V2::is_region_error( $identity[ $key ] ) ) {
					throw new DST2T_API_Exception( Delicat_Player_Region_V2::message( array( 'account_region' => Delicat_Player_Region_V2::error_region( $identity ) ) ), 'REGION_MISMATCH', 422, 0, array( 'account_region' => Delicat_Player_Region_V2::error_region( $identity ) ) );
				}
			}
			if ( isset( $identity['valid'] ) && true !== $identity['valid'] ) {
				throw new DST2T_API_Exception( 'Player validation was not confirmed.', 'INVALID_RESPONSE', 502 );
			}
		}
		if ( ! isset( $player['player_name'] ) || ! is_string( $player['player_name'] ) || '' === trim( $player['player_name'] ) ) {
			throw new DST2T_API_Exception( 'Player validation response is incomplete.', 'INVALID_RESPONSE' );
		}
		if ( Delicat_Player_Region_V2::mismatch( $product_context, $requirements, $player ) ) {
			throw new DST2T_API_Exception( Delicat_Player_Region_V2::message( $player ), 'REGION_MISMATCH', 422, 0, array( 'account_region' => Delicat_Player_Region_V2::account_region( $player ) ) );
		}
		return $player;
	}

	public function create_order( $order_id, $item_id, $quantity, $requirements, $expected_unit_price ) {
		$requirements = is_array( $requirements ) ? $requirements : array();
		// Keep legacy UID aliases for compatibility, but always include the canonical
		// player_id expected by the order contract whenever an identifier is present.
		if ( empty( $requirements['player_id'] ) ) {
			foreach ( array( 'uid', 'user_id', 'userid', 'playerid' ) as $alias ) {
				if ( isset( $requirements[ $alias ] ) && is_scalar( $requirements[ $alias ] ) && '' !== trim( (string) $requirements[ $alias ] ) ) {
					$requirements['player_id'] = trim( (string) $requirements[ $alias ] );
					break;
				}
			}
		}

		$body = array(
			'order_id' => (string) $order_id,
			'sub_category_id' => absint( $item_id ),
			'quantity' => max( 1, absint( $quantity ) ),
			'requirements' => (object) $requirements,
			'expected_unit_price' => DST2T_Decimal::normalize( $expected_unit_price ),
		);

		try {
			$data = $this->request( 'POST', '/orders/create', $body, array(), 10 );
			$order = $this->extract_order_payload( $data, $order_id );
			if ( ! $order ) {
				$this->record_invalid_shape( '/orders/create', 'missing_order' );
				throw new DST2T_API_Exception( 'Order payload is missing.', 'INVALID_RESPONSE', 201 );
			}
			$this->clear_response_diagnostic( '/orders/create' );
			return $this->validated_order( $order, $order_id );
		} catch ( DST2T_API_Exception $error ) {
			// Never blindly repeat a POST whose response was malformed or interrupted.
			// The UUID is the idempotency key, so first ask for that exact order.
			if ( in_array( $error->get_api_code(), array( 'INVALID_RESPONSE', 'TRANSPORT_ERROR' ), true ) ) {
				try {
					usleep( 300000 );
					return $this->order( $order_id );
				} catch ( DST2T_API_Exception $lookup_error ) {
					// ORDER_NOT_FOUND means the create probably did not land; the fulfillment
					// worker will safely retry later with the same UUID after another lookup.
					if ( 'ORDER_NOT_FOUND' !== $lookup_error->get_api_code() ) {
						throw $lookup_error;
					}
				}
			}
			throw $error;
		}
	}

	public function order( $order_id ) {
		$path = '/orders/' . rawurlencode( (string) $order_id );
		$data = $this->request( 'GET', $path, null, array(), 8 );
		$order = $this->extract_order_payload( $data, $order_id );
		if ( ! $order ) {
			$this->record_invalid_shape( $path, 'missing_order' );
			throw new DST2T_API_Exception( 'Order payload is missing.', 'INVALID_RESPONSE', 200 );
		}
		$this->clear_response_diagnostic( $path );
		return $this->validated_order( $order, $order_id );
	}

	/**
	 * Normalize the documented order object plus harmless live envelope variants.
	 * The final identity check still happens against our persisted UUID, so a wrapper
	 * change can never redirect fulfillment to a different supplier order.
	 */
	public function normalize_order_payload( $payload, $expected_id ) {
		$order = $this->extract_order_payload( $payload, $expected_id );
		if ( ! $order ) {
			throw new DST2T_API_Exception( 'Order payload is missing.', 'INVALID_RESPONSE', 200 );
		}
		return $this->validated_order( $order, $expected_id );
	}

	public function batch_orders( $order_ids ) {
		$order_ids = array_values( array_unique( array_filter( array_map( 'strval', (array) $order_ids ) ) ) );
		$order_ids = array_slice( $order_ids, 0, 50 );
		$data = $this->request( 'POST', '/orders/batch', array( 'order_ids' => $order_ids ), array(), 10 );
		$orders = array();
		$seen = array();
		foreach ( $data['orders'] as $remote ) {
			if ( ! is_array( $remote ) || ! isset( $remote['order_id'] ) || ! is_string( $remote['order_id'] ) || ! in_array( $remote['order_id'], $order_ids, true ) || isset( $seen[ $remote['order_id'] ] ) ) { $this->invalid_payload( '/orders/batch', 'invalid_order_identity' ); }
			$orders[] = $this->validated_order( $remote, $remote['order_id'] );
			$seen[ $remote['order_id'] ] = true;
		}
		$not_found = isset( $data['not_found'] ) ? $data['not_found'] : array();
		if ( ! is_array( $not_found ) ) { $this->invalid_payload( '/orders/batch', 'invalid_not_found' ); }
		foreach ( $not_found as $id ) {
			if ( ! is_string( $id ) || ! in_array( $id, $order_ids, true ) || isset( $seen[ $id ] ) ) { $this->invalid_payload( '/orders/batch', 'conflicting_order_identity' ); }
			$seen[ $id ] = true;
		}
		if ( count( $seen ) !== count( $order_ids ) ) { $this->invalid_payload( '/orders/batch', 'incomplete_batch' ); }
		return array( 'orders' => $orders, 'not_found' => $not_found );
	}

	private function extract_order_payload( $payload, $expected_id = '' ) {
		if ( ! is_array( $payload ) ) { return array(); }
		$queue = array( array( $payload, 0 ) );
		$seen = 0;
		while ( $queue && $seen < 40 ) {
			$current = array_shift( $queue );
			$node = $current[0];
			$depth = $current[1];
			++$seen;
			if ( ! is_array( $node ) ) { continue; }

			$id = '';
			foreach ( array( 'order_id', 'orderId', 'client_order_id', 'uuid', 'id' ) as $key ) {
				if ( isset( $node[ $key ] ) && is_scalar( $node[ $key ] ) ) {
					$candidate = trim( (string) $node[ $key ] );
					if ( ( '' !== (string) $expected_id && hash_equals( (string) $expected_id, $candidate ) ) || preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate ) ) {
						$id = $candidate;
						break;
					}
				}
			}
			$status = '';
			foreach ( array( 'status', 'order_status', 'orderStatus', 'state' ) as $key ) {
				if ( isset( $node[ $key ] ) && is_scalar( $node[ $key ] ) ) {
					$status = strtolower( sanitize_key( (string) $node[ $key ] ) );
					break;
				}
			}
			$status_map = array( 'created' => 'pending', 'accepted' => 'pending', 'queued' => 'pending', 'complete' => 'completed', 'refund' => 'refunded' );
			if ( isset( $status_map[ $status ] ) ) { $status = $status_map[ $status ]; }

			if ( $id && $status && in_array( $status, array( 'pending', 'processing', 'retrying', 'partial', 'completed', 'failed', 'refunded' ), true ) ) {
				if ( '' === (string) $expected_id || hash_equals( (string) $expected_id, (string) $id ) || hash_equals( strtolower( (string) $expected_id ), strtolower( (string) $id ) ) ) {
					$order = $node;
					$order['order_id'] = $id;
					$order['status'] = $status;
					return $order;
				}
			}

			if ( $depth >= 4 ) { continue; }
			// Prefer known envelope keys, then inspect other nested objects. Depth/node
			// limits keep a hostile-but-authenticated response from causing deep traversal.
			foreach ( array( 'order', 'data', 'result', 'payload', 'response' ) as $key ) {
				if ( isset( $node[ $key ] ) && is_array( $node[ $key ] ) ) {
					$queue[] = array( $node[ $key ], $depth + 1 );
				}
			}
			foreach ( $node as $key => $value ) {
				if ( in_array( $key, array( 'order', 'data', 'result', 'payload', 'response' ), true ) ) { continue; }
				if ( is_array( $value ) && ! $this->is_list_array( $value ) ) { $queue[] = array( $value, $depth + 1 ); }
			}
		}
		return array();
	}

	private function record_invalid_shape( $path, $reason ) {
		update_option( 'dst2t_response_diagnostic', array( 'at' => time(), 'endpoint' => $this->diagnostic_path( $path ), 'http' => 200, 'format' => sanitize_key( $reason ), 'bytes' => 0 ), false );
	}

	private function validated_order( $order, $expected_id ) {
		if ( ! is_array( $order ) || ! isset( $order['order_id'], $order['status'] ) || ! is_string( $order['order_id'] ) || ! hash_equals( (string) $expected_id, $order['order_id'] ) || ! is_string( $order['status'] ) || ! in_array( $order['status'], array( 'pending', 'processing', 'retrying', 'partial', 'completed', 'failed', 'refunded' ), true ) ) {
			$this->invalid_payload( '/orders/{id}', 'invalid_order_payload' );
		}
		return $order;
	}

	private function invalid_payload( $path, $reason ) {
		update_option( 'dst2t_response_diagnostic', array( 'at' => time(), 'endpoint' => $path, 'http' => 200, 'format' => $reason, 'bytes' => 0 ), false );
		throw new DST2T_API_Exception( 'The provider response has an invalid structure.', 'INVALID_RESPONSE' );
	}

	/**
	 * Read every page when the provider includes its documented pagination envelope.
	 * The first request uses only endpoint-documented query parameters; `page` is
	 * added only after the response explicitly reports more pages.
	 */
	private function list_all( $path, $keys, $query = array() ) {
		$keys = is_array( $keys ) ? array_values( $keys ) : array( (string) $keys );
		$data = $this->request( 'GET', $path, null, $query );
		$rows = $this->extract_list_rows( $data, $keys );
		$pagination = $this->extract_pagination( $data );
		$total_pages = isset( $pagination['total_pages'] ) ? absint( $pagination['total_pages'] ) : 1;
		$current = isset( $pagination['page'] ) ? max( 1, absint( $pagination['page'] ) ) : 1;
		$total_pages = min( 100, max( 1, $total_pages ) );

		for ( $page = $current + 1; $page <= $total_pages; $page++ ) {
			$page_query = $query;
			$page_query['page'] = $page;
			$next = $this->request( 'GET', $path, null, $page_query );
			$more = $this->extract_list_rows( $next, $keys );
			if ( ! $more ) { break; }
			$rows = array_merge( $rows, $more );
		}
		return $rows;
	}

	/** Accept the documented response plus harmless envelope variants used by older deployments. */
	private function extract_list_rows( $data, $keys ) {
		if ( ! is_array( $data ) ) { return array(); }
		$containers = array( $data );
		foreach ( array( 'data', 'result', 'payload' ) as $container_key ) {
			if ( isset( $data[ $container_key ] ) && is_array( $data[ $container_key ] ) ) {
				$containers[] = $data[ $container_key ];
			}
		}
		foreach ( $containers as $container ) {
			foreach ( $keys as $key ) {
				if ( isset( $container[ $key ] ) && is_array( $container[ $key ] ) ) {
					return array_values( $container[ $key ] );
				}
			}
			if ( $this->is_list_array( $container ) ) { return array_values( $container ); }
		}
		$this->invalid_payload( '/catalog', 'missing_catalog_list' );
	}

	private function extract_pagination( $data ) {
		if ( ! is_array( $data ) ) { return array(); }
		if ( isset( $data['pagination'] ) && is_array( $data['pagination'] ) ) { return $data['pagination']; }
		foreach ( array( 'data', 'result', 'payload' ) as $container_key ) {
			if ( isset( $data[ $container_key ]['pagination'] ) && is_array( $data[ $container_key ]['pagination'] ) ) {
				return $data[ $container_key ]['pagination'];
			}
		}
		return array();
	}

	private function is_list_array( $value ) {
		if ( ! is_array( $value ) || array() === $value ) { return false; }
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private function normalize_category_rows( $rows, $big = false ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			if ( $big ) {
				$id = isset( $row['id'] ) ? absint( $row['id'] ) : ( isset( $row['big_category_id'] ) ? absint( $row['big_category_id'] ) : ( isset( $row['bigCategoryId'] ) ? absint( $row['bigCategoryId'] ) : 0 ) );
				$name = isset( $row['name'] ) ? (string) $row['name'] : ( isset( $row['big_category_name'] ) ? (string) $row['big_category_name'] : ( isset( $row['bigCategoryName'] ) ? (string) $row['bigCategoryName'] : '' ) );
			} else {
				$id = isset( $row['id'] ) ? absint( $row['id'] ) : ( isset( $row['category_id'] ) ? absint( $row['category_id'] ) : ( isset( $row['categoryId'] ) ? absint( $row['categoryId'] ) : 0 ) );
				$name = isset( $row['name'] ) ? (string) $row['name'] : ( isset( $row['category_name'] ) ? (string) $row['category_name'] : ( isset( $row['categoryName'] ) ? (string) $row['categoryName'] : '' ) );
			}
			if ( ! $id ) { continue; }
			$row['id'] = $id;
			if ( '' !== trim( $name ) ) { $row['name'] = $name; }
			if ( ! $big ) {
				if ( ! isset( $row['big_category_id'] ) && isset( $row['bigCategoryId'] ) ) { $row['big_category_id'] = absint( $row['bigCategoryId'] ); }
				if ( ! isset( $row['big_category_name'] ) && isset( $row['bigCategoryName'] ) ) { $row['big_category_name'] = $row['bigCategoryName']; }
			}
			$out[ $id ] = $row;
		}
		return array_values( $out );
	}

	private function derive_categories_from_items( $items, $big_category_id = 0 ) {
		$categories = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$id = isset( $item['category_id'] ) ? absint( $item['category_id'] ) : ( isset( $item['categoryId'] ) ? absint( $item['categoryId'] ) : 0 );
			if ( ! $id ) { continue; }
			$item_big = isset( $item['big_category_id'] ) ? absint( $item['big_category_id'] ) : ( isset( $item['bigCategoryId'] ) ? absint( $item['bigCategoryId'] ) : 0 );
			if ( $big_category_id && $item_big && $item_big !== absint( $big_category_id ) ) { continue; }
			if ( isset( $categories[ $id ] ) ) { continue; }
			$categories[ $id ] = array(
				'id' => $id,
				'name' => isset( $item['category_name'] ) ? (string) $item['category_name'] : ( isset( $item['categoryName'] ) ? (string) $item['categoryName'] : sprintf( 'Category %d', $id ) ),
				'big_category_id' => $item_big,
				'big_category_name' => isset( $item['big_category_name'] ) ? (string) $item['big_category_name'] : '',
			);
		}
		return array_values( $categories );
	}

	private function request( $method, $path, $body = null, $query = array(), $timeout = 20 ) {
		$auth = $this->auth_headers();
		if ( ! $auth ) { throw new DST2T_API_Exception( 'The complete keyId.secret API credential is not configured.', 'MISSING_API_KEY', 401 ); }
		if ( ! preg_match( '#^/[A-Za-z0-9_./:-]+$#', $path ) ) { throw new DST2T_API_Exception( 'Unsafe API path rejected.', 'CLIENT_PATH_REJECTED' ); }

		$url = self::BASE_URL . $path;
		if ( $query ) { $url = add_query_arg( $query, $url ); }
		$args = array(
			'method' => strtoupper( (string) $method ), 'timeout' => max( 3, min( 20, absint( $timeout ) ) ), 'redirection' => 0, 'sslverify' => true,
			'limit_response_size' => 4194304,
			'headers' => array_merge( $auth, array( 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'User-Agent' => 'Delicat-TopUp/' . DST2T_VERSION ) ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
			if ( false === $args['body'] ) { throw new DST2T_API_Exception( 'The request body could not be encoded.', 'CLIENT_JSON_ERROR' ); }
		}

		if ( '/player/validate' === $path ) {
			$args['headers']['Cache-Control'] = 'no-cache, no-store, max-age=0';
			$args['headers']['Pragma'] = 'no-cache';
		}

		$started = microtime( true );
		$response = wp_safe_remote_request( $url, $args );
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $response ) ) {
			$this->log( 'warning', 'API transport error.', array( 'path' => $path, 'error' => $response->get_error_code(), 'ms' => $elapsed ) );
			$retry_hint = 20;
			if ( '/orders/create' === $path || '/player/validate' === $path || 0 === strpos( $path, '/catalog/subcategory/' ) ) {
				$retry_hint = 3;
			}
			throw new DST2T_API_Exception( $response->get_error_message(), 'TRANSPORT_ERROR', 0, $retry_hint );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw = (string) wp_remote_retrieve_body( $response );
		$retry_after = absint( wp_remote_retrieve_header( $response, 'retry-after' ) );
		$data = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $raw ), true );
		if ( ! is_array( $data ) ) {
			$content_type = sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
			$details = array(
				'content_type' => $content_type,
				'body_bytes'   => strlen( $raw ),
				'body_sha256'  => hash( 'sha256', $raw ),
			);
			$this->log( 'warning', 'API returned non-JSON response.', array( 'path' => $path, 'http' => $status, 'content_type' => $content_type, 'bytes' => strlen( $raw ), 'ms' => $elapsed ) );
			// Keep only safe response metadata; never persist bodies, credentials or player data.
			update_option( 'dst2t_response_diagnostic', array( 'at' => time(), 'endpoint' => $this->diagnostic_path( $path ), 'http' => $status, 'format' => 'non_json', 'bytes' => strlen( $raw ) ), false );
			$code = 429 === $status ? 'RATE_LIMIT_EXCEEDED' : ( in_array( $status, array( 401, 403 ), true ) ? 'HTTP_' . $status : 'INVALID_RESPONSE' );
			throw new DST2T_API_Exception( 'The provider returned invalid JSON.', $code, $status, $retry_after, $details );
		}

		if ( $status < 200 || $status >= 300 || ! isset( $data['success'] ) || true !== $data['success'] ) {
			$error = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
			$code = ! empty( $error['code'] ) && is_string( $error['code'] ) ? strtoupper( sanitize_key( $error['code'] ) ) : ( $status >= 200 && $status < 300 ? 'INVALID_RESPONSE' : 'HTTP_' . $status );
			if ( Delicat_Player_Region_V2::is_region_error( $code ) ) { $code = 'REGION_MISMATCH'; }
			$message = ! empty( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : 'The provider request failed.';
			$details = isset( $error['details'] ) && is_array( $error['details'] ) ? $error['details'] : array();
			if ( 'REGION_MISMATCH' === $code ) {
				$actual_region = Delicat_Player_Region_V2::error_region( $details );
				if ( ! $actual_region ) { $actual_region = Delicat_Player_Region_V2::error_region( $error ); }
				$details['account_region'] = $actual_region;
			}
			if ( array_key_exists( 'retryable', $error ) ) { $details['_retryable'] = (bool) $error['retryable']; }
			if ( ! empty( $error['action'] ) && is_scalar( $error['action'] ) ) { $details['_action'] = sanitize_key( (string) $error['action'] ); }
			if ( ! $retry_after && ! empty( $error['retry_after'] ) ) { $retry_after = absint( $error['retry_after'] ); }
			$this->log( 'warning', 'API request rejected.', array( 'path' => $path, 'http' => $status, 'code' => $code, 'ms' => $elapsed ) );
			throw new DST2T_API_Exception( $message, $code, $status, $retry_after, $details );
		}
		// Accept the documented named object first, then harmless envelope variants.
		// Some live reseller deployments return the object itself inside data/result/payload
		// instead of nesting it one level deeper (for example data.unit_price rather than
		// data.price.unit_price). Normalize those successful JSON shapes here without ever
		// treating an error envelope as success.
		foreach ( array( 'data', 'result', 'payload' ) as $wrapper ) {
			if ( isset( $data[ $wrapper ] ) && is_array( $data[ $wrapper ] ) ) {
				foreach ( array( 'price', 'account', 'order', 'orders', 'not_found', 'requirements' ) as $key ) {
					if ( ! array_key_exists( $key, $data ) && isset( $data[ $wrapper ][ $key ] ) && is_array( $data[ $wrapper ][ $key ] ) ) {
						$data[ $key ] = $data[ $wrapper ][ $key ];
					}
				}
			}
		}

		$expected = '/account' === $path ? 'account' : ( substr( $path, -6 ) === '/price' ? 'price' : ( '/orders/batch' === $path ? 'orders' : ( 0 === strpos( $path, '/orders/' ) ? 'order' : '' ) ) );
		if ( $expected ) {
			$data = $this->normalize_named_response_object( $data, $expected );
		}
		if ( $expected && ( ! isset( $data[ $expected ] ) || ! is_array( $data[ $expected ] ) ) ) {
			update_option( 'dst2t_response_diagnostic', array( 'at' => time(), 'endpoint' => $this->diagnostic_path( $path ), 'http' => $status, 'format' => 'missing_' . $expected, 'bytes' => strlen( $raw ) ), false );
			throw new DST2T_API_Exception( 'Required response object is missing.', 'INVALID_RESPONSE', $status );
		}
		$this->clear_response_diagnostic( $path );
		$this->log( 'debug', 'API request completed.', array( 'path' => $path, 'http' => $status, 'ms' => $elapsed ) );
		return $data;
	}


	/**
	 * Promote successful flat response objects into the documented named envelope.
	 *
	 * The public docs show {success:true, price:{...}} and {success:true, order:{...}},
	 * but the live API can legitimately return the object directly in data/result/payload.
	 * Only endpoint-specific sentinel keys are accepted, so unrelated successful JSON
	 * can never be misclassified as a valid price/account/order response.
	 */
	private function normalize_named_response_object( $data, $expected ) {
		if ( ! is_array( $data ) ) { return $data; }
		if ( isset( $data[ $expected ] ) && is_array( $data[ $expected ] ) ) { return $data; }

		$sentinels = array(
			'price'   => array( 'unit_price', 'item_id', 'currency' ),
			'account' => array( 'balance', 'wallet', 'wallet_balance', 'enabled', 'verified' ),
			'order'   => array( 'order_id', 'status' ),
		);

		if ( 'orders' === $expected ) {
			foreach ( array( 'data', 'result', 'payload' ) as $wrapper ) {
				if ( isset( $data[ $wrapper ] ) && is_array( $data[ $wrapper ] ) && isset( $data[ $wrapper ]['orders'] ) && is_array( $data[ $wrapper ]['orders'] ) ) {
					$data['orders'] = $data[ $wrapper ]['orders'];
					if ( ! isset( $data['not_found'] ) && isset( $data[ $wrapper ]['not_found'] ) && is_array( $data[ $wrapper ]['not_found'] ) ) {
						$data['not_found'] = $data[ $wrapper ]['not_found'];
					}
					return $data;
				}
			}
			return $data;
		}

		if ( ! isset( $sentinels[ $expected ] ) ) { return $data; }

		if ( 'order' === $expected ) {
			$found = $this->extract_order_payload( $data );
			if ( $found ) {
				$data['order'] = $found;
				return $data;
			}
		}

		$candidates = array();
		foreach ( array( 'data', 'result', 'payload' ) as $wrapper ) {
			if ( isset( $data[ $wrapper ] ) && is_array( $data[ $wrapper ] ) ) {
				$candidates[] = $data[ $wrapper ];
			}
		}
		$candidates[] = $data;

		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) { continue; }

			// A few price deployments have emitted price as the scalar amount itself.
			if ( 'price' === $expected && isset( $candidate['price'] ) && is_scalar( $candidate['price'] ) && ! isset( $candidate['unit_price'] ) ) {
				$candidate['unit_price'] = $candidate['price'];
			}

			$matched = 0;
			foreach ( $sentinels[ $expected ] as $key ) {
				if ( array_key_exists( $key, $candidate ) ) { ++$matched; }
			}
			// Price requires unit_price (or scalar price normalized above). Order requires
			// both identity and status. Account needs any recognized account-state field.
			$valid = 'price' === $expected
				? isset( $candidate['unit_price'] ) && is_scalar( $candidate['unit_price'] )
				: ( 'order' === $expected ? isset( $candidate['order_id'], $candidate['status'] ) : $matched > 0 );
			if ( $valid ) {
				$copy = $candidate;
				unset( $copy['success'], $copy['error'] );
				$data[ $expected ] = $copy;
				return $data;
			}
		}
		return $data;
	}

	private function diagnostic_path( $path ) {
		return preg_replace( '#/orders/(?!create$|batch$)[^/]+$#', '/orders/{id}', (string) $path );
	}

	/** Clear a stale "last invalid response" banner once that exact endpoint succeeds. */
	private function clear_response_diagnostic( $path ) {
		$diagnostic = get_option( 'dst2t_response_diagnostic', array() );
		if ( ! is_array( $diagnostic ) || empty( $diagnostic['endpoint'] ) ) { return; }
		if ( hash_equals( (string) $diagnostic['endpoint'], (string) $this->diagnostic_path( $path ) ) ) {
			delete_option( 'dst2t_response_diagnostic' );
		}
	}

	private function log( $level, $message, $context = array() ) {
		if ( ! $this->settings->enabled( 'debug_logging' ) || ! function_exists( 'wc_get_logger' ) ) { return; }
		$context['source'] = 'delicat-topup';
		wc_get_logger()->log( $level, $message, $context );
	}
}
