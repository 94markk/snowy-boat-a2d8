<?php
/**
 * The asset layer must be additive.
 *
 * Assets are content-addressed: editing drawer.css changes its filename. HTML
 * that references the OLD filename is cached in places a build cannot reach -
 * LiteSpeed, Cloudflare, and every phone that has already loaded the shop. If
 * the build deletes the superseded copy, all of that HTML starts 404ing on it.
 *
 * A 404 on one component is a cosmetic fault. A 404 on
 * storefront-chrome.min.<hash>.css is the entire shop rendered with no styling,
 * which is exactly what happened: one release dropped the copy the previous
 * release's cached pages were still asking for, and the storefront came back as
 * a bare list of links.
 *
 * So superseded copies are kept, and only --prune removes them.
 *
 * Run: php tests/asset-retention-test.php
 */
$script = __DIR__ . '/../scripts/refresh-delicat-assets.py';
$tmp    = sys_get_temp_dir() . '/delicat-asset-retention-' . getmypid();

$pass = 0; $fail = 0;
function ok( string $what, bool $cond, string $detail = '' ): void {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  ok    $what\n"; }
    else { $fail++; echo "  FAIL  $what" . ( $detail ? "  -- $detail" : '' ) . "\n"; }
}
function group( string $n ): void { echo "\n$n\n" . str_repeat( '-', strlen( $n ) ) . "\n"; }

function rmtree( string $dir ): void {
    if ( ! is_dir( $dir ) ) { return; }
    $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
    rmdir( $dir );
}

/** A minimal plugin tree the refresh script will accept. */
function build_tree( string $tmp, string $css ): void {
    rmtree( $tmp );
    mkdir( $tmp . '/assets/css', 0777, true );
    mkdir( $tmp . '/assets/components', 0777, true );

    /* drawer.css is one of the chrome bundle's sources, so the bundle and its
       siblings have to exist or the script refuses to build. Using a source
       that feeds a bundle is deliberate: the bundle is the file whose 404 takes
       the whole storefront down, so it is the one worth testing. */
    foreach ( array( 'theme-system.css', 'bottom-nav.css' ) as $sibling ) {
        file_put_contents( $tmp . '/assets/css/' . $sibling, '.x{}' );
    }
    file_put_contents( $tmp . '/assets/dsb8-beta2-header.css', '.y{}' );
    file_put_contents( $tmp . '/assets/css/drawer.css', $css );

    foreach ( array(
        'base.css', 'testimonials.css', 'trust-strip.css', 'products.css', 'newsletter.css',
        'how-it-works.css', 'hero.css', 'bon-kliyan.css', 'text.css', 'banner.css',
        'category-chips.css', 'faq.css', 'why-delicat.css', 'favorites.css', 'spacer.css',
    ) as $part ) {
        file_put_contents( $tmp . '/assets/components/' . $part, '.c{}' );
    }

    file_put_contents( $tmp . '/delicat-builder-v9.php', "<?php\n/**\n * Version: 9.9.9-test\n */\n" );
}

function refresh( string $script, string $tmp, bool $prune = false ): string {
    $cmd = 'python3 ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $tmp ) . ( $prune ? ' --prune' : '' ) . ' 2>&1';
    return (string) shell_exec( $cmd );
}

/** Hashed copies currently on disk. */
/** Hashed copies of one source, by its basename. */
function hashed( string $tmp, string $stem = 'drawer' ): array {
    $out = array();
    foreach ( (array) glob( $tmp . '/assets/css/' . $stem . '.*' ) as $f ) {
        if ( preg_match( '/\.[0-9a-f]{12}\.css$/', (string) $f ) ) { $out[] = basename( (string) $f ); }
    }
    sort( $out );
    return $out;
}

group( 'An edit does not strand the pages already cached' );

build_tree( $tmp, '.a{color:red}' );
refresh( $script, $tmp );
$first = hashed( $tmp );
ok( 'the first build writes one hashed copy', 1 === count( $first ), implode( ',', $first ) );
$original = $first[0] ?? '';

/* The edit every release makes. */
file_put_contents( $tmp . '/assets/css/drawer.css', '.a{color:blue}' );
$out    = refresh( $script, $tmp );
$second = hashed( $tmp );

ok( 'the edit produces a new hashed copy', count( $second ) >= 2, implode( ',', $second ) );
ok(
    'and the copy cached pages ask for is still there',
    in_array( $original, $second, true ),
    'a page cached before this build would 404 on ' . $original
);
ok( 'the build says so', false !== strpos( $out, 'kept' ), trim( $out ) );

$map = (string) file_get_contents( $tmp . '/asset-versions.php' );
ok(
    'the map points at the new copy, not the kept one',
    false !== strpos( $map, "'assets/css/drawer.css' => " ) && false === strpos( $map, $original ),
    'new HTML must reference the current build'
);

group( 'Removing them is a deliberate act' );

$out   = refresh( $script, $tmp, true );
$third = hashed( $tmp );
ok( 'with --prune the superseded copy goes', ! in_array( $original, $third, true ), implode( ',', $third ) );
ok( 'the current one stays', 1 === count( $third ), implode( ',', $third ) );

group( 'The file whose 404 takes the whole storefront down' );

build_tree( $tmp, '.a{color:red}' );
refresh( $script, $tmp );
$bundle_first = hashed( $tmp, 'storefront-chrome.min' );
file_put_contents( $tmp . '/assets/css/drawer.css', '.a{color:green}' );
refresh( $script, $tmp );
$bundle_second = hashed( $tmp, 'storefront-chrome.min' );

ok( 'editing a source rebuilds the chrome bundle', count( $bundle_second ) >= 2, implode( ',', $bundle_second ) );
ok(
    'and the bundle cached pages ask for survives the rebuild',
    in_array( $bundle_first[0] ?? '-', $bundle_second, true ),
    'a 404 here is the entire shop with no styling at all'
);
ok( 'and it warns what that costs', false !== strpos( $out, 'PRUNED' ), trim( $out ) );

rmtree( $tmp );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
