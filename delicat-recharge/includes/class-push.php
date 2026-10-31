<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DELICAT NOTIFICATION — Firebase Cloud Messaging push sender.
 *
 * Sends a push to your Android app ("Delicat Notification") whenever:
 *   - a client submits a new recharge request
 *   - a recharge is approved (wallet credited)
 *
 * Uses Firebase HTTP v1 API with a service-account JSON (modern, secure).
 * You paste two things in Delicat Console → Paramètres:
 *   1. The device token (shown in the Android app)
 *   2. The Firebase service-account JSON (from Firebase console)
 *
 * SECURITY: credentials stored in WP options (admin-only), sent over HTTPS,
 * never exposed to the front-end.
 */
class Delicat_Push {

    /**
     * Send a push notification to the configured device.
     *
     * @param string $title  Notification title.
     * @param string $body   Notification body.
     * @param string $tap_url Optional URL to open when the alert is tapped.
     * @param bool   $urgent  If true, routes to the loud "urgent" channel.
     */
    public static function send( string $title, string $body, string $tap_url = '', bool $urgent = false ): void {
        $token_raw = trim( (string) get_option( 'delicat_fcm_device_token', '' ) );
        $json      = trim( (string) get_option( 'delicat_fcm_service_account', '' ) );

        if ( $token_raw === '' || $json === '' ) {
            return; // Not configured yet — silently skip.
        }

        // Multi-device: tokens may be separated by newlines, commas, or spaces.
        $tokens = preg_split( '/[\s,]+/', $token_raw, -1, PREG_SPLIT_NO_EMPTY );
        if ( empty( $tokens ) ) {
            return;
        }

        $sa = json_decode( $json, true );
        if ( ! is_array( $sa ) || empty( $sa['project_id'] ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
            error_log( '[Delicat Push] Invalid service account JSON.' );
            return;
        }

        $access_token = self::get_access_token( $sa );
        if ( ! $access_token ) {
            return;
        }

        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode( $sa['project_id'] ) . '/messages:send';

        $channel = $urgent ? 'delicat_recharge_urgent' : 'delicat_recharge_channel';

        $data = [
            'title'  => $title,
            'body'   => $body,
            'urgent' => $urgent ? '1' : '0',
        ];
        if ( $tap_url !== '' ) {
            $data['url'] = $tap_url;
        }

        // Send to every registered device.
        foreach ( $tokens as $token ) {
            $payload = [
                'message' => [
                    'token'        => $token,
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                    ],
                    'android' => [
                        'priority'     => 'high',
                        'notification' => [
                            'channel_id' => $channel,
                            'sound'      => 'default',
                        ],
                    ],
                    'data' => $data,
                ],
            ];

            wp_remote_post( $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 6,
            ] );
        }
    }

    /**
     * Send a push to ONE specific token (used by the HQ relay for client sites).
     * Returns true on a 2xx from FCM.
     */
    public static function send_to_token( string $token, string $title, string $body, string $tap_url = '', bool $urgent = false ): bool {
        $json = trim( (string) get_option( 'delicat_fcm_service_account', '' ) );
        if ( $token === '' || $json === '' ) return false;
        $sa = json_decode( $json, true );
        if ( ! is_array( $sa ) || empty( $sa['project_id'] ) ) return false;

        $access_token = self::get_access_token( $sa );
        if ( ! $access_token ) return false;

        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode( $sa['project_id'] ) . '/messages:send';
        $channel = $urgent ? 'delicat_recharge_urgent' : 'delicat_recharge_channel';
        $data = [ 'title' => $title, 'body' => $body, 'urgent' => $urgent ? '1' : '0' ];
        if ( $tap_url !== '' ) $data['url'] = $tap_url;

        $payload = [ 'message' => [
            'token'        => $token,
            'notification' => [ 'title' => $title, 'body' => $body ],
            'android'      => [ 'priority' => 'high', 'notification' => [ 'channel_id' => $channel, 'sound' => 'default' ] ],
            'data'         => $data,
        ] ];

        $resp = wp_remote_post( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 6,
        ] );
        if ( is_wp_error( $resp ) ) return false;
        $code = (int) wp_remote_retrieve_response_code( $resp );
        return $code >= 200 && $code < 300;
    }

    /**
     * Exchange the service account for a short-lived OAuth2 access token.
     * Cached for ~55 minutes to avoid re-signing on every push.
     */
    private static function get_access_token( array $sa ): ?string {
        $cached = get_transient( 'delicat_fcm_access_token' );
        if ( $cached ) return $cached;

        $now    = time();
        $header = self::b64( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $claim  = self::b64( wp_json_encode( [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ] ) );

        $signing_input = $header . '.' . $claim;
        $signature = '';
        $ok = openssl_sign( $signing_input, $signature, $sa['private_key'], 'SHA256' );
        if ( ! $ok ) {
            error_log( '[Delicat Push] JWT signing failed.' );
            return null;
        }
        $jwt = $signing_input . '.' . self::b64( $signature );

        $resp = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
            'timeout' => 8,
        ] );

        if ( is_wp_error( $resp ) ) {
            error_log( '[Delicat Push] Token request failed: ' . $resp->get_error_message() );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        $access = $data['access_token'] ?? '';
        if ( ! $access ) {
            error_log( '[Delicat Push] No access token in response.' );
            return null;
        }

        set_transient( 'delicat_fcm_access_token', $access, 55 * MINUTE_IN_SECONDS );
        return $access;
    }

    /** URL-safe base64 (no padding) for JWT. */
    private static function b64( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /* ---- Convenience wrappers used by the recharge flows ------------- */

    /** Amount (HTG) at or above which a recharge is flagged "urgent" (loud alarm). */
    const URGENT_THRESHOLD = 5000;

    public static function notify_new_request( object $r ): void {
        $title = '🔔 Nouvelle recharge';
        $body  = sprintf(
            "%s — %s HTG via %s\nClient: %s",
            'Demande #' . $r->id,
            number_format( (float) $r->montant, 2 ),
            strtoupper( $r->tab ),
            $r->user_email
        );
        $url    = admin_url( 'admin.php?page=delicat-recharges&highlight=' . (int) $r->id );
        $urgent = (float) $r->montant >= self::URGENT_THRESHOLD;

        // Client site (under a Délicat license) → send via HQ relay.
        if ( class_exists( 'Delicat_Client_License' ) && Delicat_Client_License::is_configured() ) {
            Delicat_Client_License::relay_push( $title, $body );
            return;
        }
        // HQ site → send directly via own Firebase.
        self::send( $title, $body, $url, $urgent );
    }

    public static function notify_approved( object $r ): void {
        $title = '✅ Wallet rechargé';
        $body  = sprintf(
            "%s HTG crédités à %s (demande #%d)",
            number_format( (float) $r->montant, 2 ),
            $r->user_email,
            $r->id
        );
        $url = admin_url( 'admin.php?page=delicat-recharges' );
        self::send( $title, $body, $url, false );
    }
}
