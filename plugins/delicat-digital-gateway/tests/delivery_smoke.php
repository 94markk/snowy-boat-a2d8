<?php
/**
 * Delivery smoke test for Delicat Digital Gateway 4.15, against stubbed WordPress and
 * WooCommerce. No database, no network.
 *
 *   php tests/delivery_smoke.php
 *
 * Guards the 4.15 fixes for supplier and gift-card orders stuck "En cours":
 *  - background jobs are scheduled without Action Scheduler's `unique` flag (which only
 *    compares hook + group), deduped on arguments, with a WP-Cron fallback;
 *  - checkout only queues the supplier pass: post-response work, a single-use loopback,
 *    and a durable job;
 *  - a definitive delivery failure puts the order On hold, tells the customer once,
 *    alerts the owner, never refunds automatically, and is never resent on its own;
 *  - delivered codes reach the app only for the owner of a paid, unrefunded line;
 *  - an admin "Completed" on an undelivered order is recorded, not announced;
 *  - mixed carts complete once the other lines are delivered;
 *  - code extraction ignores `code` keys outside delivery containers.
 *
 * Pass 2 (stubs follow WooCommerce: is_paid() is Processing/Completed only, get_item()
 * and read_meta_data(true) reload line meta from a backing store):
 *  - the gateway's own review hold (_dfr_gateway_hold) keeps sibling lines moving, a
 *    webhook during it stores codes, an owner hold still blocks, a status change clears it;
 *  - concurrent runners never resend a line or fail it once its supplier order exists,
 *    and a stale copy never reopens a Completed order;
 *  - failed lines can be resolved by hand; « Relancer » only for causes a resend clears;
 *  - a refused wallet attestation is escalated; lists never decrypt or spend the budget;
 *  - amount-only refunds, labelled multi-card codes, typed extraction, reseller mirror
 *    orders, top-up quantity, Player ID rate limits, admin-only mapping, maintenance.
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('DFR_VERSION', '4.20.0');
define('DFR_FILE', __FILE__);
define('DFR_DIR', dirname(__DIR__) . '/');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ---- WordPress stubs ----------------------------------------------------------------
$OPTIONS = array(); $TRANSIENTS = array(); $ACTIONS = array(); $FILTERS = array();
$SCHEDULED_WP = array(); $AS = array(); $AS_REFUSE = array(); $MAILS = array();
$REMOTE_POSTS = array(); $FIRED = array(); $CURRENT_USER = 7; $CAPS = array();

class WP_Error {
    public $code; public $message; public $data;
    function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
    function get_error_data() { return $this->data; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function __($s, $d = '') { return $s; }
function esc_html__($s, $d = '') { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function absint($v) { return abs((int) $v); }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_user($v) { return preg_replace('/[^a-z0-9_.\-@]/i', '', (string) $v); }
function wp_strip_all_tags($v) { return trim(strip_tags((string) $v)); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_parse_args($a, $d = array()) { return array_merge((array) $d, (array) $a); }
function wp_salt($s = 'auth') { return 'test-salt-' . $s; }
function home_url($p = '') { return 'https://delicastoreha.com' . $p; }
function admin_url($p = '') { return 'https://delicastoreha.com/wp-admin/' . $p; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function wp_mail($to, $subject, $body) { global $MAILS; $MAILS[] = array($to, $subject, $body); return true; }
function get_option($k, $d = false) { global $OPTIONS; return array_key_exists($k, $OPTIONS) ? $OPTIONS[$k] : $d; }
function update_option($k, $v, $a = null) { global $OPTIONS; $OPTIONS[$k] = $v; return true; }
function add_option($k, $v = '', $dep = '', $a = null) { global $OPTIONS; if (array_key_exists($k, $OPTIONS)) { return false; } $OPTIONS[$k] = $v; return true; }
function delete_option($k) { global $OPTIONS; unset($OPTIONS[$k]); return true; }
function get_transient($k) { global $TRANSIENTS; return $TRANSIENTS[$k] ?? false; }
function set_transient($k, $v, $t = 0) { global $TRANSIENTS; $TRANSIENTS[$k] = $v; return true; }
function delete_transient($k) { global $TRANSIENTS; unset($TRANSIENTS[$k]); return true; }
function get_post_meta($id, $k = '', $s = false) { return ''; }
function add_action($h, $cb, $p = 10, $a = 1) { global $ACTIONS; $ACTIONS[$h][] = array($cb, $a); return true; }
function add_filter($h, $cb, $p = 10, $a = 1) { global $FILTERS; $FILTERS[$h][] = array($cb, $a); return true; }
function do_action($h, ...$args) {
    global $FIRED, $ACTIONS;
    $FIRED[] = $h;
    foreach ($ACTIONS[$h] ?? array() as $row) { call_user_func_array($row[0], array_slice($args, 0, $row[1])); }
}
function apply_filters($h, $v, ...$args) {
    global $FILTERS;
    foreach ($FILTERS[$h] ?? array() as $row) { $v = call_user_func_array($row[0], array_slice(array_merge(array($v), $args), 0, $row[1])); }
    return $v;
}
function did_action($h) { return 1; }
function current_action() { return 'test'; }
function wp_doing_cron() { return false; }
function get_current_user_id() { global $CURRENT_USER; return $CURRENT_USER; }
function current_user_can($cap) { global $CAPS; return in_array($cap, $CAPS, true); }
function is_user_logged_in() { global $CURRENT_USER; return $CURRENT_USER > 0; }
function wp_get_current_user() { global $CURRENT_USER; return (object) array('ID' => $CURRENT_USER, 'user_login' => 'staff'); }
function wp_next_scheduled($h, $args = array()) {
    global $SCHEDULED_WP;
    foreach ($SCHEDULED_WP as $row) { if ($row[1] === $h && $row[2] == $args) { return $row[0]; } }
    return false;
}
function wp_schedule_single_event($ts, $h, $args = array()) { global $SCHEDULED_WP; $SCHEDULED_WP[] = array($ts, $h, $args); return true; }
function spawn_cron() { return true; }
function wp_safe_remote_post($url, $args) { global $REMOTE_POSTS; $REMOTE_POSTS[] = $args['body']; $GLOBALS['REMOTE_ARGS'][] = $args; return array('response' => array('code' => 200)); }

// Action Scheduler, including its real `unique` semantics: hook + group only.
function as_has_scheduled_action($h, $args = null, $g = '') {
    global $AS;
    foreach ($AS as $row) { if ($row['hook'] === $h && ($args === null || $row['args'] == $args)) { return true; } }
    return false;
}
function as_schedule_single_action($ts, $h, $args = array(), $g = '', $unique = false, $priority = 10) {
    global $AS, $AS_REFUSE;
    if (in_array($h, $AS_REFUSE, true)) { return 0; }
    if ($unique) { foreach ($AS as $row) { if ($row['hook'] === $h) { return 0; } } }
    $AS[] = array('hook' => $h, 'args' => $args, 'ts' => $ts, 'unique' => $unique, 'priority' => $priority);
    return count($AS);
}
function as_enqueue_async_action($h, $args = array(), $g = '', $unique = false, $priority = 10) { return as_schedule_single_action(time(), $h, $args, $g, $unique, $priority); }
function as_unschedule_action($h, $args = null, $g = '') { $GLOBALS['AS_UNSCHEDULED'][] = array($h, $args); return null; }

class WPDB_Stub {
    public $base_prefix = 'wp_'; public $prefix = 'wp_'; public $ledger = array();
    function prepare($q, ...$a) { return vsprintf(str_replace(array('%s', '%d'), array("'%s'", '%d'), $q), $a); }
    function get_var($q) { return (stripos($q, 'GET_LOCK') !== false || stripos($q, 'RELEASE_LOCK') !== false) ? '1' : null; }
    function get_row($q) { return (preg_match('/transaction_id=(\d+)/', $q, $m) && isset($this->ledger[(int) $m[1]])) ? $this->ledger[(int) $m[1]] : null; }
    function get_results($q) { return array(); }
}
$wpdb = new WPDB_Stub();

// ---- WooCommerce stubs --------------------------------------------------------------
class Test_Product {
    public $downloadable; public $file;
    function __construct($downloadable = false, $file = null) { $this->downloadable = $downloadable; $this->file = $file === null ? $downloadable : $file; }
    function is_downloadable() { return $this->downloadable; }
    function has_file() { return $this->downloadable && $this->file; }
    function get_parent_id() { return 0; }
    function get_id() { return 500; }
}
// Line meta as the database holds it. Like WooCommerce, save() writes only the keys this
// object changed, read_meta_data(true) and Test_Order::get_item() reload from here, and
// get_items() keeps returning the objects the order was loaded with.
$ITEM_STORE = array();
class Test_Item {
    public $id; public $order_id; public $name; public $meta; public $product; public $qty = 1; public $total = 0.0;
    public $dirty = array(); public $deleted = array();
    function __construct($id, $order_id, $name, array $meta = array(), $product = null) { $this->id = $id; $this->order_id = $order_id; $this->name = $name; $this->meta = $meta; $this->product = $product; }
    function get_id() { return $this->id; }
    function get_order_id() { return $this->order_id; }
    function get_name() { return $this->name; }
    function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
    function update_meta_data($k, $v) { $this->meta[$k] = $v; $this->dirty[$k] = true; unset($this->deleted[$k]); }
    function delete_meta_data($k) { unset($this->meta[$k]); $this->deleted[$k] = true; unset($this->dirty[$k]); }
    function save() {
        global $ITEM_STORE;
        if (!isset($ITEM_STORE[$this->id])) {
            $ITEM_STORE[$this->id] = $this->meta;
        } else {
            foreach (array_keys($this->dirty) as $k) { $ITEM_STORE[$this->id][$k] = $this->meta[$k]; }
            foreach (array_keys($this->deleted) as $k) { unset($ITEM_STORE[$this->id][$k]); }
        }
        $this->dirty = array(); $this->deleted = array();
    }
    function read_meta_data($force = false) {
        global $ITEM_STORE;
        if ($force && isset($ITEM_STORE[$this->id])) { $this->meta = $ITEM_STORE[$this->id]; $this->dirty = array(); $this->deleted = array(); }
    }
    function get_product() { return $this->product; }
    function get_quantity() { return $this->qty; }
    function get_product_id() { return 500; }
    function get_variation_id() { return 0; }
    function get_total() { return $this->total; }
    function get_total_tax() { return 0.0; }
}
// Supplier API: records every call and answers from per-test handlers.
class DFR_API {
    public static $calls = array(); public static $handlers = array();
    function __construct($key = '', $logger = null) {}
    function __call($name, $args) {
        self::$calls[] = $name;
        if (isset(self::$handlers[$name])) { return call_user_func_array(self::$handlers[$name], $args); }
        return new WP_Error('dfr_test_unstubbed', 'unstubbed ' . $name, array('status' => 503));
    }
}
class Test_Date { function getTimestamp() { return time(); } function date($f) { return gmdate($f); } }
class Test_Order {
    public $id; public $status; public $items = array(); public $meta = array(); public $notes = array();
    public $customer = 7; public $paid = true; public $payment_method = 'wallet'; public $tx = '9001';
    public $refunded_total = 0.0; public $refunded_qty = array();
    function __construct($id, $status) { $this->id = $id; $this->status = $status; }
    function get_id() { return $this->id; }
    function get_order_number() { return (string) $this->id; }
    function get_status() { return $this->status; }
    function has_status($s) { return in_array($this->status, (array) $s, true); }
    function update_status($to, $note = '') { $from = $this->status; $this->status = $to; do_action('woocommerce_order_status_changed', $this->id, $from, $to, $this); return true; }
    function add_order_note($note, $is_customer_note = false) { $this->notes[] = array($note, (bool) $is_customer_note); return count($this->notes); }
    function get_items($type = 'line_item') { return $this->items; }
    // WooCommerce loads a line from the database here; get_items() keeps the cached objects.
    function get_item($id) { $item = $this->items[$id] ?? false; if ($item) { $item->read_meta_data(true); } return $item; }
    function get_customer_id($c = '') { return $this->customer; }
    function get_user_id() { return $this->customer; }
    function get_payment_method($c = '') { return $this->payment_method; }
    // WooCommerce's paid statuses: processing and completed only.
    function is_paid() { return $this->paid && in_array($this->status, array('processing', 'completed'), true); }
    function get_date_paid($c = '') { return $this->paid ? new Test_Date() : null; }
    function get_date_created($c = '') { return new Test_Date(); }
    function get_transaction_id($c = '') { return $this->tx; }
    function get_total($c = '') { return 500.0; }
    function get_currency($c = '') { return 'HTG'; }
    function get_total_refunded() { return $this->refunded_total; }
    function get_qty_refunded_for_item($id) { return $this->refunded_qty[$id] ?? 0; }
    function get_refunds() { return array(); }
    function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
    function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    function delete_meta_data($k) { unset($this->meta[$k]); }
    function save_meta_data() {}
    function save() {}
    function read_meta_data($force = false) {}
    function get_order_key() { return 'wc_order_key'; }
}
$ORDERS = array();
function wc_get_order($id) { global $ORDERS; return $ORDERS[$id] ?? false; }
function wc_get_orders($args) { return array(); }
function wc_get_price_decimals() { return 2; }

require_once DFR_DIR . 'includes/class-dfr-crypto.php';
require_once DFR_DIR . 'includes/class-dfr-plugin.php';

// ---- Harness --------------------------------------------------------------------------
$plugin = DFR_Plugin::instance();
$ref = new ReflectionClass('DFR_Plugin');
function call_private($name, ...$args) {
    global $plugin, $ref;
    $method = $ref->getMethod($name);
    if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
    return $method->invokeArgs($method->isStatic() ? null : $plugin, $args);
}
function set_private($name, $value) {
    global $plugin, $ref;
    $property = $ref->getProperty($name);
    if (PHP_VERSION_ID < 80100) { $property->setAccessible(true); }
    $property->setValue($plugin, $value);
}
function get_private($name) {
    global $plugin, $ref;
    $property = $ref->getProperty($name);
    if (PHP_VERSION_ID < 80100) { $property->setAccessible(true); }
    return $property->getValue($plugin);
}
set_private('settings', array_merge(call_private('defaults'), array('live_orders_enabled' => '1', 'auto_fulfill_enabled' => '1', 'logging_enabled' => '0')));

$failures = 0;
function check($cond, $label) { global $failures; echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n"; if (!$cond) { $failures++; } }
function reset_world() {
    global $OPTIONS, $TRANSIENTS, $ACTIONS, $FILTERS, $SCHEDULED_WP, $AS, $AS_REFUSE, $MAILS, $REMOTE_POSTS, $FIRED, $ORDERS, $CURRENT_USER, $CAPS, $wpdb, $ITEM_STORE;
    // Orders below this id predate the v4.8 attestation, so the wallet proof is the ledger row alone.
    $OPTIONS = array('dfr_wallet_attestation_legacy_max_order_id' => '99999', 'admin_email' => 'owner@delicastoreha.com');
    $TRANSIENTS = array(); $ACTIONS = array(); $FILTERS = array(); $SCHEDULED_WP = array(); $AS = array(); $AS_REFUSE = array();
    $MAILS = array(); $REMOTE_POSTS = array(); $FIRED = array(); $ORDERS = array(); $CURRENT_USER = 7; $CAPS = array();
    $ITEM_STORE = array(); DFR_API::$calls = array(); DFR_API::$handlers = array();
    $wpdb->ledger = array(9001 => (object) array('user_id' => 7, 'type' => 'debit', 'deleted' => 0));
    set_private('post_response_fulfill', array());
    set_private('post_response_reconcile', array());
    set_private('delivery_summary_memo', array());
}
/** An encrypted fulfilment snapshot, as the checkout leaves it on a paid line. */
function sealed_request($order_id, $item_id, array $snapshot) {
    $snapshot = array_merge(array('v' => 2, 'category' => 'cat-1', 'offer' => 'off-1', 'quantity' => 1, 'fields' => array(), 'validation_category' => '', 'validation_fields' => array()), $snapshot);
    return DFR_Crypto::encrypt_context(json_encode($snapshot), 'fulfillment-request|order:' . $order_id . '|item:' . $item_id);
}
function api_calls($name) { return count(array_filter(DFR_API::$calls, function ($call) use ($name) { return $call === $name; })); }
function make_order($id, $status, array $items) {
    global $ORDERS;
    $order = new Test_Order($id, $status);
    foreach ($items as $item) { $item->order_id = $id; $order->items[$item->id] = $item; }
    $ORDERS[$id] = $order;
    return $order;
}
function jobs($hook) { global $AS; return array_values(array_filter($AS, function ($row) use ($hook) { return $row['hook'] === $hook; })); }

