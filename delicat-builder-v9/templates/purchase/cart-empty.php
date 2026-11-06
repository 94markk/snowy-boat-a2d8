<?php
/**
 * Purchase Studio native empty cart (RC51.58).
 *
 * The core WooCommerce empty-cart hook still runs for extension compatibility.
 * Only WooCommerce's stock sentence is suppressed because this template
 * presents the same state inside the designed card below.
 *
 * @package Delicat_Builder_V9
 */
defined( 'ABSPATH' ) || exit;

$dpn = 'Delicat_Builder_V9_Purchase_Native';

$dpn_empty_message_priority = has_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message' );
if ( false !== $dpn_empty_message_priority ) {
	remove_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message', $dpn_empty_message_priority );
}

ob_start();
do_action( 'woocommerce_cart_is_empty' );
$dpn_empty_extensions = trim( (string) ob_get_clean() );

if ( false !== $dpn_empty_message_priority ) {
	add_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message', $dpn_empty_message_priority );
}

$dpn_shop_url  = apply_filters( 'woocommerce_return_to_shop_redirect', $dpn::shop_url() );
$dpn_shop_text = apply_filters( 'woocommerce_return_to_shop_text', __( 'Commencer vos achats', 'delicat-builder-v9' ) );
?>
<div class="dpn-shell dpn-shell-empty">
	<header class="dpn-head dpn-head-empty">
		<a class="dpn-back" href="<?php echo esc_url( $dpn_shop_url ); ?>" aria-label="<?php esc_attr_e( 'Continuer vos achats', 'delicat-builder-v9' ); ?>"><?php echo $dpn::icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		<div>
			<h1><?php esc_html_e( 'Panier', 'delicat-builder-v9' ); ?></h1>
			<p><?php esc_html_e( '0 article', 'delicat-builder-v9' ); ?></p>
		</div>
	</header>

	<div class="dpn-empty-card">
		<span class="dpn-empty-icon"><?php echo $dpn::icon( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<h2><?php esc_html_e( 'Votre panier est vide', 'delicat-builder-v9' ); ?></h2>
		<p><?php esc_html_e( 'Découvrez nos produits numériques et ajoutez votre premier article.', 'delicat-builder-v9' ); ?></p>
		<a class="dpn-start button wc-backward" href="<?php echo esc_url( $dpn_shop_url ); ?>"><?php echo esc_html( $dpn_shop_text ); ?></a>
	</div>

	<?php if ( '' !== $dpn_empty_extensions ) : ?>
		<div class="dpn-empty-extensions"><?php echo $dpn_empty_extensions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<?php endif; ?>
</div>
