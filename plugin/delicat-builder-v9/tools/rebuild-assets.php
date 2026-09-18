<?php
/**
 * Rebuild the asset version map and the integrity manifest.
 *
 * PRO37 — WHAT CHANGED
 * --------------------
 * This script used to write a hashed COPY of every asset (foo.css plus
 * foo.<hash>.css) and keep three generations of them. That shipped 121
 * duplicate files, 1.8 MB, more than half of every CSS/JS byte in the plugin,
 * and it drifted: five retained twins no longer matched the source they were
 * built from, so browsers could be served code the plugin no longer contained.
 *
 * Assets are now addressed by ?ver=<content hash> on their real path. The
 * cache-busting guarantee is unchanged — the URL changes if and only if the
 * bytes change — with one file per asset instead of two, and no generation
 * bookkeeping to fall out of sync.
 *
 * Artefacts maintained:
 *
 *   asset-versions.php      plain path => 12-hex sha256 of contents
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

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit;
}

$root  = dirname( __DIR__ );
$write = in_array( '--write', $argv, true );

/** Assets are addressed by the first 12 hex of the sha256 of their contents. */
function dbv9_hash( string $file ): string {
	return substr( hash_file( 'sha256', $file ), 0, 12 );
}

/** A leftover hashed twin from the pre-PRO37 pipeline. */
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
/* 1. Hash every asset.                                                */
/* ------------------------------------------------------------------ */

$sources = array();
$twins   = array();
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
		if ( ! preg_match( '/\.(css|js)$/', $relative ) ) {
			continue;
		}
		if ( dbv9_is_hashed( $relative ) ) {
			$twins[] = $relative;
			continue;
		}
		$sources[] = $relative;
	}
}
sort( $sources, SORT_STRING );
sort( $twins, SORT_STRING );

$old_map = is_file( $root . '/asset-versions.php' ) ? (array) require $root . '/asset-versions.php' : array();
$map     = array();
$changed = array();

foreach ( $sources as $relative ) {
	$hash             = dbv9_hash( $root . '/' . $relative );
	$map[ $relative ] = $hash;

	$previous = (string) ( $old_map[ $relative ] ?? '' );
	/* The old map stored a path; anything that is not a bare 12-hex hash is a
	 * pre-PRO37 entry and counts as changed. */
	if ( ! preg_match( '/^[0-9a-f]{12}$/', $previous ) || $previous !== $hash ) {
		$changed[ $relative ] = array(
			'from' => $previous ?: '(new)',
			'to'   => $hash,
		);
	}
}

/* ------------------------------------------------------------------ */
/* 2. Report.                                                          */
/* ------------------------------------------------------------------ */

$twin_bytes = 0;
foreach ( $twins as $relative ) {
	$twin_bytes += (int) filesize( $root . '/' . $relative );
}

printf( "assets mapped        : %d\n", count( $map ) );
printf( "versions to rewrite  : %d\n", count( $changed ) );
foreach ( $changed as $relative => $info ) {
	printf( "   %s\n      %s -> %s\n", $relative, $info['from'], $info['to'] );
}
printf( "hashed twins to drop : %d (%s KB)\n", count( $twins ), number_format( $twin_bytes / 1024, 1 ) );
foreach ( $twins as $relative ) {
	printf( "   %s\n", $relative );
}

if ( ! $write ) {
	echo "\nnothing written. re-run with --write to apply.\n";
	exit( 0 );
}

/* ------------------------------------------------------------------ */
/* 3. Apply.                                                           */
/* ------------------------------------------------------------------ */

foreach ( $twins as $relative ) {
	if ( is_file( $root . '/' . $relative ) && ! unlink( $root . '/' . $relative ) ) {
		fwrite( STDERR, "FAILED to delete $relative\n" );
		exit( 1 );
	}
}

$php = "<?php\n// Generated: asset path => 12-hex sha256 of contents, applied as ?ver=.\nreturn array(\n";
foreach ( $map as $relative => $hash ) {
	$php .= " '" . $relative . "' => '" . $hash . "',\n";
}
$php .= ");\n";
file_put_contents( $root . '/asset-versions.php', $php );

/* asset-history.json tracked which hashed twins were still safe to keep. With
 * no twins on disk there is nothing to retain, so the file is retired. */
if ( is_file( $root . '/asset-history.json' ) ) {
	unlink( $root . '/asset-history.json' );
}

/* The integrity manifest covers every shipped file and is written last, so it
 * reflects the map above. It never lists itself. */
$files = array();
$bytes = 0;
foreach ( dbv9_all_files( $root ) as $relative ) {
	if ( 'integrity-manifest.json' === $relative ) {
		continue;
	}
	$size   = (int) filesize( $root . '/' . $relative );
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

printf( "\nwritten. %d assets mapped, %d twins removed, %s KB reclaimed.\n", count( $map ), count( $twins ), number_format( $twin_bytes / 1024, 1 ) );
