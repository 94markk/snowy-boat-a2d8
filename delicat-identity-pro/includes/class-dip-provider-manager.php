<?php
defined('ABSPATH') || exit;

/**
 * Provider Control Center.
 * Keeps provider diagnostics and ordering separate from the OAuth callback engine.
 */
final class DIP_Provider_Manager {
    const PAGE = 'delicat-identity-providers';
    const TEST_TRANSIENT = 'dip_provider_test_';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 30);
        add_action('admin_post_dip_provider_save', [__CLASS__, 'save']);
        add_action('admin_post_dip_provider_test', [__CLASS__, 'test']);
    }

    public static function menu() {
        add_options_page(
            __('Identity Providers', 'delicat-google-login'),
            __('Identity Providers', 'delicat-google-login'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render']
        );
    }

    public static function providers(array $settings) {
        $callback = home_url('/?dip_action=callback');
        $providers = [
            'google' => [
                'label' => 'Google',
                'enabled' => ($settings['enabled'] ?? 'no') === 'yes',
                'configured' => !empty($settings['client_id']) && !empty($settings['client_secret']),
                'client_id' => (string) ($settings['client_id'] ?? ''),
                'callback' => $callback,
                'discovery' => 'https://accounts.google.com/.well-known/openid-configuration',
            ],
            'microsoft' => [
                'label' => 'Microsoft',
                'enabled' => ($settings['microsoft_enabled'] ?? 'no') === 'yes',
                'configured' => !empty($settings['microsoft_client_id']) && !empty($settings['microsoft_client_secret']),
                'client_id' => (string) ($settings['microsoft_client_id'] ?? ''),
                'callback' => $callback,
                'discovery' => 'https://login.microsoftonline.com/' . rawurlencode($settings['microsoft_tenant'] ?? 'common') . '/v2.0/.well-known/openid-configuration',
            ],
        ];
        return apply_filters('dip_provider_manager_providers', $providers, $settings);
    }

    private static function settings() {
        $raw = wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
        $raw['client_secret'] = DIP_Crypto::decrypt($raw['client_secret'] ?? '');
        $raw['microsoft_client_secret'] = DIP_Crypto::decrypt($raw['microsoft_client_secret'] ?? '');
        return $raw;
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Permission refusée.', 'delicat-google-login'), 403);
        check_admin_referer('dip_provider_save');
        $raw = wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
        $raw['enabled'] = !empty($_POST['google_enabled']) ? 'yes' : 'no';
        $raw['microsoft_enabled'] = !empty($_POST['microsoft_enabled']) ? 'yes' : 'no';
        $order = array_values(array_unique(array_intersect(
            array_map('sanitize_key', (array) ($_POST['provider_order'] ?? [])),
            ['google', 'microsoft']
        )));
        $raw['provider_order'] = implode(',', $order ?: ['google', 'microsoft']);
        update_option(DIP_Plugin::OPTION, $raw, false);
        if (class_exists('DIP_Audit')) DIP_Audit::record('provider_settings_updated', 'info', get_current_user_id(), ['order' => $raw['provider_order']]);
        wp_safe_redirect(add_query_arg('dip_saved', '1', admin_url('options-general.php?page=' . self::PAGE)));
        exit;
    }

    public static function test() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Permission refusée.', 'delicat-google-login'), 403);
        $provider = sanitize_key(wp_unslash($_GET['provider'] ?? ''));
        check_admin_referer('dip_provider_test_' . $provider);
        $settings = self::settings();
        $providers = self::providers($settings);
        if (!isset($providers[$provider])) wp_die(esc_html__('Fournisseur invalide.', 'delicat-google-login'), 400);

        $item = $providers[$provider];
        $result = [
            'provider' => $provider,
            'checked_at' => time(),
            'ok' => false,
            'message' => '',
        ];
        if (!is_ssl()) {
            $result['message'] = 'HTTPS est requis pour OAuth.';
        } elseif (!$item['configured']) {
            $result['message'] = 'Client ID ou secret manquant.';
        } else {
            $response = wp_safe_remote_get($item['discovery'], ['timeout' => 12, 'redirection' => 2]);
            if (is_wp_error($response)) {
                $result['message'] = 'Connexion au fournisseur impossible : ' . $response->get_error_message();
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                $body = json_decode((string) wp_remote_retrieve_body($response), true);
                if ($code === 200 && is_array($body) && !empty($body['authorization_endpoint']) && !empty($body['token_endpoint'])) {
                    $result['ok'] = true;
                    $result['message'] = 'Découverte OpenID disponible et configuration locale complète.';
                } else {
                    $result['message'] = 'Le fournisseur a répondu, mais sa configuration OpenID est invalide.';
                }
            }
        }
        set_transient(self::TEST_TRANSIENT . get_current_user_id() . '_' . $provider, $result, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['dip_test' => $provider], admin_url('options-general.php?page=' . self::PAGE)));
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $settings = self::settings();
        $providers = self::providers($settings);
        $order = array_values(array_filter(array_map('sanitize_key', explode(',', (string) ($settings['provider_order'] ?? 'google,microsoft')))));
        foreach (array_keys($providers) as $id) if (!in_array($id, $order, true)) $order[] = $id;
        ?>
        <div class="wrap dip-admin-wrap">
            <section class="dip-admin-hero">
                <div><span class="dip-eyebrow">PHASE 2 — PROVIDER CONTROL CENTER</span><h1>Fournisseurs d’identité</h1><p>Activez, testez et ordonnez les connexions sociales sans modifier le moteur OAuth.</p></div>
                <div class="dip-hero-score"><small>Fournisseurs prêts</small><strong><?php echo esc_html((string) count(array_filter($providers, static function ($p) { return !empty($p['enabled']) && !empty($p['configured']); }))); ?></strong><span>sur <?php echo esc_html((string) count($providers)); ?></span></div>
            </section>
            <?php if (!empty($_GET['dip_saved'])): ?><div class="notice notice-success is-dismissible"><p>Configuration des fournisseurs enregistrée.</p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="dip_provider_save">
                <?php wp_nonce_field('dip_provider_save'); ?>
                <section class="dip-quick-grid">
                <?php foreach ($order as $id): if (empty($providers[$id])) continue; $p = $providers[$id];
                    $test = get_transient(self::TEST_TRANSIENT . get_current_user_id() . '_' . $id); ?>
                    <article class="dip-quick-card">
                        <span class="dip-status-dot <?php echo $p['enabled'] && $p['configured'] && is_ssl() ? 'is-good' : ($p['enabled'] ? 'is-warn' : 'is-info'); ?>"></span>
                        <div style="width:100%">
                            <small><?php echo esc_html(strtoupper($id)); ?></small>
                            <strong><?php echo esc_html($p['label']); ?></strong>
                            <p><?php echo $p['configured'] ? 'Identifiants configurés.' : 'Identifiants incomplets.'; ?></p>
                            <label><input type="checkbox" name="<?php echo esc_attr($id); ?>_enabled" value="1" <?php checked($p['enabled']); ?>> Activer</label>
                            <input type="hidden" name="provider_order[]" value="<?php echo esc_attr($id); ?>">
                            <p><code><?php echo esc_html($p['callback']); ?></code></p>
                            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'dip_provider_test', 'provider' => $id], admin_url('admin-post.php')), 'dip_provider_test_' . $id)); ?>">Tester <?php echo esc_html($p['label']); ?></a></p>
                            <?php if (is_array($test)): ?><p><strong><?php echo !empty($test['ok']) ? '✓' : '⚠'; ?></strong> <?php echo esc_html($test['message']); ?></p><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </section>
                <p class="description">L’ordre affiché ci-dessus est utilisé par les shortcodes sociaux. Les fournisseurs désactivés restent configurés mais ne sont plus proposés aux clients.</p>
                <p><button type="submit" class="button button-primary">Enregistrer les fournisseurs</button> <a class="button" href="<?php echo esc_url(admin_url('options-general.php?page=delicat-identity')); ?>">Réglages avancés</a></p>
            </form>
        </div>
        <?php
    }
}
