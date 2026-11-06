<?php
// php google-relogin.php <site-dir> — STAGING ONLY. Identity 6.17.6 / 6.17.7.
// Customers reported « my account was disconnected and I can't sign back in with Google ». A Google sign-in that
// matches an existing customer by e-mail (first Google use, or after « Mot de passe oublié » removed a recent link)
// went through adopt_unproven_account(), which marked the address « not confirmed » whatever the account's age, so
// the pre-registration reset wiped the password, every website session and the app sessions; with a passkey, 2FA or
// another linked provider the sign-in was refused instead. The rule the plugin documents: only an account still
// waiting for its link, or created less than 30 days ago, is reset (someone may have registered the address first).
// Each case builds a customer, runs DIP_Identity::resolve() with a verified Gmail profile (what Google's callback and
// the app's Google route pass), then checks the account. Everything created here is removed at the end.
$site = $argv[1] ?? '';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php google-relogin.php <site-dir>\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
if ( false === strpos( home_url(), 'localhost' ) ) { fwrite( STDERR, "refusing: not a staging site\n" ); exit( 3 ); }
foreach ( array( 'DIP_Identity', 'DIP_Email_Proof', 'DIP_Account_Sync', 'DIP_Mobile_API' ) as $class ) {
	if ( ! class_exists( $class ) ) { fwrite( STDERR, "missing {$class}\n" ); exit( 4 ); }
}

$results = array();
$check   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$results ): void {
	$results[] = $ok;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' — ' . $detail : '' ) . "\n";
};
$made     = array();
$customer = static function ( string $slug, int $age_days, string $verified, bool $other_provider ) use ( &$made ): array {
	$login = 'grl_' . $slug . '_' . wp_rand( 1000, 9999 );
	$email = $login . '@gmail.com';
	$pass  = 'Own-Password!' . wp_rand( 1000, 9999 );
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => $pass, 'role' => 'customer' ) );
	if ( is_wp_error( $id ) ) { fwrite( STDERR, $id->get_error_message() . "\n" ); exit( 5 ); }
	$made[] = (int) $id;
	global $wpdb;
	$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - $age_days * DAY_IN_SECONDS ) ), array( 'ID' => $id ) );
	clean_user_cache( $id );
	if ( '' !== $verified ) {
		update_user_meta( $id, 'dip_email_verified', $verified ); // 'yes' without a proof marker = unproven
	}
	update_user_meta( $id, 'dip_age_declaration', 'adult' );
	update_user_meta( $id, 'dip_age_declared_at', time() );
	if ( $other_provider ) {
		update_user_meta( $id, DIP_Identity::meta_sub_key( 'microsoft' ), 'ms-' . wp_rand( 100000, 999999 ) );
	}
	WP_Session_Tokens::get_instance( $id )->create( time() + DAY_IN_SECONDS ); // a phone where the customer is signed in
	return array( 'id' => (int) $id, 'email' => $email, 'pass' => $pass );
};
$state = static function ( array $c ): array {
	clean_user_cache( $c['id'] );
	$user = get_userdata( $c['id'] );
	return array(
		'password_kept' => $user && wp_check_password( $c['pass'], $user->user_pass, $c['id'] ),
		'sessions'      => count( WP_Session_Tokens::get_instance( $c['id'] )->get_all() ),
		'proof'         => DIP_Email_Proof::state( $c['id'] ),
	);
};
$settings                        = DIP_Mobile_API::settings();
$settings['link_existing_email'] = 'yes';
$run = static function ( array $c ) use ( $settings ) {
	$profile = array( 'provider' => 'google', 'sub' => 'g-' . $c['id'] . '-' . wp_rand( 1000, 9999 ), 'email' => $c['email'], 'email_verified' => true, 'hd' => '', 'name' => 'Test', 'given_name' => 'Test', 'family_name' => 'Client', 'picture' => '' );
	return ( new DIP_Identity( $settings ) )->resolve( $profile, 0 );
};
$detail = static fn( $r, array $s ): string => ( is_wp_error( $r ) ? $r->get_error_code() . ': ' . $r->get_error_message() . ' ' : '' ) . wp_json_encode( $s );

