<?php
/**
 * Authenticated Android support desk.
 *
 * Customer installation tokens and administrator tokens are deliberately kept
 * in different tables, namespaces and permission callbacks.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class DSA2_Admin_Mobile_API {
    const NS = 'delicat-assistant-admin/v1';
    const PUSH_ACTION = 'dsa2_admin_mobile_push';

    public static function init() {
        static $initialized = false;
        if ( $initialized ) return;
        $initialized = true;
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'dsa2_message_created', array( __CLASS__, 'message_created' ), 10, 6 );
        add_action( self::PUSH_ACTION, array( __CLASS__, 'deliver_push' ), 10, 2 );
        add_action( 'admin_post_dap_revoke_admin_sessions', array( __CLASS__, 'revoke_from_dashboard' ) );
        add_action( 'profile_update', array( __CLASS__, 'revoke_if_permission_removed' ), 10, 1 );
        add_action( 'deleted_user', array( __CLASS__, 'revoke_user' ), 10, 1 );
        add_action( 'after_password_reset', array( __CLASS__, 'revoke_after_password_reset' ), 10, 2 );
    }

    public static function routes() {
        $public = array( __CLASS__, 'public_allowed' );
        $secure = array( __CLASS__, 'allow' );
        register_rest_route( self::NS, '/auth/login', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'login' ), 'permission_callback' => $public,
        ) );
        register_rest_route( self::NS, '/auth/logout', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'logout' ), 'permission_callback' => $secure,
        ) );
        register_rest_route( self::NS, '/auth/refresh', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'refresh' ), 'permission_callback' => $secure,
        ) );
        foreach ( array(
            '/me' => 'me',
            '/dashboard' => 'dashboard',
            '/inbox' => 'inbox',
            '/agents' => 'agents',
            '/tickets' => 'tickets',
        ) as $path => $callback ) {
            register_rest_route( self::NS, $path, array(
                'methods' => 'GET', 'callback' => array( __CLASS__, $callback ), 'permission_callback' => $secure,
            ) );
        }
        register_rest_route( self::NS, '/device', array(
            array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'register_device' ), 'permission_callback' => $secure ),
            array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'unregister_device' ), 'permission_callback' => $secure ),
        ) );
        register_rest_route( self::NS, '/conversations/(?P<id>\d+)', array(
            array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'conversation' ), 'permission_callback' => $secure ),
            array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_conversation' ), 'permission_callback' => $secure ),
        ) );
        register_rest_route( self::NS, '/conversations/(?P<id>\d+)/messages', array(
            array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'messages' ), 'permission_callback' => $secure ),
            array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'reply' ), 'permission_callback' => $secure ),
        ) );
        register_rest_route( self::NS, '/conversations/(?P<id>\d+)/notes', array(
            array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'notes' ), 'permission_callback' => $secure ),
            array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'add_note' ), 'permission_callback' => $secure ),
        ) );
        register_rest_route( self::NS, '/conversations/(?P<id>\d+)/attachments', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'attachment' ), 'permission_callback' => $secure,
        ) );
        register_rest_route( self::NS, '/conversations/(?P<id>\d+)/read', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'mark_read' ), 'permission_callback' => $secure,
        ) );
        register_rest_route( self::NS, '/conversations/(?P<id>\d+)/typing', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'typing' ), 'permission_callback' => $secure,
        ) );
        register_rest_route( self::NS, '/tickets/(?P<id>\d+)', array(
            'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_ticket' ), 'permission_callback' => $secure,
        ) );
    }

    public static function public_allowed() {
        $settings = get_option( 'dap_settings', array() );
        if ( isset( $settings['admin_app_enabled'] ) && ! $settings['admin_app_enabled'] ) {
            return new WP_Error( 'app_disabled', 'Application administrateur désactivée.', array( 'status' => 403 ) );
        }
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $local = defined( 'WP_DEBUG' ) && WP_DEBUG && in_array( $remote, array( '127.0.0.1', '::1' ), true );
        if ( ! is_ssl() && ! $local ) {
            return new WP_Error( 'https_required', 'Une connexion HTTPS est obligatoire.', array( 'status' => 403 ) );
        }
        return true;
    }

    private static function token( $request ) {
        $token = trim( (string) $request->get_header( 'X-Delicat-Admin-Token' ) );
        if ( '' === $token ) {
            $authorization = (string) $request->get_header( 'Authorization' );
            if ( preg_match( '/^Bearer\s+([a-fA-F0-9]{64})$/', $authorization, $matches ) ) {
                $token = $matches[1];
            }
        }
        return preg_match( '/^[a-fA-F0-9]{64}$/', $token ) ? strtolower( $token ) : '';
    }

    private static function hash_token( $token ) {
        return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) . '|dap-admin-v1' );
    }

    private static function random_token() {
        try {
            return bin2hex( random_bytes( 32 ) );
        } catch ( Throwable $exception ) {
            return hash( 'sha256', wp_generate_password( 80, true, true ) . wp_salt( 'secure_auth' ) . microtime( true ) );
        }
    }

    private static function support_user( $user ) {
        return $user instanceof WP_User && (
            user_can( $user, DSA2_Install::CAP_SUPPORT ) ||
            user_can( $user, 'manage_woocommerce' ) ||
            user_can( $user, 'manage_options' )
        );
    }

    private static function manager_user( $user ) {
        return $user instanceof WP_User && (
            user_can( $user, DSA2_Install::CAP_SETTINGS ) ||
            user_can( $user, 'manage_woocommerce' ) ||
            user_can( $user, 'manage_options' )
        );
    }

    private static function limited( $bucket, $identity, $limit, $window ) {
        $key = 'dap_adm_' . sanitize_key( $bucket ) . '_' . hash_hmac( 'sha256', (string) $identity, wp_salt( 'nonce' ) );
        $record = get_transient( $key );
        $now = time();
        if ( ! is_array( $record ) || ! isset( $record['expires'], $record['count'] ) || $now >= (int) $record['expires'] ) {
            $record = array( 'count' => 0, 'expires' => $now + max( 1, (int) $window ) );
        }
        if ( (int) $record['count'] >= (int) $limit ) return true;
        $record['count'] = (int) $record['count'] + 1;
        set_transient( $key, $record, max( 1, (int) $record['expires'] - $now ) );
        return false;
    }

    public static function allow( $request ) {
        $enabled = self::public_allowed();
        if ( is_wp_error( $enabled ) ) return $enabled;
        $token = self::token( $request );
        if ( '' === $token ) return new WP_Error( 'missing_token', 'Session administrateur manquante.', array( 'status' => 401 ) );
        global $wpdb;
        $table = $wpdb->prefix . 'dap_admin_sessions';
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE token_hash=%s AND expires_at>UTC_TIMESTAMP() LIMIT 1",
            self::hash_token( $token )
        ) );
        if ( ! $session ) return new WP_Error( 'invalid_token', 'Session expirée. Reconnectez-vous.', array( 'status' => 401 ) );
        $user = get_userdata( (int) $session->user_id );
        if ( ! self::support_user( $user ) ) {
            $wpdb->delete( $table, array( 'user_id' => (int) $session->user_id ), array( '%d' ) );
            return new WP_Error( 'forbidden', 'Accès administrateur refusé.', array( 'status' => 403 ) );
        }
        if ( self::limited( 'request', $session->id, 360, MINUTE_IN_SECONDS ) ) {
            return new WP_Error( 'rate_limited', 'Trop de demandes. Réessayez dans une minute.', array( 'status' => 429 ) );
        }
        wp_set_current_user( (int) $user->ID );
        $request->set_param( '_dap_admin_session', $session );
        $request->set_param( '_dap_admin_user', $user );
        $last_seen = strtotime( (string) $session->last_seen_at . ' UTC' );
        if ( ! $last_seen || $last_seen < time() - 300 ) {
            $wpdb->update( $table, array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $session->id ), array( '%s' ), array( '%d' ) );
        }
        return true;
    }

    private static function response( $data, $status = 200 ) {
        $response = new WP_REST_Response( $data, (int) $status );
        $response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        return $response;
    }

    public static function login( $request ) {
        $username = trim( sanitize_text_field( (string) $request->get_param( 'username' ) ) );
        $password = (string) $request->get_param( 'password' );
        $installation = sanitize_text_field( (string) $request->get_param( 'installation_id' ) );
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ( '' === $username || strlen( $password ) > 4096 || ! preg_match( '/^[A-Za-z0-9_-]{16,100}$/', $installation ) ) {
            return new WP_Error( 'invalid_request', 'Informations de connexion invalides.', array( 'status' => 400 ) );
        }
        if ( self::limited( 'login_ip', $remote, 12, 15 * MINUTE_IN_SECONDS ) ||
             self::limited( 'login_user', strtolower( $username ), 6, 15 * MINUTE_IN_SECONDS ) ) {
            return new WP_Error( 'rate_limited', 'Trop de tentatives. Réessayez dans 15 minutes.', array( 'status' => 429 ) );
        }
        $user = wp_authenticate( $username, $password );
        if ( is_wp_error( $user ) || ! self::support_user( $user ) ) {
            return new WP_Error( 'invalid_credentials', 'Identifiants incorrects ou accès non autorisé.', array( 'status' => 401 ) );
        }
        $token = self::random_token();
        $settings = get_option( 'dap_settings', array() );
        $days = max( 1, min( 90, (int) ( $settings['admin_session_days'] ?? 30 ) ) );
        $now = gmdate( 'Y-m-d H:i:s' );
        $expires = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
        $label = DSA2_Compat::substr( sanitize_text_field( (string) $request->get_param( 'device_label' ) ), 0, 120 );
        global $wpdb;
        $stored = $wpdb->replace(
            $wpdb->prefix . 'dap_admin_sessions',
            array(
                'user_id' => (int) $user->ID,
                'installation_id' => $installation,
                'token_hash' => self::hash_token( $token ),
                'fcm_token' => '',
                'platform' => 'android',
                'device_label' => $label ?: 'Android',
                'expires_at' => $expires,
                'last_seen_at' => $now,
                'created_at' => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
        if ( false === $stored ) {
            return new WP_Error( 'session_failed', 'Impossible de créer la session sécurisée.', array( 'status' => 500 ) );
        }
        wp_set_current_user( (int) $user->ID );
        return self::response( array(
            'token' => $token,
            'expires_at' => mysql2date( 'c', $expires, false ),
            'api_base' => untrailingslashit( rest_url( self::NS ) ),
            'user' => self::user_payload( $user ),
            'features' => self::features(),
        ) );
    }

    private static function user_payload( $user ) {
        return array(
            'id' => (int) $user->ID,
            'name' => sanitize_text_field( $user->display_name ),
            'email' => sanitize_email( $user->user_email ),
            'avatar' => esc_url_raw( get_avatar_url( (int) $user->ID, array( 'size' => 128 ) ) ),
            'is_manager' => self::manager_user( $user ),
            'roles' => array_values( array_map( 'sanitize_key', (array) $user->roles ) ),
        );
    }

    private static function features() {
        $settings = get_option( 'dap_settings', array() );
        return array(
            'push_enabled' => ! isset( $settings['admin_push_enabled'] ) || ! empty( $settings['admin_push_enabled'] ),
            'push_configured' => self::push_configured(),
            'wallet' => function_exists( 'woo_wallet' ),
            'orders' => function_exists( 'wc_get_orders' ),
            'tickets' => post_type_exists( 'dap_ticket' ),
            'attachments' => true,
            'typing' => true,
            'read_receipts' => true,
            'internal_notes' => true,
            'handoff_context' => true,
            'all_conversations' => true,
            'plugin_version' => DSA2_VERSION,
        );
    }

    public static function me( $request ) {
        $user = $request->get_param( '_dap_admin_user' );
        $settings = get_option( 'dap_settings', array() );
        $replies = array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', (string) ( $settings['canned_replies'] ?? '' ) ) ) ) );
        return self::response( array(
            'user' => self::user_payload( $user ),
            'brand' => sanitize_text_field( (string) ( $settings['brand_name'] ?? 'Delicat Assistant' ) ),
            'store_url' => esc_url_raw( home_url( '/' ) ),
            'canned_replies' => array_slice( array_map( 'sanitize_textarea_field', $replies ), 0, 40 ),
            // v6.4.0: the grouped library, so the Android app shows the same messages
            // as the web inbox instead of only whatever was typed in settings.
            'quick_replies'  => class_exists( 'DSA2_QuickReplies' ) ? DSA2_QuickReplies::all( null ) : array(),
            'features' => self::features(),
        ) );
    }

    public static function dashboard( $request ) {
        global $wpdb;
        $user = $request->get_param( '_dap_admin_user' );
        $conv = $wpdb->prefix . 'dap_conversations';
        $messages = $wpdb->prefix . 'dap_messages';
        $access = self::manager_user( $user ) ? '' : $wpdb->prepare( ' AND (c.assigned_to=0 OR c.assigned_to=%d)', (int) $user->ID );
        $stats = $wpdb->get_row(
            "SELECT COUNT(*) AS total,
                COALESCE(SUM(c.status='waiting'),0) AS waiting,
                COALESCE(SUM(c.status='open'),0) AS active,
                COALESCE(SUM(c.status='resolved'),0) AS resolved,
                COALESCE(SUM(c.assigned_to=0 AND c.status<>'resolved'),0) AS unassigned,
                COALESCE(SUM(c.priority IN ('high','urgent') AND c.status<>'resolved'),0) AS urgent
             FROM $conv c
             WHERE EXISTS(SELECT 1 FROM $messages m WHERE m.conversation_id=c.id AND m.sender='customer')
             $access"
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $unread = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $messages m
             INNER JOIN $conv c ON c.id=m.conversation_id
             WHERE m.sender='customer' AND m.channel='agent' AND m.id>c.last_agent_read_id $access"
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $mine = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $conv WHERE assigned_to=%d AND status<>'resolved'",
            (int) $user->ID
        ) );
        return self::response( array(
            'total' => (int) ( $stats->total ?? 0 ),
            'waiting' => (int) ( $stats->waiting ?? 0 ),
            'active' => (int) ( $stats->active ?? 0 ),
            'resolved' => (int) ( $stats->resolved ?? 0 ),
            'unassigned' => (int) ( $stats->unassigned ?? 0 ),
            'unread' => $unread,
            'mine' => $mine,
            'urgent' => (int) ( $stats->urgent ?? 0 ),
            'agent_status' => DSA2_Data::effective_status(),
            'server_time' => gmdate( 'c' ),
        ) );
    }

    public static function inbox( $request ) {
        global $wpdb;
        $user = $request->get_param( '_dap_admin_user' );
        $conv = $wpdb->prefix . 'dap_conversations';
        $messages = $wpdb->prefix . 'dap_messages';
        $clauses = array( "EXISTS(SELECT 1 FROM $messages customer WHERE customer.conversation_id=c.id AND customer.sender='customer')" );
        $params = array();
        if ( ! self::manager_user( $user ) ) {
            $clauses[] = '(c.assigned_to=0 OR c.assigned_to=%d)';
            $params[] = (int) $user->ID;
        }
        $status = sanitize_key( (string) $request->get_param( 'status' ) );
        if ( in_array( $status, array( 'waiting', 'open', 'resolved' ), true ) ) {
            $clauses[] = 'c.status=%s';
            $params[] = $status;
        }
        $queue = sanitize_key( (string) $request->get_param( 'queue' ) );
        if ( 'mine' === $queue ) {
            $clauses[] = 'c.assigned_to=%d';
            $params[] = (int) $user->ID;
        } elseif ( 'unassigned' === $queue ) {
            $clauses[] = 'c.assigned_to=0';
        } elseif ( 'unread' === $queue ) {
            $clauses[] = "EXISTS(SELECT 1 FROM $messages unread WHERE unread.conversation_id=c.id AND unread.sender='customer' AND unread.channel='agent' AND unread.id>c.last_agent_read_id)";
        } elseif ( 'urgent' === $queue ) {
            $clauses[] = "c.priority IN ('high','urgent') AND c.status<>'resolved'";
        }
        $scope = sanitize_key( (string) $request->get_param( 'scope' ) );
        if ( 'human' === $scope ) $clauses[] = 'c.last_agent_message_at IS NOT NULL';
        if ( 'assistant' === $scope ) $clauses[] = 'c.last_agent_message_at IS NULL';
        $search = DSA2_Compat::substr( trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) ), 0, 100 );
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $clauses[] = '(c.customer_name LIKE %s OR c.customer_email LIKE %s OR c.customer_phone LIKE %s OR CAST(c.id AS CHAR)=%s)';
            array_push( $params, $like, $like, $like, ltrim( $search, '#' ) );
        }
        $limit = max( 1, min( 60, absint( $request->get_param( 'per_page' ) ) ?: 30 ) );
        $page = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
        $where = implode( ' AND ', $clauses );
        $count_sql = "SELECT COUNT(*) FROM $conv c WHERE $where";
        $total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = "SELECT c.*,
            (SELECT sender FROM $messages lm WHERE lm.conversation_id=c.id ORDER BY lm.id DESC LIMIT 1) AS last_sender,
            (SELECT body FROM $messages lm WHERE lm.conversation_id=c.id ORDER BY lm.id DESC LIMIT 1) AS last_body,
            (SELECT id FROM $messages lm WHERE lm.conversation_id=c.id ORDER BY lm.id DESC LIMIT 1) AS last_message_id,
            (SELECT COUNT(*) FROM $messages um WHERE um.conversation_id=c.id AND um.channel='agent' AND um.sender='customer' AND um.id>c.last_agent_read_id) AS unread_agent
            FROM $conv c WHERE $where
            ORDER BY CASE c.status WHEN 'waiting' THEN 0 WHEN 'open' THEN 1 ELSE 2 END,
                CASE c.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
                c.last_message_at DESC, c.id DESC LIMIT %d OFFSET %d";
        $query_params = array_merge( $params, array( $limit, ( $page - 1 ) * $limit ) );
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) );
        return self::response( array(
            'conversations' => array_map( array( __CLASS__, 'conversation_payload' ), $rows ),
            'pagination' => array( 'page' => $page, 'per_page' => $limit, 'total' => $total, 'has_more' => $page * $limit < $total ),
            'server_time' => gmdate( 'c' ),
        ) );
    }

    private static function conversation_payload( $row ) {
        $assigned = ! empty( $row->assigned_to ) ? get_userdata( (int) $row->assigned_to ) : false;
        $body = html_entity_decode( wp_strip_all_tags( (string) ( $row->last_body ?? '' ) ), ENT_QUOTES, 'UTF-8' );
        return array(
            'id' => (int) $row->id,
            'customer_id' => (int) $row->user_id,
            'customer_name' => sanitize_text_field( $row->customer_name ?: 'Visiteur #' . (int) $row->id ),
            'customer_email' => sanitize_email( (string) $row->customer_email ),
            'customer_phone' => sanitize_text_field( (string) $row->customer_phone ),
            'status' => sanitize_key( (string) $row->status ),
            'priority' => sanitize_key( (string) $row->priority ),
            'language' => sanitize_key( (string) $row->language ),
            'assigned_to' => (int) $row->assigned_to,
            'assigned_name' => $assigned ? sanitize_text_field( $assigned->display_name ) : '',
            'last_message' => DSA2_Compat::substr( $body, 0, 200 ),
            'last_sender' => sanitize_key( (string) ( $row->last_sender ?? '' ) ),
            'last_message_id' => (int) ( $row->last_message_id ?? 0 ),
            'last_message_at' => (string) $row->last_message_at,
            'created_at' => (string) $row->created_at,
            'unread' => (int) ( $row->unread_agent ?? 0 ),
            'channel' => ! empty( $row->last_agent_message_at ) ? 'human' : 'assistant',
            'customer_typing' => (bool) get_transient( 'dap_customer_typing_' . (int) $row->id ),
        );
    }

    private static function accessible_conversation( $request ) {
        $id = absint( $request->get_param( 'id' ) );
        if ( ! $id ) return new WP_Error( 'invalid_conversation', 'Conversation invalide.', array( 'status' => 400 ) );
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dap_conversations WHERE id=%d", $id ) );
        if ( ! $row ) return new WP_Error( 'not_found', 'Conversation introuvable.', array( 'status' => 404 ) );
        $user = $request->get_param( '_dap_admin_user' );
        if ( ! self::manager_user( $user ) && (int) $row->assigned_to && (int) $row->assigned_to !== (int) $user->ID ) {
            return new WP_Error( 'forbidden', 'Cette conversation appartient à un autre agent.', array( 'status' => 403 ) );
        }
        return $row;
    }

    public static function conversation( $request ) {
        $row = self::accessible_conversation( $request );
        if ( is_wp_error( $row ) ) return $row;
        return self::response( array( 'conversation' => self::conversation_payload( $row ), 'customer' => self::customer_context( $row ) ) );
    }

    private static function customer_context( $conversation ) {
        $uid = (int) $conversation->user_id;
        $user = $uid ? get_userdata( $uid ) : false;
        $orders = array();
        $wallet = '';
        $spent = '';
        if ( $user && function_exists( 'wc_get_orders' ) ) {
            $items = wc_get_orders( array( 'customer_id' => $uid, 'limit' => 8, 'orderby' => 'date', 'order' => 'DESC' ) );
            foreach ( (array) $items as $order ) {
                $products = array();
                foreach ( array_slice( $order->get_items(), 0, 6, true ) as $item ) {
                    $products[] = array( 'name' => sanitize_text_field( $item->get_name() ), 'quantity' => (int) $item->get_quantity() );
                }
                $orders[] = array(
                    'id' => (int) $order->get_id(),
                    'number' => sanitize_text_field( $order->get_order_number() ),
                    'status' => sanitize_key( $order->get_status() ),
                    'status_label' => function_exists( 'wc_get_order_status_name' ) ? wp_strip_all_tags( wc_get_order_status_name( $order->get_status() ) ) : sanitize_key( $order->get_status() ),
                    'total' => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
                    'currency' => sanitize_key( strtolower( $order->get_currency() ) ),
                    'created_at' => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : '',
                    'payment_method' => sanitize_text_field( $order->get_payment_method_title() ),
                    'admin_url' => esc_url_raw( method_exists( $order, 'get_edit_order_url' )
                        ? $order->get_edit_order_url()
                        : admin_url( 'post.php?post=' . (int) $order->get_id() . '&action=edit' ) ),
                    'items' => $products,
                );
            }
            if ( function_exists( 'wc_get_customer_total_spent' ) && function_exists( 'wc_price' ) ) {
                $spent = html_entity_decode( wp_strip_all_tags( wc_price( (float) wc_get_customer_total_spent( $uid ) ) ), ENT_QUOTES, 'UTF-8' );
            }
        }
        if ( $user && function_exists( 'woo_wallet' ) ) {
            try {
                $wallet = html_entity_decode( wp_strip_all_tags( woo_wallet()->wallet->get_wallet_balance( $uid ) ), ENT_QUOTES, 'UTF-8' );
            } catch ( Throwable $exception ) {
                $wallet = '';
            }
        }
        $points = 0;
        if ( $user ) {
            foreach ( array( 'dap_reward_points', '_delicat_reward_points', 'delicat_reward_points' ) as $key ) {
                $value = get_user_meta( $uid, $key, true );
                if ( '' !== $value && is_numeric( $value ) ) {
                    $points = (int) $value;
                    break;
                }
            }
        }
        return array(
            'linked' => (bool) $user,
            'id' => $user ? $uid : 0,
            'name' => sanitize_text_field( $user ? $user->display_name : $conversation->customer_name ),
            'email' => sanitize_email( $user ? $user->user_email : $conversation->customer_email ),
            'phone' => sanitize_text_field( (string) $conversation->customer_phone ),
            'avatar' => $user ? esc_url_raw( get_avatar_url( $uid, array( 'size' => 128 ) ) ) : '',
            'wallet_balance' => $wallet,
            'order_count' => $user && function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $uid ) : count( $orders ),
            'total_spent' => $spent,
            'reward_points' => $points,
            'orders' => $orders,
            'latest_order' => $orders ? $orders[0] : null,
            'privacy' => array( 'player_ids_included' => false, 'redeem_pins_included' => false, 'payment_secrets_included' => false ),
        );
    }

    public static function messages( $request ) {
        $conversation = self::accessible_conversation( $request );
        if ( is_wp_error( $conversation ) ) return $conversation;
        $after = absint( $request->get_param( 'after_id' ) );
        $before = absint( $request->get_param( 'before_id' ) );
        $limit = max( 1, min( 150, absint( $request->get_param( 'limit' ) ) ?: 60 ) );
        $include_bot = rest_sanitize_boolean( $request->get_param( 'include_automation' ) );
        $channel = $include_bot ? '' : " AND channel='agent'";
        global $wpdb;
        $table = $wpdb->prefix . 'dap_messages';
        if ( $after ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE conversation_id=%d $channel AND id>%d ORDER BY id ASC LIMIT %d", (int) $conversation->id, $after, $limit ) );
        } elseif ( $before ) {
            $rows = array_reverse( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE conversation_id=%d $channel AND id<%d ORDER BY id DESC LIMIT %d", (int) $conversation->id, $before, $limit ) ) );
        } else {
            $rows = array_reverse( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE conversation_id=%d $channel ORDER BY id DESC LIMIT %d", (int) $conversation->id, $limit ) ) );
        }
        return self::response( array(
            'messages' => array_map( array( 'DSA2_Mobile_API', 'format' ), (array) $rows ),
            'customer_typing' => (bool) get_transient( 'dap_customer_typing_' . (int) $conversation->id ),
            'customer_read_id' => (int) $conversation->last_customer_read_id,
            'agent_read_id' => (int) $conversation->last_agent_read_id,
            'conversation_status' => sanitize_key( (string) $conversation->status ),
            'has_more' => count( $rows ) >= $limit,
        ) );
    }

    public static function notes( $request ) {
        $conversation = self::accessible_conversation( $request );
        if ( is_wp_error( $conversation ) ) return $conversation;
        $notes = get_option( 'dap_internal_notes_' . (int) $conversation->id, array() );
        if ( ! is_array( $notes ) ) $notes = array();
        $safe = array();
        foreach ( array_slice( $notes, -30 ) as $note ) {
            if ( ! is_array( $note ) ) continue;
            $safe[] = array(
                'author' => sanitize_text_field( $note['author'] ?? '' ),
                'body' => sanitize_textarea_field( $note['body'] ?? '' ),
                'time' => sanitize_text_field( $note['time'] ?? '' ),
            );
        }
        return self::response( array( 'notes'=>$safe, 'count'=>count( $safe ) ) );
    }

    public static function add_note( $request ) {
        $conversation = self::accessible_conversation( $request );
        if ( is_wp_error( $conversation ) ) return $conversation;
        $user = $request->get_param( '_dap_admin_user' );
        $body = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
        if ( '' === $body || DSA2_Compat::length( $body ) > 1200 ) return new WP_Error( 'invalid_note', 'Note interne invalide.', array( 'status'=>400 ) );
        if ( self::limited( 'note', $user->ID, 30, MINUTE_IN_SECONDS ) ) return new WP_Error( 'rate_limited', 'Trop de notes internes.', array( 'status'=>429 ) );
        $option = 'dap_internal_notes_' . (int) $conversation->id;
        $notes = get_option( $option, array() );
        if ( ! is_array( $notes ) ) $notes = array();
        $note = array( 'author'=>sanitize_text_field( $user->display_name ), 'body'=>$body, 'time'=>current_time( 'd/m/Y H:i' ) );
        $notes[] = $note;
        $notes = array_slice( $notes, -30 );
        if ( false === get_option( $option, false ) ) add_option( $option, $notes, '', false );
        else update_option( $option, $notes, false );
        return self::response( array( 'note'=>$note, 'count'=>count( $notes ) ), 201 );
    }

    private static function claim( $conversation, $user ) {
        global $wpdb;
        $table = $wpdb->prefix . 'dap_conversations';
        if ( self::manager_user( $user ) ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE $table SET status='open', assigned_to=IF(assigned_to=0,%d,assigned_to) WHERE id=%d",
                (int) $user->ID, (int) $conversation->id
            ) );
            return true;
        }
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET status='open', assigned_to=%d WHERE id=%d AND (assigned_to=0 OR assigned_to=%d)",
            (int) $user->ID, (int) $conversation->id, (int) $user->ID
        ) );
        return false !== $updated && ( 0 !== $updated || (int) $conversation->assigned_to === (int) $user->ID );
    }

    public static function reply( $request ) {
        $conversation = self::accessible_conversation( $request );
        if ( is_wp_error( $conversation ) ) return $conversation;
        $user = $request->get_param( '_dap_admin_user' );
        $body = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
        if ( '' === $body || DSA2_Compat::length( $body ) > 3000 ) {
            return new WP_Error( 'invalid_message', 'Le message doit contenir entre 1 et 3 000 caractères.', array( 'status' => 400 ) );
        }
        if ( self::limited( 'reply', $user->ID, 80, MINUTE_IN_SECONDS ) ) {
            return new WP_Error( 'rate_limited', 'Trop de messages envoyés.', array( 'status' => 429 ) );
        }
        if ( ! self::claim( $conversation, $user ) ) {
            return new WP_Error( 'already_assigned', 'Un autre agent a pris cette conversation.', array( 'status' => 409 ) );
        }
        $id = DSA2_Data::add_message( (int) $conversation->id, 'agent', $body, 'text', array(), 'agent' );
        if ( ! $id ) return new WP_Error( 'send_failed', 'Le message n’a pas pu être enregistré.', array( 'status' => 500 ) );
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dap_messages WHERE id=%d", $id ) );
        delete_transient( 'dap_agent_typing_' . (int) $conversation->id );
        return self::response( array( 'message' => DSA2_Mobile_API::format( $row ), 'status' => 'open', 'assigned_to' => (int) $user->ID ), 201 );
    }

    public static function attachment( $request ) {
        $conversation = self::accessible_conversation( $request );
        if ( is_wp_error( $conversation ) ) return $conversation;
        $files = $request->get_file_params();
        $file = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
        if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || (int) $file['size'] > 5 * MB_IN_BYTES ) {
            return new WP_Error( 'invalid_attachment', 'Image invalide ou supérieure à 5 Mo.', array( 'status' => 400 ) );
        }
        $mimes = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' );
        $checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
        if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
            return new WP_Error( 'unsupported_attachment', 'Seules les images JPEG, PNG, GIF et WebP sont autorisées.', array( 'status' => 415 ) );
        }
        $user = $request->get_param( '_dap_admin_user' );
        if ( ! self::claim( $conversation, $user ) ) {
            return new WP_Error( 'already_assigned', 'Un autre agent a pris cette conversation.', array( 'status' => 409 ) );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $uploaded = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => $mimes ) );
        if ( isset( $uploaded['error'] ) ) return new WP_Error( 'upload_failed', sanitize_text_field( $uploaded['error'] ), array( 'status' => 400 ) );
        $attachment_id = wp_insert_attachment( array(
            'post_mime_type' => sanitize_mime_type( $uploaded['type'] ),
            'post_title' => sanitize_file_name( basename( $uploaded['file'] ) ),
            'post_status' => 'inherit',
            'post_author' => (int) $user->ID,
        ), $uploaded['file'] );
        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            wp_delete_file( $uploaded['file'] );
            return new WP_Error( 'upload_failed', 'Impossible d’enregistrer l’image.', array( 'status' => 500 ) );
        }
        $metadata = wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] );
        if ( ! is_wp_error( $metadata ) ) wp_update_attachment_metadata( $attachment_id, $metadata );
        $meta = array( 'url' => esc_url_raw( $uploaded['url'] ), 'mime' => sanitize_mime_type( $uploaded['type'] ), 'name' => sanitize_file_name( $file['name'] ), 'attachment_id' => (int) $attachment_id );
        $id = DSA2_Data::add_message( (int) $conversation->id, 'agent', 'Image', 'image', $meta, 'agent' );
        if ( ! $id ) {
            wp_delete_attachment( $attachment_id, true );
            return new WP_Error( 'send_failed', 'Impossible d’enregistrer le message.', array( 'status' => 500 ) );
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dap_messages WHERE id=%d", $id ) );
        return self::response( array( 'message' => DSA2_Mobile_API::format( $row ) ), 201 );
    }

    public static function mark_read( $request ) {
        $conversation = self::accessible_conversation( $request );
        if ( is_wp_error( $conversation ) ) return $conversation;
        global $wpdb;
        $max = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(id) FROM {$wpdb->prefix}dap_messages WHERE conversation_id=%d AND sender='customer' AND channel='agent'",
            (int) $conversation->id
        ) );
        if ( $max ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}dap_conversations SET last_agent_read_id=GREATEST(last_agent_read_id,%d) WHERE id=%d",
                $max, (int) $conversation->id
            ) );
        }
        return self::response( array( 'updated' => true, 'last_read_id' => $max, 'unread' => 0 ) );
    }

    public static function typing( $request ) {
        $conversation = self::accessible_conversation( $request );
        if ( is_wp_error( $conversation ) ) return $conversation;
        $typing = rest_sanitize_boolean( $request->get_param( 'typing' ) );
        $key = 'dap_agent_typing_' . (int) $conversation->id;
        if ( $typing ) set_transient( $key, 1, 15 );
        else delete_transient( $key );
        return self::response( array( 'updated' => true ) );
    }

    public static function update_conversation( $request ) {
        $conversation = self::accessible_conversation( $request );
        if ( is_wp_error( $conversation ) ) return $conversation;
        $user = $request->get_param( '_dap_admin_user' );
        $update = array();
        $status = sanitize_key( (string) $request->get_param( 'status' ) );
        $priority = sanitize_key( (string) $request->get_param( 'priority' ) );
        if ( '' !== $status ) {
            if ( ! in_array( $status, array( 'open', 'waiting', 'resolved' ), true ) ) return new WP_Error( 'invalid_status', 'Statut non autorisé.', array( 'status' => 400 ) );
            $update['status'] = $status;
        }
        if ( '' !== $priority ) {
            if ( ! in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ) return new WP_Error( 'invalid_priority', 'Priorité non autorisée.', array( 'status' => 400 ) );
            $update['priority'] = $priority;
        }
        if ( null !== $request->get_param( 'assigned_to' ) ) {
            $assigned = absint( $request->get_param( 'assigned_to' ) );
            if ( self::manager_user( $user ) ) {
                $target = $assigned ? get_userdata( $assigned ) : false;
                if ( $assigned && ! self::support_user( $target ) ) {
                    return new WP_Error( 'invalid_agent', 'Agent support invalide.', array( 'status' => 400 ) );
                }
                $update['assigned_to'] = $assigned;
            } elseif ( $assigned === (int) $user->ID && ( ! (int) $conversation->assigned_to || (int) $conversation->assigned_to === (int) $user->ID ) ) {
                $update['assigned_to'] = $assigned;
            } else {
                return new WP_Error( 'forbidden', 'Seul un responsable peut modifier cette assignation.', array( 'status' => 403 ) );
            }
        }
        if ( ! $update ) return new WP_Error( 'no_changes', 'Aucune modification fournie.', array( 'status' => 400 ) );
        global $wpdb;
        $ok = $wpdb->update( $wpdb->prefix . 'dap_conversations', $update, array( 'id' => (int) $conversation->id ) );
        if ( false === $ok ) return new WP_Error( 'update_failed', 'Impossible de mettre à jour la conversation.', array( 'status' => 500 ) );
        if ( isset( $update['status'] ) && 'resolved' === $update['status'] && 'resolved' !== (string) $conversation->status ) {
            DSA2_Data::request_feedback( $conversation );
        }
        $fresh = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dap_conversations WHERE id=%d", (int) $conversation->id ) );
        return self::response( array( 'conversation' => self::conversation_payload( $fresh ) ) );
    }

    public static function agents( $request ) {
        $user = $request->get_param( '_dap_admin_user' );
        if ( ! self::manager_user( $user ) ) return self::response( array( 'agents' => array( self::user_payload( $user ) ) ) );
        $users = get_users( array( 'capability' => DSA2_Install::CAP_SUPPORT, 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 100 ) );
        $items = array();
        foreach ( (array) $users as $agent ) {
            if ( self::support_user( $agent ) ) $items[] = self::user_payload( $agent );
        }
        return self::response( array( 'agents' => $items ) );
    }

    public static function tickets( $request ) {
        if ( ! post_type_exists( 'dap_ticket' ) ) return self::response( array( 'tickets' => array() ) );
        $status = sanitize_key( (string) $request->get_param( 'status' ) );
        $args = array( 'post_type' => 'dap_ticket', 'post_status' => array( 'publish', 'draft', 'pending' ), 'posts_per_page' => 60, 'orderby' => 'modified', 'order' => 'DESC' );
        if ( in_array( $status, array( 'open', 'closed' ), true ) ) {
            $args['meta_query'] = array( array( 'key' => 'dap_ticket_status', 'value' => $status ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        }
        $user = $request->get_param( '_dap_admin_user' );
        global $wpdb;
        $items = array();
        foreach ( (array) get_posts( $args ) as $post ) {
            $cid = (int) get_post_meta( $post->ID, 'dap_conversation_id', true );
            $assigned = (int) get_post_meta( $post->ID, 'dap_ticket_assigned_to', true );
            if ( ! self::manager_user( $user ) ) {
                $conversation_assigned = $cid ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT assigned_to FROM {$wpdb->prefix}dap_conversations WHERE id=%d", $cid ) ) : 0;
                if ( ( $assigned && $assigned !== (int) $user->ID ) || ( $conversation_assigned && $conversation_assigned !== (int) $user->ID ) ) continue;
            }
            $items[] = self::ticket_payload( $post );
        }
        return self::response( array( 'tickets' => $items ) );
    }

    private static function ticket_payload( $post ) {
        $agent_id = (int) get_post_meta( $post->ID, 'dap_ticket_assigned_to', true );
        $agent = $agent_id ? get_userdata( $agent_id ) : false;
        return array(
            'id' => (int) $post->ID,
            'conversation_id' => (int) get_post_meta( $post->ID, 'dap_conversation_id', true ),
            'subject' => sanitize_text_field( $post->post_title ),
            'message' => wp_strip_all_tags( $post->post_content ),
            'status' => sanitize_key( get_post_meta( $post->ID, 'dap_ticket_status', true ) ?: 'open' ),
            'priority' => sanitize_key( get_post_meta( $post->ID, 'dap_ticket_priority', true ) ?: 'normal' ),
            'assigned_to' => $agent_id,
            'assigned_name' => $agent ? sanitize_text_field( $agent->display_name ) : '',
            'rating' => (int) get_post_meta( $post->ID, 'dap_ticket_rating', true ),
            'created_at' => get_post_time( 'c', true, $post ),
            'updated_at' => get_post_modified_time( 'c', true, $post ),
        );
    }

    public static function update_ticket( $request ) {
        $id = absint( $request->get_param( 'id' ) );
        $post = get_post( $id );
        if ( ! $post || 'dap_ticket' !== $post->post_type ) return new WP_Error( 'not_found', 'Ticket introuvable.', array( 'status' => 404 ) );
        $cid = (int) get_post_meta( $id, 'dap_conversation_id', true );
        $request->set_param( 'id', $cid );
        $conversation = self::accessible_conversation( $request );
        $request->set_param( 'id', $id );
        if ( is_wp_error( $conversation ) ) return $conversation;
        $status = sanitize_key( (string) $request->get_param( 'status' ) );
        $priority = sanitize_key( (string) $request->get_param( 'priority' ) );
        if ( '' !== $status ) {
            if ( ! in_array( $status, array( 'open', 'closed' ), true ) ) return new WP_Error( 'invalid_status', 'Statut de ticket invalide.', array( 'status' => 400 ) );
            update_post_meta( $id, 'dap_ticket_status', $status );
        }
        if ( '' !== $priority ) {
            if ( ! in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ) return new WP_Error( 'invalid_priority', 'Priorité invalide.', array( 'status' => 400 ) );
            update_post_meta( $id, 'dap_ticket_priority', $priority );
        }
        $user = $request->get_param( '_dap_admin_user' );
        if ( null !== $request->get_param( 'assigned_to' ) && self::manager_user( $user ) ) {
            $assigned = absint( $request->get_param( 'assigned_to' ) );
            if ( $assigned && ! self::support_user( get_userdata( $assigned ) ) ) return new WP_Error( 'invalid_agent', 'Agent invalide.', array( 'status' => 400 ) );
            update_post_meta( $id, 'dap_ticket_assigned_to', $assigned );
        }
        wp_update_post( array( 'ID' => $id ) );
        return self::response( array( 'ticket' => self::ticket_payload( get_post( $id ) ) ) );
    }

    public static function register_device( $request ) {
        $token = trim( sanitize_text_field( (string) $request->get_param( 'fcm_token' ) ) );
        if ( strlen( $token ) < 20 || strlen( $token ) > 4096 ) {
            return new WP_Error( 'invalid_fcm_token', 'Jeton Firebase invalide.', array( 'status' => 400 ) );
        }
        $session = $request->get_param( '_dap_admin_session' );
        global $wpdb;
        $table = $wpdb->prefix . 'dap_admin_sessions';
        // A phone belongs to one support session; prevent a reused token from notifying a signed-out user.
        $wpdb->query( $wpdb->prepare( "UPDATE $table SET fcm_token='' WHERE fcm_token=%s AND id<>%d", $token, (int) $session->id ) );
        $ok = $wpdb->update( $table, array( 'fcm_token' => $token ), array( 'id' => (int) $session->id ), array( '%s' ), array( '%d' ) );
        if ( false === $ok ) return new WP_Error( 'registration_failed', 'Impossible d’activer les notifications.', array( 'status' => 500 ) );
        return self::response( array( 'registered' => true, 'push_configured' => self::features()['push_configured'] ) );
    }

    public static function unregister_device( $request ) {
        $session = $request->get_param( '_dap_admin_session' );
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'dap_admin_sessions', array( 'fcm_token' => '' ), array( 'id' => (int) $session->id ), array( '%s' ), array( '%d' ) );
        return self::response( array( 'registered' => false ) );
    }

    public static function refresh( $request ) {
        $session = $request->get_param( '_dap_admin_session' );
        $settings = get_option( 'dap_settings', array() );
        $days = max( 1, min( 90, (int) ( $settings['admin_session_days'] ?? 30 ) ) );
        $token = self::random_token();
        $expires = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->prefix . 'dap_admin_sessions',
            array( 'token_hash' => self::hash_token( $token ), 'expires_at' => $expires, 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ) ),
            array( 'id' => (int) $session->id, 'token_hash' => (string) $session->token_hash ),
            array( '%s', '%s', '%s' ),
            array( '%d', '%s' )
        );
        if ( 1 !== $updated ) return new WP_Error( 'session_changed', 'La session a été modifiée. Reconnectez-vous.', array( 'status' => 409 ) );
        return self::response( array( 'token' => $token, 'expires_at' => mysql2date( 'c', $expires, false ) ) );
    }

    public static function logout( $request ) {
        $session = $request->get_param( '_dap_admin_session' );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'dap_admin_sessions', array( 'id' => (int) $session->id ), array( '%d' ) );
        return self::response( array( 'logged_out' => true ) );
    }

    public static function revoke_user( $user_id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'dap_admin_sessions', array( 'user_id' => absint( $user_id ) ), array( '%d' ) );
    }

    public static function revoke_if_permission_removed( $user_id ) {
        $user = get_userdata( absint( $user_id ) );
        if ( ! self::support_user( $user ) ) self::revoke_user( $user_id );
    }

    public static function revoke_after_password_reset( $user, $new_password ) {
        if ( $user instanceof WP_User ) self::revoke_user( (int) $user->ID );
    }

    public static function revoke_from_dashboard() {
        if ( ! current_user_can( DSA2_Install::CAP_SETTINGS ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'delicat-assistant' ) );
        }
        check_admin_referer( 'dap_revoke_admin_sessions' );
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}dap_admin_sessions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        wp_safe_redirect( admin_url( 'admin.php?page=delicat-assistant-settings&devices_revoked=1' ) );
        exit;
    }

    public static function message_created( $conversation_id, $message_id, $sender, $body, $type, $channel = 'bot' ) {
        if ( 'customer' !== $sender || 'agent' !== $channel ) return;
        $settings = get_option( 'dap_settings', array() );
        if ( isset( $settings['admin_push_enabled'] ) && ! $settings['admin_push_enabled'] ) return;
        if ( ! self::push_configured() ) return;
        $args = array( absint( $conversation_id ), absint( $message_id ) );
        $queued = function_exists( 'as_enqueue_async_action' )
            ? as_enqueue_async_action( self::PUSH_ACTION, $args, 'delicat-assistant', true )
            : 0;
        if ( ! $queued && ! wp_next_scheduled( self::PUSH_ACTION, $args ) ) {
            wp_schedule_single_event( time(), self::PUSH_ACTION, $args );
            if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) spawn_cron( time() );
        }
    }

    /** 6.6.1: push is "configured" when the Delicat App API can send, or (pre-API) when the webhook is set. */
    public static function push_configured() {
        if ( class_exists( 'Delicat_App_Push' ) && method_exists( 'Delicat_App_Push', 'is_configured' ) ) {
            return (bool) Delicat_App_Push::is_configured();
        }
        $settings = get_option( 'dap_settings', array() );
        return ! empty( $settings['firebase_v1_webhook_url'] ) && ! empty( $settings['firebase_v1_webhook_secret'] );
    }

    public static function deliver_push( $conversation_id, $message_id ) {
        global $wpdb;
        $settings = get_option( 'dap_settings', array() );
        $webhook = esc_url_raw( (string) ( $settings['firebase_v1_webhook_url'] ?? '' ) );
        $secret = (string) ( $settings['firebase_v1_webhook_secret'] ?? '' );
        // 6.6.1: the Delicat App API sends FCM for the whole site; the webhook is the pre-API fallback only.
        $via_api = class_exists( 'Delicat_App_Push' ) && method_exists( 'Delicat_App_Push', 'send_to_token' );
        if ( ! $via_api && ( ! $webhook || ! $secret || 'https' !== wp_parse_url( $webhook, PHP_URL_SCHEME ) ) ) return;
        $conversation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dap_conversations WHERE id=%d", absint( $conversation_id ) ) );
        $message = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dap_messages WHERE id=%d AND conversation_id=%d AND sender='customer' AND channel='agent'",
            absint( $message_id ), absint( $conversation_id )
        ) );
        if ( ! $conversation || ! $message ) return;
        $sessions = (array) $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}dap_admin_sessions WHERE fcm_token<>'' AND expires_at>UTC_TIMESTAMP() ORDER BY last_seen_at DESC LIMIT 100"
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ( $sessions as $session ) {
            $agent = get_userdata( (int) $session->user_id );
            if ( ! self::support_user( $agent ) ) {
                self::revoke_user( (int) $session->user_id );
                continue;
            }
            if ( (int) $conversation->assigned_to && (int) $conversation->assigned_to !== (int) $agent->ID && ! self::manager_user( $agent ) ) continue;
            $name = sanitize_text_field( $conversation->customer_name ?: 'Client #' . (int) $conversation->id );
            $preview = ! empty( $settings['admin_push_preview'] )
                ? DSA2_Compat::substr( html_entity_decode( wp_strip_all_tags( (string) $message->body ), ENT_QUOTES, 'UTF-8' ), 0, 140 )
                : 'Nouveau message client · #' . (int) $conversation->id;
            $payload = array(
                'token' => (string) $session->fcm_token,
                'notification' => array( 'title' => 'Delicat Support · ' . $name, 'body' => $preview ),
                'data' => array(
                    'audience' => 'admin',
                    'screen' => 'conversation',
                    'conversation_id' => (string) (int) $conversation->id,
                    'message_id' => (string) (int) $message->id,
                    'deep_link' => 'delicatadmin://conversation/' . (int) $conversation->id,
                    'priority' => sanitize_key( (string) $conversation->priority ),
                ),
            );
            if ( $via_api ) {
                $data = array_merge( array( 'type' => 'chat' ), (array) $payload['data'] );
                $detail = method_exists( 'Delicat_App_Push', 'send_to_token_detailed' )
                    ? Delicat_App_Push::send_to_token_detailed( (string) $session->fcm_token, (string) $payload['notification']['title'], (string) $payload['notification']['body'], $data )
                    : array( 'ok' => Delicat_App_Push::send_to_token( (string) $session->fcm_token, (string) $payload['notification']['title'], (string) $payload['notification']['body'], $data ), 'error_code' => '' );
                $ok = ! empty( $detail['ok'] );
                $result = null;
                if ( ! $ok && in_array( (string) ( $detail['error_code'] ?? '' ), array( 'UNREGISTERED', 'NOT_FOUND' ), true ) ) {
                    $wpdb->update( $wpdb->prefix . 'dap_admin_sessions', array( 'fcm_token' => '' ), array( 'id' => (int) $session->id ), array( '%s' ), array( '%d' ) );
                }
            } else {
            $json = wp_json_encode( $payload );
            $result = wp_safe_remote_post( $webhook, array(
                'timeout' => 12,
                'redirection' => 0,
                'headers' => array( 'Content-Type' => 'application/json', 'X-Delicat-Signature' => hash_hmac( 'sha256', $json, $secret ) ),
                'body' => $json,
            ) );
            $ok = ! is_wp_error( $result ) && wp_remote_retrieve_response_code( $result ) >= 200 && wp_remote_retrieve_response_code( $result ) < 300;
            }
            if ( ! $via_api && ! $ok && ! is_wp_error( $result ) && in_array( wp_remote_retrieve_response_code( $result ), array( 404, 410 ), true ) ) {
                $wpdb->update( $wpdb->prefix . 'dap_admin_sessions', array( 'fcm_token' => '' ), array( 'id' => (int) $session->id ), array( '%s' ), array( '%d' ) );
            }
            if ( class_exists( 'DSA2_Production', false ) ) {
                DSA2_Production::audit( $ok ? 'admin_push_sent' : 'admin_push_failed', array( 'conversation_id' => (int) $conversation->id, 'message_id' => (int) $message->id ) );
            }
        }
    }
}
