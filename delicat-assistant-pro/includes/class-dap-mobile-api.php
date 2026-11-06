<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * What remains of the old « Delicat Assistance » customer API.
 *
 * 7.1.1 (AST-06): the customer REST API (delicat-assistant/v1 and v2: app token bootstrap,
 * messages, attachments, tickets, account linking by e-mail code, orders, wallet, sessions)
 * is removed. It was off by default since 6.6.1 and no route of it was registered while off;
 * the Délicat Store app chats through /delicat-app/v1/chat. Those addresses keep answering
 * WordPress's usual 404 JSON. Kept here, because other parts use them: the ticket post type
 * (inbox Tickets page, staff app), the message format of the staff app, and the conversation
 * key of an app installation (DSA2_Data::unlink_conversation()).
 */
class DSA2_Mobile_API {
    const NS = 'delicat-assistant/v2';
    const LEGACY_NS = 'delicat-assistant/v1';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_ticket_type' ) );
    }

    /** 7.1.1: the customer API no longer exists; kept for callers that still ask. */
    public static function customer_api_enabled() {
        return false;
    }

    public static function installation_key( $installation_id ) {
        return 'app_' . substr( hash_hmac( 'sha256', (string) $installation_id, wp_salt( 'nonce' ) ), 0, 48 );
    }

    public static function register_ticket_type() {
        $cap = DSA2_Install::CAP_SUPPORT;
        register_post_type('dap_ticket', array(
            'labels'=>array('name'=>'Tickets Delicat','singular_name'=>'Ticket Delicat'),
            'public'=>false,'show_ui'=>true,'show_in_menu'=>false,'supports'=>array('title','editor'),
            'map_meta_cap'=>false,'menu_icon'=>'dashicons-sos',
            'capabilities'=>array(
                'edit_post'=>$cap,'read_post'=>$cap,'delete_post'=>$cap,'edit_posts'=>$cap,
                'edit_others_posts'=>$cap,'publish_posts'=>$cap,'read_private_posts'=>$cap,
                'delete_posts'=>$cap,'delete_private_posts'=>$cap,'delete_published_posts'=>$cap,
                'delete_others_posts'=>$cap,'edit_private_posts'=>$cap,'edit_published_posts'=>$cap,
                'create_posts'=>$cap,
            ),
        ));
    }

    // add_message() stores wp_kses_post(), so "l'agent" comes back as "l&#039;agent". The web
    // widget decodes before rendering; the app did not, and showed the raw entities.
    public static function format( $m ) {
        $meta=$m->meta?json_decode($m->meta,true):null;
        if(!is_array($meta))$meta=null;
        if($meta&&isset($m->conversation_id))$meta=DSA2_Data::public_meta($meta,(int)$m->conversation_id); // 7.1.1: private images, signed address
        $sender_id=isset($m->sender_id)?(int)$m->sender_id:0;
        static $agents=array();
        if($sender_id&&!array_key_exists($sender_id,$agents))$agents[$sender_id]=get_userdata($sender_id);
        $agent=$sender_id?($agents[$sender_id]??false):false;
        $channel=isset($m->channel)&&in_array($m->channel,array('bot','agent','system'),true)?$m->channel:((string)$m->sender==='agent'?'agent':'bot');
        return array('id'=>(int)$m->id,'sender'=>(string)$m->sender,'sender_id'=>$sender_id,'sender_name'=>$agent?sanitize_text_field($agent->display_name):'','channel'=>$channel,'body'=>html_entity_decode(wp_strip_all_tags((string)$m->body),ENT_QUOTES,'UTF-8'),'type'=>(string)$m->message_type,'meta'=>$meta,'created_at'=>(string)$m->created_at,'delivery_status'=>'sent');
    }
}
