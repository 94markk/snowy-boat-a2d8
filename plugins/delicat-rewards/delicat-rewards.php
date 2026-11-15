<?php
/**
 * Plugin Name: Delicat Rewards — Admin Edition
 * Description: Complete Delicat Rewards system with branding controls and secure license server for Client editions.
 * Version:     4.1.0
 * Author:      Delicat Store Haiti
 * Requires PHP: 7.4
 *
 * Bonus Free Fire page (monthly bonuses credited in HTG to Delicat Wallet, weekly « Meilleur Client »
 * ranking, referral programme « Parrainage »), its REST routes, the app contract and the dashboard
 * WooCommerce › Delicat Rewards. Release history: README.txt.
 *
 * FILES
 *   delicat-rewards.php   configuration, purchase counting, signed ledger, claims, leaderboard, weekly award
 *   wallet-rewards.php    HTG credit through TeraWallet (one durable marker per reward, never a second credit)
 *   reward-delivery.php   a customer's reward orders and legacy PINs (owner-only, no-store)
 *   competition.php       weekly periods and past winners; competition-admin.php closing and history (wp-admin)
 *   referral.php / referral-admin.php
 *   bonus-page.php        [delicat_bonus_free_fire] page and its single boot request
 *   app-contract.php      delicat_rewards_app_*() for the Delicat App API
 *   rewards-admin.php     dashboard screens and forms: loaded in wp-admin only (4.1.0)
 *
 * SECURITY MODEL
 *   • The browser is never trusted. Progress, eligibility and claims are always
 *     recomputed on the server from real WooCommerce orders.
 *   • Every admin form: capability check + nonce + referer check + strict
 *     sanitising + whitelists + hard caps that the dashboard itself cannot raise.
 *   • Claims: MySQL lock, re-check inside the lock, counter reserved before the
 *     reward is issued and rolled back if issuing fails, per-user rate limit,
 *     a database one-shot slot per claim number and a signed, short-lived token.
 *   • Tier keys are permanent: renaming a label never resets or duplicates the
 *     claims already recorded for the month.
 */


/* =========================================================================
   HARD LIMITS — deliberately NOT editable from the dashboard.
   Even a compromised admin session cannot hand out 1,000,000 diamonds.
   Change them here in code only, on purpose.
   ========================================================================= */
define( 'DELICAT_MAX_TIERS',      20 );    // max number of tiers
define( 'DELICAT_MAX_GOAL',       999 );   // max "x fois" per tier
define( 'DELICAT_MAX_REWARD',     10000 ); // max diamonds per single claim
define( 'DELICAT_MAX_MONTH_CAP',  100000 );// max value of the monthly cap setting
define( 'DELICAT_MAX_PIN_ITEMS',  60 );    // max PIN codes a reward order may contain
define( 'DELICAT_TEXT_LEN',       90 );    // max length of any editable text
define( 'DELICAT_MSG_LEN',        1200 );  // max length of an email template
define( 'DELICAT_CLAIMS_PER_MIN', 5 );     // per-user claim rate limit
define( 'DELICAT_CLAIMS_PER_DAY', 20 );    // per-user hard ceiling per day
define( 'DELICAT_IP_PER_MIN',     15 );    // per-IP claim rate limit
define( 'DELICAT_TOKEN_TTL',      1800 );  // signed claim token lifetime (seconds)
define( 'DELICAT_BOARD_SPEND_WEIGHT', 0.70 ); // Meilleur Client: money is primary
define( 'DELICAT_BOARD_ORDER_WEIGHT', 0.30 ); // distinct WooCommerce orders
define( 'DELICAT_LEDGER_DEBT_KEY',    '#debt' ); // reserved signed-ledger key: purchases still owed (sanitize_key can never produce it)
define( 'DELICAT_DEBT_MONTHS',        13 );    // how many past months a refund after a claim is carried forward from
if ( ! defined( 'DELICAT_REWARDS_VERSION' ) ) define( 'DELICAT_REWARDS_VERSION', '4.1.0' ); // keep in step with the header

/** Capability required for every Delicat admin screen and action. */
require_once __DIR__ . '/wallet-rewards.php';
require_once __DIR__ . '/reward-delivery.php';
require_once __DIR__ . '/rewards-view.php';
require_once __DIR__ . '/supplier-history.php';
if ( wp_doing_ajax() ) {
	require_once __DIR__ . '/rewards-transport.php'; // V10: its only entry point is admin-ajax.php
}

function delicat_cap() {
	return apply_filters( 'delicat_admin_cap', 'manage_woocommerce' );
}

/** Internal WooCommerce logger. Never exposes stack traces to customers. */
function delicat_log( $level, $message, array $context = array() ) {
	if ( ! function_exists( 'wc_get_logger' ) ) return;
	$logger = wc_get_logger();
	$context = array_merge( array( 'source' => 'delicat-rewards' ), $context );
	$level = in_array( $level, array( 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ), true ) ? $level : 'info';
	$logger->log( $level, wp_strip_all_tags( (string) $message ), $context );
}


register_activation_hook( __FILE__, function () {
	set_transient( 'delicat_rewards_setup_notice', 1, 10 * MINUTE_IN_SECONDS );
} );

/* Declare compatibility with WooCommerce High-Performance Order Storage (HPOS) */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/* =========================================================================
   CONFIG — stored in one option, edited from the dashboard
   ========================================================================= */
function delicat_defaults() {
	return array(
		/* products */
		'pins'          => array( 110 => 0, 341 => 0, 572 => 0, 1160 => 0, 2180 => 0 ), // diamonds => product ID (0 = resolve by slug)
		'extra_pins'    => array(),                    // extra product IDs counted in the leaderboard only
		'match_slug'    => 1,                          // also treat any product matching the slug pattern as a FF PIN
		'slug_pattern'  => 'pin-free-%d-diamants',

		/* tiers */
		'tiers'         => array(
			'mini'     => array( 'label' => 'Mini',     'title' => 'Free Fire', 'sub' => '110 Diamants',  'ids' => array(), 'pin' => 110,  'goal' => 25, 'reward' => 110,  'max_claims' => 0, 'enabled' => 1, 'order' => 1 ),
			'smart'    => array( 'label' => 'Smart',    'title' => 'Free Fire', 'sub' => '341 Diamants',  'ids' => array(), 'pin' => 341,  'goal' => 20, 'reward' => 341,  'max_claims' => 0, 'enabled' => 1, 'order' => 2 ),
			'silver'   => array( 'label' => 'Silver',   'title' => 'Free Fire', 'sub' => '572 Diamants',  'ids' => array(), 'pin' => 572,  'goal' => 15, 'reward' => 572,  'max_claims' => 0, 'enabled' => 1, 'order' => 3 ),
			'gold'     => array( 'label' => 'Gold',     'title' => 'Free Fire', 'sub' => '1160 Diamants', 'ids' => array(), 'pin' => 1160, 'goal' => 12, 'reward' => 1160, 'max_claims' => 0, 'enabled' => 1, 'order' => 4 ),
			'platinum' => array( 'label' => 'Platinum', 'title' => 'Free Fire', 'sub' => '2180 Diamants', 'ids' => array(), 'pin' => 2180, 'goal' => 10, 'reward' => 2180, 'max_claims' => 0, 'enabled' => 1, 'order' => 5 ),
		),

		/* program rules */
		'reward_method' => 'wallet',                   // 4.0: claim HTG credit through TeraWallet
		'credit_values' => array(),                    // optional HTG overrides by reward denomination
		'statuses'      => array( 'completed' ),       // compatibility field; v3 strict mode counts completed only
		'strict_completed' => 1,                         // direct top-up/PIN earns progress only after Woo marks it completed
		'require_paid'  => 1,                          // WooCommerce must consider the order paid
		'ignore_refund' => 1,                          // refunded quantities are subtracted
		'require_deliv' => 0,                          // the PIN code must have been delivered (needs a keys plugin)
		'board_public'  => 0,                          // leaderboard visible to guests? no — login only
		'dry_run'       => 0,                          // TEST MODE: progress is shown, nothing is ever issued
		'monthly_cap'   => 0,                          // 0 = no cap. Max free diamonds one client can claim per month.
		'prize'         => 572,                        // weekly Meilleur Client prize
		'prize2'        => 0,                          // 2nd place prize — 0 = disabled (winner-takes-all, legacy)
		'prize3'        => 0,                          // 3rd place prize — 0 = disabled
		'reset_day'     => 0,                          // 0 = Sunday
		'reset_hour'    => 20,                         // 20:00 site time
		'board_size'    => 10,
		'mask_names'    => 1,                          // « Jean R. »; logins and e-mail parts are never shown (delicat_display_name)
		'wa'            => 'https://wa.me/message/BXQXUCKDDI3GO1',

		/* texts shown to clients */
		'page_title'    => 'Rewards Program',
		'program_name'  => 'PROGRAMME DE BONUS FREE FIRE',
		'brand_name'    => 'DELICAT STORE',
		'brand_logo_id' => 0,
		't_spend'       => 'Achats pour ce Mois :',
		't_reward'      => 'Recompense pour ce Mois :',
		't_progress'    => 'VOTRE PROGRESSION',
		'note'          => 'NB: Le programme redémarrera automatiquement le 1er jour de chaque mois.',
		'unit'          => 'DIAMANT',
		'currency'      => 'G',
		'badge_suffix'  => 'Bonus',
		'btn_prefix'    => 'RECLAMER',
		'btn_suffix'    => 'GRATUIT',
		'btn_locked'    => 'LIMITE ATTEINTE',
		'btn_dry'       => 'BIENTÔT DISPONIBLE',

		/* customizable Meilleur Client page */
		'board_kicker'         => '🔥 DELICAT STORE · KLASMAN SEMÈN',
		'board_title'          => 'MEILLEUR CLIENT',
		'board_games'          => '🔥 Free Fire · 🚨 FlexCity · ⚽️ eFootball',
		'board_reset_label'    => 'RESET NAN',
		'board_reward_label'   => 'RÉCOMPENSE · 1ÈRE PLAS',
		'board_prize_suffix'   => '💎 Gratis',
		'board_reward_sub'     => '🔥 Toujou kontinye Top up pou ou ka rive Premye nan koukou a 🔥',
		'board_you_label'      => 'Pozisyon ou semèn sa a',
		'board_login_text'     => 'Konekte pou wè pozisyon ou',
		'board_heading'        => 'KLASMAN',
		'board_hidden_text'    => 'Kache',
		'board_empty_text'     => 'Poko gen kòmand semèn sa a.',
		'board_first_text'     => '🏆 Ou Premye plas la! Kontinye konsa!',
		'board_rank_text'      => 'Ou nan plas #{rank} 🔥',
		'board_gap_text'       => 'Vous êtes #{rank}e — {gap} point(s) derrière la place #{ahead} 🔥',
		'board_start_text'     => 'Fè premye top-up ou pou antre nan klasman an!',
		'board_footer_1'       => '🔒 Non lòt kliyan yo rete kache',
		'board_footer_2'       => '🔥 Toujou kontinye Top up pou ou ka rive Premye nan koukou a 🔥',
		'board_footer_url'     => 'https://delicastoreha.com',

		/* email sent to the client when a reward is issued */
		'notify_client'  => 1,
		'notify_subject' => 'Delicat Store — ou resevwa bonis ou! 🎁',
		'notify_body'    => "Bonjou {name},\n\nBonis « {tier} » ou a valide : {reward} {unit} gratis!\nKòmand rekonpans #{order} pare — ale nan kont ou pou w wè kòd yo.\n\nMèsi paske w se yon kliyan Delicat Store 💜\n{site}",
		'notify_winner'  => "Bravo {name}! 🏆\n\nOu se Meilleur Client semèn nan : {reward} {unit} gratis!\nKòmand rekonpans #{order} pare nan kont ou.\n\nDelicat Store 💜",
	);
}

/** Merged, filtered config. Read-only at runtime. */
function delicat_cfg( $key = null ) {
	static $cfg = null;
	if ( null === $cfg ) {
		$saved = get_option( 'delicat_config', array() );
		if ( ! is_array( $saved ) ) $saved = array();
		$cfg = apply_filters( 'delicat_config', array_merge( delicat_defaults(), $saved ) );
		$cfg['reward_method'] = 'wallet'; // delivery contract: PIN issuance is historical only
	}
	if ( null === $key ) return $cfg;
	return array_key_exists( $key, $cfg ) ? $cfg[ $key ] : null;
}

/** Config generation — bumped on every save so all caches invalidate at once. */
function delicat_cfg_gen() {
	return (int) get_option( 'delicat_cfg_gen', 1 );
}

/** Permanent minimal tier snapshots keep old claim values meaningful after edits/deletion. */
function delicat_tier_history() {
	$h = get_option( 'delicat_tier_history', array() ); return is_array( $h ) ? $h : array();
}
function delicat_tier_history_remember( $tiers ) {
	if ( ! is_array( $tiers ) ) return;
	$h = delicat_tier_history(); $changed = false;
	foreach ( $tiers as $key => $t ) {
		$key = sanitize_key( $key ); if ( '' === $key || ! is_array( $t ) ) continue;
		$rec = array( 'label' => delicat_txt( $t['label'] ?? $key ), 'pin' => max( 0, (int) ( $t['pin'] ?? 0 ) ), 'reward' => max( 0, (int) ( $t['reward'] ?? 0 ) ) );
		if ( ! isset( $h[ $key ] ) || $h[ $key ] !== $rec ) { $h[ $key ] = $rec; $changed = true; }
	}
	if ( $changed ) update_option( 'delicat_tier_history', $h, false );
}

function delicat_cfg_save( array $cfg ) {
	$prev = get_option( 'delicat_config', array() );
	if ( is_array( $prev ) ) delicat_tier_history_remember( $prev['tiers'] ?? array() );
	delicat_tier_history_remember( $cfg['tiers'] ?? array() );
	update_option( 'delicat_config', $cfg, true );
	update_option( 'delicat_cfg_gen', delicat_cfg_gen() + 1 ); // every cached progress key becomes stale instantly
	delete_transient( 'delicat_leaderboard' );
}

/**
 * 4.1.0: once every one-time upgrade below (and the purchase-history one) has run for this release, an
 * autoloaded flag skips their checks: two option reads less on every request without an object cache.
 */
function delicat_upgrades_done() {
	return DELICAT_REWARDS_VERSION === get_option( 'delicat_rewards_upgraded' );
}
add_action( 'init', function () {
	if ( delicat_upgrades_done() ) return;
	if ( version_compare( (string) get_option( 'delicat_rewards_schema_version', '0' ), '3.1.1', '>=' ) && '3.2.4' === get_option( 'delicat_purchase_history_version', '' ) ) {
		update_option( 'delicat_rewards_upgraded', DELICAT_REWARDS_VERSION, true );
	}
}, 101 );

/** One-way v3 migration. Keeps custom mappings, but corrects old built-in journey defaults. */
function delicat_upgrade_300() {
	if ( delicat_upgrades_done() ) return;
	if ( version_compare( (string) get_option( 'delicat_rewards_schema_version', '0' ), '3.0.1', '>=' ) ) return;
	$saved = get_option( 'delicat_config', false );
	if ( is_array( $saved ) ) {
		$saved['reward_method']    = 'order';
		$saved['strict_completed'] = 1;
		$saved['statuses']         = array( 'completed' );
		$saved['require_paid']     = 1;
		$saved['ignore_refund']    = 1;
		$old_rewards = array( 'mini' => array( 110, 110 ), 'smart' => array( 220, 341 ), 'silver' => array( 341, 572 ), 'gold' => array( 341, 1160 ), 'platinum' => array( 572, 2180 ) );
		if ( isset( $saved['tiers'] ) && is_array( $saved['tiers'] ) ) {
			foreach ( $old_rewards as $key => $pair ) {
				if ( ! isset( $saved['tiers'][ $key ] ) || ! is_array( $saved['tiers'][ $key ] ) ) continue;
				if ( (int) ( $saved['tiers'][ $key ]['reward'] ?? 0 ) === (int) $pair[0] ) $saved['tiers'][ $key ]['reward'] = (int) $pair[1];
				if ( 'PIN Free Fire' === (string) ( $saved['tiers'][ $key ]['title'] ?? '' ) ) $saved['tiers'][ $key ]['title'] = 'Free Fire';
			}
		}
		delicat_tier_history_remember( $saved['tiers'] ?? array() );
		update_option( 'delicat_config', $saved, true );
		update_option( 'delicat_cfg_gen', delicat_cfg_gen() + 1 );
		delete_transient( 'delicat_leaderboard' );
	}
	update_option( 'delicat_rewards_schema_version', '3.0.1', false );
}
add_action( 'init', 'delicat_upgrade_300', 1 );

/** v3.1 changes no customer data: invalidate only the old quantity-based board. */
function delicat_upgrade_310() {
	if ( delicat_upgrades_done() ) return;
	if ( version_compare( (string) get_option( 'delicat_rewards_schema_version', '0' ), '3.1.0', '>=' ) ) return;
	$saved = get_option( 'delicat_config', false );
	if ( is_array( $saved ) && ( $saved['board_gap_text'] ?? '' ) === 'Ou nan plas #{rank} — anviwon {gap} top-up pou rive #{ahead} 🔥' ) {
		/* {gap} is now a combined-score distance, not an inaccurate quantity guess. */
		$saved['board_gap_text'] = 'Vous êtes #{rank}e — {gap} point(s) derrière la place #{ahead} 🔥';
		update_option( 'delicat_config', $saved, true );
	}
	delete_transient( 'delicat_leaderboard' );
	update_option( 'delicat_rewards_schema_version', '3.1.0', false );
}
add_action( 'init', 'delicat_upgrade_310', 2 );

/** v3.1.1 removes cached client metrics from the leaderboard REST response. */
function delicat_upgrade_311() {
	if ( delicat_upgrades_done() ) return;
	if ( version_compare( (string) get_option( 'delicat_rewards_schema_version', '0' ), '3.1.1', '>=' ) ) return;
	delete_transient( 'delicat_leaderboard' );
	update_option( 'delicat_rewards_schema_version', '3.1.1', false );
}
add_action( 'init', 'delicat_upgrade_311', 3 );

/**
 * Reseller API mirrors and staff orders no longer count (delicat_order_is_retail): drop
 * every cached progress and board built with them. Own flag; the schema version belongs
 * to the release.
 */
function delicat_upgrade_retail_only() {
	if ( get_option( 'delicat_rewards_retail_only' ) ) return;
	update_option( 'delicat_rewards_retail_only', 1, true );
	update_option( 'delicat_cfg_gen', delicat_cfg_gen() + 1 );
	delete_transient( 'delicat_leaderboard' );
	delete_transient( 'delicat_leaderboard_stale' );
}
add_action( 'init', 'delicat_upgrade_retail_only', 3 );

/** Custom branding used on the client rewards card. */
function delicat_brand_name() {
	$name = trim( (string) delicat_cfg( 'brand_name' ) );
	return $name !== '' ? $name : get_bloginfo( 'name' );
}
function delicat_brand_logo_url() {
	$id = absint( delicat_cfg( 'brand_logo_id' ) );
	if ( ! $id ) return '';
	$url = wp_get_attachment_image_url( $id, 'medium' );
	return $url ? esc_url_raw( $url ) : '';
}

/** First run: snapshot the real product IDs so the dashboard opens pre-filled. */
add_action( 'admin_init', function () {
	if ( get_option( 'delicat_config' ) !== false ) return;
	if ( ! function_exists( 'wc_get_products' ) ) return;
	$cfg = delicat_defaults();
	$cfg['prize'] = max( 1, (int) get_option( 'delicat_meilleur_prize', 572 ) );
	foreach ( $cfg['pins'] as $denom => $unused ) {
		$cfg['pins'][ $denom ] = delicat_pid_by_slug( sprintf( $cfg['slug_pattern'], (int) $denom ) );
	}
	delicat_cfg_save( $cfg );
	delicat_audit_add( 'Configuration initialisée (IDs produits détectés automatiquement).' );
} );

