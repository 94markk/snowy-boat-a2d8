<?php
/**
 * Guard: a non-default currency may be CACHED PER CURRENCY, never cached wrong.
 *
 * Until PRO44 any shopper on a non-default currency bypassed the page cache
 * entirely. Correct, but on a store with USD/CAD/EUR enabled that is a large
 * share of traffic paying a full PHP render on every page. LiteSpeed can key its
 * cache on a cookie value -- the plugin already does this for
 * woocommerce_items_in_cart -- which gives one copy per currency instead of none.
 *
 * The failure mode to prevent is serving one currency's prices under another
 * currency's key. display_choice() resolves the Woo SESSION before the cookie,
 * and geolocation can pick a currency with no cookie at all, so the cookie is
 * not automatically the truth. cache_vary_safe() therefore requires that the
 * BROWSER sent the cookie, that it is valid and non-default, and that it equals
 * what is actually being rendered. Anything else falls back to the old bypass.
 *
 * Run: php tests/test-currency-cache-vary.php
 *
 * @package Delicat_Builder_V9
 */

define( 'ABSPATH', __DIR__ );
$PLUGIN = dirname( __DIR__ );

class WooCommerce {}
class WC_Product {}
class WC_Coupon {}

$OPTIONS = array( 'woocommerce_currency' => 'HTG' );
function get_option( $k, $d = false ) { global $OPTIONS; return array_key_exists( $k, $OPTIONS ) ? $OPTIONS[ $k ] : $d; }
function add_option( $k, $v, $x = '', $y = false ) { global $OPTIONS; $OPTIONS[ $k ] = $v; return true; }
function update_option( $k, $v, $a = null ) { global $OPTIONS; $OPTIONS[ $k ] = $v; return true; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, is_array( $a ) ? $a : array() ); }
function apply_filters( $t, $v ) { global $FILTERS; return array_key_exists( $t, $FILTERS ) ? $FILTERS[ $t ] : $v; }
function add_filter() {} function add_action() {} function add_shortcode() {}
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_unslash( $v ) { return $v; }
function is_wc_endpoint_url( $e = '' ) { return false; }
function is_checkout() { return false; }
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return $s; }
function get_post_meta( $i, $k, $s = false ) { return ''; }
function wp_next_scheduled() { return time() + 3600; }
function wp_schedule_event() { return true; }
function is_ssl() { return true; }

$FILTERS = array();

/** WooCommerce session stub: null means "no session value". */
class DBV9_Session { public $v = null; public function get( $k ) { return $this->v; } public function set( $k, $x ) { $this->v = $x; } }
class DBV9_WC { public $session; public function __construct() { $this->session = new DBV9_Session(); } }
function WC() { global $WCOBJ; return $WCOBJ; }
$WCOBJ = new DBV9_WC();

require_once $PLUGIN . '/includes/class-delicat-builder-multi-currency.php';

/** Fresh instance so the constructor re-reads $_COOKIE. */
function fresh(): Delicat_Builder_V9_Multi_Currency {
	$r = new ReflectionClass( 'Delicat_Builder_V9_Multi_Currency' );
	$p = $r->getProperty( 'instance' ); $p->setAccessible( true ); $p->setValue( null, null );
	$c = $r->getConstructor(); $c->setAccessible( true );
	$o = $r->newInstanceWithoutConstructor(); $c->invoke( $o );
	return $o;
}

$seed = fresh();
$rm   = new ReflectionMethod( $seed, 'default_currencies' ); $rm->setAccessible( true );
$OPTIONS['dmc_currencies'] = $rm->invoke( $seed );
$OPTIONS['dmc_settings']   = Delicat_Builder_V9_Multi_Currency::default_settings();

$pass = 0; $fail = 0;
function check( string $label, bool $got, bool $want, string $detail = '' ): void {
	global $pass, $fail;
	if ( $got === $want ) { ++$pass; printf( "  ok    %-50s %s %s\n", $label, $got ? 'VARY  ' : 'bypass', $detail ); }
	else { ++$fail; printf( "  FAIL  %-50s got %s want %s %s\n", $label, $got ? 'VARY' : 'bypass', $want ? 'VARY' : 'bypass', $detail ); }
}

/** @return array{0:bool,1:bool} cache_vary_safe(), public_cache_variant_required() */
function probe( ?string $cookie, ?string $session = null, bool $litespeed = true ): array {
	global $WCOBJ;
	$_COOKIE = array();
	if ( null !== $cookie ) { $_COOKIE['dmc_currency'] = $cookie; }
	$WCOBJ->session->v = $session;
	$mc = fresh();
	$r  = new ReflectionProperty( $mc, 'incoming_cookie' ); $r->setAccessible( true );
	if ( ! $litespeed ) {
		/* Simulate "LiteSpeed absent" by blanking the snapshot the gate needs. */
		$r->setValue( $mc, '' );
	}
	return array( $mc->cache_vary_safe(), $mc->public_cache_variant_required() );
}

if ( ! defined( 'LSCWP_V' ) ) { define( 'LSCWP_V', '6.5' ); }

echo "\n=== base currency HTG; USD/CAD/EUR enabled ===\n";

list( $v ) = probe( 'USD' );
check( 'USD cookie, no session conflict', $v, true, '-> one cached copy per currency' );

list( $v ) = probe( 'EUR' );
check( 'EUR cookie, no session conflict', $v, true, '' );

list( $v ) = probe( null );
check( 'no cookie at all (guest on base currency)', $v, false, '-> nothing to key on' );

list( $v ) = probe( 'HTG' );
check( 'cookie holds the DEFAULT currency', $v, false, '-> no useful variant' );

list( $v ) = probe( 'ZZZ' );
check( 'cookie holds an invalid code', $v, false, '' );

echo "\n=== the dangerous case: cookie and session disagree ===\n";
list( $v ) = probe( 'USD', 'EUR' );
check( 'cookie USD but session EUR', $v, false, '-> would cache EUR prices under a USD key' );

list( $v ) = probe( 'USD', 'USD' );
check( 'cookie USD and session USD agree', $v, true, '' );

echo "\n=== the vary must not be taken without a cache that can key on it ===\n";
list( $v ) = probe( 'USD', null, false );
check( 'no usable incoming cookie snapshot', $v, false, '' );

$FILTERS['delicat_builder_v9_currency_cache_vary'] = false;
list( $v ) = probe( 'USD' );
check( 'site switched the vary off by filter', $v, false, '' );
unset( $FILTERS['delicat_builder_v9_currency_cache_vary'] );

echo "\n=== the bypass decision must follow the vary decision ===\n";
list( $v, $bypass ) = probe( 'USD' );
check( 'USD: vary on => bypass off', ! $bypass, true, '(public_cache_variant_required=false)' );

list( $v, $bypass ) = probe( 'USD', 'EUR' );
check( 'conflict: vary off => bypass ON', $bypass, true, '(public_cache_variant_required=true)' );

list( $v, $bypass ) = probe( 'HTG' );
check( 'default currency: no variant needed', ! $bypass, true, '' );

echo "\n---------------------------------------------\n";
printf( "passed %d / failed %d\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
