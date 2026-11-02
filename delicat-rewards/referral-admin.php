<?php
/** Parrainage — admin tab (WooCommerce › Delicat Rewards › Parrainage): settings, review queue, blocked accounts. */
defined( 'ABSPATH' ) || exit;

function delicat_ref_reason_label( $code ) {
	$labels = array(
		'self' => 'Son propre code', 'inviter_blocked' => 'Parrain bloqué', 'same_device' => 'Même téléphone que le parrain',
		'device_shared' => 'Téléphone déjà utilisé par un autre compte', 'phone_shared' => 'Numéro de téléphone d’un autre compte',
		'email_alias' => 'E-mail d’un autre compte (alias)', 'same_browser_network' => 'Même navigateur et même réseau que le parrain',
		'same_network' => 'Même réseau que le parrain', 'same_browser' => 'Même navigateur que le parrain', 'vpn' => 'VPN ou proxy',
		'refunded' => 'Achat remboursé ou annulé', 'refunded_after' => 'Remboursé après validation', 'order_changed' => 'Commande modifiée',
		'too_late' => 'Pas d’achat dans le délai', 'admin' => 'Refusé par l’équipe', 'admin_ok' => 'Accepté par l’équipe',
		'velocity' => 'Beaucoup d’invitations en 24 h',
	);
	$out = array();
	foreach ( array_filter( explode( ',', (string) $code ) ) as $c ) $out[] = $labels[ $c ] ?? $c;
	return implode( ' · ', $out );
}
/** The order's edit screen (classic or HPOS storage). */
function delicat_ref_order_link( $order_id ) {
	$o = $order_id ? wc_get_order( (int) $order_id ) : null;
	return $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . (int) $order_id . '</a>' : '—';
}
function delicat_ref_user_label( $uid ) {
	$u = get_userdata( (int) $uid );
	return $u ? $u->display_name . ' (#' . (int) $uid . ')' : '#' . (int) $uid;
}