// ---- Scenarios ------------------------------------------------------------------------
echo "\n== background jobs: no `unique`, dedupe on arguments, WP-Cron fallback\n";
reset_world();
check(call_private('schedule_action', 'dfr_retry_order_item', array(1, 11, 1), time() + 5), 'a retry is scheduled');
check(call_private('schedule_action', 'dfr_retry_order_item', array(2, 21, 1), time() + 5), 'another order\'s retry is not blocked by it');
check(count($AS) === 2 && !$AS[0]['unique'] && !$AS[1]['unique'], 'both reach Action Scheduler with unique off');
call_private('schedule_action', 'dfr_retry_order_item', array(1, 11, 1), time() + 5);
check(count($AS) === 2, 'the same job (same arguments) is not added twice');
check(call_private('schedule_action', 'dfr_retry_order_item', array(1, 11, 2), time() + 9) && count($AS) === 3, 'the next attempt of a running retry is scheduled');
$AS_REFUSE = array('dfr_finalize_completed_order');
check(call_private('schedule_action', 'dfr_finalize_completed_order', array(3, 1), 0), 'a job Action Scheduler refuses...');
check(count($SCHEDULED_WP) === 1 && $SCHEDULED_WP[0][1] === 'dfr_finalize_completed_order', '...falls back to WP-Cron');

