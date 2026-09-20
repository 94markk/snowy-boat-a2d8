<?php

defined( 'ABSPATH' ) || exit;

final class DST2T_Fulfillment {
	const ACTION_WEBHOOK = 'dst2t_webhook_reconcile';
	private $held_locks = array();
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
		add_action( 'woocommerce_payment_complete', array( $this, 'queue_order' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'queue_order' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'queue_order' ), 20 );
		add_filter( 'woocommerce_order_item_needs_processing', array( $this, 'mapped_item_needs_processing' ), 10, 3 );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'paid_order_status' ), 20, 3 );
		add_action( self::ACTION_WEBHOOK, array( $this, 'process_webhook' ), 10, 2 );
		add_action( self::ACTION_PROCESS, array( $this, 'process_item' ), 10, 2 );
		add_action( self::ACTION_RECONCILE, array( $this, 'reconcile' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_item_meta' ) );
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render_vouchers' ), 10, 4 );
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ) );
		add_action( 'woocommerce_order_action_dst2t_sync', array( $this, 'manual_order_sync' ) );
	}

	public function queue_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}

		$lock = $this->acquire_lock( 'order-' . $order->get_id(), 300 );
		if ( ! $lock ) {
			return;
		}

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
					$this->schedule_item( $order->get_id(), $item_id, 0 );
				}
			}
			if ( $has_mapped && ! $all_mapped_done && $order->has_status( 'completed' ) ) {
				$order->update_status( 'processing', sprintf( /* translators: %s: neutral provider label. */ __( 'Paid order is waiting for verified %s delivery.', 'delicat-shop2topup' ), DST2T_Brand::label() ) );
			}
		} finally {
			$this->release_lock( 'order-' . $order->get_id(), $lock );
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
		$lock     = $this->acquire_lock( 'order-' . $order_id, 300 );
		if ( ! $lock ) {
			return;
		}

		try {
			$order = wc_get_order( $order_id );
			$item  = $order ? $order->get_item( $item_id ) : false;
			if ( ! $order || ! $item instanceof WC_Order_Item_Product || ! $order->is_paid() ) {
				return;
			}

			$provider_order_id = (string) $item->get_meta( '_dst2t_order_id', true );
			$supplier_item_id  = absint( $item->get_meta( '_dst2t_item_id', true ) );
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
			if ( in_array( $status, array( 'pending', 'processing', 'retrying', 'partial' ), true ) ) {
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
			if ( $attempts > 0 && in_array( $status, array( 'submitting', 'unknown' ), true ) ) {
				try {
					$remote = $this->api->order( $provider_order_id );
					$this->apply_provider_order( $provider_order_id, $remote, 'recovery' );
					return;
				} catch ( DST2T_API_Exception $lookup_error ) {
					if ( 'ORDER_NOT_FOUND' !== $lookup_error->get_api_code() ) {
						$this->handle_temporary_or_failure( $order, $item, $provider_order_id, $lookup_error, $attempts );
						return;
					}
				}
			}

			try {
				$price = $this->api->price( $supplier_item_id );
				$this->assert_cost_guard( $item, $price['unit_price'] );
			} catch ( DST2T_API_Exception $price_error ) {
				$this->handle_temporary_or_failure( $order, $item, $provider_order_id, $price_error, $attempts );
				return;
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
					if ( $attempts >= 4 ) {
						$this->mark_manual_review( $order, $item, $provider_order_id, 'price_blocked', 'PRICE_INCREASED', __( 'Supplier price changed repeatedly. No supplier purchase was made.', 'delicat-shop2topup' ) );
						return;
					}
					$this->repository->update( $provider_order_id, 'intent', array( 'next_check_at' => time() + 20, 'last_error_code' => $error->get_api_code() ) );
					$item->update_meta_data( '_dst2t_status', 'intent' );
					$item->save();
					$this->schedule_item( $order_id, $item_id, 20 );
					return;
				}
				$this->handle_temporary_or_failure( $order, $item, $provider_order_id, $error, $attempts + 1 );
			}
		} catch ( Throwable $error ) {
			$this->log( 'error', 'Unexpected fulfillment exception.', array( 'order_id' => $order_id, 'item_id' => $item_id, 'type' => get_class( $error ) ) );
		} finally {
			$this->release_lock( 'order-' . $order_id, $lock );
		}
	}

	public function apply_provider_order( $provider_order_id, $remote, $source = 'status', $event_timestamp = 0 ) {
		$row = $this->repository->get( $provider_order_id );
		if ( ! $row ) { return false; }
		$key = 'order-' . absint( $row['wc_order_id'] );
		$lock = $this->acquire_lock( $key, 300 );
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

		$status = isset( $remote['status'] ) ? sanitize_key( $remote['status'] ) : 'unknown';
		if ( ! in_array( $status, array( 'pending', 'processing', 'retrying', 'completed', 'partial', 'failed', 'refunded' ), true ) ) {
			$status = 'unknown';
		}

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
				$this->repository->update( $provider_order_id, 'unknown', array( 'next_check_at' => time() + max( 20, $detail_error->get_retry_after() ), 'last_error_code' => $detail_error->get_api_code() ) );
				$item->update_meta_data( '_dst2t_status', 'unknown' );
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
			'next_check_at'  => $terminal ? null : time() + 60,
			'last_error_code' => '',
		);
		if ( $event_timestamp ) {
			$args['last_event_at'] = $event_timestamp;
		}
		$this->repository->update( $provider_order_id, $status, $args );
		$this->evaluate_order( $order );
		return true;
	}

	public function queue_webhook( $uuid, $event_timestamp = 0 ) {
		$args = array( (string) $uuid, absint( $event_timestamp ) );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$id = as_enqueue_async_action( self::ACTION_WEBHOOK, $args, self::ACTION_GROUP );
			if ( ! $id ) { throw new RuntimeException( 'Webhook queue unavailable.' ); }
		} elseif ( ! wp_next_scheduled( self::ACTION_WEBHOOK, $args ) ) {
			if ( ! wp_schedule_single_event( time(), self::ACTION_WEBHOOK, $args ) ) {
				throw new RuntimeException( 'Webhook queue unavailable.' );
			}
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
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $delay, self::ACTION_WEBHOOK, $args, self::ACTION_GROUP );
			} else {
				wp_schedule_single_event( time() + $delay, self::ACTION_WEBHOOK, $args );
			}
		}
	}

	public function reconcile() {
		if ( ! $this->settings->credentials_configured() ) { return; }
		$lock = $this->acquire_lock( 'reconcile', 300 );
		if ( ! $lock ) {
			return;
		}

		try {
			$rows      = $this->repository->due( 50 );
			$batch_ids = array();
			foreach ( $rows as $row ) {
				if ( 'intent' === $row['status'] ) {
					$this->schedule_item( $row['wc_order_id'], $row['wc_item_id'], 0 );
					continue;
				}
				$batch_ids[] = $row['provider_order_id'];
			}

			if ( ! $batch_ids ) {
				return;
			}
			$result = $this->api->batch_orders( $batch_ids );
			foreach ( $result['orders'] as $remote ) {
				if ( ! empty( $remote['order_id'] ) ) {
					$this->apply_provider_order( (string) $remote['order_id'], $remote, 'batch' );
				}
			}
			foreach ( $result['not_found'] as $not_found ) {
				$row = $this->repository->get( (string) $not_found );
				if ( $row ) {
					$this->repository->update( $not_found, 'unknown', array( 'next_check_at' => time() + 30, 'last_error_code' => 'ORDER_NOT_FOUND' ) );
					$this->schedule_item( $row['wc_order_id'], $row['wc_item_id'], 30 );
				}
			}
		} catch ( DST2T_API_Exception $error ) {
			$this->log( 'warning', 'Reconciliation request failed.', array( 'code' => $error->get_api_code() ) );
		} finally {
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
				'_dst2t_product_id', '_dst2t_item_id', '_dst2t_category_id', '_dst2t_requirements',
				'_dst2t_returns_voucher', '_dst2t_order_id', '_dst2t_status', '_dst2t_charged_amount', '_dst2t_vouchers', '_dst2t_player_name',
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

	public function schedule_item( $order_id, $item_id, $delay ) {
		$args = array( absint( $order_id ), absint( $item_id ) );
		$when = time() + max( 0, absint( $delay ) );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::ACTION_PROCESS, $args, self::ACTION_GROUP ) ) {
				if ( ! $delay && function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( self::ACTION_PROCESS, $args, self::ACTION_GROUP, true );
				} else {
					as_schedule_single_action( $when, self::ACTION_PROCESS, $args, self::ACTION_GROUP, true );
				}
			}
			return;
		}
		if ( ! wp_next_scheduled( self::ACTION_PROCESS, $args ) ) {
			wp_schedule_single_event( $when, self::ACTION_PROCESS, $args );
		}
	}

	private function assert_cost_guard( $item, $live_cost ) {
		$product_id = absint( $item->get_meta( '_dst2t_product_id', true ) );
		if ( ! $product_id ) {
			$product_id = $item->get_variation_id() ?: $item->get_product_id();
		}
		$mapping = $this->products->mapping( $product_id );
		$live    = DST2T_Decimal::normalize( $live_cost );

		// A ceiling or a cached cost of exactly zero means "not set". Treating a
		// zero as a real limit would block every purchase for the product.
		$max_cost = (string) $mapping['max_cost'];
		if ( '' !== $max_cost && DST2T_Decimal::compare( $max_cost, '0' ) > 0 && DST2T_Decimal::compare( $live, $max_cost ) > 0 ) {
			throw new RuntimeException( __( 'Live supplier cost is above the product’s absolute maximum. No supplier purchase was made.', 'delicat-shop2topup' ) );
		}

		$guard     = $this->settings->get( 'cost_guard_percent', '10.00' );
		$last_cost = (string) $mapping['last_cost'];
		if ( '' !== $last_cost && DST2T_Decimal::compare( $last_cost, '0' ) > 0 && DST2T_Decimal::compare( $guard, '0' ) > 0 ) {
			$limit = DST2T_Decimal::add_percent( $last_cost, $guard );
			if ( DST2T_Decimal::compare( $live, $limit ) > 0 ) {
				throw new RuntimeException( __( 'Live supplier cost increased beyond the configured safety limit. No supplier purchase was made.', 'delicat-shop2topup' ) );
			}
		}
	}

	private function handle_temporary_or_failure( $order, $item, $provider_order_id, DST2T_API_Exception $error, $attempts ) {
		// A funds-related rejection means the cached wallet figure is out of date.
		$code = $error->get_api_code();
		if ( class_exists( 'DST2T_Balance' ) && ( false !== strpos( $code, 'BALANCE' ) || false !== strpos( $code, 'FUNDS' ) || false !== strpos( $code, 'CREDIT' ) ) ) {
			DST2T_Balance::queue_refresh();
		}

		if ( $error->is_temporary() && $attempts < 8 ) {
			$delay = max( $error->get_retry_after(), $this->retry_delay( $attempts ) );
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
			$order->update_status( 'on-hold', __( 'Automatic digital fulfillment paused for safe manual review.', 'delicat-shop2topup' ) );
		}
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
				$order->update_status( 'on-hold' );
			}
			return;
		}

		if ( count( array_filter( $statuses, static function ( $status ) { return 'completed' === $status; } ) ) === count( $statuses ) ) {
			$all_order_items_mapped = count( $order->get_items( 'line_item' ) ) === $mapped;
			if ( $this->settings->enabled( 'auto_complete' ) && $all_order_items_mapped && $order->is_paid() && ! $order->has_status( 'completed' ) ) {
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

	private function terminal_statuses() {
		return array( 'completed', 'failed', 'refunded', 'submission_failed', 'price_blocked', 'voucher_storage_error', 'voucher_missing', 'manual_review' );
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
