<?php
// php reseller-setup.php <site-dir> <command> [args] — STAGING ONLY. Delicat Resellers 1.0 test data. Commands:
//   setup                          programme open, pages, priced products, test customers rs1…rs4 reset
//   deposit  <login> <amount> [days_ago] [status]  a recharge as Delicat Recharge records it (approved + credited
//                                  by default) and its « approved » hook
//   transfer <from> <to> <amount>  a completed wallet-to-wallet transfer (Send Money) and its hook
//   approve  <login>               the team approves the customer's open application (step one)
//   state    <login>               JSON: level, roles, open application, month progress
//   eval     <login> <done-month>  pretend months up to <done-month> are settled, then settle the rest now
//   currency <login>               JSON: the 110 PIN's price for <login> with Builder showing USD and a fixed USD price
// Run rewards-setup.php first (PIN products).
$site = $argv[1] ?? '';
$cmd  = $argv[2] ?? 'setup';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php reseller-setup.php <site-dir> <command>\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( false === strpos( home_url(), 'localhost' ) ) { fwrite( STDERR, "refusing: not a staging site\n" ); exit( 3 ); }
if ( ! function_exists( 'dlr_apply' ) ) { fwrite( STDERR, "Delicat Resellers is not active\n" ); exit( 1 ); }
global $wpdb;
$user = function ( $login ) { $u = get_user_by( 'login', $login ); if ( ! $u ) { fwrite( STDERR, "no user $login\n" ); exit( 1 ); } return $u; };

if ( 'setup' === $cmd ) {
	update_option( 'dlr_config', array(
		'enabled' => 1, 'deposit_days' => 7, 'reminder_day' => 22, 'page_id' => 0, 'recharge_page_id' => 0, 'notify' => '',
		'tiers' => array(
			'premium' => array( 'label' => 'Premium Reseller', 'min' => 100000, 'discount' => 15 ),
			'gold'    => array( 'label' => 'Golden Reseller', 'min' => 50000, 'discount' => 10 ),
			'silver'  => array( 'label' => 'Silver Reseller', 'min' => 25000, 'discount' => 5 ),
		),
	), false );
	foreach ( array( 'programme-revendeur' => array( 'Programme Revendeur', '[delicat_revendeur]' ), 'recharge' => array( 'Recharge', '[delicat_recharge]' ) ) as $slug => $p ) {
		$page = get_page_by_path( $slug );
		$data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $p[0], 'post_content' => $p[1] );
		if ( $page ) { $data['ID'] = $page->ID; wp_update_post( $data ); } else wp_insert_post( $data );
	}
	delete_transient( 'dlr_page_' . md5( 'delicat_revendeur' ) ); delete_transient( 'dlr_page_' . md5( 'delicat_recharge' ) );
	// Prices: the 110-diamond PIN (150 HTG) has its own Gold and Premium prices; the 341 PIN (450) uses the % only.
	$p110 = wc_get_product( get_page_by_path( 'pin-free-110-diamants', OBJECT, 'product' )->ID );
	$p110->update_meta_data( '_dlr_price_gold', '120' ); $p110->update_meta_data( '_dlr_price_premium', '110' ); $p110->delete_meta_data( '_dlr_price_silver' ); $p110->save();
	$p341 = wc_get_product( get_page_by_path( 'pin-free-341-diamants', OBJECT, 'product' )->ID );
	foreach ( dlr_tier_keys() as $t ) $p341->delete_meta_data( '_dlr_price_' . $t );
	$p341->delete_meta_data( '_dlr_exclude' ); $p341->save();
	$p572 = wc_get_product( get_page_by_path( 'pin-free-572-diamants', OBJECT, 'product' )->ID );
	$p572->update_meta_data( '_dlr_exclude', 'yes' ); $p572->save(); // no reseller price at all
	// Test customers: rs1 (applies, deposits), rs2 (receives a transfer), rs3 (a reseller for the monthly rule), rs4 (sender).
	$names = array( 'rs1' => 'Rony Saint', 'rs2' => 'Rita Simon', 'rs3' => 'Rudy Sylvain', 'rs4' => 'Rose Sender' );
	$out = array();
	foreach ( $names as $login => $name ) {
		$u = get_user_by( 'login', $login );
		if ( ! $u ) {
			$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => 'ClientPass!2026', 'role' => 'customer', 'display_name' => $name ) );
			$u  = get_user_by( 'id', $id );
			update_user_meta( $u->ID, 'dip_email_verified', 'yes' );
		}
		dlr_set_tier( $u->ID, '', 'admin_set', 0, 'test reset' );
		foreach ( array( '_dlr_eval', '_dlr_reminded', '_dlr_since' ) as $k ) delete_user_meta( $u->ID, $k );
		$wpdb->delete( dlr_table( 'apps' ), array( 'user_id' => $u->ID ) );
		$wpdb->delete( dlr_table( 'log' ), array( 'user_id' => $u->ID ) );
		$wpdb->delete( $wpdb->prefix . 'delicat_recharges', array( 'user_id' => $u->ID ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}delicat_transfers WHERE sender_id=%d OR recipient_id=%d", $u->ID, $u->ID ) );
		delete_transient( 'dlr_apply_' . $u->ID );
		foreach ( wc_get_orders( array( 'customer_id' => $u->ID, 'limit' => -1 ) ) as $o ) $o->delete( true );
		$out[ $login ] = (int) $u->ID;
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dlr\\_lock\\_%'" );
	dlr_cfg( null, true );
	echo wp_json_encode( array( 'users' => $out, 'page' => dlr_page_url(), 'recharge' => dlr_recharge_url(), 'account' => wc_get_page_permalink( 'myaccount' ), 'accountPage' => function_exists( 'dlr_account_url' ) ? dlr_account_url() : '', 'product' => get_permalink( $p110->get_id() ), 'cart' => wc_get_cart_url(), 'products' => array( 'p110' => $p110->get_id(), 'p341' => $p341->get_id(), 'p572' => $p572->get_id() ) ) ) . "\n";
	exit;
}

