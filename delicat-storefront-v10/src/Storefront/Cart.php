<?php
namespace Delicat\V10\Storefront;

use Delicat\V10\Context;
use Delicat\V10\Module;
use Delicat\V10\Shell\Icons;

defined( 'ABSPATH' ) || exit;

/**
 * The cart.
 *
 * WooCommerce renders it. V10 adds a better empty state and the count that the
 * tab bar's badge reads, and otherwise stays out of the way - every line, price,
 * quantity rule, coupon and total here is WooCommerce's.
 */
final class Cart extends Module {

	public static function routes(): array {
		return array( Context::ROUTE_CART );
	}

	public function register(): void {
		add_action( 'woocommerce_cart_is_empty', array( $this, 'empty_state' ), 5 );

		/* Remove WooCommerce's own empty-cart block, which the action above
		 * replaces. Both would otherwise render. */
		remove_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message', 10 );
	}

	public function empty_state(): void {
		echo '<div class="dlx-empty">';
		printf( '<span class="dlx-empty__icon">%s</span>', Icons::svg( 'cart', 28 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed table.
		printf( '<h2>%s</h2>', esc_html__( 'Votre panier est vide', 'delicat-v10' ) );
		printf( '<p>%s</p>', esc_html__( 'Parcourez la boutique pour trouver votre recharge.', 'delicat-v10' ) );

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			printf(
				'<a class="dlx-btn dlx-btn--primary" href="%s">%s</a>',
				esc_url( (string) wc_get_page_permalink( 'shop' ) ),
				esc_html__( 'Voir la boutique', 'delicat-v10' )
			);
		}

		echo '</div>';
	}
}
