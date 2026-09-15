<?php
/**
 * The v14 express checkout is wired to everything it needs.
 *
 * This module was retired once and brought back. It does not stand alone: it
 * needs helpers on Purchase_Native, a boot call from Native_Product, an AJAX
 * endpoint that serves WooCommerce's real checkout form, and a transport in the
 * bootstrap. Restore four files and miss any one of those and the popup simply
 * never opens - with no error anywhere, because every piece fails quietly by
 * design.
 *
 * So each connection is asserted rather than assumed.
 *
 * Run: php tests/express-checkout-wiring-test.php
 */
$root = __DIR__ . '/../delicat-builder-v9';

$pass = 0; $fail = 0;
function ok( string $what, bool $cond, string $detail = '' ): void {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  ok    $what\n"; }
    else { $fail++; echo "  FAIL  $what" . ( $detail ? "  -- $detail" : '' ) . "\n"; }
}
function group( string $n ): void { echo "\n$n\n" . str_repeat( '-', strlen( $n ) ) . "\n"; }
function body( string $f ): string { return (string) file_get_contents( $f ); }

group( 'The four files are present' );

foreach ( array(
    'includes/class-delicat-builder-express-checkout.php',
    'includes/class-delicat-builder-express-payment.php',
    'assets/js/express-checkout.js',
    'assets/css/express-checkout.css',
) as $rel ) {
    ok( $rel, is_file( "$root/$rel" ) && filesize( "$root/$rel" ) > 500, 'missing or truncated' );
}

group( 'Purchase_Native still carries what the module calls' );

$pn = body( "$root/includes/class-delicat-builder-purchase-native.php" );
foreach ( array( 'mark_express', 'express_active', 'express_supported', 'express_presentation_css', 'render_express_form' ) as $method ) {
    ok(
        "Purchase_Native::$method()",
        false !== strpos( $pn, "function $method(" ),
        'the module calls it; without it the sheet renders empty or fatals'
    );
}
ok( 'and the flag those set', false !== strpos( $pn, '$express' ) );

group( 'Something actually boots it' );

$np = body( "$root/includes/class-delicat-builder-native-product.php" );
ok(
    'Native_Product::prepare_express() exists',
    false !== strpos( $np, 'function prepare_express(' )
);
ok(
    'it is hooked, not just defined',
    false !== strpos( $np, "add_action( 'wp', array( __CLASS__, 'prepare_express' )" ),
    'a boot method nothing calls is a popup that never appears'
);
ok(
    'and it hands the product to the module',
    false !== strpos( $np, 'Delicat_Builder_V9_Express_Checkout::boot_for_product(' )
);
ok(
    'exactly once',
    1 === substr_count( $np, 'function prepare_express(' ),
    'two declarations is a fatal on every product page'
);

group( 'The transport the sheet talks to' );

$boot = body( "$root/delicat-builder-v9.php" );
ok(
    'the express request helper is defined',
    false !== strpos( $boot, 'function delicat_builder_v9_express_request(' )
);
ok(
    'wc-ajax=delicat_express_form serves WooCommerce\'s real form',
    false !== strpos( $boot, "'wc_ajax_delicat_express_form'" ),
    'this is where the fields, gateways, terms and process-checkout nonce come from'
);
ok(
    'the payment module is required',
    false !== strpos( $boot, "require_once __DIR__ . '/includes/class-delicat-builder-express-payment.php';" )
);
ok(
    'and its pay endpoint is let through the wc-ajax gate',
    false !== strpos( $boot, "'delicat_express_pay'" ),
    'without this the module loads on every request except the one that pays'
);

group( 'The transport refuses anything it should' );

ok(
    'an express add-to-cart needs a POST',
    false !== strpos( $boot, "'POST' !== strtoupper" )
);
ok(
    'and a same-origin fetch',
    false !== strpos( $boot, 'HTTP_SEC_FETCH_SITE' )
);
ok(
    'and HTTPS, a signed-in customer and a valid nonce',
    false !== strpos( $boot, 'is_ssl()' )
        && false !== strpos( $boot, 'is_user_logged_in()' )
        && false !== strpos( $boot, "wp_verify_nonce( \$nonce, 'delicat_express_add' )" ),
    'these run before WooCommerce mutates the cart'
);

group( 'The retired sheet is gone' );

$leftovers = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
    if ( $f->isFile() && false !== strpos( $f->getFilename(), 'express-sheet' ) ) {
        $leftovers[] = $f->getFilename();
    }
    if ( $f->isFile() && false !== strpos( $f->getFilename(), 'checkout-sheet' ) ) {
        $leftovers[] = $f->getFilename();
    }
}
ok(
    'no file of the replaced popup survives',
    empty( $leftovers ),
    implode( ', ', $leftovers )
);
ok(
    'and nothing references its class',
    false === strpos( $boot, 'Delicat_Builder_V9_Express_Sheet' )
        && false === strpos( $boot, 'Delicat_Builder_V9_Checkout_Sheet' )
);

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
