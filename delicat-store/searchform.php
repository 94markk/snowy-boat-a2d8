<?php
/**
 * Search form.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
$ds_id = 'ds-q-' . wp_unique_id();
?>
<form class="ds-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="screen-reader-text" for="<?php echo esc_attr( $ds_id ); ?>">Rechercher</label>
	<input id="<?php echo esc_attr( $ds_id ); ?>" class="ds-search__input" type="search" name="s" list="<?php echo esc_attr( ds_search_datalist_id() ); ?>" placeholder="Rechercher un produit…" value="<?php echo esc_attr( get_search_query() ); ?>" autocomplete="off">
	<?php if ( ds_is_woo() ) : ?><input type="hidden" name="post_type" value="product"><?php endif; ?>
	<button type="submit" class="ds-search__btn" aria-label="Rechercher"><?php ds_the_icon( 'search' ); ?></button>
</form>
