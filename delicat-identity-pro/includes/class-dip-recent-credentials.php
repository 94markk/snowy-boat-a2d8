<?php
defined('ABSPATH') || exit;

/**
 * 6.17.5 (IDN-07): « Mot de passe oublié » and « Déconnecter les autres
 * sessions » ended sessions, but a passkey or a Google/Microsoft link that an
 * intruder had added kept letting them back in. After those actions, the
 * passkeys and provider links added in the last 7 days are removed (older
 * ones stay), and the customer gets an e-mail listing what was removed with a
 * link to review the rest. The exact credential that opened the current
 * session (or re-confirmed it since) is never removed: it is recorded in the
 * session itself at sign-in (passkey id, Google/Microsoft).
 */
final class DIP_Recent_Credentials {
    const WINDOW = 7 * DAY_IN_SECONDS;
    /** Seconds of tolerance between a sign-in and the session it opened (sessions without a record). */
    const SESSION_SLACK = 120;
    const SESSION_FIELD = 'dip_credentials';

    /** @var array<int,string> session tokens issued in this request */
    private static $issued = [];
    private static $pending_passkey = '';

    public static function init() {
        add_action('set_logged_in_cookie', [__CLASS__, 'capture_token'], 10, 6);
        add_action('wp_login', [__CLASS__, 'stamp_signin'], 110, 2);
    }

    public static function capture_token($cookie = '', $expire = 0, $expiration = 0, $user_id = 0, $scheme = '', $token = '') {
        $user_id = absint($user_id);
        if ($user_id && is_string($token) && $token !== '') self::$issued[$user_id] = $token;
    }

    /** The passkey whose assertion is signing in now (before the session is created). */
    public static function note_passkey_signin($credential_id) {
        self::$pending_passkey = is_string($credential_id) ? $credential_id : '';
    }

    /** Record in the new session which credential opened it (passkey or provider sign-in). */
    public static function stamp_signin($user_login, $user) {
        if (!($user instanceof WP_User)) return;
        $token = (string) (self::$issued[$user->ID] ?? '');
        $method = class_exists('DIP_Account_Sync') && method_exists('DIP_Account_Sync', 'wp_login_context') ? (string) DIP_Account_Sync::wp_login_context() : '';
        $credential = '';
        if ($method === 'passkey' && self::$pending_passkey !== '') $credential = 'passkey:' . self::$pending_passkey;
        elseif (preg_match('/^([a-z0-9]+)_social$/D', $method, $m)) $credential = 'provider:' . $m[1];
        self::$pending_passkey = '';
        if ($token !== '' && $credential !== '') self::add_to_session($user->ID, $token, $credential);
    }

    /** The current session was re-confirmed with this passkey (identity confirmation). */
    public static function confirm_with_passkey($user_id, $credential_id) {
        if (!function_exists('wp_get_session_token') || !is_string($credential_id) || $credential_id === '') return;
        $token = (string) wp_get_session_token();
        if ($token !== '') self::add_to_session(absint($user_id), $token, 'passkey:' . $credential_id);
    }

    private static function add_to_session($user_id, $token, $credential) {
        if (!$user_id || !class_exists('WP_Session_Tokens')) return;
        try {
            $manager = WP_Session_Tokens::get_instance($user_id);
            $session = $manager->get($token);
            if (!is_array($session)) return;
            $list = isset($session[self::SESSION_FIELD]) && is_array($session[self::SESSION_FIELD]) ? $session[self::SESSION_FIELD] : [];
            $list[] = (string) $credential;
            $session[self::SESSION_FIELD] = array_slice(array_values(array_unique($list)), -10);
            $manager->update($token, $session);
        } catch (Throwable $e) {}
    }

    public static function first_linked_key($provider) {
        return '_dip_' . sanitize_key((string) $provider) . '_first_linked';
    }

    /** Called when a provider identity is newly linked to an account. */
    public static function note_link($user_id, $provider) {
        $user_id = absint($user_id);
        if ($user_id) update_user_meta($user_id, self::first_linked_key($provider), time());
    }

    /** The current session record ([] when there is none). */
    private static function current_session($user_id) {
        if (!class_exists('WP_Session_Tokens') || !function_exists('wp_get_session_token') || get_current_user_id() !== absint($user_id)) return [];
        $token = (string) wp_get_session_token();
        if ($token === '') return [];
        try { $session = WP_Session_Tokens::get_instance(absint($user_id))->get($token); } catch (Throwable $e) { $session = null; }
        return is_array($session) ? $session : [];
    }

    /**
     * Does this credential belong to the current session? Recorded: only the
     * exact passkey/provider that opened or re-confirmed it. Not recorded
     * (session older than 6.17.5): only a credential last used within
     * SESSION_SLACK of the session's sign-in, never one used later.
     */
    private static function opened_session($credential, $last_used, array $session) {
        if (!$session) return false;
        $recorded = isset($session[self::SESSION_FIELD]) && is_array($session[self::SESSION_FIELD]) ? $session[self::SESSION_FIELD] : [];
        if ($recorded) return in_array($credential, $recorded, true);
        $login = (int) ($session['login'] ?? 0);
        return $login > 0 && (int) $last_used > 0 && abs((int) $last_used - $login) <= self::SESSION_SLACK;
    }

