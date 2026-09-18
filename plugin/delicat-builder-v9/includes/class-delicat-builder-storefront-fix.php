<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC51.45 — Storefront Fix.
 *
 * Four independent, filterable repairs found in the live-site audit:
 *
 * 1. LEGACY CATEGORY 301. Menu entries still point at the old
 *    /categorie-produit/... base, which now 404s. Requests to that base are
 *    301-redirected to /product-category/... The redirect is identical for
 *    every visitor, so it is LiteSpeed/Cloudflare cache-safe.
 *
 * 2. WOO FRENCH STRINGS. WooCommerce archive/product loop strings render in
 *    English ("Showing 1-24 of 40 results", "Sort by popularity",
 *    "Choose an option", ...) because no French translation pack is loaded.
 *    A frontend-only gettext map supplies the boutique's French. It only
 *    fires when WooCommerce itself returned the untranslated msgid, so a
 *    real language pack installed later automatically wins.
 *
 * 3. SHOP TITLE. The shop page renders a lowercase "shop" H1/document title.
 *    Replaced with "Boutique" on the shop archive only.
 *
 * 4. ELEMENTOR SLIM. Extends the Native Product dequeue recipe to WooCommerce
 *    surfaces that never render Elementor widgets:
 *      - cart / checkout / my-account, only when the queried page is NOT an
 *        Elementor-built page (checked via _elementor_edit_mode), and
 *      - shop / product category / product tag archives, only when the V9
 *        native archive template is the published renderer (Elementor's
 *        archive is not in play on those requests).
 *    Guest-only cart-fragments removal matches the Native Product rules.
 *
 * Every behavior is filterable via delicat_builder_v9_storefront_fix_{feature}
 * and the module respects Core enable/safe-mode. Front-end only. Requires PHP 8.5 (gated in the main plugin file).
 */
final class Delicat_Builder_V9_Storefront_Fix {

	/** @var array<string,string>|null */
	private static $fr_map = null;

	/** @var int */
	private static $archive_loop_index = 0;

