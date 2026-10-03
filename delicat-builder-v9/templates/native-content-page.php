<?php defined( 'ABSPATH' ) || exit; ?><!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><?php wp_head(); ?></head>
<body <?php body_class( 'delicat-native-info-document' ); ?>><?php wp_body_open(); ?>
<?php if ( class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) ) { echo Delicat_Builder_V9_Header_Studio_8::instance()->render_header(); } elseif ( class_exists( 'Delicat_Builder_V9_Shell', false ) ) { echo Delicat_Builder_V9_Shell::render_header(); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<main class="delicat-native-info-main" data-delicat-server-render="1"><?php while ( have_posts() ) { the_post(); the_content(); } ?></main>
<?php wp_footer(); ?></body></html>
