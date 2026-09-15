<?php
/**
 * Just enough WordPress, WooCommerce and the wallet plugin to load a single
 * class and call it.
 *
 * Shared so that a test and a harness cannot drift into stubbing the same
 * function two different ways and disagreeing about what the code does.
 *
 * Behaviour is driven by globals, so a test can say what the world looks like:
 *   $GLOBALS['stub_is_product']  is this a product page
 *   $GLOBALS['stub_logged_in']   is anyone signed in
 *   $GLOBALS['stub_cart_total']  what WooCommerce says the cart comes to
 *   $GLOBALS['stub_balance']     the wallet balance, or null to make it throw
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

/* ---- Just enough WordPress and WooCommerce ---- */

function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_attr__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return (string) $u; }
function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); }
function wp_kses_post( $t ) { return (string) $t; }
function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); }
function is_admin() { return false; }
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function is_user_logged_in() { return (bool) $GLOBALS['stub_logged_in']; }
function get_current_user_id() { return 0; }
function absint( $n ) { return abs( (int) $n ); }
function home_url( $p = '/' ) { return 'https://shop.test' . $p; }
function get_page_by_path() { return null; }
function wc_get_page_permalink() { return 'https://shop.test/commander/'; }
$GLOBALS['stub_enqueued'] = array();
function wp_enqueue_script( $handle = '', $src = '', $deps = array(), $ver = false, $args = array() ) {
    $GLOBALS['stub_enqueued'][] = array( 'kind' => 'script', 'handle' => $handle, 'src' => (string) $src );
}
function wp_enqueue_style( $handle = '', $src = '', $deps = array(), $ver = false, $media = 'all' ) {
    $GLOBALS['stub_enqueued'][] = array( 'kind' => 'style', 'handle' => $handle, 'src' => (string) $src );
}
function wp_localize_script() {}
function wp_create_nonce() { return 'nonce'; }
function admin_url( $p = '' ) { return 'https://shop.test/wp-admin/' . $p; }
function wc_get_account_endpoint_url() { return ''; }
function get_option( $k, $d = false ) { return $d; }
function is_product() { return $GLOBALS['stub_is_product'] ?? false; }
function wc_price( $n ) { return 'G' . number_format( (float) $n ); }
function wc_get_formatted_cart_item_data() { return ''; }

class WC_Product {}

$GLOBALS['stub_cart_total'] = 'G7,400';
class Stub_Cart {
    public function is_empty() { return false; }
    public function get_cart() { return array(); }
    public function get_total( $c = 'view' ) { return $GLOBALS['stub_cart_total']; }
}
class Stub_WC { public $cart; public $session = null;
    public function __construct() { $this->cart = new Stub_Cart(); }
    public function payment_gateways() { return null; } }
function WC() { return $GLOBALS['stub_wc']; }
$GLOBALS['stub_wc'] = new Stub_WC();

/* The wallet plugin, present only when the test says so. */
$GLOBALS['stub_balance']   = null;
$GLOBALS['stub_logged_in'] = true;
class Stub_Wallet_Api {
    public function get_wallet_balance( $uid, $ctx = 'view' ) {
        if ( null === $GLOBALS['stub_balance'] ) { throw new RuntimeException( 'no wallet' ); }
        return $GLOBALS['stub_balance'];
    }
}
class Stub_Wallet { public $wallet; public function __construct() { $this->wallet = new Stub_Wallet_Api(); } }
function woo_wallet() { return $GLOBALS['stub_woo_wallet']; }
$GLOBALS['stub_woo_wallet'] = new Stub_Wallet();

function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function wp_register_script( $handle = '', $src = false, $deps = array(), $ver = false, $footer = false ) {
    $GLOBALS['stub_registered'][] = array( 'handle' => $handle, 'src' => $src );
}
$GLOBALS['stub_registered'] = array();
$GLOBALS['stub_inline'] = array();
function wp_add_inline_script( $handle = '', $data = '', $position = 'after' ) {
    $GLOBALS['stub_inline'][] = (string) $data;
}
function wp_script_is() { return false; }
function wc_get_cart_url() { return 'https://shop.test/panier/'; }
function wc_get_checkout_url() { return 'https://shop.test/commander/'; }
function get_permalink() { return 'https://shop.test/page/'; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function get_page_by_path_stub() { return null; }

/* The plugin's own constants, so a class can be loaded outside WordPress. */
if ( ! defined( 'DELICAT_BUILDER_V9_DIR' ) )     { define( 'DELICAT_BUILDER_V9_DIR', __DIR__ . '/../../delicat-builder-v9/' ); }
if ( ! defined( 'DELICAT_BUILDER_V9_URL' ) )     { define( 'DELICAT_BUILDER_V9_URL', 'https://shop.test/plugin/' ); }
if ( ! defined( 'DELICAT_BUILDER_V9_VERSION' ) ) { define( 'DELICAT_BUILDER_V9_VERSION', 'test' ); }