echo "\n== code extraction\n";
reset_world();
check(call_private('extract_codes', array('kind' => 'gift_card', 'product' => array('code' => 'PSN-US-10'), 'category' => array('code' => 'PSN'))) === array(), 'product.code and category.code are not delivery codes');
check(call_private('extract_codes', array('kind' => 'gift_card', 'cards' => array(array('code' => 'CARD-111', 'pin' => '0000')))) === array('CARD-111', '0000'), 'cards[].code and pin are delivered');
check(call_private('extract_codes', array('kind' => 'gift_card', 'code' => 'ROOT-CODE')) === array('ROOT-CODE'), 'a root code on a gift-card order is kept');
check(call_private('extract_codes', array('kind' => 'topup', 'code' => 'NOT-A-CODE')) === array(), 'a root code on a top-up is ignored');

echo "\n== delivered codes in the app\n";
reset_world();
$sealed = DFR_Crypto::encrypt_context(json_encode(array('GIFT-ABC-123')), 'delivery-code|order:4455|item:11');
check(is_string($sealed) && strpos($sealed, 'x2:') === 0, 'test code sealed with the real AEAD envelope');
$gift = new Test_Item(11, 4455, 'Carte PSN 10$', array(
    '_dfr_service_type' => 'giftcard', '_dfr_remote_order_id' => 'ord-1', '_dfr_remote_status' => 'completed',
    '_dfr_codes_enc' => $sealed, '_dfr_codes_count' => 1, '_dfr_delivery_authorized_at' => '2026-09-10T10:00:00Z',
));
$order = make_order(4455, 'completed', array($gift));
$codes = $plugin->app_order_codes(array(), $order, 'order');
check(count($codes) === 1 && $codes[0]['code'] === 'GIFT-ABC-123' && $codes[0]['label'] === 'Carte PSN 10$', 'the owner sees the code, labelled with the product');
check(count($plugin->app_order_codes(array(), $order, 'pins')) === 0, 'the bulk /pins context never decrypts');
check(count($plugin->app_order_codes(array(array('code' => 'KEEP')), $order, 'pins')) === 1, 'and keeps what other providers returned');
$CURRENT_USER = 8;
check(count($plugin->app_order_codes(array(), $order, 'order')) === 0, 'another customer gets nothing');
$CURRENT_USER = 7;
$order->refunded_qty[11] = -1;
check(count($plugin->app_order_codes(array(), $order, 'order')) === 0, 'a refunded line hides its code');
$order->refunded_qty = array();
$order->refunded_total = 100.0;
check(count($plugin->app_order_codes(array(), $order, 'order')) === 0, 'an amount-only refund hides every code');
$order->refunded_total = 0.0;
$order->status = 'on-hold';
check(count($plugin->app_order_codes(array(), $order, 'order')) === 0, 'an order on hold shows no code');
$order->status = 'completed';
$order->meta['_dfr_gateway_hold'] = gmdate('c');
$order->status = 'on-hold';
check(count($plugin->app_order_codes(array(), $order, 'order')) === 1, 'an order On hold by the gateway\'s own review hold still shows a delivered code');
unset($order->meta['_dfr_gateway_hold']);
$order->status = 'completed';
$TRANSIENTS = array('dfr_app_codes_rl_7' => array('count' => 60));
check(count($plugin->app_order_codes(array(), $order, 'order')) === 0, 'the per-customer decrypt budget is enforced');

