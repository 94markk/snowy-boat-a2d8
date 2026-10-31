<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DELICAT ALERTS — scheduled push notifications.
 *
 * Phase 2:
 *   - Pending re-alert: re-pings you if a recharge sits unapproved too long.
 * Phase 3:
 *   - Daily summary: one evening push with the day's totals.
 *   - Low-stock alerts: warns when Free Fire keys or WooCommerce stock run low.
 *
 * All pushes reuse Delicat_Push (secure FCM). Schedules via WP-Cron.
 */
class Delicat_Alerts {

    const PENDING_MINUTES   = 30;   // re-alert if pending longer than this
    const LOW_STOCK_KEYS    = 5;    // warn when Free Fire keys at/below this
    const LOW_STOCK_PRODUCT = 5;    // warn when a WC product stock at/below this

    public function __construct() {
        // Register schedules + hooks.
        add_filter( 'cron_schedules', [ $this, 'add_schedules' ] );
        add_action( 'init', [ $this, 'ensure_scheduled' ] );

        add_action( 'delicat_cron_pending_check', [ $this, 'check_pending' ] );
        add_action( 'delicat_cron_daily_summary', [ $this, 'daily_summary' ] );
        add_action( 'delicat_cron_stock_check',   [ $this, 'check_stock' ] );
    }

    public function add_schedules( array $s ): array {
        if ( ! isset( $s['delicat_15min'] ) ) {
            $s['delicat_15min'] = [ 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Toutes les 15 minutes (Delicat)' ];
        }
        return $s;
    }

    public function ensure_scheduled(): void {
        if ( ! wp_next_scheduled( 'delicat_cron_pending_check' ) ) {
            wp_schedule_event( time() + 60, 'delicat_15min', 'delicat_cron_pending_check' );
        }
        if ( ! wp_next_scheduled( 'delicat_cron_stock_check' ) ) {
            wp_schedule_event( time() + 120, 'hourly', 'delicat_cron_stock_check' );
        }
        if ( ! wp_next_scheduled( 'delicat_cron_daily_summary' ) ) {
            // Next run ~20:00 local time.
            $ts = strtotime( 'today 20:00' );
            if ( $ts < time() ) $ts = strtotime( 'tomorrow 20:00' );
            wp_schedule_event( $ts, 'daily', 'delicat_cron_daily_summary' );
        }
    }

    public static function clear_schedules(): void {
        foreach ( [ 'delicat_cron_pending_check', 'delicat_cron_daily_summary', 'delicat_cron_stock_check' ] as $h ) {
            $ts = wp_next_scheduled( $h );
            if ( $ts ) wp_unschedule_event( $ts, $h );
        }
    }

    /* ---- Phase 2: pending re-alert ----------------------------------- */

    public function check_pending(): void {
        if ( ! class_exists( 'Delicat_Push' ) || ! class_exists( 'Delicat_Database' ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'delicat_recharges';
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::PENDING_MINUTES * MINUTE_IN_SECONDS );

        // Pending requests older than cutoff that we haven't re-alerted yet.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE statut = %s AND created_at <= %s
             ORDER BY created_at ASC LIMIT 20",
            'en_attente', $cutoff
        ) );
        if ( empty( $rows ) ) return;

        $pending_total = count( $rows );
        $oldest = $rows[0];
        $url = admin_url( 'admin.php?page=delicat-recharges&statut=en_attente&highlight=' . (int) $oldest->id );

        $title = '⏰ Recharges en attente';
        $body  = sprintf(
            "%d demande(s) attendent votre validation.\nLa plus ancienne: #%d — %s HTG (%s)",
            $pending_total,
            (int) $oldest->id,
            number_format( (float) $oldest->montant, 2 ),
            $oldest->user_email
        );
        Delicat_Push::send( $title, $body, $url, $pending_total >= 3 );
    }

    /* ---- Phase 3: daily summary -------------------------------------- */

