<?php
/**
 * Hero: eyebrow, title, text, search.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
$ds_image = (string) ds_opt( 'hero_image' );
$ds_woo   = ds_is_woo();
?>
<section class="ds-hero<?php echo '' !== $ds_image ? ' ds-hero--image' : ''; ?>" <?php echo '' !== $ds_image ? 'style="--ds-hero-image:url(' . esc_url( $ds_image ) . ')"' : ''; ?>>
	<div class="ds-container ds-hero__inner">
		<p class="ds-hero__eyebrow"><?php echo esc_html( (string) ds_opt( 'hero_eyebrow' ) ); ?></p>
		<h1 class="ds-hero__title"><?php echo esc_html( (string) ds_opt( 'hero_title' ) ); ?></h1>
		<p class="ds-hero__text"><?php echo esc_html( (string) ds_opt( 'hero_text' ) ); ?></p>
		<?php if ( (int) ds_opt( 'hero_search' ) === 1 ) : ?>
			<form class="ds-search ds-search--hero" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<label class="screen-reader-text" for="ds-hero-q">Rechercher un produit</label>
				<?php ds_the_icon( 'search', 'ds-search__icon' ); ?>
				<input id="ds-hero-q" class="ds-search__input" type="search" name="s" list="<?php echo esc_attr( ds_search_datalist_id() ); ?>" placeholder="Free Fire, Netflix, Google Play…" autocomplete="off">
				<?php if ( $ds_woo ) : ?><input type="hidden" name="post_type" value="product"><?php endif; ?>
				<button type="submit" class="ds-btn ds-btn--brand">Chercher</button>
			</form>
		<?php endif; ?>
		<div class="ds-hero__actions">
			<a class="ds-btn ds-btn--brand ds-btn--large" href="<?php echo esc_url( ds_shop_url() ); ?>"><?php ds_the_icon( 'grid' ); ?><span>Voir la boutique</span></a>
			<?php $ds_how = ds_page_url( 'comment-ca-marche' ); ?>
			<?php if ( '' !== $ds_how ) : ?>
				<a class="ds-btn ds-btn--ghost ds-btn--large" href="<?php echo esc_url( $ds_how ); ?>">Comment ça marche</a>
			<?php endif; ?>
		</div>
	</div>
</section>
