<?php
/**
 * Template Name: Page légale
 * Template Post Type: page
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
get_header();
$ds_key = ds_legal_current_key();
$ds_doc = '' !== $ds_key ? ds_legal_documents()[ $ds_key ] : null;
$ds_all = ds_legal_footer_links();
?>
<main id="main" class="ds-main">
	<div class="ds-container ds-container--narrow">
		<?php while ( have_posts() ) : the_post(); ?>
			<article <?php post_class( 'ds-page ds-page--legal' ); ?>>
				<header class="ds-page__head">
					<?php if ( $ds_doc ) : ?><p class="ds-section__eyebrow"><?php echo esc_html( $ds_doc['eyebrow'] ); ?></p><?php endif; ?>
					<h1 class="ds-page__title"><?php the_title(); ?></h1>
					<?php if ( $ds_doc ) : ?><p class="ds-page__desc"><?php echo esc_html( $ds_doc['intro'] ); ?></p><?php endif; ?>
				</header>
				<div class="ds-page__body ds-prose"><?php the_content(); ?></div>
			</article>
		<?php endwhile; ?>
		<?php if ( count( $ds_all ) > 1 ) : ?>
			<nav class="ds-legal-nav" aria-label="Autres documents">
				<p class="ds-drawer__label">Autres documents</p>
				<ul>
					<?php foreach ( $ds_all as $ds_title => $ds_url ) : ?>
						<li><a href="<?php echo esc_url( $ds_url ); ?>"<?php echo $ds_doc && $ds_doc['title'] === $ds_title ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $ds_title ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
