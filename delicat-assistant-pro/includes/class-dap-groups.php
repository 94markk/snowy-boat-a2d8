<?php
/**
 * Delicat Community Groups — client-to-client group chat.
 *
 * Threat model this module is written against, in a game top-up business:
 *  1. A stranger poses as "Delicat Support" in the group and asks a customer to send
 *     MonCash/NatCash to a personal number. Countermeasure: staff badges are rendered from
 *     the WordPress role server-side and can never be typed by a member; display names that
 *     claim staff identity are rejected; payment numbers are masked out of every message.
 *  2. PIN / gift-card codes get pasted into a public room and are burned by the first reader.
 *     Countermeasure: code-shaped strings are masked before the row is written.
 *  3. Phishing links to a fake delicastoreha.com. Countermeasure: external links are removed
 *     unless the store explicitly allows them; the store's own host is always allowed.
 *  4. Guests / bots flooding the room. Countermeasure: login required (no *_nopriv actions),
 *     nonce on every call, per-user fixed-window rate limit, per-group slow mode,
 *     duplicate-message rejection.
 *  5. Harassment. Countermeasure: report button with distinct-reporter threshold and
 *     automatic hiding, plus mute / ban / delete / lock for support staff.
 *
 * Everything the customer sends is stored already masked. Nothing is trusted on render:
 * the client escapes, the server sanitises.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DSA2_Groups {

    const NONCE      = 'dsa2_groups';
    const ROLE_AGENT = 'agent';
    const ROLE_MOD   = 'moderator';
    const ROLE_MEMBER= 'member';

    /* ---------------------------------------------------------------- tables */

    public static function table( $which = 'groups' ) {
        global $wpdb;
        $map = array(
            'groups'   => $wpdb->prefix . 'dap_groups',
            'members'  => $wpdb->prefix . 'dap_group_members',
            'messages' => $wpdb->prefix . 'dap_group_messages',
            'reports'  => $wpdb->prefix . 'dap_group_reports',
        );
        return isset( $map[ $which ] ) ? $map[ $which ] : $map['groups'];
    }

    /* -------------------------------------------------------------- settings */

    public static function settings() {
        $s = (array) get_option( 'dap_settings', array() );
        return wp_parse_args( $s, array(
            'groups_enabled'        => 1,
            'groups_images'         => 0,
            'groups_links'          => 0,
            'groups_slow_mode'      => 5,
            'groups_max_length'     => 700,
            'groups_history'        => 60,
            'groups_report_hide'    => 3,
            'groups_retention_days' => 120,
            'groups_blocklist'      => '',
            'groups_rules'          => "Aucun numéro MonCash / NatCash dans le groupe.\nAucun code PIN ni code cadeau.\nL’équipe Delicat ne demande jamais un paiement en message privé.\nRespect obligatoire : pas d’insultes, pas de spam.",
        ) );
    }

    public static function enabled() {
        $s = self::settings();
        return ! empty( $s['groups_enabled'] );
    }

    /* ------------------------------------------------------------------ init */

    public static function init() {
        add_shortcode( 'delicat_groups', array( __CLASS__, 'shortcode' ) );
        if ( ! self::enabled() ) return;

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_widget' ), 20 );
        add_filter( 'style_loader_tag', array( __CLASS__, 'asset_tag' ), 10, 2 );
        add_filter( 'script_loader_tag', array( __CLASS__, 'asset_tag' ), 10, 2 );

        // Members only. There is deliberately no wp_ajax_nopriv_* twin for any of these:
        // an unauthenticated request must not be able to read or write a single row.
        foreach ( array(
            'list'     => 'ajax_list',
            'open'     => 'ajax_open',
            'poll'     => 'ajax_poll',
            'send'     => 'ajax_send',
            'join'     => 'ajax_join',
            'leave'    => 'ajax_leave',
            'read'     => 'ajax_read',
            'report'   => 'ajax_report',
            'profile'  => 'ajax_profile',
            'upload'   => 'ajax_upload',
            'moderate' => 'ajax_moderate',
        ) as $slug => $method ) {
            add_action( 'wp_ajax_dsa2_groups_' . $slug, array( __CLASS__, $method ) );
        }
    }

    /* ---------------------------------------------------------------- assets */

    private static function asset_version( $relative ) {
        $path = DSA2_DIR . $relative;
        return file_exists( $path ) ? DSA2_VERSION . '.' . filemtime( $path ) : DSA2_VERSION;
    }

    public static function register_assets() {
        wp_register_style( 'dap-groups', DSA2_URL . 'assets/css/groups.css', array(), self::asset_version( 'assets/css/groups.css' ) );
        wp_register_script( 'dap-groups', DSA2_URL . 'assets/js/groups.js', array(), self::asset_version( 'assets/js/groups.js' ), true );
    }

    /** Nothing is printed for logged-out visitors, so guest pages stay exactly as fast as before. */
    public static function enqueue() {
        static $done = false;
        if ( $done || ! is_user_logged_in() || ! self::enabled() ) return;
        $done = true;
        if ( ! wp_style_is( 'dap-groups', 'registered' ) ) self::register_assets();
        wp_enqueue_style( 'dap-groups' );
        wp_enqueue_script( 'dap-groups' );
        wp_localize_script( 'dap-groups', 'DAP_GROUPS', self::config() );
    }

    /** Off-limits to JS/CSS combining, exactly like the main widget bundle. */
    public static function asset_tag( $tag, $handle ) {
        if ( 'dap-groups' !== $handle || false !== strpos( $tag, 'data-no-optimize' ) ) return $tag;
        return preg_replace( '/<(script|link)\s/', '<$1 data-no-optimize="1" data-no-defer="1" data-cfasync="false" ', $tag, 1 );
    }

    public static function maybe_enqueue_widget() {
        if ( is_admin() || ! is_user_logged_in() ) return;
        $s = (array) get_option( 'dap_settings', array() );
        if ( empty( $s['enabled'] ) ) return; // widget itself is off
        self::enqueue();
    }

    public static function config() {
        $s = self::settings();
        $user = wp_get_current_user();
        return array(
            'ajax'      => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( self::NONCE ),
            'userId'    => (int) $user->ID,
            'canModerate' => self::can_moderate(),
            'maxLength' => max( 40, min( 2000, (int) $s['groups_max_length'] ) ),
            'images'    => ! empty( $s['groups_images'] ) ? 1 : 0,
            'links'     => ! empty( $s['groups_links'] ) ? 1 : 0,
            'rules'     => array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $s['groups_rules'] ) ) ) ),
            'displayName' => self::display_name( (int) $user->ID ),
            'banned'    => self::is_banned( (int) $user->ID ),
        );
    }

    /* ------------------------------------------------------------- shortcode */

    public static function shortcode( $atts = array() ) {
        if ( ! self::enabled() ) return '';
        if ( ! is_user_logged_in() ) {
            $login = wp_login_url( get_permalink() ?: home_url( '/' ) );
            return '<div class="dapg-standalone dapg-locked"><div class="dapg-locked-card"><span aria-hidden="true">🔒</span><strong>' .
                esc_html__( 'Communauté Delicat', 'delicat-assistant' ) . '</strong><p>' .
                esc_html__( 'Connectez-vous à votre compte pour rejoindre les groupes.', 'delicat-assistant' ) .
                '</p><a class="dapg-btn" href="' . esc_url( $login ) . '">' . esc_html__( 'Se connecter', 'delicat-assistant' ) . '</a></div></div>';
        }
        self::enqueue();
        $atts = shortcode_atts( array( 'height' => '640', 'group' => '' ), $atts, 'delicat_groups' );
        $height = max( 380, min( 1200, (int) $atts['height'] ) );
        return '<div class="dapg-standalone" data-dapg-standalone data-dapg-group="' . esc_attr( sanitize_title( $atts['group'] ) ) .
            '" style="--dapg-height:' . esc_attr( $height ) . 'px"><div class="dapg-boot">' .
            esc_html__( 'Chargement des groupes…', 'delicat-assistant' ) . '</div></div>';
    }

    /* ------------------------------------------------------------- identities */

    /**
     * DSA2_Install is only present on the full engine. A storefront page view loads the
     * light kernel, so the capability name is resolved defensively instead of through the
     * class constant — reading it directly there would be a fatal error.
     */
    private static function cap_support() {
        return class_exists( 'DSA2_Install', false ) ? DSA2_Install::CAP_SUPPORT : 'manage_delicat_assistant';
    }

    public static function can_moderate( $user_id = 0 ) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if ( ! $user_id ) return false;
        if ( $user_id === get_current_user_id() ) {
            return current_user_can( self::cap_support() ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
        }
        return user_can( $user_id, self::cap_support() ) || user_can( $user_id, 'manage_options' );
    }

    public static function is_staff( $user_id ) {
        $user_id = (int) $user_id;
        return $user_id > 0 && ( user_can( $user_id, self::cap_support() ) || user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_woocommerce' ) );
    }

    public static function is_banned( $user_id ) {
        return (int) get_user_meta( (int) $user_id, 'dap_groups_banned', true ) === 1;
    }

    /**
     * A pseudonym, never an e-mail or a phone number. Members may choose their own, but a
     * name that claims to be the store or its staff is refused — impersonation is the
     * single most profitable attack against a top-up community.
     */
    public static function display_name( $user_id ) {
        $user_id = (int) $user_id;
        if ( ! $user_id ) return __( 'Invité', 'delicat-assistant' );
        $custom = trim( (string) get_user_meta( $user_id, 'dap_group_name', true ) );
        if ( '' !== $custom ) return $custom;
        $user = get_userdata( $user_id );
        if ( ! $user ) return __( 'Client', 'delicat-assistant' );
        $first = trim( (string) $user->first_name );
        $last  = trim( (string) $user->last_name );
        if ( '' !== $first ) return $last ? $first . ' ' . DSA2_Compat::upper( DSA2_Compat::substr( $last, 0, 1 ) ) . '.' : $first;
        $nice = (string) $user->display_name;
        if ( '' === $nice || false !== strpos( $nice, '@' ) ) $nice = (string) $user->user_login;
        if ( false !== strpos( $nice, '@' ) ) $nice = DSA2_Compat::substr( $nice, 0, (int) strpos( $nice, '@' ) );
        $nice = preg_replace( '/[^\p{L}\p{N}\s._-]/u', '', $nice );
        $nice = trim( (string) $nice );
        return '' !== $nice ? DSA2_Compat::substr( $nice, 0, 24 ) : __( 'Client', 'delicat-assistant' );
    }

    /** Words a customer may not put in their group name. */
    private static function reserved_name( $name ) {
        $probe = self::fold( $name );
        foreach ( array( 'delicat', 'admin', 'support', 'officiel', 'official', 'moderateur', 'moderator', 'agent', 'staff', 'equipe delicat', 'service client' ) as $needle ) {
            if ( false !== strpos( $probe, $needle ) ) return true;
        }
        return false;
    }

    /** Lowercase, accent-free comparison string. */
    public static function fold( $value ) {
        $value = (string) $value;
        if ( function_exists( 'mb_strtolower' ) ) $value = mb_strtolower( $value, 'UTF-8' );
        else $value = strtolower( $value );
        if ( function_exists( 'iconv' ) ) {
            $plain = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );
            if ( false !== $plain && '' !== $plain ) $value = strtolower( $plain );
        }
        return preg_replace( '/[^a-z0-9\s]/', ' ', $value );
    }

    public static function initials( $name ) {
        $name = trim( (string) $name );
        if ( '' === $name ) return '?';
        $parts = preg_split( '/\s+/', $name );
        $out = DSA2_Compat::upper( DSA2_Compat::substr( $parts[0], 0, 1 ) );
        if ( count( $parts ) > 1 ) $out .= DSA2_Compat::upper( DSA2_Compat::substr( end( $parts ), 0, 1 ) );
        return $out;
    }

    /* ------------------------------------------------------------ safety net */

    /**
     * Rewrites a message into what is safe to store. Returns the cleaned body plus the
     * list of rules it tripped, which the moderation queue shows to staff.
     */
    public static function sanitize_body( $raw, $user_id = 0 ) {
        $s = self::settings();
        $flags = array();
        $body = wp_strip_all_tags( (string) $raw );
        $body = preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $body ); // invisible / bidi override
        $body = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $body ); // C0 controls: also stops placeholder forgery
        $body = preg_replace( "/\n{3,}/", "\n\n", (string) $body );
        $body = trim( (string) $body );

        // Links are lifted out first and put back last. Otherwise the digit rules below would
        // happily rewrite the middle of a legitimate product URL.
        $host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $allow_links = ! empty( $s['groups_links'] );
        $kept = array();
        $body = preg_replace_callback( '#\b(?:https?://|www\.)[^\s<>"\']+#i', function( $m ) use ( &$flags, &$kept, $host, $allow_links ) {
            $url = rtrim( $m[0], '.,;:)!?' );
            $probe = 0 === stripos( $url, 'www.' ) ? 'https://' . $url : $url;
            $url_host = strtolower( (string) wp_parse_url( $probe, PHP_URL_HOST ) );
            $own = $url_host && ( $url_host === $host || substr( $url_host, - ( strlen( $host ) + 1 ) ) === '.' . $host );
            if ( ! $own && ! $allow_links ) { $flags[] = 'link_blocked'; return '[lien retiré]'; }
            if ( ! $own ) $flags[] = 'link';
            $kept[] = $url;
            return "\x02" . str_repeat( 'L', count( $kept ) ) . "\x02";
        }, $body );

        // 1. E-mail addresses — nobody needs to publish one in a public room.
        $body = preg_replace_callback( '/[\w.+-]+@[\w-]+\.[\w.-]{2,}/u', function() use ( &$flags ) {
            $flags[] = 'email';
            return '[e-mail masqué]';
        }, $body );

        // 2. Payment numbers — without eating the Player IDs this store's customers trade all
        //    day. A Haitian mobile number is 8 digits (or 11 with the 509 prefix); a Free Fire
        //    or PUBG ID is 9 to 12. So a bare long run is left alone, and anything that looks
        //    like a phone — or any long run in a message that is talking about money — is masked.
        $money_talk = (bool) preg_match( '/moncash|natcash|whatsapp|whatsap|voye kob|voye lajan|peye|paye|payer|transfer|transf|envoy/i', self::fold( $body ) );
        $body = preg_replace_callback( '/(?:\+?\d[\s\-\.\(\)]{0,2}){7,}\d/u', function( $m ) use ( &$flags, $money_talk ) {
            $digits = preg_replace( '/\D+/', '', $m[0] );
            $len = strlen( (string) $digits );
            $is_phone = ( 8 === $len )
                || ( 11 === $len && 0 === strpos( $digits, '509' ) )
                || ( 10 === $len && 0 === strpos( $digits, '0' ) );
            if ( ! $is_phone && ! $money_talk ) return $m[0]; // player ID, order number, score
            $flags[] = 'phone';
            return '[numéro masqué]';
        }, $body );

        // 3. PIN / gift-card shapes. Hyphenated groups only: matching space-separated words
        //    turned an ordinary sentence containing a year into "[code masqué]".
        $body = preg_replace_callback( '/\b[A-Za-z0-9]{4,}(?:-[A-Za-z0-9]{4,})+\b/u', function( $m ) use ( &$flags ) {
            if ( ! preg_match( '/\d/', $m[0] ) ) return $m[0]; // porte-monnaie and friends
            $flags[] = 'code';
            return '[code masqué]';
        }, $body );
        // One long block mixing letters and digits: a raw voucher code. Pure digits are left
        // alone on purpose — that is a Player ID, handled by rule 2.
        $body = preg_replace_callback( '/\b(?=[A-Za-z0-9]*\d)(?=[A-Za-z0-9]*[A-Za-z])[A-Za-z0-9]{12,}\b/u', function() use ( &$flags ) {
            $flags[] = 'code';
            return '[code masqué]';
        }, $body );

        // 4. Links go back exactly as they were written.
        foreach ( $kept as $i => $url ) {
            $body = str_replace( "\x02" . str_repeat( 'L', $i + 1 ) . "\x02", $url, $body );
        }

        // 5. Store word list.
        $words = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n|,/', (string) $s['groups_blocklist'] ) ) );
        if ( $words ) {
            $probe = self::fold( $body );
            foreach ( $words as $word ) {
                $needle = trim( self::fold( $word ) );
                if ( '' === $needle ) continue;
                if ( false !== strpos( $probe, $needle ) ) {
                    $flags[] = 'blocklist';
                    $body = preg_replace( '/' . preg_quote( $word, '/' ) . '/iu', str_repeat( '●', max( 3, DSA2_Compat::length( $word ) ) ), $body );
                }
            }
        }

        // 6. Impersonation and payment solicitation get flagged for review, not blocked:
        //    "j'ai écrit au support" is a normal sentence and must still go through.
        $probe = self::fold( $body );
        if ( ! self::is_staff( $user_id ) ) {
            foreach ( array( 'je suis admin', 'je suis le support', 'support officiel', 'agent officiel', 'delicat officiel', 'mwen se admin' ) as $needle ) {
                if ( false !== strpos( $probe, $needle ) ) { $flags[] = 'impersonation'; break; }
            }
        }
        if ( $money_talk ) $flags[] = 'payment';

        $body = trim( preg_replace( '/[ \t]{2,}/', ' ', (string) $body ) );
        $max = max( 40, min( 2000, (int) $s['groups_max_length'] ) );
        if ( DSA2_Compat::length( $body ) > $max ) $body = DSA2_Compat::substr( $body, 0, $max );

        return array( 'body' => $body, 'flags' => array_values( array_unique( $flags ) ) );
    }

    /* ------------------------------------------------------------ rate limit */

    private static function bump( $key, $window ) {
        $d = get_transient( $key );
        if ( ! is_array( $d ) || ! isset( $d['t'] ) || ( time() - (int) $d['t'] ) > $window ) $d = array( 'c' => 0, 't' => time() );
        $d['c']++;
        set_transient( $key, $d, 2 * $window );
        return (int) $d['c'];
    }

    /** Every endpoint starts here: session, nonce, ban, flood. */
    private static function guard( $limit = 90 ) {
        nocache_headers();
        header( 'Vary: Cookie', false );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'code' => 'auth', 'message' => __( 'Connectez-vous pour utiliser les groupes.', 'delicat-assistant' ) ), 401 );
        }
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            wp_send_json_error( array( 'code' => 'bad_nonce', 'message' => __( 'Session expirée. Rechargez la page.', 'delicat-assistant' ) ), 403 );
        }
        if ( ! self::enabled() ) {
            wp_send_json_error( array( 'code' => 'disabled', 'message' => __( 'Les groupes sont désactivés.', 'delicat-assistant' ) ), 403 );
        }
        $user_id = get_current_user_id();
        if ( self::is_banned( $user_id ) ) {
            wp_send_json_error( array( 'code' => 'banned', 'message' => __( 'Votre accès aux groupes a été suspendu.', 'delicat-assistant' ) ), 403 );
        }
        if ( self::bump( 'dapg_rate_' . $user_id, MINUTE_IN_SECONDS ) > (int) $limit ) {
            wp_send_json_error( array( 'code' => 'rate_limited', 'message' => __( 'Trop de requêtes. Patientez une minute.', 'delicat-assistant' ) ), 429 );
        }
        return $user_id;
    }

    /* ----------------------------------------------------------------- reads */

    public static function get_group( $id_or_slug ) {
        global $wpdb;
        $table = self::table( 'groups' );
        if ( is_numeric( $id_or_slug ) ) {
            return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", absint( $id_or_slug ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE slug=%s", sanitize_title( (string) $id_or_slug ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function membership( $group_id, $user_id ) {
        global $wpdb;
        $table = self::table( 'members' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE group_id=%d AND user_id=%d", absint( $group_id ), absint( $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    private static function member_role( $group_id, $user_id ) {
        if ( self::is_staff( $user_id ) ) return self::ROLE_AGENT;
        $m = self::membership( $group_id, $user_id );
        return ( $m && self::ROLE_MOD === $m->role ) ? self::ROLE_MOD : self::ROLE_MEMBER;
    }

    /** Can this member post right now, and if not, why. */
    private static function post_state( $group, $user_id ) {
        if ( ! $group || 'active' !== $group->status ) return array( false, 'archived', __( 'Ce groupe est archivé.', 'delicat-assistant' ) );
        if ( (int) $group->locked && ! self::can_moderate() ) return array( false, 'locked', __( 'Le groupe est en lecture seule.', 'delicat-assistant' ) );
        $m = self::membership( $group->id, $user_id );
        if ( ! $m || 'active' !== $m->status ) {
            if ( $m && 'banned' === $m->status ) return array( false, 'banned', __( 'Vous ne pouvez plus écrire dans ce groupe.', 'delicat-assistant' ) );
            return array( false, 'join', __( 'Rejoignez le groupe pour écrire.', 'delicat-assistant' ) );
        }
        if ( $m->muted_until && strtotime( $m->muted_until . ' UTC' ) > time() ) {
            return array( false, 'muted', __( 'Vous êtes temporairement en sourdine.', 'delicat-assistant' ) );
        }
        return array( true, '', '' );
    }

    public static function group_payload( $group, $user_id ) {
        $m = self::membership( $group->id, $user_id );
        $unread = 0;
        if ( $m && 'active' === $m->status ) {
            global $wpdb;
            $table = self::table( 'messages' );
            $unread = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE group_id=%d AND id>%d AND status='visible' AND user_id<>%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (int) $group->id, (int) $m->last_read_id, (int) $user_id
            ) );
        }
        list( $can_post, $reason, $message ) = self::post_state( $group, $user_id );
        return array(
            'id'          => (int) $group->id,
            'slug'        => (string) $group->slug,
            'name'        => (string) $group->name,
            'description' => (string) $group->description,
            'icon'        => (string) $group->icon,
            'members'     => (int) $group->member_count,
            'locked'      => (int) $group->locked,
            'archived'    => 'active' !== $group->status ? 1 : 0,
            'slow'        => (int) $group->slow_mode,
            'joined'      => ( $m && 'active' === $m->status ) ? 1 : 0,
            'role'        => self::member_role( $group->id, $user_id ),
            'unread'      => min( 99, $unread ),
            'lastAt'      => $group->last_message_at ? (string) $group->last_message_at : '',
            'canPost'     => $can_post ? 1 : 0,
            'blockReason' => $reason,
            'blockText'   => $message,
        );
    }

    public static function format_messages( $rows, $user_id ) {
        $out = array();
        $can_moderate = self::can_moderate();
        foreach ( (array) $rows as $row ) {
            $meta = $row->meta ? json_decode( $row->meta, true ) : array();
            if ( ! is_array( $meta ) ) $meta = array();
            $hidden = 'visible' !== $row->status;
            $mine = ( (int) $row->user_id === (int) $user_id );
            $body = $row->body;
            if ( $hidden && ! $can_moderate ) $body = __( 'Message retiré par la modération.', 'delicat-assistant' );
            $author = isset( $meta['author'] ) ? (string) $meta['author'] : self::display_name( (int) $row->user_id );
            $out[] = array(
                'id'      => (int) $row->id,
                'groupId' => (int) $row->group_id,
                'userId'  => (int) $row->user_id,
                'author'  => $author,
                'initials'=> self::initials( $author ),
                'role'    => (string) $row->author_role,
                'type'    => (string) $row->message_type,
                'body'    => (string) $body,
                'url'     => isset( $meta['url'] ) ? esc_url_raw( (string) $meta['url'] ) : '',
                'time'    => mysql2date( 'H:i', $row->created_at ),
                'day'     => mysql2date( 'Y-m-d', $row->created_at ),
                'mine'    => $mine ? 1 : 0,
                'pinned'  => (int) $row->pinned,
                'hidden'  => $hidden ? 1 : 0,
                'flags'   => $can_moderate ? (string) $row->flags : '',
                'reports' => $can_moderate ? (int) $row->report_count : 0,
            );
        }
        return $out;
    }

    private static function fetch_messages( $group_id, $after_id = 0, $limit = 60 ) {
        global $wpdb;
        $table = self::table( 'messages' );
        $limit = max( 1, min( 200, (int) $limit ) );
        if ( $after_id > 0 ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $table WHERE group_id=%d AND id>%d AND status<>'deleted' ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (int) $group_id, (int) $after_id, $limit
            ) );
            return (array) $rows;
        }
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE group_id=%d AND status<>'deleted' ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (int) $group_id, $limit
        ) );
        return array_reverse( $rows );
    }

    /* ------------------------------------------------------------- endpoints */

    public static function ajax_list() {
        $user_id = self::guard( 120 );
        global $wpdb;
        $table = self::table( 'groups' );
        $rows = (array) $wpdb->get_results( "SELECT * FROM $table WHERE status='active' AND visibility='public' ORDER BY sort_order ASC, last_message_at DESC, id ASC LIMIT 60" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $groups = array();
        foreach ( $rows as $row ) $groups[] = self::group_payload( $row, $user_id );
        wp_send_json_success( array(
            'groups'      => $groups,
            'displayName' => self::display_name( $user_id ),
            'canModerate' => self::can_moderate(),
            'isStaff'     => self::is_staff( $user_id ),
        ) );
    }

    public static function ajax_open() {
        $user_id = self::guard( 120 );
        $s = self::settings();
        $group = self::get_group( isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : 0 );
        if ( ! $group || 'public' !== $group->visibility ) {
            wp_send_json_error( array( 'message' => __( 'Groupe introuvable.', 'delicat-assistant' ) ), 404 );
        }
        $rows = self::fetch_messages( (int) $group->id, 0, (int) $s['groups_history'] );
        wp_send_json_success( array(
            'group'    => self::group_payload( $group, $user_id ),
            'messages' => self::format_messages( $rows, $user_id ),
            'rules'    => array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $s['groups_rules'] ) ) ) ),
        ) );
    }

    public static function ajax_poll() {
        $user_id = self::guard( 240 );
        $group = self::get_group( isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : 0 );
        if ( ! $group ) wp_send_json_error( array( 'message' => __( 'Groupe introuvable.', 'delicat-assistant' ) ), 404 );
        $after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
        $rows = self::fetch_messages( (int) $group->id, $after, 60 );
        wp_send_json_success( array(
            'messages' => self::format_messages( $rows, $user_id ),
            'group'    => self::group_payload( $group, $user_id ),
        ) );
    }

    public static function ajax_join() {
        $user_id = self::guard( 40 );
        $group = self::get_group( isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : 0 );
        if ( ! $group || 'active' !== $group->status || 'public' !== $group->visibility ) {
            wp_send_json_error( array( 'message' => __( 'Groupe indisponible.', 'delicat-assistant' ) ), 404 );
        }
        $existing = self::membership( $group->id, $user_id );
        if ( $existing && 'banned' === $existing->status ) {
            wp_send_json_error( array( 'message' => __( 'Vous ne pouvez pas rejoindre ce groupe.', 'delicat-assistant' ) ), 403 );
        }
        self::add_member( (int) $group->id, $user_id );
        $group = self::get_group( (int) $group->id );
        wp_send_json_success( array( 'group' => self::group_payload( $group, $user_id ) ) );
    }

    public static function ajax_leave() {
        $user_id = self::guard( 40 );
        $group = self::get_group( isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : 0 );
        if ( ! $group ) wp_send_json_error( array( 'message' => __( 'Groupe introuvable.', 'delicat-assistant' ) ), 404 );
        global $wpdb;
        $wpdb->update( self::table( 'members' ), array( 'status' => 'left' ), array( 'group_id' => (int) $group->id, 'user_id' => $user_id ), array( '%s' ), array( '%d', '%d' ) );
        self::refresh_counts( (int) $group->id );
        $group = self::get_group( (int) $group->id );
        wp_send_json_success( array( 'group' => self::group_payload( $group, $user_id ) ) );
    }

    public static function add_member( $group_id, $user_id, $role = self::ROLE_MEMBER ) {
        global $wpdb;
        $table = self::table( 'members' );
        $existing = self::membership( $group_id, $user_id );
        $now = current_time( 'mysql', 1 );
        if ( $existing ) {
            $wpdb->update( $table, array( 'status' => 'active', 'muted_until' => null, 'last_seen_at' => $now ), array( 'id' => (int) $existing->id ), array( '%s', '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, array(
                'group_id'     => absint( $group_id ),
                'user_id'      => absint( $user_id ),
                'role'         => sanitize_key( $role ),
                'status'       => 'active',
                'last_read_id' => 0,
                'joined_at'    => $now,
                'last_seen_at' => $now,
            ), array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) );
        }
        self::refresh_counts( $group_id );
        return true;
    }

    public static function refresh_counts( $group_id ) {
        global $wpdb;
        $members = self::table( 'members' );
        $messages = self::table( 'messages' );
        $groups = self::table( 'groups' );
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $members WHERE group_id=%d AND status='active'", absint( $group_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $messages WHERE group_id=%d AND status='visible'", absint( $group_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->update( $groups, array( 'member_count' => $count, 'message_count' => $total ), array( 'id' => absint( $group_id ) ), array( '%d', '%d' ), array( '%d' ) );
    }

    public static function ajax_send() {
        $user_id = self::guard( 60 );
        $s = self::settings();
        $group = self::get_group( isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : 0 );
        if ( ! $group ) wp_send_json_error( array( 'message' => __( 'Groupe introuvable.', 'delicat-assistant' ) ), 404 );

        list( $can_post, $reason, $message ) = self::post_state( $group, $user_id );
        if ( ! $can_post ) wp_send_json_error( array( 'code' => $reason, 'message' => $message ), 403 );

        $raw = isset( $_POST['message'] ) ? (string) wp_unslash( $_POST['message'] ) : '';
        $raw = sanitize_textarea_field( $raw );
        if ( '' === trim( $raw ) ) wp_send_json_error( array( 'message' => __( 'Message vide.', 'delicat-assistant' ) ), 400 );

        // Slow mode, per member per group.
        $slow = max( 0, min( 300, (int) $group->slow_mode ) );
        if ( $slow && ! self::can_moderate() ) {
            $key = 'dapg_slow_' . (int) $group->id . '_' . $user_id;
            $last = (int) get_transient( $key );
            if ( $last && ( time() - $last ) < $slow ) {
                wp_send_json_error( array(
                    'code'    => 'slow_mode',
                    'wait'    => $slow - ( time() - $last ),
                    'message' => sprintf( __( 'Mode lent : attendez %d s.', 'delicat-assistant' ), $slow - ( time() - $last ) ),
                ), 429 );
            }
        }

        $clean = self::sanitize_body( $raw, $user_id );
        if ( '' === $clean['body'] ) {
            wp_send_json_error( array( 'code' => 'filtered', 'message' => __( 'Ce message ne peut pas être publié (numéro, code ou lien interdit).', 'delicat-assistant' ) ), 422 );
        }
        if ( self::is_duplicate( (int) $group->id, $user_id, $clean['body'] ) ) {
            wp_send_json_error( array( 'code' => 'duplicate', 'message' => __( 'Vous venez d’envoyer ce message.', 'delicat-assistant' ) ), 429 );
        }

        $after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
        $id = self::insert_message( (int) $group->id, $user_id, $clean['body'], 'text', array(), $clean['flags'] );
        if ( ! $id ) wp_send_json_error( array( 'message' => __( 'Envoi impossible. Réessayez.', 'delicat-assistant' ) ), 500 );
        if ( $slow ) set_transient( 'dapg_slow_' . (int) $group->id . '_' . $user_id, time(), max( 60, $slow * 3 ) );

        $rows = self::fetch_messages( (int) $group->id, $after, 60 );
        $group = self::get_group( (int) $group->id );
        wp_send_json_success( array(
            'messages' => self::format_messages( $rows, $user_id ),
            'group'    => self::group_payload( $group, $user_id ),
            'filtered' => $clean['flags'] ? 1 : 0,
        ) );
    }

    private static function is_duplicate( $group_id, $user_id, $body ) {
        global $wpdb;
        $table = self::table( 'messages' );
        $last = $wpdb->get_var( $wpdb->prepare(
            "SELECT body FROM $table WHERE group_id=%d AND user_id=%d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            absint( $group_id ), absint( $user_id )
        ) );
        return null !== $last && (string) $last === (string) $body;
    }

    public static function insert_message( $group_id, $user_id, $body, $type = 'text', $meta = array(), $flags = array() ) {
        global $wpdb;
        $now = current_time( 'mysql', 1 );
        $author = self::display_name( $user_id );
        $meta = is_array( $meta ) ? $meta : array();
        $meta['author'] = $author; // frozen at write time: renaming later never rewrites history
        $inserted = $wpdb->insert( self::table( 'messages' ), array(
            'group_id'     => absint( $group_id ),
            'user_id'      => absint( $user_id ),
            'author_role'  => self::member_role( $group_id, $user_id ),
            'body'         => $body,
            'message_type' => sanitize_key( $type ),
            'meta'         => wp_json_encode( $meta ),
            'status'       => 'visible',
            'flags'        => implode( ',', array_map( 'sanitize_key', (array) $flags ) ),
            'report_count' => 0,
            'pinned'       => 0,
            'created_at'   => $now,
        ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ) );
        $id = (int) $wpdb->insert_id;
        if ( false === $inserted || ! $id ) return 0;
        $wpdb->update( self::table( 'groups' ), array( 'last_message_at' => $now ), array( 'id' => absint( $group_id ) ), array( '%s' ), array( '%d' ) );
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'groups' ) . ' SET message_count=message_count+1 WHERE id=%d', absint( $group_id ) ) );
        // Members read their own writes without a round trip.
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'members' ) . ' SET last_read_id=%d WHERE group_id=%d AND user_id=%d', $id, absint( $group_id ), absint( $user_id ) ) );
        do_action( 'dsa2_group_message_created', absint( $group_id ), $id, absint( $user_id ), $flags );
        return $id;
    }

    public static function ajax_read() {
        $user_id = self::guard( 240 );
        $group = self::get_group( isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : 0 );
        if ( ! $group ) wp_send_json_error( array( 'message' => __( 'Groupe introuvable.', 'delicat-assistant' ) ), 404 );
        $last = isset( $_POST['last_id'] ) ? absint( $_POST['last_id'] ) : 0;
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::table( 'members' ) . ' SET last_read_id=%d, last_seen_at=%s WHERE group_id=%d AND user_id=%d AND last_read_id<%d',
            $last, current_time( 'mysql', 1 ), (int) $group->id, $user_id, $last
        ) );
        wp_send_json_success( array( 'ok' => 1 ) );
    }

    public static function ajax_profile() {
        $user_id = self::guard( 20 );
        $name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
        $name = trim( preg_replace( '/\s{2,}/', ' ', (string) $name ) );
        $name = preg_replace( '/[^\p{L}\p{N}\s._-]/u', '', $name );
        if ( DSA2_Compat::length( $name ) < 2 || DSA2_Compat::length( $name ) > 24 ) {
            wp_send_json_error( array( 'message' => __( 'Le nom doit contenir entre 2 et 24 caractères.', 'delicat-assistant' ) ), 400 );
        }
        if ( self::reserved_name( $name ) && ! self::is_staff( $user_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Ce nom est réservé à l’équipe Delicat.', 'delicat-assistant' ) ), 403 );
        }
        update_user_meta( $user_id, 'dap_group_name', $name );
        wp_send_json_success( array( 'displayName' => $name ) );
    }

    public static function ajax_report() {
        $user_id = self::guard( 20 );
        $s = self::settings();
        $message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : 'other';
        if ( ! in_array( $reason, array( 'scam', 'abuse', 'spam', 'other' ), true ) ) $reason = 'other';
        global $wpdb;
        $messages = self::table( 'messages' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $messages WHERE id=%d", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $row ) wp_send_json_error( array( 'message' => __( 'Message introuvable.', 'delicat-assistant' ) ), 404 );
        if ( (int) $row->user_id === $user_id ) wp_send_json_error( array( 'message' => __( 'Vous ne pouvez pas signaler votre propre message.', 'delicat-assistant' ) ), 400 );

        // UNIQUE(message_id, reporter_id) makes a second report from the same account a no-op.
        $wpdb->insert( self::table( 'reports' ), array(
            'message_id'  => $message_id,
            'group_id'    => (int) $row->group_id,
            'reporter_id' => $user_id,
            'reason'      => $reason,
            'status'      => 'open',
            'created_at'  => current_time( 'mysql', 1 ),
        ), array( '%d', '%d', '%d', '%s', '%s', '%s' ) );

        $reports = self::table( 'reports' );
        $distinct = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT reporter_id) FROM $reports WHERE message_id=%d", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $threshold = max( 1, (int) $s['groups_report_hide'] );
        $update = array( 'report_count' => $distinct );
        $format = array( '%d' );
        if ( $distinct >= $threshold && 'visible' === $row->status ) {
            $update['status'] = 'hidden';
            $format[] = '%s';
        }
        $wpdb->update( $messages, $update, array( 'id' => $message_id ), $format, array( '%d' ) );
        wp_send_json_success( array( 'reported' => 1, 'hidden' => $distinct >= $threshold ? 1 : 0 ) );
    }

    /* ------------------------------------------------------------ moderation */

    public static function ajax_moderate() {
        $user_id = self::guard( 120 );
        if ( ! self::can_moderate() ) {
            wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'delicat-assistant' ) ), 403 );
        }
        $action = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
        $message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;
        $group_id = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
        $target = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;
        global $wpdb;
        $messages = self::table( 'messages' );

        switch ( $action ) {
            case 'delete':
                $wpdb->update( $messages, array( 'status' => 'deleted' ), array( 'id' => $message_id ), array( '%s' ), array( '%d' ) );
                break;
            case 'hide':
                $wpdb->update( $messages, array( 'status' => 'hidden' ), array( 'id' => $message_id ), array( '%s' ), array( '%d' ) );
                break;
            case 'restore':
                $wpdb->update( $messages, array( 'status' => 'visible', 'report_count' => 0 ), array( 'id' => $message_id ), array( '%s', '%d' ), array( '%d' ) );
                $wpdb->update( self::table( 'reports' ), array( 'status' => 'dismissed' ), array( 'message_id' => $message_id ), array( '%s' ), array( '%d' ) );
                break;
            case 'pin':
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT group_id,pinned FROM $messages WHERE id=%d", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                if ( $row ) {
                    $wpdb->update( $messages, array( 'pinned' => 0 ), array( 'group_id' => (int) $row->group_id ), array( '%d' ), array( '%d' ) );
                    if ( ! (int) $row->pinned ) $wpdb->update( $messages, array( 'pinned' => 1 ), array( 'id' => $message_id ), array( '%d' ), array( '%d' ) );
                }
                break;
            case 'mute':
                if ( ! $target || ! $group_id ) break;
                $minutes = isset( $_POST['minutes'] ) ? max( 5, min( 10080, absint( $_POST['minutes'] ) ) ) : 60;
                $wpdb->update( self::table( 'members' ), array( 'muted_until' => gmdate( 'Y-m-d H:i:s', time() + $minutes * MINUTE_IN_SECONDS ) ), array( 'group_id' => $group_id, 'user_id' => $target ), array( '%s' ), array( '%d', '%d' ) );
                break;
            case 'unmute':
                if ( ! $target || ! $group_id ) break;
                $wpdb->update( self::table( 'members' ), array( 'muted_until' => null ), array( 'group_id' => $group_id, 'user_id' => $target ), array( '%s' ), array( '%d', '%d' ) );
                break;
            case 'ban':
                if ( ! $target ) break;
                if ( self::is_staff( $target ) ) wp_send_json_error( array( 'message' => __( 'Impossible de bannir un membre de l’équipe.', 'delicat-assistant' ) ), 400 );
                if ( $group_id ) {
                    $wpdb->update( self::table( 'members' ), array( 'status' => 'banned' ), array( 'group_id' => $group_id, 'user_id' => $target ), array( '%s' ), array( '%d', '%d' ) );
                    self::refresh_counts( $group_id );
                } else {
                    update_user_meta( $target, 'dap_groups_banned', 1 );
                }
                break;
            case 'unban':
                if ( ! $target ) break;
                delete_user_meta( $target, 'dap_groups_banned' );
                if ( $group_id ) $wpdb->update( self::table( 'members' ), array( 'status' => 'active' ), array( 'group_id' => $group_id, 'user_id' => $target ), array( '%s' ), array( '%d', '%d' ) );
                break;
            case 'lock':
                if ( ! $group_id ) break;
                $locked = isset( $_POST['locked'] ) ? absint( $_POST['locked'] ) : 1;
                $wpdb->update( self::table( 'groups' ), array( 'locked' => $locked ? 1 : 0 ), array( 'id' => $group_id ), array( '%d' ), array( '%d' ) );
                break;
            default:
                wp_send_json_error( array( 'message' => __( 'Action inconnue.', 'delicat-assistant' ) ), 400 );
        }
        if ( $message_id && ! $group_id ) {
            $group_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM $messages WHERE id=%d", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        if ( $group_id ) self::refresh_counts( $group_id );
        $group = $group_id ? self::get_group( $group_id ) : null;
        wp_send_json_success( array(
            'ok'       => 1,
            'group'    => $group ? self::group_payload( $group, $user_id ) : null,
            'messages' => $group ? self::format_messages( self::fetch_messages( (int) $group->id, 0, 60 ), $user_id ) : array(),
        ) );
    }

    /* --------------------------------------------------------------- uploads */

    public static function ajax_upload() {
        $user_id = self::guard( 30 );
        $s = self::settings();
        if ( empty( $s['groups_images'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Les images sont désactivées dans les groupes.', 'delicat-assistant' ) ), 403 );
        }
        $group = self::get_group( isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : 0 );
        if ( ! $group ) wp_send_json_error( array( 'message' => __( 'Groupe introuvable.', 'delicat-assistant' ) ), 404 );
        list( $can_post, $reason, $message ) = self::post_state( $group, $user_id );
        if ( ! $can_post ) wp_send_json_error( array( 'code' => $reason, 'message' => $message ), 403 );

        if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['file']['error'] ) {
            wp_send_json_error( array( 'message' => __( 'Envoi impossible.', 'delicat-assistant' ) ), 400 );
        }
        if ( (int) $_FILES['file']['size'] > 4 * MB_IN_BYTES ) {
            wp_send_json_error( array( 'message' => __( 'Image trop lourde (4 Mo maximum).', 'delicat-assistant' ) ), 400 );
        }
        // The bytes decide, not the file name.
        $size = @getimagesize( $_FILES['file']['tmp_name'] );
        $types = array( IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp' );
        if ( ! $size || ! isset( $types[ (int) $size[2] ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Seules les images sont acceptées.', 'delicat-assistant' ) ), 400 );
        }
        if ( self::bump( 'dapg_up_' . $user_id, HOUR_IN_SECONDS ) > 10 ) {
            wp_send_json_error( array( 'message' => __( 'Limite d’images atteinte pour cette heure.', 'delicat-assistant' ) ), 429 );
        }
        $ext = $types[ (int) $size[2] ];
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $_FILES['file']['name'] = 'dapg-' . wp_generate_password( 20, false, false ) . '.' . $ext;
        $moved = wp_handle_upload( $_FILES['file'], array(
            'test_form' => false,
            'mimes'     => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ),
        ) );
        if ( ! $moved || isset( $moved['error'] ) || empty( $moved['url'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Envoi impossible.', 'delicat-assistant' ) ), 400 );
        }
        $id = self::insert_message( (int) $group->id, $user_id, __( 'Image', 'delicat-assistant' ), 'image', array( 'url' => esc_url_raw( $moved['url'] ) ), array( 'image' ) );
        if ( ! $id ) {
            if ( ! empty( $moved['file'] ) && is_file( $moved['file'] ) ) wp_delete_file( $moved['file'] );
            wp_send_json_error( array( 'message' => __( 'Envoi impossible.', 'delicat-assistant' ) ), 500 );
        }
        $after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
        wp_send_json_success( array( 'messages' => self::format_messages( self::fetch_messages( (int) $group->id, $after, 60 ), $user_id ) ) );
    }

    /* ---------------------------------------------------------- housekeeping */

    /** Called from the plugin's daily cleanup so group history cannot grow forever. */
    public static function cleanup() {
        $s = self::settings();
        $days = (int) $s['groups_retention_days'];
        if ( $days < 1 ) return;
        global $wpdb;
        $messages = self::table( 'messages' );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $messages WHERE created_at<%s AND pinned=0 LIMIT 500", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $ids ) return;
        $in = implode( ',', array_map( 'absint', $ids ) );
        // Remove the uploaded files before the rows that point at them.
        $rows = (array) $wpdb->get_results( "SELECT meta FROM $messages WHERE id IN ($in) AND message_type='image'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $uploads = wp_get_upload_dir();
        foreach ( $rows as $row ) {
            $meta = $row->meta ? json_decode( $row->meta, true ) : array();
            if ( empty( $meta['url'] ) ) continue;
            $path = str_replace( $uploads['baseurl'], $uploads['basedir'], (string) $meta['url'] );
            if ( 0 === strpos( $path, $uploads['basedir'] ) && is_file( $path ) ) wp_delete_file( $path );
        }
        $wpdb->query( "DELETE FROM " . self::table( 'reports' ) . " WHERE message_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DELETE FROM $messages WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** One room so the feature works the moment it is switched on. */
    public static function seed_default_group() {
        global $wpdb;
        $table = self::table( 'groups' );
        $exists = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $exists ) return;
        $wpdb->insert( $table, array(
            'slug'            => 'communaute-delicat',
            'name'            => 'Communauté Delicat',
            'description'     => 'Échangez avec les autres clients : jeux, recharges, astuces.',
            'icon'            => '🎮',
            'visibility'      => 'public',
            'status'          => 'active',
            'locked'          => 0,
            'slow_mode'       => 5,
            'member_count'    => 0,
            'message_count'   => 0,
            'sort_order'      => 1,
            'created_by'      => get_current_user_id(),
            'last_message_at' => null,
            'created_at'      => current_time( 'mysql', 1 ),
        ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' ) );
    }
}
