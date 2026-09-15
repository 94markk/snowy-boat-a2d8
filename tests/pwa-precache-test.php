<?php
/**
 * The service worker precaches the storefront shell at install. Those URLs are
 * only worth downloading if they are the URLs the page actually requests, and
 * the page requests the content-addressed twin with no query string. This test
 * runs the same resolution the service worker builder runs and asserts every
 * shell file lands on a twin that exists on disk.
 *
 * Run: php tests/pwa-precache-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DELICAT_BUILDER_V9_DIR', dirname( __DIR__ ) . '/delicat-builder-v9/' );
define( 'DELICAT_BUILDER_V9_URL', 'https://delicastoreha.com/wp-content/plugins/delicat-builder-v9/' );
define( 'DELICAT_BUILDER_V9_VERSION', '9.2.0-pro.16' );

function add_filter( ...$args ) { return true; }
function add_action( ...$args ) { return true; }
function esc_url_raw( $url ) { return $url; }
function add_query_arg( $key, $value, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . rawurlencode( $value );
}

require_once DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-audit-fixes.php';

/* The shell list as class-delicat-builder-pwa.php builds it. */
$shell = array(
	'assets/css/storefront-chrome.min.css',
	'assets/dsb8-beta2-header.js',
	'assets/js/drawer.js',
	'assets/js/session.js',
	'assets/js/theme.js',
	'assets/js/pwa-runtime.js',
);

$failures = 0;
foreach ( $shell as $rel ) {
	if ( ! is_file( DELICAT_BUILDER_V9_DIR . $rel ) ) {
		printf( "%-44s MISSING SOURCE  FAIL\n", $rel );
		$failures++;
		continue;
	}

	$url      = add_query_arg( 'ver', DELICAT_BUILDER_V9_VERSION, DELICAT_BUILDER_V9_URL . $rel );
	$resolved = Delicat_Builder_V9_Audit_Fixes::asset_url( $url );
	$path     = substr( $resolved, strlen( DELICAT_BUILDER_V9_URL ) );

	$hashed  = (bool) preg_match( '#\.[0-9a-f]{12}\.(?:css|js)$#', $path );
	$noquery = false === strpos( $resolved, '?' );
	$exists  = is_file( DELICAT_BUILDER_V9_DIR . $path );
	$ok      = $hashed && $noquery && $exists;

	printf(
		"%-44s -> %-52s %s\n",
		$rel,
		$path,
		$ok ? 'PASS' : 'FAIL' . ( $hashed ? '' : ' (not content-addressed)' ) . ( $noquery ? '' : ' (query survived)' ) . ( $exists ? '' : ' (twin missing)' )
	);
	$ok || $failures++;
}

/* A file with no twin must keep the ?ver URL WordPress would have emitted. */
$unmapped = Delicat_Builder_V9_Audit_Fixes::asset_url( DELICAT_BUILDER_V9_URL . 'assets/css/does-not-exist.css?ver=1' );
$keeps    = DELICAT_BUILDER_V9_URL . 'assets/css/does-not-exist.css?ver=1' === $unmapped;
printf( "%-44s -> %-52s %s\n", 'unmapped file', 'unchanged', $keeps ? 'PASS' : 'FAIL' );
$keeps || $failures++;

echo "\n", $failures ? $failures . " failed\n" : "all pass\n";
exit( $failures ? 1 : 0 );
