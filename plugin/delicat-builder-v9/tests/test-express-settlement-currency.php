<?php
/**
 * Guard: the express review sheet and the express pay request must price the
 * cart in the SAME currency.
 *
 * Express_Payment::fields() issues a cart hash from
 * woocommerce_review_order_before_submit, which fires while
 * wc-ajax=delicat_express_form renders checkout/form-checkout.php. That request
 * is not is_checkout() and does not define WOOCOMMERCE_CHECKOUT, so until PRO39
 * settle_in_base() returned false there and the sheet priced the cart in the
 * shopper's DISPLAY currency. Express_Payment::pay() then defines
 * WOOCOMMERCE_CHECKOUT one line before calling begin(), which re-hashes the cart
 * in BASE currency -- so hash_equals() could never match and every express
 * payment failed with "Le panier a changé. Actualisez pour vérifier le total."
 * Refreshing never helped: the mismatch is structural, not a race.
 *
 * On an HTG store with USD/CAD/EUR enabled, that is every shopper not browsing
 * in the base currency.
 *
 * Run: php tests/test-express-settlement-currency.php
 *
 * @package Delicat_Builder_V9
 */
define( 'ABSPATH', __DIR__ );
$PLUGIN = dirname( __DIR__ );

class WooCommerce {}
class WC_Product {
	private $p; public function __construct($p){ $this->p=(float)$p; }
	public function get_price($c='view'){ return $this->p; }
	public function get_meta($k,$s=false){ return ''; }
	public function get_parent_id(){ return 0; }
	public function get_id(){ return 1; }
}
class WC_Coupon {}

$OPTIONS = array(
	'woocommerce_currency' => 'HTG',
	/* Shipped defaults: enabled, settlement in base, USD/CAD/EUR enabled. */
);
function get_option( $k, $d = false ) { global $OPTIONS; return array_key_exists($k,$OPTIONS) ? $OPTIONS[$k] : $d; }
function add_option( $k, $v, $x='', $y=false ) { global $OPTIONS; $OPTIONS[$k] = $v; return true; }
function update_option( $k, $v, $a = null ) { global $OPTIONS; $OPTIONS[$k] = $v; return true; }
function sanitize_key( $k ) { return preg_replace('/[^a-z0-9_\-]/','', strtolower((string)$k)); }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, is_array($a)?$a:array() ); }
function apply_filters( $t, $v ) { return $v; }
function add_filter() {} function add_action() {} function add_shortcode() {}
function is_admin() { return false; }
function wp_doing_ajax() { return true; }
function wp_unslash( $v ) { return $v; }
function is_wc_endpoint_url( $e = '' ) { return false; }
function function_exists_shim() {}
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return $s; }
function get_post_meta( $id, $k, $s = false ) { return ''; }

require_once $PLUGIN . '/includes/class-delicat-builder-multi-currency.php';

function fresh(): Delicat_Builder_V9_Multi_Currency {
	$r = new ReflectionClass( 'Delicat_Builder_V9_Multi_Currency' );
	$p = $r->getProperty( 'instance' ); $p->setAccessible( true ); $p->setValue( null, null );
	$c = $r->getConstructor(); $c->setAccessible( true );
	$o = $r->newInstanceWithoutConstructor(); $c->invoke( $o );
	return $o;
}

/* Seed the shipped default currency table, then pick USD as the display currency. */
$mc = fresh();
$rm = new ReflectionMethod( $mc, 'default_currencies' ); $rm->setAccessible( true );
$OPTIONS['dmc_currencies'] = $rm->invoke( $mc );
$OPTIONS['dmc_settings']   = Delicat_Builder_V9_Multi_Currency::default_settings();

printf( "base currency          : %s\n", $mc->base_code() );
printf( "USD rate               : %s\n", $mc->rate( 'USD' ) );
printf( "settlement setting     : %s\n\n", $OPTIONS['dmc_settings']['settlement'] );

$product = new WC_Product( 5000.0 ); // 5 000 HTG

/* ---- Request A: wc-ajax=delicat_express_form (renders the review sheet) ---- */
$_GET = array( 'wc-ajax' => 'delicat_express_form' );
$mc = fresh(); $mc->set_current( 'USD' );
$a_settle = $mc->settle_in_base();
$a_cur    = $mc->current();
$a_price  = $mc->convert_product_price( $product->get_price( 'edit' ), $product );
printf( "A  wc-ajax=delicat_express_form   settle_in_base=%s  current=%s  line total=%.2f\n",
	var_export( $a_settle, true ), $a_cur, $a_price );

/* ---- Request B: wc-ajax=delicat_express_pay (Express_Payment::pay) ---- */
$_GET = array( 'wc-ajax' => 'delicat_express_pay' );
define( 'WOOCOMMERCE_CHECKOUT', true );   // express-payment.php line 233
$mc = fresh(); $mc->set_current( 'USD' );
$b_settle = $mc->settle_in_base();
$b_cur    = $mc->current();
$b_price  = $mc->convert_product_price( $product->get_price( 'edit' ), $product );
printf( "B  wc-ajax=delicat_express_pay    settle_in_base=%s  current=%s  line total=%.2f\n\n",
	var_export( $b_settle, true ), $b_cur, $b_price );

/* WC_Cart::get_cart_hash() = md5( json(cart_for_session) . get_total('edit') ) */
$hash_a = md5( json_encode( array( 'k' => array( 'line_total' => $a_price ) ) ) . $a_price );
$hash_b = md5( json_encode( array( 'k' => array( 'line_total' => $b_price ) ) ) . $b_price );
printf( "cart hash issued in A  : %s\n", $hash_a );
printf( "cart hash checked in B : %s\n", $hash_b );
printf( "hash_equals()          : %s\n", $hash_a === $hash_b ? 'MATCH -> payment proceeds'
	: 'MISMATCH -> begin() throws "Le panier a change. Actualisez pour verifier le total."' );

if ( $hash_a === $hash_b ) {
	echo "\n  ok    sheet and payment agree on currency; express checkout can complete\n";
	exit( 0 );
}
echo "\n  FAIL  express checkout is unusable in this currency\n";
exit( 1 );
