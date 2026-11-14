<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 6.6.5 — storage of received payment SMS (MonCash / Natcash), owner rule of
 * 2026-11-07: « keep all SMS on the system for at least 24 h, after that it
 * expires and disappears ».
 *
 * Until 6.6.4 every SMS lived in one option ('delicat_natcash_log', the last 40,
 * raw text in clear). Now:
 *  - one row per SMS in {prefix}delicat_sms, kept for the retention window
 *    (setting, 24 h minimum and default, 7 days maximum, clamped in code), then
 *    DELETED for good by an hourly WP-Cron event and by a cheap purge on intake;
 *    an expired row is never read again, even before the purge reaches it;
 *  - the raw body is encrypted at rest (sodium crypto_secretbox, key from
 *    DELICAT_RECHARGE_SMS_KEY in wp-config.php, else derived from the WordPress
 *    salts with hash_hkdf); values written in clear before 6.6.5 still read;
 *  - sender / payer phone numbers are stored masked, with a keyed hash (HMAC)
 *    for comparisons; the raw text is never shown, logged or sent: admin screens
 *    get mask_text();
 *  - the same SMS (HMAC of sender + body + time) is stored and processed once;
 *  - an SMS that credits a top-up leaves its audit on the top-up row (TransCode,
 *    amount, masked sender, time): see audit_line(). The SMS row itself expires
 *    like the others.
 *
 * A DB table, never the object cache, for anything that must hold (LiteSpeed
 * drops TTLs).
 */
class Delicat_SMS_Store {

	const TABLE         = 'delicat_sms';
	const OPT_HOURS     = 'delicat_sms_retention_hours';
	const MIN_HOURS     = 24;   // owner rule: never shorter
	const MAX_HOURS     = 168;  // 7 days
	const DEFAULT_HOURS = 24;
	const CRON          = 'delicat_recharge_sms_purge';
	const BATCH         = 500;  // rows per DELETE
	const OPT_LAST_PURGE = 'delicat_sms_last_purge';
	const INTAKE_PURGE_EVERY = 600; // seconds between purges triggered by intake
	const BODY_MAX      = 480;
	const PLACEHOLDER   = 'Reçu — en cours de traitement';
	const LEGACY_OPTION = 'delicat_natcash_log';

	/** uid of the row the current processing writes into (intake, admin confirm, retry). */
	private static $current_uid = null;

