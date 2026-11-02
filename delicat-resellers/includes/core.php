<?php
/**
 * Delicat Resellers — engine: levels, applications, qualifying deposits, wallet-to-wallet refusal,
 * activation, monthly minimum (move down a level, or leave below the lowest), reminders, e-mails.
 *
 * Life of an application: pending (sent) → deposit (approved by the team: the customer has N days to
 * deposit the level's minimum through the recharge system) → active (badge, reseller prices).
 * Endings: rejected (team), declined (a wallet-to-wallet transfer reached the account while the deposit
 * was awaited), expired (no full deposit in time), cancelled (by the customer).
 */
defined( 'ABSPATH' ) || exit;

/* ============================================================== storage */

function dlr_table( $which ) {
	global $wpdb;
	return $wpdb->prefix . ( 'apps' === $which ? 'delicat_reseller_apps' : 'delicat_reseller_log' );
}

function dlr_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta( 'CREATE TABLE ' . dlr_table( 'apps' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  tier varchar(10) NOT NULL,
  status varchar(12) NOT NULL DEFAULT 'pending',
  business varchar(120) NOT NULL DEFAULT '',
  phone varchar(30) NOT NULL DEFAULT '',
  city varchar(80) NOT NULL DEFAULT '',
  channel text NULL,
  volume varchar(20) NOT NULL DEFAULT '',
  note_admin text NULL,
  reason varchar(40) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  decided_at datetime NULL,
  deadline datetime NULL,
  activated_at datetime NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY status (status)
) $c;" );
	dbDelta( 'CREATE TABLE ' . dlr_table( 'log' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  event varchar(20) NOT NULL,
  tier_from varchar(10) NOT NULL DEFAULT '',
  tier_to varchar(10) NOT NULL DEFAULT '',
  amount decimal(14,2) NOT NULL DEFAULT 0.00,
  note varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY created_at (created_at)
) $c;" );
	foreach ( dlr_tier_keys() as $t ) {
		if ( ! get_role( 'dlr_' . $t ) ) add_role( 'dlr_' . $t, 'Revendeur ' . ucfirst( $t ), array( 'read' => true ) );
	}
	if ( ! wp_next_scheduled( 'dlr_daily' ) ) wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dlr_daily' );
	update_option( 'dlr_db', DLR_DB, false );
}
add_action( 'plugins_loaded', function () {
	if ( (int) get_option( 'dlr_db' ) < DLR_DB ) dlr_install();
} );

/* ============================================================== settings */

/** Levels from the highest to the lowest. */
function dlr_tier_keys() {
	return array( 'premium', 'gold', 'silver' );
}
function dlr_defaults() {
	return array(
		'enabled'          => 0,   // closed until the owner has set the prices
		'tiers'            => array(
			'premium' => array( 'label' => 'Premium Reseller', 'min' => 100000, 'discount' => 0 ),
			'gold'    => array( 'label' => 'Golden Reseller', 'min' => 50000, 'discount' => 0 ),
			'silver'  => array( 'label' => 'Silver Reseller', 'min' => 25000, 'discount' => 0 ),
		),
		'deposit_days'     => 7,   // after the team's approval, to deposit the level's minimum
		'reminder_day'     => 22,  // from this day of the month, a reseller below the minimum is reminded once
		'page_id'          => 0,   // the reseller page (shortcode [delicat_revendeur]); 0 = found automatically
		'recharge_page_id' => 0,   // the recharge page (shortcode [delicat_recharge]); 0 = found automatically
		'notify'           => '',  // e-mail for new applications and changes; '' = the site's admin e-mail
	);
}
function dlr_cfg( $key = null, $reload = false ) {
	static $c = null;
	if ( null === $c || $reload ) {
		$d     = dlr_defaults();
		$saved = get_option( 'dlr_config', array() );
		$c     = wp_parse_args( is_array( $saved ) ? $saved : array(), $d );
		$tiers = array();
		foreach ( dlr_tier_keys() as $t ) {
			$s = isset( $c['tiers'][ $t ] ) && is_array( $c['tiers'][ $t ] ) ? $c['tiers'][ $t ] : array();
			$tiers[ $t ] = array(
				'label'    => '' !== trim( (string) ( $s['label'] ?? '' ) ) ? (string) $s['label'] : $d['tiers'][ $t ]['label'],
				'min'      => max( 1, (int) ( $s['min'] ?? $d['tiers'][ $t ]['min'] ) ),
				'discount' => max( 0, min( 90, (float) ( $s['discount'] ?? 0 ) ) ),
			);
		}
		$c['tiers'] = $tiers;
	}
	return null === $key ? $c : ( $c[ $key ] ?? null );
}
function dlr_on() {
	return 1 === (int) dlr_cfg( 'enabled' ) && function_exists( 'WC' );
}
function dlr_tiers() {
	return dlr_cfg( 'tiers' );
}
function dlr_tier_label( $tier ) {
	$t = dlr_tiers();
	return isset( $t[ $tier ] ) ? (string) $t[ $tier ]['label'] : '';
}
function dlr_tier_min( $tier ) {
	$t = dlr_tiers();
	return isset( $t[ $tier ] ) ? (int) $t[ $tier ]['min'] : 0;
}
function dlr_tier_rank( $tier ) {
	$r = array_flip( array_reverse( dlr_tier_keys() ) ); // silver 0, gold 1, premium 2
	return isset( $r[ $tier ] ) ? $r[ $tier ] + 1 : 0;
}
function dlr_money( $amount ) {
	// French WordPress writes its thousands separator as « &nbsp; »: decoded here, the text can be escaped safely.
	return html_entity_decode( number_format_i18n( (float) $amount, 0 ), ENT_QUOTES, 'UTF-8' ) . "\u{00A0}HTG";
}

/* ============================================================== the reseller's level */

/** The customer's current level ('' = not a reseller). Read once per request. */
function dlr_user_tier( $user_id, $fresh = false ) {
	static $cache = array();
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) return '';
	if ( $fresh || ! isset( $cache[ $user_id ] ) ) {
		$t = (string) get_user_meta( $user_id, '_dlr_tier', true );
		$cache[ $user_id ] = in_array( $t, dlr_tier_keys(), true ) ? $t : '';
	}
	return $cache[ $user_id ];
}
/** The level whose prices apply to this visitor right now ('' when the programme is closed). */
function dlr_current_tier() {
	if ( ! dlr_on() || ! is_user_logged_in() ) return '';
	return dlr_user_tier( get_current_user_id() );
}

