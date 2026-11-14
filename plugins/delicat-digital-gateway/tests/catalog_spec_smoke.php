<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Catalog and reseller smoke test, against stubbed WordPress and WooCommerce. No database,
 * no network.
 *
 *   php tests/catalog_spec_smoke.php
 *
 * Guards:
 *  - select options keep their values through sanitize -> store (v2) -> read, whatever the
 *    format (objects, value => label map, numeric values, 0..n), and the reseller catalog
 *    exposes the same values;
 *  - supplier availability at add/checkout (filter delicat_dfr_cart_line_available): offer
 *    gone or out of stock refused, supplier errors and empty lists let the sale through,
 *    one supplier call per category per request, opt-in margin hold;
 *  - Player ID: « not found » is a 200 valid:false for the app, remembered 60 s, the
 *    validation context is resolved once per request, a failed validation list is not asked
 *    again for a minute, an app add reuses the customer's recent success, the shared budget;
 *  - catalog sync: missing only after two identical answers for more than half a group,
 *    empty lists and errors change nothing, saves only on change, per-save bumps suspended
 *    and one catalog_sync event;
 *  - reseller API: shared wallet lock name, typed code extraction on the unwrapped order,
 *    no automatic refund after an uncertain attempt, stale supplier_unknown alert, API key
 *    found by its key-ring hash after a salt change.
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('DFR_VERSION', '4.20.0');
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

// ---- WordPress stubs ----------------------------------------------------------------
$SALT = 'salt-one';
$OPTIONS = array('admin_email' => 'owner@example.com'); $TRANSIENTS = array(); $META = array();
$FILTERS = array(); $FIRED = array(); $MAILS = array(); $CURRENT_USER = 0;
$POST_TYPES = array(); $PARENTS = array(); $PRODUCTS = array(); $GROUP_POSTS = array();

class WP_Error {
    public $code; public $message; public $data;
    function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
    function get_error_data() { return $this->data; }
}
class WP_REST_Request {
    public $params = array(); public $method = 'POST'; public $route = '';
    function get_param($k) { return $this->params[$k] ?? null; }
    function set_param($k, $v) { $this->params[$k] = $v; }
    function get_method() { return $this->method; }
    function get_route() { return $this->route; }
}
class WooCommerce {}
class Delicat_App_Sync_Hub {}
function is_wp_error($x) { return $x instanceof WP_Error; }
function __($s, $d = '') { return $s; }
function _n($a, $b, $n, $d = '') { return $n == 1 ? $a : $b; }
function absint($v) { return abs((int) $v); }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function sanitize_text_field($v) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $v))); }
function wp_strip_all_tags($v) { return trim(strip_tags((string) $v)); }
function remove_accents($v) { return (string) $v; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_slash($v) { return is_string($v) ? addslashes($v) : $v; }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function wp_parse_args($a, $d = array()) { return array_merge((array) $d, (array) $a); }
function wp_salt($s = 'auth') { global $SALT; return $SALT . '|' . $s; }
function home_url($p = '') { return 'https://delicastoreha.com/'; }
function admin_url($p = '') { return 'https://delicastoreha.com/wp-admin/' . $p; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function wp_mail($to, $subject, $body) { global $MAILS; $MAILS[] = array($to, $subject, $body); return true; }
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function wc_format_decimal($v, $dp = false) { return $dp === false ? (string) $v : number_format((float) $v, (int) $dp, '.', ''); }
function get_option($k, $d = false) { global $OPTIONS; return array_key_exists($k, $OPTIONS) ? $OPTIONS[$k] : $d; }
function update_option($k, $v, $a = null) { global $OPTIONS; $OPTIONS[$k] = $v; return true; }
function delete_option($k) { global $OPTIONS; unset($OPTIONS[$k]); return true; }
function get_transient($k) { global $TRANSIENTS; return $TRANSIENTS[$k] ?? false; }
function set_transient($k, $v, $t = 0) { global $TRANSIENTS; $TRANSIENTS[$k] = $v; return true; }
function delete_transient($k) { global $TRANSIENTS; unset($TRANSIENTS[$k]); return true; }
function get_post_meta($id, $k = '', $single = false) { global $META; return $META[(int) $id][$k] ?? ''; }
// Like WordPress: the stored value is unslashed.
function update_post_meta($id, $k, $v) { global $META; $v = wp_unslash($v); if (($META[(int) $id][$k] ?? null) === $v) { return false; } $META[(int) $id][$k] = $v; return true; }
function delete_post_meta($id, $k) { global $META; unset($META[(int) $id][$k]); return true; }
function get_post_type($id) { global $POST_TYPES; return $POST_TYPES[(int) $id] ?? 'product'; }
function wp_get_post_parent_id($id) { global $PARENTS; return $PARENTS[(int) $id] ?? 0; }
function get_the_title($id) { return 'Produit ' . $id; }
function get_posts($args) { global $GROUP_POSTS; return $GROUP_POSTS; }
function get_current_user_id() { global $CURRENT_USER; return $CURRENT_USER; }
function is_user_logged_in() { global $CURRENT_USER; return $CURRENT_USER > 0; }
function add_filter($h, $cb, $p = 10, $a = 1) { global $FILTERS; $FILTERS[$h][] = array($cb, $a); return true; }
function add_action($h, $cb, $p = 10, $a = 1) { return add_filter($h, $cb, $p, $a); }
function remove_filter($h, $cb, $p = 10) { global $FILTERS; foreach ($FILTERS[$h] ?? array() as $i => $row) { if ($row[0] == $cb) { unset($FILTERS[$h][$i]); } } return true; }
function apply_filters($h, $v, ...$args) {
    global $FILTERS;
    foreach ($FILTERS[$h] ?? array() as $row) { $v = call_user_func_array($row[0], array_slice(array_merge(array($v), $args), 0, max(1, $row[1]))); }
    return $v;
}
function do_action($h, ...$args) { global $FIRED; $FIRED[] = array($h, $args); }
function rest_ensure_response($v) { return $v; }
function did_action($h) { return 0; }
function wp_next_scheduled($h, $a = array()) { return false; }
function wp_schedule_single_event($t, $h, $a = array()) { $GLOBALS['SCHEDULED'][] = $h; return true; }
function woo_wallet() {
    static $wallet;
    if (!$wallet) {
        $wallet = (object) array('wallet' => new class {
            public $credits = 0;
            function get_wallet_balance($u, $c = '', $cur = '') { return 0.0; }
            function debit() { return false; }
            function credit() { $this->credits++; return false; }
        });
    }
    return $wallet;
}

class Test_WPDB {
    public $prefix = 'wp_'; public $base_prefix = 'wp_';
    public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts'; public $options = 'wp_options'; public $usermeta = 'wp_usermeta'; public $users = 'wp_users';
    public $queries = array(); public $orders = array(); public $clients = array(); public $group_rows = array();
    public $insert_id = 0;
    function esc_like($s) { return addcslashes((string) $s, '_%\\'); }
    function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%[sdf]/', static function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            if ($m[0] === '%d') { return (string) (int) $v; }
            if ($m[0] === '%f') { return (string) (float) $v; }
            return "'" . str_replace("'", "\\'", (string) $v) . "'";
        }, $query);
    }
    function get_var($q) {
        $this->queries[] = $q;
        if (stripos($q, 'GET_LOCK') !== false || stripos($q, 'RELEASE_LOCK') !== false) { return '1'; }
        if (stripos($q, 'SHOW COLUMNS') !== false) {
            foreach (array('alerted_at', 'key_hash_ring', 'install_hash_ring') as $column) {
                if (strpos(str_replace('\\', '', $q), "LIKE '" . $column . "'") !== false) { return $column; }
            }
        }
        return null;
    }
    function get_row($q) {
        $this->queries[] = $q;
        if (preg_match('/FROM wp_dfr_api_orders WHERE id=(\d+)/', $q, $m)) { return isset($this->orders[(int) $m[1]]) ? (object) $this->orders[(int) $m[1]] : null; }
        if (preg_match("/key_hash_ring='([a-f0-9]{64})' AND key_ring_kid='([A-Za-z0-9]+)'/", $q, $m)) {
            foreach ($this->clients as $row) { if ($row['status'] === 'active' && $row['key_hash_ring'] === $m[1] && $row['key_ring_kid'] === $m[2]) { return (object) $row; } }
            return null;
        }
        if (preg_match("/WHERE key_hash='([a-f0-9]{64})'/", $q, $m)) {
            foreach ($this->clients as $row) { if ($row['status'] === 'active' && $row['key_hash'] === $m[1]) { return (object) $row; } }
        }
        return null;
    }
    function get_results($q) {
        $this->queries[] = $q;
        if (strpos($q, 'SELECT DISTINCT s.meta_value') !== false) { return $this->group_rows; }
        if (strpos($q, "status='supplier_unknown'") !== false && strpos($q, 'alerted_at IS NULL') !== false) {
            $out = array();
            foreach ($this->orders as $row) {
                if ($row['status'] === 'supplier_unknown' && empty($row['remote_order_id']) && $row['alerted_at'] === null) { $out[] = (object) $row; }
            }
            return $out;
        }
        return array();
    }
    function update($table, $data, $where, $format = null, $where_format = null) {
        $this->queries[] = 'UPDATE ' . $table . ' ' . json_encode($data);
        $store = $table === 'wp_dfr_api_orders' ? 'orders' : ($table === 'wp_dfr_api_clients' ? 'clients' : '');
        if ($store === '') { return 1; }
        $count = 0;
        foreach ($this->{$store} as $id => $row) {
            $match = true;
            foreach ($where as $k => $v) {
                $current = $row[$k] ?? null;
                if ($v === null ? $current !== null : (string) $current !== (string) $v) { $match = false; break; }
            }
            if ($match) { $this->{$store}[$id] = array_merge($row, $data); $count++; }
        }
        return $count;
    }
    function query($q) {
        $this->queries[] = $q;
        if (preg_match("/UPDATE wp_dfr_api_orders SET alerted_at='([^']*)' WHERE id=(\d+) AND alerted_at IS NULL/", $q, $m)) {
            $id = (int) $m[2];
            if (isset($this->orders[$id]) && $this->orders[$id]['alerted_at'] === null) { $this->orders[$id]['alerted_at'] = $m[1]; return 1; }
            return 0;
        }
        return 1;
    }
    function insert($table, $data, $format = null) { return 1; }
}
$wpdb = new Test_WPDB();

