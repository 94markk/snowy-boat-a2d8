<?php
defined('ABSPATH') || exit;

/**
 * Secure Google authentication policy for WordPress Administrator accounts.
 *
 * A privileged WordPress session must never be granted because a Google email
 * happens to match a local administrator email. Google admin login is allowed
 * only for an immutable Google `sub` that was explicitly approved while the
 * administrator was already authenticated, recently reauthenticated, and had
 * Delicat TOTP 2FA enabled. The normal social callback then still requires the
 * Delicat second-factor challenge before issuing any WordPress auth cookie.
 */
final class DIP_Privileged_Social {
    const META_GOOGLE_ADMIN_APPROVAL = '_dip_admin_google_secure_link_v1';
    const META_LEGACY_GOOGLE_CONTINUITY = '_dip_legacy_google_continuity_v1';
    const NONCE = 'dip_admin_google_secure_mode';

    private static $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;
        add_action('admin_post_dip_admin_google_revoke', [__CLASS__, 'revoke_current']);
        add_action('dip_account_disconnected', [__CLASS__, 'provider_disconnected'], 20, 2);
        add_action('dip_account_unlinked', [__CLASS__, 'provider_disconnected'], 20, 2);
        add_action('dip_two_factor_disabled', [__CLASS__, 'two_factor_disabled'], 20, 1);
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
    }

    private static function settings() {
        $defaults = class_exists('DIP_Plugin') ? DIP_Plugin::defaults() : [];
        return wp_parse_args((array)get_option(class_exists('DIP_Plugin') ? DIP_Plugin::OPTION : 'dglp_settings', []), $defaults);
    }

    public static function secure_mode_enabled() {
        $s = self::settings();
        return ($s['admin_google_secure_mode'] ?? 'yes') === 'yes';
    }

    public static function is_admin($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        return $user_id > 0 && user_can($user_id, 'manage_options');
    }

    private static function marker($user_id, $sub) {
        $user = get_userdata(absint($user_id));
        $roles = $user instanceof WP_User ? array_values(array_unique(array_map('sanitize_key', (array)$user->roles))) : [];
        sort($roles, SORT_STRING);
        $role_fingerprint = implode(',', $roles);
        return hash_hmac('sha256', absint($user_id) . '|google|' . (string)$sub . '|roles:' . $role_fingerprint . '|admin-secure-v1', wp_salt('auth'));
    }


    private static function legacy_marker($user_id, $sub) {
        $user = get_userdata(absint($user_id));
        if (!$user instanceof WP_User) return '';
        $roles = array_values(array_unique(array_map('sanitize_key', (array)$user->roles)));
        sort($roles, SORT_STRING);
        return hash_hmac('sha256', absint($user_id) . '|google|' . (string)$sub . '|roles:' . implode(',', $roles) . '|nextend-continuity-v1', wp_salt('auth'));
    }

    /**
     * Existing Nextend privileged Google links receive a narrow continuity
     * marker only after Identity has cryptographically verified Google and the
     * immutable `sub` maps back to the exact same WordPress user in Nextend.
     * No e-mail-only privileged auto-link is permitted.
     */
    public static function grant_legacy_continuity($user_id, $sub, array $profile = []) {
        $user_id = absint($user_id);
        $sub = sanitize_text_field((string)$sub);
        if (!$user_id || $sub === '' || !get_userdata($user_id)) return false;
        if (!class_exists('DIP_Migration') || !DIP_Migration::nextend_google_link_matches($user_id, $sub)) return false;
        if (!class_exists('DIP_Google') || !DIP_Google::email_is_authoritative($profile)) return false;
        $user = get_userdata($user_id);
        $local_email = $user ? strtolower(sanitize_email($user->user_email)) : '';
        $google_email = strtolower(sanitize_email($profile['email'] ?? ''));
        if ($local_email === '' || $google_email === '' || !hash_equals($local_email, $google_email)) return false;

        $marker = self::legacy_marker($user_id, $sub);
        if ($marker === '') return false;
        $previous = (string)get_user_meta($user_id, self::META_LEGACY_GOOGLE_CONTINUITY, true);
        update_user_meta($user_id, self::META_LEGACY_GOOGLE_CONTINUITY, $marker);
        if ($previous === '' || !hash_equals($previous, $marker)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('privileged_google_nextend_continuity_granted', 'warning', $user_id, ['provider'=>'google']);
            self::security_email($user_id, __('Connexion Google Delicat migrée en continuité sécurisée', 'delicat-google-login'), __('Votre identité Google déjà liée via Nextend a été reconnue par Delicat Identity à partir de son identifiant Google immuable. Activez TOTP 2FA pour passer au mode Administrateur renforcé.', 'delicat-google-login'));
        }
        return true;
    }

    public static function legacy_continuity_approved($user_id, $sub = '') {
        $user_id = absint($user_id);
        if (!$user_id) return false;
        if ($sub === '') $sub = (string)get_user_meta($user_id, DIP_Identity::META_SUB, true);
        if ($sub === '') return false;
        $stored = (string)get_user_meta($user_id, self::META_LEGACY_GOOGLE_CONTINUITY, true);
        $expected = self::legacy_marker($user_id, $sub);
        return $stored !== '' && $expected !== '' && hash_equals($stored, $expected);
    }

    public static function approved($user_id = 0, $sub = '') {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id || !self::is_admin($user_id)) return false;
        if ($sub === '') $sub = (string)get_user_meta($user_id, DIP_Identity::META_SUB, true);
        if ($sub === '') return false;
        $stored = (string)get_user_meta($user_id, self::META_GOOGLE_ADMIN_APPROVAL, true);
        $expected = self::marker($user_id, $sub);
        return $stored !== '' && hash_equals($stored, $expected);
    }

    /**
     * Privileged linking is allowed only for WordPress Administrators in the
     * secure Google mode. Editors/shop managers remain blocked by the generic
     * privileged-social policy.
     */
    public static function can_link_current($user_id, $provider) {
        $user_id = absint($user_id);
        $provider = sanitize_key($provider);
        if (!self::secure_mode_enabled() || $provider !== 'google') return false;
        if (!is_user_logged_in() || get_current_user_id() !== $user_id || !self::is_admin($user_id)) return false;
        if (!is_ssl()) return false;
        if (!class_exists('DIP_Two_Factor') || !DIP_Two_Factor::is_enabled($user_id)) return false;
        if (class_exists('DIP_Reauth') && !DIP_Reauth::is_recent($user_id)) return false;
        return true;
    }

    public static function approve_link($user_id, $sub, $source = 'linked') {
        $user_id = absint($user_id);
        $sub = sanitize_text_field((string)$sub);
        if (!$user_id || !$sub || !self::is_admin($user_id)) return false;
        $local_sub = (string)get_user_meta($user_id, DIP_Identity::META_SUB, true);
        if ($local_sub === '' || !hash_equals($local_sub, $sub)) return false;
        update_user_meta($user_id, self::META_GOOGLE_ADMIN_APPROVAL, self::marker($user_id, $sub));
        if (class_exists('DIP_Audit')) DIP_Audit::record('admin_google_secure_link_authorized', 'warning', $user_id, ['source'=>sanitize_key($source)]);
        self::security_email($user_id, __('Google autorisé pour votre compte administrateur Delicat', 'delicat-google-login'), __('La connexion Google sécurisée vient d’être autorisée pour votre compte Administrateur. Chaque connexion Google exigera encore votre second facteur Delicat.', 'delicat-google-login'));
        return true;
    }

    public static function revoke($user_id, $reason = 'manual') {
        $user_id = absint($user_id);
        if (!$user_id) return;
        $had = (string)get_user_meta($user_id, self::META_GOOGLE_ADMIN_APPROVAL, true) !== '';
        delete_user_meta($user_id, self::META_GOOGLE_ADMIN_APPROVAL);
        if ($had && class_exists('DIP_Audit')) DIP_Audit::record('admin_google_secure_link_revoked', 'critical', $user_id, ['reason'=>sanitize_key($reason)]);
    }

    public static function provider_disconnected($user_id, $provider) {
        if (sanitize_key($provider) === 'google') {
            self::revoke(absint($user_id), 'google_disconnected');
            delete_user_meta(absint($user_id), self::META_LEGACY_GOOGLE_CONTINUITY);
        }
    }

    public static function two_factor_disabled($user_id) {
        if (self::is_admin($user_id)) self::revoke(absint($user_id), 'two_factor_disabled');
    }

    /**
     * Validate an Administrator social login after Identity has resolved the
     * immutable provider subject but before any auth cookie is issued.
     */
    public static function validate_admin_google_login($user_id, $provider, array $profile) {
        $user_id = absint($user_id);
        $provider = sanitize_key($provider);
        if (!self::is_admin($user_id)) return true;
        if (!self::secure_mode_enabled()) {
            return new WP_Error('admin_google_secure_mode_disabled', __('La connexion Google Administrateur est désactivée par la politique de sécurité.', 'delicat-google-login'));
        }
        if ($provider !== 'google') {
            return new WP_Error('privileged_social_login_blocked', __('Les comptes Administrateur peuvent utiliser uniquement Google Secure Mode, un Passkey ou les identifiants WordPress protégés.', 'delicat-google-login'));
        }
        if (!is_ssl()) return new WP_Error('admin_google_https_required', __('HTTPS est requis pour la connexion Google Administrateur.', 'delicat-google-login'));
        $sub = sanitize_text_field((string)($profile['sub'] ?? ''));
        $stored_sub = (string)get_user_meta($user_id, DIP_Identity::META_SUB, true);
        if ($sub === '' || $stored_sub === '' || !hash_equals($stored_sub, $sub)) {
            return new WP_Error('admin_google_identity_mismatch', __('Cette identité Google n’est pas l’identité Administrateur approuvée.', 'delicat-google-login'));
        }
        $approved = self::approved($user_id, $sub);
        $legacy_continuity = self::legacy_continuity_approved($user_id, $sub);
        if (!$approved && !$legacy_continuity) {
            return new WP_Error('admin_google_not_authorized', __('Cette identité Google doit être explicitement autorisée depuis une session Administrateur déjà sécurisée.', 'delicat-google-login'));
        }

        // New Delicat admin links keep mandatory TOTP. Existing Nextend admin
        // links may continue during migration because the exact immutable Google
        // subject was already linked to this WordPress user before Identity Pro.
        // The continuity exception is signed, role-bound, auditable and cannot be
        // created from an e-mail match alone.
        $totp = class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user_id);
        if (!$totp && !$legacy_continuity) {
            return new WP_Error('admin_google_two_factor_required', __('Activez d’abord TOTP 2FA sur ce compte Administrateur.', 'delicat-google-login'));
        }
        if (class_exists('DIP_Audit')) DIP_Audit::record(
            $legacy_continuity && !$totp ? 'admin_google_legacy_continuity_verified' : 'admin_google_first_factor_verified',
            $legacy_continuity && !$totp ? 'warning' : 'notice',
            $user_id,
            ['provider'=>'google']
        );
        return true;
    }

    public static function revoke_current() {
        self::require_admin_post();
        check_admin_referer(self::NONCE . '_revoke');
        $uid = get_current_user_id();
        if (class_exists('DIP_Reauth') && !DIP_Reauth::is_recent($uid)) self::redirect('reauth_required');
        self::revoke($uid, 'manual');
        if (class_exists('WP_Session_Tokens')) WP_Session_Tokens::get_instance($uid)->destroy_others(wp_get_session_token());
        if (class_exists('DIP_Mobile_API')) DIP_Mobile_API::revoke_all_for_user($uid, 'admin_google_revoked');
        self::security_email($uid, __('Google retiré du mode Administrateur sécurisé', 'delicat-google-login'), __('L’autorisation Google Administrateur a été retirée. Votre compte Google reste lié au compte jusqu’à sa déconnexion explicite, mais il ne peut plus ouvrir une session Administrateur.', 'delicat-google-login'));
        self::redirect('revoked');
    }

    private static function require_admin_post() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response'=>405]);
        if (!is_user_logged_in() || !current_user_can('manage_options')) wp_die(esc_html__('Administrateur requis.', 'delicat-google-login'), '', ['response'=>403]);
    }

    private static function security_url() {
        if (function_exists('wc_get_account_endpoint_url') && class_exists('DIP_Customer_Dashboard')) {
            $url = wc_get_account_endpoint_url(DIP_Customer_Dashboard::ENDPOINT);
            return add_query_arg('dip_tab', 'security', $url);
        }
        return admin_url('options-general.php?page=dip-security-center');
    }

    private static function redirect($status) {
        $ref = wp_get_referer();
        $target = $ref ? wp_validate_redirect($ref, self::security_url()) : self::security_url();
        wp_safe_redirect(add_query_arg('dip_admin_google_status', sanitize_key($status), $target));
        exit;
    }

    private static function security_email($user_id, $subject, $message) {
        $user = get_userdata(absint($user_id));
        if (!$user || !is_email($user->user_email)) return;
        if (class_exists('DIP_Email_Hub')) DIP_Email_Hub::send_user_security($user->ID, $subject, $message);
        else wp_mail($user->user_email, wp_strip_all_tags((string)$subject), wp_strip_all_tags((string)$message));
    }

    public static function render_security_card($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id || !self::is_admin($user_id) || get_current_user_id() !== $user_id) return '';
        $linked_sub = (string)get_user_meta($user_id, DIP_Identity::META_SUB, true);
        $linked = $linked_sub !== '';
        $approved = $linked && self::approved($user_id, $linked_sub);
        $totp = class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user_id);
        $passkey = class_exists('DIP_Passkeys') && DIP_Passkeys::has_passkeys($user_id);
        $recent = !class_exists('DIP_Reauth') || DIP_Reauth::is_recent($user_id);
        $status = sanitize_key(wp_unslash($_GET['dip_admin_google_status'] ?? ''));
        $messages = [
            'authorized'=>__('Google Secure Mode est maintenant autorisé pour ce compte Administrateur.', 'delicat-google-login'),
            'revoked'=>__('L’autorisation Google Administrateur a été retirée.', 'delicat-google-login'),
            'two_factor_required'=>__('Activez TOTP 2FA avant d’autoriser Google pour un Administrateur.', 'delicat-google-login'),
            'reauth_required'=>__('Confirmez votre identité récemment avant cette opération.', 'delicat-google-login'),
            'google_not_linked'=>__('Reliez d’abord votre compte Google depuis cette session Administrateur.', 'delicat-google-login'),
            'https_required'=>__('HTTPS est obligatoire pour Google Secure Mode.', 'delicat-google-login'),
            'mode_disabled'=>__('Google Secure Mode Administrateur est désactivé dans les réglages.', 'delicat-google-login'),
        ];
        $settings = self::settings();
        $connect_url = add_query_arg([
            'dip_action'=>'login','provider'=>'google','link'=>1,
            '_dip_nonce'=>wp_create_nonce('dip_link_' . $user_id),
            'redirect'=>self::security_url(),
        ], home_url('/'));
        ob_start(); ?>
        <div class="dip-security-card dip-admin-google-secure">
          <div class="dip-security-card-title"><span class="dip-security-card-icon" aria-hidden="true">G</span><div><h3><?php esc_html_e('Google Secure Mode — Administrateur', 'delicat-google-login'); ?></h3><p><?php esc_html_e('Google peut ouvrir votre compte Administrateur uniquement avec l’identité Google explicitement approuvée + TOTP 2FA. Une simple correspondance d’e-mail ne suffit jamais.', 'delicat-google-login'); ?></p></div></div>
          <?php if (isset($messages[$status])): ?><div class="dip-security-notice" role="status"><?php echo esc_html($messages[$status]); ?></div><?php endif; ?>
          <div class="dip-check"><span><?php esc_html_e('Mode sécurisé Administrateur', 'delicat-google-login'); ?></span><span class="dip-pill <?php echo self::secure_mode_enabled()?'is-ok':'is-warn'; ?>"><?php echo self::secure_mode_enabled()?esc_html__('Actif','delicat-google-login'):esc_html__('Désactivé','delicat-google-login'); ?></span></div>
          <div class="dip-check"><span><?php esc_html_e('Google lié', 'delicat-google-login'); ?></span><span class="dip-pill <?php echo $linked?'is-ok':'is-warn'; ?>"><?php echo $linked?esc_html__('Oui','delicat-google-login'):esc_html__('Non','delicat-google-login'); ?></span></div>
          <div class="dip-check"><span><?php esc_html_e('Identité Google approuvée pour admin', 'delicat-google-login'); ?></span><span class="dip-pill <?php echo $approved?'is-ok':'is-warn'; ?>"><?php echo $approved?esc_html__('Approuvée','delicat-google-login'):esc_html__('À autoriser','delicat-google-login'); ?></span></div>
          <div class="dip-check"><span><?php esc_html_e('TOTP 2FA obligatoire', 'delicat-google-login'); ?></span><span class="dip-pill <?php echo $totp?'is-ok':'is-warn'; ?>"><?php echo $totp?esc_html__('Actif','delicat-google-login'):esc_html__('Requis','delicat-google-login'); ?></span></div>
          <div class="dip-check"><span><?php esc_html_e('Passkey de secours recommandée', 'delicat-google-login'); ?></span><span class="dip-pill <?php echo $passkey?'is-ok':'is-warn'; ?>"><?php echo $passkey?esc_html__('Disponible','delicat-google-login'):esc_html__('Recommandée','delicat-google-login'); ?></span></div>
          <div class="dip-actions">
          <?php if (!$linked && ($settings['enabled'] ?? 'yes') === 'yes' && $totp && $recent && is_ssl()): ?>
            <a class="dip-btn primary" href="<?php echo esc_url($connect_url); ?>"><?php esc_html_e('Relier Google en mode sécurisé', 'delicat-google-login'); ?></a>
          <?php elseif (!$linked && ($settings['enabled'] ?? 'yes') === 'yes'): ?>
            <span class="dip-btn" aria-disabled="true"><?php esc_html_e('TOTP + confirmation requis avant liaison', 'delicat-google-login'); ?></span>
          <?php elseif ($linked && !$approved && $totp && $recent && is_ssl()): ?>
            <a class="dip-btn primary" href="<?php echo esc_url($connect_url); ?>"><?php esc_html_e('Vérifier Google et autoriser cet Administrateur', 'delicat-google-login'); ?></a>
          <?php elseif ($linked && !$approved): ?>
            <span class="dip-btn" aria-disabled="true"><?php esc_html_e('TOTP + confirmation requis avant vérification Google', 'delicat-google-login'); ?></span>
          <?php elseif ($approved): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-dip-confirm="<?php echo esc_attr__('Retirer l’autorisation Google Administrateur ? Les autres méthodes de connexion resteront disponibles.', 'delicat-google-login'); ?>"><input type="hidden" name="action" value="dip_admin_google_revoke"><?php wp_nonce_field(self::NONCE . '_revoke'); ?><button type="submit" class="dip-btn danger"><?php esc_html_e('Retirer l’autorisation Google Admin', 'delicat-google-login'); ?></button></form>
          <?php endif; ?>
          </div>
          <?php if (!$recent): ?><p class="dip-security-empty"><?php esc_html_e('Confirmez d’abord votre identité dans la carte « Confirmation d’identité » ci-dessous.', 'delicat-google-login'); ?></p><?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    public static function admin_notice() {
        if (!is_admin() || !current_user_can('manage_options') || !self::secure_mode_enabled()) return;
        $uid = get_current_user_id();
        $sub = (string)get_user_meta($uid, DIP_Identity::META_SUB, true);
        if ($sub === '') return;
        if (self::legacy_continuity_approved($uid, $sub) && (!class_exists('DIP_Two_Factor') || !DIP_Two_Factor::is_enabled($uid))) {
            echo '<div class="notice notice-warning"><p><strong>Delicat Identity:</strong> ' . esc_html__('Votre ancien lien Google Nextend fonctionne en mode de continuité sécurisé. Activez TOTP 2FA dans le Centre de sécurité pour passer au niveau Administrateur renforcé.', 'delicat-google-login') . '</p></div>';
            return;
        }
        if (self::approved($uid, $sub)) return;
        echo '<div class="notice notice-warning"><p><strong>Delicat Identity:</strong> ' . esc_html__('Google est lié à votre compte Administrateur mais n’est pas encore autorisé pour Google Secure Mode. Tant que vous ne l’approuvez pas dans le Centre de sécurité, Google ne peut pas créer de session Administrateur.', 'delicat-google-login') . '</p></div>';
    }
}
