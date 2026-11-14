<?php
/** Purchase mappings survive supplier replacements; reward inventory stays separate. */
defined( 'ABSPATH' ) || exit;

function delicat_purchase_history() {
    $history = get_option( 'delicat_purchase_history', array() );
    return is_array( $history ) ? $history : array();
}

function delicat_purchase_history_remember( $cfg ) {
    if ( ! is_array( $cfg ) ) return;
    $history = delicat_purchase_history();
    $before = $history;
    foreach ( (array) ( $cfg['tiers'] ?? array() ) as $key => $tier ) {
        $key = sanitize_key( $key );
        $denom = (int) ( $tier['pin'] ?? 0 );
        if ( '' === $key || $denom < 1 ) continue;
        $ids = (array) ( $tier['ids'] ?? array() );
        $pin = (int) ( $cfg['pins'][ $denom ] ?? 0 );
        if ( ! $pin && ! empty( $cfg['slug_pattern'] ) ) {
            $pin = delicat_pid_by_slug( str_replace( '%d', (string) $denom, $cfg['slug_pattern'] ) );
        }
        if ( $pin > 0 ) $ids[] = $pin;
        $ids = array_merge( (array) ( $history[ $key ][ $denom ] ?? array() ), $ids );
        $history[ $key ][ $denom ] = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    }
    if ( $before !== $history ) update_option( 'delicat_purchase_history', $history, false );
}

// Also covers integrations which update the config directly instead of using the editor.
add_filter( 'pre_update_option_delicat_config', function ( $value, $old ) {
    delicat_purchase_history_remember( $old );
    delicat_purchase_history_remember( $value );
    return $value;
}, 10, 2 );

add_action( 'init', function () {
    if ( get_option( 'delicat_purchase_history_version', '' ) === '3.2.4' ) return;
    delicat_purchase_history_remember( delicat_cfg() );
    update_option( 'delicat_cfg_gen', delicat_cfg_gen() + 1 );
    delete_transient( 'delicat_leaderboard' );
    update_option( 'delicat_purchase_history_version', '3.2.4', false );
}, 100 );

/** Historical item names survive product deletion. Only unambiguous diamond packs qualify.
 * A name without a clear pack must be mapped by product/variation ID in the editor.
 * No fuzzy supplier IDs, prices, parent variation inference or client input is used.
 */
function delicat_item_pack_diamonds( $item ) {
    $name = method_exists( $item, 'get_name' ) ? wp_strip_all_tags( (string) $item->get_name() ) : '';
    if ( ! preg_match( '/\bfree[\s_-]*fire\b/i', $name ) ) return 0;
    if ( preg_match( '/\b(pass|membership|weekly|monthly|hebdomadaire|mensuel|abonnement)\b/i', $name ) ) return 0;
    if ( ! preg_match_all( '/(?<![\d.,])([1-9]\d{0,6})\s*(?:💎|diamants?\b|diamonds?\b)/iu', $name, $matches ) ) return 0;
    // Bonuses/compositions such as 100 + 10 diamonds need an explicit mapping.
    if ( preg_match( '/\d\s*\+\s*\d/', $name ) || count( $matches[1] ) !== 1 ) return 0;
    return (int) $matches[1][0];
}
