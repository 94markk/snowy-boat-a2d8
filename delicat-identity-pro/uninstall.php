<?php
// Intentionally non-destructive. Customer identity metadata and settings are preserved.
defined('WP_UNINSTALL_PLUGIN') || exit;

// Only the plugin's WP-Cron events go: with the plugin gone they would fire
// forever without a callback. Every instance, whatever its arguments.
foreach ([
    'dip_audit_cleanup', 'dip_device_cleanup', 'dip_automatic_migration_tick', 'dip_analytics_daily_rollup',
    'dip_performance_maintenance', 'dip_mobile_cleanup', 'dip_app_pairing_cleanup', 'dip_flow_claim_cleanup',
    'dip_headless_google_cleanup',
] as $dip_hook) {
    wp_unschedule_hook($dip_hook);
}
