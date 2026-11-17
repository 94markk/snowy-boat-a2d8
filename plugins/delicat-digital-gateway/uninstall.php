<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

/*
 * Data-safe uninstall policy (v3.9.3+).
 * Scheduled callbacks are always removed when the plugin is deleted, but
 * merchant settings/credentials/ledgers are preserved unless an intentional
 * destructive purge is explicitly enabled in wp-config.php:
 *
 * define('DELICAT_DIGITAL_PURGE_ON_UNINSTALL', true);
 */
$dfr_hooks = array(
    'dfr_poll_remote_order',
    'dfr_catalog_sync_event',
    'dfr_reconcile_recent_deliveries',
    'dfr_retry_order_item',
    'dfr_process_verified_webhook',
    'dfr_fast_reconcile_remote_order',
    'dfr_finalize_completed_order',
    'dfr_repair_stuck_reconcile',
    'dfr_reseller_poll_order',
    'dfr_reseller_retry_supplier',
    'dfr_reconcile_plan_tick',
    'dfr_reconcile_watchdog',
    'dfr_gold_plan_renewal',
    'dfr_public_privacy_migration_batch',
    'dfr_catalog_sync_batch',
    'dfr_keyring_migration_batch',
    'dfr_reseller_keyring_migration_batch',
    // 4.17-4.19 fast supplier synchronization.
    'dfr_catalog_sync_continue',
    'dfr_balance_sync_event',
    // 4.15+ fulfilment queue and alerts, 4.24 background jobs.
    'dfr_fulfill_paid_order',
    'dfr_send_delivery_failure_alert',
    'dfr_send_attestation_refusal_alert',
    'dfr_catalog_sync_start',
    'dfr_uid_validation_games_refresh',
);
foreach ($dfr_hooks as $hook) {
    // Every WP-Cron event of the hook, whatever its arguments: wp_clear_scheduled_hook()
    // alone left the per-order events and the every-minute watchdog (args: wp_cron).
    if (function_exists('wp_unschedule_hook')) { wp_unschedule_hook($hook); } else { wp_clear_scheduled_hook($hook); }
    if (function_exists('as_unschedule_all_actions') && did_action('action_scheduler_init')) {
        // Hook only (every group, every argument list): an args filter of array() matched
        // argument-less jobs only and left every per-order job queued.
        as_unschedule_all_actions($hook);
    }
}

if (!defined('DELICAT_DIGITAL_PURGE_ON_UNINSTALL') || DELICAT_DIGITAL_PURGE_ON_UNINSTALL !== true) {
    return;
}

foreach (array(
    'dfr_settings', 'dfr_catalog_last_sync', 'dfr_catalog_last_import',
    'dfr_reseller_settings', 'dfr_reseller_schema_version', 'dfr_plugin_version',
    'dfr_wallet_attestation_required_after', 'dfr_wallet_attestation_legacy_max_order_id',
    'dfr_public_privacy_migrated_v391', 'dfr_public_privacy_migrated_v393',
    'dfr_public_privacy_terms_v393', 'dfr_public_privacy_migration_state_v393', 'dfr_uid_global_window_v1',
    'dfr_completion_repair_pending_v491', 'dfr_completion_repair_pending_v492',
    'dfr_reconcile_repair_pending_v410', 'dfr_reconcile_repair_runs_v410',
    'dfr_reconcile_repair_pending_v413', 'dfr_reconcile_repair_runs_v413',
    'dfr_reconcile_repair_pending_v4131', 'dfr_reconcile_repair_runs_v4131',
    'dfr_reconcile_repair_pending_v414', 'dfr_reconcile_repair_runs_v414',
    'dfr_reconcile_watchdog_version', 'dfr_sync_watchdog_health_v1',
    'dfr_webhook_health_v1', 'dfr_webhook_last_received_v1',
    'dfr_catalog_sync_groups_v1', 'dfr_catalog_sync_health_v1', 'dfr_catalog_schema_changes_v1',
    'dfr_margin_guard_v1', 'dfr_margin_alerts_v1', 'dfr_auto_terms_tagged_v1',
    'dfr_reseller_keyring_migration_v1',
    'dfr_catalog_sync_cursor', 'dfr_catalog_last_batch_errors', 'dfr_live_sync_migrated_v417',
    // 4.15+ audit log, encryption health and key-ring migration state.
    'dfr_admin_audit_log_v1', 'dfr_crypto_sentinel_v2', 'dfr_keyring_migration_v1'
) as $option) { delete_option($option); }

// 4.19.0 remembers the last five Player IDs on the customer's account.
foreach (array('_dfr_recent_player_ids_v1') as $dfr_user_meta) {
    delete_metadata('user', 0, $dfr_user_meta, '', true);
}

global $wpdb;
foreach (array('dfr_remote_', 'dfr_poll_attempt_', 'dfr_fast_worker_', 'dfr_supplier_poll_', 'dfr_order_lock_') as $prefix) {
    $like = $wpdb->esc_like($prefix) . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
}
foreach (array('_transient_dfr_', '_transient_timeout_dfr_') as $prefix) {
    $like = $wpdb->esc_like($prefix) . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
}

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dfr_api_clients");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dfr_api_orders");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dfr_api_plan_events");

$meta_keys = array(
    '_dfr_reseller_approved', '_dfr_gold_trial_used', '_dfr_gold_trial_started',
    '_dfr_gold_trial_ends', '_dfr_gold_paid_until', '_dfr_gold_auto_renew',
);
foreach ($meta_keys as $meta_key) {
    $wpdb->delete($wpdb->usermeta, array('meta_key' => $meta_key));
}
