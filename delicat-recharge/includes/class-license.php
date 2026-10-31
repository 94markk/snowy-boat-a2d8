<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DELICAT LICENSE MANAGER (runs on the HQ site — delicastoreha.com).
 *
 * Turns the recharge/notification system into a paid multi-tenant service.
 * Each client gets a license tied to THEIR site domain, with a plan that
 * expires. The Android app validates the license on every launch; expired =>
 * the app locks. Expiry is enforced HERE (server-side), so it can't be
 * bypassed by editing the app or changing the phone clock.
 *
 * Data model — option 'delicat_licenses':
 *   [ license_key => [
 *        'client'    => 'Nom du client',
 *        'domain'    => 'clientstore.com',     // domain-locked
 *        'plan'      => 1|2|3|6,               // months
 *        'starts'    => 'Y-m-d',
 *        'expires'   => 'Y-m-d',
 *        'status'    => 'active'|'suspended',
 *        'sign_key'  => '...',                 // HMAC key shared with that client's app
 *        'created'   => 'Y-m-d H:i:s',
 *        'last_check'=> 'Y-m-d H:i:s',
 *   ] ]
 *
 * Validation endpoint (public, signed):
 *   GET /wp-json/delicat/v1/license/check?key=...&domain=...&ts=...&sig=...
 *   Returns { valid:bool, expires, plan, days_left, message }.
 */
class Delicat_License {

