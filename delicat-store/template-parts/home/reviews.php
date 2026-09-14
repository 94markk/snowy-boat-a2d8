<?php
/**
 * "Ils nous font confiance".
 *
 * @param array{reviews:array<int,array{name:string,text:string,product:string,rating:int}>} $args
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
$ds_reviews = $args['reviews'] ?? array();
if ( array() === $ds_reviews ) {
	return;
}
$ds_wa = ds_whatsapp_url( 'Bonjour, je souhaite laisser un avis sur ma commande.' );
?>
<section class="ds-section ds-section--reviews" aria-labelledby="ds-reviews-title">
	<div class="ds-container">
		<div class="ds-section__head ds-section__head--center">
			<div>
				<p class="ds-section__eyebrow">Avis vérifiés</p>
				<h2 class="ds-section__title" id="ds-reviews-title">Ils nous font confiance</h2>
				<p class="ds-section__subtitle">Des milliers de recharges livrées partout en Haïti.</p>
			</div>
		</div>
		<ul class="ds-reviews">
			<?php foreach ( $ds_reviews as $ds_r ) : ?>
				<li class="ds-review">
					<span class="ds-review__stars" aria-label="<?php echo esc_attr( $ds_r['rating'] . ' sur 5' ); ?>"><?php for ( $ds_i = 0; $ds_i < 5; $ds_i++ ) { echo ds_icon( 'star', $ds_i < $ds_r['rating'] ? 'is-on' : '' ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<p class="ds-review__text">« <?php echo esc_html( $ds_r['text'] ); ?> »</p>
					<p class="ds-review__name"><?php echo esc_html( $ds_r['name'] ); ?><?php if ( '' !== $ds_r['product'] ) : ?><small> · <?php echo esc_html( $ds_r['product'] ); ?></small><?php endif; ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( '' !== $ds_wa ) : ?>
			<div class="ds-callout ds-callout--compact">
				<div><p class="ds-section__eyebrow">Déjà client ?</p><h3 class="ds-callout__title">Laisse ton avis</h3><p>Ton retour aide toute la communauté Delicat Store.</p></div>
				<a class="ds-btn ds-btn--whatsapp" href="<?php echo esc_url( $ds_wa ); ?>" target="_blank" rel="noopener"><?php ds_the_icon( 'whatsapp' ); ?><span>Laisser un avis</span></a>
			</div>
		<?php endif; ?>
	</div>
</section>
