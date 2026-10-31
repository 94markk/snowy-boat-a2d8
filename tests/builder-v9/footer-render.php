<?php
// Usage: php footer-render.php <wp-site-dir> <plugin-dir> <out.html> [bodyclasses]
$_SERVER['HTTP_HOST']='localhost:8950'; $_SERVER['REQUEST_URI']='/'; $_SERVER['SERVER_NAME']='localhost';
require $argv[1] . '/wp-load.php';
$dir = rtrim($argv[2], '/') . '/';
if (!defined('DELICAT_BUILDER_V9_DIR')) define('DELICAT_BUILDER_V9_DIR', $dir);
if (!defined('DELICAT_BUILDER_V9_URL')) define('DELICAT_BUILDER_V9_URL', 'http://localhost:8950/wp-content/plugins/delicat-builder-v9/');
if (!defined('DELICAT_BUILDER_V9_VERSION')) { preg_match("/define\( 'DELICAT_BUILDER_V9_VERSION', '([^']+)'/", file_get_contents($dir.'delicat-builder-v9.php'), $m); define('DELICAT_BUILDER_V9_VERSION', $m[1]); }
require_once $dir . 'includes/class-delicat-builder-footer.php';
if (is_file($dir . 'includes/class-delicat-builder-legal.php')) { @include_once $dir . 'includes/class-delicat-builder-legal.php'; }
update_option('blogname', 'Delicat Store Haiti');
$html = Delicat_Builder_V9_Footer::render_html();
$css = file_get_contents($dir . 'assets/css/native-footer.css');
$body = $argv[4] ?? 'dbv9-universal-footer-active delicat-shell-mobile-nav-active delicat-bottom-nav-active';
$out = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>footer</title><style>body{margin:0;font-family:Inter,system-ui,sans-serif;background:#fff}html.dlc-theme-dark body{background:#0b0d1a;color:#eef0fb}main{min-height:240px;padding:24px}.fake-nav{position:fixed;left:12px;right:12px;bottom:12px;height:64px;border-radius:24px;background:#11104a;opacity:.92}@media(min-width:821px){.fake-nav{display:none}}</style><style id="footer-css">' . $css . '</style></head><body class="' . htmlspecialchars($body) . '"><main><h1>Page</h1><p>Contenu</p></main>' . $html . '<div class="fake-nav" aria-hidden="true"></div></body></html>';
file_put_contents($argv[3], $out);
echo strlen($html) . " bytes footer\n";
