<?php
defined('ABSPATH') || exit;

/**
 * Central notification service for Delicat Identity.
 *
 * All Identity security/access emails use the same embedded Email Studio
 * renderer as WordPress/WooCommerce messages. No authentication secret,
 * OTP, token or magic URL is persisted or written to the audit log here.
 */
final class DIP_Email_Hub {
    public static function init() {
        add_action('after_password_reset', [__CLASS__, 'password_changed'], 20, 2);
        add_action('dip_account_linked', [__CLASS__, 'provider_connected'], 30, 2);
        add_action('dip_account_disconnected', [__CLASS__, 'provider_disconnected'], 30, 2);
        add_filter('retrieve_password_notification_email', [__CLASS__, 'wordpress_password_reset_email'], 99, 4);
    }

    public static function security_url() {
        if (class_exists('DIP_Customer_Dashboard') && function_exists('wc_get_account_endpoint_url')) {
            return add_query_arg('dip_tab', 'security', wc_get_account_endpoint_url(DIP_Customer_Dashboard::ENDPOINT));
        }
        if (function_exists('wc_get_page_permalink')) return wc_get_page_permalink('myaccount');
        return home_url('/my-account/');
    }

    private static function studio_ready() {
        return class_exists('DIP_Email_Studio_Bridge') && DIP_Email_Studio_Bridge::load() && function_exists('dipes_email_css');
    }

    private static function user_context(WP_User $user, array $extra = []) {
        $first = trim((string)get_user_meta($user->ID, 'first_name', true));
        $last  = trim((string)get_user_meta($user->ID, 'last_name', true));
        $display = trim((string)$user->display_name);
        $base = [
            'first_name' => $first ?: ($display ?: $user->user_login),
            'last_name' => $last,
            'full_name' => trim($first . ' ' . $last) ?: ($display ?: $user->user_login),
            'display_name' => $display ?: $user->user_login,
            'username' => $user->user_login,
            'user_email' => $user->user_email,
            'user_id' => (string)$user->ID,
            'security_url' => self::security_url(),
            'admin_identity_url' => admin_url('admin.php?page=dip-ui-stability'),
        ];
        return function_exists('dipes_context') ? dipes_context(null, null, array_merge($base, $extra)) : array_merge($base, $extra);
    }

    private static function fallback_html($title, $message_html, $cta_label = '', $cta_url = '') {
        $cta = '';
        if ($cta_label && $cta_url) {
            $cta = '<p style="margin:24px 0 0;text-align:center"><a href="' . esc_url($cta_url) . '" style="display:inline-block;padding:14px 22px;border-radius:12px;background:#123b72;color:#fff;text-decoration:none;font-weight:700">' . esc_html($cta_label) . '</a></p>';
        }
        return '<!doctype html><html><body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;background:#f3f6fb;padding:24px"><div style="max-width:620px;margin:auto;background:#fff;padding:28px;border-radius:18px"><h1 style="font-size:24px">' . esc_html($title) . '</h1><div style="font-size:15px;line-height:1.7">' . wp_kses_post($message_html) . '</div>' . $cta . '</div></body></html>';
    }

