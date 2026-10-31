<?php
defined('ABSPATH') || exit;

/**
 * Delicat Identity — unified premium WooCommerce My Account presentation.
 *
 * Presentation and customer-safe status only. WordPress/WooCommerce/TeraWallet
 * and Delicat Identity remain the sources of truth for authorization and data.
 */
final class DIP_My_Account_Modern {
    /** Preference cookie of the « Masquer le solde » toggle (value 1/0 only). */
    const BALANCE_COOKIE = 'dipma_balance_hidden';

    private static $initialized = false;
    /** @var array<int,array> per-request security summary, by user id */
    private static $security = [];
    /** @var array<int,array> per-request orders/wallet summary, by user id */
    private static $commerce = [];
    /** @var string|null endpoint of the current My Account request, once the query ran */
    private static $endpoint = null;

    public static function init() {
        if (self::$initialized || !class_exists('WooCommerce')) return;
        self::$initialized = true;

        add_action('wp_loaded', [__CLASS__, 'cleanup_legacy_ui'], PHP_INT_MAX);
        add_filter('body_class', [__CLASS__, 'body_classes'], 10000);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_items'], 10000);
        add_filter('gettext_woocommerce', [__CLASS__, 'translate_account_strings'], 20, 3);
        add_action('woocommerce_before_account_navigation', [__CLASS__, 'profile_header'], 1);
        add_action('woocommerce_account_dashboard', [__CLASS__, 'dashboard'], 5);
        add_action('woocommerce_before_account_orders', [__CLASS__, 'orders_toolbar'], 5);
        // 6.15.2: the « État » column of the orders list shows the same status chip (glyph + words) as the account home.
        add_action('woocommerce_my_account_my_orders_column_order-status', [__CLASS__, 'orders_status_column']);
        add_action('woocommerce_before_edit_account_form', [__CLASS__, 'profile_form_intro'], 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 90);
        add_action('template_redirect', [__CLASS__, 'early_private_account'], 0);
        add_action('template_redirect', [__CLASS__, 'redirect_legacy_identity_endpoint'], 15);
    }

    public static function is_account() {
        return is_user_logged_in() && function_exists('is_account_page') && is_account_page();
    }

    /** Remove known presentation duplicates only; backend wallet/order code stays untouched. */
    public static function cleanup_legacy_ui() {
        foreach ([
            ['woocommerce_before_account_navigation', 'delicat_account_v2_profile_header', 1],
            ['woocommerce_before_account_navigation', 'delicat_account_v2_open_shell', 2],
            ['woocommerce_after_account_navigation', 'delicat_account_v2_close_shell', 999],
            ['woocommerce_before_account_orders', 'delicat_account_v2_orders_toolbar', 5],
            ['wp_head', 'delicat_account_v2_styles', 99999],
            ['wp_footer', 'delicat_account_v2_scripts', 99999],
            ['wp_loaded', 'delicat_account_v22_replace_dashboard', 50],
        ] as $hook) {
            if (function_exists($hook[1])) remove_action($hook[0], $hook[1], $hook[2]);
        }
        if (function_exists('delicat_account_v2_menu_labels')) remove_filter('woocommerce_account_menu_items', 'delicat_account_v2_menu_labels', 10000);
        if (function_exists('delicat_account_v2_body_class')) remove_filter('body_class', 'delicat_account_v2_body_class');

        foreach (['delicat_cs_dashboard', 'delicat_account_v22_dashboard'] as $callback) {
            if (function_exists($callback)) remove_action('woocommerce_account_dashboard', $callback, 5);
        }
    }

    /**
     * The My Account endpoint being shown, detected the way WooCommerce's
     * woocommerce_account_content() picks it: the first query var that has an
     * endpoint handler. Works for WooCommerce, Delicat and third-party
     * endpoints alike. '' means the dashboard.
     */
    public static function current_endpoint() {
        if (self::$endpoint !== null) return self::$endpoint;
        global $wp;
        $endpoint = '';
        if ($wp instanceof WP && is_array($wp->query_vars)) {
            foreach ($wp->query_vars as $key => $value) {
                if (!is_string($key) || $key === 'pagename' || $key === 'page') continue;
                if (has_action('woocommerce_account_' . $key . '_endpoint')) { $endpoint = $key; break; }
            }
        }
        if (did_action('wp')) self::$endpoint = $endpoint;
        return $endpoint;
    }

    public static function body_classes($classes) {
        if (!self::is_account()) return $classes;
        $classes[] = 'dip-account-modern';
        $classes[] = 'dip-account-premium';
        $classes[] = 'dip-account-app';
        $endpoint = self::current_endpoint();
        $classes[] = $endpoint === '' ? 'dip-account-home' : 'dip-account-endpoint-' . sanitize_html_class($endpoint);
        // 'auto' follows the visitor's system preference; a site can pin 'light' or 'dark'.
        $scheme = apply_filters('dip_account_color_scheme', 'auto');
        if ($scheme === 'light' || $scheme === 'dark') $classes[] = 'dipma-scheme-' . $scheme;
        return array_values(array_unique($classes));
    }

    public static function menu_items($items) {
        if (!is_user_logged_in()) return $items;
        unset($items['connected-accounts']);

        $labels = [
            'dashboard'       => __('Accueil', 'delicat-google-login'),
            'orders'          => __('Commandes', 'delicat-google-login'),
            'downloads'       => __('Téléchargements', 'delicat-google-login'),
            'edit-address'    => __('Adresses', 'delicat-google-login'),
            'woo-wallet'      => __('Portefeuille', 'delicat-google-login'),
            'change-password' => __('Mot de passe', 'delicat-google-login'),
            'edit-account'    => __('Profil', 'delicat-google-login'),
            'identity-center' => __('Sécurité', 'delicat-google-login'),
            'customer-logout' => __('Déconnexion', 'delicat-google-login'),
        ];
        foreach ($labels as $key => $label) if (isset($items[$key])) $items[$key] = $label;

        $preferred = ['dashboard','orders','downloads','edit-address','woo-wallet','edit-account','identity-center'];
        $out = [];
        foreach ($preferred as $key) if (isset($items[$key])) $out[$key] = $items[$key];
        foreach ($items as $key => $label) {
            if ($key === 'customer-logout' || isset($out[$key])) continue;
            $out[$key] = $label;
        }
        if (isset($items['customer-logout'])) $out['customer-logout'] = $items['customer-logout'];
        return $out;
    }