// ---- WooCommerce / gateway stubs -----------------------------------------------------
class Test_Product {
    public $id; public $parent = 0; public $name; public $price = '';
    public $manage = false; public $qty = null; public $status = 'instock'; public $backorders = 'no';
    public $changes = array(); public $saves = 0; public $bumps_suspended = array();
    function __construct($id, $name = 'Produit', $price = '') { $this->id = $id; $this->name = $name; $this->price = $price; }
    function get_id() { return $this->id; }
    function get_parent_id() { return $this->parent; }
    function get_name() { return $this->name; }
    function get_price($c = 'view') { return $this->price; }
    function is_type($t) { return false; }
    function get_manage_stock() { return $this->manage; }
    function set_manage_stock($v) { if ($this->manage !== (bool) $v) { $this->manage = (bool) $v; $this->changes['manage_stock'] = true; } }
    function set_stock_quantity($q) { if ($this->qty !== (int) $q) { $this->qty = (int) $q; $this->changes['stock_quantity'] = true; } }
    function set_stock_status($s) { if ($this->status !== $s) { $this->status = $s; $this->changes['stock_status'] = true; } }
    function set_backorders($v) { if ($this->backorders !== $v) { $this->backorders = $v; $this->changes['backorders'] = true; } }
    function get_stock_status() { return $this->status; }
    function get_stock_quantity() { return $this->qty; }
    function set_regular_price($p) {}
    function set_price($p) {}
    function get_changes() { return $this->changes; }
    function save() { $this->saves++; $this->bumps_suspended[] = apply_filters('delicat_app_suspend_catalog_bumps', false); $this->changes = array(); return $this->id; }
}
function wc_get_product($id) { global $PRODUCTS; return $PRODUCTS[(int) $id] ?? false; }
function wc_add_notice($m, $t = 'success') {}

