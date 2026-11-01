<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DSA2_Data {
    public static function init() {
        add_action( 'wp_ajax_dsa2_send_message', array( __CLASS__, 'ajax_send_message' ) );
        add_action( 'wp_ajax_nopriv_dsa2_send_message', array( __CLASS__, 'ajax_send_message' ) );
        add_action( 'wp_ajax_dsa2_load_messages', array( __CLASS__, 'ajax_load_messages' ) );
        add_action( 'wp_ajax_nopriv_dsa2_load_messages', array( __CLASS__, 'ajax_load_messages' ) );
        add_action( 'wp_ajax_dsa2_quick_action', array( __CLASS__, 'ajax_quick_action' ) );
        add_action( 'wp_ajax_nopriv_dsa2_quick_action', array( __CLASS__, 'ajax_quick_action' ) );
        add_action( 'wp_ajax_dsa2_mark_read', array( __CLASS__, 'ajax_mark_read' ) );
        add_action( 'wp_ajax_nopriv_dsa2_mark_read', array( __CLASS__, 'ajax_mark_read' ) );
        add_action( 'wp_ajax_dsa2_typing', array( __CLASS__, 'ajax_typing' ) );
        add_action( 'wp_ajax_nopriv_dsa2_typing', array( __CLASS__, 'ajax_typing' ) );
        add_action( 'wp_ajax_dsa2_unread', array( __CLASS__, 'ajax_unread' ) );
        add_action( 'wp_ajax_nopriv_dsa2_unread', array( __CLASS__, 'ajax_unread' ) );
        add_action( 'wp_ajax_dsa2_ping', array( __CLASS__, 'ajax_ping' ) );
        add_action( 'wp_ajax_nopriv_dsa2_ping', array( __CLASS__, 'ajax_ping' ) );
        add_action( 'wp_ajax_dsa2_get_nonce', array( __CLASS__, 'ajax_get_nonce' ) );
        add_action( 'wp_ajax_nopriv_dsa2_get_nonce', array( __CLASS__, 'ajax_get_nonce' ) );
        add_action( 'wp_ajax_dsa2_set_contact', array( __CLASS__, 'ajax_set_contact' ) );
        add_action( 'wp_ajax_nopriv_dsa2_set_contact', array( __CLASS__, 'ajax_set_contact' ) );
        add_action( 'wp_ajax_dsa2_rate', array( __CLASS__, 'ajax_rate' ) );
        add_action( 'wp_ajax_nopriv_dsa2_rate', array( __CLASS__, 'ajax_rate' ) );
        add_action( 'wp_ajax_dsa2_upload', array( __CLASS__, 'ajax_upload' ) );
        add_action( 'wp_ajax_nopriv_dsa2_upload', array( __CLASS__, 'ajax_upload' ) );
        add_action( 'wp_ajax_dsa2_find_order', array( __CLASS__, 'ajax_find_order' ) );
        add_action( 'wp_ajax_nopriv_dsa2_find_order', array( __CLASS__, 'ajax_find_order' ) );
        // Agent alert e-mail runs out of band so SMTP never blocks a customer's message.
        add_action( 'dsa2_send_agent_email', array( __CLASS__, 'send_agent_email' ) );
        add_action( 'dsa2_send_message_alert', array( __CLASS__, 'send_message_alert' ), 10, 3 );
        add_action( 'dsa2_message_created', array( __CLASS__, 'notify_new_customer_message' ), 10, 6 );
        // Logout drops local markers on shared devices. Login first folds the visitor's
        // thread into the account thread, then clears the guest cookie.
        add_action( 'wp_logout', array( __CLASS__, 'clear_session_cookies' ) );
        add_action( 'wp_login', array( __CLASS__, 'link_guest_on_login' ), 10, 2 );
    }

    public static function clear_session_cookies() {
        unset( $_COOKIE['dap_session'], $_COOKIE['dap_has_chat'] );
        if ( headers_sent() ) return;
        foreach ( array( 'dap_session', 'dap_has_chat' ) as $name ) {
            setcookie( $name, '', array(
                'expires'  => time() - YEAR_IN_SECONDS,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => 'dap_session' === $name,
                'samesite' => 'Lax',
            ) );
        }
    }

    /** Keeps a guest support thread when that same visitor signs in on the website. */
    public static function link_guest_on_login( $user_login, $user ) {
        $cookie = isset( $_COOKIE['dap_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['dap_session'] ) ) : '';
        if ( $user instanceof WP_User && preg_match( '/^[a-zA-Z0-9_-]{20,100}$/', $cookie ) ) {
            global $wpdb;
            $conversation_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}dap_conversations WHERE session_key=%s",
                'guest_' . $cookie
            ) );
            // 6.6.1 (ported from 6.2.2): a guest thread whose contact details belong to
            // someone else (a shared phone) is never merged into this account.
            $guest = $conversation_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dap_conversations WHERE id=%d", $conversation_id ) ) : null;
            if ( $guest && self::guest_may_join_account( $guest, $user ) ) self::link_conversation_to_user( $conversation_id, (int) $user->ID );
        }
        self::clear_session_cookies();
    }

    /** Small translator for server strings shown in the chat. */
    private static function t( $lang, $fr, $en, $ht ) {
        return $lang === 'en' ? $en : ( $lang === 'ht' ? $ht : $fr );
    }

    private static function request_lang() {
        $lang = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : 'fr';
        return in_array( $lang, array( 'fr', 'en', 'ht' ), true ) ? $lang : 'fr';
    }

    /** Human-readable response estimate shared by web and Android. */
    public static function agent_eta( $status = '' ) {
        $settings = (array) get_option( 'dap_settings', array() );
        $status = $status ? sanitize_key( $status ) : self::effective_status();
        if ( 'online' === $status ) return sanitize_text_field( $settings['agent_eta_online'] ?? '5–15 min' );
        if ( 'away' === $status ) return sanitize_text_field( $settings['agent_eta_away'] ?? '30–60 min' );
        $hours = sanitize_text_field( $settings['business_hours'] ?? '' );
        return $hours ? $hours : 'Dès le retour de l’équipe';
    }

    /** Sanitises browser/app page context without accepting Player IDs, PINs or free-form secrets. */
    public static function sanitize_context( $raw ) {
        if ( is_string( $raw ) ) {
            if ( strlen( $raw ) > 12000 ) return array();
            $raw = json_decode( wp_unslash( $raw ), true );
        }
        if ( ! is_array( $raw ) ) return array();
        $page_type = sanitize_key( $raw['page_type'] ?? '' );
        if ( ! in_array($page_type,array('product','checkout','cart','account','page','home','category'),true) ) $page_type='page';
        $raw_url = esc_url_raw( $raw['url'] ?? ( $raw['product']['url'] ?? '' ) );
        $site_host = strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        $url_host = strtolower((string)wp_parse_url($raw_url,PHP_URL_HOST));
        if ( $raw_url && ( !$url_host || $url_host !== $site_host ) ) $raw_url='';
        $context = array(
            'page_type' => $page_type,
            'product_id' => absint( $raw['product_id'] ?? ( $raw['product']['id'] ?? 0 ) ),
            'title' => DSA2_Compat::substr( sanitize_text_field( $raw['title'] ?? ( $raw['product']['name'] ?? '' ) ), 0, 160 ),
            'url' => $raw_url,
            'variation_id' => absint( $raw['variation_id'] ?? 0 ),
            'variation' => DSA2_Compat::substr( sanitize_text_field( $raw['variation'] ?? '' ), 0, 220 ),
            'price' => DSA2_Compat::substr( sanitize_text_field( $raw['price'] ?? ( $raw['product']['price'] ?? '' ) ), 0, 100 ),
            'plan' => DSA2_Compat::substr( sanitize_text_field( $raw['plan'] ?? '' ), 0, 140 ),
            'player_id_field' => ! empty( $raw['player_id_field'] ),
            'player_id_filled' => ! empty( $raw['player_id_filled'] ),
            'is_pin' => ! empty( $raw['is_pin'] ) || ! empty( $raw['product']['is_pin'] ),
        );
        $attrs = isset($raw['attributes']) && is_array($raw['attributes']) ? $raw['attributes'] : array();
        $safe_attrs = array();
        foreach ( array_slice($attrs,0,8,true) as $key=>$value ) {
            $key = sanitize_key($key); $value = DSA2_Compat::substr(sanitize_text_field($value),0,80);
            if ( preg_match('/(player|joueur|uid|pin|code|password|pass|otp|transcode|account|email|phone|whatsapp)/i',$key) ) continue;
            if($key && $value) $safe_attrs[$key]=$value;
        }
        $context['attributes']=$safe_attrs;
        if ( $context['product_id'] && ( ! DSA2_Products::is_public( $context['product_id'] ) || DSA2_Products::hidden_for_app( $context['product_id'] ) ) ) $context['product_id'] = 0;
        if ( $context['product_id'] && function_exists('wc_get_product') ) {
            $product=wc_get_product($context['product_id']);
            if($product){$context['title']=sanitize_text_field($product->get_name());$context['url']=get_permalink($context['product_id']);}
        }
        // Never retain values from Player ID, account, PIN, code or password fields.
        return array_filter( $context, static function($value){ return !( '' === $value || array() === $value || false === $value || 0 === $value ); } );
    }

    private static function request_context() {
        return self::sanitize_context( $_POST['context'] ?? array() );
    }

    public static function session_key() {
        if ( is_user_logged_in() ) return 'user_' . get_current_user_id();
        $cookie = isset( $_COOKIE['dap_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['dap_session'] ) ) : '';
        if ( ! $cookie || ! preg_match( '/^[a-zA-Z0-9_-]{20,100}$/', $cookie ) ) {
            $cookie = wp_generate_password( 32, false, false );
            // headers_sent() guard: a stray notice from another plugin would otherwise turn
            // this into a visible PHP warning inside the JSON response and break the chat.
            if ( ! headers_sent() ) {
                // Host-only cookie: do not force COOKIE_DOMAIN. Sites switching between
                // www/non-www or using a reverse proxy were losing the guest session on
                // admin-ajax requests after logout.
                setcookie( 'dap_session', $cookie, array(
                    'expires'  => time() + MONTH_IN_SECONDS,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ) );
            }
            $_COOKIE['dap_session'] = $cookie;
        }
        return 'guest_' . $cookie;
    }

    public static function get_or_create_conversation( $language = 'fr' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'dap_conversations';
        $key = self::session_key();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE session_key=%s", $key ) );
        $language = in_array( sanitize_key( $language ), array( 'fr', 'en', 'ht' ), true ) ? sanitize_key( $language ) : 'fr';
        // v6.2.1 — one thread per customer across surfaces. The app (API 2.6.0) keys the
        // conversation by user_id; if the customer wrote from the app first, the website
        // must continue that thread instead of opening a second one under 'user_<id>'.
        if ( ! $row && is_user_logged_in() ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $table WHERE user_id=%d ORDER BY last_message_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                get_current_user_id()
            ) );
            if ( $row ) {
                $wpdb->update( $table, array( 'session_key' => $key ), array( 'id' => (int) $row->id ) );
                $row->session_key = $key;
            }
        }
        if ( $row ) {
            if ( $row->language !== $language ) {
                $wpdb->update( $table, array( 'language' => $language ), array( 'id' => (int) $row->id ) );
                $row->language = $language;
            }
            return $row;
        }

        $user = wp_get_current_user();
        // DB-flood guard: rotating the guest cookie must not create unlimited conversation
        // rows. Kept deliberately loose for the same reason as the request limiter — a whole
        // neighbourhood shares one carrier-NAT address here, and every one of them is a guest.
        if ( 0 === strpos( $key, 'guest_' ) && self::bump_window( 'dap_newconv_' . md5( self::client_ip() ), HOUR_IN_SECONDS ) > 100 ) {
            return null;
        }
        $inserted = $wpdb->insert( $table, array(
            'session_key' => $key,
            'user_id' => get_current_user_id(),
            'customer_name' => $user && $user->exists() ? $user->display_name : '',
            'customer_email' => $user && $user->exists() ? $user->user_email : '',
            'status' => 'open',
            'language' => $language,
            'last_message_at' => current_time( 'mysql' ),
            'created_at' => current_time( 'mysql' ),
        ) );
        if ( false === $inserted || ! $wpdb->insert_id ) {
            // Parallel request created it first (UNIQUE session_key) — reuse that row.
            return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE session_key=%s", $key ) );
        }
        // Non-sensitive marker readable by JS (the session cookie is httponly, so JS can't see it for the badge check).
        if ( ! headers_sent() ) {
            setcookie( 'dap_has_chat', '1', array(
                'expires'  => time() + MONTH_IN_SECONDS,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ) );
        }
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $wpdb->insert_id ) );
    }

    /**
     * The conversation an agent should write into when they pick a customer out of the
     * directory rather than out of the inbox. Reuses the customer's most recent thread so
     * an outbound message lands in the history they already know; only creates a row when
     * that person has never chatted. Called from the admin screen, never from a visitor
     * request, so it deliberately does not touch session cookies or the guest rate limiter.
     */
    public static function conversation_for_user( $user_id, $language = 'fr' ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) return null;
        $user = get_userdata( $user_id );
        if ( ! $user ) return null;
        $language = in_array( sanitize_key( $language ), array( 'fr', 'en', 'ht' ), true ) ? sanitize_key( $language ) : 'fr';
        global $wpdb;
        $table = $wpdb->prefix . 'dap_conversations';
        // v6.2.1 — the website keys a signed-in customer's thread 'user_<id>'. Prefer that
        // row, then the most recent one for the account, and create new threads under the
        // same key so both surfaces always land on one conversation.
        $key = 'user_' . $user_id;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE user_id=%d ORDER BY (session_key=%s) DESC, last_message_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $user_id,
            $key
        ) );
        if ( $row ) {
            if ( $row->language !== $language ) {
                $wpdb->update( $table, array( 'language' => $language ), array( 'id' => (int) $row->id ) );
                $row->language = $language;
            }
            return $row;
        }

        $phone = (string) get_user_meta( $user_id, 'billing_phone', true );
        $now = current_time( 'mysql' );
        $inserted = $wpdb->insert( $table, array(
            'session_key' => $key,
            'user_id' => $user_id,
            'customer_name' => sanitize_text_field( $user->display_name ),
            'customer_email' => sanitize_email( $user->user_email ),
            'customer_phone' => sanitize_text_field( $phone ),
            'status' => 'open',
            'language' => $language,
            'last_message_at' => $now,
            'created_at' => $now,
        ) );
        if ( false === $inserted || ! $wpdb->insert_id ) {
            return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE session_key=%s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", (int) $wpdb->insert_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function add_message( $conversation_id, $sender, $body, $type='text', $meta=array(), $channel='' ) {
        global $wpdb;
        $msg = $wpdb->prefix . 'dap_messages';
        $conv = $wpdb->prefix . 'dap_conversations';
        $sender = sanitize_key( $sender );
        $type = sanitize_key( $type );
        $channel = self::message_channel( $channel, $sender, $type );
        $sender_id = 'agent' === $sender ? get_current_user_id() : 0;
        $inserted = $wpdb->insert( $msg, array(
            'conversation_id' => absint( $conversation_id ),
            'sender' => $sender,
            'sender_id' => $sender_id,
            'channel' => $channel,
            'body' => wp_kses_post( $body ),
            'message_type' => $type,
            'meta' => $meta ? wp_json_encode( $meta ) : null,
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
        // Read insert_id straight after the INSERT. It survived the UPDATE below only
        // because wpdb happens not to refresh it on UPDATE — one refactor away from
        // handing every listener the id of some other row.
        $message_id = (int) $wpdb->insert_id;
        if ( false === $inserted || ! $message_id ) return 0;
        $now = current_time( 'mysql' );
        $conversation_update = array( 'last_message_at' => $now );
        if ( 'agent' === $channel ) $conversation_update['last_agent_message_at'] = $now;
        $wpdb->update( $conv, $conversation_update, array( 'id' => absint( $conversation_id ) ) );
        do_action( 'dsa2_message_created', absint( $conversation_id ), $message_id, $sender, wp_strip_all_tags( $body ), $type, $channel );
        return $message_id;
    }

    /** Resolves a trusted bot/agent/system channel while keeping old callers compatible. */
    private static function message_channel( $channel, $sender, $type ) {
        $channel = sanitize_key( (string) $channel );
        if ( in_array( $channel, array( 'bot', 'agent', 'system' ), true ) ) return $channel;
        if ( 'agent' === $sender || in_array( $type, array( 'agent_card', 'contact_card', 'rate_card', 'rating' ), true ) ) return 'agent';
        if ( 'system' === $sender ) return 'system';
        return 'bot';
    }

    /**
     * The single reply engine, shared by the web widget and the native app.
     *
     * The mobile REST endpoint used to store the customer's message and stop there, so
     * an app user typed into total silence: no FAQ answer, no product card, no guide, no
     * escalation to a human. Both surfaces now run this one method, which means a fix to
     * the brain reaches the site and the app in the same release.
     *
     * @param object   $conv    Conversation row.
     * @param string   $body    Customer text, already sanitised.
     * @param string   $lang    fr|en|ht.
     * @param string   $intent  '', 'bot', 'agent' or 'product'.
     * @param int|null $user_id Linked account, or null to use the current WP session.
     * @return array{reply:?array,escalated:bool,message_id:int}
     */
    public static function respond_to_message( $conv, $body, $lang, $intent = '', $user_id = null, $context = array() ) {
        $uid         = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;
        $live        = self::is_live_agent_thread( $conv );
        $force_bot   = ( 'bot' === $intent );
        $force_agent = ( 'agent' === $intent );
        $context = self::sanitize_context( $context );

        // Customer wrote while an agent conversation is live: ping the agent's phone (max 1/min).
        if ( ( $force_agent || ( $live && ! $force_bot ) ) && ! get_transient( 'dap_wh_' . (int) $conv->id ) ) {
            set_transient( 'dap_wh_' . (int) $conv->id, 1, MINUTE_IN_SECONDS );
            self::fire_webhook( $conv, 'customer_message', $body );
        }

        if ( $force_agent ) {
            if ( ! self::is_identified( $conv, $uid ) ) {
                $reply = self::contact_request_payload( $lang );
            } elseif ( $live ) {
                // Already escalated and waiting on (or talking to) a human. Re-issuing the
                // handoff card on every single message buried the agent's real answers
                // under a wall of identical "Un agent a été prévenu" cards.
                $reply = null;
            } else {
                $reply = self::quick_action_payload( 'agent', $lang, $uid, $context );
            }
        } elseif ( 'product' === $intent ) {
            $reply = self::product_search_reply( $body, $lang );
        } else {
            $reply = self::smart_reply( $body, $lang, $force_bot ? false : $live, $uid, $context, $conv );
        }

        // Guests must leave a name + contact before reaching a human.
        if ( $reply && 'agent_card' === $reply['type'] && ! self::is_identified( $conv, $uid ) ) {
            $reply = self::contact_request_payload( $lang );
        }

        $message_id = 0;
        if ( $reply ) {
            // Asking for a human again must always re-confirm. Small talk gets a short
            // window too: a customer who writes "mèsi" twice deserves an answer twice,
            // and the engine varies the wording so it is not the same sentence anyway.
            $is_small_talk = ( 'small_talk_v3' === ( $reply['meta']['source'] ?? '' ) );
            $window = in_array( $reply['type'], array( 'agent_card', 'contact_card' ), true ) ? 20
                : ( $is_small_talk ? 8 : 120 );
            if ( ! self::is_duplicate_assistant_reply( $conv->id, $reply['body'], $window ) ) {
                $reply_channel = in_array( $reply['type'], array( 'agent_card', 'contact_card', 'rate_card' ), true ) ? 'agent' : 'bot';
                $message_id = self::add_message( $conv->id, 'assistant', $reply['body'], $reply['type'], $reply['meta'], $reply_channel );
            }
        }
        $escalated = ( $reply && 'agent_card' === $reply['type'] );
        if ( $escalated ) self::escalate( $conv );

        return array( 'reply' => $reply, 'escalated' => $escalated, 'message_id' => $message_id );
    }

    /**
     * Folds one conversation into another and deletes the empty shell.
     *
     * The app bootstraps into its own "app_<installation>" thread. Once the customer
     * proves who they are, that thread and the "user_<id>" thread they use on the website
     * are the same person having one conversation — but they were two separate rows, so
     * an agent replying on the site was invisible in the app and vice versa.
     */
    public static function merge_conversations( $from_id, $into_id ) {
        global $wpdb;
        $from = absint( $from_id ); $into = absint( $into_id );
        if ( ! $from || ! $into || $from === $into ) return false;
        $conv = $wpdb->prefix . 'dap_conversations';
        $msg  = $wpdb->prefix . 'dap_messages';

        // Message ids are globally sequential, so re-parenting is enough to interleave
        // the two histories in the right order.
        $wpdb->query( $wpdb->prepare( "UPDATE $msg SET conversation_id=%d WHERE conversation_id=%d", $into, $from ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value=%d WHERE meta_key='dap_conversation_id' AND meta_value=%s", $into, (string) $from ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dap_mobile_sessions SET conversation_id=%d WHERE conversation_id=%d", $into, $from ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dap_mobile_devices SET conversation_id=%d WHERE conversation_id=%d", $into, $from ) );

        // Carry the stronger of the two states over before the shell disappears.
        $old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $conv WHERE id=%d", $from ) );
        $new = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $conv WHERE id=%d", $into ) );
        if ( $old && $new ) {
            $update = array();
            if ( '' === (string) $new->customer_name  && '' !== (string) $old->customer_name )  $update['customer_name']  = $old->customer_name;
            if ( '' === (string) $new->customer_phone && '' !== (string) $old->customer_phone ) $update['customer_phone'] = $old->customer_phone;
            if ( 'waiting' === $old->status && 'waiting' !== $new->status ) $update['status'] = 'waiting';
            if ( ! (int) $new->assigned_to && (int) $old->assigned_to ) $update['assigned_to'] = (int) $old->assigned_to;
            $priorities = array( 'low'=>0, 'normal'=>1, 'high'=>2, 'urgent'=>3 );
            $old_priority = isset( $priorities[ $old->priority ] ) ? $priorities[ $old->priority ] : 1;
            $new_priority = isset( $priorities[ $new->priority ] ) ? $priorities[ $new->priority ] : 1;
            if ( $old_priority > $new_priority ) $update['priority'] = $old->priority;
            $update['last_customer_read_id'] = max( (int) $old->last_customer_read_id, (int) $new->last_customer_read_id );
            $update['last_agent_read_id'] = max( (int) $old->last_agent_read_id, (int) $new->last_agent_read_id );
            $last = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created_at) FROM $msg WHERE conversation_id=%d", $into ) );
            if ( $last ) $update['last_message_at'] = $last;
            $last_agent = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created_at) FROM $msg WHERE conversation_id=%d AND channel='agent'", $into ) );
            if ( $last_agent ) $update['last_agent_message_at'] = $last_agent;
            if ( $update ) $wpdb->update( $conv, $update, array( 'id' => $into ) );
        }
        $wpdb->delete( $conv, array( 'id' => $from ), array( '%d' ) );
        return true;
    }

    /**
     * Attaches a conversation to an account, reusing that account's existing thread when
     * there is one. Returns the id of the surviving conversation.
     */
    /**
     * 6.6.1 (ported from 6.2.2): true only when the guest thread's contact details prove it
     * is this account's owner: the same e-mail (any case), or a phone ending with the same
     * 8 digits as the billing phone (guests type 8 bare digits, billing phones often carry
     * +509 or 509). A contact detail that belongs to someone else wins over a match on the
     * other one.
     */
    private static function guest_matches_account( $guest, $user ) {
        if ( ! $guest || ! ( $user instanceof WP_User ) ) return false;
        $guest_email = strtolower( trim( (string) ( $guest->customer_email ?? '' ) ) );
        $guest_phone = (string) preg_replace( '/\D+/', '', (string) ( $guest->customer_phone ?? '' ) );
        $email_match = '' !== $guest_email && hash_equals( strtolower( trim( (string) $user->user_email ) ), $guest_email );
        $billing     = (string) preg_replace( '/\D+/', '', (string) get_user_meta( (int) $user->ID, 'billing_phone', true ) );
        $phone_match = strlen( $guest_phone ) >= 8 && strlen( $billing ) >= 8 && substr( $guest_phone, -8 ) === substr( $billing, -8 );
        if ( '' !== $guest_email && ! $email_match ) return false;
        if ( '' !== $guest_phone && ! $phone_match ) return false;
        return $email_match || $phone_match;
    }

    /** The cookie path: a thread with no contact details is the visitor's own; one with details must match. */
    private static function guest_may_join_account( $guest, $user ) {
        $has_details = '' !== trim( (string) ( $guest->customer_email ?? '' ) ) || '' !== trim( (string) ( $guest->customer_phone ?? '' ) );
        return ! $has_details || self::guest_matches_account( $guest, $user );
    }

    public static function link_conversation_to_user( $conversation_id, $user_id ) {
        global $wpdb;
        $cid = absint( $conversation_id ); $uid = absint( $user_id );
        if ( ! $cid || ! $uid ) return $cid;
        $conv = $wpdb->prefix . 'dap_conversations';
        $key  = 'user_' . $uid;
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $conv WHERE session_key=%s", $key ) );
        if ( $existing && (int) $existing->id !== $cid ) {
            self::merge_conversations( $cid, (int) $existing->id );
            $wpdb->update( $conv, array( 'user_id' => $uid ), array( 'id' => (int) $existing->id ), array( '%d' ), array( '%d' ) );
            return (int) $existing->id;
        }
        // No website thread yet: claim the key so a later browser sign-in lands here.
        $user = get_userdata( $uid );
        $wpdb->update( $conv, array(
            'user_id'        => $uid,
            'session_key'    => $key,
            'customer_name'  => $user ? $user->display_name : '',
            'customer_email' => $user ? $user->user_email : '',
        ), array( 'id' => $cid ), array( '%d', '%s', '%s', '%s' ), array( '%d' ) );
        return $cid;
    }

    /**
     * Signs one installation out without detaching every other device from the account.
     * The old implementation re-keyed the shared account conversation itself, leaving all
     * of its messages on the signed-out phone and disconnecting the website plus every other
     * device. A new empty conversation is now created and only this installation moves.
     *
     * @return int New private conversation id, or 0 on failure.
     */
    public static function unlink_conversation( $conversation_id, $installation_id = '', $language = 'fr' ) {
        global $wpdb;
        $old = absint( $conversation_id );
        $installation = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $installation_id );
        if ( ! $old || strlen( $installation ) < 16 ) return 0;

        $conv = $wpdb->prefix . 'dap_conversations';
        $sessions = $wpdb->prefix . 'dap_mobile_sessions';
        $devices = $wpdb->prefix . 'dap_mobile_devices';
        $language = in_array( $language, array( 'fr', 'en', 'ht' ), true ) ? $language : 'fr';
        $key = class_exists( 'DSA2_Mobile_API' ) ? DSA2_Mobile_API::installation_key( $installation ) : 'app_' . substr( hash( 'sha256', $installation ), 0, 48 );
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $conv WHERE session_key=%s", $key ) ) ) {
            $key = 'app_detached_' . substr( hash( 'sha256', $installation . '|' . wp_generate_password( 20, false, false ) ), 0, 40 );
        }

        $inserted = $wpdb->insert( $conv, array(
            'session_key'     => $key,
            'user_id'         => 0,
            'status'          => 'open',
            'language'        => $language,
            'last_message_at' => current_time( 'mysql' ),
            'created_at'      => current_time( 'mysql' ),
        ), array( '%s', '%d', '%s', '%s', '%s', '%s' ) );
        $new = $inserted ? (int) $wpdb->insert_id : 0;
        if ( ! $new ) return 0;

        $wpdb->update( $sessions, array( 'conversation_id' => $new ), array( 'installation_id' => $installation ), array( '%d' ), array( '%s' ) );
        $wpdb->update( $devices, array( 'conversation_id' => $new ), array( 'installation_id' => $installation ), array( '%d' ), array( '%s' ) );
        // Devices registered before v5 had no installation id. They cannot be safely
        // attributed after sign-out, so remove them from the old account thread.
        $wpdb->delete( $devices, array( 'conversation_id' => $old, 'installation_id' => '' ), array( '%d', '%s' ) );
        return $new;
    }

    /**
     * Stores an assistant card (a quick action, a tapped guide, a FAQ answer) against a
     * conversation, applying the same guest-contact gate, duplicate suppression and
     * escalation the typed path uses. Shared by the widget and the REST app.
     *
     * @return array{reply:?array,escalated:bool,message_id:int}
     */
    public static function store_assistant_payload( $conv, $payload, $user_id = null ) {
        if ( ! $conv || ! $payload || empty( $payload['type'] ) ) {
            return array( 'reply' => null, 'escalated' => false, 'message_id' => 0 );
        }
        $uid  = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;
        $lang = in_array( (string) ( $conv->language ?? 'fr' ), array( 'fr', 'en', 'ht' ), true ) ? (string) $conv->language : 'fr';

        // Guests must leave a name + contact before reaching a human.
        if ( 'agent_card' === $payload['type'] && ! self::is_identified( $conv, $uid ) ) {
            $payload = self::contact_request_payload( $lang );
        }
        $message_id = 0;
        if ( ! self::is_duplicate_assistant_reply( $conv->id, $payload['body'], 20 ) ) {
            $payload_channel = in_array( $payload['type'], array( 'agent_card', 'contact_card', 'rate_card' ), true ) ? 'agent' : 'bot';
            $message_id = self::add_message( $conv->id, 'assistant', $payload['body'], $payload['type'], $payload['meta'], $payload_channel );
        }
        $escalated = ( 'agent_card' === $payload['type'] );
        if ( $escalated ) self::escalate( $conv );
        return array( 'reply' => $payload, 'escalated' => $escalated, 'message_id' => $message_id );
    }

    /**
     * Whether we know how to reach this customer. The web widget could rely on
     * is_user_logged_in(), but an app session carries its identity on the conversation
     * row instead, so both surfaces have to ask the same question this way.
     */
    private static function is_identified( $conv, $user_id = null ) {
        $uid = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;
        if ( $uid > 0 ) return true;
        if ( (int) ( $conv->user_id ?? 0 ) > 0 ) return true;
        return self::has_contact( $conv );
    }

    /**
     * $after_id > 0 → the next page of new messages (polling).
     * $after_id = 0 → the *newest* 100, re-sorted oldest-first.
     *
     * The initial load used to take the oldest 100 rows, so a customer with a long
     * history (conversations are keyed to the account and never rotate) reopened the
     * chat on messages from months ago and had to wait one poll per 100 rows for the
     * recent ones to arrive.
     */
    public static function messages( $conversation_id, $after_id=0, $channel='', $limit=100 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'dap_messages';
        $channel = in_array( $channel, array( 'bot', 'agent', 'system' ), true ) ? $channel : '';
        $limit = max( 1, min( 200, absint( $limit ) ) );
        $channel_sql = $channel ? $wpdb->prepare( ' AND channel=%s', $channel ) : '';
        if ( $after_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $table WHERE conversation_id=%d AND id>%d $channel_sql ORDER BY id ASC LIMIT %d",
                absint( $conversation_id ), absint( $after_id ), $limit
            ) );
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE conversation_id=%d $channel_sql ORDER BY id DESC LIMIT %d",
            absint( $conversation_id ), $limit
        ) );
        return array_reverse( (array) $rows );
    }

    /**
     * True for the automatic order-status receipts written by DSA2_System_Sync before
     * v6.2.0. Rows already in the database still load, so they are filtered here as well
     * as being purged on upgrade — a customer whose thread was flooded sees it clean on
     * the very next open, whether or not the cleanup query has run yet.
     */
    private static function is_order_receipt( $row ) {
        if ( 'system' !== (string) $row->sender || 'system' !== (string) $row->message_type ) return false;
        if ( empty( $row->meta ) ) return false;
        $meta = json_decode( (string) $row->meta, true );
        return is_array( $meta ) && ! empty( $meta['order'] );
    }

    private static function strip_order_receipts( $rows ) {
        $s = get_option( 'dap_settings', array() );
        if ( ! empty( $s['order_updates_in_chat'] ) ) return $rows;
        $kept = array();
        foreach ( (array) $rows as $row ) {
            if ( ! self::is_order_receipt( $row ) ) $kept[] = $row;
        }
        return $kept;
    }

    /**
     * 6.6.3 — Google Play: a Personal developer account may not offer financial services.
     * The app and the website read one shared history, and rows written before 6.6.3 (or
     * by the website widget) can hold what the app must never show: the retired « Change
     * et transfert » guide, a product the app hides (« Exchange » / « Finance », crypto) or
     * an automated answer naming one. Retired guides go on every surface, since the guide
     * no longer exists; the rest only while the app is reading. Only automated rows
     * (sender 'assistant') are looked at: a customer's or an agent's words are never changed.
     * A « je n'ai pas compris » card that quotes such a question loses the quote instead of
     * being dropped, so the customer's message never stays without a reply.
     */
    private static function strip_hidden_for_app( $rows ) {
        $app  = class_exists( 'DSA2_Products', false ) && DSA2_Products::app_surface();
        $kept = array();
        foreach ( (array) $rows as $row ) {
            if ( ! is_object( $row ) || 'assistant' !== (string) ( $row->sender ?? '' ) ) { $kept[] = $row; continue; }
            $type = (string) ( $row->message_type ?? '' );
            $body = (string) ( $row->body ?? '' );
            $meta = ! empty( $row->meta ) ? json_decode( (string) $row->meta, true ) : array();
            $meta = is_array( $meta ) ? $meta : array();
            $changed = false;

            if ( 'guide_card' === $type && class_exists( 'DSA2_Guides', false )
                && ( DSA2_Guides::is_retired( $meta['guide'] ?? '' ) || ( 0 === strpos( $body, 'guide:' ) && DSA2_Guides::is_retired( substr( $body, 6 ) ) ) ) ) {
                continue;
            }
            if ( 'guide_menu' === $type && class_exists( 'DSA2_Guides', false ) && ! empty( $meta['items'] ) && is_array( $meta['items'] ) ) {
                $items = array_values( array_filter( $meta['items'], static function( $item ) {
                    return ! ( is_array( $item ) && DSA2_Guides::is_retired( $item['id'] ?? '' ) );
                } ) );
                if ( count( $items ) !== count( $meta['items'] ) ) { $meta['items'] = $items; $changed = true; }
            }
            if ( $app ) {
                if ( 'product_list' === $type ) {
                    // The product a card is about travels in its body, not always in its items:
                    // reco:p{id} (« Avec X, les clients prennent aussi : » + other products),
                    // context:{id}, product_context:{id}:…
                    if ( preg_match( '/^(?:reco:p|context:|product_context:)(\d+)/', $body, $about ) && DSA2_Products::hidden_for_app( (int) $about[1] ) ) continue;
                    if ( ! empty( $meta['items'] ) && is_array( $meta['items'] ) ) {
                        $items = array_values( array_filter( $meta['items'], static function( $item ) {
                            return ! ( is_array( $item ) && DSA2_Products::hidden_for_app( $item['id'] ?? 0 ) );
                        } ) );
                        if ( ! $items ) continue;
                        if ( count( $items ) !== count( $meta['items'] ) ) { $meta['items'] = $items; $changed = true; }
                    }
                }
                if ( 'help_card' === $type && 0 === strpos( $body, 'misunderstood:' )
                    && preg_match( DSA2_Products::APP_HIDDEN_ANSWER_WORDS, wp_json_encode( $meta ) ) ) {
                    $meta['intro'] = self::misunderstood_intro( self::misunderstood_lang( $meta ), '' );
                    $changed = true;
                }
                // A product_list body is an internal key that holds the customer's search words
                // (« products:netflix ou paypal 777 »); its items were checked by id above.
                $scan = ( 'product_list' === $type ? '' : $body ) . ' ' . wp_json_encode( $meta );
                if ( preg_match( DSA2_Products::APP_HIDDEN_ANSWER_WORDS, $scan ) ) continue;
            }
            if ( $changed ) {
                $row = clone $row;
                $row->meta = wp_json_encode( $meta );
            }
            $kept[] = $row;
        }
        return $kept;
    }

    /** Newest useful history from both workspaces, without one channel evicting the other. */
    public static function client_messages( $conversation_id, $after_id=0 ) {
        if ( $after_id > 0 ) return self::strip_hidden_for_app( self::strip_order_receipts( self::messages( $conversation_id, $after_id, '', 150 ) ) );
        $rows = array_merge(
            self::messages( $conversation_id, 0, 'bot', 60 ),
            self::messages( $conversation_id, 0, 'agent', 120 ),
            self::messages( $conversation_id, 0, 'system', 30 )
        );
        $unique = array();
        foreach ( $rows as $row ) $unique[ (int) $row->id ] = $row;
        ksort( $unique, SORT_NUMERIC );
        return self::strip_hidden_for_app( self::strip_order_receipts( array_values( $unique ) ) );
    }

    /**
     * Deletes the screenshots attached to the given conversations.
     *
     * Chat uploads are written straight to the uploads folder (no media-library
     * attachment), so nothing else in WordPress knows about them: pruning or erasing a
     * conversation left the customer's TransCode receipts on disk forever. Only files
     * inside the uploads directory whose name matches the generated "dap-" pattern are
     * removed, so a hand-edited meta URL cannot point this at anything else.
     */
    public static function delete_conversation_images( $conversation_ids ) {
        $ids = array_filter( array_map( 'absint', (array) $conversation_ids ) );
        if ( ! $ids ) return 0;
        global $wpdb;
        $in = implode( ',', $ids );
        $rows = $wpdb->get_results( "SELECT message_type,meta FROM " . $wpdb->prefix . "dap_messages WHERE message_type IN ('image','attachment') AND conversation_id IN ($in)" ); // phpcs:ignore
        if ( ! $rows ) return 0;

        $uploads = wp_get_upload_dir();
        if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) return 0;
        $basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
        // Compare on the URL path, not the full base URL: matching the literal baseurl
        // string breaks after an http→https switch or a domain change, and those are exactly
        // the sites with the most orphans. The host is ignored on purpose; safety comes from
        // confining the result to basedir and requiring the generated dap-<20 chars> name.
        $base_path = trailingslashit( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) );
        if ( '/' === $base_path || '' === $base_path ) return 0;
        $deleted = 0;
        foreach ( $rows as $row ) {
            $meta = json_decode( (string) $row->meta, true );
            if ( 'attachment' === $row->message_type && ! empty( $meta['attachment_id'] ) ) {
                if ( wp_delete_attachment( absint( $meta['attachment_id'] ), true ) ) $deleted++;
                continue;
            }
            if ( empty( $meta['url'] ) ) continue;
            $url_path = (string) wp_parse_url( (string) $meta['url'], PHP_URL_PATH );
            if ( '' === $url_path || 0 !== strpos( $url_path, $base_path ) ) continue; // not in this uploads folder
            $path = wp_normalize_path( $basedir . substr( $url_path, strlen( $base_path ) ) );
            if ( 0 !== strpos( $path, $basedir ) || false !== strpos( $path, '..' ) ) continue;
            if ( ! preg_match( '#/dap-[A-Za-z0-9]{20}\.(jpg|png|gif|webp)$#', $path ) ) continue;
            if ( is_file( $path ) && @unlink( $path ) ) $deleted++;
        }
        return $deleted;
    }

    private static function client_ip() {
        // Never trust a forwarded header here: unless the proxy address is verified, an
        // attacker can rotate CF-Connecting-IP/X-Forwarded-For and mint unlimited counters.
        // Hosts using a trusted-proxy plugin normally rewrite REMOTE_ADDR before WordPress.
        return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    }

    // Fixed-window counter: returns the hit count for the current window.
    private static function bump_window( $key, $window ) {
        $d = get_transient( $key );
        if ( ! is_array( $d ) || ! isset( $d['t'] ) || ( time() - (int) $d['t'] ) > $window ) {
            $d = array( 'c' => 0, 't' => time() );
        }
        $d['c']++;
        set_transient( $key, $d, 2 * $window );
        return (int) $d['c'];
    }

    private static function verify_request() {
        nocache_headers();
        header( 'Vary: Cookie', false );
        $lang = self::request_lang();
        if ( ! check_ajax_referer( 'dsa2_frontend', 'nonce', false ) ) {
            // Recoverable: page cache (LiteSpeed/Cloudflare) can serve an expired nonce. JS refreshes and retries.
            wp_send_json_error( array( 'code' => 'bad_nonce', 'message' => self::t( $lang, 'Session expirée. Veuillez réessayer.', 'Session expired. Please try again.', 'Sesyon an ekspire. Tanpri eseye ankò.' ) ), 403 );
        }
        // Per-session limit (normal use) + per-IP limit: without it, a guest rotating/omitting the
        // session cookie gets a fresh counter on every request and the limiter is a no-op.
        $session_hits = self::bump_window( 'dap_rate_' . md5( self::session_key() . '|' . self::client_ip() ), MINUTE_IN_SECONDS );
        $ip_hits      = self::bump_window( 'dap_rate_ip_' . md5( self::client_ip() ), MINUTE_IN_SECONDS );
        if ( $session_hits > 120 || $ip_hits > 600 ) { // IP cap is loose on purpose: many customers share carrier-NAT IPs in Haiti.
            wp_send_json_error( array( 'code' => 'rate_limited', 'message' => self::t( $lang, 'Trop de demandes. Réessayez dans une minute.', 'Too many requests. Try again in a minute.', 'Twòp demann. Eseye ankò nan yon minit.' ) ), 429 );
        }
    }

    public static function ajax_get_nonce() {
        nocache_headers();
        header( 'Vary: Cookie', false );
        // Guests hit this whenever a page cached longer than the nonce lifetime hands them an
        // expired nonce — so on a busy store every guest behind one carrier-NAT address needs
        // a refresh. At 30/minute they exhausted it between them and the chat stopped working
        // for logged-out visitors only. Still cheap: this endpoint just mints a nonce.
        if ( self::bump_window( 'dap_rate_nonce_' . md5( self::client_ip() ), MINUTE_IN_SECONDS ) > 120 ) {
            wp_send_json_error( array( 'message' => 'Too many requests.' ), 429 );
        }
        wp_send_json_success( array( 'nonce' => wp_create_nonce( 'dsa2_frontend' ) ) );
    }

    public static function ajax_send_message() {
        self::verify_request();
        $lang = self::request_lang();
        $body = isset( $_POST['message'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ) : '';
        if ( $body === '' ) wp_send_json_error( array( 'message' => self::t( $lang, 'Message vide.', 'Empty message.', 'Mesaj la vid.' ) ), 400 );
        if ( DSA2_Compat::length( $body ) > 1500 ) wp_send_json_error( array( 'message' => self::t( $lang, 'Message trop long.', 'Message too long.', 'Mesaj la twò long.' ) ), 400 );

        $after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
        $intent = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : '';
        if ( ! in_array( $intent, array( 'bot', 'agent', 'product' ), true ) ) $intent = '';
        $context = self::request_context();
        $conv = self::get_or_create_conversation( $lang );
        if ( ! $conv ) wp_send_json_error( array( 'code'=>'rate_limited', 'message' => self::service_busy( $lang ) ), 429 );
        $client_message_id = isset( $_POST['client_message_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_message_id'] ) ) : '';
        if ( $client_message_id && ! preg_match( '/^[A-Za-z0-9._-]{8,80}$/', $client_message_id ) ) $client_message_id = '';
        if ( $client_message_id && self::find_client_message( (int) $conv->id, $client_message_id ) ) {
            wp_send_json_success( array(
                'messages' => self::format_messages( self::client_messages( $conv->id, $after ) ),
                'agent_read_id' => (int) $conv->last_agent_read_id,
                'client_message_id' => $client_message_id,
                'deduplicated' => true,
            ) );
        }
        // No esc_html here: add_message() sanitizes and the JS escapes on render — double-escaping showed &#039; in the chat.
        $channel = ( 'agent' === $intent || ( '' === $intent && self::is_live_agent_thread( $conv ) ) ) ? 'agent' : 'bot';
        $message_meta = $context ? array( 'context' => $context ) : array();
        if ( $client_message_id ) $message_meta['client_message_id'] = $client_message_id;
        $message_id = self::add_message( $conv->id, 'customer', $body, 'text', $message_meta, $channel );
        if ( ! $message_id ) wp_send_json_error( array( 'message' => self::service_busy( $lang ) ), 500 );
        delete_transient( 'dap_customer_typing_' . (int) $conv->id );

        // The home search box always means "find this product"; typed chat goes through
        // the full brain — quiet fallback while an agent conversation is in progress.
        // Identical call to the one the native app makes, so both stay in step.
        self::respond_to_message( $conv, $body, $lang, $intent, null, $context );

        wp_send_json_success( array(
            'messages' => self::format_messages( self::client_messages( $conv->id, $after ) ),
            'agent_read_id' => (int) $conv->last_agent_read_id,
            'client_message_id' => $client_message_id,
        ) );
    }

    /** Retrying a timed-out write must not create a second customer message. */
    private static function find_client_message( $conversation_id, $client_message_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id,meta FROM {$wpdb->prefix}dap_messages WHERE conversation_id=%d AND sender='customer' ORDER BY id DESC LIMIT 15",
            absint( $conversation_id )
        ) );
        foreach ( (array) $rows as $row ) {
            $meta = $row->meta ? json_decode( $row->meta, true ) : array();
            if ( is_array( $meta ) && isset( $meta['client_message_id'] ) && hash_equals( (string) $meta['client_message_id'], $client_message_id ) ) {
                return (int) $row->id;
            }
        }
        return 0;
    }

    /**
     * A thread is "live" while it waits for a human, and also once an agent has taken it
     * over (assigned + still open). Without the second case, every customer message after
     * the agent's first reply lost its phone ping and let the bot talk over the agent.
     */
    /**
     * 6.6.1 (ported from 6.2.2): a thread is "live" while it waits for a human, and
     * once an agent has taken it over -- but only with real human activity inside the
     * TTL (24 h by default, filter delicat_app_chat_live_ttl, 0 or less for no limit).
     * Without the TTL a customer an agent once answered lost the bot for good. Same
     * rule as the Delicat App API bridge, so the app's header matches what happens
     * to the next message. Public: the bridge and the chat-files handler call it.
     */
    public static function is_live_agent_thread( $conv ) {
        if ( ! $conv ) return false;
        $status   = (string) ( $conv->status ?? '' );
        $assigned = (int) ( $conv->assigned_to ?? 0 );
        if ( 'waiting' !== $status && ! ( 'open' === $status && $assigned > 0 ) ) return false;
        $ttl = (int) apply_filters( 'delicat_app_chat_live_ttl', DAY_IN_SECONDS, $conv );
        if ( $ttl <= 0 || empty( $conv->id ) ) return true;
        global $wpdb;
        // created_at is site time (current_time('mysql')), so the cutoff is site time too.
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dap_messages WHERE conversation_id=%d AND ( sender='agent' OR ( channel='agent' AND message_type='agent_card' ) ) AND created_at > %s LIMIT 1",
            (int) $conv->id,
            wp_date( 'Y-m-d H:i:s', time() - $ttl )
        ) );
    }

    /** Marks the thread as waiting for a human and notifies the team once per window. */
    private static function escalate( $conv ) {
        if ( ! $conv ) return;
        global $wpdb;
        // Always reopen — a resolved thread where the customer asks again must reappear in "En attente".
        $update = array( 'status' => 'waiting' );
        if ( 'resolved' === (string) $conv->status ) $update['assigned_to'] = 0;
        $wpdb->update( $wpdb->prefix . 'dap_conversations', $update, array( 'id' => (int) $conv->id ) );
        // Tapping "Parler à un agent" five times used to fire five webhooks.
        $key = 'dap_wh_esc_' . (int) $conv->id;
        if ( ! get_transient( $key ) ) {
            set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );
            self::fire_webhook( $conv, 'agent_requested', self::last_customer_message( $conv->id ) );
        }
        self::notify_agent( $conv );
    }

    /** Opens an existing conversation in the human queue and runs its alert workflow. */
    public static function request_agent( $conversation_id ) {
        global $wpdb;
        $conv = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dap_conversations WHERE id=%d",
            absint( $conversation_id )
        ) );
        if ( ! $conv ) return false;
        self::escalate( $conv );
        return true;
    }

    /** Ask for feedback once when a human agent closes an active support conversation. */
    public static function request_feedback( $conversation ) {
        if ( ! $conversation || empty( $conversation->id ) ) return false;
        $language = isset( $conversation->language ) && in_array( $conversation->language, array( 'fr', 'en', 'ht' ), true )
            ? $conversation->language : 'fr';
        $payload = self::quick_action_payload( 'end_chat', $language, (int) ( $conversation->user_id ?? 0 ) );
        if ( ! $payload ) return false;
        self::store_assistant_payload( $conversation, $payload, (int) ( $conversation->user_id ?? 0 ) );
        return true;
    }

    /**
     * Fires a signed webhook so the Delicat notification app (or SMS forwarder)
     * can push an alert to the agent's phone. Non-blocking: never slows the chat.
     */
    public static function last_customer_message( $conversation_id ) {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( "SELECT body FROM " . $wpdb->prefix . "dap_messages WHERE conversation_id=%d AND sender='customer' ORDER BY id DESC LIMIT 1", absint( $conversation_id ) ) );
    }

    private static function fire_webhook( $conv, $event, $preview = '' ) {
        $s = get_option( 'dap_settings', array() );
        $url = isset( $s['webhook_url'] ) ? trim( (string) $s['webhook_url'] ) : '';
        if ( ! $url || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! wp_http_validate_url( $url ) ) return;
        $payload = wp_json_encode( array(
            'event'           => $event,
            'conversation_id' => (int) $conv->id,
            'customer'        => $conv->customer_name ? $conv->customer_name : 'Visiteur',
            'contact'         => $conv->customer_email ? $conv->customer_email : ( isset( $conv->customer_phone ) ? $conv->customer_phone : '' ),
            'language'        => $conv->language,
            'preview'         => DSA2_Compat::substr( self::redact_secrets( wp_strip_all_tags( html_entity_decode( (string) $preview, ENT_QUOTES, 'UTF-8' ) ) ), 0, 200 ),
            'admin_url'       => admin_url( 'admin.php?page=delicat-assistant&conversation=' . (int) $conv->id ),
            'site'            => home_url( '/' ),
            'time'            => current_time( 'mysql' ),
        ) );
        $secret = isset( $s['webhook_secret'] ) ? (string) $s['webhook_secret'] : '';
        $headers = array( 'Content-Type' => 'application/json' );
        if ( $secret !== '' ) $headers['X-Delicat-Signature'] = hash_hmac( 'sha256', $payload, $secret );
        wp_safe_remote_post( $url, array( 'timeout' => 2, 'blocking' => false, 'redirection' => 0, 'headers' => $headers, 'body' => $payload ) );
    }

    private static function notify_agent( $conv ) {
        $s = get_option( 'dap_settings', array() );
        if ( empty( $s['notify_enabled'] ) ) return;
        // A customer message fires its own instant alert through notify_new_customer_message().
        // This short window only stops the escalation alert from duplicating it in the same
        // request; it is no longer the 15-minute silence that used to swallow follow-ups.
        $key = 'dap_notify_' . (int) $conv->id;
        if ( get_transient( $key ) ) return;
        set_transient( $key, 1, 45 );

        // wp_mail() is synchronous: a slow SMTP host added seconds to the customer's
        // request, right at the moment they are waiting for an answer. Hand it to cron and
        // fall back to sending inline only if scheduling is unavailable.
        if ( false !== wp_schedule_single_event( time(), 'dsa2_send_agent_email', array( (int) $conv->id ) ) ) return;
        self::send_agent_email( (int) $conv->id );
    }

    /**
     * v6.2.0 — instant alert on every customer message addressed to the team.
     *
     * Before this, one e-mail went out when a thread escalated and then nothing for the
     * next fifteen minutes, so the second, third and fourth message of a conversation —
     * usually the ones carrying the TransCode or the screenshot — arrived silently. The
     * team only found them by opening the inbox.
     *
     * Fires from the dsa2_message_created hook, so the web widget, the Android app and
     * image uploads all alert through the same path. Throttling is a setting and defaults
     * to zero: every message, immediately.
     */
    /**
     * 6.6.1 (ported from 6.2.2): strip what looks like a secret before a message leaves
     * the inbox in an e-mail, webhook or push preview: long digit runs (PINs, TransCodes,
     * card numbers), long code-like tokens, and anything written after
     * "pin/code/mot de passe/password/otp".
     */
    public static function redact_secrets( $text ) {
        $text = (string) $text;
        $text = preg_replace( '/\b(pin|code|mot\s+de\s+passe|password|passcode|otp|transcode)\b(\s*(?:[:=]\s*|(?:est|is|c\'est)\s+)?)(\S+)/iu', '$1$2••••', $text );
        $text = preg_replace( '/\b\d{6,}\b/u', '••••', $text );
        $text = preg_replace( '/\b(?=[A-Z0-9-]*\d)[A-Z0-9]{4,}(?:-[A-Z0-9]{3,}){1,}\b/u', '••••', $text );
        $text = preg_replace( '/\b(?=[A-Za-z0-9]*\d)(?=[A-Za-z0-9]*[A-Za-z])[A-Za-z0-9]{12,}\b/u', '••••', $text );
        return $text;
    }

    /** 6.6.1 (ported from 6.2.2): the conversations an account deletion must erase. */
    public static function erasable_conversation_ids( $user_id, $email = '' ) {
        global $wpdb;
        $user_id = absint( $user_id );
        $email   = strtolower( trim( (string) $email ) );
        if ( '' === $email && $user_id ) {
            $user  = get_userdata( $user_id );
            $email = $user ? strtolower( trim( (string) $user->user_email ) ) : '';
        }
        $table = $wpdb->prefix . 'dap_conversations';
        $ids   = array();
        if ( $user_id ) {
            $ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE user_id=%d OR session_key=%s", $user_id, 'user_' . $user_id ) );
        }
        if ( '' !== $email && is_email( $email ) ) {
            $ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE user_id=0 AND LOWER(customer_email)=%s", $email ) ) );
        }
        return array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) );
    }

    /**
     * 6.6.1 (ported from 6.2.2): erase a customer's support history -- Google Play account
     * deletion and the WordPress privacy eraser: messages, uploaded files, internal notes,
     * tickets, mobile sessions with their push preferences, and the conversation rows.
     */
    public static function erase_customer_data( $user_id, $email = '' ) {
        global $wpdb;
        // 7.0: community data (group messages, images, memberships, reports, group name) too.
        if ( absint( $user_id ) ) {
            if ( ! class_exists( 'DSA2_Groups', false ) && function_exists( 'dsa2_require_file' ) ) dsa2_require_file( 'class-dap-groups.php' );
            if ( class_exists( 'DSA2_Groups', false ) ) DSA2_Groups::erase_user( absint( $user_id ) );
        }
        $ids = self::erasable_conversation_ids( $user_id, $email );
        if ( ! $ids ) return 0;
        $conv_table = $wpdb->prefix . 'dap_conversations';
        if ( method_exists( __CLASS__, 'delete_conversation_images' ) ) self::delete_conversation_images( $ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $installations = array();
        foreach ( array( 'dap_mobile_sessions', 'dap_mobile_devices' ) as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ) ) {
                $installations = array_merge( $installations, (array) $wpdb->get_col( $wpdb->prepare( "SELECT installation_id FROM {$wpdb->prefix}{$table} WHERE conversation_id IN ($placeholders)", $ids ) ) );
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$table} WHERE conversation_id IN ($placeholders)", $ids ) );
            }
        }
        foreach ( array_unique( array_filter( array_map( 'strval', $installations ) ) ) as $installation_id ) {
            delete_option( 'dap_mobile_prefs_' . $installation_id );
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}dap_messages WHERE conversation_id IN ($placeholders)", $ids ) );
        foreach ( $ids as $conversation_id ) {
            delete_option( 'dap_internal_notes_' . $conversation_id );
            $tickets = get_posts( array( 'post_type' => 'dap_ticket', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => 'dap_conversation_id', 'meta_value' => (int) $conversation_id ) );
            foreach ( (array) $tickets as $ticket_id ) wp_delete_post( (int) $ticket_id, true );
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM $conv_table WHERE id IN ($placeholders)", $ids ) );
        return count( $ids );
    }

    public static function notify_new_customer_message( $conversation_id, $message_id, $sender, $body, $type, $channel ) {
        if ( 'customer' !== $sender || 'agent' !== $channel ) return;
        $s = get_option( 'dap_settings', array() );
        if ( empty( $s['notify_enabled'] ) || empty( $s['notify_every_message'] ) ) return;
        $throttle = isset( $s['notify_throttle'] ) ? max( 0, min( 900, (int) $s['notify_throttle'] ) ) : 0;
        if ( $throttle > 0 ) {
            $key = 'dap_msg_alert_' . absint( $conversation_id );
            if ( get_transient( $key ) ) return;
            set_transient( $key, 1, $throttle );
        }
        set_transient( 'dap_notify_' . absint( $conversation_id ), 1, 45 ); // no duplicate escalation mail
        $preview = self::redact_secrets( DSA2_Compat::substr( wp_strip_all_tags( html_entity_decode( (string) $body, ENT_QUOTES, 'UTF-8' ) ), 0, 400 ) );
        if ( 'image' === $type && '' === trim( $preview ) ) $preview = '[Image envoyée par le client]';
        $args = array( absint( $conversation_id ), $preview, absint( $message_id ) );
        if ( false !== wp_schedule_single_event( time(), 'dsa2_send_message_alert', $args ) ) {
            // Cron only runs on the next page view. On a quiet store that can be minutes,
            // which is not "instant", so spawn the runner immediately as well.
            if ( function_exists( 'spawn_cron' ) ) spawn_cron();
            return;
        }
        self::send_message_alert( absint( $conversation_id ), $preview, absint( $message_id ) );
    }

    /** Everyone who should hear about a waiting customer: the alert box plus the support team. */
    private static function alert_recipients( $settings ) {
        $to = array();
        $primary = ! empty( $settings['notify_email'] ) && is_email( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' );
        if ( is_email( $primary ) ) $to[] = $primary;
        if ( ! empty( $settings['notify_staff'] ) ) {
            $agents = get_users( array(
                'capability' => DSA2_Install::CAP_SUPPORT,
                'fields'     => array( 'user_email' ),
                'number'     => 25,
            ) );
            foreach ( (array) $agents as $agent ) {
                if ( ! empty( $agent->user_email ) && is_email( $agent->user_email ) ) $to[] = $agent->user_email;
            }
        }
        return array_values( array_unique( array_filter( $to ) ) );
    }

    private static function conversation_who( $conv ) {
        $reach = $conv->customer_email ? $conv->customer_email : (string) ( $conv->customer_phone ?? '' );
        $who = $conv->customer_name ? $conv->customer_name : ( $reach ? $reach : 'Visiteur #' . (int) $conv->id );
        if ( $conv->customer_name && $reach ) $who .= ' (' . $reach . ')';
        return $who;
    }

    /** Cron target for the per-message alert — also callable directly as a fallback. */
    public static function send_message_alert( $conversation_id, $preview = '', $message_id = 0 ) {
        global $wpdb;
        $conv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . $wpdb->prefix . "dap_conversations WHERE id=%d", absint( $conversation_id ) ) );
        if ( ! $conv ) return;
        $s = get_option( 'dap_settings', array() );
        if ( empty( $s['notify_enabled'] ) ) return;
        $to = self::alert_recipients( $s );
        if ( ! $to ) return;

        $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $who = self::conversation_who( $conv );
        $link = admin_url( 'admin.php?page=delicat-assistant&conversation=' . (int) $conv->id );
        $subject = sprintf( '[%s] Nouveau message de %s', $site, $who );
        $preview = (string) $preview;
        if ( '' === trim( $preview ) ) $preview = (string) self::last_customer_message( $conv->id );
        $preview = self::redact_secrets( DSA2_Compat::substr( wp_strip_all_tags( html_entity_decode( $preview, ENT_QUOTES, 'UTF-8' ) ), 0, 400 ) );

        $body  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#10152e;line-height:1.55">';
        $body .= '<p style="margin:0 0 14px"><strong>' . esc_html( $who ) . '</strong> vient d\'écrire à l\'équipe support.</p>';
        if ( '' !== $preview ) {
            $body .= '<blockquote style="margin:0 0 16px;padding:12px 14px;background:#f5f6fb;border-left:4px solid #ff5a2b;border-radius:8px">' . nl2br( esc_html( $preview ) ) . '</blockquote>';
        }
        $body .= '<p style="margin:0 0 6px"><strong>Conversation :</strong> #' . (int) $conv->id . ' · ' . esc_html( strtoupper( (string) $conv->language ) ) . ' · statut ' . esc_html( (string) $conv->status ) . '</p>';
        if ( $conv->customer_phone ) {
            $wa = preg_replace( '/\D+/', '', (string) $conv->customer_phone );
            $body .= '<p style="margin:0 0 14px"><strong>WhatsApp :</strong> <a href="https://wa.me/' . esc_attr( $wa ) . '">' . esc_html( $conv->customer_phone ) . '</a></p>';
        }
        $body .= '<p style="margin:18px 0"><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:12px 20px;border-radius:10px;background:#ff5a2b;color:#fff;text-decoration:none;font-weight:700">Répondre au client →</a></p>';
        $body .= '<p style="margin:0;font-size:12px;color:#6f778b">Delicat Assistant · alerte automatique</p></div>';

        add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_mail_type' ) );
        wp_mail( $to, $subject, $body );
        remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_mail_type' ) );
    }

    public static function html_mail_type() {
        return 'text/html';
    }

    /** Cron target for the escalation alert — also callable directly as a fallback. */
    public static function send_agent_email( $conversation_id ) {
        global $wpdb;
        $conv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . $wpdb->prefix . "dap_conversations WHERE id=%d", absint( $conversation_id ) ) );
        if ( ! $conv ) return;
        $s = get_option( 'dap_settings', array() );
        if ( empty( $s['notify_enabled'] ) ) return;
        $to = self::alert_recipients( $s );
        if ( ! $to ) return;

        $who = self::conversation_who( $conv );
        $link = admin_url( 'admin.php?page=delicat-assistant&conversation=' . (int) $conv->id );
        $last = self::last_customer_message( $conv->id );
        $subject = sprintf( '[%s] Un client attend un agent', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
        $message = sprintf(
            "Un client demande un agent dans Delicat Assistant.\n\nClient : %s\nLangue : %s\nConversation : %s\n",
            $who, strtoupper( $conv->language ), $link
        );
        if ( $last ) {
            $message .= "\nDernier message :\n" . DSA2_Compat::substr( self::redact_secrets( wp_strip_all_tags( html_entity_decode( $last, ENT_QUOTES, 'UTF-8' ) ) ), 0, 300 ) . "\n";
        }
        wp_mail( $to, $subject, $message );
    }

    // True when the last assistant message is identical and recent — prevents stacked duplicate replies.
    private static function is_duplicate_assistant_reply( $conversation_id, $body, $window = 20 ) {
        global $wpdb;
        $last = $wpdb->get_row( $wpdb->prepare( "SELECT body, created_at FROM " . $wpdb->prefix . "dap_messages WHERE conversation_id=%d AND sender='assistant' ORDER BY id DESC LIMIT 1", absint( $conversation_id ) ) );
        // add_message() stores wp_kses_post( $body ); compare like for like or an answer
        // containing & or < is never recognised as a duplicate and stacks in the thread.
        $body = wp_kses_post( $body );
        return $last && $last->body === $body && ( current_time( 'timestamp' ) - strtotime( $last->created_at ) ) < $window;
    }

    public static function ajax_load_messages() {
        self::verify_request();
        $after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
        $lang = self::request_lang(); // whitelisted fr|en|ht, like every other endpoint
        $request_context = self::request_context();
        $conv = self::get_or_create_conversation( $lang );
        if ( ! $conv ) wp_send_json_error( array( 'code'=>'rate_limited', 'message' => 'Service momentanément indisponible. Réessayez plus tard.' ), 429 );
        $messages = self::client_messages( $conv->id, $after );
        if ( ! $messages && $after === 0 ) {
            self::add_message( $conv->id, 'assistant', self::welcome_text( $lang ), 'text', array(), 'bot' );
            $messages = self::client_messages( $conv->id, 0 );
        }
        if ( 0 === $after ) {
            $page_product = isset( $_POST['page_product'] ) ? absint( $_POST['page_product'] ) : 0;
            $page_title   = isset( $_POST['page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) ) : '';
            if ( !$request_context ) $request_context = self::sanitize_context(array('product_id'=>$page_product,'title'=>$page_title));
            $ctx = self::context_payload( $conv->id, $page_product, $page_title, $lang, $request_context );
            if ( $ctx ) {
                self::add_message( $conv->id, 'assistant', $ctx['body'], $ctx['type'], $ctx['meta'], 'bot' );
                $messages = self::client_messages( $conv->id, 0 );
            } elseif ( self::maybe_add_recommendations( $conv, $lang ) ) {
                $messages = self::client_messages( $conv->id, 0 );
            }
        }
        wp_send_json_success( array(
            'messages' => self::format_messages( $messages ),
            'conversation_id' => (int) $conv->id,
            'unread_count' => self::unread_count( $conv->id ),
            'agent_read_id' => (int) $conv->last_agent_read_id,
            'agent_typing' => (bool) get_transient( 'dap_agent_typing_' . (int) $conv->id ),
            'status' => sanitize_key( (string) $conv->status ),
        ) );
    }

    /**
     * v6.2.0 — a suggestion when the assistant opens with nothing else to say.
     *
     * "Pour vous" was a button nobody pressed. The recommendation engine already knew what
     * this customer buys and what sells alongside it; it just had no way to show up on its
     * own. Now, when the panel opens on no particular product, the assistant leads with a
     * short list — the customer's own repeat purchases if they are signed in, the store's
     * best sellers if not.
     *
     * Deliberately narrow: only when the assistant workspace is otherwise empty of
     * suggestions, only once every 12 hours per conversation, never on top of a product
     * context card, and never in the agent thread. It suggests; it does not accumulate.
     *
     * @return bool True when a card was written.
     */
    private static function maybe_add_recommendations( $conv, $lang ) {
        if ( ! class_exists( 'DSA2_Recommend', false ) || ! DSA2_Recommend::enabled() ) return false;
        $guard = 'dap_reco_open_' . (int) $conv->id;
        if ( get_transient( $guard ) ) return false;
        // Never interrupt a live support thread with merchandising.
        if ( in_array( (string) $conv->status, array( 'waiting' ), true ) ) return false;
        $payload = DSA2_Recommend::for_customer( $lang );
        if ( ! $payload || empty( $payload['meta']['items'] ) ) return false;
        if ( self::is_duplicate_assistant_reply( (int) $conv->id, $payload['body'], 12 * HOUR_IN_SECONDS ) ) {
            set_transient( $guard, 1, 12 * HOUR_IN_SECONDS );
            return false;
        }
        set_transient( $guard, 1, 12 * HOUR_IN_SECONDS );
        self::add_message( (int) $conv->id, 'assistant', $payload['body'], $payload['type'], $payload['meta'], 'bot' );
        return true;
    }

    /**
     * Card shown when the chat opens on a relevant page: the product being viewed,
     * or quick Free Fire answers. Once per conversation per page (6h guard).
     */
    private static function context_payload( $conv_id, $product_id, $page_title, $lang, $context = array() ) {
        $context = self::sanitize_context($context);
        if(!$product_id && !empty($context['product_id'])) $product_id=(int)$context['product_id'];
        if(!$page_title && !empty($context['title'])) $page_title=(string)$context['title'];
        $norm  = DSA2_Products::norm( $page_title );
        $is_ff = ( false !== strpos( $norm, 'free fire' ) || false !== strpos( $norm, 'freefire' ) );
        if ( ! $product_id && ! $is_ff ) return null;
        $guard = 'dap_ctx_' . (int) $conv_id . '_' . ( $product_id ? $product_id : 'ff' );
        if ( get_transient( $guard ) ) return null;
        $count_key = 'dap_ctxn_' . (int) $conv_id;
        $counter   = get_transient( $count_key );
        $count     = is_array( $counter ) ? (int) $counter['c'] : 0;
        if ( $count >= 3 ) return null; // cap per conversation
        // Keep the original 6 h window instead of renewing it on each card, otherwise the
        // cap slides forward forever and a long session keeps getting context cards.
        $expires = is_array( $counter ) ? max( MINUTE_IN_SECONDS, (int) $counter['e'] - time() ) : 6 * HOUR_IN_SECONDS;
        set_transient( $count_key, array( 'c' => $count + 1, 'e' => is_array( $counter ) ? (int) $counter['e'] : time() + 6 * HOUR_IN_SECONDS ), $expires );
        set_transient( $guard, 1, 6 * HOUR_IN_SECONDS );

        $agent_btn = array( 'label' => self::t( $lang, 'Parler à un agent', 'Talk to an agent', 'Pale ak yon ajan' ), 'action' => 'agent', 'value' => '' );
        $pin_btn   = array( 'label' => self::t( $lang, 'Utiliser un PIN Free Fire', 'Use a Free Fire PIN', 'Itilize yon PIN Free Fire' ), 'action' => 'guide', 'value' => 'pin' );

        if ( $product_id && ! DSA2_Products::is_public( $product_id ) ) $product_id = 0; // client-supplied id
        if ( $product_id && DSA2_Products::enabled() ) {
            $payload = DSA2_Products::payload( array( $product_id ), $lang, 'context' );
            if ( $payload ) {
                $payload['body'] = 'context:' . (int) $product_id;
                $payload['meta']['title'] = self::t( $lang, 'Le produit que vous consultez', 'The product you are viewing', 'Pwodwi w ap gade a' );
                $selected = array_filter(array($context['variation']??'', $context['plan']??'', $context['price']??''));
                $payload['meta']['intro'] = $selected ? self::t($lang,'Sélection actuelle : ','Current selection: ','Seleksyon aktyèl la: ').implode(' · ',$selected) : self::t( $lang, 'Posez votre question, ou utilisez les boutons ci-dessous.', 'Ask your question, or use the buttons below.', 'Poze kesyon ou, oswa itilize bouton ki anba yo.' );
                $payload['meta']['context']=$context;
                $payload['meta']['buttons'] = $is_ff ? array( $pin_btn, array('label'=>self::t($lang,'Question sur ce produit','Question about this product','Kesyon sou pwodwi sa a'),'action'=>'quick','value'=>'product_context'), $agent_btn ) : array( array('label'=>self::t($lang,'Comment commander','How to order','Kijan pou kòmande'),'action'=>'guide','value'=>'buy'), $agent_btn );
                return $payload;
            }
        }
        if ( $is_ff ) {
            return array( 'type' => 'help_card', 'body' => 'context:freefire', 'meta' => array(
                'title' => self::t( $lang, 'Questions Free Fire ?', 'Free Fire questions?', 'Kesyon sou Free Fire?' ),
                'intro' => self::t( $lang, 'Réponses rapides sur les diamants, les PIN et la livraison :', 'Quick answers about diamonds, PINs and delivery:', 'Repons rapid sou dyaman, PIN ak livrezon:' ),
                'buttons' => array(
                    $pin_btn,
                    array( 'label' => self::t( $lang, 'Comment commander', 'How to order', 'Kijan pou kòmande' ), 'action' => 'guide', 'value' => 'buy' ),
                    array( 'label' => self::t( $lang, 'Délai de livraison', 'Delivery time', 'Delè livrezon' ), 'action' => 'guide', 'value' => 'delivery' ),
                    array( 'label' => self::t( $lang, 'Voir les prix', 'See prices', 'Gade pri yo' ), 'action' => 'quick', 'value' => 'products' ),
                    $agent_btn,
                ),
            ) );
        }
        return null;
    }

    public static function ajax_quick_action() {
        self::verify_request();
        $action = isset( $_POST['quick_action'] ) ? sanitize_key( wp_unslash( $_POST['quick_action'] ) ) : '';
        $lang = self::request_lang();
        $after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
        $context = self::request_context();
        $conv = self::get_or_create_conversation( $lang );
        if ( ! $conv ) wp_send_json_error( array( 'code'=>'rate_limited', 'message' => self::service_busy( $lang ) ), 429 );
        $payload = self::quick_action_payload( $action, $lang, null, $context );
        if ( ! $payload ) wp_send_json_error( array( 'message' => self::t( $lang, 'Action indisponible.', 'Action unavailable.', 'Aksyon sa a pa disponib.' ) ), 404 );
        if ( 'end_chat' === $action ) {
            global $wpdb;
            $wpdb->update( $wpdb->prefix . 'dap_conversations', array( 'status' => 'resolved' ), array( 'id' => (int) $conv->id ) );
        }
        $conv->language = $lang; // the card is written in the language the customer is using now
        self::store_assistant_payload( $conv, $payload );
        wp_send_json_success( array( 'messages' => self::format_messages( self::client_messages( $conv->id, $after ) ) ) );
    }

    /** Store URLs are settings first (SaaS: each boutique has its own paths). */
    public static function store_url( $key ) {
        $s = get_option( 'dap_settings', array() );
        $custom = isset( $s[ 'url_' . $key ] ) ? trim( (string) $s[ 'url_' . $key ] ) : '';
        if ( $custom ) return esc_url_raw( $custom );
        switch ( $key ) {
            case 'wallet':  return home_url( '/my-wallet/' );
            case 'redeem':  return home_url( '/free-fire-redeem/' );
            case 'rewards': return home_url( '/rewards/' );
            case 'shop':    return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
        }
        return home_url( '/' );
    }
    public static function account_url() {
        return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
    }
    public static function store_name() {
        $s = get_option( 'dap_settings', array() );
        $n = trim( (string) ( $s['brand_name'] ?? '' ) );
        return $n !== '' ? $n : 'Delicat Store';
    }

    /**
     * Agent availability: the manual status, downgraded to offline outside opening
     * hours so the widget never promises a live answer at 3 a.m.
     */
    public static function effective_status() {
        $s = get_option( 'dap_settings', array() );
        $status = isset( $s['agent_status'] ) ? $s['agent_status'] : 'online';
        if ( 'offline' === $status ) return 'offline';
        $open  = isset( $s['open_hour'] ) ? (int) $s['open_hour'] : 8;
        $close = isset( $s['close_hour'] ) ? (int) $s['close_hour'] : 20;
        if ( $open === $close ) return $status; // 24/7
        $hour = (int) current_time( 'G' );
        $inside = ( $open < $close ) ? ( $hour >= $open && $hour < $close ) : ( $hour >= $open || $hour < $close );
        return $inside ? $status : 'offline';
    }

    private static function service_busy( $lang ) {
        return self::t( $lang, 'Service momentanément indisponible. Réessayez plus tard.', 'Service temporarily unavailable. Try again later.', 'Sèvis la pa disponib pou kounye a. Eseye ankò pita.' );
    }

    private static function rating_label( $rating, $lang ) {
        $labels = array(
            'great' => '😍 ' . self::t( $lang, 'Excellent', 'Excellent', 'Ekselan' ),
            'good'  => '🙂 ' . self::t( $lang, 'Bien', 'Good', 'Byen' ),
            'bad'   => '😕 ' . self::t( $lang, 'À améliorer', 'Needs improvement', 'Pou amelyore' ),
        );
        return isset( $labels[ $rating ] ) ? $labels[ $rating ] : '';
    }

    /**
     * Customer screenshot upload (TransCode receipts, error screens).
     * Anonymous file upload is the riskiest surface in the plugin, so this is
     * deliberately narrow: images only, verified by content, randomly renamed,
     * size-capped and rate-limited per conversation and per IP.
     */
    public static function ajax_upload() {
        self::verify_request();
        $lang = self::request_lang();
        $fail = function( $msg ) use ( $lang ) { wp_send_json_error( array( 'message' => $msg ), 400 ); };

        if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['file']['error'] ) {
            $fail( self::t( $lang, 'Envoi impossible. Réessayez.', 'Upload failed. Please try again.', 'Nou pa ka voye l. Eseye ankò.' ) );
        }
        $file = $_FILES['file'];
        if ( (int) $file['size'] > 5 * MB_IN_BYTES ) {
            $fail( self::t( $lang, 'Image trop lourde (5 Mo maximum).', 'Image too large (5 MB maximum).', 'Imaj la twò lou (5 Mo maksimòm).' ) );
        }
        // Content check first: an image extension alone proves nothing.
        $size = @getimagesize( $file['tmp_name'] );
        $allowed_types = array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP );
        if ( ! $size || ! in_array( (int) $size[2], $allowed_types, true ) ) {
            $fail( self::t( $lang, 'Seules les images sont acceptées.', 'Images only.', 'Se sèlman imaj nou aksepte.' ) );
        }
        $ext = array( IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp' );
        $ext = $ext[ (int) $size[2] ];

        $conv = self::get_or_create_conversation( $lang );
        if ( ! $conv ) wp_send_json_error( array( 'message' => self::service_busy( $lang ) ), 429 );
        $intent = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : '';
        $context = self::request_context();
        $channel = ( 'agent' === $intent || ( '' === $intent && self::is_live_agent_thread( $conv ) ) ) ? 'agent' : 'bot';
        global $wpdb;
        $sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . $wpdb->prefix . "dap_messages WHERE conversation_id=%d AND message_type='image'", (int) $conv->id ) );
        if ( $sent >= 10 || self::bump_window( 'dap_up_' . md5( self::client_ip() ), HOUR_IN_SECONDS ) > 20 ) {
            wp_send_json_error( array( 'message' => self::t( $lang, 'Limite d’images atteinte. Parlez à un agent.', 'Image limit reached. Talk to an agent.', 'Ou rive nan limit imaj yo. Pale ak yon ajan.' ) ), 429 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $name = 'dap-' . wp_generate_password( 20, false, false ) . '.' . $ext; // unguessable, no user-controlled name
        $overrides = array(
            'test_form' => false,
            'mimes'     => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ),
        );
        $_FILES['file']['name'] = $name;
        $moved = wp_handle_upload( $_FILES['file'], $overrides );
        if ( ! $moved || isset( $moved['error'] ) || empty( $moved['url'] ) ) {
            $fail( self::t( $lang, 'Envoi impossible. Réessayez.', 'Upload failed. Please try again.', 'Nou pa ka voye l. Eseye ankò.' ) );
        }
        $after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
        $message_id = self::add_message( $conv->id, 'customer', 'image', 'image', array(
            'url' => esc_url_raw( $moved['url'] ),
            'mime' => isset( $moved['type'] ) ? sanitize_text_field( $moved['type'] ) : 'image/' . $ext,
            'title' => self::t( $lang, 'Image envoyée', 'Image sent', 'Imaj voye' ),
            'context' => $context,
        ), $channel );
        if ( ! $message_id ) {
            if ( ! empty( $moved['file'] ) && is_file( $moved['file'] ) ) wp_delete_file( $moved['file'] );
            $fail( self::t( $lang, 'Envoi impossible. Réessayez.', 'Upload failed. Please try again.', 'Nou pa ka voye l. Eseye ankò.' ) );
        }
        if ( 'agent' === $channel ) self::fire_webhook( $conv, 'customer_image', 'image' );
        wp_send_json_success( array( 'messages' => self::format_messages( self::client_messages( $conv->id, $after ) ) ) );
    }

    /**
     * Guests can check an order with its number, but only by proving they own it
     * (matching billing e-mail or phone). Attempts are capped to stop enumeration.
     */
    public static function ajax_find_order() {
        self::verify_request();
        $lang = self::request_lang();
        if ( ! function_exists( 'wc_get_orders' ) ) wp_send_json_error( array( 'message' => self::service_busy( $lang ) ), 400 );
        if ( self::bump_window( 'dap_ord_' . md5( self::client_ip() ), HOUR_IN_SECONDS ) > 10 ) {
            wp_send_json_error( array( 'message' => self::t( $lang, 'Trop de tentatives. Réessayez plus tard.', 'Too many attempts. Try again later.', 'Twòp tantativ. Eseye ankò pita.' ) ), 429 );
        }
        $number  = isset( $_POST['order'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['order'] ) ) ) : '';
        $proof   = isset( $_POST['proof'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['proof'] ) ) ) : '';
        $after   = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
        $conv = self::get_or_create_conversation( $lang );
        if ( ! $conv ) wp_send_json_error( array( 'message' => self::service_busy( $lang ) ), 429 );

        $order = false;
        $id = absint( preg_replace( '/\D+/', '', $number ) );
        if ( $id && $proof ) {
            $candidate = wc_get_order( $id );
            // wc_get_order() also returns refunds and other order types, which have no
            // billing getters — calling them there is a fatal error.
            if ( $candidate instanceof WC_Order ) {
                $proof_digits = preg_replace( '/\D+/', '', $proof );
                $email_ok = is_email( $proof ) && 0 === strcasecmp( trim( $candidate->get_billing_email() ), $proof );
                // 7.0: the digits must be the END of the billing phone (its last 8 or more),
                // not any 8-digit run found inside it.
                $billing_digits = preg_replace( '/\D+/', '', $candidate->get_billing_phone() );
                $phone_ok = strlen( $proof_digits ) >= 8 && strlen( $billing_digits ) >= strlen( $proof_digits ) && substr( $billing_digits, - strlen( $proof_digits ) ) === $proof_digits;
                if ( $email_ok || $phone_ok ) $order = $candidate;
            }
        }
        if ( ! $order ) {
            // Same answer whether the order is missing or the proof is wrong: no enumeration.
            $payload = array( 'type' => 'help_card', 'body' => 'order_not_found:' . substr( md5( $number . '|' . $proof ), 0, 8 ), 'meta' => array(
                'title' => self::t( $lang, 'Commande introuvable', 'Order not found', 'Nou pa jwenn kòmand lan' ),
                'intro' => self::t( $lang, 'Vérifiez le numéro de commande et l’e-mail ou le numéro utilisé lors de l’achat. Un agent peut aussi vérifier pour vous.', 'Check the order number and the e-mail or phone used at purchase. An agent can also check for you.', 'Tcheke nimewo kòmand lan ak imel oswa nimewo ou te itilize a. Yon ajan ka tcheke pou ou tou.' ),
                'buttons' => self::human_buttons( $lang ),
            ) );
        } else {
            $payload = array( 'type' => 'link_card', 'body' => 'order:' . $order->get_id(), 'meta' => array(
                'title' => sprintf( self::t( $lang, 'Commande #%s', 'Order #%s', 'Kòmand #%s' ), $order->get_order_number() ),
                'description' => sprintf(
                    self::t( $lang, 'Statut : %1$s — Total : %2$s (%3$s)', 'Status: %1$s — Total: %2$s (%3$s)', 'Estati: %1$s — Total: %2$s (%3$s)' ),
                    wc_get_order_status_name( $order->get_status() ),
                    wp_strip_all_tags( $order->get_formatted_order_total() ),
                    wc_format_datetime( $order->get_date_created() )
                ),
                'url' => self::account_url(),
                'button' => self::t( $lang, 'Mon compte', 'My account', 'Kont mwen' ),
                'icon' => '🧾',
            ) );
        }
        self::add_message( $conv->id, 'assistant', $payload['body'], $payload['type'], $payload['meta'], 'bot' );
        wp_send_json_success( array( 'messages' => self::format_messages( self::client_messages( $conv->id, $after ) ) ) );
    }

    public static function ajax_rate() {
        self::verify_request();
        $lang = self::request_lang();
        $rating = isset( $_POST['rating'] ) ? sanitize_key( wp_unslash( $_POST['rating'] ) ) : '';
        if ( ! in_array( $rating, array( 'great', 'good', 'bad' ), true ) ) {
            wp_send_json_error( array( 'message' => self::t( $lang, 'Choix invalide.', 'Invalid choice.', 'Chwa sa a pa valab.' ) ), 400 );
        }
        $after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
        $conv = self::find_conversation(); // Rating never creates a conversation.
        if ( ! $conv ) wp_send_json_error( array( 'message' => self::service_busy( $lang ) ), 429 );
        global $wpdb;
        $handoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM " . $wpdb->prefix . "dap_messages WHERE conversation_id=%d AND channel='agent' AND message_type='agent_card'", (int) $conv->id ) );
        $already = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . $wpdb->prefix . "dap_messages WHERE conversation_id=%d AND channel='agent' AND message_type='rating' AND id>%d", (int) $conv->id, $handoff ) );
        if ( $already ) { // One rating per conversation: otherwise the report is skewed by repeated taps.
            wp_send_json_success( array( 'messages' => self::format_messages( self::client_messages( $conv->id, $after ) ) ) );
        }
        self::add_message( $conv->id, 'customer', 'rating:' . $rating, 'rating', array(
            'title' => self::t( $lang, 'Avis client', 'Customer rating', 'Avi kliyan' ) . ' : ' . self::rating_label( $rating, $lang ),
            'rating' => $rating,
        ), 'agent' );
        self::add_message( $conv->id, 'assistant', self::t( $lang, 'Merci pour votre retour 🙏 À bientôt !', 'Thank you for your feedback 🙏 See you soon!', 'Mèsi pou repons ou 🙏 N a wè ankò!' ), 'text', array(), 'agent' );
        wp_send_json_success( array( 'messages' => self::format_messages( self::client_messages( $conv->id, $after ) ) ) );
    }

    /** Inline name + e-mail form shown to guests before an agent handoff. */
    private static function has_contact( $conv ) {
        return ( '' !== (string) $conv->customer_email ) || ( '' !== (string) ( $conv->customer_phone ?? '' ) );
    }

    private static function contact_request_payload( $lang ) {
        return array( 'type' => 'contact_card', 'body' => 'contact_request', 'meta' => array(
            'title'      => self::t( $lang, 'Avant de parler à un agent', 'Before talking to an agent', 'Anvan ou pale ak yon ajan' ),
            'intro'      => self::t( $lang, 'Indiquez votre nom et votre e-mail pour que l’agent puisse vous répondre.', 'Leave your name and e-mail so the agent can get back to you.', 'Bay non w ak imel ou pou ajan an ka reponn ou.' ),
            'nameLabel'  => self::t( $lang, 'Votre nom', 'Your name', 'Non ou' ),
            'emailLabel' => self::t( $lang, 'Votre e-mail ou WhatsApp', 'Your e-mail or WhatsApp', 'Imel ou oswa WhatsApp' ),
            'button'     => self::t( $lang, 'Continuer vers l’agent', 'Continue to the agent', 'Kontinye al jwenn ajan an' ),
            'privacy'    => self::t( $lang, 'Utilisées uniquement pour vous répondre.', 'Used only to reply to you.', 'Nou itilize yo sèlman pou reponn ou.' ),
        ) );
    }

    public static function ajax_set_contact() {
        self::verify_request();
        $lang = self::request_lang();
        if ( is_user_logged_in() ) {
            // Logged-in identity comes from the account and must not be overwritten via the chat.
            wp_send_json_error( array( 'message' => self::t( $lang, 'Vous êtes déjà connecté.', 'You are already signed in.', 'Ou deja konekte.' ) ), 400 );
        }
        $name    = isset( $_POST['name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) : '';
        $contact = isset( $_POST['email'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['email'] ) ) ) : '';
        $after   = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
        // Many customers live on WhatsApp and never read e-mail: accept either.
        $email = is_email( $contact ) ? sanitize_email( $contact ) : '';
        $phone = '';
        if ( ! $email ) {
            $digits = preg_replace( '/\D+/', '', $contact );
            if ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) $phone = $digits;
        }
        if ( DSA2_Compat::length( $name ) < 2 || DSA2_Compat::length( $name ) > 100 || ( ! $email && ! $phone ) ) {
            wp_send_json_error( array( 'message' => self::t( $lang, 'Veuillez entrer un nom et un e-mail ou numéro WhatsApp valide.', 'Please enter a valid name and e-mail or WhatsApp number.', 'Tanpri antre yon non ak yon imel oswa nimewo WhatsApp ki valab.' ) ), 400 );
        }
        $conv = self::get_or_create_conversation( $lang );
        if ( ! $conv ) wp_send_json_error( array( 'code'=>'rate_limited', 'message' => self::service_busy( $lang ) ), 429 );
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'dap_conversations', array( 'customer_name' => $name, 'customer_email' => $email, 'customer_phone' => $phone ), array( 'id' => (int) $conv->id ) );
        $conv->customer_name = $name; $conv->customer_email = $email; $conv->customer_phone = $phone;

        $payload = self::quick_action_payload( 'agent', $lang );
        if ( $payload && ! self::is_duplicate_assistant_reply( $conv->id, $payload['body'], 20 ) ) {
            self::add_message( $conv->id, 'assistant', $payload['body'], $payload['type'], $payload['meta'], 'agent' );
        }
        self::escalate( $conv );
        wp_send_json_success( array( 'messages' => self::format_messages( self::client_messages( $conv->id, $after ) ) ) );
    }


    public static function unread_count( $conversation_id ) {
        global $wpdb;
        $conv = $wpdb->prefix . 'dap_conversations';
        $msg = $wpdb->prefix . 'dap_messages';
        $last = (int) $wpdb->get_var( $wpdb->prepare( "SELECT last_customer_read_id FROM $conv WHERE id=%d", absint($conversation_id) ) );
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $msg WHERE conversation_id=%d AND channel='agent' AND id>%d AND sender='agent'", absint($conversation_id), $last ) );
    }

    private static function find_conversation() {
        global $wpdb;
        $table = $wpdb->prefix . 'dap_conversations';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE session_key=%s", self::session_key() ) );
    }

    /** Publish customer typing without creating an empty conversation or exposing another session. */
    public static function ajax_typing() {
        self::verify_request();
        $conversation = self::find_conversation();
        if ( ! $conversation ) wp_send_json_success( array( 'typing' => false, 'agent_typing' => false ) );
        $typing = isset( $_POST['typing'] ) && rest_sanitize_boolean( wp_unslash( $_POST['typing'] ) );
        $key = 'dap_customer_typing_' . (int) $conversation->id;
        if ( $typing ) set_transient( $key, 1, 12 );
        else delete_transient( $key );
        wp_send_json_success( array(
            'typing' => $typing,
            'agent_typing' => (bool) get_transient( 'dap_agent_typing_' . (int) $conversation->id ),
        ) );
    }

    public static function ajax_mark_read() {
        self::verify_request();
        global $wpdb;
        $conv = self::find_conversation(); // Lookup only — mark_read must never create a conversation.
        $last_id = isset($_POST['last_id']) ? absint($_POST['last_id']) : 0;
        if ( $conv && $last_id ) {
            $owned_last = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(id) FROM {$wpdb->prefix}dap_messages WHERE conversation_id=%d AND channel='agent' AND sender='agent' AND id<=%d",
                (int) $conv->id,
                $last_id
            ) );
            if ( $owned_last ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}dap_conversations SET last_customer_read_id=GREATEST(last_customer_read_id,%d) WHERE id=%d",
                    $owned_last,
                    (int) $conv->id
                ) );
            }
        }
        wp_send_json_success( array( 'unread_count'=>$conv ? self::unread_count( $conv->id ) : 0 ) );
    }

    /**
     * The polling heartbeat. Two indexed reads and about forty bytes of JSON, against
     * the four queries, the message formatting and the full thread payload that
     * dsa2_load_messages costs. The widget only asks for messages once this says the
     * thread has grown, which on an idle conversation is never.
     *
     * Deliberately lighter than verify_request(): it never creates a conversation and
     * keeps a single wide rate-limit counter, because it runs every 7 to 30 seconds
     * per open tab and every counter write is a row in wp_options on a site with no
     * object cache.
     */
    public static function ajax_ping() {
        $lang = self::request_lang();
        if ( ! check_ajax_referer( 'dsa2_frontend', 'nonce', false ) ) {
            wp_send_json_error( array( 'code' => 'bad_nonce', 'message' => self::t( $lang, 'Session expirée. Veuillez réessayer.', 'Session expired. Please try again.', 'Sesyon an fini. Tanpri eseye ankò.' ) ), 403 );
        }
        if ( self::bump_window( 'dap_ping_' . md5( self::client_ip() ), MINUTE_IN_SECONDS ) > 900 ) {
            wp_send_json_error( array( 'code' => 'rate_limited', 'message' => self::service_busy( $lang ) ), 429 );
        }
        $conv = self::find_conversation();
        if ( ! $conv ) wp_send_json_success( array( 'last_id' => 0, 'unread' => 0, 'status' => '', 'agent_read_id' => 0, 'agent_typing' => false ) );
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT MAX(id) AS last_id, SUM(CASE WHEN channel='agent' AND id>%d AND sender='agent' THEN 1 ELSE 0 END) AS unread FROM " . $wpdb->prefix . "dap_messages WHERE conversation_id=%d",
            (int) $conv->last_customer_read_id, (int) $conv->id
        ) );
        wp_send_json_success( array(
            'last_id' => $row ? (int) $row->last_id : 0,
            'unread'  => $row ? (int) $row->unread : 0,
            'status'  => (string) $conv->status,
            'agent_read_id' => (int) $conv->last_agent_read_id,
            'agent_typing' => (bool) get_transient( 'dap_agent_typing_' . (int) $conv->id ),
        ) );
    }

    public static function ajax_unread() {
        self::verify_request();
        $conv = self::find_conversation();
        wp_send_json_success( array( 'unread_count' => $conv ? self::unread_count( $conv->id ) : 0 ) );
    }

    private static function welcome_text( $lang ) {
        // "Bonjour 👋" at 9 p.m. reads like a machine. The greeting follows the store clock.
        $hello = class_exists( 'DSA2_SmallTalk', false )
            ? DSA2_SmallTalk::hello_word( $lang )
            : self::t( $lang, 'Bonjour 👋', 'Hello 👋', 'Bonjou 👋' );
        return $hello . ' ' . self::t( $lang,
            'Je peux vous aider avec votre crédit boutique, vos commandes, vos PIN, vos récompenses, vos produits ou vous transférer à un agent.',
            'I can help with your store credit, orders, PINs, rewards, products, or connect you to an agent.',
            'Mwen ka ede w ak kredi boutik ou, kòmand, PIN, rekonpans, pwodwi, oswa mete w an kontak ak yon ajan.' );
    }

    /**
     * Typed chat entry point. Splits the social opener from the request so that both
     * are answered: "Bonjou, kote PIN mwen an?" gets a hello *and* the PIN answer.
     */
    private static function smart_reply( $body, $lang, $quiet = false, $user_id = null, $context = array(), $conv = null ) {
        $lead = '';
        if ( class_exists( 'DSA2_SmallTalk', false ) ) {
            $st = DSA2_SmallTalk::analyze( $body, $lang, $conv );
            if ( $st ) {
                if ( ! empty( $st['pure'] ) ) return $st['payload'];
                $lead = (string) $st['lead'];
                // Reply in the language the customer actually wrote in.
                if ( ! empty( $st['lang'] ) ) $lang = $st['lang'];
            }
        }
        $reply = self::smart_reply_core( $body, $lang, $quiet, $user_id, $context );
        if ( $lead && $reply && class_exists( 'DSA2_SmallTalk', false ) ) {
            $reply = DSA2_SmallTalk::apply_lead( $reply, $lead );
        }
        return $reply;
    }

    private static function smart_reply_core( $body, $lang, $quiet = false, $user_id = null, $context = array() ) {
        $uid = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;
        $context = self::sanitize_context($context);
        if ( !empty($context['product_id']) && preg_match('/\b(ce produit|cet article|this product|this item|pwodwi sa|sa a|celui ci|celle ci|prix de ce|price of this|plan sélectionné|selected plan)\b/u', DSA2_Products::norm($body)) ) {
            return self::product_context_payload($context,$lang);
        }
        $t = remove_accents( strtolower( $body ) );
        if ( preg_match( '/\b(robot|bot|machin|humain|human|vre moun|moun reyel|real person)\b/', $t ) ) {
            return array( 'type' => 'faq_card', 'body' => self::t( $lang,
                'Je suis l’assistant automatique de Delicat Store 🤖 Je réponds instantanément aux questions sur les produits, le crédit boutique et les commandes — et je peux vous transférer à un agent humain à tout moment.',
                'I’m Delicat Store’s automated assistant 🤖 I answer product, store credit and order questions instantly — and I can hand you to a human agent at any time.',
                'Mwen se asistan otomatik Delicat Store 🤖 Mwen reponn kesyon sou pwodwi, kredi boutik ak kòmand touswit — epi mwen ka mete w an kontak ak yon ajan lè ou vle.' ),
                'meta' => array( 'title' => self::t( $lang, 'Robot ou humain ?', 'Bot or human?', 'Robo oswa moun?' ), 'buttons' => self::human_buttons( $lang ) ) );
        }

        // Asking for a human wins over everything else.
        if ( preg_match( '/\b(agent|ajan|humain|human|moun|operateur|conseiller|support|sipo|whatsapp|parler a|pale ak|talk to)\b/', $t ) ) {
            return self::quick_action_payload( 'agent', $lang, $uid, $context );
        }
        // A specific term ("rechaje", "transcode", "livrezon", "pa antre") means the
        // customer wants the procedure, even when the sentence also says "solde".
        $guide = DSA2_Guides::match( $t, 5 );
        if ( $guide ) {
            $payload = DSA2_Guides::payload( $guide, $lang );
            if ( $payload ) return $payload;
        }

        // "Que me conseillez-vous", "kisa pou m achte", "best seller" — asked before the
        // price branch, because those sentences usually contain "produit" too.
        if ( DSA2_Recommend::is_recommendation_question( $t ) ) {
            $reco = DSA2_Recommend::for_customer( $lang );
            if ( $reco ) return $reco;
        }

        // "How much / do you sell / is it available" asks about the catalogue.
        $wants_price = DSA2_Products::is_product_question( $t );
        if ( $wants_price ) {
            $product = DSA2_Products::reply( $t, $lang );
            if ( $product ) return $product;
        }

        // Training intent detection runs before generic account shortcuts so a phrase
        // such as “where is my Free Fire order?” keeps both the product and the demand.
        $trained = DSA2_Training::reply( $body, $lang );
        if ( $trained ) return $trained;

        if ( preg_match( '/\b(solde|balance|wallet|portefeuille|balans|bous|kob|lajan|argent)\b/', $t ) ) return self::quick_action_payload( 'balance', $lang, $uid, $context );
        if ( preg_match( '/(paiement|payment|peman).*(non credite|pas credite|not credited|pa kredite)|(?:solde|balance|balans).*(non credite|not credited|pa kredite)/', $t ) ) return self::quick_action_payload( 'payment_missing', $lang, $uid, $context );
        if ( preg_match( '/(pin|code|kod).*(non recu|pas recu|not received|pa rive|pa resevwa)|(?:commande|order|komand).*(pin|code|kod).*(manque|missing|absent)/', $t ) ) return self::quick_action_payload( 'pin_missing', $lang, $uid, $context );
        if ( preg_match( '/\b(player id|id joueur|identifiant joueur|uid|id jwete|id jwe)\b/', $t ) ) return self::quick_action_payload( 'player_id_help', $lang, $uid, $context );
        if ( preg_match( '/\b(commande|order|achat|processing|komand|kmand)\b/', $t ) ) return self::quick_action_payload( 'order_center', $lang, $uid, $context );

        $guide = DSA2_Guides::match( $t );
        if ( $guide ) {
            $payload = DSA2_Guides::payload( $guide, $lang );
            if ( $payload ) return $payload;
        }
        $product = DSA2_Products::reply( $t, $lang );
        if ( $product ) return $product;

        $faq = self::faq_reply( $t, $lang );
        if ( $faq ) return $faq;
        // Store policies, adapted to Delicat reality (MonCash/Natcash, Récompenses).
        if ( preg_match( '/(code|kod|pin)/', $t ) && preg_match( '/(pa mache|marche pas|ne marche|ne fonctionne|pa fonksyone|not working|doesnt work|does not work|invalide|invalid|deja itilize|deja utilise|already used|rejete|rejected)/', $t ) ) {
            return self::policy_card( 'code_issue', $lang );
        }
        if ( preg_match( '/(rembours|refund|ranbous)/', $t ) ) return self::policy_card( 'refund', $lang );
        if ( preg_match( '/(en gros|bulk|wholesale|revande|revendeur|reseller|gwo kantite|pri gwo)/', $t ) ) return self::policy_card( 'bulk', $lang );
        if ( preg_match( '/(moyens? de paiement|payment methods?|how (can|do) i pay|comment payer|kijan pou m? ?peye|mwayen peman)/', $t ) ) return self::policy_card( 'payments', $lang );
        if ( preg_match( '/(rabais|discount|promo\b|reduction|\brabe\b|kod promo|coupon)/', $t ) ) return self::policy_card( 'discount', $lang );
        // v6.5.0 — "free fire" used to land here, so the store's top seller (sold as an
        // automatic top-up in five regional builds) was answered with "open your delivered
        // PINs". Game names now go to the top-up card; only code/gift-card language
        // reaches the PIN card.
        if ( preg_match( '/\b(free\s*fire|freefire|pubg|call\s*of\s*duty|codm|efootball|blood\s*strike|dls|dream\s*league|flexcity|flex\s*city|one\s*state|roblox|robux|poppo|tiktok|diamant|dyaman|diamond|uc\b|cp\b|recharge|rechaj|rechaje|top\s*up)\b/', $t )
             && ! preg_match( '/\b(gift\s*card|kat\s*kado|carte\s*cadeau|pin\b|kod\b)\b/', $t ) ) {
            return self::quick_action_payload( 'topup', $lang, $uid, $context );
        }
        if ( preg_match( '/\b(pin|code|kod|redeem)\b/', $t ) ) return self::quick_action_payload( 'pin', $lang, $uid );
        if ( preg_match( '/\b(reward|recompense|rekonpans|bonus|points)\b/', $t ) ) return self::quick_action_payload( 'rewards', $lang, $uid );
        if ( preg_match( '/\b(faq|kesyon|questions?)\b/', $t ) ) {
            $menu = self::faq_menu_payload( $lang );
            if ( $menu ) return $menu;
        }
        if ( preg_match( '/\b(aide|help|ede|assistance)\b/', $t ) ) return self::quick_action_payload( 'help', $lang, $uid );
        // A generic "how do I…" without a specific topic: show the guide menu, not the vague card.
        if ( preg_match( '/\b(comment|kijan|ki jan|how to|etape|etap|tuto|guide|gid)\b/', $t ) ) {
            $menu = DSA2_Guides::menu_payload( $lang );
            if ( $menu ) return $menu;
        }
        // Agent conversation in progress: the bot stays out of the way instead of
        // dropping a canned card between the customer and the human.
        if ( $quiet ) return null;
        return self::misunderstood_payload( $lang, $body );
    }

    /** Live chat + WhatsApp (when configured) — the two paths to a human. */
    /** Outside opening hours the promise changes from "now" to "when we open". */
    public static function offline_notice( $lang ) {
        if ( 'offline' !== self::effective_status() ) return '';
        $s = get_option( 'dap_settings', array() );
        $hours = trim( (string) ( $s['business_hours'] ?? '' ) );
        $base = self::t( $lang,
            'Nos agents sont hors ligne pour le moment. Laissez votre message ici : nous répondons dès l’ouverture.',
            'Our agents are offline right now. Leave your message here — we reply as soon as we open.',
            'Ajan nou yo pa anliy kounye a. Kite mesaj ou la: n ap reponn ou tou swit lè nou louvri.' );
        return $hours ? $base . ' (' . $hours . ')' : $base;
    }

    private static function human_buttons( $lang ) {
        $s = get_option( 'dap_settings', array() );
        $wa = preg_replace( '/\D+/', '', (string) ( $s['whatsapp'] ?? '' ) );
        $buttons = array(
            array( 'label' => '💬 ' . self::t( $lang, 'Chat en direct', 'Live chat', 'Chat an dirèk' ), 'action' => 'agent', 'value' => '' ),
        );
        if ( $wa ) $buttons[] = array( 'label' => 'WhatsApp', 'action' => 'whatsapp', 'value' => 'https://wa.me/' . $wa );
        return $buttons;
    }

    /** Store-policy answers taught from the training set, adapted to Delicat. */
    private static function policy_card( $key, $lang ) {
        $cards = array(
            'refund' => array(
                'title' => self::t( $lang, 'Remboursement', 'Refunds', 'Ranbousman' ),
                'body'  => self::t( $lang,
                    'Les produits numériques (PIN, cartes, recharges) sont livrés instantanément : toute vente est définitive une fois le code livré. Si un code est défectueux ou déjà utilisé au moment de la livraison, nous vérifions et le remplaçons ou le remboursons. Envoyez votre numéro de commande à un agent.',
                    'Digital products (PINs, cards, top-ups) are delivered instantly: sales are final once the code is delivered. If a code is defective or already used at delivery, we verify and replace or refund it. Send your order number to an agent.',
                    'Pwodwi dijital yo (PIN, kat, rechaj) livre touswit: tout vant final yon fwa kòd la livre. Si yon kòd gen defo oswa li te deja itilize lè ou resevwa l, n ap verifye epi ranplase l oswa ranbouse w. Voye nimewo kòmand ou bay yon ajan.' ),
                'buttons' => self::human_buttons( $lang ),
                'chips' => array( array( 'label' => self::t( $lang, 'Ma dernière commande', 'My last order', 'Dènye kòmand mwen' ), 'action' => 'quick', 'value' => 'last_order' ) ),
            ),
            'code_issue' => array(
                'title' => self::t( $lang, 'Code / PIN ne fonctionne pas', 'Code / PIN not working', 'Kòd / PIN pa mache' ),
                'body'  => self::t( $lang,
                    'Vérifiez qu’il n’y a pas d’espace avant ou après le code, et qu’il correspond à la bonne région. Un code ne fonctionne qu’une seule fois. S’il est toujours refusé, envoyez votre numéro de commande et le code à un agent : nous vérifions immédiatement.',
                    'Check there is no space before or after the code, and that it matches the right region. A code works only once. If it is still rejected, send your order number and the code to an agent — we check immediately.',
                    'Tcheke pou pa gen espas anvan oswa apre kòd la, epi ke li se pou bon rejyon an. Yon kòd mache yon sèl fwa. Si li toujou refize, voye nimewo kòmand ou ak kòd la bay yon ajan — n ap tcheke touswit.' ),
                'buttons' => self::human_buttons( $lang ),
                'chips' => array( array( 'label' => self::t( $lang, 'Utiliser un PIN Free Fire', 'Use a Free Fire PIN', 'Itilize yon PIN Free Fire' ), 'action' => 'guide', 'value' => 'pin' ) ),
            ),
            'bulk' => array(
                'title' => self::t( $lang, 'Achat en gros / Revendeur', 'Bulk / Reseller', 'Achte an gwo / Revandè' ),
                'body'  => self::t( $lang,
                    'Oui, nous servons les revendeurs avec des prix en gros. Parlez à un agent en indiquant les produits et les quantités souhaitées : vous recevrez une offre personnalisée.',
                    'Yes, we serve resellers with wholesale pricing. Talk to an agent with the products and quantities you need and you will receive a custom offer.',
                    'Wi, nou sèvi revandè ak pri an gwo. Pale ak yon ajan, di l ki pwodwi ak ki kantite ou vle: w ap resevwa yon òf espesyal.' ),
                'buttons' => self::human_buttons( $lang ),
                'chips' => array(),
            ),
            'payments' => array(
                'title' => self::t( $lang, 'Moyens de paiement', 'Payment methods', 'Mwayen peman' ),
                'body'  => self::t( $lang,
                    'Nous acceptons MonCash, Natcash (TransCode) et votre crédit boutique Delicat — et la carte bancaire selon le produit. Le plus simple : ajoutez du crédit boutique une fois, puis payez tout avec ce solde.',
                    'We accept MonCash, Natcash (TransCode) and your Delicat store credit — and bank cards on some products. Easiest: add store credit once, then pay everything with that balance.',
                    'Nou aksepte MonCash, Natcash (TransCode) ak kredi boutik Delicat ou — ak kat bankè sou kèk pwodwi. Pi fasil: ajoute kredi boutik yon fwa, epi peye tout bagay ak balans sa a.' ),
                'buttons' => array(),
                'chips' => array(
                    array( 'label' => self::t( $lang, 'Recharger mon solde', 'Top up my balance', 'Rechaje balans mwen' ), 'action' => 'guide', 'value' => 'recharge' ),
                    array( 'label' => 'Natcash Auto', 'action' => 'guide', 'value' => 'natcash_auto' ),
                ),
            ),
            'discount' => array(
                'title' => self::t( $lang, 'Promotions', 'Discounts', 'Pwomosyon' ),
                'body'  => self::t( $lang,
                    'Les prix affichés sont nos prix standards. Le programme Récompenses vous donne des bonus et avantages selon votre niveau, et nos promotions sont annoncées sur la boutique et WhatsApp.',
                    'Listed prices are our standard prices. The Rewards program gives you bonuses and perks based on your level, and promotions are announced on the shop and WhatsApp.',
                    'Pri ki afiche yo se pri estanda nou. Pwogram Rekonpans lan ba w bonis ak avantaj selon nivo ou, epi pwomosyon yo anonse sou boutik la ak WhatsApp.' ),
                'buttons' => array(),
                'chips' => array( array( 'label' => self::t( $lang, 'Récompenses', 'Rewards', 'Rekonpans' ), 'action' => 'quick', 'value' => 'rewards' ) ),
            ),
        );
        if ( ! isset( $cards[ $key ] ) ) return null;
        $c = $cards[ $key ];
        return array( 'type' => 'faq_card', 'body' => $c['body'], 'meta' => array(
            'title' => $c['title'], 'buttons' => $c['buttons'], 'chips' => $c['chips'], 'source' => 'policy',
        ) );
    }

    /**
     * Honest fallback: admit it, quote the question, offer the two human channels.
     * 6.6.3: the app never quotes back a question that names a product it hides (« vous
     * vendez paypal ? »); the card then says it found no answer, without the quote.
     */
    private static function misunderstood_payload( $lang, $question ) {
        $q = trim( wp_strip_all_tags( (string) $question ) );
        $q = function_exists( 'mb_substr' ) ? DSA2_Compat::substr( $q, 0, 80 ) : substr( $q, 0, 80 );
        $quote = ( DSA2_Products::app_surface() && preg_match( DSA2_Products::APP_HIDDEN_ANSWER_WORDS, $q ) ) ? '' : $q;
        return array( 'type' => 'help_card', 'body' => 'misunderstood:' . substr( md5( $q ), 0, 8 ), 'meta' => array(
            'title'   => self::misunderstood_title( $lang ),
            'intro'   => self::misunderstood_intro( $lang, $quote ),
            'buttons' => self::human_buttons( $lang ),
        ) );
    }

    private static function misunderstood_title( $lang ) {
        return self::t( $lang, 'Je n’ai pas bien compris 🤔', 'I didn’t quite understand 🤔', 'Mwen pa fin konprann 🤔' );
    }

    /** The card's sentence, quoting the question when there is one to quote. */
    private static function misunderstood_intro( $lang, $quote ) {
        if ( '' === (string) $quote ) {
            return self::t( $lang, 'Je n’ai pas trouvé de réponse à votre question. Vous pouvez en parler directement à un agent :', 'I couldn’t find an answer to your question. You can talk about it directly with an agent:', 'Mwen pa jwenn repons pou kesyon ou an. Ou ka pale de sa dirèkteman ak yon ajan:' );
        }
        return sprintf(
            self::t( $lang, 'Je n’ai pas trouvé de réponse pour « %s ». Vous pouvez en parler directement à un agent :', 'I couldn’t find an answer for “%s”. You can talk about it directly with an agent:', 'Mwen pa jwenn repons pou « %s ». Ou ka pale de sa dirèkteman ak yon ajan:' ),
            $quote
        );
    }

    /** Language of a stored « je n'ai pas compris » card, read from its title. */
    private static function misunderstood_lang( $meta ) {
        $title = (string) ( $meta['title'] ?? '' );
        foreach ( array( 'en', 'ht' ) as $lang ) {
            if ( self::misunderstood_title( $lang ) === $title ) return $lang;
        }
        return 'fr';
    }

    /** The home search box: catalogue first, then an honest "nothing found" card. */
    private static function product_search_reply( $body, $lang ) {
        $t = remove_accents( strtolower( $body ) );
        if ( DSA2_Products::enabled() ) {
            $ids = DSA2_Products::find( $t );
            if ( ! $ids ) $ids = DSA2_Products::search( $body );
            if ( $ids ) {
                $payload = DSA2_Products::payload( $ids, $lang, $t );
                if ( $payload ) return $payload;
            }
        }
        $shop = self::store_url( 'shop' );
        return array( 'type' => 'help_card', 'body' => 'no_products:' . substr( md5( $t ), 0, 8 ), 'meta' => array(
            'title' => $lang === 'en' ? 'No product found' : ( $lang === 'ht' ? 'Nou pa jwenn pwodwi sa a' : 'Aucun produit trouvé' ),
            'intro' => $lang === 'en' ? 'Try another name, browse the shop, or ask an agent — we may still have it.' : ( $lang === 'ht' ? 'Eseye yon lòt non, gade boutik la, oswa mande yon ajan — nou ka genyen l kanmenm.' : 'Essayez un autre nom, parcourez la boutique ou demandez à un agent : nous l’avons peut-être quand même.' ),
            'buttons' => array(
                array( 'label' => $lang === 'en' ? 'Browse the shop' : ( $lang === 'ht' ? 'Gade boutik la' : 'Voir la boutique' ), 'action' => 'url', 'value' => $shop ),
                array( 'label' => $lang === 'en' ? 'Ask an agent' : ( $lang === 'ht' ? 'Mande yon ajan' : 'Demander à un agent' ), 'action' => 'agent', 'value' => '' ),
            ),
        ) );
    }

    /** Shown when nothing matched, and by the Help quick action. */
    private static function help_payload( $lang, $unmatched = false ) {
        $title = $unmatched
            ? ( $lang === 'en' ? 'Let me point you the right way' : ( $lang === 'ht' ? 'Kite m mennen w kote ki bon an' : 'Voici comment vous aider' ) )
            : ( $lang === 'en' ? 'What can I help you with?' : ( $lang === 'ht' ? 'Ak kisa m ka ede w?' : 'Comment puis-je vous aider ?' ) );
        $intro = $unmatched
            ? ( $lang === 'en' ? 'Your message is saved and an agent can read it. Meanwhile, pick one of these:' : ( $lang === 'ht' ? 'Mesaj ou anrejistre epi yon ajan ka li l. Antretan, chwazi youn nan sa yo:' : 'Votre message est enregistré et un agent peut le lire. En attendant, choisissez :' ) )
            : ( $lang === 'en' ? 'Choose a topic, or type a product name for its price.' : ( $lang === 'ht' ? 'Chwazi yon sijè, oswa ekri non yon pwodwi pou pri l.' : 'Choisissez un sujet, ou écrivez le nom d’un produit pour son prix.' ) );

        return array(
            'type' => 'help_card',
            'body' => 'help',
            'meta' => array(
                'title' => $title,
                'intro' => $intro,
                'buttons' => array(
                    array( 'label' => $lang === 'en' ? 'Step-by-step guides' : ( $lang === 'ht' ? 'Gid etap pa etap' : 'Guides pas à pas' ), 'action' => 'quick', 'value' => 'guides' ),
                    array( 'label' => $lang === 'en' ? 'Top up my balance' : ( $lang === 'ht' ? 'Rechaje balans mwen' : 'Recharger mon solde' ), 'action' => 'guide', 'value' => 'recharge' ),
                    array( 'label' => $lang === 'en' ? 'Our products' : ( $lang === 'ht' ? 'Pwodwi nou yo' : 'Nos produits' ), 'action' => 'quick', 'value' => 'products' ),
                    array( 'label' => $lang === 'en' ? 'Talk to an agent' : ( $lang === 'ht' ? 'Pale ak yon ajan' : 'Parler à un agent' ), 'action' => 'agent', 'value' => '' ),
                ),
            ),
        );
    }


    /** Tappable list of the Builder questions — instant answers without typing. */
    private static function faq_menu_payload( $lang ) {
        DSA2_Install::maybe_sync_faq();
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT id, question FROM " . $wpdb->prefix . "dap_faq WHERE active=1 ORDER BY id ASC LIMIT 10" );
        $items = array();
        foreach ( (array) $rows as $r ) {
            $question = trim( wp_strip_all_tags( (string) $r->question ) );
            if ( $question !== '' ) $items[] = array( 'id' => (int) $r->id, 'title' => $question );
        }
        if ( ! $items ) return self::help_payload( $lang );
        return array( 'type' => 'faq_menu', 'body' => 'faq_menu', 'meta' => array(
            'title' => $lang === 'en' ? 'Frequently asked questions' : ( $lang === 'ht' ? 'Kesyon yo poze souvan' : 'Questions fréquentes' ),
            'intro' => $lang === 'en' ? 'Tap a question for an instant answer.' : ( $lang === 'ht' ? 'Peze yon kesyon pou jwenn repons lan touswit.' : 'Touchez une question pour obtenir la réponse immédiatement.' ),
            'items' => $items,
            'buttons' => array( array( 'label' => $lang === 'en' ? 'Talk to an agent' : ( $lang === 'ht' ? 'Pale ak yon ajan' : 'Parler à un agent' ), 'action' => 'agent', 'value' => '' ) ),
        ) );
    }

    /**
     * 6.6.4: the buttons of a Builder FAQ row, as the widget and the app can follow them.
     *
     * The Builder stores whatever the owner typed. « Wallet Delicat Store » offered
     * « Mon solde », « Recharger Wallet » and « Guide Natcash Auto » with actions this
     * plugin does not have, so the widget drew dead buttons and the app opened missing
     * pages (owner, 2026-10-27). With Delicat App API 2.33.1 active, each such button
     * becomes the pair both surfaces follow (quick:balance, url:wallet, quick:natcash…);
     * the assistant's own actions, real pages and other sites pass untouched. Without it
     * the buttons stay exactly as stored.
     */
    private static function faq_buttons( $raw ) {
        $buttons = json_decode( (string) $raw, true );
        if ( ! is_array( $buttons ) || ! $buttons ) return array();
        if ( class_exists( 'Delicat_App_Chat_Actions' ) && method_exists( 'Delicat_App_Chat_Actions', 'button' ) ) {
            $buttons = array_map( array( 'Delicat_App_Chat_Actions', 'button' ), $buttons );
        }
        return $buttons;
    }

    private static function faq_answer_payload( $id, $lang ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . $wpdb->prefix . "dap_faq WHERE id=%d AND active=1", absint( $id ) ) );
        if ( ! $row ) return null;
        $answer = $lang === 'en' && $row->answer_en ? $row->answer_en : ( $lang === 'ht' && $row->answer_ht ? $row->answer_ht : $row->answer_fr );
        $answer = trim( wp_strip_all_tags( (string) $answer ) );
        if ( $answer === '' ) return null;
        return array( 'body' => $answer, 'type' => 'faq_card', 'meta' => array( 'title' => trim( wp_strip_all_tags( (string) $row->question ) ), 'buttons' => self::faq_buttons( $row->buttons ), 'chips'=>array( array( 'label'=>self::t( $lang, 'Autres questions', 'Other questions', 'Lòt kesyon' ), 'action'=>'quick', 'value'=>'faq' ) ), 'source' => 'builder_faq' ) );
    }

    private static function faq_reply( $normalized, $lang='fr' ) {
        DSA2_Install::maybe_sync_faq();
        global $wpdb;
        $table = $wpdb->prefix . 'dap_faq';
        $rows = $wpdb->get_results( "SELECT * FROM $table WHERE active=1 ORDER BY id ASC LIMIT 200" );
        $best = null; $best_score = 0;
        foreach ( (array) $rows as $row ) {
            $score = 0;
            foreach ( array_filter( array_map( 'trim', explode( ',', (string)$row->keywords ) ) ) as $key ) {
                $key = remove_accents( strtolower( $key ) );
                if ( $key === '' ) continue;
                // Short keywords need word boundaries: "pin" must not fire inside "shopping".
                $hit = strlen( $key ) >= 5
                    ? ( strpos( $normalized, $key ) !== false )
                    : (bool) preg_match( '/\b' . preg_quote( $key, '/' ) . '\b/', $normalized );
                if ( $hit ) $score += max( 1, strlen( $key ) );
            }
            if ( $score > $best_score ) { $best=$row; $best_score=$score; }
        }
        if ( $best ) {
            $answer = $lang==='en' && $best->answer_en ? $best->answer_en : ( $lang==='ht' && $best->answer_ht ? $best->answer_ht : $best->answer_fr );
            $answer = trim( wp_strip_all_tags( (string) $answer ) ); // JS escapes on render — raw HTML would display as literal tags.
            return array( 'body'=>$answer, 'type'=>'faq_card', 'meta'=>array( 'title'=>$best->question, 'buttons'=>self::faq_buttons( $best->buttons ), 'chips'=>array( array( 'label'=>self::t( $lang, 'Autres questions', 'Other questions', 'Lòt kesyon' ), 'action'=>'quick', 'value'=>'faq' ) ), 'source'=>'builder_faq' ) );
        }
        $settings = get_option('dap_settings', array());
        foreach ( preg_split('/\r\n|\r|\n/', (string)($settings['faq_text'] ?? '')) as $line ) {
            if (strpos($line, '=') === false) continue;
            list($keys,$answer)=array_map('trim', explode('=', $line, 2));
            foreach (explode('|',$keys) as $key) {
                $key=remove_accents(strtolower(trim($key)));
                if ($key && strpos($normalized,$key)!==false) return array('body'=>$answer,'type'=>'text','meta'=>array('source'=>'manual_faq'));
            }
        }
        return null;
    }

    public static function quick_action_payload( $action, $lang='fr', $user_id = null, $context = array() ) {
        // The app authenticates over REST, so is_user_logged_in() is false there even for a
        // customer who linked their account. Take the identity from the caller instead, or
        // fall back to the browser session for the web widget.
        $uid = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;
        $context = self::sanitize_context($context);
        $feature_settings = get_option('dap_settings', array());
        if ( 'product_context' === $action && isset($feature_settings['product_context_enabled']) && empty($feature_settings['product_context_enabled']) ) $action = 'products';
        if ( in_array($action,array('order_center','payment_missing','pin_missing','player_id_help','order_agent'),true) && isset($feature_settings['order_support_enabled']) && empty($feature_settings['order_support_enabled']) ) $action = 'last_order';
        $signed_in = $uid > 0;
        $account_url = self::account_url();

        // guide_natcash_auto, guide_recharge, ... come from the guide buttons.
        if ( 0 === strpos( $action, 'guide_' ) ) {
            $guide = DSA2_Guides::payload( substr( $action, 6 ), $lang );
            // 6.6.3: a button of a retired guide, still in an old conversation, opens the menu.
            return ( $guide || ! DSA2_Guides::is_retired( substr( $action, 6 ) ) ) ? $guide : DSA2_Guides::menu_payload( $lang );
        }
        // faq_12 answers one Builder question; "faq" lists them all.
        if ( 0 === strpos( $action, 'faq_' ) ) {
            return self::faq_answer_payload( absint( substr( $action, 4 ) ), $lang );
        }
        if ( 'faq' === $action ) return self::faq_menu_payload( $lang );
        // recommend = personalised list; reco_123 = what goes with product 123.
        if ( 'recommend' === $action ) {
            $reco = DSA2_Recommend::for_customer( $lang );
            return $reco ? $reco : self::quick_action_payload( 'products', $lang, $uid );
        }
        if ( 0 === strpos( $action, 'reco_' ) ) {
            $reco = DSA2_Recommend::related( absint( substr( $action, 5 ) ), $lang );
            return $reco ? $reco : self::quick_action_payload( 'products', $lang, $uid );
        }
        if ( 'end_chat' === $action ) {
            return array( 'type' => 'rate_card', 'body' => 'rate_request', 'meta' => array(
                'title' => self::t( $lang, 'Merci pour votre visite !', 'Thanks for stopping by!', 'Mèsi paske ou te pase!' ),
                'intro' => self::t( $lang, 'La conversation est terminée. Comment évaluez-vous notre aide ?', 'This conversation is closed. How would you rate our help?', 'Konvèsasyon an fini. Kijan ou evalye èd nou an?' ),
                'buttons' => array(
                    array( 'label' => self::rating_label( 'great', $lang ), 'action' => 'rate', 'value' => 'great' ),
                    array( 'label' => self::rating_label( 'good', $lang ), 'action' => 'rate', 'value' => 'good' ),
                    array( 'label' => self::rating_label( 'bad', $lang ), 'action' => 'rate', 'value' => 'bad' ),
                ),
            ) );
        }
        // Guests can still check one order by proving they own it.
        if ( ! $signed_in && in_array($action,array('last_order','order_center','payment_missing','pin_missing','order_agent'),true) ) {
            return array( 'type' => 'order_card', 'body' => 'order_lookup', 'meta' => array(
                'title'       => self::t( $lang, 'Suivre ma commande', 'Track my order', 'Swiv kòmand mwen' ),
                'intro'       => self::t( $lang, 'Entrez votre numéro de commande et l’e-mail ou le numéro WhatsApp utilisé lors de l’achat.', 'Enter your order number and the e-mail or WhatsApp number used at purchase.', 'Antre nimewo kòmand ou ak imel oswa nimewo WhatsApp ou te itilize a.' ),
                'orderLabel'  => self::t( $lang, 'Numéro de commande', 'Order number', 'Nimewo kòmand' ),
                'proofLabel'  => self::t( $lang, 'E-mail ou WhatsApp', 'E-mail or WhatsApp', 'Imel oswa WhatsApp' ),
                'button'      => self::t( $lang, 'Vérifier', 'Check', 'Tcheke' ),
                'privacy'     => self::t( $lang, 'Vérification sécurisée : nous ne montrons la commande qu’à son propriétaire.', 'Secure check: we only show an order to its owner.', 'Verifikasyon an sekirite: nou montre kòmand lan sèlman ak mèt li.' ),
                'buttons'     => array( array( 'label' => self::t( $lang, 'Se connecter', 'Sign in', 'Konekte' ), 'action' => 'url', 'value' => $account_url ) ),
            ) );
        }
        // Other personal information requires an account.
        if ( ! $signed_in && in_array( $action, array( 'balance', 'pin', 'rewards' ), true ) ) {
            return array( 'type' => 'link_card', 'body' => 'login_required', 'meta' => array(
                'title'       => self::t( $lang, 'Connexion requise', 'Sign-in required', 'Koneksyon obligatwa' ),
                'description' => self::t( $lang, 'SVP, veuillez vous connecter pour accéder à ces informations en toute sécurité.', 'Please sign in to access this information securely.', 'Tanpri konekte pou w ka jwenn enfòmasyon sa yo an sekirite.' ),
                'url'         => $account_url,
                'button'      => self::t( $lang, 'Se connecter', 'Sign in', 'Konekte' ),
                'icon'        => '🔐',
            ) );
        }
        if ( 'guides' === $action ) return DSA2_Guides::menu_payload( $lang );
        if ( 'help' === $action )   return self::help_payload( $lang );
        if ( 'recharge' === $action ) return DSA2_Guides::payload( 'recharge', $lang );

        switch ( $action ) {
            case 'product_context':
                return self::product_context_payload($context,$lang);
            case 'order_center':
                return array('type'=>'order_card','body'=>'order_center','meta'=>self::last_order_data($lang,$uid,true));
            case 'payment_missing':
                return self::order_issue_payload('payment_missing',$lang,$uid);
            case 'pin_missing':
                return self::order_issue_payload('pin_missing',$lang,$uid);
            case 'player_id_help':
                return self::order_issue_payload('player_id_help',$lang,$uid);
            case 'order_agent':
                return self::order_issue_payload('order_agent',$lang,$uid);
            case 'balance':
                $balance = self::wallet_balance( $uid );
                return array( 'type'=>'account_card', 'body'=>'wallet', 'meta'=>array(
                    'title' => $lang==='en'?'My store credit':($lang==='ht'?'Kredi boutik mwen':'Mon crédit boutique'),
                    'value' => $balance,
                    'status' => $signed_in ? 'secure' : 'login_required',
                    'url' => $signed_in ? self::store_url('wallet') : $account_url,
                    'button' => $signed_in ? ( $lang==='en'?'View my store credit':($lang==='ht'?'Gade kredi boutik mwen':'Voir mon crédit boutique') ) : ( $lang==='en'?'Sign in':($lang==='ht'?'Konekte':'Se connecter') )
                ) );
            case 'last_order':
                return array( 'type'=>'order_card', 'body'=>'order', 'meta'=>self::last_order_data( $lang, $uid ) );
            case 'topup':
                return array( 'type'=>'guide_card', 'body'=>'topup', 'meta'=>array(
                    'icon'=>'⚡',
                    'title'=> self::t( $lang, 'Recharge automatique', 'Automatic top-up', 'Rechaj otomatik' ),
                    'intro'=> self::t( $lang,
                        'La plupart des jeux sont crédités directement sur votre compte de jeu — aucun code à saisir.',
                        'Most games are credited straight to your game account — there is no code to enter.',
                        'Pifò jwèt yo kredite dirèkteman nan kont jwèt ou — pa gen okenn kòd pou antre.' ),
                    'steps'=> array(
                        self::t( $lang, 'Choisissez le jeu et le pack sur la boutique.', 'Pick the game and the pack in the shop.', 'Chwazi jwèt la ak pak la nan boutik la.' ),
                        self::t( $lang, 'Entrez votre Player ID (ou pseudo pour Roblox) et vérifiez que le nom affiché est bien le vôtre.', 'Enter your Player ID (or username for Roblox) and check the nickname shown is yours.', 'Antre Player ID ou (oswa non itilizatè pou Roblox) epi verifye non ki parèt la se pa w.' ),
                        self::t( $lang, 'Payez avec votre crédit boutique Delicat, ajouté par MonCash ou NatCash.', 'Pay with your Delicat store credit, added through MonCash or NatCash.', 'Peye ak kredi boutik Delicat ou, ke ou ajoute ak MonCash oswa NatCash.' ),
                        self::t( $lang, 'La recharge arrive dans le jeu en moins de 60 secondes. Relancez le jeu si le solde ne bouge pas tout de suite.', 'The top-up lands in the game in under 60 seconds. Restart the game if the balance does not move right away.', 'Rechaj la rive nan jwèt la nan mwens pase 60 segonn. Rilanse jwèt la si balans lan pa bouje touswit.' ),
                    ),
                    'tip'=> self::t( $lang,
                        'Nous ne demandons jamais votre mot de passe : le Player ID suffit.',
                        'We never ask for your password: the Player ID is enough.',
                        'Nou pa janm mande modpas ou: Player ID a ase.' ),
                    'buttons'=> array(
                        array( 'label'=> self::t( $lang, 'Voir les jeux', 'Browse games', 'Gade jwèt yo' ), 'action'=>'quick', 'value'=>'products' ),
                        array( 'label'=> self::t( $lang, 'Où est mon Player ID ?', 'Where is my Player ID?', 'Kote Player ID mwen?' ), 'action'=>'quick', 'value'=>'player_id_help' ),
                    ),
                    'chips'=> array(
                        array( 'label'=> self::t( $lang, 'Recharge non reçue', 'Top-up not received', 'Rechaj pa rive' ), 'action'=>'quick', 'value'=>'agent' ),
                    ),
                ) );
            case 'pin':
                return array( 'type'=>'link_card', 'body'=>'pin', 'meta'=>array(
                    'title'=>'Free Fire PIN', 'description'=>$lang==='en'?'Open your delivered PINs and redeem them securely.':($lang==='ht'?'Louvri PIN ou resevwa yo epi itilize yo an sekirite.':'Ouvrez vos PIN livrés et utilisez-les en toute sécurité.'),
                    'url'=>self::store_url('redeem'), 'button'=>$lang==='en'?'View my PIN':($lang==='ht'?'Gade PIN mwen':'Voir mon PIN'), 'icon'=>'💎'
                ) );
            case 'rewards':
                return array( 'type'=>'link_card', 'body'=>'rewards', 'meta'=>array(
                    'title'=>$lang==='en'?'Rewards':($lang==='ht'?'Rekonpans':'Récompenses'), 'description'=>$lang==='en'?'View your level, progress and available rewards.':($lang==='ht'?'Gade nivo ou, pwogrè ou ak rekonpans ki disponib yo.':'Consultez votre niveau, votre progression et vos récompenses disponibles.'),
                    'url'=>self::store_url('rewards'), 'button'=>$lang==='en'?'Open rewards':($lang==='ht'?'Louvri rekonpans':'Voir mes récompenses'), 'icon'=>'🎁'
                ) );
            case 'products':
                if ( DSA2_Products::enabled() ) {
                    $highlights = DSA2_Products::highlights( $lang );
                    if ( $highlights ) return $highlights;
                }
                return array( 'type'=>'link_card', 'body'=>'products', 'meta'=>array(
                    'title'=>$lang==='en'?'Our products':($lang==='ht'?'Pwodwi nou yo':'Nos produits'), 'description'=>$lang==='en'?'Browse games, gift cards and digital services.':($lang==='ht'?'Gade jwèt, kat kado ak sèvis dijital yo.':'Découvrez les jeux, cartes cadeaux et services numériques.'),
                    'url'=>self::store_url('shop'), 'button'=>$lang==='en'?'Browse products':($lang==='ht'?'Gade pwodwi yo':'Voir les produits'), 'icon'=>'🛍️'
                ) );
            case 'natcash':
                $guide = DSA2_Guides::payload( 'natcash_auto', $lang );
                if ( $guide ) return $guide;
                return array( 'type'=>'link_card', 'body'=>'natcash', 'meta'=>array(
                    'title'=>'Natcash Auto (TC)', 'description'=>$lang==='en'?'Add store credit automatically with your transaction code.':($lang==='ht'?'Ajoute kredi boutik otomatikman avèk TransCode ou.':'Ajoutez du crédit boutique automatiquement avec votre TransCode.'),
                    'url'=>self::store_url('wallet'), 'button'=>$lang==='en'?'Recharge now':($lang==='ht'?'Rechaje kounye a':'Recharger maintenant'), 'icon'=>'⚡'
                ) );
            case 'agent':
                $s = get_option('dap_settings', array());
                $wa = preg_replace('/\D+/', '', $s['whatsapp'] ?? '');
                $status = self::effective_status(); // offline outside opening hours, not just when toggled
                if ( 'offline' === $status ) {
                    $desc = $lang==='en'?'No agent is online right now. Write your question here — it is saved and answered as soon as an agent is back.':($lang==='ht'?'Pa gen ajan sou entènèt kounye a. Ekri kesyon w isit la — nou anrejistre l epi n ap reponn kou yon ajan tounen.':'Aucun agent n’est en ligne pour le moment. Écrivez votre question ici : elle est enregistrée et traitée dès le retour d’un agent.');
                } else {
                    $desc = $lang==='en'?'An agent has been notified. Write your question here and stay on this chat — the reply appears in this conversation.':($lang==='ht'?'Nou avèti yon ajan. Ekri kesyon w isit la epi rete nan chat la — repons lan ap parèt nan konvèsasyon sa a.':'Un agent a été prévenu. Écrivez votre question ici et restez sur ce chat : la réponse arrive dans cette conversation.');
                }
                return array( 'type'=>'agent_card', 'body'=>'agent', 'meta'=>array(
                    'title'=>$lang==='en'?'Talk to an agent':($lang==='ht'?'Pale ak yon ajan':'Parler à un agent'),
                    'description'=>trim( $desc . ( 'offline' === $status && ! empty( $s['business_hours'] ) ? ' (' . $s['business_hours'] . ')' : '' ) ),
                    'hint'=>$lang==='en'?'Include your order number, amount or TransCode if you have one.':($lang==='ht'?'Mete nimewo kòmand, montan oswa TransCode ou si ou genyen.':'Indiquez votre numéro de commande, le montant ou le TransCode si vous en avez un.'),
                    'whatsapp'=>$wa ? 'https://wa.me/'.$wa : '', 'status'=>$status, 'eta'=>self::agent_eta($status)
                ) );
        }
        return null;
    }

    private static function product_context_payload($context,$lang) {
        $context=self::sanitize_context($context);
        $pid=(int)($context['product_id']??0);
        if($pid && DSA2_Products::enabled()){
            $payload=DSA2_Products::payload(array($pid),$lang,'context');
            if($payload){
                $selection=array_filter(array($context['variation']??'', $context['plan']??'', $context['price']??''));
                $payload['body']='product_context:'.$pid.':'.md5(wp_json_encode($selection));
                $payload['meta']['title']=self::t($lang,'Aide sur ce produit','Help with this product','Èd sou pwodwi sa a');
                $payload['meta']['intro']=$selection?self::t($lang,'Votre sélection : ','Your selection: ','Seleksyon ou: ').implode(' · ',$selection):self::t($lang,'Que souhaitez-vous savoir sur ce produit ?','What would you like to know about this product?','Kisa ou vle konnen sou pwodwi sa a?');
                $payload['meta']['context']=$context;
                $payload['meta']['buttons']=array(
                    array('label'=>self::t($lang,'Comment commander','How to order','Kijan pou kòmande'),'action'=>'guide','value'=>'buy'),
                    array('label'=>self::t($lang,'Délai de livraison','Delivery time','Delè livrezon'),'action'=>'guide','value'=>'delivery'),
                    array('label'=>self::t($lang,'Parler à un agent','Talk to an agent','Pale ak yon ajan'),'action'=>'agent','value'=>''),
                );
                return $payload;
            }
        }
        return array('type'=>'help_card','body'=>'product_context','meta'=>array(
            'title'=>self::t($lang,'Question sur ce produit','Question about this product','Kesyon sou pwodwi sa a'),
            'intro'=>self::t($lang,'Écrivez votre question. Le nom du produit et votre sélection seront joints automatiquement, sans transmettre votre Player ID.','Write your question. The product name and selection are attached automatically without sending your Player ID.','Ekri kesyon ou. Non pwodwi a ak seleksyon ou ap ajoute otomatikman san voye Player ID ou.'),
            'buttons'=>array(array('label'=>self::t($lang,'Parler à un agent','Talk to an agent','Pale ak yon ajan'),'action'=>'agent','value'=>'')),
            'context'=>$context,
        ));
    }

    private static function order_issue_payload($issue,$lang,$uid){
        $order=self::last_order_data($lang,$uid,true);
        if($uid<1)return array('type'=>'order_card','body'=>'order_lookup','meta'=>$order);
        $map=array(
            'payment_missing'=>array('💳',self::t($lang,'Paiement effectué, solde non crédité','Payment made, balance not credited','Peman fèt, balans pa kredite'),self::t($lang,'Vérifiez le TransCode, le montant et le numéro utilisé. Ne partagez jamais votre code secret. Si le délai normal est dépassé, transférez la commande à un agent.','Check the TransCode, amount and number used. Never share your secret code. If the normal delay has passed, transfer the order to an agent.','Verifye TransCode, montan ak nimewo ou te itilize a. Pa janm pataje kòd sekrè ou. Si delè nòmal la pase, voye kòmand lan bay yon ajan.')),
            'pin_missing'=>array('💎',self::t($lang,'PIN non reçu','PIN not received','PIN pa rive'),self::t($lang,'Ouvrez les détails de la commande et la page Mes PIN. Si la commande est terminée mais aucun PIN n’apparaît, contactez un agent avec le numéro de commande.','Open the order details and My PIN page. If the order is completed but no PIN appears, contact an agent with the order number.','Louvri detay kòmand lan ak paj PIN mwen. Si kòmand lan fini men PIN pa parèt, kontakte yon ajan ak nimewo kòmand lan.')),
            'player_id_help'=>array('🎮',self::t($lang,'Vérifier mon Player ID','Check my Player ID','Verifye Player ID mwen'),self::t($lang,'Copiez votre Player ID directement depuis le jeu, vérifiez la région et évitez les espaces. Pour votre sécurité, le chat transmet seulement si le champ est rempli, jamais la valeur elle-même.','Copy the Player ID directly from the game, check the region and remove spaces. For security, chat only reports whether the field is filled, never the value.','Kopye Player ID a dirèkteman nan jwèt la, verifye rejyon an epi retire espas. Pou sekirite, chat la sèlman di si chan an ranpli, li pa voye valè a.')),
            'order_agent'=>array('💬',self::t($lang,'Agent pour cette commande','Agent for this order','Ajan pou kòmand sa'),self::t($lang,'La dernière commande et son statut seront visibles par l’agent autorisé. Écrivez le problème sans partager mot de passe, OTP ou PIN.','The latest order and its status will be visible to the authorised agent. Describe the issue without sharing a password, OTP or PIN.','Ajan otorize a ap wè dènye kòmand lan ak estati li. Ekri pwoblèm nan san pataje modpas, OTP oswa PIN.')),
        );
        $item=$map[$issue]??$map['order_agent'];
        if ( 'order_agent' === $issue ) {
            $status=self::effective_status();
            return array('type'=>'agent_card','body'=>'order_agent:'.md5(wp_json_encode($order)),'meta'=>array(
                'title'=>$item[0].' '.$item[1],
                'description'=>$item[2],
                'hint'=>self::t($lang,'Décrivez le problème ici. Ne partagez jamais mot de passe, OTP ou PIN.','Describe the issue here. Never share a password, OTP or PIN.','Eksplike pwoblèm nan isit la. Pa janm pataje modpas, OTP oswa PIN.'),
                'status'=>$status,'eta'=>self::agent_eta($status),
                'order'=>array_intersect_key($order,array_flip(array('title','status','total','date','url'))),
            ));
        }
        $buttons=array();
        if(!empty($order['url']))$buttons[]=array('label'=>self::t($lang,'Voir la commande','View order','Gade kòmand lan'),'action'=>'url','value'=>$order['url']);
        $buttons[]=array('label'=>self::t($lang,'Transférer à un agent','Transfer to an agent','Voye bay yon ajan'),'action'=>'agent','value'=>'');
        return array('type'=>'help_card','body'=>$issue,'meta'=>array('title'=>$item[0].' '.$item[1],'intro'=>$item[2],'buttons'=>$buttons,'chips'=>$order['chips']??array(),'order'=>array_intersect_key($order,array_flip(array('title','status','total','date','url')))));
    }

    private static function wallet_balance( $user_id = null ) {
        $uid = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;
        if ( $uid < 1 ) return '—';
        if ( function_exists( 'woo_wallet' ) ) {
            try { return wp_strip_all_tags( woo_wallet()->wallet->get_wallet_balance( $uid ) ); } catch ( Throwable $e ) {}
        }
        return function_exists('wc_price') ? wp_strip_all_tags( wc_price(0) ) : '0';
    }

    private static function last_order_data( $lang, $user_id = null, $support_center = false ) {
        $uid = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;
        $title = self::t( $lang, 'Ma dernière commande', 'My last order', 'Dènye kòmand mwen' );
        if ( $uid < 1 || ! function_exists( 'wc_get_orders' ) ) {
            return array( 'title'=>$title, 'empty'=>true,
                'description'=>self::t( $lang, 'Connectez-vous pour consulter vos commandes.', 'Sign in to see your orders.', 'Konekte pou w wè kòmand ou yo.' ),
                'url'=>self::account_url(),
                'button'=>self::t( $lang, 'Se connecter', 'Sign in', 'Konekte' ) );
        }
        $orders = wc_get_orders(array('customer_id'=>$uid,'limit'=>1,'orderby'=>'date','order'=>'DESC'));
        if ( ! $orders ) return array( 'title'=>$title, 'empty'=>true,
            'description'=>self::t( $lang, 'Aucune commande trouvée.', 'No order found.', 'Nou pa jwenn okenn kòmand.' ),
            'url'=>wc_get_account_endpoint_url('orders'),
            'button'=>self::t( $lang, 'Voir mes commandes', 'View my orders', 'Gade kòmand mwen yo' ) );
        $o = $orders[0];
        $chips = array(
            array('label'=>self::t($lang,'Paiement non crédité','Payment not credited','Peman pa kredite'),'action'=>'quick','value'=>'payment_missing'),
            array('label'=>self::t($lang,'PIN non reçu','PIN not received','PIN pa rive'),'action'=>'quick','value'=>'pin_missing'),
            array('label'=>self::t($lang,'Vérifier mon Player ID','Check my Player ID','Verifye Player ID mwen'),'action'=>'quick','value'=>'player_id_help'),
            array('label'=>self::t($lang,'Agent pour cette commande','Agent for this order','Ajan pou kòmand sa'),'action'=>'quick','value'=>'order_agent'),
            array( 'label' => self::t( $lang, 'Délai de livraison', 'Delivery time', 'Delè livrezon' ), 'action' => 'guide', 'value' => 'delivery' )
        );
        $reco = DSA2_Recommend::chip( $lang );
        if ( $reco ) array_unshift( $chips, $reco );
        return array(
            'title'=>sprintf( self::t( $lang, 'Commande #%s', 'Order #%s', 'Kòmand #%s' ), $o->get_order_number() ),
            'empty'=>false,
            'status'=>wc_get_order_status_name($o->get_status()),
            'total'=>wp_strip_all_tags($o->get_formatted_order_total()),
            'date'=>wc_format_datetime($o->get_date_created()),
            'url'=>$o->get_view_order_url(),
            'button'=>self::t( $lang, 'Voir les détails', 'View details', 'Gade detay yo' ),
            'chips'=>$chips
        );
    }

    public static function format_messages( $messages ) {
        $agent_names = array();
        foreach ( (array) $messages as $m ) {
            $agent_id = isset( $m->sender_id ) ? (int) $m->sender_id : 0;
            if ( $agent_id && ! isset( $agent_names[ $agent_id ] ) ) {
                $user = get_userdata( $agent_id );
                $agent_names[ $agent_id ] = $user ? sanitize_text_field( $user->display_name ) : '';
            }
        }
        return array_map( function( $m ) use ( $agent_names ) {
            $agent_id = isset( $m->sender_id ) ? (int) $m->sender_id : 0;
            return array(
                'id'=>(int)$m->id,
                'sender'=>$m->sender,
                'sender_id'=>$agent_id,
                'sender_name'=>$agent_id && isset( $agent_names[ $agent_id ] ) ? $agent_names[ $agent_id ] : '',
                'channel'=>isset( $m->channel ) && in_array( $m->channel, array( 'bot', 'agent', 'system' ), true ) ? $m->channel : self::message_channel( '', $m->sender, $m->message_type ),
                // Decode so messages stored double-encoded by v1.2.0 render correctly; the JS escapes on output.
                'body'=>html_entity_decode( $m->body, ENT_QUOTES, 'UTF-8' ),
                'type'=>$m->message_type,
                'meta'=>$m->meta ? json_decode($m->meta,true) : array(),
                'time'=>mysql2date('H:i',$m->created_at),
            );
        }, $messages );
    }
}
