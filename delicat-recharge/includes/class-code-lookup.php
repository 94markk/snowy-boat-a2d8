<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 6.5.0 — TransCode lookup (read-only).
 *
 * Delicat Console → 🔎 Recherche code. Answers two support questions without
 * touching anything:
 *   1. "Has this TransCode / Txn ID already been used on the site?"
 *   2. "A client says their recharge is missing: what happened to this code?"
 *
 * It only READS the three places a code can live:
 *   - the recharge requests table (every submission, any status),
 *   - the spend ledger (the UNIQUE "one code = one credit" table),
 *   - the received-SMS diagnostic log (MonCash / Natcash confirmations).
 *
 * Nothing here writes to those, credits, rejects or unblocks anything; every
 * existing flow, check and lock is unchanged. Access: manage_options (same as
 * the rest of the console) + a dedicated nonce + a per-admin rate limit. Each
 * search is recorded (masked code) in a short audit trail shown on the page.
 */
class Delicat_Code_Lookup {

    const NONCE      = 'delicat_code_lookup';
    const AUDIT      = 'delicat_code_lookup_audit';
    const AUDIT_MAX  = 40;
    const RATE_MAX   = 60;            // searches per admin per window
    const RATE_WIN   = 600;           // 10 minutes
    const MAX_ROWS   = 50;

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ], 25 );
        add_action( 'wp_ajax_delicat_code_lookup', [ __CLASS__, 'ajax' ] );
    }

    public static function menu(): void {
        add_submenu_page( 'delicat-recharges', 'Recherche TransCode', '🔎 Recherche code',
            'manage_options', 'delicat-code-lookup', [ __CLASS__, 'page' ] );
    }

    /** Same normalisation as the credit path (Delicat_Natcash_Auto::norm_code). */
    public static function norm( string $code ): string {
        if ( class_exists( 'Delicat_Natcash_Auto' ) ) {
            return Delicat_Natcash_Auto::norm_code( $code );
        }
        return strtoupper( str_replace( [ ' ', '-', "\t" ], '', trim( $code ) ) );
    }

    private static function mask( string $code ): string {
        $len = strlen( $code );
        if ( $len <= 5 ) return substr( $code, 0, 1 ) . str_repeat( '•', max( 0, $len - 1 ) );
        return substr( $code, 0, 3 ) . str_repeat( '•', min( 6, $len - 5 ) ) . substr( $code, -2 );
    }

    /* ------------------------------------------------------------------ */
    /* AJAX                                                                */
    /* ------------------------------------------------------------------ */

    public static function ajax(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }
        check_ajax_referer( self::NONCE, 'nonce' );
        nocache_headers();

        $raw  = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['code'] ) ) : '';
        $mode = ( isset( $_POST['mode'] ) && 'contains' === sanitize_key( wp_unslash( (string) $_POST['mode'] ) ) ) ? 'contains' : 'exact';
        $code = self::norm( $raw );

        if ( $code === '' || strlen( $code ) < 4 ) {
            wp_send_json_error( [ 'message' => 'Entrez au moins 4 caractères du TransCode / Txn ID.' ] );
        }
        if ( strlen( $code ) > 64 || ! preg_match( '/^[A-Z0-9._:\/#]+$/', $code ) ) {
            wp_send_json_error( [ 'message' => 'Code invalide : lettres et chiffres uniquement.' ] );
        }
        if ( 'contains' === $mode && strlen( $code ) < 6 ) {
            wp_send_json_error( [ 'message' => 'Recherche partielle : entrez au moins 6 caractères.' ] );
        }

        $uid = get_current_user_id();
        $rk  = 'delicat_lookup_rl_' . $uid;
        $n   = (int) get_transient( $rk );
        if ( $n >= self::RATE_MAX ) {
            wp_send_json_error( [ 'message' => 'Trop de recherches. Réessayez dans quelques minutes.' ], 429 );
        }
        set_transient( $rk, $n + 1, self::RATE_WIN );

        $result = self::lookup( $code, $mode );
        self::audit( $uid, $code, $mode, (string) $result['verdict'] );
        wp_send_json_success( $result );
    }

    /* ------------------------------------------------------------------ */
    /* Lookup (read-only)                                                  */
    /* ------------------------------------------------------------------ */

    private static function method_label( string $tab ): string {
        $map = [
            'moncash'    => 'MonCash',
            'natcash_tc' => 'Natcash (TC)',
            'natcash'    => 'Natcash',
            'manuel'     => 'Manuel',
        ];
        return $map[ $tab ] ?? ucfirst( $tab );
    }

    private static function status_label( string $s ): string {
        $map = [ 'en_attente' => 'En attente', 'approuve' => 'Approuvé', 'rejete' => 'Rejeté' ];
        return $map[ $s ] ?? $s;
    }

    public static function lookup( string $code, string $mode = 'exact' ): array {
        global $wpdb;
        $tbl    = $wpdb->prefix . 'delicat_recharges';
        $ledger = $wpdb->prefix . 'delicat_code_spent';
        $expr   = "REPLACE(REPLACE(REPLACE(UPPER(TRIM(trans_code)),' ',''),'-',''),CHAR(9),'')";

        if ( 'contains' === $mode ) {
            $like  = '%' . $wpdb->esc_like( $code ) . '%';
            $rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$tbl} WHERE trans_code <> '' AND {$expr} LIKE %s ORDER BY created_at DESC LIMIT %d",
                $like, self::MAX_ROWS ) );
            $spent = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$ledger} WHERE code_norm LIKE %s ORDER BY created_at DESC LIMIT %d",
                $like, self::MAX_ROWS ) );
        } else {
            $rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$tbl} WHERE trans_code <> '' AND {$expr} = %s ORDER BY created_at DESC LIMIT %d",
                $code, self::MAX_ROWS ) );
            $spent = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$ledger} WHERE code_norm = %s ORDER BY created_at DESC LIMIT %d",
                $code, self::MAX_ROWS ) );
        }
        $rows  = is_array( $rows ) ? $rows : [];
        $spent = is_array( $spent ) ? $spent : [];

        /* Requests ---------------------------------------------------- */
        $requests = [];
        $users    = [];
        foreach ( $rows as $r ) {
            $user_id = (int) $r->user_id;
            if ( $user_id > 0 && ! isset( $users[ $user_id ] ) ) {
                $u = get_userdata( $user_id );
                $users[ $user_id ] = $u ? (string) $u->display_name : '';
            }
            $note = trim( wp_strip_all_tags( (string) ( $r->note_admin ?? '' ) ) );
            $requests[] = [
                'id'         => (int) $r->id,
                'code'       => (string) $r->trans_code,
                'method'     => self::method_label( (string) $r->tab ),
                'amount'     => number_format( (float) $r->montant, 2, '.', ',' ) . ' HTG',
                'status'     => (string) $r->statut,
                'status_lbl' => self::status_label( (string) $r->statut ),
                'credited'   => (int) $r->wallet_credited === 1,
                'blocked'    => strpos( $note, 'BLOCAGE-MANUEL' ) !== false,
                'email'      => (string) $r->user_email,
                'name'       => $user_id > 0 ? ( $users[ $user_id ] ?? '' ) : '',
                'user_id'    => $user_id,
                'created'    => (string) $r->created_at,
                'updated'    => (string) $r->updated_at,
                'note'       => function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 400 ) : substr( $note, 0, 400 ),
                'open_url'   => admin_url( 'admin.php?page=delicat-recharges&tab=all&highlight=' . (int) $r->id ),
                'user_url'   => $user_id > 0 ? admin_url( 'user-edit.php?user_id=' . $user_id ) : '',
            ];
        }

        /* Spend ledger ------------------------------------------------ */
        $ledger_out = [];
        foreach ( $spent as $s ) {
            $ledger_out[] = [
                'code'        => (string) $s->code_norm,
                'recharge_id' => (int) $s->recharge_id,
                'amount'      => number_format( (float) $s->montant, 2, '.', ',' ) . ' HTG',
                'source'      => (string) $s->source,
                'at'          => (string) $s->created_at,
            ];
        }

        /* Received SMS (diagnostic log, last few dozen) ---------------- */
        $sms_out = [];
        $entries = get_option( 'delicat_natcash_log', [] );
        if ( is_array( $entries ) ) {
            foreach ( $entries as $e ) {
                $sms = (string) ( $e['sms'] ?? '' );
                if ( $sms === '' ) continue;
                $tokens = [];
                if ( preg_match_all( '/[A-Za-z0-9][A-Za-z0-9\-]{3,}/', $sms, $m ) ) {
                    foreach ( $m[0] as $t ) $tokens[] = self::norm( $t );
                }
                if ( ! empty( $e['sms_code'] ) ) $tokens[] = self::norm( (string) $e['sms_code'] );
                $hit = false;
                foreach ( $tokens as $t ) {
                    if ( 'contains' === $mode ? ( strpos( $t, $code ) !== false ) : ( $t === $code ) ) { $hit = true; break; }
                }
                if ( ! $hit ) continue;

                $is_moncash = stripos( $sms, 'moncash' ) !== false
                    || ( isset( $e['provider'] ) && stripos( (string) $e['provider'], 'moncash' ) !== false )
                    || (bool) preg_match( '/Txn\s*ID/i', $sms );
                $parsed = null;
                if ( class_exists( 'Delicat_Natcash_Auto' ) ) {
                    $parsed = $is_moncash ? Delicat_Natcash_Auto::parse_moncash( $sms ) : Delicat_Natcash_Auto::parse_natcash_code( $sms );
                }
                $trusted = class_exists( 'Delicat_SMS_Guard' ) ? (bool) Delicat_SMS_Guard::entry_trusted( $e ) : true;
                $sms_out[] = [
                    'at'       => (string) ( $e['time'] ?? '' ),
                    'provider' => $is_moncash ? 'MonCash' : 'Natcash',
                    'amount'   => ( $parsed && ! empty( $parsed['amount'] ) ) ? number_format( (float) $parsed['amount'], 2, '.', ',' ) . ' HTG' : '',
                    'trusted'  => $trusted,
                    'resolved' => ! empty( $e['resolved'] ),
                    'result'   => wp_strip_all_tags( is_scalar( $e['result'] ?? '' ) ? (string) ( $e['result'] ?? '' ) : wp_json_encode( $e['result'] ) ),
                    'sms'      => function_exists( 'mb_substr' ) ? mb_substr( $sms, 0, 300 ) : substr( $sms, 0, 300 ),
                ];
                if ( count( $sms_out ) >= 10 ) break;
            }
        }

        /* Verdict (exact search) -------------------------------------- */
        $credited = false;
        $pending  = false;
        foreach ( $requests as $q ) {
            if ( $q['credited'] && 'approuve' === $q['status'] ) $credited = true;
            if ( 'en_attente' === $q['status'] ) $pending = true;
        }
        if ( 'contains' === $mode ) {
            $verdict = ( $requests || $ledger_out || $sms_out ) ? 'partial' : 'none';
        } elseif ( $ledger_out || $credited ) {
            $verdict = 'used';
        } elseif ( $pending ) {
            $verdict = 'pending';
        } elseif ( $requests ) {
            $verdict = 'not_credited';
        } elseif ( $sms_out ) {
            $verdict = 'sms_only';
        } else {
            $verdict = 'free';
        }

        return [
            'code'     => $code,
            'mode'     => $mode,
            'verdict'  => $verdict,
            'requests' => $requests,
            'ledger'   => $ledger_out,
            'sms'      => $sms_out,
            'sms_note' => 'Journal SMS : seuls les ' . ( class_exists( 'Delicat_Natcash_Auto' ) ? (int) Delicat_Natcash_Auto::LOG_MAX : 40 ) . ' derniers SMS reçus sont conservés.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Audit                                                               */
    /* ------------------------------------------------------------------ */

    private static function audit( int $uid, string $code, string $mode, string $verdict ): void {
        $log = get_option( self::AUDIT, [] );
        if ( ! is_array( $log ) ) $log = [];
        array_unshift( $log, [
            't' => current_time( 'mysql' ),
            'u' => $uid,
            'c' => self::mask( $code ),
            'm' => $mode,
            'v' => $verdict,
        ] );
        update_option( self::AUDIT, array_slice( $log, 0, self::AUDIT_MAX ), false );
    }

    /* ------------------------------------------------------------------ */
    /* Page                                                                */
    /* ------------------------------------------------------------------ */

    public static function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé.' );
        }
        $nonce   = wp_create_nonce( self::NONCE );
        $prefill = isset( $_GET['code'] ) ? self::norm( sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) ) : '';
        if ( strlen( $prefill ) > 64 || ! preg_match( '/^[A-Z0-9._:\/#]*$/', $prefill ) ) $prefill = '';
        $audit = get_option( self::AUDIT, [] );
        $audit = is_array( $audit ) ? array_slice( $audit, 0, 12 ) : [];
        $vlabel = [
            'used' => 'déjà utilisé', 'pending' => 'en attente', 'not_credited' => 'non crédité',
            'sms_only' => 'SMS seulement', 'free' => 'jamais utilisé', 'partial' => 'résultats partiels', 'none' => 'aucun résultat',
        ];
        ?>
        <div class="wrap dlc-lookup">
            <h1>🔎 Recherche TransCode / Txn ID</h1>
            <p class="dlc-lookup-intro">Vérifiez si un code a déjà été utilisé sur le site, ou retrouvez la transaction d'un client à partir de son code.
               <strong>Lecture seule</strong> : cette page ne crédite, ne bloque et ne débloque rien.</p>

            <form id="dlc-lookup-form" class="dlc-lookup-form" autocomplete="off">
                <label for="dlc-lookup-code" class="screen-reader-text">TransCode / Txn ID</label>
                <input type="text" id="dlc-lookup-code" name="code" maxlength="64" spellcheck="false"
                       autocapitalize="characters" placeholder="Ex : 9F3K2L7Q ou 2034819927" value="<?php echo esc_attr( $prefill ); ?>" required>
                <select id="dlc-lookup-mode" name="mode" aria-label="Type de recherche">
                    <option value="exact">Code exact</option>
                    <option value="contains">Contient (6+ caractères)</option>
                </select>
                <button type="submit" class="button button-primary" id="dlc-lookup-go">Rechercher</button>
            </form>
            <div id="dlc-lookup-out" aria-live="polite"></div>

            <?php if ( $audit ) : ?>
            <details class="dlc-lookup-audit">
                <summary>Dernières recherches (<?php echo count( $audit ); ?>)</summary>
                <table class="widefat striped">
                    <thead><tr><th>Date</th><th>Admin</th><th>Code</th><th>Type</th><th>Résultat</th></tr></thead>
                    <tbody>
                    <?php foreach ( $audit as $a ) :
                        $u = get_userdata( (int) ( $a['u'] ?? 0 ) ); ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $a['t'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( $u ? $u->display_name : '#' . (int) ( $a['u'] ?? 0 ) ); ?></td>
                            <td><code><?php echo esc_html( (string) ( $a['c'] ?? '' ) ); ?></code></td>
                            <td><?php echo esc_html( 'contains' === ( $a['m'] ?? '' ) ? 'contient' : 'exact' ); ?></td>
                            <td><?php echo esc_html( $vlabel[ (string) ( $a['v'] ?? '' ) ] ?? (string) ( $a['v'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endif; ?>
        </div>

        <style>
            .dlc-lookup{max-width:980px}
            .dlc-lookup-intro{color:#555;max-width:760px}
            .dlc-lookup-form{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0 18px;padding:14px;border:1px solid #dcdcf0;border-radius:12px;background:#f8f8ff}
            .dlc-lookup-form input[type=text]{flex:1 1 260px;min-width:0;font-size:16px;padding:8px 12px;letter-spacing:.04em;text-transform:uppercase;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
            .dlc-lookup-form select{min-height:38px}
            .dlc-verdict{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border-radius:12px;border:1px solid;margin:0 0 16px;font-size:14px;line-height:1.5}
            .dlc-verdict b{display:block;font-size:16px;margin-bottom:2px}
            .dlc-verdict .dlc-ico{font-size:22px;line-height:1}
            .dlc-v-used{background:#fdecee;border-color:#f3b4bd;color:#7a1626}
            .dlc-v-pending{background:#fff6e5;border-color:#f6d199;color:#7a4b00}
            .dlc-v-not_credited{background:#fff6e5;border-color:#f6d199;color:#7a4b00}
            .dlc-v-sms_only{background:#e9f2ff;border-color:#b6cdf5;color:#173f7a}
            .dlc-v-free{background:#e9f8ef;border-color:#a8e0bf;color:#145a32}
            .dlc-v-partial,.dlc-v-none{background:#f3f3f7;border-color:#dcdce6;color:#333}
            .dlc-sec{margin:18px 0 8px;font-size:14px;text-transform:uppercase;letter-spacing:.06em;color:#555}
            .dlc-card{border:1px solid #e2e4ea;border-radius:12px;background:#fff;padding:12px 14px;margin:0 0 10px}
            .dlc-card-top{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;justify-content:space-between}
            .dlc-card code{font-size:13px}
            .dlc-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700;background:#eef0f6;color:#333}
            .dlc-b-approuve{background:#e3f6ea;color:#1c6b3c}.dlc-b-en_attente{background:#fff1d6;color:#8a5a00}.dlc-b-rejete{background:#fde3e6;color:#8f1d2c}
            .dlc-b-ok{background:#e3f6ea;color:#1c6b3c}.dlc-b-warn{background:#fff1d6;color:#8a5a00}.dlc-b-bad{background:#fde3e6;color:#8f1d2c}
            .dlc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:6px 16px;margin-top:8px;font-size:13px}
            .dlc-grid span{color:#777;display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
            .dlc-note{margin:8px 0 0;font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word}
            .dlc-sms{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;white-space:pre-wrap;word-break:break-word;background:#f6f7fb;border-radius:8px;padding:8px;margin-top:8px}
            .dlc-muted{color:#888;font-size:12px}
            .dlc-lookup-audit{margin-top:26px}
            .dlc-lookup-audit summary{cursor:pointer;font-weight:600}
            .dlc-lookup-audit table{margin-top:8px}
        </style>
        <script>
        (function(){
            var form = document.getElementById('dlc-lookup-form');
            if (!form) return;
            var input = document.getElementById('dlc-lookup-code'),
                mode = document.getElementById('dlc-lookup-mode'),
                btn = document.getElementById('dlc-lookup-go'),
                out = document.getElementById('dlc-lookup-out'),
                ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                nonce = <?php echo wp_json_encode( $nonce ); ?>;
            function el(tag, cls, text){ var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = String(text); return n; }
            function field(grid, label, value){ if (value === '' || value == null) return; var d = el('div'); d.appendChild(el('span', '', label)); d.appendChild(document.createTextNode(String(value))); grid.appendChild(d); }
            function link(href, text){ var a = el('a', '', text); a.href = href; return a; }
            var verdicts = {
                used:         ['dlc-v-used', '⛔', 'Code déjà utilisé', 'Ce code a déjà donné un crédit. Toute nouvelle soumission sera refusée automatiquement.'],
                pending:      ['dlc-v-pending', '⏳', 'Demande en attente', 'Le code a été soumis mais n’est pas encore crédité.'],
                not_credited: ['dlc-v-not_credited', '⚠️', 'Code soumis, jamais crédité', 'Une demande existe avec ce code mais elle n’a pas crédité de wallet (rejetée ou échouée).'],
                sms_only:     ['dlc-v-sms_only', '📩', 'Paiement reçu (SMS) sans demande', 'Un SMS de confirmation contient ce code, mais aucun client ne l’a soumis sur le site.'],
                free:         ['dlc-v-free', '✅', 'Code jamais utilisé', 'Aucune demande, aucun crédit et aucun SMS récent avec ce code.'],
                partial:      ['dlc-v-partial', '🔎', 'Résultats partiels', 'Codes qui contiennent votre recherche.'],
                none:         ['dlc-v-none', '🔎', 'Aucun résultat', 'Aucun code ne contient cette recherche.']
            };
            function render(d){
                out.textContent = '';
                var v = verdicts[d.verdict] || verdicts.none;
                var box = el('div', 'dlc-verdict ' + v[0]);
                box.appendChild(el('span', 'dlc-ico', v[1]));
                var txt = el('div'); txt.appendChild(el('b', '', v[2] + ' — ' + d.code)); txt.appendChild(document.createTextNode(v[3])); box.appendChild(txt);
                out.appendChild(box);

                out.appendChild(el('h2', 'dlc-sec', 'Demandes de recharge (' + d.requests.length + ')'));
                if (!d.requests.length) out.appendChild(el('p', 'dlc-muted', 'Aucune demande avec ce code.'));
                d.requests.forEach(function(r){
                    var c = el('div', 'dlc-card'), top = el('div', 'dlc-card-top'), left = el('div');
                    left.appendChild(el('strong', '', '#' + r.id + ' · ' + r.method + ' · ' + r.amount + ' '));
                    left.appendChild(el('span', 'dlc-badge dlc-b-' + r.status, r.status_lbl));
                    left.appendChild(document.createTextNode(' '));
                    left.appendChild(el('span', 'dlc-badge ' + (r.credited ? 'dlc-b-ok' : 'dlc-b-warn'), r.blocked ? 'Bloqué (hors-ligne)' : (r.credited ? 'Wallet crédité' : 'Non crédité')));
                    top.appendChild(left);
                    var right = el('div'); right.appendChild(link(r.open_url, 'Ouvrir dans Recharges →')); top.appendChild(right);
                    c.appendChild(top);
                    var g = el('div', 'dlc-grid');
                    field(g, 'Code saisi', r.code); field(g, 'Client', (r.name ? r.name + ' — ' : '') + r.email);
                    field(g, 'Créée le', r.created); field(g, 'Mise à jour', r.updated);
                    c.appendChild(g);
                    if (r.user_url) { var p = el('p', 'dlc-muted'); p.appendChild(link(r.user_url, 'Profil du client')); c.appendChild(p); }
                    if (r.note) c.appendChild(el('p', 'dlc-note', r.note));
                    out.appendChild(c);
                });

                out.appendChild(el('h2', 'dlc-sec', 'Registre « code utilisé » (' + d.ledger.length + ')'));
                if (!d.ledger.length) out.appendChild(el('p', 'dlc-muted', 'Ce code n’est pas dans le registre anti-double crédit.'));
                d.ledger.forEach(function(s){
                    var c = el('div', 'dlc-card'), g = el('div', 'dlc-grid');
                    field(g, 'Code', s.code); field(g, 'Montant', s.amount); field(g, 'Recharge', s.recharge_id ? '#' + s.recharge_id : '—'); field(g, 'Source', s.source || '—'); field(g, 'Enregistré le', s.at);
                    c.appendChild(g); out.appendChild(c);
                });

                out.appendChild(el('h2', 'dlc-sec', 'SMS reçus (' + d.sms.length + ')'));
                if (!d.sms.length) out.appendChild(el('p', 'dlc-muted', 'Aucun SMS récent ne contient ce code.'));
                d.sms.forEach(function(s){
                    var c = el('div', 'dlc-card'), top = el('div', 'dlc-card-top'), left = el('div');
                    left.appendChild(el('strong', '', s.provider + (s.amount ? ' · ' + s.amount : '') + ' '));
                    left.appendChild(el('span', 'dlc-badge ' + (s.trusted ? 'dlc-b-ok' : 'dlc-b-bad'), s.trusted ? 'SMS fiable' : 'SMS suspect / non confirmé'));
                    if (s.resolved) { left.appendChild(document.createTextNode(' ')); left.appendChild(el('span', 'dlc-badge dlc-b-ok', 'Résolu')); }
                    top.appendChild(left); top.appendChild(el('span', 'dlc-muted', s.at));
                    c.appendChild(top);
                    if (s.result) c.appendChild(el('p', 'dlc-note', s.result));
                    c.appendChild(el('div', 'dlc-sms', s.sms));
                    out.appendChild(c);
                });
                out.appendChild(el('p', 'dlc-muted', d.sms_note));
            }
            function search(){
                var code = (input.value || '').trim();
                if (!code) { input.focus(); return; }
                btn.disabled = true; btn.textContent = 'Recherche…';
                var body = new URLSearchParams(); body.set('action', 'delicat_code_lookup'); body.set('nonce', nonce); body.set('code', code); body.set('mode', mode.value);
                fetch(ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function(r){ return r.json().catch(function(){ return { success: false, data: { message: 'Session expirée : rechargez la page.' } }; }); })
                    .then(function(j){
                        if (j && j.success) render(j.data);
                        else { out.textContent = ''; out.appendChild(el('div', 'dlc-verdict dlc-v-none', (j && j.data && j.data.message) || 'Recherche impossible.')); }
                    })
                    .catch(function(){ out.textContent = ''; out.appendChild(el('div', 'dlc-verdict dlc-v-none', 'Erreur réseau. Réessayez.')); })
                    .then(function(){ btn.disabled = false; btn.textContent = 'Rechercher'; });
            }
            form.addEventListener('submit', function(e){ e.preventDefault(); search(); });
            if (input.value) search(); else input.focus();
        })();
        </script>
        <?php
    }
}
