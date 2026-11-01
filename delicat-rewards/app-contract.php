<?php
/**
 * App contract (3.2.3): the functions the Delicat App API wraps for the native app.
 *
 * The website runs both programmes on cookie + nonce routes under delicat/v1, which the
 * app's token cannot use. The App API (2.9, delicat-app/v1/loyalty/*) calls only these
 * plain functions, guarded by DELICAT_REWARDS_APP_CONTRACT, so it never depends on this
 * plugin's internals and a missing contract (live 3.2.2) simply means "not supported".
 *
 * Rules kept here, whatever the caller:
 *   • Claims, PINs and the customer's own line are for get_current_user_id() only: the
 *     App API has already made the app's customer the current user. A claim token is
 *     handed only to the customer it was signed for.
 *   • The claim is the website's own (delicat_claim_for_current_user): same locks, rate
 *     limits, signed ledger, signed token and exact-retry lookup. The plugin's 401 'auth'
 *     becomes 403 'loyalty_auth': the app signs its customer out on any 401.
 *   • Board names are always masked (« Jean R. » or « Client xxxxxx »), whatever the
 *     website's « Masquer les noms » setting, and never carry a login or e-mail part. No
 *     customer id, e-mail, spend or order count of anyone leaves the server.
 *   • Money and diamonds arrive already formatted in French; dates are ISO 8601 in the
 *     site's time zone with its offset ("2026-09-24T10:00:00-04:00").
 */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DELICAT_REWARDS_APP_CONTRACT' ) ) define( 'DELICAT_REWARDS_APP_CONTRACT', 1 );

/**
 * Which programmes the app may show natively: ['rewards', 'rewards_paused', 'meilleur_client',
 * 'board_public']. Both follow the wp-admin on/off switches; nothing is served without
 * WooCommerce. 'rewards_paused': the Bonus programme is switched off (paused). The app still
 * reads « Mes bonus » then, as the website shows its pause: the state says paused, purchases
 * keep counting, and the claim answers 503 paused.
 */
function delicat_rewards_app_flags() {
	$woo   = function_exists( 'wc_get_orders' );
	$board = $woo && delicat_meilleur_on();
	return array(
		'rewards'         => $woo && delicat_rewards_on(),
		'rewards_paused'  => $woo && ! delicat_rewards_on(),
		'meilleur_client' => $board,
		'board_public'    => $board && (int) delicat_cfg( 'board_public' ) === 1,
	);
}

/** « Mes bonus » of one customer (contract C1). A claim token only ever goes to its own customer. */
function delicat_rewards_app_rewards( $user_id ) {
	return delicat_rewards_app_state( (int) $user_id );
}

/**
 * The rewards shape, from the cached state or, right after a claim, from the state the
 * claim just computed (no second scan of the month's orders).
 */