/* =========================================================================
   PRODUCT RESOLUTION — by ID first, slug as a safety net
   ========================================================================= */
/**
 * A usable product ID: a simple/variable product, OR a single variation of one.
 * Variable products live in the DB as 'product' + several 'product_variation'
 * children — that is why a variation ID used to be rejected.
 */
function delicat_valid_pid( $id ) {
	$id = (int) $id;
	if ( $id <= 0 ) return 0;
	static $cache = array();
	if ( isset( $cache[ $id ] ) ) return $cache[ $id ];

	$ok   = 0;
	$post = get_post( $id );
	$live = array( 'publish', 'private' );

	if ( $post && in_array( $post->post_status, $live, true ) ) {
		if ( 'product' === $post->post_type ) {
			$ok = $id;
		} elseif ( 'product_variation' === $post->post_type ) {
			$parent = $post->post_parent ? get_post( $post->post_parent ) : null;
			if ( $parent && 'product' === $parent->post_type && in_array( $parent->post_status, $live, true ) ) {
				$ok = $id; // a variation of a published variable product
			}
		}
	}
	return $cache[ $id ] = $ok;
}

/** Is this ID a variation (as opposed to a whole product)? */
function delicat_is_variation( $id ) {
	return 'product_variation' === get_post_type( (int) $id );
}

/** The parent product of a variation (or the ID itself if it is not one). */
function delicat_parent_pid( $id ) {
	$id = (int) $id;
	return delicat_is_variation( $id ) ? (int) wp_get_post_parent_id( $id ) : $id;
}

/**
 * Every ID an order line can be recognised by: its variation AND its parent.
 * So a tier configured with the parent counts every variation, and a tier
 * configured with one variation counts only that one.
 */
function delicat_item_ids( $item ) {
	$ids = array();
	if ( method_exists( $item, 'get_variation_id' ) ) {
		$v = (int) $item->get_variation_id();
		if ( $v ) $ids[] = $v;
	}
	$p = (int) $item->get_product_id();
	if ( $p ) $ids[] = $p;
	return $ids;
}

function delicat_pid_by_slug( $slug ) {
	static $cache = array();
	if ( isset( $cache[ $slug ] ) ) return $cache[ $slug ];
	$post = get_page_by_path( $slug, OBJECT, 'product' );
	$id   = ( $post && 'publish' === $post->post_status ) ? (int) $post->ID : 0;
	return $cache[ $slug ] = $id;
}

/** The product that delivers N diamonds: dashboard ID, else slug lookup. */
function delicat_pid_by_diamonds( $n ) {
	$n    = (int) $n;
	$pins = (array) delicat_cfg( 'pins' );
	if ( isset( $pins[ $n ] ) ) {
		$id = delicat_valid_pid( $pins[ $n ] );
		if ( $id ) return $id;
	}
	$pattern = (string) delicat_cfg( 'slug_pattern' );
	if ( false === strpos( $pattern, '%d' ) ) return 0;
	return delicat_pid_by_slug( str_replace( '%d', (string) $n, $pattern ) ); // not sprintf: a stray %s cannot crash PHP 8
}

/** Denominations the rewards can be composed from (dashboard order). */
function delicat_ff_denoms() {
	$d = array_map( 'intval', array_keys( (array) delicat_cfg( 'pins' ) ) );
	$d = array_values( array_filter( $d, function ( $x ) { return $x > 0; } ) );
	sort( $d );
	return apply_filters( 'delicat_ff_denoms', $d );
}

function delicat_slug_regex() {
	$p = (string) delicat_cfg( 'slug_pattern' );
	if ( false === strpos( $p, '%d' ) ) return '';
	$parts = explode( '%d', $p, 2 );
	return '#^' . preg_quote( $parts[0], '#' ) . '\d+' . preg_quote( isset( $parts[1] ) ? $parts[1] : '', '#' ) . '$#';
}

/** Every product ID that counts as an eligible Free Fire purchase (PIN + direct/server). */
function delicat_ff_pin_ids() {
	static $set = null;
	if ( null !== $set ) return $set;
	$set = array();
	foreach ( delicat_ff_denoms() as $d ) {
		$id = delicat_pid_by_diamonds( $d );
		if ( $id ) $set[ $id ] = true;
	}
	foreach ( (array) delicat_cfg( 'extra_pins' ) as $id ) {
		$id = (int) $id;
		if ( $id > 0 ) $set[ $id ] = true;
	}
	foreach ( delicat_tiers() as $t ) {
		foreach ( $t['product_ids'] as $id ) $set[ (int) $id ] = true;
	}
	return $set;
}

function delicat_is_ff_pin( $product_id ) { // legacy name; v3 means any eligible Free Fire purchase
	$product_id = (int) $product_id;
	static $cache = array();
	if ( isset( $cache[ $product_id ] ) ) return $cache[ $product_id ];

	$set = delicat_ff_pin_ids();
	$is  = isset( $set[ $product_id ] );

	if ( ! $is && delicat_cfg( 'match_slug' ) ) {
		$rx = delicat_slug_regex();
		if ( $rx ) {
			/* A variation has its own meaningless slug — test the parent's. */
			$slug = (string) get_post_field( 'post_name', delicat_parent_pid( $product_id ) );
			$is   = (bool) preg_match( $rx, $slug );
		}
	}
	return $cache[ $product_id ] = (bool) apply_filters( 'delicat_is_ff_pin', $is, $product_id );
}

/** IDs reserved for PIN fulfilment (not the direct-top-up/server purchase map). */
function delicat_reward_pin_ids() {
	$set = array();
	foreach ( delicat_ff_denoms() as $d ) { $id = delicat_pid_by_diamonds( $d ); if ( $id ) $set[ $id ] = true; }
	return $set;
}

/** True when a product/variation is one of the PIN products used for fulfilment. */
function delicat_is_reward_pin( $product_id ) {
	$product_id = (int) $product_id; $set = delicat_reward_pin_ids();
	if ( isset( $set[ $product_id ] ) ) return true;
	/* A parent explicitly mapped as the PIN product covers its variations; an
	   explicitly mapped PIN variation does NOT classify its siblings as PINs. */
	if ( delicat_is_variation( $product_id ) ) { $parent = delicat_parent_pid( $product_id ); return isset( $set[ $parent ] ); }
	return false;
}

function delicat_item_is_reward_pin( $item ) {
	foreach ( delicat_item_ids( $item ) as $id ) if ( delicat_is_reward_pin( $id ) ) return true;
	return false;
}

/** True if this order line is any eligible Free Fire purchase — PIN or direct top-up/server. */
function delicat_item_is_ff( $item ) {
	foreach ( delicat_item_ids( $item ) as $id ) {
		if ( delicat_is_ff_pin( $id ) ) return true;
	}
	return delicat_item_pack_diamonds( $item ) > 0;
}

/* =========================================================================
   REWARD COMPOSITION — exact, minimal number of PINs (dynamic programming,
   so it stays correct whatever denominations you configure).
   ========================================================================= */
function delicat_product_capacity( $product ) {
	if ( ! $product || ! is_a( $product, 'WC_Product' ) ) return 0;
	if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) return 0;
	$cap = DELICAT_MAX_PIN_ITEMS;
	if ( $product->managing_stock() ) $cap = max( 0, min( $cap, (int) $product->get_stock_quantity() ) );

	$uses_delicat = function_exists( 'delicat_is_keyed' ) && delicat_is_keyed( $product->get_id() );
	$uses_wckm    = function_exists( 'wckm_is_keyed_product' ) && wckm_is_keyed_product( $product );
	if ( $uses_delicat ) {
		if ( ! function_exists( 'delicat_keys_available' ) ) return 0;
		$cap = max( 0, min( $cap, (int) delicat_keys_available( $product->get_id() ) ) );
	} elseif ( $uses_wckm && function_exists( 'wckm_get_key_stock' ) ) {
		// Free Fire PINs must be real preset codes, never generated license strings.
		if ( ! function_exists( 'wckm_get_key_source' ) || 'preset' !== wckm_get_key_source( $product ) ) return 0;
		$per_unit = function_exists( 'wckm_get_delivery_quantity' ) ? max( 1, (int) wckm_get_delivery_quantity( $product, 1 ) ) : 1;
		$cap = min( $cap, intdiv( max( 0, (int) wckm_get_key_stock( $product->get_id() ) ), $per_unit ) );
	} elseif ( function_exists( 'delicat_is_keyed' ) || function_exists( 'wckm_is_keyed_product' ) ) {
		// A configured PIN provider exists but this reward product is not attached
		// to one. Never create a zero-total reward that cannot deliver a PIN.
		return 0;
	}
	return $cap;
}

/** Resolve a PIN product/variation that can really deliver the requested quantity. */
function delicat_purchasable( $id, $qty = 1 ) {
	if ( ! function_exists( 'wc_get_product' ) ) return null;
	$qty = max( 1, (int) $qty );
	$product = wc_get_product( (int) $id );
	if ( ! $product ) return null;

	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && delicat_product_capacity( $v ) >= $qty ) return $v;
		}
		return null;
	}
	return delicat_product_capacity( $product ) >= $qty ? $product : null;
}

/** Max PIN units currently deliverable for one denomination. */
function delicat_reward_denom_capacity( $denom ) {
	$pid = delicat_pid_by_diamonds( (int) $denom );
	if ( ! $pid || ! function_exists( 'wc_get_product' ) ) return 0;
	$p = wc_get_product( $pid );
	if ( ! $p ) return 0;
	if ( $p->is_type( 'variable' ) ) {
		/* A reward line is fulfilled by one concrete variation, so the usable
		 * capacity is the best single variation, not the sum of sibling stock. */
		$best = 0;
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			$best = max( $best, delicat_product_capacity( $v ) );
		}
		return min( DELICAT_MAX_PIN_ITEMS, $best );
	}
	return delicat_product_capacity( $p );
}

/**
 * Exact minimal PIN composition. When $require_stock=true, current Woo/key
 * inventory is treated as a hard bound, preventing a reward order that cannot
 * actually be fulfilled.
 */
function delicat_reward_plan( $amount, $require_stock = false ) {
	$amount = (int) $amount;
	if ( $amount <= 0 ) return array();
	if ( $amount > DELICAT_MAX_REWARD ) return null;

	static $memo = array();
	$gen = delicat_cfg_gen();
	$key = $gen . ':' . $amount . ':' . ( $require_stock ? 'stock' : 'shape' );
	/* Stock can change independently of config, so never memoize stock-aware plans. */
	if ( ! $require_stock && isset( $memo[ $key ] ) ) return $memo[ $key ];

	$denoms = array();
	foreach ( delicat_ff_denoms() as $d ) {
		if ( $d <= 0 || ! delicat_pid_by_diamonds( $d ) ) continue;
		$cap = $require_stock ? delicat_reward_denom_capacity( $d ) : DELICAT_MAX_PIN_ITEMS;
		$cap = min( $cap, intdiv( $amount, $d ), DELICAT_MAX_PIN_ITEMS );
		if ( $cap > 0 ) $denoms[ $d ] = $cap;
	}
	if ( ! $denoms ) return $require_stock ? null : ( $memo[ $key ] = null );

	$best = array_fill( 0, $amount + 1, PHP_INT_MAX );
	$plans = array_fill( 0, $amount + 1, null );
	$best[0] = 0; $plans[0] = array();
	foreach ( $denoms as $d => $cap ) {
		$prev_best = $best; $prev_plans = $plans;
		for ( $sum = 0; $sum <= $amount; $sum++ ) {
			if ( PHP_INT_MAX === $prev_best[ $sum ] ) continue;
			for ( $q = 1; $q <= $cap && $sum + $q * $d <= $amount; $q++ ) {
				$ns = $sum + $q * $d; $cost = $prev_best[ $sum ] + $q;
				if ( $cost < $best[ $ns ] && $cost <= DELICAT_MAX_PIN_ITEMS ) {
					$best[ $ns ] = $cost;
					$pl = $prev_plans[ $sum ]; $pl[ $d ] = ( $pl[ $d ] ?? 0 ) + $q;
					$plans[ $ns ] = $pl;
				}
			}
		}
	}
	if ( PHP_INT_MAX === $best[ $amount ] || ! is_array( $plans[ $amount ] ) ) return $require_stock ? null : ( $memo[ $key ] = null );
	$counts = $plans[ $amount ]; krsort( $counts );
	$plan = array(); foreach ( $counts as $d => $q ) $plan[] = array( 'denom' => (int) $d, 'qty' => (int) $q );
	return $require_stock ? $plan : ( $memo[ $key ] = $plan );
}

/* =========================================================================
   TIERS
   ========================================================================= */
/** Raw tiers exactly as saved (used by the editor). Sorted by position. */
function delicat_all_tiers() {
	$tiers = (array) delicat_cfg( 'tiers' );
	uasort( $tiers, function ( $a, $b ) {
		return ( (int) ( $a['order'] ?? 0 ) ) <=> ( (int) ( $b['order'] ?? 0 ) );
	} );
	return $tiers;
}

/** Active tiers, with their resolved product IDs. This is what clients see. */
function delicat_tiers() {
	static $out = null;
	if ( null !== $out ) return $out;
	$out = array();
	foreach ( delicat_all_tiers() as $key => $t ) {
		$key = sanitize_key( $key );
		if ( '' === $key || empty( $t['enabled'] ) ) continue;

		$history = delicat_purchase_history();
		$ids = (array) ( $history[ $key ][ (int) ( $t['pin'] ?? 0 ) ] ?? array() );
		foreach ( (array) ( $t['ids'] ?? array() ) as $id ) {
			$id = absint( $id ); // Historical orders remain eligible even after product deletion.
			if ( $id ) $ids[] = $id;
		}
		/* Purchase sources and reward source are separate in v3. The tier always
		   counts its canonical PIN product PLUS every direct-top-up/server ID. */
		$fallback = delicat_pid_by_diamonds( (int) ( $t['pin'] ?? 0 ) );
		if ( $fallback ) $ids[] = $fallback;

		$out[ $key ] = array(
			'label'       => (string) ( $t['label'] ?? $key ),
			'title'       => (string) ( $t['title'] ?? 'Free Fire' ),
			'sub'         => (string) ( $t['sub'] ?? '' ),
			'pin'         => (int) ( $t['pin'] ?? 0 ),
			'goal'        => max( 1, min( DELICAT_MAX_GOAL, (int) ( $t['goal'] ?? 1 ) ) ),
			'reward'      => max( 0, min( DELICAT_MAX_REWARD, (int) ( $t['reward'] ?? 0 ) ) ),
			'max_claims'  => max( 0, (int) ( $t['max_claims'] ?? 0 ) ),
			'product_ids' => array_values( array_unique( $ids ) ),
		);
	}
	return $out = apply_filters( 'delicat_tiers', $out );
}

/* ---- Per-program ON/OFF switches ---- */
function delicat_rewards_on()  { return get_option( 'delicat_rewards_enabled', '1' ) === '1'; }
function delicat_meilleur_on() { return get_option( 'delicat_meilleur_enabled', '1' ) === '1'; }
function delicat_auto_award_on(){ return get_option( 'delicat_auto_award', '0' ) === '1'; }

function delicat_meilleur_prize() {
	$v = (int) delicat_cfg( 'prize' );
	return $v > 0 ? $v : 572;
}

/** Podium prizes, rank 1 → 3. Ranks 2/3 are 0 unless enabled in Réglages. */
function delicat_meilleur_prizes() {
	return array(
		1 => delicat_meilleur_prize(),
		2 => max( 0, (int) delicat_cfg( 'prize2' ) ),
		3 => max( 0, (int) delicat_cfg( 'prize3' ) ),
	);
}

function delicat_reset_day()  { return max( 0, min( 6,  (int) delicat_cfg( 'reset_day' ) ) ); }
function delicat_reset_hour() { return max( 0, min( 23, (int) delicat_cfg( 'reset_hour' ) ) ); }
function delicat_board_size() { return max( 1, min( 50, (int) delicat_cfg( 'board_size' ) ) ); }

/** Order statuses that count. Whitelisted against WooCommerce's own list. */
function delicat_counting_statuses() {
	if ( (int) delicat_cfg( 'strict_completed' ) === 1 ) return array( 'completed' );
	$want  = (array) delicat_cfg( 'statuses' );
	$valid = function_exists( 'wc_get_order_statuses' )
		? array_map( function ( $s ) { return preg_replace( '/^wc-/', '', $s ); }, array_keys( wc_get_order_statuses() ) )
		: array( 'completed', 'processing' );
	$out = array_values( array_intersect( array_map( 'sanitize_key', $want ), $valid ) );
	if ( ! $out ) $out = array( 'completed' );
	return apply_filters( 'delicat_counting_statuses', $out );
}

/* =========================================================================
   CACHES
   ========================================================================= */
function delicat_state_key( $user_id ) {
	return 'delicat_s4_' . delicat_cfg_gen() . '_' . (int) $user_id . '_' . wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
}
function delicat_user_state_cached( $user_id ) {
	$k = delicat_state_key( $user_id );
	$c = get_transient( $k );
	if ( false !== $c ) return $c;
	$state = delicat_compute_state( $user_id );
	set_transient( $k, $state, 15 * MINUTE_IN_SECONDS );
	return $state;
}
function delicat_flush_user( $user_id ) {
	delete_transient( delicat_state_key( $user_id ) );
}

/* Participation control (Participants dashboard). */
function delicat_is_excluded( $user_id ) {
	return get_user_meta( (int) $user_id, '_delicat_excluded', true ) === '1';
}
function delicat_set_excluded( $user_id, $excluded ) {
	if ( $excluded ) update_user_meta( (int) $user_id, '_delicat_excluded', '1' );
	else            delete_user_meta( (int) $user_id, '_delicat_excluded' );
	delicat_flush_user( $user_id );
	delete_transient( 'delicat_leaderboard' );
}

function delicat_paused_html() {
	return '<div style="max-width:520px;margin:24px auto;font-family:\'Poppins\',sans-serif;">'
		. '<div style="background:linear-gradient(160deg,#ecdde1,#e1cdd4);border-radius:18px;padding:34px 24px;text-align:center;box-shadow:0 18px 40px -22px rgba(51,33,90,.55);">'
		. '<div style="font-size:2.4rem;margin-bottom:10px;">🎁⏳</div>'
		. '<div style="font-family:\'Playfair Display\',serif;font-weight:800;font-size:1.5rem;color:#33215a;margin-bottom:8px;">Programme momentanément en pause</div>'
		. '<div style="color:#4d4654;font-size:1rem;line-height:1.5;">Notre programme de récompenses sera bientôt de retour.<br>Merci de votre patience et de votre fidélité ! 💜</div>'
		. '</div></div>';
}

/* Any order status change refreshes that client's cache + the leaderboard. */
add_action( 'woocommerce_order_status_changed', function ( $order_id, $from, $to, $order ) {
	$is_order = $order && is_a( $order, 'WC_Order' );
	$uid = $is_order ? $order->get_customer_id() : 0;
	/* Leaving completed still shows the original date_completed: keep it before a later
	   completion resets it. */
	if ( $is_order && ( 'completed' === $from || 'completed' === $to ) ) delicat_remember_first_completion( $order );
	if ( $uid ) delicat_flush_user( $uid );
	delicat_leaderboard_touch();
	/* Cancelled, refunded, failed or reopened after counting: purchases may be owed back. */
	if ( $is_order && 'completed' === $from && 'completed' !== $to ) delicat_refund_after_claim( $order_id );
}, 10, 4 );

/* A refund removes the progress it created — immediately, not after the cache expires. */
function delicat_resync_order( $order_id ) {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	$uid   = ( $order && is_a( $order, 'WC_Abstract_Order' ) ) ? (int) $order->get_customer_id() : 0;
	if ( $uid ) delicat_flush_user( $uid );
	delicat_leaderboard_touch();
}
add_action( 'woocommerce_order_refunded',        'delicat_resync_order', 10, 1 );
add_action( 'woocommerce_order_refunded',        'delicat_refund_after_claim', 20, 1 ); // runs after a full refund's status change
add_action( 'woocommerce_order_fully_refunded',  'delicat_resync_order', 10, 1 );
add_action( 'woocommerce_payment_complete',      'delicat_resync_order', 10, 1 );
add_action( 'woocommerce_order_partially_refunded', 'delicat_resync_order', 10, 1 );

