<?php
defined('ABSPATH') || exit;

final class DIP_Passwordless_Registration {
    const OPTION = 'dip_auth_methods_settings';
    const OTP_META = '_dip_email_otp';
    const MAGIC_META = '_dip_magic_login';
    const RATE_PREFIX = 'dip_auth_rate_';

    public static function install() {
        $defaults = self::defaults();
        $current = get_option(self::OPTION, []);
        update_option(self::OPTION, wp_parse_args(is_array($current) ? $current : [], $defaults), false);
    }

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_nopriv_dip_request_otp', [__CLASS__, 'request_otp']);
        add_action('admin_post_dip_request_otp', [__CLASS__, 'request_otp']);
        add_action('admin_post_nopriv_dip_verify_otp', [__CLASS__, 'verify_otp']);
        add_action('admin_post_dip_verify_otp', [__CLASS__, 'verify_otp']);
        add_action('admin_post_nopriv_dip_request_magic', [__CLASS__, 'request_magic']);
        add_action('admin_post_dip_request_magic', [__CLASS__, 'request_magic']);
        add_action('init', [__CLASS__, 'consume_magic'], 2);
        add_action('init', [__CLASS__, 'consume_email_verification'], 3);
        add_action('admin_post_nopriv_dip_register_customer', [__CLASS__, 'register_customer']);
        add_action('admin_post_dip_register_customer', [__CLASS__, 'register_customer']);
        add_shortcode('delicat_passwordless_login', [__CLASS__, 'passwordless_shortcode']);
        add_shortcode('delicat_registration_form', [__CLASS__, 'registration_shortcode']);
    }

    public static function defaults() {
        return [
            'otp_enabled' => 'yes',
            'magic_enabled' => 'yes',
            'registration_enabled' => 'yes',
            'otp_expiry' => 10,
            'magic_expiry' => 15,
            'allow_new_passwordless_users' => 'no',
            'registration_fields' => 'first_name,last_name,phone,country,referral,terms',
            'require_email_verification' => 'yes',
            'default_country' => 'HT',
            'terms_url' => '',
            'privacy_url' => '',
            'redirect_after_login' => '/my-account/',
            'redirect_after_register' => '/my-account/',
        ];
    }

    private static function settings() {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }

    public static function requires_email_verification() {
        $s = self::settings();
        return ($s['require_email_verification'] ?? 'yes') === 'yes';
    }

    private static function password_limits() {
        $main = class_exists('DIP_Plugin') ? wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults()) : [];
        $min = min(64, max(8, absint($main['native_password_min'] ?? 8)));
        $max = min(256, max($min, absint($main['native_password_max'] ?? 100)));
        return [$min, $max];
    }

    public static function send_email_verification($user_id, $email = '') {
        $user_id = absint($user_id);
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user) return false;
        $email = sanitize_email($email ?: $user->user_email);
        if (!is_email($email)) return false;
        $token = wp_generate_password(48, false, false);
        update_user_meta($user_id, '_dip_email_verify_hash', hash_hmac('sha256', $token, wp_salt('auth')));
        update_user_meta($user_id, '_dip_email_verify_email_hash', hash_hmac('sha256', strtolower($email), wp_salt('auth')));
        update_user_meta($user_id, '_dip_email_verify_expires', time() + DAY_IN_SECONDS);
        $verify_url = add_query_arg(['dip_verify_email'=>'1','uid'=>$user_id,'token'=>$token], home_url('/'));
        $body = '<p>Confirmez votre adresse email pour activer votre compte Delicat.</p><p>Ce lien expire dans <strong>24 heures</strong>.</p>';
        $sent = class_exists('DIP_Email_Hub')
            ? DIP_Email_Hub::send_access_email($email, 'Vérifiez votre adresse email Delicat', 'Confirmez votre adresse e-mail', $body, 'Vérifier mon e-mail', $verify_url, ['badge'=>'Vérification e-mail'])
            : wp_mail($email, 'Vérifiez votre adresse email Delicat', $body . '<p><a href="'.esc_url($verify_url).'">Vérifier mon email</a></p>', ['Content-Type: text/html; charset=UTF-8']);
        if (!$sent) {
            delete_user_meta($user_id, '_dip_email_verify_hash');
            delete_user_meta($user_id, '_dip_email_verify_email_hash');
            delete_user_meta($user_id, '_dip_email_verify_expires');
            if (class_exists('DIP_Audit')) DIP_Audit::record('registration_verification_email_failed', 'warning', $user_id, ['email_hash'=>hash('sha256', strtolower($email))]);
        }
        return (bool) $sent;
    }

    public static function admin_menu() {
        add_options_page('Identity Authentication', 'Identity Authentication', 'manage_options', 'dip-auth-methods', [__CLASS__, 'settings_page']);
    }

    public static function register_settings() {
        register_setting('dip_auth_methods_group', self::OPTION, ['type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize']]);
    }

    public static function sanitize($input) {
        $out = self::settings();
        foreach (['otp_enabled','magic_enabled','registration_enabled','allow_new_passwordless_users','require_email_verification'] as $key) {
            $out[$key] = !empty($input[$key]) ? 'yes' : 'no';
        }
        $out['otp_expiry'] = min(30, max(5, absint($input['otp_expiry'] ?? 10)));
        $out['magic_expiry'] = min(60, max(5, absint($input['magic_expiry'] ?? 15)));
        $allowed = ['first_name','last_name','phone','country','referral','terms','marketing'];
        $fields = array_values(array_intersect($allowed, array_map('sanitize_key', (array) ($input['registration_fields'] ?? []))));
        $out['registration_fields'] = implode(',', $fields);
        $out['default_country'] = strtoupper(substr(sanitize_text_field($input['default_country'] ?? 'HT'), 0, 2));
        $out['terms_url'] = esc_url_raw($input['terms_url'] ?? '');
        $out['privacy_url'] = esc_url_raw($input['privacy_url'] ?? '');
        $out['redirect_after_login'] = esc_url_raw($input['redirect_after_login'] ?? '/my-account/');
        $out['redirect_after_register'] = esc_url_raw($input['redirect_after_register'] ?? '/my-account/');
        return $out;
    }

    public static function settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = self::settings();
        $selected = array_filter(explode(',', $s['registration_fields']));
        ?>
        <div class="wrap"><h1>Delicat Identity — Authentication</h1>
        <p>Email OTP, magic links and configurable customer registration.</p>
        <form method="post" action="options.php">
            <?php settings_fields('dip_auth_methods_group'); ?>
            <table class="form-table" role="presentation">
                <tr><th>Email OTP</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[otp_enabled]" <?php checked($s['otp_enabled'], 'yes'); ?>> Enable email OTP login</label><p><input type="number" min="5" max="30" name="<?php echo esc_attr(self::OPTION); ?>[otp_expiry]" value="<?php echo esc_attr($s['otp_expiry']); ?>"> minutes validity</p></td></tr>
                <tr><th>Magic links</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[magic_enabled]" <?php checked($s['magic_enabled'], 'yes'); ?>> Enable one-time magic links</label><p><input type="number" min="5" max="60" name="<?php echo esc_attr(self::OPTION); ?>[magic_expiry]" value="<?php echo esc_attr($s['magic_expiry']); ?>"> minutes validity</p></td></tr>
                <tr><th>Passwordless registration</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[allow_new_passwordless_users]" <?php checked($s['allow_new_passwordless_users'], 'yes'); ?>> Allow a verified email to create a new customer automatically</label></td></tr>
                <tr><th>Registration builder</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[registration_enabled]" <?php checked($s['registration_enabled'], 'yes'); ?>> Enable custom registration form</label><br><?php
                $labels = ['first_name'=>'First name','last_name'=>'Last name','phone'=>'Phone','country'=>'Country','referral'=>'Referral code','terms'=>'Terms acceptance','marketing'=>'Marketing consent'];
                foreach ($labels as $key=>$label) echo '<label style="display:inline-block;margin:8px 16px 0 0"><input type="checkbox" name="'.esc_attr(self::OPTION).'[registration_fields][]" value="'.esc_attr($key).'" '.checked(in_array($key,$selected,true),true,false).'> '.esc_html($label).'</label>';
                ?></td></tr>
                <tr><th>Email verification</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[require_email_verification]" <?php checked($s['require_email_verification'], 'yes'); ?>> Require email verification before registration completes</label></td></tr>
                <tr><th>Legal URLs</th><td><input class="regular-text" type="url" placeholder="Terms URL" name="<?php echo esc_attr(self::OPTION); ?>[terms_url]" value="<?php echo esc_attr($s['terms_url']); ?>"><br><input class="regular-text" type="url" placeholder="Privacy URL" name="<?php echo esc_attr(self::OPTION); ?>[privacy_url]" value="<?php echo esc_attr($s['privacy_url']); ?>"></td></tr>
                <tr><th>Redirects</th><td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[redirect_after_login]" value="<?php echo esc_attr($s['redirect_after_login']); ?>"><p class="description">After login</p><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[redirect_after_register]" value="<?php echo esc_attr($s['redirect_after_register']); ?>"><p class="description">After registration</p></td></tr>
            </table><?php submit_button(); ?>
        </form>
        <hr><h2>Shortcodes</h2><code>[delicat_passwordless_login]</code> &nbsp; <code>[delicat_registration_form]</code>
        </div><?php
    }

    private static function rate_key($email, $action) {
        $ip = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return self::RATE_PREFIX . md5(strtolower((string) $email) . '|' . sanitize_key($action) . '|' . hash_hmac('sha256', $ip, wp_salt('auth')));
    }

    private static function client_key() {
        // Bind OTPs to a stable first-party browser identifier rather than the
        // IP address, which can legitimately change on mobile networks between
        // requesting and entering a code.
        if (class_exists('DIP_Devices')) return DIP_Devices::current_hash(true);
        $ua = substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 180);
        $ip = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return hash_hmac('sha256', $ip . '|' . $ua, wp_salt('auth'));
    }

    private static function otp_key($email) {
        return 'dip_otp_' . hash_hmac('sha256', strtolower((string) $email) . '|' . self::client_key(), wp_salt('nonce'));
    }

    private static function magic_key($token) {
        return 'dip_magic_' . hash_hmac('sha256', (string) $token, wp_salt('nonce'));
    }

    private static function new_accounts_allowed() {
        $main = class_exists('DIP_Plugin') ? wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults()) : [];
        if (($main['allow_registration'] ?? 'yes') !== 'yes') return false;
        if (class_exists('DIP_Account_Sync')) return DIP_Account_Sync::storefront_registration_enabled();
        return (bool) get_option('users_can_register')
            || 'yes' === get_option('woocommerce_enable_myaccount_registration')
            || 'yes' === get_option('woocommerce_enable_signup_and_login_from_checkout');
    }

    private static function throttle($email, $action, $seconds = 60) {
        $key = self::rate_key($email, $action);
        if (get_transient($key)) return false;
        $ip = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $global_key = self::RATE_PREFIX . 'ip_' . sanitize_key($action) . '_' . substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 32);
        $state = get_transient($global_key);
        if (!is_array($state) || empty($state['reset']) || (int) $state['reset'] <= time()) $state = ['count'=>0,'reset'=>time() + 10 * MINUTE_IN_SECONDS];
        if ((int) $state['count'] >= 12) return false;
        $state['count']++;
        set_transient($global_key, $state, max(1, (int) $state['reset'] - time()));
        set_transient($key, 1, $seconds);
        return true;
    }

    private static function redirect($args = []) {
        $url = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg($args, $url));
        exit;
    }

    /** Browser-bound nonce shared with the native Delicat authentication layer. */
    private static function verify_form_nonce($action) {
        if (class_exists('DIP_Native_Auth')) return DIP_Native_Auth::verify_browser_nonce($action);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        return $nonce !== '' && (bool) wp_verify_nonce($nonce, $action);
    }

    private static function nonce_input($action, $key) {
        $nonce = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::issue_browser_nonce($action) : wp_create_nonce($action);
        return '<input type="hidden" name="nonce" value="' . esc_attr($nonce) . '" data-dip-passwordless-nonce="' . esc_attr($key) . '">';
    }

    private static function otp_email_key() {
        return 'dip_otp_email_' . substr(hash_hmac('sha256', self::client_key(), wp_salt('nonce')), 0, 40);
    }

    private static function remember_otp_email($email, $ttl) {
        $email = sanitize_email($email);
        if ($email) set_transient(self::otp_email_key(), $email, max(60, absint($ttl)));
    }

    private static function remembered_otp_email() {
        $email = sanitize_email((string) get_transient(self::otp_email_key()));
        return is_email($email) ? $email : '';
    }

    /** Refresh cached-page nonces after load, using the same browser cookie as the modal. */
    private static function nonce_refresh_script() {
        static $printed = false;
        if ($printed || !class_exists('DIP_Native_Auth')) return '';
        $printed = true;
        $ajax = wp_make_link_relative(admin_url('admin-ajax.php'));
        if (!is_string($ajax) || $ajax === '' || strpos($ajax, '/') !== 0) $ajax = '/wp-admin/admin-ajax.php';
        $nonce_action = defined('DIP_Native_Auth::AJAX_NONCE_ACTION') ? DIP_Native_Auth::AJAX_NONCE_ACTION : 'dip_native_fresh_nonces_v2';
        return '<script>(function(){"use strict";var u=' . wp_json_encode($ajax) . ',a=' . wp_json_encode($nonce_action) . ';function r(){var d=new FormData();d.append("action",a);fetch(u,{method:"POST",body:d,credentials:"same-origin",cache:"no-store",headers:{"X-Requested-With":"XMLHttpRequest"}}).then(function(x){return x.json();}).then(function(x){if(!x||!x.success||!x.data)return;document.querySelectorAll("[data-dip-passwordless-nonce]").forEach(function(n){var k=n.getAttribute("data-dip-passwordless-nonce");if(k&&x.data[k])n.value=x.data[k];});}).catch(function(){});}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",r,{once:true});else r();document.addEventListener("visibilitychange",function(){if(document.visibilityState==="visible")r();});})();</script>';
    }

    public static function request_otp() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') self::redirect(['dip_auth'=>'confirmation_failed']);
        if (!self::verify_form_nonce('dip_request_otp')) self::redirect(['dip_auth'=>'confirmation_failed']);
        $s = self::settings();
        if ($s['otp_enabled'] !== 'yes') self::redirect(['dip_auth'=>'disabled']);
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!$email || !is_email($email)) self::redirect(['dip_auth'=>'invalid_email']);
        if (!self::throttle($email, 'otp', 60)) self::redirect(['dip_auth'=>'wait']);
        $user = get_user_by('email', $email);
        if (!$user && ($s['allow_new_passwordless_users'] !== 'yes' || !self::new_accounts_allowed())) self::redirect(['dip_auth'=>'sent']);
        $code = (string) random_int(100000, 999999);
        $payload = ['hash'=>wp_hash_password($code), 'expires'=>time() + absint($s['otp_expiry']) * 60, 'attempts'=>0, 'client'=>self::client_key()];
        set_transient(self::otp_key($email), $payload, absint($s['otp_expiry']) * 60);
        self::remember_otp_email($email, absint($s['otp_expiry']) * 60);
        $subject = 'Votre code de connexion Delicat';
        $body = '<p>Votre code de connexion est :</p><p style="font-size:30px;font-weight:900;letter-spacing:6px;text-align:center">'.esc_html($code).'</p><p>Il expire dans <strong>'.absint($s['otp_expiry']).' minutes</strong>. Ne partagez jamais ce code.</p>';
        $sent = class_exists('DIP_Email_Hub')
            ? DIP_Email_Hub::send_access_email($email, $subject, 'Code de connexion sécurisé', $body, '', '', ['badge'=>'Code unique','accent'=>'#7c3aed'])
            : wp_mail($email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
        if (!$sent) {
            delete_transient(self::otp_key($email));
            if (class_exists('DIP_Audit')) DIP_Audit::log('passwordless_otp_email_failed', 0, ['email_hash'=>hash('sha256', strtolower($email))]);
            self::redirect(['dip_auth'=>'email_failed']);
        }
        if (class_exists('DIP_Audit')) DIP_Audit::log('passwordless_otp_requested', 0, ['email_hash'=>hash('sha256', strtolower($email))]);
        self::redirect(['dip_auth'=>'sent']);
    }

    public static function verify_otp() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') self::redirect(['dip_auth'=>'confirmation_failed']);
        if (!self::verify_form_nonce('dip_verify_otp')) self::redirect(['dip_auth'=>'confirmation_failed']);
        if (is_user_logged_in()) self::redirect(['dip_auth'=>'already_logged_in']);
        $s = self::settings();
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
        $key = self::otp_key($email);
        $payload = get_transient($key);
        if (!$payload || empty($payload['expires']) || time() > $payload['expires'] || !hash_equals((string)$payload['client'], self::client_key())) self::redirect(['dip_auth'=>'expired']);
        $payload['attempts'] = absint($payload['attempts'] ?? 0) + 1;
        if ($payload['attempts'] > 5 || !wp_check_password($code, $payload['hash'])) {
            set_transient($key, $payload, max(1, $payload['expires'] - time()));
            self::redirect(['dip_auth'=>'invalid_code']);
        }
        delete_transient($key);
        $user = get_user_by('email', $email);
        $created = false;
        if (!$user && $s['allow_new_passwordless_users'] === 'yes' && self::new_accounts_allowed()) {
            if (class_exists('DIP_Native_Auth') && DIP_Native_Auth::registration_rate_limited()) self::redirect(['dip_register'=>'registration_rate_limited']);
            $username = self::unique_username($email);
            $id = class_exists('DIP_Account_Sync')
                ? DIP_Account_Sync::create_customer($email, $username, wp_generate_password(48, true, true), [], get_role('customer') ? 'customer' : 'subscriber')
                : wp_insert_user(['user_login'=>$username,'user_email'=>$email,'user_pass'=>wp_generate_password(48,true,true),'role'=>get_role('customer')?'customer':'subscriber']);
            if (!is_wp_error($id)) {
                $created = true;
                update_user_meta($id, DIP_Identity::META_PASSWORD_MANAGED, 'yes');
                if (class_exists('DIP_Native_Auth')) DIP_Native_Auth::record_registration();
                if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_verified_registration($id, $email); else update_user_meta($id, 'dip_email_verified', 'yes');
                do_action('dip_user_created', $id, ['provider'=>'email_otp','email'=>$email]);
                $user = get_user_by('id', $id);
            }
        }
        if (!$user) self::redirect(['dip_auth'=>'account_required']);
        // A valid OTP proves mailbox ownership even for a pre-existing unverified account.
        if (get_user_meta($user->ID, 'dip_email_verified', true) === 'no') {
            if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_verified_registration($user->ID, $email); else update_user_meta($user->ID, 'dip_email_verified', 'yes');
        }
        $guard = class_exists('DIP_Account_Sync') ? DIP_Account_Sync::login_guard($user->ID) : true;
        if (is_wp_error($guard)) self::redirect(['dip_auth'=>$guard->get_error_code()]);
        self::login_user($user, 'email_otp');
        if (class_exists('DIP_Audit')) DIP_Audit::log('passwordless_otp_login', $user->ID, []);
        wp_safe_redirect(self::safe_redirect($s['redirect_after_login'])); exit;
    }

    public static function request_magic() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') self::redirect(['dip_auth'=>'confirmation_failed']);
        if (!self::verify_form_nonce('dip_request_magic')) self::redirect(['dip_auth'=>'confirmation_failed']);
        $s = self::settings();
        if ($s['magic_enabled'] !== 'yes') self::redirect(['dip_auth'=>'disabled']);
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!$email || !is_email($email)) self::redirect(['dip_auth'=>'invalid_email']);
        if (!self::throttle($email, 'magic', 90)) self::redirect(['dip_auth'=>'wait']);
        $user = get_user_by('email', $email);
        if (!$user && ($s['allow_new_passwordless_users'] !== 'yes' || !self::new_accounts_allowed())) self::redirect(['dip_auth'=>'sent']);
        $token = wp_generate_password(48, false, false);
        $email_enc = class_exists('DIP_Crypto') ? DIP_Crypto::encrypt($email) : '';
        $record = [
            'hash'=>hash_hmac('sha256',$token,wp_salt('auth')),
            'email_hash'=>hash_hmac('sha256',strtolower($email),wp_salt('auth')),
            'email_enc'=>$email_enc,
            'expires'=>time()+absint($s['magic_expiry'])*60,
        ];
        set_transient(self::magic_key($token), $record, absint($s['magic_expiry'])*60);
        // New links keep the email address out of browser history/access logs
        // when encrypted transient storage is available. Legacy fallback remains
        // compatible on hosts without a crypto engine.
        $args = ['dip_magic'=>'1','token'=>$token];
        if ($email_enc === '') $args['email'] = rawurlencode($email);
        $url = add_query_arg($args, home_url('/'));
        $body = '<p>Utilisez le bouton ci-dessous pour vous connecter en toute sécurité.</p><p>Ce lien expire dans <strong>'.absint($s['magic_expiry']).' minutes</strong> et ne peut être utilisé qu’une seule fois.</p>';
        $sent = class_exists('DIP_Email_Hub')
            ? DIP_Email_Hub::send_access_email($email, 'Votre lien de connexion Delicat', 'Connexion sécurisée', $body, 'Se connecter', $url, ['badge'=>'Lien unique','accent'=>'#2563eb'])
            : wp_mail($email, 'Votre lien de connexion Delicat', $body . '<p><a href="'.esc_url($url).'">Se connecter</a></p>', ['Content-Type: text/html; charset=UTF-8']);
        if (!$sent) {
            delete_transient(self::magic_key($token));
            if (class_exists('DIP_Audit')) DIP_Audit::log('magic_link_email_failed', 0, ['email_hash'=>hash('sha256', strtolower($email))]);
            self::redirect(['dip_auth'=>'email_failed']);
        }
        if (class_exists('DIP_Audit')) DIP_Audit::log('magic_link_requested', 0, ['email_hash'=>hash('sha256', strtolower($email))]);
        self::redirect(['dip_auth'=>'sent']);
    }

    private static function confirmation_page($title, $message, $button, array $fields, $nonce) {
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        $action = home_url('/');
        ?><!doctype html><html lang="fr"><head><meta charset="<?php echo esc_attr(get_option('blog_charset')); ?>"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title><?php echo esc_html($title); ?></title><style>
        *{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#f5f7fb;color:#11172b}body{min-height:100vh;min-height:100dvh;display:grid;place-items:center;padding:max(18px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(18px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left))}.dip-confirm{width:min(100%,430px);padding:30px 26px;border:1px solid #e3e8f2;border-radius:26px;background:#fff;box-shadow:0 24px 70px rgba(16,31,67,.16);text-align:center}.dip-confirm-mark{width:58px;height:58px;margin:0 auto 18px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,#1459ff,#6b35ef);color:#fff;font-size:27px;font-weight:900}.dip-confirm h1{margin:0 0 10px;font-size:25px;line-height:1.18}.dip-confirm p{margin:0 0 22px;color:#687087;line-height:1.55}.dip-confirm button{width:100%;min-height:56px;border:0;border-radius:15px;background:linear-gradient(135deg,#1459ff,#6b35ef);color:#fff;font-size:16px;font-weight:800;cursor:pointer;box-shadow:0 13px 28px rgba(49,77,207,.25)}.dip-confirm small{display:block;margin-top:16px;color:#8a91a3;line-height:1.45}@media(max-width:480px){.dip-confirm{padding:26px 20px;border-radius:22px}.dip-confirm h1{font-size:22px}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important}}
        </style></head><body><main class="dip-confirm" role="main"><div class="dip-confirm-mark" aria-hidden="true">D</div><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($message); ?></p><form method="post" action="<?php echo esc_url($action); ?>"><?php foreach ($fields as $name=>$value): ?><input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>"><?php endforeach; ?><input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>"><button type="submit"><?php echo esc_html($button); ?></button></form><small><?php echo esc_html__('Cette étape protège votre compte contre les ouvertures automatiques de liens et les connexions non désirées.', 'delicat-google-login'); ?></small></main></body></html><?php
        exit;
    }

    private static function masked_email($email) {
        $email = sanitize_email($email);
        if (!$email || strpos($email, '@') === false) return '';
        [$local, $domain] = explode('@', $email, 2);
        $first = function_exists('mb_substr') ? mb_substr($local, 0, 1) : substr($local, 0, 1);
        return $first . str_repeat('•', min(5, max(3, strlen($local) - 1))) . '@' . $domain;
    }

    public static function consume_magic() {
        $confirm = !empty($_POST['dip_magic_confirm']);
        if (!$confirm && (empty($_GET['dip_magic']) || empty($_GET['token']))) return;
        nocache_headers();
        $s = self::settings();
        $token = sanitize_text_field(wp_unslash($confirm ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '')));
        $key = self::magic_key($token);
        $record = get_transient($key);
        $fallback_email = sanitize_email(wp_unslash($confirm ? ($_POST['email'] ?? '') : ($_GET['email'] ?? '')));
        $email = '';
        if (is_array($record) && !empty($record['email_enc']) && class_exists('DIP_Crypto')) {
            $email = sanitize_email(DIP_Crypto::decrypt((string) $record['email_enc']));
        }
        if (!$email) $email = $fallback_email; // compatibility with links issued before 6.2.0
        $expected = hash_hmac('sha256', $token, wp_salt('auth'));
        if (!$email || !$token || !$record || time() > absint($record['expires'] ?? 0) || !hash_equals((string)($record['hash'] ?? ''), $expected) || !hash_equals((string)($record['email_hash'] ?? ''), hash_hmac('sha256', strtolower($email), wp_salt('auth')))) {
            wp_safe_redirect(add_query_arg('dip_auth','expired',home_url('/'))); exit;
        }

        $nonce_base = 'dip_magic_confirm_' . substr(hash_hmac('sha256', $token, wp_salt('nonce')), 0, 24);
        if (!$confirm) {
            $nonce = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::issue_browser_nonce($nonce_base) : '';
            if ($nonce === '') { wp_safe_redirect(add_query_arg('dip_auth','confirmation_failed',home_url('/'))); exit; }
            self::confirmation_page(
                __('Confirmer la connexion', 'delicat-google-login'),
                sprintf(__('Vous allez vous connecter avec %s.', 'delicat-google-login'), self::masked_email($email)),
                __('Continuer la connexion', 'delicat-google-login'),
                array_merge(['dip_magic_confirm'=>'1','token'=>$token], empty($record['email_enc']) ? ['email'=>$email] : []),
                $nonce
            );
        }
        if (!class_exists('DIP_Native_Auth') || !DIP_Native_Auth::verify_browser_nonce($nonce_base)) {
            wp_safe_redirect(add_query_arg('dip_auth','confirmation_failed',home_url('/'))); exit;
        }

        $user = get_user_by('email', $email);
        if (is_user_logged_in() && (!$user || get_current_user_id() !== (int) $user->ID)) {
            wp_safe_redirect(add_query_arg('dip_auth','already_logged_in',home_url('/'))); exit;
        }

        // Consume only after an explicit, browser-bound confirmation. Email
        // scanners/prefetchers can safely open the GET without burning the link.
        delete_transient($key);
        if (!$user && $s['allow_new_passwordless_users'] === 'yes' && self::new_accounts_allowed()) {
            if (class_exists('DIP_Native_Auth') && DIP_Native_Auth::registration_rate_limited()) { wp_safe_redirect(add_query_arg('dip_register','registration_rate_limited',home_url('/'))); exit; }
            $id = class_exists('DIP_Account_Sync')
                ? DIP_Account_Sync::create_customer($email, self::unique_username($email), wp_generate_password(48, true, true), [], get_role('customer') ? 'customer' : 'subscriber')
                : wp_insert_user(['user_login'=>self::unique_username($email),'user_email'=>$email,'user_pass'=>wp_generate_password(48,true,true),'role'=>get_role('customer')?'customer':'subscriber']);
            if (!is_wp_error($id)) {
                update_user_meta($id, DIP_Identity::META_PASSWORD_MANAGED, 'yes');
                if (class_exists('DIP_Native_Auth')) DIP_Native_Auth::record_registration();
                if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_verified_registration($id, $email); else update_user_meta($id, 'dip_email_verified', 'yes');
                do_action('dip_user_created', $id, ['provider'=>'magic_link','email'=>$email]);
                $user = get_user_by('id', $id);
            }
        }
        if (!$user) { wp_safe_redirect(add_query_arg('dip_auth','account_required',home_url('/'))); exit; }
        if (get_user_meta($user->ID, 'dip_email_verified', true) === 'no') {
            if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_verified_registration($user->ID, $email); else update_user_meta($user->ID, 'dip_email_verified', 'yes');
        }
        $guard = class_exists('DIP_Account_Sync') ? DIP_Account_Sync::login_guard($user->ID) : true;
        if (is_wp_error($guard)) { wp_safe_redirect(add_query_arg('dip_auth',$guard->get_error_code(),home_url('/'))); exit; }
        self::login_user($user, 'magic_link');
        if (class_exists('DIP_Audit')) DIP_Audit::log('magic_link_login', $user->ID, []);
        wp_safe_redirect(self::safe_redirect($s['redirect_after_login'])); exit;
    }

    public static function register_customer() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') self::redirect(['dip_auth'=>'confirmation_failed']);
        if (!self::verify_form_nonce('dip_register_customer')) self::redirect(['dip_auth'=>'confirmation_failed']);
        if (is_user_logged_in()) self::redirect(['dip_register'=>'already_logged_in']);
        if (!empty($_POST['dl_hp'])) self::redirect(['dip_register'=>'invalid']);
        if (class_exists('DIP_Native_Auth') && !DIP_Native_Auth::registration_attempt_allowed()) self::redirect(['dip_register'=>'registration_attempt_rate_limited']);
        $s = self::settings();
        $platform_registration = class_exists('DIP_Account_Sync')
            ? DIP_Account_Sync::storefront_registration_enabled()
            : ((bool) get_option('users_can_register') || 'yes' === get_option('woocommerce_enable_myaccount_registration') || 'yes' === get_option('woocommerce_enable_signup_and_login_from_checkout'));
        if ($s['registration_enabled'] !== 'yes' || !$platform_registration) self::redirect(['dip_register'=>'disabled']);
        if (class_exists('DIP_Native_Auth') && DIP_Native_Auth::registration_rate_limited()) self::redirect(['dip_register'=>'registration_rate_limited']);
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $password = (string) wp_unslash($_POST['password'] ?? '');
        [$min, $max] = self::password_limits();
        $password_len = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        if (!is_email($email) || strlen($password) > 1024 || $password_len < $min || $password_len > $max || !preg_match('/\p{L}/u', $password) || !preg_match('/\p{N}/u', $password)) self::redirect(['dip_register'=>'invalid']);
        if (email_exists($email)) self::redirect(['dip_register'=>'failed']);
        $fields = array_filter(explode(',', $s['registration_fields']));
        if (in_array('terms',$fields,true) && empty($_POST['terms'])) self::redirect(['dip_register'=>'terms']);
        $first = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $role = get_role('customer') ? 'customer' : 'subscriber';
        $id = class_exists('DIP_Account_Sync')
            ? DIP_Account_Sync::create_customer($email, self::unique_username($email), $password, ['first_name'=>$first,'last_name'=>$last,'display_name'=>trim($first.' '.$last)], $role)
            : wp_insert_user(['user_login'=>self::unique_username($email),'user_email'=>$email,'user_pass'=>$password,'first_name'=>$first,'last_name'=>$last,'role'=>$role]);
        if (is_wp_error($id)) self::redirect(['dip_register'=>'failed']);
        delete_user_meta($id, DIP_Identity::META_PASSWORD_MANAGED);
        if (class_exists('DIP_Native_Auth')) DIP_Native_Auth::record_registration();
        foreach (['phone'=>'billing_phone','country'=>'billing_country','referral'=>'dip_referral_code','marketing'=>'dip_marketing_consent'] as $input=>$meta) {
            if (in_array($input,$fields,true) && isset($_POST[$input])) update_user_meta($id,$meta,sanitize_text_field(wp_unslash($_POST[$input])));
        }
        do_action('dip_user_created', $id, ['provider'=>'custom_registration','email'=>$email,'given_name'=>$first,'family_name'=>$last]);
        $requires_verification = self::requires_email_verification();
        update_user_meta($id, 'dip_email_verified', $requires_verification ? 'no' : 'yes');
        if ($requires_verification) {
            if (!self::send_email_verification($id, $email)) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($id);
                self::redirect(['dip_register'=>'email_failed']);
            }
            if (class_exists('DIP_Audit')) DIP_Audit::record('custom_registration_pending_verification', 'notice', $id);
            self::redirect(['dip_register'=>'verify_sent']);
        }
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_verified_registration($id, $email);
        $user = get_user_by('id',$id);
        self::login_user($user, 'custom_registration');
        if (class_exists('DIP_Audit')) DIP_Audit::record('custom_registration', 'info', $id);
        wp_safe_redirect(self::safe_redirect($s['redirect_after_register'])); exit;
    }


    public static function consume_email_verification() {
        $confirm = !empty($_POST['dip_verify_email_confirm']);
        if (!$confirm && (empty($_GET['dip_verify_email']) || empty($_GET['uid']) || empty($_GET['token']))) return;
        nocache_headers();
        $uid = absint($confirm ? ($_POST['uid'] ?? 0) : ($_GET['uid'] ?? 0));
        $token = sanitize_text_field(wp_unslash($confirm ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '')));
        $user = get_user_by('id', $uid);
        $stored = (string) get_user_meta($uid, '_dip_email_verify_hash', true);
        $stored_email = (string) get_user_meta($uid, '_dip_email_verify_email_hash', true);
        $expires = absint(get_user_meta($uid, '_dip_email_verify_expires', true));
        $expected = hash_hmac('sha256', $token, wp_salt('auth'));
        $current_email_hash = $user ? hash_hmac('sha256', strtolower(sanitize_email($user->user_email)), wp_salt('auth')) : '';
        if (!$user || !$token || !$stored || !$stored_email || !$expires || time() > $expires || !hash_equals($stored, $expected) || !hash_equals($stored_email, $current_email_hash)) {
            wp_safe_redirect(add_query_arg('dip_register', 'verify_expired', home_url('/'))); exit;
        }

        $nonce_base = 'dip_email_verify_' . $uid . '_' . substr(hash_hmac('sha256', $token, wp_salt('nonce')), 0, 20);
        if (!$confirm) {
            $nonce = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::issue_browser_nonce($nonce_base) : '';
            if ($nonce === '') { wp_safe_redirect(add_query_arg('dip_register','confirmation_failed',home_url('/'))); exit; }
            self::confirmation_page(
                __('Vérifier votre adresse e-mail', 'delicat-google-login'),
                sprintf(__('Confirmez l’adresse %s pour activer votre compte.', 'delicat-google-login'), self::masked_email($user->user_email)),
                __('Vérifier mon e-mail', 'delicat-google-login'),
                ['dip_verify_email_confirm'=>'1','uid'=>$uid,'token'=>$token],
                $nonce
            );
        }
        if (!class_exists('DIP_Native_Auth') || !DIP_Native_Auth::verify_browser_nonce($nonce_base)) {
            wp_safe_redirect(add_query_arg('dip_register','confirmation_failed',home_url('/'))); exit;
        }

        update_user_meta($uid, 'dip_email_verified', 'yes');
        delete_user_meta($uid, '_dip_email_verify_hash');
        delete_user_meta($uid, '_dip_email_verify_email_hash');
        delete_user_meta($uid, '_dip_email_verify_expires');
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_verified_registration($uid, $user->user_email);
        if (class_exists('DIP_Audit')) DIP_Audit::log('registration_email_verified', $uid, []);

        // Verification proves mailbox ownership but does not silently replace
        // the visitor's current WordPress identity. The customer explicitly
        // signs in afterwards, eliminating verification-link login CSRF.
        $guard = class_exists('DIP_Account_Sync') ? DIP_Account_Sync::login_guard($uid) : true;
        if (is_wp_error($guard)) { wp_safe_redirect(add_query_arg('dip_auth', $guard->get_error_code(), home_url('/'))); exit; }
        $s = self::settings();
        $destination = add_query_arg('dip_verified', '1', self::safe_redirect($s['redirect_after_register']));
        wp_safe_redirect($destination . '#delicat-login'); exit;
    }

    private static function login_user($user, $method = 'passwordless') {
        if (!($user instanceof WP_User)) return;
        if (class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user->ID)) {
            $s = self::settings();
            $destination = self::safe_redirect($s['redirect_after_login'] ?? '/my-account/');
            $challenge = DIP_Two_Factor::begin_web_challenge($user->ID, $destination, true, sanitize_key($method));
            if ($challenge !== '') {
                wp_safe_redirect($challenge);
                exit;
            }
            if (class_exists('DIP_Audit')) DIP_Audit::record('two_factor_challenge_init_failed', 'critical', $user->ID, ['method'=>sanitize_key($method)]);
            self::redirect(['dip_auth'=>'two_factor_challenge_failed']);
        }
        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true, is_ssl());
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::fire_wp_login($user, $method);
        else do_action('wp_login', $user->user_login, $user);
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_login($user->ID);
        do_action('dip_login_success', $user->ID, ['provider'=>sanitize_key($method)]);
    }

    private static function safe_redirect($value) {
        $url = $value ?: '/my-account/';
        if (strpos($url, '/') === 0) $url = home_url($url);
        return wp_validate_redirect($url, home_url('/'));
    }

    private static function unique_username($email) {
        $base = sanitize_user(strtok($email, '@'), true) ?: 'customer';
        $name = $base; $i = 1;
        while (username_exists($name)) { $name = $base . $i; $i++; }
        return $name;
    }

    private static function notice() {
        $code = sanitize_key($_GET['dip_auth'] ?? $_GET['dip_register'] ?? '');
        [$pass_min] = self::password_limits();
        $map = ['sent'=>'Si ce compte existe, un message a été envoyé.','wait'=>'Veuillez attendre avant de réessayer.','invalid_email'=>'Adresse email invalide.','invalid_code'=>'Code incorrect.','expired'=>'Le code ou lien a expiré.','account_required'=>'Créez d’abord un compte.','terms'=>'Vous devez accepter les conditions.','invalid'=>sprintf('Vérifiez les champs et utilisez un mot de passe d’au moins %d caractères avec une lettre et un chiffre.', $pass_min),'failed'=>'Inscription impossible.','verify_sent'=>'Compte créé. Vérifiez votre email pour l’activer.','verify_expired'=>'Le lien de vérification est invalide ou expiré.','email_failed'=>'L’e-mail n’a pas pu être envoyé. Vérifiez la configuration SMTP du site.','already_logged_in'=>'Vous êtes déjà connecté. Déconnectez-vous avant de créer un autre compte.','registration_rate_limited'=>'Limite d’inscriptions atteinte. Réessayez dans une heure.','registration_attempt_rate_limited'=>'Trop de demandes d’inscription. Réessayez dans quelques minutes.','account_pending_approval'=>'Votre compte attend l’approbation de l’administrateur.','dip_email_not_verified'=>'Vérifiez votre adresse e-mail avant de vous connecter.','confirmation_failed'=>'La confirmation sécurisée a expiré. Ouvrez de nouveau le lien.','two_factor_challenge_failed'=>'Impossible de démarrer la vérification en deux étapes. Réessayez.'];
        return isset($map[$code]) ? '<div class="dip-auth-notice">'.esc_html($map[$code]).'</div>' : '';
    }

    private static function style() {
        return '<style>.dip-auth-box{max-width:520px;margin:20px auto;padding:24px;border:1px solid #e4e8ef;border-radius:20px;background:#fff;box-shadow:0 16px 50px rgba(15,42,82,.08)}.dip-auth-box h3{margin:0 0 8px}.dip-auth-box form{display:grid;gap:12px;margin:18px 0}.dip-auth-box input,.dip-auth-box select{width:100%;min-height:48px;padding:10px 13px;border:1px solid #ced5df;border-radius:12px}.dip-auth-box button{min-height:48px;border:0;border-radius:12px;background:#0f2a52;color:#fff;font-weight:700;padding:10px 16px;cursor:pointer}.dip-auth-tabs{display:grid;grid-template-columns:1fr 1fr;gap:10px}.dip-auth-notice{padding:11px 13px;background:#eef5ff;border-radius:12px;margin-bottom:12px}.dip-check{display:flex;gap:9px;align-items:flex-start}.dip-check input{width:auto;min-height:0;margin-top:4px}@media(max-width:560px){.dip-auth-box{padding:18px;margin:12px}.dip-auth-tabs{grid-template-columns:1fr}}</style>';
    }

    public static function passwordless_shortcode() {
        if (is_user_logged_in()) return '<div class="dip-auth-box">Vous êtes déjà connecté.</div>';
        $s = self::settings();
        ob_start(); echo self::style(); ?>
        <div class="dip-auth-box"><h3>Connexion sans mot de passe</h3><p>Recevez un code ou un lien sécurisé par email.</p><?php echo self::notice(); ?>
        <?php if ($s['otp_enabled']==='yes'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_request_otp"><?php echo self::nonce_input('dip_request_otp','otp_request'); ?><label class="screen-reader-text" for="dip-otp-request-email">Adresse email</label><input id="dip-otp-request-email" type="email" name="email" maxlength="254" autocomplete="email" placeholder="Adresse email" required><button type="submit">Envoyer un code</button></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_verify_otp"><?php echo self::nonce_input('dip_verify_otp','otp_verify'); ?><label class="screen-reader-text" for="dip-otp-verify-email">Adresse email</label><input id="dip-otp-verify-email" type="email" name="email" maxlength="254" autocomplete="email" value="<?php echo esc_attr(self::remembered_otp_email()); ?>" placeholder="Adresse email" required><label class="screen-reader-text" for="dip-otp-code">Code à 6 chiffres</label><input id="dip-otp-code" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" name="code" placeholder="Code à 6 chiffres" required><button type="submit">Vérifier et se connecter</button></form><?php endif; ?>
        <?php if ($s['magic_enabled']==='yes'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_request_magic"><?php echo self::nonce_input('dip_request_magic','magic_request'); ?><label class="screen-reader-text" for="dip-magic-email">Adresse email</label><input id="dip-magic-email" type="email" name="email" maxlength="254" autocomplete="email" placeholder="Adresse email" required><button type="submit">Envoyer un lien magique</button></form><?php endif; ?>
        </div><?php echo self::nonce_refresh_script(); return ob_get_clean();
    }

    public static function registration_shortcode() {
        $s = self::settings();
        if ($s['registration_enabled']!=='yes') return '';
        if (is_user_logged_in()) return '<div class="dip-auth-box">Vous êtes déjà connecté.</div>';
        $fields = array_filter(explode(',', $s['registration_fields']));
        [$pass_min, $pass_max] = self::password_limits();
        ob_start(); echo self::style(); ?>
        <div class="dip-auth-box"><h3>Créer votre compte</h3><p>Inscription sécurisée Delicat.</p><?php echo self::notice(); ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_register_customer"><?php echo self::nonce_input('dip_register_customer','customer_register'); ?>
        <input type="email" name="email" maxlength="254" autocomplete="email" placeholder="Adresse email" required><input type="password" name="password" minlength="<?php echo esc_attr($pass_min); ?>" maxlength="<?php echo esc_attr($pass_max); ?>" autocomplete="new-password" placeholder="<?php echo esc_attr(sprintf('Mot de passe (%d caractères minimum)', $pass_min)); ?>" required><input type="text" name="dl_hp" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;opacity:0;pointer-events:none">
        <?php if(in_array('first_name',$fields,true)): ?><input type="text" name="first_name" placeholder="Prénom"><?php endif; ?>
        <?php if(in_array('last_name',$fields,true)): ?><input type="text" name="last_name" placeholder="Nom"><?php endif; ?>
        <?php if(in_array('phone',$fields,true)): ?><input type="tel" name="phone" placeholder="Téléphone"><?php endif; ?>
        <?php if(in_array('country',$fields,true)): ?><input type="text" maxlength="2" name="country" value="<?php echo esc_attr($s['default_country']); ?>" placeholder="Code pays"><?php endif; ?>
        <?php if(in_array('referral',$fields,true)): ?><input type="text" name="referral" placeholder="Code de parrainage"><?php endif; ?>
        <?php if(in_array('marketing',$fields,true)): ?><label class="dip-check"><input type="checkbox" name="marketing" value="yes"> Recevoir les offres et nouveautés Delicat.</label><?php endif; ?>
        <?php if(in_array('terms',$fields,true)): ?><label class="dip-check"><input type="checkbox" name="terms" value="yes" required> <span>J’accepte <?php if($s['terms_url']): ?><a href="<?php echo esc_url($s['terms_url']); ?>" target="_blank" rel="noopener">les conditions</a><?php else: ?>les conditions<?php endif; ?><?php if($s['privacy_url']): ?> et <a href="<?php echo esc_url($s['privacy_url']); ?>" target="_blank" rel="noopener">la confidentialité</a><?php endif; ?>.</span></label><?php endif; ?>
        <button type="submit">Créer mon compte</button></form></div><?php echo self::nonce_refresh_script(); return ob_get_clean();
    }
}
