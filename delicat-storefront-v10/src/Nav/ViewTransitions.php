<?php
namespace Delicat\V10\Nav;

use Delicat\V10\Context;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Cross-document view transitions.
 *
 * The CSS opt-in lives in the bundle, because both the page being left and the
 * page being entered must carry it. What this module does is the part that
 * cannot be expressed in CSS: name the one element on the page that should morph
 * into its counterpart, so that tapping a product card animates *that card* into
 * the product page's hero image rather than crossfading the whole screen.
 *
 * That single effect - the thing you touched becoming the thing you are looking
 * at - is what a customer reads as "this is an app". It is also the only part of
 * V10's navigation that needs any server involvement at all.
 */
final class ViewTransitions extends Module {

	public static function priority(): int {
		return 28;
	}

	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_named_element_css' ), 8 );
	}

	/**
	 * On a product page, give the hero image the transition name that the card
	 * the customer tapped also carries.
	 *
	 * The name has to be unique within each document and identical across the
	 * two, which is why it is derived from the product ID: the card on the
	 * listing and the image on the product page independently arrive at the
	 * same string without needing to know about each other.
	 *
	 * A listing renders many cards, so the names are assigned there by class
	 * attribute rather than here; this only handles the destination.
	 */
	public function print_named_element_css(): void {
		$context = Context::instance();

		if ( ! $context->is_page_view() || Context::ROUTE_PRODUCT !== $context->route() ) {
			return;
		}

		$id = (int) get_queried_object_id();
		if ( $id <= 0 ) {
			return;
		}

		/*
		 * Scoped to the first image inside the product gallery. A product page
		 * that renders the same image twice would otherwise give two elements
		 * the same name and the browser would abandon the transition entirely,
		 * so :first-of-type is load-bearing rather than decorative.
		 */
		printf(
			'<style id="delicat-v10-vt">.dlx-product-hero img:first-of-type,'
			. '.woocommerce-product-gallery__image:first-of-type img'
			. '{view-transition-name:dlx-p%1$d}</style>' . "\n",
			$id
		);
	}

	/**
	 * The transition name a product card carries, so a listing and a product
	 * page agree without either importing the other.
	 */
	public static function product_name( int $id ): string {
		return 'dlx-p' . max( 0, $id );
	}
}
