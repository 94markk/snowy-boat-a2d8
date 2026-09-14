<?php
/**
 * Blog index and generic fallback.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
get_header();
?>
<main id="main" class="ds-main">
	<div class="ds-container ds-container--narrow">
		<header class="ds-page__head">
			<h1 class="ds-page__title"><?php echo is_home() ? esc_html( get_the_title( (int) get_option( 'page_for_posts' ) ) ?: 'Actualités' ) : ''; ?></h1>
		</header>
		<?php if ( have_posts() ) : ?>
			<div class="ds-posts">
				<?php while ( have_posts() ) : the_post(); ?>
					<?php get_template_part( 'template-parts/post-card' ); ?>
				<?php endwhile; ?>
			</div>
			<?php the_posts_pagination( array( 'prev_text' => '‹', 'next_text' => '›', 'class' => 'ds-pagination' ) ); ?>
		<?php else : ?>
			<p>Rien à afficher pour le moment.</p>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
