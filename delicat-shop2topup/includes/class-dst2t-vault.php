<?php

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts API credentials and voucher payloads at rest with AES-256-GCM.
 * Defining credentials as wp-config.php constants remains the preferred option.
 */
final class DST2T_Vault {
	const PREFIX = 'dst2t:v1:';

	/** @var bool Set when a stored secret could only be read with a historical key. */
	private static $fallback_used = false;

	/** True when something in this request decrypted with a superseded key. */
	public static function needs_rekey() {
		return self::$fallback_used;
	}

	public function available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	public function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! $this->available() ) {
			return new WP_Error( 'dst2t_crypto_unavailable', __( 'OpenSSL is required to store top-up credentials securely.', 'delicat-shop2topup' ) );
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( Throwable $error ) {
			return new WP_Error( 'dst2t_random_failed', __( 'Secure random generation failed.', 'delicat-shop2topup' ) );
		}

		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, 'delicat-shop2topup', 16 );
		if ( false === $ciphertext ) {
			return new WP_Error( 'dst2t_encrypt_failed', __( 'The secret could not be encrypted.', 'delicat-shop2topup' ) );
		}

		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	public function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 !== strpos( $stored, self::PREFIX ) || ! $this->available() ) {
			return '';
		}

		$decoded = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $decoded || strlen( $decoded ) < 29 ) {
			return '';
		}

		$iv         = substr( $decoded, 0, 12 );
		$tag        = substr( $decoded, 12, 16 );
		$ciphertext = substr( $decoded, 28 );

		// Try the current key first, then every historical derivation. Without this
		// a site URL change, a salt rotation, or adopting the wp-config constant
		// later would silently orphan saved credentials and voucher codes.
		foreach ( $this->keys() as $index => $key ) {
			$plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'delicat-shop2topup' );
			if ( false !== $plaintext ) {
				if ( $index > 0 ) {
					self::$fallback_used = true;
				}
				return $plaintext;
			}
		}

		return '';
	}

	private function key() {
		$keys = $this->keys();
		return $keys[0];
	}

	/**
	 * Key candidates, most current first. Only the first is ever used to encrypt.
	 *
	 * @return array
	 */
	private function keys() {
		$keys = array();

		if ( defined( 'DELICAT_S2T_ENCRYPTION_KEY' ) && strlen( (string) DELICAT_S2T_ENCRYPTION_KEY ) >= 32 ) {
			$keys[] = hash( 'sha256', (string) DELICAT_S2T_ENCRYPTION_KEY, true );
		}
		if ( defined( 'DELICAT_S2T_ENCRYPTION_KEY_PREVIOUS' ) && strlen( (string) DELICAT_S2T_ENCRYPTION_KEY_PREVIOUS ) >= 32 ) {
			$keys[] = hash( 'sha256', (string) DELICAT_S2T_ENCRYPTION_KEY_PREVIOUS, true );
		}

		$salts  = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' );
		$keys[] = hash( 'sha256', $salts . '|' . home_url( '/' ), true );
		$keys[] = hash( 'sha256', $salts, true );

		return $keys;
	}
}
