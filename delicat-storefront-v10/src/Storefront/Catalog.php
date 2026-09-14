<?php
namespace Delicat\V10\Storefront;

use Delicat\V10\Context;
use Delicat\V10\Module;
use Delicat\V10\Nav\ViewTransitions;

defined( 'ABSPATH' ) || exit;

/**
 * The shop and category listings.
 *
 * WooCommerce chooses and orders the products; V10 names each card's image so
 * that tapping one animates it into the product page's image, and lets the
 * first two load eagerly because they are above the fold on every phone.
 */
final class Catalog extends Module {

	public static function routes(): array {
		return array( Context::ROUTE_CATALOG, Context::ROUTE_SEARCH );
	}

	/** @var int */
	private $seen = 0;

	public function register(): void {
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'card_image' ), 10, 2 );
	}

	/**
	 * @param array<string,string> $attrs
	 * @param mixed $attachment
	 * @return array<string,string>
	 */
	public function card_image( $attrs, $attachment ): array {
		if ( ! is_array( $attrs ) || ! in_the_loop() ) {
			return is_array( $attrs ) ? $attrs : array();
		}

		$product_id = (int) get_the_ID();
		if ( $product_id <= 0 ) {
			return $attrs;
		}

		$this->seen++;

		/*
		 * The first two images on a listing are visible without scrolling on
		 * every phone size. Lazy-loading them delays the largest contentful
		 * paint by a full round trip for no benefit - lazy loading is for
		 * images below the fold, and these are not.
		 */
		if ( $this->seen <= 2 ) {
			$attrs['loading']       = 'eager';
			$attrs['decoding']      = 'sync';
			$attrs['fetchpriority'] = 1 === $this->seen ? 'high' : 'auto';
		}

		$style          = isset( $attrs['style'] ) ? rtrim( (string) $attrs['style'], '; ' ) . ';' : '';
		$attrs['style'] = $style . 'view-transition-name:' . ViewTransitions::product_name( $product_id );

		unset( $attachment );
		return $attrs;
	}
}