echo "\n== definitive delivery failure (owner policy: On hold, no automatic refund)\n";
reset_world();
$topup = new Test_Item(21, 5001, 'Free Fire 110 diamants', array('_dfr_service_type' => 'topup'));
$order = make_order(5001, 'processing', array($topup));
$status_when_fired = '';
add_action('dfr_delivery_failed', function ($order_id, $item_id, $reason) use ($order, &$status_when_fired) { $status_when_fired = $order->status . ':' . $reason; }, 10, 3);
call_private('mark_item_delivery_failed', $order, 21, 'supplier_rejected');
check(($topup->meta['_dfr_delivery_state'] ?? '') === 'failed' && ($topup->meta['_dfr_delivery_failure'] ?? '') === 'supplier_rejected', 'the line is marked failed with a machine reason');
check($order->status === 'on-hold', 'the order is put On hold');
check($status_when_fired === 'processing:supplier_rejected', 'dfr_delivery_failed fires before the status change');
$customer_notes = array_values(array_filter($order->notes, function ($note) { return $note[1]; }));
check(count($customer_notes) === 1 && strpos($customer_notes[0][0], 'Aucun nouveau paiement') !== false, 'the customer is told once, in French, that the team is checking');
check(!in_array('woo_wallet_credit', $FIRED, true) && count(jobs('dfr_send_delivery_failure_alert')) === 1, 'no refund; an owner alert is queued in the background');
$notes_before = count($order->notes);
call_private('mark_item_delivery_failed', $order, 21, 'supplier_failed');
check(count($order->notes) === $notes_before && $topup->meta['_dfr_delivery_failure'] === 'supplier_rejected', 'a second failure signal changes nothing');
$plugin->send_delivery_failure_alert(5001, 21);
check(count($MAILS) === 1 && strpos($MAILS[0][1], '#5001') !== false && $MAILS[0][0] === 'owner@delicastoreha.com', 'the owner gets the alert e-mail');
$plugin->send_delivery_failure_alert(5001, 21);
check(count($MAILS) === 1, 'only once');

echo "\n== a failed line is never resent on its own\n";
$notes_before = count($order->notes);
call_private('fulfill_item', $order, 21, $topup);
check(count($order->notes) === $notes_before && !isset($topup->meta['_dfr_fulfill_attempt']), 'fulfill_item returns before any work');
check(!call_private('order_has_unsent_paid_items', $order), 'the watchdog does not count it as unsent');

echo "\n== an admin marks an undelivered order Completed\n";
reset_world();
add_action('woocommerce_order_status_changed', array($plugin, 'guard_manual_completion'), 5, 4);
$order = make_order(6001, 'processing', array(new Test_Item(31, 6001, 'Carte Netflix', array('_dfr_service_type' => 'giftcard', '_dfr_remote_order_id' => 'ord-9', '_dfr_remote_status' => 'processing'))));
$order->update_status('completed');
check(!empty($order->meta['_dfr_manual_completion_without_delivery']), 'it is recorded on the order');
check((bool) array_filter($order->notes, function ($note) { return strpos($note[0], 'Attention') === 0; }), 'with a note for the team');
check($plugin->app_order_status_label('Terminée', $order) === 'En cours de livraison', 'the app still says delivery is in progress');
$order = make_order(6002, 'processing', array(new Test_Item(32, 6002, 'Recharge', array('_dfr_service_type' => 'topup', '_dfr_remote_order_id' => 'ord-10', '_dfr_remote_status' => 'completed'))));
check(call_private('maybe_complete_woo_order', $order) === true && $order->status === 'completed', 'a delivered top-up completes automatically');
check(empty($order->meta['_dfr_manual_completion_without_delivery']), 'and automatic completion is not flagged as manual');

echo "\n== mixed carts\n";
reset_world();
$delivered_topup = function ($id, $order_id) { return new Test_Item($id, $order_id, 'Recharge PUBG', array('_dfr_service_type' => 'topup', '_dfr_remote_order_id' => 'ord-' . $id, '_dfr_remote_status' => 'completed')); };
$order = make_order(7001, 'processing', array($delivered_topup(41, 7001), new Test_Item(42, 7001, 'Guide PDF', array(), new Test_Product(true))));
check(call_private('maybe_complete_woo_order', $order) === true, 'a delivered gateway line plus a downloadable product completes');
$order = make_order(7002, 'processing', array($delivered_topup(43, 7002), new Test_Item(44, 7002, 'Compte Netflix (envoi manuel)', array(), new Test_Product(false))));
check(call_private('maybe_complete_woo_order', $order) === false && ($order->meta['_dfr_completion_block_reason'] ?? '') === 'untracked_line_44', 'a line delivered by hand keeps the order open');
check(call_private('delivery_summary', $order)['state'] === 'partial', 'and the app shows it as partially delivered');
$order = make_order(7003, 'processing', array(new Test_Item(45, 7003, 'Guide PDF', array(), new Test_Product(true))));
check(call_private('maybe_complete_woo_order', $order) === false && ($order->meta['_dfr_completion_block_reason'] ?? '') === 'no_gateway_lines', 'the gateway never completes an order it delivered nothing for');

echo "\n== delivery states for the app\n";
reset_world();
$line = new Test_Item(51, 8001, 'Carte Steam', array('_dfr_service_type' => 'giftcard'));
$order = make_order(8001, 'processing', array($line));
check(call_private('delivery_summary', $order)['state'] === 'queued', 'paid, not sent yet: queued');
$line->meta['_dfr_remote_order_id'] = 'ord-51';
$line->meta['_dfr_remote_status'] = 'processing';
check(call_private('delivery_summary', $order)['state'] === 'supplier_processing', 'sent: supplier_processing');
$line->meta['_dfr_remote_status'] = 'completed';
check(call_private('delivery_summary', $order)['state'] === 'waiting_code', 'supplier done, code not stored yet: waiting_code');
$line->meta['_dfr_codes_enc'] = DFR_Crypto::encrypt_context(json_encode(array('STEAM-1')), 'delivery-code|order:8001|item:51');
$line->meta['_dfr_codes_count'] = 1;
$line->meta['_dfr_delivery_authorized_at'] = gmdate('c');
$summary = call_private('delivery_summary', $order);
check($summary['state'] === 'delivered' && $summary['codes_available'] === 1, 'code stored: delivered, one code available to the owner');
$line->meta['_dfr_delivery_state'] = 'failed';
check(call_private('delivery_summary', $order)['state'] === 'needs_support', 'failed line: needs_support');
$timeline = $plugin->app_order_timeline(array(
    array('key' => 'received', 'title' => 'Commande reçue', 'done' => true),
    array('key' => 'preparing', 'title' => 'Traitement en cours', 'done' => true),
    array('key' => 'delivered', 'title' => 'Livré', 'done' => false),
), $order);
$last_step = end($timeline);
check($last_step['key'] === 'delivery_review', 'the timeline adds "Livraison à vérifier"');

