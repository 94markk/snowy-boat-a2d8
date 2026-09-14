<?php
/**
 * Shared helpers. Pure functions (no WordPress calls) are grouped at the end so
 * tests/theme/run.php can exercise them without loading WordPress.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is WooCommerce active?
 */
function ds_is_woo(): bool {
	return class_exists( 'WooCommerce', false ) || function_exists( 'WC' );
}

/**
 * Every Customizer default in one place. inc/customizer.php registers these
 * keys; templates read them through ds_opt(). A key that is not here does not
 * exist.
 *
 * @return array<string,mixed>
 */
function ds_defaults(): array {
	static $defaults = null;
	if ( null !== $defaults ) {
		return $defaults;
	}
	$defaults = array(
		/* Identité & contact */
		'tagline'          => 'Jeux, cartes cadeaux et services digitaux livrés simplement, rapidement et en toute sécurité.',
		'whatsapp'         => '50933111283',
		'email_support'    => '',
		'telegram'         => '',
		'instagram'        => '',
		'facebook'         => '',
		'tiktok'           => '',
		'youtube'          => '',
		'google_fonts'     => 1,

		/* Couleurs (clair) — le sombre est dérivé dans assets/css/theme.css */
		'color_brand'      => '#6d5dfc',
		'color_accent'     => '#ec4899',
		'color_ground'     => '#f5f6fb',
		'color_surface'    => '#ffffff',
		'color_ink'        => '#111827',
		'radius'           => 14,

		/* Accueil */
		'hero_eyebrow'     => 'DELICAT STORE · RAPIDE · SÉCURISÉ',
		'hero_title'       => 'Votre univers digital, livré rapidement.',
		'hero_text'        => 'Jeux, gift cards, streaming et services numériques avec une expérience simple, sécurisée et pensée pour Haïti.',
		'hero_search'      => 1,
		'hero_image'       => '',
		'show_chips'       => 1,
		'show_trust'       => 1,
		'show_best'        => 1,
		'best_title'       => 'Les plus achetés',
		'best_subtitle'    => 'Classement des produits les plus commandés.',
		'show_promo'       => 1,
		'show_steps'       => 1,
		'show_why'         => 1,
		'show_kliyan'      => 1,
		'show_reviews'     => 1,
		'show_faq'         => 1,
		'show_cta'         => 1,
		'rail_1_cat'       => 'jeux',
		'rail_1_title'     => 'JEUX',
		'rail_1_subtitle'  => 'Recharge automatique sur votre compte.',
		'rail_2_cat'       => 'abonnement',
		'rail_2_title'     => 'ABONNEMENT PREMIUM',
		'rail_2_subtitle'  => 'Vos plateformes préférées, à portée de main.',
		'rail_3_cat'       => 'echanges',
		'rail_3_title'     => 'ÉCHANGES',
		'rail_3_subtitle'  => 'Vos services financiers essentiels.',
		'rail_4_cat'       => 'gift-card',
		'rail_4_title'     => 'GIFT CARDS',
		'rail_4_subtitle'  => 'Cartes cadeaux numériques livrées rapidement.',
		'top_threshold'    => 10,

		/* Bon Kliyan */
		'kliyan_prize'     => 'Une recharge de jeu ou un crédit Wallet offert au meilleur client de la semaine.',
		'kliyan_amounts'   => 1,
		'kliyan_size'      => 5,

		/* Boutique */
		'hide_qty_virtual' => 1,
		'instant_badge'    => 1,
		'card_button'      => 1,
		'phone_required'   => 1,

		/* Infos légales (remplissent les modèles de legal/) */
		'legal_company'    => '',
		'legal_form'       => '',
		'legal_address'    => '',
		'legal_tax_id'     => '',
		'legal_publisher'  => '',
		'legal_host_name'  => 'Hostinger International Ltd.',
		'legal_host_address' => '61 Lordou Vironos Street, 6023 Larnaca, Chypre',
		'legal_host_url'   => 'https://www.hostinger.com/',
		'legal_email_privacy'  => '',
		'legal_email_security' => '',
		'legal_updated'    => '',
	);

	for ( $i = 1; $i <= 6; $i++ ) {
		$defaults[ "faq_{$i}_q" ] = ds_default_faq()[ $i - 1 ]['q'];
		$defaults[ "faq_{$i}_a" ] = ds_default_faq()[ $i - 1 ]['a'];
	}
	for ( $i = 1; $i <= 3; $i++ ) {
		$defaults[ "review_{$i}_name" ] = ds_default_reviews()[ $i - 1 ]['name'];
		$defaults[ "review_{$i}_text" ] = ds_default_reviews()[ $i - 1 ]['text'];
	}
	return $defaults;
}

