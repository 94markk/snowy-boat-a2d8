<?php
// Isolated behavioral tests; no WordPress or supplier requests are made.
if ( PHP_SAPI !== 'cli' ) { exit; }
define('ABSPATH', __DIR__ . '/');
define('DST2T_VERSION', '1.1.0');
define('DELICAT_S2T_KEY_ID', 'test');
define('DELICAT_S2T_KEY_SECRET', 'test');
define('DELICAT_S2T_WEBHOOK_SECRET', 'test-signing-secret');
$GLOBALS['options'] = $GLOBALS['transients'] = $GLOBALS['responses'] = $GLOBALS['calls'] = $GLOBALS['jobs'] = [];
function absint($v) { return abs((int)$v); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($v)); }
function sanitize_text_field($v) { return strip_tags((string)$v); }
function wp_json_encode($v,$flags=0) { return json_encode($v,$flags); }
function wp_unslash($v) { return is_array($v)?array_map('wp_unslash',$v):stripslashes((string)$v); }
function __($v,$domain='') { return $v; }
function home_url($v='') { return 'https://example.test'.$v; }
function wp_salt($v) { return 'test-salt-'.$v; }
function wp_parse_args($v,$defaults) { return array_merge($defaults,$v); }
function get_option($k,$d=false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k,$v,$autoload=false) { $GLOBALS['options'][$k]=$v; return true; }
function add_option($k,$v,$deprecated='',$autoload=false) { if(isset($GLOBALS['options'][$k]))return false; $GLOBALS['options'][$k]=$v; return true; }
function wp_cache_delete($k,$g) {}
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k,$v,$ttl) { $GLOBALS['transients'][$k]=$v; return true; }
function wp_generate_uuid4() { return sprintf('00000000-0000-4000-8000-%012d',random_int(1,999999)); }
function add_query_arg($q,$url) { return $url.'?'.http_build_query($q); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error { function get_error_code(){return 'timeout';} function get_error_message(){return 'timeout';} }
function wp_safe_remote_request($url,$args) { $GLOBALS['calls'][]=[$url,$args]; if(!$GLOBALS['responses'])throw new Exception('Unexpected HTTP request '.$url); return array_shift($GLOBALS['responses']); }
function wp_remote_retrieve_response_code($r) { return $r['http']; }
function wp_remote_retrieve_body($r) { return json_encode($r['body']); }
function wp_remote_retrieve_header($r,$h) { return $r['headers'][$h] ?? ''; }
function as_enqueue_async_action($h,$a,$g,$u=false){ $GLOBALS['jobs'][]=[$h,$a]; return count($GLOBALS['jobs']); }
function as_schedule_single_action($t,$h,$a,$g,$u=false){ $GLOBALS['jobs'][]=[$h,$a]; return count($GLOBALS['jobs']); }
function as_has_scheduled_action($h,$a,$g){return false;}
function wc_get_order($id){return $GLOBALS['orders'][$id] ?? false;}
function delete_option($k){unset($GLOBALS['options'][$k]);return true;}
function is_admin(){return !empty($GLOBALS['is_admin']);}
function wp_doing_ajax(){return false;}
function current_user_can($c){return !empty($GLOBALS['is_manager']);}
function doing_action($a){return !empty($GLOBALS['doing_action'][$a]);}
function get_current_user_id(){return 0;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
function esc_attr__($v,$d=''){return esc_attr($v);}
function esc_html__($v,$d=''){return esc_html($v);}
function wp_kses_post($v){return (string)$v;}
function human_time_diff($from,$to=0){return abs((int)$to-(int)$from).' seconds';}
function wp_http_validate_url($url){return filter_var($url,FILTER_VALIDATE_URL)?$url:false;}
function has_post_thumbnail($id){return false;}
function wc_get_product_id_by_sku($sku){return $GLOBALS['skus'][$sku] ?? 0;}
function wc_get_product($id){return $GLOBALS['wc_products'][$id] ?? false;}
function get_post_meta($id,$k,$single=true){return $GLOBALS['postmeta'][$id][$k] ?? '';}
function update_post_meta($id,$k,$v){$GLOBALS['postmeta'][$id][$k]=$v;return true;}
function delete_post_meta($id,$k){unset($GLOBALS['postmeta'][$id][$k]);return true;}
function wc_delete_product_transients($id){}
function wp_get_post_parent_id($id){return 0;}
function wp_generate_password($l=12,$s=true,$e=false){return str_repeat('a',$l);}
function wp_rand($min=0,$max=1){return $min;}
function rest_url($path=''){return 'https://example.test/wp-json/'.ltrim($path,'/');}
function wp_send_json_success($d){throw new Exception('json:'.json_encode($d));}
function wp_send_json_error($d,$c=0){throw new Exception('jsonerr:'.json_encode($d));}
const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS = 3600;
const DAY_IN_SECONDS = 86400;
$GLOBALS['skus'] = $GLOBALS['wc_products'] = $GLOBALS['postmeta'] = [];
class WC_Product {
 public $id=0,$data=[],$saved=0;
 function get_id(){return $this->id;}
 function set_name($v){$this->data['name']=$v;}
 function set_description($v){$this->data['description']=$v;}
 function set_virtual($v){$this->data['virtual']=$v;}
 function set_sku($v){$this->data['sku']=$v;}
 function set_status($v){$this->data['status']=$v;}
 function get_status(){return $this->data['status'] ?? 'publish';}
 function get_sale_price(){return $this->data['sale_price'] ?? '';}
 function set_regular_price($v){$this->data['regular_price']=$v;}
 function set_price($v){$this->data['price']=$v;}
 function get_regular_price(){return $this->data['regular_price'] ?? '';}
 function get_stock_status(){return $this->data['stock_status'] ?? 'instock';}
 function set_stock_status($v){$this->data['stock_status']=$v;}
 function get_manage_stock(){return !empty($this->data['manage_stock']);}
 function set_manage_stock($v){$this->data['manage_stock']=$v;}
 function get_stock_quantity(){return $this->data['stock_quantity'] ?? 0;}
 function set_stock_quantity($v){$this->data['stock_quantity']=$v;}
 function save(){if(!$this->id){$this->id=count($GLOBALS['wc_products'])+100;}$this->saved++;$GLOBALS['wc_products'][$this->id]=$this;return $this->id;}
}
class WC_Product_Simple extends WC_Product {}
class TestDB {
 public $options='wp_options'; public $posts='wp_posts'; public $postmeta='wp_postmeta'; public $prefix='wp_';
 function prepare($sql,...$args){return [$sql,$args];}
 function get_var($q){return get_option($q[1][0],'');}
 function query($q){[$sql,$a]=$q;if(strpos($sql,'INSERT IGNORE')===0){if(isset($GLOBALS['options'][$a[0]]))return 0;update_option($a[0],$a[1]);return 1;}if(strpos($sql,'DELETE')===0){if(get_option($a[0])===$a[1]){unset($GLOBALS['options'][$a[0]]);return 1;}return 0;}if(get_option($a[1])===$a[2]){update_option($a[1],$a[0]);return 1;}return 0;}
}
$GLOBALS['wpdb']=new TestDB();
class DST2T_Repository {
 public $rows=[]; public $fail=false;
 function get($uuid){return $this->rows[$uuid] ?? false;}
 function register_intent($uuid,$oid,$iid,$hash){$this->rows[$uuid]=['wc_order_id'=>$oid,'wc_item_id'=>$iid,'status'=>'intent','attempt_count'=>0,'payload_hash'=>$hash,'next_check_at'=>null];return true;}
 function update($uuid,$status,$args=[]){if($this->fail)return false; $this->rows[$uuid]['status']=$status; if(!empty($args['increment_attempt']))$this->rows[$uuid]['attempt_count']++; foreach($args as $k=>$v)if($k!=='increment_attempt')$this->rows[$uuid][$k]=$k==='next_check_at'&&$v?gmdate('Y-m-d H:i:s',$v):$v;return true;}
 function is_stale_event($u,$t){return false;}
}
class WC_Order_Item_Product {
 public $meta=[];public $id=1;
 function get_meta($k,$single=true){return $this->meta[$k] ?? '';}
 function update_meta_data($k,$v){$this->meta[$k]=$v;}
 function save(){}
 function get_id(){return $this->id;}
 function get_quantity(){return 1;}
 function get_variation_id(){return 0;}
 function get_product_id(){return 1;}
}
class WC_Order {
 public $status='processing';public $items=[];public $meta=[];public $notes=[];
 function get_id(){return 1;}
 function get_item($id){return $this->items[$id] ?? false;}
 function get_items($type='line_item'){return $this->items;}
 function is_paid(){return in_array($this->status,['processing','completed'],true);}
 function has_status($s){return in_array($this->status,(array)$s,true);}
 function update_status($s,$n=''){$this->status=$s;}
 function get_meta($k,$s=true){return $this->meta[$k] ?? '';}
 function update_meta_data($k,$v){$this->meta[$k]=$v;}
 function add_order_note($n){$this->notes[]=$n;}
 function save(){}
}
class WP_REST_Request { public $raw,$headers,$params;function __construct($raw,$headers,$params=[]){$this->raw=$raw;$this->headers=$headers;$this->params=$params;}function get_body(){return $this->raw;}function get_header($k){return $this->headers[$k] ?? '';}function get_param($k){return $this->params[$k] ?? '';}}
function wc_format_decimal($v,$dp=2){return number_format((float)$v,(int)$dp,'.','');}
function wc_get_price_decimals(){return 2;}
function add_action(...$a){}
function add_filter(...$a){}
class WP_REST_Response { public $data,$status; function __construct($d,$s){$this->data=$d;$this->status=$s;} }
foreach(['api-exception','decimal','vault','settings','brand','api-client','product','fulfillment','balance','sync','privacy','webhook','admin'] as $file) require dirname(__DIR__).'/includes/class-dst2t-'.$file.'.php';
$vault=new DST2T_Vault();$settings=new DST2T_Settings($vault);DST2T_Brand::boot($settings);$api=new DST2T_API_Client($settings);$repo=new DST2T_Repository();
$products=new DST2T_Product($api,$settings);
$GLOBALS['postmeta'][1]=[DST2T_Product::META_ENABLED=>'yes',DST2T_Product::META_ITEM_ID=>999,DST2T_Product::META_CATEGORY_ID=>7,DST2T_Product::META_LAST_COST=>'1.000000'];
$ful=new DST2T_Fulfillment($api,$settings,$repo,$products,$vault);$web=new DST2T_Webhook($settings,$repo,$ful);
$balance=new DST2T_Balance($api,$settings);$syncer=new DST2T_Sync($api,$settings,$products);
$admin=new DST2T_Admin($settings,$api,$repo,$products,$ful,$balance,$syncer);
function defaults_restore(){ $GLOBALS['options']['dst2t_settings']=DST2T_Settings::defaults(); }
function settings_with($overrides){ $GLOBALS['options']['dst2t_settings']=array_merge(DST2T_Settings::defaults(),$overrides); }
function invoke($object,$method,...$args){ $ref=new ReflectionMethod(get_class($object),$method); $ref->setAccessible(true); return $ref->invokeArgs($object,$args); }
$n=0;function check($ok,$name){global $n;if(!$ok)throw new Exception('FAIL: '.$name);echo 'PASS '.$name."\n";$n++;}
function respond($body,$http=200){$GLOBALS['responses'][]=['http'=>$http,'body'=>$body];}
function reset_http(){ $GLOBALS['responses']=$GLOBALS['calls']=$GLOBALS['transients']=[]; }
function fixture($status='intent') { global $repo; $u='11111111-1111-4111-8111-111111111111';$i=new WC_Order_Item_Product();$i->meta=['_dst2t_order_id'=>$u,'_dst2t_item_id'=>999,'_dst2t_status'=>$status,'_dst2t_requirements'=>'{}'];$o=new WC_Order();$o->items=[1=>$i];$GLOBALS['orders']=[1=>$o];$repo->register_intent($u,1,1,hash('sha256',json_encode([999,1,[]])));$repo->rows[$u]['status']=$status;return [$u,$o,$i];}
check(DST2T_Decimal::compare('0.950001','0.950000')===1,'Exact micro-unit comparison');
check(DST2T_Decimal::add_percent('0.95','10')==='1.045000','Exact cost guard');
$enc=$vault->encrypt('secret-voucher');check($vault->decrypt($enc)==='secret-voucher' && strpos($enc,'secret-voucher')===false,'Voucher encryption round trip');
check($vault->decrypt(substr($enc,0,-3).'xxx')==='','Tampered ciphertext rejected');
reset_http();respond(['success'=>true,'order'=>['order_id'=>'uuid','status'=>'pending']]);$api->create_order('uuid',999,1,[],'0.95');$body=json_decode($GLOBALS['calls'][0][1]['body']);check(is_object($body->requirements),'Empty voucher requirements encoded as object');check($body->expected_unit_price==='0.950000','Price sent as decimal string');
reset_http();respond(['account'=>[]]);try{$api->account();check(false,'Missing success rejected');}catch(DST2T_API_Exception $e){check(true,'Missing success rejected');}
reset_http();respond(['success'=>false,'error'=>['code'=>'RATE_LIMIT_EXCEEDED','retry_after'=>123]],429);try{$api->account();}catch(DST2T_API_Exception $e){check($e->get_retry_after()===123,'Top-level retry_after respected');}
reset_http();respond(['success'=>true,'data'=>['player_name'=>'Player']]);check($api->validate_player(999,['player_id'=>'999'])['player_name']==='Player','Alternate documented player envelope');
[$u,$o,$i]=fixture();$raw=json_encode(['event'=>'order.completed','timestamp'=>gmdate('c'),'data'=>['order_id'=>$u,'status'=>'pending']]);$headers=['x-shop2topup-event'=>'order.completed','x-shop2topup-signature'=>'sha256='.hash_hmac('sha256',$raw,'test-signing-secret')];$jobs_before=count($GLOBALS['jobs']);$r=$web->receive(new WP_REST_Request($raw,$headers));check($r->status===200&&count($GLOBALS['jobs'])===$jobs_before+1,'Unit completion with pending aggregate queued');check($o->status==='processing','Webhook does not prematurely complete order');check($web->receive(new WP_REST_Request($raw.' ',$headers))->status===404,'Forged webhook is answered as a missing route while stealth mode is on');
settings_with(['stealth_mode'=>'no']);check($web->receive(new WP_REST_Request($raw.' ',$headers))->status===401,'Forged webhook rejected');defaults_restore();
reset_http();[$u,$o,$i]=fixture('pending');respond(['success'=>true,'order'=>['order_id'=>$u,'status'=>'completed']]);$ful->process_item(1,1);check(count($GLOBALS['calls'])===1&&strpos($GLOBALS['calls'][0][0],'/orders/create')===false,'Pending worker only reads existing order');check($o->status==='completed','Verified complete order completes WooCommerce');
$ful->apply_provider_order($u,['order_id'=>$u,'status'=>'pending']);check($i->get_meta('_dst2t_status')==='completed','Late pending result cannot downgrade completed item');
[$u,$o,$i]=fixture();$o->status='cancelled';$ful->apply_provider_order($u,['order_id'=>$u,'status'=>'completed']);check($o->status==='cancelled','Late delivery preserves cancellation');
[$u,$o,$i]=fixture();$o->status='refunded';$ful->apply_provider_order($u,['order_id'=>$u,'status'=>'completed']);check($o->status==='refunded','Late delivery preserves customer refund');
[$u,$o,$i]=fixture();$o->status='pending';$ful->apply_provider_order($u,['order_id'=>$u,'status'=>'completed']);check($o->status==='pending','Unpaid order never auto-completes');
[$u,$o,$i]=fixture();$o->items[2]=new WC_Order_Item_Product();$ful->apply_provider_order($u,['order_id'=>$u,'status'=>'completed']);check($o->status==='processing','Mixed orders retain WooCommerce control');
[$u,$o,$i]=fixture();$ful->apply_provider_order($u,['order_id'=>$u,'status'=>'partial']);check($o->status==='on-hold'&&$repo->rows[$u]['next_check_at']!==null,'Partial delivery held and still reconciled');
reset_http();[$u,$o,$i]=fixture();$repo->fail=true;respond(['success'=>true,'price'=>['unit_price'=>'0.95','currency'=>'USD']]);$ful->process_item(1,1);check(count($GLOBALS['calls'])===1,'Failed submission persistence prevents purchase');$repo->fail=false;
reset_http();[$u,$o,$i]=fixture();$o->status='pending';$ful->process_item(1,1);check(count($GLOBALS['calls'])===0,'Unpaid order never submitted');
reset_http();[$u,$o,$i]=fixture('unknown');$repo->rows[$u]['attempt_count']=1;respond(['success'=>true,'order'=>['order_id'=>$u,'status'=>'pending']]);$ful->process_item(1,1);check(count($GLOBALS['calls'])===1&&$repo->rows[$u]['status']==='pending','Timeout recovery looks up original UUID');
reset_http();[$u,$o,$i]=fixture();respond(['success'=>true,'price'=>['unit_price'=>'0.95','currency'=>'USD']]);respond(['success'=>true,'order'=>['order_id'=>$u,'status'=>'pending']]);$ful->process_item(1,1);$ful->process_item(1,1);check(count($GLOBALS['calls'])===2,'Repeated jobs do not create duplicate orders');
reset_http();[$u,$o,$i]=fixture('pending');$i->meta['_dst2t_returns_voucher']='yes';respond(['success'=>true,'order'=>['order_id'=>$u,'status'=>'completed','vouchers'=>[['serial_number'=>'no-code']]]]);$ful->apply_provider_order($u,['order_id'=>$u,'status'=>'completed']);check($o->status==='on-hold'&&$i->get_meta('_dst2t_status')==='voucher_missing','Malformed voucher cannot complete delivery');

// ---------------------------------------------------------------- white label
defaults_restore();
check(DST2T_Brand::scrub('Powered by Shop2TopUp')==='Powered by Top-Up Provider','Supplier name scrubbed from catalog copy');
check(strpos(DST2T_Brand::scrub('Buy at https://shop2topup.com/item/1 now'),'shop2topup')===false,'Supplier URL scrubbed from catalog copy');
settings_with(['stealth_mode'=>'no']);
check(DST2T_Brand::scrub('Powered by Shop2TopUp')==='Powered by Shop2TopUp','Scrubbing is opt-out');
defaults_restore();
check(DST2T_Brand::mask_value('11111111-1111-4111-8111-111111111111',6)==='••••••••111111','Identifier masked to its last characters');
$secret=DST2T_Brand::secret_html('11111111-1111-4111-8111-111111111111',6);
check(strpos($secret,'data-dst2t-secret="11111111-1111-4111-8111-111111111111"')!==false && strpos($secret,'<code class="dst2t-secret-value">11111111')===false,'Dashboard renders identifiers masked with an explicit reveal');
settings_with(['mask_identifiers'=>'no']);
check(strpos(DST2T_Brand::secret_html('abc123'),'dst2t-reveal')===false,'Masking can be turned off');
defaults_restore();
check(DST2T_Brand::sku_prefix()==='tu-','Neutral SKU prefix by default');

// ------------------------------------------------------- customer visibility
$rows=[
 1=>(object)['key'=>'_dst2t_order_id','value'=>'11111111-1111-4111-8111-111111111111','display_key'=>'_dst2t_order_id','display_value'=>'x'],
 2=>(object)['key'=>'Fulfilled by','value'=>'Shop2TopUp','display_key'=>'Fulfilled by','display_value'=>'Shop2TopUp'],
 3=>(object)['key'=>'Player ID','value'=>'900123','display_key'=>'Player ID','display_value'=>'900123'],
];
$visible=DST2T_Privacy::filter_item_meta($rows);
check(!isset($visible[1]),'Internal fulfillment metadata never reaches the customer order view');
check(!isset($visible[2]),'Supplier attribution never reaches the customer order view');
check(isset($visible[3]) && $visible[3]->display_value==='900123','Customer still sees their own game details');
$GLOBALS['is_admin']=true;$GLOBALS['is_manager']=true;
check(count(DST2T_Privacy::filter_item_meta($rows))===3,'Shop managers still see the full item metadata in wp-admin');
$GLOBALS['doing_action']=['woocommerce_email_order_details'=>true];
check(count(DST2T_Privacy::filter_item_meta($rows))===1,'An order email rendered from wp-admin is still scrubbed');
$GLOBALS['doing_action']=[];$GLOBALS['is_admin']=false;$GLOBALS['is_manager']=false;
settings_with(['stealth_mode'=>'no']);
check(count(DST2T_Privacy::filter_item_meta($rows))===3,'Metadata filtering follows the stealth setting');
defaults_restore();
check(DST2T_Webhook::owns_namespace('store-callbacks/v1') && DST2T_Webhook::owns_namespace('delicat-shop2topup/v1') && !DST2T_Webhook::owns_namespace('wc/v3'),'Only plugin namespaces are hidden from the REST index');

// -------------------------------------------------------------- webhook edge
function signed($payload,$headers=[],$secret='test-signing-secret'){ $raw=json_encode($payload); return new WP_REST_Request($raw,array_merge(['x-shop2topup-signature'=>'sha256='.hash_hmac('sha256',$raw,$secret)],$headers)); }
[$u,$o,$i]=fixture();
$before=count($GLOBALS['jobs']);
check($web->receive(signed(['event'=>'order.processing','timestamp'=>gmdate('c'),'data'=>['order_id'=>$u]]))->status===200 && count($GLOBALS['jobs'])===$before+1,'Any order lifecycle event triggers authoritative reconciliation');
$before=count($GLOBALS['jobs']);
check($web->receive(signed(['event'=>'order.completed','data'=>['order_id'=>$u]]))->status===200 && count($GLOBALS['jobs'])===$before+1,'A callback without a timestamp is still accepted');
$raw=json_encode(['event'=>'order.completed','timestamp'=>gmdate('c'),'data'=>['order_id'=>$u]]);
$bare=new WP_REST_Request($raw,['x-signature'=>hash_hmac('sha256',$raw,'test-signing-secret')]);
check($web->receive($bare)->status===200,'Bare-hex signature on an alternate header is accepted');
$bad=new WP_REST_Request($raw,['x-signature'=>hash_hmac('sha256',$raw,'wrong-secret')]);
check($web->receive($bad)->status===404,'Alternate header does not weaken signature verification');
$ignored=$web->receive(signed(['event'=>'invoice.paid','data'=>['order_id'=>$u]]));
check($ignored->status===200 && !empty($ignored->data['ignored']),'Unrelated events are acknowledged, not rejected');
check($web->receive(signed(['event'=>'order.completed','timestamp'=>gmdate('c'),'data'=>['order_id'=>$u]],['x-shop2topup-event'=>'order.failed']))->status===400,'A mismatched event header is still rejected');
check($web->receive(signed(['event'=>'order.completed','timestamp'=>'2999-01-01T00:00:00Z','data'=>['order_id'=>$u]]))->status===400,'A future-dated callback is rejected');
check(preg_match('/^[a-f0-9]{32}$/',DST2T_Webhook::token())===1,'A private callback token is generated on demand');
$token=DST2T_Webhook::token();
check($web->receive_private(new WP_REST_Request('{}',[],['token'=>str_repeat('0',32)]))->status===404,'The private callback rejects a wrong token');
check(strpos(DST2T_Webhook::url(),$token)!==false && strpos(DST2T_Webhook::url(),'shop2topup')===false,'The active callback URL is unbranded');
check(DST2T_Webhook::legacy_url()==='https://example.test/wp-json/delicat-shop2topup/v1/webhook' && DST2T_Webhook::legacy_enabled(),'The original callback URL keeps working after an upgrade');
settings_with(['legacy_webhook'=>'no']);check(DST2T_Webhook::legacy_enabled()===false,'The provider-named route can be retired once migration is confirmed');
settings_with(['legacy_webhook'=>'no','private_webhook'=>'no']);check(DST2T_Webhook::legacy_enabled()===true,'Retiring the old route cannot leave the site with no callback at all');
defaults_restore();

// ------------------------------------------------------------------ balance
reset_http();
respond(['success'=>true,'account'=>['balance'=>'12.5','currency'=>'USD','enabled'=>true,'verified'=>true]]);
$state=$balance->refresh(true);
check($state['formatted']==='12.50 USD','Wallet balance is read from an alternate field name');
check($state['enabled']===true && $state['latency_ms']>=0 && $state['error']==='','Account flags are cached alongside the balance');
check($balance->state()['formatted']==='12.50 USD','Cached balance is served without a network call');
settings_with(['low_balance_threshold'=>'20.000000']);
check($balance->state()['low']===true,'Low balance threshold raises a warning');
settings_with(['low_balance_threshold'=>'5.000000']);
check($balance->state()['low']===false,'Sufficient balance clears the warning');
defaults_restore();
check($balance->state()['auto']===true && $balance->interval_seconds()===600,'Automatic refresh is on by default every ten minutes');
reset_http();
respond(['success'=>false,'error'=>['code'=>'SERVICE_UNAVAILABLE']],503);
$state=$balance->refresh(true);
check($state['error']==='SERVICE_UNAVAILABLE' && $state['formatted']==='12.50 USD','A failed check records the error and keeps the last known balance');

// ------------------------------------------------------------------ settings
$settings->save(['brand_label'=>'  My Provider  ','import_sku_prefix'=>'TU_@#','balance_interval'=>'0','catalog_sync_interval'=>'360']);
check($settings->get('brand_label')==='My Provider','Provider label is trimmed and stored');
check(DST2T_Brand::sku_prefix()==='tu_','SKU prefix is reduced to safe characters');
check($settings->get('balance_interval')==='10','An out-of-range refresh interval falls back to the default');
check($settings->get('catalog_sync_interval')==='360','Catalog sync interval is stored');
check($settings->get('stealth_mode')==='no' && $settings->get('validate_player')==='no','Unchecked boxes are saved as off');
defaults_restore();

// ------------------------------------------------------------ stock mirroring
$product=new WC_Product_Simple(); $product->id=800; $GLOBALS['wc_products'][800]=$product;
check(invoke($products,'apply_stock',$product,['unit_price'=>'1.00'])===false && $product->get_stock_status()==='instock','A missing stock field is never read as zero stock');
check(invoke($products,'apply_stock',$product,['unit_price'=>'1.00','in_stock'=>false])===true && $product->get_stock_status()==='outofstock','Reported unavailability takes the product out of stock');
check(invoke($products,'apply_stock',$product,['unit_price'=>'1.00','stock'=>25])===true && $product->get_stock_quantity()===25 && $product->get_stock_status()==='instock','A reported quantity is mirrored onto the product');
check($products->selling_price('1.500000')==='1.80','Selling price applies the exchange rate and markup');

// ----------------------------------------------------------- cost guard
reset_http();[$u,$o,$i]=fixture();
$GLOBALS['postmeta'][1][DST2T_Product::META_LAST_COST]='1.000000';
respond(['success'=>true,'price'=>['unit_price'=>'2.00','currency'=>'USD']]);
$ful->process_item(1,1);
check($o->status==='on-hold'&&$repo->rows[$u]['status']==='price_blocked','A cost spike beyond the guard blocks the purchase');
reset_http();[$u,$o,$i]=fixture();
$GLOBALS['postmeta'][1][DST2T_Product::META_LAST_COST]='0.000000';
respond(['success'=>true,'price'=>['unit_price'=>'2.00','currency'=>'USD']]);
$ful->process_item(1,1);
check(count($GLOBALS['calls'])===1&&$repo->rows[$u]['status']==='price_blocked','A zero cached cost fails closed instead of buying at any price');
$GLOBALS['postmeta'][1][DST2T_Product::META_LAST_COST]='1.000000';

// --------------------------------------------------------------- import flow
reset_http();
invoke($admin,'import_item',7,['item_id'=>4242,'name'=>'Shop2TopUp Diamonds 100','description'=>'Delivered by Shop2TopUp','price'=>'1.50'],[],'');
$imported=$GLOBALS['wc_products'][array_key_last($GLOBALS['wc_products'])];
check($imported->get_status()==='draft','An imported product is saved as a draft');
check(strpos($imported->data['name'],'Shop2TopUp')===false && strpos($imported->data['description'],'Shop2TopUp')===false,'Imported copy never names the supplier');
check(strpos($imported->data['sku'],'tu-')===0 && strpos($imported->data['sku'],'4242')===false,'Imported SKU is opaque and never embeds the provider item id');
check($imported->data['sku']===DST2T_Brand::sku_for_item(4242) && DST2T_Brand::sku_for_item(4242)!==DST2T_Brand::sku_for_item(4243),'Opaque SKUs are stable per item and distinct between items');
check($imported->data['regular_price']==='1.80','Import prices with the configured markup');

$live=new WC_Product_Simple(); $live->id=555; $live->data['status']='publish'; $GLOBALS['wc_products'][555]=$live;
$GLOBALS['skus'][DST2T_Brand::sku_for_item(4243)]=555; $GLOBALS['postmeta'][555]=[DST2T_Product::META_ITEM_ID=>4243];
invoke($admin,'import_item',7,['item_id'=>4243,'name'=>'Gems','price'=>'2.00'],[],'');
check($live->get_status()==='draft','Re-importing returns a published product to draft for review');

settings_with(['import_force_draft'=>'no']);
$kept=new WC_Product_Simple(); $kept->id=556; $kept->data['status']='publish'; $GLOBALS['wc_products'][556]=$kept;
$GLOBALS['skus'][DST2T_Brand::sku_for_item(4244)]=556; $GLOBALS['postmeta'][556]=[DST2T_Product::META_ITEM_ID=>4244];
invoke($admin,'import_item',7,['item_id'=>4244,'name'=>'Coins','price'=>'2.00'],[],'');
check($kept->get_status()==='publish','Operators can opt out of forcing re-imports back to draft');
defaults_restore();

$collision=new WC_Product_Simple(); $collision->id=557; $GLOBALS['wc_products'][557]=$collision;
$GLOBALS['skus'][DST2T_Brand::sku_for_item(4245)]=557; $GLOBALS['postmeta'][557]=[DST2T_Product::META_ITEM_ID=>1];
try { invoke($admin,'import_item',7,['item_id'=>4245,'name'=>'X','price'=>'1.00'],[],''); check(false,'SKU collision refuses to overwrite an unrelated product'); }
catch (RuntimeException $e) { check(true,'SKU collision refuses to overwrite an unrelated product'); }

echo "$n tests passed.\n";