class DFR_API {
    public static $calls = array(); public static $handlers = array();
    function __construct($key = '', $logger = null) {}
    function __call($name, $args) {
        self::$calls[] = $name;
        if (isset(self::$handlers[$name])) { return call_user_func_array(self::$handlers[$name], $args); }
        return new WP_Error('dfr_test_unstubbed', 'unstubbed ' . $name, array('status' => 503));
    }
}
class DFR_Plugin {
    private static $instance;
    public $settings = array(
        'native_fields_enabled' => '1', 'uid_validation_enabled' => '1', 'uid_validation_required' => '1',
        'catalog_pricing_mode' => 'manual', 'catalog_price_sync' => '0', 'catalog_disable_missing' => '1',
        'catalog_auto_sync' => '0', 'catalog_sync_interval' => 'hourly', 'live_orders_enabled' => '1', 'logging_enabled' => '0',
    );
    public $audits = array(); public $extract_calls = array();
    static function instance() { if (!self::$instance) { self::$instance = new self(); } return self::$instance; }
    function get_settings() { return $this->settings; }
    function api() { return new DFR_API(); }
    function has_api_key() { return true; }
    function log($level, $message, $context = array()) {}
    function can_manage() { return true; }
    function record_admin_audit($action, array $details = array()) { $this->audits[] = array($action, $details); }
    function extract_delivery_codes(array $data, $service_type = '') { $this->extract_calls[] = array($data, $service_type); return array(' GIFT-1 ', 'GIFT-1', '', 'GIFT-2'); }
}

require_once dirname(__DIR__) . '/includes/class-dfr-crypto.php';
require_once dirname(__DIR__) . '/includes/class-dfr-catalog.php';
require_once dirname(__DIR__) . '/includes/class-dfr-reseller-api.php';

// ---- Harness --------------------------------------------------------------------------
$fail = 0;
function check($ok, $label) { global $fail; echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }
function section($title) { echo "== {$title}\n"; }
$catalog = DFR_Catalog::instance();
function cat_call($name, ...$args) {
    global $catalog;
    $method = new ReflectionMethod('DFR_Catalog', $name);
    if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
    return $method->invokeArgs($catalog, $args);
}
function cat_reset_memos() {
    global $catalog;
    foreach (array('uid_context_memo', 'field_spec_memo', 'availability_memo') as $name) {
        $prop = new ReflectionProperty('DFR_Catalog', $name);
        if (PHP_VERSION_ID < 80100) { $prop->setAccessible(true); }
        $prop->setValue($catalog, array());
    }
}
$reseller = DFR_Reseller_API::instance();
function res_call($name, ...$args) {
    global $reseller;
    $method = new ReflectionMethod('DFR_Reseller_API', $name);
    if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
    return $method->invokeArgs($reseller, $args);
}
function calls_of($name) { return count(array_keys(DFR_API::$calls, $name, true)); }

// ======================================================================================
section('select options keep their values (spec v2)');
$objects = array('key' => 'server', 'label' => 'Serveur', 'type' => 'select', 'options' => array(array('value' => 'os_usa', 'label' => 'America'), array('value' => 'os_euro', 'label' => 'Europe')));
$string_map = array('key' => 'server', 'label' => 'Serveur', 'type' => 'select', 'options' => array('os_usa' => 'America', 'os_euro' => 'Europe'));
$numeric_map = array('key' => 'zone', 'label' => 'Zone', 'type' => 'select', 'options' => json_decode('{"10":"A","20":"B"}', true));
$zero_based = array('key' => 'region', 'label' => 'Région "test"', 'type' => 'select', 'options' => array(array('value' => '0', 'label' => 'Asia'), array('value' => '1', 'label' => 'Europe')));
$scalars = array('key' => 'region', 'label' => 'Region', 'type' => 'select', 'options' => array('Asia', 'Europe'));

check(DFR_Catalog::spec_options_map($objects) === array('os_usa' => 'America', 'os_euro' => 'Europe'), 'objects: value => label');
check(DFR_Catalog::spec_options_map($string_map) === array('os_usa' => 'America', 'os_euro' => 'Europe'), 'stored value => label map is read as a map (was America => America)');
check(array_keys(DFR_Catalog::spec_options_map($numeric_map)) === array(10, 20), 'numeric values 10/20 are kept');
check(DFR_Catalog::spec_options_map($zero_based) === array(0 => 'Asia', 1 => 'Europe'), 'values 0..n from objects are kept');
check(DFR_Catalog::spec_options_map($scalars) === array('Asia' => 'Asia', 'Europe' => 'Europe'), 'a plain list: value = label');
$many = array();
for ($i = 0; $i < 120; $i++) { $many[1000 + $i] = 'Option ' . $i; }
$many_map = DFR_Catalog::spec_options_map(array('options' => $many));
check(count($many_map) === 100 && array_key_first($many_map) === 1000, 'at most 100 options, keys preserved (array_slice preserve_keys)');

$first = cat_call('sanitize_field_spec', array($objects));
check(cat_call('sanitize_field_spec', $first) === $first, 'sanitizing twice changes nothing (the runtime double pass)');
foreach (array('objects' => $objects, 'value => label map' => $string_map, 'numeric values' => $numeric_map, 'values 0..n' => $zero_based) as $label => $field) {
    $sanitized = cat_call('sanitize_field_spec', array($field));
    $stored = wp_json_encode(cat_call('spec_for_storage', $sanitized));
    $read = cat_call('sanitize_field_spec', json_decode($stored, true));
    check($read === $sanitized, "round trip sanitize -> store -> read: {$label}");
    check(cat_call('field_spec_hash', $read) === cat_call('field_spec_hash', $sanitized), "stable schema hash: {$label}");
}
$META[900] = array('_dfr_service_type' => 'topup');
cat_call('store_field_spec', 900, cat_call('sanitize_field_spec', array($zero_based)));
$decoded = json_decode((string) get_post_meta(900, '_dfr_field_spec_json', true), true);
check(is_array($decoded) && $decoded[0]['label'] === 'Région "test"' && $decoded[0]['options'][1]['value'] === '1', 'stored JSON survives update_post_meta() unslashing (quotes, accents)');
check(get_post_meta(900, '_dfr_field_spec_version', true) === '2', 'spec version 2 recorded beside the JSON');
check(res_call('api_field_options', $decoded[0]) === array('0', '1'), 'reseller catalog: values of a v2 spec (was 0, 1 indexes of objects)');
check(res_call('api_field_options', $string_map) === array('os_usa', 'os_euro'), 'reseller catalog: values of an older map spec');

