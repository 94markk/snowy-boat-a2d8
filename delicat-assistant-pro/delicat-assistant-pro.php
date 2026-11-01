<?php
/**
 * Plugin Name: Delicat Assistant Pro
 * Description: Secure mobile-first customer assistant for WooCommerce with wallet, orders, multilingual FAQ, WhatsApp and live admin chat.
 * Version: 7.0.0
 * Author: Delicat Store
 * Text Domain: delicat-assistant
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DSA2_VERSION', '7.0.0' );
define( 'DSA2_FILE', __FILE__ );
define( 'DSA2_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSA2_URL', plugin_dir_url( __FILE__ ) );

/**
 * v7.0.0 Rebuilt storefront: a 3 KB launcher (no request, no nonce in the HTML), a new chat app loaded on the
 *        first tap (isolated from the theme CSS), rebuilt community groups with "Ask the team" and a private
 *        follow-up, membership checks on every group read, report rules that cannot hide staff messages.
 * v6.6.4 Builder FAQ buttons the widget and the app can follow (with Delicat App API 2.33.1).
 * v6.6.3 Google Play: no financial service in the app. « Change et transfert » guide retired, « Crédit boutique » guide, Exchange / Finance products kept out of app answers.
 * v6.6.0 Community groups: moderated client-to-client rooms, members only.
 * v6.5.1 Launch performance: 86 KB less per page view, scroll work throttled to one frame.
 * v6.5.0 Automatic top-up is the main path; PIN is the smaller, separate one.
 * v6.4.1 Catalogue accuracy: the bot now answers about products the store actually sells.
 * v6.4.0 Agent quick messages and a wider conversational range.
 * v6.3.0 Conversational layer: greetings, small talk and a trilingual training corpus.
 * v6.1.0 Reliable customer messaging, secure support operations and Android synchronization.
 * Normal storefront requests load only the presentation/data kernel.
 * Admin, Assistant AJAX/REST and cron load the complete engine.
 */
function dsa2_require_file( $relative ) {
    $path = DSA2_DIR . 'includes/' . ltrim( (string) $relative, '/\\' );
    if ( is_file( $path ) ) require_once $path;
}
/**
 * 7.0: a page view parses two small files. The chat engine (data, guides, bot) is only
 * loaded by the chat's own requests; the launcher prints no nonce and no user id, so the
 * page HTML stays cacheable for everyone.
 */
function dsa2_load_frontend_kernel() {
    dsa2_require_file( 'class-dap-system-sync.php' );
    dsa2_require_file( 'class-dsa7-launcher.php' );
}
function dsa2_load_full_engine() {
    static $loaded = false;
    if ( $loaded ) return;
    foreach ( array(
        'class-dap-compat.php','class-dap-install.php','class-dap-guides.php','class-dap-products.php',
        'class-dap-recommend.php','class-dap-training.php','class-dap-smalltalk.php','class-dap-data.php','class-dsa7-launcher.php',
        'class-dap-quickreplies.php','class-dap-admin.php','class-dap-mobile-api.php','class-dap-admin-mobile-api.php',
        'class-dap-system-sync.php','class-dap-enterprise.php','class-dap-production.php',
        'class-dap-diagnostics.php','class-dap-groups.php','class-dap-groups-admin.php'
    ) as $file ) dsa2_require_file( $file );
    $loaded = true;
}

register_activation_hook( __FILE__, static function() {
    dsa2_load_full_engine();
    if ( class_exists( 'DSA2_Install', false ) ) DSA2_Install::activate();
} );
register_deactivation_hook( __FILE__, static function() {
    dsa2_load_full_engine();
    if ( class_exists( 'DSA2_Install', false ) ) DSA2_Install::unschedule_cleanup();
} );
add_action( 'dsa2_daily_cleanup', static function() {
    dsa2_load_full_engine();
    if ( class_exists( 'DSA2_Install', false ) ) DSA2_Install::cleanup();
    if ( class_exists( 'DSA2_Groups', false ) ) DSA2_Groups::cleanup();
} );

