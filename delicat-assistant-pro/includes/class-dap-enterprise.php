<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Enterprise operations, account linking, preferences and WooCommerce actions. */
class DSA2_Enterprise {
    const LINK_MAX_ATTEMPTS_PER_HOUR = 10;
    const LINK_MAX_ATTEMPTS_PER_CODE = 5;
    const LINK_CODE_TTL = 600;
    const LINK_CODE_LENGTH = 8;
    const NS = 'delicat-assistant/v2';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 30 );
        add_action( 'admin_post_dap_agent_ticket_action', array( __CLASS__, 'ticket_action' ) );
        add_action( 'dap_enterprise_push_retry', array( __CLASS__, 'process_push_queue' ) );
        if ( ! wp_next_scheduled( 'dap_enterprise_push_retry' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'dap_enterprise_push_retry' );
        }
    }

    public static function routes() {
        // Same app token as the legacy customer API: registered only while it is switched on (6.6.1).
        if ( ! class_exists( 'DSA2_Mobile_API', false ) || ! DSA2_Mobile_API::customer_api_enabled() ) return;
        register_rest_route( self::NS, '/auth/request-code', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'request_code'), 'permission_callback'=>array('DSA2_Mobile_API','allow') ) );
        register_rest_route( self::NS, '/auth/verify', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'verify_code'), 'permission_callback'=>array('DSA2_Mobile_API','allow') ) );
        register_rest_route( self::NS, '/account', array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'account'), 'permission_callback'=>array('DSA2_Mobile_API','allow') ) );
        register_rest_route( self::NS, '/preferences', array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'preferences'), 'permission_callback'=>array('DSA2_Mobile_API','allow') ) );
        register_rest_route( self::NS, '/preferences', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'save_preferences'), 'permission_callback'=>array('DSA2_Mobile_API','allow') ) );
        register_rest_route( self::NS, '/orders', array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'orders'), 'permission_callback'=>array('DSA2_Mobile_API','allow') ) );
        register_rest_route( self::NS, '/orders/action', array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'order_action'), 'permission_callback'=>array('DSA2_Mobile_API','allow') ) );
        register_rest_route( self::NS, '/wallet/transactions', array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'wallet_transactions'), 'permission_callback'=>array('DSA2_Mobile_API','allow') ) );
        register_rest_route( self::NS, '/health', array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'health'), 'permission_callback'=>'__return_true' ) );
    }

    private static function code_limited( $name, $max, $window ) {
        $key='dap_'.sanitize_key($name); $d=get_transient($key);
        if( ! is_array($d) || ! isset($d['t']) || ( time() - (int)$d['t'] ) > $window ) $d=array('c'=>0,'t'=>time());
        if( (int)$d['c'] >= $max ) return true;
        $d['c']=(int)$d['c']+1; set_transient($key,$d,$window); return false;
    }

    private static function session( $request ) { return $request->get_param('_mobile_session'); }
    private static function session_user_id( $session ) {
        global $wpdb;
        $uid = (int) $wpdb->get_var( $wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}dap_conversations WHERE id=%d", (int)$session->conversation_id ) );
        return $uid;
    }
    private static function require_user( $request ) {
        $uid = self::session_user_id( self::session($request) );
        return $uid > 0 ? $uid : new WP_Error('account_required','Link your Delicat account first',array('status'=>401));
    }

    public static function request_code( $r ) {
        $s = self::session($r); $email = sanitize_email((string)$r->get_param('email'));
        if ( ! is_email($email) ) return new WP_Error('bad_email','Invalid email address',array('status'=>400));
        // Unlimited before: one app session could send our server's mail to any address it
        // liked, as fast as it could POST. Capped per conversation and per address.
        $remote=sanitize_text_field((string)($_SERVER['REMOTE_ADDR']??''));
        if ( self::code_limited('code_conv_'.(int)$s->conversation_id, 5, HOUR_IN_SECONDS)
          || self::code_limited('code_mail_'.md5(strtolower($email)), 5, HOUR_IN_SECONDS)
          || self::code_limited('code_ip_'.md5($remote), 30, HOUR_IN_SECONDS) ) {
            return new WP_Error('rate_limited','Too many verification requests',array('status'=>429));
        }
        $user = get_user_by('email',$email);
        // Do not reveal whether an account exists, nor whether it may be linked.
        if ( $user && true === self::link_allowed( (int) $user->ID ) && self::link_lock( (int) $user->ID ) ) {
            $uid  = (int) $user->ID;
            $code = str_pad( (string) wp_rand( 0, 99999999 ), self::LINK_CODE_LENGTH, '0', STR_PAD_LEFT );
            try {
                // The code and its attempt counter belong to the account, not the conversation:
                // a new request cancels the previous code wherever it came from, so parallel
                // conversations cannot multiply the guesses.
                set_transient( self::link_key( $uid ), array(
                    'hash'            => wp_hash_password( $code ),
                    'conversation_id' => (int) $s->conversation_id,
                    'email'           => hash( 'sha256', strtolower( trim( (string) $user->user_email ) ) ),
                    'attempts'        => 0,
                    'expires'         => time() + self::LINK_CODE_TTL,
                ), self::LINK_CODE_TTL );
                set_transient( 'dap_link_conv_' . (int) $s->conversation_id, $uid, self::LINK_CODE_TTL );
                delete_transient( 'dap_link_' . (int) $s->conversation_id ); // per-conversation code of older releases
            } finally {
                self::link_unlock( $uid );
            }
            wp_mail($email,'Code de connexion Delicat','Votre code Delicat Assistance est : '.$code."\nIl expire dans 10 minutes.");
        }
        return rest_ensure_response(array('success'=>true,'message'=>'If the account exists, a verification code was sent.'));
    }

    public static function verify_code( $r ) {
        $s    = self::session( $r );
        $cid  = (int) $s->conversation_id;
        $code = (string) preg_replace( '/\D/', '', (string) $r->get_param( 'code' ) );
        $uid  = absint( get_transient( 'dap_link_conv_' . $cid ) );
        if ( ! $uid || strlen( $code ) !== self::LINK_CODE_LENGTH ) return self::invalid_code();

        // One pass under the account's lock: count the guess, check it, consume a correct code.
        // The old read-increment-write around the slow password check let parallel requests
        // share one count, and five codes per hour each came with five fresh attempts. Doing
        // the check inside the lock also means a correct code links once, and a newer code
        // requested in the meantime (same lock) has already replaced this record.
        if ( ! self::link_lock( $uid ) ) return new WP_Error( 'rate_limited', 'Too many verification requests', array( 'status' => 429 ) );
        try {
            $record = get_transient( self::link_key( $uid ) );
            if ( ! is_array( $record ) || (int) ( $record['conversation_id'] ?? 0 ) !== $cid || (int) ( $record['expires'] ?? 0 ) < time() ) {
                return self::invalid_code();
            }
            $window = get_transient( self::link_guess_key( $uid ) );
            if ( ! is_array( $window ) || ! isset( $window['t'] ) || ( time() - (int) $window['t'] ) > HOUR_IN_SECONDS ) {
                $window = array( 'c' => 0, 't' => time() );
            }
            if ( (int) ( $record['attempts'] ?? 0 ) >= self::LINK_MAX_ATTEMPTS_PER_CODE || (int) $window['c'] >= self::LINK_MAX_ATTEMPTS_PER_HOUR ) {
                delete_transient( self::link_key( $uid ) );
                return new WP_Error( 'locked', 'Too many attempts', array( 'status' => 429 ) );
            }
            $window['c'] = (int) $window['c'] + 1;
            set_transient( self::link_guess_key( $uid ), $window, HOUR_IN_SECONDS );
            $record['attempts'] = (int) ( $record['attempts'] ?? 0 ) + 1;
            set_transient( self::link_key( $uid ), $record, max( 1, (int) $record['expires'] - time() ) );
            if ( ! wp_check_password( $code, (string) ( $record['hash'] ?? '' ) ) ) return self::invalid_code();
            delete_transient( self::link_key( $uid ) );
            delete_transient( 'dap_link_conv_' . $cid );
        } finally {
            self::link_unlock( $uid );
        }

        // Bound to the address the code was sent to: a changed account e-mail voids it.
        $user = get_userdata( $uid );
        if ( ! $user || ! hash_equals( (string) ( $record['email'] ?? '' ), hash( 'sha256', strtolower( trim( (string) $user->user_email ) ) ) ) ) {
            return self::invalid_code();
        }
        $allowed = self::link_allowed( $uid );
        if ( is_wp_error( $allowed ) ) return $allowed;

        // Linking used to only stamp user_id on the app's own thread, so the customer kept
        // two separate conversations — one in the app, one on the website — and an agent
        // answering in either was invisible in the other. Fold them into a single thread.
        $conversation_id = DSA2_Data::link_conversation_to_user( $cid, $uid );
        if ( $conversation_id !== $cid ) {
            $s->conversation_id = $conversation_id;
            $r->set_param('_mobile_session',$s);
        }
        if(class_exists('DSA2_Production')) DSA2_Production::audit('account_linked',array('conversation_id'=>$conversation_id));
        self::notify_account_linked( $user );
        $out = self::account($r);
        $data_out = $out instanceof WP_REST_Response ? (array)$out->get_data() : array();
        $data_out['conversation_id'] = $conversation_id;
        return rest_ensure_response($data_out);
    }

    private static function link_key( $user_id ) { return 'dap_link_user_' . absint( $user_id ); }
    private static function link_guess_key( $user_id ) { return 'dap_link_guess_' . absint( $user_id ); }
    private static function invalid_code() { return new WP_Error( 'invalid_code', 'Invalid or expired code', array( 'status' => 400 ) ); }

    /**
     * Per-account MySQL lock around the code record: a transient read-modify-write is not
     * atomic between parallel requests, whatever stores the transients.
     */
    private static function link_lock( $user_id ) {
        global $wpdb;
        return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', self::link_lock_name( $user_id ) ) );
    }
    private static function link_unlock( $user_id ) {
        global $wpdb;
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::link_lock_name( $user_id ) ) );
    }
    private static function link_lock_name( $user_id ) {
        global $wpdb;
        return substr( 'dap_link_' . $wpdb->prefix . absint( $user_id ), 0, 64 );
    }

    /**
     * An account that may not sign in may not be linked either: blocked by the owner
     * ("Bloquer login"), or refused by Identity Pro's login guard (e-mail not verified,
     * approval pending).
     *
     * @return true|WP_Error
     */
    private static function link_allowed( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id || get_user_meta( $user_id, 'dr_banned_login', true ) ) {
            return new WP_Error( 'account_unavailable', 'Ce compte ne peut pas être relié à l’application.', array( 'status' => 403 ) );
        }
        if ( class_exists( 'DIP_Account_Sync' ) && is_callable( array( 'DIP_Account_Sync', 'login_guard' ) ) ) {
            $guard = DIP_Account_Sync::login_guard( $user_id );
            if ( is_wp_error( $guard ) ) {
                return new WP_Error( 'account_unavailable', 'Ce compte ne peut pas être relié à l’application pour le moment.', array( 'status' => 403 ) );
            }
        }
        return true;
    }

    /** Tells the account owner a device was linked, so a link they did not make is noticed. */
    private static function notify_account_linked( $user ) {
        if ( ! ( $user instanceof WP_User ) || ! is_email( $user->user_email ) ) return;
        $message = sprintf(
            "Bonjour %s,\n\nUn appareil vient d’être relié à votre compte dans l’application Delicat Assistance, le %s.\n\nSi c’est vous, vous n’avez rien à faire. Sinon, changez votre mot de passe avec « Mot de passe oublié » et contactez notre équipe.",
            sanitize_text_field( (string) $user->display_name ),
            wp_date( 'd/m/Y H:i' )
        );
        wp_mail( $user->user_email, 'Nouvel appareil relié à votre compte Delicat', $message );
    }

    public static function account( $r ) {
        $uid=self::session_user_id(self::session($r));
        if(!$uid) return rest_ensure_response(array('linked'=>false));
        $u=get_userdata($uid); if(!$u) return rest_ensure_response(array('linked'=>false));
        return rest_ensure_response(array('linked'=>true,'id'=>$uid,'name'=>sanitize_text_field($u->display_name),'email'=>sanitize_email($u->user_email),'avatar'=>esc_url_raw(get_avatar_url($uid,array('size'=>192)))));
    }

    public static function preferences( $r ) {
        $s=self::session($r); $prefs=get_option('dap_mobile_prefs_'.$s->installation_id,array());
        $lang=in_array((string)$s->language,array('fr','ht','en'),true)?(string)$s->language:'fr';
        return rest_ensure_response(wp_parse_args($prefs,array('messages'=>true,'tickets'=>true,'orders'=>true,'wallet'=>true,'promotions'=>false,'quiet_start'=>'22:00','quiet_end'=>'07:00','language'=>$lang)));
    }
    public static function save_preferences( $r ) {
        $s=self::session($r); $allowed=array('messages','tickets','orders','wallet','promotions');
        $session_lang=in_array((string)$s->language,array('fr','ht','en'),true)?(string)$s->language:'fr';
        $prefs=wp_parse_args(get_option('dap_mobile_prefs_'.$s->installation_id,array()),array('messages'=>true,'tickets'=>true,'orders'=>true,'wallet'=>true,'promotions'=>false,'quiet_start'=>'22:00','quiet_end'=>'07:00','language'=>$session_lang));
        foreach($allowed as $k)if(null!==$r->get_param($k))$prefs[$k]=rest_sanitize_boolean($r->get_param($k));
        $start=$r->get_param('quiet_start');$end=$r->get_param('quiet_end');
        if(null!==$start&&preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)$start))$prefs['quiet_start']=(string)$start;
        if(null!==$end&&preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)$end))$prefs['quiet_end']=(string)$end;
        $lang=sanitize_key((string)$r->get_param('language')); if(in_array($lang,array('fr','ht','en'),true))$prefs['language']=$lang;
        update_option('dap_mobile_prefs_'.$s->installation_id,$prefs,false);
        global $wpdb;
        $wpdb->update($wpdb->prefix.'dap_mobile_sessions',array('language'=>$prefs['language']),array('installation_id'=>(string)$s->installation_id),array('%s'),array('%s'));
        $wpdb->update($wpdb->prefix.'dap_conversations',array('language'=>$prefs['language']),array('id'=>(int)$s->conversation_id),array('%s'),array('%d'));
        return rest_ensure_response($prefs);
    }

    public static function orders( $r ) {
        $uid=self::require_user($r); if(is_wp_error($uid))return $uid;
        if(!function_exists('wc_get_orders'))return rest_ensure_response(array('orders'=>array()));
        $orders=wc_get_orders(array('customer_id'=>$uid,'limit'=>20,'orderby'=>'date','order'=>'DESC'));
        $out=array(); foreach($orders as $o){$out[]=array('id'=>$o->get_id(),'number'=>sanitize_text_field($o->get_order_number()),'status'=>sanitize_key($o->get_status()),'total'=>wp_strip_all_tags($o->get_formatted_order_total()),'date'=>$o->get_date_created()?$o->get_date_created()->date('c'):'','can_cancel'=>$o->has_status(self::cancellable_statuses($o)),'payment_method'=>sanitize_text_field($o->get_payment_method_title()));}
        return rest_ensure_response(array('orders'=>$out));
    }

    private static function cancellable_statuses( $order ) {
        $statuses = apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), $order );
        return array_values( array_intersect( array_map( 'sanitize_key', (array) $statuses ), array( 'pending', 'failed', 'on-hold', 'processing' ) ) );
    }

    public static function order_action( $r ) {
        $uid=self::require_user($r); if(is_wp_error($uid))return $uid;
        $session=self::session($r); if(self::code_limited('order_action_'.(int)$session->conversation_id,10,HOUR_IN_SECONDS))return new WP_Error('rate_limited','Too many order requests',array('status'=>429));
        if(!function_exists('wc_get_order'))return new WP_Error('woocommerce_required','WooCommerce unavailable',array('status'=>503));
        $id=absint($r->get_param('order_id')); $action=sanitize_key((string)$r->get_param('action')); $order=wc_get_order($id);
        if(!$order||(int)$order->get_customer_id()!==$uid)return new WP_Error('forbidden','Order unavailable',array('status'=>403));
        if($action==='cancel_request'&&$order->has_status(self::cancellable_statuses($order))){$order->update_status('cancelled','Cancelled by customer from Delicat Assistance app'); return rest_ensure_response(array('success'=>true,'status'=>$order->get_status()));}
        if($action==='support_ticket'){
            $ticket=wp_insert_post(array('post_type'=>'dap_ticket','post_status'=>'publish','post_title'=>'Assistance commande #'.$order->get_order_number(),'post_content'=>'Demande créée depuis l’application pour la commande #'.$order->get_order_number()),true);
            if(is_wp_error($ticket))return $ticket; $s=self::session($r); update_post_meta($ticket,'dap_conversation_id',(int)$s->conversation_id); update_post_meta($ticket,'dap_order_id',$id); update_post_meta($ticket,'dap_ticket_status','open'); update_post_meta($ticket,'dap_ticket_priority','normal');
            $lang = in_array( (string) $s->language, array( 'fr', 'ht', 'en' ), true ) ? (string) $s->language : 'fr';
            $label = 'en' === $lang ? 'Order support' : ( 'ht' === $lang ? 'Sipò pou kòmand' : 'Assistance commande' );
            DSA2_Data::add_message((int)$s->conversation_id,'customer','Ticket #'.$ticket.' — '.$label.' #'.$order->get_order_number(),'system',array('ticket_id'=>(int)$ticket,'order_id'=>$id,'priority'=>'normal'),'agent');
            DSA2_Data::request_agent((int)$s->conversation_id);
            do_action('dsa2_mobile_ticket_created',(int)$ticket,(int)$s->conversation_id,'Assistance commande #'.$order->get_order_number(),'normal');
            return rest_ensure_response(array('success'=>true,'ticket_id'=>(int)$ticket));
        }
        return new WP_Error('unsupported_action','Action unavailable for this order',array('status'=>400));
    }

    public static function wallet_transactions( $r ) {
        $uid=self::require_user($r); if(is_wp_error($uid))return $uid; $out=array();
        if(function_exists('woo_wallet')){
            global $wpdb; $table=$wpdb->prefix.'woo_wallet_transactions';
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table){$rows=$wpdb->get_results($wpdb->prepare("SELECT transaction_id,type,amount,details,date FROM $table WHERE user_id=%d ORDER BY transaction_id DESC LIMIT 50",$uid)); foreach((array)$rows as $row)$out[]=array('id'=>(int)$row->transaction_id,'type'=>sanitize_key($row->type),'amount'=>(string)$row->amount,'details'=>sanitize_text_field($row->details),'date'=>(string)$row->date);}
        }
        return rest_ensure_response(array('transactions'=>$out));
    }

    public static function health() { return rest_ensure_response(array('ok'=>true,'time'=>gmdate('c'))); }

    public static function admin_menu() {
        add_submenu_page('delicat-assistant','Agent Workspace','Tickets',DSA2_Install::CAP_SUPPORT,'dap-agent-workspace',array(__CLASS__,'workspace'));
    }
    public static function workspace() {
        if(!current_user_can(DSA2_Install::CAP_SUPPORT))wp_die('Forbidden');
        $status=sanitize_key(wp_unslash($_GET['ticket_status']??'')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if(!in_array($status,array('open','closed'),true))$status='';
        $search=trim(sanitize_text_field(wp_unslash($_GET['s']??''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $args=array('post_type'=>'dap_ticket','post_status'=>array('publish','draft','pending'),'posts_per_page'=>75,'orderby'=>'modified','order'=>'DESC');
        if($status)$args['meta_query']=array(array('key'=>'dap_ticket_status','value'=>$status));
        if($search!=='')$args['s']=$search;
        $tickets=get_posts($args);
        $agents=get_users(array('capability'=>DSA2_Install::CAP_SUPPORT,'orderby'=>'display_name','order'=>'ASC'));
        ?>
        <div class="wrap dap-admin dap-ticket-workspace">
            <header class="dap-admin-hero"><div><span class="dap-eyebrow">SUPPORT STRUCTURÉ</span><h1>Tickets clients</h1><p>Assignez, priorisez et suivez les demandes créées depuis l’application.</p></div><div class="dap-hero-actions"><a class="dap-secondary-link" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant')); ?>">← Retour à l’inbox</a><span class="dap-version"><?php echo count($tickets); ?> ticket(s)</span></div></header>
            <form method="get" class="dap-ticket-toolbar"><input type="hidden" name="page" value="dap-agent-workspace"><label><span>État</span><select name="ticket_status"><option value="">Tous</option><option value="open" <?php selected($status,'open'); ?>>Ouverts</option><option value="closed" <?php selected($status,'closed'); ?>>Fermés</option></select></label><label class="dap-ticket-search"><span>Recherche</span><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Sujet ou numéro"></label><button type="submit">Filtrer</button></form>
            <?php if(isset($_GET['updated'])):?><div class="notice notice-success is-dismissible"><p>Ticket mis à jour.</p></div><?php endif; ?>
            <div class="dap-ticket-grid">
            <?php if(!$tickets):?><div class="dap-ticket-empty"><span>✓</span><h2>Aucun ticket</h2><p>Aucune demande ne correspond à ce filtre.</p></div><?php endif; ?>
            <?php foreach($tickets as $ticket):
                $ticket_status=sanitize_key(get_post_meta($ticket->ID,'dap_ticket_status',true)?:'open');
                $priority=sanitize_key(get_post_meta($ticket->ID,'dap_ticket_priority',true)?:'normal');
                $assigned=(int)get_post_meta($ticket->ID,'dap_ticket_assigned_to',true);
                $conversation_id=(int)get_post_meta($ticket->ID,'dap_conversation_id',true);
                $order_id=(int)get_post_meta($ticket->ID,'dap_order_id',true);
                $attachments=get_post_meta($ticket->ID,'dap_ticket_attachments',true);
                $notes=get_post_meta($ticket->ID,'dap_internal_notes',true);if(!is_array($notes))$notes=array();$last_note=$notes?end($notes):null;
                $rating=(int)get_post_meta($ticket->ID,'dap_ticket_rating',true);
            ?>
                <article class="dap-ticket-card is-<?php echo esc_attr($ticket_status); ?>">
                    <header><div><span class="dap-ticket-number">TICKET #<?php echo (int)$ticket->ID; ?></span><h2><?php echo esc_html($ticket->post_title); ?></h2></div><span class="dap-ticket-state is-<?php echo esc_attr($ticket_status); ?>"><?php echo 'closed'===$ticket_status?'Fermé':'Ouvert'; ?></span></header>
                    <p class="dap-ticket-message"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($ticket->post_content),32,'…')); ?></p>
                    <div class="dap-ticket-meta"><span>Mis à jour <?php echo esc_html(human_time_diff(get_post_modified_time('U',true,$ticket),current_time('timestamp',true))); ?></span><?php if($conversation_id):?><a href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant&conversation='.$conversation_id)); ?>">Conversation #<?php echo (int)$conversation_id; ?></a><?php endif;?><?php if($order_id):?><span>Commande #<?php echo (int)$order_id; ?></span><?php endif;?><span><?php echo is_array($attachments)?count($attachments):0; ?> pièce(s)</span><?php if($rating):?><span><?php echo str_repeat('★',min(5,$rating)); ?></span><?php endif;?></div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dap-ticket-form"><input type="hidden" name="action" value="dap_agent_ticket_action"><input type="hidden" name="ticket_id" value="<?php echo (int)$ticket->ID; ?>"><?php wp_nonce_field('dap_agent_ticket_action'); ?>
                        <label><span>État</span><select name="status"><option value="open" <?php selected($ticket_status,'open'); ?>>Ouvert</option><option value="closed" <?php selected($ticket_status,'closed'); ?>>Fermé</option></select></label>
                        <label><span>Priorité</span><select name="priority"><option value="low" <?php selected($priority,'low'); ?>>Basse</option><option value="normal" <?php selected($priority,'normal'); ?>>Normale</option><option value="high" <?php selected($priority,'high'); ?>>Haute</option><option value="urgent" <?php selected($priority,'urgent'); ?>>Urgente</option></select></label>
                        <label class="dap-ticket-assignee"><span>Agent</span><select name="assigned_to"><option value="0">Non assigné</option><?php foreach($agents as $agent):?><option value="<?php echo (int)$agent->ID; ?>" <?php selected($assigned,(int)$agent->ID); ?>><?php echo esc_html($agent->display_name); ?></option><?php endforeach;?></select></label>
                        <label class="dap-ticket-note"><span>Note interne (jamais visible par le client)</span><textarea name="internal_note" rows="2" maxlength="3000" placeholder="Ajouter un contexte pour l’équipe…"></textarea></label>
                        <?php if(is_array($last_note)):?><div class="dap-ticket-last-note"><strong>Dernière note</strong><p><?php echo esc_html(wp_trim_words((string)($last_note['note']??''),22,'…')); ?></p></div><?php endif;?>
                        <footer><a href="<?php echo esc_url(get_edit_post_link($ticket->ID)); ?>">Ouvrir le contenu ↗</a><button type="submit">Enregistrer</button></footer>
                    </form>
                </article>
            <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    public static function ticket_action() {
        if ( ! current_user_can( DSA2_Install::CAP_SUPPORT ) ) wp_die( 'Forbidden' );
        check_admin_referer( 'dap_agent_ticket_action' );
        $id = absint( $_POST['ticket_id'] ?? 0 );
        if ( 'dap_ticket' !== get_post_type( $id ) ) wp_die( 'Invalid ticket' );

        $old_status = sanitize_key( get_post_meta( $id, 'dap_ticket_status', true ) ?: 'open' );
        $new_status = $old_status;
        if ( isset( $_POST['assigned_to'] ) ) {
            $assigned = absint( $_POST['assigned_to'] );
            $user = $assigned ? get_userdata( $assigned ) : false;
            if ( ! $assigned || ( $user && user_can( $user, DSA2_Install::CAP_SUPPORT ) ) ) {
                update_post_meta( $id, 'dap_ticket_assigned_to', $assigned );
            }
        }
        if ( isset( $_POST['status'] ) ) {
            $status = sanitize_key( wp_unslash( $_POST['status'] ) );
            if ( in_array( $status, array( 'open', 'closed' ), true ) ) {
                $new_status = $status;
                update_post_meta( $id, 'dap_ticket_status', $status );
            }
        }
        if ( isset( $_POST['priority'] ) ) {
            $priority = sanitize_key( wp_unslash( $_POST['priority'] ) );
            if ( in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ) {
                update_post_meta( $id, 'dap_ticket_priority', $priority );
            }
        }
        if ( ! empty( $_POST['internal_note'] ) ) {
            $notes = (array) get_post_meta( $id, 'dap_internal_notes', true );
            $note = DSA2_Compat::substr( sanitize_textarea_field( wp_unslash( $_POST['internal_note'] ) ), 0, 3000 );
            if ( '' !== $note ) {
                $notes[] = array( 'user_id'=>get_current_user_id(), 'note'=>$note, 'time'=>current_time('mysql') );
                update_post_meta( $id, 'dap_internal_notes', array_slice( $notes, -200 ) );
            }
        }
        wp_update_post( array( 'ID'=>$id ) );

        if ( $new_status !== $old_status ) {
            $cid = (int) get_post_meta( $id, 'dap_conversation_id', true );
            if ( $cid ) {
                global $wpdb;
                $lang = (string) $wpdb->get_var( $wpdb->prepare( "SELECT language FROM {$wpdb->prefix}dap_conversations WHERE id=%d", $cid ) );
                if ( ! in_array( $lang, array( 'fr', 'en', 'ht' ), true ) ) $lang = 'fr';
                if ( 'closed' === $new_status ) {
                    $text = 'en' === $lang ? "Ticket #$id closed by the team." : ( 'ht' === $lang ? "Ekip la fèmen tikè #$id." : "Ticket #$id fermé par l’équipe." );
                } else {
                    $text = 'en' === $lang ? "Ticket #$id reopened by the team." : ( 'ht' === $lang ? "Ekip la relouvri tikè #$id." : "Ticket #$id rouvert par l’équipe." );
                }
                DSA2_Data::add_message( $cid, 'agent', $text, 'text', array( 'ticket_id'=>$id, 'ticket_status'=>$new_status ), 'agent' );
                if ( 'open' === $new_status ) DSA2_Data::request_agent( $cid );
            }
        }
        if ( class_exists( 'DSA2_Production' ) ) DSA2_Production::audit( 'ticket_updated', array( 'ticket_id'=>$id, 'status'=>$new_status ) );
        $return = wp_get_referer() ?: admin_url( 'admin.php?page=dap-agent-workspace' );
        wp_safe_redirect( add_query_arg( 'updated', '1', $return ) );
        exit;
    }

    public static function queue_push( $payload ) {$queue=(array)get_option('dap_push_retry_queue',array());$queue[]=array('payload'=>$payload,'attempts'=>0,'next'=>time());update_option('dap_push_retry_queue',array_slice($queue,-500),false);}
    public static function process_push_queue() {
        $queue = (array) get_option( 'dap_push_retry_queue', array() );
        $left = array();
        foreach ( $queue as $item ) {
            if ( (int) ( $item['next'] ?? 0 ) > time() ) { $left[] = $item; continue; }
            $item['attempts'] = (int) ( $item['attempts'] ?? 0 ) + 1;
            $result = class_exists( 'DSA2_Mobile_API' ) ? DSA2_Mobile_API::retry_push_payload( (array) ( $item['payload'] ?? array() ) ) : false;
            if ( false === $result && $item['attempts'] < 5 ) {
                $item['next'] = time() + min( HOUR_IN_SECONDS, 60 * ( 2 ** $item['attempts'] ) );
                $left[] = $item;
                continue;
            }
            if ( class_exists( 'DSA2_Production' ) ) {
                $event = 'dropped' === $result ? 'push_retry_dropped' : ( true === $result ? 'push_retry_sent' : 'push_retry_exhausted' );
                DSA2_Production::audit( $event, array( 'attempts'=>$item['attempts'] ) );
            }
        }
        update_option( 'dap_push_retry_queue', $left, false );
    }
}