/* =========================================================================
   TIME WINDOWS
   ========================================================================= */
function delicat_month_start() {
	return ( new DateTimeImmutable( 'first day of this month 00:00:00', wp_timezone() ) )->getTimestamp();
}
/**
 * Real UTC timestamp of the last weekly reset (reset day + hour, site time zone).
 * Up to 3.2.1 this built "local date 20:00" as if it were UTC from current_time(), so
 * every window and cutoff was shifted by the site's UTC offset while WooCommerce compares
 * order dates in real UTC. Day arithmetic keeps the wall-clock hour across DST changes.
 */
function delicat_week_start( $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( delicat_reset_hour(), 0, 0 );
	$back  = ( (int) $local->format( 'w' ) - delicat_reset_day() + 7 ) % 7;
	if ( $back > 0 ) $local = $local->modify( '-' . $back . ' days' );
	if ( $local->getTimestamp() > $now ) $local = $local->modify( '-7 days' );
	return $local->getTimestamp();
}
/** The reset one week before $boundary, same wall-clock hour (167 or 169 hours around DST). */
function delicat_previous_week_start( $boundary ) {
	return ( new DateTimeImmutable( '@' . (int) $boundary ) )->setTimezone( wp_timezone() )->modify( '-7 days' )->getTimestamp();
}
/** The next weekly reset after $boundary. */
function delicat_next_week_start( $boundary = null ) {
	$boundary = null === $boundary ? delicat_week_start() : (int) $boundary;
	return ( new DateTimeImmutable( '@' . $boundary ) )->setTimezone( wp_timezone() )->modify( '+7 days' )->getTimestamp();
}
/** The month key (Y-m, site time zone) of a real timestamp. */
function delicat_month_key( $ts ) {
	return wp_date( 'Y-m', (int) $ts, wp_timezone() );
}
/** Real [start, end] timestamps of a Y-m month in the site time zone. */
function delicat_month_bounds( $month ) {
	$first = new DateTimeImmutable( $month . '-01 00:00:00', wp_timezone() );
	return array( $first->getTimestamp(), $first->modify( '+1 month' )->getTimestamp() - 1 );
}
/** The Y-m month $delta months away from $month (negative = earlier). */
function delicat_month_shift( $month, $delta ) {
	return ( new DateTimeImmutable( $month . '-01 00:00:00', wp_timezone() ) )->modify( ( (int) $delta >= 0 ? '+' : '' ) . (int) $delta . ' month' )->format( 'Y-m' );
}

/* =========================================================================
   INTEGRITY — HMAC-SHA256 signatures (per-install key + WordPress salts)

   What this protects: the reward counters and the claim requests. Nobody —
   not a stolen cookie or a forged browser request can raise a client's claim
   counter or replay a claim without the secret key. A fully compromised
   WordPress/database administrator remains outside this integrity boundary. Any mismatch freezes claims for that account
   and warns you instead of silently handing out diamonds.
   ========================================================================= */
/** Salt-derived key used up to 3.2.1. $create = false never creates the stored half. */
function delicat_legacy_secret( $create = true ) {
	$k = get_option( 'delicat_hmac_key' );
	if ( ! is_string( $k ) || strlen( $k ) < 40 ) {
		if ( ! $create ) return '';
		try {
			$k = bin2hex( random_bytes( 32 ) );          // CSPRNG
		} catch ( \Throwable $e ) {
			$k = wp_generate_password( 64, true, true ); // fallback
		}
		update_option( 'delicat_hmac_key', $k, false );  // never autoloaded
	}
	/* The stored key alone is useless without the salts in wp-config.php. */
	return hash_hmac( 'sha256', $k, wp_salt( 'secure_auth' ) );
}

/**
 * Signing keys, current first. The salt-derived key froze every customer's bonuses for the
 * month whenever the WordPress salts changed. A DELICAT_REWARDS_HMAC_KEY constant (32+
 * characters in wp-config.php) signs without the salts; DELICAT_REWARDS_HMAC_PREVIOUS_KEYS
 * (comma separated) keeps an older constant valid during a rotation. Once the constant
 * exists, the salt-derived key is still accepted and a ledger read with it is re-sealed.
 * Old salts cannot be recomputed: after a salt change without the constant, use
 * « Re-sceller les registres du mois » (Diagnostics).
 */
function delicat_secret_ring() {
	static $ring = null;
	if ( null !== $ring ) return $ring;
	$derive  = function ( $key ) { return hash_hmac( 'sha256', 'delicat-rewards|ledger', (string) $key ); };
	$current = defined( 'DELICAT_REWARDS_HMAC_KEY' ) && is_string( DELICAT_REWARDS_HMAC_KEY ) && strlen( DELICAT_REWARDS_HMAC_KEY ) >= 32 ? $derive( DELICAT_REWARDS_HMAC_KEY ) : '';
	$legacy  = delicat_legacy_secret( '' === $current );
	$ring    = array( '' !== $current ? $current : $legacy );
	if ( defined( 'DELICAT_REWARDS_HMAC_PREVIOUS_KEYS' ) && is_string( DELICAT_REWARDS_HMAC_PREVIOUS_KEYS ) ) {
		foreach ( preg_split( '/[\s,]+/', DELICAT_REWARDS_HMAC_PREVIOUS_KEYS, -1, PREG_SPLIT_NO_EMPTY ) as $old ) {
			if ( strlen( $old ) >= 32 ) $ring[] = $derive( $old );
		}
	}
	if ( '' !== $current && '' !== $legacy ) $ring[] = $legacy;
	return $ring = array_values( array_unique( array_filter( $ring ) ) );
}

function delicat_secret() {
	$ring = delicat_secret_ring();
	return $ring[0];
}

/** Short public fingerprint of a key, to tell a key change from a tampered counter. */
function delicat_key_fingerprint( $secret ) {
	return substr( hash( 'sha256', 'delicat-fp|' . (string) $secret ), 0, 16 );
}

/**
 * True when the signing key changed and the previous one is gone (salts rotated without
 * DELICAT_REWARDS_HMAC_KEY): ledgers sealed before are frozen until re-sealed.
 */
function delicat_key_changed() {
	$stored = (string) get_option( 'delicat_hmac_fingerprint', '' );
	$now    = delicat_key_fingerprint( delicat_secret() );
	if ( '' === $stored ) { update_option( 'delicat_hmac_fingerprint', $now, false ); return false; }
	if ( hash_equals( $stored, $now ) ) return false;
	foreach ( delicat_secret_ring() as $secret ) {
		// A planned change: the previous key is still accepted and ledgers re-seal on read.
		if ( hash_equals( $stored, delicat_key_fingerprint( $secret ) ) ) { update_option( 'delicat_hmac_fingerprint', $now, false ); return false; }
	}
	return true;
}

function delicat_sign( $data ) {
	$payload = is_string( $data ) ? $data : (string) wp_json_encode( $data );
	return hash_hmac( 'sha256', $payload, delicat_secret() );
}

/** Constant-time comparison — no timing side-channel. */
function delicat_sig_ok( $expected, $given ) {
	return is_string( $given ) && hash_equals( (string) $expected, $given );
}

/** Raise a red flag the admin cannot miss. */
function delicat_tamper_flag( $user_id, $what ) {
	$log = get_option( 'delicat_tamper', array() );
	if ( ! is_array( $log ) ) $log = array();
	array_unshift( $log, array( 't' => current_time( 'mysql' ), 'u' => (int) $user_id, 'w' => (string) $what ) );
	update_option( 'delicat_tamper', array_slice( $log, 0, 30 ), false );
	delicat_audit_add( sprintf( '⛔ INTÉGRITÉ : %s (client #%d)', $what, (int) $user_id ) );
}

/* ---- Signed claim ledger (replaces the raw claim counters) ---- */
function delicat_ledger_key( $month ) { return '_delicat_ledger_' . $month; }

/**
 * Returns array( 'claims' => array( tier => n ), 'ok' => bool ).
 * 'ok' === false means the stored counters were altered outside this plugin.
 */
function delicat_ledger_migration_key( $month ) { return '_delicat_ledger_migrated_' . $month; }
function delicat_ledger_integrity_fail( $user_id, $month, $what ) {
	/* A changed signing key (salts rotated) is not fraud: say so, the fix is to re-seal. */
	if ( delicat_key_changed() ) $what = 'Clé de signature changée (salts WordPress modifiés ?) — registre à re-sceller depuis Diagnostics.';
	$mk = '_delicat_integrity_alert_' . sanitize_key( $month ); $fingerprint = md5( (string) $what );
	if ( get_user_meta( (int) $user_id, $mk, true ) !== $fingerprint ) {
		delicat_tamper_flag( (int) $user_id, (string) $what ); update_user_meta( (int) $user_id, $mk, $fingerprint );
	}
	return array( 'claims' => array(), 'debt' => array(), 'ok' => false, 'reward_total' => 0 );
}

/** Index in delicat_secret_ring() of the key that signed this payload, or -1. */
function delicat_ledger_key_index( $user_id, $month, array $c, $sig ) {
	if ( ! is_string( $sig ) ) return -1;
	$payload = (string) wp_json_encode( array( 'u' => (int) $user_id, 'm' => (string) $month, 'c' => $c ) );
	foreach ( delicat_secret_ring() as $i => $secret ) {
		if ( hash_equals( hash_hmac( 'sha256', $payload, $secret ), $sig ) ) return (int) $i;
	}
	return -1;
}

/**
 * Read-only view of a month's ledger: never creates, migrates or reconciles it.
 * 'claims' and 'debt' are parsed even when 'ok' is false (re-seal never lowers them);
 * callers must check 'ok' before trusting them.
 */
function delicat_ledger_peek( $user_id, $month ) {
	$out = array( 'exists' => false, 'ok' => true, 'key' => -1, 'claims' => array(), 'debt' => array() );
	$raw = get_user_meta( (int) $user_id, delicat_ledger_key( $month ), true );
	if ( ! is_array( $raw ) || ! isset( $raw['c'], $raw['sig'] ) || ! is_array( $raw['c'] ) ) return $out;
	$out['exists'] = true;
	$out['key']    = delicat_ledger_key_index( $user_id, $month, $raw['c'], $raw['sig'] );
	$out['ok']     = $out['key'] >= 0;
	foreach ( $raw['c'] as $k => $v ) {
		if ( DELICAT_LEDGER_DEBT_KEY === $k ) {
			foreach ( is_array( $v ) ? $v : array() as $tier => $n ) {
				$tier = sanitize_key( $tier );
				if ( '' !== $tier && (int) $n > 0 ) $out['debt'][ $tier ] = (int) $n;
			}
			continue;
		}
		$k = sanitize_key( $k ); if ( '' !== $k ) $out['claims'][ $k ] = max( 0, (int) $v );
	}
	return $out;
}

/** Actual completed reward orders are a second, WooCommerce-backed source of truth. */
function delicat_reward_order_snapshot( $user_id, $month ) {
	$empty = array( 'claims' => array(), 'diamonds' => 0, 'money' => 0 );
	if ( ! function_exists( 'wc_get_orders' ) || ! preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ) return $empty;
	$boundary = new DateTimeImmutable( $month . '-01 00:00:00', wp_timezone() );
	$start = $boundary->getTimestamp(); $end = $boundary->modify( '+1 month' )->getTimestamp() - 1;
	$args = array( 'customer_id' => (int) $user_id, 'date_created' => $start . '...' . $end, 'orderby' => 'date', 'order' => 'ASC' );
	$orders = delicat_orders_paged( $args, 100, 1000 ); $out = array(); $diamonds = 0; $money = 0; $tiers = array_merge( delicat_tier_history(), delicat_all_tiers() );
	foreach ( $orders as $o ) {
		if ( 'completed' !== $o->get_status() && 'wallet' !== $o->get_meta( '_delicat_reward_delivery' ) ) continue;
		$tag  = sanitize_key( (string) $o->get_meta( '_delicat_reward' ) );
		$kind = sanitize_key( (string) $o->get_meta( '_delicat_reward_kind' ) );
		if ( 'weekly' === $kind || 0 === strpos( $tag, 'weekly_' ) ) continue; // podium does not consume the monthly tier cap
		if ( '' === $tag ) continue;
		if ( 'tier' === $kind || isset( $tiers[ $tag ] ) ) {
			$out[ $tag ] = ( $out[ $tag ] ?? 0 ) + 1;
			$amt = (int) $o->get_meta( '_delicat_reward_amount' );
			if ( $amt < 1 && isset( $tiers[ $tag ] ) ) $amt = (int) ( $tiers[ $tag ]['reward'] ?? 0 );
			$diamonds += max( 0, $amt );
			if ( 'wallet' === $o->get_meta( '_delicat_reward_delivery' ) && $o->get_meta( '_delicat_wallet_tx' ) ) $money += (float) $o->get_meta( '_delicat_wallet_amount' );
		}
	}
	return array( 'claims' => $out, 'diamonds' => $diamonds, 'money' => round( $money, 2 ) );
}
function delicat_reward_order_claims( $user_id, $month ) {
	$s = delicat_reward_order_snapshot( $user_id, $month ); return $s['claims'];
}

function delicat_ledger_get( $user_id, $month ) {
	$user_id = (int) $user_id; $month = (string) $month;
	$peek = delicat_ledger_peek( $user_id, $month );
	$migrated = (bool) get_user_meta( $user_id, delicat_ledger_migration_key( $month ), true );

	if ( $peek['exists'] ) {
		if ( ! $peek['ok'] ) return delicat_ledger_integrity_fail( $user_id, $month, 'Signature du registre de récompenses invalide — réclamations gelées.' );
		$claims = $peek['claims']; $debt = $peek['debt'];
		if ( ! $migrated ) update_user_meta( $user_id, delicat_ledger_migration_key( $month ), 1 );
		/* Never let a stale ledger under-count already-issued Woo reward orders. */
		$snapshot = delicat_reward_order_snapshot( $user_id, $month ); $changed = false;
		foreach ( $snapshot['claims'] as $k => $n ) if ( $n > ( $claims[ $k ] ?? 0 ) ) { $claims[ $k ] = $n; $changed = true; }
		if ( $changed ) delicat_log( 'warning', 'Reward ledger reconciled from WooCommerce reward orders.', array( 'user_id' => $user_id, 'month' => $month ) );
		/* Sealed with a previous key that is still accepted: re-seal with the current one. */
		if ( $changed || $peek['key'] > 0 ) delicat_ledger_save( $user_id, $month, $claims, $debt );
		return array( 'claims' => $claims, 'debt' => $debt, 'ok' => true, 'reward_total' => (int) $snapshot['diamonds'], 'wallet_total' => (float) ( $snapshot['money'] ?? 0 ) );
	}

	/* Once migrated, a missing signed ledger is an integrity failure, not a reset. */
	if ( $migrated ) return delicat_ledger_integrity_fail( $user_id, $month, 'Registre signé manquant après migration — réclamations gelées.' );

	/* One-time legacy migration, immediately sealed into the signed ledger. */
	$claims = array();
	foreach ( array_keys( array_merge( delicat_tier_history(), delicat_all_tiers() ) ) as $k ) { $n = (int) get_user_meta( $user_id, "_delicat_claimed_{$month}_{$k}", true ); if ( $n > 0 ) $claims[ $k ] = $n; }
	$snapshot = delicat_reward_order_snapshot( $user_id, $month );
	foreach ( $snapshot['claims'] as $k => $n ) if ( $n > ( $claims[ $k ] ?? 0 ) ) $claims[ $k ] = $n;
	/* A new month starts with what the previous month still owes after refunds. */
	$debt = delicat_debt_carry( $user_id, $month );
	delicat_ledger_save( $user_id, $month, $claims, $debt );
	update_user_meta( $user_id, delicat_ledger_migration_key( $month ), 1 );
	return array( 'claims' => $claims, 'debt' => $debt, 'ok' => true, 'reward_total' => (int) $snapshot['diamonds'], 'wallet_total' => (float) ( $snapshot['money'] ?? 0 ) );
}

/**
 * $debt = purchases still owed per tier (signed under DELICAT_LEDGER_DEBT_KEY inside 'c').
 * null keeps the debt already stored; pass the verified value whenever you have it.
 */
function delicat_ledger_save( $user_id, $month, array $claims, $debt = null ) {
	$c = array();
	foreach ( $claims as $k => $v ) {
		if ( DELICAT_LEDGER_DEBT_KEY === $k ) continue;
		$k = sanitize_key( $k );
		$v = (int) $v;
		if ( '' !== $k && $v > 0 ) $c[ $k ] = $v;
	}
	if ( null === $debt ) { $stored = delicat_ledger_peek( $user_id, $month ); $debt = $stored['debt']; }
	$owed = array();
	foreach ( (array) $debt as $k => $v ) {
		$k = sanitize_key( $k );
		$v = min( 1000000, (int) $v );
		if ( '' !== $k && $v > 0 ) $owed[ $k ] = $v;
	}
	ksort( $owed );
	if ( $owed ) $c[ DELICAT_LEDGER_DEBT_KEY ] = $owed;
	ksort( $c );
	update_user_meta( (int) $user_id, delicat_ledger_key( $month ), array(
		'c'   => $c,
		't'   => time(),
		'sig' => delicat_sign( array( 'u' => (int) $user_id, 'm' => (string) $month, 'c' => $c ) ),
	) );
	update_user_meta( (int) $user_id, delicat_ledger_migration_key( $month ), 1 );
	if ( '' === (string) get_option( 'delicat_hmac_fingerprint', '' ) ) update_option( 'delicat_hmac_fingerprint', delicat_key_fingerprint( delicat_secret() ), false );
	unset( $c[ DELICAT_LEDGER_DEBT_KEY ] );
	/* Fully synchronize legacy keys so rollback/removal cannot leave stale counters. */
	$legacy_keys = array_unique( array_merge( array_keys( delicat_tier_history() ), array_keys( delicat_all_tiers() ), array_keys( $c ) ) );
	foreach ( $legacy_keys as $k ) {
		$v = isset( $c[ $k ] ) ? (int) $c[ $k ] : 0;
		if ( $v > 0 ) update_user_meta( (int) $user_id, "_delicat_claimed_{$month}_{$k}", $v );
		else delete_user_meta( (int) $user_id, "_delicat_claimed_{$month}_{$k}" );
	}
}

/* ---- Short-lived signed claim token (anti-replay, anti-forgery) ---- */
function delicat_token_make( $user_id, $tier, $month, $claimed ) {
	$exp  = time() + DELICAT_TOKEN_TTL;
	$body = (int) $user_id . '|' . $tier . '|' . $month . '|' . (int) $claimed . '|' . $exp;
	return $exp . '.' . (int) $claimed . '.' . delicat_sign( 'claim|' . $body );
}

function delicat_token_ok( $token, $user_id, $tier, $month, $claimed ) {
	if ( ! is_string( $token ) ) return false;
	$parts = explode( '.', $token );
	if ( 3 !== count( $parts ) ) return false;
	list( $exp, $n, $sig ) = $parts;
	if ( ! ctype_digit( $exp ) || ! ctype_digit( $n ) ) return false;
	if ( (int) $exp < time() ) return false;                 // expired
	if ( (int) $n !== (int) $claimed ) return false;         // already used since it was issued
	$body = (int) $user_id . '|' . $tier . '|' . $month . '|' . (int) $n . '|' . (int) $exp;
	return delicat_sig_ok( delicat_sign( 'claim|' . $body ), $sig );
}

/* =========================================================================
   WHAT COUNTS AS A REAL PURCHASE
   A diamond is only added when money really arrived and stayed.
   ========================================================================= */
