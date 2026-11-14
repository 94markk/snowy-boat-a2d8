<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Offline regression harness: no network, purchases, or WordPress installation.
define('ABSPATH', __DIR__); define('MINUTE_IN_SECONDS',60); define('HOUR_IN_SECONDS',3600); define('DAY_IN_SECONDS',86400); define('WEEK_IN_SECONDS',604800);
class WP_Error { public $code,$data; function __construct($code,$message='',$data=[]) {$this->code=$code;$this->data=$data;} function get_error_code(){return $this->code;} function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$domain=''){return $v;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',$v));}
function sanitize_text_field($v){return (string)$v;}
function absint($v){return abs((int)$v);}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;}
function delete_option($k){unset($GLOBALS['options'][$k]);}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['transients'][$k]=$v;$GLOBALS['ttls'][$k]=$ttl;}
function delete_transient($k){unset($GLOBALS['transients'][$k]);}
function get_posts($args){
 if(!empty($args['meta_query'])){ // 4.20.0 selects a group by service + supplier category.
  $want=[];foreach($args['meta_query'] as $clause){if(is_array($clause)&&isset($clause['key']))$want[$clause['key']]=$clause['value'];}
  $out=[];foreach($GLOBALS['meta'] as $id=>$m){if(!isset($GLOBALS['products'][$id]))continue;$ok=true;foreach($want as $k=>$v){if(($m[$k]??null)!==$v){$ok=false;break;}}if($ok)$out[]=(int)$id;}
  sort($out);return array_slice($out,0,$args['posts_per_page']??500);
 }
 return array_slice(array_keys($GLOBALS['products']),$args['offset']??0,$args['posts_per_page']);}
function get_post_type($id){return 'product';}
function add_filter($hook,$cb,$priority=10,$accepted=1){return true;}
function remove_filter($hook,$cb,$priority=10){return true;}
function wp_parse_args($args,$defaults=[]){return array_merge($defaults,is_array($args)?$args:[]);}
function wp_strip_all_tags($v){return strip_tags((string)$v);}
function _n($single,$plural,$n,$domain=''){return $n>1?$plural:$single;}
function _x($v,$ctx='',$domain=''){return $v;}
function esc_html($v){return (string)$v;}
function wp_json_encode($v){return json_encode($v);}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$v){$GLOBALS['meta'][$id][$key]=$v;}
function delete_post_meta($id,$key){unset($GLOBALS['meta'][$id][$key]);}
function current_time($type,$gmt=false){return gmdate('Y-m-d H:i:s');}
function wc_format_decimal($v,$d=4){return number_format((float)$v,$d,'.','');}
function wc_get_product($id){return $GLOBALS['products'][$id]??false;}
function wp_next_scheduled($hook){return $GLOBALS['scheduled'][$hook]??false;}
function wp_schedule_single_event($time,$hook,$args=[]){$GLOBALS['scheduled'][$hook]=$time;return true;}
function do_action(...$args){}
function apply_filters($hook,$value,...$args){return $value;}
class WooCommerce {}
class FakeProduct {
 public $id=0,$manage=false,$qty=null,$stock='instock',$backorders='no',$saves=0,$price='250',$name='Produit',$changes=[];
 function get_id(){return $this->id;}
 private function set($prop,$v){if($this->$prop!==$v){$this->$prop=$v;$this->changes[$prop]=$v;}}
 function set_manage_stock($v){$this->set('manage',(bool)$v);} function get_manage_stock(){return $this->manage;}
 function set_stock_quantity($v){$this->set('qty',$v);} function get_stock_quantity(){return $this->qty;}
 function set_stock_status($v){$this->set('stock',$v);} function get_stock_status(){return $this->stock;}
 function set_backorders($v){$this->set('backorders',$v);} function get_parent_id(){return 0;}
 function set_regular_price($v){$this->set('price',(string)$v);} function set_price($v){$this->set('price',(string)$v);}
 function get_price($context=''){return (float)$this->price;} function get_regular_price($context=''){return $this->price;}
 function get_name(){return $this->name;}
 function get_changes(){return $this->changes;}
 function save(){$this->saves++;$this->changes=[];return $this->id;}
}
class FakeDB {
 public $postmeta='wp_postmeta',$posts='wp_posts';
 function prepare($q,...$v){return $q;} function get_var($q){return 1;}
 /** DISTINCT (_dfr_service_type, _dfr_category_id) over the imported products. */
 function get_results($q){$seen=[];foreach($GLOBALS['meta'] as $id=>$m){if(!isset($GLOBALS['products'][$id])||($m['_dfr_imported']??'')!=='1')continue;$k=($m['_dfr_service_type']??'').'|'.($m['_dfr_category_id']??'');if(isset($seen[$k]))continue;$seen[$k]=(object)['service'=>$m['_dfr_service_type']??'','category_id'=>$m['_dfr_category_id']??''];}return array_values($seen);}}
