<?php
// Prints the speculation rules of TurboNav for a guest and a signed-in customer (home and account page).
$_SERVER['HTTP_HOST'] = getenv('HOSTPORT'); $_SERVER['REQUEST_URI'] = getenv('URI') ?: '/';
require getenv('SITE') . '/wp-load.php';
if (!class_exists('Delicat_Builder_V9_Engine', false)) { final class Delicat_Builder_V9_Engine { public static function active() { return true; } public static function account_sections() { return ['base' => '/my-account/', 'paths' => ['/my-account/orders/', '/my-account/view-order/']]; } } }
require_once WP_PLUGIN_DIR . '/delicat-builder-v9/includes/class-delicat-builder-turbonav.php';
if (getenv('USER_ID')) wp_set_current_user((int) getenv('USER_ID'));
$GLOBALS['wp']->main(); $GLOBALS['wp_query']->query($GLOBALS['wp']->query_vars);
$m = new ReflectionMethod('Delicat_Builder_V9_TurboNav', 'print_full_document_prefetch'); $m->setAccessible(true);
ob_start(); $m->invoke(null); $out = ob_get_clean();
if (!preg_match('~>(\{.*\})</script>~s', $out, $mm)) { echo "no rules\n"; exit; }
$r = json_decode($mm[1], true);
foreach ($r as $kind => $rules) foreach ($rules as $rule) { echo $kind, ' [', $rule['eagerness'], '] ', json_encode($rule['where']['and'][0]['href_matches']); foreach ($rule['where']['and'] as $c) if (isset($c['not']['href_matches'])) echo ' not:', json_encode($c['not']['href_matches']); echo "\n"; }
