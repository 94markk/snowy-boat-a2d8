<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'delicat-v9-native-document' ); ?>>
<?php wp_body_open(); ?>
<?php
if ( class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) && is_callable( array( 'Delicat_Builder_V9_Header_Studio_8', 'instance' ) ) ) {
	$header_engine = Delicat_Builder_V9_Header_Studio_8::instance();
	if ( is_object( $header_engine ) && is_callable( array( $header_engine, 'render_header' ) ) ) {
		echo $header_engine->render_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
} elseif ( class_exists( 'Delicat_Builder_V9_Shell', false ) && is_callable( array( 'Delicat_Builder_V9_Shell', 'render_header' ) ) ) {
	echo Delicat_Builder_V9_Shell::render_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
?>
<main id="delicat-v9-server-main" class="delicat-v9-server-main" data-delicat-server-render="1" data-delicat-native-shell="1">
	<?php
	$html = class_exists( 'Delicat_Builder_V9_Server_Engine', false ) ? ( is_callable( array( 'Delicat_Builder_V9_Server_Engine', 'render_current' ) ) ? Delicat_Builder_V9_Server_Engine::render_current() : Delicat_Builder_V9_Server_Engine::render_current_page() ) : '';
	if ( '' !== $html ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		while ( have_posts() ) {
			the_post();
			the_content();
		}
	}
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
