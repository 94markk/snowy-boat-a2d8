<?php
defined('ABSPATH') || exit;

final class DIP_Customer_Dashboard {
    const ENDPOINT = 'identity-center';

    public static function init() {
        add_action('init', [__CLASS__, 'register_endpoint']);
        add_shortcode('delicat_identity_dashboard', [__CLASS__, 'shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_item'], 35);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'endpoint']);
    }

    public static function activate() {
        self::register_endpoint();
        flush_rewrite_rules(false);
    }

    public static function register_endpoint() {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
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

    public static function assets() {
        if (!is_user_logged_in()) return;
        $load = function_exists('is_account_page') && is_account_page();
        if (!$load && is_singular()) {
            global $post;
            $load = $post && has_shortcode((string) $post->post_content, 'delicat_identity_dashboard');
        }
        if (!$load) return;
        wp_enqueue_style('dip-customer-dashboard', DIP_URL . 'assets/customer-dashboard.css', [], DIP_VERSION);
    }

    private static function tab() {
        $allowed = ['overview','accounts','security','app','orders','profile'];
        if (class_exists('DIP_Access_Guard') && DIP_Access_Guard::can_manage_identity()) $allowed[] = 'admin';
        $tab = isset($_GET['dip_tab']) ? sanitize_key(wp_unslash($_GET['dip_tab'])) : 'overview';
        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    private static function base_url() {
        if (function_exists('wc_get_account_endpoint_url') && is_account_page()) return wc_get_account_endpoint_url(self::ENDPOINT);
        return remove_query_arg('dip_tab');
    }

    private static function tab_url($tab) { return esc_url(add_query_arg('dip_tab', $tab, self::base_url())); }

    private static function connected_count($uid) {
        $count = 0;
        if (get_user_meta($uid, '_dglp_google_sub', true) || get_user_meta($uid, 'dip_google_sub', true)) $count++;
        if (get_user_meta($uid, '_dip_microsoft_sub', true) || get_user_meta($uid, 'dip_microsoft_sub', true)) $count++;
        return $count;
    }

    private static function device_counts($uid) {
        global $wpdb;
        $table = $wpdb->prefix . 'dip_devices';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return [0,0];
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d", $uid));
        $trusted = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND trusted=1", $uid));
        return [$total,$trusted];
    }

    private static function order_stats($uid) {
        $count = function_exists('wc_get_customer_order_count') ? (int) wc_get_customer_order_count($uid) : 0;
        $spent = function_exists('wc_get_customer_total_spent') ? (float) wc_get_customer_total_spent($uid) : 0;
        return [$count, $spent];
    }

    private static function wallet_balance($uid) {
        if (function_exists('woo_wallet')) {
            try { return (float) woo_wallet()->wallet->get_wallet_balance($uid, 'edit'); } catch (Throwable $e) {}
        }
        return null;
    }

    private static function security_score($uid) {
        $score = DIP_Security_Center::score(absint($uid));
        return min(100, max(0, absint($score['score'] ?? 0)));
    }

    public static function shortcode($atts) {
        if (!is_user_logged_in()) {
            $login = wp_login_url(get_permalink());
            return '<div class="dip-id-empty"><h3>' . esc_html__('Connexion requise', 'delicat-google-login') . '</h3><p>' . esc_html__('Connectez-vous pour gérer votre identité, vos appareils et votre sécurité.', 'delicat-google-login') . '</p><a class="dip-id-primary" href="' . esc_url($login) . '">' . esc_html__('Se connecter', 'delicat-google-login') . '</a></div>';
        }
        $atts = shortcode_atts(['integrated'=>'no'], (array) $atts, 'delicat_identity_dashboard');
        $integrated = ($atts['integrated'] === 'yes') && function_exists('is_account_page') && is_account_page();
        $uid = get_current_user_id(); $user = wp_get_current_user(); $tab = self::tab();
        $connected = self::connected_count($uid); list($devices,$trusted) = self::device_counts($uid);
        list($orders,$spent) = self::order_stats($uid); $wallet = self::wallet_balance($uid);
        $score = self::security_score($uid);
        ob_start();
        ?>
        <section class="dip-id-dashboard<?php echo $integrated ? ' dip-id-dashboard--integrated' : ''; ?>" aria-label="<?php echo esc_attr__('Centre d’identité Delicat', 'delicat-google-login'); ?>">
          <?php if (!$integrated) : ?>
          <header class="dip-id-hero">
            <div class="dip-id-user">
              <?php echo get_avatar($uid, 72, '', '', ['class'=>'dip-id-avatar']); ?>
              <div><span class="dip-id-kicker"><?php esc_html_e('Centre d’identité', 'delicat-google-login'); ?></span><h2><?php echo esc_html($user->display_name ?: $user->user_login); ?></h2><p><?php echo esc_html($user->user_email); ?></p></div>
            </div>
            <div class="dip-id-score" aria-label="<?php echo esc_attr(sprintf(__('Score de sécurité %d sur 100', 'delicat-google-login'), $score)); ?>"><strong><?php echo esc_html($score); ?></strong><span>/100<br><?php esc_html_e('Sécurité', 'delicat-google-login'); ?></span></div>
          </header>
          <?php endif; ?>
          <nav class="dip-id-tabs" aria-label="<?php esc_attr_e('Navigation du compte', 'delicat-google-login'); ?>">
          <?php $nav_tabs = $integrated ? ['overview'=>'Vue d’ensemble','accounts'=>'Comptes liés','security'=>'Sécurité','app'=>'Application'] : ['overview'=>'Vue d’ensemble','accounts'=>'Comptes liés','security'=>'Sécurité','app'=>'Application','orders'=>'Commandes','profile'=>'Profil'];
          if (class_exists('DIP_Access_Guard') && DIP_Access_Guard::can_manage_identity()) $nav_tabs['admin'] = 'Administration';
          foreach ($nav_tabs as $key=>$label): ?>
            <a href="<?php echo self::tab_url($key); ?>" class="<?php echo $tab===$key?'is-active':''; ?>" <?php echo $tab===$key?'aria-current="page"':''; ?>><?php echo esc_html__($label, 'delicat-google-login'); ?></a>
          <?php endforeach; ?>
          </nav>
          <div class="dip-id-content">
          <?php if ($tab === 'overview'): ?>
            <div class="dip-id-grid dip-id-stats">
              <article><span><?php esc_html_e('Comptes liés', 'delicat-google-login'); ?></span><strong><?php echo esc_html($connected); ?></strong><a href="<?php echo self::tab_url('accounts'); ?>"><?php esc_html_e('Gérer', 'delicat-google-login'); ?></a></article>
              <article><span><?php esc_html_e('Appareils', 'delicat-google-login'); ?></span><strong><?php echo esc_html($devices); ?></strong><small><?php echo esc_html(sprintf(__('%d approuvé(s)', 'delicat-google-login'), $trusted)); ?></small></article>
              <article><span><?php esc_html_e('Commandes', 'delicat-google-login'); ?></span><strong><?php echo esc_html($orders); ?></strong><small><?php echo function_exists('wc_price') ? wp_kses_post(wc_price($spent)) : esc_html(number_format_i18n($spent,2)); ?></small></article>
              <article><span><?php esc_html_e('Portefeuille', 'delicat-google-login'); ?></span><strong><?php echo $wallet === null ? '—' : (function_exists('wc_price') ? wp_kses_post(wc_price($wallet)) : esc_html(number_format_i18n($wallet,2))); ?></strong><small><?php echo $wallet===null?esc_html__('Non disponible','delicat-google-login'):esc_html__('Solde actuel','delicat-google-login'); ?></small></article>
            </div>
            <div class="dip-id-grid dip-id-actions">
              <a href="<?php echo self::tab_url('security'); ?>"><strong><?php esc_html_e('Renforcer ma sécurité', 'delicat-google-login'); ?></strong><span><?php esc_html_e('Sessions, appareils et historique', 'delicat-google-login'); ?></span></a>
              <a href="<?php echo self::tab_url('accounts'); ?>"><strong><?php esc_html_e('Gérer mes connexions', 'delicat-google-login'); ?></strong><span><?php esc_html_e('Google, Microsoft et futurs fournisseurs', 'delicat-google-login'); ?></span></a>
              <a href="<?php echo self::tab_url('app'); ?>"><strong><?php esc_html_e('Connecter l’application', 'delicat-google-login'); ?></strong><span><?php esc_html_e('Association sécurisée web et mobile', 'delicat-google-login'); ?></span></a>
            </div>
          <?php elseif ($tab === 'accounts'): echo do_shortcode('[delicat_connected_accounts]');
                elseif ($tab === 'security'): echo do_shortcode('[delicat_security_center]');
                elseif ($tab === 'app'): echo do_shortcode('[delicat_app_pairing]');
                elseif ($tab === 'orders'): ?>
                  <div class="dip-id-panel"><h3><?php esc_html_e('Vos commandes', 'delicat-google-login'); ?></h3><p><?php esc_html_e('Consultez vos achats, leur état et les détails de livraison depuis votre espace WooCommerce.', 'delicat-google-login'); ?></p><?php if (function_exists('wc_get_account_endpoint_url')): ?><a class="dip-id-primary" href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>"><?php esc_html_e('Voir mes commandes', 'delicat-google-login'); ?></a><?php endif; ?></div>
          <?php elseif ($tab === 'admin' && class_exists('DIP_Access_Guard') && DIP_Access_Guard::can_manage_identity()):
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
