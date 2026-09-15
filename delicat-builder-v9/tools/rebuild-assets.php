<?php
/**
 * Rebuild the content-addressed asset pipeline.
 *
 * Browsers never load `assets/css/foo.css`. Audit_Fixes::asset_url() filters
 * style_loader_src/script_loader_src and rewrites every plugin asset URL to the
 * hashed twin named in asset-versions.php, so editing a plain file has no
 * effect on the storefront until this script runs.
 *
 * It maintains all four build artefacts together:
 *
 *   asset-versions.php      plain path => hashed path (sha256 of contents, 12 hex)
 *   the hashed files        one per mapped asset
 *   asset-history.json      the last N build manifests; hashed files belonging to
 *                           a retained build are kept so a page that is already
 *                           in flight can still fetch the asset it referenced
 *   integrity-manifest.json bytes + sha256 for every shipped file, which
 *                           Production::verify() checks at runtime
 *
 * Usage, from the plugin directory:
 *
 *   php tools/rebuild-assets.php           # report what would change
 *   php tools/rebuild-assets.php --write   # apply
 *
 * @package Delicat_Builder_V9
 */

$root  = dirname( __DIR__ );
$write = in_array( '--write', $argv, true );

/** Assets are addressed by the first 12 hex of the sha256 of their contents. */
function dbv9_hash( string $file ): string {
	return substr( hash_file( 'sha256', $file ), 0, 12 );
}

function dbv9_is_hashed( string $relative ): bool {
	return (bool) preg_match( '/\.[0-9a-f]{12}\.(css|js)$/', $relative );
}

/** Every shipped file, plugin-root-relative, sorted, excluding build noise. */
function dbv9_all_files( string $root ): array {
	$out = array();
	$it  = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $it as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		if ( '' === $relative || 0 === strpos( $relative, '.git/' ) ) {
			continue;
		}
		$out[] = $relative;
	}
	sort( $out, SORT_STRING );
	return $out;
}

/* ------------------------------------------------------------------ */
/* 1. Hash every plain asset and decide the new map.                    */
/* ------------------------------------------------------------------ */