// ======================================================================================
section('supplier availability at add and checkout (K32)');
$POST_TYPES[501] = 'product_variation'; $PARENTS[501] = 500;
$META[500] = array('_dfr_service_type' => 'giftcard', '_dfr_category_id' => 'cat1');
$META[501] = array('_dfr_service_type' => 'giftcard', '_dfr_category_id' => 'cat1', '_dfr_offer_id' => 'card-9', '_dfr_supplier_cost_usd' => '10.0000');
$PRODUCTS[501] = new Test_Product(501, 'Netflix 15 USD', '1000');
$offers = array(array('card_id' => 'card-9', 'name' => 'Netflix 15', 'price_usd' => '10', 'stock' => 3));
DFR_API::$handlers['giftcard_cards'] = static function ($category, $timeout = 20) use (&$offers) { DFR_API::$handlers['_timeout'] = $timeout; return array('offers' => $offers); };

check($catalog->cart_line_available(true, 500, 501, 1) === true, 'offer present with stock: sellable');
check((int) DFR_API::$handlers['_timeout'] === 5, 'the live check uses a 5 s supplier timeout');
check($catalog->cart_line_available(true, 500, 501, 1) === true && calls_of('giftcard_cards') === 1, 'one supplier call per category per request');
$result = $catalog->cart_line_available(true, 500, 501, 4);
check(is_wp_error($result) && $result->get_error_code() === 'delicat_item_unavailable' && strpos($result->get_error_message(), 'il en reste 3') !== false, 'more than the supplier stock: refused with the remaining quantity');
$unrelated = new WP_Error('other', 'Autre');
check($catalog->cart_line_available($unrelated, 500, 501, 1) === $unrelated, 'an earlier refusal passes through unchanged');
check($catalog->cart_line_available(true, 777, 0, 1) === true, 'a product this gateway does not deliver is left alone');

$TRANSIENTS = array(); cat_reset_memos();
$offers = array(array('card_id' => 'card-other', 'price_usd' => '5', 'stock' => 9));
$result = $catalog->cart_line_available(true, 500, 501, 1);
check(is_wp_error($result) && $result->get_error_code() === 'delicat_item_unavailable' && strpos($result->get_error_message(), 'plus disponible') !== false, 'offer gone from the supplier list: delicat_item_unavailable, French message');
cat_reset_memos();
$before = calls_of('giftcard_cards');
$catalog->cart_line_available(true, 500, 501, 1);
check(calls_of('giftcard_cards') === $before, 'the answer is cached (60 s) across requests');

$TRANSIENTS = array(); cat_reset_memos();
$offers = array(array('card_id' => 'card-9', 'price_usd' => '10', 'stock' => 0));
$result = $catalog->cart_line_available(true, 500, 501, 1);
check(is_wp_error($result) && strpos($result->get_error_message(), 'rupture de stock') !== false, 'supplier stock 0: refused');

$TRANSIENTS = array(); cat_reset_memos();
$offers = array();
check($catalog->cart_line_available(true, 500, 501, 1) === true, 'an empty supplier list proves nothing: sale allowed');

$TRANSIENTS = array(); cat_reset_memos();
DFR_API::$handlers['giftcard_cards'] = static function () { return new WP_Error('http_request_failed', 'timeout', array('status' => 0)); };
check($catalog->cart_line_available(true, 500, 501, 1) === true, 'supplier transport error: fail open');
cat_reset_memos();
$before = calls_of('giftcard_cards');
$catalog->cart_line_available(true, 500, 501, 1);
check(calls_of('giftcard_cards') === $before, 'after an error the supplier is not asked again for 30 s');

$TRANSIENTS = array(); cat_reset_memos();
$META[601] = array('_dfr_service_type' => 'topup', '_dfr_category_id' => 'ff', '_dfr_offer_id' => 'pack-1');
$PRODUCTS[601] = new Test_Product(601, 'Free Fire 100', '300');
DFR_API::$handlers['topup_offers'] = static function () { return array('offers' => array(array('offer_id' => 'pack-1', 'price_usd' => '1', 'stock' => 0))); };
check($catalog->cart_line_available(true, 601, 0, 1) === true, 'a top-up ignores the supplier stock figure');

$TRANSIENTS = array(); cat_reset_memos();
DFR_API::$handlers['giftcard_cards'] = static function () { return array('offers' => array(array('card_id' => 'card-9', 'price_usd' => '10', 'stock' => 5))); };
$OPTIONS['dfr_margin_guard_v1'] = array('rate' => '130', 'threshold' => '10', 'email' => '1', 'hold' => '0');
check($catalog->cart_line_available(true, 500, 501, 1) === true, 'margin under the minimum with the hold off: sale allowed (alert only)');
$OPTIONS['dfr_margin_guard_v1']['hold'] = '1';
$result = $catalog->cart_line_available(true, 500, 501, 1);
check(is_wp_error($result) && $result->get_error_code() === 'delicat_item_on_hold', 'hold on, price 1000 under the 1430 minimum: delicat_item_on_hold');
$PRODUCTS[501]->price = '2000';
check($catalog->cart_line_available(true, 500, 501, 1) === true, 'hold on, price above the minimum: sellable');
$result = $catalog->reseller_line_error(500, 501, 1, 900.0);
check(is_wp_error($result) && $result->get_error_code() === 'delicat_item_on_hold', 'reseller: the hold applies to the reseller unit price');
check($catalog->reseller_line_error(500, 501, 1, 1500.0) === null, 'reseller price above the minimum: sellable');
$OPTIONS['dfr_margin_guard_v1'] = array('rate' => '0', 'threshold' => '0', 'email' => '1', 'hold' => '1');
check($catalog->cart_line_available(true, 500, 501, 1) === true, 'monitoring rate 0: guard off whatever the hold switch');

// ======================================================================================
section('Player ID checks (app verify and add)');
$TRANSIENTS = array(); cat_reset_memos(); DFR_API::$calls = array();
$META[700] = array(
    '_dfr_service_type' => 'topup', '_dfr_category_id' => 'free_fire',
    '_dfr_field_spec_json' => json_encode(array(array('key' => 'player_id', 'label' => 'Player ID', 'type' => 'text', 'required' => true))),
);
$PRODUCTS[700] = new Test_Product(700, 'Free Fire', '300');
DFR_API::$handlers['topup_validation_games'] = static function ($timeout = 20) {
    DFR_API::$handlers['_games_timeout'] = $timeout;
    return array('ok' => true, 'items' => array(array('category_id' => 'free_fire', 'name' => 'Free Fire', 'fields' => array(array('key' => 'player_id', 'label' => 'Player ID', 'type' => 'text', 'required' => true)))));
};
$validation_answer = array('ok' => true, 'valid' => false);
DFR_API::$handlers['validate_topup_id'] = static function ($category, $fields) use (&$validation_answer) { return $validation_answer; };

