<?php
/* Verifies which cache tier Delicat_Builder_V9_Security assigns to a request. */
define('ABSPATH', '/');
$S = array('logged_in'=>false,'cart'=>false,'checkout'=>false,'account'=>false);
function is_admin(){ return false; } function wp_doing_ajax(){ return false; } function wp_doing_cron(){ return false; }
function is_user_logged_in(){ return $GLOBALS['S']['logged_in']; }
function is_cart(){ return $GLOBALS['S']['cart']; }
function is_checkout(){ return $GLOBALS['S']['checkout']; }
function is_account_page(){ return $GLOBALS['S']['account']; }
function sanitize_key($k){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$k)); }
function sanitize_text_field($s){ return is_scalar($s)?trim((string)$s):''; }
function wp_unslash($s){ return $s; }
function get_option($k,$d=false){ $o=array('woocommerce_currency'=>'HTG','dmc_settings'=>array(),'dmc_currencies'=>array()); return array_key_exists($k,$o)?$o[$k]:$d; }
function apply_filters($h,$v){ return $v; }
function wp_parse_url($u,$c=-1){ return parse_url($u,$c); }
function add_action($h,$c,$p=10,$a=1){} function add_filter($h,$c,$p=10,$a=1){}
function remove_action($h,$c,$p=10){} function do_action($h,$r=null){}
function nocache_headers(){}
function is_ssl(){ return true; } function get_current_user_id(){ return 0; }
function wp_salt($s=''){ return 'salt'; } function get_transient($k){ return false; } function set_transient($k,$v,$t){ return true; }
function current_user_can($c){ return false; } function is_admin_bar_showing(){ return false; }
function absint($v){ return abs((int)$v); } function esc_url_raw($u){ return $u; }
function is_wc_endpoint_url($e=''){ return false; }
require '/home/user/snowy-boat-a2d8/delicat-builder-v9/includes/class-delicat-builder-security.php';
$C = 'Delicat_Builder_V9_Security';

function tier($name, $setup, $want) {
  global $S, $C, $pass, $fail;
  $S = array('logged_in'=>false,'cart'=>false,'checkout'=>false,'account'=>false);
  $_GET = array(); $_POST = array(); $_COOKIE = array();
  $_SERVER = array('REQUEST_URI'=>'/boutique/','REQUEST_METHOD'=>'GET');
  $setup();
  $got = $C::response_cache_tier();
  $ok = ($got === $want);
  printf("%-52s %-10s want %-10s %s\n", $name, $got, $want, $ok?'PASS':'FAIL');
  $ok ? $pass++ : $fail++;
}
$pass=0; $fail=0;

tier('guest, no cookies, shop page',        function(){}, 'shared');
tier('guest with WooCommerce cart cookie',  function(){ $_COOKIE['woocommerce_items_in_cart']='1'; }, 'personal');
tier('guest with wp_woocommerce_session',   function(){ $_COOKIE['wp_woocommerce_session_abc']='x'; }, 'personal');
tier('signed-in customer on shop page',     function(){ $GLOBALS['S']['logged_in']=true; }, 'personal');
tier('signed-in customer on product page',  function(){ $GLOBALS['S']['logged_in']=true; $_SERVER['REQUEST_URI']='/produit/netflix/'; }, 'personal');
tier('cart page',                           function(){ $GLOBALS['S']['cart']=true; }, 'sensitive');
tier('checkout page',                       function(){ $GLOBALS['S']['checkout']=true; }, 'sensitive');
tier('account page',                        function(){ $GLOBALS['S']['account']=true; }, 'sensitive');
tier('wallet page',                         function(){ $GLOBALS['S']['logged_in']=true; $_SERVER['REQUEST_URI']='/my-wallet/'; }, 'sensitive');
tier('order-received page',                 function(){ $GLOBALS['S']['logged_in']=true; $_SERVER['REQUEST_URI']='/commande/order-received/12/'; }, 'sensitive');
tier('navigation fragment request',         function(){ $_GET['dbp_nav']='1'; }, 'sensitive');
tier('navigation fragment via header',      function(){ $_SERVER['HTTP_X_DELICAT_PRO_NAV']='1'; }, 'sensitive');
tier('add-to-cart action in query',         function(){ $_GET['add-to-cart']='12'; }, 'sensitive');
tier('logout link',                         function(){ $_GET['customer-logout']='true'; }, 'sensitive');
tier('nonce in query',                      function(){ $_GET['_wpnonce']='abc'; }, 'sensitive');
tier('Identity OAuth callback',             function(){ $_GET['dip_action']='callback'; }, 'sensitive');
tier('builder preview',                     function(){ $_GET['delicat_builder_preview']='1'; }, 'sensitive');
tier('guest with currency cookie',          function(){ $_COOKIE['dmc_currency']='USD'; }, 'personal');

echo "\n$pass passed, $fail failed\n"; exit($fail?1:0);
