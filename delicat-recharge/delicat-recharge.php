<?php
/**
 * Plugin Name: Delicat Recharge Admin (HQ)
 * Plugin URI:  https://delicastoreha.com
 * Description: Système de recharge de wallet TeraWallet via MonCash (auto), Natcash (auto) et Manuel (proof upload).
 * Version:     6.5.1
 * Author:      Delicat Store Haiti
 * Text Domain: delicat-recharge
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Prevent critical errors if another Delicat Recharge copy is still active.
if ( defined( 'DELICAT_RECHARGE_LOADED' ) ) {
    return;
}
define( 'DELICAT_RECHARGE_LOADED', true );

if ( ! defined( 'DELICAT_VERSION' ) ) {
    define( 'DELICAT_VERSION',  '6.0.5' );
}
if ( ! defined( 'DELICAT_ASSET_VERSION' ) ) {
    // UI-only cache version. Keep DELICAT_VERSION unchanged so database/self-healing
    // version checks do not run just because presentation assets changed.
    define( 'DELICAT_ASSET_VERSION', '6.5.1' );
}
if ( ! defined( 'DELICAT_API_SYNC_VERSION' ) ) {
    // Integration version only: never trigger the existing database upgrades
    // or change the established recharge presentation/assets.
    define( 'DELICAT_API_SYNC_VERSION', '6.2.0' );
}
if ( ! defined( 'DELICAT_SMS_GUARD_VERSION' ) ) {
    // SMS Guard (anti-spoofing) version. No database migration.
    define( 'DELICAT_SMS_GUARD_VERSION', '6.3.0' );
}
if ( ! defined( 'DELICAT_PATH' ) ) {
    define( 'DELICAT_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'DELICAT_URL' ) ) {
    define( 'DELICAT_URL', plugin_dir_url( __FILE__ ) );
}

// Load all classes
foreach ( [
    'class-database',
    'class-files',
    'class-terawallet',
    'class-moncash',
    'class-natcash',
    'class-email',
    'class-webhook',
    'class-ajax-handler',
    'class-shortcode',
    'class-send-money',
    'class-admin',
    'class-clients',
    'class-guard',
    'class-watchdog',
    'class-push',
    'class-alerts',
    'class-rest',
    'class-app-api',
    'class-natcash-auto',
    'class-sms-guard',
    'class-license',
    'class-client-license',
    'class-client-ux',
    'class-workflow',
    'class-growth',
    'class-api-sync',
    'class-code-lookup',
] as $file ) {
    require_once DELICAT_PATH . "includes/{$file}.php";
}

register_activation_hook( __FILE__, function () {
    Delicat_Database::create_table();
    Delicat_Send_Money::create_table();
} );

// Self-healing: ensure tables exist even if the plugin was updated
// by overwriting files (which skips the activation hook).
add_action( 'init', function () {
    if ( get_option( 'delicat_db_version' ) !== DELICAT_VERSION ) {
        Delicat_Database::create_table();
        Delicat_Send_Money::create_table();
        update_option( 'delicat_db_version', DELICAT_VERSION );
    }
}, 1 );

add_action( 'plugins_loaded', function () {
    new Delicat_Files();
    new Delicat_Ajax_Handler();
    new Delicat_Shortcode();
    new Delicat_Admin();
    new Delicat_Clients();
    new Delicat_Send_Money();
    new Delicat_Guard();
    new Delicat_Watchdog();
    new Delicat_Alerts();
    new Delicat_Rest();
    new Delicat_App_Api();
    new Delicat_Natcash_Auto();
    Delicat_SMS_Guard::init();
    new Delicat_License();
    new Delicat_Client_Ux();
    new Delicat_Workflow();
    new Delicat_Growth();
    Delicat_Recharge_Api_Sync::init();
    Delicat_Code_Lookup::init(); // 6.5.0: read-only TransCode search (admin)
} );

// Clear scheduled cron jobs when the plugin is deactivated.
register_deactivation_hook( __FILE__, function () {
    if ( class_exists( 'Delicat_Alerts' ) ) {
        Delicat_Alerts::clear_schedules();
    }
} );


/* ============================================================
   v5.0.3 Safe Design Loader
   Loads the new Apple Glass wallet CSS on WooCommerce account pages,
   even when the recharge shortcode is not present. Admin, licensing,
   database, payment logic and support tools are untouched.
   ============================================================ */
