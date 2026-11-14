<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * 2.9.0 audit regressions, against stubbed WordPress/WooCommerce. No database,
 * no network.
 *
 *   php tests/audit-290-smoke.php
 *
 * Guards:
 *  - a cost-guard refusal on the price fallback puts the line on review (v2.8 threw
 *    it out of a catch block, so the line was silently re-priced every minute);
 *  - the catalog fallback no longer stores its own price as the guard baseline
 *    before the guard runs;
 *  - the normal cached-price fallback still submits with the cached maximum;
 *  - player-verification throttles use fixed windows and include a site ceiling;
 *  - recurring-job checks only run on admin, cron and CLI requests.
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$OPTIONS = array(); $TRANSIENTS = array(); $TTL = array(); $AS = array(); $POST_META = array(); $LOGS = array();
$IS_ADMIN = false; $DOING_AJAX = false; $DOING_CRON = false;

function __($s, $d = '') { return $s; }
function absint($v) { return abs((int) $v); }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function wp_unslash($v) { return $v; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function get_option($k, $d = false) { global $OPTIONS; return array_key_exists($k, $OPTIONS) ? $OPTIONS[$k] : $d; }
function update_option($k, $v, $a = null) { global $OPTIONS; $OPTIONS[$k] = $v; return true; }
function get_transient($k) { global $TRANSIENTS; return $TRANSIENTS[$k] ?? false; }
function set_transient($k, $v, $t = 0) { global $TRANSIENTS, $TTL; $TRANSIENTS[$k] = $v; $TTL[$k] = $t; return true; }
function delete_transient($k) { global $TRANSIENTS; unset($TRANSIENTS[$k]); return true; }
function wp_doing_cron() { return $GLOBALS['DOING_CRON']; }
function wp_doing_ajax() { return $GLOBALS['DOING_AJAX']; }
function is_admin() { return $GLOBALS['IS_ADMIN']; }
function doing_action($h = null) { return false; }
function current_user_can($c) { return false; }
function get_current_user_id() { return 0; }
function get_the_title($id) { return 'Free Fire'; }
function update_post_meta($id, $k, $v) { global $POST_META; $POST_META[$id][$k] = $v; return true; }
function wp_next_scheduled($h, $a = array()) { return false; }
function wp_schedule_single_event($ts, $h, $a = array()) { return true; }
function wp_cache_delete($k, $g = '') { return true; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function wc_get_logger() { return new class { function log($l, $m, $c = array()) { $GLOBALS['LOGS'][] = $m; } function warning($m, $c = array()) {} }; }
function as_get_scheduled_actions($q, $ret = 'ids') { return array(); }
function as_schedule_single_action($ts, $h, $args = array(), $g = '', $unique = false, $priority = 10) { global $AS; $AS[] = array($h, $args); return count($AS); }
function as_enqueue_async_action($h, $args = array(), $g = '', $unique = false, $priority = 10) { return as_schedule_single_action(time(), $h, $args, $g, $unique, $priority); }

class WPDB_Stub {
    public $options = 'wp_options';
    function prepare($q, ...$a) { return array($q, $a); }
    function query($p) {
        global $OPTIONS; list($q, $a) = $p;
        if (stripos($q, 'INSERT IGNORE') === 0) { if (array_key_exists($a[0], $OPTIONS)) { return 0; } $OPTIONS[$a[0]] = $a[1]; return 1; }
        if (stripos($q, 'DELETE') === 0) { if (($OPTIONS[$a[0]] ?? null) === $a[1]) { unset($OPTIONS[$a[0]]); return 1; } return 0; }
        return 0;
    }
    function get_var($p) { global $OPTIONS; list($q, $a) = $p; return $OPTIONS[$a[0]] ?? null; }
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
class WC_Order {
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

require_once dirname(__DIR__) . '/includes/class-dst2t-api-exception.php';
require_once dirname(__DIR__) . '/includes/class-dst2t-decimal.php';
require_once dirname(__DIR__) . '/includes/class-dst2t-scheduler.php';
class DST2T_Brand { static function label() { return 'Supplier'; } }
require_once dirname(__DIR__) . '/includes/class-dst2t-fulfillment.php';
require_once dirname(__DIR__) . '/includes/class-dst2t-product.php';

$failures = 0;
function check($cond, $label) { global $failures; echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n"; if (!$cond) { $failures++; } }

class Fake_Repo {
    public $rows = array();
    function get($id) { return $this->rows[$id] ?? null; }
    function register_intent($id, $o, $i, $h) { return true; }
    function update($id, $status, $args = array()) { $this->rows[$id]['status'] = $status; if (array_key_exists('next_check_at', $args)) { $this->rows[$id]['next_check_at'] = $args['next_check_at'] ? gmdate('Y-m-d H:i:s', $args['next_check_at']) : null; } return true; }
}
class Fake_Api {
    public $created = array(); public $catalog = '2.000000';
    function price($id) { throw new DST2T_API_Exception('bad shape', 'INVALID_RESPONSE'); }
    function catalog_price($id, $cat) { return $this->catalog; }
    function create_order($uuid, $item, $qty, $req, $expected) { $this->created[] = $expected; throw new DST2T_API_Exception('busy', 'SERVICE_UNAVAILABLE', 503); }
}
class Fake_Products {
    function mapping($id) {
        global $POST_META;
        return array('last_cost' => $POST_META[$id]['_dst2t_last_cost_usd'] ?? '', 'max_cost' => $POST_META[$id]['_dst2t_max_cost_usd'] ?? '', 'returns_voucher' => false);
    }
}
class Fake_Settings {
    public $flags = array('woocommerce_payment_gate' => false, 'validate_player' => false);
    function enabled($k) { return !empty($this->flags[$k]); }
    function get($k, $d = null) { return 'cost_guard_percent' === $k ? '10.000000' : $d; }
}

$ref = new ReflectionClass('DST2T_Fulfillment');
$f = $ref->newInstanceWithoutConstructor();
$repo = new Fake_Repo(); $api = new Fake_Api();
foreach (array('api' => $api, 'settings' => new Fake_Settings(), 'repository' => $repo, 'products' => new Fake_Products(), 'vault' => null) as $prop => $value) {
    $p = $ref->getProperty($prop); $p->setValue($f, $value);
}

function paid_line($order_id, $item_id, $uuid) {
    global $ORDERS, $repo;
    $order = new WC_Order($order_id);
    $order->items[$item_id] = new WC_Order_Item_Product($item_id, array('_dst2t_order_id' => $uuid, '_dst2t_item_id' => 555, '_dst2t_product_id' => 77, '_dst2t_category_id' => 9, '_dst2t_status' => 'intent', '_dst2t_returns_voucher' => 'no'));
    $ORDERS[$order_id] = $order;
    $repo->rows[$uuid] = array('provider_order_id' => $uuid, 'wc_order_id' => $order_id, 'wc_item_id' => $item_id, 'status' => 'intent', 'attempt_count' => 0, 'next_check_at' => null, 'payload_hash' => '');
    return $order;
}

echo "== price fallback refused by the absolute ceiling\n";
$POST_META[77] = array('_dst2t_last_cost_usd' => '1.000000', '_dst2t_max_cost_usd' => '0.500000');
$order = paid_line(801, 81, 'aaaaaaaa-1111-4111-8111-111111111111');
$LOGS = array();
$f->process_item(801, 81);
check($repo->rows['aaaaaaaa-1111-4111-8111-111111111111']['status'] === 'price_blocked', 'the line is put on price review');
check($order->status === 'on-hold' && !$api->created, 'the order is held and nothing is bought');
check(!in_array('Unexpected fulfillment exception.', $LOGS, true), 'no unhandled exception (v2.8 left the line in intent and retried every minute)');

echo "\n== catalog fallback against a zero baseline\n";
$POST_META[77] = array('_dst2t_last_cost_usd' => '0.000000', '_dst2t_max_cost_usd' => '');
$order = paid_line(802, 82, 'bbbbbbbb-2222-4222-8222-222222222222');
$f->process_item(802, 82);
check($repo->rows['bbbbbbbb-2222-4222-8222-222222222222']['status'] === 'price_blocked' && !$api->created, 'the guard runs before the catalog price is learned');
check($POST_META[77]['_dst2t_last_cost_usd'] === '0.000000', 'the refused catalog price is not stored as the new baseline');

echo "\n== normal cached-price fallback\n";
$POST_META[77] = array('_dst2t_last_cost_usd' => '1.000000', '_dst2t_max_cost_usd' => '');
$order = paid_line(803, 83, 'cccccccc-3333-4333-8333-333333333333');
$f->process_item(803, 83);
check($api->created === array('1.000000'), 'the supplier order is sent with the cached price as its maximum');
check($repo->rows['cccccccc-3333-4333-8333-333333333333']['status'] === 'unknown', 'a temporary create error stays in recovery');

echo "\n== catalog fallback without a learned cost\n";
$POST_META[77] = array();
$api->created = array();
$order = paid_line(804, 84, 'dddddddd-4444-4444-8444-444444444444');
$f->process_item(804, 84);
check($api->created === array('2.000000') && ($POST_META[77]['_dst2t_last_cost_usd'] ?? '') === '2.000000', 'the catalog price is used and remembered once it passed the guard');

echo "\n== player verification throttle\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$pref = new ReflectionClass('DST2T_Product');
$product = $pref->newInstanceWithoutConstructor();
$throttled = $pref->getMethod('validation_throttled');
$TRANSIENTS = array();
$hits = 0;
for ($i = 0; $i < DST2T_Product::SITE_LIMIT; $i++) { if ($throttled->invoke($product)) { $hits++; } }
check($hits === 0, 'normal traffic passes');
check($throttled->invoke($product) === true, 'the site-wide ceiling stops a script that forges its address');
check(!array_filter(array_keys($TRANSIENTS), function ($k) { return 0 === strpos($k, 'dst2t_pv_v_'); }), 'no per-request rows for visitors without a session');
$TRANSIENTS['dst2t_pv_site'] = array('n' => DST2T_Product::SITE_LIMIT, 't' => time() - 61);
check($throttled->invoke($product) === false, 'the window ends on time even under continuous traffic');
check($TTL['dst2t_pv_site'] <= 60 && $TTL['dst2t_pv_site'] >= 1, 'the expiry is the rest of the window, not refreshed by each attempt');
$TRANSIENTS['dst2t_pv_a_' . md5('203.0.113.7')] = array('n' => DST2T_Product::ADDRESS_LIMIT, 't' => time() - 10);
check($throttled->invoke($product) === true, 'a full address bucket still throttles inside its window');

echo "\n== recurring-job checks\n";
$IS_ADMIN = false; $DOING_AJAX = false; $DOING_CRON = false;
check(DST2T_Scheduler::housekeeping_request() === false, 'storefront request: skipped');
$IS_ADMIN = true; $DOING_AJAX = true;
check(DST2T_Scheduler::housekeeping_request() === false, 'storefront admin-ajax call: skipped');
$DOING_AJAX = false;
check(DST2T_Scheduler::housekeeping_request() === true, 'wp-admin page: checked');
$IS_ADMIN = false; $DOING_CRON = true;
check(DST2T_Scheduler::housekeeping_request() === true, 'WP-Cron: checked');

echo "\n" . ($failures ? "$failures FAILURE(S)" : 'ALL CHECKS PASSED') . "\n";
exit($failures ? 1 : 0);
