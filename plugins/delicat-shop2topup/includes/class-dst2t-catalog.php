<?php

defined( 'ABSPATH' ) || exit;

/**
 * Resilient catalog importer.
 *
 * Imports are resumable and can advance from Action Scheduler/WP-Cron or directly
 * from the authenticated admin browser. The browser-driven step prevents hosts
 * with disabled loopback cron from leaving imports permanently queued.
 */
final class DST2T_Catalog {
	const ACTION_PROCESS  = 'dst2t_catalog_import_process';
	const ACTION_GROUP    = 'delicat-shop2topup';
	const JOB_PREFIX      = 'dst2t_catalog_job_';
	const USER_JOB_PREFIX = 'dst2t_catalog_last_job_';
	const BATCH_SIZE      = 6;
	const MAX_ITEMS       = 5000;

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
		add_action( self::ACTION_PROCESS, array( $this, 'process_job' ), 10, 1 );
		add_action( 'wp_ajax_dst2t_import_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_dst2t_import_step', array( $this, 'ajax_step' ) );
	}

	/** Create an import job for one supplier category. */
	public function start_import( $category_id, $requested, $all, $category_name, $user_id, $selling_prices = array() ) {
		$category_id = absint( $category_id );
		$user_id     = absint( $user_id );
		if ( ! $category_id || ! $this->settings->credentials_configured() ) {
			return new WP_Error( 'dst2t_import_not_ready', __( 'The catalog connection is not ready.', 'delicat-shop2topup' ) );
		}

		try {
			$items = $this->api->subcategories( $category_id );
		} catch ( DST2T_API_Exception $error ) {
			return new WP_Error( 'dst2t_import_api', sprintf( __( 'Catalog request failed: %s.', 'delicat-shop2topup' ), $error->get_api_code() ) );
		}

		$available = array();
		$categories = array();
		$category_names = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['item_id'] ) ) {
				continue;
			}
			$item_id = absint( $item['item_id'] );
			$available[ $item_id ] = true;
			$categories[ $item_id ] = $this->item_category_id( $item, $category_id );
			$category_names[ $item_id ] = $this->item_category_name( $item, $category_name );
		}

		if ( ! $available ) {
			return new WP_Error( 'dst2t_import_empty', __( 'This supplier category does not currently contain importable products.', 'delicat-shop2topup' ) );
		}

		if ( $all ) {
			$item_ids = array_keys( $available );
		} else {
			$item_ids = array();
			foreach ( array_unique( array_map( 'absint', (array) $requested ) ) as $item_id ) {
				if ( $item_id && isset( $available[ $item_id ] ) ) {
					$item_ids[] = $item_id;
				}
			}
		}

		return $this->create_job( 'category', $category_id, $category_name, $item_ids, $categories, $category_names, $user_id, $selling_prices );
	}

	/** Create an import job for the complete supplier catalog. */
	public function start_full_import( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $this->settings->credentials_configured() ) {
			return new WP_Error( 'dst2t_import_not_ready', __( 'The catalog connection is not ready.', 'delicat-shop2topup' ) );
		}

		try {
			$items = $this->api->subcategories( 0 );
		} catch ( DST2T_API_Exception $error ) {
			return new WP_Error( 'dst2t_import_api', sprintf( __( 'Catalog request failed: %s.', 'delicat-shop2topup' ), $error->get_api_code() ) );
		}

		$item_ids = array();
		$categories = array();
		$category_names = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['item_id'] ) ) {
				continue;
			}
			$item_id = absint( $item['item_id'] );
			$item_ids[] = $item_id;
			$categories[ $item_id ] = $this->item_category_id( $item, 0 );
			$category_names[ $item_id ] = $this->item_category_name( $item, '' );
		}

		if ( ! $item_ids ) {
			return new WP_Error( 'dst2t_import_empty', __( 'The supplier returned zero importable catalog products.', 'delicat-shop2topup' ) );
		}

		return $this->create_job( 'full', 0, '', $item_ids, $categories, $category_names, $user_id, array() );
	}

	private function create_job( $mode, $category_id, $category_name, $item_ids, $categories, $category_names, $user_id, $selling_prices = array() ) {
		$item_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $item_ids ) ) ) );
		$item_ids = array_slice( $item_ids, 0, self::MAX_ITEMS );
		if ( ! $item_ids ) {
			return new WP_Error( 'dst2t_import_none', __( 'Choose at least one catalog item to import.', 'delicat-shop2topup' ) );
		}

		$category_map = array();
		$name_map = array();
		foreach ( $item_ids as $item_id ) {
			$category_map[ $item_id ] = isset( $categories[ $item_id ] ) ? absint( $categories[ $item_id ] ) : absint( $category_id );
			$name_map[ $item_id ] = isset( $category_names[ $item_id ] ) ? substr( sanitize_text_field( (string) $category_names[ $item_id ] ), 0, 190 ) : substr( sanitize_text_field( (string) $category_name ), 0, 190 );
		}

		$price_map = array();
		foreach ( (array) $selling_prices as $raw_item_id => $raw_price ) {
			$price_item_id = absint( $raw_item_id );
			if ( ! $price_item_id || ! in_array( $price_item_id, $item_ids, true ) ) {
				continue;
			}
			$price = $this->sanitize_selling_price( $raw_price );
			if ( '' !== $price ) {
				$price_map[ $price_item_id ] = $price;
			}
		}

		$structure = (string) $this->settings->get( 'import_structure', 'variable' );
		$structure = in_array( $structure, array( 'variable', 'simple' ), true ) ? $structure : 'variable';
		$pricing_mode = (string) $this->settings->get( 'catalog_pricing_mode', 'manual' );
		$pricing_mode = 'automatic' === $pricing_mode ? 'automatic' : 'manual';

		$job_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( microtime( true ) . '|' . wp_rand() );
		$state  = array(
			'id'                  => $job_id,
			'user_id'             => absint( $user_id ),
			'mode'                => 'full' === $mode ? 'full' : 'category',
			'category_id'         => absint( $category_id ),
			'category_name'       => substr( sanitize_text_field( (string) $category_name ), 0, 190 ),
			'item_ids'            => $item_ids,
			'item_categories'     => $category_map,
			'item_category_names' => $name_map,
			'selling_prices'       => $price_map,
			'import_structure'     => $structure,
			'pricing_mode'         => $pricing_mode,
			'cursor'              => 0,
			'total'               => count( $item_ids ),
			'processed'           => 0,
			'created'             => 0,
			'updated'             => 0,
			'failed'              => 0,
			'errors'              => array(),
			'status'              => 'queued',
			'started_at'          => time(),
			'updated_at'          => time(),
		);

		update_option( $this->job_key( $job_id ), $state, false );
		update_option( self::USER_JOB_PREFIX . absint( $user_id ), $job_id, false );
		$this->schedule( $job_id, 1 );
		return $state;
	}

	/** Process one safe batch. */
	public function process_job( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );
		$state  = $this->load_state( $job_id );
		if ( ! $state || in_array( $state['status'], array( 'complete', 'complete_with_errors', 'failed' ), true ) ) {
			return;
		}

		$lock_key = 'dst2t_catalog_lock_' . md5( $job_id );
		$now      = time();
		$lock     = (int) get_option( $lock_key, 0 );
		if ( $lock && $lock > $now - 120 ) {
			return;
		}
		if ( $lock ) {
			delete_option( $lock_key );
		}
		if ( ! add_option( $lock_key, $now, '', false ) ) {
			return;
		}

		try {
			$state['status']     = 'running';
			$state['updated_at'] = time();
			$this->save_state( $state );

			try {
				$items = $this->api->subcategories( 'full' === $state['mode'] ? 0 : $state['category_id'] );
			} catch ( DST2T_API_Exception $error ) {
				$this->fail_job( $state, $error->get_api_code() );
				return;
			}

			$map = array();
			foreach ( (array) $items as $item ) {
				if ( is_array( $item ) && ! empty( $item['item_id'] ) ) {
					$map[ absint( $item['item_id'] ) ] = $item;
				}
			}

			$end_ids = array_slice( $state['item_ids'], (int) $state['cursor'], self::BATCH_SIZE );
			if ( ! $end_ids ) {
				$this->finish_job( $state );
				return;
			}

			// Requirements are category-level. Fetch each category once per batch and do
			// not let NO_REQUIREMENTS_FOUND prevent the WooCommerce draft from importing.
			$schemas = array();
			foreach ( $end_ids as $item_id ) {
				$item_id = absint( $item_id );
				if ( ! isset( $map[ $item_id ] ) || $this->is_voucher( $map[ $item_id ] ) ) {
					continue;
				}
				$category_id = $this->item_category_id( $map[ $item_id ], isset( $state['item_categories'][ $item_id ] ) ? $state['item_categories'][ $item_id ] : $state['category_id'] );
				if ( ! $category_id || array_key_exists( $category_id, $schemas ) ) {
					continue;
				}
				try {
					$schemas[ $category_id ] = $this->api->requirements( $category_id );
				} catch ( DST2T_API_Exception $error ) {
					$code = $error->get_api_code();
					if ( 'NO_REQUIREMENTS_FOUND' === $code || 'HTTP_404' === $code ) {
						$schemas[ $category_id ] = array();
					} elseif ( $this->fatal_catalog_error( $code ) ) {
						$this->fail_job( $state, $code );
						return;
					} else {
						// Keep the product as a draft and let a later sync refresh the schema.
						$schemas[ $category_id ] = array();
					}
				}
			}

			foreach ( $end_ids as $item_id ) {
				$item_id = absint( $item_id );
				++$state['processed'];
				++$state['cursor'];

				if ( ! isset( $map[ $item_id ] ) ) {
					++$state['failed'];
					$this->record_error( $state, $item_id, 'ITEM_NOT_FOUND' );
					continue;
				}

				$item = $map[ $item_id ];
				$category_id = $this->item_category_id( $item, isset( $state['item_categories'][ $item_id ] ) ? $state['item_categories'][ $item_id ] : $state['category_id'] );
				$category_name = $this->item_category_name( $item, isset( $state['item_category_names'][ $item_id ] ) ? $state['item_category_names'][ $item_id ] : $state['category_name'] );
				$schema = $this->is_voucher( $item ) ? array() : ( isset( $schemas[ $category_id ] ) ? $schemas[ $category_id ] : array() );

				$manual_price = isset( $state['selling_prices'][ $item_id ] ) ? (string) $state['selling_prices'][ $item_id ] : '';
				try {
					$result = $this->import_item( $category_id, $item, $schema, $category_name, $manual_price, $state['import_structure'], $state['pricing_mode'] );
					if ( ! empty( $result['created'] ) ) {
						++$state['created'];
					} else {
						++$state['updated'];
					}
				} catch ( DST2T_API_Exception $error ) {
					++$state['failed'];
					$this->record_error( $state, $item_id, $error->get_api_code() );
				} catch ( Throwable $error ) {
					++$state['failed'];
					$this->record_error( $state, $item_id, 'IMPORT_ERROR' );
				}
			}

			$state['updated_at'] = time();
			$this->save_state( $state );

			if ( (int) $state['cursor'] < (int) $state['total'] ) {
				$this->schedule( $job_id, 1 );
			} else {
				$this->finish_job( $state );
			}
		} finally {
			delete_option( $lock_key );
		}
	}

	/** Return a sanitized job state. */
	public function status( $job_id, $user_id = 0 ) {
		$state = $this->load_state( $job_id );
		if ( ! $state ) {
			return array();
		}
		if ( $user_id && absint( $state['user_id'] ) !== absint( $user_id ) ) {
			return array();
		}
		$state['percent'] = $state['total'] ? min( 100, (int) floor( ( (int) $state['processed'] / (int) $state['total'] ) * 100 ) ) : 100;
		unset( $state['item_ids'], $state['item_categories'], $state['item_category_names'], $state['selling_prices'] );
		return $state;
	}

	public function last_job_id( $user_id ) {
		return sanitize_text_field( (string) get_option( self::USER_JOB_PREFIX . absint( $user_id ), '' ) );
	}

	public function ajax_status() {
		$this->authorize_ajax();
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$state  = $this->status( $job_id, get_current_user_id() );
		if ( ! $state ) {
			wp_send_json_error( array( 'message' => __( 'Import job not found.', 'delicat-shop2topup' ) ), 404 );
		}
		wp_send_json_success( $state );
	}

	/** Advance one batch from the browser so imports work even when cron is blocked. */
	public function ajax_step() {
		$this->authorize_ajax();
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$state = $this->status( $job_id, get_current_user_id() );
		if ( ! $state ) {
			wp_send_json_error( array( 'message' => __( 'Import job not found.', 'delicat-shop2topup' ) ), 404 );
		}
		if ( ! in_array( $state['status'], array( 'complete', 'complete_with_errors', 'failed' ), true ) ) {
			$this->process_job( $job_id );
		}
		wp_send_json_success( $this->status( $job_id, get_current_user_id() ) );
	}

	private function authorize_ajax() {
		check_ajax_referer( 'dst2t_import_status', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'delicat-shop2topup' ) ), 403 );
		}
	}

	/** Import or refresh one WooCommerce catalog item. */
	private function import_item( $category_id, $catalog_item, $schema, $category_name, $manual_price = '', $structure = '', $pricing_mode = '' ) {
		$structure = in_array( $structure, array( 'variable', 'simple' ), true ) ? $structure : (string) $this->settings->get( 'import_structure', 'variable' );
		$pricing_mode = 'automatic' === $pricing_mode ? 'automatic' : ( 'automatic' === $this->settings->get( 'catalog_pricing_mode', 'manual' ) ? 'automatic' : 'manual' );
		if ( 'variable' === $structure ) {
			return $this->import_variation_item( $category_id, $catalog_item, $schema, $category_name, $manual_price, $pricing_mode );
		}
		return $this->import_simple_item( $category_id, $catalog_item, $schema, $category_name, $manual_price, $pricing_mode );
	}

	/** Legacy/simple mode: one supplier item becomes one WooCommerce product. */
	private function import_simple_item( $category_id, $catalog_item, $schema, $category_name, $manual_price = '', $pricing_mode = 'manual' ) {
		$item_id = isset( $catalog_item['item_id'] ) ? absint( $catalog_item['item_id'] ) : 0;
		if ( ! $item_id ) {
			throw new RuntimeException( 'Invalid catalog item.' );
		}

		$sku = DST2T_Brand::sku_for_item( $item_id );
		$id  = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $sku ) : 0;
		if ( $id && absint( get_post_meta( $id, DST2T_Product::META_ITEM_ID, true ) ) !== $item_id ) {
			throw new RuntimeException( 'SKU collision.' );
		}
		if ( ! $id ) {
			$id = $this->find_product_by_item_id( $item_id );
		}

		$product = $id ? wc_get_product( $id ) : new WC_Product_Simple();
		if ( ! $product instanceof WC_Product ) {
			throw new RuntimeException( 'WooCommerce product creation failed.' );
		}

		$is_new = ! $product->get_id();
		$name   = isset( $catalog_item['name'] ) ? DST2T_Brand::scrub( (string) $catalog_item['name'] ) : '';
		$desc   = isset( $catalog_item['description'] ) ? DST2T_Brand::scrub( (string) $catalog_item['description'] ) : '';
		$product->set_name( sanitize_text_field( $name ) );
		$product->set_description( wp_kses_post( $desc ) );
		$product->set_virtual( true );
		if ( $is_new ) {
			$product->set_sku( $sku );
		}
		if ( $is_new || $this->settings->enabled( 'import_force_draft' ) ) {
			$product->set_status( 'draft' );
		}

		$cost = isset( $catalog_item['price'] ) ? $this->normalize_cost( $catalog_item['price'] ) : '';
		if ( '' === $cost || 0 === DST2T_Decimal::compare( $cost, '0' ) ) {
			$price = $this->api->price( $item_id );
			$cost  = $price['unit_price'];
		}

		$priceable = 0 !== DST2T_Decimal::compare( $cost, '0' );
		$retail = $this->sanitize_selling_price( $manual_price );
		if ( '' !== $retail ) {
			$product->set_regular_price( $retail );
			if ( '' === (string) $product->get_sale_price() ) {
				$product->set_price( $retail );
			}
		} elseif ( $priceable && 'automatic' === $pricing_mode && ( $is_new || $this->settings->enabled( 'sync_catalog_prices' ) ) ) {
			$retail = $this->products->selling_price( $cost );
			if ( '' !== $retail ) {
				$product->set_regular_price( $retail );
				if ( '' === (string) $product->get_sale_price() ) {
					$product->set_price( $retail );
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
			$name,
			$this->is_voucher( $catalog_item ),
			is_array( $schema ) ? $schema : array(),
			$cost
		);
		update_post_meta( $product_id, DST2T_Product::META_IMPORT_STRUCTURE, 'simple' );
		$this->products->apply_catalog_snapshot( $product_id, $catalog_item );

		if ( ! $priceable ) {
			update_post_meta( $product_id, DST2T_Product::META_SYNC_ERROR, 'ZERO_COST' );
		} else {
			delete_post_meta( $product_id, DST2T_Product::META_SYNC_ERROR );
		}

		$this->import_image( $product_id, $catalog_item );
		$this->assign_category( $product_id, $category_name );
		return array( 'product_id' => $product_id, 'created' => $is_new );
	}

	/**
	 * Group a supplier category into one variable WooCommerce product. Every
	 * supplier item/denomination is a mapped variation with its own immutable
	 * supplier USD cost and independent WooCommerce retail price.
	 */
	private function import_variation_item( $category_id, $catalog_item, $schema, $category_name, $manual_price = '', $pricing_mode = 'manual' ) {
		if ( ! class_exists( 'WC_Product_Variable' ) || ! class_exists( 'WC_Product_Variation' ) || ! class_exists( 'WC_Product_Attribute' ) ) {
			throw new RuntimeException( 'WooCommerce variable product classes are unavailable.' );
		}
		$item_id = isset( $catalog_item['item_id'] ) ? absint( $catalog_item['item_id'] ) : 0;
		$category_id = absint( $category_id );
		if ( ! $item_id || ! $category_id ) {
			throw new RuntimeException( 'Invalid catalog variation.' );
		}
		$category_name = trim( sanitize_text_field( DST2T_Brand::scrub( $category_name ) ) );
		if ( '' === $category_name ) {
			$category_name = $this->item_category_name( $catalog_item, __( 'Top-up', 'delicat-shop2topup' ) );
		}
		$category_name = trim( sanitize_text_field( DST2T_Brand::scrub( $category_name ) ) );

		$parent_id = $this->find_variable_parent( $category_id );
		$parent = $parent_id ? wc_get_product( $parent_id ) : new WC_Product_Variable();
		if ( ! $parent instanceof WC_Product ) {
			throw new RuntimeException( 'WooCommerce variable product could not be created.' );
		}
		$parent_new = ! $parent->get_id();
		if ( $parent_new ) {
			$parent->set_name( $category_name );
			$parent->set_virtual( true );
			if ( method_exists( $parent, 'set_catalog_visibility' ) ) {
				$parent->set_catalog_visibility( 'visible' );
			}
		}
		if ( $parent_new || $this->settings->enabled( 'import_force_draft' ) ) {
			$parent->set_status( 'draft' );
		}

		$attribute_label = trim( sanitize_text_field( (string) $this->settings->get( 'variation_attribute', 'Package' ) ) );
		if ( '' === $attribute_label ) {
			$attribute_label = 'Package';
		}
		$attribute_key = sanitize_title( $attribute_label );
		if ( '' === $attribute_key ) {
			$attribute_label = 'Package';
			$attribute_key = 'package';
		}
		$previous_attribute_label = $parent_id ? trim( (string) get_post_meta( $parent_id, DST2T_Product::META_VARIATION_ATTRIBUTE, true ) ) : '';
		$previous_attribute_key = '' !== $previous_attribute_label ? sanitize_title( $previous_attribute_label ) : $attribute_key;

		$variation_id = $this->find_variation_by_item_id( $item_id );
		$variation = $variation_id ? wc_get_product( $variation_id ) : new WC_Product_Variation();
		if ( ! $variation instanceof WC_Product ) {
			throw new RuntimeException( 'WooCommerce variation could not be created.' );
		}
		$variation_new = ! $variation->get_id();
		$legacy_id = $this->find_simple_by_item_id( $item_id );
		$legacy = $legacy_id ? wc_get_product( $legacy_id ) : false;

		$existing_label = $variation_id ? (string) get_post_meta( $variation_id, DST2T_Product::META_VARIATION_LABEL, true ) : '';
		$label = '' !== trim( $existing_label ) ? trim( $existing_label ) : $this->variation_label( $catalog_item, $category_name, $item_id );
		$label = $this->unique_variation_label( $parent, $attribute_key, $previous_attribute_key, $label, $item_id, $variation_id );

		$this->merge_parent_attribute( $parent, $attribute_label, $attribute_key, $previous_attribute_key, $label );
		$parent_id = $parent->save();
		if ( ! $parent_id ) {
			throw new RuntimeException( 'WooCommerce variable parent could not be saved.' );
		}
		update_post_meta( $parent_id, DST2T_Product::META_GROUP_CATEGORY, $category_id );
		update_post_meta( $parent_id, DST2T_Product::META_IMPORT_STRUCTURE, 'variable' );
		update_post_meta( $parent_id, DST2T_Product::META_VARIATION_ATTRIBUTE, $attribute_label );

		if ( $variation_new ) {
			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'publish' );
			$variation->set_virtual( true );
			$variation->set_sku( DST2T_Brand::sku_for_item( $item_id ) );
		} elseif ( method_exists( $variation, 'set_parent_id' ) && (int) $variation->get_parent_id() !== (int) $parent_id ) {
			$variation->set_parent_id( $parent_id );
		}
		$attributes = $variation_new ? array() : (array) $variation->get_attributes();
		if ( $previous_attribute_key !== $attribute_key ) {
			unset( $attributes[ $previous_attribute_key ] );
		}
		$attributes[ $attribute_key ] = $label;
		$variation->set_attributes( $attributes );

		$cost = isset( $catalog_item['price'] ) ? $this->normalize_cost( $catalog_item['price'] ) : '';
		if ( '' === $cost || 0 === DST2T_Decimal::compare( $cost, '0' ) ) {
			$live = $this->api->price( $item_id );
			$cost = $live['unit_price'];
		}
		$priceable = 0 !== DST2T_Decimal::compare( $cost, '0' );
		$retail = $this->sanitize_selling_price( $manual_price );

		// When migrating an older simple draft to the grouped structure, preserve a
		// merchant-entered WooCommerce price unless an explicit new price was posted.
		if ( '' === $retail && $variation_new && $legacy instanceof WC_Product ) {
			$retail = $this->sanitize_selling_price( $legacy->get_regular_price() );
			if ( '' !== $retail && '' !== (string) $legacy->get_sale_price() ) {
				$variation->set_sale_price( (string) $legacy->get_sale_price() );
			}
		}
		if ( '' !== $retail ) {
			$variation->set_regular_price( $retail );
			if ( '' === (string) $variation->get_sale_price() ) {
				$variation->set_price( $retail );
			}
		} elseif ( $priceable && 'automatic' === $pricing_mode && ( $variation_new || $this->settings->enabled( 'sync_catalog_prices' ) ) ) {
			$retail = $this->products->selling_price( $cost );
			if ( '' !== $retail ) {
				$variation->set_regular_price( $retail );
				if ( '' === (string) $variation->get_sale_price() ) {
					$variation->set_price( $retail );
				}
			}
		}

		$variation_id = $variation->save();
		$this->products->save_mapping(
			$variation_id,
			'yes',
			$item_id,
			$category_id,
			$variation_new ? '' : get_post_meta( $variation_id, DST2T_Product::META_MAX_COST, true ),
			isset( $catalog_item['name'] ) ? DST2T_Brand::scrub( $catalog_item['name'] ) : '',
			$this->is_voucher( $catalog_item ),
			is_array( $schema ) ? $schema : array(),
			$cost
		);
		update_post_meta( $variation_id, DST2T_Product::META_IMPORT_STRUCTURE, 'variation' );
		update_post_meta( $variation_id, DST2T_Product::META_VARIATION_LABEL, $label );
		$this->products->apply_catalog_snapshot( $variation_id, $catalog_item );

		if ( ! $priceable ) {
			update_post_meta( $variation_id, DST2T_Product::META_SYNC_ERROR, 'ZERO_COST' );
		} else {
			delete_post_meta( $variation_id, DST2T_Product::META_SYNC_ERROR );
		}

		// Once an item is represented by a variation, every old simple product with
		// the same immutable supplier item id is superseded. The product title or
		// category name is intentionally irrelevant, so renaming “Direct Topup” to
		// “Free Fire LATAM” cannot create a second sellable copy of the same item.
		$this->supersede_simple_duplicates( $item_id, $parent_id, $variation_id );

		$this->import_image( $parent_id, $catalog_item );
		$this->assign_category( $parent_id, $category_name );
		if ( class_exists( 'WC_Product_Variable' ) && is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
			WC_Product_Variable::sync( $parent_id );
		}
		wc_delete_product_transients( $parent_id );
		return array( 'product_id' => $variation_id, 'parent_id' => $parent_id, 'created' => $variation_new, 'parent_created' => $parent_new );
	}

	private function merge_parent_attribute( $parent, $attribute_label, $attribute_key, $previous_attribute_key, $new_label ) {
		$options = array();
		$keep = array();
		foreach ( (array) $parent->get_attributes( 'edit' ) as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$existing_key = sanitize_title( $attribute->get_name() );
			if ( $existing_key === $attribute_key || $existing_key === $previous_attribute_key ) {
				$options = array_merge( $options, (array) $attribute->get_options() );
				continue;
			}
			$keep[] = $attribute;
		}
		$options[] = $new_label;
		$options = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $options ) ) ) );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( $attribute_label );
		$attribute->set_options( $options );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$keep[] = $attribute;
		$parent->set_attributes( $keep );
	}

	private function variation_label( $item, $category_name, $item_id ) {
		$name = isset( $item['name'] ) ? trim( sanitize_text_field( DST2T_Brand::scrub( $item['name'] ) ) ) : '';
		$category_name = trim( sanitize_text_field( $category_name ) );
		if ( '' !== $category_name && 0 === stripos( $name, $category_name ) ) {
			$trimmed = trim( substr( $name, strlen( $category_name ) ), " \t\n\r\0\x0B-–—:|/" );
			if ( '' !== $trimmed ) {
				$name = $trimmed;
			}
		}
		if ( '' === $name ) {
			$name = __( 'Package', 'delicat-shop2topup' );
		}
		return substr( $name, 0, 100 );
	}

	private function unique_variation_label( $parent, $attribute_key, $previous_attribute_key, $label, $item_id, $variation_id = 0 ) {
		$used = array();
		if ( $parent instanceof WC_Product && method_exists( $parent, 'get_children' ) ) {
			foreach ( (array) $parent->get_children() as $child_id ) {
				$child_id = absint( $child_id );
				if ( ! $child_id || $child_id === absint( $variation_id ) ) {
					continue;
				}
				$child = wc_get_product( $child_id );
				if ( ! $child instanceof WC_Product ) {
					continue;
				}
				$attrs = (array) $child->get_attributes();
				$existing = isset( $attrs[ $attribute_key ] ) ? (string) $attrs[ $attribute_key ] : '';
				if ( '' === trim( $existing ) && $previous_attribute_key !== $attribute_key && isset( $attrs[ $previous_attribute_key ] ) ) {
					$existing = (string) $attrs[ $previous_attribute_key ];
				}
				if ( '' === trim( $existing ) ) {
					$existing = (string) get_post_meta( $child_id, DST2T_Product::META_VARIATION_LABEL, true );
				}
				if ( '' !== trim( $existing ) ) {
					$used[ strtolower( trim( $existing ) ) ] = true;
				}
			}
		}
		if ( ! isset( $used[ strtolower( trim( $label ) ) ] ) ) {
			return $label;
		}
		return substr( $label, 0, 88 ) . ' · ' . strtoupper( substr( hash( 'sha256', (string) $item_id ), 0, 6 ) );
	}

	private function find_variable_parent( $category_id ) {
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}
		$ids = get_posts( array(
			'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
			'meta_key' => DST2T_Product::META_GROUP_CATEGORY, 'meta_value' => (string) absint( $category_id ),
		) );
		return $ids ? absint( $ids[0] ) : 0;
	}

	private function find_variation_by_item_id( $item_id ) {
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}
		$ids = get_posts( array(
			'post_type' => 'product_variation', 'post_status' => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
			'meta_key' => DST2T_Product::META_ITEM_ID, 'meta_value' => (string) absint( $item_id ),
		) );
		return $ids ? absint( $ids[0] ) : 0;
	}

	private function supersede_simple_duplicates( $item_id, $parent_id, $variation_id ) {
		if ( ! function_exists( 'get_posts' ) ) {
			return;
		}
		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => DST2T_Product::META_ITEM_ID,
			'meta_value'     => (string) absint( $item_id ),
		) );
		foreach ( (array) $ids as $legacy_id ) {
			$legacy_id = absint( $legacy_id );
			if ( ! $legacy_id || $legacy_id === absint( $variation_id ) ) {
				continue;
			}
			$legacy = wc_get_product( $legacy_id );
			if ( ! $legacy instanceof WC_Product || ! $legacy->is_type( 'simple' ) ) {
				continue;
			}
			update_post_meta( $legacy_id, DST2T_Product::META_SUPERSEDED_BY, absint( $parent_id ) );
			update_post_meta( $legacy_id, DST2T_Product::META_ENABLED, 'no' );
			if ( method_exists( $legacy, 'set_catalog_visibility' ) ) {
				$legacy->set_catalog_visibility( 'hidden' );
			}
			if ( method_exists( $legacy, 'set_status' ) ) {
				$legacy->set_status( 'draft' );
			}
			$legacy->save();
		}
	}

	private function find_simple_by_item_id( $item_id ) {
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}
		$ids = get_posts( array(
			'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
			'meta_key' => DST2T_Product::META_ITEM_ID, 'meta_value' => (string) absint( $item_id ),
		) );
		return $ids ? absint( $ids[0] ) : 0;
	}

	private function sanitize_selling_price( $value ) {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		if ( '' === $value || ! is_numeric( $value ) || (float) $value < 0 ) {
			return '';
		}
		return (string) wc_format_decimal( $value, wc_get_price_decimals() );
	}

	private function normalize_cost( $value ) {
		try {
			return DST2T_Decimal::normalize( $value );
		} catch ( Throwable $error ) {
			return '';
		}
	}

	private function item_category_id( $item, $fallback = 0 ) {
		if ( is_array( $item ) ) {
			if ( isset( $item['category_id'] ) ) { return absint( $item['category_id'] ); }
			if ( isset( $item['categoryId'] ) ) { return absint( $item['categoryId'] ); }
		}
		return absint( $fallback );
	}

	private function item_category_name( $item, $fallback = '' ) {
		if ( is_array( $item ) ) {
			if ( isset( $item['category_name'] ) && '' !== trim( (string) $item['category_name'] ) ) { return (string) $item['category_name']; }
			if ( isset( $item['categoryName'] ) && '' !== trim( (string) $item['categoryName'] ) ) { return (string) $item['categoryName']; }
		}
		return (string) $fallback;
	}

	private function fatal_catalog_error( $code ) {
		return in_array( strtoupper( (string) $code ), array( 'INVALID_API_KEY', 'MISSING_API_KEY', 'IP_NOT_ALLOWED', 'HTTP_401', 'HTTP_403' ), true );
	}

	private function is_voucher( $item ) {
		$type = isset( $item['product_type'] ) ? strtolower( sanitize_key( (string) $item['product_type'] ) ) : '';
		return 'voucher' === $type || ! empty( $item['returns_voucher'] ) || ( isset( $item['fulfillment_type'] ) && 'voucher' === strtolower( (string) $item['fulfillment_type'] ) );
	}

	private function import_image( $product_id, $item ) {
		if ( ! $this->settings->enabled( 'import_images' ) || has_post_thumbnail( $product_id ) ) {
			return;
		}
		$url = '';
		foreach ( array( 'image', 'image_url', 'icon', 'thumbnail', 'logo', 'picture' ) as $key ) {
			if ( isset( $item[ $key ] ) && is_string( $item[ $key ] ) && '' !== trim( $item[ $key ] ) ) {
				$url = trim( $item[ $key ] );
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
		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $product_id, $attachment_id );
		}
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
				 INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id
				 WHERE m.meta_key=%s AND m.meta_value=%s
				 AND p.post_type IN ('product','product_variation')
				 AND p.post_status NOT IN ('trash','auto-draft')
				 ORDER BY m.post_id ASC LIMIT 1",
				DST2T_Product::META_ITEM_ID,
				(string) absint( $item_id )
			)
		);
	}

	private function record_error( &$state, $item_id, $code ) {
		$state['errors'][] = array( 'item_id' => absint( $item_id ), 'code' => substr( sanitize_key( (string) $code ), 0, 64 ) );
		$state['errors']   = array_slice( $state['errors'], -20 );
	}

	private function fail_job( $state, $code ) {
		$state['status']     = 'failed';
		$state['updated_at'] = time();
		$this->record_error( $state, 0, $code );
		$this->save_state( $state );
	}

	private function finish_job( $state ) {
		$state['status']      = ! empty( $state['failed'] ) ? 'complete_with_errors' : 'complete';
		$state['updated_at']  = time();
		$state['finished_at'] = time();
		$this->save_state( $state );
		update_option( 'dst2t_catalog_last_import', gmdate( 'c' ), false );
	}

	/** Internal job state including the private item queue. */
	private function load_state( $job_id ) {
		$state = get_option( $this->job_key( $job_id ), array() );
		if ( ! is_array( $state ) || empty( $state['id'] ) || ! hash_equals( (string) $state['id'], (string) $job_id ) ) {
			return array();
		}
		$state['mode'] = isset( $state['mode'] ) && 'full' === $state['mode'] ? 'full' : 'category';
		$state['item_ids'] = isset( $state['item_ids'] ) && is_array( $state['item_ids'] ) ? array_values( array_map( 'absint', $state['item_ids'] ) ) : array();
		$state['item_categories'] = isset( $state['item_categories'] ) && is_array( $state['item_categories'] ) ? $state['item_categories'] : array();
		$state['item_category_names'] = isset( $state['item_category_names'] ) && is_array( $state['item_category_names'] ) ? $state['item_category_names'] : array();
		$state['selling_prices'] = isset( $state['selling_prices'] ) && is_array( $state['selling_prices'] ) ? $state['selling_prices'] : array();
		$state['import_structure'] = isset( $state['import_structure'] ) && 'simple' === $state['import_structure'] ? 'simple' : 'variable';
		$state['pricing_mode'] = isset( $state['pricing_mode'] ) && 'automatic' === $state['pricing_mode'] ? 'automatic' : 'manual';
		return $state;
	}

	private function save_state( $state ) {
		update_option( $this->job_key( $state['id'] ), $state, false );
	}

	private function job_key( $job_id ) {
		return self::JOB_PREFIX . md5( (string) $job_id );
	}

	private function schedule( $job_id, $delay ) {
		return DST2T_Scheduler::enqueue( self::ACTION_PROCESS, array( (string) $job_id ), max( 1, absint( $delay ) ) );
	}
}
