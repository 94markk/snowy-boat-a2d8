<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Client management console:
 *  - List clients with live balance, transaction count, status
 *  - Search/filter
 *  - View per-client transaction history
 *  - Ban/unban login + ban/unban actions (independent toggles)
 *  - Adjust wallet balance (credit/debit)
 *  - CSV export (clients + transactions)
 */
class Delicat_Clients {

    public function __construct( bool $register_hooks = true ) {
        if ( ! $register_hooks ) return;

        add_action( 'wp_ajax_delicat_client_action', [ $this, 'ajax_action' ] );
        add_action( 'admin_init',  [ $this, 'maybe_export_csv' ] );

        // Enforce login ban (never blocks admins)
        add_filter( 'authenticate', [ $this, 'enforce_login_ban' ], 30 );
    }

    /* ---- Menu callback (registered by Delicat_Admin) ------------------ */

    /**
     * Static entry point used by Delicat_Admin's submenu registration.
     * Renders without re-registering hooks.
     */
    public static function page_clients(): void {
        $instance = new self( false );
        $instance->page();
    }

    /* ---- Ban enforcement ---------------------------------------------- */

    public function enforce_login_ban( $user ) {
        if ( $user instanceof WP_User
            && ! user_can( $user, 'manage_options' )
            && get_user_meta( $user->ID, 'dr_banned_login', true ) ) {
            return new WP_Error( 'dr_banned',
                '<strong>Compte suspendu.</strong> Contactez le support Delicat Store.' );
        }
        return $user;
    }

    public static function can_transact( int $user_id ): bool {
        if ( ! $user_id ) return true;
        return ! get_user_meta( $user_id, 'dr_banned_actions', true );
    }

    /* ---- AJAX: ban/unban + balance adjust ----------------------------- */

