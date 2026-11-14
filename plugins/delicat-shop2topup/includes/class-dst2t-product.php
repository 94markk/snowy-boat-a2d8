<?php

defined( 'ABSPATH' ) || exit;

final class DST2T_Product {
	/** Background refresh hook used after an admin product save. */
	const ACTION_REFRESH_MAPPING = 'dst2t_refresh_mapping_async';
	const META_ENABLED      = '_dst2t_enabled';
	const META_ITEM_ID      = '_dst2t_item_id';
	const META_CATEGORY_ID  = '_dst2t_category_id';
	const META_PROVIDER_NAME = '_dst2t_provider_name';
	const META_REQUIREMENTS = '_dst2t_requirements_schema';
	const META_LAST_COST    = '_dst2t_last_cost_usd';
	const META_MAX_COST     = '_dst2t_max_cost_usd';
	const META_VOUCHER      = '_dst2t_returns_voucher';
	const META_SYNC_ERROR   = '_dst2t_sync_error';
	const META_PRODUCT_TYPE = '_dst2t_product_type';
	const META_SUPPLIER_STOCK = '_dst2t_supplier_stock';
	const META_STOCK_OWNED = '_dst2t_supplier_stock_owned';
	const META_SUPPLIER_MISSING = '_dst2t_supplier_missing';
	const META_LAST_SEEN = '_dst2t_supplier_last_seen';
	const META_GROUP_CATEGORY = '_dst2t_group_category_id';
	const META_IMPORT_STRUCTURE = '_dst2t_import_structure';
	const META_VARIATION_LABEL = '_dst2t_variation_label';
	const META_VARIATION_ATTRIBUTE = '_dst2t_variation_attribute';
	const META_SUPERSEDED_BY = '_dst2t_superseded_by_variable_product';

	/** Hands a page that came from the full-page cache a current verification nonce. */
	const AJAX_VERIFY_NONCE = 'dst2t_verify_nonce';

	/** Player validations allowed per visitor per five minutes. */
	const VALIDATION_LIMIT  = 20;

	/** Player validations allowed per client address per five minutes. */
	const ADDRESS_LIMIT     = 300;

	/** Storefront player validations allowed for the whole site per minute. */
	const SITE_LIMIT        = 120;
	const META_LAST_SYNC    = '_dst2t_last_sync';

	/** @var DST2T_API_Client */
	private $api;

	/** @var DST2T_Settings */
	private $settings;

	/** @var array */
	private $validated = array();

	/** @var array Per-request requirement-schema cache keyed by supplier category. */
	private $requirements_cache = array();

	/** @var array Per-request catalog cache keyed by supplier category. */
	private $catalog_cache = array();

	public function __construct( DST2T_API_Client $api, DST2T_Settings $settings ) {
		$this->api      = $api;
		$this->settings = $settings;
	}

	/** Player-account field aliases accepted by imported/migrated requirement schemas. */
	public static function is_player_identifier_key( $key ) {
		return in_array( sanitize_key( (string) $key ), array( 'player_id', 'uid', 'user_id', 'userid', 'playerid' ), true );
	}

	/** Return the submitted player identifier key without changing the provider schema. */
	private function player_identifier_key( $requirements ) {
		foreach ( (array) $requirements as $key => $value ) {
			if ( self::is_player_identifier_key( $key ) && is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return sanitize_key( (string) $key );
			}
		}
		return '';
	}