    /**
     * French labels of the WooCommerce « Détails du compte » form. Runs for
     * every WooCommerce string, so it does nothing before the main query (no
     * conditional tags yet) and decides once per request.
     */
    public static function translate_account_strings($translated, $text, $domain) {
        static $on_edit = null;
        if ($on_edit === null) {
            if (!did_action('wp') || is_admin()) return $translated;
            $on_edit = self::is_account() && function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('edit-account');
        }
        if (!$on_edit) return $translated;
        static $map = [
            'First name' => 'Prénom',
            'Last name' => 'Nom',
            'Display name' => 'Nom affiché',
            'This will be how your name will be displayed in the account section and in reviews' => 'C’est ainsi que votre nom apparaîtra dans votre compte et dans les avis.',
            'Email address' => 'Adresse e-mail',
            'Password change' => 'Modifier le mot de passe',
            'Current password (leave blank to leave unchanged)' => 'Mot de passe actuel (laissez vide pour ne pas le modifier)',
            'New password (leave blank to leave unchanged)' => 'Nouveau mot de passe (laissez vide pour ne pas le modifier)',
            'Confirm new password' => 'Confirmer le nouveau mot de passe',
            'Save changes' => 'Enregistrer les modifications',
        ];
        return isset($map[$text]) ? $map[$text] : $translated;
    }

    public static function assets() {
        if (!self::is_account()) return;
        // Early non-deferred theme sync: correct on first paint, including iOS dark OS + site light.
        wp_enqueue_script('dip-account-theme', DIP_URL . 'assets/account-theme.js', [], DIP_VERSION, false);
        wp_enqueue_style('dip-my-account-modern', DIP_URL . 'assets/my-account-modern.css', [], DIP_VERSION);
        wp_enqueue_style('dip-account-app', DIP_URL . 'assets/account-app.css', ['dip-my-account-modern'], DIP_VERSION);
        wp_enqueue_script('dip-my-account-modern', DIP_URL . 'assets/my-account-modern.js', [], DIP_VERSION, true);
        if (function_exists('wp_script_add_data')) wp_script_add_data('dip-my-account-modern', 'strategy', 'defer');
    }

    /**
     * Customer data is about to be printed: make sure no page cache keeps it,
     * even when the page was not detected up front (block templates, widgets,
     * other builders). Account pages already answer no-store.
     */
    /** Never send a private account response to a page cache, before output begins. */
    public static function early_private_account() {
        if (self::is_account()) self::private_page();
    }

    public static function private_page() {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        do_action('litespeed_control_set_nocache', 'delicat identity: customer data');
        if (!headers_sent()) nocache_headers();
    }

    private static function safe_name(WP_User $user) {
        $name = trim((string) $user->first_name . ' ' . (string) $user->last_name);
        if ($name === '') $name = $user->display_name ?: $user->user_login;
        return sanitize_text_field($name);
    }

    public static function initials($name) {
        $parts = preg_split('/\s+/u', trim((string) $name));
        $letters = '';
        if (!empty($parts[0])) $letters .= function_exists('mb_substr') ? mb_substr($parts[0], 0, 1, 'UTF-8') : substr($parts[0], 0, 1);
        if (count($parts) > 1) {
            $last = end($parts);
            if ($last !== false) $letters .= function_exists('mb_substr') ? mb_substr($last, 0, 1, 'UTF-8') : substr($last, 0, 1);
        }
        return function_exists('mb_strtoupper') ? mb_strtoupper($letters, 'UTF-8') : strtoupper($letters);
    }

    /**
     * Picture shown in the account header, or '' for the local initials.
     * Gravatar is not contacted by default (it would receive the customer's
     * e-mail hash and IP on every account view): a photo supplied locally or by
     * the linked Google account is used, Gravatar only when the site opts in
     * with the dip_account_use_gravatar filter.
     */
    public static function avatar_src($uid, $size = 144) {
        $uid = absint($uid);
        if (!$uid || !get_option('show_avatars')) return '';
        $url = (string) get_avatar_url($uid, ['size' => absint($size), 'default' => '404']);
        if ($url === '') return '';
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $is_gravatar = $host === 'gravatar.com' || substr($host, -13) === '.gravatar.com';
        if ($is_gravatar && !apply_filters('dip_account_use_gravatar', false, $uid)) return '';
        return $url;
    }

    /**
     * Customer-safe security summary, computed once per request and shared by
     * the header, the dashboard and the Identity Center. No global policy,
     * secrets or admin data are exposed here.
     */
    public static function security_context($uid) {
        $uid = absint($uid);
        if (isset(self::$security[$uid])) return self::$security[$uid];

        $score_data = ['score' => 0, 'items' => []];
        if ($uid && class_exists('DIP_Security_Center')) {
            try { $score_data = DIP_Security_Center::score($uid); } catch (Throwable $e) {}
        }
        $items = is_array($score_data['items'] ?? null) ? $score_data['items'] : [];
        $ok = static function ($key) use ($items) { return !empty($items[$key]['ok']); };

        $providers = 0;
        if (class_exists('DIP_Connected_Accounts')) {
            foreach ((array) DIP_Connected_Accounts::status($uid) as $status) if (!empty($status['connected'])) $providers++;
        } else {
            if (get_user_meta($uid, '_dglp_google_sub', true)) $providers++;
            if (get_user_meta($uid, '_dip_microsoft_sub', true)) $providers++;
        }

        $email_verified = $ok('email');
        $passkey = $ok('passkey');
        $two_factor = $ok('two_factor');
        $linked = isset($items['provider']) ? $ok('provider') : $providers > 0;
        $trusted = $ok('trusted');
        $https = isset($items['https']) ? $ok('https') : is_ssl();
        $password_fallback = $ok('password');
        $score = min(100, max(0, absint($score_data['score'] ?? 0)));

        if ($score >= 90) {
            $level = __('Excellent', 'delicat-google-login');
            $tone = 'excellent';
        } elseif ($score >= 70) {
            $level = __('Bien protégé', 'delicat-google-login');
            $tone = 'good';
        } else {
            $level = __('À renforcer', 'delicat-google-login');
            $tone = 'attention';
        }

        $security_url = self::account_url('identity-center');
        if ($security_url !== home_url('/')) $security_url = add_query_arg('dip_tab', 'security', $security_url);
        $cta = ['url' => $security_url, 'label' => __('Gérer ma sécurité', 'delicat-google-login')];
        if (!$email_verified) {
            $recommendation = __('Vérifiez votre adresse e-mail.', 'delicat-google-login');
        } elseif (!$passkey && !$two_factor) {
            // A passkey is the strongest and simplest factor for customers.
            $recommendation = __('Ajoutez une Passkey (Face ID, Touch ID, empreinte ou Windows Hello) : connexion en un geste, sans mot de passe à retenir.', 'delicat-google-login');
            $cta = ['url' => $security_url . '#dip-passkeys', 'label' => __('Ajouter une Passkey', 'delicat-google-login')];
        } elseif (!$linked) {
            $recommendation = __('Ajoutez Google ou Microsoft comme méthode de connexion.', 'delicat-google-login');
        } elseif (!$trusted) {
            $recommendation = __('Vérifiez vos appareils et approuvez uniquement ceux que vous reconnaissez.', 'delicat-google-login');
        } elseif (!$password_fallback) {
            $recommendation = __('Ajoutez un mot de passe de secours pour éviter de perdre l’accès.', 'delicat-google-login');
        } else {
            $recommendation = __('Vos principales protections sont actives.', 'delicat-google-login');
        }

        return self::$security[$uid] = [
            'providers' => $providers,
            'linked' => $linked,
            'trusted' => $trusted,
            'email_verified' => $email_verified,
            'passkey' => $passkey,
            'two_factor' => $two_factor,
            'https' => $https,
            'score' => $score,
            'level' => $level,
            'tone' => $tone,
            'recommendation' => $recommendation,
            'cta' => $cta,
            'items' => $items,
        ];
    }

