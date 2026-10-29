<?php
defined('ABSPATH') || exit;

final class DIP_WooCommerce {
    const ENDPOINT = 'connected-accounts';

    /** @var array<int,bool> users whose own password was confirmed in this request */
    private static $password_confirmed = [];

    public static function init() {
        // Password bookkeeping applies to every reset path, with or without WooCommerce.
        add_action('after_password_reset', [__CLASS__, 'password_confirmed'], 10, 2);
        add_action('wp_set_password', [__CLASS__, 'password_set'], 10, 3);
        add_action('profile_update', [__CLASS__, 'profile_updated'], 10, 2);
        if (!class_exists('WooCommerce')) return;
        add_action('init', [__CLASS__, 'endpoint']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'screen']);
        add_action('admin_post_dip_unlink_google', [__CLASS__, 'unlink']);
        add_action('admin_post_dip_unlink_microsoft', [__CLASS__, 'unlink_microsoft']);
        add_action('admin_post_dip_send_password_setup', [__CLASS__, 'send_password_setup']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 20);
        add_filter('woocommerce_login_redirect', [__CLASS__, 'login_redirect'], 10, 2);
    }

    public static function endpoint() { add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES); }

    public static function activate() {
        if (class_exists('WooCommerce')) { self::endpoint(); flush_rewrite_rules(false); }
    }

    public static function assets() {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) return;
        // The Checkout block keeps its own field state; the restore helper targets the classic form.
        if (function_exists('has_block') && function_exists('wc_get_page_id') && has_block('woocommerce/checkout', wc_get_page_id('checkout'))) return;
        wp_enqueue_script('dip-checkout-restore', DIP_URL . 'assets/checkout-restore.js', [], DIP_VERSION, true);
    }

    public static function login_redirect($redirect, $user) {
        if (!empty($_REQUEST['redirect']) && is_string($_REQUEST['redirect'])) {
            $candidate = wp_validate_redirect(esc_url_raw(wp_unslash($_REQUEST['redirect'])), '');
            if ($candidate) return $candidate;
        }
        return $redirect;
    }

    /** Identity Center « Comptes liés » tab, where linked accounts are managed now. */
    private static function accounts_url(array $args = []) {
        $base = function_exists('wc_get_account_endpoint_url') && class_exists('DIP_Customer_Dashboard')
            ? wc_get_account_endpoint_url(DIP_Customer_Dashboard::ENDPOINT)
            : home_url('/');
        return add_query_arg(array_merge(['dip_tab' => 'accounts'], $args), $base);
    }

    /**
     * Legacy endpoint: old links are redirected to the Identity Center before
     * this renders (DIP_My_Account_Modern). Fallback when it is shown anyway.
     */
    public static function screen() {
        if (!is_user_logged_in()) return;
        echo '<section class="dip-connected-account"><h2>' . esc_html__('Comptes connectés', 'delicat-google-login') . '</h2>';
        echo '<p>' . esc_html__('Vos connexions Google et Microsoft, vos appareils et vos sessions se gèrent désormais dans « Identité et sécurité ».', 'delicat-google-login') . '</p>';
        echo '<a class="button" href="' . esc_url(self::accounts_url()) . '">' . esc_html__('Ouvrir Identité et sécurité', 'delicat-google-login') . '</a></section>';
    }

    private static function has_password($uid) {
        return get_user_meta($uid, '_dglp_password_managed', true) !== 'yes';
    }

    /** The account now has a password its owner chose: it is no longer a managed (random) one. */
    private static function mark_password_confirmed($user_id) {
        $user_id = absint($user_id);
        if (!$user_id || isset(self::$password_confirmed[$user_id])) return;
        self::$password_confirmed[$user_id] = true;
        delete_user_meta($user_id, '_dglp_password_managed');
    }

    public static function password_confirmed($user, $new_pass = '') {
        if ($user instanceof WP_User) self::mark_password_confirmed($user->ID);
    }

    /**
     * wp_set_password (WordPress 6.2+) also covers WooCommerce's « Mot de passe
     * oublié », which never fires after_password_reset. Skipped for Delicat's
     * own random-password rotation and for a re-hash of the same password at
     * sign-in.
     */
    public static function password_set($password, $user_id, $old_user_data = null) {
        $user_id = absint($user_id);
        if (!$user_id || isset(self::$password_confirmed[$user_id])) return;
        if (class_exists('DIP_Account_Sync') && method_exists('DIP_Account_Sync', 'internal_password_rotation') && DIP_Account_Sync::internal_password_rotation($user_id)) return;
        if ($old_user_data instanceof WP_User) {
            $old_hash = (string) $old_user_data->user_pass;
            // No user id: wp_check_password() must not re-hash (and re-fire this hook) here.
            if ($old_hash !== '' && (string) $password !== '' && wp_check_password((string) $password, $old_hash)) return;
        } elseif (doing_filter('authenticate')) {
            return;
        }
        self::mark_password_confirmed($user_id);
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

    private static function require_self_service_post() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        }
        if (class_exists('DIP_Access_Guard') ? !DIP_Access_Guard::can_self_service() : !is_user_logged_in()) wp_die(esc_html__('Accès refusé.', 'delicat-google-login'), '', ['response' => 403]);
    }

    public static function unlink() { self::unlink_provider('google'); }

    public static function unlink_microsoft() { self::unlink_provider('microsoft'); }

    /** Legacy « Déconnecter Google / Microsoft » forms: same rules and effects as the Identity Center. */
    private static function unlink_provider($provider) {
        self::require_self_service_post();
        $uid = get_current_user_id();
        check_admin_referer('dip_unlink_' . $provider . '_' . $uid);
        $metas = $provider === 'google'
            ? ['sub' => '_dglp_google_sub', 'other' => '_dip_microsoft_sub', 'delete' => ['_dglp_google_sub', '_dglp_google_avatar', '_dglp_google_linked_at', '_dip_google_explicit_link_v2'], 'event' => 'account_unlinked']
            : ['sub' => '_dip_microsoft_sub', 'other' => '_dglp_google_sub', 'delete' => ['_dip_microsoft_sub', '_dip_microsoft_linked_at'], 'event' => 'account_unlinked_microsoft'];
        // Never leave the account without a way in.
        if (!self::has_password($uid) && !get_user_meta($uid, $metas['other'], true)) {
            wp_safe_redirect(self::accounts_url(['dip_account_status' => 'blocked'])); exit;
        }
        $sub = (string) get_user_meta($uid, $metas['sub'], true);
        // C3: tombstone the subject before it goes, so no just-in-time path silently re-links it.
        if ($sub !== '' && class_exists('DIP_Identity') && method_exists('DIP_Identity', 'remember_disconnected_subject')) {
            DIP_Identity::remember_disconnected_subject($uid, $provider, $sub);
        }
        foreach ($metas['delete'] as $key) delete_user_meta($uid, $key);
        do_action('dip_account_unlinked', $uid, $provider);
        if ($sub !== '') do_action('dip_account_disconnected', $uid, $provider, $sub);
        if (class_exists('DIP_Audit')) DIP_Audit::record($metas['event'], 'warning', $uid);
        wp_safe_redirect(self::accounts_url(['dip_account_status' => 'disconnected'])); exit;
    }

    public static function send_password_setup() {
        self::require_self_service_post();
        $uid = get_current_user_id();
        check_admin_referer('dip_send_password_setup_' . $uid);
        $user = get_userdata($uid);
        $sent = $user ? retrieve_password($user->user_login) : false;
        if (class_exists('DIP_Audit')) DIP_Audit::record('password_setup_requested', 'notice', $uid);
        wp_safe_redirect(self::accounts_url(['dip_password_email' => $sent === true ? 'sent' : 'failed'])); exit;
    }
}
