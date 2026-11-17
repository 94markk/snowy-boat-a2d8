<?php defined('ABSPATH')||exit; global $post; $id=absint(get_queried_object_id()); ?>
<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#ffffff"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?>
<?php if(class_exists('Delicat_Builder_V9_Header_Studio_8',false)){echo Delicat_Builder_V9_Header_Studio_8::instance()->render_header();}elseif(class_exists('Delicat_Builder_V9_Shell',false)){echo Delicat_Builder_V9_Shell::render_header();} // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<main id="delicat-native-product-main" class="delicat-native-product-main" data-delicat-server-render="1"><?php if(function_exists('woocommerce_output_all_notices'))woocommerce_output_all_notices(); echo Delicat_Builder_V9_Native_Product::render($id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></main>
<?php wp_footer(); ?></body></html>
