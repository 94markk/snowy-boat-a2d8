<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BATCH A — Client experience features.
 *
 *  1) Status tracking: shortcode [delicat_statut] shows the logged-in client
 *     their own recharge requests with live status badges. No WhatsApp needed.
 *  2) Branded receipt: a printable/screenshot-able receipt per approved
 *     recharge, opened from the status page or via ?delicat_recu=ID.
 *
 * Read-only and per-user scoped (a client only ever sees their own rows).
 */
class Delicat_Client_Ux {

    public function __construct() {
        add_shortcode( 'delicat_statut', [ $this, 'status_page' ] );
        add_action( 'init', [ $this, 'maybe_render_receipt' ] );
    }

    /* ---- Helpers ----------------------------------------------------- */

    private static function badge( string $statut ): string {
        switch ( $statut ) {
            case 'approuve':
                return '<span class="dx-badge dx-ok">✅ Approuvé</span>';
            case 'rejete':
                return '<span class="dx-badge dx-no">❌ Rejeté</span>';
            default:
                return '<span class="dx-badge dx-wait">⏳ En attente</span>';
        }
    }

    private static function brand(): array {
        return [
            'name'    => get_bloginfo( 'name' ) ?: 'Delicat Store Haiti',
            'primary' => '#1a1a2e',
            'accent'  => '#5b7ccc',
        ];
    }

    /* ---- 1) STATUS TRACKING PAGE ------------------------------------- */

