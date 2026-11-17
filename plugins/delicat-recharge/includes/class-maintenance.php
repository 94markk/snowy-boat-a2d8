<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 6.6.4 — daily housekeeping (WP-Cron), nothing that touches money.
 *
 *  RCH-09: 6.6.5 moved SMS retention to Delicat_SMS_Store (every SMS is
 *          deleted for good after the retention window, 24 h minimum, by an
 *          hourly event). The recharge rows, the one-time TransCode ledger
 *          and the wallet ledger are never touched.
 *  RCH-08: up to 6.6.3 every approved recharge with Growth bonuses left an
 *          option "_delicat_bonus_done_<id>". Each one is copied onto its
 *          recharge row (bonus_done = 1) and then deleted, in small batches.
 *          Only options whose whole name is that prefix + digits are touched.
 */
class Delicat_Maintenance {

	const CRON_DAILY   = 'delicat_recharge_daily_maintenance';
	const CRON_BATCH   = 'delicat_recharge_maintenance_batch';
	const BATCH        = 200;  // legacy bonus markers per run
	const OPT_FLAGS_DONE = 'delicat_bonus_flags_migrated';

	public static function init(): void {
		add_action( self::CRON_DAILY, [ __CLASS__, 'run' ] );
		add_action( self::CRON_BATCH, [ __CLASS__, 'run' ] );
		add_action( 'init', [ __CLASS__, 'ensure_scheduled' ] );
	}

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			// First run soon after the update, then once a day.
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	public static function clear_schedules(): void {
		foreach ( [ self::CRON_DAILY, self::CRON_BATCH ] as $h ) {
			wp_clear_scheduled_hook( $h );
		}
	}

	public static function run(): void {
		if ( class_exists( 'Delicat_SMS_Store' ) ) Delicat_SMS_Store::purge( 50 ); // 6.6.5: also hourly on its own event
		if ( self::cleanup_bonus_flags( self::BATCH ) && ! wp_next_scheduled( self::CRON_BATCH ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::CRON_BATCH ); // more left
		}
	}

	/**
	 * Move up to $limit legacy bonus markers onto their recharge rows, then delete
	 * them. Returns true when more remain. Does nothing until the bonus_done column
	 * exists (the markers are the only record of a paid bonus until then).
	 */
	public static function cleanup_bonus_flags( int $limit = self::BATCH ): bool {
		if ( get_option( self::OPT_FLAGS_DONE ) || ! Delicat_Database::bonus_column_ready() ) return false;
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options}
			  WHERE option_name LIKE %s AND option_name REGEXP %s
			  ORDER BY option_id ASC LIMIT %d",
			$wpdb->esc_like( '_delicat_bonus_done_' ) . '%', '^_delicat_bonus_done_[0-9]+$', $limit
		) );
		foreach ( (array) $names as $name ) {
			if ( ! preg_match( '/^_delicat_bonus_done_([0-9]+)$/', (string) $name, $m ) ) continue;
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}delicat_recharges SET bonus_done = 1 WHERE id = %d", (int) $m[1] ) );
			delete_option( (string) $name );
		}
		if ( count( (array) $names ) < $limit ) {
			update_option( self::OPT_FLAGS_DONE, current_time( 'mysql' ), true );
			return false;
		}
		return true;
	}
}
