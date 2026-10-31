<?php
/**
 * TEST-ONLY: loads the Builder 9.5.1 order modules the way the Runtime Router does on account
 * pages, without the rest of the Builder (WooCommerce is a stub on this site).
 */
define('DELICAT_BUILDER_V9_DIR', WP_PLUGIN_DIR . '/delicat-builder-v9/');
define('DELICAT_BUILDER_V9_URL', plugins_url('/delicat-builder-v9/'));
define('DELICAT_BUILDER_V9_VERSION', '9.5.1-test');
if (get_option('dipstub_builder', '1') === '1') {
    require DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-order-confirmation-compat.php';
    add_action('wp', function () {
        if (is_account_page() && is_user_logged_in() && Delicat_Builder_V9_Order_Confirmation_Compat::enabled()) {
            require_once DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-order-status.php';
            require_once DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-order-confirmation.php';
            require_once DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-order-view.php';
            Delicat_Builder_V9_Order_View::boot();
        }
    }, 5);
    $phase = get_option('dipstub_force_phase', []);
    add_filter('delicat_builder_order_phase', function ($p, $order) use ($phase) { return $phase[$order->get_id()] ?? $p; }, 10, 2);
}
