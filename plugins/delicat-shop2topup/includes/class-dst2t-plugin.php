<?php

defined( 'ABSPATH' ) || exit;

final class DST2T_Plugin {
	/** @var DST2T_Plugin|null */
	private static $instance;

	/** @var DST2T_Settings */
	private $settings;

	/** @var DST2T_API_Client */
	private $api;

	/** @var DST2T_Repository */
	private $repository;

	/** @var DST2T_Fulfillment */
	private $fulfillment;

	/** @var DST2T_Balance */
	private $balance;

	/** @var DST2T_Sync */
	private $sync;

	/** @var DST2T_Product */
	private $products;

	/** @var DST2T_Catalog */
	private $catalog;

	/** @var DST2T_Player_History */
	private $history;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'delicat-shop2topup', false, dirname( plugin_basename( DST2T_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		if ( get_option( 'dst2t_db_version' ) !== DST2T_VERSION ) {
			DST2T_Repository::install();
		}

		$vault          = new DST2T_Vault();
		$this->settings = new DST2T_Settings( $vault );
		$this->migrate_22_defaults();
		$this->migrate_23_defaults();
		DST2T_Brand::boot( $this->settings );

		$this->api         = new DST2T_API_Client( $this->settings );
		$this->repository  = new DST2T_Repository();
		$this->products    = new DST2T_Product( $this->api, $this->settings );
		$this->catalog     = new DST2T_Catalog( $this->api, $this->settings, $this->products );
		$this->history     = new DST2T_Player_History( $this->products, $vault, $this->settings );
		$this->fulfillment = new DST2T_Fulfillment( $this->api, $this->settings, $this->repository, $this->products, $vault );
		$this->balance     = new DST2T_Balance( $this->api, $this->settings );
		$this->sync        = new DST2T_Sync( $this->api, $this->settings, $this->products );

		$webhook = new DST2T_Webhook( $this->settings, $this->repository, $this->fulfillment );
		$admin   = new DST2T_Admin( $this->settings, $this->api, $this->repository, $this->products, $this->catalog, $this->fulfillment, $this->balance, $this->sync );

		$this->products->hooks();
		$this->catalog->hooks();
		$this->history->hooks();
		$this->fulfillment->hooks();
		$webhook->hooks();
		$this->balance->hooks();
		$this->sync->hooks();
		DST2T_Privacy::hooks();

		if ( is_admin() ) {
			$admin->hooks();
		}

		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		// Re-armed from wp-admin, WP-Cron and WP-CLI only, never on storefront requests.
		if ( DST2T_Scheduler::housekeeping_request() ) {
			add_action( 'init', array( $this, 'ensure_recurring_action' ), 30 );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( DST2T_FILE ), array( $this, 'action_links' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'DST2T_CLI' ) ) {
			DST2T_CLI::register();
		}
	}

	public static function activate() {
		DST2T_Repository::install();
		if ( false === get_option( DST2T_Settings::OPTION, false ) ) {
			add_option( DST2T_Settings::OPTION, DST2T_Settings::defaults(), '', false );
		}
		DST2T_Webhook::token();
	}

	public static function deactivate() {
		$actions = array(
			DST2T_Fulfillment::ACTION_RECONCILE,
			DST2T_Fulfillment::ACTION_PROCESS,
			DST2T_Fulfillment::ACTION_WEBHOOK,
			DST2T_Balance::ACTION_REFRESH,
			DST2T_Balance::ACTION_NOW,
			DST2T_Sync::ACTION,
			DST2T_Product::ACTION_REFRESH_MAPPING,
			DST2T_Catalog::ACTION_PROCESS,
		);

		foreach ( $actions as $action ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $action ); // hook only: array() args would skip jobs that carry arguments
			}
			wp_clear_scheduled_hook( $action );
		}

