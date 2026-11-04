<?php
/**
 * Delicat Resellers — the customer's side: page [delicat_revendeur] (levels, application, its progress,
 * the month's deposits), the form handlers, and the badge card on the account home.
 * Personal views carry data-user-id so Builder's shared fragment cache never stores them.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/icons.php';

const DLR_TIER_ICON = array( 'premium' => 'workspace_premium', 'gold' => 'military_tech', 'silver' => 'verified' );

function dlr_assets() {
	if ( wp_style_is( 'delicat-resellers', 'enqueued' ) ) return;
	wp_enqueue_style( 'delicat-resellers', DLR_URL . 'assets/reseller.css', array(), DLR_VERSION );
}
add_action( 'wp_enqueue_scripts', function () {
	$post = get_post();
	if ( ( $post && has_shortcode( (string) $post->post_content, 'delicat_revendeur' ) ) || ( is_user_logged_in() && function_exists( 'is_account_page' ) && is_account_page() ) ) dlr_assets();
}, 20 );

function dlr_badge( $tier, $size = '' ) {
	if ( '' === $tier ) return '';
	return '<span class="dlr-badge dlr-t-' . esc_attr( $tier ) . ( $size ? ' dlr-badge--' . esc_attr( $size ) : '' ) . '">' . dlr_icon( DLR_TIER_ICON[ $tier ] ) . '<span>' . esc_html( dlr_tier_label( $tier ) ) . '</span></span>';
}
function dlr_bar( $value, $goal, $label ) {
	$pct = $goal > 0 ? max( 0, min( 100, (int) floor( $value / $goal * 100 ) ) ) : 0;
	return '<div class="dlr-bar" role="progressbar" aria-label="' . esc_attr( $label ) . '" aria-valuemin="0" aria-valuemax="' . (int) $goal . '" aria-valuenow="' . (int) min( $value, $goal ) . '"><i style="width:' . $pct . '%"></i></div>';
}
/**
 * The result of the last form. The page address says which (?dlr=sent|err|cancelled), so every load of
 * that address shows it (Builder may load a page twice: a visible load and a background refresh); an
 * error's message and the typed values stay stored for a few minutes.
 */
