<?php
namespace Delicat\V10\Storefront;

use Delicat\V10\Context;
use Delicat\V10\Module;
use Delicat\V10\Shell\Icons;

defined( 'ABSPATH' ) || exit;

/**
 * Store search.
 *
 * A real <form method="get"> pointing at WordPress's own search, with a
 * type-ahead built on the browser's own <datalist>. No endpoint, no fetch, no
 * debounce, no race - and it works with JavaScript unavailable, where it is
 * simply a search box that submits.
 *
 * V9 built the type-ahead from a JSON index printed into the page, which was
 * defensible, and then registered the class that builds that index only for
 * Builder admin screens. On the storefront the class did not exist, the hero
 * checked for it before asking, the check failed on every visit, and the page
 * shipped an empty list. The box looked and typed normally; it simply had
 * nothing to search. That is why the index in V10 is built by the same module
 * that renders the box - one file, so there is nothing to forget to register.
 */
final class Search extends Module {

	/**
	 * Every page: the header and drawer both offer search, so the suggestions
	 * must exist wherever they do.
	 */
	public static function routes(): array {
		return array();
	}

	private const CACHE_KEY = 'delicat_v10_search_index';
	private const CACHE_TTL = 900;
	private const MAX       = 150;

	public function register(): void {
		add_action( 'delicat_v10_drawer_items', array( $this, 'render_box' ), 1 );

		/* The index is invalidated by the things that change it, rather than
		 * only by time - so a price edit is visible in the suggestions at once
		 * and the TTL is only a backstop. */
		foreach ( array( 'save_post_product', 'deleted_post', 'woocommerce_update_product' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
	}

	public function render_box(): void {
		$suggestions = self::index();

		printf(
			'<form class="dlx-search" role="search" method="get" action="%s" style="margin-block:var(--dlx-space-xs)">',
			esc_url( home_url( '/' ) )
		);

		printf( '<span class="dlx-search__icon">%s</span>', Icons::svg( 'search', 20 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed table.

		printf(
			'<label class="dlx-sr" for="dlx-q">%s</label>',
			esc_html__( 'Rechercher un produit', 'delicat-v10' )
		);

		printf(
			'<input id="dlx-q" type="search" name="s" autocomplete="off" enterkeyhint="search" spellcheck="false" placeholder="%s"%s>',
			esc_attr__( 'Rechercher…', 'delicat-v10' ),
			array() === $suggestions ? '' : ' list="dlx-suggestions"'
		);

		echo '<input type="hidden" name="post_type" value="product">';
		echo '</form>';

		if ( array() === $suggestions ) {
			return;
		}

		/*
		 * <datalist> is the browser's own type-ahead: it filters as the customer
		 * types, renders with the platform's own list UI, is keyboard and screen
		 * reader accessible without any ARIA of ours, and costs no script.
		 */
		echo '<datalist id="dlx-suggestions">';
		foreach ( $suggestions as $name ) {
			printf( '<option value="%s"></option>', esc_attr( $name ) );
		}
		echo '</datalist>';
	}

	/**
	 * Product names, cached.
	 *
	 * Names only - not prices, images or URLs. A suggestion list needs the
	 * string the customer is typing towards and nothing else, and keeping it to
	 * strings is what lets this ship inside a publicly cached page without
	 * leaking anything a visitor could not already see in the shop.
	 *
	 * @return string[]
	 */
	public static function index(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$products = wc_get_products(
				array(
					'limit'   => self::MAX,
					'status'  => 'publish',
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'objects',
				)
			);
		} catch ( \Throwable $error ) {
			unset( $error );
			return array();
		}

		$names = array();
		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof \WC_Product || ! $product->is_visible() ) {
				continue;
			}
			$name = trim( wp_strip_all_tags( (string) $product->get_name(), true ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		$names = array_values( array_unique( $names ) );
		set_transient( self::CACHE_KEY, $names, self::CACHE_TTL );

		return $names;
	}

	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}
}
