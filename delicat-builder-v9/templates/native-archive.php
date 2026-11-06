<?php
/** pro.49 — ultra-light native Delicat WooCommerce archive document. */
defined( 'ABSPATH' ) || exit;
$config = class_exists( 'Delicat_Builder_V9_Archive_Builder', false )
	? Delicat_Builder_V9_Archive_Builder::active_config()
	: array();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'delicat-native-archive-document delicat-archive-v4-ultra' ); ?>>
<?php wp_body_open(); ?>
<?php
if ( class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) ) {
	echo Delicat_Builder_V9_Header_Studio_8::instance()->render_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} elseif ( class_exists( 'Delicat_Builder_V9_Shell', false ) ) {
	echo Delicat_Builder_V9_Shell::render_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
?>
<main id="delicat-native-archive" class="delicat-native-archive-main" data-delicat-server-render="1" data-delicat-archive-engine="v4-ultra">
	<?php
	if ( function_exists( 'woocommerce_output_all_notices' ) ) {
		woocommerce_output_all_notices();
	}

	if ( class_exists( 'Delicat_Builder_V9_Archive_Builder', false ) ) {
		Delicat_Builder_V9_Archive_Builder::render_header();
		Delicat_Builder_V9_Archive_Builder::render_category_chips();
	}

	if ( woocommerce_product_loop() ) {
		$show_count = empty( $config['fast_mode'] ) && ! empty( $config['show_result_count'] );
		$show_sort  = ! array_key_exists( 'show_ordering', $config ) || ! empty( $config['show_ordering'] );
		if ( $show_count || $show_sort ) {
			?><div class="delicat-archive-toolbar"><?php
			if ( $show_count && function_exists( 'woocommerce_result_count' ) ) {
				woocommerce_result_count();
			}
			if ( $show_sort && function_exists( 'woocommerce_catalog_ordering' ) ) {
				woocommerce_catalog_ordering();
			}
			?></div><?php
		}
		?>
		<section class="delicat-archive-grid" aria-label="<?php echo esc_attr__( 'Produits', 'delicat-builder-v9' ); ?>">
		<?php
		$card_index = 0;
		while ( have_posts() ) {
			the_post();
			if ( class_exists( 'Delicat_Builder_V9_Archive_Builder', false ) ) {
				Delicat_Builder_V9_Archive_Builder::render_product_card( get_the_ID(), $card_index++ );
			}
		}
		?>
		</section>
		<?php
		if ( ! array_key_exists( 'show_pagination', $config ) || ! empty( $config['show_pagination'] ) ) {
			woocommerce_pagination();
		}
	} else {
		if ( function_exists( 'wc_no_products_found' ) ) {
			wc_no_products_found();
		}
	}
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
