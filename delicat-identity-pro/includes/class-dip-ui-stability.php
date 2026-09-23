<?php
defined('ABSPATH') || exit;

final class DIP_UI_Stability {
    const VERSION_OPTION = 'dip_ui_stability_version';
    const PAGE = 'dip-ui-stability';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 5);
        add_action('admin_menu', [__CLASS__, 'consolidate_menus'], 999);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets'], 50);
        add_action('wp_enqueue_scripts', [__CLASS__, 'front_assets'], 50);
        add_action('login_enqueue_scripts', [__CLASS__, 'front_assets'], 50);
        add_action('admin_post_dip_ui_repair', [__CLASS__, 'repair']);
        add_filter('admin_body_class', [__CLASS__, 'admin_body_class']);
        add_action('admin_notices', [__CLASS__, 'upgrade_notice']);
        add_action('all_admin_notices', [__CLASS__, 'app_navbar'], 1);
        add_action('init', [__CLASS__, 'maybe_upgrade'], 2);
    }

    public static function install() {
        update_option(self::VERSION_OPTION, DIP_VERSION, false);
        if (class_exists('DIP_Customer_Dashboard')) DIP_Customer_Dashboard::activate();
        flush_rewrite_rules(false);
    }

    public static function maybe_upgrade() {
        if (get_option(self::VERSION_OPTION) === DIP_VERSION) return;
        self::install();
    }

    public static function menu() {
        add_menu_page(
            __('Delicat Identity Pro', 'delicat-google-login'),
            __('Delicat Identity', 'delicat-google-login'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'page'],
            'dashicons-shield-alt',
            57
        );
        add_submenu_page(self::PAGE, __('Vue d’ensemble', 'delicat-google-login'), __('Vue d’ensemble', 'delicat-google-login'), 'manage_options', self::PAGE, [__CLASS__, 'page']);
        add_submenu_page(self::PAGE, 'Réglages', 'Réglages', 'manage_options', 'delicat-identity', [DIP_Plugin::instance(), 'settings_page']);
        add_submenu_page(self::PAGE, 'Fournisseurs', 'Fournisseurs', 'manage_options', 'delicat-identity-providers', ['DIP_Provider_Manager', 'render']);
        add_submenu_page(self::PAGE, 'Visual Builder', 'Visual Builder', 'manage_options', 'delicat-identity-builder', ['DIP_Visual_Builder_Pro', 'page']);
        add_submenu_page(self::PAGE, 'Sécurité', 'Sécurité', 'manage_options', 'dip-security-center', ['DIP_Security_Center', 'admin_page']);
        add_submenu_page(self::PAGE, 'WooCommerce', 'WooCommerce', 'manage_options', 'dip-woocommerce-pro', ['DIP_WooCommerce_Pro', 'page']);
        add_submenu_page(self::PAGE, 'App Sync', 'App Sync', 'manage_options', 'dip-app-sync-v2', ['DIP_App_Sync_V2', 'admin_page']);
        add_submenu_page(self::PAGE, 'Analytics', 'Analytics', 'manage_options', 'dip-analytics', ['DIP_Analytics', 'render_page']);
        add_submenu_page(self::PAGE, 'E-mails', 'E-mails', 'manage_options', 'delicat-identity-emails', ['DIP_Email_Studio_Bridge', 'page']);
        add_submenu_page(self::PAGE, 'Authentification', 'Authentification', 'manage_options', 'dip-auth-methods', ['DIP_Passwordless_Registration', 'settings_page']);
        add_submenu_page(self::PAGE, 'Santé système', 'Santé système', 'manage_options', 'dip-foundation-health', ['DIP_Foundation', 'render_page']);
    }

    public static function consolidate_menus() {
        foreach (['delicat-identity','delicat-identity-providers','delicat-identity-builder','dip-security-center','dip-foundation-health','dip-woocommerce-pro','dip-app-sync-v2','delicat-identity-emails','dip-auth-methods'] as $slug) {
            remove_submenu_page('options-general.php', $slug);
        }
        remove_menu_page('dip-analytics');
    }

    private static function plugin_pages() {
        return [
            self::PAGE,
            'delicat-identity', 'delicat-identity-providers', 'delicat-identity-builder',
            'dip-security-center', 'dip-foundation-health', 'dip-woocommerce-pro',
            'dip-app-sync-v2', 'dip-analytics', 'delicat-identity-emails', 'dip-auth-methods'
        ];
    }

    public static function is_plugin_admin_page() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return in_array($page, self::plugin_pages(), true);
    }

    public static function admin_assets() {
        if (!self::is_plugin_admin_page()) return;
        wp_enqueue_style('dip-admin', DIP_URL . 'assets/admin.css', [], DIP_VERSION);
        wp_enqueue_style('dip-ui-stability', DIP_URL . 'assets/ui-stability.css', ['dip-admin'], DIP_VERSION);
        wp_enqueue_script('dip-ui-stability', DIP_URL . 'assets/ui-stability.js', [], DIP_VERSION, true);
        wp_localize_script('dip-ui-stability', 'dipUiStability', [
            'copied' => __('Copié', 'delicat-google-login'),
            'saving' => __('Enregistrement…', 'delicat-google-login'),
        ]);
    }

    public static function front_assets() {
        if (is_admin()) return;
        wp_enqueue_style('dip-ui-front', DIP_URL . 'assets/ui-front.css', [], DIP_VERSION);
    }

    public static function admin_body_class($classes) {
        return self::is_plugin_admin_page() ? $classes . ' dip-modern-admin ' : $classes;
    }

    private static function nav_items() {
        return [
            [self::PAGE, 'Accueil', 'dashicons-grid-view'],
            ['delicat-identity', 'Réglages', 'dashicons-admin-generic'],
            ['delicat-identity-providers', 'Fournisseurs', 'dashicons-networking'],
            ['delicat-identity-builder', 'Visual Builder', 'dashicons-art'],
            ['dip-security-center', 'Sécurité', 'dashicons-shield'],
            ['dip-woocommerce-pro', 'WooCommerce', 'dashicons-cart'],
            ['dip-app-sync-v2', 'App Sync', 'dashicons-smartphone'],
            ['dip-analytics', 'Analytics', 'dashicons-chart-area'],
            ['delicat-identity-emails', 'E-mails', 'dashicons-email-alt'],
            ['dip-auth-methods', 'Authentification', 'dashicons-unlock'],
            ['dip-foundation-health', 'Santé système', 'dashicons-heart'],
        ];
    }

    public static function app_navbar() {
        if (!self::is_plugin_admin_page() || !current_user_can('manage_options')) return;
        $current = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : self::PAGE;
        echo '<div class="dip-app-shell-header">';
        echo '<div class="dip-app-brand"><span class="dashicons dashicons-shield-alt"></span><div><strong>Delicat Identity Pro</strong><small>Centre d’identité unifié</small></div></div>';
        echo '<button type="button" class="dip-app-nav-toggle" aria-expanded="false"><span class="dashicons dashicons-menu-alt3"></span><span>Modules</span></button>';
        echo '<nav class="dip-app-navbar" aria-label="Navigation Delicat Identity">';
        foreach (self::nav_items() as $item) {
            $active = $current === $item[0] ? ' is-active' : '';
            echo '<a class="dip-app-nav-item' . esc_attr($active) . '" href="' . esc_url(admin_url('admin.php?page=' . $item[0])) . '"><span class="dashicons ' . esc_attr($item[2]) . '"></span><span>' . esc_html($item[1]) . '</span></a>';
        }
        echo '</nav></div>';
    }

    public static function upgrade_notice() {
        if (!self::is_plugin_admin_page() || !current_user_can('manage_options')) return;
        if (empty($_GET['dip_repaired'])) return;
        echo '<div class="notice notice-success is-dismissible"><p><strong>Delicat Identity :</strong> ' . esc_html__('réparation terminée. Les tables, tâches, routes et caches ont été réinitialisés sans supprimer les comptes clients.', 'delicat-google-login') . '</p></div>';
    }

    private static function checks() {
        global $wpdb;
        $checks = [];
        $checks[] = ['Moteur principal', class_exists('DIP_Plugin'), 'Le cœur du plugin doit être chargé.'];
        $checks[] = ['Google OAuth', class_exists('DIP_Google'), 'Module Google disponible.'];
        $checks[] = ['Microsoft OAuth', class_exists('DIP_Microsoft'), 'Module Microsoft disponible.'];
        $checks[] = ['WooCommerce', !class_exists('WooCommerce') || class_exists('DIP_WooCommerce_Pro'), 'Intégration WooCommerce chargée quand WooCommerce est actif.'];
        $checks[] = ['App Sync', class_exists('DIP_App_Sync_V2'), 'Synchronisation web/application disponible.'];
        $checks[] = ['Analytics', class_exists('DIP_Analytics'), 'Rapports d’identité disponibles.'];
        $checks[] = ['Emails', class_exists('DIP_Email_Studio_Bridge'), 'Email Studio unifié disponible.'];
        $checks[] = ['Passwordless', class_exists('DIP_Passwordless_Registration'), 'OTP et magic links disponibles.'];
        $checks[] = ['HTTPS', is_ssl(), 'HTTPS est requis en production pour OAuth et les sessions.'];
        $checks[] = ['REST API', !empty(get_option('permalink_structure')), 'Des permaliens lisibles sont recommandés pour les routes API.'];

        $tables = [
            $wpdb->prefix . 'dip_audit',
            $wpdb->prefix . 'dip_devices',
        ];
        foreach ($tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
            $checks[] = ['Table ' . str_replace($wpdb->prefix, '', $table), $exists, 'Table requise pour les journaux ou appareils.'];
        }
        return $checks;
    }

    private static function links() {
        return [
            ['Réglages principaux', 'admin.php?page=delicat-identity', 'dashicons-admin-generic', 'OAuth, redirections et migration'],
            ['Fournisseurs', 'admin.php?page=delicat-identity-providers', 'dashicons-networking', 'Google, Microsoft et comptes liés'],
            ['Visual Builder', 'admin.php?page=delicat-identity-builder', 'dashicons-art', 'Créer une interface de connexion premium'],
            ['Sécurité', 'admin.php?page=dip-security-center', 'dashicons-shield', 'Sessions, appareils et événements'],
            ['WooCommerce', 'admin.php?page=dip-woocommerce-pro', 'dashicons-cart', 'Compte client et checkout'],
            ['App Sync', 'admin.php?page=dip-app-sync-v2', 'dashicons-smartphone', 'Connexion web et application'],
            ['Analytics', 'admin.php?page=dip-analytics', 'dashicons-chart-area', 'Rapports et tendances de connexion'],
            ['E-mails', 'admin.php?page=delicat-identity-emails', 'dashicons-email-alt', 'Templates et notifications'],
            ['Authentification', 'admin.php?page=dip-auth-methods', 'dashicons-unlock', 'OTP, magic links et inscription'],
            ['Santé système', 'admin.php?page=dip-foundation-health', 'dashicons-heart', 'Diagnostic et réparation'],
        ];
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;
        $checks = self::checks();
        $passing = count(array_filter($checks, static function($row){ return !empty($row[1]); }));
        $score = $checks ? (int) round(($passing / count($checks)) * 100) : 0;
        ?>
        <div class="wrap dip-admin-wrap dip-ui-hub">
            <section class="dip-admin-hero dip-ui-hero">
                <div>
                    <span class="dip-eyebrow">UI STABILITY RELEASE</span>
                    <h1>Delicat Identity Pro</h1>
                    <p>Un seul centre de contrôle pour configurer, tester et réparer tous les modules d’identité.</p>
                </div>
                <div class="dip-hero-score"><small>État du système</small><strong><?php echo esc_html((string)$score); ?>%</strong><span><?php echo esc_html($passing . '/' . count($checks)); ?> contrôles</span></div>
            </section>

            <section class="dip-ui-overview">
                <article><span class="dashicons dashicons-yes-alt"></span><div><small>Modules opérationnels</small><strong><?php echo esc_html($passing . ' / ' . count($checks)); ?></strong></div></article>
                <article><span class="dashicons dashicons-lock"></span><div><small>Connexion sécurisée</small><strong><?php echo is_ssl() ? 'HTTPS actif' : 'Action requise'; ?></strong></div></article>
                <article><span class="dashicons dashicons-admin-appearance"></span><div><small>Interface active</small><strong>Unified App 2.0</strong></div></article>
                <a href="<?php echo esc_url(admin_url('admin.php?page=delicat-identity-builder')); ?>"><span class="dashicons dashicons-art"></span><div><small>Action rapide</small><strong>Personnaliser le login</strong></div></a>
            </section>

            <div class="dip-ui-section-title"><div><span>MODULES</span><h2>Votre centre d’identité</h2></div><p>Configurez chaque fonctionnalité depuis une seule application.</p></div>
            <div class="dip-ui-module-grid">
                <?php foreach (self::links() as $item): ?>
                    <a class="dip-ui-module-card" href="<?php echo esc_url(admin_url($item[1])); ?>">
                        <span class="dip-ui-card-icon dashicons <?php echo esc_attr($item[2]); ?>"></span>
                        <span class="dip-ui-card-copy"><strong><?php echo esc_html($item[0]); ?></strong><small><?php echo esc_html($item[3]); ?></small></span>
                        <span class="dip-ui-card-arrow dashicons dashicons-arrow-right-alt2"></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <section class="dip-ui-panel">
                <div class="dip-ui-panel-head"><div><h2>Diagnostic rapide</h2><p>Détecte les modules absents, tables manquantes et prérequis de fonctionnement.</p></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="dip_ui_repair"><?php wp_nonce_field('dip_ui_repair'); ?>
                    <button class="button button-primary dip-ui-primary">Réparer automatiquement</button>
                </form></div>
                <div class="dip-ui-checks">
                    <?php foreach ($checks as $row): ?>
                        <div class="dip-ui-check <?php echo $row[1] ? 'is-ok' : 'is-warning'; ?>">
                            <span class="dashicons <?php echo $row[1] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                            <div><strong><?php echo esc_html($row[0]); ?></strong><small><?php echo esc_html($row[2]); ?></small></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dip-ui-panel dip-ui-shortcodes">
                <h2>Shortcodes prêts à utiliser</h2>
                <div class="dip-ui-code-grid">
                    <?php foreach (['[delicat_auth_panel]','[delicat_passwordless_login]','[delicat_registration_form]','[delicat_connected_accounts]','[delicat_security_center]','[delicat_app_pairing]','[delicat_identity_dashboard]'] as $code): ?>
                        <button type="button" class="dip-ui-copy" data-copy="<?php echo esc_attr($code); ?>"><code><?php echo esc_html($code); ?></code><span class="dashicons dashicons-admin-page"></span></button>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php
    }

    public static function repair() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Permission refusée.', 'delicat-google-login'), 403);
        check_admin_referer('dip_ui_repair');
        if (class_exists('DIP_Audit')) DIP_Audit::install();
        if (class_exists('DIP_Devices')) DIP_Devices::install();
        if (class_exists('DIP_Mobile_API')) DIP_Mobile_API::install();
        if (class_exists('DIP_Foundation')) DIP_Foundation::activate();
        if (class_exists('DIP_App_Sync_V2')) DIP_App_Sync_V2::install();
        if (class_exists('DIP_Customer_Dashboard')) DIP_Customer_Dashboard::activate();
        if (class_exists('DIP_Analytics')) DIP_Analytics::install();
        if (class_exists('DIP_Email_Studio_Bridge')) DIP_Email_Studio_Bridge::install();
        if (class_exists('DIP_Passwordless_Registration')) DIP_Passwordless_Registration::install();
        delete_transient('dip_health_snapshot');
        delete_transient('dip_analytics_summary');
        flush_rewrite_rules(false);
        if (class_exists('DIP_Audit')) DIP_Audit::record('ui_system_repaired', 'notice', get_current_user_id());
        wp_safe_redirect(add_query_arg('dip_repaired', '1', admin_url('admin.php?page=' . self::PAGE)));
        exit;
    }
}