    const OPT       = 'delicat_licenses';
    const SIG_WINDOW = 300; // seconds tolerance on signed checks

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
        add_action( 'admin_menu', [ $this, 'menu' ], 20 );
        add_action( 'admin_init', [ $this, 'handle_admin_post' ] );
    }

    /* ---------------- Storage ---------------- */

    public static function all(): array {
        $v = get_option( self::OPT, [] );
        return is_array( $v ) ? $v : [];
    }
    public static function save( array $v ): void {
        update_option( self::OPT, $v, false );
    }

    private static function norm_domain( string $d ): string {
        $d = strtolower( trim( $d ) );
        $d = preg_replace( '#^https?://#', '', $d );
        $d = preg_replace( '#^www\.#', '', $d );
        $d = rtrim( $d, '/' );
        return $d;
    }

    public static function create( string $client, string $domain, int $plan_months ): array {
        $all = self::all();
        $key = 'DLC-' . strtoupper( wp_generate_password( 4, false, false ) )
             . '-' . strtoupper( wp_generate_password( 4, false, false ) )
             . '-' . strtoupper( wp_generate_password( 4, false, false ) );
        $starts  = current_time( 'Y-m-d' );
        $expires = date( 'Y-m-d', strtotime( "+{$plan_months} months", current_time( 'timestamp' ) ) );
        $all[ $key ] = [
            'client'     => sanitize_text_field( $client ),
            'domain'     => self::norm_domain( $domain ),
            'plan'       => $plan_months,
            'starts'     => $starts,
            'expires'    => $expires,
            'status'     => 'active',
            'sign_key'   => wp_generate_password( 40, false, false ),
            'created'    => current_time( 'mysql' ),
            'last_check' => '',
        ];
        self::save( $all );
        return [ $key, $all[ $key ] ];
    }

    public static function extend( string $key, int $months ): void {
        $all = self::all();
        if ( ! isset( $all[ $key ] ) ) return;
        $base = max( strtotime( $all[ $key ]['expires'] ), current_time( 'timestamp' ) );
        $all[ $key ]['expires'] = date( 'Y-m-d', strtotime( "+{$months} months", $base ) );
        $all[ $key ]['status']  = 'active';
        self::save( $all );
    }

    public static function set_status( string $key, string $status ): void {
        $all = self::all();
        if ( isset( $all[ $key ] ) ) {
            $all[ $key ]['status'] = ( $status === 'active' ) ? 'active' : 'suspended';
            self::save( $all );
        }
    }

    public static function delete( string $key ): void {
        $all = self::all();
        unset( $all[ $key ] );
        self::save( $all );
    }

    public static function is_valid( array $lic ): bool {
        if ( ( $lic['status'] ?? '' ) !== 'active' ) return false;
        return strtotime( $lic['expires'] . ' 23:59:59' ) >= current_time( 'timestamp' );
    }

    /* ---------------- Validation REST endpoint ---------------- */

    public function routes(): void {
        register_rest_route( 'delicat/v1', '/license/check', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'check' ],
            'permission_callback' => '__return_true', // public; protected by signature
        ] );

        // HQ push relay: a licensed client site asks HQ to send a push via HQ's
        // Firebase. Client never sees HQ's Firebase credentials.
        register_rest_route( 'delicat/v1', '/license/push', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'relay_push' ],
            'permission_callback' => '__return_true', // protected by signature below
        ] );
    }

    /**
     * Push relay. The client site signs the request with its license sign_key.
     * HQ verifies license validity + signature, then sends the push to the
     * client's own device token(s) through HQ's Firebase.
     */
    public function relay_push( WP_REST_Request $req ) {
        $key    = sanitize_text_field( (string) $req->get_param( 'key' ) );
        $token  = sanitize_text_field( (string) $req->get_param( 'device_token' ) );
        $title  = sanitize_text_field( (string) $req->get_param( 'title' ) );
        $body   = sanitize_text_field( (string) $req->get_param( 'body' ) );
        $ts     = (int) $req->get_param( 'ts' );
        $sig    = (string) $req->get_param( 'sig' );

        $all = self::all();
        if ( ! isset( $all[ $key ] ) ) {
            return new WP_Error( 'no_license', 'Licence inconnue.', [ 'status' => 403 ] );
        }
        $lic = $all[ $key ];

        // License must be valid (active + not expired).
        if ( ! self::is_valid( $lic ) ) {
            return new WP_Error( 'expired', 'Abonnement expiré.', [ 'status' => 403 ] );
        }

        // Signature: base = key|device_token|ts, key = sign_key. Anti-replay 300s.
        if ( abs( time() - $ts ) > self::SIG_WINDOW ) {
            return new WP_Error( 'stale', 'Horodatage expiré.', [ 'status' => 403 ] );
        }
        $expected = hash_hmac( 'sha256', $key . '|' . $token . '|' . $ts, $lic['sign_key'] );
        if ( ! hash_equals( $expected, $sig ) ) {
            return new WP_Error( 'bad_sig', 'Signature invalide.', [ 'status' => 403 ] );
        }

        if ( $token === '' || $title === '' ) {
            return new WP_Error( 'bad_request', 'Données manquantes.', [ 'status' => 400 ] );
        }

        $ok = class_exists( 'Delicat_Push' )
            ? Delicat_Push::send_to_token( $token, $title, $body )
            : false;

        return rest_ensure_response( [ 'sent' => (bool) $ok ] );
    }

    public function check( WP_REST_Request $req ) {
        $key    = sanitize_text_field( (string) $req->get_param( 'key' ) );
        $domain = self::norm_domain( (string) $req->get_param( 'domain' ) );
        $ts     = (int) $req->get_param( 'ts' );
        $sig    = (string) $req->get_param( 'sig' );

        $all = self::all();
        if ( ! isset( $all[ $key ] ) ) {
            return rest_ensure_response( [ 'valid' => false, 'message' => 'Licence inconnue.' ] );
        }
        $lic = $all[ $key ];

        // Signature check (anti-tamper). base = key|domain|ts, key = sign_key.
        if ( abs( time() - $ts ) > self::SIG_WINDOW ) {
            return rest_ensure_response( [ 'valid' => false, 'message' => 'Horodatage expiré.' ] );
        }
        $expected = hash_hmac( 'sha256', $key . '|' . $domain . '|' . $ts, $lic['sign_key'] );
        if ( ! hash_equals( $expected, $sig ) ) {
            return rest_ensure_response( [ 'valid' => false, 'message' => 'Signature invalide.' ] );
        }

        // Domain lock.
        if ( $domain !== $lic['domain'] ) {
            return rest_ensure_response( [ 'valid' => false, 'message' => 'Licence non valide pour ce domaine.' ] );
        }

        // Record the check.
        $all[ $key ]['last_check'] = current_time( 'mysql' );
        self::save( $all );

        $valid = self::is_valid( $lic );
        $days_left = max( 0, (int) ceil( ( strtotime( $lic['expires'] . ' 23:59:59' ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) );

        return rest_ensure_response( [
            'valid'     => $valid,
            'status'    => $lic['status'],
            'plan'      => (int) $lic['plan'],
            'expires'   => $lic['expires'],
            'days_left' => $valid ? $days_left : 0,
            'message'   => $valid
                ? sprintf( 'Abonnement actif — %d jour(s) restant(s).', $days_left )
                : 'Abonnement expiré ou suspendu.',
        ] );
    }

    /* ---------------- Admin UI ---------------- */

    public function menu(): void {
        add_submenu_page( 'delicat-recharges', 'Licences clients', '🔑 Licences',
            'manage_options', 'delicat-licenses', [ $this, 'page' ] );
    }

    public function handle_admin_post(): void {
        if ( empty( $_POST['delicat_license_action'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'delicat_license' );

        $action = sanitize_text_field( $_POST['delicat_license_action'] );

        if ( $action === 'create' ) {
            $client = sanitize_text_field( $_POST['client'] ?? '' );
            $domain = sanitize_text_field( $_POST['domain'] ?? '' );
            $plan   = (int) ( $_POST['plan'] ?? 1 );
            if ( $client && $domain && in_array( $plan, [1,2,3,6], true ) ) {
                list( $key, $lic ) = self::create( $client, $domain, $plan );
                set_transient( 'delicat_new_license', [ 'key' => $key, 'lic' => $lic ], 120 );
            }
        } elseif ( $action === 'extend' ) {
            self::extend( sanitize_text_field( $_POST['key'] ?? '' ), (int) ( $_POST['months'] ?? 1 ) );
        } elseif ( $action === 'suspend' ) {
            self::set_status( sanitize_text_field( $_POST['key'] ?? '' ), 'suspended' );
        } elseif ( $action === 'activate' ) {
            self::set_status( sanitize_text_field( $_POST['key'] ?? '' ), 'active' );
        } elseif ( $action === 'delete' ) {
            self::delete( sanitize_text_field( $_POST['key'] ?? '' ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=delicat-licenses' ) );
        exit;
    }

    public function page(): void {
        $all  = self::all();
        $new  = get_transient( 'delicat_new_license' );
        if ( $new ) delete_transient( 'delicat_new_license' );
        $nonce = wp_create_nonce( 'delicat_license' );
        ?>
        <div class="wrap">
            <h1>🔑 Licences clients</h1>
            <p>Gérez les abonnements des clients qui utilisent le système Délicat sur leur propre site.</p>

            <?php if ( $new ) : ?>
            <div class="notice notice-success" style="padding:14px;">
                <h3>✅ Licence créée — donnez ces valeurs au client (une seule fois)</h3>
                <p><strong>Clé de licence :</strong> <code style="font-size:14px;"><?php echo esc_html( $new['key'] ); ?></code></p>
                <p><strong>Clé de signature :</strong> <code><?php echo esc_html( $new['lic']['sign_key'] ); ?></code></p>
                <p><strong>Domaine :</strong> <?php echo esc_html( $new['lic']['domain'] ); ?>
                   &nbsp; <strong>Expire :</strong> <?php echo esc_html( $new['lic']['expires'] ); ?></p>
                <p class="description">Le client entre la clé de licence + clé de signature + son domaine dans l'app.</p>
            </div>
            <?php endif; ?>

            <h2>Créer une licence</h2>
            <form method="post">
                <?php wp_nonce_field( 'delicat_license' ); ?>
                <input type="hidden" name="delicat_license_action" value="create">
                <table class="form-table">
                    <tr><th>Nom du client</th><td><input type="text" name="client" class="regular-text" required></td></tr>
                    <tr><th>Domaine du site</th><td><input type="text" name="domain" placeholder="clientstore.com" class="regular-text" required></td></tr>
                    <tr><th>Plan</th><td>
                        <select name="plan">
                            <option value="1">1 mois</option>
                            <option value="2">2 mois</option>
                            <option value="3">3 mois</option>
                            <option value="6">6 mois</option>
                        </select>
                    </td></tr>
                </table>
                <?php submit_button( 'Créer la licence' ); ?>
            </form>

            <hr>
            <h2>Licences existantes (<?php echo count( $all ); ?>)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>Client</th><th>Domaine</th><th>Plan</th><th>Expire</th>
                    <th>Statut</th><th>Dernier check</th><th>Actions</th>
                </tr></thead>
                <tbody>
                <?php if ( empty( $all ) ) : ?>
                    <tr><td colspan="7">Aucune licence.</td></tr>
                <?php else : foreach ( $all as $key => $lic ) :
                    $valid = self::is_valid( $lic );
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( $lic['client'] ); ?></strong><br>
                            <code style="font-size:11px;"><?php echo esc_html( $key ); ?></code></td>
                        <td><?php echo esc_html( $lic['domain'] ); ?></td>
                        <td><?php echo (int) $lic['plan']; ?> mois</td>
                        <td><?php echo esc_html( $lic['expires'] ); ?></td>
                        <td><?php echo $valid
                            ? '<span style="color:#1b5e20;font-weight:700;">🟢 Actif</span>'
                            : '<span style="color:#b71c1c;font-weight:700;">🔴 ' . ( $lic['status']==='suspended' ? 'Suspendu' : 'Expiré' ) . '</span>'; ?></td>
                        <td><small><?php echo esc_html( $lic['last_check'] ?: 'jamais' ); ?></small></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'delicat_license' ); ?>
                                <input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
                                <select name="months" style="width:60px;"><option>1</option><option>2</option><option>3</option><option>6</option></select>
                                <button class="button" name="delicat_license_action" value="extend">+Prolonger</button>
                            </form>
                            <?php if ( $lic['status'] === 'active' ) : ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'delicat_license' ); ?>
                                <input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
                                <button class="button" name="delicat_license_action" value="suspend">Suspendre</button>
                            </form>
                            <?php else : ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'delicat_license' ); ?>
                                <input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
                                <button class="button" name="delicat_license_action" value="activate">Activer</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer cette licence ?');">
                                <?php wp_nonce_field( 'delicat_license' ); ?>
                                <input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
                                <button class="button" name="delicat_license_action" value="delete" style="color:#b71c1c;">✕</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
