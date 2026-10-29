<?php
defined('ABSPATH') || exit;

/**
 * Session-bound recent-authentication gate for sensitive account operations.
 *
 * A fresh sign-in of any method counts as recent authentication for a short
 * period: the session's own sign-in time is used (WordPress stores 'login' in
 * every session token), so nothing has to be written at login. Later, the
 * customer re-confirms with the methods the account really has: TOTP or a
 * recovery code when 2FA is on (the password alone is not enough then), the
 * password, a one-time code sent to the account e-mail when Delicat manages
 * the password (Google, e-mail code, magic link), or a passkey. The marker is
 * bound to the current WordPress session token, so it never authorizes a
 * different browser/session.
 *
 * The confirmation card is rendered with the stable anchor #dip-reauth.
 */
final class DIP_Reauth {
    const NONCE_ACTION = 'dip_reauth_confirm';
    const CODE_NONCE = 'dip_reauth_email_code';
    const ANCHOR = 'dip-reauth';
    const SESSION_FIELD = 'dip_auth_at';
    const CODE_TTL = 600;
    const CODE_COOLDOWN = 60;
    const CODE_MAX_TRIES = 5;
    const CODE_MAIL_FLOOD = 10;
    const ACCOUNT_FAILURES = 20;
    const DAY_FAILURES = 50;

    /** Session tokens issued during this request, by user id. */
    private static $issued = [];
    private static $styles_printed = false;

    public static function init() {
        add_action('set_logged_in_cookie', [__CLASS__, 'capture_issued_token'], 10, 6);
        add_filter('attach_session_information', [__CLASS__, 'attach_session'], 10, 2);
        add_action('dip_login_success', [__CLASS__, 'login_success'], 100, 2);
        add_action('wp_login', [__CLASS__, 'wp_login'], 100, 2);
        add_action('admin_post_dip_reauth_confirm', [__CLASS__, 'handle_confirm']);
        add_action('admin_post_dip_reauth_email_code', [__CLASS__, 'handle_send_code']);
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

    /**
     * The session of this request. wp_set_auth_cookie() never updates
     * $_COOKIE, so inside the sign-in request the token it just issued is
     * used instead of the (missing or previous) cookie.
     */
    private static function session_token($user_id = 0) {
        $user_id = absint($user_id);
        if ($user_id && !empty(self::$issued[$user_id])) return (string) self::$issued[$user_id];
        return function_exists('wp_get_session_token') ? (string) wp_get_session_token() : '';
    }

    private static function key($user_id, $token = null) {
        $user_id = absint($user_id);
        $token = $token === null ? self::session_token($user_id) : (string) $token;
        if (!$user_id || $token === '') return '';
        return 'dip_reauth_' . substr(hash_hmac('sha256', $user_id . '|' . $token, wp_salt('secure_auth')), 0, 40);
    }

    private static function session_record($user_id, $token) {
        if (!$user_id || $token === '' || !class_exists('WP_Session_Tokens')) return [];
        try { $session = WP_Session_Tokens::get_instance($user_id)->get($token); }
        catch (Throwable $e) { $session = null; }
        return is_array($session) ? $session : [];
    }

    /** When the session last proved who it is by signing in (0: never, e.g. app pairing). */
    private static function session_auth_time(array $session) {
        if (array_key_exists(self::SESSION_FIELD, $session)) return (int) $session[self::SESSION_FIELD];
        return (int) ($session['login'] ?? 0);
    }

    private static function recent_at_for_token($user_id, $token) {
        $key = self::key($user_id, $token);
        if ($key === '') return 0;
        return max((int) get_transient($key), self::session_auth_time(self::session_record($user_id, $token)));
    }

    private static function recent_at($user_id) {
        return self::recent_at_for_token(absint($user_id), self::session_token($user_id));
    }

    public static function mark_recent($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        $key = self::key($user_id);
        if (!$key) return false;
        return set_transient($key, time(), self::window());
    }

    public static function is_recent($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id || !is_user_logged_in() || $user_id !== get_current_user_id()) return false;
        $at = self::recent_at($user_id);
        return $at > 0 && (time() - $at) <= self::window();
    }

    public static function seconds_remaining($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        $at = self::recent_at($user_id);
        if (!$at) return 0;
        return max(0, self::window() - (time() - $at));
    }

    public static function capture_issued_token($logged_in_cookie, $expire, $expiration, $user_id, $scheme, $token) {
        $user_id = absint($user_id);
        if ($user_id && is_string($token) && $token !== '') self::$issued[$user_id] = $token;
    }

    /**
     * A new session created while this request already carries a live
     * session of the same user is a re-issue (WordPress does that when the
     * user changes their own password), not a sign-in: it keeps the recent
     * state of the session it replaces instead of counting as fresh. A real
     * sign-in on top of it is marked fresh by wp_login()/login_success().
     */
    public static function attach_session($session, $user_id) {
        $user_id = absint($user_id);
        if (!$user_id || !is_array($session)) return $session;
        $previous = self::session_token($user_id);
        if ($previous === '' || !self::session_record($user_id, $previous)) return $session;
        $session[self::SESSION_FIELD] = self::recent_at_for_token($user_id, $previous);
        return $session;
    }

