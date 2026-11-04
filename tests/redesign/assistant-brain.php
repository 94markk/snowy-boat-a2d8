<?php
// php assistant-brain.php <site-dir> — STAGING ONLY. Delicat Assistant 7.1: order lookup in the chat (own orders
// only, the same answer for someone else's order as for a missing one, sign-in for visitors, a limit per hour, a
// receipt without private data) and the answers the 7.0 review found wrong (small talk swallowing questions,
// generic guides catching « PIN pas reçu », « moun » escalating, « oui » after an offer, names, wording).
// Runs the real AJAX handler (dsa2_send_message) as temporary customers and removes everything it created.
$site = $argv[1] ?? '';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php assistant-brain.php <site-dir>\n" ); exit( 2 ); }
define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php'; $_SERVER['SERVER_NAME'] = 'localhost'; $_SERVER['REMOTE_ADDR'] = '203.0.113.71';
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( false === strpos( home_url(), 'localhost' ) ) { fwrite( STDERR, "refusing: not a staging site\n" ); exit( 3 ); }
dsa2_load_full_engine();
if ( ! class_exists( 'DSA2_Brain' ) ) { fwrite( STDERR, "Delicat Assistant 7.1 is not active\n" ); exit( 1 ); }
global $wpdb;

final class DAB_Test_Response extends Exception {}
add_filter( 'wp_die_ajax_handler', static function () { return static function () { throw new DAB_Test_Response(); }; } );
add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' ); // test orders never touch the PIN stock

$pass = 0; $fail = 0;
$check = static function ( $label, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  (' . $detail . ')' ) . "\n";
	$ok ? $pass++ : $fail++;
};

/* Temporary customers and their orders, with private details that must never reach the chat. */
foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'dlab\\_%'" ) as $old ) wp_delete_user( (int) $old );
$users = array();
foreach ( array( 'a', 'b' ) as $k ) {
	$users[ $k ] = (int) wp_insert_user( array( 'user_login' => 'dlab_' . $k, 'user_email' => 'dlab_' . $k . '@example.test', 'user_pass' => wp_generate_password( 16 ), 'role' => 'customer', 'display_name' => 'Client ' . strtoupper( $k ) ) );
}
$product = wc_get_product( get_page_by_path( 'pin-free-341-diamants', OBJECT, 'product' )->ID );
$orders  = array();
$guests  = array();
$make = static function ( $uid, $status ) use ( $product, &$orders ) {
	$o = wc_create_order( array( 'customer_id' => $uid ) );
	$o->add_product( $product, 2 );
	foreach ( $o->get_items() as $item ) { $item->add_meta_data( 'Player ID', '987654321' ); $item->save(); }
	$o->set_billing_email( 'secret-mail@example.test' ); $o->set_billing_phone( '+50937001234' );
	$o->set_billing_address_1( '12 Rue Secrète' ); $o->set_payment_method_title( 'MonCash' );
	$o->calculate_totals(); $o->set_status( $status ); $o->save();
	$o->add_order_note( 'note interne secrète' );
	$orders[] = $o->get_id();
	return $o;
};
$order_a = $make( $users['a'], 'completed' );
$order_b = $make( $users['b'], 'processing' );
$num_a   = (string) $order_a->get_order_number();
$num_b   = (string) $order_b->get_order_number();
$guest_key = static function () use ( &$guests ) { $g = 'g' . wp_generate_password( 31, false ); $guests[] = 'guest_' . $g; return $g; };