if ( 'deposit' === $cmd ) {
	$u = $user( $argv[3] ?? '' ); $amount = (float) ( $argv[4] ?? 0 ); $ago = (int) ( $argv[5] ?? 0 ); $status = (string) ( $argv[6] ?? 'approuve' );
	$row = array( 'user_id' => $u->ID, 'user_email' => $u->user_email, 'tab' => 'moncash', 'methode' => 'moncash', 'montant' => $amount, 'trans_code' => 'TEST' . wp_generate_password( 8, false ), 'statut' => $status, 'wallet_credited' => 'approuve' === $status ? 1 : 0 );
	$wpdb->insert( $wpdb->prefix . 'delicat_recharges', $row );
	$id = (int) $wpdb->insert_id;
	if ( $ago ) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}delicat_recharges SET created_at = created_at - INTERVAL %d DAY WHERE id=%d", $ago, $id ) );
	if ( 'approuve' === $status ) do_action( 'delicat_recharge_approved', $id );
	echo wp_json_encode( array( 'recharge' => $id ) ) . "\n";
	exit;
}

if ( 'transfer' === $cmd ) {
	$from = $user( $argv[3] ?? '' ); $to = $user( $argv[4] ?? '' ); $amount = (float) ( $argv[5] ?? 0 );
	$wpdb->insert( $wpdb->prefix . 'delicat_transfers', array( 'ref_key' => wp_generate_password( 20, false ), 'sender_id' => $from->ID, 'recipient_id' => $to->ID, 'amount' => $amount, 'fee' => 0, 'status' => 'completed', 'created_at' => current_time( 'mysql' ) ) );
	do_action( 'delicat_transfer_completed', (int) $wpdb->insert_id, $from->ID, $to->ID, $amount, 0 );
	echo wp_json_encode( array( 'transfer' => (int) $wpdb->insert_id ) ) . "\n";
	exit;
}

if ( 'approve' === $cmd ) {
	$u = $user( $argv[3] ?? '' ); $app = dlr_open_app( $u->ID );
	$r = $app ? dlr_approve( $app->id ) : new WP_Error( 'none', 'no open application' );
	echo wp_json_encode( array( 'ok' => ! is_wp_error( $r ), 'error' => is_wp_error( $r ) ? $r->get_error_message() : '' ) ) . "\n";
	exit;
}

if ( 'state' === $cmd ) {
	$u = $user( $argv[3] ?? '' ); $app = dlr_last_app( $u->ID ); $fresh = get_userdata( $u->ID );
	echo wp_json_encode( array(
		'tier' => dlr_user_tier( $u->ID, true ), 'roles' => array_values( (array) $fresh->roles ),
		'app' => $app ? array( 'status' => $app->status, 'tier' => $app->tier, 'reason' => $app->reason ) : null,
		'month' => dlr_month_progress( $u->ID ), 'eval' => get_user_meta( $u->ID, '_dlr_eval', true ),
	) ) . "\n";
	exit;
}

if ( 'currency' === $cmd ) { // the 150 HTG PIN with a price typed by hand in USD (1.50), seen by <login> browsing in USD
	$u = $user( $argv[3] ?? '' );
	$p = get_page_by_path( 'pin-free-110-diamants', OBJECT, 'product' )->ID;
	update_post_meta( $p, '_dmc_price_USD', '1.50' );
	add_filter( 'dmc_current_currency', static function () { return 'USD'; } );
	wp_set_current_user( $u->ID );
	$price = wc_get_product( $p )->get_price();
	delete_post_meta( $p, '_dmc_price_USD' );
	echo wp_json_encode( array( 'tier' => dlr_user_tier( $u->ID ), 'price' => (string) $price, 'builder' => class_exists( 'Delicat_Builder_V9_Multi_Currency' ) ) ) . "\n";
	exit;
}

if ( 'eval' === $cmd ) {
	$u = $user( $argv[3] ?? '' );
	update_user_meta( $u->ID, '_dlr_eval', (string) ( $argv[4] ?? '' ) );
	dlr_evaluate_user( $u->ID );
	echo wp_json_encode( array( 'tier' => dlr_user_tier( $u->ID, true ), 'eval' => get_user_meta( $u->ID, '_dlr_eval', true ) ) ) . "\n";
	exit;
}

fwrite( STDERR, "unknown command $cmd\n" );
exit( 2 );
