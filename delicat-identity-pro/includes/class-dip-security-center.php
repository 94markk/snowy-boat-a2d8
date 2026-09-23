<?php
defined('ABSPATH') || exit;

/**
 * Phase 4 Security Center: customer security score, devices, sessions and login history.
 */
final class DIP_Security_Center {
    const NONCE = 'dip_security_center_action';

    public static function init() {
        add_shortcode('delicat_security_center', [__CLASS__, 'shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 40);
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 35);
        add_action('admin_post_dip_security_device', [__CLASS__, 'handle_device']);
        add_action('admin_post_dip_security_logout_others', [__CLASS__, 'logout_others']);
        add_action('wp_login', [__CLASS__, 'record_native_login'], 20, 2);
    }

    public static function assets() {
        if (!is_user_logged_in()) return;
        $load = function_exists('is_account_page') && is_account_page();
        if (!$load && is_singular()) {
            global $post;
            $load = $post instanceof WP_Post && has_shortcode((string) $post->post_content, 'delicat_security_center');
        }
        if (!$load) return;
        wp_enqueue_style('dip-customer-dashboard', DIP_URL . 'assets/customer-dashboard.css', [], DIP_VERSION);
        wp_enqueue_script('dip-security-center-client', DIP_URL . 'assets/security-center.js', [], DIP_VERSION, true);
    }

    public static function record_native_login($user_login, $user) {
        if (!$user instanceof WP_User) return;
        // Social/passwordless/app-pairing flows deliberately fire wp_login for
        // WordPress compatibility and then publish their own Delicat method.
        // Do not relabel those events as password logins.
        if (class_exists('DIP_Account_Sync') && DIP_Account_Sync::wp_login_context() !== '') return;
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_login($user->ID);
        do_action('dip_login_success', $user->ID, ['provider'=>'password']);
        DIP_Audit::record('login_success', 'info', $user->ID, ['method' => 'password']);
    }

    public static function score($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return ['score'=>0,'items'=>[]];
        $statuses = class_exists('DIP_Connected_Accounts') ? DIP_Connected_Accounts::status($user_id) : [];
        $devices = DIP_Devices::user_devices($user_id);
        $has_provider = false;
        foreach ($statuses as $status) if (!empty($status['connected'])) $has_provider = true;
        $is_admin = user_can($user_id, 'manage_options');
        if ($is_admin) {
            // A legacy Google link is not a security benefit for an Administrator
            // until the exact immutable identity has been re-verified and approved
            // by Google Secure Mode. Other social providers are not accepted as
            // Administrator login methods by the privileged policy.
            $google_sub = (string)get_user_meta($user_id, DIP_Identity::META_SUB, true);
            $has_provider = $google_sub !== ''
                && class_exists('DIP_Privileged_Social')
                && DIP_Privileged_Social::approved($user_id, $google_sub);
        }
        $email_fingerprint = (string) get_user_meta($user_id, '_dip_verified_email_fingerprint', true);
        $current_email_fingerprint = is_email($user->user_email) ? hash('sha256', strtolower(sanitize_email($user->user_email))) : '';
        $email_verified = get_user_meta($user_id, 'dip_email_verified', true) === 'yes'
            && $email_fingerprint !== '' && $current_email_fingerprint !== ''
            && hash_equals($email_fingerprint, $current_email_fingerprint);
        $items = [
            'passkey' => [
                'ok' => class_exists('DIP_Passkeys') && DIP_Passkeys::has_passkeys($user_id),
                'label' => 'Passkey phishing-resistant enregistrée',
                'points' => 20,
            ],
            'two_factor' => [
                'ok' => class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user_id),
                'label' => 'Authentification à deux facteurs active',
                'points' => 20,
            ],
            'password' => [
                'ok' => get_user_meta($user_id, DIP_Identity::META_PASSWORD_MANAGED, true) !== 'yes',
                'label' => 'Mot de passe de secours disponible',
                'points' => 10,
            ],
            'provider' => ['ok'=>$has_provider,'label'=>$is_admin ? 'Google Secure Mode Administrateur approuvé' : 'Compte social connecté','points'=>10],
            'trusted' => ['ok'=>count(array_filter($devices, function($d){ return !empty($d['trusted']); })) > 0,'label'=>'Au moins un appareil de confiance','points'=>15],
            'email' => ['ok'=>$email_verified,'label'=>'Adresse e-mail vérifiée','points'=>15],
            'https' => ['ok'=>is_ssl(),'label'=>'Session protégée par HTTPS','points'=>10],
        ];
        $score = 0;
        foreach ($items as $item) if ($item['ok']) $score += $item['points'];
        return ['score'=>min(100,$score),'items'=>$items];
    }

