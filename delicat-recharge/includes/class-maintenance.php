<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 6.6.4 — daily housekeeping (WP-Cron), nothing that touches money.
 *
 *  RCH-09: the raw text of forwarded payment SMS (diagnostic log
 *          'delicat_natcash_log', field 'sms') is erased after N days
 *          (setting, default 180, 0 = keep). Only that text: the parsed
 *          fields (code, amount, verdict, time), the recharge rows, the
 *          one-time TransCode ledger and the wallet ledger are never touched.
 *  RCH-08: up to 6.6.3 every approved recharge with Growth bonuses left an
 *          option "_delicat_bonus_done_<id>". Each one is copied onto its
 *          recharge row (bonus_done = 1) and then deleted, in small batches.
 *          Only options whose whole name is that prefix + digits are touched.
 */
class Delicat_Maintenance {

	const CRON_DAILY   = 'delicat_recharge_daily_maintenance';
	const CRON_BATCH   = 'delicat_recharge_maintenance_batch';
	const OPT_SMS_DAYS = 'delicat_sms_retention_days';
	const DEFAULT_DAYS = 180;
	const MIN_DAYS     = 30;   // instant verify reads the log: never shorter
	const BATCH        = 200;  // legacy bonus markers per run
	const OPT_FLAGS_DONE = 'delicat_bonus_flags_migrated';

	public static function init(): void {
		add_action( self::CRON_DAILY, [ __CLASS__, 'run' ] );
		add_action( self::CRON_BATCH, [ __CLASS__, 'run' ] );
		add_action( 'init', [ __CLASS__, 'ensure_scheduled' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'delicat_settings_extra', [ __CLASS__, 'settings_ui' ] );
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

	/** Retention in days; 0 = keep the text. */
	public static function sms_days(): int {
		$v = get_option( self::OPT_SMS_DAYS, null );
		if ( null === $v || '' === $v ) return self::DEFAULT_DAYS;
		$d = (int) $v;
		return $d <= 0 ? 0 : max( self::MIN_DAYS, $d );
	}

	public static function run(): void {
		self::purge_sms_text();
		if ( self::cleanup_bonus_flags( self::BATCH ) && ! wp_next_scheduled( self::CRON_BATCH ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::CRON_BATCH ); // more left
		}
	}

	/**
	 * Erase the raw SMS text of log entries older than the retention.
	 * Idempotent: an emptied entry is skipped next time. Returns entries emptied.
	 */
	public static function purge_sms_text( ?int $now = null ): int {
		$days = self::sms_days();
		if ( $days <= 0 ) return 0;
		$entries = get_option( 'delicat_natcash_log', [] );
		if ( ! is_array( $entries ) || ! $entries ) return 0;
		$cutoff = ( null === $now ? time() : $now ) - $days * DAY_IN_SECONDS;
		$done   = 0;
		foreach ( $entries as $i => $e ) {
			if ( ! is_array( $e ) || '' === (string) ( $e['sms'] ?? '' ) || empty( $e['time'] ) ) continue;
			try {
				$ts = ( new DateTime( (string) $e['time'], wp_timezone() ) )->getTimestamp();
			} catch ( Exception $ex ) {
				continue; // unreadable date: leave it alone
			}
			if ( $ts >= $cutoff ) continue;
			$entries[ $i ]['sms']        = '';
			$entries[ $i ]['sms_purged'] = current_time( 'mysql' );
			$done++;
		}
		if ( $done ) update_option( 'delicat_natcash_log', $entries, false );
		return $done;
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

	/* ---- settings (Delicat Console → Paramètres) ------------------------ */

	public static function register_settings(): void {
		register_setting( 'delicat_settings_group', self::OPT_SMS_DAYS, [
			'sanitize_callback' => function ( $v ) {
				if ( null === $v || '' === trim( (string) $v ) ) return (string) self::DEFAULT_DAYS;
				$d = absint( $v );
				return (string) ( 0 === $d ? 0 : max( self::MIN_DAYS, $d ) );
			},
		] );
	}

	public static function settings_ui(): void {
		?>
		<h2>Conservation des SMS de paiement</h2>
		<table class="form-table">
			<tr><th>Effacer le texte des SMS après</th>
				<td><input type="number" min="0" step="1" name="<?php echo esc_attr( self::OPT_SMS_DAYS ); ?>" value="<?php echo esc_attr( (string) self::sms_days() ); ?>" class="small-text"> jours
					<p class="description">Par défaut 180 jours (minimum <?php echo (int) self::MIN_DAYS; ?>). Seul le texte brut des SMS reçus (page Natcash SMS) est effacé, chaque nuit ; les recharges, les crédits du portefeuille et la liste des TransCode déjà utilisés sont toujours conservés. 0 = garder le texte.</p></td></tr>
		</table>
		<?php
	}
}
