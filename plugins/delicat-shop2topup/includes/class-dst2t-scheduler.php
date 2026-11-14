<?php

defined( 'ABSPATH' ) || exit;

/** Queue a successor without mistaking the currently running action for it. */
final class DST2T_Scheduler {
	/**
	 * Action Scheduler priority (0 runs first, default 10). Paid-order delivery and
	 * signed supplier callbacks must never wait behind catalog or mapping work in a
	 * shared queue: on a single-runner host a 20-second catalog batch used to delay
	 * an order status check by a full queue cycle. Older Action Scheduler releases
	 * ignore the extra argument, so this is a no-op there.
	 */
	public static function priority( $hook ) {
		switch ( (string) $hook ) {
			case 'dst2t_process_item':
			case 'dst2t_webhook_reconcile':
			case 'dst2t_reconcile':
				return 0;
			case 'dst2t_refresh_balance_now':
			case 'dst2t_refresh_balance':
				return 50;
			default:
				return 120;
		}
	}

	public static function enqueue( $hook, $args, $delay = 0 ) {
		$group = 'delicat-shop2topup';
		$when = time() + max( 0, absint( $delay ) );
		$priority = self::priority( $hook );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				$pending = as_get_scheduled_actions( array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'status' => 'pending', 'per_page' => 1 ), 'ids' );
				if ( $pending ) { return true; }
			} elseif ( ! doing_action( $hook ) && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, $group ) ) {
				return true;
			}
			// unique=true also matches in-progress tasks. Workers already hold their
			// entity lock; duplicate wakeups are harmless and must recheck local state.
			$id = ! $delay && function_exists( 'as_enqueue_async_action' )
				? as_enqueue_async_action( $hook, $args, $group, false, $priority )
				: as_schedule_single_action( $when, $hook, $args, $group, false, $priority );
			if ( $id ) { return true; }
		}
		if ( wp_next_scheduled( $hook, $args ) ) { return true; }
		$result = wp_schedule_single_event( $when, $hook, $args );
		return ! is_wp_error( $result ) && (bool) $result;
	}

	/** Drop a queued job once a faster path has already done its work. */
	public static function cancel( $hook, $args ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, 'delicat-shop2topup' );
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( $hook, $args );
		}
	}

	/**
	 * Finish the HTTP response now and keep this PHP worker for background work.
	 * Supports PHP-FPM (fastcgi_finish_request) and LiteSpeed LSAPI
	 * (litespeed_finish_request, used by Hostinger). Returns false when neither
	 * exists, in which case callers must not do long work in this request.
	 */
	public static function finish_response() {
		static $finished = false;
		if ( $finished ) { return true; }
		$finish = function_exists( 'fastcgi_finish_request' ) ? 'fastcgi_finish_request'
			: ( function_exists( 'litespeed_finish_request' ) ? 'litespeed_finish_request' : '' );
		if ( '' === $finish ) { return false; }
		ignore_user_abort( true );
		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_flush() ) { break; } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@call_user_func( $finish ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$finished = true;
		return true;
	}

	public static function can_finish_response() {
		return function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
	}
}
