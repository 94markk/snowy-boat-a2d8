<?php
namespace Delicat\V10\Storefront;

use Delicat\V10\Context;
use Delicat\V10\Module;
use Delicat\V10\Shell\Dock;
use Delicat\V10\Shell\Icons;

defined( 'ABSPATH' ) || exit;

/**
 * The product page.
 *
 * -----------------------------------------------------------------------------
 * What V10 does here, and what it deliberately does not
 * -----------------------------------------------------------------------------
 * It renders a purchase dock - the bar a phone customer buys from without
 * scrolling back to the top - and it gives WooCommerce's add-to-cart form an id
 * so that the dock's button can submit it.
 *
 * It does NOT own the purchase. The button is a plain submit pointing at
 * WooCommerce's own form. There is no V10 endpoint, no signed intent, no status
 * poller and no per-customer lock. V9 had all four, and its lock outlived a
 * failed payment: a customer whose wallet was short was left unable to check out
 * at all until someone released them by hand from an admin screen. A customer
 * with no funds should see a declined payment, which is what WooCommerce does by
 * itself as soon as nothing is standing in the way.
 *
 * Every decision about price, stock, variations, tax and whether a purchase may
 * proceed is read from WooCommerce and made by WooCommerce.
 */
final class Product extends Module {

	public static function routes(): array {
		return array( Context::ROUTE_PRODUCT );
	}

	/** @var bool */
	private $buffering = false;

	/** @var bool Whether the form was successfully given an id this request. */
	private static $tagged = false;

	public function register(): void {
		/*
		 * WooCommerce's add-to-cart form has no id, and the dock's button needs
		 * one to target. These two hooks bracket the form exactly - they fire
		 * immediately before and after it in every add-to-cart template - so
		 * the buffer between them contains the form and nothing else.
		 *
		 * This is the only place V10 touches WooCommerce's own markup; it adds
		 * a single attribute; and a theme that has overridden the template keeps
		 * its override, because the change is made to the rendered output rather
		 * than to the template.
		 */
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'capture_form' ), 1 );
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'release_form' ), 999 );

		/* The footer runs after the form, so by the time the dock renders it
		 * knows for certain whether there is a form for it to submit. */
		add_action( 'delicat_v10_shell_bottom', array( $this, 'render_dock' ), 5 );
	}

	/* ---------------------------------------------------------------------
	 * Giving WooCommerce's form an id
	 * ------------------------------------------------------------------ */

	public function capture_form(): void {
		if ( $this->buffering ) {
			return;
		}
		$this->buffering = true;
		ob_start();
	}

	public function release_form(): void {
		if ( ! $this->buffering ) {
			return;
		}
		$this->buffering = false;

		$html = ob_get_clean();
		$html = is_string( $html ) ? $html : '';

		$tagged = self::tag_form( $html );

		self::$tagged = $tagged !== $html || false !== strpos( $html, 'id="' . self::form_id() . '"' );

		echo $tagged; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own escaped output with one attribute added.
	}

	/**
	 * Add the id to WooCommerce's cart form if it does not already have one.
	 *
	 * A form that already carries an id keeps it, because a plugin may be
	 * targeting that id. The dock then renders without a button, which is the
	 * correct way to fail: a missing shortcut rather than a broken purchase.
	 */
	public static function tag_form( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}

		$tagged = preg_replace(
			'/<form(?![^>]*\bid=)([^>]*\bclass=(["\'])[^"\']*\bcart\b[^"\']*\2)/i',
			'<form id="' . self::form_id() . '"$1',
			$html,
			1
		);

		return is_string( $tagged ) ? $tagged : $html;
	}

	/**
	 * The id of WooCommerce's add-to-cart form on this page.
	 *
	 * Assigned from here and nowhere else, so no two consumers can invent
	 * different ones for the same form.
	 */
	public static function form_id(): string {
		return 'dlx-add-to-cart';
	}

	/* ---------------------------------------------------------------------
	 * The dock
	 * ------------------------------------------------------------------ */

	/**
	 * Three things worth noting, each of them a V9 bug the merchant saw:
	 *
	 * 1. The dock sits ON the tab bar, never over it. Its `bottom` is the tab
	 *    bar's height plus the safe area, both read from the token layer, so
	 *    the two cannot overlap however either is retuned. In V9 the dock and
	 *    the nav bar overlapped each other and together covered the package
	 *    cards.
	 *
	 * 2. Whether it renders is decided by Shell\Dock - which is also what
	 *    Document::body_class() asks when it decides how much space to reserve.
	 *    One question, one answer, so the page cannot reserve room for a dock it
	 *    did not draw, or draw one it did not reserve room for.
	 *
	 * 3. It is removed on desktop rather than restyled: a laptop product page
	 *    already has an add-to-cart button in the page. V9 shipped a phone
	 *    action bar across the bottom of laptops and reserved 190px for it.
	 */
	public function render_dock(): void {
		if ( ! Dock::will_render() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product ) {
			return;
		}

		echo '<div class="dlx-dock">';

		echo '<span class="dlx-dock__price" data-dlx-dock-price>';
		printf( '<small>%s</small>', esc_html__( 'Prix', 'delicat-v10' ) );
		printf( '<strong>%s</strong>', wp_kses_post( $product->get_price_html() ) );
		echo '</span>';

		if ( ! $product->is_in_stock() ) {
			printf(
				'<span class="dlx-btn dlx-btn--secondary" aria-disabled="true">%s</span>',
				esc_html__( 'Rupture de stock', 'delicat-v10' )
			);
		} elseif ( self::$tagged ) {
			/*
			 * A plain submit that targets WooCommerce's own form. The form
			 * carries the nonce, the variation inputs and any custom fields a
			 * plugin added - a Player ID, for instance - and this button simply
			 * submits it. Nothing is duplicated here, so nothing here can fall
			 * out of step with it, and with JavaScript unavailable the button
			 * still submits the form, because submitting is all it does.
			 */
			printf(
				'<button type="submit" form="%1$s" class="dlx-btn dlx-btn--primary">%2$s %3$s</button>',
				esc_attr( self::form_id() ),
				Icons::svg( 'cart', 20 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed table.
				esc_html__( 'Acheter maintenant', 'delicat-v10' )
			);
		} else {
			/* No form to submit - an external product, or a template that
			 * renders its own. Send the customer to the page's own button
			 * rather than showing one that would do nothing. */
			printf(
				'<a class="dlx-btn dlx-btn--primary" href="#%1$s">%2$s</a>',
				esc_attr( self::form_id() ),
				esc_html__( 'Voir les options', 'delicat-v10' )
			);
		}

		echo '</div>';
	}
}
