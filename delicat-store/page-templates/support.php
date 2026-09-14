<?php
/**
 * Template Name: Support & contact
 * Template Post Type: page
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
get_header();
$ds_wa    = ds_whatsapp_url( 'Bonjour, j\'ai besoin d\'aide.' );
$ds_email = ds_support_email();
$ds_woo   = ds_is_woo();
?>
<main id="main" class="ds-main">
	<div class="ds-container ds-container--narrow">
		<?php while ( have_posts() ) : the_post(); ?>
			<article <?php post_class( 'ds-page' ); ?>>
				<header class="ds-page__head">
					<p class="ds-section__eyebrow">On est là</p>
					<h1 class="ds-page__title"><?php the_title(); ?></h1>
					<p class="ds-page__desc">Une question, une commande en attente, un identifiant à corriger ? Le plus rapide est WhatsApp.</p>
				</header>
				<div class="ds-support">
					<?php if ( '' !== $ds_wa ) : ?>
						<a class="ds-support__card ds-support__card--wa" href="<?php echo esc_url( $ds_wa ); ?>" target="_blank" rel="noopener">
							<?php ds_the_icon( 'whatsapp' ); ?><span><strong>WhatsApp</strong><small>+<?php echo esc_html( ds_whatsapp_number() ); ?> · réponse tous les jours</small></span><?php ds_the_icon( 'arrow' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( '' !== $ds_email ) : ?>
						<a class="ds-support__card" href="mailto:<?php echo esc_attr( $ds_email ); ?>">
							<?php ds_the_icon( 'chat' ); ?><span><strong>E-mail</strong><small><?php echo esc_html( $ds_email ); ?></small></span><?php ds_the_icon( 'arrow' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $ds_woo ) : ?>
						<a class="ds-support__card" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">
							<?php ds_the_icon( 'clock' ); ?><span><strong>Suivre ma commande</strong><small>Statut, détails et livraison dans votre espace client</small></span><?php ds_the_icon( 'arrow' ); ?>
						</a>
					<?php endif; ?>
				</div>
				<?php if ( '' !== trim( (string) get_the_content() ) ) : ?>
					<div class="ds-page__body ds-prose"><?php the_content(); ?></div>
				<?php endif; ?>
				<section class="ds-page__section">
					<h2>Avant d'écrire</h2>
					<ul class="ds-checklist">
						<li><?php ds_the_icon( 'check' ); ?><span>Ayez votre <strong>numéro de commande</strong> sous la main (e-mail de confirmation ou espace client).</span></li>
						<li><?php ds_the_icon( 'check' ); ?><span>Pour une recharge de jeu, vérifiez le <strong>Player ID</strong> saisi : une recharge envoyée sur un mauvais identifiant n'est pas récupérable.</span></li>
						<li><?php ds_the_icon( 'check' ); ?><span>Pour un abonnement, vérifiez l'<strong>e-mail du compte</strong> indiqué à la commande.</span></li>
					</ul>
				</section>
			</article>
		<?php endwhile; ?>
		<?php get_template_part( 'template-parts/home/faq' ); ?>
	</div>
</main>
<?php
get_footer();