/** Changes a customer's level (role + meta + history). $tier '' removes it. */
function dlr_set_tier( $user_id, $tier, $event, $amount = 0, $note = '' ) {
	$user_id = (int) $user_id;
	$from    = dlr_user_tier( $user_id, true );
	$tier    = in_array( $tier, dlr_tier_keys(), true ) ? $tier : '';
	if ( $tier === $from ) return false;
	$user = get_userdata( $user_id );
	if ( ! $user ) return false;
	foreach ( dlr_tier_keys() as $t ) {
		if ( in_array( 'dlr_' . $t, (array) $user->roles, true ) ) $user->remove_role( 'dlr_' . $t );
	}
	if ( '' !== $tier ) {
		if ( ! get_role( 'dlr_' . $tier ) ) add_role( 'dlr_' . $tier, 'Revendeur ' . ucfirst( $tier ), array( 'read' => true ) );
		$user->add_role( 'dlr_' . $tier );
		update_user_meta( $user_id, '_dlr_tier', $tier );
		if ( '' === $from ) update_user_meta( $user_id, '_dlr_since', time() );
	} else {
		delete_user_meta( $user_id, '_dlr_tier' );
		delete_user_meta( $user_id, '_dlr_since' );
		delete_user_meta( $user_id, '_dlr_eval' );
	}
	dlr_user_tier( $user_id, true );
	dlr_log( $user_id, $event, $from, $tier, $amount, $note );
	do_action( 'dlr_tier_changed', $user_id, $from, $tier, $event );
	return true;
}

function dlr_log( $user_id, $event, $from = '', $to = '', $amount = 0, $note = '' ) {
	global $wpdb;
	$wpdb->insert( dlr_table( 'log' ), array(
		'user_id' => (int) $user_id, 'event' => substr( (string) $event, 0, 20 ), 'tier_from' => (string) $from, 'tier_to' => (string) $to,
		'amount' => round( (float) $amount, 2 ), 'note' => substr( (string) $note, 0, 255 ), 'created_at' => current_time( 'mysql', 1 ),
	), array( '%d', '%s', '%s', '%s', '%f', '%s', '%s' ) );
}

/* ============================================================== locks */

/** A short lock shared by every PHP worker (compare-and-set on the options table). */
function dlr_lock( $key, $ttl = 30 ) {
	global $wpdb;
	$name = 'dlr_lock_' . $key;
	$now  = time();
	if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) ( $now + $ttl ) ) ) ) return true;
	$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
	if ( (int) $held >= $now ) return false;
	return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", (string) ( $now + $ttl ), $name, $held ) );
}
function dlr_unlock( $key ) {
	global $wpdb;
	$wpdb->delete( $wpdb->options, array( 'option_name' => 'dlr_lock_' . $key ) );
}

