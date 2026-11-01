<?php
/**
 * Parrainage (3.4.0) — a customer invites friends with a personal code and link; each friend who
 * creates an account and pays a first real order earns the customer 1 point (up to 100). Points
 * unlock milestone rewards (5, 15, 35, 60, 85, 100 by default), each claimed once, as Free Fire
 * diamonds (a PIN reward order) or as store credit in HTG (TeraWallet).
 *
 * Fraud rules, applied when the friend signs up, pays, and again before the point counts:
 *   - same phone/browser (the first-party device cookie, Identity's when present) as the inviter, or
 *     as any other account: refused; with the « same device » policy on « block », the
 *     accounts involved leave the whole rewards programme until the team reviews them;
 *   - same payment phone number or e-mail (aliases such as dots and +tags) as another account;
 *   - the same network as the inviter in the last 3 days: sent to the team's review (not refused);
 *   - VPN or proxy at the visit, the sign-up, a code entry or a claim (IP reputation service);
 *   - the first order must be paid by a real payment method (not store credit by default), at
 *     least the minimum amount, completed, and not refunded during the holding period.
 * Signals are stored as keyed hashes only (never a raw IP address, phone number or e-mail).
 */
defined( 'ABSPATH' ) || exit;

const DELICAT_REF_DB         = 1;
const DELICAT_REF_MAX_POINTS = 100;
const DELICAT_REF_VPN_ANON_PER_HOUR = 300; // provider lookups from link visits (no account), per hour
const DELICAT_REF_SIGNALS_PER_KIND = 100; // rows kept per account and kind (device, ip, fp, email, phone)
const DELICAT_REF_IP_WINDOW  = 259200; // 3 days: network addresses are compared only while this recent

/* ============================================================== storage */

function delicat_ref_table( $which ) {
	global $wpdb;
	$map = array( 'referrals' => 'delicat_referrals', 'claims' => 'delicat_ref_claims', 'signals' => 'delicat_ref_signals' );
	return $wpdb->prefix . ( $map[ $which ] ?? 'delicat_referrals' );
}

function delicat_ref_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta( 'CREATE TABLE ' . delicat_ref_table( 'referrals' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  referrer_id bigint(20) unsigned NOT NULL,
  referred_id bigint(20) unsigned NOT NULL,
  source varchar(10) NOT NULL DEFAULT 'link',
  status varchar(12) NOT NULL DEFAULT 'signed_up',
  reason varchar(40) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  qualified_at datetime DEFAULT NULL,
  confirm_after datetime DEFAULT NULL,
  decided_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY referred_id (referred_id),
  KEY referrer_status (referrer_id,status),
  KEY status_confirm (status,confirm_after)
) $c;" );
	dbDelta( 'CREATE TABLE ' . delicat_ref_table( 'claims' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  milestone smallint(5) unsigned NOT NULL,
  choice varchar(10) NOT NULL,
  amount int(10) unsigned NOT NULL,
  ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(10) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_milestone (user_id,milestone)
) $c;" );
	dbDelta( 'CREATE TABLE ' . delicat_ref_table( 'signals' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  kind varchar(8) NOT NULL,
  hash char(64) NOT NULL,
  seen_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_kind_hash (user_id,kind,hash),
  KEY kind_hash (kind,hash)
) $c;" );
	update_option( 'delicat_ref_db', DELICAT_REF_DB, true );
}
add_action( 'plugins_loaded', function () {
	if ( (int) get_option( 'delicat_ref_db', 0 ) < DELICAT_REF_DB ) delicat_ref_install();
}, 20 );

/* ============================================================== settings */

function delicat_ref_defaults() {
	return array(
		'enabled'          => 0,    // off until the owner has checked the rewards below
		'hold_days'        => 7,    // a first order must stay paid this long before the point counts
		'min_order'        => 100,  // HTG, the friend's first order
		'order_days'       => 30,   // the first order must come within this many days of the sign-up
		'attribution_days' => 30,   // a link visit is remembered this long
		'code_days'        => 7,    // a new account may type an inviter's code this long after signing up
		'exclude_wallet'   => 1,    // the qualifying order is paid by a real payment method, not store credit
		'max_points'       => DELICAT_REF_MAX_POINTS,
		'milestones'       => array(
			array( 'points' => 5,   'diamonds' => 110,  'htg' => 140 ),
			array( 'points' => 15,  'diamonds' => 341,  'htg' => 420 ),
			array( 'points' => 35,  'diamonds' => 572,  'htg' => 700 ),
			array( 'points' => 60,  'diamonds' => 1160, 'htg' => 1400 ),
			array( 'points' => 85,  'diamonds' => 2180, 'htg' => 2600 ),
			array( 'points' => 100, 'diamonds' => 3340, 'htg' => 4000 ),
		),
		'same_device'      => 'block',  // block: refused + accounts leave the rewards programme | review
		'same_ip'          => 'review', // review | block | off (mobile networks share addresses: review by default)
		'vpn'              => 'block',  // block | review | off
		'daily_cap'        => 5,        // more new friends than this in 24 h: the next ones go to review
		'page_id'          => 0,        // the Bonus Free Fire page; 0 = found automatically
		'share_text'       => 'Rejoins-moi sur Delicat Store pour tes diamants Free Fire ! Crée ton compte avec mon lien et fais ton premier achat : {lien} (code {code})',
	);
}
function delicat_ref_cfg( $key = null, $reload = false ) {
	static $c = null;
	if ( null === $c || $reload ) { // read once per request; $reload after the settings change
		$saved = get_option( 'delicat_ref_config', array() );
		$c = wp_parse_args( is_array( $saved ) ? $saved : array(), delicat_ref_defaults() );
	}
	return null === $key ? $c : ( $c[ $key ] ?? null );
}
function delicat_ref_on() {
	return 1 === (int) delicat_ref_cfg( 'enabled' ) && function_exists( 'wc_get_orders' );
}
/** Milestones sorted by points, within 1…max points. */
function delicat_ref_milestones() {
	$max = min( DELICAT_REF_MAX_POINTS, max( 1, (int) delicat_ref_cfg( 'max_points' ) ) );
	$out = array();
	foreach ( (array) delicat_ref_cfg( 'milestones' ) as $m ) {
		$p = (int) ( $m['points'] ?? 0 );
		if ( $p < 1 || $p > $max ) continue;
		$out[ $p ] = array( 'points' => $p, 'diamonds' => max( 0, (int) ( $m['diamonds'] ?? 0 ) ), 'htg' => max( 0, (int) ( $m['htg'] ?? 0 ) ) );
	}
	ksort( $out );
	return array_values( $out );
}

/* ============================================================== codes and links */

function delicat_ref_normalize_code( $code ) {
	$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
	return preg_match( '/^DLC[A-HJ-NP-Z2-9]{6}$/', $code ) ? $code : '';
}
function delicat_ref_user_by_code( $code ) {
	$code = delicat_ref_normalize_code( $code );
	if ( '' === $code ) return 0;
	$ids = get_users( array( 'meta_key' => '_delicat_ref_code', 'meta_value' => $code, 'number' => 1, 'fields' => 'ID' ) );
	return $ids ? (int) $ids[0] : 0;
}
/** The customer's personal code, created on first use: DLC + 6 characters without 0/O/1/I. */
function delicat_ref_code( $user_id, $create = true ) {
	$user_id = (int) $user_id;
	$code = (string) get_user_meta( $user_id, '_delicat_ref_code', true );
	if ( '' !== $code || ! $create || $user_id < 1 ) return $code;
	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	for ( $try = 0; $try < 10; $try++ ) {
		$c = 'DLC';
		for ( $i = 0; $i < 6; $i++ ) $c .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		if ( delicat_ref_user_by_code( $c ) ) continue;
		add_user_meta( $user_id, '_delicat_ref_code', $c, true );
		return (string) get_user_meta( $user_id, '_delicat_ref_code', true );
	}
	return '';
}
/** A public page: published, of type page, without a password. */
function delicat_ref_public_page( $id ) {
	$id = (int) $id;
	return $id > 0 && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) && '' === (string) get_post_field( 'post_password', $id );
}
/**
 * The Bonus Free Fire page the invitation links open: the page chosen in the dashboard, else the first
 * public page holding the shortcode (looked up once a day, again when a page is saved). Never taken from
 * a page view: whoever can preview a draft must not be able to redirect every customer's link.
 */
function delicat_ref_page_id() {
	$chosen = (int) delicat_ref_cfg( 'page_id' );
	if ( delicat_ref_public_page( $chosen ) ) return $chosen;
	$found = get_transient( 'delicat_ref_page' );
	if ( false === $found ) {
		global $wpdb;
		$found = 0;
		foreach ( array( '[delicat_bonus_free_fire', '[delicat_rewards', '[delicat_meilleur_client' ) as $tag ) {
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_password='' AND post_content LIKE %s ORDER BY ID ASC LIMIT 5", '%' . $wpdb->esc_like( $tag ) . '%' ) ) as $id ) {
				if ( delicat_ref_public_page( $id ) ) { $found = (int) $id; break 2; }
			}
		}
		set_transient( 'delicat_ref_page', $found, DAY_IN_SECONDS );
	}
	return delicat_ref_public_page( $found ) ? (int) $found : 0;
}
add_action( 'save_post_page', function () { delete_transient( 'delicat_ref_page' ); } );
function delicat_ref_link( $code ) {
	$page = delicat_ref_page_id();
	$base = $page ? (string) get_permalink( $page ) : '';
	if ( '' === $base ) return ''; // no public Bonus Free Fire page: a link would land nowhere
	return add_query_arg( 'ref', rawurlencode( $code ), $base ) . '#parrainage';
}

