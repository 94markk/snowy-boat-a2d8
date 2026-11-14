<?php

defined( 'ABSPATH' ) || exit;

final class DST2T_Fulfillment {
	const ACTION_WEBHOOK = 'dst2t_webhook_reconcile';
	const ACTION_FAST    = 'dst2t_fast_fulfill';
	const ORDER_LOCK_TTL = 60;
	const WEBHOOK_LOCK_WAIT_MS = 1500;
	private $held_locks = array();
	private $post_response_items = array();
	private $internal_fast_active = false;
	const ACTION_PROCESS   = 'dst2t_process_item';
	const ACTION_RECONCILE = 'dst2t_reconcile';
	const ACTION_GROUP     = 'delicat-shop2topup';

	/** @var DST2T_API_Client */
	private $api;

	/** @var DST2T_Settings */
	private $settings;

	/** @var DST2T_Repository */
	private $repository;

	/** @var DST2T_Product */
	private $products;

	/** @var DST2T_Vault */
	private $vault;

	public function __construct( DST2T_API_Client $api, DST2T_Settings $settings, DST2T_Repository $repository, DST2T_Product $products, DST2T_Vault $vault ) {
		$this->api        = $api;
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->products   = $products;
		$this->vault      = $vault;
	}

	public function hooks() {
		add_action( 'woocommerce_payment_complete', array( $this, 'mark_payment_approved' ), 5 );
		add_action( 'woocommerce_payment_complete', array( $this, 'queue_order' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'queue_order' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'queue_order' ), 20 );
		add_filter( 'woocommerce_order_item_needs_processing', array( $this, 'mapped_item_needs_processing' ), 10, 3 );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'paid_order_status' ), 20, 3 );
		add_action( self::ACTION_WEBHOOK, array( $this, 'process_webhook' ), 10, 2 );
		add_action( self::ACTION_PROCESS, array( $this, 'process_item' ), 10, 2 );
		add_action( self::ACTION_RECONCILE, array( $this, 'reconcile' ) );
		add_action( 'wp_ajax_' . self::ACTION_FAST, array( $this, 'ajax_fast_fulfill' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION_FAST, array( $this, 'ajax_fast_fulfill' ) );
		add_action( 'shutdown', array( $this, 'run_post_response_fastlane' ), PHP_INT_MAX - 20 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_item_meta' ) );
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render_vouchers' ), 10, 4 );
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ) );
		add_action( 'woocommerce_order_action_dst2t_sync', array( $this, 'manual_order_sync' ) );
	}

	public function mark_payment_approved( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) { return; }
		// This hook is emitted by WooCommerce's payment_complete() flow. Persist a
		// durable release marker so supplier fulfillment can never be triggered by a
		// mere status edit or by the supplier itself.
		$order->update_meta_data( '_dst2t_wc_payment_approved', 'yes' );
		$order->update_meta_data( '_dst2t_wc_payment_approved_at', time() );
		if ( method_exists( $order, 'get_transaction_id' ) ) {
			$transaction_id = sanitize_text_field( (string) $order->get_transaction_id() );
			if ( '' !== $transaction_id ) { $order->update_meta_data( '_dst2t_wc_transaction_id', $transaction_id ); }
		}
		// Bind WooCommerce's payment approval to the immutable facts that can spend
		// supplier balance. If an order line, quantity, recipient, total or transaction
		// is altered after payment, the HMAC no longer matches and fulfillment fails closed.
		$order->update_meta_data( '_dst2t_payment_attestation_v', '1' );
		$order->update_meta_data( '_dst2t_payment_attestation_sig', $this->payment_attestation_signature( $order ) );
		$order->save();
	}

	public function queue_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $this->payment_confirmed( $order ) ) {
			return;
		}

		$lock = $this->acquire_lock( 'order-' . $order->get_id(), self::ORDER_LOCK_TTL );
		if ( ! $lock ) {
			return;
		}

		$fast_items = array();
		try {
			$has_mapped        = false;
			$all_mapped_done   = true;
			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				$supplier_item_id = absint( $item->get_meta( '_dst2t_item_id', true ) );
				if ( ! $supplier_item_id ) {
					continue;
				}
				$has_mapped = true;

				$provider_order_id = (string) $item->get_meta( '_dst2t_order_id', true );
				if ( ! $provider_order_id ) {
					$provider_order_id = wp_generate_uuid4();
					$requirements      = $this->item_requirements( $item );
					$payload_hash      = hash( 'sha256', wp_json_encode( array( $supplier_item_id, $item->get_quantity(), $requirements ) ) );

					// Persist the idempotency key before any supplier request.
					$item->update_meta_data( '_dst2t_order_id', $provider_order_id );
					$item->update_meta_data( '_dst2t_status', 'intent' );
					$item->save();
					$this->repository->register_intent( $provider_order_id, $order->get_id(), $item_id, $payload_hash );
				}

				$status = sanitize_key( (string) $item->get_meta( '_dst2t_status', true ) );
				if ( ! in_array( $status, $this->terminal_statuses(), true ) ) {
					$all_mapped_done = false;
					// Action Scheduler remains the durable safety net, but it is no longer
					// the first-line runner. The fast lane is dispatched after this order
					// lock is released so concurrent customers are not serialized behind it.
					$this->schedule_item( $order->get_id(), $item_id, 0 );
					$fast_items[] = absint( $item_id );
				}
			}
			if ( $has_mapped && ! $all_mapped_done && $order->has_status( 'completed' ) ) {
				$order->update_status( 'processing', sprintf( /* translators: %s: neutral provider label. */ __( 'Paid order is waiting for verified %s delivery.', 'delicat-shop2topup' ), DST2T_Brand::label() ) );
			}
		} finally {
			$this->release_lock( 'order-' . $order->get_id(), $lock );
		}

		// Start each line independently after the local order lock is free. This
		// avoids Action Scheduler queue starvation on busy stores while keeping the
		// same UUID/idempotency and payment-attestation gates inside process_item().
		foreach ( array_values( array_unique( $fast_items ) ) as $item_id ) {
			$this->dispatch_fast_item( $order->get_id(), $item_id );
		}
	}

	public function mapped_item_needs_processing( $needs_processing, $product, $order_id ) {
		if ( $product instanceof WC_Product && $this->products->mapped_product_id( $product->get_parent_id() ?: $product->get_id(), $product->is_type( 'variation' ) ? $product->get_id() : 0 ) ) {
			return true;
		}
		return $needs_processing;
	}

	public function paid_order_status( $status, $order_id, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return $status;
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item->get_meta( '_dst2t_item_id', true ) ) {
				return 'processing';
			}
		}
		return $status;
	}

	public function process_item( $order_id, $item_id ) {
		$order_id = absint( $order_id );
		$item_id  = absint( $item_id );
		$lock     = $this->acquire_lock( 'order-' . $order_id, self::ORDER_LOCK_TTL );
		if ( ! $lock ) {
			return;
		}

		try {
			$order = wc_get_order( $order_id );
			$item  = $order ? $order->get_item( $item_id ) : false;
			if ( ! $order || ! $item instanceof WC_Order_Item_Product || ! $this->payment_confirmed( $order ) ) {
				return;
			}

			$provider_order_id = (string) $item->get_meta( '_dst2t_order_id', true );
			$supplier_item_id  = absint( $item->get_meta( '_dst2t_item_id', true ) );
			if ( class_exists( 'Delicat_Custom_Packages', false ) && Delicat_Custom_Packages::recipe( $item->get_variation_id() ) ) {
				$this->mark_manual_review( $order, $item, $provider_order_id, 'manual_review', 'CUSTOM_PACKAGE_UNSPLIT', 'Custom package requires a compatible checkout before delivery.' );
				return;
			}
			if ( ! $provider_order_id || ! $supplier_item_id ) {
				return;
			}

			$row = $this->repository->get( $provider_order_id );
			if ( ! $row ) {
				$this->repository->register_intent(
					$provider_order_id,
					$order_id,
					$item_id,
					hash( 'sha256', wp_json_encode( array( $supplier_item_id, $item->get_quantity(), $this->item_requirements( $item ) ) ) )
				);
				$row = $this->repository->get( $provider_order_id );
			}
			if ( ! $row ) {
				$this->mark_manual_review( $order, $item, $provider_order_id, 'manual_review', 'STATE_STORAGE_ERROR', __( 'Supplier fulfillment did not start because the local idempotency record could not be stored.', 'delicat-shop2topup' ) );
				return;
			}

			$status   = sanitize_key( $row['status'] );
			$attempts = absint( $row['attempt_count'] );
			if ( in_array( $status, $this->terminal_statuses(), true ) ) {
				return;
			}
			if ( ! empty( $row['next_check_at'] ) && strtotime( $row['next_check_at'] . ' UTC' ) > time() ) {
				return;
			}
			// Once accepted, a scheduled worker may only read; never recreate.
			if ( 'yes' === $item->get_meta( '_dst2t_accepted', true ) || in_array( $status, array( 'pending', 'processing', 'retrying', 'partial' ), true ) ) {
				try {
					$this->apply_provider_order( $provider_order_id, $this->api->order( $provider_order_id ), 'status' );
				} catch ( DST2T_API_Exception $status_error ) {
					// Back off but keep the accepted status: the supplier order exists.
					$delay = max( $status_error->get_retry_after(), $this->retry_delay( $attempts ) );
					$this->repository->update( $provider_order_id, $status, array( 'next_check_at' => time() + $delay, 'last_error_code' => $status_error->get_api_code() ) );
					$this->schedule_item( $order_id, $item_id, $delay );
				}
				return;
			}
			$current_payload_hash = hash( 'sha256', wp_json_encode( array( $supplier_item_id, $item->get_quantity(), $this->item_requirements( $item ) ) ) );
			if ( $row && ! empty( $row['payload_hash'] ) && ! hash_equals( (string) $row['payload_hash'], $current_payload_hash ) ) {
				$this->mark_manual_review( $order, $item, $provider_order_id, 'manual_review', 'PAYLOAD_CHANGED', __( 'The mapped item, quantity, or delivery details changed after the supplier intent was created. Fulfillment stopped to protect idempotency.', 'delicat-shop2topup' ) );
				return;
			}

			// A previous create may have succeeded after our HTTP connection timed out.
			if ( in_array( $status, array( 'submitting', 'unknown' ), true ) ) {
				try {
					$remote = $this->api->order( $provider_order_id );
					$this->apply_provider_order( $provider_order_id, $remote, 'recovery' );
					return;
				} catch ( DST2T_API_Exception $lookup_error ) {
					if ( 'ORDER_NOT_FOUND' !== $lookup_error->get_api_code() ) {
						$this->repository->update( $provider_order_id, $status, array( 'increment_attempt' => true ) );
						$this->handle_temporary_or_failure( $order, $item, $provider_order_id, $lookup_error, $attempts + 1 );
						return;
					}
				}
			}

			// WooCommerce is the payment authority. Player validation happens only after
			// payment approval unless it was already verified with the storefront button.
			// A supplier outage can therefore never prevent checkout or payment capture.
			if ( $this->settings->enabled( 'validate_player' ) && 'yes' !== $item->get_meta( '_dst2t_returns_voucher', true ) && 'yes' !== $item->get_meta( '_dst2t_player_validated', true ) ) {
				$requirements = $this->item_requirements( $item );
				if ( $this->has_player_identifier( $requirements ) ) {
					try {
						$player = $this->api->validate_player( $supplier_item_id, $requirements, get_the_title( $item->get_product_id() ) );
						$item->update_meta_data( '_dst2t_player_validated', 'yes' );
						if ( ! empty( $player['player_name'] ) ) {
							$name = sanitize_text_field( $player['player_name'] );
							$item->update_meta_data( '_dst2t_player_name', $name );
							if ( ! $item->get_meta( __( 'Nom du joueur', 'delicat-shop2topup' ), true ) ) {
								$item->add_meta_data( __( 'Nom du joueur', 'delicat-shop2topup' ), $name, false );
							}
						}
						$item->save();
					} catch ( DST2T_API_Exception $player_error ) {
						$this->handle_preflight_failure( $order, $item, $provider_order_id, $player_error, $attempts );
						return;
					}
				}
			}

			$price = null;
			try {
				$price = $this->api->price( $supplier_item_id );
				$this->assert_cost_guard( $item, $price['unit_price'] );
			} catch ( DST2T_API_Exception $price_error ) {
				$cached_cost = $this->cached_expected_cost( $item );
				$fallback_source = 'cached';
				if ( ! $cached_cost && $this->can_fallback_to_cached_price( $price_error ) ) {
					try {
						$category_id = absint( $item->get_meta( '_dst2t_category_id', true ) );
						$cached_cost = $this->api->catalog_price( $supplier_item_id, $category_id );
						$fallback_source = 'catalog';
						$this->remember_supplier_cost( $item, $cached_cost );
					} catch ( Throwable $catalog_error ) {
						$cached_cost = '';
					}
				}
				if ( $cached_cost && $this->can_fallback_to_cached_price( $price_error ) ) {
					// expected_unit_price is a supplier-side upper bound. Using the last
					// known/catalog cost is safe: if the live cost rose, /orders/create refuses
					// with PRICE_INCREASED and tells us the current price; it cannot overcharge.
					$price = array( 'unit_price' => $cached_cost );
					$this->assert_cost_guard( $item, $cached_cost );
					$note = 'catalog' === $fallback_source
						? __( 'The dedicated supplier price response was temporarily incompatible. Fulfillment used the current catalog price as a strict maximum; the supplier cannot charge above it.', 'delicat-shop2topup' )
						: __( 'Live supplier price lookup was temporarily unavailable. Fulfillment continued with the last known price as a strict maximum; the supplier cannot charge above it.', 'delicat-shop2topup' );
					$this->order_note_once( $order, 'cached-price-' . $item_id, $note );
				} else {
					$this->handle_preflight_failure( $order, $item, $provider_order_id, $price_error, $attempts );
					return;
				}
			} catch ( RuntimeException $guard_error ) {
				$this->mark_manual_review( $order, $item, $provider_order_id, 'price_blocked', 'PRICE_GUARD', $guard_error->getMessage() );
				return;
			}

			$stored = $this->repository->update(
				$provider_order_id,
				'submitting',
				array( 'increment_attempt' => true, 'next_check_at' => time() + 30, 'last_error_code' => '' )
			);
			if ( ! $stored ) { throw new RuntimeException( 'Cannot persist submission state.' ); }
			$item->update_meta_data( '_dst2t_status', 'submitting' );
			$item->save();

			try {
				$remote = $this->api->create_order(
					$provider_order_id,
					$supplier_item_id,
					max( 1, absint( $item->get_quantity() ) ),
					$this->item_requirements( $item ),
					$price['unit_price']
				);
				$this->apply_provider_order( $provider_order_id, $remote, 'create' );
				// The wallet was just debited; refresh the cached balance out of band.
				if ( class_exists( 'DST2T_Balance' ) ) {
					DST2T_Balance::queue_refresh();
				}
			} catch ( DST2T_API_Exception $error ) {
				if ( 'DUPLICATE_ORDER' === $error->get_api_code() ) {
					try {
						$this->apply_provider_order( $provider_order_id, $this->api->order( $provider_order_id ), 'duplicate-recovery' );
						return;
					} catch ( DST2T_API_Exception $lookup_error ) {
						$error = $lookup_error;
					}
				}
				if ( 'PRICE_INCREASED' === $error->get_api_code() ) {
					if ( $attempts + 1 >= 4 ) {
						$this->mark_manual_review( $order, $item, $provider_order_id, 'price_blocked', 'PRICE_INCREASED', __( 'Supplier price changed repeatedly. No supplier purchase was made.', 'delicat-shop2topup' ) );
						return;
					}
					$delay   = 20;
					$details = $error->get_details();
					$current = isset( $details['current_unit_price'] ) && is_scalar( $details['current_unit_price'] ) ? (string) $details['current_unit_price'] : '';
					if ( '' !== $current ) {
						try {
							$current = DST2T_Decimal::normalize( $current );
							$this->assert_cost_guard( $item, $current );
							$this->remember_supplier_cost( $item, $current );
							$delay = 2;
						} catch ( Throwable $price_guard_error ) {
							$this->mark_manual_review( $order, $item, $provider_order_id, 'price_blocked', 'PRICE_GUARD', $price_guard_error->getMessage() );
							return;
						}
					}
					$this->repository->update( $provider_order_id, 'intent', array( 'next_check_at' => time() + $delay, 'last_error_code' => $error->get_api_code() ) );
					$item->update_meta_data( '_dst2t_status', 'intent' );
					$item->save();
					$this->schedule_item( $order_id, $item_id, $delay );
					return;
				}
				$this->handle_temporary_or_failure( $order, $item, $provider_order_id, $error, $attempts + 1, true );
			}
		} catch ( Throwable $error ) {
			$this->log( 'error', 'Unexpected fulfillment exception.', array( 'order_id' => $order_id, 'item_id' => $item_id, 'type' => get_class( $error ) ) );
		} finally {
			$this->release_lock( 'order-' . $order_id, $lock );
		}
	}

	/** Apply a HMAC-authenticated supplier callback immediately, without a second API read. */
	public function apply_signed_webhook( $provider_order_id, $payload, $event_timestamp = 0 ) {
		$remote = $this->api->normalize_order_payload( $payload, $provider_order_id );
		return $this->apply_provider_order( $provider_order_id, $remote, 'webhook-signed', absint( $event_timestamp ) );
	}

	public function apply_provider_order( $provider_order_id, $remote, $source = 'status', $event_timestamp = 0 ) {
		$row = $this->repository->get( $provider_order_id );
		if ( ! $row ) { return false; }
		$key = 'order-' . absint( $row['wc_order_id'] );
		// A very fast supplier can deliver the signed completion callback before the
		// create-order worker has released its local order lock. Waiting briefly here
		// prevents that authenticated callback from falling into a slow queue solely
		// because of a local race. No supplier network I/O happens while waiting.
		$lock = 'webhook-signed' === $source
			? $this->acquire_lock_wait( $key, self::ORDER_LOCK_TTL, self::WEBHOOK_LOCK_WAIT_MS )
			: $this->acquire_lock( $key, self::ORDER_LOCK_TTL );
		if ( ! $lock ) { return false; }
		try {
			return $this->apply_locked( $provider_order_id, $remote, $source, $event_timestamp );
		} finally {
			$this->release_lock( $key, $lock );
		}
	}

	private function apply_locked( $provider_order_id, $remote, $source, $event_timestamp ) {
		if ( ! is_array( $remote ) || empty( $remote['order_id'] ) || ! hash_equals( (string) $provider_order_id, (string) $remote['order_id'] ) ) {
			throw new InvalidArgumentException( 'Supplier order identity mismatch.' );
		}

		$row = $this->repository->get( $provider_order_id );
		if ( ! $row ) {
			$this->log( 'warning', 'Ignoring an unmatched supplier order.', array( 'provider_order_id' => $provider_order_id, 'source_name' => $source ) );
			return false;
		}

		$order = wc_get_order( absint( $row['wc_order_id'] ) );
		$item  = $order ? $order->get_item( absint( $row['wc_item_id'] ) ) : false;
		if ( ! $order || ! $item instanceof WC_Order_Item_Product ) {
			return false;
		}

		if ( $event_timestamp && $this->repository->is_stale_event( $provider_order_id, $event_timestamp ) ) { return true; }

		$status = isset( $remote['status'] ) ? sanitize_key( $remote['status'] ) : 'unknown';
		if ( ! in_array( $status, array( 'pending', 'processing', 'retrying', 'completed', 'partial', 'failed', 'refunded' ), true ) ) {
			throw new DST2T_API_Exception( 'Unrecognized supplier order status.', 'INVALID_RESPONSE' );
		}
		$item->update_meta_data( '_dst2t_accepted', 'yes' );
		$item->save();

		$product_id = absint( $item->get_meta( '_dst2t_product_id', true ) );
		if ( ! $product_id ) {
			$product_id = $item->get_variation_id() ?: $item->get_product_id();
		}
		$voucher_snapshot = $item->get_meta( '_dst2t_returns_voucher', true );
		$expects_voucher = '' !== $voucher_snapshot ? 'yes' === $voucher_snapshot : $this->products->mapping( $product_id )['returns_voucher'];
		if ( 'completed' === $status && $expects_voucher && empty( $remote['vouchers'] ) ) {
			try {
				$remote = $this->api->order( $provider_order_id );
			} catch ( DST2T_API_Exception $detail_error ) {
				$this->repository->update( $provider_order_id, 'pending', array( 'next_check_at' => time() + max( 20, $detail_error->get_retry_after() ), 'last_error_code' => $detail_error->get_api_code() ) );
				$item->update_meta_data( '_dst2t_status', 'pending' );
				$item->save();
				$this->schedule_item( $order->get_id(), $item->get_id(), max( 20, $detail_error->get_retry_after() ) );
				return false;
			}
			if ( empty( $remote['order_id'] ) || ! hash_equals( $provider_order_id, (string) $remote['order_id'] ) || 'completed' !== ( $remote['status'] ?? '' ) ) {
				return false;
			}
			if ( empty( $remote['vouchers'] ) ) {
				$this->mark_manual_review( $order, $item, $provider_order_id, 'voucher_missing', 'VOUCHER_MISSING', __( 'Supplier marked the voucher order complete without returning a voucher code.', 'delicat-shop2topup' ) );
				return false;
			}
		}

		$current = sanitize_key( (string) $item->get_meta( '_dst2t_status', true ) );
		if ( 'completed' === $current && in_array( $status, array( 'pending', 'processing', 'retrying', 'unknown' ), true ) ) {
			return true;
		}
		if ( 'refunded' === $current && 'refunded' !== $status ) {
			return true;
		}

		$item->update_meta_data( '_dst2t_status', $status );
		if ( isset( $remote['charged_amount'] ) ) {
			try {
				$item->update_meta_data( '_dst2t_charged_amount', DST2T_Decimal::normalize( $remote['charged_amount'] ) );
			} catch ( Throwable $error ) {
				// Ignore malformed optional accounting metadata.
			}
		}
		if ( ! empty( $remote['player_name'] ) ) {
			$item->update_meta_data( '_dst2t_player_name', sanitize_text_field( $remote['player_name'] ) );
		}

		if ( ! empty( $remote['vouchers'] ) && is_array( $remote['vouchers'] ) ) {
			$vouchers  = $this->sanitize_vouchers( $remote['vouchers'] );
			if ( ! $vouchers && $expects_voucher ) {
				$this->mark_manual_review( $order, $item, $provider_order_id, 'voucher_missing', 'VOUCHER_MISSING', 'No usable voucher codes were returned.' );
				return false;
			}
			$encrypted = $this->vault->encrypt( wp_json_encode( $vouchers ) );
			if ( is_wp_error( $encrypted ) ) {
				$this->mark_manual_review( $order, $item, $provider_order_id, 'voucher_storage_error', 'VOUCHER_STORAGE_ERROR', __( 'Voucher delivery was completed, but the codes could not be stored securely.', 'delicat-shop2topup' ) );
				return false;
			}
			$item->update_meta_data( '_dst2t_vouchers', $encrypted );
		}
		$item->save();

		$terminal = in_array( $status, $this->terminal_statuses(), true );
		$args     = array(
			// Webhooks are the primary real-time path. This 20-second read is only a
			// bounded fallback and stays within the documented 3 status reads / 60s / order.
			'next_check_at'  => $terminal ? null : time() + 20,
			'last_error_code' => '',
		);
		// Only ever advance the staleness watermark: an out-of-order delivery must
		// not move it back and re-open the window for an older replay.
		$previous_event = ! empty( $row['last_event_at'] ) ? strtotime( $row['last_event_at'] . ' UTC' ) : 0;
		if ( $event_timestamp && $event_timestamp > $previous_event ) {
			$args['last_event_at'] = $event_timestamp;
		}
		$this->repository->update( $provider_order_id, $status, $args );
		$this->evaluate_order( $order );
		if ( ! $terminal ) {
			$this->schedule_item( $order->get_id(), $item->get_id(), 20 );
		}
		return true;
	}

	public function queue_webhook( $uuid, $event_timestamp = 0 ) {
		if ( ! DST2T_Scheduler::enqueue( self::ACTION_WEBHOOK, array( (string) $uuid, absint( $event_timestamp ) ) ) ) {
			throw new RuntimeException( 'Webhook queue unavailable.' );
		}
	}

	public function process_webhook( $uuid, $event_timestamp = 0 ) {
		$row = $this->repository->get( $uuid );
		if ( ! $row ) { return; }
		// Terminal rows also need reconciliation when a later refund arrives. The
		// callback timestamp is carried through so a delivery that overtakes an
		// earlier one cannot be applied twice or out of order.
		try {
			if ( ! $this->apply_provider_order( $uuid, $this->api->order( $uuid ), 'webhook-reconciliation', absint( $event_timestamp ) ) ) {
				throw new RuntimeException( 'Settlement deferred.' );
			}
		} catch ( Throwable $error ) {
			$args = array( (string) $uuid, absint( $event_timestamp ) );
			$delay = $error instanceof DST2T_API_Exception ? max( 60, $error->get_retry_after() ) : 60;
			DST2T_Scheduler::enqueue( self::ACTION_WEBHOOK, $args, $delay );
		}
	}

	public function reconcile( $run_worker = false ) {
		if ( ! $this->settings->credentials_configured() ) { return; }
		$lock = $this->acquire_lock( 'reconcile', 300 );
		if ( ! $lock ) {
			return;
		}

		$summary = array( 'at' => time(), 'queued' => 0, 'updated' => 0, 'processed' => 0, 'error' => '' );
		try {
			$rows      = $this->repository->due( $run_worker ? 1 : 50 );
			if ( $run_worker && $rows ) {
				$this->process_item( $rows[0]['wc_order_id'], $rows[0]['wc_item_id'] );
				$summary['processed'] = 1;
				return;
			}
			$batch_ids = array();
			foreach ( $rows as $row ) {
				if ( in_array( $row['status'], array( 'intent', 'unknown', 'submitting' ), true ) ) {
					if ( $this->schedule_item( $row['wc_order_id'], $row['wc_item_id'], 0 ) ) { ++$summary['queued']; } else { $summary['error'] = 'QUEUE_UNAVAILABLE'; }
					continue;
				}
				$batch_ids[] = $row['provider_order_id'];
			}

			if ( ! $batch_ids ) {
				return;
			}
			$next_batch = absint( get_option( 'dst2t_batch_not_before', 0 ) );
			if ( $next_batch > time() ) { $summary['error'] = 'BATCH_COOLDOWN'; return; }
			update_option( 'dst2t_batch_not_before', time() + 35, false );
			$result = $this->api->batch_orders( $batch_ids );
			foreach ( $result['orders'] as $remote ) {
				if ( ! empty( $remote['order_id'] ) ) {
					if ( in_array( (string) $remote['order_id'], $batch_ids, true ) && $this->apply_provider_order( (string) $remote['order_id'], $remote, 'batch' ) ) { ++$summary['updated']; }
				}
			}
			foreach ( $result['not_found'] as $not_found ) {
				if ( ! is_string( $not_found ) || ! in_array( $not_found, $batch_ids, true ) ) { continue; }
				$row = $this->repository->get( (string) $not_found );
				if ( $row ) {
					// Do not overwrite a state that a concurrent settlement may have advanced.
					// The worker re-reads it under the order lock before any action.
					$this->schedule_item( $row['wc_order_id'], $row['wc_item_id'], 30 );
				}
			}
		} catch ( DST2T_API_Exception $error ) {
			$summary['error'] = $error->get_api_code();
			update_option( 'dst2t_batch_not_before', time() + max( 35, $error->get_retry_after() ), false );
			$this->log( 'warning', 'Reconciliation request failed.', array( 'code' => $error->get_api_code() ) );
		} finally {
			update_option( 'dst2t_reconcile_state', $summary, false );
			$this->release_lock( 'reconcile', $lock );
		}
	}

	/** Timestamp of the next scheduled reconciliation, 0 when nothing is scheduled. */
	public function next_reconcile() {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::ACTION_RECONCILE, array(), self::ACTION_GROUP );
			if ( is_numeric( $next ) ) {
				return (int) $next;
			}
			if ( true === $next ) {
				return time();
			}
		}
		return (int) wp_next_scheduled( self::ACTION_RECONCILE );
	}

	public function manual_order_sync( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$provider_order_id = (string) $item->get_meta( '_dst2t_order_id', true );
			if ( ! $provider_order_id ) {
				continue;
			}
			try {
				$this->apply_provider_order( $provider_order_id, $this->api->order( $provider_order_id ), 'manual' );
			} catch ( DST2T_API_Exception $error ) {
				if ( 'ORDER_NOT_FOUND' === $error->get_api_code() ) {
					$this->schedule_item( $order->get_id(), $item_id, 0 );
				} else {
					$order->add_order_note( sprintf( /* translators: 1: neutral provider label, 2: error code. */ __( '%1$s sync could not run: %2$s.', 'delicat-shop2topup' ), DST2T_Brand::label(), $error->get_api_code() ) );
				}
			}
		}
	}

	public function order_actions( $actions ) {
		$actions['dst2t_sync'] = sprintf( /* translators: %s: neutral provider label. */ __( 'Synchronize %s status', 'delicat-shop2topup' ), DST2T_Brand::label() );
		return $actions;
	}

	public function hidden_item_meta( $hidden ) {
		return array_merge(
			$hidden,
			array(
				'_dst2t_accepted', '_dst2t_product_id', '_dst2t_item_id', '_dst2t_category_id', '_dst2t_requirements',
				'_dst2t_returns_voucher', '_dst2t_order_id', '_dst2t_status', '_dst2t_charged_amount', '_dst2t_vouchers', '_dst2t_player_name', '_dst2t_player_validated',
			)
		);
	}

	public function render_vouchers( $item_id, $item, $order, $plain_text ) {
		if ( ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order || ! $order->has_status( 'completed' ) ) {
			return;
		}
		$encrypted = (string) $item->get_meta( '_dst2t_vouchers', true );
		if ( ! $encrypted || ! $this->can_view_vouchers( $order ) ) {
			return;
		}
		$vouchers = json_decode( $this->vault->decrypt( $encrypted ), true );
		if ( ! is_array( $vouchers ) || ! $vouchers ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Voucher codes:', 'delicat-shop2topup' ) . "\n";
			foreach ( $vouchers as $voucher ) {
				echo esc_html( $voucher['code'] );
				if ( $voucher['serial_number'] ) {
					echo ' | ' . esc_html__( 'Serial:', 'delicat-shop2topup' ) . ' ' . esc_html( $voucher['serial_number'] );
				}
				if ( $voucher['expiry_date'] ) {
					echo ' | ' . esc_html__( 'Expires:', 'delicat-shop2topup' ) . ' ' . esc_html( $voucher['expiry_date'] );
				}
				echo "\n";
			}
			return;
		}

		echo '<div class="dst2t-vouchers"><strong>' . esc_html__( 'Voucher codes', 'delicat-shop2topup' ) . '</strong><ul>';
		foreach ( $vouchers as $voucher ) {
			echo '<li><code>' . esc_html( $voucher['code'] ) . '</code>';
			if ( $voucher['serial_number'] ) {
				echo '<small> ' . esc_html__( 'Serial:', 'delicat-shop2topup' ) . ' ' . esc_html( $voucher['serial_number'] ) . '</small>';
			}
			if ( $voucher['expiry_date'] ) {
				echo '<small> ' . esc_html__( 'Expires:', 'delicat-shop2topup' ) . ' ' . esc_html( $voucher['expiry_date'] ) . '</small>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/** Reopen under the same order lock used by purchase and settlement workers. */
	public function retry_fulfillment( $uuid ) {
		$row = $this->repository->get( $uuid );
		if ( ! $row ) { throw new RuntimeException( 'Commande de livraison inconnue.' ); }
		$key = 'order-' . absint( $row['wc_order_id'] );
		$lock = $this->acquire_lock( $key, self::ORDER_LOCK_TTL );
		if ( ! $lock ) { throw new RuntimeException( 'Un traitement est déjà en cours. Réessayez dans quelques instants.' ); }
		try {
			$row = $this->repository->get( $uuid );
			if ( ! $row || in_array( $row['status'], array( 'completed', 'refunded' ), true ) ) { throw new RuntimeException( 'Livraison déjà terminée ou remboursée. Utilisez Sync.' ); }
			$order = wc_get_order( absint( $row['wc_order_id'] ) );
			$item = $order ? $order->get_item( absint( $row['wc_item_id'] ) ) : false;
			if ( ! $order || ! $item instanceof WC_Order_Item_Product || ! $this->payment_confirmed( $order ) ) { throw new RuntimeException( 'Paiement non confirmé ou commande indisponible. Aucune relance effectuée.' ); }
			if ( in_array( $row['status'], array( 'pending', 'processing', 'retrying', 'partial', 'failed', 'voucher_missing', 'voucher_storage_error' ), true ) ) {
				$item->update_meta_data( '_dst2t_accepted', 'yes' );
				$item->save();
			}
			$status = in_array( $row['status'], array( 'pending', 'processing', 'retrying', 'partial' ), true ) ? $row['status'] : 'unknown';
			if ( ! $this->repository->update( $uuid, $status, array( 'next_check_at' => time() - 1, 'last_error_code' => '' ) ) ) { throw new RuntimeException( 'Impossible de sauvegarder la relance.' ); }
			$item->update_meta_data( '_dst2t_status', $status );
			$item->save();
			if ( $order->has_status( 'on-hold' ) ) { $order->update_status( 'processing', __( 'Digital fulfillment retried by a store manager.', 'delicat-shop2topup' ) ); }
			if ( ! $this->reschedule_item( $order->get_id(), absint( $row['wc_item_id'] ), 0 ) ) { throw new RuntimeException( 'File indisponible. La commande reste enregistrée pour la récupération.' ); }
			return true;
		} finally {
			$this->release_lock( $key, $lock );
		}
	}

	/** Cancels any pending worker job for one line item, then queues a fresh one. */
	public function reschedule_item( $order_id, $item_id, $delay = 0 ) {
		$args = array( absint( $order_id ), absint( $item_id ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_PROCESS, $args, self::ACTION_GROUP );
		}
		wp_clear_scheduled_hook( self::ACTION_PROCESS, $args );
		return $this->schedule_item( $order_id, $item_id, $delay );
	}

	public function schedule_item( $order_id, $item_id, $delay ) {
		return DST2T_Scheduler::enqueue( self::ACTION_PROCESS, array( absint( $order_id ), absint( $item_id ) ), $delay );
	}

	private function has_player_identifier( $requirements ) {
		foreach ( (array) $requirements as $key => $value ) {
			if ( DST2T_Product::is_player_identifier_key( $key ) && is_scalar( $value ) && '' !== trim( (string) $value ) ) { return true; }
		}
		return false;
	}

	private function cached_expected_cost( $item ) {
		$product_id = absint( $item->get_meta( '_dst2t_product_id', true ) );
		if ( ! $product_id ) { $product_id = $item->get_variation_id() ?: $item->get_product_id(); }
		$mapping = $this->products->mapping( $product_id );
		try {
			$cost = DST2T_Decimal::normalize( (string) $mapping['last_cost'] );
			return DST2T_Decimal::compare( $cost, '0' ) > 0 ? $cost : '';
		} catch ( Throwable $error ) {
			return '';
		}
	}

	private function can_fallback_to_cached_price( DST2T_API_Exception $error ) {
		return in_array( $error->get_api_code(), array( 'INVALID_RESPONSE', 'TRANSPORT_ERROR', 'RATE_LIMIT_EXCEEDED', 'INTERNAL_ERROR', 'SERVICE_UNAVAILABLE', 'PRICING_UPDATING' ), true );
	}

	private function remember_supplier_cost( $item, $cost ) {
		$product_id = absint( $item->get_meta( '_dst2t_product_id', true ) );
		if ( ! $product_id ) { $product_id = $item->get_variation_id() ?: $item->get_product_id(); }
		if ( $product_id ) {
			update_post_meta( $product_id, DST2T_Product::META_LAST_COST, DST2T_Decimal::normalize( $cost ) );
			update_post_meta( $product_id, DST2T_Product::META_LAST_SYNC, time() );
		}
	}

	private function assert_cost_guard( $item, $live_cost ) {
		$product_id = absint( $item->get_meta( '_dst2t_product_id', true ) );
		if ( ! $product_id ) {
			$product_id = $item->get_variation_id() ?: $item->get_product_id();
		}
		$mapping = $this->products->mapping( $product_id );
		$live    = DST2T_Decimal::normalize( $live_cost );

		// Both limits fail closed. A ceiling or a cached baseline of exactly zero
		// means the guard evaluates to zero and every purchase is refused: that is
		// deliberate, because a zero baseline means the real cost was never learned
		// and buying against an unknown baseline is how a wallet gets drained. The
		// note below names the cause so the operator can refresh the product.
		$max_cost = (string) $mapping['max_cost'];
		if ( '' !== $max_cost && DST2T_Decimal::compare( $live, $max_cost ) > 0 ) {
			throw new RuntimeException(
				0 === DST2T_Decimal::compare( $max_cost, '0' )
					? __( 'This product’s absolute maximum cost is set to zero, so no purchase can be made. Set a real ceiling or clear the field.', 'delicat-shop2topup' )
					: __( 'Live supplier cost is above the product’s absolute maximum. No supplier purchase was made.', 'delicat-shop2topup' )
			);
		}

		$guard     = $this->settings->get( 'cost_guard_percent', '10.00' );
		$last_cost = (string) $mapping['last_cost'];
		if ( '' !== $last_cost && DST2T_Decimal::compare( $guard, '0' ) > 0 ) {
			$limit = DST2T_Decimal::add_percent( $last_cost, $guard );
			if ( DST2T_Decimal::compare( $live, $limit ) > 0 ) {
				throw new RuntimeException(
					0 === DST2T_Decimal::compare( $last_cost, '0' )
						? __( 'The cached cost for this product is zero, so the safety limit is zero and no purchase can be made. Refresh the product from the provider, then retry.', 'delicat-shop2topup' )
						: __( 'Live supplier cost increased beyond the configured safety limit. No supplier purchase was made.', 'delicat-shop2topup' )
				);
			}
		}
	}

	private function handle_preflight_failure( $order, $item, $provider_order_id, DST2T_API_Exception $error, $attempts ) {
		$code = $error->get_api_code();
		// No create-order POST has been attempted yet. Keep the row in intent instead
		// of marking it unknown/checking, and count the failed preflight so it cannot
		// retry forever with Attempts=0.
		if ( $error->is_temporary() && $attempts < 8 ) {
			$delay = max( $error->get_retry_after(), $this->submission_retry_delay( $attempts ) );
			$this->repository->update(
				$provider_order_id,
				'intent',
				array(
					'increment_attempt' => true,
					'next_check_at'     => time() + $delay,
					'last_error_code'   => $code,
				)
			);
			$item->update_meta_data( '_dst2t_status', 'intent' );
			$item->save();
			$this->schedule_item( $order->get_id(), $item->get_id(), $delay );
			return;
		}

		$this->mark_manual_review(
			$order,
			$item,
			$provider_order_id,
			'submission_failed',
			$code,
			sprintf( __( '%1$s preflight failed (%2$s). No supplier purchase was made.', 'delicat-shop2topup' ), DST2T_Brand::label(), $code )
		);
	}

	private function handle_temporary_or_failure( $order, $item, $provider_order_id, DST2T_API_Exception $error, $attempts, $fast_retry = false ) {
		// A funds-related rejection means the cached wallet figure is out of date.
		$code = $error->get_api_code();
		if ( class_exists( 'DST2T_Balance' ) && ( false !== strpos( $code, 'BALANCE' ) || false !== strpos( $code, 'FUNDS' ) || false !== strpos( $code, 'CREDIT' ) ) ) {
			DST2T_Balance::queue_refresh();
		}

		if ( $error->is_temporary() && $attempts < 8 ) {
			$delay = max( $error->get_retry_after(), $fast_retry ? $this->submission_retry_delay( $attempts ) : $this->retry_delay( $attempts ) );
			$this->repository->update( $provider_order_id, 'unknown', array( 'next_check_at' => time() + $delay, 'last_error_code' => $error->get_api_code() ) );
			$item->update_meta_data( '_dst2t_status', 'unknown' );
			$item->save();
			$this->schedule_item( $order->get_id(), $item->get_id(), $delay );
			return;
		}

		$this->mark_manual_review(
			$order,
			$item,
			$provider_order_id,
			'submission_failed',
			$error->get_api_code(),
			sprintf( /* translators: 1: neutral provider label, 2: error code. */ __( '%1$s fulfillment requires review (%2$s). No automatic customer refund was issued.', 'delicat-shop2topup' ), DST2T_Brand::label(), $error->get_api_code() )
		);
	}

	private function mark_manual_review( $order, $item, $provider_order_id, $status, $code, $note ) {
		$this->repository->update( $provider_order_id, $status, array( 'next_check_at' => null, 'last_error_code' => $code ) );
		$item->update_meta_data( '_dst2t_status', $status );
		$item->save();
		$this->order_note_once( $order, $code . '-' . $item->get_id(), $note );
		if ( ! $order->has_status( array( 'refunded', 'cancelled', 'failed' ) ) ) {
			if ( $order->is_paid() ) { $order->update_meta_data( '_dst2t_paid_review_hold', 'yes' ); $order->save(); }
			$order->update_status( 'on-hold', __( 'Automatic digital fulfillment paused for safe manual review.', 'delicat-shop2topup' ) );
		}
	}

	public function payment_confirmed( $order ) {
		if ( ! $order instanceof WC_Order || $order->has_status( array( 'pending', 'failed', 'cancelled', 'refunded' ) ) ) {
			return false;
		}
		if ( ! $this->settings->enabled( 'woocommerce_payment_gate' ) ) {
			return $order->is_paid() || ( $order->has_status( 'on-hold' ) && 'yes' === $order->get_meta( '_dst2t_paid_review_hold', true ) );
		}
		if ( 'yes' === $order->get_meta( '_dst2t_wc_payment_approved', true ) ) {
			$version = (string) $order->get_meta( '_dst2t_payment_attestation_v', true );
			$stored  = strtolower( trim( (string) $order->get_meta( '_dst2t_payment_attestation_sig', true ) ) );
			if ( '1' === $version && preg_match( '/^[a-f0-9]{64}$/', $stored ) ) {
				return hash_equals( $this->payment_attestation_signature( $order ), $stored );
			}
			// Legacy paid orders created before v2.5.0 did not have the cryptographic
			// attestation. Keep them recoverable while every new payment gets one.
			return true;
		}
		// Backwards compatibility for legitimately paid orders created before this
		// version: WooCommerce's paid timestamp is durable payment evidence.
		if ( is_callable( array( $order, 'get_date_paid' ) ) && $order->get_date_paid() ) {
			return true;
		}
		if ( $order->has_status( 'on-hold' ) && 'yes' === $order->get_meta( '_dst2t_paid_review_hold', true ) ) {
			return true;
		}
		return false;
	}

	private function payment_attestation_signature( $order ) {
		return hash_hmac( 'sha256', $this->payment_attestation_payload( $order ), wp_salt( 'secure_auth' ) );
	}

	private function payment_attestation_payload( $order ) {
		$items = array();
		foreach ( (array) $order->get_items( 'line_item' ) as $item_id => $item ) {
			$supplier_item_id = absint( $item->get_meta( '_dst2t_item_id', true ) );
			if ( ! $supplier_item_id ) { continue; }
			$requirements = $this->item_requirements( $item );
			ksort( $requirements );
			$items[] = array(
				'line'         => absint( $item_id ),
				'supplier'     => $supplier_item_id,
				'quantity'     => max( 1, absint( $item->get_quantity() ) ),
				'requirements' => hash( 'sha256', wp_json_encode( $requirements ) ),
			);
		}
		usort( $items, static function ( $a, $b ) { return $a['line'] <=> $b['line']; } );
		$order_key = method_exists( $order, 'get_order_key' ) ? (string) $order->get_order_key() : '';
		$transaction = method_exists( $order, 'get_transaction_id' ) ? (string) $order->get_transaction_id() : '';
		$total = method_exists( $order, 'get_total' ) ? (string) $order->get_total() : '';
		$currency = method_exists( $order, 'get_currency' ) ? strtoupper( (string) $order->get_currency() ) : '';
		$customer = method_exists( $order, 'get_customer_id' ) ? absint( $order->get_customer_id() ) : ( method_exists( $order, 'get_user_id' ) ? absint( $order->get_user_id() ) : 0 );
		return wp_json_encode( array(
			'v'           => 1,
			'order'       => absint( $order->get_id() ),
			'order_key'   => hash( 'sha256', $order_key ),
			'customer'    => $customer,
			'total'       => $total,
			'currency'    => $currency,
			'transaction' => hash( 'sha256', $transaction ),
			'items'       => $items,
		) );
	}

	private function evaluate_order( $order ) {
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) { return; }
		$statuses = array();
		$mapped   = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item->get_meta( '_dst2t_order_id', true ) ) {
				continue;
			}
			++$mapped;
			$statuses[] = sanitize_key( (string) $item->get_meta( '_dst2t_status', true ) );
		}
		if ( ! $mapped ) {
			return;
		}

		$problem = array_intersect( $statuses, array( 'partial', 'failed', 'refunded', 'submission_failed', 'price_blocked', 'voucher_storage_error', 'voucher_missing', 'manual_review' ) );
		if ( $problem ) {
			$this->order_note_once( $order, 'aggregate-' . implode( '-', array_unique( $problem ) ), sprintf( /* translators: %s: neutral provider label. */ __( 'One or more %s items require manual review. Provider and WooCommerce states were preserved.', 'delicat-shop2topup' ), DST2T_Brand::label() ) );
			if ( ! $order->has_status( array( 'refunded', 'cancelled', 'failed', 'on-hold' ) ) ) {
				if ( $order->is_paid() ) { $order->update_meta_data( '_dst2t_paid_review_hold', 'yes' ); $order->save(); }
				$order->update_status( 'on-hold' );
			}
			return;
		}

		if ( count( array_filter( $statuses, static function ( $status ) { return 'completed' === $status; } ) ) === count( $statuses ) ) {
			$all_order_items_mapped = count( $order->get_items( 'line_item' ) ) === $mapped;
			if ( $this->settings->enabled( 'auto_complete' ) && $all_order_items_mapped && $this->payment_confirmed( $order ) && ! $order->has_status( 'completed' ) ) {
				$order->update_status( 'completed', sprintf( /* translators: %s: neutral provider label. */ __( 'All %s items were delivered and synchronized.', 'delicat-shop2topup' ), DST2T_Brand::label() ) );
			} elseif ( ! $all_order_items_mapped ) {
				$this->order_note_once( $order, 'mapped-items-complete', sprintf( /* translators: %s: neutral provider label. */ __( 'All %s items are complete; other order items still control the WooCommerce status.', 'delicat-shop2topup' ), DST2T_Brand::label() ) );
			}
		}
	}

	private function item_requirements( $item ) {
		$requirements = json_decode( (string) $item->get_meta( '_dst2t_requirements', true ), true );
		return is_array( $requirements ) ? $requirements : array();
	}

	private function sanitize_vouchers( $vouchers ) {
		$out = array();
		foreach ( (array) $vouchers as $voucher ) {
			if ( ! is_array( $voucher ) || empty( $voucher['code'] ) ) {
				continue;
			}
			$out[] = array(
				'code'          => sanitize_text_field( $voucher['code'] ),
				'serial_number' => isset( $voucher['serial_number'] ) ? sanitize_text_field( $voucher['serial_number'] ) : '',
				'expiry_date'   => isset( $voucher['expiry_date'] ) ? sanitize_text_field( $voucher['expiry_date'] ) : '',
			);
		}
		return $out;
	}

	private function can_view_vouchers( $order ) {
		if ( current_user_can( 'manage_woocommerce' ) || doing_action( 'woocommerce_email_order_details' ) ) {
			return true;
		}
		if ( get_current_user_id() && absint( $order->get_user_id() ) === get_current_user_id() ) {
			return true;
		}
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $key && hash_equals( (string) $order->get_order_key(), (string) $key );
	}

	/**
	 * Fire a detached, single-use internal worker for one paid line item.
	 *
	 * The public request contains only a 256-bit random token. Order/item IDs are
	 * stored server-side in a short-lived transient and consumed atomically before
	 * process_item() can run. Action Scheduler stays armed as the durable fallback.
	 */
	private function dispatch_fast_item( $order_id, $item_id ) {
		$order_id = absint( $order_id );
		$item_id  = absint( $item_id );
		if ( ! $order_id || ! $item_id ) { return false; }

		$this->remember_post_response_item( $order_id, $item_id );

		// The payment-complete and processing hooks can fire back-to-back. Avoid
		// fanning out duplicate loopbacks for the same line during that short window.
		$debounce = 'dst2t_fast_dispatch_' . md5( $order_id . '|' . $item_id );
		if ( get_transient( $debounce ) ) { return true; }
		if ( ! set_transient( $debounce, 1, 5 ) ) { return false; }

		try {
			$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		} catch ( Throwable $error ) {
			delete_transient( $debounce );
			return false;
		}
		if ( strlen( $token ) < 32 ) {
			delete_transient( $debounce );
			return false;
		}

		$claim_key = 'dst2t_fast_claim_' . hash( 'sha256', $token );
		if ( ! set_transient(
			$claim_key,
			array( 'order_id' => $order_id, 'item_id' => $item_id, 'issued' => time() ),
			120
		) ) {
			delete_transient( $debounce );
			return false;
		}

		// Unit tests and unusual CLI contexts may not expose admin_url(). The
		// scheduler/post-response fallbacks remain valid in those environments.
		if ( ! function_exists( 'admin_url' ) || ! function_exists( 'wp_safe_remote_post' ) ) {
			delete_transient( $claim_key );
			delete_transient( $debounce );
			return false;
		}
		$url = admin_url( 'admin-ajax.php', 'https' );
		if ( 0 !== strpos( (string) $url, 'https://' ) ) {
			delete_transient( $claim_key );
			delete_transient( $debounce );
			return false;
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'            => 0.5,
				'blocking'           => false,
				'redirection'        => 0,
				'sslverify'          => true,
				'reject_unsafe_urls' => true,
				'headers'            => array( 'Cache-Control' => 'no-store' ),
				'body'               => array( 'action' => self::ACTION_FAST, 'token' => $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			delete_transient( $claim_key );
			delete_transient( $debounce );
			return false;
		}
		return true;
	}

	private function remember_post_response_item( $order_id, $item_id ) {
		if ( $this->internal_fast_active || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) { return; }
		if ( count( $this->post_response_items ) >= 4 ) { return; }
		$key = absint( $order_id ) . ':' . absint( $item_id );
		$this->post_response_items[ $key ] = array( absint( $order_id ), absint( $item_id ) );
	}

	/**
	 * FastCGI fallback: finish the HTTP response first, then start at most two
	 * paid line items. It never sleeps, so a busy store does not monopolize PHP
	 * workers while waiting for supplier status changes.
	 */
	public function run_post_response_fastlane() {
		if ( ! $this->post_response_items || $this->internal_fast_active ) { return; }
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) { return; }
		if ( ! function_exists( 'fastcgi_finish_request' ) ) { return; }
		ignore_user_abort( true );
		@fastcgi_finish_request();
		$items = array_slice( array_values( $this->post_response_items ), 0, 2 );
		$this->post_response_items = array();
		foreach ( $items as $candidate ) {
			$this->process_item( absint( $candidate[0] ), absint( $candidate[1] ) );
		}
	}

	/** Single-use authenticated internal worker. */
	public function ajax_fast_fulfill() {
		if ( function_exists( 'nocache_headers' ) && ! headers_sent() ) { nocache_headers(); }
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( $_SERVER['CONTENT_LENGTH'] ) : 0;
		if ( 'POST' !== $method || $length > 4096 ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}
		$token = isset( $_POST['token'] ) ? trim( (string) wp_unslash( $_POST['token'] ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{32,100}$/', $token ) ) {
			wp_send_json_error( array( 'code' => 'invalid' ), 403 );
		}

		$claim_key  = 'dst2t_fast_claim_' . hash( 'sha256', $token );
		global $wpdb;
		$claim_lock = 'dst2t_fast_' . substr( hash( 'sha256', $token ), 0, 40 );
		$locked = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $claim_lock ) ) === '1';
		if ( ! $locked ) {
			wp_send_json_error( array( 'code' => 'busy' ), 503 );
		}
		try {
			$claim = get_transient( $claim_key );
			if ( ! is_array( $claim ) ) {
				wp_send_json_error( array( 'code' => 'expired' ), 403 );
			}
			// Consume before any supplier request. Replaying the token cannot replay a
			// purchase, even before the supplier UUID idempotency protection is reached.
			delete_transient( $claim_key );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $claim_lock ) );
		}

		$order_id = absint( isset( $claim['order_id'] ) ? $claim['order_id'] : 0 );
		$item_id  = absint( isset( $claim['item_id'] ) ? $claim['item_id'] : 0 );
		$issued   = absint( isset( $claim['issued'] ) ? $claim['issued'] : 0 );
		if ( ! $order_id || ! $item_id || ! $issued || $issued < time() - 90 || $issued > time() + 10 ) {
			wp_send_json_error( array( 'code' => 'invalid_claim' ), 403 );
		}

		$this->internal_fast_active = true;
		try {
			$this->process_item( $order_id, $item_id );
		} finally {
			$this->internal_fast_active = false;
		}
		wp_send_json_success( array( 'ok' => true ) );
	}

	/** Retry a local order lock for a short bounded window; no supplier I/O here. */
	private function acquire_lock_wait( $key, $ttl, $wait_ms ) {
		$deadline = microtime( true ) + ( max( 0, absint( $wait_ms ) ) / 1000 );
		do {
			$token = $this->acquire_lock( $key, $ttl );
			if ( $token ) { return $token; }
			if ( microtime( true ) >= $deadline ) { break; }
			usleep( 50000 );
		} while ( true );
		return false;
	}

	private function terminal_statuses() {
		return array( 'completed', 'failed', 'refunded', 'submission_failed', 'price_blocked', 'voucher_storage_error', 'voucher_missing', 'manual_review' );
	}

	private function submission_retry_delay( $attempts ) {
		// POST /orders/create and catalog preflight endpoints have wide published
		// limits. Short retries keep a paid order moving without changing its UUID.
		// Status reads intentionally keep their separate 20s+ cadence because the
		// supplier limits GET /orders/:id to 3 reads per 60 seconds per order.
		$delays = array( 3, 6, 10, 20, 30, 60, 120, 300 );
		return $delays[ min( count( $delays ) - 1, max( 0, absint( $attempts ) ) ) ];
	}

	private function retry_delay( $attempts ) {
		$delays = array( 20, 60, 180, 600, 1800, 3600, 7200, 14400 );
		return $delays[ min( count( $delays ) - 1, max( 0, absint( $attempts ) ) ) ];
	}

	private function order_note_once( $order, $key, $message ) {
		$meta_key = '_dst2t_note_' . substr( hash( 'sha256', (string) $key ), 0, 16 );
		if ( $order->get_meta( $meta_key, true ) ) {
			return;
		}
		$order->add_order_note( $message );
		$order->update_meta_data( $meta_key, gmdate( 'c' ) );
		$order->save();
	}

	private function acquire_lock( $key, $ttl ) {
		global $wpdb;
		$option = 'dst2t_lock_' . md5( (string) $key );
		if ( isset( $this->held_locks[ $option ] ) ) {
			++$this->held_locks[ $option ]['depth'];
			return $this->held_locks[ $option ]['token'];
		}
		$token = wp_generate_uuid4() . '|' . ( time() + absint( $ttl ) );
		// INSERT IGNORE is the atomic step. add_option() is a cached existence check
		// followed by a write, so two concurrent workers can both believe they won.
		$ok = (bool) $wpdb->query(
			$wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, $token )
		);
		if ( $ok ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		if ( ! $ok ) {
			$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
			$parts = explode( '|', $current );
			if ( absint( end( $parts ) ) < time() ) {
				$ok = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $option, $current ) );
				wp_cache_delete( $option, 'options' );
			}
		}
		if ( $ok ) {
			$this->held_locks[ $option ] = array( 'token' => $token, 'depth' => 1 );
			return $token;
		}
		return false;
	}

	private function release_lock( $key, $token ) {
		global $wpdb;
		$option = 'dst2t_lock_' . md5( (string) $key );
		if ( ! isset( $this->held_locks[ $option ] ) || --$this->held_locks[ $option ]['depth'] > 0 ) { return; }
		unset( $this->held_locks[ $option ] );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option, $token ) );
		wp_cache_delete( $option, 'options' );
	}

	private function log( $level, $message, $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$context['source'] = 'delicat-shop2topup';
		wc_get_logger()->log( $level, $message, $context );
	}
}
