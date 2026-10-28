<?php
defined( 'ABSPATH' ) || exit;

function dipes_template_map() {
    $generic = 'templates/emails/generic-order.php';
    return array(
        'emails/email-header.php' => array( 'file' => 'templates/emails/email-header.php', 'id' => '' ),
        'emails/email-footer.php' => array( 'file' => 'templates/emails/email-footer.php', 'id' => '' ),
        'emails/email-styles.php' => array( 'file' => 'templates/emails/email-styles.php', 'id' => '' ),
        'emails/email-customer-details.php' => array( 'file' => 'templates/emails/email-customer-details.php', 'id' => '' ),
        'emails/email-addresses.php' => array( 'file' => 'templates/emails/email-addresses.php', 'id' => '' ),
        'emails/admin-new-order.php' => array( 'file' => $generic, 'id' => 'new_order' ),
        'emails/admin-cancelled-order.php' => array( 'file' => $generic, 'id' => 'cancelled_order' ),
        'emails/admin-failed-order.php' => array( 'file' => $generic, 'id' => 'failed_order' ),
        // Compatibility aliases used by some older custom email classes.
        'emails/cancelled-order.php' => array( 'file' => $generic, 'id' => 'cancelled_order' ),
        'emails/failed-order.php' => array( 'file' => $generic, 'id' => 'failed_order' ),
        'emails/customer-on-hold-order.php' => array( 'file' => $generic, 'id' => 'customer_on_hold_order' ),
        'emails/customer-processing-order.php' => array( 'file' => $generic, 'id' => 'customer_processing_order' ),
        'emails/customer-completed-order.php' => array( 'file' => $generic, 'id' => 'customer_completed_order' ),
        'emails/customer-refunded-order.php' => array( 'file' => $generic, 'id' => 'customer_refunded_order' ),
        'emails/customer-invoice.php' => array( 'file' => $generic, 'id' => 'customer_invoice' ),
        'emails/customer-note.php' => array( 'file' => $generic, 'id' => 'customer_note' ),
        'emails/customer-failed-order.php' => array( 'file' => $generic, 'id' => 'customer_failed_order' ),
        'emails/customer-cancelled-order.php' => array( 'file' => $generic, 'id' => 'customer_cancelled_order' ),
        'emails/customer-new-account.php' => array( 'file' => 'templates/emails/customer-new-account.php', 'id' => 'customer_new_account' ),
        'emails/customer-reset-password.php' => array( 'file' => 'templates/emails/customer-reset-password.php', 'id' => 'customer_reset_password' ),
    );
}

add_filter( 'woocommerce_locate_template', static function ( $template, $template_name, $template_path ) {
    if ( 0 !== strpos( $template_name, 'emails/' ) || 0 === strpos( $template_name, 'emails/plain/' ) ) { return $template; }
    $map = dipes_template_map();
    if ( empty( $map[ $template_name ] ) ) { return $template; }
    $entry = $map[ $template_name ];
    if ( ! empty( $entry['id'] ) && 'yes' !== dipes_email_setting( $entry['id'], 'enabled', 'yes' ) ) { return $template; }
    $custom = DIPES_DIR . $entry['file'];
    return file_exists( $custom ) ? $custom : $template;
}, 99, 3 );
