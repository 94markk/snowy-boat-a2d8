<?php
defined('ABSPATH') || exit;

/**
 * 6.17.5 (IDN-08): WordPress' own privacy tools (Outils › Exporter / Effacer
 * les données personnelles) now cover what Identity keeps about a customer.
 *
 * Export: e-mail verification state, age answer, consents and referral code,
 * linked Google/Microsoft accounts, passkeys, devices, app sessions, quick
 * sign-in keys and the security history.
 * Erase: app sessions (tokens), quick sign-in keys (biometric), the security
 * history, devices, app pairings and the profile fields Identity stores
 * (age answer, consents, referral code, Google picture, profile photo).
 * Sign-in methods themselves (password, passkeys, linked accounts) are kept
 * so the account still works; deleting the account removes them.
 */
final class DIP_Privacy {
    const HISTORY_PAGE = 200;

    /** Profile fields Identity writes and erases (meta key => label). */
    private static function profile_fields() {
        return [
            'dip_age_declaration' => __('Déclaration d’âge (18 ans ou plus)', 'delicat-google-login'),
            'dip_age_declared_at' => __('Date de la déclaration d’âge', 'delicat-google-login'),
            'dip_marketing_consent' => __('Accord pour les offres et nouveautés', 'delicat-google-login'),
            'dip_referral_code' => __('Code de parrainage saisi à l’inscription', 'delicat-google-login'),
            '_dglp_google_avatar' => __('Adresse de la photo Google', 'delicat-google-login'),
            '_dip_mail_confirmed_at' => __('Dernier test de réception d’e-mail', 'delicat-google-login'),
        ];
    }

    public static function init() {
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_erasers']);
    }

    public static function register_exporters($exporters) {
        $exporters = is_array($exporters) ? $exporters : [];
        $exporters['delicat-identity'] = [
            'exporter_friendly_name' => __('Delicat Identity — compte et sécurité', 'delicat-google-login'),
            'callback' => [__CLASS__, 'export'],
        ];
        return $exporters;
    }

    public static function register_erasers($erasers) {
        $erasers = is_array($erasers) ? $erasers : [];
        $erasers['delicat-identity'] = [
            'eraser_friendly_name' => __('Delicat Identity — sessions de l’application, historique de sécurité et profil', 'delicat-google-login'),
            'callback' => [__CLASS__, 'erase'],
        ];
        return $erasers;
    }

    private static function date_value($value) {
        if (is_numeric($value)) return (int) $value > 0 ? wp_date('Y-m-d H:i', (int) $value) : '';
        $ts = $value ? strtotime((string) $value . ' UTC') : false;
        return $ts ? wp_date('Y-m-d H:i', $ts) : '';
    }

    private static function item($group, $group_label, $id, array $pairs) {
        $data = [];
        foreach ($pairs as $name => $value) {
            if ($value === '' || $value === null) continue;
            $data[] = ['name' => $name, 'value' => (string) $value];
        }
        return $data ? ['group_id' => $group, 'group_label' => $group_label, 'item_id' => $id, 'data' => $data] : null;
    }

