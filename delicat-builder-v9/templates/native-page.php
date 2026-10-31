<?php
/** Native Builder V9 document for WordPress, WooCommerce and shortcode pages. */
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'delicat-native-page-document' ); ?>>
<?php wp_body_open(); ?>
<?php
// wp_body_open normally emitted Header Studio already. The direct call is an
// idempotent fallback for unusual themes/integrations that suppress the hook.
if ( class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) ) {
	echo Delicat_Builder_V9_Header_Studio_8::instance()->render_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
?>
<main id="delicat-native-page-main" class="delicat-native-page-main" data-delicat-server-render="1" data-delicat-native-document="1">
	<?php
	while ( have_posts() ) {
		the_post();
		the_content();
	}
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