	public static function init(): void {
		add_action( self::CRON, [ __CLASS__, 'cron_purge' ] );
		add_action( 'init', [ __CLASS__, 'ensure_scheduled' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'delicat_settings_extra', [ __CLASS__, 'settings_ui' ] );
	}

	/* ---- retention -------------------------------------------------------- */

	/** Retention in hours, always clamped to 24..168 whatever is stored. */
	public static function hours(): int {
		$v = get_option( self::OPT_HOURS, null );
		$h = ( null === $v || '' === $v || ! is_numeric( $v ) ) ? self::DEFAULT_HOURS : (int) $v;
		return self::clamp_hours( $h );
	}

	public static function clamp_hours( int $h ): int {
		return max( self::MIN_HOURS, min( self::MAX_HOURS, $h ) );
	}

	public static function window_seconds(): int {
		return self::hours() * HOUR_IN_SECONDS;
	}

	/** UTC datetime before which a row has expired. */
	private static function cutoff_gmt( ?int $now = null ): string {
		return gmdate( 'Y-m-d H:i:s', ( null === $now ? time() : $now ) - self::window_seconds() );
	}

	public static function register_settings(): void {
		register_setting( 'delicat_settings_group', self::OPT_HOURS, [
			'sanitize_callback' => function ( $v ) {
				$h = is_numeric( $v ) ? (int) $v : self::DEFAULT_HOURS;
				return (string) self::clamp_hours( $h );
			},
		] );
	}

	public static function settings_ui(): void {
		?>
		<h2>Conservation des SMS de paiement</h2>
		<table class="form-table">
			<tr><th>Garder chaque SMS reçu</th>
				<td><input type="number" min="<?php echo (int) self::MIN_HOURS; ?>" max="<?php echo (int) self::MAX_HOURS; ?>" step="1" name="<?php echo esc_attr( self::OPT_HOURS ); ?>" value="<?php echo esc_attr( (string) self::hours() ); ?>" class="small-text"> heures
					<p class="description">Au moins 24 h (minimum imposé), au plus 7 jours (168 h). Pendant ce délai, un SMS peut encore valider la recharge d'un client ; ensuite il est effacé pour de bon (vérification toutes les heures). Une recharge validée garde son TransCode, son montant, l'heure du SMS et l'expéditeur masqué. Le texte des SMS est chiffré.</p></td></tr>
		</table>
		<?php
	}

	/* ---- table ----------------------------------------------------------- */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function create_table(): void {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uid varchar(16) NOT NULL,
			replay_key char(64) NOT NULL,
			received_at datetime NOT NULL,
			provider varchar(16) NOT NULL DEFAULT '',
			path varchar(16) NOT NULL DEFAULT '',
			trust varchar(16) NOT NULL DEFAULT '',
			result varchar(255) NOT NULL DEFAULT '',
			code_norm varchar(64) NOT NULL DEFAULT '',
			amount decimal(14,2) NOT NULL DEFAULT 0.00,
			sender_masked varchar(40) NOT NULL DEFAULT '',
			sender_hash char(64) NOT NULL DEFAULT '',
			body_enc text,
			meta longtext,
			recharge_id bigint(20) unsigned NOT NULL DEFAULT 0,
			resolved tinyint(1) NOT NULL DEFAULT 0,
			cleared tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY replay_key (replay_key),
			UNIQUE KEY uid (uid),
			KEY received_at (received_at),
			KEY code_norm (code_norm)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ---- keys and encryption ---------------------------------------------- */

	/** 32-byte key: 'c' = wp-config DELICAT_RECHARGE_SMS_KEY, 's' = derived from the salts. */
	private static function key( string $kid ): string {
		if ( 'c' === $kid ) {
			if ( ! defined( 'DELICAT_RECHARGE_SMS_KEY' ) || ! is_string( DELICAT_RECHARGE_SMS_KEY ) || '' === trim( DELICAT_RECHARGE_SMS_KEY ) ) return '';
			$k   = trim( DELICAT_RECHARGE_SMS_KEY );
			$raw = base64_decode( $k, true );
			if ( is_string( $raw ) && 32 === strlen( $raw ) ) return $raw;
			if ( preg_match( '/^[0-9a-f]{64}$/i', $k ) ) return (string) hex2bin( $k );
			return hash_hkdf( 'sha256', $k, 32, 'delicat-recharge-sms|v1' );
		}
		return hash_hkdf( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), 32, 'delicat-recharge-sms|v1' );
	}

	/** The key id new values are sealed with. */
	private static function current_kid(): string {
		return '' !== self::key( 'c' ) ? 'c' : 's';
	}

	/** Key for the keyed hashes (replay key, phone hashes): never the encryption key itself. */
	private static function hash_key(): string {
		$base = '' !== self::key( 'c' ) ? self::key( 'c' ) : self::key( 's' );
		return hash_hkdf( 'sha256', $base, 32, 'delicat-recharge-sms-hash|v1' );
	}

	public static function hmac( string $value ): string {
		return hash_hmac( 'sha256', $value, self::hash_key() );
	}

	/**
	 * Encrypt a body: 'dsms1:<kid>:' + base64(nonce . secretbox). Falls back to
	 * AES-256-GCM ('dsmg1:') only if sodium is missing (WordPress ships
	 * sodium_compat, so it should not be). Returns '' rather than store clear text.
	 */
	public static function seal( string $plain ): string {
		if ( '' === $plain ) return '';
		$kid = self::current_kid();
		$key = self::key( $kid );
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 'dsms1:' . $kid . ':' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $ct ) return 'dsmg1:' . $kid . ':' . base64_encode( $iv . $tag . $ct );
		}
		return '';
	}

	/** Decrypt a stored body. A value without our prefix (written before 6.6.5) is returned as is. */
	public static function open( ?string $stored ): string {
		$stored = (string) $stored;
		if ( '' === $stored ) return '';
		if ( ! preg_match( '/^(dsms1|dsmg1):([cs]):(.+)$/s', $stored, $m ) ) return $stored; // legacy clear text
		$key = self::key( $m[2] );
		$bin = base64_decode( $m[3], true );
		if ( '' === $key || ! is_string( $bin ) ) return '';
		if ( 'dsms1' === $m[1] ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || strlen( $bin ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) return '';
			$plain = sodium_crypto_secretbox_open( substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
			return false === $plain ? '' : $plain;
		}
		if ( strlen( $bin ) <= 28 || ! function_exists( 'openssl_decrypt' ) ) return '';
		$plain = openssl_decrypt( substr( $bin, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $bin, 0, 12 ), substr( $bin, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}

	/* ---- masking ----------------------------------------------------------- */

	/** Digits of a phone-like value, '' when it has fewer than 6 digits (a sender name). */
	private static function phone_digits( string $v ): string {
		$d = preg_replace( '/\D+/', '', $v );
		return strlen( $d ) >= 6 ? $d : '';
	}

	/** "+509 3311 1283" -> "+509 ••••••83"; a sender name ("Natcash") stays as is. */
	public static function mask_phone( string $v ): string {
		$v = trim( $v );
		$d = self::phone_digits( $v );
		if ( '' === $d ) return sanitize_text_field( $v );
		$prefix = '';
		if ( strlen( $d ) > 8 && 0 === strpos( $d, '509' ) ) { $prefix = '+509 '; $d = substr( $d, 3 ); }
		$label = trim( preg_replace( '/[\d+()\s.\-]+/', ' ', $v ) );
		return trim( ( '' !== $label ? $label . ' ' : '' ) . $prefix . str_repeat( '•', max( 0, strlen( $d ) - 2 ) ) . substr( $d, -2 ) );
	}

	/** Keyed hash of a phone number (last 8 digits), for comparisons only. '' for a name. */
	public static function phone_hash( string $v ): string {
		$d = self::phone_digits( $v );
		return '' === $d ? '' : self::hmac( 'phone|' . substr( $d, -8 ) );
	}

	/**
	 * Masked copy of an SMS text for screens: phone numbers (8 digits, with or
	 * without +509) keep their last 2 digits, payer names are hidden. TransCodes
	 * (12 or 14 digits), amounts, balances and dates stay readable.
	 */
	public static function mask_text( string $text ): string {
		if ( '' === $text ) return '';
		$out = preg_replace_callback( '/(?<![\d•])(\+?509[\s\-]?)?(\d{4})[\s\-]?(\d{4})(?![\d•])/', function ( $m ) {
			return ( '' !== $m[1] ? '+509 ' : '' ) . '••••••' . substr( $m[3], -2 );
		}, $text );
		// MonCash: "from 509••••••09 - FIRST LAST." ; Natcash: "de FIRST LAST ••••••54 a 14:55"
		$out = preg_replace( '/(\bfrom\s+(?:\+509\s)?\S+\s*-\s*)[^.\n]+/iu', '$1•••', $out );
		$out = preg_replace( '/(\b(?:de|nan)\s+)(?:[^\s\d•+]+\s+){1,4}(?=(?:\+509\s)?•)/iu', '$1••• ', $out );
		return (string) $out;
	}

	/** Mask the phone-like fields of an extra array before it is stored. */
	private static function clean_extra( array $extra ): array {
		unset( $extra['sms'], $extra['pin'] ); // never stored as fields
		foreach ( [ 'payer', 'sender', 'from', 'expediteur' ] as $k ) {
			if ( ! isset( $extra[ $k ] ) || ! is_scalar( $extra[ $k ] ) ) continue;
			$raw = (string) $extra[ $k ];
			if ( in_array( $k, [ 'payer', 'sender' ], true ) && '' !== ( $h = self::phone_hash( $raw ) ) ) {
				$extra[ $k . '_hash' ] = $h;
			}
			$extra[ $k ] = self::mask_phone( $raw );
		}
		return $extra;
	}

	/* ---- provider / parse ----------------------------------------------------- */

	/** [provider, trans_code, amount] read from the body (same rules as the matchers). */
	private static function parse( string $raw, string $label = '' ): array {
		$is_mc = stripos( $raw, 'moncash' ) !== false || stripos( $label, 'moncash' ) !== false || preg_match( '/Txn\s*ID/i', $raw );
		$p = null;
		if ( class_exists( 'Delicat_Natcash_Auto' ) ) {
			$p = $is_mc ? Delicat_Natcash_Auto::parse_moncash( $raw ) : Delicat_Natcash_Auto::parse_natcash_code( $raw );
		}
		$code = ( $p && ! empty( $p['trans_code'] ) ) ? strtoupper( str_replace( [ ' ', '-', "\t" ], '', trim( (string) $p['trans_code'] ) ) ) : '';
		return [ $is_mc ? 'MonCash' : 'Natcash', substr( $code, 0, 64 ), $p ? (float) $p['amount'] : 0.0 ];
	}

	/* ---- intake ------------------------------------------------------------ */

	/**
	 * Store a newly received SMS once. Returns its uid, or '' when the same SMS
	 * (same sender + body + time) was already received: the caller answers
	 * « déjà reçu » and processes nothing. A row stuck on the placeholder for
	 * more than 2 minutes (the first attempt died) is handed back for a retry.
	 * Sets the current row, so the matcher's log lines are written into it.
	 */
	public static function claim( string $raw, string $from, $ts, array $ctx = [] ): string {
		global $wpdb;
		$raw  = self::trim_body( $raw );
		$key  = self::hmac( 'sms|' . strtolower( trim( $from ) ) . "\n" . $raw . "\n" . ( null === $ts || '' === $ts ? '' : (string) (int) $ts ) );
		$uid  = self::new_uid();
		list( $provider, $code, $amount ) = self::parse( $raw, (string) ( $ctx['label'] ?? '' ) );
		$ok = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO " . self::table() . " (uid, replay_key, received_at, provider, path, result, code_norm, amount, sender_masked, sender_hash, body_enc, meta)
			 VALUES (%s, %s, %s, %s, %s, %s, %s, %f, %s, %s, %s, %s)",
			$uid, $key, gmdate( 'Y-m-d H:i:s' ), $provider, substr( (string) ( $ctx['path'] ?? 'forward' ), 0, 16 ), self::PLACEHOLDER,
			$code, $amount, substr( self::mask_phone( $from ), 0, 40 ), self::phone_hash( $from ), self::seal( $raw ),
			wp_json_encode( self::clean_extra( [ 'smsc' => (string) ( $ctx['smsc'] ?? '' ), 'label' => (string) ( $ctx['label'] ?? '' ) ] ) )
		) );
		if ( 1 === (int) $ok ) {
			self::$current_uid = $uid;
			return $uid;
		}
		if ( false === $ok ) {
			// Database error (table missing…): never mistake it for a duplicate and
			// drop a payment SMS. Process it anyway; the matcher still works.
			self::$current_uid = null;
			return 'nostore';
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT uid, result, received_at FROM " . self::table() . " WHERE replay_key = %s", $key ) );
		if ( $row && self::PLACEHOLDER === (string) $row->result && strtotime( $row->received_at . ' UTC' ) < time() - 120 ) {
			self::$current_uid = (string) $row->uid;
			return (string) $row->uid;
		}
		return '';
	}

	/** After the matcher: a row nobody wrote a result into gets the answer's reason. */
	public static function finish( $response ): void {
		$uid = self::$current_uid;
		self::$current_uid = null;
		if ( ! $uid ) return;
		$data = ( $response instanceof WP_REST_Response ) ? (array) $response->get_data() : ( is_array( $response ) ? $response : [] );
		$row  = self::find( $uid, false );
		if ( $row && self::PLACEHOLDER === (string) ( $row['result'] ?? '' ) ) {
			$msg = (string) ( $data['message'] ?? $data['reason'] ?? 'Traité' );
			self::update( $uid, [ 'result' => $msg ] );
		}
	}

	/** Run $fn with $uid as the current row, then put the previous one back. */
	public static function with_current( string $uid, callable $fn ) {
		$prev = self::$current_uid;
		self::$current_uid = $uid;
		try {
			return $fn();
		} finally {
			self::$current_uid = $prev;
		}
	}

	public static function current_uid(): ?string {
		return self::$current_uid;
	}

	/**
	 * The single writer behind Delicat_Natcash_Auto::log_entry(): writes the
	 * result into the current row, else stores a new row (once per SMS).
	 */
	public static function record( string $raw, $result, array $extra = [] ): void {
		global $wpdb;
		$result = is_scalar( $result ) ? (string) $result : (string) wp_json_encode( $result );
		$uid    = self::$current_uid;
		if ( $uid && self::find( $uid, false ) ) {
			self::update( $uid, array_merge( $extra, [ 'result' => $result ] ) );
			return;
		}
		$raw = self::trim_body( $raw );
		list( $provider, $code, $amount ) = self::parse( $raw, (string) ( $extra['label'] ?? $extra['provider'] ?? '' ) );
		$from = (string) ( $extra['from'] ?? '' );
		$key  = self::hmac( 'sms|' . strtolower( trim( $from ) ) . "\n" . $raw . "\n" );
		$cols = self::column_values( array_merge( $extra, [ 'result' => $result ] ) );
		$meta = self::clean_extra( array_diff_key( $extra, array_flip( [ 'trust', 'result', 'path', 'provider' ] ) ) );
		$ok   = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO " . self::table() . " (uid, replay_key, received_at, provider, path, trust, result, code_norm, amount, sender_masked, sender_hash, body_enc, meta)
			 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %f, %s, %s, %s, %s)",
			self::new_uid(), $key, gmdate( 'Y-m-d H:i:s' ), (string) ( $extra['provider'] ?? $provider ), substr( (string) ( $extra['path'] ?? '' ), 0, 16 ),
			(string) ( $cols['trust'] ?? '' ), (string) $cols['result'], $code, $amount,
			substr( self::mask_phone( $from ), 0, 40 ), self::phone_hash( $from ), self::seal( $raw ), wp_json_encode( $meta )
		) );
		if ( 1 !== (int) $ok ) { // the same SMS again: update its row instead of storing it twice
			$existing = (string) $wpdb->get_var( $wpdb->prepare( "SELECT uid FROM " . self::table() . " WHERE replay_key = %s", $key ) );
			if ( '' !== $existing ) self::update( $existing, array_merge( $extra, [ 'result' => $result ] ) );
		}
	}

	/* ---- reading ----------------------------------------------------------- */

	/**
	 * Unexpired rows as the entry arrays the matchers always used (newest first):
	 * uid, time (site time), sms (decrypted when with_body), result, trust,
	 * provider, flags, resolved…, plus id, received_ts and expires_ts.
	 *
	 * @param array $args code (normalized: rows with that code or no code), since (unix),
	 *                    visible (bool: not cleared), with_body (bool), limit (int)
	 */
	public static function entries( array $args = [] ): array {
		global $wpdb;
		$where = [ $wpdb->prepare( 'received_at >= %s', self::cutoff_gmt() ) ];
		if ( ! empty( $args['since'] ) ) $where[] = $wpdb->prepare( 'received_at >= %s', gmdate( 'Y-m-d H:i:s', (int) $args['since'] ) );
		if ( ! empty( $args['visible'] ) ) $where[] = 'cleared = 0';
		$limit = max( 1, min( 2000, (int) ( $args['limit'] ?? 300 ) ) );
		// With a code: the rows carrying that code, plus the rows nobody could parse
		// (their text may still hold it), each with its own limit so many unparsed
		// SMS can never push the matching one out.
		$sets = ( isset( $args['code'] ) && '' !== (string) $args['code'] )
			? [ $wpdb->prepare( 'code_norm = %s', (string) $args['code'] ), $wpdb->prepare( 'code_norm = %s', '' ) ]
			: [ '' ];
		$rows = [];
		foreach ( $sets as $extra ) {
			$w = $extra ? array_merge( $where, [ $extra ] ) : $where;
			foreach ( (array) $wpdb->get_results( "SELECT * FROM " . self::table() . " WHERE " . implode( ' AND ', $w ) . " ORDER BY received_at DESC, id DESC LIMIT " . $limit ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- parts prepared above.
				$rows[ (int) $r->id ] = $r;
			}
		}
		uasort( $rows, function ( $a, $b ) { return [ $b->received_at, (int) $b->id ] <=> [ $a->received_at, (int) $a->id ]; } );
		$out = [];
		foreach ( $rows as $r ) $out[] = self::to_entry( $r, ! empty( $args['with_body'] ) );
		return $out;
	}

	/** One unexpired row by uid (or null). */
	public static function find( string $uid, bool $with_body = true ): ?array {
		global $wpdb;
		if ( '' === $uid ) return null;
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE uid = %s AND received_at >= %s", $uid, self::cutoff_gmt() ) );
		return $r ? self::to_entry( $r, $with_body ) : null;
	}

	private static function to_entry( $r, bool $with_body ): array {
		$meta = json_decode( (string) ( $r->meta ?? '' ), true );
		$e    = is_array( $meta ) ? $meta : [];
		$ts   = strtotime( $r->received_at . ' UTC' );
		$e['id']          = (int) $r->id;
		$e['uid']         = (string) $r->uid;
		$e['time']        = function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( $r->received_at ) : $r->received_at;
		$e['received_ts'] = $ts;
		$e['expires_ts']  = $ts + self::window_seconds();
		$e['result']      = (string) $r->result;
		$e['provider']    = (string) $r->provider;
		$e['path']        = (string) $r->path;
		$e['from']        = (string) $r->sender_masked;
		if ( '' !== (string) $r->trust ) $e['trust'] = (string) $r->trust;
		if ( '' !== (string) $r->code_norm ) $e['sms_code_norm'] = (string) $r->code_norm;
		if ( (int) $r->recharge_id ) $e['recharge_id'] = (int) $r->recharge_id;
		$e['resolved'] = (int) $r->resolved ? 1 : ( ! empty( $e['resolved'] ) ? 1 : 0 );
		if ( ! $e['resolved'] ) unset( $e['resolved'] );
		$e['cleared'] = (int) $r->cleared;
		$e['sms']     = $with_body ? self::open( $r->body_enc ) : '';
		return $e;
	}

	/** Column part of a change set. */
	private static function column_values( array $changes ): array {
		$cols = [];
		if ( isset( $changes['result'] ) ) $cols['result'] = substr( self::mask_text( (string) $changes['result'] ), 0, 255 );
		if ( isset( $changes['trust'] ) )  $cols['trust']  = substr( (string) $changes['trust'], 0, 16 );
		foreach ( [ 'resolved', 'cleared' ] as $k ) if ( isset( $changes[ $k ] ) ) $cols[ $k ] = $changes[ $k ] ? 1 : 0;
		if ( isset( $changes['recharge_id'] ) ) $cols['recharge_id'] = absint( $changes['recharge_id'] );
		if ( isset( $changes['provider'] ) && '' !== (string) $changes['provider'] ) $cols['provider'] = substr( (string) $changes['provider'], 0, 16 );
		if ( isset( $changes['path'] ) && '' !== (string) $changes['path'] ) $cols['path'] = substr( (string) $changes['path'], 0, 16 );
		return $cols;
	}

	/** Change a row: known columns directly, everything else (masked) into meta. */
	public static function update( string $uid, array $changes ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT meta FROM " . self::table() . " WHERE uid = %s", $uid ) );
		if ( ! $row ) return;
		$cols = self::column_values( $changes );
		$rest = array_diff_key( $changes, array_flip( [ 'result', 'trust', 'resolved', 'cleared', 'recharge_id', 'provider', 'path', 'sms', 'uid', 'time', 'id' ] ) );
		if ( $rest ) {
			$meta = json_decode( (string) $row->meta, true );
			$meta = is_array( $meta ) ? $meta : [];
			$cols['meta'] = wp_json_encode( array_merge( $meta, self::clean_extra( $rest ) ) );
		}
		if ( $cols ) $wpdb->update( self::table(), $cols, [ 'uid' => $uid ] );
	}

	/** Hide every current row from the diagnostic list; they still match top-ups until they expire. */
	public static function clear_view(): void {
		global $wpdb;
		$wpdb->query( "UPDATE " . self::table() . " SET cleared = 1 WHERE cleared = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- constant SQL.
	}

	/* ---- audit on the top-up ----------------------------------------------- */

	/**
	 * A top-up was credited: mark the SMS rows with its TransCode as matched and
	 * leave on the top-up row what its audit needs once the SMS is gone:
	 * provider, time received, masked sender, TransCode, amount. Written once.
	 */
	public static function on_approved( int $recharge_id, string $code_norm ): void {
		global $wpdb;
		if ( $recharge_id <= 0 || '' === $code_norm ) return;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE code_norm = %s AND received_at >= %s ORDER BY (trust = 'ok') DESC, received_at DESC LIMIT 5",
			$code_norm, self::cutoff_gmt()
		) );
		if ( ! $rows ) return;
		$best = $rows[0];
		foreach ( $rows as $r ) $wpdb->update( self::table(), [ 'recharge_id' => $recharge_id ], [ 'id' => (int) $r->id ] );
		if ( ! class_exists( 'Delicat_Database' ) ) return;
		$rec = Delicat_Database::get( $recharge_id );
		if ( ! $rec || false !== strpos( (string) $rec->note_admin, '[SMS-AUDIT' ) ) return;
		Delicat_Database::update( $recharge_id, [
			'note_admin' => trim( (string) $rec->note_admin . "\n" . self::audit_line( $best ) ),
		] );
	}

	/** "[SMS-AUDIT MonCash reçu 2026-11-07 14:55 — expéditeur +509 ••••••09 — TransCode 0033… — 500.00 HTG]" */
	public static function audit_line( $r ): string {
		$meta  = json_decode( (string) ( $r->meta ?? '' ), true );
		$payer = is_array( $meta ) ? (string) ( $meta['payer'] ?? $meta['sender'] ?? '' ) : '';
		$who   = '' !== $payer ? $payer : (string) $r->sender_masked;
		$when  = function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( $r->received_at, 'Y-m-d H:i' ) : $r->received_at;
		return sprintf( '[SMS-AUDIT %s reçu %s — expéditeur %s — TransCode %s — %s HTG]',
			(string) $r->provider, $when, '' !== $who ? $who : '—', (string) $r->code_norm, number_format( (float) $r->amount, 2, '.', '' ) );
	}

	/* ---- purge ------------------------------------------------------------- */

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	public static function clear_schedules(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function cron_purge(): void {
		// The pre-6.6.5 log still there (its move failed at the update): move it now.
		if ( null !== get_option( self::LEGACY_OPTION, null ) ) self::migrate_legacy_log();
		self::purge( 50 );
	}

	/**
	 * Delete for good every row older than the window, in DELETE … LIMIT batches,
	 * under a DB lock so two purges never overlap. Returns rows deleted.
	 */
	public static function purge( int $max_batches = 50, ?int $now = null ): int {
		global $wpdb;
		if ( 1 !== (int) $wpdb->get_var( "SELECT GET_LOCK('delicat_sms_purge', 0)" ) ) return 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- constant SQL.
		$deleted = 0;
		try {
			$cutoff = self::cutoff_gmt( $now );
			for ( $i = 0; $i < max( 1, $max_batches ); $i++ ) {
				$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM " . self::table() . " WHERE received_at < %s LIMIT %d", $cutoff, self::BATCH ) );
				$deleted += max( 0, $n );
				if ( $n < self::BATCH ) break;
			}
			update_option( self::OPT_LAST_PURGE, time(), false );
		} finally {
			$wpdb->get_var( "SELECT RELEASE_LOCK('delicat_sms_purge')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- constant SQL.
		}
		return $deleted;
	}

	/** Cheap purge from the intake, at most every 10 minutes, so expiry happens even if WP-Cron is late. */
	public static function maybe_purge_on_intake(): void {
		if ( time() - (int) get_option( self::OPT_LAST_PURGE, 0 ) < self::INTAKE_PURGE_EVERY ) return;
		self::purge( 2 );
	}

	/**
	 * One-time move of the pre-6.6.5 option log into the table: entries still
	 * inside the window are stored (encrypted, masked); older ones are dropped.
	 * The option is then deleted, and the 6.6.4 retention setting with it.
	 */
	public static function migrate_legacy_log(): int {
		global $wpdb;
		// No table, no move: the old log stays until the next try (never lost).
		if ( self::table() !== (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) ) return 0;
		$entries = get_option( self::LEGACY_OPTION, null );
		$moved   = 0;
		if ( is_array( $entries ) ) {
			$cutoff = time() - self::window_seconds();
			foreach ( $entries as $e ) {
				if ( ! is_array( $e ) ) continue;
				try {
					$ts = ( new DateTime( (string) ( $e['time'] ?? '' ), wp_timezone() ) )->getTimestamp();
				} catch ( Exception $ex ) {
					continue;
				}
				if ( $ts < $cutoff ) continue; // expired: gone
				$raw  = self::trim_body( (string) ( $e['sms'] ?? '' ) );
				$from = (string) ( $e['from'] ?? '' );
				list( $provider, $code, $amount ) = self::parse( $raw, (string) ( $e['provider'] ?? '' ) );
				$meta = self::clean_extra( array_diff_key( $e, array_flip( [ 'uid', 'time', 'sms', 'result', 'trust', 'provider', 'path', 'resolved', 'sms_purged' ] ) ) );
				if ( ! empty( $e['resolved'] ) ) $meta['resolved'] = 1;
				$n = $wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO " . self::table() . " (uid, replay_key, received_at, provider, path, trust, result, code_norm, amount, sender_masked, sender_hash, body_enc, meta, resolved)
					 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %f, %s, %s, %s, %s, %d)",
					substr( preg_replace( '/[^a-z0-9]/', '', (string) ( $e['uid'] ?? '' ) ) ?: self::new_uid(), 0, 16 ),
					self::hmac( 'legacy|' . (string) ( $e['uid'] ?? '' ) . "\n" . $raw . "\n" . (string) ( $e['time'] ?? '' ) ),
					gmdate( 'Y-m-d H:i:s', $ts ), (string) ( $e['provider'] ?? $provider ), substr( (string) ( $e['path'] ?? '' ), 0, 16 ),
					substr( (string) ( $e['trust'] ?? '' ), 0, 16 ), substr( self::mask_text( is_scalar( $e['result'] ?? '' ) ? (string) ( $e['result'] ?? '' ) : '' ), 0, 255 ),
					$code, $amount, substr( self::mask_phone( $from ), 0, 40 ), self::phone_hash( $from ), self::seal( $raw ), wp_json_encode( $meta ),
					empty( $e['resolved'] ) ? 0 : 1
				) );
				$moved += (int) $n;
			}
		}
		delete_option( self::LEGACY_OPTION );
		delete_option( 'delicat_sms_retention_days' ); // 6.6.4 setting (days), replaced by OPT_HOURS
		return $moved;
	}

	/* ---- request ------------------------------------------------------------ */

	/**
	 * HTTPS for the SMS intake (403 otherwise). TLS may end at Cloudflare /
	 * LiteSpeed, so a forwarded https scheme counts too. Emergency switch:
	 * add_filter( 'delicat_recharge_sms_require_https', '__return_false' ).
	 */
	public static function https_ok(): bool {
		if ( ! apply_filters( 'delicat_recharge_sms_require_https', true ) ) return true;
		if ( function_exists( 'is_ssl' ) && is_ssl() ) return true;
		if ( function_exists( 'delicat_builder_v9_request_is_secure' ) && delicat_builder_v9_request_is_secure() ) return true;
		$xfp = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( trim( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] )[0] ) ) : '';
		if ( 'https' === $xfp ) return true;
		$cfv = isset( $_SERVER['HTTP_CF_VISITOR'] ) ? (string) $_SERVER['HTTP_CF_VISITOR'] : '';
		return false !== stripos( $cfv, '"scheme":"https"' );
	}

	/* ---- helpers ------------------------------------------------------------ */

	private static function trim_body( string $raw ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, self::BODY_MAX ) : substr( $raw, 0, self::BODY_MAX );
	}

	private static function new_uid(): string {
		return substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
	}
}
