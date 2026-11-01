<?php
// php referral-setup.php <site-dir> <command> [args] — STAGING ONLY. Referral (« Parrainage », Delicat Rewards 3.4).
// Run rewards-setup.php first (PIN products, test PIN fixture). Commands:
//   setup                                  programme on (no holding period, small milestones), test accounts reset,
//                                          staging fixture (per-browser network address, VPN switch, sign-up request)
//   order  <login> <total> <method> [phone] a completed order for that customer (method: bacs, wallet…)
//   refund <login>                         refunds the customer's latest order
//   state  <login>                         JSON: the customer's invitation, points, blocks, wallet, stored signals
//   seed   <login> <n>                     n confirmed invitations from placeholder friends (points cap test)
//   unblock <login>                        lifts a referral block (as the team does in the dashboard)
// Test accounts: ref1 (the inviter, a customer since 60 days) and rf* (friends the tests sign up).
$site = $argv[1] ?? '';
$cmd  = $argv[2] ?? 'setup';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php referral-setup.php <site-dir> <command>\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( false === strpos( home_url(), 'localhost' ) ) { fwrite( STDERR, "refusing: not a staging site\n" ); exit( 3 ); }
if ( ! function_exists( 'delicat_ref_table' ) ) { fwrite( STDERR, "Delicat Rewards 3.4 is not active\n" ); exit( 1 ); }
global $wpdb;

function rs_user( $login ) {
	$u = get_user_by( 'login', $login );
	if ( ! $u ) { fwrite( STDERR, "no user $login\n" ); exit( 1 ); }
	return $u;
}
function rs_pin_product() {
	$p = get_page_by_path( 'pin-free-110-diamants', OBJECT, 'product' );
	if ( ! $p ) { fwrite( STDERR, "run rewards-setup.php first\n" ); exit( 1 ); }
	return wc_get_product( $p->ID );
}

