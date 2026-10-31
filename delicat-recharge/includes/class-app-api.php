<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DELICAT APP API — secure mobile admin endpoints (approve/reject + live list).
 *
 * Security model (because these endpoints MOVE MONEY):
 *  - Per-device credentials: each phone has a device_id + secret token pair.
 *    Tokens are stored HASHED in WordPress (wp_hash). The plain token is shown
 *    once when you create the device, then never again.
 *  - Read (list/stats): device_id + token sent as headers (X-Delicat-Device /
 *    X-Delicat-Token), NOT in the URL — so they never land in server logs.
 *  - Write (approve/reject): in addition to device auth, each action carries an
 *    HMAC signature over (device_id|action|id|timestamp). A leaked read view
 *    cannot be replayed to move money without the device's secret.
 *  - Timestamp window: signed actions older than 120s are rejected (anti-replay).
 *  - Per-device enable/revoke + last-seen, manageable from the admin console.
 *  - Full audit: every approve/reject records which device acted, in note_admin.
 *  - HTTPS required (your site is HTTPS).
 *
 * Devices are stored in option 'delicat_app_devices' as:
 *   [ device_id => [ 'label'=>.., 'token_hash'=>.., 'enabled'=>1, 'last_seen'=>.. ] ]
 */
class Delicat_App_Api {

    const OPT_DEVICES = 'delicat_app_devices';
    const SIG_WINDOW  = 120; // seconds

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    /* ---------------- Device storage helpers ---------------- */

    public static function devices(): array {
        $d = get_option( self::OPT_DEVICES, [] );
        return is_array( $d ) ? $d : [];
    }

    public static function save_devices( array $d ): void {
        update_option( self::OPT_DEVICES, $d, false );
    }

    /** Create a device, return [device_id, plain_token, sign_key] (shown once). */
    public static function create_device( string $label ): array {
        $devices = self::devices();
        $device_id = 'dev_' . wp_generate_password( 8, false, false );
        $token     = wp_generate_password( 40, false, false );
        $sign_key  = wp_generate_password( 40, false, false );
        $devices[ $device_id ] = [
            'label'      => sanitize_text_field( $label ),
            'token_hash' => wp_hash( $token ),
            'sign_key'   => $sign_key, // used as HMAC key; the app holds the same value
            'enabled'    => 1,
            'created'    => current_time( 'mysql' ),
            'last_seen'  => '',
        ];
        self::save_devices( $devices );
        return [ $device_id, $token, $sign_key ];
    }

    public static function revoke_device( string $device_id ): void {
        $devices = self::devices();
        unset( $devices[ $device_id ] );
        self::save_devices( $devices );
    }

    /* ---------------- Auth ---------------- */

    /** Validate device_id + token from request headers. Returns device_id or ''. */
    private function auth_device( WP_REST_Request $request ): string {
        // If this site runs under a client license, it must be valid first.
        if ( class_exists( 'Delicat_Client_License' )
             && Delicat_Client_License::is_configured()
             && ! Delicat_Client_License::valid() ) {
            return ''; // license expired/invalid → no data served
        }
        $device_id = (string) $request->get_header( 'x-delicat-device' );
        $token     = (string) $request->get_header( 'x-delicat-token' );
        if ( $device_id === '' || $token === '' ) return '';

        $devices = self::devices();
        if ( ! isset( $devices[ $device_id ] ) ) return '';
        $dev = $devices[ $device_id ];
        if ( empty( $dev['enabled'] ) ) return '';
        if ( ! hash_equals( (string) $dev['token_hash'], wp_hash( $token ) ) ) return '';

        // Update last_seen (cheap, no autoload).
        $devices[ $device_id ]['last_seen'] = current_time( 'mysql' );
        self::save_devices( $devices );
        return $device_id;
    }

