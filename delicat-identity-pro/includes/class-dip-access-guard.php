<?php
defined('ABSPATH') || exit;

/**
 * Central authorization boundary for Delicat Identity.
 *
 * Customer capabilities are intentionally limited to actions affecting their
 * own account. Global security/configuration operations require WordPress
 * administrator capability (manage_options), even if a module accidentally
 * forgets its own capability check later.
 */
final class DIP_Access_Guard {
    const ADMIN_CAP = 'manage_options';

    /** @var bool */
    private static $protected_request = false;

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'protect_frontend'], 0);
        add_action('admin_init', [__CLASS__, 'protect_admin_actions'], 0);
        add_action('send_headers', [__CLASS__, 'security_headers'], 20);
        add_filter('wp_headers', [__CLASS__, 'filter_headers'], 20);
        add_filter('option_page_capability_dip_group', [__CLASS__, 'settings_capability']);
        add_filter('option_page_capability_dip_email_group', [__CLASS__, 'settings_capability']);
        add_filter('option_page_capability_dip_builder_group', [__CLASS__, 'settings_capability']);
        add_filter('option_page_capability_dip_auth_methods_group', [__CLASS__, 'settings_capability']);
        add_action('add_meta_boxes_page', [__CLASS__, 'add_private_page_meta_box']);
        add_action('save_post_page', [__CLASS__, 'save_private_page_meta'], 10, 2);
    }

    public static function is_admin_user($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id) return false;
        return user_can($user_id, self::ADMIN_CAP);
    }

    public static function can_manage_identity() {
        return current_user_can(self::ADMIN_CAP);
    }

    public static function can_manage_security() {
        return current_user_can(self::ADMIN_CAP);
    }

    public static function can_manage_integrations() {
        return current_user_can(self::ADMIN_CAP);
    }

    public static function can_manage_mobile_api() {
        return current_user_can(self::ADMIN_CAP);
    }

    public static function settings_capability($capability = '') {
        return self::ADMIN_CAP;
    }

    public static function add_private_page_meta_box() {
        if (!self::can_manage_identity()) return;
        add_meta_box(
            'dip-private-page',
            __('Delicat Identity — Accès', 'delicat-google-login'),
            [__CLASS__, 'render_private_page_meta_box'],
            'page',
            'side',
            'high'
        );
    }

    public static function render_private_page_meta_box($post) {
        if (!$post instanceof WP_Post || !self::can_manage_identity()) return;
        wp_nonce_field('dip_private_page_' . $post->ID, 'dip_private_page_nonce');
        $enabled = get_post_meta($post->ID, '_dip_login_required', true) === 'yes';
        echo '<label style="display:block;line-height:1.45"><input type="checkbox" name="dip_login_required" value="1" ' . checked($enabled, true, false) . '> <strong>' . esc_html__('Connexion requise', 'delicat-google-login') . '</strong></label>';
        echo '<p class="description">' . esc_html__('Bloque l’accès avant le rendu du contenu et désactive le cache public pour les visiteurs connectés.', 'delicat-google-login') . '</p>';
    }

    public static function save_private_page_meta($post_id, $post) {
        $post_id = absint($post_id);
        if (!$post_id || !($post instanceof WP_Post) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!self::can_manage_identity() || !current_user_can('edit_post', $post_id)) return;
        $nonce = sanitize_text_field(wp_unslash($_POST['dip_private_page_nonce'] ?? ''));
        if (!$nonce || !wp_verify_nonce($nonce, 'dip_private_page_' . $post_id)) return;
        if (!empty($_POST['dip_login_required'])) update_post_meta($post_id, '_dip_login_required', 'yes');
        else delete_post_meta($post_id, '_dip_login_required');
    }

    /** Customer self-service is only valid for the authenticated current user. */
    public static function can_self_service($user_id = 0) {
        if (!is_user_logged_in()) return false;
        $user_id = absint($user_id ?: get_current_user_id());
        return $user_id > 0 && $user_id === get_current_user_id();
    }

    public static function require_login($message = '') {
        if (is_user_logged_in()) return;
        if ($message === '') $message = __('Connexion requise.', 'delicat-google-login');
        wp_die(esc_html($message), esc_html__('Accès protégé', 'delicat-google-login'), ['response' => 401]);
    }

    public static function require_admin($message = '') {
        if (self::can_manage_identity()) return;
        if ($message === '') $message = __('Cette fonction est réservée aux administrateurs.', 'delicat-google-login');
        wp_die(esc_html($message), esc_html__('Accès refusé', 'delicat-google-login'), ['response' => 403]);
    }

    /**
     * Defense-in-depth list for configuration/destructive admin-post actions.
     * Customer self-service actions are deliberately NOT included here.
     */
    public static function admin_only_actions() {
        return apply_filters('dip_admin_only_actions', [
            'dip_integrity_scan','dip_clear_security_events','dip_migration_scan',
            'dip_migration_backup','dip_migration_restore','dip_migration_export',
            'dip_migration_dry_run','dip_migration_test','dip_migration_batch',
            'dip_migration_rollback','dip_migration_auto_start','dip_migration_auto_pause',
            'dip_migration_auto_resume','dip_migration_verify','dip_migration_finalize',
            'dip_oauth_test','dip_export_security_csv','dip_repair_schedules',
            'dip_assistant_analyze','dip_assistant_bundle','dip_performance_run',
            'dip_performance_analyze','dip_performance_purge','dip_sdk_self_test',
            'dip_sdk_export','dip_builder_export','dip_builder_import','dip_builder_reset',
            'dip_provider_save','dip_provider_test','dipes_email_preview','dipes_email_test','dipes_export_settings','dip_wc_pro_save',
            'dip_app_sync_v2_save','dip_app_sync_v2_cleanup','dip_foundation_repair',
            'dip_ui_repair','dip_analytics_export','dip_analytics_refresh',
            'dip_admin_google_revoke',
        ]);
    }

    /** Actions a logged-in customer may perform only for their own account. */
    public static function self_service_actions() {
        return apply_filters('dip_self_service_actions', [
            'dip_security_device',
            'dip_security_logout_others',
            'dip_device_action',
            'dip_revoke_other_sessions',
            'dip_disconnect_provider',
            'dip_unlink_google',
            'dip_unlink_microsoft',
            'dip_send_password_setup',
            'dip_reauth_confirm',
            'dip_2fa_begin',
            'dip_2fa_confirm',
            'dip_2fa_disable',
            'dip_2fa_recovery_regen',
        ]);
    }

    /** Self-service operations that additionally require recent authentication. */
    public static function sensitive_self_service_actions() {
        return apply_filters('dip_sensitive_self_service_actions', [
            'dip_security_device','dip_security_logout_others','dip_device_action',
            'dip_revoke_other_sessions','dip_disconnect_provider','dip_unlink_google',
            'dip_unlink_microsoft','dip_send_password_setup','dip_2fa_begin',
            'dip_2fa_confirm','dip_2fa_disable','dip_2fa_recovery_regen',
        ]);
    }

    /** Intentionally public authentication/registration admin-post actions. */
    public static function public_auth_actions() {
        return apply_filters('dip_public_auth_actions', [
            'dip_request_otp','dip_verify_otp','dip_request_magic','dip_register_customer',
            'dip_2fa_complete_challenge',
        ]);
    }

    public static function require_self_service($user_id = 0) {
        if (self::can_self_service($user_id)) return;
        if (!is_user_logged_in()) {
            self::require_login(__('Connexion requise.', 'delicat-google-login'));
        }
        wp_die(
            esc_html__('Cette action est limitée à votre propre compte.', 'delicat-google-login'),
            esc_html__('Accès refusé', 'delicat-google-login'),
            ['response' => 403]
        );
    }

    public static function protect_admin_actions() {
        if (!is_admin()) return;

        // Explicit admin-post action boundary.
        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? ''));
        if ($action !== '' && in_array($action, self::admin_only_actions(), true)) {
            self::require_admin();
            return;
        }
        if ($action !== '' && in_array($action, self::self_service_actions(), true)) {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), esc_html__('Accès protégé', 'delicat-google-login'), ['response'=>405]);
            }
            self::require_self_service();
            if (in_array($action, self::sensitive_self_service_actions(), true) && class_exists('DIP_Reauth') && !DIP_Reauth::is_recent()) {
                DIP_Reauth::require_recent_or_redirect();
            }
            return;
        }

        // Direct access to plugin administration pages must never rely solely
        // on the menu being hidden from non-admin users.
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if ($page !== '' && in_array($page, self::admin_page_slugs(), true)) {
            self::require_admin();
        }
    }

    private static function admin_page_slugs() {
        return apply_filters('dip_admin_page_slugs', [
            'delicat-identity','delicat-identity-providers','delicat-identity-builder',
            'dip-security-center','dip-woocommerce-pro','dip-app-sync-v2','dip-analytics',
            'delicat-identity-emails','dip-auth-methods','dip-foundation-health',
            'delicat-identity-hub',
        ]);
    }

    private static function protected_shortcodes() {
        return apply_filters('dip_login_required_shortcodes', [
            'delicat_identity_dashboard',
            'delicat_security_center',
            'delicat_connected_accounts',
        ]);
    }

    /**
     * Default private pages. Site owners can extend this without editing the
     * plugin via the dip_protected_page_slugs filter.
     */
    private static function protected_page_slugs() {
        $settings = class_exists('DIP_Plugin')
            ? wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults())
            : ['protected_page_slugs' => 'my-wallet'];
        $raw = (string) ($settings['protected_page_slugs'] ?? 'my-wallet');
        $parts = preg_split('/[\s,;]+/', $raw);
        $slugs = array_values(array_unique(array_filter(array_map(static function($slug) {
            return sanitize_title(trim((string) $slug, " /\t\n\r\0\x0B"));
        }, (array) $parts))));
        $slugs = apply_filters('dip_protected_page_slugs', $slugs);
        $slugs = array_values(array_unique(array_filter(array_map('sanitize_title', (array) $slugs))));
        // Never protect the actual WooCommerce login host page by slug or the
        // redirect target could loop for anonymous visitors.
        if (function_exists('wc_get_page_id')) {
            $account_id = absint(wc_get_page_id('myaccount'));
            if ($account_id > 0) {
                $account_slug = sanitize_title((string) get_post_field('post_name', $account_id));
                if ($account_slug) $slugs = array_values(array_diff($slugs, [$account_slug]));
            }
        }
        return $slugs;
    }

    private static function current_url() {
        $uri = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        if ($uri === '' || strpos($uri, '/') !== 0 || strpos($uri, '//') === 0 || strlen($uri) > 4096) $uri = '/';
        return wp_validate_redirect(home_url($uri), home_url('/'));
    }

    private static function account_login_url($return_url = '') {
        $return_url = $return_url ?: self::current_url();
        if (function_exists('wc_get_page_permalink')) {
            $account = wc_get_page_permalink('myaccount');
            if ($account) return add_query_arg('redirect_to', $return_url, $account);
        }
        return wp_login_url($return_url);
    }

    private static function has_protected_shortcode() {
        if (!is_singular()) return false;
        global $post;
        if (!$post instanceof WP_Post) return false;
        if (get_post_meta($post->ID, '_dip_login_required', true) === 'yes') return true;
        $content = (string) $post->post_content;
        $builder = get_post_meta($post->ID, '_elementor_data', true);
        $builder = is_string($builder) && strlen($builder) <= 2097152 ? $builder : '';
        foreach (self::protected_shortcodes() as $tag) {
            if (shortcode_exists($tag) && has_shortcode($content, $tag)) return true;
            // Elementor stores shortcode widgets in page meta rather than
            // post_content. A literal tag check keeps those pages protected
            // before Elementor renders any customer data.
            if ($builder !== '' && (strpos($builder, '[' . $tag) !== false || strpos($builder, '"' . $tag . '"') !== false)) return true;
        }
        return false;
    }

    private static function is_identity_endpoint() {
        if (!function_exists('is_account_page') || !is_account_page()) return false;

        // All WooCommerce My Account endpoints contain private account data,
        // except the password-recovery endpoint which must remain public.
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url()) {
            if (is_wc_endpoint_url('lost-password')) return false;
            return true;
        }

        $endpoints = [];
        if (class_exists('DIP_Customer_Dashboard')) $endpoints[] = DIP_Customer_Dashboard::ENDPOINT;
        if (class_exists('DIP_WooCommerce')) $endpoints[] = DIP_WooCommerce::ENDPOINT;
        foreach (array_filter($endpoints) as $endpoint) {
            if (get_query_var($endpoint, null) !== null) return true;
        }
        return false;
    }

    public static function is_protected_frontend_request() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return false;
        if (defined('REST_REQUEST') && REST_REQUEST) return false;
        if (self::is_identity_endpoint()) return true;
        if (self::has_protected_shortcode()) return true;
        $slugs = self::protected_page_slugs();
        return $slugs && is_page($slugs);
    }

    private static function remember_return_url($url) {
        if (headers_sent() || !wp_http_validate_url($url)) return;
        setcookie('dip_return_url', $url, [
            'expires' => time() + 15 * MINUTE_IN_SECONDS,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['dip_return_url'] = $url;
    }

    public static function protect_frontend() {
        if (!self::is_protected_frontend_request()) return;
        self::$protected_request = true;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();

        if (!is_user_logged_in()) {
            $return_url = self::current_url();
            self::remember_return_url($return_url);
            $target = self::account_login_url($return_url);
            wp_safe_redirect($target, 302, 'Delicat Identity');
            exit;
        }

        // Re-check the account policy on every protected customer request so a
        // user suspended after login cannot keep using a stale browser session.
        if (class_exists('DIP_Account_Sync')) {
            $guard = DIP_Account_Sync::login_guard(get_current_user_id());
            if (is_wp_error($guard)) {
                if (class_exists('WP_Session_Tokens')) {
                    WP_Session_Tokens::get_instance(get_current_user_id())->destroy(wp_get_session_token());
                }
                wp_clear_auth_cookie();
                wp_set_current_user(0);
                $url = add_query_arg('dip_auth_error', sanitize_key($guard->get_error_code()), self::account_login_url(self::current_url()));
                wp_safe_redirect($url, 302, 'Delicat Identity');
                exit;
            }
        }
    }

    private static function should_send_private_headers() {
        if (self::$protected_request || self::is_protected_frontend_request()) return true;
        return is_user_logged_in() && function_exists('is_account_page') && is_account_page();
    }

    public static function security_headers() {
        if (!self::should_send_private_headers()) return;
        nocache_headers();
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow, noarchive', true);
            header('Referrer-Policy: same-origin', true);
            header('X-Content-Type-Options: nosniff', true);
            header('X-Frame-Options: SAMEORIGIN', true);
        }
    }

    public static function filter_headers($headers) {
        if (!self::should_send_private_headers()) return $headers;
        $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, private, max-age=0';
        $headers['Pragma'] = 'no-cache';
        $headers['Expires'] = 'Wed, 11 Jan 1984 05:00:00 GMT';
        $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
        $headers['Referrer-Policy'] = 'same-origin';
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['X-Frame-Options'] = 'SAMEORIGIN';
        return $headers;
    }
}
