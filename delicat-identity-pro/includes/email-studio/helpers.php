<?php
defined( 'ABSPATH' ) || exit;

function dipes_current_email_id( $email = null ) {
    if ( ! empty( $GLOBALS['dipes_preview_email_id'] ) ) {
        return sanitize_key( $GLOBALS['dipes_preview_email_id'] );
    }
    if ( is_object( $email ) && ! empty( $email->id ) ) {
        return sanitize_key( $email->id );
    }
    if ( ! empty( $GLOBALS['dipes_current_email_id'] ) ) {
        return sanitize_key( $GLOBALS['dipes_current_email_id'] );
    }
    return 'customer_processing_order';
}

function dipes_font_stack( $key ) {
    $map = array(
        'system'    => '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif',
        'arial'     => 'Arial,Helvetica,sans-serif',
        'georgia'   => 'Georgia,"Times New Roman",serif',
        'trebuchet' => '"Trebuchet MS",Arial,sans-serif',
        'verdana'   => 'Verdana,Geneva,sans-serif',
        'mono'      => 'ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace',
    );
    return isset( $map[ $key ] ) ? $map[ $key ] : $map['system'];
}

function dipes_brand_name() {
    $name = trim( (string) dipes_get( 'brand_name' ) );
    return $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
}

function dipes_logo_url() {
    $url = trim( (string) dipes_get( 'logo_url' ) );
    if ( $url ) { return $url; }
    $wc_logo = get_option( 'woocommerce_email_header_image' );
    if ( $wc_logo ) { return $wc_logo; }
    $logo_id = get_theme_mod( 'custom_logo' );
    return $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'full' ) : '';
}

function dipes_context( $email = null, $order = null, $extra = array() ) {
    if ( ! $order && is_object( $email ) && isset( $email->object ) && is_a( $email->object, 'WC_Order' ) ) {
        $order = $email->object;
    }
    $first = '';
    $last  = '';
    $full  = '';
    $number = '';
    $date = '';
    $total = '';
    $total_html = '';
    $payment = '';
    $status = '';
    $order_url = '';
    $admin_order_url = '';
    $payment_url = '';
    $billing_email = '';
    $billing_phone = '';
    $billing_company = '';

    if ( is_object( $order ) && is_a( $order, 'WC_Order' ) ) {
        $first = $order->get_billing_first_name();
        $last = $order->get_billing_last_name();
        $full = trim( $order->get_formatted_billing_full_name() );
        $number = $order->get_order_number();
        $date_obj = $order->get_date_created();
        $date = $date_obj && function_exists( 'wc_format_datetime' ) ? wc_format_datetime( $date_obj ) : '';
        $total_html = $order->get_formatted_order_total();
        $total = trim( preg_replace( "/\s+/u", " ", html_entity_decode( wp_strip_all_tags( $total_html ) ) ) );
        $payment = $order->get_payment_method_title();
        $status = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : $order->get_status();
        $admin_order_url = $order->get_edit_order_url();
        $payment_url = $order->needs_payment() ? $order->get_checkout_payment_url() : '';
        $order_url = $order->get_user_id() ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
        $billing_email = $order->get_billing_email();
        $billing_phone = $order->get_billing_phone();
        $billing_company = $order->get_billing_company();
    }

    $ctx = array(
        'site_name' => dipes_brand_name(),
        'site_url' => home_url( '/' ),
        'first_name' => $first ?: 'Client',
        'last_name' => $last,
        'full_name' => $full ?: trim( $first . ' ' . $last ),
        'billing_email' => $billing_email,
        'billing_phone' => $billing_phone,
        'billing_company' => $billing_company,
        'order_number' => $number,
        'order_date' => $date,
        'order_total' => $total,
        'order_total_html' => $total_html,
        'payment_method' => $payment,
        'order_status' => $status,
        'order_url' => $order_url,
        'admin_order_url' => $admin_order_url,
        'payment_url' => $payment_url ?: $order_url,
        'account_url' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ),
        'account_orders_url' => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/my-account/orders/' ),
        'security_url' => class_exists( 'DIP_Email_Hub' ) ? DIP_Email_Hub::security_url() : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ) ),
        'admin_identity_url' => admin_url( 'admin.php?page=dip-ui-stability' ),
        'action_url' => '',
        'support_url' => dipes_get( 'support_url' ),
        'support_email' => dipes_get( 'support_email' ) ?: get_option( 'admin_email' ),
        'year' => gmdate( 'Y' ),
        'username' => '', 'display_name' => '', 'user_email' => '', 'reset_password_url' => '', 'set_password_url' => '',
        'account_primary_url' => '', 'account_primary_label' => '', 'needs_password_setup' => '', 'account_status' => '',
    );
    $ctx = array_merge( $ctx, is_array( $extra ) ? $extra : array() );
    if ( ! empty( $GLOBALS['dipes_preview_context'] ) && is_array( $GLOBALS['dipes_preview_context'] ) ) {
        $ctx = array_merge( $ctx, $GLOBALS['dipes_preview_context'] );
    }
    return $ctx;
}

function dipes_replace_tokens( $text, $ctx ) {
    if ( '' === (string) $text ) { return ''; }
    $replace = array();
    foreach ( $ctx as $key => $value ) {
        if ( is_scalar( $value ) ) {
            $replace[ '{' . $key . '}' ] = (string) $value;
        }
    }
    return strtr( (string) $text, $replace );
}

/**
 * Resolve Email Studio tokens and then let WooCommerce resolve its native
 * placeholders. WooCommerce formats its configured subject before applying
 * the subject filter, so custom subjects inserted by this plugin must be
 * explicitly formatted again or placeholders such as {order_number} leak
 * literally into the inbox.
 */
