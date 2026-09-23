<?php
defined('ABSPATH') || exit;

final class DIP_Policy {
    const META_PENDING = '_dip_identity_pending_approval';

    public static function country() {
        $country = '';
        $trust_cf = (defined('DIP_TRUST_CLOUDFLARE_HEADERS') && DIP_TRUST_CLOUDFLARE_HEADERS)
            || (defined('DIP_TRUST_CLOUDFLARE_CONNECTING_IP') && DIP_TRUST_CLOUDFLARE_CONNECTING_IP);
        if ($trust_cf && !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            $candidate = strtoupper(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY'])));
            if (preg_match('/^[A-Z]{2}$/', $candidate) && $candidate !== 'XX' && $candidate !== 'T1') $country = $candidate;
        }
        $country = strtoupper(sanitize_text_field((string) apply_filters('dip_identity_country', $country)));
        return preg_match('/^[A-Z]{2}$/', $country) ? $country : '';
    }

    public static function enforce_profile(array $profile, array $settings) {
        $email = strtolower(sanitize_email($profile['email'] ?? ''));
        if (!$email || strpos($email, '@') === false) return new WP_Error('policy_email_invalid', 'Adresse e-mail invalide.');
        $domain = substr(strrchr($email, '@'), 1);
        $blocked = self::csv($settings['blocked_email_domains'] ?? '');
        $allowed = self::csv($settings['allowed_email_domains'] ?? '');
        if ($blocked && in_array($domain, $blocked, true)) return new WP_Error('policy_domain_blocked', 'Ce domaine e-mail n’est pas autorisé.');
        if ($allowed && !in_array($domain, $allowed, true)) return new WP_Error('policy_domain_not_allowed', 'Ce domaine e-mail ne figure pas dans la liste autorisée.');

        $mode = $settings['country_policy_mode'] ?? 'off';
        $country = self::country();
        $countries = array_map('strtoupper', self::csv($settings['country_codes'] ?? ''));
        if ($mode !== 'off') {
            if (!$country && ($settings['country_header_required'] ?? 'no') === 'yes') return new WP_Error('policy_country_unknown', 'Le pays de connexion ne peut pas être vérifié.');
            if ($country && $mode === 'allow' && !in_array($country, $countries, true)) return new WP_Error('policy_country_not_allowed', 'Connexion non autorisée depuis ce pays.');
            if ($country && $mode === 'deny' && in_array($country, $countries, true)) return new WP_Error('policy_country_blocked', 'Connexion bloquée depuis ce pays.');
        }
        return true;
    }

    public static function assess($user_id, array $profile, array $settings) {
        $score = 0; $reasons = [];
        if (!self::country()) { $score += 10; $reasons[] = 'country_unknown'; }
        if (empty($_SERVER['HTTP_USER_AGENT'])) { $score += 25; $reasons[] = 'user_agent_missing'; }
        if (class_exists('DIP_Devices') && !DIP_Devices::is_known($user_id)) { $score += 25; $reasons[] = 'new_device'; }
        if (($profile['provider'] ?? 'google') !== 'google') { $score += 5; $reasons[] = 'secondary_provider'; }
        DIP_Audit::record('adaptive_risk_assessed', $score >= 50 ? 'warning' : 'info', $user_id, ['score'=>$score,'reasons'=>$reasons,'country'=>self::country() ?: 'unknown']);
        $threshold = min(100, max(10, absint($settings['risk_block_threshold'] ?? 70)));
        if (($settings['adaptive_risk_blocking'] ?? 'no') === 'yes' && $score >= $threshold) {
            return new WP_Error('policy_risk_blocked', 'Cette connexion nécessite une vérification supplémentaire. Contactez le support.');
        }
        return $score;
    }

    public static function is_pending($user_id) {
        return get_user_meta(absint($user_id), self::META_PENDING, true) === 'yes';
    }

    public static function mark_pending($user_id, array $profile = []) {
        $user_id = absint($user_id);
        if (!$user_id || !get_userdata($user_id)) return false;

        $was_pending = self::is_pending($user_id);
        update_user_meta($user_id, self::META_PENDING, 'yes');
        update_user_meta($user_id, '_dip_identity_pending_since', time());

        // Suspension must invalidate sessions that were issued before the
        // account became pending. The authentication guard blocks new sessions;
        // this closes the already-authenticated browser/mobile window as well.
        if (class_exists('WP_Session_Tokens')) {
            WP_Session_Tokens::get_instance($user_id)->destroy_all();
        }
        if (class_exists('DIP_Mobile_API') && method_exists('DIP_Mobile_API', 'revoke_all_for_user')) {
            DIP_Mobile_API::revoke_all_for_user($user_id, 'account_pending');
        }

        // Avoid duplicate admin mail when a profile save repeats an already
        // pending state. Newly created social accounts still produce one alert.
        if (!$was_pending) {
            $admin = get_option('admin_email');
            if (is_email($admin)) {
                $pending_user = get_userdata(absint($user_id));
                $pending_name = $pending_user instanceof WP_User ? trim((string)$pending_user->display_name) : '';
                $pending_email = $pending_user instanceof WP_User ? sanitize_email((string)$pending_user->user_email) : '';
                $identity = trim($pending_name . ($pending_email ? ' — ' . $pending_email : ''));
                $message = 'Un compte Delicat Identity attend votre approbation.' . ($identity ? ' ' . $identity . '.' : '');
                $profile_url = add_query_arg('user_id', absint($user_id), admin_url('user-edit.php'));
                if (class_exists('DIP_Email_Hub')) DIP_Email_Hub::send_admin_alert('[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] Nouveau compte en attente', 'Compte en attente d’approbation', $message, $profile_url, 'Voir le profil client');
                else wp_mail($admin, '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] Nouveau compte en attente', $message);
            }
        }
        if (class_exists('DIP_Audit')) {
            DIP_Audit::record('account_pending_approval', 'notice', $user_id, ['provider'=>sanitize_key($profile['provider'] ?? '')]);
        }
        do_action('dip_account_suspended', $user_id, $profile);
        return true;
    }

    public static function approve($user_id) {
        delete_user_meta(absint($user_id), self::META_PENDING);
        delete_user_meta(absint($user_id), '_dip_identity_pending_since');
        DIP_Audit::record('account_approved', 'info', absint($user_id));
    }

    private static function csv($value) {
        $items = preg_split('/[\s,;]+/', strtolower((string) $value));
        return array_values(array_unique(array_filter(array_map('trim', $items))));
    }
}
