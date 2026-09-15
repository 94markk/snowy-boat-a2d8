<?php
defined('ABSPATH') || exit;

/**
 * Local diagnostics assistant. It never transmits site, customer, credential,
 * token, IP or log data to an external AI service.
 */
final class DIP_Assistant {
    const REPORT_OPTION = 'dip_assistant_last_report';

    public static function analyze(array $settings) {
        $health = DIP_Operations::health($settings);
        $compat = DIP_Operations::compatibility();
        $audit = DIP_Audit::summary(30);
        $integrity = get_option('dip_last_integrity_scan', []);
        $issues = [];
        $recommendations = [];
        $score = 100;

        foreach ($health as $check) {
            if (($check['status'] ?? '') === 'healthy') continue;
            $score -= 12;
            $issues[] = self::issue(
                'health_' . sanitize_key($check['id'] ?? 'unknown'),
                'critical',
                ($check['label'] ?? 'Contrôle système') . ' nécessite une intervention',
                (string) ($check['detail'] ?? ''),
                self::health_fix((string) ($check['id'] ?? ''))
            );
        }

        if (($settings['security_log_enabled'] ?? 'yes') !== 'yes') {
            $score -= 10;
            $issues[] = self::issue('audit_disabled', 'warning', 'Journal de sécurité désactivé', 'Les erreurs de connexion et les blocages ne seront pas traçables.', 'Activez le journal de sécurité et conservez environ 30 jours d’événements.');
        }
        if (($settings['lockout_enabled'] ?? 'yes') !== 'yes') {
            $score -= 12;
            $issues[] = self::issue('lockout_disabled', 'warning', 'Verrouillage temporaire désactivé', 'Les tentatives répétées ne déclenchent aucune protection locale.', 'Activez le verrouillage temporaire avec 7 échecs sur 15 minutes et 20 minutes de blocage.');
        }
        if ((int) ($settings['rate_limit'] ?? 10) > 15) {
            $score -= 5;
            $recommendations[] = self::issue('high_rate_limit', 'notice', 'Limite de tentatives permissive', 'La limite actuelle autorise de nombreuses demandes OAuth dans une courte période.', 'Utilisez 10 tentatives sur 10 minutes comme point de départ.');
        }
        if (($settings['link_existing_email'] ?? 'yes') === 'yes') {
            $recommendations[] = self::issue('email_linking', 'notice', 'Liaison Google par email activée', 'Cette option facilite la migration Nextend pour les clients existants.', 'Gardez la protection des comptes privilégiés activée et vérifiez les groupes d’emails dupliqués avant la bascule finale.');
        }
        if (($settings['block_privileged_social_login'] ?? 'yes') !== 'yes') {
            $recommendations[] = self::issue('privileged_social_legacy_flag', 'notice', 'Ancien indicateur de blocage à normaliser', 'La valeur enregistrée est ancienne, mais 6.9.0 impose désormais le blocage des rôles privilégiés non-admin directement dans le moteur.', 'Enregistrez les réglages une fois pour normaliser la valeur stockée.');
        }
        if (($settings['admin_google_secure_mode'] ?? 'yes') !== 'yes') {
            $score -= 20;
            $issues[] = self::issue('admin_google_secure_mode', 'critical', 'Google Secure Mode Administrateur désactivé', 'Une politique dédiée est requise pour empêcher une simple correspondance d’e-mail Google de devenir une session Administrateur.', 'Activez Google Secure Mode Administrateur et approuvez l’identité Google depuis une session Administrateur avec TOTP actif.');
        }
        if (($settings['adaptive_risk_blocking'] ?? 'no') === 'yes' && empty($_SERVER['HTTP_CF_RAY'])) {
            $recommendations[] = self::issue('adaptive_without_edge', 'warning', 'Blocage adaptatif sans proxy de confiance détecté', 'Les signaux pays et réseau peuvent être incomplets.', 'Commencez en mode observation, puis activez le blocage après validation des événements sur votre hébergement.');
        }
        $cf_trusted = (defined('DIP_TRUST_CLOUDFLARE_HEADERS') && DIP_TRUST_CLOUDFLARE_HEADERS)
            || (defined('DIP_TRUST_CLOUDFLARE_CONNECTING_IP') && DIP_TRUST_CLOUDFLARE_CONNECTING_IP);
        if (($settings['country_policy_mode'] ?? 'off') !== 'off' && !$cf_trusted) {
            $score -= 8;
            $issues[] = self::issue('country_untrusted', 'warning', 'Politique pays active sans confiance Cloudflare explicite', 'Delicat Identity ignore volontairement CF-IPCountry tant que la confiance proxy n’est pas activée côté serveur.', 'Protégez l’origine puis définissez DIP_TRUST_CLOUDFLARE_HEADERS=true dans wp-config.php, ou désactivez la politique pays.');
        }
        if (($settings['mobile_api_enabled'] ?? 'no') === 'yes' && !DIP_Request::is_secure()) {
            $score -= 25;
            $issues[] = self::issue('mobile_without_https', 'critical', 'API mobile active sans HTTPS', 'Les jetons mobiles ne doivent jamais circuler sur une connexion non chiffrée.', 'Désactivez immédiatement l’API mobile ou activez HTTPS valide avant tout test.');
        }
        if (($settings['mobile_api_enabled'] ?? 'no') === 'yes' && empty($settings['client_id'])) {
            $issues[] = ['level'=>'critical','title'=>'Audience serveur Google manquante','message'=>'Renseignez le Client ID Web / serveur utilisé par l’application native.'];
        }
        if (($settings['mobile_api_enabled'] ?? 'no') === 'yes' && empty($settings['android_client_id']) && empty($settings['ios_client_id'])) {
            $score -= 8;
            $issues[] = self::issue('mobile_audience_missing', 'warning', 'API mobile sans Client ID natif', 'Aucune audience Android ou iOS n’est configurée.', 'Ajoutez les Client IDs Android/iOS correspondant exactement aux applications publiées.');
        }
        if (($settings['microsoft_enabled'] ?? 'no') === 'yes' && ($settings['microsoft_link_existing_email'] ?? 'no') === 'yes') {
            $recommendations[] = self::issue('microsoft_email_linking', 'warning', 'Liaison Microsoft automatique par email', 'Les emails Microsoft organisationnels peuvent changer ou utiliser des alias.', 'Préférez la liaison manuelle depuis un compte déjà connecté.');
        }
        if (($audit['critical'] ?? 0) > 0) {
            $score -= min(20, (int) $audit['critical'] * 2);
            $issues[] = self::issue('critical_events', 'warning', 'Événements critiques récents', (int) $audit['critical'] . ' événement(s) critique(s) ont été observés sur 30 jours.', 'Consultez les derniers événements, identifiez leur code, puis testez le correctif sur staging.');
        }
        if (($audit['blocked'] ?? 0) > 20) {
            $recommendations[] = self::issue('many_blocks', 'notice', 'Volume élevé de tentatives bloquées', (int) $audit['blocked'] . ' tentatives ont été bloquées sur 30 jours.', 'Vérifiez si elles proviennent de clients légitimes avant de resserrer davantage les règles.');
        }
        if (is_array($integrity) && $integrity) {
            if (!empty($integrity['duplicate_identities'])) {
                $score -= 20;
                $issues[] = self::issue('duplicate_identities', 'critical', 'Identités sociales dupliquées', (int) $integrity['duplicate_identities'] . ' identité(s) sont liées à plusieurs utilisateurs.', 'Ne fusionnez rien automatiquement. Examinez chaque groupe et conservez le WordPress user ID qui porte les commandes et le portefeuille.');
            }
            if (!empty($integrity['orphaned_references'])) {
                $score -= 10;
                $issues[] = self::issue('orphaned_references', 'warning', 'Références sociales orphelines', (int) $integrity['orphaned_references'] . ' référence(s) ne pointent plus vers un utilisateur valide.', 'Exportez le rapport, sauvegardez la base, puis nettoyez uniquement les métadonnées confirmées comme orphelines.');
            }
        }
        if (!$compat['WooCommerce']) {
            $recommendations[] = self::issue('woocommerce_missing', 'notice', 'WooCommerce non détecté', 'Les intégrations checkout et Mon compte seront inactives.', 'Aucune action nécessaire si ce site n’utilise pas WooCommerce.');
        }
        if ($compat['Nextend Social Login']) {
            $recommendations[] = self::issue('nextend_present', 'notice', 'Nextend encore détecté', 'La coexistence est utile pendant les tests mais peut afficher plusieurs boutons.', 'Désactivez seulement le fournisseur Google de Nextend après avoir validé plusieurs clients existants.');
        }

        $score = max(0, min(100, $score));
        $report = [
            'generated_at' => time(),
            'score' => $score,
            'status' => $score >= 85 ? 'healthy' : ($score >= 65 ? 'review' : 'critical'),
            'issues' => $issues,
            'recommendations' => $recommendations,
            'summary' => [
                'health_checks' => count($health),
                'critical_events_30d' => (int) ($audit['critical'] ?? 0),
                'blocked_events_30d' => (int) ($audit['blocked'] ?? 0),
                'nextend_detected' => !empty($compat['Nextend Social Login']),
                'woocommerce_detected' => !empty($compat['WooCommerce']),
            ],
        ];
        update_option(self::REPORT_OPTION, $report, false);
        return $report;
    }

