<?php
/**
 * Delicat Resellers — WooCommerce › Delicat Revendeurs: applications (approve / refuse), deposits being
 * awaited, active resellers (change level, remove, add one by hand), history, settings.
 */
defined( 'ABSPATH' ) || exit;

const DLR_CAP = 'manage_woocommerce';

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Delicat Revendeurs', 'Delicat Revendeurs', DLR_CAP, 'delicat-resellers', 'dlr_admin_page' );
}, 60 );

function dlr_admin_url( $tab = 'demandes' ) {
	return admin_url( 'admin.php?page=delicat-resellers&tab=' . rawurlencode( $tab ) );
}
function dlr_admin_guard( $action ) {
	if ( ! current_user_can( DLR_CAP ) ) wp_die( 'Accès refusé.', 'Delicat Revendeurs', array( 'response' => 403 ) );
	check_admin_referer( $action );
}
function dlr_admin_back( $tab, $msg, $ok = true ) {
	set_transient( 'dlr_admin_note_' . get_current_user_id(), array( $ok, $msg ), MINUTE_IN_SECONDS );
	wp_safe_redirect( dlr_admin_url( $tab ) );
	exit;
}
function dlr_admin_form( $action ) {
	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">' . wp_nonce_field( $action, '_wpnonce', true, false );
}
/** Facts the team weighs before approving: account age, purchases, deposits, transfers received. */
function dlr_admin_customer( $uid ) {
	$u = get_userdata( (int) $uid );
	if ( ! $u ) return '#' . (int) $uid;
	$age    = max( 0, (int) floor( ( time() - strtotime( $u->user_registered . ' UTC' ) ) / DAY_IN_SECONDS ) );
	$spent  = function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $u->ID ) : 0;
	$orders = function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $u->ID ) : 0;
	$dep    = dlr_deposits( $u->ID, time() - 90 * DAY_IN_SECONDS );
	list( $tn, $ts ) = dlr_transfers_in( $u->ID, time() - 30 * DAY_IN_SECONDS );
	return '<strong><a href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">' . esc_html( $u->display_name ) . '</a></strong> #' . (int) $u->ID . '<br>' . esc_html( $u->user_email )
		. '<br><small>Compte : ' . (int) $age . ' j · ' . (int) $orders . ' commande(s), ' . esc_html( dlr_money( $spent ) ) . ' · recharges 90 j : ' . esc_html( dlr_money( $dep ) )
		. ( $tn ? ' · <span style="color:#b32d2e">' . (int) $tn . ' envoi(s) reçu(s) en 30 j (' . esc_html( dlr_money( $ts ) ) . ')</span>' : '' ) . '</small>';
}

function dlr_admin_page() {
	global $wpdb;
	if ( ! current_user_can( DLR_CAP ) ) return;
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'demandes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation
	$tabs = array( 'demandes' => 'Demandes', 'revendeurs' => 'Revendeurs', 'historique' => 'Historique', 'reglages' => 'Réglages' );
	if ( ! isset( $tabs[ $tab ] ) ) $tab = 'demandes';
	echo '<div class="wrap dlr-admin"><h1>Delicat Revendeurs</h1>';
	echo '<style>.dlr-admin .box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:16px 0}.dlr-admin td small{color:#50575e}.dlr-admin .dlr-tag{display:inline-block;padding:2px 8px;border-radius:999px;color:#fff;font-weight:600;font-size:12px}.dlr-admin .t-premium{background:#6d28d9}.dlr-admin .t-gold{background:#92400e}.dlr-admin .t-silver{background:#475569}.dlr-admin .bar{height:8px;border-radius:8px;background:#e2e8f0;overflow:hidden;max-width:220px}.dlr-admin .bar i{display:block;height:100%;background:#2271b1}</style>';
	$note = get_transient( 'dlr_admin_note_' . get_current_user_id() );
	if ( is_array( $note ) ) { delete_transient( 'dlr_admin_note_' . get_current_user_id() ); echo '<div class="notice notice-' . ( $note[0] ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $note[1] ) . '</p></div>'; }
	if ( ! dlr_source_table( 'delicat_recharges' ) ) echo '<div class="notice notice-error"><p>Delicat Recharge est introuvable : les dépôts ne peuvent pas être comptés. Activez Delicat Recharge Admin.</p></div>';
	if ( ! dlr_on() ) echo '<div class="notice notice-warning"><p>Le programme est <strong>fermé</strong> : activez-le dans « Réglages » après avoir saisi les prix revendeurs sur vos produits.</p></div>';
	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $k => $label ) echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( dlr_admin_url( $k ) ) . '">' . esc_html( $label ) . '</a>';
	echo '</nav>';
	call_user_func( 'dlr_admin_tab_' . $tab );
	echo '</div>';
}

