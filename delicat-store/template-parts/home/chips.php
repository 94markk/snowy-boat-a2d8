<?php
/**
 * Category chips. Also used above the shop and category archives.
 *
 * @param array{title?:string} $args
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

if ( ! ds_is_woo() ) {
	return;
}
$ds_cats = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
		'number'     => 12,
		'orderby'    => 'count',
		'order'      => 'DESC',
	)
);
if ( ! is_array( $ds_cats ) || array() === $ds_cats ) {
	return;
}
$ds_current = is_product_category() ? (int) get_queried_object_id() : 0;
$ds_title   = isset( $args['title'] ) ? (string) $args['title'] : '';
?>
<section class="ds-section ds-section--chips">
	<div class="ds-container">
		<?php if ( '' !== $ds_title ) : ?><h2 class="ds-section__title ds-section__title--small"><?php echo esc_html( $ds_title ); ?></h2><?php endif; ?>
		<ul class="ds-chips" aria-label="Catégories">
			<li><a class="ds-chip<?php echo is_shop() ? ' is-active' : ''; ?>" href="<?php echo esc_url( ds_shop_url() ); ?>"><?php ds_the_icon( 'grid' ); ?><span>Tous</span></a></li>
			<?php foreach ( $ds_cats as $ds_cat ) : ?>
				<?php
				if ( in_array( $ds_cat->slug, array( 'uncategorized', 'non-classe' ), true ) ) {
					continue;
				}
				$ds_topic = ds_badge_topic_for( array( $ds_cat->slug, $ds_cat->name ) );
				$ds_icon  = $ds_topic ? ds_badge_topics()[ $ds_topic ]['icon'] : 'tag';
				?>
				<li><a class="ds-chip<?php echo $ds_current === (int) $ds_cat->term_id ? ' is-active' : ''; ?>" href="<?php echo esc_url( get_term_link( $ds_cat ) ); ?>"><?php ds_the_icon( $ds_icon ); ?><span><?php echo esc_html( $ds_cat->name ); ?></span></a></li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
