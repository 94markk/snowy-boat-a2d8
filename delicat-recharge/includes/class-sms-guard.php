<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Delicat SMS Guard — anti-spoofing for forwarded payment SMS (v6.3.0).
 *
 * THREAT: a scammer sends a fake "Natcash" / "MonCash" SMS to the forwarder
 * phone using a Twilio-type service with an alphanumeric sender ID. The fake
 * lands in the SAME thread as the real ones, so the sender name is identical
 * and the old "sender must be Natcash" rule cannot tell them apart. The body
 * is fully under the scammer's control (amount, name, time, TransCode).
 *
 * DEFENCE: every forwarded receipt now has to pass proofs the scammer cannot
 * forge because they depend on facts only the real operator knows:
 *
 *   1. BALANCE CHAIN  — "Balans ou / Votre solde" in the SMS must equal the
 *      last trusted balance + the received amount. A spoofer does not know
 *      the live balance. (The fakes in the wild show the SAME balance before
 *      and after "receiving" 1,000,000 HTG — impossible.)
 *   2. TRANSCODE DATE — every real Natcash TransCode observed so far is
 *      14 digits and starts with the transaction date (YYMMDD). The date
 *      written inside the SMS must agree with the code. Fakes seen so far
 *      start with "260873" — day 73 does not exist.
 *   3. TIMESTAMP      — the time written in the SMS must be close to the
 *      moment the forwarder received it. Real receipts arrive in seconds.
 *   4. SMSC ROUTE     — optional: pin the operator's service-centre number
 *      once the forwarder sends it (a spoofed SMS travels via a foreign SMSC).
 *
 * DAMAGE CONTROL (policy): per-SMS auto-credit cap, rolling hourly cap, and
 * a temporary lockdown after any suspect SMS (all credits go to manual
 * approval for N minutes) with an urgent push/email alert.
 *
 * Anything that fails is HELD — logged with the exact reasons, never
 * credited, never deleted. A genuine payment that trips a check is ONE tap
 * away on the "Natcash SMS" page: "Authentique → créditer & re-sync".
 */
class Delicat_SMS_Guard {

	/* ---- options ------------------------------------------------------ */
	const OPT_MODE       = 'delicat_smsg_mode';          // hold | log
	const OPT_TIME_TOL   = 'delicat_smsg_time_tol';      // minutes, 0 = off
	const OPT_TC_FORMAT  = 'delicat_smsg_tc_format';     // 1 | 0
	const OPT_BAL_ABS    = 'delicat_smsg_bal_abs';       // HTG
	const OPT_BAL_PCT    = 'delicat_smsg_bal_pct';       // %
	const OPT_AUTO_MAX   = 'delicat_smsg_auto_max';      // HTG per SMS
	const OPT_HOUR_MAX   = 'delicat_smsg_hour_max';      // HTG per rolling hour
	const OPT_LOCK_MIN   = 'delicat_smsg_lockdown_min';  // minutes, 0 = off
	const OPT_SMSC       = 'delicat_smsg_smsc';          // allowed SMSC list
	const OPT_MC_BAL     = 'delicat_smsg_mc_need_bal';   // MonCash: require balance
	const OPT_LOCK_UNTIL = 'delicat_smsg_lockdown_until';
	const OPT_HOUR       = 'delicat_smsg_hour';          // rolling credit counter
	const OPT_SMSC_SEEN  = 'delicat_smsg_smsc_seen';     // learned SMSC values
	const ANCHOR_PREFIX  = 'delicat_smsg_anchor_';       // + provider

	/** Flags that are EVIDENCE of a forged SMS (trigger lockdown + urgent alert). */
	const SPOOF_FLAGS = [ 'tc_format', 'tc_date', 'time', 'balance_chain', 'balance_missing', 'smsc' ];

	private static $notified = [];

	/* ---- bootstrap ---------------------------------------------------- */

	public static function init(): void {
		add_action( 'admin_init',           [ __CLASS__, 'register_settings' ] );
		add_action( 'delicat_settings_extra', [ __CLASS__, 'settings_ui' ] );
		add_action( 'admin_post_delicat_smsg_trust',  [ __CLASS__, 'handle_trust' ] );
		add_action( 'admin_post_delicat_smsg_unlock', [ __CLASS__, 'handle_unlock' ] );
		add_action( 'admin_post_delicat_smsg_anchor', [ __CLASS__, 'handle_anchor' ] );
		add_action( 'rest_api_init',        [ __CLASS__, 'routes' ] );
	}

	public static function defaults(): array {
		return [
			self::OPT_MODE      => 'hold',
			self::OPT_TIME_TOL  => 30,
			self::OPT_TC_FORMAT => '1',
			self::OPT_BAL_ABS   => 2,
			self::OPT_BAL_PCT   => 3,
			self::OPT_AUTO_MAX  => 25000,
			self::OPT_HOUR_MAX  => 100000,
			self::OPT_LOCK_MIN  => 30,
			self::OPT_SMSC      => '',
			self::OPT_MC_BAL    => '0',
		];
	}

	public static function opt( string $key ) {
		$d = self::defaults();
		$v = get_option( $key, null );
		if ( $v === null || $v === '' ) {
			// Empty string is a valid value only for the SMSC list.
			return ( $key === self::OPT_SMSC && $v === '' ) ? '' : ( $d[ $key ] ?? '' );
		}
		return $v;
	}

	public static function mode(): string {
		return self::opt( self::OPT_MODE ) === 'log' ? 'log' : 'hold';
	}

	/* ---- parsing helpers --------------------------------------------- */

	/** Amount string "25,131.4" → float. */
	private static function num( string $s ): float {
		return (float) str_replace( ',', '', $s );
	}

