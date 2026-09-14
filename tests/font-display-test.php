<?php
function add_query_arg($k,$v,$u){ return $u . (strpos($u,'?')===false?'?':'&') . $k . '=' . $v; }
function esc_attr($u){ return htmlspecialchars($u, ENT_QUOTES, 'UTF-8'); }
$src = file_get_contents('/home/user/snowy-boat-a2d8/delicat-builder-v9/includes/class-delicat-builder-app-tuning.php');
preg_match('/\$rewritten = preg_replace_callback\(.*?\n\t\t\);/s', $src, $m);
$body = $m[0];
$run = function ($tag) use ($body) { eval($body); return is_string($rewritten) && '' !== $rewritten ? $rewritten : $tag; };
$cases = array(
  '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins" media="all">',
  "<link rel='stylesheet' href='https://fonts.googleapis.com/css2?family=X&#038;weight=400'>",
  '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Y&display=block">',
  '<link rel="stylesheet" href="https://example.com/theme.css">',
);
foreach ($cases as $t) { echo "in : $t\nout: " . $run($t) . "\n\n"; }
