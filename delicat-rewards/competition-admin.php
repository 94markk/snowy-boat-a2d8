<?php
/** Meilleur client lifecycle and delivery records. */
if ( ! defined( 'ABSPATH' ) ) exit;

function delicat_competition_start() {
    return max( delicat_week_start(), (int) get_option( 'delicat_competition_cutoff', 0 ) );
}

function delicat_closed_period_start( $boundary ) {
    $start = delicat_previous_week_start( $boundary );
    $cut = 0;
    // Cutoffs are keyed by the start of the week they closed. A daylight-saving change moves
    // that key by an hour against today's arithmetic, so match within two hours.
    foreach ( (array) get_option( 'delicat_competition_cutoffs', array() ) as $week => $end ) {
        if ( abs( (int) $week - $start ) <= 2 * HOUR_IN_SECONDS && (int) $end < (int) $boundary ) $cut = max( $cut, (int) $end );
    }
    return max( $start, $cut );
}

function delicat_competition_snapshot( $start, $end, $reset = false ) {
    $rows = delicat_collect_leaderboard_rows( $start, $end - 1 );
    $week = array( 'start' => $start, 'end' => $end, 'ranking' => array(), 'placements' => array(), 'complete' => $reset ? 1 : 0, 'reason' => $reset ? 'reset_without_award' : 'closed_by_admin', 'updated' => current_time( 'mysql' ) );
    if ( $reset ) return $week;
    $prizes = delicat_meilleur_prizes(); $rank = 0;
    foreach ( $rows as $uid => $row ) {
        if ( ++$rank > 3 ) break;
        $week['ranking'][ $rank ] = (int) $uid;
        $week['placements'][ $rank ] = array( 'uid' => (int) $uid, 'prize' => $prizes[ $rank ], 'oid' => 0, 'status' => $prizes[ $rank ] > 0 ? 'pending_manual' : 'disabled' );
    }
    return $week;
}