/** Where a new customer opens an account (Identity's sign-in window takes over the link when it is active). */
function delicat_ref_signup_url( $back = '' ) {
	if ( function_exists( 'wc_get_page_permalink' ) && 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) return (string) wc_get_page_permalink( 'myaccount' );
	if ( get_option( 'users_can_register' ) ) return wp_registration_url();
	return wp_login_url( $back );
}

/* ============================================================== signals */

function delicat_ref_hash( $kind, $value ) {
	return hash_hmac( 'sha256', $kind . '|' . $value, delicat_secret() );
}
/** The visitor's address: Cloudflare's CF-Connecting-IP when the request really comes from Cloudflare (Identity). */
function delicat_ref_ip() {
	return delicat_client_ip();
}
/**
 * This browser's device hash: Identity's (the same one its sign-in history keeps per account),
 * or this programme's own first-party cookie when Identity is absent. '' without a cookie unless
 * $create; never the User-Agent alone, which thousands of identical phones share.
 */
function delicat_ref_device( $create = false ) {
	if ( class_exists( 'DIP_Devices' ) && method_exists( 'DIP_Devices', 'current_hash' ) ) {
		$raw = isset( $_COOKIE['dip_device_id'] ) && is_string( $_COOKIE['dip_device_id'] ) ? (string) wp_unslash( $_COOKIE['dip_device_id'] ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{32,128}$/', $raw ) && ( ! $create || headers_sent() ) ) return '';
		$hash = (string) DIP_Devices::current_hash( true );
		// Only the cookie-based identity: without its cookie Identity falls back to a User-Agent hash shared by identical phones.
		return isset( $_COOKIE['dip_device_id'] ) && preg_match( '/^[A-Za-z0-9_-]{32,128}$/', (string) $_COOKIE['dip_device_id'] ) ? $hash : '';
	}
	$raw = isset( $_COOKIE['dlc_did'] ) && is_string( $_COOKIE['dlc_did'] ) ? (string) wp_unslash( $_COOKIE['dlc_did'] ) : '';
	if ( ! preg_match( '/^[A-Za-z0-9_-]{32,64}$/', $raw ) ) {
		if ( ! $create || headers_sent() ) return '';
		$raw = rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' );
		setcookie( 'dlc_did', $raw, array( 'expires' => time() + YEAR_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		$_COOKIE['dlc_did'] = $raw;
	}
	return delicat_ref_hash( 'did', $raw );
}
function delicat_ref_norm_phone( $phone ) {
	$d = preg_replace( '/\D+/', '', (string) $phone );
	return strlen( $d ) >= 8 ? substr( $d, -8 ) : ''; // Haitian numbers: the 8 local digits, with or without +509
}
function delicat_ref_norm_email( $email ) {
	$email = strtolower( trim( (string) $email ) );
	if ( ! is_email( $email ) ) return '';
	list( $local, $domain ) = explode( '@', $email, 2 );
	$local = preg_replace( '/\+.*$/', '', $local );
	if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) { $local = str_replace( '.', '', $local ); $domain = 'gmail.com'; }
	return $local . '@' . $domain;
}
function delicat_ref_signal( $user_id, $kind, $hash ) {
	global $wpdb;
	if ( (int) $user_id < 1 || ! preg_match( '/^[a-f0-9]{64}$/', (string) $hash ) ) return;
	$t = delicat_ref_table( 'signals' ); $now = current_time( 'mysql', 1 );
	// Known: refreshed. New: always recorded, within DELICAT_REF_SIGNALS_PER_KIND rows per account and kind.
	// At the limit, room is made without losing what detection uses: network addresses older than the
	// comparison window go first; for the other kinds the account's first 20 rows (the evidence) stay and
	// the least recently seen of the others gives way. A flood can neither stop new signals being recorded
	// nor push the first ones out, and the table cannot grow without end.
	if ( $wpdb->query( $wpdb->prepare( "UPDATE $t SET seen_at=%s WHERE user_id=%d AND kind=%s AND hash=%s", $now, (int) $user_id, $kind, $hash ) ) ) return; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count = function () use ( $wpdb, $t, $user_id, $kind ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id=%d AND kind=%s", (int) $user_id, $kind ) ); }; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $count() >= DELICAT_REF_SIGNALS_PER_KIND ) {
		if ( 'ip' === $kind ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE user_id=%d AND kind='ip' AND seen_at<%s", (int) $user_id, gmdate( 'Y-m-d H:i:s', time() - DELICAT_REF_IP_WINDOW ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $count() >= DELICAT_REF_SIGNALS_PER_KIND ) {
			$first = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE user_id=%d AND kind=%s ORDER BY id ASC LIMIT 1 OFFSET 19", (int) $user_id, $kind ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$gone  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE user_id=%d AND kind=%s AND id>%d ORDER BY seen_at ASC, id ASC LIMIT 1", (int) $user_id, $kind, $first ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $gone ) $wpdb->delete( $t, array( 'id' => $gone ) );
		}
	}
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (user_id,kind,hash,seen_at) VALUES (%d,%s,%s,%s)", (int) $user_id, $kind, $hash, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
/** Records what this request tells about the signed-in customer (device, network, browser, e-mail). */
function delicat_ref_capture( $user_id, $fp = '', $create_device = false ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) return;
	$device = delicat_ref_device( $create_device );
	if ( '' !== $device ) delicat_ref_signal( $user_id, 'device', $device );
	$ip = delicat_ref_ip();
	if ( '' !== $ip ) delicat_ref_signal( $user_id, 'ip', delicat_ref_hash( 'ip', $ip ) );
	if ( preg_match( '/^[a-f0-9]{64}$/', (string) $fp ) ) delicat_ref_signal( $user_id, 'fp', delicat_ref_hash( 'fp', $fp ) );
	$u = get_userdata( $user_id );
	if ( $u ) {
		$e = delicat_ref_norm_email( $u->user_email );
		if ( '' !== $e ) delicat_ref_signal( $user_id, 'email', delicat_ref_hash( 'email', $e ) );
	}
}
function delicat_ref_hashes( $user_id ) {
	global $wpdb;
	$out = array( 'device' => array(), 'ip' => array(), 'fp' => array(), 'email' => array(), 'phone' => array() );
	// Network addresses only while recent: mobile carriers hand the same public addresses to thousands of
	// customers, so two accounts that each used an address weeks apart prove nothing.
	$since = gmdate( 'Y-m-d H:i:s', time() - DELICAT_REF_IP_WINDOW );
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT kind,hash FROM ' . delicat_ref_table( 'signals' ) . " WHERE user_id=%d AND (kind<>'ip' OR seen_at>=%s) LIMIT 1000", (int) $user_id, $since ) ) as $r ) { // at most 100 per kind are kept
		if ( isset( $out[ $r->kind ] ) ) $out[ $r->kind ][] = $r->hash;
	}
	// Identity's own device list is not read: rows saved before its device cookie existed are a hash of
	// the User-Agent alone, the same for every phone of one model, and would match strangers.
	foreach ( $out as $k => $v ) $out[ $k ] = array_values( array_unique( $v ) );
	return $out;
}
/** Other accounts that share one of these hashes. */
function delicat_ref_other_users( $kind, array $hashes, $exclude_user ) {
	global $wpdb;
	$hashes = array_values( array_filter( $hashes, function ( $h ) { return (bool) preg_match( '/^[a-f0-9]{64}$/', (string) $h ); } ) );
	if ( ! $hashes ) return array();
	$in  = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM ' . delicat_ref_table( 'signals' ) . " WHERE kind=%s AND hash IN ($in) AND user_id<>%d LIMIT 20", array_merge( array( $kind ), $hashes, array( (int) $exclude_user ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return array_values( array_unique( array_map( 'intval', $ids ) ) );
}
/** Other accounts whose saved billing phone ends with the same 8 digits. */
function delicat_ref_phone_users( $phone8, $exclude_user ) {
	global $wpdb;
	if ( ! preg_match( '/^\d{8}$/', (string) $phone8 ) ) return array();
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='billing_phone' AND REPLACE(REPLACE(REPLACE(meta_value,' ',''),'-',''),'+','') LIKE %s AND user_id<>%d LIMIT 20", '%' . $wpdb->esc_like( $phone8 ), (int) $exclude_user ) ) );
}

