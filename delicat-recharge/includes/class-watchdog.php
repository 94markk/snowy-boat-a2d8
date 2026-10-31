<?php
/**
 * Delicat Watchdog — forwarder heartbeat monitor.
 *
 * The SMS forwarder is the automation engine: it reads payment SMS and posts
 * them to the site. When it stops (phone off, no signal, battery optimisation,
 * lost default-SMS role, app killed) auto-validation stops SILENTLY. Nothing
 * errors — payments simply sit pending until a customer complains.
 *
 * This watchdog turns that silent failure into an alert. Every authenticated
 * forward updates 'delicat_forwarder_last_seen'; a scheduled check warns when
 * that timestamp goes stale, and confirms when it recovers.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Delicat_Watchdog {

	const CRON_HOOK   = 'delicat_watchdog_check';
	const LAST_SEEN   = 'delicat_forwarder_last_seen';
	const STATE_OPT   = 'delicat_watchdog_state';   // 'ok' | 'down'
	const HOURS_OPT   = 'delicat_watchdog_hours';   // silence tolerated
	const DEFAULT_HRS = 6;

	public function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'check' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
	}

	private function hours(): int {
		return max( 1, (int) get_option( self::HOURS_OPT, self::DEFAULT_HRS ) );
	}

	/** Seconds since the forwarder was last heard from, or null if never. */
	public static function silence_seconds(): ?int {
		$last = (int) get_option( self::LAST_SEEN, 0 );
		if ( $last <= 0 ) return null;
		return max( 0, time() - $last );
	}

	/**
	 * Alert when the forwarder has been quiet too long, and confirm recovery.
	 * State is remembered so you get one alert per outage, not one per hour.
	 */
	public function check(): void {
		$silence = self::silence_seconds();
		$limit   = $this->hours() * HOUR_IN_SECONDS;
		$state   = get_option( self::STATE_OPT, 'ok' );

		// Never seen: the forwarder was never configured. Don't nag.
		if ( $silence === null ) return;

		if ( $silence > $limit ) {
			if ( $state !== 'down' ) {
				update_option( self::STATE_OPT, 'down', false );
				$this->alert_down( $silence );
			}
			return;
		}

		if ( $state === 'down' ) {
			update_option( self::STATE_OPT, 'ok', false );
			$this->alert_back();
		}
	}

	private function alert_down( int $silence ): void {
		$hrs  = round( $silence / HOUR_IN_SECONDS, 1 );
		$body = sprintf(
			"⚠️ Aucun SMS reçu du forwarder depuis %s heures.\n\n"
			. "La validation automatique est probablement ARRÊTÉE.\n\n"
			. "À vérifier sur le téléphone forwarder :\n"
			. "• Il est allumé et chargé\n"
			. "• Connexion internet active\n"
			. "• L'app Delicat SMS Forwarder est ouverte\n"
			. "• Elle est toujours l'app SMS par défaut\n"
			. "• L'optimisation de batterie est désactivée pour elle\n\n"
			. "En attendant, validez manuellement depuis l'application.",
			$hrs
		);

		// Deferred so no customer request is slowed by mail/push.
		add_action( 'shutdown', function () use ( $body ) {
			wp_mail( get_option( 'admin_email' ), '⚠️ Delicat — validation automatique arrêtée', $body );
		}, 25 );

		if ( class_exists( 'Delicat_Push' ) && method_exists( 'Delicat_Push', 'send' ) ) {
			add_action( 'shutdown', function () use ( $hrs ) {
				try {
					Delicat_Push::send(
						'⚠️ Validation auto arrêtée',
						sprintf( 'Aucun SMS du forwarder depuis %s h. Vérifiez le téléphone.', $hrs ),
						'', true
					);
				} catch ( \Throwable $e ) {}
			}, 26 );
		}
	}

	private function alert_back(): void {
		add_action( 'shutdown', function () {
			wp_mail(
				get_option( 'admin_email' ),
				'✅ Delicat — validation automatique rétablie',
				"Le forwarder renvoie des SMS. La validation automatique fonctionne à nouveau."
			);
		}, 25 );

		if ( class_exists( 'Delicat_Push' ) && method_exists( 'Delicat_Push', 'send' ) ) {
			add_action( 'shutdown', function () {
				try {
					Delicat_Push::send( '✅ Validation auto rétablie',
						'Le forwarder est de nouveau actif.' );
				} catch ( \Throwable $e ) {}
			}, 26 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Status panel (rendered on the recharge admin page)                  */
	/* ------------------------------------------------------------------ */

	public static function render_panel(): void {
		if ( isset( $_POST['delicat_watchdog_hours'] ) && check_admin_referer( 'delicat_watchdog' ) ) {
			update_option( self::HOURS_OPT, max( 1, absint( $_POST['delicat_watchdog_hours'] ) ), false );
			echo '<div class="notice notice-success"><p>Seuil enregistré.</p></div>';
		}

		$silence = self::silence_seconds();
		$limit   = max( 1, (int) get_option( self::HOURS_OPT, self::DEFAULT_HRS ) );

		if ( $silence === null ) {
			$badge = '<span style="color:#888;font-weight:700;">— jamais vu</span>';
			$detail = 'Le forwarder n\'a encore jamais contacté le site.';
		} elseif ( $silence > $limit * HOUR_IN_SECONDS ) {
			$badge = '<span style="color:#c62828;font-weight:700;">🔴 ARRÊTÉ</span>';
			$detail = sprintf( 'Dernier SMS reçu il y a %s.', human_time_diff( time() - $silence, time() ) );
		} else {
			$badge = '<span style="color:#2e7d32;font-weight:700;">🟢 ACTIF</span>';
			$detail = sprintf( 'Dernier SMS reçu il y a %s.', human_time_diff( time() - $silence, time() ) );
		}
		?>
		<div style="margin:18px 0;border:1px solid #e0e0e0;border-radius:12px;background:#fff;padding:16px 18px;">
			<h2 style="margin:0 0 6px;font-size:16px;">📡 Validation automatique — état du forwarder</h2>
			<p style="margin:0 0 10px;font-size:15px;"><?php echo $badge; // phpcs:ignore ?>
				&nbsp;<span style="color:#555;"><?php echo esc_html( $detail ); ?></span></p>
			<form method="post" style="margin:0;">
				<?php wp_nonce_field( 'delicat_watchdog' ); ?>
				<label style="font-size:13px;">Alerter après
					<input type="number" name="delicat_watchdog_hours" min="1" max="72"
					       value="<?php echo esc_attr( $limit ); ?>" style="width:70px;"> heure(s) sans SMS
				</label>
				<button class="button">Enregistrer</button>
			</form>
			<p style="margin:8px 0 0;color:#666;font-size:12px;">
				Vous recevrez un email et une notification si la validation automatique s'arrête,
				puis une confirmation dès qu'elle repart.
			</p>
		</div>
		<?php
	}
}
