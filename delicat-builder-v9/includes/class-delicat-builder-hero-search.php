<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compact, cache-aware public product index for the hero type-ahead search.
 *
 * Security/performance rules:
 * - no public Builder REST/AJAX endpoint is created;
 * - only already-public WooCommerce storefront data is emitted;
 * - product updates invalidate the index through Delicat_Builder_V9_Cache::version();
 * - the index is capped to keep initial HTML small;
 * - images are URLs only and are not requested until a suggestion is displayed.
 */
final class Delicat_Builder_V9_Hero_Search {
	private const MAX_PRODUCTS = 120;
	private const CACHE_TTL    = 900;

	public static function index( int $limit = 60 ): array {
		$limit = max( 10, min( self::MAX_PRODUCTS, $limit ) );
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		// Partition public prices by currency, locale and taxable address.
		// Private requests bypass the shared index entirely.
		$context = array(
			'limit' => $limit,
			'currency' => get_woocommerce_currency(),
			'locale' => get_locale(),
			'tax_address' => function_exists( 'WC' ) && WC()->customer
				? WC()->customer->get_taxable_address() : array(),
		);
		$key = class_exists( 'Delicat_Builder_V9_Cache' )
			? Delicat_Builder_V9_Cache::key( 'hero_search_index_v2', $context )
			: 'dbv9_hero_search_v2_' . md5( wp_json_encode( $context ) );

		$share = class_exists( 'Delicat_Builder_V9_Security', false )
			&& Delicat_Builder_V9_Security::public_cache_allowed();
		$cached = $share ? get_transient( $key ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$items = array();
		try {
			$products = wc_get_products(
				array(
					'limit'   => $limit,
					'status'  => 'publish',
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'objects',
				)
			);
		} catch ( Throwable $error ) {
			return array();
		}

		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
				continue;
			}

			$id   = absint( $product->get_id() );
			$url  = $id ? get_permalink( $id ) : '';
			$name = wp_strip_all_tags( (string) $product->get_name(), true );
			if ( ! $id || ! is_string( $url ) || '' === $url || '' === $name ) {
				continue;
			}

			$image_id = absint( $product->get_image_id() );
			$thumb    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
			$price = wp_strip_all_tags( (string) $product->get_price_html(), true );
			$price = html_entity_decode( $price, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );

			$items[] = array(
				'id'    => $id,
				'name'  => $name,
				'url'   => esc_url_raw( $url ),
				'price' => sanitize_text_field( $price ),
				'image' => $thumb ? esc_url_raw( $thumb ) : '',
			);
		}

		if ( $share ) { set_transient( $key, $items, self::CACHE_TTL ); }
		return $items;
	}
}
