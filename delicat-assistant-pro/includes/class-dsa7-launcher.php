<?php
/**
 * Delicat Assistant 7 — storefront kernel.
 *
 * This is the only assistant code an ordinary page view runs: one launcher button, about
 * 1 KB of CSS and 2 KB of boot script printed in the footer. Nothing user-specific is
 * printed (no nonce, no user id), so a full-page cache can serve the same HTML to
 * everyone. The chat app (assets/app/app.js, its styles and icons bundled in) is fetched on
 * the first tap and everything personal comes from dsa7_boot.
 *
 * Two light AJAX actions live here so they never load the full engine:
 *  - dsa7_ping: read-only status of the visitor's own thread (never creates one).
 *  - dsa7_boot: loads the engine once per chat opening and returns nonces + settings.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class DSA7_Launcher {

    private static $rendered = false;

    public static function init() {
        add_action( 'wp_footer', array( __CLASS__, 'render' ), 30 );
        add_action( 'wp_ajax_dsa7_ping', array( __CLASS__, 'ajax_ping' ) );
        add_action( 'wp_ajax_nopriv_dsa7_ping', array( __CLASS__, 'ajax_ping' ) );
        add_action( 'wp_ajax_dsa7_boot', array( __CLASS__, 'ajax_boot' ) );
        add_action( 'wp_ajax_nopriv_dsa7_boot', array( __CLASS__, 'ajax_boot' ) );
    }

    public static function settings() {
        return wp_parse_args( (array) get_option( 'dap_settings', array() ), array(
            'enabled'                    => 1,
            'auto_open'                  => 0,
            'brand_name'                 => 'Delicat Assistant',
            'launcher_side'              => 'right',
            'launcher_bottom'            => 16,
            'launcher_label_enabled'     => 1,
            'launcher_kicker'            => 'Besoin d’aide ?',
            'launcher_message'           => 'Discutez avec nous !',
            'launcher_popup_delay'       => 10,
            'launcher_popup_visible'     => 6,
            'launcher_popup_max'         => 3,
            'launcher_suppress_checkout' => 1,
            'font_scale'                 => 100,
            'force_reduced_motion'       => 0,
        ) );
    }

    /* --------------------------------------------------------------- assets */

    /** Content-hash version, so an edited file always gets a new URL past every cache. */
    public static function asset_url( $relative ) {
        static $versions = null;
        if ( null === $versions ) {
            $file     = DSA2_DIR . 'assets/app/versions.php';
            $versions = is_file( $file ) ? (array) include $file : array();
        }
        $ver = isset( $versions[ $relative ] ) ? $versions[ $relative ] : DSA2_VERSION;
        return add_query_arg( 'ver', rawurlencode( $ver ), DSA2_URL . 'assets/app/' . $relative );
    }

    private static function inline_asset( $relative ) {
        $path = DSA2_DIR . 'assets/app/' . $relative;
        return is_file( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    /* -------------------------------------------------------------- context */

    /** French by default, as in every earlier version; the customer's own choice is kept in the browser. */
    public static function language() {
        $lang = (string) apply_filters( 'dsa7_default_language', 'fr' );
        return in_array( $lang, array( 'fr', 'en', 'ht' ), true ) ? $lang : 'fr';
    }

    /** Only what is cheap and identical for every visitor of this URL. */
    private static function page_context() {
        $type = 'page';
        $id   = 0;
        if ( function_exists( 'is_product' ) && is_product() ) {
            $type = 'product';
            $id   = (int) get_queried_object_id();
        } elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
            $type = 'checkout';
        } elseif ( function_exists( 'is_cart' ) && is_cart() ) {
            $type = 'cart';
        } elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
            $type = 'account';
        } elseif ( is_front_page() ) {
            $type = 'home';
        } elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
            $type = 'category';
        }
        $title = is_singular() ? (string) get_the_title( (int) get_queried_object_id() ) : '';
        return array(
            'page_type'  => $type,
            'product_id' => $id,
            'title'      => sanitize_text_field( wp_strip_all_tags( $title ) ),
        );
    }

    public static function config( $s = null ) {
        $s     = $s ? $s : self::settings();
        $quiet = ! empty( $s['launcher_suppress_checkout'] ) && (
            ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() )
        );
        return array(
            'v'      => DSA2_VERSION,
            'ajax'   => admin_url( 'admin-ajax.php' ),
            'js'     => self::asset_url( 'app.js' ),
            'lang'   => self::language(),
            'brand'  => sanitize_text_field( (string) $s['brand_name'] ),
            'side'   => 'left' === $s['launcher_side'] ? 'left' : 'right',
            'bottom' => max( 0, min( 400, (int) $s['launcher_bottom'] ) ),
            'nudge'  => ( ! empty( $s['launcher_label_enabled'] ) && ! $quiet ) ? array(
                'kicker'  => sanitize_text_field( (string) $s['launcher_kicker'] ),
                'text'    => sanitize_text_field( (string) $s['launcher_message'] ),
                'delay'   => max( 3, min( 120, (int) $s['launcher_popup_delay'] ) ),
                'visible' => max( 3, min( 15, (int) $s['launcher_popup_visible'] ) ),
                'max'     => max( 0, min( 10, (int) $s['launcher_popup_max'] ) ),
            ) : null,
            'auto'   => ( ! empty( $s['auto_open'] ) && ! $quiet ) ? 1 : 0,
            'scale'  => max( 90, min( 125, (int) $s['font_scale'] ) ),
            'still'  => ! empty( $s['force_reduced_motion'] ) ? 1 : 0,
            'page'   => self::page_context(),
        );
    }

    /* --------------------------------------------------------------- output */

    public static function render() {
        if ( self::$rendered || is_admin() || is_feed() || is_embed() || wp_doing_ajax() ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
        $s = self::settings();
        if ( empty( $s['enabled'] ) ) return;
        self::$rendered = true;
        $config = self::config( $s );
        $json   = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $label  = 'en' === $config['lang'] ? 'Open Delicat support chat' : ( 'ht' === $config['lang'] ? 'Louvri chat sipò Delicat' : 'Ouvrir l’assistance Delicat' );
        $close  = 'en' === $config['lang'] ? 'Close' : ( 'ht' === $config['lang'] ? 'Fèmen' : 'Fermer' );
        // Material Symbols Rounded "chat" (400, 24 px grid), same family as the Builder icons.
        $icon   = '<svg viewBox="0 -960 960 960" aria-hidden="true" focusable="false"><path d="M240-400h320v-80H240v80Zm0-120h480v-80H240v80Zm0-120h480v-80H240v80ZM80-80v-720q0-33 23.5-56.5T160-880h640q33 0 56.5 23.5T880-800v480q0 33-23.5 56.5T800-240H240L80-80Z"/></svg>';
        echo "\n" . '<div id="dsa7-root" class="dsa7-root" data-side="' . esc_attr( $config['side'] ) . '" data-delicat-keep style="--dsa7-offset:' . (int) $config['bottom'] . 'px">';
        echo '<div class="dsa7-nudge" role="status" hidden><button type="button" class="dsa7-nudge__open"><b></b><span></span></button><button type="button" class="dsa7-nudge__x" aria-label="' . esc_attr( $close ) . '">&times;</button></div>';
        echo '<button type="button" id="dsa7-launcher" class="dsa7-launcher" aria-label="' . esc_attr( $label ) . '" aria-haspopup="dialog" aria-expanded="false">' . $icon . '<span class="dsa7-badge" hidden></span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
        echo '<style id="dsa7-launcher-css">' . self::inline_asset( 'launcher.min.css' ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<script id="dsa7-boot" data-no-optimize="1" data-cfasync="false">window.DSA7_CONFIG=' . $json . ';' . self::inline_asset( 'boot.min.js' ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * [delicat_assistant] and [delicat_groups]: an inline box on the page. The app mounts
     * into it on the first visit of the page (no tap needed), with the requested view.
     */
    public static function shortcode( $atts = array(), $content = '', $tag = 'delicat_assistant' ) {
        $atts   = shortcode_atts( array( 'height' => '640', 'group' => '', 'view' => '' ), (array) $atts, $tag );
        $view   = 'delicat_groups' === $tag ? 'community' : sanitize_key( $atts['view'] );
        $height = max( 380, min( 1200, (int) $atts['height'] ) );
        $loading = 'delicat_groups' === $tag ? __( 'Chargement des groupes…', 'delicat-assistant' ) : __( 'Chargement de l’assistance…', 'delicat-assistant' );
        return '<div class="dsa7-inline" data-dsa7-inline data-view="' . esc_attr( $view ) . '" data-group="' . esc_attr( sanitize_title( $atts['group'] ) ) .
            '" style="min-height:' . (int) $height . 'px;--dsa7-inline-h:' . (int) $height . 'px"><p class="dsa7-inline__wait">' . esc_html( $loading ) . '</p></div>';
    }

    /* ----------------------------------------------------------------- AJAX */

    private static function no_cache() {
        nocache_headers();
        header( 'Vary: Cookie', false );
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
        do_action( 'litespeed_control_set_nocache', 'delicat assistant private data' );
    }

    /** The visitor's own thread, read-only: never creates a row or a cookie. */
    public static function find_conversation() {
        global $wpdb;
        $table = $wpdb->prefix . 'dap_conversations';
        $cols  = 'id,status,last_customer_read_id,last_agent_read_id';
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT $cols FROM $table WHERE session_key=%s", 'user_' . $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( ! $row ) {
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT $cols FROM $table WHERE user_id=%d ORDER BY last_message_at DESC LIMIT 1", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
            return $row;
        }
        $cookie = isset( $_COOKIE['dap_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['dap_session'] ) ) : '';
        if ( ! preg_match( '/^[a-zA-Z0-9_-]{20,100}$/', $cookie ) ) return null;
        return $wpdb->get_row( $wpdb->prepare( "SELECT $cols FROM $table WHERE session_key=%s", 'guest_' . $cookie ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Heartbeat of an open chat and the launcher badge. Two indexed reads. No nonce: it is
     * read-only and scoped to the caller's own cookie, which a cross-site request does not
     * carry (SameSite=Lax) and whose answer it could not read (no CORS on admin-ajax).
     * Rate limited only where a persistent object cache makes counting free; without one,
     * a counter would cost two wp_options writes per heartbeat.
     */
    public static function ajax_ping() {
        self::no_cache();
        if ( wp_using_ext_object_cache() ) {
            $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
            $key = 'dsa7_ping_' . md5( $ip ) . '_' . (int) floor( time() / 60 );
            wp_cache_add( $key, 0, 'dsa7', 120 );
            if ( (int) wp_cache_incr( $key, 1, 'dsa7' ) > 900 ) wp_send_json_error( array( 'code' => 'rate_limited' ), 429 );
        }
        $conv = self::find_conversation();
        if ( ! $conv ) {
            wp_send_json_success( array( 'last_id' => 0, 'unread' => 0, 'status' => '', 'agent_read_id' => 0, 'agent_typing' => false ) );
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT MAX(id) AS last_id, SUM(CASE WHEN channel='agent' AND sender='agent' AND id>%d THEN 1 ELSE 0 END) AS unread FROM {$wpdb->prefix}dap_messages WHERE conversation_id=%d",
            (int) $conv->last_customer_read_id,
            (int) $conv->id
        ) );
        wp_send_json_success( array(
            'last_id'       => $row ? (int) $row->last_id : 0,
            'unread'        => $row ? (int) $row->unread : 0,
            'status'        => (string) $conv->status,
            'agent_read_id' => (int) $conv->last_agent_read_id,
            'agent_typing'  => (bool) get_transient( 'dap_agent_typing_' . (int) $conv->id ),
        ) );
    }

    /** Once per chat opening: nonces, the visitor's identity and the live settings. */
    public static function ajax_boot() {
        self::no_cache();
        dsa2_load_full_engine();
        // Two limits: per address (generous, carrier-NAT puts many customers behind one) and per visitor (address
        // + account or guest cookie), so one client cannot use up the address's budget; a rotated cookie still
        // counts against the address. Both are checked before anything is written.
        $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $who = get_current_user_id() ? 'u' . get_current_user_id() : ( isset( $_COOKIE['dap_session'] ) ? 'g' . substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_COOKIE['dap_session'] ) ), 0, 100 ) : 'n' );
        $limits = array(
            'dsa7_boot_' . md5( $ip )               => 120,
            'dsa7_boot_' . md5( $ip . '|' . $who ) => 30,
        );
        $windows = array();
        foreach ( $limits as $key => $max ) {
            $hits = get_transient( $key );
            $hits = is_array( $hits ) && isset( $hits['t'] ) && time() - (int) $hits['t'] < MINUTE_IN_SECONDS ? $hits : array( 't' => time(), 'c' => 0 );
            if ( (int) $hits['c'] >= $max ) wp_send_json_error( array( 'code' => 'rate_limited' ), 429 );
            $windows[ $key ] = $hits;
        }
        foreach ( $windows as $key => $hits ) {
            $hits['c'] = (int) $hits['c'] + 1;
            set_transient( $key, $hits, 2 * MINUTE_IN_SECONDS );
        }

        $s       = wp_parse_args( (array) get_option( 'dap_settings', array() ), array( 'whatsapp' => '', 'show_whatsapp' => 1, 'business_hours' => '', 'order_support_enabled' => 1, 'brand_name' => 'Delicat Assistant' ) );
        $user    = wp_get_current_user();
        $user_id = (int) $user->ID;
        $status  = DSA2_Data::effective_status();
        $wa      = preg_replace( '/\D+/', '', (string) $s['whatsapp'] );
        $conv    = self::find_conversation();
        $account = DSA2_Data::account_url();

        $groups = array( 'enabled' => 0 );
        if ( class_exists( 'DSA2_Groups', false ) && DSA2_Groups::enabled() ) {
            $g = DSA2_Groups::settings();
            $groups = array(
                'enabled'     => 1,
                'nonce'       => $user_id ? wp_create_nonce( DSA2_Groups::NONCE ) : '',
                'displayName' => $user_id ? DSA2_Groups::display_name( $user_id ) : '',
                'isStaff'     => $user_id ? DSA2_Groups::is_staff( $user_id ) : false,
                'canModerate' => $user_id ? DSA2_Groups::can_moderate( $user_id ) : false,
                'banned'      => $user_id ? DSA2_Groups::is_banned( $user_id ) : false,
                'images'      => ! empty( $g['groups_images'] ) ? 1 : 0,
                'maxLength'   => max( 40, min( 2000, (int) $g['groups_max_length'] ) ),
                'rules'       => array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $g['groups_rules'] ) ) ) ),
            );
        }

        wp_send_json_success( array(
            'nonce'    => wp_create_nonce( 'dsa2_frontend' ),
            'brand'    => sanitize_text_field( (string) $s['brand_name'] ),
            'user'     => array(
                'loggedIn' => $user_id > 0,
                'name'     => $user_id ? sanitize_text_field( $user->first_name ? $user->first_name : $user->display_name ) : '',
            ),
            'status'   => $status,
            'eta'      => DSA2_Data::agent_eta( $status ),
            'hours'    => sanitize_text_field( (string) $s['business_hours'] ),
            'whatsapp' => ( $wa && ! empty( $s['show_whatsapp'] ) ) ? 'https://wa.me/' . $wa : '',
            'orders'   => ! empty( $s['order_support_enabled'] ) ? 1 : 0,
            'unread'   => $conv ? DSA2_Data::unread_count( (int) $conv->id ) : 0,
            // Whether a thread exists: the app only loads history when there is one, so opening
            // the chat never creates an empty conversation row.
            'thread'   => $conv ? 1 : 0,
            'urls'     => array(
                'wallet'  => DSA2_Data::store_url( 'wallet' ),
                'redeem'  => DSA2_Data::store_url( 'redeem' ),
                'rewards' => DSA2_Data::store_url( 'rewards' ),
                'shop'    => DSA2_Data::store_url( 'shop' ),
                'account' => $account,
                'login'   => $account ? $account : wp_login_url( home_url( '/' ) ),
                'home'    => home_url( '/' ),
            ),
            'groups'   => $groups,
        ) );
    }
}
