<?php
defined('ABSPATH') || exit;

/**
 * Phase 5 WooCommerce experience layer.
 * Keeps the legacy integration intact and adds configurable, reversible behavior.
 */
final class DIP_WooCommerce_Pro {
    const OPTION = 'dip_wc_pro_settings';
    const NONCE  = 'dip_wc_pro_save';

    public static function defaults() {
        return [
            'checkout_panel'       => 1,
            'preserve_return_url'  => 1,
            'redirect_customer'    => '',
            'redirect_shop_manager'=> '',
            'redirect_admin'       => '',
            'protected_products'   => '',
            'protected_categories' => '',
        ];
    }

    public static function settings() {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }

    public static function init() {
        if (!class_exists('WooCommerce')) return;

        add_action('admin_menu', [__CLASS__, 'admin_menu'], 25);
        add_action('admin_post_dip_wc_pro_save', [__CLASS__, 'save']);

        add_action('woocommerce_before_checkout_form', [__CLASS__, 'checkout_panel'], 6);

        add_filter('woocommerce_login_redirect', [__CLASS__, 'role_redirect'], 30, 2);
        add_filter('login_redirect', [__CLASS__, 'wp_role_redirect'], 30, 3);
        add_action('template_redirect', [__CLASS__, 'remember_return_url'], 1);
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_protected_product'], 10, 5);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 30);
    }

    public static function admin_menu() {
        add_options_page(
            __('Identity WooCommerce', 'delicat-google-login'),
            __('Identity WooCommerce', 'delicat-google-login'),
            'manage_options',
            'dip-woocommerce-pro',
            [__CLASS__, 'page']
        );
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;
        $s = self::settings();
        ?>
        <div class="wrap dip-wc-pro-admin">
            <h1><?php esc_html_e('Delicat Identity · WooCommerce', 'delicat-google-login'); ?></h1>
            <p><?php esc_html_e('Configurez l’expérience de connexion, les redirections et la protection des produits.', 'delicat-google-login'); ?></p>
            <?php if (!empty($_GET['updated'])): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Réglages enregistrés.', 'delicat-google-login'); ?></p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="dip_wc_pro_save">
                <?php wp_nonce_field(self::NONCE); ?>
                <table class="form-table" role="presentation">
                    <tr><th><?php esc_html_e('Expérience client', 'delicat-google-login'); ?></th><td>
                        <label><input type="checkbox" name="checkout_panel" value="1" <?php checked(!empty($s['checkout_panel'])); ?>> <?php esc_html_e('Afficher le panneau de connexion moderne au paiement', 'delicat-google-login'); ?></label><br>
                        <span><strong><?php esc_html_e('Mon compte unifié actif', 'delicat-google-login'); ?></strong> — <?php esc_html_e('le tableau de bord moderne, le portefeuille et la sécurité Identity sont maintenant intégrés dans une seule interface.', 'delicat-google-login'); ?></span><br>
                        <label><input type="checkbox" name="preserve_return_url" value="1" <?php checked(!empty($s['preserve_return_url'])); ?>> <?php esc_html_e('Renvoyer le client vers sa page initiale après connexion', 'delicat-google-login'); ?></label>
                    </td></tr>
                    <tr><th><label for="redirect_customer"><?php esc_html_e('Redirection client', 'delicat-google-login'); ?></label></th><td><input class="regular-text" id="redirect_customer" name="redirect_customer" value="<?php echo esc_attr($s['redirect_customer']); ?>" placeholder="/my-account/"><p class="description"><?php esc_html_e('URL relative ou complète. Vide = comportement WooCommerce.', 'delicat-google-login'); ?></p></td></tr>
                    <tr><th><label for="redirect_shop_manager"><?php esc_html_e('Redirection gestionnaire boutique', 'delicat-google-login'); ?></label></th><td><input class="regular-text" id="redirect_shop_manager" name="redirect_shop_manager" value="<?php echo esc_attr($s['redirect_shop_manager']); ?>"></td></tr>
                    <tr><th><label for="redirect_admin"><?php esc_html_e('Redirection administrateur', 'delicat-google-login'); ?></label></th><td><input class="regular-text" id="redirect_admin" name="redirect_admin" value="<?php echo esc_attr($s['redirect_admin']); ?>"></td></tr>
                    <tr><th><label for="protected_products"><?php esc_html_e('Produits exigeant une connexion', 'delicat-google-login'); ?></label></th><td><input class="large-text" id="protected_products" name="protected_products" value="<?php echo esc_attr($s['protected_products']); ?>" placeholder="123, 456"><p class="description"><?php esc_html_e('IDs de produits séparés par des virgules.', 'delicat-google-login'); ?></p></td></tr>
                    <tr><th><label for="protected_categories"><?php esc_html_e('Catégories exigeant une connexion', 'delicat-google-login'); ?></label></th><td><input class="large-text" id="protected_categories" name="protected_categories" value="<?php echo esc_attr($s['protected_categories']); ?>" placeholder="jeux, abonnements"><p class="description"><?php esc_html_e('Slugs de catégories séparés par des virgules.', 'delicat-google-login'); ?></p></td></tr>
                </table>
                <?php submit_button(__('Enregistrer les réglages', 'delicat-google-login')); ?>
            </form>
        </div>
        <?php
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Accès refusé.', 'delicat-google-login'), '', ['response' => 403]);
        check_admin_referer(self::NONCE);
        $clean_url = static function($value) {
            $value = trim((string) wp_unslash($value));
            if ($value === '') return '';
            return esc_url_raw($value);
        };
        $settings = [
            'checkout_panel'        => empty($_POST['checkout_panel']) ? 0 : 1,
            'preserve_return_url'   => empty($_POST['preserve_return_url']) ? 0 : 1,
            'redirect_customer'     => $clean_url($_POST['redirect_customer'] ?? ''),
            'redirect_shop_manager' => $clean_url($_POST['redirect_shop_manager'] ?? ''),
            'redirect_admin'        => $clean_url($_POST['redirect_admin'] ?? ''),
            'protected_products'    => self::clean_csv($_POST['protected_products'] ?? '', true),
            'protected_categories'  => self::clean_csv($_POST['protected_categories'] ?? '', false),
        ];
        update_option(self::OPTION, $settings, false);
        if (class_exists('DIP_Audit')) DIP_Audit::record('woocommerce_identity_settings_updated', 'notice', get_current_user_id());
        wp_safe_redirect(add_query_arg(['page' => 'dip-woocommerce-pro', 'updated' => 1], admin_url('options-general.php')));
        exit;
    }