function dlr_tag( $tier ) {
	return '' === $tier ? '—' : '<span class="dlr-tag t-' . esc_attr( $tier ) . '">' . esc_html( dlr_tier_label( $tier ) ) . '</span>';
}

function dlr_admin_tab_demandes() {
	global $wpdb;
	$t = dlr_table( 'apps' );
	$pending = $wpdb->get_results( "SELECT * FROM $t WHERE status='pending' ORDER BY id ASC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	echo '<div class="box"><h2>Nouvelles demandes (' . count( (array) $pending ) . ')</h2>';
	if ( ! $pending ) echo '<p>Aucune demande à examiner.</p>';
	else {
		echo '<table class="widefat striped"><thead><tr><th>Client</th><th>Niveau</th><th>Activité</th><th>Volume</th><th>Date</th><th>Décision</th></tr></thead><tbody>';
		foreach ( $pending as $a ) {
			echo '<tr><td>' . dlr_admin_customer( $a->user_id ) . '</td><td>' . dlr_tag( $a->tier ) . '<br><small>actuel : ' . wp_strip_all_tags( dlr_tag( dlr_user_tier( $a->user_id ) ) ) . '</small></td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helpers
				. '<td><strong>' . esc_html( $a->business ) . '</strong> — ' . esc_html( $a->city ) . '<br>' . esc_html( $a->phone ) . '<br><small>' . nl2br( esc_html( (string) $a->channel ) ) . '</small></td>'
				. '<td>' . esc_html( dlr_volumes()[ $a->volume ] ?? $a->volume ) . '</td><td>' . esc_html( get_date_from_gmt( $a->created_at, 'd/m/Y H:i' ) ) . '</td><td>';
			echo dlr_admin_form( 'dlr_admin_decide' ) . '<input type="hidden" name="id" value="' . (int) $a->id . '"><p><textarea name="note" rows="2" cols="24" placeholder="Note ou motif (envoyé au client si refus)"></textarea></p><button class="button button-primary" name="do" value="approve">Approuver (étape dépôt)</button> <button class="button" name="do" value="reject">Refuser</button></form></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table><p class="description">Approuver : le client reçoit un e-mail et a ' . (int) dlr_cfg( 'deposit_days' ) . ' jours pour recharger le minimum du niveau par le système de recharge. S’il reçoit un envoi d’un autre portefeuille pendant ce temps, la demande est refusée automatiquement.</p>';
	}
	echo '</div>';
	$waiting = $wpdb->get_results( "SELECT * FROM $t WHERE status='deposit' ORDER BY deadline ASC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	echo '<div class="box"><h2>Dépôt attendu (' . count( (array) $waiting ) . ')</h2>';
	if ( ! $waiting ) echo '<p>Aucune demande en attente de dépôt.</p>';
	else {
		echo '<table class="widefat striped"><thead><tr><th>Client</th><th>Niveau</th><th>Déjà rechargé</th><th>Date limite</th><th></th></tr></thead><tbody>';
		foreach ( $waiting as $a ) {
			$paid = dlr_app_paid( $a );
			$min  = dlr_tier_min( $a->tier );
			echo '<tr><td>' . dlr_admin_customer( $a->user_id ) . '</td><td>' . dlr_tag( $a->tier ) . '</td><td>' . esc_html( dlr_money( $paid ) ) . ' / ' . esc_html( dlr_money( $min ) ) . '<div class="bar"><i style="width:' . (int) min( 100, $min ? $paid / $min * 100 : 0 ) . '%"></i></div></td><td>' . esc_html( get_date_from_gmt( $a->deadline, 'd/m/Y H:i' ) ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo dlr_admin_form( 'dlr_admin_decide' ) . '<input type="hidden" name="id" value="' . (int) $a->id . '"><button class="button" name="do" value="check">Vérifier maintenant</button> <button class="button" name="do" value="reject">Refuser</button></form></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

function dlr_admin_tab_revendeurs() {
	$ids = get_users( array( 'meta_key' => '_dlr_tier', 'fields' => 'ID', 'number' => 1000 ) );
	echo '<div class="box"><h2>Revendeurs actifs (' . count( $ids ) . ')</h2>';
	if ( ! $ids ) echo '<p>Aucun revendeur pour le moment.</p>';
	else {
		echo '<table class="widefat striped"><thead><tr><th>Client</th><th>Niveau</th><th>Depuis</th><th>Dépôts ce mois</th><th>Changer</th></tr></thead><tbody>';
		foreach ( $ids as $uid ) {
			$p = dlr_month_progress( $uid );
			if ( '' === $p['tier'] ) continue;
			$since = (int) get_user_meta( $uid, '_dlr_since', true );
			echo '<tr><td>' . dlr_admin_customer( $uid ) . '</td><td>' . dlr_tag( $p['tier'] ) . '</td><td>' . ( $since ? esc_html( wp_date( 'd/m/Y', $since ) ) : '—' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( dlr_money( $p['paid'] ) ) . ' / ' . esc_html( dlr_money( $p['min'] ) ) . ( $p['settled'] ? ' <small>(activé ce mois)</small>' : '' ) . '<div class="bar"><i style="width:' . (int) min( 100, $p['min'] ? $p['paid'] / $p['min'] * 100 : 0 ) . '%"></i></div></td><td>';
			echo dlr_admin_form( 'dlr_admin_tier' ) . '<input type="hidden" name="user_id" value="' . (int) $uid . '"><select name="tier">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( dlr_tiers() as $t => $c ) echo '<option value="' . esc_attr( $t ) . '"' . selected( $p['tier'], $t, false ) . '>' . esc_html( $c['label'] ) . '</option>';
			echo '<option value="">Retirer du programme</option></select> <button class="button">Appliquer</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div><div class="box"><h2>Ajouter un revendeur</h2><p>Pour un revendeur que vous connaissez déjà (sans demande ni dépôt de qualification). Le minimum mensuel s’applique dès le mois prochain.</p>';
	echo dlr_admin_form( 'dlr_admin_tier' ) . '<input type="email" name="email" placeholder="E-mail du compte client" required style="width:260px"> <select name="tier">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	foreach ( dlr_tiers() as $t => $c ) echo '<option value="' . esc_attr( $t ) . '">' . esc_html( $c['label'] ) . '</option>';
	echo '</select> <button class="button button-primary">Ajouter</button></form></div>';
}

function dlr_admin_tab_historique() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . dlr_table( 'log' ) . ' ORDER BY id DESC LIMIT 200' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$ev = array( 'applied' => 'Demande envoyée', 'approved' => 'Approuvée (dépôt attendu)', 'rejected' => 'Refusée par l’équipe', 'declined' => 'Refusée : envoi entre portefeuilles', 'expired' => 'Expirée (dépôt incomplet)', 'cancelled' => 'Annulée par le client', 'activated' => 'Activé', 'upgraded' => 'Niveau supérieur', 'kept' => 'Minimum atteint', 'downgraded' => 'Niveau baissé', 'removed' => 'Retiré (minimum non atteint)', 'admin_set' => 'Changé par l’équipe', 'paused' => 'Programme fermé : mois non dus' );
	echo '<div class="box"><h2>Historique (200 derniers)</h2>';
	if ( ! $rows ) { echo '<p>Rien pour le moment.</p></div>'; return; }
	echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Client</th><th>Événement</th><th>Niveau</th><th>Montant</th><th>Détail</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$u = get_userdata( (int) $r->user_id );
		echo '<tr><td>' . esc_html( get_date_from_gmt( $r->created_at, 'd/m/Y H:i' ) ) . '</td><td>' . esc_html( $u ? $u->display_name . ' #' . $u->ID : '#' . (int) $r->user_id ) . '</td><td>' . esc_html( $ev[ $r->event ] ?? $r->event ) . '</td><td>' . esc_html( dlr_tier_label( $r->tier_from ) ?: '—' ) . ' → ' . esc_html( dlr_tier_label( $r->tier_to ) ?: '—' ) . '</td><td>' . ( (float) $r->amount ? esc_html( dlr_money( $r->amount ) ) : '' ) . '</td><td>' . esc_html( $r->note ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

function dlr_admin_tab_reglages() {
	$c = dlr_cfg();
	echo '<div class="box">' . dlr_admin_form( 'dlr_admin_save' ) . '<table class="form-table"><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<tr><th>Programme</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( 1, (int) $c['enabled'], false ) . '> Ouvrir le programme revendeur</label><p class="description">Fermé : la page affiche « bientôt », les prix revendeurs ne s’appliquent pas et les badges sont masqués.</p></td></tr>';
	echo '<tr><th>Niveaux</th><td><table class="widefat" style="max-width:720px"><thead><tr><th>Niveau</th><th>Nom du badge</th><th>Dépôt minimum par mois (HTG)</th><th>Remise si le produit n’a pas de prix revendeur (%)</th></tr></thead><tbody>';
	foreach ( $c['tiers'] as $t => $s ) {
		echo '<tr><td>' . esc_html( ucfirst( $t ) ) . '</td><td><input name="tiers[' . esc_attr( $t ) . '][label]" value="' . esc_attr( $s['label'] ) . '" maxlength="40"></td><td><input type="number" min="1" max="10000000" name="tiers[' . esc_attr( $t ) . '][min]" value="' . (int) $s['min'] . '" style="width:130px"></td><td><input type="number" min="0" max="90" step="0.5" name="tiers[' . esc_attr( $t ) . '][discount]" value="' . esc_attr( (string) $s['discount'] ) . '" style="width:90px"></td></tr>';
	}
	echo '</tbody></table><p class="description">Les prix revendeurs se saisissent sur chaque produit (onglet Général) et chaque variation. Un produit sans prix pour un niveau utilise la remise de ce niveau (0 = prix normal). Le produit de recharge du portefeuille n’a jamais de prix revendeur. Les minimums doivent aller en baissant : Premium &gt; Gold &gt; Silver.</p></td></tr>';
	echo '<tr><th>Délai du dépôt</th><td><input type="number" min="1" max="60" name="deposit_days" value="' . (int) $c['deposit_days'] . '" style="width:80px"> jours après l’approbation</td></tr>';
	echo '<tr><th>Rappel mensuel</th><td>à partir du <input type="number" min="1" max="28" name="reminder_day" value="' . (int) $c['reminder_day'] . '" style="width:70px"> du mois, un e-mail rappelle ce qu’il manque à un revendeur sous son minimum</td></tr>';
	echo '<tr><th>Page revendeur</th><td>' . wp_dropdown_pages( array( 'name' => 'page_id', 'echo' => 0, 'selected' => (int) $c['page_id'], 'show_option_none' => 'Automatique : la page qui contient [delicat_revendeur]', 'option_none_value' => '0' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<tr><th>Page de recharge</th><td>' . wp_dropdown_pages( array( 'name' => 'recharge_page_id', 'echo' => 0, 'selected' => (int) $c['recharge_page_id'], 'show_option_none' => 'Automatique : la page qui contient [delicat_recharge]', 'option_none_value' => '0' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<tr><th>E-mail de l’équipe</th><td><input type="email" name="notify" value="' . esc_attr( (string) $c['notify'] ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '" style="width:280px"><p class="description">Nouvelles demandes, activations, refus automatiques, retraits.</p></td></tr>';
	echo '</tbody></table><p><button class="button button-primary">Enregistrer</button></p></form></div>';
}

/* ============================================================== handlers */

add_action( 'admin_post_dlr_admin_decide', function () {
	dlr_admin_guard( 'dlr_admin_decide' );
	$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
	$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
	if ( 'approve' === $do ) $r = dlr_approve( $id, $note );
	elseif ( 'reject' === $do ) $r = dlr_reject( $id, $note );
	elseif ( 'check' === $do ) { dlr_check_app( dlr_app( $id ) ); $a = dlr_app( $id ); dlr_admin_back( 'demandes', 'Demande #' . $id . ' : ' . ( $a ? $a->status : '?' ) . '.' ); }
	else $r = new WP_Error( 'do', 'Action inconnue.' );
	if ( is_wp_error( $r ) ) dlr_admin_back( 'demandes', $r->get_error_message(), false );
	dlr_admin_back( 'demandes', 'approve' === $do ? 'Demande approuvée : le client a reçu les instructions de dépôt.' : 'Demande refusée.' );
} );

add_action( 'admin_post_dlr_admin_tier', function () {
	dlr_admin_guard( 'dlr_admin_tier' );
	$tier = isset( $_POST['tier'] ) ? sanitize_key( wp_unslash( $_POST['tier'] ) ) : '';
	$uid  = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
	if ( ! $uid && ! empty( $_POST['email'] ) ) {
		$u   = get_user_by( 'email', sanitize_email( wp_unslash( $_POST['email'] ) ) );
		$uid = $u ? (int) $u->ID : 0;
		if ( ! $uid ) dlr_admin_back( 'revendeurs', 'Aucun compte avec cet e-mail.', false );
	}
	if ( ! $uid || ( '' !== $tier && ! in_array( $tier, dlr_tier_keys(), true ) ) ) dlr_admin_back( 'revendeurs', 'Choix invalide.', false );
	$from = dlr_user_tier( $uid, true );
	if ( ! dlr_set_tier( $uid, $tier, 'admin_set', 0, 'Par ' . wp_get_current_user()->user_login ) ) dlr_admin_back( 'revendeurs', 'Aucun changement.', false );
	if ( '' !== $tier ) update_user_meta( $uid, '_dlr_eval', dlr_month_key() ); // the minimum applies from next month
	$open = dlr_open_app( $uid );
	if ( $open && dlr_tier_rank( $tier ) >= dlr_tier_rank( $open->tier ) ) dlr_app_move( $open->id, $open->status, array( 'status' => 'cancelled', 'reason' => 'team_set', 'decided_at' => current_time( 'mysql', 1 ) ) );
	dlr_admin_back( 'revendeurs', '' === $tier ? 'Revendeur retiré du programme.' : ( '' === $from ? 'Revendeur ajouté : ' : 'Niveau changé : ' ) . dlr_tier_label( $tier ) . '.' );
} );

add_action( 'admin_post_dlr_admin_save', function () {
	dlr_admin_guard( 'dlr_admin_save' );
	$c = dlr_cfg();
	$c['enabled'] = empty( $_POST['enabled'] ) ? 0 : 1;
	$in = isset( $_POST['tiers'] ) && is_array( $_POST['tiers'] ) ? wp_unslash( $_POST['tiers'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value cast below
	foreach ( dlr_tier_keys() as $t ) {
		$s = isset( $in[ $t ] ) && is_array( $in[ $t ] ) ? $in[ $t ] : array();
		$label = trim( sanitize_text_field( (string) ( $s['label'] ?? '' ) ) );
		$c['tiers'][ $t ] = array(
			'label'    => '' !== $label ? mb_substr( $label, 0, 40 ) : dlr_defaults()['tiers'][ $t ]['label'],
			'min'      => max( 1, min( 10000000, (int) ( $s['min'] ?? 0 ) ) ),
			'discount' => max( 0, min( 90, round( (float) ( $s['discount'] ?? 0 ), 1 ) ) ),
		);
	}
	if ( ! ( $c['tiers']['premium']['min'] > $c['tiers']['gold']['min'] && $c['tiers']['gold']['min'] > $c['tiers']['silver']['min'] ) ) dlr_admin_back( 'reglages', 'Les minimums doivent baisser d’un niveau à l’autre : Premium > Gold > Silver. Rien n’a été enregistré.', false );
	$c['deposit_days']     = max( 1, min( 60, isset( $_POST['deposit_days'] ) ? (int) $_POST['deposit_days'] : 7 ) );
	$c['reminder_day']     = max( 1, min( 28, isset( $_POST['reminder_day'] ) ? (int) $_POST['reminder_day'] : 22 ) );
	$c['page_id']          = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
	$c['recharge_page_id'] = isset( $_POST['recharge_page_id'] ) ? absint( $_POST['recharge_page_id'] ) : 0;
	$notify                = isset( $_POST['notify'] ) ? sanitize_email( wp_unslash( $_POST['notify'] ) ) : '';
	$c['notify']           = is_email( $notify ) ? $notify : '';
	update_option( 'dlr_config', $c, false );
	dlr_cfg( null, true );
	dlr_admin_back( 'reglages', 'Réglages enregistrés.' );
} );

/* Users list: the level beside each customer. */
add_filter( 'manage_users_columns', function ( $cols ) { $cols['dlr_tier'] = 'Revendeur'; return $cols; } );
add_filter( 'manage_users_custom_column', function ( $out, $col, $uid ) {
	return 'dlr_tier' === $col ? esc_html( dlr_tier_label( dlr_user_tier( $uid ) ) ?: '—' ) : $out;
}, 10, 3 );