add_action( 'admin_post_delicat_competition', function () {
    delicat_guard( 'delicat_competition' );
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( 'POST requis.' );
    $in = wp_unslash( $_POST );
    $op = sanitize_key( $in['operation'] ?? '' );
    $lock = 'delicat_competition_control';
    if ( ! delicat_lock( $lock, 3 ) ) delicat_back( 'meilleur', array( 'Une opération est en cours. Réessayez.' ) );
    $errors = array(); $notes = array();
    try {
        // Week keys must be real timestamps before any period is written (see delicat_week_utc_ready).
        if ( ! delicat_week_utc_ready() ) throw new Exception( 'Mise à jour du calendrier en cours. Réessayez dans une minute.' );
        $all = delicat_weekly_state_get();
        if ( in_array( $op, array( 'close', 'reset' ), true ) ) {
            if ( (string) ( $in['confirm'] ?? '' ) !== 'RECOMMENCER' ) throw new Exception( 'Saisissez RECOMMENCER pour confirmer.' );
            $start = delicat_competition_start(); $end = time(); // real timestamp, compared with UTC order dates
            if ( (int) ( $in['period_start'] ?? 0 ) !== $start || $end <= $start || isset( $all[ $end ] ) ) throw new Exception( 'La période a changé. Rechargez la page.' );
            $all[ $end ] = delicat_competition_snapshot( $start, $end, 'reset' === $op );
            delicat_weekly_state_save( $all );
            update_option( 'delicat_competition_cutoff', $end, false );
            // Retain cutoffs per calendar week so a reset cannot erase an unpaid previous week.
            $cutoffs = get_option( 'delicat_competition_cutoffs', array() );
            $cutoffs[ delicat_week_start() ] = $end;
            update_option( 'delicat_competition_cutoffs', $cutoffs, false );
            delete_transient( 'delicat_leaderboard' );
            delicat_audit_add( 'Meilleur client : ' . $op . ', période ' . $start . ' à ' . $end . ', administrateur #' . get_current_user_id() );
            $notes[] = 'Nouvelle période démarrée. Les bonus mensuels et les anciennes récompenses sont conservés.';
        } elseif ( 'previous' === $op ) {
            $end = delicat_week_start();
            if ( ! isset( $all[ $end ] ) ) {
                $start = delicat_closed_period_start( $end );
                if ( $start >= $end ) throw new Exception( 'Cette période a déjà été clôturée ou réinitialisée.' );
                $all[ $end ] = delicat_competition_snapshot( $start, $end );
                delicat_weekly_state_save( $all );
                delicat_audit_add( 'Résultats de la semaine précédente figés pour remise manuelle.' );
            }
            $notes[] = 'Résultats disponibles dans l’historique.';
        } elseif ( 'manual' === $op ) {
            $key = (int) ( $in['period'] ?? 0 ); $rank = (int) ( $in['rank'] ?? 0 );
            $p = $all[ $key ]['placements'][ $rank ] ?? array();
            if ( ! in_array( $p['status'] ?? '', array( 'pending_manual', 'failed_retryable' ), true ) || empty( $p['uid'] ) || empty( $p['prize'] ) ) throw new Exception( 'Récompense absente ou déjà traitée.' );
            if ( empty( $in['delivered_confirm'] ) ) throw new Exception( 'Confirmez que le client a déjà reçu sa récompense.' );
            $reference = sanitize_textarea_field( (string) ( $in['reference'] ?? '' ) );
            if ( '' === trim( $reference ) || strlen( $reference ) > 500 ) throw new Exception( 'Ajoutez une référence de remise (500 caractères maximum).' );
            if ( delicat_existing_weekly_order( $key, $rank ) ) throw new Exception( 'Une commande automatique existe déjà. Vérifiez sa livraison avant toute remise manuelle.' );
            $p['status'] = 'manual'; $p['reference'] = $reference; $p['delivered_at'] = current_time( 'mysql' ); $p['admin_id'] = get_current_user_id();
            $all[ $key ]['placements'][ $rank ] = $p;
            $all[ $key ]['complete'] = 1;
            foreach ( $all[ $key ]['placements'] as $placement ) {
                if ( in_array( $placement['status'], array( 'pending_manual', 'failed_retryable' ), true ) ) $all[ $key ]['complete'] = 0;
            }
            delicat_weekly_state_save( $all );
            delicat_audit_add( 'Récompense Meilleur client remise manuellement : période ' . $key . ', place ' . $rank . ', client #' . $p['uid'] . ', administrateur #' . get_current_user_id() );
            $notes[] = 'Remise enregistrée. Aucune nouvelle commande ni recharge créée.';
        } elseif ( 'verified' === $op ) { // 3.4.2: the team has checked a podium changed by a refund
            $key = (int) ( $in['period'] ?? 0 );
            if ( empty( $all[ $key ]['recheck'] ) ) throw new Exception( 'Rien à vérifier pour cette période.' );
            foreach ( (array) ( $all[ $key ]['placements'] ?? array() ) as $r => $placement ) unset( $all[ $key ]['placements'][ $r ]['verify'] );
            $all[ $key ]['recheck']['checked'] = current_time( 'mysql' ) . ' — admin #' . get_current_user_id();
            delicat_weekly_state_save( $all );
            delicat_audit_add( 'Meilleur client : podium recalculé de la période ' . $key . ' marqué vérifié, administrateur #' . get_current_user_id() );
            $notes[] = 'Période marquée vérifiée. Un prix encore à livrer reprend la livraison automatique (au gagnant d’origine) ; utilisez « Remise manuelle » si vous l’avez remis autrement.';
        } elseif ( 'history' === $op ) {
            update_option( 'delicat_winners_visible', empty( $in['visible'] ) ? '0' : '1', false );
            delicat_audit_add( 'Visibilité de l’historique Meilleur client modifiée.' );
            $notes[] = 'Visibilité enregistrée.';
        } else throw new Exception( 'Action inconnue.' );
    } catch ( Throwable $e ) { $errors[] = $e->getMessage(); }
    finally { delicat_unlock( $lock ); }
    delicat_back( 'meilleur', $errors, $notes );
} );

function delicat_competition_form( $op ) {
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    wp_nonce_field( 'delicat_competition' );
    echo '<input type="hidden" name="action" value="delicat_competition"><input type="hidden" name="operation" value="' . esc_attr( $op ) . '">';
}

