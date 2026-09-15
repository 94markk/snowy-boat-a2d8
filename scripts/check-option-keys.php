<?php
/**
 * Cross-cutting check: option and post-meta keys that are only ever written, or only ever
 * read, across the whole plugin. A setting saved but never read is a control that does
 * nothing; a key read but never written is a feature that never turns on.
 *
 * Usage: php scripts/check-option-keys.php delicat-builder-v9
 */
$root = $argv[1] ?? 'delicat-builder-v9';
$files = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { if (substr((string)$f, -4) === '.php') { $files[] = (string)$f; } }
sort($files);

$reads = array(); $writes = array(); $where = array();
$readFns  = 'get_option|get_site_option|get_post_meta|get_user_meta|get_term_meta|get_transient|get_site_transient';
$writeFns = 'update_option|add_option|update_site_option|update_post_meta|add_post_meta|update_user_meta|add_user_meta|update_term_meta|set_transient|set_site_transient|delete_option|delete_post_meta|delete_user_meta|delete_transient';

/* Constant values, so OPTION = 'x' style references resolve. */
$consts = array();
foreach ($files as $file) {
    $src = file_get_contents($file);
    if (preg_match_all('/(?:const|public\s+const)\s+([A-Z_0-9]+)\s*=\s*[\'"]([^\'"]+)[\'"]/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $c) { $consts[$c[1]] = $c[2]; }
    }
}

foreach ($files as $file) {
    $src = file_get_contents($file);
    foreach (array(array($readFns, 'r'), array($writeFns, 'w')) as $pair) {
        list($fns, $kind) = $pair;
        // literal key, possibly after an id argument
        if (preg_match_all('/\b(' . $fns . ')\(\s*(?:[^,()]{1,80},\s*)?[\'"]([a-zA-Z0-9_\-]{3,})[\'"]/', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $key = $x[2];
                if ($kind === 'r') { $reads[$key] = true; } else { $writes[$key] = true; }
                $where[$key][$kind][] = basename($file);
            }
        }
        // self::CONST / CLASS::CONST key
        if (preg_match_all('/\b(' . $fns . ')\(\s*(?:[^,()]{1,80},\s*)?(?:self|static|[A-Za-z_0-9]+)::([A-Z_0-9]+)/', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if (!isset($consts[$x[2]])) continue;
                $key = $consts[$x[2]];
                if ($kind === 'r') { $reads[$key] = true; } else { $writes[$key] = true; }
                $where[$key][$kind][] = basename($file);
            }
        }
    }
}

$writeOnly = array_diff(array_keys($writes), array_keys($reads));
$readOnly  = array_diff(array_keys($reads), array_keys($writes));
sort($writeOnly); sort($readOnly);

echo "=== WRITTEN BUT NEVER READ (" . count($writeOnly) . ") ===\n";
foreach ($writeOnly as $k) { echo "  $k   <- " . implode(', ', array_unique($where[$k]['w'])) . "\n"; }
echo "\n=== READ BUT NEVER WRITTEN (" . count($readOnly) . ") ===\n";
foreach ($readOnly as $k) { echo "  $k   -> " . implode(', ', array_unique($where[$k]['r'])) . "\n"; }
