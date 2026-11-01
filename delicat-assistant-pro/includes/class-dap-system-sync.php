<?php
/**
 * Lightweight synchronization hooks shared by storefront, WooCommerce and admin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class DSA2_System_Sync {
    public static function init() {
        static $initialized = false;
        if ( $initialized ) return;
        $initialized = true;
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_status_changed' ), 20, 4 );
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'payment_complete' ), 20, 1 );
        add_action( 'woocommerce_update_product', array( __CLASS__, 'invalidate_product_cache' ), 20, 1 );
        add_action( 'woocommerce_new_product', array( __CLASS__, 'invalidate_product_cache' ), 20, 1 );
        add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'invalidate_stock' ), 20, 1 );
        add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'invalidate_stock' ), 20, 1 );
        add_action( 'woo_wallet_transaction_recorded', array( __CLASS__, 'wallet_changed' ), 20, 2 );
        add_action( 'woo_wallet_credit', array( __CLASS__, 'wallet_changed' ), 20, 2 );
        add_action( 'woo_wallet_debit', array( __CLASS__, 'wallet_changed' ), 20, 2 );
        // Identity Pro and wp-login.php do not use a dsa2_ AJAX action. Keep the
        // guest-to-account merge active on those lightweight requests as well.
        add_action( 'wp_login', array( 'DSA2_Data', 'link_guest_on_login' ), 10, 2 );
        add_action( 'wp_logout', array( 'DSA2_Data', 'clear_session_cookies' ) );
        add_action( 'set_user_role', array( __CLASS__, 'role_changed' ), 20, 3 );
    }

    public static function invalidate_product_cache( $product_id = 0 ) {
        delete_transient( 'dap_product_index' );
        do_action( 'dsa2_store_context_changed', 'product', absint( $product_id ) );
    }

    public static function invalidate_stock( $product ) {
        $id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
        self::invalidate_product_cache( $id );
    }

    public static function role_changed( $user_id, $new_role, $old_roles ) {
        if ( class_exists( 'DSA2_Admin_Mobile_API', false ) ) {
            DSA2_Admin_Mobile_API::revoke_if_permission_removed( absint( $user_id ) );
            return;
        }
        $user = get_userdata( absint( $user_id ) );
        if ( $user && ( user_can( $user, 'manage_delicat_assistant' ) || user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'manage_options' ) ) ) return;
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'dap_admin_sessions', array( 'user_id' => absint( $user_id ) ), array( '%d' ) );
    }

    public static function payment_complete( $order_id ) {
        do_action( 'dsa2_store_context_changed', 'payment', absint( $order_id ) );
    }

    public static function wallet_changed( $transaction_id = 0, $user_id = 0 ) {
        do_action( 'dsa2_store_context_changed', 'wallet', absint( $user_id ?: $transaction_id ) );
    }

    /**
     * v6.2.0 — every status transition used to be posted into the customer's support
     * thread. Two cards per order ("Processing", then "Completed") meant a regular
     * customer opened "Équipe support" onto a wall of order receipts with the actual
     * conversation buried under it, and the unread badge pinned at 99+.
     *
     * The transition is no longer written to the thread by default. Order status is still
     * fully available on demand — "Aide commande" and the order lookup card answer it in
     * the assistant workspace, where it belongs, without becoming permanent history.
     * Stores that want the old behaviour can re-enable it in Paramètres.
     */
    private static function order_updates_in_chat() {
        $s = get_option( 'dap_settings', array() );
        return ! empty( $s['order_updates_in_chat'] );
    }

    public static function order_status_changed( $order_id, $from, $to, $order = null ) {
        if ( ! function_exists( 'wc_get_order' ) ) return;
        if ( ! self::order_updates_in_chat() ) {
            do_action( 'dsa2_store_context_changed', 'order', absint( $order_id ) );
            return;
        }
        $order = is_object( $order ) ? $order : wc_get_order( absint( $order_id ) );
        if ( ! $order || ! method_exists( $order, 'get_customer_id' ) ) return;
        $customer = (int) $order->get_customer_id();
        if ( ! $customer ) return;
        $status = sanitize_key( (string) $to );
        $dedupe = 'dap_order_sync_' . absint( $order_id ) . '_' . $status;
        if ( get_transient( $dedupe ) ) return;
        global $wpdb;
        $conversation = $wpdb->get_row( $wpdb->prepare(
            "SELECT id,language FROM {$wpdb->prefix}dap_conversations
             WHERE user_id=%d AND last_agent_message_at IS NOT NULL AND status IN ('waiting','open')
             ORDER BY last_message_at DESC LIMIT 1",
            $customer
        ) );
        if ( ! $conversation ) return;
        set_transient( $dedupe, 1, 15 * MINUTE_IN_SECONDS );
        $label = function_exists( 'wc_get_order_status_name' ) ? wp_strip_all_tags( wc_get_order_status_name( $status ) ) : $status;
        $number = sanitize_text_field( $order->get_order_number() );
        $body = 'en' === $conversation->language
            ? 'Order #' . $number . ': ' . $label
            : ( 'ht' === $conversation->language ? 'Kòmand #' . $number . ': ' . $label : 'Commande #' . $number . ' : ' . $label );
        $meta = array(
            'title' => $body,
            'order' => array(
                'id' => absint( $order_id ),
                'title' => 'Commande #' . $number,
                'status' => $label,
                'total' => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
                'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : '',
                'url' => esc_url_raw( $order->get_view_order_url() ),
            ),
        );
        DSA2_Data::add_message( (int) $conversation->id, 'system', $body, 'system', $meta, 'agent' );
        do_action( 'dsa2_store_context_changed', 'order', absint( $order_id ) );
    }
}
