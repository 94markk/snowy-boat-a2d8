<?php
// php rewards-db.php <site-dir> <command> [login] — STAGING ONLY helper for rewards-e2e.cjs.
//   reward-orders <login>   number of reward orders (free PIN orders) of that customer
$site = $argv[1] ?? '';
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
$u = get_user_by( 'login', (string) ( $argv[3] ?? '' ) );
switch ( $argv[2] ?? '' ) {
	case 'reward-orders':
		echo $u ? count( wc_get_orders( array( 'customer_id' => $u->ID, 'limit' => -1, 'return' => 'ids', 'meta_key' => '_delicat_reward', 'meta_compare' => 'EXISTS' ) ) ) : -1;
		break;
	default:
		fwrite( STDERR, "unknown command\n" ); exit( 2 );
}
