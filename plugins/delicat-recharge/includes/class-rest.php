<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DELICAT REST API — read-only dashboard data for the Android app.
 *
 * Endpoint: GET /wp-json/delicat/v1/stats  (header X-Delicat-Stats-Key: SECRET)
 *
 * Auth: a SECRET KEY sent in the X-Delicat-Stats-Key header (6.6.5: header
 *       only; ?key= in the URL is refused), compared against the
 *       option 'delicat_dashboard_key'. This deliberately avoids HTTP
 *       Basic auth / Application Passwords, because some hosts (LiteSpeed,
 *       Hostinger) strip the Authorization header and break login.
 *
 * The key is generated automatically and shown in
 * Delicat Console -> Parametres -> Notification Android.
 *
 * Security notes:
 *  - Read-only: returns only aggregate stats + recent rows (no actions).
 *  - Constant-time key comparison (hash_equals) to prevent timing attacks.
 *  - HTTPS required to keep the key private in transit (your site is HTTPS).
 *  - Key is revocable: regenerate it any time to instantly cut off old apps.
 *  - The legacy manage_options /dashboard route is kept for anyone who had
 *    Application Passwords working.
 */
class Delicat_Rest {

    const KEY_OPTION = 'delicat_dashboard_key';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
        // 6.5.2: no longer on every request (one uncached option read each
        // time) - get_key() creates the key the first time it is needed.
    }

    public function ensure_key(): void {
        if ( ! get_option( self::KEY_OPTION ) ) {
            update_option( self::KEY_OPTION, wp_generate_password( 32, false, false ), false );
        }
    }

    public static function get_key(): string {
        $k = get_option( self::KEY_OPTION );
        if ( ! $k ) {
            $k = wp_generate_password( 32, false, false );
            update_option( self::KEY_OPTION, $k, false );
        }
        return $k;
    }

    public function routes(): void {
        // NEW: key-based endpoint (no login, no auth header).
        register_rest_route( 'delicat/v1', '/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'dashboard' ],
            'permission_callback' => [ $this, 'check_key' ],
        ] );

        // LEGACY: login-based endpoint (kept for backward compatibility).
        register_rest_route( 'delicat/v1', '/dashboard', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'dashboard' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );
    }

    public function check_key( $request ): bool {
        // 6.6.5: header only. The old ?key= fallback is gone: a key in a URL ends
        // up in LiteSpeed, Cloudflare and proxy logs. No current app sends it there.
        $provided = trim( (string) $request->get_header( 'x-delicat-stats-key' ) );
        if ( $provided === '' ) return false;
        return hash_equals( self::get_key(), $provided );
    }

    public function dashboard( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'delicat_recharges';
        $since = gmdate( 'Y-m-d 00:00:00' );

        $today = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) c, COALESCE(SUM(montant),0) s FROM {$table}
             WHERE statut = %s AND created_at >= %s", 'approuve', $since
        ) );
        $pending = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE statut = %s", 'en_attente'
        ) );

        $recent_rows = $wpdb->get_results(
            "SELECT id, user_email, montant, tab, statut, created_at
             FROM {$table} ORDER BY created_at DESC LIMIT 10"
        );
        $recent = [];
        foreach ( (array) $recent_rows as $r ) {
            $recent[] = [
                'id'      => (int) $r->id,
                'email'   => $r->user_email,
                'montant' => (float) $r->montant,
                'tab'     => $r->tab,
                'statut'  => $r->statut,
                'date'    => $r->created_at,
            ];
        }

        return rest_ensure_response( [
            'today_count'   => $today ? (int) $today->c : 0,
            'today_total'   => $today ? (float) $today->s : 0,
            'pending_count' => $pending,
            'recent'        => $recent,
            'currency'      => 'HTG',
            'generated_at'  => current_time( 'mysql' ),
        ] );
    }
}
