<?php

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the reseller wallet balance current without operator action.
 *
 * A recurring background job refreshes the cached account state, the dashboard
 * polls a nonce-protected admin endpoint for live updates, and a configurable
 * threshold raises a low-balance warning before fulfillment starts failing.
 */
final class DST2T_Balance {
	const ACTION_REFRESH  = 'dst2t_refresh_balance';
	const OPTION_STATE    = 'dst2t_account_state';
	const OPTION_SCHEDULE = 'dst2t_balance_scheduled_interval';
	const OPTION_ALERTED  = 'dst2t_low_balance_alerted_at';
	const MIN_INTERVAL    = 60;
	const MAX_INTERVAL    = 86400;
	const LIVE_COOLDOWN   = 15;

	/** @var DST2T_API_Client */
	private $api;

	/** @var DST2T_Settings */
	private $settings;

	public function __construct( DST2T_API_Client $api, DST2T_Settings $settings ) {
		$this->api      = $api;
		$this->settings = $settings;
	}

	public function hooks() {
		add_action( self::ACTION_REFRESH, array( $this, 'refresh' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ), 31 );
		add_action( 'wp_ajax_dst2t_balance', array( $this, 'ajax_balance' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 80 );
	}

	/* ---------------------------------------------------------------- state */

	/**
	 * Cached account state. Never performs a network request.
	 *
	 * @return array
	 */
	public function state() {
		$stored = get_option( self::OPTION_STATE, array() );
		$state  = wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'wallet'     => '',
				'currency'   => 'USD',
				'enabled'    => null,
				'verified'   => null,
				'checked_at' => 0,
				'latency_ms' => 0,
				'error'      => '',
				'error_at'   => 0,
			)
		);

		$state['configured'] = $this->settings->credentials_configured();
		$state['age']        = $state['checked_at'] ? max( 0, time() - (int) $state['checked_at'] ) : 0;
		$state['stale']      = ! $state['checked_at'] || $state['age'] > ( $this->interval_seconds() * 3 );
		$state['low']        = $this->is_low( $state['wallet'] );
		$state['threshold']  = $this->threshold();
		$state['formatted']  = $this->format_amount( $state['wallet'], $state['currency'] );
		$state['interval']   = $this->interval_seconds();
		$state['auto']       = $this->auto_enabled();