function delicat_order_ok( $order ) {
	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) return false; // 4.1.0: a refund object (no customer) never counts
	if ( $order->get_meta( '_delicat_reward' ) )   return false; // free reward orders never count
	if ( ! (int) $order->get_customer_id() )       return false; // guest orders never count
	if ( ! delicat_order_is_retail( $order ) )     return false; // reseller API mirrors and staff orders never count
	if ( (int) delicat_cfg( 'strict_completed' ) === 1 && 'completed' !== $order->get_status() ) return false;

	if ( delicat_cfg( 'require_paid' ) ) {
		/* WooCommerce is authoritative. Some valid gateways/status automations do
		 * not persist date_paid reliably, so prefer Woo's paid-state API. */
		if ( method_exists( $order, 'is_paid' ) ) {
			if ( ! $order->is_paid() ) return false;
		} else {
			$paid = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;
			if ( ! $paid ) return false;
		}
	}
	if ( delicat_cfg( 'ignore_refund' ) ) {
		$total = (float) $order->get_total();
		if ( $total > 0 && (float) $order->get_total_refunded() >= $total ) return false; // fully refunded
	}
	return (bool) apply_filters( 'delicat_order_counts', true, $order );
}

/**
 * Only real retail purchases earn loyalty. A reseller API mirror order belongs to the
 * reseller's account at reseller price, and a staff account's order is a test or an
 * internal sale: both used to earn tier PINs and win the weekly Meilleur Client prize.
 */
function delicat_order_is_retail( $order ) {
	static $staff = array();
	$sources = (array) apply_filters( 'delicat_rewards_excluded_sources', array( 'delicat_reseller_api', 'delicat-rewards', 'delicat_rewards' ) );
	$via     = method_exists( $order, 'get_created_via' ) ? (string) $order->get_created_via() : '';
	if ( '' !== $via && in_array( $via, $sources, true ) ) return false;
	if ( ! empty( $order->get_meta( '_dfr_reseller_api_order' ) ) ) return false;
	$uid = (int) $order->get_customer_id();
	if ( $uid > 0 ) {
		if ( ! isset( $staff[ $uid ] ) ) {
			$cap = (string) apply_filters( 'delicat_rewards_staff_cap', 'manage_woocommerce' );
			$staff[ $uid ] = '' !== $cap && function_exists( 'user_can' ) && user_can( $uid, $cap );
		}
		if ( $staff[ $uid ] ) return false;
	}
	return (bool) apply_filters( 'delicat_rewards_order_is_retail', true, $order );
}

/**
 * Share (0..1) of the order's remaining value refunded without naming a line. The owner
 * often refunds a failed supplier line by amount; quantities stay untouched, so such a
 * refund used to be ignored unless it reached the whole order total.
 */
function delicat_order_refund_share( $order ) {
	if ( ! delicat_cfg( 'ignore_refund' ) || ! is_object( $order ) || ! method_exists( $order, 'get_total_refunded' ) ) return 0.0;
	$refunded = (float) $order->get_total_refunded();
	if ( $refunded < 0.01 ) return 0.0;
	$itemized = 0.0;
	if ( method_exists( $order, 'get_total_refunded_for_item' ) ) {
		foreach ( array( 'line_item', 'fee', 'shipping' ) as $type ) {
			foreach ( $order->get_items( $type ) as $item_id => $unused ) $itemized += abs( (float) $order->get_total_refunded_for_item( (int) $item_id, $type ) );
		}
	}
	if ( method_exists( $order, 'get_total_tax_refunded' ) ) $itemized += abs( (float) $order->get_total_tax_refunded() );
	$loose = $refunded - $itemized;
	if ( $loose < 0.01 ) return 0.0;
	$base = (float) $order->get_total() - $itemized;
	return $base < 0.01 ? 1.0 : min( 1.0, $loose / $base );
}

/**
 * What one order really contributes: eligible Free Fire units and net spend, and units per
 * tier. Line refunds come off each line (delicat_item_qty/delicat_item_spend). An
 * amount-only refund removes the same share of the order's units, rounded against the
 * customer: a small goodwill refund keeps most of a 25-unit order, but a refunded unit
 * never keeps counting.
 */
function delicat_order_units( $order, array $tiers ) {
	$out = array( 'qty' => 0, 'spend' => 0.0, 'tiers' => array() );
	$free_counts = (bool) apply_filters( 'delicat_free_lines_count', false );
	foreach ( $order->get_items() as $item ) {
		$qty = delicat_item_qty( $order, $item );
		if ( $qty < 1 || ! delicat_item_delivered( $order, $item ) ) continue;
		/* 3.3.0: a line nobody paid for (100 % coupon, stacked coupons, a $0 order made by hand)
		   earns no progress. Checked before refunds, which keep their own rule below. */
		if ( ! $free_counts && (float) $item->get_total() <= 0 ) continue;
		if ( delicat_item_is_ff( $item ) ) { $out['qty'] += $qty; $out['spend'] += delicat_item_spend( $order, $item ); }
		$ids = delicat_item_ids( $item ); // variation id + parent id
		$matched = array();
		foreach ( $tiers as $key => $t ) {
			if ( array_intersect( $ids, (array) $t['product_ids'] ) ) $matched[] = $key;
		}
		if ( ! $matched ) {
			$diamonds = delicat_item_pack_diamonds( $item );
			foreach ( $tiers as $key => $t ) {
				if ( $diamonds > 0 && $diamonds === (int) $t['pin'] ) $matched[] = $key;
			}
			// Duplicate pack configurations require explicit IDs; never award ambiguously.
			if ( count( $matched ) !== 1 ) $matched = array();
		}
		foreach ( $matched as $key ) $out['tiers'][ $key ] = ( $out['tiers'][ $key ] ?? 0 ) + $qty;
	}
	$share = delicat_order_refund_share( $order );
	if ( $share > 0 ) {
		$keep = function ( $n ) use ( $share ) { return max( 0, (int) $n - (int) ceil( $n * $share - 1e-9 ) ); };
		$out['qty']   = $keep( $out['qty'] );
		$out['spend'] = max( 0.0, $out['spend'] * ( 1 - $share ) );
		foreach ( $out['tiers'] as $key => $n ) $out['tiers'][ $key ] = $keep( $n );
	}
	return $out;
}

/**
 * When the order earned progress. WooCommerce resets date_completed each time an order is
 * completed again, so an order moved back and forth would count in a second month or week:
 * the first completion is kept in _delicat_first_completed_at and wins.
 */
function delicat_order_counted_at( $order ) {
	$key = delicat_order_window_key();
	if ( 'date_completed' === $key ) {
		$first = (int) $order->get_meta( '_delicat_first_completed_at' );
		if ( $first > 0 ) return $first;
	}
	$method = 'date_completed' === $key ? 'get_date_completed' : ( 'date_paid' === $key ? 'get_date_paid' : 'get_date_created' );
	$date   = method_exists( $order, $method ) ? $order->{$method}() : null;
	return $date && method_exists( $date, 'getTimestamp' ) ? (int) $date->getTimestamp() : 0;
}
function delicat_order_in_window( $order, $start, $end = null ) {
	$at = delicat_order_counted_at( $order );
	return $at > 0 && $at >= (int) $start && ( null === $end || $at <= (int) $end );
}
function delicat_remember_first_completion( $order ) {
	if ( ! $order || ! is_a( $order, 'WC_Order' ) || $order->get_meta( '_delicat_reward' ) || (int) $order->get_meta( '_delicat_first_completed_at' ) > 0 ) return;
	$date = $order->get_date_completed();
	$ts   = $date ? (int) $date->getTimestamp() : 0;
	if ( $ts < 1 ) return;
	$order->update_meta_data( '_delicat_first_completed_at', $ts );
	$order->save_meta_data();
}

/** Quantity that really stayed bought: ordered minus refunded. */
function delicat_item_qty( $order, $item ) {
	$qty = (int) $item->get_quantity();
	if ( delicat_cfg( 'ignore_refund' ) && method_exists( $order, 'get_qty_refunded_for_item' ) ) {
		$qty += (int) $order->get_qty_refunded_for_item( $item->get_id() ); // negative or 0
	}
	return max( 0, $qty );
}

/** Money that really stayed spent on this line. */
function delicat_item_spend( $order, $item ) {
	$net = (float) $item->get_total();
	if ( delicat_cfg( 'ignore_refund' ) && method_exists( $order, 'get_total_refunded_for_item' ) ) {
		$net -= (float) $order->get_total_refunded_for_item( $item->get_id() );
	}
	return max( 0.0, $net );
}

/**
 * Optional PIN-delivery verification. Direct top-up purchases do not have a PIN
 * payload: for them a paid COMPLETED WooCommerce order is the delivery proof.
 */
function delicat_item_delivered( $order, $item ) {
	if ( ! delicat_cfg( 'require_deliv' ) ) return true;
	if ( ! delicat_item_is_reward_pin( $item ) ) return 'completed' === $order->get_status();
    if ( function_exists( 'wckm_get_order_keys' ) && 'yes' === $item->get_meta( '_wckm_order_item', true ) ) return count( delicat_reward_item_codes( $order, $item ) ) >= delicat_reward_item_expected( $item );
	foreach ( array( '_delicat_keys', '_delicat_codes', 'delicat_keys', '_license_keys', '_wc_keys' ) as $m ) {
		$v = $item->get_meta( $m, true );
		if ( ! empty( $v ) ) return true;
	}
	if ( function_exists( 'delicat_item_has_keys' ) ) return (bool) delicat_item_has_keys( $item );
	return (bool) apply_filters( 'delicat_item_delivered', false, $order, $item );
}

/* =========================================================================
   PROGRESS — the server is the only source of truth
   ========================================================================= */
function delicat_orders_paged( array $args, $page_size = 200, $max = 5000 ) {
	if ( ! function_exists( 'wc_get_orders' ) ) return array();
	$out = array(); $page = 1; $page_size = max( 20, min( 500, (int) $page_size ) );
	while ( count( $out ) < $max ) {
		$q = $args; $q['limit'] = min( $page_size, $max - count( $out ) ); $q['page'] = $page;
		$batch = wc_get_orders( $q );
		if ( ! is_array( $batch ) || ! $batch ) break;
		$out = array_merge( $out, $batch );
		if ( count( $batch ) < $q['limit'] ) break;
		$page++;
	}
	return $out;
}

/* v3 eligibility begins when WooCommerce marks the order COMPLETED. This
 * matches the business rule "payment approved + order received" and avoids
 * assigning a delayed order to the month/week in which it was merely created. */
function delicat_order_window_key() {
	return (int) delicat_cfg( 'strict_completed' ) === 1 ? 'date_completed' : ( delicat_cfg( 'require_paid' ) ? 'date_paid' : 'date_created' );
}
function delicat_order_window_args( $start, $end = null ) {
	return array( delicat_order_window_key() => null === $end ? '>=' . (int) $start : (int) $start . '...' . (int) $end );
}
/**
 * Orders that may belong to a window starting at $start. Open-ended on purpose: an order
 * first completed in the window but completed again later must still be found. Callers
 * keep only delicat_order_in_window() matches.
 */
function delicat_order_window_query( $start ) {
	return array( delicat_order_window_key() => '>=' . (int) $start );
}

/**
 * 4.1.0: the accounts of a page of orders in two queries (users + their meta) instead of two per customer:
 * the leaderboard rebuild reads each customer's exclusion, staff role and name.
 */
function delicat_prime_customers( array $orders ) {
	if ( ! function_exists( 'cache_users' ) ) return;
	$ids = array();
	foreach ( $orders as $o ) {
		if ( is_object( $o ) && method_exists( $o, 'get_customer_id' ) && (int) $o->get_customer_id() > 0 ) $ids[ (int) $o->get_customer_id() ] = true;
	}
	if ( count( $ids ) > 1 ) cache_users( array_keys( $ids ) );
}

/** Walks wc_get_orders() page by page, so a week of orders is never held in one array. */
function delicat_orders_each( array $args, callable $callback, $page_size = 200, $max = 5000 ) {
	if ( ! function_exists( 'wc_get_orders' ) ) return 0;
	$seen = 0; $page = 1; $page_size = max( 20, min( 500, (int) $page_size ) );
	while ( $seen < $max ) {
		$q = $args; $q['limit'] = $page_size; $q['page'] = $page;
		$batch = wc_get_orders( $q );
		if ( ! is_array( $batch ) || ! $batch ) break;
		$count = count( $batch );
		delicat_prime_customers( $batch );
		foreach ( array_slice( $batch, 0, $max - $seen ) as $order ) $callback( $order );
		$seen += $count; unset( $batch );
		if ( $count < $page_size ) break;
		$page++;
	}
	return $seen;
}

/** Eligible units per active tier and Free Fire net spend of one customer over [start, end]. */
function delicat_user_window_units( $user_id, $start, $end = null, $tiers = null ) {
	$user_id = (int) $user_id;
	$tiers   = null === $tiers ? delicat_tiers() : $tiers;
	$out     = array( 'counts' => array_fill_keys( array_keys( $tiers ), 0 ), 'spend' => 0.0 );
	if ( $user_id < 1 ) return $out;
	$args = array_merge( array( 'customer_id' => $user_id, 'status' => delicat_counting_statuses(), 'orderby' => 'date', 'order' => 'DESC' ), delicat_order_window_query( $start ) );
	delicat_orders_each( $args, function ( $order ) use ( &$out, $tiers, $user_id, $start, $end ) {
		if ( ! delicat_order_in_window( $order, $start, $end ) ) return;
		if ( ! delicat_order_ok( $order ) || (int) $order->get_customer_id() !== $user_id ) return; // not paid / refunded / guest / reward / reseller / staff
		$units = delicat_order_units( $order, $tiers );
		$out['spend'] += $units['spend'];
		foreach ( $units['tiers'] as $key => $n ) $out['counts'][ $key ] = ( $out['counts'][ $key ] ?? 0 ) + $n;
	} );
	return $out;
}

/**
 * Purchases still owed when $month starts: the previous month's claims times their goal,
 * plus what that month itself carried in, minus the purchases that stayed bought there.
 * The previous ledger is only read, never created; a frozen one carries nothing until it
 * is re-sealed.
 */
function delicat_debt_carry( $user_id, $month, $tiers = null ) {
	$prev   = delicat_month_shift( $month, -1 );
	$ledger = delicat_ledger_peek( $user_id, $prev );
	if ( ! $ledger['ok'] || ( ! array_filter( $ledger['claims'] ) && ! $ledger['debt'] ) ) return array();
	$tiers = null === $tiers ? delicat_tiers() : $tiers;
	list( $start, $end ) = delicat_month_bounds( $prev );
	$units = delicat_user_window_units( $user_id, $start, $end, $tiers );
	$debt  = array();
	foreach ( $tiers as $key => $t ) {
		$owed = (int) ( $ledger['claims'][ $key ] ?? 0 ) * (int) $t['goal'] + (int) ( $ledger['debt'][ $key ] ?? 0 ) - (int) ( $units['counts'][ $key ] ?? 0 );
		if ( $owed > 0 ) $debt[ $key ] = $owed;
	}
	/* A tier that is no longer active cannot be recounted: it keeps what it owed. */
	foreach ( $ledger['debt'] as $key => $n ) if ( ! isset( $tiers[ $key ] ) ) $debt[ $key ] = (int) $n;
	return $debt;
}

/** Re-signs the carried debt of each month after $from up to $to. Caller holds the claim lock of $to. */
function delicat_debt_forward( $user_id, $from, $to, $tiers = null ) {
	$month = (string) $from; $steps = 0;
	while ( $month < $to && $steps++ <= DELICAT_DEBT_MONTHS ) {
		$next = delicat_month_shift( $month, 1 );
		$peek = delicat_ledger_peek( $user_id, $next );
		if ( ! $peek['ok'] ) break; // frozen: re-seal it first
		$carry = delicat_debt_carry( $user_id, $next, $tiers );
		if ( $peek['exists'] ) {
			if ( $carry != $peek['debt'] ) delicat_ledger_save( $user_id, $next, $peek['claims'], $carry );
		} elseif ( $carry ) {
			delicat_ledger_save( $user_id, $next, array(), $carry ); // no ledger = no claim that month
		}
		$month = $next;
	}
}

/**
 * A refund, cancellation or reopening after the purchases were counted. When they had
 * already paid for a claimed bonus, the shortfall is carried: signed into each following
 * month's ledger up to now, covered first by the customer's next purchases, and the owner
 * is told once per change. Nothing is revoked automatically.
 */
function delicat_refund_after_claim( $order_id ) {
	static $seen = array();
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	if ( $order && is_a( $order, 'WC_Order_Refund' ) ) $order = wc_get_order( $order->get_parent_id() );
	if ( ! $order || ! is_a( $order, 'WC_Order' ) || $order->get_meta( '_delicat_reward' ) ) return;
	$uid = (int) $order->get_customer_id();
	$at  = delicat_order_counted_at( $order );
	if ( $uid < 1 || $at < 1 ) return;
	$from = delicat_month_key( $at ); $now = delicat_month_key( time() );
	if ( $from > $now || $from < delicat_month_shift( $now, -DELICAT_DEBT_MONTHS ) ) return;
	$pass = $order->get_id() . '|' . $order->get_status() . '|' . (float) $order->get_total_refunded();
	if ( isset( $seen[ $pass ] ) ) return;
	$seen[ $pass ] = true;
	$lock = 'delicat_claim_' . $uid . '_' . $now; // the claim lock: a claim never saves over a new debt
	if ( ! delicat_lock( $lock, 5 ) ) {
		delicat_log( 'warning', 'Refund after claim not re-checked: customer ledger busy.', array( 'order_id' => (int) $order->get_id(), 'user_id' => $uid ) );
		return;
	}
	try {
		$tiers  = delicat_tiers();
		$ledger = delicat_ledger_peek( $uid, $from );
		if ( ! $ledger['ok'] || ( ! array_filter( $ledger['claims'] ) && ! $ledger['debt'] ) ) return;
		list( $start, $end ) = delicat_month_bounds( $from );
		$units = delicat_user_window_units( $uid, $start, $end, $tiers );
		$short = array();
		foreach ( $tiers as $key => $t ) {
			$n = (int) ( $ledger['claims'][ $key ] ?? 0 ) * (int) $t['goal'] + (int) ( $ledger['debt'][ $key ] ?? 0 ) - (int) ( $units['counts'][ $key ] ?? 0 );
			if ( $n > 0 ) $short[ $key ] = $n;
		}
		delicat_debt_forward( $uid, $from, $now, $tiers );
		$alert_key   = '_delicat_shortfall_alert_' . str_replace( '-', '_', $from );
		$fingerprint = $short ? md5( (string) wp_json_encode( $short ) ) : '';
		if ( $fingerprint === (string) get_user_meta( $uid, $alert_key, true ) ) return;
		if ( '' === $fingerprint ) { delete_user_meta( $uid, $alert_key ); return; }
		update_user_meta( $uid, $alert_key, $fingerprint );
		$lines = array();
		foreach ( $short as $key => $n ) $lines[] = sprintf( '%s : %d achat(s)', $tiers[ $key ]['label'], $n );
		$order->add_order_note( sprintf( 'Delicat Rewards : ce remboursement ou cette annulation touche des achats déjà utilisés pour un bonus gratuit (%s). Le manque sera couvert en priorité par les prochains achats du client.', implode( ', ', $lines ) ) );
		wp_mail( get_option( 'admin_email' ), 'Delicat Rewards — remboursement après un bonus', sprintf( "Commande #%d (client #%d, mois %s) remboursée ou annulée après un bonus réclamé.\nAchats déjà utilisés pour une récompense : %s.\nLe manque est reporté sur les prochains achats du client. Aucune récompense n'a été reprise : vérifiez la commande si nécessaire.", (int) $order->get_id(), $uid, $from, implode( ', ', $lines ) ) );
		delicat_log( 'warning', 'Refund after a claimed bonus: shortfall carried forward.', array( 'order_id' => (int) $order->get_id(), 'user_id' => $uid, 'month' => $from ) );
	} catch ( \Throwable $e ) {
		delicat_log( 'error', 'Refund after claim check failed.', array( 'order_id' => (int) $order->get_id() ) );
	} finally {
		delicat_unlock( $lock );
		delicat_flush_user( $uid );
	}
}

