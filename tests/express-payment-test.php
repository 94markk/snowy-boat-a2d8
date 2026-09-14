<?php
/* Stub-based test of Delicat_Builder_V9_Express_Payment guard-release decisions. */
define('ABSPATH', '/'); define('HOUR_IN_SECONDS',3600); define('DAY_IN_SECONDS',86400);
$GLOBALS['notes']=array();
function add_action($h,$c,$p=10,$a=1){} function add_filter($h,$c,$p=10,$a=1){}
function apply_filters($h,$v){ return $v; }
function wp_cache_delete($k,$g=''){} function maybe_serialize($v){ return is_array($v)||is_object($v)?serialize($v):$v; }
function maybe_unserialize($v){ $u=@unserialize($v); return false===$u && 'b:0;'!==$v ? $v : $u; }
function sanitize_text_field($s){ return is_scalar($s)?trim((string)$s):''; } function wp_unslash($s){ return $s; }
function is_ssl(){ return true; } function is_user_logged_in(){ return true; } function get_current_user_id(){ return 7; }
function wp_verify_nonce($n,$a){ return true; } function wp_send_json($d,$c=null){ throw new Exception('__exit__'); }
function esc_html($s){ return $s; } function esc_attr($s){ return $s; } function esc_url($s){ return $s; }
function wp_generate_uuid4(){ return '11111111-2222-3333-4444-555555555555'; }
function wp_create_nonce($a){ return 'n'; } function wc_get_account_endpoint_url($e){ return '/orders'; }
function current_user_can($c){ return false; } function admin_url($p=''){ return '/wp-admin/'.$p; }
function wp_nonce_field($a){} function absint($v){ return abs((int)$v); } function check_admin_referer($a){ return true; }
function wp_die($m=''){ throw new Exception('die'); } function wp_safe_redirect($u){}
function wp_next_scheduled($h){ return false; } function wp_schedule_event($t,$r,$h){} function delete_option($k){}
function wc_notice_count($t){ return 0; }
class FakeWpdb { public $options='wp_options'; public $prefix='wp_'; public $last_error=''; public $db=array(); public $ledger=0; public $ledgerTable=true;
  function prepare($q,...$a){ $i=0; return preg_replace_callback('/%s|%d/', function($m) use(&$i,$a){ return $a[$i++]; }, $q); }
  function esc_like($s){ return $s; }
  function get_var($q){
    if(strpos($q,'SHOW TABLES LIKE')!==false){ return $this->ledgerTable ? 'wp_woo_wallet_transaction_meta' : null; }
    if(strpos($q,'SELECT COUNT(*)')!==false){ return (string)$this->ledger; }
    if(preg_match('/option_name = (\S+)$/',$q,$m)) return $this->db[$m[1]] ?? null; return null; }
  function query($q){
    if(preg_match('/INSERT IGNORE INTO \S+ \(option_name, option_value, autoload\) VALUES \((.+?), (.+), \'no\'\)$/s',$q,$m)){ if(isset($this->db[$m[1]])) return 0; $this->db[$m[1]]=$m[2]; return 1; }
    if(preg_match('/UPDATE \S+ SET option_value = (.+) WHERE option_name = (\S+) AND BINARY option_value = BINARY (.+)$/s',$q,$m)){ if(($this->db[$m[2]]??null)===$m[3]){ $this->db[$m[2]]=$m[1]; return 1;} return 0; }
    if(preg_match('/DELETE FROM \S+ WHERE option_name = (\S+) AND BINARY option_value = BINARY (.+)$/s',$q,$m)){ if(($this->db[$m[1]]??null)===$m[2]){ unset($this->db[$m[1]]); return 1;} return 0; }
    return false; }
  function get_results($q){ return array(); } }
