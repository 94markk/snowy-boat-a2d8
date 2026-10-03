<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="delicat-v9-server-main" class="delicat-v9-server-main" data-delicat-server-render="1">
	<?php
	$html = class_exists( 'Delicat_Builder_V9_Server_Engine', false ) ? ( is_callable( array( 'Delicat_Builder_V9_Server_Engine', 'render_current' ) ) ? Delicat_Builder_V9_Server_Engine::render_current() : Delicat_Builder_V9_Server_Engine::render_current_page() ) : '';
	if ( '' !== $html ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes component output.
	} else {
		// Emergency compatibility fallback if rendering fails after template selection.
		while ( have_posts() ) {
			the_post();
			the_content();
		}
	}
	?>
</main>
<?php
get_footer();
