<?php
/**
 * Delicat Resellers — prices. On each product (or variation) the team types a Premium, Gold and Silver
 * price; a product without one uses the level's % discount on its regular price (0 % = normal price).
 * Never on the wallet top-up product, nor on a product marked « pas de prix revendeur ». A normal
 * price that is already lower (a sale) wins. Prices are applied at priority 20, before Delicat
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
		if ( $off <= 0 ) return null;
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
	// A price set while the page runs (Builder's product calculator: catalogue price + paid options):
	// the reseller price replaces the catalogue part only, the options keep their cost.
	if ( array_key_exists( 'price', $product->get_changes() ) ) {
		$data   = $product->get_data();
		$stored = (string) ( $data['price'] ?? '' );
		if ( '' === $stored ) return $price;
		$r = dlr_reseller_price( $product, $tier, $stored );
		return null === $r ? $price : (string) max( 0, $r + ( (float) $price - (float) $stored ) );
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
add_filter( 'woocommerce_get_variation_prices_hash', function ( $hash ) {
	$tier = dlr_current_tier();
	if ( '' !== $tier ) {
		$t = dlr_tiers();
		$hash[] = 'dlr:' . $tier . ':' . (float) $t[ $tier ]['discount'];
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

/* Reseller purchases are not retail for Delicat Rewards: no bonus progress, no weekly ranking, no
   referral point (Rewards 3.4.1 asks the same question for invitations). */
add_filter( 'delicat_rewards_order_is_retail', function ( $retail, $order ) {
	if ( ! $retail || ! $order instanceof WC_Order ) return $retail;
	if ( $order->get_meta( '_dlr_tier' ) ) return false;
	$uid = (int) $order->get_customer_id();
	return ! ( $uid > 0 && dlr_on() && '' !== dlr_user_tier( $uid ) );
}, 10, 2 );

/* ============================================================== product screen */

function dlr_price_field( $id, $name, $value, $label, $wrapper = '' ) {
	woocommerce_wp_text_input( array(
		'id' => $id, 'name' => $name, 'value' => '' === (string) $value ? '' : wc_format_localized_price( $value ),
		'label' => $label . ' (' . get_woocommerce_currency_symbol() . ')', 'data_type' => 'price', 'wrapper_class' => $wrapper,
		'desc_tip' => true, 'description' => 'Prix pour ce niveau de revendeur. Vide : remise du niveau (réglages Delicat Revendeurs), ou prix normal.',
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
