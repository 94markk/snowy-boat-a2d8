<?php
// php reseller-security.php <site-dir> — STAGING ONLY. Delicat Resellers 1.0: application rules, qualifying deposit
// (recharges only, after the approval, refunds excluded), automatic refusal on a wallet-to-wallet transfer, deadline,
// activation, prices (own, %, excluded, top-up, sale, calculator), order stamp, Rewards exclusion, monthly rule
// (keep / move down / leave), reminder, admin rights and CSRF, escaping, Builder cache safety.
// Run reseller-setup.php setup first.
$site = $argv[1] ?? '';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php reseller-security.php <site-dir>\n" ); exit( 2 ); }
define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'localhost'; $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
if ( false === strpos( home_url(), 'localhost' ) || ! function_exists( 'dlr_apply' ) ) { fwrite( STDERR, "staging with Delicat Resellers only\n" ); exit( 2 ); }
require_once DLR_DIR . 'includes/admin.php'; // loaded by WordPress in wp-admin only
global $wpdb;
final class DLR_Test_Exit extends Exception {}
add_filter( 'wp_die_ajax_handler', static function () { return static function () { throw new DLR_Test_Exit( 'die' ); }; } );
add_filter( 'wp_redirect', static function () { throw new DLR_Test_Exit( 'redirect' ); }, 1 );
$pass = 0; $fail = 0;
$check = static function ( $label, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  (' . $detail . ')' ) . "\n";
	$ok ? $pass++ : $fail++;
};
$u = function ( $login ) { return get_user_by( 'login', $login ); };
$rs1 = $u( 'rs1' ); $rs2 = $u( 'rs2' ); $rs3 = $u( 'rs3' ); $rs4 = $u( 'rs4' );
if ( ! $rs1 || ! $rs4 ) { fwrite( STDERR, "run reseller-setup.php setup first\n" ); exit( 2 ); }
$rt = $wpdb->prefix . 'delicat_recharges';
$deposit = function ( $uid, $amount, $ago_days = 0, $status = 'approuve' ) use ( $wpdb, $rt ) {
	$wpdb->insert( $rt, array( 'user_id' => $uid, 'user_email' => get_userdata( $uid )->user_email, 'tab' => 'moncash', 'methode' => 'moncash', 'montant' => $amount, 'trans_code' => 'T' . wp_generate_password( 9, false ), 'statut' => $status, 'wallet_credited' => 'approuve' === $status ? 1 : 0 ) );
	$id = (int) $wpdb->insert_id;
	if ( $ago_days ) $wpdb->query( $wpdb->prepare( "UPDATE $rt SET created_at = created_at - INTERVAL %d DAY WHERE id=%d", $ago_days, $id ) ); // phpcs:ignore
	if ( 'approuve' === $status ) do_action( 'delicat_recharge_approved', $id );
	return $id;
};
$form = array( 'tier' => 'gold', 'business' => 'Boutique Test', 'phone' => '+509 3700 0001', 'city' => 'Cap-Haïtien', 'channel' => 'Je vends des diamants à mon école et sur WhatsApp.', 'volume' => '50_100', 'terms' => '1' );
$orders = array();

