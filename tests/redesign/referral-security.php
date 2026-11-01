<?php
// php referral-security.php <site-dir> — STAGING ONLY. Server checks for the referral programme (Delicat Rewards 3.4):
// keyed hashes only, forged/expired invitation cookies, self-referral, code-entry window, first-order rules, blocked
// accounts, one claim per milestone, points cap, rate limits, admin handlers, escaping, deletion, the VPN key.
// Run rewards-setup.php and referral-setup.php setup first. Accounts this script creates (rs*) are removed at the end.
$site = $argv[1] ?? '';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php referral-security.php <site-dir>\n" ); exit( 2 ); }
define( 'DOING_AJAX', true );
define( 'DELICAT_PROXYCHECK_KEY', 'pk-test-SECRET-123456' ); // a fake key: it must reach the provider and nothing else
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php'; $_SERVER['SERVER_NAME'] = 'localhost'; $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( false === strpos( home_url(), 'localhost' ) || ! function_exists( 'delicat_ref_table' ) ) { fwrite( STDERR, "staging with Delicat Rewards 3.4 only\n" ); exit( 2 ); }
global $wpdb;

final class DRF_Test_Response extends Exception {}
add_filter( 'wp_die_ajax_handler', static function () { return static function ( $message = '' ) { throw new DRF_Test_Response( is_string( $message ) ? $message : 'died' ); }; } );
$pass = 0; $fail = 0;
$check = static function ( $label, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  (' . $detail . ')' ) . "\n";
	$ok ? $pass++ : $fail++;
};
$ajax = static function ( callable $fn ) {
	ob_start(); try { $fn(); } catch ( DRF_Test_Response $e ) { /* sent */ }
	return json_decode( (string) ob_get_clean(), true );
};
$made = array(); $orders = array();
$user = static function ( $login, $extra = array() ) use ( &$made ) {
	$id = wp_insert_user( array_merge( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password( 20 ), 'role' => 'customer' ), $extra ) );
	if ( is_wp_error( $id ) ) { fwrite( STDERR, $id->get_error_message() . "\n" ); exit( 1 ); }
	$made[] = (int) $id; return (int) $id;
};
$order = static function ( $uid, $total, $method = 'bacs', $extra = null ) use ( &$orders ) {
	$p = get_page_by_path( 'pin-free-110-diamants', OBJECT, 'product' );
	$o = wc_create_order( array( 'customer_id' => $uid ) );
	$o->add_product( wc_get_product( $p->ID ), 1 ); $o->set_payment_method( $method ); $o->calculate_totals(); $o->set_total( $total );
	if ( $extra ) $extra( $o );
	$o->set_date_paid( time() ); $o->save(); $orders[] = $o->get_id();
	return $o;
};
// A stand-in for proxycheck.io (no request leaves staging): 8.8.4.4 is a VPN, 8.8.8.8 finds the service down.
$calls = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$calls ) {
	if ( false === strpos( $url, 'proxycheck.io' ) ) return $pre;
	$calls[] = array( $url, $args['timeout'] );
	if ( false !== strpos( $url, '/8.8.8.8' ) ) return new WP_Error( 'down', 'down' );
	$ip = (string) preg_replace( '#^.*/v2/([^?]+)\?.*$#', '$1', $url );
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'cookies' => array(), 'body' => wp_json_encode( array( rawurldecode( $ip ) => array( 'proxy' => '8.8.4.4' === rawurldecode( $ip ) ? 'yes' : 'no' ) ) ) );
}, 10, 3 );
foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'rs\\_%'" ) as $old ) wp_delete_user( (int) $old );
// Each test friend uses another phone: this process forgets the device cookie the previous one received.
$new_phone = static function () { unset( $_COOKIE['dip_device_id'], $_COOKIE['dlc_did'], $_COOKIE['dlc_ref'] ); };
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%delicat\\_r%' OR option_name LIKE '\\_transient%delicat\\_vpn\\_%'" );

