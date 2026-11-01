<?php
// php rewards-security.php <site-dir> — STAGING ONLY. Regression checks for the Delicat Rewards 3.3.0 security fixes.
// Run rewards-setup.php first. Everything this script creates is removed at the end.
$site = $argv[1] ?? '';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php rewards-security.php <site-dir>\n" ); exit( 2 ); }
define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php'; $_SERVER['SERVER_NAME'] = 'localhost'; $_SERVER['REMOTE_ADDR'] = '198.51.100.88';
require $site . '/wp-load.php';
global $wpdb;

final class DRW_Test_Response extends Exception {}
add_filter( 'wp_die_ajax_handler', static function () { return static function () { throw new DRW_Test_Response(); }; } );
$pass = 0; $fail = 0;
$check = static function ( $label, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  (' . $detail . ')' ) . "\n";
	$ok ? $pass++ : $fail++;
};
$c1 = get_user_by( 'login', 'client1' ); $c2 = get_user_by( 'login', 'client2' ); $c3 = get_user_by( 'login', 'client3' );
if ( ! $c1 || ! $c2 || ! $c3 || ! function_exists( 'delicat_order_units' ) ) { fwrite( STDERR, "run rewards-setup.php first\n" ); exit( 2 ); }
$pin = (int) delicat_pid_by_diamonds( 110 );
$cleanup_orders = array();

try {
	/* F3 — a free line (100 % coupon, $0 order) earns no progress; a paid one does. */
	$free = wc_create_order( array( 'customer_id' => $c3->ID ) );
	$free->add_product( wc_get_product( $pin ), 1 );
	foreach ( $free->get_items() as $item ) { $item->set_subtotal( 150 ); $item->set_total( 0 ); $item->save(); }
	$free->set_total( 0 ); $free->set_date_paid( time() ); $free->save(); $free->update_status( 'completed' );
	$cleanup_orders[] = $free->get_id();
	$units = delicat_order_units( wc_get_order( $free->get_id() ), delicat_tiers() );
	$check( 'F3: a 100 % coupon line adds no tier progress', empty( $units['tiers'] ), wp_json_encode( $units ) );
	$paid = wc_create_order( array( 'customer_id' => $c3->ID ) );
	$paid->add_product( wc_get_product( $pin ), 1 ); $paid->calculate_totals(); $paid->set_date_paid( time() ); $paid->save(); $paid->update_status( 'completed' );
	$cleanup_orders[] = $paid->get_id();
	$units = delicat_order_units( wc_get_order( $paid->get_id() ), delicat_tiers() );
	$check( 'F3: a paid line still counts', 1 === (int) ( $units['tiers']['mini'] ?? 0 ), wp_json_encode( $units ) );

	/* F1 — the app's « Mes bonus » only for the signed-in customer. */
	wp_set_current_user( $c1->ID );
	$other = delicat_rewards_app_rewards( $c2->ID );
	$check( 'F1: another customer\'s bonus state is refused', is_wp_error( $other ) && 403 === (int) ( $other->get_error_data()['status'] ?? 0 ) );
	$own = delicat_rewards_app_rewards( $c1->ID );
	$check( 'F1: the customer\'s own state is served', is_array( $own ) );

	/* F5 — one claim number, one reward, enforced by the database. */
	$month = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	$a = delicat_claim_slot( $c1->ID, $month, 'zz-test', 7 );
	$b = delicat_claim_slot( $c1->ID, $month, 'zz-test', 7 );
	$check( 'F5: the same claim slot cannot be taken twice', '' !== $a && '' === $b );
	delicat_claim_slot_release( $a );
	$c = delicat_claim_slot( $c1->ID, $month, 'zz-test', 7 );
	$check( 'F5: a released slot (failed issue) can be taken again', '' !== $c );
	$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 3600 ) ), array( 'option_name' => $c ) );
	$d = delicat_claim_slot( $c1->ID, $month, 'zz-test', 7 );
	$check( 'F5: a slot left by a dead request is taken over after 15 minutes', '' !== $d );
	delicat_claim_slot_release( $d );

	/* L3 — a refund id asked as a reward order: a plain 404, no fatal error. */
	$refund = wc_create_refund( array( 'order_id' => $paid->get_id(), 'amount' => 1, 'reason' => 'test' ) );
	$rid = is_wp_error( $refund ) ? 0 : $refund->get_id();
	$res = delicat_reward_delivery( $rid, $c3->ID );
	$check( 'L3: a refund id is not a reward order (404)', $rid > 0 && is_wp_error( $res ), $rid ? '' : 'refund not created' );

	/* F4 — licence check: lower-case keys work; a client nonce comes back inside the signed reply. */
	$key = 'DLR-AAAA1111-BBBB2222-CCCC3333-DDDD4444';
	$all = delicat_license_records();
	$all[ hash( 'sha256', $key ) ] = array( 'last4' => '4444', 'active' => 1, 'domain' => '', 'expires' => '', 'note' => 'test', 'created' => current_time( 'mysql' ) );
	delicat_license_save_records( $all );
	$req = new WP_REST_Request( 'POST', '/delicat-license/v1/validate' );
	$req->set_body_params( array( 'license_key' => strtolower( $key ), 'domain' => 'client.example', 'nonce' => 'n0nce-123' ) );
	$data = rest_do_request( $req )->get_data();
	$check( 'F4: a key typed in lower case is recognised', ! empty( $data['valid'] ), wp_json_encode( $data ) );
	$sig = $data['signature'] ?? ''; unset( $data['signature'] );
	$check( 'F4: the client nonce is signed into the reply', 'n0nce-123' === ( $data['nonce'] ?? '' ) && hash_equals( delicat_license_signature( $data, $key ), (string) $sig ) );
	$all = delicat_license_records(); unset( $all[ hash( 'sha256', $key ) ] ); delicat_license_save_records( $all );

	/* Boot request: guests get no personal data; a customer gets their own session and state. */
	$boot = static function ( $uid ) {
		wp_set_current_user( $uid ); delete_transient( 'delicat_bb_' . ( $uid ? 'u' . $uid : md5( '198.51.100.88' ) ) );
		ob_start(); try { delicat_bonus_boot(); } catch ( DRW_Test_Response $e ) { /* sent */ }
		return json_decode( (string) ob_get_clean(), true );
	};
	$g = $boot( 0 );
	$check( 'boot: a guest gets no nonce, no account, no bonus state, no private board', is_array( $g ) && '' === $g['nonce'] && 0 === $g['userId'] && ! isset( $g['rewards'] ) && ! isset( $g['board'] ), wp_json_encode( $g ) );
	$u = $boot( $c1->ID );
	$check( 'boot: a customer gets their session, state and the board (names only)', '' !== $u['nonce'] && $c1->ID === $u['userId'] && isset( $u['rewards']['tiers'], $u['board']['top'] ) && ! isset( $u['board']['top'][0]['uid'] ) );
	$check( 'boot: board rows carry no money, order count or account id', ! preg_match( '/"(uid|spend|orders|email|user_id)"/', wp_json_encode( $u['board'] ) ) );
} finally {
	foreach ( $cleanup_orders as $id ) { $o = wc_get_order( $id ); if ( $o ) $o->delete( true ); }
	if ( function_exists( 'delicat_flush_user' ) ) delicat_flush_user( $c3->ID );
}
echo "\n$pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
