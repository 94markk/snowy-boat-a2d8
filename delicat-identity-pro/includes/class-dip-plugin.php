<?php
defined('ABSPATH') || exit;

final class DIP_Plugin {
    const OPTION = 'dglp_settings';
    const LOG_OPTION = 'dip_security_events';
    const VERSION_OPTION = 'dip_identity_installed_version';
    const RECOVERY_NOTICE = 'dip_google_maintenance_recovered';
    const RECOVERY_PURGE = 'dip_google_recovery_purge_pending';
    const RUNTIME_SCHEMA_OPTION = 'dip_runtime_schema_version';
    const RUNTIME_SCHEMA_VERSION = '6.2.2';
    private static $instance;
    private $failure_tracker = '';
    private $failure_return_url = '';

    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public static function defaults() {
        return [
            'enabled' => 'yes', 'client_id' => '', 'client_secret' => '',
            'button_text' => 'Continuer avec Google', 'allow_registration' => 'yes',
            'link_existing_email' => 'yes', 'default_role' => 'customer',
            'remember_login' => 'yes', 'auto_wp_login' => 'yes',
            'auto_my_account' => 'yes', 'auto_checkout' => 'yes',
            'redirect_login' => '/my-account/', 'redirect_register' => '/my-account/',
            'rate_limit' => 10, 'rate_window' => 10, 'use_google_avatar' => 'yes',
            'security_log_enabled' => 'yes', 'security_retention_days' => 30,
            'lockout_enabled' => 'yes', 'lockout_threshold' => 7,
            'lockout_window' => 15, 'lockout_duration' => 20,
            'button_theme' => 'light', 'button_width' => 100, 'button_height' => 50,
            'button_radius' => 14, 'button_font_size' => 15, 'button_weight' => 700,
            'button_align' => 'stretch', 'button_shadow' => 'soft', 'button_motion' => 'lift',
            'button_bg' => '#ffffff', 'button_color' => '#1f2937', 'button_border' => '#d9dde5',
            'show_divider' => 'no', 'mobile_full_width' => 'yes',
            'wp_login_text' => '', 'account_text' => '', 'checkout_text' => '',
            'trusted_devices_enabled' => 'yes', 'new_device_email' => 'yes', 'device_retention_days' => 90,
            'mobile_api_enabled' => 'no', 'android_client_id' => '', 'ios_client_id' => '',
            'mobile_push_enabled' => 'no', 'mobile_biometric_enabled' => 'no',
            'mobile_access_ttl' => 900, 'mobile_refresh_ttl' => 2592000,
            'provider_framework_enabled' => 'yes',
            'microsoft_enabled' => 'no', 'microsoft_client_id' => '', 'microsoft_client_secret' => '',
            'microsoft_tenant' => 'common', 'microsoft_button_text' => 'Continuer avec Microsoft',
            'microsoft_link_existing_email' => 'no',
            'adaptive_risk_blocking' => 'no', 'risk_block_threshold' => 70,
            'allowed_email_domains' => '', 'blocked_email_domains' => '',
            'country_policy_mode' => 'off', 'country_codes' => '', 'country_header_required' => 'no',
            'require_new_user_approval' => 'no', 'block_privileged_social_login' => 'yes', 'admin_google_secure_mode' => 'yes',
            'social_login_maintenance' => 'no',
            'social_login_maintenance_explicit' => 'no',
            'social_login_maintenance_changed_at' => 0,
            'social_login_maintenance_changed_by' => 0,
            'health_email' => 'no',
            'performance_maintenance_enabled' => 'yes', 'conditional_assets' => 'yes',
            'performance_health_cache' => 'yes',
            'automatic_migration_batch_size' => 25,
            'native_account_url' => '',
            'native_login_text' => 'Connexion / Inscription',
            'native_account_text' => 'Mon compte',
            'show_native_login_link' => 'yes',
            'native_modal_enabled' => 'yes',
            'native_login_redirect' => '/my-wallet/',
            'native_register_redirect' => '/my-wallet/',
            'native_max_attempts' => 5,
            'native_lockout_base' => 15,
            'native_lockout_max' => 120,
            'native_reg_max_per_hour' => 3,
            'native_password_min' => 8,
            'native_password_max' => 100,
            'protected_page_slugs' => 'my-wallet',
            'strict_rest_firewall' => 'yes',
            'two_factor_available' => 'yes',
            'passkeys_available' => 'yes',
            'passkeys_max_per_user' => 8,
            'sensitive_reauth_minutes' => 10,
            'auto_comments' => 'no',
            'auto_lost_password' => 'yes',
            'bypass_cache_redirect' => 'yes',
            'google_prompt_select_account' => 'yes',
            'sync_profile_name' => 'yes',
            'registration_notification' => 'admin',
            'blocked_social_roles' => 'administrator,editor,shop_manager',
            'button_layout' => 'wide',
            'provider_order' => 'google,microsoft',
            'google_redirect' => '',
            'microsoft_redirect' => '',
            'role_redirects' => "customer=/my-wallet/\nsubscriber=/my-account/",
            'hide_social_for_logged_in' => 'yes',
        ];
    }

