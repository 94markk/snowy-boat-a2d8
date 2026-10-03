<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
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
