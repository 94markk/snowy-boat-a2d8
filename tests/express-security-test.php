<?php
/**
 * The security decisions on the express payment path, executed rather than read.
 *
 * Three of them, and all three fail quietly when they are wrong:
 *
 *  1. Whether the CUSTOMER's connection is encrypted. Every SSL gate in the
 *     plugin asked is_ssl(), which reports the leg between the edge and PHP.
 *     Behind Cloudflare or LiteSpeed that leg is plain http, so the answer is
 *     "no" on a site that is https end to end - the express gates refuse and
 *     HSTS is never sent. The new answer must be permissive about proxies and
 *     completely unmovable on a plain-http install, where a forged header would
 *     otherwise be enough to claim encryption.
 *
 *  2. What read() will hand back. It feeds maybe_unserialize(), which is where a
 *     serialized object turns a database row into code execution. Nothing this
 *     class stores is ever an object.
 *
 *  3. When a per-customer payment guard may be taken away from whoever holds
 *     it. Too eager and two requests charge the same cart; too timid and a
 *     request PHP killed locks that customer out of checkout for good.
 *
 * Nothing here is asserted from source text: the bootstrap helper is evaluated
 * out of the real plugin file and the payment class is loaded and called against
 * a fake wpdb, so changing the code changes what these tests see.
 *
 * Run: php tests/express-security-test.php
 */

$root = __DIR__ . '/../delicat-builder-v9';

$pass = 0; $fail = 0;
function ok( string $what, bool $cond, string $detail = '' ): void {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok    $what\n"; }
	else { $fail++; echo "  FAIL  $what" . ( $detail ? "  -- $detail" : '' ) . "\n"; }
}
function group( string $n ): void { echo "\n$n\n" . str_repeat( '-', strlen( $n ) ) . "\n"; }

/* ------------------------------------------------------------------ */
/* Just enough WordPress to run these two files and nothing else.      */
/* ------------------------------------------------------------------ */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['stub_is_ssl']    = false;
$GLOBALS['stub_home']      = 'https://delicastoreha.com';
$GLOBALS['stub_filters']   = array();

function is_ssl() { return (bool) $GLOBALS['stub_is_ssl']; }
function home_url( $p = '/' ) { return $GLOBALS['stub_home'] . $p; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['stub_filters'][ $tag ] ) ? call_user_func( $GLOBALS['stub_filters'][ $tag ], $value ) : $value;
}
function add_action() {}
function add_filter() {}
function wp_cache_delete() {}
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_url( $u ) { return (string) $u; }
function esc_html__( $t, $d = null ) { return $t; }
function admin_url( $p = '' ) { return 'https://delicastoreha.com/wp-admin/' . $p; }
function is_serialized( $data ): bool {
	if ( ! is_string( $data ) ) { return false; }
	$data = trim( $data );
	if ( 'N;' === $data ) { return true; }
	if ( strlen( $data ) < 4 || ':' !== $data[1] ) { return false; }
	return (bool) preg_match( '/^[adObis]:/', $data );
}
function maybe_serialize( $data ) { return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data; }
function maybe_unserialize( $data ) { return is_serialized( $data ) ? @unserialize( trim( $data ) ) : $data; }

/* ------------------------------------------------------------------ */
/* A wp_options table that answers the four statements this code runs. */
/* ------------------------------------------------------------------ */

