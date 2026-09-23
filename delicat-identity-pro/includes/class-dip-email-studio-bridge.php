<?php
defined('ABSPATH') || exit;

/**
 * Embedded Email Studio bridge.
 *
 * One source of truth for WordPress, WooCommerce and Delicat Identity email design.
 * The former standalone Email Studio plugin is migrated/deactivated to avoid double hooks.
 */
final class DIP_Email_Studio_Bridge {
    const OPTION = 'delicat_email_studio_settings_v3';
    const LEGACY_OPTION = 'delicat_email_settings';
    const OLD_IDENTITY_OPTION = 'dip_email_designer_settings';
    const MIGRATION_FLAG = 'dip_email_studio_unified_migrated_690';

    private static $loaded = false;
    private static $admin_loaded = false;

    public static function init() {
        add_action('admin_notices', [__CLASS__, 'legacy_cleanup_notice']);
        add_action('admin_post_dip_delete_legacy_email_studio', [__CLASS__, 'delete_legacy_email_studio']);

        // Plugin replacement/update does not always execute an activation hook.
        // Finish the migration on the next privileged admin request as well.
        if (self::standalone_active() || get_option(self::MIGRATION_FLAG, '') !== DIP_VERSION) {
            add_action('admin_init', [__CLASS__, 'maybe_complete_migration'], 1);
        }

        // Never register two e-mail renderers in the same request. If the old
        // standalone plugin is active it will finish this request alone; the
        // embedded studio takes over cleanly after migration/deactivation.
        if (self::standalone_active()) {
            add_action('admin_notices', [__CLASS__, 'standalone_notice']);
            return;
        }
        if (self::load() && self::needs_admin_ui()) {
            self::load_admin_ui();
        }
    }

    public static function maybe_complete_migration() {
        if (!is_admin() || !current_user_can('activate_plugins')) return;
        self::install();
    }

    public static function install() {
        self::migrate_settings();
        self::deactivate_standalone();
        update_option(self::MIGRATION_FLAG, DIP_VERSION, false);
    }