echo "\n== checkout only queues the supplier pass\n";
reset_world();
$order = make_order(9001, 'processing', array(new Test_Item(61, 9001, 'Recharge', array('_dfr_service_type' => 'topup'))));
check($plugin->queue_fulfillment(9001, 'test') === true, 'queued');
check(isset(get_private('post_response_fulfill')[9001]), 'post-response pass registered');
check(count($REMOTE_POSTS) === 1 && $REMOTE_POSTS[0]['action'] === 'dfr_internal_fulfill' && strlen($REMOTE_POSTS[0]['token']) >= 32 && count($REMOTE_POSTS[0]) === 2, 'single-use loopback carries only a random token');
$jobs = jobs('dfr_fulfill_paid_order');
check(count($jobs) === 1 && $jobs[0]['args'] === array(9001, 1) && $jobs[0]['ts'] > time(), 'durable job scheduled a little later');
$plugin->queue_fulfillment(9001, 'test');
check(count($REMOTE_POSTS) === 1 && count(jobs('dfr_fulfill_paid_order')) === 1, 'a second trigger in the same request adds nothing');
$order->payment_method = 'bacs';
check($plugin->queue_fulfillment(9001, 'test') === false, 'non-wallet orders are not queued');
$order->payment_method = 'wallet';
$wpdb->ledger = array();
$REMOTE_POSTS = array(); $TRANSIENTS = array(); $AS = array();
set_private('post_response_fulfill', array());
check($plugin->queue_fulfillment(9001, 'test') === true && count($REMOTE_POSTS) === 0 && count(jobs('dfr_fulfill_paid_order')) === 1, 'before the payment proof exists: durable job only, no loopback');

echo "\n== manual-review hold keeps the other paid lines moving\n";
reset_world();
add_action('woocommerce_order_status_changed', array($plugin, 'clear_gateway_hold_on_status_change'), 1, 4);
$bad_topup = new Test_Item(101, 5101, 'Free Fire 110 diamants', array(
    '_dfr_service_type' => 'topup',
    '_dfr_fulfill_request_enc' => sealed_request(5101, 101, array('type' => 'topup', 'fields' => array('player_id' => '123'), 'validation_category' => 'val-ff')),
));
$psn = new Test_Item(102, 5101, 'Carte PSN 10$', array(
    '_dfr_service_type' => 'giftcard',
    '_dfr_fulfill_request_enc' => sealed_request(5101, 102, array('type' => 'giftcard')),
));
$order = make_order(5101, 'processing', array($bad_topup, $psn));
DFR_API::$handlers['validate_topup_id'] = function () { return array('ok' => true, 'valid' => false); };
DFR_API::$handlers['create_giftcard'] = function () { return array('order' => array('id' => 'ord-5102', 'status' => 'processing')); };
$plugin->maybe_auto_fulfill_order(5101);
check(($bad_topup->meta['_dfr_delivery_state'] ?? '') === 'failed' && $order->status === 'on-hold', 'line 1 fails for good: the order goes On hold at once');
check(($order->meta['_dfr_gateway_hold'] ?? '') !== '', 'with the gateway\'s own hold marker');
check(api_calls('create_giftcard') === 1 && ($psn->meta['_dfr_remote_order_id'] ?? '') === 'ord-5102', 'line 2 still reaches the supplier in the same pass');
check(api_calls('create_topup') === 0, 'the failed top-up is never sent');
DFR_API::$handlers['get_order'] = function () {
    return array('order' => array('id' => 'ord-5102', 'status' => 'completed', 'kind' => 'gift_card', 'cards' => array(array('code' => 'PSN-AAAA-BBBB'))));
};
check($plugin->process_verified_webhook('ord-5102', 'completed', 'evt-5102-0001', 0, true) === true, 'a signed webhook after the hold is processed');
check((int) ($psn->meta['_dfr_codes_count'] ?? 0) === 1 && ($psn->meta['_dfr_delivery_authorized_at'] ?? '') !== '', 'and the delivered code is stored');
$codes = $plugin->app_order_codes(array(), $order, 'order');
check(count($codes) === 1 && $codes[0]['code'] === 'PSN-AAAA-BBBB', 'the customer sees it while the order is On hold for the other line');
check(call_private('maybe_complete_woo_order', $order) === false && $order->status === 'on-hold', 'the failed line still blocks completion');
$order->update_status('processing');
check(!isset($order->meta['_dfr_gateway_hold']), 'a status change by the owner clears the marker');

echo "\n== an On hold set by the owner still blocks\n";
reset_world();
add_action('woocommerce_order_status_changed', array($plugin, 'clear_gateway_hold_on_status_change'), 1, 4);
$card = new Test_Item(111, 5111, 'Carte Steam', array(
    '_dfr_service_type' => 'giftcard',
    '_dfr_fulfill_request_enc' => sealed_request(5111, 111, array('type' => 'giftcard')),
));
$order = make_order(5111, 'on-hold', array($card));
DFR_API::$handlers['create_giftcard'] = function () { return array('order' => array('id' => 'ord-5111', 'status' => 'processing')); };
$plugin->maybe_auto_fulfill_order(5111);
check(count(DFR_API::$calls) === 0, 'no marker: nothing is sent to the supplier');
check(!call_private('order_has_verified_wallet_payment', $order), 'and the payment proof stays closed');

