<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Focused support inbox and configuration screens. */
class DSA2_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_post_dap_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_dap_reply', array( __CLASS__, 'reply' ) );
        add_action( 'admin_post_dap_update_conversation', array( __CLASS__, 'update_conversation' ) );
        add_action( 'admin_post_dap_sync_faq', array( __CLASS__, 'sync_faq' ) );
        add_action( 'admin_post_dap_export_conversation', array( __CLASS__, 'export_conversation' ) );
        add_action( 'admin_post_dap_delete_conversation', array( __CLASS__, 'delete_conversation' ) );
        add_action( 'wp_ajax_dsa2_admin_thread', array( __CLASS__, 'ajax_thread' ) );
        add_action( 'wp_ajax_dsa2_admin_reply', array( __CLASS__, 'ajax_reply' ) );
        add_action( 'wp_ajax_dsa2_admin_update', array( __CLASS__, 'ajax_update' ) );
        add_action( 'wp_ajax_dsa2_admin_inbox_state', array( __CLASS__, 'ajax_inbox_state' ) );
        add_action( 'wp_ajax_dsa2_admin_inbox_items', array( __CLASS__, 'ajax_inbox_items' ) );
        add_action( 'wp_ajax_dsa2_admin_automation_context', array( __CLASS__, 'ajax_automation_context' ) );
        add_action( 'wp_ajax_dsa2_admin_note', array( __CLASS__, 'ajax_note' ) );
        add_action( 'wp_ajax_dsa2_admin_find_customer', array( __CLASS__, 'ajax_find_customer' ) );
        add_action( 'wp_ajax_dsa2_admin_direct_message', array( __CLASS__, 'ajax_direct_message' ) );
        add_action( 'admin_notices', array( __CLASS__, 'update_notice' ) );
        add_action( 'admin_post_dap_dismiss_711_notice', array( __CLASS__, 'dismiss_update_notice' ) );
    }

    /** 7.1.1: tells the owner, once, what the security update changed in the settings. */
    public static function update_notice() {
        $state = (string) get_option( 'dap_711_app_notice', '' );
        if ( '' === $state || ! self::can_manage() ) return;
        $settings_url = admin_url( 'admin.php?page=delicat-assistant-settings' );
        $dismiss_url  = wp_nonce_url( admin_url( 'admin-post.php?action=dap_dismiss_711_notice' ), 'dap_dismiss_711_notice' );
        $app = 'kept' === $state
            ? 'L’application Android du personnel reste activée (vous l’aviez activée). Désormais : sessions de 7 jours au plus, déconnexion à chaque changement de mot de passe ou de rôle, et vérification en deux étapes exigée (case « Exiger la 2FA pour l’application »). Les membres de l’équipe sans 2FA doivent l’activer sur leur compte, et l’application doit envoyer le code ; sinon, décochez cette case.'
            : 'L’application Android du personnel est maintenant désactivée par défaut (sécurité). Si votre équipe l’utilise, activez-la dans Paramètres → « Application Android administrateur ». Elle exige la vérification en deux étapes et des sessions de 7 jours au plus.';
        echo '<div class="notice notice-warning"><p><strong>Delicat Assistant 7.1.1 — sécurité.</strong> ' . esc_html( $app ) . '</p>';
        echo '<p>' . esc_html( 'Alertes e-mail de l’équipe : un e-mail au plus toutes les 2 minutes par conversation, 30 par heure au maximum, puis un récapitulatif. Un visiteur non connecté doit laisser son nom et un numéro ou un e-mail avant d’écrire à l’équipe.' ) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url( $settings_url ) . '">Ouvrir les paramètres</a> <a class="button" href="' . esc_url( $dismiss_url ) . '">J’ai compris</a></p></div>';
    }

    public static function dismiss_update_notice() {
        self::require_manager();
        check_admin_referer( 'dap_dismiss_711_notice' );
        delete_option( 'dap_711_app_notice' );
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=delicat-assistant-settings' ) );
        exit;
    }

    public static function menu() {
        $cap = DSA2_Install::CAP_SUPPORT;
        add_menu_page( 'Delicat Assistant', 'Delicat Assistant', $cap, 'delicat-assistant', array( __CLASS__, 'page' ), 'dashicons-format-chat', 56 );
        add_submenu_page( 'delicat-assistant', 'Boîte de réception', 'Boîte de réception', $cap, 'delicat-assistant', array( __CLASS__, 'page' ) );
        add_submenu_page( 'delicat-assistant', 'Paramètres', 'Paramètres', DSA2_Install::CAP_SETTINGS, 'delicat-assistant-settings', array( __CLASS__, 'settings_page' ) );
        add_submenu_page( 'delicat-assistant', 'Diagnostic', 'Diagnostic', DSA2_Install::CAP_SETTINGS, 'delicat-assistant-diagnostics', array( 'DSA2_Diagnostics', 'page' ) );
    }

    private static function can_support() {
        return current_user_can( DSA2_Install::CAP_SUPPORT ) || current_user_can( 'manage_woocommerce' );
    }

    private static function require_support( $json = false ) {
        if ( self::can_support() ) return;
        if ( $json ) wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 );
        wp_die( esc_html__( 'Accès refusé.', 'delicat-assistant' ) );
    }

    private static function require_manager() {
        if ( self::can_manage() ) return;
        wp_die( esc_html__( 'Accès refusé.', 'delicat-assistant' ) );
    }

    private static function can_manage() {
        return current_user_can( DSA2_Install::CAP_SETTINGS ) || current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
    }

    /** Support agents can work with unassigned threads and their own assignments only. */
    private static function access_sql( $alias = 'c' ) {
        if ( self::can_manage() ) return '';
        global $wpdb;
        $alias = preg_replace( '/[^a-z_]/', '', (string) $alias ) ?: 'c';
        return $wpdb->prepare( " AND ({$alias}.assigned_to=0 OR {$alias}.assigned_to=%d)", get_current_user_id() );
    }

    public static function assets( $hook ) {
        if ( false === strpos( (string) $hook, 'delicat-assistant' ) && false === strpos( (string) $hook, 'dap-' ) ) return;
        wp_enqueue_style( 'dap-admin', DSA2_URL . 'assets/css/admin.css', array(), DSA2_VERSION . '.' . filemtime( DSA2_DIR . 'assets/css/admin.css' ) );
        wp_enqueue_script( 'dap-admin', DSA2_URL . 'assets/js/admin.js', array(), DSA2_VERSION . '.' . filemtime( DSA2_DIR . 'assets/js/admin.js' ), true );
        wp_localize_script( 'dap-admin', 'DSA2_ADMIN', array(
            'ajax'        => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'dsa2_admin' ),
            'currentUser' => get_current_user_id(),
            'strings'     => array(
                'sending'       => 'Envoi…',
                'send'          => 'Envoyer',
                'failed'        => 'Impossible d’envoyer. Réessayez.',
                'newActivity'   => 'Nouvelle activité dans la boîte de réception.',
                'customerTyping'=> 'Le client écrit…',
                'searching'     => 'Recherche…',
                'noCustomer'    => 'Aucun client trouvé.',
                'composeSent'   => 'Message envoyé ✓',
            ),
        ) );
    }

    private static function defaults() {
        return array(
            'enabled'=>1,'auto_open'=>0,'whatsapp'=>'','show_whatsapp'=>1,'agent_status'=>'online','brand_name'=>'Delicat Assistant','primary_color'=>'#ff5a2b','business_hours'=>'',
            'launcher_label_enabled'=>1,'launcher_kicker'=>'Besoin d’aide ?','launcher_message'=>'Discutez avec nous !',
            'launcher_popup_delay'=>10,'launcher_popup_interval'=>75,'launcher_popup_visible'=>6,'launcher_popup_max'=>3,'launcher_suppress_checkout'=>1,
            'faq_text'=>'','products_enabled'=>1,'recommend_enabled'=>1,'product_context_enabled'=>1,'order_support_enabled'=>1,'runtime_load'=>'demand','launcher_side'=>'right','launcher_bottom'=>16,'guide_images'=>'','training_enabled'=>1,'smalltalk_enabled'=>1,'quick_replies_enabled'=>1,'quick_groups_off'=>'',
            'notify_enabled'=>1,'notify_email'=>get_option('admin_email'),'notify_every_message'=>1,'notify_staff'=>1,'notify_throttle'=>120,'notify_hourly_cap'=>30,'order_updates_in_chat'=>0,'agent_eta_online'=>'5–15 min','agent_eta_away'=>'30–60 min','moncash_number'=>'','natcash_number'=>'','min_recharge'=>'','delivery_time'=>'','guides_text'=>'',
            'webhook_url'=>'','webhook_secret'=>'','firebase_v1_webhook_url'=>'','firebase_v1_webhook_secret'=>'','retention_days'=>90,'open_hour'=>8,'close_hour'=>20,'url_wallet'=>'','url_redeem'=>'','url_rewards'=>'','url_shop'=>'','canned_replies'=>'','font_scale'=>100,'force_reduced_motion'=>0,
            'admin_app_enabled'=>0,'admin_app_require_2fa'=>1,'admin_push_enabled'=>1,'admin_push_preview'=>0,'admin_session_days'=>7,'image_retention_days'=>0,
        );
    }

    public static function save_settings() {
        self::require_manager();
        check_admin_referer( 'dap_save_settings' );
        $existing = (array) get_option( 'dap_settings', array() );
        $agent_status = sanitize_key( wp_unslash( $_POST['agent_status'] ?? 'online' ) );
        if ( ! in_array( $agent_status, array( 'online', 'away', 'offline' ), true ) ) $agent_status = 'online';
        $runtime = sanitize_key( wp_unslash( $_POST['runtime_load'] ?? 'demand' ) );
        if ( ! in_array( $runtime, array( 'auto', 'demand', 'eager' ), true ) ) $runtime = 'auto';
        $secret = sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ?? '' ) );

        $new = array(
            'enabled'=>!empty($_POST['enabled'])?1:0,
            'auto_open'=>!empty($_POST['auto_open'])?1:0,
            'show_whatsapp'=>!empty($_POST['show_whatsapp'])?1:0,
            'whatsapp'=>preg_replace('/\D+/', '', (string)wp_unslash($_POST['whatsapp']??'')),
            'agent_status'=>$agent_status,
            'brand_name'=>trim(sanitize_text_field(wp_unslash($_POST['brand_name']??'')))?:'Delicat Assistant',
            'primary_color'=>sanitize_hex_color(wp_unslash($_POST['primary_color']??'#ff5a2b'))?:'#ff5a2b',
            'business_hours'=>sanitize_text_field(wp_unslash($_POST['business_hours']??'')),
            'faq_text'=>sanitize_textarea_field(wp_unslash($_POST['faq_text']??'')),
            'products_enabled'=>!empty($_POST['products_enabled'])?1:0,
            'recommend_enabled'=>!empty($_POST['recommend_enabled'])?1:0,
            'runtime_load'=>$runtime,
            'launcher_side'=>('left'===($_POST['launcher_side']??'right'))?'left':'right',
            'launcher_bottom'=>max(0,min(400,absint($_POST['launcher_bottom']??16))),
            'launcher_label_enabled'=>!empty($_POST['launcher_label_enabled'])?1:0,
            'launcher_kicker'=>trim(sanitize_text_field(wp_unslash($_POST['launcher_kicker']??'')))?:'Besoin d’aide ?',
            'launcher_message'=>trim(sanitize_text_field(wp_unslash($_POST['launcher_message']??'')))?:'Discutez avec nous !',
            'launcher_popup_delay'=>max(3,min(120,absint($_POST['launcher_popup_delay']??10))),
            'launcher_popup_interval'=>max(30,min(600,absint($_POST['launcher_popup_interval']??75))),
            'launcher_popup_visible'=>max(3,min(15,absint($_POST['launcher_popup_visible']??6))),
            'launcher_popup_max'=>max(0,min(10,absint($_POST['launcher_popup_max']??3))),
            'launcher_suppress_checkout'=>!empty($_POST['launcher_suppress_checkout'])?1:0,
            'product_context_enabled'=>!empty($_POST['product_context_enabled'])?1:0,
            'order_support_enabled'=>!empty($_POST['order_support_enabled'])?1:0,
            'font_scale'=>max(90,min(125,absint($_POST['font_scale']??100))),
            'force_reduced_motion'=>!empty($_POST['force_reduced_motion'])?1:0,
            'guide_images'=>sanitize_textarea_field(wp_unslash($_POST['guide_images']??'')),
            'training_enabled'=>!empty($_POST['training_enabled'])?1:0,
            'training_corpus_enabled'=>!empty($_POST['training_corpus_enabled'])?1:0, // 6.6.1
            'smalltalk_enabled'=>!empty($_POST['smalltalk_enabled'])?1:0,
            'quick_replies_enabled'=>!empty($_POST['quick_replies_enabled'])?1:0,
            'quick_groups_off'=>implode(',',array_map('sanitize_key',(array)($_POST['quick_groups_off']??array()))),
            'notify_enabled'=>!empty($_POST['notify_enabled'])?1:0,
            'notify_email'=>sanitize_email(wp_unslash($_POST['notify_email']??'')),
            'notify_every_message'=>!empty($_POST['notify_every_message'])?1:0,
            'notify_staff'=>!empty($_POST['notify_staff'])?1:0,
            'notify_throttle'=>max(0,min(900,absint($_POST['notify_throttle']??120))),
            'notify_hourly_cap'=>max(5,min(500,absint($_POST['notify_hourly_cap']??30))), // 7.1.1
            'order_updates_in_chat'=>!empty($_POST['order_updates_in_chat'])?1:0,
            'agent_eta_online'=>sanitize_text_field(wp_unslash($_POST['agent_eta_online']??'5–15 min')),
            'agent_eta_away'=>sanitize_text_field(wp_unslash($_POST['agent_eta_away']??'30–60 min')),
            'moncash_number'=>sanitize_text_field(wp_unslash($_POST['moncash_number']??'')),
            'natcash_number'=>sanitize_text_field(wp_unslash($_POST['natcash_number']??'')),
            'min_recharge'=>sanitize_text_field(wp_unslash($_POST['min_recharge']??'')),
            'delivery_time'=>sanitize_text_field(wp_unslash($_POST['delivery_time']??'')),
            'guides_text'=>sanitize_textarea_field(wp_unslash($_POST['guides_text']??'')),
            'canned_replies'=>sanitize_textarea_field(wp_unslash($_POST['canned_replies']??'')),
            'webhook_url'=>esc_url_raw(wp_unslash($_POST['webhook_url']??'')),
            'firebase_v1_webhook_url'=>esc_url_raw(wp_unslash($_POST['firebase_v1_webhook_url']??'')),
            'admin_app_enabled'=>!empty($_POST['admin_app_enabled'])?1:0,
            'admin_app_require_2fa'=>!empty($_POST['admin_app_require_2fa'])?1:0, // 7.1.1
            'admin_push_enabled'=>!empty($_POST['admin_push_enabled'])?1:0,
            'admin_push_preview'=>!empty($_POST['admin_push_preview'])?1:0,
            'admin_session_days'=>max(1,min(DSA2_STAFF_SESSION_MAX_DAYS,absint($_POST['admin_session_days']??DSA2_STAFF_SESSION_MAX_DAYS))), // 7.1.1: 7 days at most (was 90)
            'image_retention_days'=>max(0,min(3650,absint($_POST['image_retention_days']??0))), // 7.1.1
            'retention_days'=>max(0,min(3650,(int)($_POST['retention_days']??90))),
            'open_hour'=>min(23,max(0,(int)($_POST['open_hour']??8))),
            'close_hour'=>min(24,max(0,(int)($_POST['close_hour']??20))),
            'url_wallet'=>esc_url_raw(wp_unslash($_POST['url_wallet']??'')),
            'url_redeem'=>esc_url_raw(wp_unslash($_POST['url_redeem']??'')),
            'url_rewards'=>esc_url_raw(wp_unslash($_POST['url_rewards']??'')),
            'url_shop'=>esc_url_raw(wp_unslash($_POST['url_shop']??'')),
        );
        if ( ! empty( $_POST['clear_webhook_secret'] ) ) $new['webhook_secret'] = '';
        elseif ( '' !== $secret ) $new['webhook_secret'] = $secret;
        $firebase_secret = sanitize_text_field( wp_unslash( $_POST['firebase_v1_webhook_secret'] ?? '' ) );
        if ( ! empty( $_POST['clear_firebase_secret'] ) ) $new['firebase_v1_webhook_secret'] = '';
        elseif ( '' !== $firebase_secret ) $new['firebase_v1_webhook_secret'] = $firebase_secret;

        update_option( 'dap_settings', array_merge( $existing, $new ) );
        DSA2_Products::flush();
        DSA2_Install::purge_caches();
        wp_safe_redirect( admin_url( 'admin.php?page=delicat-assistant-settings&saved=1' ) );
        exit;
    }

    private static function conversation( $conversation_id, $agent_only = true ) {
        global $wpdb;
        $where = $agent_only ? ' AND last_agent_message_at IS NOT NULL' : '';
        $conversation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dap_conversations WHERE id=%d $where", absint( $conversation_id ) ) );
        if ( $conversation && ! self::can_manage() && (int) $conversation->assigned_to && (int) $conversation->assigned_to !== get_current_user_id() ) return null;
        return $conversation;
    }

    private static function save_reply( $conversation_id, $body ) {
        $conversation = self::conversation( $conversation_id );
        if ( ! $conversation || '' === $body ) return 0;
        $message_id = DSA2_Data::add_message( $conversation_id, 'agent', $body, 'text', array(), 'agent' );
        if ( ! $message_id ) return 0;
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}dap_conversations SET status='open', assigned_to=IF(assigned_to=0,%d,assigned_to) WHERE id=%d",
            get_current_user_id(),
            $conversation_id
        ) );
        return $message_id;
    }

    public static function reply() {
        self::require_support();
        check_admin_referer( 'dap_reply' );
        $cid = absint( $_POST['conversation_id'] ?? 0 );
        $body = trim( sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ) );
        if ( DSA2_Compat::length( $body ) <= 3000 ) self::save_reply( $cid, $body );
        wp_safe_redirect( admin_url( 'admin.php?page=delicat-assistant&conversation=' . $cid ) );
        exit;
    }

    public static function ajax_reply() {
        self::require_support( true );
        check_ajax_referer( 'dsa2_admin', 'nonce' );
        $cid = absint( $_POST['conversation_id'] ?? 0 );
        $body = trim( sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ) );
        if ( ! $cid || '' === $body || DSA2_Compat::length( $body ) > 3000 ) wp_send_json_error( array( 'message' => 'Message invalide.' ), 400 );
        $id = self::save_reply( $cid, $body );
        if ( ! $id ) wp_send_json_error( array( 'message' => 'Le message n’a pas pu être enregistré.' ), 500 );
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dap_messages WHERE id=%d", $id ) );
        wp_send_json_success( array( 'message' => self::message_payload( $row ), 'status' => 'open' ) );
    }

    private static function apply_conversation_update( $cid, $status, $priority, $assigned_to ) {
        $conversation = self::conversation( $cid );
        if ( ! $conversation ) return false;
        $update = array();
        if ( in_array( $status, array( 'open', 'waiting', 'resolved' ), true ) ) $update['status'] = $status;
        if ( in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ) $update['priority'] = $priority;

        if ( self::can_manage() ) {
            $agent = $assigned_to ? get_userdata( $assigned_to ) : false;
            if ( ! $assigned_to || ( $agent && user_can( $agent, DSA2_Install::CAP_SUPPORT ) ) ) $update['assigned_to'] = $assigned_to;
        } elseif ( 0 === (int) $conversation->assigned_to || get_current_user_id() === (int) $conversation->assigned_to ) {
            $update['assigned_to'] = get_current_user_id();
        }
        if ( ! $update ) return true;
        global $wpdb;
        $saved = false !== $wpdb->update( $wpdb->prefix . 'dap_conversations', $update, array( 'id' => $cid ) );
        if ( $saved && isset( $update['status'] ) && 'resolved' === $update['status'] && 'resolved' !== (string) $conversation->status ) {
            DSA2_Data::request_feedback( $conversation );
        }
        return $saved;
    }

    public static function update_conversation() {
        self::require_support();
        check_admin_referer( 'dap_update_conversation' );
        $cid = absint( $_POST['conversation_id'] ?? 0 );
        self::apply_conversation_update( $cid, sanitize_key( $_POST['status'] ?? '' ), sanitize_key( $_POST['priority'] ?? '' ), absint( $_POST['assigned_to'] ?? 0 ) );
        wp_safe_redirect( admin_url( 'admin.php?page=delicat-assistant&conversation=' . $cid ) );
        exit;
    }

    public static function ajax_update() {
        self::require_support( true );
        check_ajax_referer( 'dsa2_admin', 'nonce' );
        $cid = absint( $_POST['conversation_id'] ?? 0 );
        $ok = self::apply_conversation_update( $cid, sanitize_key( $_POST['status'] ?? '' ), sanitize_key( $_POST['priority'] ?? '' ), absint( $_POST['assigned_to'] ?? 0 ) );
        if ( ! $ok ) wp_send_json_error( array( 'message' => 'Mise à jour impossible.' ), 400 );
        $conversation = self::conversation( $cid );
        wp_send_json_success( array( 'status' => $conversation->status, 'priority' => $conversation->priority, 'assigned_to' => (int) $conversation->assigned_to ) );
    }

    private static function context( $conversation ) {
        $empty = array( 'balance'=>'—','orders'=>'0','spent'=>'—','points'=>0,'account_url'=>'','recent_orders'=>array() );
        if ( ! $conversation || ! $conversation->user_id ) return $empty;
        $uid = (int) $conversation->user_id;
        $balance = '—';
        if ( function_exists( 'woo_wallet' ) ) {
            try { $balance = wp_strip_all_tags( woo_wallet()->wallet->get_wallet_balance( $uid ) ); } catch ( Throwable $e ) {}
        }
        $orders = function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $uid ) : 0;
        $spent = function_exists( 'wc_get_customer_total_spent' ) && function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( (float) wc_get_customer_total_spent( $uid ) ) ) : '—';
        $points = 0;
        foreach ( array( 'dap_reward_points', '_delicat_reward_points', 'delicat_reward_points' ) as $key ) {
            $value = get_user_meta( $uid, $key, true );
            if ( '' !== $value && is_numeric( $value ) ) { $points = (int) $value; break; }
        }
        $recent_orders = array();
        if ( function_exists( 'wc_get_orders' ) ) {
            foreach ( (array) wc_get_orders( array( 'customer_id'=>$uid, 'limit'=>3, 'orderby'=>'date', 'order'=>'DESC' ) ) as $order ) {
                if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) continue;
                $recent_orders[] = array(
                    'number' => sanitize_text_field( $order->get_order_number() ),
                    'status' => sanitize_text_field( function_exists( 'wc_get_order_status_name' ) ? wp_strip_all_tags( wc_get_order_status_name( $order->get_status() ) ) : $order->get_status() ),
                    'total' => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
                    'payment' => sanitize_text_field( $order->get_payment_method_title() ),
                    'date' => $order->get_date_created() ? $order->get_date_created()->date( 'd/m/Y' ) : '',
                    'url' => esc_url_raw( method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . (int) $order->get_id() . '&action=edit' ) ),
                );
            }
        }
        return array( 'balance'=>$balance, 'orders'=>$orders, 'spent'=>$spent, 'points'=>$points, 'account_url'=>get_edit_user_link( $uid ), 'recent_orders'=>$recent_orders );
    }

    private static function csv_safe( $value ) {
        $value = (string) $value;
        return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
    }

    public static function export_conversation() {
        self::require_support();
        check_admin_referer( 'dap_export_conversation' );
        $cid = absint( $_GET['conversation_id'] ?? 0 );
        if ( ! self::conversation( $cid ) ) wp_die( 'Conversation introuvable.' );
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT sender,sender_id,body,message_type,created_at FROM {$wpdb->prefix}dap_messages WHERE conversation_id=%d AND channel='agent' ORDER BY id ASC", $cid ) );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=conversation-agent-' . $cid . '.csv' );
        $out = fopen( 'php://output', 'w' );
        fputs( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, array( 'Date', 'Expéditeur', 'Type', 'Message' ) );
        foreach ( (array) $rows as $row ) {
            $sender = $row->sender;
            if ( 'agent' === $sender && $row->sender_id ) {
                $agent = get_userdata( (int) $row->sender_id );
                if ( $agent ) $sender .= ' — ' . $agent->display_name;
            }
            $body = wp_strip_all_tags( html_entity_decode( $row->body, ENT_QUOTES, 'UTF-8' ) );
            fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), array( $row->created_at, $sender, $row->message_type, $body ) ) );
        }
        fclose( $out );
        exit;
    }

    public static function delete_conversation() {
        self::require_manager();
        check_admin_referer( 'dap_delete_conversation' );
        $cid = absint( $_POST['conversation_id'] ?? 0 );
        if ( $cid ) {
            global $wpdb;
            DSA2_Data::delete_conversation_images( array( $cid ) );
            $tickets = get_posts( array( 'post_type'=>'dap_ticket', 'post_status'=>'any', 'fields'=>'ids', 'posts_per_page'=>-1, 'meta_key'=>'dap_conversation_id', 'meta_value'=>$cid ) );
            foreach ( $tickets as $ticket_id ) wp_delete_post( $ticket_id, true );
            $wpdb->delete( $wpdb->prefix . 'dap_mobile_devices', array( 'conversation_id'=>$cid ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'dap_mobile_sessions', array( 'conversation_id'=>$cid ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'dap_messages', array( 'conversation_id'=>$cid ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'dap_conversations', array( 'id'=>$cid ), array( '%d' ) );
            delete_option( 'dap_internal_notes_' . $cid );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=delicat-assistant&deleted=1' ) );
        exit;
    }

    private static function message_payload( $message ) {
        if ( ! $message ) return array();
        $meta = $message->meta ? json_decode( $message->meta, true ) : array();
        if ( ! is_array( $meta ) ) $meta = array();
        $meta = DSA2_Data::public_meta( $meta, isset( $message->conversation_id ) ? (int) $message->conversation_id : 0 ); // 7.1.1: private images
        $sender_name = '';
        if ( 'agent' === $message->sender && ! empty( $message->sender_id ) ) {
            $agent = get_userdata( (int) $message->sender_id );
            if ( $agent ) $sender_name = $agent->display_name;
        }
        return array(
            'id'          => (int) $message->id,
            'sender'      => sanitize_key( $message->sender ),
            'sender_name' => sanitize_text_field( $sender_name ),
            'body'        => html_entity_decode( wp_strip_all_tags( (string) $message->body ), ENT_QUOTES, 'UTF-8' ),
            'type'        => sanitize_key( $message->message_type ),
            'meta'        => $meta,
            'time'        => mysql2date( 'd M · H:i', $message->created_at ),
        );
    }

    private static function render_message( $message, $customer_read_id = 0 ) {
        $payload = self::message_payload( $message );
        $sender = $payload['sender'];
        $label = 'customer' === $sender ? 'Client' : ( 'agent' === $sender ? ( $payload['sender_name'] ?: 'Agent Delicat' ) : 'Système Delicat' );
        $meta = $payload['meta'];
        $url = ! empty( $meta['url'] ) ? esc_url( $meta['url'] ) : '';
        $mime = sanitize_text_field( $meta['mime'] ?? '' );
        ?>
        <article class="dap-admin-msg <?php echo esc_attr( $sender ); ?>" data-message-id="<?php echo (int) $payload['id']; ?>">
            <header><strong><?php echo esc_html( $label ); ?></strong><time><?php echo esc_html( $payload['time'] ); ?></time></header>
            <?php if ( $url && ( 'image' === $payload['type'] || 0 === strpos( $mime, 'image/' ) ) ) : ?>
                <a class="dap-admin-attachment" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $url ); ?>" alt="Image envoyée par le client" loading="lazy"></a>
            <?php elseif ( $url ) : ?>
                <a class="dap-admin-file" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">↗ <?php echo esc_html( $meta['name'] ?? 'Ouvrir la pièce jointe' ); ?></a>
            <?php elseif ( 'text' === $payload['type'] ) : ?>
                <p><?php echo nl2br( esc_html( $payload['body'] ) ); ?></p>
            <?php else : ?>
                <p class="dap-system-copy"><?php echo esc_html( $meta['title'] ?? $payload['body'] ); ?></p>
            <?php endif; ?>
            <?php if ( 'agent' === $sender ) : $seen = (int) $payload['id'] <= (int) $customer_read_id; ?>
                <span class="dap-admin-delivery <?php echo $seen ? 'is-read' : 'is-delivered'; ?>" data-delivery-state="<?php echo $seen ? 'read' : 'delivered'; ?>"><?php echo $seen ? '✓✓ Lu par le client' : '✓ Envoyé'; ?></span>
            <?php endif; ?>
            <?php $order_context = isset($meta['order']) && is_array($meta['order']) ? $meta['order'] : array(); if($order_context): ?>
                <div class="dap-admin-context dap-admin-order-context"><strong>🧾 <?php echo esc_html($order_context['title']??'Commande'); ?></strong><span><?php echo esc_html(implode(' · ',array_filter(array($order_context['status']??'', $order_context['total']??'', $order_context['date']??'')))); ?></span><?php if(!empty($order_context['url'])):?><a href="<?php echo esc_url($order_context['url']); ?>" target="_blank" rel="noopener">Voir la commande ↗</a><?php endif; ?></div>
            <?php endif; ?>
            <?php $context = isset($meta['context']) && is_array($meta['context']) ? DSA2_Data::sanitize_context($meta['context']) : array(); if ( $context ) : ?>
                <div class="dap-admin-context" aria-label="Contexte de la page du client">
                    <strong>🛍️ <?php echo esc_html($context['title'] ?? 'Contexte client'); ?></strong>
                    <?php $selection=array_filter(array($context['plan']??'', $context['variation']??'', $context['price']??'')); if($selection): ?><span><?php echo esc_html(implode(' · ',$selection)); ?></span><?php endif; ?>
                    <small><?php echo !empty($context['player_id_field']) ? ( !empty($context['player_id_filled']) ? 'Player ID : champ rempli (valeur masquée)' : 'Player ID : champ vide' ) : 'Aucune donnée sensible transmise'; ?></small>
                    <?php if(!empty($context['url'])):?><a href="<?php echo esc_url($context['url']); ?>" target="_blank" rel="noopener">Voir la page ↗</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </article>
        <?php
    }

    public static function ajax_thread() {
        self::require_support( true );
        check_ajax_referer( 'dsa2_admin', 'nonce' );
        $cid = absint( $_POST['conversation_id'] ?? 0 );
        $after = absint( $_POST['after_id'] ?? 0 );
        $conversation = self::conversation( $cid );
        if ( ! $conversation ) wp_send_json_error( array( 'message'=>'Conversation introuvable.' ), 404 );
        if ( isset( $_POST['typing'] ) ) {
            $key = 'dap_agent_typing_' . $cid;
            if ( rest_sanitize_boolean( $_POST['typing'] ) ) set_transient( $key, 1, 15 );
            else delete_transient( $key );
        }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dap_messages WHERE conversation_id=%d AND channel='agent' AND id>%d ORDER BY id ASC LIMIT 100", $cid, $after ) );
        $last_customer = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$wpdb->prefix}dap_messages WHERE conversation_id=%d AND channel='agent' AND sender='customer'", $cid ) );
        if ( $last_customer ) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dap_conversations SET last_agent_read_id=GREATEST(last_agent_read_id,%d) WHERE id=%d", $last_customer, $cid ) );
        wp_send_json_success( array(
            'messages'        => array_map( array( __CLASS__, 'message_payload' ), (array) $rows ),
            'customer_typing' => (bool) get_transient( 'dap_customer_typing_' . $cid ),
            'customer_read_id' => (int) $conversation->last_customer_read_id,
            'agent_read_id' => $last_customer,
        ) );
    }

    /** Optional bot context gives the agent the handoff history without mixing both timelines. */
    public static function ajax_automation_context() {
        self::require_support( true );
        check_ajax_referer( 'dsa2_admin', 'nonce' );
        $cid = absint( $_POST['conversation_id'] ?? 0 );
        if ( ! self::conversation( $cid ) ) wp_send_json_error( array( 'message'=>'Conversation introuvable.' ), 404 );
        global $wpdb;
        $rows = array_reverse( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dap_messages WHERE conversation_id=%d AND channel='bot' ORDER BY id DESC LIMIT 12",
            $cid
        ) ) );
        $messages = array();
        foreach ( $rows as $row ) {
            $payload = self::message_payload( $row );
            $messages[] = array(
                'sender' => $payload['sender'],
                'body' => sanitize_text_field( DSA2_Compat::substr( $payload['body'], 0, 300 ) ),
                'time' => $payload['time'],
            );
        }
        wp_send_json_success( array( 'messages' => $messages, 'count' => count( $messages ) ) );
    }

    /** Private support notes are never stored in the customer-visible message channels. */
    public static function ajax_note() {
        self::require_support( true );
        check_ajax_referer( 'dsa2_admin', 'nonce' );
        $cid = absint( $_POST['conversation_id'] ?? 0 );
        if ( ! self::conversation( $cid ) ) wp_send_json_error( array( 'message'=>'Conversation introuvable.' ), 404 );
        $body = trim( sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ) );
        if ( '' === $body || DSA2_Compat::length( $body ) > 1200 ) wp_send_json_error( array( 'message'=>'Note interne invalide.' ), 400 );
        $user = wp_get_current_user();
        $note = array(
            'author' => sanitize_text_field( $user->display_name ),
            'body' => $body,
            'time' => current_time( 'd/m/Y H:i' ),
        );
        $option = 'dap_internal_notes_' . $cid;
        $notes = get_option( $option, array() );
        if ( ! is_array( $notes ) ) $notes = array();
        $notes[] = $note;
        $notes = array_slice( $notes, -30 );
        if ( false === get_option( $option, false ) ) add_option( $option, $notes, '', false );
        else update_option( $option, $notes, false );
        wp_send_json_success( array( 'note'=>$note, 'count'=>count( $notes ) ) );
    }

    private static function inbox_state() {
        global $wpdb;
        $conv = $wpdb->prefix . 'dap_conversations';
        $msg  = $wpdb->prefix . 'dap_messages';
        $access = self::access_sql( 'c' );
        $latest_access = self::access_sql( 'lc' );
        $site_now = $wpdb->prepare( '%s', current_time( 'mysql' ) );
        $row = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(c.status='waiting') AS waiting, SUM(c.status='open') AS open_count, SUM(c.priority IN ('high','urgent') AND c.status<>'resolved') AS urgent, SUM(c.status='waiting' AND TIMESTAMPDIFF(MINUTE,c.last_agent_message_at,$site_now)>=15) AS overdue, (SELECT MAX(lm.id) FROM $msg lm INNER JOIN $conv lc ON lc.id=lm.conversation_id WHERE lm.channel='agent' $latest_access) AS latest FROM $conv c WHERE c.last_agent_message_at IS NOT NULL $access" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $unread = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $msg m INNER JOIN $conv c ON c.id=m.conversation_id WHERE m.channel='agent' AND m.sender='customer' AND m.id>c.last_agent_read_id $access" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return array( 'total'=>(int)($row->total??0), 'waiting'=>(int)($row->waiting??0), 'open'=>(int)($row->open_count??0), 'urgent'=>(int)($row->urgent??0), 'overdue'=>(int)($row->overdue??0), 'unread'=>$unread, 'latest'=>(string)($row->latest??'') );
    }

    public static function ajax_inbox_state() {
        self::require_support( true );
        check_ajax_referer( 'dsa2_admin', 'nonce' );
        wp_send_json_success( self::inbox_state() );
    }

    /** Refresh just the visible inbox cards; never reload or discard an agent's draft. */
    public static function ajax_inbox_items() {
        self::require_support( true );
        check_ajax_referer( 'dsa2_admin', 'nonce' );
        global $wpdb;
        $conv = $wpdb->prefix . 'dap_conversations';
        $messages = $wpdb->prefix . 'dap_messages';
        $clauses = array( 'c.last_agent_message_at IS NOT NULL' );
        $params = array();
        $status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
        $queue = sanitize_key( wp_unslash( $_POST['queue'] ?? '' ) );
        $search = DSA2_Compat::substr( trim( sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ) ), 0, 100 );
        if ( in_array( $status, array( 'waiting', 'open', 'resolved' ), true ) ) {
            $clauses[] = 'c.status=%s';
            $params[] = $status;
        }
        if ( 'mine' === $queue ) {
            $clauses[] = 'c.assigned_to=%d';
            $params[] = get_current_user_id();
        } elseif ( 'unassigned' === $queue ) {
            $clauses[] = 'c.assigned_to=0';
        } elseif ( 'unread' === $queue ) {
            $clauses[] = "EXISTS(SELECT 1 FROM $messages qmsg WHERE qmsg.conversation_id=c.id AND qmsg.channel='agent' AND qmsg.sender='customer' AND qmsg.id>c.last_agent_read_id)";
        } elseif ( 'urgent' === $queue ) {
            $clauses[] = "c.priority IN ('high','urgent') AND c.status<>'resolved'";
        }
        if ( ! self::can_manage() ) { $clauses[] = '(c.assigned_to=0 OR c.assigned_to=%d)'; $params[] = get_current_user_id(); }
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $clauses[] = '(c.customer_name LIKE %s OR c.customer_email LIKE %s OR c.customer_phone LIKE %s OR CAST(c.id AS CHAR)=%s)';
            array_push( $params, $like, $like, $like, ltrim( $search, '#' ) );
        }
        $where = implode( ' AND ', $clauses );
        $sql = "SELECT c.*,
            (SELECT body FROM $messages lm WHERE lm.conversation_id=c.id AND lm.channel='agent' ORDER BY lm.id DESC LIMIT 1) AS last_body,
            (SELECT created_at FROM $messages cm WHERE cm.conversation_id=c.id AND cm.channel='agent' AND cm.sender='customer' ORDER BY cm.id DESC LIMIT 1) AS last_customer_at,
            (SELECT COUNT(*) FROM $messages um WHERE um.conversation_id=c.id AND um.channel='agent' AND um.sender='customer' AND um.id>c.last_agent_read_id) AS unread_agent
            FROM $conv c WHERE $where
            ORDER BY CASE c.status WHEN 'waiting' THEN 0 WHEN 'open' THEN 1 ELSE 2 END, CASE c.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END, c.last_agent_message_at DESC LIMIT 100";
        $rows = (array) $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $items = array();
        foreach ( $rows as $item ) {
            $name = $item->customer_name ?: 'Visiteur #' . (int) $item->id;
            $args = array_filter( array( 'page' => 'delicat-assistant', 'conversation' => (int) $item->id, 'status' => $status, 'queue' => $queue, 's' => $search ) );
            $waiting = ( 'waiting' === (string) $item->status || (int) $item->unread_agent > 0 ) && ! empty( $item->last_customer_at );
            $wait_seconds = $waiting ? max( 0, current_time( 'timestamp' ) - strtotime( $item->last_customer_at ) ) : 0;
            $items[] = array(
                'id' => (int) $item->id,
                'name' => sanitize_text_field( $name ),
                'initial' => DSA2_Compat::upper( DSA2_Compat::substr( $name, 0, 1 ) ),
                'contact' => sanitize_text_field( $item->customer_email ?: ( $item->customer_phone ?: 'Session invitée' ) ),
                'preview' => sanitize_text_field( wp_trim_words( wp_strip_all_tags( html_entity_decode( (string) $item->last_body, ENT_QUOTES, 'UTF-8' ) ), 10, '…' ) ?: 'Nouvelle demande de support' ),
                'time' => sanitize_text_field( human_time_diff( strtotime( $item->last_agent_message_at ), current_time( 'timestamp' ) ) ),
                'status' => sanitize_key( (string) $item->status ),
                'priority' => sanitize_key( (string) $item->priority ),
                'assigned_to' => (int) $item->assigned_to,
                'wait_seconds' => $wait_seconds,
                'wait_label' => $waiting ? sanitize_text_field( human_time_diff( strtotime( $item->last_customer_at ), current_time( 'timestamp' ) ) ) : '',
                'unread' => (int) $item->unread_agent,
                'url' => esc_url_raw( add_query_arg( $args, admin_url( 'admin.php' ) ) ),
            );
        }
        wp_send_json_success( array( 'items' => $items, 'count' => count( $items ), 'latest' => self::inbox_state()['latest'] ) );
    }

    /**
     * v6.2.0 — customer directory search.
     *
     * The inbox search only ever looked inside dap_conversations, so a customer who had
     * never opened the chat simply did not exist as far as the team was concerned: there
     * was no way to start a conversation with them. This searches the WordPress customer
     * base as well and reports, for each match, whether a thread already exists.
     */
    public static function ajax_find_customer() {
        self::require_support( true );
        check_ajax_referer( 'dsa2_admin', 'nonce' );
        $term = DSA2_Compat::substr( trim( sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ) ), 0, 100 );
        if ( DSA2_Compat::length( $term ) < 2 ) wp_send_json_success( array( 'items' => array() ) );
        global $wpdb;
        $conv = $wpdb->prefix . 'dap_conversations';
        $found = array();

        // Existing conversations first: an open thread is always the right place to write.
        $like = '%' . $wpdb->esc_like( $term ) . '%';
        $access = self::access_sql( 'c' );
        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "SELECT c.id,c.user_id,c.customer_name,c.customer_email,c.customer_phone,c.status,c.language,c.last_message_at
             FROM $conv c
             WHERE (c.customer_name LIKE %s OR c.customer_email LIKE %s OR c.customer_phone LIKE %s OR CAST(c.id AS CHAR)=%s) $access
             ORDER BY c.last_message_at DESC LIMIT 20",
            $like, $like, $like, ltrim( $term, '#' )
        ) );
        foreach ( (array) $rows as $row ) {
            $key = (int) $row->user_id ? 'u' . (int) $row->user_id : 'c' . (int) $row->id;
            if ( isset( $found[ $key ] ) ) continue;
            $found[ $key ] = array(
                'user_id' => (int) $row->user_id,
                'conversation_id' => (int) $row->id,
                'name' => sanitize_text_field( $row->customer_name ?: 'Visiteur #' . (int) $row->id ),
                'contact' => sanitize_text_field( $row->customer_email ?: ( $row->customer_phone ?: 'Session invitée' ) ),
                'language' => sanitize_key( (string) $row->language ),
                'status' => sanitize_key( (string) $row->status ),
                'known' => true,
                'meta' => sprintf( 'Conversation #%d · %s', (int) $row->id, human_time_diff( strtotime( $row->last_message_at ), current_time( 'timestamp' ) ) . ' — dernier message' ),
            );
        }

        // Then the customer base. Agents restricted to their own queue do not get to browse
        // the whole directory, so this half is manager-only.
        if ( self::can_manage() && count( $found ) < 20 ) {
            $users = get_users( array(
                'search' => '*' . $term . '*',
                'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
                'number' => 20,
                'orderby' => 'display_name',
            ) );
            if ( count( $users ) < 20 ) {
                $by_meta = get_users( array(
                    'number' => 10,
                    'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                        'relation' => 'OR',
                        array( 'key' => 'billing_phone', 'value' => $term, 'compare' => 'LIKE' ),
                        array( 'key' => 'billing_first_name', 'value' => $term, 'compare' => 'LIKE' ),
                        array( 'key' => 'billing_last_name', 'value' => $term, 'compare' => 'LIKE' ),
                    ),
                ) );
                $users = array_merge( $users, $by_meta );
            }
            foreach ( (array) $users as $user ) {
                $key = 'u' . (int) $user->ID;
                if ( isset( $found[ $key ] ) || count( $found ) >= 25 ) continue;
                $phone = (string) get_user_meta( $user->ID, 'billing_phone', true );
                $found[ $key ] = array(
                    'user_id' => (int) $user->ID,
                    'conversation_id' => 0,
                    'name' => sanitize_text_field( $user->display_name ?: $user->user_login ),
                    'contact' => sanitize_text_field( $user->user_email ?: ( $phone ?: '—' ) ),
                    'language' => 'fr',
                    'status' => '',
                    'known' => false,
                    'meta' => 'Client du site — aucune conversation encore',
                );
            }
        }
        wp_send_json_success( array( 'items' => array_values( $found ) ) );
    }

    /**
     * Sends an agent message to a customer chosen from the directory. The customer sees it
     * in the "Équipe" tab of the assistant exactly like any other agent reply, and their
     * unread badge and, on the web, the notification chime both fire.
     */
    public static function ajax_direct_message() {
        self::require_support( true );
        check_ajax_referer( 'dsa2_admin', 'nonce' );
        $body = trim( sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ) );
        if ( '' === $body || DSA2_Compat::length( $body ) > 3000 ) wp_send_json_error( array( 'message' => 'Message invalide.' ), 400 );
        $cid = absint( $_POST['conversation_id'] ?? 0 );
        $uid = absint( $_POST['user_id'] ?? 0 );

        if ( ! $cid && $uid ) {
            // 7.0: starting a thread with any customer is a directory action, manager-only,
            // like the directory half of ajax_find_customer().
            if ( ! self::can_manage() ) wp_send_json_error( array( 'message' => 'Accès réservé aux responsables.' ), 403 );
            $conversation = DSA2_Data::conversation_for_user( $uid, 'fr' );
            if ( ! $conversation ) wp_send_json_error( array( 'message' => 'Impossible d’ouvrir une conversation avec ce client.' ), 400 );
            $cid = (int) $conversation->id;
        }
        if ( ! $cid ) wp_send_json_error( array( 'message' => 'Sélectionnez un client.' ), 400 );

        // conversation() normally requires an existing agent thread; a first outbound
        // message is precisely the case where there is none yet, so allow it here and keep
        // the assignment check that stops one agent writing into another's conversation.
        $conversation = self::conversation( $cid, false );
        if ( ! $conversation ) wp_send_json_error( array( 'message' => 'Conversation introuvable ou non autorisée.' ), 403 );

        $message_id = DSA2_Data::add_message( $cid, 'agent', $body, 'text', array(), 'agent' );
        if ( ! $message_id ) wp_send_json_error( array( 'message' => 'Le message n’a pas pu être enregistré.' ), 500 );
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}dap_conversations SET status='open', assigned_to=IF(assigned_to=0,%d,assigned_to) WHERE id=%d",
            get_current_user_id(),
            $cid
        ) );
        wp_send_json_success( array(
            'conversation_id' => $cid,
            'url' => esc_url_raw( admin_url( 'admin.php?page=delicat-assistant&conversation=' . $cid ) ),
            'name' => sanitize_text_field( $conversation->customer_name ?: 'Visiteur #' . $cid ),
        ) );
    }

    public static function sync_faq() {
        self::require_manager();
        check_admin_referer( 'dap_sync_faq' );
        DSA2_Install::maybe_sync_faq( true );
        wp_safe_redirect( admin_url( 'admin.php?page=delicat-assistant-settings&faqsync=1' ) );
        exit;
    }

    private static function support_agents() {
        return get_users( array( 'capability'=>DSA2_Install::CAP_SUPPORT, 'orderby'=>'display_name', 'order'=>'ASC' ) );
    }

    public static function page() {
        self::require_support();
        global $wpdb;
        $conv_table = $wpdb->prefix . 'dap_conversations';
        $msg_table  = $wpdb->prefix . 'dap_messages';
        $filter = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $queue  = sanitize_key( wp_unslash( $_GET['queue'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $clauses = array( 'c.last_agent_message_at IS NOT NULL' );
        $params = array();
        if ( in_array( $filter, array( 'open', 'waiting', 'resolved' ), true ) ) { $clauses[] = 'c.status=%s'; $params[] = $filter; }
        if ( 'mine' === $queue ) { $clauses[] = 'c.assigned_to=%d'; $params[] = get_current_user_id(); }
        elseif ( 'unassigned' === $queue ) $clauses[] = 'c.assigned_to=0';
        elseif ( 'unread' === $queue ) $clauses[] = "EXISTS(SELECT 1 FROM $msg_table qmsg WHERE qmsg.conversation_id=c.id AND qmsg.channel='agent' AND qmsg.sender='customer' AND qmsg.id>c.last_agent_read_id)";
        elseif ( 'urgent' === $queue ) $clauses[] = "c.priority IN ('high','urgent') AND c.status<>'resolved'";
        if ( ! self::can_manage() ) { $clauses[] = '(c.assigned_to=0 OR c.assigned_to=%d)'; $params[] = get_current_user_id(); }
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $clauses[] = '(c.customer_name LIKE %s OR c.customer_email LIKE %s OR c.customer_phone LIKE %s OR CAST(c.id AS CHAR)=%s)';
            array_push( $params, $like, $like, $like, ltrim( $search, '#' ) );
        }
        $where = implode( ' AND ', $clauses );
        $sql = "SELECT c.*,
            (SELECT sender FROM $msg_table lm WHERE lm.conversation_id=c.id AND lm.channel='agent' ORDER BY lm.id DESC LIMIT 1) AS last_sender,
            (SELECT body FROM $msg_table lm WHERE lm.conversation_id=c.id AND lm.channel='agent' ORDER BY lm.id DESC LIMIT 1) AS last_body,
            (SELECT created_at FROM $msg_table cm WHERE cm.conversation_id=c.id AND cm.channel='agent' AND cm.sender='customer' ORDER BY cm.id DESC LIMIT 1) AS last_customer_at,
            (SELECT COUNT(*) FROM $msg_table um WHERE um.conversation_id=c.id AND um.channel='agent' AND um.sender='customer' AND um.id>c.last_agent_read_id) AS unread_agent
            FROM $conv_table c WHERE $where
            ORDER BY CASE c.status WHEN 'waiting' THEN 0 WHEN 'open' THEN 1 ELSE 2 END, CASE c.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END, c.last_agent_message_at DESC LIMIT 100";
        if ( $params ) $sql = $wpdb->prepare( $sql, $params );
        $conversations = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $selected = absint( $_GET['conversation'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $selected && $conversations ) $selected = (int) $conversations[0]->id;
        $conversation = $selected ? self::conversation( $selected ) : null;
        if ( ! $conversation ) $selected = 0;
        $messages = $selected ? array_reverse( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $msg_table WHERE conversation_id=%d AND channel='agent' ORDER BY id DESC LIMIT 200", $selected ) ) ) : array();
        if ( $selected ) {
            $last_customer = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM $msg_table WHERE conversation_id=%d AND channel='agent' AND sender='customer'", $selected ) );
            if ( $last_customer ) $wpdb->query( $wpdb->prepare( "UPDATE $conv_table SET last_agent_read_id=GREATEST(last_agent_read_id,%d) WHERE id=%d", $last_customer, $selected ) );
        }
        $context = self::context( $conversation );
        $state = self::inbox_state();
        $settings = wp_parse_args( get_option( 'dap_settings', array() ), self::defaults() );
        $agents = self::support_agents();
        $last_id = $messages ? (int) end( $messages )->id : 0;
        $internal_notes = $selected ? get_option( 'dap_internal_notes_' . $selected, array() ) : array();
        if ( ! is_array( $internal_notes ) ) $internal_notes = array();
        ?>
        <div class="wrap dap-admin" data-dap-admin-inbox data-latest="<?php echo esc_attr( $state['latest'] ); ?>">
            <header class="dap-admin-hero">
                <div><span class="dap-eyebrow">DELICAT SUPPORT</span><h1>Centre de communication</h1><p>Conversations clients, suivi des commandes et passage fluide entre l’assistant et votre équipe.</p></div>
                <div class="dap-hero-actions"><span class="dap-live-pill is-<?php echo esc_attr( DSA2_Data::effective_status() ); ?>"><i></i><?php echo 'online'===DSA2_Data::effective_status()?'Équipe disponible':'Réponse différée'; ?></span><span class="dap-version">v<?php echo esc_html( DSA2_VERSION ); ?></span></div>
            </header>
            <?php if ( isset( $_GET['deleted'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Conversation et pièces jointes supprimées.</p></div><?php endif; ?>
            <section class="dap-stat-grid" aria-label="Résumé de la boîte de réception">
                <a href="<?php echo esc_url( admin_url('admin.php?page=delicat-assistant&status=waiting') ); ?>"><span>En attente</span><strong data-dap-count="waiting"><?php echo (int)$state['waiting']; ?></strong><em>À prendre en charge</em></a>
                <a href="<?php echo esc_url( admin_url('admin.php?page=delicat-assistant&status=open') ); ?>"><span>Actives</span><strong data-dap-count="open"><?php echo (int)$state['open']; ?></strong><em>En cours de traitement</em></a>
                <a href="<?php echo esc_url( admin_url('admin.php?page=delicat-assistant&queue=unread') ); ?>"><span>Non lus</span><strong data-dap-count="unread"><?php echo (int)$state['unread']; ?></strong><em>Messages clients</em></a>
                <a href="<?php echo esc_url( admin_url('admin.php?page=delicat-assistant&queue=mine') ); ?>"><span>Mes conversations</span><strong><?php echo (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $conv_table WHERE last_agent_message_at IS NOT NULL AND assigned_to=%d AND status<>'resolved'",get_current_user_id())); ?></strong><em>Assignées à moi</em></a>
            </section>
            <section class="dap-service-summary" aria-label="Priorités de service"><span><i aria-hidden="true">⚡</i><strong data-dap-count="urgent"><?php echo (int)$state['urgent']; ?></strong> demande(s) prioritaire(s)</span><span><i aria-hidden="true">⏱</i><strong data-dap-count="overdue"><?php echo (int)$state['overdue']; ?></strong> attente(s) de plus de 15 min</span><a href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant&queue=urgent')); ?>">Voir les urgences</a></section>
            <div class="dap-inbox-refresh" hidden><span>Nouvelle activité détectée.</span><button type="button" data-dap-inbox-refresh>Actualiser</button></div>
            <nav class="dap-inbox-toolbar" aria-label="Filtres">
                <div class="dap-filter-pills">
                    <a class="<?php echo ''===$filter&&''===$queue?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant')); ?>">Toutes</a>
                    <a class="<?php echo 'waiting'===$filter?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant&status=waiting')); ?>">En attente</a>
                    <a class="<?php echo 'unread'===$queue?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant&queue=unread')); ?>">Non lus</a>
                    <a class="<?php echo 'urgent'===$queue?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant&queue=urgent')); ?>">Urgents</a>
                    <a class="<?php echo 'open'===$filter?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant&status=open')); ?>">Actives</a>
                    <a class="<?php echo 'mine'===$queue?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant&queue=mine')); ?>">À moi</a>
                    <a class="<?php echo 'unassigned'===$queue?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant&queue=unassigned')); ?>">Non assignées</a>
                    <a class="<?php echo 'resolved'===$filter?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant&status=resolved')); ?>">Résolues</a>
                </div>
                <form method="get" class="dap-inbox-search"><input type="hidden" name="page" value="delicat-assistant"><label><span class="screen-reader-text">Rechercher</span><input name="s" type="search" value="<?php echo esc_attr($search); ?>" placeholder="Nom, e-mail, WhatsApp ou #ID"></label><button type="submit">Rechercher</button><button type="button" class="dap-compose-open" data-dap-compose-open>✉ Nouveau message</button></form>
            </nav>
            <section class="dap-compose" data-dap-compose hidden aria-label="Envoyer un message à un client">
                <header><div><strong>Envoyer un message à un client</strong><small>Cherchez n’importe quel client du site, même s’il n’a jamais ouvert le chat.</small></div><button type="button" class="dap-compose-close" data-dap-compose-close aria-label="Fermer">×</button></header>
                <div class="dap-compose-body">
                    <label class="dap-compose-field"><span>Client</span><input type="search" data-dap-compose-search autocomplete="off" placeholder="Nom, e-mail, WhatsApp ou #ID de conversation"></label>
                    <div class="dap-compose-results" data-dap-compose-results role="listbox" aria-label="Résultats"><p class="dap-compose-hint">Tapez au moins deux caractères.</p></div>
                    <div class="dap-compose-selected" data-dap-compose-selected hidden></div>
                    <label class="dap-compose-field"><span>Message</span><textarea data-dap-compose-body maxlength="3000" rows="4" placeholder="Écrivez le message que le client recevra dans l’onglet Équipe…"></textarea></label>
                    <div class="dap-compose-actions"><span class="dap-compose-status" aria-live="polite"></span><button type="button" data-dap-compose-send disabled>Envoyer le message</button></div>
                </div>
            </section>
            <div class="dap-inbox-layout">
                <aside class="dap-conversation-list" aria-label="Conversations">
                    <div class="dap-panel-heading"><div><span>Boîte de réception</span><strong><?php echo count($conversations); ?> conversation(s)</strong></div><a href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant&queue=unassigned')); ?>">Non assignées</a></div>
                    <div class="dap-conversation-scroll">
                    <?php if ( ! $conversations ) : ?><div class="dap-list-empty"><span>✓</span><strong>La file est vide</strong><p>Aucune conversation ne correspond à ce filtre.</p></div><?php endif; ?>
                    <?php foreach ( $conversations as $item ) :
                        $name = $item->customer_name ?: 'Visiteur #' . $item->id;
                        $contact = $item->customer_email ?: ( $item->customer_phone ?: 'Session invitée' );
                        $preview = wp_trim_words( wp_strip_all_tags( html_entity_decode( (string)$item->last_body, ENT_QUOTES, 'UTF-8' ) ), 10, '…' );
                        $url = add_query_arg( array_filter(array('page'=>'delicat-assistant','conversation'=>(int)$item->id,'status'=>$filter,'queue'=>$queue,'s'=>$search)), admin_url('admin.php') );
                        $wait_seconds = ( ( 'waiting' === $item->status || (int) $item->unread_agent > 0 ) && ! empty( $item->last_customer_at ) ) ? max( 0, current_time( 'timestamp' ) - strtotime( $item->last_customer_at ) ) : 0;
                    ?>
                        <a class="dap-conversation-card <?php echo $selected===(int)$item->id?'is-active':''; ?> <?php echo $wait_seconds >= 3600 ? 'is-overdue-critical' : ( $wait_seconds >= 900 ? 'is-overdue' : '' ); ?>" data-conversation-id="<?php echo (int)$item->id; ?>" href="<?php echo esc_url($url); ?>">
                            <span class="dap-avatar"><?php echo esc_html( DSA2_Compat::upper( DSA2_Compat::substr( $name, 0, 1 ) ) ); ?></span>
                            <span class="dap-conversation-copy"><span><strong><?php echo esc_html($name); ?></strong><time><?php echo esc_html(human_time_diff(strtotime($item->last_agent_message_at),current_time('timestamp'))); ?></time></span><em><?php echo esc_html($contact); ?></em><small><?php echo esc_html($preview?:'Nouvelle demande de support'); ?></small></span>
                            <span class="dap-card-meta"><i class="dap-state is-<?php echo esc_attr($item->status); ?>"><?php echo esc_html($item->status); ?></i><?php if(in_array($item->priority,array('high','urgent'),true)):?><i class="dap-priority is-<?php echo esc_attr($item->priority); ?>"><?php echo 'urgent'===$item->priority?'Urgent':'Priorité'; ?></i><?php endif;?><?php if($wait_seconds>=60):?><small class="dap-wait-age">⏱ <?php echo esc_html(human_time_diff(strtotime($item->last_customer_at),current_time('timestamp'))); ?></small><?php endif;?><?php if((int)$item->unread_agent>0):?><b><?php echo min(99,(int)$item->unread_agent); ?></b><?php endif;?></span>
                        </a>
                    <?php endforeach; ?>
                    </div>
                </aside>

                <main class="dap-thread-panel">
                <?php if ( $conversation ) :
                    $customer_name = $conversation->customer_name ?: 'Visiteur #' . $conversation->id;
                    $contact = $conversation->customer_email ?: ( $conversation->customer_phone ?: 'Session invitée' );
                    $assigned = $conversation->assigned_to ? get_userdata((int)$conversation->assigned_to) : false;
                    $wa = $conversation->customer_phone ? 'https://wa.me/' . preg_replace('/\D+/','',$conversation->customer_phone) : '';
                ?>
                    <header class="dap-thread-header">
                        <div class="dap-thread-person"><span class="dap-avatar is-large"><?php echo esc_html(DSA2_Compat::upper(DSA2_Compat::substr($customer_name,0,1))); ?></span><div><span class="dap-mobile-back">Conversation #<?php echo (int)$conversation->id; ?></span><h2><?php echo esc_html($customer_name); ?></h2><p><?php echo esc_html($contact); ?> · <?php echo esc_html(strtoupper($conversation->language)); ?></p></div></div>
                        <div class="dap-thread-actions"><?php if($wa):?><a href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif;?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dap_export_conversation&conversation_id='.$selected),'dap_export_conversation')); ?>">Exporter</a></div>
                    </header>
                    <form class="dap-routing-bar" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-dap-routing>
                        <input type="hidden" name="action" value="dap_update_conversation"><input type="hidden" name="conversation_id" value="<?php echo (int)$selected; ?>"><?php wp_nonce_field('dap_update_conversation'); ?>
                        <label><span>Statut</span><select name="status"><option value="waiting" <?php selected($conversation->status,'waiting'); ?>>En attente</option><option value="open" <?php selected($conversation->status,'open'); ?>>Active</option><option value="resolved" <?php selected($conversation->status,'resolved'); ?>>Résolue</option></select></label>
                        <label><span>Priorité</span><select name="priority"><option value="low" <?php selected($conversation->priority,'low'); ?>>Basse</option><option value="normal" <?php selected($conversation->priority,'normal'); ?>>Normale</option><option value="high" <?php selected($conversation->priority,'high'); ?>>Haute</option><option value="urgent" <?php selected($conversation->priority,'urgent'); ?>>Urgente</option></select></label>
                        <?php if(self::can_manage()):?><label class="dap-assignee"><span>Assignée à</span><select name="assigned_to"><option value="0">Non assignée</option><?php foreach($agents as $agent):?><option value="<?php echo (int)$agent->ID; ?>" <?php selected((int)$conversation->assigned_to,(int)$agent->ID); ?>><?php echo esc_html($agent->display_name); ?></option><?php endforeach;?></select></label><?php else:?><input type="hidden" name="assigned_to" value="<?php echo (int)$conversation->assigned_to; ?>"><span class="dap-assignee-copy"><?php echo $assigned?'Assignée à '.esc_html($assigned->display_name):'Non assignée'; ?></span><?php endif; ?>
                        <button type="submit">Mettre à jour</button><span class="dap-route-saved" aria-live="polite"></span>
                    </form>
                    <details class="dap-handoff-context" data-dap-handoff><summary>✨ Voir le contexte de l’assistant automatique</summary><div class="dap-handoff-items" aria-live="polite"><p>Ouvrez pour consulter les échanges précédant le passage à l’équipe.</p></div></details>
                    <section class="dap-thread" data-conversation-id="<?php echo (int)$selected; ?>" data-last-id="<?php echo (int)$last_id; ?>" data-customer-read-id="<?php echo (int)$conversation->last_customer_read_id; ?>" aria-live="polite" aria-relevant="additions">
                        <div class="dap-thread-start"><span>🔒</span><p>Canal privé entre le client et l’équipe Delicat. Le contexte de l’assistant reste disponible ci-dessus.</p></div>
                        <?php foreach($messages as $message) self::render_message($message, (int) $conversation->last_customer_read_id); ?>
                    </section>
                    <div class="dap-customer-typing" hidden><i></i><span>Le client écrit…</span></div>
                    <form class="dap-reply" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-dap-reply>
                        <input type="hidden" name="action" value="dap_reply"><input type="hidden" name="conversation_id" value="<?php echo (int)$selected; ?>"><?php wp_nonce_field('dap_reply'); ?>
                        <?php
                        $quick = class_exists('DSA2_QuickReplies') ? DSA2_QuickReplies::all($conversation) : array();
                        $qlang = $conversation && !empty($conversation->language) ? sanitize_key($conversation->language) : 'fr';
                        $qtabs = $quick ? DSA2_QuickReplies::group_tabs($quick,$qlang) : array();
                        if($quick): ?>
                        <div class="dap-quick" data-dap-quick>
                            <div class="dap-quick-bar">
                                <button type="button" class="dap-quick-toggle" data-dap-quick-toggle aria-expanded="false">⚡ <span>Messages rapides</span> <b><?php echo count($quick); ?></b></button>
                                <input type="search" class="dap-quick-search" data-dap-quick-search placeholder="Rechercher… (/)" aria-label="Rechercher un message rapide">
                            </div>
                            <div class="dap-quick-body" hidden>
                                <div class="dap-quick-tabs" role="tablist">
                                    <button type="button" class="is-on" data-group="">Tous</button>
                                    <?php foreach($qtabs as $gk=>$glabel): ?>
                                    <button type="button" data-group="<?php echo esc_attr($gk); ?>"><?php echo esc_html($glabel); ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="dap-quick-list" role="list">
                                    <?php foreach($quick as $i=>$r): ?>
                                    <button type="button" role="listitem" class="dap-quick-item" data-group="<?php echo esc_attr($r['group']); ?>"
                                        data-text="<?php echo esc_attr($r['text']); ?>"
                                        title="<?php echo esc_attr($r['text']); ?>">
                                        <?php if($i<9): ?><kbd><?php echo (int)($i+1); ?></kbd><?php endif; ?>
                                        <strong><?php echo esc_html($r['label']); ?></strong>
                                        <small><?php echo esc_html(DSA2_Compat::substr($r['text'],0,88)); ?>…</small>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                                <p class="dap-quick-hint">Clic pour insérer · <kbd>Alt</kbd>+<kbd>1…9</kbd> raccourci · le nom du client, votre nom et le délai sont déjà remplis</p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="dap-reply-row"><textarea name="body" maxlength="3000" rows="2" required placeholder="Écrivez une réponse claire au client…"></textarea><button type="submit"><span>Envoyer</span><b>➤</b></button></div>
                        <div class="dap-reply-help"><span>Ctrl/⌘ + Entrée pour envoyer</span><span>Réponse signée par <?php echo esc_html(wp_get_current_user()->display_name); ?></span></div>
                    </form>
                    <div class="dap-thread-danger"><?php if(self::can_manage()):?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-dap-confirm-delete><input type="hidden" name="action" value="dap_delete_conversation"><input type="hidden" name="conversation_id" value="<?php echo (int)$selected; ?>"><?php wp_nonce_field('dap_delete_conversation'); ?><button type="submit">Supprimer définitivement</button></form><?php endif;?></div>
                <?php else : ?>
                    <div class="dap-thread-empty"><span>💬</span><h2>Sélectionnez une conversation</h2><p>Choisissez un client dans la liste pour consulter uniquement son échange avec les agents.</p></div>
                <?php endif; ?>
                </main>

                <aside class="dap-customer-panel">
                    <div class="dap-customer-card"><span class="dap-card-kicker">CONTEXTE CLIENT</span><h2><?php echo $conversation?esc_html($conversation->customer_name?:'Visiteur'):'Aucun client'; ?></h2><p><?php echo $conversation?esc_html($conversation->customer_email?:($conversation->customer_phone?:'Session invitée')):'Sélectionnez une conversation'; ?></p><?php if($context['account_url']):?><a href="<?php echo esc_url($context['account_url']); ?>">Ouvrir le compte client ↗</a><?php endif;?></div>
                    <div class="dap-context-metrics"><div><span>Solde</span><strong><?php echo esc_html($context['balance']); ?></strong></div><div><span>Commandes</span><strong><?php echo esc_html($context['orders']); ?></strong></div><div><span>Total dépensé</span><strong><?php echo esc_html($context['spent']); ?></strong></div><div><span>Récompenses</span><strong><?php echo (int)$context['points']; ?></strong></div><div><span>Priorité</span><strong><?php echo $conversation?esc_html(ucfirst($conversation->priority)):'—'; ?></strong></div></div>
                    <?php if(!empty($context['recent_orders'])):?><section class="dap-recent-orders"><h3>Commandes récentes</h3><?php foreach($context['recent_orders'] as $recent):?><a href="<?php echo esc_url($recent['url']); ?>"><strong>#<?php echo esc_html($recent['number']); ?></strong><span><?php echo esc_html($recent['status']); ?> · <?php echo esc_html($recent['total']); ?></span><small><?php echo esc_html(trim($recent['date'].' · '.$recent['payment'],' ·')); ?></small></a><?php endforeach;?></section><?php endif;?>
                    <div class="dap-customer-details"><h3>Détails</h3><dl><div><dt>ID</dt><dd><?php echo $conversation?'#'.(int)$conversation->id:'—'; ?></dd></div><div><dt>Langue</dt><dd><?php echo $conversation?esc_html(strtoupper($conversation->language)):'—'; ?></dd></div><div><dt>Assignation</dt><dd><?php echo $conversation&&$assigned?esc_html($assigned->display_name):'Non assignée'; ?></dd></div><div><dt>Dernière activité</dt><dd><?php echo $conversation?esc_html(mysql2date('d/m/Y H:i',$conversation->last_agent_message_at)):'—'; ?></dd></div></dl></div>
                    <?php if($conversation):?><section class="dap-internal-notes"><h3>Notes internes <span>🔒</span></h3><div class="dap-note-list" aria-live="polite"><?php foreach(array_slice($internal_notes,-8) as $note): if(!is_array($note))continue;?><article><strong><?php echo esc_html($note['author']??'Agent'); ?></strong><time><?php echo esc_html($note['time']??''); ?></time><p><?php echo esc_html($note['body']??''); ?></p></article><?php endforeach;?></div><form data-dap-note><label class="screen-reader-text" for="dap-private-note">Note interne</label><textarea id="dap-private-note" maxlength="1200" rows="2" placeholder="Visible uniquement par l’équipe…"></textarea><button type="submit">Ajouter une note</button></form></section><?php endif;?>
                    <div class="dap-side-tip"><span>💡</span><p>Demandez le numéro de commande, le montant ou le TransCode dès le premier message pour résoudre plus vite.</p></div>
                </aside>
            </div>
        </div>
        <?php
    }

    public static function settings_page() {
        self::require_manager();
        DSA2_Install::maybe_sync_faq( true );
        $settings = wp_parse_args( get_option( 'dap_settings', array() ), self::defaults() );
        $training = DSA2_Training::stats();
        $smalltalk = class_exists('DSA2_SmallTalk') ? DSA2_SmallTalk::stats() : array('intents'=>0,'variants'=>0);
        ?>
        <div class="wrap dap-admin dap-settings-page">
            <header class="dap-admin-hero"><div><span class="dap-eyebrow">CONFIGURATION</span><h1>Paramètres de Delicat Assistant</h1><p>Gérez l’apparence, les canaux de contact, les automatisations et la conservation des données.</p></div><div class="dap-hero-actions"><a class="dap-secondary-link" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant')); ?>">← Retour à l’inbox</a><span class="dap-version">v<?php echo esc_html(DSA2_VERSION); ?></span></div></header>
            <?php if(isset($_GET['saved'])):?><div class="notice notice-success is-dismissible"><p>Paramètres enregistrés et cache actualisé.</p></div><?php endif; ?>
            <?php if(isset($_GET['faqsync'])):?><div class="notice notice-success is-dismissible"><p>FAQ synchronisée avec le Builder.</p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dap-settings-form"><input type="hidden" name="action" value="dap_save_settings"><?php wp_nonce_field('dap_save_settings'); ?>
                <section class="dap-settings-card"><header><span>01</span><div><h2>Widget et marque</h2><p>Réglez le comportement et l’identité visibles par les clients.</p></div></header><div class="dap-settings-grid">
                    <label class="dap-toggle-row"><input type="checkbox" name="enabled" <?php checked($settings['enabled']); ?>><span><b>Widget flottant</b><small>Afficher l’assistant automatiquement sur le site.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="auto_open" <?php checked($settings['auto_open']); ?>><span><b>Ouverture automatique</b><small>Ouvrir une fois par session visiteur.</small></span></label>
                    <label><span>Nom affiché</span><input name="brand_name" value="<?php echo esc_attr($settings['brand_name']); ?>" maxlength="80"></label>
                    <label><span>Côté du bouton</span><select name="launcher_side"><option value="right" <?php selected($settings['launcher_side'],'right'); ?>>Droite</option><option value="left" <?php selected($settings['launcher_side'],'left'); ?>>Gauche</option></select></label>
                    <label><span>Distance du bas (px)</span><input type="number" name="launcher_bottom" min="0" max="400" value="<?php echo esc_attr($settings['launcher_bottom']); ?>"></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="launcher_label_enabled" <?php checked($settings['launcher_label_enabled']); ?>><span><b>Message sous l’icône</b><small>Afficher le petit appel à discuter avec nous.</small></span></label>
                    <label><span>Petit titre</span><input name="launcher_kicker" value="<?php echo esc_attr($settings['launcher_kicker']); ?>" maxlength="40" placeholder="Besoin d’aide ?"></label>
                    <label><span>Message du bouton</span><input name="launcher_message" value="<?php echo esc_attr($settings['launcher_message']); ?>" maxlength="60" placeholder="Discutez avec nous !"></label>
                    <label><span>Premier popup (secondes)</span><input type="number" name="launcher_popup_delay" min="3" max="120" value="<?php echo esc_attr($settings['launcher_popup_delay']); ?>"><small>Recommandé : 10 secondes.</small></label>
                    <label><span>Répéter après (secondes)</span><input type="number" name="launcher_popup_interval" min="30" max="600" value="<?php echo esc_attr($settings['launcher_popup_interval']); ?>"><small>Recommandé : 75–90 secondes.</small></label>
                    <label><span>Durée visible (secondes)</span><input type="number" name="launcher_popup_visible" min="3" max="15" value="<?php echo esc_attr($settings['launcher_popup_visible']); ?>"></label>
                    <label><span>Maximum par session</span><input type="number" name="launcher_popup_max" min="0" max="10" value="<?php echo esc_attr($settings['launcher_popup_max']); ?>"><small>0 désactive les rappels automatiques.</small></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="launcher_suppress_checkout" <?php checked($settings['launcher_suppress_checkout']); ?>><span><b>Mode paiement silencieux</b><small>Ne pas afficher le popup pendant le panier, le paiement ou la saisie d’un formulaire.</small></span></label>
                    <label><span>Taille du texte (%)</span><input type="number" name="font_scale" min="90" max="125" value="<?php echo esc_attr($settings['font_scale']); ?>"></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="force_reduced_motion" <?php checked($settings['force_reduced_motion']); ?>><span><b>Réduire les animations</b><small>Désactive la pulsation et les transitions même si l’appareil ne le demande pas.</small></span></label>
                </div></section>

                <section class="dap-settings-card"><header><span>02</span><div><h2>Agents et canaux</h2><p>Facilitez le passage du bot vers un humain ou WhatsApp.</p></div></header><div class="dap-settings-grid">
                    <label><span>Statut manuel</span><select name="agent_status"><option value="online" <?php selected($settings['agent_status'],'online'); ?>>En ligne</option><option value="away" <?php selected($settings['agent_status'],'away'); ?>>Occupé</option><option value="offline" <?php selected($settings['agent_status'],'offline'); ?>>Hors ligne</option></select></label>
                    <label><span>Horaires affichés</span><input name="business_hours" value="<?php echo esc_attr($settings['business_hours']); ?>" placeholder="Lun–Sam, 8:00–20:00"></label>
                    <label><span>Heure d’ouverture</span><input type="number" min="0" max="23" name="open_hour" value="<?php echo esc_attr($settings['open_hour']); ?>"></label>
                    <label><span>Heure de fermeture</span><input type="number" min="0" max="24" name="close_hour" value="<?php echo esc_attr($settings['close_hour']); ?>"><small>Même valeur = service 24/7.</small></label>
                    <label><span>WhatsApp</span><input name="whatsapp" inputmode="numeric" value="<?php echo esc_attr($settings['whatsapp']); ?>" placeholder="509XXXXXXXX"></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="show_whatsapp" <?php checked($settings['show_whatsapp']); ?>><span><b>Afficher WhatsApp</b><small>Troisième choix direct sur l’écran d’accueil.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="notify_enabled" <?php checked($settings['notify_enabled']); ?>><span><b>Alerte e-mail</b><small>Prévenir lors d’une nouvelle demande d’agent.</small></span></label>
                    <label><span>E-mail d’alerte</span><input type="email" name="notify_email" value="<?php echo esc_attr($settings['notify_email']); ?>"></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="notify_every_message" <?php checked($settings['notify_every_message']); ?>><span><b>Alerte à chaque message</b><small>Envoyer un e-mail immédiatement dès qu’un client écrit à l’équipe, pas seulement à la première demande.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="notify_staff" <?php checked($settings['notify_staff']); ?>><span><b>Prévenir toute l’équipe</b><small>Envoyer aussi aux agents support Delicat, en plus de l’e-mail d’alerte.</small></span></label>
                    <label><span>Anti-spam des alertes (secondes)</span><input type="number" name="notify_throttle" min="0" max="900" value="<?php echo esc_attr($settings['notify_throttle']); ?>"><small>Un e-mail au plus par conversation pendant ce délai. Recommandé : 120. 0 = un e-mail par message (déconseillé : un robot peut épuiser le quota d’e-mails de l’hébergeur).</small></label>
                    <label><span>Alertes e-mail par heure (maximum)</span><input type="number" name="notify_hourly_cap" min="5" max="500" value="<?php echo esc_attr($settings['notify_hourly_cap']); ?>"><small>Au-delà, un seul e-mail récapitulatif part à la fin de l’heure. Les messages restent tous dans la boîte de réception.</small></label>
                    <label><span>Délai estimé — en ligne</span><input name="agent_eta_online" value="<?php echo esc_attr($settings['agent_eta_online']); ?>" placeholder="5–15 min"></label>
                    <label><span>Délai estimé — occupé</span><input name="agent_eta_away" value="<?php echo esc_attr($settings['agent_eta_away']); ?>" placeholder="30–60 min"></label>
                </div></section>

                <section class="dap-settings-card"><header><span>03</span><div><h2>Assistant intelligent</h2><p>Contrôlez les réponses automatiques et les informations de boutique.</p></div></header><div class="dap-settings-grid">
                    <label class="dap-toggle-row"><input type="checkbox" name="products_enabled" <?php checked($settings['products_enabled']); ?>><span><b>Prix et stock WooCommerce</b><small>Répondre avec les produits disponibles.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="recommend_enabled" <?php checked($settings['recommend_enabled']); ?>><span><b>Recommandations</b><small>Produits liés et achats fréquents.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="product_context_enabled" <?php checked($settings['product_context_enabled']); ?>><span><b>Contexte produit</b><small>Transmettre au bot et aux agents le produit, la variation et le plan consultés, sans transmettre le Player ID.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="order_support_enabled" <?php checked($settings['order_support_enabled']); ?>><span><b>Support commandes sécurisé</b><small>Suivi, paiement non crédité, PIN non reçu et transfert lié à la dernière commande.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="order_updates_in_chat" <?php checked($settings['order_updates_in_chat']); ?>><span><b>Historique des commandes dans le chat</b><small>Déconseillé. Publie chaque changement de statut dans la conversation avec l’équipe et noie les vrais messages. Le client peut toujours consulter le statut à la demande.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="training_enabled" <?php checked($settings['training_enabled']); ?>><span><b>Base d’entraînement</b><small><?php
                        if ( ! $training['available'] ) { echo 'Fichier indisponible'; }
                        else {
                            $parts = array();
                            foreach ( array('fr'=>'FR','en'=>'EN','ht'=>'HT') as $code=>$label ) {
                                if ( ! empty($training['languages'][$code]) ) $parts[] = $label.' '.number_format_i18n($training['languages'][$code]);
                            }
                            echo esc_html( number_format_i18n($training['rows']).' conversations · '.$training['size'] . ( $parts ? ' · '.implode(' / ',$parts) : '' ) );
                        }
                    ?></small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="training_corpus_enabled" <?php checked( ! empty( $settings['training_corpus_enabled'] ) ); ?>><span><b>Réponses du corpus CSV</b><small>Jeu de données générique en anglais. Désactivé : seules les règles d’intention répondent.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="smalltalk_enabled" <?php checked($settings['smalltalk_enabled']); ?>><span><b>Conversation naturelle</b><small><?php echo esc_html( number_format_i18n($smalltalk['intents']).' intentions · '.number_format_i18n($smalltalk['variants']).' formulations FR/EN/HT' ); ?> — salutations, remerciements, « sak pase », au revoir, excuses, patience. Le bot répond à la politesse avant de traiter la demande.</small></span></label>
                    <label><span>Numéro MonCash</span><input name="moncash_number" value="<?php echo esc_attr($settings['moncash_number']); ?>"></label>
                    <label><span>Numéro Natcash</span><input name="natcash_number" value="<?php echo esc_attr($settings['natcash_number']); ?>"></label>
                    <label><span>Recharge minimum</span><input name="min_recharge" value="<?php echo esc_attr($settings['min_recharge']); ?>" placeholder="250 HTG"></label>
                    <label><span>Délai de livraison</span><input name="delivery_time" value="<?php echo esc_attr($settings['delivery_time']); ?>" placeholder="5 à 30 minutes"></label>
                    <label class="dap-span-2"><span>FAQ intelligente <small>Une règle par ligne : mots|synonymes=réponse</small></span><textarea name="faq_text" rows="7"><?php echo esc_textarea($settings['faq_text']); ?></textarea></label>
                    <label class="dap-span-2"><span>Guides personnalisés</span><textarea name="guides_text" rows="5" placeholder="id|fr|Titre|Étape 1;Étape 2|mots-clés"><?php echo esc_textarea($settings['guides_text']); ?></textarea></label>
                    <label class="dap-span-2"><span>Images des guides</span><textarea name="guide_images" rows="4" placeholder="natcash_auto|2|https://..."><?php echo esc_textarea($settings['guide_images']); ?></textarea></label>
                </div><footer><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dap_sync_faq'),'dap_sync_faq')); ?>">↻ Synchroniser maintenant la FAQ du Builder</a></footer></section>

                <section class="dap-settings-card"><header><span>04</span><div><h2>Liens et intégrations</h2><p>Adaptez les destinations et les notifications externes.</p></div></header><div class="dap-settings-grid">
                    <label><span>Portefeuille</span><input type="url" name="url_wallet" value="<?php echo esc_attr($settings['url_wallet']); ?>" placeholder="<?php echo esc_attr(home_url('/my-wallet/')); ?>"></label>
                    <label><span>PIN / Redeem</span><input type="url" name="url_redeem" value="<?php echo esc_attr($settings['url_redeem']); ?>" placeholder="<?php echo esc_attr(home_url('/free-fire-redeem/')); ?>"></label>
                    <label><span>Récompenses</span><input type="url" name="url_rewards" value="<?php echo esc_attr($settings['url_rewards']); ?>" placeholder="<?php echo esc_attr(home_url('/rewards/')); ?>"></label>
                    <label><span>Boutique</span><input type="url" name="url_shop" value="<?php echo esc_attr($settings['url_shop']); ?>" placeholder="<?php echo esc_attr(home_url('/shop/')); ?>"></label>
                    <label class="dap-span-2"><span>URL webhook agent</span><input type="url" name="webhook_url" value="<?php echo esc_attr($settings['webhook_url']); ?>" placeholder="https://..."></label>
                    <div class="dap-span-2"><span>Notifications push</span><p class="description"><?php
                        // 6.6.1: one FCM sender for the whole site, the Delicat App API's. No Firebase relay or key here any more.
                        if ( class_exists( 'Delicat_App_Push' ) ) {
                            $dap_push_ok = method_exists( 'Delicat_App_Push', 'is_configured' ) && Delicat_App_Push::is_configured();
                            echo $dap_push_ok ? '✅ Envoyées par Delicat App API (compte de service configuré).' : '⚠️ Delicat App API installé, mais son compte de service Firebase n’est pas configuré (Delicat App → Notifications push).';
                        } else {
                            echo '⚠️ Delicat App API absent : aucune notification push ne part pour l’assistance.';
                        }
                    ?></p></div>
                    <label><span>Nouvelle clé webhook</span><input type="password" name="webhook_secret" value="" autocomplete="new-password" placeholder="<?php echo !empty($settings['webhook_secret'])?'Configurée — vide = conserver':'Saisir une clé'; ?>"></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="clear_webhook_secret"><span><b>Supprimer la clé</b><small>Effacer la clé webhook enregistrée.</small></span></label>
                    <?php $qstats = class_exists('DSA2_QuickReplies') ? DSA2_QuickReplies::stats() : array('replies'=>0,'total'=>0,'groups'=>0); $qoff = array_filter(explode(',',(string)$settings['quick_groups_off'])); ?>
                    <label class="dap-toggle-row dap-span-2"><input type="checkbox" name="quick_replies_enabled" <?php checked($settings['quick_replies_enabled']); ?>><span><b>Messages rapides des agents</b><small><?php echo esc_html($qstats['replies'].' messages prêts · '.$qstats['total'].' formulations FR/EN/HT · '.$qstats['groups'].' groupes'); ?> — insérés en un clic dans l’inbox, avec le nom du client, votre nom et le délai déjà remplis.</small></span></label>
                    <label class="dap-span-2"><span>Groupes désactivés <small>Décochez ce que votre équipe n’utilise pas</small></span>
                        <span class="dap-quick-groups">
                        <?php foreach(DSA2_QuickReplies::groups() as $gk=>$gm): ?>
                            <label class="dap-quick-group"><input type="checkbox" name="quick_groups_off[]" value="<?php echo esc_attr($gk); ?>" <?php checked(in_array($gk,$qoff,true)); ?>><span><?php echo esc_html($gm['icon'].' '.$gm['fr']); ?></span></label>
                        <?php endforeach; ?>
                        </span>
                    </label>
                    <label class="dap-span-2"><span>Messages rapides personnalisés <small>Une par ligne. « Étiquette = texte » pour nommer la réponse. Variables : {name} {agent} {store} {eta} {wallet} {shop} {account}</small></span><textarea name="canned_replies" rows="6" placeholder="Promo du jour = Bonjour {name}, nous avons une promotion sur les recharges aujourd'hui."><?php echo esc_textarea($settings['canned_replies']); ?></textarea></label>
                </div></section>

                <section class="dap-settings-card"><header><span>05</span><div><h2>Application Android administrateur</h2><p>Gérez toutes les conversations et recevez les nouveaux messages sur votre téléphone.</p></div></header><div class="dap-settings-grid">
                    <label class="dap-toggle-row"><input type="checkbox" name="admin_app_enabled" <?php checked($settings['admin_app_enabled']); ?>><span><b>Activer l’application Android</b><small>Désactivée par défaut. Laissez-la désactivée si votre équipe n’utilise pas l’application. Connexion réservée aux administrateurs, responsables WooCommerce et agents support Delicat.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="admin_push_enabled" <?php checked($settings['admin_push_enabled']); ?>><span><b>Notifications push administrateur</b><small>Recevoir immédiatement les messages clients adressés à l’équipe.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="admin_push_preview" <?php checked($settings['admin_push_preview']); ?>><span><b>Aperçu du message sur le téléphone</b><small>Désactivé par défaut pour protéger la confidentialité sur l’écran verrouillé.</small></span></label>
                    <label class="dap-toggle-row"><input type="checkbox" name="admin_app_require_2fa" <?php checked($settings['admin_app_require_2fa']); ?>><span><b>Exiger la 2FA pour l’application</b><small>Recommandé. Seuls les comptes avec la vérification en deux étapes (Delicat Identity) peuvent se connecter, avec leur code à chaque connexion. Un compte qui a activé la 2FA doit toujours donner son code, même si cette case est décochée.</small></span></label>
                    <label><span>Durée des sessions Android</span><input type="number" min="1" max="<?php echo (int) DSA2_STAFF_SESSION_MAX_DAYS; ?>" name="admin_session_days" value="<?php echo esc_attr(min((int)$settings['admin_session_days'], DSA2_STAFF_SESSION_MAX_DAYS)); ?>"><small>Entre 1 et 7 jours après la connexion, puis il faut se reconnecter. Changer de mot de passe ou de rôle déconnecte aussi le téléphone. Une déconnexion supprime immédiatement le jeton push.</small></label>
                    <div class="dap-readonly-note dap-span-2"><strong>Adresse de connexion</strong><p><code><?php echo esc_html(home_url('/')); ?></code> — utilisez un compte administrateur WordPress, WooCommerce ou Agent support Delicat.</p></div>
                    <div class="dap-readonly-note dap-span-2"><strong>API Android sécurisée</strong><p><code><?php echo esc_html(rest_url(DSA2_Admin_Mobile_API::NS)); ?></code></p></div>
                    <?php global $wpdb; $active_admin_devices=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dap_admin_sessions WHERE expires_at>UTC_TIMESTAMP() AND created_at>%s", gmdate('Y-m-d H:i:s', time()-DSA2_Admin_Mobile_API::session_days()*DAY_IN_SECONDS))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared ?>
                    <div class="dap-readonly-note dap-span-2"><strong><?php echo (int)$active_admin_devices; ?> appareil(s) administrateur connecté(s)</strong><p><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dap_revoke_admin_sessions'),'dap_revoke_admin_sessions')); ?>" data-dap-confirm-revoke>Déconnecter tous les appareils Android →</a></p></div>
                </div></section>

                <section class="dap-settings-card"><header><span>06</span><div><h2>Confidentialité et maintenance</h2><p>Conservez seulement les données nécessaires au support.</p></div></header><div class="dap-settings-grid">
                    <label><span>Conservation des conversations invitées</span><input type="number" min="0" max="3650" name="retention_days" value="<?php echo esc_attr($settings['retention_days']); ?>"><small>Jours. 0 désactive la suppression automatique.</small></label>
                    <label><span>Conservation des captures de paiement</span><input type="number" min="0" max="3650" name="image_retention_days" value="<?php echo esc_attr($settings['image_retention_days']); ?>"><small>Jours. 0 = garder (par défaut). Les images envoyées dans le chat sont privées : seuls le client et l’équipe peuvent les ouvrir. Au-delà de ce délai, l’image est supprimée et remplacée par « Image supprimée ».</small></label>
                    <div class="dap-readonly-note"><strong>Rôle Agent support Delicat</strong><p>Les agents accèdent uniquement aux conversations et tickets, sans droits WooCommerce globaux.</p></div>
                    <div class="dap-readonly-note dap-span-2"><strong>Shortcode</strong><p><code>[delicat_assistant]</code> affiche une version intégrée sur une page dédiée.</p></div>
                    <div class="dap-readonly-note dap-span-2"><strong>Centre de diagnostic</strong><p><a href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant-diagnostics')); ?>">Vérifier WordPress, WooCommerce, Firebase, Android, les tables et les tâches planifiées →</a></p></div>
                </div></section>
                <div class="dap-save-dock"><span>Les changements purgent automatiquement les caches du widget.</span><button type="submit">Enregistrer les paramètres</button></div>
            </form>
        </div>
        <?php
    }
}
