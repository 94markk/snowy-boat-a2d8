<?php
/**
 * Post archives (category, tag, date, author).
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
get_header();
?>
<main id="main" class="ds-main">
	<div class="ds-container ds-container--narrow">
		<header class="ds-page__head">
			<h1 class="ds-page__title"><?php echo wp_kses_post( get_the_archive_title() ); ?></h1>
			<?php the_archive_description( '<div class="ds-page__desc">', '</div>' ); ?>
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
