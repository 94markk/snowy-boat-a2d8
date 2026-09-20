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
		return $this->vault->decrypt( $this->get( 'api_key_id', '' ) );
	}

	public function api_secret() {
		if ( defined( 'DELICAT_S2T_KEY_SECRET' ) && DELICAT_S2T_KEY_SECRET ) {
			return trim( (string) DELICAT_S2T_KEY_SECRET );
		}
		return $this->vault->decrypt( $this->get( 'api_secret', '' ) );
	}

	public function webhook_secret() {
		if ( defined( 'DELICAT_S2T_WEBHOOK_SECRET' ) && DELICAT_S2T_WEBHOOK_SECRET ) {
			return trim( (string) DELICAT_S2T_WEBHOOK_SECRET );
		}
		return $this->vault->decrypt( $this->get( 'webhook_secret', '' ) );
	}

	/**
	 * A representative credential string. Used to decide whether the integration
	 * is configured at all and to key the local rate limiter; the header actually
	 * sent is built by DST2T_API_Client::auth_headers() from the resolved mode.
	 */
	public function token() {
		$key_id = $this->api_key_id();
		$secret = $this->api_secret();
		if ( '' === $key_id && '' === $secret ) {
			return '';
		}
		if ( '' === $secret ) {
			return $key_id;
		}
		if ( '' === $key_id ) {
			return $secret;
		}
		return $key_id . '.' . $secret;
	}

	/**
	 * The credential shape to present.
	 *
	 * 'auto' picks the shape the saved fields can form; the connection test
	 * replaces it with whatever the provider actually accepted.
	 */
	public function auth_mode() {
		$mode = (string) $this->get( 'auth_mode', 'auto' );
		if ( in_array( $mode, DST2T_API_Client::AUTH_MODES, true ) ) {
			return $mode;
		}
		$key    = $this->api_key_id();
		$secret = $this->api_secret();
		if ( $key && $secret ) {
			return 'bearer_pair';
		}
		return $key ? 'bearer_key' : 'bearer_secret';
	}

	/** Persists one setting without going through the whole form. */
	public function set( $key, $value ) {
		$settings         = $this->all();
		$settings[ $key ] = $value;
		update_option( self::OPTION, $settings, false );
	}

	public function credentials_configured() {
		return '' !== $this->token();
	}

	public function save( $input ) {
		$current = $this->all();
		$next    = $current;

		foreach ( self::boolean_keys() as $key ) {
			$next[ $key ] = ! empty( $input[ $key ] ) ? 'yes' : 'no';
		}

		$next['markup_percent']        = $this->bounded_decimal( isset( $input['markup_percent'] ) ? $input['markup_percent'] : 20, 0, 1000, '20.00' );
		$next['usd_exchange_rate']     = $this->bounded_decimal( isset( $input['usd_exchange_rate'] ) ? $input['usd_exchange_rate'] : 1, 0.000001, 1000000, '1.000000' );
		$next['cost_guard_percent']    = $this->bounded_decimal( isset( $input['cost_guard_percent'] ) ? $input['cost_guard_percent'] : 10, 0, 1000, '10.00' );
		$next['low_balance_threshold'] = $this->bounded_decimal( isset( $input['low_balance_threshold'] ) ? $input['low_balance_threshold'] : 0, 0, 100000000, '0.000000' );
		$next['balance_interval']      = (string) $this->bounded_int( isset( $input['balance_interval'] ) ? $input['balance_interval'] : 10, 1, 1440, 10 );
		$next['catalog_sync_interval'] = (string) $this->bounded_int( isset( $input['catalog_sync_interval'] ) ? $input['catalog_sync_interval'] : 0, 0, 10080, 0 );

		if ( isset( $input['auth_mode'] ) ) {
			$mode              = sanitize_key( wp_unslash( (string) $input['auth_mode'] ) );
			$next['auth_mode'] = in_array( $mode, DST2T_API_Client::AUTH_MODES, true ) ? $mode : 'auto';
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
			foreach ( array( 'api_key_id', 'api_secret', 'webhook_secret' ) as $secret_key ) {
				if ( ! isset( $input[ $secret_key ] ) || '' === trim( (string) $input[ $secret_key ] ) ) {
					continue;
				}
				$encrypted = $this->vault->encrypt( trim( (string) wp_unslash( $input[ $secret_key ] ) ) );
				if ( is_wp_error( $encrypted ) ) {
					return $encrypted;
				}
				$next[ $secret_key ] = $encrypted;
			}
		}

		update_option( self::OPTION, $next, false );
		return true;
	}

	/** Every checkbox the settings form owns. Anything missing from the post is off. */
	public static function boolean_keys() {
		return array(
			'validate_player',
			'auto_complete',
			'debug_logging',
			'sync_catalog_prices',
			'sync_stock',
			'delete_data_on_uninstall',
			'stealth_mode',
			'mask_identifiers',
			'private_webhook',
			'legacy_webhook',
			'import_force_draft',
			'import_images',
			'import_categories',
			'balance_auto_refresh',
			'admin_bar_balance',
			'low_balance_email',
		);
	}

	public static function defaults() {
		return array(
			// Credentials.
			'api_key_id'               => '',
			'api_secret'               => '',
			'webhook_secret'           => '',
			'auth_mode'                => 'auto',

			// Fulfillment safety.
			'validate_player'          => 'yes',
			'auto_complete'            => 'yes',
			'cost_guard_percent'       => '10.00',

			// Catalog pricing and mirroring.
			'markup_percent'           => '20.00',
			'usd_exchange_rate'        => '1.000000',
			'sync_catalog_prices'      => 'no',
			'sync_stock'               => 'yes',
			'catalog_sync_interval'    => '0',

			// Import behaviour.
			'import_force_draft'       => 'yes',
			'import_images'            => 'yes',
			'import_categories'        => 'yes',
			'import_sku_prefix'        => 'tu-',

			// Wallet balance.
			'balance_auto_refresh'     => 'yes',
			'balance_interval'         => '10',
			'low_balance_threshold'    => '0.000000',
			'low_balance_email'        => 'no',
			'admin_bar_balance'        => 'yes',

			// White labelling.
			'brand_label'              => '',
			'stealth_mode'             => 'yes',
			'mask_identifiers'         => 'yes',
			'private_webhook'          => 'yes',
			'legacy_webhook'           => 'yes',

			// Diagnostics.
			'debug_logging'            => 'no',
			'delete_data_on_uninstall' => 'no',
		);
	}

	private function bounded_decimal( $value, $minimum, $maximum, $fallback ) {
		$value = str_replace( ',', '.', trim( (string) wp_unslash( $value ) ) );
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		$number = (float) $value;
		if ( $number < $minimum || $number > $maximum ) {
			return $fallback;
		}
		return number_format( $number, 6, '.', '' );
	}

	private function bounded_int( $value, $minimum, $maximum, $fallback ) {
		$value = trim( (string) wp_unslash( $value ) );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return $fallback;
		}
		$number = (int) $value;
		if ( $number < $minimum || $number > $maximum ) {
			return $fallback;
		}
		return $number;
	}
}
