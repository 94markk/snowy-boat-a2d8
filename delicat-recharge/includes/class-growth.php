<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BATCH C — Growth features (these CREDIT wallet money, handled carefully).
 *
 *  4) Promo/bonus codes: a code gives a % or fixed bonus on a recharge.
 *  5) Referral rewards: referred client + referrer both get wallet credit
 *     after the referred client's first approved recharge.
 *  6) Loyalty tiers: cumulative approved HTG unlocks a bonus % on recharges.
 *
 * SAFETY PRINCIPLES:
 *  - Bonuses are applied ONLY at approval time, server-side, never client-set.
 *  - Each bonus is recorded so it can never be paid twice (idempotent).
 *  - All credits go through Delicat_TeraWallet::credit with a clear description.
 *  - Admin-configurable; disabled by default so nothing pays out until you
 *    deliberately turn it on.
 */
class Delicat_Growth {

    const OPT_ENABLED   = 'delicat_growth_enabled';      // master switch
    const OPT_PROMOS    = 'delicat_growth_promos';       // array of codes
    const OPT_REF_BONUS = 'delicat_growth_ref_bonus';    // HTG to each side
    const OPT_TIERS     = 'delicat_growth_tiers';        // array threshold=>percent

    public function __construct() {
        // Hook into approval: when a recharge is approved + credited, apply bonuses.
        add_action( 'delicat_recharge_approved', [ $this, 'on_approved' ], 10, 1 );

        // Capture a referral code at registration (?ref=CODE stored in user meta).
        add_action( 'user_register', [ $this, 'capture_referral' ], 10, 1 );

        // Admin settings UI under the existing settings page.
        add_action( 'delicat_settings_extra', [ $this, 'settings_ui' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    private static function enabled(): bool {
        return (bool) get_option( self::OPT_ENABLED, 0 );
    }

    /* ================================================================
     *  Apply bonuses at approval time (the only place money is added)
     * ================================================================ */

    public function on_approved( int $recharge_id ): void {
        if ( ! self::enabled() ) return;
        if ( ! class_exists( 'Delicat_TeraWallet' ) ) return;

        $r = Delicat_Database::get( $recharge_id );
        if ( ! $r || $r->statut !== 'approuve' ) return;

        $user_id = (int) $r->user_id;
        if ( ! $user_id ) {
            $u = get_user_by( 'email', $r->user_email );
            $user_id = $u ? $u->ID : 0;
        }
        if ( ! $user_id ) return;

        // Idempotency guard: never apply bonuses to the same recharge twice.
        $done = get_post_meta( 0, '', false ); // no-op placeholder to keep linters calm
        $flag_key = '_delicat_bonus_done_' . $recharge_id;
        if ( get_option( $flag_key ) ) return;
        update_option( $flag_key, 1, false );

        $amount = (float) $r->montant;

        // 4) PROMO CODE bonus (code stored on the recharge note or user meta).
        $this->apply_promo( $user_id, $amount, $r );

        // 6) LOYALTY tier bonus (based on cumulative approved total).
        $this->apply_loyalty( $user_id, $amount );

        // 5) REFERRAL reward (only on the user's FIRST approved recharge).
        $this->apply_referral( $user_id );
    }

    /* ---- 4) Promo codes ---------------------------------------------- */

    private function apply_promo( int $user_id, float $amount, object $r ): void {
        // Promo code is captured at submit time into user meta 'delicat_pending_promo'.
        $code = strtoupper( trim( (string) get_user_meta( $user_id, 'delicat_pending_promo', true ) ) );
        if ( $code === '' ) return;

        $promos = (array) get_option( self::OPT_PROMOS, [] );
        if ( ! isset( $promos[ $code ] ) ) return;

        $p = $promos[ $code ];
        // Per-user single use.
        $used = (array) get_user_meta( $user_id, 'delicat_used_promos', true );
        if ( in_array( $code, $used, true ) ) return;

        $bonus = 0.0;
        if ( ( $p['type'] ?? 'percent' ) === 'percent' ) {
            $bonus = $amount * ( (float) ( $p['value'] ?? 0 ) / 100 );
        } else {
            $bonus = (float) ( $p['value'] ?? 0 );
        }
        if ( $bonus <= 0 ) return;

        // Optional cap.
        if ( ! empty( $p['max'] ) && $bonus > (float) $p['max'] ) $bonus = (float) $p['max'];

        Delicat_TeraWallet::credit( $user_id, $bonus,
            sprintf( 'Bonus code %s (recharge #%d)', $code, (int) $r->id ) );

        $used[] = $code;
        update_user_meta( $user_id, 'delicat_used_promos', $used );
        delete_user_meta( $user_id, 'delicat_pending_promo' );
    }

    /* ---- 6) Loyalty tiers -------------------------------------------- */

    private function apply_loyalty( int $user_id, float $amount ): void {
        $tiers = (array) get_option( self::OPT_TIERS, [] );
        if ( empty( $tiers ) ) return;

        // Cumulative approved total for this user.
        global $wpdb;
        $table = $wpdb->prefix . 'delicat_recharges';
        $total = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(montant),0) FROM {$table} WHERE user_id = %d AND statut = %s",
            $user_id, 'approuve'
        ) );

        // Find the highest tier the user qualifies for.
        $percent = 0.0;
        ksort( $tiers );
        foreach ( $tiers as $threshold => $pct ) {
            if ( $total >= (float) $threshold ) $percent = (float) $pct;
        }
        if ( $percent <= 0 ) return;

        $bonus = $amount * ( $percent / 100 );
        if ( $bonus <= 0 ) return;

        Delicat_TeraWallet::credit( $user_id, $bonus,
            sprintf( 'Bonus fidélité %s%% (total %s HTG)', $percent, number_format( $total, 0 ) ) );
    }