		return $state;
	}

	/**
	 * Fetches the live account state and caches it.
	 *
	 * @param bool $force Ignore the short live-call cooldown.
	 * @return array The refreshed state, or the cached state when the call failed.
	 */
	public function refresh( $force = false ) {
		if ( ! $this->settings->credentials_configured() ) {
			return $this->state();
		}

		$previous = get_option( self::OPTION_STATE, array() );
		$previous = is_array( $previous ) ? $previous : array();

		if ( ! $force && ! empty( $previous['checked_at'] ) && ( time() - (int) $previous['checked_at'] ) < self::LIVE_COOLDOWN ) {
			return $this->state();
		}

		$started = microtime( true );
		try {
			$account = $this->api->account();
		} catch ( DST2T_API_Exception $error ) {
			$previous['error']    = $error->get_api_code();
			$previous['error_at'] = time();
			update_option( self::OPTION_STATE, $previous, false );
			return $this->state();
		} catch ( Throwable $error ) {
			$previous['error']    = 'UNEXPECTED_ERROR';
			$previous['error_at'] = time();
			update_option( self::OPTION_STATE, $previous, false );
			return $this->state();
		}

		$wallet = $this->extract_wallet( $account );
		$state  = array(
			'wallet'     => $wallet,
			'currency'   => $this->extract_currency( $account ),
			'enabled'    => array_key_exists( 'enabled', $account ) ? ! empty( $account['enabled'] ) : null,
			'verified'   => array_key_exists( 'verified', $account ) ? ! empty( $account['verified'] ) : null,
			'checked_at' => time(),
			'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'error'      => '',
			'error_at'   => 0,
		);
		update_option( self::OPTION_STATE, $state, false );

		$this->maybe_alert( $wallet );

		return $this->state();
	}

	/** Queues an out-of-band refresh, used right after wallet-spending activity. */
	public static function queue_refresh() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_REFRESH, array(), DST2T_Fulfillment::ACTION_GROUP, true );
			return;
		}
		if ( ! wp_next_scheduled( self::ACTION_REFRESH, array() ) ) {
			wp_schedule_single_event( time() + 10, self::ACTION_REFRESH );
		}
	}

	/* ------------------------------------------------------------ schedule */

	public function auto_enabled() {
		return $this->settings->enabled( 'balance_auto_refresh' );
	}

	public function interval_seconds() {
		$minutes = absint( $this->settings->get( 'balance_interval', 10 ) );
		$seconds = $minutes * MINUTE_IN_SECONDS;
		return max( self::MIN_INTERVAL, min( self::MAX_INTERVAL, $seconds ?: 600 ) );
	}

	public function next_run() {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::ACTION_REFRESH, array(), DST2T_Fulfillment::ACTION_GROUP );
			if ( is_numeric( $next ) ) {
				return (int) $next;
			}
			if ( true === $next ) {
				return time();
			}
		}
		return (int) wp_next_scheduled( self::ACTION_REFRESH );
	}

	public function ensure_schedule() {
		$interval  = $this->interval_seconds();
		$scheduled = (int) get_option( self::OPTION_SCHEDULE, 0 );
		$wanted    = $this->auto_enabled() && $this->settings->credentials_configured();

		if ( ! $wanted ) {
			if ( $scheduled ) {
				$this->unschedule();
				update_option( self::OPTION_SCHEDULE, 0, false );
			}
			return;
		}

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			$has = ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::ACTION_REFRESH, array(), DST2T_Fulfillment::ACTION_GROUP );
			if ( $has && $scheduled === $interval ) {
				return;
			}
			$this->unschedule();
			as_schedule_recurring_action( time() + 30, $interval, self::ACTION_REFRESH, array(), DST2T_Fulfillment::ACTION_GROUP, true );
			update_option( self::OPTION_SCHEDULE, $interval, false );
			return;
		}

		if ( wp_next_scheduled( self::ACTION_REFRESH ) && $scheduled === $interval ) {
			return;
		}
		$this->unschedule();
		wp_schedule_event( time() + 30, 'dst2t_balance_interval', self::ACTION_REFRESH );
		update_option( self::OPTION_SCHEDULE, $interval, false );
	}

	public function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_REFRESH, array(), DST2T_Fulfillment::ACTION_GROUP );
		}
		wp_clear_scheduled_hook( self::ACTION_REFRESH );
	}

	/* ------------------------------------------------------------- display */

	public function ajax_balance() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'dst2t_balance', 'nonce' );

		$force = ! empty( $_POST['force'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$state = $force ? $this->refresh( false ) : $this->state();
		if ( ! $force && ( $state['stale'] || ! $state['checked_at'] ) ) {
			$state = $this->refresh( false );
		}

		wp_send_json_success( $this->public_state( $state ) );
	}

	/** Shapes the state for JSON and for templates. */
	public function public_state( $state = null ) {
		$state = is_array( $state ) ? $state : $this->state();
		return array(
			'formatted'  => $state['formatted'],
			'currency'   => $state['currency'],
			'low'        => (bool) $state['low'],
			'stale'      => (bool) $state['stale'],
			'configured' => (bool) $state['configured'],
			'enabled'    => $state['enabled'],
			'verified'   => $state['verified'],
			'error'      => $state['error'],
			'latency_ms' => (int) $state['latency_ms'],
			'checked_at' => (int) $state['checked_at'],
			'age_label'  => $this->age_label( $state ),
			'next_run'   => $this->next_run(),
		);
	}

	public function age_label( $state = null ) {
		$state = is_array( $state ) ? $state : $this->state();
		if ( empty( $state['checked_at'] ) ) {
			return __( 'never checked', 'delicat-shop2topup' );
		}
		$age = max( 0, time() - (int) $state['checked_at'] );
		if ( $age < 60 ) {
			return __( 'just now', 'delicat-shop2topup' );
		}
		/* translators: %s: human readable time difference, for example "5 mins". */
		return sprintf( __( '%s ago', 'delicat-shop2topup' ), human_time_diff( (int) $state['checked_at'], time() ) );
	}

	public function admin_bar( $bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! $this->settings->enabled( 'admin_bar_balance' ) || ! $this->settings->credentials_configured() ) {
			return;
		}
		$state = $this->state();
		$title = sprintf(
			/* translators: 1: neutral supplier label, 2: formatted wallet balance. */
			__( '%1$s: %2$s', 'delicat-shop2topup' ),
			DST2T_Brand::short_label(),
			'' !== $state['formatted'] ? $state['formatted'] : __( 'n/a', 'delicat-shop2topup' )
		);
		$bar->add_node(
			array(
				'id'    => 'dst2t-balance',
				'title' => '<span class="dst2t-bar' . ( $state['low'] ? ' dst2t-bar-low' : '' ) . '">' . esc_html( $title ) . '</span>',
				'href'  => admin_url( 'admin.php?page=' . DST2T_Admin::PAGE ),
				'meta'  => array( 'title' => $this->age_label( $state ) ),
			)
		);
	}

	/* -------------------------------------------------------------- limits */

	public function threshold() {
		$value = trim( (string) $this->settings->get( 'low_balance_threshold', '0' ) );
		try {
			return DST2T_Decimal::normalize( '' === $value ? '0' : str_replace( ',', '.', $value ) );
		} catch ( Throwable $error ) {
			return '0.000000';
		}
	}

	public function is_low( $wallet ) {
		$threshold = $this->threshold();
		if ( 0 === DST2T_Decimal::compare( $threshold, '0' ) ) {
			return false;
		}
		try {
			return DST2T_Decimal::compare( DST2T_Decimal::normalize( $wallet ), $threshold ) < 0;
		} catch ( Throwable $error ) {
			return false;
		}
	}

	private function maybe_alert( $wallet ) {
		if ( ! $this->is_low( $wallet ) ) {
			delete_option( self::OPTION_ALERTED );
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				'Reseller wallet balance is below the configured threshold.',
				array( 'source' => 'delicat-shop2topup', 'threshold' => $this->threshold() )
			);
		}

		if ( ! $this->settings->enabled( 'low_balance_email' ) ) {
			return;
		}
		$last = absint( get_option( self::OPTION_ALERTED, 0 ) );
		if ( $last && ( time() - $last ) < 6 * HOUR_IN_SECONDS ) {
			return;
		}
		update_option( self::OPTION_ALERTED, time(), false );

		$to = get_option( 'woocommerce_stock_email_recipient' );
		$to = is_email( $to ) ? $to : get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Top-up wallet balance is low', 'delicat-shop2topup' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			sprintf(
				/* translators: 1: formatted balance, 2: configured threshold, 3: dashboard URL. */
				__( "The automatic top-up wallet balance is %1\$s, below your threshold of %2\$s.\n\nDigital orders will start failing when the wallet runs out.\n\nDashboard: %3\$s", 'delicat-shop2topup' ),
				$this->format_amount( $wallet, $this->extract_currency( array() ) ),
				$this->threshold(),
				admin_url( 'admin.php?page=' . DST2T_Admin::PAGE )
			)
		);
	}

	/* -------------------------------------------------------------- shapes */

	/** Accepts every documented and observed spelling of the wallet field. */
	private function extract_wallet( $account ) {
		foreach ( array( 'wallet', 'balance', 'wallet_balance', 'available_balance', 'credit', 'funds' ) as $key ) {
			if ( ! isset( $account[ $key ] ) ) {
				continue;
			}
			$value = $account[ $key ];
			if ( is_array( $value ) ) {
				foreach ( array( 'amount', 'value', 'balance' ) as $inner ) {
					if ( isset( $value[ $inner ] ) && is_scalar( $value[ $inner ] ) ) {
						$value = $value[ $inner ];
						break;
					}
				}
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			try {
				return DST2T_Decimal::normalize( str_replace( array( ',', ' ' ), array( '.', '' ), trim( (string) $value ) ) );
			} catch ( Throwable $error ) {
				continue;
			}
		}
		return '';
	}

	private function extract_currency( $account ) {
		foreach ( array( 'currency', 'wallet_currency', 'balance_currency' ) as $key ) {
			if ( ! empty( $account[ $key ] ) && is_scalar( $account[ $key ] ) ) {
				$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $account[ $key ] ) );
				if ( 3 === strlen( $code ) ) {
					return $code;
				}
			}
		}
		return 'USD';
	}

	private function format_amount( $wallet, $currency ) {
		$wallet = trim( (string) $wallet );
		if ( '' === $wallet ) {
			return '';
		}
		$currency = $currency ? $currency : 'USD';
		return number_format( (float) $wallet, 2, '.', ',' ) . ' ' . $currency;
	}
}