	/**
	 * Extract balance / SMS timestamp / payer number from a receipt body.
	 *   Natcash: "... nan 03:11 02/09/2026 ... Balans ou: 25,131.4 HTG ..."
	 *            "... a 14:55 26/06/2026 ... Votre solde: 12,878 HTG ..."
	 *   MonCash: "... After fee is deducted your new balance is: G36,169.82. Txn ID:..."
	 */
	public static function parse_meta( string $provider, string $raw ): array {
		$out = [ 'balance' => null, 'sms_ts' => null, 'sms_time' => '', 'sms_ymd' => '', 'payer' => '' ];

		if ( $provider === 'moncash' ) {
			if ( preg_match( '/balance\s+is\s*:?\s*G\s*([\d,]+\.?\d*)/i', $raw, $m ) ) {
				$out['balance'] = self::num( $m[1] );
			}
			if ( preg_match( '/from\s+(\d{6,})/i', $raw, $m ) ) $out['payer'] = $m[1];
			return $out;
		}

		// Natcash
		if ( preg_match( '/(?:balans\s+ou|votre\s+solde|solde)\s*:?\s*([\d,]+\.?\d*)\s*HTG/iu', $raw, $m ) ) {
			$out['balance'] = self::num( $m[1] );
		}
		if ( preg_match( '/(\d{1,2}):(\d{2})\s+(\d{1,2})\/(\d{1,2})\/(\d{4})/', $raw, $m ) ) {
			$H = (int) $m[1]; $i = (int) $m[2]; $d = (int) $m[3]; $mo = (int) $m[4]; $Y = (int) $m[5];
			if ( checkdate( $mo, $d, $Y ) && $H < 24 && $i < 60 ) {
				$out['sms_time'] = sprintf( '%02d:%02d %02d/%02d/%04d', $H, $i, $d, $mo, $Y );
				$out['sms_ymd']  = sprintf( '%02d%02d%02d', $Y % 100, $mo, $d );
				// The SMS time is Haiti local time. Interpret it in the site
				// timezone AND in America/Port-au-Prince and keep the reading
				// closest to now, so a wrong WordPress timezone (UTC) does not
				// flag every genuine receipt.
				$best = null;
				foreach ( [ wp_timezone(), 'America/Port-au-Prince' ] as $tz ) {
					try {
						$zone = $tz instanceof DateTimeZone ? $tz : new DateTimeZone( $tz );
						$ts   = ( new DateTime( sprintf( '%04d-%02d-%02d %02d:%02d:00', $Y, $mo, $d, $H, $i ), $zone ) )->getTimestamp();
						if ( $best === null || abs( time() - $ts ) < abs( time() - $best ) ) $best = $ts;
					} catch ( Exception $e ) { /* skip */ }
				}
				$out['sms_ts'] = $best;
			} else {
				// A date that does not exist is itself a forgery signal.
				$out['sms_time'] = 'invalide (' . $m[0] . ')';
			}
		}
		if ( preg_match( '/(?:nan|de)\s+.*?(\d{6,})\s+(?:nan|a)\s+\d{1,2}:\d{2}/iu', $raw, $m ) ) {
			$out['payer'] = $m[1];
		}
		return $out;
	}

	/** Digits-only form of an SMSC / phone number. */
	private static function norm_smsc( string $s ): string {
		return preg_replace( '/\D+/', '', $s );
	}

	/* ---- the verdict --------------------------------------------------- */

	/**
	 * Stateless checks (no anchor needed): TransCode format/date + timestamp.
	 * Used live AND to re-evaluate log entries written by older versions.
	 */
	public static function stateless_flags( string $provider, array $parsed, string $raw, ?int $receipt_ts, bool $check_time = true ): array {
		$flags = [];
		$meta  = self::parse_meta( $provider, $raw );

		if ( $provider === 'natcash' ) {
			$tc = (string) ( $parsed['trans_code'] ?? '' );
			if ( self::opt( self::OPT_TC_FORMAT ) === '1' && $tc !== '' ) {
				if ( ! preg_match( '/^\d{14}$/', $tc ) ) {
					$flags['tc_format'] = sprintf( 'TransCode « %s » : un vrai TransCode Natcash a 14 chiffres.', $tc );
				} elseif ( $meta['sms_ymd'] !== '' ) {
					$expect = $meta['sms_ymd'];
					if ( substr( $tc, 0, 6 ) !== $expect ) {
						$flags['tc_date'] = sprintf(
							'Le TransCode commence par %s mais la date du SMS (%s) donne %s.',
							substr( $tc, 0, 6 ), $meta['sms_time'], $expect );
					}
				} elseif ( strpos( $meta['sms_time'], 'invalide' ) === 0 ) {
					$flags['tc_date'] = 'Date/heure du SMS ' . $meta['sms_time'] . '.';
				}
			}

			$tol = (int) self::opt( self::OPT_TIME_TOL );
			if ( $check_time && $tol > 0 && $meta['sms_ts'] && $receipt_ts ) {
				$diff = abs( $receipt_ts - $meta['sms_ts'] );
				if ( $diff > $tol * MINUTE_IN_SECONDS ) {
					$flags['time'] = sprintf(
						'Heure du SMS %s vs réception %s — écart %d min (tolérance %d).',
						$meta['sms_time'], wp_date( 'H:i d/m/Y', $receipt_ts ), (int) round( $diff / 60 ), $tol );
				}
			}
		}
		return $flags;
	}

