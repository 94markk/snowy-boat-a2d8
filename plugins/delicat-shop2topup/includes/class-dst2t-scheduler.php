<?php

defined( 'ABSPATH' ) || exit;

/** Queue a successor without mistaking the currently running action for it. */
final class DST2T_Scheduler {
	public static function enqueue( $hook, $args, $delay = 0 ) {
		$group = 'delicat-shop2topup';
		$when = time() + max( 0, absint( $delay ) );
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
				? as_enqueue_async_action( $hook, $args, $group, false )
				: as_schedule_single_action( $when, $hook, $args, $group, false );
			if ( $id ) { return true; }
		}
		if ( wp_next_scheduled( $hook, $args ) ) { return true; }
		$result = wp_schedule_single_event( $when, $hook, $args );
		return ! is_wp_error( $result ) && (bool) $result;
	}
}
