<?php
// php assistant-setup.php <site-dir> — STAGING ONLY. Activates Delicat Assistant Pro (whatever version the plugin
// symlink points at) and seeds data shaped like a live store: a support thread for client1, two more
// customers (one old account, one brand new), an agent, and messages in the default community room.
$site = $argv[1];
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/wp-admin/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );
$p = 'delicat-assistant-pro/delicat-assistant-pro.php';
if ( ! is_plugin_active( $p ) ) {
	$r = activate_plugin( $p, '', false, false );
	echo is_wp_error( $r ) ? 'FAIL ' . $r->get_error_message() . "\n" : "activated\n";
}
dsa2_load_full_engine();
if ( class_exists( 'DSA2_Install' ) ) DSA2_Install::activate();
global $wpdb;

function seed_user( $login, $role, $days_old ) {
	$u = get_user_by( 'login', $login );
	if ( ! $u ) {
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'ClientPass!2026', 'user_email' => $login . '@example.test', 'first_name' => ucfirst( $login ), 'role' => $role ) );
		$u = get_user_by( 'id', $id );
	}
	global $wpdb;
	$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - $days_old * DAY_IN_SECONDS ) ), array( 'ID' => $u->ID ) );
	// Identity Pro refuses sign-in until the e-mail is confirmed: mark it as a confirmed address would be.
	update_user_meta( $u->ID, 'dip_email_verified', 'yes' );
	update_user_meta( $u->ID, '_dip_verified_email_fingerprint', hash( 'sha256', strtolower( sanitize_email( $u->user_email ) ) ) );
	return (int) $u->ID;
}
$c1 = (int) get_user_by( 'login', 'client1' )->ID;
$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), array( 'ID' => $c1 ) );
$c2 = seed_user( 'client2', 'customer', 20 );
$c3 = seed_user( 'client3', 'customer', 0 );
$ag = seed_user( 'agent1', 'delicat_support_agent', 60 );
echo "users client1=$c1 client2=$c2 client3=$c3 agent1=$ag\n";

// Support thread for client1, as 6.6.4 would have stored it.
$conv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dap_conversations WHERE session_key=%s", 'user_' . $c1 ) );
if ( ! $conv ) {
	$wpdb->insert( $wpdb->prefix . 'dap_conversations', array( 'session_key' => 'user_' . $c1, 'user_id' => $c1, 'customer_name' => 'Client', 'customer_email' => 'client1@example.test', 'status' => 'open', 'language' => 'fr', 'last_message_at' => current_time( 'mysql' ), 'created_at' => current_time( 'mysql' ) ) );
	$cid = (int) $wpdb->insert_id;
	DSA2_Data::add_message( $cid, 'assistant', 'Bonjour ! Comment puis-je vous aider ?', 'text', array(), 'bot' );
	DSA2_Data::add_message( $cid, 'customer', 'Ma recharge Free Fire n’est pas arrivée', 'text', array(), 'agent' );
	DSA2_Data::add_message( $cid, 'agent', 'Bonjour Client, je vérifie votre commande tout de suite.', 'text', array(), 'agent' );
	$wpdb->update( $wpdb->prefix . 'dap_conversations', array( 'assigned_to' => 1, 'last_agent_message_at' => current_time( 'mysql' ) ), array( 'id' => $cid ) );
	echo "conversation $cid\n";
}

// Community: default room + a few messages.
if ( class_exists( 'DSA2_Groups' ) ) {
	DSA2_Groups::seed_default_group();
	$g = DSA2_Groups::get_group( 'communaute-delicat' );
	foreach ( array( $c1, $c2, $c3 ) as $uid ) DSA2_Groups::add_member( (int) $g->id, $uid );
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dap_group_messages WHERE group_id=%d", $g->id ) );
	if ( ! $count ) {
		DSA2_Groups::insert_message( (int) $g->id, $c2, 'Salut tout le monde ! Quelqu’un a déjà rechargé PUBG ici ?' );
		DSA2_Groups::insert_message( (int) $g->id, $c1, 'Oui, ça arrive en moins d’une minute chez moi.' );
		DSA2_Groups::insert_message( (int) $g->id, 1, 'Bienvenue dans la communauté ! Pour toute commande, l’équipe répond aussi ici.' );
	}
	// A hidden room client1 is NOT a member of (access-control test).
	if ( ! DSA2_Groups::get_group( 'vip-prive' ) ) {
		$wpdb->insert( DSA2_Groups::table( 'groups' ), array( 'slug' => 'vip-prive', 'name' => 'VIP privé', 'description' => 'Salon caché', 'icon' => '', 'visibility' => 'hidden', 'status' => 'active', 'locked' => 0, 'slow_mode' => 0, 'member_count' => 0, 'message_count' => 0, 'sort_order' => 2, 'created_by' => 1, 'created_at' => current_time( 'mysql', 1 ) ) );
		$vip = DSA2_Groups::get_group( 'vip-prive' );
		DSA2_Groups::add_member( (int) $vip->id, $c2 );
		DSA2_Groups::insert_message( (int) $vip->id, $c2, 'Message secret du salon VIP' );
	}
	echo 'group ' . $g->id . "\n";
}
$s = (array) get_option( 'dap_settings', array() );
$s['whatsapp'] = '50937000000';
$s['groups_slow_mode'] = 0;
update_option( 'dap_settings', $s );
$wpdb->update( DSA2_Groups::table( 'groups' ), array( 'slow_mode' => 0 ), array( 'slug' => 'communaute-delicat' ) );
echo 'version ' . DSA2_VERSION . "\n";