function delicat_rewards_app_state( $user_id, $state = null ) {
	$user_id = (int) $user_id;
	$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
	$on      = delicat_rewards_on();
	$start   = delicat_month_start();
	$next    = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( wp_timezone() )->modify( '+1 month' )->getTimestamp();
	$cap     = max( 0, min( DELICAT_MAX_MONTH_CAP, (int) delicat_cfg( 'monthly_cap' ) ) );
	$money   = trim( (string) delicat_cfg( 'currency' ) );
	$out = array(
		'enabled'     => false,
		'paused'      => ! $on,
		'dry'         => (int) delicat_cfg( 'dry_run' ) === 1,
		'frozen'      => false,
		'excluded'    => $user && delicat_is_excluded( $user_id ),
		'program'     => array(
			'name'       => (string) delicat_cfg( 'program_name' ),
			'note'       => (string) delicat_cfg( 'note' ),
			'redeem_url' => delicat_rewards_app_redeem_url(),
			'web_url'    => '/rewards-program/',
			'rules_url'  => delicat_rewards_app_rules_url( 'rewards' ),
		),
		'month'       => array(
			'key'         => wp_date( 'Y-m', $start, wp_timezone() ),
			'label'       => delicat_rewards_app_month_label( $start ),
			'reset_at'    => delicat_rewards_app_iso( $next ),
			'server_time' => delicat_rewards_app_iso( time() ),
		),
		'summary'     => array(
			'month_spend'  => trim( delicat_rewards_app_number( 0 ) . ' ' . $money ),
			'month_reward' => delicat_rewards_app_diamonds( 0 ),
			'monthly_cap'  => $cap, // free diamonds per customer and month, 0 = no cap
			'ready'        => 0,
		),
		'tiers'       => array(),
		'how_to_earn' => delicat_rewards_app_how_to_earn( $cap ),
	);
	if ( ! $user ) return $out;
	if ( ! is_array( $state ) ) $state = delicat_user_state_cached( $user_id );
	if ( ! is_array( $state ) ) return $out;

	$out['enabled'] = $on;
	$out['dry']     = ! empty( $state['dry'] );
	$out['frozen']  = ! empty( $state['frozen'] );
	if ( ! empty( $state['resetAt'] ) ) $out['month']['reset_at'] = delicat_rewards_app_iso( (int) $state['resetAt'] );
	$out['summary']['month_spend']  = trim( delicat_rewards_app_number( (float) ( $state['monthSpend'] ?? 0 ), 2 ) . ' ' . $money );
	$out['summary']['month_reward'] = delicat_rewards_app_diamonds( (int) ( $state['monthReward'] ?? 0 ) );

	$own = $user_id === (int) get_current_user_id();
	foreach ( delicat_tiers() as $key => $t ) {
		$row = $state['tiers'][ $key ] ?? null;
		if ( ! is_array( $row ) ) continue;
		$goal      = max( 1, (int) ( $row['goal'] ?? $t['goal'] ?? 1 ) );
		$progress  = max( 0, (int) ( $row['progress'] ?? 0 ) );
		$blocked   = (string) ( $row['blocked'] ?? '' );
		$claimable = ! empty( $row['claimable'] ) && $on && ! $out['excluded'];
		/* What the claim itself would answer first, for a goal that is reached (or already
		   blocked): pause, then test mode, then the account's exclusion. */
		if ( $progress >= $goal || '' !== $blocked ) {
			if ( ! $on ) $blocked = 'paused';
			elseif ( $out['dry'] && $progress >= $goal ) $blocked = 'dry';
			elseif ( $out['excluded'] ) $blocked = 'excluded';
		}
		if ( $claimable ) $out['summary']['ready']++;
		$pin = (int) ( $t['pin'] ?? 0 );
		$sub = (string) ( $t['sub'] ?? '' );
		$out['tiers'][] = array(
			'key'         => (string) $key,
			'label'       => (string) ( $t['label'] ?? $key ),
			'title'       => (string) ( $t['title'] ?? '' ),
			'sub'         => $sub,
			'pack'        => $pin > 0 ? delicat_rewards_app_number( $pin ) . ' 💎' : $sub,          // the pack counted
			'reward'      => delicat_rewards_app_diamonds( (int) ( $t['reward'] ?? 0 ) ), // what the bonus gives
			'goal'        => $goal,
			'progress'    => $progress,
			'remaining'   => max( 0, $goal - $progress ), // purchases still missing to the goal, 'owed' not included
			'owed'        => max( 0, (int) ( $row['owed'] ?? 0 ) ),
			'claimed'     => max( 0, (int) ( $row['claimed'] ?? 0 ) ),
			'max_claims'  => max( 0, (int) ( $t['max_claims'] ?? 0 ) ),
			'claimable'   => $claimable,
			'blocked'     => $blocked,
			'claim_token' => $claimable && $own ? (string) ( $row['token'] ?? '' ) : '',
		);
	}
	return $out;
}

/**
 * Claim a bonus for the signed-in customer: ['delivery' => DELIVERY, 'state' => rewards].
 * The same token sent again returns the same reward order (the app retries once after a
 * network error or a 5xx). Errors keep the website's codes and statuses, never a 401.
 */
