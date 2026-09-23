<?php
defined('ABSPATH') || exit;

final class DIP_Flow_Store {
    const COOKIE = 'dip_flow';
    const TTL = 600;

    public static function create($redirect, $link_user, $provider = 'google', $tracker = '') {
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = self::base64url(random_bytes(64));
        $browser = self::base64url(random_bytes(32));
        self::set_cookie($state, $browser, time() + self::TTL);
        $payload = [
            'nonce' => $nonce,
            'verifier' => $verifier,
            'redirect' => $redirect,
            'link_user' => absint($link_user),
            'provider' => sanitize_key($provider),
            'tracker' => substr(sanitize_text_field((string) $tracker), 0, 100),
            'created' => time(),
            'browser_hash' => hash_hmac('sha256', $browser, wp_salt('nonce')),
        ];
        set_transient(self::key($state), $payload, self::TTL);
        return [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'challenge' => self::base64url(hash('sha256', $verifier, true)),
        ];
    }

    public static function consume($state) {
        $state = (string) $state;
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) {
            return new WP_Error('invalid_state', 'Session de connexion invalide.');
        }
        $key = self::key($state);
        $payload = get_transient($key);
        if (!is_array($payload) || empty($payload['created']) || (time() - (int) $payload['created']) > self::TTL) {
            delete_transient($key);
            self::clear_cookie($state);
            return new WP_Error('invalid_state', 'Session de connexion expirée.');
        }
        $cookie_name = self::cookie_name($state);
        $host_cookie_name = self::host_cookie_name($state);
        $browser = isset($_COOKIE[$cookie_name]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_name])) : '';
        // 6.9.10 host-only fallback: some WordPress/domain configurations can
        // scope COOKIE_DOMAIN/COOKIEPATH differently from the public Home URL.
        // The fallback uses a separate cookie name and the same random browser
        // secret, so the stored HMAC binding remains mandatory and unchanged.
        if ($browser === '' && isset($_COOKIE[$host_cookie_name])) $browser = sanitize_text_field(wp_unslash($_COOKIE[$host_cookie_name]));
        // One-release fallback for OAuth flows started before the hardened
        // state-specific cookie upgrade. New flows never write this legacy name.
        if ($browser === '' && isset($_COOKIE[self::COOKIE])) $browser = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE]));
        if (!preg_match('/^[A-Za-z0-9_-]{40,128}$/D', $browser) || empty($payload['browser_hash'])) {
            return new WP_Error('browser_binding_failed', 'La tentative ne provient pas du navigateur d’origine.');
        }
        $actual = hash_hmac('sha256', $browser, wp_salt('nonce'));
        if (!hash_equals((string) $payload['browser_hash'], $actual)) {
            return new WP_Error('browser_binding_failed', 'La tentative ne provient pas du navigateur d’origine.');
        }
        // Consume the one-time state only after the browser binding succeeds.
        // A callback opened by an Android custom tab before its cookie is visible
        // must not destroy the still-valid flow for the originating browser.
        // Keep a database-backed consumed marker until the state has expired.
        // Deleting a transient alone is not atomic: concurrent workers may have
        // already cached the same payload before either callback consumes it.
        $lock = 'dip_flow_claim_' . hash('sha256', $state);
        if (!add_option($lock, time(), '', false)) return new WP_Error('invalid_state', 'Cette connexion est déjà en cours.');
        wp_schedule_single_event(time() + self::TTL + 60, 'dip_flow_claim_cleanup', [$lock]);
        delete_transient($key);
        self::clear_cookie($state);
        return $payload;
    }

    /**
     * Return only the safe presentation context required to recover a failed
     * callback. Secrets, PKCE material, nonce and browser binding never leave
     * this store, and the one-time state is not consumed by this lookup.
     */
    public static function recovery_context($state) {
        $state = (string) $state;
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) return [];
        $payload = get_transient(self::key($state));
        if (!is_array($payload) || empty($payload['created']) || (time() - (int) $payload['created']) > self::TTL) return [];
        return [
            'redirect' => isset($payload['redirect']) ? (string) $payload['redirect'] : '',
            'tracker' => substr(sanitize_text_field((string) ($payload['tracker'] ?? '')), 0, 100),
        ];
    }

    public static function cleanup_claim($lock) {
        if (!is_string($lock) || !preg_match('/^dip_flow_claim_[a-f0-9]{64}$/D', $lock)) return;
        $created = (int)get_option($lock, 0);
        if ($created && time() - $created > self::TTL) delete_option($lock);
    }

    private static function key($state) {
        return 'dip_state_' . hash('sha256', (string) $state);
    }

    private static function base64url($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function cookie_name($state) {
        return self::COOKIE . '_' . substr(hash('sha256', (string) $state), 0, 16);
    }

    private static function host_cookie_name($state) {
        return self::cookie_name($state) . '_h';
    }

    private static function home_cookie_path() {
        $path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if ($path === '') $path = '/';
        if ($path[0] !== '/') $path = '/' . $path;
        return trailingslashit($path);
    }

    private static function set_cookie($state, $value, $expires) {
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $name = self::cookie_name($state);
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => $path,
            'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $value;

        // Separate host-only fallback for the exact public Home URL scope.
        // It never replaces verification: consume() still requires the same
        // payload browser_hash HMAC and one-time OAuth state.
        $host_name = self::host_cookie_name($state);
        setcookie($host_name, $value, [
            'expires' => $expires,
            'path' => self::home_cookie_path(),
            'domain' => '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$host_name] = $value;
    }

    private static function expire_cookie_name($name, $host_only = false) {
        $path = $host_only ? self::home_cookie_path() : (defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/');
        setcookie($name, '', [
            'expires' => time() - HOUR_IN_SECONDS,
            'path' => $path,
            'domain' => $host_only ? '' : (defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : ''),
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[$name]);
    }

    private static function clear_cookie($state) {
        self::expire_cookie_name(self::cookie_name($state));
        self::expire_cookie_name(self::host_cookie_name($state), true);
        if (isset($_COOKIE[self::COOKIE])) self::expire_cookie_name(self::COOKIE);
    }
}
