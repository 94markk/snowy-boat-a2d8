<?php
defined('ABSPATH') || exit;

/**
 * Connected account management for Delicat Identity Pro.
 * Keeps provider unlinking isolated from the OAuth callback engine.
 */
final class DIP_Connected_Accounts {
    const NONCE_ACTION = 'dip_disconnect_provider';

    public static function init() {
        add_shortcode('delicat_connected_accounts', [__CLASS__, 'shortcode']);
        add_action('admin_post_dip_disconnect_provider', [__CLASS__, 'disconnect']);
        add_action('wp_ajax_dip_connected_accounts_status', [__CLASS__, 'ajax_status']);
    }

    public static function providers() {
        $providers = [
            'google' => [
                'label' => 'Google',
                'meta_sub' => DIP_Identity::META_SUB,
                'meta_linked' => DIP_Identity::META_LINKED_AT,
            ],
            'microsoft' => [
                'label' => 'Microsoft',
                'meta_sub' => '_dip_microsoft_sub',
                'meta_linked' => '_dip_microsoft_linked_at',
            ],
        ];
        return apply_filters('dip_connected_account_providers', $providers);
    }

    public static function status($user_id) {
        $user_id = absint($user_id);
        $result = [];
        foreach (self::providers() as $id => $provider) {
            $sub = (string) get_user_meta($user_id, $provider['meta_sub'], true);
            $linked_at = absint(get_user_meta($user_id, $provider['meta_linked'], true));
            $result[$id] = [
                'id' => sanitize_key($id),
                'label' => sanitize_text_field($provider['label']),
                'connected' => $sub !== '',
                'linked_at' => $linked_at,
            ];
        }
        return $result;
    }

    public static function shortcode($atts = []) {
        if (class_exists('DIP_Access_Guard') ? !DIP_Access_Guard::can_self_service() : !is_user_logged_in()) return '<p class="dip-connected-notice">Vous devez être connecté pour gérer vos comptes associés.</p>';
        $atts = shortcode_atts(['title' => 'Comptes connectés'], $atts, 'delicat_connected_accounts');
        $user_id = get_current_user_id();
        $settings = wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
        $statuses = self::status($user_id);
        $status = sanitize_key(wp_unslash($_GET['dip_account_status'] ?? ''));
        $notice = '';
        if ($status === 'disconnected') $notice = '<p class="dip-connected-notice is-success">Le fournisseur a été déconnecté avec succès.</p>';
        if ($status === 'blocked') $notice = '<p class="dip-connected-notice is-warning">Déconnexion refusée : ajoutez un mot de passe utilisable ou connectez un autre fournisseur avant de continuer.</p>';
        $html = '<section class="dip-connected-accounts">' . $notice . '<div class="dip-connected-head"><h3>' . esc_html($atts['title']) . '</h3><p>Reliez plusieurs fournisseurs au même compte sans créer de doublons.</p></div><div class="dip-connected-list">';
        foreach ($statuses as $id => $item) {
            $enabled = self::provider_enabled($id, $settings);
            $html .= '<article class="dip-connected-row ' . ($item['connected'] ? 'is-connected' : 'is-disconnected') . '">';
            $html .= '<span class="dip-provider-mark" aria-hidden="true">' . esc_html(strtoupper(substr($item['label'], 0, 1))) . '</span>';
            $html .= '<div class="dip-connected-copy"><strong>' . esc_html($item['label']) . '</strong>';
            $html .= '<small>' . ($item['connected'] ? 'Connecté' : ($enabled ? 'Non connecté' : 'Désactivé par l’administrateur')) . '</small></div>';
            if ($item['connected']) {
                $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dip-provider-inline-form" data-dip-confirm="Déconnecter ' . esc_attr($item['label']) . ' ?">';
                $html .= '<input type="hidden" name="action" value="dip_disconnect_provider"><input type="hidden" name="provider" value="' . esc_attr($id) . '">';
                $html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION . '_' . $id)) . '">';
                $html .= '<button type="submit" class="dip-provider-action is-danger">Déconnecter</button></form>';
            } elseif ($enabled) {
                $redirect = self::current_url();
                $url = add_query_arg(['dip_action' => 'login', 'provider' => $id, 'link' => 1, '_dip_nonce' => wp_create_nonce('dip_link_' . $user_id), 'redirect' => rawurlencode($redirect)], home_url('/'));
                $html .= '<a class="dip-provider-action" href="' . esc_url($url) . '">Connecter</a>';
            }
            $html .= '</article>';
        }
        $html .= '</div></section><script>document.addEventListener("submit",function(e){var f=e.target.closest("[data-dip-confirm]");if(f&&!window.confirm(f.getAttribute("data-dip-confirm"))){e.preventDefault();}});</script>';
        return $html;
    }

    public static function disconnect() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response' => 405]);
        }
        if (class_exists('DIP_Access_Guard')) { if (!DIP_Access_Guard::can_self_service()) DIP_Access_Guard::require_login(); } elseif (!is_user_logged_in()) auth_redirect();
        $provider = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
        check_admin_referer(self::NONCE_ACTION . '_' . $provider);
        $providers = self::providers();
        if (!isset($providers[$provider])) wp_die(esc_html__('Fournisseur invalide.', 'delicat-google-login'), 400);

        $user_id = get_current_user_id();
        if (!self::can_disconnect($user_id, $provider)) {
            self::redirect_with_status('blocked');
        }
        delete_user_meta($user_id, $providers[$provider]['meta_sub']);
        delete_user_meta($user_id, $providers[$provider]['meta_linked']);
        if ($provider === 'google') { delete_user_meta($user_id, DIP_Identity::META_AVATAR); delete_user_meta($user_id, '_dip_google_explicit_link_v2'); }
        do_action('dip_account_disconnected', $user_id, $provider);
        if (class_exists('DIP_Audit')) DIP_Audit::record('account_disconnected', 'info', $user_id, ['provider' => $provider]);
        self::redirect_with_status('disconnected');
    }

    private static function can_disconnect($user_id, $provider) {
        $user = get_userdata($user_id);
        if (!$user) return false;
        $password_managed = get_user_meta($user_id, DIP_Identity::META_PASSWORD_MANAGED, true) === 'yes';
        if (!$password_managed) return true;
        $connected = 0;
        foreach (self::status($user_id) as $id => $item) {
            if ($item['connected'] && $id !== $provider) $connected++;
        }
        return $connected > 0;
    }

    private static function provider_enabled($provider, array $settings) {
        if ($provider === 'google') return ($settings['enabled'] ?? 'no') === 'yes';
        return ($settings[$provider . '_enabled'] ?? 'no') === 'yes';
    }

    private static function current_url() {
        $uri = wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        $path = wp_parse_url($uri, PHP_URL_PATH);
        $query = wp_parse_url($uri, PHP_URL_QUERY);
        $url = home_url($path ?: '/');
        return esc_url_raw($query ? $url . '?' . $query : $url);
    }

    private static function redirect_with_status($status) {
        $referer = wp_get_referer();
        $target = $referer ? $referer : home_url('/my-account/');
        wp_safe_redirect(add_query_arg('dip_account_status', sanitize_key($status), $target));
        exit;
    }

    public static function ajax_status() {
        if (class_exists('DIP_Access_Guard') ? !DIP_Access_Guard::can_self_service() : !is_user_logged_in()) wp_send_json_error(['message' => 'unauthorized'], 401);
        wp_send_json_success(['providers' => self::status(get_current_user_id())]);
    }
}