$sources = array();
foreach ( array( 'assets', 'pro/assets', 'modules' ) as $dir ) {
	$base = $root . '/' . $dir;
	if ( ! is_dir( $base ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		if ( ! preg_match( '/\.(css|js)$/', $relative ) || dbv9_is_hashed( $relative ) ) {
			continue;
		}
		$sources[] = $relative;
	}
}
sort( $sources, SORT_STRING );

$old_map = is_file( $root . '/asset-versions.php' ) ? (array) require $root . '/asset-versions.php' : array();
$map     = array();
$changed = array();

foreach ( $sources as $relative ) {
	$hash   = dbv9_hash( $root . '/' . $relative );
	$hashed = preg_replace( '/\.(css|js)$/', '.' . $hash . '.$1', $relative );

	$map[ $relative ] = $hashed;

	$previous = $old_map[ $relative ] ?? '';
	$missing  = ! is_file( $root . '/' . $hashed );
	if ( $previous !== $hashed || $missing ) {
		$changed[ $relative ] = array(
			'from' => $previous,
			'to'   => $hashed,
			'why'  => $previous !== $hashed ? 'contents changed' : 'hashed copy missing',
		);
	}
}

/* ------------------------------------------------------------------ */
/* 2. Roll the build history and work out which hashed files to keep.   */
/* ------------------------------------------------------------------ */

$history     = is_file( $root . '/asset-history.json' )
	? (array) json_decode( (string) file_get_contents( $root . '/asset-history.json' ), true )
	: array();
$generations = max( 1, (int) ( $history['generations'] ?? 3 ) );
$builds      = is_array( $history['builds'] ?? null ) ? $history['builds'] : array();

$current = array_values( $map );
sort( $current, SORT_STRING );

/* The newest build goes first. An unchanged rebuild must not consume a
 * generation, or three no-op runs would evict every older asset. */
$newest = $builds[0] ?? array();
if ( $newest !== $current ) {
	array_unshift( $builds, $current );
}
$builds = array_slice( $builds, 0, $generations );

$keep = array();
foreach ( $builds as $build ) {
	foreach ( (array) $build as $path ) {
		$keep[ $path ] = true;
	}
}

$orphans = array();
foreach ( dbv9_all_files( $root ) as $relative ) {
	if ( dbv9_is_hashed( $relative ) && ! isset( $keep[ $relative ] ) ) {
		$orphans[] = $relative;
	}
}

/* ------------------------------------------------------------------ */
/* 3. Report.                                                          */
/* ------------------------------------------------------------------ */

printf( "assets mapped      : %d\n", count( $map ) );
printf( "generations kept   : %d (of %d recorded)\n", count( $builds ), $generations );
printf( "assets to rewrite  : %d\n", count( $changed ) );
foreach ( $changed as $relative => $info ) {
	printf( "   %s\n      %s -> %s  (%s)\n", $relative, $info['from'] ?: '(new)', $info['to'], $info['why'] );
}
printf( "orphans to delete  : %d\n", count( $orphans ) );
foreach ( $orphans as $relative ) {
	printf( "   %s\n", $relative );
}

if ( ! $write ) {
	echo "\nnothing written. re-run with --write to apply.\n";
	exit( 0 );
}

/* ------------------------------------------------------------------ */
/* 4. Apply.                                                           */
/* ------------------------------------------------------------------ */

foreach ( $map as $relative => $hashed ) {
	$target = $root . '/' . $hashed;
	if ( ! is_file( $target ) || hash_file( 'sha256', $target ) !== hash_file( 'sha256', $root . '/' . $relative ) ) {
		if ( ! copy( $root . '/' . $relative, $target ) ) {
			fwrite( STDERR, "FAILED to write $hashed\n" );
			exit( 1 );
		}
	}
}

foreach ( $orphans as $relative ) {
	if ( is_file( $root . '/' . $relative ) && ! unlink( $root . '/' . $relative ) ) {
		fwrite( STDERR, "FAILED to delete $relative\n" );
		exit( 1 );
	}
}

$php = "<?php\n// Generated content-addressed assets. Original paths retained for compatibility.\nreturn array(\n";
foreach ( $map as $relative => $hashed ) {
	$php .= " '" . $relative . "' => '" . $hashed . "',\n";
}
$php .= ");\n";
file_put_contents( $root . '/asset-versions.php', $php );

file_put_contents(
	$root . '/asset-history.json',
	json_encode(
		array(
			'generations' => $generations,
			'builds'      => $builds,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n"
);

/* The integrity manifest covers every shipped file and is written last, so it
 * reflects the map and history above. It never lists itself. */
$files = array();
$bytes = 0;
foreach ( dbv9_all_files( $root ) as $relative ) {
	if ( 'integrity-manifest.json' === $relative ) {
		continue;
	}
	$size  = (int) filesize( $root . '/' . $relative );
	$bytes += $size;
	$files[ $relative ] = array(
		'bytes'  => $size,
		'sha256' => hash_file( 'sha256', $root . '/' . $relative ),
	);
}

$version = '';
$main    = (string) file_get_contents( $root . '/delicat-builder-v9.php' );
if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $main, $m ) ) {
	$version = trim( $m[1] );
}

file_put_contents(
	$root . '/integrity-manifest.json',
	json_encode(
		array(
			'algorithm' => 'sha256',
			'built'     => gmdate( 'Y-m-d\TH:i:s.u+00:00' ),
			'version'   => $version,
			'bytes'     => $bytes,
			'count'     => count( $files ),
			'files'     => $files,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n"
);

printf( "\nwritten. %d assets mapped, %d rewritten, %d orphans removed, %d files in integrity manifest.\n", count( $map ), count( $changed ), count( $orphans ), count( $files ) );