/* ============================================================== months (site time zone) */

function dlr_month_key( $ts = null ) {
	return wp_date( 'Y-m', null === $ts ? time() : (int) $ts );
}
/** [first second, first second of the next month] of a 'Y-m' month in the site's time zone. */
function dlr_month_bounds( $key ) {
	$start = new DateTimeImmutable( $key . '-01 00:00:00', wp_timezone() );
	return array( $start->getTimestamp(), $start->modify( '+1 month' )->getTimestamp() );
}
function dlr_month_shift( $key, $months ) {
	return ( new DateTimeImmutable( $key . '-01 00:00:00', wp_timezone() ) )->modify( ( $months >= 0 ? '+' : '' ) . (int) $months . ' month' )->format( 'Y-m' );
}
function dlr_month_name( $key ) {
	return wp_date( 'F Y', dlr_month_bounds( $key )[0] );
}

/* ============================================================== deposits and transfers */

function dlr_source_table( $name ) {
	global $wpdb;
	static $seen = array();
	$t = $wpdb->prefix . $name;
	if ( ! isset( $seen[ $t ] ) ) $seen[ $t ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
	return $seen[ $t ] ? $t : '';
}
/**
 * Money the customer put in through the recharge system between two moments: approved and credited
 * recharges (MonCash, NatCash, manual), by the time they were sent. A refunded recharge (statut
 * 'rejete') no longer counts. UNIX_TIMESTAMP() reads the column in the database's own time zone,
 * the one CURRENT_TIMESTAMP wrote it in, whatever that zone is.
 */
function dlr_deposits( $user_id, $from_ts, $to_ts = 0 ) {
	global $wpdb;
	$t = dlr_source_table( 'delicat_recharges' );
	if ( '' === $t ) return 0.0;
	$user  = get_userdata( (int) $user_id );
	$email = $user ? (string) $user->user_email : '';
	$to_ts = $to_ts > 0 ? (int) $to_ts : time() + DAY_IN_SECONDS;
	$who   = '' !== $email ? $wpdb->prepare( '(user_id=%d OR (user_id=0 AND user_email=%s))', (int) $user_id, $email ) : $wpdb->prepare( 'user_id=%d', (int) $user_id );
	$tabs  = array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'dlr_deposit_tabs', array( 'moncash', 'natcash', 'natcash_tc', 'manuel' ) ) ) ) );
	if ( ! $tabs ) return 0.0;
	$in    = implode( ',', array_fill( 0, count( $tabs ), '%s' ) );
	$sql   = "SELECT COALESCE(SUM(montant),0) FROM $t WHERE $who AND statut='approuve' AND wallet_credited=1 AND tab IN ($in) AND UNIX_TIMESTAMP(created_at)>=%d AND UNIX_TIMESTAMP(created_at)<%d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (float) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( $tabs, array( (int) $from_ts, $to_ts ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}
/** Completed wallet-to-wallet transfers received since a moment: array( count, total ). */
function dlr_transfers_in( $user_id, $from_ts ) {
	global $wpdb;
	$t = dlr_source_table( 'delicat_transfers' );
	if ( '' === $t ) return array( 0, 0.0 );
	// Send Money writes created_at in the site's local time (current_time('mysql')).
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(amount),0) s FROM $t WHERE recipient_id=%d AND status='completed' AND created_at>=%s", (int) $user_id, wp_date( 'Y-m-d H:i:s', (int) $from_ts ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return array( (int) ( $row->n ?? 0 ), (float) ( $row->s ?? 0 ) );
}

/* ============================================================== applications */

function dlr_app( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dlr_table( 'apps' ) . ' WHERE id=%d', (int) $id ) );
}
/** The customer's application still in progress (sent or awaiting the deposit), if any. */
function dlr_open_app( $user_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dlr_table( 'apps' ) . " WHERE user_id=%d AND status IN ('pending','deposit') ORDER BY id DESC LIMIT 1", (int) $user_id ) );
}
function dlr_last_app( $user_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dlr_table( 'apps' ) . ' WHERE user_id=%d ORDER BY id DESC LIMIT 1', (int) $user_id ) );
}
/** Moves an application on, only from the status it was read in (two requests never both act). */
function dlr_app_move( $app_id, $from, array $data ) {
	global $wpdb;
	return 1 === (int) $wpdb->update( dlr_table( 'apps' ), $data, array( 'id' => (int) $app_id, 'status' => $from ) );
}
function dlr_ts( $mysql_utc ) {
	return $mysql_utc ? (int) strtotime( $mysql_utc . ' UTC' ) : 0;
}

