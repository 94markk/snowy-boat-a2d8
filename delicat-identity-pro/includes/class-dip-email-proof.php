<?php
defined('ABSPATH') || exit;

/**
 * 6.17.5: the one place that answers « is this account's e-mail address
 * proven? » and records « it is now proven ». Every proof path (e-mail code,
 * magic link, the account's own verification link, Google/Microsoft) goes
 * through prove(); before this, each path carried its own copy of the rules
 * and the verification link kept a stranger's sign-up password (IDN-01).
 *
 * The browser that registered a password account carries a random HttpOnly
 * cookie (bind_registration_browser()). Its verification link opened in that
 * same browser keeps the password the registrant chose. Opened anywhere else,
 * the link is a proof by the mailbox owner, exactly like an e-mail code: what
 * was set up before the proof stops working and the owner signs in through
 * the link itself, then chooses a password.
 */
final class DIP_Email_Proof {
    const META_BROWSER = '_dip_registration_browser';
    const COOKIE_PREFIX = 'dip_regb_';
    const BROWSER_TTL = 7 * DAY_IN_SECONDS;
    /** Proofs that may come from someone other than whoever set the account up. */
    const FOREIGN_SOURCES = ['oidc', 'otp', 'magic', 'email_link_foreign'];

    /** @var array<int,bool> users whose own password a proof replaced in this request */
    private static $password_replaced = [];