    public static function load() {
        if (self::$loaded) return true;
        if (self::standalone_active()) return false;

        if (!defined('DIPES_VERSION')) define('DIPES_VERSION', DIP_VERSION);
        if (!defined('DIPES_DIR')) define('DIPES_DIR', trailingslashit(DIP_DIR . 'includes/email-studio'));
        if (!defined('DIPES_URL')) define('DIPES_URL', trailingslashit(DIP_URL . 'includes/email-studio'));
        if (!defined('DIPES_OPTION')) define('DIPES_OPTION', self::OPTION);
        if (!defined('DIPES_LEGACY_OPTION')) define('DIPES_LEGACY_OPTION', self::LEGACY_OPTION);

        $base = DIP_DIR . 'includes/email-studio/';
        // Runtime hooks/templates are required for WordPress/WooCommerce mail.
        // Preview/admin rendering is intentionally excluded from storefront boots.
        foreach (['presets.php','settings.php','helpers.php','render.php','templates.php','wordpress-notifications.php'] as $file) {
            $path = $base . $file;
            if (!is_readable($path)) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('email_studio_module_missing', 'error', get_current_user_id(), ['file'=>$file]);
                return false;
            }
            require_once $path;
        }
        self::$loaded = true;
        return true;
    }

    private static function needs_admin_ui() {
        if (!is_admin() || wp_doing_ajax()) return false;
        $action = sanitize_key((string) wp_unslash($_REQUEST['action'] ?? ''));
        $page = sanitize_key((string) wp_unslash($_GET['page'] ?? ''));
        return 'delicat-identity-emails' === $page || 0 === strpos($action, 'dipes_');
    }

    private static function load_admin_ui() {
        if (self::$admin_loaded) return true;
        if (!self::load()) return false;
        $base = DIP_DIR . 'includes/email-studio/';
        foreach (['preview.php','admin.php'] as $file) {
            $path = $base . $file;
            if (!is_readable($path)) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('email_studio_admin_module_missing', 'error', get_current_user_id(), ['file'=>$file]);
                return false;
            }
            require_once $path;
        }
        self::$admin_loaded = true;
        return true;
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;
        if (!self::load() || !self::load_admin_ui() || !function_exists('dipes_admin_page')) {
            echo '<div class="wrap"><h1>Delicat Identity — Email Studio</h1><div class="notice notice-error"><p>Le module Email Studio n’a pas pu être chargé.</p></div></div>';
            return;
        }
        dipes_admin_page();
    }

    public static function standalone_notice() {
        if (!current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-warning"><p><strong>Delicat Identity Pro:</strong> l’ancien plugin Delicat Email Studio Pro est encore actif pour cette requête. La migration sécurisée va le désactiver automatiquement; le module e-mail unifié prendra le relais à la prochaine requête.</p></div>';
    }

    public static function legacy_cleanup_notice() {
        if (!current_user_can('delete_plugins')) return;
        $legacy = self::standalone_basenames();
        if (!$legacy || self::standalone_active()) return;
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=dip_delete_legacy_email_studio'),
            'dip_delete_legacy_email_studio'
        );
        echo '<div class="notice notice-info"><p><strong>Delicat Identity Pro:</strong> la migration Email Studio est terminée. L’ancien plugin autonome est désactivé et n’est plus utilisé. <a class="button button-secondary" href="' . esc_url($url) . '">Supprimer l’ancien Email Studio</a></p></div>';
    }

    public static function delete_legacy_email_studio() {
        if (!current_user_can('delete_plugins')) wp_die(esc_html__('Permission insuffisante.', 'delicat-google-login'), '', ['response'=>403]);
        check_admin_referer('dip_delete_legacy_email_studio');
        if (!function_exists('delete_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $legacy = self::standalone_basenames();
        if ($legacy) {
            self::deactivate_standalone();
            $result = delete_plugins($legacy);
            if (is_wp_error($result)) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('legacy_email_studio_delete_failed', 'warning', get_current_user_id(), ['count'=>count($legacy)]);
                wp_safe_redirect(add_query_arg(['page'=>'delicat-identity-emails','dip_legacy_cleanup'=>'failed'], admin_url('admin.php')));
                exit;
            }
            if (class_exists('DIP_Audit')) DIP_Audit::record('legacy_email_studio_deleted', 'notice', get_current_user_id(), ['count'=>count($legacy)]);
        }
        wp_safe_redirect(add_query_arg(['page'=>'delicat-identity-emails','dip_legacy_cleanup'=>'done'], admin_url('admin.php')));
        exit;
    }

    private static function plugin_inventory() {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        return function_exists('get_plugins') ? get_plugins() : [];
    }

    public static function standalone_basenames() {
        $matches = [];
        foreach (self::plugin_inventory() as $basename => $data) {
            $name = isset($data['Name']) ? (string)$data['Name'] : '';
            if (stripos($name, 'Delicat Email Studio Pro') !== false || preg_match('~^delicat-email-studio-pro[^/]*/delicat-email-studio-pro\.php$~', $basename)) {
                $matches[] = $basename;
            }
        }
        return array_values(array_unique($matches));
    }

    public static function standalone_active() {
        if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        foreach (self::standalone_basenames() as $basename) {
            if (function_exists('is_plugin_active') && is_plugin_active($basename)) return true;
        }
        return false;
    }

    public static function deactivate_standalone() {
        if (!function_exists('deactivate_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $active = [];
        foreach (self::standalone_basenames() as $basename) {
            if (function_exists('is_plugin_active') && is_plugin_active($basename)) $active[] = $basename;
        }
        if ($active && function_exists('deactivate_plugins')) {
            deactivate_plugins($active, true, false);
            if (class_exists('DIP_Audit')) DIP_Audit::record('standalone_email_studio_deactivated', 'notice', get_current_user_id(), ['count'=>count($active)]);
        }
    }

    public static function migrate_settings() {
        $existing = get_option(self::OPTION, null);
        if (is_array($existing) && !empty($existing)) {
            // Existing Email Studio settings are already the preferred source.
            return;
        }

        $old = get_option(self::OLD_IDENTITY_OPTION, []);
        if (!is_array($old) || !$old) return;

        // Load only the settings primitives to obtain secure defaults, without
        // registering the full renderer during activation.
        if (!defined('DIPES_VERSION')) define('DIPES_VERSION', DIP_VERSION);
        if (!defined('DIPES_DIR')) define('DIPES_DIR', trailingslashit(DIP_DIR . 'includes/email-studio'));
        if (!defined('DIPES_URL')) define('DIPES_URL', trailingslashit(DIP_URL . 'includes/email-studio'));
        if (!defined('DIPES_OPTION')) define('DIPES_OPTION', self::OPTION);
        if (!defined('DIPES_LEGACY_OPTION')) define('DIPES_LEGACY_OPTION', self::LEGACY_OPTION);
        require_once DIP_DIR . 'includes/email-studio/presets.php';
        require_once DIP_DIR . 'includes/email-studio/settings.php';

        $settings = dipes_defaults();
        $map = [
            'logo_url'   => 'logo_url',
            'accent'     => 'accent',
            'background' => 'page_bg',
            'card'       => 'card_bg',
            'text'       => 'text',
            'muted'      => 'muted',
            'radius'     => 'radius',
        ];
        foreach ($map as $old_key => $new_key) {
            if (!array_key_exists($old_key, $old)) continue;
            $value = $old[$old_key];
            if (in_array($new_key, ['accent','page_bg','card_bg','text','muted'], true)) {
                $value = sanitize_hex_color($value) ?: $settings[$new_key];
            } elseif ('logo_url' === $new_key) {
                $value = esc_url_raw($value);
            } elseif ('radius' === $new_key) {
                $value = min(32, max(0, absint($value)));
            }
            $settings[$new_key] = $value;
        }
        if (!empty($old['from_name'])) $settings['brand_name'] = sanitize_text_field($old['from_name']);
        if (!empty($old['from_email']) && is_email($old['from_email'])) $settings['support_email'] = sanitize_email($old['from_email']);
        if (!empty($old['footer_text'])) $settings['footer_note'] = sanitize_text_field($old['footer_text']);

        // Keep related surfaces coherent.
        $settings['button_bg'] = $settings['accent'];
        $settings['card_border'] = $settings['border'];
        update_option(self::OPTION, dipes_normalize_settings($settings), false);

        if (class_exists('DIP_Audit')) DIP_Audit::record('identity_email_settings_migrated', 'notice', get_current_user_id());
    }
}
