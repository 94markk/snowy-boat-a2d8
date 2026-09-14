<?php
/**
 * WooCommerce pages (shop, category, tag, product, product search).
 *
 * woocommerce_content() renders the archive loop or the single product but
 * does not fire the before/after_main_content hooks that the plugin's own
 * templates do, so the page wrapper is printed here.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
get_header();
?>
<main id="main" class="ds-main ds-main--wc-<?php echo is_product() ? 'product' : 'archive'; ?>">
	<div class="ds-container">
		<?php
		if ( is_product() ) {
			woocommerce_breadcrumb(
				array(
					'delimiter'   => '<span class="ds-crumb__sep">/</span>',
					'wrap_before' => '<nav class="ds-crumb" aria-label="Fil d\'Ariane">',
					'wrap_after'  => '</nav>',
					'home'        => 'Accueil',
				)
			);
		}
		woocommerce_content();
		?>
	</div>
</main>
<?php
get_footer();