	/**
	 * Full authenticity verdict for a live SMS.
	 *
	 * @param string $provider 'natcash' | 'moncash'
	 * @param array  $parsed   ['amount'=>float,'trans_code'=>string]
	 * @param array  $ctx      ['received_at'=>int|null,'smsc'=>string,'path'=>'forward'|'app']
	 * @return array{trusted:bool,flags:array,spoof:bool,balance:?float,sms_time:string,payer:string,smsc:string}
	 */
	public static function evaluate( string $provider, array $parsed, string $raw, string $from, array $ctx = [] ): array {
		$provider   = $provider === 'moncash' ? 'moncash' : 'natcash';
		$amount     = (float) ( $parsed['amount'] ?? 0 );
		$receipt_ts = ! empty( $ctx['received_at'] ) ? (int) $ctx['received_at'] : time();
		$smsc       = self::norm_smsc( (string) ( $ctx['smsc'] ?? '' ) );
		$meta       = self::parse_meta( $provider, $raw );

		// The app-scan path re-processes older messages by hand: skip the clock check there.
		$flags = self::stateless_flags( $provider, $parsed, $raw, $receipt_ts, ( $ctx['path'] ?? 'forward' ) !== 'app' );

		// ── Balance chain ─────────────────────────────────────────────────
		$need_bal = ( $provider === 'natcash' ) || self::opt( self::OPT_MC_BAL ) === '1';
		if ( $meta['balance'] === null ) {
			if ( $need_bal ) {
				$flags['balance_missing'] = 'Aucun solde ("Balans ou" / "Votre solde") dans le SMS — un vrai reçu en contient toujours un.';
			}
		} else {
			$anchor = self::anchor( $provider );
			if ( ! $anchor ) {
				$flags['anchor_missing'] = 'Solde de référence non défini — entrez votre solde ' . ucfirst( $provider ) . ' actuel (page Natcash SMS → Garde).';
			} else {
				$expected = (float) $anchor['balance'] + $amount;
				$tol      = max( (float) self::opt( self::OPT_BAL_ABS ), $amount * (float) self::opt( self::OPT_BAL_PCT ) / 100 );
				if ( abs( $meta['balance'] - $expected ) > $tol ) {
					$flags['balance_chain'] = sprintf(
						'Solde du SMS %s HTG ≠ dernier solde vérifié %s + %s = %s HTG (faux SMS, ou mouvement sortant non vu par le forwarder → re-synchronisez le solde).',
						number_format( $meta['balance'], 2 ), number_format( (float) $anchor['balance'], 2 ),
						number_format( $amount, 2 ), number_format( $expected, 2 ) );
				}
			}
		}

		// ── SMSC route (only once the admin pinned it) ────────────────────
		$allowed = array_filter( array_map( [ __CLASS__, 'norm_smsc' ], explode( ',', (string) self::opt( self::OPT_SMSC ) ) ) );
		if ( $allowed ) {
			if ( $smsc === '' ) {
				$flags['smsc_missing'] = 'Le forwarder n\'a pas transmis le centre SMS (smsc) alors qu\'une liste autorisée est définie.';
			} elseif ( ! in_array( $smsc, $allowed, true ) ) {
				$flags['smsc'] = 'Centre SMS ' . $smsc . ' hors liste autorisée (' . implode( ', ', $allowed ) . ').';
			}
		}
		self::remember_smsc( $smsc, empty( array_intersect_key( $flags, array_flip( self::SPOOF_FLAGS ) ) ) );

		$spoof = (bool) array_intersect_key( $flags, array_flip( self::SPOOF_FLAGS ) );
		return [
			'trusted'  => empty( $flags ),
			'flags'    => $flags,
			'spoof'    => $spoof,
			'balance'  => $meta['balance'],
			'sms_time' => $meta['sms_time'],
			'payer'    => $meta['payer'],
			'smsc'     => $smsc,
		];
	}

	/**
	 * Is a stored log entry usable as PROOF of payment (instant verify, app)?
	 *  - entries written by this version carry 'trust'
	 *  - older entries are re-checked with the stateless rules (no anchor)
	 */
	public static function entry_trusted( array $e ): bool {
		if ( isset( $e['trust'] ) ) return $e['trust'] === 'ok';
		$raw = (string) ( $e['sms'] ?? '' );
		if ( $raw === '' ) return false;
		$is_mc    = ( stripos( $raw, 'moncash' ) !== false ) || preg_match( '/Txn\s*ID/i', $raw );
		$provider = $is_mc ? 'moncash' : 'natcash';
		$parsed   = $is_mc ? Delicat_Natcash_Auto::parse_moncash( $raw ) : Delicat_Natcash_Auto::parse_natcash_code( $raw );
		if ( ! $parsed ) return false;
		$ts = null;
		if ( ! empty( $e['time'] ) ) {
			try { $ts = ( new DateTime( (string) $e['time'], wp_timezone() ) )->getTimestamp(); } catch ( Exception $ex ) { $ts = null; }
		}
		return empty( self::stateless_flags( $provider, $parsed, $raw, $ts ) );
	}

	/* ---- policy (damage control) --------------------------------------- */

	/** Non-authenticity reasons to HOLD an otherwise genuine credit. */
	public static function policy_flags( string $provider, float $amount ): array {
		$flags = [];
		$max = (float) self::opt( self::OPT_AUTO_MAX );
		if ( $max > 0 && $amount > $max ) {
			$flags['amount_cap'] = sprintf( '%s HTG dépasse le plafond de crédit automatique (%s HTG) — approbation manuelle.', number_format( $amount, 2 ), number_format( $max, 0 ) );
		}
		$hmax = (float) self::opt( self::OPT_HOUR_MAX );
		if ( $hmax > 0 ) {
			$h = self::hour_window();
			if ( $h['total'] + $amount > $hmax ) {
				$flags['hourly_cap'] = sprintf( 'Plafond horaire atteint (%s + %s > %s HTG) — approbation manuelle.', number_format( $h['total'], 0 ), number_format( $amount, 0 ), number_format( $hmax, 0 ) );
			}
		}
		if ( self::lockdown_active() ) {
			$flags['lockdown'] = 'Verrouillage actif après un SMS suspect (jusqu\'à ' . wp_date( 'H:i', (int) get_option( self::OPT_LOCK_UNTIL, 0 ) ) . ') — approbation manuelle.';
		}
		return $flags;
	}

