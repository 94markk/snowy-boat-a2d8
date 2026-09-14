<?php
/**
 * Theme supports, menus, image sizes and body classes.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

function ds_setup(): void {
	load_theme_textdomain( 'delicat-store', DS_DIR . 'languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/editor.css' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 96,
			'width'       => 320,
			'flex-height' => true,
			'flex-width'  => true,
			'unlink-homepage-logo' => true,
		)
	);
	add_theme_support(
		'editor-color-palette',
		array(
			array( 'name' => 'Marque', 'slug' => 'brand', 'color' => (string) ds_opt( 'color_brand' ) ),
			array( 'name' => 'Accent', 'slug' => 'accent', 'color' => (string) ds_opt( 'color_accent' ) ),
			array( 'name' => 'Encre', 'slug' => 'ink', 'color' => (string) ds_opt( 'color_ink' ) ),
			array( 'name' => 'Fond', 'slug' => 'ground', 'color' => (string) ds_opt( 'color_ground' ) ),
			array( 'name' => 'Surface', 'slug' => 'surface', 'color' => (string) ds_opt( 'color_surface' ) ),
		)
	);

	/* WooCommerce */
	add_theme_support(
		'woocommerce',
		array(
			'thumbnail_image_width' => 600,
			'single_image_width'    => 1000,
			'product_grid'          => array(
				'default_rows'    => 4,
				'min_rows'        => 1,
				'max_rows'        => 12,
				'default_columns' => 4,
				'min_columns'     => 2,
				'max_columns'     => 6,
			),
		)
	);
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	register_nav_menus(
		array(
			'primary' => 'Menu principal (desktop et tiroir)',
			'drawer'  => 'Menu du tiroir mobile (optionnel, sinon le menu principal)',
			'footer'  => 'Menu du pied de page',
		)
	);

	add_image_size( 'ds-card', 600, 600, true );
	add_image_size( 'ds-hero', 1600, 900, true );

	$GLOBALS['content_width'] = 1200;
}
add_action( 'after_setup_theme', 'ds_setup' );

/**
 * Body classes drive the layout: the fixed chrome states what it rendered
 * and CSS turns the class into the bottom band measurement.
 *
 * @param string[] $classes Classes.
 * @return string[]
 */
function ds_body_class( array $classes ): array {
	$classes[] = 'ds';
	$classes[] = 'ds-has-tabbar';
	if ( ds_is_woo() ) {
		if ( is_product() ) {
			$classes[] = 'ds-has-dock';
		}
		if ( is_cart() || is_checkout() || is_account_page() ) {
			$classes[] = 'ds-private';
		}
	}
	if ( is_front_page() ) {
		$classes[] = 'ds-home';
	}
	if ( has_custom_logo() ) {
		$classes[] = 'ds-has-logo';
	}
	return $classes;
}
add_filter( 'body_class', 'ds_body_class' );

/**
 * Shorter excerpts for cards.
 */
add_filter(
	'excerpt_length',
	static function () {
		return 24;
	}
);
add_filter(
	'excerpt_more',
	static function () {
		return '…';
	}
);

/**
 * Fallback for the primary menu when nothing has been assigned yet: the
 * store still navigates on day one.
 */
function ds_menu_fallback(): array {
	$items = array(
		array( 'label' => 'Accueil', 'url' => home_url( '/' ) ),
	);
	if ( ds_is_woo() ) {
		$items[] = array( 'label' => 'Boutique', 'url' => ds_shop_url() );
		$cats    = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 6,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_array( $cats ) ) {
			foreach ( $cats as $cat ) {
				if ( 'uncategorized' === $cat->slug || 'non-classe' === $cat->slug ) {
					continue;
				}
				$items[] = array( 'label' => $cat->name, 'url' => get_term_link( $cat ) );
			}
		}
	}
	foreach ( array( 'bon-kliyan' => 'Bon Kliyan', 'comment-ca-marche' => 'Comment ça marche', 'support' => 'Support' ) as $slug => $label ) {
		$url = ds_page_url( $slug );
		if ( '' !== $url ) {
			$items[] = array( 'label' => $label, 'url' => $url );
		}
	}
	return $items;
}