    private static function render($email_id, array $ctx, $title, $message_html, array $args = []) {
        $cta_label = sanitize_text_field($args['cta_label'] ?? '');
        $cta_url = esc_url_raw($args['cta_url'] ?? '');
        if (!self::studio_ready()) return self::fallback_html($title, $message_html, $cta_label, $cta_url);

        $overrides = [
            'heading' => sanitize_text_field($title),
            'preheader' => sanitize_text_field($args['preheader'] ?? wp_strip_all_tags($title)),
            'badge' => sanitize_text_field($args['badge'] ?? 'Sécurité'),
            'accent' => sanitize_hex_color($args['accent'] ?? '') ?: ('identity_admin_alert' === $email_id ? '#d97706' : '#dc2626'),
            'show_badge' => 'yes',
            'show_cta' => ($cta_label && $cta_url) ? 'yes' : 'no',
            'cta_label' => $cta_label,
            'cta_url' => $cta_url,
        ];

        $old_current = $GLOBALS['dipes_current_email_id'] ?? null;
        $old_render = !empty($GLOBALS['dipes_rendering_email']);
        $old_preview = !empty($GLOBALS['dipes_preview_mode']);
        $old_overrides = $GLOBALS['dipes_runtime_email_overrides'][$email_id] ?? null;

        $GLOBALS['dipes_runtime_email_overrides'][$email_id] = $overrides;
        $GLOBALS['dipes_current_email_id'] = $email_id;
        $GLOBALS['dipes_rendering_email'] = true;
        $GLOBALS['dipes_preview_mode'] = true;

        ob_start();
        $email = null;
        $email_heading = $title;
        include DIPES_DIR . 'templates/emails/email-header.php';
        echo '<div class="desp-notice desp-identity-message"><div class="desp-notice-label">' . esc_html($args['label'] ?? 'Delicat Identity') . '</div><div class="desp-notice-content">' . wp_kses_post($message_html) . '</div></div>';
        if ($cta_label && $cta_url && function_exists('dipes_render_cta')) {
            $ctx['action_url'] = $cta_url;
            dipes_render_cta($email_id, $ctx);
        }
        include DIPES_DIR . 'templates/emails/email-footer.php';
        $html = ob_get_clean();

        if ($old_overrides === null) unset($GLOBALS['dipes_runtime_email_overrides'][$email_id]);
        else $GLOBALS['dipes_runtime_email_overrides'][$email_id] = $old_overrides;
        if ($old_current === null) unset($GLOBALS['dipes_current_email_id']);
        else $GLOBALS['dipes_current_email_id'] = $old_current;
        if (!$old_render) unset($GLOBALS['dipes_rendering_email']);
        if (!$old_preview) unset($GLOBALS['dipes_preview_mode']);

        return $html;
    }

    private static function send($to, $email_id, $subject, $title, $message_html, array $args = [], $audit_user_id = 0) {
        $to = sanitize_email($to);
        if (!is_email($to)) return false;

        $user = $audit_user_id ? get_userdata(absint($audit_user_id)) : get_user_by('email', $to);
        $ctx = $user instanceof WP_User ? self::user_context($user, $args['context'] ?? []) : (function_exists('dipes_context') ? dipes_context(null, null, $args['context'] ?? []) : ($args['context'] ?? []));
        $html = self::render($email_id, $ctx, $title, $message_html, $args);
        $headers = function_exists('dipes_add_html_content_type_header') ? dipes_add_html_content_type_header([]) : ['Content-Type: text/html; charset=UTF-8'];
        $sent = (bool)wp_mail($to, wp_strip_all_tags((string)$subject), $html, $headers);

        if (class_exists('DIP_Audit')) {
            DIP_Audit::record($sent ? 'unified_email_sent' : 'unified_email_failed', $sent ? 'info' : 'warning', absint($audit_user_id), ['template'=>sanitize_key($email_id)]);
        }
        return $sent;
    }

    public static function send_user_security($user_id, $subject, $message, array $args = []) {
        $user = get_userdata(absint($user_id));
        if (!$user || !is_email($user->user_email)) return false;
        $title = sanitize_text_field($args['title'] ?? $subject);
        $defaults = [
            'badge' => 'Sécurité',
            'accent' => '#dc2626',
            'label' => 'Sécurité du compte',
            'cta_label' => 'Ouvrir le centre de sécurité',
            'cta_url' => self::security_url(),
        ];
        return self::send($user->user_email, 'identity_security_alert', $subject, $title, '<p>' . esc_html((string)$message) . '</p>' . (!empty($args['advice']) ? '<p>' . esc_html((string)$args['advice']) . '</p>' : ''), array_merge($defaults, $args), $user->ID);
    }

    public static function send_access_email($email, $subject, $title, $message_html, $cta_label = '', $cta_url = '', array $args = []) {
        $defaults = [
            'badge' => 'Vérification',
            'accent' => '#2563eb',
            'label' => 'Accès sécurisé',
            'cta_label' => $cta_label,
            'cta_url' => $cta_url,
        ];
        return self::send($email, 'identity_access_message', $subject, $title, $message_html, array_merge($defaults, $args));
    }

