<?php
defined('ABSPATH') || exit;

final class DIP_Microsoft {
    private $client_id;
    private $client_secret;
    private $tenant;
    private $redirect_uri;

    public function __construct($client_id, $client_secret, $tenant, $redirect_uri) {
        $this->client_id = trim((string) $client_id);
        $this->client_secret = trim((string) $client_secret);
        $this->tenant = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $tenant) ?: 'common';
        $this->redirect_uri = esc_url_raw($redirect_uri);
    }

    public function authorization_url(array $flow) {
        return add_query_arg([
            'client_id' => $this->client_id,
            'response_type' => 'code',
            'redirect_uri' => $this->redirect_uri,
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $flow['state'],
            'nonce' => $flow['nonce'],
            'code_challenge' => $flow['challenge'],
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], 'https://login.microsoftonline.com/' . rawurlencode($this->tenant) . '/oauth2/v2.0/authorize');
    }

    public function authenticate($code, array $flow) {
        $response = wp_safe_remote_post('https://login.microsoftonline.com/' . rawurlencode($this->tenant) . '/oauth2/v2.0/token', [
            'timeout' => 15,
            'redirection' => 0,
            'limit_response_size' => 262144,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'authorization_code',
                'code' => (string) $code,
                'redirect_uri' => $this->redirect_uri,
                'code_verifier' => (string) ($flow['verifier'] ?? ''),
                'scope' => 'openid profile email',
            ],
        ]);
        if (is_wp_error($response)) return new WP_Error('microsoft_token_http', 'Microsoft est temporairement indisponible.');
        if ((int) wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('microsoft_token_rejected', 'Microsoft a refusé la connexion.');
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > 100000) return new WP_Error('microsoft_response_too_large', 'Réponse Microsoft invalide.');
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['id_token'])) return new WP_Error('microsoft_missing_id_token', 'Jeton Microsoft manquant.');
        return $this->verify_id_token($json['id_token'], (string) ($flow['nonce'] ?? ''));
    }

    private function verify_id_token($jwt, $expected_nonce) {
        $jwt = (string) $jwt;
        if ($jwt === '' || strlen($jwt) > 65536) return new WP_Error('microsoft_jwt_format', 'Jeton Microsoft invalide.');
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return new WP_Error('microsoft_jwt_format', 'Jeton Microsoft invalide.');
        $header = json_decode($this->b64url_decode($parts[0]), true);
        $claims = json_decode($this->b64url_decode($parts[1]), true);
        $kid = is_array($header) ? sanitize_text_field((string) ($header['kid'] ?? '')) : '';
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || $kid === '' || strlen($kid) > 200) return new WP_Error('microsoft_jwt_header', 'Signature Microsoft invalide.');
        $tenant_id = strtolower(sanitize_text_field((string) ($claims['tid'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $tenant_id)) {
            return new WP_Error('microsoft_tenant_missing', 'Locataire Microsoft invalide.');
        }
        if (!in_array($this->tenant, ['common','organizations','consumers'], true) && !hash_equals(strtolower($this->tenant), strtolower($tenant_id))) return new WP_Error('microsoft_tenant_mismatch', 'Ce compte Microsoft n’est pas autorisé.');

        // The tenant id is needed to locate Microsoft's public key set, but no
        // identity/authorization claim is trusted until the RS256 signature is valid.
        $keys = $this->jwks($tenant_id);
        if (is_wp_error($keys)) return $keys;
        $jwk = null;
        foreach ($keys as $key) if (isset($key['kid']) && hash_equals($kid, (string) $key['kid'])) { $jwk = $key; break; }
        if (!$jwk) return new WP_Error('microsoft_key_missing', 'Clé de signature Microsoft introuvable.');
        $pem = $this->jwk_to_pem($jwk);
        if (is_wp_error($pem)) return $pem;
        if (!function_exists('openssl_verify') || !defined('OPENSSL_ALGO_SHA256')) {
            return new WP_Error('microsoft_openssl_missing', 'OpenSSL est requis pour vérifier la connexion Microsoft.');
        }
        $signature = $this->b64url_decode($parts[2]);
        if ($signature === '') return new WP_Error('microsoft_signature', 'Signature Microsoft invalide.');
        $ok = openssl_verify($parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) return new WP_Error('microsoft_signature', 'Signature Microsoft invalide.');

        $issuer = 'https://login.microsoftonline.com/' . $tenant_id . '/v2.0';
        if (!hash_equals($issuer, (string) ($claims['iss'] ?? ''))) return new WP_Error('microsoft_issuer', 'Émetteur Microsoft invalide.');
        $aud = $claims['aud'] ?? '';
        if (!is_string($aud) || !hash_equals($this->client_id, $aud)) return new WP_Error('microsoft_audience', 'Audience Microsoft invalide.');
        $now = time();
        if (empty($claims['exp']) || (int) $claims['exp'] < $now - 60) return new WP_Error('microsoft_expired', 'Jeton Microsoft expiré.');
        if (!empty($claims['nbf']) && (int) $claims['nbf'] > $now + 60) return new WP_Error('microsoft_not_yet_valid', 'Jeton Microsoft pas encore valide.');
        if (!empty($claims['iat']) && (int) $claims['iat'] > $now + 120) return new WP_Error('microsoft_issued_in_future', 'Jeton Microsoft invalide.');
        if (!$expected_nonce || empty($claims['nonce']) || !hash_equals($expected_nonce, (string) $claims['nonce'])) return new WP_Error('microsoft_nonce', 'Nonce Microsoft invalide.');
        $sub = sanitize_text_field($claims['sub'] ?? '');
        if ($sub === '' || strlen($sub) > 255) return new WP_Error('microsoft_subject_invalid', 'Identité Microsoft invalide.');
        $email = sanitize_email($claims['email'] ?? ($claims['preferred_username'] ?? ''));
        if (!$email || !is_email($email)) return new WP_Error('microsoft_email_missing', 'Microsoft n’a pas fourni une adresse e-mail utilisable.');
        return [
            'provider' => 'microsoft',
            'sub' => $sub,
            'email' => $email,
            'email_verified' => false,
            'name' => sanitize_text_field($claims['name'] ?? $email),
            'given_name' => sanitize_text_field($claims['given_name'] ?? ''),
            'family_name' => sanitize_text_field($claims['family_name'] ?? ''),
            'picture' => '',
        ];
    }

    private function jwks($tenant_id) {
        $cache_key = 'dip_ms_jwks_' . md5($tenant_id);
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;
        $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant_id) . '/discovery/v2.0/keys';
        $response = wp_safe_remote_get($url, ['timeout' => 12, 'redirection' => 0, 'limit_response_size' => 307200, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('microsoft_keys_unavailable', 'Impossible de vérifier les clés Microsoft.');
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > 250000) return new WP_Error('microsoft_keys_too_large', 'Réponse de clés Microsoft invalide.');
        $json = json_decode($body, true);
        if (empty($json['keys']) || !is_array($json['keys']) || count($json['keys']) > 50) return new WP_Error('microsoft_keys_invalid', 'Clés Microsoft invalides.');
        $ttl = 6 * HOUR_IN_SECONDS;
        $cache_control = (string) wp_remote_retrieve_header($response, 'cache-control');
        if (preg_match('/(?:^|,)\s*max-age=(\d+)/i', $cache_control, $m)) $ttl = min(DAY_IN_SECONDS, max(5 * MINUTE_IN_SECONDS, absint($m[1])));
        set_transient($cache_key, $json['keys'], $ttl);
        return $json['keys'];
    }

    private function jwk_to_pem(array $jwk) {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) return new WP_Error('microsoft_key_type', 'Type de clé Microsoft non pris en charge.');
        if (!empty($jwk['use']) && $jwk['use'] !== 'sig') return new WP_Error('microsoft_key_use', 'Usage de clé Microsoft invalide.');
        if (!empty($jwk['alg']) && $jwk['alg'] !== 'RS256') return new WP_Error('microsoft_key_alg', 'Algorithme de clé Microsoft invalide.');
        $modulus = $this->b64url_decode($jwk['n']);
        $exponent = $this->b64url_decode($jwk['e']);
        if ($modulus === '' || $exponent === '' || strlen($modulus) < 256 || strlen($modulus) > 1024 || strlen($exponent) > 16) return new WP_Error('microsoft_key_invalid', 'Clé Microsoft invalide.');
        $rsa = $this->asn1_seq($this->asn1_int($modulus) . $this->asn1_int($exponent));
        $alg = hex2bin('300d06092a864886f70d0101010500');
        $spki = $this->asn1_seq($alg . "\x03" . $this->asn1_len(strlen($rsa) + 1) . "\x00" . $rsa);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function asn1_int($value) {
        $value = ltrim($value, "\x00");
        if ($value === '') $value = "\x00";
        if (ord($value[0]) > 0x7f) $value = "\x00" . $value;
        return "\x02" . $this->asn1_len(strlen($value)) . $value;
    }
    private function asn1_seq($value) { return "\x30" . $this->asn1_len(strlen($value)) . $value; }
    private function asn1_len($length) {
        if ($length < 128) return chr($length);
        $out = '';
        while ($length > 0) { $out = chr($length & 0xff) . $out; $length >>= 8; }
        return chr(0x80 | strlen($out)) . $out;
    }
    private function b64url_decode($value) {
        $value = strtr((string) $value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad) $value .= str_repeat('=', 4 - $pad);
        return (string) base64_decode($value, true);
    }
}
