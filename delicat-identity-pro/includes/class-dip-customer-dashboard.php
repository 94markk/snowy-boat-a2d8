<?php
defined('ABSPATH') || exit;

final class DIP_Customer_Dashboard {
    const ENDPOINT = 'identity-center';
    const SHORTCODE = 'delicat_identity_dashboard';

    public static function init() {
        add_action('init', [__CLASS__, 'register_endpoint']);
        add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'trim_account_assets'], 100);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_item'], 35);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'endpoint']);
        // A real WooCommerce endpoint: is_wc_endpoint_url() and the page title work.
        add_filter('woocommerce_get_query_vars', [__CLASS__, 'query_vars']);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', [__CLASS__, 'endpoint_title']);
    }

    public static function activate() {
        self::register_endpoint();
        flush_rewrite_rules(false);
    }

    public static function register_endpoint() {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public static function query_vars($vars) {
        $vars = is_array($vars) ? $vars : [];
        if (!isset($vars[self::ENDPOINT])) $vars[self::ENDPOINT] = self::ENDPOINT;
        return $vars;
    }

    public static function endpoint_title($title = '') {
        return __('Identité et sécurité', 'delicat-google-login');
    }

    public static function menu_item($items) {
        if (!is_user_logged_in()) return $items;
        $logout = isset($items['customer-logout']) ? $items['customer-logout'] : null;
        unset($items['customer-logout']);
        $items[self::ENDPOINT] = __('Identité et sécurité', 'delicat-google-login');
        if ($logout !== null) $items['customer-logout'] = $logout;
        return $items;
    }

    public static function endpoint() { echo self::shortcode(['integrated'=>'yes']); }

    private static function is_endpoint_request() {
        return function_exists('is_account_page') && is_account_page() && get_query_var(self::ENDPOINT, null) !== null;
    }

    /** Is the shortcode in this page's content or in its Elementor data? */
    private static function page_has_shortcode() {
        if (!is_singular()) return false;
        global $post;
        if (!$post instanceof WP_Post) return false;
        if (has_shortcode((string) $post->post_content, self::SHORTCODE)) return true;
        $builder = get_post_meta($post->ID, '_elementor_data', true);
        return is_string($builder) && $builder !== '' && strlen($builder) <= 2097152
            && (strpos($builder, '[' . self::SHORTCODE) !== false || strpos($builder, '"' . self::SHORTCODE . '"') !== false);
    }

    private static function enqueue_style() {
        wp_enqueue_style('dip-customer-dashboard', DIP_URL . 'assets/customer-dashboard.css', [], DIP_VERSION);
    }

    public static function assets() {
        if (!is_user_logged_in()) return;
        if (self::is_endpoint_request() || self::page_has_shortcode()) self::enqueue_style();
    }

    /**
     * customer-dashboard.css only styles the Identity Center (and the Security
     * Center inside it): other My Account pages (dashboard, orders, addresses)
     * do not need it in <head>. A Security Center rendered elsewhere still
     * enqueues it itself when it is printed.
     */
    public static function trim_account_assets() {
        if (!is_user_logged_in() || !function_exists('is_account_page') || !is_account_page()) return;
        if (self::is_endpoint_request() || self::page_has_shortcode()) return;
        global $post;
        if ($post instanceof WP_Post && has_shortcode((string) $post->post_content, 'delicat_security_center')) return;
        wp_dequeue_style('dip-customer-dashboard');
    }

    private static function tab() {
        $allowed = ['overview','accounts','security','app','orders','profile'];
        if (class_exists('DIP_Access_Guard') && DIP_Access_Guard::can_manage_identity()) $allowed[] = 'admin';
        $tab = isset($_GET['dip_tab']) && is_string($_GET['dip_tab']) ? sanitize_key(wp_unslash($_GET['dip_tab'])) : 'overview';
        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    private static function base_url() {
        if (function_exists('wc_get_account_endpoint_url') && function_exists('is_account_page') && is_account_page()) return wc_get_account_endpoint_url(self::ENDPOINT);
        // Current page without the one-shot status messages of earlier actions.
        return remove_query_arg(['dip_tab', 'dip_account_status', 'dip_password_email', 'dip_security_status', 'dip_reauth_status', 'dip_reauth_return']);
    }

    private static function tab_url($tab) { return esc_url(add_query_arg('dip_tab', $tab, self::base_url())); }

    private static function money($amount) {
        return function_exists('wc_price') ? wp_kses_post(wc_price($amount)) : esc_html(number_format_i18n((float) $amount, 2));
    }

    /** One device query for the overview (count and approved ones). */
    private static function device_counts($uid) {
        if (!class_exists('DIP_Devices')) return [0, 0];
        try { $rows = (array) DIP_Devices::user_devices($uid); } catch (Throwable $e) { return [0, 0]; }
        $trusted = 0;
        foreach ($rows as $row) if (!empty($row['trusted'])) $trusted++;
        return [count($rows), $trusted];
    }

    /** Result of the password-setup e-mail sent from the legacy accounts screen. */
    private static function notice($tab) {
        if ($tab !== 'accounts' || !isset($_GET['dip_password_email']) || !is_string($_GET['dip_password_email'])) return '';
        $status = sanitize_key(wp_unslash($_GET['dip_password_email']));
        if ($status === 'sent') {
            return '<p class="dip-id-notice is-success" role="status">' . esc_html__('Un lien pour créer votre mot de passe vient d’être envoyé à votre adresse e-mail.', 'delicat-google-login') . '</p>';
        }
        if ($status === 'failed') {
            return '<p class="dip-id-notice is-warning" role="alert">' . esc_html__('L’e-mail n’a pas pu être envoyé. Réessayez plus tard ou utilisez « Mot de passe oublié » sur la page de connexion.', 'delicat-google-login') . '</p>';
        }
        return '';
    }

    public static function shortcode($atts) {
        if (!is_user_logged_in()) {
            $login = wp_login_url(get_permalink());
            return '<div class="dip-id-empty"><h3>' . esc_html__('Connexion requise', 'delicat-google-login') . '</h3><p>' . esc_html__('Connectez-vous pour gérer votre identité, vos appareils et votre sécurité.', 'delicat-google-login') . '</p><a class="dip-id-primary" href="' . esc_url($login) . '">' . esc_html__('Se connecter', 'delicat-google-login') . '</a></div>';
        }
        self::enqueue_style();
        DIP_My_Account_Modern::private_page();
        $atts = shortcode_atts(['integrated'=>'no'], (array) $atts, self::SHORTCODE);
        $integrated = ($atts['integrated'] === 'yes') && function_exists('is_account_page') && is_account_page();
        $uid = get_current_user_id(); $user = wp_get_current_user(); $tab = self::tab();
        $can_admin = class_exists('DIP_Access_Guard') && DIP_Access_Guard::can_manage_identity();
        // Only what the open tab (and the stand-alone hero) displays is computed.
        $security = (!$integrated || $tab === 'overview') ? DIP_My_Account_Modern::security_context($uid) : null;
        $nav_tabs = [
            'overview' => __('Vue d’ensemble', 'delicat-google-login'),
            'accounts' => __('Comptes liés', 'delicat-google-login'),
            'security' => __('Sécurité', 'delicat-google-login'),
            'app' => __('Application', 'delicat-google-login'),
        ];
        if (!$integrated) {
            $nav_tabs['orders'] = __('Commandes', 'delicat-google-login');
            $nav_tabs['profile'] = __('Profil', 'delicat-google-login');
        }
        if ($can_admin) $nav_tabs['admin'] = __('Administration', 'delicat-google-login');
        ob_start();
        ?>
        <section class="dip-id-dashboard<?php echo $integrated ? ' dip-id-dashboard--integrated' : ''; ?>" aria-label="<?php echo esc_attr__('Centre d’identité Delicat', 'delicat-google-login'); ?>">
          <?php if (!$integrated) :
              $name = $user->display_name ?: $user->user_login;
              $avatar = DIP_My_Account_Modern::avatar_src($uid, 144); ?>
          <header class="dip-id-hero">
            <div class="dip-id-user">
              <span class="dip-id-avatar" aria-hidden="true"><span><?php echo esc_html(DIP_My_Account_Modern::initials($name)); ?></span><?php if ($avatar !== '') : ?><img src="<?php echo esc_url($avatar); ?>" alt="" width="72" height="72" decoding="async" referrerpolicy="no-referrer"><?php endif; ?></span>
              <div><span class="dip-id-kicker"><?php esc_html_e('Centre d’identité', 'delicat-google-login'); ?></span><h2><?php echo esc_html($name); ?></h2><p><?php echo esc_html($user->user_email); ?></p></div>
            </div>
            <div class="dip-id-score is-<?php echo esc_attr($security['tone']); ?>" role="img" aria-label="<?php echo esc_attr(sprintf(__('Score de sécurité %d sur 100', 'delicat-google-login'), $security['score'])); ?>"><strong><?php echo esc_html($security['score']); ?></strong><span>/100<br><?php echo esc_html($security['level']); ?></span></div>
          </header>
          <?php endif; ?>
          <nav class="dip-id-tabs" aria-label="<?php esc_attr_e('Navigation du centre d’identité', 'delicat-google-login'); ?>">
          <?php foreach ($nav_tabs as $key => $label): ?>
            <a href="<?php echo self::tab_url($key); ?>"<?php echo $tab === $key ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
          <?php endforeach; ?>
          </nav>
          <div class="dip-id-content">
          <?php echo self::notice($tab); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in notice(). ?>
          <?php if ($tab === 'overview'):
                $commerce = DIP_My_Account_Modern::commerce_context($uid);
                list($devices, $trusted) = self::device_counts($uid); ?>
            <div class="dip-id-grid dip-id-stats">
              <article><span><?php esc_html_e('Comptes liés', 'delicat-google-login'); ?></span><strong><?php echo esc_html(number_format_i18n($security['providers'])); ?></strong><a href="<?php echo self::tab_url('accounts'); ?>"><?php esc_html_e('Gérer', 'delicat-google-login'); ?></a></article>
              <article><span><?php esc_html_e('Appareils', 'delicat-google-login'); ?></span><strong><?php echo esc_html(number_format_i18n($devices)); ?></strong><small><?php echo esc_html(sprintf(_n('%d approuvé', '%d approuvés', $trusted, 'delicat-google-login'), $trusted)); ?></small></article>
              <article><span><?php esc_html_e('Commandes', 'delicat-google-login'); ?></span><strong><?php echo esc_html(number_format_i18n($commerce['orders'])); ?></strong><small><?php echo self::money($commerce['spent']); ?></small></article>
              <?php if ($commerce['wallet']) : ?><article><span><?php esc_html_e('Portefeuille', 'delicat-google-login'); ?></span><strong><?php echo $commerce['balance'] === null ? '—' : self::money($commerce['balance']); ?></strong><small><?php echo $commerce['balance'] === null ? esc_html__('Non disponible', 'delicat-google-login') : esc_html__('Solde actuel', 'delicat-google-login'); ?></small></article><?php endif; ?>
            </div>
            <div class="dip-id-grid dip-id-actions">
              <a href="<?php echo self::tab_url('security'); ?>"><strong><?php echo esc_html($security['passkey'] ? __('Renforcer ma sécurité', 'delicat-google-login') : __('Ajouter une Passkey', 'delicat-google-login')); ?></strong><span><?php echo esc_html($security['passkey'] ? __('Sessions, appareils et historique', 'delicat-google-login') : __('Face ID, empreinte ou Windows Hello : connexion sans mot de passe', 'delicat-google-login')); ?></span></a>
              <a href="<?php echo self::tab_url('accounts'); ?>"><strong><?php esc_html_e('Gérer mes connexions', 'delicat-google-login'); ?></strong><span><?php esc_html_e('Google, Microsoft et futurs fournisseurs', 'delicat-google-login'); ?></span></a>
              <a href="<?php echo self::tab_url('app'); ?>"><strong><?php esc_html_e('Connecter l’application', 'delicat-google-login'); ?></strong><span><?php esc_html_e('Association sécurisée web et mobile', 'delicat-google-login'); ?></span></a>
            </div>
          <?php elseif ($tab === 'accounts'): echo do_shortcode('[delicat_connected_accounts]');
                elseif ($tab === 'security'): echo do_shortcode('[delicat_security_center]');
                elseif ($tab === 'app'): echo do_shortcode('[delicat_app_pairing]');
                elseif ($tab === 'orders'): ?>
                  <div class="dip-id-panel"><h3><?php esc_html_e('Vos commandes', 'delicat-google-login'); ?></h3><p><?php esc_html_e('Consultez vos achats, leur état et les détails de livraison depuis votre espace WooCommerce.', 'delicat-google-login'); ?></p><?php if (function_exists('wc_get_account_endpoint_url')): ?><a class="dip-id-primary" href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>"><?php esc_html_e('Voir mes commandes', 'delicat-google-login'); ?></a><?php endif; ?></div>
          <?php elseif ($tab === 'admin' && $can_admin):
                $audit_summary = class_exists('DIP_Audit') ? DIP_Audit::summary(30) : ['total'=>0,'success'=>0,'blocked'=>0];
                $device_summary = class_exists('DIP_Devices') ? DIP_Devices::summary(30) : ['total'=>0,'trusted'=>0];
                $architecture = class_exists('DIP_Security_Architecture') ? DIP_Security_Architecture::health() : ['score'=>0,'checks'=>[]]; ?>
                  <div class="dip-id-panel">
                    <h3><?php esc_html_e('Administration Identity', 'delicat-google-login'); ?></h3>
                    <p><?php esc_html_e('Cette zone est visible uniquement par les administrateurs WordPress. Les clients ne peuvent ni l’afficher ni appeler ses actions directement.', 'delicat-google-login'); ?></p>
                    <div class="dip-id-grid dip-id-stats">
                      <article><span><?php esc_html_e('Architecture sécurité', 'delicat-google-login'); ?></span><strong><?php echo esc_html((int)($architecture['score'] ?? 0)); ?>%</strong><small><?php esc_html_e('Contrôles centralisés', 'delicat-google-login'); ?></small></article>
                      <article><span><?php esc_html_e('Événements sécurité · 30 j', 'delicat-google-login'); ?></span><strong><?php echo esc_html((int)($audit_summary['total'] ?? 0)); ?></strong><small><?php echo esc_html(sprintf(__('%d connexion(s) réussie(s)', 'delicat-google-login'), (int)($audit_summary['success'] ?? 0))); ?></small></article>
                      <article><span><?php esc_html_e('Connexions bloquées', 'delicat-google-login'); ?></span><strong><?php echo esc_html((int)($audit_summary['blocked'] ?? 0)); ?></strong><small><?php esc_html_e('Global', 'delicat-google-login'); ?></small></article>
                      <article><span><?php esc_html_e('Appareils actifs', 'delicat-google-login'); ?></span><strong><?php echo esc_html((int)($device_summary['total'] ?? 0)); ?></strong><small><?php echo esc_html(sprintf(__('%d approuvé(s)', 'delicat-google-login'), (int)($device_summary['trusted'] ?? 0))); ?></small></article>
                    </div>
                    <?php if (!empty($architecture['checks'])): ?>
                    <div class="dip-id-panel dip-id-panel-spaced"><h4><?php esc_html_e('Contrôles d’accès actifs', 'delicat-google-login'); ?></h4>
                      <?php foreach ((array)$architecture['checks'] as $check): ?><p><strong><?php echo !empty($check['ok']) ? '✓' : '⚠'; ?> <?php echo esc_html($check['label'] ?? ''); ?></strong></p><?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="dip-id-grid dip-id-actions">
                      <a href="<?php echo esc_url(admin_url('options-general.php?page=dip-security-center')); ?>"><strong><?php esc_html_e('Centre de sécurité global', 'delicat-google-login'); ?></strong><span><?php esc_html_e('Audit, appareils et événements globaux', 'delicat-google-login'); ?></span></a>
                      <a href="<?php echo esc_url(admin_url('options-general.php?page=delicat-identity')); ?>"><strong><?php esc_html_e('Réglages Identity', 'delicat-google-login'); ?></strong><span><?php esc_html_e('OAuth, redirections et politiques de sécurité', 'delicat-google-login'); ?></span></a>
                      <a href="<?php echo esc_url(admin_url('options-general.php?page=dip-app-sync-v2')); ?>"><strong><?php esc_html_e('App Sync', 'delicat-google-login'); ?></strong><span><?php esc_html_e('API mobile et appairage', 'delicat-google-login'); ?></span></a>
                    </div>
                  </div>
          <?php else: ?>
                  <div class="dip-id-panel"><h3><?php esc_html_e('Profil du compte', 'delicat-google-login'); ?></h3><p><?php esc_html_e('Modifiez votre nom, votre adresse e-mail et votre mot de passe depuis les détails du compte.', 'delicat-google-login'); ?></p><?php if (function_exists('wc_get_account_endpoint_url')): ?><a class="dip-id-primary" href="<?php echo esc_url(wc_get_account_endpoint_url('edit-account')); ?>"><?php esc_html_e('Modifier mon profil', 'delicat-google-login'); ?></a><?php else: ?><a class="dip-id-primary" href="<?php echo esc_url(get_edit_profile_url($uid)); ?>"><?php esc_html_e('Modifier mon profil', 'delicat-google-login'); ?></a><?php endif; ?></div>
          <?php endif; ?>
          </div>
        </section>
        <?php return ob_get_clean();
    }
}
