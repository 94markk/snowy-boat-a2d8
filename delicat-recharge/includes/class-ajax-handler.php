<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Delicat_Ajax_Handler {

    public function __construct() {
        add_action( 'wp_ajax_delicat_submit_recharge',        [ $this, 'handle_submit' ] );
        // NOTE: no wp_ajax_nopriv_ registration — recharge submission is for
        // logged-in clients only (see handle_submit's login gate).
        add_action( 'wp_ajax_delicat_admin_action',           [ $this, 'handle_admin_action' ] );
        add_action( 'wp_ajax_delicat_manual_entry',           [ $this, 'handle_manual_entry' ] );
        add_action( 'wp_ajax_delicat_toggle_service',         [ $this, 'handle_toggle_service' ] );
        // Live wallet balance (lets the balance card refresh itself after a
        // recharge is auto-credited — no page reload needed). Logged-in only.
        add_action( 'wp_ajax_delicat_balance',                [ $this, 'handle_balance' ] );
    }

    /* ====================================================================
     * FRONT-END: Live wallet balance
     * Returns the caller's current TeraWallet balance so the balance card
     * can animate to the new value with no page refresh. Used both by the
     * post-submit poller and (merged into the response) on instant credit.
     * ==================================================================== */

    public function handle_balance(): void {
        if ( ! check_ajax_referer( 'delicat_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Requête non autorisée.' ], 403 );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Non connecté.' ], 401 );
        }

        // Light anti-abuse: cap balance polls at 40 / minute / user.
        $rl_key = 'delicat_balpoll_' . $user_id;
        $hits   = (int) get_transient( $rl_key );
        if ( $hits >= 40 ) {
            wp_send_json_error( [ 'message' => 'Trop de requêtes. Réessayez dans une minute.' ], 429 );
        }
        set_transient( $rl_key, $hits + 1, MINUTE_IN_SECONDS );

        nocache_headers();
        wp_send_json_success( $this->wallet_balance_payload( $user_id ) );
    }

    /**
     * Build the wallet-balance payload used by the live-balance endpoint and
     * merged into auto-credit responses so the front-end can animate instantly.
     * balance_fmt mirrors the balance card's server-side formatting so the
     * animated value matches exactly what a page reload would show.
     */
    private function wallet_balance_payload( int $user_id ): array {
        $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'HTG';
        $balance  = 0.0;
        if ( $user_id && class_exists( 'Delicat_TeraWallet' ) ) {
            $balance = Delicat_TeraWallet::get_balance( $user_id );
        }
        return [
            'balance'     => round( $balance, 2 ),
            'balance_fmt' => number_format_i18n( $balance, 0 ),
            'currency'    => $currency,
        ];
    }

    /* ====================================================================
     * FRONT-END: Submit recharge
     * ==================================================================== */

    public function handle_submit(): void {
        if ( ! check_ajax_referer( 'delicat_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Requête non autorisée.' ], 403 );
        }

        // ── SECURITY: login required ────────────────────────────────────────
        // Recharges credit a real wallet, so the submitter must be the account
        // owner. Without this, a logged-out visitor could submit a request in
        // someone else's name (by typing their email) and have it approved.
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [
                'message'  => 'Connectez-vous pour effectuer une recharge.',
                'need_login' => true,
                'login_url'  => wp_login_url( wp_get_referer() ?: home_url( '/' ) ),
            ], 401 );
        }

        // --- Gather & validate inputs ---
        $tab        = sanitize_text_field( $_POST['tab']        ?? '' );
        $methode    = sanitize_text_field( $_POST['methode']    ?? '' );
        $montant    = floatval( $_POST['montant']    ?? 0 );
        $trans_code = sanitize_text_field( $_POST['trans_code'] ?? '' );
        $email_post = sanitize_email( $_POST['email'] ?? '' );

        if ( ! in_array( $tab, [ 'moncash', 'natcash', 'manuel', 'natcash_tc' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Méthode invalide.' ] );
        }

        // Server-side service switch: refuse any service the admin turned OFF,
        // even if the client bypassed the hidden UI. (manuel is admin-only.)
        if ( ! Delicat_Database::service_on( $tab ) ) {
            wp_send_json_error( [ 'message' => 'Ce moyen de paiement est temporairement indisponible. Merci de choisir une autre méthode.' ] );
        }
        if ( $montant <= 0 ) {
            wp_send_json_error( [ 'message' => 'Le montant doit être supérieur à zéro.' ] );
        }
        // Maximum amount cap (security: prevents typos / abuse)
        if ( $montant > Delicat_Database::MAX_MONTANT ) {
            wp_send_json_error( [ 'message' => sprintf(
                'Le montant maximum autorisé est de %s HTG.',
                number_format( Delicat_Database::MAX_MONTANT, 0, '.', ',' )
            ) ] );
        }
        // Natcash PIN flow is RETIRED — Natcash validates by TransCode only
        // (tab 'natcash_tc'). Reject any submission still targeting the old
        // flow so a client never creates a request that can never validate.
        if ( $tab === 'natcash' ) {
            wp_send_json_error( [
                'message' => 'Cette méthode n\'est plus disponible. Utilisez « Natcash Auto (TC) » avec votre TransCode.',
            ] );
        }
        // TransCode required for MonCash, Natcash (TC) and Manuel.
        {
            if ( empty( $trans_code ) ) {
                wp_send_json_error( [ 'message' => 'Veuillez entrer votre TransCode / Txn ID.' ] );
            }
        }

        // Block duplicate transaction codes (security: prevents double-credit)
        if ( ! empty( $trans_code ) && Delicat_Database::trans_code_exists( $trans_code ) ) {
            // Strong abuse signal: submitting a code that is already spent is
            // the classic double-claim attempt. Weighted heavily.
            if ( class_exists( 'Delicat_Guard' ) ) {
                Delicat_Guard::flag( get_current_user_id(), 'TransCode déjà utilisé soumis', 3 );
            }
            wp_send_json_error( [ 'message' => 'Désolé votre code de transaction a déjà été utilisé.' ] );
        }

        // --- User info ---
        // SECURITY: identity comes from the session only. A client-supplied
        // email is never trusted, otherwise a logged-in user could file a
        // recharge that credits somebody else's account.
        $user       = wp_get_current_user();
        $user_id    = (int) ( $user->ID ?? 0 );
        $user_email = $user->user_email;

        if ( ! $user_id || ! $user_email ) {
            wp_send_json_error( [ 'message' => 'Session invalide. Reconnectez-vous.' ], 401 );
        }

        // Block clients banned from transacting.
        if ( class_exists( 'Delicat_Clients' ) && ! Delicat_Clients::can_transact( $user_id ) ) {
            wp_send_json_error( [ 'message' => 'Votre compte ne peut pas effectuer de recharge actuellement. Contactez le support.' ] );
        }

        // Anti-abuse: generous cap so a client topping up before a purchase is
        // never slowed, while still stopping automated flooding. Real protection
        // comes from the duplicate-code check, the atomic claim and the Guard.
        $rl_key = 'delicat_sub_' . $user_id;
        $hits   = (int) get_transient( $rl_key );
        if ( $hits >= 20 ) {
            wp_send_json_error( [ 'message' => 'Trop de demandes. Patientez quelques minutes.' ], 429 );
        }
        set_transient( $rl_key, $hits + 1, 5 * MINUTE_IN_SECONDS );

        // Batch C: stash an entered promo code so it can be applied on approval.
        if ( $user_id && ! empty( $_POST['promo'] ) ) {
            $promo = strtoupper( sanitize_text_field( wp_unslash( $_POST['promo'] ) ) );
            if ( $promo !== '' ) {
                update_user_meta( $user_id, 'delicat_pending_promo', $promo );
            }
        }

        // --- Handle proof file upload (required for Manuel, optional for others) ---
        $preuve_url = '';
        if ( ! empty( $_FILES['preuve']['name'] ) ) {
            $upload = $this->upload_proof( $_FILES['preuve'] );
            if ( is_wp_error( $upload ) ) {
                wp_send_json_error( [ 'message' => $upload->get_error_message() ] );
            }
            $preuve_url = $upload;
        } elseif ( $tab === 'manuel' ) {
            wp_send_json_error( [ 'message' => 'Veuillez joindre une preuve de paiement.' ] );
        }

        // --- Route by tab ---
        switch ( $tab ) {
            case 'moncash':
                $this->process_moncash( $user_id, $user_email, $montant, $trans_code, $methode, $preuve_url );
                break;

            // 'natcash' (retired PIN flow) is refused above; its handlers were removed in 6.6.4.

            case 'manuel':
                $this->process_manuel( $user_id, $user_email, $montant, $trans_code, $methode, $preuve_url );
                break;

            case 'natcash_tc':
                $this->process_natcash_tc( $user_id, $user_email, $montant, $trans_code, $methode, $preuve_url );
                break;
        }
    }

    /* ====================================================================
     * TAB: MonCash — auto verify → auto credit wallet
     * ==================================================================== */

    private function process_moncash(
        int $user_id, string $user_email,
        float $montant, string $trans_code,
        string $methode, string $preuve_url
    ): void {
        // Manual review flow — admin verifies TransCode and approves
        $id = $this->save_request( $user_id, $user_email, 'moncash', $methode ?: 'moncash',
            $montant, $trans_code, $preuve_url, 'en_attente' );

        if ( is_wp_error( $id ) ) {
            wp_send_json_error( [ 'message' => 'Erreur base de données : ' . $id->get_error_message() ] );
        }

        // Instant strict verify: if the matching MonCash SMS (exact TransCode +
        // exact amount) was already received, auto-credit right now.
        if ( $this->try_instant_verify( (int) $id, 'moncash', $trans_code, $montant ) ) {
            wp_send_json_success( array_merge( [
                'message'       => sprintf( 'Paiement MonCash vérifié ! <strong>%s HTG</strong> ajoutés à votre wallet.', number_format( $montant, 2 ) ),
                'auto_approved' => true,
            ], $this->wallet_balance_payload( $user_id ) ) );
        }

        $record = Delicat_Database::get( $id );
        $this->defer_submission( $record, 'MONCASH — vérification auto par SMS' );

        wp_send_json_success( [
            'message'       => 'Demande MonCash enregistrée ! Envoyez le paiement avec le TransCode <strong>' . esc_html( $trans_code ) . '</strong>. Votre wallet sera crédité automatiquement dès réception du SMS MonCash.',
            'auto_approved' => false,
        ] );
    }

    /* ====================================================================
     * TAB: Natcash Automatique avec Transcode — same rules as MonCash.
     * Client enters the TransCode; the system auto-credits when a real Natcash
     * SMS matches exact TransCode + exact amount (instantly if already received,
     * otherwise as soon as the SMS arrives).
     * ==================================================================== */

    private function process_natcash_tc(
        int $user_id, string $user_email,
        float $montant, string $trans_code,
        string $methode, string $preuve_url
    ): void {
        $id = $this->save_request( $user_id, $user_email, 'natcash_tc', 'natcash',
            $montant, $trans_code, $preuve_url, 'en_attente' );

        if ( is_wp_error( $id ) ) {
            wp_send_json_error( [ 'message' => 'Erreur base de données : ' . $id->get_error_message() ] );
        }

        // Instant strict verify against an already-received Natcash SMS.
        if ( $this->try_instant_verify( (int) $id, 'natcash', $trans_code, $montant ) ) {
            wp_send_json_success( array_merge( [
                'message'       => sprintf( 'Paiement Natcash vérifié ! <strong>%s HTG</strong> ajoutés à votre wallet.', number_format( $montant, 2 ) ),
                'auto_approved' => true,
            ], $this->wallet_balance_payload( $user_id ) ) );
        }

        $record = Delicat_Database::get( $id );
        $this->defer_submission( $record, 'NATCASH (Transcode) — vérification auto par SMS' );

        wp_send_json_success( [
            'message'       => 'Demande enregistrée. Dès réception du SMS Natcash correspondant (TransCode + montant exact), votre wallet sera crédité automatiquement.',
            'auto_approved' => false,
        ] );
    }

    /* ====================================================================
     * TAB: Manuel — always pending, admin approves manually
     * ==================================================================== */

    private function process_manuel(
        int $user_id, string $user_email,
        float $montant, string $trans_code,
        string $methode, string $preuve_url
    ): void {
        $id = $this->save_request( $user_id, $user_email, 'manuel', $methode,
            $montant, $trans_code, $preuve_url, 'en_attente' );

        if ( is_wp_error( $id ) ) {
            wp_send_json_error( [ 'message' => 'Erreur base de données : ' . $id->get_error_message() ] );
        }

        $record = Delicat_Database::get( $id );
        $this->defer_submission( $record, 'MANUEL — preuve jointe, à vérifier' );

        wp_send_json_success( [
            'message'       => 'Demande envoyée ! Notre équipe examinera votre preuve de paiement et rechargera votre compte sous peu.',
            'auto_approved' => false,
        ] );
    }

    /* ====================================================================
     * ADMIN: Approve or Reject
     * ==================================================================== */

    public function handle_admin_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }
        check_ajax_referer( 'delicat_admin_nonce', 'nonce' );

        $id     = absint( $_POST['id']     ?? 0 );
        $action = sanitize_text_field( $_POST['action_type'] ?? '' );

        $record = Delicat_Database::get( $id );
        if ( ! $record ) {
            wp_send_json_error( [ 'message' => 'Demande introuvable.' ] );
        }

        if ( $action === 'approuver' ) {
            if ( $record->wallet_credited ) {
                wp_send_json_error( [ 'message' => 'Wallet déjà crédité pour cette demande.' ] );
            }

            global $wpdb;
            $tbl = $wpdb->prefix . 'delicat_recharges';

            // AUTHORITATIVE ONE-TIME GATE (DB UNIQUE index).
            // The atomic claim below only protects THIS row. Without this check
            // two separate rows carrying the same TransCode could each be
            // approved, crediting one real payment twice. risk_flags() only
            // warns in the UI, so the block has to happen here.
            $rec_code = (string) ( $record->trans_code ?? '' );
            if ( $rec_code !== '' && ! Delicat_Database::claim_code( $rec_code, $id, (float) $record->montant, 'admin_approve' ) ) {
                wp_send_json_error( [ 'message' => 'Ce TransCode a déjà été crédité (double approbation évitée).' ] );
            }

            // ATOMIC CLAIM: only proceed if this row is still uncredited. Prevents
            // a double-credit when the SMS auto-verify and a manual approval (or
            // two taps) hit the same recharge at the same moment.
            $claimed = $wpdb->query( $wpdb->prepare(
                "UPDATE {$tbl} SET wallet_credited = 1, statut = 'approuve'
                 WHERE id = %d AND wallet_credited = 0",
                $id
            ) );
            if ( $claimed !== 1 ) {
                if ( $rec_code !== '' ) Delicat_Database::release_code( $rec_code );
                wp_send_json_error( [ 'message' => 'Déjà traité (crédit en double évité).' ] );
            }

            $user_id = (int) $record->user_id;
            if ( ! $user_id ) {
                // Try to find user by email
                $user    = get_user_by( 'email', $record->user_email );
                $user_id = $user ? $user->ID : 0;
            }

            if ( ! $user_id ) {
                // Roll back the claim AND free the code so it can be retried.
                $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET wallet_credited = 0, statut = 'en_attente' WHERE id = %d", $id ) );
                if ( $rec_code !== '' ) Delicat_Database::release_code( $rec_code );
                wp_send_json_error( [ 'message' => "Impossible de trouver l'utilisateur ({$record->user_email})." ] );
            }

            // Credit the wallet. (Row is already claimed/marked; credit_wallet
            // re-sets the flag harmlessly.)
            $credited = $this->credit_wallet( $user_id, (float) $record->montant, $id );

            if ( ! $credited ) {
                // Roll back so it can be retried, not stuck approved-but-not-credited.
                $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET wallet_credited = 0, statut = 'en_attente' WHERE id = %d", $id ) );
                if ( $rec_code !== '' ) Delicat_Database::release_code( $rec_code );
                wp_send_json_error( [ 'message' => 'Échec du crédit wallet. Vérifiez que TeraWallet est actif.' ] );
            }

            $record = Delicat_Database::get( $id );

            // Batch C: let growth features (promo/referral/loyalty) apply bonuses.
            do_action( 'delicat_recharge_approved', (int) $id );

            // 3) Defer the slow stuff (email + webhook) to AFTER the response is
            //    sent, so the admin/client never waits on mail or HTTP timeouts.
            $this->defer_notifications( $record );

            // 4) Respond instantly.
            wp_send_json_success( [
                'message'  => sprintf( '%.2f HTG crédités instantanément au wallet de %s.', $record->montant, $record->user_email ),
                'credited' => true,
            ] );

        } elseif ( $action === 'rejeter' ) {
            Delicat_Database::update( $id, [ 'statut' => 'rejete' ] );
            $record = Delicat_Database::get( $id );
            $this->defer_notifications( $record );

            wp_send_json_success( [ 'message' => 'Demande rejetée. Le client a été notifié.' ] );

        } else {
            wp_send_json_error( [ 'message' => 'Action invalide.' ] );
        }
    }

    /* ====================================================================
     * ADMIN: Pre-register / "burn" a TransCode as USED (anti double-dip).
     *
     * Use case: a client pays you off-site (cash / direct transfer / in person)
     * and you handle the value yourself. You record the TransCode here so it is
     * marked as spent. If the client later submits that SAME code on the
     * website to be credited a second time, the site declines it — because
     * trans_code_exists() now finds this row.
     *
     * Two modes:
     *   mode=block  → record + block the code only. Wallet is NOT credited
     *                 (you already handed over the value off-site).
     *   mode=credit → record AND credit the client's TeraWallet now.
     * ==================================================================== */
    public function handle_manual_entry(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }
        check_ajax_referer( 'delicat_admin_nonce', 'nonce' );

        $email      = sanitize_email( $_POST['email'] ?? '' );
        $tab        = sanitize_text_field( $_POST['tab'] ?? 'manuel' );
        $montant    = floatval( $_POST['montant'] ?? 0 );
        $trans_code = trim( sanitize_text_field( $_POST['trans_code'] ?? '' ) );
        $mode_raw   = sanitize_text_field( $_POST['mode'] ?? 'block' );
        $mode       = in_array( $mode_raw, [ 'credit', 'verify' ], true ) ? $mode_raw : 'block';

        if ( ! in_array( $tab, [ 'moncash', 'natcash', 'manuel' ], true ) ) {
            $tab = 'manuel';
        }
        if ( $trans_code === '' ) {
            wp_send_json_error( [ 'message' => 'Le TransCode / code de transaction est obligatoire.' ] );
        }
        if ( $montant <= 0 ) {
            wp_send_json_error( [ 'message' => 'Le montant doit être supérieur à zéro.' ] );
        }
        if ( $montant > Delicat_Database::MAX_MONTANT ) {
            wp_send_json_error( [ 'message' => sprintf(
                'Montant maximum: %s HTG.', number_format( Delicat_Database::MAX_MONTANT, 0, '.', ',' )
            ) ] );
        }

        // Already recorded? Don't create a duplicate — it's already blocked.
        if ( Delicat_Database::trans_code_exists( $trans_code ) ) {
            wp_send_json_error( [ 'message' => 'Ce code est déjà enregistré (il est donc déjà bloqué pour le site).' ] );
        }

        // Resolve the client (required for credit mode, optional for block).
        $user_id = 0;
        if ( $email ) {
            $u = get_user_by( 'email', $email );
            if ( $u ) $user_id = (int) $u->ID;
        }

        // VERIFY mode: only proceed if a real received SMS strictly matches
        // provider + exact TransCode + exact amount. Otherwise stop — nothing is
        // recorded or credited (anti-scammer gate).
        if ( $mode === 'verify' ) {
            if ( ! $user_id ) {
                wp_send_json_error( [ 'message' => "Pour vérifier & créditer, entrez l'email d'un client existant." ] );
            }
            $chk = class_exists( 'Delicat_Natcash_Auto' )
                ? Delicat_Natcash_Auto::verify_received( $tab, $trans_code, $montant )
                : [ 'ok' => false, 'reason' => 'Vérification indisponible.' ];
            if ( empty( $chk['ok'] ) ) {
                wp_send_json_error( [ 'message' => '' . ( $chk['reason'] ?? 'Vérification échouée.' ) . ' Rien n’a été crédité.' ] );
            }
            $mode = 'credit';
        }

        if ( $mode === 'credit' && ! $user_id ) {
            wp_send_json_error( [ 'message' => "Pour créditer, entrez l'email d'un client existant. Utilisateur introuvable." ] );
        }
        // 6.6.4 (RCH-02): burn the code in the one-time ledger too, like every
        // other approval path, so no later path can credit it again.
        if ( ! Delicat_Database::claim_code( $trans_code, 0, $montant, 'console_mentry_' . $mode ) ) {
            wp_send_json_error( [ 'message' => 'Ce code est déjà enregistré (il est donc déjà bloqué pour le site).' ] );
        }

        if ( $mode === 'credit' ) {
            $id = $this->save_request( $user_id, $email, $tab, $tab, $montant, $trans_code, '', 'approuve' );
            if ( is_wp_error( $id ) ) {
                Delicat_Database::release_code( $trans_code ); // nothing recorded → free it
                wp_send_json_error( [ 'message' => 'Erreur base de données : ' . $id->get_error_message() ] );
            }
            $credited = $this->credit_wallet( $user_id, $montant, $id );
            if ( ! $credited ) {
                Delicat_Database::update( $id, [
                    'note_admin' => '[BLOCAGE-MANUEL] Crédit wallet échoué (TeraWallet inactif ?). Code bloqué. — ' . current_time( 'mysql' ),
                ] );
                wp_send_json_error( [ 'message' => 'Transaction enregistrée (code bloqué) mais le crédit wallet a échoué. Vérifiez TeraWallet.' ] );
            }
            Delicat_Database::update( $id, [
                'note_admin' => 'Saisie manuelle console' . ( $mode_raw === 'verify' ? ' (vérifié SMS)' : '' ) . ' — wallet crédité — ' . current_time( 'mysql' ),
            ] );
            $record = Delicat_Database::get( $id );
            do_action( 'delicat_recharge_approved', (int) $id );
            $this->defer_notifications( $record );
            wp_send_json_success( [
                'message' => sprintf(
                    '%s HTG crédités à %s. Le code « %s » est maintenant bloqué sur le site.',
                    number_format( $montant, 2 ), $email, $trans_code
                ),
            ] );
        }

        // mode = block (default): record + burn the code, no wallet credit.
        $id = $this->save_request( $user_id, $email, $tab, $tab, $montant, $trans_code, '', 'approuve' );
        if ( is_wp_error( $id ) ) {
            Delicat_Database::release_code( $trans_code ); // nothing recorded → free it
            wp_send_json_error( [ 'message' => 'Erreur base de données : ' . $id->get_error_message() ] );
        }
        // Mark as handled so nobody credits it later by accident. The
        // [BLOCAGE-MANUEL] tag suppresses the Refund button (nothing to refund).
        Delicat_Database::update( $id, [
            'wallet_credited' => 1,
            'note_admin'      => '[BLOCAGE-MANUEL] Déjà validé hors-ligne — wallet non crédité par le plugin — ' . current_time( 'mysql' ),
        ] );
        wp_send_json_success( [
            'message' => sprintf(
                'Transaction enregistrée. Le code « %s » est bloqué : si le client le soumet sur le site, ce sera refusé.',
                $trans_code
            ),
        ] );
    }

    /* ====================================================================
     * ADMIN: turn a client-facing service ON/OFF (MonCash / Natcash Auto /
     * Natcash Auto TC). Enforced server-side in handle_submit() and hidden on
     * the client form. manage_options + nonce required.
     * ==================================================================== */
    public function handle_toggle_service(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }
        check_ajax_referer( 'delicat_admin_nonce', 'nonce' );

        $tab   = sanitize_text_field( $_POST['service'] ?? '' );
        $onRaw = (string) ( $_POST['on'] ?? '' );
        $on    = ( $onRaw === '1' || $onRaw === 'true' || $onRaw === 'on' );

        if ( ! in_array( $tab, Delicat_Database::SERVICES, true ) ) {
            wp_send_json_error( [ 'message' => 'Service inconnu.' ] );
        }

        $state = Delicat_Database::set_service( $tab, $on );
        wp_send_json_success( [
            'service' => $tab,
            'on'      => (bool) $state[ $tab ],
            'state'   => $state,
            'message' => sprintf( '%s %s', self::service_label( $tab ), $on ? 'activé' : 'désactivé' ),
        ] );
    }

    private static function service_label( string $tab ): string {
        $m = [ 'moncash' => 'MonCash', 'natcash' => 'Natcash Auto', 'natcash_tc' => 'Natcash Auto (TC)' ];
        return $m[ $tab ] ?? $tab;
    }

    /* ====================================================================
     * HELPERS
     * ==================================================================== */

    private function save_request(
        int $user_id, string $user_email,
        string $tab, string $methode,
        float $montant, string $trans_code,
        string $preuve_url, string $statut,
        string $note = ''
    ): int|WP_Error {
        $id = Delicat_Database::insert( compact(
            'user_id','user_email','tab','methode','montant','trans_code','preuve_url','statut'
        ) );

        if ( ! is_wp_error( $id ) && $note ) {
            Delicat_Database::update( $id, [ 'note_admin' => $note ] );
        }

        return $id;
    }

    /**
     * Instant strict verification on submit. If a real received SMS strictly
     * matches provider + exact TransCode + exact amount, claim + credit the
     * given recharge right away and return true. Otherwise return false and
     * leave the row pending (the SMS-arrival matcher will credit it later).
     */
    private function try_instant_verify( int $id, string $provider, string $trans_code, float $montant ): bool {
        if ( ! class_exists( 'Delicat_Natcash_Auto' ) ) return false;
        // Unattended path (v6.3.0 SMS Guard): only a TRUSTED SMS is proof, and
        // the auto-credit caps / lockdown apply. Anything else stays pending.
        $chk = Delicat_Natcash_Auto::verify_received( $provider, $trans_code, $montant, true );
        if ( empty( $chk['ok'] ) ) return false;

        global $wpdb;
        $tbl  = $wpdb->prefix . 'delicat_recharges';
        $code = trim( $trans_code );

        // Serialize ALL credit attempts for this same TransCode across concurrent
        // requests. Without this, two simultaneous submissions of the same code
        // land on two different rows; the per-id atomic claim below succeeds for
        // BOTH, and one real payment would credit twice. A MySQL named lock makes
        // the check-and-credit atomic per code. If we can't get the lock, we bail
        // and leave the row pending (the SMS-arrival matcher credits it safely).
        $lock = Delicat_Natcash_Auto::code_lock_name( $code );
        if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) !== 1 ) {
            return false;
        }

        $ok = false;
        // Per-code anti-reuse: if this TransCode already credited on ANY row, stop.
        // Must use the SAME normalization as every other reuse check (strip
        // spaces and dashes, uppercase) — otherwise a code credited as
        // "2607 2038-38458" would not be found when re-submitted as
        // "260720383845 8", and one payment could credit twice.
        $dlc_code_col = Delicat_Database::code_sql();
        $used = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl}
             WHERE {$dlc_code_col} = %s
               AND wallet_credited = 1",
            Delicat_Natcash_Auto::norm_code( $code )
        ) );
        // AUTHORITATIVE one-time gate (DB UNIQUE index) — belt and braces with
        // the named lock above.
        if ( $used === 0 && ! Delicat_Database::claim_code( $code, $id, 0, 'instant_verify' ) ) {
            $used = 1; // someone else already owns this code
        }

        if ( $used === 0 ) {
            $claimed = $wpdb->query( $wpdb->prepare(
                "UPDATE {$tbl} SET wallet_credited = 1, statut = 'approuve'
                 WHERE id = %d AND wallet_credited = 0", $id ) );
            if ( $claimed === 1 ) {
                $record  = Delicat_Database::get( $id );
                $user_id = (int) $record->user_id;
                if ( ! $user_id ) {
                    $u = get_user_by( 'email', $record->user_email );
                    $user_id = $u ? $u->ID : 0;
                }
                if ( $user_id && $this->credit_wallet( $user_id, $montant, $id ) ) {
                    Delicat_Database::update( $id, [
                        'note_admin' => trim( (string) $record->note_admin . "\n[AUTO-VÉRIFIÉ SMS " . strtoupper( $provider ) . ' ' . current_time( 'mysql' ) . ']' ),
                    ] );
                    if ( class_exists( 'Delicat_SMS_Guard' ) ) Delicat_SMS_Guard::after_credit( $provider, $montant );
                    $ok = true;
                } else {
                    $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET wallet_credited = 0, statut = 'en_attente' WHERE id = %d", $id ) );
                }
            }
        }

        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );

        if ( $ok ) {
            $fresh = Delicat_Database::get( $id );
            do_action( 'delicat_recharge_approved', $id );
            $this->defer_notifications( $fresh );
        }
        return $ok;
    }

    private function credit_wallet( int $user_id, float $amount, int $recharge_id ): bool {
        if ( $user_id <= 0 ) return false;

        $desc   = sprintf( 'Recharge #%d – Delicat Store (%s)', $recharge_id, date( 'd/m/Y H:i' ) );
        $result = Delicat_TeraWallet::credit( $user_id, $amount, $desc, $recharge_id );

        if ( is_wp_error( $result ) ) {
            error_log( '[Delicat Recharge] Wallet credit failed: ' . $result->get_error_message() );
            Delicat_Database::update( $recharge_id, [ 'note_admin' => 'Wallet credit failed: ' . $result->get_error_message() ] );
            return false;
        }

        Delicat_Database::update( $recharge_id, [ 'wallet_credited' => 1 ] );
        return true;
    }

    private function fire_webhook( object $record ): void {
        $result = Delicat_Webhook::send( $record );
        if ( ! is_wp_error( $result ) ) {
            Delicat_Database::update( $record->id, [ 'webhook_sent' => 1 ] );
        }
    }

    /**
     * Run email + webhook AFTER the HTTP response is sent to the browser, so the
     * admin/client never waits on slow mail servers or webhook timeouts.
     * The wallet credit has already happened synchronously before this is called.
     */
    private function defer_notifications( object $record ): void {
        add_action( 'shutdown', function () use ( $record ) {
            delicat_recharge_finish_request();
            Delicat_Email::send_status_update( $record );
            $this->fire_webhook( $record );
            // Android push: only on approval (wallet credited)
            if ( $record->statut === 'approuve' && class_exists( 'Delicat_Push' ) ) {
                Delicat_Push::notify_approved( $record );
            }
        }, 1 );
    }

    /**
     * Defer the NEW-SUBMISSION notifications (admin alert + user confirmation +
     * webhook) until after the response is flushed, so the client's "Envoyer"
     * returns instantly instead of waiting on email/HTTP.
     *
     * @param string $admin_label Label shown in the admin notification email.
     */
    private function defer_submission( object $record, string $admin_label ): void {
        add_action( 'shutdown', function () use ( $record, $admin_label ) {
            delicat_recharge_finish_request();
            Delicat_Email::send_admin_notification( $record, $admin_label );
            Delicat_Email::send_user_confirmation( $record );
            $this->fire_webhook( $record );
            // Android push: new request alert
            if ( class_exists( 'Delicat_Push' ) ) {
                Delicat_Push::notify_new_request( $record );
            }
        }, 1 );
    }

    private function upload_proof( array $file ): string|WP_Error {
        // Delegates to the secure private-storage handler. Returns a stored
        // filename (not a public URL); files are only viewable by admins.
        $files = new Delicat_Files();
        return $files->store( $file );
    }
}
