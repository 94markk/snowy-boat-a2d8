<?php
defined('ABSPATH') || exit;

final class DIP_Flow_Store {
    const COOKIE = 'dip_flow';
    // Long enough for Google's account chooser, 2-Step Verification or an SMS
    // code to arrive on a slow mobile connection. The state stays single-use
    // and bound to the originating browser.
    const TTL = 1800;
    // A duplicate callback (double tap, prefetch, custom-tab replay) may reuse
    // the completed outcome for this long, from the same browser only.
    const REPLAY_WINDOW = 120;
    // A duplicate may wait this long after the claim for a first callback
    // that is still running (token exchange, retries, account creation).
    const CLAIM_WAIT_WINDOW = 30;
    // Flow rows live in wp_options, not in the object cache: a Redis or
    // Memcached flush or eviction used to lose every sign-in in progress.
    const ROW_PREFIX = 'dip_flowstate_';

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
        if (!self::insert_row(self::row_name($state), $payload)) set_transient(self::key($state), $payload, self::TTL);
        if (wp_rand(1, 25) === 1) self::collect_garbage();
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
        $payload = self::load($state);
        if (!is_array($payload) || empty($payload['created']) || (time() - (int) $payload['created']) > self::TTL) {
            // The first callback for this state already consumed it.
            if (self::read_claim(self::claim_name($state)) !== null) {
                return new WP_Error('state_already_used', 'Cette connexion a déjà été traitée.');
            }
            self::forget($state);
            self::clear_cookie($state);
            return new WP_Error('invalid_state', 'Session de connexion expirée.');
        }
        $browser = self::browser_secret($state);
        if ($browser === '' || empty($payload['browser_hash'])) {
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
        $lock = self::claim_name($state);
        if (!self::insert_row($lock, ['claimed' => time(), 'browser_hash' => (string) $payload['browser_hash']])) {
            return new WP_Error('state_already_used', 'Cette connexion est déjà en cours.');
        }
        self::forget($state);
        self::clear_cookie($state);
        return $payload;
    }

    /**
     * Remember how a consumed flow ended so a duplicate callback for the same
     * state can show the same result instead of a misleading error.
     * $outcome: session (user signed in), redirect (2FA/link destination) or failed.
     */
    public static function complete($state, array $record) {
        $lock = self::claim_name($state);
        $claim = self::read_claim($lock);
        if ($claim === null) return;
        $record = array_intersect_key($record, array_flip(['outcome', 'user', 'destination', 'remember', 'code']));
        self::write_claim($lock, array_merge($claim, $record, ['completed' => time()]));
    }

