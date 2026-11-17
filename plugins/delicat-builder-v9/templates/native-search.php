<?php
/** Lightweight native search-results document. */
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'delicat-native-search-document' ); ?>>
<?php wp_body_open(); ?>
<?php if ( class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) ) { echo Delicat_Builder_V9_Header_Studio_8::instance()->render_header(); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<main class="delicat-native-page-main delicat-native-search-main" data-delicat-native-document="1">
	<header class="delicat-native-search-head">
		<span><?php esc_html_e( 'RECHERCHE', 'delicat-builder-v9' ); ?></span>
		<h1><?php echo esc_html( sprintf( __( 'Résultats pour « %s »', 'delicat-builder-v9' ), get_search_query() ) ); ?></h1>
		<?php get_search_form(); ?>
	</header>
	<?php if ( have_posts() ) : ?>
		<div class="delicat-native-search-grid">
		<?php while ( have_posts() ) : the_post(); ?>
			<article <?php post_class( 'delicat-native-search-card' ); ?>>
				<a class="delicat-native-search-image" href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1"><?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'decoding' => 'async' ) ); } ?></a>
				<div><small><?php $delicat_type = get_post_type_object( get_post_type() ); echo esc_html( is_object( $delicat_type ) && isset( $delicat_type->labels->singular_name ) ? $delicat_type->labels->singular_name : '' ); ?></small><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt() ), 22 ) ); ?></p></div>
			</article>
		<?php endwhile; ?>
		</div>
		<?php the_posts_pagination( array( 'mid_size' => 1, 'prev_text' => '←', 'next_text' => '→' ) ); ?>
	<?php else : ?>
		<section class="delicat-native-search-empty"><h2><?php esc_html_e( 'Aucun résultat', 'delicat-builder-v9' ); ?></h2><p><?php esc_html_e( 'Essayez un nom de jeu, une carte cadeau ou un service.', 'delicat-builder-v9' ); ?></p></section>
	<?php endif; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
