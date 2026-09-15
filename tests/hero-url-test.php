<?php
$calls = 0;
function attachment_url_to_postid($u) {
  $GLOBALS['calls']++;
  return $u === 'https://x.test/wp-content/uploads/2026/09/banner.jpg' ? 42 : 0;
}
$src = file_get_contents('/home/user/snowy-boat-a2d8/delicat-builder-v9/includes/class-delicat-builder-native-product.php');
preg_match('/private static function attachment_for_url.*?\n\t\}/s', $src, $m);
eval('class T { ' . $m[0] . ' public static function go($u) { return self::attachment_for_url($u); } }');
$cases = array(
  'https://x.test/wp-content/uploads/2026/09/banner.jpg'             => 42,
  'https://x.test/wp-content/uploads/2026/09/banner-1200x675.jpg'    => 42,
  'https://x.test/wp-content/uploads/2026/09/banner-scaled.jpg'      => 42,
  'https://x.test/wp-content/uploads/2026/09/banner-768x432.jpg'     => 42,
  'https://cdn.example.com/external.png'                            => 0,
  ''                                                                => 0,
);
$fail = 0;
foreach ($cases as $url => $want) {
  $got = T::go($url);
  $ok = ($got === $want);
  if (!$ok) { $fail++; }
  printf("%-62s -> %-3d want %-3d %s\n", $url === '' ? '(empty)' : $url, $got, $want, $ok ? 'PASS' : 'FAIL');
}
echo $fail ? "\n$fail FAILED\n" : "\nall pass\n";
exit($fail ? 1 : 0);
