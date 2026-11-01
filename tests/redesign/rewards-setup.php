<?php
// php rewards-setup.php <site-dir> — STAGING ONLY. Test data for the Bonus Free Fire page (Delicat Rewards 3.3):
// PIN products with stock, short tier goals, a week of paid + completed orders for the ranking, a claimable
// « Mini » bonus for client1, pages for the new and the old shortcodes, and a must-use fixture that writes
// test PIN codes onto reward orders (no key manager on staging). Safe to run again: it resets its own data.
$site = $argv[1] ?? '';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php rewards-setup.php <site-dir>\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$mu = WP_CONTENT_DIR . '/mu-plugins';
if ( ! is_dir( $mu ) ) mkdir( $mu, 0755, true );
file_put_contents( $mu . '/delicat-rewards-staging-pins.php', <<<'PHP'
<?php
/* STAGING ONLY (tests/redesign/rewards-setup.php): reward orders get test PIN codes and are completed. */
add_action( 'delicat_reward_issued', function ( $user_id, $tier, $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) return;
	foreach ( $order->get_items() as $item ) {
		$codes = array();
		for ( $i = 0; $i < max( 1, (int) $item->get_quantity() ); $i++ ) $codes[] = 'TEST-' . strtoupper( wp_generate_password( 4, false ) ) . '-' . strtoupper( wp_generate_password( 4, false ) );
		$item->update_meta_data( '_delicat_keys', implode( "\n", $codes ) );
		$item->save();
	}
	$order->update_status( 'completed', 'Staging: test PINs.' );
}, 20, 3 );
PHP
);

if ( ! is_plugin_active( 'delicat-rewards/delicat-rewards.php' ) ) {
	$r = activate_plugin( 'delicat-rewards/delicat-rewards.php' );
	if ( is_wp_error( $r ) ) { fwrite( STDERR, $r->get_error_message() . "\n" ); exit( 1 ); }
}

/* PIN products (the reward stock and the packs customers buy). */
$pins = array();
foreach ( array( 110 => 150, 341 => 450, 572 => 750 ) as $d => $price ) {
	$slug = 'pin-free-' . $d . '-diamants';
	$p    = get_page_by_path( $slug, OBJECT, 'product' );
	$prod = $p ? wc_get_product( $p->ID ) : new WC_Product_Simple();
	$prod->set_name( 'PIN Free Fire ' . $d . ' Diamants' );
	$prod->set_slug( $slug );
	$prod->set_status( 'publish' );
	$prod->set_regular_price( (string) $price );
	$prod->set_manage_stock( true );
	$prod->set_stock_quantity( 50 );
	$prod->set_virtual( true );
	$pins[ $d ] = $prod->save();
}

$cfg = (array) get_option( 'delicat_config', array() );
$cfg['pins']    = array( 110 => $pins[110], 341 => $pins[341], 572 => $pins[572] );
$cfg['tiers']   = array(
	'mini'   => array( 'label' => 'Mini', 'title' => 'Free Fire', 'sub' => '110 Diamants', 'ids' => array( $pins[110] ), 'pin' => 110, 'goal' => 2, 'reward' => 110, 'max_claims' => 0, 'enabled' => 1, 'order' => 1 ),
	'smart'  => array( 'label' => 'Smart', 'title' => 'Free Fire', 'sub' => '341 Diamants', 'ids' => array( $pins[341] ), 'pin' => 341, 'goal' => 3, 'reward' => 341, 'max_claims' => 0, 'enabled' => 1, 'order' => 2 ),
	'silver' => array( 'label' => 'Silver', 'title' => 'Free Fire', 'sub' => '572 Diamants', 'ids' => array( $pins[572] ), 'pin' => 572, 'goal' => 4, 'reward' => 572, 'max_claims' => 0, 'enabled' => 1, 'order' => 3 ),
);
$cfg['prize']   = 572;
$cfg['prize2']  = 341;
$cfg['prize3']  = 110;
$cfg['dry_run'] = 0;
$cfg['board_public'] = 0;
update_option( 'delicat_config', $cfg );
update_option( 'delicat_cfg_gen', (int) get_option( 'delicat_cfg_gen', 1 ) + 1 );
update_option( 'delicat_rewards_enabled', '1' );
update_option( 'delicat_meilleur_enabled', '1' );