add_action( 'plugins_loaded', static function() {
    $action = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() && isset( $_REQUEST['action'] ) )
        ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $assistant_ajax = ( 0 === strpos( $action, 'dsa2_' ) || 0 === strpos( $action, 'dap_' ) );
    $scheduler_ajax = in_array( $action, array( 'as_async_request_queue_runner', 'action_scheduler_run_queue' ), true );
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $rest_route = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    // 6.6.2 (ported from 6.2.2): the Delicat App API's chat routes bridge into this engine
    // (DSA2_Recommend, DSA2_Products, DSA2_Training and the team alert on dsa2_message_created),
    // so they need the full engine too; a storefront page keeps the light kernel.
    $assistant_rest = ( false !== strpos( $uri, '/delicat-assistant/' )
        || false !== strpos( $uri, '/delicat-assistant-admin/' )
        || false !== strpos( $uri, '/delicat-app/v1/chat/' )
        || 0 === strpos( $rest_route, '/delicat-assistant/' )
        || 0 === strpos( $rest_route, '/delicat-assistant-admin/' )
        || 0 === strpos( $rest_route, '/delicat-app/v1/chat/' ) );
    $cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
    $real_admin = is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() );

    if ( $real_admin || $assistant_ajax || $assistant_rest || $cron || $scheduler_ajax ) {
        dsa2_load_full_engine();
        if ( class_exists( 'DSA2_Install', false ) ) DSA2_Install::maybe_upgrade();
        if ( class_exists( 'DSA2_Products', false ) ) DSA2_Products::init();
        if ( class_exists( 'DSA2_Recommend', false ) ) DSA2_Recommend::init();
        if ( class_exists( 'DSA2_Data', false ) ) DSA2_Data::init();
        if ( class_exists( 'DSA2_Mobile_API', false ) ) DSA2_Mobile_API::init();
        if ( class_exists( 'DSA2_Admin_Mobile_API', false ) ) DSA2_Admin_Mobile_API::init();
        if ( class_exists( 'DSA2_System_Sync', false ) ) DSA2_System_Sync::init();
        if ( class_exists( 'DSA2_Enterprise', false ) ) DSA2_Enterprise::init();
        if ( class_exists( 'DSA2_Production', false ) ) DSA2_Production::init();
        if ( class_exists( 'DSA2_Diagnostics', false ) ) DSA2_Diagnostics::init();
        if ( class_exists( 'DSA2_Groups', false ) ) DSA2_Groups::init();
        if ( $real_admin && class_exists( 'DSA2_Groups_Admin', false ) ) DSA2_Groups_Admin::init();
        // admin-ajax.php is an AJAX request, not a "real" admin screen. Registering
        // these callbacks only for the latter made every inbox request return "0".
        if ( ( $real_admin || $assistant_ajax ) && class_exists( 'DSA2_Admin', false ) ) DSA2_Admin::init();
    } else {
        // Storefront pages and the launcher's own dsa7_* requests. Groups no longer print
        // anything on page views: the community lives inside the chat app.
        dsa2_load_frontend_kernel();
        if ( class_exists( 'DSA2_System_Sync', false ) ) DSA2_System_Sync::init();
        if ( class_exists( 'DSA7_Launcher', false ) ) DSA7_Launcher::init();
    }

    // Prevent an old Builder's legacy assistant (class Delicat_Assistant_Pro, Builder 8 and
    // earlier) from rendering a second widget, whatever priority it hooked wp_footer at.
    if ( class_exists( 'Delicat_Assistant_Pro', false ) ) {
        add_action( 'template_redirect', 'dsa2_suppress_legacy_widget', 1 );
    }
    // Both shortcodes embed the new app inline (help centre, or the community).
    remove_shortcode( 'delicat_assistant' );
    remove_shortcode( 'delicat_groups' );
    if ( class_exists( 'DSA7_Launcher', false ) ) {
        add_shortcode( 'delicat_assistant', array( 'DSA7_Launcher', 'shortcode' ) );
        add_shortcode( 'delicat_groups', array( 'DSA7_Launcher', 'shortcode' ) );
    }
}, 99 );

function dsa2_suppress_legacy_widget() {
    if ( empty( $GLOBALS['wp_filter']['wp_footer'] ) || ! isset( $GLOBALS['wp_filter']['wp_footer']->callbacks ) ) return;
    foreach ( (array) $GLOBALS['wp_filter']['wp_footer']->callbacks as $priority => $callbacks ) {
        foreach ( (array) $callbacks as $cb ) {
            $fn = isset( $cb['function'] ) ? $cb['function'] : null;
            if ( is_array( $fn ) && isset( $fn[0] ) && $fn[0] instanceof Delicat_Assistant_Pro ) remove_action( 'wp_footer', $fn, $priority );
        }
    }
}

/**
 * 6.6.1 (ported from 6.2.2) -- account deletion erases the customer's support history.
 * Registered outside the loader so it works whatever engine the request loaded: the
 * Google Play deletion flow fires `delicat_app_erase_customer_data` from a public page,
 * with the account e-mail as second argument, so pre-login guest chats go too.
 */
add_action( 'delicat_app_erase_customer_data', static function( $user_id, $email = '' ) {
    dsa2_load_full_engine();
    if ( class_exists( 'DSA2_Data', false ) && method_exists( 'DSA2_Data', 'erase_customer_data' ) ) {
        DSA2_Data::erase_customer_data( absint( $user_id ), is_string( $email ) ? $email : '' );
    }
}, 10, 2 );
add_filter( 'wp_privacy_personal_data_erasers', static function( $erasers ) {
    $erasers['delicat-assistant'] = array(
        'eraser_friendly_name' => __( 'Delicat Assistant — conversations', 'delicat-assistant' ),
        'callback'             => static function( $email, $page = 1 ) {
            $user     = get_user_by( 'email', $email );
            $user_id  = $user ? (int) $user->ID : 0;
            $removed  = false;
            $retained = false;
            $messages = array();
            dsa2_load_full_engine();
            if ( class_exists( 'DSA2_Data', false ) && method_exists( 'DSA2_Data', 'erase_customer_data' ) ) {
                $removed = DSA2_Data::erase_customer_data( $user_id, (string) $email ) > 0;
                if ( method_exists( 'DSA2_Data', 'erasable_conversation_ids' ) && DSA2_Data::erasable_conversation_ids( $user_id, (string) $email ) ) {
                    $retained   = true;
                    $messages[] = __( 'Certaines conversations d’assistance n’ont pas pu être supprimées. Relancez l’effacement.', 'delicat-assistant' );
                }
            }
            return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true );
        },
    );
    return $erasers;
} );
