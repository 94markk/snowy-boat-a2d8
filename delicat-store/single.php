<?php
/**
 * Single blog post.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
get_header();
?>
<main id="main" class="ds-main">
	<div class="ds-container ds-container--narrow">
		<?php while ( have_posts() ) : the_post(); ?>
			<article <?php post_class( 'ds-page' ); ?>>
				<header class="ds-page__head">
					<p class="ds-post-card__date"><?php echo esc_html( get_the_date() ); ?></p>
					<h1 class="ds-page__title"><?php the_title(); ?></h1>
				</header>
				<?php if ( has_post_thumbnail() ) : ?>
					<figure class="ds-page__media"><?php the_post_thumbnail( 'ds-hero' ); ?></figure>
				<?php endif; ?>
				<div class="ds-page__body ds-prose"><?php the_content(); ?></div>
			</article>
			<?php
			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
			?>
		<?php endwhile; ?>
	</div>
</main>
<?php
get_footer();