/* Ranking customers (rk1…rk5) plus client1–3, with orders this week. Their earlier orders are removed first. */
$customers = array( 'client1' => array( 110, 110, 341 ), 'client2' => array( 572, 572, 341 ), 'client3' => array( 110 ),
	'rk1' => array( 572, 572, 572, 341, 341 ), 'rk2' => array( 110, 110, 110, 110 ), 'rk3' => array( 110, 110, 110 ), 'rk4' => array( 110, 110 ), 'rk5' => array( 110 ) );
$names = array( 'client1' => array( 'Jean', 'Baptiste' ), 'client2' => array( 'Laroche', 'Noël' ), 'client3' => array( 'Leo', 'Bernard' ),
	'rk1' => array( 'Frantz', 'Joseph' ), 'rk2' => array( 'Marie', 'Pierre' ), 'rk3' => array( 'Kervens', 'Louis' ), 'rk4' => array( 'Sarah', 'Jean' ), 'rk5' => array( 'Wilson', 'Paul' ) );
foreach ( $customers as $login => $packs ) {
	$u = get_user_by( 'login', $login );
	if ( ! $u ) {
		$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => 'ClientPass!2026', 'role' => 'customer' ) );
		$u  = get_user_by( 'id', $id );
		update_user_meta( $u->ID, 'dip_email_verified', 'yes' );
		update_user_meta( $u->ID, '_dip_verified_email_fingerprint', hash( 'sha256', strtolower( $u->user_email ) ) );
	}
	wp_update_user( array( 'ID' => $u->ID, 'first_name' => $names[ $login ][0], 'last_name' => $names[ $login ][1], 'display_name' => $names[ $login ][0] . ' ' . $names[ $login ][1] ) );
	foreach ( wc_get_orders( array( 'customer_id' => $u->ID, 'limit' => -1 ) ) as $old ) $old->delete( true );
	// The month's signed ledger and its « migrated » flag go together: removing only the ledger reads as tampering (frozen).
	$month = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	delete_user_meta( $u->ID, delicat_ledger_key( $month ) );
	delete_user_meta( $u->ID, delicat_ledger_migration_key( $month ) );
	foreach ( array_keys( get_user_meta( $u->ID ) ) as $k ) if ( 0 === strpos( $k, '_delicat_claimed_' . $month . '_' ) ) delete_user_meta( $u->ID, $k );
	foreach ( $packs as $d ) {
		$order = wc_create_order( array( 'customer_id' => $u->ID ) );
		$order->add_product( wc_get_product( $pins[ $d ] ), 1 );
		$order->set_billing_email( $u->user_email );
		$order->set_payment_method( 'bacs' );
		$order->calculate_totals();
		$order->set_date_paid( time() - 3600 );
		$order->save();
		$order->update_status( 'completed', 'Staging order.' );
	}
	if ( function_exists( 'delicat_flush_user' ) ) delicat_flush_user( $u->ID );
}
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%delicat\\_leaderboard%' OR option_name LIKE '\\_transient%delicat\\_bb\\_%' OR option_name LIKE 'delicat\\_cs\\_%'" );

/* Pages: the new shortcode, and an old one (it now opens the same page on its tab). */
foreach ( array( 'bonus-free-fire' => array( 'Bonus Free Fire', '[delicat_bonus_free_fire]' ), 'meilleur-client' => array( 'Meilleur client', '[delicat_meilleur_client]' ) ) as $slug => $p ) {
	$page = get_page_by_path( $slug );
	$data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $p[0], 'post_content' => $p[1] );
	if ( $page ) { $data['ID'] = $page->ID; wp_update_post( $data ); } else wp_insert_post( $data );
}
echo wp_json_encode( array( 'pins' => $pins, 'customers' => array_keys( $customers ), 'page' => get_permalink( get_page_by_path( 'bonus-free-fire' ) ) ) ) . "\n";
