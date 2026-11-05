<?php
/**
 * Plugin Name: Delicat Assistant Pro
 * Description: Secure mobile-first customer assistant for WooCommerce with wallet, orders, multilingual FAQ, WhatsApp and live admin chat.
 * Version: 7.1.1
 * Author: Delicat Store
 * Text Domain: delicat-assistant
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DSA2_VERSION', '7.1.1' );
define( 'DSA2_FILE', __FILE__ );
define( 'DSA2_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSA2_URL', plugin_dir_url( __FILE__ ) );
// 7.1.1: a staff phone-app key is valid this many days after sign-in, at most (it was up to 90).
define( 'DSA2_STAFF_SESSION_MAX_DAYS', 7 );

/**
 * v7.1.1 Security: staff phone app off by default (2FA, 7-day keys, signed out on any password or role
 *        change), throttled team alerts, contact details before the team, Cloudflare-aware limits, private
 *        payment screenshots, full phone for guest order lookups, per-viewer prices in suggestions,
 *        old customer chat API removed.
 * v7.1.0 Smarter answers: order lookup by number (own orders only) with a shareable receipt, small talk
 *        that no longer swallows questions, specific answers before generic guides, store programmes.
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
        'class-dap-recommend.php','class-dap-training.php','class-dap-smalltalk.php','class-dap-brain.php','class-dap-data.php','class-dsa7-launcher.php',
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

// 7.1.1: payment screenshots move from the public uploads folder to the private one, a batch at a time.
add_action( 'dsa2_private_files_batch', static function() {
    dsa2_load_full_engine();
    if ( class_exists( 'DSA2_Data', false ) && DSA2_Data::migrate_public_images( 25 ) > 0 && ! wp_next_scheduled( 'dsa2_private_files_batch' ) ) {
        wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'dsa2_private_files_batch' );
    }
} );
// 7.1.1: when the hourly cap of team alert e-mails is reached, one summary goes out at the end of the hour.
add_action( 'dsa2_send_alert_summary', static function() {
    dsa2_load_full_engine();
    if ( class_exists( 'DSA2_Data', false ) ) DSA2_Data::send_alert_summary();
} );

add_action( 'dsa2_group_question_alert', static function( $group_id, $message_id ) {
    if ( ! class_exists( 'DSA2_Groups', false ) ) dsa2_load_full_engine();
    if ( class_exists( 'DSA2_Groups', false ) && ! has_action( 'dsa2_group_question_alert', array( 'DSA2_Groups', 'send_question_alert' ) ) ) DSA2_Groups::send_question_alert( $group_id, $message_id );
}, 10, 2 );

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
 * 7.1.1 -- the visitor's address for every rate limit of this plugin. Behind Cloudflare the
 * connection address is a Cloudflare edge shared by many customers, so one script could use
 * up a limit for everyone. Identity's address first (it believes CF-Connecting-IP only from
 * Cloudflare's own ranges), then the Builder's (same rule), else the connection address.
 */
function dsa2_client_ip() {
    static $resolved = null;
    if ( null !== $resolved ) return $resolved;
    $candidate = '';
    try {
        if ( class_exists( 'DIP_Native_Auth' ) && is_callable( array( 'DIP_Native_Auth', 'throttle_ip' ) ) ) {
            $candidate = (string) DIP_Native_Auth::throttle_ip();
        } elseif ( class_exists( 'Delicat_Builder_V9_Security' ) && is_callable( array( 'Delicat_Builder_V9_Security', 'client_ip' ) ) ) {
            $candidate = (string) Delicat_Builder_V9_Security::client_ip();
        }
    } catch ( Throwable $error ) {
        $candidate = '';
    }
    if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
        $remote    = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
        $candidate = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
    }
    $resolved = $candidate;
    return $resolved;
}

/** 7.1.1 -- who may use the staff phone app (same rule as the app's own permission check). */
function dsa2_is_staff_user( $user ) {
    return $user instanceof WP_User && (
        user_can( $user, 'manage_delicat_assistant' ) ||
        user_can( $user, 'manage_woocommerce' ) ||
        user_can( $user, 'manage_options' )
    );
}

/**
 * 7.1.1 -- signs a person out of the staff phone app everywhere. Registered below, outside
 * the loader, so a password reset from wp-login.php or the account page, a role change or a
 * deleted account always ends the app keys; before, these hooks only existed on the
 * requests that loaded the full engine, and a reset from the normal page left keys working.
 */
function dsa2_revoke_staff_app_sessions( $user_id ) {
    $user_id = absint( $user_id );
    if ( ! $user_id || ! get_option( 'dap_db_version' ) ) return 0;
    global $wpdb;
    return (int) $wpdb->delete( $wpdb->prefix . 'dap_admin_sessions', array( 'user_id' => $user_id ), array( '%d' ) );
}
add_action( 'password_reset', static function( $user ) {
    if ( $user instanceof WP_User ) dsa2_revoke_staff_app_sessions( $user->ID );
}, 10, 1 );
add_action( 'after_password_reset', static function( $user ) {
    if ( $user instanceof WP_User ) dsa2_revoke_staff_app_sessions( $user->ID );
}, 10, 1 );
add_action( 'wp_set_password', static function( $password, $user_id = 0 ) {
    dsa2_revoke_staff_app_sessions( $user_id );
}, 10, 2 );
add_action( 'profile_update', static function( $user_id, $old_user_data = null ) {
    $user = get_userdata( absint( $user_id ) );
    $password_changed = $user && $old_user_data instanceof WP_User && ! hash_equals( (string) $old_user_data->user_pass, (string) $user->user_pass );
    if ( $password_changed || ! dsa2_is_staff_user( $user ) ) dsa2_revoke_staff_app_sessions( $user_id );
}, 10, 2 );
add_action( 'set_user_role', static function( $user_id ) {
    if ( ! dsa2_is_staff_user( get_userdata( absint( $user_id ) ) ) ) dsa2_revoke_staff_app_sessions( $user_id );
}, 10, 1 );
add_action( 'remove_user_role', static function( $user_id ) {
    if ( ! dsa2_is_staff_user( get_userdata( absint( $user_id ) ) ) ) dsa2_revoke_staff_app_sessions( $user_id );
}, 10, 1 );
add_action( 'delete_user', 'dsa2_revoke_staff_app_sessions', 10, 1 );
add_action( 'deleted_user', 'dsa2_revoke_staff_app_sessions', 10, 1 );
add_action( 'remove_user_from_blog', 'dsa2_revoke_staff_app_sessions', 10, 1 );

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
