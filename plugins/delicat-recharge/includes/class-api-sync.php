<?php
/**
 * Passive bridge between existing recharge/wallet events and the App API.
 *
 * The recharge engine remains authoritative. No amount, provider credential,
 * transaction code, approval decision or wallet mutation is changed here.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Delicat_Recharge_Api_Sync {

    /** @var array<string,bool> Events already published in this request. */
    private static $published = [];

    public static function init(): void {
        add_action( 'delicat_recharge_record_created', [ __CLASS__, 'record_created' ], 20, 3 );
        add_action( 'delicat_recharge_record_updated', [ __CLASS__, 'record_updated' ], 20, 2 );
        add_action( 'delicat_recharge_approved', [ __CLASS__, 'approved' ], 30, 1 );
        add_action( 'delicat_recharge_wallet_credited', [ __CLASS__, 'wallet_credited' ], 20, 3 );
        add_action( 'delicat_recharge_wallet_debited', [ __CLASS__, 'wallet_debited' ], 20, 2 );
        add_action( 'delicat_transfer_completed', [ __CLASS__, 'transfer_completed' ], 20, 5 );
        add_action( 'delicat_recharge_services_changed', [ __CLASS__, 'services_changed' ], 20, 2 );
    }

    public static function record_created( $recharge_id, $user_id, $status ): void {
        unset( $status );
        self::publish_user( 'recharge', absint( $user_id ), 'recharge_created', absint( $recharge_id ) );
    }

    public static function record_updated( $recharge_id, $changes ): void {
        $recharge_id = absint( $recharge_id );
        $changes     = is_array( $changes ) ? $changes : [];
        $status      = isset( $changes['status'] ) ? sanitize_key( $changes['status'] ) : '';

        if ( $recharge_id < 1 || ( '' === $status && empty( $changes['wallet_changed'] ) ) ) {
            return;
        }

        $record = Delicat_Database::get( $recharge_id );
        if ( ! $record || empty( $record->user_id ) ) {
            return;
        }

        $user_id = absint( $record->user_id );
        self::publish_user( 'recharge', $user_id, 'rejete' === $status ? 'recharge_rejected' : 'recharge_updated', $recharge_id );

        if ( ! empty( $changes['wallet_changed'] ) ) {
            self::publish_user( 'wallet', $user_id, 'wallet_recharge_updated', $recharge_id );
        }

        if ( 'rejete' === $status ) {
            self::notify_rejection( $user_id, $recharge_id );
        }
    }

    public static function approved( $recharge_id ): void {
        $record = Delicat_Database::get( absint( $recharge_id ) );
        if ( ! $record || empty( $record->user_id ) ) {
            return;
        }

        self::publish_user( 'recharge', absint( $record->user_id ), 'recharge_approved', absint( $recharge_id ) );
        self::publish_user( 'wallet', absint( $record->user_id ), 'wallet_recharge_approved', absint( $recharge_id ) );
    }

    public static function wallet_credited( $user_id, $recharge_id, $transaction_id ): void {
        unset( $transaction_id );
        self::publish_user( 'wallet', absint( $user_id ), 'wallet_credit', absint( $recharge_id ) );
    }

    public static function wallet_debited( $user_id, $transaction_id ): void {
        self::publish_user( 'wallet', absint( $user_id ), 'wallet_debit', absint( $transaction_id ) );
    }

    public static function transfer_completed( $transfer_id, $sender_id, $recipient_id, $amount, $fee ): void {
        unset( $amount, $fee );
        $transfer_id = absint( $transfer_id );
        self::publish_user( 'wallet', absint( $sender_id ), 'wallet_transfer_sent', $transfer_id );
        self::publish_user( 'wallet', absint( $recipient_id ), 'wallet_transfer_received', $transfer_id );
    }

    public static function services_changed( $service, $enabled ): void {
        unset( $service, $enabled );
        self::publish_content( 'recharge_services' );
    }

    private static function publish_user( string $scope, int $user_id, string $event, int $entity_id ): void {
        if ( $user_id < 1 || ! class_exists( 'Delicat_App_Sync_Hub' ) ) {
            return;
        }

        $key = $scope . ':' . $user_id . ':' . $event . ':' . $entity_id;
        if ( isset( self::$published[ $key ] ) ) {
            return;
        }
        self::$published[ $key ] = true;

        try {
            do_action( 'delicat_app_' . $scope . '_changed', $user_id, sanitize_key( $event ), [] );
        } catch ( Throwable $exception ) {
            // A sync observer must never undo, repeat, or reject a payment.
        }
    }

    private static function publish_content( string $event ): void {
        if ( ! class_exists( 'Delicat_App_Sync_Hub' ) ) {
            return;
        }

        try {
            do_action( 'delicat_app_content_changed', sanitize_key( $event ), [] );
        } catch ( Throwable $exception ) {
            // Existing admin service toggles remain independent of App API.
        }
    }

    private static function notify_rejection( int $user_id, int $recharge_id ): void {
        if ( ! class_exists( 'Delicat_App_Push' ) || ! class_exists( 'Delicat_App_Storage' ) ) {
            return;
        }

        $marker = '_delicat_recharge_reject_notified_' . $recharge_id;
        if ( get_user_meta( $user_id, $marker, true ) ) {
            return;
        }

        $lock = 'recharge_reject_notice:' . $recharge_id;
        if ( ! Delicat_App_Storage::acquire_lock( $lock, 2 ) ) {
            return;
        }

        try {
            if ( get_user_meta( $user_id, $marker, true ) ) {
                return;
            }

            Delicat_App_Push::queue_to_user(
                $user_id,
                'Recharge à vérifier',
                'Ta demande de recharge n’a pas pu être validée. Consulte ton historique ou contacte le support.',
                [
                    'type'            => 'recharge',
                    'route'           => 'recharge',
                    'entity_id'       => $recharge_id,
                    'notification_id' => 'recharge-' . $recharge_id . '-rejected',
                ]
            );
            update_user_meta( $user_id, $marker, time() );
        } catch ( Throwable $exception ) {
            // Notification delivery can never alter a completed admin action.
        } finally {
            Delicat_App_Storage::release_lock( $lock );
        }
    }
}
