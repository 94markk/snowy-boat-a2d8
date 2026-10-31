<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BATCH B — Workflow tools for the admin.
 *
 *  8) Bulk approve: select several pending requests, approve in one click.
 *  3) Fraud/duplicate guard: flags suspicious requests (reused TransCode,
 *     unusually large amount, rapid repeats) so they stand out for review.
 *  9) Refund/reversal: reverse a wrongly-credited recharge (debits the wallet)
 *     with a full audit trail.
 *
 * SECURITY: every action requires manage_options + nonce. Refunds are
 * idempotent (a recharge can only be reversed once) and logged.
 */
class Delicat_Workflow {

    /* Tunable fraud thresholds */
    const FRAUD_LARGE_AMOUNT = 25000;  // HTG; flag for manual scrutiny
    const FRAUD_RAPID_WINDOW = 300;    // seconds; many requests within = rapid
    const FRAUD_RAPID_COUNT  = 3;      // this many in the window = flagged

    public function __construct() {
        add_action( 'wp_ajax_delicat_bulk_approve', [ $this, 'ajax_bulk_approve' ] );
        add_action( 'wp_ajax_delicat_bulk_reject',  [ $this, 'ajax_bulk_reject' ] );
        add_action( 'wp_ajax_delicat_refund',       [ $this, 'ajax_refund' ] );
    }

    /* ================================================================
     *  3) FRAUD / DUPLICATE GUARD  (read-only analysis, no side effects)
     * ================================================================ */

