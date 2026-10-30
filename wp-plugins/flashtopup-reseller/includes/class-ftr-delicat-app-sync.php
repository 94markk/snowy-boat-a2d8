<?php
if ( ! defined( 'ABSPATH' ) ) exit;
final class FTR_Delicat_App_Sync {
 public static function init(){
  add_filter('delicat_app_product_fields',[__CLASS__,'product_fields'],30,2);
  add_filter('delicat_app_product_needs_player_id',[__CLASS__,'needs_player_id'],20,2);
  add_filter('delicat_app_order_timeline',[__CLASS__,'timeline'],20,2);
  add_action('woocommerce_order_status_changed',[__CLASS__,'order_changed'],30,4);
  add_action('ftr_reconcile_order',[__CLASS__,'reconciled'],30,1);
  add_action('ftr_provider_item_status_updated',[__CLASS__,'provider_updated'],40,3);
 }
 private static function raw_fields($product_id){
  $raw=get_post_meta($product_id,'_ftr_fields',true);
  if(is_string($raw)) $raw=json_decode($raw,true);
  return is_array($raw)?$raw:[];
 }
 public static function needs_player_id($required,$product_id){
  if($required) return true;
  foreach(self::raw_fields($product_id) as $f){ $n=sanitize_key($f['name']??''); if(in_array($n,['user_id','player_id','userid','playerid'],true)) return true; }
  return false;
 }
 public static function product_fields($fields,$product_id){
  $existing=wp_list_pluck((array)$fields,'key');
  foreach(self::raw_fields($product_id) as $f){
   $name=sanitize_key($f['name']??''); if(!$name||in_array($name,$existing,true)) continue;
   $fields[]=['key'=>$name,'source'=>'flashtopup','type'=>'text','label'=>sanitize_text_field($f['label']??$name),'required'=>!empty($f['required']),'placeholder'=>sanitize_text_field($f['placeholder']??''),'help'=>'','validation'=>'text'];
  }
  return array_values($fields);
 }
 public static function timeline($steps,$order){
  $has=false;$complete=true;
  foreach($order->get_items() as $item_id=>$item){
   $variation_id=method_exists($item,'get_variation_id')?(int)$item->get_variation_id():0;
   $product_id=(int)$item->get_product_id();
   if(!get_post_meta($variation_id?:$product_id,'_ftr_service_code',true) && !get_post_meta($product_id,'_ftr_service_code',true)) continue;
   $has=true; $status=(string)wc_get_order_item_meta($item_id,'_ftr_status',true);
   if(!in_array(strtolower($status),['completed','success','successful','delivered','validated','approved','confirmed'],true)) $complete=false;
  }
  if($has) $steps[]=['key'=>'provider_delivery','title'=>'Livraison FlashTopup','done'=>$complete];
  return $steps;
 }
 public static function order_changed($order_id,$from,$to,$order){ self::emit($order_id,'order_status',['from'=>$from,'to'=>$to]); }
 public static function reconciled($order_id){ self::emit($order_id,'provider_reconciled'); }
 public static function provider_updated($order_id,$item_id,$status){ self::emit($order_id,'provider_status',['item_id'=>(int)$item_id,'provider_status'=>sanitize_key((string)$status)]); }
 private static function emit($order_id,$type,$extra=[]){
  $order=wc_get_order($order_id); if(!$order) return;
  do_action('delicat_app_order_changed',(int)$order_id,$type,$extra);
  do_action('delicat_app_content_changed','order_updated',array_merge(['order_id'=>(int)$order_id,'user_id'=>(int)$order->get_user_id(),'source'=>'flashtopup-reseller'],$extra));
  if($order->get_user_id()) do_action('delicat_app_push',(int)$order->get_user_id(),'Mise à jour de commande','Votre commande #'.$order->get_order_number().' a été mise à jour.',['type'=>'order','order_id'=>(int)$order_id]);
 }
}
