<?php
/**
 * Plugin Name: Delicat Recharge Admin (HQ)
 * Plugin URI:  https://delicastoreha.com
 * Description: Système de recharge de wallet TeraWallet via MonCash (auto), Natcash (auto) et Manuel (proof upload).
 * Version:     6.8.0
 * Author:      Delicat Store Haiti
 * Text Domain: delicat-recharge
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Prevent critical errors if another Delicat Recharge copy is still active.
if ( defined( 'DELICAT_RECHARGE_LOADED' ) ) {
    return;
}
define( 'DELICAT_RECHARGE_LOADED', true );

if ( ! defined( 'DELICAT_VERSION' ) ) {
    // 6.5.2: schema change (indexed TransCode column + date indexes).
    // 6.6.4: schema change (bonus_done column on the recharges table).
    // 6.6.5: one-time data clean-up (retired nc_pin values blanked) + SMS table.
    // 6.7.0: no schema change; the idempotent self-heal (dbDelta) runs once.
    define( 'DELICAT_VERSION',  '6.8.0' );
}
if ( ! defined( 'DELICAT_ASSET_VERSION' ) ) {
    // UI-only cache version. Keep DELICAT_VERSION unchanged so database/self-healing
    // version checks do not run just because presentation assets changed.
    define( 'DELICAT_ASSET_VERSION', '6.7.0' );
}
if ( ! function_exists( 'delicat_recharge_icon' ) ) {
    /**
     * 6.6.1: a Material Symbols glyph from the Delicat Design System (Builder 9.6+).
     * Empty when the Builder is not active, so the form never shows a broken icon.
     */
    function delicat_recharge_icon( string $name, int $size = 18 ): string {
        return function_exists( 'delicat_ds_icon' ) ? delicat_ds_icon( $name, $size ) : '';
    }
}
if ( ! function_exists( 'delicat_recharge_status_mark' ) ) {
    /**
     * 6.6.2: a yes/no state for admin tables, as a Material icon plus words (6.6.1 had removed
     * the emoji and left both states blank). The words keep the meaning without the icon set.
     */
    function delicat_recharge_status_mark( bool $on, string $on_label, string $off_label ): string {
        return '<span style="color:' . ( $on ? '#15803D' : '#B91C1C' ) . ';display:inline-flex;align-items:center;gap:4px;font-weight:600">'
            . delicat_recharge_icon( $on ? 'check-circle' : 'block', 16 ) . esc_html( $on ? $on_label : $off_label ) . '</span>';
    }
}
if ( ! function_exists( 'delicat_recharge_finish_request' ) ) {
    /**
     * 6.5.2: hand the response to the customer before the slow work that runs
     * on shutdown (e-mails, push, webhooks). PHP-FPM offers
     * fastcgi_finish_request(); LiteSpeed - the server Hostinger runs - offers
     * litespeed_finish_request() instead, the same pair wp-cron.php checks.
     * Without the second one, every submit waited for its own notifications.
     */
    function delicat_recharge_finish_request(): void {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;
        if ( function_exists( 'ignore_user_abort' ) ) {
            ignore_user_abort( true ); // some hosts disable it; PHP 8 would then fatal
        }
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } elseif ( function_exists( 'litespeed_finish_request' ) ) {
            litespeed_finish_request();
        }
    }
}
if ( ! function_exists( 'delicat_recharge_asset_url' ) ) {
    /**
     * 6.7.0: URL of a front-end file, minified build first. assets/css/form.css and
     * assets/js/form.js are the readable sources; tools/build-assets.sh writes
     * form.min.css / form.min.js next to them (same rules, about half the bytes for
     * the script). With SCRIPT_DEBUG, or if a .min file is missing, the source is used.
     */
    function delicat_recharge_asset_url( string $rel ): string {
        if ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ) {
            $min = preg_replace( '/\.(css|js)$/', '.min.$1', $rel );
            if ( $min !== $rel && file_exists( DELICAT_PATH . $min ) ) {
                return DELICAT_URL . $min;
            }
        }
        return DELICAT_URL . $rel;
    }
}
if ( ! defined( 'DELICAT_API_SYNC_VERSION' ) ) {
    // Integration version only: never trigger the existing database upgrades
    // or change the established recharge presentation/assets.
    define( 'DELICAT_API_SYNC_VERSION', '6.2.0' );
}
if ( ! defined( 'DELICAT_SMS_GUARD_VERSION' ) ) {
    // SMS Guard (anti-spoofing) version. No database migration.
    // 6.6.4: MonCash balance line required by default (one-time option switch).
    define( 'DELICAT_SMS_GUARD_VERSION', '6.6.4' );
}
if ( ! defined( 'DELICAT_PATH' ) ) {
    define( 'DELICAT_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'DELICAT_URL' ) ) {
    define( 'DELICAT_URL', plugin_dir_url( __FILE__ ) );
}

/*
 * V10: classes load on demand. Every file in includes/ holds one class and nothing else, so this
 * class map replaces the 27 require_once calls of 6.7.0: a static helper (Delicat_Database,
 * Delicat_Push, Delicat_Natcash_Auto::norm_code() ...) still works from anywhere, but a storefront
 * page no longer compiles the console, AJAX, REST and cron code (578 KB of PHP down to about 76 KB).
 * 6.6.4: class-moncash (MonCash Business API check) and class-natcash (Natcash API placeholder)
 * were removed: nothing called them (RCH-10).
 */
spl_autoload_register( static function ( $class ) {
    static $map = [
        'delicat_database'          => 'class-database',
        'delicat_files'             => 'class-files',
        'delicat_terawallet'        => 'class-terawallet',
        'delicat_email'             => 'class-email',
        'delicat_webhook'           => 'class-webhook',
        'delicat_ajax_handler'      => 'class-ajax-handler',
        'delicat_shortcode'         => 'class-shortcode',
        'delicat_send_money'        => 'class-send-money',
        'delicat_admin'             => 'class-admin',
        'delicat_clients'           => 'class-clients',
        'delicat_guard'             => 'class-guard',
        'delicat_watchdog'          => 'class-watchdog',
        'delicat_push'              => 'class-push',
        'delicat_alerts'            => 'class-alerts',
        'delicat_rest'              => 'class-rest',
        'delicat_app_api'           => 'class-app-api',
        'delicat_natcash_auto'      => 'class-natcash-auto',
        'delicat_sms_store'         => 'class-sms-store', // 6.6.5: SMS kept 24 h (minimum) then deleted, body encrypted
        'delicat_sms_guard'         => 'class-sms-guard',
        'delicat_license'           => 'class-license',
        'delicat_client_license'    => 'class-client-license',
        'delicat_client_ux'         => 'class-client-ux',
        'delicat_workflow'          => 'class-workflow',
        'delicat_growth'            => 'class-growth',
        'delicat_recharge_api_sync' => 'class-api-sync',
        'delicat_code_lookup'       => 'class-code-lookup',
        'delicat_maintenance'       => 'class-maintenance',
        'delicat_qr'                => 'class-qr', // V10: QR codes drawn locally (no api.qrserver.com)
    ];
    $key = strtolower( (string) $class );
    if ( isset( $map[ $key ] ) ) {
        require_once DELICAT_PATH . 'includes/' . $map[ $key ] . '.php';
    }
} );

/**
 * V10: every component, in the order 6.7.0 built them on each request.
 */
function delicat_recharge_parts(): array {
    return [ 'files', 'ajax_handler', 'shortcode', 'admin', 'clients', 'send_money', 'guard', 'watchdog', 'alerts', 'rest', 'app_api', 'natcash_auto', 'sms_guard', 'license', 'client_ux', 'workflow', 'growth', 'api_sync', 'code_lookup', 'maintenance', 'sms_store' ];
}

/**
 * V10: builds components (their hooks, shortcodes and REST routes) at most once per request.
 */
function delicat_recharge_boot( array $parts ): void {
    static $built = [];
    foreach ( $parts as $part ) {
        if ( isset( $built[ $part ] ) ) {
            continue;
        }
        $built[ $part ] = true;
        switch ( $part ) {
            case 'files':        new Delicat_Files(); break;
            case 'ajax_handler': new Delicat_Ajax_Handler(); break;
            case 'shortcode':    new Delicat_Shortcode(); break;
            case 'admin':        new Delicat_Admin(); break;
            case 'clients':
                remove_filter( 'authenticate', 'delicat_recharge_login_ban', 30 ); // the real filter takes over
                new Delicat_Clients();
                break;
            case 'send_money':   new Delicat_Send_Money(); break;
            case 'guard':        new Delicat_Guard(); break;
            case 'watchdog':     new Delicat_Watchdog(); break;
            case 'alerts':       new Delicat_Alerts(); break;
            case 'rest':         new Delicat_Rest(); break;
            case 'app_api':      new Delicat_App_Api(); break;
            case 'natcash_auto': new Delicat_Natcash_Auto(); break;
            case 'sms_guard':
                Delicat_SMS_Guard::init();
                if ( did_action( 'init' ) ) {
                    Delicat_SMS_Guard::maybe_migrate(); // built after init (REST): run its one-time switch now
                }
                break;
            case 'license':      new Delicat_License(); break;
            case 'client_ux':    new Delicat_Client_Ux(); break;
            case 'workflow':     new Delicat_Workflow(); break;
            case 'growth':       new Delicat_Growth(); break;
            case 'api_sync':     Delicat_Recharge_Api_Sync::init(); break;
            case 'code_lookup':  Delicat_Code_Lookup::init(); break; // 6.5.0: read-only TransCode search (admin)
            case 'maintenance':  Delicat_Maintenance::init(); break; // 6.6.4: bonus-marker clean-up (daily)
            case 'sms_store':    Delicat_SMS_Store::init(); break;   // 6.6.5: SMS retention (hourly purge) + its setting
        }
    }
}

/**
 * V10: wp-admin (admin-ajax.php and admin-post.php included), wp-cron.php and WP-CLI build every
 * component, exactly as 6.7.0 did on every request.
 */
function delicat_recharge_full_request(): bool {
    return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
}

/** V10: REST API parts, built when the REST server starts (their routes register at rest_api_init 10 as before). */
function delicat_recharge_boot_rest(): void {
    delicat_recharge_boot( [ 'guard', 'rest', 'app_api', 'natcash_auto', 'sms_guard', 'license' ] );
}

/**
 * V10: approvals happen in admin-ajax.php and REST requests, where these listeners are already
 * built. Should one ever happen on another storefront request, the listeners (priorities 5 and 20)
 * are built first, so they still run in this same call.
 */
function delicat_recharge_boot_approval_listeners(): void {
    delicat_recharge_boot( [ 'guard', 'natcash_auto' ] );
}

/** V10: with ALTERNATE_WP_CRON a storefront request can turn into the cron run: build everything first. */
function delicat_recharge_boot_cron( $pre ) {
    if ( wp_doing_cron() ) {
        delicat_recharge_boot( delicat_recharge_parts() );
    }
    return $pre;
}

/** V10: the login ban on storefront requests (wp-login.php, My Account, XML-RPC): same filter, priority 30. */
function delicat_recharge_login_ban( $user ) {
    return ( new Delicat_Clients( false ) )->enforce_login_ban( $user );
}

/** V10: [delicat_envoyer_argent] on storefront requests; the class loads only when the shortcode renders. */
function delicat_recharge_send_money_shortcode(): string {
    return ( new Delicat_Send_Money() )->render();
}

register_activation_hook( __FILE__, function () {
    Delicat_Database::create_table();
    Delicat_Send_Money::create_table();
    Delicat_SMS_Store::create_table();
} );

// Self-healing: ensure tables exist even if the plugin was updated
// by overwriting files (which skips the activation hook).
add_action( 'init', function () {
    if ( get_option( 'delicat_db_version' ) !== DELICAT_VERSION ) {
        Delicat_Database::create_table();
        Delicat_Send_Money::create_table();
        // 6.6.5: SMS table (encrypted bodies, 24 h minimum then deleted); the
        // pre-6.6.5 option log moves in (expired entries are dropped) and goes.
        Delicat_SMS_Store::create_table();
        Delicat_SMS_Store::migrate_legacy_log();
        // 6.5.2: secrets only the API calls read (FCM service account ~2 KB,
        // MonCash and webhook secrets) no longer ride along on every page load.
        if ( function_exists( 'wp_set_option_autoload_values' ) ) {
            wp_set_option_autoload_values( array_fill_keys( [
                'delicat_fcm_service_account',
                'delicat_moncash_secret',
                'delicat_webhook_secret',
            ], false ) );
        }
        // 6.6.5: the retired Natcash PIN flow left old « kontni » codes in clear in
        // nc_pin; nothing reads them any more. Blank them once (the column stays).
        $GLOBALS['wpdb']->query( "UPDATE {$GLOBALS['wpdb']->prefix}delicat_recharges SET nc_pin = '' WHERE nc_pin <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant SQL.
        update_option( 'delicat_db_version', DELICAT_VERSION );
    }
}, 1 );

add_action( 'plugins_loaded', function () {
    if ( delicat_recharge_full_request() ) {
        delicat_recharge_boot( delicat_recharge_parts() );
        return;
    }
    // V10: storefront requests (pages, wp-login.php, wc-ajax, REST, XML-RPC) build only what can run
    // there. The schedules are still checked on every admin, admin-ajax and cron request.
    delicat_recharge_boot( [ 'shortcode', 'client_ux', 'growth', 'api_sync' ] );
    add_filter( 'authenticate', 'delicat_recharge_login_ban', 30 );
    add_shortcode( 'delicat_envoyer_argent', 'delicat_recharge_send_money_shortcode' );
    add_action( 'rest_api_init', 'delicat_recharge_boot_rest', 0 );
    add_action( 'delicat_recharge_approved', 'delicat_recharge_boot_approval_listeners', -1000, 0 );
    add_filter( 'pre_get_ready_cron_jobs', 'delicat_recharge_boot_cron' );
} );

// Clear scheduled cron jobs when the plugin is deactivated.
register_deactivation_hook( __FILE__, function () {
    if ( class_exists( 'Delicat_Alerts' ) ) {
        Delicat_Alerts::clear_schedules();
    }
    if ( class_exists( 'Delicat_Maintenance' ) ) {
        Delicat_Maintenance::clear_schedules();
    }
    if ( class_exists( 'Delicat_SMS_Store' ) ) {
        Delicat_SMS_Store::clear_schedules();
    }
    if ( class_exists( 'Delicat_Watchdog' ) ) {
        Delicat_Watchdog::deactivate(); // 6.7.0: its hourly check was left behind
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
 *
 * 6.8.0: same rule as 6.7.0. The design loads on EVERY account section except the skip list
 * below. A first 6.8.0 build used an allow-list (wallet tab only). That removed form.css, form.js
 * and the glass override from live sections that no plugin in this repository registers:
 * revendeur (Delicat Resellers), exchanges (« Mes échanges »), app-security, my-data, delete-account
 * and api-revendeur. form.css also holds site-wide rules: the account menu hover, and chat bubbles
 * lifted above the bottom bar on phones. So the skip list is back, and the only sections added to
 * the 6.7.0 list are Identity's connected-accounts and the Gateway's reseller-api.
 *
 * Filters (arrays of endpoint slugs, '' = the account home):
 * - delicat_recharge_wallet_ui_skip_endpoints (6.5.1, unchanged): sections that never get the
 *   design. It runs last and has the final word: a section it adds never loads the design, and a
 *   section it removes does.
 * - delicat_recharge_wallet_ui_endpoints (6.8.0, kept): sections that must get the design. It
 *   receives the same default as in the allow-list build: TeraWallet's wallet tabs (the default
 *   slugs and the ones set in WooCommerce) and, without Identity, the home. A section it returns
 *   is taken off the default skip list before the skip filter runs, so adding one here works
 *   (even 'orders') unless the skip filter lists it again. Taking a section out of this list no
 *   longer hides the design, because every section that is not skipped loads it. To hide it, add
 *   the section to the skip filter.
 */
function delicat_recharge_account_needs_wallet_ui(): bool {
    static $memo = null;
    if ( null !== $memo ) {
        return $memo;
    }
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
    // TeraWallet's wallet tab and its transactions (default slugs and the ones set in WooCommerce).
    $allow = array( 'woo-wallet', 'woo-wallet-transactions', 'my-wallet', 'wallet-transactions' );
    foreach ( array( 'woocommerce_woo_wallet_endpoint', 'woocommerce_woo_wallet_transactions_endpoint' ) as $option ) {
        $slug = (string) get_option( $option, '' );
        if ( '' !== $slug ) {
            $allow[] = $slug;
        }
    }
    // The 6.7.0 skip list, plus Identity's connected-accounts and the Gateway's reseller-api.
    $skip = array( 'orders', 'view-order', 'downloads', 'edit-address', 'edit-account', 'payment-methods', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method', 'change-password', 'identity-center', 'security-sessions', 'customer-logout', 'lost-password', 'connected-accounts', 'reseller-api' );
    /* The account home is Delicat Identity's own screen when it is active; without it, a
     * legacy dashboard may still carry the wallet card, so the design keeps loading there. */
    if ( class_exists( 'DIP_My_Account_Modern' ) ) {
        $skip[] = '';
    } else {
        $allow[] = '';
    }
    // Sections that must get the design leave the default skip list; the skip filter decides last.
    $allow = array_filter( (array) apply_filters( 'delicat_recharge_wallet_ui_endpoints', $allow ), 'is_string' );
    $skip  = (array) apply_filters( 'delicat_recharge_wallet_ui_skip_endpoints', array_values( array_diff( $skip, $allow ) ) );
    $needs = ! in_array( $endpoint, $skip, true );
    if ( did_action( 'wp' ) ) {
        $memo = $needs; // asked up to three times per page (styles, script, head override)
    }
    return $needs;
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
        wp_enqueue_style( 'delicat-recharge', delicat_recharge_asset_url( 'assets/css/form.css' ), [], defined( 'DELICAT_ASSET_VERSION' ) ? DELICAT_ASSET_VERSION : DELICAT_VERSION );
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
        --delicat-violet:#2563eb!important;
        --delicat-pink:#1d4ed8!important;
        --delicat-orange:#ff9f5a!important;
        --delicat-glass:rgba(255,255,255,.76)!important;
        --delicat-line:rgba(100,112,220,.18)!important;
        --delicat-shadow:0 24px 70px rgba(35,57,145,.18)!important;
    }
    .dmc{max-width:1000px!important;margin:0 auto!important;position:relative!important;isolation:isolate!important;}
    .dmc:before,.dsm:before,.dr-wrap:before{
        content:""!important;position:absolute!important;inset:-34px!important;border-radius:36px!important;z-index:-2!important;pointer-events:none!important;
        background:radial-gradient(circle at 6% 12%,rgba(99,211,255,.34) 0 9%,transparent 20%),radial-gradient(circle at 92% 14%,rgba(29,78,216,.24) 0 11%,transparent 24%),radial-gradient(circle at 72% 94%,rgba(37,99,235,.2) 0 12%,transparent 25%),linear-gradient(135deg,#f8fbff 0%,#fff 42%,#f7f1ff 100%)!important;
    }
    .dmc-hero{
        border-radius:32px!important;padding:30px!important;color:var(--delicat-ink)!important;overflow:hidden!important;position:relative!important;
        background:radial-gradient(circle at 8% 16%,rgba(105,213,255,.42),transparent 28%),radial-gradient(circle at 86% 18%,rgba(29,78,216,.32),transparent 30%),radial-gradient(circle at 66% 100%,rgba(37,99,235,.24),transparent 35%),linear-gradient(135deg,rgba(255,255,255,.9),rgba(242,248,255,.7))!important;
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
    .dsm-codecard{border-radius:26px!important;background:linear-gradient(135deg,#287dff 0%,#2563eb 48%,#1d4ed8 78%,#ff9a5b 100%)!important;color:#fff!important;box-shadow:0 20px 48px rgba(37,99,235,.28),inset 0 1px 0 rgba(255,255,255,.34)!important;position:relative!important;overflow:hidden!important;}
    .dsm-codecard:after{content:""!important;position:absolute!important;right:-34px!important;top:-42px!important;width:145px!important;height:145px!important;border-radius:50%!important;background:rgba(255,255,255,.18)!important;box-shadow:-180px 135px 0 rgba(255,255,255,.1)!important;}
    .dsm-codecard>*{position:relative!important;z-index:1!important;}
    .dsm-input{border-radius:17px!important;background:rgba(255,255,255,.82)!important;border:1px solid rgba(107,122,180,.18)!important;}
    @media(max-width:720px){.dmc-hero{padding:22px!important;border-radius:28px!important}.dmc-actions{display:grid!important;grid-template-columns:1fr!important;width:100%!important}.dmc-bamount,.dmc-bamount *{font-size:clamp(2.2rem,10vw,3.4rem)!important}}
    </style>
    <?php
}, 1000000 );
