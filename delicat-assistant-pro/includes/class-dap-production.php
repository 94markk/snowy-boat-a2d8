<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Production hardening: audit trail and push relay settings.
 * 7.1.1 (AST-06): the old customer API's session, unlink and diagnostics routes are removed.
 */
class DSA2_Production {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 40 );
        add_action( 'admin_post_dap_clear_audit_log', array( __CLASS__, 'clear_audit_log' ) );
        add_action( 'admin_post_dap_save_production_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'dsa2_mobile_customer_message', function($conversation_id,$message_id){ self::audit('customer_message', array('conversation_id'=>(int)$conversation_id,'message_id'=>(int)$message_id)); }, 10, 2 );
        add_action( 'dsa2_mobile_ticket_created', function($ticket_id,$conversation_id,$subject,$priority){ self::audit('ticket_created', array('ticket_id'=>(int)$ticket_id,'conversation_id'=>(int)$conversation_id,'priority'=>sanitize_key($priority))); }, 10, 4 );
        add_action( 'dsa2_mobile_ticket_rated', function($ticket_id,$rating,$comment,$conversation_id){ self::audit('ticket_rated', array('ticket_id'=>(int)$ticket_id,'conversation_id'=>(int)$conversation_id,'rating'=>(int)$rating)); }, 10, 4 );
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