    /**
     * 'proven'   the current address has a proof marker (fingerprint) and is verified;
     * 'unproven' waiting for its verification link, changed since its last
     *            proof, or registered while verification was switched off
     *            (usable for sign-in, but never proven);
     * 'legacy'   older than these markers: today's behaviour is kept.
     */
    public static function state($user_id) {
        $user_id = absint($user_id);
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user) return 'unproven';
        $verified = (string) get_user_meta($user_id, 'dip_email_verified', true);
        if ($verified === 'no') return 'unproven';
        $fingerprint = (string) get_user_meta($user_id, '_dip_verified_email_fingerprint', true);
        if ($fingerprint === '') {
            return $verified === 'yes' && (string) get_user_meta($user_id, '_dip_verified_email_proof_v2', true) === '' ? 'unproven' : 'legacy';
        }
        $current = is_email($user->user_email) ? hash('sha256', strtolower(sanitize_email($user->user_email))) : '';
        if ($current === '' || !hash_equals($fingerprint, $current)) return 'unproven';
        return $verified === 'yes' ? 'proven' : 'legacy';
    }

    public static function is_proven($user_id) {
        return self::state($user_id) === 'proven';
    }

    public static function is_unproven($user_id) {
        return self::state($user_id) === 'unproven';
    }

    /** Days during which an unproven account still counts as possibly pre-registered by someone else. */
    const PRE_HIJACK_DAYS = 30;
    /** 6.17.6: when the current, unproven address arrived on the account (an e-mail change), as a Unix time. */
    const UNPROVEN_SINCE = '_dip_email_unproven_since';

    /**
     * Whether a proof of the mailbox (a code, a magic link, Google, the link opened in another browser) resets
     * the credentials set up before it (account pre-hijacking). Always for an account still waiting for its
     * verification link; for any other unproven account (created while verification was off, or never
     * fingerprinted) only while it is young, the window in which a stranger could have registered the address
     * first. A long-standing customer's password and devices are never wiped by signing in with a code.
     */
    public static function awaiting_verification($user_id) {
        $user_id = absint($user_id);
        if ('no' === (string) get_user_meta($user_id, 'dip_email_verified', true)) return true;
        if (!self::is_unproven($user_id)) return false;
        $user = get_userdata($user_id);
        $registered = $user ? strtotime($user->user_registered . ' UTC') : 0;
        // 6.17.6: the window runs from when this address arrived on the account, not from the registration: an
        // old account whose e-mail was just changed (verification off) to someone else's unregistered Gmail is
        // as young as a new registration for that address.
        $start = max($registered, absint(get_user_meta($user_id, self::UNPROVEN_SINCE, true)));
        return $start > 0 && (time() - $start) < self::PRE_HIJACK_DAYS * DAY_IN_SECONDS;
    }

    /**
     * Credentials an unproven account carries that a proof does not end
     * (passkeys, an authenticator app, provider identities). They belong to
     * whoever set the account up, so a proof by someone else is refused
     * instead of handing them the account. The identity performing this very
     * proof ($provider/$sub) is not counted.
     */
    public static function foreign_credentials($user_id, $provider = '', $sub = '') {
        $user_id = absint($user_id);
        if (!$user_id) return [];
        $provider = sanitize_key((string) $provider);
        $sub = (string) $sub;
        $found = [];
        if (class_exists('DIP_Passkeys') && method_exists('DIP_Passkeys', 'has_passkeys') && DIP_Passkeys::has_passkeys($user_id)) $found[] = 'passkey';
        if (class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user_id)) $found[] = 'totp';
        $keys = class_exists('DIP_Identity') ? ['google' => DIP_Identity::META_SUB, 'microsoft' => DIP_Identity::meta_sub_key('microsoft')] : [];
        if (class_exists('DIP_Connected_Accounts')) {
            foreach ((array) DIP_Connected_Accounts::providers() as $id => $item) {
                if (is_array($item) && !empty($item['meta_sub']) && is_string($item['meta_sub'])) $keys[sanitize_key((string) $id)] = $item['meta_sub'];
            }
        }
        foreach ($keys as $id => $key) {
            $current = (string) get_user_meta($user_id, $key, true);
            if ($current === '' || ($provider !== '' && $id === $provider && $sub !== '' && hash_equals($current, $sub))) continue;
            $found[] = $id;
        }
        return array_values(array_unique($found));
    }

    /**
     * Record that the account's current address is proven. $source is
     * 'email_link' (its verification link, in the browser that registered),
     * 'email_link_foreign' (the same link opened elsewhere), 'otp', 'magic',
     * 'oidc' (Google adopted or created the account from its own proof),
     * 'linked_provider' (an identity already linked to this account) or
     * 'unproven' (verification switched off: usable, never proven).
     *
     * Returns true, false (address mismatch) or a WP_Error when an unproven
     * account carries credentials that a foreign proof cannot take over.
     */
    public static function prove($user_id, $email, $source, $provider = '', $sub = '') {
        $user_id = absint($user_id);
        $source = sanitize_key((string) $source);
        if (!$user_id || !class_exists('DIP_Account_Sync')) return false;
        // A foreign proof of an unproven account that carries credentials a proof cannot end (passkeys, TOTP,
        // another provider identity) is refused, whatever the account's age (6.17.7, security review): whoever
        // set them up may not own the mailbox. Only the reset itself follows awaiting_verification().
        if (in_array($source, self::FOREIGN_SOURCES, true) && DIP_Account_Sync::is_customer_account($user_id) && self::is_unproven($user_id)) {
            $found = self::foreign_credentials($user_id, $provider, $sub);
            if ($found) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('unverified_account_merge_refused', 'warning', $user_id, ['method'=>$source, 'credentials'=>implode(',', $found)]);
                return new WP_Error('unverified_account_conflict', __('Cette adresse attend sa confirmation sur un compte existant. Si ce compte est le vôtre, confirmez l’adresse avec le lien de vérification reçu par e-mail ; sinon, contactez le support.', 'delicat-google-login'));
            }
        }
        $ok = DIP_Account_Sync::after_verified_registration($user_id, $email, $source);
        if ($ok && $source !== 'unproven') {
            // The address is proven: pending verification links are spent and
            // the registration browser binding has done its job.
            delete_user_meta($user_id, '_dip_email_verify_hash');
            delete_user_meta($user_id, '_dip_email_verify_email_hash');
            delete_user_meta($user_id, '_dip_email_verify_expires');
            delete_user_meta($user_id, self::UNPROVEN_SINCE);
            self::forget_registration_browser($user_id);
        }
        return $ok;
    }

    /** Called by DIP_Account_Sync when a proof replaced the password the account had. */
    public static function note_password_replaced($user_id) {
        self::$password_replaced[absint($user_id)] = true;
    }

    /** Did a proof in this request replace a password the registrant had chosen? */
    public static function password_replaced($user_id) {
        return !empty(self::$password_replaced[absint($user_id)]);
    }

    private static function cookie_name($user_id) {
        return self::COOKIE_PREFIX . substr(hash_hmac('sha256', 'registration-browser|' . absint($user_id), wp_salt('nonce')), 0, 16);
    }

    private static function hash_secret($secret) {
        return hash_hmac('sha256', 'registration-browser|' . (string) $secret, wp_salt('secure_auth'));
    }

    private static function set_cookie($user_id, $value, $expires) {
        if (headers_sent()) return false;
        $name = self::cookie_name($user_id);
        $ok = setcookie($name, (string) $value, [
            'expires' => (int) $expires,
            'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($ok) {
            if ((string) $value === '') unset($_COOKIE[$name]);
            else $_COOKIE[$name] = (string) $value;
        }
        return $ok;
    }

    /** Remember the browser that registered this account and chose its password. */
    public static function bind_registration_browser($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return false;
        try {
            $secret = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            return false;
        }
        $expires = time() + self::BROWSER_TTL;
        if (!self::set_cookie($user_id, $secret, $expires)) return false;
        update_user_meta($user_id, self::META_BROWSER, ['hash' => self::hash_secret($secret), 'expires' => $expires]);
        return true;
    }

    /**
     * Is this request coming from the browser that registered the account?
     * The browser bound to a provider sign-up waiting for this proof
     * (DIP_Identity pending link) counts too.
     */
    public static function registration_browser_matches($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return false;
        $bound = get_user_meta($user_id, self::META_BROWSER, true);
        $cookie = $_COOKIE[self::cookie_name($user_id)] ?? '';
        $cookie = is_string($cookie) ? sanitize_text_field(wp_unslash($cookie)) : '';
        if (is_array($bound) && !empty($bound['hash']) && (int) ($bound['expires'] ?? 0) > time()
            && preg_match('/^[a-f0-9]{64}$/D', $cookie) && hash_equals((string) $bound['hash'], self::hash_secret($cookie))) {
            return true;
        }
        return class_exists('DIP_Identity') && method_exists('DIP_Identity', 'pending_link_browser_matches') && DIP_Identity::pending_link_browser_matches($user_id);
    }

    public static function forget_registration_browser($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return;
        if (get_user_meta($user_id, self::META_BROWSER, true) !== '') delete_user_meta($user_id, self::META_BROWSER);
        if (isset($_COOKIE[self::cookie_name($user_id)])) self::set_cookie($user_id, '', time() - HOUR_IN_SECONDS);
    }
}