$wpdb = new FakeWpdb();
class FakeOrder { public $status='pending'; public $paid=false; public $txn=''; public $meta=array();
  function get_id(){ return 501; } function is_paid(){ return $this->paid; } function get_date_paid(){ return $this->paid?'now':null; }
  function get_transaction_id(){ return $this->txn; } function get_status(){ return $this->status; } function get_customer_id(){ return 7; }
  function get_meta($k){ return $this->meta[$k] ?? ''; }
  function add_order_note($n){ $GLOBALS['notes'][]=$n; } function update_meta_data($k,$v){ $this->meta[$k]=$v; } function save(){} }
$GLOBALS['order']=new FakeOrder(); function wc_get_order($id){ return $GLOBALS['order']; }
class FakeWalletApi { public $balance=100.0; function get_wallet_balance($uid,$ctx=''){ return $this->balance; } }
class FakeWallet { public $wallet; function __construct(){ $this->wallet=new FakeWalletApi(); } }
$GLOBALS['wallet']=new FakeWallet(); function woo_wallet(){ return $GLOBALS['wallet']; }
class FakeCart { function is_empty(){ return false; } function calculate_totals(){} function get_cart_hash(){ return 'HASH'; } function needs_shipping(){ return false; } }
class FakeSession { public $d=array(); function get($k){ return $this->d[$k]??null; } function set($k,$v){ $this->d[$k]=$v; } function __unset($k){ unset($this->d[$k]); } }
class FakeWC { public $cart; public $session; function __construct(){ $this->cart=new FakeCart(); $this->session=new FakeSession(); } }
$GLOBALS['wc']=new FakeWC(); function WC(){ return $GLOBALS['wc']; }

require '/home/user/snowy-boat-a2d8/delicat-builder-v9/includes/class-delicat-builder-express-payment.php';
$C='Delicat_Builder_V9_Express_Payment';
$ref=new ReflectionClass($C); $ctxProp=$ref->getProperty('context'); $ctxProp->setAccessible(true);
$begin=$ref->getMethod('begin'); $begin->setAccessible(true);
$KEY='delicat_payment_7_11111111-2222-3333-4444-555555555555'; $ACTIVE='delicat_payment_active_7';
$pass=0; $fail=0;

function run($name,$method,$setup,$expectReleased,$expectStatus){
  global $wpdb,$ctxProp,$begin,$C,$KEY,$ACTIVE,$pass,$fail;
  $wpdb->db=array(); $wpdb->ledger=0; $wpdb->ledgerTable=true; $GLOBALS['notes']=array();
  $ctxProp->setValue(null,null); $GLOBALS['order']=new FakeOrder();
  $GLOBALS['wallet']->wallet=new FakeWalletApi();
  $_SERVER['REQUEST_METHOD']='POST';
  $_POST=array('delicat_intent'=>'11111111-2222-3333-4444-555555555555','delicat_cart_hash'=>'HASH','delicat_issued'=>'1000.000000','delicat_intent_nonce'=>'n','payment_method'=>$method);
  $begin->invoke(null);
  $C::creating($GLOBALS['order'],array()); $C::order_created($GLOBALS['order']);
  $setup();
  $C::shutdown();
  $released = ! isset($wpdb->db[$ACTIVE]);
  $rec = maybe_unserialize($wpdb->db[$KEY]); $status = is_array($rec)?$rec['status']:'MISSING';
  $ok = ($released===$expectReleased) && ($status===$expectStatus);
  printf("%-55s released=%-5s status=%-9s %s\n",$name,var_export($released,true),$status,$ok?'PASS':'FAIL');
  $ok ? $pass++ : $fail++;
}
/* A gateway decline observed through woocommerce_payment_successful_result. */
$decline = function(){ global $C; $C::success(array('result'=>'failure'),501); };