function delicat_user_orders( $user_id, $after_ts ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) return array();
	$args = array( 'customer_id' => $user_id, 'status' => delicat_counting_statuses(), 'orderby' => 'date', 'order' => 'DESC' );
	$args = array_merge( $args, delicat_order_window_args( $after_ts ) );
	return delicat_orders_paged( $args );
}

function delicat_compute_state( $user_id ) {
	$user_id = (int) $user_id;
	$tiers   = delicat_tiers();
	/* Paid, completed, non-refunded retail orders of this month (delicat_order_units). */
	$units   = delicat_user_window_units( $user_id, delicat_month_start() );
	$counts  = $units['counts'];
	$spend   = (float) $units['spend'];

	$month  = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	$cap    = max( 0, (int) delicat_cfg( 'monthly_cap' ) );
	$dry    = (int) delicat_cfg( 'dry_run' ) === 1;            // test mode: look, don't touch
	$ledger = delicat_ledger_get( $user_id, $month );
	$frozen = empty( $ledger['ok'] );                          // counters were tampered with

	$claimed      = array();
	$reward_total = 0;
	foreach ( $tiers as $key => $t ) $claimed[ $key ] = isset( $ledger['claims'][ $key ] ) ? max( 0, (int) $ledger['claims'][ $key ] ) : 0;
	$values = array_merge( delicat_tier_history(), delicat_all_tiers() );
	foreach ( (array) ( $ledger['claims'] ?? array() ) as $key => $n ) {
		if ( isset( $values[ $key ] ) ) $reward_total += max( 0, (int) $n ) * max( 0, (int) ( $values[ $key ]['reward'] ?? 0 ) );
	}
	/* Completed Woo reward orders preserve the monthly cap even if a tier is
	 * later disabled/removed or its configured reward amount changes. */
	$reward_total = max( $reward_total, (int) ( $ledger['reward_total'] ?? 0 ) );

	$state = array(
		'resetAt' => ( new DateTimeImmutable( 'first day of next month 00:00:00', wp_timezone() ) )->getTimestamp(),
		'serverTime' => time(),
		'monthSpend'  => round( $spend, 2 ),
		'monthReward' => $reward_total,
		'monthCredit' => (float) ( $ledger['wallet_total'] ?? 0 ),
		'rewardType' => 'wallet', 'currency' => 'HTG',
		'frozen'      => $frozen,
		'dry'         => $dry,
		'tiers'       => array(),
	);

	$debt = (array) ( $ledger['debt'] ?? array() );
	foreach ( $tiers as $key => $t ) {
		/* Purchases first cover the bonuses already claimed, then any shortfall a refund
		   left after an earlier bonus (carried across months, signed in the ledger). */
		$required = $claimed[ $key ] * $t['goal'] + max( 0, (int) ( $debt[ $key ] ?? 0 ) );
		$progress = max( 0, (int) ( $counts[ $key ] ?? 0 ) - $required );
		$owed     = max( 0, $required - (int) ( $counts[ $key ] ?? 0 ) );
		$blocked  = '';
		$ok       = ( ! $frozen ) && ( ! $dry ) && $progress >= $t['goal'];
		if ( $frozen )                                                            { $blocked = 'frozen'; }
		elseif ( $dry && $progress >= $t['goal'] )                                { $blocked = 'dry'; }
		if ( $ok && ( ! delicat_wallet_available() || delicat_reward_value( $t['reward'] ) <= 0 ) ) { $ok = false; $blocked = 'wallet'; }
		if ( $ok && $t['max_claims'] > 0 && $claimed[ $key ] >= $t['max_claims'] ) { $ok = false; $blocked = 'max'; }
		if ( $ok && $cap > 0 && ( $reward_total + $t['reward'] ) > $cap )          { $ok = false; $blocked = 'cap'; }

		$state['tiers'][ $key ] = array(
			'progress'  => (int) $progress,
			'goal'      => (int) $t['goal'],
			'creditAmount' => delicat_reward_value( $t['reward'] ),
			'expiresAt' => $state['resetAt'],
			'claimed'   => (int) $claimed[ $key ],
			'owed'      => (int) $owed, // purchases still covering a refund after an earlier bonus
			'claimable' => (bool) $ok,
			'blocked'   => $blocked,
			/* The button only works with this signature. It dies in 30 min and
			   becomes invalid the moment the claim count changes. */
			'token'     => $ok ? delicat_token_make( $user_id, $key, $month, $claimed[ $key ] ) : '',
		);
	}
	return $state;
}

/* =========================================================================
   REWARD ISSUANCE
   ========================================================================= */
function delicat_issue_reward( $user_id, $tier_key, $request_hash = '', $claim_month = '' ) {
	$tiers = delicat_tiers();
	if ( ! isset( $tiers[ $tier_key ] ) ) return false;
	$t = $tiers[ $tier_key ];

	if ( $t['reward'] <= 0 || $t['reward'] > DELICAT_MAX_REWARD ) return false; // belt and braces
	if ( (int) delicat_cfg( 'dry_run' ) === 1 ) return false;                   // test mode issues nothing, ever

	$claim_month = $claim_month ?: wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	$expires = ( new DateTimeImmutable( $claim_month . '-01 00:00:00', wp_timezone() ) )->modify( '+1 month' )->getTimestamp();
	if ( time() >= $expires ) return false;
	$oid = delicat_create_wallet_reward( $user_id, $t['reward'], $tier_key, array(
		'delicat_reward_request' => $request_hash,
		'delicat_reward_kind' => 'tier', 'delicat_reward_amount' => (int) $t['reward'],
		'delicat_reward_tier' => $tier_key,
		'delicat_reward_month' => $claim_month,
	), $expires );
	if ( $oid ) delicat_credit_wallet_reward( $oid, $user_id );
	if ( ! $oid ) return false;

	try { do_action( 'delicat_reward_issued', $user_id, $tier_key, $oid, (int) $t['reward'] ); }
	catch ( \Throwable $e ) { delicat_log( 'error', 'Reward notification failed after order creation.', array( 'order_id' => $oid ) ); }
	return $oid;
}

/* =========================================================================
   CLIENT NOTIFICATIONS
   ========================================================================= */
/** Replace {name} {tier} {reward} {unit} {order} {site} in a template. */
function delicat_fill( $tpl, array $vars ) {
	$find = array(); $repl = array();
	foreach ( $vars as $k => $v ) { $find[] = '{' . $k . '}'; $repl[] = (string) $v; }
	return str_replace( $find, $repl, (string) $tpl );
}

function delicat_mail_user( $user_id, $subject, $body ) {
	$u = get_userdata( (int) $user_id );
	if ( ! $u || ! is_email( $u->user_email ) ) return false;
	$subject = wp_specialchars_decode( sanitize_text_field( $subject ), ENT_QUOTES );
	$body    = wp_strip_all_tags( (string) $body );
	return (bool) wp_mail( $u->user_email, $subject, $body );
}

add_action( 'delicat_reward_issued', 'delicat_notify_reward', 10, 4 );
function delicat_notify_reward( $user_id, $tier_key, $order_id, $reward ) {
	if ( delicat_wallet_claim_email( $user_id, $order_id ) ) return;
	if ( ! delicat_cfg( 'notify_client' ) ) return;
	$tiers = delicat_tiers();
	$label = isset( $tiers[ $tier_key ] ) ? $tiers[ $tier_key ]['label'] : $tier_key;
	$u     = get_userdata( (int) $user_id );

	$vars = array(
		'name'   => $u ? ( $u->first_name ?: $u->display_name ) : 'Kliyan',
		'tier'   => $label,
		'reward' => (int) $reward,
		'unit'   => (string) delicat_cfg( 'unit' ),
		'order'  => (int) $order_id ? (int) $order_id : '—',
		'site'   => (string) get_bloginfo( 'name' ),
	);
	$subject = delicat_fill( delicat_cfg( 'notify_subject' ), $vars );
	$body    = delicat_fill( delicat_cfg( 'notify_body' ), $vars );

	$body = apply_filters( 'delicat_reward_email', $body, $user_id, $tier_key, $order_id, $reward );
	delicat_mail_user( $user_id, $subject, $body );
}

function delicat_notify_winner( $user_id, $order_id, $reward ) {
	if ( ! delicat_cfg( 'notify_client' ) ) return;
	$u    = get_userdata( (int) $user_id );
	$vars = array(
		'name'   => $u ? ( $u->first_name ?: $u->display_name ) : 'Kliyan',
		'tier'   => 'Meilleur Client',
		'reward' => (int) $reward,
		'unit'   => (string) delicat_cfg( 'unit' ),
		'order'  => (int) $order_id ? (int) $order_id : '—',
		'site'   => (string) get_bloginfo( 'name' ),
	);
	delicat_mail_user( $user_id, delicat_fill( delicat_cfg( 'notify_subject' ), $vars ), delicat_fill( delicat_cfg( 'notify_winner' ), $vars ) );
}

/** Zero-total completed order made of real PIN products. Returns order id or 0. */
function delicat_create_reward_order( $user_id, $plan, $note, $tag, array $meta = array() ) {
    if ( ! function_exists( 'wc_create_order' ) ) return 0;
    // Serialize reward stock checks across customers and weekly awards as well as tiers.
    if ( ! delicat_lock( 'delicat_reward_inventory', 5 ) ) return 0;
    $order = null; $saved = false;
    try {
        $lines = array(); $items = 0;
        foreach ( $plan as $line ) {
            $pid = delicat_pid_by_diamonds( $line['denom'] );
            if ( ! $pid ) return 0;
            $qty = max( 1, (int) $line['qty'] );
            $product = delicat_purchasable( $pid, $qty );
            if ( ! $product ) return 0;
            $per_unit = function_exists( 'wckm_is_keyed_product' ) && wckm_is_keyed_product( $product ) && function_exists( 'wckm_get_delivery_quantity' ) ? max( 1, (int) wckm_get_delivery_quantity( $product, 1 ) ) : 1;
            $items += $qty * $per_unit;
            if ( $items > DELICAT_MAX_PIN_ITEMS ) return 0;
            $lines[] = array( $product, $qty );
        }
        if ( ! $lines ) return 0;
        $order = wc_create_order( array( 'customer_id' => (int) $user_id ) );
        if ( is_wp_error( $order ) || ! $order ) return 0;
        $order->set_created_via( 'delicat-rewards' );
        $order->update_meta_data( '_delicat_reward', $tag );
        foreach ( $meta as $mk => $mv ) {
            $mk = sanitize_key( $mk );
            if ( '' !== $mk ) $order->update_meta_data( '_' . ltrim( $mk, '_' ), sanitize_text_field( (string) $mv ) );
        }
        // Persist reward identity before invoking any provider hooks. A failure after
        // this point must keep the claim reserved; viewing/retrying never issues again.
        $order->save(); $saved = true;
        foreach ( $lines as $line ) {
            $item_id = $order->add_product( $line[0], $line[1], array( 'subtotal' => 0, 'total' => 0 ) );
            if ( ! $item_id ) throw new RuntimeException( 'Reward line could not be saved.' );
        }
        foreach ( $order->get_items() as $item ) {
            $item->set_subtotal( 0 ); $item->set_total( 0 );
            $item->set_taxes( array( 'total' => array(), 'subtotal' => array() ) ); $item->save();
        }
        $order->calculate_totals( false ); $order->set_total( 0 );
        $order->save();
        $order->add_order_note( $note );
        $order->update_status( 'completed', 'Delicat reward auto-issued.', true );
        // Explicit provider handshake; Secure Keys performs an idempotent atomic
        // assignment if the regular WooCommerce completion hook has not yet.
        do_action( 'delicat_reward_order_ready_for_delivery', (int) $order->get_id(), (int) $user_id, (string) $tag );
        return (int) $order->get_id();
    } catch ( \Throwable $e ) {
        delicat_log( 'error', 'Reward creation interrupted; verify delivery before retrying.', array( 'order_id' => $saved ? (int) $order->get_id() : 0 ) );
        // Do not undo or repeat an allocation that the provider may already have made.
        return $saved ? (int) $order->get_id() : 0;
    } finally { delicat_unlock( 'delicat_reward_inventory' ); }
}

/* =========================================================================
   LEADERBOARD
   ========================================================================= */
/** Timestamp used only as a deterministic final tie-breaker. */
function delicat_leaderboard_order_timestamp( $order ) {
	if ( ! $order || ! is_a( $order, 'WC_Abstract_Order' ) ) return 0;
	foreach ( array( 'get_date_completed', 'get_date_paid', 'get_date_created' ) as $method ) {
		if ( ! method_exists( $order, $method ) ) continue;
		$date = $order->{$method}();
		if ( $date && method_exists( $date, 'getTimestamp' ) ) return (int) $date->getTimestamp();
	}
	return 0;
}

/**
 * Score and sort Meilleur Client rows.
 *
 * A customer who has BOTH a higher spend and more orders than another customer
 * will always have a higher score. Money remains the main factor (70%), while
 * buying frequency uses real, distinct WooCommerce orders (30%).
 */
function delicat_rank_leaderboard_rows( array $rows ) {
	$max_orders = 1;
	$max_spend  = 1.0;
	foreach ( $rows as $r ) {
		$max_orders = max( $max_orders, (int) ( $r['orders'] ?? 0 ) );
		$max_spend  = max( $max_spend, (float) ( $r['spend'] ?? 0 ) );
	}

	foreach ( $rows as $uid => &$r ) {
		$r['user_id'] = (int) $uid;
		$r['orders']   = max( 0, (int) ( $r['orders'] ?? 0 ) );
		$r['spend']    = max( 0.0, (float) ( $r['spend'] ?? 0 ) );
		$r['score']    = round(
			DELICAT_BOARD_SPEND_WEIGHT * ( $r['spend'] / $max_spend )
			+ DELICAT_BOARD_ORDER_WEIGHT * ( $r['orders'] / $max_orders ),
			12
		);
	}
	unset( $r );

	uasort( $rows, function ( $a, $b ) {
		$by_score = (float) $b['score'] <=> (float) $a['score'];
		if ( 0 !== $by_score ) return $by_score;

		/* If the combined score is tied, reward the bigger customer value first. */
		$by_spend = (float) $b['spend'] <=> (float) $a['spend'];
		if ( 0 !== $by_spend ) return $by_spend;

		$by_orders = (int) $b['orders'] <=> (int) $a['orders'];
		if ( 0 !== $by_orders ) return $by_orders;

		$a_last = (int) ( $a['last_order_at'] ?? 0 );
		$b_last = (int) ( $b['last_order_at'] ?? 0 );
		if ( $a_last !== $b_last ) {
			if ( $a_last < 1 ) return 1;
			if ( $b_last < 1 ) return -1;
			return $a_last <=> $b_last; // same result reached earlier wins
		}

		return (int) $a['user_id'] <=> (int) $b['user_id'];
	} );

	$rank = 0;
	foreach ( $rows as &$r ) $r['rank'] = ++$rank;
	unset( $r );
	return $rows;
}

/**
 * 3.4.2 (RWD-05): « Montant minimum d'une commande pour le classement ». A change applies from the next
 * weekly reset, never to the week in progress: option delicat_board_min_rules keeps each value with the
 * period start it applies from, so a closed week is always recomputed with its own rule.
 */
function delicat_board_min_rules() {
	$r = get_option( 'delicat_board_min_rules', array() );
	return is_array( $r ) ? $r : array();
}
/** The minimum (HTG, eligible amount of one order) for a period that starts at $start. */
function delicat_board_min_order( $start ) {
	$min = 0.0; $from = -1;
	foreach ( delicat_board_min_rules() as $rule ) {
		$at = (int) ( $rule['from'] ?? 0 );
		if ( $at <= (int) $start && $at > $from ) { $from = $at; $min = max( 0.0, (float) ( $rule['min'] ?? 0 ) ); }
	}
	return $min;
}
/** Plans $min from the next weekly reset (the week in progress keeps its rule). Returns that reset's timestamp. */
function delicat_board_min_plan( $min ) {
	$from  = delicat_next_week_start();
	$rules = array();
	foreach ( delicat_board_min_rules() as $rule ) {
		if ( (int) ( $rule['from'] ?? 0 ) < $from ) $rules[] = array( 'from' => (int) $rule['from'], 'min' => (float) $rule['min'] );
	}
	if ( abs( delicat_board_min_order( $from - 1 ) - (float) $min ) > 0.001 || $rules !== delicat_board_min_rules() ) $rules[] = array( 'from' => $from, 'min' => max( 0.0, (float) $min ) );
	usort( $rules, function ( $a, $b ) { return $a['from'] <=> $b['from']; } );
	update_option( 'delicat_board_min_rules', array_slice( $rules, -60 ), false );
	return $from;
}

/**
 * Build weekly rows from the only trusted source: real WooCommerce orders.
 * Each qualifying order increments the frequency exactly once. Only eligible
 * line-item net spend is added, so unrelated products and refunded value do not
 * inflate the competition.
 */
function delicat_collect_leaderboard_rows( $start, $end = null, $include_excluded = false ) {
	$rows = array();
	if ( ! function_exists( 'wc_get_orders' ) ) return $rows;

	$min  = delicat_board_min_order( (int) $start ); // 3.4.2: the minimum in force for this period (0 = every order)
	$args = array_merge( array( 'type' => 'shop_order', 'status' => delicat_counting_statuses(), 'orderby' => 'date', 'order' => 'ASC' ), delicat_order_window_query( (int) $start ) );
	delicat_orders_each( $args, function ( $order ) use ( &$rows, $start, $end, $include_excluded, $min ) {
		if ( ! delicat_order_in_window( $order, $start, $end ) || ! delicat_order_ok( $order ) ) return;
		$uid = (int) $order->get_customer_id();
		if ( ! $uid || ( ! $include_excluded && delicat_is_excluded( $uid ) ) ) return;

		$units = delicat_order_units( $order, array() );
		/* A free/fully-refunded eligible line is not a money-spending order. */
		if ( $units['qty'] < 1 || $units['spend'] <= 0 ) return;
		/* Below the period's minimum (eligible amount after refunds): the order is left out of the ranking. */
		if ( $min > 0 && $units['spend'] + 1e-9 < $min ) return;
		if ( ! isset( $rows[ $uid ] ) ) {
			$rows[ $uid ] = array( 'orders' => 0, 'spend' => 0.0, 'last_order_at' => 0 );
		}

		$rows[ $uid ]['orders']++;
		$rows[ $uid ]['spend'] += $units['spend'];
		$rows[ $uid ]['last_order_at'] = max(
			(int) $rows[ $uid ]['last_order_at'],
			delicat_leaderboard_order_timestamp( $order )
		);
	} );

	return delicat_rank_leaderboard_rows( $rows );
}

/**
 * 3.4.2: the board customers see is a stored snapshot (option delicat_leaderboard_board), rebuilt in the
 * background — Action Scheduler (WooCommerce) when present, else WP-Cron — never while a customer waits.
 * Up to 3.4.1 the page view that found the board stale rebuilt it from up to 5,000 orders, as often as
 * once a minute. Any order change marks the board stale and asks for one rebuild ~30 s later (several
 * changes share it); the page meanwhile shows the last board of the same period.
 */
const DELICAT_BOARD_GROUP = 'delicat-rewards';
function delicat_leaderboard_touch() {
	set_transient( 'delicat_leaderboard_dirty', time(), DAY_IN_SECONDS );
	delicat_leaderboard_schedule( 30 );
}
/* Settings, exclusions, period changes… cleared the 3.4.1 cache: they now ask for a rebuild. */
add_action( 'delete_transient_delicat_leaderboard', 'delicat_leaderboard_touch' );

function delicat_board_queue() {
	return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler_Store' ) && did_action( 'action_scheduler_init' );
}
/**
 * Runs $hook once in the background at $when, unless the same run is already waiting (Action Scheduler
 * when WooCommerce has it, else WP-Cron). Only a waiting run counts: one in progress may have read the
 * orders before the change that asks again.
 */
