<?php
/**
 * Meilleur client periods and past winners, read by the Bonus page, the leaderboard and the app
 * contract. V10: split from competition-admin.php, whose period closing form and history screens
 * now load in wp-admin only (with rewards-admin.php).
 */
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
        if ( ! in_array( $p['status'] ?? '', array( 'manual', 'delivered', 'claimed' ), true ) || empty( $p['uid'] ) ) continue;
        $row = array( 'uid' => (int) $p['uid'], 'end' => (int) $key, 'prize' => (int) ( $p['prize'] ?? 0 ) );
        if ( isset( $p['amount_htg'] ) ) $row['amount_htg'] = (float) $p['amount_htg'];
        $out[] = $row;
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
        $items .= '<li style="padding:10px 0">🏆 <strong>' . esc_html( $name ) . '</strong> · ' . esc_html( wp_date( 'd/m/Y', $w['end'] ) ) . ' · ' . esc_html( isset( $w['amount_htg'] ) ? delicat_reward_money( $w['amount_htg'] ) : $w['prize'] . ' 💎' ) . ' — Récompense remise</li>';
    }
    return '<section aria-label="Anciens gagnants" style="padding:18px;margin:14px 0;border:1px solid #d9dce5;border-radius:14px"><h2 style="font-size:18px;margin:0 0 8px">Les derniers gagnants</h2>' . ( $items ? '<ul style="list-style:none;padding:0;margin:0">' . $items . '</ul>' : '<p>Le prochain gagnant récompensé apparaîtra ici.</p>' ) . '</section>';
}
