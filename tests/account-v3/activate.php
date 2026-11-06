<?php
$_SERVER['HTTP_HOST']=getenv('HOSTPORT'); $_SERVER['REQUEST_URI']='/';
require getenv('SITE').'/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
foreach (['delicat-identity-pro/delicat-google-login.php','delicat-recharge/delicat-recharge.php'] as $p) { $r = activate_plugin($p); echo $p, ' ', is_wp_error($r) ? $r->get_error_message() : 'ok', "\n"; }
flush_rewrite_rules(false);
