<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Package index regression. Each request runs in a fresh PHP process, as in
 * WordPress, with the real shared modules. get_posts() answers 'fields' the way
 * WP_Query does: 'ids' is an id list; 'id=>parent' maps post id => parent id,
 * keyed 'post_parent:ID' when the query cache answers. Reading the wrong shape
 * stored an empty index and every custom package lost its supplier mapping.
 * Usage: php tests/package-index-smoke.php [plugin root to test] */
if (($argv[1] ?? '') === '--request') {
    $cfg = json_decode($argv[2], true);
    define('ABSPATH', __DIR__ . '/'); define('DAY_IN_SECONDS', 86400);
    $options = is_file($cfg['options']) ? json_decode(file_get_contents($cfg['options']), true) : array();
    $hooks = array(); $primed = array();
    function add_filter($tag, $cb, $priority = 10, $args = 1) { global $hooks; $hooks[$tag][$priority][] = array($cb, $args); return true; }
    function add_action($tag, $cb, $priority = 10, $args = 1) { return add_filter($tag, $cb, $priority, $args); }
    function hooked($tag, $cb) { global $hooks; foreach ($hooks[$tag] ?? array() as $list) { foreach ($list as $h) { if ($h[0] === $cb) { return true; } } } return false; }
    function apply_filters($tag, $value, ...$args) { global $hooks; if (empty($hooks[$tag])) { return $value; } ksort($hooks[$tag]); foreach ($hooks[$tag] as $list) { foreach ($list as $h) { $value = call_user_func_array($h[0], array_slice(array_merge(array($value), $args), 0, $h[1])); } } return $value; }
    function do_action($tag, ...$args) { global $hooks; if (empty($hooks[$tag])) { return; } ksort($hooks[$tag]); foreach ($hooks[$tag] as $list) { foreach ($list as $h) { call_user_func_array($h[0], array_slice($args, 0, $h[1])); } } }
    function get_option($k, $d = false) { global $options; return array_key_exists($k, $options) ? $options[$k] : $d; }
    function update_option($k, $v, $autoload = null) { global $options, $cfg; $options[$k] = $v; file_put_contents($cfg['options'], json_encode($options)); return true; }
    function get_transient($k) { return false; }
    function set_transient($k, $v, $ttl = 0) { return true; }
    function current_user_can($cap, ...$args) { return true; }
    function absint($v) { return abs((int) $v); }
    function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    function wc_get_product($id) { return false; }
    // 500: variable product with supplier variations 501/502, package 600 (501 x 2)
    // and disabled package 601 (502 x 3). 700: Gateway product, supplier 701 and
    // package 702 (701 x 2). 800: product without packages.
    $posts = array(
        500 => array(0, 'product', 'publish'), 501 => array(500, 'product_variation', 'publish'), 502 => array(500, 'product_variation', 'publish'),
        600 => array(500, 'product_variation', 'publish'), 601 => array(500, 'product_variation', 'private'),
        700 => array(0, 'product', 'publish'), 701 => array(700, 'product_variation', 'publish'), 702 => array(700, 'product_variation', 'publish'),
        800 => array(0, 'product', 'publish'), 801 => array(800, 'product_variation', 'publish'), 603 => array(500, 'product_variation', 'publish'),
    );
    $meta = array(
        501 => array('_dst2t_enabled' => 'yes', '_dst2t_item_id' => '9001', '_dst2t_category_id' => '7'),
        502 => array('_dst2t_enabled' => 'yes', '_dst2t_item_id' => '9002', '_dst2t_category_id' => '7'),
        701 => array('_dfr_service_type' => 'topup', '_dfr_offer_id' => 'offer-41', '_dfr_category_id' => '12'),
        801 => array('_dst2t_enabled' => 'yes', '_dst2t_item_id' => '9100', '_dst2t_category_id' => '8'),
    );
    if ($cfg['packages']) {
        $meta[600]['_delicat_package_recipe_v1'] = array('parts' => array(array('id' => 501, 'qty' => 2)));
        $meta[601]['_delicat_package_recipe_v1'] = array('parts' => array(array('id' => 502, 'qty' => 3)));
        $meta[702]['_delicat_package_recipe_v1'] = array('parts' => array(array('id' => 701, 'qty' => 2)));
    }
    function get_post_meta($id, $key = '', $single = false) {
        global $meta;
        $check = apply_filters('get_post_metadata', null, $id, $key, $single, 'post');
        if (null !== $check) { return $single && is_array($check) ? $check[0] : $check; }
        if (!isset($meta[$id][$key])) { return $single ? '' : array(); }
        return $single ? $meta[$id][$key] : array($meta[$id][$key]);
    }
    function get_posts($args) {
        global $posts, $meta, $cfg;
        $found = array();
        foreach ($posts as $id => $p) {
            if ($p[1] !== $args['post_type']) { continue; }
            if (isset($args['post_parent']) && $p[0] !== (int) $args['post_parent']) { continue; }
            if (isset($args['post_status']) && !in_array($p[2], (array) $args['post_status'], true)) { continue; }
            if (!empty($args['meta_key']) && !isset($meta[$id][$args['meta_key']])) { continue; }
            $found[] = $id;
        }
        sort($found);
        $limit = $args['posts_per_page'] ?? -1;
        $found = array_slice($found, $args['offset'] ?? 0, -1 === (int) $limit ? null : (int) $limit);
        $fields = $args['fields'] ?? '';
        if ('ids' === $fields) { return $found; }
        if ('id=>parent' === $fields) {
            $out = array();
            foreach ($found as $id) { $out['cache' === $cfg['query_cache'] ? 'post_parent:' . $id : $id] = $posts[$id][0]; }
            return $out;
        }
        $out = array();
        foreach ($found as $id) { $out[] = (object) array('ID' => $id, 'post_parent' => $posts[$id][0], 'post_status' => $posts[$id][2]); }
        return $out;
    }
    function wp_get_post_parent_id($id) { global $posts; return isset($posts[$id]) ? $posts[$id][0] : false; }
    function _prime_post_caches($ids, $terms = true, $meta = true) { global $primed; $primed[] = array_values($ids); }
    require $cfg['root'] . '/includes/class-delicat-custom-packages.php';
    require $cfg['root'] . '/includes/class-delicat-package-selection.php';
    $mapping = array('Delicat_Custom_Packages', 'mapping_meta');
    do_action('plugins_loaded');
    $report = array('boot_hooked' => hooked('get_post_metadata', $mapping), 'rebuild_scheduled' => hooked('init', array('Delicat_Package_Selection', 'rebuild_index')));
    do_action('init');
    foreach ($cfg['steps'] as $step) {
        // 'Forfaits personnalisés' page and the daily wp-admin self-check both rebuild.
        if ('admin_page' === $step) { ob_start(); Delicat_Custom_Packages::page(); ob_end_clean(); }
        if ('self_check' === $step) { Delicat_Package_Selection::repair_batch(); }
        // An admin creates package 603 (502 x 2): WooCommerce writes its recipe meta.
        if ('create_package' === $step) { $meta[603]['_delicat_package_recipe_v1'] = array('parts' => array(array('id' => 502, 'qty' => 2))); do_action('added_post_meta', 99, 603, '_delicat_package_recipe_v1', $meta[603]['_delicat_package_recipe_v1']); }
    }
    $report['hooked'] = hooked('get_post_metadata', $mapping);
    $report['stored'] = get_option('delicat_package_index_v1', 'MISSING');
    $report['primed'] = $primed;
    $api = method_exists('Delicat_Package_Selection', 'is_package');
    foreach (array(500, 501, 502, 600, 601, 603, 700, 701, 702, 800, 801) as $id) { $report['is_package'][$id] = $api ? Delicat_Package_Selection::is_package($id) : null; }
    foreach (array(500, 700, 800) as $pid) { $report['has_packages'][$pid] = $api ? Delicat_Package_Selection::has_packages($pid) : null; }
    foreach ($cfg['reads'] as $r) { $report['meta'][] = get_post_meta($r[0], $r[1], $r[2]); }
    $report['created'] = get_post_meta(603, '_dst2t_item_id', true);
    // Stock: a package is checked against its components (none load here, so it
    // reads out of stock); any other product keeps WooCommerce's answer.
    foreach (array(600, 702, 501, 801) as $id) { $report['in_stock'][$id] = Delicat_Custom_Packages::in_stock(true, new class($id) { private $id; public function __construct($id) { $this->id = $id; } public function get_id() { return $this->id; } }); }
    echo json_encode($report);
    exit(0);
}