if ( 'setup' === $cmd ) {
	$mu = WP_CONTENT_DIR . '/mu-plugins';
	if ( ! is_dir( $mu ) ) mkdir( $mu, 0755, true );
	file_put_contents( $mu . '/delicat-referral-staging.php', <<<'PHP'
<?php
/* STAGING ONLY (tests/redesign/referral-setup.php): each test browser plays a different phone and network. */
if ( 0 !== strpos( (string) ( $_SERVER['HTTP_HOST'] ?? '' ), 'localhost' ) ) return;
// The network address a test browser states (X-Staging-IP), as Cloudflare's address would be on the live site.
if ( isset( $_SERVER['HTTP_X_STAGING_IP'] ) && filter_var( $_SERVER['HTTP_X_STAGING_IP'], FILTER_VALIDATE_IP ) ) $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_STAGING_IP'];
// VPN verdict: the cookie staging_vpn=1 plays a customer behind a VPN.
add_filter( 'delicat_ref_vpn_verdict', function ( $verdict ) { return isset( $_COOKIE['staging_vpn'] ) ? '1' === $_COOKIE['staging_vpn'] : $verdict; } );
// A sign-up like the store's forms: the account is created in this request, with this browser's cookies.
add_action( 'wp_ajax_nopriv_staging_ref_signup', function () {
	$login = sanitize_user( wp_unslash( $_POST['login'] ?? '' ), true );
	if ( 0 !== strpos( $login, 'rf' ) ) wp_send_json( array( 'ok' => false, 'error' => 'test accounts only' ), 400 );
	$id = wp_insert_user( array(
		'user_login' => $login, 'user_email' => sanitize_email( wp_unslash( $_POST['email'] ?? $login . '@example.test' ) ),
		'user_pass' => 'ClientPass!2026', 'role' => 'customer', 'first_name' => sanitize_text_field( wp_unslash( $_POST['first'] ?? 'Ami' ) ),
		'last_name' => 'Test', 'display_name' => sanitize_text_field( wp_unslash( $_POST['first'] ?? 'Ami' ) ) . ' Test',
	) );
	if ( is_wp_error( $id ) ) wp_send_json( array( 'ok' => false, 'error' => $id->get_error_message() ), 400 );
	update_user_meta( $id, 'dip_email_verified', 'yes' );
	if ( ! empty( $_POST['phone'] ) ) update_user_meta( $id, 'billing_phone', sanitize_text_field( wp_unslash( $_POST['phone'] ) ) );
	wp_set_current_user( $id ); wp_set_auth_cookie( $id, true );
	wp_send_json( array( 'ok' => true, 'id' => (int) $id ) );
} );
PHP
	);

	// Test accounts: friends from earlier runs are removed with their orders and referral data.
	$friends = $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'rf%'" );
	$ref1 = get_user_by( 'login', 'ref1' );
	$ids = array_map( 'intval', array_merge( $friends, $ref1 ? array( $ref1->ID ) : array() ) );
	if ( $ids ) {
		$in = implode( ',', $ids );
		$wpdb->query( 'DELETE FROM ' . delicat_ref_table( 'referrals' ) . " WHERE referrer_id IN ($in) OR referred_id IN ($in) OR referred_id>=900000" ); // phpcs:ignore
		$wpdb->query( 'DELETE FROM ' . delicat_ref_table( 'claims' ) . " WHERE user_id IN ($in)" ); // phpcs:ignore
		$wpdb->query( 'DELETE FROM ' . delicat_ref_table( 'signals' ) . " WHERE user_id IN ($in)" ); // phpcs:ignore
	}
	foreach ( $friends as $fid ) {
		foreach ( wc_get_orders( array( 'customer_id' => (int) $fid, 'limit' => -1 ) ) as $o ) $o->delete( true );
		wp_delete_user( (int) $fid );
	}
	if ( ! $ref1 ) {
		$id = wp_insert_user( array( 'user_login' => 'ref1', 'user_email' => 'ref1@example.test', 'user_pass' => 'ClientPass!2026', 'role' => 'customer', 'first_name' => 'Ralph', 'last_name' => 'Dorval', 'display_name' => 'Ralph Dorval' ) );
		$ref1 = get_user_by( 'id', $id );
		update_user_meta( $ref1->ID, 'dip_email_verified', 'yes' );
	}
	$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ), array( 'ID' => $ref1->ID ) );
	clean_user_cache( $ref1->ID );
	foreach ( array( '_delicat_ref_code', '_delicat_ref_blocked', '_delicat_ref_blocked_at', '_delicat_ref_excluded_by', '_delicat_ref_vpn', '_delicat_excluded' ) as $k ) delete_user_meta( $ref1->ID, $k );
	update_user_meta( $ref1->ID, 'billing_phone', '+509 3700 1111' );
	foreach ( wc_get_orders( array( 'customer_id' => $ref1->ID, 'limit' => -1 ) ) as $o ) $o->delete( true );
	$o = wc_create_order( array( 'customer_id' => $ref1->ID ) );
	$o->add_product( rs_pin_product(), 1 ); $o->set_payment_method( 'bacs' ); $o->set_billing_phone( '+509 3700 1111' );
	$o->calculate_totals(); $o->set_date_paid( time() - 20 * DAY_IN_SECONDS ); $o->save(); $o->update_status( 'completed', 'Staging order.' );

	$cfg = delicat_ref_defaults();
	$cfg['enabled'] = 1; $cfg['hold_days'] = 0;
	$cfg['milestones'] = array(
		array( 'points' => 1, 'diamonds' => 110, 'htg' => 140 ),
		array( 'points' => 2, 'diamonds' => 341, 'htg' => 420 ),
		array( 'points' => 4, 'diamonds' => 572, 'htg' => 700 ),
		array( 'points' => 100, 'diamonds' => 572, 'htg' => 4000 ),
	);
	update_option( 'delicat_ref_config', $cfg, false );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%delicat\\_rv\\_%' OR option_name LIKE '\\_transient%delicat\\_rc\\_%' OR option_name LIKE '\\_transient%delicat\\_rs\\_%' OR option_name LIKE '\\_transient%delicat\\_ref\\_alert\\_%' OR option_name LIKE '\\_transient%delicat\\_vpn\\_%' OR option_name LIKE '\\_transient%delicat\\_bb\\_%' OR option_name LIKE '\\_transient%delicat\\_rl%'" ); // claim counters too: the suite claims several times a run
	$page = get_page_by_path( 'bonus-free-fire' );
	if ( $page ) update_option( 'delicat_bonus_page_url', get_permalink( $page ), false );
	echo wp_json_encode( array( 'ref1' => (int) $ref1->ID, 'page' => $page ? get_permalink( $page ) : '' ) ) . "\n";
	exit;
}

