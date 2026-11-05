<?php
/**
 * Delicat Resellers — prices. On each product (or variation) the team types a Premium, Gold and Silver
 * price; a product without one uses the level's % discount on its regular price (0 % = normal price),
 * only in the categories ticked in the settings (« Catégories avec remise revendeur », 1.1.1): never an
 * exchange or finance product by accident. Never on the wallet top-up product, nor on a product marked
 * « pas de prix revendeur ». A normal price that is already lower (a sale) wins. Prices are applied at priority 20, before Delicat
 * Builder's currency conversion (99), so they are entered and stored in HTG.
 *
 * Reseller orders carry _dlr_tier and are not retail for Delicat Rewards (bonuses, weekly ranking,
 * referral points).
 */
defined( 'ABSPATH' ) || exit;

/* ============================================================== which price */

/** TeraWallet's top-up product: a reseller price on it would turn into free wallet money. */
function dlr_topup_id() {
	static $id = null;
	if ( null === $id ) {
		$id = 0;
		if ( function_exists( 'get_wallet_rechargeable_product' ) ) {
			$p  = get_wallet_rechargeable_product();
			$id = $p ? (int) $p->get_id() : 0;
		}
	}
	return $id;
}
function dlr_excluded( $product ) {
	if ( ! $product instanceof WC_Product ) return true;
	$parent = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
	if ( $parent && $parent === dlr_topup_id() ) return true;
	return 'yes' === get_post_meta( $parent, '_dlr_exclude', true );
}
/* ============================================================== categories with the % discount (1.1.1) */

/**
 * Exchange and finance words: a category named like this (name or slug, accents ignored) is never ticked
 * by the 1.1.1 migration, and is flagged in the settings.
 */
function dlr_finance_pattern() {
	return '/(?<![a-z0-9])(usdt|crypto[a-z]*|paypal|zelle|wise|payoneer|skrill|cash ?app|echange[a-z]*|transfert[a-z]*|binance|perfect ?money|financ[a-z]*|moncash|natcash)(?![a-z0-9])/';
}
function dlr_is_finance_name( $text ) {
	$t = strtolower( remove_accents( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) );
	return (bool) preg_match( dlr_finance_pattern(), preg_replace( '/[^a-z0-9]+/', ' ', $t ) );
}
/** Product categories, as id => term. */
function dlr_product_cats() {
	if ( ! taxonomy_exists( 'product_cat' ) ) return array();
	$out = array();
	foreach ( (array) get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1000 ) ) as $t ) {
		if ( $t instanceof WP_Term ) $out[ (int) $t->term_id ] = $t;
	}
	return $out;
}
/**
 * Does the level's % discount apply to this product? Only when the product (the parent of a variation)
 * sits directly in one of the ticked categories. A product's own reseller price does not need this.
 */
function dlr_discount_applies( $product ) {
	static $memo = array();
	if ( ! $product instanceof WC_Product ) return false;
	$parent = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
	if ( ! isset( $memo[ $parent ] ) ) {
		$cats = (array) dlr_cfg( 'discount_cats' );
		$memo[ $parent ] = $cats && $parent && array_intersect( array_map( 'intval', (array) wc_get_product_term_ids( $parent, 'product_cat' ) ), $cats );
	}
	return (bool) $memo[ $parent ];
}
/**
 * One-time migration (1.1.1), so resellers keep their discounts the day of the update: every product
 * category is ticked except the exchange/finance ones (dlr_is_finance_name). What was left out, and the
 * products with a finance name inside a ticked category, are listed in a notice for the owner to review.
 * Runs once (option dlr_cats_migrated), after WooCommerce has registered its categories.
 */