class Fake_WPDB {
	public $options    = 'wp_options';
	public $last_error = '';
	public $rows       = array();
	/** Fires after each row is served, so a test can move the world mid-read. */
	public $after_read = null;

	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }

	public function get_var( $q ) {
		$name  = $q['args'][0];
		$value = array_key_exists( $name, $this->rows ) ? $this->rows[ $name ] : null;
		if ( $this->after_read ) { call_user_func( $this->after_read, $name, $this ); }
		return $value;
	}

	public function query( $q ) {
		$verb = strtoupper( strtok( ltrim( $q['sql'] ), ' ' ) );
		if ( 'DELETE' === $verb && ! preg_match( '/BINARY option_value = BINARY/', $q['sql'] ) ) {
			throw new RuntimeException( 'a guard must only ever be deleted by its owner' );
		}
		if ( 'INSERT' === $verb ) {
			list( $name, $value ) = $q['args'];
			if ( array_key_exists( $name, $this->rows ) ) { return 0; }   // INSERT IGNORE
			$this->rows[ $name ] = (string) $value;
			return 1;
		}
		if ( 'UPDATE' === $verb ) {
			list( $after, $name, $before ) = $q['args'];
			if ( ( $this->rows[ $name ] ?? null ) !== (string) $before ) { return 0; }
			$this->rows[ $name ] = (string) $after;
			return 1;
		}
		if ( 'DELETE' === $verb ) {
			$this->delete_hits++;
			list( $name, $owner ) = $q['args'];
			if ( ( $this->rows[ $name ] ?? null ) !== (string) $owner ) { return 0; }  // BINARY compare
			unset( $this->rows[ $name ] );
			return 1;
		}
		return 0;
	}
}

$GLOBALS['wpdb'] = new Fake_WPDB();

/* ------------------------------------------------------------------ */
/* Part 1 - delicat_builder_v9_request_is_secure(), lifted from source */
/* ------------------------------------------------------------------ */

$bootstrap = (string) file_get_contents( "$root/delicat-builder-v9.php" );
$start     = strpos( $bootstrap, 'function delicat_builder_v9_request_is_secure(): bool {' );
if ( false === $start ) {
	echo "FAIL: the bootstrap no longer declares delicat_builder_v9_request_is_secure()\n";
	exit( 1 );
}
$depth = 0; $end = $start;
for ( $i = strpos( $bootstrap, '{', $start ); $i < strlen( $bootstrap ); $i++ ) {
	if ( '{' === $bootstrap[ $i ] ) { $depth++; }
	elseif ( '}' === $bootstrap[ $i ] ) { $depth--; if ( 0 === $depth ) { $end = $i; break; } }
}
$helper_src = substr( $bootstrap, $start, $end - $start + 1 );
eval( $helper_src );

