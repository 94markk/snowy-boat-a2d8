<?php
/**
 * FAQ (six questions from the Customizer).
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
$ds_items = array();
for ( $ds_i = 1; $ds_i <= 6; $ds_i++ ) {
	$ds_q = trim( (string) ds_opt( "faq_{$ds_i}_q" ) );
	$ds_a = trim( (string) ds_opt( "faq_{$ds_i}_a" ) );
	if ( '' !== $ds_q && '' !== $ds_a ) {
		$ds_items[] = array( $ds_q, $ds_a );
	}
}
if ( array() === $ds_items ) {
	return;
}
$ds_json = array(
	'@context'   => 'https://schema.org',
	'@type'      => 'FAQPage',
	'mainEntity' => array_map(
		static function ( array $item ): array {
			return array(
				'@type'          => 'Question',
				'name'           => $item[0],
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $item[1] ),
			);
		},
		$ds_items
	),
);
?>
<section class="ds-section ds-section--faq" aria-labelledby="ds-faq-title">
	<div class="ds-container ds-container--narrow">
		<div class="ds-section__head ds-section__head--center">
			<div>
				<p class="ds-section__eyebrow">FAQ</p>
				<h2 class="ds-section__title" id="ds-faq-title">Questions fréquentes</h2>
				<p class="ds-section__subtitle">Tout ce qu'il faut savoir avant de commander.</p>
			</div>
		</div>
		<div class="ds-faq">
			<?php foreach ( $ds_items as $ds_i => $ds_item ) : ?>
				<details class="ds-faq__item" <?php echo 0 === $ds_i ? 'open' : ''; ?>>
					<summary><span><?php echo esc_html( $ds_item[0] ); ?></span><?php ds_the_icon( 'chevron' ); ?></summary>
					<div class="ds-faq__answer"><p><?php echo esc_html( $ds_item[1] ); ?></p></div>
				</details>
			<?php endforeach; ?>
		</div>
	</div>
	<script type="application/ld+json"><?php echo wp_json_encode( $ds_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
</section>