    /**
     * WooCommerce's own « Mes commandes » query (with every filter that
     * plugins apply to it, e.g. the Checkout block removing draft orders),
     * always scoped to this customer.
     */
    public static function orders_query_args($uid, array $overrides = []) {
        $args = apply_filters('woocommerce_my_account_my_orders_query', [
            'customer' => absint($uid),
            'page' => 1,
            'paginate' => true,
        ]);
        $args = is_array($args) ? $args : [];
        $args = array_merge($args, ['page' => 1], $overrides);
        $args['customer'] = absint($uid);
        unset($args['customer_id'], $args['offset']);
        return $args;
    }

    /** Orders and wallet summary, computed once per request. WooCommerce/TeraWallet stay the sources of truth. */
    public static function commerce_context($uid) {
        $uid = absint($uid);
        if (isset(self::$commerce[$uid])) return self::$commerce[$uid];
        $context = ['wallet' => function_exists('woo_wallet'), 'balance' => null, 'orders' => 0, 'pending' => 0, 'spent' => 0.0, 'recent' => []];

        if ($context['wallet']) {
            try { $context['balance'] = (float) woo_wallet()->wallet->get_wallet_balance($uid, 'edit'); }
            catch (Throwable $e) { $context['balance'] = null; }
        }
        if ($uid && function_exists('wc_get_orders')) {
            try {
                // One query gives the three latest orders and the total shown on « Commandes ».
                $result = wc_get_orders(self::orders_query_args($uid, ['limit' => 3, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC']));
                if (is_object($result)) {
                    $context['orders'] = isset($result->total) ? (int) $result->total : 0;
                    $context['recent'] = isset($result->orders) ? (array) $result->orders : [];
                } elseif (is_array($result)) {
                    $context['recent'] = array_slice($result, 0, 3);
                    $context['orders'] = count($result);
                }
            } catch (Throwable $e) {}
            try {
                $pending = wc_get_orders(self::orders_query_args($uid, ['status' => ['wc-pending', 'wc-processing', 'wc-on-hold'], 'limit' => 1, 'paginate' => true, 'return' => 'ids']));
                $context['pending'] = is_object($pending) && isset($pending->total) ? (int) $pending->total : 0;
            } catch (Throwable $e) {}
        }
        if ($uid && function_exists('wc_get_customer_total_spent')) {
            try { $context['spent'] = (float) wc_get_customer_total_spent($uid); } catch (Throwable $e) {}
        }
        // Only this customer's orders, whatever a filter returned.
        $context['recent'] = array_values(array_filter($context['recent'], static function ($order) use ($uid) {
            return $order instanceof WC_Order && (int) $order->get_customer_id() === $uid;
        }));
        return self::$commerce[$uid] = $context;
    }

    /**
     * Status chip: icon, label and a check/alert glyph, so the state never
     * relies on colour alone. $state (ok, missing) is the spoken state when
     * the label itself does not say it; null for self-explaining labels.
     */
    private static function status_chip($ok, $label, $icon_class, $state = []) {
        $class = $ok ? 'is-ok' : 'is-warn';
        if ($state === []) $state = [__('activé', 'delicat-google-login'), __('à activer', 'delicat-google-login')];
        $spoken = is_array($state) ? ' : ' . ($ok ? $state[0] : $state[1]) : '';
        echo '<span class="dipma-security-chip ' . esc_attr($class) . '"><i class="dipma-icon ' . esc_attr($icon_class) . '" aria-hidden="true"></i><span class="dipma-chip-label">' . esc_html($label) . '</span><i class="dipma-state" aria-hidden="true"></i>' . ($spoken !== '' ? '<span class="screen-reader-text dipma-sr">' . esc_html($spoken) . '</span>' : '') . '</span>';
    }

    /**
     * App-style account header. The dashboard must not embed the customer's
     * e-mail, wallet amount, order history, provider identities or security score.
     * Those details are displayed only on their existing dedicated pages.
     */
    public static function profile_header() {
        if (!self::is_account()) return;
        $user = wp_get_current_user();
        if (!$user->exists()) return;
        $endpoint = self::current_endpoint();
        $home = self::account_url('dashboard');
        $menu = function_exists('wc_get_account_menu_items') ? (array) wc_get_account_menu_items() : [];
        $heading = $endpoint === '' ? __('Compte', 'delicat-google-login') : ($menu[$endpoint] ?? __('Mon compte', 'delicat-google-login'));
        if ($endpoint === 'view-order') {
            // 6.15.2: « Commande #22447 », and Back returns to the orders list.
            $order_id = absint(get_query_var('view-order'));
            $order = $order_id && function_exists('wc_get_order') ? wc_get_order($order_id) : false;
            /* translators: %s: order number */
            $heading = ($order && (int) $order->get_customer_id() === (int) $user->ID) ? sprintf(__('Commande #%s', 'delicat-google-login'), $order->get_order_number()) : __('Commande', 'delicat-google-login');
            $home = self::account_url('orders');
        }
        ?>
        <header class="dipapp-topbar" aria-label="<?php echo esc_attr__('Espace client', 'delicat-google-login'); ?>">
          <?php if ($endpoint !== '') : ?><a class="dipapp-back" href="<?php echo esc_url($home); ?>" aria-label="<?php echo esc_attr__('Retour au compte', 'delicat-google-login'); ?>"><i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i></a><?php endif; ?>
          <h1><?php echo esc_html($heading); ?></h1>
        </header>
        <?php if ($endpoint === '') : ?>
          <a class="dipapp-profile" href="<?php echo esc_url(self::account_url('edit-account')); ?>" aria-label="<?php echo esc_attr__('Ouvrir mon profil', 'delicat-google-login'); ?>">
            <span class="dipapp-avatar" aria-hidden="true"><?php $avatar = self::avatar_src($user->ID, 96); if ($avatar !== '') : ?><img src="<?php echo esc_url($avatar); ?>" alt="" width="48" height="48" decoding="async"><?php else: ?><i class="dipma-icon dipma-icon-user"></i><?php endif; ?></span>
            <span class="dipapp-profile-text"><strong><?php echo esc_html(self::safe_name($user)); ?></strong><small><?php esc_html_e('Gérer mes informations personnelles', 'delicat-google-login'); ?></small></span>
            <i class="dipma-icon dipma-icon-chevron dipapp-chevron" aria-hidden="true"></i>
          </a>
        <?php endif;
    }

    private static function account_url($endpoint) {
        if ($endpoint === 'woo-wallet') return home_url('/my-wallet/');
        return function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url($endpoint) : home_url('/');
    }

    private static function validated_site_url($candidate, $fallback) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') return $fallback;
        if (strpos($candidate, '/') === 0 && strpos($candidate, '//') !== 0) $candidate = home_url($candidate);
        return wp_validate_redirect(esc_url_raw($candidate), $fallback);
    }

    private static function money($amount) {
        return function_exists('wc_price') ? wp_kses_post(wc_price($amount)) : esc_html(number_format_i18n((float) $amount, 2));
    }

    public static function balance_hidden() {
        return isset($_COOKIE[self::BALANCE_COOKIE]) && $_COOKIE[self::BALANCE_COOKIE] === '1';
    }

    /** Render a compact, accessible settings group with no private values. */
    private static function app_group($title, array $rows) {
        if (!$rows) return;
        echo '<section class="dipapp-section"><h2>' . esc_html($title) . '</h2><div class="dipapp-rows">';
        foreach ($rows as $row) {
            $url = isset($row['url']) ? esc_url($row['url']) : '';
            if (!$url) continue;
            $label = isset($row['label']) ? (string) $row['label'] : '';
            $icon = sanitize_html_class($row['icon'] ?? 'dot');
            echo '<a class="dipapp-row" href="' . $url . '">';
            echo '<span class="dipapp-row-icon" aria-hidden="true"><i class="dipma-icon dipma-icon-' . esc_attr($icon) . '"></i></span>';
            echo '<span class="dipapp-row-label">' . esc_html($label) . '</span>';
            echo '<i class="dipma-icon dipma-icon-chevron dipapp-chevron" aria-hidden="true"></i>';
            echo '</a>';
        }
        echo '</div></section>';
    }

    /**
     * Navigation is built only from registered WooCommerce menu items. This
     * preserves third-party endpoints and avoids broken or speculative links.
     * No balance/order/security queries are made on the landing screen.
     */
    public static function dashboard() {
        if (!self::is_account()) return;
        self::private_page();
        $menu = function_exists('wc_get_account_menu_items') ? (array) wc_get_account_menu_items() : [];
        $has = static function ($endpoint) use ($menu) { return isset($menu[$endpoint]); };
        $url = static function ($endpoint) { return self::account_url($endpoint); };
        $security = $has('identity-center') ? $url('identity-center') : '';
        $user_rows = [];
        if ($has('edit-account')) {
            $user_rows[] = ['label' => __('Informations personnelles', 'delicat-google-login'), 'icon' => 'user', 'url' => $url('edit-account')];
            $user_rows[] = ['label' => __('Changer mon mot de passe', 'delicat-google-login'), 'icon' => 'lock', 'url' => $url('change-password')];
        }
        if ($security !== '') {
            $user_rows[] = ['label' => __('Passkey / Face ID', 'delicat-google-login'), 'icon' => 'key', 'url' => add_query_arg('dip_tab', 'security', $security) . '#dip-passkeys'];
            $user_rows[] = ['label' => __('Sécurité et appareils', 'delicat-google-login'), 'icon' => 'shield', 'url' => add_query_arg('dip_tab', 'security', $security)];
            $user_rows[] = ['label' => __('Comptes liés', 'delicat-google-login'), 'icon' => 'link', 'url' => add_query_arg('dip_tab', 'accounts', $security)];
        }
        if ($has('security-sessions')) $user_rows[] = ['label'=>__('Sessions et connexions', 'delicat-google-login'),'icon'=>'device','url'=>$url('security-sessions')];
        $service_rows = [];
        foreach ([
            'orders' => ['Mes commandes', 'orders'],
            'woo-wallet' => ['Mon portefeuille', 'wallet'],
            'downloads' => ['Téléchargements', 'download'],
            'edit-address' => ['Mes adresses', 'pin'],
            'payment-methods' => ['Moyens de paiement', 'wallet'],
        ] as $key => $info) {
            if ($key === 'woo-wallet' || $has($key)) $service_rows[] = ['label' => __($info[0], 'delicat-google-login'), 'icon' => $info[1], 'url' => $url($key)];
        }
        $known = ['dashboard', 'edit-account', 'identity-center', 'orders', 'woo-wallet', 'downloads', 'edit-address', 'payment-methods', 'customer-logout', 'connected-accounts', 'change-password', 'security-sessions'];
        $other_rows = [];
        foreach ($menu as $endpoint => $label) {
            if (!is_string($endpoint) || in_array($endpoint, $known, true)) continue;
            $label = is_scalar($label) ? trim(wp_strip_all_tags((string) $label)) : '';
            if ($label === '') continue;
            $icon = 'dot';
            if (preg_match('/notification|alert/i', $endpoint)) $icon = 'mail';
            elseif (preg_match('/exchange|wallet|payment|paiement/i', $endpoint)) $icon = 'wallet';
            elseif (preg_match('/security|privacy|data|delete|supp/i', $endpoint)) $icon = 'shield';
            elseif (preg_match('/app|device/i', $endpoint)) $icon = 'device';
            $other_rows[] = ['label' => $label, 'icon' => $icon, 'url' => $url($endpoint)];
        }
        $uid = get_current_user_id();
        ?>
        <div class="dipapp-dashboard" aria-label="<?php echo esc_attr__('Paramètres du compte', 'delicat-google-login'); ?>">
          <?php
            self::summary_cards($uid, $has, $url);
            self::recent_orders($uid, $has, $url);
            self::favorites($uid);
            self::app_group(__('Compte et sécurité', 'delicat-google-login'), $user_rows);
            self::app_group(__('Mes services', 'delicat-google-login'), $service_rows);
            self::app_group(__('Autres paramètres', 'delicat-google-login'), $other_rows);
          ?>
          <?php if ($has('customer-logout')) : ?>
            <a class="dipapp-logout" href="<?php echo esc_url(function_exists('wc_logout_url') ? wc_logout_url() : $url('customer-logout')); ?>"><i class="dipma-icon dipma-icon-logout" aria-hidden="true"></i><?php esc_html_e('Déconnexion', 'delicat-google-login'); ?></a>
          <?php endif; ?>
          <p class="dipapp-privacy"><i class="dipma-icon dipma-icon-lock" aria-hidden="true"></i><?php esc_html_e('Page privée : visible uniquement par vous, jamais mise en cache.', 'delicat-google-login'); ?></p>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * 6.15.2 — account home: wallet and rewards, recent orders, favourites.
     *
     * The home now shows the customer's own figures, as the app does. The page
     * stays private (no-store, DONOTCACHEPAGE, LiteSpeed no-cache, set by
     * private_page() before any output), every value belongs to the signed-in
     * customer only, and the « Masquer le solde » preference still hides the
     * amount server-side. WooCommerce, TeraWallet and the rewards provider
     * remain the sources of truth; nothing here writes.
     * ------------------------------------------------------------------ */

    /**
     * Material-style glyphs (24 px, filled, currentColor), drawn after Google's
     * Material Symbols (Apache-2.0). Inline so they need no font or request.
     */
    public static function glyph($name, $class = 'dipapp-glyph') {
        static $paths = [
            'wallet'    => 'M21 18v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v1h-9a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h9Zm-9-2h10V8H12v8Zm4-2.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z',
            'redeem'    => 'M20 6h-2.2c.1-.3.2-.6.2-1a3 3 0 0 0-5.5-1.7L12 4l-.5-.7A3 3 0 0 0 6 5c0 .4.1.7.2 1H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2Zm-5-2a1 1 0 1 1 0 2 1 1 0 0 1 0-2ZM9 4a1 1 0 1 1 0 2 1 1 0 0 1 0-2Zm11 15H4v-2h16v2Zm0-5H4V8h5.1L7 10.8 8.6 12 12 7.4l3.4 4.6 1.6-1.2L14.9 8H20v6Z',
            'orders'    => 'M19.5 3.5 18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2v14H3v3a3 3 0 0 0 3 3h12a3 3 0 0 0 3-3V2l-1.5 1.5ZM15 20H6a1 1 0 0 1-1-1v-1h10v2Zm4-1a1 1 0 0 1-2 0v-3H8V5h11v14ZM9 7h6v2H9V7Zm7 0h2v2h-2V7Zm-7 3h6v2H9v-2Zm7 0h2v2h-2v-2Z',
            'heart'     => 'M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35Z',
            'check'     => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9Z',
            'schedule'  => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16Zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7Z',
            'sync'      => 'M12 6v3l4-4-4-4v3a8 8 0 0 0-6.76 12.26L6.7 14.8A6 6 0 0 1 12 6Zm6.76 1.74L17.3 9.2A6 6 0 0 1 12 18v-3l-4 4 4 4v-3a8 8 0 0 0 6.76-12.26Z',
            'hourglass' => 'M6 2v6l4 4-4 4v6h12v-6l-4-4 4-4V2H6Zm10 14.5V20H8v-3.5l4-4 4 4Zm-4-5-4-4V4h8v3.5l-4 4Z',
            'cancel'    => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59Z',
            'error'     => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z',
            'undo'      => 'M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62A7.99 7.99 0 0 1 20.4 16l2.37-.78A10.5 10.5 0 0 0 12.5 8Z',
            'gift'      => 'M20 6h-2.2c.1-.3.2-.6.2-1a3 3 0 0 0-5.5-1.7L12 4l-.5-.7A3 3 0 0 0 6 5c0 .4.1.7.2 1H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2Zm-5-2a1 1 0 1 1 0 2 1 1 0 0 1 0-2ZM9 4a1 1 0 1 1 0 2 1 1 0 0 1 0-2Zm11 15H4v-2h16v2Zm0-5H4V8h5.1L7 10.8 8.6 12 12 7.4l3.4 4.6 1.6-1.2L14.9 8H20v6Z',
            'add'       => 'M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2Z',
            'arrow'     => 'M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8-8-8Z',
            'eye'       => 'M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5ZM12 17a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z',
        ];
        if (!isset($paths[$name])) return '';
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="currentColor" d="' . $paths[$name] . '"/></svg>';
    }

    /**
     * Customer-facing status of an order: words, tone and glyph. Uses the
     * Builder's payment-aware model when it is active (the order page shows the
     * same wording), else WooCommerce's status.
     */
    public static function order_status_view($order) {
        $glyphs = [
            'awaiting_payment' => 'schedule', 'payment_review' => 'hourglass', 'paid' => 'sync', 'sending' => 'sync',
            'partial' => 'sync', 'review' => 'hourglass', 'delivered' => 'check', 'failed' => 'error',
            'cancelled' => 'cancel', 'refunded' => 'undo',
        ];
        if (class_exists('Delicat_Builder_V9_Order_Status', false) && is_callable(['Delicat_Builder_V9_Order_Status', 'state'])) {
            try {
                $state = Delicat_Builder_V9_Order_Status::state($order);
                $phase = (string) ($state['phase'] ?? '');
                if (isset($glyphs[$phase]) && !empty($state['pill']['label'])) {
                    return ['label' => (string) $state['pill']['label'], 'tone' => sanitize_html_class((string) ($state['pill']['tone'] ?? 'neutral')), 'glyph' => $glyphs[$phase]];
                }
            } catch (Throwable $e) {}
        }
        $map = [
            'pending'    => [__('Paiement en attente', 'delicat-google-login'), 'warning', 'schedule'],
            'on-hold'    => [__('En vérification', 'delicat-google-login'), 'warning', 'hourglass'],
            'processing' => [__('En cours', 'delicat-google-login'), 'accent', 'sync'],
            'completed'  => [__('Livrée', 'delicat-google-login'), 'success', 'check'],
            'cancelled'  => [__('Annulée', 'delicat-google-login'), 'neutral', 'cancel'],
            'refunded'   => [__('Remboursée', 'delicat-google-login'), 'neutral', 'undo'],
            'failed'     => [__('Échouée', 'delicat-google-login'), 'danger', 'error'],
        ];
        $status = is_object($order) && is_callable([$order, 'get_status']) ? (string) $order->get_status() : '';
        if (isset($map[$status])) return ['label' => $map[$status][0], 'tone' => $map[$status][1], 'glyph' => $map[$status][2]];
        $label = function_exists('wc_get_order_status_name') ? (string) wc_get_order_status_name($status) : $status;
        return ['label' => $label, 'tone' => 'neutral', 'glyph' => 'schedule'];
    }

    public static function status_chip_html($order) {
        $view = self::order_status_view($order);
        return '<span class="dipapp-status" data-tone="' . esc_attr($view['tone']) . '">' . self::glyph($view['glyph'], 'dipapp-status-glyph') . '<span>' . esc_html($view['label']) . '</span></span>';
    }

    /** woocommerce_my_account_my_orders_column_order-status: the chip instead of the bare status name. */
    public static function orders_status_column($order) {
        if (!is_object($order)) return;
        echo self::status_chip_html($order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
    }

    /**
     * Rewards shown next to the wallet. A provider answers through the
     * dip_account_rewards filter with ['label','value','hint','url'] (Delicat
     * Recharge does, for its loyalty tiers and referral bonus); WooCommerce
     * Points and Rewards is read directly. null when no programme is active.
     */
    public static function rewards_context($uid) {
        $uid = absint($uid);
        $rewards = apply_filters('dip_account_rewards', null, $uid);
        if ((!is_array($rewards) || empty($rewards['value'])) && class_exists('WC_Points_Rewards_Manager') && is_callable(['WC_Points_Rewards_Manager', 'get_users_points'])) {
            try {
                $points = (int) WC_Points_Rewards_Manager::get_users_points($uid);
                $worth = is_callable(['WC_Points_Rewards_Manager', 'calculate_points_value']) ? (float) WC_Points_Rewards_Manager::calculate_points_value($points) : 0.0;
                $rewards = [
                    'label' => __('Points', 'delicat-google-login'),
                    'value' => number_format_i18n($points),
                    'hint'  => $worth > 0 ? '≈ ' . wp_strip_all_tags(function_exists('wc_price') ? wc_price($worth) : number_format_i18n($worth, 2)) : __('Points fidélité', 'delicat-google-login'),
                    'url'   => function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('points-and-rewards') : '',
                ];
            } catch (Throwable $e) { $rewards = null; }
        }
        if (!is_array($rewards) || !isset($rewards['value']) || (string) $rewards['value'] === '') return null;
        return [
            'label' => sanitize_text_field((string) ($rewards['label'] ?? __('Récompenses', 'delicat-google-login'))),
            'value' => sanitize_text_field((string) $rewards['value']),
            'hint'  => sanitize_text_field((string) ($rewards['hint'] ?? '')),
            'url'   => self::validated_site_url((string) ($rewards['url'] ?? ''), ''),
        ];
    }

    /** Wallet and rewards (or the orders count when no rewards programme runs). */
    private static function summary_cards($uid, $has, $url) {
        $commerce = self::commerce_context($uid);
        $hidden = self::balance_hidden();
        $rewards = self::rewards_context($uid);
        ?>
        <div class="dipapp-summary">
          <?php if ($commerce['wallet'] && $commerce['balance'] !== null) : ?>
            <div class="dipapp-stat dipapp-stat--wallet<?php echo $hidden ? ' is-hidden' : ''; ?>" data-dipma-balance>
              <a class="dipapp-stat-link" href="<?php echo esc_url($url('woo-wallet')); ?>"><span class="screen-reader-text"><?php esc_html_e('Ouvrir mon portefeuille', 'delicat-google-login'); ?></span></a>
              <span class="dipapp-stat-head"><?php echo self::glyph('wallet', 'dipapp-stat-glyph'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e('Portefeuille', 'delicat-google-login'); ?></span>
              <strong class="dipapp-stat-value"><span data-dipma-balance-amount<?php echo $hidden ? ' hidden' : ''; ?>><?php echo self::money($commerce['balance']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_price through wp_kses_post. ?></span><span class="dipapp-mask" data-dipma-balance-mask<?php echo $hidden ? '' : ' hidden'; ?> aria-label="<?php esc_attr_e('Solde masqué', 'delicat-google-login'); ?>">••••</span></strong>
              <span class="dipapp-stat-foot"><span class="dipapp-stat-action"><?php echo self::glyph('add', 'dipapp-stat-mini'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e('Recharger', 'delicat-google-login'); ?></span>
                <button type="button" class="dipapp-eye<?php echo $hidden ? ' is-hidden' : ''; ?>" data-dipma-balance-toggle aria-pressed="<?php echo $hidden ? 'true' : 'false'; ?>" data-label-show="<?php esc_attr_e('Afficher le solde', 'delicat-google-login'); ?>" data-label-hide="<?php esc_attr_e('Masquer le solde', 'delicat-google-login'); ?>" aria-label="<?php echo $hidden ? esc_attr__('Afficher le solde', 'delicat-google-login') : esc_attr__('Masquer le solde', 'delicat-google-login'); ?>"><?php echo self::glyph('eye', 'dipapp-stat-mini'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
              </span>
            </div>
          <?php endif; ?>
          <?php if ($rewards) : ?>
            <div class="dipapp-stat dipapp-stat--rewards">
              <?php if ($rewards['url'] !== '') : ?><a class="dipapp-stat-link" href="<?php echo esc_url($rewards['url']); ?>"><span class="screen-reader-text"><?php echo esc_html($rewards['label']); ?></span></a><?php endif; ?>
              <span class="dipapp-stat-head"><?php echo self::glyph('redeem', 'dipapp-stat-glyph'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html($rewards['label']); ?></span>
              <strong class="dipapp-stat-value"><?php echo esc_html($rewards['value']); ?></strong>
              <span class="dipapp-stat-foot"><span class="dipapp-stat-hint"><?php echo esc_html($rewards['hint']); ?></span></span>
            </div>
          <?php elseif ($has('orders')) : ?>
            <div class="dipapp-stat dipapp-stat--orders">
              <a class="dipapp-stat-link" href="<?php echo esc_url($url('orders')); ?>"><span class="screen-reader-text"><?php esc_html_e('Mes commandes', 'delicat-google-login'); ?></span></a>
              <span class="dipapp-stat-head"><?php echo self::glyph('orders', 'dipapp-stat-glyph'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e('Commandes', 'delicat-google-login'); ?></span>
              <strong class="dipapp-stat-value"><?php echo esc_html(number_format_i18n((int) $commerce['orders'])); ?></strong>
              <span class="dipapp-stat-foot"><span class="dipapp-stat-hint"><?php
                echo esc_html((int) $commerce['pending'] > 0
                    /* translators: %s: number of orders in progress */
                    ? sprintf(_n('%s en cours', '%s en cours', (int) $commerce['pending'], 'delicat-google-login'), number_format_i18n((int) $commerce['pending']))
                    : __('Tout est à jour', 'delicat-google-login'));
              ?></span></span>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    /** First item's name, « + n » for the others. */
    private static function order_title($order) {
        $items = $order->get_items('line_item');
        $first = $items ? reset($items) : null;
        $name = $first ? wp_strip_all_tags((string) $first->get_name()) : __('Commande', 'delicat-google-login');
        $more = max(0, count($items) - 1);
        return $more > 0 ? sprintf('%s + %d', $name, $more) : $name;
    }

    /** 48 px thumbnail of the first item ('' when none). */
    private static function order_thumb($order) {
        $items = $order->get_items('line_item');
        $first = $items ? reset($items) : null;
        $product = $first && is_callable([$first, 'get_product']) ? $first->get_product() : null;
        if (!is_object($product)) return '';
        $image = (int) $product->get_image_id();
        if (!$image && $product->get_parent_id() && function_exists('wc_get_product')) {
            $parent = wc_get_product($product->get_parent_id());
            $image = $parent ? (int) $parent->get_image_id() : 0;
        }
        return $image ? (string) wp_get_attachment_image_url($image, 'thumbnail') : '';
    }

    private static function recent_orders($uid, $has, $url) {
        if (!$has('orders')) return;
        $commerce = self::commerce_context($uid);
        ?>
        <section class="dipapp-section dipapp-recent" aria-labelledby="dipapp-recent-title">
          <div class="dipapp-section-head"><h2 id="dipapp-recent-title"><?php esc_html_e('Commandes récentes', 'delicat-google-login'); ?></h2>
            <?php if (!empty($commerce['recent'])) : ?><a href="<?php echo esc_url($url('orders')); ?>"><?php esc_html_e('Tout voir', 'delicat-google-login'); ?><?php echo self::glyph('arrow', 'dipapp-link-glyph'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a><?php endif; ?>
          </div>
          <?php if (empty($commerce['recent'])) : ?>
            <div class="dipapp-empty"><?php echo self::glyph('orders', 'dipapp-empty-glyph'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><p><?php esc_html_e('Aucune commande pour le moment.', 'delicat-google-login'); ?></p>
              <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/')); ?>"><?php esc_html_e('Découvrir la boutique', 'delicat-google-login'); ?></a></div>
          <?php else : ?>
            <div class="dipapp-orders">
              <?php foreach ($commerce['recent'] as $order) :
                $thumb = self::order_thumb($order);
                $date = $order->get_date_created();
              ?>
                <a class="dipapp-order" href="<?php echo esc_url($order->get_view_order_url()); ?>">
                  <span class="dipapp-order-thumb" aria-hidden="true"><?php if ($thumb !== '') : ?><img src="<?php echo esc_url($thumb); ?>" alt="" width="48" height="48" loading="lazy" decoding="async"><?php else : echo self::glyph('gift', 'dipapp-order-glyph'); endif; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
                  <span class="dipapp-order-main">
                    <span class="dipapp-order-line"><strong><?php echo esc_html(self::order_title($order)); ?></strong><span class="dipapp-order-total"><?php echo self::money($order->get_total()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_price through wp_kses_post. ?></span></span>
                    <span class="dipapp-order-line"><small><?php echo esc_html(($date ? wp_date('j M', $date->getTimestamp()) . ' · ' : '') . '#' . $order->get_order_number()); ?></small><?php echo self::status_chip_html($order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?></span>
                  </span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Favourites saved with the heart on product cards (Delicat Builder keeps
     * them in user meta). Published, visible products only; at most 10.
     */
    public static function favorites_context($uid) {
        $uid = absint($uid);
        $ids = [];
        if (class_exists('Delicat_Builder_V9_Heart_Engine', false) && is_callable(['Delicat_Builder_V9_Heart_Engine', 'favorites_for_current_user']) && $uid === get_current_user_id()) {
            try { $ids = (array) Delicat_Builder_V9_Heart_Engine::favorites_for_current_user(); } catch (Throwable $e) { $ids = []; }
        } else {
            $raw = get_user_meta($uid, '_delicat_builder_v9_favorites', true);
            $ids = is_array($raw) ? $raw : [];
        }
        $ids = apply_filters('dip_account_favorites', $ids, $uid);
        $ids = array_values(array_unique(array_filter(array_map('absint', is_array($ids) ? $ids : []))));
        if (!$ids || !function_exists('wc_get_products')) return ['total' => 0, 'items' => []];
        $total = count($ids);
        $products = [];
        try {
            $products = wc_get_products(['include' => array_slice(array_reverse($ids), 0, 24), 'status' => 'publish', 'limit' => 10, 'orderby' => 'include', 'return' => 'objects']);
        } catch (Throwable $e) { $products = []; }
        $items = [];
        foreach ((array) $products as $product) {
            if (!is_object($product) || !$product->is_visible()) continue;
            $image = (int) $product->get_image_id();
            $items[] = [
                'name'  => wp_strip_all_tags((string) $product->get_name()),
                'url'   => (string) $product->get_permalink(),
                'img'   => $image ? (string) wp_get_attachment_image_url($image, 'woocommerce_thumbnail') : '',
                'price' => (string) $product->get_price_html(),
            ];
        }
        return ['total' => $total, 'items' => $items];
    }

    private static function favorites($uid) {
        $favs = self::favorites_context($uid);
        if (!class_exists('Delicat_Builder_V9_Heart_Engine', false) && !$favs['items']) return;
        ?>
        <section class="dipapp-section dipapp-favs" aria-labelledby="dipapp-favs-title">
          <div class="dipapp-section-head"><h2 id="dipapp-favs-title"><?php esc_html_e('Mes favoris', 'delicat-google-login'); ?><?php if ($favs['total'] > 0) : ?> <span class="dipapp-count"><?php echo esc_html(number_format_i18n($favs['total'])); ?></span><?php endif; ?></h2></div>
          <?php if (!$favs['items']) : ?>
            <div class="dipapp-empty"><?php echo self::glyph('heart', 'dipapp-empty-glyph dipapp-heart'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><p><?php esc_html_e('Touchez le cœur d’un produit pour le retrouver ici.', 'delicat-google-login'); ?></p>
              <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/')); ?>"><?php esc_html_e('Parcourir la boutique', 'delicat-google-login'); ?></a></div>
          <?php else : ?>
            <ul class="dipapp-fav-rail" role="list">
              <?php foreach ($favs['items'] as $item) : ?>
                <li><a class="dipapp-fav" href="<?php echo esc_url($item['url']); ?>" data-delicat-product-link>
                  <span class="dipapp-fav-img"><?php if ($item['img'] !== '') : ?><img src="<?php echo esc_url($item['img']); ?>" alt="" loading="lazy" decoding="async" width="132" height="132"><?php else : echo self::glyph('gift', 'dipapp-order-glyph'); endif; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo self::glyph('heart', 'dipapp-fav-heart'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
                  <span class="dipapp-fav-name"><?php echo esc_html($item['name']); ?></span>
                  <?php if ($item['price'] !== '') : ?><span class="dipapp-fav-price"><?php echo wp_kses_post($item['price']); ?></span><?php endif; ?>
                </a></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
        <?php
    }

    public static function profile_form_intro() {
        if (!self::is_account() || !function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('edit-account')) return;
        $security_url = self::account_url('identity-center');
        ?>
        <section class="dipma-form-intro" aria-label="<?php echo esc_attr__('Profil et préférences', 'delicat-google-login'); ?>">
            <span class="dipma-form-intro-icon" aria-hidden="true"><i class="dipma-icon dipma-icon-shield"></i></span>
            <div>
                <span class="dipma-eyebrow"><?php esc_html_e('PROFIL & PRÉFÉRENCES', 'delicat-google-login'); ?></span>
                <h2><?php esc_html_e('Informations du compte', 'delicat-google-login'); ?></h2>
                <p><?php esc_html_e('Mettez à jour vos informations personnelles. Les changements sensibles restent protégés par Delicat Identity.', 'delicat-google-login'); ?></p>
            </div>
            <a href="<?php echo esc_url($security_url); ?>"><i class="dipma-icon dipma-icon-shield" aria-hidden="true"></i><?php esc_html_e('Sécurité', 'delicat-google-login'); ?></a>
        </section>
        <?php
    }

    public static function orders_toolbar() {
        if (!self::is_account() || !function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('orders')) return;
        if (shortcode_exists('delica_orders')) return;
        ?>
        <div class="dipma-orders-toolbar" role="search">
            <label><i class="dipma-icon dipma-icon-search" aria-hidden="true"></i><input id="dipma-order-search" type="search" autocomplete="off" inputmode="search" placeholder="<?php echo esc_attr__('Rechercher une commande…', 'delicat-google-login'); ?>" aria-label="<?php echo esc_attr__('Rechercher une commande sur cette page', 'delicat-google-login'); ?>"><button type="button" data-dipma-search-clear aria-label="<?php echo esc_attr__('Effacer la recherche', 'delicat-google-login'); ?>">×</button></label>
            <div data-dipma-search-empty role="status" hidden><span data-dipma-search-empty-text><?php esc_html_e('Aucune commande correspondante.', 'delicat-google-login'); ?></span><span data-dipma-search-paged hidden><?php esc_html_e('Aucune commande correspondante sur cette page. Les commandes plus anciennes se trouvent sur les pages suivantes.', 'delicat-google-login'); ?></span></div>
        </div>
        <?php
    }

    /**
     * The old /my-account/connected-accounts/ screen now lives in the Identity
     * Center: send old links and the legacy handlers there, keeping their
     * (whitelisted) status so the right tab shows the right message.
     */
    public static function redirect_legacy_identity_endpoint() {
        if (!is_user_logged_in() || !function_exists('is_account_page') || !is_account_page()) return;
        if (!class_exists('DIP_WooCommerce') || get_query_var(DIP_WooCommerce::ENDPOINT, null) === null) return;
        $args = ['dip_tab' => 'accounts'];
        $anchor = '';
        $sessions = sanitize_key(wp_unslash($_GET['dip_sessions'] ?? ''));
        $reauth = sanitize_key(wp_unslash($_GET['dip_reauth_status'] ?? ''));
        $password = sanitize_key(wp_unslash($_GET['dip_password_email'] ?? ''));
        $account = sanitize_key(wp_unslash($_GET['dip_account_status'] ?? ''));
        if ($reauth !== '') {
            $args = ['dip_tab' => 'security', 'dip_reauth_status' => $reauth];
            $anchor = '#' . (defined('DIP_Reauth::ANCHOR') ? DIP_Reauth::ANCHOR : 'dip-reauth');
        } elseif ($sessions === 'revoked') {
            $args = ['dip_tab' => 'security', 'dip_security_status' => 'sessions'];
        } elseif (in_array($password, ['sent', 'failed'], true)) {
            $args['dip_password_email'] = $password;
        } elseif (in_array($account, ['disconnected', 'blocked'], true)) {
            $args['dip_account_status'] = $account;
        }
        wp_safe_redirect(add_query_arg($args, self::account_url('identity-center')) . $anchor, 302, 'Delicat Identity');
        exit;
    }
}
