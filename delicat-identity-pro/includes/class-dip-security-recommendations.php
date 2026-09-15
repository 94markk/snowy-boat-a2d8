<?php
defined('ABSPATH') || exit;

/** Security recommendations derived from current Identity configuration/state. */
final class DIP_Security_Recommendations {
    public static function init() {}

    private static function settings() {
        $defaults = class_exists('DIP_Plugin') ? DIP_Plugin::defaults() : [];
        return wp_parse_args((array)get_option(class_exists('DIP_Plugin') ? DIP_Plugin::OPTION : 'dglp_settings', []), $defaults);
    }

    public static function customer($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        $items = [];
        if (!$user_id) return $items;
        $user = get_userdata($user_id);
        if (!$user) return $items;

        $settings = self::settings();
        if (($settings['passkeys_available'] ?? 'yes') === 'yes' && (!class_exists('DIP_Passkeys') || !DIP_Passkeys::has_passkeys($user_id))) {
            $items[] = ['priority'=>'high','title'=>'Ajouter une Passkey','detail'=>'Activez Face ID, Touch ID, biométrie Android, Windows Hello ou une clé de sécurité pour une connexion résistante au phishing sans exposer de secret réutilisable.'];
        }
        if (($settings['two_factor_available'] ?? 'yes') === 'yes' && (!class_exists('DIP_Two_Factor') || !DIP_Two_Factor::is_enabled($user_id))) {
            $items[] = ['priority'=>'high','title'=>'Activer la vérification en deux étapes','detail'=>'Ajoutez un code Authenticator et des codes de récupération pour protéger les connexions même si le mot de passe est compromis.'];
        } elseif (class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user_id)) {
            $recovery = get_user_meta($user_id, '_dip_totp_recovery_hashes_v1', true);
            if (!is_array($recovery) || count($recovery) < 2) {
                $items[] = ['priority'=>'high','title'=>'Renouveler vos codes de récupération','detail'=>'Il reste moins de deux codes de récupération 2FA. Générez-en de nouveaux et conservez-les hors ligne.'];
            }
        }

        $statuses = class_exists('DIP_Connected_Accounts') ? DIP_Connected_Accounts::status($user_id) : [];
        $linked = false;
        foreach ($statuses as $status) if (!empty($status['connected'])) { $linked = true; break; }
        if (user_can($user_id, 'manage_options')) {
            $google_sub = (string)get_user_meta($user_id, DIP_Identity::META_SUB, true);
            $approved_admin_google = $google_sub !== '' && class_exists('DIP_Privileged_Social') && DIP_Privileged_Social::approved($user_id, $google_sub);
            if ($google_sub !== '' && !$approved_admin_google) {
                $items[] = ['priority'=>'high','title'=>'Vérifier votre Google Administrateur','detail'=>'Votre compte Google est lié mais ne peut pas ouvrir une session Administrateur tant que vous ne le revalidez pas depuis Google Secure Mode après TOTP + confirmation récente.'];
            }
            $linked = $approved_admin_google;
        }
        if (!$linked) $items[] = ['priority'=>'medium','title'=>'Ajouter une méthode de connexion secondaire','detail'=>user_can($user_id, 'manage_options') ? 'Pour un Administrateur, privilégiez une Passkey et Google Secure Mode explicitement vérifié. Microsoft et les liaisons sociales génériques ne doivent pas ouvrir le back-office.' : 'Reliez Google ou Microsoft afin de disposer d’une méthode de récupération/connexion supplémentaire.'];

        $devices = class_exists('DIP_Devices') ? DIP_Devices::user_devices($user_id) : [];
        $trusted = false;
        foreach ((array)$devices as $device) if (!empty($device['trusted'])) { $trusted = true; break; }
        if (!$trusted) $items[] = ['priority'=>'medium','title'=>'Approuver votre appareil principal','detail'=>'Marquez votre appareil personnel comme fiable depuis le Centre de sécurité.'];

        $fingerprint = (string)get_user_meta($user_id, '_dip_verified_email_fingerprint', true);
        $current = is_email($user->user_email) ? hash('sha256', strtolower(sanitize_email($user->user_email))) : '';
        $verified = get_user_meta($user_id, 'dip_email_verified', true) === 'yes' && $fingerprint !== '' && $current !== '' && hash_equals($fingerprint, $current);
        if (!$verified) $items[] = ['priority'=>'high','title'=>'Vérifier votre adresse e-mail','detail'=>'La vérification de l’adresse protège les récupérations de compte et les changements d’identité.'];