    public static function send_admin_alert($subject, $title, $message, $cta_url = '', $cta_label = 'Ouvrir Delicat Identity') {
        $admin = sanitize_email(get_option('admin_email'));
        if (!is_email($admin)) return false;
        return self::send($admin, 'identity_admin_alert', $subject, $title, '<p>' . esc_html((string)$message) . '</p>', [
            'badge'=>'Admin', 'accent'=>'#d97706', 'label'=>'Delicat Identity',
            'cta_label'=>$cta_url ? sanitize_text_field((string)$cta_label) : '', 'cta_url'=>$cta_url,
            'context'=>['admin_identity_url'=>admin_url('admin.php?page=dip-ui-stability')],
        ]);
    }


    public static function wordpress_password_reset_email($notification, $key, $user_login, $user_data) {
        if (!($user_data instanceof WP_User) || !self::studio_ready() || 'yes' !== dipes_email_setting('customer_reset_password', 'enabled', 'yes')) return $notification;
        $locale = get_user_locale($user_data);
        $reset_url = network_site_url('wp-login.php?login=' . rawurlencode($user_login) . '&key=' . rawurlencode($key) . '&action=rp', 'login');
        if ($locale) $reset_url = add_query_arg('wp_lang', $locale, $reset_url);
        $ctx = self::user_context($user_data, ['reset_password_url'=>$reset_url, 'action_url'=>$reset_url]);
        $subject_tpl = dipes_email_setting('customer_reset_password', 'subject', 'Réinitialisez votre mot de passe — {site_name}');
        $heading = dipes_replace_tokens(dipes_email_setting('customer_reset_password', 'heading', 'Réinitialisation du mot de passe'), $ctx);
        $subject = dipes_replace_tokens($subject_tpl, $ctx);
        $body = '<p>Une demande de réinitialisation du mot de passe a été reçue pour votre compte <strong>' . esc_html($user_login) . '</strong>.</p><p>Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail. Aucun changement ne sera effectué sans utiliser le lien sécurisé.</p>';
        $notification['subject'] = wp_strip_all_tags($subject);
        $notification['message'] = self::render('customer_reset_password', $ctx, $heading, $body, ['badge'=>'Sécurité','accent'=>'#7c3aed','label'=>'Réinitialisation sécurisée','cta_label'=>'Créer un nouveau mot de passe','cta_url'=>$reset_url]);
        $notification['headers'] = dipes_add_html_content_type_header($notification['headers'] ?? '');
        return $notification;
    }

    public static function password_changed($user, $new_pass = '') {
        if (!($user instanceof WP_User)) return;
        self::send_user_security($user->ID, 'Votre mot de passe a été modifié', 'Le mot de passe de votre compte Delicat a été modifié avec succès.', [
            'title'=>'Mot de passe mis à jour',
            'advice'=>'Si vous n’êtes pas à l’origine de cette action, sécurisez immédiatement votre compte et déconnectez les autres appareils.',
        ]);
    }

    public static function provider_connected($user_id, $provider = '') {
        $provider = ucfirst(sanitize_text_field((string)$provider));
        self::send_user_security($user_id, 'Compte ' . ($provider ?: 'social') . ' connecté', ($provider ?: 'Un compte social') . ' est maintenant connecté à votre compte Delicat.', [
            'title'=>'Nouveau compte connecté', 'accent'=>'#2563eb', 'badge'=>'Compte lié', 'label'=>'Comptes connectés',
        ]);
    }

    public static function provider_disconnected($user_id, $provider = '') {
        $provider = ucfirst(sanitize_text_field((string)$provider));
        self::send_user_security($user_id, 'Compte ' . ($provider ?: 'social') . ' déconnecté', ($provider ?: 'Un compte social') . ' a été retiré de votre compte Delicat.', [
            'title'=>'Compte déconnecté', 'accent'=>'#d97706', 'badge'=>'Compte retiré', 'label'=>'Comptes connectés',
        ]);
    }
}