function delicat_tab_competition() {
    $start = delicat_competition_start();
    echo '<div class="box"><h2>Meilleur client — Gestion des périodes</h2><p>Début du classement actuel : <b>' . esc_html( wp_date( 'd/m/Y H:i:s', $start ) ) . '</b> (heure du site). Le classement reste hebdomadaire après un redémarrage.</p><p>Les bonus mensuels du Rewards Program sont indépendants de ces contrôles. Une pause masque le programme et bloque la livraison automatique ; les achats éligibles restent comptés.</p>';
    echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=delicat-participants' ) ) . '">Classement et participants</a></p>';
    delicat_competition_form( 'previous' );
    echo '<button class="button">Préparer les résultats de la semaine précédente</button></form></div>';
    echo '<div class="box danger"><h3>Recommencer le classement</h3><p><b>Clôturer</b> conserve le podium avec les récompenses à remettre manuellement. <b>Réinitialiser</b> abandonne la période sans désigner de gagnant. Aucune commande, aucun bonus mensuel ni ancien historique n’est supprimé.</p>';
    foreach ( array( 'close' => 'Clôturer et redémarrer', 'reset' => 'Réinitialiser sans gagnant' ) as $op => $label ) {
        delicat_competition_form( $op );
        echo '<input type="hidden" name="period_start" value="' . (int) $start . '"><p><label>Confirmation : <input name="confirm" required placeholder="RECOMMENCER" autocomplete="off" pattern="RECOMMENCER"></label> <button class="button">' . esc_html( $label ) . '</button></p></form>';
    }
    echo '</div><div class="box"><h3>Anciens gagnants — affichage client</h3>';
    delicat_competition_form( 'history' );
    echo '<p><label><input type="checkbox" name="visible" value="1"' . checked( get_option( 'delicat_winners_visible', '1' ), '1', false ) . '> Afficher les 5 derniers gagnants récompensés sur la page Meilleur client</label></p><p>Nom abrégé uniquement, sans email, montant dépensé ou référence de remise.</p><button class="button">Enregistrer</button></form></div>';
    echo '<div class="box"><h3>Historique et remises</h3><p>« Remise manuelle » confirme une récompense déjà donnée hors du plugin. Cette action ne livre rien et empêche une seconde livraison automatique.</p><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Fin de période</th><th>Place / client</th><th>Prix</th><th>État / action</th></tr></thead><tbody>';
    $all = delicat_weekly_state_get(); krsort( $all, SORT_NUMERIC );
    $page = max( 1, (int) ( $_GET['history_page'] ?? 1 ) ); $total = count( $all );
    foreach ( array_slice( $all, ( $page - 1 ) * 20, 20, true ) as $key => $week ) {
        if ( empty( $week['placements'] ) ) echo '<tr><td>' . esc_html( wp_date( 'd/m/Y H:i', (int) $key ) ) . '</td><td colspan="3">Période sans gagnant</td></tr>';
        if ( ! empty( $week['recheck']['new'] ) && empty( $week['recheck']['checked'] ) ) { // 3.4.2 (RWD-02)
            $names = array();
            foreach ( (array) $week['recheck']['new'] as $r => $uid ) $names[] = '#' . (int) $r . ' ' . ( $uid ? delicat_display_name( (int) $uid ) . ' · client #' . (int) $uid : '—' );
            echo '<tr style="background:#fff8e5"><td>' . esc_html( wp_date( 'd/m/Y H:i', (int) $key ) ) . '</td><td colspan="3"><b>⚠️ À vérifier :</b> ' . esc_html( 'après un remboursement' . ( ! empty( $week['recheck']['orders'] ) ? ' (commande #' . implode( ', #', array_map( 'intval', (array) $week['recheck']['orders'] ) ) . ')' : '' ) . ', le podium recalculé le ' . $week['recheck']['at'] . ' est : ' . implode( ' ; ', $names ) . '. Aucun prix n’a été repris automatiquement.' );
            delicat_competition_form( 'verified' );
            echo '<input type="hidden" name="period" value="' . (int) $key . '"> <button class="button">Marquer vérifié</button></form></td></tr>';
        }
        foreach ( $week['placements'] ?? array() as $rank => $p ) {
            $labels = array( 'manual' => 'Remise manuelle confirmée', 'delivered' => 'Livraison automatique enregistrée', 'pending_manual' => 'À remettre', 'failed_retryable' => 'Échec automatique — à vérifier', 'disabled' => 'Prix désactivé', 'no_participant' => 'Aucun participant' );
            echo '<tr><td>' . esc_html( wp_date( 'd/m/Y H:i', (int) $key ) ) . '</td><td>#' . (int) $rank . ' — ' . esc_html( empty( $p['uid'] ) ? '—' : delicat_display_name( $p['uid'] ) . ' · client #' . (int) $p['uid'] ) . '</td><td>' . (int) ( $p['prize'] ?? 0 ) . ' 💎</td><td>' . esc_html( $labels[ $p['status'] ?? '' ] ?? 'À vérifier' ) . ( ! empty( $p['verify'] ) ? ' — <b style="color:#b32d2e">à vérifier (podium changé par un remboursement)</b>' : '' );
            if ( ! empty( $p['reference'] ) ) echo '<p>' . esc_html( $p['reference'] . ' — ' . $p['delivered_at'] . ' — admin #' . $p['admin_id'] ) . '</p>';
            if ( in_array( $p['status'] ?? '', array( 'pending_manual', 'failed_retryable' ), true ) ) {
                delicat_competition_form( 'manual' );
                echo '<input type="hidden" name="period" value="' . (int) $key . '"><input type="hidden" name="rank" value="' . (int) $rank . '"><p><input name="reference" required maxlength="500" placeholder="Référence / note de remise"></p><p><label><input type="checkbox" name="delivered_confirm" value="1" required> Le client a déjà reçu ce prix</label></p><button class="button">Enregistrer la remise manuelle</button></form>';
            }
            echo '</td></tr>';
        }
    }
    if ( ! $all ) echo '<tr><td colspan="4">Aucune période archivée pour le moment.</td></tr>';
    echo '</tbody></table></div>';
    for ( $i = 1; $i <= (int) ceil( $total / 20 ); $i++ ) echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'delicat-rewards', 'tab' => 'meilleur', 'history_page' => $i ), admin_url( 'admin.php' ) ) ) . '">' . (int) $i . '</a> ';
    echo '</div><div class="box"><h3>Recommandations</h3><ul><li>Vérifiez le podium et les remboursements avant de remettre les prix.</li><li>Gardez une référence pour chaque remise manuelle.</li><li>Configurez les prix et vérifiez le stock de PIN avant d’activer la livraison automatique.</li><li>Évitez de changer les règles de classement en cours de période.</li><li>Utilisez une tâche cron serveur régulière pour fiabiliser la clôture hebdomadaire.</li></ul></div>';
}

