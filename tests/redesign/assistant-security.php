<?php
// php assistant-security.php <site-dir> — STAGING ONLY. Regression checks for the Assistant 7.0 security review:
//   M1  a message restored by the team is not hidden again by the reports it already had;
//   M2  a customer never appears under a store or staff name (own name, look-alikes, names frozen by older versions).
// Creates temporary customers, runs the real AJAX handlers (report, moderate, profile) and removes everything it created.
$site = $argv[1] ?? '';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php assistant-security.php <site-dir>\n" ); exit( 2 ); }
define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
dsa2_load_full_engine();
global $wpdb;

final class DSA7_Test_Response extends Exception {}
add_filter( 'wp_die_ajax_handler', static function () {
	return static function () { throw new DSA7_Test_Response(); };
} );

$pass = 0; $fail = 0;
$check = static function ( $label, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  (' . $detail . ')' ) . "\n";
	$ok ? $pass++ : $fail++;
};
// Runs one AJAX handler as $user_id and returns the decoded JSON reply.
$call = static function ( $handler, $user_id, array $post ) {
	wp_set_current_user( $user_id );
	delete_transient( 'dapg_rate_' . $user_id );
	$_POST = $_REQUEST = array_merge( $post, array( 'nonce' => wp_create_nonce( DSA2_Groups::NONCE ) ) );
	ob_start();
	try { call_user_func( array( 'DSA2_Groups', $handler ) ); } catch ( DSA7_Test_Response $e ) { /* wp_send_json ended the request */ }
	return json_decode( (string) ob_get_clean(), true );
};
$status = static function ( $id ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT status, report_count FROM ' . DSA2_Groups::table( 'messages' ) . ' WHERE id=%d', $id ) );
};

$group = DSA2_Groups::get_group( 'communaute-delicat' );
$agent = get_user_by( 'login', 'agent1' );
if ( ! $group || ! $agent ) { fwrite( STDERR, "run assistant-setup.php first (room communaute-delicat, user agent1)\n" ); exit( 2 ); }
$threshold = max( 1, (int) DSA2_Groups::settings()['groups_report_hide'] );

// Temporary customers registered 30 days ago, so their reports count towards hiding.
$users = array();
$old = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
for ( $i = 0; $i < $threshold + 2; $i++ ) {
	$login = 'dsa7sec_' . $i . '_' . wp_generate_password( 6, false );
	$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password( 20 ), 'role' => 'customer', 'first_name' => 'Testeur' . $i, 'user_registered' => $old ) );
	if ( is_wp_error( $id ) ) { fwrite( STDERR, $id->get_error_message() . "\n" ); exit( 2 ); }
	DSA2_Groups::add_member( (int) $group->id, (int) $id );
	$users[] = (int) $id;
}
$author = array_shift( $users );
$created = array();