/** Run the helper against one request's worth of headers. */
function secure_with( array $server, bool $php_tls = false, string $home = 'https://delicastoreha.com' ): bool {
	$GLOBALS['stub_is_ssl'] = $php_tls;
	$GLOBALS['stub_home']   = $home;
	foreach ( array( 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SCHEME', 'HTTP_X_FORWARDED_SSL', 'HTTP_CF_VISITOR' ) as $k ) {
		unset( $_SERVER[ $k ] );
	}
	foreach ( $server as $k => $v ) { $_SERVER[ $k ] = $v; }
	return delicat_builder_v9_request_is_secure();
}

group( 'A https site behind a proxy is recognised as https' );

ok( 'PHP itself on TLS, no headers at all', secure_with( array(), true ) );
ok( 'X-Forwarded-Proto: https', secure_with( array( 'HTTP_X_FORWARDED_PROTO' => 'https' ) ) );
ok( 'X-Forwarded-Scheme: https', secure_with( array( 'HTTP_X_FORWARDED_SCHEME' => 'https' ) ) );
ok( 'X-Forwarded-SSL: on', secure_with( array( 'HTTP_X_FORWARDED_SSL' => 'on' ) ) );
ok( 'CF-Visitor says the customer used https', secure_with( array( 'HTTP_CF_VISITOR' => '{"scheme":"https"}' ) ) );
ok( 'uppercase HTTPS still counts', secure_with( array( 'HTTP_X_FORWARDED_PROTO' => 'HTTPS' ) ) );
ok(
	'a proxy chain is read from the client end',
	secure_with( array( 'HTTP_X_FORWARDED_PROTO' => 'https, http' ) ),
	'the first entry is the customer-facing hop'
);

group( 'and everything else is not' );

ok( 'no headers and no TLS', ! secure_with( array() ) );
ok( 'X-Forwarded-Proto: http', ! secure_with( array( 'HTTP_X_FORWARDED_PROTO' => 'http' ) ) );
ok( 'CF-Visitor says http', ! secure_with( array( 'HTTP_CF_VISITOR' => '{"scheme":"http"}' ) ) );
ok( 'X-Forwarded-SSL: off', ! secure_with( array( 'HTTP_X_FORWARDED_SSL' => 'off' ) ) );
ok(
	'a chain whose client hop was plain http',
	! secure_with( array( 'HTTP_X_FORWARDED_PROTO' => 'http, https' ) ),
	'https on an inner hop says nothing about the customer'
);

group( 'A plain-http install cannot be talked into claiming encryption' );

foreach ( array(
	'HTTP_X_FORWARDED_PROTO'  => 'https',
	'HTTP_X_FORWARDED_SCHEME' => 'https',
	'HTTP_X_FORWARDED_SSL'    => 'on',
	'HTTP_CF_VISITOR'         => '{"scheme":"https"}',
) as $header => $value ) {
	ok(
		"$header is ignored when the site's own home URL is http",
		! secure_with( array( $header => $value ), false, 'http://delicastoreha.com' ),
		'a forged header would otherwise be a free pass through every SSL gate'
	);
}
ok(
	'but real TLS still counts on such a site',
	secure_with( array(), true, 'http://delicastoreha.com' ),
	'is_ssl() is evidence; a header is only a claim'
);

group( 'The operator has the last word' );

$GLOBALS['stub_filters']['delicat_builder_v9_request_is_secure'] = static function ( $v ) { return true; };
ok( 'a filter can vouch for an unusual front end', secure_with( array() ) );
$GLOBALS['stub_filters']['delicat_builder_v9_request_is_secure'] = static function ( $v ) { return false; };
ok( 'and can withdraw the proxy headers entirely', ! secure_with( array( 'HTTP_X_FORWARDED_PROTO' => 'https' ) ) );
$GLOBALS['stub_filters'] = array();

group( 'Every SSL gate now asks that question' );

require_once "$root/includes/class-delicat-builder-express-payment.php";

$gates = array(
	'delicat-builder-v9.php'                       => 'the express add-to-cart transport',
	'includes/class-delicat-builder-express-payment.php' => 'the payment credentials check',
	'includes/class-delicat-builder-transport.php'  => 'the https redirect boundary',
	'includes/class-delicat-builder-security.php'   => 'Strict-Transport-Security',
	'pro/class-dbp-security.php'                    => 'the CSP and Pro HSTS',
);
foreach ( $gates as $rel => $what ) {
	$src = (string) file_get_contents( "$root/$rel" );
	/* The helper's own is_ssl() is the evidence it is built on, not a gate. */
	$src = str_replace( $helper_src, '', $src );
	/* A gate is `! is_ssl()` or `if ( is_ssl() )` deciding something. Reporting
	 * is_ssl() on a diagnostics screen is a different matter and stays. */
	ok(
		"$what no longer gates on is_ssl() alone",
		! preg_match( '/(?:!\s*is_ssl\(\)|(?:if|\|\||&&)\s*\(?\s*is_ssl\(\)\s*[)&|])/', $src ),
		$rel
	);
}

/* ------------------------------------------------------------------ */
/* Part 2 - read() and PHP object injection                            */
/* ------------------------------------------------------------------ */

group( 'read() never hands a serialized object to unserialize()' );

/** Put a raw row in the fake table and read it back through the real code. */
function read_raw( string $raw ) {
	$GLOBALS['wpdb']->rows['probe'] = $raw;
	return Delicat_Builder_V9_Express_Payment::read( 'probe' );
}
function read_throws( string $raw ): bool {
	try { read_raw( $raw ); return false; } catch ( Throwable $e ) { return true; }
}

ok( 'a bare object is refused', read_throws( 'O:8:"stdClass":0:{}' ) );
ok(
	'an object hidden inside an array is refused',
	read_throws( 'a:1:{s:6:"status";O:8:"stdClass":0:{}}' ),
	'the payload is never at the start of the string'
);
ok(
	'an object nested two deep is refused',
	read_throws( 'a:1:{s:1:"a";a:1:{s:1:"b";O:8:"stdClass":0:{}}}' )
);
ok( 'and so is an enum/object at the end of a list', read_throws( 'a:2:{i:0;i:1;i:1;O:3:"Foo":0:{}}' ) );

$record = array( 'status' => 'pending', 'order_id' => 0, 'created' => 1700000000, 'hash' => 'abc', 'creating' => false );
ok( 'a normal record still round-trips', read_raw( serialize( $record ) ) === $record );
ok( 'a plain string still round-trips', 'dc0f4c9e-1111-4222-8333-444455556666' === read_raw( 'dc0f4c9e-1111-4222-8333-444455556666' ) );
ok( 'a numeric timestamp still round-trips', '1700000000.123456' === read_raw( '1700000000.123456' ) );

unset( $GLOBALS['wpdb']->rows['probe'] );
ok( 'a missing row is still null, not an exception', null === Delicat_Builder_V9_Express_Payment::read( 'probe' ) );

/* ------------------------------------------------------------------ */
/* Part 3 - reclaiming an abandoned payment guard                      */
/* ------------------------------------------------------------------ */

group( 'An abandoned guard is reclaimed only when nothing could have been charged' );

$reclaim = new ReflectionMethod( 'Delicat_Builder_V9_Express_Payment', 'reclaim_abandoned' );
$reclaim->setAccessible( true );

const UID   = 77;
const OWNER = 'aaaaaaaa-1111-4222-8333-444455556666';

/** Lay out one customer's guard plus the record behind it, then try to reclaim. */
function try_reclaim( $record, string $owner = OWNER ) {
	global $reclaim;
	$db = $GLOBALS['wpdb'];
	$db->rows = array( 'delicat_payment_active_' . UID => $owner );
	if ( null !== $record ) { $db->rows[ 'delicat_payment_' . UID . '_' . $owner ] = maybe_serialize( $record ); }
	return array( $reclaim->invoke( null, 'delicat_payment_active_' . UID, UID ), $db );
}

$old   = time() - 600;
$fresh = time() - 10;

list( $got, $db ) = try_reclaim( array( 'status' => 'pending', 'order_id' => 0, 'created' => $old, 'creating' => false ) );
ok( 'a guard 10 minutes old that never began creating an order is released', true === $got );
ok( 'and the row is actually gone', ! isset( $db->rows[ 'delicat_payment_active_' . UID ] ), 'otherwise the customer is still locked out' );

list( $got, $db ) = try_reclaim( array( 'status' => 'pending', 'order_id' => 0, 'created' => $fresh, 'creating' => false ) );
ok( 'a guard taken ten seconds ago is left alone', false === $got, 'that request is very likely still running' );
ok( 'and its row survives', isset( $db->rows[ 'delicat_payment_active_' . UID ] ) );

list( $got, $db ) = try_reclaim( array( 'status' => 'pending', 'order_id' => 0, 'created' => $old, 'creating' => true ) );
ok( 'order creation had begun: never reclaimed, however old', false === $got, 'a second charge is worse than a wait' );
ok( 'and its row survives', isset( $db->rows[ 'delicat_payment_active_' . UID ] ) );

list( $got, $db ) = try_reclaim( array( 'status' => 'submitted', 'order_id' => 4210, 'created' => $old, 'creating' => false ) );
ok( 'an order exists: never reclaimed', false === $got );
ok( 'and its row survives', isset( $db->rows[ 'delicat_payment_active_' . UID ] ) );

list( $got ) = try_reclaim( array( 'status' => 'pending', 'order_id' => 0, 'creating' => false ) );
ok( 'a record with no date is treated as brand new', false === $got, 'undated must mean untouchable, not reclaimable' );

list( $got ) = try_reclaim( null );
ok( 'a guard with no record behind it is left to the reconciliation screen', false === $got, 'nothing dates it' );

group( 'and never at the expense of another request' );

/*
 * The hard case: the guard is read, its record says "abandoned, release me" -
 * and only THEN does another request take the guard for itself. Reclaiming has
 * already decided to let go, so the delete must be the thing that notices.
 */
$db       = $GLOBALS['wpdb'];
$stolen   = 'bbbbbbbb-2222-4333-8444-555566667777';
$db->rows = array(
	'delicat_payment_active_' . UID        => OWNER,
	'delicat_payment_' . UID . '_' . OWNER => maybe_serialize( array( 'status' => 'pending', 'order_id' => 0, 'created' => $old, 'creating' => false ) ),
);
$db->after_read = static function ( $name, $db ) use ( $stolen ) {
	/* Someone else grabs the guard the instant its record has been read. */
	if ( 'delicat_payment_' . UID . '_' . OWNER === $name ) {
		$db->rows[ 'delicat_payment_active_' . UID ] = $stolen;
		$db->after_read = null;
	}
};
ok( 'a guard that changed owner mid-reclaim is not released', false === $reclaim->invoke( null, 'delicat_payment_active_' . UID, UID ) );
ok( 'and the new owner keeps it', $stolen === ( $db->rows[ 'delicat_payment_active_' . UID ] ?? null ), 'a blind DELETE here would hand one customer two live checkouts' );
$db->after_read = null;

/* A record that cannot be read at all must not become a reason to let go. */
$db->rows = array(
	'delicat_payment_active_' . UID       => OWNER,
	'delicat_payment_' . UID . '_' . OWNER => 'O:8:"stdClass":0:{}',
);
ok(
	'an unreadable record keeps the guard rather than releasing it',
	false === $reclaim->invoke( null, 'delicat_payment_active_' . UID, UID ),
	'read() throws on that row; failing open here would undo the injection guard'
);
ok( 'and the guard is still held', isset( $db->rows[ 'delicat_payment_active_' . UID ] ) );

group( 'begin() only reclaims after the guard refuses it' );

$payment = (string) file_get_contents( "$root/includes/class-delicat-builder-express-payment.php" );

/** The body of one method, by brace matching, so this does not depend on file order. */
function method_body( string $src, string $signature ): string {
	$at = strpos( $src, $signature );
	if ( false === $at ) { return ''; }
	$depth = 0;
	for ( $i = strpos( $src, '{', $at ); $i < strlen( $src ); $i++ ) {
		if ( '{' === $src[ $i ] ) { $depth++; }
		elseif ( '}' === $src[ $i ] ) { $depth--; if ( 0 === $depth ) { return substr( $src, $at, $i - $at + 1 ); } }
	}
	return '';
}

$begin = method_body( $payment, 'private static function begin(): void' );
ok( 'begin() was found to read', '' !== $begin );
ok(
	'it reclaims in exactly one place',
	1 === substr_count( $begin, 'self::reclaim_abandoned(' ),
	'a second, unguarded call is how a live guard gets taken away'
);
ok(
	'and only as a condition, never as a bare statement',
	1 === preg_match_all( '/!\s*self::reclaim_abandoned\(/', $begin ),
	'reclaiming first would hand the guard away while it is still doing its job'
);
ok(
	'after the guard has already refused it',
	strpos( $begin, 'self::reclaim_abandoned(' ) > strpos( $begin, "self::insert( \$keys['active']" ),
	'nothing may be reclaimed before the ordinary mutex has been tried'
);
ok(
	'and the guard is re-taken before proceeding',
	(bool) preg_match( '/reclaim_abandoned\([^)]*\)\s*\|\|\s*!\s*self::insert\(/', $begin ),
	'releasing without re-inserting would leave the customer unprotected'
);

group( 'A guard with no record can still be released by a human' );

ok(
	'the age gate applies only when there is a record to date',
	(bool) preg_match( '/if\s*\(\s*is_array\(\s*\$record\s*\)\s*\)\s*\{\s*(?:\/\*.*?\*\/\s*)?if\s*\(\s*time\(\)\s*-/s', $payment ),
	'time() - time() is zero, which is under five minutes for ever'
);

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
