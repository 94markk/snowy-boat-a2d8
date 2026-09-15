<?php
namespace Delicat\V10\Nav;

use Delicat\V10\Context;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Render the next page before the customer has finished tapping.
 *
 * -----------------------------------------------------------------------------
 * This is the single largest contributor to V10 feeling like an app
 * -----------------------------------------------------------------------------
 * A prerendered page is not "fast". It is already finished: HTML parsed, CSS
 * applied, images decoded, layout done. Activating it is a paint, not a load,
 * and the customer experiences a page change with no waiting at all - which is
 * precisely what a native screen push feels like and what no amount of
 * optimising a real navigation can match.
 *
 * The cost is that the server renders pages nobody visits, so the rules below
 * are conservative on purpose:
 *
 *   - `moderate` eagerness only: the browser starts when a pointer has rested
 *     on a link, not on sight. On a phone that is touch-down, which buys the
 *     150-300ms between the finger landing and lifting.
 *   - Only same-origin document links.
 *   - Never anything that changes state. A prerender runs the page for real:
 *     prerendering an add-to-cart URL would add to the cart.
 *   - Never a personal page. A prerender issued from one page can be activated
 *     later; a cart or an order page rendered speculatively is a cart rendered
 *     at the wrong moment.
 *
 * Everything excluded here is still *prefetched* instead, which fetches the
 * document without executing it - all of the latency win, none of the risk.
 */
final class Speculation extends Module {

	public static function priority(): int {
		return 30;
	}

	public function register(): void {
		/*
		 * WordPress 6.8 ships its own speculation rules. Two sets of rules for
		 * the same links means two prerenders of the same page, so V10 takes
		 * the core configuration over rather than adding to it - and only where
		 * core's own filter exists, so nothing is disabled that was not going
		 * to be replaced.
		 */
		add_filter( 'wp_speculation_rules_configuration', array( $this, 'core_configuration' ) );

		if ( ! has_filter( 'wp_speculation_rules_configuration' ) || ! function_exists( 'wp_get_speculation_rules' ) ) {
			add_action( 'wp_footer', array( $this, 'print_rules' ), 5 );
		}
	}

	/**
	 * Core's own rules, tuned rather than duplicated.
	 *
	 * @param array<string,string>|null $config
	 * @return array<string,string>|null
	 */
	public function core_configuration( $config ) {
		if ( ! Context::instance()->is_page_view() ) {
			return $config;
		}

		return array(
			'mode'      => 'prerender',
			'eagerness' => 'moderate',
		);
	}

	public function print_rules(): void {
		if ( ! Context::instance()->is_page_view() ) {
			return;
		}

		$rules = array(
			'prerender' => array(
				array(
					'source'    => 'document',
					'eagerness' => 'moderate',
					'where'     => array(
						'and' => array(
							array( 'href_matches' => '/*' ),
							array( 'not' => array( 'href_matches' => self::excluded_paths() ) ),
							array( 'not' => array( 'selector_matches' => self::excluded_selectors() ) ),
						),
					),
				),
			),
			/*
			 * Everything the prerender rule refuses still gets its document
			 * fetched - just not executed. The cart page then opens from cache
			 * without ever having been rendered speculatively.
			 */
			'prefetch'  => array(
				array(
					'source'    => 'document',
					'eagerness' => 'moderate',
					'where'     => array(
						'and' => array(
							array( 'href_matches' => '/*' ),
							array( 'not' => array( 'selector_matches' => '[data-dlx-no-prefetch], [download], [rel~="nofollow"]' ) ),
						),
					),
				),
			),
		);

		/** @param array<string,mixed> $rules */
		$rules = apply_filters( 'delicat_v10_speculation_rules', $rules );

		printf(
			'<script type="speculationrules">%s</script>' . "\n",
			wp_json_encode( $rules, JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Paths that must never be rendered speculatively.
	 *
	 * @return string[]
	 */
	private static function excluded_paths(): array {
		$paths = array(
			'/wp-admin/*',
			'/wp-login.php*',
			'/wp-json/*',
			'/*\\?*add-to-cart=*',
			'/*\\?*remove_item=*',
			'/*\\?*undo_item=*',
			'/*\\?*wc-ajax=*',
			'/*\\?*_wpnonce=*',
			'/*\\?*logout*',
		);

		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			if ( ! function_exists( 'wc_get_page_permalink' ) ) {
				continue;
			}
			$url  = (string) wc_get_page_permalink( $page );
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' !== $path && '/' !== $path ) {
				$paths[] = rtrim( $path, '/' ) . '/*';
				$paths[] = rtrim( $path, '/' );
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Links that must never be rendered speculatively, by what they are rather
	 * than where they point: an add-to-cart button is a link on many themes.
	 */
	private static function excluded_selectors(): string {
		return implode(
			', ',
			array(
				'[data-dlx-no-prerender]',
				'[download]',
				'[target="_blank"]',
				'[rel~="nofollow"]',
				'.add_to_cart_button',
				'.ajax_add_to_cart',
				'.remove',
				'.woocommerce-remove-coupon',
				'form a',
			)
		);
	}
}
