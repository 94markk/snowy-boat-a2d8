<?php
defined('ABSPATH') || exit;

final class DIP_WooCommerce {
    const ENDPOINT = 'connected-accounts';

    public static function init() {
        if (!class_exists('WooCommerce')) return;
        add_action('init', [__CLASS__, 'endpoint']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'screen']);
        add_action('admin_post_dip_unlink_google', [__CLASS__, 'unlink']);
        add_action('admin_post_dip_unlink_microsoft', [__CLASS__, 'unlink_microsoft']);
        add_action('admin_post_dip_send_password_setup', [__CLASS__, 'send_password_setup']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 20);
        add_filter('woocommerce_login_redirect', [__CLASS__, 'login_redirect'], 10, 2);
        add_action('after_password_reset', [__CLASS__, 'password_confirmed'], 10, 2);
        add_action('profile_update', [__CLASS__, 'profile_updated'], 10, 2);
    }

    public static function endpoint() { add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES); }

    public static function activate() {
        if (class_exists('WooCommerce')) { self::endpoint(); flush_rewrite_rules(false); }
    }

    public static function assets() {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) return;
        wp_enqueue_script('dip-checkout-restore', DIP_URL . 'assets/checkout-restore.js', [], DIP_VERSION, true);
    }

    public static function login_redirect($redirect, $user) {
        if (!empty($_REQUEST['redirect'])) {
            $candidate = wp_validate_redirect(esc_url_raw(wp_unslash($_REQUEST['redirect'])), '');
            if ($candidate) return $candidate;
        }
        return $redirect;
    }

    public static function screen() {
        if (!is_user_logged_in()) return;
        $uid = get_current_user_id();
        $sub = (string) get_user_meta($uid, '_dglp_google_sub', true);
        $avatar = (string) get_user_meta($uid, '_dglp_google_avatar', true);
        $linked = (string) get_user_meta($uid, '_dglp_google_linked_at', true);
        echo '<section class="dip-connected-account"><h2>' . esc_html__('Compte Google', 'delicat-google-login') . '</h2>';
        if (!$sub) {
            echo '<p>' . esc_html__('Aucun compte Google n’est connecté.', 'delicat-google-login') . '</p>';
            echo DIP_Plugin::instance()->render_button(['provider' => 'google', 'link' => 1, 'text' => __('Connecter Google', 'delicat-google-login')]);
            echo '</section>';
            self::microsoft_screen($uid);
            self::device_screen($uid);
            return;
        }
        if ($avatar) echo '<img src="' . esc_url($avatar) . '" alt="" width="64" height="64" style="border-radius:50%;object-fit:cover">';
        echo '<p><strong>' . esc_html__('Statut :', 'delicat-google-login') . '</strong> ' . esc_html__('Connecté', 'delicat-google-login') . '</p>';
        if ($linked) echo '<p><strong>' . esc_html__('Depuis :', 'delicat-google-login') . '</strong> ' . esc_html(mysql2date(get_option('date_format'), $linked)) . '</p>';
        if (!self::has_password($uid) && !get_user_meta($uid, '_dip_microsoft_sub', true)) {
            echo '<div class="woocommerce-info">' . esc_html__('Définissez d’abord un mot de passe ou connectez un autre fournisseur avant de déconnecter Google afin de ne pas perdre l’accès à votre compte.', 'delicat-google-login') . '</div>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dip_send_password_setup">';
            wp_nonce_field('dip_send_password_setup_' . $uid);
            echo '<button class="button" type="submit">' . esc_html__('Envoyer le lien de création du mot de passe', 'delicat-google-login') . '</button></form>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('Déconnecter Google de ce compte ?', 'delicat-google-login')) . '\')"><input type="hidden" name="action" value="dip_unlink_google">';
            wp_nonce_field('dip_unlink_google_' . $uid);
            echo '<button class="button" type="submit">' . esc_html__('Déconnecter Google', 'delicat-google-login') . '</button></form>';
        }
        echo '</section>';
        self::microsoft_screen($uid);
        self::device_screen($uid);
    }


    private static function microsoft_screen($uid) {
        $sub = (string) get_user_meta($uid, '_dip_microsoft_sub', true);
        $linked = (string) get_user_meta($uid, '_dip_microsoft_linked_at', true);
        echo '<section class="dip-connected-account" style="margin-top:24px"><h2>' . esc_html__('Compte Microsoft', 'delicat-google-login') . '</h2>';
        if (!$sub) {
            echo '<p>' . esc_html__('Aucun compte Microsoft n’est connecté.', 'delicat-google-login') . '</p>';
            echo DIP_Plugin::instance()->render_button(['provider' => 'microsoft', 'link' => 1, 'text' => __('Connecter Microsoft', 'delicat-google-login')]);
            echo '</section>';
            return;
        }
        echo '<p><strong>' . esc_html__('Statut :', 'delicat-google-login') . '</strong> ' . esc_html__('Connecté', 'delicat-google-login') . '</p>';
        if ($linked) echo '<p><strong>' . esc_html__('Depuis :', 'delicat-google-login') . '</strong> ' . esc_html(mysql2date(get_option('date_format'), $linked)) . '</p>';
        if (!self::has_password($uid) && !get_user_meta($uid, '_dglp_google_sub', true)) {
            echo '<div class="woocommerce-info">' . esc_html__('Définissez d’abord un mot de passe avant de déconnecter votre seul fournisseur de connexion.', 'delicat-google-login') . '</div>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('Déconnecter Microsoft de ce compte ?', 'delicat-google-login')) . '\')"><input type="hidden" name="action" value="dip_unlink_microsoft">';
            wp_nonce_field('dip_unlink_microsoft_' . $uid);
            echo '<button class="button" type="submit">' . esc_html__('Déconnecter Microsoft', 'delicat-google-login') . '</button></form>';
        }
        echo '</section>';
    }

    private static function device_screen($uid) {
        if (!class_exists('DIP_Devices')) return;
        $devices = DIP_Devices::user_devices($uid);
        $current = DIP_Devices::current_hash();
        echo '<section class="dip-connected-account" style="margin-top:24px"><h2>' . esc_html__('Appareils et sessions', 'delicat-google-login') . '</h2>';
        echo '<p>' . esc_html__('Les appareils sont reconnus par une empreinte non réversible. Aucune adresse IP brute ni cookie de session n’est conservé.', 'delicat-google-login') . '</p>';
        if (!$devices) echo '<p>' . esc_html__('Aucun appareil enregistré.', 'delicat-google-login') . '</p>';
        foreach ($devices as $device) {
            $is_current = hash_equals($current, (string)$device['device_hash']);
            echo '<div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin:10px 0"><strong>' . esc_html($device['platform'] . ' · ' . $device['browser']) . '</strong>';
            if ($is_current) echo ' <span style="font-size:12px">(' . esc_html__('cet appareil', 'delicat-google-login') . ')</span>';
            if (!empty($device['trusted'])) echo ' <span style="font-size:12px">✓ ' . esc_html__('fiable', 'delicat-google-login') . '</span>';
            echo '<br><small>' . esc_html__('Dernière utilisation :', 'delicat-google-login') . ' ' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $device['last_seen'])) . '</small>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="action" value="dip_device_action"><input type="hidden" name="device_id" value="' . esc_attr($device['id']) . '">';
            wp_nonce_field('dip_device_action_' . $uid);
            echo '<button class="button" name="operation" value="' . (!empty($device['trusted']) ? 'untrust' : 'trust') . '">' . esc_html(!empty($device['trusted']) ? __('Retirer la confiance', 'delicat-google-login') : __('Marquer comme fiable', 'delicat-google-login')) . '</button>';
            if (!$is_current) echo '<button class="button" name="operation" value="forget" onclick="return confirm(\'' . esc_js(__('Oublier cet appareil ?', 'delicat-google-login')) . '\')">' . esc_html__('Oublier', 'delicat-google-login') . '</button>';
            echo '</form></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('Déconnecter toutes les autres sessions Web et App ?', 'delicat-google-login')) . '\')"><input type="hidden" name="action" value="dip_revoke_other_sessions">';
        wp_nonce_field('dip_revoke_other_sessions_' . $uid);
        echo '<button class="button" type="submit">' . esc_html__('Déconnecter les autres sessions Web + App', 'delicat-google-login') . '</button></form></section>';
    }

    private static function has_password($uid) {
        return get_user_meta($uid, '_dglp_password_managed', true) !== 'yes';
    }

    public static function password_confirmed($user, $new_pass) {
        if ($user instanceof WP_User) delete_user_meta($user->ID, '_dglp_password_managed');
    }

    public static function profile_updated($user_id, $old_user_data) {
        $user_id = absint($user_id);
        if (!$user_id || !($old_user_data instanceof WP_User)) return;
        $current = get_userdata($user_id);
        if (!$current) return;
        $old_hash = (string) $old_user_data->user_pass;
        $new_hash = (string) $current->user_pass;
        if ($old_hash !== '' && $new_hash !== '' && !hash_equals($old_hash, $new_hash)) {
            delete_user_meta($user_id, '_dglp_password_managed');
        }
    }

    public static function unlink() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        }
        if (class_exists('DIP_Access_Guard') ? !DIP_Access_Guard::can_self_service() : !is_user_logged_in()) wp_die(esc_html__('Accès refusé.', 'delicat-google-login'), '', ['response' => 403]);
        $uid = get_current_user_id();
        check_admin_referer('dip_unlink_google_' . $uid);
        if (!self::has_password($uid) && !get_user_meta($uid, '_dip_microsoft_sub', true)) wp_die(esc_html__('Créez un mot de passe ou connectez un autre fournisseur avant de déconnecter Google.', 'delicat-google-login'));
        delete_user_meta($uid, '_dglp_google_sub');
        delete_user_meta($uid, '_dglp_google_avatar');
        delete_user_meta($uid, '_dglp_google_linked_at');
        delete_user_meta($uid, '_dip_google_explicit_link_v2');
        do_action('dip_account_unlinked', $uid, 'google');
        if (class_exists('DIP_Audit')) DIP_Audit::record('account_unlinked', 'warning', $uid);
        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT)); exit;
    }


    public static function unlink_microsoft() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        }
        if (class_exists('DIP_Access_Guard') ? !DIP_Access_Guard::can_self_service() : !is_user_logged_in()) wp_die(esc_html__('Accès refusé.', 'delicat-google-login'), '', ['response' => 403]);
        $uid = get_current_user_id();
        check_admin_referer('dip_unlink_microsoft_' . $uid);
        if (!self::has_password($uid) && !get_user_meta($uid, '_dglp_google_sub', true)) {
            wp_die(esc_html__('Créez un mot de passe avant de déconnecter votre seul fournisseur.', 'delicat-google-login'));
        }
        delete_user_meta($uid, '_dip_microsoft_sub');
        delete_user_meta($uid, '_dip_microsoft_linked_at');
        do_action('dip_account_unlinked', $uid, 'microsoft');
        if (class_exists('DIP_Audit')) DIP_Audit::record('account_unlinked_microsoft', 'warning', $uid);
        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT)); exit;
    }

    public static function send_password_setup() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        }
        if (class_exists('DIP_Access_Guard') ? !DIP_Access_Guard::can_self_service() : !is_user_logged_in()) wp_die(esc_html__('Accès refusé.', 'delicat-google-login'), '', ['response' => 403]);
        $uid = get_current_user_id();
        check_admin_referer('dip_send_password_setup_' . $uid);
        $user = get_userdata($uid);
        if ($user) retrieve_password($user->user_login);
        if (class_exists('DIP_Audit')) DIP_Audit::record('password_setup_requested', 'notice', $uid);
        wp_safe_redirect(add_query_arg('dip_password_email', 'sent', wc_get_account_endpoint_url(self::ENDPOINT))); exit;
    }
}
