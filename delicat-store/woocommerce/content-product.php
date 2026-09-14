<?php
/**
 * One product in a WooCommerce loop (shop, category, search, related).
 * Delegates to the theme's single card renderer so every listing agrees.
 *
 * @package DelicatStore
 * @version 9.4.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
	return;
}
?>
<li <?php wc_product_class( 'ds-li', $product ); ?>>
	<?php echo ds_product_card( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the renderer. ?>
</li>