/**
 * @return array<int,array{q:string,a:string}>
 */
function ds_default_faq(): array {
	return array(
		array(
			'q' => 'Combien de temps prend la livraison ?',
			'a' => 'La plupart des recharges et abonnements sont livrés en quelques minutes après confirmation du paiement, 7j/7. Les commandes passées la nuit sont traitées dès l\'ouverture.',
		),
		array(
			'q' => 'Comment payer ?',
			'a' => 'Par MonCash, carte bancaire, ou avec votre solde Delicat Wallet. Le moyen de paiement se choisit à l\'étape de commande.',
		),
		array(
			'q' => 'Où trouver mon Player ID ?',
			'a' => 'Dans le jeu, ouvrez votre profil : l\'identifiant est affiché sous votre pseudo. Chaque produit qui en a besoin affiche un bouton d\'aide avec une capture d\'écran.',
		),
		array(
			'q' => 'Que se passe-t-il si je me trompe d\'identifiant ?',
			'a' => 'Une recharge créditée sur un identifiant erroné n\'est pas récupérable. Vérifiez deux fois avant de valider : nous vous montrons l\'identifiant saisi au récapitulatif.',
		),
		array(
			'q' => 'Puis-je être remboursé ?',
			'a' => 'Oui, tant que la commande n\'a pas été exécutée. Une fois le code révélé ou la recharge créditée, le remboursement n\'est plus possible. Voir la politique de remboursement.',
		),
		array(
			'q' => 'Comment contacter le support ?',
			'a' => 'Par WhatsApp, directement depuis le bouton en bas de chaque page. Nous répondons tous les jours.',
		),
	);
}

/**
 * @return array<int,array{name:string,text:string}>
 */
function ds_default_reviews(): array {
	return array(
		array(
			'name' => 'Stanley P.',
			'text' => 'Diamants Free Fire reçus en moins de 5 minutes, payé avec MonCash. Service au top.',
		),
		array(
			'name' => 'Nadège J.',
			'text' => 'J\'achète mes abonnements Netflix ici depuis un an. Jamais un problème, et le support répond vite.',
		),
		array(
			'name' => 'Ricardo L.',
			'text' => 'Gift card Google Play livrée instantanément. Le site est simple et rapide même en 3G.',
		),
	);
}

/**
 * Read a theme option with its default.
 *
 * @param string $key Option key from ds_defaults().
 * @return mixed
 */
function ds_opt( string $key ) {
	$defaults = ds_defaults();
	$default  = array_key_exists( $key, $defaults ) ? $defaults[ $key ] : '';
	return get_theme_mod( $key, $default );
}

/**
 * Digits-only WhatsApp number, or '' if not configured.
 */
function ds_whatsapp_number(): string {
	return ds_digits( (string) ds_opt( 'whatsapp' ) );
}

/**
 * wa.me link, optionally with a prefilled message.
 */
function ds_whatsapp_url( string $text = '' ): string {
	$number = ds_whatsapp_number();
	if ( '' === $number ) {
		return '';
	}
	$url = 'https://wa.me/' . $number;
	if ( '' !== $text ) {
		$url .= '?text=' . rawurlencode( $text );
	}
	return $url;
}

/**
 * Support e-mail: option, else WooCommerce sender, else admin e-mail.
 */