run('wallet decline, ledger quiet, balance equal',        'wallet',  $decline, true,  'declined');
run('CRITICAL non-wallet gateway (moncash) -> keep',      'moncash', $decline, false, 'pending');
run('CRITICAL partial wallet debit marker -> keep',       'wallet',  function() use($decline){ $decline(); $GLOBALS['order']->meta['_via_wallet_payment']='1'; }, false, 'pending');
run('ledger row references the order -> keep',            'wallet',  function() use($decline){ $decline(); $GLOBALS['wpdb']->ledger=1; }, false, 'pending');
run('gateway threw, no verdict recorded -> keep',         'wallet',  function(){}, false, 'pending');
run('gateway success, order not yet paid -> submitted',   'wallet',  function(){ global $C; $C::success(array('result'=>'success'),501); }, true,  'submitted');
run('balance non-numeric (HTML) -> keep',                 'wallet',  function() use($decline){ $decline(); $GLOBALS['wallet']->wallet=new class { function get_wallet_balance($u,$c=''){ return '<span>100</span>'; } }; }, false, 'pending');
run('balance changed (debited) -> keep',                  'wallet',  function() use($decline){ $decline(); $GLOBALS['wallet']->wallet->balance=60.0; }, false, 'pending');
run('order paid -> keep',                                 'wallet',  function() use($decline){ $decline(); $GLOBALS['order']->paid=true; $GLOBALS['order']->status='processing'; }, false, 'pending');
run('transaction id present -> keep',                     'wallet',  function() use($decline){ $decline(); $GLOBALS['order']->txn='TX1'; }, false, 'pending');
run('status on-hold -> keep',                             'wallet',  function() use($decline){ $decline(); $GLOBALS['order']->status='on-hold'; }, false, 'pending');
run('empty payment_method -> keep',                       '',        $decline, false, 'pending');
run('ledger table absent (no TeraWallet) -> release',     'wallet',  function() use($decline){ $decline(); $GLOBALS['wpdb']->ledgerTable=false; }, true, 'declined');
run('ledger query errors -> keep',                        'wallet',  function() use($decline){ $decline(); $GLOBALS['wpdb']->last_error='gone'; }, false, 'pending');

/* Success path still releases and stamps the completion marker. */
{ $wpdb->db=array(); $wpdb->ledger=0; $wpdb->last_error=''; $ctxProp->setValue(null,null); $GLOBALS['order']=new FakeOrder();
  $_POST['payment_method']='wallet'; $begin->invoke(null); $C::creating($GLOBALS['order'],array()); $C::order_created($GLOBALS['order']);
  $GLOBALS['order']->paid=true; $GLOBALS['order']->status='completed';
  $C::success(array('result'=>'success','redirect'=>'/thanks'),501); $C::shutdown();
  $rec=maybe_unserialize($wpdb->db[$KEY]);
  $ok = !isset($wpdb->db[$ACTIVE]) && $rec['status']==='confirmed' && isset($wpdb->db['delicat_payment_completed_7']);
  printf("%-55s %s\n",'success path releases + stamps completion',$ok?'PASS':'FAIL'); $ok?$pass++:$fail++; }
/* A used intent can never be replayed. */
{ $ctxProp->setValue(null,null); $ok=false; try { $begin->invoke(null); } catch (RuntimeException $e) { $ok = false!==strpos($e->getMessage(),'déjà'); }
  printf("%-55s %s\n",'replay of a used intent refused',$ok?'PASS':'FAIL'); $ok?$pass++:$fail++; }
/* Nothing created yet: pre-existing PRO14 behaviour, released as rejected. */
{ $wpdb->db=array(); $ctxProp->setValue(null,null); $GLOBALS['order']=new FakeOrder(); $begin->invoke(null); $C::shutdown();
  $rec=maybe_unserialize($wpdb->db[$KEY]); $ok = !isset($wpdb->db[$ACTIVE]) && $rec['status']==='rejected';
  printf("%-55s %s\n",'no order created -> rejected + released',$ok?'PASS':'FAIL'); $ok?$pass++:$fail++; }

echo "\n$pass passed, $fail failed\n"; exit($fail?1:0);