		delete_option( DST2T_Balance::OPTION_SCHEDULE );
		delete_option( DST2T_Sync::OPTION_SCHEDULE );
	}

	/** One-time feature defaults added by the catalog/import rebuild. */
	private function migrate_22_defaults() {
		if ( version_compare( (string) get_option( 'dst2t_feature_version', '0' ), '2.2.0', '>=' ) ) {
			return;
		}
		$stored = get_option( DST2T_Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( ! array_key_exists( 'player_history', $stored ) ) {
			$stored['player_history'] = 'yes';
		}
		// Existing installations used 0 (off) as the old default. This rebuild is
		// intended to keep supplier catalog/availability synchronized, so migrate
		// only that untouched old default to a conservative 15-minute schedule.
		if ( ! isset( $stored['catalog_sync_interval'] ) || '0' === (string) $stored['catalog_sync_interval'] ) {
			$stored['catalog_sync_interval'] = '15';
		}
		update_option( DST2T_Settings::OPTION, $stored, false );
		update_option( 'dst2t_feature_version', '2.2.0', false );
	}


	/** One-time defaults for grouped variations and protected manual HTG pricing. */
	private function migrate_23_defaults() {
		if ( version_compare( (string) get_option( 'dst2t_feature_version', '0' ), '2.3.0', '>=' ) ) {
			return;
		}
		$stored = get_option( DST2T_Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( ! array_key_exists( 'catalog_pricing_mode', $stored ) ) {
			$stored['catalog_pricing_mode'] = 'manual';
		}
		if ( ! array_key_exists( 'import_structure', $stored ) ) {
			$stored['import_structure'] = 'variable';
		}
		if ( ! array_key_exists( 'variation_attribute', $stored ) ) {
			$stored['variation_attribute'] = 'Package';
		}
		if ( 'manual' === $stored['catalog_pricing_mode'] ) {
			$stored['sync_catalog_prices'] = 'no';
		}
		update_option( DST2T_Settings::OPTION, $stored, false );
		update_option( 'dst2t_feature_version', '2.3.0', false );
	}

	public static function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DST2T_FILE, true );
		}
	}

	public function ensure_recurring_action() {
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( DST2T_Fulfillment::ACTION_RECONCILE, array(), DST2T_Fulfillment::ACTION_GROUP ) ) {
				as_schedule_recurring_action( time() + 30, 60, DST2T_Fulfillment::ACTION_RECONCILE, array(), DST2T_Fulfillment::ACTION_GROUP, true, DST2T_Scheduler::priority( DST2T_Fulfillment::ACTION_RECONCILE ) );
			}
			return;
		}

		if ( ! wp_next_scheduled( DST2T_Fulfillment::ACTION_RECONCILE ) ) {
			wp_schedule_event( time() + 60, 'dst2t_minute', DST2T_Fulfillment::ACTION_RECONCILE );
		}
	}

	public function cron_schedules( $schedules ) {
		$schedules['dst2t_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (top-up reconciliation)', 'delicat-shop2topup' ),
		);

		$balance = $this->balance ? $this->balance->interval_seconds() : 600;
		$schedules['dst2t_balance_interval'] = array(
			'interval' => max( 60, (int) $balance ),
			'display'  => __( 'Top-up wallet balance refresh', 'delicat-shop2topup' ),
		);

		$sync = $this->sync ? $this->sync->interval_seconds() : 0;
		$schedules['dst2t_sync_interval'] = array(
			'interval' => max( 900, (int) $sync ?: 3600 ),
			'display'  => __( 'Top-up catalog synchronization', 'delicat-shop2topup' ),
		);

		return $schedules;
	}

	public function action_links( $links ) {
		$url = add_query_arg( array( 'page' => DST2T_Admin::PAGE, 'tab' => 'settings' ), admin_url( 'admin.php' ) );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'delicat-shop2topup' ) . '</a>' );
		return $links;
	}

	public function woocommerce_missing_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This top-up integration requires WooCommerce to be installed and active.', 'delicat-shop2topup' ) . '</p></div>';
		}
	}

	public function api() {
		return $this->api;
	}

	public function repository() {
		return $this->repository;
	}

	public function fulfillment() {
		return $this->fulfillment;
	}

	public function balance() {
		return $this->balance;
	}

	public function sync() {
		return $this->sync;
	}

	public function products() {
		return $this->products;
	}

	public function catalog() {
		return $this->catalog;
	}

	public function settings() {
		return $this->settings;
	}
}
