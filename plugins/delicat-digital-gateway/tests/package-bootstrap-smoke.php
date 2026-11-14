<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Fresh-process regression: load real module files, then fire plugins_loaded. */
define('ABSPATH', __DIR__ . '/');
$hooks = array();
function add_action($tag, $callback, $priority = 10, $count = 1) { global $hooks; $hooks[$tag][$priority][] = $callback; }
function add_filter($tag, $callback, $priority = 10, $count = 1) { add_action($tag, $callback, $priority, $count); }
function ok($value, $message) { if (!$value) { throw new RuntimeException('FAIL: ' . $message); } echo 'PASS ' . $message . "\n"; }
function count_hooks($tag) { global $hooks; $n = 0; foreach ($hooks[$tag] ?? array() as $callbacks) { $n += count($callbacks); } return $n; }
$legacy = $argv[1] ?? ''; $order = $argv[2] ?? 'first';
if ($legacy && $order === 'first') { require $legacy; ok(count_hooks('plugins_loaded') === 0, 'legacy first-load bug reproduced before recovery'); }
$root = dirname(__DIR__);
require $root . '/includes/class-delicat-custom-packages.php';
$has_editor = is_file($root . '/includes/class-delicat-package-product-editor.php');
if ($has_editor) { require $root . '/includes/class-delicat-package-product-editor.php'; }
require $root . '/includes/class-delicat-package-selection.php';
ok(count_hooks('plugins_loaded') >= ($has_editor ? 2 : 1), 'file loading registers startup callbacks without manual boot');
if ($legacy && $order === 'last') { require $legacy; }
// Both gateway directories may include distinct copies of this shared module.
include $root . '/includes/class-delicat-custom-packages.php';
if ($has_editor) { include $root . '/includes/class-delicat-package-product-editor.php'; }
include $root . '/includes/class-delicat-package-selection.php';
ksort($hooks['plugins_loaded']);
foreach ($hooks['plugins_loaded'] as $callbacks) { foreach ($callbacks as $callback) { call_user_func($callback); } }
ok(count_hooks('admin_menu') === 1, 'standalone package menu registered exactly once');
ok(count_hooks('woocommerce_checkout_create_order_line_item') === 1, 'package fulfillment split registered exactly once');
if ($has_editor) {
    ok(count_hooks('woocommerce_product_data_tabs') === 1, 'product package tab registered by startup event');
    ok(count_hooks('add_meta_boxes_product') === 1, 'visible product boxes registered by startup event');
    ok(count_hooks('edit_form_after_title') === 1, 'labeled product entry registered by startup event');
    ok(count_hooks('woocommerce_process_product_meta') === 1, 'package save handler registered by startup event');
}
ok(count_hooks('woocommerce_dropdown_variation_attribute_options_args') === 1, 'package dropdown repair registered once through startup');
echo "ALL PACKAGE BOOTSTRAP CHECKS PASSED\n";
