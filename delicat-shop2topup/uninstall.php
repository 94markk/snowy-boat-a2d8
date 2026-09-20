<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'dst2t_settings', array() );
if ( ! is_array( $settings ) || 'yes' !== ( isset( $settings['delete_data_on_uninstall'] ) ? $settings['delete_data_on_uninstall'] : 'no' ) ) {
	return;
}

global $wpdb;

$table = $wpdb->prefix . 'dst2t_orders';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$product_meta_keys = array(
	'_dst2t_enabled',
	'_dst2t_item_id',
	'_dst2t_category_id',
	'_dst2t_provider_name',
	'_dst2t_requirements_schema',
	'_dst2t_last_cost_usd',
	'_dst2t_max_cost_usd',
	'_dst2t_returns_voucher',
	'_dst2t_sync_error',
	'_dst2t_last_sync',
);

$order_item_meta_keys = array(
	'_dst2t_product_id',
	'_dst2t_item_id',
	'_dst2t_category_id',
	'_dst2t_requirements',
	'_dst2t_order_id',
	'_dst2t_status',
	'_dst2t_charged_amount',
	'_dst2t_vouchers',
	'_dst2t_player_name',
	'_dst2t_returns_voucher',
);

foreach ( $product_meta_keys as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}

foreach ( $order_item_meta_keys as $meta_key ) {
	$wpdb->delete( $wpdb->prefix . 'woocommerce_order_itemmeta', array( 'meta_key' => $meta_key ), array( '%s' ) );
}

// Order notes are de-duplicated with hashed meta keys, so they can only be
// removed by prefix. Both the classic and the HPOS meta tables are covered.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_dst2t\_note\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
if ( $hpos_meta === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta ) ) ) {
	$wpdb->query( "DELETE FROM {$hpos_meta} WHERE meta_key LIKE '\_dst2t\_note\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dst2t\_lock\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_dst2t\_%' OR option_name LIKE '\_transient\_timeout\_dst2t\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

foreach (
	array(
		'dst2t_settings',
		'dst2t_db_version',
		'dst2t_last_account_check',
		'dst2t_last_webhook_test',
		'dst2t_last_webhook_event',
		'dst2t_webhook_token',
		'dst2t_account_state',
		'dst2t_balance_scheduled_interval',
		'dst2t_low_balance_alerted_at',
		'dst2t_sync_cursor',
		'dst2t_sync_state',
		'dst2t_sync_scheduled_interval',
		'dst2t_sku_salt',
	) as $option
) {
	delete_option( $option );
}
