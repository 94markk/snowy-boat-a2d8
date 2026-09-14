<?php
namespace Delicat\V10\Shell;

use Delicat\V10\Compat\V9Options;

defined( 'ABSPATH' ) || exit;

/**
 * The store's navigation destinations, resolved once and shared by every piece
 * of chrome that shows them - the tab bar, the drawer and the desktop header.
 *
 * V9 had three separate navigation sources that could and did disagree: a menu
 * builder option, a bottom-nav shortcode with its own five-item slice, and a
 * shell module with a hard-coded list. A customer could see different labels
 * in the drawer and the tab bar for the same destination.
 */
final class Nav {

	/** @var array<int,array<string,mixed>>|null */
	private static $items = null;

	/**
	 * @return array<int,array{key:string,label:string,url:string,icon:string}>
	 */
	public static function items(): array {
		if ( null !== self::$items ) {
			return self::$items;
		}

		$items = self::from_v9();

		if ( array() === $items ) {
			$items = self::fallback();
		}

		/**
		 * The store's navigation, for anything that needs to add or reorder.
		 *
		 * @param array<int,array<string,mixed>> $items
		 */
		$items = apply_filters( 'delicat_v10_nav_items', $items );

		self::$items = is_array( $items ) ? array_values( $items ) : self::fallback();
		return self::$items;
	}

	/** The five destinations the tab bar shows, in order. */
	public static function tabs(): array {
		return array_slice( self::items(), 0, 5 );
	}

	/**
	 * Read the merchant's configured menu, if V9 saved one.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function from_v9(): array {
		$out = array();

		foreach ( V9Options::menu_items() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = trim( (string) ( $item['title'] ?? '' ) );
			$url   = trim( (string) ( $item['url'] ?? '' ) );

			if ( '' === $label || '' === $url ) {
				continue;
			}

			$out[] = array(
				'key'   => sanitize_key( (string) ( $item['icon'] ?? $label ) ),
				'label' => $label,
				'url'   => $url,
				'icon'  => self::icon_key( (string) ( $item['icon'] ?? '' ), $url ),
			);
		}

		return $out;
	}

	/**
	 * A sensible store without any configuration at all.
	 *
	 * Built from WooCommerce's own page settings rather than guessed paths, so
	 * a store with translated permalinks gets the right URLs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function fallback(): array {
		$items = array(
			array(
				'key'   => 'home',
				'label' => __( 'Accueil', 'delicat-v10' ),
				'url'   => home_url( '/' ),
				'icon'  => 'home',
			),
		);

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );
			if ( $shop ) {
				$items[] = array(
					'key'   => 'shop',
					'label' => __( 'Boutique', 'delicat-v10' ),
					'url'   => $shop,
					'icon'  => 'grid',
				);
			}

			$cart = wc_get_page_permalink( 'cart' );
			if ( $cart ) {
				$items[] = array(
					'key'   => 'cart',
					'label' => __( 'Panier', 'delicat-v10' ),
					'url'   => $cart,
					'icon'  => 'cart',
				);
			}

			$account = wc_get_page_permalink( 'myaccount' );
			if ( $account ) {
				$items[] = array(
					'key'   => 'account',
					'label' => __( 'Compte', 'delicat-v10' ),
					'url'   => $account,
					'icon'  => 'user',
				);
			}
		}

		return $items;
	}

	/** Map whatever V9 stored, or the destination itself, onto an icon V10 draws. */
	private static function icon_key( string $stored, string $url ): string {
		$stored = sanitize_key( $stored );
		if ( '' !== $stored && Icons::has( $stored ) ) {
			return $stored;
		}

		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		foreach ( array(
			'wallet'      => 'wallet',
			'portefeuille'=> 'wallet',
			'cart'        => 'cart',
			'panier'      => 'cart',
			'account'     => 'user',
			'compte'      => 'user',
			'shop'        => 'grid',
			'boutique'    => 'grid',
			'search'      => 'search',
		) as $needle => $icon ) {
			if ( false !== strpos( $path, $needle ) ) {
				return $icon;
			}
		}

		return '/' === $path || '' === $path ? 'home' : 'grid';
	}

	/**
	 * Is this navigation item the page currently being viewed?
	 *
	 * Compared on host plus path only. A query string or a fragment does not
	 * make the cart a different page, and a trailing slash is not a difference
	 * a customer would recognise.
	 */
	public static function is_current( string $url ): bool {
		static $here = null;

		if ( null === $here ) {
			$here = self::normalise( home_url( add_query_arg( array() ) ) );
		}

		return $here === self::normalise( $url );
	}

	private static function normalise( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path = rtrim( (string) ( $parts['path'] ?? '/' ), '/' );
		return $host . ( '' === $path ? '/' : $path );
	}

	public static function flush(): void {
		self::$items = null;
	}
}