function dlr_volumes() {
	return array( 'lt50' => 'Moins de 50 000 HTG', '50_100' => '50 000 à 100 000 HTG', '100_250' => '100 000 à 250 000 HTG', 'gt250' => 'Plus de 250 000 HTG' );
}

/** A customer's application. Returns the application id or a WP_Error with a message for the customer. */
function dlr_apply( $user_id, array $in ) {
	global $wpdb;
	$user_id = (int) $user_id;
	if ( ! dlr_on() ) return new WP_Error( 'closed', 'Le programme revendeur est fermé pour le moment.' );
	if ( $user_id < 1 || ! get_userdata( $user_id ) ) return new WP_Error( 'auth', 'Connectez-vous pour faire votre demande.' );
	$tier = sanitize_key( (string) ( $in['tier'] ?? '' ) );
	if ( ! in_array( $tier, dlr_tier_keys(), true ) ) return new WP_Error( 'tier', 'Choisissez un niveau.' );
	if ( dlr_tier_rank( $tier ) <= dlr_tier_rank( dlr_user_tier( $user_id ) ) ) return new WP_Error( 'tier', 'Vous avez déjà ce niveau ou un niveau supérieur.' );
	if ( dlr_open_app( $user_id ) ) return new WP_Error( 'open', 'Vous avez déjà une demande en cours.' );
	$k = 'dlr_apply_' . $user_id;
	if ( (int) get_transient( $k ) >= 3 ) return new WP_Error( 'rate', 'Trop de demandes aujourd’hui. Réessayez demain.' );
	$business = trim( sanitize_text_field( (string) ( $in['business'] ?? '' ) ) );
	$phone    = trim( sanitize_text_field( (string) ( $in['phone'] ?? '' ) ) );
	$city     = trim( sanitize_text_field( (string) ( $in['city'] ?? '' ) ) );
	$channel  = trim( sanitize_textarea_field( (string) ( $in['channel'] ?? '' ) ) );
	$volume   = sanitize_key( (string) ( $in['volume'] ?? '' ) );
	$errors   = array();
	if ( mb_strlen( $business ) < 2 || mb_strlen( $business ) > 120 ) $errors[] = 'Indiquez votre nom ou le nom de votre activité.';
	if ( ! preg_match( '/^\+?[0-9 ().-]{8,25}$/', $phone ) || strlen( preg_replace( '/\D+/', '', $phone ) ) < 8 ) $errors[] = 'Indiquez un numéro de téléphone (WhatsApp) valide.';
	if ( mb_strlen( $city ) < 2 || mb_strlen( $city ) > 80 ) $errors[] = 'Indiquez votre ville.';
	if ( mb_strlen( $channel ) < 10 || mb_strlen( $channel ) > 1000 ) $errors[] = 'Expliquez en quelques mots comment vous vendez (10 caractères au moins).';
	if ( ! isset( dlr_volumes()[ $volume ] ) ) $errors[] = 'Choisissez votre volume de vente mensuel.';
	if ( empty( $in['terms'] ) ) $errors[] = 'Acceptez les conditions du programme.';
	if ( $errors ) return new WP_Error( 'fields', implode( ' ', $errors ) );
	set_transient( $k, (int) get_transient( $k ) + 1, DAY_IN_SECONDS );
	if ( ! dlr_lock( 'apply_' . $user_id, 10 ) ) return new WP_Error( 'busy', 'Demande en cours d’envoi.' );
	$id = 0;
	try {
		if ( dlr_open_app( $user_id ) ) return new WP_Error( 'open', 'Vous avez déjà une demande en cours.' );
		$wpdb->insert( dlr_table( 'apps' ), array(
			'user_id' => $user_id, 'tier' => $tier, 'status' => 'pending', 'business' => $business, 'phone' => $phone,
			'city' => $city, 'channel' => $channel, 'volume' => $volume, 'created_at' => current_time( 'mysql', 1 ),
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$id = (int) $wpdb->insert_id;
	} finally {
		dlr_unlock( 'apply_' . $user_id );
	}
	if ( ! $id ) return new WP_Error( 'db', 'La demande n’a pas pu être enregistrée. Réessayez.' );
	dlr_log( $user_id, 'applied', dlr_user_tier( $user_id ), $tier, 0, 'Demande #' . $id );
	dlr_mail_admin( 'Nouvelle demande revendeur — ' . dlr_tier_label( $tier ), sprintf( "%s demande le niveau %s.\nActivité : %s — %s\nTéléphone : %s\n\nÀ examiner : WooCommerce › Delicat Revendeurs.", dlr_user_name( $user_id ), dlr_tier_label( $tier ), $business, $city, $phone ) );
	return $id;
}

/** Step one: the team approves; the customer now has deposit_days days to deposit the level's minimum. */
function dlr_approve( $app_id, $note = '' ) {
	$app = dlr_app( $app_id );
	if ( ! $app || 'pending' !== $app->status ) return new WP_Error( 'state', 'Demande introuvable ou déjà traitée.' );
	$now = time();
	$ok  = dlr_app_move( $app->id, 'pending', array(
		'status' => 'deposit', 'decided_at' => gmdate( 'Y-m-d H:i:s', $now ),
		'deadline' => gmdate( 'Y-m-d H:i:s', $now + max( 1, (int) dlr_cfg( 'deposit_days' ) ) * DAY_IN_SECONDS ),
		'note_admin' => sanitize_textarea_field( (string) $note ),
	) );
	if ( ! $ok ) return new WP_Error( 'state', 'Demande déjà traitée.' );
	$app = dlr_app( $app_id );
	dlr_log( $app->user_id, 'approved', '', $app->tier, 0, 'Demande #' . $app->id );
	dlr_mail_user( $app->user_id, 'Votre demande revendeur est approuvée — dernière étape', sprintf(
		"Bonne nouvelle : votre demande %s est approuvée.\n\nDernière étape : rechargez votre compte d’au moins %s avant le %s, uniquement par le système de recharge du site (MonCash, NatCash…) : %s\n\nImportant : un envoi d’argent d’un autre portefeuille Delicat vers le vôtre pendant cette étape refuse la demande automatiquement.\n\nDès que votre dépôt est validé, votre badge %s et vos prix revendeur sont activés.",
		dlr_tier_label( $app->tier ), dlr_money( dlr_tier_min( $app->tier ) ), wp_date( 'j F Y à H:i', dlr_ts( $app->deadline ) ), dlr_recharge_url(), dlr_tier_label( $app->tier )
	) );
	dlr_check_app( $app ); // a deposit already made after this approval (same minute) counts at once
	return true;
}

function dlr_reject( $app_id, $reason = '' ) {
	$app = dlr_app( $app_id );
	if ( ! $app || ! in_array( $app->status, array( 'pending', 'deposit' ), true ) ) return new WP_Error( 'state', 'Demande introuvable ou déjà traitée.' );
	$reason = sanitize_textarea_field( (string) $reason );
	if ( ! dlr_app_move( $app->id, $app->status, array( 'status' => 'rejected', 'reason' => 'team', 'note_admin' => $reason, 'decided_at' => current_time( 'mysql', 1 ) ) ) ) return new WP_Error( 'state', 'Demande déjà traitée.' );
	dlr_log( $app->user_id, 'rejected', '', $app->tier, 0, $reason );
	dlr_mail_user( $app->user_id, 'Votre demande revendeur', "Votre demande " . dlr_tier_label( $app->tier ) . " n’a pas été retenue." . ( '' !== $reason ? "\n\nMotif : " . $reason : '' ) . "\n\nVous pouvez nous écrire pour en savoir plus." );
	return true;
}

function dlr_cancel( $user_id ) {
	$app = dlr_open_app( $user_id );
	if ( ! $app ) return new WP_Error( 'state', 'Aucune demande en cours.' );
	if ( ! dlr_app_move( $app->id, $app->status, array( 'status' => 'cancelled', 'reason' => 'customer', 'decided_at' => current_time( 'mysql', 1 ) ) ) ) return new WP_Error( 'state', 'Demande déjà traitée.' );
	dlr_log( $user_id, 'cancelled', '', $app->tier, 0, 'Demande #' . $app->id );
	return true;
}

/** Money received from another wallet while the qualifying deposit was awaited: refused at once. */
function dlr_decline_transfer( $app, $count, $total ) {
	if ( ! dlr_app_move( $app->id, 'deposit', array( 'status' => 'declined', 'reason' => 'transfer', 'decided_at' => current_time( 'mysql', 1 ) ) ) ) return false;
	dlr_log( $app->user_id, 'declined', '', $app->tier, $total, sprintf( '%d envoi(s) entre portefeuilles reçu(s)', $count ) );
	dlr_mail_user( $app->user_id, 'Votre demande revendeur est refusée', "Votre compte a reçu de l’argent d’un autre portefeuille Delicat pendant l’étape du dépôt. Le dépôt du programme revendeur doit être fait uniquement par le système de recharge (MonCash, NatCash…).\n\nVotre demande " . dlr_tier_label( $app->tier ) . " est donc refusée. Vous pourrez faire une nouvelle demande en respectant cette règle." );
	dlr_mail_admin( 'Demande revendeur refusée automatiquement', sprintf( "%s a reçu %s par envoi entre portefeuilles pendant l’étape du dépôt (%s) : demande #%d refusée.", dlr_user_name( $app->user_id ), dlr_money( $total ), dlr_tier_label( $app->tier ), $app->id ) );
	return true;
}

/** Looks at an application awaiting its deposit: transfer received, deposit complete, or time over. */
function dlr_check_app( $app ) {
	if ( ! $app || 'deposit' !== $app->status ) return;
	if ( ! dlr_lock( 'app_' . $app->id, 20 ) ) return;
	try {
		$app = dlr_app( $app->id ); // read again under the lock
		if ( ! $app || 'deposit' !== $app->status ) return;
		$since = dlr_ts( $app->decided_at );
		list( $n, $sum_in ) = dlr_transfers_in( $app->user_id, $since );
		if ( $n > 0 ) { dlr_decline_transfer( $app, $n, $sum_in ); return; }
		$paid = dlr_deposits( $app->user_id, $since );
		if ( $paid >= dlr_tier_min( $app->tier ) ) { dlr_activate( $app, $paid ); return; }
		if ( time() > dlr_ts( $app->deadline ) && dlr_app_move( $app->id, 'deposit', array( 'status' => 'expired', 'reason' => 'deadline', 'decided_at' => current_time( 'mysql', 1 ) ) ) ) {
			dlr_log( $app->user_id, 'expired', '', $app->tier, $paid, 'Dépôt incomplet à la date limite' );
			dlr_mail_user( $app->user_id, 'Votre demande revendeur a expiré', sprintf( "Le dépôt de %s n’a pas été complété à temps (reçu : %s). Vous pouvez faire une nouvelle demande quand vous êtes prêt.", dlr_money( dlr_tier_min( $app->tier ) ), dlr_money( $paid ) ) );
		}
	} finally {
		dlr_unlock( 'app_' . $app->id );
	}
}

function dlr_activate( $app, $paid ) {
	if ( ! dlr_app_move( $app->id, 'deposit', array( 'status' => 'active', 'activated_at' => current_time( 'mysql', 1 ) ) ) ) return false;
	$from = dlr_user_tier( $app->user_id, true );
	dlr_set_tier( $app->user_id, $app->tier, '' === $from ? 'activated' : 'upgraded', $paid, 'Demande #' . $app->id );
	update_user_meta( $app->user_id, '_dlr_eval', dlr_month_key() ); // this month is settled by the qualifying deposit
	dlr_mail_user( $app->user_id, 'Bienvenue dans le programme revendeur — ' . dlr_tier_label( $app->tier ), sprintf(
		"Votre dépôt de %s est validé : vous êtes maintenant %s.\n\nVos prix revendeur s’affichent sur le site quand vous êtes connecté, et votre badge est sur votre compte.\n\nPour garder ce niveau : rechargez au moins %s chaque mois par le système de recharge. Sinon, votre niveau baisse le mois suivant (et en dessous de %s, le programme s’arrête).",
		dlr_money( $paid ), dlr_tier_label( $app->tier ), dlr_money( dlr_tier_min( $app->tier ) ), dlr_money( dlr_tier_min( 'silver' ) )
	) );
	dlr_mail_admin( 'Nouveau revendeur — ' . dlr_tier_label( $app->tier ), sprintf( '%s est %s (dépôt de %s).', dlr_user_name( $app->user_id ), dlr_tier_label( $app->tier ), dlr_money( $paid ) ) );
	return true;
}

/* ============================================================== the monthly minimum */

/** The highest level a month's deposits keep, never above the current one ('' = below the lowest minimum). */
function dlr_level_for( $deposited, $current ) {
	foreach ( dlr_tier_keys() as $t ) {
		if ( dlr_tier_rank( $t ) > dlr_tier_rank( $current ) ) continue;
		if ( $deposited >= dlr_tier_min( $t ) ) return $t;
	}
	return '';
}

/** Settles every finished month since the last one settled: keep, move down a level, or leave. */
function dlr_evaluate_user( $user_id ) {
	$user_id = (int) $user_id;
	$tier    = dlr_user_tier( $user_id, true );
	if ( '' === $tier ) return;
	$last = dlr_month_shift( dlr_month_key(), -1 ); // the last finished month
	$done = (string) get_user_meta( $user_id, '_dlr_eval', true );
	if ( ! preg_match( '/^\d{4}-\d{2}$/', $done ) ) { update_user_meta( $user_id, '_dlr_eval', dlr_month_key() ); return; } // set by hand: starts this month
	if ( strcmp( $done, $last ) >= 0 ) return;
	if ( ! dlr_lock( 'eval_' . $user_id, 60 ) ) return;
	try {
		$done = (string) get_user_meta( $user_id, '_dlr_eval', true );
		for ( $i = 0; $i < 24 && strcmp( $done, $last ) < 0; $i++ ) {
			$month = dlr_month_shift( $done, 1 );
			list( $a, $b ) = dlr_month_bounds( $month );
			$paid  = dlr_deposits( $user_id, $a, $b );
			$keep  = dlr_level_for( $paid, $tier );
			$name  = dlr_month_name( $month );
			if ( '' === $keep ) {
				dlr_set_tier( $user_id, '', 'removed', $paid, $month );
				dlr_mail_user( $user_id, 'Votre statut revendeur est arrêté', sprintf( "En %s, vos recharges ont été de %s, sous le minimum de %s du niveau le plus bas. Votre badge et vos prix revendeur sont retirés.\n\nVous pouvez refaire une demande quand vous voulez.", $name, dlr_money( $paid ), dlr_money( dlr_tier_min( 'silver' ) ) ) );
				dlr_mail_admin( 'Revendeur retiré du programme', sprintf( '%s (%s) : %s rechargés en %s.', dlr_user_name( $user_id ), dlr_tier_label( $tier ), dlr_money( $paid ), $name ) );
				return;
			}
			if ( $keep !== $tier ) {
				dlr_set_tier( $user_id, $keep, 'downgraded', $paid, $month );
				dlr_mail_user( $user_id, 'Votre niveau revendeur change : ' . dlr_tier_label( $keep ), sprintf( "En %s, vos recharges ont été de %s, sous le minimum de %s du niveau %s. Votre niveau devient %s.\n\nRechargez au moins %s ce mois-ci pour le garder. Pour remonter, refaites une demande depuis votre espace revendeur.", $name, dlr_money( $paid ), dlr_money( dlr_tier_min( $tier ) ), dlr_tier_label( $tier ), dlr_tier_label( $keep ), dlr_money( dlr_tier_min( $keep ) ) ) );
				$tier = $keep;
			} else {
				dlr_log( $user_id, 'kept', $tier, $tier, $paid, $month );
			}
			$done = $month;
			update_user_meta( $user_id, '_dlr_eval', $done );
		}
	} finally {
		dlr_unlock( 'eval_' . $user_id );
	}
}

/** Once a month, from reminder_day: a reseller still under the minimum is told how much is left. */
function dlr_remind_user( $user_id ) {
	$tier = dlr_user_tier( $user_id );
	$now  = dlr_month_key();
	if ( '' === $tier || (int) wp_date( 'j' ) < (int) dlr_cfg( 'reminder_day' ) || get_user_meta( $user_id, '_dlr_reminded', true ) === $now ) return;
	if ( get_user_meta( $user_id, '_dlr_eval', true ) === $now ) return; // activated this month: already settled
	list( $a, $b ) = dlr_month_bounds( $now );
	$paid = dlr_deposits( $user_id, $a, $b );
	$min  = dlr_tier_min( $tier );
	update_user_meta( $user_id, '_dlr_reminded', $now );
	if ( $paid >= $min ) return;
	dlr_mail_user( $user_id, 'Rappel : votre dépôt revendeur du mois', sprintf( "Ce mois-ci vous avez rechargé %s. Il manque %s pour garder votre niveau %s (minimum %s) avant la fin du mois.\n\nRecharger : %s", dlr_money( $paid ), dlr_money( $min - $paid ), dlr_tier_label( $tier ), dlr_money( $min ), dlr_recharge_url() ) );
}

/** The month so far, for the customer's page and the account badge. */
function dlr_month_progress( $user_id ) {
	$tier = dlr_user_tier( $user_id );
	$now  = dlr_month_key();
	list( $a, $b ) = dlr_month_bounds( $now );
	$paid = dlr_deposits( $user_id, $a, $b );
	return array(
		'tier' => $tier, 'paid' => $paid, 'min' => dlr_tier_min( $tier ), 'end' => $b,
		'settled' => get_user_meta( $user_id, '_dlr_eval', true ) === $now, // activated this month
		'keeps' => '' !== $tier ? dlr_level_for( $paid, $tier ) : '',
	);
}

/* ============================================================== automation */

/* A recharge approved (by the team or automatically): an application awaiting its deposit is looked at now. */
add_action( 'delicat_recharge_approved', function ( $recharge_id ) {
	global $wpdb;
	$t = dlr_source_table( 'delicat_recharges' );
	if ( '' === $t ) return;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT user_id,user_email FROM $t WHERE id=%d", (int) $recharge_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $row ) return;
	$uid = (int) $row->user_id;
	if ( $uid < 1 && $row->user_email ) { $u = get_user_by( 'email', $row->user_email ); $uid = $u ? (int) $u->ID : 0; }
	if ( $uid > 0 ) dlr_check_app( dlr_open_app( $uid ) );
}, 20 );

/* A wallet-to-wallet transfer received while the qualifying deposit is awaited: refused at once. */
add_action( 'delicat_transfer_completed', function ( $row_id, $sender, $recipient, $amount ) {
	$app = dlr_open_app( (int) $recipient );
	if ( $app && 'deposit' === $app->status ) dlr_decline_transfer( $app, 1, (float) $amount );
}, 20, 4 );

/* Daily: deadlines, missed transfers, finished months, reminders. */
add_action( 'dlr_daily', function () {
	global $wpdb;
	foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . dlr_table( 'apps' ) . " WHERE status='deposit' ORDER BY id LIMIT 500" ) as $app ) dlr_check_app( $app ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! dlr_on() ) return;
	foreach ( get_users( array( 'meta_key' => '_dlr_tier', 'fields' => 'ID', 'number' => 2000 ) ) as $uid ) {
		dlr_evaluate_user( (int) $uid );
		dlr_remind_user( (int) $uid );
	}
} );
/* Also when the reseller is on the site (a quiet site may skip a day of WP-Cron): one meta read. */
add_action( 'init', function () {
	if ( ! is_user_logged_in() || ! dlr_on() ) return;
	$uid = get_current_user_id();
	if ( '' === dlr_user_tier( $uid ) ) return;
	$done = (string) get_user_meta( $uid, '_dlr_eval', true );
	if ( strcmp( $done, dlr_month_shift( dlr_month_key(), -1 ) ) < 0 ) dlr_evaluate_user( $uid );
}, 20 );