	private static function hour_window(): array {
		$h = get_option( self::OPT_HOUR, [] );
		if ( ! is_array( $h ) || empty( $h['start'] ) || ( time() - (int) $h['start'] ) > HOUR_IN_SECONDS ) {
			$h = [ 'start' => time(), 'total' => 0.0 ];
		}
		return $h;
	}

	/** Record an UNATTENDED credit so the hourly cap can be enforced. */
	public static function after_credit( string $provider, float $amount ): void {
		$h = self::hour_window();
		$h['total'] = (float) $h['total'] + $amount;
		update_option( self::OPT_HOUR, $h, false );
	}

	/* ---- anchor (balance chain) ---------------------------------------- */

	public static function anchor( string $provider ): ?array {
		$a = get_option( self::ANCHOR_PREFIX . $provider, null );
		return ( is_array( $a ) && isset( $a['balance'] ) ) ? $a : null;
	}

	public static function set_anchor( string $provider, float $balance, string $src, string $ref = '' ): void {
		update_option( self::ANCHOR_PREFIX . $provider, [
			'balance' => round( $balance, 2 ),
			'at'      => current_time( 'mysql' ),
			'src'     => $src,
			'ref'     => $ref,
		], false );
	}

	/** A receipt passed every authenticity check: its balance becomes the new reference. */
	public static function accept( string $provider, array $verdict, string $trans_code = '' ): void {
		if ( ! empty( $verdict['trusted'] ) && $verdict['balance'] !== null ) {
			self::set_anchor( $provider, (float) $verdict['balance'], 'sms', $trans_code );
		}
	}

	/**
	 * Non-receipt Natcash SMS (transfer OUT, withdrawal, payment…) also carry
	 * the new balance. Use it to keep the anchor fresh — but ONLY when it
	 * chains: new balance ≈ anchor − amount − fee. A spoofer who does not know
	 * the balance cannot satisfy this either, so the anchor cannot be poisoned.
	 * Returns true when the anchor was advanced.
	 */
	public static function chain_outgoing( string $provider, string $raw, array $ctx = [] ): bool {
		$anchor = self::anchor( $provider );
		$meta   = self::parse_meta( $provider, $raw );
		if ( ! $anchor || $meta['balance'] === null ) return false;
		if ( ! preg_match( '/(\d[\d,]*\.?\d*)\s*HTG/i', $raw, $m ) ) return false;
		$amount = self::num( $m[1] );
		if ( $amount <= 0 ) return false;
		$tc = preg_match( '/Trans\s*code\s*:\s*([A-Za-z0-9]+)/iu', $raw, $t ) ? $t[1] : '';
		// Same format / date / time proofs as a receipt.
		$receipt_ts = ! empty( $ctx['received_at'] ) ? (int) $ctx['received_at'] : time();
		if ( self::stateless_flags( $provider, [ 'amount' => $amount, 'trans_code' => $tc ], $raw, $receipt_ts, ( $ctx['path'] ?? 'forward' ) !== 'app' ) ) return false;
		$expected = (float) $anchor['balance'] - $amount;           // no fee
		$fee_max  = max( 100.0, $amount * 0.05 );                   // fee window
		$tol      = (float) self::opt( self::OPT_BAL_ABS );
		if ( $meta['balance'] > $expected + $tol || $meta['balance'] < $expected - $fee_max - $tol ) return false;
		self::set_anchor( $provider, (float) $meta['balance'], 'sms-sortant', $tc );
		return true;
	}

	/**
	 * After the anchor moved (genuine receipt, chained outgoing SMS, manual
	 * re-sync), re-check recent HELD receipts whose only problem was the
	 * balance chain / missing anchor. Out-of-order delivery and the first-run
	 * backlog resolve themselves; nothing else can, because any other flag
	 * (format, date, time, SMSC) is never retried.
	 */
	public static function retry_held( string $provider ): void {
		$inst = class_exists( 'Delicat_Natcash_Auto' ) ? Delicat_Natcash_Auto::instance() : null;
		if ( ! $inst ) return;
		for ( $round = 0; $round < 10; $round++ ) {
			$anchor  = self::anchor( $provider );
			if ( ! $anchor ) return;
			$entries = get_option( 'delicat_natcash_log', [] );
			if ( ! is_array( $entries ) ) return;
			$hit = null;
			foreach ( $entries as $i => $e ) {
				if ( ! in_array( $e['trust'] ?? '', [ 'held', 'suspect' ], true ) || ! empty( $e['resolved'] ) ) continue;
				if ( strtolower( (string) ( $e['provider'] ?? '' ) ) !== $provider ) continue;
				$flags = is_array( $e['flags'] ?? null ) ? array_keys( $e['flags'] ) : [];
				if ( array_diff( $flags, [ 'balance_chain', 'anchor_missing', 'lockdown', 'hourly_cap' ] ) ) continue; // only chain-type holds
				if ( ! in_array( 'balance_chain', $flags, true ) && ! in_array( 'anchor_missing', $flags, true ) ) continue;
				try { $age = time() - ( new DateTime( (string) ( $e['time'] ?? '' ), wp_timezone() ) )->getTimestamp(); } catch ( Exception $ex ) { continue; }
				if ( $age > 30 * MINUTE_IN_SECONDS ) continue; // narrow window: an old spoof must never wake up
				$raw    = (string) ( $e['sms'] ?? '' );
				$parsed = $provider === 'moncash' ? Delicat_Natcash_Auto::parse_moncash( $raw ) : Delicat_Natcash_Auto::parse_natcash_code( $raw );
				$meta   = self::parse_meta( $provider, $raw );
				if ( ! $parsed || $meta['balance'] === null ) continue;
				$expected = (float) $anchor['balance'] + (float) $parsed['amount'];
				$tol      = max( (float) self::opt( self::OPT_BAL_ABS ), (float) $parsed['amount'] * (float) self::opt( self::OPT_BAL_PCT ) / 100 );
				if ( abs( $meta['balance'] - $expected ) > $tol ) continue;
				$hit = [ $i, $e, $parsed, $meta, $raw ];
				break;
			}
			if ( ! $hit ) return;
			list( $i, $e, $parsed, $meta, $raw ) = $hit;
			$entries[ $i ]['trust']        = 'ok';
			$entries[ $i ]['flags_prev']   = $e['flags'] ?? [];
			$entries[ $i ]['flags']        = [];
			$entries[ $i ]['confirmed_by'] = 'chaîne de solde ' . current_time( 'mysql' );
			update_option( 'delicat_natcash_log', $entries, false );
			self::set_anchor( $provider, (float) $meta['balance'], 'sms', (string) $parsed['trans_code'] );
			$inst->credit_trusted_entry( $provider, $raw, false );
		}
	}

