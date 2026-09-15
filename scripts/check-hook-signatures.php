<?php
/**
 * Static check for WordPress hook registrations whose callback cannot accept the
 * arguments the registration promises.
 *
 * WordPress dispatches a callback with array_slice( $args, 0, accepted_args ). If the
 * callback declares more REQUIRED parameters than `accepted_args`, PHP 8 raises
 * ArgumentCountError — a fatal, on the request that fires the hook.
 *
 * Usage: php scripts/check-hook-signatures.php delicat-builder-v9
 * Exit code 1 when a mismatch is found.
 */
$root = $argv[1] ?? 'delicat-builder-v9';

/* ---- index every method/function signature in the tree ------------------- */
$methods = array();   // "Class::method" => [required, total]
$functions = array(); // "name" => [required, total]

function sig_counts(array $tokens, int $paren_open): array {
    $depth = 0; $required = 0; $total = 0; $seen_param = false; $has_default = false; $variadic = false;
    for ($i = $paren_open; $i < count($tokens); $i++) {
        $t = $tokens[$i];
        $text = is_array($t) ? $t[1] : $t;
        if ($text === '(') { $depth++; continue; }
        if ($text === ')') { $depth--; if ($depth === 0) break; continue; }
        if ($depth !== 1) continue;
        if (is_array($t) && $t[0] === T_VARIABLE) {
            $total++; $seen_param = true; $has_default = false;
            // look back for ...
            for ($j = $i - 1; $j > $paren_open; $j--) {
                $p = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if (trim($p) === '') continue;
                if ($p === '...' || (is_array($tokens[$j]) && $tokens[$j][0] === T_ELLIPSIS)) { $variadic = true; }
                break;
            }
            // look ahead for = (default)
            for ($j = $i + 1; $j < count($tokens); $j++) {
                $p = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if (trim($p) === '') continue;
                if ($p === '=') { $has_default = true; }
                break;
            }
            if (!$has_default && !$variadic) { $required++; }
        }
    }
    return array($required, $total, $variadic);
}

$files = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { if (substr((string)$f, -4) === '.php') { $files[] = (string)$f; } }
sort($files);

foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $class = '';
    $classDepth = null; $depth = 0;
    for ($i = 0; $i < count($tokens); $i++) {
        $t = $tokens[$i];
        $text = is_array($t) ? $t[1] : $t;
        if ($text === '{') { $depth++; }
        if ($text === '}') { $depth--; if ($classDepth !== null && $depth < $classDepth) { $class = ''; $classDepth = null; } }
        if (is_array($t) && $t[0] === T_CLASS) {
            for ($j = $i + 1; $j < count($tokens); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $class = $tokens[$j][1]; $classDepth = $depth + 1; break; }
                if (!is_array($tokens[$j]) && trim($tokens[$j]) !== '') break;
            }
        }
        if (is_array($t) && $t[0] === T_FUNCTION) {
            $name = '';
            for ($j = $i + 1; $j < count($tokens); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $name = $tokens[$j][1]; break; }
                if (!is_array($tokens[$j]) && $tokens[$j] === '(') break; // closure
            }
            if ($name === '') continue;
            for ($j = $i + 1; $j < count($tokens); $j++) { if (!is_array($tokens[$j]) && $tokens[$j] === '(') { $paren = $j; break; } }
            list($req, $tot, $var) = sig_counts($tokens, $paren);
            if ($class !== '') { $methods["$class::$name"] = array($req, $tot, $var); }
            else { $functions[$name] = array($req, $tot, $var); }
        }
    }
}

/* ---- check every add_action / add_filter registration -------------------- */
$issues = 0; $checked = 0;
foreach ($files as $file) {
    $src = file_get_contents($file);
    $lines = explode("\n", $src);
    // add_action( 'hook', array( Owner, 'method' ), priority, accepted_args )
    $re = '/\badd_(?:action|filter)\(\s*' .
          '[\'"]([^\'"]+)[\'"]\s*,\s*' .
          'array\(\s*(__CLASS__|self::class|static::class|\$this|[\'"][A-Za-z0-9_\\\\]+[\'"])\s*,\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\)' .
          '\s*(?:,\s*([0-9]+|PHP_INT_MAX|-?[0-9]+)\s*)?(?:,\s*([0-9]+)\s*)?\)/s';
    if (!preg_match_all($re, $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
    foreach ($m as $match) {
        $hook = $match[1][0];
        $owner = trim($match[2][0], "'\"");
        $method = $match[3][0];
        $accepted = isset($match[5]) && $match[5][0] !== '' ? (int) $match[5][0] : 1;
        $offset = $match[0][1];
        $line = substr_count(substr($src, 0, $offset), "\n") + 1;

        // resolve owner class
        $ownerClass = $owner;
        if (in_array($owner, array('__CLASS__', 'self::class', 'static::class', '$this'), true)) {
            if (preg_match_all('/class\s+([A-Za-z0-9_]+)/', substr($src, 0, $offset), $cm)) {
                $ownerClass = end($cm[1]);
            } else { continue; }
        }
        $key = "$ownerClass::$method";
        if (!isset($methods[$key])) continue; // defined elsewhere / not in tree
        list($req, $tot, $variadic) = $methods[$key];
        $checked++;
        if ($variadic) continue;
        if ($req > $accepted) {
            $issues++;
            printf("ArgumentCountError risk: %s:%d\n  hook '%s' passes %d arg(s) to %s(), which requires %d\n",
                $file, $line, $hook, $accepted, $key, $req);
        }
    }
}
printf("\nregistrations checked: %d   signature mismatches: %d\n", $checked, $issues);
exit($issues > 0 ? 1 : 0);
