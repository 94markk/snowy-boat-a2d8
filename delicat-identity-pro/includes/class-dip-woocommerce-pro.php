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

    /** @var array|null settings of this request */
    private static $settings = null;

    public static function settings() {
        if (self::$settings === null) self::$settings = wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
        return self::$settings;
    }

    public static function init() {
        if (!class_exists('WooCommerce')) return;

        add_action('admin_menu', [__CLASS__, 'admin_menu'], 25);
        add_action('admin_post_dip_wc_pro_save', [__CLASS__, 'save']);

        add_action('woocommerce_before_checkout_form', [__CLASS__, 'checkout_panel'], 6);
        // The Checkout block (default checkout since WooCommerce 8.3) never fires the classic hook.
        add_filter('render_block_woocommerce/checkout', [__CLASS__, 'checkout_block_panel'], 10, 2);

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
        update_option(self::OPTION, $settings, true);
        self::$settings = null;
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

    /** Guest login panel of the checkout ('' when it must not show). */
    private static function panel_html() {
        if (is_user_logged_in() || empty(self::settings()['checkout_panel']) || !function_exists('wc_get_checkout_url')) return '';
        $checkout = wc_get_checkout_url();
        // The account page sends the customer back to the checkout after an e-mail/password sign-in.
        $native = add_query_arg('redirect_to', rawurlencode($checkout), wc_get_page_permalink('myaccount'));
        $html = '<section class="dip-wc-login-panel" aria-labelledby="dip-wc-login-title">';
        $html .= '<div class="dip-wc-login-copy"><span class="dip-wc-eyebrow">' . esc_html__('DELICAT IDENTITY', 'delicat-google-login') . '</span>';
        $html .= '<h2 id="dip-wc-login-title">' . esc_html__('Retrouvez votre panier et payez plus rapidement', 'delicat-google-login') . '</h2>';
        $html .= '<p>' . esc_html__('Connectez-vous sans perdre les produits, variations, coupons ou informations déjà saisis.', 'delicat-google-login') . '</p></div>';
        $html .= '<div class="dip-wc-login-actions">';
        $html .= DIP_Plugin::instance()->render_button(['provider' => 'google', 'redirect' => $checkout]);
        $html .= DIP_Plugin::instance()->render_button(['provider' => 'microsoft', 'redirect' => $checkout]);
        $html .= '<a class="button dip-wc-native-login" href="' . esc_url($native) . '">' . esc_html__('Email ou mot de passe', 'delicat-google-login') . '</a>';
        $html .= '</div></section>';
        return $html;
    }

    public static function checkout_panel() {
        echo self::panel_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    public static function checkout_block_panel($content, $block = []) {
        static $done = false;
        if ($done || is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) return $content;
        $panel = self::panel_html();
        if ($panel === '') return $content;
        $done = true;
        return $panel . $content;
    }

    /**
     * Remember where a guest came from when they open the account (login)
     * page, so the sign-in sends them back there. Only the account page writes
     * the cookie: storefront pages stay free of Set-Cookie (page/edge caches),
     * and sub-resources, 404s, feeds or prefetches never become the target.
     */
    public static function remember_return_url() {
        if (is_user_logged_in() || is_admin() || wp_doing_ajax() || headers_sent()) return;
        if (!function_exists('is_account_page') || !is_account_page()) return;
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET' || self::is_speculative_request()) return;
        if (empty(self::settings()['preserve_return_url'])) return;
        // An explicit destination (protected page, checkout link) already decides.
        if (self::explicit_destination() !== '') return;
        $return = self::clean_return_url((string) wp_get_raw_referer(), true);
        if ($return === '') return;
        $current = isset($_COOKIE['dip_return_url']) && is_string($_COOKIE['dip_return_url']) ? (string) wp_unslash($_COOKIE['dip_return_url']) : '';
        if ($current === $return) return;
        setcookie('dip_return_url', $return, [
            'expires'  => time() + 15 * MINUTE_IN_SECONDS,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['dip_return_url'] = $return;
    }

    private static function is_speculative_request() {
        foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ'] as $header) {
            $value = strtolower((string) ($_SERVER[$header] ?? ''));
            if ($value !== '' && (strpos($value, 'prefetch') !== false || strpos($value, 'prerender') !== false)) return true;
        }
        $dest = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
        return $dest !== '' && $dest !== 'document';
    }

    private static function path_of($url) {
        return strtolower(trailingslashit(rawurldecode((string) wp_parse_url((string) $url, PHP_URL_PATH))));
    }

    /**
     * A same-site page a customer can be sent back to, without one-shot
     * actions (add to cart, coupons, nonces, sign-in markers). '' otherwise.
     */
    private static function clean_return_url($url, $from_referer = false) {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2000) return '';
        if ($url[0] === '/' && substr($url, 0, 2) !== '//') $url = home_url($url);
        $url = wp_validate_redirect(esc_url_raw($url), '');
        if ($url === '') return '';
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!is_array($parts) || !is_array($home) || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($home['host'] ?? ''))) return '';
        $path = self::path_of($url);
        if (preg_match('~/(?:wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php|feed|embed|trackback)/~', $path)) return '';
        if (preg_match('~\.(?:png|jpe?g|gif|webp|avif|svg|ico|css|js|map|txt|xml|json|woff2?|ttf|eot|pdf|zip)/$~', $path)) return '';
        $query = [];
        if (!empty($parts['query'])) parse_str((string) $parts['query'], $query);
        if (isset($query['wc-ajax']) || isset($query['rest_route']) || isset($query['feed']) || isset($query['embed'])) return '';
        $strip = [];
        $one_shot = ['add-to-cart', 'quantity', 'variation_id', 'remove_item', 'undo_item', 'removed_item', 'order_again', 'apply_coupon', 'remove_coupon', 'cancel_order', 'add-to-wishlist', 'remove_from_wishlist', '_wpnonce', '_wp_http_referer', 'action', 'key', 'login', 'redirect', 'redirect_to', 'reauth', 'interim-login', 'loginSocial', 'customer-logout'];
        foreach (array_keys($query) as $key) {
            $key = (string) $key;
            if (in_array($key, $one_shot, true) || strpos($key, 'dip_') === 0) $strip[] = $key;
        }
        if ($strip) $url = remove_query_arg($strip, $url);
        if ($from_referer && function_exists('wc_get_page_permalink')) {
            // The account page itself (and its endpoints) is not a destination.
            $account = self::path_of(wc_get_page_permalink('myaccount'));
            if ($account !== '/' ? strpos($path, $account) === 0 : $path === '/') return '';
        }
        return strlen($url) <= 2000 ? $url : '';
    }

    /**
     * Destination the sign-in request names itself (?redirect_to=, a form's
     * « redirect »), validated. wp-login.php's default (the dashboard) and
     * WooCommerce's fallback to the account page are not choices.
     */
    private static function explicit_destination($requested = null) {
        $candidates = [];
        if (is_string($requested)) $candidates[] = $requested;
        foreach (['redirect', 'redirect_to'] as $key) {
            if (isset($_REQUEST[$key]) && is_string($_REQUEST[$key])) $candidates[] = (string) wp_unslash($_REQUEST[$key]);
        }
        $account = function_exists('wc_get_page_permalink') ? self::path_of(wc_get_page_permalink('myaccount')) : '';
        foreach ($candidates as $raw) {
            $raw = trim($raw);
            if ($raw === '' || strlen($raw) > 2048) continue;
            if ($raw[0] === '/' && substr($raw, 0, 2) !== '//') $raw = home_url($raw);
            $url = wp_validate_redirect(esc_url_raw($raw), '');
            if ($url === '') continue;
            $path = self::path_of($url);
            if ($path === self::path_of(admin_url()) && (string) wp_parse_url($url, PHP_URL_QUERY) === '') continue;
            if ($account !== '' && $path === $account && (string) wp_parse_url($url, PHP_URL_QUERY) === '') continue;
            return $url;
        }
        return '';
    }

    /** Reads and always clears the remembered page (one use). */
    private static function take_return_cookie() {
        if (!isset($_COOKIE['dip_return_url'])) return '';
        $raw = $_COOKIE['dip_return_url'];
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
        return is_string($raw) ? self::clean_return_url(wp_unslash($raw)) : '';
    }

    private static function is_staff(WP_User $user) {
        return user_can($user, 'edit_posts') || user_can($user, 'manage_woocommerce') || user_can($user, 'manage_options');
    }

    public static function role_redirect($redirect, $user) {
        if (!($user instanceof WP_User)) return $redirect;
        return self::resolve_redirect($redirect, $user);
    }

    public static function wp_role_redirect($redirect_to, $requested, $user) {
        if (is_wp_error($user) || !($user instanceof WP_User)) return $redirect_to;
        return self::resolve_redirect($redirect_to, $user, is_string($requested) ? $requested : '');
    }

    /**
     * Order: a destination the request names itself, then (customers only)
     * the remembered page, then the role rule, then WordPress/WooCommerce.
     * Staff keep their explicit wp-admin destinations.
     */
    private static function resolve_redirect($fallback, WP_User $user, $requested = null) {
        $s = self::settings();
        $remembered = self::take_return_cookie();
        $explicit = self::explicit_destination($requested);
        if ($explicit !== '') return ($requested !== null && is_string($fallback) && $fallback !== '') ? $fallback : $explicit;
        if (!empty($s['preserve_return_url']) && $remembered !== '' && !self::is_staff($user)) return $remembered;
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

    /** woocommerce-pro.css only styles the guest checkout panel. */
    public static function assets() {
        if (is_user_logged_in() || !function_exists('is_checkout') || !is_checkout() || empty(self::settings()['checkout_panel'])) return;
        if (function_exists('is_order_received_page') && is_order_received_page()) return;
        wp_enqueue_style('dip-wc-pro', DIP_URL . 'assets/woocommerce-pro.css', [], DIP_VERSION);
    }
}