    public function daily_summary(): void {
        if ( ! class_exists( 'Delicat_Push' ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'delicat_recharges';
        $since = gmdate( 'Y-m-d 00:00:00' );

        $approved = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) c, COALESCE(SUM(montant),0) s FROM {$table}
             WHERE statut = %s AND created_at >= %s", 'approuve', $since
        ) );
        $pending = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE statut = %s", 'en_attente'
        ) );

        $count = $approved ? (int) $approved->c : 0;
        $sum   = $approved ? (float) $approved->s : 0;

        $title = '📊 Résumé du jour — Delicat';
        $body  = sprintf(
            "Aujourd'hui: %d recharge(s) approuvée(s)\nTotal crédité: %s HTG\nEn attente: %d",
            $count, number_format( $sum, 2 ), $pending
        );
        $url = class_exists( 'Delicat_Sales_Analytics' )
            ? admin_url( 'admin.php?page=delicat-sales-analytics' )
            : admin_url( 'admin.php?page=delicat-recharges' );
        Delicat_Push::send( $title, $body, $url, false );
    }

    /* ---- Phase 3: low-stock alerts ----------------------------------- */

    public function check_stock(): void {
        if ( ! class_exists( 'Delicat_Push' ) ) return;

        $messages = [];

        // (a) WooCommerce product stock
        if ( function_exists( 'wc_get_products' ) ) {
            $low = wc_get_products( [
                'limit'        => -1,
                'status'       => 'publish',
                'stock_status' => 'instock',
                'return'       => 'objects',
            ] );
            foreach ( $low as $p ) {
                if ( ! $p->managing_stock() ) continue;
                $qty = (int) $p->get_stock_quantity();
                if ( $qty <= self::LOW_STOCK_PRODUCT ) {
                    $messages[] = sprintf( '%s: %d restant(s)', $p->get_name(), $qty );
                }
            }
        }

        // (b) Free Fire keys / Secure Keys vault (best-effort: scans known tables)
        $key_low = self::free_fire_keys_low();
        if ( $key_low !== null && $key_low <= self::LOW_STOCK_KEYS ) {
            $messages[] = sprintf( 'Clés Free Fire: %d disponible(s)', $key_low );
        }

        if ( empty( $messages ) ) return;

        // Avoid spamming: only alert once per 6h per identical message set.
        $hash = md5( implode( '|', $messages ) );
        if ( get_transient( 'delicat_stock_alert_' . $hash ) ) return;
        set_transient( 'delicat_stock_alert_' . $hash, 1, 6 * HOUR_IN_SECONDS );

        $title = '⚠️ Stock faible';
        $body  = "Réapprovisionnez bientôt:\n• " . implode( "\n• ", array_slice( $messages, 0, 8 ) );
        $url   = class_exists( 'Delicat_Sales_Analytics' )
            ? admin_url( 'admin.php?page=delicat-sales-analytics' )
            : admin_url( 'admin.php?page=delicat-recharges' );
        Delicat_Push::send( $title, $body, $url, true );
    }

    /**
     * Try to count available Free Fire keys from the Secure Keys vault.
     * Returns null if no known table is found (so we simply skip the alert).
     */
    private static function free_fire_keys_low(): ?int {
        global $wpdb;
        // Common table names used by the Secure Keys vault across versions.
        $candidates = [
            $wpdb->prefix . 'delica_keys',
            $wpdb->prefix . 'delicat_keys',
            $wpdb->prefix . 'secure_keys',
        ];
        foreach ( $candidates as $t ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
            if ( $exists !== $t ) continue;

            // Detect an "unused/available" flag column if present.
            $cols = $wpdb->get_col( "DESC {$t}", 0 );
            if ( in_array( 'is_used', $cols, true ) ) {
                return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE is_used = 0" );
            }
            if ( in_array( 'used', $cols, true ) ) {
                return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE used = 0" );
            }
            if ( in_array( 'status', $cols, true ) ) {
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$t} WHERE status = %s", 'available'
                ) );
            }
            // Fallback: total rows.
            return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
        }
        return null;
    }
}