try {
	/* Application rules. */
	$bad = dlr_apply( $rs1->ID, array_merge( $form, array( 'phone' => 'abc', 'terms' => '' ) ) );
	$check( 'apply: invalid phone and unchecked terms are refused', is_wp_error( $bad ) && false !== strpos( $bad->get_error_message(), 'téléphone' ) && false !== strpos( $bad->get_error_message(), 'conditions' ) );
	$id = dlr_apply( $rs1->ID, array_merge( $form, array( 'business' => '<img src=x onerror=alert(1)>Shop' ) ) );
	$check( 'apply: a valid application is recorded as pending', is_int( $id ) && 'pending' === dlr_app( $id )->status, is_wp_error( $id ) ? $id->get_error_message() : '' );
	$check( 'apply: stored text is cleaned (no markup)', false === strpos( (string) dlr_app( $id )->business, '<' ) );
	$dup = dlr_apply( $rs1->ID, $form );
	$check( 'apply: a second application while one is open is refused', is_wp_error( $dup ) && 'open' === $dup->get_error_code() );

	/* Step one, then a qualifying deposit made BEFORE the approval does not count. */
	$deposit( $rs1->ID, 60000, 2 ); // two days before the approval
	$check( 'approve: the team approves (deposit step)', true === dlr_approve( $id ) && 'deposit' === dlr_app( $id )->status );
	dlr_check_app( dlr_app( $id ) );
	$check( 'deposit: money recharged before the approval does not qualify', 'deposit' === dlr_app( $id )->status && '' === dlr_user_tier( $rs1->ID, true ) );
	$deposit( $rs1->ID, 30000, 0, 'rejete' ); // refused / refunded recharge
	$deposit( $rs1->ID, 30000 );
	$check( 'deposit: a refused recharge does not count; 30 000 of 50 000 is not enough', 'deposit' === dlr_app( $id )->status && 30000.0 === dlr_deposits( $rs1->ID, dlr_ts( dlr_app( $id )->decided_at ) ) );
	$deposit( $rs1->ID, 20000 );
	$st = dlr_app( $id )->status;
	$check( 'deposit: reaching 50 000 through recharges activates Golden Reseller', 'active' === $st && 'gold' === dlr_user_tier( $rs1->ID, true ), $st );
	$roles = get_userdata( $rs1->ID )->roles;
	$check( 'activation: the level role is added beside « customer »', in_array( 'dlr_gold', $roles, true ) && in_array( 'customer', $roles, true ), implode( ',', $roles ) );
	$check( 'activation: this month is settled by the qualifying deposit', dlr_month_key() === get_user_meta( $rs1->ID, '_dlr_eval', true ) );
	$low = dlr_apply( $rs1->ID, array_merge( $form, array( 'tier' => 'silver' ) ) );
	$check( 'apply: a reseller cannot apply for the same or a lower level', is_wp_error( $low ) && 'tier' === $low->get_error_code() );

	/* Wallet-to-wallet transfer during the deposit step: refused automatically. */
	$id2 = dlr_apply( $rs2->ID, array_merge( $form, array( 'tier' => 'silver' ) ) );
	dlr_approve( $id2 );
	$wpdb->insert( $wpdb->prefix . 'delicat_transfers', array( 'ref_key' => wp_generate_password( 20, false ), 'sender_id' => $rs4->ID, 'recipient_id' => $rs2->ID, 'amount' => 25000, 'fee' => 0, 'status' => 'completed', 'created_at' => current_time( 'mysql' ) ) );
	do_action( 'delicat_transfer_completed', (int) $wpdb->insert_id, $rs4->ID, $rs2->ID, 25000, 0 );
	$a2 = dlr_app( $id2 );
	$check( 'transfer: money from another wallet declines the application at once', 'declined' === $a2->status && 'transfer' === $a2->reason, $a2->status );
	$deposit( $rs2->ID, 30000 );
	$check( 'transfer: a later recharge does not revive a declined application', '' === dlr_user_tier( $rs2->ID, true ) );
	// The same, found by the daily check when the hook was missed.
	$id2b = dlr_apply( $rs2->ID, array_merge( $form, array( 'tier' => 'silver' ) ) );
	dlr_approve( $id2b );
	$wpdb->insert( $wpdb->prefix . 'delicat_transfers', array( 'ref_key' => wp_generate_password( 20, false ), 'sender_id' => $rs4->ID, 'recipient_id' => $rs2->ID, 'amount' => 500, 'fee' => 0, 'status' => 'completed', 'created_at' => current_time( 'mysql' ) ) );
	do_action( 'dlr_daily' );
	$check( 'transfer: the daily check also declines (hook missed)', 'declined' === dlr_app( $id2b )->status );

	/* Deadline. */
	$id3 = dlr_apply( $rs3->ID, array_merge( $form, array( 'tier' => 'silver' ) ) );
	dlr_approve( $id3 );
	$wpdb->update( dlr_table( 'apps' ), array( 'deadline' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $id3 ) );
	dlr_check_app( dlr_app( $id3 ) );
	$check( 'deadline: no full deposit in time → expired', 'expired' === dlr_app( $id3 )->status );

	/* Prices, as the Golden Reseller rs1. */
	wp_set_current_user( $rs1->ID );
	$p110 = wc_get_product( get_page_by_path( 'pin-free-110-diamants', OBJECT, 'product' )->ID ); // 150, Gold 120
	$p341 = wc_get_product( get_page_by_path( 'pin-free-341-diamants', OBJECT, 'product' )->ID ); // 450, Gold −10 %
	$p572 = wc_get_product( get_page_by_path( 'pin-free-572-diamants', OBJECT, 'product' )->ID ); // excluded
	$check( 'price: the product\'s own Gold price applies', 120.0 === (float) $p110->get_price(), $p110->get_price() );
	$check( 'price: no own price → the level\'s % (450 − 10 % = 405)', 405.0 === (float) $p341->get_price(), $p341->get_price() );
	$check( 'price: a product marked « pas de prix revendeur » keeps its price', (float) $p572->get_price( 'edit' ) === (float) $p572->get_price() );
	$check( 'price: shown with the regular price struck through and the level\'s name', false !== strpos( $p110->get_price_html(), '<del' ) && false !== strpos( $p110->get_price_html(), 'Prix Golden Reseller' ), wp_strip_all_tags( $p110->get_price_html() ) );
	$topup = dlr_topup_id() ? wc_get_product( dlr_topup_id() ) : null;
	if ( $topup ) $check( 'price: never on the wallet top-up product', (string) $topup->get_price( 'edit' ) === (string) $topup->get_price() );
	$p341->set_sale_price( '380' ); $p341->save(); $p341 = wc_get_product( $p341->get_id() );
	$check( 'price: a normal sale lower than the reseller price wins (380 < 405)', 380.0 === (float) $p341->get_price(), $p341->get_price() );
	$p341->set_sale_price( '' ); $p341->save(); $p341 = wc_get_product( $p341->get_id() );
	$calc = wc_get_product( $p110->get_id() ); $calc->set_price( 200 ); // catalogue 150 + 50 of options (Builder calculator)
	$check( 'price: calculator options keep their cost (120 + 50)', 170.0 === (float) $calc->get_price(), $calc->get_price() );
	$check( 'cache: Builder may reuse fragments for this reseller (role present)', true === apply_filters( 'delicat_builder_v9_server_fragment_cache_signed_in', true ) );
	$usr = new WP_User( $rs1->ID ); $usr->remove_role( 'dlr_gold' ); clean_user_cache( $rs1->ID ); wp_set_current_user( 0 ); wp_set_current_user( $rs1->ID ); // same id: WordPress would keep the cached user
	$check( 'cache: without the level role, Builder\'s shared fragments are not used', false === apply_filters( 'delicat_builder_v9_server_fragment_cache_signed_in', true ) );
	$usr->add_role( 'dlr_gold' ); clean_user_cache( $rs1->ID ); wp_set_current_user( 0 ); wp_set_current_user( $rs1->ID );
	wp_set_current_user( $rs4->ID );
	$check( 'price: a regular customer pays the normal price', 150.0 === (float) wc_get_product( $p110->get_id() )->get_price() );

	/* Orders: stamped, and not retail for Delicat Rewards. */
	$o = wc_create_order( array( 'customer_id' => $rs1->ID ) ); $o->add_product( $p110, 1 ); $o->calculate_totals(); $o->save(); $orders[] = $o->get_id();
	$o = wc_get_order( $o->get_id() );
	$check( 'order: a reseller\'s order carries the level', 'gold' === $o->get_meta( '_dlr_tier' ) );
	if ( function_exists( 'delicat_order_is_retail' ) ) {
		$check( 'rewards: a reseller order is not retail (no bonus, no ranking)', false === delicat_order_is_retail( $o ) );
		$o4 = wc_create_order( array( 'customer_id' => $rs4->ID ) ); $o4->add_product( $p110, 1 ); $o4->calculate_totals(); $o4->save(); $orders[] = $o4->get_id();
		$check( 'rewards: a regular customer\'s order stays retail', true === delicat_order_is_retail( wc_get_order( $o4->get_id() ) ) && ! wc_get_order( $o4->get_id() )->get_meta( '_dlr_tier' ) );
	}
	if ( function_exists( 'delicat_ref_order_counts' ) ) {
		$o->set_status( 'completed' ); $o->set_total( 200 ); $o->set_date_paid( time() ); $o->save();
		$check( 'rewards 3.4.1: a reseller order is not a referral first order', false === delicat_ref_order_counts( wc_get_order( $o->get_id() ) ) );
	}

	/* The monthly rule. */
	dlr_set_tier( $rs3->ID, 'premium', 'admin_set' );
	$last = dlr_month_shift( dlr_month_key(), -1 );
	list( $a ) = dlr_month_bounds( $last );
	$days_ago = (int) floor( ( time() - $a ) / DAY_IN_SECONDS ) - 1; // inside last month
	$deposit( $rs3->ID, 60000, $days_ago );
	update_user_meta( $rs3->ID, '_dlr_eval', dlr_month_shift( dlr_month_key(), -2 ) );
	dlr_evaluate_user( $rs3->ID );
	$check( 'month: Premium with 60 000 last month moves down to Gold', 'gold' === dlr_user_tier( $rs3->ID, true ) && $last === get_user_meta( $rs3->ID, '_dlr_eval', true ) );
	$roles = get_userdata( $rs3->ID )->roles;
	$check( 'month: the role follows the level', in_array( 'dlr_gold', $roles, true ) && ! in_array( 'dlr_premium', $roles, true ), implode( ',', $roles ) );
	dlr_evaluate_user( $rs3->ID );
	$check( 'month: a month is never settled twice', 'gold' === dlr_user_tier( $rs3->ID, true ) );
	update_user_meta( $rs3->ID, '_dlr_eval', dlr_month_shift( dlr_month_key(), -3 ) ); // two unsettled months: 0 then 60 000
	dlr_evaluate_user( $rs3->ID );
	$check( 'month: a month under the lowest minimum ends the programme', '' === dlr_user_tier( $rs3->ID, true ) && ! array_intersect( array( 'dlr_gold', 'dlr_silver', 'dlr_premium' ), get_userdata( $rs3->ID )->roles ) );

	/* Reminder once a month, only under the minimum. */
	dlr_set_tier( $rs3->ID, 'silver', 'admin_set' ); update_user_meta( $rs3->ID, '_dlr_eval', $last ); delete_user_meta( $rs3->ID, '_dlr_reminded' );
	$cfg = get_option( 'dlr_config' ); $cfg['reminder_day'] = 1; update_option( 'dlr_config', $cfg ); dlr_cfg( null, true );
	$sent = 0; $count = function ( $r ) use ( &$sent ) { if ( false !== strpos( (string) $r['subject'], 'Rappel' ) ) $sent++; return $r; };
	add_filter( 'wp_mail', $count );
	dlr_remind_user( $rs3->ID ); dlr_remind_user( $rs3->ID );
	remove_filter( 'wp_mail', $count );
	$check( 'reminder: sent once a month to a reseller under the minimum', 1 === $sent, (string) $sent );
	$cfg['reminder_day'] = 22; update_option( 'dlr_config', $cfg ); dlr_cfg( null, true );

	/* Programme closed: no prices, no application. */
	$cfg['enabled'] = 0; update_option( 'dlr_config', $cfg ); dlr_cfg( null, true );
	wp_set_current_user( $rs1->ID );
	$check( 'closed: reseller prices stop', 150.0 === (float) wc_get_product( $p110->get_id() )->get_price() );
	$check( 'closed: applications are refused', is_wp_error( dlr_apply( $rs4->ID, $form ) ) );
	$cfg['enabled'] = 1; update_option( 'dlr_config', $cfg ); dlr_cfg( null, true );

	/* Admin handlers: rights and CSRF. */
	$id4 = dlr_apply( $rs4->ID, array_merge( $form, array( 'tier' => 'silver' ) ) );
	wp_set_current_user( $rs4->ID );
	$_POST = $_REQUEST = array( 'action' => 'dlr_admin_decide', 'id' => $id4, 'do' => 'approve', '_wpnonce' => wp_create_nonce( 'dlr_admin_decide' ) );
	$stopped = false; try { do_action( 'admin_post_dlr_admin_decide' ); } catch ( DLR_Test_Exit $e ) { $stopped = 'die' === $e->getMessage(); }
	$check( 'admin: a customer cannot approve an application (even their own)', $stopped && 'pending' === dlr_app( $id4 )->status );
	$_POST = $_REQUEST = array( 'action' => 'dlr_admin_tier', 'user_id' => $rs4->ID, 'tier' => 'premium', '_wpnonce' => wp_create_nonce( 'dlr_admin_tier' ) );
	$stopped = false; try { do_action( 'admin_post_dlr_admin_tier' ); } catch ( DLR_Test_Exit $e ) { $stopped = 'die' === $e->getMessage(); }
	$check( 'admin: a customer cannot give themselves a level', $stopped && '' === dlr_user_tier( $rs4->ID, true ) );
	$staff = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	if ( $staff ) {
		wp_set_current_user( (int) $staff[0] );
		$_POST = $_REQUEST = array( 'action' => 'dlr_admin_decide', 'id' => $id4, 'do' => 'approve' );
		$stopped = false; try { do_action( 'admin_post_dlr_admin_decide' ); } catch ( DLR_Test_Exit $e ) { $stopped = 'die' === $e->getMessage(); }
		$check( 'admin: approving needs the form nonce (CSRF)', $stopped && 'pending' === dlr_app( $id4 )->status );
		ob_start(); $_GET['tab'] = 'demandes'; dlr_admin_page(); $html = (string) ob_get_clean();
		$check( 'admin page: lists the application with the applicant\'s facts', false !== strpos( $html, 'Boutique Test' ) && false !== strpos( $html, 'recharges 90 j' ) );
		$wpdb->update( dlr_table( 'apps' ), array( 'business' => '<script>alert(1)</script>Evil', 'channel' => '<img src=x onerror=alert(2)>' ), array( 'id' => $id4 ) );
		ob_start(); dlr_admin_page(); $html = (string) ob_get_clean();
		$check( 'admin page: application text is escaped', false === strpos( $html, '<script>alert(1)' ) && false === strpos( $html, '<img src=x' ) && false !== strpos( $html, '&lt;script&gt;' ) );
	}

	/* The customer's page: personal, not shareable by Builder's cache, escaped. */
	wp_set_current_user( $rs1->ID );
	$page = do_shortcode( '[delicat_revendeur]' );
	$check( 'page: the reseller sees their badge and this month\'s deposits', false !== strpos( $page, 'Golden Reseller' ) && false !== strpos( $page, 'Dépôts de' ) );
	$check( 'page: marked personal (data-user-id), so Builder never shares it', false !== strpos( $page, 'data-user-id="' . $rs1->ID . '"' ) );
	wp_set_current_user( 0 );
	$guest = do_shortcode( '[delicat_revendeur]' );
	$check( 'page: a visitor sees the levels and a sign-in card, no form', false !== strpos( $guest, 'Les niveaux' ) && false === strpos( $guest, 'name="business"' ) && false === strpos( $guest, 'data-user-id' ) );

	/* Deleting an account removes its applications and history. */
	$tmp = wp_insert_user( array( 'user_login' => 'rs_tmp_del', 'user_email' => 'rs_tmp_del@example.test', 'user_pass' => wp_generate_password( 16 ), 'role' => 'customer' ) );
	dlr_apply( $tmp, array_merge( $form, array( 'tier' => 'silver' ) ) );
	wp_delete_user( $tmp );
	$check( 'deletion: applications and history go with the account', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . dlr_table( 'apps' ) . ' WHERE user_id=%d', $tmp ) ) );
} finally {
	foreach ( $orders as $oid ) { $x = wc_get_order( $oid ); if ( $x ) $x->delete( true ); }
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dlr\\_lock\\_%'" );
}
echo "\n$pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
