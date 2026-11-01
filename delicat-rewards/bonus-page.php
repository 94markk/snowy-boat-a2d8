<?php
/**
 * Bonus Free Fire (3.3.0): the weekly ranking (Meilleur Client) and the customer's bonuses on one
 * page, switched with two tabs. [delicat_bonus_free_fire] — and the two older shortcodes, which now
 * open the same page on their tab: [delicat_meilleur_client] on « Classement », [delicat_rewards]
 * on « Mes bonus ».
 *
 * Speed and safety: the HTML is the same for every visitor (no nonce, no account id, no clock), so
 * a page cache may keep it. Everything personal comes from one no-store request
 * (delicat_bonus_boot) that runs the plugin's own REST routes internally, with their permission
 * callbacks; claims keep their own route, signed token, ledger and locks.
 */
defined( 'ABSPATH' ) || exit;

const DELICAT_BONUS_SHORTCODES = array( 'delicat_bonus_free_fire', 'delicat_meilleur_client', 'delicat_rewards' );

function delicat_bonus_asset_url( $file ) {
	$path = __DIR__ . '/assets/' . $file;
	return plugins_url( 'assets/' . $file, __FILE__ ) . '?ver=' . ( is_readable( $path ) ? (string) filemtime( $path ) : DELICAT_REWARDS_VERSION );
}

/* The page's two files load in the head, and only on a page that shows it. */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) return;
	$post = get_post();
	if ( ! $post ) return;
	foreach ( DELICAT_BONUS_SHORTCODES as $tag ) {
		if ( has_shortcode( (string) $post->post_content, $tag ) ) { delicat_bonus_enqueue(); return; }
	}
} );
function delicat_bonus_enqueue() {
	wp_enqueue_style( 'delicat-bonus', delicat_bonus_asset_url( 'bonus.css' ), array(), null );
	wp_enqueue_script( 'delicat-bonus', delicat_bonus_asset_url( 'bonus.js' ), array(), null, array( 'in_footer' => true, 'strategy' => 'defer' ) );
}

/** Static page settings: texts, tiers, prizes. Nothing about the visitor. */
function delicat_bonus_static_config() {
	$c = json_decode( delicat_config_json(), true );
	foreach ( array( 'nonce', 'userId', 'loggedIn', 'nextReset', 'serverNow' ) as $private ) unset( $c[ $private ] ); // per visitor or per second: from the boot request
	$c['boot']       = admin_url( 'admin-ajax.php' );
	$c['rewardsOn']  = delicat_rewards_on();
	$c['meilleurOn'] = delicat_meilleur_on();
	$c['redeemUrl']  = esc_url_raw( (string) apply_filters( 'delicat_redeem_url', home_url( '/free-fire-redeem/' ) ) );
	return $c;
}

