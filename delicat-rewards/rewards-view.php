<?php
defined( 'ABSPATH' ) || exit;

// Refresh an expired REST nonce using the authenticated WordPress cookie only.
add_action( 'wp_ajax_delicat_rewards_session', function () {
    if ( ! is_user_logged_in() ) wp_send_json_error( array( 'message' => 'Connectez-vous.' ), 401 );
    nocache_headers();
    wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ), 'userId' => get_current_user_id() ) );
} );

add_action( 'wp_ajax_nopriv_delicat_rewards_session', function () {
    nocache_headers();
    wp_send_json_error( array( 'message' => 'Connectez-vous.', 'userId' => 0 ), 401 );
} );

function delicat_rewards_view() {
    static $rendered = false;
    if ( $rendered ) return ''; // IDs and claim handlers must only exist once per page.
    $rendered = true;
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    if ( ! headers_sent() ) nocache_headers();
    if ( ! delicat_rewards_on() ) return delicat_paused_html();
    if ( ! is_user_logged_in() ) return delicat_login_html( 'Connectez-vous pour consulter vos bonus et récupérer vos PINs.' );
    $config = json_decode( delicat_config_json(), true );
    $config['userId'] = get_current_user_id();
    $config['sessionUrl'] = admin_url( 'admin-ajax.php' );
    $base = plugin_dir_url( __FILE__ );
    // The file time busts LiteSpeed/Cloudflare/browser copies whenever an asset changes.
    $asset = function ( $file ) use ( $base ) {
        $path = __DIR__ . '/' . $file;
        return $base . $file . '?ver=' . ( is_readable( $path ) ? (string) filemtime( $path ) : DELICAT_REWARDS_VERSION );
    };
    ob_start();
    ?>
<link rel="stylesheet" href="<?php echo esc_url( $asset( 'assets/rewards.css' ) ); ?>">
<section class="drw" id="drw" aria-label="Récompenses Free Fire">
    <header class="drw-hero">
        <div id="drw-brand" class="drw-brand"></div>
        <p class="drw-eyebrow" id="drw-program">BONUS FREE FIRE</p>
        <h1 id="drw-title">Mes récompenses</h1>
        <p>Achetez vos diamants, débloquez vos bonus et récupérez vos PINs ici.</p>
    </header>
    <div class="drw-notice" id="drw-notice" role="status" hidden></div>
    <section class="drw-summary" aria-label="Progression du mois">
        <div class="drw-next">
            <div class="drw-ring" id="drw-ring"><strong id="drw-percent">—</strong></div>
            <div><span class="drw-kicker">VOTRE PROCHAIN BONUS</span><h2 id="drw-next-title">Chargement…</h2><p id="drw-next-message">Vérification de vos achats.</p></div>
        </div>
        <div class="drw-stats"><div><span>Achats Free Fire ce mois</span><strong id="drw-spend">—</strong></div><div><span>Bonus réclamés ce mois</span><strong id="drw-total">—</strong></div></div>
        <p class="drw-reset" id="drw-reset">Réinitialisation mensuelle</p>
    </section>
    <div class="drw-heading"><h2>Vos bonus</h2><span id="drw-ready-count"></span></div>
    <p class="drw-help">Chaque pack progresse indépendamment. Seuls vos achats éligibles validés sont comptés.</p>
    <div class="drw-tiers" id="drw-tiers" aria-busy="true"><p>Chargement de vos bonus…</p></div>
    <section class="drw-history" aria-label="Mes PINs récompenses">
        <div class="drw-heading"><h2>Mes PINs récompenses</h2><button type="button" class="drw-secondary" id="drw-history-refresh">Actualiser</button></div>
        <p class="drw-help">Retrouvez vos PINs ici, même après avoir quitté cette page.</p>
        <div id="drw-history-list" aria-live="polite">Chargement…</div>
        <div class="drw-pagination" id="drw-pagination" hidden><button type="button" class="drw-secondary" id="drw-prev">Précédent</button><span id="drw-page"></span><button type="button" class="drw-secondary" id="drw-next">Suivant</button></div>
    </section>
    <details class="drw-how"><summary>Comment ça marche ?</summary><ol><li>Achetez vos packs Free Fire et attendez la validation de vos commandes.</li><li>Lorsque l’objectif est atteint, cliquez sur « Réclamer ».</li><li>Copiez le PIN reçu, puis cliquez sur « Utiliser mon PIN » pour le valider.</li></ol><p id="drw-note"></p></details>
</section>
<dialog id="drw-dialog" class="drw-dialog" aria-labelledby="drw-dialog-title" aria-describedby="drw-dialog-message">
    <div class="drw-dialog-top"><span class="drw-eyebrow">VOTRE RÉCOMPENSE</span><button type="button" class="drw-close" id="drw-close" aria-label="Fermer">×</button></div>
    <h2 id="drw-dialog-title">Votre PIN</h2>
    <p id="drw-dialog-message" role="status"></p>
    <div id="drw-pins"></div>
    <div class="drw-dialog-actions"><button type="button" class="drw-secondary" id="drw-pin-refresh">Actualiser le PIN</button><a class="drw-primary" id="drw-redeem" href="https://delicastoreha.com/free-fire-redeem/" rel="noreferrer">Utiliser mon PIN ↗</a></div>
    <p class="drw-private">Votre PIN est personnel. Copiez-le avant d’ouvrir la page de validation.</p>
</dialog>
<div id="drw-toast" class="drw-toast" role="status" aria-live="polite" hidden></div>
<script type="application/json" id="drw-config"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
<script src="<?php echo esc_url( $asset( 'assets/rewards.js' ) ); ?>" defer></script>
    <?php
    return ob_get_clean();
}
