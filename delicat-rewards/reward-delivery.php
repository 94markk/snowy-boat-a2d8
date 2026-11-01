<?php
/** Private, read-only delivery access. WooCommerce/key provider remains authoritative. */
defined( 'ABSPATH' ) || exit;

function delicat_private_response( $data ) {
    $response = rest_ensure_response( $data );
    $response->header( 'Cache-Control', 'no-store, private, max-age=0' );
    $response->header( 'Pragma', 'no-cache' );
    $response->header( 'Vary', 'Cookie' );
    return $response;
}

/** Only documented code fields are traversed; never flatten IDs, dates or customer data. */
function delicat_normalize_reward_codes( $value, $depth = 0 ) {
    if ( $depth > 5 ) return array();
    if ( is_string( $value ) ) {
        $value = trim( $value );
        if ( '' === $value ) return array();
        if ( '[' === substr( $value, 0, 1 ) || '{' === substr( $value, 0, 1 ) ) {
            $json = json_decode( $value, true );
            return is_array( $json ) ? delicat_normalize_reward_codes( $json, $depth + 1 ) : array();
        }
        $out = array();
        foreach ( preg_split( '/\r\n|\r|\n/', $value ) as $code ) {
            $code = trim( $code );
            // Preserve the exact PIN, including leading zeros. Reject HTML/control characters.
            if ( strlen( $code ) >= 4 && strlen( $code ) <= 256 && ! preg_match( '/[<>\x00-\x1F\x7F]/', $code ) ) $out[] = $code;
        }
        return array_slice( array_values( array_unique( $out ) ), 0, DELICAT_MAX_PIN_ITEMS );
    }
    if ( ! is_array( $value ) || ! $value ) return array();
    $out = array();
    if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
        foreach ( array_slice( $value, 0, DELICAT_MAX_PIN_ITEMS ) as $row ) $out = array_merge( $out, delicat_normalize_reward_codes( $row, $depth + 1 ) );
    } else {
        foreach ( array( 'code', 'pin', 'key', 'license_key', 'license', 'codes', 'pins', 'keys' ) as $field ) {
            if ( isset( $value[ $field ] ) ) $out = array_merge( $out, delicat_normalize_reward_codes( $value[ $field ], $depth + 1 ) );
        }
    }
    return array_slice( array_values( array_unique( $out ) ), 0, DELICAT_MAX_PIN_ITEMS );
}

function delicat_reward_item_codes( $order, $item ) {
    $codes = array();
    if ( function_exists( 'wckm_get_order_keys' ) ) {
        // WC Key Manager stores codes in its own model, not Woo item metadata.
        $keys = wckm_get_order_keys( $order->get_id(), array( 'order_item_id' => $item->get_id(), 'limit' => DELICAT_MAX_PIN_ITEMS ) );
        foreach ( is_array( $keys ) ? $keys : array() as $key ) {
            if ( ! is_object( $key ) || (int) $key->order_id !== (int) $order->get_id() || (int) $key->order_item_id !== (int) $item->get_id() || (int) $key->customer_id !== (int) $order->get_customer_id() ) continue;
            if ( ! in_array( (string) $key->status, array( 'sold', 'activated' ), true ) ) continue;
            $codes = array_merge( $codes, delicat_normalize_reward_codes( (string) $key->code ) );
        }
        // This provider is authoritative for keyed items; do not revive revoked keys
        // from a stale metadata copy if the provider returned no assigned keys.
        if ( 'yes' === $item->get_meta( '_wckm_order_item', true ) ) return array_slice( array_values( array_unique( $codes ) ), 0, delicat_reward_item_expected( $item ) );
    }
    // Native Delicat Secure Keys bridge. The vault stays encrypted at rest; only
    // an already-assigned code for this exact owner/order/item is read here.
    $delicat_pid = method_exists( $item, 'get_variation_id' ) && $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
    if ( function_exists( 'delicat_is_keyed' ) && delicat_is_keyed( $delicat_pid ) ) {
        if ( function_exists( 'delicat_keys_get_order_codes_for_user' ) ) {
            $payload = delicat_keys_get_order_codes_for_user( $order, get_current_user_id() );
            foreach ( (array) ( $payload['items'] ?? array() ) as $row ) {
                if ( (int) ( $row['order_item_id'] ?? 0 ) !== (int) $item->get_id() ) continue;
                $codes = array_merge( $codes, delicat_normalize_reward_codes( $row['code'] ?? '' ) );
            }
        }
        // Secure Keys is authoritative for marked products. Do not fall through
        // to stale plaintext metadata after a void/refund/provider removal.
        return array_slice( array_values( array_unique( $codes ) ), 0, delicat_reward_item_expected( $item ) );
    }
    // These are the delivery metadata contracts already recognized by the rewards engine.
    foreach ( array( '_delicat_keys', '_delicat_codes', 'delicat_keys', '_license_keys', '_wc_keys' ) as $key ) {
        $codes = array_merge( $codes, delicat_normalize_reward_codes( $item->get_meta( $key, true ) ) );
    }
    /** A provider with encrypted/custom storage returns its already-assigned plaintext codes here.
     * This filter must be read-only: never allocate a key while viewing an existing reward.
     */
    $codes = apply_filters( 'delicat_reward_item_codes', array_values( array_unique( $codes ) ), $item, $order );
    return array_slice( delicat_normalize_reward_codes( $codes ), 0, delicat_reward_item_expected( $item ) );
}