echo "\n== concurrent runners read fresh line meta under the order lock\n";
reset_world();
$line = new Test_Item(121, 5121, 'Carte Netflix', array(
    '_dfr_service_type' => 'giftcard',
    '_dfr_fulfill_request_enc' => sealed_request(5121, 121, array('type' => 'giftcard')),
));
$order = make_order(5121, 'processing', array($line));
// The other runner already stored its supplier order; this runner's copy predates it.
$ITEM_STORE[121] = array_merge($line->meta, array('_dfr_remote_order_id' => 'ord-121', '_dfr_remote_status' => 'processing'));
DFR_API::$handlers['create_giftcard'] = function () { return new WP_Error('dfr_http', 'Idempotency key already used', array('status' => 422)); };
call_private('fulfill_item', $order, 121, $line);
check(count(DFR_API::$calls) === 0, 'a line another runner already sent is not sent again');
check(count($order->notes) === 0 && ($line->meta['_dfr_delivery_state'] ?? '') === '' && $order->status === 'processing', 'no note, line not failed, order untouched');
$line = new Test_Item(122, 5122, 'Carte Xbox', array(
    '_dfr_service_type' => 'giftcard',
    '_dfr_fulfill_request_enc' => sealed_request(5122, 122, array('type' => 'giftcard')),
));
$order = make_order(5122, 'processing', array($line));
DFR_API::$handlers['create_giftcard'] = function () {
    global $ITEM_STORE;
    // While this POST is refused as a replay, the winner's supplier order id lands.
    $ITEM_STORE[122]['_dfr_remote_order_id'] = 'ord-122';
    return new WP_Error('dfr_http', 'Idempotency key already used', array('status' => 422));
};
call_private('fulfill_item', $order, 122, $line);
check(api_calls('create_giftcard') === 1 && ($line->meta['_dfr_delivery_state'] ?? '') === '' && $order->status === 'processing', 'a refused replay never marks a line failed once its supplier order exists');
$stale = make_order(5131, 'processing', array(new Test_Item(131, 5131, 'Recharge', array('_dfr_service_type' => 'topup'))));
$persisted = clone $stale;
$persisted->status = 'completed';
$ORDERS[5131] = $persisted;
call_private('mark_item_delivery_failed', $stale, 131, 'supplier_rejected');
check($persisted->status === 'completed' && $stale->status === 'processing' && empty($stale->meta['_dfr_gateway_hold']), 'a Completed order is never moved back to On hold from a stale copy');

echo "\n== a failed line can be resolved by the team\n";
reset_world();
$done = new Test_Item(141, 5141, 'Recharge PUBG', array('_dfr_service_type' => 'topup', '_dfr_remote_order_id' => 'ord-141', '_dfr_remote_status' => 'completed'));
$bad = new Test_Item(142, 5141, 'Carte Google Play', array('_dfr_service_type' => 'giftcard', '_dfr_delivery_state' => 'failed', '_dfr_delivery_failure' => 'uncertain_previous_attempt', '_dfr_fulfill_attempt' => 2));
$order = make_order(5141, 'on-hold', array($done, $bad));
$order->meta['_dfr_gateway_hold'] = gmdate('c');
check(!call_private('failed_line_relaunchable', $bad), '« Relancer » is never offered for an uncertain legacy attempt');
$bad->meta['_dfr_delivery_failure'] = 'integrity_check_failed';
$bad->meta['_dfr_fulfill_request_enc'] = 'x2:sealed';
check(!call_private('failed_line_relaunchable', $bad), 'nor for a cause a resend cannot fix');
$bad->meta['_dfr_delivery_failure'] = 'supplier_unreachable';
check(call_private('failed_line_relaunchable', $bad), 'but it is offered after supplier trouble, with the secure request kept');
$bad->meta['_dfr_delivery_state'] = 'refunded';
$summary = call_private('delivery_summary', $order);
check($summary['state'] === 'delivered' && $summary['items'][1]['state'] === 'refunded', 'a line marked refunded shows as refunded, the rest as delivered');
$bad->meta['_dfr_delivery_state'] = 'manual';
check(call_private('delivery_summary', $order)['state'] === 'delivered', 'a line delivered by hand counts as delivered, not « En vérification »');
check(call_private('maybe_complete_woo_order', $order) === true && $order->status === 'completed', 'and the order can complete');
call_private('mark_item_delivery_failed', $order, 142, 'supplier_failed');
check($bad->meta['_dfr_delivery_state'] === 'manual' && $order->status === 'completed', 'a late supplier signal never reopens a resolved line');

echo "\n== a refused wallet attestation is escalated\n";
reset_world();
$line = new Test_Item(151, 5151, 'Carte Amazon', array('_dfr_service_type' => 'giftcard'));
$order = make_order(5151, 'processing', array($line));
$plugin->capture_wallet_payment_attestation(5151, array('transaction_id' => 777));
check(($order->meta['_dfr_attestation_refused'] ?? '') !== '' && count(jobs('dfr_send_attestation_refusal_alert')) === 1, 'the order is flagged and the owner alert is queued');
check(count(array_filter($order->notes, function ($note) { return $note[1]; })) === 1, 'the customer is told once that the team is checking');
check(call_private('delivery_summary', $order)['state'] === 'needs_support', 'the app says « En vérification », not « Préparation » forever');
$plugin->send_attestation_refusal_alert(5151);
check(count($MAILS) === 1 && strpos($MAILS[0][1], '#5151') !== false, 'the owner gets an e-mail');
$plugin->send_attestation_refusal_alert(5151);
check(count($MAILS) === 1, 'only once');

echo "\n== lists never decrypt or spend the code budget\n";
reset_world();
$gift = new Test_Item(11, 4455, 'Carte PSN 10$', array(
    '_dfr_service_type' => 'giftcard', '_dfr_remote_order_id' => 'ord-1', '_dfr_remote_status' => 'completed',
    '_dfr_codes_enc' => $sealed, '_dfr_codes_count' => 1, '_dfr_delivery_authorized_at' => '2026-09-10T10:00:00Z',
));
$order = make_order(4455, 'completed', array($gift));
$TRANSIENTS['dfr_app_codes_rl_7'] = array('count' => 5);
$summary = $plugin->app_order_delivery(null, $order, 'summary');
check($summary['codes_available'] === 1 && $TRANSIENTS['dfr_app_codes_rl_7']['count'] === 5, 'the list summary counts codes without reading them');
check(count($plugin->app_order_codes(array(), $order, 'order')) === 1 && $TRANSIENTS['dfr_app_codes_rl_7']['count'] === 6, 'opening the order spends one read');
$plugin->app_order_codes(array(), $order, 'order');
$plugin->app_order_codes(array(), $order, 'order');
check($TRANSIENTS['dfr_app_codes_rl_7']['count'] === 6, 're-reading the same order while it is watched spends nothing more');

echo "\n== amount-only refunds\n";
reset_world();
$gift = new Test_Item(11, 4456, 'Carte PSN 10$', array(
    '_dfr_service_type' => 'giftcard', '_dfr_remote_order_id' => 'ord-1', '_dfr_remote_status' => 'completed',
    '_dfr_codes_enc' => DFR_Crypto::encrypt_context(json_encode(array('GIFT-XYZ')), 'delivery-code|order:4456|item:11'), '_dfr_codes_count' => 1, '_dfr_delivery_authorized_at' => gmdate('c'),
));
$gift->total = 300.0;
$failed_line = new Test_Item(12, 4456, 'Recharge Free Fire', array('_dfr_service_type' => 'topup', '_dfr_delivery_state' => 'failed'));
$failed_line->total = 150.0;
$order = make_order(4456, 'processing', array($gift, $failed_line));
$order->refunded_total = 150.0;
check(count($plugin->app_order_codes(array(), $order, 'order')) === 1, 'refunding the failed line by amount keeps the delivered code visible');
$order->refunded_total = 200.0;
$TRANSIENTS = array();
check(count($plugin->app_order_codes(array(), $order, 'order')) === 0, 'a refund larger than the undelivered lines hides it');
check(call_private('customer_delivery_block_reason', $order, 11, $gift, 7) === 'refunded', 'with a reason the website can explain');

