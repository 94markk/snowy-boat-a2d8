<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC51.34 — Redis-aware V9 query/object cache.
 *
 * Uses WordPress' persistent object-cache API when a Redis/Memcached drop-in is
 * active. Hosts without a persistent object cache fall back to transients.
 * Every key inherits the Builder cache version, so existing product/design
 * invalidation remains authoritative without expensive wildcard deletes.
 */
final class Delicat_Builder_V9_Query_Cache {
	private const GROUP = 'delicat_builder_v9_query';
	private static int $hits = 0;
	private static int $misses = 0;

	public static function boot(): void {
		/* RC51.35: on hosts WITHOUT a persistent object cache (Hostinger default),
		 * every cache entry lands in wp_options as a transient. WordPress only
		 * garbage-collects expired transients on core DB upgrades, so V9 query
		 * and server-fragment entries would otherwise accumulate forever and
		 * slowly bloat the options table. This sweeps expired V9 transients on
		 * ~5% of administrator requests — one bounded SELECT plus one bulk DELETE,
		 * never hundreds of per-transient DELETE calls. */
		if ( is_admin() && ! wp_doing_ajax() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_gc' ), 99 );
		}
	}

	public static function maybe_gc(): void {
		if ( ! current_user_can( 'manage_options' ) || self::persistent() || 1 !== wp_rand( 1, 20 ) ) {
			return;
		}
		global $wpdb;
		$now = time();
		$timeouts = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s AND option_value < %d
				 AND ( option_name LIKE %s OR option_name LIKE %s )
				 LIMIT 200",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now,
				'%' . $wpdb->esc_like( 'dbv9_' ) . '%',
				'%' . $wpdb->esc_like( 'delicat_builder' ) . '%'
			)
		);
		if ( empty( $timeouts ) ) {
			return;
		}
		$option_names = array();
		foreach ( $timeouts as $timeout_name ) {
			$timeout_name = (string) $timeout_name;
			$transient = substr( $timeout_name, strlen( '_transient_timeout_' ) );
			if ( '' === $transient ) { continue; }
			$option_names[] = $timeout_name;
			$option_names[] = '_transient_' . $transient;
		}
		$option_names = array_values( array_unique( $option_names ) );
		if ( ! $option_names ) { return; }
		$placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", ...$option_names ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( $option_names as $option_name ) {
			wp_cache_delete( $option_name, 'options' );
		}
	}

	public static function enabled(): bool {
		$settings = get_option( 'delicat_builder_v9_performance', array() );
		return ! is_array( $settings ) || ! array_key_exists( 'query_cache_enabled', $settings ) || ! empty( $settings['query_cache_enabled'] );
	}

	public static function persistent(): bool {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
	}

	public static function ttl(): int {
		$settings = get_option( 'delicat_builder_v9_performance', array() );
		$value = is_array( $settings ) ? absint( $settings['query_cache_ttl'] ?? 600 ) : 600;
		return min( HOUR_IN_SECONDS, max( 60, $value ) );
	}

	public static function key( string $namespace, array $payload = array() ): string {
		$namespace = sanitize_key( $namespace );
		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'key' ) ) ) {
			return Delicat_Builder_V9_Cache::key( 'query_' . $namespace, $payload );
		}
		return 'dbv9_q_' . substr( hash( 'sha256', $namespace . '|' . wp_json_encode( $payload ) ), 0, 40 );
	}

	/**
	 * @param callable $callback Callback runs only on a miss.
	 * @return mixed
	 */
	public static function remember( string $namespace, array $payload, int $ttl, callable $callback ) {
		if ( ! self::enabled() ) {
			self::$misses++;
			return $callback();
		}

		$key = self::key( $namespace, $payload );
		$ttl = min( HOUR_IN_SECONDS, max( 30, $ttl > 0 ? $ttl : self::ttl() ) );

		if ( self::persistent() ) {
			$found = false;
			$value = wp_cache_get( $key, self::GROUP, false, $found );
			if ( $found ) {
				self::$hits++;
				return $value;
			}
		} else {
			$value = get_transient( $key );
			if ( false !== $value ) {
				self::$hits++;
				return $value;
			}
		}

		self::$misses++;
		$value = $callback();

		if ( self::persistent() ) {
			wp_cache_set( $key, $value, self::GROUP, $ttl );
		} else {
			set_transient( $key, $value, $ttl );
		}

		return $value;
	}

	public static function stats(): array {
		return array(
			'hits'       => self::$hits,
			'misses'     => self::$misses,
			'persistent' => self::persistent(),
			'enabled'    => self::enabled(),
			'ttl'        => self::ttl(),
		);
	}
}
