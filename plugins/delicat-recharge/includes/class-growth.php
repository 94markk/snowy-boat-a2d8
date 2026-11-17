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

        // 6.5.1: loyalty tier / referral bonus on the account home of Delicat Identity (read-only).
        add_filter( 'dip_account_rewards', [ __CLASS__, 'account_rewards' ], 10, 2 );

        // Admin settings UI under the existing settings page.
        add_action( 'delicat_settings_extra', [ $this, 'settings_ui' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_notices', [ __CLASS__, 'referral_notice' ] );
    }

    public static function enabled(): bool {
        return (bool) get_option( self::OPT_ENABLED, 0 );
    }

    /**
     * 6.6.4 (RCH-06): Delicat Rewards runs the store's referral programme (codes,
     * fraud checks, milestones). When its referral module is installed, this
     * older « Growth » referral stands down: it no longer records who invited
     * whom, pays no referral bonus and shows no referral card. Promo codes and
     * loyalty tiers are not referral and keep working. Stored data
     * (delicat_referred_by / delicat_referral_paid) is kept untouched.
     */
    public static function rewards_referral_active(): bool {
        return (bool) apply_filters( 'delicat_growth_rewards_referral_active', function_exists( 'delicat_ref_on' ) );
    }

    /**
     * 6.6.4 (RCH-07): who may be credited as a referrer: an existing customer
     * account, never the new account itself, never staff.
     */
    public static function eligible_referrer( int $ref_id, int $user_id ): bool {
        if ( $ref_id <= 0 || $ref_id === $user_id ) return false;
        $ref = get_userdata( $ref_id );
        if ( ! $ref ) return false;
        $roles = (array) apply_filters( 'delicat_growth_referrer_roles', [ 'customer' ] );
        if ( ! array_intersect( $roles, (array) $ref->roles ) ) return false;
        foreach ( [ 'manage_options', 'manage_woocommerce', 'edit_posts' ] as $cap ) {
            if ( user_can( $ref, $cap ) ) return false;
        }
        return true;
    }

    /** Admin notice while both referral programmes could run (RCH-06). */
    public static function referral_notice(): void {
        if ( ! current_user_can( 'manage_options' ) || ! self::rewards_referral_active() ) return;
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'delicat' ) === false ) return;
        if ( ! self::enabled() || (float) get_option( self::OPT_REF_BONUS, 0 ) <= 0 ) return;
        echo '<div class="notice notice-info"><p><strong>Parrainage :</strong> le parrainage de Delicat Recharge (Croissance) est en pause, car Delicat Rewards gère maintenant le parrainage (un seul programme, pour ne jamais payer deux fois le même ami). '
            . 'Aucun bonus de parrainage Recharge n\'est versé et sa carte n\'est plus affichée ; les données déjà enregistrées sont conservées. Les codes promo et la fidélité continuent normalement. Réglez le parrainage dans Delicat Rewards.</p></div>';
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
        // 6.6.4 (RCH-08): claimed atomically on the recharge row (bonus_done)
        // instead of one never-cleaned option per recharge.
        if ( ! self::claim_bonus( (int) $r->id ) ) return;

        $amount = (float) $r->montant;

        // 4) PROMO CODE bonus (code stored on the recharge note or user meta).
        $this->apply_promo( $user_id, $amount, $r );

        // 6) LOYALTY tier bonus (based on cumulative approved total).
        $this->apply_loyalty( $user_id, $amount );

        // 5) REFERRAL reward (only on the user's FIRST approved recharge).
        $this->apply_referral( $user_id );
    }

    /** Legacy per-recharge marker written up to 6.6.3 (cleaned in batches by Delicat_Maintenance). */
    const LEGACY_FLAG_PREFIX = '_delicat_bonus_done_';

    /**
     * True for the one caller allowed to pay this recharge's bonuses. The
     * bonus_done column is flipped 0→1 by a single conditional UPDATE, so two
     * approvals firing at once cannot both pay. Older recharges keep their
     * pre-6.6.4 option marker until the clean-up has copied it to the column.
     */
    public static function claim_bonus( int $recharge_id ): bool {
        global $wpdb;
        $flag_key = self::LEGACY_FLAG_PREFIX . $recharge_id;
        if ( ! Delicat_Database::bonus_column_ready() ) {
            // Column missing (database refused the upgrade): the old way.
            if ( get_option( $flag_key ) ) return false;
            update_option( $flag_key, 1, false );
            return true;
        }
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}delicat_recharges SET bonus_done = 1 WHERE id = %d AND bonus_done = 0",
            $recharge_id
        ) );
        if ( 1 !== (int) $claimed ) return false;
        if ( ! get_option( 'delicat_bonus_flags_migrated' ) && get_option( $flag_key ) ) {
            return false; // paid before 6.6.4; the clean-up has not reached it yet
        }
        return true;
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

    /**
     * 6.5.1: the « Récompenses » card of the account home (Delicat Identity 6.15.2+):
     * the customer's loyalty bonus and how far the next tier is, else the referral
     * bonus. Read-only, the customer's own data, one indexed SUM per page view.
     *
     * @param mixed $rewards An earlier provider's answer.
     * @param int   $user_id The signed-in customer.
     */
    public static function account_rewards( $rewards, $user_id ) {
        if ( is_array( $rewards ) && ! empty( $rewards['value'] ) ) {
            return $rewards;
        }
        $user_id = absint( $user_id );
        if ( ! $user_id || ! self::enabled() || $user_id !== get_current_user_id() ) {
            return $rewards;
        }
        $url   = home_url( '/my-wallet/' );
        $tiers = (array) get_option( self::OPT_TIERS, [] );
        if ( ! empty( $tiers ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'delicat_recharges';
            $total = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(montant),0) FROM {$table} WHERE user_id = %d AND statut = %s",
                $user_id, 'approuve'
            ) );
            ksort( $tiers, SORT_NUMERIC );
            $percent = 0.0;
            $next    = null;
            foreach ( $tiers as $threshold => $pct ) {
                if ( $total >= (float) $threshold ) {
                    $percent = (float) $pct;
                } elseif ( null === $next ) {
                    $next = [ (float) $threshold, (float) $pct ];
                }
            }
            $fmt = static function ( float $n ): string {
                return rtrim( rtrim( number_format_i18n( $n, 2 ), '0' ), ',.' );
            };
            return [
                'label' => __( 'Fidélité', 'delicat-recharge' ),
                /* translators: %s: bonus percentage */
                'value' => sprintf( __( 'Bonus %s %%', 'delicat-recharge' ), $fmt( $percent ) ),
                'hint'  => null !== $next
                    /* translators: 1: amount still to recharge, 2: next bonus percentage */
                    ? sprintf( __( 'Encore %1$s HTG pour %2$s %%', 'delicat-recharge' ), number_format_i18n( max( 0, $next[0] - $total ) ), $fmt( $next[1] ) )
                    : __( 'Niveau maximum atteint', 'delicat-recharge' ),
                'url'   => $url,
            ];
        }
        $ref = (float) get_option( self::OPT_REF_BONUS, 0 );
        if ( $ref > 0 && ! self::rewards_referral_active() ) {
            return [
                'label' => __( 'Parrainage', 'delicat-recharge' ),
                'value' => '+' . number_format_i18n( $ref ) . ' HTG',
                'hint'  => __( 'Pour vous et chaque ami parrainé', 'delicat-recharge' ),
                'url'   => $url,
            ];
        }
        return $rewards;
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
        // 6.6.4 (RCH-06): Rewards owns ?ref= (its codes are not user IDs).
        if ( self::rewards_referral_active() ) return;
        if ( empty( $_GET['ref'] ) || ! is_scalar( $_GET['ref'] ) ) return;
        $code = trim( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) );
        // The ref code is a referrer's user ID (simple + unique).
        // 6.6.4 (RCH-07): digits only (« 12abc » no longer means user 12), and
        // the referrer must be an existing customer, not the new account, not staff.
        if ( ! preg_match( '/^[0-9]{1,20}$/', $code ) ) return;
        $ref_id = absint( $code );
        if ( self::eligible_referrer( $ref_id, $user_id ) ) {
            update_user_meta( $user_id, 'delicat_referred_by', $ref_id );
        }
    }

    private function apply_referral( int $user_id ): void {
        if ( self::rewards_referral_active() ) return; // 6.6.4 (RCH-06): one programme only
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
        if ( ! self::eligible_referrer( $ref_id, $user_id ) ) return; // 6.6.4 (RCH-07): re-checked at pay time

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
        // 6.6.4: WordPress sanitizes a brand-new option twice (update_option, then
        // add_option); the second pass gets this array back. It used to be cast to
        // the string "Array", which emptied the promo list on the very first save.
        if ( is_array( $raw ) ) {
            foreach ( $raw as $code => $p ) {
                $code = strtoupper( sanitize_text_field( (string) $code ) );
                if ( $code === '' || ! is_array( $p ) ) continue;
                $out[ $code ] = [
                    'type'  => ( $p['type'] ?? 'percent' ) === 'fixed' ? 'fixed' : 'percent',
                    'value' => (float) ( $p['value'] ?? 0 ),
                    'max'   => (float) ( $p['max'] ?? 0 ),
                ];
            }
            return $out;
        }
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
        if ( is_array( $raw ) ) { // second pass on a new option (see sanitize_promos)
            foreach ( $raw as $thr => $pct ) {
                if ( (float) $thr > 0 && (float) $pct > 0 ) $out[ (string) (float) $thr ] = (float) $pct;
            }
            return $out;
        }
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
        <h2>Croissance (Promo / Parrainage / Fidélité)</h2>
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
                    <p class="description">Versé au filleul ET au parrain après la 1re recharge approuvée du filleul. 0 = désactivé. Lien de parrainage : <code><?php echo esc_html( home_url( '/?ref=VOTRE_ID' ) ); ?></code> (parrain : un compte client existant).</p>
                    <?php if ( self::rewards_referral_active() ) : ?>
                    <p class="description" style="color:#b45309;font-weight:600;">En pause : Delicat Rewards gère le parrainage du magasin. Ce bonus n'est pas versé et sa carte n'est pas affichée tant que Delicat Rewards est installé (données conservées).</p>
                    <?php endif; ?>
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
