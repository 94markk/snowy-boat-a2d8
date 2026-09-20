<?php

defined( 'ABSPATH' ) || exit;

final class DST2T_Product {
	const META_ENABLED      = '_dst2t_enabled';
	const META_ITEM_ID      = '_dst2t_item_id';
	const META_CATEGORY_ID  = '_dst2t_category_id';
	const META_PROVIDER_NAME = '_dst2t_provider_name';
	const META_REQUIREMENTS = '_dst2t_requirements_schema';
	const META_LAST_COST    = '_dst2t_last_cost_usd';
	const META_MAX_COST     = '_dst2t_max_cost_usd';
	const META_VOUCHER      = '_dst2t_returns_voucher';
	const META_SYNC_ERROR   = '_dst2t_sync_error';

	/** Player validations allowed per visitor per five minutes. */
	const VALIDATION_LIMIT  = 20;

	/** Player validations allowed per client address per five minutes. */
	const ADDRESS_LIMIT     = 300;
	const META_LAST_SYNC    = '_dst2t_last_sync';

	/** @var DST2T_API_Client */
	private $api;

	/** @var DST2T_Settings */
	private $settings;

	/** @var array */
	private $validated = array();

	/** @var array Per-request requirement-schema cache keyed by supplier category. */
	private $requirements_cache = array();

	public function __construct( DST2T_API_Client $api, DST2T_Settings $settings ) {
		$this->api      = $api;
		$this->settings = $settings;
	}

