<?php
/* Minimal WP stubs so the real Security class can be exercised directly. */
define('ABSPATH', '/tmp/');
function sanitize_key($k){ $k=strtolower((string)$k); return preg_replace('/[^a-z0-9_\-]/','',$k); }
function sanitize_text_field($s){ return is_string($s)?trim(strip_tags($s)):''; }
function wp_unslash($v){ return $v; }
function apply_filters($tag,$value){ return $value; }
function is_admin(){ return false; }
function wp_doing_ajax(){ return false; }
function wp_doing_cron(){ return false; }
function is_user_logged_in(){ return $GLOBALS['T_LOGGED'] ?? false; }
function get_option($k,$d=false){ return $GLOBALS['T_OPTIONS'][$k] ?? $d; }
function wp_parse_url($u,$c=-1){ return parse_url($u,$c); }
function wp_salt($s=''){ return 'saltsalt'; }
function get_transient($k){ return false; }
function set_transient($k,$v,$t){ return true; }
function __($s,$d=null){ return $s; }
function is_cart(){ return false; }
function is_checkout(){ return false; }
function is_account_page(){ return false; }
function is_wc_endpoint_url($e=''){ return false; }
function is_admin_bar_showing(){ return false; }
function current_user_can($c){ return false; }
function do_action(){ }

function add_filter($t,$c,$p=10,$a=1){ return true; }
function add_action($t,$c,$p=10,$a=1){ return true; }
function remove_action($t,$c,$p=10){ return true; }
function __return_false(){ return false; }
function __return_empty_string(){ return ''; }
function is_ssl(){ return true; }
function wp_json_encode($v){ return json_encode($v); }
function esc_url_raw($u){ return $u; }
function home_url($p=''){ return 'https://example.test'.$p; }
function wp_create_nonce($a=''){ return 'n'; }
function wp_verify_nonce($n,$a=''){ return true; }
function absint($v){ return abs((int)$v); }

require __DIR__ . '/../includes/class-delicat-builder-security.php';

$S = 'Delicat_Builder_V9_Security';
$GLOBALS['T_OPTIONS'] = array(
    'woocommerce_currency' => 'HTG',
    'dmc_settings'   => array('default_currency'=>'HTG','lock_currency'=>'no'),
    'dmc_currencies' => array('HTG'=>array('enabled'=>'yes')),
);

function reset_memo($S){
    $r = new ReflectionClass($S);
    $p = $r->getProperty('public_cache_static');
    $p->setAccessible(true);
    $p->setValue(null, null);
}

$pass=0; $fail=0;
function check($label,$got,$want){
    global $pass,$fail;
    if($got===$want){ $pass++; printf("  ok    %-58s => %s\n",$label,var_export($got,true)); }
    else { $fail++; printf("  FAIL  %-58s => got %s want %s\n",$label,var_export($got,true),var_export($want,true)); }
}

function scenario($S,$label,$cookies,$get,$logged,$want){
    $_COOKIE = $cookies; $_GET = $get; $GLOBALS['T_LOGGED']=$logged;
    $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/boutique/';
    reset_memo($S);
    check($label, $S::public_cache_allowed(), $want);
}

echo "=== CACHEABLE (the traffic that used to render dynamically) ===\n";
scenario($S,'clean guest, no cookies, no query',            array(), array(), false, true);
scenario($S,'guest with woocommerce_items_in_cart',         array('woocommerce_items_in_cart'=>'3'), array(), false, true);
scenario($S,'guest with wp_woocommerce_session_abc',        array('wp_woocommerce_session_abc'=>'x'), array(), false, true);
scenario($S,'guest with woocommerce_cart_hash',             array('woocommerce_cart_hash'=>'h'), array(), false, true);
scenario($S,'guest with woocommerce_recently_viewed',       array('woocommerce_recently_viewed'=>'12|9'), array(), false, true);
scenario($S,'facebook ad click ?fbclid=',                   array(), array('fbclid'=>'abc'), false, true);
scenario($S,'google ad click ?gclid=',                      array(), array('gclid'=>'abc'), false, true);
scenario($S,'utm campaign bundle',                          array(), array('utm_source'=>'fb','utm_medium'=>'cpc','utm_campaign'=>'x'), false, true);
scenario($S,'archive page 2',                               array(), array('paged'=>'2'), false, true);
scenario($S,'archive sort by price',                        array(), array('orderby'=>'price'), false, true);
scenario($S,'layered nav filter_size',                      array(), array('filter_size'=>'m','query_type_size'=>'or'), false, true);
scenario($S,'ad click + cart cookie together',              array('woocommerce_items_in_cart'=>'2'), array('fbclid'=>'z'), false, true);
scenario($S,'analytics cookie containing "currency"',       array('_ga_currency_pref'=>'x'), array(), false, true);
scenario($S,'default dmc_currency cookie',                  array('dmc_currency'=>'HTG'), array(), false, true);

echo "\n=== MUST STAY PRIVATE (correctness / privacy) ===\n";
scenario($S,'logged-in user',                               array(), array(), true, false);
scenario($S,'wordpress_logged_in cookie',                   array('wordpress_logged_in_9f'=>'u'), array(), false, false);
scenario($S,'wordpress_sec cookie',                         array('wordpress_sec_9f'=>'u'), array(), false, false);
scenario($S,'password-protected post cookie',               array('wp_postpass_9f'=>'p'), array(), false, false);
scenario($S,'comment author cookie',                        array('comment_author_9f'=>'a'), array(), false, false);
scenario($S,'non-default dmc_currency (prices differ)',     array('dmc_currency'=>'USD'), array(), false, false);
scenario($S,'aelia currency switcher',                      array('aelia_cs_selected_currency'=>'USD'), array(), false, false);
scenario($S,'add-to-cart action',                           array(), array('add-to-cart'=>'55'), false, false);
scenario($S,'nonce present',                                array(), array('_wpnonce'=>'abc'), false, false);
scenario($S,'wc-ajax endpoint',                             array(), array('wc-ajax'=>'get_refreshed_fragments'), false, false);
scenario($S,'identity login callback',                      array(), array('dip_action'=>'callback'), false, false);
scenario($S,'download_file token',                          array(), array('download_file'=>'9','key'=>'k'), false, false);
scenario($S,'unknown/arbitrary param (no cache pollution)',  array(), array('zzz_random'=>'1'), false, false);
scenario($S,'search query (unbounded, left uncached)',       array(), array('s'=>'rum'), false, false);

echo "\n=== POST must never be cached ===\n";
$_COOKIE=array(); $_GET=array(); $GLOBALS['T_LOGGED']=false;
$_SERVER['REQUEST_METHOD']='POST'; reset_memo($S);
check('POST request', $S::public_cache_allowed(), false);

echo "\n=== DONOTCACHEPAGE defined AFTER first call is still honoured ===\n";
$_SERVER['REQUEST_METHOD']='GET'; $_COOKIE=array(); $_GET=array(); reset_memo($S);
check('before flag', $S::public_cache_allowed(), true);
define('DONOTCACHEPAGE', true);
check('after flag (memo must not win)', $S::public_cache_allowed(), false);

echo "\n---------------------------------------------\n";
printf("passed %d / failed %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