/* ============================================================== VPN / proxy */

/**
 * true when the address belongs to a VPN, proxy or hosting network. The answer comes from the
 * delicat_ref_vpn_verdict filter (custom provider) or from proxycheck.io with the key defined in
 * wp-config.php as DELICAT_PROXYCHECK_KEY; without either, nothing is checked (false). The key is
 * never printed, stored in the database or sent to the browser. A failed lookup does not block.
 */
function delicat_ref_is_vpn( $ip = null, $anonymous = false ) {
	$ip = null === $ip ? delicat_ref_ip() : (string) $ip;
	$verdict = apply_filters( 'delicat_ref_vpn_verdict', null, $ip );
	if ( null !== $verdict ) return (bool) $verdict;
	$key = defined( 'DELICAT_PROXYCHECK_KEY' ) ? (string) DELICAT_PROXYCHECK_KEY : '';
	if ( '' === $key || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) return false;
	$cache = 'delicat_vpn_' . md5( $ip );
	$known = get_transient( $cache );
	if ( false !== $known ) return '1' === $known;
	// Link visits need no account: their lookups share an hourly budget, so nobody can spend the provider's
	// quota for the whole store. Sign-ups, codes and claims are always checked.
	if ( $anonymous ) {
		$bk = 'delicat_vpn_budget_' . gmdate( 'YmdH' );
		if ( ! delicat_ref_rate( $bk, DELICAT_REF_VPN_ANON_PER_HOUR, HOUR_IN_SECONDS ) ) return false;
	}
	$r = wp_remote_get( 'https://proxycheck.io/v2/' . rawurlencode( $ip ) . '?vpn=1&key=' . rawurlencode( $key ), array( 'timeout' => 3, 'redirection' => 0 ) );
	if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		delicat_log( 'warning', 'Referral VPN check unavailable.', array() );
		return false;
	}
	$j = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	if ( ! is_array( $j ) || ! isset( $j[ $ip ]['proxy'] ) ) { // quota reached, key refused…: no verdict, nothing remembered
		delicat_log( 'warning', 'Referral VPN check gave no answer.', array( 'status' => is_array( $j ) ? (string) ( $j['status'] ?? '' ) : '' ) );
		return false;
	}
	$is = 'yes' === $j[ $ip ]['proxy'];
	set_transient( $cache, $is ? '1' : '0', DAY_IN_SECONDS );
	return $is;
}
function delicat_ref_vpn_provider() {
	if ( has_filter( 'delicat_ref_vpn_verdict' ) ) return 'filter';
	return defined( 'DELICAT_PROXYCHECK_KEY' ) && '' !== (string) DELICAT_PROXYCHECK_KEY ? 'proxycheck' : '';
}

/* ============================================================== rules */

function delicat_ref_blocked( $user_id ) {
	return '' !== (string) get_user_meta( (int) $user_id, '_delicat_ref_blocked', true ) || ( function_exists( 'delicat_is_excluded' ) && delicat_is_excluded( $user_id ) );
}
/** Leaves the referral programme (and, for a shared device, the whole rewards programme) until the team lifts it. */
function delicat_ref_block( $user_id, $reason, $rewards_too = false ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 || user_can( $user_id, 'manage_woocommerce' ) ) return; // staff are reviewed by hand, never auto-blocked
	update_user_meta( $user_id, '_delicat_ref_blocked', sanitize_key( $reason ) . '|' . time() );
	if ( $rewards_too && function_exists( 'delicat_set_excluded' ) && ! delicat_is_excluded( $user_id ) ) {
		delicat_set_excluded( $user_id, true );
		update_user_meta( $user_id, '_delicat_ref_excluded_by', '1' ); // so lifting the block restores it too
	}
}
function delicat_ref_unblock( $user_id ) {
	$user_id = (int) $user_id;
	delete_user_meta( $user_id, '_delicat_ref_blocked' );
	if ( get_user_meta( $user_id, '_delicat_ref_excluded_by', true ) && function_exists( 'delicat_set_excluded' ) ) delicat_set_excluded( $user_id, false );
	delete_user_meta( $user_id, '_delicat_ref_excluded_by' );
}