    /**
     * The session issued by this request was opened by a real sign-in
     * ($proven: its own 'login' time counts, any inherited value is dropped)
     * or without a fresh proof (it never counts as recent by itself).
     */
    private static function mark_session($user_id, $proven) {
        $user_id = absint($user_id);
        $token = (string) (self::$issued[$user_id] ?? '');
        $session = self::session_record($user_id, $token);
        if (!$session) return;
        $has = array_key_exists(self::SESSION_FIELD, $session);
        if ($proven) {
            if (!$has) return;
            unset($session[self::SESSION_FIELD]);
        } else {
            if ($has && (int) $session[self::SESSION_FIELD] === 0) return;
            $session[self::SESSION_FIELD] = 0;
        }
        try { WP_Session_Tokens::get_instance($user_id)->update($token, $session); } catch (Throwable $e) {}
    }

    /**
     * App pairing opens a browser session from an already signed-in phone,
     * without any fresh proof: it must not unlock sensitive web actions.
     */
    private static function proves_identity($method) {
        return !in_array(sanitize_key((string) $method), ['delicat_app_pairing'], true);
    }

    public static function login_success($user_id, $context = []) {
        $method = is_array($context) ? (string) ($context['provider'] ?? '') : '';
        self::mark_session(absint($user_id), self::proves_identity($method));
    }

    public static function wp_login($user_login, $user) {
        if (!($user instanceof WP_User)) return;
        $method = class_exists('DIP_Account_Sync') && method_exists('DIP_Account_Sync', 'wp_login_context') ? (string) DIP_Account_Sync::wp_login_context() : '';
        self::mark_session($user->ID, self::proves_identity($method));
    }

    // ------------------------------------------------------------------ methods

    private static function password_managed($user_id) {
        $meta = defined('DIP_Identity::META_PASSWORD_MANAGED') ? DIP_Identity::META_PASSWORD_MANAGED : '_dglp_password_managed';
        return get_user_meta(absint($user_id), $meta, true) === 'yes';
    }

