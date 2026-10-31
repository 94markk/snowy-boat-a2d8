<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Delicat_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'admin_init', [ $this, 'settings' ] );
        add_action( 'admin_footer', [ $this, 'ownership_footer' ] );
    }

    /**
     * Ownership + contact notice, shown at the bottom of every Delicat console
     * page. Hooked on admin_footer and gated to our own pages by screen id.
     */
    public function ownership_footer(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'delicat' ) === false ) {
            return;
        }
        $logo = defined( 'DELICAT_URL' ) ? DELICAT_URL . 'assets/img/delicat-logo.png' : '';
        ?>
        <div style="margin:30px auto 20px;max-width:680px;background:#fff;border:1px solid #e2e4ea;
                    border-radius:12px;padding:22px 26px;text-align:center;box-shadow:0 2px 10px rgba(0,0,0,.04);">
            <?php if ( $logo ) : ?>
                <img src="<?php echo esc_url( $logo ); ?>" alt="Délicat Store Haiti"
                     style="max-width:220px;height:auto;margin-bottom:12px;">
            <?php endif; ?>
            <p style="font-size:13px;color:#444;line-height:1.6;margin:8px 0;">
                Cette application est la propriété de <strong>Délicat Store</strong>,
                et personne n'a le droit de l'utiliser sans notre autorisation.
            </p>
            <p style="font-size:13px;color:#0d1b4d;line-height:1.8;margin:8px 0;">
                📞 <strong>+509 33111283</strong><br>
                ✉️ <a href="mailto:info@delicastoreha.com" style="color:#284696;text-decoration:none;">info@delicastoreha.com</a><br>
                🌐 <a href="https://www.delicastoreha.com" target="_blank" style="color:#284696;text-decoration:none;">www.delicastoreha.com</a>
            </p>
            <p style="font-size:11px;color:#999;margin-top:10px;">© <?php echo esc_html( date_i18n('Y') ); ?> Délicat Store Haiti — Tous droits réservés.</p>
        </div>
        <?php
    }

    /* ---- Menu --------------------------------------------------------- */

    public function menu(): void {
        add_menu_page( 'Delicat Console', 'Delicat Console', 'manage_options',
            'delicat-recharges', [ $this, 'page_list' ], 'dashicons-money-alt', 56 );

        add_submenu_page( 'delicat-recharges', 'Recharges', '💳 Recharges',
            'manage_options', 'delicat-recharges', [ $this, 'page_list' ] );

        add_submenu_page( 'delicat-recharges', 'Clients', '👥 Clients',
            'manage_options', 'delicat-clients', [ 'Delicat_Clients', 'page_clients' ] );

        add_submenu_page( 'delicat-recharges', 'Paramètres', '⚙️ Paramètres',
            'manage_options', 'delicat-settings', [ $this, 'page_settings' ] );
    }

    /* ---- Settings ----------------------------------------------------- */

    public function settings(): void {
        $fields = [
            'delicat_admin_email',
            'delicat_moncash_client_id',
            'delicat_moncash_secret',
            'delicat_moncash_sandbox',
            'delicat_moncash_numero',
            'delicat_moncash_nom',
            'delicat_natcash_numero',
            'delicat_natcash_nom',
            'delicat_webhook_url',
            'delicat_webhook_secret',
        ];
        foreach ( $fields as $f ) {
            register_setting( 'delicat_settings_group', $f, 'sanitize_text_field' );
        }
        // Device token(s) — multi-line for multiple phones.
        register_setting( 'delicat_settings_group', 'delicat_fcm_device_token', [
            'sanitize_callback' => function ( $val ) {
                return sanitize_textarea_field( (string) $val );
            },
        ] );
        // Service account JSON is multi-line — don't run sanitize_text_field on it.
        register_setting( 'delicat_settings_group', 'delicat_fcm_service_account', [
            'sanitize_callback' => function ( $val ) {
                $val = trim( (string) $val );
                // Accept only if it parses as JSON; otherwise keep previous value.
                if ( $val === '' ) return '';
                $decoded = json_decode( $val, true );
                return is_array( $decoded ) ? $val : get_option( 'delicat_fcm_service_account', '' );
            },
        ] );

        // Dashboard key reset: if the box was checked on the settings form, rotate it.
        if (
            isset( $_POST['delicat_reset_dashboard_key'], $_POST['option_page'] )
            && $_POST['option_page'] === 'delicat_settings_group'
            && current_user_can( 'manage_options' )
            && check_admin_referer( 'delicat_settings_group-options' )
        ) {
            update_option( 'delicat_dashboard_key', wp_generate_password( 32, false, false ), false );
        }

        // App devices: create / revoke (approve-from-app credentials).
        if (
            isset( $_POST['option_page'] )
            && $_POST['option_page'] === 'delicat_settings_group'
            && current_user_can( 'manage_options' )
            && check_admin_referer( 'delicat_settings_group-options' )
        ) {
            // Client license config (for sites running under a Délicat license).
            if ( isset( $_POST['delicat_cl_key'] ) ) {
                $cfg = [
                    'hq'       => esc_url_raw( wp_unslash( $_POST['delicat_cl_hq'] ?? '' ) ),
                    'domain'   => sanitize_text_field( wp_unslash( $_POST['delicat_cl_domain'] ?? '' ) ),
                    'key'      => sanitize_text_field( wp_unslash( $_POST['delicat_cl_key'] ?? '' ) ),
                    'sign_key' => sanitize_text_field( wp_unslash( $_POST['delicat_cl_sign'] ?? '' ) ),
                ];
                update_option( 'delicat_client_license', $cfg, false );
                delete_transient( 'delicat_license_cache' ); // force re-check
            }
        }

        // App devices: create / revoke (approve-from-app credentials).
        if (
            isset( $_POST['option_page'] )
            && $_POST['option_page'] === 'delicat_settings_group'
            && current_user_can( 'manage_options' )
            && check_admin_referer( 'delicat_settings_group-options' )
            && class_exists( 'Delicat_App_Api' )
        ) {
            if ( ! empty( $_POST['delicat_revoke_device'] ) && is_array( $_POST['delicat_revoke_device'] ) ) {
                foreach ( $_POST['delicat_revoke_device'] as $did ) {
                    Delicat_App_Api::revoke_device( sanitize_text_field( $did ) );
                }
            }
            if ( ! empty( $_POST['delicat_new_device_label'] ) ) {
                $label = sanitize_text_field( wp_unslash( $_POST['delicat_new_device_label'] ) );
                list( $device_id, $token, $sign_key ) = Delicat_App_Api::create_device( $label );
                // Stash for one-time display after redirect.
                set_transient( 'delicat_new_device', [ 'device_id' => $device_id, 'token' => $token, 'sign_key' => $sign_key ], 60 );
            }
        }
    }

    public function page_settings(): void {
        $opt = fn( $k, $d = '' ) => esc_attr( get_option( $k, $d ) );
        ?>
        <div class="wrap">
            <h1>⚙️ Delicat Recharge — Paramètres</h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'delicat_settings_group' ); ?>

                <h2>📧 Notifications</h2>
                <table class="form-table">
                    <tr><th>Email admin</th>
                        <td><input type="email" name="delicat_admin_email" class="regular-text"
                                   value="<?php echo $opt('delicat_admin_email', get_option('admin_email')); ?>"></td></tr>
                </table>

                <h2>💚 MonCash API (pour vérification automatique)</h2>
                <p class="description">Obtenez vos credentials sur
                    <a href="https://moncashbutton.digicelgroup.com/Moncash-business/merchant/login" target="_blank">
                    MonCash Business Portal</a>.</p>
                <table class="form-table">
                    <tr><th>Client ID</th>
                        <td><input type="text" name="delicat_moncash_client_id" class="regular-text"
                                   value="<?php echo $opt('delicat_moncash_client_id'); ?>"></td></tr>
                    <tr><th>Secret</th>
                        <td><input type="password" name="delicat_moncash_secret" class="regular-text"
                                   value="<?php echo $opt('delicat_moncash_secret'); ?>"></td></tr>
                    <tr><th>Environnement</th>
                        <td>
                            <label><input type="radio" name="delicat_moncash_sandbox" value="1"
                                <?php checked( $opt('delicat_moncash_sandbox','1'), '1' ); ?>> Sandbox (test)</label>
                            &nbsp;&nbsp;
                            <label><input type="radio" name="delicat_moncash_sandbox" value="0"
                                <?php checked( $opt('delicat_moncash_sandbox','1'), '0' ); ?>> Production (live)</label>
                            <p class="description">Utilisez Sandbox d'abord, puis passez en Production quand tout est OK.</p>
                        </td></tr>
                    <tr><th>Numéro MonCash (affiché au client)</th>
                        <td><input type="text" name="delicat_moncash_numero" class="regular-text"
                                   value="<?php echo $opt('delicat_moncash_numero','39421885'); ?>"></td></tr>
                    <tr><th>Nom MonCash</th>
                        <td><input type="text" name="delicat_moncash_nom" class="regular-text"
                                   value="<?php echo $opt('delicat_moncash_nom','LOUIS MARKEN'); ?>"></td></tr>
                </table>

                <h2>🔵 Natcash</h2>
                <table class="form-table">
                    <tr><th>Numéro Natcash (affiché au client)</th>
                        <td><input type="text" name="delicat_natcash_numero" class="regular-text"
                                   value="<?php echo $opt('delicat_natcash_numero','33111283'); ?>"></td></tr>
                    <tr><th>Nom Natcash</th>
                        <td><input type="text" name="delicat_natcash_nom" class="regular-text"
                                   value="<?php echo $opt('delicat_natcash_nom','Damas Louis'); ?>"></td></tr>
                </table>

                <h2>🔗 Webhook (Make / Zapier / n8n)</h2>
                <table class="form-table">
                    <tr><th>Webhook URL</th>
                        <td><input type="url" name="delicat_webhook_url" class="large-text"
                                   value="<?php echo $opt('delicat_webhook_url'); ?>">
                            <p class="description">Recevra un POST JSON à chaque création ou mise à jour.</p></td></tr>
                    <tr><th>Webhook Secret</th>
                        <td><input type="text" name="delicat_webhook_secret" class="regular-text"
                                   value="<?php echo $opt('delicat_webhook_secret'); ?>">
                            <p class="description">Signe les requêtes via <code>X-Delicat-Signature</code>.</p></td></tr>
                </table>

                <?php if ( defined( 'DELICAT_IS_CLIENT' ) && DELICAT_IS_CLIENT ) : ?>
                <h2>🔑 Licence (activation)</h2>
                <p class="description">Entrez la licence fournie par Délicat Store pour activer le système.</p>
                <?php $cl = class_exists('Delicat_Client_License') ? Delicat_Client_License::config() : []; ?>
                <table class="form-table">
                    <tr><th>Serveur HQ</th>
                        <td><input type="text" name="delicat_cl_hq" class="regular-text" value="<?php echo esc_attr( $cl['hq'] ?? 'https://delicastoreha.com' ); ?>"></td></tr>
                    <tr><th>Votre domaine</th>
                        <td><input type="text" name="delicat_cl_domain" class="regular-text" placeholder="monstore.com" value="<?php echo esc_attr( $cl['domain'] ?? '' ); ?>"></td></tr>
                    <tr><th>Clé de licence</th>
                        <td><input type="text" name="delicat_cl_key" class="regular-text" value="<?php echo esc_attr( $cl['key'] ?? '' ); ?>"></td></tr>
                    <tr><th>Clé de signature</th>
                        <td><input type="text" name="delicat_cl_sign" class="regular-text" value="<?php echo esc_attr( $cl['sign_key'] ?? '' ); ?>"></td></tr>
                    <?php if ( class_exists('Delicat_Client_License') && Delicat_Client_License::is_configured() ) : ?>
                    <tr><th>Statut</th>
                        <td><?php echo Delicat_Client_License::valid()
                            ? '<span style="color:#1b5e20;font-weight:700;">🟢 Licence active</span>'
                            : '<span style="color:#b71c1c;font-weight:700;">🔴 Licence invalide ou expirée</span>'; ?></td></tr>
                    <tr><th>Tester la notification</th>
                        <td>
                            <?php
                            if ( isset( $_GET['delicat_test_relay'] ) && check_admin_referer( 'delicat_test_relay' ) ) {
                                echo '<p style="font-weight:700;">' . esc_html( Delicat_Client_License::test_relay() ) . '</p>';
                            }
                            $test_url = wp_nonce_url( admin_url( 'admin.php?page=delicat-settings&delicat_test_relay=1' ), 'delicat_test_relay' );
                            ?>
                            <a href="<?php echo esc_url( $test_url ); ?>" class="button button-secondary">🔔 Envoyer une notification de test</a>
                            <p class="description">Envoie un push de test via HQ vers votre téléphone, et affiche le résultat exact.</p>
                        </td></tr>
                    <?php endif; ?>
                </table>
                <?php endif; // DELICAT_IS_CLIENT ?>

                <h2>📊 Tableau de bord — Appareils (approuver depuis l'app)</h2>
                <p class="description">Pour <strong>approuver / rejeter</strong> les recharges depuis l'app, créez un appareil par téléphone. Le jeton ne s'affiche qu'une seule fois — copiez-le dans l'app immédiatement.</p>
                <table class="form-table">
                    <?php
                    $devs = class_exists('Delicat_App_Api') ? Delicat_App_Api::devices() : [];
                    // A freshly created device passes its plain token via transient for one-time display.
                    $just = get_transient( 'delicat_new_device' );
                    if ( $just ) { delete_transient( 'delicat_new_device' ); }
                    ?>
                    <?php if ( $just ) : ?>
                    <tr><th style="color:#1b5e20;">✅ Nouvel appareil</th>
                        <td>
                            <p><strong>Copiez ces deux valeurs dans l'app maintenant (le jeton ne sera plus affiché) :</strong></p>
                            <p>Device ID :<br><input type="text" readonly value="<?php echo esc_attr( $just['device_id'] ); ?>" class="large-text" style="font-family:monospace;" onclick="this.select();"></p>
                            <p>Jeton (Token) :<br><input type="text" readonly value="<?php echo esc_attr( $just['token'] ); ?>" class="large-text" style="font-family:monospace;" onclick="this.select();"></p>
                            <p>Clé de signature (Sign Key) :<br><input type="text" readonly value="<?php echo esc_attr( $just['sign_key'] ); ?>" class="large-text" style="font-family:monospace;" onclick="this.select();"></p>
                        </td></tr>
                    <?php endif; ?>
                    <tr><th>Appareils enregistrés</th>
                        <td>
                            <?php if ( empty( $devs ) ) : ?>
                                <p class="description">Aucun appareil. Créez-en un ci-dessous.</p>
                            <?php else : foreach ( $devs as $did => $d ) : ?>
                                <p style="margin:4px 0;">
                                    <code><?php echo esc_html( $did ); ?></code> —
                                    <strong><?php echo esc_html( $d['label'] ?? '' ); ?></strong>
                                    <?php echo ! empty( $d['enabled'] ) ? '🟢' : '🔴'; ?>
                                    <small style="color:#888;">vu: <?php echo esc_html( $d['last_seen'] ?: 'jamais' ); ?></small>
                                    <label style="margin-left:10px;"><input type="checkbox" name="delicat_revoke_device[]" value="<?php echo esc_attr( $did ); ?>"> révoquer</label>
                                </p>
                            <?php endforeach; endif; ?>
                        </td></tr>
                    <tr><th>Créer un appareil</th>
                        <td>
                            <input type="text" name="delicat_new_device_label" placeholder="Ex: Téléphone Louis" class="regular-text">
                            <p class="description">Entrez un nom puis cliquez Enregistrer. Le Device ID + Jeton s'afficheront une fois.</p>
                        </td></tr>
                </table>

                <h2>📊 Tableau de bord — Lecture seule (statistiques)</h2>
                <table class="form-table">
                    <tr><th>Clé du tableau de bord</th>
                        <td>
                            <?php $dk = class_exists('Delicat_Rest') ? Delicat_Rest::get_key() : ''; ?>
                            <input type="text" readonly value="<?php echo esc_attr( $dk ); ?>" class="large-text" style="font-family:monospace;" onclick="this.select();">
                            <p class="description">Collez cette clé dans l'app → Tableau de bord. Cliquez pour la sélectionner.</p>
                        </td></tr>
                    <tr><th>URL complète (test)</th>
                        <td>
                            <?php $durl = esc_url( home_url( '/wp-json/delicat/v1/stats?key=' . $dk ) ); ?>
                            <input type="text" readonly value="<?php echo $durl; ?>" class="large-text" style="font-family:monospace;font-size:11px;" onclick="this.select();">
                            <p class="description">Ouvrez ce lien dans un navigateur : si vous voyez des données (JSON), tout fonctionne. ⚠️ Ne la partagez avec personne — elle donne accès à vos statistiques.</p>
                        </td></tr>
                    <tr><th>Réinitialiser la clé</th>
                        <td>
                            <label><input type="checkbox" name="delicat_reset_dashboard_key" value="1"> Générer une nouvelle clé (l'ancienne cessera de fonctionner)</label>
                            <p class="description">Cochez puis Enregistrer si vous pensez que la clé a été exposée. Vous devrez la recoller dans l'app.</p>
                        </td></tr>
                </table>

                <h2>📱 Notification Android (Delicat Notification)</h2>
                <p class="description">Recevez une alerte sur votre téléphone à chaque nouvelle recharge et à chaque approbation.</p>
                <table class="form-table">
                    <tr><th>Token(s) de l'appareil</th>
                        <td><textarea name="delicat_fcm_device_token" rows="3" class="large-text" style="font-family:monospace;font-size:11px;"><?php echo esc_textarea( get_option('delicat_fcm_device_token','') ); ?></textarea>
                            <p class="description">Copiez-le depuis l'app <strong>Delicat Notification</strong>. Pour plusieurs téléphones (vous + un employé), collez chaque token sur une ligne séparée.</p></td></tr>
                    <tr><th>Service Account JSON (Firebase)</th>
                        <td>
                            <textarea name="delicat_fcm_service_account" rows="6" class="large-text" style="font-family:monospace;font-size:11px;"
                                      placeholder='{ "type": "service_account", "project_id": "...", ... }'><?php echo esc_textarea( get_option('delicat_fcm_service_account','') ); ?></textarea>
                            <p class="description">Depuis Firebase Console → Paramètres du projet → Comptes de service → Générer une nouvelle clé privée. Collez tout le contenu du fichier JSON ici.</p>
                        </td></tr>
                </table>

                <?php do_action( 'delicat_settings_extra' ); ?>

                <?php submit_button( 'Enregistrer' ); ?>
            </form>

            <hr>
            <h2>Shortcode</h2>
            <p>Collez dans n'importe quelle page Elementor (widget <strong>Shortcode</strong>) :</p>
            <code style="font-size:16px;background:#f0f0f0;padding:8px 16px;border-radius:6px;display:inline-block;">[delicat_recharge]</code>
        </div>
        <?php
    }

    /* ---- Recharge list ------------------------------------------------ */

    public function page_list(): void {
        $f_statut = sanitize_text_field( $_GET['statut'] ?? '' );
        // Natcash (TC) is listed first in the tabs (primary service), but the
        // page defaults to showing ALL methods so nothing ever looks "missing".
        $f_tab = sanitize_text_field( $_GET['tab'] ?? '' );
        if ( $f_tab === 'all' ) $f_tab = '';
        $highlight = absint( $_GET['highlight'] ?? 0 );

        $records = Delicat_Database::get_all( [
            'statut' => $f_statut,
            'tab'    => $f_tab,
            'limit'  => 200,
        ] );

        $counts  = Delicat_Database::count_by_statut();
        $nonce   = wp_create_nonce( 'delicat_admin_nonce' );
        $ajax    = admin_url( 'admin-ajax.php' );

        $sc = [ 'en_attente' => '#f0ad4e', 'approuve' => '#5cb85c', 'rejete' => '#d9534f' ];
        $sl = [ 'en_attente' => 'En attente', 'approuve' => 'Approuvé', 'rejete' => 'Rejeté' ];
        ?>
        <div class="wrap">
            <h1>💳 Demandes de recharge
                <a href="<?php echo esc_url( admin_url('admin.php?page=delicat-recharges&dr_export=transactions&_wpnonce='.wp_create_nonce('dr_export_nonce')) ); ?>"
                   class="page-title-action">⬇️ Exporter CSV</a>
            </h1>

            <!-- Filters -->
            <div style="margin:12px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <?php foreach ( [
                    '' => 'Tous',
                    'en_attente' => '⏳ En attente (' . ( $counts['en_attente'] ?? 0 ) . ')',
                    'approuve'   => '✅ Approuvés',
                    'rejete'     => '❌ Rejetés',
                ] as $val => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'delicat-recharges', 'statut' => $val, 'tab' => ( $f_tab === '' ? 'all' : $f_tab ) ] ) ); ?>"
                       class="button <?php echo $f_statut === $val ? 'button-primary' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
                &nbsp;|&nbsp;
                <?php foreach ( [ 'natcash_tc' => 'Natcash (TC)', 'all' => 'Toutes méthodes', 'moncash' => 'MonCash', 'natcash' => 'Natcash', 'manuel' => 'Manuel' ] as $val => $label ) :
                    $is_active = ( $val === 'all' ) ? ( $f_tab === '' ) : ( $f_tab === $val ); ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'delicat-recharges', 'statut' => $f_statut, 'tab' => $val ] ) ); ?>"
                       class="button <?php echo $is_active ? 'button-primary' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div id="dr-admin-msg" style="display:none;margin-bottom:12px;"></div>

            <?php $dr_services = Delicat_Database::services(); ?>
            <div style="margin:8px 0 14px;border:1px solid #d7e3d7;border-radius:10px;background:#f4faf4;padding:14px 16px;">
                <p style="margin:0 0 10px;font-weight:800;color:#2e7d32;">🔌 Services de recharge — activer / désactiver</p>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <?php foreach ( [ 'natcash_tc' => 'Natcash Auto (TC)', 'moncash' => 'MonCash', 'natcash' => 'Natcash Auto' ] as $skey => $slabel ) :
                        $son = ! empty( $dr_services[ $skey ] ); ?>
                        <label style="display:flex;align-items:center;gap:9px;background:#fff;border:1px solid #cfe0cf;border-radius:10px;padding:9px 13px;cursor:pointer;">
                            <input type="checkbox" class="dr-svc-check" data-service="<?php echo esc_attr( $skey ); ?>"
                                   data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax="<?php echo esc_url( $ajax ); ?>" <?php checked( $son ); ?>>
                            <span style="font-weight:700;"><?php echo esc_html( $slabel ); ?></span>
                            <span class="dr-svc-state" style="font-size:12px;font-weight:800;color:<?php echo $son ? '#2e7d32' : '#c62828'; ?>;"><?php echo $son ? 'ACTIF' : 'OFF'; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p style="margin:10px 0 0;color:#888;font-size:12px;">Désactiver un service le retire du formulaire client et bloque toute nouvelle soumission (côté serveur). Les demandes déjà en attente ne sont pas affectées.</p>
            </div>

            <!-- ▼▼ Pré-enregistrer / bloquer un TransCode (anti double-dip) ▼▼ -->
            <div style="margin:8px 0 18px;border:1px solid #d9cfe8;border-radius:10px;background:#faf7ff;">
                <button type="button" id="dr-me-toggle" class="button"
                        style="width:100%;text-align:left;background:#6a1b9a;color:#fff;border-color:#4a148c;font-weight:700;padding:10px 14px;">
                    ➕ Enregistrer un code déjà payé (bloquer sur le site)
                </button>
                <div id="dr-me-panel" style="display:none;padding:16px;">
                    <p style="margin:0 0 12px;color:#555;font-size:13px;">
                        Le client vous a payé <strong>hors du site</strong> ? Entrez son TransCode ici pour le marquer comme
                        <strong>déjà utilisé</strong>. S'il essaie ensuite de le soumettre sur le site pour être crédité une 2ᵉ fois,
                        ce sera automatiquement <strong>refusé</strong>.
                    </p>
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th style="width:170px;padding:8px 10px;">TransCode / Txn ID *</th>
                            <td style="padding:8px 10px;"><input type="text" id="dr-me-code" class="regular-text" placeholder="Ex: 9F3K2L…" autocomplete="off"></td>
                        </tr>
                        <tr>
                            <th style="padding:8px 10px;">Montant (HTG) *</th>
                            <td style="padding:8px 10px;"><input type="number" id="dr-me-montant" class="regular-text" min="1" step="0.01" placeholder="Ex: 500"></td>
                        </tr>
                        <tr>
                            <th style="padding:8px 10px;">Méthode</th>
                            <td style="padding:8px 10px;">
                                <select id="dr-me-tab">
                                    <option value="manuel">Manuel</option>
                                    <option value="moncash">MonCash</option>
                                    <option value="natcash">Natcash</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th style="padding:8px 10px;">Email client</th>
                            <td style="padding:8px 10px;">
                                <input type="email" id="dr-me-email" class="regular-text" placeholder="obligatoire seulement si vous créditez">
                            </td>
                        </tr>
                    </table>
                    <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="button" id="dr-me-block" class="button button-primary"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax="<?php echo esc_url( $ajax ); ?>"
                                style="background:#6a1b9a;border-color:#4a148c;">
                            🔒 Bloquer ce code (déjà validé)
                        </button>
                        <button type="button" id="dr-me-credit" class="button"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax="<?php echo esc_url( $ajax ); ?>"
                                style="background:#43a047;color:#fff;border-color:#388e3c;">
                            💳 Créditer le wallet maintenant
                        </button>
                        <button type="button" id="dr-me-verify" class="button"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax="<?php echo esc_url( $ajax ); ?>"
                                style="background:#1565c0;color:#fff;border-color:#0d47a1;">
                            ✔️ Vérifier via SMS & créditer
                        </button>
                    </div>
                    <p style="margin:10px 0 0;color:#888;font-size:12px;">
                        <strong>Bloquer</strong> = enregistre le code sans toucher au wallet (vous avez déjà donné la valeur).
                        <strong>Créditer</strong> = enregistre le code <em>et</em> ajoute le montant au wallet du client maintenant.
                        <strong>Vérifier via SMS</strong> = ne crédite que si un vrai SMS reçu correspond exactement (fournisseur + code + montant). Sinon c'est refusé.
                    </p>
                </div>
            </div>
            <!-- ▲▲ fin pré-enregistrement ▲▲ -->

            <?php if ( class_exists( 'Delicat_Watchdog' ) ) Delicat_Watchdog::render_panel(); ?>

            <?php if ( class_exists( 'Delicat_Guard' ) ) Delicat_Guard::render_panel(); ?>


            <?php $wf_nonce = wp_create_nonce( 'delicat_workflow_nonce' ); ?>
            <div style="margin:10px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;
                        border:1px solid #e0e0e0;border-radius:10px;padding:12px 14px;background:#fafafa;">
                <strong style="font-size:12px;color:#555;">Actions groupées :</strong>
                <button id="dr-bulk-approve" class="button button-primary"
                        data-nonce="<?php echo esc_attr( $wf_nonce ); ?>"
                        data-ajax="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>">
                    ✅ Approuver la sélection
                </button>
                <button id="dr-bulk-reject" class="button"
                        style="background:#c62828;border-color:#b71c1c;color:#fff;"
                        data-nonce="<?php echo esc_attr( $wf_nonce ); ?>"
                        data-ajax="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>">
                    ❌ Rejeter la sélection
                </button>
                <span id="dr-bulk-count" style="font-weight:700;color:#1565c0;font-size:12px;"></span>
                <span style="color:#888;font-size:12px;">Cochez des demandes en attente puis choisissez une action.</span>
            </div>

            <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
                <thead>
                    <tr>
                        <th style="width:28px;"><input type="checkbox" id="dr-check-all"></th>
                        <th style="width:50px;">#</th>
                        <th>Email</th>
                        <th>Méthode</th>
                        <th>Montant</th>
                        <th>TransCode</th>
                        <th>Preuve</th>
                        <th>Wallet</th>
                        <th>Statut</th>
                        <th>Date</th>
                        <th style="width:180px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="dr-rows">
                <?php if ( empty( $records ) ) : ?>
                    <tr><td colspan="11" style="text-align:center;padding:30px;color:#888;">Aucune demande.</td></tr>
                <?php else : ?>
                    <?php foreach ( $records as $r ) :
                        $risk = class_exists( 'Delicat_Workflow' ) ? Delicat_Workflow::risk_flags( $r ) : [];
                    ?>
                    <tr id="dr-row-<?php echo $r->id; ?>"<?php echo $risk ? ' style="background:#fff8e1;"' : ''; ?>>
                        <td>
                            <?php if ( $r->statut === 'en_attente' ) : ?>
                                <input type="checkbox" class="dr-check" value="<?php echo (int) $r->id; ?>">
                            <?php endif; ?>
                        </td>
                        <td><?php echo $r->id; ?></td>
                        <td>
                            <?php echo esc_html( $r->user_email ); ?>
                            <?php if ( $risk ) : ?>
                                <br><span class="dr-risk" title="<?php echo esc_attr( implode( ' • ', $risk ) ); ?>"
                                          style="color:#c62828;font-size:11px;font-weight:700;cursor:help;">
                                    ⚠️ <?php echo count( $risk ); ?> alerte(s)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="background:<?php echo $r->tab==='moncash'?'#00897b':( ( $r->tab==='natcash' || $r->tab==='natcash_tc' )?'#1565c0':'#6d4c41'); ?>;
                                         color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">
                                <?php echo strtoupper( $r->tab ); ?>
                            </span>
                        </td>
                        <td><strong><?php echo number_format( $r->montant, 2 ); ?> HTG</strong></td>
                        <td><?php echo esc_html( $r->trans_code ?: '—' ); ?></td>
                        <td>
                            <?php if ( $r->preuve_url ) : ?>
                                <a href="<?php echo esc_url( Delicat_Files::view_url( $r->preuve_url ) ); ?>" target="_blank">📎 Voir</a>
                            <?php else : echo '—'; endif; ?>
                        </td>
                        <td><?php echo $r->wallet_credited ? '✅' : '—'; ?></td>
                        <td>
                            <span style="background:<?php echo $sc[$r->statut]??'#999'; ?>;
                                         color:#fff;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:600;">
                                <?php echo $sl[$r->statut] ?? $r->statut; ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap;"><?php echo esc_html( $r->created_at ); ?></td>
                        <td>
                            <?php if ( $r->statut !== 'approuve' ) : ?>
                            <button class="button button-small dr-approve"
                                    data-id="<?php echo $r->id; ?>"
                                    data-nonce="<?php echo $nonce; ?>"
                                    data-ajax="<?php echo $ajax; ?>"
                                    style="background:#43a047;color:#fff;border-color:#388e3c;">
                                ✅ Approuver
                            </button>
                            <?php endif; ?>
                            <?php if ( $r->statut !== 'rejete' ) : ?>
                            <button class="button button-small dr-reject"
                                    data-id="<?php echo $r->id; ?>"
                                    data-nonce="<?php echo $nonce; ?>"
                                    data-ajax="<?php echo $ajax; ?>"
                                    style="background:#e53935;color:#fff;border-color:#c62828;margin-top:4px;">
                                ❌ Rejeter
                            </button>
                            <?php endif; ?>
                            <?php if ( $r->statut === 'approuve' && (int) $r->wallet_credited === 1 && strpos( (string) $r->note_admin, '[REMBOURSÉ' ) === false && strpos( (string) $r->note_admin, '[BLOCAGE-MANUEL]' ) === false ) : ?>
                            <button class="button button-small dr-refund"
                                    data-id="<?php echo $r->id; ?>"
                                    data-nonce="<?php echo esc_attr( $wf_nonce ); ?>"
                                    data-ajax="<?php echo $ajax; ?>"
                                    style="background:#6a1b9a;color:#fff;border-color:#4a148c;margin-top:4px;">
                                ↩️ Rembourser
                            </button>
                            <?php endif; ?>
                            <?php if ( $r->note_admin ) : ?>
                                <br><small style="color:#888;" title="<?php echo esc_attr($r->note_admin); ?>">ℹ️ Note</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
        (function($){
            // Phase 2: highlight + scroll to a request opened from a phone notification.
            var hl = <?php echo (int) $highlight; ?>;
            if (hl) {
                var $row = $('#dr-row-' + hl);
                if ($row.length) {
                    $row.css({transition:'background 0.5s'});
                    var on = '#fff3cd', off = '';
                    var n = 0;
                    var flash = setInterval(function(){
                        $row.css('background', (n % 2 === 0) ? on : off);
                        if (++n > 6) { clearInterval(flash); $row.css('background', on); }
                    }, 400);
                    $('html,body').animate({ scrollTop: $row.offset().top - 120 }, 500);
                }
            }

            function adminAction(id, actionType, btn) {
                var nonce   = btn.data('nonce');
                var ajaxUrl = btn.data('ajax');
                btn.prop('disabled', true).text('...');

                $.post(ajaxUrl, {
                    action:      'delicat_admin_action',
                    action_type: actionType,
                    id:          id,
                    nonce:       nonce
                }, function(res) {
                    var $msg = $('#dr-admin-msg');
                    if (res.success) {
                        $msg.css({background:'#e8f5e9',border:'1px solid #a5d6a7',color:'#2e7d32',
                                  padding:'10px 16px',borderRadius:'6px'})
                            .html(res.data.message).show();
                        // Refresh the row area after a moment
                        setTimeout(function(){ location.reload(); }, 1500);
                    } else {
                        $msg.css({background:'#ffebee',border:'1px solid #ef9a9a',color:'#c62828',
                                  padding:'10px 16px',borderRadius:'6px'})
                            .html(res.data.message).show();
                        btn.prop('disabled', false).text(actionType === 'approuver' ? '✅ Approuver' : '❌ Rejeter');
                    }
                });
            }

            $(document).on('click', '.dr-approve', function() {
                var id = $(this).data('id');
                if (!confirm('Approuver cette demande et créditer le wallet ?')) return;
                adminAction(id, 'approuver', $(this));
            });

            $(document).on('click', '.dr-reject', function() {
                var id = $(this).data('id');
                if (!confirm('Rejeter cette demande ? Le client sera notifié.')) return;
                adminAction(id, 'rejeter', $(this));
            });

            // ── Check-all toggle ──
            $(document).on('change', '#dr-check-all', function(){
                $('.dr-check').prop('checked', $(this).is(':checked'));
                drUpdateCount();
            });
            $(document).on('change', '.dr-check', function(){ drUpdateCount(); });

            // Live selection counter — so you always know how many rows an
            // action will affect before you run it.
            function drUpdateCount(){
                var n = $('.dr-check:checked').length;
                $('#dr-bulk-count').text(n ? (n + ' sélectionnée(s)') : '');
            }

            // ── Bulk reject ──
            $(document).on('click', '#dr-bulk-reject', function(){
                var btn = $(this);
                var ids = $('.dr-check:checked').map(function(){ return $(this).val(); }).get();
                if (!ids.length) { alert('Cochez au moins une demande en attente.'); return; }
                // Two-step confirmation: rejecting notifies clients and cannot be
                // silently undone, so require an explicit typed confirmation for
                // larger batches.
                if (!confirm('❌ REJETER ' + ids.length + ' demande(s) ?\n\nLes clients seront notifiés.')) return;
                if (ids.length >= 5) {
                    var typed = prompt('Confirmation requise pour ' + ids.length + ' rejets.\nTapez REJETER pour confirmer :');
                    if (typed !== 'REJETER') { alert('Annulé.'); return; }
                }

                btn.prop('disabled', true).text('Traitement…');
                $.post(btn.data('ajax'), {
                    action: 'delicat_bulk_reject',
                    nonce:  btn.data('nonce'),
                    ids:    ids
                }, function(res){
                    var $msg = $('#dr-admin-msg');
                    $msg.css({padding:'10px 14px',borderRadius:'6px',
                        background: res.success ? '#e8f5e9' : '#ffebee',
                        color: res.success ? '#2e7d32' : '#c62828'})
                        .text((res.data && res.data.message) || 'Terminé').show();
                    setTimeout(function(){ location.reload(); }, 1600);
                }).fail(function(){
                    btn.prop('disabled', false).text('❌ Rejeter la sélection');
                    alert('Erreur réseau.');
                });
            });

            // ── Bulk approve ──
            $(document).on('click', '#dr-bulk-approve', function(){
                var btn = $(this);
                var ids = $('.dr-check:checked').map(function(){ return $(this).val(); }).get();
                if (!ids.length) { alert('Cochez au moins une demande en attente.'); return; }
                if (!confirm('Approuver ' + ids.length + ' demande(s) et créditer les wallets ?')) return;
                // Extra guard for large batches — this moves real money.
                if (ids.length >= 5) {
                    var typed = prompt('Confirmation requise pour ' + ids.length + ' approbations (crédit réel).\nTapez APPROUVER pour confirmer :');
                    if (typed !== 'APPROUVER') { alert('Annulé.'); return; }
                }

                btn.prop('disabled', true).text('Traitement…');
                $.post(btn.data('ajax'), {
                    action: 'delicat_bulk_approve',
                    nonce:  btn.data('nonce'),
                    ids:    ids
                }, function(res){
                    var $msg = $('#dr-admin-msg');
                    $msg.css({padding:'10px 14px',borderRadius:'6px',
                        background: res.success ? '#e8f5e9' : '#ffebee',
                        color: res.success ? '#2e7d32' : '#c62828'})
                        .text((res.data && res.data.message) || 'Terminé').show();
                    setTimeout(function(){ location.reload(); }, 1600);
                }).fail(function(){
                    btn.prop('disabled', false).text('✅ Approuver la sélection');
                    alert('Erreur réseau.');
                });
            });

            // ── Refund / reversal ──
            $(document).on('click', '.dr-refund', function(){
                var btn = $(this);
                var id  = btn.data('id');
                var reason = prompt('Motif du remboursement ? (optionnel)\n\nCeci débitera le wallet du client du montant de cette recharge.');
                if (reason === null) return; // cancelled
                btn.prop('disabled', true).text('…');
                $.post(btn.data('ajax'), {
                    action: 'delicat_refund',
                    nonce:  btn.data('nonce'),
                    id:     id,
                    reason: reason
                }, function(res){
                    var $msg = $('#dr-admin-msg');
                    $msg.css({padding:'10px 14px',borderRadius:'6px',
                        background: res.success ? '#e8f5e9' : '#ffebee',
                        color: res.success ? '#2e7d32' : '#c62828'})
                        .text((res.data && res.data.message) || 'Terminé').show();
                    setTimeout(function(){ location.reload(); }, 1800);
                }).fail(function(){
                    btn.prop('disabled', false).text('↩️ Rembourser');
                    alert('Erreur réseau.');
                });
            });
            // ── Pré-enregistrement / blocage d'un TransCode ──
            $(document).on('click', '#dr-me-toggle', function(){
                $('#dr-me-panel').slideToggle(150);
            });

            function meSubmit(mode, btn){
                var code    = $.trim($('#dr-me-code').val());
                var montant = $('#dr-me-montant').val();
                var tab     = $('#dr-me-tab').val();
                var email   = $.trim($('#dr-me-email').val());

                if (!code)            { alert('Entrez le TransCode.'); return; }
                if (!(montant > 0))   { alert('Entrez un montant valide.'); return; }
                if ((mode === 'credit' || mode === 'verify') && !email) { alert('Email du client requis.'); return; }

                var confirmMsg;
                if (mode === 'credit')       confirmMsg = 'Créditer ' + montant + ' HTG à ' + email + ' et bloquer le code « ' + code + ' » ?';
                else if (mode === 'verify')  confirmMsg = 'Vérifier « ' + code + ' » via le SMS reçu, puis créditer ' + montant + ' HTG à ' + email + ' seulement si tout correspond ?';
                else                         confirmMsg = 'Marquer le code « ' + code + ' » comme déjà utilisé (sans créditer) ?';
                if (!confirm(confirmMsg)) return;

                var $all = $('#dr-me-block, #dr-me-credit, #dr-me-verify');
                $all.prop('disabled', true);
                btn.text('Traitement…');

                $.post(btn.data('ajax'), {
                    action:     'delicat_manual_entry',
                    nonce:      btn.data('nonce'),
                    mode:       mode,
                    trans_code: code,
                    montant:    montant,
                    tab:        tab,
                    email:      email
                }, function(res){
                    var $msg = $('#dr-admin-msg');
                    $msg.css({padding:'10px 16px',borderRadius:'6px',
                        background: res.success ? '#e8f5e9' : '#ffebee',
                        color:      res.success ? '#2e7d32' : '#c62828',
                        border:     res.success ? '1px solid #a5d6a7' : '1px solid #ef9a9a'})
                        .html((res.data && res.data.message) || 'Terminé').show();
                    if (res.success) {
                        $('#dr-me-code, #dr-me-montant, #dr-me-email').val('');
                        setTimeout(function(){ location.reload(); }, 1600);
                    } else {
                        $all.prop('disabled', false);
                        $('#dr-me-block').text('🔒 Bloquer ce code (déjà validé)');
                        $('#dr-me-credit').text('💳 Créditer le wallet maintenant');
                        $('#dr-me-verify').text('✔️ Vérifier via SMS & créditer');
                    }
                }).fail(function(){
                    $all.prop('disabled', false);
                    $('#dr-me-block').text('🔒 Bloquer ce code (déjà validé)');
                    $('#dr-me-credit').text('💳 Créditer le wallet maintenant');
                    $('#dr-me-verify').text('✔️ Vérifier via SMS & créditer');
                    alert('Erreur réseau.');
                });
            }

            $(document).on('click', '#dr-me-block',  function(){ meSubmit('block',  $(this)); });
            $(document).on('click', '#dr-me-credit', function(){ meSubmit('credit', $(this)); });
            $(document).on('click', '#dr-me-verify', function(){ meSubmit('verify', $(this)); });

            // ── Service ON/OFF toggles ──
            $(document).on('change', '.dr-svc-check', function(){
                var $c = $(this), on = $c.is(':checked');
                $c.prop('disabled', true);
                $.post($c.data('ajax'), {
                    action: 'delicat_toggle_service',
                    nonce:  $c.data('nonce'),
                    service:$c.data('service'),
                    on:     on ? '1' : '0'
                }, function(res){
                    $c.prop('disabled', false);
                    if (res && res.success) {
                        $c.closest('label').find('.dr-svc-state')
                          .text(res.data.on ? 'ACTIF' : 'OFF')
                          .css('color', res.data.on ? '#2e7d32' : '#c62828');
                        $('#dr-admin-msg').css({padding:'8px 14px',borderRadius:'6px',
                            background:'#e8f5e9',color:'#2e7d32',border:'1px solid #a5d6a7'})
                            .html(res.data.message).show();
                    } else {
                        $c.prop('checked', !on);
                        alert((res && res.data && res.data.message) || 'Erreur.');
                    }
                }).fail(function(){
                    $c.prop('disabled', false).prop('checked', !on);
                    alert('Erreur réseau.');
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}