/**
 * Verdict on one invitation: 'ok', 'review' (the team decides) or 'reject', with reasons.
 * $block lists the accounts to take out of the programme.
 */
function delicat_ref_risk( $referrer_id, $referred_id ) {
	$cfg = delicat_ref_cfg();
	$rank = array( 'ok' => 0, 'review' => 1, 'reject' => 2 );
	$decision = 'ok'; $reasons = array(); $block = array();
	$raise = function ( $to, $why ) use ( &$decision, &$reasons, $rank ) {
		$reasons[] = $why;
		if ( $rank[ $to ] > $rank[ $decision ] ) $decision = $to;
	};
	if ( (int) $referrer_id === (int) $referred_id ) $raise( 'reject', 'self' );
	if ( delicat_ref_blocked( $referrer_id ) ) $raise( 'reject', 'inviter_blocked' );
	$mine = delicat_ref_hashes( $referred_id );
	$theirs = delicat_ref_hashes( $referrer_id );
	$strict = 'block' === $cfg['same_device'];

	// Same phone or browser as the inviter: one person with two accounts.
	if ( array_intersect( $mine['device'], $theirs['device'] ) ) {
		$raise( $strict ? 'reject' : 'review', 'same_device' );
		if ( $strict ) $block = array( (int) $referrer_id, (int) $referred_id );
	}
	// This « new » friend's phone already belongs to another account: not a new person.
	$others = array_diff( delicat_ref_other_users( 'device', $mine['device'], $referred_id ), array( (int) $referrer_id ) );
	if ( $others ) {
		$raise( $strict ? 'reject' : 'review', 'device_shared' );
		if ( $strict ) $block[] = (int) $referred_id;
	}
	// Payment phone number or e-mail of another account.
	$phone = delicat_ref_norm_phone( get_user_meta( $referred_id, 'billing_phone', true ) );
	if ( '' !== $phone && ( delicat_ref_phone_users( $phone, $referred_id ) || delicat_ref_other_users( 'phone', array( delicat_ref_hash( 'phone', $phone ) ), $referred_id ) ) ) $raise( 'reject', 'phone_shared' );
	if ( delicat_ref_other_users( 'email', $mine['email'], $referred_id ) ) $raise( 'reject', 'email_alias' );
	// Same network as the inviter in the last days: a hint for the team, never proof. Friends invite each
	// other at home on one Wi-Fi, and phones of one model report the same browser summary.
	$same_ip = (bool) array_intersect( $mine['ip'], $theirs['ip'] );
	if ( $same_ip && 'off' !== $cfg['same_ip'] ) {
		$raise( 'block' === $cfg['same_ip'] ? 'reject' : 'review', array_intersect( $mine['fp'], $theirs['fp'] ) ? 'same_browser_network' : 'same_network' );
	}
	// Many new friends in one day: a person making accounts, or a link posted publicly. The team looks first.
	$cap = (int) ( $cfg['daily_cap'] ?? 0 );
	if ( $cap > 0 ) {
		global $wpdb;
		$recent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . delicat_ref_table( 'referrals' ) . ' WHERE referrer_id=%d AND created_at>=%s', (int) $referrer_id, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		if ( $recent > $cap ) $raise( 'review', 'velocity' );
	}
	if ( get_user_meta( $referred_id, '_delicat_ref_vpn', true ) && 'off' !== $cfg['vpn'] ) $raise( 'block' === $cfg['vpn'] ? 'reject' : 'review', 'vpn' );
	return array( 'decision' => $decision, 'reasons' => array_values( array_unique( $reasons ) ), 'block' => array_values( array_unique( $block ) ) );
}
/** Applies a verdict to an invitation row; returns the new status. */
function delicat_ref_apply( $row_id, $referrer_id, $referred_id, $ok_status, $from_status ) {
	global $wpdb;
	$v = delicat_ref_risk( $referrer_id, $referred_id );
	$status = 'ok' === $v['decision'] ? $ok_status : ( 'review' === $v['decision'] ? 'review' : 'rejected' );
	$update = array( 'status' => $status, 'reason' => substr( implode( ',', $v['reasons'] ), 0, 40 ) );
	if ( 'rejected' === $status ) $update['decided_at'] = current_time( 'mysql', 1 );
	// Written only if the row is still in the status it was judged in: a refund or a team decision that
	// landed meanwhile is never overwritten.
	$changed = $wpdb->update( delicat_ref_table( 'referrals' ), $update, array( 'id' => (int) $row_id, 'status' => $from_status ) );
	if ( ! $changed && $status !== $from_status ) return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . delicat_ref_table( 'referrals' ) . ' WHERE id=%d', (int) $row_id ) );
	if ( $v['block'] ) {
		foreach ( $v['block'] as $uid ) delicat_ref_block( $uid, $v['reasons'][0] ?? 'fraud', true );
		delicat_ref_alert_admin( $referrer_id, $referred_id, $v['reasons'] );
	}
	return $status;
}
function delicat_ref_alert_admin_claim( $user_id, $points ) {
	wp_mail( get_option( 'admin_email' ), 'Delicat — Parrainage : réclamation à vérifier',
		sprintf( "La réclamation du palier %d points du client #%d a été interrompue par une erreur.\nVérifiez son portefeuille et ses commandes, puis « Libérer » ce palier dans WooCommerce › Delicat Rewards › Parrainage s'il n'a rien reçu.", (int) $points, (int) $user_id ) );
}
function delicat_ref_alert_admin( $referrer_id, $referred_id, array $reasons ) {
	$key = 'delicat_ref_alert_' . (int) $referrer_id;
	if ( get_transient( $key ) ) return;
	set_transient( $key, 1, HOUR_IN_SECONDS );
	wp_mail( get_option( 'admin_email' ), 'Delicat — Parrainage : comptes bloqués',
		sprintf( "Le parrainage du client #%d par le client #%d a été refusé (%s).\nLes comptes concernés sont sortis du programme de récompenses jusqu'à votre vérification :\nWooCommerce › Delicat Rewards › Parrainage.", (int) $referred_id, (int) $referrer_id, implode( ', ', $reasons ) ) );
}

/* ============================================================== attribution */

