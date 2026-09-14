<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight, server-rendered product badge resolver.
 *
 * No public endpoint, no client polling, no arbitrary HTML.
 */
final class Delicat_Builder_V9_Badge_Engine {
	public const MODES = array( 'auto', 'category', 'tag', 'sale', 'stock', 'custom', 'off' );

	private static array $request_cache = array();

	public static function sanitize_mode( $mode ): string {
		$mode = sanitize_key( (string) $mode );
		return in_array( $mode, self::MODES, true ) ? $mode : 'auto';
	}

	private static function first_term( int $product_id, string $taxonomy ): array {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) || empty( $terms ) || ! $terms[0] instanceof WP_Term ) {
			return array();
		}
		return array(
			'text' => sanitize_text_field( $terms[0]->name ),
			'type' => 'product_tag' === $taxonomy ? 'tag' : 'category',
		);
	}

	public static function resolve( WC_Product $product, string $mode = 'auto', string $custom = '' ): array {
		$mode = self::sanitize_mode( $mode );
		$custom = sanitize_text_field( $custom );
		$key = $product->get_id() . '|' . $mode . '|' . md5( $custom );

		if ( array_key_exists( $key, self::$request_cache ) ) {
			return self::$request_cache[ $key ];
		}

		$result = array();

		if ( 'off' === $mode ) {
			return self::$request_cache[ $key ] = $result;
		}

		if ( 'custom' === $mode || ( 'auto' === $mode && '' !== $custom ) ) {
			$result = '' !== $custom
				? array( 'text' => $custom, 'type' => 'custom' )
				: array();
			return self::$request_cache[ $key ] = $result;
		}

		if ( 'sale' === $mode ) {
			$result = $product->is_on_sale()
				? array( 'text' => __( 'Promo', 'delicat-builder-v9' ), 'type' => 'sale' )
				: array();
			return self::$request_cache[ $key ] = $result;
		}

		if ( 'stock' === $mode ) {
			$result = array(
				'text' => $product->is_in_stock()
					? __( 'Disponible', 'delicat-builder-v9' )
					: __( 'Indisponible', 'delicat-builder-v9' ),
				'type' => $product->is_in_stock() ? 'stock' : 'out',
			);
			return self::$request_cache[ $key ] = $result;
		}

		if ( 'tag' === $mode ) {
			return self::$request_cache[ $key ] = self::first_term( $product->get_id(), 'product_tag' );
		}

		if ( 'category' === $mode ) {
			return self::$request_cache[ $key ] = self::first_term( $product->get_id(), 'product_cat' );
		}

		// Auto priority:
		// 1) custom override (handled above)
		// 2) promotion
		// 3) unavailable warning
		// 4) Woo category, matching the Delicat reference design.
		if ( $product->is_on_sale() ) {
			$result = array( 'text' => __( 'Promo', 'delicat-builder-v9' ), 'type' => 'sale' );
		} elseif ( ! $product->is_in_stock() ) {
			$result = array( 'text' => __( 'Indisponible', 'delicat-builder-v9' ), 'type' => 'out' );
		} else {
			$result = self::first_term( $product->get_id(), 'product_cat' );
		}

		return self::$request_cache[ $key ] = $result;
	}

	public static function resolve_status( WC_Product $product, string $mode = 'auto', int $new_days = 30, int $sales_min = 10 ): array {
		$mode = sanitize_key( $mode );
		$mode = in_array( $mode, array( 'auto', 'sale', 'new', 'bestseller', 'off' ), true ) ? $mode : 'auto';
		$new_days = min( 120, max( 1, $new_days ) );
		$sales_min = min( 10000, max( 1, $sales_min ) );
		$key = 'status|' . $product->get_id() . '|' . $mode . '|' . $new_days . '|' . $sales_min;

		if ( array_key_exists( $key, self::$request_cache ) ) {
			return self::$request_cache[ $key ];
		}

		if ( 'off' === $mode ) {
			return self::$request_cache[ $key ] = array();
		}

		$created = $product->get_date_created();
		$is_new = $created instanceof WC_DateTime && ( time() - $created->getTimestamp() ) <= DAY_IN_SECONDS * $new_days;
		$is_best = absint( $product->get_total_sales() ) >= $sales_min;
		$is_sale = $product->is_on_sale();

		if ( 'sale' === $mode ) {
			return self::$request_cache[ $key ] = $is_sale ? array( 'text' => __( 'PROMO', 'delicat-builder-v9' ), 'type' => 'promo' ) : array();
		}
		if ( 'new' === $mode ) {
			return self::$request_cache[ $key ] = $is_new ? array( 'text' => __( 'NOUVEAU', 'delicat-builder-v9' ), 'type' => 'new' ) : array();
		}
		if ( 'bestseller' === $mode ) {
			return self::$request_cache[ $key ] = $is_best ? array( 'text' => __( 'TOP VENTE', 'delicat-builder-v9' ), 'type' => 'bestseller' ) : array();
		}

		// Reference auto priority: real active promo, then established sales,
		// then newly published products. All values come from WooCommerce.
		if ( $is_sale ) {
			$result = array( 'text' => __( 'PROMO', 'delicat-builder-v9' ), 'type' => 'promo' );
		} elseif ( $is_best ) {
			$result = array( 'text' => __( 'TOP VENTE', 'delicat-builder-v9' ), 'type' => 'bestseller' );
		} elseif ( $is_new ) {
			$result = array( 'text' => __( 'NOUVEAU', 'delicat-builder-v9' ), 'type' => 'new' );
		} else {
			$result = array();
		}

		return self::$request_cache[ $key ] = $result;
	}

}
