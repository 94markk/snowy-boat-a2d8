#!/usr/bin/env php
<?php
/**
 * Regenerate integrity-manifest.json for the plugin package.
 *
 * Every file inside the plugin directory except the manifest itself is
 * recorded with its byte size and SHA-256. The plugin version is read from
 * the main plugin file header so the manifest can never lag the release.
 *
 * Usage: php tools/build-manifest.php [plugin-dir]
 */

$root = rtrim( $argv[1] ?? dirname( __DIR__ ) . '/delicat-builder-v9', '/' );
if ( ! is_dir( $root ) ) {
	fwrite( STDERR, "Plugin directory not found: {$root}\n" );
	exit( 1 );
}

$main = file_get_contents( $root . '/delicat-builder-v9.php' );
if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) $main, $m ) ) {
	fwrite( STDERR, "Could not read the plugin version header\n" );
	exit( 1 );
}
$version = $m[1];
if ( ! preg_match( "/define\( 'DELICAT_BUILDER_V9_VERSION', '" . preg_quote( $version, '/' ) . "' \)/", (string) $main ) ) {
	fwrite( STDERR, "Version header ({$version}) and DELICAT_BUILDER_V9_VERSION constant differ\n" );
	exit( 1 );
}

$files = array();
$bytes = 0;
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	$rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
	if ( 'integrity-manifest.json' === $rel || 0 === strpos( basename( $rel ), '.' ) ) {
		continue;
	}
	$size = (int) $file->getSize();
	$files[ $rel ] = array(
		'bytes'  => $size,
		'sha256' => hash_file( 'sha256', $file->getPathname() ),
	);
	$bytes += $size;
}
ksort( $files, SORT_STRING );

$manifest = array(
	'algorithm' => 'sha256',
	'built'     => gmdate( 'Y-m-d\TH:i:s.u\+00:00' ),
	'version'   => $version,
	'bytes'     => $bytes,
	'count'     => count( $files ),
	'files'     => $files,
);

file_put_contents(
	$root . '/integrity-manifest.json',
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
);
printf( "integrity-manifest.json: version %s, %d files, %d bytes\n", $version, count( $files ), $bytes );