/** Material Symbols Rounded 400 (Apache License 2.0), the three drawn before the script runs. */
function delicat_bonus_icon( $name ) {
	$icons = array(
		'trophy'  => 'M450-180v-148q-54-11-96-46t-58-89q-74-8-125-60t-51-125v-44q0-25 18-42 17-18 42-18h104v-28q0-25 18-42 17-18 42-18h272q25 0 42 18 18 17 18 42v28h104q25 0 42 18 18 17 18 42v44q0 73-51 125t-125 60q-16 53-58 89t-96 46v148h122q13 0 21 9 9 8 9 21t-9 22-21 8H328q-13 0-21-9-9-8-9-21t9-22 21-8zM284-526v-166H180v44q0 45 30 79t74 43m293 101q39-40 39-97v-258H344v258q0 57 40 97 39 40 96 40t97-40m99-101q45-10 75-43t29-79v-44H676zm-196-57',
		'redeem'  => 'M140-277v97h680v-97zm0-443h125q-5-9-8-22t-3-25q0-47 33-80t79-33q31 0 57 16t41 39l16 26 17-26q16-25 41-40t54-15q48 0 81 33t33 80l-2 22q-2 11-8 25h124q24 0 42 18t18 42v480q0 24-18 42t-42 18H140q-24 0-42-18t-18-42v-480q0-24 18-42t42-18m0 336h680v-276H571l83 115q8 10 6 22t-13 20-22 6-20-13L480-683 355-510q-8 11-19 13t-23-6q-10-8-12-20t5-22l83-115H140zm265-345q15-16 15-38t-15-37-38-16-37 16-16 37 16 38 37 15 38-15m187 15q23 0 39-15t15-38-15-37-39-16q-21 0-36 16t-16 37 16 38 36 15',
		'group_add' => 'M474-486q26-32 39-66t12-79-12-79-39-66q76-17 134 23t57 122-57 122-134 23m202 326 10-29q5-14 4-31v-34q0-51-26-95t-90-74q173 22 237 64t63 105v34q0 25-18 42-17 18-42 18zm124-389h-70q-13 0-21-9-9-8-9-21t9-21q8-9 21-9h70v-70q0-13 9-21 8-9 21-9t21 9q9 8 9 21v70h70q13 0 21 9 9 8 9 21t-9 21q-8 9-21 9h-70v70q0 13-9 21-8 9-21 9t-21-9q-9-8-9-21zm-593 26q-42-42-42-108t42-108 108-42 108 42 42 108-42 108-108 42-108-42M0-220v-34q0-35 19-63t49-43q72-32 129-46t118-14 118 14 128 46q31 14 50 43t19 63v34q0 25-18 42-17 18-42 18H60q-25 0-42-18-18-17-18-42m315-321q39 0 65-25t25-65-25-64-65-26-64 26-26 64 26 65 64 25M60-220h510v-34q0-16-8-30t-25-22q-69-32-117-43t-105-11-104 11-119 43q-15 7-23 22t-9 30zm255 0',
		'refresh' => 'M480-160q-133 0-226-93t-94-227 94-226 226-94q85 0 149 35t111 94v-99q0-13 9-21t21-9 22 9 8 21v194q0 13-8 22t-22 8H576q-13 0-21-8t-9-22 9-21 21-9h138q-38-60-97-97t-137-37q-109 0-184 76t-76 184 76 185 184 75q75 0 140-39t97-107q5-11 17-16t22-1q12 5 16 17t-1 23q-39 84-117 134t-174 49',
	);
	return isset( $icons[ $name ] ) ? '<svg class="dbf-i" viewBox="0 -960 960 960" aria-hidden="true" focusable="false"><path d="' . esc_attr( $icons[ $name ] ) . '"/></svg>' : '';
}

/**
 * @param array $atts tab: classement|bonus (first tab shown), title: heading ('' hides it).
 */
