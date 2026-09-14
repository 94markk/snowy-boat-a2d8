<?php
/**
 * WooCommerce integration. The theme renders and restyles; every price,
 * stock state, total, tax and payment decision stays WooCommerce's.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------------
 * Wrappers
 * ---------------------------------------------------------------------- */

remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );

/* The page wrapper (<main>, container, breadcrumb) is printed by woocommerce.php. */

/* ------------------------------------------------------------------------
 * Catalogue queries (shared by rails and pages)
 * ---------------------------------------------------------------------- */

/**
 * Fetch products for a rail.
 *
 * @param array<string,mixed> $args  limit, orderby (popularity|date|price|rand|title), category (slug), tag (slug), on_sale (bool), include (ids).
 * @return WC_Product[]
 */
function ds_products( array $args = array() ): array {
	$limit = max( 1, min( 48, (int) ( $args['limit'] ?? 8 ) ) );
	$query = array(
		'status'     => 'publish',
		'limit'      => $limit,
		'visibility' => 'catalog',
		'return'     => 'objects',
	);
	switch ( $args['orderby'] ?? 'date' ) {
		case 'popularity':
			$query['orderby']  = 'meta_value_num';
			$query['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query['order']    = 'DESC';
			break;
		case 'price':
			$query['orderby'] = 'price';
			$query['order']   = 'ASC';
			break;
		case 'rand':
			$query['orderby'] = 'rand';
			break;
		case 'title':
			$query['orderby'] = 'title';
			$query['order']   = 'ASC';
			break;
		default:
			$query['orderby'] = 'date';
			$query['order']   = 'DESC';
	}
	if ( ! empty( $args['category'] ) ) {
		$query['category'] = array( sanitize_title( (string) $args['category'] ) );
	}
	if ( ! empty( $args['tag'] ) ) {
		$query['tag'] = array( sanitize_title( (string) $args['tag'] ) );
	}
	if ( ! empty( $args['on_sale'] ) ) {
		$ids = wc_get_product_ids_on_sale();
		if ( array() === $ids ) {
			return array();
		}
		$query['include'] = $ids;
	}
	if ( ! empty( $args['include'] ) ) {
		$query['include'] = array_map( 'intval', (array) $args['include'] );
	}
	$products = wc_get_products( $query );
	return array_values(
		array_filter(
			$products,
			static function ( $p ): bool {
				return $p instanceof WC_Product && $p->is_visible();
			}
		)
	);
}

/* ------------------------------------------------------------------------
 * Product card — one renderer for rails, grids, archives and search
 * ---------------------------------------------------------------------- */

/**
 * Card markup.
 *
 * @param WC_Product          $product Product.
 * @param array<string,mixed> $args    eager (bool) for the first cards.
 */
function ds_product_card( WC_Product $product, array $args = array() ): string {
	$badges = ds_product_badges( $product );
	$link   = $product->get_permalink();
	$name   = $product->get_name();
	$eager  = ! empty( $args['eager'] );

	$image = $product->get_image(
		'woocommerce_thumbnail',
		array(
			'class'    => 'ds-card__img',
			'loading'  => $eager ? 'eager' : 'lazy',
			'decoding' => 'async',
			'alt'      => $name,
		)
	);

	$out  = '<article class="ds-card" data-product="' . (int) $product->get_id() . '">';
	$out .= '<a class="ds-card__media" href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">' . $image;
	if ( $badges['topic'] ) {
		$out .= '<span class="ds-card__badge ds-card__badge--topic">' . ds_badge_html( $badges['topic'] ) . '</span>';
	}
	if ( $badges['status'] ) {
		$out .= '<span class="ds-card__badge ds-card__badge--status">' . ds_badge_html( $badges['status'] ) . '</span>';
	}
	$out .= '</a>';
	$out .= '<div class="ds-card__body">';
	$out .= '<h3 class="ds-card__title"><a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a></h3>';
	$out .= '<div class="ds-card__row">';
	$out .= '<span class="ds-card__price">' . wp_kses_post( $product->get_price_html() ) . '</span>';
	$out .= ds_card_action( $product );
	$out .= '</div>';
	if ( $badges['instant'] ) {
		$out .= '<span class="ds-card__instant">' . ds_icon( 'bolt' ) . 'Livraison instantanée</span>';
	}
	$out .= '</div></article>';
	return $out;
}

/**
 * The card's action: a one-tap add for a simple product that asks nothing,
 * otherwise a link to the product where the fields are.
 */
function ds_card_action( WC_Product $product ): string {
	$quick = (int) ds_opt( 'card_button' ) === 1
		&& $product->is_type( 'simple' )
		&& $product->is_purchasable()
		&& $product->is_in_stock()
		&& ! ds_fields_has_required( ds_fields_config_for( $product ) );

	if ( $quick ) {
		return '<a href="' . esc_url( $product->add_to_cart_url() ) . '" class="ds-card__add add_to_cart_button ajax_add_to_cart" data-product_id="' . (int) $product->get_id() . '" data-product_sku="' . esc_attr( $product->get_sku() ) . '" data-quantity="1" rel="nofollow" aria-label="' . esc_attr( 'Ajouter « ' . $product->get_name() . ' » au panier' ) . '">' . ds_icon( 'plus' ) . '</a>';
	}
	return '<a href="' . esc_url( $product->get_permalink() ) . '" class="ds-card__add ds-card__add--view" aria-label="' . esc_attr( 'Voir « ' . $product->get_name() . ' »' ) . '">' . ds_icon( 'arrow' ) . '</a>';
}

/**
 * A rail or grid of cards.
 *
 * @param WC_Product[] $products Products.
 * @param string       $layout   rail|grid.
 */
function ds_product_list( array $products, string $layout = 'rail' ): string {
	if ( array() === $products ) {
		return '';
	}
	$out = '<div class="ds-products ds-products--' . esc_attr( $layout ) . '">';
	foreach ( $products as $i => $product ) {
		$out .= ds_product_card( $product, array( 'eager' => $i < 2 ) );
	}
	return $out . '</div>';
}

/* ------------------------------------------------------------------------
 * Shop / category archive
 * ---------------------------------------------------------------------- */

add_filter(
	'loop_shop_columns',
	static function (): int {
		return 4;
	}
);
add_filter(
	'loop_shop_per_page',
	static function (): int {
		return 24;
	}
);

/* Toolbar: result count + ordering in one row. */
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
add_action( 'woocommerce_before_shop_loop', 'ds_archive_head', 5 );
add_action(
	'woocommerce_before_shop_loop',
	static function (): void {
		echo '<div class="ds-toolbar">';
		woocommerce_result_count();
		woocommerce_catalog_ordering();
		echo '</div>';
	},
	20
);
add_filter( 'woocommerce_show_page_title', '__return_false' );
remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );
remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );

/**
 * Archive heading, description and the category chip strip.
 */
function ds_archive_head(): void {
	echo '<header class="ds-archive-head">';
	echo '<h1 class="ds-archive-head__title">' . esc_html( woocommerce_page_title( false ) ) . '</h1>';
	if ( is_product_taxonomy() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term && '' !== trim( $term->description ) ) {
			echo '<div class="ds-archive-head__desc">' . wp_kses_post( wpautop( $term->description ) ) . '</div>';
		}
	}
	echo '</header>';
	if ( is_shop() || is_product_category() ) {
		get_template_part( 'template-parts/home/chips', null, array( 'title' => '' ) );
	}
}

/**
 * Search results for products use the shop layout.
 */
add_action(
	'pre_get_posts',
	static function ( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}
		if ( '' === (string) $query->get( 'post_type' ) ) {
			$query->set( 'post_type', 'product' );
		}
	}
);

/* ------------------------------------------------------------------------
 * Single product
 * ---------------------------------------------------------------------- */

/* Gallery + summary share one grid on desktop. */
add_action(
	'woocommerce_before_single_product_summary',
	static function (): void {
		echo '<div class="ds-product__top">';
	},
	1
);
add_action(
	'woocommerce_after_single_product_summary',
	static function (): void {
		echo '</div>';
	},
	1
);

remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50 );
remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );

/** Badges above the title. */
add_action(
	'woocommerce_single_product_summary',
	static function (): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$badges = ds_product_badges( $product );
		$html   = '';
		foreach ( array( 'topic', 'status', 'instant' ) as $slot ) {
			if ( $badges[ $slot ] ) {
				$html .= ds_badge_html( $badges[ $slot ] );
			}
		}
		if ( '' !== $html ) {
			echo '<div class="ds-product__badges">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	},
	4
);

/** "Acheter maintenant" beside WooCommerce's own button. */
add_action(
	'woocommerce_after_add_to_cart_button',
	static function (): void {
		global $product;
		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() || $product->is_type( 'external' ) || $product->is_type( 'grouped' ) ) {
			return;
		}
		$name_attr = $product->is_type( 'simple' ) ? ' name="add-to-cart" value="' . (int) $product->get_id() . '"' : '';
		echo '<button type="submit"' . $name_attr . ' class="ds-btn ds-btn--accent ds-buy-now" data-ds-buy-now>' . ds_icon( 'bolt' ) . '<span>Acheter maintenant</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	},
	10
);

/** Trust chips under the form. */
add_action(
	'woocommerce_single_product_summary',
	static function (): void {
		global $product;
		$chips = array(
			array( 'shield', 'Paiement sécurisé' ),
			array( 'support', 'Support WhatsApp 7j/7' ),
		);
		if ( $product instanceof WC_Product && ( $product->is_virtual() || $product->is_downloadable() ) ) {
			array_unshift( $chips, array( 'bolt', 'Livraison instantanée' ) );
		} else {
			array_unshift( $chips, array( 'clock', 'Livraison rapide' ) );
		}
		echo '<ul class="ds-trust ds-trust--inline">';
		foreach ( $chips as $chip ) {
			echo '<li>' . ds_icon( $chip[0] ) . '<span>' . esc_html( $chip[1] ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</ul>';
	},
	35
);

/**
 * Description, "Comment ça marche ?", reviews — stacked sections instead of
 * tabs, so nothing is hidden behind a click on a phone.
 */
add_action(
	'woocommerce_after_single_product_summary',
	static function (): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$description = $product->get_description();
		if ( '' !== trim( $description ) ) {
			echo '<section class="ds-product-section" id="description"><h2>Description</h2><div class="ds-prose">' . wp_kses_post( wpautop( do_shortcode( $description ) ) ) . '</div></section>';
		}
		$steps = array(
			array( 'tag', 'Choisissez', 'Sélectionnez le produit et le montant, puis renseignez les informations demandées (identifiant, compte…).' ),
			array( 'wallet', 'Payez', 'MonCash, carte ou solde Wallet : le paiement est confirmé en quelques secondes.' ),
			array( 'bolt', 'Recevez', 'La recharge, le code ou l\'accès est livré sur votre compte, puis visible dans votre espace client.' ),
		);
		echo '<section class="ds-product-section" id="how"><h2>Comment ça marche ?</h2><ol class="ds-steps ds-steps--compact">';
		foreach ( $steps as $i => $step ) {
			echo '<li class="ds-step"><span class="ds-step__num">' . ( $i + 1 ) . '</span><span class="ds-step__icon">' . ds_icon( $step[0] ) . '</span><h3>' . esc_html( $step[1] ) . '</h3><p>' . esc_html( $step[2] ) . '</p></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</ol></section>';

		$attributes = $product->has_attributes() || ( $product->has_dimensions() || $product->has_weight() );
		if ( $attributes ) {
			echo '<section class="ds-product-section" id="details"><h2>Détails</h2>';
			wc_get_template( 'single-product/product-attributes.php', array( 'product' => $product, 'product_attributes' => $product->get_attributes() ) );
			echo '</section>';
		}
		if ( comments_open() && wc_reviews_enabled() ) {
			echo '<section class="ds-product-section" id="reviews"><h2>Avis clients</h2>';
			comments_template();
			echo '</section>';
		}
	},
	10
);

add_filter(
	'woocommerce_product_related_products_heading',
	static function (): string {
		return 'Vous aimerez aussi';
	}
);
add_filter(
	'woocommerce_output_related_products_args',
	static function ( array $args ): array {
		$args['posts_per_page'] = 4;
		$args['columns']        = 4;
		return $args;
	}
);
add_filter(
	'woocommerce_product_description_heading',
	static function (): string {
		return 'Description';
	}
);

/** Digital products are sold one at a time. */
add_filter(
	'woocommerce_is_sold_individually',
	static function ( bool $sold, WC_Product $product ): bool {
		if ( (int) ds_opt( 'hide_qty_virtual' ) === 1 && ( $product->is_virtual() || $product->is_downloadable() ) ) {
			return true;
		}
		return $sold;
	},
	10,
	2
);

/**
 * Mobile purchase dock. Rendered on every product page; hidden by CSS at
 * desktop widths and when JavaScript is off (its buttons submit the
 * WooCommerce form by id, which product.js assigns).
 */
add_action(
	'woocommerce_after_single_product',
	static function (): void {
		global $product;
		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}
		if ( $product->is_type( 'external' ) ) {
			echo '<div class="ds-dock"><div class="ds-dock__price">' . wp_kses_post( $product->get_price_html() ) . '</div><a class="ds-btn ds-btn--brand" href="' . esc_url( $product->add_to_cart_url() ) . '">' . esc_html( $product->single_add_to_cart_text() ) . '</a></div>';
			return;
		}
		$simple = $product->is_type( 'simple' );
		$attr   = $simple ? ' name="add-to-cart" value="' . (int) $product->get_id() . '"' : '';
		echo '<div class="ds-dock" data-ds-dock>';
		echo '<div class="ds-dock__price">' . wp_kses_post( $product->get_price_html() ) . '</div>';
		echo '<div class="ds-dock__actions">';
		echo '<button type="submit" form="ds-cart-form"' . $attr . ' class="ds-btn ds-btn--ghost ds-dock__add" data-ds-dock-add>' . ds_icon( 'cart' ) . '<span>Ajouter</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="submit" form="ds-cart-form"' . $attr . ' class="ds-btn ds-btn--accent ds-dock__buy" data-ds-buy-now>' . ds_icon( 'bolt' ) . '<span>Acheter maintenant</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div>';
	},
	20
);

/** Buy now → straight to checkout after a successful add. */
add_filter(
	'woocommerce_add_to_cart_redirect',
	static function ( $url ) {
		if ( ! empty( $_REQUEST['ds_buy_now'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce's own add-to-cart request; the add itself is validated by WooCommerce.
			return wc_get_checkout_url();
		}
		return $url;
	},
	20
);

/* ------------------------------------------------------------------------
 * Cart, checkout, account
 * ---------------------------------------------------------------------- */

/** Header and tab-bar counts refresh with WooCommerce's cart fragments. */
add_filter(
	'woocommerce_add_to_cart_fragments',
	static function ( array $fragments ): array {
		$count = ds_cart_count();
		$fragments['.ds-cart-count'] = '<span class="ds-cart-count' . ( $count > 0 ? ' is-visible' : '' ) . '" aria-label="' . esc_attr( $count . ' article(s)' ) . '">' . (int) $count . '</span>';
		return $fragments;
	}
);

/**
 * A digital order needs a phone (WhatsApp) and not much else. When nothing
 * in the cart ships, address lines become optional and company/address 2
 * go away; country stays because taxes and gateways read it.
 *
 * @param array<string,array<string,array<string,mixed>>> $fields Fields.
 * @return array<string,array<string,array<string,mixed>>>
 */
add_filter(
	'woocommerce_checkout_fields',
	static function ( array $fields ): array {
		if ( isset( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['label']    = 'Téléphone / WhatsApp';
			$fields['billing']['billing_phone']['required'] = (int) ds_opt( 'phone_required' ) === 1;
			$fields['billing']['billing_phone']['priority'] = 25;
		}
		if ( function_exists( 'WC' ) && WC()->cart instanceof WC_Cart && ! WC()->cart->needs_shipping() ) {
			unset( $fields['billing']['billing_company'], $fields['billing']['billing_address_2'] );
			foreach ( array( 'billing_address_1', 'billing_city', 'billing_postcode', 'billing_state' ) as $key ) {
				if ( isset( $fields['billing'][ $key ] ) ) {
					$fields['billing'][ $key ]['required'] = false;
					$fields['billing'][ $key ]['priority'] = 100 + (int) ( $fields['billing'][ $key ]['priority'] ?? 0 );
					$fields['billing'][ $key ]['class'][]  = 'ds-optional-address';
				}
			}
		}
		if ( isset( $fields['order']['order_comments'] ) ) {
			$fields['order']['order_comments']['placeholder'] = 'Une précision sur votre commande ? (facultatif)';
		}
		return $fields;
	},
	20
);

/**
 * WooCommerce re-applies each country's locale (postcode/state required…)
 * on top of the fields above, including from JavaScript when the country
 * changes. For a cart that ships nothing, every locale marks the address
 * lines optional.
 */
function ds_cart_is_virtual_only(): bool {
	return function_exists( 'WC' ) && WC()->cart instanceof WC_Cart && ! WC()->cart->is_empty() && ! WC()->cart->needs_shipping();
}
add_filter(
	'woocommerce_get_country_locale',
	static function ( array $locale ): array {
		if ( ! ds_cart_is_virtual_only() ) {
			return $locale;
		}
		foreach ( $locale as $country => $fields ) {
			foreach ( array( 'address_1', 'city', 'postcode', 'state' ) as $key ) {
				$locale[ $country ][ $key ]['required'] = false;
			}
		}
		return $locale;
	},
	20
);
add_filter(
	'woocommerce_default_address_fields',
	static function ( array $fields ): array {
		if ( ! ds_cart_is_virtual_only() ) {
			return $fields;
		}
		foreach ( array( 'address_1', 'city', 'postcode', 'state' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$fields[ $key ]['required'] = false;
			}
		}
		return $fields;
	},
	20
);

/** Empty cart: a way back and a way to ask. */
add_action(
	'woocommerce_cart_is_empty',
	static function (): void {
		$wa = ds_whatsapp_url( 'Bonjour, j\'ai une question avant de commander.' );
		echo '<div class="ds-empty">' . ds_icon( 'cart', 'ds-empty__icon' ) . '<p>Votre panier est vide.</p><p><a class="ds-btn ds-btn--brand" href="' . esc_url( ds_shop_url() ) . '">Voir la boutique</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( '' !== $wa ) {
			echo ' <a class="ds-btn ds-btn--ghost" href="' . esc_url( $wa ) . '" target="_blank" rel="noopener">' . ds_icon( 'whatsapp' ) . '<span>Une question ?</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</p></div>';
	},
	5
);
remove_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message', 10 );
remove_action( 'woocommerce_cart_is_empty', 'woocommerce_output_all_notices', 5 );
add_action( 'woocommerce_cart_is_empty', 'woocommerce_output_all_notices', 4 );

/** Thank-you page: WhatsApp with the order number prefilled. */
add_action(
	'woocommerce_thankyou',
	static function ( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$wa = ds_whatsapp_url( sprintf( 'Bonjour, je viens de passer la commande #%s sur %s.', $order->get_order_number(), wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		echo '<div class="ds-thanks">';
		echo '<p>' . ds_icon( 'bolt' ) . ' Votre commande est en cours de traitement. Vous recevrez un e-mail à chaque étape, et le détail reste disponible dans <a href="' . esc_url( ds_account_url() ) . '">votre espace client</a>.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( '' !== $wa ) {
			echo '<p><a class="ds-btn ds-btn--brand" href="' . esc_url( $wa ) . '" target="_blank" rel="noopener">' . ds_icon( 'whatsapp' ) . '<span>Contacter le support pour cette commande</span></a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	},
	5
);

/** Placeholder image drawn by the theme (no grey WooCommerce box). */
add_filter(
	'woocommerce_placeholder_img_src',
	static function (): string {
		return DS_URI . 'assets/img/placeholder.svg';
	}
);

/** Fewer, clearer account menu labels. */
add_filter(
	'woocommerce_account_menu_items',
	static function ( array $items ): array {
		$labels = array(
			'dashboard'       => 'Tableau de bord',
			'orders'          => 'Mes commandes',
			'downloads'       => 'Téléchargements',
			'edit-address'    => 'Adresse',
			'edit-account'    => 'Mon profil',
			'customer-logout' => 'Déconnexion',
		);
		foreach ( $labels as $key => $label ) {
			if ( isset( $items[ $key ] ) ) {
				$items[ $key ] = $label;
			}
		}
		if ( isset( $items['edit-address'] ) && function_exists( 'WC' ) && WC()->cart instanceof WC_Cart && ! wc_shipping_enabled() ) {
			unset( $items['edit-address'] );
		}
		return $items;
	},
	20
);

/**
 * The store's French phrasing where WooCommerce's own translation is
 * generic, and English fallbacks when the French pack is not installed.
 *
 * @param string $translated Translated text.
 * @param string $text       Original.
 * @param string $domain     Domain.
 */
add_filter(
	'gettext',
	static function ( string $translated, string $text, string $domain ): string {
		if ( 'woocommerce' !== $domain ) {
			return $translated;
		}
		static $map = array(
			'Add to cart'            => 'Ajouter au panier',
			'Read more'              => 'Voir',
			'Select options'         => 'Choisir',
			'Proceed to checkout'    => 'Commander',
			'Place order'            => 'Payer et commander',
			'Return to shop'         => 'Retour à la boutique',
			'Apply coupon'           => 'Appliquer',
			'Coupon code'            => 'Code promo',
			'Update cart'            => 'Mettre à jour',
			'Sort by popularity'     => 'Trier par popularité',
			'Sort by latest'         => 'Nouveautés',
			'Sort by price: low to high' => 'Prix croissant',
			'Sort by price: high to low' => 'Prix décroissant',
			'Sort by average rating' => 'Mieux notés',
			'Default sorting'        => 'Tri par défaut',
			'Related products'       => 'Vous aimerez aussi',
			'Your order'             => 'Votre commande',
			'Billing details'        => 'Vos informations',
			'Additional information' => 'Informations complémentaires',
			'Order received'         => 'Commande reçue',
			'Thank you. Your order has been received.' => 'Merci ! Votre commande a bien été reçue.',
		);
		if ( isset( $map[ $text ] ) ) {
			return $map[ $text ];
		}
		return $translated;
	},
	20,
	3
);

/** Reviews: stars everywhere ratings show. */
add_filter( 'woocommerce_enable_review_rating', '__return_true' );

/** The theme draws its own Promo badge; WooCommerce's "Sale!" flash goes. */
add_filter( 'woocommerce_sale_flash', '__return_empty_string' );
