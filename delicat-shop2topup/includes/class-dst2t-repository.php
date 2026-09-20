<?php

defined( 'ABSPATH' ) || exit;

final class DST2T_Repository {
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'dst2t_orders';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider_order_id varchar(64) NOT NULL,
			wc_order_id bigint(20) unsigned NOT NULL,
			wc_item_id bigint(20) unsigned NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'intent',
			attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
			next_check_at datetime NULL,
			last_event_at datetime NULL,
			last_error_code varchar(64) NOT NULL DEFAULT '',
			payload_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY provider_order_id (provider_order_id),
			KEY wc_order_id (wc_order_id),
			KEY due_status (status,next_check_at)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'dst2t_db_version', DST2T_VERSION, false );
	}

	public function register_intent( $provider_order_id, $wc_order_id, $wc_item_id, $payload_hash ) {
		global $wpdb;
		$table = self::table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$sql = $wpdb->prepare(
			"INSERT INTO {$table}
			(provider_order_id, wc_order_id, wc_item_id, status, next_check_at, payload_hash, created_at, updated_at)
			VALUES (%s, %d, %d, 'intent', %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE provider_order_id = VALUES(provider_order_id)",
			(string) $provider_order_id,
			absint( $wc_order_id ),
			absint( $wc_item_id ),
			$now,
			(string) $payload_hash,
			$now,
			$now
		);

		return false !== $wpdb->query( $sql );
	}

	public function get( $provider_order_id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE provider_order_id = %s LIMIT 1", (string) $provider_order_id ),
			ARRAY_A
		);
	}

	public function update( $provider_order_id, $status, $args = array() ) {
		global $wpdb;
		$table = self::table_name();

		$data = array(
			'status'     => sanitize_key( (string) $status ),
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$formats = array( '%s', '%s' );

		if ( array_key_exists( 'next_check_at', $args ) ) {
			$data['next_check_at'] = $args['next_check_at'] ? gmdate( 'Y-m-d H:i:s', (int) $args['next_check_at'] ) : null;
			$formats[]              = '%s';
		}
		if ( ! empty( $args['increment_attempt'] ) ) {
			$wpdb->query(
				$wpdb->prepare( "UPDATE {$table} SET attempt_count = attempt_count + 1 WHERE provider_order_id = %s", (string) $provider_order_id )
			);
		}
		if ( array_key_exists( 'last_error_code', $args ) ) {
			$data['last_error_code'] = substr( sanitize_key( (string) $args['last_error_code'] ), 0, 64 );
			$formats[]                = '%s';
		}
		if ( ! empty( $args['last_event_at'] ) ) {
			$data['last_event_at'] = gmdate( 'Y-m-d H:i:s', (int) $args['last_event_at'] );
			$formats[]             = '%s';
		}

		return false !== $wpdb->update(
			$table,
			$data,
			array( 'provider_order_id' => (string) $provider_order_id ),
			$formats,
			array( '%s' )
		);
	}

	public function due( $limit = 50 ) {
		global $wpdb;
		$table    = self::table_name();
		$limit    = min( 50, max( 1, absint( $limit ) ) );
		$statuses = "'intent','submitting','unknown','pending','processing','retrying','partial'";
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status IN ({$statuses})
				 AND (next_check_at IS NULL OR next_check_at <= %s)
				 ORDER BY updated_at ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s' ),
				$limit
			),
			ARRAY_A
		);
	}

	public function recent( $limit = 20 ) {
		global $wpdb;
		$table = self::table_name();
		$limit = min( 100, max( 1, absint( $limit ) ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * Paginated listing for the admin Orders tab.
	 *
	 * @param string $status Optional exact status filter.
	 * @param int    $limit  Rows per page.
	 * @param int    $offset Row offset.
	 */
	public function search( $status = '', $limit = 25, $offset = 0 ) {
		global $wpdb;
		$table  = self::table_name();
		$limit  = min( 100, max( 1, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );
		$status = sanitize_key( (string) $status );

		if ( '' !== $status ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d", $status, $limit, $offset ),
				ARRAY_A
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);
	}

	public function count_matching( $status = '' ) {
		global $wpdb;
		$table  = self::table_name();
		$status = sanitize_key( (string) $status );
		if ( '' !== $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$table} WHERE status = %s", $status ) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function counts() {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[ sanitize_key( $row['status'] ) ] = absint( $row['total'] );
		}
		return $out;
	}

	public function is_stale_event( $provider_order_id, $event_timestamp ) {
		$row = $this->get( $provider_order_id );
		if ( ! $row || empty( $row['last_event_at'] ) || ! $event_timestamp ) {
			return false;
		}
		return strtotime( $row['last_event_at'] . ' UTC' ) > (int) $event_timestamp;
	}
}

