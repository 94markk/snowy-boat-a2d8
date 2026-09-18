<?php
/**
 * Guard: the declared PHP requirement must match the real syntax floor, and the
 * header must agree with every runtime gate.
 *
 * Two distinct failures this prevents:
 *
 * 1. Declaring MORE than the code needs. WordPress refuses to install an
 *    uploaded plugin whose `Requires PHP` exceeds the server's, and it decides
 *    that with version_compare() against the REPORTED VERSION STRING, not
 *    PHP_VERSION_ID. A release-candidate string ('8.5.0RC2') compares lower than
 *    a plain '8.5'; a control panel's configured version is not always what the
 *    web SAPI reports. pro.38 raised the requirement to 8.5 to match the store's
 *    host and the package stopped being installable on that very host.
 *
 * 2. The header and the runtime gate disagreeing. If the header allows an
 *    install that PHP_VERSION_ID then refuses, the plugin installs and silently
 *    disables itself — the worst of both.
 *
 * The floor is 8.3 for exactly one feature: typed class constants in
 * includes/class-delicat-builder-heart-engine.php. If that feature goes, the
 * floor can drop further; nothing may raise it to track a particular host.
 *
 * Run: php tests/test-php-requirement.php
 *
 * @package Delicat_Builder_V9
 */

$root = dirname( __DIR__ );
$main = (string) file_get_contents( $root . '/delicat-builder-v9.php' );

$pass = 0;
$fail = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $pass, $fail;
	if ( $ok ) {
		++$pass;
		printf( "  ok    %-50s %s\n", $label, $detail );
	} else {
		++$fail;
		printf( "  FAIL  %-50s %s\n", $label, $detail );
	}
}

/* The header WordPress reads. */
preg_match( '/^\s*\*\s*Requires PHP:\s*([0-9.]+)\s*$/mi', $main, $m );
$declared = $m[1] ?? '';
check( 'header declares a PHP requirement', '' !== $declared, 'Requires PHP: ' . ( $declared ?: '(none)' ) );
check( 'declared requirement is 8.3', '8.3' === $declared, $declared );

/* Every runtime gate in the package. */
$gates = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
	if ( 0 === strpos( $relative, 'tests/' ) ) {
		continue;
	}
	if ( preg_match_all( '/PHP_VERSION_ID\s*<\s*(\d+)/', (string) file_get_contents( $file->getPathname() ), $found ) ) {
		foreach ( $found[1] as $id ) {
			$gates[ $relative ][] = (int) $id;
		}
	}
}

/* 8.3 => 80300 */
list( $major, $minor ) = array_pad( explode( '.', $declared ), 2, '0' );
$expected = ( (int) $major * 10000 ) + ( (int) $minor * 100 );

check( 'at least one runtime gate exists', ! empty( $gates ), count( $gates, COUNT_RECURSIVE ) - count( $gates ) . ' gate(s)' );
foreach ( $gates as $relative => $ids ) {
	foreach ( $ids as $id ) {
		check( $relative . ' gate agrees with the header', $id === $expected, 'PHP_VERSION_ID < ' . $id . ' vs ' . $expected );
	}
}

/*
 * And the floor is honest: nothing in the package may use syntax newer than it.
 * Typed class constants (8.3) are expected in exactly one file; property hooks
 * and asymmetric visibility (8.4) must not appear at all.
 */
echo "\n=== the floor must stay honest ===\n";
$typed_const = array();
$php84       = array();
foreach ( $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
	if ( 0 === strpos( $relative, 'tests/' ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file->getPathname() );
	if ( preg_match( '/\bconst\s+(?:string|int|bool|float|array)\s+[A-Z_]/', $src ) ) {
		$typed_const[] = $relative;
	}
	if ( preg_match( '/\b(?:public|protected|private)\((?:set|get)\)|\{\s*get\s*=>|\{\s*set\s*=>/', $src ) ) {
		$php84[] = $relative;
	}
}
check( 'no PHP 8.4-only syntax anywhere', empty( $php84 ), $php84 ? implode( ', ', $php84 ) : 'none' );
check(
	'typed class constants confined to heart-engine',
	array( 'includes/class-delicat-builder-heart-engine.php' ) === $typed_const,
	implode( ', ', $typed_const ) ?: 'none'
);

echo "\n---------------------------------------------\n";
printf( "passed %d / failed %d\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
