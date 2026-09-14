<?php
/**
 * Enough WordPress to exercise V10's pure functions outside WordPress.
 *
 * Only functions that V10 actually calls are stubbed, and each stub behaves
 * like the real one for the inputs V10 gives it. A stub that lies makes a test
 * that lies.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DELICAT_V10_VERSION', '10.0.0' );
define( 'DELICAT_V10_FILE', dirname( __DIR__ ) . '/delicat-storefront-v10.php' );
define( 'DELICAT_V10_DIR', dirname( __DIR__ ) . '/' );
define( 'DELICAT_V10_URL', 'https://delicastoreha.com/wp-content/plugins/delicat-storefront-v10/' );
define( 'DELICAT_V10_MIN_PHP', '7.4' );

$GLOBALS['dlx_options'] = array();
$GLOBALS['dlx_filters'] = array();
$GLOBALS['dlx_actions'] = array();

function get_option( $key, $default = false ) {
	return $GLOBALS['dlx_options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['dlx_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['dlx_options'][ $key ] );
	return true;
}
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['dlx_actions'][ $hook ][] = $cb;
	return true;
}
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['dlx_filters'][ $hook ][] = $cb;
	return true;
}
function apply_filters( $hook, $value, ...$rest ) {
	foreach ( $GLOBALS['dlx_filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$rest );
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['dlx_actions'][ $hook ] ?? array() as $cb ) {
		$cb( ...$args );
	}
}
function did_action( $hook ) {
	return 0;
}
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return filter_var( (string) $t, FILTER_SANITIZE_URL ); }
function esc_url_raw( $t ) { return (string) $t; }
function wp_unslash( $t ) { return is_string( $t ) ? stripslashes( $t ) : $t; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function is_admin() { return false; }
function is_user_logged_in() { return ! empty( $GLOBALS['dlx_logged_in'] ); }
function is_rtl() { return false; }
function get_bloginfo( $key = 'name', $filter = 'raw' ) { return $GLOBALS['dlx_bloginfo'][ $key ] ?? ''; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['dlx_options']['_t_' . $k] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['dlx_options']['_t_' . $k] ?? false; }
function delete_transient( $k ) { unset( $GLOBALS['dlx_options']['_t_' . $k] ); return true; }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^A-Za-z0-9_-]+/', '-', (string) $t ) ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $c ) ? $c : null; }
function wp_strip_all_tags( $t, $break = false ) { return strip_tags( (string) $t ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function has_filter( $hook, $cb = false ) { return ! empty( $GLOBALS['dlx_filters'][ $hook ] ); }
function wp_kses_post( $t ) { return (string) $t; }
function add_query_arg( ...$a ) { return '/'; }
function wp_get_upload_dir() { return array( 'baseurl' => 'https://delicastoreha.com/wp-content/uploads' ); }
function current_user_can( $c ) { return true; }
function __return_zero() { return 0; }
function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
function wp_rand( $min = 0, $max = 0 ) { return random_int( $min, $max ?: PHP_INT_MAX ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return DELICAT_V10_URL; }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function home_url( $path = '/' ) { return 'https://delicastoreha.com' . $path; }

require_once DELICAT_V10_DIR . 'src/Support/Autoloader.php';
require_once DELICAT_V10_DIR . 'src/Support/Guard.php';
\Delicat\V10\Support\Autoloader::register( DELICAT_V10_DIR . 'src/', 'Delicat\\V10\\' );
