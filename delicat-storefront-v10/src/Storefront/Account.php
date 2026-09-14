<?php
namespace Delicat\V10\Storefront;

use Delicat\V10\Context;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The account and wallet pages.
 *
 * These are the most personal pages in the store, so the only thing V10 does
 * here is make sure they are treated as personal: never speculatively rendered,
 * never stored anywhere shared, and always revalidated.
 *
 * The balance, the transaction list and every figure on these pages come from
 * WooCommerce and the wallet plugin. V10 reads none of it.
 */
final class Account extends Module {

	public static function routes(): array {
		return array( Context::ROUTE_ACCOUNT, Context::ROUTE_WALLET );
	}

	public function register(): void {
		/*
		 * A prerender executes the page for real. A page showing one person's
		 * balance, rendered ahead of time and activated later, is a page that
		 * can be shown at the wrong moment - so every link on these pages, and
		 * every link to them, opts out. Speculation already excludes the paths;
		 * this covers a link a theme renders to somewhere unexpected.
		 */
		add_filter( 'delicat_v10_speculation_rules', array( $this, 'no_speculation' ) );

		/* The tab bar's cart badge is the only per-customer thing in the chrome.
		 * On these pages the whole document is per-customer already, so the
		 * badge may be rendered from the real cart rather than corrected later. */
		add_filter( 'delicat_v10_shared_max_age', '__return_zero' );
	}

	/**
	 * @param array<string,mixed> $rules
	 * @return array<string,mixed>
	 */
	public function no_speculation( $rules ): array {
		return is_array( $rules ) ? array() : array();
	}
}
