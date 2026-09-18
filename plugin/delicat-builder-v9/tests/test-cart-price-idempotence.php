<?php
/**
 * Guard: apply_cart_prices() must be idempotent.
 *
 * WooCommerce fires woocommerce_before_calculate_totals more than once per
 * request -- the cart page, update_order_review at checkout, any shipping
 * recalculation, and Purchase_Native::render_express_form() which calls
 * WC()->cart->calculate_totals() directly. $cart_item['data'] is rebuilt from
 * the session only ONCE per request, so it carries whatever set_price() last
 * wrote.
 *
 * compute_total() reads its {base} from the product object it is handed, and
 * apply_cart_prices() handed it that same mutated object. Every extra pass
 * therefore treated the already marked-up price as the base and applied the
 * markup again: a 250 base with a 50 gift-wrap option charged 300, then 350,
 * then 400. A percentage or a {base}*{qty} formula compounded geometrically.
 * Shoppers were overcharged, silently, and nothing in the cart said why.
 *
 * Run: php tests/test-cart-price-idempotence.php
 *
 * @package Delicat_Builder_V9
 */
define( 'ABSPATH', __DIR__ );
$PLUGIN = dirname( __DIR__ );

$GLOBALS['meta'] = array();
$GLOBALS['dbv9_failed'] = 0;
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function __( $s, $d = '' ) { return $s; }
function sanitize_text_field( $s ) { return is_scalar($s) ? trim( (string) $s ) : ''; }
function sanitize_textarea_field( $s ) { return (string) $s; }
function sanitize_email( $s ) { return (string) $s; }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function esc_url_raw( $s ) { return (string) $s; }
function wp_http_validate_url( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_URL ); }
function wp_json_encode( $v ) { return json_encode( $v ); }

class WC_Product {
	private $price; private $id; private $parent;
	public function __construct( $id, $price, $parent = 0 ) { $this->id = $id; $this->price = (float) $price; $this->parent = $parent; }
	/* WC_Data::get_prop() applies woocommerce_product_get_price only for
	   context 'view'; 'edit' returns the raw prop -- whatever set_price() wrote. */
	public function get_price( $context = 'view' ) { return $this->price; }
	public function set_price( $p ) { $this->price = (float) $p; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent; }
}
class WC_Cart { public $items = array(); public function get_cart() { return $this->items; } }

require_once $PLUGIN . '/modules/product-fields/class-dmc-calc-eval.php';
require_once $PLUGIN . '/modules/product-fields/class-dmc-calculator.php';

$calc = new Delicat_Builder_V9_Product_Fields_Calculator();

function scenario( $label, $cfg, $raw_values, $base, $passes = 3 ) {
	global $calc;
	$GLOBALS['meta'][ 77 ] = array( '_dmc_calc' => $cfg );
	$product = new WC_Product( 77, $base );

	$san   = $calc->sanitize_values( $cfg, $raw_values );
	$total = $calc->compute_total( $product, $cfg, $san['values'], $san['active'] );

	$cart  = new WC_Cart();
	$cart->items['k'] = array(
		'product_id' => 77,
		'data'       => $product,
		'dmc_calc'   => array( 'values' => $san['values'], 'active' => $san['active'], 'total' => $total ),
	);

	printf( "\n--- %s\n    base=%.2f  add-to-cart total=%.2f\n", $label, $base, $total );
	for ( $i = 1; $i <= $passes; $i++ ) {
		$calc->apply_cart_prices( $cart );
		printf( "    woocommerce_before_calculate_totals fire #%d -> unit price charged = %.2f\n", $i, $product->get_price( 'edit' ) );
	}
	return $product->get_price( 'edit' );
}

/* ------------------------------------------------------------------ */
/* 1. Auto-sum mode: 250 HTG base + one 50 HTG option.                 */
$cfg1 = array(
	'enabled' => 1,
	'fields'  => array(
		array( 'id' => 'wrap', 'type' => 'toggle', 'label' => 'Gift wrap', 'price' => 50 ),
	),
);
$a = scenario( 'auto-sum: base 250 + 50 gift wrap (correct = 300.00)', $cfg1, array( 'wrap' => 'yes' ), 250.0 );

/* 2. Percent field: +20% of base. */
$cfg2 = array(
	'enabled' => 1,
	'fields'  => array(
		array( 'id' => 'rush', 'type' => 'toggle', 'label' => 'Rush', 'price' => 20, 'price_type' => 'percent' ),
	),
);
$b = scenario( 'percent: base 250 + 20% (correct = 300.00)', $cfg2, array( 'rush' => 'yes' ), 250.0 );

/* 3. Formula mode: {base} * {qty}, qty = 3. */
$cfg3 = array(
	'enabled' => 1,
	'formula' => '{base} * {qty}',
	'fields'  => array(
		array( 'id' => 'qty', 'type' => 'number', 'label' => 'Quantity', 'price' => 0, 'min' => 1, 'max' => 100 ),
	),
);
$c = scenario( 'formula {base}*{qty}, qty=3, base 250 (correct = 750.00)', $cfg3, array( 'qty' => '3' ), 250.0 );

echo "\n";
function dbv9_assert_price( string $label, float $got, float $want ): void {
	if ( abs( $got - $want ) < 0.005 ) {
		printf( "  ok    %-38s %.2f after 3 fires\n", $label, $got );
		return;
	}
	++$GLOBALS['dbv9_failed'];
	printf( "  FAIL  %-38s %.2f after 3 fires (expected %.2f) -- OVERCHARGE\n", $label, $got, $want );
}

echo "\n=== price must not compound across repeated fires ===\n";
dbv9_assert_price( 'auto-sum  (250 + 50 gift wrap)', $a, 300.0 );
dbv9_assert_price( 'percent   (250 + 20%)', $b, 300.0 );
dbv9_assert_price( 'formula   ({base}*{qty}, qty 3)', $c, 750.0 );

echo "\n---------------------------------------------\n";
printf( "failed %d\n", $GLOBALS['dbv9_failed'] );


exit( $GLOBALS['dbv9_failed'] > 0 ? 1 : 0 );
