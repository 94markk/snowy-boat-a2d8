<?php
// php app-refresh.php <site-dir> — STAGING ONLY. Identity 6.17.6 / 6.17.7.
// App session renewal (POST delicat-identity/v1/mobile/refresh) on weak networks. Since 6.17.5 a refresh token that was
// already rotated is a replay: refused, and after REFRESH_REPLAY_GRACE (60 s) the whole session ends. When the answer
// to a renewal is lost (Digicel/Natcom connection drops, app closed before saving), the app only has the previous
// token: the customer was signed out. 6.17.7 renews again from the same installation and device within one minute
// of the rotation (lost answer, crossing renewals); later, or from another device, it stays a replay.
$site = $argv[1] ?? '';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php app-refresh.php <site-dir>\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTPS'] = 'on'; $_SERVER['REMOTE_ADDR'] = '203.0.113.' . mt_rand( 2, 250 );
require $site . '/wp-load.php';
if ( false === strpos( home_url(), 'localhost' ) ) { fwrite( STDERR, "refusing: not a staging site\n" ); exit( 3 ); }

$settings_before = get_option( 'dglp_settings', array() );
$settings        = is_array( $settings_before ) ? $settings_before : array();
$settings['mobile_api_enabled'] = 'yes';
update_option( 'dglp_settings', $settings );
$ready_before = get_option( 'dip_mobile_rotation_ready' );
if ( ! DIP_Mobile_API::enabled() ) { fwrite( STDERR, "mobile API still disabled (settings cached)\n" ); update_option( 'dglp_settings', $settings_before ); exit( 4 ); }
if ( function_exists( 'dip_mobile_api_install' ) ) { dip_mobile_api_install(); }
if ( method_exists( 'DIP_Mobile_API', 'install' ) ) { DIP_Mobile_API::install(); }
if ( '1' !== get_option( 'dip_mobile_rotation_ready' ) ) { fwrite( STDERR, "rotation columns not ready on this database\n" ); update_option( 'dglp_settings', $settings_before ); exit( 5 ); }

$results = array();
$check   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$results ): void {
	$results[] = $ok;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' — ' . $detail : '' ) . "\n";
};
$login = 'arf_' . wp_rand( 10000, 99999 );
$uid   = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@gmail.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'customer' ) );
update_user_meta( $uid, 'dip_email_verified', 'yes' );
update_user_meta( $uid, '_dip_verified_email_fingerprint', hash( 'sha256', $login . '@gmail.com' ) );
update_user_meta( $uid, '_dip_verified_email_proof_v2', hash( 'sha256', $login . '@gmail.com' ) );
update_user_meta( $uid, 'dip_age_declaration', 'adult' );

$request = static function ( array $body, string $device = 'pixel-device-0001' ): WP_REST_Request {
	$r = new WP_REST_Request( 'POST', '/delicat-identity/v1/mobile/refresh' );
	$r->set_header( 'X-Device-ID', $device );
	$r->set_header( 'X-Installation-ID', 'install-' . $device . '-abcdef' );
	$r->set_header( 'X-Platform', 'android' );
	foreach ( $body as $k => $v ) { $r->set_param( $k, $v ); }
	return $r;
};
$data = static function ( $response ): array {
	if ( is_wp_error( $response ) ) return array( 'error' => $response->get_error_code(), 'status' => (int) ( $response->get_error_data()['status'] ?? 0 ) );
	return array( 'status' => $response->get_status() ) + (array) $response->get_data();
};
global $wpdb;
$table   = $wpdb->prefix . 'dip_mobile_tokens';
$age_row = static function ( string $refresh, int $rotated_ago, int $used_ago ) use ( $wpdb, $table ): void {
	// move the rotation (and the last use) into the past, as if the app came back later
	$hash = hash_hmac( 'sha256', $refresh, wp_salt( 'auth' ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET rotated_at = %s, last_used_at = %s WHERE refresh_hash = %s", gmdate( 'Y-m-d H:i:s', time() - $rotated_ago ), gmdate( 'Y-m-d H:i:s', time() - $used_ago ), $hash ) );
};
$live = static function ( string $refresh ) use ( $wpdb, $table ): bool {
	$hash = hash_hmac( 'sha256', $refresh, wp_salt( 'auth' ) );
	return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE refresh_hash = %s AND revoked_at IS NULL", $hash ) );
};

// sign in (Google) from the app: a session with token A
$first = $data( DIP_Mobile_API::issue_for_user( $uid, $request( array() ), 'google' ) );
$a     = (string) ( $first['refresh_token'] ?? '' );
$check( 'app session issued', '' !== $a, wp_json_encode( array_intersect_key( $first, array( 'status' => 1, 'error' => 1 ) ) ) );

// 1. normal renewal A → B
$r1 = $data( DIP_Mobile_API::refresh( $request( array( 'refresh_token' => $a ) ) ) );
$b  = (string) ( $r1['refresh_token'] ?? '' );
$check( 'normal renewal works', 200 === $r1['status'] && '' !== $b && $b !== $a );

// 2. the answer (B) was lost: the app retries with A right away → renewed again (C), session alive
$r2 = $data( DIP_Mobile_API::refresh( $request( array( 'refresh_token' => $a ) ) ) );
$c  = (string) ( $r2['refresh_token'] ?? '' );
$check( 'lost answer, immediate retry with the previous token: renewed, not signed out', 200 === $r2['status'] && '' !== $c && $live( $c ), wp_json_encode( array_diff_key( $r2, array( 'access_token' => 1, 'refresh_token' => 1, 'user' => 1, 'device' => 1 ) ) ) );

// 3. the previous token comes back 10 minutes after its rotation: recovery is limited to one minute (6.17.7,
// security review), so it is a replay — refused and the session ended, as in 6.17.5
$r3 = $data( DIP_Mobile_API::refresh( $request( array( 'refresh_token' => $c ) ) ) );
$d  = (string) ( $r3['refresh_token'] ?? '' );
$age_row( $d, 600, 600 );
$r4 = $data( DIP_Mobile_API::refresh( $request( array( 'refresh_token' => $c ) ) ) );
$check( 'previous token 10 min after its rotation: refused and the session ended (recovery is one minute)', 401 === $r4['status'] && ! $live( $d ), wp_json_encode( $r4 ) );

// 5. a fresh session; its previous token presented from another phone: refused (no renewal for another device)
$again = $data( DIP_Mobile_API::issue_for_user( $uid, $request( array() ), 'google' ) );
$g     = (string) ( $again['refresh_token'] ?? '' );
$r7    = $data( DIP_Mobile_API::refresh( $request( array( 'refresh_token' => $g ) ) ) );
$h     = (string) ( $r7['refresh_token'] ?? '' );
$r8    = $data( DIP_Mobile_API::refresh( $request( array( 'refresh_token' => $g ), 'other-phone-9999' ) ) );
$check( 'previous token from another phone: refused', 401 === $r8['status'] && $live( $h ), wp_json_encode( $r8 ) );

require_once ABSPATH . 'wp-admin/includes/user.php';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $uid ) ); // phpcs:ignore -- test rows only
wp_delete_user( $uid );
update_option( 'dglp_settings', $settings_before );
if ( false === $ready_before ) { delete_option( 'dip_mobile_rotation_ready' ); }
$passed = count( array_filter( $results ) );
echo "\n{$passed}/" . count( $results ) . " passed\n";
exit( $passed === count( $results ) ? 0 : 1 );