    /** Verify the HMAC signature on a write action. */
    private function verify_signature( string $device_id, string $unused, array $p ): bool {
        $devices = self::devices();
        if ( ! isset( $devices[ $device_id ] ) ) return false;
        $key = (string) ( $devices[ $device_id ]['sign_key'] ?? '' );
        if ( $key === '' ) return false;

        $ts = (int) ( $p['ts'] ?? 0 );
        if ( abs( time() - $ts ) > self::SIG_WINDOW ) return false; // stale/future

        $base = $device_id . '|' . $p['action'] . '|' . $p['id'] . '|' . $ts;
        $expected = hash_hmac( 'sha256', $base, $key );
        if ( ! hash_equals( $expected, (string) ( $p['sig'] ?? '' ) ) ) return false;

        // SECURITY: single-use signatures. Without this, a captured request can
        // be replayed within SIG_WINDOW to trigger the same money action twice
        // (a real double-credit vector). Each signature is burned on first use.
        $seen_key = 'dlc_sig_' . md5( $device_id . '|' . $expected );
        if ( get_transient( $seen_key ) ) return false;   // already used
        set_transient( $seen_key, 1, self::SIG_WINDOW * 2 );

        return true;
    }

    /* ---------------- Routes ---------------- */

    public function routes(): void {
        register_rest_route( 'delicat/v1', '/app/list', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_tx' ],
            'permission_callback' => function ( $r ) { return $this->auth_device( $r ) !== ''; },
        ] );