function dlr_flash( $user_id, $data = null ) {
	$k = 'dlr_flash_' . (int) $user_id;
	if ( null !== $data ) { set_transient( $k, $data, 10 * MINUTE_IN_SECONDS ); return null; }
	$state = isset( $_GET['dlr'] ) ? sanitize_key( wp_unslash( $_GET['dlr'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
	if ( 'sent' === $state ) return array( 'type' => 'ok', 'msg' => 'Demande envoyée. L’équipe l’examine et vous répond par e-mail.' );
	if ( 'cancelled' === $state ) return array( 'type' => 'ok', 'msg' => 'Votre demande est annulée.' );
	if ( 'err' !== $state ) return array();
	$v = get_transient( $k );
	return is_array( $v ) ? $v : array();
}
function dlr_login_url() {
	return wp_login_url( dlr_page_url() );
}

/* ============================================================== the page */

add_shortcode( 'delicat_revendeur', function () {
	dlr_assets();
	$uid  = get_current_user_id();
	$out  = '<section class="dlr" id="dlr" data-dlc-dark-skip' . ( $uid ? ' data-user-id="' . (int) $uid . '"' : '' ) . '>';
	$out .= '<header class="dlr-hero"><p class="dlr-kicker">Delicat Store</p><h1 class="dlr-title">Programme Revendeur</h1><p>Achetez à prix revendeur et revendez à vos clients. Trois niveaux, selon votre dépôt de chaque mois.</p></header>';
	if ( ! dlr_on() ) {
		return $out . '<p class="dlr-card dlr-note">' . dlr_icon( 'schedule' ) . '<span>Le programme revendeur ouvre bientôt.</span></p></section>';
	}
	if ( $uid ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true ); // a personal page
		do_action( 'litespeed_control_set_nocache', 'Delicat Resellers: personal page' );
		$flash = dlr_flash( $uid );
		if ( ! empty( $flash['msg'] ) ) $out .= '<p class="dlr-flash dlr-flash--' . ( 'ok' === ( $flash['type'] ?? '' ) ? 'ok' : 'err' ) . '" role="status">' . esc_html( $flash['msg'] ) . '</p>';
		$tier = dlr_user_tier( $uid );
		$open = dlr_open_app( $uid );
		if ( $open && 'deposit' === $open->status ) { dlr_check_app( $open ); $open = dlr_open_app( $uid ); $tier = dlr_user_tier( $uid, true ); }
		if ( '' !== $tier ) $out .= dlr_view_member( $uid, $tier );
		if ( $open ) $out .= dlr_view_application( $open );
		elseif ( '' === $tier ) {
			$last = dlr_last_app( $uid );
			if ( $last && in_array( $last->status, array( 'rejected', 'declined', 'expired' ), true ) ) $out .= dlr_view_ended( $last );
		}
		$out .= dlr_view_levels( $tier );
		if ( ! $open && 'premium' !== $tier ) $out .= dlr_view_form( $tier, $flash['old'] ?? array() );
	} else {
		$out .= dlr_view_levels( '' );
		$out .= '<div class="dlr-card dlr-login">' . dlr_icon( 'lock' ) . '<div><h2>Devenez revendeur</h2><p>Connectez-vous à votre compte Delicat pour faire votre demande.</p></div><a class="dlr-btn" href="' . esc_url( dlr_login_url() ) . '" data-dl-open>Se connecter</a></div>';
	}
	$out .= dlr_view_rules();
	return $out . '</section>';
} );

function dlr_view_member( $uid, $tier ) {
	$p     = dlr_month_progress( $uid );
	$since = (int) get_user_meta( $uid, '_dlr_since', true );
	$left  = max( 0, (int) ceil( ( $p['end'] - time() ) / DAY_IN_SECONDS ) );
	if ( $p['settled'] ) $msg = 'Votre dépôt d’activation compte pour ce mois-ci. Le minimum s’applique dès le mois prochain.';
	elseif ( $p['paid'] >= $p['min'] ) $msg = 'Minimum atteint : votre niveau est garanti pour le mois prochain.';
	else {
		$next = $p['keeps'];
		$msg  = 'Il manque ' . dlr_money( $p['min'] - $p['paid'] ) . ' avant la fin du mois pour garder votre niveau. ' . ( '' === $next ? 'Sinon, le programme s’arrête.' : 'Sinon, vous passez ' . dlr_tier_label( $next ) . '.' );
	}
	$h  = '<div class="dlr-card dlr-me dlr-t-' . esc_attr( $tier ) . '">';
	$h .= '<div class="dlr-me-top">' . dlr_badge( $tier, 'lg' ) . ( $since ? '<small>Revendeur depuis le ' . esc_html( wp_date( 'j F Y', $since ) ) . '</small>' : '' ) . '</div>';
	$h .= '<div class="dlr-month"><div class="dlr-month-row"><span>Dépôts de ' . esc_html( wp_date( 'F', time() ) ) . '</span><b>' . esc_html( dlr_money( $p['paid'] ) ) . ' <small>/ ' . esc_html( dlr_money( $p['min'] ) ) . '</small></b></div>';
	$h .= dlr_bar( $p['paid'], $p['min'], 'Dépôts du mois' );
	$h .= '<p class="dlr-small">' . dlr_icon( 'schedule' ) . '<span>' . esc_html( $msg ) . ' Fin du mois dans ' . (int) $left . ' jour' . ( $left > 1 ? 's' : '' ) . '.</span></p></div>';
	$h .= '<div class="dlr-actions"><a class="dlr-btn" href="' . esc_url( dlr_recharge_url() ) . '">' . dlr_icon( 'account_balance_wallet' ) . 'Recharger mon compte</a></div>';
	$h .= '<p class="dlr-small dlr-muted">Vos prix revendeur s’affichent sur tout le site quand vous êtes connecté (mention « Prix ' . esc_html( dlr_tier_label( $tier ) ) . ' »).</p>';
	return $h . '</div>';
}

function dlr_steps( $current ) {
	$steps = array( 1 => 'Demande envoyée', 2 => 'Examen par l’équipe', 3 => 'Dépôt', 4 => 'Badge et prix revendeur' );
	$h = '<ol class="dlr-steps">';
	foreach ( $steps as $n => $label ) {
		$state = $n < $current ? 'done' : ( $n === $current ? 'now' : 'next' );
		$h .= '<li class="is-' . $state . '"' . ( 'now' === $state ? ' aria-current="step"' : '' ) . '><span class="dlr-step-dot">' . ( 'done' === $state ? dlr_icon( 'check_circle' ) : (int) $n ) . '</span><span>' . esc_html( $label ) . '</span></li>';
	}
	return $h . '</ol>';
}

function dlr_view_application( $app ) {
	$h = '<div class="dlr-card dlr-app"><div class="dlr-app-head"><h2>Votre demande ' . esc_html( dlr_tier_label( $app->tier ) ) . '</h2>' . dlr_badge( $app->tier ) . '</div>';
	if ( 'pending' === $app->status ) {
		$h .= dlr_steps( 2 );
		$h .= '<p>' . dlr_icon( 'hourglass_top' ) . '<span>Envoyée le ' . esc_html( wp_date( 'j F Y', dlr_ts( $app->created_at ) ) ) . '. L’équipe l’examine et vous répond par e-mail.</span></p>';
	} else {
		$min  = dlr_tier_min( $app->tier );
		$paid = dlr_app_paid( $app );
		$h .= dlr_steps( 3 );
		if ( time() > dlr_ts( $app->deadline ) ) { // still open after the deadline: a recharge sent in time is being checked
			$h .= '<p class="dlr-strong">' . dlr_icon( 'hourglass_top' ) . '<span>Le délai est passé. Une recharge envoyée avant le ' . esc_html( wp_date( 'j F Y à H:i', dlr_ts( $app->deadline ) ) ) . ' est en vérification : elle compte dès que l’équipe la valide.</span></p>';
		} else {
			$h .= '<p class="dlr-strong">Approuvée. Dernière étape : rechargez au moins ' . esc_html( dlr_money( $min ) ) . ' avant le ' . esc_html( wp_date( 'j F Y à H:i', dlr_ts( $app->deadline ) ) ) . '.</p>';
		}
		$h .= '<div class="dlr-month-row"><span>Déjà rechargé</span><b>' . esc_html( dlr_money( $paid ) ) . ' <small>/ ' . esc_html( dlr_money( $min ) ) . '</small></b></div>' . dlr_bar( $paid, $min, 'Dépôt de qualification' );
		$h .= '<p class="dlr-warn" role="note">' . dlr_icon( 'info' ) . '<span>Uniquement par le système de recharge (MonCash, NatCash…). Un envoi d’argent d’un autre portefeuille Delicat vers le vôtre pendant cette étape refuse la demande automatiquement.</span></p>';
		$h .= '<div class="dlr-actions"><a class="dlr-btn" href="' . esc_url( dlr_recharge_url() ) . '">' . dlr_icon( 'account_balance_wallet' ) . 'Recharger mon compte</a></div>';
	}
	$h .= '<details class="dlr-cancel"><summary>Annuler ma demande</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dlr_cancel">' . wp_nonce_field( 'dlr_cancel', '_wpnonce', true, false ) . '<button class="dlr-btn dlr-btn--ghost" type="submit">Oui, annuler la demande</button></form></details>';
	return $h . '</div>';
}

function dlr_view_ended( $app ) {
	$why = array(
		'rejected' => 'Votre dernière demande n’a pas été retenue par l’équipe.',
		'declined' => 'Votre dernière demande a été refusée : de l’argent est arrivé d’un autre portefeuille pendant l’étape du dépôt. Le dépôt doit être fait uniquement par le système de recharge.',
		'expired'  => 'Votre dernière demande a expiré : le dépôt n’a pas été complété à temps.',
	);
	return '<p class="dlr-card dlr-note">' . dlr_icon( 'block' ) . '<span>' . esc_html( $why[ $app->status ] ?? '' ) . ' Vous pouvez faire une nouvelle demande ci-dessous.</span></p>';
}

function dlr_view_levels( $current ) {
	$h = '<h2 class="dlr-h">Les niveaux</h2><div class="dlr-levels">';
	foreach ( dlr_tiers() as $t => $c ) {
		$h .= '<article class="dlr-card dlr-level dlr-t-' . esc_attr( $t ) . ( $t === $current ? ' is-current' : '' ) . '">' . dlr_badge( $t );
		$h .= '<p class="dlr-level-min"><b>' . esc_html( dlr_money( $c['min'] ) ) . '</b><span>de dépôt minimum par mois</span></p>';
		$h .= '<p class="dlr-small dlr-muted">Prix ' . esc_html( $c['label'] ) . ' sur les produits du site' . ( $c['discount'] > 0 ? ' (au moins −' . esc_html( rtrim( rtrim( number_format_i18n( $c['discount'], 1 ), '0' ), ',.' ) ) . ' %)' : '' ) . '.</p>';
		if ( $t === $current ) $h .= '<span class="dlr-current">' . dlr_icon( 'check_circle' ) . 'Votre niveau</span>';
		$h .= '</article>';
	}
	return $h . '</div>';
}

function dlr_view_form( $current, array $old ) {
	$choices = array_filter( dlr_tier_keys(), function ( $t ) use ( $current ) { return dlr_tier_rank( $t ) > dlr_tier_rank( $current ); } );
	if ( ! $choices ) return '';
	$v = function ( $k ) use ( $old ) { return esc_attr( (string) ( $old[ $k ] ?? '' ) ); };
	$picked = $old['tier'] ?? ( '' === $current ? 'silver' : end( $choices ) );
	$h  = '<form class="dlr-card dlr-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	$h .= '<h2>' . ( '' === $current ? 'Faire ma demande' : 'Passer au niveau supérieur' ) . '</h2>';
	$h .= '<input type="hidden" name="action" value="dlr_apply">' . wp_nonce_field( 'dlr_apply', '_wpnonce', true, false );
	$h .= '<fieldset class="dlr-pick"><legend>Niveau souhaité</legend>';
	foreach ( $choices as $t ) {
		$h .= '<label class="dlr-choice dlr-t-' . esc_attr( $t ) . '"><input type="radio" name="tier" value="' . esc_attr( $t ) . '"' . checked( $picked, $t, false ) . ' required>' . dlr_icon( DLR_TIER_ICON[ $t ] ) . '<span><b>' . esc_html( dlr_tier_label( $t ) ) . '</b><small>' . esc_html( dlr_money( dlr_tier_min( $t ) ) ) . ' / mois</small></span></label>';
	}
	$h .= '</fieldset>';
	$h .= '<label class="dlr-field"><span>Nom ou nom de votre activité</span><input name="business" maxlength="120" required autocomplete="organization" value="' . $v( 'business' ) . '"></label>';
	$h .= '<div class="dlr-two"><label class="dlr-field"><span>Téléphone WhatsApp</span><input name="phone" type="tel" maxlength="25" required autocomplete="tel" placeholder="+509 …" value="' . $v( 'phone' ) . '"></label>';
	$h .= '<label class="dlr-field"><span>Ville</span><input name="city" maxlength="80" required autocomplete="address-level2" value="' . $v( 'city' ) . '"></label></div>';
	$h .= '<label class="dlr-field"><span>Comment vendez-vous ? (clients, réseaux, boutique…)</span><textarea name="channel" rows="3" maxlength="1000" required>' . esc_textarea( (string) ( $old['channel'] ?? '' ) ) . '</textarea></label>';
	$h .= '<label class="dlr-field"><span>Vos ventes par mois (estimation)</span><select name="volume" required><option value="">Choisir…</option>';
	foreach ( dlr_volumes() as $k => $label ) $h .= '<option value="' . esc_attr( $k ) . '"' . selected( $old['volume'] ?? '', $k, false ) . '>' . esc_html( $label ) . '</option>';
	$h .= '</select></label>';
	$h .= '<label class="dlr-check"><input type="checkbox" name="terms" value="1" required><span>J’accepte les conditions : dépôt uniquement par le système de recharge, dépôt minimum de mon niveau chaque mois, et pas d’envoi d’argent entre portefeuilles pendant l’étape du dépôt.</span></label>';
	$h .= '<button class="dlr-btn dlr-btn--wide" type="submit">Envoyer ma demande</button></form>';
	return $h;
}

function dlr_view_rules() {
	$days = (int) dlr_cfg( 'deposit_days' );
	return '<details class="dlr-card dlr-rules"><summary>Les conditions du programme</summary><ol>'
		. '<li>Faites votre demande : l’équipe l’examine.</li>'
		. '<li>Une fois approuvée, rechargez au moins le minimum de votre niveau dans les ' . $days . ' jours, uniquement par le système de recharge du site (MonCash, NatCash…).</li>'
		. '<li>Un envoi d’argent d’un autre portefeuille Delicat vers le vôtre pendant cette étape refuse la demande automatiquement.</li>'
		. '<li>Dès que le dépôt est validé : votre badge s’affiche sur votre compte et vos prix revendeur s’appliquent sur le site.</li>'
		. '<li>Chaque mois, rechargez au moins le minimum de votre niveau. Sinon, votre niveau baisse le mois suivant ; en dessous du minimum ' . esc_html( dlr_tier_label( 'silver' ) ) . ' (' . esc_html( dlr_money( dlr_tier_min( 'silver' ) ) ) . '), le programme s’arrête.</li>'
		. '<li>Les achats à prix revendeur ne comptent pas pour les bonus clients (Bonus Free Fire, Meilleur client, Parrainage).</li>'
		. '</ol></details>';
}

/* ============================================================== form handlers */

function dlr_back( $state = '' ) {
	wp_safe_redirect( ( '' !== $state ? add_query_arg( 'dlr', $state, dlr_page_url() ) : dlr_page_url() ) . '#dlr' );
	exit;
}
add_action( 'admin_post_nopriv_dlr_apply', function () { wp_safe_redirect( dlr_login_url() ); exit; } );
add_action( 'admin_post_nopriv_dlr_cancel', function () { wp_safe_redirect( dlr_login_url() ); exit; } );
add_action( 'admin_post_dlr_apply', function () {
	$uid = get_current_user_id();
	if ( ! check_admin_referer( 'dlr_apply' ) ) dlr_back();
	$in = array();
	foreach ( array( 'tier', 'business', 'phone', 'city', 'channel', 'volume', 'terms' ) as $k ) $in[ $k ] = isset( $_POST[ $k ] ) ? (string) wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in dlr_apply()
	$r = dlr_apply( $uid, $in );
	if ( is_wp_error( $r ) ) {
		$old = array_map( 'sanitize_text_field', array_intersect_key( $in, array_flip( array( 'tier', 'business', 'phone', 'city', 'volume' ) ) ) );
		$old['channel'] = sanitize_textarea_field( $in['channel'] );
		dlr_flash( $uid, array( 'type' => 'err', 'msg' => $r->get_error_message(), 'old' => $old ) );
		dlr_back( 'err' );
	}
	delete_transient( 'dlr_flash_' . $uid );
	dlr_back( 'sent' );
} );
add_action( 'admin_post_dlr_cancel', function () {
	$uid = get_current_user_id();
	if ( ! check_admin_referer( 'dlr_cancel' ) ) dlr_back();
	$r = dlr_cancel( $uid );
	if ( is_wp_error( $r ) ) { dlr_flash( $uid, array( 'type' => 'err', 'msg' => $r->get_error_message() ) ); dlr_back( 'err' ); }
	dlr_back( 'cancelled' );
} );

/* ============================================================== account home: the badge */

add_action( 'woocommerce_before_account_navigation', function () {
	if ( ! dlr_on() || ! is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
	if ( function_exists( 'WC' ) && WC()->query && '' !== (string) WC()->query->get_current_endpoint() ) return; // the account home only
	$uid  = get_current_user_id();
	$tier = dlr_user_tier( $uid );
	$url  = esc_url( dlr_page_url() );
	if ( '' !== $tier ) {
		$p   = dlr_month_progress( $uid );
		$sub = $p['settled'] ? 'Niveau actif ce mois-ci' : 'Ce mois : ' . dlr_money( $p['paid'] ) . ' / ' . dlr_money( $p['min'] );
		echo '<a class="dlr-acc dlr-t-' . esc_attr( $tier ) . '" href="' . $url . '" data-user-id="' . (int) $uid . '">' . dlr_icon( DLR_TIER_ICON[ $tier ] ) . '<span><b>' . esc_html( dlr_tier_label( $tier ) ) . '</b><small>' . esc_html( $sub ) . '</small></span>' . dlr_icon( 'chevron_right', 'dlr-acc-go' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above, icons are static
		return;
	}
	$open = dlr_open_app( $uid );
	if ( $open ) {
		$sub = 'pending' === $open->status ? 'Demande en examen' : 'Dépôt attendu : ' . dlr_money( dlr_tier_min( $open->tier ) ) . ' avant le ' . wp_date( 'j F', dlr_ts( $open->deadline ) );
		echo '<a class="dlr-acc dlr-acc--soft" href="' . $url . '" data-user-id="' . (int) $uid . '">' . dlr_icon( 'hourglass_top' ) . '<span><b>Programme revendeur</b><small>' . esc_html( $sub ) . '</small></span>' . dlr_icon( 'chevron_right', 'dlr-acc-go' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}, 2 );
