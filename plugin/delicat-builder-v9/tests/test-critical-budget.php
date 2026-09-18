<?php
/**
 * Guard: every route's critical stylesheets must fit the inline budget.
 *
 * Performance::critical_css() appends whole files and skips any that would
 * cross the budget. A budget slightly too small therefore does not trim the
 * tail -- it drops an entire route stylesheet, silently, on every request for
 * that route.
 *
 * That is what happened at the old 7000-byte floor: shell minifies to 5309
 * bytes and woo-archive to 2246, so the pair overflowed by 555 bytes and the
 * archive sheet was dropped on every shop and category page. Since that sheet
 * carries `display:grid` and grid-template-columns, archives painted in
 * WooCommerce's float layout and only snapped into a grid once woo-ui.css had
 * downloaded and parsed.
 *
 * Nothing about that failure was visible in the code: the budget check reads
 * as a sensible guard, and the numbers that make it misfire live in the CSS
 * files. This test puts the numbers under assertion, so growing shell.css --
 * or adding a sheet to a route -- fails here instead of quietly costing the
 * archive its layout.
 *
 * Run: php tests/test-critical-budget.php
 *
 * @package Delicat_Builder_V9
 */

$root = dirname( __DIR__ );

/** Mirror of Performance::minify_css(). */
function dbv9_minify_css( string $css ): string {
	$css = preg_replace( '#/\*[^!][\s\S]*?\*/#', '', $css );
	$css = preg_replace( '/\s+/', ' ', (string) $css );
	$css = preg_replace( '/\s*([{}:;,>])\s*/', '$1', (string) $css );
	return trim( (string) $css );
}

/** The floor the plugin clamps to, read from the source so the two agree. */
$performance = (string) file_get_contents( $root . '/includes/class-delicat-builder-performance.php' );
$floor       = 0;
if ( preg_match( '/const MIN_CRITICAL_BYTES\s*=\s*(\d+)/', $performance, $m ) ) {
	$floor = (int) $m[1];
}

/**
 * Critical keys per route, in the order Performance::detect_context() discovers
 * them: the shell first, then the route's own sheets.
 */
$routes = array(
	'shop / category archive' => array( 'shell', 'woo-archive' ),
	'single product (woo-ui)' => array( 'shell', 'woo-single', 'purchase-single' ),
	'cart'                    => array( 'shell', 'purchase-cart' ),
	'checkout'                => array( 'shell', 'purchase-checkout' ),
	'builder homepage'        => array( 'shell', 'products-home' ),
);

$pass = 0;
$fail = 0;

if ( $floor < 1 ) {
	++$fail;
	echo "  FAIL  could not read MIN_CRITICAL_BYTES from Performance\n";
} else {
	++$pass;
	printf( "  ok    budget floor is %d bytes\n\n", $floor );
}

foreach ( $routes as $label => $keys ) {
	$total   = 0;
	$dropped = array();

	foreach ( $keys as $key ) {
		$file = $root . '/assets/critical/' . $key . '.css';
		if ( ! is_file( $file ) ) {
			++$fail;
			printf( "  FAIL  %s: missing assets/critical/%s.css\n", $label, $key );
			continue;
		}
		$size = strlen( dbv9_minify_css( (string) file_get_contents( $file ) ) );
		if ( $total + $size > $floor ) {
			$dropped[] = sprintf( '%s (%d B)', $key, $size );
			continue;
		}
		$total += $size;
	}

	if ( empty( $dropped ) ) {
		++$pass;
		printf( "  ok    %-24s %5d B of %d\n", $label, $total, $floor );
	} else {
		++$fail;
		printf(
			"  FAIL  %-24s %5d B of %d -- DROPPED: %s\n",
			$label,
			$total,
			$floor,
			implode( ', ', $dropped )
		);
	}
}

/* The admin input must not offer a value the runtime would raise anyway, or a
 * merchant can save a number that silently does not apply. */
$admin = (string) file_get_contents( $root . '/includes/class-delicat-builder-performance-admin.php' );
if ( preg_match( '/min="(\d+)" max="20000" step="500"/', $admin, $m ) && (int) $m[1] >= $floor ) {
	++$pass;
	printf( "\n  ok    admin input floor (%s) matches the runtime clamp\n", $m[1] );
} else {
	++$fail;
	printf( "\n  FAIL  admin input floor disagrees with MIN_CRITICAL_BYTES (%d)\n", $floor );
}

echo "\n---------------------------------------------\n";
printf( "passed %d / failed %d\n", $pass, $fail );
exit( 0 === $fail ? 0 : 1 );