    private static function event_label($type) {
        $labels = [
            'login_success'=>'Connexion réussie','new_device_login'=>'Nouvel appareil détecté',
            'account_disconnected'=>'Compte social déconnecté','login_blocked'=>'Connexion bloquée',
            'login_failed'=>'Échec de connexion','device_trusted'=>'Appareil approuvé',
            'device_forgotten'=>'Appareil supprimé','sessions_revoked'=>'Autres sessions déconnectées',
            'two_factor_enabled'=>'2FA activée','two_factor_disabled'=>'2FA désactivée',
            'two_factor_login_success'=>'Connexion 2FA réussie','two_factor_login_failed'=>'Échec de code 2FA',
            'two_factor_challenge_success'=>'Vérification 2FA réussie','two_factor_challenge_failed'=>'Échec de vérification 2FA',
            'two_factor_recovery_code_used'=>'Code de récupération utilisé','two_factor_recovery_codes_regenerated'=>'Codes de récupération renouvelés',
            'two_factor_replay_blocked'=>'Réutilisation de code 2FA bloquée','two_factor_application_password_blocked'=>'Application Password bloqué par 2FA',
            'reauth_success'=>'Identité confirmée','reauth_failed'=>'Échec de confirmation d’identité',
            'passkey_registered'=>'Passkey ajoutée','passkey_removed'=>'Passkey supprimée',
            'passkey_login_success'=>'Connexion Passkey réussie','passkey_login_failed'=>'Échec de connexion Passkey',
            'passkey_reauth_success'=>'Identité confirmée par Passkey','passkey_reauth_failed'=>'Échec de confirmation Passkey',
            'passkey_counter_replay_blocked'=>'Anomalie compteur Passkey bloquée',
            'admin_google_secure_link_authorized'=>'Google Secure Mode Admin autorisé',
            'admin_google_secure_link_revoked'=>'Google Secure Mode Admin révoqué',
            'admin_google_first_factor_verified'=>'Premier facteur Google Admin vérifié',
        ];
        return $labels[$type] ?? ucwords(str_replace('_',' ',(string)$type));
    }

