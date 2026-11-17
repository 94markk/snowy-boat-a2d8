<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Authenticated encryption for delivery codes, supplier secrets and snapshots.
 *
 * 4.15: optional key ring. Envelopes v2 (x2:/g2:) derive their key from the
 * WordPress salts and home_url(). Rotating the salts -- the standard first step
 * after a suspected breach, one click in several security plugins -- or moving the
 * site to another domain made every stored gift card and top-up code unreadable.
 *
 * With a key ring in wp-config.php:
 *
 *     define( 'DELICAT_DFR_KEYS', 'k1:<base64 of 32 random bytes>' );
 *
 * new data is written as v3 (x3:/g3:, key id in the envelope, independent of salts
 * and domain). Data written before the ring stays v2, so it still depends on the salts
 * until the background key-ring migration has re-sealed it (codes, snapshots, supplier
 * secrets, reseller envelopes): do not rotate the salts or move the domain before the
 * admin notice shows 0. keyring_hmac() gives signatures and one-way hashes the same
 * independence. To rotate, put a new key first and keep the old one after it:
 * 'k2:<new>,k1:<old>'. Without the constant nothing changes.
 *
 * Rolling the plugin back to 4.14.2 or earlier after adding a ring hides every v3 value
 * (those versions only read v2); upgrading again restores access. Keep the constant.
 */
final class DFR_Crypto {
    private static $ring = null;

    private static function master_key() {
        $material = wp_salt('auth') . '|' . wp_salt('secure_auth') . '|' . home_url('/') . '|delicat-fazercards-v2';
        return hash('sha256', $material, true);
    }

    private static function legacy_key() {
        $material = wp_salt('auth') . '|' . home_url('/') . '|delicat-fazercards-v1';
        return hash('sha256', $material, true);
    }

    private static function context_key($context) {
        $context = (string) $context;
        $master = self::master_key();
        if (function_exists('hash_hkdf')) {
            return hash_hkdf('sha256', $master, 32, 'DFR|' . $context, home_url('/'));
        }
        return hash_hmac('sha256', 'DFR|' . $context, $master, true);
    }

    /** @return array<string,string> key id => 32-byte key, primary first. */
    private static function keyring() {
        if (self::$ring !== null) {
            return self::$ring;
        }
        self::$ring = array();
        if (defined('DELICAT_DFR_KEYS') && is_string(DELICAT_DFR_KEYS)) {
            foreach (explode(',', DELICAT_DFR_KEYS) as $entry) {
                $parts = explode(':', trim($entry), 2);
                if (count($parts) !== 2) { continue; }
                $id = preg_replace('/[^a-z0-9]/i', '', $parts[0]);
                $raw = base64_decode(trim($parts[1]), true);
                if ($id === '' || $raw === false || strlen($raw) < 32 || isset(self::$ring[$id])) { continue; }
                self::$ring[$id] = hash('sha256', $raw . '|delicat-dfr-keyring', true);
            }
        }
        return self::$ring;
    }

    /** True when a valid key ring is configured. */
    public static function has_keyring() {
        return !empty(self::keyring());
    }

    /** Id of the primary (first) key of DELICAT_DFR_KEYS; '' without a ring. */
    public static function primary_key_id() {
        $ring = self::keyring();
        return $ring ? (string) array_key_first($ring) : '';
    }

    /**
     * HMAC-SHA256 keyed by the key ring, for proofs and one-way hashes that must survive
     * a WordPress salt change (wallet attestation, reseller API key hashes).
     *
     * @param string $data    Message.
     * @param string $context Purpose; each context uses its own derived key.
     * @param string $kid     Ring key id; '' for the primary key.
     * @return array{kid:string,mac:string}|null 64 lowercase hex chars; null without a
     *                                          ring or for an unknown key id.
     */
    public static function keyring_hmac($data, $context, $kid = '') {
        $ring = self::keyring();
        if (!$ring) { return null; }
        $kid = (string) $kid;
        if ($kid === '') { $kid = (string) array_key_first($ring); }
        if (!isset($ring[$kid])) { return null; }
        $key = self::ring_key($kid, $context);
        if ($key === '') { return null; }
        return array('kid' => $kid, 'mac' => hash_hmac('sha256', (string) $data, $key));
    }

    private static function ring_key($kid, $context) {
        $ring = self::keyring();
        if (!isset($ring[$kid])) { return ''; }
        if (function_exists('hash_hkdf')) {
            return hash_hkdf('sha256', $ring[$kid], 32, 'DFR3|' . (string) $context, 'delicat-dfr-v3');
        }
        return hash_hmac('sha256', 'DFR3|' . (string) $context, $ring[$kid], true);
    }

    /**
     * Whether an envelope is already in the preferred format (v3 with the primary
     * key when a ring exists, v2 otherwise). Callers re-encrypt what is not.
     */
    public static function is_current($encoded) {
        $encoded = (string) $encoded;
        $ring = self::keyring();
        if ($ring) {
            $primary = (string) key($ring);
            return strpos($encoded, 'x3:' . $primary . ':') === 0 || strpos($encoded, 'g3:' . $primary . ':') === 0;
        }
        return strpos($encoded, 'x2:') === 0 || strpos($encoded, 'g2:') === 0;
    }

