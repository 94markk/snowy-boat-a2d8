<?php
define('DELICAT_BUILDER_V9_DIR', '/home/user/snowy-boat-a2d8/delicat-builder-v9/');
define('DELICAT_BUILDER_V9_URL', 'https://delicastoreha.com/wp-content/plugins/delicat-builder-v9/');
$src = file_get_contents(DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-audit-fixes.php');
preg_match('/ public static function asset_url.*?\n \}/s', $src, $m);
eval('class A {' . $m[0] . '}');
$B = 'https://delicastoreha.com/wp-content/plugins/delicat-builder-v9/';
$cases = array(
  $B.'assets/js/core.js'            => $B.'assets/js/core.d2d18e641204.js',
  $B.'assets/js/core.js?ver=9'      => $B.'assets/js/core.d2d18e641204.js',
  'http://delicastoreha.com/wp-content/plugins/delicat-builder-v9/assets/js/core.js'
                                    => 'http://delicastoreha.com/wp-content/plugins/delicat-builder-v9/assets/js/core.d2d18e641204.js',
  '//delicastoreha.com/wp-content/plugins/delicat-builder-v9/assets/js/core.js'
                                    => '//delicastoreha.com/wp-content/plugins/delicat-builder-v9/assets/js/core.d2d18e641204.js',
  $B.'assets/js/unmapped.js'        => $B.'assets/js/unmapped.js',
  'https://other.example/app.js'    => 'https://other.example/app.js',
  ''                                => '',
);
$fail = 0;
foreach ($cases as $in => $want) {
  $got = A::asset_url($in);
  $ok = ($got === $want); if (!$ok) $fail++;
  printf("%s\n  in:   %s\n  out:  %s\n  want: %s\n", $ok ? 'PASS' : 'FAIL', $in === '' ? '(empty)' : $in, $got, $want);
}
echo $fail ? "\n$fail FAILED\n" : "\nall pass\n"; exit($fail ? 1 : 0);