function dipes_format_wc_email_text( $template, $email = null, $order = null ) {
    if ( ! $order && is_object( $email ) && isset( $email->object ) ) {
        if ( is_a( $email->object, 'WC_Order' ) ) {
            $order = $email->object;
        } elseif ( is_numeric( $email->object ) && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( absint( $email->object ) );
        }
    }
    if ( is_numeric( $order ) && function_exists( 'wc_get_order' ) ) {
        $order = wc_get_order( absint( $order ) );
    }

    $formatted = dipes_replace_tokens( (string) $template, dipes_context( $email, $order ) );
    if ( is_object( $email ) && method_exists( $email, 'format_string' ) ) {
        $formatted = $email->format_string( $formatted );
    }
    return (string) $formatted;
}

function dipes_email_accent( $email_id ) {
    $custom = dipes_email_setting( $email_id, 'accent', '' );
    if ( 'yes' === dipes_get( 'show_status_colors' ) && $custom ) {
        return $custom;
    }
    return dipes_get( 'accent', '#ff6435' );
}

function dipes_email_toggle( $email_id, $key, $global_key ) {
    $value = dipes_email_setting( $email_id, $key, 'inherit' );
    if ( 'yes' === $value ) { return true; }
    if ( 'no' === $value ) { return false; }
    return 'yes' === dipes_get( $global_key, 'yes' );
}

function dipes_progress_step_for_order( $order, $email_id ) {
    $status = is_object( $order ) && is_a( $order, 'WC_Order' ) ? $order->get_status() : '';
    $map = array( 'pending' => 1, 'on-hold' => 1, 'failed' => 1, 'cancelled' => 1, 'processing' => 3, 'completed' => 4, 'refunded' => 4 );
    if ( isset( $map[ $status ] ) ) { return $map[ $status ]; }
    if ( false !== strpos( $email_id, 'completed' ) || false !== strpos( $email_id, 'refunded' ) ) { return 4; }
    if ( false !== strpos( $email_id, 'processing' ) ) { return 3; }
    return 2;
}

function dipes_is_order_email( $email_id ) {
    return ! in_array( $email_id, array( 'customer_new_account', 'customer_reset_password', 'wp_new_user_admin', 'identity_security_alert', 'identity_access_message', 'identity_admin_alert' ), true );
}

function dipes_current_block_order( $email_id ) {
    $order = dipes_email_setting( $email_id, 'block_order', dipes_default_block_order() );
    return is_array( $order ) ? $order : dipes_default_block_order();
}

/** Subject + heading are safe filters; WooCommerce still handles recipients and sending. */
add_action( 'plugins_loaded', static function () {
    foreach ( dipes_email_types() as $id => $label ) {
        add_filter( 'woocommerce_email_subject_' . $id, function ( $subject, $object = null, $email = null ) use ( $id ) {
            if ( 'yes' !== dipes_email_setting( $id, 'enabled', 'yes' ) ) { return $subject; }
            $custom = dipes_email_setting( $id, 'subject', '' );
            if ( ! $custom ) { return $subject; }
            $order = is_object( $object ) && is_a( $object, 'WC_Order' ) ? $object : ( is_numeric( $object ) && function_exists( 'wc_get_order' ) ? wc_get_order( absint( $object ) ) : null );
            return wp_strip_all_tags( dipes_format_wc_email_text( $custom, $email, $order ) );
        }, 99, 3 );
        add_filter( 'woocommerce_email_heading_' . $id, function ( $heading, $object = null, $email = null ) use ( $id ) {
            if ( 'yes' !== dipes_email_setting( $id, 'enabled', 'yes' ) ) { return $heading; }
            $custom = dipes_email_setting( $id, 'heading', '' );
            if ( ! $custom ) { return $heading; }
            $order = is_object( $object ) && is_a( $object, 'WC_Order' ) ? $object : ( is_numeric( $object ) && function_exists( 'wc_get_order' ) ? wc_get_order( absint( $object ) ) : null );
            return wp_strip_all_tags( dipes_format_wc_email_text( $custom, $email, $order ) );
        }, 99, 3 );
    }
} );

add_filter( 'woocommerce_email_order_items_args', static function ( $args ) {
    if ( empty( $GLOBALS['dipes_rendering_email'] ) && empty( $GLOBALS['dipes_preview_mode'] ) ) { return $args; }
    $size = absint( dipes_get( 'image_size', 58 ) );
    $args['show_image'] = 'yes' === dipes_get( 'show_item_images' );
    $args['image_size'] = array( $size, $size );
    $args['show_sku'] = 'yes' === dipes_get( 'show_sku' );
    return $args;
}, 99 );

add_filter( 'woocommerce_order_item_name', static function ( $name, $item, $is_visible = false ) {
    if ( empty( $GLOBALS['dipes_rendering_email'] ) || 'yes' !== dipes_get( 'product_links' ) ) { return $name; }
    if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) { return $name; }
    $product = $item->get_product();
    if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_permalink' ) ) { return $name; }
    $url = $product->get_permalink();
    return $url ? '<a href="' . esc_url( $url ) . '">' . wp_kses_post( $name ) . '</a>' : $name;
}, 99, 3 );

add_filter( 'woocommerce_order_item_get_formatted_meta_data', static function ( $formatted_meta, $item ) {
    if ( ! empty( $GLOBALS['dipes_rendering_email'] ) && 'yes' !== dipes_get( 'show_item_meta' ) ) { return array(); }
    return $formatted_meta;
}, 99, 2 );