function delicat_tab_referral() {
	global $wpdb;
	$c = delicat_ref_cfg();
	$t = delicat_ref_table( 'referrals' );
	$counts = array();
	foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) n FROM $t GROUP BY status" ) as $r ) $counts[ $r->status ] = (int) $r->n; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$claims = $wpdb->get_results( 'SELECT choice, COUNT(*) n, SUM(amount) total FROM ' . delicat_ref_table( 'claims' ) . " WHERE status='done' GROUP BY choice" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$provider = delicat_ref_vpn_provider();
	$form = function ( $action ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">'; wp_nonce_field( $action ); };

	echo '<div class="box"><h3>Programme de parrainage</h3><p>Un client invite ses amis avec son code ou son lien personnel. Chaque ami qui crée un compte et paie un premier achat réel lui donne 1 point (maximum ' . (int) DELICAT_REF_MAX_POINTS . '). Les paliers débloquent une récompense, au choix : diamants Free Fire (PIN) ou crédit boutique en HTG. Page client : onglet « Parrainage » de la page Bonus Free Fire.</p>';
	echo '<p><strong>État :</strong> ' . ( delicat_ref_on() ? '<span style="color:#008a20">activé</span>' : '<span style="color:#b32d2e">désactivé</span> — vérifiez les récompenses ci-dessous puis activez.' ) . '</p>';
	echo '<p>Invitations : ' . (int) ( $counts['signed_up'] ?? 0 ) . ' inscrites · ' . (int) ( $counts['qualified'] ?? 0 ) . ' achat en attente · ' . (int) ( $counts['confirmed'] ?? 0 ) . ' points donnés · ' . (int) ( $counts['review'] ?? 0 ) . ' à vérifier · ' . (int) ( $counts['rejected'] ?? 0 ) . ' refusées.';
	foreach ( (array) $claims as $cl ) echo ' Récompenses : ' . (int) $cl->n . ' × ' . ( 'htg' === $cl->choice ? 'crédit (' . delicat_num( (int) $cl->total ) . ' HTG)' : 'diamants (' . delicat_num( (int) $cl->total ) . ')' ) . '.';
	echo '</p></div>';

	/* ---- settings */
	echo '<div class="box"><h3>Réglages</h3>';
	$form( 'delicat_ref_save' );
	echo '<table class="form-table"><tbody>';
	echo '<tr><th>Programme</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( 1, (int) $c['enabled'], false ) . '> Activer le parrainage</label></td></tr>';
	echo '<tr><th>Premier achat de l’ami</th><td>au moins <input type="number" min="1" max="100000" name="min_order" value="' . (int) $c['min_order'] . '" style="width:110px"> HTG, dans les <input type="number" min="1" max="365" name="order_days" value="' . (int) $c['order_days'] . '" style="width:80px"> jours après son inscription.<br><label><input type="checkbox" name="exclude_wallet" value="1" ' . checked( 1, (int) $c['exclude_wallet'], false ) . '> Payé par un vrai moyen de paiement (MonCash, NatCash…), pas par le crédit boutique — empêche un parrain de financer un faux compte par un transfert.</label></td></tr>';
	echo '<tr><th>Validation du point</th><td>le point compte <input type="number" min="0" max="60" name="hold_days" value="' . (int) $c['hold_days'] . '" style="width:80px"> jours après l’achat terminé (s’il n’est ni remboursé ni annulé).</td></tr>';
	echo '<tr><th>Lien et code</th><td>un clic sur un lien est retenu <input type="number" min="1" max="90" name="attribution_days" value="' . (int) $c['attribution_days'] . '" style="width:80px"> jours ; un nouveau compte peut taper un code pendant <input type="number" min="0" max="30" name="code_days" value="' . (int) $c['code_days'] . '" style="width:80px"> jours, avant son premier achat.</td></tr>';
	echo '<tr><th>Invitations par jour</th><td>au-delà de <input type="number" min="0" max="100" name="daily_cap" value="' . (int) $c['daily_cap'] . '" style="width:80px"> nouveaux amis en 24 h pour un même parrain, les suivants sont mis « À vérifier » (0 = sans limite). Freine les faux comptes créés en série.</td></tr>';
	echo '<tr><th>Points maximum</th><td><input type="number" min="1" max="' . (int) DELICAT_REF_MAX_POINTS . '" name="max_points" value="' . (int) $c['max_points'] . '" style="width:80px"> par client</td></tr>';
	echo '<tr><th>Paliers</th><td><table class="widefat" style="max-width:640px"><thead><tr><th>Points</th><th>Diamants Free Fire (PIN)</th><th>ou crédit boutique (HTG)</th></tr></thead><tbody>';
	$rows = array_values( delicat_ref_milestones() );
	for ( $i = 0; $i < 8; $i++ ) {
		$m = $rows[ $i ] ?? array( 'points' => '', 'diamonds' => '', 'htg' => '' );
		echo '<tr><td><input type="number" min="1" max="' . (int) DELICAT_REF_MAX_POINTS . '" name="m_points[]" value="' . esc_attr( $m['points'] ) . '" style="width:80px"></td><td><input type="number" min="0" max="20000" name="m_diamonds[]" value="' . esc_attr( $m['diamonds'] ) . '" style="width:110px"></td><td><input type="number" min="0" max="100000" name="m_htg[]" value="' . esc_attr( $m['htg'] ) . '" style="width:110px"></td></tr>';
	}
	echo '</tbody></table><p class="description">Laissez une ligne vide pour la retirer, 0 pour ne pas proposer un des deux choix. Les diamants doivent pouvoir être composés avec vos PIN (110, 341, 572…) ; le stock est vérifié au moment de la réclamation.</p></td></tr>';
	$sel = function ( $name, $value, $opts ) { $h = '<select name="' . esc_attr( $name ) . '">'; foreach ( $opts as $k => $l ) $h .= '<option value="' . esc_attr( $k ) . '"' . selected( $value, $k, false ) . '>' . esc_html( $l ) . '</option>'; return $h . '</select>'; };
	echo '<tr><th>Même téléphone</th><td>' . $sel( 'same_device', $c['same_device'], array( 'block' => 'Refuser et sortir les comptes du programme de récompenses (à vérifier ici)', 'review' => 'Mettre en vérification' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $sel
	echo '<tr><th>Même réseau (adresse IP)</th><td>' . $sel( 'same_ip', $c['same_ip'], array( 'review' => 'Mettre en vérification (recommandé : Digicel et Natcom partagent des adresses)', 'block' => 'Refuser', 'off' => 'Ignorer' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<tr><th>VPN / proxy</th><td>' . $sel( 'vpn', $c['vpn'], array( 'block' => 'Interdire (visite, inscription, code, réclamation)', 'review' => 'Mettre en vérification', 'off' => 'Ne pas vérifier' ) ) . '<p class="description">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( 'proxycheck' === $provider ) echo 'Détection active : proxycheck.io (clé définie dans wp-config.php, jamais affichée).';
	elseif ( 'filter' === $provider ) echo 'Détection active : fournisseur personnalisé (filtre delicat_ref_vpn_verdict).';
	else echo '<strong>Détection inactive.</strong> Créez une clé sur proxycheck.io puis ajoutez dans wp-config.php : <code>define( \'DELICAT_PROXYCHECK_KEY\', \'votre-clé\' );</code>. Sans clé, les VPN ne peuvent pas être reconnus ; les autres contrôles restent actifs.';
	echo '</p></td></tr>';
	$auto = delicat_ref_page_id();
	echo '<tr><th>Page des invitations</th><td>' . wp_dropdown_pages( array( 'name' => 'page_id', 'echo' => 0, 'selected' => (int) $c['page_id'], 'post_status' => 'publish', 'show_option_none' => 'Automatique : la page qui contient [delicat_bonus_free_fire]', 'option_none_value' => '0' ) ) . '<p class="description">' . ( $auto ? 'Les liens ouvrent : ' . esc_html( (string) get_permalink( $auto ) ) : '<strong>Aucune page publique avec [delicat_bonus_free_fire] : les clients n’ont pas encore de lien.</strong>' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core dropdown, escaped
	echo '<tr><th>Message de partage</th><td><textarea name="share_text" rows="3" maxlength="300" class="large-text">' . esc_textarea( (string) $c['share_text'] ) . '</textarea><p class="description">Envoyé par WhatsApp, Facebook, SMS… <code>{lien}</code> = lien personnel du client, <code>{code}</code> = son code. 300 caractères au plus.</p></td></tr>';
	echo '</tbody></table><p><button class="button button-primary">Enregistrer</button></p></form></div>';

	/* ---- review queue */
	$review = $wpdb->get_results( "SELECT * FROM $t WHERE status='review' ORDER BY id DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	echo '<div class="box"><h3>À vérifier (' . count( (array) $review ) . ')</h3>';
	if ( ! $review ) echo '<p>Rien à vérifier.</p>';
	else {
		echo '<table class="widefat striped"><thead><tr><th>Parrain</th><th>Ami invité</th><th>Raison</th><th>Achat</th><th>Date</th><th>Décision</th></tr></thead><tbody>';
		foreach ( $review as $r ) {
			echo '<tr><td>' . esc_html( delicat_ref_user_label( $r->referrer_id ) ) . '</td><td>' . esc_html( delicat_ref_user_label( $r->referred_id ) ) . '</td><td>' . esc_html( delicat_ref_reason_label( $r->reason ) ) . '</td><td>' . delicat_ref_order_link( $r->order_id ) . '</td><td>' . esc_html( $r->created_at ) . '</td><td>';
			$form( 'delicat_ref_decide' );
			echo '<input type="hidden" name="id" value="' . (int) $r->id . '"><button class="button button-primary" name="do" value="approve">Accepter</button> <button class="button" name="do" value="reject">Refuser</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';

	/* ---- blocked accounts */
	$blocked = get_users( array( 'meta_key' => '_delicat_ref_blocked', 'number' => 200, 'fields' => array( 'ID', 'display_name' ) ) );
	echo '<div class="box"><h3>Comptes bloqués par le parrainage (' . count( $blocked ) . ')</h3>';
	if ( ! $blocked ) echo '<p>Aucun compte bloqué.</p>';
	else {
		echo '<table class="widefat striped"><thead><tr><th>Client</th><th>Raison</th><th>Depuis</th><th>Programme de récompenses</th><th></th></tr></thead><tbody>';
		foreach ( $blocked as $u ) {
			$meta = explode( '|', (string) get_user_meta( $u->ID, '_delicat_ref_blocked', true ) );
			echo '<tr><td>' . esc_html( $u->display_name . ' (#' . $u->ID . ')' ) . '</td><td>' . esc_html( delicat_ref_reason_label( $meta[0] ?? '' ) ) . '</td><td>' . esc_html( isset( $meta[1] ) ? wp_date( 'd/m/Y H:i', (int) $meta[1] ) : '' ) . '</td><td>' . ( delicat_is_excluded( $u->ID ) ? 'exclu' : 'participe' ) . '</td><td>';
			$form( 'delicat_ref_unblock' );
			echo '<input type="hidden" name="user_id" value="' . (int) $u->ID . '"><button class="button">Débloquer</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';

	/* ---- latest invitations */
	$stuck = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . delicat_ref_table( 'claims' ) . " WHERE status='pending' AND created_at<%s ORDER BY id LIMIT 50", gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $stuck ) {
		echo '<div class="box"><h3>Réclamations à vérifier (' . count( $stuck ) . ')</h3><p>Une erreur a interrompu ces réclamations : le client a peut-être déjà reçu sa récompense. Vérifiez son portefeuille et ses commandes ; « Libérer » rend le palier réclamable, « Marquer donné » le ferme.</p><table class="widefat striped"><thead><tr><th>Client</th><th>Palier</th><th>Choix</th><th>Date</th><th></th></tr></thead><tbody>';
		foreach ( $stuck as $cl ) {
			echo '<tr><td>' . esc_html( delicat_ref_user_label( $cl->user_id ) ) . '</td><td>' . (int) $cl->milestone . ' points</td><td>' . ( 'htg' === $cl->choice ? esc_html( delicat_num( (int) $cl->amount ) ) . ' HTG' : esc_html( delicat_num( (int) $cl->amount ) ) . ' diamants' ) . '</td><td>' . esc_html( $cl->created_at ) . '</td><td>';
			$form( 'delicat_ref_release' );
			echo '<input type="hidden" name="id" value="' . (int) $cl->id . '"><button class="button" name="do" value="release">Libérer</button> <button class="button" name="do" value="done">Marquer donné</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}
	$latest = $wpdb->get_results( "SELECT * FROM $t ORDER BY id DESC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	echo '<div class="box"><h3>Dernières invitations</h3>';
	if ( ! $latest ) echo '<p>Aucune invitation pour le moment.</p>';
	else {
		$st = array( 'signed_up' => 'Inscrit', 'qualified' => 'Achat en attente', 'review' => 'À vérifier', 'confirmed' => 'Point donné', 'rejected' => 'Refusé' );
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Parrain</th><th>Ami</th><th>Par</th><th>Achat</th><th>État</th><th>Détail</th><th>Décision</th></tr></thead><tbody>';
		foreach ( $latest as $r ) {
			echo '<tr><td>' . esc_html( $r->created_at ) . '</td><td>' . esc_html( delicat_ref_user_label( $r->referrer_id ) ) . '</td><td>' . esc_html( delicat_ref_user_label( $r->referred_id ) ) . '</td><td>' . ( 'code' === $r->source ? 'code' : 'lien' ) . '</td><td>' . delicat_ref_order_link( $r->order_id ) . '</td><td>' . esc_html( $st[ $r->status ] ?? $r->status ) . '</td><td>' . esc_html( delicat_ref_reason_label( $r->reason ) ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in delicat_ref_order_link
			if ( 'review' !== $r->status ) { // the review queue above has its own buttons
				$form( 'delicat_ref_decide' );
				echo '<input type="hidden" name="id" value="' . (int) $r->id . '">';
				echo 'rejected' === $r->status ? '<button class="button" name="do" value="approve">Accepter</button>' : '<button class="button" name="do" value="reject" title="Refuser l’invitation (un point déjà donné est retiré)">Retirer</button>';
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

add_action( 'admin_post_delicat_ref_save', function () {
	delicat_guard( 'delicat_ref_save' );
	$errors = array();
	$num = function ( $k, $min, $max, $def ) { $v = isset( $_POST[ $k ] ) ? (int) $_POST[ $k ] : $def; return max( $min, min( $max, $v ) ); }; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- delicat_guard checked it
	$c = delicat_ref_cfg();
	$c['enabled']          = empty( $_POST['enabled'] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$c['exclude_wallet']   = empty( $_POST['exclude_wallet'] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$c['min_order']        = $num( 'min_order', 1, 100000, 100 );
	$c['order_days']       = $num( 'order_days', 1, 365, 30 );
	$c['hold_days']        = $num( 'hold_days', 0, 60, 7 );
	$c['attribution_days'] = $num( 'attribution_days', 1, 90, 30 );
	$c['code_days']        = $num( 'code_days', 0, 30, 7 );
	$c['max_points']       = $num( 'max_points', 1, DELICAT_REF_MAX_POINTS, DELICAT_REF_MAX_POINTS );
	$c['daily_cap']        = $num( 'daily_cap', 0, 100, 5 );
	foreach ( array( 'same_device' => array( 'block', 'review' ), 'same_ip' => array( 'review', 'block', 'off' ), 'vpn' => array( 'block', 'review', 'off' ) ) as $k => $allowed ) {
		$v = isset( $_POST[ $k ] ) ? sanitize_key( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( in_array( $v, $allowed, true ) ) $c[ $k ] = $v;
	}
	$page = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $page && ! delicat_ref_public_page( $page ) ) { $errors[] = 'Page des invitations : choisissez une page publiée sans mot de passe.'; $page = 0; }
	$c['page_id'] = $page;
	delete_transient( 'delicat_ref_page' );
	$share = isset( $_POST['share_text'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['share_text'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$c['share_text'] = '' !== $share ? mb_substr( $share, 0, 300 ) : delicat_ref_defaults()['share_text'];
	$p = (array) ( $_POST['m_points'] ?? array() ); $d = (array) ( $_POST['m_diamonds'] ?? array() ); $h = (array) ( $_POST['m_htg'] ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast below
	$miles = array();
	foreach ( $p as $i => $pts ) {
		$pts = (int) $pts; if ( $pts < 1 ) continue;
		if ( $pts > $c['max_points'] ) { $errors[] = sprintf( 'Palier %d ignoré : au-delà du maximum de points.', $pts ); continue; }
		$dia = max( 0, min( 20000, (int) ( $d[ $i ] ?? 0 ) ) ); $htg = max( 0, min( 100000, (int) ( $h[ $i ] ?? 0 ) ) );
		if ( $dia > 0 && ! delicat_reward_plan( $dia, false ) ) { $errors[] = sprintf( 'Palier %d : %d diamants ne peuvent pas être composés avec vos PIN — choix diamants retiré.', $pts, $dia ); $dia = 0; }
		if ( ! $dia && ! $htg ) { $errors[] = sprintf( 'Palier %d ignoré : aucune récompense.', $pts ); continue; }
		$miles[ $pts ] = array( 'points' => $pts, 'diamonds' => $dia, 'htg' => $htg );
	}
	ksort( $miles );
	$c['milestones'] = array_values( $miles );
	update_option( 'delicat_ref_config', $c, false );
	if ( function_exists( 'delicat_audit_add' ) ) delicat_audit_add( 'Parrainage : réglages enregistrés (' . ( $c['enabled'] ? 'activé' : 'désactivé' ) . ')' );
	delicat_back( 'parrainage', $errors, array( 'Réglages du parrainage enregistrés.' ) );
} );

add_action( 'admin_post_delicat_ref_decide', function () {
	global $wpdb;
	delicat_guard( 'delicat_ref_decide' );
	$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$t  = delicat_ref_table( 'referrals' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$allowed = 'approve' === $do ? array( 'review', 'rejected' ) : array( 'review', 'signed_up', 'qualified', 'confirmed' );
	if ( ! $row || ! in_array( $row->status, $allowed, true ) ) delicat_back( 'parrainage', array( 'Invitation introuvable ou déjà décidée.' ) );
	if ( 'approve' === $do ) {
		// Accepted by the team: no automatic check overturns it; the first-order rules still apply
		// (a refunded or changed order no longer counts, and the friend can still buy within the window).
		$counts = $row->order_id && delicat_ref_order_counts( wc_get_order( (int) $row->order_id ) );
		$status = $counts ? 'qualified' : 'signed_up';
		$update = array( 'status' => $status, 'reason' => 'admin_ok', 'decided_at' => null );
		if ( ! $counts ) { $update['order_id'] = 0; $update['qualified_at'] = null; $update['confirm_after'] = null; }
		$wpdb->update( $t, $update, array( 'id' => $id, 'status' => $row->status ) );
		$note = 'Invitation acceptée.';
		if ( function_exists( 'delicat_audit_add' ) ) delicat_audit_add( sprintf( 'Parrainage : invitation #%d acceptée', $id ) );
	} else {
		$wpdb->update( $t, array( 'status' => 'rejected', 'reason' => 'admin', 'decided_at' => current_time( 'mysql', 1 ) ), array( 'id' => $id, 'status' => $row->status ) );
		$note = 'Invitation refusée.';
		if ( function_exists( 'delicat_audit_add' ) ) delicat_audit_add( sprintf( 'Parrainage : invitation #%d refusée', $id ) );
	}
	delicat_back( 'parrainage', array(), array( $note ) );
} );

add_action( 'admin_post_delicat_ref_unblock', function () {
	delicat_guard( 'delicat_ref_unblock' );
	$uid = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $uid ) {
		delicat_ref_unblock( $uid );
		if ( function_exists( 'delicat_audit_add' ) ) delicat_audit_add( sprintf( 'Parrainage : client #%d débloqué', $uid ) );
	}
	delicat_back( 'parrainage', array(), array( 'Compte débloqué.' ) );
} );

add_action( 'admin_post_delicat_ref_release', function () {
	global $wpdb;
	delicat_guard( 'delicat_ref_release' );
	$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$t  = delicat_ref_table( 'claims' );
	$cl = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d AND status='pending'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $cl ) delicat_back( 'parrainage', array( 'Réclamation introuvable ou déjà traitée.' ) );
	if ( 'release' === $do ) {
		$wpdb->delete( $t, array( 'id' => $id, 'status' => 'pending' ) );
		$note = 'Palier libéré : le client peut le réclamer à nouveau.';
	} else {
		$wpdb->update( $t, array( 'status' => 'done' ), array( 'id' => $id, 'status' => 'pending' ) );
		$note = 'Palier marqué comme donné.';
	}
	if ( function_exists( 'delicat_audit_add' ) ) delicat_audit_add( sprintf( 'Parrainage : réclamation #%d (client #%d, palier %d) %s', $id, (int) $cl->user_id, (int) $cl->milestone, 'release' === $do ? 'libérée' : 'marquée donnée' ) );
	delicat_back( 'parrainage', array(), array( $note ) );
} );