echo "\n== several cards keep their field names\n";
reset_world();
$line = new Test_Item(161, 5161, 'Carte iTunes', array('_dfr_service_type' => 'giftcard', '_dfr_remote_order_id' => 'ord-161', '_dfr_remote_status' => 'processing'));
$order = make_order(5161, 'processing', array($line));
call_private('apply_remote_payload', $order, 161, array('order' => array(
    'id' => 'ord-161', 'status' => 'completed', 'kind' => 'gift_card',
    'cards' => array(array('code' => 'AAA-1', 'pin' => '0000'), array('code' => 'BBB-2', 'pin' => '0000')),
    'codes' => array('AAA-1'),
)));
check(call_private('decrypt_item_codes', $line) === array('AAA-1', '0000', 'BBB-2'), 'the stored flat list keeps its meaning');
$labels = array_column($plugin->app_order_codes(array(), $order, 'order'), 'label');
check($labels === array('Carte iTunes — Carte 1 — Code', 'Carte iTunes — Carte 1 — PIN', 'Carte iTunes — Carte 2 — Code', 'Carte iTunes — Carte 2 — PIN'), 'the app labels each value, and a PIN shared by two cards is not lost');
check(call_private('extract_codes', array('id' => 'ord-7', 'status' => 'completed', 'code' => 'ROOT-2'), 'giftcard') === array('ROOT-2'), 'a gift-card line takes a root code even when the supplier omits kind');
check(call_private('extract_codes', array('order_id' => 'ord-7', 'code' => 'ORDER_CREATED'), 'giftcard') === array(), 'but not from a response that is not the order object');
check(call_private('extract_codes', array('kind' => 'gift_card', 'code' => 'X-1'), 'topup') === array(), 'and a top-up line never takes a root code');
check($plugin->extract_delivery_codes(array('kind' => 'gift_card', 'product' => array('code' => 'PSN-US')), 'giftcard') === array(), 'the public extractor keeps the product.code rule');

echo "\n== orders this gateway does not deliver\n";
reset_world();
$order = make_order(5171, 'completed', array(new Test_Item(171, 5171, 'Carte PSN', array('_dfr_service_type' => 'giftcard'))));
$order->payment_method = 'delicat_reseller_wallet';
$order->meta['_dfr_reseller_api_order'] = '1';
check($plugin->app_order_delivery('untouched', $order, 'detail') === 'untouched', 'Reseller API mirror orders are left alone');
$order->update_status('completed');
check(empty($order->meta['_dfr_manual_completion_without_delivery']), 'and their completion is not flagged as manual');
$order = make_order(7004, 'processing', array(
    new Test_Item(46, 7004, 'Recharge PUBG', array('_dfr_service_type' => 'topup', '_dfr_remote_order_id' => 'ord-46', '_dfr_remote_status' => 'completed')),
    new Test_Item(47, 7004, 'Bon cadeau (envoi manuel)', array(), new Test_Product(true, false)),
));
check(call_private('maybe_complete_woo_order', $order) === false, 'a downloadable product without a file keeps the order open');

echo "\n== top-up quantity and Player ID check limits\n";
reset_world();
$line = new Test_Item(181, 5181, 'Recharge Free Fire', array(
    '_dfr_service_type' => 'topup',
    '_dfr_fulfill_request_enc' => sealed_request(5181, 181, array('type' => 'topup', 'quantity' => 2, 'fields' => array('player_id' => '1'))),
));
$order = make_order(5181, 'processing', array($line));
call_private('fulfill_item', $order, 181, $line);
check(($line->meta['_dfr_delivery_failure'] ?? '') === 'topup_quantity' && count(DFR_API::$calls) === 0, 'a top-up line with quantity 2 is held before any supplier call');
$line = new Test_Item(191, 5191, 'Recharge MLBB', array(
    '_dfr_service_type' => 'topup',
    '_dfr_fulfill_request_enc' => sealed_request(5191, 191, array('type' => 'topup', 'fields' => array('player_id' => '9'), 'validation_category' => 'val-mlbb')),
));
$order = make_order(5191, 'processing', array($line));
DFR_API::$handlers['validate_topup_id'] = function () { return new WP_Error('dfr_http', 'rate limited', array('status' => 429, 'retry_after' => 30)); };
for ($i = 0; $i < 8; $i++) { call_private('fulfill_item', $order, 191, $line, true); }
check(($line->meta['_dfr_delivery_state'] ?? '') === '' && (int) $line->meta['_dfr_uid_check_transient_count'] === 8 && count(jobs('dfr_retry_order_item')) === 8, 'a supplier rate limit retries without using up the six attempts');
check(count($order->notes) === 1, 'with one note, not one per retry');
$ITEM_STORE[191]['_dfr_uid_check_transient_since'] = gmdate('c', time() - 50 * MINUTE_IN_SECONDS);
call_private('fulfill_item', $order, 191, $line, true);
check(($line->meta['_dfr_delivery_failure'] ?? '') === 'player_id_check_unavailable', 'after 45 minutes the line is held for review');

echo "\n== supplier mapping is administrator-only\n";
reset_world();
check($plugin->guard_mapping_meta_update(null, 500, '_dfr_offer_id', 'expensive-card', '') === false, 'a shop manager cannot remap a product');
check($plugin->guard_mapping_meta_update(null, 500, '_price', '1', '') === null, 'other product meta is not affected');
$CAPS = array('manage_options');
check($plugin->guard_mapping_meta_update(null, 500, '_dfr_offer_id', 'card-2', '') === null && get_private('mapping_audit_total') === 1, 'an administrator can, and the change is recorded');
$CURRENT_USER = 0;
$CAPS = array();
check($plugin->guard_mapping_meta_add(null, 500, '_dfr_offer_id', 'card-3', false) === null, 'a background catalog sync (no user) can');

echo "\n== maintenance lock\n";
check(in_array('/delicat-gateway/v1/webhook', $plugin->maintenance_open_rest_routes(array(), '/delicat-gateway/v1/webhook'), true), 'the supplier webhook stays open');
check(in_array('dfr_internal_fulfill', $plugin->maintenance_allowed_ajax(array('heartbeat'), 'dfr_internal_fulfill'), true) && in_array('dfr_internal_reconcile', $plugin->maintenance_allowed_ajax(array(), ''), true), 'and so do the paid-order loopbacks');


