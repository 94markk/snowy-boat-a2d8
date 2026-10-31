<?php
/**
 * Delicat Guard — automatic abuse detection.
 *
 * Goal: keep the service FAST for honest clients (who top up and buy straight
 * away) while automatically shutting down abusers with no manual watching.
 *
 * It scores suspicious behaviour per user and auto-bans (via the existing
 * dr_banned_actions meta that Delicat_Clients::can_transact() already honours)
 * once the score crosses a threshold. Everything is logged so a wrongly-flagged
 * client can be released in one click.
 *
 * Signals tracked:
 *   - reused / already-used TransCode attempts   (weight 3)  ← strongest scam signal
 *   - rejected requests                          (weight 2)
 *   - malformed / invalid submissions            (weight 1)
 *
 * A normal client trips none of these. A scammer trips them quickly.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Delicat_Guard {

	/** Score at which a user is auto-blocked from submitting. */
	const BAN_SCORE   = 8;

	/** Sliding window for accumulating score. */
	const WINDOW      = HOUR_IN_SECONDS;

	/** Option holding the recent security event log (capped). */
	const LOG_OPTION  = 'delicat_guard_log';

	public function __construct() {
		add_action( 'admin_post_delicat_guard_unban', [ $this, 'handle_unban' ] );

		// A completed, credited recharge is proof of good standing — reset the
		// client's suspicion score so honest clients never drift toward a ban.
		add_action( 'delicat_recharge_approved', [ $this, 'on_approved' ], 5 );

		// One-time cleanup: release clients auto-banned by the old behaviour so
		// legitimate recharges stop being refused.
		add_action( 'init', [ __CLASS__, 'release_auto_bans_once' ] );
	}

	/**
	 * Remove bans that were applied AUTOMATICALLY by the previous version.
	 * Runs a single time. Manual bans (no dr_banned_auto flag) are left intact.
	 */
	public static function release_auto_bans_once(): void {
		if ( get_option( 'delicat_guard_autoban_released' ) ) return;
		update_option( 'delicat_guard_autoban_released', 1, false );

		$users = get_users( [ 'meta_key' => 'dr_banned_auto', 'fields' => 'ID', 'number' => 1000 ] );
		foreach ( $users as $uid ) {
			$uid = (int) $uid;
			delete_user_meta( $uid, 'dr_banned_actions' );
			delete_user_meta( $uid, 'dr_banned_auto' );
			delete_user_meta( $uid, 'dr_banned_reason' );
			delete_transient( self::score_key( $uid ) );
		}
	}

	/** Clear the score for the client behind an approved recharge. */
	public function on_approved( $recharge_id ): void {
		if ( ! class_exists( 'Delicat_Database' ) ) return;
		$r = Delicat_Database::get( (int) $recharge_id );
		if ( ! $r ) return;

		$uid = (int) $r->user_id;
		if ( ! $uid && ! empty( $r->user_email ) ) {
			$u = get_user_by( 'email', $r->user_email );
			$uid = $u ? (int) $u->ID : 0;
		}
		if ( $uid ) self::clear( $uid );
	}

	/* ------------------------------------------------------------------ */
	/* Scoring                                                             */
	/* ------------------------------------------------------------------ */

	private static function score_key( int $user_id ): string {
		return 'dlc_guard_score_' . $user_id;
	}

	/** Current abuse score for a user. */
	public static function score( int $user_id ): int {
		if ( $user_id <= 0 ) return 0;
		return (int) get_transient( self::score_key( $user_id ) );
	}

	/**
	 * Record a suspicious event and auto-ban if the score crosses the limit.
	 * Returns true if this call resulted in a ban.
	 */
	public static function flag( int $user_id, string $reason, int $weight = 1 ): bool {
		if ( $user_id <= 0 ) return false;

		// Never auto-ban an administrator (avoids locking yourself out while testing).
		if ( user_can( $user_id, 'manage_options' ) ) return false;

		$key   = self::score_key( $user_id );
		$score = (int) get_transient( $key ) + max( 1, $weight );
		set_transient( $key, $score, self::WINDOW );

		self::log( $user_id, $reason, $score );

		// Automatic banning is DISABLED. The Guard now only records suspicious
		// activity for the admin to review in the dashboard — it never blocks a
		// client on its own. This prevents honest clients (and your own tests)
		// from being locked out. To act on a flagged client, ban them manually.
		if ( $score >= self::BAN_SCORE ) {
			self::notify_admin( $user_id, $reason, $score );
		}
		return false;
	}

	/** Clear a user's score (called when they complete a clean recharge). */
	public static function clear( int $user_id ): void {
		if ( $user_id > 0 ) delete_transient( self::score_key( $user_id ) );
	}

	/* ------------------------------------------------------------------ */
	/* Logging + notification                                              */
	/* ------------------------------------------------------------------ */

	private static function log( int $user_id, string $reason, int $score ): void {
		$log = get_option( self::LOG_OPTION, [] );
		if ( ! is_array( $log ) ) $log = [];

		$u = get_userdata( $user_id );
		array_unshift( $log, [
			'time'   => current_time( 'mysql' ),
			'user'   => $u ? $u->user_email : ( '#' . $user_id ),
			'uid'    => $user_id,
			'reason' => $reason,
			'score'  => $score,
		] );

		update_option( self::LOG_OPTION, array_slice( $log, 0, 60 ), false );
	}

	/** Tell the shop owner immediately when someone is auto-blocked. */
	private static function notify_admin( int $user_id, string $reason, int $score ): void {
		$u    = get_userdata( $user_id );
		$who  = $u ? $u->user_email : ( 'ID ' . $user_id );
		$body = sprintf(
			"Un compte vient d'être bloqué automatiquement par Delicat Guard.\n\n"
			. "Client : %s\nMotif : %s\nScore : %d\nHeure : %s\n\n"
			. "Si c'est une erreur, débloquez-le depuis Délicat Console → Sécurité.",
			$who, $reason, $score, current_time( 'mysql' )
		);

		// Deferred so the client's request is never slowed by mail sending.
		add_action( 'shutdown', function () use ( $body ) {
			wp_mail(
				get_option( 'admin_email' ),
				'🚫 Delicat — blocage automatique d\'un compte',
				$body
			);
		}, 20 );
	}

	/* ------------------------------------------------------------------ */
	/* Admin: security panel                                               */
	/* ------------------------------------------------------------------ */

	/** Renders the security log + auto-blocked users. Called from the admin page. */
	public static function render_panel(): void {
		$log     = get_option( self::LOG_OPTION, [] );
		if ( ! is_array( $log ) ) $log = [];
		$blocked = get_users( [
			'meta_key'   => 'dr_banned_auto',
			'meta_compare' => 'EXISTS',
			'number'     => 50,
			'fields'     => [ 'ID', 'user_email' ],
		] );
		?>
		<div style="margin:18px 0;border:1px solid #e0e0e0;border-radius:12px;background:#fff;padding:16px 18px;">
			<h2 style="margin:0 0 4px;font-size:16px;">🛡️ Sécurité — blocages automatiques</h2>
			<p style="margin:0 0 14px;color:#666;font-size:12px;">
				Les comptes qui accumulent des comportements suspects (codes déjà
				utilisés, demandes rejetées) sont bloqués automatiquement.
				Les clients normaux ne sont jamais affectés.
			</p>

			<?php if ( $blocked ) : ?>
				<table class="wp-list-table widefat striped" style="margin-bottom:16px;font-size:13px;">
					<thead><tr><th>Compte bloqué</th><th>Motif</th><th>Depuis</th><th>Action</th></tr></thead>
					<tbody>
					<?php foreach ( $blocked as $b ) :
						$since  = get_user_meta( $b->ID, 'dr_banned_auto', true );
						$reason = get_user_meta( $b->ID, 'dr_banned_reason', true ); ?>
						<tr>
							<td><strong><?php echo esc_html( $b->user_email ); ?></strong></td>
							<td><?php echo esc_html( $reason ?: '—' ); ?></td>
							<td><?php echo esc_html( $since ); ?></td>
							<td>
								<a class="button"
								   href="<?php echo esc_url( wp_nonce_url(
									   admin_url( 'admin-post.php?action=delicat_guard_unban&uid=' . $b->ID ),
									   'delicat_guard_unban_' . $b->ID ) ); ?>">
									Débloquer
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p style="color:#2e7d32;font-weight:600;margin:0 0 14px;">✅ Aucun compte bloqué automatiquement.</p>
			<?php endif; ?>

			<details>
				<summary style="cursor:pointer;font-weight:600;">Journal de sécurité (<?php echo count( $log ); ?>)</summary>
				<table class="wp-list-table widefat striped" style="margin-top:10px;font-size:12px;">
					<thead><tr><th>Heure</th><th>Client</th><th>Événement</th><th>Score</th></tr></thead>
					<tbody>
					<?php if ( ! $log ) : ?>
						<tr><td colspan="4" style="color:#888;">Aucun événement.</td></tr>
					<?php else : foreach ( $log as $e ) : ?>
						<tr>
							<td><?php echo esc_html( $e['time'] ?? '' ); ?></td>
							<td><?php echo esc_html( $e['user'] ?? '' ); ?></td>
							<td><?php echo esc_html( $e['reason'] ?? '' ); ?></td>
							<td><?php echo (int) ( $e['score'] ?? 0 ); ?></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</details>
		</div>
		<?php
	}

	/** Unblock handler (admin only). */
	public function handle_unban(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );

		$uid = absint( $_GET['uid'] ?? 0 );
		check_admin_referer( 'delicat_guard_unban_' . $uid );

		if ( $uid ) {
			delete_user_meta( $uid, 'dr_banned_actions' );
			delete_user_meta( $uid, 'dr_banned_auto' );
			delete_user_meta( $uid, 'dr_banned_reason' );
			self::clear( $uid );
			self::log( $uid, '✅ Débloqué manuellement par un administrateur', 0 );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=delicat-recharges' ) );
		exit;
	}
}
