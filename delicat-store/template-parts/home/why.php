<?php
/**
 * "Pourquoi Delicat" — four reasons and a diaspora call-out.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
$ds_cards = array(
	array( 'bolt', 'Rapide', 'Livraison en quelques minutes, jour et nuit, sans passer par un intermédiaire.' ),
	array( 'shield', 'Sécurisé', 'Paiement chiffré, commandes contrôlées, aucune donnée bancaire stockée chez nous.' ),
	array( 'info', 'Clair', 'Le prix affiché est le prix payé. Chaque produit explique ce qu\'il livre et ce qu\'il exige.' ),
	array( 'support', 'Humain', 'Un support qui répond sur WhatsApp, en français et en créole, 7 jours sur 7.' ),
);
?>
<section class="ds-section ds-section--why" aria-labelledby="ds-why-title">
	<div class="ds-container">
		<div class="ds-section__head ds-section__head--center">
			<div>
				<p class="ds-section__eyebrow">Pourquoi Delicat</p>
				<h2 class="ds-section__title" id="ds-why-title">Tout est pensé pour ta tranquillité</h2>
				<p class="ds-section__subtitle">Une plateforme rapide, des informations claires et des commandes contrôlées.</p>
			</div>
		</div>
		<ul class="ds-why">
			<?php foreach ( $ds_cards as $ds_card ) : ?>
				<li class="ds-why__card"><span class="ds-why__icon"><?php ds_the_icon( $ds_card[0] ); ?></span><h3><?php echo esc_html( $ds_card[1] ); ?></h3><p><?php echo esc_html( $ds_card[2] ); ?></p></li>
			<?php endforeach; ?>
		</ul>
		<div class="ds-callout">
			<span class="ds-callout__icon"><?php ds_the_icon( 'globe' ); ?></span>
			<div>
				<p class="ds-section__eyebrow">Haïti · Diaspora</p>
				<h3 class="ds-callout__title">Fais plaisir à un proche, simplement.</h3>
				<p>Jeux, abonnements et cartes cadeaux : choisis le service, règle en toute sécurité, puis suis la livraison dans ton espace client.</p>
			</div>
			<a class="ds-btn ds-btn--brand" href="<?php echo esc_url( ds_shop_url() ); ?>"><span>Découvrir les services</span><?php ds_the_icon( 'arrow' ); ?></a>
		</div>
	</div>
</section>