function delicat_bg_once( $hook, array $args, $when ) {
	if ( delicat_board_queue() ) {
		$waiting = as_get_scheduled_actions( array( 'hook' => $hook, 'args' => $args, 'group' => DELICAT_BOARD_GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' );
		if ( ! $waiting ) as_schedule_single_action( (int) $when, $hook, $args, DELICAT_BOARD_GROUP );
		return;
	}
	if ( ! wp_next_scheduled( $hook, $args ) ) wp_schedule_single_event( (int) $when, $hook, $args );
}
/**
 * One rebuild $delay seconds from now (or at $at). $tag 'reset': the one planned right after the weekly
 * reset, kept apart from the rebuilds asked for by order changes.
 */
function delicat_leaderboard_schedule( $delay = 30, $at = 0, $tag = '' ) {
	delicat_bg_once( 'delicat_leaderboard_rebuild', '' === $tag ? array() : array( $tag ), $at > 0 ? (int) $at : time() + max( 0, (int) $delay ) );
}
/** The background rebuild: the same scoring engine as before, stored for every reader. */
function delicat_leaderboard_rebuild() {
	if ( ! function_exists( 'wc_get_orders' ) ) return;
	if ( ! delicat_lock( 'delicat_leaderboard_build', 0 ) ) { delicat_leaderboard_schedule( 60 ); return; } // one build at a time
	$began = time();
	try {
		$start = delicat_competition_start();
		$rows  = delicat_collect_leaderboard_rows( $start );
		update_option( 'delicat_leaderboard_board', array( 'built' => $began, 'start' => $start, 'rows' => $rows ), false );
	} finally {
		delicat_unlock( 'delicat_leaderboard_build' );
	}
	if ( (int) get_transient( 'delicat_leaderboard_dirty' ) >= $began ) delicat_leaderboard_schedule( 30 ); // orders changed during the build
	delicat_leaderboard_schedule( 0, delicat_next_week_start() + 5, 'reset' ); // the new week's board right after the reset
}
add_action( 'delicat_leaderboard_rebuild', 'delicat_leaderboard_rebuild' );
register_deactivation_hook( __FILE__, function () {
	wp_unschedule_hook( 'delicat_leaderboard_rebuild' );
	wp_unschedule_hook( 'delicat_podium_recheck' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) as_unschedule_all_actions( '', array(), DELICAT_BOARD_GROUP ); // this plugin's group only
} );

/**
 * The board of the current period, read from the stored snapshot only. A snapshot of another period
 * (the week was reset or closed) is never shown: an empty board until the rebuild that is asked for now.
 */
function delicat_leaderboard() {
	$start = delicat_competition_start();
	$board = get_option( 'delicat_leaderboard_board' );
	$same  = is_array( $board ) && isset( $board['rows'], $board['built'] ) && is_array( $board['rows'] ) && (int) ( $board['start'] ?? -1 ) === $start;
	if ( ! $same ) delicat_leaderboard_schedule( 0 );
	else {
		/* Stale: the order change already asked for a rebuild 30 s later. 4.1.0: a page view asks again only
		   when that one looks lost (2 minutes on), instead of querying the job queue on every view. */
		$dirty = (int) get_transient( 'delicat_leaderboard_dirty' );
		if ( $dirty >= (int) $board['built'] && time() - $dirty > 2 * MINUTE_IN_SECONDS ) delicat_leaderboard_schedule( 30 );
	}
	return $same ? $board['rows'] : array();
}
/** When the stored board was built (0 = never), for the Diagnostics tab. */
function delicat_leaderboard_built() {
	$board = get_option( 'delicat_leaderboard_board' );
	return is_array( $board ) ? (int) ( $board['built'] ?? 0 ) : 0;
}

/* =========================================================================
   WEEKLY AUTO-AWARD
   ========================================================================= */
add_action( 'init', function () {
	if ( ! delicat_auto_award_on() ) return;
	if ( ! wp_next_scheduled( 'delicat_weekly_award_check' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'delicat_weekly_award_check' );
	}
} );
register_deactivation_hook( __FILE__, function () {
	$ts = wp_next_scheduled( 'delicat_weekly_award_check' );
	if ( $ts ) wp_unschedule_event( $ts, 'delicat_weekly_award_check' );
} );
add_action( 'delicat_weekly_award_check', 'delicat_maybe_award_weekly' );

function delicat_weekly_state_get() {
	$v = get_option( 'delicat_weekly_award_state', array() ); return is_array( $v ) ? $v : array();
}
function delicat_weekly_state_save( array $all ) {
	// Keep historical delivery records: they are also duplicate-delivery guards.
	update_option( 'delicat_weekly_award_state', $all, false );
}
/** A 3.2.1 timestamp (site wall clock stored as if it were UTC) as the real timestamp. */
function delicat_shifted_to_real( $shifted ) {
	$shifted = (int) $shifted;
	if ( $shifted < 1 ) return $shifted;
	return ( new DateTimeImmutable( gmdate( 'Y-m-d H:i:s', $shifted ), wp_timezone() ) )->getTimestamp();
}

/**
 * One-time conversion of the stored awarded week, weekly results and cutoffs to real
 * timestamps, shipped with the delicat_week_start() fix: without it the last paid week
 * looks unpaid and is awarded again. Caller holds the 'delicat_competition_control' lock.
 * Restartable: the originals are kept until the conversion is marked done.
 */
function delicat_week_utc_ready() {
	global $wpdb;
	$names = array( 'delicat_awarded_week', 'delicat_weekly_award_state', 'delicat_competition_cutoff', 'delicat_competition_cutoffs' );
	$fresh = function ( $name ) use ( $wpdb ) {
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		return null === $value ? null : maybe_unserialize( $value );
	};
	/* Another request may have written these since this one loaded its option cache. */
	$flush = function () use ( $names ) {
		wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
		foreach ( array_merge( $names, array( 'delicat_rewards_week_utc', 'delicat_rewards_week_utc_backup' ) ) as $name ) wp_cache_delete( $name, 'options' );
	};
	$state = (string) $fresh( 'delicat_rewards_week_utc' );
	$flush();
	if ( 'done' === $state ) return true;
	if ( 'running' === $state ) {
		$orig = $fresh( 'delicat_rewards_week_utc_backup' );
		if ( ! is_array( $orig ) ) {
			// Partly converted and no originals: never guess, keep awards stopped until checked.
			delicat_log( 'error', 'Week boundary conversion cannot resume: backup option missing. Weekly awards are paused.' );
			return false;
		}
	} else {
		$orig = array();
		foreach ( $names as $name ) $orig[ $name ] = $fresh( $name );
		update_option( 'delicat_rewards_week_utc_backup', $orig, false );
		update_option( 'delicat_rewards_week_utc', 'running', false );
	}
	$slot = function ( array $into, $ts ) { $ts = delicat_shifted_to_real( $ts ); while ( isset( $into[ $ts ] ) ) $ts++; return $ts; };
	if ( null !== ( $orig['delicat_awarded_week'] ?? null ) ) update_option( 'delicat_awarded_week', delicat_shifted_to_real( $orig['delicat_awarded_week'] ) );
	if ( null !== ( $orig['delicat_competition_cutoff'] ?? null ) ) update_option( 'delicat_competition_cutoff', delicat_shifted_to_real( $orig['delicat_competition_cutoff'] ), false );
	if ( is_array( $orig['delicat_competition_cutoffs'] ?? null ) ) {
		$cutoffs = array();
		foreach ( $orig['delicat_competition_cutoffs'] as $week => $end ) $cutoffs[ $slot( $cutoffs, $week ) ] = delicat_shifted_to_real( $end );
		update_option( 'delicat_competition_cutoffs', $cutoffs, false );
	}
	if ( is_array( $orig['delicat_weekly_award_state'] ?? null ) ) {
		$all = array();
		foreach ( $orig['delicat_weekly_award_state'] as $period => $week ) {
			if ( is_array( $week ) ) {
				foreach ( array( 'start', 'end' ) as $field ) {
					if ( isset( $week[ $field ] ) && is_numeric( $week[ $field ] ) ) $week[ $field ] = delicat_shifted_to_real( $week[ $field ] );
				}
			}
			$all[ $slot( $all, $period ) ] = $week;
		}
		update_option( 'delicat_weekly_award_state', $all, false );
	}
	update_option( 'delicat_rewards_week_utc', 'done', true );
	$flush();
	delete_transient( 'delicat_leaderboard' );
	delete_transient( 'delicat_leaderboard_stale' );
	delicat_log( 'info', 'Meilleur Client week boundaries converted to real timestamps.' );
	return true;
}
add_action( 'init', function () {
	if ( 'done' === get_option( 'delicat_rewards_week_utc' ) ) return;
	if ( ! delicat_lock( 'delicat_competition_control', 2 ) ) return;
	try { delicat_week_utc_ready(); }
	catch ( \Throwable $e ) { delicat_log( 'error', 'Week boundary conversion interrupted; it resumes on the next request.' ); }
	finally { delicat_unlock( 'delicat_competition_control' ); }
}, 4 );

/**
 * Weekly reward order already issued for this boundary and rank. Orders issued up to 3.2.1
 * carry the boundary shifted by the site's UTC offset; boundaries are a week apart, so one
 * within that offset is the same week and is never paid twice.
 */
function delicat_existing_weekly_order( $boundary, $rank ) {
	$boundary = (int) $boundary;
	$slack = abs( (int) wp_timezone()->getOffset( new DateTime( '@' . $boundary ) ) ) + HOUR_IN_SECONDS;
	$start = $boundary - DAY_IN_SECONDS; $end = $boundary + 14 * DAY_IN_SECONDS;
	foreach ( delicat_orders_paged( array( 'type' => 'shop_order', 'date_created' => $start . '...' . $end, 'orderby' => 'date', 'order' => 'DESC' ), 100, 1500 ) as $o ) {
		if ( 'completed' !== $o->get_status() && 'wallet' !== $o->get_meta( '_delicat_reward_delivery' ) ) continue;
		$stored = (string) $o->get_meta( '_delicat_week_boundary' );
		if ( '' === $stored || ! is_numeric( $stored ) || abs( (int) $stored - $boundary ) > $slack ) continue;
		if ( 'wallet' === $o->get_meta( '_delicat_reward_delivery' ) && (int) $stored !== $boundary ) continue;
		if ( (int) $o->get_meta( '_delicat_week_rank' ) === (int) $rank ) return (int) $o->get_id();
	}
	return 0;
}

function delicat_maybe_award_weekly() {
	if ( ! delicat_auto_award_on() || ! delicat_meilleur_on() ) return;
	if ( (int) delicat_cfg( 'dry_run' ) === 1 ) return;
	$boundary = delicat_week_start(); $lock = 'delicat_competition_control';
	if ( ! delicat_lock( $lock, 2 ) ) return;
	try {
		if ( ! delicat_week_utc_ready() ) return; // never award against unconverted week keys
		$done = (int) get_option( 'delicat_awarded_week', 0 );
		if ( 0 === $done ) { update_option( 'delicat_awarded_week', $boundary ); return; }
		if ( $done >= $boundary ) return;
		$complete = delicat_award_weekly_winner( delicat_closed_period_start( $boundary ), $boundary, $boundary );
		if ( $complete ) update_option( 'delicat_awarded_week', $boundary );
	} catch ( \Throwable $e ) {
		delicat_log( 'error', 'Weekly award exception: ' . $e->getMessage(), array( 'boundary' => (int) $boundary ) );
	} finally { delicat_unlock( $lock ); }
}

function delicat_award_weekly_winner( $start, $end, $boundary = 0 ) {
	if ( ! function_exists( 'wc_get_orders' ) ) return false;
	$boundary = $boundary ?: (int) $end;
	$all = delicat_weekly_state_get(); $key = (string) $boundary;
	$week = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array( 'placements' => array(), 'complete' => 0, 'updated' => current_time( 'mysql' ) );
	/* 4.1.0: once the podium is frozen, an hourly retry (a prize waiting for the team's check) no longer
	   re-reads the whole week's orders: the frozen ranking is all it uses. */
	$frozen = ! empty( $week['ranking'] ) && is_array( $week['ranking'] );
	$rows = $frozen ? array() : delicat_collect_leaderboard_rows( $start, $end - 1 );
	if ( ! $rows && empty( $week['ranking'] ) ) { $week['complete'] = 1; $week['reason'] = 'no_participants'; $week['updated'] = current_time( 'mysql' ); $all[ $key ] = $week; delicat_weekly_state_save( $all ); return true; }

	$ranked = array(); $rank = 0; foreach ( $rows as $uid => $r ) { if ( ++$rank > 3 ) break; $ranked[ $rank ] = (int) $uid; }
	if ( empty( $week['ranking'] ) || ! is_array( $week['ranking'] ) ) $week['ranking'] = $ranked;
	else $ranked = array_map( 'intval', $week['ranking'] ); // freeze winners for safe retries
	$prizes = delicat_meilleur_prizes(); $results = array(); $complete = true;
	foreach ( array( 1, 2, 3 ) as $rank ) {
		$prev = $week['placements'][ $rank ] ?? array();
		if ( in_array( $prev['status'] ?? '', array( 'manual', 'delivered', 'claimable', 'claimed', 'expired' ), true ) ) continue;
		if ( ! empty( $prev['verify'] ) ) { $complete = false; continue; } // 3.4.2: podium changed by a refund — the team decides, nothing automatic
		$prize = isset( $prev['prize'] ) ? (int) $prev['prize'] : ( isset( $prizes[ $rank ] ) ? (int) $prizes[ $rank ] : 0 );
		if ( $prize < 1 ) { $week['placements'][ $rank ] = array( 'status' => 'disabled', 'prize' => 0 ); continue; }
		if ( empty( $ranked[ $rank ] ) ) { $week['placements'][ $rank ] = array( 'status' => 'no_participant', 'prize' => $prize ); continue; }
		$uid = $ranked[ $rank ]; $prev = $week['placements'][ $rank ] ?? array();
		if ( ( $prev['status'] ?? '' ) === 'manual' ) continue;
		if ( ( $prev['status'] ?? '' ) === 'delivered' && ! empty( $prev['oid'] ) ) { $results[ $rank ] = array( 'uid' => $uid, 'prize' => $prize, 'oid' => (int) $prev['oid'] ); continue; }
		$existing = delicat_existing_weekly_order( $boundary, $rank );
		if ( $existing ) {
			$eo = wc_get_order( $existing ); $euid = $eo ? (int) $eo->get_customer_id() : $uid;
			$wallet_order = $eo && 'wallet' === $eo->get_meta( '_delicat_reward_delivery' );
			$week['placements'][ $rank ] = array( 'status' => $wallet_order ? ( $eo->get_meta( '_delicat_wallet_tx' ) ? 'claimed' : 'claimable' ) : 'delivered', 'uid' => $euid ?: $uid, 'prize' => $prize, 'oid' => $existing );
			if ( $wallet_order ) { $week['placements'][ $rank ]['amount_htg'] = (float) $eo->get_meta( '_delicat_wallet_amount' ); $week['placements'][ $rank ]['expires_at'] = (int) $eo->get_meta( '_delicat_reward_expires' ); }
			$results[ $rank ] = array( 'uid' => $euid ?: $uid, 'prize' => $prize, 'oid' => $existing ); continue;
		}

		$oid = delicat_create_wallet_reward( $uid, $prize, 1 === $rank ? 'weekly_winner' : 'weekly_podium_' . $rank, array(
			'delicat_week_boundary' => $boundary, 'delicat_week_rank' => $rank,
			'delicat_reward_kind' => 'weekly', 'delicat_reward_amount' => $prize,
		), time() + 5 * DAY_IN_SECONDS );
		if ( $oid ) {
			$o = wc_get_order( $oid );
			$week['placements'][ $rank ] = array( 'status' => 'claimable', 'uid' => $uid, 'prize' => $prize, 'oid' => $oid, 'amount_htg' => (float) $o->get_meta( '_delicat_wallet_amount' ), 'expires_at' => (int) $o->get_meta( '_delicat_reward_expires' ) );
		} else {
			$week['placements'][ $rank ] = array( 'status' => 'failed_retryable', 'uid' => $uid, 'prize' => $prize, 'oid' => 0, 'last_try' => current_time( 'mysql' ) ); $complete = false;
		}

		$results[ $rank ] = array( 'uid' => $uid, 'prize' => $prize, 'oid' => $oid );
	}
	$week['complete'] = $complete ? 1 : 0; $week['updated'] = current_time( 'mysql' );
	/* Notify admin only when the meaningful delivery state changes (e.g. first
	 * failure, then later success), not on every hourly retry. */
	$fpdata = array(); foreach ( array( 1, 2, 3 ) as $r ) { $p = $week['placements'][ $r ] ?? array(); $fpdata[ $r ] = array( $p['status'] ?? '', (int) ( $p['uid'] ?? 0 ), (int) ( $p['prize'] ?? 0 ), (int) ( $p['oid'] ?? 0 ) ); }
	$fingerprint = md5( wp_json_encode( $fpdata ) ); $send_alert = $results && $fingerprint !== ( $week['alert_fingerprint'] ?? '' );
	$week['alert_fingerprint'] = $fingerprint; $all[ $key ] = $week; delicat_weekly_state_save( $all );
	foreach ( $results as $r ) if ( ! empty( $r['oid'] ) ) { try { delicat_wallet_available_email( wc_get_order( $r['oid'] ) ); } catch ( Throwable $e ) { delicat_log( 'error', 'Winner availability notification failed.' ); } }
	if ( $send_alert ) delicat_podium_alert( $results );
	return $complete;
}

/**
 * Hooks and customer e-mail once a weekly prize order exists. Up to 3.2.2 a braceless if
 * guarded only the first hook, so the 2nd and 3rd places also got the 1st place's
 * « Ou se Meilleur Client semèn nan » e-mail. Their reward order still sends WooCommerce's
 * completed-order e-mail; delicat_weekly_podium still fires for every place.
 */
function delicat_weekly_prize_delivered( $uid, $oid, $rank, $prize ) {
	$rank = (int) $rank;
	if ( 1 === $rank ) {
		do_action( 'delicat_weekly_winner', $uid, $oid );
	}
	do_action( 'delicat_weekly_podium', $uid, $oid, $rank, $prize );
	if ( 1 === $rank ) {
		delicat_notify_winner( $uid, $oid, $prize );
	}
}

/** One admin email summarising every podium placement of the week. */
function delicat_podium_alert( array $results ) {
	$lines = array();
	foreach ( $results as $rank => $r ) {
		$u   = get_userdata( $r['uid'] );
		$who = $u ? $u->display_name : ( '#' . $r['uid'] );
		$lines[] = '#' . $rank . ' — ' . $who . ' — ' . $r['prize'] . ' 💎 — '
			. ( $r['oid'] ? 'récompense à réclamer sous 5 jours #' . $r['oid']
				: 'Récompense HTG indisponible — vérifiez le montant dans WooCommerce ▸ Delicat Rewards.' );
	}
	wp_mail( get_option( 'admin_email' ), 'Delicat — Meilleur Client (podium)', implode( "\n", $lines ) );
}

/* =========================================================================
   3.4.2 (RWD-02) — A REFUND AFTER THE WEEK CLOSED
   A purchase counted in a closed week is refunded or cancelled: that week's ranking is computed again
   (in the background, a minute later, several refunds together). If the podium is no longer the same,
   the prizes concerned are marked « à vérifier » and the team gets the old and the new podium by e-mail.
   Nothing is taken back automatically (no wallet debit, no order change), and a prize still waiting for
   its automatic delivery is held until the team has checked.
   ========================================================================= */
function delicat_podium_recheck_order( $order_id ) {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	if ( ! $order || ! is_a( $order, 'WC_Order' ) || $order->get_meta( '_delicat_reward' ) || ! (int) $order->get_customer_id() ) return;
	$at = delicat_order_counted_at( $order );
	if ( $at < 1 || $at >= delicat_competition_start() ) return; // the week in progress: its board is rebuilt anyway
	$eligible = false;
	foreach ( $order->get_items() as $item ) { if ( delicat_item_is_ff( $item ) ) { $eligible = true; break; } } // only a purchase the board counts
	if ( ! $eligible ) return;
	foreach ( delicat_weekly_state_get() as $key => $week ) {
		if ( ! is_array( $week ) || empty( $week['ranking'] ) ) continue;
		$start = isset( $week['start'] ) && is_numeric( $week['start'] ) ? (int) $week['start'] : delicat_closed_period_start( (int) $key );
		if ( $at < $start || $at >= (int) $key ) continue;
		$why = get_option( 'delicat_podium_recheck_orders', array() );
		$why = is_array( $why ) ? $why : array();
		$why[ (int) $key ] = array_slice( array_values( array_unique( array_merge( (array) ( $why[ (int) $key ] ?? array() ), array( (int) $order->get_id() ) ) ) ), -20 );
		update_option( 'delicat_podium_recheck_orders', $why, false );
		delicat_bg_once( 'delicat_podium_recheck', array( (int) $key ), time() + MINUTE_IN_SECONDS );
	}
}
add_action( 'woocommerce_order_status_changed', function ( $order_id, $from, $to ) {
	if ( in_array( $from, delicat_counting_statuses(), true ) && ! in_array( $to, delicat_counting_statuses(), true ) ) delicat_podium_recheck_order( $order_id );
}, 25, 3 );
add_action( 'woocommerce_order_partially_refunded', 'delicat_podium_recheck_order', 25, 1 );
add_action( 'woocommerce_order_refunded', 'delicat_podium_recheck_order', 25, 1 );

/** The top 3 of rows: rank => customer id. */
function delicat_podium_of( array $rows ) {
	$out = array(); $rank = 0;
	foreach ( $rows as $uid => $r ) { if ( ++$rank > 3 ) break; $out[ $rank ] = (int) $uid; }
	return $out;
}
function delicat_podium_recheck( $key ) {
	$key = (int) $key;
	if ( ! delicat_lock( 'delicat_competition_control', 2 ) ) { delicat_bg_once( 'delicat_podium_recheck', array( $key ), time() + 2 * MINUTE_IN_SECONDS ); return; }
	try {
		$all  = delicat_weekly_state_get();
		$week = $all[ $key ] ?? null;
		if ( ! is_array( $week ) || empty( $week['ranking'] ) || ! is_array( $week['ranking'] ) ) return;
		$start = isset( $week['start'] ) && is_numeric( $week['start'] ) ? (int) $week['start'] : delicat_closed_period_start( $key );
		$old   = array(); foreach ( $week['ranking'] as $r => $uid ) $old[ (int) $r ] = (int) $uid;
		$new   = delicat_podium_of( delicat_collect_leaderboard_rows( $start, $key - 1 ) );
		$moved = array();
		foreach ( array( 1, 2, 3 ) as $r ) {
			$p = $week['placements'][ $r ] ?? array();
			if ( ( $old[ $r ] ?? 0 ) === ( $new[ $r ] ?? 0 ) || 'disabled' === ( $p['status'] ?? '' ) || (int) ( $p['prize'] ?? 0 ) < 1 ) continue; // same customer, or no prize at this place
			$moved[] = $r;
		}
		$orders = get_option( 'delicat_podium_recheck_orders', array() );
		$orders = is_array( $orders ) ? $orders : array();
		$ids    = array_map( 'intval', (array) ( $orders[ $key ] ?? array() ) );
		unset( $orders[ $key ] );
		update_option( 'delicat_podium_recheck_orders', $orders, false );
		$fp = md5( wp_json_encode( $new ) );
		if ( ! $moved || ( $week['recheck']['fp'] ?? '' ) === $fp ) return; // podium unchanged, or this change already reported
		$week['recheck'] = array( 'new' => $new, 'old' => $old, 'at' => current_time( 'mysql' ), 'fp' => $fp, 'orders' => $ids );
		foreach ( $moved as $r ) $week['placements'][ $r ]['verify'] = 1;
		$all[ $key ] = $week;
		delicat_weekly_state_save( $all );
	} finally {
		delicat_unlock( 'delicat_competition_control' );
	}
	delicat_audit_add( sprintf( 'Meilleur client : podium de la période terminée le %s changé après un remboursement — à vérifier', wp_date( 'd/m/Y H:i', $key ) ) );
	$line = function ( $r, $uid, $p ) {
		if ( ! $uid ) return '#' . $r . ' — personne';
		$u = get_userdata( $uid );
		return '#' . $r . ' — ' . ( $u ? $u->display_name : 'compte supprimé' ) . ' (client #' . (int) $uid . ')' . ( $p ? ' — prix ' . (int) ( $p['prize'] ?? 0 ) . ' 💎, ' . ( in_array( $p['status'] ?? '', array( 'delivered', 'manual' ), true ) ? 'déjà remis' : 'pas encore remis' ) : '' );
	};
	$body = array( 'Une commande comptée dans la semaine terminée le ' . wp_date( 'd/m/Y H:i', $key ) . ' a été remboursée ou annulée' . ( $ids ? ' (commande ' . implode( ', ', array_map( function ( $id ) { return '#' . $id; }, $ids ) ) . ')' : '' ) . '. Le classement de cette semaine a été recalculé et le podium a changé.', '', 'Ancien podium (celui des prix) :' );
	foreach ( array( 1, 2, 3 ) as $r ) $body[] = $line( $r, $old[ $r ] ?? 0, $week['placements'][ $r ] ?? array() );
	$body[] = ''; $body[] = 'Nouveau podium :';
	foreach ( array( 1, 2, 3 ) as $r ) $body[] = $line( $r, $new[ $r ] ?? 0, null );
	$body[] = ''; $body[] = 'Rien n’a été repris ni redonné automatiquement : aucun portefeuille débité, aucune commande modifiée. Les prix concernés sont marqués « à vérifier » dans WooCommerce › Delicat Rewards › Meilleur client › Historique et remises. Un prix pas encore livré attend votre décision.';
	wp_mail( get_option( 'admin_email' ), 'Delicat — Meilleur Client : podium à vérifier après un remboursement', implode( "\n", $body ) );
}
add_action( 'delicat_podium_recheck', 'delicat_podium_recheck' );

function delicat_award_alert( $winner, $order_id ) {
	$u   = get_userdata( $winner );
	$who = $u ? $u->display_name : ( '#' . $winner );
	$msg = $order_id
		? 'Gagnant de la semaine : ' . $who . ' — commande récompense #' . $order_id
		: 'Gagnant : ' . $who . ' — ÉCHEC livraison (PIN du prix introuvable ou prix non composable, vérifiez WooCommerce ▸ Delicat Rewards).';
	wp_mail( get_option( 'admin_email' ), 'Delicat — Meilleur Client', $msg );
}

/** Stable public alias that reveals nothing about the account. */
function delicat_public_alias( $user_id ) {
	return 'Client ' . substr( hash_hmac( 'sha256', (string) (int) $user_id, delicat_secret() ), 0, 6 );
}

/**
 * A name another customer may see. Passwordless and app sign-ups have no first name and a
 * display name equal to the login, which is the e-mail local part: that was shown to every
 * signed-in customer. Never show the login, anything with '@', or a value equal to the login
 * or the e-mail local part, in either mode; fall back to the alias.
 */
function delicat_display_name( $user_id, $masked = null ) {
	$masked = null === $masked ? ( delicat_cfg( 'mask_names' ) || delicat_cfg( 'board_public' ) ) : (bool) $masked;
	$u = get_userdata( (int) $user_id );
	if ( ! $u ) return 'Kliyan';
	$flat  = function ( $v ) { return strtolower( preg_replace( '/[^\p{L}\p{N}]+/u', '', (string) $v ) ); };
	$email = (string) $u->user_email;
	$local = explode( '@', $email );
	$taken = array_filter( array( $flat( $u->user_login ), $flat( $local[0] ), $flat( $email ) ) );
	$safe  = function ( $v ) use ( $flat, $taken ) {
		$v = trim( wp_strip_all_tags( (string) $v ) );
		return '' !== $v && false === strpos( $v, '@' ) && ! in_array( $flat( $v ), $taken, true ) ? $v : '';
	};
	/* 3.3.0: also refuse a name that merely contains the login or the e-mail local part
	   (« jeanrobert509 » typed as a first name), as the app already did. */
	$contains = function ( $v ) use ( $flat, $u, $local ) {
		$seen = $flat( $v );
		foreach ( array( $u->user_login, $local[0] ) as $secret ) {
			$secret = $flat( $secret );
			if ( strlen( $secret ) >= 4 && false !== strpos( $seen, $secret ) ) return true;
		}
		return false;
	};
	$first = $safe( $u->first_name );
	$last  = $safe( $u->last_name );
	if ( '' !== $first && $contains( $first ) ) $first = '';
	if ( '' !== $last && $contains( $last ) ) $last = '';
	if ( ! $masked ) {
		$full = $safe( $u->display_name );
		if ( '' !== $full && ! $contains( $full ) ) return mb_substr( $full, 0, 60 );
		if ( '' !== $first ) return mb_substr( trim( $first . ' ' . $last ), 0, 60 );
		return delicat_public_alias( $u->ID );
	}
	/* Masked: « Jean R. » from the first name, or from a safe display name. */
	if ( '' === $first ) {
		$words = preg_split( '/\s+/u', $safe( $u->display_name ), -1, PREG_SPLIT_NO_EMPTY );
		$first = $words ? $safe( $words[0] ) : '';
		if ( '' === $last && count( $words ) > 1 ) $last = $safe( end( $words ) );
	}
	if ( '' === $first ) return delicat_public_alias( $u->ID );
	$parts = preg_split( '/\s+/u', $first, -1, PREG_SPLIT_NO_EMPTY );
	$initial = mb_substr( $last, 0, 1 ); // WordPress polyfills mb_substr, not mb_strtoupper
	$initial = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initial ) : strtoupper( $initial );
	return mb_substr( $parts[0], 0, 30 ) . ( '' !== $last ? ' ' . $initial . '.' : '' );
}

/* =========================================================================
   CONCURRENCY LOCK + RATE LIMIT
   ========================================================================= */
function delicat_lock( $name, $timeout = 5 ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) ) === 1;
}
function delicat_unlock( $name ) {
	global $wpdb;
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
}
/** Per-user + per-IP + per-day throttle: blocks scripted hammering of /claim.
 * A tiny global guard makes the transient read/increment/write sequence atomic. */