    private static function user_events($user_id, $limit = 20) {
        global $wpdb;
        $limit = min(50, max(1, absint($limit)));
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . DIP_Audit::table() . ' WHERE user_id=%d ORDER BY id DESC LIMIT %d', absint($user_id), $limit), ARRAY_A);
    }

    public static function shortcode($atts = []) {
        if (class_exists('DIP_Access_Guard') ? !DIP_Access_Guard::can_self_service() : !is_user_logged_in()) {
            return '<p class="dip-security-notice">' . esc_html__('Vous devez être connecté pour ouvrir le Centre de sécurité.', 'delicat-google-login') . '</p>';
        }

        wp_enqueue_style('dip-customer-dashboard', DIP_URL . 'assets/customer-dashboard.css', [], DIP_VERSION);
        wp_enqueue_script('dip-security-center-client', DIP_URL . 'assets/security-center.js', [], DIP_VERSION, true);

        $atts = shortcode_atts(['title'=>'Centre de sécurité'], $atts, 'delicat_security_center');
        $user_id = get_current_user_id();
        $score = self::score($user_id);
        $devices = DIP_Devices::user_devices($user_id);
        $events = self::user_events($user_id);
        $current_hash = DIP_Devices::current_hash();
        $status = sanitize_key(wp_unslash($_GET['dip_security_status'] ?? ''));
        $score_value = min(100, max(0, absint($score['score'] ?? 0)));
        ob_start();
        ?>
        <section class="dip-security-center" aria-label="<?php echo esc_attr__('Centre de sécurité du compte', 'delicat-google-login'); ?>">
          <?php if ($status): ?><div class="dip-security-notice" role="status"><?php echo esc_html($status === 'sessions' ? 'Les autres sessions ont été déconnectées.' : 'Les paramètres de l’appareil ont été mis à jour.'); ?></div><?php endif; ?>
          <div class="dip-security-hero">
            <div class="dip-score dip-score-<?php echo esc_attr($score_value); ?>" role="img" aria-label="<?php echo esc_attr(sprintf(__('Score de sécurité : %d sur 100', 'delicat-google-login'), $score_value)); ?>"><span></span><strong><?php echo esc_html($score_value); ?><small>/100</small></strong></div>
            <div class="dip-security-hero-copy">
              <span class="dip-security-kicker"><?php esc_html_e('SÉCURITÉ DU COMPTE', 'delicat-google-login'); ?></span>
              <h2><?php echo esc_html($atts['title']); ?></h2>
              <p><?php esc_html_e('Contrôlez vos appareils, vos sessions Web/App et les événements importants de votre propre compte.', 'delicat-google-login'); ?></p>
              <div class="dip-actions"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-dip-confirm="<?php echo esc_attr__('Déconnecter toutes les autres sessions Web et App ?', 'delicat-google-login'); ?>"><input type="hidden" name="action" value="dip_security_logout_others"><?php wp_nonce_field(self::NONCE); ?><button type="submit" class="dip-btn primary"><?php esc_html_e('Déconnecter les autres sessions', 'delicat-google-login'); ?></button></form></div>
            </div>
          </div>

          <div class="dip-security-grid">
            <div class="dip-security-card">
              <div class="dip-security-card-title"><span class="dip-security-card-icon" aria-hidden="true">✓</span><div><h3><?php esc_html_e('Protections', 'delicat-google-login'); ?></h3><p><?php esc_html_e('État des protections de votre compte.', 'delicat-google-login'); ?></p></div></div>
              <?php foreach ($score['items'] as $item): ?><div class="dip-check"><span><?php echo esc_html($item['label']); ?></span><span class="dip-pill <?php echo $item['ok'] ? 'is-ok' : 'is-warn'; ?>"><?php echo $item['ok'] ? esc_html__('Protégé', 'delicat-google-login') : esc_html__('À améliorer', 'delicat-google-login'); ?></span></div><?php endforeach; ?>
            </div>

            <?php if (class_exists('DIP_Privileged_Social')) echo DIP_Privileged_Social::render_security_card($user_id); ?>
            <?php if (class_exists('DIP_Two_Factor')) echo DIP_Two_Factor::render_settings($user_id); ?>
            <?php if (class_exists('DIP_Passkeys')) echo DIP_Passkeys::render_settings($user_id); ?>
            <?php if (class_exists('DIP_Reauth')) echo DIP_Reauth::render_form(); ?>
            <?php if (class_exists('DIP_Security_Recommendations')) echo DIP_Security_Recommendations::render_customer($user_id); ?>

            <div class="dip-security-card">
              <div class="dip-security-card-title"><span class="dip-security-card-icon is-device" aria-hidden="true">▣</span><div><h3><?php esc_html_e('Appareils', 'delicat-google-login'); ?></h3><p><?php esc_html_e('Gérez uniquement les appareils associés à votre compte.', 'delicat-google-login'); ?></p></div></div>
              <?php if (!$devices): ?><p class="dip-security-empty"><?php esc_html_e('Aucun appareil enregistré.', 'delicat-google-login'); ?></p><?php endif; ?>
              <?php foreach ($devices as $device): $is_current = hash_equals((string)$device['device_hash'], $current_hash); ?>
                <div class="dip-device">
                  <div><strong><?php echo esc_html($device['platform'] . ' · ' . $device['browser']); ?></strong><small><?php echo $is_current ? esc_html__('Appareil actuel · ', 'delicat-google-login') : ''; ?><?php esc_html_e('Dernière activité', 'delicat-google-login'); ?> <?php echo esc_html(wp_date('d/m/Y H:i', strtotime($device['last_seen'] . ' UTC'))); ?></small></div>
                  <div class="dip-actions">
                    <?php if (!$is_current): ?>
                      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_security_device"><input type="hidden" name="device_id" value="<?php echo esc_attr((int)$device['id']); ?>"><input type="hidden" name="mode" value="<?php echo esc_attr(!empty($device['trusted'])?'untrust':'trust'); ?>"><?php wp_nonce_field(self::NONCE); ?><button type="submit" class="dip-btn"><?php echo esc_html(!empty($device['trusted']) ? __('Retirer confiance', 'delicat-google-login') : __('Faire confiance', 'delicat-google-login')); ?></button></form>
                      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-dip-confirm="<?php echo esc_attr__('Supprimer cet appareil de votre liste ?', 'delicat-google-login'); ?>"><input type="hidden" name="action" value="dip_security_device"><input type="hidden" name="device_id" value="<?php echo esc_attr((int)$device['id']); ?>"><input type="hidden" name="mode" value="forget"><?php wp_nonce_field(self::NONCE); ?><button type="submit" class="dip-btn danger"><?php esc_html_e('Supprimer', 'delicat-google-login'); ?></button></form>
                    <?php else: ?><span class="dip-pill is-ok"><?php esc_html_e('Actuel', 'delicat-google-login'); ?></span><?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="dip-security-card dip-security-history">
            <div class="dip-security-card-title"><span class="dip-security-card-icon is-history" aria-hidden="true">↺</span><div><h3><?php esc_html_e('Historique récent', 'delicat-google-login'); ?></h3><p><?php esc_html_e('Événements de sécurité associés uniquement à votre compte.', 'delicat-google-login'); ?></p></div></div>
            <?php if (!$events): ?><p class="dip-security-empty"><?php esc_html_e('Aucun événement récent.', 'delicat-google-login'); ?></p><?php endif; ?>
            <?php foreach ($events as $event): ?><div class="dip-event"><div><strong><?php echo esc_html(self::event_label($event['event_type'])); ?></strong><small><?php echo esc_html(wp_date('d/m/Y H:i', strtotime($event['created_at'] . ' UTC'))); ?></small></div><span class="dip-pill <?php echo $event['severity']==='info' ? 'is-ok' : ''; ?>"><?php echo esc_html($event['severity']); ?></span></div><?php endforeach; ?>
          </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function handle_device() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        }
        if (class_exists('DIP_Access_Guard')) { if (!DIP_Access_Guard::can_self_service()) DIP_Access_Guard::require_login(); } elseif (!is_user_logged_in()) auth_redirect();
        check_admin_referer(self::NONCE);
        $user_id = get_current_user_id();
        $device_id = absint($_POST['device_id'] ?? 0);
        $mode = sanitize_key(wp_unslash($_POST['mode'] ?? ''));
        if ($mode === 'trust') { DIP_Devices::set_trusted($user_id,$device_id,true); DIP_Audit::record('device_trusted','info',$user_id,['device_id'=>$device_id]); }
        elseif ($mode === 'untrust') { DIP_Devices::set_trusted($user_id,$device_id,false); DIP_Audit::record('device_untrusted','notice',$user_id,['device_id'=>$device_id]); }
        elseif ($mode === 'forget') { DIP_Devices::forget($user_id,$device_id); DIP_Audit::record('device_forgotten','warning',$user_id,['device_id'=>$device_id]); }
        self::redirect('device');
    }

    public static function logout_others() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        }
        if (class_exists('DIP_Access_Guard')) { if (!DIP_Access_Guard::can_self_service()) DIP_Access_Guard::require_login(); } elseif (!is_user_logged_in()) auth_redirect();
        check_admin_referer(self::NONCE);
        $user_id = get_current_user_id();
        if (class_exists('WP_Session_Tokens')) WP_Session_Tokens::get_instance($user_id)->destroy_others(wp_get_session_token());
        $mobile_revoked = class_exists('DIP_Mobile_API') ? DIP_Mobile_API::revoke_all_for_user($user_id, 'security_center_logout_others') : 0;
        DIP_Audit::record('sessions_revoked','warning',$user_id,['mobile_revoked'=>(int)$mobile_revoked]);
        self::redirect('sessions');
    }

    private static function redirect($status) {
        $target = wp_get_referer() ?: home_url('/my-account/');
        wp_safe_redirect(add_query_arg('dip_security_status', sanitize_key($status), $target));
        exit;
    }

    public static function admin_menu() {
        add_submenu_page('options-general.php','Identity Security Center','Identity Security','manage_options','dip-security-center',[__CLASS__,'admin_page']);
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) return;
        $summary = DIP_Audit::summary(30); $devices = DIP_Devices::summary(30);
        $architecture = class_exists('DIP_Security_Architecture') ? DIP_Security_Architecture::health() : ['score'=>0,'checks'=>[]];
        echo '<div class="wrap"><h1>Delicat Identity Pro — Security Center</h1><p>Vue des 30 derniers jours et état de l’architecture d’accès.</p><div class="dip-security-admin-grid">';
        foreach (['Architecture sécurité'=>$architecture['score'].'%','Événements'=>$summary['total'],'Connexions réussies'=>$summary['success'],'Connexions bloquées'=>$summary['blocked'],'Appareils actifs'=>$devices['total'],'Appareils approuvés'=>$devices['trusted']] as $label=>$value) echo '<div class="card"><h2>'.esc_html($value).'</h2><p>'.esc_html($label).'</p></div>';
        echo '</div>';
        if (!empty($architecture['checks'])) {
            echo '<div class="card dip-security-admin-card"><h2>Contrôles d’accès centralisés</h2><table class="widefat striped"><tbody>';
            foreach ((array)$architecture['checks'] as $check) echo '<tr><td>'.(!empty($check['ok'])?'✅':'⚠️').'</td><td>'.esc_html($check['label'] ?? '').'</td><td>'.(!empty($check['ok'])?'Actif':'À vérifier').'</td></tr>';
            echo '</tbody></table></div>';
        }
        if (class_exists('DIP_Security_Recommendations')) echo DIP_Security_Recommendations::render_admin();
        echo '<div class="card dip-security-admin-card"><h2>Accès client</h2><code>[delicat_security_center]</code><p>Le shortcode client expose uniquement les appareils, sessions, 2FA, recommandations et événements du compte connecté. Les contrôles globaux restent réservés aux administrateurs.</p></div></div>';
    }
}
