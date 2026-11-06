<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Thin wrapper around TeraWallet (woo-wallet plugin).
 * Handles crediting user wallets when a recharge is approved.
 */
class Delicat_TeraWallet {

    /**
     * Check if TeraWallet is active and available.
     */
    public static function is_available(): bool {
        return function_exists( 'woo_wallet' ) && is_callable( [ woo_wallet()->wallet, 'credit' ] );
    }

    /**
     * Credit a user's TeraWallet balance.
     *
     * @param int    $user_id
     * @param float  $amount
     * @param string $description   Shown in transaction history
     * @param int    $recharge_id   Our internal request ID for reference
     *
     * @return int|WP_Error  Transaction ID on success, WP_Error on failure.
     */
    public static function credit( int $user_id, float $amount, string $description = '', int $recharge_id = 0 ) {
        if ( ! self::is_available() ) {
            return new WP_Error( 'terawallet_missing', 'TeraWallet plugin is not active.' );
        }
        if ( $user_id <= 0 ) {
            return new WP_Error( 'invalid_user', 'Invalid user ID for wallet credit.' );
        }
        if ( $amount <= 0 ) {
            return new WP_Error( 'invalid_amount', 'Credit amount must be greater than zero.' );
        }

        if ( empty( $description ) ) {
            $description = sprintf( 'Recharge #%d via Delicat Store', $recharge_id );
        }

        // woo_wallet()->wallet->credit( $user_id, $amount, $description )
        $transaction_id = woo_wallet()->wallet->credit( $user_id, $amount, $description );

        if ( ! $transaction_id ) {
            return new WP_Error( 'wallet_credit_failed', 'TeraWallet credit returned no transaction ID.' );
        }

        do_action( 'delicat_recharge_wallet_credited', absint( $user_id ), absint( $recharge_id ), absint( $transaction_id ) );

        return (int) $transaction_id;
    }

    /**
     * Get a user's current wallet balance.
     */
    public static function get_balance( int $user_id ): float {
        if ( ! self::is_available() ) return 0.0;
        return (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
    }

    /**
     * Debit (remove) balance from a user's wallet.
     */
    public static function debit( int $user_id, float $amount, string $description = '' ) {
        if ( ! self::is_available() ) {
            return new WP_Error( 'terawallet_missing', 'TeraWallet plugin is not active.' );
        }
        if ( $user_id <= 0 || $amount <= 0 ) {
            return new WP_Error( 'invalid', 'Invalid user or amount.' );
        }
        if ( empty( $description ) ) {
            $description = 'Ajustement admin – Delicat Store';
        }
        $txn = woo_wallet()->wallet->debit( $user_id, $amount, $description );
        if ( ! $txn ) {
            return new WP_Error( 'debit_failed', 'TeraWallet debit failed.' );
        }

        do_action( 'delicat_recharge_wallet_debited', absint( $user_id ), absint( $txn ) );

        return $txn;
    }
}
