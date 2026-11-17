<?php

defined( 'ABSPATH' ) || exit;

final class DST2T_Settings {
	const OPTION = 'dst2t_settings';

	/** @var DST2T_Vault */
	private $vault;

	public function __construct( DST2T_Vault $vault ) {
		$this->vault = $vault;
	}

	public function all() {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	public function get( $key, $default = null ) {
		$settings = $this->all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	public function enabled( $key ) {
		return 'yes' === $this->get( $key, 'no' );
	}

	public function api_key_id() {
		if ( defined( 'DELICAT_S2T_KEY_ID' ) && DELICAT_S2T_KEY_ID ) {
			return trim( (string) DELICAT_S2T_KEY_ID );
		}
		return trim( $this->vault->decrypt( $this->get( 'api_key_id', '' ) ) );
	}

	public function api_secret() {
		if ( defined( 'DELICAT_S2T_KEY_SECRET' ) && DELICAT_S2T_KEY_SECRET ) {
			return trim( (string) DELICAT_S2T_KEY_SECRET );
		}
		return trim( $this->vault->decrypt( $this->get( 'api_secret', '' ) ) );
	}

	public function credential() {
		$key = $this->api_key_id();
		$secret = $this->api_secret();
		return ( '' !== $key && '' !== $secret ) ? $key . '.' . $secret : '';
	}

	/** Backwards-compatible alias used by older components. */
	public function token() {
		return $this->credential();
	}

	public function webhook_secret() {
		if ( defined( 'DELICAT_S2T_WEBHOOK_SECRET' ) && DELICAT_S2T_WEBHOOK_SECRET ) {
			return trim( (string) DELICAT_S2T_WEBHOOK_SECRET );
		}
		return trim( $this->vault->decrypt( $this->get( 'webhook_secret', '' ) ) );
	}

	/** SHOP2TOPUP v1 has exactly one documented auth format. */
	public function auth_mode() {
		return 'bearer_pair';
	}

	public function set( $key, $value ) {
		$settings         = $this->all();
		$settings[ $key ] = $value;
		update_option( self::OPTION, $settings, false );
	}

	public function credentials_configured() {
		return '' !== $this->credential();
	}

	/**
	 * Save settings. API credentials can be supplied as one documented keyId.secret
	 * string in api_credential, or through the legacy two-field inputs.
	 */
	public function save( $input ) {
		$current = $this->all();
		$next    = $current;

		foreach ( self::boolean_keys() as $key ) {
			$next[ $key ] = ! empty( $input[ $key ] ) ? 'yes' : 'no';
		}

		$next['markup_percent']        = $this->bounded_decimal( isset( $input['markup_percent'] ) ? $input['markup_percent'] : 20, 0, 1000, '20.000000' );
		$next['usd_exchange_rate']     = $this->bounded_decimal( isset( $input['usd_exchange_rate'] ) ? $input['usd_exchange_rate'] : 1, 0.000001, 1000000, '1.000000' );
		$next['cost_guard_percent']    = $this->bounded_decimal( isset( $input['cost_guard_percent'] ) ? $input['cost_guard_percent'] : 10, 0, 1000, '10.000000' );
		$next['low_balance_threshold'] = $this->bounded_decimal( isset( $input['low_balance_threshold'] ) ? $input['low_balance_threshold'] : 0, 0, 100000000, '0.000000' );
		$next['balance_interval']      = (string) $this->bounded_int( isset( $input['balance_interval'] ) ? $input['balance_interval'] : 10, 1, 1440, 10 );
		$next['catalog_sync_interval'] = (string) $this->bounded_int( isset( $input['catalog_sync_interval'] ) ? $input['catalog_sync_interval'] : 15, 0, 10080, 15 );
		$next['auth_mode']             = 'bearer_pair';

		$pricing_mode = isset( $input['catalog_pricing_mode'] ) ? sanitize_key( wp_unslash( (string) $input['catalog_pricing_mode'] ) ) : (string) $this->get( 'catalog_pricing_mode', 'manual' );
		$next['catalog_pricing_mode'] = in_array( $pricing_mode, array( 'manual', 'automatic' ), true ) ? $pricing_mode : 'manual';
		$import_structure = isset( $input['import_structure'] ) ? sanitize_key( wp_unslash( (string) $input['import_structure'] ) ) : (string) $this->get( 'import_structure', 'variable' );
		$next['import_structure'] = in_array( $import_structure, array( 'variable', 'simple' ), true ) ? $import_structure : 'variable';
		if ( isset( $input['variation_attribute'] ) ) {
			$attribute = sanitize_text_field( wp_unslash( (string) $input['variation_attribute'] ) );
			$attribute = trim( wp_strip_all_tags( $attribute ) );
			$next['variation_attribute'] = '' !== $attribute ? substr( $attribute, 0, 40 ) : 'Package';
		}
		// Manual pricing is intentionally authoritative: supplier cost and stock may
		// continue to synchronize, but WooCommerce customer prices are never rewritten.
		if ( 'manual' === $next['catalog_pricing_mode'] ) {
			$next['sync_catalog_prices'] = 'no';
		}

		if ( isset( $input['brand_label'] ) ) {
			$label               = sanitize_text_field( wp_unslash( (string) $input['brand_label'] ) );
			$next['brand_label'] = '' !== trim( $label ) ? substr( trim( $label ), 0, 60 ) : '';
		}
		if ( isset( $input['import_sku_prefix'] ) ) {
			$prefix                    = strtolower( sanitize_text_field( wp_unslash( (string) $input['import_sku_prefix'] ) ) );
			$prefix                    = preg_replace( '/[^a-z0-9\-_]/', '', $prefix );
			$next['import_sku_prefix'] = '' !== $prefix ? substr( $prefix, 0, 12 ) : 'tu-';
		}

		if ( ! empty( $input['clear_credentials'] ) ) {
			$next['api_key_id']     = '';
			$next['api_secret']     = '';
			$next['webhook_secret'] = '';
		} else {
			$key = '';
			$secret = '';
			$credential_supplied = false;

			if ( isset( $input['api_credential'] ) && '' !== trim( (string) $input['api_credential'] ) ) {
				$credential_supplied = true;
				$credential = trim( (string) wp_unslash( $input['api_credential'] ) );
				$parts = explode( '.', $credential, 2 );
				if ( 2 !== count( $parts ) || '' === trim( $parts[0] ) || '' === trim( $parts[1] ) ) {
					return new WP_Error( 'dst2t_bad_credential', __( 'The API credential must be the complete SHOP2TOPUP value in keyId.secret format.', 'delicat-shop2topup' ) );
				}
				$key = trim( $parts[0] );
				$secret = trim( $parts[1] );
			} else {
				$legacy_key = isset( $input['api_key_id'] ) ? trim( (string) wp_unslash( $input['api_key_id'] ) ) : '';
				$legacy_secret = isset( $input['api_secret'] ) ? trim( (string) wp_unslash( $input['api_secret'] ) ) : '';
				if ( '' !== $legacy_key || '' !== $legacy_secret ) {
					$credential_supplied = true;
					if ( '' !== $legacy_key && false !== strpos( $legacy_key, '.' ) && '' === $legacy_secret ) {
						$parts = explode( '.', $legacy_key, 2 );
						$key = trim( $parts[0] );
						$secret = isset( $parts[1] ) ? trim( $parts[1] ) : '';
					} else {
						$key = $legacy_key;
						$secret = $legacy_secret;
					}
				}
			}

			if ( $credential_supplied ) {
				if ( '' === $key || '' === $secret ) {
					return new WP_Error( 'dst2t_incomplete_credential', __( 'Both the SHOP2TOPUP key ID and secret are required.', 'delicat-shop2topup' ) );
				}
				$enc_key = $this->vault->encrypt( $key );
				$enc_secret = $this->vault->encrypt( $secret );
				if ( is_wp_error( $enc_key ) ) { return $enc_key; }
				if ( is_wp_error( $enc_secret ) ) { return $enc_secret; }
				$next['api_key_id'] = $enc_key;
				$next['api_secret'] = $enc_secret;
			}

			if ( isset( $input['webhook_secret'] ) && '' !== trim( (string) $input['webhook_secret'] ) ) {
				$encrypted = $this->vault->encrypt( trim( (string) wp_unslash( $input['webhook_secret'] ) ) );
				if ( is_wp_error( $encrypted ) ) { return $encrypted; }
				$next['webhook_secret'] = $encrypted;
			}
		}

		update_option( self::OPTION, $next, false );
		return true;
	}

	public static function boolean_keys() {
		return array(
			'validate_player', 'woocommerce_payment_gate', 'auto_complete', 'debug_logging', 'sync_catalog_prices', 'sync_stock',
			'delete_data_on_uninstall', 'stealth_mode', 'mask_identifiers', 'private_webhook', 'legacy_webhook',
			'import_force_draft', 'import_images', 'import_categories', 'balance_auto_refresh', 'admin_bar_balance',
			'low_balance_email', 'player_history',
		);
	}

	public static function defaults() {
		return array(
			'api_key_id' => '', 'api_secret' => '', 'webhook_secret' => '', 'auth_mode' => 'bearer_pair',
			'validate_player' => 'yes', 'woocommerce_payment_gate' => 'yes', 'auto_complete' => 'yes', 'cost_guard_percent' => '10.000000',
			'markup_percent' => '20.000000', 'usd_exchange_rate' => '1.000000', 'catalog_pricing_mode' => 'manual', 'import_structure' => 'variable', 'variation_attribute' => 'Package', 'sync_catalog_prices' => 'no',
			'sync_stock' => 'yes', 'catalog_sync_interval' => '15', 'player_history' => 'yes', 'import_force_draft' => 'yes', 'import_images' => 'yes',
			'import_categories' => 'yes', 'import_sku_prefix' => 'tu-', 'balance_auto_refresh' => 'yes',
			'balance_interval' => '10', 'low_balance_threshold' => '0.000000', 'low_balance_email' => 'no',
			'admin_bar_balance' => 'yes', 'brand_label' => '', 'stealth_mode' => 'yes', 'mask_identifiers' => 'yes',
			'private_webhook' => 'yes', 'legacy_webhook' => 'no', 'debug_logging' => 'no', 'delete_data_on_uninstall' => 'no',
		);
	}

	private function bounded_decimal( $value, $minimum, $maximum, $fallback ) {
		$value = str_replace( ',', '.', trim( (string) wp_unslash( $value ) ) );
		if ( ! is_numeric( $value ) ) { return $fallback; }
		$number = (float) $value;
		if ( $number < $minimum || $number > $maximum ) { return $fallback; }
		return number_format( $number, 6, '.', '' );
	}

	private function bounded_int( $value, $minimum, $maximum, $fallback ) {
		$value = trim( (string) wp_unslash( $value ) );
		if ( '' === $value || ! is_numeric( $value ) ) { return $fallback; }
		$number = (int) $value;
		if ( $number < $minimum || $number > $maximum ) { return $fallback; }
		return $number;
	}
}
