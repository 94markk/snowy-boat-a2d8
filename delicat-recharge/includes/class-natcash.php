<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Natcash payment verification.
 *
 * Natcash does not expose a public transaction-verification REST API at
 * this time. When they do, this class is where you add it.
 *
 * For now we implement a "trust + notify" flow:
 *  - Save the submission immediately with statut = 'en_attente'
 *  - Admin reviews the TransCode manually in the admin panel OR
 *    a future webhook from Natcash can hit our REST endpoint to auto-approve.
 *
 * To switch to full auto once Natcash provides an API:
 *  1. Fill in the constants below.
 *  2. Implement verify_transaction() similar to class-moncash.php.
 *  3. Set NATCASH_API_AVAILABLE to true.
 */
class Delicat_Natcash {

    const NATCASH_API_AVAILABLE = false;

    /**
     * Attempt to verify a Natcash transaction.
     * Returns same shape as Delicat_MonCash::verify_transaction().
     */
    public static function verify_transaction( string $trans_code, float $expected_amount ): array {
        if ( ! self::NATCASH_API_AVAILABLE ) {
            // No API yet — return a "pending manual" result
            return [
                'success' => false,
                'pending' => true,   // tells caller: not rejected, just needs manual review
                'message' => 'Natcash: vérification automatique non disponible. Votre demande sera traitée manuellement.',
                'amount'  => 0,
            ];
        }

        // === Future implementation ===
        // $response = wp_remote_get( 'https://api.natcash.ht/v1/transactions/' . $trans_code, [...] );
        // ... parse & return like MonCash above

        return [ 'success' => false, 'pending' => true, 'message' => 'Not implemented.', 'amount' => 0 ];
    }
}