function delicat_rate_ok( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! delicat_lock( 'delicat_rate_guard_' . $user_id, 1 ) ) return false;
	try {
		$k = 'delicat_rl_' . $user_id;
		$n = (int) get_transient( $k );
		if ( $n >= DELICAT_CLAIMS_PER_MIN ) return false;

		$dk = 'delicat_rld_' . $user_id . '_' . date( 'Ymd' );
		$dn = (int) get_transient( $dk );
		if ( $dn >= DELICAT_CLAIMS_PER_DAY ) return false;

		$ip = delicat_client_ip(); // 3.4.0: the visitor, not the Cloudflare server everyone shares
		if ( $ip ) {
			$ik = 'delicat_rli_' . md5( $ip );
			$in = (int) get_transient( $ik );
			if ( $in >= DELICAT_IP_PER_MIN ) return false;
			set_transient( $ik, $in + 1, MINUTE_IN_SECONDS );
		}

		set_transient( $k, $n + 1, MINUTE_IN_SECONDS );
		set_transient( $dk, $dn + 1, DAY_IN_SECONDS );
		return true;
	} finally { delicat_unlock( 'delicat_rate_guard_' . $user_id ); }
}

/**
 * Takes the one-shot slot of a claim: INSERT IGNORE on the options table's unique name is
 * atomic in the database, whatever the lock layer did. Returns the slot name, or '' when the
 * slot was already taken.
 */
function delicat_claim_slot( $user_id, $month, $tier, $claim_number ) {
	global $wpdb;
	$name = 'delicat_cs_' . preg_replace( '/[^0-9-]/', '', (string) $month ) . '_' . substr( hash_hmac( 'sha256', (int) $user_id . '|' . $tier . '|' . (int) $claim_number, delicat_secret() ), 0, 24 );
	$done = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
	if ( 1 === (int) $done ) return $name;
	/* A slot left by a request that died while issuing (no reward order was created for this
	   claim number, or the number would have moved on): taken over after 15 minutes, atomically. */
	$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
	if ( '' !== $held && ctype_digit( $held ) && time() - (int) $held > 15 * MINUTE_IN_SECONDS ) {
		$moved = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", (string) time(), $name, $held ) );
		if ( 1 === (int) $moved ) return $name;
	}
	return '';
}
function delicat_claim_slot_release( $slot ) {
	global $wpdb;
	if ( $slot ) $wpdb->delete( $wpdb->options, array( 'option_name' => $slot ), array( '%s' ) );
}
/* Slots of months that are over are no longer needed: swept by WordPress's own daily clean-up event. */
add_action( 'wp_scheduled_delete', function () {
	global $wpdb;
	$keep = wp_date( 'Y-m', strtotime( '-1 month', delicat_month_start() ), wp_timezone() );
	/* 4.1.0: only past months are read (names sort by month): 500 slots of the current month no longer hide the old ones. */
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s LIMIT 500", $wpdb->esc_like( 'delicat_cs_' ) . '%', 'delicat_cs_' . $keep ) );
	foreach ( (array) $rows as $name ) {
		$month = substr( (string) $name, 11, 7 );
		if ( preg_match( '/^\d{4}-\d{2}$/', $month ) && $month < $keep ) $wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
	}
} );

/* =========================================================================
   HARDENED CLAIM HANDLER
   ========================================================================= */
/** Website route POST delicat/v1/claim: the shared claim below, answered in the page's shape. */
function delicat_rest_claim( WP_REST_Request $req ) {
	$result = delicat_claim_for_current_user( (string) $req->get_param( 'tier' ), (string) $req->get_param( 'token' ) );
	if ( is_wp_error( $result ) ) return $result;
	$reply = $result['state'];
	if ( is_array( $result['delivery'] ) ) $reply['delivery'] = $result['delivery'];
	return delicat_private_response( $reply );
}

/**
 * One claim for the signed-in customer, shared by the website route and the app contract
 * (delicat_rewards_app_claim). It takes no user id: the claim is always for
 * get_current_user_id(), so no caller can claim for another account. Every rule lives
 * here: pause and test mode, rate limits, the customer+month lock, the signed ledger, the
 * signed token, and the exact-retry lookup that returns the order a lost response created.
 *
 * @return array|WP_Error ['order_id' => int, 'state' => delicat_compute_state(), 'delivery' => array|null]
 */
function delicat_claim_for_current_user( $tier, $token ) {
	if ( ! delicat_rewards_on() ) {
		return new WP_Error( 'paused', 'Programme momentanément en pause.', array( 'status' => 503 ) );
	}
	if ( (int) delicat_cfg( 'dry_run' ) === 1 ) {
		return new WP_Error( 'dry', 'Programme en mode test — les réclamations sont désactivées pour le moment.', array( 'status' => 503 ) );
	}
	/* Logged-in accounts only. No cookie, no nonce, no claim. */
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'auth', 'Connectez-vous pour réclamer votre bonus.', array( 'status' => 401 ) );
	}
	$user_id = get_current_user_id();
	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		return new WP_Error( 'auth', 'Connectez-vous pour réclamer votre bonus.', array( 'status' => 401 ) );
	}
	if ( delicat_is_excluded( $user_id ) ) {
		return new WP_Error( 'excluded', 'Votre compte ne participe pas au programme. Contactez le support.', array( 'status' => 403 ) );
	}
	if ( ! delicat_rate_ok( $user_id ) ) {
		return new WP_Error( 'slow_down', 'Trop de demandes. Réessayez plus tard.', array( 'status' => 429 ) );
	}

	$tier  = sanitize_key( (string) $tier );
	$token = (string) $token;
	if ( strlen( $token ) > 255 ) $token = ''; // the website route refuses these before; a token is never that long
	$tiers = delicat_tiers();
	if ( ! isset( $tiers[ $tier ] ) ) {
		return new WP_Error( 'bad_tier', 'Tier invalide.', array( 'status' => 400 ) );
	}

	$month = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	$lock = 'delicat_claim_' . $user_id . '_' . $month;
	if ( ! delicat_lock( $lock, 5 ) ) {
		return new WP_Error( 'busy', 'Réclamation en cours, réessayez.', array( 'status' => 429 ) );
	}

	try {
        // Exact request retry returns the existing order, even if its response was lost.
        $request_hash = hash( 'sha256', $token );
        $existing = delicat_reward_query( $user_id, 1, $request_hash );
        if ( ! empty( $existing->orders ) ) {
            $prior = $existing->orders[0];
            if ( (string) $prior->get_meta( '_delicat_reward' ) !== $tier ) return new WP_Error( 'stale', 'Demande invalide.', array( 'status' => 409 ) );
            if ( 'wallet' === $prior->get_meta( '_delicat_reward_delivery' ) ) delicat_credit_wallet_reward( $prior->get_id(), $user_id );
            $delivery = delicat_reward_delivery( $prior->get_id(), $user_id );
            return array( 'order_id' => (int) $prior->get_id(), 'state' => delicat_compute_state( $user_id ), 'delivery' => is_wp_error( $delivery ) ? null : $delivery );
        }
		$ledger = delicat_ledger_get( $user_id, $month );

		/* The stored counters no longer match their signature → someone edited
		   them outside this plugin. Freeze, warn, hand out nothing. */
		if ( empty( $ledger['ok'] ) ) {
			return new WP_Error( 'frozen', 'Vos bonus sont temporairement bloqués. Contactez le support.', array( 'status' => 423 ) );
		}

		/* Recomputed from the real, paid, non-refunded orders — inside the lock. */
		$state = delicat_compute_state( $user_id );
		if ( $month !== wp_date( 'Y-m', delicat_month_start(), wp_timezone() ) ) return new WP_Error( 'expired', 'Ce bonus a expiré à la fin du mois.', array( 'status' => 410 ) );
		$claimed_now = (int) ( $state['tiers'][ $tier ]['claimed'] ?? 0 );

		if ( empty( $state['tiers'][ $tier ]['claimable'] ) ) {
			$why = $state['tiers'][ $tier ]['blocked'] ?? '';
			$msg = 'cap' === $why    ? 'Plafond mensuel de récompenses atteint.'
				 : ( 'max' === $why  ? 'Limite de réclamations atteinte pour ce bonus ce mois-ci.'
				 : ( 'frozen' === $why ? 'Vos bonus sont temporairement bloqués. Contactez le support.'
				 : 'Pas encore éligible : achats Free Fire payés et terminés insuffisants ce mois-ci.' ) );
			return new WP_Error( 'not_ready', $msg, array( 'status' => 400 ) );
		}

		/* The button must carry the signature we issued for this exact user,
		   tier, month and claim count. Replays and forged calls stop here. */
		if ( ! delicat_token_ok( $token, $user_id, $tier, $month, $claimed_now ) ) {
			return new WP_Error( 'stale', 'Demande expirée. Rechargez la page et réessayez.', array( 'status' => 409 ) );
		}

		if ( ! apply_filters( 'delicat_can_claim', true, $user_id, $tier, $state ) ) {
			return new WP_Error( 'not_allowed', 'Réclamation non autorisée.', array( 'status' => 403 ) );
		}

		/* 3.3.0: one claim number, one reward, enforced by the database itself (a unique option
		   name), so two requests that both passed the lock on a pooled or replicated database
		   still cannot issue the same reward twice. */
		$slot = delicat_claim_slot( $user_id, $month, $tier, $claimed_now );
		if ( ! $slot ) {
			return new WP_Error( 'busy', 'Réclamation déjà en cours. Vérifiez « Mes récompenses ».', array( 'status' => 409 ) );
		}

		if ( delicat_reward_value( $tiers[ $tier ]['reward'] ) <= 0 || ! delicat_wallet_available() ) {
			delicat_claim_slot_release( $slot );
			return new WP_Error( 'wallet_unavailable', 'Le montant HTG ou le portefeuille est indisponible. Contactez le support.', array( 'status' => 503 ) );
		}
		if ( time() >= (int) $state['resetAt'] ) { delicat_claim_slot_release( $slot ); return new WP_Error( 'expired', 'Ce bonus a expiré à la fin du mois.', array( 'status' => 410 ) ); }
		/* Reserve the claim (signed) BEFORE issuing anything. */
		$claims           = $ledger['claims'];
		$before           = isset( $claims[ $tier ] ) ? (int) $claims[ $tier ] : 0;
		$claims[ $tier ]  = $before + 1;
		delicat_ledger_save( $user_id, $month, $claims, $ledger['debt'] ?? null );

		$order_id = 0;
		try {
			$order_id = delicat_issue_reward( $user_id, $tier, $request_hash, $month );
		} finally {
			if ( ! $order_id ) delicat_claim_slot_release( $slot ); // an exception never leaves the slot taken
		}
		if ( ! $order_id ) {
			$claims[ $tier ] = $before;                 // roll back — never a free second reward
			delicat_ledger_save( $user_id, $month, $claims, $ledger['debt'] ?? null );
			delicat_claim_slot_release( $slot );
			return new WP_Error( 'issue_failed', 'Récompense indisponible. Contactez le support.', array( 'status' => 500 ) );
		}

        try { do_action( 'delicat_reward_claimed', $user_id, $tier ); }
        catch ( \Throwable $e ) { delicat_log( 'error', 'Post-claim callback failed.', array( 'order_id' => $order_id ) ); }
		delicat_flush_user( $user_id );
        $delivery = delicat_reward_delivery( $order_id, $user_id );
        return array( 'order_id' => (int) $order_id, 'state' => delicat_compute_state( $user_id ), 'delivery' => is_wp_error( $delivery ) ? null : $delivery );
	} catch ( \Throwable $e ) {
		delicat_log( 'error', 'Claim exception; inspect order state before retrying.', array( 'user_id' => (int) $user_id, 'tier' => $tier ) );
		return new WP_Error( 'error', 'Erreur serveur.', array( 'status' => 500 ) );
	} finally {
		delicat_unlock( $lock );
	}
}