/**
 * 6.5.1: whether this account page can show the wallet design (.dmc/.dsm/.dpc,
 * recharge and balance components). The 286 KB wallet stylesheet, form.js
 * (with jQuery) and the glass override used to load on every My Account page,
 * including order details, the orders list, profile and security screens that
 * never contain them, which made every account section slower to open.
 * Shortcode renders still enqueue the assets themselves wherever they appear.
 */
function delicat_recharge_account_needs_wallet_ui(): bool {
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
        return false;
    }
    $endpoint = '';
    $query    = ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->query ) ) ? WC()->query : null;
    if ( $query && is_callable( array( $query, 'get_current_endpoint' ) ) ) {
        $endpoint = (string) $query->get_current_endpoint();
    } elseif ( $query && is_callable( array( $query, 'get_query_vars' ) ) ) {
        global $wp;
        foreach ( (array) $query->get_query_vars() as $key => $var ) {
            if ( isset( $wp->query_vars[ $var ] ) ) {
                $endpoint = (string) $key;
                break;
            }
        }
    }
    $skip = array( 'orders', 'view-order', 'downloads', 'edit-address', 'edit-account', 'payment-methods', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method', 'change-password', 'identity-center', 'security-sessions', 'customer-logout', 'lost-password' );
    /* The account home is Delicat Identity's own screen when it is active; without it, a
     * legacy dashboard may still carry the wallet card, so the design keeps loading there. */
    if ( '' === $endpoint && class_exists( 'DIP_My_Account_Modern' ) ) {
        $skip[] = '';
    }
    $skip = (array) apply_filters( 'delicat_recharge_wallet_ui_skip_endpoints', $skip );
    return ! in_array( $endpoint, $skip, true );
}

add_action( 'wp_enqueue_scripts', function () {
    if ( is_admin() ) {
        return;
    }

    $should_load = false;

    if ( delicat_recharge_account_needs_wallet_ui() ) {
        $should_load = true;
    }

    if ( function_exists( 'is_page' ) && ( is_page( 'my-wallet' ) || is_page( 'wallet' ) ) ) {
        $should_load = true;
    }

    if ( $should_load ) {
        wp_enqueue_style( 'delicat-recharge', DELICAT_URL . 'assets/css/form.css', [], defined( 'DELICAT_ASSET_VERSION' ) ? DELICAT_ASSET_VERSION : DELICAT_VERSION );
    }
}, 9999 );

