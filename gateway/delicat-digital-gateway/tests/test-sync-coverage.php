<?php
/**
 * Guard: every imported product must be synchronized with the supplier.
 *
 * Until 4.15.0 sync_imported_products() read at most 500 imported products with
 * no paging, then took array_slice($groups, 0, 30) — the SAME first thirty
 * category groups on every run. The tail of the catalogue was never synchronized
 * at all: stock never changed there, and a product withdrawn upstream was never
 * detected, however long the site ran. Measured on a 1,800-product /
 * 45-category store: 15 categories and 1,300 products permanently stale.
 *
 * sync_batch_keys() is the rotation that replaces it. This asserts the property
 * that actually matters — full coverage within a bounded number of runs, for any
 * catalogue size and budget, including when the catalogue changes shape.
 *
 * Run: php tests/test-sync-coverage.php
 *
 * @package Delicat_Digital_Gateway
 */

define('ABSPATH', __DIR__);

/* Load only the class under test; it needs no WordPress for this pure method. */
$src = file_get_contents(dirname(__DIR__) . '/includes/class-dfr-catalog.php');
$start = strpos($src, 'public static function sync_batch_keys');
if ($start === false) { fwrite(STDERR, "sync_batch_keys() not found\n"); exit(1); }
$depth = 0; $i = strpos($src, '{', $start); $end = $i;
for ($j = $i; $j < strlen($src); $j++) {
    if ($src[$j] === '{') { $depth++; }
    elseif ($src[$j] === '}') { $depth--; if ($depth === 0) { $end = $j; break; } }
}
eval('class DFR_Catalog_Rotation { ' . substr($src, $start, $end - $start + 1) . ' }');

$pass = 0; $fail = 0;
function ok($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  ok    %-54s %s\n", $label, $detail); }
    else { $fail++; printf("  FAIL  %-54s %s\n", $label, $detail); }
}

function keys_for($n) {
    $k = array();
    for ($i = 1; $i <= $n; $i++) { $k[] = 'topup|cat' . str_pad((string) $i, 3, '0', STR_PAD_LEFT); }
    sort($k, SORT_STRING);
    return $k;
}

/** Run the rotation until every key has been seen, or give up. @return array{runs:int,covered:int} */
function drive($keys, $budget, $max_runs = 0) {
    // Allow one run per group plus slack; the point is to measure the real bound,
    // not to impose an arbitrary one.
    if ($max_runs <= 0) { $max_runs = count($keys) + 10; }
    $cursor = '';
    $seen = array();
    for ($run = 1; $run <= $max_runs; $run++) {
        $batch = DFR_Catalog_Rotation::sync_batch_keys($keys, $cursor, $budget);
        if (!$batch) { break; }
        foreach ($batch as $k) { $seen[$k] = 1; }
        $cursor = end($batch);
        if (count($seen) >= count($keys)) { return array('runs' => $run, 'covered' => count($seen)); }
    }
    return array('runs' => $max_runs, 'covered' => count($seen));
}

echo "\n=== full coverage within ceil(groups / budget) runs ===\n";
foreach (array(
    array(45, 30), array(45, 60), array(200, 30), array(1, 30), array(31, 30),
    array(500, 7), array(1000, 1),
) as $case) {
    list($n, $budget) = $case;
    $r = drive(keys_for($n), $budget);
    $expected = (int) ceil($n / min($budget, $n));
    ok(
        sprintf('%d groups, budget %d', $n, $budget),
        $r['covered'] === $n && $r['runs'] <= $expected,
        sprintf('covered %d/%d in %d run(s), bound %d', $r['covered'], $n, $r['runs'], $expected)
    );
}

echo "\n=== the old behaviour is gone: run N+1 must not repeat run 1 ===\n";
$keys = keys_for(45);
$first = DFR_Catalog_Rotation::sync_batch_keys($keys, '', 30);
$second = DFR_Catalog_Rotation::sync_batch_keys($keys, end($first), 30);
ok('second run starts where the first stopped', $second[0] === $keys[30], $second[0]);
ok('second run reaches the previously starved tail', in_array($keys[44], $second, true), 'cat045 present');
ok('second run differs from the first', $first !== $second);

echo "\n=== wrap-around ===\n";
$last = DFR_Catalog_Rotation::sync_batch_keys($keys, $keys[44], 30);
ok('cursor at the last key wraps to the first', $last[0] === $keys[0], $last[0]);

echo "\n=== degenerate and hostile inputs ===\n";
ok('empty catalogue', DFR_Catalog_Rotation::sync_batch_keys(array(), '', 30) === array());
ok('zero budget', DFR_Catalog_Rotation::sync_batch_keys($keys, '', 0) === array());
ok('negative budget', DFR_Catalog_Rotation::sync_batch_keys($keys, '', -5) === array());
ok('budget larger than catalogue returns each key once',
    count(DFR_Catalog_Rotation::sync_batch_keys($keys, '', 9999)) === 45
    && count(array_unique(DFR_Catalog_Rotation::sync_batch_keys($keys, '', 9999))) === 45);
$unknown = DFR_Catalog_Rotation::sync_batch_keys($keys, 'topup|deleted-category', 30);
ok('unknown cursor restarts from the top rather than skipping',
    $unknown[0] === $keys[0] && count($unknown) === 30, $unknown[0]);

echo "\n=== a catalogue that grows mid-cycle still converges ===\n";
$cursor = ''; $seen = array(); $keys = keys_for(40);
for ($run = 1; $run <= 12; $run++) {
    if ($run === 3) { $keys = keys_for(90); }          // merchant imports more
    if ($run === 7) { $keys = keys_for(60); }          // and removes some
    $batch = DFR_Catalog_Rotation::sync_batch_keys($keys, $cursor, 30);
    foreach ($batch as $k) { $seen[$k] = 1; }
    $cursor = end($batch);
}
$live = array_intersect(array_keys($seen), $keys);
ok('every surviving group was reached', count($live) === count($keys), sprintf('%d/%d', count($live), count($keys)));

echo "\n---------------------------------------------\n";
printf("passed %d / failed %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
