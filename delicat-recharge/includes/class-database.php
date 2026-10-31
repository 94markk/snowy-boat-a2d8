<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Delicat_Database {

    const TABLE = 'delicat_recharges';

    /** Maximum recharge amount allowed, in HTG. */
    const MAX_MONTANT = 100000;

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        // IMPORTANT: dbDelta requires "CREATE TABLE" (NOT "IF NOT EXISTS") to be
        // able to ALTER an existing table and add any missing columns. It is also
        // strict about formatting: two spaces after PRIMARY KEY, each field on its
        // own line, and KEY definitions matching its expected style.
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_email varchar(200) NOT NULL DEFAULT '',
            tab varchar(20) NOT NULL DEFAULT 'manuel',
            methode varchar(60) NOT NULL DEFAULT '',
            montant decimal(14,2) NOT NULL DEFAULT 0.00,
            trans_code varchar(200) NOT NULL DEFAULT '',
            nc_pin varchar(12) NOT NULL DEFAULT '',
            nc_sender varchar(30) NOT NULL DEFAULT '',
            preuve_url varchar(600) NOT NULL DEFAULT '',
            statut varchar(30) NOT NULL DEFAULT 'en_attente',
            wallet_credited tinyint(1) NOT NULL DEFAULT 0,
            webhook_sent tinyint(1) NOT NULL DEFAULT 0,
            note_admin text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY statut (statut),
            KEY tab (tab)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // ── TransCode spend ledger ──────────────────────────────────────────
        // Application-level checks can always lose a race under enough load.
        // This table makes "one TransCode = one credit" a guarantee enforced by
        // the DATABASE: the normalized code is a UNIQUE key, so a second credit
        // for the same code cannot physically be inserted, no matter how many
        // requests arrive at once or which path they come through.
        $ledger = $wpdb->prefix . 'delicat_code_spent';
        $sql2 = "CREATE TABLE {$ledger} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code_norm varchar(190) NOT NULL,
            recharge_id bigint(20) unsigned NOT NULL DEFAULT 0,
            montant decimal(14,2) NOT NULL DEFAULT 0.00,
            source varchar(40) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code_norm (code_norm),
            KEY recharge_id (recharge_id)
        ) {$charset};";
        dbDelta( $sql2 );
    }

    /**
     * Claim a TransCode permanently. Returns TRUE only for the very first
     * caller; every later attempt returns FALSE because the UNIQUE index
     * rejects the duplicate insert. This is the authoritative "used once,
     * never again" gate — call it immediately before crediting.
     */
    public static function claim_code( string $trans_code, int $recharge_id = 0, float $montant = 0, string $source = '' ): bool {
        global $wpdb;
        $code = class_exists( 'Delicat_Natcash_Auto' )
            ? Delicat_Natcash_Auto::norm_code( $trans_code )
            : strtoupper( str_replace( [ ' ', '-' ], '', trim( $trans_code ) ) );
        if ( $code === '' ) return true; // nothing to guard (manual, no code)

        $ledger = $wpdb->prefix . 'delicat_code_spent';

        // INSERT IGNORE: succeeds once, silently fails on the duplicate.
        $done = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$ledger} (code_norm, recharge_id, montant, source, created_at)
             VALUES (%s, %d, %f, %s, %s)",
            $code, $recharge_id, $montant, $source, current_time( 'mysql' )
        ) );

        return ( (int) $done === 1 );
    }

    /** Release a claim when a credit fails, so the code can be retried. */
    public static function release_code( string $trans_code ): void {
        global $wpdb;
        $code = class_exists( 'Delicat_Natcash_Auto' )
            ? Delicat_Natcash_Auto::norm_code( $trans_code )
            : strtoupper( str_replace( [ ' ', '-' ], '', trim( $trans_code ) ) );
        if ( $code === '' ) return;
        $wpdb->delete( $wpdb->prefix . 'delicat_code_spent', [ 'code_norm' => $code ] );
    }

    /** Has this TransCode already been spent? */
    public static function code_spent( string $trans_code ): bool {
        global $wpdb;
        $code = class_exists( 'Delicat_Natcash_Auto' )
            ? Delicat_Natcash_Auto::norm_code( $trans_code )
            : strtoupper( str_replace( [ ' ', '-' ], '', trim( $trans_code ) ) );
        if ( $code === '' ) return false;
        $ledger = $wpdb->prefix . 'delicat_code_spent';
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$ledger} WHERE code_norm = %s", $code ) );
    }

    public static function insert( array $data ): int|WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $ok = $wpdb->insert( $table, [
            'user_id'    => absint( $data['user_id']    ?? 0 ),
            'user_email' => sanitize_email( $data['user_email'] ?? '' ),
            'tab'        => sanitize_text_field( $data['tab']    ?? 'manuel' ),
            'methode'    => sanitize_text_field( $data['methode'] ),
            'montant'    => floatval( $data['montant'] ),
            'trans_code' => sanitize_text_field( $data['trans_code'] ?? '' ),
            'nc_pin'     => sanitize_text_field( $data['nc_pin'] ?? '' ),
            'nc_sender'  => sanitize_text_field( $data['nc_sender'] ?? '' ),
            'preuve_url' => esc_url_raw( $data['preuve_url'] ?? '' ),
            'statut'     => sanitize_text_field( $data['statut'] ?? 'en_attente' ),
        ], [ '%d','%s','%s','%s','%f','%s','%s','%s','%s','%s' ] );

        if ( $ok === false ) {
            return new WP_Error( 'db_error', $wpdb->last_error );
        }

        $id = (int) $wpdb->insert_id;
        do_action(
            'delicat_recharge_record_created',
            $id,
            absint( $data['user_id'] ?? 0 ),
            sanitize_key( $data['statut'] ?? 'en_attente' )
        );

        return $id;
    }

    public static function update( int $id, array $data ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $changed = $wpdb->update( $table, $data, [ 'id' => $id ] );
        if ( $changed > 0 ) {
            do_action(
                'delicat_recharge_record_updated',
                absint( $id ),
                [
                    'status'         => isset( $data['statut'] ) ? sanitize_key( $data['statut'] ) : '',
                    'wallet_changed' => array_key_exists( 'wallet_credited', $data ),
                ]
            );
        }
    }

    public static function get( int $id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    /**
     * Check whether a transaction code has already been used (any status).
     * Comparison is case-insensitive and trims whitespace to prevent trivial bypass.
     */
    public static function trans_code_exists( string $trans_code ): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $code  = trim( $trans_code );
        if ( $code === '' ) return false;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE REPLACE(REPLACE(UPPER(TRIM(trans_code)),' ',''),'-','') = %s",
            strtoupper( str_replace( [ ' ', '-' ], '', $code ) )
        ) );
        return $count > 0;
    }

    public static function get_all( array $args = [] ): array {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $where  = '1=1';
        $values = [];

        if ( ! empty( $args['statut'] ) ) {
            $where   .= ' AND statut = %s';
            $values[] = $args['statut'];
        }
        if ( ! empty( $args['tab'] ) ) {
            $where   .= ' AND tab = %s';
            $values[] = $args['tab'];
        }
        if ( ! empty( $args['user_id'] ) ) {
            $where   .= ' AND user_id = %d';
            $values[] = absint( $args['user_id'] );
        }

        $limit    = absint( $args['limit']  ?? 100 );
        $offset   = absint( $args['offset'] ?? 0 );
        $values[] = $limit;
        $values[] = $offset;

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        return $wpdb->get_results( empty( $values ) ? $sql : $wpdb->prepare( $sql, $values ) ) ?: [];
    }

    public static function count_by_statut(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results( "SELECT statut, COUNT(*) as cnt FROM {$table} GROUP BY statut" );
        $out   = [];
        foreach ( $rows as $r ) $out[ $r->statut ] = (int) $r->cnt;
        return $out;
    }

    public static function count_for_user( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
    }

    public static function get_for_user( int $user_id, int $limit = 100 ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit ) ) ?: [];
    }

    /* ---------------- Service on/off toggles ---------------- */

    /** The 3 client-facing services that can be toggled. */
    const SERVICES = [ 'moncash', 'natcash_tc' ]; // 'natcash' (PIN) retired

    /** Current enabled state for all services (default: all ON). */
    public static function services(): array {
        // 'natcash' (PIN flow) is retired — Natcash validates by TransCode only.
        // It is force-disabled here so the tab cannot reappear on the form even
        // if an old saved setting still has it enabled.
        $defaults = [ 'moncash' => true, 'natcash_tc' => true ];
        $saved    = get_option( 'delicat_services_enabled', [] );
        if ( ! is_array( $saved ) ) $saved = [];
        $out = [];
        foreach ( $defaults as $k => $def ) {
            $out[ $k ] = array_key_exists( $k, $saved ) ? (bool) $saved[ $k ] : $def;
        }
        return $out;
    }

    /** Is a given service currently enabled? Unknown/manuel = always allowed. */
    public static function service_on( string $tab ): bool {
        if ( ! in_array( $tab, self::SERVICES, true ) ) return true;
        $s = self::services();
        return ! empty( $s[ $tab ] );
    }

    /** Enable/disable one service. Returns the full new state. */
    public static function set_service( string $tab, bool $on ): array {
        $s = self::services();
        if ( in_array( $tab, self::SERVICES, true ) ) {
            $previous = ! empty( $s[ $tab ] );
            $s[ $tab ] = $on;
            update_option( 'delicat_services_enabled', $s, false );
            if ( $previous !== $on ) {
                do_action( 'delicat_recharge_services_changed', sanitize_key( $tab ), (bool) $on );
            }
        }
        return $s;
    }
}
