<?php
defined( 'ABSPATH' ) || exit;

// Refresh an expired REST nonce using the authenticated WordPress cookie only.
add_action( 'wp_ajax_delicat_rewards_session', function () {
    if ( ! is_user_logged_in() ) wp_send_json_error( array( 'message' => 'Connectez-vous.' ), 401 );
    nocache_headers();
    wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ), 'userId' => get_current_user_id() ) );
} );

add_action( 'wp_ajax_nopriv_delicat_rewards_session', function () {
    nocache_headers();
    wp_send_json_error( array( 'message' => 'Connectez-vous.', 'userId' => 0 ), 401 );
} );

/**
 * The pre-3.3 « Mes récompenses » page. Since 3.3.0 [delicat_rewards] opens the Bonus Free Fire page on
 * « Mes bonus »; 4.1.0 does the same for a theme or template that still calls this function, and its old
 * files (assets/rewards.js, assets/rewards.css: PIN-only, before the HTG wallet) are removed.
 */
function delicat_rewards_view() {
    return function_exists( 'delicat_bonus_page' ) ? delicat_bonus_page( array( 'tab' => 'bonus' ) ) : '';
}