    private static function table_exists($table) {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    public static function export($email_address, $page = 1) {
        global $wpdb;
        $page = max(1, absint($page));
        $user = get_user_by('email', sanitize_email((string) $email_address));
        if (!$user) return ['data' => [], 'done' => true];
        $uid = (int) $user->ID;
        $items = [];

        if ($page === 1) {
            $account = [
                __('Adresse e-mail vérifiée', 'delicat-google-login') => class_exists('DIP_Email_Proof') && DIP_Email_Proof::is_proven($uid) ? __('Oui', 'delicat-google-login') : __('Non', 'delicat-google-login'),
                __('Vérification en deux étapes', 'delicat-google-login') => class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($uid) ? __('Active', 'delicat-google-login') : __('Inactive', 'delicat-google-login'),
            ];
            foreach (self::profile_fields() as $key => $label) {
                $value = get_user_meta($uid, $key, true);
                if (is_scalar($value) && (string) $value !== '') $account[$label] = substr($key, -3) === '_at' ? self::date_value($value) : (string) $value;
            }
            $items[] = self::item('delicat-identity-account', __('Compte Delicat Identity', 'delicat-google-login'), 'account-' . $uid, $account);

            if (class_exists('DIP_Connected_Accounts')) {
                foreach ((array) DIP_Connected_Accounts::providers() as $id => $provider) {
                    if (!is_array($provider) || empty($provider['meta_sub']) || (string) get_user_meta($uid, $provider['meta_sub'], true) === '') continue;
                    $first = class_exists('DIP_Recent_Credentials') ? get_user_meta($uid, DIP_Recent_Credentials::first_linked_key($id), true) : '';
                    $items[] = self::item('delicat-identity-providers', __('Comptes reliés', 'delicat-google-login'), 'provider-' . sanitize_key((string) $id), [
                        __('Fournisseur', 'delicat-google-login') => sanitize_text_field((string) ($provider['label'] ?? $id)),
                        __('Relié le', 'delicat-google-login') => self::date_value($first),
                        __('Dernière connexion', 'delicat-google-login') => self::date_value(get_user_meta($uid, $provider['meta_linked'] ?? '', true)),
                    ]);
                }
            }
            if (class_exists('DIP_Passkeys')) {
                foreach (DIP_Passkeys::credentials($uid) as $i => $entry) {
                    $items[] = self::item('delicat-identity-passkeys', __('Passkeys', 'delicat-google-login'), 'passkey-' . $i, [
                        __('Nom', 'delicat-google-login') => $entry['label'],
                        __('Ajoutée le', 'delicat-google-login') => self::date_value($entry['created']),
                        __('Dernière utilisation', 'delicat-google-login') => self::date_value($entry['last_used']),
                    ]);
                }
            }
            if (class_exists('DIP_Devices')) {
                foreach ((array) DIP_Devices::user_devices($uid) as $device) {
                    $items[] = self::item('delicat-identity-devices', __('Appareils', 'delicat-google-login'), 'device-' . (int) $device['id'], [
                        __('Navigateur', 'delicat-google-login') => (string) $device['browser'],
                        __('Appareil', 'delicat-google-login') => (string) $device['platform'],
                        __('Première connexion', 'delicat-google-login') => self::date_value($device['first_seen'] ?? ''),
                        __('Dernière activité', 'delicat-google-login') => self::date_value($device['last_seen'] ?? ''),
                    ]);
                }
            }
            $tokens = $wpdb->prefix . 'dip_mobile_tokens';
            if (self::table_exists($tokens)) {
                foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, device_name, platform, app_version, created_at, last_used_at, revoked_at FROM {$tokens} WHERE user_id=%d ORDER BY id DESC LIMIT 100", $uid), ARRAY_A) as $row) {
                    $items[] = self::item('delicat-identity-app', __('Sessions de l’application Delicat', 'delicat-google-login'), 'app-' . (int) $row['id'], [
                        __('Appareil', 'delicat-google-login') => (string) $row['device_name'],
                        __('Système', 'delicat-google-login') => (string) $row['platform'],
                        __('Version de l’application', 'delicat-google-login') => (string) $row['app_version'],
                        __('Ouverte le', 'delicat-google-login') => self::date_value($row['created_at']),
                        __('Dernière utilisation', 'delicat-google-login') => self::date_value($row['last_used_at']),
                        __('Fermée le', 'delicat-google-login') => self::date_value($row['revoked_at']),
                    ]);
                }
            }
            $keys = $wpdb->prefix . 'dip_mobile_biometric_keys';
            if (self::table_exists($keys)) {
                foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, label, created_at, last_used_at, revoked_at FROM {$keys} WHERE user_id=%d ORDER BY id DESC LIMIT 100", $uid), ARRAY_A) as $row) {
                    $items[] = self::item('delicat-identity-biometric', __('Connexion rapide (empreinte ou visage)', 'delicat-google-login'), 'key-' . (int) $row['id'], [
                        __('Nom', 'delicat-google-login') => (string) $row['label'],
                        __('Ajoutée le', 'delicat-google-login') => self::date_value($row['created_at']),
                        __('Dernière utilisation', 'delicat-google-login') => self::date_value($row['last_used_at']),
                        __('Retirée le', 'delicat-google-login') => self::date_value($row['revoked_at']),
                    ]);
                }
            }
        }

