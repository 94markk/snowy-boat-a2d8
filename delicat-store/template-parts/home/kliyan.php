<?php
/**
 * Bon Kliyan section on the homepage.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ds_kliyan_render' ) ) {
	return;
}
$ds_page = ds_page_url( 'bon-kliyan' );
?>
<section class="ds-section ds-section--kliyan" aria-labelledby="ds-kliyan-title">
	<div class="ds-container">
		<div class="ds-section__head">
			<div>
				<p class="ds-section__eyebrow">Programme fidélité</p>
				<h2 class="ds-section__title" id="ds-kliyan-title">Bon Kliyan de la semaine</h2>
				<p class="ds-section__subtitle">Le client qui commande le plus dans la semaine remporte le lot. Participation automatique.</p>
			</div>
			<?php if ( '' !== $ds_page ) : ?>
				<a class="ds-section__more" href="<?php echo esc_url( $ds_page ); ?>"><span>Le programme</span><?php ds_the_icon( 'arrow' ); ?></a>
			<?php endif; ?>
		</div>
		<?php echo ds_kliyan_render( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</section>
