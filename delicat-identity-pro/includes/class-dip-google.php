<?php
defined('ABSPATH') || exit;

final class DIP_Google {
    private $client_id;
    private $client_secret;
    private $callback;
    private $prompt_select_account;

    const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    const JWKS_CACHE_KEY = 'dip_google_jwks_v2';
    // Last key set fetched from Google, used only when Google cannot be
    // reached. Stale keys can only verify tokens Google really signed.
    const JWKS_BACKUP_OPTION = 'dip_google_jwks_backup';
    const JWKS_BACKUP_MAX_AGE = 7 * DAY_IN_SECONDS;

    public function __construct($client_id, $client_secret, $callback, $prompt_select_account = true) {
        $this->client_id = trim((string) $client_id);
        $this->client_secret = trim((string) $client_secret);
        $this->callback = (string) $callback;
        $this->prompt_select_account = (bool) $prompt_select_account;
    }

    public function authorization_url(array $flow) {
        $args = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->callback,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $flow['state'],
            'nonce' => $flow['nonce'],
            'code_challenge' => $flow['challenge'],
            'code_challenge_method' => 'S256',
        ];
        if ($this->prompt_select_account) $args['prompt'] = 'select_account';
        return add_query_arg($args, 'https://accounts.google.com/o/oauth2/v2/auth');
    }

    /** A transport failure or a Google 5xx is worth one more attempt. */
    private static function should_retry($response) {
        return is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 500;
    }

    public function authenticate($code, array $flow) {
        $request = [
            'timeout' => 15,
            'redirection' => 0,
            'limit_response_size' => 262144,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'code' => (string) $code,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri' => $this->callback,
                'grant_type' => 'authorization_code',
                'code_verifier' => (string) $flow['verifier'],
            ],
        ];
        $response = wp_safe_remote_post('https://oauth2.googleapis.com/token', $request);
        // Retrying cannot redeem a code twice: if Google already accepted the
        // first attempt it answers invalid_grant, the same outcome as no retry.
        if (self::should_retry($response)) {
            usleep(300000);
            $response = wp_safe_remote_post('https://oauth2.googleapis.com/token', $request);
        }
        if (is_wp_error($response)) return new WP_Error('token_transport_error', 'Impossible de contacter Google.');
        if ((int) wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('token_error', 'Google n’a pas validé la connexion.');
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > 220000) return new WP_Error('token_response_too_large', 'Réponse Google invalide.');
        $tokens = json_decode($body, true);
        if (!is_array($tokens) || empty($tokens['id_token']) || empty($tokens['access_token'])) {
            return new WP_Error('token_error', 'Réponse Google incomplète.');
        }

        // Production validation is performed locally against Google's rotating
        // public JWK set. Google's tokeninfo endpoint is explicitly documented
        // for development/debugging rather than production authentication.
        $claims = self::verify_id_token((string) $tokens['id_token'], [$this->client_id], (string) ($flow['nonce'] ?? ''));
        if (is_wp_error($claims)) return $claims;

        // Optional display data only: keep it from stretching a slow callback.
        $profile = wp_safe_remote_get('https://openidconnect.googleapis.com/v1/userinfo', [
            'timeout' => 5,
            'redirection' => 0,
            'limit_response_size' => 131072,
            'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token'], 'Accept' => 'application/json'],
        ]);
        $info = (!is_wp_error($profile) && (int) wp_remote_retrieve_response_code($profile) === 200)
            ? json_decode(wp_remote_retrieve_body($profile), true)
            : [];
        if (is_array($info) && !empty($info['sub']) && !hash_equals((string) $claims['sub'], (string) $info['sub'])) {
            return new WP_Error('userinfo_subject_mismatch', 'Le profil Google ne correspond pas au jeton.');
        }

        // Identity/security fields always come from the signed ID token. The
        // UserInfo response is used only to enrich harmless display profile data.
        $result = wp_parse_args(is_array($info) ? $info : [], $claims);
        foreach (['sub','email','email_verified','hd','iss','aud','exp','iat'] as $claim_key) {
            if (array_key_exists($claim_key, $claims)) $result[$claim_key] = $claims[$claim_key];
        }
        $result['email_authoritative'] = self::email_is_authoritative($claims);
        return $result;
    }

    /**
     * Verify a Google OpenID Connect ID token with Google's rotating public keys.
     * Returns signed claims or WP_Error. Suitable for both web and mobile flows.
     */
    public static function verify_id_token($jwt, array $allowed_audiences, $expected_nonce = '') {
        $jwt = trim((string) $jwt);
        if (strlen($jwt) < 100 || strlen($jwt) > 65536) return new WP_Error('google_jwt_format', 'Jeton Google invalide.');
        if (!function_exists('openssl_verify') || !defined('OPENSSL_ALGO_SHA256')) {
            return new WP_Error('google_openssl_missing', 'OpenSSL est requis pour vérifier la connexion Google.');
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return new WP_Error('google_jwt_format', 'Jeton Google invalide.');
        $header_raw = self::b64url_decode($parts[0]);
        $claims_raw = self::b64url_decode($parts[1]);
        $signature = self::b64url_decode($parts[2]);
        if ($header_raw === '' || $claims_raw === '' || $signature === '') return new WP_Error('google_jwt_format', 'Jeton Google invalide.');

        $header = json_decode($header_raw, true);
        $claims = json_decode($claims_raw, true);
        $kid = is_array($header) ? sanitize_text_field((string) ($header['kid'] ?? '')) : '';
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || $kid === '' || strlen($kid) > 200) {
            return new WP_Error('google_jwt_header', 'Signature Google invalide.');
        }

        // Verify the cryptographic signature before trusting any claim value.
        // We only use the untrusted header to select Google's advertised key.
        $keys = self::jwks(false);
        if (is_wp_error($keys)) return $keys;
        $jwk = self::find_key($keys, $kid);
        if (!$jwk && !get_transient(self::JWKS_CACHE_KEY . '_forced')) {
            // Google rotates keys. A stale cache must not turn rotation into a
            // prolonged outage, so refresh once when the advertised kid is new.
            // At most once a minute: junk tokens must not hammer Google.
            set_transient(self::JWKS_CACHE_KEY . '_forced', 1, MINUTE_IN_SECONDS);
            $keys = self::jwks(true);
            if (is_wp_error($keys)) return $keys;
            $jwk = self::find_key($keys, $kid);
        }
        if (!$jwk) return new WP_Error('google_key_missing', 'Clé de signature Google introuvable.');
        $pem = self::jwk_to_pem($jwk);
        if (is_wp_error($pem)) return $pem;
        $ok = openssl_verify($parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) return new WP_Error('google_signature', 'Signature Google invalide.');

        $audiences = array_values(array_unique(array_filter(array_map('trim', $allowed_audiences))));
        if (!$audiences) return new WP_Error('google_audience_missing', 'Audience Google non configurée.');
        $aud = $claims['aud'] ?? '';
        $aud_ok = false;
        if (is_string($aud)) {
            $aud_ok = in_array($aud, $audiences, true);
        } elseif (is_array($aud)) {
            foreach ($aud as $candidate) {
                if (is_string($candidate) && in_array($candidate, $audiences, true)) { $aud_ok = true; break; }
            }
            if ($aud_ok && count($aud) > 1) {
                $azp = (string) ($claims['azp'] ?? '');
                if ($azp === '' || !in_array($azp, $audiences, true)) $aud_ok = false;
            }
        }
        if (!$aud_ok) return new WP_Error('invalid_audience', 'Audience Google incorrecte.');

        if (!in_array((string) ($claims['iss'] ?? ''), ['accounts.google.com', 'https://accounts.google.com'], true)) {
            return new WP_Error('invalid_issuer', 'Émetteur Google incorrect.');
        }
        $now = time();
        if (empty($claims['exp']) || (int) $claims['exp'] < $now - 30) return new WP_Error('invalid_token_time', 'Le jeton Google a expiré.');
        if (!empty($claims['nbf']) && (int) $claims['nbf'] > $now + 60) return new WP_Error('invalid_token_time', 'Le jeton Google n’est pas encore valide.');
        if (!empty($claims['iat']) && (int) $claims['iat'] > $now + 300) return new WP_Error('invalid_token_time', 'Le jeton Google a une date invalide.');
        if ($expected_nonce !== '' && (empty($claims['nonce']) || !hash_equals((string) $expected_nonce, (string) $claims['nonce']))) {
            return new WP_Error('invalid_nonce', 'Nonce Google incorrect.');
        }

        $sub = sanitize_text_field((string) ($claims['sub'] ?? ''));
        if ($sub === '' || strlen($sub) > 255) return new WP_Error('invalid_subject', 'Identité Google invalide.');
        $email = sanitize_email($claims['email'] ?? '');
        $verified = in_array($claims['email_verified'] ?? false, [true, 1, '1', 'true'], true);
        if (!$email || !$verified) return new WP_Error('email_not_verified', 'Google n’a pas confirmé cette adresse e-mail.');

        $claims['sub'] = $sub;
        $claims['email'] = $email;
        $claims['email_verified'] = true;
        if (isset($claims['hd'])) $claims['hd'] = strtolower(substr(sanitize_text_field((string) $claims['hd']), 0, 253));
        return $claims;
    }

    /** Google only guarantees current mailbox ownership for Gmail/Workspace. */
    public static function email_is_authoritative(array $claims) {
        $email = strtolower(sanitize_email($claims['email'] ?? ''));
        $verified = in_array($claims['email_verified'] ?? false, [true, 1, '1', 'true'], true);
        if (!$email || !$verified) return false;
        if (substr($email, -10) === '@gmail.com') return true;
        $hd = strtolower(trim((string) ($claims['hd'] ?? '')));
        return $hd !== '' && strlen($hd) <= 253 && preg_match('/^[a-z0-9.-]+$/D', $hd);
    }

    private static function find_key(array $keys, $kid) {
        foreach ($keys as $key) {
            if (is_array($key) && isset($key['kid']) && hash_equals((string) $kid, (string) $key['kid'])) return $key;
        }
        return null;
    }

    private static function jwks($force_refresh = false) {
        if (!$force_refresh) {
            $cached = get_transient(self::JWKS_CACHE_KEY);
            if (is_array($cached) && !empty($cached)) return $cached;
        }

        $request = [
            'timeout' => 12,
            'redirection' => 0,
            'limit_response_size' => 307200,
            'headers' => ['Accept' => 'application/json'],
        ];
        $response = wp_safe_remote_get(self::JWKS_URL, $request);
        if (self::should_retry($response)) {
            usleep(300000);
            $response = wp_safe_remote_get(self::JWKS_URL, $request);
        }
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            $backup = get_option(self::JWKS_BACKUP_OPTION, []);
            if (is_array($backup) && !empty($backup['keys']) && is_array($backup['keys']) && time() - (int) ($backup['saved'] ?? 0) <= self::JWKS_BACKUP_MAX_AGE) {
                // Serve the last known keys briefly instead of failing every
                // sign-in while Google's key endpoint is unreachable.
                set_transient(self::JWKS_CACHE_KEY, $backup['keys'], 5 * MINUTE_IN_SECONDS);
                return $backup['keys'];
            }
            return new WP_Error('google_keys_unavailable', 'Impossible de vérifier les clés Google.');
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > 250000) return new WP_Error('google_keys_too_large', 'Réponse de clés Google invalide.');
        $json = json_decode($body, true);
        if (empty($json['keys']) || !is_array($json['keys']) || count($json['keys']) > 25) {
            return new WP_Error('google_keys_invalid', 'Clés Google invalides.');
        }

        $ttl = 6 * HOUR_IN_SECONDS;
        $cache_control = (string) wp_remote_retrieve_header($response, 'cache-control');
        if (preg_match('/(?:^|,)\s*max-age=(\d+)/i', $cache_control, $m)) {
            $ttl = min(DAY_IN_SECONDS, max(5 * MINUTE_IN_SECONDS, absint($m[1])));
        }
        set_transient(self::JWKS_CACHE_KEY, $json['keys'], $ttl);
        update_option(self::JWKS_BACKUP_OPTION, ['keys' => $json['keys'], 'saved' => time()], false);
        return $json['keys'];
    }

    private static function jwk_to_pem(array $jwk) {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) return new WP_Error('google_key_type', 'Type de clé Google non pris en charge.');
        if (!empty($jwk['use']) && $jwk['use'] !== 'sig') return new WP_Error('google_key_use', 'Usage de clé Google invalide.');
        if (!empty($jwk['alg']) && $jwk['alg'] !== 'RS256') return new WP_Error('google_key_alg', 'Algorithme de clé Google invalide.');
        $modulus = self::b64url_decode($jwk['n']);
        $exponent = self::b64url_decode($jwk['e']);
        if ($modulus === '' || $exponent === '' || strlen($modulus) < 256 || strlen($modulus) > 1024 || strlen($exponent) > 16) {
            return new WP_Error('google_key_invalid', 'Clé Google invalide.');
        }
        $rsa = self::asn1_seq(self::asn1_int($modulus) . self::asn1_int($exponent));
        $alg = hex2bin('300d06092a864886f70d0101010500');
        if ($alg === false) return new WP_Error('google_key_invalid', 'Clé Google invalide.');
        $spki = self::asn1_seq($alg . "\x03" . self::asn1_len(strlen($rsa) + 1) . "\x00" . $rsa);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function asn1_int($value) {
        $value = ltrim((string) $value, "\x00");
        if ($value === '') $value = "\x00";
        if (ord($value[0]) > 0x7f) $value = "\x00" . $value;
        return "\x02" . self::asn1_len(strlen($value)) . $value;
    }
    private static function asn1_seq($value) { return "\x30" . self::asn1_len(strlen($value)) . $value; }
    private static function asn1_len($length) {
        $length = absint($length);
        if ($length < 128) return chr($length);
        $out = '';
        while ($length > 0) { $out = chr($length & 0xff) . $out; $length >>= 8; }
        return chr(0x80 | strlen($out)) . $out;
    }
    private static function b64url_decode($value) {
        $value = strtr((string) $value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad) $value .= str_repeat('=', 4 - $pad);
        $decoded = base64_decode($value, true);
        return $decoded === false ? '' : $decoded;
    }
}
