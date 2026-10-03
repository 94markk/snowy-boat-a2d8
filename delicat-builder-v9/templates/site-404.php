<?php
/**
 * Delicat Builder V9 — smart 404 template (RC40).
 * Native Builder V9 document. It remains available in Compatibility Safe Mode
 * so a broken optional module can never expose the old theme header again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$delicat_site_404 = class_exists( 'Delicat_Builder_V9_Site', false )
	? Delicat_Builder_V9_Site::fourofour_context()
	: array();

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'delicat-native-404-document' ); ?>>
<?php wp_body_open(); ?>
<?php if ( class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) ) { echo Delicat_Builder_V9_Header_Studio_8::instance()->render_header(); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<main id="primary" class="delicat-site delicat-site-404__main">
	<section class="delicat-site-404__hero">
		<p class="delicat-site-404__code" aria-hidden="true">404</p>
		<h1 class="delicat-site-404__title"><?php echo esc_html( (string) ( $delicat_site_404['title'] ?? __( 'Page introuvable', 'delicat-builder-v9' ) ) ); ?></h1>
		<?php if ( ! empty( $delicat_site_404['text'] ) ) : ?>
			<p class="delicat-site-404__text"><?php echo esc_html( (string) $delicat_site_404['text'] ); ?></p>
		<?php endif; ?>

		<div class="delicat-site-404__search"><?php get_search_form(); ?></div>

		<div class="delicat-site-404__actions">
			<a class="delicat-site-404__button delicat-site-404__button--primary" href="<?php echo esc_url( (string) ( $delicat_site_404['home_url'] ?? home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Retour à l’accueil', 'delicat-builder-v9' ); ?></a>
			<?php if ( ! empty( $delicat_site_404['shop_url'] ) ) : ?>
				<a class="delicat-site-404__button" href="<?php echo esc_url( (string) $delicat_site_404['shop_url'] ); ?>"><?php esc_html_e( 'Voir la boutique', 'delicat-builder-v9' ); ?></a>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( ! empty( $delicat_site_404['products_html'] ) ) : ?>
		<section class="delicat-site-404__products">
			<?php echo $delicat_site_404['products_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Builder carousel HTML, escaped at render. ?>
		</section>
	<?php endif; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
