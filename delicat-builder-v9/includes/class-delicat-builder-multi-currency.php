<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Delicat Builder V9 Multi Currency runtime.
 *
 * Migration goals:
 * - reuse the historical dmc_* option/meta contract;
 * - keep WooCommerce/store base currency authoritative;
 * - settle checkout/wallet in base by default;
 * - never expose an unauthenticated currency-mutation endpoint;
 * - load switcher assets only when the switcher is rendered;
 * - remain class/function-name isolated from the historical DMC plugin.
 */
final class Delicat_Builder_V9_Multi_Currency {
	public const SETTINGS_OPTION   = 'dmc_settings';
	public const CURRENCIES_OPTION = 'dmc_currencies';
	public const RATES_UPDATED     = 'dmc_rates_updated';
	public const RATE_WARNINGS     = 'dmc_rate_warnings';
	public const COOKIE            = 'dmc_currency';
	public const CRON_HOOK         = 'dmc_update_rates_event';

	private static ?self $instance = null;
	private bool $booted = false;
	private ?string $choice = null;
	private ?string $current = null;
	private bool $switcher_assets_enqueued = false;
	private ?array $settings_cache = null;
	private ?array $currencies_cache = null;
	private ?array $enabled_currencies_cache = null;
	private ?string $base_code_cache = null;
	private ?bool $public_cache_variant_cache = null;
	/** Request-level settlement signal; its inputs cannot change during a request. */
	private ?string $request_signal = null;
	/** Page-level settlement contexts, resolved once the main query exists. */
	private ?array $page_contexts = null;

	/**
	 * The currency cookie exactly as the BROWSER sent it, captured before any
	 * persist() can write to $_COOKIE during this request.
	 *
	 * This is the only value a shared cache can key on, so it is the only value
	 * the vary path may trust. See cache_vary_safe().
	 *
	 * @var string
	 */
	private string $incoming_cookie = '';

	/**
	 * Legacy-shape adapters used only by the migrated Product Fields runtime.
	 * They intentionally point back to this same isolated V9 object, so there is
	 * still only one currency/rate owner and no DMC global/class collision.
	 *
	 * @var self
	 */
	public $currencies;
	/** @var self */
	public $price;