    private static function providers() {
        $providers = class_exists('DIP_Connected_Accounts') ? (array) DIP_Connected_Accounts::providers() : [];
        $out = [];
        foreach ($providers as $id => $item) {
            if (is_array($item) && !empty($item['meta_sub']) && !empty($item['meta_linked'])) $out[sanitize_key((string) $id)] = $item;
        }
        return $out;
    }

    /**
     * Remove what was added in the last 7 days. $keep_current: the request
     * belongs to a signed-in session that stays open (« déconnecter les
     * autres »): what opened or confirmed it stays. Returns the removed labels.
     */
    public static function cleanup($user_id, $reason, $keep_current = false) {
        $user_id = absint($user_id);
        if (!$user_id || !get_userdata($user_id)) return [];
        $since = time() - self::WINDOW;
        $session = $keep_current ? self::current_session($user_id) : [];
        $removed = [];

        if (class_exists('DIP_Passkeys') && method_exists('DIP_Passkeys', 'credentials')) {
            $keep = [];
            $dropped = 0;
            foreach (DIP_Passkeys::credentials($user_id) as $entry) {
                $recent = (int) $entry['created'] > $since;
                $in_use = self::opened_session('passkey:' . $entry['id'], $entry['last_used'], $session);
                if ($recent && !$in_use) {
                    $removed[] = sprintf(__('Passkey « %1$s » ajoutée le %2$s', 'delicat-google-login'), $entry['label'], wp_date('d/m/Y H:i', (int) $entry['created']));
                    $dropped++;
                    continue;
                }
                $keep[] = $entry;
            }
            if ($dropped) {
                DIP_Passkeys::save_credentials($user_id, $keep);
                if (class_exists('DIP_Audit')) DIP_Audit::record('passkey_removed', 'warning', $user_id, ['reason'=>sanitize_key($reason), 'count'=>$dropped]);
            }
        }

        foreach (self::providers() as $provider => $item) {
            $sub = (string) get_user_meta($user_id, $item['meta_sub'], true);
            $first = absint(get_user_meta($user_id, self::first_linked_key($provider), true));
            if ($sub === '' || $first <= $since) continue;
            // A provider sign-in refreshes meta_linked (its last use).
            if (self::opened_session('provider:' . $provider, absint(get_user_meta($user_id, $item['meta_linked'], true)), $session)) continue;
            if (class_exists('DIP_Identity')) DIP_Identity::remember_disconnected_subject($user_id, $provider, $sub);
            delete_user_meta($user_id, $item['meta_sub']);
            delete_user_meta($user_id, $item['meta_linked']);
            delete_user_meta($user_id, self::first_linked_key($provider));
            if ($provider === 'google') {
                if (class_exists('DIP_Identity')) delete_user_meta($user_id, DIP_Identity::META_AVATAR);
                delete_user_meta($user_id, '_dip_google_explicit_link_v2');
            }
            do_action('dip_account_disconnected', $user_id, $provider, $sub);
            if (class_exists('DIP_Audit')) DIP_Audit::record('account_disconnected', 'warning', $user_id, ['provider'=>$provider, 'reason'=>sanitize_key($reason)]);
            $removed[] = sprintf(__('Compte %1$s relié le %2$s', 'delicat-google-login'), sanitize_text_field((string) ($item['label'] ?? $provider)), wp_date('d/m/Y H:i', $first));
        }
        if (class_exists('DIP_Identity') && method_exists('DIP_Identity', 'clear_pending_provider_link')) DIP_Identity::clear_pending_provider_link($user_id);

        if ($removed) self::notify($user_id, $removed, $reason);
        return $removed;
    }

    private static function review_url() {
        if (function_exists('wc_get_account_endpoint_url') && class_exists('DIP_Customer_Dashboard')) {
            return add_query_arg('dip_tab', 'security', wc_get_account_endpoint_url(DIP_Customer_Dashboard::ENDPOINT));
        }
        return home_url('/my-account/');
    }

    private static function notify($user_id, array $removed, $reason) {
        $intro = $reason === 'password_reset'
            ? __('Votre mot de passe vient d’être réinitialisé.', 'delicat-google-login')
            : __('Les autres sessions de votre compte viennent d’être déconnectées.', 'delicat-google-login');
        $subject = __('Accès récents retirés de votre compte Delicat', 'delicat-google-login');
        $message = $intro . ' ' . __('Par sécurité, les accès ajoutés ces 7 derniers jours ont été retirés :', 'delicat-google-login') . ' ' . implode(' ; ', $removed) . '.';
        $advice = __('Si c’était vous, ajoutez-les de nouveau depuis votre compte. Vérifiez aussi les accès plus anciens qui restent actifs (passkeys, comptes Google ou Microsoft reliés, appareils).', 'delicat-google-login');
        if (class_exists('DIP_Email_Hub')) {
            DIP_Email_Hub::send_user_security($user_id, $subject, $message, ['title'=>__('Accès récents retirés', 'delicat-google-login'), 'badge'=>__('Sécurité du compte', 'delicat-google-login'), 'accent'=>'#d97706', 'advice'=>$advice, 'cta_label'=>__('Vérifier mes accès', 'delicat-google-login'), 'cta_url'=>self::review_url()]);
            return;
        }
        $user = get_userdata($user_id);
        if ($user && is_email($user->user_email)) wp_mail($user->user_email, $subject, $message . "\n\n" . $advice . "\n" . self::review_url());
    }
}