	public function hooks() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'product_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_fields' ), 10, 3 );
		add_action( self::ACTION_REFRESH_MAPPING, array( $this, 'refresh_mapping_async' ), 10, 1 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
		add_filter( 'woocommerce_available_variation', array( $this, 'variation_data' ), 10, 3 );

		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_customer_fields' ), 12 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 4 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'create_order_line_item' ), 10, 4 );

		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
		add_action( 'wp_ajax_dst2t_verify_player', array( $this, 'ajax_verify_player' ) );
		add_action( 'wp_ajax_nopriv_dst2t_verify_player', array( $this, 'ajax_verify_player' ) );
		add_action( 'wp_ajax_' . self::AJAX_VERIFY_NONCE, array( $this, 'ajax_verify_nonce' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_VERIFY_NONCE, array( $this, 'ajax_verify_nonce' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'merge_duplicate_cart_rows' ), 20, 6 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'clean_stale_cart_rows' ), 20 );
	}

	public function is_mapped( $product_id ) {
		return 'yes' === get_post_meta( absint( $product_id ), self::META_ENABLED, true )
			&& absint( get_post_meta( absint( $product_id ), self::META_ITEM_ID, true ) ) > 0;
	}

	public function mapped_product_id( $product_id, $variation_id = 0 ) {
		if ( $variation_id && $this->is_mapped( $variation_id ) ) {
			return absint( $variation_id );
		}
		return $this->is_mapped( $product_id ) ? absint( $product_id ) : 0;
	}

	public function mapping( $product_id ) {
		$product_id = absint( $product_id );
		return array(
			'enabled'        => $this->is_mapped( $product_id ),
			'item_id'        => absint( get_post_meta( $product_id, self::META_ITEM_ID, true ) ),
			'category_id'    => absint( get_post_meta( $product_id, self::META_CATEGORY_ID, true ) ),
			'provider_name'  => (string) get_post_meta( $product_id, self::META_PROVIDER_NAME, true ),
			// Player ID is a fixed field of SHOP2TOPUP's player-validation contract.
			// Some categories therefore legitimately return an empty dynamic requirements
			// array. The effective schema adds the canonical player_id field for direct
			// top-ups without mutating the provider's cached dynamic schema.
			'requirements'   => $this->effective_schema( $product_id ),
			'last_cost'      => (string) get_post_meta( $product_id, self::META_LAST_COST, true ),
			'max_cost'       => (string) get_post_meta( $product_id, self::META_MAX_COST, true ),
			'returns_voucher' => 'yes' === get_post_meta( $product_id, self::META_VOUCHER, true ),
			'product_type'    => (string) get_post_meta( $product_id, self::META_PRODUCT_TYPE, true ),
			'supplier_missing' => 'yes' === get_post_meta( $product_id, self::META_SUPPLIER_MISSING, true ),
		);
	}

	public function product_tab( $tabs ) {
		$tabs['dst2t'] = array(
			'label'    => DST2T_Brand::label(),
			'target'   => 'dst2t_product_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 75,
		);
		return $tabs;
	}

	public function product_panel() {
		global $post;
		?>
		<div id="dst2t_product_data" class="panel woocommerce_options_panel hidden">
			<input type="hidden" name="dst2t_mapping_present" value="1" />
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => self::META_ENABLED,
						'label'       => __( 'Enable fulfillment', 'delicat-shop2topup' ),
						'description' => sprintf( /* translators: %s: neutral provider label. */ __( 'Send this product to %s only after WooCommerce confirms payment.', 'delicat-shop2topup' ), DST2T_Brand::label() ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => self::META_ITEM_ID,
						'label'             => __( 'Supplier item ID', 'delicat-shop2topup' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
					)
				);
				woocommerce_wp_checkbox(
					array(
						'id'          => self::META_VOUCHER,
						'label'       => __( 'Voucher-code product', 'delicat-shop2topup' ),
						'description' => __( 'Require secure voucher codes in the completed supplier response.', 'delicat-shop2topup' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => self::META_CATEGORY_ID,
						'label'             => __( 'Supplier category ID', 'delicat-shop2topup' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => self::META_MAX_COST,
						'label'       => __( 'Absolute max unit cost (USD)', 'delicat-shop2topup' ),
						'placeholder' => __( 'Optional', 'delicat-shop2topup' ),
						'description' => __( 'Fulfillment stops before purchase when the live supplier cost is above this amount.', 'delicat-shop2topup' ),
						'desc_tip'    => true,
					)
				);
				?>
				<p class="form-field">
					<label><?php esc_html_e( 'Cached provider data', 'delicat-shop2topup' ); ?></label>
					<span class="description">
						<?php
						$last_cost = get_post_meta( $post->ID, self::META_LAST_COST, true );
						echo $last_cost ? esc_html( '$' . $last_cost . ' USD' ) : esc_html__( 'Not refreshed yet', 'delicat-shop2topup' );
						$last_sync = absint( get_post_meta( $post->ID, self::META_LAST_SYNC, true ) );
						if ( $last_sync ) {
							/* translators: %s: human readable time difference. */
							echo ' · ' . esc_html( sprintf( __( 'synced %s ago', 'delicat-shop2topup' ), human_time_diff( $last_sync, time() ) ) );
						}
						$sync_error = (string) get_post_meta( $post->ID, self::META_SYNC_ERROR, true );
						if ( '' !== $sync_error ) {
							echo ' · ' . esc_html( sprintf( /* translators: %s: error code. */ __( 'last sync error: %s', 'delicat-shop2topup' ), $sync_error ) );
						}
						?>
					</span>
				</p>
				<?php if ( $this->is_mapped( $post->ID ) && current_user_can( 'manage_woocommerce' ) ) : ?>
					<p class="form-field">
						<a class="button" href="<?php echo esc_url( $this->refresh_url( $post->ID ) ); ?>"><?php esc_html_e( 'Refresh from provider', 'delicat-shop2topup' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** Admin link that re-reads requirements, cost, and availability for one product. */
	private function refresh_url( $product_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'dst2t_refresh_product',
					'product_id'  => absint( $product_id ),
					'redirect_to' => rawurlencode( (string) get_edit_post_link( absint( $product_id ), 'raw' ) ),
				),
				admin_url( 'admin-post.php' )
			),
			'dst2t_refresh_product'
		);
	}

	public function variation_fields( $loop, $variation_data, $variation ) {
		$prefix = 'dst2t_variation[' . absint( $loop ) . ']';
		$id     = absint( $variation->ID );
		?>
		<div class="form-row form-row-full dst2t-variation-fields">
			<h4><?php echo esc_html( sprintf( /* translators: %s: neutral provider label. */ __( '%s fulfillment', 'delicat-shop2topup' ), DST2T_Brand::label() ) ); ?></h4>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $prefix . '[enabled]' ); ?>" value="yes" <?php checked( $this->is_mapped( $id ) ); ?> />
				<?php esc_html_e( 'Enable for this variation', 'delicat-shop2topup' ); ?>
			</label>
			<p class="form-row form-row-first">
				<label><?php esc_html_e( 'Supplier item ID', 'delicat-shop2topup' ); ?></label>
				<input type="number" min="0" step="1" name="<?php echo esc_attr( $prefix . '[item_id]' ); ?>" value="<?php echo esc_attr( get_post_meta( $id, self::META_ITEM_ID, true ) ); ?>" />
			</p>
			<p class="form-row form-row-last">
				<label><?php esc_html_e( 'Supplier category ID', 'delicat-shop2topup' ); ?></label>
				<input type="number" min="0" step="1" name="<?php echo esc_attr( $prefix . '[category_id]' ); ?>" value="<?php echo esc_attr( get_post_meta( $id, self::META_CATEGORY_ID, true ) ); ?>" />
			</p>
			<p class="form-row form-row-first">
				<label><?php esc_html_e( 'Absolute max unit cost (USD)', 'delicat-shop2topup' ); ?></label>
				<input type="text" inputmode="decimal" name="<?php echo esc_attr( $prefix . '[max_cost]' ); ?>" value="<?php echo esc_attr( get_post_meta( $id, self::META_MAX_COST, true ) ); ?>" />
			</p>
			<p class="form-row form-row-last">
				<label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[returns_voucher]' ); ?>" value="yes" <?php checked( 'yes', get_post_meta( $id, self::META_VOUCHER, true ) ); ?> /> <?php esc_html_e( 'Voucher-code product', 'delicat-shop2topup' ); ?></label>
			</p>
			<p class="form-row form-row-full">
				<strong><?php esc_html_e( 'Supplier cost (read only)', 'delicat-shop2topup' ); ?>:</strong>
				<?php $variation_cost = (string) get_post_meta( $id, self::META_LAST_COST, true ); ?>
				<span><?php echo esc_html( '' !== $variation_cost ? '$' . $variation_cost . ' USD' : '—' ); ?></span><br />
				<small><?php esc_html_e( 'Set the customer-facing price in WooCommerce above. In Manual Pricing mode that HTG/store price never changes the supplier USD cost and supplier sync never overwrites it.', 'delicat-shop2topup' ); ?></small>
			</p>
		</div>
		<?php
	}

	public function save_product( $product_id ) {
		// Quick/bulk/REST saves do not submit this panel. Preserve their mappings.
		if ( ! isset( $_POST['dst2t_mapping_present'] ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}
		$enabled     = isset( $_POST[ self::META_ENABLED ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$item_id     = isset( $_POST[ self::META_ITEM_ID ] ) ? absint( $_POST[ self::META_ITEM_ID ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$category_id = isset( $_POST[ self::META_CATEGORY_ID ] ) ? absint( $_POST[ self::META_CATEGORY_ID ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$max_cost    = isset( $_POST[ self::META_MAX_COST ] ) ? $this->sanitize_cost( wp_unslash( $_POST[ self::META_MAX_COST ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$voucher     = isset( $_POST[ self::META_VOUCHER ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'no' === $enabled && ! $this->is_mapped( $product_id ) && ! $item_id && ! $category_id ) {
			return; // An unrelated product must not acquire supplier metadata/jobs.
		}
		$this->save_mapping( $product_id, $enabled, $item_id, $category_id, $max_cost, '', $voucher );
	}

	public function save_variation( $variation_id, $loop ) {
		if ( ! current_user_can( 'edit_post', $variation_id ) || empty( $_POST['dst2t_variation'][ $loop ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$data        = (array) wp_unslash( $_POST['dst2t_variation'][ $loop ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$enabled     = ! empty( $data['enabled'] ) ? 'yes' : 'no';
		$item_id     = isset( $data['item_id'] ) ? absint( $data['item_id'] ) : 0;
		$category_id = isset( $data['category_id'] ) ? absint( $data['category_id'] ) : 0;
		$max_cost    = isset( $data['max_cost'] ) ? $this->sanitize_cost( $data['max_cost'] ) : '';
		$voucher     = ! empty( $data['returns_voucher'] );
		$this->save_mapping( $variation_id, $enabled, $item_id, $category_id, $max_cost, '', $voucher );
	}

	public function save_mapping( $product_id, $enabled, $item_id, $category_id, $max_cost = '', $provider_name = '', $returns_voucher = false, $schema = null, $last_cost = '' ) {
		$product_id = absint( $product_id );
		update_post_meta( $product_id, self::META_ENABLED, 'yes' === $enabled && $item_id && $category_id ? 'yes' : 'no' );
		update_post_meta( $product_id, self::META_ITEM_ID, absint( $item_id ) );
		update_post_meta( $product_id, self::META_CATEGORY_ID, absint( $category_id ) );
		update_post_meta( $product_id, self::META_MAX_COST, $this->sanitize_cost( $max_cost ) );
		if ( '' !== $provider_name ) {
			update_post_meta( $product_id, self::META_PROVIDER_NAME, sanitize_text_field( $provider_name ) );
		}
		update_post_meta( $product_id, self::META_VOUCHER, $returns_voucher ? 'yes' : 'no' );
		if ( is_array( $schema ) ) {
			update_post_meta( $product_id, self::META_REQUIREMENTS, wp_json_encode( $this->sanitize_schema( $schema ) ) );
		}
		if ( '' !== $last_cost ) {
			update_post_meta( $product_id, self::META_LAST_COST, $this->sanitize_cost( $last_cost ) );
		}
		wc_delete_product_transients( $product_id );
		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $parent_id ) {
			wc_delete_product_transients( $parent_id );
		}

		if ( 'yes' === $enabled && $item_id && $category_id && null === $schema && $this->settings->credentials_configured() ) {
			/*
			 * Never call the supplier API inside WooCommerce's product-save request.
			 * A variable product can save many variations at once; the old synchronous
			 * requirements + price requests could therefore hold post.php open for
			 * tens of seconds and make the "Update" button look broken. Persist the
			 * mapping immediately and refresh provider data in a separate worker.
			 */
			DST2T_Scheduler::enqueue( self::ACTION_REFRESH_MAPPING, array( $product_id ), 5 );
		}
	}

	/**
	 * Refresh one saved product mapping outside the wp-admin save request.
	 *
	 * The mapping is re-read before doing any network I/O so a queued task cannot
	 * revive a product that the operator disabled after the task was scheduled.
	 */
	public function refresh_mapping_async( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id || ! $this->settings->credentials_configured() || ! $this->is_mapped( $product_id ) ) {
			return;
		}

		try {
			// Requirements and cost only. Stock remains controlled by the normal
			// catalog sync so an admin save cannot overwrite "Manage stock?".
			$this->refresh_mapping( $product_id, false );
		} catch ( DST2T_API_Exception $error ) {
			update_post_meta( $product_id, self::META_SYNC_ERROR, sanitize_key( $error->get_api_code() ) );
		} catch ( Throwable $error ) {
			update_post_meta( $product_id, self::META_SYNC_ERROR, 'BACKGROUND_REFRESH_FAILED' );
		}
	}

	/**
	 * Re-reads the supplier requirement schema and live unit cost for one product.
	 *
	 * @param int  $product_id WooCommerce product or variation id.
	 * @param bool $with_stock Mirror supplier stock when the supplier reports it.
	 * @return array The live price payload.
	 */
	public function refresh_mapping( $product_id, $with_stock = true ) {
		if ( class_exists( 'Delicat_Custom_Packages', false ) && Delicat_Custom_Packages::recipe( $product_id ) ) { return; }
		$mapping = $this->mapping( $product_id );
		if ( ! $mapping['item_id'] || ! $mapping['category_id'] ) {
			throw new InvalidArgumentException( 'The product mapping is incomplete.' );
		}

		$schema       = $this->requirements_for( $mapping['category_id'] );
		$catalog_item = null;
		if ( $with_stock && $this->settings->enabled( 'sync_stock' ) ) {
			$catalog_item = $this->catalog_item_for( $mapping['category_id'], $mapping['item_id'] );
			if ( null === $catalog_item ) {
				throw new DST2T_API_Exception( 'The mapped catalog item is no longer offered.', 'ITEM_NOT_FOUND', 404 );
			}
		}

		$price = $this->api->price( $mapping['item_id'] );
		update_post_meta( $product_id, self::META_REQUIREMENTS, wp_json_encode( $this->sanitize_schema( $schema ) ) );
		$unit_cost = DST2T_Decimal::normalize( isset( $price['unit_price'] ) ? $price['unit_price'] : '0' );
		if ( 0 === DST2T_Decimal::compare( $unit_cost, '0' ) ) {
			// A zero unit price is a missing price, not a free item. Storing it would
			// set the relative cost guard to zero and block the product entirely.
			update_post_meta( $product_id, self::META_SYNC_ERROR, 'ZERO_COST' );
		} else {
			update_post_meta( $product_id, self::META_LAST_COST, $unit_cost );
			delete_post_meta( $product_id, self::META_SYNC_ERROR );
		}
		update_post_meta( $product_id, self::META_LAST_SYNC, time() );
		if ( ! empty( $price['item_name'] ) ) {
			update_post_meta( $product_id, self::META_PROVIDER_NAME, sanitize_text_field( DST2T_Brand::scrub( $price['item_name'] ) ) );
		}

		$product = wc_get_product( $product_id );
		if ( $product && 'automatic' === $this->settings->get( 'catalog_pricing_mode', 'manual' ) && $this->settings->enabled( 'sync_catalog_prices' ) && 0 !== DST2T_Decimal::compare( $unit_cost, '0' ) ) {
			$selling = $this->selling_price( $price['unit_price'] );
			if ( '' !== $selling && $selling !== (string) $product->get_regular_price() ) {
				$product->set_regular_price( $selling );
				if ( '' === (string) $product->get_sale_price() ) {
					$product->set_price( $selling );
				}
				$product->save();
			}
		}

		if ( $catalog_item ) {
			$this->apply_catalog_snapshot( $product_id, $catalog_item, true );
		}

		wc_delete_product_transients( $product_id );
		return $price;
	}

	/** Supplier requirement schemas are identical per category; fetch each one once per request. */
	public function requirements_for( $category_id ) {
		$category_id = absint( $category_id );
		if ( ! isset( $this->requirements_cache[ $category_id ] ) ) {
			$this->requirements_cache[ $category_id ] = $this->api->requirements( $category_id );
		}
		return $this->requirements_cache[ $category_id ];
	}

	/** Converts a supplier USD unit cost into a store-currency selling price. */
	public function selling_price( $cost ) {
		try {
			$amount = (float) DST2T_Decimal::normalize( $cost );
		} catch ( Throwable $error ) {
			return '';
		}
		$exchange = (float) $this->settings->get( 'usd_exchange_rate', '1' );
		$markup   = (float) $this->settings->get( 'markup_percent', '20' );
		return (string) wc_format_decimal( $amount * $exchange * ( 1 + ( $markup / 100 ) ), wc_get_price_decimals() );
	}

	/**
	 * Returns one normalized catalog item without issuing repeated list calls.
	 *
	 * @return array|null
	 */
	public function catalog_item_for( $category_id, $item_id ) {
		$category_id = absint( $category_id );
		$item_id     = absint( $item_id );
		if ( ! $category_id || ! $item_id ) {
			return null;
		}
		if ( ! isset( $this->catalog_cache[ $category_id ] ) ) {
			$map = array();
			foreach ( (array) $this->api->subcategories( $category_id ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$id = absint( isset( $item['item_id'] ) ? $item['item_id'] : ( isset( $item['id'] ) ? $item['id'] : 0 ) );
				if ( $id ) {
					$map[ $id ] = $item;
				}
			}
			$this->catalog_cache[ $category_id ] = $map;
		}
		return isset( $this->catalog_cache[ $category_id ][ $item_id ] ) ? $this->catalog_cache[ $category_id ][ $item_id ] : null;
	}

	/**
	 * Applies a supplier catalog snapshot while preserving the shop's own stock
	 * choice whenever the supplier did not actually report availability.
	 *
	 * Confirmed zero stock takes the product offline. Missing/blank stock never
	 * guesses zero; if a previous supplier-owned stock value existed, ownership is
	 * released and the product is restored to a normal sellable state.
	 *
	 * @param int   $product_id WooCommerce product/variation id.
	 * @param array $item       Normalized catalog item.
	 * @param bool  $save       Persist the WooCommerce product immediately.
	 * @return bool Whether WooCommerce stock state changed.
	 */
	public function apply_catalog_snapshot( $product_id, $item, $save = true ) {
		if ( class_exists( 'Delicat_Custom_Packages', false ) && Delicat_Custom_Packages::recipe( $product_id ) ) { return false; }
		$product_id = absint( $product_id );
		if ( ! $product_id || ! is_array( $item ) ) {
			return false;
		}

		$product_type = isset( $item['product_type'] ) ? strtolower( sanitize_key( $item['product_type'] ) ) : '';
		if ( ! in_array( $product_type, array( 'topup', 'voucher' ), true ) ) {
			$product_type = ! empty( $item['returns_voucher'] ) ? 'voucher' : 'topup';
		}
		$was_missing = 'yes' === get_post_meta( $product_id, self::META_SUPPLIER_MISSING, true );
		update_post_meta( $product_id, self::META_PRODUCT_TYPE, $product_type );
		update_post_meta( $product_id, self::META_VOUCHER, 'voucher' === $product_type ? 'yes' : 'no' );
		update_post_meta( $product_id, self::META_SUPPLIER_MISSING, 'no' );
		update_post_meta( $product_id, self::META_LAST_SEEN, time() );

		if ( ! $this->settings->enabled( 'sync_stock' ) ) {
			return false;
		}

		$quantity = null;
		foreach ( array( 'stock', 'quantity', 'stock_quantity', 'available_quantity', 'remaining' ) as $key ) {
			if ( array_key_exists( $key, $item ) && '' !== (string) $item[ $key ] && is_numeric( $item[ $key ] ) ) {
				$quantity = max( 0, (int) $item[ $key ] );
				break;
			}
		}

		$in_stock = null;
		foreach ( array( 'in_stock', 'available', 'is_available', 'stock_status', 'availability' ) as $key ) {
			if ( ! array_key_exists( $key, $item ) ) {
				continue;
			}
			$value = $item[ $key ];
			if ( is_bool( $value ) ) {
				$in_stock = $value;
				break;
			}
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$normal = strtolower( trim( (string) $value ) );
			if ( in_array( $normal, array( 'instock', 'in_stock', 'available', 'yes', 'true', '1', 'active' ), true ) ) {
				$in_stock = true;
				break;
			}
			if ( in_array( $normal, array( 'outofstock', 'out_of_stock', 'unavailable', 'no', 'false', '0', 'inactive' ), true ) ) {
				$in_stock = false;
				break;
			}
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}
		$owned   = (string) get_post_meta( $product_id, self::META_STOCK_OWNED, true );
		$changed = false;

		if ( null === $quantity && null === $in_stock ) {
			// The provider did not report stock. Never convert an omitted field into
			// zero. If this integration managed stock on a previous snapshot, release
			// that state so an old zero cannot permanently block customer purchases.
			if ( '' !== $owned || $was_missing ) {
				if ( method_exists( $product, 'get_manage_stock' ) && $product->get_manage_stock() ) {
					$product->set_manage_stock( false );
					$changed = true;
				}
				if ( method_exists( $product, 'get_stock_status' ) && 'instock' !== $product->get_stock_status() ) {
					$product->set_stock_status( 'instock' );
					$changed = true;
				}
				delete_post_meta( $product_id, self::META_STOCK_OWNED );
				delete_post_meta( $product_id, self::META_SUPPLIER_STOCK );
			}
		} elseif ( null !== $quantity ) {
			$in_stock = null === $in_stock ? $quantity > 0 : $in_stock;
			if ( ! $product->get_manage_stock() ) {
				$product->set_manage_stock( true );
				$changed = true;
			}
			if ( (int) $product->get_stock_quantity() !== $quantity ) {
				$product->set_stock_quantity( $quantity );
				$changed = true;
			}
			if ( method_exists( $product, 'set_backorders' ) ) {
				$product->set_backorders( 'no' );
			}
			$status = $in_stock ? 'instock' : 'outofstock';
			if ( $product->get_stock_status() !== $status ) {
				$product->set_stock_status( $status );
				$changed = true;
			}
			update_post_meta( $product_id, self::META_STOCK_OWNED, 'quantity' );
			update_post_meta( $product_id, self::META_SUPPLIER_STOCK, (string) $quantity );
		} else {
			$status = $in_stock ? 'instock' : 'outofstock';
			if ( $product->get_stock_status() !== $status ) {
				$product->set_stock_status( $status );
				$changed = true;
			}
			update_post_meta( $product_id, self::META_STOCK_OWNED, 'status' );
			update_post_meta( $product_id, self::META_SUPPLIER_STOCK, $in_stock ? 'available' : 'unavailable' );
		}

		if ( $changed && $save ) {
			$product->save();
			wc_delete_product_transients( $product_id );
		}
		return $changed;
	}

	/** Marks a mapped item withdrawn by the supplier without deleting its mapping. */
	public function mark_supplier_missing( $product_id ) {
		$product_id = absint( $product_id );
		update_post_meta( $product_id, self::META_SUPPLIER_MISSING, 'yes' );
		$product = wc_get_product( $product_id );
		if ( $product && 'outofstock' !== $product->get_stock_status() ) {
			$product->set_stock_status( 'outofstock' );
			$product->save();
		}
		wc_delete_product_transients( $product_id );
	}

	public function variation_data( $data, $product, $variation ) {
		$id = $variation->get_id();
		if ( ! $this->is_mapped( $id ) && $this->is_mapped( $product->get_id() ) ) {
			$id = $product->get_id();
		}
		if ( ! $this->is_mapped( $id ) ) {
			$data[ DST2T_Privacy::prefix() . '_enabled' ] = false;
			return $data;
		}
		$prefix                             = DST2T_Privacy::prefix();
		$data[ $prefix . '_enabled' ]     = true;
		$data[ $prefix . '_fields_html' ] = $this->fields_html( $id );
		return $data;
	}

	public function render_customer_fields() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$prefix = DST2T_Privacy::prefix();
		if ( $product->is_type( 'variable' ) ) {
			// Render the common account fields immediately, before a package is chosen.
			// The Delicat native product UI uses visual package cards and the mobile dock
			// can remain in the unselected state for a while; an empty mount point made
			// the UID field look as if it did not exist. Imported groups are one supplier
			// category per parent, so the first mapped direct-top-up variation supplies the
			// correct common schema. Variation JS still replaces it if a selected item has
			// a different schema (or is a voucher).
			if ( $this->has_mapped_variations( $product ) ) {
				$default_html = $this->variable_default_fields_html( $product );
				echo '<div class="' . esc_attr( $prefix ) . '-requirements" data-' . esc_attr( $prefix ) . '-variable="1"' . ( '' === $default_html ? ' aria-hidden="true"' : ' aria-hidden="false"' ) . '>' . $default_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			return;
		}
		if ( $this->is_mapped( $product->get_id() ) ) {
			echo '<div class="' . esc_attr( $prefix ) . '-requirements">' . $this->fields_html( $product->get_id() ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		$mapped_id = $this->mapped_product_id( $product_id, $variation_id );
		if ( ! $mapped_id ) {
			return $passed;
		}

		$woocommerce_gate = $this->settings->enabled( 'woocommerce_payment_gate' );
		try {
			// In WooCommerce-first mode, add-to-cart and checkout must never depend on
			// supplier availability. Validate only the locally cached schema here; the
			// supplier is consulted after WooCommerce has approved the payment.
			$requirements = $this->posted_requirements( $mapped_id, ! $woocommerce_gate );
			$player       = array();

			if ( $this->settings->enabled( 'validate_player' ) ) {
				$token = $this->posted_verification_token();
				if ( '' !== $token ) {
					$player = $this->player_from_verification_token( $token, $mapped_id, $requirements );
				}
			}

			// Legacy strict mode remains available for stores that intentionally want
			// supplier verification to gate the cart. The recommended/default mode is
			// WooCommerce-first, which prevents upstream outages from blocking payment.
			if ( ! $woocommerce_gate && $this->settings->enabled( 'validate_player' ) && ! $player ) {
				if ( ! $this->settings->credentials_configured() ) {
					throw new DST2T_API_Exception( 'Supplier connection is unavailable.', 'SERVICE_UNAVAILABLE' );
				}
				$player_key = $this->player_identifier_key( $requirements );
				if ( '' !== $player_key ) {
					if ( $this->validation_throttled() ) {
						throw new DST2T_API_Exception( 'Too many validation attempts from this visitor.', 'RATE_LIMIT_EXCEEDED' );
					}
					$mapping = $this->mapping( $mapped_id );
					$player  = $this->api->validate_player( $mapping['item_id'], $requirements, $mapping['provider_name'] . ' ' . get_the_title( $mapped_id ) );
				}
			}
			$this->validated[ $mapped_id ] = array( 'requirements' => $requirements, 'player' => $player );
		} catch ( InvalidArgumentException $error ) {
			wc_add_notice( $error->getMessage(), 'error' );
			return false;
		} catch ( DST2T_API_Exception $error ) {
			wc_add_notice( $this->customer_error( $error ), 'error' );
			return false;
		} catch ( Throwable $error ) {
			wc_add_notice( __( 'Les informations du compte de jeu sont incomplètes. Vérifiez-les puis réessayez.', 'delicat-shop2topup' ), 'error' );
			return false;
		}

		return $passed;
	}

	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id, $quantity ) {
		$mapped_id = $this->mapped_product_id( $product_id, $variation_id );
		if ( ! $mapped_id ) {
			return $cart_item_data;
		}
		if ( ! isset( $this->validated[ $mapped_id ] ) ) {
			try {
				$this->validated[ $mapped_id ] = array( 'requirements' => $this->posted_requirements( $mapped_id, ! $this->settings->enabled( 'woocommerce_payment_gate' ) ), 'player' => array() );
			} catch ( Throwable $error ) {
				return $cart_item_data;
			}
		}

		$mapping                  = $this->mapping( $mapped_id );
		$cart_item_data['dst2t']  = array(
			'product_id'   => $mapped_id,
			'item_id'      => $mapping['item_id'],
			'category_id'  => $mapping['category_id'],
			'requirements' => $this->validated[ $mapped_id ]['requirements'],
			'player'       => $this->validated[ $mapped_id ]['player'],
			'player_verified' => ! empty( $this->validated[ $mapped_id ]['player']['player_name'] ),
			'returns_voucher' => $mapping['returns_voucher'],
			'labels'       => $this->schema_labels( $mapping['requirements'] ),
		);
		$cart_item_data['dst2t_key'] = $this->cart_identity_hash( $cart_item_data['dst2t'] );
		return $cart_item_data;
	}

	/**
	 * Stable supplier identity for cart merging. Human-readable labels and the
	 * provider's returned player_name are deliberately excluded because they can
	 * change between validation calls without changing what the customer ordered.
	 */
	private function cart_identity_hash( $data ) {
		$data = is_array( $data ) ? $data : array();
		$requirements = isset( $data['requirements'] ) && is_array( $data['requirements'] ) ? $data['requirements'] : array();
		$requirements = $this->sort_recursive( $requirements );
		$identity = array(
			'item_id'      => absint( isset( $data['item_id'] ) ? $data['item_id'] : 0 ),
			'requirements' => $requirements,
			'voucher'      => ! empty( $data['returns_voucher'] ) ? 1 : 0,
		);
		return hash( 'sha256', wp_json_encode( $identity ) );
	}

	private function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
		if ( $assoc ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->sort_recursive( $child );
		}
		return $value;
	}

	/** Merge duplicate rows for the exact same supplier item + account details. */
	public function merge_duplicate_cart_rows( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart || ! isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}
		$current = WC()->cart->cart_contents[ $cart_item_key ];
		if ( empty( $current['dst2t'] ) || ! is_array( $current['dst2t'] ) ) {
			return;
		}
		$identity = $this->cart_identity_hash( $current['dst2t'] );
		$total = 0;
		$duplicates = array();
		foreach ( WC()->cart->get_cart() as $key => $row ) {
			if ( empty( $row['dst2t'] ) || ! is_array( $row['dst2t'] ) ) {
				continue;
			}
			if ( absint( $row['product_id'] ?? 0 ) !== absint( $current['product_id'] ?? 0 ) || absint( $row['variation_id'] ?? 0 ) !== absint( $current['variation_id'] ?? 0 ) ) { continue; }
			if ( ! hash_equals( $identity, $this->cart_identity_hash( $row['dst2t'] ) ) ) {
				continue;
			}
			$total += max( 1, absint( isset( $row['quantity'] ) ? $row['quantity'] : 1 ) );
			if ( $key !== $cart_item_key ) {
				$duplicates[] = $key;
			}
		}
		if ( ! $duplicates ) {
			return;
		}
		foreach ( $duplicates as $key ) {
			WC()->cart->remove_cart_item( $key );
		}
		WC()->cart->set_quantity( $cart_item_key, max( 1, $total ), false );
	}

	/**
	 * Remove cart rows that can no longer be fulfilled safely: old simple products
	 * superseded by the variable import, and direct-top-up rows saved before UID
	 * became mandatory. These are the rows that used to reappear beside the new
	 * verified variation after an upgrade.
	 */
	public function clean_stale_cart_rows( $cart ) {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		$removed = false;
		foreach ( $cart->get_cart() as $key => $row ) {
			$mapped_id = 0;
			if ( ! empty( $row['dst2t']['product_id'] ) ) {
				$mapped_id = absint( $row['dst2t']['product_id'] );
			} elseif ( ! empty( $row['variation_id'] ) ) {
				$mapped_id = absint( $row['variation_id'] );
			} elseif ( ! empty( $row['product_id'] ) ) {
				$mapped_id = absint( $row['product_id'] );
			}
			if ( ! $mapped_id ) {
				continue;
			}

			if ( absint( get_post_meta( $mapped_id, self::META_SUPERSEDED_BY, true ) ) > 0 ) {
				$cart->remove_cart_item( $key );
				$removed = true;
				continue;
			}

			if ( empty( $row['dst2t'] ) || ! is_array( $row['dst2t'] ) || ! $this->is_mapped( $mapped_id ) ) {
				continue;
			}
			// Only the voucher flag is needed here; mapping() would also decode and
			// rebuild the requirement schema for every cart row on every request.
			if ( 'yes' === get_post_meta( $mapped_id, self::META_VOUCHER, true ) ) {
				continue;
			}
			$requirements = isset( $row['dst2t']['requirements'] ) && is_array( $row['dst2t']['requirements'] ) ? $row['dst2t']['requirements'] : array();
			if ( '' === $this->player_identifier_key( $requirements ) ) {
				$cart->remove_cart_item( $key );
				$removed = true;
			}
		}
		if ( $removed && function_exists( 'wc_add_notice' ) && ! wc_has_notice( __( 'Un ancien article de recharge incomplet a été retiré du panier. Veuillez l’ajouter de nouveau avec un UID / ID Joueur vérifié.', 'delicat-shop2topup' ), 'notice' ) ) {
			wc_add_notice( __( 'Un ancien article de recharge incomplet a été retiré du panier. Veuillez l’ajouter de nouveau avec un UID / ID Joueur vérifié.', 'delicat-shop2topup' ), 'notice' );
		}
	}

	public function display_cart_item_data( $display, $cart_item ) {
		if ( empty( $cart_item['dst2t']['requirements'] ) ) {
			return $display;
		}
		$labels = isset( $cart_item['dst2t']['labels'] ) ? (array) $cart_item['dst2t']['labels'] : array();
		foreach ( (array) $cart_item['dst2t']['requirements'] as $key => $value ) {
			$display[] = array(
				'key'   => isset( $labels[ $key ] ) ? $labels[ $key ] : $this->field_label( $key ),
				'value' => is_array( $value ) ? implode( ', ', array_map( 'sanitize_text_field', $value ) ) : sanitize_text_field( $value ),
			);
		}
		if ( ! empty( $cart_item['dst2t']['player']['player_name'] ) ) {
			$display[] = array( 'key' => __( 'Nom du joueur', 'delicat-shop2topup' ), 'value' => sanitize_text_field( $cart_item['dst2t']['player']['player_name'] ) );
		}
		return $display;
	}

	public function create_order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['dst2t'] ) || ! is_array( $values['dst2t'] ) ) {
			return;
		}
		$data = $values['dst2t'];
		$item->add_meta_data( '_dst2t_returns_voucher', ! empty( $data['returns_voucher'] ) ? 'yes' : 'no', true );
		$item->add_meta_data( '_dst2t_product_id', absint( $data['product_id'] ), true );
		$item->add_meta_data( '_dst2t_item_id', absint( $data['item_id'] ), true );
		$item->add_meta_data( '_dst2t_category_id', absint( $data['category_id'] ), true );
		$item->add_meta_data( '_dst2t_requirements', wp_json_encode( (array) $data['requirements'] ), true );
		if ( ! empty( $data['player']['player_name'] ) ) {
			$item->add_meta_data( '_dst2t_player_name', sanitize_text_field( $data['player']['player_name'] ), true );
			$item->add_meta_data( '_dst2t_player_validated', ! empty( $data['player_verified'] ) ? 'yes' : 'no', true );
		} else {
			$item->add_meta_data( '_dst2t_player_validated', 'no', true );
		}

		$labels = isset( $data['labels'] ) ? (array) $data['labels'] : array();
		foreach ( (array) $data['requirements'] as $key => $value ) {
			$label = isset( $labels[ $key ] ) ? $labels[ $key ] : $this->field_label( $key );
			$value = is_array( $value ) ? implode( ', ', $value ) : $value;
			$item->add_meta_data( $label, sanitize_text_field( $value ), false );
		}
		if ( ! empty( $data['player']['player_name'] ) ) {
			$item->add_meta_data( __( 'Nom du joueur', 'delicat-shop2topup' ), sanitize_text_field( $data['player']['player_name'] ), false );
		}
	}


	private function requirements_from_array( $product_id, $posted ) {
		$schema = $this->effective_schema( $product_id );
		if ( ! $schema ) {
			return array();
		}
		$posted = is_array( $posted ) ? $posted : array();
		$out    = array();
		foreach ( $schema as $field ) {
			$name    = $field['field_name'];
			$type    = $field['data_type'];
			$value   = isset( $posted[ $name ] ) ? $posted[ $name ] : '';
			$options = isset( $field['select_options'] ) ? (array) $field['select_options'] : array();

			if ( 'multi_select' === $type ) {
				$value = array_values( array_filter( array_map( 'sanitize_text_field', (array) $value ), 'strlen' ) );
				if ( ! $value || array_diff( $value, $options ) ) {
					throw new InvalidArgumentException( sprintf( __( 'Veuillez choisir une valeur valide pour %s.', 'delicat-shop2topup' ), $this->field_label( $name ) ) );
				}
			} else {
				$value = sanitize_text_field( is_array( $value ) ? '' : $value );
				if ( '' === $value ) {
					throw new InvalidArgumentException( sprintf( __( '%s est requis.', 'delicat-shop2topup' ), $this->field_label( $name ) ) );
				}
				if ( 'single_select' === $type && ! in_array( $value, $options, true ) ) {
					throw new InvalidArgumentException( sprintf( __( 'Veuillez choisir une valeur valide pour %s.', 'delicat-shop2topup' ), $this->field_label( $name ) ) );
				}
				if ( 'number' === $type && ! preg_match( '/^[0-9]+$/', $value ) ) {
					throw new InvalidArgumentException( sprintf( __( '%s doit contenir uniquement des chiffres.', 'delicat-shop2topup' ), $this->field_label( $name ) ) );
				}
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	/**
	 * Fresh nonce for the "Vérifier votre compte" button.
	 *
	 * Product pages are served from LiteSpeed's page cache for days, while a
	 * WordPress nonce lives 12-24 hours and, for a guest with a cart, is tied to
	 * that guest's WooCommerce session. A cached page therefore often carries a
	 * nonce the server no longer accepts, and the button failed with a bare "-1".
	 * The script asks here once and retries. This hands out nothing a visitor
	 * cannot already get by loading an uncached product page.
	 */
	public function ajax_verify_nonce() {
		if ( function_exists( 'nocache_headers' ) && ! headers_sent() ) {
			nocache_headers();
		}
		if ( ! $this->settings->enabled( 'validate_player' ) ) {
			wp_send_json_error( array( 'message' => __( 'La vérification du compte est actuellement désactivée.', 'delicat-shop2topup' ) ), 400 );
		}
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'dst2t_verify_player' ) ) );
	}

	public function ajax_verify_player() {
		check_ajax_referer( 'dst2t_verify_player', 'nonce' );

		if ( ! $this->settings->enabled( 'validate_player' ) ) {
			wp_send_json_error( array( 'message' => __( 'La vérification du compte est actuellement désactivée.', 'delicat-shop2topup' ) ), 400 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $product_id || ! $this->is_mapped( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( "Ce produit n'est pas configuré pour la vérification du compte.", 'delicat-shop2topup' ) ), 404 );
		}

		if ( $this->validation_throttled() ) {
			wp_send_json_error( array( 'message' => __( 'La vérification est temporairement occupée. Veuillez patienter un instant puis réessayer.', 'delicat-shop2topup' ) ), 429 );
		}

		$raw_requirements = isset( $_POST['requirements'] ) && is_array( $_POST['requirements'] ) ? (array) wp_unslash( $_POST['requirements'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		try {
			$requirements = $this->requirements_from_array( $product_id, $raw_requirements );
			$mapping      = $this->mapping( $product_id );
			if ( empty( $mapping['item_id'] ) ) {
				throw new DST2T_API_Exception( 'Mapped item missing.', 'INVALID_PRODUCT_CONFIG', 400 );
			}
			$player = $this->api->validate_player( $mapping['item_id'], $requirements, $mapping['provider_name'] . ' ' . get_the_title( $product_id ) );
			$name   = isset( $player['player_name'] ) ? sanitize_text_field( (string) $player['player_name'] ) : '';
			$meta   = array();
			foreach ( array( 'region', 'server', 'zone_id', 'player_id' ) as $key ) {
				if ( isset( $player[ $key ] ) && is_scalar( $player[ $key ] ) && '' !== trim( (string) $player[ $key ] ) ) {
					$meta[ $key ] = sanitize_text_field( (string) $player[ $key ] );
				}
			}
			$message = $name ? sprintf( __( 'Compte vérifié avec succès : %s', 'delicat-shop2topup' ), $name ) : __( 'Compte vérifié avec succès.', 'delicat-shop2topup' );
			do_action( 'dst2t_player_verified', $product_id, $requirements );
			wp_send_json_success(
				array(
					'message'            => $message,
					'player_name'        => $name,
					'meta'               => $meta,
					'verification_token' => $this->make_verification_token( $product_id, $requirements, $player ),
				)
			);
		} catch ( InvalidArgumentException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		} catch ( DST2T_API_Exception $error ) {
			wp_send_json_error(
				array(
					'message' => $this->customer_error( $error ),
					'code'    => $error->get_api_code(),
				),
				$error->getCode() > 0 ? absint( $error->getCode() ) : 400
			);
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => __( 'Impossible de vérifier le compte pour le moment. Veuillez réessayer.', 'delicat-shop2topup' ) ), 500 );
		}
	}

	public function frontend_assets() {
		if ( ! is_product() ) {
			return;
		}
		// Only on a product this plugin actually renders fields for: an unrelated
		// product page should emit nothing at all.
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( $product->is_type( 'variable' ) ) {
			if ( ! $this->has_mapped_variations( $product ) ) {
				return;
			}
		} elseif ( ! $this->is_mapped( $product->get_id() ) ) {
			return;
		}
		// Inlined while stealth mode is on so no plugin path reaches the page source.
		DST2T_Privacy::enqueue_frontend();
	}

	/** Whether a variable parent has at least one supplier-mapped variation. */
	private function has_mapped_variations( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) || ! method_exists( $product, 'get_children' ) ) {
			return false;
		}
		foreach ( (array) $product->get_children() as $child_id ) {
			if ( $this->is_mapped( $child_id ) ) {
				return true;
			}
		}
		return false;
	}

	public function schema( $product_id ) {
		$raw    = get_post_meta( absint( $product_id ), self::META_REQUIREMENTS, true );
		$schema = json_decode( (string) $raw, true );
		return is_array( $schema ) ? $this->sanitize_schema( $schema ) : array();
	}

	/**
	 * Customer-facing schema for one mapped item.
	 *
	 * SHOP2TOPUP documents player_id as a fixed required field of
	 * POST /player/validate, while GET /catalog/category/:id/requirements describes
	 * the category's dynamic/additional fields. Some categories therefore return
	 * zero dynamic fields even though a UID / Player ID is still required. Direct
	 * top-ups always get one canonical player_id input unless the provider schema
	 * already exposes player_id (or one of the accepted legacy aliases). Vouchers
	 * never receive the synthetic account field.
	 */
	public function effective_schema( $product_id ) {
		$product_id = absint( $product_id );
		$schema     = $this->schema( $product_id );
		if ( ! $product_id || ! $this->is_mapped( $product_id ) ) {
			return $schema;
		}

		$product_type = strtolower( sanitize_key( (string) get_post_meta( $product_id, self::META_PRODUCT_TYPE, true ) ) );
		$is_voucher   = 'yes' === get_post_meta( $product_id, self::META_VOUCHER, true ) || 'voucher' === $product_type;
		if ( $is_voucher ) {
			return $schema;
		}

		foreach ( $schema as $field ) {
			if ( ! empty( $field['field_name'] ) && self::is_player_identifier_key( $field['field_name'] ) ) {
				return $schema;
			}
		}

		array_unshift(
			$schema,
			array(
				'field_name'     => 'player_id',
				'data_type'      => 'text',
				'placeholder'    => __( 'Entrez votre UID / Player ID', 'delicat-shop2topup' ),
				'select_options' => array(),
			)
		);
		return $schema;
	}

	/** Immediate account form for a grouped variable product before selection. */
	private function variable_default_fields_html( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) || ! method_exists( $product, 'get_children' ) ) {
			return '';
		}
		foreach ( (array) $product->get_children() as $child_id ) {
			if ( ! $this->is_mapped( $child_id ) ) {
				continue;
			}
			$html = $this->fields_html( $child_id );
			if ( '' !== $html ) {
				return $html;
			}
		}
		return '';
	}

	/**
	 * Small dependency-free Material-style SVG glyphs for the storefront.
	 *
	 * Keeping the paths inline avoids loading an external icon font/CDN and keeps
	 * the product form fast, private, cache-friendly and compatible with stealth
	 * mode. All glyphs are decorative; their nearby text remains the accessible
	 * label.
	 */
	private function material_icon( $name, $prefix, $extra_class = '' ) {
		$paths = array(
			'sports_esports' => '<path d="M21 6H3c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h4l2-2h6l2 2h4c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zM11 13H8v3H6v-3H3v-2h3V8h2v3h3v2zm5.5 2A1.5 1.5 0 1 1 16.5 12a1.5 1.5 0 0 1 0 3zm3-4A1.5 1.5 0 1 1 19.5 8a1.5 1.5 0 0 1 0 3z"/>',
			'person'         => '<path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>',
			'public'         => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm6.92 6h-2.95a15.6 15.6 0 0 0-1.38-3.56A8.06 8.06 0 0 1 18.92 8zM12 4c.83 1.2 1.47 2.53 1.86 4h-3.72A13.2 13.2 0 0 1 12 4zM4.26 14A7.6 7.6 0 0 1 4 12c0-.69.09-1.36.26-2h3.38a16.5 16.5 0 0 0-.14 2c0 .68.05 1.35.14 2H4.26zm.82 2h2.95a15.6 15.6 0 0 0 1.38 3.56A8.06 8.06 0 0 1 5.08 16zM8.03 8H5.08a8.06 8.06 0 0 1 4.33-3.56A15.6 15.6 0 0 0 8.03 8zM12 20a13.2 13.2 0 0 1-1.86-4h3.72A13.2 13.2 0 0 1 12 20zm2.35-6h-4.7a14.5 14.5 0 0 1-.15-2c0-.69.05-1.36.15-2h4.7c.1.64.15 1.31.15 2 0 .69-.05 1.36-.15 2zm.24 5.56A15.6 15.6 0 0 0 15.97 16h2.95a8.06 8.06 0 0 1-4.33 3.56zM16.36 14c.09-.65.14-1.32.14-2 0-.68-.05-1.35-.14-2h3.38c.17.64.26 1.31.26 2s-.09 1.36-.26 2h-3.38z"/>',
			'dns'            => '<path d="M4 2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm0 12h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2zm2-8h2v2H6V6zm0 12h2v2H6v-2zm4-12h8v2h-8V6zm0 12h8v2h-8v-2z"/>',
			'place'          => '<path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z"/>',
			'tune'           => '<path d="M3 17v2h6v-2H3zm0-12v2h10V5H3zm10 16v-2h8v-2h-8v-2h-2v6h2zM7 9v2H3v2h4v2h2V9H7zm14 4v-2H11v2h10zm-6-4h2V7h4V5h-4V3h-2v6z"/>',
			'verified_user'  => '<path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-1 16-4-4 1.41-1.41L11 14.17l4.59-4.58L17 11l-6 6z"/>',
			'arrow_forward'  => '<path d="m12 4-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8-8-8z"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			$name = 'tune';
		}
		$class = trim( $prefix . '-material-icon ' . $prefix . '-icon-' . sanitize_html_class( $name ) . ' ' . $extra_class );
		return '<span class="' . esc_attr( $class ) . '" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false">' . $paths[ $name ] . '</svg></span>';
	}

	private function field_icon_name( $name ) {
		$name = sanitize_key( $name );
		if ( self::is_player_identifier_key( $name ) ) {
			return 'person';
		}
		if ( false !== strpos( $name, 'region' ) || false !== strpos( $name, 'country' ) ) {
			return 'public';
		}
		if ( false !== strpos( $name, 'server' ) ) {
			return 'dns';
		}
		if ( false !== strpos( $name, 'zone' ) ) {
			return 'place';
		}
		return 'tune';
	}

	private function fields_html( $product_id ) {
		$schema = $this->effective_schema( $product_id );
		if ( ! $schema ) {
			return '';
		}

		$has_player_identifier = false;
		foreach ( $schema as $field ) {
			if ( ! empty( $field['field_name'] ) && self::is_player_identifier_key( $field['field_name'] ) ) {
				$has_player_identifier = true;
				break;
			}
		}

		$prefix = DST2T_Privacy::prefix();
		ob_start();
		echo '<div class="' . esc_attr( $prefix ) . '-field-list" data-' . esc_attr( $prefix ) . '-history-product="' . esc_attr( absint( $product_id ) ) . '">';
		echo '<div class="' . esc_attr( $prefix ) . '-fields-title">' . $this->material_icon( 'sports_esports', $prefix, $prefix . '-title-icon' ) . '<span>' . esc_html__( 'Informations du compte de jeu', 'delicat-shop2topup' ) . '</span></div>';

		// Keep payment-control details in the backend/order notes rather than a large
		// storefront notice. The compact UI stays focused on the customer task.
		if ( $has_player_identifier && $this->settings->enabled( 'validate_player' ) ) {
			echo '<input type="hidden" class="' . esc_attr( $prefix ) . '-verification-token" name="' . esc_attr( $prefix . '_verification_token' ) . '" value="" />';
		}

		foreach ( $schema as $field ) {
			$name        = $field['field_name'];
			$is_player   = self::is_player_identifier_key( $name );
			$label       = $this->field_label( $name );
			$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
			if ( $is_player && '' === trim( (string) $placeholder ) ) {
				$placeholder = __( 'Entrez votre UID / Player ID', 'delicat-shop2topup' );
			}
			$type        = isset( $field['data_type'] ) ? $field['data_type'] : 'text';
			$input_name  = DST2T_Privacy::field_key() . '[' . $name . ']';
			$classes     = $prefix . '-field ' . $prefix . '-field-' . $name . ( $is_player ? ' ' . $prefix . '-player-id-field' : '' );
			echo '<div class="form-row form-row-wide ' . esc_attr( $classes ) . '">';
			echo '<label>' . esc_html( $label ) . ' <span class="required" aria-hidden="true">*</span></label>';
			echo '<div class="' . esc_attr( $prefix ) . '-field-control">';
			echo $this->material_icon( $this->field_icon_name( $name ), $prefix, $prefix . '-field-leading-icon' );
			if ( in_array( $type, array( 'single_select', 'multi_select' ), true ) ) {
				$multiple = 'multi_select' === $type;
				echo '<select class="select" data-' . esc_attr( $prefix ) . '-field-key="' . esc_attr( $name ) . '" ' . ( $is_player ? 'data-' . esc_attr( $prefix ) . '-player-id="1" ' : '' ) . 'name="' . esc_attr( $input_name . ( $multiple ? '[]' : '' ) ) . '" ' . ( $multiple ? 'multiple ' : '' ) . 'required>';
				if ( ! $multiple ) {
					echo '<option value="">' . esc_html( $placeholder ?: __( 'Choisissez une option', 'delicat-shop2topup' ) ) . '</option>';
				}
				foreach ( (array) $field['select_options'] as $option ) {
					echo '<option value="' . esc_attr( $option ) . '">' . esc_html( DST2T_Brand::scrub( $option ) ) . '</option>';
				}
				echo '</select>';
			} else {
				$input_type = 'text';
				$extra      = $is_player ? ' data-' . esc_attr( $prefix ) . '-player-id="1" autocomplete="off" autocapitalize="none" spellcheck="false"' : '';
				echo '<input class="input-text" data-' . esc_attr( $prefix ) . '-field-key="' . esc_attr( $name ) . '"' . $extra . ' type="' . esc_attr( $input_type ) . '" inputmode="' . ( 'number' === $type ? 'numeric' : 'text' ) . '" name="' . esc_attr( $input_name ) . '" placeholder="' . esc_attr( $placeholder ) . '" maxlength="190" required />';
			}
			echo '</div>';
			if ( $is_player ) {
				echo '<small class="' . esc_attr( $prefix ) . '-field-help">' . esc_html__( "Entrez l’UID exactement comme dans le jeu. Le nom du joueur vérifié sera ajouté à la commande si disponible.", 'delicat-shop2topup' ) . '</small>';
			}
			echo '</div>';
		}
		if ( $has_player_identifier && $this->settings->enabled( 'validate_player' ) ) {
			echo '<div class="' . esc_attr( $prefix ) . '-verify-actions">';
			echo '<button type="button" class="button alt ' . esc_attr( $prefix ) . '-verify-button">' . $this->material_icon( 'verified_user', $prefix, $prefix . '-verify-button-icon' ) . '<span>' . esc_html__( 'Vérifier votre compte', 'delicat-shop2topup' ) . '</span>' . $this->material_icon( 'arrow_forward', $prefix, $prefix . '-verify-button-arrow' ) . '</button>';
			echo '<div class="' . esc_attr( $prefix ) . '-verify-status" aria-live="polite"></div>';
			echo '</div>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * @return bool True when this visitor has spent their validation allowance.
	 *
	 * Two buckets on purpose. A per-visitor bucket stops one customer looping, and
	 * a much larger per-address bucket stops a single source flooding — keyed on
	 * the address WooCommerce resolves, so a store behind Cloudflare or a load
	 * balancer does not put every one of its customers in one bucket.
	 */
	private function validation_throttled() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		$window  = 5 * MINUTE_IN_SECONDS;
		$buckets = array();

		$visitor = $this->visitor_id();
		if ( '' !== $visitor ) {
			$buckets[] = array( 'dst2t_pv_v_' . md5( $visitor ), self::VALIDATION_LIMIT, $window );
		}

		$ip = class_exists( 'WC_Geolocation' ) ? (string) WC_Geolocation::get_ip_address() : '';
		if ( '' === $ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		if ( '' !== $ip ) {
			$buckets[] = array( 'dst2t_pv_a_' . md5( $ip ), self::ADDRESS_LIMIT, $window );
		}

		// The address above comes from forwarding headers a client can forge, and a
		// visitor without a session cookie gets a new id on every request. This
		// site-wide ceiling is the one bucket a script cannot side-step, so it is
		// what protects the supplier API key's own rate limit (which paid orders
		// need after checkout). Normal shoppers stay far below it.
		$buckets[] = array( 'dst2t_pv_site', self::SITE_LIMIT, MINUTE_IN_SECONDS );

		foreach ( $buckets as $bucket ) {
			list( $key, $limit, $length ) = $bucket;
			// Fixed windows: v2.8 refreshed the expiry on every attempt, so a busy
			// shared address (mobile carrier NAT) never got its counter back.
			$state = get_transient( $key );
			$now   = time();
			if ( ! is_array( $state ) || ! isset( $state['n'], $state['t'] ) || (int) $state['t'] + $length <= $now ) {
				$state = array( 'n' => 0, 't' => $now );
			}
			if ( (int) $state['n'] >= $limit ) {
				return true;
			}
			$state['n'] = (int) $state['n'] + 1;
			set_transient( $key, $state, max( 1, (int) $state['t'] + $length - $now ) );
		}

		return false;
	}

	/**
	 * Stable-per-visitor identifier, only when the visitor really has one: without a
	 * session cookie WooCommerce invents a new customer id on every request, which
	 * used to create a fresh, useless rate-limit row per call.
	 */
	private function visitor_id() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			return 'u' . $user_id;
		}
		if ( function_exists( 'WC' ) && WC() && isset( WC()->session ) && is_object( WC()->session )
			&& method_exists( WC()->session, 'has_session' ) && WC()->session->has_session()
			&& method_exists( WC()->session, 'get_customer_id' ) ) {
			$id = (string) WC()->session->get_customer_id();
			if ( '' !== $id ) {
				return 's' . $id;
			}
		}
		return '';
	}

	private function posted_verification_token() {
		foreach ( array_unique( array( DST2T_Privacy::prefix() . '_verification_token', 'dst2t_verification_token', 'topup_verification_token' ) ) as $key ) {
			if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}
		return '';
	}

	private function verification_secret() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|delicat-topup-player-verification' );
	}

	private function make_verification_token( $product_id, $requirements, $player ) {
		$mapping = $this->mapping( $product_id );
		$payload = array(
			'v'                 => 3,
			'product_id'        => absint( $product_id ),
			'item_id'           => absint( $mapping['item_id'] ),
			'requirements_hash' => hash( 'sha256', wp_json_encode( $this->sort_recursive( (array) $requirements ) ) ),
			'player'            => array(),
			'iat'               => time(),
		);
		foreach ( array( 'player_name', 'player_id', 'region', 'server', 'zone_id' ) as $key ) {
			if ( isset( $player[ $key ] ) && is_scalar( $player[ $key ] ) ) {
				$payload['player'][ $key ] = sanitize_text_field( (string) $player[ $key ] );
			}
		}
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || '' === $json ) { return ''; }
		$encoded = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, $this->verification_secret() );
	}

	private function player_from_verification_token( $token, $product_id, $requirements ) {
		$parts = explode( '.', (string) $token, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) { return array(); }
		$expected = hash_hmac( 'sha256', $parts[0], $this->verification_secret() );
		if ( ! hash_equals( $expected, $parts[1] ) ) { return array(); }
		$encoded = strtr( $parts[0], '-_', '+/' );
		$padding = strlen( $encoded ) % 4;
		if ( $padding ) { $encoded .= str_repeat( '=', 4 - $padding ); }
		$decoded = base64_decode( $encoded, true );
		$payload = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $payload ) || 3 !== absint( $payload['v'] ?? 0 ) ) { return array(); }
		if ( absint( $payload['product_id'] ?? 0 ) !== absint( $product_id ) ) { return array(); }
		$mapping = $this->mapping( $product_id );
		if ( absint( $payload['item_id'] ?? 0 ) !== absint( $mapping['item_id'] ) ) { return array(); }
		if ( empty( $payload['iat'] ) || absint( $payload['iat'] ) < time() - 15 * MINUTE_IN_SECONDS || absint( $payload['iat'] ) > time() + 60 ) { return array(); }
		$hash = hash( 'sha256', wp_json_encode( $this->sort_recursive( (array) $requirements ) ) );
		if ( empty( $payload['requirements_hash'] ) || ! hash_equals( (string) $payload['requirements_hash'], $hash ) ) { return array(); }
		$player = isset( $payload['player'] ) && is_array( $payload['player'] ) ? $payload['player'] : array();
		return ! empty( $player['player_name'] ) ? $player : array();
	}

	private function posted_requirements( $product_id, $allow_remote_refresh = true ) {
		$schema = $this->schema( $product_id );
		// Only fetch when the dynamic schema has never been stored. A category that
		// genuinely has no additional fields stores an empty array; the fixed Player
		// ID field for direct top-ups is added locally by effective_schema().
		if ( $allow_remote_refresh && ! $schema && '' === (string) get_post_meta( absint( $product_id ), self::META_REQUIREMENTS, true ) && $this->settings->credentials_configured() ) {
			$this->refresh_mapping( $product_id, false );
		}
		$schema = $this->effective_schema( $product_id );
		if ( ! $schema ) {
			return array();
		}

		$field_key = DST2T_Privacy::field_key();
		$posted    = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( array_unique( array( $field_key, 'dst2t_req', 'topup_req' ) ) as $candidate ) {
			if ( isset( $_POST[ $candidate ] ) && is_array( $_POST[ $candidate ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$posted = (array) wp_unslash( $_POST[ $candidate ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				break;
			}
		}
		$out    = array();
		foreach ( $schema as $field ) {
			$name    = $field['field_name'];
			$type    = $field['data_type'];
			$value   = isset( $posted[ $name ] ) ? $posted[ $name ] : '';
			$options = isset( $field['select_options'] ) ? (array) $field['select_options'] : array();

			if ( 'multi_select' === $type ) {
				$value = array_values( array_filter( array_map( 'sanitize_text_field', (array) $value ), 'strlen' ) );
				if ( ! $value || array_diff( $value, $options ) ) {
					throw new InvalidArgumentException( sprintf( __( 'Please choose a valid value for %s.', 'delicat-shop2topup' ), $this->field_label( $name ) ) );
				}
			} else {
				$value = sanitize_text_field( is_array( $value ) ? '' : $value );
				if ( '' === $value ) {
					throw new InvalidArgumentException( sprintf( __( '%s is required.', 'delicat-shop2topup' ), $this->field_label( $name ) ) );
				}
				if ( 'single_select' === $type && ! in_array( $value, $options, true ) ) {
					throw new InvalidArgumentException( sprintf( __( 'Please choose a valid value for %s.', 'delicat-shop2topup' ), $this->field_label( $name ) ) );
				}
				if ( 'number' === $type && ! preg_match( '/^[0-9]+$/', $value ) ) {
					throw new InvalidArgumentException( sprintf( __( '%s must contain numbers only.', 'delicat-shop2topup' ), $this->field_label( $name ) ) );
				}
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	private function sanitize_schema( $schema ) {
		$out = array();
		foreach ( (array) $schema as $field ) {
			if ( ! is_array( $field ) || empty( $field['field_name'] ) ) {
				continue;
			}
			$name = sanitize_key( $field['field_name'] );
			if ( ! $name ) {
				continue;
			}
			$type = isset( $field['data_type'] ) ? sanitize_key( $field['data_type'] ) : 'text';
			if ( ! in_array( $type, array( 'text', 'number', 'single_select', 'multi_select' ), true ) ) {
				$type = 'text';
			}
			$out[] = array(
				'field_name'     => $name,
				'data_type'      => $type,
				// Placeholders are display-only provider copy, so they are scrubbed.
				// Option values are not: they are sent back upstream verbatim.
				'placeholder'    => isset( $field['placeholder'] ) ? DST2T_Brand::scrub( sanitize_text_field( $field['placeholder'] ) ) : '',
				'select_options' => isset( $field['select_options'] ) ? array_values( array_map( 'sanitize_text_field', (array) $field['select_options'] ) ) : array(),
			);
		}
		return $out;
	}

	private function schema_labels( $schema ) {
		$labels = array();
		foreach ( (array) $schema as $field ) {
			if ( ! empty( $field['field_name'] ) ) {
				$labels[ $field['field_name'] ] = $this->field_label( $field['field_name'] );
			}
		}
		return $labels;
	}

	private function field_label( $name ) {
		$name = sanitize_key( $name );
		if ( self::is_player_identifier_key( $name ) ) {
			return __( 'UID / ID Joueur', 'delicat-shop2topup' );
		}
		$known = array(
			'zone_id'      => __( 'ID de zone', 'delicat-shop2topup' ),
			'server'       => __( 'Serveur', 'delicat-shop2topup' ),
			'region'       => __( 'Région', 'delicat-shop2topup' ),
			'genshin_zone' => __( 'Serveur Genshin', 'delicat-shop2topup' ),
		);
		return isset( $known[ $name ] ) ? $known[ $name ] : ucwords( str_replace( '_', ' ', $name ) );
	}

	private function sanitize_cost( $value ) {
		if ( '' === trim( (string) $value ) ) {
			return '';
		}
		try {
			return DST2T_Decimal::normalize( str_replace( ',', '.', trim( (string) $value ) ) );
		} catch ( Throwable $error ) {
			return '';
		}
	}

	private function customer_error( DST2T_API_Exception $error ) {
		if ( 'REGION_MISMATCH' === $error->get_api_code() ) {
			// 2.7.4: name only the server the ACCOUNT is on. The raw details also carry the
			// requested product's region/server, which the message used to name instead.
			$details = (array) $error->get_details();
			$region  = is_callable( array( 'Delicat_Player_Region_V2', 'error_region' ) )
				? Delicat_Player_Region_V2::error_region( $details )
				: ( isset( $details['account_region'] ) && is_scalar( $details['account_region'] ) ? (string) $details['account_region'] : '' );
			return Delicat_Player_Region_V2::message( array( 'account_region' => $region ) );
		}
		$messages = array(
			'PLAYER_CHECK_UNAVAILABLE' => __( "La vérification du compte est temporairement indisponible. Votre UID / ID Joueur n’a pas été rejeté. Veuillez réessayer dans un instant.", 'delicat-shop2topup' ),
			'PLAYER_ID_MISMATCH' => __( 'Le service a renvoyé un autre compte joueur. Réessayez ou contactez le support.', 'delicat-shop2topup' ),
			'PLAYER_NOT_FOUND'         => __( "Joueur introuvable. Vérifiez l’UID / ID Joueur et l’ID de zone.", 'delicat-shop2topup' ),
			'PLAYER_VALIDATION_FAILED'  => __( "Le serveur du jeu n’a pas pu valider ce compte. Veuillez réessayer.", 'delicat-shop2topup' ),
			'REGION_MISMATCH'           => __( Delicat_Player_Region_V2::MESSAGE, 'delicat-shop2topup' ),
			'INVALID_PRODUCT_CONFIG'    => __( 'Les informations du compte ne correspondent pas à ce produit.', 'delicat-shop2topup' ),
			'RATE_LIMIT_EXCEEDED'       => __( 'La vérification est temporairement occupée. Veuillez patienter puis réessayer.', 'delicat-shop2topup' ),
			'SERVICE_UNAVAILABLE'       => __( 'Le service de recharge est temporairement indisponible. Veuillez réessayer bientôt.', 'delicat-shop2topup' ),
			'MISSING_PLAYER_ID'         => __( "L’UID / ID Joueur est requis.", 'delicat-shop2topup' ),
		);
		return isset( $messages[ $error->get_api_code() ] ) ? $messages[ $error->get_api_code() ] : __( "Nous n’avons pas pu valider ces informations de jeu. Vérifiez-les puis réessayez.", 'delicat-shop2topup' );
	}
}
