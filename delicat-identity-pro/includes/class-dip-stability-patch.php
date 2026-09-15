<?php
defined('ABSPATH') || exit;

/**
 * Focused regression fixes for the UI stability release.
 * This class intentionally patches behavior without replacing working modules.
 */
final class DIP_Stability_Patch {
    const VERSION_OPTION = 'dip_stability_patch_version';

    public static function init() {
        // The previous UI layer loaded on every frontend request. Replace it with
        // a conditional loader so unrelated product and homepage requests stay lean.
        if (class_exists('DIP_UI_Stability', false)) {
            remove_action('wp_enqueue_scripts', ['DIP_UI_Stability', 'front_assets'], 50);
        }
        add_action('wp_enqueue_scripts', [__CLASS__, 'front_assets'], 50);
        add_action('login_enqueue_scripts', [__CLASS__, 'login_assets'], 50);

        // Accounts created by the custom registration form must verify their email
        // before any password-based authentication path is accepted.
        add_filter('authenticate', [__CLASS__, 'enforce_verified_email'], 35, 3);

        add_action('admin_notices', [__CLASS__, 'admin_notice']);
        add_action('init', [__CLASS__, 'maybe_upgrade'], 4);
    }

    public static function install() {
        update_option(self::VERSION_OPTION, DIP_VERSION, false);
        delete_transient('dip_health_snapshot');
        delete_transient('dip_analytics_summary');
    }

    public static function maybe_upgrade() {
        if (get_option(self::VERSION_OPTION) !== DIP_VERSION) self::install();
    }

    private static function page_has_identity_shortcode() {
        if (!is_singular()) return false;
        $post = get_post();
        if (!$post || !is_string($post->post_content)) return false;
        foreach ([
            'delicat_auth_panel', 'delicat_passwordless_login', 'delicat_registration_form',
            'delicat_connected_accounts', 'delicat_security_center', 'delicat_app_pairing',
            'delicat_identity_dashboard', 'delicat_login_panel', 'delicat_login_button',
            'delicat_google_login', 'delicat_social_login', 'delicat_social_login_buttons'
        ] as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) return true;
        }
        return false;
    }

    private static function should_load_front_assets() {
        if (self::page_has_identity_shortcode()) return true;
        if (function_exists('is_account_page') && is_account_page()) return true;
        if (function_exists('is_checkout') && is_checkout()) return true;
        if (get_query_var('identite-securite')) return true;
        return false;
    }

    public static function front_assets() {
        if (is_admin() || !self::should_load_front_assets()) return;
        wp_enqueue_style('dip-ui-front', DIP_URL . 'assets/ui-front.css', [], DIP_VERSION);
        wp_enqueue_style('dip-stability-patch', DIP_URL . 'assets/stability-patch.css', ['dip-ui-front'], DIP_VERSION);
    }

    public static function login_assets() {
        wp_enqueue_style('dip-ui-front', DIP_URL . 'assets/ui-front.css', [], DIP_VERSION);
        wp_enqueue_style('dip-stability-patch', DIP_URL . 'assets/stability-patch.css', ['dip-ui-front'], DIP_VERSION);
    }

    public static function enforce_verified_email($user, $username, $password) {
        if (is_wp_error($user) || !($user instanceof WP_User)) return $user;
        if (class_exists('DIP_Account_Sync')) {
            $guard = DIP_Account_Sync::login_guard($user->ID);
            if (is_wp_error($guard)) return $guard;
            return $user;
        }
        if (get_user_meta($user->ID, 'dip_email_verified', true) === 'no') {
            return new WP_Error('dip_email_not_verified', __('Veuillez vérifier votre adresse e-mail avant de vous connecter.', 'delicat-google-login'));
        }
        if (class_exists('DIP_Policy') && DIP_Policy::is_pending($user->ID)) {
            return new WP_Error('account_pending_approval', __('Votre compte attend l’approbation de l’administrateur.', 'delicat-google-login'));
        }
        return $user;
    }

    public static function admin_notice() {
        if (!current_user_can('manage_options') || empty($_GET['page'])) return;
        $page = sanitize_key(wp_unslash($_GET['page']));
        if (strpos($page, 'dip-') !== 0 && strpos($page, 'delicat-identity') !== 0) return;
        if (!empty($_GET['settings-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Delicat Identity:</strong> ' . esc_html__('réglages enregistrés.', 'delicat-google-login') . '</p></div>';
        }
    }
}