    public function status_page(): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="dx-wrap"><p>Veuillez vous connecter pour voir vos recharges.</p></div>';
        }
        $uid  = get_current_user_id();
        $rows = Delicat_Database::get_for_user( $uid, 50 );

        ob_start();
        ?>
        <div class="dx-wrap">
            <h3 class="dx-title">Mes recharges</h3>
            <?php if ( empty( $rows ) ) : ?>
                <p class="dx-empty">Vous n'avez pas encore de demande de recharge.</p>
            <?php else : ?>
                <div class="dx-list">
                    <?php foreach ( $rows as $r ) :
                        $recu = add_query_arg( [ 'delicat_recu' => (int) $r->id ], home_url( '/' ) );
                    ?>
                        <div class="dx-card">
                            <div class="dx-row">
                                <span class="dx-amt"><?php echo number_format( (float) $r->montant, 2 ); ?> HTG</span>
                                <?php echo self::badge( $r->statut ); ?>
                            </div>
                            <div class="dx-meta">
                                <span>#<?php echo (int) $r->id; ?></span>
                                <span><?php echo esc_html( strtoupper( $r->tab ) ); ?></span>
                                <span><?php echo esc_html( mysql2date( 'd/m/Y H:i', $r->created_at ) ); ?></span>
                            </div>
                            <?php if ( $r->trans_code ) : ?>
                                <div class="dx-trans">TransCode : <?php echo esc_html( $r->trans_code ); ?></div>
                            <?php endif; ?>
                            <?php if ( $r->statut === 'approuve' ) : ?>
                                <a class="dx-recu-btn" href="<?php echo esc_url( $recu ); ?>" target="_blank">🧾 Voir le reçu</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php echo self::styles(); ?>
        <?php
        return ob_get_clean();
    }

    /* ---- 2) BRANDED RECEIPT ------------------------------------------ */

    public function maybe_render_receipt(): void {
        if ( empty( $_GET['delicat_recu'] ) ) return;
        if ( ! is_user_logged_in() ) {
            wp_die( 'Veuillez vous connecter pour voir ce reçu.' );
        }

        $id  = absint( $_GET['delicat_recu'] );
        $r   = Delicat_Database::get( $id );
        $uid = get_current_user_id();

        // Security: a client may only view their OWN receipt (admins can view any).
        if ( ! $r || ( (int) $r->user_id !== $uid && ! current_user_can( 'manage_options' ) ) ) {
            wp_die( 'Reçu introuvable.' );
        }
        if ( $r->statut !== 'approuve' ) {
            wp_die( 'Le reçu est disponible uniquement pour les recharges approuvées.' );
        }

        $b = self::brand();
        $user = get_userdata( (int) $r->user_id );
        $name = $user ? $user->display_name : $r->user_email;

        header( 'Content-Type: text/html; charset=utf-8' );
        ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reçu #<?php echo (int) $r->id; ?> — <?php echo esc_html( $b['name'] ); ?></title>
<style>
    * { box-sizing: border-box; }
    body { margin:0; background:#eef0f5; font-family:'Segoe UI',Arial,sans-serif; padding:24px; }
    .recu { max-width:420px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden;
            box-shadow:0 10px 40px rgba(0,0,0,.15); }
    .recu-head { background:<?php echo $b['primary']; ?>; color:#fff; padding:26px 24px; text-align:center; }
    .recu-head h1 { margin:0; font-size:22px; letter-spacing:1px; }
    .recu-head .sub { color:<?php echo $b['accent']; ?>; font-weight:700; font-size:13px; margin-top:4px; }
    .recu-badge { display:inline-block; margin-top:14px; background:#1b5e20; color:#fff;
                  padding:6px 16px; border-radius:20px; font-size:13px; font-weight:700; }
    .recu-body { padding:24px; }
    .recu-amt { text-align:center; font-size:34px; font-weight:800; color:<?php echo $b['primary']; ?>; margin:6px 0 18px; }
    .recu-line { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #eee; font-size:14px; }
    .recu-line span:first-child { color:#888; }
    .recu-line span:last-child { font-weight:700; color:#222; text-align:right; }
    .recu-foot { text-align:center; padding:18px 24px 26px; color:#999; font-size:12px; }
    .recu-print { display:block; width:100%; margin-top:16px; background:<?php echo $b['accent']; ?>;
                  color:#fff; border:none; padding:13px; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; }
    @media print { body { background:#fff; padding:0; } .recu-print { display:none; } .recu { box-shadow:none; } }
</style>
</head>
<body>
    <div class="recu">
        <div class="recu-head">
            <h1><?php echo esc_html( $b['name'] ); ?></h1>
            <div class="sub">REÇU DE RECHARGE</div>
            <div class="recu-badge">✅ Paiement confirmé</div>
        </div>
        <div class="recu-body">
            <div class="recu-amt"><?php echo number_format( (float) $r->montant, 2 ); ?> HTG</div>
            <div class="recu-line"><span>Reçu N°</span><span>#<?php echo (int) $r->id; ?></span></div>
            <div class="recu-line"><span>Client</span><span><?php echo esc_html( $name ); ?></span></div>
            <div class="recu-line"><span>Méthode</span><span><?php echo esc_html( strtoupper( $r->tab ) ); ?></span></div>
            <?php if ( $r->trans_code ) : ?>
            <div class="recu-line"><span>TransCode</span><span><?php echo esc_html( $r->trans_code ); ?></span></div>
            <?php endif; ?>
            <div class="recu-line"><span>Date</span><span><?php echo esc_html( mysql2date( 'd/m/Y H:i', $r->created_at ) ); ?></span></div>
            <div class="recu-line"><span>Statut</span><span style="color:#1b5e20;">Crédité au wallet</span></div>
            <button class="recu-print" onclick="window.print()">🖨️ Imprimer / Enregistrer</button>
        </div>
        <div class="recu-foot">
            Merci pour votre confiance.<br>
            <?php echo esc_html( parse_url( home_url(), PHP_URL_HOST ) ); ?>
        </div>
    </div>
</body>
</html>
        <?php
        exit;
    }

    /* ---- Styles for the status page ---------------------------------- */

    private static function styles(): string {
        $b = self::brand();
        return '<style>
            .dx-wrap { max-width:560px; margin:0 auto; font-family:"Segoe UI",Arial,sans-serif; }
            .dx-title { color:' . $b['primary'] . '; font-size:20px; margin:0 0 14px; }
            .dx-empty { color:#888; padding:20px; background:#f7f7f9; border-radius:10px; text-align:center; }
            .dx-list { display:flex; flex-direction:column; gap:12px; }
            .dx-card { background:#fff; border:1px solid #e6e6ee; border-radius:12px; padding:14px 16px;
                       box-shadow:0 2px 8px rgba(0,0,0,.04); }
            .dx-row { display:flex; justify-content:space-between; align-items:center; }
            .dx-amt { font-size:20px; font-weight:800; color:' . $b['primary'] . '; }
            .dx-meta { display:flex; gap:12px; color:#999; font-size:12px; margin-top:6px; flex-wrap:wrap; }
            .dx-trans { color:#666; font-size:12px; margin-top:6px; word-break:break-all; }
            .dx-badge { padding:5px 12px; border-radius:20px; font-size:12px; font-weight:700; }
            .dx-ok { background:#e8f5e9; color:#1b5e20; }
            .dx-no { background:#ffebee; color:#b71c1c; }
            .dx-wait { background:#fff8e1; color:#946200; }
            .dx-recu-btn { display:inline-block; margin-top:10px; background:' . $b['accent'] . '; color:#fff;
                           text-decoration:none; padding:8px 14px; border-radius:8px; font-size:13px; font-weight:700; }
            .dx-wrap a.dx-recu-btn { color:#fff; }
            /* 6.5.0: dark mode (site theme) */
            html.dlc-theme-dark .dx-title, html.dlc-theme-dark .dx-amt { color:#f4f5fb; }
            html.dlc-theme-dark .dx-empty { color:#a7aec6; background:rgba(255,255,255,.05); }
            html.dlc-theme-dark .dx-card { background:linear-gradient(150deg,rgba(44,50,108,.5) 0%,rgba(20,24,58,.58) 46%,rgba(12,15,38,.66) 100%);
                       border-color:rgba(255,255,255,.085); box-shadow:inset 0 1px 0 rgba(255,255,255,.07),5px 8px 18px rgba(0,0,0,.42); }
            html.dlc-theme-dark .dx-meta { color:#a7aec6; }
            html.dlc-theme-dark .dx-trans { color:#a7aec6; }
            html.dlc-theme-dark .dx-ok { background:rgba(66,213,156,.14); color:#7beab9; }
            html.dlc-theme-dark .dx-no { background:rgba(255,123,134,.14); color:#ffb3ba; }
            html.dlc-theme-dark .dx-wait { background:rgba(255,159,90,.14); color:#ffc08a; }
            html.dlc-theme-dark .dx-wrap a.dx-recu-btn { background:linear-gradient(115deg,#3a5fe0,#8350e6); color:#fff; }
        </style>';
    }
}
