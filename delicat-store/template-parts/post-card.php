<?php
/**
 * A blog post in a list.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
?>
<article <?php post_class( 'ds-post-card' ); ?>>
	<?php if ( has_post_thumbnail() ) : ?>
		<a class="ds-post-card__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?php the_post_thumbnail( 'ds-card', array( 'loading' => 'lazy' ) ); ?></a>
	<?php endif; ?>
	<div class="ds-post-card__body">
		<p class="ds-post-card__date"><?php echo esc_html( get_the_date() ); ?></p>
		<h2 class="ds-post-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
		<p class="ds-post-card__excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
	</div>
</article>
