<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NATCASH / MONCASH AUTO-VERIFY (no API — uses the payment SMS).
 *
 * Flow:
 *  1. The client pays, then submits the amount + the TransCode / Txn ID.
 *  2. Natcash / MonCash sends the receipt SMS to the shop phone.
 *  3. The SMS forwarder (secret key) or the staff app (device auth) POSTs the
 *     raw SMS here; the SMS Guard checks it is genuine (balance chain, ...).
 *  4. A pending request with the exact TransCode + exact amount is credited.
 *
 * Security:
 *  - One TransCode = one credit (UNIQUE ledger + named lock + atomic claim).
 *  - Forwarder: secret key, preferably in a header (6.6.4), per-IP throttle
 *    and lockout keyed on the Cloudflare-aware visitor address.
 *  - The retired Natcash PIN ("kontni") flow was removed in 6.6.4.
 */
class Delicat_Natcash_Auto {

    /** Number of SMS kept in the diagnostic log (raised from 15 so a burst of fakes cannot push real receipts out). */
    const LOG_MAX = 40;

    /** 6.6.4 (RCH-04): how the forwarder sent its key last time ('header' | 'query' | 'body'). */
    const OPT_FWD_AUTH = 'delicat_forward_auth_last';

    /** Header the forwarder should use for its key (Authorization: Bearer <key> also works). */
    const FWD_HEADER = 'X-Delicat-Key';

    /** Cloudflare edge ranges: CF-Connecting-IP is believed only from these (same list as the Builder). */
    const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @var self|null */
    private static $instance = null;

    public static function instance(): ?self {
        return self::$instance;
    }

    public function __construct() {
        self::$instance = $this;
        add_action( 'rest_api_init', [ $this, 'routes' ] );
        add_action( 'admin_menu', [ $this, 'menu' ], 30 );

        // When a recharge is credited by ANY route, clear the matching red
        // entry from the diagnostic log so the page reflects reality.
        add_action( 'delicat_recharge_approved', [ $this, 'on_recharge_approved' ], 20, 1 );
        add_action( 'admin_post_delicat_clear_natcash_log', [ $this, 'handle_clear_log' ] );
        // 6.6.4 (RCH-04): the key is never printed in the page; it is fetched on a click.
        add_action( 'wp_ajax_delicat_forward_secret', [ $this, 'ajax_forward_secret' ] );
        add_action( 'admin_post_delicat_forward_rotate', [ $this, 'handle_rotate_secret' ] );
    }

