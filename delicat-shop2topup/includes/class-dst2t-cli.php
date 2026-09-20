<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI_Command' ) ) {
	return;
}

final class DST2T_CLI_Command extends WP_CLI_Command {
	/**
	 * Validate credentials and show the safe account state.
	 *
	 * ## EXAMPLES
	 *
	 *     wp delicat-s2t test
	 */
	public function test() {
		$state = DST2T_Plugin::instance()->balance()->refresh( true );
		if ( '' !== $state['error'] ) {
			WP_CLI::error( 'Connection failed: ' . $state['error'] );
		}
		WP_CLI::success(
			sprintf(
				'Connected. Wallet: %s; enabled: %s; verified: %s; %d ms.',
				'' !== $state['formatted'] ? $state['formatted'] : 'unknown',
				null === $state['enabled'] ? 'unknown' : ( $state['enabled'] ? 'yes' : 'no' ),
				null === $state['verified'] ? 'unknown' : ( $state['verified'] ? 'yes' : 'no' ),
				(int) $state['latency_ms']
			)
		);
	}

	/**
	 * Show the wallet balance.
	 *
	 * ## OPTIONS
	 *
	 * [--refresh]
	 * : Read the live balance instead of the cached value.
	 *
	 * ## EXAMPLES
	 *
	 *     wp delicat-s2t balance --refresh
	 */
	public function balance( $args, $assoc_args = array() ) {
		$balance = DST2T_Plugin::instance()->balance();
		$state   = ! empty( $assoc_args['refresh'] ) ? $balance->refresh( true ) : $balance->state();

		if ( '' !== $state['error'] ) {
			WP_CLI::warning( 'Last balance check failed: ' . $state['error'] );
		}
		WP_CLI::line( sprintf( 'Balance: %s', '' !== $state['formatted'] ? $state['formatted'] : 'unknown' ) );
		WP_CLI::line( sprintf( 'Checked: %s', $balance->age_label( $state ) ) );
		WP_CLI::line( sprintf( 'Automatic refresh: %s', $state['auto'] ? sprintf( 'every %d s', $state['interval'] ) : 'off' ) );
		if ( $state['low'] ) {
			WP_CLI::warning( sprintf( 'Balance is below the configured threshold of %s.', $state['threshold'] ) );
		}
	}

	/** Run one safe batch reconciliation. */
	public function reconcile() {
		DST2T_Plugin::instance()->fulfillment()->reconcile();
		WP_CLI::success( 'Reconciliation finished.' );
	}

	/**
	 * Run catalog synchronization batches.
	 *
	 * ## OPTIONS
	 *
	 * [--batches=<count>]
	 * : How many batches to run in this invocation. Default 1.
	 *
	 * [--per-batch=<count>]
	 * : Products per batch, 1-100. Default 20.
	 *
	 * ## EXAMPLES
	 *
	 *     wp delicat-s2t sync --batches=5
	 */
	public function sync( $args, $assoc_args = array() ) {
		$sync    = DST2T_Plugin::instance()->sync();
		$batches = max( 1, absint( isset( $assoc_args['batches'] ) ? $assoc_args['batches'] : 1 ) );
		$size    = absint( isset( $assoc_args['per-batch'] ) ? $assoc_args['per-batch'] : 0 );

		$processed = 0;
		$updated   = 0;
		$errors    = 0;
		for ( $i = 0; $i < $batches; $i++ ) {
			$summary    = $sync->run( $size );
			$processed += (int) $summary['processed'];
			$updated   += (int) $summary['updated'];
			$errors    += (int) $summary['errors'];
			if ( ! empty( $summary['cycle_complete'] ) ) {
				WP_CLI::line( 'Reached the end of the mapped catalog.' );
				break;
			}
		}

		WP_CLI::success( sprintf( 'Processed %d, updated %d, errors %d.', $processed, $updated, $errors ) );
	}

	/** Show local synchronization counts. */
	public function status() {
		$plugin = DST2T_Plugin::instance();
		$counts = $plugin->repository()->counts();
		$sync   = $plugin->sync();

		WP_CLI::line( sprintf( 'Mapped products: %d (%d left in the current sync cycle)', $sync->mapped_count(), $sync->remaining() ) );
		WP_CLI::line( sprintf( 'Callback URL: %s', DST2T_Webhook::url() ) );

		if ( ! $counts ) {
			WP_CLI::line( 'No fulfillments have been recorded.' );
			return;
		}
		$rows = array();
		foreach ( $counts as $status => $total ) {
			$rows[] = array( 'status' => $status, 'total' => $total );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'total' ) );
	}

	/**
	 * Print the callback URL to register upstream, or generate a new one.
	 *
	 * ## OPTIONS
	 *
	 * [--rotate]
	 * : Generate a new private token first. The previous URL stops accepting events.
	 *
	 * ## EXAMPLES
	 *
	 *     wp delicat-s2t webhook --rotate
	 */
	public function webhook( $args, $assoc_args = array() ) {
		if ( ! empty( $assoc_args['rotate'] ) ) {
			DST2T_Webhook::rotate_token();
			WP_CLI::warning( 'A new token was generated. Register the URL below upstream now.' );
		}
		WP_CLI::line( DST2T_Webhook::url() );
		if ( DST2T_Webhook::url() !== DST2T_Webhook::legacy_url() ) {
			WP_CLI::line( sprintf( 'Compatibility URL: %s', DST2T_Webhook::legacy_url() ) );
		}
	}
}

final class DST2T_CLI {
	public static function register() {
		WP_CLI::add_command( 'delicat-s2t', 'DST2T_CLI_Command' );
	}
}