echo "\n== v4.24 supplier latency: queue priority, reliable loopbacks, late webhook settlement\n";
reset_world();
$GLOBALS['REMOTE_ARGS'] = array();
call_private('schedule_action', 'dfr_reconcile_plan_tick', array(1, 2, 'ord-1', 1, 'p'), time() + 5);
call_private('schedule_action', 'dfr_catalog_sync_batch', array(2), time() + 5);
check($AS[0]['priority'] === 0 && $AS[1]['priority'] === 150, 'order status jobs run before catalog batches in the shared queue');
check(DFR_Plugin::action_priority('dfr_process_verified_webhook') === 0 && DFR_Plugin::action_priority('dfr_keyring_migration_batch') === 150, 'webhook retries are urgent, migrations are not');

// Host without fastcgi/litespeed_finish_request: the loopback leaves at once, with a
// timeout long enough for TLS + WordPress bootstrap (v4.23: 0.5 s, silently lost).
check(call_private('dispatch_reconcile_loopback', 7001, 71, 'ord-71', 1) === true, 'loopback dispatched without response flushing');
check(count($GLOBALS['REMOTE_ARGS']) === 1 && $GLOBALS['REMOTE_ARGS'][0]['timeout'] >= 1.5, 'user-facing loopback waits at least 1.5 s for the worker to accept');

// LiteSpeed host: loopbacks wait until this request's own response is flushed.
$GLOBALS['FINISHED'] = 0;
// Declared conditionally so PHP does not hoist it over the earlier scenarios.
if (!function_exists('litespeed_finish_request')) { function litespeed_finish_request() { $GLOBALS['FINISHED']++; return true; } }
function status_header($code) { $GLOBALS['STATUS'] = $code; }
reset_world();
$GLOBALS['REMOTE_ARGS'] = array(); $GLOBALS['AS_UNSCHEDULED'] = array();
set_private('response_released', false); set_private('deferred_loopbacks', array()); set_private('post_response_webhooks', array());
check(call_private('dispatch_reconcile_loopback', 7002, 72, 'ord-72', 1) === true && count($GLOBALS['REMOTE_ARGS']) === 0, 'during the customer request the loopback is deferred, not sent');
check(count(get_private('deferred_loopbacks')) === 1, 'it is remembered for after the response');
ob_start(function ($buffer) { return ''; }); $plugin->run_post_response_reconcile(); if (ob_get_level() > 0) { ob_end_clean(); }
check($GLOBALS['FINISHED'] === 1, 'the response is flushed with litespeed_finish_request()');
check(count($GLOBALS['REMOTE_ARGS']) === 1 && $GLOBALS['REMOTE_ARGS'][0]['timeout'] >= 3 && $GLOBALS['REMOTE_ARGS'][0]['body']['action'] === 'dfr_internal_reconcile', 'then the loopback leaves with a patient timeout');

// A worker answers its caller before any supplier I/O.
set_private('response_released', false);
$GLOBALS['CAPTURED'] = '';
ob_start(function ($buffer) { $GLOBALS['CAPTURED'] .= $buffer; return ''; });
$released = call_private('release_loopback_caller');
if (ob_get_level() > 0) { ob_end_clean(); }
check($released === true && strpos($GLOBALS['CAPTURED'], '"accepted":true') !== false && get_private('response_released') === true, 'loopback worker acknowledges its caller before any supplier I/O');

// Signed "completed" webhook while the supplier read still says processing (read lag).
if (!defined('DELICAT_FAZER_WEBHOOK_SECRET')) { define('DELICAT_FAZER_WEBHOOK_SECRET', 'whsec_test'); }
class WP_REST_Request {
    private $headers; private $body;
    function __construct($headers, $body) { $this->headers = array_change_key_case($headers); $this->body = $body; }
    function get_header($k) { return $this->headers[strtolower($k)] ?? ''; }
    function get_body() { return $this->body; }
}
class WP_REST_Response { public $data; public $status; function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; } }
reset_world();
$GLOBALS['REMOTE_ARGS'] = array(); $GLOBALS['AS_UNSCHEDULED'] = array();
set_private('response_released', false); set_private('deferred_loopbacks', array()); set_private('post_response_webhooks', array());
$line = new Test_Item(81, 7081, 'Recharge Free Fire', array('_dfr_service_type' => 'topup', '_dfr_remote_order_id' => 'ord-81', '_dfr_remote_status' => 'processing'));
$order = make_order(7081, 'processing', array($line));
$ITEM_STORE[81] = $line->meta;
update_option('dfr_remote_' . md5('ord-81'), array('remote' => 'ord-81', 'order_id' => 7081, 'item_id' => 81));
$reads = 0;
DFR_API::$handlers['get_order'] = function () use (&$reads) {
    $reads++;
    return array('order' => array('id' => 'ord-81', 'status' => $reads === 1 ? 'processing' : 'completed'));
};
$body = json_encode(array('event' => 'order.status_changed', 'event_id' => 'evt-81-000001', 'timestamp' => gmdate('c'), 'data' => array('order_id' => 'ord-81', 'status' => 'completed')));
$req = new WP_REST_Request(array('x-webhook-signature' => 'sha256=' . hash_hmac('sha256', $body, 'whsec_test')), $body);
$res = $plugin->handle_webhook($req);
check($res->status === 200 && !empty($res->data['queued']), 'the supplier gets 200 at once, the canonical read lagged');
check(count(get_private('post_response_webhooks')) === 1, 'the signed event is kept for after the response');
check(count(jobs('dfr_process_verified_webhook')) === 1, 'and the durable queue job still exists');
$t0 = microtime(true);
ob_start(function ($buffer) { return ''; }); $plugin->run_post_response_reconcile(); if (ob_get_level() > 0) { ob_end_clean(); }
$elapsed = microtime(true) - $t0;
check(($ITEM_STORE[81]['_dfr_remote_status'] ?? '') === 'completed', 'the order line is completed by the late settlement, not by cron');
check($reads === 2, 'with exactly one more supplier read');
check($elapsed < 15, sprintf('within seconds of the webhook (%.1f s)', $elapsed));
check(!empty($GLOBALS['AS_UNSCHEDULED']) && $GLOBALS['AS_UNSCHEDULED'][0][0] === 'dfr_process_verified_webhook', 'the redundant queued job is cancelled');

// Reseller/unknown supplier orders never hold a worker for late settlement.
reset_world();
set_private('response_released', false); set_private('deferred_loopbacks', array()); set_private('post_response_webhooks', array());
DFR_API::$handlers['get_order'] = function () { return new WP_Error('dfr_http', 'timeout', array('status' => 504)); };
$body = json_encode(array('event' => 'order.status_changed', 'event_id' => 'evt-99-000001', 'timestamp' => gmdate('c'), 'data' => array('order_id' => 'ord-99', 'status' => 'completed')));
$plugin->handle_webhook(new WP_REST_Request(array('x-webhook-signature' => 'sha256=' . hash_hmac('sha256', $body, 'whsec_test')), $body));
check(count(get_private('post_response_webhooks')) === 0, 'an unmapped supplier order is left to its own bridge');

echo "\n" . ($failures ? "$failures FAILURE(S)" : 'ALL CHECKS PASSED') . "\n";
exit($failures ? 1 : 0);
