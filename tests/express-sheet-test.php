<?php
/**
 * The express express sheet.
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
 * Run: php tests/express-sheet-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/support/stubs-wp.php';

/* Enough of the product page for active() to say yes. */
if ( ! class_exists( 'WooCommerce' ) ) { class WooCommerce {} }
class Delicat_Builder_V9_Native_Product {
    public static function express_enabled_for( $id ) { return true; }
}
function get_queried_object_id() { return 7; }

require_once __DIR__ . '/../delicat-builder-v9/includes/class-delicat-builder-express-sheet.php';

/** wallet_is_short() is private; the summary markup is what it drives. */
function summary_marks_short(): bool {
    ob_start();
    Delicat_Builder_V9_Express_Sheet::render_summary();
    $html = (string) ob_get_clean();
    return false !== strpos( $html, 'data-dxs-short="1"' );
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
    '' !== $checkout_branch && false !== strpos( $checkout_branch, 'class-delicat-builder-express-sheet.php' ),
    'without this the summary hooks never exist on the request that would fire them, and the sheet shows a bare checkout form'
);
ok(
    'the product branch still loads it too, to enqueue on the product page',
    '' !== $product_branch && false !== strpos( $product_branch, 'class-delicat-builder-express-sheet.php' )
);

/* =========================================================================
   Which requests get the cards
   ====================================================================== */
group( 'Only the sheet\'s own fetch gets the summary' );

unset( $_SERVER['HTTP_X_DELICAT_EXPRESS'] );
ok( 'an ordinary checkout visit is not a sheet request', ! Delicat_Builder_V9_Express_Sheet::is_sheet_request() );

$_SERVER['HTTP_X_DELICAT_EXPRESS'] = '1';
ok( 'the sheet\'s fetch is', Delicat_Builder_V9_Express_Sheet::is_sheet_request() );

$_SERVER['HTTP_X_DELICAT_EXPRESS'] = '';
ok( 'an empty header is not', ! Delicat_Builder_V9_Express_Sheet::is_sheet_request() );
unset( $_SERVER['HTTP_X_DELICAT_EXPRESS'] );

$GLOBALS['stub_is_product'] = false;
ok(
    'loading it on a checkout request renders no dialog there',
    ! Delicat_Builder_V9_Express_Sheet::active(),
    'the panel belongs to the product page; the checkout request only supplies the cards'
);

/* =========================================================================
   The button
   ====================================================================== */
group( 'The pay button carries the total' );

ok(
    'it says what is about to be charged',
    'Payer maintenant G7,400' === Delicat_Builder_V9_Express_Sheet::order_button_text( 'Commander' ),
    Delicat_Builder_V9_Express_Sheet::order_button_text( 'Commander' )
);

$GLOBALS['stub_cart_total'] = '';
ok(
    'and keeps WooCommerce\'s own words when there is no total to show',
    'Commander' === Delicat_Builder_V9_Express_Sheet::order_button_text( 'Commander' )
);
$GLOBALS['stub_cart_total'] = 'G7,400';

$was = $GLOBALS['stub_wc'];
$GLOBALS['stub_wc'] = new class { public $cart = null; };
ok(
    'a missing cart never blanks the button',
    'Commander' === Delicat_Builder_V9_Express_Sheet::order_button_text( 'Commander' )
);
$GLOBALS['stub_wc'] = $was;

/* =========================================================================
   The label the button wears while paying
   ====================================================================== */
group( 'The panel the product page carries' );

$GLOBALS['stub_is_product'] = true;

ob_start();
Delicat_Builder_V9_Express_Sheet::render();
$panel = (string) ob_get_clean();

ok( 'a dialog is printed', false !== strpos( $panel, '<dialog class="dxs" id="dxs-sheet"' ), substr( $panel, 0, 120 ) );
ok(
    'and it is empty until the customer asks for it',
    false === strpos( $panel, 'form' ) && false === strpos( $panel, 'nonce' ),
    'a product page is publicly cached; a checkout form carries a per-customer nonce'
);
ok( 'the loading state is there to replace', false !== strpos( $panel, 'data-dxs-loading' ) );
ok( 'and the trust line starts hidden', false !== strpos( $panel, 'data-dxs-trust hidden' ) );

/* The words the pay button wears while WooCommerce is taking the payment. CSS
   content cannot be translated, so PHP hands them over as a custom property. */
ok(
    'the submitting label rides along as a custom property',
    1 === preg_match( '/--dxs-paying:&quot;[^&]+&quot;/', $panel ),
    'CSS content cannot be translated; an English default must not leak through'
);
ok(
    'carrying the translated words',
    false !== strpos( $panel, 'Paiement en cours' ),
    $panel
);
ok(
    'and it survives being decoded out of the attribute',
    false !== strpos( html_entity_decode( $panel, ENT_QUOTES, 'UTF-8' ), '--dxs-paying:"Paiement en cours' )
);

/* =========================================================================
   What a product page is made to carry
   ====================================================================== */
group( 'The product page does not carry the express sheet' );

$GLOBALS['stub_is_product'] = true;
$GLOBALS['stub_enqueued']   = array();
$GLOBALS['stub_inline']     = array();
$GLOBALS['stub_registered'] = array();

Delicat_Builder_V9_Express_Sheet::enqueue();

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
    false === strpos( $joined, 'express-sheet.css' ),
    '24 KB on every product view, for a button most visits never press'
);
ok(
    'nor the sheet script',
    false === strpos( $joined, 'express-sheet.js' ),
    'another 20 KB of the same'
);
ok(
    'a loader goes instead, watching for the buy button',
    false !== strpos( $inline, 'delicat_native_buy_now' ),
    'something has to notice the tap and fetch the sheet'
);
ok(
    'and it knows where both assets live',
    false !== strpos( $inline, 'express-sheet.css' ) && false !== strpos( $inline, 'express-sheet.js' ),
    $inline
);
ok(
    'the loader is a fraction of what it replaces',
    strlen( $inline ) < 4096,
    strlen( $inline ) . ' bytes'
);

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