/**
 * The last rewarded 1st places, newest first, while the owner shows them (« Anciens
 * gagnants »): [ ['uid', 'end' (period end), 'prize'] ]. Shared by the page and the app
 * contract; the uid stays on the server, only a masked name is ever shown.
 */
function delicat_recent_winners( $limit = 5 ) {
    if ( '1' !== get_option( 'delicat_winners_visible', '1' ) ) return array();
    $all = delicat_weekly_state_get(); krsort( $all, SORT_NUMERIC ); $out = array();
    foreach ( $all as $key => $week ) {
        $p = $week['placements'][1] ?? array();
        if ( ! in_array( $p['status'] ?? '', array( 'manual', 'delivered' ), true ) || empty( $p['uid'] ) ) continue;
        $out[] = array( 'uid' => (int) $p['uid'], 'end' => (int) $key, 'prize' => (int) ( $p['prize'] ?? 0 ) );
        if ( count( $out ) >= (int) $limit ) break;
    }
    return $out;
}

function delicat_winners_html() {
    if ( '1' !== get_option( 'delicat_winners_visible', '1' ) ) return '';
    $items = '';
    foreach ( delicat_recent_winners( 5 ) as $w ) {
        // Always masked, same rule as the live board: never a login, e-mail part or full name.
        $name = delicat_display_name( $w['uid'], true );
        $items .= '<li style="padding:10px 0">🏆 <strong>' . esc_html( $name ) . '</strong> · ' . esc_html( wp_date( 'd/m/Y', $w['end'] ) ) . ' · ' . $w['prize'] . ' 💎 — Récompense remise</li>';
    }
    return '<section aria-label="Anciens gagnants" style="padding:18px;margin:14px 0;border:1px solid #d9dce5;border-radius:14px"><h2 style="font-size:18px;margin:0 0 8px">Les derniers gagnants</h2>' . ( $items ? '<ul style="list-style:none;padding:0;margin:0">' . $items . '</ul>' : '<p>Le prochain gagnant récompensé apparaîtra ici.</p>' ) . '</section>';
}
