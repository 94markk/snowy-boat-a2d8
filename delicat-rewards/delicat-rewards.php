<?php
/**
 * Plugin Name: Delicat Rewards — Admin Edition
 * Description: Complete Delicat Rewards system with branding controls and secure license server for Client editions.
 * Version:     3.4.0
 * Author:      Delicat Store Haiti
 * Requires PHP: 7.4
 *
 * NEW IN 3.4.0 — Referral programme « Parrainage » (referral.php, referral-admin.php).
 *   • Third tab on the Bonus Free Fire page: personal code DLC…… and link, share buttons (WhatsApp,
 *     Facebook, Telegram, SMS, the phone's share sheet), milestones, invited friends, code entry.
 *   • 1 point per friend who signs up through the link (or types the code early) and pays a first real
 *     order; points count after a holding period; up to 100; milestones claimed once, as a PIN reward
 *     order or TeraWallet credit. Off until enabled in the dashboard tab « Parrainage ».
 *   • Fraud rules: same phone (device cookie) refused and both accounts leave the rewards programme;
 *     shared payment phone or e-mail alias refused; same recent network sent to review; VPN refused
 *     (proxycheck.io, DELICAT_PROXYCHECK_KEY in wp-config.php); store-credit first orders excluded;
 *     refunds reverse points. Signals stored as keyed hashes only.
 *   • Page: links drawn as buttons keep their colours under Builder (« main a »); the page opts out of
 *     Builder's dark-mode repaint (data-dlc-dark-skip) and draws its own dark theme.
 *
 * NEW IN 3.3.0 — Bonus Free Fire page and security fixes (bonus-page.php).
 *   • One page, two tabs: « Classement » (Meilleur Client) and « Mes bonus », [delicat_bonus_free_fire];
 *     the older shortcodes open it on their tab. Celebrations, light and dark, Material icons only.
 *   • Cache-safe HTML; one no-store request (delicat_bonus_boot) for the session and both tabs.
 *   • Free lines (100 % coupon, $0 orders) earn no tier progress; per-customer rate-limit lock; a
 *     database one-shot per claim number; leaderboard rebuilt once, never in parallel; app « Mes bonus »
 *     only for the signed-in customer; licence keys in lower case and a signed client nonce; refund ids
 *     are not reward orders; login/e-mail parts never shown in website names; CSV and slug-pattern guards.
 *
 * NEW IN 3.2.4 — Persistent multi-supplier purchase mappings and secure 403 recovery.
 *   • Purchase IDs survive replacements/deletion; exact named historic packs recover.
 *   • Restricted AJAX transport keeps cookie, nonce and claim protections intact.
 *
 * NEW IN 3.2.3 — App contract (app-contract.php)
 *   • DELICAT_REWARDS_APP_CONTRACT + delicat_rewards_app_*(): a small, stable set of
 *     functions the Delicat App API wraps for the native app, so it never reaches into
 *     this plugin's internals. The claim is the website's own (one shared helper bound
 *     to the signed-in customer); board names are always masked for the app.
 *   • Fixed: the board footer lines never reached the page; the 1st place's weekly
 *     e-mail went to the 2nd and 3rd places too.
 *   • This build also carries the 3.2.1 audit fixes and the 3.2.2 Secure Keys sync.
 *
 * NEW IN 3.1.1 — Meilleur Client privacy
 *   • Client-facing REST responses no longer expose anyone's exact order count
 *     or eligible spend, including the connected customer's private card.
 *   • Podium, ranking rows and personal position now show relative score
 *     percentage only. Exact money/orders remain restricted to administrators.
 *   • The secure 70% spend + 30% distinct-order calculation is unchanged.
 *
 * NEW IN 3.1.0 — Meilleur Client: spend + real WooCommerce orders
 *   • Weekly ranking now gives 70% of the score to eligible net spend and 30%
 *     to the number of distinct paid + completed WooCommerce orders.
 *   • One WooCommerce order counts exactly once, even when it contains several
 *     eligible products or a product quantity greater than one.
 *   • Live leaderboard, final weekly podium and Participants admin page share
 *     one scoring engine, preventing display/award calculation drift.
 *   • Deterministic ties: score, then net spend, order count, earliest final
 *     qualifying order and finally customer ID.
 *   • v3.1.0 initially exposed personal metrics; v3.1.1 replaces every
 *     client-facing metric with privacy-safe relative percentages.
 *
 * NEW IN 3.0.1 — Guided setup / safer deployment
 *   • New Installation tab with live readiness checks, mapping status, shortcodes
 *     and an exact go-live checklist.
 *   • One-click safe test configuration forces Woo completed + paid + refund
 *     protection + PIN-only fulfilment + dry-run without touching product IDs.
 *   • Activation/update notice links directly to the Installation assistant.
 *
 * NEW IN 3.0.0 — Omni Free Fire Rewards + production hardening
 *   • Any mapped Free Fire purchase can earn rewards: PIN or direct top-up,
 *     across different servers/products/variations. WooCommerce remains the
 *     source of truth: logged-in customer + completed order + approved payment.
 *   • Reward fulfilment is PIN-only. Direct-top-up supplier logic is NEVER used
 *     to deliver a free reward; reward orders use the dedicated PIN catalogue.
 *   • Tier mappings always include the matching PIN product PLUS every configured
 *     direct/server product ID. Parent or exact variation IDs are supported.
 *   • Default journey corrected: 110×25 → 110 free; 341×20 → 341 free;
 *     572×15 → 572 free; 1160×12 → 1160 free; 2180×10 → 2180 free.
 *   • Claim lock is now per customer/month (not per tier), preventing stale-ledger
 *     overwrites and monthly-cap races across simultaneous tier claims.
 *   • Signed-ledger migration is one-way and reward orders are reconciled back
 *     into the ledger, so deleting legacy metadata cannot reset reward history.
 *   • Weekly podium delivery is locked/idempotent with per-rank state and retries;
 *     a failed prize no longer marks the whole week as successfully delivered.
 *   • Reward planning is stock/key-inventory aware at issuance; unavailable PINs
 *     are not selected and variable products no longer fall back to dead stock.
 *   • CSV formula injection hardening, safer public-name masking, Woo logger on
 *     claim failures, branding block restored, duplicate tier mappings diagnosed.
 *
 * NEW IN 2.9.0 — Podium prizes + heat bars (smarter Meilleur Client)
 *   • PODIUM PRIZES: optional 2nd / 3rd place prizes (Réglages ▸ Prix 2e/3e
 *     place, 0 = disabled = legacy winner-takes-all). At that version, weekly
 *     auto-award ranked the top 3 with its shared score (replaced by
 *     the 70% spend + 30% distinct-order engine in v3.1.0) and
 *     issues each configured prize: reward order + client email per rank,
 *     one admin summary email. Legacy hook delicat_weekly_winner still fires
 *     for rank 1; new hook delicat_weekly_podium( $uid, $oid, $rank, $prize ).
 *     Composability of each prize is validated in Réglages and Diagnostics.
 *   • HEAT BARS: /leaderboard now sends pct per entry = the player's score
 *     relative to the leader (never gourdes or order counts). Pods size their
 *     accent bar with it; rows 4+ show a slim gradient heat bar under the
 *     name. Front-end shows prize amounts under each podium spot when set.
 *
 * NEW IN 2.8.0 — Meilleur Client "Diamond Vault" + podium (on Premium Journey)
 *   • [delicat_meilleur_client] restyled to match the Rewards page: dark
 *     neo-glass panel, violet hero with light-sweep, countdown pill.
 *   • Top 3 render as a podium: gold centre (elevated, animated crown),
 *     silver left, bronze right, initials avatars, accent bars. Ranks 4+
 *     are dark rows. Two light inline styles removed from the board JS.
 *   • Scoped to #dlc-board — the Rewards page and its Premium Journey
 *     celebration modal are untouched. Same REST /leaderboard payload, same
 *     IDs, works with 0–3+ entries, reduced-motion safe, no color-mix /
 *     backdrop-filter.
 *
 * NEW IN 2.7.1 — Premium Journey & Celebration
 *   • [delicat_rewards] front-end restyled: dark neo-glass panel, Sora /
 *     Space Grotesk / Inter type, per-tier accent colours (Mini→Platinum),
 *     gem chips, badge pills and animated progress fills.
 *   • Fully scoped to #dlc-rewards — the [delicat_meilleur_client] leaderboard
 *     is byte-for-byte unchanged. No color-mix / backdrop-filter (safe on weak
 *     Android). Respects prefers-reduced-motion.
 *   • No logic touched: claim tokens, HMAC state, REST routes, dashboard
 *     config keys and every element ID are identical to 2.5.2.
 *
 * FIXED IN 2.3 — variable products
 *   A variation is stored by WooCommerce as a 'product_variation', not a
 *   'product', which is why its ID was rejected. Now:
 *     • A variation ID is accepted everywhere a product ID is.
 *     • An order line is matched on BOTH its variation ID and its parent ID:
 *       put the PARENT id in a tier → every variation counts;
 *       put ONE VARIATION id → only that variation counts.
 *     • Rewards: a variable parent cannot be placed in an order, so the plugin
 *       resolves it to the first purchasable, in-stock variation. Diagnostics
 *       shows exactly which one will be delivered.
 *
 * NEW IN 2.2
 *   • Email to the client the moment a reward is issued (and to the weekly
 *     winner). Templates editable, with {name} {tier} {reward} {unit} {order}.
 *   • Rapports tab: what the program cost you this month, CSV export of the
 *     claims and of the reward orders, and a read-only client simulator.
 *   • 🧪 Mode test: clients see their progress, buttons are greyed out, and
 *     NOTHING is ever issued. Safe way to change goals mid-month.
 *
 * WHAT CHANGED IN 2.1 — "a diamond only counts if it was really bought"
 *   • An order is counted ONLY if: it belongs to a logged-in customer (no guest
 *     orders), WooCommerce considers it really PAID, it is not a
 *     reward order, and it is not refunded. Refunded quantities are subtracted
 *     item by item, so a refund silently removes the progress it created.
 *   • Optional: require the PIN code to have actually been delivered.
 *   • Every page, every REST route requires a logged-in account. Guests see a
 *     login card and nothing else — no progress, no leaderboard, no data.
 *   • Reward counters are signed with HMAC-SHA256 (per-install secret key +
 *     WordPress salts). If anyone edits a claim counter directly in the
 *     database, the signature no longer matches: claims are frozen for that
 *     account and the admin is warned. Claim buttons carry a short-lived
 *     signed token, so a captured request cannot be replayed.
 *
 * WHAT CHANGED IN 2.0
 *   Nothing about the program is hard-coded any more. Everything is edited in
 *   WooCommerce ▸ Delicat Rewards :
 *     • Produits  : map each diamond amount to its WooCommerce product ID
 *                   (your 110 💎 moved from #197 to #289 → change it here).
 *                   A tier can keep several IDs, so old orders still count.
 *     • Tiers     : label, title, goal (x fois), reward, monthly claim limit,
 *                   on/off, order. Add or remove tiers.
 *     • Réglages  : weekly prize, reset day/hour, reward method, order statuses,
 *                   monthly reward cap per client, all on-page texts.
 *     • Journal   : who changed what, when, from which IP.
 *
 * SECURITY MODEL (unchanged where it matters)
 *   • The browser is never trusted. Progress, eligibility and claims are always
 *     recomputed on the server from real WooCommerce orders.
 *   • Every admin form: capability check + nonce + referer check + strict
 *     sanitising + whitelists + hard caps that the dashboard itself cannot raise.
 *   • Claims: MySQL lock, re-check inside the lock, counter reserved before the
 *     reward is issued and rolled back if issuing fails, per-user rate limit.
 *   • Tier keys are permanent: renaming a label never resets or duplicates the
 *     claims already recorded for the month.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
if ( ! defined( 'DELICAT_REWARDS_VERSION' ) ) define( 'DELICAT_REWARDS_VERSION', '3.4.0' ); // keep in step with the header

/** Capability required for every Delicat admin screen and action. */
require_once __DIR__ . '/reward-delivery.php';
require_once __DIR__ . '/rewards-view.php';
require_once __DIR__ . '/supplier-history.php';
require_once __DIR__ . '/rewards-transport.php';

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

add_action( 'admin_notices', function () {
	if ( ! class_exists( 'WooCommerce' ) && current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p><b>Delicat Rewards :</b> WooCommerce doit être actif.</p></div>';
	}
} );

register_activation_hook( __FILE__, function () {
	set_transient( 'delicat_rewards_setup_notice', 1, 10 * MINUTE_IN_SECONDS );
} );

