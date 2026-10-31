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
        ?>
        <div class="dipapp-dashboard" aria-label="<?php echo esc_attr__('Paramètres du compte', 'delicat-google-login'); ?>">
          <?php
            self::app_group(__('Compte et sécurité', 'delicat-google-login'), $user_rows);
            self::app_group(__('Mes services', 'delicat-google-login'), $service_rows);
            self::app_group(__('Autres paramètres', 'delicat-google-login'), $other_rows);
          ?>
          <?php if ($has('customer-logout')) : ?>
            <a class="dipapp-logout" href="<?php echo esc_url(function_exists('wc_logout_url') ? wc_logout_url() : $url('customer-logout')); ?>"><i class="dipma-icon dipma-icon-logout" aria-hidden="true"></i><?php esc_html_e('Déconnexion', 'delicat-google-login'); ?></a>
          <?php endif; ?>
          <p class="dipapp-privacy"><i class="dipma-icon dipma-icon-lock" aria-hidden="true"></i><?php esc_html_e('Les données privées sont accessibles uniquement dans les rubriques concernées.', 'delicat-google-login'); ?></p>
        </div>
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