/* Account deleted: its applications and history go with it. */
add_action( 'delete_user', function ( $user_id ) {
	global $wpdb;
	$wpdb->delete( dlr_table( 'apps' ), array( 'user_id' => (int) $user_id ) );
	$wpdb->delete( dlr_table( 'log' ), array( 'user_id' => (int) $user_id ) );
} );

/* ============================================================== e-mail, names, pages */

function dlr_user_name( $user_id ) {
	$u = get_userdata( (int) $user_id );
	return $u ? $u->display_name . ' (#' . (int) $user_id . ', ' . $u->user_email . ')' : '#' . (int) $user_id;
}
function dlr_mail_user( $user_id, $subject, $body ) {
	$u = get_userdata( (int) $user_id );
	if ( ! $u || ! is_email( $u->user_email ) ) return;
	wp_mail( $u->user_email, 'Delicat Store — ' . $subject, $body . "\n\n— L’équipe Delicat Store\n" . dlr_page_url() );
}
function dlr_mail_admin( $subject, $body ) {
	$to = (string) dlr_cfg( 'notify' );
	if ( ! is_email( $to ) ) $to = (string) get_option( 'admin_email' );
	wp_mail( $to, 'Delicat — Revendeurs : ' . $subject, $body );
}
/** First public page holding a shortcode, looked up once a day (again when a page is saved). */
function dlr_page_with( $shortcode, $chosen ) {
	$chosen = (int) $chosen;
	if ( $chosen > 0 && 'publish' === get_post_status( $chosen ) ) return $chosen;
	$key   = 'dlr_page_' . md5( $shortcode );
	$found = get_transient( $key );
	if ( false === $found ) {
		global $wpdb;
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_password='' AND post_content LIKE %s ORDER BY ID LIMIT 1", '%' . $wpdb->esc_like( '[' . $shortcode ) . '%' ) );
		set_transient( $key, $found, DAY_IN_SECONDS );
	}
	return (int) $found;
}
add_action( 'save_post_page', function () {
	delete_transient( 'dlr_page_' . md5( 'delicat_revendeur' ) );
	delete_transient( 'dlr_page_' . md5( 'delicat_recharge' ) );
} );
function dlr_page_url() {
	$id = dlr_page_with( 'delicat_revendeur', dlr_cfg( 'page_id' ) );
	return $id ? (string) get_permalink( $id ) : home_url( '/' );
}
function dlr_recharge_url() {
	$id = dlr_page_with( 'delicat_recharge', dlr_cfg( 'recharge_page_id' ) );
	return $id ? (string) get_permalink( $id ) : home_url( '/' );
}