	public function hooks() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'product_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
		add_filter( 'woocommerce_available_variation', array( $this, 'variation_data' ), 10, 3 );

		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_customer_fields' ), 12 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 4 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'create_order_line_item' ), 10, 4 );

		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
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
			'requirements'   => $this->schema( $product_id ),
			'last_cost'      => (string) get_post_meta( $product_id, self::META_LAST_COST, true ),
			'max_cost'       => (string) get_post_meta( $product_id, self::META_MAX_COST, true ),
			'returns_voucher' => 'yes' === get_post_meta( $product_id, self::META_VOUCHER, true ),
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
						'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
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
						'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
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
				<input type="number" min="1" step="1" name="<?php echo esc_attr( $prefix . '[item_id]' ); ?>" value="<?php echo esc_attr( get_post_meta( $id, self::META_ITEM_ID, true ) ); ?>" />
			</p>
			<p class="form-row form-row-last">
				<label><?php esc_html_e( 'Supplier category ID', 'delicat-shop2topup' ); ?></label>
				<input type="number" min="1" step="1" name="<?php echo esc_attr( $prefix . '[category_id]' ); ?>" value="<?php echo esc_attr( get_post_meta( $id, self::META_CATEGORY_ID, true ) ); ?>" />
			</p>
			<p class="form-row form-row-first">
				<label><?php esc_html_e( 'Absolute max unit cost (USD)', 'delicat-shop2topup' ); ?></label>
				<input type="text" inputmode="decimal" name="<?php echo esc_attr( $prefix . '[max_cost]' ); ?>" value="<?php echo esc_attr( get_post_meta( $id, self::META_MAX_COST, true ) ); ?>" />
			</p>
			<p class="form-row form-row-last">
				<label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[returns_voucher]' ); ?>" value="yes" <?php checked( 'yes', get_post_meta( $id, self::META_VOUCHER, true ) ); ?> /> <?php esc_html_e( 'Voucher-code product', 'delicat-shop2topup' ); ?></label>
			</p>
		</div>
		<?php
	}

	public function save_product( $product_id ) {
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}
		$enabled     = isset( $_POST[ self::META_ENABLED ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$item_id     = isset( $_POST[ self::META_ITEM_ID ] ) ? absint( $_POST[ self::META_ITEM_ID ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$category_id = isset( $_POST[ self::META_CATEGORY_ID ] ) ? absint( $_POST[ self::META_CATEGORY_ID ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$max_cost    = isset( $_POST[ self::META_MAX_COST ] ) ? $this->sanitize_cost( wp_unslash( $_POST[ self::META_MAX_COST ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$voucher     = isset( $_POST[ self::META_VOUCHER ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
			try {
				// Requirements and cost only: mirroring stock here would overwrite the
				// "Manage stock?" choice the shop manager just made in the same save.
				$this->refresh_mapping( $product_id, false );
			} catch ( Throwable $error ) {
				// Product saving must never fail because the supplier is unavailable.
			}
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
		$mapping = $this->mapping( $product_id );
		if ( ! $mapping['item_id'] || ! $mapping['category_id'] ) {
			throw new InvalidArgumentException( 'The product mapping is incomplete.' );
		}
		$schema = $this->requirements_for( $mapping['category_id'] );
		$price  = $this->api->price( $mapping['item_id'] );
		update_post_meta( $product_id, self::META_REQUIREMENTS, wp_json_encode( $this->sanitize_schema( $schema ) ) );
		$unit_cost = DST2T_Decimal::normalize( $price['unit_price'] );
		if ( 0 === DST2T_Decimal::compare( $unit_cost, '0' ) ) {
			// A zero unit price is a missing price, not a free item. Storing it would
			// set the relative cost guard to zero and block the product entirely.
			update_post_meta( $product_id, self::META_SYNC_ERROR, 'ZERO_COST' );
		} else {
			update_post_meta( $product_id, self::META_LAST_COST, $unit_cost );
		}
		update_post_meta( $product_id, self::META_LAST_SYNC, time() );
		if ( ! empty( $price['item_name'] ) ) {
			update_post_meta( $product_id, self::META_PROVIDER_NAME, sanitize_text_field( DST2T_Brand::scrub( $price['item_name'] ) ) );
		}

		$product = wc_get_product( $product_id );
		if ( $product ) {
			$dirty = false;
			if ( $this->settings->enabled( 'sync_catalog_prices' ) && 0 !== DST2T_Decimal::compare( $unit_cost, '0' ) ) {
				$selling = $this->selling_price( $price['unit_price'] );
				if ( '' !== $selling && $selling !== (string) $product->get_regular_price() ) {
					$product->set_regular_price( $selling );
					if ( '' === (string) $product->get_sale_price() ) {
						$product->set_price( $selling );
					}
					$dirty = true;
				}
			}
			if ( $with_stock && $this->settings->enabled( 'sync_stock' ) && $this->apply_stock( $product, $price ) ) {
				$dirty = true;
			}
			if ( $dirty ) {
				$product->save();
			}
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
	 * Mirrors supplier availability onto the WooCommerce product.
	 *
	 * An absent stock field is never treated as zero stock: the public catalog does
	 * not always carry one, and guessing would take sellable products offline.
	 *
	 * @return bool True when the product was changed.
	 */
	private function apply_stock( $product, $price ) {
		$quantity = null;
		foreach ( array( 'stock', 'quantity', 'stock_quantity', 'available_quantity', 'remaining' ) as $key ) {
			if ( isset( $price[ $key ] ) && is_numeric( $price[ $key ] ) ) {
				$quantity = max( 0, (int) $price[ $key ] );
				break;
			}
		}

		$in_stock = null;
		foreach ( array( 'in_stock', 'available', 'is_available', 'stock_status', 'availability' ) as $key ) {
			if ( ! array_key_exists( $key, $price ) ) {
				continue;
			}
			$value = $price[ $key ];
			if ( is_bool( $value ) ) {
				$in_stock = $value;
				break;
			}
			if ( is_string( $value ) ) {
				$normal = strtolower( trim( $value ) );
				if ( in_array( $normal, array( 'instock', 'in_stock', 'available', 'yes', 'true', '1' ), true ) ) {
					$in_stock = true;
					break;
				}
				if ( in_array( $normal, array( 'outofstock', 'out_of_stock', 'unavailable', 'no', 'false', '0' ), true ) ) {
					$in_stock = false;
					break;
				}
			}
		}

		if ( null === $quantity && null === $in_stock ) {
			return false;
		}
		if ( null === $in_stock ) {
			$in_stock = $quantity > 0;
		}

		$changed = false;
		if ( null !== $quantity ) {
			if ( ! $product->get_manage_stock() ) {
				$product->set_manage_stock( true );
				$changed = true;
			}
			if ( (int) $product->get_stock_quantity() !== $quantity ) {
				$product->set_stock_quantity( $quantity );
				$changed = true;
			}
		}

		$status = $in_stock ? 'instock' : 'outofstock';
		if ( $product->get_stock_status() !== $status ) {
			$product->set_stock_status( $status );
			$changed = true;
		}

		return $changed;
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
			echo '<div class="' . esc_attr( $prefix ) . '-requirements" data-' . esc_attr( $prefix ) . '-variable="1"></div>';
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

		try {
			if ( ! $this->settings->credentials_configured() ) {
				throw new DST2T_API_Exception( 'Supplier connection is unavailable.', 'SERVICE_UNAVAILABLE' );
			}
			$requirements = $this->posted_requirements( $mapped_id );
			$player       = array();
			if ( $this->settings->enabled( 'validate_player' ) && isset( $requirements['player_id'] ) ) {
				// Add-to-cart is unauthenticated, so one visitor must not be able to
				// drive an unbounded number of upstream validation calls.
				if ( $this->validation_throttled() ) {
					throw new DST2T_API_Exception( 'Too many validation attempts from this visitor.', 'RATE_LIMIT_EXCEEDED' );
				}
				$mapping = $this->mapping( $mapped_id );
				$player  = $this->api->validate_player( $mapping['item_id'], $requirements );
			}
			$this->validated[ $mapped_id ] = array( 'requirements' => $requirements, 'player' => $player );
		} catch ( DST2T_API_Exception $error ) {
			wc_add_notice( $this->customer_error( $error ), 'error' );
			return false;
		} catch ( InvalidArgumentException $error ) {
			wc_add_notice( $error->getMessage(), 'error' );
			return false;
		} catch ( Throwable $error ) {
			wc_add_notice( __( 'We could not validate these game details. Please try again.', 'delicat-shop2topup' ), 'error' );
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
				$this->validated[ $mapped_id ] = array( 'requirements' => $this->posted_requirements( $mapped_id ), 'player' => array() );
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
			'returns_voucher' => $mapping['returns_voucher'],
			'labels'       => $this->schema_labels( $mapping['requirements'] ),
		);
		$cart_item_data['dst2t_key'] = hash( 'sha256', wp_json_encode( $cart_item_data['dst2t'] ) );
		return $cart_item_data;
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
			$display[] = array( 'key' => __( 'Player name', 'delicat-shop2topup' ), 'value' => sanitize_text_field( $cart_item['dst2t']['player']['player_name'] ) );
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
		}

		$labels = isset( $data['labels'] ) ? (array) $data['labels'] : array();
		foreach ( (array) $data['requirements'] as $key => $value ) {
			$label = isset( $labels[ $key ] ) ? $labels[ $key ] : $this->field_label( $key );
			$value = is_array( $value ) ? implode( ', ', $value ) : $value;
			$item->add_meta_data( $label, sanitize_text_field( $value ), false );
		}
		if ( ! empty( $data['player']['player_name'] ) ) {
			$item->add_meta_data( __( 'Player name', 'delicat-shop2topup' ), sanitize_text_field( $data['player']['player_name'] ), false );
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
		if ( ! $product->is_type( 'variable' ) && ! $this->is_mapped( $product->get_id() ) ) {
			return;
		}
		// Inlined while stealth mode is on so no plugin path reaches the page source.
		DST2T_Privacy::enqueue_frontend();
	}

	public function schema( $product_id ) {
		$raw    = get_post_meta( absint( $product_id ), self::META_REQUIREMENTS, true );
		$schema = json_decode( (string) $raw, true );
		return is_array( $schema ) ? $this->sanitize_schema( $schema ) : array();
	}

	private function fields_html( $product_id ) {
		$schema = $this->schema( $product_id );
		if ( ! $schema ) {
			return '';
		}

		$prefix = DST2T_Privacy::prefix();
		ob_start();
		echo '<div class="' . esc_attr( $prefix ) . '-fields-title">' . esc_html__( 'Game account details', 'delicat-shop2topup' ) . '</div>';
		foreach ( $schema as $field ) {
			$name        = $field['field_name'];
			$label       = $this->field_label( $name );
			$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
			$type        = isset( $field['data_type'] ) ? $field['data_type'] : 'text';
			$input_name  = DST2T_Privacy::field_key() . '[' . $name . ']';
			echo '<p class="form-row form-row-wide ' . esc_attr( $prefix ) . '-field ' . esc_attr( $prefix . '-field-' . $name ) . '">';
			echo '<label>' . esc_html( $label ) . ' <span class="required" aria-hidden="true">*</span></label>';
			if ( in_array( $type, array( 'single_select', 'multi_select' ), true ) ) {
				$multiple = 'multi_select' === $type;
				echo '<select class="select" name="' . esc_attr( $input_name . ( $multiple ? '[]' : '' ) ) . '" ' . ( $multiple ? 'multiple ' : '' ) . 'required>';
				if ( ! $multiple ) {
					echo '<option value="">' . esc_html( $placeholder ?: __( 'Select an option', 'delicat-shop2topup' ) ) . '</option>';
				}
				foreach ( (array) $field['select_options'] as $option ) {
					// The value is submitted back upstream and must stay byte-identical;
					// only the text the customer reads is scrubbed.
					echo '<option value="' . esc_attr( $option ) . '">' . esc_html( DST2T_Brand::scrub( $option ) ) . '</option>';
				}
				echo '</select>';
			} else {
				$input_type = 'number' === $type ? 'text' : 'text';
				echo '<input class="input-text" type="' . esc_attr( $input_type ) . '" inputmode="' . ( 'number' === $type ? 'numeric' : 'text' ) . '" name="' . esc_attr( $input_name ) . '" placeholder="' . esc_attr( $placeholder ) . '" maxlength="190" required />';
			}
			echo '</p>';
		}
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

		$buckets = array();

		$visitor = $this->visitor_id();
		if ( '' !== $visitor ) {
			$buckets[] = array( 'dst2t_pv_v_' . md5( $visitor ), self::VALIDATION_LIMIT );
		}

		$ip = class_exists( 'WC_Geolocation' ) ? (string) WC_Geolocation::get_ip_address() : '';
		if ( '' === $ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		if ( '' !== $ip ) {
			$buckets[] = array( 'dst2t_pv_a_' . md5( $ip ), self::ADDRESS_LIMIT );
		}

		foreach ( $buckets as $bucket ) {
			list( $key, $limit ) = $bucket;
			$count               = (int) get_transient( $key );
			if ( $count >= $limit ) {
				return true;
			}
			set_transient( $key, $count + 1, 5 * MINUTE_IN_SECONDS );
		}

		return false;
	}

	/** Stable-per-visitor identifier from the WooCommerce session, when there is one. */
	private function visitor_id() {
		if ( function_exists( 'WC' ) && WC() && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'get_customer_id' ) ) {
			$id = (string) WC()->session->get_customer_id();
			if ( '' !== $id ) {
				return $id;
			}
		}
		return (string) get_current_user_id();
	}

	private function posted_requirements( $product_id ) {
		$schema = $this->schema( $product_id );
		// Only fetch when the schema has never been stored. A category that genuinely
		// has no requirement fields stores an empty array, and re-fetching that on
		// every add-to-cart would be an unauthenticated request amplifier.
		if ( ! $schema && '' === (string) get_post_meta( absint( $product_id ), self::META_REQUIREMENTS, true ) && $this->settings->credentials_configured() ) {
			$this->refresh_mapping( $product_id, false );
			$schema = $this->schema( $product_id );
		}
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
		$known = array(
			'player_id'    => __( 'Player ID', 'delicat-shop2topup' ),
			'zone_id'      => __( 'Zone ID', 'delicat-shop2topup' ),
			'server'       => __( 'Server', 'delicat-shop2topup' ),
			'region'       => __( 'Region', 'delicat-shop2topup' ),
			'genshin_zone' => __( 'Genshin server', 'delicat-shop2topup' ),
		);
		return isset( $known[ $name ] ) ? $known[ $name ] : ucwords( str_replace( '_', ' ', sanitize_key( $name ) ) );
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
		$messages = array(
			'PLAYER_CHECK_UNAVAILABLE' => __( 'Player verification is temporarily unavailable. Your ID has not been rejected. Please try again shortly.', 'delicat-shop2topup' ),
			'PLAYER_NOT_FOUND'         => __( 'Player not found. Check the player ID and zone.', 'delicat-shop2topup' ),
			'PLAYER_VALIDATION_FAILED'  => __( 'The game server could not validate this player. Please try again.', 'delicat-shop2topup' ),
			'REGION_MISMATCH'           => __( 'This player is registered in a different region.', 'delicat-shop2topup' ),
			'INVALID_PRODUCT_CONFIG'    => __( 'The player details do not match this product.', 'delicat-shop2topup' ),
			'RATE_LIMIT_EXCEEDED'       => __( 'Validation is temporarily busy. Please wait a moment and try again.', 'delicat-shop2topup' ),
			'SERVICE_UNAVAILABLE'       => __( 'The top-up service is temporarily unavailable. Please try again shortly.', 'delicat-shop2topup' ),
		);
		return isset( $messages[ $error->get_api_code() ] ) ? $messages[ $error->get_api_code() ] : __( 'We could not validate these game details. Please check them and try again.', 'delicat-shop2topup' );
	}
}
