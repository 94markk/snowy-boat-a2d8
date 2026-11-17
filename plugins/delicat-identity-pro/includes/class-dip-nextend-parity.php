<?php
defined('ABSPATH') || exit;

/**
 * Feature-parity layer for the supported Delicat Identity providers.
 * It intentionally does not fake unsupported OAuth providers.
 */
final class DIP_Nextend_Parity {
    private static $settings_cb;

    public static function init(callable $settings_cb) {
        self::$settings_cb = $settings_cb;
        $s = self::settings();

        if (($s['auto_comments'] ?? 'no') === 'yes') {
            add_action('comment_form_top', [__CLASS__, 'render_comment_buttons']);
        }
        if (($s['auto_lost_password'] ?? 'yes') === 'yes') {
            add_action('lostpassword_form', [__CLASS__, 'render_lost_password_buttons']);
            add_action('woocommerce_lostpassword_form', [__CLASS__, 'render_lost_password_buttons']);
        }
        add_action('dip_login_success', [__CLASS__, 'remember_tracker'], 15, 2);
        add_action('dip_user_created', [__CLASS__, 'registration_notification'], 30, 2);
        add_action('show_user_profile', [__CLASS__, 'profile_summary']);
        add_action('edit_user_profile', [__CLASS__, 'profile_summary']);
        add_shortcode('delicat_social_login_buttons', [__CLASS__, 'buttons_shortcode']);
        add_shortcode('delicat_social_login_link', [__CLASS__, 'link_shortcode']);
    }

    private static function settings() {
        return is_callable(self::$settings_cb) ? (array) call_user_func(self::$settings_cb) : [];
    }

    public static function render_comment_buttons() {
        static $rendered = false;
        if ($rendered || is_user_logged_in()) return;
        $rendered = true;
        $buttons = DIP_Plugin::instance()->render_provider_buttons(['location' => 'comment']);
        if ($buttons === '') return;
        echo '<div class="dip-comment-social"><p><strong>' . esc_html__('Connectez-vous pour commenter', 'delicat-google-login') . '</strong></p>' . $buttons . '</div>';
    }

    public static function render_lost_password_buttons() {
        static $rendered = false;
        if ($rendered || is_user_logged_in()) return;
        $rendered = true;
        $redirect = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/');
        $buttons = DIP_Plugin::instance()->render_provider_buttons(['location' => 'lost_password', 'redirect' => $redirect]);
        if ($buttons !== '') echo '<div class="dip-lost-password-social">' . $buttons . '</div>';
    }

    public static function remember_tracker($user_id, $profile) {
        $tracker = isset($profile['_tracker']) ? sanitize_text_field((string) $profile['_tracker']) : '';
        if ($tracker !== '') {
            update_user_meta((int) $user_id, '_dip_last_tracker', substr($tracker, 0, 100));
        }
    }

    public static function registration_notification($user_id, $profile) {
        $s = self::settings();
        $mode = $s['registration_notification'] ?? 'admin';
        if (!in_array($mode, ['none','user','admin','both'], true)) $mode = 'admin';
        if ($mode === 'none') return;
        $dedupe_key = '_dip_registration_notice_' . sanitize_key($mode);
        $last_sent = absint(get_user_meta((int)$user_id, $dedupe_key, true));
        if ($last_sent && (time() - $last_sent) < 300) return;
        update_user_meta((int)$user_id, $dedupe_key, time());
        try {
            if ($mode === 'both') wp_new_user_notification((int)$user_id, null, 'both');
            elseif ($mode === 'user') wp_new_user_notification((int)$user_id, null, 'user');
            else wp_new_user_notification((int)$user_id, null, 'admin');
        } catch (Throwable $e) {
            delete_user_meta((int)$user_id, $dedupe_key);
            if (class_exists('DIP_Audit')) DIP_Audit::record('registration_notification_failed', 'warning', (int)$user_id);
        }
    }

    public static function profile_summary($user) {
        if (!($user instanceof WP_User)) return;
        $providers = [];
        if (get_user_meta($user->ID, '_dglp_google_sub', true)) $providers[] = 'Google';
        if (get_user_meta($user->ID, '_dip_microsoft_sub', true)) $providers[] = 'Microsoft';
        echo '<h2>' . esc_html__('Delicat Identity', 'delicat-google-login') . '</h2><table class="form-table"><tr><th>' . esc_html__('Comptes sociaux liés', 'delicat-google-login') . '</th><td>' . esc_html($providers ? implode(', ', $providers) : __('Aucun', 'delicat-google-login')) . '</td></tr></table>';
    }

    public static function buttons_shortcode($atts) {
        $a = shortcode_atts([
            'providers'=>'google,microsoft','redirect'=>'','layout'=>'wide','align'=>'stretch','trackerdata'=>'',
            'google_text'=>'','microsoft_text'=>'',
        ], $atts, 'delicat_social_login_buttons');
        $layout = in_array(sanitize_key($a['layout']), ['wide','row','icon'], true) ? sanitize_key($a['layout']) : 'wide';
        $align = in_array(sanitize_key($a['align']), ['left','center','right','stretch'], true) ? sanitize_key($a['align']) : 'stretch';
        $out = '<div class="dip-shortcode-buttons dip-layout-' . esc_attr($layout) . ' dip-shortcode-align-' . esc_attr($align) . '">';
        foreach (array_filter(array_map('sanitize_key', explode(',', $a['providers']))) as $provider) {
            if (!in_array($provider, ['google','microsoft'], true)) continue;
            $text_key = $provider . '_text';
            $args = ['provider'=>$provider,'redirect'=>$a['redirect'],'location'=>'shortcode','tracker'=>$a['trackerdata'],'suppress_divider'=>true,'layout'=>$layout,'align'=>$align];
            if (!empty($a[$text_key])) $args['text'] = sanitize_text_field($a[$text_key]);
            $out .= DIP_Plugin::instance()->render_button($args);
        }
        return $out . '</div>';
    }

    public static function link_shortcode($atts) {
        $a = shortcode_atts(['provider'=>'google','text'=>'Connexion','redirect'=>'','trackerdata'=>''], $atts, 'delicat_social_login_link');
        return DIP_Plugin::instance()->render_button(['provider'=>sanitize_key($a['provider']),'text'=>sanitize_text_field($a['text']),'redirect'=>$a['redirect'],'location'=>'link','tracker'=>$a['trackerdata']]);
    }

}