try {
	$inv = $user( 'rs_inv', array( 'display_name' => '<img src=x onerror=alert(1)> Inviter' ) );
	$code = delicat_ref_code( $inv );
	$check( 'code: DLC + 6 characters without look-alikes, stable', (bool) preg_match( '/^DLC[A-HJ-NP-Z2-9]{6}$/', $code ) && delicat_ref_code( $inv ) === $code, $code );

	/* Signals: keyed hashes only, never the address, phone or e-mail. */
	$_COOKIE['dlc_did'] = str_repeat( 'A', 40 ); $_COOKIE['dip_device_id'] = str_repeat( 'B', 43 );
	delicat_ref_capture( $inv, str_repeat( 'ab', 32 ), false );
	update_user_meta( $inv, 'billing_phone', '+509 3799 0001' );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT kind,hash FROM ' . delicat_ref_table( 'signals' ) . ' WHERE user_id=%d', $inv ) );
	$dump = wp_json_encode( $rows );
	$check( 'signals: device, ip, fp and e-mail stored', 4 <= count( $rows ), $dump );
	$check( 'signals: only 64-hex keyed hashes (no address, e-mail or raw fingerprint)', ! preg_match( '/203\.0\.113|example\.test|abab/', $dump ) && ! array_filter( $rows, function ( $r ) { return ! preg_match( '/^[a-f0-9]{64}$/', $r->hash ); } ), $dump );

	/* Invitation cookie: signed, expiring. */
	$_COOKIE['dlc_ref'] = delicat_ref_cookie_value( $code );
	$check( 'cookie: a genuine invitation is read back', $code === delicat_ref_cookie_code() );
	$_COOKIE['dlc_ref'] = $code . '.' . time() . '.' . str_repeat( '0', 20 );
	$check( 'cookie: a forged signature is ignored', '' === delicat_ref_cookie_code() );
	$t = time() - ( (int) delicat_ref_cfg( 'attribution_days' ) + 1 ) * DAY_IN_SECONDS;
	$_COOKIE['dlc_ref'] = $code . '.' . $t . '.' . substr( hash_hmac( 'sha256', $code . '|' . $t, delicat_secret() ), 0, 20 );
	$check( 'cookie: an expired invitation is ignored', '' === delicat_ref_cookie_code() );
	unset( $_COOKIE['dlc_ref'], $_COOKIE['dlc_did'], $_COOKIE['dip_device_id'] );

	/* Self-referral and the code-entry window. */
	$check( 'self-referral refused', is_wp_error( delicat_ref_attach( $inv, $inv, 'code' ) ) );
	$f1 = $user( 'rs_f1' );
	wp_set_current_user( $f1 );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.81';
	$check( 'code entry: a new account may type a code', delicat_ref_can_enter_code( $f1 ) );
	$own = delicat_ref_enter_code( $f1, delicat_ref_code( $f1 ) );
	$check( 'code entry: own code refused', is_wp_error( $own ), wp_json_encode( $own ) );
	$paid = $order( $f1, 150 ); $paid->update_status( 'completed' );
	$check( 'code entry: closed after a first real purchase', ! delicat_ref_can_enter_code( $f1 ) );
	$old = $user( 'rs_old' );
	$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'ID' => $old ) ); clean_user_cache( $old );
	$check( 'code entry: closed for an old account', ! delicat_ref_can_enter_code( $old ) );

	/* What counts as a first order. */
	$f2 = $user( 'rs_f2' );
	$check( 'first order: a paid order above the minimum counts', delicat_ref_order_counts( tap( $order( $f2, 150 ), 'completed' ) ) );
	$check( 'first order: under the minimum does not count', ! delicat_ref_order_counts( tap( $order( $f2, 50 ), 'completed' ) ) );
	$check( 'first order: paid with store credit does not count', ! delicat_ref_order_counts( tap( $order( $f2, 150, 'wallet' ), 'completed' ) ) );
	$check( 'first order: a free order (100 % coupon) does not count', ! delicat_ref_order_counts( tap( $order( $f2, 0 ), 'completed' ) ) );
	$check( 'first order: a reward order does not count', ! delicat_ref_order_counts( tap( $order( $f2, 150, 'bacs', function ( $o ) { $o->update_meta_data( '_delicat_reward', 'referral_1' ); } ), 'completed' ) ) );
	$check( 'first order: an unpaid order does not count', ! delicat_ref_order_counts( $order( $f2, 150 ) ) );

	/* Review fixes (3.4.0): top-ups, partial refunds, orders placed before the invitation. */
	if ( function_exists( 'get_wallet_rechargeable_product' ) && get_wallet_rechargeable_product() ) {
		$top = wc_create_order( array( 'customer_id' => $f2 ) );
		$top->add_product( get_wallet_rechargeable_product(), 1, array( 'subtotal' => 200, 'total' => 200 ) ); $top->set_payment_method( 'bacs' ); $top->calculate_totals( false ); $top->set_total( 200 ); $top->set_date_paid( time() ); $top->save(); $orders[] = $top->get_id();
		$check( 'first order: a wallet top-up does not count', ! delicat_ref_order_counts( tap( $top, 'completed' ) ) );
	}
	$part = tap( $order( $f2, 200 ), 'completed' );
	wc_create_refund( array( 'order_id' => $part->get_id(), 'amount' => 50, 'reason' => 'test' ) );
	$check( 'first order: a partial refund leaving 150 HTG still counts', delicat_ref_order_counts( wc_get_order( $part->get_id() ) ) );
	wc_create_refund( array( 'order_id' => $part->get_id(), 'amount' => 100, 'reason' => 'test' ) );
	$check( 'first order: a refund leaving 50 HTG no longer counts', ! delicat_ref_order_counts( wc_get_order( $part->get_id() ) ) );
	$f6 = $user( 'rs_f6' );
	$held = $order( $f6, 150 ); $held->update_status( 'on-hold' );
	$check( 'code entry: closed while a first order waits (on hold)', ! delicat_ref_can_enter_code( $f6 ) );
	$f7 = $user( 'rs_f7' ); $_SERVER['REMOTE_ADDR'] = '203.0.113.84';
	$early = $order( $f7, 150, 'bacs', function ( $o ) { $o->set_date_created( time() - HOUR_IN_SECONDS ); } );
	$new_phone(); delicat_ref_attach( $f7, $inv, 'code' );
	tap( $early, 'completed' );
	$row7 = delicat_ref_row_for( $f7 );
	$check( 'first order: an order placed before the invitation does not qualify it', $row7 && 'signed_up' === $row7->status && ! (int) $row7->order_id, wp_json_encode( $row7 ) );
	$wpdb->update( delicat_ref_table( 'referrals' ), array( 'status' => 'rejected', 'reason' => 'refunded' ), array( 'id' => (int) $row7->id ) );
	$res = delicat_ref_apply( (int) $row7->id, $inv, $f7, 'confirmed', 'qualified' );
	$check( 'judgement: a row refunded meanwhile is never turned back into a point', 'rejected' === $res && 'rejected' === delicat_ref_row_for( $f7 )->status, $res );

	/* Full path with a blocked inviter, claims, cap. */
	$_SERVER['REMOTE_ADDR'] = '203.0.113.82';
	$f3 = $user( 'rs_f3' );
	$new_phone(); $st = delicat_ref_attach( $f3, $inv, 'link' );
	$check( 'attach: a clean friend is signed up', 'signed_up' === $st, wp_json_encode( $st ) );
	tap( $order( $f3, 200 ), 'completed' );
	delicat_ref_confirm_due( $inv );
	$check( 'point: confirmed after the (zero) holding period', 1 === delicat_ref_points( $inv ) );
	$check( 'claim: a milestone not reached is refused', is_wp_error( delicat_ref_claim( $inv, 2, 'htg' ) ) );
	$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . delicat_ref_table( 'claims' ) . " (user_id,milestone,choice,amount,status,created_at) VALUES (%d,1,'htg',140,'pending',%s)", $inv, current_time( 'mysql', 1 ) ) );
	$dup = delicat_ref_claim( $inv, 1, 'diamonds' );
	$check( 'claim: a milestone already reserved (concurrent request) is refused, nothing delivered', is_wp_error( $dup ) && 'claimed' === $dup->get_error_code() && 0 === count( wc_get_orders( array( 'customer_id' => $inv, 'limit' => 5 ) ) ), is_wp_error( $dup ) ? $dup->get_error_code() : 'delivered' );
	$wpdb->delete( delicat_ref_table( 'claims' ), array( 'user_id' => $inv ) );
	// A wallet error after the credit may have been written: the milestone stays reserved (never paid twice).
	$boom = function ( $q ) { if ( false !== stripos( $q, 'INSERT INTO' ) && false !== stripos( $q, 'woo_wallet_transactions' ) ) throw new RuntimeException( 'db down' ); return $q; };
	add_filter( 'query', $boom );
	$err = delicat_ref_claim( $inv, 1, 'htg' );
	remove_filter( 'query', $boom );
	$left = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . delicat_ref_table( 'claims' ) . ' WHERE user_id=%d AND milestone=1', $inv ) );
	$check( 'claim: an error during delivery keeps the milestone reserved for the team', is_wp_error( $err ) && 'issue_check' === $err->get_error_code() && 'pending' === $left, ( is_wp_error( $err ) ? $err->get_error_code() : 'ok' ) . ' / ' . var_export( $left, true ) );
	$again = delicat_ref_claim( $inv, 1, 'diamonds' );
	$check( 'claim: a reserved milestone cannot be claimed again', is_wp_error( $again ) && 'claimed' === $again->get_error_code() );
	$wpdb->delete( delicat_ref_table( 'claims' ), array( 'user_id' => $inv ) );
	delicat_ref_block( $inv, 'test', false );
	$blocked = delicat_ref_claim( $inv, 1, 'htg' );
	$check( 'claim: a blocked customer cannot claim', is_wp_error( $blocked ) && 'blocked' === $blocked->get_error_code() );
	$check( 'state: a blocked customer gets no code or link', '' === delicat_ref_state( $inv )['code'] && '' === delicat_ref_state( $inv )['link'] );
	$f4 = $user( 'rs_f4' );
	wp_set_current_user( $f4 ); $_SERVER['REMOTE_ADDR'] = '203.0.113.83';
	$r = delicat_ref_enter_code( $f4, $code );
	$check( 'code entry: a blocked inviter\'s code is refused', is_wp_error( $r ) && 'invalid' === $r->get_error_code(), wp_json_encode( $r ) );
	delicat_ref_unblock( $inv );
	$check( 'unblock: the inviter is back', ! delicat_ref_blocked( $inv ) );
	$staff = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	if ( $staff ) { delicat_ref_block( (int) $staff[0], 'test', true ); $check( 'staff accounts are never blocked automatically', ! delicat_ref_blocked( (int) $staff[0] ) ); }
	for ( $i = 0; $i < 120; $i++ ) $wpdb->insert( delicat_ref_table( 'referrals' ), array( 'referrer_id' => $inv, 'referred_id' => 950000 + $i, 'source' => 'link', 'status' => 'confirmed', 'created_at' => current_time( 'mysql', 1 ) ) );
	$check( 'points stop at 100', 100 === delicat_ref_points( $inv ) );

	/* Many new friends in a day go to review. */
	$burst = array(); $inv2 = $user( 'rs_inv2' );
	for ( $i = 0; $i < 6; $i++ ) { $_SERVER['REMOTE_ADDR'] = '198.18.' . ( 10 + $i ) . '.1'; $new_phone(); $b = $user( 'rs_b' . $i ); $burst[] = delicat_ref_attach( $b, $inv2, 'link' ); }
	$check( 'velocity: the 6th new friend in 24 h goes to review', 'signed_up' === $burst[4] && 'review' === $burst[5] && false !== strpos( (string) delicat_ref_row_for( $b )->reason, 'velocity' ), wp_json_encode( $burst ) );

	/* Rate limits. */
	wp_set_current_user( 0 ); $_SERVER['REMOTE_ADDR'] = '203.0.113.90';
	$last = null;
	for ( $i = 0; $i < 31; $i++ ) { $_POST = array( 'action' => 'delicat_ref_visit', 'code' => $code ); $last = $ajax( 'delicat_ref_visit' ); }
	$check( 'visit: 31st call in a minute from one address is refused', is_array( $last ) && 'rate_limited' === ( $last['code'] ?? '' ), wp_json_encode( $last ) );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.91';
	$_POST = array( 'action' => 'delicat_ref_visit', 'code' => $code ); $v = $ajax( 'delicat_ref_visit' );
	$check( 'visit: the inviter\'s name is masked, no markup, no id or e-mail', ! empty( $v['ok'] ) && false === strpos( (string) $v['inviter'], '<' ) && ! preg_match( '/rs_inv|example\.test|' . $inv . '/', wp_json_encode( $v ) ), wp_json_encode( $v ) );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.92'; $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
	$_POST = array( 'action' => 'delicat_ref_visit', 'code' => $code ); $x = $ajax( 'delicat_ref_visit' );
	$check( 'visit: refused when posted from another site', 'origin' === ( $x['code'] ?? '' ), wp_json_encode( $x ) );
	unset( $_SERVER['HTTP_ORIGIN'] );
	$other = $user( 'rs_other', array( 'display_name' => 'Zoe Other' ) ); $ocode = delicat_ref_code( $other );
	$_COOKIE['dlc_ref'] = delicat_ref_cookie_value( $code );
	$_POST = array( 'action' => 'delicat_ref_visit', 'code' => $ocode ); $y = $ajax( 'delicat_ref_visit' );
	$check( 'visit: the first invitation on a phone is kept', ! empty( $y['ok'] ) && false === strpos( (string) $y['inviter'], 'Zoe' ), wp_json_encode( $y ) );
	unset( $_COOKIE['dlc_ref'] );
	$f5 = $user( 'rs_f5' ); wp_set_current_user( $f5 );
	for ( $i = 0; $i < 6; $i++ ) $r = delicat_ref_enter_code( $f5, 'DLCZZZZZZ' );
	$check( 'code entry: the 6th try in an hour is refused', is_wp_error( $r ) && 'rate' === $r->get_error_code(), is_wp_error( $r ) ? $r->get_error_code() : '' );

	/* REST and transport. */
	wp_set_current_user( 0 );
	$check( 'REST: /referral needs an account', 401 === rest_do_request( new WP_REST_Request( 'GET', '/delicat/v1/referral' ) )->get_status() );
	$req = new WP_REST_Request( 'POST', '/delicat/v1/referral/claim' ); $req->set_body_params( array( 'milestone' => 1, 'choice' => 'htg' ) );
	$check( 'REST: /referral/claim needs an account', 401 === rest_do_request( $req )->get_status() );
	wp_set_current_user( $inv );
	$req = new WP_REST_Request( 'POST', '/delicat/v1/referral/claim' ); $req->set_body_params( array( 'milestone' => 1, 'choice' => 'cash' ) );
	$check( 'REST: an unknown reward choice is rejected', 400 === rest_do_request( $req )->get_status() );
	$_POST = array( 'action' => 'delicat_rewards_transport', 'nonce' => wp_create_nonce( 'wp_rest' ), 'path' => '/referral/claim', 'method' => 'POST', 'params' => wp_json_encode( array( 'milestone' => 1, 'choice' => 'htg', 'user_id' => $f1 ) ) );
	$t = $ajax( 'delicat_rewards_transport' );
	$check( 'transport: unexpected parameters (user_id) are refused', 'invalid_params' === ( $t['code'] ?? '' ), wp_json_encode( $t ) );

	/* Admin handlers and admin page. */
	wp_set_current_user( $f1 );
	$before = get_option( 'delicat_ref_config' );
	$_POST = array( 'action' => 'delicat_ref_save', 'enabled' => '1', 'max_points' => '100', '_wpnonce' => wp_create_nonce( 'delicat_ref_save' ) ); $_REQUEST = $_POST;
	$died = false; try { do_action( 'admin_post_delicat_ref_save' ); } catch ( DRF_Test_Response $e ) { $died = true; }
	$check( 'admin: a customer cannot change the settings', $died && get_option( 'delicat_ref_config' ) === $before );
	if ( $staff ) {
		wp_set_current_user( (int) $staff[0] );
		$_POST = array( 'action' => 'delicat_ref_save', 'enabled' => '1' ); $_REQUEST = $_POST;
		$died = false; try { do_action( 'admin_post_delicat_ref_save' ); } catch ( DRF_Test_Response $e ) { $died = true; }
		$check( 'admin: settings need the form nonce (CSRF)', $died && get_option( 'delicat_ref_config' ) === $before );
		$wpdb->update( delicat_ref_table( 'referrals' ), array( 'status' => 'review', 'reason' => 'same_network' ), array( 'referred_id' => $f3 ) );
		// Display names are cleaned when an account is saved; a hostile one is written directly, as an import could.
		$wpdb->update( $wpdb->users, array( 'display_name' => '<img src=x onerror=alert(1)> Inviter' ), array( 'ID' => $inv ) ); clean_user_cache( $inv );
		remove_all_filters( 'delicat_ref_vpn_verdict' ); // staging's VPN switch would show « fournisseur personnalisé »
		ob_start(); delicat_tab_referral(); $html = (string) ob_get_clean();
		$check( 'admin page: review queue lists the invitation', false !== strpos( $html, 'Même réseau que le parrain' ) );
		$check( 'admin page: names are escaped', false === strpos( $html, '<img src=x' ) && false !== strpos( $html, '&lt;img' ), substr( $html, 0, 200 ) );
		$check( 'admin page: the VPN key is never shown', false === strpos( $html, 'SECRET-123456' ) && false !== strpos( $html, 'proxycheck.io' ) );
	}

	/* VPN lookup: the key goes to the provider only; one lookup per address per day; a failure does not block. */
	remove_all_filters( 'delicat_ref_vpn_verdict' );
	$calls = array();
	$v1 = delicat_ref_is_vpn( '8.8.4.4' ); $v2 = delicat_ref_is_vpn( '8.8.4.4' );
	$check( 'VPN: detected through the provider, cached (one call)', $v1 && $v2 && 1 === count( $calls ), wp_json_encode( $calls ) );
	$check( 'VPN: the key goes to the provider, over HTTPS, with a short timeout (3 s)', isset( $calls[0] ) && 0 === strpos( $calls[0][0], 'https://proxycheck.io/' ) && false !== strpos( $calls[0][0], 'SECRET-123456' ) && 3 === (int) $calls[0][1] );
	$check( 'VPN: a private address is never sent out', false === delicat_ref_is_vpn( '10.0.0.5' ) && 1 === count( $calls ) );
	$check( 'VPN: provider down → not blocked (fails open)', false === delicat_ref_is_vpn( '8.8.8.8' ) );
	$state = wp_json_encode( delicat_ref_state( $inv ) );
	$check( 'state: no key, no detection reason, no account id of friends', false === strpos( $state, 'SECRET' ) && ! preg_match( '/same_network|phone_shared|email_alias|"referred_id"|"user_id"/', $state ), substr( $state, 0, 300 ) );

	/* The invitation links' page comes from the dashboard or a published page, never from a page view. */
	delete_option( 'delicat_bonus_page_url' ); // left on staging by a pre-release build
	$draft = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'rs draft', 'post_content' => '[delicat_bonus_free_fire]' ) );
	$GLOBALS['post'] = get_post( $draft ); setup_postdata( $GLOBALS['post'] );
	do_shortcode( '[delicat_bonus_free_fire]' ); wp_reset_postdata();
	$link = delicat_ref_link( $code );
	$check( 'links: displaying the shortcode on a draft changes nothing', false === strpos( $link, 'page_id=' . $draft ) && false === strpos( $link, 'rs-draft' ) && false === get_option( 'delicat_bonus_page_url' ), $link );
	$check( 'links: they open the published Bonus Free Fire page', 0 === strpos( $link, (string) get_permalink( get_page_by_path( 'bonus-free-fire' ) ) ), $link );
	wp_update_post( array( 'ID' => $draft, 'post_status' => 'publish', 'post_password' => 'x' ) );
	$check( 'links: a draft or a password-protected page is never used as the page', ! delicat_ref_public_page( $draft ) && delicat_ref_public_page( get_page_by_path( 'bonus-free-fire' )->ID ) );
	wp_delete_post( $draft, true );

	/* A blocked account's hashed evidence survives its deletion. */
	$gone = $user( 'rs_gone' ); delicat_ref_signal( $gone, 'device', hash( 'sha256', 'rs-gone-device' ) ); delicat_ref_block( $gone, 'same_device', false );
	wp_delete_user( $gone ); $made = array_diff( $made, array( $gone ) );
	$check( 'deletion: a blocked account keeps its hashed signals (no clean restart)', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . delicat_ref_table( 'signals' ) . ' WHERE user_id=%d', $gone ) ) );
	$wpdb->delete( delicat_ref_table( 'signals' ), array( 'user_id' => $gone ) );
	$check( 'address: one helper for limits and logs', function_exists( 'delicat_client_ip' ) && '' !== delicat_client_ip() );

	/* Account deletion takes the customer's referral data with it. */
	wp_delete_user( $f3 ); $made = array_diff( $made, array( $f3 ) );
	$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . delicat_ref_table( 'signals' ) . ' WHERE user_id=%d', $f3 ) ) + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . delicat_ref_table( 'referrals' ) . ' WHERE referred_id=%d', $f3 ) );
	$check( 'deletion: signals and invitation rows removed', 0 === $left );
} finally {
	foreach ( $orders as $id ) { $o = wc_get_order( $id ); if ( $o ) $o->delete( true ); }
	foreach ( $made as $id ) wp_delete_user( $id );
	$wpdb->query( 'DELETE FROM ' . delicat_ref_table( 'referrals' ) . ' WHERE referred_id>=950000' );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%delicat\\_r%' OR option_name LIKE '\\_transient%delicat\\_vpn\\_%'" );
}
echo "\n$pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );

/** Sets an order's status and returns it, reloaded. */
function tap( $o, $status ) { $o->update_status( $status ); return wc_get_order( $o->get_id() ); }