$root = isset($argv[1]) && '' !== $argv[1] ? rtrim($argv[1], '/') : dirname(__DIR__);
$store = tempnam(sys_get_temp_dir(), 'dlc-pkg-index-');
function ok($v, $m) { if (!$v) { throw new RuntimeException('FAIL ' . $m); } echo 'PASS ' . $m . "\n"; }
// Supplier mapping of each package as read by DST2T_Product::is_mapped(), the
// Gateway and the importers: the values of its first source variation, exactly
// what the pre-index code (Gateway 4.24 / Shop2Topup 2.9) returned.
$reads = array(
    array(600, '_dst2t_enabled', true, 'yes'), array(600, '_dst2t_item_id', true, '9001'), array(600, '_dst2t_category_id', true, '7'),
    array(600, '_dst2t_item_id', false, array('9001')), array(601, '_dst2t_item_id', true, '9002'),
    array(702, '_dfr_service_type', true, 'topup'), array(702, '_dfr_offer_id', true, 'offer-41'), array(702, '_dfr_category_id', true, '12'),
    array(501, '_dst2t_item_id', true, '9001'), array(801, '_dst2t_item_id', true, '9100'), array(800, '_dst2t_enabled', true, ''),
);
function request($options = null, $cfg = array()) {
    global $root, $store, $reads;
    if (null !== $options) { file_put_contents($store, json_encode($options)); }
    $cfg += array('packages' => true, 'query_cache' => 'db', 'steps' => array());
    $cfg['root'] = $root; $cfg['options'] = $store;
    $cfg['reads'] = array_map(function ($r) { return array_slice($r, 0, 3); }, $reads);
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --request ' . escapeshellarg(json_encode($cfg)) . ' 2>&1');
    $report = json_decode((string) $out, true);
    if (!is_array($report)) { throw new RuntimeException("FAIL request did not complete:\n" . $out); }
    return $report;
}
function mapped_like_before($report) {
    global $reads;
    foreach ($reads as $i => $r) { if (($report['meta'][$i] ?? null) !== $r[3]) { echo '  ' . $r[0] . ' ' . $r[1] . ' read ' . json_encode($report['meta'][$i] ?? null) . ', expected ' . json_encode($r[3]) . "\n"; return false; } }
    return true;
}
$full = array('format' => 2, 'parents' => array(500 => array(600, 601), 700 => array(702)));
$known = function ($r) {
    $p = $r['is_package'];
    return $p[600] === true && $p[601] === true && $p[702] === true && $p[500] === false && $p[501] === false && $p[502] === false && $p[700] === false && $p[701] === false && $p[801] === false
        && $r['has_packages'][500] === true && $r['has_packages'][700] === true && $r['has_packages'][800] === false;
};
$migrated = array('delicat_package_selector_repair_v1' => array('done' => true));
try {
    $r = request($migrated);
    ok($r['rebuild_scheduled'] && $r['boot_hooked'], 'first request after the update: index unknown, supplier mapping stays on until the rebuild');
    ok($r['stored'] === $full, 'rebuild indexes every package variation under its parent (WordPress get_posts field shapes)');
    ok($known($r) && $r['hooked'], 'packages, parents and ordinary variations classified from the rebuilt index');
    ok(mapped_like_before($r), 'package variations read their supplier mapping (_dst2t_enabled = yes, item, category, Gateway offer) as before the index');
    ok($r['in_stock'] === array(600 => false, 702 => false, 501 => true, 801 => true), 'package stock still depends on its components; other products untouched');
    ok(count($r['primed']) === 1 && $r['primed'][0] === array(600, 601, 702), 'parents of all packages loaded with one cache-priming query');
    $r = request();
    ok(!$r['rebuild_scheduled'] && $r['boot_hooked'] && $r['stored'] === $full, 'next request: stored index used, no rebuild, mapping hooked at boot');
    ok($known($r) && mapped_like_before($r), 'next request: packages keep their supplier mapping');
    $r = request($migrated, array('query_cache' => 'cache'));
    ok($r['stored'] === $full && $known($r) && mapped_like_before($r), 'same index when WordPress answers from its query cache');
    $r = request($migrated + array('delicat_package_index_v1' => array()));
    ok($r['rebuild_scheduled'] && $r['stored'] === $full && mapped_like_before($r), 'empty index stored by the unreleased 4.25.0 / 2.10.0 builds is rebuilt, not trusted');
    $r = request($migrated + array('delicat_package_index_v1' => array(500 => array(600))));
    ok($r['rebuild_scheduled'] && $r['stored'] === $full && $known($r), 'index without the format marker is rebuilt from the database');
    $r = request($migrated + array('delicat_package_index_v1' => $full), array('steps' => array('admin_page', 'self_check')));
    ok(!$r['rebuild_scheduled'] && $r['stored'] === $full && $known($r) && mapped_like_before($r), "'Forfaits personnalisés' page and daily self-check rebuild the same index");
    $r = request($migrated + array('delicat_package_index_v1' => $full), array('steps' => array('create_package', 'admin_page')));
    ok($r['stored'] === array('format' => 2, 'parents' => array(500 => array(600, 601, 603), 700 => array(702))) && $r['is_package'][603] === true && $r['created'] === '9002', "new package indexed on save and kept by the 'Forfaits personnalisés' page it redirects to");
    $r = request($migrated, array('packages' => false));
    ok($r['stored'] === array('format' => 2, 'parents' => array()) && $r['is_package'][600] === false, 'shop without packages stores an empty index with its format');
    $r = request(null, array('packages' => false));
    ok(!$r['rebuild_scheduled'] && !$r['boot_hooked'] && !$r['hooked'], 'shop without packages: no rebuild and no meta filter on later requests');
    ok($r['meta'][9] === '9100' && $r['meta'][8] === '9001', 'shop without packages: supplier variations read their own mapping');
} finally { @unlink($store); }
echo "ALL PACKAGE INDEX CHECKS PASSED\n";