/* =========================================================================
   REST API
   ========================================================================= */
add_action( 'rest_api_init', function () {
	/* Logged in, real account, not a guest. Applies to every route below. */
	$logged_in = function () {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'auth', 'Connectez-vous.', array( 'status' => 401 ) );
		}
		return (bool) get_userdata( get_current_user_id() );
	};

	register_rest_route( 'delicat/v1', '/rewards', array(
		'methods'             => 'GET',
		'permission_callback' => $logged_in,
		'callback'            => function () {
			$state = delicat_user_state_cached( get_current_user_id() );
            $state['serverTime'] = time();
			return delicat_private_response( $state ); // never cached by a proxy, a CDN or LiteSpeed
		},
	) );

	register_rest_route( 'delicat/v1', '/claim', array(
		'methods'             => 'POST',
		'permission_callback' => $logged_in,
		'callback'            => 'delicat_rest_claim',
		'args'                => array(
			'tier'  => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'token' => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => function ( $v ) { return is_string( $v ) && strlen( $v ) < 256; },
			),
		),
	) );

	register_rest_route( 'delicat/v1', '/leaderboard', array(
		'methods'             => 'GET',
		'permission_callback' => function () use ( $logged_in ) {
			if ( (int) delicat_cfg( 'board_public' ) === 1 ) return true;
			return $logged_in();
		},
		'callback'            => function () {
			$board = delicat_board_snapshot( is_user_logged_in() ? get_current_user_id() : 0 );
			$top   = array();
			foreach ( $board['top'] as $row ) $top[] = array( 'name' => $row['name'], 'pct' => $row['pct'] ); // the page's shape: no rank, no id
			return delicat_private_response( array( 'top' => $top, 'you' => $board['you'], 'prizeCredits' => array_map( 'delicat_reward_value', array_values( delicat_meilleur_prizes() ) ) ) );
		},
	) );
} );

/**
 * The board customers see, shared by the website route and the app contract: the rows up to
 * the board size with their score as a percentage of the leader's (never money or order
 * counts), and the viewer's own line with the gap to the place ahead. 'uid' never leaves the
 * server: callers use it for "is this me" and drop it.
 *
 * @param int           $viewer_id Signed-in customer, 0 for a guest.
 * @param callable|null $name      Public name of a customer id; the website's setting by default.
 */
function delicat_board_snapshot( $viewer_id = 0, $name = null ) {
	$name   = is_callable( $name ) ? $name : 'delicat_display_name';
	$ranked = delicat_leaderboard();
	$top    = array();
	$lead   = 0.0;
	$size   = delicat_board_size();
	/* 4.1.0: the accounts shown (and the viewer's) in two queries instead of two per name. */
	$ids = (int) $viewer_id > 0 ? array( (int) $viewer_id ) : array();
	foreach ( $ranked as $uid => $r ) { if ( $r['rank'] > $size ) break; $ids[] = (int) $uid; }
	if ( count( $ids ) > 1 && function_exists( 'cache_users' ) ) cache_users( array_values( array_unique( $ids ) ) );
	foreach ( $ranked as $r ) { $lead = max( $lead, (float) $r['score'] ); break; } // first = leader
	foreach ( $ranked as $uid => $r ) {
		if ( $r['rank'] > $size ) break;
		/* pct = score relative to the leader. Reveals momentum, never gourdes. */
		$pct = $lead > 0 ? (int) round( 100 * (float) $r['score'] / $lead ) : 0;
		$top[] = array(
			'uid'  => (int) $uid,
			'rank' => (int) $r['rank'],
			'name' => call_user_func( $name, $uid ),
			'pct'  => max( 4, min( 100, $pct ) ),
		);
	}
	$you = null;
	$uid = (int) $viewer_id;
	if ( $uid > 0 && isset( $ranked[ $uid ] ) ) {
		$me = $ranked[ $uid ];
		$gap = 0; $ahead = 0;
		if ( $me['rank'] > 1 ) {
			foreach ( $ranked as $r ) {
				if ( $r['rank'] === $me['rank'] - 1 ) {
					$score_gap = max( 0.0, (float) $r['score'] - (float) $me['score'] );
					$gap   = $lead > 0 && $score_gap > 0 ? max( 1, (int) ceil( 100 * $score_gap / $lead ) ) : 0;
					$ahead = $r['rank'];
					break;
				}
			}
		}
		$you = array(
			'rank'   => $me['rank'],
			'name'   => call_user_func( $name, $uid ),
			'gap'    => $gap,
			'ahead'  => $ahead,
			'total'  => count( $ranked ),
			'pct'    => $lead > 0 ? max( 0, min( 100, (int) round( 100 * (float) $me['score'] / $lead ) ) ) : 0,
		);
	}
	return array( 'top' => $top, 'you' => $you );
}

/* =========================================================================
   SHARED HELPERS (client address, audit log, sanitising) — used by claims, cron and admin
   ========================================================================= */
/**
 * The visitor's address for limits and logs: Identity's helper (Cloudflare's CF-Connecting-IP, trusted only
 * from Cloudflare's own servers; IPv6 grouped by /64), else REMOTE_ADDR with IPv6 grouped by /64. Behind
 * Cloudflare, REMOTE_ADDR alone is the Cloudflare server: every visitor would share one counter.
 */
function delicat_client_ip() {
	if ( class_exists( 'DIP_Native_Auth' ) && method_exists( 'DIP_Native_Auth', 'throttle_ip' ) ) {
		$ip = (string) DIP_Native_Auth::throttle_ip();
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
	if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		$packed = inet_pton( $ip );
		if ( false !== $packed ) $ip = (string) inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) );
	}
	return $ip;
}

/** Who changed what, when, from where. Last 40 entries. */
function delicat_audit_add( $what ) {
	$log = get_option( 'delicat_audit', array() );
	if ( ! is_array( $log ) ) $log = array();
	$u  = wp_get_current_user();
	$ip = delicat_client_ip();
	array_unshift( $log, array(
		't'  => current_time( 'mysql' ),
		'u'  => ( $u && $u->ID ) ? $u->user_login : 'système',
		'ip' => $ip ? $ip : '—',
		'w'  => mb_substr( (string) $what, 0, 400 ),
	) );
	update_option( 'delicat_audit', array_slice( $log, 0, 40 ), false );
}

/** Short, safe, length-capped text from a form field. */
function delicat_txt( $v, $len = DELICAT_TEXT_LEN ) {
	$v = sanitize_text_field( is_scalar( $v ) ? (string) $v : '' );
	return mb_substr( $v, 0, (int) $len );
}

/* =========================================================================
   SHARED ASSETS
   ========================================================================= */
function delicat_print_fonts() {
	static $done = false; if ( $done ) return; $done = true;
	/* Performance-first: inherit the site's/system fonts by default. External fonts can
	 * still be re-enabled by developers without changing this plugin. */
	if ( ! apply_filters( 'delicat_rewards_load_external_fonts', false ) ) return;
	echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
	echo '<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
	echo '<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">';
}

/** Shown to guests instead of any data whatsoever. */
function delicat_login_html( $msg = '' ) {
	$msg = $msg ?: 'Konekte ak kont ou pou w wè pwogresyon ou ak reklame bonis ou yo.';
	$url = wp_login_url( get_permalink() );
	return '<div style="max-width:520px;margin:24px auto;font-family:\'Poppins\',sans-serif;text-align:center;">'
		. '<div style="background:linear-gradient(160deg,#ecdde1,#e1cdd4);border-radius:18px;padding:32px 24px;box-shadow:0 18px 40px -22px rgba(51,33,90,.55);">'
		. '<div style="font-size:2.2rem;margin-bottom:10px;">🔐</div>'
		. '<div style="font-family:\'Playfair Display\',serif;font-weight:800;font-size:1.4rem;color:#33215a;margin-bottom:8px;">Espace réservé aux membres</div>'
		. '<div style="color:#4d4654;font-size:1rem;line-height:1.5;margin-bottom:16px;">' . esc_html( $msg ) . '</div>'
		. '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#5e3f97;color:#fff;text-decoration:none;padding:12px 26px;border-radius:9px;font-weight:600;">Konekte</a>'
		. '</div></div>';
}

/**
 * Everything the front-end needs, built from the dashboard config.
 * All strings go through wp_json_encode, and the JS prints them with
 * textContent / an escaper — an admin typo can never inject markup.
 */
function delicat_config_json() {
	$tiers = delicat_tiers();
	$out   = array();
	foreach ( $tiers as $key => $t ) {
		$out[ $key ] = array(
			'label'  => $t['label'],
			'title'  => $t['title'],
			'sub'    => $t['sub'],
			'pin'    => $t['pin'],
			'goal'   => $t['goal'],
			'reward' => $t['reward'],
			'creditAmount' => delicat_reward_value( $t['reward'] ),
		);
	}

	$texts = array(
		'pageTitle'   => (string) delicat_cfg( 'page_title' ),
		'programName' => (string) delicat_cfg( 'program_name' ),
		'spend'       => (string) delicat_cfg( 't_spend' ),
		'reward'      => (string) delicat_cfg( 't_reward' ),
		'progress'    => (string) delicat_cfg( 't_progress' ),
		'note'        => (string) delicat_cfg( 'note' ),
		'unit'        => (string) delicat_cfg( 'unit' ),
		'currency'    => (string) delicat_cfg( 'currency' ),
		'badgeSuffix' => (string) delicat_cfg( 'badge_suffix' ),
		'btnPrefix'   => (string) delicat_cfg( 'btn_prefix' ),
		'btnSuffix'   => (string) delicat_cfg( 'btn_suffix' ),
		'btnLocked'   => (string) delicat_cfg( 'btn_locked' ),
		'btnDry'      => (string) delicat_cfg( 'btn_dry' ),
		'boardKicker'      => (string) delicat_cfg( 'board_kicker' ),
		'boardTitle'       => (string) delicat_cfg( 'board_title' ),
		'boardGames'       => (string) delicat_cfg( 'board_games' ),
		'boardResetLabel'  => (string) delicat_cfg( 'board_reset_label' ),
		'boardRewardLabel' => (string) delicat_cfg( 'board_reward_label' ),
		'boardPrizeSuffix' => (string) delicat_cfg( 'board_prize_suffix' ),
		'boardRewardSub'   => (string) delicat_cfg( 'board_reward_sub' ),
		'boardYouLabel'    => (string) delicat_cfg( 'board_you_label' ),
		'boardLoginText'   => (string) delicat_cfg( 'board_login_text' ),
		'boardHeading'     => (string) delicat_cfg( 'board_heading' ),
		'boardHiddenText'  => (string) delicat_cfg( 'board_hidden_text' ),
		'boardEmptyText'   => (string) delicat_cfg( 'board_empty_text' ),
		'boardFirstText'   => (string) delicat_cfg( 'board_first_text' ),
		'boardRankText'    => (string) delicat_cfg( 'board_rank_text' ),
		'boardGapText'     => (string) delicat_cfg( 'board_gap_text' ),
		'boardStartText'   => (string) delicat_cfg( 'board_start_text' ),
		/* The board's footer lines: editable in Réglages and read by the page script, but
		   missing here up to 3.2.2, so the footer stayed empty. */
		'boardFooter1'     => (string) delicat_cfg( 'board_footer_1' ),
		'boardFooter2'     => (string) delicat_cfg( 'board_footer_2' ),
	);

	return wp_json_encode( array(
		'rest'      => esc_url_raw( rest_url( 'delicat/v1' ) ),
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'loggedIn'  => is_user_logged_in(),
		'userId'    => get_current_user_id(),
		'sessionUrl' => admin_url( 'admin-ajax.php' ),
		'loginUrl'  => wp_login_url( get_permalink() ),
		'boardPub'  => (int) delicat_cfg( 'board_public' ) === 1,
		'tiers'     => $out,
		'brand'     => array( 'name' => delicat_brand_name(), 'logo' => delicat_brand_logo_url() ),
		'texts'     => $texts,
		'resetDay'  => delicat_reset_day(),
		'resetHour' => delicat_reset_hour(),
		'wa'        => esc_url_raw( (string) delicat_cfg( 'wa' ) ),
		'prize'     => delicat_meilleur_prize(),
		'prizes'    => array_values( delicat_meilleur_prizes() ),
		'prizeCredits' => array_map( 'delicat_reward_value', array_values( delicat_meilleur_prizes() ) ),
		'rewardType' => 'wallet', 'walletUrl' => home_url( '/my-wallet/' ),
		'boardFooterUrl' => esc_url_raw( (string) delicat_cfg( 'board_footer_url' ) ),
		'nextReset' => delicat_next_week_start(),
		'serverNow' => time(),
	) );
}

/** CSS of the pre-3.3 pages (no markup of this plugin uses it any more): kept for themes, loaded on demand. */
function delicat_print_css() {
	static $done = false; if ( $done ) return; $done = true;
	require_once __DIR__ . '/rewards-legacy.php';
	delicat_print_css_legacy();
}

/* =========================================================================
   ADMIN EDITION — LICENSE SERVER
   ========================================================================= */
function delicat_license_records() {
	$r = get_option( 'delicat_reward_licenses', array() );
	return is_array( $r ) ? $r : array();
}
function delicat_license_save_records( $r ) { update_option( 'delicat_reward_licenses', $r, false ); }
function delicat_license_lock( $wait = 3 ) { return delicat_lock( 'delicat_license_store', $wait ); }
function delicat_license_unlock() { delicat_unlock( 'delicat_license_store' ); }
function delicat_license_normalize_domain( $domain ) {
	$domain = strtolower( trim( (string) $domain ) );
	$domain = preg_replace( '#^https?://#', '', $domain );
	$domain = preg_replace( '#/.*$#', '', $domain );
	$domain = preg_replace( '/:\d+$/', '', $domain );
	return sanitize_text_field( $domain );
}
function delicat_license_signature( array $payload, $key ) {
	ksort( $payload ); return hash_hmac( 'sha256', wp_json_encode( $payload ), (string) $key );
}
/**
 * 3.4.2 (RWD-04): the licence server sells « Client » copies of this plugin to other sites; the shop
 * itself never asks it anything (this edition checks no licence). Its public route and its admin page
 * now exist only when the server is in use: a licence was already issued (client sites keep working),
 * or wp-config.php has define( 'DELICAT_REWARDS_LICENSE_SERVER', true ) to issue the first one.
 */
function delicat_license_server_on() {
	if ( defined( 'DELICAT_REWARDS_LICENSE_SERVER' ) ) return (bool) DELICAT_REWARDS_LICENSE_SERVER;
	return (bool) delicat_license_records();
}
add_action( 'rest_api_init', function () {
	if ( ! delicat_license_server_on() ) return;
	register_rest_route( 'delicat-license/v1', '/validate', array(
		'methods' => 'POST', 'permission_callback' => '__return_true',
		'callback' => function( WP_REST_Request $req ) {
			$key = preg_replace( '/[^A-Z0-9-]/', '', strtoupper( (string) $req->get_param( 'license_key' ) ) );
			/* A client that sends a nonce gets it back inside the signed reply, so a recorded reply
			   cannot be replayed to it; older clients that send none get the same reply as before. */
			$client_nonce = preg_replace( '/[^A-Za-z0-9_-]/', '', substr( (string) $req->get_param( 'nonce' ), 0, 64 ) );
			$domain = delicat_license_normalize_domain( $req->get_param( 'domain' ) );
			if ( strlen( $key ) < 20 || ! $domain ) return new WP_REST_Response( array( 'valid'=>false, 'code'=>'invalid_request' ), 400 );
			$ip = delicat_client_ip();
			$rl = 'dlc_lic_' . md5( $ip ); $n=(int)get_transient($rl); if($n>30) return new WP_REST_Response(array('valid'=>false,'code'=>'rate_limited'),429); set_transient($rl,$n+1,MINUTE_IN_SECONDS);
			$hash = hash( 'sha256', $key );
			if ( ! delicat_license_lock( 3 ) ) return new WP_REST_Response( array( 'valid'=>false, 'code'=>'busy' ), 503 );
			try {
				$all = delicat_license_records();
				if ( empty( $all[$hash] ) || ! is_array( $all[$hash] ) ) return new WP_REST_Response( array( 'valid'=>false, 'code'=>'not_found' ), 404 );
				$lic = $all[$hash]; $now=time(); $valid = !empty($lic['active']); $code='ok';
				if ( $valid && !empty($lic['expires']) && strtotime($lic['expires'].' 23:59:59') < $now ) { $valid=false; $code='expired'; }
				if ( $valid && !empty($lic['domain']) && $lic['domain'] !== $domain ) { $valid=false; $code='domain_mismatch'; }
				if ( $valid && empty($lic['domain']) ) { $lic['domain']=$domain; $lic['activated_at']=current_time('mysql'); }
				$lic['last_check']=current_time('mysql'); $lic['last_ip']=$ip; $all[$hash]=$lic; delicat_license_save_records($all);
				$payload=array('valid'=>$valid,'code'=>$code,'domain'=>$domain,'expires'=>(string)($lic['expires']??''),'checked_at'=>$now,'edition'=>'client');
				if ( '' !== $client_nonce ) $payload['nonce'] = $client_nonce;
				$payload['signature']=delicat_license_signature($payload,$key);
				return new WP_REST_Response($payload,$valid?200:403);
			} finally {
				delicat_license_unlock();
			}
		}
	) );
} );

/* Dashboard screens, forms and notices: only in wp-admin (admin-post.php included), never on the storefront,
   in REST, AJAX or cron requests. 4.1.0: about 115 KB less code for every customer request. */
if ( ( is_admin() && ! wp_doing_ajax() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once __DIR__ . '/rewards-admin.php';
	require_once __DIR__ . '/referral-admin.php';
	require_once __DIR__ . '/competition-admin.php'; // V10: period closing form, history, admin-post action
}

require_once __DIR__ . '/competition.php'; // V10: periods and past winners (pages, REST, app contract)
require_once __DIR__ . '/app-contract.php';
require_once __DIR__ . '/referral.php';
require_once __DIR__ . '/bonus-page.php';
