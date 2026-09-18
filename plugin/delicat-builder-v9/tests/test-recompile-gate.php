<?php
/**
 * Guard: the stylesheet recompile must never run on a plugin-management request.
 *
 * pro.40 started loading the Compiler on every admin request so the first
 * wp-admin page after an update repairs the compiled stylesheets. The repair
 * queries up to 250 Builder pages and writes a stylesheet for each, and the
 * request that FIRST sees a stale version stamp is very often the one
 * installing, updating or deleting a plugin -- WordPress moving files on disk.
 * Adding a 250-page CSS compile to that same PHP process pushed it into
 * max_execution_time, and the plugin appeared unable to install or uninstall
 * itself.
 *
 * Run: php tests/test-recompile-gate.php
 *
 * @package Delicat_Builder_V9
 */

define( 'ABSPATH', '/tmp/' );
function sanitize_key( $k ) { $k = strtolower( (string) $k ); return preg_replace( '/[^a-z0-9_\-]/', '', $k ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function wp_unslash( $v ) { return $v; }
function add_action() {}
function add_filter() {}
function apply_filters( $t, $v ) { return $v; }
function get_option( $k, $d = false ) { return $d; }
function update_option() { return true; }
function delete_option() { return true; }
function get_transient( $k ) { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function current_user_can( $c ) { return true; }
function trailingslashit( $p ) { return rtrim( (string) $p, '/\\' ) . '/'; }
function wp_mkdir_p( $d ) { return true; }
function wp_upload_dir() { return array( 'basedir' => '/tmp', 'baseurl' => 'https://example.test' ); }
function get_posts() { return array(); }
function do_action() {}
function esc_html( $s ) { return $s; }
function __( $s, $d = null ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'DELICAT_BUILDER_V9_DIR' ) ) { define( 'DELICAT_BUILDER_V9_DIR', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'DELICAT_BUILDER_V9_URL' ) ) { define( 'DELICAT_BUILDER_V9_URL', 'https://example.test/' ); }
if ( ! defined( 'DELICAT_BUILDER_V9_VERSION' ) ) { define( 'DELICAT_BUILDER_V9_VERSION', '9.9.9-test' ); }

require_once dirname( __DIR__ ) . '/includes/class-delicat-builder-compiler.php';

$probe = new ReflectionMethod( 'Delicat_Builder_V9_Compiler', 'recompile_request_is_safe' );
$probe->setAccessible( true );

$pass = 0;
$fail = 0;
function check( string $label, bool $got, bool $want ): void {
	global $pass, $fail;
	if ( $got === $want ) {
		++$pass;
		printf( "  ok    %-52s => %s\n", $label, $got ? 'compile' : 'skip' );
	} else {
		++$fail;
		printf( "  FAIL  %-52s => %s, wanted %s\n", $label, $got ? 'compile' : 'skip', $want ? 'compile' : 'skip' );
	}
}

function scenario( string $pagenow, string $method = 'GET', array $request = array() ): bool {
	global $probe;
	$GLOBALS['pagenow']        = $pagenow;
	$_SERVER['REQUEST_METHOD'] = $method;
	$_REQUEST                  = $request;
	return (bool) $probe->invoke( null );
}

echo "\n=== must NEVER compile during plugin or theme management ===\n";
check( 'update.php (install/update a plugin)', scenario( 'update.php' ), false );
check( 'update.php?action=upload-plugin', scenario( 'update.php', 'POST', array( 'action' => 'upload-plugin' ) ), false );
check( 'plugins.php (the plugins list)', scenario( 'plugins.php' ), false );
check( 'plugins.php delete-selected (POST)', scenario( 'plugins.php', 'POST', array( 'action' => 'delete-selected' ) ), false );
check( 'plugins.php?action=activate', scenario( 'plugins.php', 'GET', array( 'action' => 'activate' ) ), false );
check( 'plugin-install.php (upload form)', scenario( 'plugin-install.php' ), false );
check( 'update-core.php (bulk updates)', scenario( 'update-core.php' ), false );
check( 'themes.php', scenario( 'themes.php' ), false );

echo "\n=== must never compile on a non-idempotent request ===\n";
check( 'POST to an ordinary admin screen', scenario( 'index.php', 'POST' ), false );
check( 'GET carrying an upgrade-shaped action', scenario( 'admin.php', 'GET', array( 'action' => 'do-plugin-upgrade' ) ), false );
check( 'GET carrying action=delete', scenario( 'admin.php', 'GET', array( 'action' => 'delete' ) ), false );

echo "\n=== ordinary admin pages still perform the repair (the pro.40 intent) ===\n";
check( 'index.php (Dashboard)', scenario( 'index.php' ), true );
check( 'edit.php (Posts)', scenario( 'edit.php' ), true );
check( 'options-general.php', scenario( 'options-general.php' ), true );
check( 'admin.php (a Builder screen)', scenario( 'admin.php', 'GET', array( 'page' => 'delicat-builder-v9' ) ), true );
check( 'upload.php (Media)', scenario( 'upload.php' ), true );

echo "\n---------------------------------------------\n";
printf( "passed %d / failed %d\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