    private static function two_factor_enabled($user_id) {
        return class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user_id);
    }

    /** E-mail one-time code: for accounts whose password Delicat manages (no known password) and without 2FA. */
    private static function email_code_available($user_id) {
        $user = get_userdata(absint($user_id));
        return $user instanceof WP_User && is_email($user->user_email) && self::password_managed($user->ID) && !self::two_factor_enabled($user->ID);
    }

    private static function passkey_available($user_id) {
        if (!class_exists('DIP_Passkeys') || !method_exists('DIP_Passkeys', 'count') || !DIP_Passkeys::available()) return false;
        if (DIP_Passkeys::count($user_id) < 1) return false;
        // The button needs the passkey runtime, which enqueues itself once (C4).
        if (method_exists('DIP_Passkeys', 'enqueue_runtime')) return (bool) DIP_Passkeys::enqueue_runtime();
        return wp_script_is('dip-passkeys', 'enqueued') || wp_script_is('dip-passkeys', 'done');
    }

    // ------------------------------------------------------------- rate limits

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

    /**
     * Account-wide, failures only: rotating addresses must not allow endless
     * guessing from a stolen session (20 per 10 minutes, 50 per day). A real
     * customer is never stuck: signing in again counts as a confirmation.
     */
    private static function failure_key($user_id, $scope = '') {
        return 'dip_reauth_fail_' . ($scope !== '' ? $scope . '_' : '') . absint($user_id);
    }

    /** '' (allowed), 'rate_limited' (short window) or 'locked' (daily cap). */
    private static function failures_exhausted($user_id) {
        if (!class_exists('DIP_Lockout') || !method_exists('DIP_Lockout', 'counter_peek')) return '';
        if (DIP_Lockout::counter_peek(self::failure_key($user_id, 'day'))['count'] >= self::DAY_FAILURES) return 'locked';
        return DIP_Lockout::counter_peek(self::failure_key($user_id))['count'] >= self::ACCOUNT_FAILURES ? 'rate_limited' : '';
    }

    private static function record_failure($user_id, $method) {
        if (class_exists('DIP_Audit')) DIP_Audit::record('reauth_failed', 'warning', $user_id, ['method'=>sanitize_key($method)]);
        if (!class_exists('DIP_Lockout') || !method_exists('DIP_Lockout', 'counter_hit')) return;
        $short = DIP_Lockout::counter_hit(self::failure_key($user_id), 10 * MINUTE_IN_SECONDS);
        $day = DIP_Lockout::counter_hit(self::failure_key($user_id, 'day'), DAY_IN_SECONDS);
        if (($short === self::ACCOUNT_FAILURES || $day === self::DAY_FAILURES) && class_exists('DIP_Audit')) {
            DIP_Audit::record('reauth_failures_limit', 'critical', $user_id, ['window'=>$day === self::DAY_FAILURES ? 'day' : '10min']);
        }
    }

    /**
     * The daily cap of wrong 2FA codes is reached: signing in again would
     * need that code too, so the card must not suggest logging out.
     */
    private static function factor_locked($user_id) {
        return self::two_factor_enabled($user_id) && method_exists('DIP_Two_Factor', 'failures_exhausted') && DIP_Two_Factor::failures_exhausted($user_id);
    }

    private static function clear_failures($user_id) {
        if (!class_exists('DIP_Lockout') || !method_exists('DIP_Lockout', 'counter_clear')) return;
        DIP_Lockout::counter_clear(self::failure_key($user_id));
        DIP_Lockout::counter_clear(self::failure_key($user_id, 'day'));
    }

    // --------------------------------------------------------------- redirects

    private static function security_url() {
        $url = home_url('/my-account/');
        if (class_exists('DIP_Customer_Dashboard') && function_exists('wc_get_account_endpoint_url')) {
            $url = add_query_arg('dip_tab', 'security', wc_get_account_endpoint_url(DIP_Customer_Dashboard::ENDPOINT));
        }
        return $url;
    }

    private static function strip_fragment($url) {
        $pos = strpos((string) $url, '#');
        return $pos === false ? (string) $url : substr((string) $url, 0, $pos);
    }

    private static function same_site($url) {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2048) return '';
        // _wp_http_referer is a path: make it absolute on this site's origin.
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            $parts = wp_parse_url(home_url('/'));
            if (!is_array($parts) || empty($parts['host'])) return '';
            $url = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . absint($parts['port']) : '') . $url;
        }
        return self::strip_fragment(wp_validate_redirect(esc_url_raw($url), ''));
    }

    /** Does this page show the confirmation card? (Security tab, or a page with [delicat_security_center].) */
    private static function shows_card($url) {
        if ($url === '') return false;
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $args = [];
        if ($query !== '') parse_str($query, $args);
        if (($args['dip_tab'] ?? '') === 'security') return true;
        $post_id = function_exists('url_to_postid') ? absint(url_to_postid($url)) : 0;
        $content = $post_id ? (string) get_post_field('post_content', $post_id) : '';
        return $content !== '' && has_shortcode($content, 'delicat_security_center');
    }

    /** Where a page that started a sensitive action should be sent back after the confirmation. */
    private static function posted_return() {
        $raw = isset($_REQUEST['dip_reauth_return']) ? wp_unslash($_REQUEST['dip_reauth_return']) : '';
        return is_string($raw) ? self::same_site($raw) : '';
    }

    /**
     * URL of the confirmation card. $return is where the customer goes back
     * after confirming (same site only). Other screens can link to it.
     */
    public static function confirm_url($return = '', $status = '') {
        $return = self::same_site($return);
        $base = self::security_url();
        if ($return !== '' && self::shows_card($return)) $base = $return;
        $base = remove_query_arg(['dip_reauth_status', 'dip_reauth_return'], self::strip_fragment($base));
        if ($return !== '' && $base !== remove_query_arg(['dip_reauth_status', 'dip_reauth_return'], $return)) $base = add_query_arg('dip_reauth_return', rawurlencode($return), $base);
        if ($status !== '') $base = add_query_arg('dip_reauth_status', sanitize_key($status), $base);
        return $base . '#' . self::ANCHOR;
    }

    /** Back to the card, keeping the page to return to. */
    private static function card_target($status, $return = '') {
        $ref = self::same_site((string) wp_get_referer());
        if ($return === '' && $ref !== '' && !self::shows_card($ref)) $return = $ref;
        $base = $ref !== '' && self::shows_card($ref) ? $ref : self::security_url();
        $base = remove_query_arg(['dip_reauth_status', 'dip_reauth_return'], $base);
        if ($return !== '') $base = add_query_arg('dip_reauth_return', rawurlencode($return), $base);
        return add_query_arg('dip_reauth_status', sanitize_key($status), $base) . '#' . self::ANCHOR;
    }

    private static function redirect($status, $return = '') {
        wp_safe_redirect(self::card_target($status, $return));
        exit;
    }

    /**
     * Sensitive action without a recent confirmation: send the customer to
     * the confirmation card (where the reason is shown), then back to the
     * page that started the action once confirmed.
     */
    public static function require_recent_or_redirect($message = '') {
        if (self::is_recent()) return true;
        if (!is_user_logged_in()) {
            if (class_exists('DIP_Access_Guard')) DIP_Access_Guard::require_login();
            auth_redirect();
        }
        if (class_exists('DIP_Audit')) DIP_Audit::record('sensitive_action_reauth_required', 'notice', get_current_user_id());
        self::redirect('required');
    }

    // --------------------------------------------------------------- handlers

    private static function require_post_self_service() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'delicat-google-login'), '', ['response'=>405]);
        }
        if (class_exists('DIP_Access_Guard')) DIP_Access_Guard::require_self_service();
        elseif (!is_user_logged_in()) auth_redirect();
    }

    public static function handle_confirm() {
        self::require_post_self_service();
        check_admin_referer(self::NONCE_ACTION);

        $user_id = get_current_user_id();
        $return = self::posted_return();
        $exhausted = self::failures_exhausted($user_id);
        if ($exhausted !== '' || !self::rate_allowed($user_id)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('reauth_rate_limited', 'warning', $user_id);
            self::redirect(self::factor_locked($user_id) ? 'factor_locked' : ($exhausted === 'locked' ? 'locked' : 'rate_limited'), $return);
        }

        $user = get_userdata($user_id);
        // WordPress and WooCommerce forms hash and check the password exactly
        // as posted (with magic-quote slashes); the typed text is tried too for
        // passwords set through an unslashed path (REST, app, passwordless).
        $posted_password = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passwords are never altered.
        $password = (string) wp_unslash($posted_password);
        $factor = sanitize_text_field(wp_unslash($_POST['factor_code'] ?? ''));
        $email_code = preg_replace('/\D+/', '', sanitize_text_field(wp_unslash($_POST['email_code'] ?? '')));
        $ok = false;
        $method = '';
        $failure = 'failed';

        if (self::two_factor_enabled($user_id)) {
            // A 2FA account confirms with its second factor (or a passkey):
            // the password alone would let a stolen password + cookie pair
            // enrol a passkey and bypass TOTP for good.
            if ($factor === '') self::redirect('factor_required', $return);
            if (!DIP_Two_Factor::factor_attempt_allowed($user_id)) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('reauth_two_factor_rate_limited', 'warning', $user_id);
                self::redirect(self::factor_locked($user_id) ? 'factor_locked' : 'rate_limited', $return);
            }
            $method = 'totp_or_recovery';
            $ok = DIP_Two_Factor::verify_factor($user_id, $factor, true);
            if ($ok) DIP_Two_Factor::clear_factor_rate($user_id);
            elseif (method_exists('DIP_Two_Factor', 'last_failure') && DIP_Two_Factor::last_failure() === 'replay') $failure = 'code_replayed';
        } elseif ($email_code !== '') {
            $method = 'email_code';
            $result = self::email_code_available($user_id) ? self::verify_email_code($user_id, $email_code) : 'unavailable';
            $ok = $result === 'ok';
            if ($result === 'expired') $failure = 'code_expired';
            elseif ($result === 'busy') self::redirect('code_invalid', $return);
            elseif ($result === 'unavailable') self::redirect('method_unavailable', $return);
            elseif (!$ok) $failure = 'code_invalid';
        } elseif ($password !== '' && $user instanceof WP_User) {
            $method = 'password';
            $ok = strlen($posted_password) <= 4096 && (wp_check_password($posted_password, $user->user_pass, $user_id) || ($password !== $posted_password && wp_check_password($password, $user->user_pass, $user_id)));
        } else {
            self::redirect('failed', $return);
        }

        if (!$ok) {
            self::record_failure($user_id, $method);
            self::redirect($failure, $return);
        }

        self::mark_recent($user_id);
        delete_transient(self::rate_key($user_id));
        self::clear_failures($user_id);
        if (class_exists('DIP_Audit')) DIP_Audit::record('reauth_success', 'info', $user_id, ['method'=>$method]);
        if ($return !== '') {
            wp_safe_redirect($return);
            exit;
        }
        self::redirect('success');
    }

    // ---------------------------------------------------------- e-mail code

    private static function code_key($user_id) {
        $token = self::session_token($user_id);
        if (!$user_id || $token === '') return '';
        return 'dip_reconfirm_code_' . substr(hash_hmac('sha256', absint($user_id) . '|code|' . $token, wp_salt('secure_auth')), 0, 40);
    }

    private static function code_hash($user_id, $code) {
        return hash_hmac('sha256', absint($user_id) . '|' . self::session_token($user_id) . '|' . (string) $code, wp_salt('secure_auth'));
    }

    private static function pending_code($user_id) {
        $key = self::code_key($user_id);
        $record = $key !== '' ? get_transient($key) : false;
        return is_array($record) && (int) ($record['expires'] ?? 0) > time() && (int) ($record['tries'] ?? 0) < self::CODE_MAX_TRIES ? $record : [];
    }

    private static function mask_email($email) {
        $email = (string) $email;
        $at = strrpos($email, '@');
        if ($at === false) return '';
        $local = substr($email, 0, $at);
        $keep = function_exists('mb_substr') ? mb_substr($local, 0, min(2, max(1, (int) floor(mb_strlen($local) / 3)))) : substr($local, 0, 1);
        return $keep . '•••' . substr($email, $at);
    }

    public static function handle_send_code() {
        self::require_post_self_service();
        check_admin_referer(self::CODE_NONCE);
        $user_id = get_current_user_id();
        $return = self::posted_return();
        if (!self::email_code_available($user_id)) self::redirect('method_unavailable', $return);
        $key = self::code_key($user_id);
        if ($key === '') self::redirect('failed', $return);

        $pending = self::pending_code($user_id);
        if ($pending && time() - (int) ($pending['sent'] ?? 0) < self::CODE_COOLDOWN) self::redirect('code_cooldown', $return);
        // Flood guard only (each request sends an e-mail); never reached by a person.
        $flood = 'dip_reconfirm_mail_' . absint($user_id);
        if (class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'counter_peek') && DIP_Lockout::counter_peek($flood)['count'] >= self::CODE_MAIL_FLOOD) self::redirect('code_flood', $return);

        try { $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); }
        catch (Throwable $e) { self::redirect('code_send_failed', $return); }
        set_transient($key, ['hash'=>self::code_hash($user_id, $code), 'expires'=>time() + self::CODE_TTL, 'sent'=>time(), 'tries'=>0], self::CODE_TTL);

        $user = get_userdata($user_id);
        $minutes = (int) (self::CODE_TTL / MINUTE_IN_SECONDS);
        $subject = __('Votre code de confirmation Delicat', 'delicat-google-login');
        $body = '<p>' . esc_html__('Pour confirmer votre identité avant une action sensible sur votre compte, saisissez ce code :', 'delicat-google-login') . '</p>'
            . '<p style="font-size:30px;font-weight:900;letter-spacing:6px;text-align:center">' . esc_html($code) . '</p>'
            . '<p>' . wp_kses_post(sprintf(__('Il expire dans <strong>%d minutes</strong> et ne fonctionne que dans le navigateur où vous l’avez demandé. Ne le communiquez à personne : Delicat Store ne vous le demandera jamais.', 'delicat-google-login'), $minutes)) . '</p>'
            . '<p>' . esc_html__('Vous n’êtes pas à l’origine de cette demande ? Quelqu’un utilise peut-être une de vos sessions : ouvrez le centre de sécurité et déconnectez les autres sessions.', 'delicat-google-login') . '</p>';
        $sent = class_exists('DIP_Email_Hub')
            ? DIP_Email_Hub::send_access_email($user->user_email, $subject, __('Confirmez votre identité', 'delicat-google-login'), $body, '', '', ['badge'=>__('Code unique', 'delicat-google-login'), 'accent'=>'#7c3aed', 'label'=>__('Sécurité du compte', 'delicat-google-login')])
            : wp_mail($user->user_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
        if (!$sent) {
            delete_transient($key);
            if (class_exists('DIP_Audit')) DIP_Audit::record('reauth_email_code_failed', 'warning', $user_id);
            self::redirect('code_send_failed', $return);
        }
        if (class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'counter_hit')) DIP_Lockout::counter_hit($flood, HOUR_IN_SECONDS);
        if (class_exists('DIP_Audit')) DIP_Audit::record('reauth_email_code_sent', 'notice', $user_id);
        self::redirect('code_sent', $return);
    }

    /** 'ok', 'invalid', 'expired' or 'busy'. Attempts are counted under an exclusive lock. */
    private static function verify_email_code($user_id, $code) {
        $key = self::code_key($user_id);
        if ($key === '' || !preg_match('/^\d{6}$/D', (string) $code)) return $key === '' ? 'expired' : 'invalid';
        $lock = 'dip_reconfirm_lock_' . absint($user_id);
        $locked = class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'mutex_acquire') ? DIP_Lockout::mutex_acquire($lock, 6, 1500) : (bool) add_option($lock, time(), '', false);
        if (!$locked) return 'busy';
        try {
            $record = get_transient($key);
            if (!is_array($record) || (int) ($record['expires'] ?? 0) <= time() || (int) ($record['tries'] ?? 0) >= self::CODE_MAX_TRIES) {
                delete_transient($key);
                return 'expired';
            }
            if (hash_equals((string) ($record['hash'] ?? ''), self::code_hash($user_id, $code))) {
                delete_transient($key);
                return 'ok';
            }
            $record['tries'] = (int) ($record['tries'] ?? 0) + 1;
            if ($record['tries'] >= self::CODE_MAX_TRIES) {
                delete_transient($key);
                return 'expired';
            }
            set_transient($key, $record, max(1, (int) $record['expires'] - time()));
            return 'invalid';
        } finally {
            if (class_exists('DIP_Lockout') && method_exists('DIP_Lockout', 'mutex_release')) DIP_Lockout::mutex_release($lock);
            else delete_option($lock);
        }
    }

    // ----------------------------------------------------------- WooCommerce

    /**
     * Protect email/password changes on Woo My Account. Basic name/profile edits
     * remain convenient; identity-changing fields require recent authentication.
     * WooCommerce passes a stdClass (not a WP_User) carrying the new values.
     */
    public static function protect_account_changes($errors, $user) {
        if (!($errors instanceof WP_Error) || !is_object($user) || empty($user->ID) || !is_user_logged_in() || get_current_user_id() !== (int) $user->ID) return $errors;
        $current = get_userdata((int) $user->ID);
        if (!$current) return $errors;

        $new_email = isset($user->user_email) ? (string) $user->user_email : (isset($_POST['account_email']) ? sanitize_email(wp_unslash($_POST['account_email'])) : '');
        $email_change = $new_email !== '' && strtolower($new_email) !== strtolower((string) $current->user_email);
        // WooCommerce sets user_pass only after checking password_current.
        $password_change = !empty($user->user_pass);
        if (!$email_change && !$password_change) return $errors;
        if (self::is_recent($current->ID)) return $errors;
        // The current password Woo just verified is the very proof the card
        // would ask for, except on a 2FA account (second factor required).
        if (!$email_change && !self::two_factor_enabled($current->ID)) return $errors;

        $back = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('edit-account') : (string) wp_get_referer();
        $errors->add('dip_reauth_required', sprintf(
            /* translators: %s: link to the identity confirmation card */
            __('Pour protéger votre compte, confirmez d’abord votre identité avant de modifier votre adresse e-mail ou votre mot de passe. %s — vous reviendrez ensuite ici pour enregistrer.', 'delicat-google-login'),
            '<a href="' . esc_url(self::confirm_url($back, 'required')) . '">' . esc_html__('Confirmer mon identité', 'delicat-google-login') . '</a>'
        ));
        if (class_exists('DIP_Audit')) DIP_Audit::record('profile_sensitive_change_blocked_reauth', 'notice', $current->ID, ['email_change'=>$email_change?1:0,'password_change'=>$password_change?1:0]);
        return $errors;
    }

    // ------------------------------------------------------------------- card

    private static function hidden_fields($nonce_action, $return) {
        $out = wp_nonce_field($nonce_action, '_wpnonce', true, false);
        if ($return !== '') $out .= '<input type="hidden" name="dip_reauth_return" value="' . esc_attr($return) . '">';
        return $out;
    }

    public static function render_form() {
        if (!is_user_logged_in()) return '';
        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        $recent = self::is_recent();
        $status = sanitize_key(wp_unslash($_GET['dip_reauth_status'] ?? ''));
        $return = self::posted_return();
        $two_factor = self::two_factor_enabled($user_id);
        $email_code = !$two_factor && self::email_code_available($user_id);
        $pending = $email_code ? self::pending_code($user_id) : [];
        $passkey = !$recent && self::passkey_available($user_id);
        $masked = self::mask_email($user->user_email);
        $messages = [
            'required' => __('Cette action est sensible : confirmez d’abord votre identité ci-dessous, puis recommencez-la.', 'delicat-google-login'),
            'failed' => __('La confirmation a échoué. Vérifiez vos informations.', 'delicat-google-login'),
            'factor_required' => __('Votre compte est protégé par la 2FA : entrez un code de votre application Authenticator ou un code de récupération.', 'delicat-google-login'),
            'code_replayed' => __('Ce code vient déjà d’être utilisé. Attendez le code suivant de votre application (30 secondes).', 'delicat-google-login'),
            'rate_limited' => __('Trop de tentatives. Réessayez dans quelques minutes.', 'delicat-google-login'),
            'locked' => __('Trop de confirmations échouées aujourd’hui. Pour continuer, déconnectez-vous puis reconnectez-vous : une connexion récente confirme aussi votre identité.', 'delicat-google-login'),
            'factor_locked' => $passkey ? __('Trop de codes 2FA incorrects aujourd’hui : confirmez avec votre Passkey ci-dessous, ou réinitialisez votre mot de passe pour débloquer la vérification.', 'delicat-google-login') : __('Trop de codes 2FA incorrects aujourd’hui. Réinitialisez votre mot de passe (« Mot de passe oublié ») pour débloquer la vérification.', 'delicat-google-login'),
            'success' => __('Identité confirmée. Les actions sensibles sont autorisées temporairement.', 'delicat-google-login'),
            'code_sent' => sprintf(__('Un code à 6 chiffres vient d’être envoyé à %s. Il expire dans 10 minutes.', 'delicat-google-login'), $masked),
            'code_cooldown' => __('Un code vient déjà d’être envoyé. Vérifiez votre boîte de réception (et les indésirables) ou patientez une minute avant d’en demander un autre.', 'delicat-google-login'),
            'code_invalid' => __('Code incorrect. Vérifiez le dernier e-mail reçu et réessayez.', 'delicat-google-login'),
            'code_expired' => __('Ce code a expiré ou a été mal saisi trop de fois. Demandez-en un nouveau.', 'delicat-google-login'),
            'code_send_failed' => __('L’e-mail n’a pas pu être envoyé. Réessayez dans un instant.', 'delicat-google-login'),
            'code_flood' => __('Trop de codes demandés pour le moment. Réessayez plus tard.', 'delicat-google-login'),
            'method_unavailable' => __('Cette méthode de confirmation n’est pas disponible pour votre compte.', 'delicat-google-login'),
        ];
        $is_error = !in_array($status, ['success', 'code_sent'], true);
        if ($two_factor) $lead = $passkey ? __('Votre compte est protégé par la 2FA : confirmez avec une Passkey, un code de votre application Authenticator ou un code de récupération.', 'delicat-google-login') : __('Votre compte est protégé par la 2FA : confirmez avec un code de votre application Authenticator ou un code de récupération.', 'delicat-google-login');
        elseif ($email_code) $lead = $passkey ? __('Votre compte n’a pas de mot de passe à retenir : confirmez avec une Passkey ou un code reçu par e-mail.', 'delicat-google-login') : __('Votre compte n’a pas de mot de passe à retenir : recevez un code de confirmation par e-mail.', 'delicat-google-login');
        else $lead = $passkey ? __('Confirmez avec une Passkey ou votre mot de passe actuel.', 'delicat-google-login') : __('Saisissez votre mot de passe actuel.', 'delicat-google-login');
        $post = esc_url(admin_url('admin-post.php'));
        // Signing in again always works (a fresh sign-in counts as recent):
        // say so to accounts without a password (Google, e-mail code) and
        // whenever the in-place methods are throttled.
        $relogin = !$recent && (in_array($status, ['locked', 'rate_limited'], true) || (!$two_factor && self::password_managed($user_id)));
        ob_start();
        echo self::card_styles(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS
        ?>
        <div class="dip-security-card dip-reauth-card" id="<?php echo esc_attr(self::ANCHOR); ?>" data-dip-reauth-card>
          <div class="dip-security-card-title"><span class="dip-security-card-icon" aria-hidden="true">↻</span><div><h3><?php esc_html_e('Confirmation d’identité', 'delicat-google-login'); ?></h3><p><?php echo $recent ? esc_html(sprintf(__('Confirmation active pour encore environ %d minutes.', 'delicat-google-login'), max(1, (int) ceil(self::seconds_remaining()/60)))) : esc_html__('Requise avant les actions sensibles : ajouter une Passkey, activer ou désactiver la 2FA, modifier l’e-mail, déconnecter des sessions ou un compte lié.', 'delicat-google-login'); ?></p></div></div>
          <?php if (isset($messages[$status]) && !($recent && $is_error)): ?><div class="dip-security-notice<?php echo $is_error ? ' is-error' : ' is-success'; ?>" role="<?php echo $is_error ? 'alert' : 'status'; ?>"><?php echo esc_html($messages[$status]); ?></div><?php endif; ?>
          <?php if ($recent && $return !== ''): ?><p class="dip-reauth-hint"><a class="dip-btn" href="<?php echo esc_url($return); ?>"><?php esc_html_e('Revenir à la page précédente', 'delicat-google-login'); ?></a></p><?php endif; ?>
          <?php if (!$recent): ?>
          <p class="dip-reauth-lead"><?php echo esc_html($lead); ?><?php if ($return !== ''): ?> <?php esc_html_e('Vous reviendrez ensuite à la page précédente pour terminer votre action.', 'delicat-google-login'); ?><?php endif; ?></p>
          <?php if ($passkey): ?>
          <div class="dip-reauth-passkey"><button type="button" class="dip-btn" data-dip-passkey-reauth><?php esc_html_e('Confirmer avec une Passkey', 'delicat-google-login'); ?></button><div class="dip-passkey-status" data-dip-passkey-status role="status" aria-live="polite" hidden></div></div>
          <div class="dip-reauth-or"><?php esc_html_e('ou', 'delicat-google-login'); ?></div>
          <?php endif; ?>
          <?php if ($two_factor): ?>
          <form method="post" action="<?php echo $post; ?>" class="dip-reauth-form" autocomplete="off">
            <input type="hidden" name="action" value="dip_reauth_confirm"><?php echo self::hidden_fields(self::NONCE_ACTION, $return); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <label><span><?php esc_html_e('Code 2FA ou code de récupération', 'delicat-google-login'); ?></span><input type="text" name="factor_code" autocomplete="one-time-code" inputmode="text" maxlength="32" required></label>
            <button type="submit" class="dip-btn primary"><?php esc_html_e('Confirmer mon identité', 'delicat-google-login'); ?></button>
          </form>
          <?php elseif ($email_code): ?>
            <?php if ($pending): ?>
            <form method="post" action="<?php echo $post; ?>" class="dip-reauth-form" autocomplete="off">
              <input type="hidden" name="action" value="dip_reauth_confirm"><?php echo self::hidden_fields(self::NONCE_ACTION, $return); // phpcs:ignore WordPress.Security.EscapeOutput ?>
              <label><span><?php echo esc_html(sprintf(__('Code reçu à %s', 'delicat-google-login'), $masked)); ?></span><input type="text" name="email_code" autocomplete="one-time-code" inputmode="numeric" maxlength="7" placeholder="123456" required></label>
              <button type="submit" class="dip-btn primary"><?php esc_html_e('Confirmer mon identité', 'delicat-google-login'); ?></button>
            </form>
            <?php endif; ?>
            <form method="post" action="<?php echo $post; ?>" class="dip-reauth-form dip-reauth-send">
              <input type="hidden" name="action" value="dip_reauth_email_code"><?php echo self::hidden_fields(self::CODE_NONCE, $return); // phpcs:ignore WordPress.Security.EscapeOutput ?>
              <button type="submit" class="dip-btn<?php echo $pending ? '' : ' primary'; ?>"><?php echo $pending ? esc_html__('Renvoyer un code', 'delicat-google-login') : esc_html(sprintf(__('Recevoir un code à %s', 'delicat-google-login'), $masked)); ?></button>
            </form>
            <details class="dip-reauth-more"><summary><?php esc_html_e('J’ai défini un mot de passe', 'delicat-google-login'); ?></summary>
            <form method="post" action="<?php echo $post; ?>" class="dip-reauth-form" autocomplete="off">
              <input type="hidden" name="action" value="dip_reauth_confirm"><?php echo self::hidden_fields(self::NONCE_ACTION, $return); // phpcs:ignore WordPress.Security.EscapeOutput ?>
              <label><span><?php esc_html_e('Mot de passe actuel', 'delicat-google-login'); ?></span><input type="password" name="current_password" autocomplete="current-password" required></label>
              <button type="submit" class="dip-btn"><?php esc_html_e('Confirmer avec le mot de passe', 'delicat-google-login'); ?></button>
            </form>
            </details>
          <?php else: ?>
          <form method="post" action="<?php echo $post; ?>" class="dip-reauth-form" autocomplete="off">
            <input type="hidden" name="action" value="dip_reauth_confirm"><?php echo self::hidden_fields(self::NONCE_ACTION, $return); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <label><span><?php esc_html_e('Mot de passe actuel', 'delicat-google-login'); ?></span><input type="password" name="current_password" autocomplete="current-password" required></label>
            <button type="submit" class="dip-btn primary"><?php esc_html_e('Confirmer mon identité', 'delicat-google-login'); ?></button>
          </form>
          <?php endif; ?>
          <?php if ($relogin): ?><p class="dip-reauth-hint"><?php echo esc_html(sprintf(_n('Vous pouvez aussi vous déconnecter puis vous reconnecter avec votre méthode habituelle (Google, code e-mail, Passkey…) : pendant %d minute, une connexion récente confirme votre identité.', 'Vous pouvez aussi vous déconnecter puis vous reconnecter avec votre méthode habituelle (Google, code e-mail, Passkey…) : pendant %d minutes, une connexion récente confirme votre identité.', (int) (self::window() / MINUTE_IN_SECONDS), 'delicat-google-login'), (int) (self::window() / MINUTE_IN_SECONDS))); ?> <a href="<?php echo esc_url(wp_logout_url()); ?>"><?php esc_html_e('Se déconnecter', 'delicat-google-login'); ?></a></p><?php endif; ?>
          <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Small scoped styles for the confirmation card and for error notices of
     * the security cards (printed once per page, next to the first card).
     */
    public static function card_styles() {
        if (self::$styles_printed) return '';
        self::$styles_printed = true;
        // Account design tokens (they switch in dark mode), with light fallbacks
        // for a card rendered outside the Identity / Security Center.
        return '<style id="dip-security-cards-css">'
            . '.dip-security-notice.is-error{border-color:#f3c9cf;border-color:color-mix(in srgb,var(--dip-danger-ink,#b3263c) 30%,transparent);background:var(--dip-danger-soft,#fdeef1);color:var(--dip-danger-ink,#b3263c)}'
            . '.dip-reauth-card input,.dip-2fa-card input{box-sizing:border-box;max-width:100%}.dip-reauth-card a.dip-btn,.dip-2fa-card a.dip-btn{text-decoration:none}'
            . '.dip-2fa-card .dip-2fa-open{text-align:center}@media (hover:hover) and (pointer:fine){.dip-2fa-card .dip-2fa-open{display:none}}'
            . '.dip-reauth-card .dip-reauth-lead{margin:10px 0 2px!important;font-size:13px!important;line-height:1.5;color:var(--dip-ink-2,#3a4563)!important}'
            . '.dip-reauth-card .dip-reauth-passkey{display:grid;gap:8px;margin-top:12px}.dip-reauth-card .dip-reauth-passkey .dip-btn,.dip-reauth-card form .dip-btn{min-height:44px;font-size:13px}'
            . '.dip-reauth-card .dip-reauth-or{margin:12px 0 0}.dip-reauth-card .dip-reauth-send{margin-top:10px}'
            . '.dip-reauth-card .dip-reauth-more{margin-top:12px;font-size:13px;color:var(--dip-muted,#596480)}.dip-reauth-card .dip-reauth-more summary{cursor:pointer;font-weight:800}'
            . '.dip-reauth-card .dip-reauth-hint{margin:12px 0 0!important;font-size:13px!important;line-height:1.55;color:var(--dip-muted,#596480)!important}.dip-reauth-card .dip-reauth-hint a{font-weight:800;color:var(--dip-accent-ink,#2a44b6)}'
            . '</style>';
    }
}
