<?php
/**
 * "Comment ça marche" — three steps.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
$ds_steps = array(
	array( 'tag', 'Choisissez votre recharge', 'Sélectionnez le jeu ou le service et le montant. Renseignez votre identifiant si le produit le demande.' ),
	array( 'wallet', 'Payez en toute sécurité', 'MonCash, carte bancaire ou solde Wallet : vous choisissez à la commande.' ),
	array( 'bolt', 'Recevez instantanément', 'La recharge est créditée ou le code affiché dès la confirmation, puis conservé dans votre espace client.' ),
);
?>
<section class="ds-section ds-section--steps" aria-labelledby="ds-steps-title">
	<div class="ds-container">
		<div class="ds-section__head ds-section__head--center">
			<div>
				<p class="ds-section__eyebrow">Simple comme bonjour</p>
				<h2 class="ds-section__title" id="ds-steps-title">Comment ça marche</h2>
				<p class="ds-section__subtitle">Trois étapes. Moins d'une minute d'attente.</p>
			</div>
		</div>
		<ol class="ds-steps">
			<?php foreach ( $ds_steps as $ds_i => $ds_step ) : ?>
				<li class="ds-step">
					<span class="ds-step__num"><?php echo (int) $ds_i + 1; ?></span>
					<span class="ds-step__icon"><?php ds_the_icon( $ds_step[0] ); ?></span>
					<h3><?php echo esc_html( $ds_step[1] ); ?></h3>
					<p><?php echo esc_html( $ds_step[2] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>