function dlr_cats_migrate() {
	if ( get_option( 'dlr_cats_migrated' ) || ! taxonomy_exists( 'product_cat' ) ) return;
	if ( ! dlr_lock( 'cats_migrate', 30 ) ) return;
	try {
		if ( get_option( 'dlr_cats_migrated' ) ) return;
		$keep = array(); $left = array();
		foreach ( dlr_product_cats() as $id => $t ) {
			if ( dlr_is_finance_name( $t->name ) || dlr_is_finance_name( $t->slug ) ) $left[] = $id; else $keep[] = $id;
		}
		$suspect = array();
		if ( $keep && function_exists( 'wc_get_products' ) ) {
			$slugs = array();
			foreach ( $keep as $id ) { $t = get_term( $id, 'product_cat' ); if ( $t instanceof WP_Term ) $slugs[] = $t->slug; }
			foreach ( wc_get_products( array( 'category' => $slugs, 'limit' => 500, 'status' => array( 'publish', 'private', 'draft' ), 'return' => 'ids' ) ) as $pid ) {
				if ( 'yes' !== get_post_meta( $pid, '_dlr_exclude', true ) && dlr_is_finance_name( get_the_title( $pid ) ) ) $suspect[] = (int) $pid;
			}
		}
		$c = get_option( 'dlr_config', array() );
		$c = is_array( $c ) ? $c : array();
		$c['discount_cats'] = $keep;
		update_option( 'dlr_config', $c, false );
		dlr_cfg( null, true );
		update_option( 'dlr_cats_review', array( 'left' => $left, 'suspect' => array_slice( $suspect, 0, 50 ), 'at' => time() ), false );
		update_option( 'dlr_cats_migrated', DLR_VERSION, true ); // read on every request: kept in the autoloaded options
	} finally {
		dlr_unlock( 'cats_migrate' );
	}
}
add_action( 'init', 'dlr_cats_migrate', 20 ); // product_cat is registered at init 5

/* The owner reviews what the migration left out (until « J'ai vérifié »). */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) return;
	$r = get_option( 'dlr_cats_review' );
	if ( ! is_array( $r ) || ! empty( $r['done'] ) ) return;
	$names = array();
	foreach ( (array) ( $r['left'] ?? array() ) as $id ) { $t = get_term( (int) $id, 'product_cat' ); if ( $t instanceof WP_Term ) $names[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ); }
	$prods = array();
	foreach ( (array) ( $r['suspect'] ?? array() ) as $pid ) { $prods[] = get_the_title( (int) $pid ) . ' (#' . (int) $pid . ')'; }
	$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=dlr_cats_reviewed' ), 'dlr_cats_reviewed' );
	echo '<div class="notice notice-warning"><p><strong>Delicat Revendeurs 1.1.1 :</strong> la remise en % des niveaux ne s’applique plus qu’aux catégories cochées dans <a href="' . esc_url( admin_url( 'admin.php?page=delicat-resellers&tab=reglages' ) ) . '">Réglages › Catégories avec remise revendeur</a>. Toutes vos catégories ont été cochées, sauf les catégories d’échange ou de finance'
		. ( $names ? ' : <strong>' . esc_html( implode( ', ', $names ) ) . '</strong> (les revendeurs y paient le prix normal, sauf un prix revendeur saisi sur le produit).' : ' (aucune trouvée).' ) . '</p>';
	if ( $prods ) echo '<p>À vérifier : ces produits ont un nom d’échange ou de finance mais sont dans une catégorie avec remise. Cochez « Pas de prix revendeur » sur le produit si la remise ne doit pas s’y appliquer : ' . esc_html( implode( ', ', $prods ) ) . '.</p>';
	echo '<p><a class="button" href="' . esc_url( $dismiss ) . '">J’ai vérifié</a></p></div>';
} );
add_action( 'admin_post_dlr_cats_reviewed', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Accès refusé.', 'Delicat Revendeurs', array( 'response' => 403 ) );
	check_admin_referer( 'dlr_cats_reviewed' );
	$r = get_option( 'dlr_cats_review' );
	if ( is_array( $r ) ) { $r['done'] = get_current_user_id(); update_option( 'dlr_cats_review', $r, false ); }
	wp_safe_redirect( wp_get_referer() ?: admin_url() );
	exit;
} );

/**
 * The reseller price of a product for a level, or null for the normal price.
 * $active: the product's normal current price (sale included), unfiltered.
 */
