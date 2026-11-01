<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Secure native Android bridge and Firebase notification integration. */
class DSA2_Mobile_API {
    const NS = 'delicat-assistant/v2';
    const LEGACY_NS = 'delicat-assistant/v1';

    /** Conversation whose assistant replies are already travelling in the HTTP response. */
    private static $suppress_push_for_conversation = 0;

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'dsa2_message_created', array( __CLASS__, 'message_created' ), 10, 6 );
        add_action( 'dsa2_mobile_push', array( __CLASS__, 'push_to_conversation' ), 10, 4 );
        add_action( 'init', array( __CLASS__, 'register_ticket_type' ) );
    }

    /**
     * 6.6.1 (ported from 6.2.2): the legacy "Delicat Assistance" customer API (v1, v2, and
     * the Enterprise routes that use the same app token) is a setting, off by default. The
     * Délicat Store app never used it: it chats through /delicat-app/v1/chat. It exposed
     * account linking by e-mail code, with orders and wallet history behind it. The admin
     * API (delicat-assistant-admin/v1) is not affected.
     */
    public static function customer_api_enabled() {
        $settings = get_option( 'dap_settings', array() );
        if ( is_array( $settings ) && array_key_exists( 'customer_api_enabled', $settings ) ) {
            return ! empty( $settings['customer_api_enabled'] );
        }
        return class_exists( 'DSA2_Install', false ) && method_exists( 'DSA2_Install', 'migrate_customer_api_setting' )
            && DSA2_Install::migrate_customer_api_setting();
    }

    public static function routes() {
        if ( ! self::customer_api_enabled() ) return;
        foreach ( array( self::NS, self::LEGACY_NS ) as $ns ) {
            register_rest_route( $ns, '/config', array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'config'), 'permission_callback'=>'__return_true' ) );
            register_rest_route( $ns, '/bootstrap', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'bootstrap'), 'permission_callback'=>'__return_true' ) );
            register_rest_route( $ns, '/messages', array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'messages'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/messages', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'send'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/messages', array( 'methods'=>'PUT', 'callback'=>array(__CLASS__,'edit'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/messages', array( 'methods'=>'DELETE', 'callback'=>array(__CLASS__,'delete_message'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/quick-action', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'quick_action'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/reactions', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'reaction'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/attachments', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'attachment'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/context', array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'context'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/device', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'device'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/read', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'read'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/typing', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'typing'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/status', array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'status'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/tickets', array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'tickets'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/tickets', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'create_ticket'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/tickets', array( 'methods'=>'PUT', 'callback'=>array(__CLASS__,'update_ticket'), 'permission_callback'=>array(__CLASS__,'allow') ) );
            register_rest_route( $ns, '/rating', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'rate_ticket'), 'permission_callback'=>array(__CLASS__,'allow') ) );
        }
    }

    /** Public, cache-safe UI contract consumed by the native Android client. */
    public static function config( $request = null ) {
        $response = rest_ensure_response( self::config_payload() );
        if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
            $response->header( 'Cache-Control', 'public, max-age=300, stale-while-revalidate=600' );
            $response->header( 'ETag', '"' . self::config_revision() . '"' );
        }
        return $response;
    }

    private static function config_revision() {
        $settings = get_option( 'dap_settings', array() );
        $keys = array(
            'brand_name','primary_color','agent_status','business_hours','agent_eta_online','agent_eta_away',
            'launcher_label_enabled','launcher_kicker','launcher_message','launcher_popup_delay',
            'launcher_popup_interval','launcher_popup_visible','launcher_popup_max','launcher_suppress_checkout',
            'show_whatsapp','whatsapp','products_enabled','recommend_enabled','product_context_enabled',
            'order_support_enabled','runtime_load','launcher_side','launcher_bottom','font_scale','force_reduced_motion'
        );
        $snapshot = array( 'version'=>DSA2_VERSION );
        foreach ( $keys as $key ) $snapshot[$key] = isset($settings[$key]) ? $settings[$key] : null;
        return substr( hash( 'sha256', wp_json_encode( $snapshot ) ), 0, 16 );
    }

    private static function config_payload() {
        $settings = wp_parse_args( get_option( 'dap_settings', array() ), array(
            'brand_name'=>'Delicat Assistant','primary_color'=>'#ff5a2b','business_hours'=>'',
            'launcher_label_enabled'=>1,'launcher_kicker'=>'Besoin d’aide ?',
            'launcher_message'=>'Discutez avec nous !','launcher_popup_delay'=>10,
            'launcher_popup_interval'=>75,'launcher_popup_visible'=>6,'launcher_popup_max'=>3,
            'launcher_suppress_checkout'=>1,'show_whatsapp'=>1,'whatsapp'=>'',
            'products_enabled'=>1,'recommend_enabled'=>1,'product_context_enabled'=>1,
            'order_support_enabled'=>1,'runtime_load'=>'demand','launcher_side'=>'right','launcher_bottom'=>16,
            'font_scale'=>100,'force_reduced_motion'=>0
        ) );
        $wa = preg_replace( '/\D+/', '', (string) $settings['whatsapp'] );
        $base = untrailingslashit( rest_url( self::NS ) );
        $status = DSA2_Data::effective_status();
        $revision = self::config_revision();
        return array(
            'api_version'=>'2.3',
            'plugin_version'=>DSA2_VERSION,
            'config_revision'=>$revision,
            'cache_ttl_seconds'=>300,
            'poll_seconds'=>7,
            'background_poll_seconds'=>30,
            'brand'=>sanitize_text_field( (string) $settings['brand_name'] ),
            // Kept for older Android builds.
            'agent_status'=>$status,
            'business_hours'=>sanitize_text_field( (string) $settings['business_hours'] ),
            'agent'=>array(
                'status'=>$status,
                'eta'=>DSA2_Data::agent_eta($status),
                'business_hours'=>sanitize_text_field( (string) $settings['business_hours'] ),
                'bot_available'=>true,
                'handoff_enabled'=>true,
            ),
            'languages'=>array('fr','ht','en'),
            'theme'=>array(
                'primary'=>sanitize_hex_color( (string) $settings['primary_color'] ) ?: '#ff5a2b',
                'launcher_start'=>'#775cff',
                'launcher_end'=>'#4d2fd2',
                'launcher_badge'=>'#ff2455',
                'surface'=>'#ffffff',
                'text'=>'#10152e',
                'icon_id'=>'delicat_bot_v1',
            ),
            'launcher'=>array(
                'enabled'=>!empty($settings['launcher_label_enabled']),
                'kicker'=>sanitize_text_field( (string) $settings['launcher_kicker'] ),
                'message'=>sanitize_text_field( (string) $settings['launcher_message'] ),
                'accessibility_label'=>'Ouvrir Delicat Assistant',
                'show_status_dot'=>true,
                'pulse_enabled'=>empty($settings['force_reduced_motion']),
                'popup_first_delay_seconds'=>max(3,min(120,(int)$settings['launcher_popup_delay'])),
                'popup_interval_seconds'=>max(30,min(600,(int)$settings['launcher_popup_interval'])),
                'popup_visible_seconds'=>max(3,min(15,(int)$settings['launcher_popup_visible'])),
                'popup_max_per_session'=>max(0,min(10,(int)$settings['launcher_popup_max'])),
                'popup_stop_after_interaction'=>true,
                'suppress_on_checkout'=>!empty($settings['launcher_suppress_checkout']),
                'suppress_while_typing'=>true,
                'collision_avoidance'=>true,
                'preferred_side'=>( isset( $settings['launcher_side'] ) && 'left' === $settings['launcher_side'] ) ? 'left' : 'right',
                'base_bottom_dp'=>max( 0, min( 400, (int) $settings['launcher_bottom'] ) ),
                'minimum_touch_target_dp'=>44,
            ),
            'performance'=>array(
                'runtime_load'=>('demand'===$settings['runtime_load']?'tap':(in_array($settings['runtime_load'],array('eager','auto'),true)?$settings['runtime_load']:'demand')),
                'lazy_chat_ui'=>true,
                'config_stale_while_revalidate_seconds'=>600,
                'retry_backoff_ms'=>array(650,1300,2600),
                'save_data_poll_multiplier'=>2,
            ),
            'accessibility'=>array(
                'font_scale_percent'=>max(90,min(125,(int)$settings['font_scale'])),
                'force_reduced_motion'=>!empty($settings['force_reduced_motion']),
                'focus_trap'=>true,
                'screen_reader_labels'=>true,
                'minimum_touch_target_dp'=>44,
            ),
            'features'=>array(
                'messages'=>true,
                'agent_chat'=>true,
                'bot_chat'=>true,
                'agent_handoff'=>true,
                'attachments'=>true,
                'tickets'=>true,
                'reactions'=>true,
                'products'=>!empty($settings['products_enabled']),
                'product_context'=>!empty($settings['product_context_enabled']),
                'order_support'=>!empty($settings['order_support_enabled']),
                'recommendations'=>!empty($settings['recommend_enabled']),
                'whatsapp'=>!empty($settings['show_whatsapp']) && (bool)$wa,
                'firebase_push'=>true,
                'remote_config'=>true,
                'deep_links'=>true,
                'token_refresh'=>true,
                'real_unread_badge'=>true,
            ),
            'links'=>array(
                'whatsapp'=>$wa ? 'https://wa.me/'.$wa : '',
                'shop'=>DSA2_Data::store_url('shop'),
                'wallet'=>DSA2_Data::store_url('wallet'),
                'redeem'=>DSA2_Data::store_url('redeem'),
                'account'=>DSA2_Data::account_url(),
                'orders'=>function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('orders') : DSA2_Data::account_url(),
            ),
            'deep_links'=>array(
                'assistant'=>'delicat://assistant',
                'chat'=>'delicat://assistant/chat',
                'conversation_template'=>'delicat://assistant/chat?conversation_id={conversation_id}',
                'order_template'=>'delicat://orders/{order_id}',
            ),
            'endpoints'=>array(
                'config'=>$base.'/config',
                'bootstrap'=>$base.'/bootstrap',
                'messages'=>$base.'/messages',
                'quick_action'=>$base.'/quick-action',
                'attachments'=>$base.'/attachments',
                'context'=>$base.'/context',
                'device'=>$base.'/device',
                'read'=>$base.'/read',
                'typing'=>$base.'/typing',
                'status'=>$base.'/status',
                'tickets'=>$base.'/tickets',
                'rating'=>$base.'/rating',
            ),
        );
    }

    private static function token( $request ) {
        $token = sanitize_text_field( (string) $request->get_header( 'X-Delicat-App-Token' ) );
        return strlen( $token ) <= 128 ? $token : '';
    }
    private static function hash( $token ) { return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ); }
    public static function installation_key( $installation_id ) {
        return 'app_' . substr( hash_hmac( 'sha256', (string) $installation_id, wp_salt( 'nonce' ) ), 0, 48 );
    }
    private static function rate_key( $name, $id ) { return 'dap_mobile_' . sanitize_key($name) . '_' . md5((string)$id); }
    private static function limited( $name, $id, $max, $window ) {
        $key = self::rate_key($name,$id); $d=get_transient($key);
        // Fixed window. set_transient() with a fresh TTL on every hit renewed the window
        // each time, so a conversation at the limit could stay blocked indefinitely.
        if( ! is_array($d) || ! isset($d['t']) || ( time() - (int)$d['t'] ) > $window ) $d = array('c'=>0,'t'=>time());
        if( (int)$d['c'] >= $max ) return true;
        $d['c'] = (int)$d['c'] + 1;
        set_transient($key,$d,$window); return false;
    }

    public static function allow( $request ) {
        // Routes are not registered while the API is off; this also covers any other caller (6.6.1).
        if(!self::customer_api_enabled()) return new WP_Error('rest_no_route','No route was found matching the URL and request method.',array('status'=>404));
        $token=self::token($request);
        if(!$token) return new WP_Error('missing_token','Missing app token',array('status'=>401));
        global $wpdb; $table=$wpdb->prefix.'dap_mobile_sessions';
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE token_hash=%s AND expires_at>UTC_TIMESTAMP()",self::hash($token)));
        if(!$row) return new WP_Error('invalid_token','Invalid or expired app token',array('status'=>401));
        if(self::limited('api',(int)$row->conversation_id,600,MINUTE_IN_SECONDS)) return new WP_Error('rate_limited','Too many requests',array('status'=>429));
        $request->set_param('_mobile_session',$row); return true;
    }

    public static function bootstrap( $r ) {
        $install = sanitize_text_field( (string) $r->get_param( 'installation_id' ) );
        if ( ! preg_match( '/^[A-Za-z0-9_-]{16,100}$/', $install ) ) return new WP_Error( 'bad_installation', 'Invalid installation id', array( 'status'=>400 ) );
        $remote = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        if ( self::limited( 'bootstrap', $install, 12, HOUR_IN_SECONDS ) || self::limited( 'bootstrap_ip', md5($remote), 120, HOUR_IN_SECONDS ) ) {
            return new WP_Error( 'rate_limited', 'Too many requests', array( 'status'=>429 ) );
        }
        $lang = sanitize_key( (string) $r->get_param( 'language' ) );
        if ( ! in_array( $lang, array( 'fr', 'en', 'ht' ), true ) ) $lang = 'fr';

        global $wpdb;
        $sessions = $wpdb->prefix . 'dap_mobile_sessions';
        $conversations = $wpdb->prefix . 'dap_conversations';
        $presented = self::token( $r );
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $sessions WHERE installation_id=%s", $install ) );
        $valid_rotation = false;
        $conv = null;

        // Account linking re-keys the conversation to user_<id>. Looking up only app_<id>
        // on the next bootstrap silently disconnected the customer. Resume through the
        // session row when the app proves it still owns the current bearer token.
        if ( $existing && $presented && hash_equals( (string) $existing->token_hash, self::hash( $presented ) ) ) {
            $valid_rotation = strtotime( (string) $existing->expires_at . ' UTC' ) > time();
            if ( $valid_rotation ) {
                $conv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $conversations WHERE id=%d", (int) $existing->conversation_id ) );
                $valid_rotation = (bool) $conv;
            }
        }

        if ( ! $conv ) {
            $session_key = self::installation_key( $install );
            $legacy_key = 'app_' . $install;
            // Without the previous token, never restore an account-linked thread from a
            // guessable installation identifier. A clean guest thread can be linked again.
            $conv = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $conversations WHERE user_id=0 AND session_key IN (%s,%s) ORDER BY CASE WHEN session_key=%s THEN 0 ELSE 1 END LIMIT 1",
                $session_key,
                $legacy_key,
                $session_key
            ) );
            if ( ! $conv ) {
                $inserted = $wpdb->insert( $conversations, array(
                    'session_key'=>$session_key,
                    'user_id'=>0,
                    'status'=>'open',
                    'language'=>$lang,
                    'last_message_at'=>current_time('mysql'),
                    'created_at'=>current_time('mysql'),
                ) );
                $cid = $inserted ? (int) $wpdb->insert_id : 0;
                if ( ! $cid ) {
                    $conv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $conversations WHERE user_id=0 AND session_key=%s", $session_key ) );
                    $cid = $conv ? (int) $conv->id : 0;
                }
            } else {
                $cid = (int) $conv->id;
            }
        } else {
            $cid = (int) $conv->id;
        }
        if ( ! $cid ) return new WP_Error( 'bootstrap_failed', 'Could not start a secure session', array( 'status'=>500 ) );

        $raw = wp_generate_password( 64, false, false );
        $saved = $wpdb->replace( $sessions, array(
            'installation_id'=>$install,
            'conversation_id'=>$cid,
            'token_hash'=>self::hash($raw),
            'language'=>$lang,
            'expires_at'=>gmdate('Y-m-d H:i:s',time()+180*DAY_IN_SECONDS),
            'created_at'=>gmdate('Y-m-d H:i:s'),
        ) );
        if ( false === $saved ) return new WP_Error( 'bootstrap_failed', 'Could not store a secure session', array( 'status'=>500 ) );
        $wpdb->update( $conversations, array( 'language'=>$lang ), array( 'id'=>$cid ), array( '%s' ), array( '%d' ) );
        // If a lost/expired token produced a clean thread, its push registration must move
        // too; otherwise that installation keeps receiving replies from the old account.
        $wpdb->update( $wpdb->prefix . 'dap_mobile_devices', array( 'conversation_id'=>$cid ), array( 'installation_id'=>$install ), array( '%d' ), array( '%s' ) );
        $settings = get_option( 'dap_settings', array() );
        return rest_ensure_response( array(
            'token'=>$raw,
            'conversation_id'=>$cid,
            'resumed'=>(bool)$valid_rotation,
            'poll_seconds'=>7,
            'brand'=>sanitize_text_field($settings['brand_name']??'Delicat Assistance'),
            'agent_status'=>DSA2_Data::effective_status(),
            'languages'=>array('fr','ht','en'),
            'app_config'=>self::config_payload(),
        ) );
    }

    public static function messages( $r ) {
        $s=$r->get_param('_mobile_session'); $after=absint($r->get_param('after_id')); global $wpdb;
        $channel=sanitize_key((string)$r->get_param('channel'));
        if(in_array($channel,array('bot','agent','system'),true))$rows=DSA2_Data::messages((int)$s->conversation_id,$after,$channel,120);
        else $rows=DSA2_Data::client_messages((int)$s->conversation_id,$after);
        return rest_ensure_response(array('messages'=>array_map(array(__CLASS__,'format'),(array)$rows),'agent_status'=>DSA2_Data::effective_status(),'server_time'=>current_time('mysql'),'last_agent_read_id'=>(int)$wpdb->get_var($wpdb->prepare("SELECT last_agent_read_id FROM {$wpdb->prefix}dap_conversations WHERE id=%d",(int)$s->conversation_id))));
    }

    /**
     * The app used to store the customer's message and answer nothing at all: no FAQ hit,
     * no product card, no guide, no handoff to a human. Every one of those already existed
     * on the website. Both now call DSA2_Data::respond_to_message(), so the app and the
     * chat give the same answer to the same question.
     */
    public static function send( $r ) {
        $s=$r->get_param('_mobile_session');
        $remote=sanitize_text_field((string)($_SERVER['REMOTE_ADDR']??''));
        if(self::limited('send',(int)$s->conversation_id,40,MINUTE_IN_SECONDS)||self::limited('send_ip',md5($remote),600,MINUTE_IN_SECONDS)) return new WP_Error('rate_limited','Too many messages',array('status'=>429));
        $body=trim(sanitize_textarea_field((string)$r->get_param('message')));
        if($body===''||DSA2_Compat::length($body)>2000) return new WP_Error('bad_message','Message is empty or too long',array('status'=>400));
        $reply_to=absint($r->get_param('reply_to')); $context=DSA2_Data::sanitize_context($r->get_param('context')); $meta=$context?array('context'=>$context):array();
        global $wpdb;
        if($reply_to){
            $reply=$wpdb->get_row($wpdb->prepare("SELECT id,body FROM {$wpdb->prefix}dap_messages WHERE id=%d AND conversation_id=%d",$reply_to,(int)$s->conversation_id));
            if($reply){$meta['reply_to']=(int)$reply->id;$meta['reply_preview']=wp_strip_all_tags(wp_trim_words($reply->body,12,'…'));}
        }
        $conv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dap_conversations WHERE id=%d",(int)$s->conversation_id));
        if(!$conv) return new WP_Error('not_found','Conversation not found',array('status'=>404));
        $lang=self::lang($s,$r);
        $intent=sanitize_key((string)$r->get_param('intent'));
        if(!in_array($intent,array('bot','agent','product'),true))$intent='';
        $live=('waiting'===(string)$conv->status)||('open'===(string)$conv->status&&(int)$conv->assigned_to>0);
        $channel=('agent'===$intent||(''===$intent&&$live))?'agent':'bot';
        $id=DSA2_Data::add_message((int)$s->conversation_id,'customer',$body,'text',$meta,$channel);
        if(!$id) return new WP_Error('store_failed','Could not store the message',array('status'=>500));
        do_action('dsa2_mobile_customer_message',(int)$s->conversation_id,$id,$body);

        // The reply is already in this HTTP response; a push for it would buzz the phone
        // the customer is holding and looking at.
        self::$suppress_push_for_conversation=(int)$s->conversation_id;
        $result=DSA2_Data::respond_to_message($conv,$body,$lang,$intent,(int)($conv->user_id??0),$context);
        self::$suppress_push_for_conversation=0;

        $out=array('message'=>self::format((object)array('id'=>$id,'sender'=>'customer','sender_id'=>0,'channel'=>$channel,'body'=>$body,'message_type'=>'text','meta'=>$meta?wp_json_encode($meta):null,'created_at'=>current_time('mysql'))),'reply'=>null,'escalated'=>(bool)$result['escalated']);
        if(!empty($result['message_id'])){
            $row=$wpdb->get_row($wpdb->prepare("SELECT id,sender,sender_id,channel,body,message_type,meta,created_at FROM {$wpdb->prefix}dap_messages WHERE id=%d",(int)$result['message_id']));
            if($row)$out['reply']=self::format($row);
        }
        return rest_ensure_response($out);
    }

    /**
     * Tappable cards — guides, product lists, the FAQ menu, "talk to an agent". These are
     * the buttons the website has always had; without this route the app could only ever
     * send plain text and could not act on the buttons inside the cards it received.
     */
    public static function quick_action( $r ) {
        $s=$r->get_param('_mobile_session');
        $remote=sanitize_text_field((string)($_SERVER['REMOTE_ADDR']??''));
        if(self::limited('quick',(int)$s->conversation_id,60,MINUTE_IN_SECONDS)||self::limited('quick_ip',md5($remote),900,MINUTE_IN_SECONDS)) return new WP_Error('rate_limited','Too many requests',array('status'=>429));
        $action=sanitize_key((string)$r->get_param('quick_action'));
        if($action==='') return new WP_Error('bad_action','Missing action',array('status'=>400));
        global $wpdb;
        $conv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dap_conversations WHERE id=%d",(int)$s->conversation_id));
        if(!$conv) return new WP_Error('not_found','Conversation not found',array('status'=>404));
        $lang=self::lang($s,$r);
        $uid=(int)($conv->user_id??0);
        $context=DSA2_Data::sanitize_context($r->get_param('context'));

        self::$suppress_push_for_conversation=(int)$s->conversation_id;
        $payload=DSA2_Data::quick_action_payload($action,$lang,$uid,$context);
        if(!$payload){ self::$suppress_push_for_conversation=0; return new WP_Error('unknown_action','Action unavailable',array('status'=>404)); }
        if('end_chat'===$action) $wpdb->update($wpdb->prefix.'dap_conversations',array('status'=>'resolved'),array('id'=>(int)$conv->id));
        $conv->language=$lang; // the card is written in the language the app is set to now
        $result=DSA2_Data::store_assistant_payload($conv,$payload,$uid);
        self::$suppress_push_for_conversation=0;

        $out=array('reply'=>null,'escalated'=>(bool)$result['escalated']);
        if(!empty($result['message_id'])){
            $row=$wpdb->get_row($wpdb->prepare("SELECT id,sender,sender_id,channel,body,message_type,meta,created_at FROM {$wpdb->prefix}dap_messages WHERE id=%d",(int)$result['message_id']));
            if($row)$out['reply']=self::format($row);
        }
        return rest_ensure_response($out);
    }

    /** Request language wins, then the stored session language, then French. */
    private static function lang( $s, $r ) {
        $lang=sanitize_key((string)$r->get_param('language'));
        if(!in_array($lang,array('fr','en','ht'),true))$lang=sanitize_key((string)($s->language??'fr'));
        return in_array($lang,array('fr','en','ht'),true)?$lang:'fr';
    }




    public static function edit( $r ) {
        $s=$r->get_param('_mobile_session'); $id=absint($r->get_param('message_id')); $body=trim(sanitize_textarea_field((string)$r->get_param('message')));
        if($body===''||DSA2_Compat::length($body)>2000) return new WP_Error('bad_message','Invalid message',array('status'=>400));
        global $wpdb; $table=$wpdb->prefix.'dap_messages'; $m=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND conversation_id=%d AND sender='customer' AND message_type='text'",$id,(int)$s->conversation_id));
        if(!$m) return new WP_Error('not_found','Message not found',array('status'=>404));
        if(strtotime(get_gmt_from_date($m->created_at)) < time()-15*MINUTE_IN_SECONDS) return new WP_Error('edit_window','Edit window expired',array('status'=>403));
        $meta=$m->meta?json_decode($m->meta,true):array(); if(!is_array($meta))$meta=array(); $meta['edited']=true; $meta['edited_at']=current_time('mysql');
        $wpdb->update($table,array('body'=>$body,'meta'=>wp_json_encode($meta)),array('id'=>$id),array('%s','%s'),array('%d')); $m->body=$body;$m->meta=wp_json_encode($meta);
        return rest_ensure_response(array('message'=>self::format($m)));
    }

    public static function delete_message( $r ) {
        $s=$r->get_param('_mobile_session'); $id=absint($r->get_param('message_id')); global $wpdb; $table=$wpdb->prefix.'dap_messages';
        $m=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND conversation_id=%d AND sender='customer' AND message_type='text'",$id,(int)$s->conversation_id));
        if(!$m) return new WP_Error('not_found','Message not found',array('status'=>404));
        if(strtotime(get_gmt_from_date($m->created_at)) < time()-15*MINUTE_IN_SECONDS) return new WP_Error('delete_window','Delete window expired',array('status'=>403));
        $meta=$m->meta?json_decode($m->meta,true):array(); if(!is_array($meta))$meta=array(); $meta['deleted']=true;$meta['deleted_at']=current_time('mysql');
        $wpdb->update($table,array('body'=>'Message supprimé','message_type'=>'deleted','meta'=>wp_json_encode($meta)),array('id'=>$id),array('%s','%s','%s'),array('%d'));$m->body='Message supprimé';$m->message_type='deleted';$m->meta=wp_json_encode($meta);
        return rest_ensure_response(array('message'=>self::format($m)));
    }

    public static function reaction( $r ) {
        $s=$r->get_param('_mobile_session');
        if(self::limited('reaction',(int)$s->conversation_id,80,HOUR_IN_SECONDS)) return new WP_Error('rate_limited','Too many reactions',array('status'=>429));
        $message_id=absint($r->get_param('message_id')); $reaction=sanitize_text_field((string)$r->get_param('reaction'));
        $allowed=array('👍','❤️','😂','🙏'); if(!in_array($reaction,$allowed,true)) return new WP_Error('bad_reaction','Unsupported reaction',array('status'=>400));
        global $wpdb; $table=$wpdb->prefix.'dap_messages';
        $m=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND conversation_id=%d",$message_id,(int)$s->conversation_id));
        if(!$m) return new WP_Error('not_found','Message not found',array('status'=>404));
        $meta=$m->meta?json_decode($m->meta,true):array(); if(!is_array($meta))$meta=array(); $meta['customer_reaction']=$reaction;
        $wpdb->update($table,array('meta'=>wp_json_encode($meta)),array('id'=>$message_id),array('%s'),array('%d'));
        $m->meta=wp_json_encode($meta); return rest_ensure_response(array('message'=>self::format($m)));
    }

    public static function attachment( $r ) {
        $s=$r->get_param('_mobile_session');
        $remote=sanitize_text_field((string)($_SERVER['REMOTE_ADDR']??''));
        if(self::limited('upload',(int)$s->conversation_id,12,HOUR_IN_SECONDS)||self::limited('upload_ip',md5($remote),60,HOUR_IN_SECONDS)) return new WP_Error('rate_limited','Too many uploads',array('status'=>429));
        $ticket_id=absint($r->get_param('ticket_id'));
        if($ticket_id && ('dap_ticket'!==get_post_type($ticket_id) || (int)get_post_meta($ticket_id,'dap_conversation_id',true)!==(int)$s->conversation_id)) return new WP_Error('forbidden_ticket','Ticket unavailable',array('status'=>403));
        if(empty($_FILES['file']) || !empty($_FILES['file']['error'])) return new WP_Error('missing_file','Missing attachment',array('status'=>400));
        if((int)$_FILES['file']['size'] > 8*MB_IN_BYTES) return new WP_Error('file_too_large','Attachment exceeds 8 MB',array('status'=>413));
        $allowed=array('image/jpeg','image/png','image/webp','audio/mpeg','audio/mp4','audio/aac','audio/ogg','audio/webm','application/pdf');
        $check=wp_check_filetype_and_ext($_FILES['file']['tmp_name'],$_FILES['file']['name']);
        if(empty($check['type']) || !in_array($check['type'],$allowed,true)) return new WP_Error('bad_file_type','Unsupported attachment type',array('status'=>415));
        $name=sanitize_file_name($_FILES['file']['name']);
        $storage_ext=preg_replace('/[^a-z0-9]+/','',strtolower((string)($check['ext']??'')));
        if(''===$storage_ext)return new WP_Error('bad_file_type','Unsupported attachment type',array('status'=>415));
        if(''===$name)$name='attachment.'.$storage_ext;
        // Customer filenames often contain order numbers or names. Keep that label only in
        // protected message metadata and use an unguessable public uploads filename.
        $_FILES['file']['name']='dap-mobile-'.wp_generate_password(24,false,false).'.'.$storage_ext;
        require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php';
        $aid=media_handle_upload('file',0,array(),array('test_form'=>false));
        if(is_wp_error($aid)) return $aid;
        $url=wp_get_attachment_url($aid);
        $context=DSA2_Data::sanitize_context($r->get_param('context')); $meta=array('attachment_id'=>(int)$aid,'url'=>esc_url_raw($url),'mime'=>$check['type'],'name'=>$name); if($context)$meta['context']=$context;
        if($ticket_id){ $meta['ticket_id']=$ticket_id; $items=get_post_meta($ticket_id,'dap_ticket_attachments',true); if(!is_array($items))$items=array(); $items[]=(int)$aid; update_post_meta($ticket_id,'dap_ticket_attachments',array_values(array_unique($items))); }
        global $wpdb;
        $conv=$wpdb->get_row($wpdb->prepare("SELECT status,assigned_to FROM {$wpdb->prefix}dap_conversations WHERE id=%d",(int)$s->conversation_id));
        $intent=sanitize_key((string)$r->get_param('intent'));
        $live=$conv&&('waiting'===(string)$conv->status||('open'===(string)$conv->status&&(int)$conv->assigned_to>0));
        $channel=($ticket_id||'agent'===$intent||(''===$intent&&$live))?'agent':'bot';
        $id=DSA2_Data::add_message((int)$s->conversation_id,'customer',$name,'attachment',$meta,$channel);
        if(!$id){wp_delete_attachment($aid,true);return new WP_Error('store_failed','Could not store the attachment',array('status'=>500));}
        do_action('dsa2_mobile_customer_message',(int)$s->conversation_id,$id,$name);
        return rest_ensure_response(array('message'=>self::format((object)array('id'=>$id,'sender'=>'customer','sender_id'=>0,'channel'=>$channel,'body'=>$name,'message_type'=>'attachment','meta'=>wp_json_encode($meta),'created_at'=>current_time('mysql')))));
    }

    public static function context( $r ) {
        $s=$r->get_param('_mobile_session'); global $wpdb;
        $conv=$wpdb->get_row($wpdb->prepare("SELECT user_id,customer_name FROM {$wpdb->prefix}dap_conversations WHERE id=%d",(int)$s->conversation_id));
        $uid=$conv?(int)$conv->user_id:0; $orders=array(); $wallet='';
        $user=$uid?get_userdata($uid):false; if($uid && !$user) $uid=0;
        if($uid && function_exists('wc_get_orders')){
            foreach(wc_get_orders(array('customer_id'=>$uid,'limit'=>5,'orderby'=>'date','order'=>'DESC')) as $o){
                $items=array(); foreach(array_slice($o->get_items(),0,4,true) as $item){$items[]=array('name'=>sanitize_text_field($item->get_name()),'quantity'=>(int)$item->get_quantity());}
                $orders[]=array(
                    'id'=>(int)$o->get_id(),'number'=>sanitize_text_field($o->get_order_number()),
                    'status'=>sanitize_key($o->get_status()),'status_label'=>wc_get_order_status_name($o->get_status()),
                    'total'=>wp_strip_all_tags($o->get_formatted_order_total()),
                    'date'=>$o->get_date_created()?$o->get_date_created()->date_i18n('Y-m-d'):'',
                    'url'=>esc_url_raw($o->get_view_order_url()),'items'=>$items,
                    'support_actions'=>array('payment_missing','pin_missing','player_id_help','order_agent')
                );
            }
            if(function_exists('woo_wallet')){try{$wallet=wp_strip_all_tags(woo_wallet()->wallet->get_wallet_balance($uid));}catch(Throwable $e){$wallet='';}}
        }
        $settings=get_option('dap_settings',array());
        return rest_ensure_response(array(
            'linked'=>(bool)$uid,
            'customer_name'=>$conv?sanitize_text_field($conv->customer_name):'',
            'wallet_balance'=>$wallet,
            'orders'=>$orders,
            'latest_order'=>$orders?$orders[0]:null,
            'email'=>$user?sanitize_email($user->user_email):'',
            'reward_points'=>$uid?(int)get_user_meta($uid,'dap_reward_points',true):0,
            'open_tickets'=>(int)count(get_posts(array('post_type'=>'dap_ticket','post_status'=>array('publish','draft','pending'),'fields'=>'ids','meta_key'=>'dap_conversation_id','meta_value'=>(int)$s->conversation_id,'meta_query'=>array(array('key'=>'dap_ticket_status','value'=>'open'))))),
            'capabilities'=>array('product_context'=>!empty($settings['product_context_enabled']),'order_support'=>!empty($settings['order_support_enabled']),'agent_handoff'=>true),
            'privacy'=>array('player_id_value_transmitted'=>false,'pin_value_transmitted'=>false),
        ));
    }

    public static function device( $r ) {
        $s=$r->get_param('_mobile_session'); $fcm=sanitize_text_field((string)$r->get_param('fcm_token'));
        if(strlen($fcm)<20||strlen($fcm)>4096) return new WP_Error('bad_fcm','Invalid Firebase token',array('status'=>400));
        global $wpdb; $wpdb->replace($wpdb->prefix.'dap_mobile_devices',array('conversation_id'=>(int)$s->conversation_id,'installation_id'=>(string)$s->installation_id,'fcm_token'=>$fcm,'platform'=>'android','updated_at'=>gmdate('Y-m-d H:i:s')));
        return rest_ensure_response(array('registered'=>true));
    }

    public static function read( $r ) {
        $s=$r->get_param('_mobile_session'); $last=absint($r->get_param('last_read_id')); global $wpdb;
        $owned=(int)$wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM {$wpdb->prefix}dap_messages WHERE conversation_id=%d AND channel='agent' AND sender='agent' AND id<=%d",(int)$s->conversation_id,$last));
        if($owned)$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}dap_conversations SET last_customer_read_id=GREATEST(last_customer_read_id,%d) WHERE id=%d",$owned,(int)$s->conversation_id));
        $unread=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dap_messages m JOIN {$wpdb->prefix}dap_conversations c ON c.id=m.conversation_id WHERE m.conversation_id=%d AND m.channel='agent' AND m.sender='agent' AND m.id>c.last_customer_read_id",(int)$s->conversation_id));
        return rest_ensure_response(array('updated'=>true,'unread'=>$unread));
    }

    /** Customer-side typing beacon. The agent inbox reads this key. */
    public static function typing( $r ) {
        $s=$r->get_param('_mobile_session'); $typing=rest_sanitize_boolean($r->get_param('typing'));
        $key='dap_customer_typing_'.(int)$s->conversation_id;
        if($typing) set_transient($key,1,15); else delete_transient($key);
        do_action('dsa2_mobile_typing',(int)$s->conversation_id,$typing);
        return rest_ensure_response(array('updated'=>true));
    }

    public static function status( $r ) {
        $s=$r->get_param('_mobile_session'); global $wpdb;
        $unread=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dap_messages m JOIN {$wpdb->prefix}dap_conversations c ON c.id=m.conversation_id WHERE m.conversation_id=%d AND m.channel='agent' AND m.id>c.last_customer_read_id AND m.sender='agent'",(int)$s->conversation_id));
        $status=DSA2_Data::effective_status();
        return rest_ensure_response(array('agent_status'=>$status,'agent_eta'=>DSA2_Data::agent_eta($status),'business_hours'=>sanitize_text_field((string)(get_option('dap_settings',array())['business_hours']??'')),'config_revision'=>self::config_revision(),'unread'=>$unread,'typing'=>(bool)get_transient('dap_agent_typing_'.(int)$s->conversation_id),'last_agent_read_id'=>(int)$wpdb->get_var($wpdb->prepare("SELECT last_agent_read_id FROM {$wpdb->prefix}dap_conversations WHERE id=%d",(int)$s->conversation_id))));
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

    public static function tickets( $r ) {
        $s=$r->get_param('_mobile_session');
        $posts=get_posts(array('post_type'=>'dap_ticket','post_status'=>array('publish','draft','pending'),'posts_per_page'=>20,'orderby'=>'date','order'=>'DESC','meta_key'=>'dap_conversation_id','meta_value'=>(int)$s->conversation_id));
        $tickets=array();
        foreach($posts as $p){ $agent_id=(int)get_post_meta($p->ID,'dap_ticket_assigned_to',true); $agent=$agent_id?get_userdata($agent_id):false; $attachments=get_post_meta($p->ID,'dap_ticket_attachments',true); $tickets[]=array('id'=>(int)$p->ID,'subject'=>sanitize_text_field($p->post_title),'message'=>wp_strip_all_tags($p->post_content),'status'=>sanitize_key(get_post_meta($p->ID,'dap_ticket_status',true)?:'open'),'priority'=>sanitize_key(get_post_meta($p->ID,'dap_ticket_priority',true)?:'normal'),'created_at'=>get_post_time('c',true,$p),'updated_at'=>get_post_modified_time('c',true,$p),'rating'=>(int)get_post_meta($p->ID,'dap_ticket_rating',true),'assigned_agent'=>$agent?sanitize_text_field($agent->display_name):'Équipe Delicat','attachment_count'=>is_array($attachments)?count($attachments):0); }
        return rest_ensure_response(array('tickets'=>$tickets));
    }

    public static function create_ticket( $r ) {
        $s=$r->get_param('_mobile_session');
        $remote=sanitize_text_field((string)($_SERVER['REMOTE_ADDR']??''));
        if(self::limited('ticket',(int)$s->conversation_id,6,DAY_IN_SECONDS)||self::limited('ticket_ip',md5($remote),50,DAY_IN_SECONDS)) return new WP_Error('rate_limited','Too many tickets',array('status'=>429));
        $subject=trim(sanitize_text_field((string)$r->get_param('subject')));
        $message=trim(sanitize_textarea_field((string)$r->get_param('message')));
        $priority=sanitize_key((string)$r->get_param('priority')); if(!in_array($priority,array('low','normal','high','urgent'),true))$priority='normal';
        if(DSA2_Compat::length($subject)<3||DSA2_Compat::length($subject)>120||DSA2_Compat::length($message)<5||DSA2_Compat::length($message)>3000) return new WP_Error('bad_ticket','Invalid ticket details',array('status'=>400));
        $id=wp_insert_post(array('post_type'=>'dap_ticket','post_status'=>'publish','post_title'=>$subject,'post_content'=>$message),true);
        if(is_wp_error($id))return $id;
        update_post_meta($id,'dap_conversation_id',(int)$s->conversation_id); update_post_meta($id,'dap_ticket_status','open'); update_post_meta($id,'dap_ticket_priority',$priority); update_post_meta($id,'dap_ticket_assigned_to',0); update_post_meta($id,'dap_ticket_attachments',array());
        $lang=self::lang($s,$r);
        $ticket_label = 'en' === $lang ? 'Ticket created' : ( 'ht' === $lang ? 'Tikè kreye' : 'Ticket créé' );
        DSA2_Data::add_message((int)$s->conversation_id,'customer',$ticket_label.' #'.$id.' — '.$subject,'system',array('ticket_id'=>(int)$id,'priority'=>$priority),'agent');
        DSA2_Data::request_agent((int)$s->conversation_id);
        do_action('dsa2_mobile_ticket_created',(int)$id,(int)$s->conversation_id,$subject,$priority);
        return rest_ensure_response(array('ticket'=>array('id'=>(int)$id,'subject'=>$subject,'message'=>$message,'status'=>'open','priority'=>$priority,'created_at'=>get_post_time('c',true,$id))));
    }

    public static function update_ticket( $r ) {
        $s=$r->get_param('_mobile_session'); $id=absint($r->get_param('ticket_id')); $status=sanitize_key((string)$r->get_param('status'));
        if(!in_array($status,array('open','closed'),true)) return new WP_Error('bad_status','Invalid status',array('status'=>400));
        if('dap_ticket'!==get_post_type($id)||(int)get_post_meta($id,'dap_conversation_id',true)!==(int)$s->conversation_id) return new WP_Error('forbidden','Ticket unavailable',array('status'=>403));
        update_post_meta($id,'dap_ticket_status',$status); wp_update_post(array('ID'=>$id));
        $lang=self::lang($s,$r);
        $state = 'closed' === $status
            ? ( 'en' === $lang ? 'closed' : ( 'ht' === $lang ? 'fèmen' : 'fermé' ) )
            : ( 'en' === $lang ? 'reopened' : ( 'ht' === $lang ? 'relouvri' : 'rouvert' ) );
        DSA2_Data::add_message((int)$s->conversation_id,'customer','Ticket #'.$id.' '.$state,'system',array('ticket_id'=>$id,'ticket_status'=>$status),'agent');
        if('open'===$status)DSA2_Data::request_agent((int)$s->conversation_id);
        $p=get_post($id); return rest_ensure_response(array('ticket'=>array('id'=>$id,'subject'=>sanitize_text_field($p->post_title),'message'=>wp_strip_all_tags($p->post_content),'status'=>$status,'priority'=>sanitize_key(get_post_meta($id,'dap_ticket_priority',true)?:'normal'),'created_at'=>get_post_time('c',true,$p),'updated_at'=>get_post_modified_time('c',true,$p),'rating'=>(int)get_post_meta($id,'dap_ticket_rating',true))));
    }

    public static function rate_ticket( $r ) {
        $s=$r->get_param('_mobile_session'); $id=absint($r->get_param('ticket_id')); $rating=absint($r->get_param('rating')); $comment=sanitize_textarea_field((string)$r->get_param('comment'));
        if($rating<1||$rating>5) return new WP_Error('bad_rating','Rating must be 1-5',array('status'=>400));
        if('dap_ticket'!==get_post_type($id)||(int)get_post_meta($id,'dap_conversation_id',true)!==(int)$s->conversation_id||get_post_meta($id,'dap_ticket_status',true)!=='closed') return new WP_Error('forbidden','Closed ticket required',array('status'=>403));
        update_post_meta($id,'dap_ticket_rating',$rating); update_post_meta($id,'dap_ticket_rating_comment',DSA2_Compat::substr($comment,0,500)); update_post_meta($id,'dap_ticket_rated_at',current_time('mysql',true));
        do_action('dsa2_mobile_ticket_rated',$id,$rating,$comment,(int)$s->conversation_id); return rest_ensure_response(array('success'=>true));
    }

    // add_message() stores wp_kses_post(), so "l'agent" comes back as "l&#039;agent". The web
    // widget decodes before rendering; the app did not, and showed the raw entities.
    public static function format( $m ) {
        $meta=$m->meta?json_decode($m->meta,true):null;
        if(!is_array($meta))$meta=null;
        $sender_id=isset($m->sender_id)?(int)$m->sender_id:0;
        static $agents=array();
        if($sender_id&&!array_key_exists($sender_id,$agents))$agents[$sender_id]=get_userdata($sender_id);
        $agent=$sender_id?($agents[$sender_id]??false):false;
        $channel=isset($m->channel)&&in_array($m->channel,array('bot','agent','system'),true)?$m->channel:((string)$m->sender==='agent'?'agent':'bot');
        return array('id'=>(int)$m->id,'sender'=>(string)$m->sender,'sender_id'=>$sender_id,'sender_name'=>$agent?sanitize_text_field($agent->display_name):'','channel'=>$channel,'body'=>html_entity_decode(wp_strip_all_tags((string)$m->body),ENT_QUOTES,'UTF-8'),'type'=>(string)$m->message_type,'meta'=>$meta,'created_at'=>(string)$m->created_at,'delivery_status'=>'sent');
    }

    public static function message_created($conversation_id,$message_id,$sender,$body,$type,$channel='bot'){
        if('agent'!==$channel||'agent'!==$sender) return;
        // 6.6.1: with the Delicat App API installed, IT pushes support replies to the
        // customer's phone (its own dsa2_message_created listener, private body, the
        // owner's push_chat setting). This plugin no longer sends FCM itself.
        if(class_exists('Delicat_App_Push')) return;
        // The app just received this reply inline; buzzing the phone in the customer's hand
        // for an answer already on their screen is noise.
        if( self::$suppress_push_for_conversation && (int)$conversation_id === (int)self::$suppress_push_for_conversation ) return;
        $args=array(absint($conversation_id),'Delicat Assistance',wp_strip_all_tags(wp_trim_words($body,20,'…')),absint($message_id));
        // HTTP delivery used to run inside add_message(), keeping the customer's send
        // request open for the webhook's entire 10-second timeout. WooCommerce ships
        // Action Scheduler, so enqueue delivery and return the chat response immediately.
        $queued=function_exists('as_enqueue_async_action')?as_enqueue_async_action('dsa2_mobile_push',$args,'delicat-assistant',true):0;
        if(!$queued&&!wp_next_scheduled('dsa2_mobile_push',$args)){
            wp_schedule_single_event(time(),'dsa2_mobile_push',$args);
            if(!defined('DISABLE_WP_CRON')||!DISABLE_WP_CRON)spawn_cron(time());
        }
    }

    public static function push_to_conversation($conversation_id,$title,$body,$message_id=0){
        global $wpdb; $devices=$wpdb->get_results($wpdb->prepare("SELECT fcm_token,installation_id FROM {$wpdb->prefix}dap_mobile_devices WHERE conversation_id=%d",absint($conversation_id)));
        foreach((array)$devices as $device){if(self::push_allowed((string)$device->installation_id))self::send_fcm($device->fcm_token,$title,$body,$conversation_id,$message_id,(string)$device->installation_id);}
    }

    /** Respects per-installation message and quiet-hour preferences. */
    private static function push_allowed($installation_id){
        if(''===$installation_id)return true; // legacy token; it has no preference record
        $prefs=get_option('dap_mobile_prefs_'.$installation_id,array());
        if(is_array($prefs)&&array_key_exists('messages',$prefs)&&!$prefs['messages'])return false;
        $start=(string)($prefs['quiet_start']??'');$end=(string)($prefs['quiet_end']??'');
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end)||$start===$end)return true;
        $now=current_time('H:i');$quiet=$start<$end?($now>=$start&&$now<$end):($now>=$start||$now<$end);
        return !$quiet;
    }

    private static function send_fcm($token,$title,$body,$conversation_id,$message_id,$installation_id=''){
        // 6.6.1: one FCM sender for the whole site -- the Delicat App API's (service account
        // stored there, encrypted). No Firebase webhook or secret lives in this plugin any more.
        if(class_exists('Delicat_App_Push')&&method_exists('Delicat_App_Push','send_to_token')){
            $ok=(bool)Delicat_App_Push::send_to_token((string)$token,(string)$title,(string)$body,array('type'=>'chat','screen'=>'chat','conversation_id'=>(string)$conversation_id,'message_id'=>(string)$message_id));
            if(class_exists('DSA2_Production')) DSA2_Production::audit($ok?'push_sent':'push_failed',array('conversation_id'=>(int)$conversation_id,'message_id'=>(int)$message_id));
            return $ok;
        }
        $s=get_option('dap_settings',array());
        $webhook=esc_url_raw($s['firebase_v1_webhook_url']??''); $secret=(string)($s['firebase_v1_webhook_secret']??'');
        if($webhook){
            $payload=array('token'=>$token,'notification'=>array('title'=>$title,'body'=>$body),'data'=>array('conversation_id'=>(string)$conversation_id,'message_id'=>(string)$message_id,'screen'=>'chat','deep_link'=>'delicat://assistant/chat?conversation_id='.(string)$conversation_id,'config_revision'=>self::config_revision()));
            $headers=array('Content-Type'=>'application/json'); if($secret)$headers['X-Delicat-Signature']=hash_hmac('sha256',wp_json_encode($payload),$secret);
            if('https'!==wp_parse_url($webhook,PHP_URL_SCHEME))return false;
            $res=wp_safe_remote_post($webhook,array('timeout'=>10,'redirection'=>0,'headers'=>$headers,'body'=>wp_json_encode($payload)));
            $ok=!is_wp_error($res)&&wp_remote_retrieve_response_code($res)>=200&&wp_remote_retrieve_response_code($res)<300;
            if(!$ok && class_exists('DSA2_Enterprise')) DSA2_Enterprise::queue_push(array('token'=>$token,'installation_id'=>$installation_id,'title'=>$title,'body'=>$body,'conversation_id'=>(int)$conversation_id,'message_id'=>(int)$message_id));
            if(class_exists('DSA2_Production')) DSA2_Production::audit($ok?'push_sent':'push_failed',array('conversation_id'=>(int)$conversation_id,'message_id'=>(int)$message_id));
            return $ok;
        }
        // Missing configuration is not a transient delivery failure. Queuing every message
        // here filled the retry option with stale notifications on sites that never enabled push.
        return false;
    }

    public static function retry_push_payload($payload){
        $token=(string)($payload['token']??'');$installation=(string)($payload['installation_id']??'');$conversation_id=(int)($payload['conversation_id']??0);
        if(''===$token||!$conversation_id)return 'dropped'; // malformed/stale queue item: consume it
        if(class_exists('Delicat_App_Push')) return 'dropped'; // 6.6.1: the API delivers and retries on its own.
        global $wpdb;
        $device_exists=(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}dap_mobile_devices WHERE fcm_token=%s AND conversation_id=%d AND installation_id=%s LIMIT 1",$token,$conversation_id,$installation));
        if(!$device_exists||!self::push_allowed($installation))return 'dropped'; // revoked, unlinked or disabled: do not deliver later
        $s=get_option('dap_settings',array()); $webhook=esc_url_raw($s['firebase_v1_webhook_url']??''); if(!$webhook)return false;
        $secret=(string)($s['firebase_v1_webhook_secret']??'');
        $body=array('token'=>$token,'notification'=>array('title'=>(string)($payload['title']??'Delicat Assistance'),'body'=>(string)($payload['body']??'')),'data'=>array('conversation_id'=>(string)$conversation_id,'message_id'=>(string)($payload['message_id']??0),'screen'=>'chat','deep_link'=>'delicat://assistant/chat?conversation_id='.(string)$conversation_id,'config_revision'=>self::config_revision()));
        $json=wp_json_encode($body);$headers=array('Content-Type'=>'application/json');if($secret)$headers['X-Delicat-Signature']=hash_hmac('sha256',$json,$secret);
        if('https'!==wp_parse_url($webhook,PHP_URL_SCHEME))return false;
        $res=wp_safe_remote_post($webhook,array('timeout'=>10,'redirection'=>0,'headers'=>$headers,'body'=>$json));
        return !is_wp_error($res)&&wp_remote_retrieve_response_code($res)>=200&&wp_remote_retrieve_response_code($res)<300;
    }
}