	private static function remember_smsc( string $smsc, bool $ok ): void {
		if ( $smsc === '' ) return;
		$seen = get_option( self::OPT_SMSC_SEEN, [] );
		if ( ! is_array( $seen ) ) $seen = [];
		$row = $seen[ $smsc ] ?? [ 'n' => 0, 'ok' => 0, 'last' => '' ];
		$row['n']++; if ( $ok ) $row['ok']++;
		$row['last'] = current_time( 'mysql' );
		$seen[ $smsc ] = $row;
		if ( count( $seen ) > 10 ) $seen = array_slice( $seen, -10, null, true );
		update_option( self::OPT_SMSC_SEEN, $seen, false );
	}

	/* ---- lockdown + alerts --------------------------------------------- */

	public static function lockdown_active(): bool {
		return (int) get_option( self::OPT_LOCK_UNTIL, 0 ) > time();
	}

	public static function unlock(): void {
		update_option( self::OPT_LOCK_UNTIL, 0, false );
	}

	/**
	 * Called when an SMS was HELD. Spoof evidence → lockdown + urgent alert;
	 * policy hold → normal alert. Alerts are rate-limited and deferred so the
	 * forwarder's HTTP call is never slowed down.
	 */
	public static function on_hold( string $provider, array $verdict, array $parsed, array $flags ): void {
		$spoof = (bool) array_intersect_key( $flags, array_flip( self::SPOOF_FLAGS ) );
		$mins  = (int) self::opt( self::OPT_LOCK_MIN );
		$lock  = false;
		if ( $spoof && $mins > 0 && self::mode() === 'hold' ) {
			// A broken balance chain ALONE can also be an outgoing movement the
			// forwarder did not see, so it locks down only when it repeats
			// (probing pattern: 2+ within 30 min). Any other proof failure
			// (format, date, time, SMSC) is unambiguous → immediate lockdown.
			$only_chain = ( count( array_intersect_key( $flags, array_flip( self::SPOOF_FLAGS ) ) ) === 1 ) && isset( $flags['balance_chain'] );
			if ( $only_chain ) {
				$k = 'delicat_smsg_bc_' . $provider;
				$cnt = (int) get_transient( $k ) + 1;
				set_transient( $k, $cnt, 30 * MINUTE_IN_SECONDS );
				$lock = $cnt >= 2;
			} else {
				$lock = true;
			}
			if ( $lock ) update_option( self::OPT_LOCK_UNTIL, time() + $mins * MINUTE_IN_SECONDS, false );
		}
		$title = $spoof
			? sprintf( '🚨 SMS %s SUSPECT — %s HTG non crédités', ucfirst( $provider ), number_format( (float) ( $parsed['amount'] ?? 0 ), 0 ) )
			: sprintf( '⏸ SMS %s retenu — %s HTG à approuver', ucfirst( $provider ), number_format( (float) ( $parsed['amount'] ?? 0 ), 0 ) );
		$body  = 'TransCode ' . ( $parsed['trans_code'] ?? '?' ) . "\n" . implode( "\n", array_map( function ( $k, $v ) { return '• ' . $v; }, array_keys( $flags ), $flags ) );
		if ( $lock ) {
			$body .= "\n\n🔒 Validation automatique suspendue " . $mins . ' min. Approuvez manuellement depuis l\'app ou la page Natcash SMS.';
		}
		self::notify( $title, $body, $spoof );
	}

