<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MonCash payment verification.
 *
 * Uses the MonCash Business API to verify a transaction by its
 * TransactionId (orderId). Credentials are set in plugin settings.
 *
 * MonCash Sandbox: https://sandbox.moncashbutton.digicelgroup.com
 * MonCash Live:    https://moncashbutton.digicelgroup.com
 *
 * Docs: https://documenter.getpostman.com/view/2781675/SzYUZg52
 */
class Delicat_MonCash {

    private static function client_id(): string {
        return get_option( 'delicat_moncash_client_id', '' );
    }

    private static function secret(): string {
        return get_option( 'delicat_moncash_secret', '' );
    }

    private static function is_sandbox(): bool {
        return (bool) get_option( 'delicat_moncash_sandbox', '1' );
    }

    private static function base(): string {
        return self::is_sandbox()
            ? 'https://sandbox.moncashbutton.digicelgroup.com'
            : 'https://moncashbutton.digicelgroup.com';
    }

    /**
     * Get an OAuth2 access token from MonCash.
     * Returns token string or WP_Error.
     */
    private static function get_token() {
        $cached = get_transient( 'delicat_moncash_token' );
        if ( $cached ) return $cached;

        $response = wp_remote_post( self::base() . '/Api/oauth/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( self::client_id() . ':' . self::secret() ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept'        => 'application/json',
            ],
            'body'    => 'grant_type=client_credentials&scope=read,write',
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $token = $body['access_token'] ?? '';

        if ( empty( $token ) ) {
            return new WP_Error( 'moncash_token_error', 'Could not get MonCash token: ' . ( $body['error_description'] ?? 'unknown' ) );
        }

        set_transient( 'delicat_moncash_token', $token, 55 * 60 ); // cache 55 min
        return $token;
    }

    /**
     * Verify a transaction by transactionId.
     *
     * Returns array with keys: success (bool), amount (float), message (string)
     */
    public static function verify_transaction( string $transaction_id, float $expected_amount ): array {
        if ( empty( self::client_id() ) || empty( self::secret() ) ) {
            return [
                'success' => false,
                'message' => 'MonCash API credentials not configured.',
                'amount'  => 0,
            ];
        }

        $token = self::get_token();
        if ( is_wp_error( $token ) ) {
            return [ 'success' => false, 'message' => $token->get_error_message(), 'amount' => 0 ];
        }

        $response = wp_remote_get(
            self::base() . '/Api/v1/RetrieveTransactionPayment?transactionId=' . urlencode( $transaction_id ),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message(), 'amount' => 0 ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['payment'] ) ) {
            return [
                'success' => false,
                'message' => 'Transaction introuvable sur MonCash. Code: ' . $code,
                'amount'  => 0,
            ];
        }

        $payment = $body['payment'];
        $paid    = floatval( $payment['amount'] ?? 0 );
        $status  = strtolower( $payment['message'] ?? '' );

        // Accept if status is "successful" and amount matches (allow 1 HTG tolerance for rounding)
        if ( $status !== 'successful' ) {
            return [
                'success' => false,
                'message' => 'Statut MonCash: ' . $payment['message'],
                'amount'  => $paid,
            ];
        }

        if ( abs( $paid - $expected_amount ) > 1 ) {
            return [
                'success' => false,
                'message' => sprintf( 'Montant incorrect: payé %.2f HTG, attendu %.2f HTG', $paid, $expected_amount ),
                'amount'  => $paid,
            ];
        }

        return [
            'success' => true,
            'message' => 'Transaction MonCash vérifiée avec succès.',
            'amount'  => $paid,
        ];
    }
}