function ds_support_email(): string {
	$email = (string) ds_opt( 'email_support' );
	if ( '' === $email && ds_is_woo() ) {
		$email = (string) get_option( 'woocommerce_email_from_address', '' );
	}
	if ( '' === $email ) {
		$email = (string) get_option( 'admin_email', '' );
	}
	return $email;
}

/**
 * Social links that are actually configured, in display order.
 *
 * @return array<string,string> network => url
 */
function ds_social_links(): array {
	$out = array();
	$wa  = ds_whatsapp_url();
	if ( '' !== $wa ) {
		$out['whatsapp'] = $wa;
	}
	foreach ( array( 'telegram', 'instagram', 'facebook', 'tiktok', 'youtube' ) as $net ) {
		$url = trim( (string) ds_opt( $net ) );
		if ( '' !== $url ) {
			$out[ $net ] = $url;
		}
	}
	return $out;
}

/** URL helpers ---------------------------------------------------------- */

function ds_shop_url(): string {
	if ( ds_is_woo() && function_exists( 'wc_get_page_permalink' ) ) {
		$url = wc_get_page_permalink( 'shop' );
		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}
	}
	return home_url( '/boutique/' );
}

function ds_cart_url(): string {
	if ( ds_is_woo() && function_exists( 'wc_get_cart_url' ) ) {
		return wc_get_cart_url();
	}
	return home_url( '/panier/' );
}

function ds_account_url(): string {
	if ( ds_is_woo() && function_exists( 'wc_get_page_permalink' ) ) {
		$url = wc_get_page_permalink( 'myaccount' );
		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}
	}
	return wp_login_url();
}

/**
 * Permalink of a page by slug, or '' when the page does not exist.
 */
function ds_page_url( string $slug ): string {
	$page = get_page_by_path( $slug );
	if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
		$link = get_permalink( $page );
		return is_string( $link ) ? $link : '';
	}
	return '';
}

/**
 * Product search URL for a query.
 */
function ds_search_url( string $query = '' ): string {
	$args = array( 's' => $query );
	if ( ds_is_woo() ) {
		$args['post_type'] = 'product';
	}
	return add_query_arg( array_map( 'rawurlencode', $args ), home_url( '/' ) );
}

/**
 * Cart item count, 0 when WooCommerce or the cart is not available.
 */
function ds_cart_count(): int {
	if ( ds_is_woo() && function_exists( 'WC' ) && WC()->cart instanceof WC_Cart ) {
		return (int) WC()->cart->get_cart_contents_count();
	}
	return 0;
}

/**
 * Names of published products for the search <datalist>. Cached 15 minutes.
 *
 * @return string[]
 */
function ds_product_names(): array {
	if ( ! ds_is_woo() ) {
		return array();
	}
	$names = get_transient( 'ds_product_names' );
	if ( is_array( $names ) ) {
		return $names;
	}
	$ids   = wc_get_products(
		array(
			'status'  => 'publish',
			'limit'   => 200,
			'orderby' => 'popularity',
			'order'   => 'DESC',
			'return'  => 'ids',
		)
	);
	$names = array();
	foreach ( $ids as $id ) {
		$title = get_the_title( $id );
		if ( '' !== $title ) {
			$names[] = $title;
		}
	}
	set_transient( 'ds_product_names', $names, 15 * MINUTE_IN_SECONDS );
	return $names;
}

/**
 * Flush caches that depend on the catalogue.
 */
function ds_flush_catalog_caches(): void {
	delete_transient( 'ds_product_names' );
	delete_transient( 'ds_rank_one' );
	delete_transient( 'ds_kliyan_board' );
}
add_action( 'save_post_product', 'ds_flush_catalog_caches' );
add_action( 'woocommerce_order_status_completed', 'ds_flush_catalog_caches' );
add_action( 'woocommerce_order_status_refunded', 'ds_flush_catalog_caches' );
add_action( 'woocommerce_order_status_cancelled', 'ds_flush_catalog_caches' );

/**
 * Hero / search datalist markup, shared by header and hero.
 */
