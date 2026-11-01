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
 *  6. (7.0) Fresh accounts mass-reporting to silence someone (or the team). Countermeasure:
 *     only members of the room can report, only reporters whose account is at least two
 *     days old count towards automatic hiding, and staff or pinned messages are never
 *     hidden automatically (they go to the review queue instead).
 *  7. (7.0) Reading a hidden or archived room by guessing its id. Countermeasure: every
 *     read (open, poll, read marker, report) checks that the member may see that room.
 *
 * "Ask the team" (7.0): a member can post a question flagged for the team; staff answer in
 * the room (the answer quotes the question) or the member moves it to their private
 * support chat, where only they and the team can read it.
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
        add_action( 'dsa2_group_question_alert', array( __CLASS__, 'send_question_alert' ), 10, 2 );
        if ( ! self::enabled() ) return;

        // Members only. There is deliberately no wp_ajax_nopriv_* twin for any of these:
        // an unauthenticated request must not be able to read or write a single row.
        // 7.0: the storefront UI lives in the chat app (assets/app); nothing is enqueued here.
        foreach ( array(
            'list'     => 'ajax_list',
            'open'     => 'ajax_open',
            'poll'     => 'ajax_poll',
            'send'     => 'ajax_send',
            'ask'      => 'ajax_ask',
            'private'  => 'ajax_private',
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
        $name = self::raw_display_name( $user_id );
        // 7.0: an account name (first name, display name, login) that claims to be the store or its staff is never
        // shown for a customer, whatever spacing, digits or look-alike letters it uses.
        if ( self::reserved_name( $name ) && ! self::is_staff( $user_id ) ) {
            /* translators: %d: customer id */
            return sprintf( __( 'Client #%d', 'delicat-assistant' ), $user_id );
        }
        return $name;
    }

    private static function raw_display_name( $user_id ) {
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
        // Compared without spaces or separators ("D e l i c a t", "D.elicat") and after mapping digits and
        // look-alike letters ("Sup0rt", "Adm1n", Cyrillic "Dеlicat").
        $probe = str_replace( ' ', '', self::fold( strtr( (string) $name, array(
            'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x', 'і' => 'i', 'ј' => 'j', 'к' => 'k', 'м' => 'm', 'т' => 't', 'н' => 'h', 'в' => 'b', 'ѕ' => 's', 'ԁ' => 'd', 'ӏ' => 'l',
            'А' => 'a', 'Е' => 'e', 'О' => 'o', 'Р' => 'p', 'С' => 'c', 'У' => 'y', 'Х' => 'x', 'І' => 'i', 'К' => 'k', 'М' => 'm', 'Т' => 't', 'Н' => 'h', 'В' => 'b', 'Ѕ' => 's',
            'α' => 'a', 'ε' => 'e', 'ο' => 'o', 'ρ' => 'p', 'ι' => 'i', 'κ' => 'k', 'ν' => 'v', 'τ' => 't', 'Α' => 'a', 'Ε' => 'e', 'Ο' => 'o', 'Ρ' => 'p', 'Ι' => 'i', 'Κ' => 'k', 'Τ' => 't', 'Μ' => 'm', 'Ν' => 'n',
        ) ) ) );
        $probe = strtr( $probe, array( '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '8' => 'b', '9' => 'g' ) );
        $loose = strtr( $probe, array( 'l' => 'i' ) ); // "suppl0rt", "adm|n" and friends
        foreach ( array( 'delicat', 'admin', 'support', 'officiel', 'official', 'moderateur', 'moderator', 'agent', 'staff', 'equipe', 'serviceclient', 'sipo' ) as $needle ) {
            if ( false !== strpos( $probe, $needle ) || false !== strpos( $loose, strtr( $needle, array( 'l' => 'i' ) ) ) ) return true;
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

    /** Statuses of the messages that replies quote, so a quote never outlives its original (L5). */
    private static function quoted_status( $rows ) {
        $ids = array();
        foreach ( (array) $rows as $row ) {
            $meta = $row->meta ? json_decode( $row->meta, true ) : array();
            if ( is_array( $meta ) && ! empty( $meta['reply_to']['id'] ) ) $ids[] = (int) $meta['reply_to']['id'];
        }
        if ( ! $ids ) return array();
        global $wpdb;
        $in = implode( ',', array_map( 'absint', array_unique( $ids ) ) );
        $out = array();
        foreach ( (array) $wpdb->get_results( 'SELECT id,status FROM ' . self::table( 'messages' ) . " WHERE id IN ($in)" ) as $r ) $out[ (int) $r->id ] = (string) $r->status; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $out;
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
        // 7.0: the team answers in any room without joining it first.
        if ( self::can_moderate( $user_id ) ) return array( true, '', '' );
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

    /**
     * 7.0: who may read a room. Staff: every room. Others: active public rooms, plus hidden
     * or archived rooms they are an active member of. A banned member reads nothing.
     */
    public static function can_view( $group, $user_id ) {
        if ( ! $group ) return false;
        if ( self::can_moderate( $user_id ) ) return true;
        $m = self::membership( $group->id, $user_id );
        if ( $m && 'banned' === $m->status ) return false;
        if ( 'active' === $group->status && 'public' === $group->visibility ) return true;
        return $m && 'active' === $m->status;
    }

    /** Group rows are written in UTC (current_time( 'mysql', 1 )). */
    public static function iso( $mysql_utc ) {
        if ( ! $mysql_utc || 0 === strpos( (string) $mysql_utc, '0000' ) ) return '';
        $ts = strtotime( $mysql_utc . ' UTC' );
        return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : '';
    }

    /** Resolves the group named in the request and stops unless this user may read it. */
    private static function requested_group( $user_id, $field = 'group' ) {
        $raw = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce
        $group = self::get_group( $raw );
        if ( ! $group || ! self::can_view( $group, $user_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Groupe introuvable.', 'delicat-assistant' ) ), 404 );
        }
        return $group;
    }

    private static function open_questions( $group_id ) {
        global $wpdb;
        $table = self::table( 'messages' );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE group_id=%d AND message_type='question' AND status='visible' AND flags NOT LIKE %s AND flags NOT LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (int) $group_id, '%answered%', '%private%'
        ) );
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
        $staff = self::can_moderate( $user_id );
        return array(
            'id'          => (int) $group->id,
            'slug'        => (string) $group->slug,
            'name'        => (string) $group->name,
            'description' => (string) $group->description,
            'members'     => (int) $group->member_count,
            'locked'      => (int) $group->locked,
            'archived'    => 'active' !== $group->status ? 1 : 0,
            'private'     => 'public' !== $group->visibility ? 1 : 0,
            'slow'        => (int) $group->slow_mode,
            'joined'      => ( $m && 'active' === $m->status ) ? 1 : 0,
            'role'        => self::member_role( $group->id, $user_id ),
            'unread'      => min( 99, $unread ),
            'lastAt'      => self::iso( $group->last_message_at ),
            'canPost'     => $can_post ? 1 : 0,
            'blockReason' => $reason,
            'blockText'   => $message,
            'questions'   => $staff ? self::open_questions( (int) $group->id ) : 0,
        );
    }

    public static function format_messages( $rows, $user_id ) {
        $out = array();
        $can_moderate = self::can_moderate();
        $quoted = self::quoted_status( $rows );
        foreach ( (array) $rows as $row ) {
            $meta = $row->meta ? json_decode( $row->meta, true ) : array();
            if ( ! is_array( $meta ) ) $meta = array();
            $hidden = 'visible' !== $row->status;
            $mine = ( (int) $row->user_id === (int) $user_id );
            $body = $row->body;
            if ( $hidden && ! $can_moderate ) $body = __( 'Message retiré par la modération.', 'delicat-assistant' );
            $author = isset( $meta['author'] ) ? (string) $meta['author'] : self::display_name( (int) $row->user_id );
            // Names frozen by earlier versions are checked too: a customer never appears under a staff-like name.
            if ( self::ROLE_AGENT !== $row->author_role && self::reserved_name( $author ) ) $author = sprintf( __( 'Client #%d', 'delicat-assistant' ), (int) $row->user_id ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
            $flags = array_filter( explode( ',', (string) $row->flags ) );
            // Scoped to the room: groups a run of messages by one person here, but cannot link that person
            // across rooms (or to a later name) the way a site-wide key could.
            $author_key = substr( wp_hash( 'dsa7-author|' . (int) $row->group_id . '|' . (int) $row->user_id, 'nonce' ), 0, 12 );
            $question = '';
            if ( 'question' === $row->message_type ) {
                $question = in_array( 'answered', $flags, true ) ? 'answered' : ( in_array( 'private', $flags, true ) ? 'private' : 'open' );
            }
            $reply = null;
            if ( ! empty( $meta['reply_to'] ) && is_array( $meta['reply_to'] ) && ! $hidden ) {
                $rid = (int) ( $meta['reply_to']['id'] ?? 0 );
                $alive = isset( $quoted[ $rid ] ) && 'visible' === $quoted[ $rid ];
                $reply = array(
                    'id'      => $rid,
                    'author'  => $alive ? (string) ( $meta['reply_to']['author'] ?? '' ) : '',
                    'excerpt' => $alive ? (string) ( $meta['reply_to']['excerpt'] ?? '' ) : __( 'Message retiré', 'delicat-assistant' ),
                );
            }
            $out[] = array(
                'id'       => (int) $row->id,
                'groupId'  => (int) $row->group_id,
                'userId'   => $can_moderate || $mine ? (int) $row->user_id : 0,
                'author'   => $author,
                'initials' => self::initials( $author ),
                'ak'       => $author_key, // groups a run of messages by one person; not an account id
                'role'     => (string) $row->author_role,
                'type'     => $hidden ? 'text' : (string) $row->message_type,
                'body'     => (string) $body,
                'url'      => ( ! $hidden && isset( $meta['url'] ) ) ? esc_url_raw( (string) $meta['url'] ) : '',
                'at'       => self::iso( $row->created_at ),
                'mine'     => $mine ? 1 : 0,
                'pinned'   => (int) $row->pinned,
                'hidden'   => $hidden ? 1 : 0,
                'question' => $hidden ? '' : $question,
                'replyTo'  => $reply,
                'flags'    => $can_moderate ? (string) $row->flags : '',
                'reports'  => $can_moderate ? (int) $row->report_count : 0,
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

    /** Older history, for "load earlier messages". */
    private static function fetch_before( $group_id, $before_id, $limit = 40 ) {
        global $wpdb;
        $table = self::table( 'messages' );
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE group_id=%d AND id<%d AND status<>'deleted' ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (int) $group_id, (int) $before_id, max( 1, min( 100, (int) $limit ) )
        ) );
        return array_reverse( $rows );
    }

    /* ------------------------------------------------------------- endpoints */

    public static function ajax_list() {
        $user_id = self::guard( 120 );
        global $wpdb;
        $groups_t  = self::table( 'groups' );
        $members_t = self::table( 'members' );
        if ( self::can_moderate() ) {
            $rows = (array) $wpdb->get_results( "SELECT * FROM $groups_t WHERE status='active' ORDER BY sort_order ASC, last_message_at DESC, id ASC LIMIT 60" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            // Public rooms, plus the hidden rooms this member was added to.
            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT g.* FROM $groups_t g WHERE g.status='active' AND ( g.visibility='public' OR EXISTS ( SELECT 1 FROM $members_t m WHERE m.group_id=g.id AND m.user_id=%d AND m.status='active' ) ) ORDER BY g.sort_order ASC, g.last_message_at DESC, g.id ASC LIMIT 60", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $user_id
            ) );
        }
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
        $group = self::requested_group( $user_id );
        $rows = self::fetch_messages( (int) $group->id, 0, (int) $s['groups_history'] );
        wp_send_json_success( array(
            'group'    => self::group_payload( $group, $user_id ),
            'messages' => self::format_messages( $rows, $user_id ),
            'more'     => count( $rows ) >= (int) $s['groups_history'] ? 1 : 0,
            'rules'    => array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $s['groups_rules'] ) ) ) ),
        ) );
    }

    public static function ajax_poll() {
        $user_id = self::guard( 240 );
        $group = self::requested_group( $user_id );
        $before = isset( $_POST['before_id'] ) ? absint( $_POST['before_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $before ) {
            $rows = self::fetch_before( (int) $group->id, $before, 40 );
            wp_send_json_success( array( 'messages' => self::format_messages( $rows, $user_id ), 'more' => count( $rows ) >= 40 ? 1 : 0, 'older' => 1 ) );
        }
        $after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $rows = self::fetch_messages( (int) $group->id, $after, 60 );
        wp_send_json_success( array(
            'messages' => self::format_messages( $rows, $user_id ),
            'group'    => self::group_payload( $group, $user_id ),
        ) );
    }

    public static function ajax_join() {
        $user_id = self::guard( 40 );
        $group = self::get_group( isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
        $group = self::requested_group( $user_id );
        global $wpdb;
        $wpdb->update( self::table( 'members' ), array( 'status' => 'left' ), array( 'group_id' => (int) $group->id, 'user_id' => $user_id, 'status' => 'active' ), array( '%s' ), array( '%d', '%d', '%s' ) );
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
            if ( 'banned' === $existing->status ) return false;
            $wpdb->update( $table, array( 'status' => 'active', 'last_seen_at' => $now ), array( 'id' => (int) $existing->id ), array( '%s', '%s' ), array( '%d' ) );
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

    /**
     * Shared checks of every member write (message or question): post rights, slow mode,
     * masking, duplicates. Returns array( group, cleaned body, flags ).
     */
    private static function prepare_post( $user_id, $max_len_field = 'message' ) {
        $group = self::requested_group( $user_id );
        list( $can_post, $reason, $message ) = self::post_state( $group, $user_id );
        if ( ! $can_post ) wp_send_json_error( array( 'code' => $reason, 'message' => $message ), 403 );

        $raw = isset( $_POST[ $max_len_field ] ) ? (string) wp_unslash( $_POST[ $max_len_field ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_body() below
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
                    /* translators: %d: seconds to wait */
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
        return array( $group, $clean['body'], $clean['flags'], $slow );
    }

    /** A visible message of this group that a reply may quote. */
    private static function reply_target( $group_id ) {
        $reply_id = isset( $_POST['reply_to'] ) ? absint( $_POST['reply_to'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! $reply_id ) return null;
        global $wpdb;
        $table = self::table( 'messages' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND group_id=%d AND status='visible'", $reply_id, (int) $group_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $row ? $row : null;
    }

    public static function ajax_send() {
        $user_id = self::guard( 60 );
        list( $group, $body, $flags, $slow ) = self::prepare_post( $user_id );

        $meta = array();
        $type = 'text';
        $reply = self::reply_target( (int) $group->id );
        if ( $reply ) {
            $rmeta = $reply->meta ? json_decode( $reply->meta, true ) : array();
            $meta['reply_to'] = array(
                'id'      => (int) $reply->id,
                'author'  => isset( $rmeta['author'] ) ? (string) $rmeta['author'] : self::display_name( (int) $reply->user_id ),
                'excerpt' => DSA2_Compat::substr( (string) $reply->body, 0, 140 ),
            );
            // The team answering a member's question: the question is marked answered.
            if ( 'question' === $reply->message_type && self::can_moderate() ) {
                $type = 'answer';
                self::set_question_state( (int) $reply->id, 'answered', $user_id );
            }
        }

        $after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $id = self::insert_message( (int) $group->id, $user_id, $body, $type, $meta, $flags );
        if ( ! $id ) wp_send_json_error( array( 'message' => __( 'Envoi impossible. Réessayez.', 'delicat-assistant' ) ), 500 );
        if ( $slow ) set_transient( 'dapg_slow_' . (int) $group->id . '_' . $user_id, time(), max( 60, $slow * 3 ) );

        $rows = self::fetch_messages( (int) $group->id, $after, 60 );
        $group = self::get_group( (int) $group->id );
        wp_send_json_success( array(
            'messages' => self::format_messages( $rows, $user_id ),
            'group'    => self::group_payload( $group, $user_id ),
            'filtered' => $flags ? 1 : 0,
        ) );
    }

    /**
     * "Ask the team": the question is posted in the room (so other members see the answer
     * too), flagged for the team, and staff are alerted out of band.
     */
    public static function ajax_ask() {
        $user_id = self::guard( 30 );
        if ( self::bump( 'dapg_ask_' . $user_id, HOUR_IN_SECONDS ) > 10 ) {
            wp_send_json_error( array( 'code' => 'rate_limited', 'message' => __( 'Vous avez posé beaucoup de questions. Écrivez à l’équipe en privé.', 'delicat-assistant' ) ), 429 );
        }
        list( $group, $body, $flags, $slow ) = self::prepare_post( $user_id );
        $after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $id = self::insert_message( (int) $group->id, $user_id, $body, 'question', array(), $flags );
        if ( ! $id ) wp_send_json_error( array( 'message' => __( 'Envoi impossible. Réessayez.', 'delicat-assistant' ) ), 500 );
        if ( $slow ) set_transient( 'dapg_slow_' . (int) $group->id . '_' . $user_id, time(), max( 60, $slow * 3 ) );
        if ( ! wp_next_scheduled( 'dsa2_group_question_alert', array( (int) $group->id, $id ) ) ) {
            wp_schedule_single_event( time(), 'dsa2_group_question_alert', array( (int) $group->id, $id ) );
        }
        $rows = self::fetch_messages( (int) $group->id, $after, 60 );
        $group = self::get_group( (int) $group->id );
        wp_send_json_success( array(
            'messages' => self::format_messages( $rows, $user_id ),
            'group'    => self::group_payload( $group, $user_id ),
            'filtered' => $flags ? 1 : 0,
        ) );
    }

    /**
     * Moves one of the member's own room messages into their private support chat with
     * the team (only they and the team can read it there) and asks for an agent. The room
     * copy is marked so the team does not answer it twice.
     */
    public static function ajax_private() {
        $user_id = self::guard( 20 );
        $message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        global $wpdb;
        $table = self::table( 'messages' );
        $row = $message_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND user_id=%d AND status='visible'", $message_id, $user_id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $group = $row ? self::get_group( (int) $row->group_id ) : null;
        if ( ! $row || ! self::can_view( $group, $user_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Message introuvable.', 'delicat-assistant' ) ), 404 );
        }
        if ( ! class_exists( 'DSA2_Data', false ) ) dsa2_load_full_engine();
        $lang = isset( $_POST['language'] ) && in_array( $_POST['language'], array( 'fr', 'en', 'ht' ), true ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : 'fr'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $conv = DSA2_Data::get_or_create_conversation( $lang );
        if ( ! $conv ) wp_send_json_error( array( 'message' => __( 'Service momentanément indisponible.', 'delicat-assistant' ) ), 503 );
        $intro = 'en' === $lang ? 'Question from the group « %s »:' : ( 'ht' === $lang ? 'Kesyon nan gwoup « %s » :' : 'Question posée dans le groupe « %s » :' );
        // The body was masked when it entered the room; it stays masked here.
        $body = sprintf( $intro, $group->name ) . "\n" . (string) $row->body;
        DSA2_Data::add_message( (int) $conv->id, 'customer', $body, 'text', array( 'source' => 'group', 'group_id' => (int) $group->id, 'group_message_id' => (int) $row->id ), 'agent' );
        DSA2_Data::request_agent( (int) $conv->id );
        if ( 'question' === $row->message_type ) self::set_question_state( (int) $row->id, 'private', $user_id );
        wp_send_json_success( array( 'ok' => 1, 'conversation' => (int) $conv->id ) );
    }

    /** Staff answer from wp-admin: posted in the room as a reply quoting the question. */
    public static function answer_question( $question_id, $staff_id, $raw ) {
        global $wpdb;
        $table = self::table( 'messages' );
        $q = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND message_type='question' AND status='visible'", absint( $question_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $q || ! self::is_staff( $staff_id ) ) return 0;
        $group = self::get_group( (int) $q->group_id );
        if ( ! $group || 'active' !== $group->status ) return 0;
        $clean = self::sanitize_body( sanitize_textarea_field( (string) $raw ), $staff_id );
        if ( '' === $clean['body'] ) return 0;
        $qmeta = $q->meta ? json_decode( $q->meta, true ) : array();
        $meta = array( 'reply_to' => array(
            'id'      => (int) $q->id,
            'author'  => isset( $qmeta['author'] ) ? (string) $qmeta['author'] : self::display_name( (int) $q->user_id ),
            'excerpt' => DSA2_Compat::substr( (string) $q->body, 0, 140 ),
        ) );
        $id = self::insert_message( (int) $q->group_id, $staff_id, $clean['body'], 'answer', $meta, $clean['flags'] );
        if ( $id ) self::set_question_state( (int) $q->id, 'answered', $staff_id );
        return $id;
    }

    public static function mark_question( $question_id, $state, $staff_id ) {
        if ( ! self::is_staff( $staff_id ) ) return;
        self::set_question_state( absint( $question_id ), in_array( $state, array( 'answered', 'open' ), true ) ? $state : 'answered', $staff_id );
    }

    /** answered | private | open: kept in the flags column (staff-only), plus who and when in meta. */
    private static function set_question_state( $message_id, $state, $by ) {
        global $wpdb;
        $table = self::table( 'messages' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT flags,meta FROM $table WHERE id=%d AND message_type='question'", (int) $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $row ) return;
        $flags = array_diff( array_filter( explode( ',', (string) $row->flags ) ), array( 'answered', 'private' ) );
        if ( 'open' !== $state ) $flags[] = $state;
        $meta = $row->meta ? json_decode( $row->meta, true ) : array();
        if ( ! is_array( $meta ) ) $meta = array();
        $meta['question_state'] = array( 'state' => $state, 'by' => (int) $by, 'at' => current_time( 'mysql', 1 ) );
        $wpdb->update( $table, array( 'flags' => implode( ',', array_unique( $flags ) ), 'meta' => wp_json_encode( $meta ) ), array( 'id' => (int) $message_id ), array( '%s', '%s' ), array( '%d' ) );
    }

    /** Out-of-band e-mail to the support inbox: at most one per room every five minutes. */
    public static function send_question_alert( $group_id, $message_id ) {
        $group_id = absint( $group_id );
        if ( get_transient( 'dapg_qalert_' . $group_id ) ) {
            // Inside the 5-minute window: one digest when it ends instead of silence (message id 0 = digest).
            $at = (int) get_option( '_transient_timeout_dapg_qalert_' . $group_id );
            $when = max( time() + 60, $at ? $at + 5 : time() + 5 * MINUTE_IN_SECONDS );
            if ( absint( $message_id ) && ! wp_next_scheduled( 'dsa2_group_question_alert', array( $group_id, 0 ) ) ) wp_schedule_single_event( $when, 'dsa2_group_question_alert', array( $group_id, 0 ) );
            return;
        }
        set_transient( 'dapg_qalert_' . $group_id, 1, 5 * MINUTE_IN_SECONDS );
        if ( ! absint( $message_id ) ) {
            $open = self::open_questions( $group_id );
            $group = self::get_group( $group_id );
            $settings = (array) get_option( 'dap_settings', array() );
            $to = sanitize_email( $settings['notify_email'] ?? '' );
            if ( ! $to ) $to = get_option( 'admin_email' );
            if ( $open && $group && is_email( $to ) ) {
                /* translators: 1: number of questions, 2: group name */
                wp_mail( $to, sprintf( __( '%1$d question(s) à l’équipe en attente dans « %2$s »', 'delicat-assistant' ), $open, $group->name ), admin_url( 'admin.php?page=delicat-assistant-groups&tab=questions' ) );
            }
            return;
        }
        global $wpdb;
        $table = self::table( 'messages' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND group_id=%d", absint( $message_id ), $group_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $group = self::get_group( $group_id );
        if ( ! $row || ! $group ) return;
        $settings = (array) get_option( 'dap_settings', array() );
        if ( isset( $settings['notify_enabled'] ) && empty( $settings['notify_enabled'] ) ) return;
        $to = sanitize_email( $settings['notify_email'] ?? '' );
        if ( ! $to ) $to = get_option( 'admin_email' );
        if ( ! is_email( $to ) ) return;
        $meta = $row->meta ? json_decode( $row->meta, true ) : array();
        $author = is_array( $meta ) && isset( $meta['author'] ) ? (string) $meta['author'] : '';
        $preview = class_exists( 'DSA2_Data', false ) ? DSA2_Data::redact_secrets( (string) $row->body ) : (string) $row->body;
        $link = admin_url( 'admin.php?page=delicat-assistant-groups&tab=questions' );
        /* translators: %s: group name */
        $subject = sprintf( __( 'Question à l’équipe dans « %s »', 'delicat-assistant' ), $group->name );
        $text = $author . ' : ' . DSA2_Compat::substr( $preview, 0, 300 ) . "\n\n" . __( 'Répondre dans le groupe :', 'delicat-assistant' ) . ' ' . $link;
        wp_mail( $to, $subject, $text );
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
        $group = self::requested_group( $user_id );
        $last = isset( $_POST['last_id'] ) ? absint( $_POST['last_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
        $message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : 'other'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! in_array( $reason, array( 'scam', 'abuse', 'spam', 'other' ), true ) ) $reason = 'other';
        global $wpdb;
        $messages = self::table( 'messages' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $messages WHERE id=%d AND status<>'deleted'", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $group = $row ? self::get_group( (int) $row->group_id ) : null;
        // 7.0: only a member who can see that room may report in it.
        $member = $group ? self::membership( (int) $group->id, $user_id ) : null;
        if ( ! $row || ! self::can_view( $group, $user_id ) || ! $member || 'active' !== $member->status ) {
            wp_send_json_error( array( 'message' => __( 'Message introuvable.', 'delicat-assistant' ) ), 404 );
        }
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
        $distinct = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT reporter_id) FROM $reports WHERE message_id=%d AND status='open'", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Only accounts at least two days old count towards hiding (a burst of new accounts
        // cannot silence someone); every report still reaches the review queue.
        $established = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT r.reporter_id) FROM $reports r INNER JOIN {$wpdb->users} u ON u.ID=r.reporter_id WHERE r.message_id=%d AND r.status='open' AND u.user_registered<=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $message_id, gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS )
        ) );
        $threshold = max( 1, (int) $s['groups_report_hide'] );
        $protected = (int) $row->pinned || self::is_staff( (int) $row->user_id );
        $hide = ! $protected && $established >= $threshold && 'visible' === $row->status;
        $update = array( 'report_count' => $distinct );
        $format = array( '%d' );
        if ( $hide ) {
            $update['status'] = 'hidden';
            $format[] = '%s';
        }
        $wpdb->update( $messages, $update, array( 'id' => $message_id ), $format, array( '%d' ) );
        wp_send_json_success( array( 'reported' => 1, 'hidden' => $hide ? 1 : 0 ) );
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
        if ( $message_id ) {
            $owner = $wpdb->get_row( $wpdb->prepare( "SELECT group_id,user_id FROM $messages WHERE id=%d", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( ! $owner ) wp_send_json_error( array( 'message' => __( 'Message introuvable.', 'delicat-assistant' ) ), 404 );
            $group_id = (int) $owner->group_id; // never trust a group id that disagrees with the message
            if ( ! $target ) $target = (int) $owner->user_id;
        }

        switch ( $action ) {
            case 'delete':
                self::delete_message_files( $wpdb->get_results( $wpdb->prepare( "SELECT meta FROM $messages WHERE id=%d AND message_type='image'", $message_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
            case 'answered':
            case 'reopen':
                if ( $message_id ) self::set_question_state( $message_id, 'answered' === $action ? 'answered' : 'open', $user_id );
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
        $group = self::requested_group( $user_id );
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

    /**
     * Deletes the files behind image messages. Only our own upload names (dapg- plus 20
     * characters) inside the uploads folder are touched, whatever host or scheme the
     * stored URL carries (the site may have moved to https or another domain since).
     */
    public static function delete_message_files( $rows ) {
        $uploads = wp_get_upload_dir();
        $base = realpath( $uploads['basedir'] );
        if ( ! $base ) return;
        $base_path = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
        foreach ( (array) $rows as $row ) {
            $meta = is_object( $row ) && $row->meta ? json_decode( $row->meta, true ) : array();
            if ( empty( $meta['url'] ) ) continue;
            $path = (string) wp_parse_url( (string) $meta['url'], PHP_URL_PATH );
            if ( ! preg_match( '#/dapg-[A-Za-z0-9]{20}\.(?:jpg|png|gif|webp)$#', $path ) ) continue;
            $relative = ( $base_path && 0 === strpos( $path, $base_path ) ) ? substr( $path, strlen( $base_path ) ) : '';
            if ( '' === $relative || false !== strpos( $relative, '..' ) ) continue;
            $file = realpath( $base . '/' . ltrim( $relative, '/' ) );
            if ( $file && 0 === strpos( $file, $base . DIRECTORY_SEPARATOR ) && is_file( $file ) ) wp_delete_file( $file );
        }
    }

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
        self::delete_message_files( $wpdb->get_results( "SELECT meta FROM $messages WHERE id IN ($in) AND message_type='image'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DELETE FROM " . self::table( 'reports' ) . " WHERE message_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DELETE FROM $messages WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * 7.0: account deletion (Google Play flow and WordPress privacy eraser) also removes
     * the customer's community data: their messages and images, memberships, reports and
     * group profile. Returns the number of messages removed.
     */
    public static function erase_user( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) return 0;
        global $wpdb;
        $messages = self::table( 'messages' );
        self::delete_message_files( $wpdb->get_results( $wpdb->prepare( "SELECT meta FROM $messages WHERE user_id=%d AND message_type='image'", $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $groups = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT group_id FROM " . self::table( 'members' ) . " WHERE user_id=%d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "DELETE r FROM " . self::table( 'reports' ) . " r INNER JOIN $messages m ON m.id=r.message_id WHERE m.user_id=%d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $messages WHERE user_id=%d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->delete( self::table( 'reports' ), array( 'reporter_id' => $user_id ), array( '%d' ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'members' ) . " WHERE user_id=%d AND status<>'banned'", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- room bans stay
        delete_user_meta( $user_id, 'dap_group_name' ); // dap_groups_banned (global ban) is kept on purpose
        foreach ( $groups as $group_id ) self::refresh_counts( (int) $group_id );
        return $removed;
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
            'icon'            => '',
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