function dlr_reseller_price( $product, $tier, $active ) {
	if ( '' === $tier || dlr_excluded( $product ) ) return null;
	$active = '' === $active || null === $active ? 0.0 : (float) $active;
	$own    = $product->get_meta( '_dlr_price_' . $tier, true, 'edit' );
	if ( '' !== (string) $own && (float) $own > 0 ) {
		$price = (float) $own;
	} else {
		$tiers = dlr_tiers();
		$off   = (float) ( $tiers[ $tier ]['discount'] ?? 0 );
		if ( $off <= 0 || ! dlr_discount_applies( $product ) ) return null; // 1.1.1: the % only in the ticked categories
		$regular = (float) $product->get_regular_price( 'edit' );
		if ( $regular <= 0 ) $regular = $active;
		if ( $regular <= 0 ) return null;
		$price = $regular * ( 1 - $off / 100 );
	}
	$price = round( $price, wc_get_price_decimals() );
	if ( $active > 0 && $active <= $price ) return null; // the normal price is already as low (a sale)
	return $price;
}

function dlr_filter_price( $price, $product ) {
	$tier = dlr_current_tier();
	if ( '' === $tier || '' === (string) $price ) return $price;
	// A price set while the page runs (Builder's product calculator). When it is the catalogue price plus
	// paid options, the reseller price replaces the catalogue part and the options keep their cost. A
	// formula that does not start from the catalogue price (an amount × a rate, a quantity) gets the
	// product's reseller reduction as a share instead. The larger of the two is kept: a reseller never
	// gets more off than that share, and a priced line never drops to zero.
	if ( array_key_exists( 'price', $product->get_changes() ) ) {
		$data   = $product->get_data();
		$stored = (float) ( $data['price'] ?? 0 );
		if ( $stored <= 0 ) return $price;
		$r = dlr_reseller_price( $product, $tier, $stored );
		if ( null === $r ) return $price;
		$now = (float) $price;
		$out = round( max( $r + $now - $stored, $now * $r / $stored ), wc_get_price_decimals() );
		return $out > 0 || $now <= 0 ? (string) $out : $price; // a tiny line that would round to 0 keeps its price
	}
	$r = dlr_reseller_price( $product, $tier, $price );
	return null === $r ? $price : (string) $r;
}
/* Not presented as a sale: no « Promo » badge, and the price people see first (Builder's purchase bar reads
   the price text) is the reseller price; the normal price follows it, struck through. */
add_filter( 'woocommerce_product_get_price', 'dlr_filter_price', 20, 2 );
add_filter( 'woocommerce_product_variation_get_price', 'dlr_filter_price', 20, 2 );
/* Variable products: the price range is read from cached variation prices, kept apart per level. */
add_filter( 'woocommerce_variation_prices_price', function ( $price, $variation ) { return dlr_filter_price( $price, $variation ); }, 20, 2 );
/*
 * Delicat Builder's currency switcher (priority 99): a price typed by hand for another currency replaces
 * the converted one, reseller price included. That fixed price gets the same reduction, in proportion.
 * (By default Builder charges checkout and the wallet in HTG, where the reseller price applies as is.)
 */
function dlr_filter_fixed_currency_price( $price, $product ) {
	if ( '' === (string) $price || ! $product instanceof WC_Product || ! class_exists( 'Delicat_Builder_V9_Multi_Currency' ) ) return $price;
	$tier = dlr_current_tier();
	if ( '' === $tier ) return $price;
	$mc = Delicat_Builder_V9_Multi_Currency::instance();
	if ( ! method_exists( $mc, 'current' ) || ! method_exists( $mc, 'base_code' ) || ! method_exists( $mc, 'no_convert' ) ) return $price;
	$code = (string) $mc->current();
	if ( $code === (string) $mc->base_code() || $mc->no_convert( $product ) ) return $price;
	$fixed = get_post_meta( $product->get_id(), '_dmc_price_' . $code, true );
	if ( ( '' === $fixed || ! is_numeric( $fixed ) ) && $product->get_parent_id() ) $fixed = get_post_meta( $product->get_parent_id(), '_dmc_price_' . $code, true );
	if ( '' === $fixed || ! is_numeric( $fixed ) || abs( (float) $fixed - (float) $price ) > 0.0001 ) return $price; // converted by rate: already reduced
	$data   = $product->get_data();
	$stored = (float) ( $data['price'] ?? 0 );
	if ( $stored <= 0 ) return $price;
	$r = dlr_reseller_price( $product, $tier, $stored );
	return null === $r ? $price : (string) round( (float) $fixed * $r / $stored, wc_get_price_decimals() );
}
add_filter( 'woocommerce_product_get_price', 'dlr_filter_fixed_currency_price', 100, 2 );
add_filter( 'woocommerce_product_variation_get_price', 'dlr_filter_fixed_currency_price', 100, 2 );
add_filter( 'woocommerce_variation_prices_price', function ( $price, $variation ) { return dlr_filter_fixed_currency_price( $price, $variation ); }, 100, 2 );
add_filter( 'woocommerce_get_variation_prices_hash', function ( $hash ) {
	$tier = dlr_current_tier();
	if ( '' !== $tier ) {
		$t = dlr_tiers();
		$hash[] = 'dlr:' . $tier . ':' . (float) $t[ $tier ]['discount'] . ':' . md5( implode( ',', (array) dlr_cfg( 'discount_cats' ) ) );
	}
	return $hash;
}, 20 );

