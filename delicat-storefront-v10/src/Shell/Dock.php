<?php
namespace Delicat\V10\Shell;

use Delicat\V10\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Whether this page carries a purchase dock.
 *
 * Not a Module: the dock's markup belongs to the product page, and this class
 * exists only so that Document::body_class() and Storefront\Product can ask the
 * same question and get the same answer. In V9 the equivalent question was
 * answered independently in four places, which is how a page reserved space for
 * a dock it did not render, and rendered one it had not reserved space for.
 */
final class Dock {

	public static function will_render(): bool {
		$context = Context::instance();

		if ( ! $context->is_page_view() ) {
			return false;
		}

		if ( Context::ROUTE_PRODUCT !== $context->route() ) {
			return false;
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product || ! $product->is_purchasable() ) {
			return false;
		}

		/** @param bool $render */
		return (bool) apply_filters( 'delicat_v10_render_dock', true, $product );
	}
}
