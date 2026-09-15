<?php
/**
 * Guard: nothing visitor-specific may be rendered into a shared document.
 *
 * public_cache_allowed() lets a guest carrying a WooCommerce cart cookie be
 * served from LiteSpeed's shared cache. That is only safe while every renderer
 * that could print the current visitor's basket asks shared_document() first
 * and emits neutral markup instead. If someone later adds a cart read to the
 * page chrome without that guard, one shopper's basket is cached and served to
 * everyone who gets that copy.
 *
 * A unit test cannot catch that -- the mistake is a new call site, not a wrong
 * return value -- so this is a structural check over the source. It fails on
 * any cart read in a document-rendering module that is not guarded.
 *
 * Run: php tests/test-shared-document.php
 *
 * @package Delicat_Builder_V9
 */

$root = dirname( __DIR__ );

/**
 * Modules that render the always-present page chrome. These appear in the
 * document on every storefront route, so a cart read here lands in whatever
 * copy the cache stores.
 */
$chrome = array(
	'includes/class-delicat-builder-header-runtime.php',
	'includes/class-delicat-builder-bottom-nav.php',
	'includes/class-delicat-builder-shell.php',
	'includes/class-delicat-builder-drawer.php',
	'includes/class-delicat-builder-menu-runtime.php',
	'includes/class-delicat-builder-footer.php',
);

/** Reads that expose the current visitor's basket. */
$cart_reads = array(
	'get_cart_contents_count',
	'get_cart_subtotal',
	'->get_cart()',
);

/**
 * A cart read is considered guarded when the same function consults
 * shared_document(), or is gated on is_user_logged_in() (public caching is
 * refused for signed-in visitors), or is an AJAX/JSON responder (never cached).
 */
$guards = array(
	'shared_document',
	'is_user_logged_in',
	'wp_send_json',
	'nocache_headers',
);

$pass = 0;
$fail = 0;

foreach ( $chrome as $relative ) {
	$path = $root . '/' . $relative;
	if ( ! is_file( $path ) ) {
		printf( "  skip  %s (not present)\n", $relative );
		continue;
	}

	$lines = explode( "\n", (string) file_get_contents( $path ) );

	foreach ( $lines as $index => $line ) {
		$found = '';
		foreach ( $cart_reads as $needle ) {
			if ( false !== strpos( $line, $needle ) ) {
				$found = $needle;
				break;
			}
		}
		if ( '' === $found ) {
			continue;
		}

		/* Look at the enclosing function: walk back to its signature, then
		 * forward over its body, and see whether a guard appears in it. */
		$start = 0;
		for ( $i = $index; $i >= 0; $i-- ) {
			if ( preg_match( '/function\s+\w+\s*\(/', $lines[ $i ] ) ) {
				$start = $i;
				break;
			}
		}
		$end = min( count( $lines ) - 1, $start + 80 );
		$body = implode( "\n", array_slice( $lines, $start, $end - $start + 1 ) );

		$guarded = false;
		foreach ( $guards as $guard ) {
			if ( false !== strpos( $body, $guard ) ) {
				$guarded = true;
				break;
			}
		}

		$label = sprintf( '%s:%d  %s', basename( $relative ), $index + 1, $found );
		if ( $guarded ) {
			++$pass;
			printf( "  ok    %s\n", $label );
		} else {
			++$fail;
			printf( "  FAIL  %s  -- reads the cart with no shared_document()/login guard\n", $label );
		}
	}
}

/* The guard itself must exist and be wired to the cache gate, or every check
 * above passes vacuously. */
$security = (string) file_get_contents( $root . '/includes/class-delicat-builder-security.php' );
foreach ( array(
	'function shared_document'    => 'Security exposes shared_document()',
	'function query_is_cache_safe' => 'query allowlist present',
	'litespeed_vary_cookies'      => 'cart cache variant declared',
) as $needle => $label ) {
	if ( false !== strpos( $security, $needle ) ) {
		++$pass;
		printf( "  ok    %s\n", $label );
	} else {
		++$fail;
		printf( "  FAIL  %s\n", $label );
	}
}

/* shared_document() must track the cache gate rather than being an
 * independent opinion about what is cacheable. */
if ( preg_match( '/function shared_document\(\s*\)\s*:\s*bool\s*\{\s*return self::public_cache_allowed\(\s*\);\s*\}/', $security ) ) {
	++$pass;
	printf( "  ok    shared_document() defers to public_cache_allowed()\n" );
} else {
	++$fail;
	printf( "  FAIL  shared_document() has drifted from public_cache_allowed()\n" );
}

echo "\n---------------------------------------------\n";
printf( "passed %d / failed %d\n", $pass, $fail );
exit( 0 === $fail ? 0 : 1 );