    public static function encrypt($plaintext) {
        return self::encrypt_context($plaintext, 'generic');
    }

    public static function encrypt_context($plaintext, $context) {
        $plaintext = (string) $plaintext;
        if ($plaintext === '') {
            return '';
        }
        $context = (string) $context;
        $ring = self::keyring();
        if ($ring) {
            $kid = (string) key($ring);
            $key = self::ring_key($kid, $context);
            $aad = 'delicat-fazercards|' . $context . '|v3|' . $kid;
            $prefix = '3:' . $kid . ':';
        } else {
            $key = self::context_key($context);
            $aad = 'delicat-fazercards|' . $context . '|v2';
            $prefix = '2:';
        }

        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            try {
                $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
                return 'x' . $prefix . base64_encode($nonce . $cipher);
            } catch (Throwable $e) {
                // Continue to AES-256-GCM fallback; plaintext is never persisted.
            }
        }

        if (function_exists('openssl_encrypt')) {
            try {
                $iv = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
                if ($cipher !== false) {
                    return 'g' . $prefix . base64_encode($iv . $tag . $cipher);
                }
            } catch (Throwable $e) {
                // Secure failure below.
            }
        }

        return new WP_Error('dfr_crypto_unavailable', __('Authenticated encryption is unavailable on this server.', 'delicat-fazercards'));
    }

    public static function decrypt($encoded) {
        return self::decrypt_context($encoded, 'generic');
    }

    public static function decrypt_context($encoded, $context) {
        $encoded = (string) $encoded;
        if ($encoded === '') {
            return '';
        }
        $context = (string) $context;

        // v3: key ring.
        if (strpos($encoded, 'x3:') === 0 || strpos($encoded, 'g3:') === 0) {
            $parts = explode(':', $encoded, 3);
            if (count($parts) !== 3) { return ''; }
            $kid = $parts[1];
            $key = self::ring_key($kid, $context);
            if ($key === '') { return ''; }
            $aad = 'delicat-fazercards|' . $context . '|v3|' . $kid;
            $raw = base64_decode($parts[2], true);
            if ($raw === false) { return ''; }
            if ($parts[0] === 'x3' && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
                $n = defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES') ? SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES : 24;
                if (strlen($raw) <= $n + 16) { return ''; }
                try {
                    $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $n), $aad, substr($raw, 0, $n), $key);
                    return $plain === false ? '' : $plain;
                } catch (Throwable $e) { return ''; }
            }
            if ($parts[0] === 'g3' && function_exists('openssl_decrypt')) {
                if (strlen($raw) <= 28) { return ''; }
                $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
                return $plain === false ? '' : $plain;
            }
            return '';
        }

        $aad = 'delicat-fazercards|' . $context . '|v2';
        $key = self::context_key($context);

        if (strpos($encoded, 'x2:') === 0 && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            $raw = base64_decode(substr($encoded, 3), true);
            $n = defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES') ? SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES : 24;
            if ($raw === false || strlen($raw) <= $n + 16) { return ''; }
            $nonce = substr($raw, 0, $n);
            $cipher = substr($raw, $n);
            try {
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $aad, $nonce, $key);
                return $plain === false ? '' : $plain;
            } catch (Throwable $e) { return ''; }
        }

        if (strpos($encoded, 'g2:') === 0 && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($encoded, 3), true);
            if ($raw === false || strlen($raw) <= 28) { return ''; }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
            return $plain === false ? '' : $plain;
        }

        // Backward-compatible decryption for v1 envelopes. These are transparently
        // re-encrypted with the current envelope when order codes are read.
        $legacy = self::legacy_key();
        if (strpos($encoded, 's1:') === 0 && function_exists('sodium_crypto_secretbox_open')) {
            $raw = base64_decode(substr($encoded, 3), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { return ''; }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $legacy);
            return $plain === false ? '' : $plain;
        }
        if (strpos($encoded, 'o1:') === 0 && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($encoded, 3), true);
            if ($raw === false || strlen($raw) <= 28) { return ''; }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $legacy, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? '' : $plain;
        }

        return '';
    }

    /**
     * Health check: a sentinel written once with the salt-derived key. If it stops
     * decrypting, the salts or the site address changed and v2 data is unreadable.
     *
     * @return string 'ok', 'keyring' (a ring protects new data), 'changed' or 'new'.
     */
    public static function salt_health() {
        $stored = (string) get_option('dfr_crypto_sentinel_v2', '');
        if ($stored === '') {
            $ring = self::$ring;
            self::$ring = array(); // Force a v2 sentinel whatever the ring.
            $sentinel = self::encrypt_context('delicat-sentinel', 'sentinel');
            self::$ring = $ring;
            if (is_string($sentinel) && $sentinel !== '') {
                update_option('dfr_crypto_sentinel_v2', $sentinel, false);
            }
            return 'new';
        }
        $ring = self::$ring;
        self::$ring = array();
        $ok = self::decrypt_context($stored, 'sentinel') === 'delicat-sentinel';
        self::$ring = $ring;
        if ($ok) {
            return self::has_keyring() ? 'keyring' : 'ok';
        }
        return 'changed';
    }
}