// 1. long-standing customer (400 days), address never proven, only a password: signs in with Google
$c = $customer( 'old', 400, 'yes', false );
$r = $run( $c );
$s = $state( $c );
$check( 'long-standing customer: Google signs in', ! is_wp_error( $r ) && (int) $r === $c['id'], $detail( $r, $s ) );
$check( 'long-standing customer: password and other sessions kept', $s['password_kept'] && 1 === $s['sessions'], $detail( $r, $s ) );
$check( 'long-standing customer: address now proven, Google linked', 'proven' === $s['proof'] && '' !== (string) get_user_meta( $c['id'], DIP_Identity::META_SUB, true ), $detail( $r, $s ) );

// 2. long-standing customer with another sign-in method (Microsoft) linked: refused (6.17.7, security review) —
// whoever set that method up may not own the mailbox — but nothing is wiped and the message says how to get in
$c = $customer( 'oldms', 400, 'yes', true );
$r = $run( $c );
$s = $state( $c );
$check( 'long-standing customer with another linked sign-in: refused, nothing wiped, told how to get in', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Mon compte' ) && $s['password_kept'] && 1 === $s['sessions'], $detail( $r, $s ) );

// 3. account created 2 days ago, never proven: the pre-registration protection still applies
$c = $customer( 'young', 2, 'yes', false );
$r = $run( $c );
$s = $state( $c );
$check( 'new account (2 days): Google proof resets what was set up before it', ! is_wp_error( $r ) && ! $s['password_kept'] && 0 === $s['sessions'], $detail( $r, $s ) );

// 4. account created 2 days ago carrying another identity: still refused (a reset could not remove it)
$c = $customer( 'youngms', 2, 'yes', true );
$r = $run( $c );
$check( 'new account (2 days) with another linked sign-in: still refused', is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_code() : 'accepted' );

// 4b. an old account (400 days) whose e-mail was just changed to this unregistered Gmail (verification off): the
// address is as young as a new registration, so a Google proof by the mailbox owner resets what was set up before
$c = $customer( 'moved', 400, 'yes', false );
update_user_meta( $c['id'], DIP_Email_Proof::UNPROVEN_SINCE, time() - HOUR_IN_SECONDS );
$r = $run( $c );
$s = $state( $c );
$check( 'old account whose e-mail just changed: treated as new (reset), no takeover', ! is_wp_error( $r ) && ! $s['password_kept'] && 0 === $s['sessions'] && '' === (string) get_user_meta( $c['id'], DIP_Email_Proof::UNPROVEN_SINCE, true ), $detail( $r, $s ) );
$c = $customer( 'movedms', 400, 'yes', true );
update_user_meta( $c['id'], DIP_Email_Proof::UNPROVEN_SINCE, time() - HOUR_IN_SECONDS );
$r = $run( $c );
$check( 'old account whose e-mail just changed, with another linked sign-in: refused', is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_code() : 'accepted' );

// 5. an already proven customer: nothing changes
$c = $customer( 'proven', 400, 'yes', false );
update_user_meta( $c['id'], '_dip_verified_email_fingerprint', hash( 'sha256', strtolower( $c['email'] ) ) );
update_user_meta( $c['id'], '_dip_verified_email_proof_v2', hash( 'sha256', strtolower( $c['email'] ) ) );
$r = $run( $c );
$s = $state( $c );
$check( 'proven customer: Google signs in, nothing reset', ! is_wp_error( $r ) && $s['password_kept'] && 1 === $s['sessions'], $detail( $r, $s ) );

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $made as $id ) {
	wp_delete_user( $id );
}
$passed = count( array_filter( $results ) );
echo "\n{$passed}/" . count( $results ) . " passed\n";
exit( $passed === count( $results ) ? 0 : 1 );
