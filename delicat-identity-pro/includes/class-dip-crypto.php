<?php
defined('ABSPATH') || exit;

final class DIP_Crypto {
    public static function is_available() { return function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt'); }

    const PREFIX = 'dglp_enc_v2:';

    private static function key() {
        return hash('sha256', wp_salt('auth') . '|' . wp_salt('secure_auth') . '|delicat-identity-pro', true);
    }

    public static function encrypt($plain) {
        $plain = (string) $plain;
        if ($plain === '' || strpos($plain, self::PREFIX) === 0 || strpos($plain, 'dglp_enc_v1:') === 0) {
            return $plain;
        }
        $key = self::key();
        try {
            if (function_exists('sodium_crypto_secretbox')) {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                return self::PREFIX . 's:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
            }
            if (function_exists('openssl_encrypt')) {
                $iv = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
                if ($cipher !== false) {
                    return self::PREFIX . 'o:' . base64_encode($iv . $tag . $cipher);
                }
            }
        } catch (Throwable $e) {
            return '';
        }
        return '';
    }

    public static function decrypt($stored) {
        $stored = (string) $stored;
        if ($stored === '') return '';
        if (strpos($stored, 'dglp_enc_v1:') === 0) return self::decrypt_v1($stored);
        if (strpos($stored, self::PREFIX) !== 0) return $stored;
        $payload = substr($stored, strlen(self::PREFIX));
        $type = substr($payload, 0, 2);
        $raw = base64_decode(substr($payload, 2), true);
        if ($raw === false) return '';
        $key = self::key();
        try {
            if ($type === 's:' && function_exists('sodium_crypto_secretbox_open')) {
                $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                if (strlen($raw) <= $n) return '';
                $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
                return $plain === false ? '' : $plain;
            }
            if ($type === 'o:' && function_exists('openssl_decrypt')) {
                if (strlen($raw) <= 28) return '';
                $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
                return $plain === false ? '' : $plain;
            }
        } catch (Throwable $e) {
            return '';
        }
        return '';
    }
    private static function decrypt_v1($stored) {
        $prefix = 'dglp_enc_v1:';
        $payload = substr((string) $stored, strlen($prefix));
        $type = substr($payload, 0, 2);
        $raw = base64_decode(substr($payload, 2), true);
        if ($raw === false) return '';
        $key = hash('sha256', wp_salt('auth') . '|' . wp_salt('secure_auth') . '|dglp-oauth-secret', true);
        try {
            if ($type === 's:' && function_exists('sodium_crypto_secretbox_open')) {
                $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                if (strlen($raw) <= $n) return '';
                $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
                return $plain === false ? '' : $plain;
            }
            if ($type === 'o:' && function_exists('openssl_decrypt')) {
                if (strlen($raw) <= 28) return '';
                $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
                return $plain === false ? '' : $plain;
            }
        } catch (Throwable $e) {
            return '';
        }
        return '';
    }

}
