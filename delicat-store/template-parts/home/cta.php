<?php
/**
 * WhatsApp call to action.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
$ds_wa = ds_whatsapp_url( 'Bonjour, j\'ai une question avant de commander.' );
if ( '' === $ds_wa ) {
	return;
}
?>
<section class="ds-section ds-section--cta" aria-label="Contact">
	<div class="ds-container">
		<div class="ds-cta">
			<span class="ds-cta__icon"><?php ds_the_icon( 'whatsapp' ); ?></span>
			<div>
				<h2 class="ds-cta__title">Une question avant de commander ?</h2>
				<p>Écris-nous sur WhatsApp : on répond tous les jours, en français et en créole.</p>
			</div>
			<a class="ds-btn ds-btn--whatsapp ds-btn--large" href="<?php echo esc_url( $ds_wa ); ?>" target="_blank" rel="noopener"><span>Ouvrir WhatsApp</span><?php ds_the_icon( 'arrow' ); ?></a>
		</div>
	</div>
</section>