    /* ---- 5) Referral rewards ----------------------------------------- */

    public function capture_referral( int $user_id ): void {
        if ( empty( $_GET['ref'] ) ) return;
        $code = sanitize_text_field( wp_unslash( $_GET['ref'] ) );
        // The ref code is a referrer's user ID (simple + unique).
        $ref_id = absint( $code );
        if ( $ref_id && $ref_id !== $user_id && get_userdata( $ref_id ) ) {
            update_user_meta( $user_id, 'delicat_referred_by', $ref_id );
        }
    }

    private function apply_referral( int $user_id ): void {
        $ref_bonus = (float) get_option( self::OPT_REF_BONUS, 0 );
        if ( $ref_bonus <= 0 ) return;

        // Only on the user's FIRST approved recharge.
        if ( get_user_meta( $user_id, 'delicat_referral_paid', true ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'delicat_recharges';
        $approved_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND statut = %s",
            $user_id, 'approuve'
        ) );
        if ( $approved_count < 1 ) return;

        $ref_id = (int) get_user_meta( $user_id, 'delicat_referred_by', true );
        if ( ! $ref_id || ! get_userdata( $ref_id ) ) return;

        // Pay both sides, once.
        Delicat_TeraWallet::credit( $user_id, $ref_bonus, 'Bonus de bienvenue (parrainage)' );
        Delicat_TeraWallet::credit( $ref_id,  $ref_bonus, 'Bonus de parrainage (ami recommandé)' );
        update_user_meta( $user_id, 'delicat_referral_paid', 1 );
    }

    /* ================================================================
     *  Admin settings
     * ================================================================ */

    public function register_settings(): void {
        register_setting( 'delicat_settings_group', self::OPT_ENABLED, 'absint' );
        register_setting( 'delicat_settings_group', self::OPT_REF_BONUS, 'floatval' );
        register_setting( 'delicat_settings_group', self::OPT_PROMOS, [
            'sanitize_callback' => [ $this, 'sanitize_promos' ],
        ] );
        register_setting( 'delicat_settings_group', self::OPT_TIERS, [
            'sanitize_callback' => [ $this, 'sanitize_tiers' ],
        ] );
    }

