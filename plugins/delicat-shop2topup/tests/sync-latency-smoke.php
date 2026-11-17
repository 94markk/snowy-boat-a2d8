<?php
/**
 * Supplier-confirmation latency smoke test (2.9.0), against stubbed WordPress and
 * WooCommerce. No database, no network.
 *
 *   php tests/sync-latency-smoke.php
 *
 * Guards:
 *  - paid-order jobs outrank catalog/mapping jobs in Action Scheduler;
 *  - the post-response lane works on LiteSpeed (litespeed_finish_request), not only PHP-FPM;
 *  - detached loopbacks wait for the customer's response and use a patient timeout;
 *  - a signed "completed" callback that loses the order-lock race is settled by the same
 *    worker once the lock frees, and the redundant queued job is cancelled.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$OPTIONS = array(); $TRANSIENTS = array(); $AS = array(); $AS_CANCELLED = array(); $REMOTE = array(); $FINISHED = 0; $LOGS = array();

function __($s, $d = '') { return $s; }
function absint($v) { return abs((int) $v); }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function get_option($k, $d = false) { global $OPTIONS; return array_key_exists($k, $OPTIONS) ? $OPTIONS[$k] : $d; }
function update_option($k, $v, $a = null) { global $OPTIONS; $OPTIONS[$k] = $v; return true; }
function get_transient($k) { global $TRANSIENTS; return $TRANSIENTS[$k] ?? false; }
function set_transient($k, $v, $t = 0) { global $TRANSIENTS; $TRANSIENTS[$k] = $v; return true; }
function delete_transient($k) { global $TRANSIENTS; unset($TRANSIENTS[$k]); return true; }
function wp_doing_cron() { return false; }
function doing_action($h = null) { return false; }
function admin_url($p = '', $scheme = null) { return 'https://delicastoreha.com/wp-admin/' . $p; }
function wp_safe_remote_post($url, $args) { global $REMOTE; $REMOTE[] = $args; return array('response' => array('code' => 200)); }
function is_wp_error($x) { return false; }
function wp_next_scheduled($h, $a = array()) { return false; }
function wp_schedule_single_event($ts, $h, $a = array()) { return true; }
function wp_clear_scheduled_hook($h, $a = array()) { return 0; }
function wp_cache_delete($k, $g = '') { return true; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function sanitize_title($v) { return strtolower((string) $v); }
function clean_post_cache($id) {}
function wc_get_logger() { return new class { function log($l, $m, $c = array()) { $GLOBALS['LOGS'][] = $m; } function warning($m, $c = array()) {} }; }
function as_get_scheduled_actions($q, $ret = 'ids') {
    global $AS; $out = array();
    foreach ($AS as $id => $row) { if ($row['hook'] === $q['hook'] && $row['args'] == $q['args']) { $out[] = $id; } }
    return $out;
}
function as_schedule_single_action($ts, $h, $args = array(), $g = '', $unique = false, $priority = 10) { global $AS; $AS[] = array('hook' => $h, 'args' => $args, 'ts' => $ts, 'priority' => $priority); return count($AS); }
function as_enqueue_async_action($h, $args = array(), $g = '', $unique = false, $priority = 10) { return as_schedule_single_action(time(), $h, $args, $g, $unique, $priority); }
function as_unschedule_all_actions($h, $args = array(), $g = '') {
    global $AS, $AS_CANCELLED;
    $AS_CANCELLED[] = array($h, $args);
    foreach ($AS as $id => $row) { if ($row['hook'] === $h && $row['args'] == $args) { unset($AS[$id]); } }
}

// Options table for the INSERT IGNORE order lock.
class WPDB_Stub {
    public $options = 'wp_options';
    function prepare($q, ...$a) { return array($q, $a); }
    function query($p) {
        global $OPTIONS;
        list($q, $a) = $p;
        if (stripos($q, 'INSERT IGNORE') === 0) { if (array_key_exists($a[0], $OPTIONS)) { return 0; } $OPTIONS[$a[0]] = $a[1]; return 1; }
        if (stripos($q, 'UPDATE') === 0) { if (($OPTIONS[$a[1]] ?? null) === $a[2]) { $OPTIONS[$a[1]] = $a[0]; return 1; } return 0; }
        if (stripos($q, 'DELETE') === 0) { if (($OPTIONS[$a[0]] ?? null) === $a[1]) { unset($OPTIONS[$a[0]]); return 1; } return 0; }
        return 0;
    }
    function get_var($p) { global $OPTIONS; list($q, $a) = $p; return stripos($q, 'SELECT option_value') === 0 ? ($OPTIONS[$a[0]] ?? null) : '1'; }
}
$wpdb = new WPDB_Stub();

class WC_Order_Item_Product {
    public $id; public $meta;
    function __construct($id, $meta) { $this->id = $id; $this->meta = $meta; }
    function get_id() { return $this->id; }
    function get_meta($k, $s = true) { return $this->meta[$k] ?? ''; }
    function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    function add_meta_data($k, $v, $u = false) { $this->meta[$k] = $v; }
    function save() {}
    function get_variation_id() { return 0; }
    function get_product_id() { return 10; }
    function get_quantity() { return 1; }
}
class WC_Order {}
class Test_Order extends WC_Order {
    public $id; public $status = 'processing'; public $items = array(); public $notes = array(); public $meta = array();
    function __construct($id) { $this->id = $id; }
    function get_id() { return $this->id; }
    function get_item($id) { return $this->items[$id] ?? false; }
    function get_items($t = 'line_item') { return $this->items; }
    function has_status($s) { return in_array($this->status, (array) $s, true); }
    function update_status($s, $note = '') { $this->status = $s; $this->notes[] = $note; }
    function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
    function get_meta($k, $s = true) { return $this->meta[$k] ?? ''; }
    function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    function add_order_note($n) { $this->notes[] = $n; }
    function save() {}
}
$ORDERS = array();
function wc_get_order($id) { global $ORDERS; return $ORDERS[$id] ?? false; }

require_once dirname(__DIR__) . '/includes/class-dst2t-scheduler.php';
class DST2T_Brand { static function label() { return 'Supplier'; } }
require_once dirname(__DIR__) . '/includes/class-dst2t-fulfillment.php';

$failures = 0;
function check($cond, $label) { global $failures; echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n"; if (!$cond) { $failures++; } }

// Collaborators: only what the exercised paths read.
class Fake_Repo {
    public $rows = array();
    function get($id) { return $this->rows[$id] ?? null; }
    function is_stale_event($id, $ts) { return false; }
    function update($id, $status, $args = array()) { $this->rows[$id]['status'] = $status; return true; }
}
class Fake_Api { function normalize_order_payload($payload, $id) { return array('order_id' => $id, 'status' => $payload['status']); } }
class Fake_Settings { function enabled($k) { return in_array($k, array('auto_complete'), true); } }

$ref = new ReflectionClass('DST2T_Fulfillment');
$f = $ref->newInstanceWithoutConstructor();
$repo = new Fake_Repo();
foreach (array('api' => new Fake_Api(), 'settings' => new Fake_Settings(), 'repository' => $repo, 'products' => null, 'vault' => null) as $prop => $value) {
    $p = $ref->getProperty($prop); $p->setValue($f, $value);
}
function prop($name, $value = null) {
    global $ref, $f;
    $p = $ref->getProperty($name);
    if (func_num_args() > 1) { $p->setValue($f, $value); }
    return $p->getValue($f);
}
function call($name, ...$args) { global $ref, $f; $m = $ref->getMethod($name); return $m->invokeArgs($f, $args); }

echo "== queue priority\n";
check(DST2T_Scheduler::priority('dst2t_process_item') === 0 && DST2T_Scheduler::priority('dst2t_webhook_reconcile') === 0, 'delivery and callback jobs are urgent');
check(DST2T_Scheduler::priority('dst2t_catalog_import_process') > DST2T_Scheduler::priority('dst2t_refresh_balance_now'), 'catalog jobs come last');
DST2T_Scheduler::enqueue('dst2t_process_item', array(1, 2), 20);
DST2T_Scheduler::enqueue('dst2t_refresh_mapping_async', array(9), 5);
check(end($AS)['priority'] === 120 && reset($AS)['priority'] === 0, 'priority reaches Action Scheduler');

echo "\n== host without response flushing (mod_php / CGI)\n";
check(DST2T_Scheduler::can_finish_response() === false, 'no finish function in CLI');
$REMOTE = array();
check(call('dispatch_fast_item', 501, 51) === true, 'line dispatched');
check(count($REMOTE) === 1 && $REMOTE[0]['timeout'] >= 1.5, 'loopback leaves at once with a timeout long enough to be delivered (was 0.5 s)');

echo "\n== LiteSpeed host\n";
if (!function_exists('litespeed_finish_request')) { function litespeed_finish_request() { $GLOBALS['FINISHED']++; return true; } }
$TRANSIENTS = array(); $REMOTE = array();
prop('post_response_items', array()); prop('deferred_loopbacks', array()); prop('response_released', false);
check(DST2T_Scheduler::can_finish_response(), 'litespeed_finish_request() is recognised');
check(call('dispatch_fast_item', 502, 52) === true && count($REMOTE) === 0, 'the customer request does not wait on a loopback');
check(count(prop('post_response_items')) === 1, 'this worker runs the line after the response instead');
// A third line beyond the two this worker handles still gets a detached runner, after the flush.
prop('post_response_items', array('1:1' => array(1, 1), '2:2' => array(2, 2)));
call('dispatch_fast_item', 503, 53);
check(count($REMOTE) === 0 && count(prop('deferred_loopbacks')) === 1, 'extra lines queue a loopback for after the response');

echo "\n== signed callback that lost the order-lock race\n";
$uuid = '11111111-2222-4333-8444-555555555555';
$order = new Test_Order(700);
$order->items[70] = new WC_Order_Item_Product(70, array('_dst2t_order_id' => $uuid, '_dst2t_status' => 'processing', '_dst2t_returns_voucher' => 'no'));
$ORDERS[700] = $order;
$repo->rows[$uuid] = array('provider_order_id' => $uuid, 'wc_order_id' => 700, 'wc_item_id' => 70, 'status' => 'processing', 'last_event_at' => '');
// The create worker still holds the order lock for ~2 s.
$OPTIONS['dst2t_lock_' . md5('order-700')] = 'other-worker|' . (time() + 2);
$applied = call('apply_signed_webhook', $uuid, array('status' => 'completed'), time());
check($applied === false, 'the 1.5 s fast path gives up while the lock is held');
check(call('defer_signed_webhook', $uuid, array('status' => 'completed'), 1234) === true, 'the signed state is kept for after the response');
DST2T_Scheduler::enqueue('dst2t_webhook_reconcile', array($uuid, 1234), 0);
prop('post_response_items', array()); $REMOTE = array();
$t0 = microtime(true);
ob_start(function ($b) { return ''; });
$f->run_post_response_fastlane();
if (ob_get_level() > 0) { ob_end_clean(); }
$elapsed = microtime(true) - $t0;
check($FINISHED >= 1, 'the response was flushed first');
check(count($REMOTE) === 1 && $REMOTE[0]['timeout'] >= 3, 'deferred loopback sent after the flush with a patient timeout');
check($order->items[70]->meta['_dst2t_status'] === 'completed' && $order->status === 'completed', 'the WooCommerce order completes as soon as the lock frees');
check($elapsed < 6, sprintf('in seconds, not at the next cron run (%.1f s)', $elapsed));
$cancelled = array_filter($AS_CANCELLED, function ($row) use ($uuid) { return $row[0] === 'dst2t_webhook_reconcile' && $row[1] == array($uuid, 1234); });
check(count($cancelled) === 1, 'the redundant queued re-read is cancelled');

echo "\n" . ($failures ? "$failures FAILURE(S)" : 'ALL CHECKS PASSED') . "\n";
exit($failures ? 1 : 0);
