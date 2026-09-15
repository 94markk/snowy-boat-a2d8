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

require_once __DIR__ . '/support/stubs-wp.php';

/* Enough of the product page for active() to say yes. */
if ( ! class_exists( 'WooCommerce' ) ) { class WooCommerce {} }
class Delicat_Builder_V9_Native_Product {
    public static function express_enabled_for( $id ) { return true; }
}
function get_queried_object_id() { return 7; }

require_once __DIR__ . '/../delicat-builder-v9/includes/class-delicat-builder-checkout-sheet.php';

/** wallet_is_short() is private; the summary markup is what it drives. */
function summary_marks_short(): bool {
    ob_start();
    Delicat_Builder_V9_Checkout_Sheet::render_summary();
    $html = (string) ob_get_clean();
    return false !== strpos( $html, 'data-dcs-short="1"' );
}

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

/* =========================================================================
   Telling the customer before they fill the form in
   ====================================================================== */
group( 'A wallet that will not cover the order' );

$GLOBALS['stub_cart_total'] = 7400.0;

$GLOBALS['stub_balance'] = 22002.0;
ok( 'a wallet with enough in it is not flagged', ! summary_marks_short() );

$GLOBALS['stub_balance'] = 450.0;
ok(
    'one that falls short is',
    summary_marks_short(),
    'the balance and the total are both known here; waiting for the gateway to say so costs the sale'
);

$GLOBALS['stub_balance'] = 7400.0;
ok( 'exactly enough is not short', ! summary_marks_short() );

$GLOBALS['stub_balance'] = null;   // the wallet plugin throws
ok(
    'an unreadable balance is not treated as a shortfall',
    ! summary_marks_short(),
    'warning someone their balance is too low when it cannot be read is worse than saying nothing'
);

$GLOBALS['stub_balance']   = 450.0;
$GLOBALS['stub_logged_in'] = false;
ok( 'and a guest is never told about a wallet they do not have', ! summary_marks_short() );
$GLOBALS['stub_logged_in'] = true;

/* =========================================================================
   What a product page is made to carry
   ====================================================================== */
group( 'The product page does not carry the checkout sheet' );

$GLOBALS['stub_is_product'] = true;
$GLOBALS['stub_enqueued']   = array();
$GLOBALS['stub_inline']     = array();
$GLOBALS['stub_registered'] = array();

Delicat_Builder_V9_Checkout_Sheet::enqueue();

$srcs   = array_map( static function ( $e ) { return $e['src']; }, $GLOBALS['stub_enqueued'] );
$inline = implode( "\n", $GLOBALS['stub_inline'] );
$joined = implode( ' ', $srcs );

ok(
    'active() agreed this is a page the sheet belongs on',
    ! empty( $GLOBALS['stub_registered'] ) || '' !== $inline,
    'nothing was enqueued at all, so the rest of this group proves nothing'
);
ok(
    'the sheet stylesheet is not shipped with it',
    false === strpos( $joined, 'checkout-sheet.css' ),
    '24 KB on every product view, for a button most visits never press'
);
ok(
    'nor the sheet script',
    false === strpos( $joined, 'checkout-sheet.js' ),
    'another 20 KB of the same'
);
ok(
    'a loader goes instead, watching for the buy button',
    false !== strpos( $inline, 'delicat_native_buy_now' ),
    'something has to notice the tap and fetch the sheet'
);
ok(
    'and it knows where both assets live',
    false !== strpos( $inline, 'checkout-sheet.css' ) && false !== strpos( $inline, 'checkout-sheet.js' ),
    $inline
);
ok(
    'the loader is a fraction of what it replaces',
    strlen( $inline ) < 4096,
    strlen( $inline ) . ' bytes'
);

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
