<?php
/**
 * Homepage. Sections come from inc/homepage.php; without WooCommerce the
 * page falls back to the static page content.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
get_header();
?>
<main id="main" class="ds-main ds-main--home">
	<?php
	if ( function_exists( 'woocommerce_output_all_notices' ) ) {
		echo '<div class="ds-container">';
		woocommerce_output_all_notices();
		echo '</div>';
	}
	if ( function_exists( 'ds_home_render' ) ) {
		ds_home_render();
	} else {
		get_template_part( 'template-parts/home/hero' );
		echo '<div class="ds-container ds-prose">';
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		echo '</div>';
	}
	?>
</main>
<?php
get_footer();
