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
        wp_enqueue_style('dip-my-account-modern', DIP_URL . 'assets/my-account-modern.css', [], DIP_VERSION);
        wp_enqueue_script('dip-my-account-modern', DIP_URL . 'assets/my-account-modern.js', [], DIP_VERSION, true);
        if (function_exists('wp_script_add_data')) wp_script_add_data('dip-my-account-modern', 'strategy', 'defer');
    }

    /**
     * Customer data is about to be printed: make sure no page cache keeps it,
     * even when the page was not detected up front (block templates, widgets,
     * other builders). Account pages already answer no-store.
     */
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

    public static function profile_header() {
        if (!self::is_account()) return;
        $uid = get_current_user_id();
        $user = wp_get_current_user();
        if (!$uid || !$user->exists()) return;
        self::private_page();

        $name = self::safe_name($user);
        $initials = self::initials($name);
        $avatar = self::avatar_src($uid);
        $security = self::security_context($uid);
        $profile_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('edit-account') : get_edit_profile_url($uid);
        $security_url = $security['cta']['url'];
        if ($security['passkey']) {
            $factor_label = __('Passkey active', 'delicat-google-login');
        } elseif ($security['two_factor']) {
            $factor_label = __('2FA active', 'delicat-google-login');
        } else {
            $factor_label = __('Ajouter une Passkey', 'delicat-google-login');
        }
        ?>
        <section class="dipma-profile" aria-label="<?php echo esc_attr__('Profil du client', 'delicat-google-login'); ?>">
            <a class="dipma-avatar" href="<?php echo esc_url($profile_url); ?>" aria-label="<?php echo esc_attr__('Modifier mon profil', 'delicat-google-login'); ?>">
                <span class="dipma-avatar-fallback" aria-hidden="true"><?php echo esc_html($initials); ?></span>
                <?php if ($avatar !== '') : ?><img src="<?php echo esc_url($avatar); ?>" alt="" width="72" height="72" decoding="async" referrerpolicy="no-referrer" data-dipma-avatar><?php endif; ?>
                <span class="dipma-avatar-edit" aria-hidden="true"><i class="dipma-icon dipma-icon-edit"></i></span>
            </a>
            <div class="dipma-profile-copy">
                <div class="dipma-profile-kicker"><span class="dipma-brand-dot" aria-hidden="true"></span><?php esc_html_e('DELICAT IDENTITY', 'delicat-google-login'); ?></div>
                <strong><?php echo esc_html($name); ?></strong>
                <span><?php echo esc_html($user->user_email); ?></span>
            </div>
            <div class="dipma-profile-mini-status">
                <?php self::status_chip($security['email_verified'], $security['email_verified'] ? __('Email vérifié', 'delicat-google-login') : __('Email à vérifier', 'delicat-google-login'), 'dipma-icon-mail', null); ?>
                <?php self::status_chip($security['passkey'] || $security['two_factor'], $factor_label, 'dipma-icon-key', null); ?>
                <?php self::status_chip($security['linked'], $security['linked'] ? __('Connexion liée', 'delicat-google-login') : __('Ajouter une connexion', 'delicat-google-login'), 'dipma-icon-link', null); ?>
            </div>
            <a class="dipma-security-pill is-<?php echo esc_attr($security['tone']); ?>" href="<?php echo esc_url($security_url); ?>" aria-label="<?php echo esc_attr(sprintf(__('Sécurité du compte : %1$d sur 100, %2$s', 'delicat-google-login'), $security['score'], $security['level'])); ?>">
                <span class="dipma-security-shield" aria-hidden="true"><i class="dipma-icon dipma-icon-shield"></i></span>
                <span class="dipma-security-pill-copy" aria-hidden="true"><small><?php esc_html_e('Protection', 'delicat-google-login'); ?></small><strong><b><?php echo esc_html($security['score']); ?></b><em>/100</em></strong><span><?php echo esc_html($security['level']); ?></span></span>
                <i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i>
            </a>
        </section>
        <?php
    }

    private static function account_url($endpoint) {
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

    public static function dashboard() {
        if (!self::is_account()) return;
        $uid = get_current_user_id();
        $user = wp_get_current_user();
        if (!$uid || !$user->exists()) return;
        self::private_page();

        $hour = (int) current_time('G');
        $greeting = $hour < 12 ? __('Bonjour', 'delicat-google-login') : ($hour < 18 ? __('Bon après-midi', 'delicat-google-login') : __('Bonsoir', 'delicat-google-login'));
        $name = self::safe_name($user);
        $commerce = self::commerce_context($uid);
        $security = self::security_context($uid);
        $balance = $commerce['balance'];
        $hidden = self::balance_hidden();

        $orders_url = self::account_url('orders');
        $meter = (int) (round($security['score'] / 5) * 5);
        ?>
        <div class="dipma-dashboard">
            <?php if ($commerce['wallet']) :
                $history_url = self::validated_site_url(apply_filters('dip_my_account_history_url', self::account_url('woo-wallet')), self::account_url('woo-wallet'));
                $recharge_default = home_url('/my-wallet/');
                $send_default = home_url('/envoyer-de-largent/');
                $recharge_url = self::validated_site_url(defined('DELICAT_RECHARGE_URL') ? DELICAT_RECHARGE_URL : $recharge_default, $recharge_default);
                $send_url = self::validated_site_url(defined('DELICAT_SEND_PAGE') ? DELICAT_SEND_PAGE : $send_default, $send_default);
                ?>
            <section class="dipma-wallet-card" aria-labelledby="dipma-wallet-title">
                <div class="dipma-wallet-aurora dipma-wallet-aurora-a" aria-hidden="true"></div>
                <div class="dipma-wallet-aurora dipma-wallet-aurora-b" aria-hidden="true"></div>
                <div class="dipma-wallet-head">
                    <div>
                        <span class="dipma-eyebrow dipma-eyebrow-light"><?php esc_html_e('MON PORTEFEUILLE', 'delicat-google-login'); ?></span>
                        <h2 id="dipma-wallet-title"><?php esc_html_e('Solde disponible', 'delicat-google-login'); ?></h2>
                        <p><?php echo esc_html($greeting . ', ' . $name); ?> · <?php esc_html_e('votre espace financier sécurisé', 'delicat-google-login'); ?></p>
                    </div>
                    <span class="dipma-live"><i aria-hidden="true"></i><?php esc_html_e('Synchronisé', 'delicat-google-login'); ?></span>
                </div>
                <div class="dipma-wallet-visual" aria-hidden="true"><span class="dipma-wallet-shell"><i></i><b>G</b></span></div>
                <div class="dipma-balance-wrap">
                    <div class="dipma-balance-block">
                        <span><?php esc_html_e('Solde du portefeuille', 'delicat-google-login'); ?></span>
                        <div class="dipma-balance-line">
                            <strong data-dipma-balance class="<?php echo $hidden ? 'is-hidden' : ''; ?>"><span data-dipma-balance-amount<?php echo $hidden ? ' hidden' : ''; ?>><?php echo $balance === null ? '—' : self::money($balance); ?></span><span data-dipma-balance-mask<?php echo $hidden ? '' : ' hidden'; ?>><span aria-hidden="true">••••</span><span class="screen-reader-text dipma-sr"><?php esc_html_e('Solde masqué', 'delicat-google-login'); ?></span></span></strong>
                            <button type="button" class="<?php echo $hidden ? 'is-hidden' : ''; ?>" data-dipma-balance-toggle aria-pressed="<?php echo $hidden ? 'true' : 'false'; ?>" aria-label="<?php echo $hidden ? esc_attr__('Afficher le solde', 'delicat-google-login') : esc_attr__('Masquer le solde', 'delicat-google-login'); ?>" data-label-show="<?php echo esc_attr__('Afficher le solde', 'delicat-google-login'); ?>" data-label-hide="<?php echo esc_attr__('Masquer le solde', 'delicat-google-login'); ?>"><i class="dipma-icon dipma-icon-eye" aria-hidden="true"></i></button>
                        </div>
                        <small><i class="dipma-icon dipma-icon-lock" aria-hidden="true"></i><?php esc_html_e('Données privées · visibles uniquement dans votre session', 'delicat-google-login'); ?></small>
                    </div>
                    <nav class="dipma-quick-actions" aria-label="<?php echo esc_attr__('Actions du portefeuille', 'delicat-google-login'); ?>">
                        <a class="is-primary" href="<?php echo esc_url($recharge_url); ?>"><i class="dipma-icon dipma-icon-plus" aria-hidden="true"></i><span><?php esc_html_e('Recharger', 'delicat-google-login'); ?></span></a>
                        <a href="<?php echo esc_url($send_url); ?>"><i class="dipma-icon dipma-icon-send" aria-hidden="true"></i><span><?php esc_html_e('Envoyer', 'delicat-google-login'); ?></span></a>
                        <a href="<?php echo esc_url($history_url); ?>"><i class="dipma-icon dipma-icon-history" aria-hidden="true"></i><span><?php esc_html_e('Historique', 'delicat-google-login'); ?></span></a>
                    </nav>
                </div>
            </section>
            <?php endif; ?>

            <div class="dipma-overview-grid">
                <section class="dipma-stats" aria-label="<?php echo esc_attr__('Résumé du compte', 'delicat-google-login'); ?>">
                    <a href="<?php echo esc_url($orders_url); ?>"><span class="dipma-stat-icon dipma-icon-orders" aria-hidden="true"></span><span class="dipma-stat-copy"><strong><?php echo esc_html(number_format_i18n($commerce['orders'])); ?></strong><small><?php esc_html_e('Commandes', 'delicat-google-login'); ?></small></span><i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i></a>
                    <a href="<?php echo esc_url($orders_url); ?>"><span class="dipma-stat-icon dipma-icon-clock" aria-hidden="true"></span><span class="dipma-stat-copy"><strong><?php echo esc_html(number_format_i18n($commerce['pending'])); ?></strong><small><?php esc_html_e('En cours', 'delicat-google-login'); ?></small></span><i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i></a>
                    <div><span class="dipma-stat-icon dipma-icon-spend" aria-hidden="true"></span><span class="dipma-stat-copy"><strong class="dipma-stat-money"><?php echo self::money($commerce['spent']); ?></strong><small><?php esc_html_e('Total dépensé', 'delicat-google-login'); ?></small></span></div>
                </section>

                <section class="dipma-security-card is-<?php echo esc_attr($security['tone']); ?>" aria-labelledby="dipma-security-title">
                    <div class="dipma-security-card-head">
                        <span class="dipma-security-card-icon" aria-hidden="true"><i class="dipma-icon dipma-icon-shield"></i></span>
                        <div><span class="dipma-eyebrow"><?php esc_html_e('PROTECTION DU COMPTE', 'delicat-google-login'); ?></span><h3 id="dipma-security-title"><?php echo esc_html($security['level']); ?></h3></div>
                        <span class="dipma-security-score"><b><?php echo esc_html($security['score']); ?></b><small>/100</small></span>
                    </div>
                    <div class="dipma-security-meter dipma-score-<?php echo esc_attr($meter); ?>" role="progressbar" aria-label="<?php echo esc_attr__('Score de sécurité', 'delicat-google-login'); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($security['score']); ?>"><span></span></div>
                    <p><?php echo esc_html($security['recommendation']); ?></p>
                    <div class="dipma-security-checks">
                        <?php self::status_chip($security['email_verified'], __('Email', 'delicat-google-login'), 'dipma-icon-mail', [__('vérifié', 'delicat-google-login'), __('à vérifier', 'delicat-google-login')]); ?>
                        <?php self::status_chip($security['passkey'], __('Passkey', 'delicat-google-login'), 'dipma-icon-key'); ?>
                        <?php self::status_chip($security['two_factor'], __('2FA', 'delicat-google-login'), 'dipma-icon-shield'); ?>
                        <?php self::status_chip($security['linked'], __('Connexion liée', 'delicat-google-login'), 'dipma-icon-link', [__('oui', 'delicat-google-login'), __('aucune', 'delicat-google-login')]); ?>
                        <?php self::status_chip($security['trusted'], __('Appareil fiable', 'delicat-google-login'), 'dipma-icon-device', [__('oui', 'delicat-google-login'), __('aucun', 'delicat-google-login')]); ?>
                        <?php self::status_chip($security['https'], __('HTTPS', 'delicat-google-login'), 'dipma-icon-lock', [__('actif', 'delicat-google-login'), __('inactif', 'delicat-google-login')]); ?>
                    </div>
                    <a class="dipma-security-cta" href="<?php echo esc_url($security['cta']['url']); ?>"><i class="dipma-icon dipma-icon-shield" aria-hidden="true"></i><span><?php echo esc_html($security['cta']['label']); ?></span><i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i></a>
                </section>
            </div>

            <section class="dipma-recent" aria-labelledby="dipma-recent-title">
                <div class="dipma-section-head"><div><span class="dipma-eyebrow"><?php esc_html_e('ACTIVITÉ', 'delicat-google-login'); ?></span><h3 id="dipma-recent-title"><?php esc_html_e('Commandes récentes', 'delicat-google-login'); ?></h3></div><a href="<?php echo esc_url($orders_url); ?>"><?php esc_html_e('Tout voir', 'delicat-google-login'); ?><i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i></a></div>
                <?php if (!empty($commerce['recent'])) : ?><ul class="dipma-order-list"><?php foreach ($commerce['recent'] as $order) :
                    $product_name = __('Commande Delicat Store', 'delicat-google-login');
                    $thumb = '<span class="dipma-product-fallback" aria-hidden="true">◆</span>';
                    foreach ($order->get_items() as $item) {
                        $product_name = $item->get_name();
                        $product = $item->get_product();
                        if ($product && $product->get_image_id()) {
                            $thumb = wp_get_attachment_image($product->get_image_id(), [64,64], false, ['loading'=>'lazy','decoding'=>'async','alt'=>'']);
                        }
                        break;
                    }
                    $status = sanitize_html_class($order->get_status());
                    $created = $order->get_date_created();
                    $date_label = $created && function_exists('wc_format_datetime') ? wc_format_datetime($created, 'j M Y') : '';
                    $status_name = function_exists('wc_get_order_status_name') ? wc_get_order_status_name($order->get_status()) : $order->get_status();
                    ?>
                    <li><a class="dipma-order" href="<?php echo esc_url($order->get_view_order_url()); ?>">
                        <span class="dipma-order-thumb"><?php echo wp_kses_post($thumb); ?></span>
                        <span class="dipma-order-info"><strong><?php echo esc_html($product_name); ?></strong><small>#<?php echo esc_html($order->get_order_number()); ?><?php echo $date_label !== '' ? ' · ' . esc_html($date_label) : ''; ?></small></span>
                        <span class="dipma-order-right"><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong><mark class="status-<?php echo esc_attr($status); ?>"><i aria-hidden="true"></i><?php echo esc_html($status_name); ?></mark></span>
                        <i class="dipma-icon dipma-icon-chevron dipma-chevron" aria-hidden="true"></i>
                    </a></li>
                <?php endforeach; ?></ul><?php else : ?>
                    <div class="dipma-empty"><span class="dipma-empty-icon dipma-icon-orders" aria-hidden="true"></span><strong><?php esc_html_e('Aucune commande pour le moment', 'delicat-google-login'); ?></strong><small><?php esc_html_e('Vos prochains achats apparaîtront ici.', 'delicat-google-login'); ?></small></div>
                <?php endif; ?>
                <a class="dipma-all-orders" href="<?php echo esc_url($orders_url); ?>"><span><?php esc_html_e('Voir toutes mes commandes', 'delicat-google-login'); ?></span><i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i></a>
            </section>
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
