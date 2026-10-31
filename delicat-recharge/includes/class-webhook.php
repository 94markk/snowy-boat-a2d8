<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Delicat_Webhook {

    public static function send( object $record ): bool|WP_Error {
        $url = get_option( 'delicat_webhook_url', '' );
        if ( empty( $url ) ) return new WP_Error( 'no_url', 'Webhook URL not set.' );

        $payload = wp_json_encode( [
            'event'          => 'delicat.recharge.' . $record->statut,
            'id'             => (int) $record->id,
            'user_id'        => (int) $record->user_id,
            'user_email'     => $record->user_email,
            'tab'            => $record->tab,
            'methode'        => $record->methode,
            'montant'        => (float) $record->montant,
            'trans_code'     => $record->trans_code,
            'preuve_url'     => $record->preuve_url,
            'statut'         => $record->statut,
            'wallet_credited'=> (bool) $record->wallet_credited,
            'created_at'     => $record->created_at,
            'site_url'       => get_site_url(),
        ] );

        $headers = [ 'Content-Type' => 'application/json' ];
        $secret  = get_option( 'delicat_webhook_secret', '' );
        if ( $secret ) {
            $headers['X-Delicat-Signature'] = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
        }

        $response = wp_remote_post( $url, [
            'headers' => $headers,
            'body'    => $payload,
            'timeout' => 5,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[Delicat Recharge] Webhook error: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'http_error', 'Webhook HTTP ' . $code );
        }

        return true;
    }
}
