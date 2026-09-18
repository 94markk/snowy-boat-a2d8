<?php
/**
 * Guard: a pricing formula must never silently price a product at 0.00.
 *
 * compute_total() carries this comment:
 *
 *   "A malformed formula must never make the product free. Fall back to the
 *    native price plus field contributions and leave a trace for the admin."
 *
 * That protection is driven entirely by $eval['ok']. Until PRO38 an UNKNOWN
 * VARIABLE resolved to 0.0 and still reported ok => true, so the single most
 * likely authoring mistake -- naming a field that was renamed, deleted or
 * mistyped -- walked straight past the guard. `{base} * {qty_mult}` against a
 * field actually called `qty_multiplier` evaluated to `base * 0`, the product
 * sold for 0.00, and nothing was logged.
 *
 * Unknown FUNCTION calls were already rejected. This asserts the variable half
 * behaves the same, and that every legitimate formula still evaluates.
 *
 * Run: php tests/test-formula-eval.php
 *
 * @package Delicat_Builder_V9
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/modules/product-fields/class-dmc-calc-eval.php';

$E      = 'Delicat_Builder_V9_Product_Fields_Eval';
$passed = 0;
$failed = 0;

function dbv9_ok( string $label, bool $cond, string $detail = '' ): void {
	global $passed, $failed;
	if ( $cond ) {
		++$passed;
		printf( "  ok    %-52s %s\n", $label, $detail );
	} else {
		++$failed;
		printf( "  FAIL  %-52s %s\n", $label, $detail );
	}
}

/* The caller always seeds base, rate and every configured field id. */
$vars = array( 'base' => 100.0, 'rate' => 1.0, 'qty' => 3.0, 'extra' => 0.0 );

echo "\n=== an unknown variable must be REJECTED, not zeroed ===\n";
foreach ( array( '{base} * {qty_mult}', '{nope}', 'base + missing', '{base}+{typo}' ) as $formula ) {
	$r = $E::evaluate_checked( $formula, $vars );
	dbv9_ok(
		$formula,
		false === $r['ok'] && 0 === strpos( $r['error'], 'unknown_var:' ),
		sprintf( 'ok=%s error=%s', var_export( $r['ok'], true ), $r['error'] )
	);
}

echo "\n=== the error names the offending variable ===\n";
$r = $E::evaluate_checked( '{base} * {qty_mult}', $vars );
dbv9_ok( 'error identifies qty_mult', 'unknown_var:qty_mult' === $r['error'], $r['error'] );

echo "\n=== legitimate formulas still evaluate ===\n";
foreach ( array(
	'{base}'           => 100.0,
	'{base} * {qty}'   => 300.0,
	'base + extra'     => 100.0,
	'{base} * {rate}'  => 100.0,
	'round({base}/3)'  => 33.0,
	'max({base},{qty})' => 100.0,
) as $formula => $want ) {
	$r = $E::evaluate_checked( $formula, $vars );
	dbv9_ok( $formula, $r['ok'] && abs( $r['value'] - $want ) < 0.000001, sprintf( '= %s', var_export( $r['value'], true ) ) );
}

echo "\n=== unknown FUNCTION calls stay rejected ===\n";
foreach ( array( 'system({base})', 'foo({base})', 'eval({base})' ) as $formula ) {
	$r = $E::evaluate_checked( $formula, $vars );
	dbv9_ok( $formula, false === $r['ok'], 'error=' . $r['error'] );
}

echo "\n=== malformed input still fails safe ===\n";
foreach ( array( '', '2+', '2*', '*5', '((1+2)', str_repeat( '9', 5000 ) ) as $formula ) {
	$r = $E::evaluate_checked( $formula, $vars );
	dbv9_ok( '' === $formula ? '(empty)' : substr( $formula, 0, 44 ), false === $r['ok'], 'error=' . $r['error'] );
}

/*
 * PRO38. eval_rpn() used to return 0.0 for every arithmetic failure, so
 * evaluate_checked() reported ok => true with value 0.0 and compute_total()
 * took the SUCCESS branch: the product sold for 0.00, silently.
 *
 * `{base} / {qty}` is the realistic one -- the caller seeds an inactive field
 * with 0.0, so an ordinary configuration divides by zero.
 */
echo "\n=== arithmetic failure must not report a successful 0.00 ===\n";
foreach ( array(
	'{base} / {extra}'  => 'division by an inactive (0.0) field',
	'{base} % {extra}'  => 'modulo by zero',
	'1/0'               => 'literal division by zero',
	'sqrt(0 - 1)'       => 'sqrt of a negative',
) as $formula => $why ) {
	$r = $E::evaluate_checked( $formula, $vars );
	dbv9_ok( $formula, false === $r['ok'], sprintf( '%s -- ok=%s error=%s', $why, var_export( $r['ok'], true ), $r['error'] ) );
}

echo "\n=== a formula that legitimately equals zero must still succeed ===\n";
foreach ( array( '{base} - {base}', '{base} * 0', '{extra}', '0' ) as $formula ) {
	$r = $E::evaluate_checked( $formula, $vars );
	dbv9_ok( $formula, true === $r['ok'] && 0.0 === $r['value'], sprintf( 'ok=%s value=%s', var_export( $r['ok'], true ), var_export( $r['value'], true ) ) );
}

echo "\n=== an inactive field is seeded 0.0 and must still resolve ===\n";
$r = $E::evaluate_checked( '{base} + {extra}', $vars );
dbv9_ok( '{base} + {extra} with extra=0.0', $r['ok'] && abs( $r['value'] - 100.0 ) < 0.000001, sprintf( '= %s', var_export( $r['value'], true ) ) );

echo "\n---------------------------------------------\n";
printf( "passed %d / failed %d\n", $passed, $failed );
exit( $failed > 0 ? 1 : 0 );