    public static function last_report() {
        $report = get_option(self::REPORT_OPTION, []);
        return is_array($report) ? $report : [];
    }

    public static function explain_event($event_type) {
        $map = [
            'login_rate_limited' => ['Trop de tentatives ont été envoyées.', 'Attendez la fin de la fenêtre, puis vérifiez la limite configurée et les caches/proxies.'],
            'login_temporarily_locked' => ['Le navigateur a dépassé le seuil d’échecs.', 'Laissez expirer le verrouillage ou vérifiez qu’un cache ne rejoue pas la callback OAuth.'],
            'login_browser_mismatch' => ['La callback a été ouverte dans un autre navigateur ou contexte.', 'Démarrez et terminez la connexion dans le même navigateur, sans navigateur intégré différent.'],
            'login_invalid_state' => ['L’état OAuth n’est plus valide ou a déjà été consommé.', 'Vérifiez les caches, l’horloge serveur, les cookies et les redirections intermédiaires.'],
            'login_missing_callback_data' => ['Google/Microsoft n’a pas renvoyé le code attendu.', 'Vérifiez l’URI de redirection exacte et les paramètres de l’application OAuth.'],
            'login_privileged_social_login_blocked' => ['Un compte privilégié a tenté une connexion sociale non autorisée.', 'Pour un Administrateur, utilisez Google Secure Mode explicitement approuvé + TOTP, un Passkey, ou une connexion WordPress protégée. Les autres rôles privilégiés restent bloqués.'],
            'login_admin_google_not_authorized' => ['Une identité Google liée a tenté d’ouvrir une session Administrateur sans approbation Secure Mode.', 'Connectez-vous par Passkey ou mot de passe + 2FA, puis approuvez Google dans le Centre de sécurité.'],
            'login_admin_google_two_factor_required' => ['Google Admin a été refusé car TOTP 2FA n’est pas actif.', 'Activez TOTP et conservez les codes de récupération avant d’autoriser Google Secure Mode.'],
            'login_duplicate_google_identity' => ['Une même identité Google est associée à plusieurs utilisateurs.', 'Arrêtez la migration et examinez les WordPress user IDs concernés avant toute correction.'],
        ];
        return $map[$event_type] ?? ['Événement de sécurité enregistré par Delicat Identity.', 'Consultez son contexte, la santé système et les changements récents avant de modifier la configuration.'];
    }