function delicat_ref_cookie_value( $code ) {
	$t = time();
	return $code . '.' . $t . '.' . substr( hash_hmac( 'sha256', $code . '|' . $t, delicat_secret() ), 0, 20 );
}
/** The inviter's code remembered from a link visit, if still valid. */
function delicat_ref_cookie_code() {
	$raw = isset( $_COOKIE['dlc_ref'] ) && is_string( $_COOKIE['dlc_ref'] ) ? (string) wp_unslash( $_COOKIE['dlc_ref'] ) : '';
	if ( ! preg_match( '/^(DLC[A-HJ-NP-Z2-9]{6})\.(\d{9,11})\.([a-f0-9]{20})$/', $raw, $m ) ) return '';
	if ( ! hash_equals( substr( hash_hmac( 'sha256', $m[1] . '|' . $m[2], delicat_secret() ), 0, 20 ), $m[3] ) ) return '';
	if ( time() - (int) $m[2] > max( 1, (int) delicat_ref_cfg( 'attribution_days' ) ) * DAY_IN_SECONDS ) return '';
	return $m[1];
}
function delicat_ref_set_cookie( $value, $ttl ) {
	if ( headers_sent() ) return;
	setcookie( 'dlc_ref', $value, array( 'expires' => $ttl > 0 ? time() + $ttl : time() - 3600, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
}
function delicat_ref_rate( $key, $max, $window ) {
	$n = (int) get_transient( $key );
	if ( $n >= $max ) return false;
	set_transient( $key, $n + 1, $window );
	return true;
}

/** A visit through a personal link (AJAX from the page): the friend's browser remembers the inviter. */
function delicat_ref_visit() {
	nocache_headers();
	if ( ! delicat_ref_on() ) wp_send_json( array( 'ok' => false, 'code' => 'off' ) );
	if ( ! delicat_ref_rate( 'delicat_rv_' . md5( delicat_ref_ip() ), 30, MINUTE_IN_SECONDS ) ) wp_send_json( array( 'ok' => false, 'code' => 'rate_limited' ), 429 );
	// Asked by this site's own page only: another site cannot post a link visit in the visitor's browser.
	$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '';
	if ( '' !== $origin && strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) wp_send_json( array( 'ok' => false, 'code' => 'origin' ), 403 );
	$code = delicat_ref_normalize_code( isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public, read-mostly, no account change
	// The first valid invitation on this phone stays: a later link (or a page made to replace it) does not take it over.
	$kept = is_user_logged_in() ? '' : delicat_ref_cookie_code();
	if ( '' !== $kept && $kept !== $code && ( $first = delicat_ref_user_by_code( $kept ) ) && ! delicat_ref_blocked( $first ) ) $code = $kept;
	$inviter = delicat_ref_user_by_code( $code );
	if ( ! $inviter || delicat_ref_blocked( $inviter ) ) wp_send_json( array( 'ok' => false, 'code' => 'invalid' ) );
	if ( 'off' !== delicat_ref_cfg( 'vpn' ) && delicat_ref_is_vpn( null, true ) ) wp_send_json( array( 'ok' => false, 'code' => 'vpn', 'message' => 'VPN ou proxy détecté : désactivez-le pour accepter l’invitation.' ) );
	$name = delicat_display_name( $inviter, true );
	if ( is_user_logged_in() ) wp_send_json( array( 'ok' => true, 'inviter' => $name, 'signedIn' => true ) );
	delicat_ref_device( true ); // the phone gets its identity now, before the sign-up
	if ( $kept !== $code ) delicat_ref_set_cookie( delicat_ref_cookie_value( $code ), max( 1, (int) delicat_ref_cfg( 'attribution_days' ) ) * DAY_IN_SECONDS );
	$fp = isset( $_POST['fp'] ) ? (string) wp_unslash( $_POST['fp'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( preg_match( '/^[a-f0-9]{64}$/', $fp ) && ! headers_sent() ) setcookie( 'dlc_fp', $fp, array( 'expires' => time() + DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	wp_send_json( array( 'ok' => true, 'inviter' => $name ) );
}
add_action( 'wp_ajax_delicat_ref_visit', 'delicat_ref_visit' );
add_action( 'wp_ajax_nopriv_delicat_ref_visit', 'delicat_ref_visit' );

/** Creates the invitation row and judges it. */
function delicat_ref_attach( $referred_id, $referrer_id, $source, $fp = '' ) {
	global $wpdb;
	$referred_id = (int) $referred_id; $referrer_id = (int) $referrer_id;
	if ( $referred_id < 1 || $referrer_id < 1 || $referred_id === $referrer_id ) return new WP_Error( 'self', 'Vous ne pouvez pas utiliser votre propre code.' );
	delicat_ref_capture( $referred_id, $fp, true );
	if ( 'off' !== delicat_ref_cfg( 'vpn' ) && delicat_ref_is_vpn() ) update_user_meta( $referred_id, '_delicat_ref_vpn', '1' );
	$ok = $wpdb->insert( delicat_ref_table( 'referrals' ), array(
		'referrer_id' => $referrer_id, 'referred_id' => $referred_id, 'source' => $source,
		'status' => 'signed_up', 'created_at' => current_time( 'mysql', 1 ),
	), array( '%d', '%d', '%s', '%s', '%s' ) );
	if ( ! $ok ) return new WP_Error( 'exists', 'Ce compte a déjà un parrain.' );
	return delicat_ref_apply( (int) $wpdb->insert_id, $referrer_id, $referred_id, 'signed_up', 'signed_up' );
}

/* A new account opened from a personal link (any sign-up form: e-mail, code, Google…). */
add_action( 'user_register', function ( $user_id ) {
	if ( ! delicat_ref_on() ) return;
	$code = delicat_ref_cookie_code();
	if ( '' === $code ) return;
	$inviter = delicat_ref_user_by_code( $code );
	delicat_ref_set_cookie( '', 0 );
	if ( ! $inviter || delicat_ref_blocked( $inviter ) ) return;
	$fp = isset( $_COOKIE['dlc_fp'] ) && is_string( $_COOKIE['dlc_fp'] ) ? (string) wp_unslash( $_COOKIE['dlc_fp'] ) : '';
	delicat_ref_attach( (int) $user_id, $inviter, 'link', $fp );
}, 20 );

/* Signals at every sign-in while the programme runs (Identity also keeps the device per account). */
add_action( 'wp_login', function ( $login, $user ) {
	if ( delicat_ref_on() && $user instanceof WP_User ) delicat_ref_capture( $user->ID );
}, 30, 2 );

/* ============================================================== qualification */

function delicat_ref_row_for( $referred_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . delicat_ref_table( 'referrals' ) . ' WHERE referred_id=%d', (int) $referred_id ) );
}
/** Is this order a real first purchase that can qualify an invitation? */
function delicat_ref_order_counts( $order ) {
	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) return false;
	if ( $order->get_meta( '_delicat_reward' ) ) return false;                         // a free reward order
	if ( ! $order->is_paid() || ! $order->has_status( 'completed' ) ) return false;
	// 100 % coupons, tiny orders, and what is left after a partial refund
	if ( (float) $order->get_total() - (float) $order->get_total_refunded() < max( 1, (float) delicat_ref_cfg( 'min_order' ) ) ) return false;
	// A wallet top-up is money kept by the friend (and transferable): not a purchase.
	if ( function_exists( 'is_wallet_rechargeable_order' ) && is_wallet_rechargeable_order( $order ) ) return false;
	if ( (int) delicat_ref_cfg( 'exclude_wallet' ) && in_array( $order->get_payment_method(), array( 'wallet', 'woo_wallet', 'delicat_wallet' ), true ) ) return false;
	return true;
}
add_action( 'woocommerce_order_status_completed', function ( $order_id ) {
	global $wpdb;
	if ( ! delicat_ref_on() ) return;
	$order = wc_get_order( $order_id );
	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) return;
	$uid = (int) $order->get_customer_id();
	if ( $uid < 1 ) return;
	$row = delicat_ref_row_for( $uid );
	if ( ! $row || ! in_array( $row->status, array( 'signed_up', 'review' ), true ) || (int) $row->order_id ) return;
	$joined = strtotime( $row->created_at . ' UTC' );
	$placed = $order->get_date_created();
	if ( $placed && $placed->getTimestamp() < $joined ) return; // ordered before the invitation (a code typed afterwards, a guest order)
	$paid = $order->get_date_paid() ?: $placed;
	if ( ( $paid ? $paid->getTimestamp() : time() ) > $joined + max( 1, (int) delicat_ref_cfg( 'order_days' ) ) * DAY_IN_SECONDS ) {
		$wpdb->update( delicat_ref_table( 'referrals' ), array( 'status' => 'rejected', 'reason' => 'too_late', 'decided_at' => current_time( 'mysql', 1 ) ), array( 'id' => (int) $row->id ) );
		return;
	}
	if ( ! delicat_ref_order_counts( $order ) ) return; // a later, real order can still qualify within the window
	$phone = delicat_ref_norm_phone( $order->get_billing_phone() );
	if ( '' !== $phone ) delicat_ref_signal( $uid, 'phone', delicat_ref_hash( 'phone', $phone ) );
	$now = time();
	$wpdb->update( delicat_ref_table( 'referrals' ), array(
		'order_id' => (int) $order->get_id(), 'qualified_at' => gmdate( 'Y-m-d H:i:s', $now ),
		'confirm_after' => gmdate( 'Y-m-d H:i:s', $now + max( 0, (int) delicat_ref_cfg( 'hold_days' ) ) * DAY_IN_SECONDS ),
	), array( 'id' => (int) $row->id ) );
	if ( 'review' === $row->status ) return; // the team decides; the order is on record
	if ( 'admin_ok' === $row->reason ) { // accepted by the team: no automatic check overturns it
		$wpdb->update( delicat_ref_table( 'referrals' ), array( 'status' => 'qualified' ), array( 'id' => (int) $row->id ) );
		return;
	}
	// The payment phone is known now: judge again.
	delicat_ref_apply( (int) $row->id, (int) $row->referrer_id, $uid, 'qualified', 'signed_up' );
}, 20 );

/* A refund or cancellation of the qualifying order cancels the invitation (or takes the point back). */
function delicat_ref_order_reversed( $order_id ) {
	global $wpdb;
	// A partial refund that leaves a real first purchase (at least the minimum) keeps the invitation.
	if ( 'woocommerce_order_partially_refunded' === current_action() && delicat_ref_order_counts( wc_get_order( $order_id ) ) ) return;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,status FROM ' . delicat_ref_table( 'referrals' ) . " WHERE order_id=%d AND status IN ('qualified','confirmed','review')", (int) $order_id ) );
	foreach ( (array) $rows as $r ) {
		$wpdb->update( delicat_ref_table( 'referrals' ), array( 'status' => 'rejected', 'reason' => 'confirmed' === $r->status ? 'refunded_after' : 'refunded', 'decided_at' => current_time( 'mysql', 1 ) ), array( 'id' => (int) $r->id, 'status' => $r->status ) );
	}
}
foreach ( array( 'woocommerce_order_status_refunded', 'woocommerce_order_status_cancelled', 'woocommerce_order_status_failed', 'woocommerce_order_partially_refunded' ) as $delicat_ref_hook ) {
	add_action( $delicat_ref_hook, 'delicat_ref_order_reversed', 20 );
}

/** Invitations whose holding period is over become points (judged one last time). */
function delicat_ref_confirm_due( $referrer_id = 0, $limit = 50 ) {
	global $wpdb;
	$t = delicat_ref_table( 'referrals' );
	$sql = "SELECT * FROM $t WHERE status='qualified' AND confirm_after<=%s" . ( $referrer_id ? ' AND referrer_id=%d' : '' ) . ' ORDER BY id LIMIT %d';
	$args = $referrer_id ? array( current_time( 'mysql', 1 ), (int) $referrer_id, (int) $limit ) : array( current_time( 'mysql', 1 ), (int) $limit );
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$order = wc_get_order( (int) $row->order_id );
		if ( ! delicat_ref_order_counts( $order ) ) {
			$wpdb->update( $t, array( 'status' => 'rejected', 'reason' => 'order_changed', 'decided_at' => current_time( 'mysql', 1 ) ), array( 'id' => (int) $row->id, 'status' => 'qualified' ) );
			continue;
		}
		if ( 'admin_ok' === $row->reason ) {
			$wpdb->update( $t, array( 'status' => 'confirmed', 'decided_at' => current_time( 'mysql', 1 ) ), array( 'id' => (int) $row->id, 'status' => 'qualified' ) );
			continue;
		}
		$status = delicat_ref_apply( (int) $row->id, (int) $row->referrer_id, (int) $row->referred_id, 'confirmed', 'qualified' );
		if ( 'confirmed' === $status ) $wpdb->update( $t, array( 'decided_at' => current_time( 'mysql', 1 ) ), array( 'id' => (int) $row->id, 'status' => 'confirmed' ) );
	}
}
add_action( 'wp_scheduled_delete', function () { if ( delicat_ref_on() ) delicat_ref_confirm_due( 0, 500 ); } );

function delicat_ref_points( $user_id ) {
	global $wpdb;
	$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . delicat_ref_table( 'referrals' ) . " WHERE referrer_id=%d AND status='confirmed'", (int) $user_id ) );
	return min( min( DELICAT_REF_MAX_POINTS, max( 1, (int) delicat_ref_cfg( 'max_points' ) ) ), $n );
}

