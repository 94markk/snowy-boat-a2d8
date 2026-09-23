<?php
if ( ! defined( 'ABSPATH' ) ) exit;
final class DIP_Delicat_App_Sync {
 public static function init(){
  add_action('dip_login_success',[__CLASS__,'login'],30,2);
  add_action('user_register',[__CLASS__,'registered'],30,1);
  add_action('profile_update',[__CLASS__,'profile'],30,2);
  add_action('password_reset',[__CLASS__,'password_reset'],30,2);
  add_filter('delicat_app_user_allowed',[__CLASS__,'allowed'],20,2);
  add_filter('delicat_app_resolve_external_token',[__CLASS__,'resolve_token'],20,3);
  add_filter('delicat_app_identity_bridge_status',[__CLASS__,'bridge_status'],20,1);
  DIP_Headless_Google::init();
 }
 public static function login($user_id,$profile=[]){ self::emit($user_id,'identity_login',['provider'=>sanitize_key($profile['provider']??'google')]); }
 public static function registered($user_id){ self::emit($user_id,'identity_registered'); }
 public static function profile($user_id,$old){ self::emit($user_id,'profile_updated'); }
 public static function password_reset($user,$new_pass){ if($user instanceof WP_User) self::emit($user->ID,'security_changed'); }
 public static function resolve_token($user_id,$request,$legacy_token=''){
  if((int)$user_id>0 || !class_exists('DIP_Mobile_API')) return (int)$user_id;
  // Requests coming from Delicat App API must prove both the Bearer token and
  // the device/installation that received it. This keeps the cross-plugin
  // bridge synchronized without widening the token into a generic WP session.
  if($request instanceof WP_REST_Request){
   $session=DIP_Mobile_API::current_session_for_request($request,true);
   return $session?(int)$session->user_id:0;
  }
  // Unbound token resolution is intentionally unavailable by default.
  return 0;
 }
 public static function bridge_status($status){
  $settings=class_exists('DIP_Plugin')
   ? wp_parse_args((array)get_option(DIP_Plugin::OPTION,[]),DIP_Plugin::defaults())
   : [];
  $google=($settings['enabled']??'no')==='yes'
   && ($settings['mobile_api_enabled']??'no')==='yes'
   && !empty($settings['client_id'])
   && (!empty($settings['android_client_id'])||!empty($settings['ios_client_id']));
  return [
   'available'=>true,
   'version'=>defined('DIP_VERSION')?DIP_VERSION:'',
   'mobile_tokens'=>class_exists('DIP_Mobile_API'),
   'pairing'=>class_exists('DIP_App_Sync_V2'),
   'header'=>'X-Delicat-Identity-Token',
   'bearer'=>true,
   'device_binding'=>true,
   'google'=>$google,
   'headless_google'=>class_exists('Delicat_App_Auth'),
  ];
 }
 public static function allowed($allowed,$user_id){
  if(!$allowed) return false;
  $user_id=absint($user_id);
  $status=get_user_meta($user_id,'_dip_approval_status',true);
  if(in_array($status,['blocked','rejected','disabled'],true)) return false;
  if(class_exists('DIP_Account_Sync')){
   if(DIP_Account_Sync::privileged_mobile_blocked($user_id)) return false;
   if(is_wp_error(DIP_Account_Sync::login_guard($user_id))) return false;
  }
  return true;
 }
 private static function emit($user_id,$type,$extra=[]){
  clean_user_cache((int)$user_id);
  do_action('delicat_app_identity_changed',(int)$user_id,$type,$extra);
  do_action('delicat_app_content_changed',$type,array_merge(['user_id'=>(int)$user_id,'source'=>'delicat-identity-pro'],$extra));
 }
}
