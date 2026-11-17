<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/* 9.7.8: the must-use guard installed by the plugin goes with it (only our own file, recognised by its header). */
if ( defined( 'WPMU_PLUGIN_DIR' ) && is_file( WPMU_PLUGIN_DIR . '/delicat-guard.php' ) ) {
	$delicat_builder_v9_guard = (string) file_get_contents( WPMU_PLUGIN_DIR . '/delicat-guard.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
	if ( false !== strpos( $delicat_builder_v9_guard, 'Plugin Name: Delicat Guard' ) && false !== strpos( $delicat_builder_v9_guard, 'Installé par Delicat Builder V9' ) ) {
		wp_delete_file( WPMU_PLUGIN_DIR . '/delicat-guard.php' );
	}
	delete_option( 'delicat_builder_v9_guard_version' );
	unset( $delicat_builder_v9_guard );
}

/*
 * V10 (security review #6): credentials and personal push data, only on explicit request.
 * Like Delicat Digital Gateway (DELICAT_DIGITAL_PURGE_ON_UNINSTALL), uninstall stays
 * non-destructive unless wp-config.php says:
 *
 *     define( 'DELICAT_BUILDER_V9_PURGE_ON_UNINSTALL', true );
 *
 * Then the sealed secrets (they open with wp-config's salts, so a database copy plus
 * wp-config would still read them after the plugin is gone) and the Web Push subscriptions
 * (endpoints, keys, account ids) are deleted. Layout, design and every other setting stay
 * for a rollback or reinstall; payment intents and review votes are not touched.
 */
if ( defined( 'DELICAT_BUILDER_V9_PURGE_ON_UNINSTALL' ) && true === DELICAT_BUILDER_V9_PURGE_ON_UNINSTALL ) {
	foreach ( array(
		'delicat_builder_v9_vapid_private_enc',                 // Web Push VAPID private key (sealed)
		'delicat_builder_v9_vapid_public',                      // its public half: useless alone, and blocks a fresh pair
		'delicat_builder_v9_web_push_db_version',               // the table below is dropped
		'delicat_builder_v9_notification_service_account_enc',  // Firebase service account (sealed)
		'dsb_notification_service_account',                     // legacy plaintext copy
		'dsb_notification_fcm_server_key',                      // legacy FCM server key
		'dsb_notification_app_key',                             // app shared key
		'dsb_notification_devices',                             // FCM device tokens
	) as $delicat_builder_v9_option ) {
		delete_option( $delicat_builder_v9_option );
	}
	delete_transient( 'dbv9_notification_google_access_token' );
	/* Cloudflare: the API token goes, the zone and switches stay. */
	$delicat_builder_v9_cf = get_option( 'delicat_builder_v9_cloudflare', null );
	if ( is_array( $delicat_builder_v9_cf ) && ( isset( $delicat_builder_v9_cf['api_token'] ) || isset( $delicat_builder_v9_cf['api_token_encrypted'] ) ) ) {
		unset( $delicat_builder_v9_cf['api_token'], $delicat_builder_v9_cf['api_token_encrypted'] );
		update_option( 'delicat_builder_v9_cloudflare', $delicat_builder_v9_cf, false );
	}
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}delicat_push_subscriptions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- fixed table name.
	unset( $delicat_builder_v9_option, $delicat_builder_v9_cf );
}

/*
 * Beta 1 intentionally preserves settings on uninstall to support rollback/reinstall.
 * To remove data manually, delete:
 * - delicat_builder_v9_settings
 * - delicat_builder_v9_cache_version
 * - delicat_builder_v9_shell
 * - delicat_builder_v9_woo_ui
 * - delicat_builder_v9_native_product (Native Product Builder)
 * - dsb8_beta2_header (Header Studio v8 migration-compatible)
 * - product meta _delicat_builder_v9_native_product
 * - delicat_builder_v9_archive_builder
 * - delicat_builder_v9_archive_builder_drafts
 * - delicat_builder_v9_purchase_ui
 * - delicat_builder_v9_performance
 * - delicat_builder_v9_identity_sync
 * - delicat_builder_v9_production
 * - delicat_builder_v9_safe_mode
 * - delicat_builder_v9_safe_mode_meta (RC51.59 version-stamped trips)
 * - delicat_builder_v9_native_only_version (RC51.52+ migration stamp)
 * - delicat_builder_v9_live_selling
 * - delicat_builder_v9_footer (RC51.62 native footer)
 * - delicat_builder_v9_schema_version
 * - delicat_builder_v9_pre_rc1_backup
 * - delicat_builder_v9_last_restore_backup
 * - delicat_builder_v9_rc2_manual_gate
 * - delicat_builder_v9_design
 * - delicat_builder_v9_maintenance (RC40 Maintenance Studio, incl. the preview key)
 * - delicat_builder_v9_maintenance_boot_failure
 * - page meta _delicat_builder_v9_layout / _enabled / _revisions / _manifest
 * - uploads/delicat-builder-v9/compiled/*.css
 *
 * Generated transients expire naturally and are generation-keyed.
 */