/* One chat message through the real handler; returns the assistant's reply to it. */
$send = static function ( $uid, $message, $lang = 'fr', $guest = '' ) {
	wp_set_current_user( $uid );
	if ( $guest ) $_COOKIE['dap_session'] = $guest; else unset( $_COOKIE['dap_session'] );
	$_POST = $_REQUEST = array( 'message' => $message, 'intent' => 'bot', 'language' => $lang, 'client_message_id' => 'm' . wp_generate_password( 12, false ), 'nonce' => wp_create_nonce( 'dsa2_frontend' ) );
	ob_start();
	try { DSA2_Data::ajax_send_message(); } catch ( DAB_Test_Response $e ) { /* the JSON reply ended the request */ }
	$json = json_decode( (string) ob_get_clean(), true );
	$last = null;
	foreach ( (array) ( $json['data']['messages'] ?? array() ) as $m ) if ( 'assistant' === ( $m['sender'] ?? '' ) ) $last = $m;
	return $last ?: array( 'type' => '', 'body' => '', 'meta' => array(), 'raw' => $json );
};
/* A fresh thread before each question, so earlier answers neither repeat nor shadow the next. */
$fresh = static function ( $uid, $guest = '' ) use ( $wpdb ) {
	$key = $uid ? 'user_' . $uid : 'guest_' . $guest;
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dap_conversations WHERE session_key=%s", $key ) ) as $cid ) {
		$wpdb->delete( $wpdb->prefix . 'dap_messages', array( 'conversation_id' => (int) $cid ) );
		delete_transient( 'dap_pend_' . (int) $cid );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_dap\\_olk\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dap\\_olk\\_%' OR option_name LIKE '\\_transient\\_dap\\_rate\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dap\\_rate\\_%'" );
	wp_cache_flush();
};
$ask = static function ( $uid, $message, $lang = 'fr', $guest = '' ) use ( $send, $fresh ) { $fresh( $uid, $guest ); return $send( $uid, $message, $lang, $guest ); };
$secret = static function ( $text ) { return (bool) preg_match( '/secret-mail|37001234|Rue Secr|987654321|note interne/i', (string) $text ); };

