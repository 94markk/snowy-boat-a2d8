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
        add_action('admin_post_dip_security_verify_email', [__CLASS__, 'send_email_verification']);
        add_action('wp_login', [__CLASS__, 'record_native_login'], 20, 2);
        // Customer self-service (own mailbox only); not a sensitive change.
        add_filter('dip_self_service_actions', [__CLASS__, 'self_service_actions']);
    }

    public static function self_service_actions($actions) {
        $actions = (array) $actions;
        $actions[] = 'dip_security_verify_email';
        return $actions;
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

    /** $devices: the account's DIP_Devices::user_devices() rows when the caller already has them. */
    public static function score($user_id, $devices = null) {
        $user = get_userdata($user_id);
        if (!$user) return ['score'=>0,'items'=>[]];
        $statuses = class_exists('DIP_Connected_Accounts') ? DIP_Connected_Accounts::status($user_id) : [];
        if (!is_array($devices)) $devices = DIP_Devices::user_devices($user_id);
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

    /** Customer-facing French label of a security event. */
    private static function event_label($type) {
        $type = sanitize_key((string) $type);
        $labels = [
            'login_success'=>'Connexion réussie','login_failed'=>'Échec de connexion','login_blocked'=>'Connexion bloquée',
            'login_session_redelivered'=>'Session de connexion rétablie','new_device_login'=>'Nouvel appareil détecté',
            'device_trusted'=>'Appareil marqué de confiance','device_untrusted'=>'Confiance retirée à un appareil',
            'device_forgotten'=>'Appareil supprimé de la liste','sessions_revoked'=>'Autres sessions déconnectées',
            'other_sessions_revoked'=>'Autres sessions déconnectées','auth_cookie_blocked_by_account_policy'=>'Session refusée par la politique du compte',
            'user_registered'=>'Compte créé','custom_registration'=>'Compte créé','customer_created'=>'Compte créé avec Google ou Microsoft',
            'custom_registration_pending_verification'=>'Compte créé : vérification de l’e-mail en attente',
            'native_registration_pending_verification'=>'Compte créé : vérification de l’e-mail en attente',
            'external_registration_verification_required'=>'Vérification de l’adresse e-mail demandée',
            'registration_email_verified'=>'Adresse e-mail vérifiée','email_verification_requested'=>'Lien de vérification de l’e-mail envoyé',
            'verification_email_resend_requested'=>'Nouvel e-mail de vérification demandé',
            'registration_verification_email_failed'=>'Échec d’envoi de l’e-mail de vérification',
            'verified_email_mismatch'=>'Adresse e-mail différente de celle vérifiée',
            'account_email_changed_verification_required'=>'Adresse e-mail modifiée : vérification demandée',
            'pre_verification_credentials_reset'=>'Accès réinitialisés après la vérification de l’e-mail',
            'account_approved'=>'Compte validé','account_pending_approval'=>'Compte en attente de validation',
            'profile_updated'=>'Informations du compte modifiées','profile_sensitive_change_blocked_reauth'=>'Modification bloquée : confirmation d’identité requise',
            'password_changed'=>'Mot de passe modifié','password_setup_requested'=>'Lien de création de mot de passe envoyé',
            'password_reset_mobile_sessions_revoked'=>'Mot de passe modifié : sessions de l’application fermées',
            'account_password_changed_mobile_revoked'=>'Mot de passe modifié : sessions de l’application fermées',
            'account_linked'=>'Compte Google ou Microsoft relié','provider_link_completed'=>'Compte Google ou Microsoft relié',
            'pending_provider_link_promoted'=>'Compte Google ou Microsoft relié après vérification',
            'pending_provider_link_dropped'=>'Liaison Google ou Microsoft en attente annulée',
            'provider_link_pending_email_proof'=>'Liaison en attente de la vérification de l’e-mail',
            'unproven_provider_link_removed'=>'Liaison non vérifiée retirée','account_disconnected'=>'Compte Google ou Microsoft déconnecté',
            'unverified_account_adopted_by_provider'=>'Compte rattaché à Google ou Microsoft','unverified_account_merge_refused'=>'Rattachement d’un compte non vérifié refusé',
            'google_non_authoritative_email_requires_local_proof'=>'Google : confirmation de l’adresse e-mail demandée',
            'google_provider_email_changed_not_synced'=>'Adresse Google différente : non synchronisée',
            'nextend_continuity_skipped_disconnected'=>'Ancienne liaison Google ignorée (compte déconnecté)',
            'headless_google_ticket_consumed'=>'Connexion Google sur la boutique','headless_google_browser_recovered'=>'Connexion Google sur la boutique rétablie',
            'headless_google_customer_blocked'=>'Connexion Google sur la boutique refusée','headless_google_unproven_session_refused'=>'Connexion Google sur la boutique refusée',
            'headless_google_subject_conflict'=>'Conflit d’identité Google bloqué','headless_google_subject_replaced'=>'Identité Google mise à jour',
            'magic_link_login'=>'Connexion par lien e-mail','magic_link_requested'=>'Lien de connexion demandé','magic_link_email_failed'=>'Échec d’envoi du lien de connexion',
            'passwordless_otp_login'=>'Connexion par code e-mail','passwordless_otp_requested'=>'Code de connexion demandé',
            'passwordless_otp_failed'=>'Code e-mail incorrect','passwordless_otp_verify_throttled'=>'Trop de codes e-mail essayés',
            'passwordless_otp_email_failed'=>'Échec d’envoi du code e-mail',
            'passwordless_privileged_blocked'=>'Connexion par e-mail refusée (compte protégé)','passwordless_privileged_request_refused'=>'Connexion par e-mail refusée (compte protégé)',
            'two_factor_enabled'=>'Vérification en deux étapes activée','two_factor_disabled'=>'Vérification en deux étapes désactivée',
            'two_factor_enrollment_started'=>'Activation de la 2FA commencée','two_factor_enrollment_failed'=>'Échec d’activation de la 2FA',
            'two_factor_login_success'=>'Code 2FA validé à la connexion','two_factor_login_failed'=>'Code 2FA incorrect',
            'two_factor_challenge_started'=>'Code 2FA demandé','two_factor_challenge_success'=>'Connexion validée par la 2FA',
            'two_factor_challenge_failed'=>'Code 2FA incorrect','two_factor_challenge_init_failed'=>'Vérification 2FA indisponible',
            'two_factor_recovery_code_used'=>'Code de récupération utilisé','two_factor_recovery_codes_regenerated'=>'Codes de récupération renouvelés',
            'two_factor_replay_blocked'=>'Réutilisation d’un code 2FA bloquée','two_factor_concurrent_attempt_blocked'=>'Tentative 2FA simultanée bloquée',
            'two_factor_repeated_failures'=>'Codes 2FA incorrects répétés','two_factor_application_password_blocked'=>'Mot de passe d’application bloqué par la 2FA',
            'two_factor_auth_cookie_blocked'=>'Session sans vérification 2FA bloquée','two_factor_secret_unreadable'=>'Clé 2FA illisible : contactez le support',
            'two_factor_admin_reset'=>'2FA réinitialisée par un administrateur','two_factor_cli_reset'=>'2FA réinitialisée par le support',
            'reauth_success'=>'Identité confirmée','reauth_failed'=>'Échec de confirmation d’identité',
            'reauth_email_code_sent'=>'Code de confirmation envoyé par e-mail','reauth_email_code_failed'=>'Code de confirmation incorrect',
            'reauth_rate_limited'=>'Trop de confirmations essayées','reauth_two_factor_rate_limited'=>'Trop de codes 2FA essayés',
            'reauth_failures_limit'=>'Confirmations bloquées pour aujourd’hui','sensitive_action_reauth_required'=>'Confirmation d’identité demandée',
            'passkey_registered'=>'Passkey ajoutée','passkey_removed'=>'Passkey supprimée',
            'passkey_login_success'=>'Connexion par Passkey','passkey_login_failed'=>'Échec de connexion par Passkey',
            'passkey_login_rate_limited'=>'Trop de tentatives Passkey','passkey_registration_proof_failed'=>'Échec d’ajout de Passkey',
            'passkey_reauth_success'=>'Identité confirmée par Passkey','passkey_reauth_failed'=>'Échec de confirmation par Passkey',
            'passkey_counter_replay_blocked'=>'Anomalie de Passkey bloquée','passkeys_cli_reset'=>'Passkeys réinitialisées par le support',
            'mobile_passkey_login'=>'Connexion par Passkey (application)','mobile_passkey_login_failed'=>'Échec de connexion par Passkey (application)',
            'mobile_passkey_registered'=>'Passkey ajoutée depuis l’application','mobile_passkey_removed'=>'Passkey supprimée depuis l’application',
            'mobile_passkey_origin_refused'=>'Passkey refusée : application non reconnue',
            'mobile_biometric_enrolled'=>'Connexion rapide activée (application)','mobile_biometric_login'=>'Connexion par empreinte ou visage (application)',
            'mobile_biometric_removed'=>'Connexion rapide désactivée (application)','mobile_biometric_proof_failed'=>'Échec de connexion rapide (application)',
            'mobile_biometric_context_mismatch'=>'Connexion rapide refusée : appareil différent',
            'mobile_reauth'=>'Identité confirmée (application)','mobile_reauth_failed'=>'Échec de confirmation d’identité (application)',
            'mobile_session_issued'=>'Connexion à l’application','mobile_sessions_revoked'=>'Sessions de l’application fermées',
            'mobile_session_revoked_from_web'=>'Session de l’application fermée depuis le site',
            'mobile_credentials_erased'=>'Passkeys et connexion rapide de l’application effacées',
            'mobile_access_device_mismatch'=>'Accès de l’application refusé : appareil différent','mobile_identity_route_refused'=>'Accès de l’application refusé',
            'mobile_google_token_rejected'=>'Connexion Google refusée (application)','mobile_rate_limited'=>'Trop de tentatives depuis l’application',
            'mobile_two_factor_required'=>'Code 2FA demandé (application)','mobile_two_factor_success'=>'Code 2FA validé (application)',
            'mobile_two_factor_failed'=>'Code 2FA incorrect (application)','mobile_two_factor_rate_limited'=>'Trop de codes 2FA (application)',
            'app_pairing_approved'=>'Connexion par QR code approuvée depuis l’application','app_pairing_consumed'=>'Connexion par QR code depuis l’application',
            'app_pairing_blocked_stale_session'=>'Connexion par QR code refusée : session trop ancienne',
            'app_pairing_blocked_stale_2fa_session'=>'Connexion par QR code refusée : 2FA à confirmer',
            'admin_google_secure_link_authorized'=>'Google Secure Mode Administrateur autorisé',
            'admin_google_secure_link_revoked'=>'Google Secure Mode Administrateur révoqué',
            'admin_google_first_factor_verified'=>'Premier facteur Google Administrateur vérifié',
            'privileged_google_nextend_continuity_granted'=>'Ancienne liaison Google Administrateur conservée',
            'woocommerce_past_orders_sync_failed'=>'Rattachement des commandes passées à réessayer',
        ];
        if (isset($labels[$type])) return $labels[$type];
        // Families recorded with a dynamic suffix.
        if (preg_match('/_two_factor_(required|success|failed|rate_limited)$/', $type, $m)) return $labels['mobile_two_factor_' . $m[1]];
        if (preg_match('/^(rest|ajax|admin_action|self_service_action)_/', $type) && substr($type, -7) === '_denied') return 'Accès refusé par la protection du compte';
        if (strpos($type, 'login_') === 0) return 'Connexion refusée';
        if (substr($type, -7) === '_failed') return 'Échec d’une opération de sécurité';
        return 'Événement de sécurité';
    }

    /** Severity pill of the history: label and class. */
    private static function severity_pill($severity) {
        $map = [
            'info'=>['Info', 'is-sev-info'],
            'notice'=>['À noter', 'is-sev-notice'],
            'warning'=>['Attention', 'is-sev-warning'],
            'critical'=>['Critique', 'is-sev-critical'],
        ];
        return $map[(string) $severity] ?? $map['info'];
    }

    /** Short detail of an event (sign-in method, new device), from its sanitized context. */
    private static function event_detail($type, $context) {
        $ctx = json_decode((string) $context, true);
        if (!is_array($ctx)) return '';
        if ($type === 'new_device_login') {
            $parts = array_filter([sanitize_text_field((string) ($ctx['platform'] ?? '')), sanitize_text_field((string) ($ctx['browser'] ?? ''))]);
            return implode(' · ', $parts);
        }
        $methods = [
            'password'=>'Mot de passe','google'=>'Google','google_social'=>'Google','microsoft'=>'Microsoft','microsoft_social'=>'Microsoft',
            'mobile_google'=>'Google · application','mobile_passkey'=>'Passkey · application','mobile_biometric'=>'Empreinte ou visage · application',
            'passkey'=>'Passkey','email_otp'=>'Code e-mail','magic_link'=>'Lien e-mail','delicat_app_pairing'=>'QR code',
        ];
        $raw = sanitize_key((string) ($ctx['provider'] ?? ($ctx['method'] ?? '')));
        return $raw !== '' && isset($methods[$raw]) ? $methods[$raw] : '';
    }

    /**
     * Events shown in the customer history. Delivery receipts, risk scoring,
     * app token issues (every silent renewal; an app sign-in already records
     * login_success) and WooCommerce saves without a change (older rows
     * without fields) are not security events for the customer; neither are
     * the administrator's own maintenance operations.
     */
    private static function user_events($user_id, $limit = 25) {
        global $wpdb;
        $limit = min(50, max(1, absint($limit)));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, created_at, event_type, severity, context FROM ' . DIP_Audit::table() . " WHERE user_id=%d
             AND event_type NOT IN ('unified_email_sent','unified_email_failed','adaptive_risk_assessed','headless_google_ticket_created','woocommerce_session_sync_failed','registration_notification_failed','mobile_session_issued')
             AND NOT (event_type='profile_updated' AND (context IS NULL OR context=''))
             ORDER BY id DESC LIMIT %d",
            absint($user_id), $limit + 15
        ), ARRAY_A);
        $admin_ops = '/^(performance_|analytics_|security_export|security_log_|assistant_|migration_|automatic_migration_|maintenance_|integrity_|ui_system_|assetlinks_|email_studio_|standalone_email_studio_|identity_email_settings_|social_login_maintenance_|two_factor_admin_reset_performed)|_settings_updated$/';
        $out = [];
        foreach ((array) $rows as $row) {
            if (preg_match($admin_ops, (string) $row['event_type'])) continue;
            $out[] = $row;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** Consecutive identical events (same type and detail) become one line « ×N ». */
    private static function group_events(array $events) {
        $groups = [];
        foreach ($events as $event) {
            $type = (string) $event['event_type'];
            $detail = self::event_detail($type, $event['context'] ?? '');
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last]['type'] === $type && $groups[$last]['detail'] === $detail && $groups[$last]['severity'] === (string) $event['severity']) {
                $groups[$last]['count']++;
                continue;
            }
            $groups[] = ['type'=>$type, 'detail'=>$detail, 'severity'=>(string) $event['severity'], 'count'=>1, 'latest'=>(string) $event['created_at']];
        }
        return $groups;
    }

    private static function mask_email($email) {
        $email = (string) $email;
        $at = strpos($email, '@');
        if ($at === false || $at < 1) return $email;
        return substr($email, 0, 1) . str_repeat('•', max(2, min(6, $at - 1))) . substr($email, $at);
    }

    private static function status_notice($status) {
        $messages = [
            'sessions' => ['Les autres sessions Web et App ont été déconnectées.', 'success'],
            'device' => ['Les paramètres de l’appareil ont été mis à jour.', 'success'],
            'device_trusted' => ['Appareil marqué comme appareil de confiance.', 'success'],
            'device_untrusted' => ['Cet appareil n’est plus un appareil de confiance.', 'success'],
            'device_forgotten' => ['Appareil supprimé de votre liste. S’il se reconnecte, il sera signalé comme nouvel appareil. Pour fermer ses sessions ouvertes, utilisez « Déconnecter les autres sessions ».', 'success'],
            'device_missing' => ['Cet appareil ne figure plus dans votre liste : la page a été actualisée.', 'info'],
            'email_sent' => [sprintf('Un lien de vérification vient d’être envoyé à %s. Il expire dans 24 heures.', self::mask_email(wp_get_current_user()->user_email)), 'success'],
            'email_wait' => ['Un lien vient déjà d’être envoyé. Vérifiez votre boîte de réception (et les indésirables) avant d’en demander un autre.', 'info'],
            'email_failed' => ['L’e-mail n’a pas pu être envoyé. Réessayez dans un instant.', 'error'],
            'email_verified' => ['Votre adresse e-mail est déjà vérifiée.', 'info'],
        ];
        return $messages[$status] ?? null;
    }

    /** Small scoped styles for the elements of this screen (tokens of customer-dashboard.css). */
    private static function styles() {
        static $printed = false;
        if ($printed) return '';
        $printed = true;
        return '<style id="dip-security-center-css">'
            . '.dip-security-center .dip-security-notice.is-info{border-color:var(--dip-line,#e2e7f1);background:var(--dip-card-2,#f7f8fc);color:var(--dip-ink-2,#3a4563)}'
            . '.dip-security-center .dip-device-name{display:flex;flex-wrap:wrap;align-items:center;gap:6px 8px}.dip-security-center .dip-device-name strong{display:inline}'
            . '.dip-security-center .dip-device-name .dip-pill{min-height:22px;padding:2px 9px;font-size:12px}'
            . '.dip-security-center .dip-pill.is-sev-info{background:var(--dip-card-2,#f7f8fc);color:var(--dip-ink-2,#3a4563)}'
            . '.dip-security-center .dip-pill.is-sev-notice{background:var(--dip-accent-soft,#eef2ff);color:var(--dip-accent-ink,#2a44b6)}'
            . '.dip-security-center .dip-pill.is-sev-warning{background:var(--dip-warn-soft,#fff1e3);color:var(--dip-warn-ink,#934507)}'
            . '.dip-security-center .dip-pill.is-sev-critical{background:var(--dip-danger-soft,#fdeef1);color:var(--dip-danger-ink,#b3263c)}'
            . '.dip-security-center .dip-event-meta{display:flex;flex-wrap:wrap;gap:4px 10px;margin-top:3px;color:var(--dip-muted,#596480);font-size:13px}'
            . '.dip-security-center .dip-event-meta small{margin:0}.dip-security-center .dip-event-count{font-weight:800;color:var(--dip-ink-2,#3a4563)}'
            . '.dip-security-center .dip-sc-more{border-top:1px solid var(--dip-line,#e2e7f1)}'
            . '.dip-security-center .dip-sc-more>summary{display:flex;align-items:center;gap:6px;min-height:44px;color:var(--dip-accent-ink,#2a44b6);font-size:14px;font-weight:750;cursor:pointer;list-style:none}'
            . '.dip-security-center .dip-sc-more>summary::-webkit-details-marker{display:none}.dip-security-center .dip-sc-more>summary::after{content:"";width:7px;height:7px;margin-top:-3px;border:solid currentColor;border-width:0 2px 2px 0;transform:rotate(45deg)}'
            . '.dip-security-center .dip-sc-more[open]>summary::after{margin-top:3px;transform:rotate(-135deg)}.dip-security-center .dip-sc-more[open]>summary{border-bottom:1px solid var(--dip-line,#e2e7f1)}'
            . '.dip-security-center .dip-sc-more>.dip-device:first-of-type,.dip-security-center .dip-sc-more>.dip-event:first-of-type{border-top:0}'
            . '.dip-security-center .dip-rec-action{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 0}.dip-security-center .dip-rec-action form{margin:0}.dip-security-center a.dip-btn{text-decoration:none!important}'
            . '.dip-security-center .dip-sc-confirm{display:block;max-width:34em;margin:6px 0 0;color:var(--dip-warn-ink,#934507);font-size:13px;font-weight:700;line-height:1.45}'
            . '.dip-security-center .dip-btn.is-confirming{border:1px solid color-mix(in srgb,var(--dip-danger-ink,#b3263c) 40%,transparent);background:var(--dip-danger-soft,#fdeef1);color:var(--dip-danger-ink,#b3263c);box-shadow:none}'
            . '.dip-security-center .dip-btn[aria-busy="true"]{opacity:.8;cursor:progress}'
            . '.dip-security-center .dip-btn[aria-busy="true"]::before{content:"";flex:0 0 14px;width:14px;height:14px;margin-right:8px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:dip-sc-spin .8s linear infinite}'
            . '@keyframes dip-sc-spin{to{transform:rotate(360deg)}}@media (prefers-reduced-motion:reduce){.dip-security-center .dip-btn[aria-busy="true"]::before{animation:none}}'
            . '.dip-security-center .dip-btn{transition:border-color .15s ease,background-color .15s ease}'
            // Phones: an event keeps its short level pill on the right (half the height).
            . '@media (max-width:520px){.dip-security-center .dip-event{flex-direction:row;align-items:flex-start;gap:10px}.dip-security-center .dip-event>.dip-pill{margin-top:1px}}'
            . '</style>';
    }

    private static function form_open($action, $confirm = '', $confirm_label = '') {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-dip-sc-form';
        if ($confirm !== '') $html .= ' data-dip-confirm="' . esc_attr($confirm) . '" data-dip-confirm-label="' . esc_attr($confirm_label) . '"';
        return $html . '><input type="hidden" name="action" value="' . esc_attr($action) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false);
    }

    private static function render_device(array $device, $current_hash) {
        $is_current = $current_hash !== '' && hash_equals((string) $device['device_hash'], $current_hash);
        $trusted = !empty($device['trusted']);
        $id = (int) $device['id'];
        $seen = strtotime((string) $device['last_seen'] . ' UTC');
        ob_start(); ?>
        <div class="dip-device<?php echo $is_current ? ' is-current' : ''; ?>">
          <div>
            <div class="dip-device-name"><strong><?php echo esc_html($device['platform'] . ' · ' . $device['browser']); ?></strong><?php if ($is_current): ?><span class="dip-pill"><?php esc_html_e('Cet appareil', 'delicat-google-login'); ?></span><?php endif; ?><?php if ($trusted): ?><span class="dip-pill is-ok"><?php esc_html_e('De confiance', 'delicat-google-login'); ?></span><?php endif; ?></div>
            <small><?php esc_html_e('Dernière activité', 'delicat-google-login'); ?> <?php echo esc_html($seen ? wp_date('d/m/Y H:i', $seen) : '—'); ?></small>
          </div>
          <div class="dip-actions">
            <?php echo self::form_open('dip_security_device'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in form_open ?><input type="hidden" name="device_id" value="<?php echo esc_attr($id); ?>"><input type="hidden" name="mode" value="<?php echo esc_attr($trusted ? 'untrust' : 'trust'); ?>"><button type="submit" class="dip-btn"><?php echo esc_html($trusted ? __('Retirer la confiance', 'delicat-google-login') : __('Faire confiance', 'delicat-google-login')); ?></button></form>
            <?php if (!$is_current): ?>
            <?php echo self::form_open('dip_security_device', __('Supprimer cet appareil de votre liste ? Cela ne ferme pas ses sessions : utilisez aussi « Déconnecter les autres sessions » si vous ne le reconnaissez pas.', 'delicat-google-login'), __('Oui, supprimer', 'delicat-google-login')); // phpcs:ignore WordPress.Security.EscapeOutput ?><input type="hidden" name="device_id" value="<?php echo esc_attr($id); ?>"><input type="hidden" name="mode" value="forget"><button type="submit" class="dip-btn danger"><?php esc_html_e('Supprimer', 'delicat-google-login'); ?></button></form>
            <?php endif; ?>
          </div>
        </div>
        <?php return ob_get_clean();
    }

    private static function render_event(array $group) {
        list($sev_label, $sev_class) = self::severity_pill($group['severity']);
        $latest = strtotime($group['latest'] . ' UTC');
        ob_start(); ?>
        <div class="dip-event">
          <div><strong><?php echo esc_html(self::event_label($group['type'])); ?></strong>
            <div class="dip-event-meta"><small><?php echo esc_html($latest ? wp_date('d/m/Y H:i', $latest) : '—'); ?></small><?php if ($group['detail'] !== ''): ?><small><?php echo esc_html($group['detail']); ?></small><?php endif; ?><?php if ($group['count'] > 1): ?><small class="dip-event-count"><?php echo esc_html(sprintf(__('%d fois', 'delicat-google-login'), (int) $group['count'])); ?></small><?php endif; ?></div>
          </div>
          <span class="dip-pill <?php echo esc_attr($sev_class); ?>"><?php echo esc_html($sev_label); ?></span>
        </div>
        <?php return ob_get_clean();
    }

    public static function shortcode($atts = []) {
        if (class_exists('DIP_Access_Guard') ? !DIP_Access_Guard::can_self_service() : !is_user_logged_in()) {
            return '<p class="dip-security-notice">' . esc_html__('Vous devez être connecté pour ouvrir le Centre de sécurité.', 'delicat-google-login') . '</p>';
        }

        wp_enqueue_style('dip-customer-dashboard', DIP_URL . 'assets/customer-dashboard.css', [], DIP_VERSION);
        wp_enqueue_script('dip-security-center-client', DIP_URL . 'assets/security-center.js', [], DIP_VERSION, true);

        $atts = shortcode_atts(['title'=>'Centre de sécurité'], $atts, 'delicat_security_center');
        $user_id = get_current_user_id();
        // One device query for the score, the recommendations and the list.
        $devices = (array) DIP_Devices::user_devices($user_id);
        $score = self::score($user_id, $devices);
        $groups = self::group_events(self::user_events($user_id));
        $current_hash = (string) DIP_Devices::current_hash();
        $status = sanitize_key(wp_unslash($_GET['dip_security_status'] ?? ''));
        $notice = $status !== '' ? self::status_notice($status) : null;
        $score_value = min(100, max(0, absint($score['score'] ?? 0)));
        $device_visible = 5;
        $event_visible = 8;
        ob_start();
        echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS
        ?>
        <section class="dip-security-center" aria-label="<?php echo esc_attr__('Centre de sécurité du compte', 'delicat-google-login'); ?>">
          <?php if ($notice): ?><div class="dip-security-notice<?php echo $notice[1] === 'error' ? ' is-error' : ($notice[1] === 'info' ? ' is-info' : ' is-success'); ?>" role="<?php echo $notice[1] === 'error' ? 'alert' : 'status'; ?>"><?php echo esc_html($notice[0]); ?></div><?php endif; ?>
          <div class="dip-security-hero">
            <div class="dip-score dip-score-<?php echo esc_attr($score_value - ($score_value % 5)); ?>" role="img" aria-label="<?php echo esc_attr(sprintf(__('Score de sécurité : %d sur 100', 'delicat-google-login'), $score_value)); ?>"><span></span><strong><?php echo esc_html($score_value); ?><small>/100</small></strong></div>
            <div class="dip-security-hero-copy">
              <span class="dip-security-kicker"><?php esc_html_e('SÉCURITÉ DU COMPTE', 'delicat-google-login'); ?></span>
              <h2><?php echo esc_html($atts['title']); ?></h2>
              <p><?php esc_html_e('Contrôlez vos appareils, vos sessions Web/App et les événements importants de votre propre compte.', 'delicat-google-login'); ?></p>
              <div class="dip-actions"><?php echo self::form_open('dip_security_logout_others', __('Déconnecter toutes les autres sessions Web et App ? Cet appareil reste connecté.', 'delicat-google-login'), __('Oui, déconnecter', 'delicat-google-login')); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="dip-btn primary"><?php esc_html_e('Déconnecter les autres sessions', 'delicat-google-login'); ?></button></form></div>
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
            <?php if (class_exists('DIP_Security_Recommendations')) echo DIP_Security_Recommendations::render_customer($user_id, $devices); ?>

            <div class="dip-security-card" id="dip-security-devices">
              <div class="dip-security-card-title"><span class="dip-security-card-icon is-device" aria-hidden="true">▣</span><div><h3><?php esc_html_e('Appareils', 'delicat-google-login'); ?></h3><p><?php esc_html_e('Navigateurs qui se sont connectés à votre compte. Marquez vos appareils personnels comme appareils de confiance et supprimez ceux que vous ne reconnaissez pas.', 'delicat-google-login'); ?></p></div></div>
              <?php if (!$devices): ?><p class="dip-security-empty"><?php esc_html_e('Aucun appareil enregistré.', 'delicat-google-login'); ?></p><?php endif; ?>
              <?php foreach (array_slice($devices, 0, $device_visible) as $device) echo self::render_device($device, $current_hash); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_device ?>
              <?php if (count($devices) > $device_visible): ?>
              <details class="dip-sc-more"><summary><?php echo esc_html(sprintf(_n('Voir %d autre appareil', 'Voir les %d autres appareils', count($devices) - $device_visible, 'delicat-google-login'), count($devices) - $device_visible)); ?></summary>
                <?php foreach (array_slice($devices, $device_visible) as $device) echo self::render_device($device, $current_hash); // phpcs:ignore WordPress.Security.EscapeOutput ?>
              </details>
              <?php endif; ?>
            </div>
          </div>

          <div class="dip-security-card dip-security-history">
            <div class="dip-security-card-title"><span class="dip-security-card-icon is-history" aria-hidden="true">↺</span><div><h3><?php esc_html_e('Historique récent', 'delicat-google-login'); ?></h3><p><?php esc_html_e('Connexions et changements de sécurité de votre compte. Un événement que vous ne reconnaissez pas ? Déconnectez les autres sessions et changez votre mot de passe.', 'delicat-google-login'); ?></p></div></div>
            <?php if (!$groups): ?><p class="dip-security-empty"><?php esc_html_e('Aucun événement récent.', 'delicat-google-login'); ?></p><?php endif; ?>
            <?php foreach (array_slice($groups, 0, $event_visible) as $group) echo self::render_event($group); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_event ?>
            <?php if (count($groups) > $event_visible): ?>
            <details class="dip-sc-more"><summary><?php esc_html_e('Voir plus d’événements', 'delicat-google-login'); ?></summary>
              <?php foreach (array_slice($groups, $event_visible) as $group) echo self::render_event($group); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </details>
            <?php endif; ?>
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
        if (!in_array($mode, ['trust', 'untrust', 'forget'], true)) self::redirect('');
        // Only a device of this account, still listed (stale page, other tab,
        // device already removed): nothing is audited or reported otherwise.
        $owned = false;
        if ($device_id) {
            foreach ((array) DIP_Devices::user_devices($user_id) as $device) {
                if ((int) $device['id'] === $device_id) { $owned = true; break; }
            }
        }
        if (!$owned) self::redirect('device_missing');
        if ($mode === 'trust') {
            if (DIP_Devices::set_trusted($user_id, $device_id, true) === false) self::redirect('device_missing');
            DIP_Audit::record('device_trusted', 'info', $user_id, ['device_id'=>$device_id]);
            self::redirect('device_trusted');
        }
        if ($mode === 'untrust') {
            if (DIP_Devices::set_trusted($user_id, $device_id, false) === false) self::redirect('device_missing');
            DIP_Audit::record('device_untrusted', 'notice', $user_id, ['device_id'=>$device_id]);
            self::redirect('device_untrusted');
        }
        if (!DIP_Devices::forget($user_id, $device_id)) self::redirect('device_missing');
        DIP_Audit::record('device_forgotten', 'warning', $user_id, ['device_id'=>$device_id]);
        self::redirect('device_forgotten');
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

    /** Is the account's current e-mail address proven (same rule as score())? */
    private static function email_verified($user_id) {
        $user = get_userdata($user_id);
        if (!$user || !is_email($user->user_email)) return false;
        $fingerprint = (string) get_user_meta($user_id, '_dip_verified_email_fingerprint', true);
        return get_user_meta($user_id, 'dip_email_verified', true) === 'yes'
            && $fingerprint !== '' && hash_equals($fingerprint, hash('sha256', strtolower(sanitize_email($user->user_email))));
    }

    /** Can this account ask for a verification link from the Security Center? */
    public static function can_request_email_verification($user_id) {
        $user_id = absint($user_id);
        return $user_id > 0
            && class_exists('DIP_Passwordless_Registration') && method_exists('DIP_Passwordless_Registration', 'send_email_verification')
            && class_exists('DIP_Account_Sync') && DIP_Account_Sync::is_customer_account($user_id)
            && !self::email_verified($user_id);
    }

    /**
     * Verification link for the account's current address (legacy accounts and
     * changed addresses had no way to prove it). It goes to that mailbox only;
     * at most one every two minutes and four an hour.
     */
    public static function send_email_verification() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        }
        if (class_exists('DIP_Access_Guard')) { if (!DIP_Access_Guard::can_self_service()) DIP_Access_Guard::require_login(); } elseif (!is_user_logged_in()) auth_redirect();
        check_admin_referer(self::NONCE);
        $user_id = get_current_user_id();
        if (self::email_verified($user_id)) self::redirect('email_verified');
        if (!self::can_request_email_verification($user_id)) self::redirect('email_failed');
        $cooldown = 'dip_sc_verify_cd_' . $user_id;
        if (get_transient($cooldown)) self::redirect('email_wait');
        $hourly = class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'counter_hit') ? (int) DIP_Lockout::counter_hit('dip_sc_verify_h_' . $user_id, HOUR_IN_SECONDS) : 0;
        if ($hourly > 4) self::redirect('email_wait');
        set_transient($cooldown, 1, 2 * MINUTE_IN_SECONDS);
        $user = wp_get_current_user();
        $sent = (bool) DIP_Passwordless_Registration::send_email_verification($user_id, $user->user_email);
        if (!$sent) delete_transient($cooldown);
        DIP_Audit::record('email_verification_requested', 'notice', $user_id, ['sent'=>$sent ? 1 : 0]);
        self::redirect($sent ? 'email_sent' : 'email_failed');
    }

    /** Back to the page of the action, without the one-shot messages of earlier actions. */
    private static function redirect($status) {
        $target = wp_get_referer() ?: home_url('/my-account/');
        $target = remove_query_arg(['dip_security_status', 'dip_reauth_status', 'dip_reauth_return'], $target);
        if ($status !== '') $target = add_query_arg('dip_security_status', sanitize_key($status), $target);
        wp_safe_redirect($target);
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