	private static function notify( string $title, string $body, bool $urgent ): void {
		$key = 'delicat_smsg_ntf_' . md5( $title );
		if ( isset( self::$notified[ $key ] ) || get_transient( $key ) ) return;
		set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );
		self::$notified[ $key ] = true;
		$url = admin_url( 'admin.php?page=delicat-natcash-log' );
		add_action( 'shutdown', function () use ( $title, $body, $url, $urgent ) {
			if ( class_exists( 'Delicat_Push' ) ) Delicat_Push::send( $title, $body, $url, $urgent );
			$to = get_option( 'delicat_admin_email', '' ) ?: get_option( 'admin_email' );
			wp_mail( $to, $title, $body . "\n\n" . $url );
		}, 20 );
	}

	/* ---- labels ---------------------------------------------------------- */

	public static function flag_labels(): array {
		return [
			'tc_format'       => 'Format TransCode',
			'tc_date'         => 'Date TransCode ≠ date SMS',
			'time'            => 'Heure du SMS incohérente',
			'balance_chain'   => 'Solde incohérent',
			'balance_missing' => 'Solde absent',
			'smsc'            => 'Centre SMS étranger',
			'smsc_missing'    => 'Centre SMS non transmis',
			'anchor_missing'  => 'Solde de référence manquant',
			'amount_cap'      => 'Plafond par SMS',
			'hourly_cap'      => 'Plafond horaire',
			'lockdown'        => 'Verrouillage actif',
		];
	}

	/* ---- REST: let the admin app re-sync the reference balance ---------- */

	public static function routes(): void {
		register_rest_route( 'delicat/v1', '/sms/balance', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_set_balance' ],
			'permission_callback' => function ( WP_REST_Request $req ) {
				if ( ! class_exists( 'Delicat_Natcash_Auto' ) ) return false;
				$inst = Delicat_Natcash_Auto::instance();
				return $inst ? $inst->auth( $req ) : false;
			},
		] );
	}

	public static function rest_set_balance( WP_REST_Request $req ) {
		$provider = strtolower( (string) $req->get_param( 'provider' ) ) === 'moncash' ? 'moncash' : 'natcash';
		$balance  = (float) str_replace( ',', '', (string) $req->get_param( 'balance' ) );
		if ( $balance < 0 ) return new WP_Error( 'bad', 'Solde invalide.', [ 'status' => 400 ] );
		self::set_anchor( $provider, $balance, 'app' );
		self::retry_held( $provider );
		return rest_ensure_response( [ 'ok' => true, 'provider' => $provider, 'balance' => round( $balance, 2 ) ] );
	}

	/* ---- admin: settings ------------------------------------------------- */

	public static function register_settings(): void {
		register_setting( 'delicat_settings_group', self::OPT_MODE, [ 'sanitize_callback' => function ( $v ) { return $v === 'log' ? 'log' : 'hold'; } ] );
		foreach ( [ self::OPT_TIME_TOL, self::OPT_LOCK_MIN ] as $k ) {
			register_setting( 'delicat_settings_group', $k, [ 'sanitize_callback' => 'absint' ] );
		}
		foreach ( [ self::OPT_BAL_ABS, self::OPT_BAL_PCT, self::OPT_AUTO_MAX, self::OPT_HOUR_MAX ] as $k ) {
			register_setting( 'delicat_settings_group', $k, [ 'sanitize_callback' => function ( $v ) { return max( 0, (float) str_replace( ',', '', (string) $v ) ); } ] );
		}
		foreach ( [ self::OPT_TC_FORMAT, self::OPT_MC_BAL ] as $k ) {
			register_setting( 'delicat_settings_group', $k, [ 'sanitize_callback' => function ( $v ) { return $v ? '1' : '0'; } ] );
		}
		register_setting( 'delicat_settings_group', self::OPT_SMSC, [ 'sanitize_callback' => function ( $v ) {
			$parts = array_filter( array_map( function ( $p ) { return preg_replace( '/[^\d+]/', '', $p ); }, explode( ',', (string) $v ) ) );
			return implode( ',', $parts );
		} ] );
	}

	public static function settings_ui(): void {
		$o = function ( $k ) { return esc_attr( (string) self::opt( $k ) ); };
		?>
		<h2>🛡️ Garde SMS (anti-spoofing)</h2>
		<p class="description">Protège la validation automatique contre les faux SMS « Natcash » / « MonCash » envoyés par des escrocs (Twilio, etc.). Tout SMS qui échoue est <strong>retenu</strong> (jamais crédité) et visible sur la page <a href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-natcash-log' ) ); ?>">Natcash SMS</a> avec un bouton pour le valider si c'est un vrai.</p>
		<table class="form-table">
			<tr><th>Mode</th>
				<td>
					<label><input type="radio" name="<?php echo self::OPT_MODE; ?>" value="hold" <?php checked( self::mode(), 'hold' ); ?>> <strong>Retenir</strong> les SMS suspects (recommandé)</label>&nbsp;&nbsp;
					<label><input type="radio" name="<?php echo self::OPT_MODE; ?>" value="log" <?php checked( self::mode(), 'log' ); ?>> Journal seulement (crédite quand même — test)</label>
				</td></tr>
			<tr><th>Format TransCode Natcash</th>
				<td><input type="hidden" name="<?php echo self::OPT_TC_FORMAT; ?>" value="0"><label><input type="checkbox" name="<?php echo self::OPT_TC_FORMAT; ?>" value="1" <?php checked( self::opt( self::OPT_TC_FORMAT ), '1' ); ?>> 14 chiffres et commence par la date (AAMMJJ) écrite dans le SMS</label>
					<p class="description">Vérifié sur tous les vrais reçus Natcash observés (ex. <code>260626…</code> pour le 26/06/2026). Les faux vus commencent par <code>260873</code> — le jour 73 n'existe pas.</p></td></tr>
			<tr><th>Tolérance d'heure (min)</th>
				<td><input type="number" min="0" name="<?php echo self::OPT_TIME_TOL; ?>" value="<?php echo $o( self::OPT_TIME_TOL ); ?>" class="small-text">
					<p class="description">Écart maximal entre l'heure écrite dans le SMS et la réception par le forwarder. 0 = désactivé. Le fuseau WordPress doit être <code>America/Port-au-Prince</code>.</p></td></tr>
			<tr><th>Tolérance de solde</th>
				<td><input type="number" step="0.01" min="0" name="<?php echo self::OPT_BAL_ABS; ?>" value="<?php echo $o( self::OPT_BAL_ABS ); ?>" class="small-text"> HTG &nbsp;ou&nbsp;
					<input type="number" step="0.1" min="0" name="<?php echo self::OPT_BAL_PCT; ?>" value="<?php echo $o( self::OPT_BAL_PCT ); ?>" class="small-text"> % du montant (le plus grand des deux)
					<p class="description">Le solde du SMS doit valoir <em>dernier solde vérifié + montant</em>. Marge pour d'éventuels frais/arrondis.</p></td></tr>
			<tr><th>MonCash : exiger le solde</th>
				<td><input type="hidden" name="<?php echo self::OPT_MC_BAL; ?>" value="0"><label><input type="checkbox" name="<?php echo self::OPT_MC_BAL; ?>" value="1" <?php checked( self::opt( self::OPT_MC_BAL ), '1' ); ?>> Retenir un SMS MonCash sans « new balance is »</label></td></tr>
			<tr><th>Plafond crédit auto / SMS</th>
				<td><input type="number" min="0" name="<?php echo self::OPT_AUTO_MAX; ?>" value="<?php echo $o( self::OPT_AUTO_MAX ); ?>" class="regular-text" style="max-width:160px;"> HTG
					<p class="description">Au-dessus : approbation manuelle. 0 = illimité.</p></td></tr>
			<tr><th>Plafond crédit auto / heure</th>
				<td><input type="number" min="0" name="<?php echo self::OPT_HOUR_MAX; ?>" value="<?php echo $o( self::OPT_HOUR_MAX ); ?>" class="regular-text" style="max-width:160px;"> HTG
					<p class="description">Total maximal crédité automatiquement sur 60 min glissantes. 0 = illimité.</p></td></tr>
			<tr><th>Verrouillage après SMS suspect</th>
				<td><input type="number" min="0" name="<?php echo self::OPT_LOCK_MIN; ?>" value="<?php echo $o( self::OPT_LOCK_MIN ); ?>" class="small-text"> minutes
					<p class="description">Pendant ce délai, TOUT crédit automatique passe en approbation manuelle et une alerte urgente est envoyée. 0 = désactivé.</p></td></tr>
			<tr><th>Centres SMS autorisés (smsc)</th>
				<td><input type="text" name="<?php echo self::OPT_SMSC; ?>" value="<?php echo $o( self::OPT_SMSC ); ?>" class="regular-text" placeholder="ex: 50937000000">
					<p class="description">Optionnel, très fort : numéro du centre SMS de Natcom transmis par le forwarder (champ <code>smsc</code>). Séparés par des virgules. Les valeurs vues apparaissent sur la page Natcash SMS.</p></td></tr>
		</table>
		<?php
	}

	/* ---- admin: status panel on the Natcash SMS page --------------------- */

	public static function render_status_panel(): void {
		$na   = self::anchor( 'natcash' );
		$ma   = self::anchor( 'moncash' );
		$lock = self::lockdown_active();
		$seen = get_option( self::OPT_SMSC_SEEN, [] );
		$msg  = isset( $_GET['smsg_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['smsg_msg'] ) ) : '';
		$fmt  = function ( $a ) { return $a ? number_format( (float) $a['balance'], 2 ) . ' HTG <small style="color:#7b8794;">(' . esc_html( $a['at'] ) . ' · ' . esc_html( $a['src'] ) . ')</small>' : '<span style="color:#c62828;font-weight:700;">non défini</span>'; };
		?>
		<div class="dlc-panel" style="border-left:5px solid <?php echo $lock ? '#c62828' : ( $na ? '#2e7d32' : '#ef6c00' ); ?>;">
			<div class="dlc-panel-head">
				<strong>🛡️ Garde SMS — <?php echo self::mode() === 'hold' ? 'actif (retient les SMS suspects)' : 'journal seulement'; ?></strong>
				<span class="dlc-hint"><a href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-settings' ) ); ?>">Réglages</a></span>
			</div>
			<?php if ( $msg ) : ?><div class="notice notice-info inline" style="margin:0 0 10px;"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
			<?php if ( $lock ) : ?>
				<p style="color:#c62828;font-weight:700;">🔒 Verrouillage actif jusqu'à <?php echo esc_html( wp_date( 'H:i', (int) get_option( self::OPT_LOCK_UNTIL, 0 ) ) ); ?> — tout crédit automatique est retenu.
					<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_smsg_unlock' ), 'delicat_smsg_unlock' ) ); ?>">Lever le verrouillage</a></p>
			<?php endif; ?>
			<?php if ( ! $na ) : ?>
				<p style="color:#ef6c00;font-weight:700;">⚠️ Solde de référence Natcash non défini : les crédits automatiques Natcash sont retenus jusqu'à ce que vous l'entriez ci-dessous (voir votre solde dans l'app Natcash ou *144#).</p>
			<?php endif; ?>
			<table class="dlc-table">
				<tr><th>Dernier solde Natcash vérifié</th><td><?php echo $fmt( $na ); ?></td></tr>
				<tr><th>Dernier solde MonCash vérifié</th><td><?php echo $fmt( $ma ); ?></td></tr>
				<?php if ( is_array( $seen ) && $seen ) : ?>
				<tr><th>Centres SMS vus (smsc)</th><td>
					<?php foreach ( $seen as $s => $r ) : ?>
						<code><?php echo esc_html( $s ); ?></code> — <?php echo (int) $r['n']; ?> SMS, <?php echo (int) $r['ok']; ?> authentiques, dernier <?php echo esc_html( $r['last'] ); ?><br>
					<?php endforeach; ?>
				</td></tr>
				<?php endif; ?>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
				<?php wp_nonce_field( 'delicat_smsg_anchor' ); ?>
				<input type="hidden" name="action" value="delicat_smsg_anchor">
				<select name="provider"><option value="natcash">Natcash</option><option value="moncash">MonCash</option></select>
				<input type="text" name="balance" placeholder="Solde actuel, ex: 80707.63" class="regular-text" style="max-width:200px;" required>
				<button class="button button-primary">Définir le solde de référence</button>
				<span class="dlc-hint">À refaire après chaque retrait/transfert sortant non vu par le forwarder.</span>
			</form>
		</div>
		<?php
	}

	/** Trust badge + reasons + one-tap validation for a log entry. */
	public static function render_entry_trust( array $e ): void {
		$trust = $e['trust'] ?? 'legacy';
		$flags = is_array( $e['flags'] ?? null ) ? $e['flags'] : [];
		$lbl   = self::flag_labels();
		if ( $trust === 'ok' ) {
			echo '<div class="dlc-details" style="color:#2e7d32;font-weight:700;">🛡️ Authentique';
			if ( ! empty( $e['confirmed_by'] ) ) echo ' (confirmé par ' . esc_html( (string) $e['confirmed_by'] ) . ')';
			echo '</div>';
			return;
		}
		if ( $flags ) {
			echo '<div class="dlc-details" style="color:#b71c1c;">';
			echo '<b>' . ( $trust === 'suspect' ? '🚨 SUSPECT' : '⏸ RETENU' ) . '</b>';
			foreach ( $flags as $k => $txt ) {
				echo '<br>• <b>' . esc_html( $lbl[ $k ] ?? $k ) . '</b> — ' . esc_html( (string) $txt );
			}
			echo '</div>';
		} elseif ( $trust === 'legacy' ) {
			echo '<div class="dlc-details" style="color:#7b8794;">Reçu avant la Garde SMS — non utilisable comme preuve tant qu\'il n\'est pas confirmé.</div>';
		}
		if ( empty( $e['uid'] ) || ! empty( $e['resolved'] ) ) return;
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=delicat_smsg_trust&uid=' . rawurlencode( (string) $e['uid'] ) ), 'delicat_smsg_trust_' . $e['uid'] );
		echo '<p style="margin:6px 0 0;"><a class="button button-small button-primary" href="' . esc_url( $url ) . '" onclick="return confirm(\'Confirmez UNIQUEMENT si ce SMS existe vraiment dans votre app Natcash/MonCash (montant + TransCode + solde). Le solde du SMS deviendra la nouvelle référence.\');">✅ Authentique → créditer &amp; re-sync solde</a></p>';
	}

	/* ---- admin-post handlers ----------------------------------------------- */

	public static function handle_trust(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
		$uid = isset( $_GET['uid'] ) ? sanitize_text_field( wp_unslash( $_GET['uid'] ) ) : '';
		check_admin_referer( 'delicat_smsg_trust_' . $uid );

		$entries = get_option( 'delicat_natcash_log', [] );
		if ( ! is_array( $entries ) ) $entries = [];
		$msg = 'Entrée introuvable.';
		foreach ( $entries as $i => $e ) {
			if ( ( $e['uid'] ?? '' ) !== $uid ) continue;
			$raw      = (string) ( $e['sms'] ?? '' );
			$is_mc    = ( stripos( (string) ( $e['provider'] ?? '' ), 'moncash' ) !== false ) || stripos( $raw, 'moncash' ) !== false || preg_match( '/Txn\s*ID/i', $raw );
			$provider = $is_mc ? 'moncash' : 'natcash';
			$meta     = self::parse_meta( $provider, $raw );
			$parsed   = $is_mc ? Delicat_Natcash_Auto::parse_moncash( $raw ) : Delicat_Natcash_Auto::parse_natcash_code( $raw );

			$entries[ $i ]['trust']        = 'ok';
			$entries[ $i ]['flags_prev']   = $e['flags'] ?? [];
			$entries[ $i ]['flags']        = [];
			$entries[ $i ]['confirmed_by'] = wp_get_current_user()->user_login . ' ' . current_time( 'mysql' );
			update_option( 'delicat_natcash_log', $entries, false );

			if ( $meta['balance'] !== null ) {
				self::set_anchor( $provider, (float) $meta['balance'], 'admin', (string) ( $parsed['trans_code'] ?? '' ) );
			}
			$msg = 'SMS marqué authentique.';
			if ( $meta['balance'] !== null ) $msg .= ' Solde de référence ' . ucfirst( $provider ) . ' = ' . number_format( (float) $meta['balance'], 2 ) . ' HTG.';
			$inst = Delicat_Natcash_Auto::instance();
			if ( $inst && $parsed ) {
				$msg .= ' ' . $inst->credit_trusted_entry( $provider, $raw );
			}
			break;
		}
		wp_safe_redirect( add_query_arg( 'smsg_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=delicat-natcash-log' ) ) );
		exit;
	}

	public static function handle_unlock(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'delicat_smsg_unlock' );
		self::unlock();
		wp_safe_redirect( add_query_arg( 'smsg_msg', rawurlencode( 'Verrouillage levé.' ), admin_url( 'admin.php?page=delicat-natcash-log' ) ) );
		exit;
	}

	public static function handle_anchor(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'delicat_smsg_anchor' );
		$provider = ( isset( $_POST['provider'] ) && $_POST['provider'] === 'moncash' ) ? 'moncash' : 'natcash';
		$balance  = (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['balance'] ?? '' ) ) );
		if ( $balance < 0 ) $balance = 0;
		self::set_anchor( $provider, $balance, 'manuel' );
		self::retry_held( $provider );
		wp_safe_redirect( add_query_arg( 'smsg_msg', rawurlencode( 'Solde de référence ' . ucfirst( $provider ) . ' = ' . number_format( $balance, 2 ) . ' HTG.' ), admin_url( 'admin.php?page=delicat-natcash-log' ) ) );
		exit;
	}
}