function delicat_rewards_app_claim( $tier, $token ) {
	$tier   = is_scalar( $tier ) ? sanitize_key( (string) $tier ) : '';
	$result = delicat_claim_for_current_user( $tier, is_scalar( $token ) ? (string) $token : '' );
	if ( is_wp_error( $result ) ) return delicat_rewards_app_error( $result );
	$delivery = is_array( $result['delivery'] )
		? delicat_rewards_app_delivery_shape( $result['delivery'] )
		: delicat_rewards_app_pending_delivery( (int) $result['order_id'], $tier );
	return array(
		'delivery' => $delivery,
		'state'    => delicat_rewards_app_state( (int) get_current_user_id(), $result['state'] ),
	);
}

/** « Mes PINs récompenses » of the signed-in customer, one page, without any code. */
function delicat_rewards_app_pins( $page ) {
	$page = max( 1, min( 10000, (int) $page ) );
	$uid  = (int) get_current_user_id();
	if ( $uid < 1 ) return array( 'items' => array(), 'page' => $page, 'pages' => 0 );
	if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'unavailable', 'WooCommerce est momentanément indisponible.', array( 'status' => 503 ) );
	}
	$list  = delicat_reward_pins_page( $uid, $page );
	$items = array();
	foreach ( $list['items'] as $d ) {
		$row = delicat_rewards_app_delivery_shape( $d );
		unset( $row['pins'], $row['redeem_url'], $row['message'] );
		$items[] = $row;
	}
	return array( 'items' => $items, 'page' => (int) $list['page'], 'pages' => (int) $list['pages'] );
}

/** One reward order of the signed-in customer, with its PINs (404 for anyone else's). */
function delicat_rewards_app_delivery( $order_id ) {
	$uid = (int) get_current_user_id();
	if ( $uid < 1 ) return new WP_Error( 'loyalty_auth', 'Cette action n’est pas autorisée pour ce compte.', array( 'status' => 403 ) );
	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'unavailable', 'WooCommerce est momentanément indisponible.', array( 'status' => 503 ) );
	}
	$delivery = delicat_reward_delivery( max( 0, (int) $order_id ), $uid );
	return is_wp_error( $delivery ) ? delicat_rewards_app_error( $delivery ) : delicat_rewards_app_delivery_shape( $delivery );
}

/**
 * The weekly Meilleur Client board (contract C1). $viewer_id counts only when it is the
 * signed-in customer: anyone else is read as a guest, who sees the board only while it is
 * public on the website. Rows are the website's (same ranking, same percentages), names
 * always masked.
 */