$result = $catalog->validate_reseller_topup_uid(700, array('player_id' => '123'), true, 'app_verify');
check(is_wp_error($result) && $result->get_error_code() === 'dapi_uid_invalid', 'supplier « not found »: dapi_uid_invalid');
check(calls_of('validate_topup_id') === 1 && calls_of('topup_validation_games') === 1, 'one validation and one validation-list call');
// 4.20.0 keeps 4.19.0's caller-controlled budget: the interactive paths pass 8 s instead of
// the hardcoded 5 s of 4.15.0. The intent is unchanged — the storefront never waits 20 s.
check((int) DFR_API::$handlers['_games_timeout'] === 8, 'the validation list uses the short timeout the caller passes');
cat_call('uid_validation_context', 700);
check(calls_of('topup_validation_games') === 1, 'the validation context is resolved once per product per request');
$result = $catalog->validate_reseller_topup_uid(700, array('player_id' => '123'), true, 'app_add');
check(is_wp_error($result) && calls_of('validate_topup_id') === 1, 'the same unknown account again within a minute: no supplier call');
$request = new WP_REST_Request();
$request->params = array('id' => 700, 'fields' => array('player_id' => '123'));
$response = $catalog->handle_app_api_player_verify($request);
check(is_array($response) && $response['success'] === true && $response['supported'] === true && $response['valid'] === false && $response['message'] !== '', 'app verify: 200 with supported true, valid false and a French message (was 422)');

// 4.21.0: this route verifies against THIS supplier's engine, so it must refuse a product
// another supplier delivers. Before the guard, a Shop2TopUp product was verified here: the
// answer meant nothing for the supplier that would deliver, and the attempt spent a slot of
// this gateway's per-customer budget.
$META[7700] = array('_dst2t_enabled' => 'yes', '_dst2t_item_id' => '4411');
$PRODUCTS[7700] = new Test_Product(7700, 'Free Fire LATAM (Shop2TopUp)', '300');
$before = calls_of('validate_topup_id');
$request = new WP_REST_Request();
$request->params = array('id' => 7700, 'fields' => array('player_id' => '123'));
$response = $catalog->handle_app_api_player_verify($request);
check(is_wp_error($response) && $response->get_error_code() === 'dfr_app_not_our_product', 'app verify refuses a product another supplier delivers');
check(is_wp_error($response) && (int) $response->get_error_data()['status'] === 409, 'with 409: the product is fine, the route is the wrong one');
check(calls_of('validate_topup_id') === $before, 'and no supplier call is made for it');

$META[7701] = array();
$PRODUCTS[7701] = new Test_Product(7701, 'Compte livré à la main', '300');
$request = new WP_REST_Request();
$request->params = array('id' => 7701, 'fields' => array('player_id' => '123'));
$response = $catalog->handle_app_api_player_verify($request);
check(is_wp_error($response) && $response->get_error_code() === 'dfr_app_not_our_product', 'and refuses a product with no supplier mapping at all');

// 4.22.0: refusing outright left Shop2TopUp products unable to verify at all -- the app
// has ONE verify button. The owner of the product answers instead; we only refuse when
// nobody does.
$GLOBALS['delegated_with'] = null;
add_filter('dfr_app_player_verify_delegate', static function ($answer, $product_id, $requested_id, $request) {
    $GLOBALS['delegated_with'] = array($product_id, $requested_id);
    return array('success' => true, 'supported' => true, 'valid' => true, 'player_name' => 'Joueur S2T', 'region' => 'LATAM');
}, 10, 4);
$before = calls_of('validate_topup_id');
$request = new WP_REST_Request();
$request->params = array('id' => 7700, 'fields' => array('player_id' => '10120240285'));
$response = $catalog->handle_app_api_player_verify($request);
check(is_array($response) && true === ($response['valid'] ?? null) && 'Joueur S2T' === ($response['player_name'] ?? ''), 'the owning supplier verifies its own product');
check(array(7700, 7700) === $GLOBALS['delegated_with'], 'and is told which product was asked about');
check(calls_of('validate_topup_id') === $before, 'this gateway never calls its own supplier for it');
check(!is_wp_error($response), 'the customer no longer sees « livre par un autre fournisseur »');
$result = $catalog->validate_reseller_topup_uid(700, array('player_id' => '124'), true, 'app_add');
check(is_wp_error($result) && calls_of('validate_topup_id') === 2, 'another Player ID is asked again');
$app_error = cat_call('app_uid_error', new WP_Error('dapi_uid_upstream', 'Player ID verification is temporarily unavailable.', array('status' => 503)));
check($app_error->get_error_code() === 'dapi_uid_upstream' && $app_error->get_error_data()['status'] === 503 && strpos($app_error->get_error_message(), 'momentanément') !== false, 'app add-time notices in French; code and status unchanged');

$TRANSIENTS = array(); cat_reset_memos(); DFR_API::$calls = array();
$CURRENT_USER = 7;
$validation_answer = array('ok' => true, 'valid' => true, 'player_name' => 'Joueur', 'region' => 'US');
$result = $catalog->validate_reseller_topup_uid(700, array('player_id' => '8593173765'), true, 'app_verify');
check(is_array($result) && !empty($result['valid']) && calls_of('validate_topup_id') === 1, 'app verify succeeds');
$result = $catalog->validate_reseller_topup_uid(700, array('player_id' => '8593173765'), true, 'app_add');
check(is_array($result) && $result['player_name'] === 'Joueur' && calls_of('validate_topup_id') === 1, 'the add that follows reuses the customer\'s recent success (no third validation)');
$result = $catalog->validate_reseller_topup_uid(700, array('player_id' => '8593173765'), true, '');
check(is_array($result) && calls_of('validate_topup_id') === 2, 'the reseller path never reuses it');
$CURRENT_USER = 0;
$stored_names = implode(' ', array_keys($TRANSIENTS));
check(strpos($stored_names, '8593173765') === false && strpos(json_encode($TRANSIENTS), '8593173765') === false, 'no Player ID in transient names or values');