function delicat_reward_item_expected( $item ) {
    $per_unit = 'yes' === $item->get_meta( '_wckm_order_item', true ) ? max( 1, (int) $item->get_meta( '_wckm_delivery_qty', true ) ) : 1;
    return min( DELICAT_MAX_PIN_ITEMS, max( 0, (int) $item->get_quantity() ) * $per_unit );
}

function delicat_reward_delivery( $order_id, $user_id ) {
    $user_id = (int) $user_id;
    // Even internal callers cannot reveal another customer's keys.
    if ( $user_id < 1 || $user_id !== (int) get_current_user_id() ) return new WP_Error( 'not_found', 'Récompense introuvable.', array( 'status' => 404 ) );
    $order = wc_get_order( (int) $order_id );
    if ( ! $order || (int) $order->get_customer_id() !== $user_id || ! $order->get_meta( '_delicat_reward' ) ) {
        return new WP_Error( 'not_found', 'Récompense introuvable.', array( 'status' => 404 ) );
    }
    $revoked = $order->has_status( array( 'refunded', 'cancelled', 'failed', 'trash' ) ) || (float) $order->get_total_refunded() > 0 || count( $order->get_refunds() ) > 0;
    $pins = array(); $expected = 0; $seen = array();
    foreach ( $order->get_items() as $item ) {
        $expected += delicat_reward_item_expected( $item );
        if ( $revoked || ! $order->has_status( 'completed' ) ) continue;
        foreach ( delicat_reward_item_codes( $order, $item ) as $code ) {
            if ( isset( $seen[ $code ] ) ) continue;
            $seen[ $code ] = true;
            $pins[] = array( 'code' => $code, 'label' => wp_strip_all_tags( $item->get_name() ) );
        }
    }
    $status = $revoked ? 'unavailable' : ( $expected > 0 && count( $pins ) >= $expected ? 'ready' : ( $pins ? 'partial' : 'pending' ) );
    $date = $order->get_date_created();
    return array(
        'orderId' => (int) $order->get_id(), 'tier' => (string) $order->get_meta( '_delicat_reward' ),
        'amount' => max( 0, (int) $order->get_meta( '_delicat_reward_amount' ) ),
        'date' => $date ? $date->date( DATE_ATOM ) : '', 'status' => $status,
        'expected' => $expected, 'pins' => $pins,
        'redeemUrl' => 'https://delicastoreha.com/free-fire-redeem/',
        'message' => $revoked ? 'Cette récompense n’est plus disponible. Contactez le support.' : ( 'ready' === $status ? 'Copiez votre PIN, puis ouvrez la page de validation.' : 'Récompense enregistrée. Votre PIN est en préparation. Actualisez ici dans quelques instants ; ne réclamez pas à nouveau ce bonus.' ),
    );
}

/**
 * Secure Keys stores reward PINs encrypted in its own table and keeps only the row ids on
 * the line (_delicat_key_ids), so the website reward window stayed "en préparation" while
 * the app showed the PIN. This reads them with Secure Keys' own decrypt function. It only
 * runs from delicat_reward_item_codes(), called by delicat_reward_delivery() after the
 * owner, completed-status and refund checks. Read-only: nothing is allocated here.
 */
add_filter( 'delicat_reward_item_codes', 'delicat_reward_secure_keys_codes', 10, 3 );
function delicat_reward_secure_keys_codes( $codes, $item = null, $order = null ) {
    $codes = is_array( $codes ) ? $codes : array();
    if ( ! function_exists( 'delicat_keys_decrypt' ) || ! function_exists( 'delicat_keys_table' ) || ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) return $codes;
    $ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) $item->get_meta( '_delicat_key_ids', true ) ) ) ) ), 0, DELICAT_MAX_PIN_ITEMS );
    if ( ! $ids ) return $codes;
    global $wpdb;
    $table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) delicat_keys_table() );
    if ( '' === $table || ! is_object( $wpdb ) ) return $codes;
    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $rows = $wpdb->get_col( $wpdb->prepare( "SELECT code_enc FROM `{$table}` WHERE id IN ({$placeholders})", $ids ) );
    foreach ( is_array( $rows ) ? $rows : array() as $encrypted ) {
        $plain = delicat_keys_decrypt( $encrypted );
        if ( is_string( $plain ) && '' !== $plain ) $codes[] = $plain;
    }
    return $codes;
}

