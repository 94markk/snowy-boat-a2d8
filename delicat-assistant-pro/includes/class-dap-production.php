<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Production hardening: session controls, audit trail, diagnostics and release safety. */
class DSA2_Production {
    const NS = 'delicat-assistant/v2';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 40 );
        add_action( 'admin_post_dap_clear_audit_log', array( __CLASS__, 'clear_audit_log' ) );
        add_action( 'admin_post_dap_save_production_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'dsa2_mobile_customer_message', function($conversation_id,$message_id){ self::audit('customer_message', array('conversation_id'=>(int)$conversation_id,'message_id'=>(int)$message_id)); }, 10, 2 );
        add_action( 'dsa2_mobile_ticket_created', function($ticket_id,$conversation_id,$subject,$priority){ self::audit('ticket_created', array('ticket_id'=>(int)$ticket_id,'conversation_id'=>(int)$conversation_id,'priority'=>sanitize_key($priority))); }, 10, 4 );
        add_action( 'dsa2_mobile_ticket_rated', function($ticket_id,$rating,$comment,$conversation_id){ self::audit('ticket_rated', array('ticket_id'=>(int)$ticket_id,'conversation_id'=>(int)$conversation_id,'rating'=>(int)$rating)); }, 10, 4 );
    }

    public static function routes() {
        if ( ! class_exists( 'DSA2_Mobile_API', false ) || ! DSA2_Mobile_API::customer_api_enabled() ) return; // 6.6.1
        register_rest_route(self::NS, '/sessions', array('methods'=>'GET','callback'=>array(__CLASS__,'sessions'),'permission_callback'=>array('DSA2_Mobile_API','allow')));
        register_rest_route(self::NS, '/sessions/revoke', array('methods'=>'POST','callback'=>array(__CLASS__,'revoke_session'),'permission_callback'=>array('DSA2_Mobile_API','allow')));
        register_rest_route(self::NS, '/account/unlink', array('methods'=>'POST','callback'=>array(__CLASS__,'unlink_account'),'permission_callback'=>array('DSA2_Mobile_API','allow')));
        register_rest_route(self::NS, '/diagnostics', array('methods'=>'GET','callback'=>array(__CLASS__,'diagnostics'),'permission_callback'=>array('DSA2_Mobile_API','allow')));
    }

    private static function session($request){ return $request->get_param('_mobile_session'); }

    public static function sessions($request) {
        $s=self::session($request); global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT installation_id,language,expires_at,created_at FROM {$wpdb->prefix}dap_mobile_sessions WHERE conversation_id=%d ORDER BY created_at DESC",(int)$s->conversation_id));
        $devices=$wpdb->get_results($wpdb->prepare("SELECT platform,updated_at FROM {$wpdb->prefix}dap_mobile_devices WHERE conversation_id=%d ORDER BY updated_at DESC",(int)$s->conversation_id));
        $out=array();
        foreach((array)$rows as $row){$out[]=array('installation_id'=>(string)$row->installation_id,'language'=>(string)$row->language,'expires_at'=>(string)$row->expires_at,'created_at'=>(string)$row->created_at,'current'=>hash_equals((string)$s->installation_id,(string)$row->installation_id));}
        return rest_ensure_response(array('sessions'=>$out,'devices'=>array_map(function($d){return array('platform'=>(string)$d->platform,'updated_at'=>(string)$d->updated_at);},(array)$devices)));
    }

    public static function revoke_session($request) {
        $s=self::session($request); $installation=sanitize_text_field((string)$request->get_param('installation_id')); global $wpdb;
        if(!$installation) return new WP_Error('bad_installation','Installation is required',array('status'=>400));
        $target=$wpdb->get_row($wpdb->prepare("SELECT installation_id,conversation_id FROM {$wpdb->prefix}dap_mobile_sessions WHERE installation_id=%s",$installation));
        if(!$target || (int)$target->conversation_id !== (int)$s->conversation_id) return new WP_Error('not_found','Session not found',array('status'=>404));
        $wpdb->delete($wpdb->prefix.'dap_mobile_sessions',array('installation_id'=>$installation),array('%s'));
        $wpdb->delete($wpdb->prefix.'dap_mobile_devices',array('installation_id'=>$installation),array('%s'));
        delete_option('dap_mobile_prefs_'.$installation);
        self::audit('session_revoked',array('conversation_id'=>(int)$s->conversation_id,'installation_id'=>substr($installation,0,12).'…'));
        return rest_ensure_response(array('success'=>true,'current'=>hash_equals((string)$s->installation_id,$installation)));
    }

    public static function unlink_account($request) {
        $s=self::session($request);
        // Clearing user_id alone left the device sitting on the account's whole thread —
        // order history, agent replies and all — which on a shared phone handed the next
        // person the previous customer's conversation.
        $new_id=DSA2_Data::unlink_conversation((int)$s->conversation_id,(string)$s->installation_id,(string)$s->language);
        if(!$new_id) return new WP_Error('unlink_failed','Could not create a private signed-out session',array('status'=>500));
        self::audit('account_unlinked',array('conversation_id'=>(int)$s->conversation_id));
        return rest_ensure_response(array('success'=>true,'conversation_id'=>$new_id));
    }

    public static function diagnostics($request) {
        $s=self::session($request); global $wpdb; $settings=get_option('dap_settings',array());
        $push_mode=!empty($settings['firebase_v1_webhook_url'])?'webhook':'not_configured';
        $pending=(array)get_option('dap_push_retry_queue',array());
        return rest_ensure_response(array(
            'plugin_version'=>DSA2_VERSION,
            'api_version'=>'2.2',
            'conversation_id'=>(int)$s->conversation_id,
            'session_expires_at'=>(string)$s->expires_at,
            'push_mode'=>$push_mode,
            'push_queue'=>count($pending),
            'woocommerce'=>defined('WC_VERSION')?WC_VERSION:null,
            'wallet'=>function_exists('woo_wallet'),
            'server_time'=>gmdate('c'),
            'https'=>is_ssl(),
            'database_ok'=>(bool)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.'dap_mobile_sessions'))
        ));
    }

    public static function audit($event,$context=array()) {
        $logs=(array)get_option('dap_audit_log',array());
        $logs[]=array('time'=>gmdate('c'),'event'=>sanitize_key($event),'user_id'=>get_current_user_id(),'ip'=>self::masked_ip(),'context'=>self::sanitize_context($context));
        update_option('dap_audit_log',array_slice($logs,-1000),false);
    }

    private static function sanitize_context($context){$safe=array();foreach((array)$context as $k=>$v){$key=sanitize_key($k);$safe[$key]=is_scalar($v)?sanitize_text_field((string)$v):'';}return $safe;}
    private static function masked_ip(){ $ip=sanitize_text_field($_SERVER['REMOTE_ADDR']??''); if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){ $p=explode('.',$ip);$p[3]='0';return implode('.',$p).'/24'; } if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)){ return substr($ip,0,19).'::/64'; } return ''; }

    public static function admin_menu(){
        add_submenu_page('delicat-assistant','Production & Security','Production & sécurité','manage_options','dap-production',array(__CLASS__,'page'));
    }

    public static function page(){
        if(!current_user_can('manage_options'))wp_die('Forbidden'); $settings=get_option('dap_settings',array()); $logs=array_reverse((array)get_option('dap_audit_log',array())); $queue=(array)get_option('dap_push_retry_queue',array());
        echo '<div class="wrap"><h1>Delicat Assistance — Production & Security</h1>';
        echo '<div class="notice notice-info"><p>Firebase credentials must remain server-side. Use a protected HTTPS webhook or Cloud Function; never place a service-account key in the Android app.</p></div>';
        echo '<h2>Push delivery</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dap_save_production_settings">';wp_nonce_field('dap_save_production_settings');
        echo '<table class="form-table"><tr><th><label for="firebase_v1_webhook_url">Firebase HTTP v1 webhook</label></th><td><input class="regular-text" type="url" id="firebase_v1_webhook_url" name="firebase_v1_webhook_url" value="'.esc_attr($settings['firebase_v1_webhook_url']??'').'" placeholder="https://..."></td></tr><tr><th><label for="firebase_v1_webhook_secret">Webhook secret</label></th><td><input class="regular-text" type="password" id="firebase_v1_webhook_secret" name="firebase_v1_webhook_secret" value="" autocomplete="new-password" placeholder="'.(!empty($settings['firebase_v1_webhook_secret'])?'Configured — leave blank to keep':'Enter a secret').'"><label style="display:block;margin-top:7px"><input type="checkbox" name="clear_firebase_secret" value="1"> Clear the stored secret</label></td></tr></table>';submit_button('Save production settings');echo '</form>';
        echo '<p><strong>Pending push retries:</strong> '.count($queue).'</p><h2>Audit log</h2><table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Event</th><th>User</th><th>Network</th><th>Context</th></tr></thead><tbody>';
        foreach(array_slice($logs,0,200) as $row){echo '<tr><td>'.esc_html($row['time']??'').'</td><td><code>'.esc_html($row['event']??'').'</code></td><td>'.(int)($row['user_id']??0).'</td><td>'.esc_html($row['ip']??'').'</td><td><code>'.esc_html(wp_json_encode($row['context']??array())).'</code></td></tr>';}
        if(!$logs)echo '<tr><td colspan="5">No audit events yet.</td></tr>';echo '</tbody></table><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:12px"><input type="hidden" name="action" value="dap_clear_audit_log">';wp_nonce_field('dap_clear_audit_log');submit_button('Clear audit log','secondary','submit',false);echo '</form></div>';
    }

    public static function save_settings(){if(!current_user_can('manage_options'))wp_die('Forbidden');check_admin_referer('dap_save_production_settings');$s=get_option('dap_settings',array());$s['firebase_v1_webhook_url']=esc_url_raw(wp_unslash($_POST['firebase_v1_webhook_url']??''));$secret=sanitize_text_field(wp_unslash($_POST['firebase_v1_webhook_secret']??''));if(!empty($_POST['clear_firebase_secret']))$s['firebase_v1_webhook_secret']='';elseif(''!==$secret)$s['firebase_v1_webhook_secret']=$secret;update_option('dap_settings',$s);self::audit('production_settings_updated');wp_safe_redirect(admin_url('admin.php?page=dap-production&updated=1'));exit;}
    public static function clear_audit_log(){if(!current_user_can('manage_options'))wp_die('Forbidden');check_admin_referer('dap_clear_audit_log');delete_option('dap_audit_log');wp_safe_redirect(admin_url('admin.php?page=dap-production'));exit;}
}