/* ============================================================== state, code entry, claims */

function delicat_ref_can_enter_code( $user_id ) {
	$u = get_userdata( (int) $user_id );
	if ( ! $u || delicat_ref_row_for( $user_id ) || delicat_ref_blocked( $user_id ) ) return false;
	if ( strtotime( $u->user_registered . ' UTC' ) + max( 1, (int) delicat_ref_cfg( 'code_days' ) ) * DAY_IN_SECONDS < time() ) return false;
	// Not after a first real purchase: the invitation must come first.
	foreach ( wc_get_orders( array( 'customer_id' => (int) $user_id, 'status' => array( 'completed', 'processing', 'on-hold', 'refunded' ), 'limit' => 20 ) ) as $o ) { // on-hold: paid by MonCash/NatCash, awaiting the team
		if ( ! $o->get_meta( '_delicat_reward' ) ) return false;
	}
	return true;
}

function delicat_ref_state( $user_id ) {
	global $wpdb;
	$user_id = (int) $user_id;
	delicat_ref_confirm_due( $user_id, 20 );
	$points  = delicat_ref_points( $user_id );
	$claims  = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT milestone,choice,amount,status,ref_id FROM ' . delicat_ref_table( 'claims' ) . ' WHERE user_id=%d', $user_id ) ) as $c ) $claims[ (int) $c->milestone ] = $c;
	$blocked = delicat_ref_blocked( $user_id );
	$miles = array();
	foreach ( delicat_ref_milestones() as $m ) {
		$c = $claims[ $m['points'] ] ?? null;
		$miles[] = array(
			'points'   => $m['points'], 'diamonds' => $m['diamonds'], 'htg' => $m['htg'],
			'status'   => $c ? ( 'done' === $c->status ? 'claimed' : 'pending' ) : ( $points >= $m['points'] && ! $blocked ? 'claimable' : 'locked' ),
			'claimed'  => $c ? array( 'choice' => (string) $c->choice, 'amount' => (int) $c->amount, 'order' => 'diamonds' === $c->choice ? (int) $c->ref_id : 0 ) : null, // own reward order: « Voir mon PIN »
		);
	}
	$labels = array( 'signed_up' => 'Inscrit — attend son premier achat', 'qualified' => 'Achat validé — point en attente', 'review' => 'En vérification', 'confirmed' => 'Point gagné', 'rejected' => 'Non retenu' );
	$friends = array(); $counts = array( 'signed_up' => 0, 'qualified' => 0, 'review' => 0, 'confirmed' => 0, 'rejected' => 0 );
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) n FROM ' . delicat_ref_table( 'referrals' ) . ' WHERE referrer_id=%d GROUP BY status', $user_id ) ) as $c ) {
		if ( isset( $counts[ $c->status ] ) ) $counts[ $c->status ] = (int) $c->n; // every invitation, not only the 100 listed
	}
	$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT referred_id,status,reason,created_at FROM ' . delicat_ref_table( 'referrals' ) . ' WHERE referrer_id=%d ORDER BY id DESC LIMIT 100', $user_id ) );
	if ( function_exists( 'cache_users' ) ) cache_users( array_slice( array_map( 'intval', wp_list_pluck( $rows, 'referred_id' ) ), 0, 50 ) ); // one query for the names below
	foreach ( $rows as $r ) {
		if ( count( $friends ) >= 50 ) continue;
		// Detection details stay with the team: the customer sees a refund or « programme rules ».
		$why = 'rejected' === $r->status ? ( in_array( $r->reason, array( 'refunded', 'refunded_after', 'order_changed' ), true ) ? 'Achat remboursé ou annulé' : ( 'too_late' === $r->reason ? 'Pas d’achat dans les délais' : 'Ne respecte pas les règles du programme' ) ) : '';
		$friends[] = array( 'name' => delicat_display_name( (int) $r->referred_id, true ), 'date' => gmdate( 'c', (int) strtotime( $r->created_at . ' UTC' ) ), 'status' => $r->status, 'label' => $labels[ $r->status ] ?? $r->status, 'why' => $why );
	}
	$mine = delicat_ref_row_for( $user_id );
	$code = $blocked ? '' : delicat_ref_code( $user_id );
	return array(
		'enabled'      => delicat_ref_on(),
		'blocked'      => $blocked,
		'code'         => $code,
		'link'         => $code ? delicat_ref_link( $code ) : '', // '' without a public Bonus Free Fire page
		'points'       => $points,
		'max'          => min( DELICAT_REF_MAX_POINTS, max( 1, (int) delicat_ref_cfg( 'max_points' ) ) ),
		'milestones'   => $miles,
		'friends'      => $friends,
		'counts'       => $counts,
		'invitedBy'    => $mine ? array( 'name' => delicat_display_name( (int) $mine->referrer_id, true ), 'status' => $mine->status ) : null,
		'canEnterCode' => delicat_ref_can_enter_code( $user_id ),
		'rules'        => array( 'minOrder' => (int) delicat_ref_cfg( 'min_order' ), 'holdDays' => (int) delicat_ref_cfg( 'hold_days' ), 'orderDays' => (int) delicat_ref_cfg( 'order_days' ), 'walletExcluded' => (bool) delicat_ref_cfg( 'exclude_wallet' ) ),
		'wallet'       => function_exists( 'woo_wallet' ),
		'shareText'    => $code && delicat_ref_link( $code ) ? delicat_ref_share_text( $code ) : '',
	);
}

