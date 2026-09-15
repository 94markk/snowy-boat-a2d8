<?php
/** Native Delicat WooCommerce archive document. */
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'delicat-native-archive-document' ); ?>>
<?php wp_body_open(); ?>
<?php
if ( class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) ) {
	echo Delicat_Builder_V9_Header_Studio_8::instance()->render_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} elseif ( class_exists( 'Delicat_Builder_V9_Shell', false ) ) {
	echo Delicat_Builder_V9_Shell::render_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
?>
<main id="delicat-native-archive" class="delicat-native-archive-main" data-delicat-server-render="1">
	<?php
	if ( function_exists( 'woocommerce_output_all_notices' ) ) {
		woocommerce_output_all_notices();
	}
	do_action( 'woocommerce_shop_loop_header' );
	if ( woocommerce_product_loop() ) {
		do_action( 'woocommerce_before_shop_loop' );
		woocommerce_product_loop_start();
		if ( wc_get_loop_prop( 'total' ) ) {
			while ( have_posts() ) {
				the_post();
				do_action( 'woocommerce_shop_loop' );
				wc_get_template_part( 'content', 'product' );
			}
		}
		woocommerce_product_loop_end();
		do_action( 'woocommerce_after_shop_loop' );
	} else {
		do_action( 'woocommerce_no_products_found' );
	}
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