/* « Prix Golden Reseller » under the price, wherever a reseller price applies. */
function dlr_has_reseller_price( $product, $tier ) {
	if ( $product->is_type( 'variable' ) ) {
		foreach ( array_slice( $product->get_children(), 0, 50 ) as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && null !== dlr_reseller_price( $v, $tier, $v->get_price( 'edit' ) ) ) return true;
		}
		return false;
	}
	return null !== dlr_reseller_price( $product, $tier, $product->get_price( 'edit' ) );
}
add_filter( 'woocommerce_get_price_html', function ( $html, $product ) {
	$tier = dlr_current_tier();
	if ( '' === $tier || '' === (string) $html || ! $product instanceof WC_Product || ! dlr_has_reseller_price( $product, $tier ) ) return $html;
	$html .= ' <span class="dlr-price-tag dlr-t-' . esc_attr( $tier ) . '">Prix ' . esc_html( dlr_tier_label( $tier ) ) . '</span>';
	if ( ! $product->is_type( 'variable' ) && '' !== (string) $product->get_regular_price() ) {
		$was = wc_price( wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ) );
		$html .= ' <span class="dlr-was">au lieu de <del>' . $was . '</del></span>';
	}
	return $html;
}, 20, 2 );
add_action( 'wp_head', function () {
	if ( '' === dlr_current_tier() ) return;
	echo '<style id="dlr-price-tag">.dlr-price-tag{display:inline-block;margin-left:6px;padding:1px 8px;border-radius:999px;font-size:.72em;font-weight:700;line-height:1.6;vertical-align:middle;color:#fff;background:#475569}.dlr-price-tag.dlr-t-premium{background:#6d28d9}.dlr-price-tag.dlr-t-gold{background:#92400e}.dlr-was{display:inline-block;margin-left:6px;font-size:.8em;opacity:.75}</style>' . "\n";
}, 30 );

/* Builder keeps rendered page bodies for signed-in visitors under a key that includes their roles: a
   reseller always carries their level's role, so their prices are never shown to anyone else. If the
   role were ever missing, that cache is simply not used for them. */
add_filter( 'delicat_builder_v9_server_fragment_cache_signed_in', function ( $ok ) {
	$tier = dlr_current_tier();
	if ( '' === $tier ) return $ok;
	return $ok && in_array( 'dlr_' . $tier, (array) wp_get_current_user()->roles, true );
} );

/* ============================================================== orders */

function dlr_stamp_order( $order ) {
	if ( ! $order instanceof WC_Order || $order->get_meta( '_dlr_tier' ) ) return false;
	if ( 'delicat-rewards' === $order->get_created_via() ) return false;
	$tier = dlr_on() ? dlr_user_tier( (int) $order->get_customer_id() ) : '';
	if ( '' === $tier ) return false;
	$order->update_meta_data( '_dlr_tier', $tier );
	return true;
}
add_action( 'woocommerce_checkout_create_order', 'dlr_stamp_order', 20 );
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'dlr_stamp_order', 20 );
add_action( 'woocommerce_new_order', function ( $order_id, $order = null ) { // any other path (express checkout, app)
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	if ( dlr_stamp_order( $order ) ) $order->save_meta_data();
}, 20, 2 );

/* Purchases made as a reseller (stamped _dlr_tier when the order is created) are not retail for Delicat
   Rewards: no bonus progress, no weekly ranking, no referral point (Rewards 3.4.1 asks the same question
   for invitations). Orders from before the activation, or after leaving the programme, stay retail. */
