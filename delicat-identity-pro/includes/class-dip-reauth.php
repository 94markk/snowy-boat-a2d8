<?php
defined('ABSPATH') || exit;

/**
 * Session-bound recent-authentication gate for sensitive account operations.
 *
 * A successful login counts as recent authentication for a short period.
 * Later, the customer can explicitly re-confirm using their password or TOTP.
 * The marker is bound to the current WordPress session token, so it does not
 * authorize a different browser/session.
 */
final class DIP_Reauth {
    const NONCE_ACTION = 'dip_reauth_confirm';
    const DEFAULT_WINDOW = 600; // 10 minutes.

    public static function init() {
        add_action('dip_login_success', [__CLASS__, 'login_success'], 100, 2);
        add_action('wp_login', [__CLASS__, 'wp_login'], 100, 2);
        add_action('admin_post_dip_reauth_confirm', [__CLASS__, 'handle_confirm']);
        add_filter('woocommerce_save_account_details_errors', [__CLASS__, 'protect_account_changes'], 50, 2);
    }

    private static function settings() {
        $defaults = class_exists('DIP_Plugin') ? DIP_Plugin::defaults() : [];
        return wp_parse_args((array) get_option(class_exists('DIP_Plugin') ? DIP_Plugin::OPTION : 'dglp_settings', []), $defaults);
    }

    public static function window() {
        $s = self::settings();
        $minutes = absint($s['sensitive_reauth_minutes'] ?? 10);
        $minutes = min(60, max(5, $minutes));
        return $minutes * MINUTE_IN_SECONDS;
    }

    private static function session_token() {
        $token = function_exists('wp_get_session_token') ? (string) wp_get_session_token() : '';
        return $token;
    }

    private static function key($user_id) {
        $user_id = absint($user_id);
        $token = self::session_token();
        if (!$user_id || $token === '') return '';
        return 'dip_reauth_' . substr(hash_hmac('sha256', $user_id . '|' . $token, wp_salt('secure_auth')), 0, 40);
    }

    public static function mark_recent($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        $key = self::key($user_id);
        if (!$key) return false;
        return set_transient($key, time(), self::window());
    }

    public static function clear($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        $key = self::key($user_id);
        if ($key) delete_transient($key);
    }