    public static function support_bundle(array $settings) {
        $safe = [
            'plugin_version' => defined('DIP_VERSION') ? DIP_VERSION : '',
            'generated_at_utc' => gmdate('c'),
            'site_https' => DIP_Request::is_secure(),
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : null,
            'health' => DIP_Operations::health($settings),
            'compatibility' => DIP_Operations::compatibility(),
            'assistant_report' => self::last_report(),
            'settings_flags' => [
                'google_enabled' => ($settings['enabled'] ?? 'no') === 'yes',
                'google_client_id_present' => !empty($settings['client_id']),
                'google_secret_present' => !empty($settings['client_secret']),
                'microsoft_enabled' => ($settings['microsoft_enabled'] ?? 'no') === 'yes',
                'microsoft_client_id_present' => !empty($settings['microsoft_client_id']),
                'microsoft_secret_present' => !empty($settings['microsoft_client_secret']),
                'mobile_api_enabled' => ($settings['mobile_api_enabled'] ?? 'no') === 'yes',
                'security_log_enabled' => ($settings['security_log_enabled'] ?? 'no') === 'yes',
                'lockout_enabled' => ($settings['lockout_enabled'] ?? 'no') === 'yes',
                'admin_google_secure_mode' => ($settings['admin_google_secure_mode'] ?? 'yes') === 'yes',
            ],
        ];
        return $safe;
    }

    private static function issue($id, $severity, $title, $explanation, $fix) {
        return [
            'id' => sanitize_key($id),
            'severity' => in_array($severity, ['notice','warning','critical'], true) ? $severity : 'notice',
            'title' => sanitize_text_field($title),
            'explanation' => sanitize_text_field($explanation),
            'fix' => sanitize_text_field($fix),
        ];
    }

    private static function health_fix($id) {
        $map = [
            'https' => 'Installez un certificat TLS valide et forcez HTTPS dans WordPress avant d’activer OAuth ou l’API mobile.',
            'php' => 'Mettez PHP à niveau vers une version supportée par WordPress et testez la boutique sur staging.',
            'wordpress' => 'Mettez WordPress à jour après sauvegarde complète et test de compatibilité.',
            'woocommerce' => 'Activez WooCommerce si vous utilisez les boutons checkout et Mon compte.',
            'openssl' => 'Demandez à l’hébergeur d’activer OpenSSL; la validation de signature en dépend.',
            'json' => 'Demandez à l’hébergeur d’activer l’extension JSON de PHP.',
            'rest' => 'Vérifiez les permaliens et les plugins de sécurité qui peuvent bloquer l’API REST.',
            'cron_audit' => 'Utilisez le bouton de réparation des tâches planifiées.',
            'cron_devices' => 'Utilisez le bouton de réparation des tâches planifiées.',
            'google' => 'Configurez le Client ID, le Client Secret et l’URI de redirection exacte dans Google Cloud.',
            'microsoft' => 'Complétez ou désactivez Microsoft Login jusqu’à ce que les identifiants soient valides.',
            'audit_table' => 'Désactivez puis réactivez le plugin sur staging afin de recréer la table audit.',
            'device_table' => 'Désactivez puis réactivez le plugin sur staging afin de recréer la table appareils.',
        ];
        return $map[$id] ?? 'Examinez ce contrôle sur staging avant toute modification en production.';
    }
}