add_filter( 'delicat_rewards_order_is_retail', function ( $retail, $order ) {
	if ( ! $retail || ! $order instanceof WC_Order ) return $retail;
	return ! $order->get_meta( '_dlr_tier' );
}, 10, 2 );

/* ============================================================== product screen */

function dlr_price_field( $id, $name, $value, $label, $wrapper = '' ) {
	woocommerce_wp_text_input( array(
		'id' => $id, 'name' => $name, 'value' => '' === (string) $value ? '' : wc_format_localized_price( $value ),
		'label' => $label . ' (' . get_woocommerce_currency_symbol() . ')', 'data_type' => 'price', 'wrapper_class' => $wrapper,
		'desc_tip' => true, 'description' => 'Prix pour ce niveau de revendeur. Vide : remise du niveau si le produit est dans une « catégorie avec remise revendeur » (réglages Delicat Revendeurs), sinon prix normal.',
	) );
}
add_action( 'woocommerce_product_options_pricing', function () {
	global $product_object;
	if ( ! $product_object instanceof WC_Product ) return;
	echo '<div class="options_group dlr-prices"><p class="form-field"><strong>Prix revendeurs</strong></p>';
	foreach ( dlr_tiers() as $t => $c ) dlr_price_field( '_dlr_price_' . $t, '_dlr_price_' . $t, $product_object->get_meta( '_dlr_price_' . $t, true, 'edit' ), $c['label'] );
	echo '</div>';
} );
add_action( 'woocommerce_product_options_general_product_data', function () {
	global $product_object;
	if ( ! $product_object instanceof WC_Product ) return;
	echo '<div class="options_group">';
	woocommerce_wp_checkbox( array( 'id' => '_dlr_exclude', 'value' => 'yes' === get_post_meta( $product_object->get_id(), '_dlr_exclude', true ) ? 'yes' : 'no', 'label' => 'Pas de prix revendeur', 'description' => 'Les revendeurs paient le prix normal pour ce produit (et toutes ses variations).' ) );
	echo '</div>';
} );
add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	// WooCommerce has checked the product form's nonce and the editor's rights.
	foreach ( dlr_tier_keys() as $t ) {
		$raw = isset( $_POST[ '_dlr_price_' . $t ] ) ? wc_format_decimal( wp_unslash( $_POST[ '_dlr_price_' . $t ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' !== $raw && (float) $raw > 0 ) $product->update_meta_data( '_dlr_price_' . $t, $raw ); else $product->delete_meta_data( '_dlr_price_' . $t );
	}
	if ( ! empty( $_POST['_dlr_exclude'] ) ) $product->update_meta_data( '_dlr_exclude', 'yes' ); else $product->delete_meta_data( '_dlr_exclude' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
} );
add_action( 'woocommerce_variation_options_pricing', function ( $loop, $data, $variation ) {
	$v = wc_get_product( $variation->ID );
	if ( ! $v ) return;
	foreach ( dlr_tiers() as $t => $c ) {
		dlr_price_field( '_dlr_price_' . $t . '_' . $loop, '_dlr_price_' . $t . '[' . $loop . ']', $v->get_meta( '_dlr_price_' . $t, true, 'edit' ), $c['label'], 'form-row form-row-' . ( 'silver' === $t ? 'full' : ( 'premium' === $t ? 'first' : 'last' ) ) );
	}
}, 20, 3 );
add_action( 'woocommerce_save_product_variation', function ( $variation_id, $i ) {
	// WooCommerce has checked the variations nonce and the editor's rights.
	$v = wc_get_product( $variation_id );
	if ( ! $v ) return;
	foreach ( dlr_tier_keys() as $t ) {
		$raw = isset( $_POST[ '_dlr_price_' . $t ][ $i ] ) ? wc_format_decimal( wp_unslash( $_POST[ '_dlr_price_' . $t ][ $i ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' !== $raw && (float) $raw > 0 ) $v->update_meta_data( '_dlr_price_' . $t, $raw ); else $v->delete_meta_data( '_dlr_price_' . $t );
	}
	$v->save_meta_data();
}, 20, 2 );