        register_rest_route( 'delicat/v1', '/app/action', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'do_action_tx' ],
            'permission_callback' => function ( $r ) { return $this->auth_device( $r ) !== ''; },
        ] );

        // Manual TransCode entry (anti-scammer): credit a pending recharge by
        // entering the real TransCode. Two modes:
        //   verify = 1 → must match a received SMS log before crediting
        //   verify = 0 → credit immediately (admin already verified manually)
        register_rest_route( 'delicat/v1', '/app/manual-code', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'manual_code' ],
            'permission_callback' => function ( $r ) { return $this->auth_device( $r ) !== ''; },
        ] );

        // Pre-register (burn) a TransCode as USED — anti double-dip. Does NOT
        // need an existing recharge. mode=block records+blocks; mode=credit
        // records+credits the wallet now. Signed like approve/reject.
        register_rest_route( 'delicat/v1', '/app/manual-entry', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'manual_entry' ],
            'permission_callback' => function ( $r ) { return $this->auth_device( $r ) !== ''; },
        ] );

        // Client transaction history (search by email/name, or list all).
        register_rest_route( 'delicat/v1', '/app/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'history' ],
            'permission_callback' => function ( $r ) { return $this->auth_device( $r ) !== ''; },
        ] );

        // Clients list with aggregate stats (client-first browsing in the app).
        register_rest_route( 'delicat/v1', '/app/clients', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'clients' ],
            'permission_callback' => function ( $r ) { return $this->auth_device( $r ) !== ''; },
        ] );
    }

    /**
     * List every client that has transactions, with aggregate stats.
     * Optional ?q= filters by email. Used by the app's Clients section; tapping
     * a client then calls /app/history?q=<email> for their full history.
     */
    public function clients( WP_REST_Request $request ) {
        global $wpdb;
        if ( $this->auth_device( $request ) === '' ) {
            return new WP_Error( 'auth', 'Non autorisé.', [ 'status' => 401 ] );
        }

        $tbl = $wpdb->prefix . 'delicat_recharges';
        $q   = sanitize_text_field( (string) $request->get_param( 'q' ) );

        $base = "SELECT user_email, user_id,
                        COUNT(*) AS total_tx,
                        SUM(CASE WHEN wallet_credited = 1 THEN montant ELSE 0 END) AS total_credite,
                        SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) AS en_attente,
                        MAX(created_at) AS derniere
                 FROM {$tbl} ";

        if ( $q !== '' ) {
            $like = '%' . $wpdb->esc_like( $q ) . '%';
            $rows = $wpdb->get_results( $wpdb->prepare(
                $base . "WHERE user_email LIKE %s GROUP BY user_email, user_id ORDER BY derniere DESC LIMIT 200",
                $like ) );
        } else {
            $rows = $wpdb->get_results(
                $base . "GROUP BY user_email, user_id ORDER BY derniere DESC LIMIT 200" );
        }

        $out = [];
        foreach ( (array) $rows as $r ) {
            $name = '';
            if ( (int) $r->user_id ) {
                $u = get_userdata( (int) $r->user_id );
                if ( $u ) $name = $u->display_name;
            }
            $out[] = [
                'email'         => $r->user_email,
                'user_id'       => (int) $r->user_id,
                'name'          => $name,
                'total_tx'      => (int) $r->total_tx,
                'total_credite' => (float) $r->total_credite,
                'en_attente'    => (int) $r->en_attente,
                'derniere'      => $r->derniere,
            ];
        }
        return rest_ensure_response( [ 'ok' => true, 'clients' => $out ] );
    }

    /* ---------------- Manual TransCode entry ---------------- */

    public function manual_code( WP_REST_Request $request ) {
        global $wpdb;
        $device_id = $this->auth_device( $request );
        if ( $device_id === '' ) return new WP_Error( 'auth', 'Non autorisé.', [ 'status' => 401 ] );

        $id     = (int) $request->get_param( 'id' );
        $code   = sanitize_text_field( (string) $request->get_param( 'trans_code' ) );
        $verify = (int) $request->get_param( 'verify' ) === 1;
        $tbl    = $wpdb->prefix . 'delicat_recharges';

        // Signature: base = device_id|manual|id|ts (same scheme as approve/reject).
        $p = [
            'action' => 'manual',
            'id'     => $id,
            'ts'     => absint( $request->get_param( 'ts' ) ),
            'sig'    => (string) $request->get_param( 'sig' ),
        ];
        if ( ! $this->verify_signature( $device_id, '', $p ) ) {
            return new WP_Error( 'bad_sig', 'Signature invalide ou expirée.', [ 'status' => 403 ] );
        }

        if ( $id <= 0 || $code === '' ) {
            return new WP_Error( 'bad', 'ID et TransCode requis.', [ 'status' => 400 ] );
        }

        $record = Delicat_Database::get( $id );
        if ( ! $record ) return new WP_Error( 'notfound', 'Demande introuvable.', [ 'status' => 404 ] );

        // Block if this TransCode was already used anywhere (anti-reuse).
        $dupe = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl} WHERE REPLACE(REPLACE(UPPER(TRIM(trans_code)),' ',''),'-','') = %s AND id <> %d AND wallet_credited = 1",
            Delicat_Natcash_Auto::norm_code( $code ), $id
        ) );
        if ( $dupe > 0 ) {
            return rest_ensure_response( [ 'ok' => false, 'message' => '⛔ Ce TransCode a déjà été utilisé.' ] );
        }

        // Verify mode: the code must strictly match a real received SMS —
        // same provider, exact TransCode, exact amount (anti-scammer).
        if ( $verify ) {
            $chk = class_exists( 'Delicat_Natcash_Auto' )
                ? Delicat_Natcash_Auto::verify_received( (string) $record->tab, $code, (float) $record->montant )
                : [ 'ok' => false, 'reason' => 'Vérification indisponible.' ];
            if ( empty( $chk['ok'] ) ) {
                return rest_ensure_response( [
                    'ok'      => false,
                    'message' => '⚠️ ' . ( $chk['reason'] ?? 'Vérification échouée.' ),
                ] );
            }
        }

        // AUTHORITATIVE ONE-TIME GATE (DB UNIQUE index). The $dupe SELECT above
        // is a read-then-write and can lose a race; this cannot.
        if ( ! Delicat_Database::claim_code( $code, $id, (float) $record->montant, 'app_manual' ) ) {
            return rest_ensure_response( [ 'ok' => false, 'message' => '⛔ Ce TransCode a déjà été crédité.' ] );
        }

        // ATOMIC CLAIM to avoid any double-credit.
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$tbl} SET wallet_credited = 1, statut = 'approuve', trans_code = %s
             WHERE id = %d AND wallet_credited = 0",
            $code, $id
        ) );
        if ( $claimed !== 1 ) {
            Delicat_Database::release_code( $code );
            return rest_ensure_response( [ 'ok' => false, 'message' => 'Déjà traité.' ] );
        }

        $user_id = (int) $record->user_id;
        if ( ! $user_id ) {
            $u = get_user_by( 'email', $record->user_email );
            $user_id = $u ? $u->ID : 0;
        }
        if ( ! $user_id ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET wallet_credited=0, statut='en_attente' WHERE id=%d", $id ) );
            Delicat_Database::release_code( $code );
            return rest_ensure_response( [ 'ok' => false, 'message' => 'Utilisateur introuvable.' ] );
        }

        $res = Delicat_TeraWallet::credit( $user_id, (float) $record->montant,
            sprintf( 'Recharge #%d — TransCode manuel', $id ), $id );
        if ( is_wp_error( $res ) ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET wallet_credited=0, statut='en_attente' WHERE id=%d", $id ) );
            Delicat_Database::release_code( $code );
            return rest_ensure_response( [ 'ok' => false, 'message' => 'Échec crédit: ' . $res->get_error_message() ] );
        }

        Delicat_Database::update( $id, [
            'note_admin' => trim( (string) $record->note_admin . "\n[MANUEL TransCode " . ( $verify ? 'vérifié' : 'direct' ) . " " . current_time('mysql') . "]" ),
        ] );
        do_action( 'delicat_recharge_approved', $id );

        return rest_ensure_response( [
            'ok' => true,
            'message' => sprintf( '✅ %.2f HTG crédités (%s).', $record->montant, $verify ? 'vérifié SMS' : 'manuel' ),
        ] );
    }

    /* ---------------- Manual pre-register (burn a TransCode) ---------------- */

    /**
     * Pre-register a TransCode as USED, WITHOUT needing an existing pending
     * recharge. Use when a client paid you off-site and you want to stop them
     * re-submitting the same code on the website to be credited a second time.
     *
     * POST params:
     *   trans_code (required)  the code to burn
     *   montant    (required)  amount in HTG (for the record)
     *   tab        moncash|natcash|manuel   (default manuel)
     *   email      client email — REQUIRED only when mode=credit
     *   mode       'block'  → record + block the code (no wallet credit)
     *              'credit' → record AND credit the client's wallet now
     *   ts, sig    device HMAC over  device_id | mentry | trans_code | ts
     */
    public function manual_entry( WP_REST_Request $request ) {
        $device_id = $this->auth_device( $request );
        if ( $device_id === '' ) return new WP_Error( 'auth', 'Non autorisé.', [ 'status' => 401 ] );

        $trans_code = trim( sanitize_text_field( (string) $request->get_param( 'trans_code' ) ) );
        $montant    = (float) $request->get_param( 'montant' );
        $tab        = sanitize_text_field( (string) $request->get_param( 'tab' ) );
        $email      = sanitize_email( (string) $request->get_param( 'email' ) );
        $mode_raw   = sanitize_text_field( (string) $request->get_param( 'mode' ) );
        $mode       = in_array( $mode_raw, [ 'credit', 'verify' ], true ) ? $mode_raw : 'block';

        // Signature: base = device_id | mentry | trans_code | ts (same scheme
        // as approve/reject, using the code in place of the numeric id).
        $p = [
            'action' => 'mentry',
            'id'     => $trans_code,
            'ts'     => absint( $request->get_param( 'ts' ) ),
            'sig'    => (string) $request->get_param( 'sig' ),
        ];
        if ( ! $this->verify_signature( $device_id, '', $p ) ) {
            return new WP_Error( 'bad_sig', 'Signature invalide ou expirée.', [ 'status' => 403 ] );
        }

        if ( ! in_array( $tab, [ 'moncash', 'natcash', 'manuel' ], true ) ) $tab = 'manuel';
        if ( $trans_code === '' ) {
            return rest_ensure_response( [ 'ok' => false, 'message' => 'TransCode requis.' ] );
        }
        if ( $montant <= 0 || $montant > Delicat_Database::MAX_MONTANT ) {
            return rest_ensure_response( [ 'ok' => false, 'message' => 'Montant invalide.' ] );
        }

        // Already recorded anywhere? Then it's already blocked — nothing to do.
        if ( Delicat_Database::trans_code_exists( $trans_code ) ) {
            return rest_ensure_response( [ 'ok' => false, 'message' => '⛔ Ce code est déjà enregistré (déjà bloqué pour le site).' ] );
        }

        $user_id = 0;
        if ( $email ) {
            $u = get_user_by( 'email', $email );
            if ( $u ) $user_id = (int) $u->ID;
        }

        $devices = self::devices();
        $label   = $devices[ $device_id ]['label'] ?? $device_id;

        // VERIFY mode: only proceed if a real received SMS strictly matches
        // provider + exact TransCode + exact amount. Otherwise stop (nothing is
        // recorded or credited) — this is the anti-scammer gate.
        if ( $mode === 'verify' ) {
            if ( ! $user_id ) {
                return rest_ensure_response( [ 'ok' => false, 'message' => "Email d'un client existant requis pour vérifier & créditer." ] );
            }
            $chk = class_exists( 'Delicat_Natcash_Auto' )
                ? Delicat_Natcash_Auto::verify_received( $tab, $trans_code, $montant )
                : [ 'ok' => false, 'reason' => 'Vérification indisponible.' ];
            if ( empty( $chk['ok'] ) ) {
                return rest_ensure_response( [ 'ok' => false, 'message' => '⚠️ ' . ( $chk['reason'] ?? 'Vérification échouée.' ) ] );
            }
            $mode = 'credit'; // verified true → credit
        }

        $row = [
            'user_id'    => $user_id,
            'user_email' => $email,
            'tab'        => $tab,
            'methode'    => $tab,
            'montant'    => $montant,
            'trans_code' => $trans_code,
            'statut'     => 'approuve',
        ];

        if ( $mode === 'credit' ) {
            if ( ! $user_id ) {
                return rest_ensure_response( [ 'ok' => false, 'message' => "Email d'un client existant requis pour créditer." ] );
            }
            $id = Delicat_Database::insert( $row );
            if ( is_wp_error( $id ) ) {
                return rest_ensure_response( [ 'ok' => false, 'message' => 'Erreur DB: ' . $id->get_error_message() ] );
            }
            $res = Delicat_TeraWallet::credit( $user_id, $montant,
                sprintf( 'Recharge #%d — saisie manuelle (app)', $id ), (int) $id );
            if ( is_wp_error( $res ) ) {
                Delicat_Database::update( (int) $id, [
                    'note_admin' => sprintf( '⚠️ [APP %s] crédit échoué, code bloqué — %s', $label, current_time( 'mysql' ) ),
                ] );
                return rest_ensure_response( [ 'ok' => false, 'message' => 'Code enregistré (bloqué) mais crédit échoué: ' . $res->get_error_message() ] );
            }
            Delicat_Database::update( (int) $id, [
                'wallet_credited' => 1,
                'note_admin'      => sprintf( '💳 [APP %s] saisie manuelle%s — wallet crédité — %s', $label, ( $mode_raw === 'verify' ? ' (vérifié SMS)' : '' ), current_time( 'mysql' ) ),
            ] );
            do_action( 'delicat_recharge_approved', (int) $id );
            return rest_ensure_response( [
                'ok'      => true,
                'message' => sprintf( '✅ %.2f HTG crédités. Code « %s » bloqué.', $montant, $trans_code ),
            ] );
        }

        // mode = block: record + burn, no credit.
        $id = Delicat_Database::insert( $row );
        if ( is_wp_error( $id ) ) {
            return rest_ensure_response( [ 'ok' => false, 'message' => 'Erreur DB: ' . $id->get_error_message() ] );
        }
        Delicat_Database::update( (int) $id, [
            'wallet_credited' => 1,
            'note_admin'      => sprintf( '🔒 [BLOCAGE-MANUEL] [APP %s] déjà validé hors-ligne, wallet non crédité par le plugin — %s', $label, current_time( 'mysql' ) ),
        ] );
        return rest_ensure_response( [
            'ok'      => true,
            'message' => sprintf( '🔒 Code « %s » enregistré et bloqué. Toute soumission sur le site sera refusée.', $trans_code ),
        ] );
    }

    /** Check the Natcash-auto SMS log for a matching TransCode + amount. */
    private function transcode_in_sms_log( string $code, float $amount ): bool {
        $entries = get_option( 'delicat_natcash_log', [] );
        if ( ! is_array( $entries ) ) return false;
        foreach ( $entries as $e ) {
            $sms = (string) ( $e['sms'] ?? '' );
            if ( $sms === '' ) continue;
            // Match the code appearing in the SMS text.
            if ( stripos( $sms, $code ) !== false ) {
                // Also require the amount to appear (loose check).
                $amt = number_format( $amount, 0, '', '' );
                if ( strpos( preg_replace( '/[,\.]/', '', $sms ), $amt ) !== false
                     || strpos( $sms, number_format( $amount, 2 ) ) !== false ) {
                    return true;
                }
            }
        }
        return false;
    }

    /* ---------------- Client history ---------------- */

    public function history( WP_REST_Request $request ) {
        global $wpdb;
        $device_id = $this->auth_device( $request );
        if ( $device_id === '' ) return new WP_Error( 'auth', 'Non autorisé.', [ 'status' => 401 ] );

        $q   = sanitize_text_field( (string) $request->get_param( 'q' ) );
        $tbl = $wpdb->prefix . 'delicat_recharges';

        if ( $q !== '' ) {
            $like = '%' . $wpdb->esc_like( $q ) . '%';
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$tbl} WHERE user_email LIKE %s OR trans_code LIKE %s
                 ORDER BY created_at DESC LIMIT 100", $like, $like
            ) );
        } else {
            $rows = $wpdb->get_results( "SELECT * FROM {$tbl} ORDER BY created_at DESC LIMIT 100" );
        }

        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'id'         => (int) $r->id,
                'email'      => $r->user_email,
                'montant'    => (float) $r->montant,
                'tab'        => $r->tab,
                'statut'     => $r->statut,
                'trans_code' => $r->trans_code,
                'created_at' => $r->created_at,
            ];
        }
        return rest_ensure_response( [ 'ok' => true, 'transactions' => $out ] );
    }

    /* ---------------- List endpoint ---------------- */

    public function list_tx( WP_REST_Request $request ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'delicat_recharges';
        $filter = sanitize_text_field( (string) $request->get_param( 'filter' ) ); // pending|approved|rejected|all

        $where = '1=1';
        if ( $filter === 'pending' )      $where = "statut = 'en_attente'";
        elseif ( $filter === 'approved' ) $where = "statut = 'approuve'";
        elseif ( $filter === 'rejected' ) $where = "statut = 'rejete'";

        $rows = $wpdb->get_results( "SELECT id, user_email, montant, tab, statut, trans_code, created_at
            FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT 50" );

        $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE statut='en_attente'" );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'id'         => (int) $r->id,
                'email'      => $r->user_email,
                'montant'    => (float) $r->montant,
                'tab'        => $r->tab,
                'statut'     => $r->statut,
                'trans_code' => $r->trans_code,
                'date'       => $r->created_at,
            ];
        }
        return rest_ensure_response( [
            'pending_count' => $pending,
            'items'         => $out,
            'currency'      => 'HTG',
            'server_time'   => time(),
        ] );
    }

    /* ---------------- Action endpoint (approve/reject) ---------------- */

    public function do_action_tx( WP_REST_Request $request ) {
        $device_id = $this->auth_device( $request );
        if ( $device_id === '' ) {
            return new WP_Error( 'forbidden', 'Appareil non autorisé.', [ 'status' => 403 ] );
        }

        $p = [
            'action' => sanitize_text_field( (string) $request->get_param( 'action' ) ),
            'id'     => absint( $request->get_param( 'id' ) ),
            'ts'     => absint( $request->get_param( 'ts' ) ),
            'sig'    => (string) $request->get_param( 'sig' ),
        ];

        if ( ! in_array( $p['action'], [ 'approve', 'reject' ], true ) || ! $p['id'] ) {
            return new WP_Error( 'bad_request', 'Requête invalide.', [ 'status' => 400 ] );
        }

        // Signature check — this is what stops a leaked read-token from moving money.
        if ( ! $this->verify_signature( $device_id, '', $p ) ) {
            return new WP_Error( 'bad_sig', 'Signature invalide ou expirée.', [ 'status' => 403 ] );
        }

        $record = Delicat_Database::get( $p['id'] );
        if ( ! $record ) {
            return new WP_Error( 'not_found', 'Demande introuvable.', [ 'status' => 404 ] );
        }

        $devices = self::devices();
        $label   = $devices[ $device_id ]['label'] ?? $device_id;
        $stamp   = sprintf( '[APP %s — %s]', $label, current_time( 'mysql' ) );

        if ( $p['action'] === 'approve' ) {
            if ( $record->statut === 'approuve' || (int) $record->wallet_credited === 1 ) {
                return rest_ensure_response( [ 'ok' => true, 'message' => 'Déjà approuvé.', 'noop' => true ] );
            }

            // ATOMIC CLAIM: prevents double-credit if the SMS auto-verify or
            // another device approves the same recharge at the same time.
            global $wpdb;
            $tbl = $wpdb->prefix . 'delicat_recharges';
            $claimed = $wpdb->query( $wpdb->prepare(
                "UPDATE {$tbl} SET wallet_credited = 1, statut = 'approuve'
                 WHERE id = %d AND wallet_credited = 0",
                (int) $p['id']
            ) );
            if ( $claimed !== 1 ) {
                return rest_ensure_response( [ 'ok' => true, 'message' => 'Déjà traité.', 'noop' => true ] );
            }

            $user_id = (int) $record->user_id;
            if ( ! $user_id ) {
                $u = get_user_by( 'email', $record->user_email );
                $user_id = $u ? $u->ID : 0;
            }
            if ( ! $user_id ) {
                $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET wallet_credited = 0, statut = 'en_attente' WHERE id = %d", (int) $p['id'] ) );
                return new WP_Error( 'no_user', 'Utilisateur introuvable.', [ 'status' => 422 ] );
            }

            $res = Delicat_TeraWallet::credit( $user_id, (float) $record->montant,
                sprintf( 'Recharge #%d approuvée (app)', $record->id ), (int) $record->id );
            if ( is_wp_error( $res ) ) {
                $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET wallet_credited = 0, statut = 'en_attente' WHERE id = %d", (int) $p['id'] ) );
                return new WP_Error( 'credit_fail', 'Échec du crédit: ' . $res->get_error_message(), [ 'status' => 500 ] );
            }

            Delicat_Database::update( $p['id'], [
                'note_admin'      => trim( (string) $record->note_admin . "\n" . $stamp . ' APPROUVÉ' ),
            ] );
            do_action( 'delicat_recharge_approved', (int) $p['id'] );

            $fresh = Delicat_Database::get( $p['id'] );
            add_action( 'shutdown', function () use ( $fresh ) {
                if ( class_exists( 'Delicat_Email' ) && method_exists( 'Delicat_Email', 'send_status_update' ) ) {
                    Delicat_Email::send_status_update( $fresh );
                }
            }, 1 );

            return rest_ensure_response( [
                'ok' => true,
                'message' => sprintf( '%.2f HTG crédités.', $record->montant ),
            ] );
        }

        // reject
        if ( $record->statut === 'rejete' ) {
            return rest_ensure_response( [ 'ok' => true, 'message' => 'Déjà rejeté.', 'noop' => true ] );
        }
        Delicat_Database::update( $p['id'], [
            'statut'     => 'rejete',
            'note_admin' => trim( (string) $record->note_admin . "\n" . $stamp . ' REJETÉ' ),
        ] );
        $fresh = Delicat_Database::get( $p['id'] );
        add_action( 'shutdown', function () use ( $fresh ) {
            if ( class_exists( 'Delicat_Email' ) && method_exists( 'Delicat_Email', 'send_status_update' ) ) {
                Delicat_Email::send_status_update( $fresh );
            }
        }, 1 );

        return rest_ensure_response( [ 'ok' => true, 'message' => 'Demande rejetée.' ] );
    }
}
