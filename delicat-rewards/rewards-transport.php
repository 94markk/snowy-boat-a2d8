<?php
/** Restricted same-origin transport when a host blocks the plugin's REST URL. */
defined( 'ABSPATH' ) || exit;

function delicat_rewards_transport() {
    nocache_headers();
    if ( ! headers_sent() ) header( 'Cache-Control: no-store, private, max-age=0' );
    if ( ! is_user_logged_in() || ! get_userdata( get_current_user_id() ) ) {
        wp_send_json( array( 'code' => 'auth', 'message' => 'Connectez-vous.' ), 401 );
    }
    $nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? wp_unslash( $_POST['nonce'] ) : '';
    if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
        wp_send_json( array( 'code' => 'rest_cookie_invalid_nonce', 'message' => 'Actualisez votre session.' ), 403 );
    }
    $path = isset( $_POST['path'] ) && is_string( $_POST['path'] ) ? wp_unslash( $_POST['path'] ) : '';
    $method = isset( $_POST['method'] ) && is_string( $_POST['method'] ) ? strtoupper( wp_unslash( $_POST['method'] ) ) : 'GET';
    $routes = array( '/rewards' => 'GET', '/reward-pins' => 'GET', '/leaderboard' => 'GET', '/claim' => 'POST', '/referral' => 'GET', '/referral/claim' => 'POST', '/referral/code' => 'POST' );
    if ( ! isset( $routes[ $path ] ) || $method !== $routes[ $path ] ) {
        wp_send_json( array( 'code' => 'invalid_route', 'message' => 'Action non autorisée.' ), 400 );
    }
    $raw = isset( $_POST['params'] ) && is_string( $_POST['params'] ) ? wp_unslash( $_POST['params'] ) : '{}';
    if ( strlen( $raw ) > 2048 ) wp_send_json( array( 'code' => 'invalid_params' ), 400 );
    $params = json_decode( $raw, true );
    if ( ! is_array( $params ) ) wp_send_json( array( 'code' => 'invalid_params' ), 400 );
    $allowed = array( '/claim' => array( 'tier', 'token' ), '/reward-pins' => array( 'order_id', 'page' ), '/referral/claim' => array( 'milestone', 'choice' ), '/referral/code' => array( 'code', 'fp' ) );
    $allowed = $allowed[ $path ] ?? array();
    if ( array_diff( array_keys( $params ), $allowed ) ) wp_send_json( array( 'code' => 'invalid_params' ), 400 );
    $request = new WP_REST_Request( $method, '/delicat/v1' . $path );
    if ( 'POST' === $method ) $request->set_body_params( $params );
    else $request->set_query_params( $params );
    // Internal dispatch still runs registered argument validation and permission callbacks.
    // The cookie + CSRF nonce above authenticate this AJAX request; claims retain their
    // signed token, ledger, rate limits, stock checks, locks and exact-retry behavior.
    $response = rest_do_request( $request );
    wp_send_json( $response->get_data(), $response->get_status() );
}
add_action( 'wp_ajax_delicat_rewards_transport', 'delicat_rewards_transport' );
add_action( 'wp_ajax_nopriv_delicat_rewards_transport', 'delicat_rewards_transport' );
