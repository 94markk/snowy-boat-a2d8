<?php
if (!defined('ABSPATH')) {
    exit;
}

final class DFR_Crypto {
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

    public static function encrypt($plaintext) {
        return self::encrypt_context($plaintext, 'generic');
    }

    public static function encrypt_context($plaintext, $context) {
        $plaintext = (string) $plaintext;
        if ($plaintext === '') {
            return '';
        }
        $context = (string) $context;
        $key = self::context_key($context);
        $aad = 'delicat-fazercards|' . $context . '|v2';

        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            try {
                $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
                return 'x2:' . base64_encode($nonce . $cipher);
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
                    return 'g2:' . base64_encode($iv . $tag . $cipher);
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
        // re-encrypted with the v2 contextual AEAD envelope when order codes are read.
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
}