    /** Admin only: hand the forwarder key (or the old-style full URL) to the copy button. */
    public function ajax_forward_secret(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }
        check_ajax_referer( 'delicat_forward_secret', 'nonce' );
        nocache_headers();
        $what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : 'key';
        $key  = self::forward_secret();
        wp_send_json_success( [ 'value' => 'url' === $what ? rest_url( 'delicat/v1/natcash/forward' ) . '?key=' . rawurlencode( $key ) : $key ] );
    }

    /** Admin only: replace the forwarder key (the old one stops working at once). */
    public function handle_rotate_secret(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
        check_admin_referer( 'delicat_forward_rotate' );
        update_option( 'delicat_natcash_secret', wp_generate_password( 24, false, false ), false );
        delete_option( self::OPT_FWD_AUTH );
        wp_safe_redirect( add_query_arg( 'smsg_msg', rawurlencode( 'Nouvelle clé du transmetteur créée. Collez-la maintenant dans votre application de transfert SMS : l\'ancienne clé ne fonctionne plus.' ), admin_url( 'admin.php?page=delicat-natcash-log' ) ) );
        exit;
    }

    /** Clear the diagnostic log (admin only). Useful after fixing a batch. */
    public function handle_clear_log(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
        check_admin_referer( 'delicat_clear_natcash_log' );
        update_option( 'delicat_natcash_log', [], false );
        wp_safe_redirect( admin_url( 'admin.php?page=delicat-natcash-log' ) );
        exit;
    }

    /** Resolve any log entry whose TransCode matches a now-credited recharge. */
    public function on_recharge_approved( $recharge_id ): void {
        if ( ! class_exists( 'Delicat_Database' ) ) return;
        $r = Delicat_Database::get( (int) $recharge_id );
        if ( ! $r || empty( $r->trans_code ) ) return;
        self::resolve_log_for_code( (string) $r->trans_code, 'validé' );
    }

    public function menu(): void {
        add_submenu_page( 'delicat-recharges', 'Natcash Auto — Diagnostic', 'Natcash SMS',
            'manage_options', 'delicat-natcash-log', [ $this, 'log_page' ] );
    }

    public function log_page(): void {
        $entries = get_option( 'delicat_natcash_log', [] );
        if ( ! is_array( $entries ) ) $entries = [];

        // v6.3.0: entries written before the SMS Guard have no uid; give them one
        // so the "Authentique" button can target them.
        $backfilled = false;
        foreach ( $entries as $i => $e ) {
            if ( empty( $e['uid'] ) ) { $entries[ $i ]['uid'] = substr( md5( $i . ( $e['time'] ?? '' ) . ( $e['sms'] ?? '' ) ), 0, 10 ); $backfilled = true; }
        }
        if ( $backfilled ) update_option( 'delicat_natcash_log', $entries, false );

        global $wpdb;
        $tbl = $wpdb->prefix . 'delicat_recharges';

        // Live stats from the log.
        $total = count( $entries );
        $ok_count = 0;
        foreach ( $entries as $e ) {
            $is_ok = self::is_approved_result( (string) ( $e['result'] ?? '' ) )
                     || ! empty( $e['resolved'] );   // later fixed by hand counts as OK
            if ( $is_ok ) $ok_count++;
        }
        $fail_count = $total - $ok_count;
        $last_time  = $total ? ( $entries[0]['time'] ?? '' ) : '';

        // Forwarder health (shared with the watchdog).
        $last_seen = (int) get_option( 'delicat_forwarder_last_seen', 0 );
        $silence   = $last_seen ? ( time() - $last_seen ) : null;
        if ( $silence === null )      { $hb_state = 'unknown'; $hb_label = 'Jamais vu'; }
        elseif ( $silence < 3600 )    { $hb_state = 'ok';      $hb_label = 'Actif'; }
        elseif ( $silence < 21600 )   { $hb_state = 'warn';    $hb_label = 'Silencieux'; }
        else                          { $hb_state = 'bad';     $hb_label = 'Arrêté'; }

        // Requests currently waiting — the other half of every mismatch.
        $pending = $wpdb->get_results(
            "SELECT id, user_email, montant, trans_code, tab, created_at
             FROM {$tbl}
             WHERE statut = 'en_attente' AND tab IN ('natcash_tc','moncash')
             ORDER BY created_at DESC LIMIT 10" );

        // 6.6.4 (RCH-04): the key is never printed in full; see render_forwarder_setup().
        $fwd_auth = get_option( self::OPT_FWD_AUTH, [] );
        $fwd_auth = is_array( $fwd_auth ) ? $fwd_auth : [];
        ?>
        <div class="wrap dlc-diag">
            <h1 style="display:flex;align-items:center;gap:10px;">
                <span>Natcash Auto</span>
                <span style="font-size:13px;font-weight:400;color:#666;">Diagnostic en temps réel</span>
            </h1>

            <?php if ( 'query' === ( $fwd_auth['method'] ?? '' ) ) : ?>
                <div class="notice notice-warning inline" style="margin:12px 0;padding:10px 14px;">
                    <p style="margin:0 0 6px;"><strong>Votre transmetteur utilise encore la clé dans l'URL : passez à l'en-tête.</strong>
                        Une clé dans l'adresse peut finir dans l'historique du navigateur et les journaux du serveur.</p>
                    <p style="margin:0;">Voir les étapes simples dans « Adresse du forwarder » en bas de cette page. Rien ne casse en attendant : l'ancienne méthode marche toujours.</p>
                </div>
            <?php endif; ?>

            <!-- Stat cards -->
            <div class="dlc-cards">
                <div class="dlc-card dlc-<?php echo esc_attr( $hb_state ); ?>">
                    <div class="dlc-card-label">Forwarder</div>
                    <div class="dlc-card-value"><?php echo esc_html( $hb_label ); ?></div>
                    <div class="dlc-card-sub">
                        <?php echo $silence === null ? 'Aucun SMS reçu'
                            : 'il y a ' . esc_html( human_time_diff( time() - $silence, time() ) ); ?>
                    </div>
                </div>
                <div class="dlc-card dlc-ok">
                    <div class="dlc-card-label">Validés</div>
                    <div class="dlc-card-value"><?php echo (int) $ok_count; ?></div>
                    <div class="dlc-card-sub">sur <?php echo (int) $total; ?> SMS récents</div>
                </div>
                <div class="dlc-card <?php echo $fail_count ? 'dlc-warn' : 'dlc-ok'; ?>">
                    <div class="dlc-card-label">Non validés</div>
                    <div class="dlc-card-value"><?php echo (int) $fail_count; ?></div>
                    <div class="dlc-card-sub">à examiner</div>
                </div>
                <div class="dlc-card dlc-neutral">
                    <div class="dlc-card-label">En attente</div>
                    <div class="dlc-card-value"><?php echo count( (array) $pending ); ?></div>
                    <div class="dlc-card-sub">demandes clients</div>
                </div>
            </div>

            <?php if ( class_exists( 'Delicat_SMS_Guard' ) ) Delicat_SMS_Guard::render_status_panel(); ?>

            <!-- Pending requests: the other side of every mismatch -->
            <div class="dlc-panel">
                <div class="dlc-panel-head">
                    <strong>Demandes en attente</strong>
                    <span class="dlc-hint">Un SMS ne se valide que s'il correspond exactement à l'une de ces lignes (TransCode + montant).</span>
                </div>
                <?php if ( empty( $pending ) ) : ?>
                    <p class="dlc-empty">Aucune demande en attente.</p>
                <?php else : ?>
                    <table class="dlc-table">
                        <thead><tr><th>#</th><th>Client</th><th>Montant</th><th>TransCode attendu</th><th>Méthode</th></tr></thead>
                        <tbody>
                        <?php foreach ( $pending as $pr ) : ?>
                            <tr>
                                <td><?php echo (int) $pr->id; ?></td>
                                <td><?php echo esc_html( $pr->user_email ); ?></td>
                                <td><strong><?php echo esc_html( number_format( (float) $pr->montant, 2 ) ); ?></strong> HTG</td>
                                <td><code><?php echo esc_html( $pr->trans_code ?: '—' ); ?></code></td>
                                <td><span class="dlc-pill"><?php echo esc_html( strtoupper( $pr->tab ) ); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- SMS activity -->
            <div class="dlc-panel">
                <div class="dlc-panel-head">
                    <strong>Derniers SMS reçus</strong>
                    <span class="dlc-hint">
                        <label style="font-weight:400;">
                            <input type="checkbox" id="dlc-auto" checked> Actualisation auto (20s)
                        </label>
                        &nbsp;
                        <a class="button button-small"
                           href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_clear_natcash_log' ), 'delicat_clear_natcash_log' ) ); ?>"
                           onclick="return confirm('Vider le journal des SMS ?');">Vider le journal</a>
                    </span>
                </div>
                <?php if ( empty( $entries ) ) : ?>
                    <p class="dlc-empty">Aucun SMS reçu. Vérifiez que l'app forwarder tourne et pointe vers l'adresse ci-dessous.</p>
                <?php else : ?>
                    <div class="dlc-feed">
                        <?php foreach ( $entries as $e ) :
                            $res      = (string) ( $e['result'] ?? '' );
                            $resolved = ! empty( $e['resolved'] );
                            $ok       = self::is_approved_result( $res );
                            $trust    = (string) ( $e['trust'] ?? '' );
                            if ( $resolved )        $cls = 'res';
                            elseif ( $ok )          $cls = 'ok';
                            elseif ( $trust === 'suspect' ) $cls = 'sus';
                            elseif ( $trust === 'held' )    $cls = 'held';
                            elseif ( strpos( $res, '↩' ) !== false ) $cls = 'dup';
                            else                    $cls = 'fail'; ?>
                            <div class="dlc-item dlc-item-<?php echo esc_attr( $cls ); ?>">
                                <div class="dlc-item-top">
                                    <span class="dlc-badge dlc-badge-<?php echo esc_attr( $cls ); ?>">
                                        <?php
                                        if ( $cls === 'res' )      echo 'RÉGULARISÉ';
                                        elseif ( $cls === 'ok' )   echo 'VALIDÉ';
                                        elseif ( $cls === 'sus' )  echo 'SUSPECT';
                                        elseif ( $cls === 'held' ) echo 'RETENU';
                                        elseif ( $cls === 'dup' )  echo 'DOUBLON';
                                        else                       echo 'NON VALIDÉ';
                                        ?>
                                    </span>
                                    <span class="dlc-result"><?php echo esc_html( $res ); ?></span>
                                    <span class="dlc-time"><?php echo esc_html( $e['time'] ?? '' ); ?></span>
                                </div>
                                <?php if ( '' === (string) ( $e['sms'] ?? '' ) && ! empty( $e['sms_purged'] ) ) : ?>
                                    <code class="dlc-sms" style="color:#7b8794;">Texte du SMS effacé le <?php echo esc_html( (string) $e['sms_purged'] ); ?> (conservation limitée, Paramètres).</code>
                                <?php else : ?>
                                    <code class="dlc-sms"><?php echo esc_html( $e['sms'] ?? '' ); ?></code>
                                <?php endif; ?>
                                <?php
                                $details = [];
                                if ( $resolved ) {
                                    echo '<div class="dlc-resolved">Régularisé '
                                        . esc_html( (string) ( $e['resolved_by'] ?? '' ) )
                                        . ( ! empty( $e['resolved_at'] ) ? ' — ' . esc_html( (string) $e['resolved_at'] ) : '' )
                                        . '</div>';
                                }
                                foreach ( [ 'sms_code','sms_montant','amount','pin','sender','trans_code','attendu','recu','en_attente','from','smsc','balance','sms_time','payer','path','label' ] as $k ) {
                                    if ( isset( $e[ $k ] ) && $e[ $k ] !== '' && $e[ $k ] !== null ) {
                                        $details[] = '<b>' . esc_html( $k ) . '</b>: ' . esc_html( (string) $e[ $k ] );
                                    }
                                }
                                if ( $details ) echo '<div class="dlc-details">' . implode( ' &nbsp;·&nbsp; ', $details ) . '</div>';
                                if ( class_exists( 'Delicat_SMS_Guard' ) ) Delicat_SMS_Guard::render_entry_trust( $e );
                                ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php $this->render_forwarder_setup( $fwd_auth ); ?>
        </div>

        <style>
        .dlc-diag{max-width:1100px}
        .dlc-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:18px 0}
        .dlc-card{background:#fff;border:1px solid #e3e6ec;border-left-width:5px;border-radius:12px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
        .dlc-card-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#7b8794;font-weight:700}
        .dlc-card-value{font-size:26px;font-weight:800;line-height:1.2;margin:4px 0;color:#16223a}
        .dlc-card-sub{font-size:12px;color:#7b8794}
        .dlc-ok{border-left-color:#2e7d32}.dlc-warn{border-left-color:#ef6c00}
        .dlc-bad{border-left-color:#c62828}.dlc-neutral{border-left-color:#284696}
        .dlc-unknown{border-left-color:#9aa5b1}
        .dlc-panel{background:#fff;border:1px solid #e3e6ec;border-radius:12px;padding:16px 18px;margin-bottom:18px}
        .dlc-panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px}
        .dlc-hint{font-size:12px;color:#7b8794}
        .dlc-empty{color:#7b8794;font-style:italic;margin:6px 0}
        .dlc-table{width:100%;border-collapse:collapse;font-size:13px}
        .dlc-table th{text-align:left;padding:8px;border-bottom:2px solid #eef1f5;color:#5b6675;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
        .dlc-table td{padding:9px 8px;border-bottom:1px solid #f2f4f7}
        .dlc-pill{background:#eef2ff;color:#284696;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700}
        .dlc-feed{display:flex;flex-direction:column;gap:10px;max-height:560px;overflow:auto}
        .dlc-item{border:1px solid #eef1f5;border-left-width:4px;border-radius:10px;padding:10px 12px;background:#fcfdff}
        .dlc-item-ok{border-left-color:#2e7d32}
        .dlc-item-fail{border-left-color:#c62828}
        .dlc-item-dup{border-left-color:#9aa5b1}
        .dlc-item-sus{border-left-color:#b71c1c;background:#fff5f5}
        .dlc-item-held{border-left-color:#ef6c00;background:#fff9f2}
        .dlc-badge-sus{background:#b71c1c}.dlc-badge-held{background:#ef6c00}
        .dlc-item-res{border-left-color:#2e7d32;background:#f4fbf5}
        .dlc-badge-res{background:#2e7d32}
        .dlc-resolved{margin-top:6px;font-size:12px;font-weight:700;color:#2e7d32}
        .dlc-item-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px}
        .dlc-badge{font-size:10px;font-weight:800;letter-spacing:.05em;border-radius:5px;padding:3px 8px;color:#fff}
        .dlc-badge-ok{background:#2e7d32}.dlc-badge-fail{background:#c62828}.dlc-badge-dup{background:#9aa5b1}
        .dlc-result{font-weight:700;font-size:13px;color:#16223a}
        .dlc-time{margin-left:auto;font-size:11px;color:#9aa5b1}
        .dlc-sms{display:block;font-size:11px;background:#f6f8fb;padding:8px;border-radius:6px;word-break:break-word;color:#2b3648}
        .dlc-details{margin-top:6px;font-size:11px;color:#5b6675}
        .dlc-url{display:block;padding:10px;background:#f6f8fb;border-radius:6px;word-break:break-all;font-size:12px}
        @media(prefers-color-scheme:dark){}
        </style>

        <script>
        (function(){
            var t=null;
            function arm(){ if(t) clearTimeout(t); t=setTimeout(function(){ location.reload(); },20000); }
            var cb=document.getElementById('dlc-auto');
            if(cb){ cb.addEventListener('change',function(){ if(cb.checked){arm();} else if(t){clearTimeout(t);} }); if(cb.checked) arm(); }
            /* 6.6.4: copy buttons. The key is not in the page: it is fetched on the click,
               copied, and shown (once) only when the browser cannot copy by itself. */
            function done(b,label){ b.textContent='Copié'; setTimeout(function(){ b.textContent=label; },1800); }
            function copyText(text,b,label){
                if(navigator.clipboard && window.isSecureContext){
                    navigator.clipboard.writeText(text).then(function(){ done(b,label); },function(){ window.prompt('Copiez :',text); });
                } else { window.prompt('Copiez :',text); }
            }
            var btns=document.querySelectorAll('[data-dlc-copy]');
            Array.prototype.forEach.call(btns,function(b){
                var label=b.textContent;
                b.addEventListener('click',function(){
                    var what=b.getAttribute('data-dlc-copy');
                    if(what==='endpoint'){ copyText(b.getAttribute('data-value')||'',b,label); return; }
                    if(t){ clearTimeout(t); }
                    var fd=new FormData();
                    fd.append('action','delicat_forward_secret');
                    fd.append('nonce',b.getAttribute('data-nonce')||'');
                    fd.append('what',what);
                    fetch(window.ajaxurl||'<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',body:fd,credentials:'same-origin',cache:'no-store'})
                        .then(function(r){ return r.json(); })
                        .then(function(j){ if(j && j.success && j.data){ copyText(String(j.data.value),b,label); } else { b.textContent='Erreur'; } })
                        .catch(function(){ b.textContent='Erreur'; });
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * 6.6.4 (RCH-04): forwarder set-up box. Shows the address without the key, the key
     * masked, how the forwarder sent it last time, the steps to move it into a header,
     * and copy buttons that fetch the key only when clicked.
     */
    private function render_forwarder_setup( array $fwd_auth ): void {
        $endpoint = rest_url( 'delicat/v1/natcash/forward' );
        $key      = self::forward_secret();
        $masked   = substr( $key, 0, 3 ) . str_repeat( '•', 8 ) . substr( $key, -2 );
        $nonce    = wp_create_nonce( 'delicat_forward_secret' );
        $method   = (string) ( $fwd_auth['method'] ?? '' );
        $labels   = [
            'header' => 'clé envoyée dans un en-tête (recommandé)',
            'query'  => 'clé dans l\'adresse (?key=…) — ancienne méthode, à changer',
            'body'   => 'clé dans le corps du message (accepté ; l\'en-tête est préférable)',
        ];
        $rotate = wp_nonce_url( admin_url( 'admin-post.php?action=delicat_forward_rotate' ), 'delicat_forward_rotate' );
        ?>
        <details class="dlc-panel dlc-setup"<?php echo 'query' === $method ? ' open' : ''; ?>>
            <summary><strong>Adresse du forwarder</strong></summary>
            <table class="dlc-table" style="margin-top:10px;">
                <tr><th>Adresse (POST)</th><td><code class="dlc-url"><?php echo esc_html( $endpoint ); ?></code></td></tr>
                <tr><th>Clé secrète</th><td><code><?php echo esc_html( $masked ); ?></code> <span class="dlc-hint">(masquée)</span></td></tr>
                <tr><th>Dernier SMS reçu</th><td><?php
                    if ( isset( $labels[ $method ] ) ) {
                        echo esc_html( $labels[ $method ] ) . ( ! empty( $fwd_auth['at'] ) ? ' <span class="dlc-hint">— ' . esc_html( wp_date( 'd/m/Y H:i', (int) $fwd_auth['at'] ) ) . '</span>' : '' );
                    } else {
                        echo '<span class="dlc-hint">pas encore connu (mis à jour au prochain SMS transmis)</span>';
                    }
                ?></td></tr>
            </table>
            <p style="margin:12px 0 6px;"><strong>Méthode recommandée : la clé dans un en-tête</strong></p>
            <ol style="margin:0 0 10px 18px;">
                <li>Dans votre application de transfert SMS, mettez l'adresse ci-dessus <strong>sans</strong> « ?key=… » à la fin.</li>
                <li>Ajoutez un en-tête (« Header ») : nom <code><?php echo esc_html( self::FWD_HEADER ); ?></code>, valeur : la clé (bouton « Copier la clé »). Si l'application propose seulement « Authorization », mettez <code>Bearer</code>, un espace, puis la clé.</li>
                <li>Enregistrez et envoyez-vous un SMS de test : la ligne « Dernier SMS reçu » ci-dessus doit indiquer « en-tête ».</li>
            </ol>
            <p style="display:flex;gap:8px;flex-wrap:wrap;margin:0;">
                <button type="button" class="button" data-dlc-copy="endpoint" data-value="<?php echo esc_attr( $endpoint ); ?>">Copier l'adresse</button>
                <button type="button" class="button button-primary" data-dlc-copy="key" data-nonce="<?php echo esc_attr( $nonce ); ?>">Copier la clé</button>
                <button type="button" class="button" data-dlc-copy="url" data-nonce="<?php echo esc_attr( $nonce ); ?>">Copier l'adresse avec la clé (ancienne méthode)</button>
            </p>
            <p class="dlc-hint" style="margin:8px 0 0;">L'ancienne méthode (clé dans l'adresse) reste acceptée pour ne rien casser ; utilisez-la seulement si votre application ne permet pas d'en-tête. Gardez la clé secrète.</p>
            <p style="margin:10px 0 0;"><a class="button button-small" href="<?php echo esc_url( $rotate ); ?>" onclick="return confirm('Créer une nouvelle clé ? L\'ancienne cessera de fonctionner TOUT DE SUITE : la validation automatique s\'arrête jusqu\'à ce que vous colliez la nouvelle clé dans l\'application de transfert SMS.');">Créer une nouvelle clé</a>
                <span class="dlc-hint">À faire une fois l'en-tête en place, si l'ancienne adresse a pu être vue par d'autres.</span></p>
        </details>
        <?php
    }

    public function routes(): void {
        register_rest_route( 'delicat/v1', '/natcash/sms', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_sms' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );

        // Forwarder endpoint for generic SMS-forwarding apps. Auth is a secret
        // key, preferably in a header (X-Delicat-Key or Authorization: Bearer);
        // ?key= in the URL / body is still accepted for forwarders set up that
        // way. Accepts flexible field names (msg/message/text/sms, from/sender/number).
        register_rest_route( 'delicat/v1', '/natcash/forward', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_forward' ],
            'permission_callback' => '__return_true', // protected by secret token
        ] );
        // Also allow GET, since some forwarders only do GET with query params.
        register_rest_route( 'delicat/v1', '/natcash/forward', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_forward' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /** Get (or create) the secret token used by the forwarder URL. */
    public static function forward_secret(): string {
        $s = get_option( 'delicat_natcash_secret', '' );
        if ( ! $s ) {
            $s = wp_generate_password( 24, false, false );
            update_option( 'delicat_natcash_secret', $s, false );
        }
        return $s;
    }

    /**
     * Visitor address for the forwarder throttle and lockout.
     *
     * 6.6.4: CF-Connecting-IP / True-Client-IP / X-Real-IP / X-Forwarded-For were
     * believed from anyone, so a caller could pick a new address per request and
     * never be throttled or locked out. Now CF-Connecting-IP counts only when the
     * socket peer (REMOTE_ADDR) is a Cloudflare edge; every other header is ignored.
     * The Builder has the same rule (and the App API's trusted-proxy rule) in
     * Delicat_Builder_V9_Security::client_ip(): used when present, so all limits
     * on the site agree on who the visitor is.
     */
    private static function client_ip(): string {
        if ( class_exists( 'Delicat_Builder_V9_Security', false ) && is_callable( [ 'Delicat_Builder_V9_Security', 'client_ip' ] ) ) {
            try {
                $ip = (string) Delicat_Builder_V9_Security::client_ip();
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            } catch ( Throwable $e ) {
                unset( $e ); // fall back to the local rule below
            }
        }
        return self::local_client_ip();
    }

    /** Same rule as the Builder, for sites without it: CF-Connecting-IP only from a Cloudflare edge. */
    private static function local_client_ip(): string {
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $peer   = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
        $cf     = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : '';
        if ( $peer !== '' && $cf !== '' && filter_var( $cf, FILTER_VALIDATE_IP ) ) {
            foreach ( (array) apply_filters( 'delicat_recharge_trusted_proxy_ranges', self::CLOUDFLARE_RANGES ) as $cidr ) {
                if ( self::ip_in_cidr( $peer, (string) $cidr ) ) return $cf;
            }
        }
        return $peer !== '' ? $peer : '0';
    }

    private static function ip_in_cidr( string $ip, string $cidr ): bool {
        if ( strpos( $cidr, '/' ) === false ) return $ip === $cidr;
        list( $subnet, $bits ) = explode( '/', $cidr, 2 );
        $ip_bin     = @inet_pton( $ip );     // malformed input is a "no", not a warning
        $subnet_bin = @inet_pton( $subnet );
        if ( $ip_bin === false || $subnet_bin === false || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) return false;
        $bits  = max( 0, min( strlen( $ip_bin ) * 8, (int) $bits ) );
        $bytes = intdiv( $bits, 8 );
        $rest  = $bits % 8;
        if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) return false;
        if ( $rest === 0 ) return true;
        $mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
        return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
    }

    /** Simple per-IP rate limit for the public forward endpoint. */
    private static function forward_rate_ok(): bool {
        $ip = self::client_ip();
        $k  = 'delicat_fwd_rl_' . md5( $ip );
        $n  = (int) get_transient( $k );
        if ( $n >= 60 ) return false;          // max 60 requests / minute / IP
        set_transient( $k, $n + 1, MINUTE_IN_SECONDS );
        return true;
    }

    /**
     * Brute-force lockout on the forward secret. Wrong keys are counted
     * separately and lock the IP out for 15 minutes after 10 failures, so the
     * 24-char secret can't be ground down by guessing.
     */
    private static function forward_bruteforce_ok(): bool {
        $k = 'delicat_fwd_bad_' . md5( self::client_ip() );
        return (int) get_transient( $k ) < 10;
    }

    private static function forward_bad_key(): void {
        $k = 'delicat_fwd_bad_' . md5( self::client_ip() );
        $n = (int) get_transient( $k );
        set_transient( $k, $n + 1, 15 * MINUTE_IN_SECONDS );
    }

    /**
     * 6.6.4 (RCH-04): the forwarder key and where it came from. Header first
     * (X-Delicat-Key, X-Delicat-Forward-Key, Authorization: Bearer), then the
     * old ?key= in the URL, then a key field in the body. The first candidate
     * that matches wins, so a forwarder that sends both keeps working.
     *
     * @return array{0:string,1:string} [ key, 'header'|'query'|'body' ] or [ '', '' ]
     */
    private static function forward_key_from( WP_REST_Request $req ): array {
        $cands = [];
        foreach ( [ 'x_delicat_key', 'x_delicat_forward_key' ] as $h ) {
            $v = trim( (string) $req->get_header( $h ) );
            if ( $v !== '' ) $cands[] = [ $v, 'header' ];
        }
        $auth = trim( (string) $req->get_header( 'authorization' ) );
        if ( preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ) $cands[] = [ $m[1], 'header' ];
        $query = $req->get_query_params();
        if ( isset( $query['key'] ) && is_scalar( $query['key'] ) && (string) $query['key'] !== '' ) $cands[] = [ (string) $query['key'], 'query' ];
        $body = $req->get_body_params();
        $json = $req->get_json_params();
        foreach ( [ $body, is_array( $json ) ? $json : [] ] as $src ) {
            if ( isset( $src['key'] ) && is_scalar( $src['key'] ) && (string) $src['key'] !== '' ) $cands[] = [ (string) $src['key'], 'body' ];
        }
        if ( ! $cands ) return [ '', '' ];
        $secret = self::forward_secret();
        foreach ( $cands as $c ) {
            if ( hash_equals( $secret, $c[0] ) ) return $c;
        }
        return $cands[0];
    }

    /** Remember how the key was sent (never the key itself), for the admin page. */
    private static function remember_auth_method( string $method ): void {
        $prev = get_option( self::OPT_FWD_AUTH, [] );
        $prev = is_array( $prev ) ? $prev : [];
        if ( ( $prev['method'] ?? '' ) === $method && time() - (int) ( $prev['at'] ?? 0 ) < 10 * MINUTE_IN_SECONDS ) return;
        update_option( self::OPT_FWD_AUTH, [ 'method' => $method, 'at' => time() ], false );
    }

    /**
     * Handle a forwarded SMS from a generic SMS-forwarder app.
     * Accepts many field names so it works with whatever app you choose.
     */
    public function handle_forward( WP_REST_Request $req ) {
        // Throttle to blunt any attempt to brute-force the secret.
        if ( ! self::forward_rate_ok() ) {
            return new WP_Error( 'rate_limited', 'Trop de requêtes.', [ 'status' => 429 ] );
        }
        // Lock out IPs that keep sending wrong keys.
        if ( ! self::forward_bruteforce_ok() ) {
            return new WP_Error( 'locked', 'Trop de tentatives. Réessayez plus tard.', [ 'status' => 429 ] );
        }

        // Secret: preferably a request HEADER, so the key does NOT leak in URLs,
        // server logs, referrers or browser history (6.6.4: X-Delicat-Key or
        // Authorization: Bearer <key>; X-Delicat-Forward-Key still works).
        // ?key= / body stays accepted: forwarders already set up that way keep
        // working, and the admin page asks to move the key into a header.
        list( $key, $method ) = self::forward_key_from( $req );
        if ( $key === '' || ! hash_equals( self::forward_secret(), $key ) ) {
            self::forward_bad_key();
            return new WP_Error( 'forbidden', 'Clé invalide.', [ 'status' => 403 ] );
        }
        self::remember_auth_method( $method );

        // HEARTBEAT: the forwarder authenticated, so it is alive right now.
        // If this stops updating, auto-validation has silently died (phone off,
        // no signal, battery optimisation, lost default-SMS role) and the
        // scheduled watchdog will alert.
        update_option( 'delicat_forwarder_last_seen', time(), false );

        // Flexible field names for the message body.
        $sms = '';
        foreach ( [ 'sms', 'msg', 'message', 'text', 'body', 'content' ] as $f ) {
            $v = (string) $req->get_param( $f );
            if ( $v !== '' ) { $sms = $v; break; }
        }
        // Flexible field names for the sender.
        $from = '';
        foreach ( [ 'from', 'sender', 'number', 'address', 'originator' ] as $f ) {
            $v = (string) $req->get_param( $f );
            if ( $v !== '' ) { $from = $v; break; }
        }

        if ( $sms === '' ) {
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'Aucun texte SMS reçu.' ] );
        }

        // ── SMS Guard context (v6.3.0) ───────────────────────────────────
        // Optional extra fields a forwarder can send. Both are strong
        // anti-spoofing signals when present:
        //   smsc        — service-centre number the SMS travelled through
        //                 (SmsMessage.getServiceCenterAddress() on Android)
        //   ts/timestamp/received_at — epoch (s or ms) when the phone got it
        $ctx = [ 'path' => 'forward', 'smsc' => '', 'received_at' => null ];
        foreach ( [ 'smsc', 'service_center', 'sc', 'center' ] as $f ) {
            $v = (string) $req->get_param( $f );
            if ( $v !== '' ) { $ctx['smsc'] = $v; break; }
        }
        foreach ( [ 'ts', 'timestamp', 'received_at', 'sms_ts', 'date' ] as $f ) {
            $v = (string) $req->get_param( $f );
            if ( $v !== '' && is_numeric( $v ) ) {
                $t = (float) $v;
                if ( $t > 1e11 ) $t = $t / 1000; // milliseconds
                // Ignore a wildly wrong device clock (> 24 h off) and fall back to server time.
                if ( $t > 1e9 && abs( time() - $t ) < DAY_IN_SECONDS ) { $ctx['received_at'] = (int) $t; }
                break;
            }
        }

        // OPTIONAL sender allowlist (opt-in). Set option 'delicat_sms_allowed_senders'
        // to a comma-separated list (e.g. "MonCash,Natcash,940") to only accept
        // SMS from those senders. Empty = accept any (backward compatible).
        $allow = array_filter( array_map( 'trim', explode( ',', (string) get_option( 'delicat_sms_allowed_senders', '' ) ) ) );
        if ( $allow ) {
            $sender_ok = false;
            foreach ( $allow as $a ) {
                if ( $a !== '' && stripos( $from, $a ) !== false ) { $sender_ok = true; break; }
            }
            if ( ! $sender_ok ) {
                return rest_ensure_response( [ 'matched' => false, 'reason' => 'Expéditeur non autorisé.' ] );
            }
        }

        // Provider from the forwarder ('MonCash' or 'Natcash'). If MonCash, use
        // the MonCash TransCode matcher; otherwise the Natcash flow.
        $provider = (string) $req->get_param( 'provider' );
        $ctx['label'] = substr( sanitize_text_field( $provider ), 0, 20 );
        if ( stripos( $provider, 'moncash' ) !== false || stripos( $sms, 'moncash' ) !== false ) {
            return $this->process_moncash_sms( $sms, $from, $ctx );
        }

        // If the forwarder positively identified this as Natcash (it checks the
        // real sender before sending), treat the sender as trusted-Natcash even
        // when the raw sender field is a numeric shortcode.
        // 6.6.4 (RCH-13): honoured because it cannot weaken anything. It only
        // replaces the sender-NAME check (a name a spoofer can copy anyway);
        // the SMS is still parsed as a Natcash receipt and the SMS Guard still
        // requires the balance line + balance chain, the TransCode date, the
        // time and the SMSC rules, whatever the label says. The label is kept
        // in the log ('label') so a mislabelled SMS stays visible.
        if ( stripos( $provider, 'natcash' ) !== false && stripos( $from, 'natcash' ) === false ) {
            $from = trim( $from . ' natcash' ); // tag so the strict check passes
        }

        return $this->process_sms( $sms, $from, $ctx );
    }

    /**
     * Re-run the matcher for an SMS the admin has just confirmed as genuine
     * (SMS Guard "Authentique" button). Authenticity checks and unattended
     * policy caps are skipped — this is an attended admin action — but every
     * one-time / anti-reuse gate still applies. Returns a short status text.
     */
    public function credit_trusted_entry( string $provider, string $raw, bool $attended = true ): string {
        $ctx = [ 'path' => $attended ? 'admin' : 'retry', 'pre_trusted' => true, 'attended' => $attended ];
        if ( $provider === 'moncash' ) {
            $res = $this->process_moncash_sms( $raw, 'moncash (confirmé admin)', $ctx );
        } else {
            $log = function ( $result, $extra = [] ) use ( $raw ) { self::log_entry( $raw, $result, array_merge( [ 'provider' => 'Natcash', 'trust' => 'ok', 'path' => 'admin' ], $extra ) ); };
            $res = $this->try_credit_natcash_tc( $raw, $log, $ctx );
            if ( $res === null ) {
                return 'Aucune demande en attente ne correspond (TransCode + montant) — elle sera créditée automatiquement dès que le client la soumet.';
            }
        }
        $data = ( $res instanceof WP_REST_Response ) ? (array) $res->get_data() : [];
        if ( ! empty( $data['matched'] ) ) return (string) ( $data['message'] ?? 'Crédité.' );
        return 'Non crédité : ' . (string) ( $data['reason'] ?? 'voir le journal.' );
    }

    /**
     * Single writer for the diagnostic log. Every entry gets a uid (needed by
     * the SMS Guard buttons) and a provider tag.
     */
    /**
     * 6.6.2: is this log line a credited approval? Only approvals start with "APPROUVÉ"; entries
     * written before 6.6.1 started with a check-mark emoji instead and stay in the log after the
     * upgrade. The 'trust' field cannot decide: it is 'ok' for any trusted SMS, credited or not.
     */
    private static function is_approved_result( string $res ): bool {
        $res = ltrim( $res );
        return 0 === strpos( $res, 'APPROUVÉ' ) || false !== strpos( $res, "\u{2705}" );
    }

    public static function log_entry( string $raw, $result, array $extra = [] ): void {
        $entries = get_option( 'delicat_natcash_log', [] );
        if ( ! is_array( $entries ) ) $entries = [];
        array_unshift( $entries, array_merge( [
            'uid'    => substr( md5( uniqid( '', true ) . wp_rand() ), 0, 10 ),
            'time'   => current_time( 'mysql' ),
            'sms'    => function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 300 ) : substr( $raw, 0, 300 ),
            'result' => $result,
        ], $extra ) );
        update_option( 'delicat_natcash_log', array_slice( $entries, 0, self::LOG_MAX ), false );
    }

    /**
     * Natcash Automatique avec Transcode matcher. Credits a pending
     * 'natcash_tc' request whose montant + TransCode match this SMS. Strict:
     *   - amount must match exactly
     *   - TransCode must match the pending request's entered trans_code
     *   - atomic claim prevents double-credit; anti-reuse prevents replay
     * Returns a REST response on match/attempt, or NULL when no natcash_tc
     * request is waiting (so the caller falls through to the PIN flow).
     */
    private function try_credit_natcash_tc( string $raw, callable $log, array $ctx = [] ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'delicat_recharges';

        $p = self::parse_natcash_code( $raw );
        if ( ! $p || $p['trans_code'] === '' ) return null;
        $attended = ! empty( $ctx['attended'] );

        // Is a pending natcash_tc request waiting for this exact code + amount?
        $dlc_code_col = Delicat_Database::code_sql();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$tbl}
             WHERE tab = 'natcash_tc' AND statut = 'en_attente'
               AND {$dlc_code_col} = %s
               AND ABS(montant - %f) < 0.01
             ORDER BY created_at DESC LIMIT 1",
            self::norm_code( $p['trans_code'] ), $p['amount']
        ) );
        if ( ! $row ) {
            // Log the miss so an unvalidated payment is diagnosable instead of
            // silently disappearing. Shows what the SMS carried vs what is
            // actually waiting, which immediately reveals amount/code mismatches.
            $pending = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, montant, trans_code FROM {$tbl}
                 WHERE tab = 'natcash_tc' AND statut = 'en_attente'
                 ORDER BY created_at DESC LIMIT 5" ) );
            $hint = [];
            foreach ( (array) $pending as $pr ) {
                $hint[] = sprintf( '#%d %.2f/%s', $pr->id, (float) $pr->montant, $pr->trans_code );
            }
            $log( 'Aucune demande Natcash TC correspondante', [
                'sms_code'    => $p['trans_code'],
                'sms_montant' => $p['amount'],
                'en_attente'  => $hint ? implode( ' | ', $hint ) : 'aucune',
            ] );
            return null; // none waiting → let the PIN flow try
        }

        // ── SMS Guard policy (unattended only): caps + lockdown → HOLD ─────
        // The request stays 'en_attente' and the code is NOT claimed, so the
        // admin can approve it normally from the app / console.
        if ( ! $attended && class_exists( 'Delicat_SMS_Guard' ) ) {
            $policy = Delicat_SMS_Guard::policy_flags( 'natcash', (float) $p['amount'] );
            if ( $policy ) {
                $log( 'RETENU #' . (int) $row->id . ' — approbation manuelle', array_merge( $p, [ 'trust' => 'held', 'flags' => $policy ] ) );
                Delicat_SMS_Guard::on_hold( 'natcash', [], $p, $policy );
                return rest_ensure_response( [ 'matched' => false, 'held' => true, 'id' => (int) $row->id,
                    'reason' => 'Retenu pour approbation manuelle : ' . implode( ' ', $policy ) ] );
            }
        }

        // Serialize credit for this TransCode across all paths (instant verify,
        // MonCash matcher, this matcher). Auto-released at DB session end.
        if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::code_lock_name( $p['trans_code'] ) ) ) !== 1 ) {
            $log( 'Verrou occupé — réessai plus tard', $p );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'Traitement concurrent en cours.' ] );
        }

        // AUTHORITATIVE one-time gate: the DB rejects a second claim of this
        // code outright (UNIQUE index), so a race cannot credit it twice.
        if ( ! Delicat_Database::claim_code( $p['trans_code'], 0, (float) $p['amount'], 'natcash_tc' ) ) {
            $log( 'TransCode déjà utilisé (verrou base)', $p );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'TransCode déjà utilisé.' ] );
        }

        // Anti-reuse: this TransCode must not have already credited.
        $dlc_code_col = Delicat_Database::code_sql();
        $used = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl} WHERE {$dlc_code_col} = %s AND wallet_credited = 1", self::norm_code( $p['trans_code'] ) ) );
        if ( $used > 0 ) {
            $log( 'TransCode Natcash déjà crédité', $p );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'TransCode déjà utilisé.' ] );
        }

        // Atomic claim.
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$tbl} SET wallet_credited = 1, statut = 'approuve'
             WHERE id = %d AND statut = 'en_attente' AND wallet_credited = 0", (int) $row->id ) );
        if ( $claimed !== 1 ) {
            Delicat_Database::release_code( $p['trans_code'] ); // not credited → free it
            $log( 'Déjà traité (course évitée) #' . (int) $row->id, $p );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'Déjà traité.' ] );
        }

        $user_id = (int) $row->user_id;
        if ( ! $user_id ) {
            $u = get_user_by( 'email', $row->user_email );
            $user_id = $u ? $u->ID : 0;
        }
        if ( ! $user_id ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET wallet_credited=0, statut='en_attente' WHERE id=%d", (int) $row->id ) );
            Delicat_Database::release_code( $p['trans_code'] );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'Utilisateur introuvable.' ] );
        }

        if ( class_exists( 'Delicat_TeraWallet' ) ) {
            $res = Delicat_TeraWallet::credit( $user_id, (float) $row->montant,
                sprintf( 'Recharge Natcash (transcode) auto #%d (SMS)', $row->id ), (int) $row->id );
            if ( is_wp_error( $res ) ) {
                $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET wallet_credited=0, statut='en_attente' WHERE id=%d", (int) $row->id ) );
                Delicat_Database::release_code( $p['trans_code'] );
                return rest_ensure_response( [ 'matched' => false, 'reason' => 'Échec crédit: ' . $res->get_error_message() ] );
            }
        }

        Delicat_Database::update( (int) $row->id, [
            'trans_code' => $p['trans_code'],
            'note_admin' => trim( (string) $row->note_admin . "\n[AUTO-NATCASH TRANSCODE SMS " . current_time( 'mysql' ) . ( $attended ? ' — SMS confirmé admin' : '' ) . "]" ),
        ] );
        do_action( 'delicat_recharge_approved', (int) $row->id );
        if ( ! $attended && class_exists( 'Delicat_SMS_Guard' ) ) {
            Delicat_SMS_Guard::after_credit( 'natcash', (float) $row->montant );
        }

        $log( 'APPROUVÉ Natcash-TC #' . (int) $row->id, array_merge( $p, [ 'trust' => 'ok' ] ) );
        return rest_ensure_response( [
            'matched' => true, 'id' => (int) $row->id,
            'message' => sprintf( 'Recharge Natcash #%d approuvée (%s HTG).', $row->id, number_format( (float) $row->montant, 2 ) ),
        ] );
    }

    /**
     * MonCash auto-match: parse a MonCash SMS and credit a pending MonCash
     * recharge whose montant + trans_code match. Strict:
     *   - sender must be MonCash (checked by forwarder + here)
     *   - amount must match exactly
     *   - TransCode must match the pending request's entered trans_code
     *   - atomic claim prevents double-credit; anti-reuse prevents replay
     */
    public function process_moncash_sms( string $raw, string $sms_from = '', array $ctx = [] ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'delicat_recharges';
        $attended = ! empty( $ctx['attended'] );

        $base = [ 'provider' => 'MonCash', 'from' => $sms_from, 'smsc' => (string) ( $ctx['smsc'] ?? '' ), 'path' => (string) ( $ctx['path'] ?? 'forward' ), 'label' => (string) ( $ctx['label'] ?? '' ) ];
        $log  = function ( $result, $extra = [] ) use ( $raw, $base ) {
            self::log_entry( $raw, $result, array_merge( $base, $extra ) );
        };

        // Strict: sender must indicate MonCash if provided.
        if ( $sms_from !== '' && stripos( $sms_from, 'moncash' ) === false ) {
            $log( 'Expéditeur non MonCash: ' . $sms_from, [ 'trust' => 'rejected' ] );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'Expéditeur non MonCash.' ] );
        }

        $parsed = self::parse_moncash( $raw );
        if ( ! $parsed ) {
            $log( 'Format MonCash non reconnu', [ 'trust' => 'rejected' ] );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'Format MonCash non reconnu.' ] );
        }

        // ── SMS Guard: is this SMS genuine? (v6.3.0) ──────────────────────
        // Sender "MonCash" can be spoofed; the balance chain cannot.
        if ( class_exists( 'Delicat_SMS_Guard' ) && empty( $ctx['pre_trusted'] ) ) {
            $verdict = Delicat_SMS_Guard::evaluate( 'moncash', $parsed, $raw, $sms_from, $ctx );
            $base   += [ 'balance' => $verdict['balance'], 'trust' => $verdict['trusted'] ? 'ok' : ( $verdict['spoof'] ? 'suspect' : 'held' ), 'flags' => $verdict['flags'] ];
            $log     = function ( $result, $extra = [] ) use ( $raw, $base ) { self::log_entry( $raw, $result, array_merge( $base, $extra ) ); };
            if ( ! $verdict['trusted'] && Delicat_SMS_Guard::mode() === 'hold' ) {
                $log( ( $verdict['spoof'] ? 'SUSPECT' : 'RETENU' ) . ' — non crédité', $parsed );
                Delicat_SMS_Guard::on_hold( 'moncash', $verdict, $parsed, $verdict['flags'] );
                return rest_ensure_response( [ 'matched' => false, 'held' => true,
                    'reason' => ( $verdict['spoof'] ? 'SMS suspect (spoof) : ' : 'Retenu : ' ) . implode( ' ', $verdict['flags'] ) ] );
            }
            Delicat_SMS_Guard::accept( 'moncash', $verdict, (string) $parsed['trans_code'] );
        }

        // Serialize credit for this TransCode across all paths (auto-released at
        // DB session end) so it can only be credited once.
        if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::code_lock_name( $parsed['trans_code'] ) ) ) !== 1 ) {
            $log( 'Verrou occupé — réessai plus tard', $parsed );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'Traitement concurrent en cours.' ] );
        }

        // Anti-reuse: this TransCode must not have already credited.
        $dlc_code_col = Delicat_Database::code_sql();
        $used = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl} WHERE {$dlc_code_col} = %s AND wallet_credited = 1", self::norm_code( $parsed['trans_code'] ) ) );
        if ( $used > 0 ) {
            $log( 'TransCode déjà crédité', $parsed );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'TransCode déjà utilisé.' ] );
        }

        // AUTHORITATIVE one-time gate (DB UNIQUE index).
        if ( ! Delicat_Database::claim_code( $parsed['trans_code'], 0, (float) $parsed['amount'], 'moncash' ) ) {
            $log( 'TransCode déjà utilisé (verrou base)', $parsed );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'TransCode déjà utilisé.' ] );
        }

        // Find a pending MonCash request: exact trans_code + amount match.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$tbl}
             WHERE tab = 'moncash' AND statut = 'en_attente'
               AND {$dlc_code_col} = %s
               AND ABS(montant - %f) < 0.01
             ORDER BY created_at DESC LIMIT 1",
            self::norm_code( $parsed['trans_code'] ), $parsed['amount']
        ) );

        if ( ! $row ) {
            Delicat_Database::release_code( $parsed['trans_code'] ); // nothing credited
            $log( 'Aucune demande MonCash correspondante', $parsed );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'Aucune demande correspondante (TransCode/montant).', 'parsed' => $parsed ] );
        }

        // ── SMS Guard policy (unattended only): caps + lockdown → HOLD ─────
        if ( ! $attended && class_exists( 'Delicat_SMS_Guard' ) ) {
            $policy = Delicat_SMS_Guard::policy_flags( 'moncash', (float) $parsed['amount'] );
            if ( $policy ) {
                Delicat_Database::release_code( $parsed['trans_code'] ); // not credited → free it
                $log( 'RETENU #' . (int) $row->id . ' — approbation manuelle', array_merge( $parsed, [ 'trust' => 'held', 'flags' => $policy ] ) );
                Delicat_SMS_Guard::on_hold( 'moncash', [], $parsed, $policy );
                return rest_ensure_response( [ 'matched' => false, 'held' => true, 'id' => (int) $row->id,
                    'reason' => 'Retenu pour approbation manuelle : ' . implode( ' ', $policy ) ] );
            }
        }

        // Atomic claim.
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$tbl} SET wallet_credited = 1, statut = 'approuve'
             WHERE id = %d AND statut = 'en_attente' AND wallet_credited = 0", (int) $row->id ) );
        if ( $claimed !== 1 ) {
            $log( 'Déjà traité (course évitée) #' . (int) $row->id, $parsed );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'Déjà traité.' ] );
        }

        $user_id = (int) $row->user_id;
        if ( ! $user_id ) {
            $u = get_user_by( 'email', $row->user_email );
            $user_id = $u ? $u->ID : 0;
        }
        if ( ! $user_id ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET wallet_credited=0, statut='en_attente' WHERE id=%d", (int) $row->id ) );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'Utilisateur introuvable.' ] );
        }

        if ( class_exists( 'Delicat_TeraWallet' ) ) {
            $res = Delicat_TeraWallet::credit( $user_id, (float) $row->montant,
                sprintf( 'Recharge MonCash auto #%d (SMS)', $row->id ), (int) $row->id );
            if ( is_wp_error( $res ) ) {
                $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET wallet_credited=0, statut='en_attente' WHERE id=%d", (int) $row->id ) );
                return rest_ensure_response( [ 'matched' => false, 'reason' => 'Échec crédit: ' . $res->get_error_message() ] );
            }
        }

        Delicat_Database::update( (int) $row->id, [
            'note_admin' => trim( (string) $row->note_admin . "\n[AUTO-MONCASH SMS " . current_time('mysql') . ( $attended ? ' — SMS confirmé admin' : '' ) . "]" ),
        ] );
        do_action( 'delicat_recharge_approved', (int) $row->id );
        if ( ! $attended && class_exists( 'Delicat_SMS_Guard' ) ) {
            Delicat_SMS_Guard::after_credit( 'moncash', (float) $row->montant );
        }

        $log( 'APPROUVÉ MonCash #' . (int) $row->id, array_merge( $parsed, [ 'trust' => 'ok' ] ) );
        return rest_ensure_response( [
            'matched' => true, 'id' => (int) $row->id,
            'message' => sprintf( 'Recharge MonCash #%d approuvée (%s HTG).', $row->id, number_format( (float) $row->montant, 2 ) ),
        ] );
    }

    /** Reuse the app device auth (device id + token headers). */
    public function auth( WP_REST_Request $req ): bool {
        if ( ! class_exists( 'Delicat_App_Api' ) ) return false;
        $device_id = (string) $req->get_header( 'x-delicat-device' );
        $token     = (string) $req->get_header( 'x-delicat-token' );
        if ( $device_id === '' || $token === '' ) return false;
        $devices = Delicat_App_Api::devices();
        if ( ! isset( $devices[ $device_id ] ) ) return false;
        $dev = $devices[ $device_id ];
        if ( empty( $dev['enabled'] ) ) return false;
        return hash_equals( (string) $dev['token_hash'], wp_hash( $token ) );
    }

    /**
     * Parse a raw Natcash SMS and try to auto-approve a matching request.
     * Expected SMS sample:
     *   "Vous avez recu 160 HTG de Unknow 43180354 a 14:55 26/06/2026,
     *    contenu: 2008. Votre solde: 12,878 HTG. TransCode: 26062687398118. Merci"
     */
    public function handle_sms( WP_REST_Request $req ) {
        $raw  = (string) $req->get_param( 'sms' );
        $from = (string) $req->get_param( 'from' );
        if ( $raw === '' ) {
            return new WP_Error( 'empty', 'SMS vide.', [ 'status' => 400 ] );
        }
        // The app scans Natcash SMS; if it couldn't capture the sender name but
        // the body is clearly a Natcash receipt, tag it so the strict Natcash
        // sender check accepts it. (This endpoint is already device-authed.)
        if ( stripos( $from, 'natcash' ) === false ) {
            $lb = strtolower( $raw );
            $looks_natcash = ( strpos( $lb, 'htg' ) !== false )
                && ( strpos( $lb, 'transcode' ) !== false || strpos( $lb, 'trans code' ) !== false
                     || strpos( $lb, 'kontni' ) !== false || strpos( $lb, 'contenu' ) !== false );
            if ( $looks_natcash ) $from = trim( $from . ' natcash' );
        }
        return $this->process_sms( $raw, $from, [ 'path' => 'app', 'smsc' => (string) $req->get_param( 'smsc' ) ] );
    }

    /**
     * Core: parse a raw Natcash SMS and try to auto-approve a matching request.
     * Shared by the app endpoint (device-authed) and the forwarder endpoint.
     */
    public function process_sms( string $raw, string $sms_from = '', array $ctx = [] ) {
        // On client sites (under a Délicat license), auto-verify only works while
        // the license is valid. On HQ (no DELICAT_IS_CLIENT) this is skipped.
        if ( defined( 'DELICAT_IS_CLIENT' ) && DELICAT_IS_CLIENT
             && class_exists( 'Delicat_Client_License' )
             && Delicat_Client_License::is_configured() ) {
            $cache = get_transient( 'delicat_license_cache' );
            $ok = is_array( $cache ) ? ! empty( $cache['valid'] ) : Delicat_Client_License::valid();
            if ( ! $ok ) {
                return rest_ensure_response( [ 'matched' => false, 'reason' => 'Abonnement expiré.' ] );
            }
        }
        // Diagnostic log helper (shared writer): keeps the last LOG_MAX attempts
        // so we can see what was received and why it did/didn't match.
        $base = [ 'provider' => 'Natcash', 'from' => $sms_from, 'smsc' => (string) ( $ctx['smsc'] ?? '' ), 'path' => (string) ( $ctx['path'] ?? 'forward' ), 'label' => (string) ( $ctx['label'] ?? '' ) ];
        $log  = function ( $result, $extra = [] ) use ( $raw, $base ) {
            self::log_entry( $raw, $result, array_merge( $base, $extra ) );
        };

        // ── STRICT SENDER: Natcash only ─────────────────────────────────────
        // A Natcash payment must actually COME FROM Natcash. Approval requires
        // POSITIVE proof of a Natcash origin — the sender string contains
        // "natcash" (the forwarder appends this when it confirms the provider).
        // Anything else (wrong sender, or no proof at all) is rejected, so a
        // spoofed body from another sender can never be approved.
        $is_natcash_sender = ( stripos( (string) $sms_from, 'natcash' ) !== false );

        if ( ! $is_natcash_sender ) {
            $log( 'Origine Natcash non confirmée', [ 'expediteur' => $sms_from ?: '(vide)', 'trust' => 'rejected' ] );
            return rest_ensure_response( [
                'matched' => false,
                'reason'  => 'Origine Natcash non confirmée — non approuvé.',
            ] );
        }

        // The body must look like a Natcash receipt. Two shapes are valid:
        //   • PIN flow  — carries "kontni"/"contenu" (our generated PIN)
        //   • TC  flow  — the client never enters our PIN, so the SMS may have
        //                 no "kontni" at all; it is identified by "Transcode".
        $low = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw ) : strtolower( $raw );
        $has_htg      = strpos( $low, 'htg' ) !== false;
        $has_pin_field = ( strpos( $low, 'kontni' ) !== false || strpos( $low, 'contenu' ) !== false );
        $has_transcode = ( strpos( $low, 'transcode' ) !== false || strpos( $low, 'trans code' ) !== false );

        if ( ! ( $has_htg && ( $has_pin_field || $has_transcode ) ) ) {
            $log( 'SMS non reconnu comme Natcash', [ 'trust' => 'rejected' ] );
            return rest_ensure_response( [ 'matched' => false, 'reason' => 'SMS non reconnu comme Natcash.' ] );
        }

        // ── SMS GUARD: is this SMS genuine? (v6.3.0) ──────────────────────
        // The sender name "Natcash" can be spoofed (Twilio-style alphanumeric
        // sender IDs land in the same thread). What cannot be forged: the
        // running balance ("Balans ou"), the date encoded in the TransCode,
        // the time of arrival, and the SMSC route. A body that fails any of
        // these is HELD (logged + alert), never credited.
        global $wpdb;
        $guard_lock = 'delicat_smsg_natcash';
        if ( class_exists( 'Delicat_SMS_Guard' ) && empty( $ctx['pre_trusted'] ) ) {
            $p = self::parse_natcash_code( $raw );
            if ( $p ) {
                // Serialize receipts per provider so the balance chain is
                // always compared against the anchor set by the previous one.
                $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $guard_lock ) );
                $verdict = Delicat_SMS_Guard::evaluate( 'natcash', $p, $raw, $sms_from, $ctx );
                $base   += [ 'balance' => $verdict['balance'], 'sms_time' => $verdict['sms_time'], 'payer' => $verdict['payer'],
                             'trust' => $verdict['trusted'] ? 'ok' : ( $verdict['spoof'] ? 'suspect' : 'held' ), 'flags' => $verdict['flags'] ];
                $log     = function ( $result, $extra = [] ) use ( $raw, $base ) { self::log_entry( $raw, $result, array_merge( $base, $extra ) ); };
                if ( ! $verdict['trusted'] && Delicat_SMS_Guard::mode() === 'hold' ) {
                    $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $guard_lock ) );
                    $log( ( $verdict['spoof'] ? 'SUSPECT' : 'RETENU' ) . ' — non crédité', $p );
                    Delicat_SMS_Guard::on_hold( 'natcash', $verdict, $p, $verdict['flags'] );
                    return rest_ensure_response( [ 'matched' => false, 'held' => true,
                        'reason' => ( $verdict['spoof'] ? 'SMS suspect (spoof) : ' : 'Retenu : ' ) . implode( ' ', $verdict['flags'] ) ] );
                }
                // Genuine: its balance is the new reference, even when no
                // request is pending yet (the client may submit afterwards).
                Delicat_SMS_Guard::accept( 'natcash', $verdict, (string) $p['trans_code'] );
                Delicat_SMS_Guard::retry_held( 'natcash' );
            } else {
                // Not a receipt (transfer out, withdrawal, payment…): keep the
                // balance reference fresh when the new balance chains.
                if ( Delicat_SMS_Guard::chain_outgoing( 'natcash', $raw, $ctx ) ) {
                    $log( 'Mouvement sortant — solde de référence mis à jour' );
                    Delicat_SMS_Guard::retry_held( 'natcash' );
                    return rest_ensure_response( [ 'matched' => false, 'reason' => 'SMS non-reçu : solde de référence mis à jour.' ] );
                }
            }
        }

        // Natcash Automatique avec Transcode (same rules as MonCash): if a
        // pending natcash_tc request matches this SMS by exact TransCode + exact
        // amount, credit it now. This is the ONLY Natcash validation path.
        $tc = $this->try_credit_natcash_tc( $raw, $log, $ctx );
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $guard_lock ) );
        if ( $tc !== null ) return $tc;

        // ── Natcash PIN flow REMOVED ────────────────────────────────────────
        // Natcash now validates exclusively via TransCode (natcash_tc), matched
        // above by try_credit_natcash_tc(). Reaching this point means the SMS
        // did not match any pending TransCode request, so there is nothing more
        // to try. The PIN ("kontni") matcher has been retired.
        $log( 'Aucune demande Natcash (TC) correspondante pour ce SMS' );
        return rest_ensure_response( [
            'matched' => false,
            'reason'  => 'Aucune demande correspondante (TransCode + montant).',
        ] );
    }

    /* ===================== RETIRED: Natcash PIN matcher =====================
       Natcash validation is TransCode-only. The PIN ("kontni") matcher, its
       TTL, the PIN generator and the PIN parser were removed (the last two in
       6.6.4, nothing called them) — do not reintroduce them without also
       restoring the nc_pin matching rules. The nc_pin column keeps old rows.
       ====================================================================== */

    /**
     * Parse a MonCash SMS. Returns [amount, sender, trans_code] or null.
     * (Restored in v6.3.0 — it was referenced but missing, so any forwarded
     * MonCash SMS crashed with "undefined method".)
     * Formats:
     *   "You have received G1,600.00 with MonCash from 50936441609. Txn ID: 003357665049"
     *   "You have received a transfer of G480.00 with MonCash from 11454600 - NAME.
     *    After fee is deducted your new balance is: G36,169.82. Txn ID:003347119034"
     * The FIRST G-amount is the received amount (a later one is the balance).
     */
    public static function parse_moncash( string $s ): ?array {
        $out = [ 'amount' => 0.0, 'sender' => '', 'trans_code' => '' ];
        if ( preg_match( '/\bG\s*([\d,]+\.?\d*)/', $s, $m ) ) {
            $out['amount'] = (float) str_replace( ',', '', $m[1] );
        }
        if ( preg_match( '/from\s+(\d{6,})/i', $s, $m ) ) {
            $out['sender'] = $m[1];
        }
        if ( preg_match( '/Txn\s*ID\s*:?\s*(\d+)/i', $s, $m ) ) {
            $out['trans_code'] = $m[1];
        }
        if ( $out['amount'] <= 0 || $out['trans_code'] === '' ) return null;
        return $out;
    }

    /**
     * Shared MySQL named-lock key for a TransCode. Every credit path (instant
     * verify on submit, MonCash SMS matcher, Natcash-TC SMS matcher) uses this
     * SAME key so only one of them can credit a given code at a time — even
     * across concurrent requests. MySQL auto-releases the lock at session end.
     */
    /**
     * Canonical form of a TransCode for comparison.
     *
     * Clients type codes with spaces, dashes or mixed case ("2607 2038-38458")
     * while the SMS carries the clean value. Comparing raw strings silently
     * fails to match, leaving a legitimate payment stuck "en attente" with no
     * error — the cause of transactions that never validate. Every lookup and
     * every lock now goes through this so both sides agree.
     */
    /**
     * Mark diagnostic log entries as RESOLVED once the matching payment has
     * been credited by any route (manual TransCode entry, admin approval, app).
     *
     * The log is written when an SMS is processed and was never revisited, so a
     * payment you later fixed by hand stayed red on the diagnostic page for
     * ever. That made genuine failures hard to spot among already-handled ones.
     * Matching is done on the normalized TransCode so spacing/case differences
     * between what the client typed and what the SMS carried still line up.
     */
    public static function resolve_log_for_code( string $trans_code, string $how = 'manuel' ): void {
        $code = self::norm_code( $trans_code );
        if ( $code === '' ) return;

        $entries = get_option( 'delicat_natcash_log', [] );
        if ( ! is_array( $entries ) || ! $entries ) return;

        $changed = false;
        foreach ( $entries as $i => $e ) {
            if ( ! empty( $e['resolved'] ) ) continue;

            // The code may sit in the parsed field or inside the raw SMS text.
            $hay = self::norm_code( (string) ( $e['sms_code'] ?? '' ) );
            if ( $hay === '' ) {
                $raw = self::norm_code( (string) ( $e['sms'] ?? '' ) );
                $hit = ( $raw !== '' && strpos( $raw, $code ) !== false );
            } else {
                $hit = ( $hay === $code );
            }
            if ( ! $hit ) continue;

            $entries[ $i ]['resolved']    = 1;
            $entries[ $i ]['resolved_at'] = current_time( 'mysql' );
            $entries[ $i ]['resolved_by'] = $how;
            $changed = true;
        }

        if ( $changed ) update_option( 'delicat_natcash_log', $entries, false );
    }

    public static function norm_code( string $code ): string {
        return strtoupper( str_replace( [ ' ', '-', "\t" ], '', trim( $code ) ) );
    }

    public static function code_lock_name( string $code ): string {
        // Must use the normalized form, otherwise two spellings of the same
        // code would take two different locks and could both credit.
        return 'delicat_tc_' . md5( self::norm_code( $code ) );
    }

    /**
     * Lightweight parse of a Natcash SMS for the TRANSCODE flow: extract just
     * amount + TransCode (no PIN required). Used for "Natcash Automatique avec
     * Transcode" where the client did NOT put our PIN in "contenu".
     * Returns [amount, trans_code] or null.
     */
    public static function parse_natcash_code( string $s ): ?array {
        $out = [ 'amount' => 0.0, 'trans_code' => '' ];
        if ( preg_match( '/(?:resevwa|re\S*u|(?:te\s+)?ajoute)\s+([\d,\.]+)\s*HTG/iu', $s, $m ) ) {
            $out['amount'] = (float) str_replace( ',', '', $m[1] );
        }
        if ( preg_match( '/Trans\s*code\s*:\s*([A-Za-z0-9]+)/iu', $s, $m ) ) {
            $out['trans_code'] = $m[1];
        }
        if ( $out['amount'] <= 0 || $out['trans_code'] === '' ) return null;
        return $out;
    }

    /**
     * STRICT verification of a real received payment SMS (anti-scammer).
     *
     * Scans the received-SMS log and returns ok=true ONLY if a real SMS is
     * found that STRICTLY matches all three:
     *   - provider  (moncash SMS for a moncash request, natcash for natcash)
     *   - trans_code exact (case-insensitive, whole value)
     *   - amount    exact (within 0.01 HTG)
     *
     * If the code is found but the amount differs, it returns ok=false with a
     * clear amount-mismatch reason (so a wrong/altered amount is rejected, not
     * silently ignored). 'manuel' has no provider SMS, so it can't be verified
     * this way — callers should treat that as "not verifiable".
     *
     * @return array{ok:bool, reason?:string, parsed?:array, provider?:string}
     */
    public static function verify_received( string $provider, string $trans_code, float $amount, bool $unattended = false ): array {
        $code = trim( $trans_code );
        if ( $code === '' ) {
            return [ 'ok' => false, 'reason' => 'TransCode vide.' ];
        }
        $provider = strtolower( trim( $provider ) );
        if ( $provider === 'manuel' ) {
            return [ 'ok' => false, 'reason' => 'Le mode Manuel n’a pas de SMS fournisseur à vérifier.' ];
        }
        // 'natcash_tc' rows carry provider 'natcash' SMS.
        if ( $provider === 'natcash_tc' ) $provider = 'natcash';

        $entries = get_option( 'delicat_natcash_log', [] );
        if ( ! is_array( $entries ) ) $entries = [];

        $amount_mismatch = null;
        $suspect_found   = false;

        foreach ( $entries as $e ) {
            $sms = (string) ( $e['sms'] ?? '' );
            if ( $sms === '' ) continue;

            // ── SMS Guard: a suspect / held / unconfirmed SMS is NOT proof ──
            // Otherwise a scammer could send the fake SMS first, then submit
            // a matching request and get credited instantly.
            if ( class_exists( 'Delicat_SMS_Guard' ) && ! Delicat_SMS_Guard::entry_trusted( $e ) ) {
                if ( stripos( $sms, $code ) !== false ) $suspect_found = true;
                continue;
            }

            // Which provider is THIS sms?
            $is_moncash = ( stripos( $sms, 'moncash' ) !== false )
                || ( isset( $e['provider'] ) && stripos( (string) $e['provider'], 'moncash' ) !== false )
                || (bool) preg_match( '/Txn\s*ID/i', $sms );
            $sms_provider = $is_moncash ? 'moncash' : 'natcash';

            // Provider must strictly match the request's provider.
            if ( $sms_provider !== $provider ) continue;

            $parsed = $is_moncash ? self::parse_moncash( $sms ) : self::parse_natcash_code( $sms );
            if ( ! $parsed || empty( $parsed['trans_code'] ) ) continue;

            // TransCode must match exactly (case-insensitive).
            if ( strcasecmp( trim( (string) $parsed['trans_code'] ), $code ) !== 0 ) continue;

            // Amount must match exactly.
            if ( abs( (float) $parsed['amount'] - $amount ) >= 0.01 ) {
                $amount_mismatch = (float) $parsed['amount'];
                continue; // keep scanning in case a correct one also exists
            }

            // Unattended (instant verify on submit): caps + lockdown still apply.
            if ( $unattended && class_exists( 'Delicat_SMS_Guard' ) ) {
                $policy = Delicat_SMS_Guard::policy_flags( $sms_provider, $amount );
                if ( $policy ) {
                    return [ 'ok' => false, 'reason' => 'Retenu pour approbation manuelle : ' . implode( ' ', $policy ), 'held' => true ];
                }
            }

            return [ 'ok' => true, 'parsed' => $parsed, 'provider' => $sms_provider ];
        }

        if ( $suspect_found ) {
            return [ 'ok' => false, 'reason' => 'Un SMS avec ce TransCode existe mais il est SUSPECT / non confirmé (Garde SMS) — vérifiez-le dans votre app ' . ucfirst( $provider ) . ' avant de créditer.' ];
        }
        if ( $amount_mismatch !== null ) {
            return [ 'ok' => false, 'reason' => sprintf(
                'Montant incorrect : le SMS reçu indique %s HTG, pas %s HTG.',
                number_format( $amount_mismatch, 2 ), number_format( $amount, 2 )
            ) ];
        }
        return [ 'ok' => false, 'reason' => 'Aucun SMS reçu ne correspond à ce TransCode + fournisseur.' ];
    }
}