function ds_search_datalist_id(): string {
	static $printed = false;
	if ( $printed ) {
		return 'ds-products';
	}
	$printed = true;
	add_action(
		'wp_footer',
		static function () {
			$names = ds_product_names();
			if ( array() === $names ) {
				return;
			}
			echo '<datalist id="ds-products">';
			foreach ( $names as $name ) {
				echo '<option value="' . esc_attr( $name ) . '"></option>';
			}
			echo '</datalist>';
		},
		5
	);
	return 'ds-products';
}

/* ------------------------------------------------------------------------
 * Pure functions (no WordPress). Covered by tests/theme/run.php.
 * ---------------------------------------------------------------------- */

/**
 * Keep digits only.
 */
function ds_digits( string $value ): string {
	return (string) preg_replace( '/[^0-9]/', '', $value );
}

/**
 * Normalise a tag/category name or slug for matching: lower case, accents
 * stripped, every separator collapsed to a single hyphen.
 * "Réseaux Sociaux", "reseaux_sociaux" and "Resaux-Sociaux" stay distinct
 * strings but all match through ds_badge_topic()'s alias table.
 */
function ds_normalize_term( string $value ): string {
	$value = mb_strtolower( trim( $value ), 'UTF-8' );
	$map   = array(
		'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
		'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
		'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
		'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
		'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
		'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
	);
	$value = strtr( $value, $map );
	$value = (string) preg_replace( '/[^a-z0-9]+/', '-', $value );
	return trim( $value, '-' );
}

/**
 * "Jean Dupont" → "Jean D.", "stanley" → "Stanley", "" → "Client".
 */
function ds_mask_name( string $name ): string {
	$name  = trim( (string) preg_replace( '/\s+/', ' ', $name ) );
	if ( '' === $name ) {
		return 'Client';
	}
	$parts = explode( ' ', $name );
	$first = mb_convert_case( mb_strtolower( array_shift( $parts ), 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
	if ( array() === $parts ) {
		return $first;
	}
	$last = mb_strtoupper( mb_substr( end( $parts ), 0, 1, 'UTF-8' ), 'UTF-8' );
	return $first . ' ' . $last . '.';
}

/**
 * Relative luminance of a hex colour (0 = black, 1 = white).
 */
function ds_luminance( string $hex ): float {
	$hex = ltrim( trim( $hex ), '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		return 0.5;
	}
	$channel = static function ( string $pair ): float {
		$c = hexdec( $pair ) / 255;
		return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	};
	return 0.2126 * $channel( substr( $hex, 0, 2 ) ) + 0.7152 * $channel( substr( $hex, 2, 2 ) ) + 0.0722 * $channel( substr( $hex, 4, 2 ) );
}

/**
 * Black or white text for a background colour.
 */
function ds_ink_for( string $hex ): string {
	return ds_luminance( $hex ) > 0.45 ? '#111827' : '#ffffff';
}

/**
 * Lighten (positive) or darken (negative) a hex colour by a fraction.
 */
function ds_shade( string $hex, float $amount ): string {
	$hex = ltrim( trim( $hex ), '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		return '#' . $hex;
	}
	$out = '#';
	foreach ( str_split( $hex, 2 ) as $pair ) {
		$c = hexdec( $pair );
		$c = $amount >= 0 ? $c + ( 255 - $c ) * $amount : $c * ( 1 + $amount );
		$out .= str_pad( dechex( (int) round( max( 0, min( 255, $c ) ) ) ), 2, '0', STR_PAD_LEFT );
	}
	return $out;
}

/**
 * Validate a hex colour, returning the fallback when invalid.
 */
function ds_hex( $value, string $fallback ): string {
	$value = is_string( $value ) ? trim( $value ) : '';
	return (bool) preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? strtolower( $value ) : $fallback;
}

/**
 * Format a Gourde amount for display outside WooCommerce (Bon Kliyan board).
 */
function ds_format_amount( float $amount, string $symbol = 'G' ): string {
	return $symbol . ' ' . number_format( $amount, 0, ',', ' ' );
}