/**
 * Print a nav menu, using the fallback when the location is empty.
 */
function ds_nav( string $location, string $class ): void {
	if ( has_nav_menu( $location ) ) {
		wp_nav_menu(
			array(
				'theme_location' => $location,
				'container'      => false,
				'menu_class'     => $class,
				'depth'          => 2,
				'fallback_cb'    => false,
			)
		);
		return;
	}
	if ( 'drawer' === $location && has_nav_menu( 'primary' ) ) {
		ds_nav( 'primary', $class );
		return;
	}
	echo '<ul class="' . esc_attr( $class ) . '">';
	foreach ( ds_menu_fallback() as $item ) {
		echo '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a></li>';
	}
	echo '</ul>';
}

/**
 * The tab bar's five slots. Wallet appears when TeraWallet is installed,
 * otherwise the slot goes to Search so nobody gets a dead tab.
 *
 * @return array<int,array{key:string,label:string,icon:string,url:string,active:bool,count?:int}>
 */
function ds_tabs(): array {
	$woo   = ds_is_woo();
	$tabs  = array();
	$tabs[] = array(
		'key'    => 'home',
		'label'  => 'Accueil',
		'icon'   => 'home',
		'url'    => home_url( '/' ),
		'active' => is_front_page(),
	);
	$tabs[] = array(
		'key'    => 'shop',
		'label'  => 'Boutique',
		'icon'   => 'grid',
		'url'    => ds_shop_url(),
		'active' => $woo && ( is_shop() || is_product_taxonomy() || is_product() ),
	);
	if ( $woo && function_exists( 'ds_wallet_active' ) && ds_wallet_active() ) {
		$tabs[] = array(
			'key'    => 'wallet',
			'label'  => 'Wallet',
			'icon'   => 'wallet',
			'url'    => ds_wallet_url(),
			'active' => function_exists( 'ds_is_wallet_page' ) && ds_is_wallet_page(),
		);
	} else {
		$tabs[] = array(
			'key'    => 'search',
			'label'  => 'Recherche',
			'icon'   => 'search',
			'url'    => ds_search_url(),
			'active' => is_search(),
		);
	}
	$tabs[] = array(
		'key'    => 'cart',
		'label'  => 'Panier',
		'icon'   => 'cart',
		'url'    => ds_cart_url(),
		'active' => $woo && ( is_cart() || is_checkout() ),
		'count'  => ds_cart_count(),
	);
	$tabs[] = array(
		'key'    => 'account',
		'label'  => 'Compte',
		'icon'   => 'user',
		'url'    => ds_account_url(),
		'active' => $woo && is_account_page() && ! ( function_exists( 'ds_is_wallet_page' ) && ds_is_wallet_page() ),
	);
	return $tabs;
}

/**
 * Speculation rules: prefetch product and category links on touch-down.
 * Private pages (cart, checkout, account) are excluded, and prerender is
 * only requested on a fast connection — the browser ignores the block
 * entirely where it is unsupported.
 */
function ds_speculation_rules(): void {
	if ( is_admin() || ( ds_is_woo() && ( is_cart() || is_checkout() || is_account_page() ) ) ) {
		return;
	}
	$rules = array(
		'prefetch' => array(
			array(
				'source'    => 'document',
				'where'     => array(
					'and' => array(
						array( 'href_matches' => '/*' ),
						array( 'not' => array( 'href_matches' => array( '/panier/*', '/cart/*', '/commander/*', '/checkout/*', '/mon-compte/*', '/my-account/*', '/wp-admin/*', '/wp-login.php', '/*\\?*add-to-cart=*', '/*\\?*remove_item=*' ) ) ),
					),
				),
				'eagerness' => 'moderate',
			),
		),
	);
	echo '<script type="speculationrules">' . wp_json_encode( $rules ) . '</script>' . "\n";
}
add_action( 'wp_footer', 'ds_speculation_rules', 30 );