	private function __construct() {
		$this->currencies = $this;
		$this->price      = $this;
		/* Captured here, not read on demand: persist() assigns to $_COOKIE so a
		 * later read cannot tell a browser-sent cookie from one this request
		 * just minted, and only a browser-sent one is in the cache key. */
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$this->incoming_cookie = strtoupper( sanitize_key( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
		}
	}

	/**
	 * May a non-default-currency response be cached as ONE SHARED COPY PER
	 * CURRENCY, instead of not being cached at all?
	 *
	 * PRO44. Until now any shopper on a non-default currency bypassed the page
	 * cache entirely -- correct, but on a store with USD/CAD/EUR enabled it
	 * means a large share of traffic pays a full PHP render on every page.
	 * LiteSpeed can key its cache on a cookie value (the plugin already does
	 * this for woocommerce_items_in_cart), which gives one cached copy per
	 * currency rather than none.
	 *
	 * The danger is serving one currency's prices under another's key, so every
	 * condition below must hold:
	 *
	 * 1. LiteSpeed is actually present. Without it there is no vary mechanism.
	 * 2. The BROWSER sent the cookie. A currency resolved from the Woo session or
	 *    from geolocation is invisible to the cache, and a cookie this request
	 *    is only now minting is not in the key of the copy being stored.
	 * 3. The cookie is valid and non-default. A default-currency cookie creates
	 *    no useful variant (display_choice() already retires those).
	 * 4. The cookie matches what is actually being rendered. display_choice()
	 *    resolves session BEFORE cookie, so the two can in principle disagree;
	 *    when they do, the rendered prices would not match the cache key. Bypass.
	 *
	 * Fails closed: any doubt returns false and the old bypass applies.
	 *
	 * @return bool
	 */
	public function cache_vary_safe(): bool {
		if ( ! (bool) apply_filters( 'delicat_builder_v9_currency_cache_vary', true ) ) {
			return false;
		}
		/* LiteSpeed Cache defines this on load; without it nothing varies. */
		if ( ! defined( 'LSCWP_V' ) ) {
			return false;
		}
		if ( '' === $this->incoming_cookie || ! $this->is_valid( $this->incoming_cookie ) ) {
			return false;
		}
		if ( $this->incoming_cookie === $this->default_currency() ) {
			return false;
		}
		return $this->incoming_cookie === $this->current();
	}

	public static function instance(): self {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	public static function boot(): void {
		self::instance()->init();
	}

	public function init(): void {
		if ( $this->booted || ! class_exists( 'WooCommerce' ) ) { return; }
		$this->booted = true;
		$this->ensure_defaults();

		add_action( 'wp_loaded', array( $this, 'sync_base' ), 1 );
		add_action( 'update_option_' . self::SETTINGS_OPTION, array( $this, 'reset_option_caches' ), 1, 0 );
		add_action( 'update_option_' . self::CURRENCIES_OPTION, array( $this, 'reset_option_caches' ), 1, 0 );
		add_action( 'update_option_woocommerce_currency', array( $this, 'reset_option_caches' ), 1, 0 );
		add_action( 'update_option_woocommerce_currency', array( $this, 'sync_base' ), 10, 0 );

		add_filter( 'woocommerce_currency', array( $this, 'filter_currency' ), 99 );
		add_filter( 'woocommerce_currency_symbol', array( $this, 'filter_symbol' ), 99, 2 );
		add_filter( 'wc_get_price_decimals', array( $this, 'filter_decimals' ), 99 );
		add_filter( 'woocommerce_price_format', array( $this, 'filter_price_format' ), 99, 2 );

		add_filter( 'woocommerce_product_get_price', array( $this, 'convert_product_price' ), 99, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'convert_product_price' ), 99, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'convert_product_price' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'convert_product_price' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'convert_product_price' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'convert_product_price' ), 99, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'convert_variation_price' ), 99, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'convert_variation_price' ), 99, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'convert_variation_price' ), 99, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'variation_prices_hash' ), 99 );
		add_filter( 'woocommerce_package_rates', array( $this, 'convert_shipping' ), 99 );
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'convert_coupon' ), 99, 2 );

		add_action( 'woocommerce_review_order_before_payment', array( $this, 'settlement_notice' ) );
		add_action( 'wp', array( $this, 'settle_page' ), 1 );
		// Priority 0: refuse a wrong-currency order before the payment guard marks
		// its intent as creating (priority 1), so no payment lock is left behind.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'guard_order_currency' ), 0, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'guard_store_api_order' ), 0, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'guard_store_api_order' ), 0, 1 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_wallet_gateway' ), PHP_INT_MAX );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_order' ), 20, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'admin_order_display' ) );

		add_action( 'woocommerce_product_options_pricing', array( $this, 'product_currency_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_currency_fields' ), 15 );

		add_shortcode( 'dmc_switcher', array( $this, 'shortcode' ) );
		add_shortcode( 'delicat_currency_switcher', array( $this, 'shortcode' ) );
		add_action( 'wp_footer', array( $this, 'sticky_switcher' ), 30 );
		add_action( 'wp_footer', array( $this, 'rate_provider_attribution' ), 99 );
		add_filter( 'wp_nav_menu_items', array( $this, 'maybe_add_to_menu' ), 20, 2 );

		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'update_rates' ) );
		add_action( 'init', array( $this, 'ensure_cron' ), 20 );
		add_action( 'update_option_' . self::SETTINGS_OPTION, array( $this, 'reschedule' ), 10, 2 );
		add_action( 'admin_post_delicat_builder_v9_dmc_update_rates', array( $this, 'admin_update_rates' ) );
	}

	public static function default_settings(): array {
		return array(
			'enabled'            => 'yes',
			'rate_mode'          => 'manual',
			'rate_provider'      => 'open_er_api',
			'update_interval'    => 'dmc_daily',
			'handling_fee'       => 0,
			'switcher_style'     => 'dropdown',
			'show_flags'         => 'yes',
			'geolocate'          => 'no',
			'default_currency'   => '',
			'lock_currency'      => 'no',
			'sticky'             => 'no',
			'charge_in_currency' => 'yes',
			'settlement'         => 'base',
			'wallet_base'        => 'yes',
			'rate_guard'         => 20,
			'approx'             => 'no',
			'menu_location'      => '',
		);
	}

	public static function currency_library(): array {
		return array(
			'HTG' => array( 'symbol' => 'G', 'position' => 'left', 'decimals' => 2, 'name' => 'Haitian Gourde' ),
			'USD' => array( 'symbol' => '$', 'position' => 'left', 'decimals' => 2, 'name' => 'US Dollar' ),
			'CAD' => array( 'symbol' => 'C$', 'position' => 'left', 'decimals' => 2, 'name' => 'Canadian Dollar' ),
			'EUR' => array( 'symbol' => '€', 'position' => 'left', 'decimals' => 2, 'name' => 'Euro' ),
			'GBP' => array( 'symbol' => '£', 'position' => 'left', 'decimals' => 2, 'name' => 'British Pound' ),
			'DOP' => array( 'symbol' => 'RD$', 'position' => 'left', 'decimals' => 2, 'name' => 'Dominican Peso' ),
			'BRL' => array( 'symbol' => 'R$', 'position' => 'left', 'decimals' => 2, 'name' => 'Brazilian Real' ),
			'MXN' => array( 'symbol' => 'Mex$', 'position' => 'left', 'decimals' => 2, 'name' => 'Mexican Peso' ),
			'JPY' => array( 'symbol' => '¥', 'position' => 'left', 'decimals' => 0, 'name' => 'Japanese Yen' ),
			'CLP' => array( 'symbol' => 'CLP$', 'position' => 'left', 'decimals' => 0, 'name' => 'Chilean Peso' ),
			'AUD' => array( 'symbol' => 'A$', 'position' => 'left', 'decimals' => 2, 'name' => 'Australian Dollar' ),
			'CHF' => array( 'symbol' => 'CHF', 'position' => 'left', 'decimals' => 2, 'name' => 'Swiss Franc' ),
		);
	}

	private function ensure_defaults(): void {
		if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
			add_option( self::SETTINGS_OPTION, self::default_settings(), '', false );
		}
		if ( false === get_option( self::CURRENCIES_OPTION, false ) ) {
			add_option( self::CURRENCIES_OPTION, $this->default_currencies(), '', false );
		}
	}

	private function default_currencies(): array {
		$base = $this->base_code();
		$lib = self::currency_library();
		$info = $lib[ $base ] ?? array( 'symbol' => $base, 'position' => 'left', 'decimals' => 2 );
		$out = array(
			$base => array( 'code' => $base, 'symbol' => $info['symbol'], 'position' => $info['position'], 'decimals' => $info['decimals'], 'rate' => 1, 'rounding' => 0, 'charm' => '', 'enabled' => 'yes', 'is_base' => 'yes' ),
		);
		foreach ( array( 'USD' => 0.0076, 'CAD' => 0.0104, 'EUR' => 0.0070 ) as $code => $rate ) {
			if ( $code === $base || empty( $lib[ $code ] ) ) { continue; }
			$out[ $code ] = array( 'code' => $code, 'symbol' => $lib[ $code ]['symbol'], 'position' => $lib[ $code ]['position'], 'decimals' => $lib[ $code ]['decimals'], 'rate' => $rate, 'rounding' => 0, 'charm' => '', 'enabled' => 'yes', 'is_base' => 'no' );
		}
		return $out;
	}

	public function reset_option_caches(): void {
		$this->settings_cache = null;
		$this->currencies_cache = null;
		$this->enabled_currencies_cache = null;
		$this->base_code_cache = null;
		$this->public_cache_variant_cache = null;
		$this->choice = null;
		$this->current = null;
	}

	public function settings(): array {
		if ( null !== $this->settings_cache ) { return $this->settings_cache; }
		$value = get_option( self::SETTINGS_OPTION, array() );
		$this->settings_cache = wp_parse_args( is_array( $value ) ? $value : array(), self::default_settings() );
		return $this->settings_cache;
	}

	public function currencies(): array {
		if ( null !== $this->currencies_cache ) { return $this->currencies_cache; }
		$value = get_option( self::CURRENCIES_OPTION, array() );
		$this->currencies_cache = is_array( $value ) ? $value : array();
		return $this->currencies_cache;
	}

	public function enabled_currencies(): array {
		if ( null !== $this->enabled_currencies_cache ) { return $this->enabled_currencies_cache; }
		$this->enabled_currencies_cache = array_filter( $this->currencies(), static function ( $c ) { return is_array( $c ) && 'yes' === ( $c['enabled'] ?? 'no' ); } );
		return $this->enabled_currencies_cache;
	}

	public function base_code(): string {
		if ( null !== $this->base_code_cache ) { return $this->base_code_cache; }
		$this->base_code_cache = strtoupper( sanitize_key( (string) get_option( 'woocommerce_currency', 'HTG' ) ) );
		return $this->base_code_cache;
	}

	public function get( string $code ): ?array {
		$all = $this->currencies();
		$code = strtoupper( sanitize_key( $code ) );
		return isset( $all[ $code ] ) && is_array( $all[ $code ] ) ? $all[ $code ] : null;
	}

	public function rate( string $code ): float {
		$code = strtoupper( sanitize_key( $code ) );
		if ( $code === $this->base_code() ) { return 1.0; }
		$c = $this->get( $code );
		$rate = is_array( $c ) ? (float) ( $c['rate'] ?? 0 ) : 0.0;
		return $rate > 0 ? $rate : 1.0;
	}

	public function is_valid( string $code ): bool {
		$code = strtoupper( sanitize_key( $code ) );
		return '' !== $code && isset( $this->enabled_currencies()[ $code ] );
	}

	public function default_currency(): string {
		$s = $this->settings();
		$default = strtoupper( sanitize_key( (string) ( $s['default_currency'] ?? '' ) ) );
		return $this->is_valid( $default ) ? $default : $this->base_code();
	}

	public function is_locked(): bool {
		return 'yes' === ( $this->settings()['lock_currency'] ?? 'no' );
	}

	public function display_choice(): string {
		if ( null !== $this->choice ) { return $this->choice; }
		// REST answers settle in base and must neither depend on nor change a
		// visitor preference: no ?dmc_currency, session, cookie or geolocation
		// read, and no Set-Cookie or session write.
		if ( $this->is_rest_request() ) { return $this->choice = $this->base_code(); }
		if ( $this->is_locked() ) {
			$this->forget_choice();
			return $this->choice = $this->default_currency();
		}

		$chosen = '';
		if ( isset( $_GET['dmc_currency'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public display preference only.
			$candidate = strtoupper( sanitize_key( (string) wp_unslash( $_GET['dmc_currency'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $this->is_valid( $candidate ) ) { $chosen = $candidate; $this->remember_choice( $chosen ); }
		}
		if ( ! $chosen && function_exists( 'WC' ) && WC() && WC()->session ) {
			$session = strtoupper( sanitize_key( (string) WC()->session->get( self::COOKIE ) ) );
			if ( $this->is_valid( $session ) ) {
				$chosen = $session;
				if ( $session === $this->default_currency() ) { $this->forget_choice(); }
			}
		}
		if ( ! $chosen && isset( $_COOKIE[ self::COOKIE ] ) ) {
			$candidate = strtoupper( sanitize_key( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
			if ( $this->is_valid( $candidate ) ) {
				$chosen = $candidate;
				// RC34: a cookie containing the public default creates no useful
				// variant, but it prevents LiteSpeed/CDN reuse. Retire legacy copies.
				if ( $candidate === $this->default_currency() ) { $this->forget_choice(); }
			}
		}
		if ( ! $chosen && 'yes' === ( $this->settings()['geolocate'] ?? 'no' ) ) {
			$geo = $this->geolocate_currency();
			if ( $this->is_valid( $geo ) ) {
				$chosen = $geo;
				// Do not emit a Set-Cookie header or create a Woo session for the
				// overwhelmingly common default-currency response. Non-default
				// geolocation remains isolated behind a private preference cookie.
				if ( $chosen !== $this->default_currency() ) { $this->persist( $chosen ); }
			}
		}
		if ( ! $chosen ) { $chosen = $this->default_currency(); }
		return $this->choice = $chosen;
	}

	public function current(): string {
		// Only the display preference is cached. The settlement decision is taken
		// again on every read: WooCommerce prices the session cart on wp_loaded,
		// before a payment request can be recognised, and a cached display
		// currency then created and charged the order in that currency.
		// Settlement requests skip the display lookup (no geolocation, no cookie).
		$chosen = $this->settle_in_base() ? $this->base_code() : $this->display_choice();
		return apply_filters( 'dmc_current_currency', $chosen );
	}

	public function set_current( string $code ): void {
		$code = strtoupper( sanitize_key( $code ) );
		if ( ! $this->is_valid( $code ) ) { return; }
		$this->choice = $code;
		$this->current = null;
		$this->remember_choice( $code );
	}

	private function remember_choice( string $code ): void {
		if ( $code === $this->default_currency() ) {
			$this->forget_choice();
			return;
		}
		$this->persist( $code );
	}

	private function cookie_options( int $expires ): array {
		return array(
			'expires'  => $expires,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}

	private function forget_choice(): void {
		if ( $this->is_rest_request() ) { return; } // REST never writes the preference.
		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			$stored = WC()->session->get( self::COOKIE );
			if ( null !== $stored && '' !== (string) $stored ) {
				WC()->session->set( self::COOKIE, null );
			}
		}
		if ( ! headers_sent() && isset( $_COOKIE[ self::COOKIE ] ) ) {
			setcookie( self::COOKIE, '', $this->cookie_options( time() - HOUR_IN_SECONDS ) );
		}
		unset( $_COOKIE[ self::COOKIE ] );
	}

	private function persist( string $code ): void {
		if ( $this->is_rest_request() ) { return; } // REST never writes the preference.
		if ( function_exists( 'WC' ) && WC() && WC()->session ) { WC()->session->set( self::COOKIE, $code ); }
		if ( ! headers_sent() ) {
			// PHP 7.4+ options syntax lets the preference carry SameSite=Lax;
			// WooCommerce's compatibility wrapper cannot express SameSite here.
			setcookie( self::COOKIE, $code, $this->cookie_options( time() + MONTH_IN_SECONDS ) );
		}
		$_COOKIE[ self::COOKIE ] = $code;
	}

	/**
	 * Whether this request can render a non-default currency and therefore must
	 * never enter a shared public page cache. This probe does not mutate state.
	 */
	public function public_cache_variant_required(): bool {
		if ( null !== $this->public_cache_variant_cache ) { return $this->public_cache_variant_cache; }
		if ( 'yes' !== ( $this->settings()['enabled'] ?? 'yes' ) || $this->is_locked() ) {
			return $this->public_cache_variant_cache = false;
		}

		/* PRO44: when the cache can key on this currency, a shared copy per
		 * currency is safe and far cheaper than refusing to cache at all. */
		if ( $this->cache_vary_safe() ) {
			return $this->public_cache_variant_cache = false;
		}

		$default = $this->default_currency();
		if ( '' !== $this->incoming_cookie && $this->is_valid( $this->incoming_cookie ) && $this->incoming_cookie !== $default ) {
			return $this->public_cache_variant_cache = true;
		}
		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			$session = strtoupper( sanitize_key( (string) WC()->session->get( self::COOKIE ) ) );
			if ( $this->is_valid( $session ) && $session !== $default ) {
				return $this->public_cache_variant_cache = true;
			}
		}
		if ( 'yes' === ( $this->settings()['geolocate'] ?? 'no' ) ) {
			$geo = $this->geolocate_currency();
			if ( $this->is_valid( $geo ) && $geo !== $default ) {
				return $this->public_cache_variant_cache = true;
			}
		}
		return $this->public_cache_variant_cache = false;
	}

	public function geolocate_currency(): string {
		if ( ! class_exists( 'WC_Geolocation' ) ) { return ''; }
		$geo = WC_Geolocation::geolocate_ip();
		$country = strtoupper( sanitize_key( (string) ( $geo['country'] ?? '' ) ) );
		$map = apply_filters( 'dmc_country_currency_map', array( 'HT'=>'HTG','US'=>'USD','CA'=>'CAD','DO'=>'DOP','FR'=>'EUR','DE'=>'EUR','ES'=>'EUR','GB'=>'GBP','BR'=>'BRL','MX'=>'MXN','CL'=>'CLP' ) );
		return isset( $map[ $country ] ) ? strtoupper( sanitize_key( (string) $map[ $country ] ) ) : '';
	}

	public function sync_base(): void {
		$base = $this->base_code();
		$currencies = $this->currencies();
		if ( ! $currencies ) { $currencies = $this->default_currencies(); }
		$stored_base = '';
		foreach ( $currencies as $code => $c ) { if ( 'yes' === ( $c['is_base'] ?? 'no' ) ) { $stored_base = $code; break; } }
		if ( $stored_base === $base ) { return; }
		$lib = self::currency_library();
		if ( ! isset( $currencies[ $base ] ) ) {
			$i = $lib[ $base ] ?? array( 'symbol'=>$base,'position'=>'left','decimals'=>2 );
			$currencies[ $base ] = array( 'code'=>$base,'symbol'=>$i['symbol'],'position'=>$i['position'],'decimals'=>$i['decimals'],'rate'=>1,'rounding'=>0,'charm'=>'','enabled'=>'yes','is_base'=>'no' );
		}
		$factor = (float) ( $currencies[ $base ]['rate'] ?? 0 );
		foreach ( $currencies as $code => &$c ) {
			$c['is_base'] = $code === $base ? 'yes' : 'no';
			if ( $code === $base ) { $c['rate'] = 1; continue; }
			if ( $factor > 0 ) { $c['rate'] = round( (float) ( $c['rate'] ?? 1 ) / $factor, 8 ); }
		}
		unset( $c );
		update_option( self::CURRENCIES_OPTION, $currencies, false );
		$this->choice = $this->current = null;
	}

	private function conversion_active(): bool {
		if ( 'yes' !== ( $this->settings()['enabled'] ?? 'yes' ) ) { return false; }
		if ( is_admin() && ! wp_doing_ajax() ) { return false; }
		return true;
	}

	public function settle_in_base(): bool {
		if ( is_admin() && ! wp_doing_ajax() ) { return false; }
		$s = $this->settings();
		$signal = $this->request_signal();

		// TeraWallet balances exist only in the store currency. The express sheet
		// pays with the wallet alone, the app labels every amount in the store
		// currency, and REST answers (Store API checkout under any namespace
		// spelling, catalogues behind shared caches) carry no visitor preference.
		// All of them settle in base whatever the settlement policy says.
		//
		// PRO39 (kept, and widened): the express sheet is a payment context and must
		// settle like one. Express_Payment::fields() issues the cart hash from
		// woocommerce_review_order_before_submit, which fires while
		// wc-ajax=delicat_express_form renders checkout/form-checkout.php. That request
		// is not is_checkout() and does not define WOOCOMMERCE_CHECKOUT, so the sheet
		// priced the cart in the SHOPPER'S display currency, while Express_Payment::pay()
		// defines WOOCOMMERCE_CHECKOUT one line before calling begin() and re-hashes the
		// cart in base currency -- hash_equals() could then never match. Every express
		// payment failed with "Le panier a changé. Actualisez pour vérifier le total.",
		// and refreshing never helped because the mismatch is structural, not a race.
		// pro.53 fixed it by listing delicat_express_form/delicat_express_pay among the
		// wc-ajax payment actions BELOW the settlement-policy gate; that left the bug in
		// place on a store that charges in the display currency. The `express` signal
		// here covers every delicat_express_* endpoint and returns before the gate, so
		// the sheet and the payment are always priced in the same currency.
		if ( 'express' === $signal || $this->is_rest_request() ) { return true; }

		$page = $this->page_contexts();
		// Wallet pages always stay in the WooCommerce base currency when enabled.
		if ( 'yes' === ( $s['wallet_base'] ?? 'yes' ) && $page['wallet'] ) { return true; }

		// Either an explicit base-settlement policy OR the historical display-only
		// toggle forces payment contexts back to base currency.
		if ( ! $this->base_settlement_policy() ) { return false; }
		// Request-level signals are cheap and checked on every read; a constant
		// can be defined after the first price read of the request.
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) { return true; }
		if ( 'ajax' === $signal ) { return true; }
		return $page['checkout'];
	}

	/** Any REST addressing: /wp-json/ (also encoded or behind index.php), ?rest_route, or REST_REQUEST. */
	private function is_rest_request(): bool {
		$signal = $this->request_signal();
		return 'app' === $signal || 'rest' === $signal || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	private function base_settlement_policy(): bool {
		$s = $this->settings();
		return 'base' === ( $s['settlement'] ?? 'base' ) || 'yes' !== ( $s['charge_in_currency'] ?? 'yes' );
	}

	/**
	 * What the request itself says, before any query exists: 'app' (Delicat App
	 * REST), 'express' (wallet-only express sheet), 'rest' (any other REST
	 * addressing, including ?rest_route and encoded paths), 'ajax' (any other
	 * WooCommerce AJAX endpoint) or ''. Resolved once per request.
	 */
	private function request_signal(): string {
		if ( null !== $this->request_signal ) { return $this->request_signal; }

		$route = '';
		foreach ( array( $_GET, $_POST ) as $source ) { // phpcs:ignore WordPress.Security.NonceVerification -- routing only; it can only force base.
			if ( isset( $source['rest_route'] ) && is_scalar( $source['rest_route'] ) ) { $route = strtolower( (string) wp_unslash( $source['rest_route'] ) ); break; }
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = explode( '?', $uri, 2 )[0];
		$rest = '' !== $route;
		$app  = $rest && 0 === strpos( '/' . ltrim( $route, '/' ), '/delicat-app/' );
		$prefix = '/' . trim( function_exists( 'rest_get_url_prefix' ) ? (string) rest_get_url_prefix() : 'wp-json', '/' ) . '/';
		foreach ( array_unique( array( strtolower( $path ), strtolower( rawurldecode( $path ) ) ) ) as $candidate ) {
			foreach ( array_unique( array( strtolower( $prefix ), '/wp-json/' ) ) as $needle ) {
				$at = strpos( $candidate, $needle );
				if ( false === $at ) { continue; }
				$rest = true;
				if ( 0 === strpos( substr( $candidate, $at + strlen( $needle ) ), 'delicat-app/' ) ) { $app = true; }
			}
		}

		$ajax = isset( $_GET['wc-ajax'] ) && is_scalar( $_GET['wc-ajax'] ) ? sanitize_key( (string) wp_unslash( $_GET['wc-ajax'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Read-only cart and catalogue refreshes keep the display currency. Every
		// other WooCommerce AJAX endpoint may create, price or pay an order.
		$display_only = array( 'get_refreshed_fragments', 'get_variation', 'add_to_cart', 'remove_from_cart', 'get_cart_totals', 'apply_coupon', 'remove_coupon', 'update_shipping_method' );

		if ( $app ) {
			$signal = 'app';
		} elseif ( 0 === strpos( $ajax, 'delicat_express_' ) ) {
			$signal = 'express';
		} elseif ( $rest ) {
			$signal = 'rest';
		} elseif ( '' !== $ajax && ! in_array( $ajax, $display_only, true ) ) {
			$signal = 'ajax';
		} else {
			$signal = '';
		}
		return $this->request_signal = $signal;
	}

	/**
	 * is_checkout() scans post content, so page contexts are resolved once after
	 * the `wp` action rather than on every price read. Before the main query
	 * exists both answer false; request-level signals cover payment endpoints.
	 */
	private function page_contexts(): array {
		if ( null !== $this->page_contexts ) { return $this->page_contexts; }
		/* Guarded like every other WordPress call in this class: settle_in_base() is
		 * reachable from harnesses and from very early boot, where did_action() may not
		 * be defined yet. No main query means no page context either way. */
		if ( ! function_exists( 'did_action' ) || ! did_action( 'wp' ) ) { return array( 'wallet' => false, 'checkout' => false ); }
		$endpoint = function_exists( 'is_wc_endpoint_url' );
		return $this->page_contexts = array(
			'wallet'   => $endpoint && is_wc_endpoint_url( 'woo-wallet' ),
			'checkout' => ( function_exists( 'is_checkout' ) && is_checkout() ) || ( $endpoint && is_wc_endpoint_url( 'order-pay' ) ),
		);
	}

	public function filter_currency( $currency ) {
		return $this->conversion_active() ? $this->current() : $currency;
	}

	public function filter_symbol( $symbol, $currency ) {
		$c = $this->get( (string) $currency );
		if ( $c && ! empty( $c['symbol'] ) ) { $symbol = $c['symbol']; }
		if ( 'yes' === ( $this->settings()['approx'] ?? 'no' ) && $currency !== $this->base_code() && ! $this->settle_in_base() ) { $symbol = '≈ ' . $symbol; }
		return $symbol;
	}

	public function filter_decimals( $decimals ) {
		if ( ! $this->conversion_active() ) { return $decimals; }
		$c = $this->get( $this->current() );
		return $c && isset( $c['decimals'] ) ? max( 0, min( 6, (int) $c['decimals'] ) ) : $decimals;
	}

	public function filter_price_format( $format, $currency_pos ) {
		if ( ! $this->conversion_active() ) { return $format; }
		$c = $this->get( $this->current() );
		$pos = $c['position'] ?? '';
		return array( 'left'=>'%1$s%2$s','right'=>'%2$s%1$s','left_space'=>'%1$s&nbsp;%2$s','right_space'=>'%2$s&nbsp;%1$s' )[ $pos ] ?? $format;
	}

	public function no_convert( $product ): bool {
		if ( ! $product instanceof WC_Product ) { return false; }
		if ( 'yes' === (string) $product->get_meta( '_dmc_no_convert', true ) ) { return true; }
		$parent = $product->get_parent_id();
		return $parent > 0 && 'yes' === (string) get_post_meta( $parent, '_dmc_no_convert', true );
	}

	private function product_override( WC_Product $product, string $code ): ?float {
		$meta = get_post_meta( $product->get_id(), '_dmc_price_' . $code, true );
		if ( '' !== $meta && is_numeric( $meta ) ) { return (float) $meta; }
		$parent = $product->get_parent_id();
		if ( $parent ) {
			$meta = get_post_meta( $parent, '_dmc_price_' . $code, true );
			if ( '' !== $meta && is_numeric( $meta ) ) { return (float) $meta; }
		}
		return null;
	}

	public function apply_rate( float $price, string $code ): float {
		$c = $this->get( $code );
		$converted = $price * $this->rate( $code );
		if ( $c && (float) ( $c['rounding'] ?? 0 ) > 0 ) { $step = (float) $c['rounding']; $converted = ceil( $converted / $step ) * $step; }
		if ( $c && '' !== (string) ( $c['charm'] ?? '' ) && is_numeric( $c['charm'] ) ) { $converted = floor( $converted ) + (float) $c['charm']; }
		$decimals = $c && isset( $c['decimals'] ) ? max( 0, min( 6, (int) $c['decimals'] ) ) : 2;
		return (float) apply_filters( 'dmc_converted_price', round( $converted, $decimals ), $price, $code );
	}

	public function convert_product_price( $price, $product = null ) {
		if ( ! $this->conversion_active() || '' === $price || null === $price ) { return $price; }
		$code = $this->current();
		if ( $code === $this->base_code() ) { return $price; }
		if ( $product instanceof WC_Product ) {
			if ( $this->no_convert( $product ) ) { return $price; }
			$override = $this->product_override( $product, $code );
			if ( null !== $override ) { return $override; }
		}
		return $this->apply_rate( (float) $price, $code );
	}

	public function convert_variation_price( $price, $variation = null, $product = null ) {
		return $this->convert_product_price( $price, $variation );
	}

	public function variation_prices_hash( $hash ) {
		if ( $this->conversion_active() ) { $hash['dbv9_dmc'] = $this->current(); $hash['dbv9_dmcr'] = $this->rate( $this->current() ); }
		return $hash;
	}

	public function convert_shipping( $rates ) {
		if ( ! $this->conversion_active() ) { return $rates; }
		$code = $this->current();
		if ( $code === $this->base_code() ) { return $rates; }
		foreach ( (array) $rates as $rate ) {
			if ( ! is_object( $rate ) || ! isset( $rate->cost ) ) { continue; }
			$rate->cost = $this->apply_rate( (float) $rate->cost, $code );
			if ( method_exists( $rate, 'get_taxes' ) ) {
				$taxes = $rate->get_taxes();
				foreach ( (array) $taxes as $k => $tax ) { if ( $tax ) { $taxes[ $k ] = $this->apply_rate( (float) $tax, $code ); } }
				$rate->taxes = $taxes;
			}
		}
		return $rates;
	}

	public function convert_coupon( $amount, $coupon ) {
		if ( ! $this->conversion_active() || ! $amount || ! $coupon instanceof WC_Coupon ) { return $amount; }
		if ( ! in_array( $coupon->get_discount_type(), array( 'fixed_cart', 'fixed_product' ), true ) ) { return $amount; }
		$code = $this->current();
		return $code === $this->base_code() ? $amount : $this->apply_rate( (float) $amount, $code );
	}

	public function settlement_notice(): void {
		if ( ! $this->settle_in_base() ) { return; }
		$choice = $this->display_choice(); $base = $this->base_code();
		if ( $choice === $base ) { return; }
		echo '<p class="dbv9-dmc-settle-notice">' . sprintf( esc_html__( 'Paiement traité en %1$s. Les prix étaient affichés en %2$s à titre de référence.', 'delicat-builder-v9' ), esc_html( $base ), esc_html( $choice ) ) . '</p>';
	}

	public function stamp_order( $order, $data ): void {
		if ( ! $order instanceof WC_Order ) { return; }
		$choice = $this->display_choice(); $base = $this->base_code();
		$order->update_meta_data( '_dmc_display_currency', $choice );
		$order->update_meta_data( '_dmc_base_currency', $base );
		$order->update_meta_data( '_dmc_rate', $this->rate( $choice ) );
		$order->update_meta_data( '_dmc_settled_currency', $order->get_currency() );
		$order->update_meta_data( '_dbv9_dmc_runtime', DELICAT_BUILDER_V9_VERSION );
	}

	/**
	 * WooCommerce priced the session cart on wp_loaded, before the query could
	 * say that this page settles in base. Reprice it once, so the page never
	 * shows display-currency totals under the base currency label.
	 */
	public function settle_page(): void {
		if ( ! $this->conversion_active() ) { return; }
		$page = $this->page_contexts();
		if ( ! $page['wallet'] && ! $page['checkout'] ) { return; }
		if ( ! $this->settle_in_base() || $this->display_choice() === $this->base_code() ) { return; }
		if ( ! did_action( 'woocommerce_cart_loaded_from_session' ) || ! function_exists( 'WC' ) ) { return; }
		$wc = WC();
		if ( ! is_object( $wc ) || ! is_object( $wc->cart ) || ! is_callable( array( $wc->cart, 'calculate_totals' ) ) || $wc->cart->is_empty() ) { return; }
		try {
			$wc->cart->calculate_totals();
		} catch ( Throwable $error ) {
			unset( $error ); // Checkout recalculates its totals again before any payment.
		}
	}

	/**
	 * Why an order must not be saved in its currency, or '' when it may.
	 * The base is the unfiltered woocommerce_currency option.
	 */
	private function currency_refusal( $order, array $payment_methods ): string {
		if ( ! $order instanceof WC_Order ) { return ''; }
		$base     = $this->base_code();
		$currency = strtoupper( sanitize_key( (string) $order->get_currency( 'edit' ) ) );
		if ( '' === $currency || $currency === $base ) { return ''; }
		// TeraWallet balances exist only in the store currency, whatever the settlement setting.
		if ( in_array( 'wallet', $payment_methods, true ) ) {
			/* translators: %s: store currency code */
			return sprintf( __( 'Delicat Wallet accepte uniquement les paiements en %s. Actualisez la page puis réessayez.', 'delicat-builder-v9' ), $base );
		}
		if ( 'yes' === ( $this->settings()['enabled'] ?? 'yes' ) && $this->base_settlement_policy() ) {
			/* translators: %s: store currency code */
			return sprintf( __( 'Le paiement se fait en %s. Actualisez la page puis réessayez.', 'delicat-builder-v9' ), $base );
		}
		return '';
	}

	/** Classic, express and app checkouts: WC_Checkout::create_order() turns this into a refusal. */
	public function guard_order_currency( $order, $data = array() ): void {
		if ( ! $order instanceof WC_Order ) { return; }
		$methods = array( sanitize_key( (string) $order->get_payment_method( 'edit' ) ) );
		if ( is_array( $data ) && isset( $data['payment_method'] ) && is_scalar( $data['payment_method'] ) ) {
			$methods[] = sanitize_key( (string) $data['payment_method'] );
		}
		$message = $this->currency_refusal( $order, $methods );
		if ( '' !== $message ) { throw new Exception( $message ); }
	}

	/** Store API checkout (block checkout, any namespace spelling), before payment runs. */
	public function guard_store_api_order( $order, $request = null ): void {
		if ( ! $order instanceof WC_Order ) { return; }
		$message = $this->currency_refusal( $order, array( sanitize_key( (string) $order->get_payment_method( 'edit' ) ) ) );
		if ( '' === $message ) { return; }
		$route_exception = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
		if ( class_exists( $route_exception ) ) { throw new $route_exception( 'delicat_currency_mismatch', $message, 409 ); }
		throw new Exception( $message );
	}

	/** Never offer the wallet while amounts, or the order being paid, are in another currency. */
	public function filter_wallet_gateway( $gateways ) {
		if ( ! is_array( $gateways ) || ! isset( $gateways['wallet'] ) || ! function_exists( 'get_woocommerce_currency' ) ) { return $gateways; }
		$base = $this->base_code();
		$paying = $this->order_pay_currency();
		if ( strtoupper( sanitize_key( (string) get_woocommerce_currency() ) ) !== $base || ( '' !== $paying && $paying !== $base ) ) {
			unset( $gateways['wallet'] );
		}
		return $gateways;
	}

	private function order_pay_currency(): string {
		global $wp;
		if ( ! did_action( 'wp' ) || ! is_object( $wp ) || ! is_array( $wp->query_vars ?? null ) || empty( $wp->query_vars['order-pay'] ) || ! function_exists( 'wc_get_order' ) ) { return ''; }
		$order = wc_get_order( absint( $wp->query_vars['order-pay'] ) );
		return $order instanceof WC_Order ? strtoupper( sanitize_key( (string) $order->get_currency( 'edit' ) ) ) : '';
	}

	public function admin_order_display( $order ): void {
		if ( ! $order instanceof WC_Order ) { return; }
		$choice = $order->get_meta( '_dmc_display_currency' ); $base = $order->get_meta( '_dmc_base_currency' );
		if ( ! $choice || $choice === $base ) { return; }
		echo '<p class="form-field form-field-wide"><strong>' . esc_html__( 'Multi Currency', 'delicat-builder-v9' ) . ':</strong> ' . esc_html( sprintf( 'Viewed %s • settled %s • rate %s', $choice, $order->get_meta( '_dmc_settled_currency' ) ?: $base, $order->get_meta( '_dmc_rate' ) ) ) . '</p>';
	}

	public function product_currency_fields(): void {
		$base = $this->base_code();
		echo '<div class="options_group dbv9-dmc-product-prices"><p class="form-field"><strong>' . esc_html__( 'Prix fixes par devise (optionnel)', 'delicat-builder-v9' ) . '</strong><br><span class="description">' . esc_html__( 'Laissez vide pour utiliser le taux de conversion.', 'delicat-builder-v9' ) . '</span></p>';
		foreach ( $this->enabled_currencies() as $code => $c ) {
			if ( $code === $base ) { continue; }
			woocommerce_wp_text_input( array( 'id'=>'_dmc_price_'.$code, 'label'=>sprintf( 'Prix %s (%s)', $code, $c['symbol'] ?? $code ), 'data_type'=>'price', 'desc_tip'=>true, 'description'=>__( 'Prix exact lorsque cette devise est sélectionnée.', 'delicat-builder-v9' ), 'value'=>get_post_meta( get_the_ID(), '_dmc_price_'.$code, true ) ) );
		}
		echo '</div>';
	}

	public function save_product_currency_fields( $product ): void {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_product', $product->get_id() ) ) { return; }
		foreach ( $this->currencies() as $code => $c ) {
			$key = '_dmc_price_' . $code;
			if ( ! array_key_exists( $key, $_POST ) ) { continue; } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo product save has its own nonce/capability boundary.
			$value = wc_clean( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === $value ) { $product->delete_meta_data( $key ); }
			elseif ( is_numeric( $value ) && (float) $value >= 0 ) { $product->update_meta_data( $key, wc_format_decimal( $value ) ); }
		}
	}

	private function enqueue_switcher_assets(): void {
		if ( $this->switcher_assets_enqueued ) { return; }
		$this->switcher_assets_enqueued = true;
		wp_enqueue_style( 'delicat-builder-v9-currency', DELICAT_BUILDER_V9_URL . 'assets/css/currency.css', array(), DELICAT_BUILDER_V9_VERSION );
		wp_enqueue_script( 'delicat-builder-v9-currency', DELICAT_BUILDER_V9_URL . 'assets/js/currency.js', array(), DELICAT_BUILDER_V9_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	private function flag( string $code ): string {
		$map = array( 'HTG'=>'🇭🇹','USD'=>'🇺🇸','CAD'=>'🇨🇦','EUR'=>'🇪🇺','GBP'=>'🇬🇧','DOP'=>'🇩🇴','BRL'=>'🇧🇷','MXN'=>'🇲🇽','JPY'=>'🇯🇵','CLP'=>'🇨🇱','AUD'=>'🇦🇺','CHF'=>'🇨🇭' );
		return $map[ $code ] ?? '';
	}

	public function render_switcher( array $atts = array() ): string {
		if ( $this->is_locked() ) { return ''; }
		$enabled = $this->enabled_currencies();
		if ( count( $enabled ) < 2 ) { return ''; }
		$this->enqueue_switcher_assets();
		$s = $this->settings(); $style = sanitize_key( (string) ( $atts['style'] ?? $s['switcher_style'] ?? 'dropdown' ) );
		if ( ! in_array( $style, array( 'dropdown','buttons','flags' ), true ) ) { $style = 'dropdown'; }
		$current = $this->display_choice(); $show_flags = 'yes' === ( $s['show_flags'] ?? 'yes' );
		ob_start();
		?>
		<div class="dbv9-currency dbv9-currency--<?php echo esc_attr( $style ); ?>" data-dbv9-currency data-current="<?php echo esc_attr( $current ); ?>">
		<?php if ( 'dropdown' === $style ) : ?>
			<select class="dbv9-currency__select" aria-label="<?php esc_attr_e( 'Choisir la devise', 'delicat-builder-v9' ); ?>" data-dbv9-currency-select>
			<?php foreach ( $enabled as $code => $c ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>><?php echo esc_html( ( $show_flags ? $this->flag( $code ) . ' ' : '' ) . $code . ' — ' . ( $c['symbol'] ?? $code ) ); ?></option><?php endforeach; ?>
			</select>
		<?php else : ?>
			<div class="dbv9-currency__buttons"><?php foreach ( $enabled as $code => $c ) : ?><button type="button" data-dbv9-currency-value="<?php echo esc_attr( $code ); ?>" class="<?php echo $current === $code ? 'is-active' : ''; ?>"><?php echo esc_html( ( $show_flags ? $this->flag( $code ) . ' ' : '' ) . ( 'flags' === $style ? $code : $code . ' ' . ( $c['symbol'] ?? $code ) ) ); ?></button><?php endforeach; ?></div>
		<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'style'=>'' ), is_array( $atts ) ? $atts : array(), 'dmc_switcher' );
		return $this->render_switcher( $atts );
	}

	public function rate_provider_attribution(): void {
		$s = $this->settings();
		if ( 'yes' !== ( $s['enabled'] ?? 'yes' ) || 'auto' !== ( $s['rate_mode'] ?? 'manual' ) ) { return; }
		if ( $this->display_choice() === $this->base_code() ) { return; }
		echo '<small class="dbv9-currency-attribution" style="display:block;margin:4px auto;text-align:center;font-size:10px;opacity:.55"><a href="https://www.exchangerate-api.com" target="_blank" rel="nofollow noopener noreferrer">Rates by Exchange Rate API</a></small>';
	}

	public function sticky_switcher(): void {
		if ( 'yes' !== ( $this->settings()['sticky'] ?? 'no' ) ) { return; }
		echo '<div class="dbv9-currency-sticky">' . $this->render_switcher() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function maybe_add_to_menu( $items, $args ) {
		$loc = (string) ( $this->settings()['menu_location'] ?? '' );
		if ( $loc && isset( $args->theme_location ) && $loc === $args->theme_location ) { $items .= '<li class="menu-item dbv9-currency-menu">' . $this->render_switcher() . '</li>'; }
		return $items;
	}

	public function cron_schedules( $schedules ) {
		$schedules['dmc_hourly'] = array( 'interval'=>HOUR_IN_SECONDS, 'display'=>'Deli currency hourly' );
		$schedules['dmc_daily'] = array( 'interval'=>DAY_IN_SECONDS, 'display'=>'Deli currency daily' );
		$schedules['dmc_weekly'] = array( 'interval'=>WEEK_IN_SECONDS, 'display'=>'Deli currency weekly' );
		return $schedules;
	}

	public function ensure_cron(): void {
		$s = $this->settings();
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( 'auto' !== ( $s['rate_mode'] ?? 'manual' ) ) {
			if ( $scheduled ) { wp_clear_scheduled_hook( self::CRON_HOOK ); }
			return;
		}
		if ( $scheduled ) { return; }
		$interval = in_array( $s['update_interval'] ?? '', array( 'dmc_hourly','dmc_daily','dmc_weekly' ), true ) ? $s['update_interval'] : 'dmc_daily';
		wp_schedule_event( time() + 60, $interval, self::CRON_HOOK );
	}

	public function reschedule( $old, $new ): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( is_array( $new ) && 'auto' === ( $new['rate_mode'] ?? 'manual' ) ) {
			$interval = in_array( $new['update_interval'] ?? '', array( 'dmc_hourly','dmc_daily','dmc_weekly' ), true ) ? $new['update_interval'] : 'dmc_daily';
			wp_schedule_event( time() + 60, $interval, self::CRON_HOOK );
		}
	}

	public function update_rates( $force = false ) {
		$s = $this->settings();
		if ( 'auto' !== ( $s['rate_mode'] ?? 'manual' ) && ! $force ) { return 0; }
		$base = $this->base_code();
		$response = wp_safe_remote_get( 'https://open.er-api.com/v6/latest/' . rawurlencode( $base ), array( 'timeout'=>10, 'redirection'=>2, 'limit_response_size'=>262144, 'headers'=>array( 'Accept'=>'application/json' ) ) );
		if ( is_wp_error( $response ) ) { return $response; }
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'dbv9_dmc_http', 'Currency provider returned a non-200 response.' ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || 'success' !== ( $body['result'] ?? '' ) || empty( $body['rates'] ) || ! is_array( $body['rates'] ) ) {
			return new WP_Error( 'dbv9_dmc_parse', 'Currency rates response could not be parsed.' );
		}
		if ( ! empty( $body['base_code'] ) && strtoupper( sanitize_key( (string) $body['base_code'] ) ) !== $base ) {
			return new WP_Error( 'dbv9_dmc_base_mismatch', 'Currency provider returned an unexpected base currency.' );
		}
		$currencies = $this->currencies(); $updated = 0; $warnings = array();
		$fee = max( -50, min( 100, (float) ( $s['handling_fee'] ?? 0 ) ) );
		$guard = max( 1, min( 100, (float) ( $s['rate_guard'] ?? 20 ) ) );
		foreach ( $currencies as $code => &$c ) {
			if ( $code === $base ) { $c['rate'] = 1; continue; }
			if ( ! isset( $body['rates'][ $code ] ) || ! is_numeric( $body['rates'][ $code ] ) ) { continue; }
			$new = (float) $body['rates'][ $code ] * ( 1 + $fee / 100 );
			$new = round( $new, 8 ); $old = (float) ( $c['rate'] ?? 0 );
			if ( $old > 0 && abs( $new - $old ) / $old > $guard / 100 ) { $warnings[ $code ] = array( 'old'=>$old,'new'=>$new ); continue; }
			if ( $new > 0 ) { $c['rate'] = $new; $updated++; }
		}
		unset( $c );
		update_option( self::CURRENCIES_OPTION, $currencies, false );
		update_option( self::RATES_UPDATED, time(), false );
		$warnings ? update_option( self::RATE_WARNINGS, array( 'time'=>time(),'items'=>$warnings ), false ) : delete_option( self::RATE_WARNINGS );
		$this->choice = $this->current = null;
		return $updated;
	}

	public function admin_update_rates(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'delicat-builder-v9' ) ); }
		check_admin_referer( 'delicat_builder_v9_dmc_update_rates' );
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'require_recent_for_security_change' ) ) ) { Delicat_Builder_V9_Identity_Bridge::require_recent_for_security_change( 'multi_currency_rates' ); }
		$result = $this->update_rates( true );
		$key = is_wp_error( $result ) ? 'rate_error' : 'rates_updated';
		$url = add_query_arg( array( 'page'=>'delicat-builder-v9-integrated', $key=>is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : absint( $result ) ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url ); exit;
	}

	public static function sanitize_settings( array $input, array $existing = array() ): array {
		$defaults = self::default_settings();
		$existing = wp_parse_args( is_array( $existing ) ? $existing : array(), $defaults );
		$out = $existing;
		foreach ( array( 'enabled','show_flags','geolocate','lock_currency','sticky','charge_in_currency','wallet_base','approx' ) as $key ) {
			// Checkbox fields are authoritative only when the settings form posts
			// the companion _present marker. This lets future/older dashboards omit
			// a field without silently resetting a migrated legacy preference.
			if ( ! empty( $input['_present'][ $key ] ) ) {
				$out[ $key ] = ! empty( $input[ $key ] ) ? 'yes' : 'no';
			} elseif ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = ! empty( $input[ $key ] ) ? 'yes' : 'no';
			}
		}
		$out['rate_mode'] = isset( $input['rate_mode'] ) && in_array( $input['rate_mode'], array( 'manual','auto' ), true ) ? $input['rate_mode'] : ( $existing['rate_mode'] ?? 'manual' );
		$out['update_interval'] = isset( $input['update_interval'] ) && in_array( $input['update_interval'], array( 'dmc_hourly','dmc_daily','dmc_weekly' ), true ) ? $input['update_interval'] : ( $existing['update_interval'] ?? 'dmc_daily' );
		$out['switcher_style'] = isset( $input['switcher_style'] ) && in_array( $input['switcher_style'], array( 'dropdown','buttons','flags' ), true ) ? $input['switcher_style'] : ( $existing['switcher_style'] ?? 'dropdown' );
		$out['settlement'] = isset( $input['settlement'] ) && in_array( $input['settlement'], array( 'base','selected' ), true ) ? $input['settlement'] : ( $existing['settlement'] ?? 'base' );
		$out['handling_fee'] = isset( $input['handling_fee'] ) ? max( -50, min( 100, (float) $input['handling_fee'] ) ) : (float) ( $existing['handling_fee'] ?? 0 );
		$out['rate_guard'] = isset( $input['rate_guard'] ) ? max( 1, min( 100, (float) $input['rate_guard'] ) ) : (float) ( $existing['rate_guard'] ?? 20 );
		$out['default_currency'] = array_key_exists( 'default_currency', $input ) ? strtoupper( sanitize_key( (string) $input['default_currency'] ) ) : strtoupper( sanitize_key( (string) ( $existing['default_currency'] ?? '' ) ) );
		$out['menu_location'] = array_key_exists( 'menu_location', $input ) ? sanitize_key( (string) $input['menu_location'] ) : sanitize_key( (string) ( $existing['menu_location'] ?? '' ) );
		$out['rate_provider'] = 'open_er_api';
		return $out;
	}

	public static function sanitize_currencies( array $input, string $base ): array {
		$lib = self::currency_library(); $out = array();
		foreach ( array_slice( $input, 0, 24, true ) as $code => $row ) {
			$code = strtoupper( sanitize_key( (string) $code ) );
			if ( ! preg_match( '/^[A-Z]{3}$/', $code ) || ! is_array( $row ) ) { continue; }
			$meta = $lib[ $code ] ?? array( 'symbol'=>$code,'position'=>'left','decimals'=>2 );
			$position = in_array( $row['position'] ?? '', array( 'left','right','left_space','right_space' ), true ) ? $row['position'] : $meta['position'];
			$rate = is_numeric( $row['rate'] ?? null ) ? max( 0.00000001, min( 1000000, (float) $row['rate'] ) ) : 1;
			$out[ $code ] = array(
				'code'=>$code,
				'symbol'=>substr( sanitize_text_field( (string) ( $row['symbol'] ?? $meta['symbol'] ) ), 0, 12 ),
				'position'=>$position,
				'decimals'=>max( 0, min( 6, absint( $row['decimals'] ?? $meta['decimals'] ) ) ),
				'rate'=>$code === $base ? 1 : $rate,
				'rounding'=>is_numeric( $row['rounding'] ?? null ) ? max( 0, min( 1000000, (float) $row['rounding'] ) ) : 0,
				'charm'=>( '' !== (string) ( $row['charm'] ?? '' ) && is_numeric( $row['charm'] ) ) ? (float) $row['charm'] : '',
				'enabled'=>! empty( $row['enabled'] ) ? 'yes' : 'no',
				'is_base'=>$code === $base ? 'yes' : 'no',
			);
		}
		if ( ! isset( $out[ $base ] ) ) {
			$meta = $lib[ $base ] ?? array( 'symbol'=>$base,'position'=>'left','decimals'=>2 );
			$out[ $base ] = array( 'code'=>$base,'symbol'=>$meta['symbol'],'position'=>$meta['position'],'decimals'=>$meta['decimals'],'rate'=>1,'rounding'=>0,'charm'=>'','enabled'=>'yes','is_base'=>'yes' );
		} else { $out[ $base ]['enabled']='yes'; $out[ $base ]['rate']=1; $out[ $base ]['is_base']='yes'; }
		return $out;
	}
}
