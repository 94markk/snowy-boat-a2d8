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

	/** @var DST2T_Catalog */
	private $catalog;

	/** @var DST2T_Fulfillment */
	private $fulfillment;

	/** @var DST2T_Balance */
	private $balance;

	/** @var DST2T_Sync */
	private $sync;

	/** @var array|null Per-request cache of the repository status counts. */
	private $counts;

	public function __construct( DST2T_Settings $settings, DST2T_API_Client $api, DST2T_Repository $repository, DST2T_Product $products, DST2T_Catalog $catalog, DST2T_Fulfillment $fulfillment, DST2T_Balance $balance, DST2T_Sync $sync ) {
		$this->settings    = $settings;
		$this->api         = $api;
		$this->repository  = $repository;
		$this->products    = $products;
		$this->catalog     = $catalog;
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
		add_action( 'admin_post_dst2t_import_full_catalog', array( $this, 'import_full_catalog' ) );
		add_action( 'admin_post_dst2t_refresh_product', array( $this, 'refresh_product' ) );
		add_action( 'admin_post_dst2t_refresh_balance', array( $this, 'refresh_balance' ) );
		add_action( 'admin_post_dst2t_sync_catalog', array( $this, 'sync_catalog' ) );
		add_action( 'admin_post_dst2t_rotate_webhook', array( $this, 'rotate_webhook' ) );
		add_action( 'admin_post_dst2t_sync_order', array( $this, 'sync_order' ) );
		add_action( 'admin_post_dst2t_retry_order', array( $this, 'retry_order' ) );
		add_action( 'admin_post_dst2t_rewrite_skus', array( $this, 'rewrite_skus' ) );
		add_action( 'admin_post_dst2t_open_verification', array( $this, 'open_verification' ) );
		add_action( 'admin_post_dst2t_selftest_webhook', array( $this, 'selftest_webhook' ) );
		add_action( 'wp_ajax_dst2t_recovery_step', array( $this, 'recovery_step' ) );

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
				'importNonce' => wp_create_nonce( 'dst2t_import_status' ),
				'recoveryNonce' => wp_create_nonce( 'dst2t_recovery_step' ),
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
		if ( ! $this->settings->credentials_configured() ) {
			$this->redirect_with_notice( 'settings', 'error', __( 'Paste the complete keyId.secret API credential and save settings first.', 'delicat-shop2topup' ) );
		}
		$state = $this->balance->refresh( true );
		if ( '' !== $state['error'] ) {
			$message = sprintf( __( 'Connection failed: %s.', 'delicat-shop2topup' ), $state['error'] );
			if ( 'INVALID_API_KEY' === $state['error'] || 'MISSING_API_KEY' === $state['error'] ) {
				$message .= ' ' . __( 'Create a new API key in the provider panel and paste the complete keyId.secret value exactly as shown at creation.', 'delicat-shop2topup' );
			} elseif ( 'IP_NOT_ALLOWED' === $state['error'] ) {
				$message .= ' ' . __( 'Your server outbound IP is not allowed for this API key.', 'delicat-shop2topup' );
			}
			$this->redirect_with_notice( 'settings', 'error', $message );
		}
		$this->redirect_with_notice( 'dashboard', 'success', sprintf( __( 'Connection successful. Wallet balance: %s.', 'delicat-shop2topup' ), '' !== $state['formatted'] ? $state['formatted'] : __( 'unavailable', 'delicat-shop2topup' ) ) );
	}

	private function auth_mode_label( $mode ) {
		$labels = array(
			'auto'          => __( 'Automatic', 'delicat-shop2topup' ),
			'bearer_key'    => __( 'Bearer <api key>', 'delicat-shop2topup' ),
			'bearer_pair'   => __( 'Bearer <key>.<secret>', 'delicat-shop2topup' ),
			'bearer_secret' => __( 'Bearer <secret>', 'delicat-shop2topup' ),
			'x_api_key'     => __( 'X-API-Key header', 'delicat-shop2topup' ),
			'raw_key'       => __( 'Authorization: <api key>', 'delicat-shop2topup' ),
		);
		return isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
	}

	public function refresh_balance() {
		$this->authorize( 'dst2t_refresh_balance' );
		$state = $this->balance->refresh( true );
		if ( '' !== $state['error'] ) {
			$this->redirect_with_notice( 'dashboard', 'error', sprintf( /* translators: %s: error code. */ __( 'Balance refresh failed: %s.', 'delicat-shop2topup' ), $state['error'] ) );
		}
		$this->redirect_with_notice( 'dashboard', 'success', sprintf( /* translators: %s: formatted wallet balance. */ __( 'Wallet balance updated: %s.', 'delicat-shop2topup' ), $state['formatted'] ) );
	}

	/** Authenticated dashboard assist when cron/loopback is late; at most one due item. */
	public function recovery_step() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( array( 'message' => 'Forbidden' ), 403 ); return; }
		check_ajax_referer( 'dst2t_recovery_step', 'nonce' );
		$last = absint( get_option( 'dst2t_recovery_step_at', 0 ) );
		if ( $last + 60 <= time() ) {
			update_option( 'dst2t_recovery_step_at', time(), false );
			$this->fulfillment->reconcile( true );
		}
		wp_send_json_success( get_option( 'dst2t_reconcile_state', array() ) );
	}

	public function sync_now() {
		$this->authorize( 'dst2t_sync_now' );
		$this->fulfillment->reconcile( true );
		$this->redirect_with_notice( 'dashboard', 'success', __( 'Un passage de récupération a été effectué sur une commande échue au maximum. Vérifiez son état ; les autres commandes seront traitées par le planificateur ou pendant que ce tableau de bord reste ouvert.', 'delicat-shop2topup' ) );
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
			if ( ! $this->fulfillment->apply_provider_order( $uuid, $this->api->order( $uuid ), 'manual' ) ) { throw new RuntimeException( 'Settlement deferred.' ); }
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

		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $uuid ) ) { $this->redirect_with_notice( $tab, 'error', 'Référence de commande invalide.' ); }
		try {
			$this->fulfillment->retry_fulfillment( $uuid );
		} catch ( RuntimeException $error ) {
			$this->redirect_with_notice( $tab, 'error', $error->getMessage() );
		}
		$this->redirect_with_notice( $tab, 'success', __( 'Relance enregistrée avec la même référence. Les livraisons déjà acceptées seront uniquement synchronisées.', 'delicat-shop2topup' ) );
	}

	/** Opens the window in which the provider may validate the callback URL. */
	public function open_verification() {
		$this->authorize( 'dst2t_open_verification' );
		DST2T_Webhook::open_verification();
		$this->redirect_with_notice(
			'dashboard',
			'success',
			sprintf(
				/* translators: %d: number of minutes. */
				__( 'Endpoint verification is open for %d minutes. Save the callback URL in the provider panel now.', 'delicat-shop2topup' ),
				(int) round( DST2T_Webhook::VERIFY_WINDOW / MINUTE_IN_SECONDS )
			)
		);
	}

	/**
	 * Posts to this site's own callback URL exactly as the provider does.
	 *
	 * The first request is unsigned, which is what a provider sends when it
	 * validates a URL before saving it; the second is correctly signed. Between
	 * them they separate "the URL is unreachable" from "the signing secret is
	 * wrong" without waiting on the provider panel.
	 */
	public function selftest_webhook() {
		$this->authorize( 'dst2t_selftest_webhook' );

		$url   = DST2T_Webhook::url();
		$probe = $this->post_to_callback( $url, '{}', array() );

		$secret = $this->settings->webhook_secret();
		$signed = null;
		if ( '' !== $secret ) {
			$payload = wp_json_encode( array( 'event' => 'webhook.test', 'timestamp' => gmdate( 'c' ), 'data' => array( 'dst2t_local_test' => true ) ) );
			$signed  = $this->post_to_callback(
				$url,
				$payload,
				array(
					'x-shop2topup-signature' => 'sha256=' . hash_hmac( 'sha256', $payload, $secret ),
					'x-shop2topup-event'     => 'webhook.test',
				)
			);
		}

		$lines = array(
			sprintf(
				/* translators: %s: HTTP status code or transport error. */
				__( 'Unsigned validation POST (what the provider sends first): %s', 'delicat-shop2topup' ),
				$probe
			),
		);
		$lines[] = null === $signed
			? __( 'Signed test: skipped, no signing secret is saved yet.', 'delicat-shop2topup' )
			: sprintf( /* translators: %s: HTTP status code or transport error. */ __( 'Signed test callback: %s', 'delicat-shop2topup' ), $signed );

		$ok = '200' === (string) $probe && '200' === (string) $signed;
		if ( ! $ok ) {
			$lines[] = __( 'The provider will refuse to save a URL that does not answer 2xx. If this says 404, the plugin is not serving that URL — check the URL you pasted. If it is a transport error, this server cannot reach its own public URL, which usually means a firewall, a security plugin, or a host that blocks loopback requests.', 'delicat-shop2topup' );
		}

		$this->redirect_with_notice( 'dashboard', $ok ? 'success' : 'error', implode( ' | ', $lines ) );
	}

	/**
	 * @return string HTTP status code, or a transport error message.
	 */
	private function post_to_callback( $url, $body, $headers ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return sanitize_text_field( $response->get_error_message() );
		}
		$status = absint( wp_remote_retrieve_response_code( $response ) );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status >= 200 && $status < 300 && ( ! is_array( $data ) || true !== ( $data['success'] ?? false ) || ( '{}' === $body && empty( $data['verification'] ) ) ) ) {
			return 'HTTP ' . $status . ' — réponse inattendue (cache, maintenance ou redirection)';
		}
		return (string) $status;
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
		$category_id  = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$big_id       = isset( $_POST['big_id'] ) ? absint( $_POST['big_id'] ) : 0;
		$requested    = isset( $_POST['item_ids'] ) ? array_values( array_unique( array_map( 'absint', (array) wp_unslash( $_POST['item_ids'] ) ) ) ) : array();
		$import_all   = ! empty( $_POST['import_all'] );
		$category_name = isset( $_POST['category_name'] ) ? sanitize_text_field( wp_unslash( $_POST['category_name'] ) ) : '';
		$return       = array( 'category_id' => $category_id, 'big_id' => $big_id );

		if ( ! $category_id || ( ! $import_all && ! $requested ) ) {
			$this->redirect_with_notice( 'catalog', 'error', __( 'Choose at least one catalog item or use Import entire category.', 'delicat-shop2topup' ), $return );
		}

		$selling_prices = isset( $_POST['selling_prices'] ) && is_array( $_POST['selling_prices'] ) ? (array) wp_unslash( $_POST['selling_prices'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = $this->catalog->start_import( $category_id, $requested, $import_all, $category_name, get_current_user_id(), $selling_prices );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'catalog', 'error', $result->get_error_message(), $return );
		}

		$return['import_job'] = isset( $result['id'] ) ? sanitize_text_field( $result['id'] ) : '';
		$this->redirect_with_notice(
			'catalog',
			'success',
			sprintf(
				/* translators: %d: product count queued for import. */
				_n( '%d product queued for background import.', '%d products queued for background import.', (int) $result['total'], 'delicat-shop2topup' ),
				(int) $result['total']
			),
			$return
		);
	}

	public function import_full_catalog() {
		$this->authorize( 'dst2t_import_full_catalog' );
		$result = $this->catalog->start_full_import( get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'catalog', 'error', $result->get_error_message() );
		}

		$return = array( 'import_job' => isset( $result['id'] ) ? sanitize_text_field( $result['id'] ) : '' );
		$this->redirect_with_notice(
			'catalog',
			'success',
			sprintf(
				/* translators: %d: product count queued for import. */
				_n( '%d supplier product queued for import.', '%d supplier products queued for import.', (int) $result['total'], 'delicat-shop2topup' ),
				(int) $result['total']
			),
			$return
		);
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
		$next_reconcile = $this->fulfillment->next_reconcile();
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
					<li><span><?php esc_html_e( 'Next reconcile', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $this->when( $next_reconcile ) ); ?></strong></li>
					<li><span><?php esc_html_e( 'Next balance check', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $this->when( $this->balance->next_run() ) ); ?></strong></li>
					<li><span><?php esc_html_e( 'Next catalog sync', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $this->sync->interval_seconds() ? $this->when( $this->sync->next_run() ) : __( 'off', 'delicat-shop2topup' ) ); ?></strong></li>
					<li><span><?php esc_html_e( 'Mapped products', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $this->sync->mapped_count() ); ?></strong></li>
				</ul>
				<?php if ( $next_reconcile && $next_reconcile < time() - 120 ) : ?>
				<p class="dst2t-error">Planificateur en retard. La récupération ci-dessous traite au maximum une commande échue par minute pendant que cette page reste ouverte. Vérifiez WooCommerce → État → Actions planifiées et le cron serveur.</p>
				<?php endif; ?>
				<p class="description" data-dst2t-recovery-status>Assistance de récupération active sur ce tableau de bord.</p>
				<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
					<p class="dst2t-error"><?php esc_html_e( 'WP-Cron est désactivé. Action Scheduler nécessite aussi un déclencheur : configurez une tâche cron serveur pour garantir les traitements quand le tableau de bord est fermé.', 'delicat-shop2topup' ); ?></p>
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
				<?php $unverified = DST2T_Webhook::unverified_state(); ?>
				<?php if ( DST2T_Webhook::verification_open() ) : ?>
					<div class="notice notice-info inline"><p><?php echo esc_html( sprintf( /* translators: %s: human readable duration. */ __( 'Endpoint verification is open for another %s. Les sondes sans signature reçoivent une réponse 2xx sans traitement de commande. Les signatures incorrectes restent refusées.', 'delicat-shop2topup' ), human_time_diff( time(), time() + DST2T_Webhook::verification_remaining() ) ) ); ?></p></div>
				<?php endif; ?>
				<?php if ( $unverified['count'] > 0 ) : ?>
					<div class="notice notice-warning inline"><p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of callbacks, 2: reason code, 3: human readable time difference. */
								_n( '%1$d callback could not be authenticated (%2$s, %3$s ago). Nothing was processed.', '%1$d callbacks could not be authenticated (%2$s, most recently %3$s ago). Nothing was processed.', (int) $unverified['count'], 'delicat-shop2topup' ),
								(int) $unverified['count'],
								$unverified['reason'] ? $unverified['reason'] : 'unknown',
								$unverified['last'] ? human_time_diff( (int) $unverified['last'], time() ) : '—'
							)
						);
						?>
						<?php esc_html_e( 'La signature du callback est absente ou incorrecte. Vérifiez le secret de signature dans Réglages et dans le panneau fournisseur, puis envoyez un test signé. La synchronisation de secours nécessite aussi une API fonctionnelle.', 'delicat-shop2topup' ); ?>
					</p></div>
				<?php endif; ?>
				<ul class="dst2t-list">
					<li><span>Dernier contrôle local signé</span><strong><?php echo esc_html( get_option( 'dst2t_last_webhook_local_test', '—' ) ); ?></strong></li>
					<li><span>Dernière validation URL reçue</span><strong><?php echo esc_html( get_option( 'dst2t_last_webhook_probe', 0 ) ? $this->when( get_option( 'dst2t_last_webhook_probe' ) ) : '—' ); ?></strong></li>
					<li><span><?php esc_html_e( 'Dernier test signé du fournisseur', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( get_option( 'dst2t_last_webhook_test', '—' ) ); ?></strong></li>
					<li><span><?php esc_html_e( 'Last event received', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $webhook['at'] ? $webhook['event'] . ' · ' . $this->when( (int) $webhook['at'] ) : '—' ); ?></strong></li>
					<li><span><?php esc_html_e( 'Last catalog sync', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $sync['last_run'] ? $this->when( (int) $sync['last_run'] ) : __( 'never', 'delicat-shop2topup' ) ); ?></strong></li>
				</ul>
				<p class="dst2t-actions">
					<?php $this->action_button( 'dst2t_selftest_webhook', __( 'Test this URL from here', 'delicat-shop2topup' ), 'primary' ); ?>
					<?php $this->action_button( 'dst2t_open_verification', __( 'Allow verification for 15 minutes', 'delicat-shop2topup' ) ); ?>
					<?php $this->action_button( 'dst2t_rotate_webhook', __( 'Generate a new callback URL', 'delicat-shop2topup' ) ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'L’URL privée accepte les sondes de validation sans signature, même si le secret HMAC est déjà enregistré. Aucune commande ne sera traitée sans signature valide. Après avoir enregistré l’URL, utilisez « Send Test » dans le panneau fournisseur. Le contrôle local ne prouve pas l’accès depuis le fournisseur.', 'delicat-shop2topup' ); ?></p>
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
		<div class="dst2t-table-scroll" role="region" aria-label="Commandes" tabindex="0">
<?php $diagnostic = get_option( 'dst2t_response_diagnostic', array() ); if ( is_array( $diagnostic ) && ! empty( $diagnostic['at'] ) ) : ?>
		<p class="dst2t-error"><?php echo esc_html( sprintf( 'Dernière réponse API invalide : %s — HTTP %d — %s — %d octets (%s UTC).', $diagnostic['endpoint'], $diagnostic['http'], $diagnostic['format'], $diagnostic['bytes'], gmdate( 'Y-m-d H:i:s', $diagnostic['at'] ) ) ); ?></p>
		<?php endif; ?>
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
		</div>
		<?php
	}

	private function settings_tab() {
		$constants = defined( 'DELICAT_S2T_KEY_ID' ) || defined( 'DELICAT_S2T_KEY_SECRET' ) || defined( 'DELICAT_S2T_WEBHOOK_SECRET' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dst2t-settings">
			<input type="hidden" name="action" value="dst2t_save_settings" />
			<?php wp_nonce_field( 'dst2t_save_settings' ); ?>

			<h2><?php esc_html_e( 'API credentials', 'delicat-shop2topup' ); ?></h2>
			<p><?php esc_html_e( 'This build follows the SHOP2TOPUP v1 documentation exactly. Paste the complete keyId.secret credential generated in API Access Management. It is encrypted at rest and is never printed back to the browser.', 'delicat-shop2topup' ); ?></p>
			<?php if ( $constants ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'One or more credentials are controlled by wp-config.php and override saved values.', 'delicat-shop2topup' ); ?></p></div>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="api_credential"><?php esc_html_e( 'API credential', 'delicat-shop2topup' ); ?></label></th>
					<td>
						<input class="regular-text" id="api_credential" name="api_credential" type="password" autocomplete="new-password" placeholder="<?php echo $this->settings->credentials_configured() ? esc_attr( 'keyId.secret already saved' ) : esc_attr( 'keyId.secret' ); ?>" />
						<p class="description"><?php esc_html_e( 'Required format: keyId.secret. Do not paste only the visible key prefix from the provider panel. The secret is shown only once when a key is created.', 'delicat-shop2topup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Authentication header', 'delicat-shop2topup' ); ?></th>
					<td><code>Authorization: Bearer &lt;keyId&gt;.&lt;secret&gt;</code><p class="description"><?php esc_html_e( 'Fixed by the v1 API contract; there is no automatic header guessing in this build.', 'delicat-shop2topup' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="webhook_secret"><?php esc_html_e( 'Webhook HMAC secret', 'delicat-shop2topup' ); ?></label></th>
					<td>
						<input class="regular-text" id="webhook_secret" name="webhook_secret" type="password" autocomplete="new-password" placeholder="<?php echo $this->settings->webhook_secret() ? esc_attr( 'HMAC secret already saved' ) : ''; ?>" />
						<p class="description"><?php esc_html_e( 'Paste the signing secret created with the webhook. It is different from the API secret. Invalid signatures are rejected with HTTP 401.', 'delicat-shop2topup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Clear secrets', 'delicat-shop2topup' ); ?></th>
					<td><label><input type="checkbox" name="clear_credentials" value="1" /> <?php esc_html_e( 'Remove the saved API credential and webhook secret', 'delicat-shop2topup' ); ?></label></td>
				</tr>
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
				$this->checkbox_row( 'woocommerce_payment_gate', __( 'WooCommerce controls payment approval', 'delicat-shop2topup' ), __( 'Recommended. Supplier API errors never block cart or checkout. Fulfillment starts only after WooCommerce records the order as paid; unpaid, failed, cancelled and pending orders are never sent upstream.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'validate_player', __( 'Validate UID / Player ID', 'delicat-shop2topup' ), __( 'Keeps the Vérifier votre compte button and validates the player before supplier fulfillment. In WooCommerce payment-gate mode, a temporary supplier validation outage does not block the customer from paying.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'player_history', __( 'Remember last 5 UID / Player IDs', 'delicat-shop2topup' ), __( 'For signed-in customers, securely stores up to five recent Player ID/server combinations per game. Selecting one never bypasses normal validation.', 'delicat-shop2topup' ) );
				$this->checkbox_row( 'auto_complete', __( 'Complete WooCommerce orders automatically', 'delicat-shop2topup' ), __( 'Only when WooCommerce approved the payment, every mapped item is confirmed delivered upstream, and no other unmapped line still controls the order.', 'delicat-shop2topup' ) );
				?>
				<tr><th><label for="cost_guard_percent"><?php esc_html_e( 'Maximum cost increase', 'delicat-shop2topup' ); ?></label></th><td><input id="cost_guard_percent" name="cost_guard_percent" type="number" min="0" max="1000" step="0.01" value="<?php echo esc_attr( $this->settings->get( 'cost_guard_percent' ) ); ?>" /> %<p class="description"><?php esc_html_e( 'Compared with the cached cost. Set 0 to disable this relative guard.', 'delicat-shop2topup' ); ?></p></td></tr>
			</table>

			<h2><?php esc_html_e( 'Catalog and import', 'delicat-shop2topup' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="import_structure"><?php esc_html_e( 'Import structure', 'delicat-shop2topup' ); ?></label></th>
					<td><select id="import_structure" name="import_structure"><option value="variable" <?php selected( $this->settings->get( 'import_structure', 'variable' ), 'variable' ); ?>><?php esc_html_e( 'Variable product — one game/service with package variations', 'delicat-shop2topup' ); ?></option><option value="simple" <?php selected( $this->settings->get( 'import_structure', 'variable' ), 'simple' ); ?>><?php esc_html_e( 'Separate simple product for every supplier item', 'delicat-shop2topup' ); ?></option></select><p class="description"><?php esc_html_e( 'Variable mode groups denominations such as 100 / 310 / 520 diamonds under one WooCommerce product.', 'delicat-shop2topup' ); ?></p></td>
				</tr>
				<tr><th><label for="variation_attribute"><?php esc_html_e( 'Variation selector name', 'delicat-shop2topup' ); ?></label></th><td><input id="variation_attribute" name="variation_attribute" type="text" maxlength="40" value="<?php echo esc_attr( $this->settings->get( 'variation_attribute', 'Package' ) ); ?>" /><p class="description"><?php esc_html_e( 'Example: Package, Amount, Diamonds, Points.', 'delicat-shop2topup' ); ?></p></td></tr>
				<tr>
					<th><label for="catalog_pricing_mode"><?php esc_html_e( 'Customer pricing', 'delicat-shop2topup' ); ?></label></th>
					<td><select id="catalog_pricing_mode" name="catalog_pricing_mode"><option value="manual" <?php selected( $this->settings->get( 'catalog_pricing_mode', 'manual' ), 'manual' ); ?>><?php esc_html_e( 'Manual WooCommerce pricing — recommended for HTG', 'delicat-shop2topup' ); ?></option><option value="automatic" <?php selected( $this->settings->get( 'catalog_pricing_mode', 'manual' ), 'automatic' ); ?>><?php esc_html_e( 'Automatic USD conversion + markup', 'delicat-shop2topup' ); ?></option></select><p class="description"><?php esc_html_e( 'Manual mode keeps supplier USD cost separate and read-only. You can set each variation price in HTG/store currency without changing what the supplier charges.', 'delicat-shop2topup' ); ?></p></td>
				</tr>
				<tr><th><label for="usd_exchange_rate"><?php esc_html_e( '1 USD in store currency (automatic mode only)', 'delicat-shop2topup' ); ?></label></th><td><input id="usd_exchange_rate" name="usd_exchange_rate" type="number" min="0.000001" step="0.000001" value="<?php echo esc_attr( $this->settings->get( 'usd_exchange_rate' ) ); ?>" /></td></tr>
				<tr><th><label for="markup_percent"><?php esc_html_e( 'Catalog markup (automatic mode only)', 'delicat-shop2topup' ); ?></label></th><td><input id="markup_percent" name="markup_percent" type="number" min="0" max="1000" step="0.01" value="<?php echo esc_attr( $this->settings->get( 'markup_percent' ) ); ?>" /> %</td></tr>
				<tr>
					<th><label for="catalog_sync_interval"><?php esc_html_e( 'Automatic catalog sync', 'delicat-shop2topup' ); ?></label></th>
					<td>
						<select id="catalog_sync_interval" name="catalog_sync_interval">
							<?php
							$intervals = array(
								0    => __( 'Off', 'delicat-shop2topup' ),
								15   => __( 'Every 15 minutes', 'delicat-shop2topup' ),
								30   => __( 'Every 30 minutes', 'delicat-shop2topup' ),
								60   => __( 'Every hour', 'delicat-shop2topup' ),
								360  => __( 'Every 6 hours', 'delicat-shop2topup' ),
								720  => __( 'Every 12 hours', 'delicat-shop2topup' ),
								1440 => __( 'Once a day', 'delicat-shop2topup' ),
							);
							$selected = absint( $this->settings->get( 'catalog_sync_interval', 15 ) );
							foreach ( $intervals as $minutes => $label ) :
								?>
								<option value="<?php echo esc_attr( $minutes ); ?>" <?php selected( $selected, $minutes ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Refreshes cached costs, requirement fields, and availability for mapped products in small batches.', 'delicat-shop2topup' ); ?></p>
					</td>
				</tr>
				<?php
				$this->checkbox_row( 'sync_catalog_prices', __( 'Update WooCommerce prices during sync (automatic mode only)', 'delicat-shop2topup' ), __( 'Manual pricing forces this off. Supplier USD cost still refreshes normally without touching your HTG/store selling prices.', 'delicat-shop2topup' ) );
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
		// One query for every row of the table instead of one per catalog item.
		$existing_ids = $items ? $this->find_products_by_item_ids( array_map( static function ( $row ) { return is_array( $row ) && isset( $row['item_id'] ) ? $row['item_id'] : 0; }, $items ) ) : array();
		?>
		<section class="dst2t-card dst2t-card-wide dst2t-catalog-import-all">
			<h2><?php esc_html_e( 'Import supplier catalog', 'delicat-shop2topup' ); ?></h2>
			<p><?php esc_html_e( 'Import the complete live supplier catalog into WooCommerce. New products are created as drafts so you can review them before publishing.', 'delicat-shop2topup' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dst2t-confirm="<?php esc_attr_e( 'Queue the complete supplier catalog for import as WooCommerce drafts?', 'delicat-shop2topup' ); ?>">
				<input type="hidden" name="action" value="dst2t_import_full_catalog" />
				<?php wp_nonce_field( 'dst2t_import_full_catalog' ); ?>
				<?php submit_button( __( 'Import full catalog as drafts', 'delicat-shop2topup' ), 'primary', 'submit', false ); ?>
			</form>
			<p class="description"><?php esc_html_e( 'The import advances from this browser and also has a background scheduler fallback, so hosts with blocked WP-Cron no longer leave the job permanently queued.', 'delicat-shop2topup' ); ?></p>
		</section>

		<?php if ( ! $categories ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'The provider returned no category index. The importer will still try the documented unfiltered product catalog and derive categories from product rows.', 'delicat-shop2topup' ); ?></p></div>
		<?php endif; ?>

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

		<?php
		$job_id = isset( $_GET['import_job'] ) ? sanitize_text_field( wp_unslash( $_GET['import_job'] ) ) : $this->catalog->last_job_id( get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$job    = $job_id ? $this->catalog->status( $job_id, get_current_user_id() ) : array();
		if ( $job ) :
		?>
			<section class="dst2t-card dst2t-import-progress" data-dst2t-import-job="<?php echo esc_attr( $job_id ); ?>">
				<h2><?php esc_html_e( 'Catalog import', 'delicat-shop2topup' ); ?></h2>
				<p><strong data-dst2t-import-status><?php echo esc_html( ucfirst( str_replace( '_', ' ', $job['status'] ) ) ); ?></strong>
					<span class="dst2t-muted" data-dst2t-import-count><?php echo esc_html( sprintf( '%d / %d', (int) $job['processed'], (int) $job['total'] ) ); ?></span></p>
				<progress data-dst2t-import-progress max="100" value="<?php echo esc_attr( (int) $job['percent'] ); ?>" style="width:100%"></progress>
				<p class="description" data-dst2t-import-summary><?php echo esc_html( sprintf( __( 'Created %1$d · Updated %2$d · Failed %3$d', 'delicat-shop2topup' ), (int) $job['created'], (int) $job['updated'], (int) $job['failed'] ) ); ?></p>
			</section>
		<?php endif; ?>

		<?php if ( $selected && ! $items ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This category has no items right now.', 'delicat-shop2topup' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $selected && $items ) : ?>
			<?php $store_currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'HTG'; ?>
			<div class="notice notice-info inline"><p><?php echo esc_html( sprintf( __( 'Supplier cost stays in USD. Selling prices below are independent WooCommerce prices in %s. In Manual Pricing mode future supplier cost syncs do not overwrite them.', 'delicat-shop2topup' ), $store_currency ) ); ?></p></div>
			<?php if ( 'manual' === $this->settings->get( 'catalog_pricing_mode', 'manual' ) && 'HTG' !== strtoupper( (string) $store_currency ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( __( 'WooCommerce currency is currently %s. To show these manual prices as Haitian gourdes, change WooCommerce > Settings > General > Currency to HTG. This plugin will not change your store currency automatically.', 'delicat-shop2topup' ), $store_currency ) ); ?></p></div>
			<?php endif; ?>
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
							<th><?php echo esc_html( sprintf( __( 'Selling price (%s)', 'delicat-shop2topup' ), function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'HTG' ) ); ?></th>
							<th><?php esc_html_e( 'Type', 'delicat-shop2topup' ); ?></th>
							<th><?php esc_html_e( 'In store', 'delicat-shop2topup' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $items as $catalog_item ) : ?>
						<?php
						$item_id  = isset( $catalog_item['item_id'] ) ? absint( $catalog_item['item_id'] ) : 0;
						$existing = $item_id && isset( $existing_ids[ $item_id ] ) ? $existing_ids[ $item_id ] : 0;
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
							<td>
								<?php
								$existing_price = '';
								if ( $existing ) {
									$existing_product = wc_get_product( $existing );
									$existing_price = $existing_product instanceof WC_Product ? (string) $existing_product->get_regular_price() : '';
								}
								$auto_price = '' !== $cost ? $this->products->selling_price( $cost ) : '';
								$value = '' !== $existing_price ? $existing_price : ( 'automatic' === $this->settings->get( 'catalog_pricing_mode', 'manual' ) ? $auto_price : '' );
								?>
								<input type="number" min="0" step="0.01" inputmode="decimal" name="selling_prices[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( 'manual' === $this->settings->get( 'catalog_pricing_mode', 'manual' ) ? __( 'Set HTG price', 'delicat-shop2topup' ) : $auto_price ); ?>" style="width:8.5em" />
							</td>
							<td><?php echo ( isset( $catalog_item['product_type'] ) && 'voucher' === strtolower( (string) $catalog_item['product_type'] ) ) ? esc_html__( 'Voucher', 'delicat-shop2topup' ) : esc_html__( 'Direct top-up', 'delicat-shop2topup' ); ?></td>
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
				<p class="submit dst2t-actions">
					<button class="button button-primary" type="submit" name="import_selected" value="1"><?php esc_html_e( 'Import selected products as drafts', 'delicat-shop2topup' ); ?></button>
					<button class="button" type="submit" name="import_all" value="1"><?php esc_html_e( 'Import entire category as drafts', 'delicat-shop2topup' ); ?></button>
				</p>
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
					<li><span><?php esc_html_e( 'Credential format', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( $this->auth_mode_label( $this->settings->auth_mode() ) ); ?></strong></li>
					<li><span><?php esc_html_e( 'This server\'s address', 'delicat-shop2topup' ); ?></span><strong><?php echo esc_html( isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '—' ); ?></strong></li>
				</ul>
				<p class="description"><?php esc_html_e( 'If the provider panel restricts API access by IP, it needs this server\'s outbound address. That is often different from the address shown here — confirm it with your host before relying on it.', 'delicat-shop2topup' ); ?></p>
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

	/**
	 * Maps supplier item ids to the WooCommerce product or variation that carries
	 * them, in one query. Variations win over simple products, then the oldest id,
	 * exactly like the per-item lookup this replaces.
	 *
	 * @param array $item_ids Supplier item ids.
	 * @return array item_id => post id.
	 */
	private function find_products_by_item_ids( $item_ids ) {
		global $wpdb;
		$item_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $item_ids ) ) ) );
		if ( ! $item_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s AND m.meta_value IN ({$placeholders})
				 AND p.post_type IN ('product','product_variation')
				 AND p.post_status NOT IN ('trash','auto-draft')
				 ORDER BY CASE WHEN p.post_type='product_variation' THEN 0 ELSE 1 END, m.post_id ASC", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( DST2T_Product::META_ITEM_ID ), array_map( 'strval', $item_ids ) )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$item = absint( $row['meta_value'] );
			if ( $item && ! isset( $out[ $item ] ) ) {
				$out[ $item ] = absint( $row['post_id'] );
			}
		}
		return $out;
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
