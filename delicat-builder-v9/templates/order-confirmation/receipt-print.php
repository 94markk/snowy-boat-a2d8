<?php
/**
 * Print sheet of the shareable receipt (A5), printed in wp_footer by
 * Delicat_Builder_V9_Order_Confirmation::print_root(). Hidden on screen; the print
 * stylesheet shows only this, so « Imprimer / PDF » and a plain Ctrl+P never print the
 * page itself (which carries the codes). Built from the receipt model only: never any
 * code, never billing details.
 *
 * @package Delicat_Builder_V9
 * @var array  $receipt Delicat_Builder_V9_Order_Status::receipt().
 * @var string $qr_svg  Inline SVG of the QR ('' for guests).
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $receipt ) || ! is_array( $receipt ) ) {
	return;
}
?>
<div class="dlc-oc-print" id="dlc-oc-print">
	<div class="dlc-oc-print__card">
		<div class="dlc-oc-print__band">
			<span class="dlc-oc-print__logo">
				<?php if ( ! empty( $receipt['brand']['logo'] ) ) : ?>
					<img src="<?php echo esc_url( $receipt['brand']['logo'] ); ?>" width="36" height="36" alt="">
				<?php else : ?>
					D
				<?php endif; ?>
			</span>
			<span class="dlc-oc-print__brand"><strong><?php echo esc_html( $receipt['brand']['name'] ); ?></strong><small><?php echo esc_html( $receipt['brand']['host'] ); ?></small></span>
			<span class="dlc-oc-print__no">#<?php echo esc_html( $receipt['number'] ); ?></span>
		</div>
		<dl class="dlc-oc-print__meta">
			<div><dt><?php esc_html_e( 'Date', 'delicat-builder-v9' ); ?></dt><dd><?php echo esc_html( $receipt['date'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Paiement', 'delicat-builder-v9' ); ?></dt><dd><?php echo esc_html( $receipt['payment'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Statut', 'delicat-builder-v9' ); ?></dt><dd><span class="dlc-oc-print__pill" data-tone="<?php echo esc_attr( $receipt['pill']['tone'] ); ?>"><?php echo esc_html( $receipt['status'] ); ?></span></dd></div>
			<?php if ( '' !== (string) $receipt['payment_ref'] ) : ?>
				<div><dt><?php esc_html_e( 'Référence', 'delicat-builder-v9' ); ?></dt><dd><?php echo esc_html( $receipt['payment_ref'] ); ?></dd></div>
			<?php endif; ?>
		</dl>
		<ul class="dlc-oc-print__lines">
			<?php foreach ( $receipt['lines'] as $dlc_oc_line ) : ?>
				<li>
					<span class="dlc-oc-print__qty"><?php echo esc_html( (string) $dlc_oc_line['qty'] ); ?>×</span>
					<span class="dlc-oc-print__name"><?php echo esc_html( $dlc_oc_line['name'] ); ?>
						<?php if ( '' !== (string) $dlc_oc_line['player'] ) : ?>
							<small>
								<?php
								/* translators: %s: player ID */
								echo esc_html( Delicat_Builder_V9_Order_Status::fr( sprintf( __( 'ID : %s', 'delicat-builder-v9' ), $dlc_oc_line['player'] ) ) );
								?>
							</small>
						<?php endif; ?>
					</span>
					<span class="dlc-oc-print__amount"><?php echo esc_html( $dlc_oc_line['total'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( (int) $receipt['more'] > 0 ) : ?>
			<p class="dlc-oc-print__more">
				<?php
				/* translators: %d: number of lines not shown */
				echo esc_html( sprintf( __( '+ %d autres articles', 'delicat-builder-v9' ), (int) $receipt['more'] ) );
				?>
			</p>
		<?php endif; ?>
		<dl class="dlc-oc-print__rows">
			<?php foreach ( $receipt['rows'] as $dlc_oc_row ) : ?>
				<div><dt><?php echo esc_html( $dlc_oc_row['label'] ); ?></dt><dd><?php echo esc_html( $dlc_oc_row['value'] ); ?></dd></div>
			<?php endforeach; ?>
		</dl>
		<div class="dlc-oc-print__total"><span><?php echo esc_html( $receipt['total']['label'] ); ?></span><strong><?php echo esc_html( $receipt['total']['value'] ); ?></strong></div>
		<div class="dlc-oc-print__foot">
			<?php if ( '' !== $qr_svg ) : ?>
				<span class="dlc-oc-print__qr"><?php echo $qr_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from booleans and integers by Delicat_Builder_V9_Qr::svg(). ?></span>
			<?php endif; ?>
			<div>
				<?php foreach ( $receipt['footer'] as $dlc_oc_i => $dlc_oc_line ) : ?>
					<p class="<?php echo 0 === $dlc_oc_i ? 'dlc-oc-print__thanks' : ''; ?>"><?php echo esc_html( $dlc_oc_line ); ?></p>
				<?php endforeach; ?>
				<?php if ( ! empty( $receipt['link'] ) ) : ?>
					<p class="dlc-oc-print__link"><?php echo esc_html( $receipt['link'] ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
