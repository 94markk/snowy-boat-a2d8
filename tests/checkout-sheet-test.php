<?php
/**
 * The express checkout sheet.
 *
 * The sheet fetches the checkout page and lifts WooCommerce's form out of the
 * reply. The order card, the wallet card and the button's total are rendered
 * ONTO that reply, by hooks this class registers — so the class has to be
 * loaded on the request that renders them, which is a checkout request, not the
 * product request that shows the button.
 *
 * It was loaded only on product requests. The sheet therefore opened on a bare
 * checkout form: no order card, no wallet card, and "Commander" instead of the
 * total. The first case below is that bug.
 *
 * Run: php tests/checkout-sheet-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

/* ---- Just enough WordPress and WooCommerce ---- */

function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_attr__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return (string) $u; }
function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); }
function wp_kses_post( $t ) { return (string) $t; }
function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); }
function is_admin() { return false; }
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function is_user_logged_in() { return false; }
function get_current_user_id() { return 0; }
function absint( $n ) { return abs( (int) $n ); }
function home_url( $p = '/' ) { return 'https://shop.test' . $p; }
function get_page_by_path() { return null; }
function wc_get_page_permalink() { return 'https://shop.test/commander/'; }
function wp_enqueue_script() {}
function wp_enqueue_style() {}
function wp_localize_script() {}
function wp_create_nonce() { return 'nonce'; }
function admin_url( $p = '' ) { return 'https://shop.test/wp-admin/' . $p; }
function wc_get_account_endpoint_url() { return ''; }
function get_option( $k, $d = false ) { return $d; }
function is_product() { return $GLOBALS['stub_is_product'] ?? false; }
function wc_price( $n ) { return 'G' . number_format( (float) $n ); }
function wc_get_formatted_cart_item_data() { return ''; }

class WC_Product {}

$GLOBALS['stub_cart_total'] = 'G7,400';
class Stub_Cart {
    public function is_empty() { return false; }
    public function get_cart() { return array(); }
    public function get_total( $c = 'view' ) { return $GLOBALS['stub_cart_total']; }
}
class Stub_WC { public $cart; public $session = null;
    public function __construct() { $this->cart = new Stub_Cart(); }
    public function payment_gateways() { return null; } }
function WC() { return $GLOBALS['stub_wc']; }
$GLOBALS['stub_wc'] = new Stub_WC();

require_once __DIR__ . '/../delicat-builder-v9/includes/class-delicat-builder-checkout-sheet.php';

/* ---- harness ---- */
$pass = 0; $fail = 0;
function ok( $what, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  ok    $what\n"; }
    else { $fail++; echo "  FAIL  $what" . ( $detail ? "  -- $detail" : '' ) . "\n"; }
}
function group( $n ) { echo "\n$n\n" . str_repeat( '-', strlen( $n ) ) . "\n"; }

/* =========================================================================
   The bug the merchant reported: a sheet with no summary in it
   ====================================================================== */
group( 'The class is loaded on the request that renders the cards' );

$router = (string) file_get_contents( __DIR__ . '/../delicat-builder-v9/includes/class-delicat-builder-runtime-router.php' );

/** Body of the first `if` whose condition mentions $needle, by brace matching. */
function branch_body( string $src, string $needle ): string {
    $at = strpos( $src, $needle );
    if ( false === $at ) { return ''; }
    $open = strpos( $src, '{', $at );
    if ( false === $open ) { return ''; }
    $depth = 0;
    for ( $i = $open; $i < strlen( $src ); $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        elseif ( '}' === $src[ $i ] ) { $depth--; if ( 0 === $depth ) { return substr( $src, $open, $i - $open ); } }
    }
    return '';
}

$checkout_branch = branch_body( $router, 'is_cart()) || (function_exists(\'is_checkout\')' );
$product_branch  = branch_body( $router, 'if ( function_exists(\'is_product\') && is_product() )' );

ok(
    'the checkout branch loads the sheet',
    '' !== $checkout_branch && false !== strpos( $checkout_branch, 'class-delicat-builder-checkout-sheet.php' ),
    'without this the summary hooks never exist on the request that would fire them, and the sheet shows a bare checkout form'
);
ok(
    'the product branch still loads it too, to enqueue on the product page',
    '' !== $product_branch && false !== strpos( $product_branch, 'class-delicat-builder-checkout-sheet.php' )
);

/* =========================================================================
   Which requests get the cards
   ====================================================================== */
group( 'Only the sheet\'s own fetch gets the summary' );

unset( $_SERVER['HTTP_X_DELICAT_SHEET'] );
ok( 'an ordinary checkout visit is not a sheet request', ! Delicat_Builder_V9_Checkout_Sheet::is_sheet_request() );

$_SERVER['HTTP_X_DELICAT_SHEET'] = '1';
ok( 'the sheet\'s fetch is', Delicat_Builder_V9_Checkout_Sheet::is_sheet_request() );

$_SERVER['HTTP_X_DELICAT_SHEET'] = '';
ok( 'an empty header is not', ! Delicat_Builder_V9_Checkout_Sheet::is_sheet_request() );
unset( $_SERVER['HTTP_X_DELICAT_SHEET'] );

$GLOBALS['stub_is_product'] = false;
ok(
    'loading it on a checkout request renders no dialog there',
    ! Delicat_Builder_V9_Checkout_Sheet::active(),
    'the panel belongs to the product page; the checkout request only supplies the cards'
);

/* =========================================================================
   The button
   ====================================================================== */
group( 'The pay button carries the total' );

ok(
    'it says what is about to be charged',
    'Payer maintenant G7,400' === Delicat_Builder_V9_Checkout_Sheet::order_button_text( 'Commander' ),
    Delicat_Builder_V9_Checkout_Sheet::order_button_text( 'Commander' )
);

$GLOBALS['stub_cart_total'] = '';
ok(
    'and keeps WooCommerce\'s own words when there is no total to show',
    'Commander' === Delicat_Builder_V9_Checkout_Sheet::order_button_text( 'Commander' )
);
$GLOBALS['stub_cart_total'] = 'G7,400';

$was = $GLOBALS['stub_wc'];
$GLOBALS['stub_wc'] = new class { public $cart = null; };
ok(
    'a missing cart never blanks the button',
    'Commander' === Delicat_Builder_V9_Checkout_Sheet::order_button_text( 'Commander' )
);
$GLOBALS['stub_wc'] = $was;

/* =========================================================================
   The label the button wears while paying
   ====================================================================== */
group( 'The submitting label reaches CSS intact' );

$style = Delicat_Builder_V9_Checkout_Sheet::paying_label_style();
ok( 'it is a custom property CSS can read', 1 === preg_match( '/^--dcs-paying:"[^"]+"$/', $style ), $style );
ok( 'carrying the translated words', false !== strpos( $style, 'Paiement en cours' ), $style );
ok(
    'and it survives being escaped into the attribute',
    false !== strpos( html_entity_decode( esc_attr( $style ), ENT_QUOTES, 'UTF-8' ), '--dcs-paying:"Paiement en cours' ),
    esc_attr( $style )
);

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
