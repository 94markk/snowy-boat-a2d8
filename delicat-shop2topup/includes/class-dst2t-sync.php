<?php

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled catalog mirroring.
 *
 * Walks every mapped product in small cursor-based batches and refreshes the
 * cached supplier cost, the dynamic requirement schema, and — when the supplier
 * reports it — stock availability. Selling prices are only rewritten when the
 * operator opted in. An item the supplier no longer offers is taken out of
 * stock rather than deleted, so nothing disappears from the shop silently.
 */
final class DST2T_Sync {
	const ACTION          = 'dst2t_sync_catalog';
	const OPTION_CURSOR   = 'dst2t_sync_cursor';
	const OPTION_STATE    = 'dst2t_sync_state';
	const OPTION_SCHEDULE = 'dst2t_sync_scheduled_interval';
	const BATCH           = 20;

	/** @var DST2T_API_Client */
	private $api;

	/** @var DST2T_Settings */
	private $settings;

	/** @var DST2T_Product */
	private $products;

	public function __construct( DST2T_API_Client $api, DST2T_Settings $settings, DST2T_Product $products ) {
		$this->api      = $api;
		$this->settings = $settings;
		$this->products = $products;
	}

	public function hooks() {
		add_action( self::ACTION, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ), 32 );
	}

	public function interval_seconds() {
		$minutes = absint( $this->settings->get( 'catalog_sync_interval', 0 ) );
		if ( ! $minutes ) {
			return 0;
		}
		return max( 15 * MINUTE_IN_SECONDS, min( 7 * DAY_IN_SECONDS, $minutes * MINUTE_IN_SECONDS ) );
	}

	public function next_run() {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::ACTION, array(), DST2T_Fulfillment::ACTION_GROUP );
			if ( is_numeric( $next ) ) {
				return (int) $next;
			}
			if ( true === $next ) {
				return time();
			}
		}
		return (int) wp_next_scheduled( self::ACTION );
	}

	public function ensure_schedule() {
		$interval  = $this->interval_seconds();
		$scheduled = (int) get_option( self::OPTION_SCHEDULE, 0 );

		if ( ! $interval || ! $this->settings->credentials_configured() ) {
			if ( $scheduled ) {
				$this->unschedule();
				update_option( self::OPTION_SCHEDULE, 0, false );
			}
			return;
		}

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			$has = ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::ACTION, array(), DST2T_Fulfillment::ACTION_GROUP );
			if ( $has && $scheduled === $interval ) {
				return;
			}
			$this->unschedule();
			as_schedule_recurring_action( time() + 120, $interval, self::ACTION, array(), DST2T_Fulfillment::ACTION_GROUP, true );
			update_option( self::OPTION_SCHEDULE, $interval, false );
			return;
		}

		if ( wp_next_scheduled( self::ACTION ) && $scheduled === $interval ) {
			return;
		}
		$this->unschedule();
		wp_schedule_event( time() + 120, 'dst2t_sync_interval', self::ACTION );
		update_option( self::OPTION_SCHEDULE, $interval, false );
	}

	public function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, array(), DST2T_Fulfillment::ACTION_GROUP );
		}
		wp_clear_scheduled_hook( self::ACTION );
	}

	/**
	 * Processes one batch of mapped products.
	 *
	 * @param int $limit Optional batch size override, used by WP-CLI.
	 * @return array Run summary.
	 */
	public function run( $limit = 0 ) {
		$summary = array( 'processed' => 0, 'updated' => 0, 'errors' => 0, 'last_error' => '', 'cycle_complete' => false );
		if ( ! $this->settings->credentials_configured() ) {
			$summary['last_error'] = 'MISSING_API_KEY';
			return $summary;
		}

		$limit = $limit ? max( 1, min( 100, absint( $limit ) ) ) : self::BATCH;
		$ids   = $this->next_batch( $limit );

		if ( ! $ids ) {
			update_option( self::OPTION_CURSOR, 0, false );
			$summary['cycle_complete'] = true;
			$this->record( $summary );
			return $summary;
		}

		$highest       = 0;
		$stopped_early = false;
		foreach ( $ids as $product_id ) {
			$product_id = absint( $product_id );
			$highest    = max( $highest, $product_id );
			++$summary['processed'];

			try {
				$this->products->refresh_mapping( $product_id, true );
				delete_post_meta( $product_id, DST2T_Product::META_SYNC_ERROR );
				++$summary['updated'];
			} catch ( DST2T_API_Exception $error ) {
				++$summary['errors'];
				$summary['last_error'] = $error->get_api_code();
				update_post_meta( $product_id, DST2T_Product::META_SYNC_ERROR, $error->get_api_code() );

				if ( in_array( $error->get_api_code(), array( 'ITEM_NOT_FOUND', 'SUBCATEGORY_NOT_FOUND', 'NOT_FOUND', 'PRODUCT_UNAVAILABLE' ), true ) ) {
					$this->mark_unavailable( $product_id );
					continue;
				}
				if ( in_array( $error->get_api_code(), array( 'RATE_LIMIT_EXCEEDED', 'TRANSPORT_ERROR', 'SERVICE_UNAVAILABLE' ), true ) || $error->get_http_status() >= 500 ) {
					// Stop the run early; the cursor keeps this batch for the next pass.
					$highest       = $product_id - 1;
					$stopped_early = true;
					break;
				}
			} catch ( Throwable $error ) {
				++$summary['errors'];
				$summary['last_error'] = 'UNEXPECTED_ERROR';
			}
		}

		if ( $highest > 0 ) {
			update_option( self::OPTION_CURSOR, $highest, false );
		}
		if ( ! $stopped_early && count( $ids ) < $limit ) {
			update_option( self::OPTION_CURSOR, 0, false );
			$summary['cycle_complete'] = true;
		}

		$this->record( $summary );
		return $summary;
	}

	public function state() {
		$stored = get_option( self::OPTION_STATE, array() );
		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array( 'last_run' => 0, 'processed' => 0, 'updated' => 0, 'errors' => 0, 'last_error' => '', 'last_cycle' => 0 )
		);
	}

	/** Number of mapped products still queued in the current cycle. */
	public function remaining() {
		global $wpdb;
		$cursor = absint( get_option( self::OPTION_CURSOR, 0 ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'yes'
				 WHERE p.post_type IN ('product','product_variation')
				 AND p.post_status NOT IN ('trash','auto-draft')
				 AND p.ID > %d",
				DST2T_Product::META_ENABLED,
				$cursor
			)
		);
	}

	public function mapped_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'yes'
				 WHERE p.post_type IN ('product','product_variation')
				 AND p.post_status NOT IN ('trash','auto-draft')",
				DST2T_Product::META_ENABLED
			)
		);
	}

	private function next_batch( $limit ) {
		global $wpdb;
		$cursor = absint( get_option( self::OPTION_CURSOR, 0 ) );
		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'yes'
				 WHERE p.post_type IN ('product','product_variation')
				 AND p.post_status NOT IN ('trash','auto-draft')
				 AND p.ID > %d
				 ORDER BY p.ID ASC LIMIT %d",
				DST2T_Product::META_ENABLED,
				$cursor,
				absint( $limit )
			)
		);
	}

	/** An item the supplier withdrew stops selling, but is never deleted or unmapped. */
	private function mark_unavailable( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		if ( 'outofstock' !== $product->get_stock_status() ) {
			$product->set_stock_status( 'outofstock' );
			$product->save();
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				'A mapped product is no longer offered upstream and was set out of stock.',
				array( 'source' => 'delicat-shop2topup', 'product_id' => absint( $product_id ) )
			);
		}
	}

	private function record( $summary ) {
		$state               = $this->state();
		$state['last_run']   = time();
		$state['processed']  = absint( $summary['processed'] );
		$state['updated']    = absint( $summary['updated'] );
		$state['errors']     = absint( $summary['errors'] );
		$state['last_error'] = (string) $summary['last_error'];
		if ( ! empty( $summary['cycle_complete'] ) ) {
			$state['last_cycle'] = time();
		}
		update_option( self::OPTION_STATE, $state, false );
	}
}
