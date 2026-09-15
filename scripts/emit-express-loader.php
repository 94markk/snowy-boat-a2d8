<?php
/**
 * Print the express sheet's on-intent loader, exactly as the plugin prints it.
 *
 * The browser suite runs the real text rather than a copy of it: a loader that
 * holds a customer's "Acheter maintenant" is not something to test an
 * approximation of.
 *
 * Usage: php scripts/emit-express-loader.php [base-url]
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DELICAT_BUILDER_V9_URL', ( $argv[1] ?? 'https://shop.test/plugin/' ) );
define( 'DELICAT_BUILDER_V9_DIR', __DIR__ . '/../delicat-builder-v9/' );
define( 'DELICAT_BUILDER_V9_VERSION', 'test' );

require __DIR__ . '/../tests/support/stubs-wp.php';
require __DIR__ . '/../delicat-builder-v9/includes/class-delicat-builder-express-sheet.php';

echo Delicat_Builder_V9_Express_Sheet::loader_script();
