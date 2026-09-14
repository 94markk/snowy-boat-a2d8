<?php
/**
 * Standard page (cart, checkout, account and any content page).
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
get_header();
$ds_is_wc_page = ds_is_woo() && ( is_cart() || is_checkout() || is_account_page() );
?>
<main id="main" class="ds-main<?php echo $ds_is_wc_page ? ' ds-main--wc' : ''; ?>">
	<div class="ds-container ds-container--narrow">
		<?php while ( have_posts() ) : the_post(); ?>
			<article <?php post_class( 'ds-page' ); ?>>
				<?php if ( ! $ds_is_wc_page || is_account_page() ) : ?>
					<header class="ds-page__head">
						<h1 class="ds-page__title"><?php the_title(); ?></h1>
					</header>
				<?php endif; ?>
				<div class="ds-page__body <?php echo $ds_is_wc_page ? 'ds-wc' : 'ds-prose'; ?>">
					<?php
					the_content();
					wp_link_pages( array( 'before' => '<nav class="ds-pages">', 'after' => '</nav>' ) );
					?>
				</div>
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
