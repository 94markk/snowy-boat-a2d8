<?php
/**
 * Delicat — "Envoyer de l'argent" (wallet-to-wallet transfer).
 *
 * Self-contained rebuild. Owns its table, its validation, its execution and its
 * UI. It deliberately shares nothing with the recharge flow beyond the wallet
 * itself, so changes here cannot affect recharge.
 *
 * What the previous implementation got wrong, and what this fixes:
 *
 *  • The balance was checked when the transfer was PREPARED but never again
 *    when it was EXECUTED. With a five-minute window between the two, a client
 *    could prepare two transfers in two tabs — each affordable on its own — and
 *    confirm both, overdrawing the wallet. Every limit is now re-validated at
 *    execution time, against the live balance.
 *
 *  • The "already in progress" guard was a read-then-write on a transient,
 *    which two simultaneous requests can both pass. Execution is now claimed
 *    through a UNIQUE index in the database: the first request wins and every
 *    other one fails at the storage layer, where it cannot be raced.
 *
 *  • The transfers table was never actually created, so nothing was ever
 *    written to it — money moved between wallets with no audit trail at all.
 *    The table is created on activation and every attempt is recorded,
 *    including the failures.
 *
 *  • Recipients could be looked up by e-mail or username, which let anyone test
 *    whether an address had an account. Lookup is now by personal code only.
 *
 *  • Neither party was checked against the transaction ban list.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Delicat_Send_Money {

	const TABLE = 'delicat_transfers';

	/** Transfer limits (HTG). */
	const MIN_AMOUNT = 50.0;
	const MAX_AMOUNT = 10000.0;

	/** Fee charged to the sender, on top of the amount. */
	const FEE_RATE = 0.015;

	/** How long a prepared transfer stays confirmable. */
	const CONFIRM_TTL = 300; // 5 minutes

	/** Max completed transfers per sender per hour. */
	const HOURLY_LIMIT = 10;

	public function __construct() {
		add_shortcode( 'delicat_envoyer_argent', [ $this, 'render' ] );
	}

	/* ================================================================== */
	/* Storage                                                             */
	/* ================================================================== */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the transfers table.
	 *
	 * ref_key is UNIQUE: it is how a confirmation is claimed exactly once. A
	 * duplicate submit cannot insert a second row, so it cannot move money a
	 * second time either.
	 */
	public static function create_table(): void {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ref_key varchar(64) NOT NULL,
			sender_id bigint(20) unsigned NOT NULL,
			recipient_id bigint(20) unsigned NOT NULL,
			amount decimal(14,2) NOT NULL DEFAULT 0.00,
			fee decimal(14,2) NOT NULL DEFAULT 0.00,
			status varchar(20) NOT NULL DEFAULT 'pending',
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY ref_key (ref_key),
			KEY sender_id (sender_id),
			KEY recipient_id (recipient_id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ================================================================== */
	/* Helpers                                                             */
	/* ================================================================== */

	private static function wallet_available(): bool {
		return function_exists( 'woo_wallet' ) && woo_wallet() && isset( woo_wallet()->wallet );
	}

	public static function balance( int $user_id ): float {
		if ( ! self::wallet_available() ) return 0.0;
		return (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
	}

	/** Personal code for a user, generated on first use. */
	public static function user_code( int $uid ): string {
		$code = (string) get_user_meta( $uid, 'delicat_user_code', true );
		if ( $code === '' ) {
			do {
				$code = strtoupper( wp_generate_password( 8, false, false ) );
			} while ( self::code_taken( $code ) );
			update_user_meta( $uid, 'delicat_user_code', $code );
		}
		return $code;
	}

	private static function code_taken( string $code ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->usermeta}
			 WHERE meta_key = 'delicat_user_code' AND UPPER(meta_value) = %s",
			strtoupper( $code )
		) );
	}

	/**
	 * Resolve a recipient from their personal code.
	 *
	 * Code ONLY — deliberately not e-mail or username. Accepting those turns
	 * this box into an account-existence oracle: type any address, learn from
	 * the error message whether that person banks here.
	 */
	private static function resolve_recipient( string $input ) {
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $input ) );
		if ( $code === '' ) return false;

		global $wpdb;
		$uid = $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta}
			 WHERE meta_key = 'delicat_user_code' AND UPPER(meta_value) = %s LIMIT 1",
			$code
		) );
		return $uid ? get_user_by( 'id', (int) $uid ) : false;
	}

	private static function can_transact( int $uid ): bool {
		if ( class_exists( 'Delicat_Clients' ) && method_exists( 'Delicat_Clients', 'can_transact' ) ) {
			return (bool) Delicat_Clients::can_transact( $uid );
		}
		return true;
	}

	private static function hourly_count( int $uid ): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE sender_id = %d AND status = 'completed'
			   AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
			$uid
		) );
	}

	private static function fee_for( float $amount ): float {
		return round( $amount * self::FEE_RATE, 2 );
	}

	/* ================================================================== */
	/* Validation                                                          */
	/* ================================================================== */

	/**
	 * Validate a proposed transfer. Returns an error string, or null if fine.
	 *
	 * Called at BOTH prepare and execute. Running it only at prepare is what
	 * previously allowed an overdraft: the balance can change in between.
	 */
	private static function validate( int $sender, $recipient, float $amount ): ?string {
		if ( ! self::wallet_available() )        return 'Portefeuille indisponible.';
		if ( ! $recipient )                      return 'Code destinataire introuvable.';

		$rid = (int) $recipient->ID;
		if ( $rid === $sender )                  return 'Vous ne pouvez pas vous envoyer de l’argent.';
		if ( ! self::can_transact( $sender ) )   return 'Votre compte ne peut pas envoyer d’argent actuellement.';
		if ( ! self::can_transact( $rid ) )      return 'Ce destinataire ne peut pas recevoir d’argent actuellement.';

		if ( $amount < self::MIN_AMOUNT ) {
			return sprintf( 'Le montant minimum est de %s HTG.', number_format( self::MIN_AMOUNT, 0 ) );
		}
		if ( $amount > self::MAX_AMOUNT ) {
			return sprintf( 'Le montant maximum est de %s HTG.', number_format( self::MAX_AMOUNT, 0 ) );
		}

		$total = $amount + self::fee_for( $amount );
		if ( $total > self::balance( $sender ) ) {
			return 'Solde insuffisant pour le montant + les frais.';
		}

		if ( self::hourly_count( $sender ) >= self::HOURLY_LIMIT ) {
			return 'Limite d’envois atteinte pour cette heure. Réessayez plus tard.';
		}

		return null;
	}

	/* ================================================================== */
	/* Execution                                                           */
	/* ================================================================== */

	/**
	 * Move the money. Returns true, or an error string.
	 *
	 * $ref_key makes this idempotent: the row is inserted FIRST with a UNIQUE
	 * key, so a duplicate confirmation is rejected by the database before any
	 * wallet is touched. That is a guarantee, not a race we hope to win.
	 */
	private static function execute( string $ref_key, int $sender, int $recipient, float $amount, float $fee ) {
		global $wpdb;
		$table = self::table();
		$total = round( $amount + $fee, 2 );

		// Claim this transfer. INSERT IGNORE: first caller wins, duplicates fail.
		$claimed = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table}
			 (ref_key, sender_id, recipient_id, amount, fee, status, created_at)
			 VALUES (%s, %d, %d, %f, %f, 'pending', %s)",
			$ref_key, $sender, $recipient, $amount, $fee, current_time( 'mysql' )
		) );
		if ( (int) $claimed !== 1 ) {
			return 'Cet envoi a déjà été traité.';
		}
		$row_id = (int) $wpdb->insert_id;

		$wallet = woo_wallet()->wallet;

		$debit = $wallet->debit( $sender, $total, [
			'description' => sprintf( 'Envoi de %s HTG (frais %s)', $amount, $fee ),
		] );
		if ( ! $debit ) {
			$wpdb->update( $table, [ 'status' => 'failed', 'note' => 'debit_failed' ], [ 'id' => $row_id ] );
			return 'Débit impossible. Aucun montant n’a été prélevé.';
		}

		$credit = $wallet->credit( $recipient, $amount, [
			'description' => sprintf( 'Reçu de %s', self::user_code( $sender ) ),
		] );
		if ( ! $credit ) {
			// Put the sender's money back.
			$refund = $wallet->credit( $sender, $total, [
				'description' => 'Remboursement — envoi échoué',
			] );
			$wpdb->update( $table, [
				'status' => 'failed',
				'note'   => $refund ? 'credit_failed_refunded' : 'credit_failed_REFUND_FAILED',
			], [ 'id' => $row_id ] );

			if ( ! $refund ) {
				// Money is debited but neither delivered nor returned. Never let
				// this pass silently — it needs a human immediately.
				self::alert_admin_stuck( $row_id, $sender, $recipient, $total );
				return 'Erreur critique. Notre équipe a été alertée, votre argent sera restitué.';
			}
			return 'Le destinataire n’a pas pu être crédité. Votre montant a été remboursé.';
		}

		$wpdb->update( $table, [ 'status' => 'completed' ], [ 'id' => $row_id ] );

		self::notify_recipient( $recipient, $sender, $amount );
		do_action( 'delicat_transfer_completed', $row_id, $sender, $recipient, $amount, $fee );

		return true;
	}

	private static function alert_admin_stuck( int $row_id, int $sender, int $recipient, float $total ): void {
		$body = sprintf(
			"TRANSFERT BLOQUÉ — intervention requise.\n\n"
			. "Transfert #%d\nExpéditeur : %d\nDestinataire : %d\nMontant débité : %s HTG\n\n"
			. "Le débit a réussi mais NI le crédit NI le remboursement n'ont abouti.\n"
			. "Le solde de l'expéditeur doit être corrigé manuellement.",
			$row_id, $sender, $recipient, number_format( $total, 2 )
		);
		add_action( 'shutdown', function () use ( $body ) {
			wp_mail( get_option( 'admin_email' ), '🚨 Delicat — transfert bloqué', $body );
		}, 20 );
	}

	private static function notify_recipient( int $recipient, int $sender, float $amount ): void {
		$user = get_userdata( $recipient );
		if ( ! $user || ! $user->user_email ) return;

		$body = sprintf(
			"Bonjour %s,\n\nVous avez reçu %s HTG de %s sur votre portefeuille Delicat Store.\n\n"
			. "Consultez votre solde : %s\n",
			$user->display_name,
			number_format( $amount, 2 ),
			self::user_code( $sender ),
			home_url( '/' )
		);
		add_action( 'shutdown', function () use ( $user, $body ) {
			wp_mail( $user->user_email, '💰 Vous avez reçu de l’argent — Delicat Store', $body );
		}, 21 );
	}

	/* ================================================================== */
	/* Request handling                                                    */
	/* ================================================================== */

	/**
	 * Handle a POST. Returns [ notice, step, confirm ].
	 *
	 * Two steps on purpose: "prepare" shows the client exactly who they are
	 * paying and what it costs, "confirm" executes. The confirmation token is
	 * single-use and bound to the sender.
	 */
	private static function handle_post( int $uid ): array {
		$notice  = '';
		$step    = 'form';
		$confirm = [];

		if ( ! isset( $_POST['dsm_action'] ) ) return [ $notice, $step, $confirm ];

		$action = sanitize_text_field( wp_unslash( $_POST['dsm_action'] ) );

		/* ---------- Step 1: prepare ---------- */
		if ( $action === 'prepare'
			&& isset( $_POST['dsm_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsm_nonce'] ) ), 'dsm_prepare' ) ) {

			$input  = isset( $_POST['dsm_recipient'] ) ? (string) wp_unslash( $_POST['dsm_recipient'] ) : '';
			$amount = isset( $_POST['dsm_amount'] ) ? round( (float) wp_unslash( $_POST['dsm_amount'] ), 2 ) : 0.0;

			$recipient = self::resolve_recipient( $input );
			$error     = self::validate( $uid, $recipient, $amount );

			if ( $error !== null ) {
				$notice = self::msg( 'err', $error );
			} else {
				$fee   = self::fee_for( $amount );
				$token = wp_generate_password( 32, false, false );

				set_transient( 'dsm_tx_' . $uid . '_' . $token, [
					'to'     => (int) $recipient->ID,
					'amount' => $amount,
					'fee'    => $fee,
				], self::CONFIRM_TTL );

				$name = trim( $recipient->first_name . ' ' . $recipient->last_name );
				$confirm = [
					'token'  => $token,
					'name'   => $name !== '' ? $name : $recipient->display_name,
					'code'   => self::user_code( (int) $recipient->ID ),
					'amount' => $amount,
					'fee'    => $fee,
					'total'  => round( $amount + $fee, 2 ),
				];
				$step = 'confirm';
			}
		}

		/* ---------- Step 2: confirm ---------- */
		if ( $action === 'confirm'
			&& isset( $_POST['dsm_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsm_nonce'] ) ), 'dsm_confirm' ) ) {

			$token = isset( $_POST['dsm_token'] ) ? sanitize_text_field( wp_unslash( $_POST['dsm_token'] ) ) : '';
			$key   = 'dsm_tx_' . $uid . '_' . $token;
			$data  = $token !== '' ? get_transient( $key ) : false;

			// Burn the token immediately — a refresh must not replay it.
			delete_transient( $key );

			if ( ! $data || empty( $data['to'] ) ) {
				$notice = self::msg( 'err', 'Session expirée. Recommencez l’envoi.' );
			} else {
				$recipient = get_user_by( 'id', (int) $data['to'] );
				$amount    = round( (float) $data['amount'], 2 );

				// Re-validate against LIVE state. The balance, the ban list and
				// the hourly limit can all have changed since prepare.
				$error = self::validate( $uid, $recipient, $amount );
				if ( $error !== null ) {
					$notice = self::msg( 'err', $error );
				} else {
					$ref = 'TX' . $uid . '-' . substr( hash( 'sha256', $token ), 0, 40 );
					$res = self::execute( $ref, $uid, (int) $recipient->ID, $amount, self::fee_for( $amount ) );

					$notice = ( $res === true )
						? self::msg( 'ok', sprintf( '✅ %s HTG envoyés à %s.',
							number_format( $amount, 2 ), esc_html( self::user_code( (int) $recipient->ID ) ) ) )
						: self::msg( 'err', (string) $res );
				}
			}
		}

		return [ $notice, $step, $confirm ];
	}

	private static function msg( string $type, string $text ): string {
		return '<div class="dsm-msg dsm-' . esc_attr( $type ) . '">' . esc_html( $text ) . '</div>';
	}

	/* ================================================================== */
	/* Rendering                                                           */
	/* ================================================================== */

	public function render(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="dsm-wrap"><div class="dsm-msg dsm-info">Connectez-vous pour envoyer de l’argent.</div></div>';
		}
		if ( ! self::wallet_available() ) {
			return '<div class="dsm-wrap"><div class="dsm-msg dsm-err">Le portefeuille n’est pas disponible.</div></div>';
		}

		$uid = get_current_user_id();
		list( $notice, $step, $confirm ) = self::handle_post( $uid );

		$balance = self::balance( $uid );
		$my_code = self::user_code( $uid );
		$incoming_code = isset( $_GET['dsm_recipient'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['dsm_recipient'] ) ) ) : '';
		$incoming_code = preg_replace( '/[^A-Z0-9]/', '', $incoming_code );
		$qr_data = add_query_arg( 'dsm_recipient', rawurlencode( $my_code ), get_permalink() );

		// Open after a deliberate submit or after scanning a Delicat receive QR.
		// The query parameter is removed client-side immediately after it is consumed.
		$auto_open = ( $notice !== '' || $step === 'confirm' || $incoming_code !== '' );

		ob_start();
		?>
		<div class="dsm-wrap" id="envoyer-argent">

			<!-- Options popup -->
			<div class="dsm-modal" id="dsm-menu-modal" role="dialog" aria-modal="true"
			     aria-labelledby="dsm-menu-title" aria-hidden="true">
				<div class="dsm-backdrop" data-dsm-close></div>
				<div class="dsm-dialog dsm-dialog-menu" role="document">
					<div class="dsm-drag-handle" aria-hidden="true"></div>
					<div class="dsm-head">
						<span class="dsm-title" id="dsm-menu-title">💸 Envoyer de l’argent</span>
						<button type="button" class="dsm-x" data-dsm-close aria-label="Fermer">&times;</button>
					</div>
					<div class="dsm-menu">
						<button type="button" data-dsm-goto="send">
							<span class="dsm-mi">👤</span>
							<span class="dsm-mt"><strong>À un autre client</strong><small>Envoi par code personnel</small></span>
						</button>
						<button type="button" data-dsm-goto="qr">
							<span class="dsm-mi">🔳</span>
							<span class="dsm-mt"><strong>Recevoir par QR code</strong><small>Partagez votre code</small></span>
						</button>
						<button type="button" data-dsm-goto="history">
							<span class="dsm-mi">🕘</span>
							<span class="dsm-mt"><strong>Historique des envois</strong><small>Vos transferts récents</small></span>
						</button>
					</div>
				</div>
			</div>

			<!-- Send dialog -->
			<div class="dsm-modal<?php echo $auto_open ? ' is-open' : ''; ?>" id="dsm-send-modal"
			     role="dialog" aria-modal="true" aria-labelledby="dsm-send-title"
			     <?php echo $auto_open ? '' : 'aria-hidden="true"'; ?>>
				<div class="dsm-backdrop" data-dsm-close></div>
				<div class="dsm-dialog" role="document" data-dsm-step="<?php echo esc_attr( $step ); ?>">
					<div class="dsm-drag-handle" aria-hidden="true"></div>
					<div class="dsm-head">
						<span class="dsm-title-wrap"><span class="dsm-title" id="dsm-send-title">Envoyer de l’argent</span><small>Transfert sécurisé entre clients Delicat</small></span>
						<button type="button" class="dsm-x" data-dsm-close aria-label="Fermer">&times;</button>
					</div>

					<div class="dsm-balance">
						<span class="dsm-balance-icon" aria-hidden="true">◈</span>
						<span class="dsm-balance-copy"><small>Solde disponible</small><strong><?php echo esc_html( number_format( $balance, 2 ) ); ?> HTG</strong></span>
						<span class="dsm-secure-pill">Sécurisé</span>
					</div>

					<?php echo $notice; // already escaped by msg() ?>

					<?php if ( $step === 'confirm' && $confirm ) : ?>
						<div class="dsm-confirm">
							<p class="dsm-confirm-lead">Vérifiez avant de confirmer :</p>
							<div class="dsm-row"><span>Destinataire</span><strong><?php echo esc_html( $confirm['name'] ); ?></strong></div>
							<div class="dsm-row"><span>Code</span><code><?php echo esc_html( $confirm['code'] ); ?></code></div>
							<div class="dsm-row"><span>Montant</span><strong><?php echo esc_html( number_format( $confirm['amount'], 2 ) ); ?> HTG</strong></div>
							<div class="dsm-row"><span>Frais</span><span><?php echo esc_html( number_format( $confirm['fee'], 2 ) ); ?> HTG</span></div>
							<div class="dsm-row dsm-row-total"><span>Total débité</span><strong><?php echo esc_html( number_format( $confirm['total'], 2 ) ); ?> HTG</strong></div>
						</div>
						<form method="post" class="dsm-form">
							<?php wp_nonce_field( 'dsm_confirm', 'dsm_nonce' ); ?>
							<input type="hidden" name="dsm_action" value="confirm">
							<input type="hidden" name="dsm_token" value="<?php echo esc_attr( $confirm['token'] ); ?>">
							<div class="dsm-actions">
								<button type="submit" class="dsm-btn dsm-btn-primary">Confirmer l’envoi</button>
								<button type="button" class="dsm-btn dsm-btn-ghost" data-dsm-close data-dsm-reset>Annuler</button>
							</div>
						</form>
					<?php else : ?>
						<form method="post" class="dsm-form">
							<?php wp_nonce_field( 'dsm_prepare', 'dsm_nonce' ); ?>
							<input type="hidden" name="dsm_action" value="prepare">

							<div class="dsm-field">
								<label class="dsm-label" for="dsm-recipient">Code du destinataire</label>
								<div class="dsm-input-shell"><span class="dsm-input-icon" aria-hidden="true">◎</span><input class="dsm-input" id="dsm-recipient" name="dsm_recipient" type="text" inputmode="latin" autocomplete="off" spellcheck="false" placeholder="Ex : DSGZKE7X" maxlength="12" value="<?php echo esc_attr( $incoming_code ); ?>" required></div>
								<p class="dsm-hint">Demandez son code personnel au destinataire.</p>
							</div>

							<div class="dsm-field">
								<label class="dsm-label" for="dsm-amount">Montant à envoyer</label>
								<div class="dsm-input-shell dsm-amount-shell"><span class="dsm-input-icon" aria-hidden="true">G</span><input class="dsm-input" id="dsm-amount" name="dsm_amount" type="number" inputmode="decimal" min="<?php echo esc_attr( self::MIN_AMOUNT ); ?>" max="<?php echo esc_attr( self::MAX_AMOUNT ); ?>" step="0.01" placeholder="500" required><span class="dsm-input-suffix">HTG</span></div>
								<div class="dsm-quick" aria-label="Montants rapides"><button type="button" data-dsm-amount="250">250</button><button type="button" data-dsm-amount="500">500</button><button type="button" data-dsm-amount="1000">1 000</button><button type="button" data-dsm-amount="2500">2 500</button></div>
								<p class="dsm-hint">Entre <?php echo esc_html( number_format( self::MIN_AMOUNT, 0 ) ); ?> et <?php echo esc_html( number_format( self::MAX_AMOUNT, 0 ) ); ?> HTG · frais <?php echo esc_html( self::FEE_RATE * 100 ); ?>%</p>
							</div>

							<div class="dsm-live-summary" aria-live="polite">
								<span><small>Le destinataire reçoit</small><strong id="dsm-receives">0.00 HTG</strong></span>
								<span><small>Total débité</small><strong id="dsm-total-live">0.00 HTG</strong></span>
							</div>

							<div class="dsm-actions">
								<button type="submit" class="dsm-btn dsm-btn-primary"><span>Continuer</span><span aria-hidden="true">→</span></button>
								<button type="button" class="dsm-btn dsm-btn-ghost" data-dsm-close>Annuler</button>
							</div>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<!-- QR dialog -->
			<div class="dsm-modal" id="dsm-qr-modal" role="dialog" aria-modal="true"
			     aria-labelledby="dsm-qr-title" aria-hidden="true">
				<div class="dsm-backdrop" data-dsm-close></div>
				<div class="dsm-dialog dsm-dialog-qr" role="document">
					<div class="dsm-drag-handle" aria-hidden="true"></div>
					<div class="dsm-head">
						<span class="dsm-title" id="dsm-qr-title">🔳 Recevoir de l’argent</span>
						<button type="button" class="dsm-x" data-dsm-close aria-label="Fermer">&times;</button>
					</div>
					<p class="dsm-hint dsm-center">Faites scanner ce code pour recevoir de l’argent.</p>
					<div class="dsm-qr">
						<img loading="lazy" alt="QR code"
						     src="https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=2&data=<?php echo rawurlencode( $qr_data ); ?>">
					</div>
					<div class="dsm-code-box">
						<span>Mon code</span>
						<code id="dsm-my-code"><?php echo esc_html( $my_code ); ?></code>
						<button type="button" class="dsm-btn dsm-btn-ghost" data-dsm-copy="<?php echo esc_attr( $my_code ); ?>">Copier</button>
					</div>
				</div>
			</div>

		</div>
		<?php
		return ob_get_clean() . self::assets();
	}

	/* ================================================================== */
	/* Assets                                                              */
	/* ================================================================== */

	/**
	 * Printed once. Nowdoc throughout so no PHP interpolation can reach it.
	 *
	 * The dialogs are hidden with display:none rather than opacity/visibility.
	 * A transparent full-viewport element still swallows every click on the
	 * page, which is indistinguishable from the page having frozen.
	 */
	private static function assets(): string {
		static $done = false;
		if ( $done ) return '';
		$done = true;

		$css = <<<'DSM_CSS'
<style id="dsm-css">
.dsm-wrap{display:contents}
.dsm-modal{display:none;position:fixed;inset:0;z-index:2147483000;
  align-items:center;justify-content:center;padding:18px}
.dsm-modal.is-open{display:flex}
.dsm-backdrop{position:absolute;inset:0;background:rgba(13,27,77,.55);
  -webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);
  animation:dsmFade .2s ease both}