        // Security history, one page per call (newest first).
        $done = true;
        if (class_exists('DIP_Audit')) {
            $offset = ($page - 1) * self::HISTORY_PAGE;
            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, created_at, event_type FROM ' . DIP_Audit::table() . ' WHERE user_id=%d ORDER BY id DESC LIMIT %d OFFSET %d', $uid, self::HISTORY_PAGE, $offset), ARRAY_A);
            foreach ($rows as $row) {
                $label = class_exists('DIP_Security_Center') && method_exists('DIP_Security_Center', 'event_label') ? DIP_Security_Center::event_label($row['event_type']) : (string) $row['event_type'];
                $items[] = self::item('delicat-identity-history', __('Historique de sécurité', 'delicat-google-login'), 'event-' . (int) $row['id'], [
                    __('Événement', 'delicat-google-login') => $label,
                    __('Date', 'delicat-google-login') => self::date_value($row['created_at']),
                ]);
            }
            $done = count($rows) < self::HISTORY_PAGE;
        }
        return ['data' => array_values(array_filter($items)), 'done' => $done];
    }

    public static function erase($email_address, $page = 1) {
        global $wpdb;
        $user = get_user_by('email', sanitize_email((string) $email_address));
        if (!$user) return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        $uid = (int) $user->ID;
        $removed = 0;
        $suppress = $wpdb->suppress_errors(true);

        // App sessions first (revoked, then deleted), then quick sign-in keys.
        if (class_exists('DIP_Mobile_API')) DIP_Mobile_API::revoke_all_for_user($uid, 'privacy_erasure');
        foreach (['dip_mobile_tokens', 'dip_mobile_biometric_keys'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            if (self::table_exists($table)) $removed += (int) $wpdb->delete($table, ['user_id' => $uid], ['%d']);
        }
        if (class_exists('DIP_Devices') && method_exists('DIP_Devices', 'delete_for_user')) $removed += (int) DIP_Devices::delete_for_user($uid);
        // Staff keep their security history (who signed in, 2FA changes): the shop needs it to investigate.
        if (class_exists('DIP_Audit') && !user_can($uid, 'manage_options') && !user_can($uid, 'manage_woocommerce')) $removed += (int) $wpdb->delete(DIP_Audit::table(), ['user_id' => $uid], ['%d']);
        $wpdb->suppress_errors($suppress);

        foreach (array_keys(self::profile_fields()) as $key) {
            if (get_user_meta($uid, $key, true) !== '' && delete_user_meta($uid, $key)) $removed++;
        }
        if (class_exists('DIP_Account_Control')) {
            $photo = absint(get_user_meta($uid, DIP_Account_Control::PHOTO, true));
            if ($photo && (int) get_post_meta($photo, '_dip_avatar_owner', true) === $uid && (int) get_post_field('post_author', $photo) === $uid) {
                if (wp_delete_attachment($photo, true)) $removed++;
                delete_user_meta($uid, DIP_Account_Control::PHOTO);
            }
        }
        return [
            'items_removed' => $removed > 0,
            'items_retained' => true,
            'messages' => [__('Delicat Identity conserve les moyens de connexion (mot de passe, passkeys, comptes Google ou Microsoft reliés) pour que le compte reste utilisable : ils disparaissent avec la suppression du compte.', 'delicat-google-login')],
            'done' => true,
        ];
    }
}