    /**
     * Return an array of human-readable risk flags for a recharge record.
     * Empty array = looks clean.
     */
    public static function risk_flags( object $r ): array {
        global $wpdb;
        $flags = [];
        $table = $wpdb->prefix . 'delicat_recharges';

        // (a) Reused TransCode across DIFFERENT requests.
        if ( ! empty( $r->trans_code ) ) {
            $dupes = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE REPLACE(REPLACE(UPPER(TRIM(trans_code)),' ',''),'-','') = %s AND id <> %d",
                strtoupper( str_replace( [ ' ', '-' ], '', trim( (string) $r->trans_code ) ) ), (int) $r->id
            ) );
            if ( $dupes > 0 ) {
                $flags[] = 'TransCode déjà utilisé (' . $dupes . ' autre[s])';
            }
        }

        // (b) Unusually large amount.
        if ( (float) $r->montant >= self::FRAUD_LARGE_AMOUNT ) {
            $flags[] = 'Montant élevé (' . number_format( (float) $r->montant, 2 ) . ' HTG)';
        }

        // (c) Rapid repeat requests from the same user.
        if ( (int) $r->user_id > 0 ) {
            $since = gmdate( 'Y-m-d H:i:s', strtotime( $r->created_at ) - self::FRAUD_RAPID_WINDOW );
            $rapid = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE user_id = %d AND created_at BETWEEN %s AND %s",
                (int) $r->user_id, $since, $r->created_at
            ) );
            if ( $rapid >= self::FRAUD_RAPID_COUNT ) {
                $flags[] = 'Demandes rapprochées (' . $rapid . ' en peu de temps)';
            }
        }

        return $flags;
    }

    /* ================================================================
     *  8) BULK APPROVE
     * ================================================================ */

    /**
     * Bulk reject pending recharges. Security: admin capability + nonce, and an
     * atomic status flip so a request being credited elsewhere can't be rejected
     * out from under it (never rejects an already-credited row).
     */
    public function ajax_bulk_reject(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }
        check_ajax_referer( 'delicat_workflow_nonce', 'nonce' );

        $ids = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
        $ids = array_filter( $ids );
        if ( empty( $ids ) ) {
            wp_send_json_error( [ 'message' => 'Aucune demande sélectionnée.' ] );
        }

        global $wpdb;
        $tbl = $wpdb->prefix . 'delicat_recharges';
        $rejected = 0; $skipped = 0;

        foreach ( $ids as $id ) {
            $r = Delicat_Database::get( $id );
            if ( ! $r || $r->statut !== 'en_attente' ) { $skipped++; continue; }
            // Never reject something already credited.
            if ( (int) $r->wallet_credited === 1 ) { $skipped++; continue; }

            $done = $wpdb->query( $wpdb->prepare(
                "UPDATE {$tbl} SET statut = 'rejete'
                 WHERE id = %d AND statut = 'en_attente' AND wallet_credited = 0",
                $id ) );
            if ( $done !== 1 ) { $skipped++; continue; }

            $rejected++;
            do_action( 'delicat_recharge_record_updated', absint( $id ), [ 'status' => 'rejete', 'wallet_changed' => false ] );

            $rec = Delicat_Database::get( $id );
            add_action( 'shutdown', function () use ( $rec ) {
                if ( class_exists( 'Delicat_Email' ) ) Delicat_Email::send_status_update( $rec );
                if ( class_exists( 'Delicat_Push' ) )  {
                    if ( method_exists( 'Delicat_Push', 'notify_rejected' ) ) {
                        Delicat_Push::notify_rejected( $rec );
                    }
                }
            }, 1 );
        }

        wp_send_json_success( [
            'message' => sprintf( '❌ %d rejetée(s), %d ignorée(s).', $rejected, $skipped ),
        ] );
    }

    public function ajax_bulk_approve(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }
        check_ajax_referer( 'delicat_workflow_nonce', 'nonce' );

        $ids = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
        $ids = array_filter( $ids );
        if ( empty( $ids ) ) {
            wp_send_json_error( [ 'message' => 'Aucune demande sélectionnée.' ] );
        }

        $approved = 0; $skipped = 0; $errors = [];

        foreach ( $ids as $id ) {
            $r = Delicat_Database::get( $id );
            if ( ! $r || $r->statut !== 'en_attente' ) { $skipped++; continue; }
            if ( (int) $r->wallet_credited === 1 ) { $skipped++; continue; }

            $user_id = (int) $r->user_id;
            if ( ! $user_id ) {
                $u = get_user_by( 'email', $r->user_email );
                $user_id = $u ? $u->ID : 0;
            }
            if ( ! $user_id ) { $errors[] = "#{$id}: utilisateur introuvable"; continue; }

            global $wpdb;
            $tbl = $wpdb->prefix . 'delicat_recharges';

            // AUTHORITATIVE ONE-TIME GATE (DB UNIQUE index).
            // The atomic claim below only protects a single ROW. In a batch it
            // is entirely possible for two selected rows to carry the SAME
            // TransCode — approving both would credit one real payment twice.
            // risk_flags() only warns in the UI, so the block belongs here.
            $bulk_code = (string) ( $r->trans_code ?? '' );
            if ( $bulk_code !== '' && ! Delicat_Database::claim_code( $bulk_code, $id, (float) $r->montant, 'bulk_approve' ) ) {
                $errors[] = "#{$id}: TransCode déjà crédité (ignoré)";
                $skipped++;
                continue;
            }

            // ATOMIC CLAIM — only credit if this row is still uncredited. Stops
            // a double-credit if the SMS auto-verify or the app hits the same
            // recharge while this batch is running.
            $claimed = $wpdb->query( $wpdb->prepare(
                "UPDATE {$tbl} SET wallet_credited = 1, statut = 'approuve'
                 WHERE id = %d AND wallet_credited = 0 AND statut = 'en_attente'",
                $id ) );
            if ( $claimed !== 1 ) {
                if ( $bulk_code !== '' ) Delicat_Database::release_code( $bulk_code );
                $skipped++;
                continue;
            }

            $desc = sprintf( 'Recharge #%d – Delicat Store (lot)', $id );
            $res  = Delicat_TeraWallet::credit( $user_id, (float) $r->montant, $desc, $id );
            if ( is_wp_error( $res ) ) {
                // Roll back the claim AND free the code so it can be retried.
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$tbl} SET wallet_credited = 0, statut = 'en_attente' WHERE id = %d", $id ) );
                if ( $bulk_code !== '' ) Delicat_Database::release_code( $bulk_code );
                $errors[] = "#{$id}: " . $res->get_error_message();
                continue;
            }

            $approved++;

            // Batch C: apply growth bonuses for this recharge too.
            do_action( 'delicat_recharge_approved', (int) $id );

            // Defer client notification so the loop stays fast.
            $rec = Delicat_Database::get( $id );
            add_action( 'shutdown', function () use ( $rec ) {
                if ( class_exists( 'Delicat_Email' ) )  Delicat_Email::send_status_update( $rec );
                if ( class_exists( 'Delicat_Push' ) )   Delicat_Push::notify_approved( $rec );
            }, 1 );
        }

        wp_send_json_success( [
            'message' => sprintf(
                '✅ %d approuvée(s), %d ignorée(s)%s.',
                $approved, $skipped,
                $errors ? ', ' . count( $errors ) . ' erreur(s)' : ''
            ),
            'errors'  => $errors,
        ] );
    }

    /* ================================================================
     *  9) REFUND / REVERSAL  (debits wallet, idempotent, audited)
     * ================================================================ */

    public function ajax_refund(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }
        check_ajax_referer( 'delicat_workflow_nonce', 'nonce' );

        $id     = absint( $_POST['id'] ?? 0 );
        $reason = sanitize_text_field( $_POST['reason'] ?? '' );
        $r      = Delicat_Database::get( $id );

        if ( ! $r ) {
            wp_send_json_error( [ 'message' => 'Demande introuvable.' ] );
        }
        if ( $r->statut !== 'approuve' || (int) $r->wallet_credited !== 1 ) {
            wp_send_json_error( [ 'message' => 'Seules les recharges approuvées et créditées peuvent être remboursées.' ] );
        }
        // Idempotency: never reverse twice.
        if ( strpos( (string) $r->note_admin, '[REMBOURSÉ' ) !== false ) {
            wp_send_json_error( [ 'message' => 'Cette recharge a déjà été remboursée.' ] );
        }

        $user_id = (int) $r->user_id;
        if ( ! $user_id ) {
            $u = get_user_by( 'email', $r->user_email );
            $user_id = $u ? $u->ID : 0;
        }
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Utilisateur introuvable.' ] );
        }

        // Check the wallet can cover the reversal.
        $balance = Delicat_TeraWallet::get_balance( $user_id );
        if ( $balance < (float) $r->montant ) {
            wp_send_json_error( [ 'message' => sprintf(
                'Solde insuffisant pour rembourser (solde: %s HTG, requis: %s HTG). Le client a peut-être déjà dépensé les fonds.',
                number_format( $balance, 2 ), number_format( (float) $r->montant, 2 )
            ) ] );
        }

        $desc = sprintf( 'Remboursement recharge #%d – %s', $id, $reason ?: 'sans motif' );
        $res  = Delicat_TeraWallet::debit( $user_id, (float) $r->montant, $desc );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( [ 'message' => 'Échec du débit: ' . $res->get_error_message() ] );
        }

        // Audit trail in note_admin + status.
        $admin = wp_get_current_user();
        $stamp = sprintf(
            '[REMBOURSÉ le %s par %s%s]',
            date_i18n( 'd/m/Y H:i' ),
            $admin ? $admin->user_login : 'admin',
            $reason ? ' — ' . $reason : ''
        );
        $note = trim( (string) $r->note_admin . "\n" . $stamp );
        Delicat_Database::update( $id, [
            'statut'          => 'rejete',
            'wallet_credited' => 0,
            'note_admin'      => $note,
        ] );

        wp_send_json_success( [
            'message' => sprintf( '✅ %s HTG remboursés (débités du wallet). Trace enregistrée.', number_format( (float) $r->montant, 2 ) ),
        ] );
    }
}