    public static function activate() {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) $raw = [];
        // Upgrade plaintext and legacy v1 OAuth secrets to the authenticated
        // v2 storage format on plugin activation/update. If cryptography is
        // unavailable, preserve the previous value rather than corrupting it.
        foreach (['client_secret', 'microsoft_client_secret'] as $secret_key) {
            $stored = (string) ($raw[$secret_key] ?? '');
            if ($stored === '' || strpos($stored, DIP_Crypto::PREFIX) === 0) continue;
            $plain = strpos($stored, 'dglp_enc_v1:') === 0 ? DIP_Crypto::decrypt($stored) : $stored;
            if ($plain === '') continue;
            $encrypted = DIP_Crypto::encrypt($plain);
            if ($encrypted !== '') $raw[$secret_key] = $encrypted;
        }
        update_option(self::OPTION, wp_parse_args($raw, self::defaults()), false);
        self::install_runtime_schema();
        self::maybe_upgrade();
    }

    /**
     * Run small, versioned data repairs on normal plugin updates as well as on
     * activation. WordPress does not guarantee that an activation hook is run
     * when an already-active plugin is replaced, so update recovery must also
     * happen during the ordinary boot path.
     *
     * 6.9.16 repairs the legacy, untracked social-maintenance flag that caused
     * Google to disappear after an update. Older releases stored only `yes` or
     * `no`, so there was no way to distinguish an intentional emergency switch
     * from a stale value. We clear that legacy value once. Any administrator who
     * explicitly enables maintenance in 6.9.16+ gets an audit marker and their
     * choice is preserved by later updates.
     */
    public static function maybe_upgrade() {
        $installed = sanitize_text_field((string) get_option(self::VERSION_OPTION, ''));
        if ($installed !== '' && version_compare($installed, DIP_VERSION, '>=')) return;

        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) $raw = [];
        $legacy_maintenance = ($raw['social_login_maintenance'] ?? 'no') === 'yes'
            && ($raw['social_login_maintenance_explicit'] ?? 'no') !== 'yes';

        if ($legacy_maintenance && ($installed === '' || version_compare($installed, '6.9.16', '<'))) {
            $raw['social_login_maintenance'] = 'no';
            $raw['social_login_maintenance_explicit'] = 'no';
            $raw['social_login_maintenance_changed_at'] = time();
            $raw['social_login_maintenance_changed_by'] = 0;
            update_option(self::OPTION, wp_parse_args($raw, self::defaults()), false);
            set_transient(self::RECOVERY_NOTICE, 1, 30 * MINUTE_IN_SECONDS);
            set_transient(self::RECOVERY_PURGE, 1, 30 * MINUTE_IN_SECONDS);
        }

        update_option(self::VERSION_OPTION, DIP_VERSION, false);
    }

    /**
     * Install only the runtime tables owned by the legacy/core Identity modules.
     * Foundation owns dip_db_version independently; never mutate that option here.
     */
    public static function install_runtime_schema() {
        DIP_Audit::install();
        DIP_Devices::install();
        DIP_Mobile_API::install();
        update_option(self::RUNTIME_SCHEMA_OPTION, self::RUNTIME_SCHEMA_VERSION, false);
    }

    public static function deactivate() { if (class_exists('DIP_Analytics')) DIP_Analytics::uninstall_schedule(); DIP_Audit::uninstall_schedule(); DIP_Devices::uninstall_schedule(); DIP_Migration::uninstall_automatic_schedule(); if (class_exists('DIP_Performance')) DIP_Performance::uninstall_schedule(); }

    private function __construct() {
        self::maybe_upgrade();
        add_action('init', [$this, 'route'], 1);
        add_action('wp_loaded', [$this, 'maybe_purge_recovery_cache'], PHP_INT_MAX);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action(DIP_Audit::CRON_HOOK, [$this, 'audit_cleanup']);
        add_action(DIP_Devices::CRON_HOOK, [$this, 'device_cleanup']);
        add_action(DIP_Migration::CRON_HOOK, ['DIP_Migration', 'automatic_tick']);
        add_action('init', ['DIP_Migration', 'maybe_run_automatic_fallback'], 25);
        add_action('admin_post_dip_integrity_scan', [$this, 'handle_integrity_scan']);
        add_action('admin_post_dip_clear_security_events', [$this, 'handle_clear_security_events']);
        add_action('admin_post_dip_migration_scan', [$this, 'handle_migration_scan']);
        add_action('admin_post_dip_migration_backup', [$this, 'handle_migration_backup']);
        add_action('admin_post_dip_migration_restore', [$this, 'handle_migration_restore']);
        add_action('admin_post_dip_migration_export', [$this, 'handle_migration_export']);
        add_action('admin_post_dip_migration_dry_run', [$this, 'handle_migration_dry_run']);
        add_action('admin_post_dip_migration_test', [$this, 'handle_migration_test']);
        add_action('admin_post_dip_migration_batch', [$this, 'handle_migration_batch']);
        add_action('admin_post_dip_migration_rollback', [$this, 'handle_migration_rollback']);
        add_action('admin_post_dip_migration_auto_start', [$this, 'handle_migration_auto_start']);
        add_action('admin_post_dip_migration_auto_pause', [$this, 'handle_migration_auto_pause']);
        add_action('admin_post_dip_migration_auto_resume', [$this, 'handle_migration_auto_resume']);
        add_action('admin_post_dip_migration_verify', [$this, 'handle_migration_verify']);
        add_action('admin_post_dip_migration_finalize', [$this, 'handle_migration_finalize']);
        add_action('admin_post_dip_oauth_test', [$this, 'handle_oauth_test']);
        add_action('admin_post_dip_reenable_google', [$this, 'handle_reenable_google']);
        add_action('admin_post_dip_device_action', [$this, 'handle_device_action']);
        add_action('admin_post_dip_revoke_other_sessions', [$this, 'handle_revoke_other_sessions']);
        add_action('admin_post_dip_export_security_csv', [$this, 'handle_export_security_csv']);
        add_action('admin_post_dip_repair_schedules', [$this, 'handle_repair_schedules']);
        add_action('admin_post_dip_assistant_analyze', [$this, 'handle_assistant_analyze']);
        add_action('admin_post_dip_assistant_bundle', [$this, 'handle_assistant_bundle']);
        add_action('admin_post_dip_performance_run', [$this, 'handle_performance_run']);
        add_action('admin_post_dip_performance_analyze', [$this, 'handle_performance_analyze']);
        add_action('admin_post_dip_performance_purge', [$this, 'handle_performance_purge']);
        add_action('admin_post_dip_sdk_self_test', [$this, 'handle_sdk_self_test']);
        add_action('admin_post_dip_sdk_export', [$this, 'handle_sdk_export']);
        add_action('admin_notices', [$this, 'migration_notice']);
        add_action('admin_notices', [$this, 'google_availability_notice']);
        add_action('show_user_profile', [$this, 'approval_profile']);
        add_action('edit_user_profile', [$this, 'approval_profile']);
        add_action('personal_options_update', [$this, 'save_approval_profile']);
        add_action('edit_user_profile_update', [$this, 'save_approval_profile']);
        add_action('dip_user_created', [$this, 'audit_user_created'], 10, 2);
        add_action('dip_account_linked', [$this, 'audit_account_linked'], 10, 2);
        add_action('dip_login_success', [$this, 'track_device'], 20, 2);
        if ((string) get_option(self::RUNTIME_SCHEMA_OPTION, '') !== self::RUNTIME_SCHEMA_VERSION) { self::install_runtime_schema(); }
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('login_enqueue_scripts', [$this, 'assets']);
        add_action('login_form', [$this, 'render_wp_login']);
        add_filter('login_message', [$this, 'login_error_message']);
        add_action('woocommerce_login_form_end', [$this, 'render_my_account']);
        add_action('woocommerce_register_form_end', [$this, 'render_my_account']);
        add_action('woocommerce_before_checkout_form', [$this, 'render_checkout'], 8);
        add_shortcode('delicat_google_login', [$this, 'shortcode']);
        add_shortcode('delicat_social_login', [$this, 'social_shortcode']);
        add_shortcode('delicat_login_button', [$this, 'login_button_shortcode']);
        add_shortcode('delicat_account_button', [$this, 'account_button_shortcode']);
        add_shortcode('delicat_login_panel', [$this, 'login_panel_shortcode']);
        add_filter('get_avatar_url', [$this, 'avatar_url'], 10, 3);
        add_filter('plugin_action_links_' . plugin_basename(DIP_FILE), [$this, 'links']);
    }

    public function links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=delicat-identity')) . '">Réglages</a>');
        return $links;
    }

    public function admin_assets($hook) {
        if ($hook !== 'settings_page_delicat-identity') return;
        wp_enqueue_style('dip-admin', DIP_URL . 'assets/admin.css', [], DIP_VERSION);
        wp_enqueue_script('dip-visual-builder', DIP_URL . 'assets/visual-builder.js', [], DIP_VERSION, true);
        wp_enqueue_script('dip-admin', DIP_URL . 'assets/admin.js', [], DIP_VERSION, true);
    }

    public function assets($force = false) {
        if (is_admin()) return;
        $s = $this->settings();
        if (!$force && ($s['conditional_assets'] ?? 'yes') === 'yes' && class_exists('DIP_Performance') && !DIP_Performance::should_load_front_assets() && !did_action('login_enqueue_scripts')) return;
        wp_enqueue_style('dip-login', DIP_URL . 'assets/login.css', [], DIP_VERSION);
        $css = ':root{--dip-width:' . absint($s['button_width']) . '%;--dip-height:' . absint($s['button_height']) . 'px;--dip-radius:' . absint($s['button_radius']) . 'px;--dip-font:' . absint($s['button_font_size']) . 'px;--dip-weight:' . absint($s['button_weight']) . ';--dip-bg:' . esc_attr($s['button_bg']) . ';--dip-color:' . esc_attr($s['button_color']) . ';--dip-border:' . esc_attr($s['button_border']) . ';}';
        wp_add_inline_style('dip-login', $css);
    }

    public function admin_menu() {
        add_options_page('Delicat Identity Pro', 'Delicat Identity', 'manage_options', 'delicat-identity', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('dip_group', self::OPTION, ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_settings']]);
    }

    public function sanitize_settings($input) {
        $old = $this->settings();
        $out = wp_parse_args($old, self::defaults());
        foreach (['enabled','allow_registration','link_existing_email','remember_login','auto_wp_login','auto_my_account','auto_checkout','use_google_avatar','security_log_enabled','lockout_enabled','show_divider','mobile_full_width','trusted_devices_enabled','new_device_email','mobile_api_enabled','mobile_push_enabled','mobile_biometric_enabled','provider_framework_enabled','microsoft_enabled','microsoft_link_existing_email','adaptive_risk_blocking','country_header_required','require_new_user_approval','block_privileged_social_login','social_login_maintenance','health_email','performance_maintenance_enabled','conditional_assets','performance_health_cache','show_native_login_link','auto_comments','auto_lost_password','bypass_cache_redirect','google_prompt_select_account','sync_profile_name','hide_social_for_logged_in','native_modal_enabled','strict_rest_firewall','two_factor_available','passkeys_available','admin_google_secure_mode'] as $key) {
            $out[$key] = !empty($input[$key]) ? 'yes' : 'no';
        }
        // From 6.9.16 onward maintenance is an explicit administrator decision,
        // not an untracked value that can silently survive an update forever.
        $out['social_login_maintenance_explicit'] = 'yes';
        $out['social_login_maintenance_changed_at'] = time();
        $out['social_login_maintenance_changed_by'] = get_current_user_id();
        // Privileged social access is a non-downgradeable policy. WordPress
        // Administrators have only the explicit Google Secure Mode exception;
        // editors/shop managers and other privileged accounts remain blocked.
        $out['block_privileged_social_login'] = 'yes';
        // Microsoft email/preferred_username claims are mutable; never use them for automatic account ownership.
        $out['microsoft_link_existing_email'] = 'no';
        $out['client_id'] = sanitize_text_field($input['client_id'] ?? '');
        $out['android_client_id'] = sanitize_text_field($input['android_client_id'] ?? '');
        $out['ios_client_id'] = sanitize_text_field($input['ios_client_id'] ?? '');
        $out['microsoft_client_id'] = sanitize_text_field($input['microsoft_client_id'] ?? '');
        $out['microsoft_tenant'] = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($input['microsoft_tenant'] ?? 'common')) ?: 'common';
        $out['microsoft_button_text'] = sanitize_text_field($input['microsoft_button_text'] ?? 'Continuer avec Microsoft');
        $out['allowed_email_domains'] = sanitize_text_field($input['allowed_email_domains'] ?? '');
        $out['blocked_email_domains'] = sanitize_text_field($input['blocked_email_domains'] ?? '');
        $out['country_codes'] = strtoupper(sanitize_text_field($input['country_codes'] ?? ''));
        $out['country_policy_mode'] = in_array(($input['country_policy_mode'] ?? 'off'), ['off','allow','deny'], true) ? $input['country_policy_mode'] : 'off';
        $out['risk_block_threshold'] = min(100, max(10, absint($input['risk_block_threshold'] ?? 70)));
        // 6.9.15: $old holds DECRYPTED secrets. When WordPress salts change, a
        // stored secret decrypts to '' — and the previous code then wrote that
        // '' back, permanently destroying a blob that was still recoverable by
        // restoring the salts. Fall back to the raw stored ciphertext instead.
        $stored_raw = wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
        $secret = trim((string) ($input['client_secret'] ?? ''));
        if (!empty($input['clear_client_secret'])) {
            $out['client_secret'] = '';
        } elseif ($secret === '' || $secret === '********') {
            $candidate = (string) $old['client_secret'];
            if ($candidate === '') {
                $out['client_secret'] = (string) ($stored_raw['client_secret'] ?? '');
            } else {
                $encrypted = DIP_Crypto::encrypt($candidate);
                $out['client_secret'] = $encrypted !== '' ? $encrypted : (string) ($stored_raw['client_secret'] ?? '');
            }
        } else {
            $candidate = sanitize_text_field($secret);
            $encrypted = DIP_Crypto::encrypt($candidate);
            $out['client_secret'] = $encrypted !== '' ? $encrypted : (string) ($stored_raw['client_secret'] ?? '');
        }
        $ms_secret = trim((string) ($input['microsoft_client_secret'] ?? ''));
        if (!empty($input['clear_microsoft_client_secret'])) {
            $out['microsoft_client_secret'] = '';
        } elseif ($ms_secret === '' || $ms_secret === '********') {
            $candidate = (string) $old['microsoft_client_secret'];
            if ($candidate === '') {
                $out['microsoft_client_secret'] = (string) ($stored_raw['microsoft_client_secret'] ?? '');
            } else {
                $encrypted = DIP_Crypto::encrypt($candidate);
                $out['microsoft_client_secret'] = $encrypted !== '' ? $encrypted : (string) ($stored_raw['microsoft_client_secret'] ?? '');
            }
        } else {
            $candidate = sanitize_text_field($ms_secret);
            $encrypted = DIP_Crypto::encrypt($candidate);
            $out['microsoft_client_secret'] = $encrypted !== '' ? $encrypted : (string) ($stored_raw['microsoft_client_secret'] ?? '');
        }
        $out['button_text'] = sanitize_text_field($input['button_text'] ?? $out['button_text']);
        $out['default_role'] = class_exists('DIP_Account_Sync')
            ? DIP_Account_Sync::safe_registration_role($input['default_role'] ?? 'customer')
            : (get_role('customer') ? 'customer' : 'subscriber');
        $out['redirect_login'] = esc_url_raw($input['redirect_login'] ?? '');
        $out['redirect_register'] = esc_url_raw($input['redirect_register'] ?? '');
        $out['rate_limit'] = min(50, max(3, absint($input['rate_limit'] ?? 10)));
        $out['rate_window'] = min(60, max(5, absint($input['rate_window'] ?? 10)));
        $out['security_retention_days'] = min(180, max(7, absint($input['security_retention_days'] ?? 30)));
        $out['device_retention_days'] = min(365, max(30, absint($input['device_retention_days'] ?? 90)));
        $out['mobile_access_ttl'] = min(3600, max(300, absint($input['mobile_access_ttl'] ?? 900)));
        $out['mobile_refresh_ttl'] = min(7776000, max(86400, absint($input['mobile_refresh_ttl'] ?? 2592000)));
        $out['automatic_migration_batch_size'] = min(50, max(5, absint($input['automatic_migration_batch_size'] ?? ($old['automatic_migration_batch_size'] ?? 25))));
        $out['lockout_threshold'] = min(25, max(3, absint($input['lockout_threshold'] ?? 7)));
        $out['lockout_window'] = min(60, max(5, absint($input['lockout_window'] ?? 15)));
        $out['lockout_duration'] = min(120, max(5, absint($input['lockout_duration'] ?? 20)));
        $out['button_theme'] = in_array(($input['button_theme'] ?? ''), ['light','dark','brand','custom'], true) ? $input['button_theme'] : 'light';
        $out['button_width'] = min(100, max(40, absint($input['button_width'] ?? 100)));
        $out['button_height'] = min(72, max(40, absint($input['button_height'] ?? 50)));
        $out['button_radius'] = min(36, max(0, absint($input['button_radius'] ?? 14)));
        $out['button_font_size'] = min(22, max(12, absint($input['button_font_size'] ?? 15)));
        $out['button_weight'] = in_array(absint($input['button_weight'] ?? 700), [500,600,700,800], true) ? absint($input['button_weight']) : 700;
        $out['button_align'] = in_array(($input['button_align'] ?? ''), ['left','center','right','stretch'], true) ? $input['button_align'] : 'stretch';
        $out['button_shadow'] = in_array(($input['button_shadow'] ?? ''), ['none','soft','strong'], true) ? $input['button_shadow'] : 'soft';
        $out['button_motion'] = in_array(($input['button_motion'] ?? ''), ['none','lift','pulse'], true) ? $input['button_motion'] : 'lift';
        foreach (['button_bg'=>'#ffffff','button_color'=>'#1f2937','button_border'=>'#d9dde5'] as $key=>$fallback) $out[$key] = sanitize_hex_color($input[$key] ?? '') ?: $fallback;
        foreach (['wp_login_text','account_text','checkout_text','native_login_text','native_account_text'] as $key) $out[$key] = sanitize_text_field($input[$key] ?? '');
        $out['native_account_url'] = esc_url_raw($input['native_account_url'] ?? '');
        $out['native_login_redirect'] = esc_url_raw($input['native_login_redirect'] ?? '/my-wallet/');
        $out['native_register_redirect'] = esc_url_raw($input['native_register_redirect'] ?? '/my-wallet/');
        $out['native_max_attempts'] = min(20, max(3, absint($input['native_max_attempts'] ?? 5)));
        $out['native_lockout_base'] = min(60, max(5, absint($input['native_lockout_base'] ?? 15)));
        $out['native_lockout_max'] = min(1440, max($out['native_lockout_base'], absint($input['native_lockout_max'] ?? 120)));
        $out['native_reg_max_per_hour'] = min(20, max(1, absint($input['native_reg_max_per_hour'] ?? 3)));
        $out['native_password_min'] = min(64, max(8, absint($input['native_password_min'] ?? 8)));
        $out['native_password_max'] = min(256, max($out['native_password_min'], absint($input['native_password_max'] ?? 100)));
        $out['passkeys_max_per_user'] = min(12, max(1, absint($input['passkeys_max_per_user'] ?? 8)));
        $out['sensitive_reauth_minutes'] = min(60, max(5, absint($input['sensitive_reauth_minutes'] ?? ($old['sensitive_reauth_minutes'] ?? 10))));
        $protected_raw = (string) ($input['protected_page_slugs'] ?? $out['protected_page_slugs'] ?? 'my-wallet');
        $protected_parts = preg_split('/[\s,;]+/', $protected_raw);
        $protected_parts = array_values(array_unique(array_filter(array_map(static function($slug) {
            return sanitize_title(trim((string) $slug, " /\t\n\r\0\x0B"));
        }, (array) $protected_parts))));
        $out['protected_page_slugs'] = implode(',', array_slice($protected_parts, 0, 50));
        $out['registration_notification'] = in_array(($input['registration_notification'] ?? 'admin'), ['none','user','admin','both'], true) ? $input['registration_notification'] : 'admin';
        $out['blocked_social_roles'] = sanitize_text_field($input['blocked_social_roles'] ?? 'administrator,editor,shop_manager');
        $order = array_values(array_unique(array_filter(array_map('sanitize_key', preg_split('/[\s,]+/', (string) ($input['provider_order'] ?? 'google,microsoft'))))));
        $order = array_values(array_intersect($order, ['google','microsoft']));
        $out['provider_order'] = implode(',', $order ?: ['google','microsoft']);
        $out['google_redirect'] = esc_url_raw($input['google_redirect'] ?? '');
        $out['microsoft_redirect'] = esc_url_raw($input['microsoft_redirect'] ?? '');
        $role_lines = preg_split('/\r\n|\r|\n/', (string) ($input['role_redirects'] ?? ''));
        $clean_roles = [];
        foreach ((array) $role_lines as $line) {
            if (strpos($line, '=') === false) continue;
            list($role, $url) = array_map('trim', explode('=', $line, 2));
            $role = sanitize_key($role);
            $url = esc_url_raw($url);
            if ($role && $url) $clean_roles[] = $role . '=' . $url;
        }
        $out['role_redirects'] = implode("\n", $clean_roles);
        $out['button_layout'] = in_array(($input['button_layout'] ?? 'wide'), ['wide','row','icon'], true) ? $input['button_layout'] : 'wide';
        return $out;
    }

    private function settings() {
        $raw = wp_parse_args(get_option(self::OPTION, []), self::defaults());
        // Runtime fail-safe: a pre-6.9.16 maintenance value without an explicit
        // administrator marker is legacy state, not an authorization decision.
        // This keeps Google available even if the database is temporarily
        // read-only and the versioned persistence repair cannot be written yet.
        if (($raw['social_login_maintenance'] ?? 'no') === 'yes'
            && ($raw['social_login_maintenance_explicit'] ?? 'no') !== 'yes') {
            $raw['social_login_maintenance'] = 'no';
        }
        $raw['client_secret'] = DIP_Crypto::decrypt($raw['client_secret']);
        $raw['microsoft_client_secret'] = DIP_Crypto::decrypt($raw['microsoft_client_secret'] ?? '');
        return $raw;
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->settings();
        $callback = $this->callback_url();
        ?>
        <?php
        $health = class_exists('DIP_Operations') ? DIP_Operations::health($s) : [];
        $healthy = 0;
        foreach ($health as $check) { if (($check['status'] ?? '') === 'healthy') $healthy++; }
        $health_score = $health ? (int) round(($healthy / count($health)) * 100) : 0;
        $oauth_test = get_option('dip_oauth_test_v1', []);
        ?>
        <div class="wrap dip-admin-wrap">
          <section class="dip-admin-hero">
            <div><span class="dip-eyebrow">DELICAT IDENTITY PRO</span><h1>Connexion sociale & sécurité</h1><p>Gérez Google, Microsoft, la migration Nextend et la protection des comptes depuis un tableau de bord plus clair.</p></div>
            <div class="dip-hero-score"><small>Santé système</small><strong><?php echo esc_html((string) $health_score); ?>%</strong><span><?php echo $health_score >= 90 ? 'Excellent' : ($health_score >= 70 ? 'À vérifier' : 'Action requise'); ?></span></div>
          </section>
          <nav class="dip-admin-nav" aria-label="Navigation Delicat Identity">
            <a href="#dip-quick-start">Démarrage</a><a href="#dip-settings">Réglages</a><a href="#dip-redirects">Redirections</a><a href="#dip-security">Sécurité</a><a href="#dip-migration">Migration</a><a href="#dip-tools">Outils</a>
          </nav>
          <?php settings_errors(); ?>
          <section id="dip-quick-start" class="dip-quick-grid">
            <?php $google_state = $this->google_availability($s); ?>
            <article class="dip-quick-card"><span class="dip-status-dot <?php echo $google_state === 'ok' ? 'is-good' : ($google_state === 'secret_unreadable' ? 'is-bad' : 'is-warn'); ?>"></span><div><small>Google OAuth</small><strong><?php echo $google_state === 'ok' ? 'Configuré' : 'À corriger'; ?></strong><p><?php echo esc_html(self::google_availability_label($google_state)); ?></p><p>URI exacte : <code><?php echo esc_html($callback); ?></code></p></div></article>
            <article class="dip-quick-card"><span class="dip-status-dot <?php echo DIP_Migration::plugin_status() === 'active' ? 'is-info' : 'is-good'; ?>"></span><div><small>Migration Nextend</small><strong><?php echo DIP_Migration::plugin_status() === 'active' ? 'Mode coexistence' : 'Prêt'; ?></strong><p>Conservez Nextend pendant les tests clients.</p></div></article>
            <article class="dip-quick-card"><span class="dip-status-dot <?php echo DIP_Request::is_secure() ? 'is-good' : 'is-bad'; ?>"></span><div><small>HTTPS</small><strong><?php echo DIP_Request::is_secure() ? 'Actif' : 'Obligatoire'; ?></strong><p>OAuth et l’API mobile exigent une connexion chiffrée.</p></div></article>
          </section>
          <section class="dip-oauth-panel">
            <div><span class="dip-eyebrow">CONFIGURATION GOOGLE</span><h2>URI de redirection</h2><p>Ajoutez cette adresse dans Google Cloud Console → APIs & Services → Credentials → OAuth Client ID → Authorized redirect URIs.</p><div class="dip-copy-row"><code id="dip-callback-uri"><?php echo esc_html($callback); ?></code><button type="button" class="button" data-dip-copy="#dip-callback-uri">Copier</button></div></div>
            <div class="dip-oauth-actions"><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_oauth_test'), 'dip_oauth_test')); ?>">Tester la configuration Google</a><a class="button" target="_blank" rel="noopener noreferrer" href="https://console.cloud.google.com/apis/credentials">Ouvrir Google Cloud</a><?php if (is_array($oauth_test) && !empty($oauth_test['checked_at'])): ?><small>Dernier test : <?php echo esc_html(wp_date('Y-m-d H:i', (int)$oauth_test['checked_at'])); ?> — <?php echo !empty($oauth_test['ok']) ? 'Configuration locale valide' : 'Vérification requise'; ?></small><?php endif; ?></div>
          </section>
        <section class="dip-oauth-panel dip-native-setup">
          <div>
            <span class="dip-eyebrow">AUTHENTIFICATION UNIFIÉE — MODAL + IDENTITY</span>
            <h2>Connexion / inscription native unifiée</h2>
            <p>Le modal sécurisé est maintenant intégré directement à Delicat Identity. E-mail/mot de passe, Google, WooCommerce, appareils, sessions et journal de sécurité utilisent un seul moteur.</p>
            <div class="dip-copy-row"><code id="dip-account-shortcode">[delicat_login_button]</code><button type="button" class="button" data-dip-copy="#dip-account-shortcode">Copier</button></div>
            <div class="dip-copy-row"><code id="dip-panel-shortcode">[delicat_login_panel]</code><button type="button" class="button" data-dip-copy="#dip-panel-shortcode">Copier</button></div>
            <p><strong>Important :</strong> désactivez l’ancien snippet de modal dans Code Snippets pour éviter les doublons. Utilisez <code>[delicat_login_button]</code> ou un lien <code>#delicat-login</code>.</p>
          </div>
          <div class="dip-oauth-actions">
            <a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url($this->native_account_url($s)); ?>">Tester Mon compte</a>
          </div>
        </section>
        <div id="dip-settings" class="dip-section-anchor"></div>
        <form id="dip-settings-form" class="dip-settings-form" method="post" action="options.php"><?php settings_fields('dip_group'); ?>
        <table class="form-table" role="presentation">
          <tr><th>URI de redirection</th><td><code><?php echo esc_html($callback); ?></code></td></tr>
          <tr><th colspan="2"><h2>Connexion WordPress / WooCommerce + modal unifié</h2></th></tr>
          <tr><th>URL de connexion native</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[native_account_url]" value="<?php echo esc_attr($s['native_account_url']); ?>" placeholder="<?php echo esc_attr($this->native_account_url($s)); ?>"><p class="description">Laissez vide pour utiliser automatiquement la page Mon compte WooCommerce ou wp-login.php.</p></td></tr>
          <tr><th>Texte visiteur</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[native_login_text]" value="<?php echo esc_attr($s['native_login_text']); ?>"></td></tr>
          <tr><th>Texte utilisateur connecté</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[native_account_text]" value="<?php echo esc_attr($s['native_account_text']); ?>"></td></tr>
          <?php $this->checkbox_row('show_native_login_link','Afficher le lien e-mail/mot de passe dans [delicat_login_panel]',$s); ?>
          <?php $this->checkbox_row('native_modal_enabled','Activer le modal Delicat Identity',$s); ?>
          <tr><th>Redirection modal — connexion</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[native_login_redirect]" value="<?php echo esc_attr($s['native_login_redirect']); ?>" placeholder="/my-wallet/"></td></tr>
          <tr><th>Redirection modal — inscription</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[native_register_redirect]" value="<?php echo esc_attr($s['native_register_redirect']); ?>" placeholder="/my-wallet/"></td></tr>
          <tr><th>Pages privées</th><td><input class="large-text" name="<?php echo esc_attr(self::OPTION); ?>[protected_page_slugs]" value="<?php echo esc_attr($s['protected_page_slugs']); ?>" placeholder="my-wallet, mes-achats"><p class="description">Slugs séparés par des virgules. Les visiteurs non connectés sont redirigés vers Mon compte avant que le contenu soit rendu. Les onglets Identity, Security Center et Comptes liés sont protégés automatiquement.</p></td></tr>
          <?php $this->checkbox_row('strict_rest_firewall','Pare-feu REST Identity strict (refuser toute route non classifiée)',$s); ?>
          <?php $this->checkbox_row('two_factor_available','Autoriser l’authentification à deux facteurs TOTP pour les comptes',$s); ?>
          <?php $this->checkbox_row('passkeys_available','Autoriser les Passkeys / WebAuthn (Face ID, Touch ID, biométrie, clés de sécurité)',$s); ?>
          <tr><th>Passkeys par compte</th><td><input type="number" min="1" max="12" name="<?php echo esc_attr(self::OPTION); ?>[passkeys_max_per_user]" value="<?php echo esc_attr($s['passkeys_max_per_user'] ?? 8); ?>"><p class="description">Chaque Passkey est liée au domaine configuré, exige la vérification utilisateur et ne stocke dans WordPress que la clé publique.</p></td></tr>
          <tr><th>Ré-authentification sensible</th><td><label>Durée de confirmation <input type="number" min="5" max="60" name="<?php echo esc_attr(self::OPTION); ?>[sensitive_reauth_minutes]" value="<?php echo esc_attr($s['sensitive_reauth_minutes'] ?? 10); ?>"> minutes</label><p class="description">Après une connexion ou une confirmation d’identité réussie, les actions sensibles restent autorisées uniquement pendant cette fenêtre et uniquement dans la session courante.</p></td></tr>
          <tr><th>2FA administrateur</th><td><strong>Enrôlement recommandé avant obligation</strong><p class="description">La version 6.9.0 n’active pas automatiquement une obligation globale afin d’éviter le verrouillage de l’administrateur. Le Centre de sécurité signale chaque compte Administrateur sans 2FA.</p></td></tr>
          <tr><th>Outils globaux de sécurité</th><td><strong>Administrateur WordPress uniquement</strong><p class="description">Cette restriction est imposée par le moteur d’autorisation et ne peut pas être désactivée depuis le compte client.</p></td></tr>
          <tr><th>Sécurité du mot de passe</th><td><label>Tentatives avant verrouillage <input type="number" min="3" max="20" name="<?php echo esc_attr(self::OPTION); ?>[native_max_attempts]" value="<?php echo esc_attr($s['native_max_attempts']); ?>"></label> &nbsp; <label>Verrouillage initial (min) <input type="number" min="5" max="60" name="<?php echo esc_attr(self::OPTION); ?>[native_lockout_base]" value="<?php echo esc_attr($s['native_lockout_base']); ?>"></label> &nbsp; <label>Maximum (min) <input type="number" min="5" max="1440" name="<?php echo esc_attr(self::OPTION); ?>[native_lockout_max]" value="<?php echo esc_attr($s['native_lockout_max']); ?>"></label><p class="description">Progression par défaut : 15 → 30 → 60 → 120 minutes.</p></td></tr>
          <tr><th>Inscription native</th><td><label>Maximum / IP / heure <input type="number" min="1" max="20" name="<?php echo esc_attr(self::OPTION); ?>[native_reg_max_per_hour]" value="<?php echo esc_attr($s['native_reg_max_per_hour']); ?>"></label> &nbsp; <label>Mot de passe min. <input type="number" min="8" max="64" name="<?php echo esc_attr(self::OPTION); ?>[native_password_min]" value="<?php echo esc_attr($s['native_password_min']); ?>"></label> &nbsp; <label>max. <input type="number" min="8" max="256" name="<?php echo esc_attr(self::OPTION); ?>[native_password_max]" value="<?php echo esc_attr($s['native_password_max']); ?>"></label></td></tr>
          <tr id="dip-redirects"><th colspan="2"><h2>Redirections après authentification</h2><p class="description">Ces destinations sont utilisées uniquement lorsqu’aucune page de départ sûre (par exemple le paiement WooCommerce) ne doit être restaurée. Le plugin refuse toujours wp-login.php, wp-admin, déconnexion et réinitialisation de mot de passe après une connexion réussie.</p></th></tr>
          <tr><th>Après connexion</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[redirect_login]" value="<?php echo esc_attr($s['redirect_login']); ?>" placeholder="/my-account/"><p class="description">Recommandé : <code>/my-account/</code> ou <code>/my-wallet/</code>. Une URL interne complète est aussi acceptée.</p><p><button type="button" class="button dip-redirect-preset" data-target="redirect_login" data-value="/my-account/">Mon compte</button> <button type="button" class="button dip-redirect-preset" data-target="redirect_login" data-value="/my-wallet/">Portefeuille</button> <button type="button" class="button dip-redirect-preset" data-target="redirect_login" data-value="/">Accueil</button></p></td></tr>
          <tr><th>Après inscription</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[redirect_register]" value="<?php echo esc_attr($s['redirect_register']); ?>" placeholder="/my-account/"><p class="description">Destination des nouveaux clients créés par Google ou Microsoft.</p><p><button type="button" class="button dip-redirect-preset" data-target="redirect_register" data-value="/my-account/">Mon compte</button> <button type="button" class="button dip-redirect-preset" data-target="redirect_register" data-value="/my-wallet/">Portefeuille</button> <button type="button" class="button dip-redirect-preset" data-target="redirect_register" data-value="/">Accueil</button></p></td></tr>
          <tr><th>Priorité intelligente</th><td><p><strong>Activée automatiquement :</strong> paiement → retour au paiement; lien explicite sûr → retour demandé; sinon → destination configurée ci-dessus.</p></td></tr>
          <tr><th>Client ID Web / serveur</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[client_id]" value="<?php echo esc_attr($s['client_id']); ?>"><p class="description">Client OAuth de type Application Web. Il est l’audience serveur unique des jetons Google envoyés par l’application.</p></td></tr>
          <tr><th>Android Client ID</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[android_client_id]" value="<?php echo esc_attr($s['android_client_id']); ?>"><p class="description">Client OAuth Android exact pour com.delicatstore.app et l’empreinte SHA de signature publiée.</p></td></tr>
          <tr><th>iOS Client ID</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[ios_client_id]" value="<?php echo esc_attr($s['ios_client_id']); ?>"><p class="description">OAuth Client ID iOS autorisé pour l’application native.</p></td></tr>
          <tr><th>Client Secret</th><td><input class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION); ?>[client_secret]" value="<?php echo $s['client_secret'] ? '********' : ''; ?>"><p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[clear_client_secret]" value="1"> Supprimer le secret Google enregistré</label></p><p class="description">Laissez ******** ou le champ vide pour conserver le secret actuel. La suppression désactive Google jusqu’à l’enregistrement d’un nouveau secret.</p></td></tr>
          <tr><th colspan="2"><h2>Microsoft Entra ID / Microsoft Account</h2></th></tr>
          <?php $this->checkbox_row('microsoft_enabled','Activer Microsoft Login',$s); ?>
          <tr><th>Liaison Microsoft par e-mail</th><td><strong>Désactivée pour la sécurité</strong><p class="description">Microsoft email/preferred_username peut changer. La liaison utilise l’identifiant immuable du fournisseur; une nouvelle adresse locale doit être vérifiée par Delicat.</p></td></tr>
          <tr><th>Microsoft Client ID</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[microsoft_client_id]" value="<?php echo esc_attr($s['microsoft_client_id']); ?>"></td></tr>
          <tr><th>Microsoft Client Secret</th><td><input class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION); ?>[microsoft_client_secret]" value="<?php echo $s['microsoft_client_secret'] ? '********' : ''; ?>"><p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[clear_microsoft_client_secret]" value="1"> Supprimer le secret Microsoft enregistré</label></p><p class="description">Laissez ******** ou le champ vide pour conserver le secret actuel.</p></td></tr>
          <tr><th>Microsoft Tenant</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[microsoft_tenant]" value="<?php echo esc_attr($s['microsoft_tenant']); ?>"><p class="description">Utilisez common, organizations, consumers ou un Tenant ID précis.</p></td></tr>
          <tr><th>Texte Microsoft</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[microsoft_button_text]" value="<?php echo esc_attr($s['microsoft_button_text']); ?>"></td></tr>
          <tr><th colspan="2"><h2>Politiques de sécurité adaptatives</h2></th></tr>
          <?php $this->checkbox_row('adaptive_risk_blocking','Bloquer automatiquement les connexions à risque élevé',$s); ?>
          <tr><th>Seuil de risque</th><td><input type="number" min="10" max="100" name="<?php echo esc_attr(self::OPTION); ?>[risk_block_threshold]" value="<?php echo esc_attr($s['risk_block_threshold']); ?>"> / 100</td></tr>
          <tr><th>Domaines e-mail autorisés</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[allowed_email_domains]" value="<?php echo esc_attr($s['allowed_email_domains']); ?>"><p class="description">Séparez par virgules. Laissez vide pour autoriser tous les domaines non bloqués.</p></td></tr>
          <tr><th>Domaines e-mail bloqués</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[blocked_email_domains]" value="<?php echo esc_attr($s['blocked_email_domains']); ?>"></td></tr>
          <tr><th>Politique pays</th><td><select name="<?php echo esc_attr(self::OPTION); ?>[country_policy_mode]"><option value="off" <?php selected($s['country_policy_mode'],'off'); ?>>Désactivée</option><option value="allow" <?php selected($s['country_policy_mode'],'allow'); ?>>Autoriser seulement</option><option value="deny" <?php selected($s['country_policy_mode'],'deny'); ?>>Bloquer</option></select> <input name="<?php echo esc_attr(self::OPTION); ?>[country_codes]" value="<?php echo esc_attr($s['country_codes']); ?>" placeholder="HT,DO,US"><p class="description">Utilise CF-IPCountry uniquement si la confiance Cloudflare est explicitement activée dans wp-config.php avec <code>DIP_TRUST_CLOUDFLARE_HEADERS</code> (ou <code>DIP_TRUST_CLOUDFLARE_CONNECTING_IP</code>). L’origine doit rester inaccessible directement afin que ces en-têtes ne puissent pas être falsifiés.</p></td></tr>
          <?php $this->checkbox_row('country_header_required','Refuser si le pays ne peut pas être vérifié',$s); ?>
          <?php $this->checkbox_row('require_new_user_approval','Exiger l’approbation admin pour les nouveaux comptes sociaux',$s); ?>
          <tr><th>Rôles privilégiés non-admin</th><td><strong>Blocage social imposé</strong><input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[block_privileged_social_login]" value="1"><p class="description">Editors, shop managers et autres rôles privilégiés ne peuvent pas désactiver cette protection depuis les réglages.</p></td></tr>
          <?php $this->checkbox_row('admin_google_secure_mode','Autoriser Google Secure Mode uniquement pour les Administrateurs explicitement approuvés + TOTP',$s); ?>
          <tr><th>Google Administrateur</th><td><p class="description"><strong>Politique renforcée :</strong> une correspondance d’e-mail Google ne peut jamais ouvrir un compte Administrateur. L’identité Google doit être liée depuis une session Administrateur déjà authentifiée, explicitement approuvée, puis chaque connexion Google exige TOTP avant l’émission du cookie WordPress. Microsoft et les autres connexions sociales restent bloqués pour les Administrateurs.</p></td></tr>
          <?php $this->checkbox_row('social_login_maintenance','Mode urgence des connexions sociales (masquer Google/Microsoft)',$s); ?>
          <tr><th></th><td><p class="description">Utilisez uniquement ce commutateur pour une interruption volontaire d’OAuth. La maintenance visuelle du site dans Delicat Builder ne désactive pas automatiquement Google.</p></td></tr>
          <?php $this->checkbox_row('health_email','Alerte e-mail si la santé système devient critique',$s); ?>
          <?php $this->checkbox_row('enabled','Activer Google Login',$s); ?>
          <?php $this->checkbox_row('allow_registration','Créer les nouveaux clients',$s); ?>
          <?php $this->checkbox_row('link_existing_email','Relier automatiquement un client existant par email Google vérifié',$s); ?>
          <?php $this->checkbox_row('remember_login','Garder la session connectée',$s); ?>
          <?php $this->checkbox_row('auto_wp_login','Afficher sur wp-login.php',$s); ?>
          <?php $this->checkbox_row('auto_my_account','Afficher sur WooCommerce Mon compte',$s); ?>
          <?php $this->checkbox_row('auto_checkout','Afficher au paiement WooCommerce',$s); ?>
          <?php $this->checkbox_row('use_google_avatar','Utiliser la photo Google',$s); ?>
          <?php $this->checkbox_row('security_log_enabled','Activer le journal de sécurité',$s); ?>
          <?php $this->checkbox_row('lockout_enabled','Activer le verrouillage temporaire',$s); ?>
          <?php $this->checkbox_row('trusted_devices_enabled','Activer le suivi des appareils',$s); ?>
          <?php $this->checkbox_row('new_device_email','Envoyer une alerte lors d’un nouvel appareil',$s); ?>
          <?php $this->checkbox_row('mobile_api_enabled','Activer l’API mobile sécurisée (HTTPS obligatoire)',$s); ?>
          <?php $this->checkbox_row('mobile_push_enabled','Activer l’enregistrement sécurisé des notifications push',$s); ?>
          <?php $this->checkbox_row('mobile_biometric_enabled','Activer la connexion biométrique liée à l’appareil',$s); ?>
          <tr><th>Durée jeton mobile</th><td><input type="number" min="300" max="3600" name="<?php echo esc_attr(self::OPTION); ?>[mobile_access_ttl]" value="<?php echo esc_attr($s['mobile_access_ttl']); ?>"> secondes</td></tr>
          <tr><th>Durée actualisation mobile</th><td><input type="number" min="86400" max="7776000" name="<?php echo esc_attr(self::OPTION); ?>[mobile_refresh_ttl]" value="<?php echo esc_attr($s['mobile_refresh_ttl']); ?>"> secondes</td></tr>
          <?php $this->checkbox_row('provider_framework_enabled','Activer l’architecture modulaire des fournisseurs',$s); ?>
          <tr><th>Texte du bouton</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[button_text]" value="<?php echo esc_attr($s['button_text']); ?>"></td></tr>
          <tr><th>Rôle des nouveaux comptes</th><td><select name="<?php echo esc_attr(self::OPTION); ?>[default_role]"><?php foreach (wp_roles()->roles as $key=>$role) { if (class_exists('DIP_Account_Sync') && DIP_Account_Sync::safe_registration_role($key) !== sanitize_key($key)) continue; echo '<option value="'.esc_attr($key).'" '.selected($s['default_role'],$key,false).'>'.esc_html($role['name']).'</option>'; } ?></select><p class="description">Seuls les rôles client sans capacités d’administration, de publication ou de gestion sont proposés.</p></td></tr>
          <tr><th>Limitation</th><td><input type="number" min="3" max="50" name="<?php echo esc_attr(self::OPTION); ?>[rate_limit]" value="<?php echo esc_attr($s['rate_limit']); ?>"> tentatives / <input type="number" min="5" max="60" name="<?php echo esc_attr(self::OPTION); ?>[rate_window]" value="<?php echo esc_attr($s['rate_window']); ?>"> minutes</td></tr>
          <tr><th>Verrouillage</th><td>Après <input type="number" min="3" max="25" name="<?php echo esc_attr(self::OPTION); ?>[lockout_threshold]" value="<?php echo esc_attr($s['lockout_threshold']); ?>"> échecs dans <input type="number" min="5" max="60" name="<?php echo esc_attr(self::OPTION); ?>[lockout_window]" value="<?php echo esc_attr($s['lockout_window']); ?>"> minutes, bloquer pendant <input type="number" min="5" max="120" name="<?php echo esc_attr(self::OPTION); ?>[lockout_duration]" value="<?php echo esc_attr($s['lockout_duration']); ?>"> minutes.</td></tr>
          <tr><th>Rétention du journal</th><td><input type="number" min="7" max="180" name="<?php echo esc_attr(self::OPTION); ?>[security_retention_days]" value="<?php echo esc_attr($s['security_retention_days']); ?>"> jours</td></tr>
          <tr><th>Rétention des appareils</th><td><input type="number" min="30" max="365" name="<?php echo esc_attr(self::OPTION); ?>[device_retention_days]" value="<?php echo esc_attr($s['device_retention_days']); ?>"> jours pour les appareils non fiables</td></tr>
        </table>
        <section class="dip-builder-card">
          <div class="dip-builder-head"><div><span>PHASE 5</span><h2>Studio visuel de connexion</h2><p>Personnalisez le bouton sans modifier le moteur OAuth.</p></div><strong>Prévisualisation en direct</strong></div>
          <div class="dip-builder-grid">
            <div class="dip-builder-controls">
              <label>Thème<select data-dip-control="theme" name="<?php echo esc_attr(self::OPTION); ?>[button_theme]"><option value="light" <?php selected($s['button_theme'],'light'); ?>>Clair</option><option value="dark" <?php selected($s['button_theme'],'dark'); ?>>Sombre</option><option value="brand" <?php selected($s['button_theme'],'brand'); ?>>Bleu Google</option><option value="custom" <?php selected($s['button_theme'],'custom'); ?>>Personnalisé</option></select></label>
              <div class="dip-two"><label>Largeur <input data-dip-control="width" type="range" min="40" max="100" name="<?php echo esc_attr(self::OPTION); ?>[button_width]" value="<?php echo esc_attr($s['button_width']); ?>"></label><label>Hauteur <input data-dip-control="height" type="range" min="40" max="72" name="<?php echo esc_attr(self::OPTION); ?>[button_height]" value="<?php echo esc_attr($s['button_height']); ?>"></label></div>
              <div class="dip-two"><label>Arrondi <input data-dip-control="radius" type="range" min="0" max="36" name="<?php echo esc_attr(self::OPTION); ?>[button_radius]" value="<?php echo esc_attr($s['button_radius']); ?>"></label><label>Taille du texte <input data-dip-control="font" type="range" min="12" max="22" name="<?php echo esc_attr(self::OPTION); ?>[button_font_size]" value="<?php echo esc_attr($s['button_font_size']); ?>"></label></div>
              <div class="dip-two"><label>Alignement<select data-dip-control="align" name="<?php echo esc_attr(self::OPTION); ?>[button_align]"><?php foreach(['stretch'=>'Pleine largeur','left'=>'Gauche','center'=>'Centre','right'=>'Droite'] as $v=>$l): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($s['button_align'],$v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label><label>Ombre<select data-dip-control="shadow" name="<?php echo esc_attr(self::OPTION); ?>[button_shadow]"><?php foreach(['none'=>'Aucune','soft'=>'Douce','strong'=>'Forte'] as $v=>$l): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($s['button_shadow'],$v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label></div>
              <div class="dip-two"><label>Animation<select data-dip-control="motion" name="<?php echo esc_attr(self::OPTION); ?>[button_motion]"><?php foreach(['none'=>'Aucune','lift'=>'Élévation','pulse'=>'Pulsation légère'] as $v=>$l): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($s['button_motion'],$v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label><label>Graisse<select data-dip-control="weight" name="<?php echo esc_attr(self::OPTION); ?>[button_weight]"><?php foreach([500,600,700,800] as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected((int)$s['button_weight'],$v); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label></div>
              <div class="dip-three"><label>Fond<input data-dip-control="bg" type="color" name="<?php echo esc_attr(self::OPTION); ?>[button_bg]" value="<?php echo esc_attr($s['button_bg']); ?>"></label><label>Texte<input data-dip-control="color" type="color" name="<?php echo esc_attr(self::OPTION); ?>[button_color]" value="<?php echo esc_attr($s['button_color']); ?>"></label><label>Bordure<input data-dip-control="border" type="color" name="<?php echo esc_attr(self::OPTION); ?>[button_border]" value="<?php echo esc_attr($s['button_border']); ?>"></label></div>
              <div class="dip-two"><label>Texte wp-login<input data-dip-control="text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[wp_login_text]" value="<?php echo esc_attr($s['wp_login_text']); ?>" placeholder="<?php echo esc_attr($s['button_text']); ?>"></label><label>Texte Mon compte<input type="text" name="<?php echo esc_attr(self::OPTION); ?>[account_text]" value="<?php echo esc_attr($s['account_text']); ?>" placeholder="<?php echo esc_attr($s['button_text']); ?>"></label></div>
              <label>Texte paiement<input type="text" name="<?php echo esc_attr(self::OPTION); ?>[checkout_text]" value="<?php echo esc_attr($s['checkout_text']); ?>" placeholder="Paiement rapide avec Google"></label>
              <label class="dip-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_divider]" value="1" <?php checked($s['show_divider'],'yes'); ?>> Afficher le séparateur « ou »</label>
              <label class="dip-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[mobile_full_width]" value="1" <?php checked($s['mobile_full_width'],'yes'); ?>> Pleine largeur sur mobile</label>
            </div>
            <div class="dip-preview-panel"><span>Aperçu</span><div id="dip-preview-wrap" class="dip-preview-align-<?php echo esc_attr($s['button_align']); ?>"><a id="dip-preview-button" class="dip-preview-button dip-theme-<?php echo esc_attr($s['button_theme']); ?> dip-shadow-<?php echo esc_attr($s['button_shadow']); ?> dip-motion-<?php echo esc_attr($s['button_motion']); ?>"><b>G</b><em><?php echo esc_html($s['wp_login_text'] ?: $s['button_text']); ?></em></a></div><small>Les couleurs personnalisées s’appliquent au thème Personnalisé. Les animations sont automatiquement désactivées lorsque l’utilisateur préfère réduire les mouvements.</small></div>
          </div>
        </section>

        <section class="dip-builder-card" style="margin-top:24px">
          <div class="dip-builder-head"><div><span>PARITÉ NEXTEND</span><h2>Fonctions de connexion sociale</h2><p>Active les fonctions Nextend les plus utiles pour Google et Microsoft dans le même moteur Identity.</p></div></div>
          <table class="form-table" role="presentation">
            <tr><th>Emplacements automatiques</th><td>
              <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[auto_comments]" value="1" <?php checked($s['auto_comments'],'yes'); ?>> Formulaire de commentaires</label><br>
              <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[auto_lost_password]" value="1" <?php checked($s['auto_lost_password'],'yes'); ?>> Mot de passe perdu</label>
            </td></tr>
            <tr><th>Compatibilité cache</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[bypass_cache_redirect]" value="1" <?php checked($s['bypass_cache_redirect'],'yes'); ?>> Ajouter une clé anti-cache aux redirections OAuth</label></td></tr>
            <tr><th>Sélecteur de compte Google</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[google_prompt_select_account]" value="1" <?php checked($s['google_prompt_select_account'],'yes'); ?>> Demander à Google de choisir un compte à chaque nouvelle connexion</label></td></tr>
            <tr><th>Synchronisation profil</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[sync_profile_name]" value="1" <?php checked($s['sync_profile_name'],'yes'); ?>> Synchroniser prénom et nom</label></td></tr>
            <tr><th>Rôles bloqués</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[blocked_social_roles]" value="<?php echo esc_attr($s['blocked_social_roles']); ?>"><p class="description">Séparés par des virgules. Recommandé : administrator,editor,shop_manager</p></td></tr>
            <tr><th>Notification inscription</th><td><select name="<?php echo esc_attr(self::OPTION); ?>[registration_notification]"><option value="none" <?php selected($s['registration_notification'],'none'); ?>>Aucune</option><option value="user" <?php selected($s['registration_notification'],'user'); ?>>Utilisateur</option><option value="admin" <?php selected($s['registration_notification'],'admin'); ?>>Administrateur</option><option value="both" <?php selected($s['registration_notification'],'both'); ?>>Utilisateur et administrateur</option></select></td></tr>
            <tr><th>Disposition des boutons</th><td><select name="<?php echo esc_attr(self::OPTION); ?>[button_layout]"><option value="wide" <?php selected($s['button_layout'],'wide'); ?>>Bouton large</option><option value="row" <?php selected($s['button_layout'],'row'); ?>>Ligne compacte</option><option value="icon" <?php selected($s['button_layout'],'icon'); ?>>Icône compacte</option></select></td></tr>
            <tr><th>Ordre des fournisseurs</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[provider_order]" value="<?php echo esc_attr($s['provider_order']); ?>"><p class="description">Exemple : <code>google,microsoft</code>.</p></td></tr>
            <tr><th>Redirection Google</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[google_redirect]" value="<?php echo esc_attr($s['google_redirect']); ?>" placeholder="/my-wallet/"><p class="description">Optionnelle. Prioritaire après une connexion Google hors checkout.</p></td></tr>
            <tr><th>Redirection Microsoft</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[microsoft_redirect]" value="<?php echo esc_attr($s['microsoft_redirect']); ?>" placeholder="/my-account/"></td></tr>
            <tr><th>Redirections par rôle</th><td><textarea class="large-text code" rows="4" name="<?php echo esc_attr(self::OPTION); ?>[role_redirects]"><?php echo esc_textarea($s['role_redirects']); ?></textarea><p class="description">Une règle par ligne, par exemple <code>customer=/my-wallet/</code>. Les règles de rôle sont prioritaires sur les redirections fournisseur.</p></td></tr>
            <tr><th>Utilisateurs connectés</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[hide_social_for_logged_in]" value="1" <?php checked($s['hide_social_for_logged_in'],'yes'); ?>> Masquer les boutons de connexion sociale, sauf pendant une liaison explicite</label></td></tr>
          </table>
          <p><strong>Shortcodes :</strong> <code>[delicat_social_login_buttons providers="google,microsoft" trackerdata="checkout"]</code> · <code>[delicat_social_login_link provider="google"]</code></p>
        </section>
        <div class="dip-save-bar"><div><strong>Modifications de configuration</strong><span>Enregistrez avant de tester les fournisseurs ou les redirections.</span></div><?php submit_button('Enregistrer les réglages', 'primary', 'submit', false); ?></div></form>
        <div id="dip-tools" class="dip-section-anchor"></div><?php $this->render_performance_center($s); ?>
        <?php $this->render_sdk_center(); ?>
        <?php $this->render_assistant_center($s); ?>
        <?php $this->render_operations_dashboard($s); ?>
        <?php $this->render_provider_center($s); ?>
        <div id="dip-security" class="dip-section-anchor"></div><?php $this->render_security_center($s); ?>
        <?php $this->render_phase6($s); ?>
        <div id="dip-migration" class="dip-section-anchor"></div><?php $this->render_migration_assistant(); ?>
        </div><?php
    }

    private function checkbox_row($key, $label, $s) {
        echo '<tr><th>' . esc_html($label) . '</th><td><label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[' . esc_attr($key) . ']" value="1" ' . checked($s[$key], 'yes', false) . '> Oui</label></td></tr>';
    }

    public function route() {
        $action = isset($_GET['dip_action']) ? sanitize_key(wp_unslash($_GET['dip_action'])) : '';
        if (!$action) return;
        $settings = $this->settings();
        if (($settings['social_login_maintenance'] ?? 'no') === 'yes' && in_array($action, ['login','callback'], true) && !current_user_can('manage_options')) {
            // A stale page cache can still contain a Google link after an
            // administrator enables emergency maintenance. Return modal users
            // to the page they started from instead of unexpectedly dropping
            // them on wp-login.php.
            if ($action === 'login') {
                $this->failure_tracker = substr(sanitize_text_field(wp_unslash($_GET['tracker'] ?? '')), 0, 100);
                $this->failure_return_url = $this->safe_redirect($_GET['redirect'] ?? '');
                $this->fail('La connexion sociale est temporairement en maintenance.', 'maintenance', false);
            }
            $this->recover_failure_context(sanitize_text_field(wp_unslash($_GET['state'] ?? '')));
            $this->fail('La connexion sociale est temporairement en maintenance.', 'maintenance', false);
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        if ($action === 'login') $this->start_login();
        if ($action === 'callback') $this->callback();
    }

    private function start_login() {
        $s = $this->settings();
        $this->failure_tracker = substr(sanitize_text_field(wp_unslash($_GET['tracker'] ?? '')), 0, 100);
        $this->failure_return_url = $this->safe_redirect($_GET['redirect'] ?? '');
        $provider_id = sanitize_key(wp_unslash($_GET['provider'] ?? 'google'));
        $provider = $this->provider($provider_id, $s);
        if (!$provider || !$provider->is_configured($s)) $this->fail('Ce fournisseur de connexion n’est pas configuré.', 'not_configured');
        if ($s['lockout_enabled'] === 'yes' && DIP_Lockout::is_locked()) $this->fail('Connexion temporairement bloquée. Réessayez plus tard.', 'temporarily_locked', false);
        if (!$this->rate_limit()) $this->fail('Trop de tentatives. Réessayez plus tard.', 'rate_limited');
        $link = is_user_logged_in() && !empty($_GET['link']);
        if ($link) {
            $nonce = sanitize_text_field(wp_unslash($_GET['_dip_nonce'] ?? ''));
            if (!wp_verify_nonce($nonce, 'dip_link_' . get_current_user_id())) $this->fail('Demande de liaison invalide.', 'invalid_link_nonce');
        } elseif (is_user_logged_in()) {
            wp_safe_redirect($this->safe_redirect($_GET['redirect'] ?? ''));
            exit;
        }
        $tracker = sanitize_text_field(wp_unslash($_GET['tracker'] ?? ''));
        $flow = DIP_Flow_Store::create($this->safe_redirect($_GET['redirect'] ?? ''), $link ? get_current_user_id() : 0, $provider_id, $tracker);
        $authorization_url = $this->trusted_authorization_url($provider->authorization_url($flow), $provider_id, $provider);
        if (is_wp_error($authorization_url)) {
            $this->fail($authorization_url->get_error_message(), $authorization_url->get_error_code(), false);
        }
        wp_redirect($authorization_url, 302, 'Delicat Identity');
        exit;
    }

    private function callback() {
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        $error = sanitize_key(wp_unslash($_GET['error'] ?? ''));
        // OAuth cancellation/error callbacks must still consume and validate the
        // one-time state. Unsolicited callback URLs must never increment the
        // customer's password/social lockout bucket.
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) {
            $this->fail('Réponse du fournisseur incomplète ou invalide.', 'missing_callback_data', false);
        }
        // Recover the storefront tracker/return before consume(). If an Android
        // custom tab has not exposed the binding cookie yet, fail() can still
        // return the customer to the Delicat modal instead of wp-login.php.
        $this->recover_failure_context($state);
        $flow = DIP_Flow_Store::consume($state);
        if (is_wp_error($flow)) $this->fail($flow->get_error_message(), $flow->get_error_code(), false);
        $this->failure_tracker = substr(sanitize_text_field((string) ($flow['tracker'] ?? '')), 0, 100);
        $this->failure_return_url = $this->safe_redirect($flow['redirect'] ?? '');
        if ($error) $this->fail('Connexion sociale annulée ou refusée.', 'access_denied', false);
        if ($code === '' || strlen($code) > 4096) {
            $this->fail('Réponse du fournisseur incomplète ou invalide.', 'missing_callback_data', false);
        }
        $s = $this->settings();
        $provider_id = sanitize_key($flow['provider'] ?? 'google');
        $provider = $this->provider($provider_id, $s);
        if (!$provider) $this->fail('Fournisseur d’identité indisponible.', 'provider_unavailable');
        $profile = $provider->authenticate($code, $flow);
        if (is_array($profile) && !empty($flow['tracker'])) $profile['_tracker'] = sanitize_text_field((string) $flow['tracker']);
        if (is_wp_error($profile)) $this->fail($profile->get_error_message(), $profile->get_error_code());
        $policy = DIP_Policy::enforce_profile($profile, $s);
        if (is_wp_error($policy)) $this->fail($policy->get_error_message(), $policy->get_error_code());
        $identity = new DIP_Identity($s);
        $user_id = $identity->resolve($profile, (int) ($flow['link_user'] ?? 0));
        if (is_wp_error($user_id)) $this->fail($user_id->get_error_message(), $user_id->get_error_code());
        $is_admin = class_exists('DIP_Privileged_Social') && DIP_Privileged_Social::is_admin($user_id);
        if ($is_admin) {
            $admin_policy = DIP_Privileged_Social::validate_admin_google_login($user_id, $provider_id, $profile);
            if (is_wp_error($admin_policy)) $this->fail($admin_policy->get_error_message(), $admin_policy->get_error_code(), false);
        } elseif ($this->is_social_role_blocked($user_id, $s)) {
            $sub = sanitize_text_field((string)($profile['sub'] ?? ''));
            $legacy_ok = $provider_id === 'google'
                && class_exists('DIP_Privileged_Social')
                && DIP_Privileged_Social::legacy_continuity_approved($user_id, $sub);
            if (!$legacy_ok) $this->fail('Ce rôle privilégié ne peut pas utiliser une connexion sociale.', 'privileged_social_login_blocked');
        }

        // Linking a provider is a configuration action, not a login. Preserve
        // the already-authenticated session and never issue a new auth cookie
        // from the OAuth callback merely because the link succeeded.
        $link_user = absint($flow['link_user'] ?? 0);
        if ($link_user) {
            if (!is_user_logged_in() || get_current_user_id() !== $link_user || $user_id !== $link_user) {
                $this->fail('La session utilisée pour relier ce fournisseur n’est plus valide.', 'link_session_mismatch', false);
            }
            if ($is_admin && $provider_id === 'google' && class_exists('DIP_Privileged_Social')) {
                $sub = sanitize_text_field((string)($profile['sub'] ?? ''));
                if (!DIP_Privileged_Social::approved($user_id, $sub) && !DIP_Privileged_Social::approve_link($user_id, $sub, 'oauth_link')) {
                    $this->fail('Impossible d’autoriser cette identité Google pour le compte Administrateur.', 'admin_google_link_authorization_failed', false);
                }
            }
            if (class_exists('DIP_Audit')) DIP_Audit::record('provider_link_completed', 'notice', $user_id, ['provider'=>$provider_id]);
            $link_destination = $this->post_login_redirect((string)($flow['redirect'] ?? ''), $this->native_account_url($s));
            $link_destination = $link_destination ?: $this->native_account_url($s);
            if ($is_admin && $provider_id === 'google') $link_destination = add_query_arg('dip_admin_google_status', 'authorized', $link_destination);
            wp_safe_redirect($link_destination);
            exit;
        }

        $guard = class_exists('DIP_Account_Sync') ? DIP_Account_Sync::login_guard($user_id) : true;
        if (is_wp_error($guard)) {
            $this->fail($guard->get_error_message(), $guard->get_error_code(), false);
        }
        $risk = DIP_Policy::assess($user_id, $profile, $s);
        if (is_wp_error($risk)) $this->fail($risk->get_error_message(), $risk->get_error_code());

        $new = !empty($profile['_new_user']);
        $fallback = $this->native_account_url($s);
        // A social login launched inside the native modal follows the exact same
        // success destinations as the email/password modal. The flow redirect is
        // retained as the safe page to return to when OAuth itself fails.
        if ($this->is_native_modal_tracker($flow['tracker'] ?? '')) {
            $configured = $this->safe_redirect($new ? ($s['native_register_redirect'] ?? '') : ($s['native_login_redirect'] ?? ''));
        } else {
            $configured = $this->pro_redirect_for_user($user_id, $provider_id, $new, $s);
        }
        $flow_redirect = (string) ($flow['redirect'] ?? '');

        // Preserve a meaningful customer destination (especially checkout).
        // Unsafe WordPress authentication/admin URLs are discarded by post_login_redirect().
        $headless_google_flow = (bool) preg_match('/^headless_[a-f0-9]{64}$/D', (string) ($flow['tracker'] ?? ''));
        if ((!$new || $headless_google_flow) && $flow_redirect !== '') {
            $flow_destination = $this->post_login_redirect($flow_redirect, '');
            $destination = $flow_destination !== '' ? $flow_destination : ($configured ?: $fallback);
        } else {
            $destination = $configured ?: $flow_redirect ?: $fallback;
        }
        $destination = $this->post_login_redirect($destination, $configured ?: $fallback);
        if (!$headless_google_flow) {
            $destination = remove_query_arg('dip_auth_sync', $destination);
            $destination = add_query_arg('dip_auth_sync', '1', $destination);
        }

        // Accounts with TOTP enabled complete a browser-bound second-factor
        // challenge before any WordPress authentication cookie is created.
        if (class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user_id)) {
            $challenge = DIP_Two_Factor::begin_web_challenge(
                $user_id,
                $destination,
                $s['remember_login'] === 'yes',
                sanitize_key($provider_id . '_social')
            );
            if ($challenge !== '') {
                wp_safe_redirect($challenge);
                exit;
            }
            $this->fail("Impossible d'initialiser la vérification à deux facteurs.", 'two_factor_challenge_failed', false);
        }

        wp_clear_auth_cookie();
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, $s['remember_login'] === 'yes', DIP_Request::is_secure());
        $user = get_userdata($user_id);
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::fire_wp_login($user, sanitize_key($provider_id . '_social'));
        else do_action('wp_login', $user->user_login, $user);
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_login($user_id);
        do_action('dip_login_success', $user_id, $profile);
        $this->event('login_success', $user_id, 'info', ['provider' => sanitize_key($flow['provider'] ?? 'google')]);
        DIP_Lockout::clear_failures();
        wp_safe_redirect($destination);
        exit;
    }

    private function rate_limit() {
        $s = $this->settings();
        $address = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::client_ip() : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $fingerprint = substr(hash_hmac('sha256', $address, wp_salt('nonce')), 0, 32);
        $key = 'dip_rate_' . $fingerprint;
        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, (int) $s['rate_window'] * MINUTE_IN_SECONDS);
        return $count <= (int) $s['rate_limit'];
    }

    private function recover_failure_context($state) {
        if (!class_exists('DIP_Flow_Store') || !is_callable(['DIP_Flow_Store', 'recovery_context'])) return;
        $context = DIP_Flow_Store::recovery_context((string) $state);
        if (!is_array($context)) return;
        $this->failure_tracker = substr(sanitize_text_field((string) ($context['tracker'] ?? '')), 0, 100);
        $this->failure_return_url = $this->safe_redirect($context['redirect'] ?? '');
    }

    private function event($name, $user_id = 0, $severity = 'info', array $context = []) {
        $s = $this->settings();
        if (($s['security_log_enabled'] ?? 'yes') === 'yes') DIP_Audit::record($name, $severity, $user_id, $context);
    }

    /**
     * 6.9.10 compatibility: 6.9.9's lazy modal emitted identity_modal_v3
     * while the OAuth callback only recognized native_modal. Accept the old
     * tracker values so in-flight/cached pages recover safely, and emit only
     * native_modal from new markup.
     */
    private function is_native_modal_tracker($tracker) {
        $tracker = sanitize_key((string) $tracker);
        return in_array($tracker, ['native_modal', 'identity_modal', 'identity_modal_v3', 'identity_modal_v4'], true)
            || (bool) preg_match('/^headless_[a-f0-9]{64}$/D', $tracker);
    }

    private function fail($message, $code, $count_failure = true) {
        $s = $this->settings();
        $code = sanitize_key($code) ?: 'authentication_failed';
        $severity = in_array($code, ['rate_limited','temporarily_locked','duplicate_google_identity','browser_mismatch','browser_binding_failed','admin_google_identity_mismatch','admin_google_not_authorized','admin_google_two_factor_required','provider_already_linked_elsewhere'], true) ? 'critical' : 'warning';
        $this->event('login_' . $code, 0, $severity, ['detail_hash'=>hash('sha256', wp_strip_all_tags((string) $message))]);
        if ($count_failure && ($s['lockout_enabled'] ?? 'yes') === 'yes') DIP_Lockout::register_failure($s);
        // Only a normalized error code crosses the redirect boundary. The
        // login page maps it to a trusted local message, preventing arbitrary
        // query text from impersonating a Delicat security notice.
        if ($this->is_native_modal_tracker($this->failure_tracker) && $this->failure_return_url !== '') {
            $return = $this->safe_redirect($this->failure_return_url);
            if ($return !== '') {
                $return = remove_query_arg(['dip_auth_error', 'dip_verified'], $return);
                $return = add_query_arg('dip_auth_error', $code, $return);
                wp_safe_redirect($return . '#delicat-login');
                exit;
            }
        }

        /*
         * 6.9.18 - a failed social login used to end on wp-login.php.
         *
         * That is the bare WordPress form, with a WordPress notice on it, shown
         * to a customer who was standing in the Delicat storefront a moment
         * earlier. They cannot tell it apart from the site breaking, and the
         * sign-in they attempt next happens outside every Delicat flow.
         *
         * The storefront modal already holds a French sentence for each of these
         * codes, so the customer is returned to it with the code and reads a
         * real explanation. wp-login.php is kept only for a sign-in that
         * genuinely started there - an administrator using the Google button on
         * the WordPress form - where sending them to the storefront would be the
         * wrong answer instead.
         */
        $origin = $this->failure_return_url !== '' ? $this->safe_redirect($this->failure_return_url) : '';
        $origin_path = $origin !== '' ? (string) wp_parse_url($origin, PHP_URL_PATH) : '';
        $started_on_wp_screen = $origin_path !== ''
            && (preg_match('~/wp-login\.php$~i', untrailingslashit($origin_path)) || false !== strpos($origin_path, '/wp-admin'));

        if ($started_on_wp_screen) {
            wp_safe_redirect(add_query_arg('dip_error', $code, wp_login_url()));
            exit;
        }

        $storefront = $origin !== '' && !$started_on_wp_screen ? $origin : home_url('/');
        $storefront = remove_query_arg(['dip_auth_error', 'dip_error', 'dip_verified'], $storefront);
        wp_safe_redirect(add_query_arg('dip_auth_error', $code, $storefront) . '#delicat-login');
        exit;
    }

    public function login_error_message($message) {
        if (empty($_GET['dip_error'])) return $message;
        $code = sanitize_key(wp_unslash($_GET['dip_error']));
        $map = [
            'maintenance' => __('La connexion sociale est temporairement en maintenance.', 'delicat-google-login'),
            'access_denied' => __('Connexion sociale annulée ou refusée.', 'delicat-google-login'),
            'temporarily_locked' => __('Connexion temporairement bloquée. Réessayez plus tard.', 'delicat-google-login'),
            'rate_limited' => __('Trop de tentatives. Réessayez plus tard.', 'delicat-google-login'),
            'dip_email_not_verified' => __('Vérifiez votre adresse e-mail avant de vous connecter.', 'delicat-google-login'),
            'account_pending_approval' => __('Votre compte attend l’approbation de l’administrateur.', 'delicat-google-login'),
            'privileged_social_login_blocked' => __('Ce compte doit utiliser une méthode de connexion privilégiée sécurisée.', 'delicat-google-login'),
            'privileged_auto_link_blocked' => __('Ce compte sensible existe déjà. Delicat refuse une simple liaison par e-mail; utilisez son lien Google historique ou reliez Google depuis une session sécurisée.', 'delicat-google-login'),
            'duplicate_nextend_google_identity' => __('Cette identité Google apparaît sur plusieurs comptes historiques. Une vérification administrateur est requise.', 'delicat-google-login'),
            'legacy_google_email_mismatch' => __('L’adresse Google ne correspond pas exactement au compte Delicat historiquement lié.', 'delicat-google-login'),
            'legacy_google_owner_conflict' => __('Le lien Google historique et l’adresse e-mail pointent vers deux comptes différents.', 'delicat-google-login'),
            'legacy_privileged_continuity_failed' => __('Le lien Google historique n’a pas pu être validé de manière sécurisée.', 'delicat-google-login'),
            'admin_google_secure_mode_disabled' => __('Google Secure Mode Administrateur est désactivé.', 'delicat-google-login'),
            'admin_google_https_required' => __('HTTPS est requis pour Google Secure Mode Administrateur.', 'delicat-google-login'),
            'admin_google_identity_mismatch' => __('Cette identité Google ne correspond pas à l’identité Administrateur approuvée.', 'delicat-google-login'),
            'admin_google_not_authorized' => __('Cette identité Google doit être explicitement approuvée depuis le Centre de sécurité de votre session Administrateur.', 'delicat-google-login'),
            'admin_google_two_factor_required' => __('TOTP 2FA doit être actif avant d’utiliser Google pour un compte Administrateur.', 'delicat-google-login'),
            'admin_google_link_authorization_failed' => __('La liaison Google Administrateur n’a pas pu être autorisée de manière sécurisée.', 'delicat-google-login'),
            'link_session_mismatch' => __('La session de liaison a expiré. Recommencez depuis votre compte.', 'delicat-google-login'),
            'privileged_link_requires_secure_mode' => __('Confirmez votre identité et activez TOTP 2FA avant de relier Google à cet Administrateur.', 'delicat-google-login'),
            'provider_already_linked_elsewhere' => __('Cette identité Google est déjà liée à un autre compte.', 'delicat-google-login'),
            'invalid_link_nonce' => __('La demande de liaison a expiré. Recommencez depuis votre compte.', 'delicat-google-login'),
            'email_exists' => __('Un compte utilise déjà cette adresse. Connectez-vous d’abord, puis reliez le fournisseur depuis votre compte.', 'delicat-google-login'),
            'not_configured' => __('Ce fournisseur de connexion n’est pas disponible actuellement.', 'delicat-google-login'),
            'provider_unavailable' => __('Le fournisseur Google est momentanément indisponible. Réessayez.', 'delicat-google-login'),
            'invalid_authorization_url' => __('La configuration de connexion Google est invalide. Vérifiez les réglages OAuth.', 'delicat-google-login'),
            'missing_callback_data' => __('Google a renvoyé une réponse incomplète. Recommencez la connexion.', 'delicat-google-login'),
            'invalid_state' => __('La session Google a expiré ou n’est plus valide. Recommencez depuis Delicat Store.', 'delicat-google-login'),
            'browser_binding_failed' => __('La session Google n’est plus liée à ce navigateur. Fermez puis relancez la connexion.', 'delicat-google-login'),
            'token_transport_error' => __('Impossible de contacter Google pour terminer la connexion. Réessayez.', 'delicat-google-login'),
            'token_error' => __('Google n’a pas validé cette tentative de connexion. Recommencez.', 'delicat-google-login'),
            'token_response_too_large' => __('Google a renvoyé une réponse inattendue. Recommencez la connexion.', 'delicat-google-login'),
            'google_keys_unavailable' => __('Impossible de vérifier les clés de sécurité Google actuellement. Réessayez dans un instant.', 'delicat-google-login'),
            'google_keys_too_large' => __('La réponse de sécurité Google est invalide. Réessayez.', 'delicat-google-login'),
            'google_keys_invalid' => __('Les clés de sécurité Google n’ont pas pu être validées. Réessayez.', 'delicat-google-login'),
            'google_key_missing' => __('Google a renouvelé sa clé de sécurité. Réessayez la connexion.', 'delicat-google-login'),
            'google_key_type' => __('La clé de sécurité Google reçue n’est pas prise en charge.', 'delicat-google-login'),
            'google_key_use' => __('La clé de sécurité Google reçue est invalide.', 'delicat-google-login'),
            'google_key_alg' => __('L’algorithme de sécurité Google reçu est invalide.', 'delicat-google-login'),
            'google_key_invalid' => __('La clé de sécurité Google reçue est invalide.', 'delicat-google-login'),
            'google_signature' => __('La signature de sécurité Google n’a pas pu être vérifiée.', 'delicat-google-login'),
            'google_jwt_format' => __('Le jeton de connexion Google est invalide. Recommencez.', 'delicat-google-login'),
            'google_jwt_header' => __('La signature du jeton Google est invalide.', 'delicat-google-login'),
            'google_openssl_missing' => __('Le serveur ne peut pas vérifier cryptographiquement Google. Contactez l’administrateur.', 'delicat-google-login'),
            'google_audience_missing' => __('L’identifiant OAuth Google n’est pas configuré correctement.', 'delicat-google-login'),
            'invalid_audience' => __('L’identifiant OAuth Google ne correspond pas à ce site. Vérifiez le Client ID.', 'delicat-google-login'),
            'invalid_issuer' => __('L’émetteur du jeton Google est invalide.', 'delicat-google-login'),
            'invalid_token_time' => __('Le jeton Google a expiré ou sa date est invalide. Recommencez.', 'delicat-google-login'),
            'invalid_nonce' => __('La vérification anti-rejeu Google a échoué. Recommencez la connexion.', 'delicat-google-login'),
            'invalid_subject' => __('L’identité Google reçue est invalide.', 'delicat-google-login'),
            'email_not_verified' => __('Google n’a pas confirmé cette adresse e-mail.', 'delicat-google-login'),
            'userinfo_subject_mismatch' => __('Le profil Google ne correspond pas au jeton de connexion. Recommencez.', 'delicat-google-login'),
            'two_factor_challenge_failed' => __('Impossible de démarrer la vérification en deux étapes. Réessayez.', 'delicat-google-login'),
            'two_factor_expired' => __('La vérification en deux étapes a expiré. Recommencez la connexion.', 'delicat-google-login'),
            'two_factor_https_required' => __('HTTPS est requis pour terminer la vérification en deux étapes.', 'delicat-google-login'),
        ];
        $safe = $map[$code] ?? __('La connexion n’a pas pu être finalisée. Réessayez.', 'delicat-google-login');
        return $message . '<div id="login_error" class="notice notice-error"><p>' . esc_html($safe) . '</p></div>';
    }

    private function is_social_role_blocked($user_id, array $settings) {
        $user = get_userdata((int) $user_id);
        if (!$user) return true;
        if (class_exists('DIP_Account_Sync') && !DIP_Account_Sync::is_customer_account($user_id)) return true;
        $configured = preg_split('/[\s,]+/', (string) ($settings['blocked_social_roles'] ?? 'administrator,editor,shop_manager'));
        $blocked = array_values(array_filter(array_map('sanitize_key', (array) $configured)));
        return user_can($user, 'manage_options')
            || user_can($user, 'manage_woocommerce')
            || (bool) array_intersect((array) $user->roles, $blocked);
    }

    private function configured(array $s) {
        return $s['enabled'] === 'yes' && DIP_Request::is_secure() && !empty($s['client_id']) && !empty($s['client_secret']);
    }

    private function provider($id, array $settings = []) {
        if (!$settings) $settings = $this->settings();
        $registry = new DIP_Provider_Registry();
        $registry->register(new DIP_Google_Provider($settings, $this->callback_url('google')));
        $registry->register(new DIP_Microsoft_Provider($settings, $this->callback_url('microsoft')));
        /**
         * Allows future provider modules to register without editing the core.
         * Providers must implement DIP_Provider and must validate tokens server-side.
         */
        do_action('dip_register_identity_providers', $registry, $settings);
        return $registry->get($id);
    }

    private function render_provider_center(array $s) {
        $registry = new DIP_Provider_Registry();
        $registry->register(new DIP_Google_Provider($s, $this->callback_url('google')));
        $registry->register(new DIP_Microsoft_Provider($s, $this->callback_url('microsoft')));
        do_action('dip_register_identity_providers', $registry, $s);
        echo '<section class="dip-builder-card"><div class="dip-builder-head"><div><span>PHASE 9</span><h2>Fournisseurs d’identité modulaires</h2><p>Chaque fournisseur est isolé du moteur principal et doit valider ses jetons côté serveur.</p></div><strong>' . esc_html(count($registry->all())) . ' module(s)</strong></div><div class="dip-provider-grid">';
        foreach ($registry->all() as $provider) {
            $ok = $provider->is_configured($s);
            echo '<div class="dip-provider-card"><h3>' . esc_html($provider->label()) . '</h3><p><strong>' . ($ok ? 'Configuré' : 'Non configuré') . '</strong></p><code>' . esc_html($provider->callback_url()) . '</code></div>';
        }
        echo '<div class="dip-provider-card"><h3>Apple / Discord / Steam</h3><p>Modules futurs. Ils restent désactivés jusqu’à leur validation cryptographique complète.</p></div></div></section>';
    }

    private function callback_url($provider = 'google') {
        return add_query_arg('dip_action', 'callback', home_url('/'));
    }

    /**
     * Validate the external OAuth authorization destination before redirecting.
     * Built-in providers are restricted to their canonical HTTPS hosts. Custom
     * providers must explicitly opt their host in through the documented filter.
     */
    private function trusted_authorization_url($url, $provider_id, $provider) {
        $url = esc_url_raw((string) $url, ['https']);
        if ($url === '') {
            return new WP_Error('invalid_authorization_url', 'Adresse OAuth invalide.');
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(rtrim((string) wp_parse_url($url, PHP_URL_HOST), '.'));
        if ($scheme !== 'https' || $host === '') {
            return new WP_Error('invalid_authorization_url', 'Le fournisseur OAuth doit utiliser HTTPS.');
        }

        $allowed = [
            'google' => ['accounts.google.com'],
            'microsoft' => ['login.microsoftonline.com'],
        ];
        $hosts = $allowed[sanitize_key($provider_id)] ?? [];
        /**
         * Custom provider modules may add only the exact authorization host(s)
         * they control. Wildcards are intentionally unsupported by the core.
         */
        $hosts = apply_filters('dip_allowed_authorization_hosts', $hosts, sanitize_key($provider_id), $provider);
        $hosts = array_values(array_unique(array_filter(array_map(static function ($value) {
            $value = strtolower(rtrim(trim((string) $value), '.'));
            return preg_match('/^[a-z0-9.-]+$/D', $value) ? $value : '';
        }, (array) $hosts))));

        if (!in_array($host, $hosts, true)) {
            return new WP_Error('untrusted_authorization_host', 'Le domaine OAuth du fournisseur n’est pas autorisé.');
        }
        return $url;
    }

    private function safe_redirect($url) {
        $url = trim((string) wp_unslash($url));
        if ($url === '') return '';
        // Admin-friendly relative paths such as /my-wallet/ become absolute site URLs.
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            $url = home_url($url);
        }
        return wp_validate_redirect(esc_url_raw($url), home_url('/'));
    }

    /**
     * Return a safe customer-facing destination after social authentication.
     * Never send a successfully authenticated customer back to wp-login.php,
     * registration, password-reset, logout, or wp-admin screens.
     */
    private function post_login_redirect($url, $fallback = '') {
        $fallback = $fallback ? $this->safe_redirect($fallback) : $this->native_account_url($this->settings());
        $candidate = $this->safe_redirect($url);
        if ($candidate === '') return $fallback;
        $path = (string) wp_parse_url($candidate, PHP_URL_PATH);
        $query = (string) wp_parse_url($candidate, PHP_URL_QUERY);
        parse_str($query, $params);

        $is_login = (bool) preg_match('~/wp-login\.php$~i', untrailingslashit($path));
        $is_admin = strpos($path, '/wp-admin') !== false;
        $login_action = sanitize_key((string) ($params['action'] ?? ''));
        $unsafe_action = in_array($login_action, ['login','lostpassword','retrievepassword','resetpass','rp','register','logout','reauth'], true);

        if ($is_login || $is_admin || $unsafe_action) {
            $candidate = $fallback;
        }

        /** Allow trusted site code to customize the final customer redirect. */
        $filtered = apply_filters('dip_social_login_redirect', $candidate, $fallback);
        return $this->safe_redirect($filtered) ?: $fallback;
    }

    public function render_button(array $args = []) {
        $this->assets(true);
        return $this->button($args);
    }

    private function provider_icon($provider_id) {
        if ($provider_id === 'google') {
            return '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.22-.2-1.76H12v3.4h5.52a4.74 4.74 0 0 1-2.05 3.02l-.03.11 2.97 2.3.21.02c1.94-1.79 3.06-4.42 3.06-7.09Z"/><path fill="#34A853" d="M12 22c2.7 0 4.97-.89 6.63-2.42l-3.16-2.44c-.85.57-2 .97-3.47.97-2.6 0-4.8-1.76-5.6-4.18l-.11.01-3.09 2.39-.04.1A10 10 0 0 0 12 22Z"/><path fill="#FBBC05" d="M6.4 13.93A6.2 6.2 0 0 1 6.08 12c0-.67.12-1.32.31-1.93v-.12L3.27 7.53l-.1.05A10 10 0 0 0 2 12c0 1.6.38 3.11 1.16 4.42l3.24-2.49Z"/><path fill="#EA4335" d="M12 5.89c1.88 0 3.15.81 3.88 1.48l2.82-2.75C16.97 3.01 14.7 2 12 2a10 10 0 0 0-8.84 5.58l3.23 2.49C7.2 7.65 9.4 5.89 12 5.89Z"/></svg>';
        }
        return '<span aria-hidden="true">M</span>';
    }

    /**
     * 6.9.15 — Explain, without ever exposing a secret, why the storefront
     * Google button is or is not rendered.
     *
     * Every gate below previously failed silently and identically: the button
     * simply vanished from the modal, from wp-login.php, from My Account and
     * from checkout with no admin signal. `secret_unreadable` is the important
     * new state: the encrypted secret is still stored, but WordPress salts
     * (AUTH_SALT / SECURE_AUTH_SALT) changed, so DIP_Crypto can no longer open
     * it. That looked exactly like "never configured".
     *
     * @return string ok|maintenance|disabled|no_https|missing_client_id|missing_secret|secret_unreadable
     */
    public function google_availability(array $s = []) {
        if (!$s) $s = $this->settings();
        if (($s['social_login_maintenance'] ?? 'no') === 'yes') return 'maintenance';
        if (($s['enabled'] ?? 'no') !== 'yes') return 'disabled';
        if (!DIP_Request::is_secure()) return 'no_https';
        if (empty($s['client_id'])) return 'missing_client_id';
        if (empty($s['client_secret'])) {
            $stored = (string) (wp_parse_args((array) get_option(self::OPTION, []), self::defaults())['client_secret'] ?? '');
            return $stored !== '' ? 'secret_unreadable' : 'missing_secret';
        }
        return 'ok';
    }

    /** Short, non-secret admin label for a google_availability() state. */
    public static function google_availability_label($state) {
        $map = [
            'ok'                => __('Configuré — le bouton Google est affiché.', 'delicat-google-login'),
            'maintenance'       => __('Mode maintenance des connexions sociales actif : le bouton Google est masqué pour les clients.', 'delicat-google-login'),
            'disabled'          => __('La connexion Google est désactivée dans les réglages Delicat Identity.', 'delicat-google-login'),
            'no_https'          => __('HTTPS n’est pas détecté côté serveur. Derrière Cloudflare ou un proxy, ajoutez la détection HTTPS dans wp-config.php.', 'delicat-google-login'),
            'missing_client_id' => __('Le Client ID Google est vide.', 'delicat-google-login'),
            'missing_secret'    => __('Le Client Secret Google est vide.', 'delicat-google-login'),
            'secret_unreadable' => __('Un Client Secret Google est enregistré mais illisible : les clés de sécurité WordPress (AUTH_SALT / SECURE_AUTH_SALT) ont changé. Ressaisissez le secret Google puis enregistrez.', 'delicat-google-login'),
        ];
        return $map[$state] ?? $map['missing_secret'];
    }

    /**
     * The request looks HTTPS to the client but not to PHP.
     *
     * 6.9.18: no longer a diagnostic curiosity. Every gate in the plugin now
     * asks DIP_Request::is_secure(), which counts this case as encrypted -
     * because for the customer it is. Kept as a named state so the admin screen
     * can say "https, terminated upstream" instead of reporting a correctly
     * configured site as having no HTTPS at all.
     */
    public static function proxy_https_detected() {
        return DIP_Request::tls_terminated_upstream();
    }

    public function google_availability_notice() {
        if (!current_user_can('manage_options')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_settings = $screen && $screen->id === 'settings_page_delicat-identity';
        if (!$on_settings && (!$screen || !in_array($screen->id, ['dashboard', 'plugins'], true))) return;
        if (get_transient(self::RECOVERY_NOTICE)) {
            delete_transient(self::RECOVERY_NOTICE);
            echo '<div class="notice notice-success is-dismissible"><p><strong>Delicat Identity — Google :</strong> '
                . esc_html__('Un ancien mode maintenance non suivi a été désactivé. La connexion Google publique est de nouveau autorisée.', 'delicat-google-login')
                . '</p></div>';
        }
        $state = $this->google_availability();
        if ($state === 'ok') return;
        $extra = ($state === 'no_https' && self::proxy_https_detected())
            ? ' ' . esc_html__('Un en-tête de proxy HTTPS a été détecté : définissez $_SERVER[\'HTTPS\'] = \'on\' dans wp-config.php avant require_once ABSPATH . \'wp-settings.php\'.', 'delicat-google-login')
            : '';
        $repair = $state === 'maintenance'
            ? ' <a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_reenable_google'), 'dip_reenable_google')) . '">' . esc_html__('Réactiver Google', 'delicat-google-login') . '</a>'
            : '';
        echo '<div class="notice notice-warning"><p><strong>Delicat Identity — Google :</strong> '
            . esc_html(self::google_availability_label($state)) . $extra
            . ' <a href="' . esc_url(admin_url('options-general.php?page=delicat-identity#dip-settings')) . '">'
            . esc_html__('Ouvrir les réglages', 'delicat-google-login') . '</a>' . $repair . '</p></div>';
    }

    /** Clear only the social OAuth maintenance switch; credentials are untouched. */
    public function handle_reenable_google() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Permission refusée.', 'delicat-google-login'), 403);
        check_admin_referer('dip_reenable_google');
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) $raw = [];
        $raw['social_login_maintenance'] = 'no';
        $raw['social_login_maintenance_explicit'] = 'yes';
        $raw['social_login_maintenance_changed_at'] = time();
        $raw['social_login_maintenance_changed_by'] = get_current_user_id();
        update_option(self::OPTION, wp_parse_args($raw, self::defaults()), false);
        do_action('litespeed_purge_all');
        if (class_exists('DIP_Audit')) DIP_Audit::record('social_login_maintenance_disabled', 'notice', get_current_user_id(), ['source'=>'admin_repair']);
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_google_reenabled'=>'1'], admin_url('options-general.php')));
        exit;
    }

    /**
     * Purge cached modal HTML only after every plugin has registered its cache
     * listeners. Builder's Cloudflare bridge also listens to this LiteSpeed-
     * compatible action, so one recovery invalidates both page and edge HTML.
     */
    public function maybe_purge_recovery_cache() {
        if (!get_transient(self::RECOVERY_PURGE)) return;
        delete_transient(self::RECOVERY_PURGE);
        do_action('litespeed_purge_all');
    }

    private function button(array $args = []) {
        $s = $this->settings();
        if (($s['social_login_maintenance'] ?? 'no') === 'yes' && !current_user_can('manage_options')) return ''; 
        $provider_id = sanitize_key($args['provider'] ?? 'google');
        $provider = $this->provider($provider_id, $s);
        if (!$provider || !$provider->is_configured($s)) return '';
        $default_text = $provider_id === 'microsoft' ? $s['microsoft_button_text'] : $s['button_text'];
        $text = sanitize_text_field($args['text'] ?? $default_text);
        $url = add_query_arg([
            'dip_action' => 'login',
            'provider' => $provider_id,
            'redirect' => $this->safe_redirect($args['redirect'] ?? $this->current_url()),
            'tracker' => substr(sanitize_text_field((string) ($args['tracker'] ?? '')), 0, 100),
        ], home_url('/'));
        if (empty($args['tracker'])) $url = remove_query_arg('tracker', $url);
        if (!empty($args['link']) && is_user_logged_in()) {
            $url = add_query_arg(['link' => 1, '_dip_nonce' => wp_create_nonce('dip_link_' . get_current_user_id())], $url);
        }
        $location = sanitize_key($args['location'] ?? 'default');
        if (empty($args['text'])) {
            if ($location === 'wp_login' && !empty($s['wp_login_text'])) $text = $s['wp_login_text'];
            elseif ($location === 'account' && !empty($s['account_text'])) $text = $s['account_text'];
            elseif ($location === 'checkout' && !empty($s['checkout_text'])) $text = $s['checkout_text'];
        }
        $layout = sanitize_key($args['layout'] ?? ($s['button_layout'] ?? 'wide'));
        if (!in_array($layout, ['wide','row','icon'], true)) $layout = 'wide';
        $align = sanitize_key($args['align'] ?? ($s['button_align'] ?? 'stretch'));
        if (!in_array($align, ['left','center','right','stretch'], true)) $align = 'stretch';
        $classes = ['dip-google-button','dip-provider-' . $provider_id,'dip-layout-' . $layout,'dip-theme-' . $s['button_theme'],'dip-shadow-' . $s['button_shadow'],'dip-motion-' . $s['button_motion']];
        $wrap = ['dip-login-wrap','dip-align-' . $align];
        if ($s['mobile_full_width'] === 'yes') $wrap[] = 'dip-mobile-full';
        $divider = ($s['show_divider'] === 'yes' && empty($args['suppress_divider'])) ? '<div class="dip-divider"><span>' . esc_html__('ou', 'delicat-google-login') . '</span></div>' : '';
        if (($s['bypass_cache_redirect'] ?? 'yes') === 'yes') $url = add_query_arg('dip_nocache', wp_generate_password(8, false, false), $url);
        return '<div class="' . esc_attr(implode(' ', $wrap)) . '">' . $divider . '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($url) . '" data-dip-location="' . esc_attr($location) . '" data-dip-provider="' . esc_attr($provider_id) . '" data-delicat-no-app="1" aria-label="' . esc_attr($text) . '" rel="nofollow noopener"><span class="dip-g" aria-hidden="true">' . $this->provider_icon($provider_id) . '</span><span>' . esc_html($text) . '</span></a></div>';
    }

    /**
     * The scheme comes from the site's own home URL, never from is_ssl().
     *
     * This value becomes the OAuth return address. Behind a proxy that ends TLS
     * before PHP the old version produced http://delicastoreha.com/... - which
     * Google refuses as a redirect_uri mismatch, so the customer is bounced back
     * with an error they can do nothing about.
     */
    private function current_url() {
        return DIP_Request::current_url();
    }

    private function provider_buttons(array $args = []) {
        $s = $this->settings();
        if (($s['hide_social_for_logged_in'] ?? 'yes') === 'yes' && is_user_logged_in() && empty($args['link'])) return '';
        $order = array_values(array_filter(array_map('sanitize_key', preg_split('/[\s,]+/', (string) ($s['provider_order'] ?? 'google,microsoft')))));
        $order = array_values(array_intersect($order, ['google','microsoft']));
        if (!$order) $order = ['google','microsoft'];
        $out = '';
        foreach ($order as $provider) {
            $out .= $this->button(array_merge($args, ['provider' => $provider, 'suppress_divider' => true]));
        }
        if ($out !== '' && ($s['show_divider'] ?? 'no') === 'yes' && empty($args['suppress_divider'])) {
            $out = '<div class="dip-divider"><span>' . esc_html__('ou', 'delicat-google-login') . '</span></div>' . $out;
        }
        return $out;
    }

    /** Public, ordered provider group for integrations and extensions. */
    public function render_provider_buttons(array $args = []) {
        $this->assets(true);
        return $this->provider_buttons($args);
    }

    private function pro_redirect_for_user($user_id, $provider_id, $new, array $s) {
        $user = get_userdata((int) $user_id);
        if ($user) {
            $map = preg_split('/\r\n|\r|\n/', (string) ($s['role_redirects'] ?? ''));
            foreach ((array) $map as $line) {
                if (strpos($line, '=') === false) continue;
                list($role, $url) = array_map('trim', explode('=', $line, 2));
                if ($role && in_array(sanitize_key($role), (array) $user->roles, true) && $url) return $url;
            }
        }
        $provider_key = sanitize_key($provider_id) . '_redirect';
        if (!empty($s[$provider_key])) return $s[$provider_key];
        return $new ? ($s['redirect_register'] ?? '') : ($s['redirect_login'] ?? '');
    }
    public function render_wp_login() {
        $s = $this->settings();
        if ($s['auto_wp_login'] === 'yes') {
            echo $this->provider_buttons(['location' => 'wp_login', 'redirect' => $this->native_account_url($s)]);
        }
    }
    public function render_my_account() { if (!is_user_logged_in() && $this->settings()['auto_my_account'] === 'yes') echo $this->provider_buttons(['location' => 'account']); }
    public function render_checkout() { if (!is_user_logged_in() && $this->settings()['auto_checkout'] === 'yes') echo $this->provider_buttons(['location' => 'checkout', 'redirect' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : $this->current_url()]); }
    public function shortcode($atts) {
        $this->assets(true);
        $a = shortcode_atts(['text' => '', 'redirect' => '', 'provider' => 'google'], $atts, 'delicat_google_login');
        if ($a['text'] === '') unset($a['text']);
        return $this->button($a);
    }

    public function social_shortcode($atts) {
        $this->assets(true);
        $a = shortcode_atts(['text' => '', 'redirect' => '', 'provider' => 'google'], $atts, 'delicat_social_login');
        if ($a['text'] === '') unset($a['text']);
        return $this->button($a);
    }

    private function native_account_url(array $settings = []) {
        $settings = $settings ?: $this->settings();
        if (!empty($settings['native_account_url'])) {
            return $this->safe_redirect($settings['native_account_url']);
        }
        if (function_exists('wc_get_page_permalink')) {
            $url = wc_get_page_permalink('myaccount');
            if ($url) return $url;
        }
        return wp_login_url();
    }

    public function login_button_shortcode($atts) {
        $this->assets(true);
        $s = $this->settings();
        $a = shortcode_atts([
            'text' => '',
            'logout_text' => 'Déconnexion',
            'class' => '',
        ], $atts, 'delicat_login_button');
        $classes = 'dl-trigger-btn';
        if ($a['class'] !== '') $classes .= ' ' . sanitize_html_class($a['class']);
        if (is_user_logged_in()) {
            return '<a class="' . esc_attr($classes) . '" href="' . esc_url(wp_logout_url(home_url('/'))) . '">' . esc_html(sanitize_text_field($a['logout_text'])) . '</a>';
        }
        $text = $a['text'] !== '' ? sanitize_text_field($a['text']) : ($s['native_login_text'] ?: 'Connexion');
        return '<button type="button" class="' . esc_attr($classes) . '" data-dip-auth-open data-dl-open aria-haspopup="dialog" aria-controls="dip-identity-modal">' . esc_html($text) . '</button>';
    }

    public function account_button_shortcode($atts) {
        $this->assets(true);
        $s = $this->settings();
        $a = shortcode_atts([
            'text' => '',
            'logged_in_text' => '',
            'url' => '',
            'class' => '',
        ], $atts, 'delicat_account_button');
        $logged_in = is_user_logged_in();
        $text = $logged_in
            ? ($a['logged_in_text'] !== '' ? sanitize_text_field($a['logged_in_text']) : $s['native_account_text'])
            : ($a['text'] !== '' ? sanitize_text_field($a['text']) : $s['native_login_text']);
        $url = $a['url'] !== '' ? $this->safe_redirect($a['url']) : $this->native_account_url($s);
        $classes = 'dip-native-account-button';
        if ($a['class'] !== '') $classes .= ' ' . sanitize_html_class($a['class']);
        return '<a class="' . esc_attr($classes) . '" href="' . esc_url($url) . '">' . esc_html($text) . '</a>';
    }

    public function login_panel_shortcode($atts) {
        $this->assets(true);
        $s = $this->settings();
        $a = shortcode_atts(['redirect' => '', 'title' => 'Accédez à votre compte'], $atts, 'delicat_login_panel');
        if (is_user_logged_in()) {
            return '<div class="dip-native-login-panel is-connected"><h3>' . esc_html($s['native_account_text']) . '</h3>' . $this->account_button_shortcode([]) . '</div>';
        }
        $redirect = $a['redirect'] !== '' ? $this->safe_redirect($a['redirect']) : $this->native_account_url($s);
        $social = $this->provider_buttons(['location' => 'account', 'redirect' => $redirect]);
        $native = '';
        if (($s['show_native_login_link'] ?? 'yes') === 'yes') {
            $native = '<a class="dip-native-login-link" href="' . esc_url($this->native_account_url($s)) . '">Se connecter avec e-mail et mot de passe</a>';
        }
        return '<section class="dip-native-login-panel"><h3>' . esc_html(sanitize_text_field($a['title'])) . '</h3>' . $social . $native . '</section>';
    }


    public function migration_notice() {
        if (!current_user_can('manage_options')) return;
        if (DIP_Migration::plugin_status() !== 'active') return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'settings_page_delicat-identity') return;
        echo '<div class="notice notice-info"><p><strong>Delicat Identity Pro:</strong> Nextend is active. Keep both providers during testing, run the migration scan, then disable only Nextend Google after existing-customer validation. The secure login/register modal is now integrated into Delicat Identity; disable the old Code Snippets copy to avoid duplicate hooks, then use <code>[delicat_login_button]</code> or <code>#delicat-login</code>.</p></div>';
    }

    public function handle_oauth_test() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_oauth_test');
        $s = $this->settings();
        $checks = [
            'https' => DIP_Request::is_secure(),
            'client_id' => !empty($s['client_id']),
            'client_secret' => !empty($s['client_secret']),
            'callback' => $this->callback_url() === add_query_arg('dip_action', 'callback', home_url('/')),
            'google_discovery' => false,
        ];
        $error = '';
        $response = wp_safe_remote_get('https://accounts.google.com/.well-known/openid-configuration', [
            'timeout' => 12,
            'redirection' => 0,
            'limit_response_size' => 131072,
            'headers' => ['Accept' => 'application/json'],
            'user-agent' => 'Delicat-Identity-Pro/' . DIP_VERSION,
        ]);
        if (is_wp_error($response)) {
            $error = sanitize_text_field($response->get_error_message());
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            $checks['google_discovery'] = $code === 200 && is_array($body) && !empty($body['authorization_endpoint']) && !empty($body['token_endpoint']) && !empty($body['jwks_uri']);
            if (!$checks['google_discovery']) $error = 'Google OpenID Discovery n’a pas retourné une réponse valide.';
        }
        // 6.9.15: report whether the public storefront button actually renders.
        // Local credentials can be valid while the button is still suppressed
        // (maintenance mode, Google disabled, unreadable secret).
        $button_state = $this->google_availability($s);
        $checks['storefront_button'] = ($button_state === 'ok');
        if ($button_state !== 'ok' && $error === '') $error = self::google_availability_label($button_state);
        $ok = !in_array(false, $checks, true);
        update_option('dip_oauth_test_v1', ['checked_at'=>time(),'ok'=>$ok,'checks'=>$checks,'error'=>$error,'button_state'=>$button_state], false);
        $this->event($ok ? 'oauth_configuration_test_passed' : 'oauth_configuration_test_failed', get_current_user_id(), $ok ? 'notice' : 'warning');
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_oauth_test'=>$ok ? 'passed' : 'failed'], admin_url('options-general.php')));
        exit;
    }

    public function handle_migration_scan() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_scan');
        DIP_Migration::scan();
        $this->event('migration_scan_completed', get_current_user_id(), 'notice');
        wp_safe_redirect(add_query_arg(['page' => 'delicat-identity', 'dip_migration' => 'scanned'], admin_url('options-general.php')));
        exit;
    }

    public function handle_migration_backup() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_backup');
        $id = DIP_Migration::create_backup('Before Nextend provider switch');
        $this->event('migration_backup_created', get_current_user_id(), 'notice', ['backup' => substr($id, 0, 14)]);
        wp_safe_redirect(add_query_arg(['page' => 'delicat-identity', 'dip_migration' => 'backup'], admin_url('options-general.php')));
        exit;
    }

    public function handle_migration_restore() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_restore');
        $id = sanitize_text_field(wp_unslash($_GET['backup_id'] ?? ''));
        $result = DIP_Migration::restore_backup($id);
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()));
        $this->event('migration_backup_restored', get_current_user_id(), 'warning', ['backup' => substr($id, 0, 14)]);
        wp_safe_redirect(add_query_arg(['page' => 'delicat-identity', 'dip_migration' => 'restored'], admin_url('options-general.php')));
        exit;
    }

    public function handle_migration_export() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_export');
        $report = DIP_Migration::report();
        if (!$report) $report = DIP_Migration::scan();
        $payload = wp_json_encode(DIP_Migration::public_report($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="delicat-identity-migration-report-' . gmdate('Ymd-His') . '.json"');
        echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }



    public function handle_migration_dry_run() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_dry_run');
        $report = DIP_Migration::dry_run();
        $this->event('migration_dry_run_completed', get_current_user_id(), empty($report['manual_review']) ? 'notice' : 'warning', ['ready'=>(int)($report['ready']??0),'review'=>(int)($report['manual_review']??0)]);
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_migration'=>'dry-run'], admin_url('options-general.php'))); exit;
    }

    public function handle_migration_test() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_test');
        $raw = sanitize_text_field(wp_unslash($_POST['test_user_ids'] ?? ''));
        $ids = array_values(array_unique(array_filter(array_map('absint', preg_split('/[\s,;]+/', $raw)))));
        if (!$ids || count($ids) > 10) wp_die('Saisissez entre 1 et 10 identifiants utilisateur WordPress valides.');
        $result = DIP_Migration::migrate($ids, 10);
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()));
        $this->event('migration_test_batch_completed', get_current_user_id(), 'notice', ['requested'=>count($ids),'migrated'=>count((array)($result['migrated']??[]))]);
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_migration'=>'test-complete'], admin_url('options-general.php'))); exit;
    }

    public function handle_migration_batch() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_batch');
        if (empty($_POST['confirm_bulk'])) wp_die('Confirmation requise.');
        $result = DIP_Migration::migrate([], 25);
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()));
        $this->event('migration_bulk_batch_completed', get_current_user_id(), 'notice', ['migrated'=>count((array)($result['migrated']??[])),'skipped'=>count((array)($result['skipped']??[]))]);
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_migration'=>!empty($result['completed'])?'completed':'batch'], admin_url('options-general.php'))); exit;
    }

    public function handle_migration_auto_start() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_auto_start');
        $batch=max(5,min(50,absint($_POST['batch_size']??25)));
        $result=DIP_Migration::start_automatic($batch);
        if (is_wp_error($result)) {
            $this->event('automatic_migration_start_blocked',get_current_user_id(),'warning',['reason'=>$result->get_error_code()]);
            wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_migration'=>'auto-error','dip_message'=>rawurlencode($result->get_error_message())],admin_url('options-general.php'))); exit;
        }
        $this->event('automatic_migration_started',get_current_user_id(),'warning',['batch_size'=>$batch]);
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_migration'=>'auto-started'],admin_url('options-general.php'))); exit;
    }

    public function handle_migration_auto_pause() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_auto_pause');
        DIP_Migration::pause_automatic();
        $this->event('automatic_migration_paused',get_current_user_id(),'warning');
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_migration'=>'auto-paused'],admin_url('options-general.php'))); exit;
    }

    public function handle_migration_auto_resume() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_auto_resume');
        $result=DIP_Migration::resume_automatic();
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()));
        $this->event('automatic_migration_resumed',get_current_user_id(),'notice');
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_migration'=>'auto-resumed'],admin_url('options-general.php'))); exit;
    }


    public function handle_migration_verify() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_verify');
        $result = DIP_Migration::final_verification();
        $this->event('migration_final_verification_completed', get_current_user_id(), !empty($result['healthy']) ? 'notice' : 'warning', ['migrated'=>(int)$result['migrated'],'skipped'=>(int)$result['skipped'],'remaining'=>(int)$result['remaining']]);
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_migration'=>'verified'], admin_url('options-general.php'))); exit;
    }

    public function handle_migration_finalize() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_finalize');
        if (empty($_POST['confirm_final'])) wp_die('Confirmation requise.');
        $result = DIP_Migration::finalize();
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_migration'=>'finalize-error','dip_message'=>rawurlencode($result->get_error_message())], admin_url('options-general.php'))); exit;
        }
        $this->event('migration_finalized_monitoring_started', get_current_user_id(), 'warning', ['monitor_until'=>(int)$result['monitor_until']]);
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_migration'=>'finalized'], admin_url('options-general.php'))); exit;
    }

    public function handle_migration_rollback() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_migration_rollback');
        $result = DIP_Migration::rollback_wizard_mappings();
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()));
        $this->event('migration_wizard_rollback_completed', get_current_user_id(), 'warning', ['rolled_back'=>(int)$result]);
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_migration'=>'rolled-back'], admin_url('options-general.php'))); exit;
    }

    private function render_migration_assistant() {
        $report = DIP_Migration::report(); $dry = DIP_Migration::dry_run_report(); $state = DIP_Migration::state();
        $auto = DIP_Migration::automatic_state(); $s = $this->settings();
        $final = DIP_Migration::final_report();
        $skipped_items = DIP_Migration::skipped_accounts();
        $backups = DIP_Migration::backups(); $status = DIP_Migration::plugin_status();
        $status_labels = ['active'=>'Actif — coexistence de test','installed'=>'Installé mais inactif','missing'=>'Non détecté'];
        $callback = $this->callback_url(); $ready = $report && !empty($report['ready']);
        $total=(int)($dry['total']??($report['nextend_candidate_links']??0)); $migrated=count((array)($state['migrated']??[]));
        $skipped=count((array)($state['skipped']??[])); $progress=$total>0?min(100,(int)round(($migrated/$total)*100)):0;
        ?>
        <section class="dip-modern-section dip-migration-section">
          <div class="dip-section-head"><div><span class="dip-eyebrow">MIGRATION GUIDÉE SANS PERTE</span><h2>Assistant Nextend → Delicat Identity</h2><p>Analyse, simulation, test limité puis migration par lots. Aucun utilisateur, commande ou solde n’est supprimé.</p></div><span class="dip-pill <?php echo $ready?'is-success':'is-neutral'; ?>"><?php echo $report?($ready?'Prêt pour la simulation':'Révision nécessaire'):'Analyse requise'; ?></span></div>
          <div class="dip-metric-grid">
            <article><small>Nextend</small><strong><?php echo esc_html($status_labels[$status]??$status); ?></strong></article>
            <article><small>Candidats détectés</small><strong><?php echo esc_html((string)($report['nextend_candidate_links']??0)); ?></strong></article>
            <article><small>Prêts selon simulation</small><strong><?php echo esc_html((string)($dry['ready']??0)); ?></strong></article>
            <article><small>Liés par l’assistant</small><strong><?php echo esc_html((string)$migrated); ?></strong></article>
          </div>
          <div class="dip-migration-progress"><div><strong>Progression de la migration</strong><span><?php echo esc_html($progress); ?>%</span></div><progress max="100" value="<?php echo esc_attr($progress); ?>"></progress><small><?php echo esc_html($migrated); ?> migré(s), <?php echo esc_html($skipped); ?> ignoré(s), <?php echo esc_html(max(0,$total-$migrated-$skipped)); ?> restant(s).</small></div>
          <div class="dip-callback-reminder"><div><strong>URI Delicat à ajouter dans Google Cloud</strong><code id="dip-migration-callback"><?php echo esc_html($callback); ?></code></div><button type="button" class="button" data-dip-copy="#dip-migration-callback">Copier</button></div>
          <div class="dip-wizard-grid">
            <article><span>1</span><h3>Analyser</h3><p>Détecte Nextend, doublons et références invalides.</p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_migration_scan'),'dip_migration_scan')); ?>">Analyser sans modifier</a></article>
            <article><span>2</span><h3>Sauvegarder</h3><p>Crée un point de restauration des réglages.</p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_migration_backup'),'dip_migration_backup')); ?>">Sauvegarder</a></article>
            <article><span>3</span><h3>Simuler</h3><p>Valide chaque compte sans écrire de liaison.</p><a class="button <?php echo $report?'':'disabled'; ?>" href="<?php echo $report?esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_migration_dry_run'),'dip_migration_dry_run')):'#'; ?>">Lancer la simulation</a></article>
            <article><span>4</span><h3>Tester</h3><p>Migre 1 à 10 comptes sélectionnés avant la bascule.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('dip_migration_test'); ?><input type="hidden" name="action" value="dip_migration_test"><input type="text" name="test_user_ids" placeholder="IDs WordPress, ex. 12, 48, 91" required><button class="button">Migrer les comptes test</button></form></article>
          </div>
          <?php if($report): ?><div class="dip-check-grid">
          <?php foreach([['Identités Google dupliquées',(int)$report['duplicate_google_identities']],['Références orphelines',(int)$report['orphaned_references']],['Groupes d’e-mails dupliqués',(int)$report['duplicate_email_groups']],['Utilisateurs Nextend',(int)($report['nextend_table_candidates']??0)]] as $item): ?><article class="<?php echo ($item[0]==='Utilisateurs Nextend'||$item[1]===0)?'is-ok':'is-review'; ?>"><span><?php echo ($item[0]==='Utilisateurs Nextend'||$item[1]===0)?'✓':'!'; ?></span><div><strong><?php echo esc_html($item[0]); ?></strong><small><?php echo esc_html((string)$item[1]); ?></small></div></article><?php endforeach; ?></div><?php endif; ?>
          <?php if($dry): ?><div class="dip-dryrun"><h3>Résultat de la simulation</h3><div class="dip-metric-grid"><article><small>Total analysé</small><strong><?php echo esc_html((string)$dry['total']); ?></strong></article><article><small>Prêts</small><strong><?php echo esc_html((string)$dry['ready']); ?></strong></article><article><small>Déjà liés</small><strong><?php echo esc_html((string)$dry['already_linked']); ?></strong></article><article><small>Révision manuelle</small><strong><?php echo esc_html((string)$dry['manual_review']); ?></strong></article></div><?php if(!empty($dry['issues'])):?><details><summary>Voir les premiers comptes à réviser</summary><table class="widefat striped"><thead><tr><th>ID utilisateur</th><th>Motif</th></tr></thead><tbody><?php foreach(array_slice($dry['issues'],0,25) as $issue):?><tr><td><?php echo esc_html((string)$issue['user_id']);?></td><td><?php echo esc_html((string)$issue['reason']);?></td></tr><?php endforeach;?></tbody></table></details><?php endif;?></div><?php endif; ?>
          <?php $auto_status=(string)($auto['status']??'idle'); $auto_total=(int)($auto['total']??($dry['total']??0)); $auto_terminal=(int)($auto['terminal_total']??0); $auto_percent=$auto_total?min(100,(int)round(($auto_terminal/$auto_total)*100)):0; ?>
          <div class="dip-auto-migration-card">
            <div><span class="dip-eyebrow">MIGRATION AUTOMATIQUE</span><h3>Transférer automatiquement tous les comptes sûrs</h3><p>Le système travaille en arrière-plan, reprend après une interruption et ignore automatiquement les conflits. Nextend reste actif pendant toute la transition.</p></div>
            <div class="dip-auto-status"><span class="dip-pill <?php echo $auto_status==='completed'?'is-success':($auto_status==='running'?'is-info':($auto_status==='paused'?'is-warning':'is-neutral')); ?>"><?php echo esc_html($auto_status==='running'?'En cours':($auto_status==='completed'?'Terminée':($auto_status==='paused'?'En pause':'Non démarrée'))); ?></span><strong><?php echo esc_html((string)$auto_percent); ?>%</strong></div>
            <progress max="100" value="<?php echo esc_attr($auto_percent); ?>"></progress>
            <div class="dip-auto-metrics"><span><b><?php echo esc_html((string)($auto['migrated']??count((array)($state['migrated']??[])))); ?></b> migrés</span><span><b><?php echo esc_html((string)($auto['skipped']??count((array)($state['skipped']??[])))); ?></b> à réviser</span><span><b><?php echo esc_html((string)max(0,$auto_total-$auto_terminal)); ?></b> restants</span></div>
            <?php if(!empty($auto['last_error'])):?><div class="notice notice-error inline"><p><?php echo esc_html($auto['last_error']); ?></p></div><?php endif;?>
            <div class="dip-auto-actions">
              <?php if(!in_array($auto_status,['running','completed'],true)):?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Démarrer la migration automatique de tous les comptes Nextend admissibles ?');"><?php wp_nonce_field('dip_migration_auto_start'); ?><input type="hidden" name="action" value="dip_migration_auto_start"><label>Taille des lots <input type="number" name="batch_size" min="5" max="50" value="<?php echo esc_attr((string)($s['automatic_migration_batch_size']??25)); ?>"></label><button class="button button-primary" <?php disabled(empty($report)||empty($report['ready'])); ?>>Démarrer automatiquement</button></form><?php endif;?>
              <?php if($auto_status==='running'):?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_migration_auto_pause'),'dip_migration_auto_pause')); ?>">Mettre en pause</a><?php elseif($auto_status==='paused'):?><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_migration_auto_resume'),'dip_migration_auto_resume')); ?>">Reprendre</a><?php endif;?>
            </div>
            <small>Les administrateurs, doublons, identifiants manquants et liaisons contradictoires ne sont jamais forcés. Ils restent dans la liste de révision manuelle.</small>
          </div>
          <div class="dip-final-migration-card">
            <div class="dip-section-head"><div><span class="dip-eyebrow">VÉRIFICATION FINALE</span><h3>Contrôler les liaisons avant de quitter Nextend</h3><p>Vérifie les 233 liaisons, les comptes restants et les indicateurs WooCommerce sans modifier les commandes ni les soldes.</p></div><?php if($final):?><span class="dip-pill <?php echo !empty($final['healthy'])?'is-success':'is-warning'; ?>"><?php echo !empty($final['healthy'])?'Vérification saine':'Révision nécessaire'; ?></span><?php endif;?></div>
            <?php if($final):?><div class="dip-metric-grid"><article><small>Liaisons valides</small><strong><?php echo esc_html((string)($final['valid_links']??0));?></strong></article><article><small>Liaisons invalides</small><strong><?php echo esc_html((string)($final['invalid_links']??0));?></strong></article><article><small>Restants</small><strong><?php echo esc_html((string)($final['remaining']??0));?></strong></article><article><small>Doublons créés</small><strong><?php echo esc_html((string)($final['duplicate_users_created']??0));?></strong></article><article><small>Avec commandes</small><strong><?php echo esc_html((string)($final['users_with_orders']??0));?></strong></article><article><small>Avec adresse</small><strong><?php echo esc_html((string)($final['users_with_addresses']??0));?></strong></article></div><?php endif;?>
            <div class="dip-auto-actions"><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_migration_verify'),'dip_migration_verify')); ?>">Lancer la vérification finale</a>
            <?php if(!empty($final['healthy']) && empty($final['finalized_at'])):?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Finaliser la migration et démarrer la période de surveillance ? Nextend ne sera pas supprimé.');"><?php wp_nonce_field('dip_migration_finalize');?><input type="hidden" name="action" value="dip_migration_finalize"><label><input type="checkbox" name="confirm_final" value="1" required> J’ai testé au moins un ancien client et confirmé ses commandes et son portefeuille.</label><button class="button button-primary">Finaliser et surveiller 7 jours</button></form><?php endif;?></div>
            <?php if(!empty($final['finalized_at'])):?><div class="notice notice-success inline"><p><strong>Migration finalisée.</strong> Période de surveillance active jusqu’au <?php echo esc_html(wp_date('Y-m-d H:i',(int)$final['monitor_until']));?>. Désactivez uniquement Google dans Nextend, mais gardez Nextend installé pendant cette période.</p></div><?php endif;?>
          </div>
          <?php if($skipped_items):?><div class="dip-review-card"><div class="dip-section-head"><div><span class="dip-eyebrow">RÉVISION MANUELLE</span><h3>Compte ignoré par sécurité</h3><p>Ce compte n’a pas été forcé. Consultez le motif avant toute action.</p></div><span class="dip-pill is-warning"><?php echo esc_html((string)count($skipped_items));?> à réviser</span></div><table class="widefat striped"><thead><tr><th>ID</th><th>Client</th><th>E-mail masqué</th><th>Motif</th></tr></thead><tbody><?php foreach($skipped_items as $item):?><tr><td><?php echo esc_html((string)$item['user_id']);?></td><td><?php echo esc_html($item['display_name']);?></td><td><?php echo esc_html($item['masked_email']);?></td><td><code><?php echo esc_html($item['reason']);?></code></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
          <div class="dip-migration-columns">
            <div><h3>Migration par lots</h3><p>Après avoir testé plusieurs clients et vérifié leurs commandes, portefeuille et récompenses, migrez 25 comptes par clic.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Migrer le prochain lot de comptes Nextend admissibles ?');"><?php wp_nonce_field('dip_migration_batch'); ?><input type="hidden" name="action" value="dip_migration_batch"><label><input type="checkbox" name="confirm_bulk" value="1" required> J’ai vérifié les comptes test et je confirme.</label><p><button class="button button-primary" <?php disabled(empty($dry)||empty($backups)); ?>>Migrer le prochain lot de 25</button></p></form><?php if($migrated):?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Annuler uniquement les liaisons créées par cet assistant ?');"><?php wp_nonce_field('dip_migration_rollback'); ?><input type="hidden" name="action" value="dip_migration_rollback"><button class="button button-link-delete">Annuler les liaisons créées par l’assistant</button></form><?php endif;?></div>
            <div><h3>Sauvegardes</h3><?php if(!$backups):?><p class="dip-empty">Aucune sauvegarde disponible.</p><?php else:?><table class="widefat striped"><thead><tr><th>Date</th><th>Libellé</th><th></th></tr></thead><tbody><?php foreach($backups as $id=>$backup):?><tr><td><?php echo esc_html(wp_date('Y-m-d H:i',(int)$backup['created_at']));?></td><td><?php echo esc_html($backup['label']);?></td><td><a class="button button-small" onclick="return confirm('Restaurer cette configuration ?');" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action'=>'dip_migration_restore','backup_id'=>$id],admin_url('admin-post.php')),'dip_migration_restore'));?>">Restaurer</a></td></tr><?php endforeach;?></tbody></table><?php endif;?><?php if($report):?><p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_migration_export'),'dip_migration_export'));?>">Exporter le rapport JSON</a></p><?php endif;?></div>
          </div>
          <div class="notice notice-info inline"><p><strong>Pour démarrer :</strong> gardez Nextend actif, ajoutez l’URI Delicat dans Google Cloud, lancez Analyse → Sauvegarde → Simulation, puis testez 2–3 vrais clients avant toute migration par lots.</p></div>
        </section>
        <?php
    }



    public function handle_export_security_csv() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_export_security_csv');
        DIP_Audit::record('security_export_created', 'notice', get_current_user_id());
        DIP_Operations::export_csv();
    }


    public function handle_assistant_analyze() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_assistant_analyze');
        $report = DIP_Assistant::analyze($this->settings());
        $this->event('assistant_analysis_completed', get_current_user_id(), 'notice', ['score' => (int) ($report['score'] ?? 0)]);
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_assistant'=>'done'], admin_url('options-general.php')));
        exit;
    }

    public function handle_assistant_bundle() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_assistant_bundle');
        $bundle = DIP_Assistant::support_bundle($this->settings());
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="delicat-identity-support-' . gmdate('Ymd-His') . '.json"');
        echo wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function render_assistant_center(array $s) {
        $report = DIP_Assistant::last_report();
        if (!$report) $report = DIP_Assistant::analyze($s);
        $issues = array_merge((array) ($report['issues'] ?? []), (array) ($report['recommendations'] ?? []));
        $events = DIP_Audit::recent(8);
        ?>
        <section class="dip-builder-card dip-assistant-card"><div class="dip-builder-head"><div><span>PHASE 13</span><h2>Assistant intelligent de diagnostic</h2><p>Analyse locale et explicable. Aucun secret, jeton, e-mail client, adresse IP ou journal brut n’est envoyé à un service externe.</p></div><strong><?php echo esc_html((int)($report['score'] ?? 0)); ?>/100</strong></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:16px">
          <div class="dip-provider-card"><small>État</small><strong style="display:block;font-size:22px"><?php echo esc_html(($report['status'] ?? '') === 'healthy' ? 'Sain' : (($report['status'] ?? '') === 'review' ? 'À vérifier' : 'Critique')); ?></strong></div>
          <div class="dip-provider-card"><small>Problèmes</small><strong style="display:block;font-size:22px"><?php echo esc_html(count((array)($report['issues'] ?? []))); ?></strong></div>
          <div class="dip-provider-card"><small>Recommandations</small><strong style="display:block;font-size:22px"><?php echo esc_html(count((array)($report['recommendations'] ?? []))); ?></strong></div>
          <div class="dip-provider-card"><small>Dernière analyse</small><strong style="display:block;font-size:16px"><?php echo esc_html(!empty($report['generated_at']) ? wp_date('Y-m-d H:i', (int)$report['generated_at']) : '—'); ?></strong></div>
        </div>
        <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_assistant_analyze'), 'dip_assistant_analyze')); ?>">Analyser maintenant</a> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_assistant_bundle'), 'dip_assistant_bundle')); ?>">Exporter un dossier de support sécurisé</a></p>
        <div class="dip-provider-grid">
        <?php if (!$issues): ?><div class="dip-provider-card"><strong>✅ Aucun problème important détecté</strong><p>Continuez les tests réguliers sur staging et conservez les sauvegardes.</p></div><?php endif; ?>
        <?php foreach (array_slice($issues,0,12) as $item): ?><div class="dip-provider-card dip-ai-<?php echo esc_attr($item['severity']); ?>"><strong><?php echo $item['severity']==='critical'?'❌ ':($item['severity']==='warning'?'⚠️ ':'💡 '); echo esc_html($item['title']); ?></strong><p><?php echo esc_html($item['explanation']); ?></p><p><b>Action :</b> <?php echo esc_html($item['fix']); ?></p></div><?php endforeach; ?>
        </div>
        <h3>Explication des derniers événements</h3><table class="widefat striped"><thead><tr><th>Événement</th><th>Signification</th><th>Action recommandée</th></tr></thead><tbody>
        <?php if(!$events): ?><tr><td colspan="3">Aucun événement récent.</td></tr><?php endif; ?>
        <?php foreach($events as $event): $ex=DIP_Assistant::explain_event($event['event_type']); ?><tr><td><code><?php echo esc_html($event['event_type']); ?></code></td><td><?php echo esc_html($ex[0]); ?></td><td><?php echo esc_html($ex[1]); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        </section>
        <?php
    }

    public function handle_repair_schedules() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_repair_schedules');
        DIP_Audit::install(); DIP_Devices::install();
        DIP_Audit::record('maintenance_schedules_repaired', 'notice', get_current_user_id());
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_repair'=>'done'], admin_url('options-general.php'))); exit;
    }

    private function render_operations_dashboard(array $s) {
        $health = DIP_Operations::health($s);
        $compat = DIP_Operations::compatibility();
        $summary = DIP_Audit::summary(30);
        $devices = DIP_Devices::summary(30);
        $usage = DIP_Operations::provider_usage(30);
        $healthy = count(array_filter($health, function($c){ return $c['status']==='healthy'; }));
        $total = count($health);
        ?>
        <section class="dip-builder-card"><div class="dip-builder-head"><div><span>PHASE 11</span><h2>Centre des opérations</h2><p>Vue unifiée de la santé, des fournisseurs, de la sécurité et de la compatibilité.</p></div><strong><?php echo esc_html($healthy . '/' . $total); ?> contrôles sains</strong></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
          <?php foreach ([['Connexions 30 j',$summary['success']],['Tentatives bloquées',$summary['blocked']],['Appareils actifs',$devices['total']],['Google',$usage['google']],['Microsoft',$usage['microsoft']]] as $c): ?><div class="dip-provider-card"><small><?php echo esc_html($c[0]); ?></small><strong style="display:block;font-size:25px"><?php echo esc_html($c[1]); ?></strong></div><?php endforeach; ?>
        </div>
        <h3>Santé système</h3><div class="dip-provider-grid">
        <?php foreach ($health as $c): ?><div class="dip-provider-card"><strong><?php echo $c['status']==='healthy' ? '✅ ' : '❌ '; echo esc_html($c['label']); ?></strong><p><?php echo esc_html($c['detail']); ?></p></div><?php endforeach; ?>
        </div>
        <h3>Compatibilité</h3><div class="dip-provider-grid">
        <?php foreach ($compat as $name=>$active): ?><div class="dip-provider-card"><strong><?php echo $active ? '✅ ' : '— '; echo esc_html($name); ?></strong><p><?php echo $active ? 'Détecté' : 'Non détecté'; ?></p></div><?php endforeach; ?>
        </div>
        <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_export_security_csv'), 'dip_export_security_csv')); ?>">Exporter les événements CSV</a> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_repair_schedules'), 'dip_repair_schedules')); ?>">Réparer les tâches planifiées</a></p>
        <p><strong>Mode maintenance :</strong> <?php echo ($s['social_login_maintenance'] ?? 'no') === 'yes' ? 'Actif — les boutons publics sont masqués' : 'Inactif'; ?></p>
        </section>
        <?php
    }

    public function handle_performance_run() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_performance_run');
        DIP_Performance::scheduled_maintenance();
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_performance'=>'completed'], admin_url('options-general.php')));
        exit;
    }

    public function handle_performance_analyze() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_performance_analyze');
        DIP_Performance::analyze_tables();
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_performance'=>'analyzed'], admin_url('options-general.php')));
        exit;
    }

    public function handle_performance_purge() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_performance_purge');
        DIP_Performance::purge_cache();
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_performance'=>'purged'], admin_url('options-general.php')));
        exit;
    }

    private function render_performance_center(array $s) {
        $health = DIP_Performance::health();
        $last = (int) get_option('dip_last_performance_maintenance', 0);
        $rows = 0;
        foreach (($health['tables'] ?? []) as $table) $rows += (int) ($table['rows'] ?? 0);
        ?>
        <section class="dip-builder-card" style="margin-top:20px">
          <div class="dip-builder-head"><div><span>PHASE 14</span><h2>Performance, cache et maintenance</h2><p>Optimisations limitées aux données Delicat Identity, sans purger le panier WooCommerce ni les caches globaux.</p></div><strong><?php echo !empty($health['persistent_object_cache']) ? 'Cache objet persistant' : 'Cache WordPress standard'; ?></strong></div>
          <table class="form-table" role="presentation">
            <?php $this->checkbox_row('performance_maintenance_enabled','Maintenance automatique quotidienne',$s); ?>
            <?php $this->checkbox_row('conditional_assets','Charger les ressources uniquement où nécessaire',$s); ?>
            <?php $this->checkbox_row('performance_health_cache','Mettre en cache les diagnostics pendant 5 minutes',$s); ?>
          </table>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:12px 0">
            <div class="dip-stat"><span>Cache de page</span><strong><?php echo esc_html($health['page_cache']); ?></strong></div>
            <div class="dip-stat"><span>Lignes gérées</span><strong><?php echo esc_html(number_format_i18n($rows)); ?></strong></div>
            <div class="dip-stat"><span>WP-Cron</span><strong><?php echo !empty($health['cron_disabled']) ? 'Cron serveur requis' : 'Actif'; ?></strong></div>
            <div class="dip-stat"><span>Dernière maintenance</span><strong><?php echo $last ? esc_html(wp_date('Y-m-d H:i', $last)) : 'Jamais'; ?></strong></div>
          </div>
          <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_performance_run'), 'dip_performance_run')); ?>">Exécuter la maintenance</a>
          <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_performance_analyze'), 'dip_performance_analyze')); ?>">Analyser les tables</a>
          <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_performance_purge'), 'dip_performance_purge')); ?>">Vider uniquement le cache Identity</a></p>
          <p class="description">ANALYZE TABLE met à jour les statistiques de l’optimiseur MySQL. Aucune table n’est supprimée, tronquée ou réparée automatiquement.</p>
        </section>
        <?php
    }

    public function audit_user_created($user_id, $profile) {
        $this->event('customer_created', absint($user_id), 'notice');
    }

    public function audit_account_linked($user_id, $profile) {
        $this->event('account_linked', absint($user_id), 'notice');
    }

    public function audit_cleanup() {
        $s = $this->settings();
        DIP_Audit::cleanup($s['security_retention_days'] ?? 30);
    }

    public function handle_integrity_scan() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_integrity_scan');
        DIP_Integrity::scan();
        wp_safe_redirect(add_query_arg(['page' => 'delicat-identity', 'dip_scan' => 'done'], admin_url('options-general.php')));
        exit;
    }

    public function handle_clear_security_events() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_clear_security_events');
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . DIP_Audit::table());
        wp_safe_redirect(add_query_arg(['page' => 'delicat-identity', 'dip_events' => 'cleared'], admin_url('options-general.php')));
        exit;
    }

    private function security_score(array $s) {
        $score = 0;
        $score += DIP_Request::is_secure() ? 15 : 0;
        $score += !empty($s['client_id']) && !empty($s['client_secret']) ? 15 : 0;
        $score += ($s['security_log_enabled'] ?? 'yes') === 'yes' ? 10 : 0;
        $score += ($s['lockout_enabled'] ?? 'yes') === 'yes' ? 10 : 0;
        $score += ($s['strict_rest_firewall'] ?? 'yes') === 'yes' ? 15 : 0;
        $score += class_exists('DIP_Crypto') && DIP_Crypto::is_available() ? 10 : 0;
        $score += class_exists('DIP_Reauth') ? 10 : 0;
        $score += class_exists('DIP_Two_Factor') ? 10 : 0;
        $score += (int) ($s['rate_limit'] ?? 10) <= 10 ? 5 : 2;
        return min(100, $score);
    }

    private function render_security_center(array $s) {
        $summary = DIP_Audit::summary(30);
        $events = DIP_Audit::recent(25);
        $scan = get_option('dip_last_integrity_scan', []);
        $score = $this->security_score($s);
        ?>
        <hr><h2>Centre de sécurité</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;max-width:920px">
          <?php foreach ([['Score',$score.'/100'],['Connexions réussies',$summary['success']],['Tentatives bloquées',$summary['blocked']],['Alertes critiques',$summary['critical']]] as $card): ?>
          <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px"><div style="color:#646970;font-size:12px"><?php echo esc_html($card[0]); ?></div><strong style="font-size:26px"><?php echo esc_html($card[1]); ?></strong></div>
          <?php endforeach; ?>
        </div>
        <p><a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_integrity_scan'), 'dip_integrity_scan')); ?>">Analyser l’intégrité des comptes</a>
        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_clear_security_events'), 'dip_clear_security_events')); ?>" onclick="return confirm('Effacer le journal de sécurité ?');">Effacer le journal</a></p>
        <?php if (is_array($scan) && $scan): ?>
        <p><strong>Dernière analyse :</strong> <?php echo esc_html(wp_date('Y-m-d H:i', (int) $scan['checked_at'])); ?> — <?php echo esc_html($scan['linked_accounts']); ?> comptes liés, <?php echo esc_html($scan['duplicate_identities']); ?> doublons, <?php echo esc_html($scan['orphaned_references']); ?> références orphelines, <?php echo esc_html($scan['privileged_links']); ?> comptes privilégiés liés.</p>
        <?php endif; ?>
        <table class="widefat striped" style="max-width:1100px"><thead><tr><th>Date UTC</th><th>Événement</th><th>Niveau</th><th>Utilisateur</th><th>Empreinte</th></tr></thead><tbody>
        <?php if (!$events): ?><tr><td colspan="5">Aucun événement.</td></tr><?php endif; ?>
        <?php foreach ($events as $event): ?><tr><td><?php echo esc_html($event['created_at']); ?></td><td><code><?php echo esc_html($event['event_type']); ?></code></td><td><?php echo esc_html($event['severity']); ?></td><td><?php echo esc_html($event['user_id'] ?: '—'); ?></td><td><code><?php echo esc_html(substr($event['fingerprint'],0,12)); ?>…</code></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php
    }

    public function track_device($user_id, $profile = []) {
        $s = $this->settings();
        if (($s['trusted_devices_enabled'] ?? 'yes') === 'yes') DIP_Devices::register_login($user_id, $s);
    }

    public function device_cleanup() {
        $s = $this->settings();
        DIP_Devices::cleanup($s['device_retention_days'] ?? 90);
    }

    public function handle_device_action() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        }
        if (class_exists('DIP_Access_Guard') ? !DIP_Access_Guard::can_self_service() : !is_user_logged_in()) wp_die('Accès refusé.', '', ['response' => 403]);
        $uid = get_current_user_id();
        check_admin_referer('dip_device_action_' . $uid);
        $device_id = absint($_POST['device_id'] ?? 0);
        $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
        if ($operation === 'trust') DIP_Devices::set_trusted($uid, $device_id, true);
        elseif ($operation === 'untrust') DIP_Devices::set_trusted($uid, $device_id, false);
        elseif ($operation === 'forget') DIP_Devices::forget($uid, $device_id);
        else wp_die('Action invalide.');
        $this->event('device_' . $operation, $uid, 'notice');
        $redirect = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url(DIP_WooCommerce::ENDPOINT) : home_url('/');
        wp_safe_redirect($redirect); exit;
    }

    public function handle_revoke_other_sessions() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        }
        if (class_exists('DIP_Access_Guard') ? !DIP_Access_Guard::can_self_service() : !is_user_logged_in()) wp_die('Accès refusé.', '', ['response' => 403]);
        $uid = get_current_user_id();
        check_admin_referer('dip_revoke_other_sessions_' . $uid);
        if (function_exists('wp_destroy_other_sessions')) wp_destroy_other_sessions();
        else {
            require_once ABSPATH . WPINC . '/class-wp-session-tokens.php';
            $manager = WP_Session_Tokens::get_instance($uid);
            $manager->destroy_others(wp_get_session_token());
        }
        $mobile_revoked = class_exists('DIP_Mobile_API') ? DIP_Mobile_API::revoke_all_for_user($uid, 'user_revoke_other_sessions') : 0;
        $this->event('other_sessions_revoked', $uid, 'warning', ['mobile_revoked'=>(int)$mobile_revoked]);
        $redirect = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url(DIP_WooCommerce::ENDPOINT) : home_url('/');
        wp_safe_redirect(add_query_arg('dip_sessions', 'revoked', $redirect)); exit;
    }

    private function render_phase6(array $s) {
        $d = DIP_Devices::summary(30);
        global $wpdb;
        $new_users = (int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM " . DIP_Audit::table() . " WHERE event_type='user_created' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)");
        $returning = max(0, (int)DIP_Audit::summary(30)['success'] - $new_users);
        ?>
        <section class="dip-builder-card"><div class="dip-builder-head"><div><span>PHASE 6</span><h2>Analytics, appareils et sessions</h2><p>Statistiques respectueuses de la vie privée, sans IP brute ni cookie de session enregistré.</p></div><strong>30 derniers jours</strong></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
          <?php foreach ([['Appareils actifs',$d['total']],['Nouveaux appareils',$d['new']],['Appareils fiables',$d['trusted']],['Nouveaux clients',$new_users],['Connexions de retour',$returning]] as $c): ?><div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:15px"><small><?php echo esc_html($c[0]); ?></small><strong style="display:block;font-size:25px"><?php echo esc_html($c[1]); ?></strong></div><?php endforeach; ?>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:16px">
          <div><h3>Navigateurs</h3><table class="widefat striped"><tbody><?php if(!$d['browsers']) echo '<tr><td>Aucune donnée</td></tr>'; foreach($d['browsers'] as $r): ?><tr><td><?php echo esc_html($r['browser']); ?></td><td><?php echo esc_html($r['total']); ?></td></tr><?php endforeach; ?></tbody></table></div>
          <div><h3>Appareils</h3><table class="widefat striped"><tbody><?php if(!$d['platforms']) echo '<tr><td>Aucune donnée</td></tr>'; foreach($d['platforms'] as $r): ?><tr><td><?php echo esc_html($r['platform']); ?></td><td><?php echo esc_html($r['total']); ?></td></tr><?php endforeach; ?></tbody></table></div>
        </div></section>
        <?php
    }

    public function avatar_url($url, $id_or_email, $args) {
        if ($this->settings()['use_google_avatar'] !== 'yes') return $url;
        $user = false;
        if (is_numeric($id_or_email)) $user = get_user_by('id', (int) $id_or_email);
        elseif ($id_or_email instanceof WP_User) $user = $id_or_email;
        elseif ($id_or_email instanceof WP_Comment) $user = get_user_by('id', (int) $id_or_email->user_id);
        elseif (is_string($id_or_email)) $user = get_user_by('email', $id_or_email);
        if (!$user) return $url;
        $avatar = get_user_meta($user->ID, DIP_Identity::META_AVATAR, true);
        return $avatar && wp_http_validate_url($avatar) ? esc_url_raw($avatar) : $url;
    }

    public function approval_profile($user) {
        if (!current_user_can('manage_options')) return;
        $pending = DIP_Policy::is_pending($user->ID);
        wp_nonce_field('dip_approval_' . $user->ID, 'dip_approval_nonce');
        echo '<h2>Delicat Identity</h2><table class="form-table"><tr><th>Approbation du compte social</th><td><label><input type="checkbox" name="dip_identity_approved" value="1" ' . checked(!$pending, true, false) . '> Compte approuvé</label><p class="description">Décochez pour suspendre immédiatement toutes les connexions Delicat de ce compte (web et mobile).</p></td></tr></table>';
    }

    public function save_approval_profile($user_id) {
        if (!current_user_can('manage_options') || !current_user_can('edit_user', $user_id)) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dip_approval_nonce'] ?? '')), 'dip_approval_' . $user_id)) return;
        if (!empty($_POST['dip_identity_approved'])) DIP_Policy::approve($user_id);
        else DIP_Policy::mark_pending($user_id, ['provider'=>'admin']);
    }

    public function handle_sdk_self_test() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_sdk_self_test');
        DIP_SDK::run_self_test();
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity','dip_sdk'=>'tested'], admin_url('options-general.php')));
        exit;
    }

    public function handle_sdk_export() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé.');
        check_admin_referer('dip_sdk_export');
        $report = DIP_SDK::sanitized_report();
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="delicat-identity-support-' . gmdate('Ymd-His') . '.json"');
        echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function render_sdk_center() {
        $test = get_option(DIP_SDK::SELF_TEST_OPTION, []);
        $extensions = DIP_SDK::health();
        $counts = ['pass'=>0,'warn'=>0,'fail'=>0];
        foreach (($test['checks'] ?? []) as $check) if (isset($counts[$check['status']])) $counts[$check['status']]++;
        ?>
        <section class="dip-builder-card" style="margin-top:20px">
          <div class="dip-builder-head"><div><span>PHASE 15</span><h2>SDK, extensions et support long terme</h2><p>API publique versionnée, auto-tests, rapport de compatibilité et commandes WP-CLI.</p></div><strong>SDK <?php echo esc_html(DIP_SDK::API_VERSION); ?></strong></div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:12px 0">
            <div class="dip-stat"><span>Contrôles réussis</span><strong><?php echo esc_html($counts['pass']); ?></strong></div>
            <div class="dip-stat"><span>Avertissements</span><strong><?php echo esc_html($counts['warn']); ?></strong></div>
            <div class="dip-stat"><span>Échecs</span><strong><?php echo esc_html($counts['fail']); ?></strong></div>
            <div class="dip-stat"><span>Extensions SDK</span><strong><?php echo esc_html(count($extensions)); ?></strong></div>
          </div>
          <?php if (!empty($test['checks'])): ?><table class="widefat striped"><thead><tr><th>Contrôle</th><th>État</th><th>Détail</th></tr></thead><tbody><?php foreach ($test['checks'] as $key=>$check): ?><tr><td><code><?php echo esc_html($key); ?></code></td><td><?php echo $check['status']==='pass'?'✅':($check['status']==='warn'?'⚠️':'❌'); ?> <?php echo esc_html($check['status']); ?></td><td><?php echo esc_html($check['message']); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
          <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_sdk_self_test'), 'dip_sdk_self_test')); ?>">Exécuter les auto-tests</a>
          <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_sdk_export'), 'dip_sdk_export')); ?>">Exporter le rapport sécurisé</a></p>
          <p class="description">Hooks principaux : <code>dip_before_authentication</code>, <code>dip_login_success</code>, <code>dip_user_created</code>, <code>dip_account_linked</code>, <code>dip_identity_extensions</code> et <code>dip_sdk_ready</code>. Aucun secret ni jeton n’est inclus dans le rapport.</p>
        </section>
        <?php
    }


}