try {
	/* ---------------------------------------------------------------- M1: restore is final for old reports */
	$mid = DSA2_Groups::insert_message( (int) $group->id, $author, 'Message signalé puis rétabli (test sécurité)' );
	$created[] = $mid;
	$reporters = array_slice( $users, 0, $threshold );
	foreach ( $reporters as $r ) $call( 'ajax_report', $r, array( 'message_id' => $mid, 'reason' => 'spam' ) );
	$check( "M1: $threshold reports from established accounts hide the message", 'hidden' === $status( $mid )->status );

	$reply = $call( 'ajax_moderate', (int) $agent->ID, array( 'op' => 'restore', 'message_id' => $mid ) );
	$check( 'M1: the team restores it', ! empty( $reply['success'] ) && 'visible' === $status( $mid )->status, wp_json_encode( $reply ) );

	foreach ( $reporters as $r ) $call( 'ajax_report', $r, array( 'message_id' => $mid, 'reason' => 'spam' ) );
	$s = $status( $mid );
	$check( 'M1: the same reporters cannot hide it again', 'visible' === $s->status && 0 === (int) $s->report_count, 'status=' . $s->status . ' count=' . $s->report_count );

	$fresh = $users[ $threshold ];
	$call( 'ajax_report', $fresh, array( 'message_id' => $mid, 'reason' => 'abuse' ) );
	$s = $status( $mid );
	$check( 'M1: a new report after the restore counts once and goes to the queue', 1 === (int) $s->report_count && ( $threshold > 1 ? 'visible' === $s->status : 'hidden' === $s->status ), 'status=' . $s->status . ' count=' . $s->report_count );

	/* ---------------------------------------------------------------- M2: no store or staff name for a customer */
	$expect = sprintf( 'Client #%d', $author );
	foreach ( array( 'Support Delicat', 'D e l i c a t', 'Adm1n', "D\u{0435}licat", 'Équipe officielle', 'Supp0rt' ) as $name ) {
		update_user_meta( $author, 'dap_group_name', $name );
		$check( "M2: customer name \"$name\" is shown as $expect", DSA2_Groups::display_name( $author ) === $expect, DSA2_Groups::display_name( $author ) );
	}
	update_user_meta( $author, 'dap_group_name', 'Jean B.' );
	$check( 'M2: an ordinary name is kept', 'Jean B.' === DSA2_Groups::display_name( $author ) );

	$reply = $call( 'ajax_profile', $author, array( 'display_name' => 'Delicat Officiel' ) );
	$check( 'M2: the profile form refuses a staff-like name', empty( $reply['success'] ) && 'Jean B.' === get_user_meta( $author, 'dap_group_name', true ), wp_json_encode( $reply ) );
	$reply = $call( 'ajax_profile', $author, array( 'display_name' => 'Jean Baptiste' ) );
	$check( 'M2: the profile form accepts an ordinary name', ! empty( $reply['success'] ) && 'Jean Baptiste' === DSA2_Groups::display_name( $author ), wp_json_encode( $reply ) );

	// A message written by an older version kept the author name in its meta: it is checked when shown.
	$frozen = DSA2_Groups::insert_message( (int) $group->id, $author, 'Ancien message (test sécurité)' );
	$created[] = $frozen;
	$wpdb->update( DSA2_Groups::table( 'messages' ), array( 'meta' => wp_json_encode( array( 'author' => 'Admin Delicat' ) ) ), array( 'id' => $frozen ) );
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DSA2_Groups::table( 'messages' ) . ' WHERE id=%d', $frozen ) );
	wp_set_current_user( $users[0] );
	$shown = DSA2_Groups::format_messages( array( $row ), $users[0] );
	$check( 'M2: a frozen staff-like name on an old message is replaced', $expect === ( $shown[0]['author'] ?? '' ), (string) ( $shown[0]['author'] ?? '' ) );

	$staff_msg = DSA2_Groups::insert_message( (int) $group->id, (int) $agent->ID, 'Message de l’équipe (test sécurité)' );
	$created[] = $staff_msg;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DSA2_Groups::table( 'messages' ) . ' WHERE id=%d', $staff_msg ) );
	$shown = DSA2_Groups::format_messages( array( $row ), $users[0] );
	$check( 'M2: the team keeps its own name and badge', 'agent' === $row->author_role && 0 !== strpos( (string) ( $shown[0]['author'] ?? '' ), 'Client #' ), (string) ( $shown[0]['author'] ?? '' ) );

	/* ---------------------------------------------------------------- L: chat start-up limits (dsa7_boot) */
	$_SERVER['REMOTE_ADDR'] = '198.51.100.77'; // documentation range, used only by this test
	$boot = static function ( $cookie ) {
		wp_set_current_user( 0 );
		$_COOKIE['dap_session'] = $cookie;
		$_POST = $_REQUEST = array( 'action' => 'dsa7_boot' );
		ob_start();
		try { DSA7_Launcher::ajax_boot(); } catch ( DSA7_Test_Response $e ) { /* reply sent */ }
		$reply = json_decode( (string) ob_get_clean(), true );
		return ! empty( $reply['success'] ) ? 'ok' : (string) ( $reply['data']['code'] ?? 'error' );
	};
	$counters = array( 'dsa7_boot_' . md5( $_SERVER['REMOTE_ADDR'] ) );
	$codes = array();
	for ( $i = 0; $i < 31; $i++ ) $codes[] = $boot( 'dsa7testvisitor' );
	$counters[] = 'dsa7_boot_' . md5( $_SERVER['REMOTE_ADDR'] . '|gdsa7testvisitor' );
	$ok = count( array_keys( $codes, 'ok', true ) );
	$check( 'L: one visitor gets 30 chat start-ups a minute, then a refusal', 30 === $ok && 'rate_limited' === end( $codes ), "ok=$ok last=" . end( $codes ) );
	$codes = array();
	for ( $i = 0; $i < 95; $i++ ) {
		$codes[] = $boot( 'dsa7rotated' . $i );
		$counters[] = 'dsa7_boot_' . md5( $_SERVER['REMOTE_ADDR'] . '|gdsa7rotated' . $i );
	}
	$ok = count( array_keys( $codes, 'ok', true ) );
	$check( 'L: rotating the guest cookie still stops at 120 for the address', 90 === $ok && 'rate_limited' === end( $codes ), "ok=$ok last=" . end( $codes ) );
	foreach ( $counters as $key ) delete_transient( $key ); // only the counters this run wrote
} finally {
	// Remove only what this run created.
	$ids = implode( ',', array_map( 'absint', array_filter( $created ) ) );
	if ( '' !== $ids ) {
		$wpdb->query( 'DELETE FROM ' . DSA2_Groups::table( 'reports' ) . " WHERE message_id IN ($ids)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DELETE FROM ' . DSA2_Groups::table( 'messages' ) . " WHERE id IN ($ids)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	foreach ( array_merge( array( $author ), $users ) as $u ) {
		$wpdb->delete( DSA2_Groups::table( 'members' ), array( 'user_id' => $u, 'group_id' => (int) $group->id ) );
		wp_delete_user( $u );
	}
	DSA2_Groups::refresh_counts( (int) $group->id );
}
echo "\n$pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