    public function sanitize_promos( $raw ): array {
        // Raw textarea: one per line  CODE|percent|10|max  OR  CODE|fixed|100
        $out = [];
        foreach ( preg_split( '/\r?\n/', (string) $raw ) as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            $parts = array_map( 'trim', explode( '|', $line ) );
            $code  = strtoupper( $parts[0] ?? '' );
            if ( $code === '' ) continue;
            $out[ $code ] = [
                'type'  => ( $parts[1] ?? 'percent' ) === 'fixed' ? 'fixed' : 'percent',
                'value' => (float) ( $parts[2] ?? 0 ),
                'max'   => (float) ( $parts[3] ?? 0 ),
            ];
        }
        return $out;
    }

    public function sanitize_tiers( $raw ): array {
        // Raw textarea: one per line  THRESHOLD|percent   e.g. 5000|3
        $out = [];
        foreach ( preg_split( '/\r?\n/', (string) $raw ) as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            $parts = array_map( 'trim', explode( '|', $line ) );
            $thr = (float) ( $parts[0] ?? 0 );
            $pct = (float) ( $parts[1] ?? 0 );
            if ( $thr > 0 && $pct > 0 ) $out[ (string) $thr ] = $pct;
        }
        return $out;
    }

    public function settings_ui(): void {
        $enabled = self::enabled();
        $ref     = (float) get_option( self::OPT_REF_BONUS, 0 );

        // Render promos back to textarea form.
        $promos = (array) get_option( self::OPT_PROMOS, [] );
        $promo_lines = [];
        foreach ( $promos as $code => $p ) {
            $promo_lines[] = $code . '|' . $p['type'] . '|' . $p['value'] . ( $p['max'] ? '|' . $p['max'] : '' );
        }
        $tiers = (array) get_option( self::OPT_TIERS, [] );
        $tier_lines = [];
        foreach ( $tiers as $thr => $pct ) { $tier_lines[] = $thr . '|' . $pct; }
        ?>
        <h2>🎁 Croissance (Promo / Parrainage / Fidélité)</h2>
        <table class="form-table">
            <tr><th>Activer les bonus</th>
                <td>
                    <label><input type="checkbox" name="<?php echo self::OPT_ENABLED; ?>" value="1" <?php checked( $enabled ); ?>>
                    Activer promo, parrainage et fidélité</label>
                    <p class="description">Désactivé par défaut. Aucun bonus n'est versé tant que ceci n'est pas coché.</p>
                </td></tr>

            <tr><th>Codes promo</th>
                <td>
                    <textarea name="<?php echo self::OPT_PROMOS; ?>" rows="4" class="large-text" style="font-family:monospace;"><?php echo esc_textarea( implode( "\n", $promo_lines ) ); ?></textarea>
                    <p class="description">Un par ligne : <code>CODE|percent|10|max</code> (10% bonus, plafond optionnel) ou <code>CODE|fixed|100</code> (100 HTG fixe). Ex : <code>NWEL|percent|10|500</code></p>
                </td></tr>

            <tr><th>Bonus de parrainage (HTG)</th>
                <td>
                    <input type="number" step="0.01" min="0" name="<?php echo self::OPT_REF_BONUS; ?>" value="<?php echo esc_attr( $ref ); ?>" class="small-text">
                    <p class="description">Versé au filleul ET au parrain après la 1re recharge approuvée du filleul. 0 = désactivé. Lien de parrainage : <code><?php echo esc_html( home_url( '/?ref=VOTRE_ID' ) ); ?></code></p>
                </td></tr>

            <tr><th>Paliers de fidélité</th>
                <td>
                    <textarea name="<?php echo self::OPT_TIERS; ?>" rows="3" class="large-text" style="font-family:monospace;"><?php echo esc_textarea( implode( "\n", $tier_lines ) ); ?></textarea>
                    <p class="description">Un par ligne : <code>SEUIL|pourcentage</code>. Ex : <code>5000|2</code> = 2% bonus une fois 5000 HTG cumulés ; <code>20000|5</code> = 5% au-delà de 20000.</p>
                </td></tr>
        </table>
        <?php
    }
}