add_action( 'wp_head', function () {
    if ( is_admin() ) {
        return;
    }

    $should_load = false;

    if ( delicat_recharge_account_needs_wallet_ui() ) {
        $should_load = true;
    }

    if ( function_exists( 'is_page' ) && ( is_page( 'my-wallet' ) || is_page( 'wallet' ) ) ) {
        $should_load = true;
    }

    if ( ! $should_load ) {
        return;
    }
    ?>
    <style id="delicat-wallet-glass-override-v503">
    .woocommerce-account .woocommerce-MyAccount-content:has(.dmc),
    .woocommerce-account .woocommerce-MyAccount-content:has(.dsm),
    body.page .entry-content:has(.dmc),
    body.page .entry-content:has(.dsm){
        position:relative!important;
        overflow:visible!important;
    }
    .dmc,.dsm,.dpc,.dr-wrap{
        --delicat-ink:#070b3f!important;
        --delicat-blue:#2778ff!important;
        --delicat-cyan:#65d4ff!important;
        --delicat-violet:#7b4dff!important;
        --delicat-pink:#ff5bbd!important;
        --delicat-orange:#ff9f5a!important;
        --delicat-glass:rgba(255,255,255,.76)!important;
        --delicat-line:rgba(100,112,220,.18)!important;
        --delicat-shadow:0 24px 70px rgba(35,57,145,.18)!important;
    }
    .dmc{max-width:1000px!important;margin:0 auto!important;position:relative!important;isolation:isolate!important;}
    .dmc:before,.dsm:before,.dr-wrap:before{
        content:""!important;position:absolute!important;inset:-34px!important;border-radius:36px!important;z-index:-2!important;pointer-events:none!important;
        background:radial-gradient(circle at 6% 12%,rgba(99,211,255,.34) 0 9%,transparent 20%),radial-gradient(circle at 92% 14%,rgba(255,118,190,.24) 0 11%,transparent 24%),radial-gradient(circle at 72% 94%,rgba(126,82,255,.2) 0 12%,transparent 25%),linear-gradient(135deg,#f8fbff 0%,#fff 42%,#f7f1ff 100%)!important;
    }
    .dmc-hero{
        border-radius:32px!important;padding:30px!important;color:var(--delicat-ink)!important;overflow:hidden!important;position:relative!important;
        background:radial-gradient(circle at 8% 16%,rgba(105,213,255,.42),transparent 28%),radial-gradient(circle at 86% 18%,rgba(255,130,199,.32),transparent 30%),radial-gradient(circle at 66% 100%,rgba(125,88,255,.24),transparent 35%),linear-gradient(135deg,rgba(255,255,255,.9),rgba(242,248,255,.7))!important;
        border:1px solid rgba(255,255,255,.86)!important;box-shadow:var(--delicat-shadow),inset 0 1px 0 rgba(255,255,255,.92)!important;
        backdrop-filter:blur(24px) saturate(165%)!important;-webkit-backdrop-filter:blur(24px) saturate(165%)!important;
    }
    .dmc-hero:before{content:""!important;position:absolute!important;inset:0!important;background:linear-gradient(135deg,rgba(255,255,255,.26),transparent 38%,rgba(255,255,255,.2))!important;pointer-events:none!important;}
    .dmc-hero::after{content:""!important;position:absolute!important;right:-55px!important;top:-55px!important;width:170px!important;height:170px!important;border-radius:50%!important;background:radial-gradient(circle,rgba(255,255,255,.7),rgba(255,255,255,.18) 46%,transparent 68%)!important;box-shadow:120px 180px 0 rgba(255,255,255,.22),-170px 200px 0 rgba(103,206,255,.18)!important;pointer-events:none!important;}
    .dmc-hero-top,.dmc-balance-row{position:relative!important;z-index:2!important;}
    .dmc-hello,.dmc-blabel,.dmc-email{color:#28345f!important;}
    .dmc-bamount,.dmc-bamount *{font-size:clamp(2.4rem,8vw,4.4rem)!important;letter-spacing:-.07em!important;color:var(--delicat-ink)!important;text-shadow:0 10px 24px rgba(36,61,141,.12)!important;}
    .dmc-avatar{border-radius:22px!important;border:1px solid rgba(255,255,255,.78)!important;box-shadow:0 14px 30px rgba(45,80,180,.12)!important;}
    .dmc-btn,.dsm-btn{border-radius:18px!important;padding:14px 20px!important;font-weight:900!important;border:1px solid rgba(255,255,255,.65)!important;box-shadow:0 14px 30px rgba(55,75,160,.12),inset 0 1px 0 rgba(255,255,255,.7)!important;}
    .dmc-btn-light,.dmc-btn-solid,.dsm-btn-solid{background:linear-gradient(135deg,var(--delicat-blue),var(--delicat-violet),var(--delicat-pink))!important;color:#fff!important;}
    .dmc-btn-ghost,.dsm-btn-ghost{background:rgba(255,255,255,.66)!important;color:#17205a!important;backdrop-filter:blur(14px)!important;-webkit-backdrop-filter:blur(14px)!important;}
    .dmc-stat,.dmc-recent,.dmc-card,.dsm-card,.dpc{border-radius:24px!important;background:linear-gradient(145deg,rgba(255,255,255,.82),rgba(248,251,255,.62))!important;border:1px solid rgba(255,255,255,.86)!important;box-shadow:0 18px 45px rgba(49,61,136,.1),inset 0 1px 0 rgba(255,255,255,.95)!important;backdrop-filter:blur(18px) saturate(155%)!important;-webkit-backdrop-filter:blur(18px) saturate(155%)!important;}
    .dsm-codecard{border-radius:26px!important;background:linear-gradient(135deg,#287dff 0%,#7550ff 48%,#ff5abd 78%,#ff9a5b 100%)!important;color:#fff!important;box-shadow:0 20px 48px rgba(109,81,255,.28),inset 0 1px 0 rgba(255,255,255,.34)!important;position:relative!important;overflow:hidden!important;}
    .dsm-codecard:after{content:""!important;position:absolute!important;right:-34px!important;top:-42px!important;width:145px!important;height:145px!important;border-radius:50%!important;background:rgba(255,255,255,.18)!important;box-shadow:-180px 135px 0 rgba(255,255,255,.1)!important;}
    .dsm-codecard>*{position:relative!important;z-index:1!important;}
    .dsm-input{border-radius:17px!important;background:rgba(255,255,255,.82)!important;border:1px solid rgba(107,122,180,.18)!important;}
    @media(max-width:720px){.dmc-hero{padding:22px!important;border-radius:28px!important}.dmc-actions{display:grid!important;grid-template-columns:1fr!important;width:100%!important}.dmc-bamount,.dmc-bamount *{font-size:clamp(2.2rem,10vw,3.4rem)!important}}
    </style>
    <?php
}, 1000000 );
