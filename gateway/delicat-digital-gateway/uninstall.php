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
);
foreach ($dfr_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
    if (function_exists('as_unschedule_all_actions') && did_action('action_scheduler_init')) {
        foreach (array('delicat-fazercards', 'delicat-digital-gateway') as $group) {
            as_unschedule_all_actions($hook, array(), $group);
        }
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
    'dfr_webhook_health_v1', 'dfr_webhook_last_received_v1'
) as $option) { delete_option($option); }

global $wpdb;
foreach (array('dfr_remote_', 'dfr_poll_attempt_', 'dfr_fast_worker_', 'dfr_supplier_poll_') as $prefix) {
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