        if (!DIP_Request::is_secure()) $items[] = ['priority'=>'high','title'=>'Connexion HTTPS requise','detail'=>'N’utilisez pas les fonctions sensibles tant que la connexion n’est pas protégée par HTTPS.'];
        return $items;
    }

    public static function admin() {
        $s = self::settings();
        $items = [];
        $admin_ids = get_users(['role'=>'administrator','fields'=>'ID','number'=>-1]);
        $admins_without_2fa = 0;
        $admins_without_passkey = 0;
        $admins_low_recovery = 0;
        $admins_with_app_passwords = 0;
        $admins_google_linked_unapproved = 0;
        foreach ((array)$admin_ids as $admin_id) {
            $admin_id = absint($admin_id);
            $enabled = class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($admin_id);
            if (!class_exists('DIP_Passkeys') || !DIP_Passkeys::has_passkeys($admin_id)) $admins_without_passkey++;
            if (!$enabled) {
                $admins_without_2fa++;
            } else {
                $recovery = get_user_meta($admin_id, '_dip_totp_recovery_hashes_v1', true);
                if (!is_array($recovery) || count($recovery) < 2) $admins_low_recovery++;
            }
            if (class_exists('WP_Application_Passwords') && method_exists('WP_Application_Passwords', 'get_user_application_passwords')) {
                $app_passwords = WP_Application_Passwords::get_user_application_passwords($admin_id);
                if (is_array($app_passwords) && $app_passwords) $admins_with_app_passwords++;
            }
            $google_sub = (string)get_user_meta($admin_id, DIP_Identity::META_SUB, true);
            if ($google_sub !== '' && (!class_exists('DIP_Privileged_Social') || !DIP_Privileged_Social::approved($admin_id, $google_sub))) {
                $admins_google_linked_unapproved++;
            }
        }
        if (($s['admin_google_secure_mode'] ?? 'yes') !== 'yes') {
            $items[] = ['priority'=>'critical','title'=>'Activer Google Secure Mode pour les Administrateurs','detail'=>'La connexion Google d’un Administrateur doit rester limitée à une identité Google explicitement approuvée et protégée par TOTP avant toute session WordPress.'];
        }
        if ($admins_google_linked_unapproved > 0) {
            $items[] = ['priority'=>'high','title'=>'Approuver ou retirer les anciens liens Google Administrateur','detail'=>sprintf('%d compte(s) Administrateur(s) ont Google lié sans autorisation Secure Mode. Ils ne peuvent pas se connecter par Google tant que l’identité n’est pas approuvée depuis une session Administrateur récemment authentifiée.', $admins_google_linked_unapproved)];
        }
        if (($s['passkeys_available'] ?? 'yes') === 'yes' && $admins_without_passkey > 0) {
            $items[] = ['priority'=>'high','title'=>'Enregistrer une Passkey pour chaque administrateur','detail'=>sprintf('%d compte(s) administrateur(s) n’ont pas encore de Passkey. Ajoutez au moins une Passkey vérifiée par utilisateur avant d’envisager une politique obligatoire.', $admins_without_passkey)];
        }
        if ($admins_without_2fa > 0) {
            $items[] = ['priority'=>'critical','title'=>'Activer 2FA sur tous les administrateurs','detail'=>sprintf('%d compte(s) administrateur(s) n’ont pas encore activé 2FA. Enrôlez et sauvegardez leurs codes de récupération avant toute obligation globale.', $admins_without_2fa)];
        }
        if ($admins_low_recovery > 0) {
            $items[] = ['priority'=>'critical','title'=>'Renouveler les codes de récupération administrateur','detail'=>sprintf('%d administrateur(s) protégé(s) par 2FA ont moins de deux codes de récupération disponibles.', $admins_low_recovery)];
        }
        if ($admins_with_app_passwords > 0) {
            $items[] = ['priority'=>'high','title'=>'Auditer les Application Passwords WordPress','detail'=>sprintf('%d compte(s) administrateur(s) possèdent des Application Passwords. Delicat les bloque par défaut lorsqu’un compte active 2FA; supprimez les anciens identifiants API inutiles.', $admins_with_app_passwords)];
        }
        if (($s['strict_rest_firewall'] ?? 'yes') !== 'yes') $items[] = ['priority'=>'critical','title'=>'Activer le pare-feu REST strict','detail'=>'Les routes Identity inconnues doivent être refusées par défaut.'];
        if (!class_exists('DIP_Crypto') || !DIP_Crypto::is_available()) $items[] = ['priority'=>'critical','title'=>'Activer un moteur de chiffrement serveur','detail'=>'Sodium ou OpenSSL est nécessaire pour protéger les secrets OAuth et TOTP au repos.'];
        if (!DIP_Request::is_secure()) $items[] = ['priority'=>'critical','title'=>'Forcer HTTPS','detail'=>'Toutes les pages de compte et d’administration doivent être servies exclusivement en HTTPS.'];
        if (defined('WP_DEBUG') && WP_DEBUG) $items[] = ['priority'=>'high','title'=>'Désactiver WP_DEBUG en production','detail'=>'Les erreurs détaillées peuvent révéler des chemins internes et des informations techniques.'];
        if (($s['lockout_enabled'] ?? 'yes') !== 'yes') $items[] = ['priority'=>'high','title'=>'Réactiver le verrouillage anti-bruteforce','detail'=>'La protection contre les tentatives répétées doit rester active sur les connexions publiques.'];
        if (($s['trusted_devices_enabled'] ?? 'yes') !== 'yes') $items[] = ['priority'=>'medium','title'=>'Activer la gestion des appareils','detail'=>'Le suivi des appareils améliore la détection et la révocation des sessions inhabituelles.'];
        if (($s['new_device_email'] ?? 'yes') !== 'yes') $items[] = ['priority'=>'medium','title'=>'Activer les alertes de nouvel appareil','detail'=>'Prévenez le client lorsqu’un nouvel appareil se connecte à son compte.'];
        if (($s['protected_page_slugs'] ?? '') === '') $items[] = ['priority'=>'high','title'=>'Définir les pages privées','detail'=>'Ajoutez Wallet, historique, PIN et autres pages client à la liste des pages exigeant une connexion.'];
        if (!(defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT)) $items[] = ['priority'=>'medium','title'=>'Désactiver l’éditeur de fichiers WordPress','detail'=>'Définissez DISALLOW_FILE_EDIT à true en production pour empêcher la modification de thèmes/plugins depuis wp-admin si un compte administrateur est compromis.'];
        if (DIP_Request::is_secure() && !(defined('FORCE_SSL_ADMIN') && FORCE_SSL_ADMIN)) $items[] = ['priority'=>'medium','title'=>'Forcer HTTPS dans wp-admin','detail'=>'Le site utilise HTTPS; activez FORCE_SSL_ADMIN après vérification de votre proxy/Cloudflare pour maintenir l’administration en HTTPS.'];
        return $items;
    }

    public static function render_customer($user_id = 0) {
        $items = self::customer($user_id);
        if (!$items) return '<div class="dip-security-card dip-recommendations"><div class="dip-security-card-title"><span class="dip-security-card-icon" aria-hidden="true">✓</span><div><h3>'.esc_html__('Recommandations', 'delicat-google-login').'</h3><p>'.esc_html__('Aucune action importante recommandée pour le moment.', 'delicat-google-login').'</p></div></div></div>';
        ob_start(); ?>
        <div class="dip-security-card dip-recommendations"><div class="dip-security-card-title"><span class="dip-security-card-icon" aria-hidden="true">!</span><div><h3><?php esc_html_e('Recommandations de sécurité', 'delicat-google-login'); ?></h3><p><?php esc_html_e('Actions personnalisées pour renforcer votre propre compte.', 'delicat-google-login'); ?></p></div></div>
        <?php foreach ($items as $item): ?><div class="dip-recommendation is-<?php echo esc_attr($item['priority']); ?>"><strong><?php echo esc_html($item['title']); ?></strong><p><?php echo esc_html($item['detail']); ?></p></div><?php endforeach; ?></div>
        <?php return ob_get_clean();
    }

    public static function render_admin() {
        $items = self::admin();
        if (!$items) return '<div class="card"><h2>✅ Recommandations</h2><p>Aucune recommandation critique détectée.</p></div>';
        ob_start(); ?><div class="card"><h2>Recommandations prioritaires</h2><table class="widefat striped"><tbody><?php foreach ($items as $item): ?><tr><td><strong><?php echo esc_html(strtoupper($item['priority'])); ?></strong></td><td><strong><?php echo esc_html($item['title']); ?></strong><br><span><?php echo esc_html($item['detail']); ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php return ob_get_clean();
    }
}
