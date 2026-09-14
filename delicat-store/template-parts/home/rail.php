<?php
/**
 * A titled rail or grid of products.
 *
 * @param array{eyebrow:string,title:string,subtitle:string,products:WC_Product[],more:string,layout:string} $args
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $args['products'] ) ) {
	return;
}
$ds_layout = 'grid' === ( $args['layout'] ?? 'rail' ) ? 'grid' : 'rail';
$ds_id     = 'ds-rail-' . sanitize_title( (string) $args['title'] );
?>
<section class="ds-section ds-section--products" aria-labelledby="<?php echo esc_attr( $ds_id ); ?>">
	<div class="ds-container">
		<div class="ds-section__head">
			<div>
				<?php if ( '' !== (string) ( $args['eyebrow'] ?? '' ) ) : ?><p class="ds-section__eyebrow"><?php echo esc_html( (string) $args['eyebrow'] ); ?></p><?php endif; ?>
				<h2 class="ds-section__title" id="<?php echo esc_attr( $ds_id ); ?>"><?php echo esc_html( (string) $args['title'] ); ?></h2>
				<?php if ( '' !== (string) ( $args['subtitle'] ?? '' ) ) : ?><p class="ds-section__subtitle"><?php echo esc_html( (string) $args['subtitle'] ); ?></p><?php endif; ?>
			</div>
			<?php if ( '' !== (string) ( $args['more'] ?? '' ) ) : ?>
				<a class="ds-section__more" href="<?php echo esc_url( (string) $args['more'] ); ?>"><span>Voir tout</span><?php ds_the_icon( 'arrow' ); ?></a>
			<?php endif; ?>
		</div>
		<?php echo ds_product_list( $args['products'], $ds_layout ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</section>