function delicat_rewards_app_leaderboard( $viewer_id ) {
	$viewer = (int) $viewer_id;
	if ( $viewer > 0 && ( $viewer !== (int) get_current_user_id() || ! get_userdata( $viewer ) ) ) $viewer = 0;
	$out = array(
		'enabled'    => false,
		'period'     => array( 'start' => '', 'ends_at' => '', 'server_time' => delicat_rewards_app_iso( time() ) ),
		'rule'       => array(
			'spend_weight'  => (int) round( 100 * DELICAT_BOARD_SPEND_WEIGHT ),
			'orders_weight' => (int) round( 100 * DELICAT_BOARD_ORDER_WEIGHT ),
			'scope'         => delicat_rewards_app_scope(),
		),
		'prizes'     => array(),
		'board_size' => delicat_board_size(),
		'top'        => array(),
		'you'        => null,
		'excluded'   => $viewer > 0 && delicat_is_excluded( $viewer ),
		'winners'    => array(),
		'texts'      => delicat_rewards_app_board_texts(),
		'web_url'    => '/program-bon-kliyan/',
		'rules_url'  => delicat_rewards_app_rules_url( 'board' ),
	);
	if ( ! delicat_meilleur_on() || ( $viewer < 1 && (int) delicat_cfg( 'board_public' ) !== 1 ) ) return $out;

	$out['enabled']           = true;
	$out['period']['start']   = delicat_rewards_app_iso( delicat_competition_start() );
	$out['period']['ends_at'] = delicat_rewards_app_iso( delicat_next_week_start() );
	foreach ( delicat_meilleur_prizes() as $rank => $prize ) {
		if ( (int) $prize > 0 ) $out['prizes'][] = array( 'rank' => (int) $rank, 'amount' => delicat_rewards_app_diamonds( (int) $prize ) );
	}
	$board = delicat_board_snapshot( $viewer, 'delicat_rewards_app_public_name' );
	foreach ( $board['top'] as $row ) {
		$out['top'][] = array(
			'rank'     => (int) $row['rank'],
			'name'     => (string) $row['name'],
			'initials' => delicat_rewards_app_initials( $row['name'] ),
			'pct'      => (int) $row['pct'],
			'is_you'   => $viewer > 0 && (int) $row['uid'] === $viewer,
		);
	}
	if ( is_array( $board['you'] ) ) {
		$me = $board['you'];
		$out['you'] = array(
			'rank'  => (int) $me['rank'],
			'name'  => (string) $me['name'],
			'pct'   => max( 4, min( 100, (int) $me['pct'] ) ),
			'gap'   => (string) (int) $me['gap'],   // "{gap}" of texts.gap: points of the leader's score behind the place ahead
			'ahead' => (string) (int) $me['ahead'], // "{ahead}": that place; "0" in 1st place
			'total' => (int) $me['total'],
		);
	}
	foreach ( delicat_recent_winners( 5 ) as $w ) { // empty unless « Anciens gagnants » is shown on the page
		$out['winners'][] = array(
			'name'  => delicat_rewards_app_public_name( $w['uid'] ),
			'date'  => wp_date( 'Y-m-d', $w['end'], wp_timezone() ),
			'prize' => delicat_rewards_app_diamonds( $w['prize'] ),
		);
	}
	return $out;
}

/* ---- Helpers ------------------------------------------------------------ */

/**
 * A board name for the app: always the masked form, and never one that still contains the
 * account's login or e-mail local part (a first name typed as "jeanrobert509", say): the
 * alias then. delicat_display_name() already refuses exact matches and anything with '@'.
 */
