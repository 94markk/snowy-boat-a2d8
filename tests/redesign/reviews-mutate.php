<?php
// php reviews-mutate.php <site-dir> <action> [post-id] — STAGING ONLY. Used by reviews-980.cjs to check that the
// review figures follow real changes on their own (no manual cache flush here):
//   stats                 print Delicat_Builder_V9_Reviews::stats()
//   publish <id>          approve a pending store review          then print stats
//   unpublish <id>        put it back to pending                  then print stats
//   hide-product <id>     move a reviewed product to draft        then print stats
//   show-product <id>     publish it again                        then print stats
$rv_site   = $argv[1] ?? '';
$rv_action = $argv[2] ?? '';
$rv_id     = (int) ( $argv[3] ?? 0 );
if ( '' === $rv_site || ! is_file( $rv_site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php reviews-mutate.php <site-dir> <action> [post-id]\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/wp-admin/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $rv_site . '/wp-load.php';
if ( false === strpos( home_url(), 'localhost' ) ) { fwrite( STDERR, "refusing: not a staging site\n" ); exit( 3 ); }
wp_set_current_user( 1 );
if ( ! class_exists( 'Delicat_Builder_V9_Reviews', false ) ) { require_once WP_PLUGIN_DIR . '/delicat-builder-v9/includes/class-delicat-builder-reviews.php'; }

$rv_status = array( 'publish' => 'publish', 'unpublish' => 'pending', 'hide-product' => 'draft', 'show-product' => 'publish' );
if ( isset( $rv_status[ $rv_action ] ) ) {
	if ( $rv_id <= 0 ) { fwrite( STDERR, "post id required\n" ); exit( 2 ); }
	$rv_res = wp_update_post( array( 'ID' => $rv_id, 'post_status' => $rv_status[ $rv_action ] ), true );
	if ( is_wp_error( $rv_res ) ) { fwrite( STDERR, $rv_res->get_error_message() . "\n" ); exit( 4 ); }
} elseif ( 'stats' !== $rv_action ) {
	fwrite( STDERR, "unknown action\n" ); exit( 2 );
}
echo wp_json_encode( Delicat_Builder_V9_Reviews::stats() ), "\n";
