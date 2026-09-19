<?php
/**
 * Guard: a partial digital delivery must never count as a completed one.
 *
 * item_has_delivery_codes() returned true as soon as ONE code existed, whatever
 * the quantity. Every caller — queue_item_delivery_refresh(),
 * refresh_item_delivery_now() and the customer-facing rollup — uses it to decide
 * whether to keep reconciling, so an order for five gift cards that received one
 * code was treated as delivered: reconciliation stopped permanently, the customer
 * never received the other four they paid for, and nothing recorded a shortfall.
 *
 * Quantity maps to codes one-for-one: one supplier order carries
 * 'quantity' => $item->get_quantity(), and extract_codes() collects a list capped
 * at 100 because "Supplier order quantity is capped at 100."
 *
 * The chase has to be bounded, though — a supplier that genuinely sends fewer
 * codes than units would otherwise be re-polled forever.
 *
 * Run: php tests/test-partial-delivery.php
 *
 * @package Delicat_Digital_Gateway
 */

define('ABSPATH', __DIR__);
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
function apply_filters($tag, $value) { return $value; }
function absint($v) { return abs((int) $v); }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }

/** Minimal order-item double with the metadata the real code reads. */
class FakeItem {
    private $meta = array(); private $qty; private $type;
    public function __construct($qty, $type = 'giftcard') { $this->qty = (int) $qty; $this->type = $type; }
    public function get_quantity() { return $this->qty; }
    public function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function delete_meta_data($k) { unset($this->meta[$k]); }
    public function save() {}
    public function service_type() { return $this->type; }
}

/** The methods under test, with their two collaborators stubbed. */
class Delivery {
    public function item_requires_delivery_code($item) {
        return in_array($item->service_type(), array('giftcard', 'gamekey'), true);
    }
    public function item_expected_code_count($item) {
        if (!$item || !$this->item_requires_delivery_code($item)) { return 0; }
        $qty = is_callable(array($item, 'get_quantity')) ? (int) $item->get_quantity() : 1;
        return max(1, min(100, $qty));
    }
    public function short_delivery_grace() { return (int) apply_filters('dfr_short_delivery_grace', 6 * HOUR_IN_SECONDS); }
    public function item_delivered_code_count($item) {
        if (!$item) { return 0; }
        $count = absint($item->get_meta('_dfr_codes_count', true));
        if ($count > 0 && (string) $item->get_meta('_dfr_codes_enc', true) !== '') { return $count; }
        return 0;
    }
    public function item_has_delivery_codes($item) {
        if (!$item) { return false; }
        $delivered = $this->item_delivered_code_count($item);
        if ($delivered < 1) { return false; }
        $expected = $this->item_expected_code_count($item);
        if ($expected <= 1 || $delivered >= $expected) {
            if ($item->get_meta('_dfr_codes_short_since', true)) { $item->delete_meta_data('_dfr_codes_short_since'); }
            return true;
        }
        $since = (string) $item->get_meta('_dfr_codes_short_since', true);
        if ($since === '') { $item->update_meta_data('_dfr_codes_short_since', gmdate('c')); $item->save(); return false; }
        $started = strtotime($since);
        if ($started === false) { $item->update_meta_data('_dfr_codes_short_since', gmdate('c')); $item->save(); return false; }
        if ((time() - $started) < $this->short_delivery_grace()) { return false; }
        return true;
    }
}

$d = new Delivery();
$pass = 0; $fail = 0;
function ok($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  ok    %-52s %s\n", $label, $detail); }
    else { $fail++; printf("  FAIL  %-52s %s\n", $label, $detail); }
}
function item($qty, $codes, $short_since = null, $type = 'giftcard') {
    $i = new FakeItem($qty, $type);
    if ($codes > 0) { $i->update_meta_data('_dfr_codes_count', $codes); $i->update_meta_data('_dfr_codes_enc', 'x2:fake'); }
    if ($short_since !== null) { $i->update_meta_data('_dfr_codes_short_since', $short_since); }
    return $i;
}

echo "\n=== a short delivery is not a delivery ===\n";
ok('1 of 5 gift cards -> keep reconciling', $d->item_has_delivery_codes(item(5, 1)) === false, 'was: treated as delivered');
ok('4 of 5 gift cards -> keep reconciling', $d->item_has_delivery_codes(item(5, 4)) === false);
ok('1 of 2 game keys  -> keep reconciling', $d->item_has_delivery_codes(item(2, 1, null, 'gamekey')) === false);

echo "\n=== a complete delivery is complete ===\n";
ok('5 of 5', $d->item_has_delivery_codes(item(5, 5)) === true);
ok('1 of 1', $d->item_has_delivery_codes(item(1, 1)) === true);
ok('6 of 5 (supplier over-delivered)', $d->item_has_delivery_codes(item(5, 6)) === true);

echo "\n=== nothing delivered is never complete ===\n";
ok('0 of 5', $d->item_has_delivery_codes(item(5, 0)) === false);
ok('0 of 1', $d->item_has_delivery_codes(item(1, 0)) === false);

echo "\n=== the chase is bounded, so a real bundle cannot loop forever ===\n";
$fresh = item(5, 1, gmdate('c', time() - 60));
ok('short for 1 minute -> still chasing', $d->item_has_delivery_codes($fresh) === false);
$stale = item(5, 1, gmdate('c', time() - 7 * HOUR_IN_SECONDS));
ok('short past the 6h grace -> accepted, stops', $d->item_has_delivery_codes($stale) === true);
$edge = item(5, 1, 'not-a-date');
$edge_first = $d->item_has_delivery_codes($edge);
ok('unparseable stamp -> re-stamped, keeps chasing', $edge_first === false && strtotime((string) $edge->get_meta('_dfr_codes_short_since')) !== false);
$edge->update_meta_data('_dfr_codes_short_since', gmdate('c', time() - 7 * HOUR_IN_SECONDS));
ok('re-stamped item still terminates after the grace', $d->item_has_delivery_codes($edge) === true);

echo "\n=== the short marker is stamped once, then cleared on completion ===\n";
$i = item(5, 1);
$d->item_has_delivery_codes($i);
ok('first short check stamps the marker', $i->get_meta('_dfr_codes_short_since') !== '');
$i->update_meta_data('_dfr_codes_count', 5);
ok('completing clears the marker', $d->item_has_delivery_codes($i) === true && $i->get_meta('_dfr_codes_short_since') === '');

echo "\n=== non-code items are unaffected ===\n";
ok('top-up with no codes is not code-based', $d->item_expected_code_count(item(3, 0, null, 'topup')) === 0);

/* This file reimplements the method under test, because the real class needs far
   more of WordPress than a unit test should boot. That copy can drift, so assert
   the real source still contains the decisions being tested here. */
echo "\n=== the test double still mirrors the real implementation ===\n";
$real = (string) file_get_contents(dirname(__DIR__) . '/includes/class-dfr-plugin.php');
foreach (array(
    'expected code count is quantity-bounded' => 'return max(1, min(100, $qty));',
    'short delivery is incomplete'            => 'if ($expected <= 1 || $delivered >= $expected) {',
    'corrupt stamp is re-stamped'             => 'Corrupt stamp. Re-stamp rather than trusting it',
    'grace window bounds the chase'           => 'if ((time() - $started) < $this->short_delivery_grace()) {',
) as $label => $needle) {
    ok($label, strpos($real, $needle) !== false);
}

echo "\n---------------------------------------------\n";
printf("passed %d / failed %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