$TRANSIENTS = array(); cat_reset_memos(); DFR_API::$calls = array();
$OPTIONS['dfr_uid_global_window_v1'] = array('started' => time(), 'count' => 60);
$result = $catalog->validate_reseller_topup_uid(700, array('player_id' => '556'), true, '');
check(is_wp_error($result) && $result->get_error_code() === 'dapi_uid_upstream' && $result->get_error_data()['status'] === 429 && calls_of('validate_topup_id') === 0, 'shared budget spent: 429 before any supplier call');
unset($OPTIONS['dfr_uid_global_window_v1']);

$TRANSIENTS = array(); cat_reset_memos(); DFR_API::$calls = array();
DFR_API::$handlers['topup_validation_games'] = static function () { return new WP_Error('http_request_failed', 'timeout', array('status' => 0)); };
check(cat_call('uid_validation_context', 700) === false, 'validation list down: no context (verification skipped)');
cat_reset_memos();
cat_call('uid_validation_context', 700);
check(calls_of('topup_validation_games') === 1, 'a failed validation list is not asked again for a minute');

// 4.24: the product page only decides which button to show. It never waits for the
// supplier's validation list when a good copy exists; checks keep their own fetch.
section('product page uses the last good validation list');
$TRANSIENTS = array(); cat_reset_memos(); DFR_API::$calls = array(); $GLOBALS['SCHEDULED'] = array();
DFR_API::$handlers['topup_validation_games'] = static function ($timeout = 20) {
    DFR_API::$handlers['_games_timeout'] = $timeout;
    return array('ok' => true, 'items' => array(array('category_id' => 'free_fire', 'name' => 'Free Fire', 'fields' => array(array('key' => 'player_id', 'label' => 'Player ID', 'type' => 'text', 'required' => true)))));
};
check(is_array(cat_call('uid_validation_context', 700)) && !empty($TRANSIENTS['dfr_uid_validation_games_stale_v3']), 'a good list is also kept as the last good copy');
unset($TRANSIENTS['dfr_uid_validation_games_v3']); cat_reset_memos(); DFR_API::$calls = array();
check(is_array(cat_call('uid_validation_context', 700, false, 5, true)) && calls_of('topup_validation_games') === 0, 'expired list: the page shows the button from the last good copy, no supplier call');
check(in_array('dfr_uid_validation_games_refresh', $GLOBALS['SCHEDULED'], true), 'and queues a background refresh');
check(is_array(cat_call('uid_validation_context', 700)) && calls_of('topup_validation_games') === 1, 'a verification in the same request still reads a fresh list');
$catalog->refresh_uid_validation_games();
check(calls_of('topup_validation_games') === 1, 'the background refresh does nothing once the list is fresh');
$TRANSIENTS = array(); cat_reset_memos(); DFR_API::$calls = array();
cat_call('uid_validation_context', 700, false, 5, true);
check(calls_of('topup_validation_games') === 1 && (int) DFR_API::$handlers['_games_timeout'] === 5, 'no saved list at all: one fetch bounded to 5 s (was 20 s)');

// ======================================================================================
section('catalog sync batches');
$TRANSIENTS = array(); cat_reset_memos(); DFR_API::$calls = array(); $FIRED = array();
$META[801] = array('_dfr_imported' => '1', '_dfr_service_type' => 'giftcard', '_dfr_category_id' => 'cat2', '_dfr_offer_id' => 'a', '_dfr_supplier_stock' => '5');
$META[802] = array('_dfr_imported' => '1', '_dfr_service_type' => 'giftcard', '_dfr_category_id' => 'cat2', '_dfr_offer_id' => 'b');
$META[803] = array('_dfr_imported' => '1', '_dfr_service_type' => 'giftcard', '_dfr_category_id' => 'cat2', '_dfr_offer_id' => 'c', '_dfr_supplier_stock' => '2');
$PRODUCTS[801] = new Test_Product(801, 'Carte A'); $PRODUCTS[801]->manage = true; $PRODUCTS[801]->qty = 5;
$PRODUCTS[802] = new Test_Product(802, 'Carte B');
$PRODUCTS[803] = new Test_Product(803, 'Carte C'); $PRODUCTS[803]->manage = true; $PRODUCTS[803]->qty = 2;
$GROUP_POSTS = array(801, 802, 803);
$group = array('service' => 'giftcard', 'category_id' => 'cat2');
$sync_offers = array();
DFR_API::$handlers['giftcard_cards'] = static function ($category, $timeout = 20) use (&$sync_offers) {
    DFR_API::$handlers['_sync_timeout'] = $timeout;
    return is_wp_error($sync_offers) ? $sync_offers : array('offers' => $sync_offers);
};
$settings = DFR_Plugin::instance()->settings;

$sync_offers = array(array('card_id' => 'a', 'price_usd' => '4', 'stock' => 4), array('card_id' => 'b', 'price_usd' => '5', 'stock' => null), array('card_id' => 'c', 'price_usd' => '6', 'stock' => 2));
$out = cat_call('sync_group', $group, $settings, 8, array());
check($out['updated'] === 3 && $PRODUCTS[801]->qty === 4 && $PRODUCTS[801]->saves === 1, 'present offers: stock updated and saved');
check($PRODUCTS[803]->saves === 0 && $PRODUCTS[802]->saves === 0, 'unchanged products are not saved');
check((int) DFR_API::$handlers['_sync_timeout'] === 8, 'background sync uses the 8 s timeout');

$sync_offers = array(array('card_id' => 'a', 'price_usd' => '4', 'stock' => 4));
$out = cat_call('sync_group', $group, $settings, 8, $out['state']);
check($out['pending_confirmation'] === true && $out['missing'] === 0 && $PRODUCTS[803]->status === 'instock', 'two of three offers gone: nothing disabled on the first answer');
$out = cat_call('sync_group', $group, $settings, 8, $out['state']);
check($out['missing'] === 2 && $PRODUCTS[802]->status === 'outofstock' && $PRODUCTS[803]->status === 'outofstock', 'the same answer twice: marked missing');
check($PRODUCTS[803]->qty === 0 && get_post_meta(803, '_dfr_supplier_missing', true) === '1', 'a managed missing item goes to quantity 0 (so WooCommerce keeps it out of stock)');