    public function ajax_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }
        check_ajax_referer( 'delicat_client_nonce', 'nonce' );

        $user_id = absint( $_POST['user_id'] ?? 0 );
        $action  = sanitize_text_field( $_POST['client_action'] ?? '' );
        $user    = get_user_by( 'id', $user_id );

        if ( ! $user ) {
            wp_send_json_error( [ 'message' => 'Utilisateur introuvable.' ] );
        }

        switch ( $action ) {
            case 'ban_login':
                update_user_meta( $user_id, 'dr_banned_login', 1 );
                wp_send_json_success( [ 'message' => '🔒 Connexion bloquée pour ' . $user->user_email ] );

            case 'unban_login':
                delete_user_meta( $user_id, 'dr_banned_login' );
                wp_send_json_success( [ 'message' => '🔓 Connexion réactivée pour ' . $user->user_email ] );

            case 'ban_actions':
                update_user_meta( $user_id, 'dr_banned_actions', 1 );
                wp_send_json_success( [ 'message' => '⛔ Recharges/achats bloqués pour ' . $user->user_email ] );

            case 'unban_actions':
                delete_user_meta( $user_id, 'dr_banned_actions' );
                wp_send_json_success( [ 'message' => '✅ Recharges/achats réactivés pour ' . $user->user_email ] );

            case 'adjust_balance':
                $amount = floatval( $_POST['amount'] ?? 0 );
                $type   = sanitize_text_field( $_POST['adjust_type'] ?? 'credit' );
                if ( $amount <= 0 ) {
                    wp_send_json_error( [ 'message' => 'Montant invalide.' ] );
                }
                $note = sprintf( 'Ajustement admin (%s) – %s', $type, date( 'd/m/Y H:i' ) );
                if ( $type === 'credit' ) {
                    $res = Delicat_TeraWallet::credit( $user_id, $amount, $note );
                    $msg = sprintf( '✅ %.2f HTG ajoutés au wallet de %s.', $amount, $user->user_email );
                } else {
                    $res = Delicat_TeraWallet::debit( $user_id, $amount, $note );
                    $msg = sprintf( '➖ %.2f HTG retirés du wallet de %s.', $amount, $user->user_email );
                }
                if ( is_wp_error( $res ) ) {
                    wp_send_json_error( [ 'message' => $res->get_error_message() ] );
                }
                wp_send_json_success( [ 'message' => $msg ] );

            default:
                wp_send_json_error( [ 'message' => 'Action invalide.' ] );
        }
    }

    /* ---- CSV export --------------------------------------------------- */

    public function maybe_export_csv(): void {
        if ( empty( $_GET['dr_export'] ) || ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'dr_export_nonce' );

        $what = sanitize_text_field( $_GET['dr_export'] );
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=delicat-' . $what . '-' . date( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'w' );

        if ( $what === 'transactions' ) {
            fputcsv( $out, [ 'ID','Email','Méthode','Montant HTG','TransCode','Statut','Wallet crédité','Date' ] );
            foreach ( Delicat_Database::get_all( [ 'limit' => 100000 ] ) as $r ) {
                fputcsv( $out, [ $r->id, $r->user_email, $r->tab . '/' . $r->methode,
                    number_format( $r->montant, 2 ), $r->trans_code, $r->statut,
                    $r->wallet_credited ? 'Oui' : 'Non', $r->created_at ] );
            }
        } elseif ( $what === 'clients' ) {
            fputcsv( $out, [ 'ID','Nom','Email','Solde HTG','Connexion','Transactions','Inscrit le' ] );
            foreach ( get_users( [ 'role__in' => [ 'customer', 'subscriber' ] ] ) as $u ) {
                $bal    = Delicat_TeraWallet::get_balance( $u->ID );
                $banned = get_user_meta( $u->ID, 'dr_banned_login', true ) ? 'BLOQUÉ' : 'OK';
                fputcsv( $out, [ $u->ID, $u->display_name, $u->user_email,
                    number_format( $bal, 2 ), $banned,
                    Delicat_Database::count_for_user( $u->ID ), $u->user_registered ] );
            }
        }
        fclose( $out );
        exit;
    }

    /* ---- Pages -------------------------------------------------------- */

    public function page(): void {
        $view_id = absint( $_GET['client'] ?? 0 );
        if ( $view_id ) { $this->detail( $view_id ); return; }

        $search = sanitize_text_field( $_GET['s'] ?? '' );
        $args   = [ 'number' => 200, 'orderby' => 'registered', 'order' => 'DESC' ];
        if ( $search ) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
        }
        $users = get_users( $args );
        $nonce = wp_create_nonce( 'delicat_client_nonce' );
        $ajax  = admin_url( 'admin-ajax.php' );
        $exp_n = wp_create_nonce( 'dr_export_nonce' );
        ?>
        <div class="wrap">
            <h1>👥 Clients
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-clients&dr_export=clients&_wpnonce=' . $exp_n ) ); ?>"
                   class="page-title-action">⬇️ Exporter CSV</a>
            </h1>

            <form method="get" style="margin:12px 0;">
                <input type="hidden" name="page" value="delicat-clients">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="Nom, email…" style="padding:6px 10px;width:260px;">
                <button class="button">🔍 Rechercher</button>
                <?php if ( $search ) : ?>
                    <a href="<?php echo admin_url( 'admin.php?page=delicat-clients' ); ?>" class="button">✖ Réinitialiser</a>
                <?php endif; ?>
            </form>

            <div id="dr-client-msg" style="display:none;margin-bottom:12px;padding:10px 16px;border-radius:6px;"></div>

            <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
                <thead><tr>
                    <th>Client</th><th>Email</th><th>Solde</th>
                    <th>Transactions</th><th>Statut</th><th style="width:330px;">Actions</th>
                </tr></thead>
                <tbody>
                <?php if ( empty( $users ) ) : ?>
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:#888;">Aucun client trouvé.</td></tr>
                <?php else : foreach ( $users as $u ) :
                    $bal            = Delicat_TeraWallet::get_balance( $u->ID );
                    $banned_login   = get_user_meta( $u->ID, 'dr_banned_login', true );
                    $banned_actions = get_user_meta( $u->ID, 'dr_banned_actions', true );
                    $txn            = Delicat_Database::count_for_user( $u->ID );
                ?>
                    <tr id="dr-client-<?php echo $u->ID; ?>">
                        <td><strong><?php echo esc_html( $u->display_name ); ?></strong><br>
                            <small style="color:#888;">ID: <?php echo $u->ID; ?></small></td>
                        <td><?php echo esc_html( $u->user_email ); ?></td>
                        <td><strong style="color:<?php echo $bal > 0 ? '#2e7d32' : '#999'; ?>;">
                            <?php echo number_format( $bal, 2 ); ?> HTG</strong></td>
                        <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-clients&client=' . $u->ID ) ); ?>">
                            <?php echo $txn; ?> transaction(s)</a></td>
                        <td>
                            <?php if ( $banned_login ) : ?>
                                <span style="background:#d9534f;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;">🔒 Connexion bloquée</span><br>
                            <?php endif; ?>
                            <?php if ( $banned_actions ) : ?>
                                <span style="background:#f0ad4e;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;">⛔ Actions bloquées</span>
                            <?php endif; ?>
                            <?php if ( ! $banned_login && ! $banned_actions ) : ?>
                                <span style="background:#5cb85c;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;">✅ Actif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $btn = function ( $act, $label, $color ) use ( $u, $nonce, $ajax ) {
                                printf( '<button class="button button-small dr-caction" data-uid="%d" data-act="%s" data-nonce="%s" data-ajax="%s" style="background:%s;color:#fff;border:none;margin:2px;">%s</button>',
                                    $u->ID, $act, $nonce, $ajax, $color, $label );
                            };
                            if ( $banned_login )   $btn( 'unban_login', '🔓 Débloquer login', '#5cb85c' );   else $btn( 'ban_login', '🔒 Bloquer login', '#d9534f' );
                            if ( $banned_actions ) $btn( 'unban_actions', '✅ Débloquer actions', '#5cb85c' ); else $btn( 'ban_actions', '⛔ Bloquer actions', '#f0ad4e' );
                            ?>
                            <button class="button button-small dr-adjust" data-uid="<?php echo $u->ID; ?>"
                                    data-nonce="<?php echo $nonce; ?>" data-ajax="<?php echo $ajax; ?>"
                                    data-email="<?php echo esc_attr( $u->user_email ); ?>"
                                    style="background:#1565c0;color:#fff;border:none;margin:2px;">💰 Ajuster solde</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php $this->js(); ?>
        <?php
    }

    private function detail( int $user_id ): void {
        $u = get_user_by( 'id', $user_id );
        if ( ! $u ) { echo '<div class="wrap"><p>Client introuvable.</p></div>'; return; }

        $bal  = Delicat_TeraWallet::get_balance( $user_id );
        $txns = Delicat_Database::get_for_user( $user_id, 100 );
        $sc   = [ 'en_attente' => '#f0ad4e', 'approuve' => '#5cb85c', 'rejete' => '#d9534f' ];
        $sl   = [ 'en_attente' => 'En attente', 'approuve' => 'Approuvé', 'rejete' => 'Rejeté' ];
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo admin_url( 'admin.php?page=delicat-clients' ); ?>" class="page-title-action">← Retour</a>
                👤 <?php echo esc_html( $u->display_name ); ?>
            </h1>

            <div style="display:flex;gap:20px;flex-wrap:wrap;margin:20px 0;">
                <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:18px 24px;min-width:200px;">
                    <div style="font-size:12px;color:#888;">Solde wallet</div>
                    <div style="font-size:26px;font-weight:800;color:#2e7d32;"><?php echo number_format( $bal, 2 ); ?> HTG</div>
                </div>
                <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:18px 24px;min-width:200px;">
                    <div style="font-size:12px;color:#888;">Email</div>
                    <div style="font-size:16px;font-weight:600;"><?php echo esc_html( $u->user_email ); ?></div>
                    <div style="font-size:12px;color:#888;margin-top:6px;">Inscrit le <?php echo esc_html( date( 'd/m/Y', strtotime( $u->user_registered ) ) ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:18px 24px;min-width:160px;">
                    <div style="font-size:12px;color:#888;">Total transactions</div>
                    <div style="font-size:26px;font-weight:800;"><?php echo count( $txns ); ?></div>
                </div>
            </div>

            <h2>Historique des transactions</h2>
            <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
                <thead><tr><th>#</th><th>Méthode</th><th>Montant</th><th>TransCode</th><th>Preuve</th><th>Statut</th><th>Wallet</th><th>Date</th></tr></thead>
                <tbody>
                <?php if ( empty( $txns ) ) : ?>
                    <tr><td colspan="8" style="text-align:center;padding:24px;color:#888;">Aucune transaction.</td></tr>
                <?php else : foreach ( $txns as $r ) : ?>
                    <tr>
                        <td><?php echo $r->id; ?></td>
                        <td><?php echo strtoupper( $r->tab ); ?> / <?php echo esc_html( $r->methode ); ?></td>
                        <td><strong><?php echo number_format( $r->montant, 2 ); ?> HTG</strong></td>
                        <td><?php echo esc_html( $r->trans_code ?: '—' ); ?></td>
                        <td><?php echo $r->preuve_url ? '<a href="' . esc_url( Delicat_Files::view_url( $r->preuve_url ) ) . '" target="_blank">📎</a>' : '—'; ?></td>
                        <td><span style="background:<?php echo $sc[ $r->statut ] ?? '#999'; ?>;color:#fff;padding:3px 9px;border-radius:10px;font-size:11px;"><?php echo $sl[ $r->statut ] ?? $r->statut; ?></span></td>
                        <td><?php echo $r->wallet_credited ? '✅' : '—'; ?></td>
                        <td style="white-space:nowrap;"><?php echo esc_html( $r->created_at ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function js(): void {
        ?>
        <script>
        (function($){
            function showMsg(txt, ok){
                $('#dr-client-msg').css({background: ok?'#e8f5e9':'#ffebee',
                    border:'1px solid '+(ok?'#a5d6a7':'#ef9a9a'), color: ok?'#2e7d32':'#c62828'})
                    .html(txt).show();
            }
            $(document).on('click','.dr-caction',function(){
                var b=$(this);
                var labels={ban_login:'bloquer la connexion',unban_login:'débloquer la connexion',
                             ban_actions:'bloquer les recharges/achats',unban_actions:'débloquer les recharges/achats'};
                if(!confirm('Confirmer : '+labels[b.data('act')]+' ?')) return;
                b.prop('disabled',true);
                $.post(b.data('ajax'),{action:'delicat_client_action',client_action:b.data('act'),
                    user_id:b.data('uid'),nonce:b.data('nonce')},function(res){
                    showMsg(res.data.message,res.success);
                    if(res.success) setTimeout(function(){location.reload();},1200);
                    else b.prop('disabled',false);
                });
            });
            $(document).on('click','.dr-adjust',function(){
                var b=$(this);
                var type=confirm('OK = AJOUTER des HTG\nAnnuler = RETIRER des HTG\n\nClient: '+b.data('email'))?'credit':'debit';
                var amt=prompt((type==='credit'?'Montant à AJOUTER':'Montant à RETIRER')+' (HTG):','');
                if(amt===null||isNaN(parseFloat(amt))||parseFloat(amt)<=0){return;}
                b.prop('disabled',true);
                $.post(b.data('ajax'),{action:'delicat_client_action',client_action:'adjust_balance',
                    user_id:b.data('uid'),amount:amt,adjust_type:type,nonce:b.data('nonce')},function(res){
                    showMsg(res.data.message,res.success);
                    if(res.success) setTimeout(function(){location.reload();},1300);
                    else b.prop('disabled',false);
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}
