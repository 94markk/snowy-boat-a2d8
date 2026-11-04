<?php
// php archive-setup.php <site-dir> — STAGING ONLY. Applies Delicat Builder's catalogue preset (9.7.4:
// archive-v5-home, the homepage card) as an administrator's first wp-admin visit would, and prints the shop
// and a category address as JSON.
$site = $argv[1] ?? '';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php archive-setup.php <site-dir>\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/wp-admin/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
if ( false === strpos( home_url(), 'localhost' ) ) { fwrite( STDERR, "refusing: not a staging site\n" ); exit( 3 ); }
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admin ) { fwrite( STDERR, "no administrator\n" ); exit( 1 ); }
wp_set_current_user( (int) $admin[0] );
if ( ! class_exists( 'Delicat_Builder_V9_Archive_Builder' ) ) require_once WP_PLUGIN_DIR . '/delicat-builder-v9/includes/class-delicat-builder-archive-builder.php';
delete_option( 'delicat_builder_v9_native_archive_preset' );
Delicat_Builder_V9_Archive_Builder::maybe_install_native_preset();
if ( 'legacy' === ( $argv[2] ?? '' ) ) {
	// 9.7.5: the live store's case: settings saved as « Classique » (stock badge on) and the preset already stamped,
	// so nothing switches them. The shop must still print the homepage card.
	$configs = get_option( Delicat_Builder_V9_Archive_Builder::OPTION, array() );
	foreach ( $configs as &$config ) {
		if ( is_array( $config ) ) { $config['card_style'] = 'minimal'; $config['show_stock_badge'] = 1; $config['cta_label'] = 'Choisir'; }
	}
	unset( $config );
	update_option( Delicat_Builder_V9_Archive_Builder::OPTION, $configs, false );
	if ( class_exists( 'Delicat_Builder_V9_Cache', false ) ) { Delicat_Builder_V9_Cache::bump_version(); Delicat_Builder_V9_Cache::purge_product_archives(); }
}
$cat = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 1, 'exclude' => array( (int) get_option( 'default_product_cat' ) ) ) );
echo wp_json_encode( array(
	'preset'   => get_option( 'delicat_builder_v9_native_archive_preset' ),
	'style'    => Delicat_Builder_V9_Archive_Builder::get_config( 'global' )['card_style'],
	'shop'     => get_permalink( wc_get_page_id( 'shop' ) ),
	'category' => ( $cat && ! is_wp_error( $cat ) ) ? get_term_link( $cat[0] ) : get_permalink( wc_get_page_id( 'shop' ) ),
) ) . "\n";
