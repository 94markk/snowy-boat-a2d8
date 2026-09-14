<?php
namespace Delicat\V10\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The single failure boundary.
 *
 * V9 had eleven overlapping safety mechanisms — a bootstrap circuit breaker, a
 * safe-mode option, a dependency gate, a module-load-failure ledger, a runtime
 * failure ledger, a template failure ledger, a server-engine ledger — and three
 * of those ledgers were written but never read by anything, so the faults they
 * recorded were invisible. One boundary, one ledger, one place to look.
 *
 * Contract:
 *   - A Throwable never escapes to the browser.
 *   - Every Throwable is recorded with enough context to find it.
 *   - Repeated failures in the same scope disable that scope, not the store.
 */
final class Guard {

	public const LEDGER = 'delicat_v10_failures';
	public const DISABLED = 'delicat_v10_disabled';

	/** Failures in one scope, within the window, before that scope is switched off. */
	private const THRESHOLD = 3;
	private const WINDOW    = 3600;

	/**
	 * Run $work. On success return its value; on failure record and return $fallback.
	 *
	 * @param mixed $fallback
	 * @return mixed
	 */
	public static function run( string $scope, callable $work, $fallback = null ) {
		if ( self::is_disabled( $scope ) ) {
			return $fallback;
		}

		try {
			return $work();
		} catch ( \Throwable $error ) {
			self::record( $scope, $error );
			return $fallback;
		}
	}

	public static function is_disabled( string $scope ): bool {
		$off = get_option( self::DISABLED, array() );
		return is_array( $off ) && ! empty( $off[ $scope ] );
	}

	public static function record( string $scope, \Throwable $error ): void {
		$entry = array(
			'scope'   => $scope,
			'message' => $error->getMessage(),
			'file'    => self::relative( $error->getFile() ),
			'line'    => $error->getLine(),
			'class'   => get_class( $error ),
			'version' => DELICAT_V10_VERSION,
			'at'      => time(),
		);

		$ledger = get_option( self::LEDGER, array() );
		$ledger = is_array( $ledger ) ? $ledger : array();
		array_unshift( $ledger, $entry );
		$ledger = array_slice( $ledger, 0, 40 );
		update_option( self::LEDGER, $ledger, false );

		/* Count only recent failures in this scope: a fault fixed a week ago
		 * must not contribute to switching the scope off today. */
		$recent = 0;
		foreach ( $ledger as $row ) {
			if ( ( $row['scope'] ?? '' ) === $scope && ( time() - (int) ( $row['at'] ?? 0 ) ) < self::WINDOW ) {
				$recent++;
			}
		}

		if ( $recent >= self::THRESHOLD ) {
			$off           = get_option( self::DISABLED, array() );
			$off           = is_array( $off ) ? $off : array();
			$off[ $scope ] = time();
			update_option( self::DISABLED, $off, false );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[delicat-v10:%s] %s in %s:%d', $scope, $error->getMessage(), $entry['file'], $entry['line'] ) );
		}
	}

	/** Absolute paths in a ledger leak the server layout into admin screens. */
	private static function relative( string $path ): string {
		return 0 === strpos( $path, DELICAT_V10_DIR )
			? substr( $path, strlen( DELICAT_V10_DIR ) )
			: basename( $path );
	}

	public static function clear(): void {
		delete_option( self::DISABLED );
		delete_option( self::LEDGER );
	}
}
