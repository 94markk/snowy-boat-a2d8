<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Offline orchestration regression test; no WordPress database or supplier calls. */
define('ABSPATH', __DIR__ . '/');
$meta = array(); $products = array(); $hooks = array(); $notices = array();
function add_action($tag, $cb, $priority = 10, $args = 1) { global $hooks; $hooks[$tag][] = $cb; }
function add_filter($tag, $cb, $priority = 10, $args = 1) { add_action($tag, $cb, $priority, $args); }
function get_post_meta($id, $key, $single = true) { global $meta; return $meta[$id][$key] ?? ($single ? '' : array()); }
function absint($v) { return abs((int) $v); }
function wc_get_product($id) { global $products; return $products[$id] ?? null; }
function apply_filters($tag, $value, ...$args) { return $value; }
function is_wp_error($v) { return false; }
function wp_json_encode($v) { return json_encode($v); }
function wp_generate_uuid4() { static $i = 0; return 'group-' . ++$i; }
function wc_get_price_decimals() { return 2; }
function wc_add_notice($m, $t) { global $notices; $notices[] = $m; }
function WC() { global $wc; return $wc; }
class Product {
    public $id; public $parent; public $stock = 999; public $active = true;
    function __construct($id, $parent = 100) { $this->id = $id; $this->parent = $parent; }
    function get_id() { return $this->id; }
    function is_type($t) { return $t === 'variation'; }
    function get_parent_id() { return $this->parent; }
    function is_purchasable() { return $this->active; }
    function is_in_stock() { return $this->stock > 0; }
    function has_enough_stock($q) { return $this->stock >= $q; }
    function is_sold_individually() { return true; }
    function get_variation_attributes() { return array('attribute_package' => (string) $this->id); }
}
class WC_Order_Item_Product {
    public $id = 0; public $pid = 100; public $vid = 0; public $name = '451 Diamants'; public $qty = 1; public $total = 580; public $subtotal = 600; public $taxes = array('total' => array(1 => 0.01), 'subtotal' => array(1 => 0.02)); public $meta = array();
    function get_id() { return $this->id; }
    function get_product_id() { return $this->pid; }
    function get_variation_id() { return $this->vid; }
    function get_name() { return $this->name; }
    function set_name($v) { $this->name = $v; }
    function get_quantity() { return $this->qty; }
    function set_quantity($v) { $this->qty = $v; }
    function get_total() { return $this->total; }
    function get_subtotal() { return $this->subtotal; }
    function set_total($v) { $this->total = $v; }
    function set_subtotal($v) { $this->subtotal = $v; }
    function get_taxes() { return $this->taxes; }
    function set_taxes($v) { $this->taxes = $v; }
    function set_product($p) { $this->pid = $p->parent; $this->vid = $p->id; }
    function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
    function add_meta_data($k, $v, $unique = true) { $this->meta[$k] = $v; }
    function delete_meta_data($k) { unset($this->meta[$k]); }
    function get_meta_data() { $a = array(); foreach ($this->meta as $k => $v) { $a[] = (object) array('key' => $k, 'value' => $v); } return $a; }
}
class Order {
    public $items = array(); public $paid = false;
    function get_id() { return 0; }
    function is_paid() { return $this->paid; }
    function add_item($i) { $this->items[spl_object_hash($i)] = $i; }
    function get_items() { return $this->items; }
}
// Provider adapters are spies. Existing gateway suites test their real encryption,
// attestation, delivery idempotency, status updates and failure policy separately.
class DST2T_Plugin {
    static function instance() { static $s; return $s ?: $s = new self; }
    function products() { return $this; }
    function mapping($id) { return array('item_id' => (int) get_post_meta($id, '_dst2t_item_id'), 'category_id' => (int) get_post_meta($id, '_dst2t_category_id')); }
    function create_order_line_item($item, $key, $v, $order) { foreach ($v['dst2t'] as $k => $value) { $item->add_meta_data('_dst2t_' . $k, $value); } $item->add_meta_data('_test_key', $key); }
}
class DFR_Catalog {
    static function instance() { static $s; return $s ?: $s = new self; }
    function save_native_order_item_data($item, $key, $v, $order) { $item->add_meta_data('_dfr_checkout_snapshot_enc', json_encode(array('mapping' => $v['dfr_supplier_snapshot'], 'fields' => $v['dfr_fields'], 'qty' => $item->qty))); $item->add_meta_data('_test_key', $key); }
}
require dirname(__DIR__) . '/includes/class-delicat-custom-packages.php';
function ok($b, $m) { if (!$b) { throw new RuntimeException('FAIL: ' . $m); } echo "PASS $m\n"; }
function refuses($cb, $m) { try { $cb(); } catch (Throwable $e) { ok(true, $m); return; } ok(false, $m); }
function fixture($gateway, $combo = 900) {
    global $products, $meta;
    $products = array(); $meta = array();
    foreach (array(110,341,572) as $id) {
        $products[$id] = new Product($id);
        $meta[$id] = $gateway === 'dst2t' ? array('_dst2t_enabled' => 'yes', '_dst2t_item_id' => $id + 1000, '_dst2t_category_id' => 7, '_dst2t_returns_voucher' => 'no') : array('_dfr_service_type' => 'topup', '_dfr_offer_id' => 'offer-' . $id, '_dfr_category_id' => 'latam');
    }
    $products[$combo] = new Product($combo);
    $r = Delicat_Custom_Packages::validate_recipe(array('parts' => array(array('id' => 110, 'qty' => 1), array('id' => 341, 'qty' => 1))));
    $meta[$combo][Delicat_Custom_Packages::RECIPE] = $r; return $r;
}
function split_fixture($gateway, $recipe = null) {
    global $meta;
    $r = fixture($gateway);
    if ($recipe) { $r = Delicat_Custom_Packages::validate_recipe($recipe); $meta[900][Delicat_Custom_Packages::RECIPE] = $r; }
    $order = new Order; $item = new WC_Order_Item_Product; $item->vid = 900; $item->meta['_ftr_field_user_id'] = '123456789';
    $v = array('product_id' => 100, 'variation_id' => 900, 'quantity' => 1, 'delicat_package' => $r, 'dst2t' => array('requirements' => array('player_id' => '123456789'), 'player_verified' => true), 'dfr_fields' => array('uid' => array('label' => 'ID joueur', 'value' => '123456789')));
    Delicat_Custom_Packages::split($item, 'cart-line', $v, $order); $order->add_item($item); return array_values($order->items);
}
Delicat_Custom_Packages::boot(); $before = count($hooks['woocommerce_checkout_create_order_line_item']); Delicat_Custom_Packages::boot();
ok(count($hooks['woocommerce_checkout_create_order_line_item']) === $before, 'module boots once with both gateways');
foreach (array('dst2t','dfr') as $gateway) {
    $lines = split_fixture($gateway); $ids = array_column($lines, 'vid'); sort($ids);
    ok($ids === array(110,341), "$gateway: 451 splits into 110 + 341");
    ok(count($lines) === 2 && array_sum(array_column($lines, 'total')) === 580.0, "$gateway: customer total preserved");
    ok(array_sum(array_column($lines, 'subtotal')) === 600.0, "$gateway: pre-discount total preserved");
    ok(abs(array_sum(array_map(function($i) { return $i->taxes['total'][1]; }, $lines)) - 0.01) < 1e-9, "$gateway: tiny tax preserved");
    ok($lines[0]->get_meta('_delicat_package_group') === $lines[1]->get_meta('_delicat_package_group'), "$gateway: parts linked privately");
    ok($lines[0]->get_meta('_test_key') !== $lines[1]->get_meta('_test_key'), "$gateway: unique checkout context per part");
    foreach ($lines as $line) {
        if ($gateway === 'dst2t') { ok($line->get_meta('_dst2t_requirements')['player_id'] === '123456789' && $line->get_meta('_dst2t_item_id') === $line->vid + 1000, 'supplier mapping and recipient match each source'); }
        else { $snapshot = json_decode($line->get_meta('_dfr_checkout_snapshot_enc'), true); ok($snapshot['mapping']['offer'] === 'offer-' . $line->vid && $snapshot['fields']['uid']['value'] === '123456789' && $snapshot['qty'] === 1, 'each snapshot contains its own offer, one recharge and same recipient'); }
    }
    ok(count(array_filter($lines, function($i) { return $i->get_meta('_ftr_field_user_id') === '123456789'; })) === 1, "$gateway: original integration metadata retained");
    $repeat = split_fixture($gateway, array('parts' => array(array('id' => 110, 'qty' => 2))));
    ok(count($repeat) === 2 && $repeat[0]->vid === 110 && $repeat[1]->vid === 110, "$gateway: 220 uses two independently tracked 110 recharges");
}
$r = fixture('dst2t'); $products[341]->stock = 0;
refuses(function() use ($r) { Delicat_Custom_Packages::available($r); }, 'unavailable second component blocks package');
fixture('dst2t'); $products[110]->stock = 1;
refuses(function() { Delicat_Custom_Packages::available(array('parts' => array(array('id' => 110, 'qty' => 2)))); }, 'repeated component stock counted twice');
$r = fixture('dst2t'); $meta[341]['_dst2t_category_id'] = 8;
refuses(function() use ($r) { Delicat_Custom_Packages::validate_recipe($r); }, 'different server refused');
$r = fixture('dst2t'); $meta[341]['_dst2t_item_id'] = 9999;
refuses(function() use ($r) { Delicat_Custom_Packages::validate_recipe($r); }, 'mapping changes invalidate frozen cart');
fixture('dst2t');
refuses(function() { Delicat_Custom_Packages::validate_recipe(array('parts' => array(array('id' => 900, 'qty' => 2)))); }, 'recursive packages refused');
refuses(function() { Delicat_Custom_Packages::validate_recipe(array('parts' => array(array('id' => 110, 'qty' => 21)))); }, 'excessive recipe refused');
$r = fixture('dst2t'); $evil = $r; $evil['parts'][1]['id'] = 572; unset($evil['parts'][1]['source']);
refuses(function() use ($evil) { Delicat_Custom_Packages::split(new WC_Order_Item_Product, 'bad', array('product_id' => 100,'variation_id' => 900,'delicat_package' => $evil), new Order); }, 'tampered client recipe refused');
$r = fixture('dst2t');
refuses(function() use ($r) { Delicat_Custom_Packages::split(new WC_Order_Item_Product, 'bad', array('product_id' => 100,'variation_id' => 110,'delicat_package' => $r), new Order); }, 'native low-price variation cannot carry a fabricated package');
ok(Delicat_Custom_Packages::mapping_meta(null,900,'_dst2t_item_id',true) === array(1110), 'virtual source mapping used only at read time');
ok(Delicat_Custom_Packages::guard_meta(null,900,'_dst2t_item_id',9999) === true && get_post_meta(900,'_dst2t_item_id') === '', 'native admin cannot convert package into duplicate imported mapping');
ok(Delicat_Custom_Packages::guard_meta(null,110,'_dst2t_item_id',9999) === null, 'native mappings unaffected');
$products[110]->stock = 2;
$wc = (object) array('cart' => new class($r) { private $r; function __construct($r) { $this->r = $r; } function get_cart() { return array(array('product_id' => 100,'variation_id' => 900,'quantity' => 2,'delicat_package' => $this->r),array('product_id' => 100,'variation_id' => 110,'quantity' => 1)); } });
Delicat_Custom_Packages::check_cart(); ok(count($notices) === 1, 'cart aggregates native and package component demand');
for ($n = 2; $n <= 20; $n++) { foreach (array(0.01,0.04,143,580,999.99) as $amount) { $shares = Delicat_Custom_Packages::shares($amount,$n,2); if (abs(array_sum($shares) - $amount) > 1e-8 || min($shares) < 0) { throw new RuntimeException('Allocation failed'); } } }
ok(true, '95 allocation cases preserve money without negative parts');
$r = fixture('dfr'); refuses(function() use ($r) { Delicat_Custom_Packages::available($r,2); }, 'gateway top-up quantity stays one');
$order = new Order; $line = new WC_Order_Item_Product; $line->vid = 900; $order->add_item($line);
refuses(function() use ($order) { Delicat_Custom_Packages::block_unsupported_checkout($order,null); }, 'unsupported Store API checkout fails closed');
echo "ALL CUSTOM PACKAGE CHECKS PASSED\n";