    public static function is_recent($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id || !is_user_logged_in() || $user_id !== get_current_user_id()) return false;
        $key = self::key($user_id);
        if (!$key) return false;
        $at = (int) get_transient($key);
        return $at > 0 && (time() - $at) <= self::window();
    }

    public static function seconds_remaining($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        $key = self::key($user_id);
        if (!$key) return 0;
        $at = (int) get_transient($key);
        if (!$at) return 0;
        return max(0, self::window() - (time() - $at));
    }

    public static function login_success($user_id, $context = []) {
        self::mark_recent(absint($user_id));
    }

    public static function wp_login($user_login, $user) {
        if ($user instanceof WP_User) self::mark_recent($user->ID);
    }

    private static function rate_key($user_id) {
        $ip = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::client_ip() : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        return 'dip_reauth_rate_' . substr(hash_hmac('sha256', absint($user_id) . '|' . $ip, wp_salt('nonce')), 0, 40);
    }

    private static function rate_allowed($user_id) {
        $key = self::rate_key($user_id);
        $state = get_transient($key);
        if (!is_array($state) || empty($state['reset']) || (int) $state['reset'] <= time()) {
            $state = ['count'=>0, 'reset'=>time() + 10 * MINUTE_IN_SECONDS];
        }
        if ((int) ($state['count'] ?? 0) >= 7) return false;
        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        set_transient($key, $state, max(1, (int) $state['reset'] - time()));
        return true;
    }

    private static function redirect_target($status = '') {
        $fallback = home_url('/my-account/');
        if (class_exists('DIP_Customer_Dashboard') && function_exists('wc_get_account_endpoint_url')) {
            $fallback = wc_get_account_endpoint_url(DIP_Customer_Dashboard::ENDPOINT);
            $fallback = add_query_arg('dip_tab', 'security', $fallback);
        }
        $ref = wp_get_referer();
        $target = $ref ? wp_validate_redirect($ref, $fallback) : $fallback;
        if ($status !== '') $target = add_query_arg('dip_reauth_status', sanitize_key($status), $target);
        return $target;
    }

    public static function require_recent_or_redirect($message = '') {
        if (self::is_recent()) return true;
        if (!is_user_logged_in()) {
            if (class_exists('DIP_Access_Guard')) DIP_Access_Guard::require_login();
            auth_redirect();
        }
        if (class_exists('DIP_Audit')) DIP_Audit::record('sensitive_action_reauth_required', 'notice', get_current_user_id());
        $target = self::redirect_target('required');
        wp_safe_redirect($target);
        exit;
    }

    public static function handle_confirm() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response'=>405]);
        }
        if (class_exists('DIP_Access_Guard')) DIP_Access_Guard::require_self_service();
        elseif (!is_user_logged_in()) auth_redirect();
        check_admin_referer(self::NONCE_ACTION);

        $user_id = get_current_user_id();
        if (!self::rate_allowed($user_id)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('reauth_rate_limited', 'warning', $user_id);
            wp_safe_redirect(self::redirect_target('rate_limited'));
            exit;
        }

        $user = get_userdata($user_id);
        $password = (string) wp_unslash($_POST['current_password'] ?? '');
        $factor = sanitize_text_field(wp_unslash($_POST['factor_code'] ?? ''));
        $ok = false;
        $method = '';

        if ($factor !== '' && class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user_id)) {
            if (!DIP_Two_Factor::factor_attempt_allowed($user_id)) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('reauth_two_factor_rate_limited', 'warning', $user_id);
                wp_safe_redirect(self::redirect_target('rate_limited'));
                exit;
            }
            $ok = DIP_Two_Factor::verify_factor($user_id, $factor, true);
            if ($ok) DIP_Two_Factor::clear_factor_rate($user_id);
            $method = $ok ? 'totp_or_recovery' : '';
        }
        if (!$ok && $password !== '' && $user instanceof WP_User) {
            $ok = wp_check_password($password, $user->user_pass, $user_id);
            $method = $ok ? 'password' : '';
        }

        if (!$ok) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('reauth_failed', 'warning', $user_id);
            wp_safe_redirect(self::redirect_target('failed'));
            exit;
        }

        self::mark_recent($user_id);
        delete_transient(self::rate_key($user_id));
        if (class_exists('DIP_Audit')) DIP_Audit::record('reauth_success', 'info', $user_id, ['method'=>$method]);
        wp_safe_redirect(self::redirect_target('success'));
        exit;
    }

    /**
     * Protect email/password changes on Woo My Account. Basic name/profile edits
     * remain convenient; identity-changing fields require recent authentication.
     */
    public static function protect_account_changes($errors, $user) {
        if (!($errors instanceof WP_Error) || !($user instanceof WP_User) || !is_user_logged_in() || get_current_user_id() !== (int) $user->ID) return $errors;
        $current = get_userdata($user->ID);
        if (!$current) return $errors;

        $posted_email = isset($_POST['account_email']) ? sanitize_email(wp_unslash($_POST['account_email'])) : $current->user_email;
        $pass1 = (string) wp_unslash($_POST['password_1'] ?? '');
        $pass2 = (string) wp_unslash($_POST['password_2'] ?? '');
        $email_change = $posted_email && strtolower($posted_email) !== strtolower((string) $current->user_email);
        $password_change = $pass1 !== '' || $pass2 !== '';

        if (($email_change || $password_change) && !self::is_recent($user->ID)) {
            $errors->add('dip_reauth_required', __('Pour modifier votre e-mail ou votre mot de passe, confirmez d’abord votre identité dans l’onglet Sécurité puis réessayez.', 'delicat-google-login'));
            if (class_exists('DIP_Audit')) DIP_Audit::record('profile_sensitive_change_blocked_reauth', 'notice', $user->ID, ['email_change'=>$email_change?1:0,'password_change'=>$password_change?1:0]);
        }
        return $errors;
    }

    public static function render_form() {
        if (!is_user_logged_in()) return '';
        $recent = self::is_recent();
        $status = sanitize_key(wp_unslash($_GET['dip_reauth_status'] ?? ''));
        $messages = [
            'required' => __('Confirmez votre identité avant cette action sensible.', 'delicat-google-login'),
            'failed' => __('La confirmation a échoué. Vérifiez vos informations.', 'delicat-google-login'),
            'rate_limited' => __('Trop de tentatives. Réessayez dans quelques minutes.', 'delicat-google-login'),
            'success' => __('Identité confirmée. Les actions sensibles sont autorisées temporairement.', 'delicat-google-login'),
        ];
        ob_start();
        ?>
        <div class="dip-security-card dip-reauth-card">
          <div class="dip-security-card-title"><span class="dip-security-card-icon" aria-hidden="true">↻</span><div><h3><?php esc_html_e('Confirmation d’identité', 'delicat-google-login'); ?></h3><p><?php echo $recent ? esc_html(sprintf(__('Confirmation active pour encore environ %d minutes.', 'delicat-google-login'), max(1, (int) ceil(self::seconds_remaining()/60)))) : esc_html__('Requise avant les actions sensibles comme désactiver 2FA, modifier l’e-mail ou révoquer des sessions.', 'delicat-google-login'); ?></p></div></div>
          <?php if (isset($messages[$status])): ?><div class="dip-security-notice" role="status"><?php echo esc_html($messages[$status]); ?></div><?php endif; ?>
          <?php if (!$recent): ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dip-reauth-form" autocomplete="off">
            <input type="hidden" name="action" value="dip_reauth_confirm">
            <?php wp_nonce_field(self::NONCE_ACTION); ?>
            <label><span><?php esc_html_e('Mot de passe actuel', 'delicat-google-login'); ?></span><input type="password" name="current_password" autocomplete="current-password"></label>
            <?php if (class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled(get_current_user_id())): ?>
            <div class="dip-reauth-or"><?php esc_html_e('ou', 'delicat-google-login'); ?></div>
            <label><span><?php esc_html_e('Code 2FA ou code de récupération', 'delicat-google-login'); ?></span><input type="text" name="factor_code" autocomplete="one-time-code" inputmode="text" maxlength="32"></label>
            <?php endif; ?>
            <button type="submit" class="dip-btn primary"><?php esc_html_e('Confirmer mon identité', 'delicat-google-login'); ?></button>
          </form>
          <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