$sync_offers = array();
$state_before = $out['state'];
$out = cat_call('sync_group', $group, $settings, 8, $state_before);
check($out['missing'] === 0 && $out['pending_confirmation'] === true && !empty($out['state']['empty']), 'an empty list disables nothing on the first answer');
$sync_offers = new WP_Error('dfr_api_error', 'Upstream service error (HTTP 502).', array('status' => 502));
$out = cat_call('sync_group', $group, $settings, 8, $state_before);
check($out['error'] === 'dfr_api_error' && $out['updated'] === 0 && $out['state']['errors'] === 1, 'a supplier error leaves the group untouched');

$sync_offers = array(array('card_id' => 'a', 'price_usd' => '4', 'stock' => 4), array('card_id' => 'b', 'price_usd' => '5', 'stock' => null), array('card_id' => 'c', 'price_usd' => '6', 'stock' => 1));
$out = cat_call('sync_group', $group, $settings, 8, $state_before);
check($PRODUCTS[802]->status === 'outofstock' && get_post_meta(802, '_dfr_supplier_missing', true) === '', 'back without a stock figure: « missing » cleared, but an unknown availability never restocks (4.18)');
check($PRODUCTS[803]->status === 'instock' && $PRODUCTS[803]->qty === 1, 'back with a stock figure: quantity restored');
$saves = $PRODUCTS[801]->saves + $PRODUCTS[802]->saves + $PRODUCTS[803]->saves;
cat_call('sync_group', $group, $settings, 8, $out['state']);
check($PRODUCTS[801]->saves + $PRODUCTS[802]->saves + $PRODUCTS[803]->saves === $saves, 'the same data again: no save');

$wpdb->group_rows = array((object) array('service' => 'giftcard', 'category_id' => 'cat2'));
$sync_offers = array(array('card_id' => 'a', 'price_usd' => '4', 'stock' => 7), array('card_id' => 'b', 'price_usd' => '5', 'stock' => null), array('card_id' => 'c', 'price_usd' => '6', 'stock' => 1));
$FIRED = array();
$result = cat_call('sync_imported_products', true, 0);
check(is_array($result) && $result['groups'] === 1 && $result['remaining'] === 0, 'a batch syncs the pending group');
check(end($PRODUCTS[801]->bumps_suspended) === true, 'per-save app catalog bumps are suspended during the batch');
check(apply_filters('delicat_app_suspend_catalog_bumps', false) === false, 'and restored after it');
$events = array_values(array_filter($FIRED, static function ($row) { return $row[0] === 'delicat_app_content_changed'; }));
check(count($events) === 1 && $events[0][1][0] === 'catalog_sync', 'one delicat_app_content_changed « catalog_sync » event');

// ======================================================================================
section('reseller API');
check(res_call('wallet_lock_name', 7) === 'dlc_' . substr(hash('sha256', 'wallet_user:7'), 0, 48), 'wallet lock: the App API name for wallet_user:7 (contract K23)');
$wpdb->queries = array();
res_call('wallet_lock', 7);
check(strpos(end($wpdb->queries), res_call('wallet_lock_name', 7)) !== false, 'GET_LOCK uses it');

$safe = res_call('safe_result', array('ok' => true, 'order' => array('id' => 'ord-5', 'product' => array('code' => 'SKU-1'), 'cards' => array(array('code' => 'GIFT-1')))), 'giftcard');
$last = end(DFR_Plugin::instance()->extract_calls);
check($last[0]['id'] === 'ord-5' && $last[1] === 'giftcard', 'codes: the shared typed extractor gets the unwrapped order and the line type');
check($safe['codes'] === array('GIFT-1', 'GIFT-2'), 'codes: trimmed, distinct, empty values dropped');

$public = 'dapiord_TESTORDER000001';
$request_json = DFR_Crypto::encrypt_context(json_encode(array('v' => 2, 'sku' => 'dapi_1', 'quantity' => 1, 'fields' => array(), 'mapping' => array('type' => 'giftcard', 'category' => 'cat1', 'offer' => 'card-9', 'validation_category' => ''))), 'reseller-request|order:' . $public);
$row = array('id' => 11, 'public_id' => $public, 'user_id' => 7, 'product_id' => 500, 'variation_id' => 501, 'quantity' => 1, 'amount' => '500.00', 'currency' => 'HTG', 'status' => 'processing', 'remote_order_id' => null, 'remote_status' => null, 'request_json' => $request_json, 'result_enc' => null, 'wallet_debit_ref' => '55', 'wallet_refund_ref' => null, 'woo_order_id' => null, 'attempts' => 1, 'alerted_at' => null, 'updated_at' => gmdate('Y-m-d H:i:s', time() - 3600));
DFR_API::$handlers['create_giftcard'] = static function () { return new WP_Error('dfr_invalid_card', 'Upstream service rejected the request (HTTP 400).', array('status' => 400)); };
$wpdb->orders = array(11 => $row);
$wpdb->queries = array();
res_call('submit_supplier', 11);
$lock_taken = count(array_filter($wpdb->queries, static function ($q) { return strpos($q, 'GET_LOCK') !== false && strpos($q, res_call('wallet_lock_name', 7)) !== false; }));
check($wpdb->orders[11]['status'] === 'supplier_unknown' && $lock_taken === 0, 'refusal after an attempt with an unknown outcome: held (supplier_unknown), no refund attempted');
$row['attempts'] = 0;
$wpdb->orders = array(11 => $row);
$wpdb->queries = array();
res_call('submit_supplier', 11);
$lock_taken = count(array_filter($wpdb->queries, static function ($q) { return strpos($q, 'GET_LOCK') !== false && strpos($q, res_call('wallet_lock_name', 7)) !== false; }));
check($wpdb->orders[11]['status'] === 'failed' && $lock_taken === 1, 'refusal on the first attempt: the existing refund path still runs');

$row['attempts'] = 5;
$row['status'] = 'supplier_unknown';
$wpdb->orders = array(11 => $row);
$MAILS = array();
$reseller->reconcile_supplier_orders_watchdog('test');
check(count($MAILS) === 1 && strpos($MAILS[0][2], $public) !== false && $wpdb->orders[11]['alerted_at'] !== null, 'supplier_unknown without a supplier order for 30 min: one e-mail to the owner');
$reseller->reconcile_supplier_orders_watchdog('test');
check(count($MAILS) === 1, 'and only once');

