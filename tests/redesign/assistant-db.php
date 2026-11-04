<?php
// php assistant-db.php <site-dir> <command> [args] — STAGING ONLY test helper for the assistant e2e suite.
//   agent-reply <user_login> <text>   the support agent (admin) writes into that customer's thread
//   agent-typing <user_login>         sets the agent-typing flag on that thread
//   conv <user_login>                 JSON: the customer's conversation + its last 8 messages
//   group <slug>                      JSON: the room's last 12 messages (id, user, type, status, flags, body)
//   count-conv                        number of conversation rows
//   reset-reports <slug>              deletes reports in that room and makes its messages visible again
//   reset-room <slug>                 keeps only the 3 seed messages of the room (fresh state for a test run)
$site = $argv[1];
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
dsa2_load_full_engine();
global $wpdb;
$cmd = $argv[2] ?? '';
$conv_for = function ( $login ) use ( $wpdb ) {
	$u = get_user_by( 'login', $login );
	return $u ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dap_conversations WHERE user_id=%d ORDER BY last_message_at DESC LIMIT 1", $u->ID ) ) : null;
};
switch ( $cmd ) {
	case 'agent-reply':
		$c = $conv_for( $argv[3] );
		wp_set_current_user( 1 );
		$id = DSA2_Data::add_message( (int) $c->id, 'agent', $argv[4], 'text', array(), 'agent' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dap_messages SET sender_id=1 WHERE id=%d", $id ) );
		$wpdb->update( $wpdb->prefix . 'dap_conversations', array( 'status' => 'open', 'assigned_to' => 1, 'last_agent_message_at' => current_time( 'mysql' ) ), array( 'id' => (int) $c->id ) );
		echo json_encode( array( 'message' => (int) $id ) );
		break;
	case 'agent-typing':
		$c = $conv_for( $argv[3] );
		set_transient( 'dap_agent_typing_' . (int) $c->id, 1, 15 );
		echo json_encode( array( 'ok' => 1 ) );
		break;
	case 'conv':
		$c = $conv_for( $argv[3] );
		$m = $c ? $wpdb->get_results( $wpdb->prepare( "SELECT id,sender,channel,message_type,body FROM {$wpdb->prefix}dap_messages WHERE conversation_id=%d ORDER BY id DESC LIMIT 8", $c->id ) ) : array();
		echo json_encode( array( 'conv' => $c, 'messages' => $m ), JSON_UNESCAPED_UNICODE );
		break;
	case 'group':
		$g = DSA2_Groups::get_group( $argv[3] );
		$m = $wpdb->get_results( $wpdb->prepare( 'SELECT id,user_id,message_type,status,flags,report_count,body FROM ' . DSA2_Groups::table( 'messages' ) . ' WHERE group_id=%d ORDER BY id DESC LIMIT 60', $g->id ) );
		echo json_encode( array( 'group' => (int) $g->id, 'messages' => $m ), JSON_UNESCAPED_UNICODE );
		break;
	case 'reset-room':
		$g = DSA2_Groups::get_group( $argv[3] );
		$t = DSA2_Groups::table( 'messages' );
		$keep = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE group_id=%d ORDER BY id ASC LIMIT 3", $g->id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE group_id=%d AND id NOT IN (" . implode( ',', array_map( 'absint', $keep ) ) . ')', $g->id ) );
		$wpdb->delete( DSA2_Groups::table( 'reports' ), array( 'group_id' => $g->id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET status='visible', report_count=0, pinned=0 WHERE group_id=%d", $g->id ) );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dapg_%' OR option_name LIKE '_transient_timeout_dapg_%'" );
		DSA2_Groups::refresh_counts( (int) $g->id );
		echo 'ok';
		break;
	case 'count-conv':
		echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dap_conversations" );
		break;
	case 'order': // order <login>: a completed order with private details the chat must never show; prints id and number
		add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
		$u = get_user_by( 'login', $argv[3] );
		$o = wc_create_order( array( 'customer_id' => $u->ID ) );
		$o->add_product( wc_get_product( get_page_by_path( 'pin-free-341-diamants', OBJECT, 'product' )->ID ), 1 );
		foreach ( $o->get_items() as $item ) { $item->add_meta_data( 'Player ID', '987654321' ); $item->save(); }
		$o->set_billing_email( 'secret-mail@example.test' ); $o->set_billing_phone( '+50937001234' ); $o->set_billing_address_1( '12 Rue Secrète' );
		$o->set_payment_method_title( 'MonCash' ); $o->calculate_totals(); $o->set_status( 'completed' ); $o->save();
		echo wp_json_encode( array( 'id' => $o->get_id(), 'number' => (string) $o->get_order_number() ) );
		break;
	case 'order-delete': // order-delete <id>
		$o = wc_get_order( absint( $argv[3] ) );
		if ( $o ) $o->delete( true );
		echo 'ok';
		break;
	case 'reset-reports':
		$g = DSA2_Groups::get_group( $argv[3] );
		$wpdb->delete( DSA2_Groups::table( 'reports' ), array( 'group_id' => $g->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . DSA2_Groups::table( 'messages' ) . " SET status='visible', report_count=0 WHERE group_id=%d AND status='hidden'", $g->id ) );
		echo 'ok';
		break;
	default:
		echo "unknown command\n";
}
