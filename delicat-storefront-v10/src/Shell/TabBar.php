<?php
namespace Delicat\V10\Shell;

use Delicat\V10\Context;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The bottom tab bar.
 *
 * Rendered on every storefront page, including on desktop, where CSS removes
 * it. That is deliberate: whether the bar exists must not depend on a guess
 * about the device made on the server, because a server-side guess is wrong for
 * a tablet held either way round, and a page cached for a phone would then be
 * served to a laptop with a phone's furniture in it.
 *
 * The bar is always in the markup; the viewport decides whether it is drawn.
 */
final class TabBar extends Module {

	public static function priority(): int {
		return 20;
	}

	public function register(): void {
		add_action( 'delicat_v10_shell_bottom', array( $this, 'render' ), 20 );
	}

	/**
	 * Asked by Document::body_class() before anything is rendered, so the band
	 * class and the bar itself can never disagree about whether there is a bar.
	 */
	public static function will_render(): bool {
		$context = Context::instance();

		if ( ! $context->is_page_view() ) {
			return false;
		}

		/* Checkout is the one storefront page with no tab bar. A customer who
		 * has begun paying is not being invited to browse away, and the space
		 * belongs to the order summary. Every native checkout does this. */
		if ( Context::ROUTE_CHECKOUT === $context->route() ) {
			return false;
		}

		if ( array() === Nav::tabs() ) {
			return false;
		}

		/** @param bool $render */
		return (bool) apply_filters( 'delicat_v10_render_tabbar', true );
	}

	public function render(): void {
		if ( ! self::will_render() ) {
			return;
		}

		$tabs = Nav::tabs();

		echo '<nav class="dlx-tabbar" aria-label="' . esc_attr__( 'Navigation principale', 'delicat-v10' ) . '">';

		foreach ( $tabs as $tab ) {
			$current = Nav::is_current( (string) $tab['url'] );

			printf(
				'<a class="dlx-tab" href="%1$s"%2$s>',
				esc_url( (string) $tab['url'] ),
				$current ? ' aria-current="page"' : ''
			);

			echo '<span class="dlx-tab__icon">';
			echo Icons::svg( (string) $tab['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup from a fixed table.

			if ( 'cart' === $tab['icon'] ) {
				$count = self::cart_count();
				printf(
					'<span class="dlx-tab__count" data-count="%1$d">%2$s</span>',
					$count,
					$count > 0 ? esc_html( (string) $count ) : ''
				);
			}

			echo '</span>';

			printf( '<span class="dlx-tab__label">%s</span>', esc_html( (string) $tab['label'] ) );
			echo '</a>';
		}

		echo '</nav>';
	}

	/**
	 * WooCommerce's own count, or zero.
	 *
	 * Read from WooCommerce rather than kept in a V10 table, so it cannot go
	 * stale and cannot disagree with the cart page. A page that is publicly
	 * cached reports zero and ui.js corrects it from WooCommerce's own cart
	 * fragments once the page is interactive - the badge is the only part of
	 * the chrome that is per-customer, and this is why the rest of the document
	 * stays cacheable.
	 */
	public static function cart_count(): int {
		if ( ! function_exists( 'WC' ) ) {
			return 0;
		}

		$cart = WC()->cart;
		if ( ! $cart ) {
			return 0;
		}

		return (int) $cart->get_cart_contents_count();
	}
}
