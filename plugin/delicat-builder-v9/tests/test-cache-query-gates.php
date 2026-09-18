<?php
/**
 * Guard: EVERY cache gate must use the pro.30 query allowlist, not "any query
 * string is private".
 *
 * pro.30 replaced `! empty( $_GET )` with query_is_cache_safe() in
 * Security::public_cache_allowed(), and its own comment explains why:
 *
 *   "every ad-tagged landing (utm_*, fbclid, gclid, msclkid) and every archive
 *    page-2 or sort change was rebuilt in PHP, which on an ad-driven store is
 *    most of the traffic and exactly the navigation that felt slow."
 *
 * But three other gates kept the old rule and silently vetoed that fix:
 *
 *   Server_Engine::prepare_cache_policy()   -- defines DONOTCACHEPAGE, which
 *                                              kills the page cache, the fragment
 *                                              cache and LiteSpeed for the whole
 *                                              request
 *   Server_Engine::fragment_cache_allowed() -- skips the rendered-body cache
 *   Security::private_cache_allowed()       -- drops signed-in private caching
 *
 * So pro.30 shipped with no effect on the traffic it was written for. The
 * existing test-cache-gate.php never caught it because it only ever exercised
 * public_cache_allowed().
 *
 * This asserts the shared predicate behaves, and -- structurally -- that no gate
 * has drifted back to the old rule.
 *
 * Run: php tests/test-cache-query-gates.php
 *
 * @package Delicat_Builder_V9
 */

define( 'ABSPATH', '/tmp/' );
function sanitize_key( $k ) { $k = strtolower( (string) $k ); return preg_replace( '/[^a-z0-9_\-]/', '', $k ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function wp_unslash( $v ) { return $v; }
function apply_filters( $t, $v ) { return $v; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_user_logged_in() { return $GLOBALS['T_LOGGED'] ?? false; }
function get_option( $k, $d = false ) { return $GLOBALS['T_OPTIONS'][ $k ] ?? $d; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_salt( $s = '' ) { return 'saltsalt'; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t ) { return true; }
function __( $s, $d = null ) { return $s; }
function is_cart() { return false; }
function is_checkout() { return false; }
function is_account_page() { return false; }
function is_wc_endpoint_url( $e = '' ) { return false; }
function is_admin_bar_showing() { return false; }
function current_user_can( $c ) { return false; }
function do_action() {}
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function add_action( $t, $c, $p = 10, $a = 1 ) { return true; }
function remove_action( $t, $c, $p = 10 ) { return true; }
function __return_false() { return false; }
function __return_empty_string() { return ''; }
function is_ssl() { return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function esc_url_raw( $u ) { return $u; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function wp_create_nonce( $a = '' ) { return 'n'; }
function wp_verify_nonce( $n, $a = '' ) { return true; }
function absint( $v ) { return abs( (int) $v ); }

require __DIR__ . '/../includes/class-delicat-builder-security.php';

$S                    = 'Delicat_Builder_V9_Security';
$GLOBALS['T_OPTIONS'] = array( 'woocommerce_currency' => 'HTG' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';

$pass = 0;
$fail = 0;
function check( string $label, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) {
		++$pass;
		printf( "  ok    %-56s => %s\n", $label, var_export( $got, true ) );
	} else {
		++$fail;
		printf( "  FAIL  %-56s => got %s want %s\n", $label, var_export( $got, true ), var_export( $want, true ) );
	}
}

echo "\n=== the shared allowlist: real traffic must stay cacheable ===\n";
foreach ( array(
	'no query at all'            => array( array(), true ),
	'?fbclid= (Facebook ad)'     => array( array( 'fbclid' => 'IwAR2xQ9' ), true ),
	'?gclid= (Google ad)'        => array( array( 'gclid' => 'Cj0KCQ' ), true ),
	'?utm_source+utm_medium'     => array( array( 'utm_source' => 'fb', 'utm_medium' => 'cpc' ), true ),
	'?igshid= (Instagram)'       => array( array( 'igshid' => 'abc' ), true ),
	'?paged=2 (catalog page 2)'  => array( array( 'paged' => '2' ), true ),
	'?orderby=price'             => array( array( 'orderby' => 'price' ), true ),
	'?filter_size= (layered nav)'=> array( array( 'filter_size' => 'l' ), true ),
	'?min_price+max_price'       => array( array( 'min_price' => '10', 'max_price' => '99' ), true ),
	'?s= (search, uncacheable)'  => array( array( 's' => 'phone' ), false ),
	'?add-to-cart= (mutating)'   => array( array( 'add-to-cart' => '12' ), false ),
	'unknown arbitrary key'      => array( array( 'zzz' => '1' ), false ),
	'safe key + unknown key'     => array( array( 'fbclid' => 'x', 'zzz' => '1' ), false ),
) as $label => $case ) {
	list( $query, $want ) = $case;
	$_GET = $query;
	check( $label, $S::query_is_cache_safe(), $want );
}

echo "\n=== signed-in private caching survives a tracked link ===\n";
$GLOBALS['T_LOGGED'] = true;
$_GET                = array( 'fbclid' => 'IwAR2xQ9' );
check( 'private_cache_allowed() on ?fbclid', $S::private_cache_allowed(), true );
$_GET = array( 'zzz' => '1' );
check( 'private_cache_allowed() on unknown key', $S::private_cache_allowed(), false );
$GLOBALS['T_LOGGED'] = false;

/*
 * Structural guard. These three gates cannot be unit-called cheaply --
 * prepare_cache_policy() defines a constant, and two are private -- but the
 * regression they carried is textual and easy to assert against: none of them
 * may decide privacy from `! empty( $_GET )` again.
 */
echo "\n=== no gate may drift back to \"any query string is private\" ===\n";
foreach ( array(
	'includes/class-delicat-builder-server-engine.php',
	'includes/class-delicat-builder-security.php',
) as $relative ) {
	$source = (string) file_get_contents( dirname( __DIR__ ) . '/' . $relative );
	/* Strip block and line comments so the historical notes do not trip this. */
	$code  = preg_replace( '#/\*.*?\*/#s', '', $source );
	$code  = preg_replace( '#//[^\n]*#', '', (string) $code );
	$hits  = preg_match_all( '/!\s*empty\(\s*\$_GET\s*\)/', (string) $code );
	check( basename( $relative ) . ' has no `! empty($_GET)` gate', 0 === $hits, true );
}

echo "\n---------------------------------------------\n";
printf( "passed %d / failed %d\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