$wpdb=new FakeDB;
class FakeAPI {
 public $response,$balance=['balance'=>'0.0000','currency'=>'USD'],$calls=0;
 function is_configured(){return true;} function balance($timeout=20){$this->calls++;return $this->balance;}
 function topup_offers($category,$timeout=20){return $this->response??['offers'=>[['offer_id'=>'one','name'=>'Test','price_usd'=>'1.00']]];}
 function giftcard_cards($category,$timeout=20){return $this->response;} function gamekey_keys($category,$timeout=20){return $this->response;}
}
class DFR_Plugin {
 public $api; static $instance;
 static function instance(){if(!self::$instance){self::$instance=new self;self::$instance->api=new FakeAPI;}return self::$instance;}
 function api(){return $this->api;}
 function get_settings(){return ['catalog_auto_sync'=>'1','catalog_disable_missing'=>'1','catalog_pricing_mode'=>'manual','catalog_cache_ttl'=>30];}
 function log(...$args){}
}
require dirname(__DIR__).'/includes/class-dfr-catalog.php';
$catalog=DFR_Catalog::instance(); $api=DFR_Plugin::instance()->api();
function call_private($name,...$args){global $catalog;$r=new ReflectionMethod($catalog,$name);$r->setAccessible(true);return $r->invokeArgs($catalog,$args);}
$count=0;
function check($value,$name){global $count;if(!$value){throw new Exception('FAIL: '.$name);} $count++;}
foreach(['topup','giftcard','gamekey'] as $service){
 $p=new FakeProduct;$p->id=999;$GLOBALS['meta'][999]=['_dfr_imported'=>'1','_dfr_service_type'=>$service,'_dfr_category_id'=>'cat','_dfr_offer_id'=>'one'];
 call_private('apply_supplier_availability',$p,['stock'=>null],$service);
 check(!$p->manage&&$p->stock==='instock',$service.' unspecified stock available');
 call_private('apply_supplier_availability',$p,['stock'=>0],$service);
 check($p->manage&&$p->qty===0&&$p->stock==='outofstock',$service.' explicit zero unavailable');
 call_private('apply_supplier_availability',$p,['stock'=>5,'available'=>false],$service);
 check($p->qty===0&&$p->stock==='outofstock',$service.' availability overrides count');
 call_private('apply_supplier_availability',$p,['stock'=>null],$service);
 check($p->stock==='outofstock',$service.' unknown availability preserves state');
 call_private('apply_supplier_availability',$p,['stock'=>null,'available'=>true],$service);
 check(!$p->manage&&$p->qty===null&&$p->stock==='instock',$service.' explicit availability recovery');
 call_private('apply_supplier_availability',$p,['stock'=>5],$service);
 check($p->qty===5&&$p->stock==='instock',$service.' restock recovery');
}
check(call_private('offer_availability',['available'=>'unknown'])===null,'unknown availability preserved');
check(call_private('offer_availability',['available'=>'false'])===false,'string false parsed');
check(call_private('offer_availability',['available'=>true],['enabled'=>false])===false,'category disabled precedence');
$products=[];$meta=[];
$api->response=['ok'=>true];check(is_wp_error(call_private('get_offers','topup','cat',true)),'missing offer list rejected');
$api->response=['offers'=>[null]];check(is_wp_error(call_private('get_offers','topup','cat',true)),'malformed row rejected');
$api->response=['offers'=>[['name'=>'no id']]];check(is_wp_error(call_private('get_offers','topup','cat',true)),'invalid ID rejected');
$api->response=['offers'=>[['offer_id'=>'one','stock'=>-1]]];$v=call_private('get_offers','topup','cat',true);check($v['items'][0]['stock']===null,'negative sentinel not zero');
$api->response=['offers'=>[],'meta'=>['has_more'=>true]];$v=call_private('get_offers','topup','cat',true);check(!$v['complete'],'partial response not authoritative absence');
function seed($n){$GLOBALS['products']=[];$GLOBALS['meta']=[];$GLOBALS['options']=[];$GLOBALS['transients']=[];for($i=1;$i<=$n;$i++){$GLOBALS['products'][$i]=new FakeProduct;$GLOBALS['products'][$i]->id=$i;$GLOBALS['meta'][$i]=['_dfr_imported'=>'1','_dfr_service_type'=>'topup','_dfr_category_id'=>'cat'.intdiv($i-1,2),'_dfr_offer_id'=>'one'];}}
seed(625);$api->response=null;
// 4.20.0 sweeps by supplier-category group instead of by page cursor, and only saves a
// product whose properties changed, so a visit is proven by its « supplier last seen » stamp.
for($i=0;$i<400;$i++){$r=call_private('sync_imported_products',true);if(($r['remaining']??0)===0)break;}
check(count(array_filter($meta,fn($m)=>!empty($m['_dfr_supplier_last_seen'])))===625,'all 625 imports synchronized across pages and category slices');
$health=get_option('dfr_catalog_sync_health_v1',[]);
check(($health['remaining']??1)===0&&!empty($health['cycle_finished']),'cursor resets after complete sweep');
check(count(array_filter($products,fn($p)=>$p->price==='250'))===625,'manual prices preserved');
// Each scenario below asserts what ONE background sweep does to the product, so the cycle
// bookkeeping is cleared first: 4.20.0 visits a group once per cycle, and this harness runs
// every scenario inside the same second.
function sweep(){unset($GLOBALS['options']['dfr_catalog_sync_groups_v1'],$GLOBALS['options']['dfr_catalog_sync_health_v1']);return call_private('sync_imported_products',true);}
seed(1);$api->response=['offers'=>[],'meta'=>['has_more'=>true]];sweep();check($products[1]->stock==='instock','partial catalog preserves existing stock');
$api->response=['offers'=>[]];sweep();check($products[1]->stock==='outofstock','complete missing offer disabled');
$api->response=null;sweep();check($products[1]->stock==='outofstock','unknown availability does not falsely restock');
$api->response=['offers'=>[['offer_id'=>'one','available'=>true]]];sweep();check($products[1]->stock==='instock','explicit supplier availability restocks');
$api->response=new WP_Error('transport');sweep();check($products[1]->stock==='instock','API outage preserves stock');
$api->response=new WP_Error('dfr_rate_limited','',['retry_after'=>120]);sweep();check(($ttls['dfr_catalog_backoff']??0)>=120,'catalog respects retry-after');
check(is_wp_error(call_private('sync_imported_products',false)),'manual sync respects backoff');
$transients=[];$api->calls=0;
$v=$catalog->refresh_balance();check($v['balance']==='0.0000','zero balance displayed');
$catalog->refresh_balance();check($api->calls===1,'balance cache shared across refreshes');
$transients['dfr_live_balance']['checked_at']=time()-20;$api->balance=new WP_Error('dfr_rate_limited','',['retry_after'=>90]);
check(is_wp_error($catalog->refresh_balance()),'balance error not new balance');check($transients['dfr_live_balance']['balance']==='0.0000','last known balance retained');check($ttls['dfr_balance_backoff']>=90,'balance retry-after honored');
// Execute the real webhook worker method with controlled external dependencies.
$source=file_get_contents(dirname(__DIR__).'/includes/class-dfr-plugin.php');
$start=strpos($source,'    public function process_verified_webhook(');$end=strpos($source,'    private function apply_remote_payload(', $start);
$method=substr($source,$start,$end-$start);
$stubs=<<<'CODE'
class WebhookWorkerFixture {
 public $retries=[], $canonical='processing';
 function normalize_supplier_status($s){return $s;}
 function read_canonical_supplier_order($remote,$timeout){return ['order'=>['status'=>$this->canonical]];}
 function get_remote_map($remote){return false;}
 function schedule_verified_webhook_retry(...$a){$this->retries[]=$a;return true;}
CODE;
eval($stubs.$method.'}');
$w=new WebhookWorkerFixture;$w->process_verified_webhook('ord-123','completed','test-event',1,false);
check(count($w->retries)===1&&$w->retries[0][1]==='completed','terminal hint survives canonical processing race');
$w=new WebhookWorkerFixture;$w->process_verified_webhook('ord-123','completed','test-event',0,true);check(!$w->retries,'fast worker leaves durable scheduling to handler');
$w=new WebhookWorkerFixture;$w->canonical='completed';$w->process_verified_webhook('ord-123','completed','test-event',1,false);check(count($w->retries)===1,'early callback retries until local order mapping exists');
echo "PASS: $count offline regression checks\n";
