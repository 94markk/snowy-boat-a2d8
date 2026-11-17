<?php
/**
 * 9.13.0 (audit 2026-11-12, M-1): the Delicat Top-Up Connector prints its product-page CSS + JS inline
 * (handle « topup-fields », ~17 KB, wp_add_inline_style / wp_add_inline_script under a source-less handle)
 * on EVERY product page, including the Fazercard and Shop2TopUp products it does not sell. On those pages
 * the Builder dequeues that handle before it is printed - the Connector's files are not touched, and on its
 * own products (meta _ftr_product_code on the product or its parent) nothing changes.
 *
 * Presentation only. Filter « delicat_builder_v9_keep_connector_assets » (true = keep) for any exception.
 *
 * @package Delicat_Builder_V9
 */

defined( 'ABSPATH' ) || exit;

final class Delicat_Builder_V9_Supplier_Assets {

	public const HANDLE = 'topup-fields';

	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}
		/* the Connector enqueues at the default priority 10; 99 runs after it and before printing */
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_connector' ), 99 );
	}

	/** Is this product (or its parent) one the Connector sells? */
	public static function connector_sells( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		$ids = array( $product_id );
		$parent = (int) wp_get_post_parent_id( $product_id );
		if ( $parent > 0 ) {
			$ids[] = $parent;
		}
		foreach ( $ids as $id ) {
			if ( '' !== trim( (string) get_post_meta( $id, '_ftr_product_code', true ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function dequeue_connector(): void {
		if ( ! class_exists( 'FTR_Woo', false ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( self::connector_sells( $id ) || apply_filters( 'delicat_builder_v9_keep_connector_assets', false, $id ) ) {
			return;
		}
		wp_dequeue_script( self::HANDLE );
		wp_dequeue_style( self::HANDLE );
	}
}

Delicat_Builder_V9_Supplier_Assets::boot();
