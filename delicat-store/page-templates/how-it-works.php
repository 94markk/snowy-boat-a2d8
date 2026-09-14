<?php
/**
 * Template Name: Comment ça marche
 * Template Post Type: page
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
get_header();
$ds_kinds = array(
	array( 'gamepad', 'Recharges de jeux', 'Free Fire, PUBG, Mobile Legends… Indiquez votre Player ID (visible dans le profil du jeu). Les diamants ou UC sont crédités directement sur votre compte.' ),
	array( 'play', 'Abonnements', 'Netflix, Spotify, Canva… Vous recevez un accès ou un code, avec les instructions, dans votre espace client et par e-mail.' ),
	array( 'gift', 'Cartes cadeaux', 'Google Play, iTunes, PlayStation, Steam… Le code est affiché après paiement et reste consultable dans votre compte.' ),
	array( 'wallet', 'Échanges & Wallet', 'MonCash vers USDT, recharge de votre Delicat Wallet : le montant est converti au taux affiché et crédité après vérification.' ),
	array( 'phone', 'Mobile', 'Recharges Digicel et Natcom : indiquez le numéro à créditer, la recharge part immédiatement.' ),
	array( 'share', 'Réseaux sociaux', 'Services de visibilité pour vos pages : renseignez le lien du profil ou de la publication.' ),
);
?>
<main id="main" class="ds-main">
	<div class="ds-container ds-container--narrow">
		<?php while ( have_posts() ) : the_post(); ?>
			<article <?php post_class( 'ds-page' ); ?>>
				<header class="ds-page__head">
					<p class="ds-section__eyebrow">Simple comme bonjour</p>
					<h1 class="ds-page__title"><?php the_title(); ?></h1>
					<p class="ds-page__desc">Trois étapes, et votre produit numérique est livré. Voici précisément ce qui se passe.</p>
				</header>
				<?php if ( '' !== trim( (string) get_the_content() ) ) : ?>
					<div class="ds-page__body ds-prose"><?php the_content(); ?></div>
				<?php endif; ?>
			</article>
		<?php endwhile; ?>
	</div>
	<?php get_template_part( 'template-parts/home/steps' ); ?>
	<div class="ds-container ds-container--narrow">
		<section class="ds-page__section">
			<h2>Selon le type de produit</h2>
			<ul class="ds-why">
				<?php foreach ( $ds_kinds as $ds_kind ) : ?>
					<li class="ds-why__card"><span class="ds-why__icon"><?php ds_the_icon( $ds_kind[0] ); ?></span><h3><?php echo esc_html( $ds_kind[1] ); ?></h3><p><?php echo esc_html( $ds_kind[2] ); ?></p></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<section class="ds-page__section">
			<h2>Où trouver mon Player ID ?</h2>
			<div class="ds-prose">
				<p>Ouvrez le jeu, touchez votre avatar ou votre pseudo en haut de l'écran : l'identifiant (une suite de chiffres) s'affiche sous votre nom. Sur la page de chaque recharge, le bouton <strong>Aide</strong> à côté du champ montre une capture d'écran pour ce jeu précis.</p>
				<p><strong>Vérifiez deux fois.</strong> Une recharge créditée sur un mauvais identifiant est livrée à quelqu'un d'autre et ne peut pas être récupérée.</p>
			</div>
		</section>
	</div>
	<?php get_template_part( 'template-parts/home/faq' ); ?>
	<?php get_template_part( 'template-parts/home/cta' ); ?>
</main>
<?php
get_footer();