    /**
     * Outcome of an already consumed state. `browser_ok` is true only when this
     * request still carries the originating browser's binding cookie. Only
     * that browser may wait (briefly, right after the claim) for a concurrent
     * first callback to finish, so replays cannot hold PHP workers.
     */
    public static function completion($state, $wait_seconds = 0) {
        $state = (string) $state;
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) return null;
        $lock = self::claim_name($state);
        $claim = self::read_claim($lock);
        if ($claim === null) return null;
        $browser = self::browser_secret($state);
        $browser_ok = $browser !== '' && !empty($claim['browser_hash'])
            && hash_equals((string) $claim['browser_hash'], hash_hmac('sha256', $browser, wp_salt('nonce')));
        if ($browser_ok) {
            $deadline = min(microtime(true) + max(0, (float) $wait_seconds), (float) ($claim['claimed'] ?? 0) + self::CLAIM_WAIT_WINDOW);
            while (empty($claim['completed']) && microtime(true) < $deadline) {
                usleep(250000);
                $claim = self::read_claim($lock);
                if ($claim === null) return null;
            }
        }
        $claim['browser_ok'] = $browser_ok;
        return $claim;
    }

    /** A completed session may be delivered to a duplicate callback only once. */
    public static function claim_redelivery($state) {
        return self::insert_row(self::claim_name((string) $state) . '_r', time());
    }

    /**
     * Return only the safe presentation context required to recover a failed
     * callback. Secrets, PKCE material, nonce and browser binding never leave
     * this store, and the one-time state is not consumed by this lookup.
     */
    public static function recovery_context($state) {
        $state = (string) $state;
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) return [];
        $payload = self::load($state);
        if (!is_array($payload) || empty($payload['created']) || (time() - (int) $payload['created']) > self::TTL) return [];
        return [
            'redirect' => isset($payload['redirect']) ? (string) $payload['redirect'] : '',
            'tracker' => substr(sanitize_text_field((string) ($payload['tracker'] ?? '')), 0, 100),
        ];
    }

    /** Cleanup events scheduled by versions up to 6.9.19; claims are now swept by collect_garbage(). */
    public static function cleanup_claim($lock) {
        self::collect_garbage();
    }

    private static function row_name($state) {
        return self::ROW_PREFIX . hash('sha256', (string) $state);
    }

    /** Stored flow payload; transients only hold flows started before 6.9.20 or when the row write failed. */
    private static function load($state) {
        $payload = self::read_row(self::row_name($state));
        return is_array($payload) ? $payload : get_transient(self::key($state));
    }

    private static function forget($state) {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => self::row_name($state)]);
        wp_cache_delete(self::row_name($state), 'options');
        delete_transient(self::key($state));
    }

    /**
     * Remove expired flow rows (customers who never came back from the
     * provider) and claims whose state can no longer be used. Oldest first.
     */
    private static function collect_garbage() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_id ASC LIMIT 200",
            $wpdb->esc_like(self::ROW_PREFIX) . '%',
            $wpdb->esc_like('dip_flow_claim_') . '%'
        ));
        foreach ((array) $rows as $row) {
            $value = maybe_unserialize($row->option_value);
            if (strpos($row->option_name, self::ROW_PREFIX) === 0) {
                $expired = !is_array($value) || time() - (int) ($value['created'] ?? 0) > self::TTL;
            } else {
                // Claim arrays live as long as their state could; bare timestamps
                // (re-delivery markers, 6.9.19 claims) only need the old 600 s.
                $expired = is_array($value)
                    ? time() - (int) ($value['claimed'] ?? 0) > self::TTL + 60
                    : time() - (int) $value > 660;
            }
            if ($expired) {
                $wpdb->delete($wpdb->options, ['option_name' => $row->option_name]);
                wp_cache_delete($row->option_name, 'options');
            }
        }
    }

    private static function key($state) {
        return 'dip_state_' . hash('sha256', (string) $state);
    }

    private static function claim_name($state) {
        return 'dip_flow_claim_' . hash('sha256', (string) $state);
    }

    /**
     * Flow rows and claims bypass the options API on purpose: add_option() is an upsert, so
     * two concurrent callbacks could both "win", and a cached notoptions entry
     * would hide a row written by another worker. INSERT IGNORE is atomic.
     */
    private static function insert_row($name, $value) {
        global $wpdb;
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            maybe_serialize($value)
        ));
        wp_cache_delete($name, 'options');
        return (int) $inserted === 1;
    }

    private static function read_row($name) {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
        return $raw === null ? null : maybe_unserialize($raw);
    }

    private static function read_claim($name) {
        $value = self::read_row($name);
        if ($value === null) return null;
        // Claims written before 6.9.20 only stored the claim timestamp.
        return is_array($value) ? $value : ['claimed' => (int) $value];
    }

    private static function write_claim($name, array $value) {
        global $wpdb;
        $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($value)], ['option_name' => $name]);
        wp_cache_delete($name, 'options');
    }

    /** Random browser secret for this state, or '' when no binding cookie is present. */
    private static function browser_secret($state) {
        $browser = '';
        // 6.9.10 host-only fallback: some WordPress/domain configurations can
        // scope COOKIE_DOMAIN/COOKIEPATH differently from the public Home URL.
        // The fallback uses a separate cookie name and the same random browser
        // secret, so the stored HMAC binding remains mandatory and unchanged.
        foreach ([self::cookie_name($state), self::host_cookie_name($state)] as $name) {
            if (isset($_COOKIE[$name]) && is_string($_COOKIE[$name])) {
                $browser = sanitize_text_field(wp_unslash($_COOKIE[$name]));
                if ($browser !== '') break;
            }
        }
        return preg_match('/^[A-Za-z0-9_-]{40,128}$/D', $browser) ? $browser : '';
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
    }
}
