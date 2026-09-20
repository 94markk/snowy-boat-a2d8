<?php

defined( 'ABSPATH' ) || exit;

final class DST2T_Admin {
	// Admin menu slug. Kept free of the provider name so the URL in the address
	// bar is safe to show in a screen share or a support screenshot.
	const PAGE  = 'delicat-topup';
	const TABS  = array( 'dashboard', 'catalog', 'orders', 'settings', 'tools' );

	/** @var DST2T_Settings */
	private $settings;

	/** @var DST2T_API_Client */
	private $api;

	/** @var DST2T_Repository */
	private $repository;

	/** @var DST2T_Product */
	private $products;

	/** @var DST2T_Fulfillment */
	private $fulfillment;

	/** @var DST2T_Balance */
	private $balance;

	/** @var DST2T_Sync */
	private $sync;

	/** @var array|null Per-request cache of the repository status counts. */
	private $counts;

	public function __construct( DST2T_Settings $settings, DST2T_API_Client $api, DST2T_Repository $repository, DST2T_Product $products, DST2T_Fulfillment $fulfillment, DST2T_Balance $balance, DST2T_Sync $sync ) {
		$this->settings    = $settings;
		$this->api         = $api;
		$this->repository  = $repository;
		$this->products    = $products;
		$this->fulfillment = $fulfillment;
		$this->balance     = $balance;
		$this->sync        = $sync;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_dst2t_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_dst2t_test_connection', array( $this, 'test_connection' ) );
		add_action( 'admin_post_dst2t_sync_now', array( $this, 'sync_now' ) );
		add_action( 'admin_post_dst2t_import_catalog', array( $this, 'import_catalog' ) );
		add_action( 'admin_post_dst2t_refresh_product', array( $this, 'refresh_product' ) );
		add_action( 'admin_post_dst2t_refresh_balance', array( $this, 'refresh_balance' ) );
		add_action( 'admin_post_dst2t_sync_catalog', array( $this, 'sync_catalog' ) );
		add_action( 'admin_post_dst2t_rotate_webhook', array( $this, 'rotate_webhook' ) );
		add_action( 'admin_post_dst2t_sync_order', array( $this, 'sync_order' ) );
		add_action( 'admin_post_dst2t_retry_order', array( $this, 'retry_order' ) );
		add_action( 'admin_post_dst2t_rewrite_skus', array( $this, 'rewrite_skus' ) );

		// Fulfillment state on the WooCommerce order list, HPOS and legacy.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'order_column_content' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'order_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'order_column_content' ), 10, 2 );
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			/* translators: %s: neutral supplier label. */
			sprintf( __( '%s Integration', 'delicat-shop2topup' ), DST2T_Brand::label() ),
			DST2T_Brand::short_label(),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'page' )
		);
	}

	public function assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'dst2t-admin', DST2T_URL . 'assets/admin.css', array(), DST2T_VERSION );
		wp_enqueue_script( 'dst2t-admin', DST2T_URL . 'assets/admin.js', array(), DST2T_VERSION, true );
		wp_localize_script(
			'dst2t-admin',
			'dst2tAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'dst2t_balance' ),
				'poll'     => max( 30, (int) round( $this->balance->interval_seconds() / 2 ) ),
				'auto'     => $this->balance->auto_enabled(),
				'strings'  => array(
					'reveal'     => __( 'Reveal', 'delicat-shop2topup' ),
					'hide'       => __( 'Hide', 'delicat-shop2topup' ),
					'copied'     => __( 'Copied', 'delicat-shop2topup' ),
					'copy'       => __( 'Copy', 'delicat-shop2topup' ),
					'refreshing' => __( 'Refreshing…', 'delicat-shop2topup' ),
					'failed'     => __( 'Could not reach the provider.', 'delicat-shop2topup' ),
				),
			)
		);
	}

	public function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this integration.', 'delicat-shop2topup' ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, self::TABS, true ) ) {
			$tab = 'dashboard';
		}
		$state = $this->balance->state();
		?>
		<div class="wrap dst2t-admin<?php echo DST2T_Brand::masking() ? ' dst2t-masked' : ''; ?>">
			<div class="dst2t-titlebar">
				<h1><?php echo esc_html( DST2T_Brand::label() ); ?></h1>
				<div class="dst2t-pills">
					<span class="dst2t-pill <?php echo $this->settings->credentials_configured() ? 'is-ok' : 'is-warn'; ?>">
						<?php echo $this->settings->credentials_configured() ? esc_html__( 'Connected', 'delicat-shop2topup' ) : esc_html__( 'Not configured', 'delicat-shop2topup' ); ?>
					</span>
					<span class="dst2t-pill <?php echo $state['low'] ? 'is-warn' : 'is-neutral'; ?>">
						<?php esc_html_e( 'Balance', 'delicat-shop2topup' ); ?>
						<strong data-dst2t-balance><?php echo esc_html( '' !== $state['formatted'] ? $state['formatted'] : '—' ); ?></strong>
					</span>
					<span class="dst2t-pill is-neutral"><?php echo esc_html( sprintf( /* translators: %d: number of supplier orders awaiting review. */ _n( '%d needs review', '%d need review', $this->review_count(), 'delicat-shop2topup' ), $this->review_count() ) ); ?></span>
				</div>
			</div>
			<nav class="nav-tab-wrapper">
				<?php
				$this->tab_link( 'dashboard', __( 'Dashboard', 'delicat-shop2topup' ), $tab );
				$this->tab_link( 'catalog', __( 'Catalog', 'delicat-shop2topup' ), $tab );
				$this->tab_link( 'orders', __( 'Orders', 'delicat-shop2topup' ), $tab );
				$this->tab_link( 'settings', __( 'Settings', 'delicat-shop2topup' ), $tab );
				$this->tab_link( 'tools', __( 'Tools', 'delicat-shop2topup' ), $tab );
				?>
			</nav>
			<?php
			if ( 'settings' === $tab ) {
				$this->settings_tab();
			} elseif ( 'catalog' === $tab ) {
				$this->catalog_tab();
			} elseif ( 'orders' === $tab ) {
				$this->orders_tab();
			} elseif ( 'tools' === $tab ) {
				$this->tools_tab();
			} else {
				$this->dashboard_tab( $state );
			}
			?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------- actions */

	public function save_settings() {
		$this->authorize( 'dst2t_save_settings' );
		$result = $this->settings->save( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'settings', 'error', $result->get_error_message() );
		}
		// Interval changes take effect on the next request through ensure_schedule().
		delete_option( DST2T_Balance::OPTION_SCHEDULE );
		delete_option( DST2T_Sync::OPTION_SCHEDULE );
		$this->balance->ensure_schedule();
		$this->sync->ensure_schedule();
		$this->redirect_with_notice( 'settings', 'success', __( 'Settings saved.', 'delicat-shop2topup' ) );
	}

	public function test_connection() {
		$this->authorize( 'dst2t_test_connection' );
		$state = $this->balance->refresh( true );
		if ( '' !== $state['error'] ) {
			$this->redirect_with_notice( 'settings', 'error', sprintf( /* translators: %s: error code. */ __( 'Connection failed: %s.', 'delicat-shop2topup' ), $state['error'] ) );
		}
		$this->redirect_with_notice(
			'dashboard',
			'success',
			sprintf(
				/* translators: %s: formatted wallet balance. */
				__( 'Connection successful. Wallet balance: %s.', 'delicat-shop2topup' ),
				'' !== $state['formatted'] ? $state['formatted'] : __( 'unavailable', 'delicat-shop2topup' )
			)
		);
	}

	public function refresh_balance() {
		$this->authorize( 'dst2t_refresh_balance' );
		$state = $this->balance->refresh( true );
		if ( '' !== $state['error'] ) {
			$this->redirect_with_notice( 'dashboard', 'error', sprintf( /* translators: %s: error code. */ __( 'Balance refresh failed: %s.', 'delicat-shop2topup' ), $state['error'] ) );
		}
		$this->redirect_with_notice( 'dashboard', 'success', sprintf( /* translators: %s: formatted wallet balance. */ __( 'Wallet balance updated: %s.', 'delicat-shop2topup' ), $state['formatted'] ) );
	}

	public function sync_now() {
		$this->authorize( 'dst2t_sync_now' );
		$this->fulfillment->reconcile();
		$this->redirect_with_notice( 'dashboard', 'success', __( 'Reconciliation requested. Check the order list and logs for results; rate limits may defer checks.', 'delicat-shop2topup' ) );
	}

	public function sync_catalog() {
		$this->authorize( 'dst2t_sync_catalog' );
		$summary = $this->sync->run();
		$this->redirect_with_notice(
			'tools',
			$summary['errors'] ? 'error' : 'success',
			sprintf(
				/* translators: 1: products processed, 2: products updated, 3: error count. */
				__( 'Catalog sync batch finished. Processed %1$d, updated %2$d, errors %3$d.', 'delicat-shop2topup' ),
				$summary['processed'],
				$summary['updated'],
				$summary['errors']
			)
		);
	}

	public function rotate_webhook() {
		$this->authorize( 'dst2t_rotate_webhook' );
		DST2T_Webhook::rotate_token();
		$this->redirect_with_notice( 'dashboard', 'success', __( 'A new private callback URL was generated. Register it upstream now — the previous one no longer accepts events.', 'delicat-shop2topup' ) );
	}

	public function sync_order() {
		$this->authorize( 'dst2t_sync_order' );
		$uuid = isset( $_POST['provider_order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['provider_order_id'] ) ) : '';
		$tab  = isset( $_POST['return_tab'] ) && in_array( sanitize_key( wp_unslash( $_POST['return_tab'] ) ), self::TABS, true ) ? sanitize_key( wp_unslash( $_POST['return_tab'] ) ) : 'orders';
		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $uuid ) || ! $this->repository->get( $uuid ) ) {
			$this->redirect_with_notice( $tab, 'error', __( 'Unknown fulfillment record.', 'delicat-shop2topup' ) );
		}
		try {
			$this->fulfillment->apply_provider_order( $uuid, $this->api->order( $uuid ), 'manual' );
			$this->redirect_with_notice( $tab, 'success', __( 'Fulfillment record synchronized.', 'delicat-shop2topup' ) );
		} catch ( DST2T_API_Exception $error ) {
			$this->redirect_with_notice( $tab, 'error', sprintf( /* translators: %s: error code. */ __( 'Synchronization failed: %s.', 'delicat-shop2topup' ), $error->get_api_code() ) );
		} catch ( Throwable $error ) {
			$this->redirect_with_notice( $tab, 'error', __( 'Synchronization could not run.', 'delicat-shop2topup' ) );
		}
	}

	/**
	 * Puts one stalled fulfillment back into the worker queue.
	 *
	 * The row is reopened as `unknown`, never as a fresh intent: if a previous
	 * attempt did reach the provider, the worker looks the UUID up before it is
	 * allowed to create anything, so a retry can never buy the same item twice.
	 */
	public function retry_order() {
		$this->authorize( 'dst2t_retry_order' );
		$uuid = isset( $_POST['provider_order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['provider_order_id'] ) ) : '';
		$tab  = isset( $_POST['return_tab'] ) && in_array( sanitize_key( wp_unslash( $_POST['return_tab'] ) ), self::TABS, true ) ? sanitize_key( wp_unslash( $_POST['return_tab'] ) ) : 'orders';

		$row = preg_match( '/^[0-9a-f-]{36}$/i', $uuid ) ? $this->repository->get( $uuid ) : null;
		if ( ! $row ) {
			$this->redirect_with_notice( $tab, 'error', __( 'Unknown fulfillment record.', 'delicat-shop2topup' ) );
		}
		if ( in_array( $row['status'], array( 'completed', 'refunded' ), true ) ) {
			$this->redirect_with_notice( $tab, 'error', __( 'Settled fulfillments cannot be retried. Use Sync to re-read the current state.', 'delicat-shop2topup' ) );
		}

		$order = wc_get_order( absint( $row['wc_order_id'] ) );
		$item  = $order ? $order->get_item( absint( $row['wc_item_id'] ) ) : false;
		if ( ! $order || ! $item instanceof WC_Order_Item_Product ) {
			$this->redirect_with_notice( $tab, 'error', __( 'The WooCommerce order or line item no longer exists.', 'delicat-shop2topup' ) );
		}
		if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			$this->redirect_with_notice( $tab, 'error', __( 'This order was cancelled or refunded. Fulfillment is intentionally not retried.', 'delicat-shop2topup' ) );
		}

		$this->repository->update( $uuid, 'unknown', array( 'next_check_at' => time() - MINUTE_IN_SECONDS, 'last_error_code' => '' ) );
		$item->update_meta_data( '_dst2t_status', 'unknown' );
		$item->save();

		// The worker only acts on paid orders, so a held order has to be reopened.
		if ( $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'processing', __( 'Digital fulfillment retried by a store manager.', 'delicat-shop2topup' ) );
		}
		// A backoff job may already be pending hours out; displace it so the retry
		// the operator just asked for actually runs now.
		$this->fulfillment->reschedule_item( $order->get_id(), absint( $row['wc_item_id'] ), 0 );

		$this->redirect_with_notice( $tab, 'success', __( 'Fulfillment queued for another attempt. Watch the status; no duplicate purchase can be made.', 'delicat-shop2topup' ) );
	}

	/**
	 * Replaces provider-derived SKUs left behind by earlier versions.
	 *
	 * Before 1.2.0 the SKU was the provider's own item id with a recognisable
	 * prefix, and WooCommerce publishes SKUs to customers, to JSON-LD, and to the
	 * unauthenticated Store API. Rewriting is an explicit operator decision
	 * because a SKU may already appear on invoices and in external systems.
	 */
	public function rewrite_skus() {
		$this->authorize( 'dst2t_rewrite_skus' );

		$rewritten = 0;
		$skipped   = 0;
		foreach ( $this->legacy_sku_products() as $product_id ) {
			$product = wc_get_product( $product_id );
			$item_id = absint( get_post_meta( $product_id, DST2T_Product::META_ITEM_ID, true ) );
			if ( ! $product || ! $item_id ) {
				++$skipped;
				continue;
			}
			try {
				$product->set_sku( DST2T_Brand::sku_for_item( $item_id ) );
				$product->save();
				++$rewritten;
			} catch ( Throwable $error ) {
				++$skipped;
			}
		}

		$this->redirect_with_notice(
			'tools',
			$skipped ? 'error' : 'success',
			sprintf(
				/* translators: 1: number of products rewritten, 2: number skipped. */
				__( '%1$d SKUs replaced with opaque ones, %2$d skipped.', 'delicat-shop2topup' ),
				$rewritten,
				$skipped
			)
		);
	}

	/** Product ids whose SKU still encodes the provider's item id. */
	private function legacy_sku_products() {
		global $wpdb;
		return array_map(
			'absint',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT sku.post_id FROM {$wpdb->postmeta} sku
					 INNER JOIN {$wpdb->postmeta} item ON item.post_id = sku.post_id AND item.meta_key = %s
					 INNER JOIN {$wpdb->posts} p ON p.ID = sku.post_id
					 WHERE sku.meta_key = '_sku' AND sku.meta_value LIKE %s
					 AND p.post_type IN ('product','product_variation')
					 AND p.post_status NOT IN ('trash','auto-draft')
					 LIMIT 500",
					DST2T_Product::META_ITEM_ID,
					$wpdb->esc_like( 's2t-' ) . '%'
				)
			)
		);
	}

	public function refresh_product() {
		$this->authorize( 'dst2t_refresh_product' );
		$product_id = isset( $_REQUEST['product_id'] ) ? absint( $_REQUEST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $product_id || ! current_user_can( 'edit_post', $product_id ) ) {
			$this->redirect_with_notice( 'catalog', 'error', __( 'Invalid product.', 'delicat-shop2topup' ) );
		}
		try {
			$this->products->refresh_mapping( $product_id );
			$message = __( 'Requirements, live cost, and availability refreshed.', 'delicat-shop2topup' );
			$type    = 'success';
		} catch ( Throwable $error ) {
			$message = __( 'The product could not be refreshed.', 'delicat-shop2topup' );
			$type    = 'error';
		}

		$return = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $return ) {
			set_transient( 'dst2t_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => wp_strip_all_tags( $message ) ), 60 );
			wp_safe_redirect( $return );
			exit;
		}
		$this->redirect_with_notice( 'catalog', $type, $message );
	}

	public function import_catalog() {
		$this->authorize( 'dst2t_import_catalog' );
		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$big_id      = isset( $_POST['big_id'] ) ? absint( $_POST['big_id'] ) : 0;
		$requested   = isset( $_POST['item_ids'] ) ? array_values( array_unique( array_map( 'absint', (array) $_POST['item_ids'] ) ) ) : array();
		$item_ids    = array_slice( $requested, 0, 50 );
		$skipped     = max( 0, count( $requested ) - count( $item_ids ) );
		$return      = array( 'category_id' => $category_id, 'big_id' => $big_id );

		if ( ! $category_id || ! $item_ids ) {
			$this->redirect_with_notice( 'catalog', 'error', __( 'Choose at least one catalog item.', 'delicat-shop2topup' ), $return );
		}

		try {
			$items         = $this->api->subcategories( $category_id );
			$schema        = $this->api->requirements( $category_id );
			$category_name = isset( $_POST['category_name'] ) ? sanitize_text_field( wp_unslash( $_POST['category_name'] ) ) : '';
			$count         = 0;
			foreach ( $items as $catalog_item ) {
				$item_id = isset( $catalog_item['item_id'] ) ? absint( $catalog_item['item_id'] ) : 0;
				if ( ! $item_id || ! in_array( $item_id, $item_ids, true ) ) {
					continue;
				}
				$this->import_item( $category_id, $catalog_item, $schema, $category_name );
				++$count;
			}
			$message = sprintf(
				/* translators: %d: number of products. */
				_n( '%d product imported or updated as a draft.', '%d products imported or updated as drafts.', $count, 'delicat-shop2topup' ),
				$count
			);
			if ( $skipped ) {
				$message .= ' ' . sprintf(
					/* translators: %d: number of items left out of this import run. */
					_n( '%d further selected item was left out: imports run 50 at a time. Import the rest in a second pass.', '%d further selected items were left out: imports run 50 at a time. Import the rest in a second pass.', $skipped, 'delicat-shop2topup' ),
					$skipped
				);
			}
			$this->redirect_with_notice( 'catalog', $skipped ? 'error' : 'success', $message, $return );
		} catch ( DST2T_API_Exception $error ) {
			$this->redirect_with_notice( 'catalog', 'error', sprintf( /* translators: %s: error code. */ __( 'Catalog import failed: %s.', 'delicat-shop2topup' ), $error->get_api_code() ), $return );
		} catch ( Throwable $error ) {
			$this->redirect_with_notice( 'catalog', 'error', __( 'Catalog import stopped safely before an invalid product could be published.', 'delicat-shop2topup' ), $return );
		}
	}

	public function notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$key    = 'dst2t_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
			delete_transient( $key );
			$type = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		if ( DST2T_Vault::needs_rekey() ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'A stored secret could only be read with a superseded encryption key — usually after a site URL change, a salt rotation, or a staging clone. Re-save your credentials so they are re-encrypted with the current key.', 'delicat-shop2topup' )
				. '</p></div>';
		}

		$state = $this->balance->state();
		if ( $state['configured'] && $state['low'] ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html(
					sprintf(
						/* translators: 1: formatted wallet balance, 2: configured threshold. */
						__( 'Top-up wallet balance is %1$s, below your alert threshold of %2$s. Digital orders will fail once the wallet is empty.', 'delicat-shop2topup' ),
						'' !== $state['formatted'] ? $state['formatted'] : __( 'unknown', 'delicat-shop2topup' ),
						$state['threshold_formatted']
					)
				)
				. '</p></div>';
		}
	}

	/* ---------------------------------------------------------------- tabs */

	private function dashboard_tab( $state ) {
		$counts    = $this->counts();
		$recent    = $this->repository->recent( 20 );
		$sync      = $this->sync->state();
		$webhook   = DST2T_Webhook::last_event();
		$scheduler = function_exists( 'as_schedule_recurring_action' ) ? __( 'Action Scheduler', 'delicat-shop2topup' ) : __( 'WP-Cron fallback', 'delicat-shop2topup' );
		?>
		<div class="dst2t-grid">
			<section class="dst2t-card dst2t-card-balance<?php echo $state['low'] ? ' is-low' : ''; ?>">
				<h2><?php esc_html_e( 'Wallet balance', 'delicat-shop2topup' ); ?></h2>
				<p class="dst2t-amount" data-dst2t-balance><?php echo esc_html( '' !== $state['formatted'] ? $state['formatted'] : '—' ); ?></p>
				<p class="dst2t-meta">
					<span data-dst2t-balance-age><?php echo esc_html( $this->balance->age_label( $state ) ); ?></span>
					<?php if ( $state['auto'] ) : ?>
						· <?php echo esc_html( sprintf( /* translators: %s: human readable interval. */ __( 'auto every %s', 'delicat-shop2topup' ), human_time_diff( 0, $this->balance->interval_seconds() ) ) ); ?>
					<?php else : ?>
						· <?php esc_html_e( 'automatic refresh is off', 'delicat-shop2topup' ); ?>
					<?php endif; ?>
				</p>
				<?php if ( '' !== $state['error'] ) : ?>
					<p class="dst2t-error"><?php echo esc_html( sprintf( /* translators: %s: error code. */ __( 'Last check failed: %s', 'delicat-shop2topup' ), $state['error'] ) ); ?></p>
				<?php endif; ?>
				<p class="dst2t-actions">
					<button type="button" class="button button-secondary" data-dst2t-refresh-balance><?php esc_html_e( 'Refresh now', 'delicat-shop2topup' ); ?></button>
				</p>
			</section>

			<section class="dst2t-card">
				<h2><?php esc_html_e( 'Account', 'delicat-shop2topup' ); ?></h2>
				<ul class="dst2t-list">
					<li><span><?php esc_html_e( 'Credentials', 'delicat-shop2topup' ); ?></span><strong><?php echo $this->settings->credentials_configured() ? esc_html__( 'Configured', 'delicat-shop2topup' ) : esc_html__( 'Missing', 'delicat-shop2topup' ); ?></strong></li>
					<li><span><?php esc_html_e( 'Account enabled', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $this->tri_state( $state['enabled'] ) ); ?></strong></li>
					<li><span><?php esc_html_e( 'Account verified', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $this->tri_state( $state['verified'] ) ); ?></strong></li>
					<li><span><?php esc_html_e( 'Last response time', 'delicat-shop2topup' ); ?></span><strong><?php echo $state['latency_ms'] ? esc_html( $state['latency_ms'] . ' ms' ) : '—'; ?></strong></li>
				</ul>
				<p class="dst2t-actions">
					<?php $this->action_button( 'dst2t_test_connection', __( 'Test connection', 'delicat-shop2topup' ) ); ?>
				</p>
			</section>

			<section class="dst2t-card">
				<h2><?php esc_html_e( 'Fulfillment queue', 'delicat-shop2topup' ); ?></h2>
				<div class="dst2t-stats">
					<?php
					$groups = array(
						'in_progress' => array( __( 'In progress', 'delicat-shop2topup' ), array( 'intent', 'submitting', 'pending', 'processing', 'retrying', 'unknown' ) ),
						'completed'   => array( __( 'Completed', 'delicat-shop2topup' ), array( 'completed' ) ),
						'review'      => array( __( 'Needs review', 'delicat-shop2topup' ), array( 'partial', 'failed', 'refunded', 'submission_failed', 'price_blocked', 'voucher_missing', 'voucher_storage_error', 'manual_review' ) ),
					);
					foreach ( $groups as $slug => $group ) :
						$total = 0;
						foreach ( $group[1] as $status ) {
							$total += isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0;
						}
						?>
						<span class="dst2t-stat dst2t-stat-<?php echo esc_attr( $slug ); ?>"><strong><?php echo esc_html( $total ); ?></strong><?php echo esc_html( $group[0] ); ?></span>
					<?php endforeach; ?>
				</div>
				<p class="dst2t-actions">
					<?php $this->action_button( 'dst2t_sync_now', __( 'Reconcile now', 'delicat-shop2topup' ), 'primary' ); ?>
				</p>
			</section>

			<section class="dst2t-card">
				<h2><?php esc_html_e( 'Automation health', 'delicat-shop2topup' ); ?></h2>
				<ul class="dst2t-list">
					<li><span><?php esc_html_e( 'Scheduler', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $scheduler ); ?></strong></li>
					<li><span><?php esc_html_e( 'Next reconcile', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $this->when( $this->fulfillment->next_reconcile() ) ); ?></strong></li>
					<li><span><?php esc_html_e( 'Next balance check', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $this->when( $this->balance->next_run() ) ); ?></strong></li>
					<li><span><?php esc_html_e( 'Next catalog sync', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $this->sync->interval_seconds() ? $this->when( $this->sync->next_run() ) : __( 'off', 'delicat-shop2topup' ) ); ?></strong></li>
					<li><span><?php esc_html_e( 'Mapped products', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $this->sync->mapped_count() ); ?></strong></li>
				</ul>
				<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ! function_exists( 'as_schedule_recurring_action' ) ) : ?>
					<p class="dst2t-error"><?php esc_html_e( 'WP-Cron is disabled and Action Scheduler is unavailable. Configure a server cron or background jobs will not run.', 'delicat-shop2topup' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="dst2t-card dst2t-card-wide">
				<h2><?php esc_html_e( 'Delivery callback', 'delicat-shop2topup' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Register this exact HTTPS callback in the provider panel. The URL contains a private token, so treat it like a password.', 'delicat-shop2topup' ); ?></p>
				<div class="dst2t-copyrow">
					<input class="large-text code" type="<?php echo DST2T_Brand::masking() ? 'password' : 'text'; ?>" readonly data-dst2t-url value="<?php echo esc_attr( DST2T_Webhook::url() ); ?>" />
					<button type="button" class="button" data-dst2t-toggle-url><?php esc_html_e( 'Reveal', 'delicat-shop2topup' ); ?></button>
					<button type="button" class="button" data-dst2t-copy-url><?php esc_html_e( 'Copy', 'delicat-shop2topup' ); ?></button>
				</div>
				<ul class="dst2t-list">
					<li><span><?php esc_html_e( 'Last signed test', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( get_option( 'dst2t_last_webhook_test', '—' ) ); ?></strong></li>
					<li><span><?php esc_html_e( 'Last event received', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $webhook['at'] ? $webhook['event'] . ' · ' . $this->when( (int) $webhook['at'] ) : '—' ); ?></strong></li>
					<li><span><?php esc_html_e( 'Last catalog sync', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $sync['last_run'] ? $this->when( (int) $sync['last_run'] ) : __( 'never', 'delicat-shop2topup' ) ); ?></strong></li>
				</ul>
				<p class="dst2t-actions">
					<?php $this->action_button( 'dst2t_rotate_webhook', __( 'Generate a new callback URL', 'delicat-shop2topup' ) ); ?>
				</p>
			</section>
		</div>

		<h2><?php esc_html_e( 'Recent fulfillments', 'delicat-shop2topup' ); ?></h2>
		<?php $this->orders_table( $recent, 'dashboard' ); ?>
		<?php
	}

	private function orders_tab() {
		$filter   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 25;
		$rows     = $this->repository->search( $filter, $per_page, ( $paged - 1 ) * $per_page );
		$total    = $this->repository->count_matching( $filter );
		$counts   = $this->counts();
		?>
		<form method="get" class="dst2t-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="hidden" name="tab" value="orders" />
			<label for="dst2t-status"><strong><?php esc_html_e( 'Status', 'delicat-shop2topup' ); ?></strong></label>
			<select id="dst2t-status" name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'delicat-shop2topup' ); ?></option>
				<?php foreach ( $counts as $status => $total_for_status ) : ?>
					<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filter, $status ); ?>><?php echo esc_html( $this->status_label( $status ) . ' (' . $total_for_status . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'delicat-shop2topup' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		$this->orders_table( $rows, 'orders' );
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}
	}

	private function orders_table( $rows, $return_tab ) {
		?>
		<table class="widefat striped dst2t-orders">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'delicat-shop2topup' ); ?></th>
					<th><?php esc_html_e( 'Fulfillment reference', 'delicat-shop2topup' ); ?></th>
					<th><?php esc_html_e( 'Status', 'delicat-shop2topup' ); ?></th>
					<th><?php esc_html_e( 'Attempts', 'delicat-shop2topup' ); ?></th>
					<th><?php esc_html_e( 'Updated (UTC)', 'delicat-shop2topup' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'delicat-shop2topup' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No fulfillments recorded yet.', 'delicat-shop2topup' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php $order = wc_get_order( absint( $row['wc_order_id'] ) ); ?>
					<tr>
						<td>
							<?php if ( $order ) : ?>
								<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
							<?php else : ?>
								<?php echo '#' . esc_html( $row['wc_order_id'] ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo DST2T_Brand::secret_html( $row['provider_order_id'], 6 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td>
							<span class="dst2t-status dst2t-status-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $this->status_label( $row['status'] ) ); ?></span>
							<?php if ( ! empty( $row['last_error_code'] ) ) : ?>
								<br /><small class="dst2t-muted"><?php echo esc_html( $row['last_error_code'] ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $row['attempt_count'] ) ? $row['attempt_count'] : 0 ); ?></td>
						<td><?php echo esc_html( $row['updated_at'] ); ?></td>
						<td class="dst2t-rowactions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="dst2t_sync_order" />
								<input type="hidden" name="provider_order_id" value="<?php echo esc_attr( $row['provider_order_id'] ); ?>" />
								<input type="hidden" name="return_tab" value="<?php echo esc_attr( $return_tab ); ?>" />
								<?php wp_nonce_field( 'dst2t_sync_order' ); ?>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Sync', 'delicat-shop2topup' ); ?></button>
							</form>
							<?php if ( ! in_array( $row['status'], array( 'completed', 'refunded' ), true ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dst2t-confirm="<?php esc_attr_e( 'Retry this fulfillment? A held order is returned to processing. The provider order is looked up first, so nothing can be purchased twice.', 'delicat-shop2topup' ); ?>">
									<input type="hidden" name="action" value="dst2t_retry_order" />
									<input type="hidden" name="provider_order_id" value="<?php echo esc_attr( $row['provider_order_id'] ); ?>" />
									<input type="hidden" name="return_tab" value="<?php echo esc_attr( $return_tab ); ?>" />
									<?php wp_nonce_field( 'dst2t_retry_order' ); ?>
									<button type="submit" class="button button-small"><?php esc_html_e( 'Retry', 'delicat-shop2topup' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function settings_tab() {
		$constants = defined( 'DELICAT_S2T_KEY_ID' ) || defined( 'DELICAT_S2T_KEY_SECRET' ) || defined( 'DELICAT_S2T_WEBHOOK_SECRET' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dst2t-settings">
			<input type="hidden" name="action" value="dst2t_save_settings" />
			<?php wp_nonce_field( 'dst2t_save_settings' ); ?>

			<h2><?php esc_html_e( 'API credentials', 'delicat-shop2topup' ); ?></h2>
			<p><?php esc_html_e( 'Secrets are encrypted at rest with AES-256-GCM. For the strongest protection, define the DELICAT_S2T_* constants in wp-config.php, including a stable encryption key.', 'delicat-shop2topup' ); ?></p>
			<?php if ( $constants ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'One or more credentials are controlled by wp-config.php and override saved values.', 'delicat-shop2topup' ); ?></p></div>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="api_key_id"><?php esc_html_e( 'Key ID', 'delicat-shop2topup' ); ?></label></th><td><input class="regular-text" id="api_key_id" name="api_key_id" type="password" autocomplete="new-password" placeholder="<?php echo $this->settings->api_key_id() ? esc_attr( '••••••••' ) : ''; ?>" /></td></tr>
				<tr><th><label for="api_secret"><?php esc_html_e( 'Key secret', 'delicat-shop2topup' ); ?></label></th><td><input class="regular-text" id="api_secret" name="api_secret" type="password" autocomplete="new-password" placeholder="<?php echo $this->settings->api_secret() ? esc_attr( '••••••••' ) : ''; ?>" /></td></tr>
				<tr><th><label for="webhook_secret"><?php esc_html_e( 'Callback signing secret', 'delicat-shop2topup' ); ?></label></th><td><input class="regular-text" id="webhook_secret" name="webhook_secret" type="password" autocomplete="new-password" placeholder="<?php echo $this->settings->webhook_secret() ? esc_attr( '••••••••' ) : ''; ?>" /></td></tr>
				<tr><th><?php esc_html_e( 'Clear secrets', 'delicat-shop2topup' ); ?></th><td><label><input type="checkbox" name="clear_credentials" value="1" /> <?php esc_html_e( 'Remove all credentials saved in WordPress (constants are unchanged)', 'delicat-shop2topup' ); ?></label></td></tr>
			</table>

			<h2><?php esc_html_e( 'Supplier privacy', 'delicat-shop2topup' ); ?></h2>
			<p><?php esc_html_e( 'Keeps the upstream provider out of your storefront, your customer emails, and your public REST index.', 'delicat-shop2topup' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="brand_label"><?php esc_html_e( 'Provider label', 'delicat-shop2topup' ); ?></label></th>
					<td>
						<input class="regular-text" id="brand_label" name="brand_label" type="text" maxlength="60" value="<?php echo esc_attr( $this->settings->get( 'brand_label', '' ) ); ?>" placeholder="<?php esc_attr_e( 'Top-Up Provider', 'delicat-shop2topup' ); ?>" />
						<p class="description"><?php esc_html_e( 'Shown everywhere in WordPress instead of the provider name, including this menu entry.', 'delicat-shop2topup' ); ?></p>
					</td>
				</tr>
				<?php
				$this->checkbox_row( 'stealth_mode', __( 'Hide the provider from the storefront', 'delicat-shop2topup' ), __( 'Inline the storefront assets, scrub provider names from imported catalog copy, strip fulfillment metadata from customer order views and emails, and hide the callback route from the public REST index.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'mask_identifiers', __( 'Mask identifiers in the dashboard', 'delicat-shop2topup' ), __( 'Fulfillment references and the callback URL are shown masked until you reveal them.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'private_webhook', __( 'Use an unbranded private callback URL', 'delicat-shop2topup' ), __( 'Serves the callback from a neutral REST route with a secret token.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'legacy_webhook', __( 'Keep the old callback URL working', 'delicat-shop2topup' ), __( 'The original URL contains the provider name. Leave this on until the provider panel is sending to the new URL and you have seen an event arrive, then turn it off to remove that path entirely.', 'delicat-shop2topup' ) );
				?>
				<tr>
					<th><label for="import_sku_prefix"><?php esc_html_e( 'Imported SKU prefix', 'delicat-shop2topup' ); ?></label></th>
					<td><input class="small-text" id="import_sku_prefix" name="import_sku_prefix" type="text" maxlength="12" value="<?php echo esc_attr( $this->settings->get( 'import_sku_prefix', 'tu-' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'SKUs are visible to customers, so this prefix should not identify the provider. Existing SKUs are never renamed.', 'delicat-shop2topup' ); ?></p></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Wallet balance', 'delicat-shop2topup' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php $this->checkbox_row( 'balance_auto_refresh', __( 'Update the balance automatically', 'delicat-shop2topup' ), __( 'Refreshes the wallet balance in the background and while the dashboard is open.', 'delicat-shop2topup' ) ); ?>
				<tr><th><label for="balance_interval"><?php esc_html_e( 'Refresh interval', 'delicat-shop2topup' ); ?></label></th><td><input id="balance_interval" name="balance_interval" type="number" min="1" max="1440" step="1" value="<?php echo esc_attr( $this->settings->get( 'balance_interval', '10' ) ); ?>" /> <?php esc_html_e( 'minutes', 'delicat-shop2topup' ); ?></td></tr>
				<tr><th><label for="low_balance_threshold"><?php esc_html_e( 'Low balance alert', 'delicat-shop2topup' ); ?></label></th><td><input id="low_balance_threshold" name="low_balance_threshold" type="text" inputmode="decimal" value="<?php echo esc_attr( $this->settings->get( 'low_balance_threshold', '0.000000' ) ); ?>" /> USD<p class="description"><?php esc_html_e( 'Warn when the wallet falls below this amount. Set 0 to disable.', 'delicat-shop2topup' ); ?></p></td></tr>
				<?php
				$this->checkbox_row( 'low_balance_email', __( 'Email me on low balance', 'delicat-shop2topup' ), __( 'Sends at most one message every six hours to the WooCommerce stock notification recipient.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'admin_bar_balance', __( 'Show the balance in the admin bar', 'delicat-shop2topup' ), __( 'Visible only to users who can manage WooCommerce.', 'delicat-shop2topup' ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Fulfillment safety', 'delicat-shop2topup' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$this->checkbox_row( 'validate_player', __( 'Validate the player before checkout', 'delicat-shop2topup' ), __( 'Confirms the player name and region before the customer pays.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'auto_complete', __( 'Complete WooCommerce orders automatically', 'delicat-shop2topup' ), __( 'Only when every line item is mapped and every upstream item is completed.', 'delicat-shop2topup' ) );
				?>
				<tr><th><label for="cost_guard_percent"><?php esc_html_e( 'Maximum cost increase', 'delicat-shop2topup' ); ?></label></th><td><input id="cost_guard_percent" name="cost_guard_percent" type="number" min="0" max="1000" step="0.01" value="<?php echo esc_attr( $this->settings->get( 'cost_guard_percent' ) ); ?>" /> %<p class="description"><?php esc_html_e( 'Compared with the cached cost. Set 0 to disable this relative guard.', 'delicat-shop2topup' ); ?></p></td></tr>
			</table>

			<h2><?php esc_html_e( 'Catalog and import', 'delicat-shop2topup' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th><label for="usd_exchange_rate"><?php esc_html_e( '1 USD in store currency', 'delicat-shop2topup' ); ?></label></th><td><input id="usd_exchange_rate" name="usd_exchange_rate" type="number" min="0.000001" step="0.000001" value="<?php echo esc_attr( $this->settings->get( 'usd_exchange_rate' ) ); ?>" /></td></tr>
				<tr><th><label for="markup_percent"><?php esc_html_e( 'Catalog markup', 'delicat-shop2topup' ); ?></label></th><td><input id="markup_percent" name="markup_percent" type="number" min="0" max="1000" step="0.01" value="<?php echo esc_attr( $this->settings->get( 'markup_percent' ) ); ?>" /> %</td></tr>
				<tr>
					<th><label for="catalog_sync_interval"><?php esc_html_e( 'Automatic catalog sync', 'delicat-shop2topup' ); ?></label></th>
					<td>
						<select id="catalog_sync_interval" name="catalog_sync_interval">
							<?php
							$intervals = array(
								0    => __( 'Off', 'delicat-shop2topup' ),
								60   => __( 'Every hour', 'delicat-shop2topup' ),
								360  => __( 'Every 6 hours', 'delicat-shop2topup' ),
								720  => __( 'Every 12 hours', 'delicat-shop2topup' ),
								1440 => __( 'Once a day', 'delicat-shop2topup' ),
							);
							$selected = absint( $this->settings->get( 'catalog_sync_interval', 0 ) );
							foreach ( $intervals as $minutes => $label ) :
								?>
								<option value="<?php echo esc_attr( $minutes ); ?>" <?php selected( $selected, $minutes ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Refreshes cached costs, requirement fields, and availability for mapped products in small batches.', 'delicat-shop2topup' ); ?></p>
					</td>
				</tr>
				<?php
				$this->checkbox_row( 'sync_catalog_prices', __( 'Update WooCommerce prices during sync', 'delicat-shop2topup' ), __( 'Uses the exchange rate and markup above. A sale price you set by hand is never overwritten.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'sync_stock', __( 'Mirror availability', 'delicat-shop2topup' ), __( 'Applies upstream stock when it is reported. A missing stock field is never read as zero.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'import_force_draft', __( 'Always import as draft', 'delicat-shop2topup' ), __( 'New and re-imported products are saved as drafts so you review the title, image, and selling price before they go live.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'import_images', __( 'Import product images', 'delicat-shop2topup' ), __( 'Copies the catalog image into your media library instead of hot-linking it, so no provider URL appears on your site.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'import_categories', __( 'Create product categories on import', 'delicat-shop2topup' ), __( 'Files imported products under a WooCommerce category named after the game or service.', 'delicat-shop2topup' ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Diagnostics and cleanup', 'delicat-shop2topup' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$this->checkbox_row( 'debug_logging', __( 'Debug logging', 'delicat-shop2topup' ), __( 'Writes redacted request timing to WooCommerce logs. Player details and secrets are never logged.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'delete_data_on_uninstall', __( 'Delete data when the plugin is uninstalled', 'delicat-shop2topup' ), __( 'Off by default. Keep it off during upgrades.', 'delicat-shop2topup' ) );
				?>
			</table>
			<?php submit_button( __( 'Save settings', 'delicat-shop2topup' ) ); ?>
		</form>
		<?php
	}

	private function catalog_tab() {
		if ( ! $this->settings->credentials_configured() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Save API credentials before browsing the live catalog.', 'delicat-shop2topup' ) . '</p></div>';
			return;
		}

		$big_id   = isset( $_GET['big_id'] ) ? absint( $_GET['big_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected = isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$big_categories = $this->api->big_categories( true );
		} catch ( DST2T_API_Exception $error ) {
			$big_categories = array();
		}

		try {
			$categories = $this->api->categories( $big_id, true );
			$items      = $selected ? $this->api->subcategories( $selected ) : array();
		} catch ( DST2T_API_Exception $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( /* translators: %s: error code. */ __( 'Catalog unavailable: %s.', 'delicat-shop2topup' ), $error->get_api_code() ) ) . '</p></div>';
			return;
		}

		$category_name = '';
		foreach ( $categories as $category ) {
			if ( isset( $category['id'] ) && absint( $category['id'] ) === $selected ) {
				$category_name = isset( $category['name'] ) ? (string) $category['name'] : '';
			}
		}
		?>
		<form method="get" class="dst2t-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="hidden" name="tab" value="catalog" />
			<?php if ( $big_categories ) : ?>
				<label for="dst2t-big"><strong><?php esc_html_e( 'Section', 'delicat-shop2topup' ); ?></strong></label>
				<select id="dst2t-big" name="big_id">
					<option value="0"><?php esc_html_e( 'All sections', 'delicat-shop2topup' ); ?></option>
					<?php foreach ( $big_categories as $big ) : ?>
						<?php if ( empty( $big['id'] ) ) { continue; } ?>
						<option value="<?php echo esc_attr( absint( $big['id'] ) ); ?>" <?php selected( $big_id, absint( $big['id'] ) ); ?>><?php echo esc_html( DST2T_Brand::scrub( isset( $big['name'] ) ? $big['name'] : $big['id'] ) ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<label for="dst2t-category"><strong><?php esc_html_e( 'Game or service', 'delicat-shop2topup' ); ?></strong></label>
			<select id="dst2t-category" name="category_id">
				<option value=""><?php esc_html_e( 'Choose a category', 'delicat-shop2topup' ); ?></option>
				<?php foreach ( $categories as $category ) : ?>
					<?php if ( empty( $category['id'] ) ) { continue; } ?>
					<option value="<?php echo esc_attr( absint( $category['id'] ) ); ?>" <?php selected( $selected, absint( $category['id'] ) ); ?>><?php echo esc_html( DST2T_Brand::scrub( isset( $category['name'] ) ? $category['name'] : $category['id'] ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Load products', 'delicat-shop2topup' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( $selected && ! $items ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This category has no items right now.', 'delicat-shop2topup' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $selected && $items ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dst2t_import_catalog" />
				<input type="hidden" name="category_id" value="<?php echo esc_attr( $selected ); ?>" />
				<input type="hidden" name="big_id" value="<?php echo esc_attr( $big_id ); ?>" />
				<input type="hidden" name="category_name" value="<?php echo esc_attr( $category_name ); ?>" />
				<?php wp_nonce_field( 'dst2t_import_catalog' ); ?>
				<table class="widefat striped dst2t-catalog-table">
					<thead>
						<tr>
							<td class="check-column"><input class="dst2t-select-all" type="checkbox" /></td>
							<th><?php esc_html_e( 'Item', 'delicat-shop2topup' ); ?></th>
							<th><?php esc_html_e( 'Reference', 'delicat-shop2topup' ); ?></th>
							<th><?php esc_html_e( 'Cost', 'delicat-shop2topup' ); ?></th>
							<th><?php esc_html_e( 'Your price', 'delicat-shop2topup' ); ?></th>
							<th><?php esc_html_e( 'Type', 'delicat-shop2topup' ); ?></th>
							<th><?php esc_html_e( 'In store', 'delicat-shop2topup' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $items as $catalog_item ) : ?>
						<?php
						$item_id  = isset( $catalog_item['item_id'] ) ? absint( $catalog_item['item_id'] ) : 0;
						$existing = $item_id ? $this->find_product_by_item_id( $item_id ) : 0;
						$cost     = isset( $catalog_item['price'] ) ? (string) $catalog_item['price'] : '';
						?>
						<tr>
							<th class="check-column"><input class="dst2t-item-check" type="checkbox" name="item_ids[]" value="<?php echo esc_attr( $item_id ); ?>" /></th>
							<td>
								<strong><?php echo esc_html( DST2T_Brand::scrub( isset( $catalog_item['name'] ) ? $catalog_item['name'] : '' ) ); ?></strong>
								<?php if ( ! empty( $catalog_item['description'] ) ) : ?>
									<br /><small class="dst2t-muted"><?php echo esc_html( DST2T_Brand::scrub( $catalog_item['description'] ) ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo DST2T_Brand::secret_html( (string) $item_id, 3 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo esc_html( '' !== $cost ? $cost . ' USD' : '—' ); ?></td>
							<td><?php echo esc_html( '' !== $cost ? $this->products->selling_price( $cost ) : '—' ); ?></td>
							<td><?php echo ! empty( $catalog_item['returns_voucher'] ) ? esc_html__( 'Voucher', 'delicat-shop2topup' ) : esc_html__( 'Direct top-up', 'delicat-shop2topup' ); ?></td>
							<td>
								<?php if ( $existing ) : ?>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $existing ) ); ?>"><?php echo esc_html( (string) get_post_status( $existing ) ); ?></a>
									<br />
									<a class="dst2t-muted" href="<?php echo esc_url( $this->refresh_product_url( $existing ) ); ?>"><?php esc_html_e( 'Refresh from provider', 'delicat-shop2topup' ); ?></a>
								<?php else : ?>
									<span class="dst2t-muted"><?php esc_html_e( 'Not imported', 'delicat-shop2topup' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php
					echo $this->settings->enabled( 'import_force_draft' )
						? esc_html__( 'Imported products are always saved as drafts. Review the title, image, and selling price, then publish them yourself.', 'delicat-shop2topup' )
						: esc_html__( 'New products are saved as drafts. Re-imported products keep their current status because "Always import as draft" is off.', 'delicat-shop2topup' );
					?>
				</p>
				<?php submit_button( __( 'Import selected products as drafts', 'delicat-shop2topup' ) ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	private function tools_tab() {
		$vault = new DST2T_Vault();
		?>
		<div class="dst2t-grid">
			<section class="dst2t-card">
				<h2><?php esc_html_e( 'Manual jobs', 'delicat-shop2topup' ); ?></h2>
				<p class="dst2t-actions">
					<?php
					$this->action_button( 'dst2t_test_connection', __( 'Test connection', 'delicat-shop2topup' ) );
					$this->action_button( 'dst2t_refresh_balance', __( 'Refresh balance', 'delicat-shop2topup' ) );
					$this->action_button( 'dst2t_sync_now', __( 'Reconcile pending orders', 'delicat-shop2topup' ) );
					$this->action_button( 'dst2t_sync_catalog', __( 'Run one catalog sync batch', 'delicat-shop2topup' ) );
					?>
				</p>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %d: number of products left in the current sync cycle. */ __( '%d mapped products remain in the current sync cycle.', 'delicat-shop2topup' ), $this->sync->remaining() ) ); ?></p>
			</section>

			<?php $legacy = count( $this->legacy_sku_products() ); ?>
			<section class="dst2t-card">
				<h2><?php esc_html_e( 'Legacy SKUs', 'delicat-shop2topup' ); ?></h2>
				<?php if ( $legacy ) : ?>
					<p><?php echo esc_html( sprintf( /* translators: %d: number of products. */ _n( '%d product still has a SKU that encodes the provider item id.', '%d products still have SKUs that encode the provider item id.', $legacy, 'delicat-shop2topup' ), $legacy ) ); ?></p>
					<p class="description"><?php esc_html_e( 'WooCommerce shows the SKU to customers, to search engines, and in the public Store API. Replacing them makes the mapping unreadable. Do this only if the old SKUs are not referenced by invoices or another system.', 'delicat-shop2topup' ); ?></p>
					<p class="dst2t-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dst2t-inline-form" data-dst2t-confirm="<?php esc_attr_e( 'Replace these SKUs with opaque ones? Customers and any external system referencing the old SKUs will see the new values.', 'delicat-shop2topup' ); ?>">
							<input type="hidden" name="action" value="dst2t_rewrite_skus" />
							<?php wp_nonce_field( 'dst2t_rewrite_skus' ); ?>
							<?php submit_button( __( 'Replace legacy SKUs', 'delicat-shop2topup' ), 'secondary', 'submit', false ); ?>
						</form>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'No product SKU encodes the provider item id.', 'delicat-shop2topup' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="dst2t-card">
				<h2><?php esc_html_e( 'Environment', 'delicat-shop2topup' ); ?></h2>
				<ul class="dst2t-list">
					<li><span><?php esc_html_e( 'Plugin version', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( DST2T_VERSION ); ?></strong></li>
					<li><span><?php esc_html_e( 'PHP', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( PHP_VERSION ); ?></strong></li>
					<li><span><?php esc_html_e( 'WooCommerce', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : '—' ); ?></strong></li>
					<li><span><?php esc_html_e( 'Encryption', 'delicat-shop2topup' ); ?></span><strong><?php echo $vault->available() ? esc_html__( 'AES-256-GCM available', 'delicat-shop2topup' ) : esc_html__( 'OpenSSL missing', 'delicat-shop2topup' ); ?></strong></li>
					<li><span><?php esc_html_e( 'Encryption key', 'delicat-shop2topup' ); ?></span><strong><?php echo defined( 'DELICAT_S2T_ENCRYPTION_KEY' ) ? esc_html__( 'wp-config.php constant', 'delicat-shop2topup' ) : esc_html__( 'derived from WordPress salts', 'delicat-shop2topup' ); ?></strong></li>
					<li><span><?php esc_html_e( 'Action Scheduler', 'delicat-shop2topup' ); ?></span><strong><?php echo function_exists( 'as_schedule_recurring_action' ) ? esc_html__( 'Available', 'delicat-shop2topup' ) : esc_html__( 'Missing', 'delicat-shop2topup' ); ?></strong></li>
				</ul>
				<?php if ( ! defined( 'DELICAT_S2T_ENCRYPTION_KEY' ) ) : ?>
					<p class="description"><?php esc_html_e( 'Define DELICAT_S2T_ENCRYPTION_KEY in wp-config.php before storing credentials so rotating WordPress salts cannot make saved secrets and historical voucher codes unreadable.', 'delicat-shop2topup' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="dst2t-card dst2t-card-wide">
				<h2><?php esc_html_e( 'Callback endpoints', 'delicat-shop2topup' ); ?></h2>
				<ul class="dst2t-list">
					<li><span><?php esc_html_e( 'Active URL', 'delicat-shop2topup' ); ?></span><strong><?php echo DST2T_Brand::secret_html( DST2T_Webhook::url(), 8 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong></li>
					<li><span><?php esc_html_e( 'Compatibility URL', 'delicat-shop2topup' ); ?></span><strong><?php echo DST2T_Webhook::legacy_enabled() ? DST2T_Brand::secret_html( DST2T_Webhook::legacy_url(), 8 ) : esc_html__( 'retired', 'delicat-shop2topup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong></li>
				</ul>
				<p class="description">
					<?php
					echo DST2T_Webhook::legacy_enabled()
						? esc_html__( 'Both accept correctly signed events. Only the active URL is unbranded; the compatibility URL exists so the upgrade never drops deliveries. Retire it in Settings once the provider panel is sending to the active URL.', 'delicat-shop2topup' )
						: esc_html__( 'Only the active URL accepts events. The original provider-named path is no longer served.', 'delicat-shop2topup' );
					?>
				</p>
				<p class="dst2t-actions"><?php echo esc_html__( 'WP-CLI:', 'delicat-shop2topup' ); ?> <code>wp delicat-s2t balance</code> <code>wp delicat-s2t sync</code> <code>wp delicat-s2t reconcile</code> <code>wp delicat-s2t status</code></p>
			</section>
		</div>
		<?php
	}

	/* ------------------------------------------------------------- imports */

	private function import_item( $category_id, $catalog_item, $schema, $category_name = '' ) {
		$item_id = absint( $catalog_item['item_id'] );
		$sku     = DST2T_Brand::sku_for_item( $item_id );
		$id      = wc_get_product_id_by_sku( $sku );

		if ( $id && absint( get_post_meta( $id, DST2T_Product::META_ITEM_ID, true ) ) !== $item_id ) {
			throw new RuntimeException( 'SKU collision for ' . $sku );
		}
		if ( ! $id ) {
			// Catches products imported under an older SKU prefix.
			$id = $this->find_product_by_item_id( $item_id );
		}

		$product = $id ? wc_get_product( $id ) : new WC_Product_Simple();
		if ( ! $product instanceof WC_Product ) {
			throw new RuntimeException( 'WooCommerce product could not be created.' );
		}

		$is_new = ! $product->get_id();
		$product->set_name( sanitize_text_field( DST2T_Brand::scrub( isset( $catalog_item['name'] ) ? $catalog_item['name'] : '' ) ) );
		$product->set_description( wp_kses_post( DST2T_Brand::scrub( isset( $catalog_item['description'] ) ? $catalog_item['description'] : '' ) ) );
		$product->set_virtual( true );

		if ( $is_new ) {
			$product->set_sku( $sku );
		}
		// The operator reviews every import before it can be sold.
		if ( $is_new || $this->settings->enabled( 'import_force_draft' ) ) {
			$product->set_status( 'draft' );
		}

		$cost = isset( $catalog_item['price'] ) ? DST2T_Decimal::normalize( $catalog_item['price'] ) : '';
		// A listing price of zero means the catalog did not carry one, not that the
		// item is free, so fall back to the live price endpoint.
		if ( '' === $cost || 0 === DST2T_Decimal::compare( $cost, '0' ) ) {
			$price = $this->api->price( $item_id );
			$cost  = $price['unit_price'];
		}
		$priceable = 0 !== DST2T_Decimal::compare( $cost, '0' );
		if ( $priceable && ( $is_new || $this->settings->enabled( 'sync_catalog_prices' ) ) ) {
			$selling_price = $this->products->selling_price( $cost );
			if ( '' !== $selling_price ) {
				$product->set_regular_price( $selling_price );
				if ( '' === (string) $product->get_sale_price() ) {
					$product->set_price( $selling_price );
				}
			}
		}
		$product_id = $product->save();

		$this->products->save_mapping(
			$product_id,
			'yes',
			$item_id,
			$category_id,
			$is_new ? '' : get_post_meta( $product_id, DST2T_Product::META_MAX_COST, true ),
			isset( $catalog_item['name'] ) ? DST2T_Brand::scrub( $catalog_item['name'] ) : '',
			! empty( $catalog_item['returns_voucher'] ),
			$schema,
			$cost
		);

		if ( ! $priceable ) {
			// Left unpriced on purpose: the draft cannot be sold for nothing.
			update_post_meta( $product_id, DST2T_Product::META_SYNC_ERROR, 'ZERO_COST' );
		}

		$this->import_image( $product_id, $catalog_item );
		$this->assign_category( $product_id, $category_name );
	}

	/** Copies the catalog image into the media library; provider URLs never reach the storefront. */
	private function import_image( $product_id, $catalog_item ) {
		if ( ! $this->settings->enabled( 'import_images' ) || has_post_thumbnail( $product_id ) ) {
			return;
		}
		$url = '';
		foreach ( array( 'image', 'image_url', 'icon', 'thumbnail', 'logo', 'picture' ) as $key ) {
			if ( ! empty( $catalog_item[ $key ] ) && is_string( $catalog_item[ $key ] ) ) {
				$url = trim( $catalog_item[ $key ] );
				break;
			}
		}
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return;
		}
		set_post_thumbnail( $product_id, $attachment_id );
	}

	private function assign_category( $product_id, $category_name ) {
		$category_name = trim( DST2T_Brand::scrub( (string) $category_name ) );
		if ( ! $this->settings->enabled( 'import_categories' ) || '' === $category_name ) {
			return;
		}
		$term = get_term_by( 'name', $category_name, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			$term_id = (int) $term->term_id;
		} else {
			$created = wp_insert_term( $category_name, 'product_cat' );
			if ( is_wp_error( $created ) ) {
				return;
			}
			$term_id = (int) $created['term_id'];
		}
		wp_set_object_terms( $product_id, array( $term_id ), 'product_cat', true );
	}

	private function find_product_by_item_id( $item_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s AND m.meta_value = %s
				 AND p.post_type IN ('product','product_variation')
				 AND p.post_status NOT IN ('trash','auto-draft')
				 ORDER BY m.post_id ASC LIMIT 1",
				DST2T_Product::META_ITEM_ID,
				(string) absint( $item_id )
			)
		);
	}

	/* --------------------------------------------------- order list column */

	public function order_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['dst2t_fulfillment'] = __( 'Top-up', 'delicat-shop2topup' );
			}
		}
		if ( ! isset( $out['dst2t_fulfillment'] ) ) {
			$out['dst2t_fulfillment'] = __( 'Top-up', 'delicat-shop2topup' );
		}
		return $out;
	}

	/**
	 * @param string       $column       Column key.
	 * @param int|WC_Order $order_or_id  Post id on the legacy list table, order object on HPOS.
	 */
	public function order_column_content( $column, $order_or_id ) {
		if ( 'dst2t_fulfillment' !== $column ) {
			return;
		}
		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order ) {
			return;
		}

		$statuses = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item->get_meta( '_dst2t_order_id', true ) ) {
				continue;
			}
			$statuses[] = sanitize_key( (string) $item->get_meta( '_dst2t_status', true ) );
		}
		if ( ! $statuses ) {
			echo '<span class="dst2t-muted">—</span>';
			return;
		}

		$summary = $this->aggregate_status( $statuses );
		printf(
			'<span class="dst2t-status dst2t-status-%1$s">%2$s</span>',
			esc_attr( $summary ),
			esc_html( $this->status_label( $summary ) )
		);
		if ( count( $statuses ) > 1 ) {
			printf( ' <small class="dst2t-muted">%s</small>', esc_html( sprintf( '%d', count( $statuses ) ) ) );
		}
	}

	/** Worst-first summary of one order's line-item fulfillment states. */
	private function aggregate_status( $statuses ) {
		foreach ( array( 'manual_review', 'voucher_storage_error', 'voucher_missing', 'price_blocked', 'submission_failed', 'failed', 'refunded', 'partial', 'unknown', 'retrying', 'submitting', 'intent', 'processing', 'pending' ) as $status ) {
			if ( in_array( $status, $statuses, true ) ) {
				return $status;
			}
		}
		return 'completed';
	}

	private function refresh_product_url( $product_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'dst2t_refresh_product',
					'product_id'  => absint( $product_id ),
					// add_query_arg does not encode the values it is handed, so an
					// unencoded URL would be truncated at its first ampersand.
					'redirect_to' => rawurlencode( add_query_arg( array( 'page' => self::PAGE, 'tab' => 'catalog' ), admin_url( 'admin.php' ) ) ),
				),
				admin_url( 'admin-post.php' )
			),
			'dst2t_refresh_product'
		);
	}

	/* ------------------------------------------------------------- helpers */

	private function counts() {
		if ( null === $this->counts ) {
			$this->counts = $this->repository->counts();
		}
		return $this->counts;
	}

	private function review_count() {
		$counts = $this->counts();
		$total  = 0;
		foreach ( array( 'partial', 'failed', 'refunded', 'submission_failed', 'price_blocked', 'voucher_missing', 'voucher_storage_error', 'manual_review' ) as $status ) {
			$total += isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0;
		}
		return $total;
	}

	private function status_label( $status ) {
		$labels = array(
			'intent'                => __( 'Queued', 'delicat-shop2topup' ),
			'submitting'            => __( 'Submitting', 'delicat-shop2topup' ),
			'pending'               => __( 'Pending', 'delicat-shop2topup' ),
			'processing'            => __( 'Processing', 'delicat-shop2topup' ),
			'retrying'              => __( 'Retrying', 'delicat-shop2topup' ),
			'completed'             => __( 'Completed', 'delicat-shop2topup' ),
			'partial'               => __( 'Partial', 'delicat-shop2topup' ),
			'failed'                => __( 'Failed', 'delicat-shop2topup' ),
			'refunded'              => __( 'Refunded', 'delicat-shop2topup' ),
			'unknown'               => __( 'Checking', 'delicat-shop2topup' ),
			'submission_failed'     => __( 'Submission failed', 'delicat-shop2topup' ),
			'price_blocked'         => __( 'Price blocked', 'delicat-shop2topup' ),
			'voucher_missing'       => __( 'Voucher missing', 'delicat-shop2topup' ),
			'voucher_storage_error' => __( 'Voucher storage error', 'delicat-shop2topup' ),
			'manual_review'         => __( 'Manual review', 'delicat-shop2topup' ),
		);
		$status = (string) $status;
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucwords( str_replace( '_', ' ', $status ) );
	}

	private function tri_state( $value ) {
		if ( null === $value ) {
			return __( 'unknown', 'delicat-shop2topup' );
		}
		return $value ? __( 'yes', 'delicat-shop2topup' ) : __( 'no', 'delicat-shop2topup' );
	}

	private function when( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( ! $timestamp ) {
			return __( 'not scheduled', 'delicat-shop2topup' );
		}
		if ( $timestamp > time() ) {
			/* translators: %s: human readable time difference. */
			return sprintf( __( 'in %s', 'delicat-shop2topup' ), human_time_diff( time(), $timestamp ) );
		}
		/* translators: %s: human readable time difference. */
		return sprintf( __( '%s ago', 'delicat-shop2topup' ), human_time_diff( $timestamp, time() ) );
	}

	private function action_button( $action, $label, $style = 'secondary' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dst2t-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, $style, 'submit', false ); ?>
		</form>
		<?php
	}

	private function checkbox_row( $key, $label, $description ) {
		?>
		<tr><th><?php echo esc_html( $label ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $this->settings->enabled( $key ) ); ?> /> <?php echo esc_html( $description ); ?></label></td></tr>
		<?php
	}

	private function tab_link( $tab, $label, $current ) {
		$url = add_query_arg( array( 'page' => self::PAGE, 'tab' => $tab ), admin_url( 'admin.php' ) );
		echo '<a class="nav-tab ' . ( $tab === $current ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	private function authorize( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'delicat-shop2topup' ) );
		}
		check_admin_referer( $action );
	}

	private function redirect_with_notice( $tab, $type, $message, $extra = array() ) {
		set_transient(
			'dst2t_notice_' . get_current_user_id(),
			array( 'type' => $type, 'message' => wp_strip_all_tags( $message ) ),
			60
		);
		$query = array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $extra );
		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) );
		exit;
	}
}