/**
 * `delicat_app_order_codes` provider for reward orders. The app read only Secure Keys and
 * Digital Gateway codes, so with WC Key Manager a delivered reward showed no PIN. The same
 * gate as the website rewards page applies (owner, completed, never refunded). The bulk
 * 'pins' and 'summary' contexts never read codes (they may decrypt Secure Keys rows), and
 * a code another provider already listed is not repeated.
 */
add_filter( 'delicat_app_order_codes', 'delicat_reward_app_order_codes', 15, 3 );
function delicat_reward_app_order_codes( $codes, $order = null, $context = 'order' ) {
    $codes = is_array( $codes ) ? $codes : array();
    if ( in_array( $context, array( 'pins', 'summary' ), true ) || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! $order->get_meta( '_delicat_reward' ) ) return $codes;
    $user_id = get_current_user_id();
    if ( $user_id < 1 || (int) $order->get_customer_id() !== (int) $user_id || ! function_exists( 'wc_get_order' ) ) return $codes;
    $delivery = delicat_reward_delivery( $order->get_id(), $user_id );
    if ( is_wp_error( $delivery ) || empty( $delivery['pins'] ) ) return $codes;
    $known = array();
    foreach ( $codes as $row ) {
        if ( is_array( $row ) && isset( $row['code'] ) && is_scalar( $row['code'] ) ) $known[ trim( (string) $row['code'] ) ] = true;
    }
    foreach ( $delivery['pins'] as $pin ) {
        $code = trim( (string) ( $pin['code'] ?? '' ) );
        if ( '' === $code || isset( $known[ $code ] ) ) continue;
        $known[ $code ] = true;
        $codes[] = array(
            'label'      => (string) ( $pin['label'] ?? 'PIN Free Fire' ),
            'code'       => $code,
            'redeem_url' => (string) ( $delivery['redeemUrl'] ?? '' ),
            'status'     => 'delivered',
            'free_fire'  => true,
        );
    }
    return $codes;
}

/** HPOS and legacy CPT use the same filtered, paginated order query. */
function delicat_reward_query( $user_id, $page = 1, $request_hash = '' ) {
    $meta = array( array( 'key' => '_delicat_reward', 'value' => '', 'compare' => '!=' ) );
    if ( $request_hash ) $meta[] = array( 'key' => '_delicat_reward_request', 'value' => $request_hash );
    $args = array( 'customer_id' => (int) $user_id, 'limit' => 10, 'page' => max( 1, (int) $page ), 'orderby' => 'ID', 'order' => 'DESC', 'paginate' => true );
    if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) $args['meta_query'] = $meta;
    else $args['delicat_reward_meta'] = $meta;
    return wc_get_orders( $args );
}
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', function ( $query, $vars ) {
    if ( ! empty( $vars['delicat_reward_meta'] ) ) {
        if ( ! isset( $query['meta_query'] ) ) $query['meta_query'] = array();
        $query['meta_query'][] = $vars['delicat_reward_meta'];
    }
    return $query;
}, 10, 2 );

function delicat_rest_reward_pins( WP_REST_Request $req ) {
    if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) return new WP_Error( 'unavailable', 'WooCommerce est momentanément indisponible.', array( 'status' => 503 ) );
    $uid = get_current_user_id();
    if ( ! $uid ) return new WP_Error( 'auth', 'Connectez-vous.', array( 'status' => 401 ) );
    if ( $req->get_param( 'order_id' ) ) {
        $delivery = delicat_reward_delivery( absint( $req->get_param( 'order_id' ) ), $uid );
        return is_wp_error( $delivery ) ? $delivery : delicat_private_response( $delivery );
    }
    return delicat_private_response( delicat_reward_pins_page( $uid, max( 1, absint( $req->get_param( 'page' ) ) ) ) );
}

/** One page of a customer's reward orders without their codes (website route and app contract). */
function delicat_reward_pins_page( $uid, $page ) {
    $page = max( 1, (int) $page );
    $result = delicat_reward_query( $uid, $page );
    $items = array();
    foreach ( $result->orders as $order ) {
        $delivery = delicat_reward_delivery( $order->get_id(), $uid );
        if ( is_wp_error( $delivery ) ) continue;
        // History is an index, not a bulk PIN dump. Reveal one order on explicit request.
        unset( $delivery['pins'] );
        $items[] = $delivery;
    }
    return array( 'items' => $items, 'page' => $page, 'pages' => (int) $result->max_num_pages );
}
add_action( 'rest_api_init', function () {
    register_rest_route( 'delicat/v1', '/reward-pins', array(
        'methods' => 'GET', 'callback' => 'delicat_rest_reward_pins',
        'permission_callback' => function () { return is_user_logged_in() && (bool) get_userdata( get_current_user_id() ); },
        'args' => array( 'order_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ) ),
    ) );
} );
