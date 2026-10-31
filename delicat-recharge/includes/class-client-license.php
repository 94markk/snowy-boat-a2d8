<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CLIENT-SIDE LICENSE (runs on a CLIENT's site).
 *
 * A client site stores its own license (key + sign_key + HQ url + domain).
 * Before serving app data, the plugin verifies the license against HQ.
 * Result is cached for 6h so HQ isn't hit on every request.
 *
 * This is what lets the app use ONE credential set: the license's sign_key
 * is also the HMAC key the app uses to sign data/action requests to THIS site.
 *
 * Settings (option 'delicat_client_license'):
 *   [ 'hq'=>'https://delicastoreha.com', 'key'=>.., 'sign_key'=>.., 'domain'=>.. ]
 */
class Delicat_Client_License {

    const OPT   = 'delicat_client_license';
    const CACHE = 'delicat_license_cache'; // transient

    public static function config(): array {
        $c = get_option( self::OPT, [] );
        return is_array( $c ) ? $c : [];
    }

    public static function is_configured(): bool {
        $c = self::config();
        return ! empty( $c['key'] ) && ! empty( $c['sign_key'] );
    }

    /** The HMAC key the app shares with this site = the license sign_key. */
    public static function sign_key(): string {
        $c = self::config();
        return (string) ( $c['sign_key'] ?? '' );
    }

    /**
     * Is the license currently valid? Cached 6h. On network failure, falls back
     * to the last known good result (so a brief HQ outage doesn't lock the client).
     */
    public static function valid(): bool {
        if ( ! self::is_configured() ) return false;

        $cache = get_transient( self::CACHE );
        if ( is_array( $cache ) && isset( $cache['valid'], $cache['until'] )
             && $cache['until'] > time() ) {
            return (bool) $cache['valid'];
        }

        $c = self::config();
        $hq     = rtrim( (string) $c['hq'], '/' );
        $key    = (string) $c['key'];
        $domain = (string) $c['domain'];
        $ts     = time();
        $sig    = hash_hmac( 'sha256', $key . '|' . $domain . '|' . $ts, $c['sign_key'] );

        $url = add_query_arg( [
            'key'    => $key,
            'domain' => $domain,
            'ts'     => $ts,
            'sig'    => $sig,
        ], $hq . '/wp-json/delicat/v1/license/check' );

        $resp = wp_remote_get( $url, [ 'timeout' => 8 ] );
        if ( is_wp_error( $resp ) ) {
            // Network issue: keep last known good for a short grace, else deny.
            $last = get_option( 'delicat_license_last_good', 0 );
            return ( $last && ( time() - $last ) < DAY_IN_SECONDS );
        }

        $body  = json_decode( wp_remote_retrieve_body( $resp ), true );
        $valid = is_array( $body ) && ! empty( $body['valid'] );

        set_transient( self::CACHE, [ 'valid' => $valid, 'until' => time() + 6 * HOUR_IN_SECONDS ], 6 * HOUR_IN_SECONDS );
        if ( $valid ) update_option( 'delicat_license_last_good', time(), false );

        return $valid;
    }

    /** Verify an app request signed with the license sign_key. */
    public static function verify_request_sig( array $p ): bool {
        $key = self::sign_key();
        if ( $key === '' ) return false;
        $ts = (int) ( $p['ts'] ?? 0 );
        if ( abs( time() - $ts ) > 300 ) return false;
        $base = ( $p['action'] ?? 'list' ) . '|' . ( $p['id'] ?? '0' ) . '|' . $ts;
        $expected = hash_hmac( 'sha256', $base, $key );
        return hash_equals( $expected, (string) ( $p['sig'] ?? '' ) );
    }

    /**
     * Send a push notification through the HQ relay (for client sites).
     * Called when a new recharge arrives. HQ sends via its Firebase.
     */
    public static function relay_push( string $title, string $body ): void {
        if ( ! self::is_configured() ) return;
        $cache = get_transient( self::CACHE );
        $cached_valid = is_array( $cache ) ? ! empty( $cache['valid'] ) : true;
        if ( ! $cached_valid ) return;

        $c = self::config();
        $tokens_raw = trim( (string) get_option( 'delicat_fcm_device_token', '' ) );
        if ( $tokens_raw === '' ) return;
        $tokens = preg_split( '/[\s,]+/', $tokens_raw, -1, PREG_SPLIT_NO_EMPTY );

        $hq  = rtrim( (string) $c['hq'], '/' );
        $key = (string) $c['key'];
        $sign_key = (string) $c['sign_key'];

        // Defer the actual HTTP send to 'shutdown' (after the response is sent to
        // the customer). The request itself is BLOCKING so it reliably completes
        // on shared hosting — but because it runs on shutdown, the customer never
        // waits for it. Best of both: reliable AND no page slowdown.
        add_action( 'shutdown', function () use ( $hq, $key, $sign_key, $tokens, $title, $body ) {
            if ( function_exists( 'fastcgi_finish_request' ) ) {
                fastcgi_finish_request();
            }
            foreach ( $tokens as $token ) {
                $ts  = time();
                $sig = hash_hmac( 'sha256', $key . '|' . $token . '|' . $ts, $sign_key );
                wp_remote_post( $hq . '/wp-json/delicat/v1/license/push', [
                    'blocking' => true,
                    'timeout'  => 8,
                    'body'     => [
                        'key'          => $key,
                        'device_token' => $token,
                        'title'        => $title,
                        'body'         => $body,
                        'ts'           => $ts,
                        'sig'          => $sig,
                    ],
                ] );
            }
        }, 20 );
    }

    /**
     * Test the relay synchronously and return a human-readable result.
     */
    public static function test_relay(): string {
        if ( ! self::is_configured() ) return '❌ Licence non configurée.';
        $c = self::config();
        $tokens_raw = trim( (string) get_option( 'delicat_fcm_device_token', '' ) );
        if ( $tokens_raw === '' ) return '❌ Aucun token d\'appareil (Notification Android).';
        $tokens = preg_split( '/[\s,]+/', $tokens_raw, -1, PREG_SPLIT_NO_EMPTY );

        $hq    = rtrim( (string) $c['hq'], '/' );
        $key   = (string) $c['key'];
        $token = $tokens[0];
        $ts    = time();
        $sig   = hash_hmac( 'sha256', $key . '|' . $token . '|' . $ts, $c['sign_key'] );

        $resp = wp_remote_post( $hq . '/wp-json/delicat/v1/license/push', [
            'blocking' => true,
            'timeout'  => 12,
            'body'     => [
                'key' => $key, 'device_token' => $token,
                'title' => '🔔 Test Délicat', 'body' => 'Notification de test.',
                'ts' => $ts, 'sig' => $sig,
            ],
        ] );

        if ( is_wp_error( $resp ) ) {
            return '❌ Impossible de joindre HQ : ' . $resp->get_error_message();
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );

        if ( $code === 200 && is_array( $data ) && ! empty( $data['sent'] ) ) {
            return '✅ Envoyé ! Vérifiez le téléphone.';
        }
        if ( $code === 403 ) {
            return '❌ HQ a refusé (403) : ' . ( is_array($data) && isset($data['message']) ? $data['message'] : 'licence/signature' );
        }
        if ( $code === 200 && is_array( $data ) && empty( $data['sent'] ) ) {
            return '⚠️ HQ accepté mais Firebase n\'a pas envoyé. Vérifiez le token ou le Service Account côté HQ.';
        }
        return '❌ Réponse inattendue (' . $code . ') : ' . substr( (string) $body, 0, 200 );
    }
}
