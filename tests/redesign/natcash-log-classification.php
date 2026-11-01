<?php
// php natcash-log-classification.php <wordpress-path> — Recharge 6.6.2 regression test (run on a staging copy only):
// the Natcash SMS diagnostic page must label only real approvals as validated (6.6.1 labelled every row
// "ok" after the emoji removal). Seeds five log entries, renders the page as user 1, restores the log.
$_SERVER['HTTP_HOST'] = 'localhost:8943'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=delicat-natcash-log'; $_SERVER['HTTPS'] = 'on';
define( 'WP_ADMIN', true );
require $argv[1] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
$saved = get_option( 'delicat_natcash_log', null );
$now   = current_time( 'mysql' );
$seed  = array(
	array( 'time' => $now, 'raw' => 'sms 1', 'result' => 'APPROUVÉ Natcash-TC #11', 'trust' => 'ok' ),
	array( 'time' => $now, 'raw' => 'sms 2', 'result' => "\u{2705} APPROUVÉ MonCash #12", 'trust' => 'ok' ),
	array( 'time' => $now, 'raw' => 'sms 3', 'result' => 'Aucune demande correspondante', 'trust' => 'ok' ),
	array( 'time' => $now, 'raw' => 'sms 4', 'result' => 'SUSPECT — non crédité', 'trust' => 'suspect' ),
	array( 'time' => $now, 'raw' => 'sms 5', 'result' => 'RETENU #13 — approbation manuelle', 'trust' => 'held' ),
);
update_option( 'delicat_natcash_log', $seed, false );
try {
	ob_start();
	( new Delicat_Natcash_Auto() )->log_page();
	$html = ob_get_clean();
} finally {
	if ( null === $saved ) { delete_option( 'delicat_natcash_log' ); } else { update_option( 'delicat_natcash_log', $saved, false ); }
}
preg_match_all( '/dlc-item dlc-item-([a-z]+)/', $html, $m );
echo 'classes: ' . implode( ',', $m[1] ) . "\n";
echo ( $m[1] === array( 'ok', 'ok', 'fail', 'sus', 'held' ) ? 'PASS' : 'FAIL' ) . "\n";