/** The invitation message, with the personal link and code filled in. Plain text, never HTML. */
function delicat_ref_share_text( $code ) {
	$text = trim( (string) delicat_ref_cfg( 'share_text' ) );
	if ( '' === $text ) $text = delicat_ref_defaults()['share_text'];
	$link = delicat_ref_link( $code );
	if ( false === strpos( $text, '{lien}' ) ) $text .= ' {lien}'; // the link is the point of the message
	return strtr( $text, array( '{lien}' => $link, '{code}' => $code ) );
}

function delicat_ref_enter_code( $user_id, $code, $fp = '' ) {
	if ( ! delicat_ref_on() ) return new WP_Error( 'off', 'Le parrainage est fermé pour le moment.', array( 'status' => 503 ) );
	if ( ! delicat_ref_rate( 'delicat_rc_' . (int) $user_id, 5, HOUR_IN_SECONDS ) ) return new WP_Error( 'rate', 'Trop d’essais. Réessayez dans une heure.', array( 'status' => 429 ) );
	if ( ! delicat_ref_can_enter_code( $user_id ) ) return new WP_Error( 'not_allowed', 'Le code d’un parrain se saisit dans les premiers jours, avant votre premier achat.', array( 'status' => 403 ) );
	$inviter = delicat_ref_user_by_code( $code );
	if ( ! $inviter || delicat_ref_blocked( $inviter ) ) return new WP_Error( 'invalid', 'Code de parrainage introuvable.', array( 'status' => 404 ) );
	if ( 'off' !== delicat_ref_cfg( 'vpn' ) && delicat_ref_is_vpn() ) return new WP_Error( 'vpn', 'VPN ou proxy détecté : désactivez-le pour utiliser un code.', array( 'status' => 403 ) );
	$r = delicat_ref_attach( (int) $user_id, $inviter, 'code', $fp );
	if ( is_wp_error( $r ) ) { $r->add_data( array( 'status' => 409 ) ); return $r; }
	return delicat_ref_state( $user_id );
}