function delicat_bonus_page( $atts = array() ) {
	static $rendered = false;
	if ( $rendered ) return ''; // ids and dialogs exist once per page
	$rendered = true;
	$atts = shortcode_atts( array( 'tab' => 'classement', 'title' => 'Bonus Free Fire' ), (array) $atts, 'delicat_bonus_free_fire' );
	$rewards  = delicat_rewards_on();
	$meilleur = delicat_meilleur_on();
	$referral = function_exists( 'delicat_ref_on' ) && delicat_ref_on();
	if ( ! $rewards && ! $meilleur && ! $referral ) return delicat_paused_html();
	$open = array_keys( array_filter( array( 'classement' => $meilleur, 'bonus' => $rewards, 'parrainage' => $referral ) ) );
	$tab  = in_array( $atts['tab'], $open, true ) ? $atts['tab'] : $open[0];
	// The page's address, for the personal invitation links.
	if ( is_singular() ) {
		$here = (string) get_permalink();
		if ( '' !== $here && get_option( 'delicat_bonus_page_url' ) !== $here ) update_option( 'delicat_bonus_page_url', $here, false );
	}
	if ( ! wp_style_is( 'delicat-bonus', 'enqueued' ) ) delicat_bonus_enqueue(); // shortcode outside the post content (widget, template)
	$c     = delicat_bonus_static_config();
	$texts = (array) ( $c['texts'] ?? array() );
	$clean = function ( $v ) { return trim( (string) preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}]/u', '', (string) $v ) ); };
	$t     = function ( $k, $fallback ) use ( $texts, $clean ) { $v = $clean( $texts[ $k ] ?? '' ); return '' !== $v ? $v : $fallback; };
	$prize = (int) ( $c['prize'] ?? 0 );
	$title = (string) $atts['title'];
	$label = '' !== $title ? $title : 'Bonus Free Fire';
	ob_start();
	?>
<section class="dbf" id="dbf" data-dlc-dark-skip data-tab="<?php echo esc_attr( $tab ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" data-config="<?php echo esc_attr( wp_json_encode( $c ) ); /* an attribute, not a JSON <script>: Builder's instant navigation re-runs inline scripts; data-dlc-dark-skip: the page draws its own dark theme, Builder's dark-mode repaint leaves it alone */ ?>">
	<div class="dbf-top">
		<?php if ( '' !== $title ) : ?><h1 class="dbf-title"><?php echo esc_html( $title ); ?></h1><?php endif; ?>
		<button type="button" class="dbf-round" id="dbf-refresh" aria-label="Actualiser"><?php echo delicat_bonus_icon( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?></button>
	</div>
	<?php if ( count( $open ) > 1 ) : ?>
	<div class="dbf-tabs" role="tablist" aria-label="<?php echo esc_attr( $label ); ?>" style="--n:<?php echo (int) count( $open ); ?>">
		<?php if ( $meilleur ) : ?>
		<button type="button" role="tab" class="dbf-tab" id="dbf-tab-classement" aria-controls="dbf-panel-classement" aria-selected="<?php echo 'classement' === $tab ? 'true' : 'false'; ?>" tabindex="<?php echo 'classement' === $tab ? '0' : '-1'; ?>"><?php echo delicat_bonus_icon( 'trophy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Classement</span></button>
		<?php endif; if ( $rewards ) : ?>
		<button type="button" role="tab" class="dbf-tab" id="dbf-tab-bonus" aria-controls="dbf-panel-bonus" aria-selected="<?php echo 'bonus' === $tab ? 'true' : 'false'; ?>" tabindex="<?php echo 'bonus' === $tab ? '0' : '-1'; ?>"><?php echo delicat_bonus_icon( 'redeem' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Mes bonus</span></button>
		<?php endif; if ( $referral ) : ?>
		<button type="button" role="tab" class="dbf-tab" id="dbf-tab-parrainage" aria-controls="dbf-panel-parrainage" aria-selected="<?php echo 'parrainage' === $tab ? 'true' : 'false'; ?>" tabindex="<?php echo 'parrainage' === $tab ? '0' : '-1'; ?>"><?php echo delicat_bonus_icon( 'group_add' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Parrainage</span></button>
		<?php endif; ?>
	</div>
	<?php endif; ?>
	<div class="dbf-notice" id="dbf-notice" role="status" hidden></div>

	<?php if ( $meilleur ) : ?>
	<section class="dbf-panel" id="dbf-panel-classement" role="tabpanel" aria-labelledby="dbf-tab-classement" <?php echo 'classement' === $tab ? '' : 'hidden'; ?>>
		<div class="dbf-hero">
			<p class="dbf-kicker"><?php echo esc_html( $t( 'boardKicker', 'DELICAT STORE · KLASMAN SEMÈN' ) ); ?></p>
			<h2 class="dbf-hero-title"><?php echo esc_html( $t( 'boardTitle', 'MEILLEUR CLIENT' ) ); ?></h2>
			<p class="dbf-games"><?php echo esc_html( $t( 'boardGames', 'Free Fire' ) ); ?></p>
			<div class="dbf-glass"><span class="dbf-glass-i" data-icon="timer"></span><div><small><?php echo esc_html( $t( 'boardResetLabel', 'RESET NAN' ) ); ?></small><b id="dbf-board-clock">—</b></div></div>
			<div class="dbf-glass"><span class="dbf-glass-i" data-icon="trophy"></span><div><small><?php echo esc_html( $t( 'boardRewardLabel', 'RÉCOMPENSE · 1ÈRE PLACE' ) ); ?></small><b><?php echo $prize > 0 ? esc_html( number_format_i18n( $prize ) . ' diamants' ) : '—'; ?></b></div></div>
			<?php $sub = $t( 'boardRewardSub', '' ); if ( '' !== $sub ) : ?><p class="dbf-hero-sub"><?php echo esc_html( $sub ); ?></p><?php endif; ?>
		</div>
		<h2 class="dbf-h"><?php echo esc_html( $t( 'boardHeading', 'KLASMAN' ) ); ?></h2>
		<div class="dbf-board" id="dbf-board" aria-busy="true">
			<div class="dbf-podium dbf-skel" aria-hidden="true"><span></span><span></span><span></span></div>
		</div>
		<p class="dbf-rule">Classement : 70 % montant dépensé · 30 % commandes</p>
		<section class="dbf-winners" id="dbf-winners" aria-label="Les derniers gagnants" hidden></section>
		<div class="dbf-you" id="dbf-you" hidden>
			<span class="dbf-you-i" id="dbf-you-badge" data-icon="flag"></span>
			<div><small><?php echo esc_html( $t( 'boardYouLabel', 'Votre position cette semaine' ) ); ?></small><b id="dbf-you-text"></b></div>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $rewards ) : ?>
	<section class="dbf-panel" id="dbf-panel-bonus" role="tabpanel" aria-labelledby="dbf-tab-bonus" <?php echo 'bonus' === $tab ? '' : 'hidden'; ?>>
		<div class="dbf-hero dbf-hero--bonus">
			<p class="dbf-kicker"><?php echo esc_html( $clean( $texts['programName'] ?? '' ) ?: 'BONUS FREE FIRE' ); ?></p>
			<div class="dbf-next">
				<div class="dbf-ring" id="dbf-ring"><b id="dbf-ring-pct">—</b></div>
				<div><small>Votre prochain bonus</small><h2 class="dbf-hero-title" id="dbf-next-title">Chargement…</h2><p id="dbf-next-msg">Vérification de vos achats.</p></div>
			</div>
			<div class="dbf-stats">
				<div class="dbf-glass"><div><small>Achats Free Fire ce mois</small><b id="dbf-spend">—</b></div></div>
				<div class="dbf-glass"><div><small>Bonus reçus ce mois</small><b id="dbf-total">—</b></div></div>
			</div>
			<p class="dbf-hero-sub" id="dbf-month-clock"></p>
		</div>
		<div class="dbf-row-h"><h2 class="dbf-h">Vos bonus</h2><span class="dbf-chip" id="dbf-ready" hidden></span></div>
		<div class="dbf-tiers" id="dbf-tiers" aria-busy="true"><div class="dbf-card dbf-skel" aria-hidden="true"></div><div class="dbf-card dbf-skel" aria-hidden="true"></div></div>
		<div class="dbf-row-h"><h2 class="dbf-h">Mes PINs récompenses</h2><button type="button" class="dbf-btn dbf-btn--ghost" id="dbf-history-refresh">Actualiser</button></div>
		<div class="dbf-history" id="dbf-history" aria-live="polite"><p class="dbf-muted">Chargement…</p></div>
		<div class="dbf-pages" id="dbf-pages" hidden><button type="button" class="dbf-btn dbf-btn--ghost" id="dbf-prev">Précédent</button><span id="dbf-page"></span><button type="button" class="dbf-btn dbf-btn--ghost" id="dbf-next">Suivant</button></div>
		<details class="dbf-how"><summary>Comment ça marche ?</summary><ol><li>Achetez vos packs Free Fire et attendez la validation de vos commandes.</li><li>Quand l’objectif d’un pack est atteint, touchez « Réclamer ».</li><li>Copiez le PIN reçu, puis touchez « Utiliser mon PIN » pour le valider.</li></ol><?php $note = $clean( $texts['note'] ?? '' ); if ( '' !== $note ) : ?><p><?php echo esc_html( $note ); ?></p><?php endif; ?></details>
	</section>
	<?php endif; ?>

	<?php if ( $referral ) : $rc = delicat_ref_cfg(); $refmax = min( DELICAT_REF_MAX_POINTS, max( 1, (int) $rc['max_points'] ) ); ?>
	<section class="dbf-panel" id="dbf-panel-parrainage" role="tabpanel" aria-labelledby="dbf-tab-parrainage" <?php echo 'parrainage' === $tab ? '' : 'hidden'; ?>>
		<div class="dbf-hero dbf-hero--ref">
			<p class="dbf-kicker">Parrainage Delicat</p>
			<h2 class="dbf-hero-title">Invitez vos amis, gagnez des récompenses</h2>
			<p class="dbf-games">1 ami qui s’inscrit et fait son premier achat = 1 point. Jusqu’à <?php echo (int) $refmax; ?> points.</p>
			<div class="dbf-ref-score"><b id="dbf-ref-points">—</b><span>/ <?php echo (int) $refmax; ?> points</span></div>
			<div class="dbf-bar dbf-bar--hero" id="dbf-ref-meter" role="progressbar" aria-label="Points de parrainage" aria-valuemin="0" aria-valuemax="<?php echo (int) $refmax; ?>" aria-valuenow="0"><i id="dbf-ref-bar"></i></div>
			<div class="dbf-ref-track" id="dbf-ref-track" aria-hidden="true"></div>
			<p class="dbf-hero-sub" id="dbf-ref-next"></p>
		</div>
		<div class="dbf-ref-body" id="dbf-ref-body" aria-busy="true"><div class="dbf-card dbf-skel" aria-hidden="true"></div></div>
		<details class="dbf-how"><summary>Règles du parrainage</summary><ol>
			<li>Partagez votre code ou votre lien personnel.</li>
			<li>Votre ami crée son compte avec votre lien (ou tape votre code dans les <?php echo (int) $rc['code_days']; ?> jours suivant son inscription), puis paie un premier achat d’au moins <?php echo esc_html( number_format_i18n( (int) $rc['min_order'] ) ); ?> HTG<?php echo $rc['exclude_wallet'] ? ' par MonCash, NatCash ou un autre moyen de paiement (pas avec le crédit boutique)' : ''; ?> dans les <?php echo (int) $rc['order_days']; ?> jours.</li>
			<li>Le point est validé <?php echo (int) $rc['hold_days']; ?> jours après son achat terminé, s’il n’est ni remboursé ni annulé.</li>
			<li>Un compte par personne et par téléphone. Une invitation ne compte pas si le compte est ouvert sur le même téléphone, avec le même numéro ou la même adresse e-mail qu’un autre compte, ou avec un VPN. Plusieurs comptes sur un même téléphone sont retirés du programme de récompenses.</li>
			<li>Maximum <?php echo (int) $refmax; ?> points. Chaque palier se réclame une fois : diamants Free Fire ou crédit boutique, au choix.</li>
		</ol></details>
	</section>
	<?php endif; ?>

	<div class="dbf-login" id="dbf-login" hidden>
		<span class="dbf-login-i" data-icon="lock"></span>
		<h2>Espace réservé aux clients</h2>
		<p>Connectez-vous pour voir votre progression, votre place dans le classement et récupérer vos PINs.</p>
		<a class="dbf-btn" href="<?php echo esc_url( wp_login_url( (string) get_permalink() ) ); ?>" data-dl-open>Se connecter</a>
	</div>
	<?php if ( $referral ) : ?>
	<div class="dbf-card dbf-invite" id="dbf-invite" hidden>
		<span class="dbf-av dbf-av--lg" data-ref="initials">?</span>
		<p class="dbf-kicker">Invitation</p>
		<h2><span data-ref="name">Un ami</span> vous invite sur Delicat Store</h2>
		<p class="dbf-muted">Créez votre compte avec cette invitation, puis payez votre premier achat d’au moins <?php echo esc_html( number_format_i18n( (int) $rc['min_order'] ) ); ?> HTG : votre ami gagne un point de parrainage.</p>
		<div class="dbf-invite-actions">
			<a class="dbf-btn" href="<?php echo esc_url( delicat_ref_signup_url( (string) get_permalink() ) ); ?>" data-dl-open data-dl-open-register><span data-icon="person_add"></span>Créer mon compte</a>
			<a class="dbf-btn dbf-btn--ghost" href="<?php echo esc_url( wp_login_url( (string) get_permalink() ) ); ?>" data-dl-open>J’ai déjà un compte</a>
		</div>
	</div>
	<?php endif; ?>
</section>
<dialog class="dbf-dialog" id="dbf-dialog" data-dlc-dark-skip aria-labelledby="dbf-dialog-title" aria-describedby="dbf-dialog-msg">
	<div class="dbf-dialog-top"><span class="dbf-kicker">Votre récompense</span><button type="button" class="dbf-round dbf-round--sm" id="dbf-dialog-close" aria-label="Fermer"><span data-icon="close"></span></button></div>
	<div class="dbf-win" id="dbf-win" hidden><span data-icon="celebration"></span></div>
	<h2 id="dbf-dialog-title">Votre PIN</h2>
	<p id="dbf-dialog-msg" role="status"></p>
	<div id="dbf-pins"></div>
	<div class="dbf-dialog-actions"><button type="button" class="dbf-btn dbf-btn--ghost" id="dbf-pin-refresh">Actualiser le PIN</button><a class="dbf-btn" id="dbf-redeem" href="<?php echo esc_url( $c['redeemUrl'] ); ?>" rel="noreferrer">Utiliser mon PIN</a></div>
	<p class="dbf-muted dbf-small">Votre PIN est personnel. Copiez-le avant d’ouvrir la page de validation.</p>
</dialog>
<div class="dbf-toast" id="dbf-toast" data-dlc-dark-skip role="status" aria-live="polite" hidden></div>
<script>/* The page's data is asked for while the rest of the page still downloads; bonus.js picks the answer up. */(function(){try{window.DBF_BOOT=fetch(<?php echo wp_json_encode( $c['boot'] ); ?>,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=delicat_bonus_boot'}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});});}catch(e){}})();</script>
	<?php
	return (string) ob_get_clean();
}

add_shortcode( 'delicat_bonus_free_fire', 'delicat_bonus_page' );
add_shortcode( 'delicat_meilleur_client', function ( $atts = array() ) {
	return delicat_bonus_page( array_merge( array( 'tab' => 'classement' ), (array) $atts ) );
} );
add_shortcode( 'delicat_rewards', function ( $atts = array() ) {
	return delicat_bonus_page( array_merge( array( 'tab' => 'bonus' ), (array) $atts ) );
} );

/**
 * One request for everything personal: session (REST nonce), « Mes bonus » state and the board.
 * Internal REST dispatch keeps the routes' permission callbacks; the answer is never cached.
 */
function delicat_bonus_boot() {
	nocache_headers();
	if ( ! headers_sent() ) header( 'Cache-Control: no-store, private, max-age=0' );
	$uid  = get_current_user_id();
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
	$key  = 'delicat_bb_' . ( $uid ? 'u' . $uid : md5( $ip ) );
	$hits = (int) get_transient( $key );
	if ( $hits >= ( $uid ? 30 : 60 ) ) wp_send_json( array( 'code' => 'rate_limited', 'message' => 'Trop de demandes. Réessayez dans une minute.' ), 429 );
	set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );

	$logged = $uid > 0 && (bool) get_userdata( $uid );
	$out = array(
		'loggedIn'  => $logged,
		'userId'    => $logged ? $uid : 0,
		'nonce'     => $logged ? wp_create_nonce( 'wp_rest' ) : '',
		'serverNow' => time(),
		'nextReset' => delicat_next_week_start(),
	);
	$get = function ( $path ) {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/delicat/v1' . $path ) );
		$data     = $response->get_data();
		if ( $response->is_error() || $response->get_status() >= 400 ) {
			return array( 'error' => is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : 'Indisponible pour le moment.', 'status' => (int) $response->get_status() );
		}
		return $data;
	};
	if ( delicat_rewards_on() && $logged ) $out['rewards'] = $get( '/rewards' );
	if ( function_exists( 'delicat_ref_on' ) && delicat_ref_on() && $logged ) {
		delicat_ref_capture( $uid, '', true ); // this browser and network, for the duplicate-account checks
		$out['referral'] = $get( '/referral' );
	}
	if ( delicat_meilleur_on() && ( $logged || (int) delicat_cfg( 'board_public' ) === 1 ) ) {
		$out['board']   = $get( '/leaderboard' );
		$out['winners'] = array();
		if ( function_exists( 'delicat_recent_winners' ) ) {
			foreach ( delicat_recent_winners( 5 ) as $w ) {
				$out['winners'][] = array( 'name' => delicat_display_name( $w['uid'], true ), 'end' => (int) $w['end'], 'prize' => (int) $w['prize'] ); // masked, no id
			}
		}
	}
	wp_send_json( $out );
}
add_action( 'wp_ajax_delicat_bonus_boot', 'delicat_bonus_boot' );
add_action( 'wp_ajax_nopriv_delicat_bonus_boot', 'delicat_bonus_boot' );
