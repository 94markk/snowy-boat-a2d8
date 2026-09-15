#!/usr/bin/env php
<?php
/**
 * Regenerate the content-addressed asset copies and asset-versions.php.
 *
 * For every plain-named .css/.js under assets/, pro/assets/ and
 * modules/product-fields/assets/ a copy named <name>.<sha256[0:12]>.<ext> is
 * written next to it (stale hashed copies of the same original are removed)
 * and the original => hashed map is written to asset-versions.php, which the
 * Audit_Fixes layer uses to rewrite enqueued URLs.
 *
 * Usage: php tools/build-assets.php [plugin-dir]
 */

$root = rtrim( $argv[1] ?? dirname( __DIR__ ) . '/delicat-builder-v9', '/' );
if ( ! is_dir( $root ) ) {
	fwrite( STDERR, "Plugin directory not found: {$root}\n" );
	exit( 1 );
}

$dirs = array( 'assets', 'pro/assets', 'modules/product-fields/assets' );
$map  = array();
$written = 0;
$removed = 0;

foreach ( $dirs as $dir ) {
	$base = $root . '/' . $dir;
	if ( ! is_dir( $base ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	$originals = array();
	$hashed    = array();
	foreach ( $it as $file ) {
		$path = str_replace( '\\', '/', $file->getPathname() );
		if ( ! preg_match( '/\.(css|js)$/', $path ) ) {
			continue;
		}
		if ( preg_match( '/\.[0-9a-f]{12}\.(css|js)$/', $path ) ) {
			$hashed[] = $path;
		} else {
			$originals[] = $path;
		}
	}
	sort( $originals );
	$expected = array();
	foreach ( $originals as $path ) {
		$hash = substr( hash_file( 'sha256', $path ), 0, 12 );
		$target = preg_replace( '/\.(css|js)$/', '.' . $hash . '.$1', $path );
		$expected[ $target ] = true;
		if ( ! is_file( $target ) || hash_file( 'sha256', $target ) !== hash_file( 'sha256', $path ) ) {
			copy( $path, $target );
			$written++;
		}
		$map[ substr( $path, strlen( $root ) + 1 ) ] = substr( $target, strlen( $root ) + 1 );
	}
	foreach ( $hashed as $path ) {
		if ( ! isset( $expected[ $path ] ) ) {
			unlink( $path );
			$removed++;
		}
	}
}

ksort( $map, SORT_STRING );
$out = "<?php\n// Generated content-addressed assets. Original paths retained for compatibility.\nreturn array(\n";
foreach ( $map as $orig => $target ) {
	$out .= " '" . $orig . "' => '" . $target . "',\n";
}
$out .= ");\n";
file_put_contents( $root . '/asset-versions.php', $out );

printf( "asset-versions.php: %d entries, %d hashed copies written, %d stale copies removed\n", count( $map ), $written, $removed );