function delicat_ref_claim( $user_id, $points, $choice ) {
	global $wpdb;
	$user_id = (int) $user_id; $points = (int) $points;
	if ( ! delicat_ref_on() ) return new WP_Error( 'off', 'Le parrainage est fermé pour le moment.', array( 'status' => 503 ) );
	if ( delicat_ref_blocked( $user_id ) ) return new WP_Error( 'blocked', 'Votre accès au parrainage est suspendu. Contactez le support.', array( 'status' => 403 ) );
	if ( function_exists( 'delicat_rate_ok' ) && ! delicat_rate_ok( $user_id ) ) return new WP_Error( 'slow_down', 'Trop de demandes. Réessayez plus tard.', array( 'status' => 429 ) );
	$milestone = null;
	foreach ( delicat_ref_milestones() as $m ) if ( $m['points'] === $points ) $milestone = $m;
	if ( ! $milestone ) return new WP_Error( 'bad', 'Palier inconnu.', array( 'status' => 400 ) );
	$choice = 'htg' === $choice ? 'htg' : 'diamonds';
	$amount = (int) $milestone[ $choice ];
	if ( $amount < 1 ) return new WP_Error( 'bad', 'Cette récompense n’est pas proposée pour ce palier.', array( 'status' => 400 ) );
	if ( 'htg' === $choice && ! function_exists( 'woo_wallet' ) ) return new WP_Error( 'wallet', 'Le crédit boutique est indisponible pour le moment.', array( 'status' => 503 ) );
	if ( 'off' !== delicat_ref_cfg( 'vpn' ) && delicat_ref_is_vpn() ) return new WP_Error( 'vpn', 'VPN ou proxy détecté : désactivez-le pour réclamer.', array( 'status' => 403 ) );
	$lock = 'delicat_ref_claim_' . $user_id;
	if ( ! delicat_lock( $lock, 5 ) ) return new WP_Error( 'busy', 'Réclamation en cours, réessayez.', array( 'status' => 429 ) );
	try {
		delicat_ref_confirm_due( $user_id, 20 );
		if ( delicat_ref_blocked( $user_id ) ) return new WP_Error( 'blocked', 'Votre accès au parrainage est suspendu. Contactez le support.', array( 'status' => 403 ) ); // the judgement just above may have blocked it
		if ( delicat_ref_points( $user_id ) < $points ) return new WP_Error( 'not_ready', 'Pas encore assez de points pour ce palier.', array( 'status' => 400 ) );
		// One claim per milestone, enforced by the database (unique user + milestone).
		$t = delicat_ref_table( 'claims' );
		$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (user_id,milestone,choice,amount,status,created_at) VALUES (%d,%d,%s,%d,'pending',%s)", $user_id, $points, $choice, $amount, current_time( 'mysql', 1 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $ok ) return new WP_Error( 'claimed', 'Ce palier a déjà été réclamé.', array( 'status' => 409 ) );
		$claim_id = (int) $wpdb->insert_id;
		$ref = 0; $delivery = null; $broken = false; $note = sprintf( 'Parrainage Delicat — palier %d points', $points );
		try {
			if ( 'diamonds' === $choice ) {
				$plan = delicat_reward_plan( $amount, true );
				if ( $plan ) $ref = (int) delicat_create_reward_order( $user_id, $plan, $note . ' — ' . $amount . ' diamants offerts.', 'referral_' . $points, array(
					'delicat_reward_kind' => 'referral', 'delicat_reward_amount' => $amount, 'delicat_referral_milestone' => $points,
				) );
			} else {
				$ref = (int) woo_wallet()->wallet->credit( $user_id, $amount, $note );
			}
		} catch ( \Throwable $e ) {
			$broken = true; // the reward may have been given before the error: the milestone stays reserved
			delicat_log( 'error', 'Referral reward interrupted; check the customer before releasing the milestone.', array( 'user_id' => $user_id, 'milestone' => $points, 'choice' => $choice ) );
		}
		if ( ! $ref && $broken ) {
			delicat_ref_alert_admin_claim( $user_id, $points );
			return new WP_Error( 'issue_check', 'Votre récompense est en vérification par l’équipe. Elle vous sera confirmée rapidement.', array( 'status' => 503 ) );
		}
		if ( ! $ref ) {
			$wpdb->delete( $t, array( 'id' => $claim_id, 'status' => 'pending' ) ); // nothing delivered: the milestone stays claimable
			return new WP_Error( 'issue_failed', 'diamonds' === $choice ? 'Stock de PIN indisponible pour le moment. Réessayez plus tard ou choisissez le crédit boutique.' : 'Le crédit boutique n’a pas pu être ajouté. Réessayez plus tard.', array( 'status' => 503 ) );
		}
		$wpdb->update( $t, array( 'status' => 'done', 'ref_id' => $ref ), array( 'id' => $claim_id ) );
		if ( 'diamonds' === $choice && function_exists( 'delicat_reward_delivery' ) ) {
			$d = delicat_reward_delivery( $ref, $user_id );
			$delivery = is_wp_error( $d ) ? null : $d;
		}
		do_action( 'delicat_referral_claimed', $user_id, $points, $choice, $amount, $ref );
		$state = delicat_ref_state( $user_id );
		$state['claimed'] = array( 'points' => $points, 'choice' => $choice, 'amount' => $amount, 'delivery' => $delivery );
		return $state;
	} finally {
		delicat_unlock( $lock );
	}
}

/* ============================================================== REST + AJAX */

add_action( 'rest_api_init', function () {
	$logged_in = function () {
		if ( ! is_user_logged_in() || ! get_userdata( get_current_user_id() ) ) return new WP_Error( 'auth', 'Connectez-vous.', array( 'status' => 401 ) );
		return true;
	};
	register_rest_route( 'delicat/v1', '/referral', array(
		'methods' => 'GET', 'permission_callback' => $logged_in,
		'callback' => function () {
			if ( ! delicat_ref_on() ) return delicat_private_response( array( 'enabled' => false ) );
			return delicat_private_response( delicat_ref_state( get_current_user_id() ) );
		},
	) );
	register_rest_route( 'delicat/v1', '/referral/claim', array(
		'methods' => 'POST', 'permission_callback' => $logged_in,
		'args' => array(
			'milestone' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1, 'maximum' => DELICAT_REF_MAX_POINTS ),
			'choice'    => array( 'required' => true, 'type' => 'string', 'enum' => array( 'diamonds', 'htg' ) ),
		),
		'callback' => function ( WP_REST_Request $req ) {
			$r = delicat_ref_claim( get_current_user_id(), (int) $req['milestone'], (string) $req['choice'] );
			return is_wp_error( $r ) ? $r : delicat_private_response( $r );
		},
	) );
	register_rest_route( 'delicat/v1', '/referral/code', array(
		'methods' => 'POST', 'permission_callback' => $logged_in,
		'args' => array(
			'code' => array( 'required' => true, 'type' => 'string', 'maxLength' => 20 ),
			'fp'   => array( 'required' => false, 'type' => 'string', 'maxLength' => 64 ),
		),
		'callback' => function ( WP_REST_Request $req ) {
			$r = delicat_ref_enter_code( get_current_user_id(), (string) $req['code'], (string) $req['fp'] );
			return is_wp_error( $r ) ? $r : delicat_private_response( $r );
		},
	) );
} );

/* The page reports the browser it runs in (a few times an hour at most), so active customers' devices are known. */
add_action( 'wp_ajax_delicat_ref_seen', function () {
	nocache_headers();
	if ( ! delicat_ref_on() || ! check_ajax_referer( 'wp_rest', 'nonce', false ) ) wp_send_json( array( 'ok' => false ), 403 );
	$uid = get_current_user_id();
	if ( ! delicat_ref_rate( 'delicat_rs_' . $uid, 6, HOUR_IN_SECONDS ) ) wp_send_json( array( 'ok' => true ) );
	delicat_ref_capture( $uid, isset( $_POST['fp'] ) ? (string) wp_unslash( $_POST['fp'] ) : '', true );
	wp_send_json( array( 'ok' => true ) );
} );

/* Account deletion takes the customer's signals, claims and invitations with it. */
add_action( 'delete_user', function ( $user_id ) {
	global $wpdb;
	$user_id = (int) $user_id;
	// A blocked account's hashed signals stay: the same phone or e-mail cannot come back clean under a new account.
	if ( ! delicat_ref_blocked( $user_id ) ) $wpdb->delete( delicat_ref_table( 'signals' ), array( 'user_id' => $user_id ) );
	$wpdb->delete( delicat_ref_table( 'claims' ), array( 'user_id' => $user_id ) );
	$wpdb->delete( delicat_ref_table( 'referrals' ), array( 'referred_id' => $user_id ) );
	$wpdb->delete( delicat_ref_table( 'referrals' ), array( 'referrer_id' => $user_id ) );
} );
