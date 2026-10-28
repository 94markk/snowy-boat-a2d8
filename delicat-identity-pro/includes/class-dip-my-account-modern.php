<?php
defined('ABSPATH') || exit;

/**
 * Delicat Identity — unified premium WooCommerce My Account presentation.
 *
 * Presentation and customer-safe status only. WordPress/WooCommerce/TeraWallet
 * and Delicat Identity remain the sources of truth for authorization and data.
 */
final class DIP_My_Account_Modern {
    private static $initialized = false;

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

    public static function body_classes($classes) {
        if (!self::is_account()) return $classes;
        $classes[] = 'dip-account-modern';
        $classes[] = 'dip-account-premium';
        if (self::is_dashboard()) {
            $classes[] = 'dip-account-home';
        } elseif (function_exists('is_wc_endpoint_url')) {
            foreach (['orders','view-order','downloads','edit-address','woo-wallet','edit-account','identity-center'] as $endpoint) {
                if (is_wc_endpoint_url($endpoint)) {
                    $classes[] = 'dip-account-endpoint-' . sanitize_html_class($endpoint);
                    break;
                }
            }
        }
        return array_values(array_unique($classes));
    }

    private static function is_dashboard() {
        if (!function_exists('is_wc_endpoint_url')) return true;
        return !is_wc_endpoint_url();
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

    public static function translate_account_strings($translated, $text, $domain) {
        if (!self::is_account() || !function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('edit-account')) return $translated;
        $map = [
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
    }

    private static function safe_name(WP_User $user) {
        $name = trim((string) $user->first_name . ' ' . (string) $user->last_name);
        if ($name === '') $name = $user->display_name ?: $user->user_login;
        return sanitize_text_field($name);
    }

    private static function initials($name) {
        $parts = preg_split('/\s+/u', trim((string) $name));
        $letters = '';
        if (!empty($parts[0])) $letters .= function_exists('mb_substr') ? mb_substr($parts[0], 0, 1, 'UTF-8') : substr($parts[0], 0, 1);
        if (count($parts) > 1) {
            $last = end($parts);
            if ($last !== false) $letters .= function_exists('mb_substr') ? mb_substr($last, 0, 1, 'UTF-8') : substr($last, 0, 1);
        }
        return function_exists('mb_strtoupper') ? mb_strtoupper($letters, 'UTF-8') : strtoupper($letters);
    }

    /** Customer-safe security summary. No global policy/secrets/admin data are exposed here. */
    private static function security_profile($uid) {
        $uid = absint($uid);
        $providers = 0;
        if (get_user_meta($uid, '_dglp_google_sub', true) || get_user_meta($uid, 'dip_google_sub', true)) $providers++;
        if (get_user_meta($uid, '_dip_microsoft_sub', true) || get_user_meta($uid, 'dip_microsoft_sub', true)) $providers++;

        $trusted = 0;
        if (class_exists('DIP_Devices')) {
            try {
                $rows = DIP_Devices::user_devices($uid);
                foreach ((array) $rows as $row) if (!empty($row['trusted'])) $trusted++;
            } catch (Throwable $e) {}
        }

        $score_data = class_exists('DIP_Security_Center') ? DIP_Security_Center::score($uid) : ['score'=>0,'items'=>[]];
        $items = is_array($score_data['items'] ?? null) ? $score_data['items'] : [];
        $email_verified = !empty($items['email']['ok']);
        $https = isset($items['https']) ? !empty($items['https']['ok']) : is_ssl();
        $password_fallback = !empty($items['password']['ok']);
        $two_factor = !empty($items['two_factor']['ok']);
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

        if (!$email_verified) {
            $recommendation = __('Vérifiez votre adresse e-mail.', 'delicat-google-login');
        } elseif (!$two_factor) {
            $recommendation = __('Activez la vérification en deux étapes pour protéger les connexions.', 'delicat-google-login');
        } elseif ($providers < 1) {
            $recommendation = __('Ajoutez Google ou Microsoft comme méthode de connexion.', 'delicat-google-login');
        } elseif ($trusted < 1) {
            $recommendation = __('Vérifiez vos appareils et approuvez uniquement ceux que vous reconnaissez.', 'delicat-google-login');
        } elseif (!$password_fallback) {
            $recommendation = __('Ajoutez un mot de passe de secours pour éviter de perdre l’accès.', 'delicat-google-login');
        } else {
            $recommendation = __('Vos principales protections sont actives.', 'delicat-google-login');
        }

        return [
            'providers' => $providers,
            'trusted' => $trusted,
            'email_verified' => $email_verified,
            'two_factor' => $two_factor,
            'https' => $https,
            'score' => $score,
            'level' => $level,
            'tone' => $tone,
            'recommendation' => $recommendation,
        ];
    }

    private static function status_chip($ok, $label, $icon_class) {
        $class = $ok ? 'is-ok' : 'is-warn';
        echo '<span class="dipma-security-chip ' . esc_attr($class) . '"><i class="dipma-icon ' . esc_attr($icon_class) . '" aria-hidden="true"></i>' . esc_html($label) . '</span>';
    }

    public static function profile_header() {
        if (!self::is_account()) return;
        $uid = get_current_user_id();
        $user = wp_get_current_user();
        if (!$uid || !$user->exists()) return;

        $name = self::safe_name($user);
        $initials = self::initials($name);
        $avatar = get_avatar_url($uid, ['size'=>144, 'default'=>'blank']);
        $security = self::security_profile($uid);
        $profile_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('edit-account') : get_edit_profile_url($uid);
        $security_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('identity-center') : home_url('/');
        ?>
        <section class="dipma-profile" aria-label="<?php echo esc_attr__('Profil du client', 'delicat-google-login'); ?>">
            <div class="dipma-profile-glow" aria-hidden="true"></div>
            <a class="dipma-avatar" href="<?php echo esc_url($profile_url); ?>#avatar" aria-label="<?php echo esc_attr__('Modifier mon profil', 'delicat-google-login'); ?>">
                <span class="dipma-avatar-fallback" aria-hidden="true"><?php echo esc_html($initials); ?></span>
                <img src="<?php echo esc_url($avatar); ?>" alt="" width="72" height="72" decoding="async" data-dipma-avatar>
                <span class="dipma-avatar-edit" aria-hidden="true"><i class="dipma-icon dipma-icon-edit"></i></span>
            </a>
            <div class="dipma-profile-copy">
                <div class="dipma-profile-kicker"><span class="dipma-brand-dot"></span><?php esc_html_e('DELICAT IDENTITY', 'delicat-google-login'); ?></div>
                <strong><?php echo esc_html($name); ?></strong>
                <span><?php echo esc_html($user->user_email); ?></span>
                <div class="dipma-profile-mini-status">
                    <?php self::status_chip($security['email_verified'], $security['email_verified'] ? __('Email vérifié', 'delicat-google-login') : __('Email à vérifier', 'delicat-google-login'), 'dipma-icon-mail'); ?>
                    <?php self::status_chip($security['two_factor'], $security['two_factor'] ? __('2FA active', 'delicat-google-login') : __('2FA à activer', 'delicat-google-login'), 'dipma-icon-shield'); ?>
                    <?php self::status_chip($security['providers'] > 0, $security['providers'] > 0 ? __('Connexion liée', 'delicat-google-login') : __('Ajouter une connexion', 'delicat-google-login'), 'dipma-icon-link'); ?>
                </div>
            </div>
            <a class="dipma-security-pill is-<?php echo esc_attr($security['tone']); ?>" href="<?php echo esc_url($security_url); ?>" aria-label="<?php echo esc_attr(sprintf(__('Sécurité du compte : %d sur 100', 'delicat-google-login'), $security['score'])); ?>">
                <span class="dipma-security-shield" aria-hidden="true"><i class="dipma-icon dipma-icon-shield"></i></span>
                <span class="dipma-security-pill-copy"><small><?php esc_html_e('Protection', 'delicat-google-login'); ?></small><strong><b><?php echo esc_html($security['score']); ?></b><em>/100</em></strong><span><?php echo esc_html($security['level']); ?></span></span>
                <i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i>
            </a>
        </section>
        <?php
    }

    private static function wallet_balance($uid) {
        if (!function_exists('woo_wallet')) return null;
        try { return (float) woo_wallet()->wallet->get_wallet_balance($uid, 'edit'); }
        catch (Throwable $e) { return null; }
    }

    private static function order_count_for_statuses($uid, $statuses) {
        if (!function_exists('wc_get_orders')) return 0;
        try {
            $result = wc_get_orders([
                'customer' => absint($uid),
                'status' => array_values((array) $statuses),
                'limit' => 1,
                'paginate' => true,
                'return' => 'ids',
            ]);
            return is_object($result) && isset($result->total) ? (int) $result->total : 0;
        } catch (Throwable $e) { return 0; }
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

    public static function dashboard() {
        if (!self::is_account()) return;
        $uid = get_current_user_id();
        $user = wp_get_current_user();
        if (!$uid || !$user->exists()) return;

        $hour = (int) current_time('G');
        $greeting = $hour < 12 ? __('Bonjour', 'delicat-google-login') : ($hour < 18 ? __('Bon après-midi', 'delicat-google-login') : __('Bonsoir', 'delicat-google-login'));
        $name = self::safe_name($user);
        $balance = self::wallet_balance($uid);
        $total_orders = function_exists('wc_get_customer_order_count') ? (int) wc_get_customer_order_count($uid) : 0;
        $total_spent = function_exists('wc_get_customer_total_spent') ? (float) wc_get_customer_total_spent($uid) : 0.0;
        $pending = self::order_count_for_statuses($uid, ['wc-pending','wc-processing','wc-on-hold']);
        $security = self::security_profile($uid);

        $recent = [];
        if (function_exists('wc_get_orders')) {
            try { $recent = wc_get_orders(['customer'=>$uid,'limit'=>3,'orderby'=>'date','order'=>'DESC']); }
            catch (Throwable $e) { $recent = []; }
        }

        $orders_url = self::account_url('orders');
        $history_url = self::validated_site_url(apply_filters('dip_my_account_history_url', self::account_url('woo-wallet')), self::account_url('woo-wallet'));
        $security_url = self::account_url('identity-center');
        $recharge_default = home_url('/my-wallet/');
        $send_default = home_url('/envoyer-de-largent/');
        $recharge_url = self::validated_site_url(defined('DELICAT_RECHARGE_URL') ? DELICAT_RECHARGE_URL : $recharge_default, $recharge_default);
        $send_url = self::validated_site_url(defined('DELICAT_SEND_PAGE') ? DELICAT_SEND_PAGE : $send_default, $send_default);
        ?>
        <div class="dipma-dashboard">
            <section class="dipma-wallet-card">
                <div class="dipma-wallet-aurora dipma-wallet-aurora-a" aria-hidden="true"></div>
                <div class="dipma-wallet-aurora dipma-wallet-aurora-b" aria-hidden="true"></div>
                <div class="dipma-wallet-head">
                    <div>
                        <span class="dipma-eyebrow dipma-eyebrow-light"><?php esc_html_e('MON PORTEFEUILLE', 'delicat-google-login'); ?></span>
                        <h2><?php esc_html_e('Solde disponible', 'delicat-google-login'); ?></h2>
                        <p><?php echo esc_html($greeting . ', ' . $name); ?> · <?php esc_html_e('votre espace financier sécurisé', 'delicat-google-login'); ?></p>
                    </div>
                    <span class="dipma-live"><i></i><?php esc_html_e('Synchronisé', 'delicat-google-login'); ?></span>
                </div>
                <div class="dipma-wallet-visual" aria-hidden="true"><span class="dipma-wallet-shell"><i></i><b>G</b></span></div>
                <div class="dipma-balance-wrap">
                    <div class="dipma-balance-block">
                        <span><?php esc_html_e('Solde du portefeuille', 'delicat-google-login'); ?></span>
                        <div class="dipma-balance-line">
                            <strong data-dipma-balance data-value="<?php echo esc_attr($balance === null ? '' : (string) $balance); ?>"><?php echo $balance === null ? '—' : wp_kses_post(wc_price($balance)); ?></strong>
                            <button type="button" data-dipma-balance-toggle aria-pressed="false" aria-label="<?php echo esc_attr__('Masquer le solde', 'delicat-google-login'); ?>"><i class="dipma-icon dipma-icon-eye" aria-hidden="true"></i></button>
                        </div>
                        <small><i class="dipma-icon dipma-icon-lock" aria-hidden="true"></i><?php esc_html_e('Données privées · visibles uniquement dans votre session', 'delicat-google-login'); ?></small>
                    </div>
                    <div class="dipma-quick-actions">
                        <a class="is-primary" href="<?php echo esc_url($recharge_url); ?>"><i class="dipma-icon dipma-icon-plus" aria-hidden="true"></i><span><?php esc_html_e('Recharger', 'delicat-google-login'); ?></span></a>
                        <a href="<?php echo esc_url($send_url); ?>"><i class="dipma-icon dipma-icon-send" aria-hidden="true"></i><span><?php esc_html_e('Envoyer', 'delicat-google-login'); ?></span></a>
                        <a href="<?php echo esc_url($history_url); ?>"><i class="dipma-icon dipma-icon-history" aria-hidden="true"></i><span><?php esc_html_e('Historique', 'delicat-google-login'); ?></span></a>
                    </div>
                </div>
            </section>

            <div class="dipma-overview-grid">
                <section class="dipma-stats" aria-label="<?php echo esc_attr__('Résumé du compte', 'delicat-google-login'); ?>">
                    <a href="<?php echo esc_url($orders_url); ?>"><span class="dipma-stat-icon dipma-icon-orders"></span><div><strong data-dipma-count="<?php echo esc_attr($total_orders); ?>">0</strong><small><?php esc_html_e('Commandes', 'delicat-google-login'); ?></small></div><i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i></a>
                    <a href="<?php echo esc_url($orders_url); ?>"><span class="dipma-stat-icon dipma-icon-clock"></span><div><strong data-dipma-count="<?php echo esc_attr($pending); ?>">0</strong><small><?php esc_html_e('En cours', 'delicat-google-login'); ?></small></div><i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i></a>
                    <div><span class="dipma-stat-icon dipma-icon-spend"></span><div><strong class="dipma-stat-money"><?php echo function_exists('wc_price') ? wp_kses_post(wc_price($total_spent)) : esc_html(number_format_i18n($total_spent, 2)); ?></strong><small><?php esc_html_e('Total dépensé', 'delicat-google-login'); ?></small></div></div>
                </section>

                <section class="dipma-security-card is-<?php echo esc_attr($security['tone']); ?>" aria-label="<?php echo esc_attr__('État de sécurité du compte', 'delicat-google-login'); ?>">
                    <div class="dipma-security-card-head">
                        <div><span class="dipma-eyebrow"><?php esc_html_e('IDENTITY PROTECTION', 'delicat-google-login'); ?></span><h3><?php echo esc_html($security['level']); ?></h3></div>
                        <span class="dipma-security-score"><b><?php echo esc_html($security['score']); ?></b><small>/100</small></span>
                    </div>
                    <div class="dipma-security-meter dipma-score-<?php echo esc_attr($security['score']); ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($security['score']); ?>"><span data-score="<?php echo esc_attr($security['score']); ?>"></span></div>
                    <p><?php echo esc_html($security['recommendation']); ?></p>
                    <div class="dipma-security-checks">
                        <?php self::status_chip($security['email_verified'], __('Email', 'delicat-google-login'), 'dipma-icon-mail'); ?>
                        <?php self::status_chip($security['two_factor'], __('2FA', 'delicat-google-login'), 'dipma-icon-shield'); ?>
                        <?php self::status_chip($security['providers'] > 0, __('Connexion liée', 'delicat-google-login'), 'dipma-icon-link'); ?>
                        <?php self::status_chip($security['trusted'] > 0, __('Appareil fiable', 'delicat-google-login'), 'dipma-icon-device'); ?>
                        <?php self::status_chip($security['https'], __('HTTPS', 'delicat-google-login'), 'dipma-icon-lock'); ?>
                    </div>
                    <a class="dipma-security-cta" href="<?php echo esc_url($security_url); ?>"><i class="dipma-icon dipma-icon-shield" aria-hidden="true"></i><span><?php esc_html_e('Gérer ma sécurité', 'delicat-google-login'); ?></span><i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i></a>
                </section>
            </div>

            <section class="dipma-recent">
                <div class="dipma-section-head"><div><span class="dipma-eyebrow"><?php esc_html_e('ACTIVITÉ', 'delicat-google-login'); ?></span><h3><?php esc_html_e('Commandes récentes', 'delicat-google-login'); ?></h3><p><?php esc_html_e('Vos trois derniers achats', 'delicat-google-login'); ?></p></div><a href="<?php echo esc_url($orders_url); ?>"><?php esc_html_e('Tout voir', 'delicat-google-login'); ?><i class="dipma-icon dipma-icon-chevron" aria-hidden="true"></i></a></div>
                <?php if (!empty($recent)) : foreach ($recent as $order) :
                    if (!$order instanceof WC_Order || (int) $order->get_customer_id() !== $uid) continue;
                    $product_name = __('Commande Delicat Store', 'delicat-google-login');
                    $thumb = '<span class="dipma-product-fallback">◆</span>';
                    foreach ($order->get_items() as $item) {
                        $product_name = $item->get_name();
                        $product = $item->get_product();
                        if ($product && $product->get_image_id()) {
                            $thumb = wp_get_attachment_image($product->get_image_id(), [64,64], false, ['loading'=>'lazy','decoding'=>'async']);
                        }
                        break;
                    }
                    $status = sanitize_html_class($order->get_status());
                    $created = $order->get_date_created();
                    $date_label = $created ? wc_format_datetime($created, 'j M Y') : '';
                    ?>
                    <a class="dipma-order" href="<?php echo esc_url($order->get_view_order_url()); ?>">
                        <span class="dipma-order-thumb"><?php echo wp_kses_post($thumb); ?></span>
                        <span class="dipma-order-info"><strong><?php echo esc_html($product_name); ?></strong><small>#<?php echo esc_html($order->get_order_number()); ?> · <?php echo esc_html($date_label); ?></small></span>
                        <span class="dipma-order-right"><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong><mark class="status-<?php echo esc_attr($status); ?>"><i></i><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></mark></span>
                        <i class="dipma-icon dipma-icon-chevron dipma-chevron" aria-hidden="true"></i>
                    </a>
                <?php endforeach; else : ?>
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
        <div class="dipma-orders-toolbar">
            <label><i class="dipma-icon dipma-icon-search" aria-hidden="true"></i><input id="dipma-order-search" type="search" autocomplete="off" inputmode="search" placeholder="<?php echo esc_attr__('Rechercher une commande…', 'delicat-google-login'); ?>" aria-label="<?php echo esc_attr__('Rechercher une commande', 'delicat-google-login'); ?>"><button type="button" data-dipma-search-clear aria-label="<?php echo esc_attr__('Effacer la recherche', 'delicat-google-login'); ?>">×</button></label>
            <div data-dipma-search-empty hidden><?php esc_html_e('Aucune commande correspondante.', 'delicat-google-login'); ?></div>
        </div>
        <?php
    }

    public static function redirect_legacy_identity_endpoint() {
        if (!is_user_logged_in() || !function_exists('is_account_page') || !is_account_page() || !function_exists('is_wc_endpoint_url')) return;
        if (!class_exists('DIP_WooCommerce') || !is_wc_endpoint_url(DIP_WooCommerce::ENDPOINT)) return;
        $target = add_query_arg('dip_tab', 'accounts', self::account_url('identity-center'));
        wp_safe_redirect($target, 302, 'Delicat Identity');
        exit;
    }
}