function delicat_rewards_app_public_name( $user_id ) {
	$name = (string) delicat_display_name( (int) $user_id, true );
	$u    = get_userdata( (int) $user_id );
	if ( ! $u ) return $name;
	$flat  = function ( $v ) { return strtolower( (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', (string) $v ) ); };
	$seen  = $flat( $name );
	$local = explode( '@', (string) $u->user_email );
	foreach ( array( $u->user_login, $local[0] ) as $secret ) {
		$secret = $flat( $secret );
		if ( strlen( $secret ) >= 4 && false !== strpos( $seen, $secret ) ) return delicat_public_alias( $u->ID );
	}
	return false === strpos( $name, '@' ) ? $name : delicat_public_alias( $u->ID );
}

/** Avatar letters the way the page draws them: first letter of the first two words. */
function delicat_rewards_app_initials( $name ) {
	$words = preg_split( '/\s+/u', trim( (string) $name ), -1, PREG_SPLIT_NO_EMPTY );
	$words = is_array( $words ) ? $words : array();
	$first = function ( $w ) { return function_exists( 'mb_substr' ) ? mb_substr( $w, 0, 1 ) : substr( $w, 0, 1 ); };
	$out   = ( $words ? $first( $words[0] ) : '' ) . ( isset( $words[1] ) ? $first( $words[1] ) : '' );
	$out   = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $out ) : strtoupper( $out );
	return '' !== $out ? $out : '?';
}

/** The board's own copy (Réglages > Textes — page Meilleur Client), by contract key. */
function delicat_rewards_app_board_texts() {
	$map = array(
		'kicker' => 'board_kicker', 'title' => 'board_title', 'games' => 'board_games', 'reset_label' => 'board_reset_label',
		'reward_label' => 'board_reward_label', 'reward_sub' => 'board_reward_sub', 'you_label' => 'board_you_label',
		'heading' => 'board_heading', 'empty' => 'board_empty_text', 'first' => 'board_first_text', 'rank' => 'board_rank_text',
		'gap' => 'board_gap_text', 'start' => 'board_start_text', 'footer_1' => 'board_footer_1', 'footer_2' => 'board_footer_2',
	);
	$out = array();
	foreach ( $map as $key => $setting ) $out[ $key ] = (string) delicat_cfg( $setting );
	return $out;
}

/** "payés et terminés": which orders count, from the settings the engine itself applies. */
function delicat_rewards_app_counted() {
	$paid = (bool) delicat_cfg( 'require_paid' );
	$done = array( 'completed' ) === delicat_counting_statuses();
	if ( $paid && $done ) return 'payés et terminés';
	if ( $done ) return 'terminés';
	return $paid ? 'payés' : 'validés';
}

/**
 * The games whose purchases count. The monthly bonus counts only the tiers' products (their
 * titles, "Free Fire" by default). The board counts those too, plus any « produits
 * supplémentaires » (extra_pins): then the admin's own games line (board_games) names them.
 */
function delicat_rewards_app_games( $board ) {
	$extra = array_filter( array_map( 'intval', (array) delicat_cfg( 'extra_pins' ) ) );
	if ( $board && $extra ) {
		$games = trim( wp_strip_all_tags( (string) delicat_cfg( 'board_games' ) ) );
		if ( '' !== $games ) return $games;
	}
	$titles = array();
	foreach ( delicat_tiers() as $t ) {
		$title = trim( wp_strip_all_tags( (string) ( $t['title'] ?? '' ) ) );
		if ( '' !== $title ) $titles[ strtolower( $title ) ] = $title;
	}
	return implode( ' · ', $titles );
}

/** What the board counts, e.g. « Achats payés et terminés : Free Fire ». */
function delicat_rewards_app_scope() {
	$games = delicat_rewards_app_games( true );
	return 'Achats ' . delicat_rewards_app_counted() . ( '' !== $games ? ' : ' . $games : '' );
}

/** « Comment ça marche » of the monthly bonus, from the same settings as the engine. */
function delicat_rewards_app_how_to_earn( $cap ) {
	$games = delicat_rewards_app_games( false );
	$lines = array(
		'Achetez vos packs' . ( '' !== $games ? ' ' . $games : '' ) . ' : chaque pack a son propre objectif du mois.',
		'Seuls les achats ' . delicat_rewards_app_counted() . ' comptent' . ( delicat_cfg( 'ignore_refund' ) ? ' ; un remboursement retire l’achat du compteur.' : '.' ),
		'Objectif atteint : appuyez sur « Réclamer » pour recevoir votre PIN gratuit.',
		'Copiez le PIN reçu, puis ouvrez « Utiliser mon PIN » pour le valider.',
	);
	if ( (int) $cap > 0 ) $lines[] = 'Bonus limités à ' . delicat_rewards_app_diamonds( (int) $cap ) . ' par mois.';
	$lines[] = 'Les compteurs repartent à zéro le 1er de chaque mois.';
	return $lines;
}

/** DELIVERY in the app's keys, from delicat_reward_delivery() (the page's camelCase). */
function delicat_rewards_app_delivery_shape( array $d ) {
	$pins = array();
	foreach ( (array) ( $d['pins'] ?? array() ) as $pin ) {
		if ( ! is_array( $pin ) || '' === (string) ( $pin['code'] ?? '' ) ) continue;
		$pins[] = array( 'code' => (string) $pin['code'], 'label' => (string) ( $pin['label'] ?? '' ) );
	}
	$amount = (int) ( $d['amount'] ?? 0 );
	return array(
		'order_id'   => (int) ( $d['orderId'] ?? 0 ),
		'tier'       => (string) ( $d['tier'] ?? '' ),
		'amount'     => $amount > 0 ? delicat_rewards_app_diamonds( $amount ) : '',
		'date'       => (string) ( $d['date'] ?? '' ),
		'status'     => (string) ( $d['status'] ?? 'pending' ),
		'expected'   => max( 0, (int) ( $d['expected'] ?? 0 ) ),
		'pins'       => $pins,
		'redeem_url' => (string) ( $d['redeemUrl'] ?? delicat_rewards_app_redeem_url() ),
		'message'    => (string) ( $d['message'] ?? '' ),
	);
}

/** A claim went through but its order could not be read back yet: never ask to claim again. */
function delicat_rewards_app_pending_delivery( $order_id, $tier ) {
	$tiers = delicat_tiers();
	return array(
		'order_id'   => (int) $order_id,
		'tier'       => (string) $tier,
		'amount'     => isset( $tiers[ $tier ] ) ? delicat_rewards_app_diamonds( (int) ( $tiers[ $tier ]['reward'] ?? 0 ) ) : '',
		'date'       => delicat_rewards_app_iso( time() ),
		'status'     => 'pending',
		'expected'   => 0,
		'pins'       => array(),
		'redeem_url' => delicat_rewards_app_redeem_url(),
		'message'    => 'Récompense enregistrée. Votre PIN est en préparation. Actualisez ici dans quelques instants ; ne réclamez pas à nouveau ce bonus.',
	);
}

/** The plugin's refusals keep their code and status, except 'auth' (401): 403 loyalty_auth. */
function delicat_rewards_app_error( $error ) {
	$data   = $error->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
	if ( 'auth' === $error->get_error_code() || 401 === $status ) {
		return new WP_Error( 'loyalty_auth', 'Cette action n’est pas autorisée pour ce compte.', array( 'status' => 403 ) );
	}
	return $error;
}

/** Where a Free Fire PIN is used: the same page as delicat_reward_delivery()'s redeemUrl. */
function delicat_rewards_app_redeem_url() {
	return 'https://delicastoreha.com/free-fire-redeem/';
}

/**
 * The written rules. The board's is the Builder's « Règlement du programme Bon Kliyan »
 * page (slug reglement-bon-kliyan) when it is published; that page covers only the weekly
 * contest, so the monthly bonus has none unless a filter gives one.
 */
function delicat_rewards_app_rules_url( $context ) {
	$url = '';
	if ( 'board' === $context && function_exists( 'get_page_by_path' ) ) {
		$page = get_page_by_path( 'reglement-bon-kliyan', OBJECT, 'page' );
		if ( $page && 'publish' === $page->post_status ) $url = (string) get_permalink( $page );
	}
	return esc_url_raw( (string) apply_filters( 'delicat_rewards_app_rules_url', $url, $context ) );
}

/** ISO 8601 in the site's time zone, with its offset. */
function delicat_rewards_app_iso( $ts ) {
	return (string) wp_date( DATE_ATOM, (int) $ts, wp_timezone() );
}

/** « Septembre 2026 », in French whatever the site language. */
function delicat_rewards_app_month_label( $ts ) {
	$months = array( 1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre' );
	return ( $months[ (int) wp_date( 'n', (int) $ts, wp_timezone() ) ] ?? '' ) . ' ' . wp_date( 'Y', (int) $ts, wp_timezone() );
}

/** French number: « 1 250 », « 1 250,5 » (up to $decimals, trailing zeros dropped). */
function delicat_rewards_app_number( $n, $decimals = 0 ) {
	$out = number_format( (float) $n, max( 0, (int) $decimals ), ',', ' ' );
	return $decimals > 0 ? rtrim( rtrim( $out, '0' ), ',' ) : $out;
}

/** « 1 160 diamants ». */
function delicat_rewards_app_diamonds( $n ) {
	$n = (int) $n;
	return delicat_rewards_app_number( $n ) . ( abs( $n ) > 1 ? ' diamants' : ' diamant' );
}
