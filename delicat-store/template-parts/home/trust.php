<?php
/**
 * Trust strip.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
$ds_items = array(
	array( 'bolt', 'Livraison instantanée', 'Recharges et codes livrés en quelques minutes.' ),
	array( 'shield', 'Paiement sécurisé', 'MonCash, carte bancaire ou solde Wallet.' ),
	array( 'support', 'Support 7j/7', 'Une vraie personne sur WhatsApp, tous les jours.' ),
	array( 'lock', 'Commandes contrôlées', 'Chaque identifiant est vérifié avant livraison.' ),
);
?>
<section class="ds-section ds-section--trust" aria-label="Nos engagements">
	<div class="ds-container">
		<ul class="ds-trust ds-trust--strip">
			<?php foreach ( $ds_items as $ds_item ) : ?>
				<li><?php ds_the_icon( $ds_item[0] ); ?><span><strong><?php echo esc_html( $ds_item[1] ); ?></strong><small><?php echo esc_html( $ds_item[2] ); ?></small></span></li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