.dsm-dialog{position:relative;width:100%;max-width:460px;max-height:88vh;
  overflow-y:auto;-webkit-overflow-scrolling:touch;background:#fff;
  border-radius:22px;padding:22px 20px 20px;
  box-shadow:0 24px 60px rgba(13,27,77,.32);
  animation:dsmRise .26s cubic-bezier(.22,1,.36,1) both;
  font-family:'Poppins',system-ui,-apple-system,sans-serif;color:#0d1b4d}
.dsm-dialog-menu,.dsm-dialog-qr{max-width:420px}
@keyframes dsmFade{from{opacity:0}to{opacity:1}}
@keyframes dsmRise{from{opacity:0;transform:translateY(18px) scale(.97)}to{opacity:1;transform:none}}


.dsm-drag-handle{display:none;width:52px;height:6px;border-radius:999px;background:#cfd6e5;margin:-7px auto 12px;touch-action:none;cursor:grab;position:relative}
.dsm-drag-handle:after{content:"";position:absolute;left:-28px;right:-28px;top:-16px;bottom:-16px}
.dsm-dialog.is-dragging{transition:none!important;animation:none!important}

.dsm-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.dsm-title{font-family:'Playfair Display',Georgia,serif;font-size:20px;font-weight:700;line-height:1.25}
.dsm-x{border:0;background:#f1f3f9;color:#0d1b4d;width:34px;height:34px;min-width:34px;
  border-radius:50%;font-size:22px;line-height:1;cursor:pointer;
  transition:background .18s ease,transform .18s ease}
.dsm-x:hover{background:#e3e7f2;transform:rotate(90deg)}

.dsm-balance{background:#f4f7ff;border-radius:14px;padding:12px 14px;margin-bottom:14px;
  font-size:13px;color:#6b7794;display:flex;justify-content:space-between;align-items:center}
.dsm-balance strong{font-size:17px;color:#0d1b4d}

.dsm-menu{display:flex;flex-direction:column;gap:10px}
.dsm-menu button{display:flex;align-items:center;gap:14px;width:100%;text-align:left;
  border:1px solid #e8ebf3;background:#fbfcfe;border-radius:16px;padding:14px;
  cursor:pointer;font:inherit;color:inherit;
  transition:background .16s ease,border-color .16s ease,transform .12s ease}
.dsm-menu button:hover{background:#f2f5fd;border-color:#c9d3ee}
.dsm-menu button:active{transform:scale(.985)}
.dsm-mi{display:flex;align-items:center;justify-content:center;width:42px;height:42px;
  min-width:42px;border-radius:12px;background:#eef2ff;font-size:20px}
.dsm-mt{display:flex;flex-direction:column;line-height:1.3}
.dsm-mt strong{font-size:14px;font-weight:700}
.dsm-mt small{font-size:12px;color:#75809a}

.dsm-label{display:block;font-size:13px;font-weight:600;margin:12px 0 6px}
.dsm-input{width:100%;box-sizing:border-box;border:1px solid #dde3f0;border-radius:12px;
  padding:13px 14px;font:inherit;font-size:16px;color:#0d1b4d;background:#fff;
  transition:border-color .16s ease,box-shadow .16s ease}
.dsm-input:focus{outline:0;border-color:#5b7ccc;box-shadow:0 0 0 3px rgba(91,124,204,.18)}
#dsm-recipient{text-transform:uppercase;letter-spacing:.08em;font-weight:600}
.dsm-hint{margin:6px 0 0;font-size:12px;color:#75809a}
.dsm-center{text-align:center}

.dsm-actions{display:flex;gap:10px;margin-top:18px}
.dsm-btn{flex:1;border:0;border-radius:12px;padding:13px 16px;font:inherit;
  font-weight:700;font-size:14px;cursor:pointer;transition:transform .12s ease,filter .16s ease}
.dsm-btn:active{transform:scale(.985)}
.dsm-btn-primary{background:linear-gradient(135deg,#284696,#5b7ccc);color:#fff}
.dsm-btn-primary:hover{filter:brightness(1.06)}
.dsm-btn-ghost{background:#f1f3f9;color:#42506e}
.dsm-btn-ghost:hover{background:#e5e9f4}

.dsm-msg{border-radius:12px;padding:11px 13px;font-size:13px;margin-bottom:12px;line-height:1.45}
.dsm-ok{background:#e8f6ec;color:#1b5e20}
.dsm-err{background:#fdecec;color:#b3261e}
.dsm-info{background:#eef2ff;color:#284696}

.dsm-confirm{background:#f9fbff;border:1px solid #e6ecfa;border-radius:16px;padding:14px;margin-bottom:4px}
.dsm-confirm-lead{margin:0 0 10px;font-size:13px;color:#6b7794}
.dsm-row{display:flex;justify-content:space-between;align-items:center;gap:12px;
  padding:7px 0;font-size:14px;border-bottom:1px dashed #e6ecfa}
.dsm-row:last-child{border-bottom:0}
.dsm-row span{color:#6b7794}
.dsm-row code{background:#eef2ff;border-radius:6px;padding:2px 8px;font-size:13px;letter-spacing:.06em}
.dsm-row-total{margin-top:4px;padding-top:10px;border-top:2px solid #e6ecfa;font-size:15px}

.dsm-qr{display:flex;justify-content:center;padding:6px 0 14px}
.dsm-qr img{width:220px;height:220px;border-radius:14px;background:#fff}
.dsm-code-box{display:flex;align-items:center;justify-content:space-between;gap:10px;
  background:#f4f7ff;border-radius:14px;padding:12px 14px}
.dsm-code-box span{font-size:12px;color:#6b7794}
.dsm-code-box code{font-size:17px;font-weight:700;letter-spacing:.1em}

body.dsm-lock{overflow:hidden}

/* v6.0.1 — smoother premium Send Money UI */
.dsm-backdrop{background:rgba(8,16,42,.62);-webkit-backdrop-filter:blur(7px);backdrop-filter:blur(7px)}
.dsm-dialog{max-width:480px;border:1px solid rgba(255,255,255,.82);border-radius:26px;padding:22px;box-shadow:0 30px 80px rgba(6,18,52,.34),0 1px 0 rgba(255,255,255,.9) inset;scrollbar-width:thin}
.dsm-dialog:before{content:"";position:absolute;inset:0 0 auto;height:88px;border-radius:26px 26px 0 0;background:linear-gradient(135deg,rgba(40,70,150,.08),rgba(91,124,204,.02));pointer-events:none}
.dsm-head{position:relative;margin-bottom:18px}
.dsm-title-wrap{display:flex;flex-direction:column;gap:3px;min-width:0}
.dsm-title-wrap .dsm-title{font-family:'Poppins',system-ui,-apple-system,sans-serif;font-size:21px;font-weight:800;letter-spacing:-.025em}
.dsm-title-wrap small{font-size:11.5px;font-weight:500;color:#7a86a0}
.dsm-x{border:1px solid #e7ebf4;background:rgba(255,255,255,.88);box-shadow:0 6px 18px rgba(13,27,77,.09);width:38px;height:38px;min-width:38px}
.dsm-x:hover{background:#fff;transform:rotate(90deg) scale(1.04)}
.dsm-balance{position:relative;justify-content:flex-start;gap:11px;overflow:hidden;background:linear-gradient(135deg,#10265f,#3159b0);border:1px solid rgba(255,255,255,.16);border-radius:18px;padding:14px 15px;margin-bottom:16px;color:#fff;box-shadow:0 12px 30px rgba(40,70,150,.22)}
.dsm-balance:after{content:"";position:absolute;width:120px;height:120px;border-radius:50%;right:-45px;top:-64px;background:rgba(255,255,255,.10)}
.dsm-balance-icon{display:grid;place-items:center;width:40px;height:40px;min-width:40px;border-radius:13px;background:rgba(255,255,255,.14);font-size:21px}
.dsm-balance-copy{display:flex;flex-direction:column;gap:1px;position:relative;z-index:1}
.dsm-balance-copy small{font-size:11px;color:rgba(255,255,255,.72)}
.dsm-balance-copy strong{font-size:18px;color:#fff;letter-spacing:-.02em}
.dsm-secure-pill{position:relative;z-index:1;margin-left:auto;font-size:10px;font-weight:700;padding:6px 9px;border-radius:999px;background:rgba(255,255,255,.13);color:#fff}
.dsm-form{display:flex;flex-direction:column;gap:15px}
.dsm-field{display:flex;flex-direction:column;gap:7px}
.dsm-label{margin:0;font-size:12.5px;font-weight:700;color:#24365f}
.dsm-input-shell{display:flex;align-items:center;gap:8px;width:100%;box-sizing:border-box;border:1px solid #dfe5f1;border-radius:15px;background:#f9fbff;padding:0 12px;transition:border-color .18s ease,box-shadow .18s ease,background .18s ease,transform .18s ease}
.dsm-input-shell:focus-within{background:#fff;border-color:#5b7ccc;box-shadow:0 0 0 4px rgba(91,124,204,.12);transform:translateY(-1px)}
.dsm-input-icon{display:grid;place-items:center;width:29px;height:29px;min-width:29px;border-radius:9px;background:#eaf0ff;color:#3159b0;font-size:14px;font-weight:800}
.dsm-input-shell .dsm-input{border:0!important;box-shadow:none!important;background:transparent;padding:14px 2px;min-width:0}
.dsm-input-suffix{font-size:11px;font-weight:800;color:#5c6882;background:#edf1f8;border-radius:8px;padding:5px 7px}
.dsm-hint{margin:0 2px;font-size:11.5px;line-height:1.45}
.dsm-quick{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}
.dsm-quick button{border:1px solid #e1e6f0;background:#fff;color:#405170;border-radius:10px;padding:8px 4px;font:inherit;font-size:11.5px;font-weight:700;cursor:pointer;transition:all .15s ease}
.dsm-quick button:hover,.dsm-quick button.is-active{border-color:#5b7ccc;background:#eef3ff;color:#284696;transform:translateY(-1px)}
.dsm-live-summary{display:grid;grid-template-columns:1fr 1fr;gap:9px;background:#f5f8ff;border:1px solid #e5ebf7;border-radius:15px;padding:11px 12px}
.dsm-live-summary span{display:flex;flex-direction:column;gap:2px}
.dsm-live-summary span+span{border-left:1px solid #dde5f3;padding-left:11px}
.dsm-live-summary small{font-size:10.5px;color:#7a86a0}
.dsm-live-summary strong{font-size:13px;color:#18336f}
.dsm-actions{margin-top:2px}
.dsm-btn{min-height:47px;border-radius:14px}
.dsm-btn-primary{display:flex;align-items:center;justify-content:center;gap:10px;background:linear-gradient(135deg,#193875,#416fd0);box-shadow:0 10px 24px rgba(40,70,150,.22)}
.dsm-btn-primary:hover{filter:brightness(1.06);transform:translateY(-1px)}
.dsm-btn-ghost{background:#f1f4f9}
.dsm-menu button{border-radius:18px;padding:15px;box-shadow:0 5px 18px rgba(13,27,77,.04)}
.dsm-menu button:hover{transform:translateY(-1px);box-shadow:0 9px 24px rgba(13,27,77,.08)}
.dsm-confirm{border-radius:18px;background:linear-gradient(180deg,#fbfcff,#f5f8ff)}
.dsm-msg{border-radius:14px}

/* The legacy QR markup inside the balance card is superseded by this module's
   own dialog. It is left in the DOM by the card template but nothing opens it;
   force it hidden so it can never become an invisible click-catcher. */
.dw12-qr-modal,.delicat-qr-modal{display:none!important}

@media (max-width:520px){
  .dsm-modal{padding:0;align-items:flex-end}
  .dsm-dialog{max-width:none;border-radius:26px 26px 0 0;max-height:92vh;padding:20px 17px calc(18px + env(safe-area-inset-bottom));touch-action:pan-y;overscroll-behavior:contain}
  .dsm-head,.dsm-drag-handle{touch-action:none;-webkit-user-select:none;user-select:none}
  .dsm-drag-handle{display:block}
  .dsm-dialog:before{border-radius:26px 26px 0 0}
  .dsm-actions{position:sticky;bottom:0;background:linear-gradient(180deg,rgba(255,255,255,0),#fff 22%);padding-top:13px}
  .dsm-btn{min-height:50px}
  @keyframes dsmRise{from{opacity:0;transform:translateY(100%)}to{opacity:1;transform:none}}
}
@media (prefers-reduced-motion:reduce){
  .dsm-backdrop,.dsm-dialog{animation:none}
}
</style>
DSM_CSS;

		$js = <<<'DSM_JS'
<script id="dsm-js">
(function(){
  function boot(){
    /* Duplicate shortcodes would create duplicate IDs; keep the first of each
       so a stray copy can never sit invisibly over the page. */
    function one(sel){
      var all = document.querySelectorAll(sel);
      for (var i = 1; i < all.length; i++) { all[i].parentNode.removeChild(all[i]); }
      return all[0] || null;
    }
    var menu = one('#dsm-menu-modal');
    var send = one('#dsm-send-modal');
    var qr   = one('#dsm-qr-modal');
    if (!send) return;

    /* Move dialogs to <body>. An ancestor using backdrop-filter (the balance
       card does) becomes the containing block for position:fixed children,
       which traps a dialog inside the card instead of covering the screen. */
    [menu, send, qr].forEach(function(el){
      if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });

    var all = [menu, send, qr].filter(Boolean);
    var lastFocus = null;
    var amountInput = send.querySelector('#dsm-amount');
    var receives = send.querySelector('#dsm-receives');
    var totalLive = send.querySelector('#dsm-total-live');
    var quickButtons = send.querySelectorAll('[data-dsm-amount]');

    function money(value){
      var n = Number(value || 0);
      try { return n.toLocaleString('fr-HT',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' HTG'; }
      catch(_) { return n.toFixed(2) + ' HTG'; }
    }
    function updateAmountPreview(){
      if (!amountInput) return;
      var amount = Math.max(0, Number(amountInput.value || 0));
      var total = amount + (amount * 0.015);
      if (receives) receives.textContent = money(amount);
      if (totalLive) totalLive.textContent = money(total);
      for (var i=0;i<quickButtons.length;i++) {
        quickButtons[i].classList.toggle('is-active', Number(quickButtons[i].getAttribute('data-dsm-amount')) === amount);
      }
    }
    if (amountInput) { amountInput.addEventListener('input', updateAmountPreview); updateAmountPreview(); }

    function lock(){
      var open = all.some(function(el){ return el.classList.contains('is-open'); });
      document.body.classList.toggle('dsm-lock', open);
      return open;
    }

    function resetComposeForm(){
      var form = send.querySelector('form input[name="dsm_action"][value="prepare"]');
      if (!form) return;
      var recipient = send.querySelector('#dsm-recipient');
      if (recipient) recipient.value = '';
      if (amountInput) amountInput.value = '';
      for (var i=0;i<quickButtons.length;i++) quickButtons[i].classList.remove('is-active');
      updateAmountPreview();
    }

    function cleanGetUrl(){
      var url = new URL(window.location.href);
      url.searchParams.delete('dsm_recipient');
      url.hash = '';
      return url.pathname + (url.search ? url.search : '');
    }

    function consumeQrRecipient(){
      var url = new URL(window.location.href);
      if (!url.searchParams.has('dsm_recipient')) return;
      url.searchParams.delete('dsm_recipient');
      url.hash = '';
      try { window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '')); } catch(_){}
    }

    function resetServerConfirmation(){
      /* The confirmation view was produced by POST. Reloading the same clean URL
         as GET removes the prepared recipient/token so it cannot reappear. */
      window.location.replace(cleanGetUrl());
    }

    function closeAll(forceReset){
      var confirmationOpen = send.classList.contains('is-open') &&
        send.querySelector('.dsm-confirm') !== null;
      all.forEach(function(el){
        el.classList.remove('is-open');
        el.setAttribute('aria-hidden','true');
        var dialog = el.querySelector('.dsm-dialog');
        if (dialog) {
          dialog.classList.remove('is-dragging');
          dialog.style.transform = '';
          dialog.style.opacity = '';
        }
      });
      lock();
      resetComposeForm();
      if (confirmationOpen || forceReset) {
        resetServerConfirmation();
        return;
      }
      if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch(_){} }
    }

    function open(el){
      if (!el) return;
      lastFocus = document.activeElement;
      all.forEach(function(x){
        x.classList.remove('is-open');
        x.setAttribute('aria-hidden','true');
      });
      el.classList.add('is-open');
      el.removeAttribute('aria-hidden');
      lock();
      var f = el.querySelector('input,select,textarea,button');
      if (f) setTimeout(function(){ try { f.focus(); } catch(_){} }, 60);
    }

    /* Public API so any button anywhere can drive the same code path. */
    window.delicatSend = {
      menu:  function(){ open(menu); },
      send:  function(){ open(send); },
      qr:    function(){ open(qr);   },
      close: closeAll
    };

    function up(node, sel){
      while (node && node !== document) {
        if (node.matches && node.matches(sel)) return node;
        node = node.parentNode;
      }
      return null;
    }

    document.addEventListener('click', function(e){
      var closer = up(e.target, '[data-dsm-close]');
      if (closer) { e.preventDefault(); closeAll(closer.hasAttribute('data-dsm-reset')); return; }

      var goto = up(e.target, '[data-dsm-goto]');
      if (goto) {
        e.preventDefault();
        var t = goto.getAttribute('data-dsm-goto');
        if (t === 'send')      open(send);
        else if (t === 'qr')   open(qr);
        else if (t === 'history') {
          closeAll();
          var h = document.getElementById('historique')
               || document.querySelector('.wallet-transactions,.woo-wallet-transactions');
          if (h) h.scrollIntoView({ behavior:'smooth', block:'start' });
        }
        return;
      }

      var amountButton = up(e.target, '[data-dsm-amount]');
      if (amountButton && amountInput) {
        e.preventDefault();
        amountInput.value = amountButton.getAttribute('data-dsm-amount') || '';
        updateAmountPreview();
        try { amountInput.focus(); } catch(_){}
        return;
      }

      var copy = up(e.target, '[data-dsm-copy]');
      if (copy) {
        e.preventDefault();
        var val = copy.getAttribute('data-dsm-copy') || '';
        var done = function(){
          var old = copy.textContent; copy.textContent = '✅ Copié';
          setTimeout(function(){ copy.textContent = old; }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(val).then(done, done);
        } else {
          var ta = document.createElement('textarea');
          ta.value = val; document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); } catch(_){}
          document.body.removeChild(ta); done();
        }
        return;
      }

      /* Any control aimed at the transfer UI opens the options popup. */
      if (up(e.target, '[data-delicat-send-menu],[data-dsm-menu-open],a[href$="#envoyer-argent"]')) {
        e.preventDefault(); open(menu); return;
      }
    }, false);

    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' || e.keyCode === 27) closeAll();
    });

    /* Reliable mobile bottom-sheet gesture using Pointer Events. */
    all.forEach(function(modal){
      var dialog = modal.querySelector('.dsm-dialog');
      if (!dialog) return;
      var startY = 0, currentY = 0, startedAt = 0, dragging = false, pointerId = null;

      function canStart(e){
        if (window.innerWidth > 520 || !modal.classList.contains('is-open')) return false;
        if (dialog.scrollTop > 0) return false;
        return !!up(e.target, '.dsm-drag-handle,.dsm-head');
      }
      function begin(e){
        if (!canStart(e) || (e.pointerType === 'mouse' && e.button !== 0)) return;
        pointerId = e.pointerId;
        startY = currentY = e.clientY;
        startedAt = Date.now();
        dragging = true;
        dialog.classList.add('is-dragging');
        try { dialog.setPointerCapture(pointerId); } catch(_){}
        if (e.cancelable) e.preventDefault();
      }
      function move(e){
        if (!dragging || e.pointerId !== pointerId) return;
        currentY = e.clientY;
        var dy = Math.max(0, currentY - startY);
        if (dy <= 0) return;
        dialog.style.transform = 'translate3d(0,' + dy + 'px,0)';
        dialog.style.opacity = String(Math.max(.45, 1 - dy / 430));
        if (e.cancelable) e.preventDefault();
      }
      function finish(e){
        if (!dragging || (e && e.pointerId !== pointerId)) return;
        dragging = false;
        var dy = Math.max(0, currentY - startY);
        var elapsed = Math.max(1, Date.now() - startedAt);
        var velocity = dy / elapsed;
        dialog.classList.remove('is-dragging');
        try { dialog.releasePointerCapture(pointerId); } catch(_){}
        pointerId = null;
        if (dy > 68 || velocity > .42) {
          dialog.style.transition = 'transform .16s ease-in,opacity .16s ease-in';
          dialog.style.transform = 'translate3d(0,115%,0)';
          dialog.style.opacity = '0';
          setTimeout(function(){ closeAll(false); dialog.style.transition = ''; }, 165);
        } else {
          dialog.style.transition = 'transform .2s cubic-bezier(.22,1,.36,1),opacity .2s ease';
          dialog.style.transform = '';
          dialog.style.opacity = '';
          setTimeout(function(){ dialog.style.transition = ''; }, 220);
        }
      }
      dialog.addEventListener('pointerdown', begin, {passive:false});
      dialog.addEventListener('pointermove', move, {passive:false});
      dialog.addEventListener('pointerup', finish, {passive:true});
      dialog.addEventListener('pointercancel', finish, {passive:true});
    });

    /* Watchdog: never leave the page locked with nothing open. */
    setInterval(function(){
      if (document.body.classList.contains('dsm-lock')) lock();
    }, 1200);

    /* A browser refresh must never reopen a stale transfer sheet. */
    var nav = (performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || null;
    if (nav && nav.type === 'reload' && send.classList.contains('is-open')) {
      window.location.replace(cleanGetUrl());
      return;
    }
    consumeQrRecipient();
    lock();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
DSM_JS;

		return $css . $js;
	}
}
