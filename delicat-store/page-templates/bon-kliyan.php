<?php
/**
 * Template Name: Bon Kliyan
 * Template Post Type: page
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
get_header();
$ds_rules = ds_legal_url( 'contest' );
?>
<main id="main" class="ds-main">
	<div class="ds-container ds-container--narrow">
		<?php while ( have_posts() ) : the_post(); ?>
			<article <?php post_class( 'ds-page' ); ?>>
				<header class="ds-page__head">
					<p class="ds-section__eyebrow">Programme fidélité</p>
					<h1 class="ds-page__title"><?php the_title(); ?></h1>
					<p class="ds-page__desc">Chaque semaine, le client qui a le plus commandé remporte le lot. Aucune inscription : passer commande suffit.</p>
				</header>
				<?php if ( function_exists( 'ds_kliyan_render' ) ) { echo ds_kliyan_render( false ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( '' !== trim( (string) get_the_content() ) ) : ?>
					<div class="ds-page__body ds-prose"><?php the_content(); ?></div>
				<?php endif; ?>
				<section class="ds-page__section">
					<h2>Les règles en bref</h2>
					<ul class="ds-checklist">
						<li><?php ds_the_icon( 'check' ); ?><span>Cycle du <strong>lundi 00h00 au dimanche 23h59</strong>, heure d'Haïti. Gagnant annoncé le lundi.</span></li>
						<li><?php ds_the_icon( 'check' ); ?><span>Comptent les <strong>commandes payées et livrées</strong>. Pas les commandes annulées, remboursées ou non payées.</span></li>
						<li><?php ds_the_icon( 'check' ); ?><span>En cas d'égalité, la première commande passée dans la semaine l'emporte.</span></li>
						<li><?php ds_the_icon( 'check' ); ?><span>Le gagnant est contacté sur WhatsApp ou par e-mail et doit réclamer son lot sous 7 jours.</span></li>
						<li><?php ds_the_icon( 'check' ); ?><span>Un seul compte par personne. Les comptes multiples sont disqualifiés.</span></li>
					</ul>
					<p><a class="ds-btn ds-btn--ghost" href="<?php echo esc_url( $ds_rules ); ?>">Lire le règlement complet</a> <a class="ds-btn ds-btn--brand" href="<?php echo esc_url( ds_shop_url() ); ?>">Commander maintenant</a></p>
				</section>
			</article>
		<?php endwhile; ?>
	</div>
</main>
<?php
get_footer();