	public static function boot(): void {
		if ( wp_doing_ajax() ) {
			add_action( 'wp_ajax_delicat_builder_v9_clear_cart', array( __CLASS__, 'clear_cart' ) );
			add_action( 'wp_ajax_nopriv_delicat_builder_v9_clear_cart', array( __CLASS__, 'clear_cart' ) );
			return;
		}
		if ( is_admin() ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'legacy_category_redirect' ), 1 );
		add_filter( 'gettext', array( __CLASS__, 'gettext_fr' ), 20, 3 );
		add_filter( 'ngettext', array( __CLASS__, 'ngettext_fr' ), 20, 5 );
		add_filter( 'woocommerce_page_title', array( __CLASS__, 'shop_page_title' ), 20 );
		add_filter( 'document_title_parts', array( __CLASS__, 'shop_document_title' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'elementor_slim' ), 998 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'block_css_slim' ), 997 );
		add_filter( 'hello_elementor_header_footer', array( __CLASS__, 'suppress_theme_header_footer' ) );
		add_filter( 'hello_elementor_page_title', array( __CLASS__, 'suppress_theme_page_title' ) );
		add_filter( 'wp_page_menu', array( __CLASS__, 'suppress_fallback_page_menu' ), 10, 1 );
		add_filter( 'wp_nav_menu_args', array( __CLASS__, 'nav_menu_no_fallback' ) );
		add_action( 'wp_head', array( __CLASS__, 'native_header_css' ), 3 );
		add_filter( 'template_include', array( __CLASS__, 'native_document_template' ), 90000 );
		add_filter( 'body_class', array( __CLASS__, 'native_document_body_class' ), 8 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'native_document_assets' ), 35 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'storefront_polish_assets' ), 999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'native_only_slim' ), PHP_INT_MAX );
		add_filter( 'woocommerce_checkout_must_be_logged_in_message', array( __CLASS__, 'checkout_login_message' ), 999 );
		add_filter( 'woocommerce_gateway_description', array( __CLASS__, 'gateway_description' ), 20, 2 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'render_cart_toolbar' ), 1 );
		add_action( 'woocommerce_cart_is_empty', array( __CLASS__, 'render_empty_cart' ), 1 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'cart_item_name' ), 20, 3 );
		add_filter( 'woocommerce_cart_item_remove_link', array( __CLASS__, 'cart_item_remove_link' ), 20, 2 );
		add_action( 'wc_ajax_delicat_builder_v9_clear_cart', array( __CLASS__, 'clear_cart' ) );
		add_action( 'wc_ajax_nopriv_delicat_builder_v9_clear_cart', array( __CLASS__, 'clear_cart' ) );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'reset_archive_loop_index' ), 1 );
		add_action( 'woocommerce_before_shop_loop_item', array( __CLASS__, 'advance_archive_loop_index' ), 1 );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'archive_image_priority' ), 20, 3 );
	}

	private static function core_enabled(): bool {
		return class_exists( 'Delicat_Builder_V9_Core', false )
			&& is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) )
			&& Delicat_Builder_V9_Core::is_enabled();
	}

	/** Older cart styling/JS must stand down on classic and Block surfaces. */
	private static function native_cart_surface(): bool {
		return class_exists( 'Delicat_Builder_V9_Purchase_Native', false )
			&& is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'cart_surface_active' ) )
			&& Delicat_Builder_V9_Purchase_Native::cart_surface_active();
	}

	private static function native_checkout_shell(): bool {
		return class_exists( 'Delicat_Builder_V9_Purchase_Native', false )
			&& is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'checkout_shell_active' ) )
			&& Delicat_Builder_V9_Purchase_Native::checkout_shell_active();
	}

	private static function active(): bool {
		return self::core_enabled()
			&& ( ! is_callable( array( 'Delicat_Builder_V9_Core', 'is_safe_mode' ) ) || ! Delicat_Builder_V9_Core::is_safe_mode() );
	}

	private static function feature( string $name, bool $default = true ): bool {
		return (bool) apply_filters( 'delicat_builder_v9_storefront_fix_' . $name, $default );
	}

	/* ------------------------------------------------------------------ */
	/* 1. Legacy /categorie-produit/ -> /product-category/ 301             */
	/* ------------------------------------------------------------------ */

	public static function legacy_category_redirect(): void {
		if ( ! self::active() || ! self::feature( 'legacy_category_redirect' ) ) {
			return;
		}
		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $request ) {
			return;
		}
		$path  = (string) wp_parse_url( $request, PHP_URL_PATH );
		$query = (string) wp_parse_url( $request, PHP_URL_QUERY );
		$legacy = '/categorie-produit/';
		if ( 0 !== strpos( $path, $legacy ) ) {
			return;
		}
		$rest = substr( $path, strlen( $legacy ) );
		$rest = ltrim( (string) $rest, '/' );
		// Slug segments only; anything suspicious falls through to normal 404.
		if ( '' !== $rest && ! preg_match( '#^[a-z0-9\-_/]+/?$#i', $rest ) ) {
			return;
		}
		$target = home_url( '/product-category/' . $rest );
		if ( '' !== $query ) {
			$target .= '?' . $query;
		}
		wp_safe_redirect( esc_url_raw( $target ), 301 );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* 2. WooCommerce French strings (fallback only)                       */
	/* ------------------------------------------------------------------ */

	private static function fr_map(): array {
		if ( null !== self::$fr_map ) {
			return self::$fr_map;
		}
		self::$fr_map = array(
			// Archive result counts.
			'Showing the single result'                => 'Affichage du seul résultat',
			'Showing all %d results'                   => 'Affichage des %d résultats',
			'Showing %1$d&ndash;%2$d of %3$d results'  => 'Affichage de %1$d&ndash;%2$d sur %3$d résultats',
			'Showing %1$d&ndash;%2$d of %3$d result'   => 'Affichage de %1$d&ndash;%2$d sur %3$d résultat',
			'Showing %1$d–%2$d of %3$d results'      => 'Affichage de %1$d–%2$d sur %3$d résultats',
			// Catalog ordering.
			'Default sorting'                          => 'Tri par défaut',
			'Sort by popularity'                       => 'Trier par popularité',
			'Sort by average rating'                   => 'Trier par note moyenne',
			'Sort by latest'                           => 'Trier par nouveauté',
			'Sort by price: low to high'               => 'Trier par prix croissant',
			'Sort by price: high to low'               => 'Trier par prix décroissant',
			// Loop / product accessibility strings.
			'Price range: %1$s through %2$s'           => 'Fourchette de prix : de %1$s à %2$s',
			'This product has multiple variants. The options may be chosen on the product page' => 'Ce produit existe en plusieurs variantes. Les options se choisissent sur la page du produit',
			// Product form.
			'Choose an option'                         => 'Choisissez une option',
			'Clear'                                    => 'Effacer',
			'%s quantity'                              => 'Quantité de %s',
			'Sale!'                                    => 'Promo !',
			'Add to cart'                              => 'Ajouter au panier',
			'Select options'                           => 'Choisir les options',
			'Read more'                                => 'En savoir plus',
			'Out of stock'                             => 'Rupture de stock',
			'In stock'                                 => 'En stock',
			'Awaiting product image'                   => 'Image du produit à venir',
			// Cart, checkout and account fallbacks when the Woo language pack is absent.
			'Cart'                                     => 'Panier',
			'My account'                               => 'Mon compte',
			'Login'                                    => 'Connexion',
			'Username or email address'                => 'Nom d’utilisateur ou adresse e-mail',
			'Password'                                 => 'Mot de passe',
			'Remember me'                              => 'Se souvenir de moi',
			'Log in'                                   => 'Se connecter',
			'Lost your password?'                      => 'Mot de passe oublié ?',
			'Your cart is currently empty.'            => 'Votre panier est vide.',
			'Return to shop'                           => 'Retour à la boutique',
			'Product'                                  => 'Produit',
			'Price'                                    => 'Prix',
			'Quantity'                                 => 'Quantité',
			'Subtotal'                                 => 'Sous-total',
			'Cart totals'                              => 'Total du panier',
			'Coupon code'                              => 'Code promo',
			'Apply coupon'                             => 'Appliquer',
			'Remove this item'                         => 'Supprimer cet article',
			'Shipping'                                 => 'Livraison',
			'Discount'                                 => 'Remise',
			'Fee'                                      => 'Frais',
			'Total'                                    => 'Total',
			'Update cart'                              => 'Mettre à jour le panier',
			'Proceed to checkout'                      => 'Passer la commande',
			'Checkout'                                 => 'Paiement',
			'Billing details'                          => 'Détails de facturation',
			'Additional information'                   => 'Informations complémentaires',
			'Your order'                               => 'Votre commande',
			'Payment'                                  => 'Paiement',
			'Have a coupon?'                           => 'Vous avez un code promo ?',
			'Click here to enter your code'            => 'Cliquez ici pour saisir votre code',
			'Place order'                              => 'Passer la commande',
			'Required'                                 => 'Obligatoire',
			'Search results: &ldquo;%s&rdquo;'          => 'Résultats de recherche : &laquo; %s &raquo;',
			'You must be logged in to checkout.'       => 'Veuillez vous connecter pour finaliser votre commande.',
			// RC51.60 — full-site French coverage for the highest-traffic Woo strings.
			'%s has been added to your cart.'          => '%s a été ajouté à votre panier.',
			'%s have been added to your cart.'         => '%s ont été ajoutés à votre panier.',
			'Continue shopping'                        => 'Continuer vos achats',
			'View cart'                                => 'Voir le panier',
			'No products in the cart.'                 => 'Aucun produit dans le panier.',
			'Free!'                                    => 'Gratuit !',
			'Coupon code applied successfully.'        => 'Code promo appliqué avec succès.',
			'Coupon has been removed.'                 => 'Le code promo a été retiré.',
			'Order received'                           => 'Commande reçue',
			'Thank you. Your order has been received.' => 'Merci. Votre commande a bien été reçue.',
			'Order number:'                            => 'Numéro de commande :',
			'Date:'                                    => 'Date :',
			'Total:'                                   => 'Total :',
			'Email:'                                   => 'E-mail :',
			'Payment method:'                          => 'Moyen de paiement :',
			'Order details'                            => 'Détails de la commande',
			'Billing address'                          => 'Adresse de facturation',
			'Dashboard'                                => 'Tableau de bord',
			'Orders'                                   => 'Commandes',
			'Downloads'                                => 'Téléchargements',
			'Addresses'                                => 'Adresses',
			'Account details'                          => 'Détails du compte',
			'Log out'                                  => 'Déconnexion',
			'Qty'                                      => 'Qté',
			'Order'                                    => 'Commande',
			'Status'                                   => 'Statut',
			'Actions'                                  => 'Actions',
			'View'                                     => 'Voir',
		);
		return self::$fr_map;
	}

	public static function gettext_fr( $translated, $text, $domain ) {
		if ( 'woocommerce' !== $domain || $translated !== $text ) {
			// A real translation already applied; never override it.
			return $translated;
		}
		if ( ! self::core_enabled() || ! self::feature( 'woo_french' ) ) {
			return $translated;
		}
		$map = self::fr_map();
		return isset( $map[ $text ] ) ? $map[ $text ] : $translated;
	}

	public static function ngettext_fr( $translated, $single, $plural, $number, $domain ) {
		if ( 'woocommerce' !== $domain ) {
			return $translated;
		}
		$source = ( 1 === (int) $number ) ? $single : $plural;
		if ( $translated !== $source ) {
			return $translated;
		}
		if ( ! self::core_enabled() || ! self::feature( 'woo_french' ) ) {
			return $translated;
		}
		$map = self::fr_map();
		return isset( $map[ $source ] ) ? $map[ $source ] : $translated;
	}

	/* ------------------------------------------------------------------ */
	/* 3. Shop title                                                       */
	/* ------------------------------------------------------------------ */

	private static function is_shop_request(): bool {
		return function_exists( 'is_shop' ) && is_shop();
	}

	private static function is_product_search_request(): bool {
		if ( ! is_search() ) {
			return false;
		}
		$post_type = get_query_var( 'post_type' );
		return 'product' === $post_type || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) );
	}

	public static function shop_page_title( $title ) {
		if ( ! self::core_enabled() || ! self::feature( 'shop_title' ) ) {
			return $title;
		}
		if ( self::is_product_search_request() ) {
			return sprintf( 'Résultats pour « %s »', get_search_query() );
		}
		if ( ! self::is_shop_request() ) {
			return $title;
		}
		if ( 'shop' === strtolower( trim( (string) $title ) ) ) {
			return __( 'Boutique', 'delicat-builder-v9' );
		}
		return $title;
	}

	public static function shop_document_title( $parts ) {
		if ( ! is_array( $parts ) || ! self::core_enabled() || ! self::feature( 'shop_title' ) ) {
			return $parts;
		}
		if ( self::is_product_search_request() ) {
			$parts['title'] = sprintf( 'Résultats pour « %s »', get_search_query() );
			return $parts;
		}
		if ( ! self::is_shop_request() ) {
			return $parts;
		}
		if ( isset( $parts['title'] ) && 'shop' === strtolower( trim( (string) $parts['title'] ) ) ) {
			$parts['title'] = __( 'Boutique', 'delicat-builder-v9' );
		}
		return $parts;
	}

	/* ------------------------------------------------------------------ */
	/* 4. Elementor slim on non-Elementor Woo surfaces                     */
	/* ------------------------------------------------------------------ */

	private static function page_uses_elementor( int $page_id ): bool {
		if ( $page_id <= 0 ) {
			return false;
		}
		return 'builder' === get_post_meta( $page_id, '_elementor_edit_mode', true );
	}

	private static function native_archive_published(): bool {
		if ( ! class_exists( 'Delicat_Builder_V9_Archive_Builder', false )
			|| ! is_callable( array( 'Delicat_Builder_V9_Archive_Builder', 'is_published' ) )
			|| ! is_callable( array( 'Delicat_Builder_V9_Archive_Builder', 'current_target' ) ) ) {
			return false;
		}
		$target = (string) Delicat_Builder_V9_Archive_Builder::current_target();
		return ( '' !== $target && Delicat_Builder_V9_Archive_Builder::is_published( $target ) )
			|| Delicat_Builder_V9_Archive_Builder::is_published( 'global' );
	}

	private static function slim_context(): bool {
		// Woo account/cart/checkout pages, only when not built with Elementor.
		$page_checks = array( 'is_cart', 'is_checkout', 'is_account_page' );
		foreach ( $page_checks as $check ) {
			if ( function_exists( $check ) && call_user_func( $check ) ) {
				return ! self::page_uses_elementor( absint( get_queried_object_id() ) );
			}
		}
		// Woo archives, only when the V9 native archive owns the render.
		$archive = ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() );
		if ( $archive ) {
			return self::native_archive_published();
		}
		return false;
	}

	public static function elementor_slim(): void {
		if ( ! self::active() || ! self::feature( 'elementor_slim' ) || ! self::slim_context() ) {
			return;
		}

		foreach ( array( 'elementor-frontend', 'elementor-pro', 'elementor-icons', 'eicons', 'elementor-common', 'swiper', 'e-swiper' ) as $handle ) {
			wp_dequeue_style( $handle );
		}
		foreach ( array( 'elementor-frontend', 'elementor-pro', 'elementor-common', 'elementor-frontend-modules', 'elementor-webpack-runtime', 'swiper' ) as $handle ) {
			wp_dequeue_script( $handle );
		}

		// Per-post Elementor CSS bundles (elementor-post-123) are dead weight here.
		if ( self::feature( 'elementor_slim_post_css' ) ) {
			$styles = wp_styles();
			if ( $styles ) {
				foreach ( (array) $styles->queue as $handle ) {
					if ( 0 === strpos( (string) $handle, 'elementor-post-' ) ) {
						wp_dequeue_style( $handle );
					}
				}
			}
		}

		// Same guest cart-fragments rule as Native Product.
		if ( ! is_user_logged_in()
			&& empty( $_COOKIE['woocommerce_items_in_cart'] )
			&& empty( $_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] ) ) {
			wp_dequeue_script( 'wc-cart-fragments' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* 5. Block CSS slim (wp-block-library / global-styles / wc-blocks)   */
	/* ------------------------------------------------------------------ */

	/**
	 * WordPress and WooCommerce enqueue block-editor stylesheets on every
	 * front-end request even when the rendered content contains no blocks:
	 * wp-block-library (+theme), the theme.json global-styles inline sheet,
	 * classic-theme-styles, and the WooCommerce Blocks bundle. On this
	 * storefront, pages are Builder/Elementor documents and product
	 * descriptions are classic-editor content, so these sheets are dead
	 * weight — often the single largest CSS payload on the page.
	 *
	 * Decisions are made per request from real evidence, never globally:
	 * the queried content is inspected for block markers, so any post or
	 * product that genuinely uses blocks keeps every stylesheet.
	 */
	private static function queried_content(): string {
		$post = null;
		if ( is_singular() ) {
			$post = get_queried_object();
		} elseif ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) ) {
			$shop_id = (int) wc_get_page_id( 'shop' );
			if ( $shop_id > 0 ) {
				$post = get_post( $shop_id );
			}
		}
		if ( $post instanceof WP_Post ) {
			return (string) $post->post_content;
		}
		return '';
	}

	private static function block_slim_context(): bool {
		if ( is_admin() || is_customize_preview() ) {
			return false;
		}
		if ( is_singular() ) {
			return true;
		}
		if ( function_exists( 'is_woocommerce' ) && ( is_shop() || is_product_category() || is_product_tag() ) ) {
			return true;
		}
		return false;
	}

	public static function block_css_slim(): void {
		if ( ! self::active() || ! self::feature( 'block_css_slim' ) || ! self::block_slim_context() ) {
			return;
		}

		$content    = self::queried_content();
		$has_blocks = ( false !== strpos( $content, '<!-- wp:' ) );

		if ( ! $has_blocks ) {
			foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ) as $handle ) {
				wp_dequeue_style( $handle );
			}
			// theme.json duotone SVG filters accompany global-styles.
			remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
			remove_action( 'wp_footer', 'wp_global_styles_render_svg_filters' );
		}

		if ( ! self::feature( 'wc_blocks_css_slim' ) ) {
			return;
		}
		// Never touch cart / checkout / my-account: a block-based checkout
		// must keep its stylesheets even though its page content is a block.
		$protected = ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );
		if ( $protected ) {
			return;
		}
		$has_wc_blocks = ( false !== strpos( $content, '<!-- wp:woocommerce' ) );
		if ( ! $has_wc_blocks ) {
			foreach ( array( 'wc-blocks-style', 'wc-blocks-packages-style', 'wc-blocks-vendors-style', 'wc-all-blocks-style' ) as $handle ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* 6. Native Header Engine (post-Elementor theme header takeover)      */
	/* ------------------------------------------------------------------ */

	/**
	 * Elementor's Theme Builder header previously replaced the theme's own
	 * header template. With Elementor removed, the theme falls back to its
	 * default header: raw logo, site tagline, and a wp_page_menu listing of
	 * every published page (leaking test/internal pages), plus raw page
	 * titles like "HOMEPAGE" and "Cart". Header Studio is the storefront's
	 * header engine, so the theme layer is neutralized whenever the native
	 * Header Studio runtime owns the page:
	 *
	 * - Hello Elementor theme: its documented filters disable the theme
	 *   header/footer templates and the raw page title outright.
	 * - Any theme: the wp_page_menu fallback is emptied (no page leak) and
	 *   nav menus lose their fallback callback.
	 * - Any theme: a tiny scoped stylesheet hides common theme
	 *   header/footer containers, never anything carrying delicat markup.
	 *
	 * The gate is independent from the optional Shell and from Compatibility
	 * Safe Mode. Disabling Builder Core still returns full ownership to the
	 * active WordPress theme.
	 */
	private static function native_header_engine_active(): bool {
		if ( is_admin() || ! self::core_enabled() || ! self::feature( 'native_header_engine' ) ) {
			return false;
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) ) {
			return false;
		}
		return ! is_callable( array( 'Delicat_Builder_V9_Header_Studio_8', 'should_auto_render' ) )
			|| Delicat_Builder_V9_Header_Studio_8::should_auto_render();
	}

	public static function suppress_theme_header_footer( $value ) {
		return self::native_header_engine_active() ? false : $value;
	}

	public static function suppress_theme_page_title( $value ) {
		return self::native_header_engine_active() ? false : $value;
	}

	public static function suppress_fallback_page_menu( $menu ) {
		return self::native_header_engine_active() ? '' : $menu;
	}

	public static function nav_menu_no_fallback( $args ) {
		if ( self::native_header_engine_active() && is_array( $args ) ) {
			// Only theme menu locations: never touch menus the Shell builds itself.
			$theme_location = isset( $args['theme_location'] ) ? (string) $args['theme_location'] : '';
			$container      = isset( $args['container_class'] ) ? (string) $args['container_class'] : '';
			if ( '' !== $theme_location && false === strpos( $container, 'delicat' ) ) {
				$args['fallback_cb'] = '__return_empty_string';
			}
		}
		return $args;
	}

	public static function native_header_css(): void {
		if ( ! self::native_header_engine_active() ) {
			return;
		}
		// Scoped to the native-header body class; :not guards keep every
		// delicat-* element visible even if a theme reuses generic classes.
		echo '<style id="delicat-native-header-engine">'
			. 'body.delicat-native-header-active #site-header,'
			. 'body.delicat-native-header-active #masthead,'
			. 'body.delicat-native-header-active header.site-header:not([class*="delicat"]),'
			. 'body.delicat-native-header-active .site-branding:not([class*="delicat"]),'
			. 'body.delicat-native-header-active .elementor-location-header,'
			. 'body.delicat-native-header-active #site-footer,'
			. 'body.delicat-native-header-active #colophon,'
			. 'body.delicat-native-header-active footer.site-footer:not([class*="delicat"]),'
			. 'body.delicat-native-header-active .elementor-location-footer'
			. '{display:none!important}'
			. '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ------------------------------------------------------------------ */
	/* 7. Native V9 document ownership (pages, commerce and 404)           */
	/* ------------------------------------------------------------------ */

	private static function native_document_request(): bool {
		if ( ! self::core_enabled() || ! self::feature( 'native_document_engine' ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return is_404() || is_search() || is_singular( 'page' );
	}

	public static function native_document_template( $template ) {
		if ( ! self::native_document_request() ) {
			return $template;
		}
		if ( is_404() ) {
			$file = 'templates/site-404.php';
		} elseif ( is_search() ) {
			$file = 'templates/native-search.php';
		} else {
			$file = 'templates/native-page.php';
		}
		$candidate = DELICAT_BUILDER_V9_DIR . $file;
		return is_readable( $candidate ) ? $candidate : $template;
	}

	public static function native_document_body_class( $classes ) {
		if ( ! self::native_document_request() ) {
			return $classes;
		}
		$classes[] = 'delicat-native-document';
		if ( is_404() ) {
			$classes[] = 'delicat-site-404';
		} elseif ( is_search() ) {
			$classes[] = 'delicat-site-search';
			$classes[] = 'delicat-native-search';
		} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
			if ( ! self::native_cart_surface() ) {
				$classes[] = 'delicat-native-cart';
				if ( function_exists( 'WC' ) && WC()->cart && WC()->cart->is_empty() ) {
					$classes[] = 'dcn-cart-empty';
				}
			}
		} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
			if ( ! self::native_checkout_shell() ) {
				$classes[] = 'delicat-native-checkout';
			}
		} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$classes[] = 'delicat-site-account';
			$classes[] = 'delicat-native-account';
		} else {
			$classes[] = 'delicat-native-page';
		}
		return array_values( array_unique( (array) $classes ) );
	}

	public static function native_document_assets(): void {
		if ( ! self::native_document_request() ) {
			return;
		}
		$path = DELICAT_BUILDER_V9_DIR . 'assets/css/native-document.css';
		if ( is_file( $path ) ) {
			wp_enqueue_style( 'delicat-builder-v9-native-document', DELICAT_BUILDER_V9_URL . 'assets/css/native-document.css', array(), DELICAT_BUILDER_V9_VERSION );
		}
		/*
		 * RC51.59: site.css has exactly one owner again. The Site module is
		 * routed onto 404/search/account/order-received by the Runtime Router
		 * and enqueues the `delicat-builder-v9-site` handle itself; the copy
		 * that lived here was the duplicate-ownership bug.
		 */
	}

	/**
	 * RC51.59: the polish sheet only contains selectors for Woo surfaces,
	 * Builder/native documents and archives. Its two genuinely global rules
	 * (base font, drawer-menu containment) moved into theme-system.css, so
	 * every other route — including Native-Product-owned product documents,
	 * which ship their own complete stylesheet — now skips the 24 KB file.
	 */
	private static function polish_surface(): bool {
		/* 9.1 RC4: the managed V9 homepage owns its complete component CSS.
		 * Loading Storefront Polish there added ~23 KB of Woo/native rules and,
		 * more importantly, its legacy late drawer rule could override Menu Runtime.
		 * Standard/theme front pages still keep the polish layer. */
		if (
			function_exists( 'is_front_page' )
			&& is_front_page()
			&& function_exists( 'delicat_builder_v9_is_managed_page' )
			&& delicat_builder_v9_is_managed_page( absint( get_queried_object_id() ) )
		) {
			return false;
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			if (
				class_exists( 'Delicat_Builder_V9_Native_Product', false )
				&& is_callable( array( 'Delicat_Builder_V9_Native_Product', 'is_active_product' ) )
				&& Delicat_Builder_V9_Native_Product::is_active_product( absint( get_queried_object_id() ) )
			) {
				$np = is_callable( array( 'Delicat_Builder_V9_Native_Product', 'settings' ) )
					? Delicat_Builder_V9_Native_Product::settings()
					: array();
				if ( ! empty( $np['force_native_template'] ) ) {
					return false;
				}
			}
			return true;
		}
		if (
			( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
			|| ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
		) {
			return true;
		}
		return is_404() || is_search() || is_front_page() || is_singular( 'page' );
	}

	/**
	 * True only for full documents rendered by Builder V9. Product and archive
	 * templates own their own CSS just like standard native documents, so none
	 * of them need the active theme's public stylesheet.
	 */
	private static function native_asset_request(): bool {
		if ( self::native_document_request() ) {
			return true;
		}
		if ( function_exists( 'is_product' ) && is_product()
			&& class_exists( 'Delicat_Builder_V9_Native_Product', false )
			&& is_callable( array( 'Delicat_Builder_V9_Native_Product', 'is_active_product' ) )
			&& Delicat_Builder_V9_Native_Product::is_active_product( absint( get_queried_object_id() ) ) ) {
			$settings = is_callable( array( 'Delicat_Builder_V9_Native_Product', 'settings' ) )
				? Delicat_Builder_V9_Native_Product::settings()
				: array();
			return ! empty( $settings['force_native_template'] );
		}
		$archive = ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() );
		return $archive && self::native_archive_published();
	}

	/* ------------------------------------------------------------------ */
	/* 8. Storefront polish: shared typography + native Woo presentation   */
	/* ------------------------------------------------------------------ */

	/** Load one small visual layer; WooCommerce keeps all commerce state. */
	public static function storefront_polish_assets(): void {
		if ( ! self::active() || ! self::feature( 'storefront_polish' ) || ! self::polish_surface() ) {
			return;
		}
		$css = DELICAT_BUILDER_V9_DIR . 'assets/css/storefront-polish.css';
		if ( is_file( $css ) ) {
			wp_enqueue_style( 'delicat-builder-v9-storefront-polish', DELICAT_BUILDER_V9_URL . 'assets/css/storefront-polish.css', array(), DELICAT_BUILDER_V9_VERSION );
		}

		$is_cart = function_exists( 'is_cart' ) && is_cart() && ! self::native_cart_surface();
		$is_checkout = function_exists( 'is_checkout' )
			&& is_checkout()
			&& ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() )
			&& ! self::native_checkout_shell();
		$commerce = ( $is_cart && self::cart_count() > 0 ) || $is_checkout;
		$js = DELICAT_BUILDER_V9_DIR . 'assets/js/storefront-commerce.js';
		if ( ! $commerce || ! is_file( $js ) ) {
			return;
		}
		wp_enqueue_script( 'delicat-builder-v9-storefront-commerce', DELICAT_BUILDER_V9_URL . 'assets/js/storefront-commerce.js', array(), DELICAT_BUILDER_V9_VERSION, true );
		if ( $is_cart ) {
			wp_localize_script(
				'delicat-builder-v9-storefront-commerce',
				'DelicatStorefrontCommerce',
				array(
					'clearEndpoint' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
					'clearAction'   => 'delicat_builder_v9_clear_cart',
					'clearNonce'    => wp_create_nonce( 'delicat_builder_v9_clear_cart' ),
					'clearConfirm'  => __( 'Vider tout le panier ?', 'delicat-builder-v9' ),
					'clearError'    => __( 'Impossible de vider le panier. Réessayez.', 'delicat-builder-v9' ),
				)
			);
		}
	}

	/** Plain text prevents third-party checkout wrappers from escaping HTML. */
	public static function checkout_login_message( $message ) {
		if ( ! self::active() ) {
			return $message;
		}
		return __( 'Veuillez vous connecter pour finaliser votre commande.', 'delicat-builder-v9' );
	}

	/**
	 * TeraWallet may put its registered balance shortcode in a gateway
	 * description. This filter is global so classic checkout, wc-ajax and the
	 * Checkout Block's Store API receive the same clean description. Only the
	 * exact trusted token is executed; every other shortcode remains untouched.
	 */
	public static function gateway_description( $description, $gateway_id = '' ) {
		unset( $gateway_id );
		if ( ! is_string( $description ) || false === stripos( $description, '[terawallet_balance' ) ) {
			return $description;
		}

		$rendered = preg_replace_callback(
			'/\[terawallet_balance(?:\s[^\]]*)?\]/i',
			static function ( $match ) {
				if ( ! shortcode_exists( 'terawallet_balance' ) ) {
					return '';
				}
				$value = do_shortcode( $match[0] );
				return is_string( $value )
					? preg_replace( '/\[\/?terawallet_balance[^\]]*\]/i', '', $value )
					: '';
			},
			$description
		);
		return is_string( $rendered ) ? $rendered : '';
	}

	private static function cart_count(): int {
		return ( function_exists( 'WC' ) && WC()->cart ) ? absint( WC()->cart->get_cart_contents_count() ) : 0;
	}

	private static function icon_svg( string $name ): string {
		$icons = array(
			'cart'  => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 4h2l2.2 10.1a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 1.9-1.4L21 7H6.1M9.5 20a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm9 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg>',
			'trash' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"/></svg>',
		);
		return $icons[ $name ] ?? '';
	}

	/** Reference-inspired cart heading without replacing the Woo cart form. */
	public static function render_cart_toolbar(): void {
		if ( self::native_cart_surface() ) {
			return;
		}
		if ( ! self::active() || ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}
		$count = self::cart_count();
		if ( $count < 1 ) {
			return;
		}
		echo '<header class="dcn-cart-toolbar">'
			. '<div><h1>' . esc_html__( 'Panier', 'delicat-builder-v9' ) . '</h1><p>'
			. esc_html( sprintf( _n( '%d article', '%d articles', $count, 'delicat-builder-v9' ), $count ) )
			. '</p></div><button type="button" class="dcn-clear-cart" data-dcn-clear-cart>'
			. self::icon_svg( 'trash' ) . '<span>' . esc_html__( 'Tout effacer', 'delicat-builder-v9' ) . '</span></button>'
			. '</header><p class="dcn-swipe-hint">' . esc_html__( 'Glissez un article vers la gauche pour le supprimer.', 'delicat-builder-v9' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Premium empty state injected through WooCommerce's native empty hook. */
	public static function render_empty_cart(): void {
		if ( self::native_cart_surface() ) {
			return;
		}
		if ( ! self::active() || ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
		if ( ! is_string( $shop ) || '' === $shop ) {
			$shop = home_url( '/shop/' );
		}
		echo '<section class="dcn-empty-shell"><header class="dcn-cart-toolbar dcn-cart-toolbar-empty"><div><h1>'
			. esc_html__( 'Panier', 'delicat-builder-v9' ) . '</h1><p>' . esc_html__( '0 article', 'delicat-builder-v9' )
			. '</p></div></header><div class="dcn-empty-card"><span class="dcn-empty-icon">' . self::icon_svg( 'cart' ) . '</span><h2>'
			. esc_html__( 'Votre panier est vide', 'delicat-builder-v9' ) . '</h2><p>'
			. esc_html__( 'Découvrez nos produits numériques et ajoutez votre premier article.', 'delicat-builder-v9' )
			. '</p><a class="button dcn-start-shopping" href="' . esc_url( $shop ) . '">'
			. esc_html__( 'Commencer vos achats', 'delicat-builder-v9' ) . '</a></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Add a quiet category label while preserving Woo's linked product name. */
	public static function cart_item_name( $name, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );
		if ( self::native_cart_surface() ) {
			return $name;
		}
		if ( ! self::active() || ! function_exists( 'is_cart' ) || ! is_cart() || ! is_array( $cart_item ) ) {
			return $name;
		}
		$product = $cart_item['data'] ?? null;
		if ( ! is_object( $product ) || ! is_callable( array( $product, 'get_id' ) ) ) {
			return $name;
		}
		$product_id = absint( $product->get_id() );
		if ( is_callable( array( $product, 'is_type' ) ) && $product->is_type( 'variation' ) && is_callable( array( $product, 'get_parent_id' ) ) ) {
			$product_id = absint( $product->get_parent_id() );
		}
		$terms = get_the_terms( $product_id, 'product_cat' );
		$label = '';
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof WP_Term && 'uncategorized' !== $term->slug ) {
					$label = $term->name;
					break;
				}
			}
		}
		$badge = '' !== $label ? '<small class="dcn-cart-category">' . esc_html( $label ) . '</small>' : '';
		return '<span class="dcn-cart-product-copy">' . $badge . '<span class="dcn-cart-product-name">' . wp_kses_post( $name ) . '</span></span>';
	}

	/** Replace the fragile × glyph only; URL, nonce and Woo behavior stay native. */
	public static function cart_item_remove_link( $link, $cart_item_key ) {
		unset( $cart_item_key );
		if ( self::native_cart_surface() ) {
			return $link;
		}
		if ( ! self::active() || ! function_exists( 'is_cart' ) || ! is_cart() || ! is_string( $link ) || false === stripos( $link, '<a' ) ) {
			return $link;
		}
		$icon = self::icon_svg( 'trash' );
		return preg_replace_callback(
			'#(<a\b[^>]*>).*?(</a>)#is',
			static function ( $matches ) use ( $icon ) {
				return $matches[1] . $icon . '<span class="screen-reader-text">Supprimer</span>' . $matches[2];
			},
			$link,
			1
		) ?: $link;
	}

	/** Same-origin Woo AJAX mutation; WC owns session totals and notices. */
	public static function clear_cart(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			wp_send_json_error( array( 'message' => __( 'Méthode non autorisée.', 'delicat-builder-v9' ) ), 405 );
		}
		check_ajax_referer( 'delicat_builder_v9_clear_cart', 'security' );
		if ( class_exists( 'Delicat_Builder_V9_Security', false ) && ! Delicat_Builder_V9_Security::rate_limit_allowed( 'clear_cart', 10, 60 ) ) {
			wp_send_json_error( array( 'message' => __( 'Trop de requêtes. Réessayez dans un instant.', 'delicat-builder-v9' ) ), 429 );
		}
		if ( function_exists( 'WC' ) && ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Panier indisponible.', 'delicat-builder-v9' ) ), 503 );
		}
		WC()->cart->empty_cart();
		wp_send_json_success( array( 'count' => 0, 'cart_hash' => WC()->cart->get_cart_hash() ) );
	}

	/** First visible archive row paints immediately; the remaining catalog stays lazy. */
	public static function reset_archive_loop_index(): void {
		self::$archive_loop_index = 0;
	}

	public static function advance_archive_loop_index(): void {
		self::$archive_loop_index++;
	}

	public static function archive_image_priority( $attr, $attachment, $size ) {
		unset( $attachment, $size );
		if ( ! is_array( $attr ) || self::$archive_loop_index < 1 || self::$archive_loop_index > 4 || ! self::native_archive_published() ) {
			return $attr;
		}
		$archive = ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() );
		if ( ! $archive ) {
			return $attr;
		}
		$attr['loading'] = 'eager';
		$attr['decoding'] = 'async';
		if ( 1 === self::$archive_loop_index ) {
			$attr['fetchpriority'] = 'high';
		}
		return $attr;
	}

	/** Remove the inactive theme/Elementor layer only after V9 owns the document. */
	public static function native_only_slim(): void {
		if ( ! self::native_asset_request() || ! self::feature( 'native_only_slim' ) ) {
			return;
		}
		$content = self::queried_content();
		$has_blocks = false !== strpos( $content, '<!-- wp:' );
		$has_wc_blocks = false !== strpos( $content, '<!-- wp:woocommerce' )
			|| (
				class_exists( 'Delicat_Builder_V9_Purchase_Native', false )
				&& (
					( is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'cart_block_active' ) ) && Delicat_Builder_V9_Purchase_Native::cart_block_active() )
					|| ( is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'checkout_block_active' ) ) && Delicat_Builder_V9_Purchase_Native::checkout_block_active() )
				)
			);
		$has_blocks = $has_blocks || $has_wc_blocks;
		$is_product = function_exists( 'is_product' ) && is_product();
		$is_cart_checkout = ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() );
		$styles = wp_styles();
		if ( $styles ) {
			foreach ( (array) $styles->queue as $handle ) {
				$registered = $styles->registered[ $handle ] ?? null;
				$src = is_object( $registered ) ? (string) $registered->src : '';
				if ( false !== strpos( $src, '/themes/' ) || false !== strpos( $src, '/plugins/elementor' ) ) {
					wp_dequeue_style( $handle );
				}
				if ( false !== strpos( $src, '/plugins/astra-sites/' ) ) {
					wp_dequeue_style( $handle );
				}
				if ( false !== strpos( $src, 'fonts.googleapis.com' ) || false !== strpos( $src, 'fonts.gstatic.com' ) ) {
					wp_dequeue_style( $handle );
				}
				if ( ! $has_blocks && in_array( $handle, array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ), true ) ) {
					wp_dequeue_style( $handle );
				}
				if ( ( $is_product || $is_cart_checkout ) && ! $has_wc_blocks && false !== strpos( $src, '/woocommerce/assets/client/blocks/' ) ) {
					wp_dequeue_style( $handle );
				}
				if ( $is_product && ( false !== strpos( $src, '/photoswipe/' ) || false !== strpos( $src, '/flexslider/' ) ) ) {
					wp_dequeue_style( $handle );
				}
				if ( ( $is_product || $is_cart_checkout ) && ( false !== strpos( $src, '/hostinger-reach/' ) || false !== strpos( $src, '/yith-woocommerce-ajax-navigation/assets/css/shortcodes' ) ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}
		$scripts = wp_scripts();
		if ( $scripts ) {
			foreach ( (array) $scripts->queue as $handle ) {
				$registered = $scripts->registered[ $handle ] ?? null;
				$src = is_object( $registered ) ? (string) $registered->src : '';
				if ( false !== strpos( $src, '/plugins/elementor' ) || false !== strpos( $src, '/plugins/astra-sites/' ) ) {
					wp_dequeue_script( $handle );
				}
				if ( $is_product && ( false !== strpos( $src, '/jquery.zoom' ) || false !== strpos( $src, '/flexslider/' ) || false !== strpos( $src, '/photoswipe/' ) ) ) {
					wp_dequeue_script( $handle );
				}
				if ( ( $is_product || $is_cart_checkout ) && false !== strpos( $src, '/hostinger-reach/' ) ) {
					wp_dequeue_script( $handle );
				}
			}
		}
	}
}