$raw_key = 'dk_live_' . str_repeat('ab', 32);
$wpdb->clients = array(1 => array('id' => 1, 'user_id' => 7, 'key_prefix' => substr($raw_key, 0, 18), 'key_hash' => hash_hmac('sha256', $raw_key, wp_salt('auth')), 'status' => 'active', 'key_hash_ring' => null, 'key_ring_kid' => null));
define('DELICAT_DFR_KEYS', 'k1:' . base64_encode(str_repeat('K', 32)));
$ring = new ReflectionProperty('DFR_Crypto', 'ring');
if (PHP_VERSION_ID < 80100) { $ring->setAccessible(true); }
$ring->setValue(null, null);
$client = res_call('find_client_by_key', $raw_key);
check($client && (int) $client->id === 1, 'API key found by its salt hash (issued before the ring)');
res_call('upgrade_key_ring', $client, $raw_key);
check(preg_match('/^[a-f0-9]{64}$/', (string) $wpdb->clients[1]['key_hash_ring']) === 1 && $wpdb->clients[1]['key_ring_kid'] === 'k1', 'after authentication its key-ring hash is stored');
$SALT = 'salt-rotated';
$client = res_call('find_client_by_key', $raw_key);
check($client && (int) $client->id === 1, 'after a salt rotation the key is still found (key-ring hash)');
check(res_call('find_client_by_key', 'dk_live_' . str_repeat('cd', 32)) === null, 'another key is not');


section('Confirmed Free Fire region mismatch');
$META[700]['_dfr_supplier_category_name']='Free Fire Europe';
$badRegion=cat_call('uid_check_response_region',700,array('player_id'=>'8593173765'),array('ok'=>true,'valid'=>true,'player_name'=>'A','region'=>'NA'));
check(is_wp_error($badRegion)&&$badRegion->get_error_message()===Delicat_Player_Region_V2::message(array('region'=>'NA')),'Wrong Free Fire server returns the requested French message');
check(cat_call('uid_validation_outcome',$badRegion)==='region_mismatch','Wrong region is distinct from not-found and unavailable');
$match=cat_call('uid_check_response_region',700,array(),array('ok'=>true,'valid'=>true,'region'=>'EU'));
check(!is_wp_error($match),'Matching European account is accepted');
$META[700]['_dfr_supplier_category_name']='Free Fire LATAM';
check(!is_wp_error(cat_call('uid_check_response_region',700,array(),array('ok'=>true,'valid'=>true,'region'=>'NA'))),'LATAM label accepts Garena Americas server NA');
check(!is_wp_error(cat_call('uid_check_response_region',700,array(),array('ok'=>true,'valid'=>true))),'Missing region is never misreported as another server');
$explicit=cat_call('uid_check_response_region',700,array(),array('ok'=>true,'valid'=>false,'code'=>'region_mismatch'));
check(is_wp_error($explicit),'Explicit provider wrong-region response keeps its classification');
$TRANSIENTS=array();cat_reset_memos();DFR_API::$calls=array();
$SALT='salt-one';
DFR_API::$handlers['topup_validation_games']=static function(){return array('ok'=>true,'items'=>array(array('category_id'=>'free_fire','name'=>'Free Fire','fields'=>array(array('key'=>'player_id','label'=>'Player ID','type'=>'text','required'=>true)))));};
$validation_answer=new WP_Error('dfr_region_mismatch',Delicat_Player_Region_V2::message(array('region'=>'EU')),array('status'=>422,'provider_code'=>'region_mismatch','provider_hint'=>'player','account_region'=>'eu'));
$result=$catalog->validate_reseller_topup_uid(700,array('player_id'=>'8593173765'),true,'app_verify');
check(is_wp_error($result)&&$result->get_error_code()==='dapi_uid_region_mismatch','Region rejection survives reseller verification');
$request=new WP_REST_Request();$request->params=array('id'=>700,'fields'=>array('player_id'=>'8593173765'));
$response=$catalog->handle_app_api_player_verify($request);
check(is_array($response)&&$response['valid']===false&&$response['message']===Delicat_Player_Region_V2::message(array('region'=>'EU')),'App gets valid false and the exact wrong-region message');

$notice=cat_call('app_uid_error',new WP_Error('dapi_uid_region_mismatch','unused',array('status'=>422,'account_region'=>'eu')));
check(strpos($notice->get_error_message(),'Europe (EU)')!==false&&strpos($notice->get_error_message(),'effectuer votre transaction')!==false,'Cart/app error keeps known account server and transaction direction');
check(Delicat_Player_Region_V2::message(array('region'=>'<script>unknown</script>'))===Delicat_Player_Region_V2::MESSAGE,'Unknown upstream labels are never echoed');
// 4.24: deactivation removes WP-Cron events whatever their arguments (catalog batches carry
// their round, reseller polls their order) and Action Scheduler jobs by hook only.
section('deactivation leaves no scheduled job behind');
$GLOBALS['UNSCHEDULED_HOOKS'] = array(); $GLOBALS['AS_UNSCHEDULED_ALL'] = array();
function wp_unschedule_hook($h) { $GLOBALS['UNSCHEDULED_HOOKS'][] = $h; return 0; }
function wp_clear_scheduled_hook($h, $a = array()) { return 0; }
function as_unschedule_all_actions($h, $args = null, $g = '') { $GLOBALS['AS_UNSCHEDULED_ALL'][] = array($h, $args); }
DFR_Catalog::deactivate();
check(in_array('dfr_catalog_sync_batch', $GLOBALS['UNSCHEDULED_HOOKS'], true) && in_array('dfr_uid_validation_games_refresh', $GLOBALS['UNSCHEDULED_HOOKS'], true), 'catalog: every WP-Cron event of its hooks');
DFR_Reseller_API::deactivate();
check(in_array('dfr_reseller_poll_order', $GLOBALS['UNSCHEDULED_HOOKS'], true) && in_array('dfr_reseller_retry_supplier', $GLOBALS['UNSCHEDULED_HOOKS'], true), 'reseller API: every WP-Cron poll and retry');
$hook_only = (bool) $GLOBALS['AS_UNSCHEDULED_ALL'];
foreach ($GLOBALS['AS_UNSCHEDULED_ALL'] as $row) { if ($row[1] !== null) { $hook_only = false; } }
check($hook_only, 'Action Scheduler jobs removed by hook, not only argument-less ones');
echo $fail ? "\n{$fail} CHECK(S) FAILED\n" : "\nALL CHECKS PASSED\n";
exit($fail ? 1 : 0);