try {
	/* Order lookup. */
	$r = $ask( $users['a'], '#' . $num_a );
	$m = $r['meta'] ?? array();
	$check( 'order: « #N » shows the customer\'s own order', 'order_card' === $r['type'] && false !== strpos( (string) ( $m['title'] ?? '' ), $num_a ) && 'completed' === ( $m['state'] ?? '' ), wp_json_encode( $r ) );
	$check( 'order: status, total, date, items and payment are shown', '' !== ( $m['status'] ?? '' ) && '' !== ( $m['total'] ?? '' ) && '' !== ( $m['date'] ?? '' ) && false !== strpos( implode( ' ', (array) ( $m['items'] ?? array() ) ), '× 2' ) && 'MonCash' === ( $m['payment'] ?? '' ) );
	$check( 'order: « Partager le reçu » carries a receipt', ! empty( $m['share']['text'] ) && false !== strpos( $m['share']['text'], '#' . $num_a ) && false !== strpos( $m['share']['text'], (string) ( $m['total'] ?? 'x' ) ) );
	$check( 'order: the receipt and card hold no e-mail, phone, address, Player ID or note', ! $secret( wp_json_encode( $r ) ) );
	$check( 'order: « Voir la commande » is the account\'s order page', ( $m['url'] ?? '' ) === $order_a->get_view_order_url() );
	$r = $ask( $users['a'], 'commande ' . $num_a );
	$check( 'order: « commande N » works too', 'order_card' === $r['type'] );
	$r = $ask( $users['a'], 'kòmand #' . $num_a . ' la kote li ye ?', 'ht' );
	$check( 'order: Kreyòl « kòmand #N » works too', 'order_card' === $r['type'] );
	$r = $ask( $users['a'], $num_a );
	$check( 'order: the number alone works too', 'order_card' === $r['type'] );

	$other   = $ask( $users['a'], '#' . $num_b );
	$missing = $ask( $users['a'], '#99999999' );
	$check( 'order: someone else\'s order is never shown', 'order_card' !== $other['type'] && ! $secret( wp_json_encode( $other ) ) && false === strpos( wp_json_encode( $other ), 'processing' ) );
	$same = $other['type'] === $missing['type'] && str_replace( $num_b, 'N', (string) ( $other['meta']['intro'] ?? '' ) ) === str_replace( '99999999', 'N', (string) ( $missing['meta']['intro'] ?? '' ) );
	$check( 'order: someone else\'s order reads exactly like a missing one', $same, $other['type'] . ' / ' . $missing['type'] );

	$g = $guest_key();
	$r = $ask( 0, '#' . $num_a, 'fr', $g );
	$check( 'order: a visitor is asked to sign in, nothing about the order is shown', 'order_sign_in' === $r['body'] && false === strpos( wp_json_encode( $r ), 'MonCash' ) && false === strpos( wp_json_encode( $r ), wp_strip_all_tags( wc_get_order_status_name( 'completed' ) ) ), wp_json_encode( $r ) );

	$fresh( $users['a'] );
	set_transient( 'dap_olk_u_' . $users['a'], DSA2_Brain::LOOKUPS_PER_HOUR, HOUR_IN_SECONDS );
	$r = $send( $users['a'], '#' . $num_a );
	$check( 'order: lookups are limited per hour', 'order_limit' === $r['body'] );

	$r = $ask( $users['a'], 'commande 110 diamants' );
	$check( 'order: « commande 110 diamants » is not an order number', false === strpos( (string) $r['body'], 'order_not_found' ) && 0 !== strpos( (string) $r['body'], 'order:110' ), $r['body'] );
	$r = $ask( $users['a'], 'Où est ma commande ?' );
	$check( 'order: « Où est ma commande ? » shows the last order', 'order_card' === $r['type'] && false !== strpos( (string) ( $r['meta']['title'] ?? '' ), $num_a ) );

	/* Small talk that used to swallow the question. */
	$r = $ask( $users['a'], 'Bonjour, je n’ai pas reçu mes diamants' );
	$check( 'small talk: « Bonjour, je n’ai pas reçu mes diamants » gets the top-up answer', 'small_talk_v3' !== ( $r['meta']['source'] ?? '' ) && 'topup_missing' === ( $r['meta']['intent'] ?? '' ), wp_json_encode( $r['meta'] ?? array() ) );
	$r = $ask( $users['a'], 'Est-ce que ça va arriver aujourd’hui ?' );
	$check( 'small talk: « Est-ce que ça va arriver aujourd’hui ? » is not « ça va »', 'how_are_you' !== ( $r['meta']['intent'] ?? '' ) );
	$r = $ask( $users['a'], 'J’attends depuis 3 jours' );
	$check( 'small talk: « J’attends depuis 3 jours » is not « prenez votre temps »', 'wait' !== ( $r['meta']['intent'] ?? '' ) );
	$r = $ask( $users['a'], 'Il n’y a plus de diamants ?' );
	$check( 'small talk: « Il n’y a plus de diamants ? » is not « à plus »', 'goodbye' !== ( $r['meta']['intent'] ?? '' ) );
	$r = $ask( $users['a'], 'Yo pa reponn mwen', 'ht' );
	$check( 'small talk: « Yo pa reponn mwen » is not a greeting', 'greeting' !== ( $r['meta']['intent'] ?? '' ) );
	$r = $ask( $users['a'], 'Bonjour' );
	$check( 'small talk: « Bonjour » still gets a greeting', 'greeting' === ( $r['meta']['intent'] ?? '' ) );
	$r = $ask( $users['a'], 'merci beaucoup' );
	$check( 'small talk: « merci beaucoup » still gets thanks', 'thanks' === ( $r['meta']['intent'] ?? '' ) );
	$ask( $users['a'], 'my name is not on the order', 'en' );
	$name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT customer_name FROM {$wpdb->prefix}dap_conversations WHERE session_key=%s", 'user_' . $users['a'] ) );
	$check( 'small talk: « my name is not… » stores no name « Not »', 'not' !== strtolower( $name ), $name );

	/* Routing. */
	$r = $ask( $users['a'], 'M pa resevwa PIN mwen', 'ht' );
	$check( 'routing: « M pa resevwa PIN mwen » gets the PIN answer, not the payment guide', 'pin_missing' === ( $r['meta']['intent'] ?? '' ) && 0 !== strpos( (string) $r['body'], 'guide:' ), $r['body'] );
	$r = $ask( $users['a'], 'How do I top up my Free Fire account?', 'en' );
	$check( 'routing: « top up my Free Fire account » is not the account guide', 'guide:account' !== $r['body'], $r['body'] );
	$r = $ask( $users['a'], 'Je veux parler à un humain' );
	$check( 'routing: « parler à un humain » asks for an agent', in_array( $r['type'], array( 'agent_card', 'contact_card' ), true ), $r['type'] );
	$r = $ask( $users['a'], 'Es-tu un robot ?' );
	$check( 'routing: « Es-tu un robot ? » explains the assistant', 'faq_card' === $r['type'] && false !== stripos( (string) ( $r['meta']['title'] ?? '' ), 'robot' ) );
	$r = $ask( $users['a'], 'Kòmand sa a se pou yon moun', 'ht' );
	$check( 'routing: « … pou yon moun » does not call an agent', ! in_array( $r['type'], array( 'agent_card', 'contact_card' ), true ), $r['type'] );
	$fresh( $users['a'] );
	$send( $users['a'], 'c’est nul, tu comprends rien' );
	$r = $send( $users['a'], 'oui' );
	$check( 'memory: « oui » after « voulez-vous un agent ? » connects', in_array( $r['type'], array( 'agent_card', 'contact_card' ), true ), $r['type'] );
	$fresh( $users['a'] );
	$send( $users['a'], 'c’est nul, tu comprends rien' );
	$send( $users['a'], 'Quels sont vos horaires ?' );
	$r = $send( $users['a'], 'oui' );
	$check( 'memory: the offer lapses once the conversation moves on', ! in_array( $r['type'], array( 'agent_card', 'contact_card' ), true ), $r['type'] );
	$r = $ask( $users['a'], '987654321' );
	$check( 'order: a Player ID typed alone (9 digits) is not looked up as an order', false === strpos( (string) $r['body'], 'order_not_found' ) && 'order_card' !== $r['type'], $r['body'] );
	$r = $ask( 0, 'où est ma commande', 'fr', $guest_key() );
	$check( 'wording: no « commande ce produit » when no product is named', false === strpos( wp_json_encode( $r ), 'ce produit' ) );

	/* The visitor form (number + e-mail or phone): wrong proofs are limited per order number, from any address. */
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%dap\\_ord\\_%' OR option_name LIKE '%dap\\_ofail\\_%'" );
	wp_cache_flush();
	$g = $guest_key();
	$find = static function ( $proof, $ip ) use ( $num_a, $g ) {
		wp_set_current_user( 0 );
		$_COOKIE['dap_session'] = $g;
		$_SERVER['REMOTE_ADDR'] = $ip;
		$_POST = $_REQUEST = array( 'order' => $num_a, 'proof' => $proof, 'language' => 'fr', 'nonce' => wp_create_nonce( 'dsa2_frontend' ) );
		ob_start();
		try { DSA2_Data::ajax_find_order(); } catch ( DAB_Test_Response $e ) {}
		return json_decode( (string) ob_get_clean(), true );
	};
	for ( $i = 0; $i < 5; $i++ ) $find( '+50911110' . $i . $i . $i, '198.51.100.' . ( 10 + $i ) ); // five addresses, five wrong phones
	$blocked = $find( 'secret-mail@example.test', '198.51.100.99' ); // even the right proof, from a sixth address
	$_SERVER['REMOTE_ADDR'] = '203.0.113.71';
	$check( 'visitor form: after 5 wrong proofs for one order number, that number is locked for an hour', empty( $blocked['success'] ) && false !== strpos( (string) ( $blocked['data']['message'] ?? '' ), 'Trop de tentatives' ), wp_json_encode( $blocked ) );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%dap\\_ord\\_%' OR option_name LIKE '%dap\\_ofail\\_%'" );
	wp_cache_flush();

	/* The rest of the store. */
	if ( function_exists( 'dlr_on' ) && dlr_on() ) {
		$r = $ask( $users['a'], 'Je veux devenir revendeur' );
		$check( 'store: the reseller programme with its three levels', 'reseller:info' === $r['body'] && 3 === count( (array) ( $r['meta']['steps'] ?? array() ) ) );
	}
	$r = $ask( $users['a'], 'Comment envoyer de l’argent à un ami ?' );
	$check( 'store: « envoyer de l’argent » explains Send Money', 'send_money:info' === $r['body'] && false !== strpos( (string) ( $r['meta']['intro'] ?? '' ), 'code personnel' ), $r['body'] );
	$r = $ask( $users['a'], 'Mon compte a été piraté' );
	$check( 'store: a hacked account gets the security card', 'security:info' === $r['body'], $r['body'] );
} finally {
	foreach ( $orders as $oid ) { $o = wc_get_order( $oid ); if ( $o ) $o->delete( true ); }
	$keys = $guests;
	foreach ( $users as $uid ) $keys[] = 'user_' . $uid;
	foreach ( $keys as $key ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dap_conversations WHERE session_key=%s", $key ) ) as $cid ) {
			$wpdb->delete( $wpdb->prefix . 'dap_messages', array( 'conversation_id' => (int) $cid ) );
			$wpdb->delete( $wpdb->prefix . 'dap_conversations', array( 'id' => (int) $cid ) );
		}
	}
	foreach ( $users as $uid ) wp_delete_user( $uid );
}
echo "\n$pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