    private static function clean_csv($value, $numeric) {
        $parts = array_filter(array_map('trim', explode(',', (string) wp_unslash($value))));
        if ($numeric) $parts = array_filter(array_map('absint', $parts));
        else $parts = array_filter(array_map('sanitize_title', $parts));
        return implode(',', array_unique($parts));
    }

    public static function checkout_panel() {
        if (is_user_logged_in() || empty(self::settings()['checkout_panel'])) return;
        echo '<section class="dip-wc-login-panel" aria-labelledby="dip-wc-login-title">';
        echo '<div class="dip-wc-login-copy"><span class="dip-wc-eyebrow">' . esc_html__('DELICAT IDENTITY', 'delicat-google-login') . '</span>';
        echo '<h2 id="dip-wc-login-title">' . esc_html__('Retrouvez votre panier et payez plus rapidement', 'delicat-google-login') . '</h2>';
        echo '<p>' . esc_html__('Connectez-vous sans perdre les produits, variations, coupons ou informations déjà saisis.', 'delicat-google-login') . '</p></div>';
        echo '<div class="dip-wc-login-actions">';
        echo DIP_Plugin::instance()->render_button(['provider' => 'google', 'redirect' => wc_get_checkout_url()]);
        echo DIP_Plugin::instance()->render_button(['provider' => 'microsoft', 'redirect' => wc_get_checkout_url()]);
        echo '<a class="button dip-wc-native-login" href="' . esc_url(wc_get_page_permalink('myaccount')) . '">' . esc_html__('Email ou mot de passe', 'delicat-google-login') . '</a>';
        echo '</div></section>';
    }

    public static function remember_return_url() {
        if (is_user_logged_in() || empty(self::settings()['preserve_return_url']) || is_admin() || wp_doing_ajax()) return;
        if (!function_exists('is_account_page')) return;
        $request = home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        if (is_account_page()) return;
        if (!wp_http_validate_url($request)) return;
        setcookie('dip_return_url', $request, [
            'expires'  => time() + 15 * MINUTE_IN_SECONDS,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function role_redirect($redirect, $user) {
        return self::resolve_redirect($redirect, $user);
    }

    public static function wp_role_redirect($redirect_to, $requested, $user) {
        if (is_wp_error($user) || !($user instanceof WP_User)) return $redirect_to;
        return self::resolve_redirect($redirect_to, $user);
    }

    private static function resolve_redirect($fallback, $user) {
        $s = self::settings();
        if (!empty($s['preserve_return_url']) && !empty($_COOKIE['dip_return_url'])) {
            $return = wp_validate_redirect(esc_url_raw(wp_unslash($_COOKIE['dip_return_url'])), '');
            if (!headers_sent()) {
                setcookie('dip_return_url', '', [
                    'expires'  => time() - HOUR_IN_SECONDS,
                    'path'     => COOKIEPATH ?: '/',
                    'domain'   => COOKIE_DOMAIN ?: '',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            unset($_COOKIE['dip_return_url']);
            if ($return) return $return;
        }
        $roles = (array) $user->roles;
        $target = '';
        if (in_array('administrator', $roles, true)) $target = $s['redirect_admin'];
        elseif (in_array('shop_manager', $roles, true)) $target = $s['redirect_shop_manager'];
        elseif (array_intersect(['customer', 'subscriber'], $roles)) $target = $s['redirect_customer'];
        if (!$target) return $fallback;
        if (strpos($target, '/') === 0) $target = home_url($target);
        return wp_validate_redirect($target, $fallback);
    }

    public static function validate_protected_product($passed, $product_id, $quantity, $variation_id = 0, $variations = []) {
        if (!$passed || is_user_logged_in()) return $passed;
        $s = self::settings();
        $ids = array_filter(array_map('absint', explode(',', $s['protected_products'])));
        $categories = array_filter(array_map('sanitize_title', explode(',', $s['protected_categories'])));
        $match = in_array((int) $product_id, $ids, true);
        if (!$match && $categories) $match = has_term($categories, 'product_cat', $product_id);
        if (!$match) return $passed;
        wc_add_notice(__('Connectez-vous pour acheter ce produit. Votre panier actuel sera conservé.', 'delicat-google-login'), 'notice');
        if (WC()->session) WC()->session->set('dip_protected_return', get_permalink($product_id));
        return false;
    }

    public static function assets() {
        if ((function_exists('is_checkout') && is_checkout()) || (function_exists('is_account_page') && is_account_page())) {
            wp_enqueue_style('dip-wc-pro', DIP_URL . 'assets/woocommerce-pro.css', [], DIP_VERSION);
        }
    }
}
