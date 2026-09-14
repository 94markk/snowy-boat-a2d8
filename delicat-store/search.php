<?php
/**
 * Search results. Product searches are handled by WooCommerce's shop loop
 * (woocommerce.php); this template serves non-product searches.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

if ( ds_is_woo() && 'product' === get_query_var( 'post_type' ) ) {
	get_header();
	woocommerce_content();
	get_footer();
	return;
}
get_header();
?>
<main id="main" class="ds-main">
	<div class="ds-container ds-container--narrow">
		<header class="ds-page__head">
			<h1 class="ds-page__title">Résultats pour « <?php echo esc_html( get_search_query() ); ?> »</h1>
			<?php get_search_form(); ?>
		</header>
		<?php if ( have_posts() ) : ?>
			<div class="ds-posts">
				<?php while ( have_posts() ) : the_post(); ?>
					<?php get_template_part( 'template-parts/post-card' ); ?>
				<?php endwhile; ?>
			</div>
			<?php the_posts_pagination( array( 'prev_text' => '‹', 'next_text' => '›', 'class' => 'ds-pagination' ) ); ?>
		<?php else : ?>
			<div class="ds-empty"><?php ds_the_icon( 'search', 'ds-empty__icon' ); ?><p>Aucun résultat. Essayez un autre mot, ou parcourez la boutique.</p><p><a class="ds-btn ds-btn--brand" href="<?php echo esc_url( ds_shop_url() ); ?>">Voir la boutique</a></p></div>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
