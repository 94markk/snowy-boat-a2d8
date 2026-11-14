<?php
// Exercise the real API client without network access or account credentials.
define('ABSPATH',__DIR__);define('DFR_VERSION','4.22.1');
class WP_Error { public $code; function __construct($c,$m='',$d=null){$this->code=$c;} }
function is_wp_error($r){return $r instanceof WP_Error;}
function __($s,$d=''){return $s;}
function sanitize_text_field($s){return trim(strip_tags($s));}
function sanitize_key($s){return strtolower($s);}
function home_url($s){return 'https://example.test/';}
function wp_json_encode($s,$flags=0){return json_encode($s,$flags);}
function wp_safe_remote_request($url,$args){$GLOBALS['args']=$args;return ['body'=>json_encode($GLOBALS['answer'])];}
function wp_remote_retrieve_response_code($r){return 200;}
function wp_remote_retrieve_body($r){return $r['body'];}
require dirname(__DIR__).'/includes/class-dfr-api.php';
function check($ok,$msg){if(!$ok)throw new Exception($msg);echo 'PASS '.$msg."\n";}
$api=new DFR_API('test');$name='꧁☯ⓐⓒⓗⓘⓛⓛⓔ☯꧂';
$GLOBALS['answer']=['ok'=>true,'valid'=>true,'category_id'=>'free_fire','player_id'=>'8593173765','player_name'=>$name];
$result=$api->validate_topup_id('free_fire',['uid'=>'8593173765']);
check(!is_wp_error($result)&&$result['player_name']===$name,'Large UID and decorated nickname preserved');
check(strpos($GLOBALS['args']['headers']['Cache-Control'],'no-store')!==false,'Lookup cache prevention header');
foreach ([['player_id'=>'8593173766'],['fields'=>['uid'=>'8593173766']],['player'=>['player_id'=>'8593173766']],['player_id'=>[]],['category_id'=>'pubg'],['player_name'=>['Wrong']]] as $override){
 $GLOBALS['answer']=array_merge(['ok'=>true,'valid'=>true,'category_id'=>'free_fire','player_name'=>$name],$override);
 check(is_wp_error($api->validate_topup_id('free_fire',['uid'=>'8593173765'])),'Inconsistent account response rejected');
}
$GLOBALS['answer']=['ok'=>true,'valid'=>true,'player_name'=>$name];
check(!is_wp_error($api->validate_topup_id('free_fire',['uid'=>'8593173765'])),'Documented response without an echoed UID remains compatible');
$GLOBALS['answer']=['ok'=>true,'valid'=>false];
check($api->validate_topup_id('free_fire',['uid'=>'8593173765'])['valid']===false,'Account rejection stays a rejection');