if ( 'order' === $cmd ) {
	$u = rs_user( $argv[3] ?? '' );
	$total = (float) ( $argv[4] ?? 150 ); $method = (string) ( $argv[5] ?? 'bacs' ); $phone = (string) ( $argv[6] ?? '' );
	$o = wc_create_order( array( 'customer_id' => $u->ID ) );
	$o->add_product( rs_pin_product(), 1 );
	$o->set_payment_method( $method ); $o->set_billing_email( $u->user_email );
	if ( '' !== $phone ) $o->set_billing_phone( $phone );
	$o->calculate_totals(); $o->set_total( $total ); $o->set_date_paid( time() - 60 ); $o->save();
	$o->update_status( 'completed', 'Staging order.' );
	echo wp_json_encode( array( 'order' => $o->get_id() ) ) . "\n";
	exit;
}

if ( 'refund' === $cmd ) {
	$u = rs_user( $argv[3] ?? '' );
	$o = wc_get_orders( array( 'customer_id' => $u->ID, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ) )[0] ?? null;
	if ( ! $o ) { fwrite( STDERR, "no order\n" ); exit( 1 ); }
	$o->update_status( 'refunded', 'Staging refund.' );
	echo wp_json_encode( array( 'order' => $o->get_id() ) ) . "\n";
	exit;
}

if ( 'state' === $cmd ) {
	$u = rs_user( $argv[3] ?? '' );
	$row = delicat_ref_row_for( $u->ID );
	$signals = $wpdb->get_results( $wpdb->prepare( 'SELECT kind,hash FROM ' . delicat_ref_table( 'signals' ) . ' WHERE user_id=%d', $u->ID ) );
	echo wp_json_encode( array(
		'id'       => (int) $u->ID,
		'row'      => $row ? array( 'status' => $row->status, 'reason' => $row->reason, 'source' => $row->source, 'referrer' => (int) $row->referrer_id, 'order' => (int) $row->order_id ) : null,
		'points'   => delicat_ref_points( $u->ID ),
		'refBlock' => (string) get_user_meta( $u->ID, '_delicat_ref_blocked', true ),
		'excluded' => function_exists( 'delicat_is_excluded' ) && delicat_is_excluded( $u->ID ),
		'wallet'   => function_exists( 'woo_wallet' ) ? (float) woo_wallet()->wallet->get_wallet_balance( $u->ID, 'edit' ) : null,
		'claims'   => $wpdb->get_results( $wpdb->prepare( 'SELECT milestone,choice,amount,status,ref_id FROM ' . delicat_ref_table( 'claims' ) . ' WHERE user_id=%d', $u->ID ), ARRAY_A ),
		'signals'  => array_map( function ( $s ) { return $s->kind . ':' . $s->hash; }, (array) $signals ),
	) ) . "\n";
	exit;
}

if ( 'seed' === $cmd ) {
	$u = rs_user( $argv[3] ?? '' );
	$n = max( 1, min( 200, (int) ( $argv[4] ?? 1 ) ) );
	$base = 900000 + (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . delicat_ref_table( 'referrals' ) . ' WHERE referred_id>=900000' ); // phpcs:ignore
	for ( $i = 0; $i < $n; $i++ ) {
		$wpdb->insert( delicat_ref_table( 'referrals' ), array( 'referrer_id' => $u->ID, 'referred_id' => $base + $i, 'source' => 'link', 'status' => 'confirmed', 'reason' => '', 'created_at' => current_time( 'mysql', 1 ), 'decided_at' => current_time( 'mysql', 1 ) ) );
	}
	echo wp_json_encode( array( 'points' => delicat_ref_points( $u->ID ) ) ) . "\n";
	exit;
}

if ( 'unblock' === $cmd ) {
	$u = rs_user( $argv[3] ?? '' );
	delicat_ref_unblock( $u->ID );
	echo wp_json_encode( array( 'refBlock' => (string) get_user_meta( $u->ID, '_delicat_ref_blocked', true ), 'excluded' => function_exists( 'delicat_is_excluded' ) && delicat_is_excluded( $u->ID ) ) ) . "\n";
	exit;
}

fwrite( STDERR, "unknown command $cmd\n" );
exit( 2 );