add_action( 'admin_notices', function () {
	if ( ! current_user_can( delicat_cap() ) || ! get_transient( 'delicat_rewards_setup_notice' ) ) return;
	delete_transient( 'delicat_rewards_setup_notice' );
	$url = admin_url( 'admin.php?page=delicat-rewards&tab=installation' );
	echo '<div class="notice notice-success is-dismissible"><p><b>Delicat Rewards v' . esc_html( DELICAT_REWARDS_VERSION ) . ' prêt.</b> Avant les récompenses réelles, ouvrez le guide d’installation et vérifiez les PINs, les top-ups directs et les variations serveur. <a class="button button-small" style="margin-left:8px" href="' . esc_url( $url ) . '">Configurer maintenant</a></p></div>';
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
		'reward_method' => 'order',                    // v3: rewards are always delivered as FREE FIRE PIN orders
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

/** One-way v3 migration. Keeps custom mappings, but corrects old built-in journey defaults. */
function delicat_upgrade_300() {
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
	return 'delicat_s' . delicat_cfg_gen() . '_' . (int) $user_id . '_' . wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
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
	$empty = array( 'claims' => array(), 'diamonds' => 0 );
	if ( ! function_exists( 'wc_get_orders' ) || ! preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ) return $empty;
	$boundary = new DateTimeImmutable( $month . '-01 00:00:00', wp_timezone() );
	$start = $boundary->getTimestamp(); $end = $boundary->modify( '+1 month' )->getTimestamp() - 1;
	$args = array( 'customer_id' => (int) $user_id, 'date_created' => $start . '...' . $end, 'orderby' => 'date', 'order' => 'ASC' );
	$orders = delicat_orders_paged( $args, 100, 1000 ); $out = array(); $diamonds = 0; $tiers = array_merge( delicat_tier_history(), delicat_all_tiers() );
	foreach ( $orders as $o ) {
		if ( 'completed' !== $o->get_status() ) continue; // incomplete/failed reward orders are never treated as delivered
		$tag  = sanitize_key( (string) $o->get_meta( '_delicat_reward' ) );
		$kind = sanitize_key( (string) $o->get_meta( '_delicat_reward_kind' ) );
		if ( 'weekly' === $kind || 0 === strpos( $tag, 'weekly_' ) ) continue; // podium does not consume the monthly tier cap
		if ( '' === $tag ) continue;
		if ( 'tier' === $kind || isset( $tiers[ $tag ] ) ) {
			$out[ $tag ] = ( $out[ $tag ] ?? 0 ) + 1;
			$amt = (int) $o->get_meta( '_delicat_reward_amount' );
			if ( $amt < 1 && isset( $tiers[ $tag ] ) ) $amt = (int) ( $tiers[ $tag ]['reward'] ?? 0 );
			$diamonds += max( 0, $amt );
		}
	}
	return array( 'claims' => $out, 'diamonds' => $diamonds );
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
		return array( 'claims' => $claims, 'debt' => $debt, 'ok' => true, 'reward_total' => (int) $snapshot['diamonds'] );
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
	return array( 'claims' => $claims, 'debt' => $debt, 'ok' => true, 'reward_total' => (int) $snapshot['diamonds'] );
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
	if ( ! $order || ! is_a( $order, 'WC_Abstract_Order' ) ) return false;
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

/** Walks wc_get_orders() page by page, so a week of orders is never held in one array. */
function delicat_orders_each( array $args, callable $callback, $page_size = 200, $max = 5000 ) {
	if ( ! function_exists( 'wc_get_orders' ) ) return 0;
	$seen = 0; $page = 1; $page_size = max( 20, min( 500, (int) $page_size ) );
	while ( $seen < $max ) {
		$q = $args; $q['limit'] = $page_size; $q['page'] = $page;
		$batch = wc_get_orders( $q );
		if ( ! is_array( $batch ) || ! $batch ) break;
		$count = count( $batch );
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
		wp_mail( get_option( 'admin_email' ), 'Delicat Rewards — remboursement après un bonus', sprintf( "Commande #%d (client #%d, mois %s) remboursée ou annulée après un bonus réclamé.\nAchats déjà utilisés pour un PIN gratuit : %s.\nLe manque est reporté sur les prochains achats du client. Aucun PIN n'a été retiré : vérifiez la commande si nécessaire.", (int) $order->get_id(), $uid, $from, implode( ', ', $lines ) ) );
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
		if ( $ok && $t['max_claims'] > 0 && $claimed[ $key ] >= $t['max_claims'] ) { $ok = false; $blocked = 'max'; }
		if ( $ok && $cap > 0 && ( $reward_total + $t['reward'] ) > $cap )          { $ok = false; $blocked = 'cap'; }

		$state['tiers'][ $key ] = array(
			'progress'  => (int) $progress,
			'goal'      => (int) $t['goal'],
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
function delicat_issue_reward( $user_id, $tier_key, $request_hash = '' ) {
	$tiers = delicat_tiers();
	if ( ! isset( $tiers[ $tier_key ] ) ) return false;
	$t = $tiers[ $tier_key ];

	if ( $t['reward'] <= 0 || $t['reward'] > DELICAT_MAX_REWARD ) return false; // belt and braces
	if ( (int) delicat_cfg( 'dry_run' ) === 1 ) return false;                   // test mode issues nothing, ever

	$plan = delicat_reward_plan( $t['reward'], true );
	if ( ! $plan ) { delicat_log( 'warning', 'Reward stock unavailable.', array( 'user_id' => (int) $user_id, 'tier' => $tier_key, 'reward' => (int) $t['reward'] ) ); return false; }
	$note = sprintf( 'Récompense Delicat Store — bonus %s (%d diamants gratis).', $t['label'], $t['reward'] );
	$oid  = (int) delicat_create_reward_order( $user_id, $plan, $note, $tier_key, array(
		'delicat_reward_request' => $request_hash,
		'delicat_reward_kind'   => 'tier',
		'delicat_reward_amount' => (int) $t['reward'],
		'delicat_reward_tier'   => $tier_key,
		'delicat_reward_month'  => wp_date( 'Y-m', delicat_month_start(), wp_timezone() ),
	) );
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
 * Build weekly rows from the only trusted source: real WooCommerce orders.
 * Each qualifying order increments the frequency exactly once. Only eligible
 * line-item net spend is added, so unrelated products and refunded value do not
 * inflate the competition.
 */
function delicat_collect_leaderboard_rows( $start, $end = null, $include_excluded = false ) {
	$rows = array();
	if ( ! function_exists( 'wc_get_orders' ) ) return $rows;

	$args = array_merge( array( 'status' => delicat_counting_statuses(), 'orderby' => 'date', 'order' => 'ASC' ), delicat_order_window_query( (int) $start ) );
	delicat_orders_each( $args, function ( $order ) use ( &$rows, $start, $end, $include_excluded ) {
		if ( ! delicat_order_in_window( $order, $start, $end ) || ! delicat_order_ok( $order ) ) return;
		$uid = (int) $order->get_customer_id();
		if ( ! $uid || ( ! $include_excluded && delicat_is_excluded( $uid ) ) ) return;

		$units = delicat_order_units( $order, array() );
		/* A free/fully-refunded eligible line is not a money-spending order. */
		if ( $units['qty'] < 1 || $units['spend'] <= 0 ) return;
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

/** Any order change marks the board stale; it is rebuilt at most once a minute. */
function delicat_leaderboard_touch() {
	set_transient( 'delicat_leaderboard_dirty', time(), DAY_IN_SECONDS );
}

function delicat_leaderboard() {
	$start = delicat_competition_start();
	$fresh = function () use ( $start ) {
		$cached = get_transient( 'delicat_leaderboard' );
		if ( ! is_array( $cached ) || ! isset( $cached['rows'], $cached['built'] ) || (int) ( $cached['start'] ?? -1 ) !== $start ) return null;
		$dirty = (int) get_transient( 'delicat_leaderboard_dirty' );
		return ( $dirty < (int) $cached['built'] || time() - (int) $cached['built'] < MINUTE_IN_SECONDS ) ? $cached['rows'] : null;
	};
	$rows = $fresh();
	if ( null !== $rows ) return $rows;
	/* One rebuild at a time: meanwhile other visitors see the last board of the same period.
	   3.3.0: a request that waited for the lock reads the board the other one built, and none
	   rebuilds without the lock (up to 3.2.4 every waiting request rebuilt the whole board right
	   after the weekly reset). Only the page and the app read this; the weekly award and the
	   admin pages compute their own rows. */
	$locked = delicat_lock( 'delicat_leaderboard_build', 0 );
	if ( ! $locked ) {
		$stale = get_transient( 'delicat_leaderboard_stale' );
		if ( is_array( $stale ) && isset( $stale['rows'] ) && (int) ( $stale['start'] ?? -1 ) === $start ) return $stale['rows'];
		$locked = delicat_lock( 'delicat_leaderboard_build', 5 );
		if ( ! $locked ) return array(); // still being built: an empty board for this view, never a parallel build
	}
	try {
		$rows = $fresh();
		if ( null !== $rows ) return $rows;
		$entry = array( 'built' => time(), 'start' => $start, 'rows' => array() );
		$entry['rows'] = delicat_collect_leaderboard_rows( $start );
		set_transient( 'delicat_leaderboard', $entry, 5 * MINUTE_IN_SECONDS );
		set_transient( 'delicat_leaderboard_stale', $entry, DAY_IN_SECONDS );
		return $entry['rows'];
	} finally {
		if ( $locked ) delicat_unlock( 'delicat_leaderboard_build' );
	}
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
	foreach ( delicat_orders_paged( array( 'date_created' => $start . '...' . $end, 'orderby' => 'date', 'order' => 'DESC' ), 100, 1500 ) as $o ) {
		if ( 'completed' !== $o->get_status() ) continue;
		$stored = (string) $o->get_meta( '_delicat_week_boundary' );
		if ( '' === $stored || ! is_numeric( $stored ) || abs( (int) $stored - $boundary ) > $slack ) continue;
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
	$rows = delicat_collect_leaderboard_rows( $start, $end - 1 );

	$all = delicat_weekly_state_get(); $key = (string) $boundary;
	$week = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array( 'placements' => array(), 'complete' => 0, 'updated' => current_time( 'mysql' ) );
	if ( ! $rows && empty( $week['ranking'] ) ) { $week['complete'] = 1; $week['reason'] = 'no_participants'; $week['updated'] = current_time( 'mysql' ); $all[ $key ] = $week; delicat_weekly_state_save( $all ); return true; }

	$ranked = array(); $rank = 0; foreach ( $rows as $uid => $r ) { if ( ++$rank > 3 ) break; $ranked[ $rank ] = (int) $uid; }
	if ( empty( $week['ranking'] ) || ! is_array( $week['ranking'] ) ) $week['ranking'] = $ranked;
	else $ranked = array_map( 'intval', $week['ranking'] ); // freeze winners for safe retries
	$prizes = delicat_meilleur_prizes(); $results = array(); $complete = true;
	foreach ( array( 1, 2, 3 ) as $rank ) {
		$prev = $week['placements'][ $rank ] ?? array();
		if ( in_array( $prev['status'] ?? '', array( 'manual', 'delivered' ), true ) ) continue;
		$prize = isset( $prev['prize'] ) ? (int) $prev['prize'] : ( isset( $prizes[ $rank ] ) ? (int) $prizes[ $rank ] : 0 );
		if ( $prize < 1 ) { $week['placements'][ $rank ] = array( 'status' => 'disabled', 'prize' => 0 ); continue; }
		if ( empty( $ranked[ $rank ] ) ) { $week['placements'][ $rank ] = array( 'status' => 'no_participant', 'prize' => $prize ); continue; }
		$uid = $ranked[ $rank ]; $prev = $week['placements'][ $rank ] ?? array();
		if ( ( $prev['status'] ?? '' ) === 'manual' ) continue;
		if ( ( $prev['status'] ?? '' ) === 'delivered' && ! empty( $prev['oid'] ) ) { $results[ $rank ] = array( 'uid' => $uid, 'prize' => $prize, 'oid' => (int) $prev['oid'] ); continue; }
		$existing = delicat_existing_weekly_order( $boundary, $rank );
		if ( $existing ) {
			$eo = wc_get_order( $existing ); $euid = $eo ? (int) $eo->get_customer_id() : $uid;
			$week['placements'][ $rank ] = array( 'status' => 'delivered', 'uid' => $euid ?: $uid, 'prize' => $prize, 'oid' => $existing );
			$results[ $rank ] = array( 'uid' => $euid ?: $uid, 'prize' => $prize, 'oid' => $existing ); continue;
		}

		$plan = delicat_reward_plan( $prize, true ); $oid = 0;
		if ( $plan ) {
			$medal = array( 1 => '🏆', 2 => '🥈', 3 => '🥉' );
			$oid = delicat_create_reward_order( $uid, $plan, $medal[ $rank ] . ' Meilleur Client — ' . ( 1 === $rank ? '1ère' : $rank . 'e' ) . ' place de la semaine — ' . $prize . ' diamants gratis.', 1 === $rank ? 'weekly_winner' : 'weekly_podium_' . $rank, array(
				'delicat_week_boundary' => $boundary,
				'delicat_week_rank'     => $rank,
				'delicat_reward_kind'   => 'weekly',
				'delicat_reward_amount' => $prize,
			) );
		}
		if ( $oid ) {
			$week['placements'][ $rank ] = array( 'status' => 'delivered', 'uid' => $uid, 'prize' => $prize, 'oid' => $oid );
			delicat_weekly_prize_delivered( $uid, $oid, $rank, $prize );
		} else {
			$week['placements'][ $rank ] = array( 'status' => 'failed_retryable', 'uid' => $uid, 'prize' => $prize, 'oid' => 0, 'last_try' => current_time( 'mysql' ) ); $complete = false;
			delicat_log( 'warning', 'Weekly prize delivery failed and will retry.', array( 'boundary' => $boundary, 'rank' => $rank, 'user_id' => $uid, 'prize' => $prize ) );
		}
		$results[ $rank ] = array( 'uid' => $uid, 'prize' => $prize, 'oid' => $oid );
	}
	$week['complete'] = $complete ? 1 : 0; $week['updated'] = current_time( 'mysql' );
	/* Notify admin only when the meaningful delivery state changes (e.g. first
	 * failure, then later success), not on every hourly retry. */
	$fpdata = array(); foreach ( array( 1, 2, 3 ) as $r ) { $p = $week['placements'][ $r ] ?? array(); $fpdata[ $r ] = array( $p['status'] ?? '', (int) ( $p['uid'] ?? 0 ), (int) ( $p['prize'] ?? 0 ), (int) ( $p['oid'] ?? 0 ) ); }
	$fingerprint = md5( wp_json_encode( $fpdata ) ); $send_alert = $results && $fingerprint !== ( $week['alert_fingerprint'] ?? '' );
	$week['alert_fingerprint'] = $fingerprint; $all[ $key ] = $week; delicat_weekly_state_save( $all );
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
			. ( $r['oid'] ? 'commande récompense #' . $r['oid']
				: 'ÉCHEC livraison (prix non composable avec vos PINs, vérifiez WooCommerce ▸ Delicat Rewards).' );
	}
	wp_mail( get_option( 'admin_email' ), 'Delicat — Meilleur Client (podium)', implode( "\n", $lines ) );
}

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

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
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
	$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'delicat\\_cs\\_%' LIMIT 500" );
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
			return new WP_Error( 'busy', 'Réclamation déjà en cours. Vérifiez « Mes PINs récompenses ».', array( 'status' => 409 ) );
		}

		/* Reserve the claim (signed) BEFORE issuing anything. */
		$claims           = $ledger['claims'];
		$before           = isset( $claims[ $tier ] ) ? (int) $claims[ $tier ] : 0;
		$claims[ $tier ]  = $before + 1;
		delicat_ledger_save( $user_id, $month, $claims, $ledger['debt'] ?? null );

		$order_id = 0;
		try {
			$order_id = delicat_issue_reward( $user_id, $tier, $request_hash );
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
			$r     = rest_ensure_response( $state );
			$r->header( 'Cache-Control', 'no-store, private' ); // never cached by a proxy or a CDN
			return $r;
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
			return delicat_private_response( array( 'top' => $top, 'you' => $board['you'] ) );
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
	foreach ( $ranked as $r ) { $lead = max( $lead, (float) $r['score'] ); break; } // first = leader
	foreach ( $ranked as $uid => $r ) {
		if ( $r['rank'] > delicat_board_size() ) break;
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
   ADMIN — SHARED HELPERS (guards, notices, audit log, sanitising)
   ========================================================================= */

/** Every write goes through this: capability + nonce + referer, or die. */
function delicat_guard( $action ) {
	if ( ! current_user_can( delicat_cap() ) ) {
		wp_die( 'Accès refusé.', 'Delicat Rewards', array( 'response' => 403 ) );
	}
	check_admin_referer( $action ); // nonce + referer; dies on failure
}

/** PRG: redirect back to a tab after a write (no double-submit on refresh). */
function delicat_back( $tab, $errors = array(), $notes = array() ) {
	delicat_notice_set( $errors, $notes );
	wp_safe_redirect( add_query_arg(
		array( 'page' => 'delicat-rewards', 'tab' => sanitize_key( $tab ) ),
		admin_url( 'admin.php' )
	) );
	exit;
}

function delicat_notice_set( $errors = array(), $notes = array() ) {
	$errors = array_values( array_filter( (array) $errors ) );
	$notes  = array_values( array_filter( (array) $notes ) );
	if ( ! $errors && ! $notes ) return;
	set_transient( 'delicat_notice_' . get_current_user_id(), array( 'e' => $errors, 'n' => $notes ), 120 );
}

function delicat_notice_show() {
	$k = 'delicat_notice_' . get_current_user_id();
	$n = get_transient( $k );
	if ( ! $n || ! is_array( $n ) ) return;
	delete_transient( $k );
	if ( ! empty( $n['n'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . implode( '<br>', array_map( 'esc_html', $n['n'] ) ) . '</p></div>';
	}
	if ( ! empty( $n['e'] ) ) {
		echo '<div class="notice notice-warning is-dismissible"><p><b>À vérifier :</b><br>' . implode( '<br>', array_map( 'esc_html', $n['e'] ) ) . '</p></div>';
	}
}

/** Who changed what, when, from where. Last 40 entries. */
function delicat_audit_add( $what ) {
	$log = get_option( 'delicat_audit', array() );
	if ( ! is_array( $log ) ) $log = array();
	$u  = wp_get_current_user();
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
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

/** "197, 289" → array( valid ids ), array( rejected ids ) */
function delicat_parse_ids( $raw, $historical = false ) {
	$ids = array(); $bad = array();
	foreach ( preg_split( '/[^0-9]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $chunk ) {
		$id = (int) $chunk;
		if ( $id <= 0 ) continue;
		if ( $historical || delicat_valid_pid( $id ) ) $ids[ $id ] = true;
		else                            $bad[ $id ] = true;
	}
	return array( array_keys( $ids ), array_keys( $bad ) );
}

/** "✅ Nom du produit (#289)" or a loud warning. Handles variations. */
function delicat_pid_hint( $id ) {
	$id = (int) $id;
	if ( ! $id ) return '<span style="color:#777">— (résolu par slug)</span>';
	if ( ! delicat_valid_pid( $id ) ) {
		return '<b>#' . $id . ' — ID historique / produit indisponible</b>'
			. '<div class="hint">Les commandes conservant cet ID peuvent compter. Vérifiez le pack dans vos anciennes commandes.</div>';
	}

	$name = '';
	if ( function_exists( 'wc_get_product' ) ) {
		$p = wc_get_product( $id );
		if ( $p ) $name = $p->get_name(); // for a variation: "PIN Free Fire - 110 Diamants"
	}
	if ( '' === $name ) $name = get_the_title( $id );

	$parent = delicat_parent_pid( $id );
	$edit   = admin_url( 'post.php?post=' . $parent . '&action=edit' );
	$tag    = '';
	if ( delicat_is_variation( $id ) ) {
		$tag = ' <span class="hint">· variation</span>';
	} elseif ( function_exists( 'wc_get_product' ) ) {
		$p = wc_get_product( $id );
		if ( $p && $p->is_type( 'variable' ) ) {
			$tag = ' <span class="hint">· produit variable (' . count( $p->get_children() ) . ' variations, toutes comptées)</span>';
		}
	}
	return '✅ <a href="' . esc_url( $edit ) . '" target="_blank">' . esc_html( $name ) . '</a> <code>#' . $id . '</code>' . $tag;
}

/** Datalist of products AND their variations, so the admin picks instead of typing. */
function delicat_products_datalist() {
	static $done = false;
	if ( $done || ! function_exists( 'wc_get_products' ) ) return;
	$done = true;

	$ids  = wc_get_products( array( 'limit' => 200, 'status' => 'publish', 'orderby' => 'title', 'order' => 'ASC', 'return' => 'ids' ) );
	$rows = array();
	foreach ( (array) $ids as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) continue;
		$rows[ (int) $id ] = $p->get_name() . ' (#' . (int) $id . ')';

		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v ) continue;
				$rows[ (int) $vid ] = '↳ ' . $v->get_name() . ' (#' . (int) $vid . ')';
				if ( count( $rows ) > 500 ) break 2;
			}
		}
	}

	echo '<datalist id="dlc-products">';
	foreach ( $rows as $id => $label ) {
		echo '<option value="' . (int) $id . '">' . esc_attr( $label ) . '</option>';
	}
	echo '</datalist>';
}

/** Everything currently broken in the configuration (used by notices + diagnostics). */
function delicat_config_problems() {
	$out = array();
	foreach ( (array) delicat_cfg( 'pins' ) as $denom => $pid ) {
		if ( ! delicat_pid_by_diamonds( $denom ) ) {
			$out[] = sprintf( 'Aucun produit pour %d 💎 — renseignez son ID dans l\'onglet Produits.', (int) $denom );
		}
	}
	$owners = array(); $mapped = array();
	foreach ( delicat_tiers() as $tk => $tt ) foreach ( $tt['product_ids'] as $pid ) { $pid = (int) $pid; $owners[ $pid ][] = $tk; $mapped[] = array( 'tier' => $tk, 'pid' => $pid ); }
	foreach ( $owners as $pid => $ks ) if ( count( array_unique( $ks ) ) > 1 ) $out[] = sprintf( 'Produit #%d affecté à plusieurs tiers (%s) — un même achat peut progresser dans plusieurs récompenses.', $pid, implode( ', ', array_unique( $ks ) ) );
	/* Also catch parent-vs-variation overlap: runtime order matching contains both
	 * IDs, so parent #100 in one tier + variation #123 (child of #100) in another
	 * would double-count even though the numeric IDs are different. */
	$semantic_seen = array();
	for ( $i = 0, $n = count( $mapped ); $i < $n; $i++ ) for ( $j = $i + 1; $j < $n; $j++ ) {
		$a = $mapped[ $i ]; $b = $mapped[ $j ]; if ( $a['tier'] === $b['tier'] || $a['pid'] === $b['pid'] ) continue;
		$overlap = ( ! delicat_is_variation( $a['pid'] ) && delicat_parent_pid( $b['pid'] ) === $a['pid'] ) || ( ! delicat_is_variation( $b['pid'] ) && delicat_parent_pid( $a['pid'] ) === $b['pid'] );
		if ( $overlap ) { $sk = min( $a['pid'], $b['pid'] ) . ':' . max( $a['pid'], $b['pid'] ); if ( ! isset( $semantic_seen[ $sk ] ) ) { $semantic_seen[ $sk ] = 1; $out[] = sprintf( 'Chevauchement parent/variation #%d ↔ #%d entre tiers %s / %s — mappez les variations exactes pour éviter un double comptage.', $a['pid'], $b['pid'], $a['tier'], $b['tier'] ); } }
	}
	foreach ( delicat_tiers() as $key => $t ) {
		if ( ! $t['product_ids'] ) {
			$out[] = sprintf( 'Tier « %s » : aucun produit acheté valide — la progression restera à 0.', $t['label'] );
		}
		if ( $t['reward'] > 0 && ! delicat_reward_plan( $t['reward'] ) ) {
			$out[] = sprintf( 'Tier « %s » : la récompense de %d 💎 ne peut pas être composée avec vos PINs.', $t['label'], $t['reward'] );
		}
	}
	if ( 'order' === delicat_cfg( 'reward_method' ) ) {
		foreach ( delicat_meilleur_prizes() as $rk => $pv ) {
			if ( $pv > 0 && ! delicat_reward_plan( $pv ) ) {
				$out[] = sprintf( 'Prix Meilleur Client (place %d) : %d 💎 ne peut pas être composé avec vos PINs.', $rk, $pv );
			}
		}
	}
	return $out;
}

add_action( 'admin_notices', function () {
	if ( ! current_user_can( delicat_cap() ) || ! class_exists( 'WooCommerce' ) ) return;

	$tamper = get_option( 'delicat_tamper', array() );
	if ( is_array( $tamper ) && $tamper ) {
		echo '<div class="notice notice-error"><p><b>Delicat Rewards — alerte d\'intégrité :</b> '
			. (int) count( $tamper ) . ' compteur(s) de bonus modifié(s) hors du plugin. Les réclamations concernées sont gelées. '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=delicat-rewards&tab=diagnostics' ) ) . '">Voir</a></p></div>';
	}

	if ( (int) delicat_cfg( 'dry_run' ) === 1 ) {
		echo '<div class="notice notice-warning"><p><b>Delicat Rewards — 🧪 MODE TEST ACTIF :</b> aucune récompense n\'est livrée aux clients. '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=delicat-rewards&tab=reglages' ) ) . '">Désactiver</a></p></div>';
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && false !== strpos( (string) $screen->id, 'delicat-rewards' ) ) return; // the page shows them itself
	$p = delicat_config_problems();
	if ( ! $p ) return;
	$url = admin_url( 'admin.php?page=delicat-rewards&tab=diagnostics' );
	echo '<div class="notice notice-warning"><p><b>Delicat Rewards :</b> ' . esc_html( $p[0] )
		. ( count( $p ) > 1 ? ' (+' . ( count( $p ) - 1 ) . ' autre' . ( count( $p ) > 2 ? 's' : '' ) . ')' : '' )
		. ' <a href="' . esc_url( $url ) . '">Ouvrir les diagnostics</a></p></div>';
} );


/* ---- Branding: editable store name and Media Library logo ---- */
add_action( 'admin_post_delicat_save_branding', function () {
	delicat_guard( 'delicat_save_branding' );
	$cfg = delicat_cfg();
	$name = delicat_txt( wp_unslash( $_POST['brand_name'] ?? '' ) );
	$logo_id = absint( $_POST['brand_logo_id'] ?? 0 );
	if ( $logo_id && 'attachment' !== get_post_type( $logo_id ) ) $logo_id = 0;
	$cfg['brand_name'] = $name ?: get_bloginfo( 'name' );
	$cfg['brand_logo_id'] = $logo_id;
	delicat_cfg_save( $cfg );
	delicat_audit_add( sprintf( 'Identité visuelle mise à jour : %s, logo #%d.', $cfg['brand_name'], $logo_id ) );
	delicat_back( 'branding', array(), array( 'Nom et logo enregistrés.' ) );
} );

/* =========================================================================
   ADMIN — MENU
   ========================================================================= */
add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Delicat Rewards', 'Delicat Rewards', delicat_cap(), 'delicat-rewards', 'delicat_admin_page' );
	add_submenu_page( 'woocommerce', 'Delicat — Meilleur client', 'Delicat Meilleur client', delicat_cap(), 'delicat-meilleur', function () { $_GET['tab'] = 'meilleur'; delicat_admin_page(); } );
	add_submenu_page( 'woocommerce', 'Delicat — Participants', 'Delicat Participants', delicat_cap(), 'delicat-participants', 'delicat_participants_page' );
} );

/* =========================================================================
   ADMIN — WRITE HANDLERS (admin-post.php: capability + nonce + validation)
   ========================================================================= */

/* ---- Tiers: goals, rewards, product IDs, limits, order, add/remove ---- */
add_action( 'admin_post_delicat_save_tiers', function () {
	delicat_guard( 'delicat_save_tiers' );

	$cfg  = delicat_cfg();
	$old  = (array) $cfg['tiers'];
	$in   = ( isset( $_POST['tiers'] ) && is_array( $_POST['tiers'] ) ) ? wp_unslash( $_POST['tiers'] ) : array();
	$errs = array();
	$notes= array();
	$new  = array();
	$diff = array();

	foreach ( $in as $rawkey => $row ) {
		$key = sanitize_key( $rawkey );
		if ( '' === $key || ! isset( $old[ $key ] ) || ! is_array( $row ) ) continue; // only keys we already know

		if ( ! empty( $row['delete'] ) ) {
			$notes[] = sprintf( 'Tier « %s » supprimé.', $old[ $key ]['label'] ?? $key );
			$diff[]  = 'suppression:' . $key;
			continue;
		}

		$label   = delicat_txt( $row['label'] ?? '' ) ?: ( $old[ $key ]['label'] ?? $key );
		$goal    = max( 1, min( DELICAT_MAX_GOAL, (int) ( $row['goal'] ?? 1 ) ) );
		$reward  = max( 0, min( DELICAT_MAX_REWARD, (int) ( $row['reward'] ?? 0 ) ) );
		$maxc    = max( 0, min( 999, (int) ( $row['max_claims'] ?? 0 ) ) );
		$pos     = max( 0, min( 99, (int) ( $row['order'] ?? 0 ) ) );
		$pin     = max( 0, min( 1000000, (int) ( $row['pin'] ?? 0 ) ) );
		$enabled = empty( $row['enabled'] ) ? 0 : 1;

		list( $ids, $bad ) = delicat_parse_ids( $row['ids'] ?? '', true );
		foreach ( $bad as $b ) {
			$errs[] = sprintf( 'Tier « %s » : #%d n\'est ni un produit ni une variation publiée — ignoré.', $label, $b );
		}

		if ( ! $ids && ! delicat_pid_by_diamonds( $pin ) && $enabled ) {
			$enabled = 0;
			$errs[]  = sprintf( 'Tier « %s » désactivé : aucun produit valide à compter.', $label );
		}
		if ( $enabled && $reward > 0 && 'order' === $cfg['reward_method'] && ! delicat_reward_plan( $reward ) ) {
			$enabled = 0;
			$errs[]  = sprintf( 'Tier « %s » désactivé : %d 💎 ne peut pas être composé avec vos PINs (%s).',
				$label, $reward, implode( ', ', delicat_ff_denoms() ) );
		}

		$o = $old[ $key ];
		if ( (int) ( $o['goal'] ?? 0 ) !== $goal )       $diff[] = sprintf( '%s objectif %d→%d', $key, (int) ( $o['goal'] ?? 0 ), $goal );
		if ( (int) ( $o['reward'] ?? 0 ) !== $reward )   $diff[] = sprintf( '%s récompense %d→%d', $key, (int) ( $o['reward'] ?? 0 ), $reward );
		$oldids = implode( ',', array_map( 'intval', (array) ( $o['ids'] ?? array() ) ) );
		if ( $oldids !== implode( ',', $ids ) )          $diff[] = sprintf( '%s produits [%s]→[%s]', $key, $oldids, implode( ',', $ids ) );
		if ( (int) ( $o['enabled'] ?? 0 ) !== $enabled ) $diff[] = sprintf( '%s %s', $key, $enabled ? 'activé' : 'désactivé' );

		if ( $goal < (int) ( $o['goal'] ?? 0 ) ) {
			$errs[] = sprintf( 'Tier « %s » : objectif abaissé (%d→%d). Les clients qui ont déjà assez d\'achats ce mois-ci peuvent réclamer immédiatement.',
				$label, (int) $o['goal'], $goal );
		}

		$new[ $key ] = array(
			'label'      => $label,
			'title'      => delicat_txt( $row['title'] ?? '' ),
			'sub'        => delicat_txt( $row['sub'] ?? '' ),
			'ids'        => $ids,
			'pin'        => $pin,
			'goal'       => $goal,
			'reward'     => $reward,
			'max_claims' => $maxc,
			'enabled'    => $enabled,
			'order'      => $pos,
		);
	}

	/* ---- new tier ---- */
	$nt = ( isset( $_POST['newtier'] ) && is_array( $_POST['newtier'] ) ) ? wp_unslash( $_POST['newtier'] ) : array();
	$nlabel = delicat_txt( $nt['label'] ?? '' );
	if ( '' !== $nlabel ) {
		if ( count( $new ) >= DELICAT_MAX_TIERS ) {
			$errs[] = sprintf( 'Maximum %d tiers — nouveau tier non ajouté.', DELICAT_MAX_TIERS );
		} else {
			$key = sanitize_key( sanitize_title( $nlabel ) );
			if ( '' === $key ) $key = 'tier';
			$base = $key; $i = 2;
			while ( isset( $new[ $key ] ) || isset( $old[ $key ] ) ) { $key = $base . '-' . $i; $i++; }

			list( $ids, $bad ) = delicat_parse_ids( $nt['ids'] ?? '', true );
			foreach ( $bad as $b ) $errs[] = sprintf( 'Nouveau tier : produit #%d inexistant — ignoré.', $b );

			$reward  = max( 0, min( DELICAT_MAX_REWARD, (int) ( $nt['reward'] ?? 0 ) ) );
			$pin     = max( 0, min( 1000000, (int) ( $nt['pin'] ?? 0 ) ) );
			$enabled = ( $ids || delicat_pid_by_diamonds( $pin ) ) ? 1 : 0;
			if ( $enabled && $reward > 0 && 'order' === $cfg['reward_method'] && ! delicat_reward_plan( $reward ) ) {
				$enabled = 0;
				$errs[]  = sprintf( 'Nouveau tier créé mais désactivé : %d 💎 non composable.', $reward );
			}
			if ( ! $enabled && ! $ids ) $errs[] = 'Nouveau tier créé mais désactivé : aucun produit valide.';

			$new[ $key ] = array(
				'label'      => $nlabel,
				'title'      => delicat_txt( $nt['title'] ?? 'Free Fire' ),
				'sub'        => delicat_txt( $nt['sub'] ?? '' ),
				'ids'        => $ids,
				'pin'        => $pin,
				'goal'       => max( 1, min( DELICAT_MAX_GOAL, (int) ( $nt['goal'] ?? 10 ) ) ),
				'reward'     => $reward,
				'max_claims' => max( 0, min( 999, (int) ( $nt['max_claims'] ?? 0 ) ) ),
				'enabled'    => $enabled,
				'order'      => count( $new ) + 1,
			);
			$notes[] = sprintf( 'Tier « %s » créé (clé permanente : %s).', $nlabel, $key );
			$diff[]  = 'création:' . $key;
		}
	}

	if ( ! $new ) {
		delicat_back( 'tiers', array( 'Refusé : la configuration doit garder au moins un tier.' ) );
	}

	$cfg['tiers'] = $new;
	delicat_cfg_save( $cfg );
	if ( $diff ) delicat_audit_add( 'Tiers : ' . implode( ' · ', $diff ) );
	$notes[] = 'Tiers enregistrés.';
	delicat_back( 'tiers', $errs, $notes );
} );

/* ---- Products: diamond amount → WooCommerce product ID ---- */
add_action( 'admin_post_delicat_save_pins', function () {
	delicat_guard( 'delicat_save_pins' );

	$cfg   = delicat_cfg();
	$old   = (array) $cfg['pins'];
	$rows  = ( isset( $_POST['pins'] ) && is_array( $_POST['pins'] ) ) ? wp_unslash( $_POST['pins'] ) : array();
	$errs  = array();
	$notes = array();
	$pins  = array();
	$diff  = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
			if ( ! empty( $row['delete'] ) && ! empty( $row['denom'] ) ) $diff[] = 'suppression PIN ' . (int) $row['denom'];
			continue;
		}
		$denom = (int) ( $row['denom'] ?? 0 );
		if ( $denom < 1 || $denom > 1000000 ) continue;
		if ( isset( $pins[ $denom ] ) ) {
			$errs[] = sprintf( 'Dénomination %d en double — la première a été gardée.', $denom );
			continue;
		}
		$raw = trim( (string) ( $row['pid'] ?? '' ) );
		$pid = (int) preg_replace( '/[^0-9]/', '', $raw );
		if ( $pid && ! delicat_valid_pid( $pid ) ) {
			$errs[] = sprintf( '%d 💎 : #%d n\'est ni un produit ni une variation publiée — champ laissé vide (résolution par slug).', $denom, $pid );
			$pid    = 0;
		}
		$pins[ $denom ] = $pid;
		if ( (int) ( $old[ $denom ] ?? 0 ) !== $pid ) $diff[] = sprintf( '%d💎 : #%d→#%d', $denom, (int) ( $old[ $denom ] ?? 0 ), $pid );
	}

	/* new row */
	$np = ( isset( $_POST['newpin'] ) && is_array( $_POST['newpin'] ) ) ? wp_unslash( $_POST['newpin'] ) : array();
	$nd = (int) ( $np['denom'] ?? 0 );
	if ( $nd > 0 && $nd <= 1000000 ) {
		if ( isset( $pins[ $nd ] ) ) {
			$errs[] = sprintf( 'Dénomination %d déjà présente.', $nd );
		} else {
			$pid = (int) preg_replace( '/[^0-9]/', '', (string) ( $np['pid'] ?? '' ) );
			if ( $pid && ! delicat_valid_pid( $pid ) ) {
				$errs[] = sprintf( 'Nouveau PIN %d 💎 : produit #%d introuvable — ajouté sans ID.', $nd, $pid );
				$pid = 0;
			}
			$pins[ $nd ] = $pid;
			$notes[]     = sprintf( 'PIN %d 💎 ajouté.', $nd );
			$diff[]      = sprintf( 'ajout PIN %d💎 #%d', $nd, $pid );
		}
	}

	if ( ! $pins ) {
		delicat_back( 'produits', array( 'Refusé : gardez au moins une dénomination PIN.' ) );
	}
	ksort( $pins );

	/* leaderboard extras + slug matching */
	list( $extra, $badextra ) = delicat_parse_ids( isset( $_POST['extra_pins'] ) ? wp_unslash( $_POST['extra_pins'] ) : '' );
	foreach ( $badextra as $b ) $errs[] = sprintf( 'Produit supplémentaire #%d inexistant — ignoré.', $b );

	$pattern = strtolower( sanitize_text_field( wp_unslash( $_POST['slug_pattern'] ?? '' ) ) );
	$pattern = preg_replace( '/[^a-z0-9\-%]/', '', $pattern );
	if ( false === strpos( $pattern, '%d' ) ) {
		if ( '' !== $pattern ) $errs[] = 'Le motif de slug doit contenir %d — ancienne valeur conservée.';
		$pattern = (string) $cfg['slug_pattern'];
	}

	$cfg['pins']         = $pins;
	$cfg['extra_pins']   = $extra;
	$cfg['match_slug']   = empty( $_POST['match_slug'] ) ? 0 : 1;
	$cfg['slug_pattern'] = $pattern;

	delicat_cfg_save( $cfg );
	if ( $diff ) delicat_audit_add( 'Produits : ' . implode( ' · ', $diff ) );
	$notes[] = 'Produits enregistrés.';
	delicat_back( 'produits', $errs, $notes );
} );

/* ---- Settings: rules, prize, statuses, texts ---- */
add_action( 'admin_post_delicat_save_general', function () {
	delicat_guard( 'delicat_save_general' );

	$cfg   = delicat_cfg();
	$in    = wp_unslash( $_POST );
	$scope = ( $in['settings_scope'] ?? '' ) === 'board' ? 'board' : 'rewards';
	$preserve = 'board' === $scope ? array( 'monthly_cap' ) : array( 'prize', 'prize2', 'prize3', 'reset_day', 'reset_hour', 'board_size', 'mask_names' );
	foreach ( $preserve as $field ) $in[ $field ] = $cfg[ $field ];
	$errs  = array();
	$diff  = array();

	$method = 'order'; // v3: reward fulfilment is intentionally PIN-only
	if ( $method !== $cfg['reward_method'] ) $diff[] = 'méthode:' . $method;
	$cfg['reward_method'] = $method;

	/* v3 program contract: eligible purchases must be completed. */
	if ( (array) ( $cfg['statuses'] ?? array() ) !== array( 'completed' ) ) $diff[] = 'statuts:completed';
	$cfg['statuses'] = array( 'completed' );
	$cfg['strict_completed'] = 1;

	/* Security switches */
	$paid  = 1; // approved payment is mandatory
	$refnd = 1; // refund protection is mandatory
	$deliv = empty( $in['require_deliv'] ) ? 0 : 1;
	$pub   = empty( $in['board_public'] )  ? 0 : 1;
	if ( $pub )     $errs[] = '⚠️ Le classement est désormais visible sans connexion.';
	foreach ( array( 'require_paid' => $paid, 'ignore_refund' => $refnd, 'require_deliv' => $deliv, 'board_public' => $pub ) as $k => $v ) {
		if ( (int) ( $cfg[ $k ] ?? 0 ) !== $v ) $diff[] = $k . ':' . ( $v ? 'oui' : 'non' );
		$cfg[ $k ] = $v;
	}

	/* Test mode + client notifications */
	$dry = empty( $in['dry_run'] ) ? 0 : 1;
	if ( (int) ( $cfg['dry_run'] ?? 0 ) !== $dry ) $diff[] = 'mode test:' . ( $dry ? 'ON' : 'OFF' );
	if ( $dry ) $errs[] = '🧪 MODE TEST ACTIF : les clients voient leur progression mais aucune récompense n\'est livrée.';
	$cfg['dry_run'] = $dry;

	$notify = empty( $in['notify_client'] ) ? 0 : 1;
	if ( (int) ( $cfg['notify_client'] ?? 0 ) !== $notify ) $diff[] = 'email client:' . ( $notify ? 'oui' : 'non' );
	$cfg['notify_client'] = $notify;

	foreach ( array( 'notify_body', 'notify_winner' ) as $f ) {
		if ( isset( $in[ $f ] ) ) {
			$v = mb_substr( sanitize_textarea_field( (string) $in[ $f ] ), 0, DELICAT_MSG_LEN );
			if ( '' !== $v ) $cfg[ $f ] = $v;
		}
	}

	$prize = max( 1, min( DELICAT_MAX_REWARD, (int) ( $in['prize'] ?? 572 ) ) );
	if ( 'order' === $method && ! delicat_reward_plan( $prize ) ) {
		$errs[] = sprintf( 'Prix %d 💎 non composable avec vos PINs (%s) — la livraison auto échouera.',
			$prize, implode( ', ', delicat_ff_denoms() ) );
	}
	if ( $prize !== (int) $cfg['prize'] ) $diff[] = sprintf( 'prix %d→%d', (int) $cfg['prize'], $prize );
	$cfg['prize'] = $prize;
	update_option( 'delicat_meilleur_prize', $prize ); // keep the legacy option in sync

	foreach ( array( 2 => 'prize2', 3 => 'prize3' ) as $rk => $pk ) {
		$pv = max( 0, min( DELICAT_MAX_REWARD, (int) ( $in[ $pk ] ?? 0 ) ) );
		if ( $pv > 0 && 'order' === $method && ! delicat_reward_plan( $pv ) ) {
			$errs[] = sprintf( 'Prix %de place %d 💎 non composable avec vos PINs (%s) — la livraison auto échouera.',
				$rk, $pv, implode( ', ', delicat_ff_denoms() ) );
		}
		if ( $pv !== (int) $cfg[ $pk ] ) $diff[] = sprintf( 'prix #%d %d→%d', $rk, (int) $cfg[ $pk ], $pv );
		$cfg[ $pk ] = $pv;
	}

	$cap = max( 0, min( DELICAT_MAX_MONTH_CAP, (int) ( $in['monthly_cap'] ?? 0 ) ) );
	if ( $cap !== (int) $cfg['monthly_cap'] ) $diff[] = sprintf( 'plafond mensuel %d→%d', (int) $cfg['monthly_cap'], $cap );
	$cfg['monthly_cap'] = $cap;

	$cfg['reset_day']  = max( 0, min( 6,  (int) ( $in['reset_day'] ?? 0 ) ) );
	$cfg['reset_hour'] = max( 0, min( 23, (int) ( $in['reset_hour'] ?? 20 ) ) );
	$cfg['board_size'] = max( 1, min( 50, (int) ( $in['board_size'] ?? 10 ) ) );
	$cfg['mask_names'] = $pub ? 1 : ( empty( $in['mask_names'] ) ? 0 : 1 );

	$wa = esc_url_raw( trim( (string) ( $in['wa'] ?? '' ) ), array( 'http', 'https' ) );
	if ( '' === $wa && '' !== trim( (string) ( $in['wa'] ?? '' ) ) ) {
		$errs[] = 'Lien WhatsApp invalide (http/https requis) — ancienne valeur conservée.';
	} else {
		$cfg['wa'] = $wa;
	}

	foreach ( array( 'page_title', 'program_name', 't_spend', 't_reward', 't_progress', 'note', 'unit', 'currency', 'badge_suffix', 'btn_prefix', 'btn_suffix', 'btn_locked', 'btn_dry', 'notify_subject', 'board_kicker', 'board_title', 'board_games', 'board_reset_label', 'board_reward_label', 'board_prize_suffix', 'board_reward_sub', 'board_you_label', 'board_login_text', 'board_heading', 'board_hidden_text', 'board_empty_text', 'board_first_text', 'board_rank_text', 'board_gap_text', 'board_start_text', 'board_footer_1', 'board_footer_2' ) as $f ) {
		if ( isset( $in[ $f ] ) ) {
			$v = delicat_txt( $in[ $f ], 'note' === $f ? 160 : DELICAT_TEXT_LEN );
			if ( '' !== $v ) $cfg[ $f ] = $v;
		}
	}

	if ( isset( $in['board_footer_url'] ) ) {
		$board_url = esc_url_raw( trim( (string) $in['board_footer_url'] ), array( 'http', 'https' ) );
		if ( $board_url ) $cfg['board_footer_url'] = $board_url;
	}

	delicat_cfg_save( $cfg );
	if ( $diff ) delicat_audit_add( 'Réglages : ' . implode( ' · ', $diff ) );
	delicat_back( 'board' === $scope ? 'meilleur' : 'reglages', $errs, array( 'Réglages enregistrés.' ) );
} );

/* ---- Program on/off switches ---- */
add_action( 'admin_post_delicat_toggle', function () {
	delicat_guard( 'delicat_toggle' );
	$which = sanitize_key( wp_unslash( $_POST['which'] ?? '' ) );
	$map   = array(
		'rewards'   => array( 'delicat_rewards_enabled',  'delicat_rewards_on',  'Rewards Program' ),
		'meilleur'  => array( 'delicat_meilleur_enabled', 'delicat_meilleur_on', 'Meilleur Client' ),
		'autoaward' => array( 'delicat_auto_award',       'delicat_auto_award_on','Livraison auto du prix' ),
	);
	if ( isset( $map[ $which ] ) ) {
		list( $opt, $fn, $label ) = $map[ $which ];
		$now = call_user_func( $fn ) ? '0' : '1';
		update_option( $opt, $now );
		delete_transient( 'delicat_leaderboard' );
		delicat_audit_add( $label . ' : ' . ( '1' === $now ? 'ACTIVÉ' : 'EN PAUSE' ) );
	}
	delicat_back( 'rewards' === $which ? 'reglages' : 'meilleur' );
} );

/* ---- Clear the integrity alerts (after the admin has checked the accounts) ---- */
add_action( 'admin_post_delicat_clear_tamper', function () {
	delicat_guard( 'delicat_clear_tamper' );
	delete_option( 'delicat_tamper' );
	delicat_audit_add( 'Alertes d\'intégrité effacées après vérification.' );
	delicat_back( 'diagnostics', array(), array( 'Alertes effacées.' ) );
} );

/** Tier claims per reward order of $month, in any status except cancelled or failed. */
function delicat_reward_order_claims_any( $user_id, $month ) {
	if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) ) return array();
	list( $start, $end ) = delicat_month_bounds( $month );
	$statuses = array_values( array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-failed', 'wc-checkout-draft' ) ) );
	$args  = array( 'customer_id' => (int) $user_id, 'status' => $statuses, 'date_created' => ( $start - DAY_IN_SECONDS ) . '...' . ( $end + DAY_IN_SECONDS ), 'orderby' => 'date', 'order' => 'ASC' );
	$tiers = array_merge( delicat_tier_history(), delicat_all_tiers() ); $out = array();
	delicat_orders_each( $args, function ( $o ) use ( &$out, $month, $tiers ) {
		$tag  = sanitize_key( (string) $o->get_meta( '_delicat_reward' ) );
		$kind = sanitize_key( (string) $o->get_meta( '_delicat_reward_kind' ) );
		if ( '' === $tag || 'weekly' === $kind || 0 === strpos( $tag, 'weekly_' ) ) return; // the podium does not use the tier ledger
		if ( 'tier' !== $kind && ! isset( $tiers[ $tag ] ) ) return;
		$m = (string) $o->get_meta( '_delicat_reward_month' );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $m ) ) { $d = $o->get_date_created(); $m = $d ? delicat_month_key( $d->getTimestamp() ) : ''; }
		if ( $m === $month ) $out[ $tag ] = ( $out[ $tag ] ?? 0 ) + 1;
	}, 100, 2000 );
	return $out;
}

/**
 * Rebuilds and re-signs the ledgers of $month that no longer verify (salts rotated, host
 * move). Claims are never lowered: each tier keeps the highest of the stored value, the
 * legacy _delicat_claimed_ counters and the month's reward orders in any status except
 * cancelled or failed (a reward order stuck in processing still used its claim). Carried
 * debt is never lowered either. Stops after $budget seconds; run it again for the rest.
 */
function delicat_reseal_month( $month, $budget = 20 ) {
	$started = time(); $fixed = 0; $left = 0; $ids = array();
	foreach ( array( delicat_ledger_key( $month ), delicat_ledger_migration_key( $month ) ) as $meta_key ) {
		foreach ( get_users( array( 'meta_key' => $meta_key, 'fields' => 'ID', 'number' => -1 ) ) as $id ) $ids[ (int) $id ] = true;
	}
	$current   = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	$tier_keys = array_keys( array_merge( delicat_tier_history(), delicat_all_tiers() ) );
	foreach ( array_keys( $ids ) as $uid ) {
		$peek = delicat_ledger_peek( $uid, $month );
		if ( $peek['exists'] ? $peek['ok'] : ! get_user_meta( $uid, delicat_ledger_migration_key( $month ), true ) ) continue;
		if ( time() - $started >= $budget ) { $left++; continue; }
		$lock = 'delicat_claim_' . $uid . '_' . $current;
		if ( ! delicat_lock( $lock, 2 ) ) { $left++; continue; }
		try {
			$claims = $peek['claims'];
			foreach ( array_unique( array_merge( $tier_keys, array_keys( $claims ) ) ) as $k ) {
				$n = (int) get_user_meta( $uid, "_delicat_claimed_{$month}_{$k}", true );
				if ( $n > ( $claims[ $k ] ?? 0 ) ) $claims[ $k ] = $n;
			}
			foreach ( delicat_reward_order_claims_any( $uid, $month ) as $k => $n ) if ( $n > ( $claims[ $k ] ?? 0 ) ) $claims[ $k ] = $n;
			$debt = $peek['debt'];
			foreach ( delicat_debt_carry( $uid, $month ) as $k => $n ) if ( $n > ( $debt[ $k ] ?? 0 ) ) $debt[ $k ] = $n;
			delicat_ledger_save( $uid, $month, $claims, $debt );
			delete_user_meta( $uid, '_delicat_integrity_alert_' . sanitize_key( $month ) );
			if ( $month < $current ) delicat_debt_forward( $uid, $month, $current );
			delicat_flush_user( $uid );
			$fixed++;
		} catch ( \Throwable $e ) {
			$left++;
			delicat_log( 'error', 'Ledger re-seal failed for one customer.', array( 'user_id' => (int) $uid, 'month' => $month ) );
		} finally { delicat_unlock( $lock ); }
	}
	if ( ! $left ) update_option( 'delicat_hmac_fingerprint', delicat_key_fingerprint( delicat_secret() ), false );
	return array( 'fixed' => $fixed, 'left' => $left );
}

add_action( 'admin_post_delicat_reseal', function () {
	delicat_guard( 'delicat_reseal' );
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( 'POST requis.' );
	$current = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	$month   = sanitize_text_field( wp_unslash( $_POST['month'] ?? '' ) );
	if ( ! in_array( $month, array( $current, delicat_month_shift( $current, -1 ) ), true ) ) $month = $current;
	$r = delicat_reseal_month( $month );
	delicat_audit_add( sprintf( 'Registres %s re-scellés : %d compte(s) réparé(s), %d restant(s).', $month, $r['fixed'], $r['left'] ) );
	$errors = $r['left'] ? array( sprintf( '%d compte(s) restant(s) : cliquez de nouveau sur « Re-sceller les registres du mois ».', $r['left'] ) ) : array();
	delicat_back( 'diagnostics', $errors, array( sprintf( '%s : %d registre(s) re-scellé(s). Aucune réclamation n’a été baissée.', $month, $r['fixed'] ) ) );
} );

/* ---- Safe deployment preset: preserves all IDs/tier rules, only hardens runtime and enables dry-run. ---- */
add_action( 'admin_post_delicat_safe_setup', function () {
	delicat_guard( 'delicat_safe_setup' );
	$cfg = delicat_cfg();
	$cfg['reward_method']    = 'order';
	$cfg['statuses']         = array( 'completed' );
	$cfg['strict_completed'] = 1;
	$cfg['require_paid']     = 1;
	$cfg['ignore_refund']    = 1;
	$cfg['dry_run']          = 1;
	$cfg['board_public']     = 0;
	delicat_cfg_save( $cfg );
	delicat_audit_add( 'Assistant installation : mode test sécurisé activé (completed + paid + refunds + PIN-only).' );
	delicat_back( 'installation', array(), array( 'Mode test sécurisé activé. Aucun PIN ni prix hebdomadaire ne sera livré tant que le mode test reste actif.' ) );
} );

/* ---- Restore defaults (config only — no order, claim or code is touched) ---- */
add_action( 'admin_post_delicat_reset', function () {
	delicat_guard( 'delicat_reset' );
	delicat_cfg_save( delicat_defaults() );
	delicat_audit_add( 'Configuration réinitialisée aux valeurs par défaut.' );
	delicat_back( 'reglages', array(), array( 'Configuration réinitialisée. Vérifiez les IDs produits dans l\'onglet Produits.' ) );
} );

/* =========================================================================
   ADMIN — PAGE
   ========================================================================= */
function delicat_admin_page() {
	if ( ! current_user_can( delicat_cap() ) ) {
		wp_die( 'Accès refusé.', 'Delicat Rewards', array( 'response' => 403 ) );
	}
	$tabs = array(
		'installation'=> 'Installation',
		'tiers'       => 'Rewards Program — Tiers',
		'meilleur' => 'Meilleur client',
		'parrainage'  => 'Parrainage',
		'produits'    => 'Produits',
		'reglages'    => 'Rewards — Réglages',
		'branding'     => 'Nom & Logo',
		'rapports'    => 'Rapports',
		'diagnostics' => 'Diagnostics',
		'journal'     => 'Journal',
	);
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'installation';
	if ( ! isset( $tabs[ $tab ] ) ) $tab = 'installation';

	echo '<div class="wrap"><h1>Delicat Rewards</h1>';
	delicat_notice_show();

	echo '<h2 class="nav-tab-wrapper" style="margin-bottom:18px">';
	foreach ( $tabs as $k => $label ) {
		$url = add_query_arg( array( 'page' => 'delicat-rewards', 'tab' => $k ), admin_url( 'admin.php' ) );
		echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</h2>';

	echo '<style>
	.dlcadm table.form{border-collapse:collapse;width:100%;max-width:1100px;background:#fff;border:1px solid #dcdcde;border-radius:6px;overflow:hidden}
	.dlcadm table.form th{background:#f6f7f7;text-align:left;padding:10px 12px;font-size:12px;letter-spacing:.4px;text-transform:uppercase;color:#50575e;border-bottom:1px solid #dcdcde}
	.dlcadm table.form td{padding:12px;border-bottom:1px solid #f0f0f1;vertical-align:top}
	.dlcadm input[type=number]{width:88px}
	.dlcadm input.ids{width:150px}
	.dlcadm input.txt{width:190px}
	.dlcadm .hint{color:#646970;font-size:12px;line-height:1.5}
	.dlcadm .off{opacity:.55}
	.dlcadm .box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 18px;max-width:1100px;margin:0 0 18px}
	.dlcadm .box h3{margin:0 0 6px}
	.dlcadm .danger{border-color:#f3c2c2;background:#fff6f6}
	.dlcadm .addrow{background:#f6fbf7}
	</style><div class="dlcadm">';

	if ( 'installation' === $tab )     delicat_tab_setup();
	elseif ( 'meilleur' === $tab ) { delicat_tab_competition(); delicat_tab_settings( true ); }
	elseif ( 'tiers' === $tab )        delicat_tab_tiers();
	elseif ( 'parrainage' === $tab )   delicat_tab_referral();
	elseif ( 'produits' === $tab )     delicat_tab_products();
	elseif ( 'reglages' === $tab )     delicat_tab_settings();
	elseif ( 'branding' === $tab )      delicat_tab_branding();
	elseif ( 'rapports' === $tab )     delicat_tab_reports();
	elseif ( 'diagnostics' === $tab )  delicat_tab_diagnostics();
	elseif ( 'journal' === $tab )      delicat_tab_audit();

	echo '</div></div>';
}


/* ---- TAB: INSTALLATION / READINESS ---- */
function delicat_tab_setup() {
	$cfg      = delicat_cfg();
	$problems = delicat_config_problems();
	$tiers    = delicat_tiers();
	$pins     = (array) ( $cfg['pins'] ?? array() );
	$valid_pins = 0;
	foreach ( $pins as $denom => $pid ) if ( delicat_pid_by_diamonds( (int) $denom ) ) $valid_pins++;
	$mapped_tiers = 0;
	foreach ( $tiers as $t ) if ( ! empty( $t['product_ids'] ) ) $mapped_tiers++;
	$dry = ! empty( $cfg['dry_run'] );
	$program = delicat_rewards_on();

	$u = function ( $tab ) { return admin_url( 'admin.php?page=delicat-rewards&tab=' . $tab ); };
	$ok = '<span style="color:#137333;font-weight:700">✅ OK</span>';
	$no = '<span style="color:#b32d2e;font-weight:700">⛔ À corriger</span>';
	$warn = '<span style="color:#996800;font-weight:700">⚠️ Vérifier</span>';

	echo '<div class="box"><h2 style="margin-top:0">Assistant d’installation — Omni Free Fire Rewards</h2>';
	echo '<p>Objectif : <b>tout achat Free Fire éligible</b> (PIN ou top-up direct, peu importe le serveur) progresse après <b>paiement approuvé + commande WooCommerce terminée</b>. La récompense gratuite est <b>toujours livrée en PIN Free Fire</b>.</p>';
	echo '<p class="hint">Cet assistant ne remplace pas WooCommerce et ne modifie jamais votre API direct-top-up. WooCommerce reste la source de vérité.</p></div>';

	echo '<div class="box"><h3>État actuel</h3><table class="widefat striped"><tbody>';
	echo '<tr><td style="width:330px"><b>WooCommerce</b></td><td>' . ( class_exists( 'WooCommerce' ) ? $ok : $no ) . '</td></tr>';
	echo '<tr><td><b>PINs de récompense résolus</b></td><td>' . ( $valid_pins === count( $pins ) && $valid_pins > 0 ? $ok : $warn ) . ' &nbsp; ' . (int) $valid_pins . '/' . (int) count( $pins ) . '</td></tr>';
	echo '<tr><td><b>Tiers actifs avec produits éligibles</b></td><td>' . ( $mapped_tiers === count( $tiers ) && $mapped_tiers > 0 ? $ok : $warn ) . ' &nbsp; ' . (int) $mapped_tiers . '/' . (int) count( $tiers ) . '</td></tr>';
	echo '<tr><td><b>Diagnostics</b></td><td>' . ( ! $problems ? $ok : $no . ' &nbsp; ' . count( $problems ) . ' problème(s)' ) . '</td></tr>';
	echo '<tr><td><b>Mode test</b></td><td>' . ( $dry ? '<span style="color:#996800;font-weight:700">🧪 ACTIF — aucune récompense livrée</span>' : '<span style="color:#137333;font-weight:700">RÉEL — récompenses autorisées</span>' ) . '</td></tr>';
	echo '<tr><td><b>Rewards Program</b></td><td>' . ( $program ? '<span style="color:#137333;font-weight:700">ACTIF</span>' : '<span style="color:#646970;font-weight:700">EN PAUSE</span>' ) . '</td></tr>';
	echo '</tbody></table></div>';

	echo '<div class="box"><h3>1. Préparer un test sécurisé</h3><p>Ce bouton <b>ne change aucun ID produit, aucun objectif et aucune progression client</b>. Il force seulement : Woo <code>completed</code>, paiement approuvé, remboursements déduits, récompense PIN-only, classement privé et <b>Mode test</b>.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_safe_setup' );
	echo '<input type="hidden" name="action" value="delicat_safe_setup"><button class="button button-primary button-large">🧪 Activer la configuration de test sécurisée</button></form></div>';

	echo '<div class="box"><h3>2. Mapper les PINs qui seront offerts</h3><p>Ouvrez <a href="' . esc_url( $u( 'produits' ) ) . '"><b>Produits</b></a>. Pour chaque dénomination (110, 341, 572, 1160, 2180…), indiquez le <b>produit ou la variation PIN exacte</b> qui contient les codes à offrir.</p>';
	echo '<p class="hint">Exemple : 110 💎 → variation WooCommerce #1234 correspondant au PIN 110. Un top-up direct ne doit pas être utilisé comme produit de livraison de la récompense.</p></div>';

	echo '<div class="box"><h3>3. Mapper tous les achats Free Fire qui gagnent de la progression</h3><p>Ouvrez <a href="' . esc_url( $u( 'tiers' ) ) . '"><b>Tiers</b></a>. Pour chaque montant, ajoutez dans <b>Produits éligibles</b> tous les IDs du même pack : PIN, direct top-up, LATAM, Europe, Brésil, USA, ancien produit, etc.</p>';
	echo '<p><b>Important pour les variations :</b> si le produit parent contient plusieurs montants de diamants, mappez les <b>IDs des variations exactes</b>. Utilisez le parent seulement si toutes ses variations représentent le même montant.</p>';
	echo '<table class="widefat striped"><thead><tr><th>Pack</th><th>Objectif conseillé</th><th>Récompense</th></tr></thead><tbody>';
	$journey = array( 110 => 25, 341 => 20, 572 => 15, 1160 => 12, 2180 => 10 );
	foreach ( $journey as $d => $g ) echo '<tr><td>' . (int) $d . ' 💎</td><td>' . (int) $g . ' achats terminés</td><td>' . (int) $d . ' 💎 gratuit en PIN</td></tr>';
	echo '</tbody></table></div>';

	echo '<div class="box"><h3>4. Installer les pages clients</h3><p>Ajoutez ces shortcodes dans vos pages WordPress :</p>';
	echo '<p><code>[delicat_rewards]</code> — progression mensuelle + réclamation sécurisée.</p>';
	echo '<p><code>[delicat_meilleur_client]</code> — classement hebdomadaire / podium.</p>';
	echo '<p class="hint">Vous pouvez utiliser Gutenberg, Elementor ou un bloc Shortcode. Le client doit être connecté pour voir ses données privées.</p></div>';

	echo '<div class="box"><h3>5. Tester avant la mise en production</h3><ol style="line-height:1.8">';
	echo '<li>Gardez <b>Mode test actif</b>.</li><li>Passez une vraie commande test avec un compte client.</li><li>Validez le paiement et laissez votre système de livraison/direct top-up terminer la commande WooCommerce.</li><li>Vérifiez que la progression augmente uniquement quand l’ordre devient <code>completed</code>.</li><li>Testez PIN et direct top-up sur chaque serveur/variation mappé.</li><li>Ouvrez <a href="' . esc_url( $u( 'diagnostics' ) ) . '"><b>Diagnostics</b></a> et corrigez toutes les alertes rouges.</li></ol></div>';

	echo '<div class="box"><h3>6. Passer en réel</h3><p>Quand les diagnostics sont propres et les tests corrects : allez dans <a href="' . esc_url( $u( 'reglages' ) ) . '"><b>Réglages</b></a>, désactivez <b>Mode test</b>, activez <b>Rewards Program</b>, puis activez éventuellement <b>Meilleur Client</b> et sa livraison automatique après avoir testé le podium.</p>';
	if ( $problems ) echo '<p style="color:#b32d2e"><b>Ne passez pas en réel maintenant :</b> ' . (int) count( $problems ) . ' problème(s) de configuration restent détectés.</p>';
	else echo '<p style="color:#137333"><b>Diagnostics : aucune erreur de configuration détectée.</b></p>';
	echo '</div>';
}

/* ---- TAB: NOM & LOGO ---- */
function delicat_tab_branding() {
	$cfg = delicat_cfg();
	$logo_id = absint( $cfg['brand_logo_id'] ?? 0 );
	$logo = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
	wp_enqueue_media();
	echo '<div class="box"><h3>Identité visuelle du programme</h3><p class="hint">Le nom et le logo apparaissent en haut de la carte Rewards. Le fichier est choisi dans la Médiathèque WordPress et son ID est validé côté serveur.</p></div>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_save_branding' );
	echo '<input type="hidden" name="action" value="delicat_save_branding">';
	echo '<div class="box"><table class="widefat" style="border:0"><tbody>';
	echo '<tr><td style="width:260px"><b>Nom de la boutique</b></td><td><input type="text" class="regular-text" maxlength="' . DELICAT_TEXT_LEN . '" name="brand_name" value="' . esc_attr( (string) ( $cfg['brand_name'] ?? '' ) ) . '"></td></tr>';
	echo '<tr><td><b>Logo</b></td><td><input type="hidden" id="delicat_brand_logo_id" name="brand_logo_id" value="' . $logo_id . '">';
	echo '<div id="delicat_brand_preview" style="width:220px;min-height:90px;border:1px dashed #b8b8b8;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#fafafa;margin-bottom:10px;padding:10px">';
	if ( $logo ) echo '<img src="' . esc_url( $logo ) . '" alt="" style="max-width:200px;max-height:110px;object-fit:contain">';
	else echo '<span class="hint">Aucun logo sélectionné</span>';
	echo '</div><button type="button" class="button" id="delicat_choose_logo">Choisir / remplacer</button> <button type="button" class="button" id="delicat_remove_logo">Retirer</button></td></tr>';
	echo '</tbody></table></div><p><button class="button button-primary button-large">Enregistrer le nom et le logo</button></p></form>';
	echo <<<'JS'
<script>
(function($){
 var frame;
 $('#delicat_choose_logo').on('click',function(e){e.preventDefault(); if(frame){frame.open();return;} frame=wp.media({title:'Choisir le logo Rewards',button:{text:'Utiliser ce logo'},multiple:false,library:{type:'image'}}); frame.on('select',function(){var a=frame.state().get('selection').first().toJSON(); $('#delicat_brand_logo_id').val(a.id); $('#delicat_brand_preview').html('<img src="'+(a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url)+'" alt="" style="max-width:200px;max-height:110px;object-fit:contain">');}); frame.open();});
 $('#delicat_remove_logo').on('click',function(){ $('#delicat_brand_logo_id').val('0'); $('#delicat_brand_preview').html('<span class="hint">Aucun logo sélectionné</span>'); });
})(jQuery);
</script>
JS;
}

/* ---- TAB: TIERS ---- */
function delicat_tab_tiers() {
	delicat_products_datalist();
	$tiers = delicat_all_tiers();

	echo '<div class="box"><h3>Free Fire — achats éligibles et récompenses PIN</h3>'
		. '<p class="hint"><b>Pack 💎</b> : le montant Free Fire suivi par ce palier (110, 341, 572…). Le produit PIN correspondant dans l\'onglet Produits est <b>toujours compté automatiquement</b>.<br>'
		. '<b>Produits éligibles</b> : ajoutez tous les autres IDs WooCommerce représentant le même pack : top-up direct, autres serveurs/régions, anciens produits ou variations. Séparez-les par des virgules.<br>'
		. '<b>Produit variable / plusieurs serveurs :</b> si un parent contient plusieurs montants de diamants, mettez les IDs des <b>variations exactes</b>. N\'utilisez le parent que si toutes ses variations représentent le même pack.<br>'
		. '<b>Règle v3 :</b> progression uniquement après paiement confirmé + commande WooCommerce <code>completed</code>. La récompense est toujours livrée depuis le stock PIN, jamais par le fournisseur direct-top-up.<br>'
		. '<b>Limite/mois</b> : 0 = illimité. La clé du tier est permanente : renommer le badge ne remet jamais les réclamations à zéro.</p></div>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_save_tiers' );
	echo '<input type="hidden" name="action" value="delicat_save_tiers">';

	echo '<p class="hint">Les IDs des fournisseurs précédents sont conservés pour le même pack et la même clé de tier, même si leurs produits sont supprimés. Gardez la clé du tier lors du changement de fournisseur. Pour récupérer un ancien pack sans nom explicite (Free Fire + nombre de diamants), ajoutez son ID historique.</p>';
	echo '<table class="form"><thead><tr>'
		. '<th style="width:52px">Actif</th><th>Badge</th><th>Titre affiché</th><th>Pack 💎</th><th>Produits éligibles (direct/PIN/serveurs)</th>'
		. '<th>Objectif</th><th>Récompense PIN</th><th>Limite/mois</th><th style="width:60px">Ordre</th><th style="width:70px">Suppr.</th>'
		. '</tr></thead><tbody>';

	foreach ( $tiers as $key => $t ) {
		$ids   = implode( ', ', array_map( 'intval', (array) ( $t['ids'] ?? array() ) ) );
		$plan  = delicat_reward_plan( (int) ( $t['reward'] ?? 0 ) );
		$compo = $plan ? implode( ' + ', array_map( function ( $l ) { return $l['qty'] . '× PIN ' . $l['denom']; }, $plan ) ) : '⛔ non composable';
		$pinpid = delicat_pid_by_diamonds( (int) ( $t['pin'] ?? 0 ) );

		echo '<tr' . ( empty( $t['enabled'] ) ? ' class="off"' : '' ) . '>';
		echo '<td><input type="checkbox" name="tiers[' . esc_attr( $key ) . '][enabled]" value="1"' . checked( ! empty( $t['enabled'] ), true, false ) . '></td>';
		echo '<td><input class="txt" type="text" name="tiers[' . esc_attr( $key ) . '][label]" value="' . esc_attr( $t['label'] ?? '' ) . '" maxlength="' . DELICAT_TEXT_LEN . '"><div class="hint">clé : <code>' . esc_html( $key ) . '</code></div></td>';
		echo '<td><input class="txt" type="text" name="tiers[' . esc_attr( $key ) . '][title]" value="' . esc_attr( $t['title'] ?? '' ) . '" placeholder="Free Fire"><br>'
			. '<input class="txt" type="text" name="tiers[' . esc_attr( $key ) . '][sub]" value="' . esc_attr( $t['sub'] ?? '' ) . '" placeholder="110 Diamants" style="margin-top:6px"></td>';
		echo '<td><input type="number" min="1" max="1000000" name="tiers[' . esc_attr( $key ) . '][pin]" value="' . (int) ( $t['pin'] ?? 0 ) . '"> 💎'
			. '<div class="hint">PIN auto : ' . ( $pinpid ? delicat_pid_hint( $pinpid ) : '<b style="color:#b32d2e">⛔ non mappé</b>' ) . '</div></td>';
		echo '<td><input class="ids" type="text" name="tiers[' . esc_attr( $key ) . '][ids]" value="' . esc_attr( $ids ) . '" list="dlc-products" placeholder="ex. 197, 289, 845">'
			. '<div class="hint">' . ( '' !== $ids ? implode( '<br>', array_map( 'delicat_pid_hint', array_map( 'intval', (array) $t['ids'] ) ) ) : 'Aucun ID supplémentaire — les anciens fournisseurs restent comptabilisés.' ) . '</div></td>';
		echo '<td><input type="number" min="1" max="' . DELICAT_MAX_GOAL . '" name="tiers[' . esc_attr( $key ) . '][goal]" value="' . (int) ( $t['goal'] ?? 1 ) . '"><div class="hint">fois</div></td>';
		echo '<td><input type="number" min="0" max="' . DELICAT_MAX_REWARD . '" name="tiers[' . esc_attr( $key ) . '][reward]" value="' . (int) ( $t['reward'] ?? 0 ) . '"><div class="hint">' . esc_html( $compo ) . '</div></td>';
		echo '<td><input type="number" min="0" max="999" name="tiers[' . esc_attr( $key ) . '][max_claims]" value="' . (int) ( $t['max_claims'] ?? 0 ) . '"><div class="hint">0 = illimité</div></td>';
		echo '<td><input type="number" min="0" max="99" name="tiers[' . esc_attr( $key ) . '][order]" value="' . (int) ( $t['order'] ?? 0 ) . '"></td>';
		echo '<td><label class="hint"><input type="checkbox" name="tiers[' . esc_attr( $key ) . '][delete]" value="1"> retirer</label></td>';
		echo '</tr>';
	}

	echo '<tr class="addrow"><td>＋</td>'
		. '<td><input class="txt" type="text" name="newtier[label]" placeholder="Diamond" maxlength="' . DELICAT_TEXT_LEN . '"></td>'
		. '<td><input class="txt" type="text" name="newtier[title]" placeholder="Free Fire"><br><input class="txt" type="text" name="newtier[sub]" placeholder="4360 Diamants" style="margin-top:6px"></td>'
		. '<td><input type="number" min="1" max="1000000" name="newtier[pin]" placeholder="4360"></td>'
		. '<td><input class="ids" type="text" name="newtier[ids]" list="dlc-products" placeholder="direct/PIN/serveurs"></td>'
		. '<td><input type="number" min="1" max="' . DELICAT_MAX_GOAL . '" name="newtier[goal]" value="10"></td>'
		. '<td><input type="number" min="0" max="' . DELICAT_MAX_REWARD . '" name="newtier[reward]" value="0"></td>'
		. '<td><input type="number" min="0" max="999" name="newtier[max_claims]" value="0"></td>'
		. '<td colspan="2" class="hint">Ajoutez un nouveau pack/récompense Free Fire.</td></tr>';

	echo '</tbody></table><p><button class="button button-primary button-large">Enregistrer les tiers</button></p></form>';
}

/* ---- TAB: PRODUITS ---- */
function delicat_tab_products() {
	delicat_products_datalist();
	$cfg  = delicat_cfg();
	$pins = (array) $cfg['pins'];

	echo '<div class="box"><h3>PINs Free Fire — stock de livraison des récompenses</h3>'
		. '<p class="hint">Chaque dénomination pointe vers le produit/variation PIN utilisé pour <b>livrer les récompenses gratuites</b>. Les top-ups directs et autres serveurs se configurent dans <b>Tiers ▸ Produits éligibles</b>.<br>'
		. 'Laissez l\'ID vide pour laisser le plugin retrouver le produit par son slug (<code>' . esc_html( $cfg['slug_pattern'] ) . '</code>).<br>'
		. '<b>Produits variables :</b> pour la <u>livraison</u> des récompenses, indiquez de préférence l\'ID de la <b>variation</b> exacte à offrir. '
		. 'Si vous mettez l\'ID du produit parent, le plugin choisit automatiquement la première variation disponible en stock.<br>'
		. 'Ces dénominations servent uniquement à composer/livrer les récompenses PIN (ex. une récompense peut combiner plusieurs PINs si nécessaire).</p></div>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_save_pins' );
	echo '<input type="hidden" name="action" value="delicat_save_pins">';

	echo '<table class="form"><thead><tr><th style="width:130px">Diamants</th><th style="width:140px">ID produit</th><th>Produit résolu</th><th style="width:120px">Stock codes</th><th style="width:80px">Suppr.</th></tr></thead><tbody>';
	$i = 0;
	foreach ( $pins as $denom => $pid ) {
		$resolved = delicat_pid_by_diamonds( $denom );
		echo '<tr>';
		echo '<td><input type="number" min="1" max="1000000" name="pins[' . $i . '][denom]" value="' . (int) $denom . '"> 💎</td>';
		echo '<td><input class="ids" type="text" name="pins[' . $i . '][pid]" value="' . ( (int) $pid ?: '' ) . '" list="dlc-products" placeholder="auto"></td>';
		echo '<td>' . delicat_pid_hint( $resolved ) . ( ( ! $pid && $resolved ) ? ' <span class="hint">(trouvé par slug)</span>' : '' ) . '</td>';
		echo '<td>' . delicat_stock_cell( $resolved ) . '</td>';
		echo '<td><label class="hint"><input type="checkbox" name="pins[' . $i . '][delete]" value="1"> retirer</label></td>';
		echo '</tr>';
		$i++;
	}
	echo '<tr class="addrow"><td><input type="number" min="1" max="1000000" name="newpin[denom]" placeholder="4360"> 💎</td>'
		. '<td><input class="ids" type="text" name="newpin[pid]" list="dlc-products" placeholder="ID"></td>'
		. '<td colspan="3" class="hint">Ajouter une dénomination.</td></tr>';
	echo '</tbody></table>';

	$extra = implode( ', ', array_map( 'intval', (array) $cfg['extra_pins'] ) );
	echo '<div class="box" style="margin-top:18px"><h3>Free Fire — autres produits comptés seulement au classement</h3>';
	echo '<p><input type="text" class="regular-text" name="extra_pins" value="' . esc_attr( $extra ) . '" list="dlc-products" placeholder="ex. 412, 588"></p>';
	echo '<p class="hint">IDs de produits Free Fire qui comptent pour le <b>classement Meilleur Client</b> sans être un tier (autres recharges, packs…).</p>';
	echo '<p><label><input type="checkbox" name="match_slug" value="1"' . checked( ! empty( $cfg['match_slug'] ), true, false ) . '> '
		. 'Compter aussi automatiquement tout produit dont le slug suit le motif</label> '
		. '<input type="text" name="slug_pattern" value="' . esc_attr( $cfg['slug_pattern'] ) . '" style="width:220px"> <span class="hint">(<code>%d</code> = le nombre de diamants)</span></p></div>';

	echo '<p><button class="button button-primary button-large">Enregistrer les produits</button></p></form>';
}

/* ---- TAB: RÉGLAGES ---- */
function delicat_tab_settings( $board = false ) {
	$cfg = delicat_cfg();

	if ( ! empty( $cfg['dry_run'] ) ) {
		echo '<div class="notice notice-warning" style="margin:0 0 16px"><p><b>🧪 MODE TEST ACTIF.</b> Les clients voient leur progression, les boutons sont grisés, '
			. 'et <b>aucune récompense n\'est livrée</b> (ni bonus, ni prix hebdo). Idéal quand vous changez les objectifs en cours de mois.</p></div>';
	}

	/* switches */
	echo '<div class="box"><h3>Activation</h3>';
	if ( ! $board ) echo delicat_toggle_row( 'rewards',   'Rewards Program (bonus Free Fire)', delicat_rewards_on() );
	if ( $board ) echo delicat_toggle_row( 'meilleur',  'Meilleur Client (classement)',      delicat_meilleur_on() );
	if ( $board ) echo delicat_toggle_row( 'autoaward', 'Livraison automatique du prix hebdo au gagnant', delicat_auto_award_on() );
	echo '</div>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_save_general' );
	echo '<input type="hidden" name="action" value="delicat_save_general">';

	echo '<input type="hidden" name="settings_scope" value="' . ( $board ? 'board' : 'rewards' ) . '">';
	/* rules */
	echo '<div class="box"><h3>Règles communes aux deux programmes</h3><table class="widefat" style="border:0"><tbody>';

	echo '<tr><td style="width:280px"><b>Livraison des récompenses</b></td><td>';
	echo '<b>PIN Free Fire uniquement</b> — commande WooCommerce à 0, codes livrés automatiquement. <span class="hint">Les achats peuvent être PIN ou top-up direct, mais la récompense est toujours un PIN.</span></td></tr>';

	echo '<tr><td><b>Commandes comptées</b></td><td><b>Terminée (completed) uniquement ✅</b><div class="hint">Le paiement doit aussi être confirmé. Pour un top-up direct, le statut completed est la preuve que la recharge a été reçue. Remboursements/annulations retirent la progression.</div></td></tr>';

	echo '<tr><td><b>Sécurité des achats</b></td><td>';
	echo '<div style="margin-bottom:6px"><b>✅ Paiement approuvé obligatoire</b> <span class="hint">— date_paid + WooCommerce paid.</span></div>';
	echo '<div style="margin-bottom:6px"><b>✅ Remboursements déduits automatiquement</b> <span class="hint">— une quantité remboursée ne garde pas sa progression; après une récompense, les achats futurs compensent d\'abord le manque.</span></div>';
	echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="require_deliv" value="1"' . checked( ! empty( $cfg['require_deliv'] ), true, false ) . '> <b>Pour les achats PIN, exiger que le code ait été livré</b> <span class="hint">— les top-ups directs utilisent paid + completed comme preuve de réception.</span></label>';
	echo '<label style="display:block"><input type="checkbox" name="board_public" value="1"' . checked( ! empty( $cfg['board_public'] ), true, false ) . '> Classement visible sans être connecté <span class="hint">(les noms seront automatiquement masqués).</span></label>';
	echo '</td></tr>';

	echo '<tr><td><b>Mode test (simulation)</b></td><td>';
	echo '<label><input type="checkbox" name="dry_run" value="1"' . checked( ! empty( $cfg['dry_run'] ), true, false ) . '> '
		. '<b>🧪 Activer le mode test</b> — la progression s\'affiche normalement, mais <b>rien n\'est livré</b> : boutons grisés, aucune commande récompense, aucun prix hebdo.</label>';
	echo '<div class="hint">À utiliser quand vous changez les objectifs en cours de mois, ou pour vérifier une nouvelle configuration avant de l\'ouvrir aux clients.</div></td></tr>';

	if ( $board ) {
	echo '<tr><td><b>Calcul Meilleur Client</b></td><td><b>70 % montant net dépensé + 30 % nombre de commandes distinctes</b>'
		. '<div class="hint">Une commande payée et terminée compte une seule fois, même si elle contient plusieurs produits. Les montants remboursés sont retirés. En cas d’égalité : score, montant, commandes, puis résultat atteint le plus tôt.</div></td></tr>';

	echo '<tr><td><b>Prix Meilleur Client</b></td><td><input type="number" min="1" max="' . DELICAT_MAX_REWARD . '" name="prize" value="' . (int) $cfg['prize'] . '"> 💎';
	$plan = delicat_reward_plan( (int) $cfg['prize'] );
	echo ' <span class="hint">' . ( $plan ? esc_html( implode( ' + ', array_map( function ( $l ) { return $l['qty'] . '× PIN ' . $l['denom']; }, $plan ) ) ) : '<b style="color:#b32d2e">⛔ non composable</b>' ) . '</span></td></tr>';

	foreach ( array( 2 => 'prize2', 3 => 'prize3' ) as $rk => $pk ) {
		$pv = (int) $cfg[ $pk ];
		echo '<tr><td><b>Prix ' . $rk . 'e place</b></td><td><input type="number" min="0" max="' . DELICAT_MAX_REWARD . '" name="' . esc_attr( $pk ) . '" value="' . $pv . '"> 💎';
		if ( $pv > 0 ) {
			$pp = delicat_reward_plan( $pv );
			echo ' <span class="hint">' . ( $pp ? esc_html( implode( ' + ', array_map( function ( $l ) { return $l['qty'] . '× PIN ' . $l['denom']; }, $pp ) ) ) : '<b style="color:#b32d2e">⛔ non composable</b>' ) . '</span>';
		} else {
			echo ' <span class="hint">0 = désactivé (seule la 1ère place gagne)</span>';
		}
		echo '</td></tr>';
	}

	}
	if ( ! $board ) {
	echo '<tr><td><b>Plafond de récompenses par client / mois</b></td><td><input type="number" min="0" max="' . DELICAT_MAX_MONTH_CAP . '" name="monthly_cap" value="' . (int) $cfg['monthly_cap'] . '"> 💎'
		. '<div class="hint">0 = illimité. Filet de sécurité : au-delà, le client ne peut plus réclamer ce mois-ci.</div></td></tr>';

	}
	if ( $board ) {
	$days = array( 'Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi' );
	echo '<tr><td><b>Reset du classement</b></td><td><select name="reset_day">';
	foreach ( $days as $i => $d ) echo '<option value="' . $i . '"' . selected( $i, (int) $cfg['reset_day'], false ) . '>' . esc_html( $d ) . '</option>';
	echo '</select> à <input type="number" min="0" max="23" name="reset_hour" value="' . (int) $cfg['reset_hour'] . '"> h</td></tr>';

	echo '<tr><td><b>Taille du classement</b></td><td><input type="number" min="1" max="50" name="board_size" value="' . (int) $cfg['board_size'] . '"></td></tr>';
	echo '<tr><td><b>Masquer les noms</b></td><td><label><input type="checkbox" name="mask_names" value="1"' . checked( ! empty( $cfg['mask_names'] ), true, false ) . '> Afficher « Jean R. » au lieu du nom complet</label></td></tr>';
	}
	echo '<tr><td><b>Lien WhatsApp</b></td><td><input type="url" class="regular-text" name="wa" value="' . esc_attr( $cfg['wa'] ) . '"></td></tr>';
	echo '</tbody></table></div>';

	if ( ! $board ) {
	/* texts */
	echo '<div class="box"><h3>Textes de la page client</h3><table class="widefat" style="border:0"><tbody>';
	$fields = array(
		'page_title'   => 'Titre de la page',
		'program_name' => 'Nom du programme',
		't_spend'      => 'Libellé « achats du mois »',
		't_reward'     => 'Libellé « récompense du mois »',
		't_progress'   => 'Titre de la section progression',
		'note'         => 'Note de bas de carte',
		'unit'         => 'Unité (DIAMANT)',
		'currency'     => 'Monnaie (G)',
		'badge_suffix' => 'Mot après le badge (Bonus)',
		'btn_prefix'   => 'Début du bouton (RECLAMER)',
		'btn_suffix'   => 'Fin du bouton (GRATUIT)',
		'btn_locked'   => 'Bouton quand la limite est atteinte',
		'btn_dry'      => 'Bouton en mode test',
		'notify_subject' => 'Objet de l\'email au client',
	);
	foreach ( $fields as $f => $label ) {
		$max = ( 'note' === $f ) ? 160 : DELICAT_TEXT_LEN;
		echo '<tr><td style="width:280px"><b>' . esc_html( $label ) . '</b></td>'
			. '<td><input type="text" class="regular-text" name="' . esc_attr( $f ) . '" value="' . esc_attr( (string) $cfg[ $f ] ) . '" maxlength="' . (int) $max . '"></td></tr>';
	}
	echo '</tbody></table></div>';

	}
	if ( $board ) {
	/* Meilleur Client texts */
	echo '<div class="box"><h3>Textes — page Meilleur Client</h3><p class="hint">Personnalisez les informations visibles dans le shortcode <code>[delicat_meilleur_client]</code> sur le site client.</p><table class="widefat" style="border:0"><tbody>';
	$board_fields = array(
		'board_kicker'       => 'Petit texte au-dessus du titre',
		'board_title'        => 'Titre principal',
		'board_games'        => 'Jeux/services affichés',
		'board_reset_label'  => 'Libellé du compte à rebours',
		'board_reward_label' => 'Libellé de la récompense',
		'board_prize_suffix' => 'Texte après le montant du prix',
		'board_reward_sub'   => 'Message sous la récompense',
		'board_you_label'    => 'Libellé position du client',
		'board_login_text'   => 'Texte lorsque le client est déconnecté',
		'board_heading'      => 'Titre du classement',
		'board_hidden_text'  => 'Texte de confidentialité',
		'board_empty_text'   => 'Message si le classement est vide',
		'board_first_text'   => 'Message pour la première place',
		'board_rank_text'    => 'Message de position — utilisez {rank}',
		'board_gap_text'     => 'Message de progression — {rank}, {gap}, {ahead}',
		'board_start_text'   => 'Message avant le premier achat',
		'board_footer_1'     => 'Pied de page — ligne 1',
		'board_footer_2'     => 'Pied de page — ligne 2',
	);
	foreach ( $board_fields as $f => $label ) {
		echo '<tr><td style="width:280px"><b>' . esc_html( $label ) . '</b></td><td><input type="text" class="large-text" name="' . esc_attr( $f ) . '" value="' . esc_attr( (string) $cfg[ $f ] ) . '" maxlength="160"></td></tr>';
	}
	echo '<tr><td><b>Lien du pied de page</b></td><td><input type="url" class="large-text" name="board_footer_url" value="' . esc_attr( (string) $cfg['board_footer_url'] ) . '"></td></tr>';
	echo '</tbody></table></div>';

	}
	/* notifications */
	echo '<div class="box"><h3>Email au client</h3>';
	echo '<p><label><input type="checkbox" name="notify_client" value="1"' . checked( ! empty( $cfg['notify_client'] ), true, false ) . '> '
		. '<b>Prévenir le client par email dès que sa récompense est livrée</b></label></p>';
	echo '<p class="hint">Balises disponibles : <code>{name}</code> <code>{tier}</code> <code>{reward}</code> <code>{unit}</code> <code>{order}</code> <code>{site}</code></p>';
	if ( ! $board ) echo '<p><b>Message — bonus réclamé</b><br><textarea name="notify_body" rows="6" class="large-text" maxlength="' . DELICAT_MSG_LEN . '">'
		. esc_textarea( (string) $cfg['notify_body'] ) . '</textarea></p>';
	if ( $board ) echo '<p><b>Message — gagnant Meilleur Client</b><br><textarea name="notify_winner" rows="5" class="large-text" maxlength="' . DELICAT_MSG_LEN . '">'
		. esc_textarea( (string) $cfg['notify_winner'] ) . '</textarea></p>';
	echo '<p class="hint">L\'email part avec la messagerie de WordPress. Si vos emails WooCommerce arrivent bien, celui-ci aussi.</p></div>';

	echo '<p><button class="button button-primary button-large">Enregistrer les réglages</button></p></form>';

	if ( $board ) return;
	/* reset */
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="box danger" onsubmit="return confirm(\'Réinitialiser toute la configuration Delicat Rewards ?\');">';
	wp_nonce_field( 'delicat_reset' );
	echo '<input type="hidden" name="action" value="delicat_reset">';
	echo '<h3>Réinitialiser la configuration</h3><p class="hint">Remet tiers, produits et textes aux valeurs d\'origine. Aucune commande, aucun code et aucune réclamation déjà faite n\'est touché.</p>';
	echo '<button class="button">Réinitialiser</button></form>';
}

/** One on/off row (posts to admin-post.php). */
function delicat_toggle_row( $key, $label, $on ) {
	$bg  = $on ? '#e7f6ec' : '#fde8e8';
	$bd  = $on ? '#bfe3cb' : '#f3c2c2';
	$st  = $on ? '🟢 ACTIVÉ' : '🔴 EN PAUSE';
	$des = $on ? 'Visible normalement par les clients.' : 'Les clients voient « Programme momentanément en pause ».';
	$btn = $on ? 'Mettre en pause' : 'Activer';

	$h  = '<div style="background:' . $bg . ';border:1px solid ' . $bd . ';border-radius:8px;padding:12px 14px;margin:8px 0;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">';
	$h .= '<div><b>' . esc_html( $label ) . ' : ' . $st . '</b><br><span class="hint">' . esc_html( $des ) . '</span></div>';
	$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0">';
	$h .= wp_nonce_field( 'delicat_toggle', '_wpnonce', true, false );
	$h .= '<input type="hidden" name="action" value="delicat_toggle">';
	$h .= '<input type="hidden" name="which" value="' . esc_attr( $key ) . '">';
	$h .= '<button class="button">' . esc_html( $btn ) . '</button></form></div>';
	return $h;
}

/* =========================================================================
   ADMIN — RAPPORTS (monthly totals, CSV export, client simulator)
   ========================================================================= */

/** Last 12 month keys, newest first. */
function delicat_month_list() {
	$out = array();
	$t   = current_time( 'timestamp' );
	for ( $i = 0; $i < 12; $i++ ) {
		$out[] = date( 'Y-m', strtotime( "-{$i} month", $t ) );
	}
	return $out;
}

/** Everything claimed in a given month, per client and per tier. Read-only. */
function delicat_month_claims( $month ) {
	$rows  = array();
	$users = get_users( array(
		'meta_key' => delicat_ledger_key( $month ),
		'number'   => 1000,
		'fields'   => array( 'ID' ),
	) );

	/* Ledgers created before 2.1 use the legacy per-tier keys. */
	if ( ! $users ) {
		foreach ( array_keys( array_merge( delicat_tier_history(), delicat_all_tiers() ) ) as $k ) {
			$legacy = get_users( array( 'meta_key' => "_delicat_claimed_{$month}_{$k}", 'number' => 1000, 'fields' => array( 'ID' ) ) );
			foreach ( $legacy as $u ) $users[] = $u;
		}
	}

	$seen  = array();
	$tiers = array_merge( delicat_tier_history(), delicat_all_tiers() );
	foreach ( $users as $u ) {
		$uid = (int) $u->ID;
		if ( isset( $seen[ $uid ] ) ) continue;
		$seen[ $uid ] = true;

		$ledger = delicat_ledger_get( $uid, $month );
		foreach ( $ledger['claims'] as $key => $n ) {
			if ( $n < 1 ) continue;
			$t = $tiers[ $key ] ?? array( 'label' => $key, 'reward' => 0 );
			$rows[] = array(
				'uid'      => $uid,
				'tier'     => $key,
				'label'    => (string) $t['label'],
				'claims'   => (int) $n,
				'diamonds' => (int) $n * (int) $t['reward'],
				'signed'   => ! empty( $ledger['ok'] ),
			);
		}
	}
	return $rows;
}

/** Reward orders issued during a month (the delivery record). */
function delicat_month_reward_orders( $month ) {
	if ( ! function_exists( 'wc_get_orders' ) ) return array();
	$start = strtotime( $month . '-01 00:00:00' );
	$end   = strtotime( '+1 month', $start );
	return delicat_orders_paged( array(
		'date_created' => $start . '...' . ( $end - 1 ),
		'meta_key'     => '_delicat_reward',
		'meta_compare' => 'EXISTS',
	), 200, 5000 );
}

/* ---- CSV export ---- */
function delicat_csv_safe_row( array $row ) {
	foreach ( $row as &$v ) {
		if ( is_string( $v ) && preg_match( '/^[=+\-@\t\r]/', $v ) ) $v = "'" . $v;
	}
	unset( $v ); return $row;
}
add_action( 'admin_post_delicat_export', function () {
	delicat_guard( 'delicat_export' );

	$month = sanitize_text_field( wp_unslash( $_POST['month'] ?? '' ) );
	if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) $month = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	$what = ( ( $_POST['what'] ?? '' ) === 'orders' ) ? 'orders' : 'claims';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=delicat-' . $what . '-' . $month . '.csv' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel reads the accents

	if ( 'orders' === $what ) {
		fputcsv( $out, delicat_csv_safe_row( array( 'Commande', 'Date', 'Client ID', 'Identifiant', 'Email', 'Origine', 'Produits livrés', 'Total (G)' ) ) );
		foreach ( delicat_month_reward_orders( $month ) as $o ) {
			$u     = get_userdata( (int) $o->get_customer_id() );
			$items = array();
			foreach ( $o->get_items() as $it ) $items[] = $it->get_quantity() . '× ' . $it->get_name();
			fputcsv( $out, delicat_csv_safe_row( array(
				$o->get_id(),
				$o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
				(int) $o->get_customer_id(),
				$u ? $u->user_login : '',
				$u ? $u->user_email : '',
				(string) $o->get_meta( '_delicat_reward' ),
				implode( ' | ', $items ),
				$o->get_total(),
			) ) );
		}
	} else {
		fputcsv( $out, delicat_csv_safe_row( array( 'Mois', 'Client ID', 'Identifiant', 'Nom', 'Email', 'Tier', 'Badge', 'Réclamations', 'Diamants offerts', 'Signature' ) ) );
		$total = 0;
		foreach ( delicat_month_claims( $month ) as $r ) {
			$u      = get_userdata( $r['uid'] );
			$total += $r['diamonds'];
			fputcsv( $out, delicat_csv_safe_row( array(
				$month,
				$r['uid'],
				$u ? $u->user_login : '',
				$u ? $u->display_name : '',
				$u ? $u->user_email : '',
				$r['tier'],
				$r['label'],
				$r['claims'],
				$r['diamonds'],
				$r['signed'] ? 'OK' : 'ALTEREE',
			) ) );
		}
		fputcsv( $out, delicat_csv_safe_row( array() ) );
		fputcsv( $out, delicat_csv_safe_row( array( 'TOTAL', '', '', '', '', '', '', '', $total, '' ) ) );
	}
	fclose( $out );
	delicat_audit_add( sprintf( 'Export CSV (%s) du mois %s.', $what, $month ) );
	exit;
} );

function delicat_tab_reports() {
	$months = delicat_month_list();
	$month  = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : $months[0];
	if ( ! in_array( $month, $months, true ) ) $month = $months[0];

	/* month picker */
	echo '<form method="get" style="margin-bottom:16px"><input type="hidden" name="page" value="delicat-rewards">'
		. '<input type="hidden" name="tab" value="rapports"><label><b>Mois :</b> <select name="month" onchange="this.form.submit()">';
	foreach ( $months as $m ) echo '<option value="' . esc_attr( $m ) . '"' . selected( $m, $month, false ) . '>' . esc_html( $m ) . '</option>';
	echo '</select></label></form>';

	/* totals per tier */
	$rows  = delicat_month_claims( $month );
	$per   = array();
	$total = 0; $clients = array();
	foreach ( $rows as $r ) {
		if ( ! isset( $per[ $r['tier'] ] ) ) $per[ $r['tier'] ] = array( 'label' => $r['label'], 'claims' => 0, 'diamonds' => 0 );
		$per[ $r['tier'] ]['claims']   += $r['claims'];
		$per[ $r['tier'] ]['diamonds'] += $r['diamonds'];
		$total += $r['diamonds'];
		$clients[ $r['uid'] ] = true;
	}

	echo '<div class="box"><h3>Ce que le programme vous a coûté en ' . esc_html( $month ) . '</h3>';
	if ( ! $per ) {
		echo '<p><em>Aucune réclamation ce mois-ci.</em></p>';
	} else {
		echo '<table class="form"><thead><tr><th>Tier</th><th>Réclamations</th><th>Diamants offerts</th></tr></thead><tbody>';
		foreach ( $per as $key => $p ) {
			echo '<tr><td><b>' . esc_html( $p['label'] ) . '</b> <code>' . esc_html( $key ) . '</code></td>'
				. '<td>' . (int) $p['claims'] . '</td><td><b>' . (int) $p['diamonds'] . ' 💎</b></td></tr>';
		}
		echo '<tr><td><b>TOTAL</b> — ' . count( $clients ) . ' client(s)</td><td></td><td><b>' . (int) $total . ' 💎</b></td></tr>';
		echo '</tbody></table>';
	}
	echo '</div>';

	/* exports */
	echo '<div class="box"><h3>Export comptable</h3><p class="hint">Fichier CSV, ouvrable dans Excel ou Google Sheets.</p>';
	foreach ( array( 'claims' => 'Exporter les réclamations (CSV)', 'orders' => 'Exporter les commandes récompense (CSV)' ) as $what => $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:10px">';
		echo wp_nonce_field( 'delicat_export', '_wpnonce', true, false );
		echo '<input type="hidden" name="action" value="delicat_export">';
		echo '<input type="hidden" name="what" value="' . esc_attr( $what ) . '">';
		echo '<input type="hidden" name="month" value="' . esc_attr( $month ) . '">';
		echo '<button class="button button-primary">' . esc_html( $label ) . '</button></form>';
	}
	echo '</div>';

	/* client simulator */
	$q = isset( $_GET['sim'] ) ? sanitize_text_field( wp_unslash( $_GET['sim'] ) ) : '';
	echo '<div class="box"><h3>Simulateur — que verrait ce client ?</h3>'
		. '<p class="hint">Lecture seule. Recalcule la progression réelle du client avec la configuration actuelle : parfait pour vérifier l\'effet d\'un changement d\'objectif avant de l\'ouvrir à tout le monde.</p>';
	echo '<form method="get" style="margin-bottom:12px"><input type="hidden" name="page" value="delicat-rewards">'
		. '<input type="hidden" name="tab" value="rapports"><input type="hidden" name="month" value="' . esc_attr( $month ) . '">'
		. '<input type="search" name="sim" value="' . esc_attr( $q ) . '" class="regular-text" placeholder="Identifiant, email ou ID du client"> '
		. '<button class="button">Simuler</button></form>';

	if ( '' !== $q ) {
		$user = is_numeric( $q ) ? get_userdata( (int) $q ) : ( get_user_by( 'login', $q ) ?: get_user_by( 'email', $q ) );
		if ( ! $user ) {
			echo '<p><em>Client introuvable.</em></p>';
		} else {
			$state = delicat_compute_state( $user->ID ); // read-only: computes, never writes
			$tiers = delicat_tiers();
			echo '<p><b>' . esc_html( $user->display_name ) . '</b> <code>#' . (int) $user->ID . '</code> · achats du mois : <b>'
				. esc_html( (string) $state['monthSpend'] ) . ' ' . esc_html( (string) delicat_cfg( 'currency' ) ) . '</b> · déjà offert : <b>'
				. (int) $state['monthReward'] . ' 💎</b></p>';
			echo '<table class="form"><thead><tr><th>Tier</th><th>Progression</th><th>Déjà réclamé</th><th>Peut réclamer ?</th></tr></thead><tbody>';
			foreach ( $tiers as $key => $t ) {
				$s   = $state['tiers'][ $key ] ?? array();
				$why = $s['blocked'] ?? '';
				$lbl = ! empty( $s['claimable'] ) ? '<b style="color:#1a7f37">✅ OUI — ' . (int) $t['reward'] . ' 💎</b>'
					: ( 'cap'    === $why ? '⛔ plafond mensuel atteint'
					: ( 'max'    === $why ? '⛔ limite de réclamations atteinte'
					: ( 'dry'    === $why ? '🧪 éligible, mais mode test actif'
					: ( 'frozen' === $why ? '⛔ compte gelé (intégrité)'
					: '⏳ pas encore' ) ) ) );
				echo '<tr><td><b>' . esc_html( $t['label'] ) . '</b></td>'
					. '<td>' . (int) ( $s['progress'] ?? 0 ) . ' / ' . (int) $t['goal'] . ' fois</td>'
					. '<td>' . (int) ( $s['claimed'] ?? 0 ) . '×</td>'
					. '<td>' . $lbl . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
	echo '</div>';
}

/* ---- TAB: DIAGNOSTICS ---- */
function delicat_stock_cell( $pid ) {
	$pid = (int) $pid;
	if ( ! $pid ) return '—';
	if ( ! function_exists( 'delicat_keys_available' ) ) return '<span class="hint" title="Activez Delicat Keys">n/a</span>';
	$n     = (int) delicat_keys_available( $pid );
	$color = 0 === $n ? '#b32d2e' : ( $n <= 5 ? '#b26a00' : '#1a7f37' );
	$warn  = 0 === $n ? ' ⛔' : ( $n <= 5 ? ' ⚠️' : '' );
	return '<b style="color:' . $color . '">' . $n . $warn . '</b>';
}

function delicat_tab_diagnostics() {
	/* Integrity panel */
	$tamper = get_option( 'delicat_tamper', array() );
	if ( is_array( $tamper ) && $tamper ) {
		echo '<div class="notice notice-error" style="margin:0 0 16px"><p><b>⛔ Alerte d\'intégrité</b><br>'
			. 'Les compteurs de réclamations de ces comptes ne correspondent plus à leur signature : ils ont été modifiés en dehors du plugin. '
			. 'Leurs réclamations sont gelées.</p><ul style="margin-left:18px;list-style:disc">';
		foreach ( array_slice( $tamper, 0, 10 ) as $row ) {
			$u = get_userdata( (int) ( $row['u'] ?? 0 ) );
			echo '<li>' . esc_html( $row['t'] ?? '' ) . ' — <b>' . esc_html( $u ? $u->user_login : ( '#' . (int) ( $row['u'] ?? 0 ) ) ) . '</b> : '
				. esc_html( $row['w'] ?? '' ) . '</li>';
		}
		echo '</ul><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		wp_nonce_field( 'delicat_clear_tamper' );
		echo '<input type="hidden" name="action" value="delicat_clear_tamper">';
		echo '<button class="button">J\'ai vérifié — effacer les alertes</button></form></div>';
	} else {
		echo '<div class="notice notice-success" style="margin:0 0 16px"><p>🔐 Intégrité : aucune altération détectée. '
			. 'Les compteurs de bonus sont signés (HMAC-SHA256) et les boutons « Réclamer » portent un jeton signé de 30 minutes.</p></div>';
	}

	/* Signing key: tell a salt change from fraud and offer the recovery. */
	$reseal_month = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	echo '<div class="box"><h3>Clé de signature des bonus</h3>';
	if ( delicat_key_changed() ) {
		echo '<p style="color:#b32d2e"><b>⛔ La clé de signature a changé</b> (salts WordPress modifiés, déménagement du site…). Les registres signés avant ce changement sont gelés : ce n’est pas une fraude. Re-scellez-les ci-dessous.</p>';
	}
	echo '<p class="hint">' . ( defined( 'DELICAT_REWARDS_HMAC_KEY' )
		? '✅ Clé dédiée <code>DELICAT_REWARDS_HMAC_KEY</code> active : un changement des salts WordPress ne gèle plus les bonus.'
		: '⚠️ Les registres sont signés avec les salts WordPress. Avant tout changement de salts, ajoutez dans <code>wp-config.php</code> une clé dédiée d’au moins 32 caractères : <code>define( \'DELICAT_REWARDS_HMAC_KEY\', \'…\' );</code>. Les registres existants restent valides et sont re-scellés à la lecture.' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_reseal' );
	echo '<input type="hidden" name="action" value="delicat_reseal"><p><label>Mois : <select name="month">';
	foreach ( array( $reseal_month, delicat_month_shift( $reseal_month, -1 ) ) as $m ) echo '<option value="' . esc_attr( $m ) . '">' . esc_html( $m ) . '</option>';
	echo '</select></label> <button class="button">Re-sceller les registres du mois</button></p>';
	echo '<p class="hint">Répare uniquement les registres gelés. Aucune réclamation n’est baissée : chaque bonus garde le plus haut des compteurs enregistrés et des commandes récompense du mois (sauf annulées ou échouées). Effacez ensuite les alertes.</p></form></div>';

	echo '<div class="box"><h3>Ce qui compte comme un achat réel</h3><p class="hint">'
		. '• Commande d\'un <b>compte connecté</b> (les commandes invité ne comptent jamais)<br>'
		. '• Statut : <code>' . esc_html( implode( ', ', delicat_counting_statuses() ) ) . '</code><br>'
		. '• Paiement confirmé : <b>' . ( delicat_cfg( 'require_paid' ) ? 'exigé ✅' : 'NON exigé ⚠️' ) . '</b><br>'
		. '• Remboursements déduits : <b>' . ( delicat_cfg( 'ignore_refund' ) ? 'oui ✅' : 'non ⚠️' ) . '</b><br>'
		. '• Livraison du code exigée pour les achats PIN : <b>' . ( delicat_cfg( 'require_deliv' ) ? 'oui' : 'non' ) . '</b><br>'
		. '• Les commandes récompense (0 G) ne comptent jamais.</p>';

	/* Reality check: would the "paid" rule silently exclude your real orders? */
	if ( function_exists( 'wc_get_orders' ) ) {
		$recent  = wc_get_orders( array( 'status' => delicat_counting_statuses(), 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC' ) );
		$unpaid  = 0; $guest = 0; $total = 0;
		foreach ( $recent as $o ) {
			if ( $o->get_meta( '_delicat_reward' ) ) continue;
			$total++;
			if ( ! $o->get_date_paid() )        $unpaid++;
			if ( ! (int) $o->get_customer_id() ) $guest++;
		}
		if ( $total ) {
			$style = $unpaid ? 'color:#b26a00;font-weight:600' : 'color:#1a7f37;font-weight:600';
			echo '<p style="' . $style . '">Sur les ' . (int) $total . ' dernières commandes : <b>' . (int) $unpaid . '</b> sans date de paiement'
				. ( $unpaid ? ' ⚠️ (elles ne compteront pas tant que « paiement confirmé » est exigé — vérifiez votre passerelle)' : ' ✅' )
				. ' · <b>' . (int) $guest . '</b> passées sans compte' . ( $guest ? ' (jamais comptées)' : '' ) . '.</p>';
		}
	}
	echo '</div>';

	$problems = delicat_config_problems();
	if ( $problems ) {
		echo '<div class="notice notice-warning" style="margin:0 0 16px"><p><b>' . count( $problems ) . ' problème(s) :</b><br>'
			. implode( '<br>', array_map( 'esc_html', $problems ) ) . '</p></div>';
	} else {
		echo '<div class="notice notice-success" style="margin:0 0 16px"><p>✅ Configuration cohérente : achats Free Fire mappés et récompenses PIN composables.</p></div>';
	}

	echo '<table class="form"><thead><tr><th>Tier</th><th>Statut</th><th>Achats éligibles (PIN/direct/serveurs)</th><th>Objectif</th><th>Récompense livrée</th></tr></thead><tbody>';
	foreach ( delicat_all_tiers() as $key => $raw ) {
		$on   = ! empty( $raw['enabled'] );
		$ids  = array();
		foreach ( (array) ( $raw['ids'] ?? array() ) as $id ) { if ( delicat_valid_pid( $id ) ) $ids[] = (int) $id; }
		$fb = delicat_pid_by_diamonds( (int) ( $raw['pin'] ?? 0 ) ); if ( $fb ) $ids[] = $fb;
		$ids = array_values( array_unique( $ids ) );

		$plan = delicat_reward_plan( (int) ( $raw['reward'] ?? 0 ) );
		$rew  = '<b>' . (int) ( $raw['reward'] ?? 0 ) . ' 💎</b> = ';
		if ( $plan ) {
			$parts = array();
			foreach ( $plan as $l ) {
				$pid     = delicat_pid_by_diamonds( $l['denom'] );
				$deliv   = delicat_purchasable( $pid );
				$did     = $deliv ? $deliv->get_id() : 0;
				$extra   = ( $did && $did !== (int) $pid ) ? ' <span class="hint">→ livré : ' . esc_html( $deliv->get_name() ) . ' <code>#' . (int) $did . '</code></span>' : '';
				if ( ! $deliv ) $extra = ' <b style="color:#b32d2e">⛔ aucune variation achetable</b>';
				$parts[] = $l['qty'] . '× PIN ' . (int) $l['denom'] . ' ' . delicat_pid_hint( $pid ) . $extra . ' · stock ' . delicat_stock_cell( $did ?: $pid );
			}
			$rew .= implode( '<br>', $parts );
		} else {
			$rew .= '<b style="color:#b32d2e">⛔ non composable</b>';
		}

		echo '<tr><td><b>' . esc_html( $raw['label'] ?? $key ) . '</b><div class="hint"><code>' . esc_html( $key ) . '</code></div></td>';
		echo '<td>' . ( $on ? '🟢 actif' : '⚪️ inactif' ) . '</td>';
		echo '<td>' . ( $ids ? implode( '<br>', array_map( 'delicat_pid_hint', $ids ) ) : '<b style="color:#b32d2e">⛔ aucun</b>' ) . '</td>';
		echo '<td>' . (int) ( $raw['goal'] ?? 0 ) . ' fois</td>';
		echo '<td>' . $rew . '</td></tr>';
	}
	echo '</tbody></table>';

	/* Count the explicit configured map without scanning the whole product catalog. */
	$count = count( delicat_ff_pin_ids() );
	echo '<p style="margin-top:16px">Produits explicitement configurés pour Free Fire : <b>' . (int) $count . '</b> · dénominations : <code>'
		. esc_html( implode( ', ', delicat_ff_denoms() ) ) . '</code></p>';

	if ( function_exists( 'delicat_is_keyed' ) ) {
		$not_keyed = array();
		foreach ( delicat_ff_denoms() as $d ) {
			$pid = delicat_pid_by_diamonds( $d );
			if ( $pid && ! delicat_is_keyed( $pid ) ) $not_keyed[] = $d . ' 💎 (#' . $pid . ')';
		}
		if ( $not_keyed ) {
			echo '<p style="color:#b26a00;font-weight:600">⚠️ Produits trouvés mais NON activés pour la livraison — cochez « Delicat Keys » sur : '
				. esc_html( implode( ', ', $not_keyed ) ) . ', sinon l\'achat ne livrera aucun code.</p>';
		} else {
			echo '<p style="color:#1a7f37;font-weight:600">✅ Tous les PINs sont activés pour la livraison (Delicat Keys).</p>';
		}
	} else {
		echo '<p class="hint">Activez « Delicat Store — Secure Keys » pour voir le stock de codes et la livraison.</p>';
	}
	echo '<p class="hint">Cette page est en lecture seule : aucune commande, aucun solde et aucun code n\'est modifié.</p>';
}

/* ---- TAB: JOURNAL ---- */
function delicat_tab_audit() {
	$log = get_option( 'delicat_audit', array() );
	if ( ! is_array( $log ) || ! $log ) {
		echo '<p><em>Aucune modification enregistrée pour l\'instant.</em></p>';
		return;
	}
	echo '<p class="hint">Les 40 dernières modifications de configuration : qui, quand, depuis quelle IP. Les réclamations des clients, elles, sont tracées dans les commandes récompense (note de commande).</p>';
	echo '<table class="form"><thead><tr><th style="width:170px">Date</th><th style="width:150px">Utilisateur</th><th style="width:140px">IP</th><th>Modification</th></tr></thead><tbody>';
	foreach ( $log as $row ) {
		echo '<tr><td>' . esc_html( $row['t'] ?? '' ) . '</td><td>' . esc_html( $row['u'] ?? '' ) . '</td>'
			. '<td><code>' . esc_html( $row['ip'] ?? '' ) . '</code></td><td>' . esc_html( $row['w'] ?? '' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

/* =========================================================================
   ADMIN — PARTICIPANTS
   ========================================================================= */
function delicat_week_participants() {
	/* Include excluded accounts here so an administrator can still inspect and
	 * reinstate them. The public leaderboard continues to exclude them. */
	return delicat_collect_leaderboard_rows( delicat_competition_start(), null, true );
}

function delicat_participants_page() {
	if ( ! current_user_can( delicat_cap() ) ) {
		wp_die( 'Accès refusé.', 'Delicat Rewards', array( 'response' => 403 ) );
	}

	if ( isset( $_POST['delicat_part_uid'] ) && check_admin_referer( 'delicat_participants' ) ) {
		$uid = (int) $_POST['delicat_part_uid'];
		$off = ( ( $_POST['delicat_part_to'] ?? '' ) === 'off' );
		if ( $uid && get_userdata( $uid ) ) {
			delicat_set_excluded( $uid, $off );
			delicat_audit_add( sprintf( 'Participant %s : %s', delicat_display_name( $uid ), $off ? 'retiré' : 'réintégré' ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( delicat_display_name( $uid ) ) . ' : '
				. ( $off ? '🔴 retiré du classement' : '🟢 affiché dans le classement' ) . '.</p></div>';
		}
	}

	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

	echo '<div class="wrap"><h1>Delicat — Participants</h1>';
	echo '<p style="max-width:760px;color:#555;"><b>🟢 Affiché</b> : le client participe normalement. <b>🔴 Masqué</b> : son nom est retiré du classement et il ne peut plus réclamer de récompense. Par défaut, tout le monde participe.</p>';

	echo '<form method="get" style="margin:10px 0;"><input type="hidden" name="page" value="delicat-participants">';
	echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Rechercher un client (nom, email)" style="width:280px;padding:6px;"> ';
	echo '<button class="button">Rechercher</button></form>';

	$weekly = delicat_week_participants();
	$show   = array();
	if ( '' !== $search ) {
		$users = get_users( array( 'search' => '*' . $search . '*', 'search_columns' => array( 'user_login', 'user_email', 'display_name' ), 'number' => 50, 'fields' => array( 'ID' ) ) );
		foreach ( $users as $u ) $show[ (int) $u->ID ] = $weekly[ (int) $u->ID ] ?? array();
	} else {
		$show = $weekly;
		$ex = get_users( array( 'meta_key' => '_delicat_excluded', 'meta_value' => '1', 'number' => 200, 'fields' => array( 'ID' ) ) );
		foreach ( $ex as $u ) if ( ! isset( $show[ (int) $u->ID ] ) ) $show[ (int) $u->ID ] = array();
	}

	if ( ! $show ) {
		echo '<p><em>' . ( '' !== $search ? 'Aucun client trouvé.' : 'Aucun participant cette semaine. Utilisez la recherche pour trouver un client.' ) . '</em></p></div>';
		return;
	}

	echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Client</th><th>Email</th><th>Commandes terminées</th><th>Montant net dépensé</th><th style="width:230px">Participation</th></tr></thead><tbody>';
	foreach ( $show as $uid => $ctx ) {
		$u = get_userdata( $uid );
		if ( ! $u ) continue;
		$excluded = delicat_is_excluded( $uid );
		$orders   = max( 0, (int) ( $ctx['orders'] ?? 0 ) );
		$spend    = max( 0.0, (float) ( $ctx['spend'] ?? 0 ) );
		echo '<tr><td><b>' . esc_html( $u->display_name ?: $u->user_login ) . '</b></td>';
		echo '<td>' . esc_html( $u->user_email ) . '</td>';
		echo '<td>' . ( $orders ? (int) $orders : '—' ) . '</td>';
		echo '<td>' . ( $spend > 0 && function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $spend ) ) : '—' ) . '</td><td>';
		echo '<form method="post" style="margin:0;display:flex;gap:8px;align-items:center;">';
		wp_nonce_field( 'delicat_participants' );
		echo '<input type="hidden" name="delicat_part_uid" value="' . (int) $uid . '">';
		if ( $excluded ) {
			echo '<input type="hidden" name="delicat_part_to" value="on">';
			echo '<span style="color:#b32d2e;font-weight:700;">🔴 Masqué</span>';
			echo '<button class="button button-primary">Afficher</button>';
		} else {
			echo '<input type="hidden" name="delicat_part_to" value="off">';
			echo '<span style="color:#1a7f37;font-weight:700;">🟢 Affiché</span>';
			echo '<button class="button">Retirer</button>';
		}
		echo '</form></td></tr>';
	}
	echo '</tbody></table></div>';
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
		'boardFooterUrl' => esc_url_raw( (string) delicat_cfg( 'board_footer_url' ) ),
		'nextReset' => delicat_next_week_start(),
		'serverNow' => time(),
	) );
}

/* CSS shared by both pages (Delicat Store brand) */
function delicat_print_css() {
	static $done = false; if ( $done ) return; $done = true;
	echo <<<'CSS'
<style>
.dlc{--navy:#1b2a4a;--purple:#5e3f97;--purple-dark:#4a3179;--purple-deep:#33215a;--indigo:#5b4fc7;
--mauve:#e1cdd4;--mauve-2:#ecdde1;--mauve-3:#f4eaed;--cream:#f3f0e3;--ink:#2a2230;--muted:#7a7280;
--gold:#d9a93b;--silver:#9aa3b2;--bronze:#c08454;--ok:#2e9e6b;--shadow:0 18px 40px -22px rgba(51,33,90,.55);
font-family:'Poppins',sans-serif;color:var(--ink);max-width:520px;margin:0 auto;line-height:1.5}
.dlc *{box-sizing:border-box}
.dlc h1.title{font-family:'Playfair Display',serif;font-weight:800;text-align:center;font-size:clamp(2.1rem,9vw,2.8rem);color:var(--ink);margin:26px 0 22px;letter-spacing:.5px}
.dlc .card{background:linear-gradient(160deg,var(--mauve-2),var(--mauve));border-radius:18px;padding:22px 20px;box-shadow:var(--shadow)}
.dlc .brand-mark{display:flex;flex-direction:column;align-items:center;gap:6px;padding-bottom:14px;border-bottom:1.5px solid rgba(60,40,90,.25)}
.dlc .brand-mark .mono{font-family:'Playfair Display',serif;font-weight:800;font-size:2.2rem;color:var(--purple-deep);line-height:1}
.dlc .brand-mark img{display:block;max-width:220px;max-height:125px;width:auto;height:auto;object-fit:contain}.dlc .brand-mark .leaf{font-size:1.4rem}.dlc .brand-mark small{font-size:.55rem;letter-spacing:2px;color:var(--purple-deep);font-weight:600}
.dlc .prog-name{text-align:center;font-weight:700;margin:14px 0 16px;font-size:.98rem}
.dlc .stat{background:var(--cream);border-radius:12px;text-align:center;padding:16px 14px;margin-bottom:14px}
.dlc .stat .lab{color:#5b5560;font-size:.95rem}.dlc .stat .val{font-size:1.5rem;font-weight:700;margin-top:2px}.dlc .stat .val.gold{color:var(--purple-deep)}
.dlc .nb{border-top:1.5px solid rgba(60,40,90,.25);padding-top:14px;text-align:center;color:#4d4654;font-size:.86rem}
.dlc h2.section{font-weight:700;text-align:center;letter-spacing:1px;margin:34px 0 18px;font-size:1.25rem}
.dlc .tier{background:linear-gradient(160deg,var(--mauve-2),var(--mauve));border-radius:16px;padding:18px;margin-bottom:18px;box-shadow:var(--shadow);opacity:0;transform:translateY(14px);animation:dlcrise .5s forwards}
@keyframes dlcrise{to{opacity:1;transform:none}}
.dlc .tier-top{display:flex;justify-content:space-between;gap:10px}.dlc .tier-name{font-weight:700;font-size:1.02rem;line-height:1.3}
.dlc .badge{color:var(--indigo);font-weight:700;text-align:right;font-size:.95rem;line-height:1.2;flex-shrink:0}
.dlc .track{height:9px;border-radius:6px;background:var(--cream);margin:16px 0 9px;overflow:hidden;box-shadow:inset 0 1px 3px rgba(0,0,0,.08)}
.dlc .fill{height:100%;width:0;border-radius:6px;background:linear-gradient(90deg,var(--indigo),var(--purple));transition:width .6s cubic-bezier(.4,0,.2,1)}
.dlc .meta{display:flex;justify-content:space-between;color:#4d4654;font-size:.9rem;font-weight:500}
.dlc .claim{width:100%;margin-top:15px;border:0;cursor:pointer;color:#fff;font-family:inherit;font-weight:600;background:linear-gradient(180deg,var(--purple),var(--purple-dark));padding:13px 12px;border-radius:9px;font-size:.82rem;letter-spacing:.4px;text-transform:uppercase;transition:.15s;box-shadow:0 8px 18px -10px rgba(74,49,121,.9)}
.dlc .claim:hover{filter:brightness(1.06)}.dlc .claim:disabled{background:#c3b6cf;cursor:not-allowed;box-shadow:none;filter:none}
.dlc .claim.ready{animation:dlcpulse 1.6s infinite}@keyframes dlcpulse{50%{box-shadow:0 8px 26px -6px rgba(94,63,151,.95)}}
.dlc .login{text-align:center;background:var(--cream);border-radius:12px;padding:18px;margin-top:8px}
.dlc .login a{display:inline-block;margin-top:8px;background:var(--purple);color:#fff;text-decoration:none;padding:10px 22px;border-radius:9px;font-weight:600}
/* leaderboard */
.dlc .hero{margin-top:18px;border-radius:22px;padding:30px 24px 28px;color:#fff;text-align:center;background:linear-gradient(155deg,#6a48aa,var(--purple) 45%,var(--purple-deep));box-shadow:var(--shadow);position:relative;overflow:hidden}
.dlc .hero::after{content:"";position:absolute;inset:0;background:radial-gradient(400px 200px at 80% -10%,rgba(255,255,255,.18),transparent 60%)}
.dlc .kicker{font-size:.74rem;letter-spacing:3px;font-weight:700;opacity:.92;margin-bottom:8px}
.dlc .hero h1{font-family:'Playfair Display',serif;font-weight:900;font-size:clamp(2.1rem,9.5vw,3rem);line-height:1.02}
.dlc .games{font-weight:600;font-size:.92rem;margin-top:10px;opacity:.95}
.dlc .reset{font-size:.74rem;letter-spacing:2px;font-weight:600;margin-top:18px;opacity:.85}.dlc .reset b{color:#ffe7a6;font-weight:800}
.dlc .reward{margin-top:18px;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:16px;padding:16px 18px;display:flex;align-items:center;gap:14px;text-align:left}
.dlc .reward .cup{font-size:2.3rem}.dlc .reward .r-lab{font-size:.68rem;letter-spacing:2px;font-weight:700;opacity:.85}
.dlc .reward .r-big{font-size:1.7rem;font-weight:800;margin:2px 0}.dlc .reward .r-sub{font-size:.82rem;opacity:.9}
.dlc .you{margin-top:16px;border:1.5px solid #e6d8de;background:#fff;border-radius:16px;padding:16px 18px;display:flex;align-items:center;gap:14px;box-shadow:0 10px 26px -20px rgba(51,33,90,.5)}
.dlc .you .badge-rank{width:48px;height:48px;border-radius:12px;display:grid;place-items:center;background:var(--mauve-3);color:var(--purple);font-weight:800;font-size:1.1rem;flex-shrink:0}
.dlc .you .y-lab{font-size:.78rem;color:var(--muted)}.dlc .you .y-name{font-weight:700;font-size:1.05rem}
.dlc .lbl{font-weight:800;letter-spacing:2px;margin:26px 4px 14px;font-size:.92rem;color:var(--muted)}
.dlc .row{background:#fff;border:1px solid #efe7ea;border-radius:16px;padding:14px 16px;margin-bottom:11px;display:flex;align-items:center;gap:14px;box-shadow:0 10px 24px -22px rgba(51,33,90,.6);opacity:0;transform:translateY(12px);animation:dlcrise .45s forwards}
.dlc .rank{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;font-weight:800;font-size:1rem;flex-shrink:0;background:var(--mauve-3)}
.dlc .rank.medal{font-size:1.5rem;border:2px solid}.dlc .rank.g{border-color:var(--gold);background:#fbf3dc}.dlc .rank.s{border-color:var(--silver);background:#eef0f4}.dlc .rank.b{border-color:var(--bronze);background:#f6e7da}
.dlc .name{font-weight:700;font-size:1.02rem;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis}.dlc .row.top .name{color:var(--purple-deep)}
.dlc .lock{display:flex;align-items:center;gap:6px;color:#a59bb0;font-weight:600;font-size:.9rem;flex-shrink:0}
.dlc .foot{margin-top:22px;text-align:center;color:var(--muted);font-size:.82rem;line-height:1.6}.dlc .foot a{color:var(--purple);font-weight:700;text-decoration:none}
.dlc-wa{position:fixed;right:18px;bottom:20px;width:56px;height:56px;border-radius:50%;background:#25d366;display:flex;align-items:center;justify-content:center;box-shadow:0 12px 26px -8px rgba(37,211,102,.7);z-index:60;text-decoration:none}
.dlc-wa svg{width:30px;height:30px;fill:#fff}
.dlc-toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(40px);background:var(--purple-deep,#33215a);color:#fff;padding:13px 20px;border-radius:12px;font-weight:600;font-size:.9rem;opacity:0;transition:.3s;z-index:80;max-width:90vw;text-align:center;font-family:'Poppins',sans-serif}
.dlc-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

/* =======================================================================
   REWARDS PAGE — v2.7 Premium Rewards. Fully scoped to #dlc-rewards.
   Lightweight: no backdrop-filter, no permanent particles, GPU-safe motion.
   ======================================================================= */
/* =======================================================================
   MEILLEUR CLIENT — "Diamond Vault" dark theme + podium. Scoped entirely
   to #dlc-board: the rewards page and its Premium Journey additions are
   untouched. No color-mix / backdrop-filter (weak-Android safe).
   ======================================================================= */
#dlc-board{font-family:'Inter',system-ui,sans-serif;color:#EDF1F8;max-width:520px;margin:0 auto;
  background:radial-gradient(560px 340px at 50% -70px,rgba(76,141,255,.14),transparent 60%),
             radial-gradient(520px 320px at 112% 8%,rgba(155,140,255,.12),transparent 55%),
             linear-gradient(180deg,#0A0F1A,#070A11);
  border:1px solid rgba(255,255,255,.08);border-radius:24px;padding:6px 16px 26px;
  box-shadow:0 30px 70px -30px rgba(0,0,0,.72);overflow:hidden}
#dlc-board .hero{margin-top:14px;border-radius:22px;padding:28px 22px 24px;text-align:center;color:#fff;
  background:linear-gradient(160deg,#241a52,#191436 55%,#0d0a1f);border:1px solid rgba(155,140,255,.22);
  box-shadow:0 26px 60px -30px rgba(0,0,0,.85);position:relative;overflow:hidden}
#dlc-board .hero::after{content:"";position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(360px 200px at 82% -12%,rgba(124,141,255,.30),transparent 60%),
            radial-gradient(300px 180px at 6% 6%,rgba(44,229,224,.14),transparent 60%)}
#dlc-board .hero::before{content:"";position:absolute;inset:-40% -10%;pointer-events:none;z-index:0;
  background:linear-gradient(115deg,transparent 42%,rgba(255,255,255,.07) 49%,rgba(155,140,255,.12) 53%,transparent 62%);
  transform:translateX(-130%);animation:dlcSheenB 7s ease-in-out infinite}
@keyframes dlcSheenB{0%,68%{transform:translateX(-130%)}86%,100%{transform:translateX(130%)}}
#dlc-board .kicker{position:relative;font-family:'Inter',sans-serif;font-size:.72rem;letter-spacing:.24em;
  font-weight:700;text-transform:uppercase;color:#C9B8FF;opacity:1;margin-bottom:10px}
#dlc-board .hero h1{position:relative;font-family:'Sora',sans-serif;font-weight:800;
  font-size:clamp(2rem,9vw,2.8rem);line-height:1.02;letter-spacing:-.01em;color:#F4F7FF;
  text-shadow:0 2px 34px rgba(124,141,255,.35)}
#dlc-board .games{position:relative;font-weight:600;font-size:.92rem;margin-top:12px;color:#D7DEEC;opacity:1}
#dlc-board .reset{position:relative;display:inline-flex;align-items:center;gap:8px;margin-top:18px;
  padding:8px 14px;border-radius:999px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);
  font-family:'Space Grotesk',sans-serif;font-size:.76rem;letter-spacing:.14em;font-weight:600;color:#9AA3C0;opacity:1}
#dlc-board .reset b{color:#7CE0FF;font-weight:800;font-family:'Space Grotesk',sans-serif;font-variant-numeric:tabular-nums}
#dlc-board .reward{position:relative;margin-top:18px;background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.14);border-radius:16px;padding:16px 18px;display:flex;align-items:center;gap:14px;text-align:left}
#dlc-board .reward .cup{font-size:2.2rem;filter:drop-shadow(0 4px 12px rgba(246,197,96,.5))}
#dlc-board .reward .r-lab{font-family:'Inter',sans-serif;font-size:.66rem;letter-spacing:.16em;font-weight:700;
  color:#C9B8FF;text-transform:uppercase;opacity:1}
#dlc-board .reward .r-big{font-family:'Sora',sans-serif;font-size:1.6rem;font-weight:800;margin:3px 0;color:#fff}
#dlc-board .reward .r-sub{font-size:.82rem;color:#C6CFE0;opacity:1}
#dlc-board .score-rule{position:relative;margin:11px auto 0;padding:7px 11px;border-radius:999px;width:max-content;max-width:100%;
  background:rgba(44,229,224,.08);border:1px solid rgba(44,229,224,.18);color:#BFD5E2;
  font-family:'Space Grotesk',sans-serif;font-size:.69rem;font-weight:650;letter-spacing:.02em}
#dlc-board .you{margin-top:16px;background:linear-gradient(180deg,#141B29,#10151F);border:1px solid rgba(255,255,255,.10);
  border-radius:18px;padding:16px 18px;display:flex;align-items:center;gap:14px;box-shadow:0 20px 44px -30px rgba(0,0,0,.85)}
#dlc-board .you .badge-rank{width:50px;height:50px;border-radius:13px;display:grid;place-items:center;
  background:rgba(44,229,224,.12);border:1px solid rgba(44,229,224,.30);color:#35E0E0;
  font-family:'Space Grotesk',sans-serif;font-weight:800;font-size:1.05rem}
#dlc-board .you .y-lab{font-size:.74rem;color:#8592AB;letter-spacing:.04em}
#dlc-board .you .y-name{font-weight:700;font-size:1.02rem;color:#EDF1F8}
#dlc-board .you .y-name a{color:#35E0E0;font-weight:700}
#dlc-board .you .y-stats{margin-top:5px;font-family:'Space Grotesk',sans-serif;font-size:.76rem;color:#AEB9CC}
#dlc-board .lbl{display:flex;align-items:center;gap:12px;font-family:'Sora',sans-serif;font-weight:700;
  letter-spacing:.08em;margin:26px 4px 14px;font-size:.86rem;color:#8592AB;text-transform:uppercase}
#dlc-board .lbl::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,rgba(255,255,255,.14),transparent)}
#dlc-board .row{background:linear-gradient(180deg,#141B29,#10151F);border:1px solid rgba(255,255,255,.07);
  border-radius:16px;padding:13px 15px;margin-bottom:11px;display:flex;align-items:center;gap:14px;
  box-shadow:0 16px 40px -32px rgba(0,0,0,.9)}
#dlc-board .rank{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;
  font-family:'Space Grotesk',sans-serif;font-weight:800;font-size:1rem;flex-shrink:0;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:#C6CFE0}
#dlc-board .name{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:1rem;color:#EDF1F8;flex:1;
  min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#dlc-board .lock{display:flex;align-items:center;gap:6px;color:#6F7A92;font-weight:600;font-size:.86rem;flex-shrink:0}
#dlc-board .login{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;
  padding:18px;text-align:center;color:#C6CFE0}
#dlc-board .foot{margin-top:22px;text-align:center;color:#8592AB;font-size:.82rem;line-height:1.7}
#dlc-board .foot a{color:#35E0E0;font-weight:700;text-decoration:none}
#dlc-board .podium{display:flex;align-items:flex-end;gap:10px;margin:2px 0 16px}
#dlc-board .pod{--pc:#C7D2E4;flex:1;min-width:0;text-align:center;position:relative;border-radius:18px;
  background:linear-gradient(180deg,#161E2E,#10151F);border:1px solid rgba(255,255,255,.09);
  padding:14px 8px 12px;opacity:0;transform:translateY(14px);animation:dlcrise .5s forwards;overflow:hidden}
#dlc-board .pod::after{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:var(--pc)}
#dlc-board .pod-1{--pc:#F6C560;flex:1.18;padding:18px 8px 22px;border-color:rgba(246,197,96,.45);
  box-shadow:0 18px 44px -24px rgba(246,197,96,.45)}
#dlc-board .pod-2{--pc:#C7D2E4}
#dlc-board .pod-3{--pc:#CE9A6A}
#dlc-board .pod-medal{font-size:1.8rem;line-height:1}
#dlc-board .pod-1 .pod-medal{font-size:2.1rem;animation:dlcCrown 3.2s ease-in-out infinite}
@keyframes dlcCrown{50%{transform:translateY(-4px)}}
#dlc-board .pod-ava{width:50px;height:50px;border-radius:50%;margin:9px auto;display:grid;place-items:center;
  font-family:'Space Grotesk',sans-serif;font-weight:800;font-size:1rem;color:#fff;
  background:rgba(255,255,255,.06);border:2px solid var(--pc);box-shadow:0 0 20px -6px var(--pc)}
#dlc-board .pod-1 .pod-ava{width:58px;height:58px;font-size:1.15rem}
#dlc-board .pod-name{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:.78rem;color:#EDF1F8;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 2px}
#dlc-board .pod-rank{font-family:'Space Grotesk',sans-serif;font-size:.64rem;letter-spacing:.14em;color:#8592AB;margin-top:3px}
#dlc-board .pod-bar{height:7px;margin-top:10px;border-radius:6px;background:rgba(255,255,255,.07);overflow:hidden}
#dlc-board .pod-bar i{display:block;height:100%;width:0;border-radius:6px;background:var(--pc);
  box-shadow:0 0 10px var(--pc);transition:width 1s cubic-bezier(.22,1,.36,1)}
#dlc-board .pod-prize{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:.72rem;color:var(--pc);margin-top:5px}
#dlc-board .pod-score{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:.72rem;color:#AEB9CC;margin-top:5px}
#dlc-board .row-score{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:.8rem;color:#AEB9CC;white-space:nowrap}
#dlc-board .heat{height:5px;margin-top:7px;border-radius:6px;background:rgba(255,255,255,.06);overflow:hidden;max-width:180px}
#dlc-board .heat i{display:block;height:100%;width:0;border-radius:6px;
  background:linear-gradient(90deg,#4C8DFF,#35E0E0);box-shadow:0 0 8px rgba(53,224,224,.6);
  transition:width 1s cubic-bezier(.22,1,.36,1)}
@media(prefers-reduced-motion:reduce){#dlc-board .hero::before{animation:none}#dlc-board .pod{animation:none;opacity:1;transform:none}#dlc-board .pod-1 .pod-medal{animation:none}#dlc-board .pod-bar i,#dlc-board .heat i{transition:none}}
</style>
CSS;
}

/* =========================================================================
   SHORTCODE 1 — REWARDS PROGRAM  [delicat_rewards]
   ========================================================================= */
/* 3.3.0: [delicat_rewards] opens the Bonus Free Fire page on « Mes bonus » (bonus-page.php). */

/* =========================================================================
   SHORTCODE 2 — MEILLEUR CLIENT (leaderboard)  [delicat_meilleur_client]
   ========================================================================= */
/* 3.3.0: [delicat_meilleur_client] opens the Bonus Free Fire page on « Classement » (bonus-page.php). */


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
add_action( 'rest_api_init', function () {
	register_rest_route( 'delicat-license/v1', '/validate', array(
		'methods' => 'POST', 'permission_callback' => '__return_true',
		'callback' => function( WP_REST_Request $req ) {
			$key = preg_replace( '/[^A-Z0-9-]/', '', strtoupper( (string) $req->get_param( 'license_key' ) ) );
			/* A client that sends a nonce gets it back inside the signed reply, so a recorded reply
			   cannot be replayed to it; older clients that send none get the same reply as before. */
			$client_nonce = preg_replace( '/[^A-Za-z0-9_-]/', '', substr( (string) $req->get_param( 'nonce' ), 0, 64 ) );
			$domain = delicat_license_normalize_domain( $req->get_param( 'domain' ) );
			if ( strlen( $key ) < 20 || ! $domain ) return new WP_REST_Response( array( 'valid'=>false, 'code'=>'invalid_request' ), 400 );
			$ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
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
add_action( 'admin_menu', function(){ add_submenu_page('woocommerce','Rewards Licences','Rewards Licences',delicat_cap(),'delicat-rewards-licenses','delicat_license_admin_page'); }, 40 );
add_action( 'admin_post_delicat_license_create', function(){
	delicat_guard('delicat_license_create');
	if ( ! delicat_license_lock( 5 ) ) wp_die( 'Le registre des licences est occupé. Réessayez.', 'Delicat Rewards', array( 'response' => 503 ) );
	try {
		$raw=strtoupper(wp_generate_password(32,false,false)); $key='DLR-'.substr($raw,0,8).'-'.substr($raw,8,8).'-'.substr($raw,16,8).'-'.substr($raw,24,8); $h=hash('sha256',$key);
		$expires=sanitize_text_field(wp_unslash($_POST['expires']??'')); if($expires && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$expires)) $expires='';
		$note=sanitize_text_field(wp_unslash($_POST['note']??'')); $all=delicat_license_records(); $all[$h]=array('last4'=>substr($key,-4),'active'=>1,'domain'=>'','expires'=>$expires,'note'=>$note,'created'=>current_time('mysql')); delicat_license_save_records($all);
	} finally { delicat_license_unlock(); }
	set_transient('delicat_new_license_'.get_current_user_id(),$key,120); wp_safe_redirect(admin_url('admin.php?page=delicat-rewards-licenses')); exit;
});
add_action( 'admin_post_delicat_license_action', function(){
	delicat_guard('delicat_license_action');
	$h=preg_replace('/[^a-f0-9]/','',strtolower((string)($_POST['hash']??''))); $do=sanitize_key($_POST['do']??'');
	if ( ! delicat_license_lock( 5 ) ) wp_die( 'Le registre des licences est occupé. Réessayez.', 'Delicat Rewards', array( 'response' => 503 ) );
	try {
		$all=delicat_license_records(); if(isset($all[$h])){ if($do==='toggle')$all[$h]['active']=empty($all[$h]['active'])?1:0; elseif($do==='unbind'){ $all[$h]['domain']=''; unset($all[$h]['activated_at']); } elseif($do==='delete')unset($all[$h]); delicat_license_save_records($all);}
	} finally { delicat_license_unlock(); }
	wp_safe_redirect(admin_url('admin.php?page=delicat-rewards-licenses')); exit;
});
function delicat_license_admin_page(){
	if(!current_user_can(delicat_cap()))wp_die('Accès refusé.'); $all=delicat_license_records(); $new=get_transient('delicat_new_license_'.get_current_user_id()); if($new)delete_transient('delicat_new_license_'.get_current_user_id());
	echo '<div class="wrap"><h1>Delicat Rewards — Licences</h1><p>URL du serveur à saisir dans le Client : <code>'.esc_html(home_url()).'</code></p>';
	if($new)echo '<div class="notice notice-success"><p><b>Nouvelle clé — copiez-la maintenant :</b><br><code style="font-size:16px">'.esc_html($new).'</code></p></div>';
	echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="background:#fff;padding:16px;max-width:800px">'; wp_nonce_field('delicat_license_create'); echo '<input type="hidden" name="action" value="delicat_license_create"><label>Expiration <input type="date" name="expires"></label> &nbsp; <label>Note <input type="text" name="note" maxlength="120"></label> <button class="button button-primary">Générer une licence</button></form><br>';
	echo '<table class="widefat striped"><thead><tr><th>Clé</th><th>État</th><th>Domaine</th><th>Expiration</th><th>Dernière vérification</th><th>Note</th><th>Actions</th></tr></thead><tbody>';
	foreach($all as $h=>$l){echo '<tr><td>•••• '.esc_html($l['last4']??'').'</td><td>'.(!empty($l['active'])?'Active':'Révoquée').'</td><td>'.esc_html($l['domain']??'—').'</td><td>'.esc_html($l['expires']??'—').'</td><td>'.esc_html($l['last_check']??'—').'</td><td>'.esc_html($l['note']??'').'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">';wp_nonce_field('delicat_license_action');echo '<input type="hidden" name="action" value="delicat_license_action"><input type="hidden" name="hash" value="'.esc_attr($h).'"><button class="button" name="do" value="toggle">'.(!empty($l['active'])?'Révoquer':'Réactiver').'</button> <button class="button" name="do" value="unbind">Délier</button> <button class="button" name="do" value="delete" onclick="return confirm(\'Supprimer cette licence ?\')">Supprimer</button></form></td></tr>';}
	if(!$all)echo '<tr><td colspan="7">Aucune licence.</td></tr>'; echo '</tbody></table></div>';
}

require_once __DIR__ . '/competition-admin.php';
require_once __DIR__ . '/app-contract.php';
require_once __DIR__ . '/referral.php';
require_once __DIR__ . '/referral-admin.php';
require_once __DIR__ . '/bonus-page.php';
