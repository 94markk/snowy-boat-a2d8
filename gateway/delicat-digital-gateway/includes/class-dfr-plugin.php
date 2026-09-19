<?php
if (!defined('ABSPATH')) {
    exit;
}

final class DFR_Plugin {
    private static $instance;
    private $settings = array();
    private $completion_candidates = array();
    private $post_response_reconcile = array();
    private $internal_reconcile_active = false;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        $defaults = self::defaults();
        $existing = get_option('dfr_settings', array());
        update_option('dfr_settings', wp_parse_args(is_array($existing) ? $existing : array(), $defaults), false);
        if (!get_option('dfr_wallet_attestation_required_after', false)) {
            add_option('dfr_wallet_attestation_required_after', time(), '', false);
        }
        if ((string) get_option('dfr_plugin_version', '') === '') {
            update_option('dfr_plugin_version', DFR_VERSION, false);
        }
        flush_rewrite_rules(false);
    }

    public static function deactivate() {
        foreach (array(
            'dfr_poll_remote_order',
            'dfr_reconcile_recent_deliveries',
            'dfr_retry_order_item',
            'dfr_process_verified_webhook',
            'dfr_fast_reconcile_remote_order',
            'dfr_reconcile_plan_tick',
            'dfr_reconcile_watchdog',
            'dfr_finalize_completed_order',
            'dfr_repair_stuck_reconcile',
        ) as $hook) {
            wp_clear_scheduled_hook($hook);
            if (function_exists('as_unschedule_all_actions') && did_action('action_scheduler_init')) {
                as_unschedule_all_actions($hook, array(), 'delicat-fazercards');
            }
        }
        if (class_exists('DFR_Catalog')) { DFR_Catalog::deactivate(); }
        flush_rewrite_rules(false);
    }

    private static function defaults() {
        return array(
            'api_key_enc'              => '',
            'webhook_secret_enc'       => '',
            'live_orders_enabled'      => '0',
            'auto_fulfill_enabled'     => '0',
            'auto_complete_woo'        => '1',
            'customer_codes_enabled'   => '1',
            'logging_enabled'          => '1',
            'catalog_pricing_mode'     => 'manual',
            'usd_to_store_rate'        => '0',
            'catalog_markup_percent'   => '10',
            'catalog_fixed_markup'     => '0',
            'catalog_rounding'         => '1',
            'catalog_import_status'    => 'draft',
            'catalog_cache_ttl'        => '600',
            'catalog_price_sync'       => '0',
            'catalog_disable_missing'  => '1',
            'catalog_update_titles'    => '0',
            'native_fields_enabled'    => '1',
            'uid_validation_enabled'   => '1',
            'uid_validation_required'  => '1',
            'catalog_auto_sync'        => '0',
            'catalog_sync_interval'    => 'hourly',
            'topup_import_structure'      => 'variable',
            'topup_variation_attribute'   => 'Package',
            'giftcard_import_structure'   => 'variable',
            'giftcard_variation_attribute'=> 'Amount',
            'gamekey_import_structure'    => 'variable',
            'gamekey_variation_attribute' => 'Option',
        );
    }

    private function maybe_upgrade_settings() {
        $stored = get_option('dfr_settings', array());
        $stored = is_array($stored) ? $stored : array();

        // v2.0 did not have an explicit pricing mode and defaulted price sync ON.
        // Migrate those installations to the safer manual-price model without
        // touching any existing WooCommerce product prices.
        if (!array_key_exists('catalog_pricing_mode', $stored)) {
            $stored['catalog_pricing_mode'] = 'manual';
            $stored['catalog_price_sync'] = '0';
            update_option('dfr_settings', wp_parse_args($stored, self::defaults()), false);
        }

        // v2.2 groups game top-up offers into one native WooCommerce variable product.
        // Existing imported simple products are never deleted or repriced during upgrade.
        if (!array_key_exists('topup_import_structure', $stored)) {
            $stored['topup_import_structure'] = 'variable';
            $stored['topup_variation_attribute'] = 'Package';
            update_option('dfr_settings', wp_parse_args($stored, self::defaults()), false);
        }

        // v3.2 extends native grouped-variable imports to gift cards and game keys.
        // Re-importing a supplier category creates one parent product with denomination/
        // edition variations while legacy simple products are preserved for safe review.
        if (!array_key_exists('giftcard_import_structure', $stored) || !array_key_exists('gamekey_import_structure', $stored)) {
            $stored['giftcard_import_structure'] = 'variable';
            $stored['giftcard_variation_attribute'] = 'Amount';
            $stored['gamekey_import_structure'] = 'variable';
            $stored['gamekey_variation_attribute'] = 'Option';
            update_option('dfr_settings', wp_parse_args($stored, self::defaults()), false);
        }

        // v3.5 adds UID validation to the consolidated delivery/encryption branch.
        // Keep it enabled by default and force a fresh dynamic support list so regional
        // products can resolve against FazerCards' canonical validation categories.
        if (!array_key_exists('uid_validation_enabled', $stored)) {
            $stored['uid_validation_enabled'] = '1';
            $stored['uid_validation_required'] = '1';
            update_option('dfr_settings', wp_parse_args($stored, self::defaults()), false);
        }
        delete_transient('dfr_uid_validation_games_v1');

        // v3.3 makes code delivery customer-facing by default. Previously the code display
        // toggle could remain disabled after a successful supplier fulfillment, leaving the
        // encrypted code stored but invisible in My Account.
        $previous_version = (string) get_option('dfr_plugin_version', '');
        if ($previous_version !== '' && version_compare($previous_version, '3.3.0', '<')) {
            $stored['customer_codes_enabled'] = '1';
            update_option('dfr_settings', wp_parse_args($stored, self::defaults()), false);
        }

        // v3.6 repairs previously completed WooCommerce gift-card/game-key orders that
        // still have a supplier order id but no encrypted delivery code. Replacement of
        // an active plugin does not always run the activation hook, so queue this from
        // the version migration path as well.
        if ($previous_version !== '' && version_compare($previous_version, '3.6.0', '<')) {
            if (!wp_next_scheduled('dfr_reconcile_recent_deliveries')) {
                wp_schedule_single_event(time() + 20, 'dfr_reconcile_recent_deliveries', array(1));
            }
        }

        // v3.7 recognizes the current FazerCards delivery fields (`order.cards[]`
        // for gift cards and `order.keys[]` for game keys) and fixes the current
        // terminal refund status name (`refund`). Re-scan recent completed code orders
        // so previously stuck deliveries are repaired without another purchase.
        if ($previous_version !== '' && version_compare($previous_version, '3.7.0', '<')) {
            if (!wp_next_scheduled('dfr_reconcile_recent_deliveries')) {
                wp_schedule_single_event(time() + 10, 'dfr_reconcile_recent_deliveries', array(1));
            }
        }

        // v3.9 expands UID verification to every validation-capable top-up in the
        // live FazerCards validation catalog. Force a fresh support/schema list so
        // PUBG, Call of Duty Mobile (when exposed by FazerCards), MLBB and future
        // validation-capable games can be resolved without stale v3.5 mappings.
        if ($previous_version !== '' && version_compare($previous_version, '3.9.0', '<')) {
            delete_transient('dfr_uid_validation_games_v1');
            delete_transient('dfr_uid_validation_games_v2');
            delete_transient('dfr_uid_validation_games_v3');
        }

        // v3.9.2 changes unsupported live UID verification from fail-closed to advisory.
        // Validation-capable games can still require a fresh server verification, but a
        // top-up is never blocked solely because live account-name validation is unavailable.
        if ($previous_version !== '' && version_compare($previous_version, '3.9.2', '<')) {
            unset($stored['uid_validation_strict_player_fields']);
            update_option('dfr_settings', wp_parse_args($stored, self::defaults()), false);
            delete_transient('dfr_uid_validation_games_v1');
            delete_transient('dfr_uid_validation_games_v2');
            delete_transient('dfr_uid_validation_games_v3');
        }

        // v3.9.3 removes the obsolete strict-mode setting entirely and restarts the
        // white-label privacy migration using a cursor-based background scanner. This
        // avoids the old 1,000-product ceiling and guarantees collision-safe neutral
        // public slugs/SKUs without touching private supplier mappings.
        if ($previous_version !== '' && version_compare($previous_version, '3.9.3', '<')) {
            unset($stored['uid_validation_strict_player_fields']);
            update_option('dfr_settings', wp_parse_args($stored, self::defaults()), false);
            delete_option('dfr_public_privacy_migrated_v391');
            delete_option('dfr_public_privacy_migrated_v393');
            delete_option('dfr_public_privacy_terms_v393');
            delete_option('dfr_public_privacy_migration_state_v393');
            if (!wp_next_scheduled('dfr_public_privacy_migration_batch')) {
                wp_schedule_single_event(time() + 5, 'dfr_public_privacy_migration_batch');
            }
        }

        // v3.8 gives supplier credentials separate cryptographic contexts instead of
        // sharing the generic envelope. Old encrypted values are decrypted in memory and
        // rewrapped once; plaintext is never written back to WordPress options.
        if ($previous_version !== '' && version_compare($previous_version, '3.8.0', '<')) {
            $secret_contexts = array(
                'api_key_enc' => 'supplier-api-key',
                'webhook_secret_enc' => 'supplier-webhook-secret',
            );
            $changed_secrets = false;
            foreach ($secret_contexts as $setting_key => $crypto_context) {
                $stored_secret = isset($stored[$setting_key]) ? (string) $stored[$setting_key] : '';
                if ($stored_secret === '') { continue; }
                $already = DFR_Crypto::decrypt_context($stored_secret, $crypto_context);
                if ($already !== '') { continue; }
                $plain_secret = DFR_Crypto::decrypt($stored_secret);
                if ($plain_secret === '') { continue; }
                $rewrapped = DFR_Crypto::encrypt_context($plain_secret, $crypto_context);
                if (!is_wp_error($rewrapped)) {
                    $stored[$setting_key] = $rewrapped;
                    $changed_secrets = true;
                }
            }
            if ($changed_secrets) {
                update_option('dfr_settings', wp_parse_args($stored, self::defaults()), false);
            }
        }

        // v4.9.1 fixes a completion-gate regression: live auto-fulfillment could
        // successfully receive supplier `completed` while WooCommerce stayed Processing
        // because auto_complete_woo historically defaulted OFF. For an installation that
        // has explicitly enabled live + automatic fulfillment, completion is part of the
        // same secure fulfillment contract and is therefore forced ON. Queue a local-only
        // repair pass for recent Processing/On-hold orders; the repair re-verifies the
        // wallet attestation and every tracked item before changing WooCommerce status.
        if ($previous_version !== '' && version_compare($previous_version, '4.9.1', '<')) {
            if (($stored['live_orders_enabled'] ?? '0') === '1' && ($stored['auto_fulfill_enabled'] ?? '0') === '1') {
                $stored['auto_complete_woo'] = '1';
                update_option('dfr_settings', wp_parse_args($stored, self::defaults()), false);
                update_option('dfr_completion_repair_pending_v492', '1', false);
            update_option('dfr_completion_repair_pending_v491', '1', false);
            }
        }

        // v4.9.2 makes WooCommerce completion self-verifying and race-resistant.
        // Supplier-completed orders are reloaded from the database and finalized locally
        // after the payment/status hook chain. Queue a one-time local repair for recent
        // Processing/On-hold orders that already have canonical supplier completion.
        if ($previous_version !== '' && version_compare($previous_version, '4.9.2', '<')) {
            update_option('dfr_completion_repair_pending_v492', '1', false);
        }

        // v4.10 removes the first-delivery dependency on Action Scheduler/WP-Cron.
        // Some shared-hosting queues accept an async action immediately but do not run it
        // for 30-120+ seconds. Queue a small repair sweep for recent Processing orders;
        // the sweep dispatches signed single-use server loopbacks and never creates a
        // second supplier order.
        if ($previous_version !== '' && version_compare($previous_version, '4.10.0', '<')) {
            update_option('dfr_reconcile_repair_pending_v410', '1', false);
            delete_option('dfr_reconcile_repair_runs_v410');
        }

        // v4.13 closes the gap between the first two-second loopback observation and a
        // delayed Action Scheduler runner. Re-scan recent in-flight orders through the
        // new chained, cron-independent watcher. This is GET-only reconciliation and can
        // never create or charge a second supplier order.
        if ($previous_version !== '' && version_compare($previous_version, '4.13.0', '<')) {
            update_option('dfr_reconcile_repair_pending_v413', '1', false);
            delete_option('dfr_reconcile_repair_runs_v413');
        }

        // v4.13.1 fixes a lost-successor race: a running fast Action Scheduler action
        // could be mistaken for a future queued action, causing reconciliation to stop
        // after one processing read. Re-scan recent in-flight orders immediately.
        if ($previous_version !== '' && version_compare($previous_version, '4.13.1', '<')) {
            update_option('dfr_reconcile_repair_pending_v4131', '1', false);
            delete_option('dfr_reconcile_repair_runs_v4131');
        }

        // v4.14.1 hardens the interactive Player ID validator against a stale
        // dynamic FazerCards validation schema. Force a fresh category/field list once
        // after upgrade; no customer identifiers or supplier secrets are persisted.
        if ($previous_version !== '' && version_compare($previous_version, '4.14.1', '<')) {
            delete_transient('dfr_uid_validation_games_v1');
            delete_transient('dfr_uid_validation_games_v2');
            delete_transient('dfr_uid_validation_games_v3');
        }

        // v4.14.2 adds a read-only authenticated Player ID server diagnostic.
        // It never stores the entered Player ID and never creates an upstream order.
        if ($previous_version !== '' && version_compare($previous_version, '4.14.2', '<')) {
            delete_transient('dfr_uid_validation_games_v1');
            delete_transient('dfr_uid_validation_games_v2');
            delete_transient('dfr_uid_validation_games_v3');
        }

        // v4.14 replaces successor-dependent polling with independently scheduled
        // canonical checks plus a recurring repair watchdog. Re-arm every recent
        // in-flight item so upgrades recover orders stranded by any older runner.
        if ($previous_version !== '' && version_compare($previous_version, '4.14.0', '<')) {
            update_option('dfr_reconcile_repair_pending_v414', '1', false);
            delete_option('dfr_reconcile_repair_runs_v414');
        }

        // v4.8 cryptographically binds a successful TeraWallet debit to the exact
        // WooCommerce order/customer/amount/currency at the wallet payment event. New
        // orders created after this migration fail closed if that attestation is absent.
        // Older in-flight orders retain the v4.7 ledger proof so upgrades do not strand
        // legitimate purchases that were already paid before the new hook existed.
        if (!get_option('dfr_wallet_attestation_required_after', false)) {
            add_option('dfr_wallet_attestation_required_after', time(), '', false);
        }

        if ((string) get_option('dfr_plugin_version', '') !== DFR_VERSION) {
            update_option('dfr_plugin_version', DFR_VERSION, false);
        }
    }

    public function boot() {
        $this->maybe_upgrade_settings();
        $this->settings = wp_parse_args((array) get_option('dfr_settings', array()), self::defaults());
        $this->initialize_wallet_attestation_boundary();

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_notices', array($this, 'legacy_folder_privacy_notice'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('add_meta_boxes', array($this, 'add_product_meta_box'));
        add_action('save_post_product', array($this, 'save_product_meta'), 10, 2);
        add_action('woocommerce_product_after_variable_attributes', array($this, 'render_variation_supplier_mapping'), 10, 3);
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('template_redirect', array($this, 'protect_customer_account_cache'), 1);
        add_action('dfr_poll_remote_order', array($this, 'poll_remote_order'), 10, 3);
        add_action('dfr_reconcile_recent_deliveries', array($this, 'reconcile_recent_deliveries'), 10, 1);
        add_action('dfr_retry_order_item', array($this, 'retry_order_item'), 10, 2);
        add_action('dfr_process_verified_webhook', array($this, 'process_verified_webhook'), 10, 4);
        add_action('dfr_fast_reconcile_remote_order', array($this, 'fast_reconcile_remote_order'), 10, 4);
        add_action('dfr_reconcile_plan_tick', array($this, 'reconcile_plan_tick'), 10, 5);
        add_action('dfr_reconcile_watchdog', array($this, 'reconcile_watchdog'), 10, 1);
        add_action('dfr_finalize_completed_order', array($this, 'finalize_completed_order'), 10, 1);
        add_filter('cron_schedules', array($this, 'add_reconcile_cron_schedule'));
        add_action('init', array($this, 'ensure_reconcile_watchdog'), 20);
        add_action('init', array($this, 'repair_completed_processing_orders_once'), 99);
        add_action('init', array($this, 'repair_stuck_reconcile_once'), 100);
        add_action('wp_ajax_nopriv_dfr_internal_reconcile', array($this, 'ajax_internal_reconcile'));
        add_action('wp_ajax_dfr_internal_reconcile', array($this, 'ajax_internal_reconcile'));
        // Last-stage local finalizer: runs after the current payment/webhook request has
        // finished its status hooks. It performs no supplier request and therefore adds
        // no network latency, while preventing a later hook in the same request from
        // leaving an already-fulfilled order stuck in Processing.
        // Primary cron-independent delivery reconciler. On PHP-FPM, the browser response
        // is finished first and supplier status is then reconciled in the same worker, so
        // checkout remains fast even when WP-Cron/Action Scheduler is stalled.
        add_action('shutdown', array($this, 'run_post_response_reconcile'), PHP_INT_MAX - 20);
        add_action('shutdown', array($this, 'flush_completion_candidates'), PHP_INT_MAX);

        // Delicat digital fulfillment is wallet-only. Restrict checkout choices for
        // carts containing mapped digital items, then verify the wallet debit again
        // server-side before any supplier balance can be spent.
        add_filter('woocommerce_available_payment_gateways', array($this, 'restrict_digital_checkout_to_wallet'), 999);
        add_filter('woocommerce_hidden_order_itemmeta', array($this, 'hide_sensitive_order_item_meta'), 999);

        // Capture an immutable, site-keyed attestation at the exact TeraWallet debit
        // event. This is local-only cryptography/DB work and adds no supplier latency.
        add_action('woo_wallet_payment_processed', array($this, 'capture_wallet_payment_attestation'), 20, 2);

        add_action('woocommerce_order_status_processing', array($this, 'maybe_auto_fulfill_order'));
        add_action('woocommerce_payment_complete', array($this, 'maybe_auto_fulfill_order'));
        // Re-run only the local completion gate after every other normal payment-complete
        // callback has had a chance to finish. The supplier is never called here.
        add_action('woocommerce_payment_complete', array($this, 'finalize_completed_order'), PHP_INT_MAX, 1);
        add_action('woocommerce_order_payment_status_changed', array($this, 'maybe_auto_fulfill_order'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'render_customer_sync_heartbeat'), 4);
        add_action('woocommerce_order_details_after_order_table', array($this, 'render_customer_delivery_panel'), 5);
        add_filter('woocommerce_my_account_my_orders_columns', array($this, 'add_delivery_order_column'), 30);
        add_action('woocommerce_my_account_my_orders_column_dfr-delivery', array($this, 'render_delivery_order_column'));
        add_action('woocommerce_thankyou', array($this, 'maybe_auto_fulfill_order'), 5);
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'admin_queue_order_reconcile'), 5);

        // Delicat Mes Achats / transaction-history bridge. Enriches [delica_orders]
        // rows without replacing the existing shortcode or its design.
        add_filter('delica_extra_rows', array($this, 'sync_delica_transaction_rows'), 20, 3);
        add_action('wp_ajax_dfr_delica_reveal_codes', array($this, 'ajax_delica_reveal_codes'));
        add_action('wp_ajax_dfr_delica_delivery_status', array($this, 'ajax_delica_delivery_status'));
        add_action('wp_ajax_dfr_customer_order_sync_status', array($this, 'ajax_customer_order_sync_status'));
        add_action('wp_ajax_dfr_delica_nonce', array($this, 'ajax_delica_nonce'));

        add_filter('plugin_action_links_' . plugin_basename(DFR_FILE), array($this, 'plugin_action_links'));

        if (class_exists('DFR_Catalog')) {
            DFR_Catalog::instance()->boot();
        }
    }

    /**
     * Digital delivery pages contain authenticated order data and may contain a
     * decrypted code after ownership checks. Never allow a page cache/CDN plugin
     * to persist a logged-in WooCommerce account response.
     */
    public function protect_customer_account_cache() {
        if (!is_user_logged_in() || !function_exists('is_account_page') || !is_account_page()) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        if (!defined('DONOTCACHEOBJECT')) { define('DONOTCACHEOBJECT', true); }
        if (function_exists('nocache_headers') && !headers_sent()) { nocache_headers(); }
        if (!headers_sent()) {
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-LiteSpeed-Cache-Control: no-cache, no-store');
        }
        do_action('litespeed_control_set_nocache', 'delicat fazer authenticated delivery');
    }

    public function plugin_action_links($links) {
        $url = admin_url('admin.php?page=delicat-fazercards');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'delicat-fazercards') . '</a>');
        return $links;
    }

    private function decrypt_setting_secret($encoded, $context) {
        $encoded = (string) $encoded;
        if ($encoded === '') { return ''; }
        $plain = DFR_Crypto::decrypt_context($encoded, $context);
        if ($plain === '') {
            // Backward compatibility for v3.7 and earlier generic envelopes.
            $plain = DFR_Crypto::decrypt($encoded);
        }
        return is_string($plain) ? trim($plain) : '';
    }

    private function api_key() {
        if (defined('DELICAT_FAZER_API_KEY') && DELICAT_FAZER_API_KEY) {
            return trim((string) DELICAT_FAZER_API_KEY);
        }
        return $this->decrypt_setting_secret($this->settings['api_key_enc'], 'supplier-api-key');
    }

    private function webhook_secret() {
        if (defined('DELICAT_FAZER_WEBHOOK_SECRET') && DELICAT_FAZER_WEBHOOK_SECRET) {
            return trim((string) DELICAT_FAZER_WEBHOOK_SECRET);
        }
        return $this->decrypt_setting_secret($this->settings['webhook_secret_enc'], 'supplier-webhook-secret');
    }

    public function api() {
        return new DFR_API($this->api_key(), array($this, 'log'));
    }

    /** Normalize supplier statuses to a small, stable internal vocabulary. */
    private function normalize_supplier_status($status) {
        $status = sanitize_key((string) $status);
        if (in_array($status, array('completed', 'complete', 'success', 'succeeded'), true)) { return 'completed'; }
        if (in_array($status, array('failed', 'failure', 'error', 'cancelled', 'canceled'), true)) { return 'failed'; }
        if (in_array($status, array('refund', 'refunded'), true)) { return 'refunded'; }
        return 'processing';
    }

    private function jitter_delay($seconds) {
        $seconds = max(1, (int) $seconds);
        $spread = max(1, (int) floor($seconds * 0.15));
        try {
            return max(1, $seconds + random_int(-$spread, $spread));
        } catch (Throwable $e) {
            return $seconds;
        }
    }


    /**
     * Short retry profiles for transient supplier/network conditions. Long waits are
     * reserved for an explicit supplier Retry-After. Idempotent order creation can be
     * retried quickly because the immutable request uses the same Idempotency-Key.
     */
    private function adaptive_retry_delay($attempt, $profile = 'poll') {
        $attempt = max(1, (int) $attempt);
        $profiles = array(
            'create'     => array(3, 6, 10, 20, 35, 60),
            'poll'       => array(3, 5, 8, 12, 20, 30, 45, 60, 90, 120),
            'code'       => array(2, 3, 5, 8, 12, 20, 30, 45, 60),
            'validation' => array(5, 8, 12, 20, 30, 45),
            'webhook'    => array(3, 6, 12, 20, 35, 60),
        );
        $series = isset($profiles[$profile]) ? $profiles[$profile] : $profiles['poll'];
        return (int) $series[min($attempt, count($series)) - 1];
    }

    private function mark_order_timing($order, $key, $value = '') {
        if (!$order || !is_object($order) || !method_exists($order, 'update_meta_data')) { return; }
        $key = sanitize_key((string) $key);
        if ($key === '') { return; }
        if ($value === '') { $value = gmdate('c'); }
        $order->update_meta_data('_dfr_timing_' . $key, is_scalar($value) ? (string) $value : '');
        $order->save_meta_data();
    }

    private function mark_item_timing($item, $key, $value = '') {
        if (!$item || !is_object($item) || !method_exists($item, 'update_meta_data')) { return; }
        $key = sanitize_key((string) $key);
        if ($key === '') { return; }
        if ($value === '') { $value = gmdate('c'); }
        $item->update_meta_data('_dfr_timing_' . $key, is_scalar($value) ? (string) $value : '');
        $item->save();
    }

    private function mark_item_delay_reason($item, $reason) {
        if (!$item || !is_object($item)) { return; }
        $reason = sanitize_key((string) $reason);
        if ($reason === '') { return; }
        $item->update_meta_data('_dfr_last_delay_reason', substr($reason, 0, 80));
        $item->update_meta_data('_dfr_last_delay_reason_at', gmdate('c'));
        $item->save();
    }

    private function timing_seconds($from, $to) {
        $a = strtotime((string) $from);
        $b = strtotime((string) $to);
        if ($a === false || $b === false || $b < $a) { return null; }
        return $b - $a;
    }

    /**
     * Add one internal-only timing note when an order took long enough to matter.
     * No customer fields, codes, API errors or credentials are stored here.
     */
    private function maybe_add_slow_timing_note($order) {
        if (!$order || !is_object($order) || (string) $order->get_meta('_dfr_timing_note_added', true) === '1') { return; }
        $parts = array();
        foreach ((array) $order->get_items('line_item') as $item) {
            $created = (string) $item->get_meta('_dfr_remote_created_at', true);
            $ready = (string) $item->get_meta('_dfr_delivery_ready_at', true);
            $secs = $this->timing_seconds($created, $ready);
            if ($secs !== null && $secs >= 10) {
                $parts[] = sprintf('supplier/code %ds', (int) $secs);
            }
            $queued = (string) $item->get_meta('_dfr_timing_fast_queued', true);
            $started = (string) $item->get_meta('_dfr_timing_fast_started', true);
            $queue_secs = $this->timing_seconds($queued, $started);
            if ($queue_secs !== null && $queue_secs >= 3) { $parts[] = sprintf('queue %ds', (int) $queue_secs); }
            $submit_ms = absint($item->get_meta('_dfr_timing_supplier_submit_ms', true));
            if ($submit_ms >= 3000) { $parts[] = sprintf('supplier-create %.1fs', $submit_ms / 1000); }
            $reason = sanitize_key((string) $item->get_meta('_dfr_last_delay_reason', true));
            if ($reason !== '') { $parts[] = 'last=' . $reason; }
        }
        $pay = (string) $order->get_meta('_dfr_timing_payment_verified', true);
        $done = (string) $order->get_meta('_dfr_completed_at', true);
        $total = $this->timing_seconds($pay, $done);
        if ($total !== null && $total >= 10) { $parts[] = sprintf('payment→complete %ds', (int) $total); }
        $hooks_ms = absint($order->get_meta('_dfr_timing_completion_hooks_ms', true));
        if ($hooks_ms >= 2000) { $parts[] = sprintf('Woo hooks %.1fs', $hooks_ms / 1000); }
        if (!$parts) { return; }
        $parts = array_values(array_unique($parts));
        $order->add_order_note('Delicat latency diagnostic: ' . implode('; ', array_slice($parts, 0, 8)) . '.');
        $order->update_meta_data('_dfr_timing_note_added', '1');
        $order->save();
    }

    /**
     * HTTP failures that are safe/appropriate to retry without changing the
     * immutable supplier request. A conflict can represent an in-flight or
     * idempotency state and is therefore treated as uncertain rather than as a
     * definitive rejection/refund signal.
     */
    private function supplier_http_is_retryable($http) {
        $http = (int) $http;
        if ($http <= 0 || $http >= 500) { return true; }
        return in_array($http, array(408, 409, 425, 429), true);
    }

    private function acquire_order_lock($order_id, $timeout = 1) {
        global $wpdb;
        $name = 'dfr_wc_' . absint($order_id);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, max(0, (int) $timeout)));
        if ((string) $got === '1') { return array('db', $name); }
        // GET_LOCK() returning 0 means another worker owns the canonical lock. Never
        // fall through to a different lock primitive in that case: doing so would
        // create a split-brain where both workers believe they exclusively own the
        // same WooCommerce order. The option lock is only a compatibility fallback
        // when the database does not provide a usable GET_LOCK() result at all.
        if ((string) $got === '0') { return false; }

        // Atomic database-option fallback for hosts where MySQL named locks are not
        // available. add_option() relies on the unique option_name constraint, so only
        // one worker can acquire this fallback lock.
        $key = 'dfr_order_lock_' . absint($order_id);
        $now = time();
        if (add_option($key, $now, '', false)) { return array('option', $key); }
        $existing = (int) get_option($key, 0);
        if ($existing > 0 && $existing < $now - 30) {
            delete_option($key);
            if (add_option($key, $now, '', false)) { return array('option', $key); }
        }
        return false;
    }

    private function release_order_lock($lock) {
        if (!is_array($lock) || count($lock) < 2) { return; }
        if ($lock[0] === 'db') {
            global $wpdb;
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', (string) $lock[1]));
        } elseif ($lock[0] === 'option') {
            delete_option((string) $lock[1]);
        } else {
            delete_transient((string) $lock[1]);
        }
    }

    private function fulfillment_context($order_id, $item_id) {
        return 'fulfillment-request|order:' . absint($order_id) . '|item:' . absint($item_id);
    }

    public function can_manage() {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public function legacy_folder_privacy_notice() {
        if (!$this->can_manage()) { return; }
        $legacy_dir = trailingslashit(WP_PLUGIN_DIR) . 'delicat-fazercards-integration-v1.0.0';
        $current_dir = untrailingslashit(DFR_DIR);
        if (!is_dir($legacy_dir) || wp_normalize_path($legacy_dir) === wp_normalize_path($current_dir)) { return; }
        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('White-label cleanup:', 'delicat-fazercards') . '</strong> ';
        echo esc_html__('A legacy gateway plugin folder is still present on the server. After confirming this current gateway is active and working, remove the inactive legacy folder with your hosting File Manager. Do not use WordPress “Delete” on the old copy because its older uninstall routine may remove shared settings.', 'delicat-fazercards');
        echo '</p></div>';
    }

    public function get_settings() {
        return wp_parse_args($this->settings, self::defaults());
    }

    public function has_api_key() {
        return $this->api_key() !== '';
    }

    public function has_webhook_secret() {
        return $this->webhook_secret() !== '';
    }

    public function log($level, $message, $context = array()) {
        if ($this->settings['logging_enabled'] !== '1') { return; }
        if (function_exists('wc_get_logger')) {
            $context = $this->redact_log_context($context);
            wc_get_logger()->log(
                $level,
                sanitize_text_field((string) $message) . ($context ? ' ' . wp_json_encode($context) : ''),
                array('source' => 'delicat-fazercards')
            );
        }
    }

    private function redact_log_context($value) {
        $sensitive = array(
            'api_key','apikey','authorization','password','secret','token',
            'code','codes','pin','pins','cards','keys','serial','serials',
            'redeem_code','redemption_code','gift_code','card_code',
            'activation_key','license_key','voucher_code','fields','player_id','uid'
        );
        if (!is_array($value)) { return is_scalar($value) ? sanitize_text_field((string) $value) : ''; }
        $out = array();
        foreach ($value as $k => $v) {
            $key = strtolower((string) $k);
            if (in_array($key, $sensitive, true) || strpos($key, 'secret') !== false || strpos($key, 'token') !== false || strpos($key, 'password') !== false) {
                $out[$k] = '[REDACTED]';
            } else {
                $out[$k] = is_array($v) ? $this->redact_log_context($v) : (is_scalar($v) ? sanitize_text_field((string) $v) : '');
            }
        }
        return $out;
    }

    private function sanitize_mapping_id($value) {
        $value = trim(sanitize_text_field((string) $value));
        if ($value === '' || strlen($value) > 190 || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            return '';
        }
        return $value;
    }

    public function admin_menu() {
        $parent = class_exists('WooCommerce') ? 'woocommerce' : 'tools.php';
        add_submenu_page(
            $parent,
            __('FazerCards Integration', 'delicat-fazercards'),
            __('FazerCards', 'delicat-fazercards'),
            class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options',
            'delicat-fazercards',
            array($this, 'render_admin_page')
        );
    }

    public function handle_admin_actions() {
        if (!is_admin() || empty($_POST['dfr_action']) || !$this->can_manage()) {
            return;
        }
        check_admin_referer('dfr_admin_action');

        $action = sanitize_key(wp_unslash($_POST['dfr_action']));
        if ($action === 'save_settings') {
            $this->save_settings();
            return;
        }

        $api = $this->api();
        if ($action === 'test_connection') {
            $result = $api->me();
            $this->set_admin_result($result, __('Connection test', 'delicat-fazercards'));
        } elseif ($action === 'check_balance') {
            $result = $api->balance();
            $this->set_admin_result($result, __('Balance check', 'delicat-fazercards'));
        } elseif ($action === 'preview_catalog') {
            $topups = $api->topups(10);
            $giftcards = $api->giftcards(10);
            $result = array('topups' => $topups, 'giftcards' => $giftcards);
            if (is_wp_error($topups)) {
                $result = $topups;
            } elseif (is_wp_error($giftcards)) {
                $result = $giftcards;
            }
            $this->set_admin_result($result, __('Catalog preview', 'delicat-fazercards'));
        } elseif ($action === 'diagnose_player_id_server') {
            $player_id = isset($_POST['dfr_diagnostic_player_id'])
                ? trim(sanitize_text_field(wp_unslash($_POST['dfr_diagnostic_player_id'])))
                : '';
            if (!class_exists('DFR_Catalog')) {
                $this->set_admin_notice(__('Catalog Studio could not be loaded for Player ID diagnostics.', 'delicat-fazercards'), 'error');
            } else {
                $result = DFR_Catalog::instance()->run_player_id_server_diagnostic($player_id);
                set_transient('dfr_uid_server_diag_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
            }
        } elseif ($action === 'configure_webhook') {
            $result = $this->configure_supplier_webhook($api);
            if (is_wp_error($result)) {
                $this->set_admin_notice($result->get_error_message(), 'error');
            } else {
                $this->set_admin_notice(__('FazerCards webhook configured and verified successfully.', 'delicat-fazercards'), 'success');
            }
        } elseif ($action === 'test_webhook') {
            $result = $this->test_supplier_webhook($api);
            if (is_wp_error($result)) {
                $this->set_admin_notice($result->get_error_message(), 'error');
            } else {
                $this->set_admin_notice(__('FazerCards delivered a signed test webhook successfully.', 'delicat-fazercards'), 'success');
            }
        }

        $tab = in_array($action, array('configure_webhook', 'test_webhook', 'diagnose_player_id_server'), true) ? 'security' : 'catalog';
        wp_safe_redirect(add_query_arg(array('page' => 'delicat-fazercards', 'dfr_tab' => $tab), admin_url('admin.php')));
        exit;
    }

    /** Return only non-secret provider health metadata for the Security screen. */
    public function webhook_health() {
        $health = get_option('dfr_webhook_health_v1', array());
        $health = is_array($health) ? $health : array();
        $health['last_received_at'] = substr(sanitize_text_field((string) get_option('dfr_webhook_last_received_v1', '')), 0, 40);
        return $health;
    }

    private function webhook_settings_row($result) {
        if (!is_array($result)) { return array(); }
        if (isset($result['webhook']) && is_array($result['webhook'])) { return $result['webhook']; }
        if (isset($result['data']) && is_array($result['data'])) {
            if (isset($result['data']['webhook']) && is_array($result['data']['webhook'])) { return $result['data']['webhook']; }
            return $result['data'];
        }
        return $result;
    }

    private function canonical_webhook_url($url) {
        $url = esc_url_raw(trim((string) $url));
        return $url === '' ? '' : untrailingslashit($url);
    }

    private function save_webhook_health(array $settings, $test = null) {
        $expected = $this->canonical_webhook_url(rest_url('delicat-gateway/v1/webhook'));
        $actual = $this->canonical_webhook_url($settings['url'] ?? '');
        $health = array(
            'checked_at'           => gmdate('c'),
            'enabled'              => !empty($settings['enabled']) ? '1' : '0',
            'url_matches'          => ($expected !== '' && $actual !== '' && hash_equals($expected, $actual)) ? '1' : '0',
            'consecutive_failures' => min(1000000, absint($settings['consecutive_failures'] ?? 0)),
            'last_success_at'      => substr(sanitize_text_field((string) ($settings['last_success_at'] ?? '')), 0, 40),
            'last_failure_at'      => substr(sanitize_text_field((string) ($settings['last_failure_at'] ?? '')), 0, 40),
            'test_ok'              => '',
            'test_status'          => 0,
        );
        if (is_array($test)) {
            $health['test_ok'] = !empty($test['ok']) ? '1' : '0';
            $health['test_status'] = min(599, absint($test['status'] ?? 0));
        }
        update_option('dfr_webhook_health_v1', $health, false);
        return $health;
    }

    private function configure_supplier_webhook($api) {
        $expected = $this->canonical_webhook_url(rest_url('delicat-gateway/v1/webhook'));
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $webhook_host = strtolower((string) wp_parse_url($expected, PHP_URL_HOST));
        if ($expected === '' || strtolower((string) wp_parse_url($expected, PHP_URL_SCHEME)) !== 'https' || $home_host === '' || !hash_equals($home_host, $webhook_host)) {
            return new WP_Error('dfr_webhook_https_required', __('Configure the WordPress Site URL as public HTTPS before enabling supplier webhooks.', 'delicat-fazercards'));
        }

        $configured = $api->set_webhook($expected, true);
        if (is_wp_error($configured)) { return $configured; }
        $remote = $api->webhook_settings();
        if (is_wp_error($remote)) { return $remote; }
        $settings = $this->webhook_settings_row($remote);
        $actual = $this->canonical_webhook_url($settings['url'] ?? '');
        if ($actual === '' || !hash_equals($expected, $actual) || empty($settings['enabled'])) {
            $this->save_webhook_health($settings);
            return new WP_Error('dfr_webhook_configuration_mismatch', __('FazerCards did not confirm the exact enabled webhook URL. No local secret was changed.', 'delicat-fazercards'));
        }

        $provider_secret = trim((string) ($settings['secret'] ?? ''));
        if ($provider_secret === '' || strlen($provider_secret) > 512) {
            $this->save_webhook_health($settings);
            return new WP_Error('dfr_webhook_secret_missing', __('FazerCards did not return a usable webhook secret. Keep the existing secret and contact the supplier before regenerating it.', 'delicat-fazercards'));
        }

        if (defined('DELICAT_FAZER_WEBHOOK_SECRET') && DELICAT_FAZER_WEBHOOK_SECRET) {
            if (!hash_equals(trim((string) DELICAT_FAZER_WEBHOOK_SECRET), $provider_secret)) {
                $this->save_webhook_health($settings);
                return new WP_Error('dfr_webhook_constant_mismatch', __('The provider secret does not match DELICAT_FAZER_WEBHOOK_SECRET in wp-config.php. Update that constant before testing.', 'delicat-fazercards'));
            }
        } else {
            $encrypted = DFR_Crypto::encrypt_context($provider_secret, 'supplier-webhook-secret');
            if (is_wp_error($encrypted)) { return $encrypted; }
            $new = wp_parse_args((array) get_option('dfr_settings', array()), self::defaults());
            $new['webhook_secret_enc'] = $encrypted;
            update_option('dfr_settings', $new, false);
            $this->settings = $new;
        }

        // Store the verified secret before asking the supplier to call back; its test
        // request can arrive before this outgoing API request returns.
        $test = $api->test_webhook();
        if (is_wp_error($test)) {
            $this->save_webhook_health($settings);
            return $test;
        }
        $health = $this->save_webhook_health($settings, $test);
        if ($health['test_ok'] !== '1') {
            return new WP_Error('dfr_webhook_test_failed', __('The webhook was saved, but FazerCards could not deliver its signed test. Check firewall/CDN rules and retry.', 'delicat-fazercards'));
        }
        return $health;
    }

    private function test_supplier_webhook($api) {
        if ($this->webhook_secret() === '') {
            return new WP_Error('dfr_webhook_secret_missing', __('Configure the FazerCards webhook before running a delivery test.', 'delicat-fazercards'));
        }
        $remote = $api->webhook_settings();
        if (is_wp_error($remote)) { return $remote; }
        $settings = $this->webhook_settings_row($remote);
        $expected = $this->canonical_webhook_url(rest_url('delicat-gateway/v1/webhook'));
        $actual = $this->canonical_webhook_url($settings['url'] ?? '');
        if (empty($settings['enabled']) || $expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
            $this->save_webhook_health($settings);
            return new WP_Error('dfr_webhook_configuration_mismatch', __('The provider webhook is disabled or points to another URL. Configure it again before testing.', 'delicat-fazercards'));
        }
        $test = $api->test_webhook();
        if (is_wp_error($test)) {
            $this->save_webhook_health($settings);
            return $test;
        }
        $health = $this->save_webhook_health($settings, $test);
        if ($health['test_ok'] !== '1') {
            return new WP_Error('dfr_webhook_test_failed', __('FazerCards could not deliver its signed test. Check firewall/CDN rules and retry.', 'delicat-fazercards'));
        }
        return $health;
    }

    private function save_settings() {
        $new = wp_parse_args($this->settings, self::defaults());
        $scope = isset($_POST['dfr_settings_scope']) ? sanitize_key(wp_unslash($_POST['dfr_settings_scope'])) : 'legacy';

        if ($scope === 'catalog' || $scope === 'legacy') {
            $pricing_mode = isset($_POST['catalog_pricing_mode']) ? sanitize_key(wp_unslash($_POST['catalog_pricing_mode'])) : $new['catalog_pricing_mode'];
            $new['catalog_pricing_mode'] = in_array($pricing_mode, array('manual', 'automatic'), true) ? $pricing_mode : 'manual';
            $rate = isset($_POST['usd_to_store_rate']) ? (float) wp_unslash($_POST['usd_to_store_rate']) : (float) $new['usd_to_store_rate'];
            $new['usd_to_store_rate'] = (string) max(0, min(1000000, $rate));
            $new['catalog_markup_percent'] = (string) max(0, min(1000, isset($_POST['catalog_markup_percent']) ? (float) wp_unslash($_POST['catalog_markup_percent']) : (float) $new['catalog_markup_percent']));
            $new['catalog_fixed_markup'] = (string) max(0, min(100000000, isset($_POST['catalog_fixed_markup']) ? (float) wp_unslash($_POST['catalog_fixed_markup']) : (float) $new['catalog_fixed_markup']));
            $new['catalog_rounding'] = (string) max(0, min(1000000, isset($_POST['catalog_rounding']) ? (float) wp_unslash($_POST['catalog_rounding']) : (float) $new['catalog_rounding']));
            $status = isset($_POST['catalog_import_status']) ? sanitize_key(wp_unslash($_POST['catalog_import_status'])) : $new['catalog_import_status'];
            $new['catalog_import_status'] = in_array($status, array('draft', 'publish', 'private'), true) ? $status : 'draft';
            $ttl = isset($_POST['catalog_cache_ttl']) ? (int) wp_unslash($_POST['catalog_cache_ttl']) : (int) $new['catalog_cache_ttl'];
            $new['catalog_cache_ttl'] = (string) (in_array($ttl, array(300, 600, 900), true) ? $ttl : 600);
            $new['catalog_price_sync'] = !empty($_POST['catalog_price_sync']) ? '1' : '0';
            if ($new['catalog_pricing_mode'] === 'manual') {
                $new['catalog_price_sync'] = '0';
            }
            $new['catalog_disable_missing'] = !empty($_POST['catalog_disable_missing']) ? '1' : '0';
            $new['catalog_update_titles'] = !empty($_POST['catalog_update_titles']) ? '1' : '0';
            $new['native_fields_enabled'] = !empty($_POST['native_fields_enabled']) ? '1' : '0';
            $new['uid_validation_enabled'] = !empty($_POST['uid_validation_enabled']) ? '1' : '0';
            $new['uid_validation_required'] = !empty($_POST['uid_validation_required']) ? '1' : '0';
            $builder_defaults = array(
                'topup' => array('structure' => 'variable', 'attribute' => 'Package'),
                'giftcard' => array('structure' => 'variable', 'attribute' => 'Amount'),
                'gamekey' => array('structure' => 'variable', 'attribute' => 'Option'),
            );
            foreach ($builder_defaults as $service_key => $builder_default) {
                $structure_key = $service_key . '_import_structure';
                $attribute_key = $service_key . '_variation_attribute';
                $structure = isset($_POST[$structure_key]) ? sanitize_key(wp_unslash($_POST[$structure_key])) : $new[$structure_key];
                $new[$structure_key] = in_array($structure, array('variable', 'simple'), true) ? $structure : $builder_default['structure'];
                $attribute = isset($_POST[$attribute_key]) ? sanitize_text_field(wp_unslash($_POST[$attribute_key])) : $new[$attribute_key];
                $attribute = trim(wp_strip_all_tags($attribute));
                $new[$attribute_key] = $attribute !== '' ? substr($attribute, 0, 40) : $builder_default['attribute'];
            }
            $new['catalog_auto_sync'] = !empty($_POST['catalog_auto_sync']) ? '1' : '0';
            $interval = isset($_POST['catalog_sync_interval']) ? sanitize_key(wp_unslash($_POST['catalog_sync_interval'])) : $new['catalog_sync_interval'];
            $new['catalog_sync_interval'] = in_array($interval, array('dfr_15_minutes', 'hourly', 'twicedaily', 'daily'), true) ? $interval : 'hourly';
        }

        if ($scope === 'automation' || $scope === 'legacy') {
            $new['live_orders_enabled']    = !empty($_POST['live_orders_enabled']) ? '1' : '0';
            $new['auto_fulfill_enabled']   = !empty($_POST['auto_fulfill_enabled']) ? '1' : '0';
            $new['auto_complete_woo']      = !empty($_POST['auto_complete_woo']) ? '1' : '0';
            $new['customer_codes_enabled'] = !empty($_POST['customer_codes_enabled']) ? '1' : '0';
            $new['logging_enabled']        = !empty($_POST['logging_enabled']) ? '1' : '0';
        }

        if ($scope === 'security' || $scope === 'legacy') {
            if (!(defined('DELICAT_FAZER_API_KEY') && DELICAT_FAZER_API_KEY)) {
                $api_key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';
                if ($api_key !== '') {
                    $enc = DFR_Crypto::encrypt_context($api_key, 'supplier-api-key');
                    if (is_wp_error($enc)) {
                        $this->set_admin_notice($enc->get_error_message(), 'error');
                        return;
                    }
                    $new['api_key_enc'] = $enc;
                }
                if (!empty($_POST['clear_api_key'])) {
                    $new['api_key_enc'] = '';
                }
            }

            if (!(defined('DELICAT_FAZER_WEBHOOK_SECRET') && DELICAT_FAZER_WEBHOOK_SECRET)) {
                $secret = isset($_POST['webhook_secret']) ? trim(sanitize_text_field(wp_unslash($_POST['webhook_secret']))) : '';
                if ($secret !== '') {
                    $enc = DFR_Crypto::encrypt_context($secret, 'supplier-webhook-secret');
                    if (is_wp_error($enc)) {
                        $this->set_admin_notice($enc->get_error_message(), 'error');
                        return;
                    }
                    $new['webhook_secret_enc'] = $enc;
                }
                if (!empty($_POST['clear_webhook_secret'])) {
                    $new['webhook_secret_enc'] = '';
                }
            }
        }

        if ($new['live_orders_enabled'] !== '1') {
            $new['auto_fulfill_enabled'] = '0';
        }
        // Automatic supplier fulfillment and WooCommerce completion are one atomic
        // business flow for Delicat digital items. Do not allow a configuration where
        // supplier delivery succeeds but WooCommerce is left Processing indefinitely.
        if ($new['live_orders_enabled'] === '1' && $new['auto_fulfill_enabled'] === '1') {
            $new['auto_complete_woo'] = '1';
        }

        update_option('dfr_settings', $new, false);
        $this->settings = $new;
        if (class_exists('DFR_Catalog')) {
            DFR_Catalog::instance()->ensure_cron();
        }
        $this->set_admin_notice(__('Settings saved securely.', 'delicat-fazercards'), 'success');
        $tab = $scope === 'catalog' ? 'pricing' : ($scope === 'automation' ? 'automation' : ($scope === 'security' ? 'security' : 'catalog'));
        wp_safe_redirect(add_query_arg(array('page' => 'delicat-fazercards', 'dfr_tab' => $tab), admin_url('admin.php')));
        exit;
    }

    private function set_admin_notice($message, $type = 'success') {
        set_transient('dfr_admin_notice_' . get_current_user_id(), array('message' => (string) $message, 'type' => $type), 60);
    }

    private function set_admin_result($result, $label) {
        if (is_wp_error($result)) {
            $data = array('ok' => false, 'label' => $label, 'message' => $result->get_error_message());
        } else {
            $data = array('ok' => true, 'label' => $label, 'result' => $this->redact_for_admin($result));
        }
        set_transient('dfr_admin_result_' . get_current_user_id(), $data, 120);
    }

    private function redact_for_admin($value) {
        $sensitive = array(
            'api_key','apikey','authorization','password','secret','token',
            'code','codes','pin','pins','cards','keys','serial','serials','serial_number',
            'redeem_code','redemption_code','gift_code','gift_card_code','card_code',
            'activation_key','activation_code','license_key','voucher_code','vouchers',
            'fields','player_id','playerid','uid'
        );
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $lk = strtolower((string) $k);
                $value[$k] = (in_array($lk, $sensitive, true) || strpos($lk, 'secret') !== false || strpos($lk, 'token') !== false)
                    ? '[REDACTED]'
                    : $this->redact_for_admin($v);
            }
        }
        return $value;
    }

    public function render_admin_page() {
        if (class_exists('DFR_Catalog')) {
            DFR_Catalog::instance()->render_admin_page();
            return;
        }
        wp_die(esc_html__('Catalog Studio could not be loaded.', 'delicat-fazercards'));
    }

    public function add_product_meta_box() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        add_meta_box('dfr_product_mapping', __('FazerCards Mapping', 'delicat-fazercards'), array($this, 'render_product_meta_box'), 'product', 'side', 'default');
    }

    public function render_product_meta_box($post) {
        wp_nonce_field('dfr_save_product_mapping', 'dfr_product_nonce');
        $type = get_post_meta($post->ID, '_dfr_service_type', true);
        $category = get_post_meta($post->ID, '_dfr_category_id', true);
        $offer = get_post_meta($post->ID, '_dfr_offer_id', true);
        $field_map = get_post_meta($post->ID, '_dfr_field_map', true);
        $supplier_cost = get_post_meta($post->ID, '_dfr_supplier_cost_usd', true);
        $pricing_mode = $this->get_settings()['catalog_pricing_mode'];
        ?>
        <?php if ($pricing_mode === 'manual'): ?>
            <div style="padding:10px 12px;margin:0 0 12px;border-radius:9px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:12px;line-height:1.45">
                <strong><?php esc_html_e('WooCommerce price protected', 'delicat-fazercards'); ?></strong><br>
                <?php esc_html_e('Catalog import and sync will not change this product’s selling price.', 'delicat-fazercards'); ?>
                <?php if ($supplier_cost !== ''): ?><br><span><?php esc_html_e('Supplier cost:', 'delicat-fazercards'); ?> $<?php echo esc_html($supplier_cost); ?> USD</span><?php endif; ?>
            </div>
        <?php endif; ?>
        <p><label><strong><?php esc_html_e('Service type', 'delicat-fazercards'); ?></strong></label><br>
            <select name="dfr_service_type" style="width:100%">
                <option value="" <?php selected($type, ''); ?>><?php esc_html_e('Not mapped', 'delicat-fazercards'); ?></option>
                <option value="topup" <?php selected($type, 'topup'); ?>><?php esc_html_e('Game top-up', 'delicat-fazercards'); ?></option>
                <option value="giftcard" <?php selected($type, 'giftcard'); ?>><?php esc_html_e('Gift card', 'delicat-fazercards'); ?></option>
                <option value="gamekey" <?php selected($type, 'gamekey'); ?>><?php esc_html_e('Game key', 'delicat-fazercards'); ?></option>
            </select>
        </p>
        <p><label><strong><?php esc_html_e('Category ID', 'delicat-fazercards'); ?></strong></label><br><input type="text" name="dfr_category_id" value="<?php echo esc_attr($category); ?>" style="width:100%" autocomplete="off"></p>
        <?php $wc_product = function_exists('wc_get_product') ? wc_get_product($post->ID) : false; ?>
        <?php if ($wc_product && $wc_product->is_type('variable') && in_array($type, array('topup', 'giftcard', 'gamekey'), true)): ?>
            <div style="padding:9px 11px;margin:0 0 12px;border-radius:9px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;line-height:1.45">
                <strong><?php esc_html_e('Variable FazerCards product', 'delicat-fazercards'); ?></strong><br>
                <?php if ($type === 'topup'): ?>
                    <?php esc_html_e('Each variation stores its own FazerCards Offer ID. The parent keeps the supplier category and Player ID/account field mapping.', 'delicat-fazercards'); ?>
                <?php elseif ($type === 'giftcard'): ?>
                    <?php esc_html_e('Each denomination variation stores its own FazerCards Card ID and supplier USD cost. The parent keeps the gift-card category.', 'delicat-fazercards'); ?>
                <?php else: ?>
                    <?php esc_html_e('Each option/edition variation stores its own FazerCards Key ID and supplier USD cost. The parent keeps the game-key category.', 'delicat-fazercards'); ?>
                <?php endif; ?>
            </div>
            <input type="hidden" name="dfr_offer_id" value="">
        <?php else: ?>
            <p><label><strong><?php esc_html_e('Offer / Card / Key ID', 'delicat-fazercards'); ?></strong></label><br><input type="text" name="dfr_offer_id" value="<?php echo esc_attr($offer); ?>" style="width:100%" autocomplete="off"></p>
        <?php endif; ?>
        <?php if ($type === 'topup' || $type === ''): ?>
            <p><label><strong><?php esc_html_e('Top-up field map', 'delicat-fazercards'); ?></strong></label><br>
                <textarea name="dfr_field_map" rows="4" style="width:100%" placeholder="player_id=Player ID"><?php echo esc_textarea($field_map); ?></textarea>
                <span class="description"><?php esc_html_e('One per line: api_field=Woo order item meta label. Used only for top-up products.', 'delicat-fazercards'); ?></span>
            </p>
        <?php else: ?>
            <input type="hidden" name="dfr_field_map" value="">
        <?php endif; ?>
        <?php
    }

    public function render_variation_supplier_mapping($loop, $variation_data, $variation) {
        if (!$variation || empty($variation->ID)) {
            return;
        }
        $variation_id = (int) $variation->ID;
        $service = (string) get_post_meta($variation_id, '_dfr_service_type', true);
        if (!in_array($service, array('topup', 'giftcard', 'gamekey'), true)) {
            return;
        }
        $offer = (string) get_post_meta($variation_id, '_dfr_offer_id', true);
        $cost = (string) get_post_meta($variation_id, '_dfr_supplier_cost_usd', true);
        $previous = (string) get_post_meta($variation_id, '_dfr_supplier_cost_previous_usd', true);
        $last_seen = (string) get_post_meta($variation_id, '_dfr_supplier_last_seen', true);
        $id_label = $service === 'giftcard' ? __('Card ID:', 'delicat-fazercards') : ($service === 'gamekey' ? __('Key ID:', 'delicat-fazercards') : __('Offer ID:', 'delicat-fazercards'));
        ?>
        <div class="form-row form-row-full" style="margin:8px 2px 12px;padding:11px 12px;border:1px solid #c7d2fe;border-radius:9px;background:#f8faff;color:#3730a3;box-sizing:border-box">
            <strong style="display:block;margin-bottom:4px"><?php esc_html_e('FazerCards variation mapping', 'delicat-fazercards'); ?></strong>
            <span style="display:block;font-size:12px;line-height:1.55">
                <?php echo esc_html($id_label); ?> <code><?php echo esc_html($offer ?: '—'); ?></code>
                &nbsp;·&nbsp; <?php esc_html_e('Supplier cost:', 'delicat-fazercards'); ?> <b><?php echo $cost !== '' ? '$' . esc_html($cost) . ' USD' : '—'; ?></b>
                <?php if ($previous !== '' && $previous !== $cost): ?>&nbsp;·&nbsp; <?php esc_html_e('Previous:', 'delicat-fazercards'); ?> $<?php echo esc_html($previous); ?><?php endif; ?>
            </span>
            <small style="display:block;margin-top:4px;color:#6366f1"><?php esc_html_e('Your WooCommerce variation price remains the customer-facing HTG price in Manual Pricing mode.', 'delicat-fazercards'); ?><?php if ($last_seen !== ''): ?> <?php esc_html_e('Supplier last seen:', 'delicat-fazercards'); ?> <?php echo esc_html($last_seen); ?><?php endif; ?></small>
        </div>
        <?php
    }

    public function save_product_meta($post_id, $post) {
        if (!isset($_POST['dfr_product_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dfr_product_nonce'])), 'dfr_save_product_mapping')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        $type = isset($_POST['dfr_service_type']) ? sanitize_key(wp_unslash($_POST['dfr_service_type'])) : '';
        if (!in_array($type, array('', 'topup', 'giftcard', 'gamekey'), true)) {
            $type = '';
        }
        $category = isset($_POST['dfr_category_id']) ? $this->sanitize_mapping_id(wp_unslash($_POST['dfr_category_id'])) : '';
        $offer = isset($_POST['dfr_offer_id']) ? $this->sanitize_mapping_id(wp_unslash($_POST['dfr_offer_id'])) : '';
        $map = isset($_POST['dfr_field_map']) ? $this->sanitize_field_map_text(wp_unslash($_POST['dfr_field_map'])) : '';

        update_post_meta($post_id, '_dfr_service_type', $type);
        update_post_meta($post_id, '_dfr_category_id', $category);
        update_post_meta($post_id, '_dfr_offer_id', $offer);
        update_post_meta($post_id, '_dfr_field_map', $map);
    }

    private function sanitize_field_map_text($text) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        $safe = array();
        foreach ($lines as $line) {
            if (strpos($line, '=') === false) {
                continue;
            }
            list($api_key, $meta_key) = array_map('trim', explode('=', $line, 2));
            $api_key = sanitize_key($api_key);
            $meta_key = sanitize_text_field($meta_key);
            if ($api_key !== '' && $meta_key !== '') {
                $safe[] = $api_key . '=' . $meta_key;
            }
        }
        return implode("\n", array_slice($safe, 0, 20));
    }

    private function parse_field_map($text) {
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            if (strpos($line, '=') === false) {
                continue;
            }
            list($api_key, $meta_key) = array_map('trim', explode('=', $line, 2));
            $api_key = sanitize_key($api_key);
            if ($api_key !== '' && $meta_key !== '') {
                $out[$api_key] = $meta_key;
            }
        }
        return $out;
    }

    /**
     * Return true only when WooCommerce has accepted a full TeraWallet payment.
     *
     * TeraWallet's native gateway id is `wallet`. During payment_complete it
     * debits the customer's wallet first and stores that ledger transaction id on
     * the WooCommerce order. Requiring all three signals prevents Pending/On-hold,
     * other gateways, manually-created unpaid orders, and failed wallet debits from
     * reaching supplier fulfillment.
     */
    private function normalize_wallet_transaction_id($value) {
        if (is_array($value)) {
            foreach (array('transaction_id', 'id', 'transaction') as $key) {
                if (isset($value[$key])) { $value = $value[$key]; break; }
            }
        } elseif (is_object($value)) {
            foreach (array('transaction_id', 'id', 'transaction') as $key) {
                if (isset($value->{$key})) { $value = $value->{$key}; break; }
            }
        }
        $value = trim((string) $value);
        return ($value !== '' && $value !== '0' && ctype_digit($value)) ? absint($value) : 0;
    }

    private function normalized_wallet_attestation_amount($order) {
        $decimals = function_exists('wc_get_price_decimals') ? max(0, (int) wc_get_price_decimals()) : 2;
        return number_format((float) $order->get_total('edit'), $decimals, '.', '');
    }

    private function wallet_ledger_row_matches_order($order, $wallet_tx_id) {
        if (!$order || !is_object($order)) { return false; }
        $wallet_tx_id = absint($wallet_tx_id);
        $customer_id = absint($order->get_customer_id('edit'));
        if (!$wallet_tx_id || !$customer_id) { return false; }

        global $wpdb;
        $table = $wpdb->base_prefix . 'woo_wallet_transactions';

        // Do not memoize this financial authorization decision across a request. The
        // ledger is deliberately re-read at each spend/completion gate so a concurrent
        // reversal/deletion cannot be hidden behind a previously cached true result.
        // Numeric primary key only; the table name comes from WordPress' trusted prefix.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE transaction_id=%d LIMIT 1", $wallet_tx_id));
        if (!$row) { return false; }
        if (!isset($row->user_id, $row->type) || absint($row->user_id) !== $customer_id || (string) $row->type !== 'debit') {
            return false;
        }
        if (isset($row->deleted) && absint($row->deleted) !== 0) { return false; }
        if (isset($row->category) && trim((string) $row->category) !== '' && sanitize_key((string) $row->category) !== 'purchase') {
            return false;
        }

        if (isset($row->original_amount) && is_numeric($row->original_amount)) {
            $decimals = function_exists('wc_get_price_decimals') ? max(0, (int) wc_get_price_decimals()) : 2;
            $epsilon = 1 / pow(10, $decimals);
            if (abs((float) $row->original_amount - (float) $order->get_total('edit')) >= $epsilon) {
                return false;
            }
        }
        if (isset($row->original_currency) && trim((string) $row->original_currency) !== '') {
            if (strtoupper(trim((string) $row->original_currency)) !== strtoupper((string) $order->get_currency('edit'))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Freeze the highest Woo order id that existed when v4.8 first booted. New order
     * ids cannot normally move backward through WordPress/WooCommerce, so this is a
     * stronger migration boundary than relying only on an editable order date.
     */
    private function initialize_wallet_attestation_boundary() {
        if (get_option('dfr_wallet_attestation_legacy_max_order_id', null) !== null) { return; }
        $max_id = 0;
        if (function_exists('wc_get_orders')) {
            $ids = wc_get_orders(array(
                'limit' => 1,
                'orderby' => 'ID',
                'order' => 'DESC',
                'return' => 'ids',
            ));
            if (is_array($ids) && $ids) { $max_id = absint(reset($ids)); }
        }
        add_option('dfr_wallet_attestation_legacy_max_order_id', (string) $max_id, '', false);
    }

    /**
     * Canonicalize the exact supplier fulfillment intent that existed at checkout.
     * The resulting digest covers service type, supplier category/offer, quantity,
     * Player ID/account fields and UID-validation fields without persisting plaintext.
     */
    private function fulfillment_intent_digest_from_snapshot(array $snapshot) {
        $type = sanitize_key((string) ($snapshot['type'] ?? ''));
        if (!in_array($type, array('topup','giftcard','gamekey'), true)) { return ''; }
        $category = $this->sanitize_mapping_id((string) ($snapshot['category'] ?? ''));
        $offer = $this->sanitize_mapping_id((string) ($snapshot['offer'] ?? ''));
        if ($category === '' || $offer === '') { return ''; }

        $fields = array();
        foreach (array_slice((array) ($snapshot['fields'] ?? array()), 0, 20, true) as $key => $value) {
            if (is_array($value) || is_object($value)) { return ''; }
            $key = sanitize_key((string) $key);
            if ($key === '') { continue; }
            $value = trim(sanitize_text_field((string) $value));
            if (strlen($value) > 190) { return ''; }
            if ($value !== '') { $fields[$key] = $value; }
        }
        ksort($fields, SORT_STRING);

        $validation_fields = array();
        foreach (array_slice((array) ($snapshot['validation_fields'] ?? array()), 0, 20, true) as $key => $value) {
            if (is_array($value) || is_object($value)) { return ''; }
            $key = sanitize_key((string) $key);
            if ($key === '') { continue; }
            $value = trim(sanitize_text_field((string) $value));
            if (strlen($value) > 190) { return ''; }
            if ($value !== '') { $validation_fields[$key] = $value; }
        }
        ksort($validation_fields, SORT_STRING);

        $canonical = array(
            'type' => $type,
            'category' => $category,
            'offer' => $offer,
            'quantity' => max(1, min(100, (int) ($snapshot['quantity'] ?? 1))),
            'fields' => $fields,
            'validation_category' => $type === 'topup' ? $this->sanitize_mapping_id((string) ($snapshot['validation_category'] ?? '')) : '',
            'validation_fields' => $type === 'topup' ? $validation_fields : array(),
        );
        return hash('sha256', wp_json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Read the current authenticated checkout/fulfillment envelope and digest its intent. */
    private function current_item_fulfillment_intent_digest($order, $item_id, $item) {
        if (!$order || !$item) { return ''; }
        $order_id = absint($order->get_id());
        $item_id = absint($item_id);
        if (!$order_id || !$item_id) { return ''; }

        $fulfill_enc = (string) $item->get_meta('_dfr_fulfill_request_enc', true);
        if ($fulfill_enc !== '') {
            $json = DFR_Crypto::decrypt_context($fulfill_enc, $this->fulfillment_context($order_id, $item_id));
            $snapshot = json_decode((string) $json, true);
            if (!is_array($snapshot) || (int) ($snapshot['v'] ?? 0) < 2) { return ''; }
            return $this->fulfillment_intent_digest_from_snapshot($snapshot);
        }

        $checkout_enc = (string) $item->get_meta('_dfr_checkout_snapshot_enc', true);
        if ($checkout_enc === '') { return ''; }
        $product_id = is_callable(array($item, 'get_product_id')) ? absint($item->get_product_id()) : 0;
        $variation_id = is_callable(array($item, 'get_variation_id')) ? absint($item->get_variation_id()) : 0;
        $line_context_id = strtolower(trim((string) $item->get_meta('_dfr_checkout_context_id', true)));
        if (!preg_match('/^[a-f0-9]{40}$/', $line_context_id)) { return ''; }
        $context = 'checkout-snapshot|line:' . $line_context_id . '|product:' . $product_id . '|variation:' . $variation_id;
        $json = DFR_Crypto::decrypt_context($checkout_enc, $context);
        $snapshot = json_decode((string) $json, true);
        if (!is_array($snapshot) || (int) ($snapshot['v'] ?? 0) !== 1) { return ''; }
        return $this->fulfillment_intent_digest_from_snapshot($snapshot);
    }

    /**
     * v2 payment attestations also bind every Delicat line to its exact encrypted
     * fulfillment intent. New payments fail closed if that intent cannot be proven.
     */
    private function prepare_wallet_fulfillment_digests($order) {
        foreach ((array) $order->get_items('line_item') as $item_id => $item) {
            if (!is_object($item)) { continue; }
            $type = $this->item_service_type($item);
            if (!in_array($type, array('topup','giftcard','gamekey'), true)) { continue; }
            $digest = $this->current_item_fulfillment_intent_digest($order, $item_id, $item);
            if (!preg_match('/^[a-f0-9]{64}$/', $digest)) { return false; }
            $item->update_meta_data('_dfr_wallet_fulfillment_digest', $digest);
            $item->save();
        }
        return true;
    }

    private function validate_wallet_fulfillment_digests($order) {
        foreach ((array) $order->get_items('line_item') as $item_id => $item) {
            if (!is_object($item)) { continue; }
            $type = $this->item_service_type($item);
            if (!in_array($type, array('topup','giftcard','gamekey'), true)) { continue; }
            $stored = strtolower(trim((string) $item->get_meta('_dfr_wallet_fulfillment_digest', true)));
            if (!preg_match('/^[a-f0-9]{64}$/', $stored)) { return false; }
            $current = $this->current_item_fulfillment_intent_digest($order, $item_id, $item);
            if (!preg_match('/^[a-f0-9]{64}$/', $current) || !hash_equals($stored, $current)) { return false; }
        }
        return true;
    }

    private function wallet_attestation_items_fingerprint($order, $version = 2) {
        $rows = array();
        if ($order && method_exists($order, 'get_items')) {
            foreach ((array) $order->get_items('line_item') as $item_id => $item) {
                if (!is_object($item)) { continue; }
                $row = array(
                    'id'        => absint($item_id),
                    'product'   => is_callable(array($item, 'get_product_id')) ? absint($item->get_product_id()) : 0,
                    'variation' => is_callable(array($item, 'get_variation_id')) ? absint($item->get_variation_id()) : 0,
                    'qty'       => is_callable(array($item, 'get_quantity')) ? (string) $item->get_quantity() : '0',
                    'subtotal'  => is_callable(array($item, 'get_subtotal')) ? (string) $item->get_subtotal() : '',
                    'total'     => is_callable(array($item, 'get_total')) ? (string) $item->get_total() : '',
                    'tax'       => is_callable(array($item, 'get_total_tax')) ? (string) $item->get_total_tax() : '',
                );
                if ((int) $version >= 2) {
                    $row['service'] = $this->item_service_type($item);
                    $row['fulfillment'] = strtolower(trim((string) $item->get_meta('_dfr_wallet_fulfillment_digest', true)));
                }
                $rows[] = $row;
            }
        }
        usort($rows, static function($a, $b) { return (int) $a['id'] <=> (int) $b['id']; });
        return hash('sha256', wp_json_encode($rows, JSON_UNESCAPED_SLASHES));
    }

    private function wallet_attestation_payload($order, $wallet_tx_id, $version = 2) {
        $version = (int) $version === 1 ? 1 : 2;
        $order_key = method_exists($order, 'get_order_key') ? (string) $order->get_order_key() : '';
        $payload = array(
            'v'        => $version,
            'gateway'  => 'wallet',
            'order'    => absint($order->get_id()),
            'orderkey' => hash('sha256', $order_key),
            'customer' => absint($order->get_customer_id('edit')),
            'amount'   => $this->normalized_wallet_attestation_amount($order),
            'currency' => strtoupper((string) $order->get_currency('edit')),
            'tx'       => absint($wallet_tx_id),
            'items'    => $this->wallet_attestation_items_fingerprint($order, $version),
        );
        return wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private function wallet_attestation_signature($order, $wallet_tx_id, $version = 2) {
        return hash_hmac('sha256', $this->wallet_attestation_payload($order, $wallet_tx_id, $version), wp_salt('secure_auth'));
    }

    private function wallet_attestation_is_required($order) {
        if (!$order || !is_object($order)) { return true; }
        $legacy_max = get_option('dfr_wallet_attestation_legacy_max_order_id', null);
        if ($legacy_max !== null && is_numeric($legacy_max)) {
            return absint($order->get_id()) > absint($legacy_max);
        }
        $threshold = absint(get_option('dfr_wallet_attestation_required_after', 0));
        if ($threshold < 1 || !method_exists($order, 'get_date_created')) { return true; }
        $created = $order->get_date_created('edit');
        return !$created || !method_exists($created, 'getTimestamp') || (int) $created->getTimestamp() >= $threshold;
    }

    private function verify_wallet_payment_attestation($order, $wallet_tx_id) {
        $version = (string) $order->get_meta('_dfr_wallet_attestation_v', true);
        $stored_tx = $this->normalize_wallet_transaction_id($order->get_meta('_dfr_wallet_attestation_tx', true));
        $stored_sig = strtolower(trim((string) $order->get_meta('_dfr_wallet_attestation_sig', true)));
        if (!in_array($version, array('1','2'), true) || !$stored_tx || $stored_tx !== absint($wallet_tx_id) || !preg_match('/^[a-f0-9]{64}$/', $stored_sig)) {
            return false;
        }
        if ($version === '2' && !$this->validate_wallet_fulfillment_digests($order)) {
            return false;
        }
        return hash_equals($this->wallet_attestation_signature($order, $wallet_tx_id, (int) $version), $stored_sig);
    }

    /**
     * Called by TeraWallet immediately after its ledger debit succeeds. The proof is
     * intentionally captured before Delicat is allowed to spend supplier balance.
     */
    public function capture_wallet_payment_attestation($order_id, $wallet_response) {
        if (!function_exists('wc_get_order')) { return; }
        $order = wc_get_order(absint($order_id));
        if (!$order || (string) $order->get_payment_method('edit') !== 'wallet') { return; }

        $wallet_tx_id = $this->normalize_wallet_transaction_id($wallet_response);
        if (!$wallet_tx_id || !$this->wallet_ledger_row_matches_order($order, $wallet_tx_id)) {
            $this->log('warning', 'Wallet payment attestation refused: ledger proof failed', array('order' => absint($order_id)));
            return;
        }

        $existing_sig = strtolower(trim((string) $order->get_meta('_dfr_wallet_attestation_sig', true)));
        if ($existing_sig !== '') {
            // Immutable once written. A second wallet hook may only confirm the exact
            // same proof; it can never rewrite an order binding after the fact.
            if (!$this->verify_wallet_payment_attestation($order, $wallet_tx_id)) {
                $this->log('error', 'Wallet payment attestation conflict; fulfillment remains blocked', array('order' => absint($order_id)));
            }
            return;
        }

        if (!$this->prepare_wallet_fulfillment_digests($order)) {
            $order->add_order_note(__('Delicat security: exact digital fulfillment intent could not be attested after wallet debit. Supplier fulfillment is blocked for manual review.', 'delicat-fazercards'));
            $this->log('error', 'Wallet payment attestation refused: fulfillment intent proof unavailable', array('order' => absint($order_id)));
            return;
        }

        $order->update_meta_data('_dfr_wallet_attestation_v', '2');
        $order->update_meta_data('_dfr_wallet_attestation_tx', (string) $wallet_tx_id);
        $order->update_meta_data('_dfr_wallet_attestation_sig', $this->wallet_attestation_signature($order, $wallet_tx_id, 2));
        $order->update_meta_data('_dfr_wallet_attestation_at', gmdate('c'));
        $order->save_meta_data();
    }

    /**
     * Return true only when WooCommerce has accepted a full TeraWallet payment,
     * its ledger contains the matching debit, and (for new v4.8+ orders) Delicat's
     * immutable payment-event attestation still matches the exact order facts.
     */
    private function order_has_verified_wallet_payment($order) {
        if (!$order || !is_object($order)) { return false; }
        if ((string) $order->get_payment_method('edit') !== 'wallet') { return false; }
        if (!$order->is_paid() || !$order->get_date_paid('edit')) { return false; }

        $wallet_tx_id = $this->normalize_wallet_transaction_id($order->get_transaction_id('edit'));
        if (!$wallet_tx_id || !$this->wallet_ledger_row_matches_order($order, $wallet_tx_id)) { return false; }

        $has_attestation = trim((string) $order->get_meta('_dfr_wallet_attestation_sig', true)) !== '';
        if ($has_attestation || $this->wallet_attestation_is_required($order)) {
            return $this->verify_wallet_payment_attestation($order, $wallet_tx_id);
        }

        // Compatibility only for orders created before the v4.8 migration boundary.
        return true;
    }

    /**
     * Detect whether the current cart contains any product mapped to this digital
     * gateway. Variation mapping wins; parent mapping is the fallback.
     */
    private function cart_contains_dfr_digital_item() {
        if (!function_exists('WC') || !WC() || !WC()->cart) { return false; }
        foreach ((array) WC()->cart->get_cart() as $cart_item) {
            $product_id = absint($cart_item['product_id'] ?? 0);
            $variation_id = absint($cart_item['variation_id'] ?? 0);
            $type = $variation_id ? sanitize_key((string) get_post_meta($variation_id, '_dfr_service_type', true)) : '';
            if (!in_array($type, array('topup','giftcard','gamekey'), true) && $product_id) {
                $type = sanitize_key((string) get_post_meta($product_id, '_dfr_service_type', true));
            }
            if (in_array($type, array('topup','giftcard','gamekey'), true)) { return true; }
        }
        return false;
    }

    /**
     * For any checkout containing Delicat gateway items, expose only TeraWallet.
     * This is a UX/business-rule layer; fulfillment still performs an independent
     * server-side proof check so REST/admin/order manipulation cannot bypass it.
     */
    /** Keep ciphertext/authentication internals out of formatted Woo order-item meta. */
    public function hide_sensitive_order_item_meta($hidden) {
        $hidden = is_array($hidden) ? $hidden : array();
        $sensitive = array(
            '_dfr_fulfill_request_enc',
            '_dfr_checkout_snapshot_enc',
            '_dfr_checkout_context_id',
            '_dfr_codes_enc',
            '_dfr_codes_crypto',
            '_dfr_wallet_fulfillment_digest',
        );
        return array_values(array_unique(array_merge($hidden, $sensitive)));
    }

    public function restrict_digital_checkout_to_wallet($gateways) {
        if (!is_array($gateways) || !$gateways) { return $gateways; }
        if (is_admin() && !wp_doing_ajax()) { return $gateways; }
        if (!$this->cart_contains_dfr_digital_item()) { return $gateways; }
        if (!isset($gateways['wallet'])) {
            // Fail closed: never silently fall back to another payment method for a
            // product that can trigger irreversible digital fulfillment.
            return array();
        }
        return array('wallet' => $gateways['wallet']);
    }

    public function maybe_auto_fulfill_order($order_id) {
        if ($this->settings['live_orders_enabled'] !== '1' || $this->settings['auto_fulfill_enabled'] !== '1' || !function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$this->order_has_verified_wallet_payment($order)) {
            return;
        }
        if (!(string) $order->get_meta('_dfr_timing_payment_verified', true)) {
            $this->mark_order_timing($order, 'payment_verified');
        }

        $lock = $this->acquire_order_lock($order_id, 1);
        if (!$lock) { return; }
        try {
            foreach ($order->get_items('line_item') as $item_id => $item) {
                $this->fulfill_item($order, $item_id, $item);
            }
        } finally {
            $this->release_order_lock($lock);
        }

        // Also repair the case where this order already had a canonical supplier
        // `completed` state before this hook fired. fulfill_item() intentionally skips
        // items that already have a remote order id, so without this local completion
        // pass an otherwise fully delivered top-up could remain Processing forever.
        $fresh_order = wc_get_order($order_id);
        if ($fresh_order) {
            $this->queue_completion_candidate($fresh_order->get_id());
            $this->maybe_complete_woo_order($fresh_order);
        }
    }

    private function fulfill_item($order, $item_id, $item) {
        if (!$order || !$item || $item->get_meta('_dfr_remote_order_id', true)) { return; }
        if (!$this->order_has_verified_wallet_payment($order)) {
            $this->log('warning', 'Blocked supplier fulfillment without verified wallet payment', array('order_id' => $order->get_id(), 'item_id' => $item_id));
            return;
        }

        $snapshot = array();
        $stored = (string) $item->get_meta('_dfr_fulfill_request_enc', true);
        if ($stored !== '') {
            $json = DFR_Crypto::decrypt_context($stored, $this->fulfillment_context($order->get_id(), $item_id));
            $decoded = json_decode((string) $json, true);
            if (is_array($decoded) && (int) ($decoded['v'] ?? 0) >= 2) {
                $snapshot = $decoded;
            } else {
                // Once a fulfillment snapshot exists it is the immutable body bound
                // to the supplier idempotency key. Never rebuild it from mutable
                // product metadata if decryption/integrity validation fails.
                $order->add_order_note(sprintf(__('FazerCards: secure fulfillment snapshot for item %d failed integrity validation. Supplier order was not retried.', 'delicat-fazercards'), $item_id));
                $this->log('error', 'Fulfillment snapshot integrity failure', array('order_id' => $order->get_id(), 'item_id' => $item_id));
                return;
            }
        }

        if (!$snapshot) {
            if ((string) $item->get_meta('_dfr_checkout_snapshot_failed', true) === '1') {
                $order->add_order_note(sprintf(__('FazerCards: secure checkout snapshot for item %d was unavailable. Supplier order was not sent.', 'delicat-fazercards'), $item_id));
                $this->log('error', 'Checkout fulfillment snapshot unavailable', array('order_id'=>$order->get_id(), 'item_id'=>$item_id));
                return;
            }

            $checkout_enc = (string) $item->get_meta('_dfr_checkout_snapshot_enc', true);
            if ($checkout_enc !== '') {
                $product_id = is_callable(array($item, 'get_product_id')) ? absint($item->get_product_id()) : 0;
                $variation_id = is_callable(array($item, 'get_variation_id')) ? absint($item->get_variation_id()) : 0;
                $line_context_id = strtolower(trim((string) $item->get_meta('_dfr_checkout_context_id', true)));
                if (!preg_match('/^[a-f0-9]{40}$/', $line_context_id)) {
                    $order->add_order_note(sprintf(__('FazerCards: checkout context for item %d is missing or invalid. Supplier order was not sent.', 'delicat-fazercards'), $item_id));
                    $this->log('error', 'Checkout context integrity failure', array('order_id'=>$order->get_id(), 'item_id'=>$item_id));
                    return;
                }
                $checkout_context = 'checkout-snapshot|line:' . $line_context_id . '|product:' . $product_id . '|variation:' . $variation_id;
                $checkout_json = DFR_Crypto::decrypt_context($checkout_enc, $checkout_context);
                $checkout = json_decode((string) $checkout_json, true);
                if (!is_array($checkout) || (int) ($checkout['v'] ?? 0) !== 1) {
                    $order->add_order_note(sprintf(__('FazerCards: checkout mapping snapshot for item %d failed integrity validation. Supplier order was not sent.', 'delicat-fazercards'), $item_id));
                    $this->log('error', 'Checkout snapshot integrity failure', array('order_id'=>$order->get_id(), 'item_id'=>$item_id));
                    return;
                }
                $checkout_validation_fields = array();
                if (!empty($checkout['validation_fields']) && is_array($checkout['validation_fields'])) {
                    foreach (array_slice($checkout['validation_fields'], 0, 20, true) as $vk => $vv) {
                        $vk = sanitize_key((string) $vk);
                        if ($vk === '' || is_array($vv) || is_object($vv)) { continue; }
                        $vv = trim(sanitize_text_field((string) $vv));
                        if ($vv !== '') { $checkout_validation_fields[$vk] = substr($vv, 0, 190); }
                    }
                }
                $candidate = array(
                    'v' => 2,
                    'type' => sanitize_key((string) ($checkout['type'] ?? '')),
                    'category' => substr(sanitize_text_field((string) ($checkout['category'] ?? '')), 0, 190),
                    'offer' => substr(sanitize_text_field((string) ($checkout['offer'] ?? '')), 0, 190),
                    'quantity' => max(1, min(100, (int) ($checkout['quantity'] ?? 1))),
                    'fields' => is_array($checkout['fields'] ?? null) ? $checkout['fields'] : array(),
                    'validation_category' => substr(sanitize_text_field((string) ($checkout['validation_category'] ?? '')), 0, 190),
                    'validation_fields' => $checkout_validation_fields,
                );
                if (!in_array($candidate['type'], array('topup','giftcard','gamekey'), true) || $candidate['category'] === '' || $candidate['offer'] === '') {
                    $order->add_order_note(sprintf(__('FazerCards: checkout mapping snapshot for item %d is invalid. Supplier order was not sent.', 'delicat-fazercards'), $item_id));
                    return;
                }
                $enc = DFR_Crypto::encrypt_context(wp_json_encode($candidate), $this->fulfillment_context($order->get_id(), $item_id));
                if (is_wp_error($enc)) {
                    $order->add_order_note(__('FazerCards: checkout mapping could not be secured for fulfillment; supplier order was not sent.', 'delicat-fazercards'));
                    return;
                }
                $item->update_meta_data('_dfr_fulfill_request_enc', $enc);
                $item->delete_meta_data('_dfr_checkout_snapshot_enc');
                $item->delete_meta_data('_dfr_checkout_context_id');
                $item->delete_meta_data('_dfr_checkout_snapshot_failed');
                $item->update_meta_data('_dfr_service_type', $candidate['type']);
                $item->update_meta_data('_dfr_fulfill_attempt', 0);
                $item->save();
                $snapshot = $candidate;
            }
        }

        if (!$snapshot) {
            $legacy_attempt = max(0, (int) $item->get_meta('_dfr_fulfill_attempt', true));
            if ($legacy_attempt > 0) {
                // A pre-v3.8 item may already have reached FazerCards but lost the
                // response before a remote order id was stored. Without the original
                // immutable body, rebuilding from today's product mapping could bind
                // the same idempotency key to different data. Stop for manual review.
                $order->add_order_note(sprintf(__('FazerCards: item %d has an uncertain legacy fulfillment attempt without a secure request snapshot. No automatic resend was made.', 'delicat-fazercards'), $item_id));
                $this->log('warning', 'Blocked uncertain legacy fulfillment retry', array('order_id'=>$order->get_id(), 'item_id'=>$item_id, 'attempt'=>$legacy_attempt));
                return;
            }
            $product = $item->get_product();
            if (!$product) { return; }
            $variation_id = $product->get_parent_id() ? $product->get_id() : 0;
            $product_id = $product->get_parent_id() ?: $product->get_id();

            $type = $variation_id ? get_post_meta($variation_id, '_dfr_service_type', true) : '';
            if (!in_array($type, array('topup', 'giftcard', 'gamekey'), true)) {
                $type = get_post_meta($product_id, '_dfr_service_type', true);
            }
            if (!in_array($type, array('topup', 'giftcard', 'gamekey'), true)) { return; }

            $category = $variation_id ? trim((string) get_post_meta($variation_id, '_dfr_category_id', true)) : '';
            if ($category === '') { $category = trim((string) get_post_meta($product_id, '_dfr_category_id', true)); }
            $offer = $variation_id ? trim((string) get_post_meta($variation_id, '_dfr_offer_id', true)) : '';
            if ($offer === '') { $offer = trim((string) get_post_meta($product_id, '_dfr_offer_id', true)); }
            if ($category === '' || $offer === '') {
                $order->add_order_note(sprintf(__('Digital gateway: item %d is mapped but missing Category ID or Offer/Card ID.', 'delicat-fazercards'), $item_id));
                return;
            }

            $fields = array();
            if ($type === 'topup') {
                $field_map = $this->parse_field_map(get_post_meta($product_id, '_dfr_field_map', true));
                $field_spec = json_decode((string) get_post_meta($product_id, '_dfr_field_spec_json', true), true);
                $required_by_key = array();
                if (is_array($field_spec)) {
                    foreach ($field_spec as $spec) {
                        if (!is_array($spec) || empty($spec['key'])) { continue; }
                        $required_by_key[sanitize_key($spec['key'])] = array_key_exists('required', $spec) ? !empty($spec['required']) : true;
                    }
                }
                foreach ($field_map as $api_field => $meta_label) {
                    $value = $this->find_item_meta_value($item, $meta_label);
                    $required = array_key_exists($api_field, $required_by_key) ? $required_by_key[$api_field] : true;
                    if ($value === '' && $required) {
                        $order->add_order_note(sprintf(__('Digital gateway: item %1$d is missing required mapped field "%2$s".', 'delicat-fazercards'), $item_id, $meta_label));
                        return;
                    }
                    if ($value !== '') { $fields[$api_field] = $value; }
                }
            }

            $snapshot = array(
                'v' => 2,
                'type' => $type,
                'category' => substr(sanitize_text_field($category), 0, 190),
                'offer' => substr(sanitize_text_field($offer), 0, 190),
                'quantity' => max(1, min(100, (int) $item->get_quantity())),
                'fields' => $fields,
                'validation_category' => $type === 'topup'
                    ? substr(sanitize_text_field((string) $item->get_meta('_dfr_uid_validation_category_id', true)), 0, 190)
                    : '',
                'validation_fields' => array(),
            );
            $enc = DFR_Crypto::encrypt_context(wp_json_encode($snapshot), $this->fulfillment_context($order->get_id(), $item_id));
            if (is_wp_error($enc)) {
                $order->add_order_note(__('Digital gateway: secure fulfillment snapshot could not be created; upstream order was not sent.', 'delicat-fazercards'));
                $this->log('error', 'Could not encrypt fulfillment snapshot', array('order_id' => $order->get_id(), 'item_id' => $item_id));
                return;
            }
            $item->update_meta_data('_dfr_service_type', $type);
            $item->update_meta_data('_dfr_fulfill_request_enc', $enc);
            $item->update_meta_data('_dfr_fulfill_attempt', 0);
            $item->save();
        }

        $type = sanitize_key((string) ($snapshot['type'] ?? ''));
        $category = (string) ($snapshot['category'] ?? '');
        $offer = (string) ($snapshot['offer'] ?? '');
        $quantity = max(1, min(100, (int) ($snapshot['quantity'] ?? 1)));
        $fields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : array();
        $validation_fields = is_array($snapshot['validation_fields'] ?? null) ? $snapshot['validation_fields'] : array();
        if (!in_array($type, array('topup','giftcard','gamekey'), true) || $category === '' || $offer === '') { return; }

        $attempt = max(0, (int) $item->get_meta('_dfr_fulfill_attempt', true)) + 1;
        $item->update_meta_data('_dfr_fulfill_attempt', $attempt);
        $item->save();

        $idempotency = 'wc-' . $order->get_id() . '-item-' . $item_id . '-v1';
        $api = $this->api();

        // For Player-ID products verified at checkout, revalidate server-to-server
        // immediately before spending supplier balance. This protects against stale or
        // tampered checkout state without exposing the FazerCards key to the browser.
        $validation_category = $type === 'topup' ? (string) ($snapshot['validation_category'] ?? '') : '';
        if ($type === 'topup' && $validation_category !== '' && ($validation_fields || $fields)) {
            $revalidation_fields = $validation_fields ?: $fields; // legacy snapshots used identical keys.
            $validation = $api->validate_topup_id($validation_category, $revalidation_fields);
            if (is_wp_error($validation)) {
                $data = $validation->get_error_data();
                $http = is_array($data) ? absint($data['status'] ?? 0) : 0;
                $retry_after = is_array($data) ? absint($data['retry_after'] ?? 0) : 0;
                $terminal = ($http >= 400 && $http < 500 && !$this->supplier_http_is_retryable($http));
                $order->add_order_note(sprintf(
                    $terminal
                        ? __('Player ID revalidation returned a non-retryable error for item %d. No upstream balance was spent; review the mapping/UID before retrying manually.', 'delicat-fazercards')
                        : __('Player ID revalidation could not be completed for item %d; upstream order was not sent yet.', 'delicat-fazercards'),
                    $item_id
                ));
                if (!$terminal && $attempt < 6) {
                    $base = ($http === 429 && $retry_after > 0) ? max(1, min(3600, $retry_after)) : $this->adaptive_retry_delay($attempt, 'validation');
                    $this->mark_item_delay_reason($item, $http === 429 ? 'supplier_rate_limit' : 'uid_revalidation_transient');
                    $this->schedule_order_item_retry($order->get_id(), $item_id, $base);
                }
                return;
            }
            if (empty($validation['ok']) || empty($validation['valid'])) {
                $item->update_meta_data('_dfr_uid_revalidation_failed', gmdate('c'));
                $item->save();
                $order->add_order_note(sprintf(__('Player ID revalidation failed for item %d. No upstream balance was spent; review the customer UID before retrying manually.', 'delicat-fazercards'), $item_id));
                $order->save();
                return;
            }
            $item->delete_meta_data('_dfr_uid_revalidation_failed');
            $item->save();
        }

        if (!(string) $item->get_meta('_dfr_timing_supplier_submit_first', true)) {
            $this->mark_item_timing($item, 'supplier_submit_first');
        }
        $this->mark_item_timing($item, 'supplier_submit_last');
        $supplier_started = microtime(true);
        if ($type === 'topup') {
            $result = $api->create_topup($category, $offer, $fields, $idempotency);
        } elseif ($type === 'giftcard') {
            $result = $api->create_giftcard($category, $offer, $quantity, $idempotency);
        } else {
            $result = $api->create_gamekey($category, $offer, $quantity, $idempotency);
        }
        $supplier_ms = max(0, (int) round((microtime(true) - $supplier_started) * 1000));
        $this->mark_item_timing($item, 'supplier_submit_ms', $supplier_ms);

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $http = is_array($data) ? absint($data['status'] ?? 0) : 0;
            $retry_after = is_array($data) ? absint($data['retry_after'] ?? 0) : 0;
            $terminal = ($http >= 400 && $http < 500 && !$this->supplier_http_is_retryable($http));
            $order->add_order_note(sprintf(__('FazerCards API error for item %1$d: %2$s', 'delicat-fazercards'), $item_id, $result->get_error_message()));
            if (!$terminal && $attempt < 6) {
                $base = ($http === 429 && $retry_after > 0)
                    ? max(1, min(3600, $retry_after))
                    : $this->adaptive_retry_delay($attempt, 'create');
                $this->mark_item_delay_reason($item, $http === 429 ? 'supplier_rate_limit' : ($http >= 500 || $http === 0 ? 'supplier_create_transport' : 'supplier_create_retryable'));
                $this->schedule_order_item_retry($order->get_id(), $item_id, $base);
            }
            return;
        }

        $remote = isset($result['order']['id']) ? sanitize_text_field($result['order']['id']) : (isset($result['order_id']) ? sanitize_text_field($result['order_id']) : '');
        $status = $this->normalize_supplier_status(isset($result['order']['status']) ? $result['order']['status'] : (isset($result['status']) ? $result['status'] : 'processing'));
        if (!preg_match('/^ord-[0-9]+$/', $remote)) {
            $order->add_order_note(__('FazerCards returned an unexpected order ID; the item will be retried safely with the same idempotency key.', 'delicat-fazercards'));
            if ($attempt < 6) { $this->mark_item_delay_reason($item, 'supplier_create_unexpected_id'); $this->schedule_order_item_retry($order->get_id(), $item_id, $this->adaptive_retry_delay($attempt, 'create')); }
            return;
        }

        $item->update_meta_data('_dfr_remote_order_id', $remote);
        $item->update_meta_data('_dfr_remote_status', $status);
        $item->update_meta_data('_dfr_timing_supplier_response', gmdate('c'));
        $item->delete_meta_data('_dfr_last_delay_reason');
        $item->delete_meta_data('_dfr_last_delay_reason_at');
        if (!$item->get_meta('_dfr_remote_created_at', true)) { $item->update_meta_data('_dfr_remote_created_at', gmdate('c')); }
        // Retain the authenticated-encrypted immutable request snapshot after the
        // remote order id is known. Besides auditability, this prevents a rare
        // partial-meta-write/crash from leaving a paid item with neither the
        // supplier id nor the exact request body needed for a safe idempotent retry.
        $item->delete_meta_data('_dfr_fulfill_attempt');
        $item->save();

        $this->store_remote_map($remote, $order->get_id(), $item_id);
        $order->add_order_note(sprintf(__('FazerCards order %1$s created for item %2$d (status: %3$s).', 'delicat-fazercards'), $remote, $item_id, $status));
        $order->save();

        if ($status === 'completed') {
            // Use the creation payload immediately. If the supplier marks the order
            // complete before attaching the card/key, never hold the checkout request
            // open on another long GET; the true async fast worker retrieves it.
            $this->apply_remote_payload($order, $item_id, $result);
            $fresh_item = $order->get_item($item_id);
            if ($fresh_item && $this->item_requires_delivery_code($fresh_item) && !$this->item_has_delivery_codes($fresh_item)) {
                $this->enqueue_fast_reconcile($order->get_id(), $item_id, $remote);
            }
        } else {
            $this->enqueue_fast_reconcile($order->get_id(), $item_id, $remote);
        }
    }

    private function schedule_order_item_retry($order_id, $item_id, $delay) {
        $order_id = absint($order_id);
        $item_id = absint($item_id);
        $attempt_token = 1;
        if (function_exists('wc_get_order')) {
            $retry_order = wc_get_order($order_id);
            $retry_item = $retry_order ? $retry_order->get_item($item_id) : false;
            if ($retry_item) { $attempt_token = max(1, (int) $retry_item->get_meta('_dfr_fulfill_attempt', true)); }
        }
        // The token is ignored by the two-argument callback but makes each successor
        // unique from the currently running attempt in Action Scheduler.
        $args = array($order_id, $item_id, $attempt_token);
        $timestamp = time() + $this->jitter_delay(max(1, (int) $delay));
        if (function_exists('as_schedule_single_action') && did_action('action_scheduler_init')) {
            as_schedule_single_action($timestamp, 'dfr_retry_order_item', $args, 'delicat-fazercards', true);
        } elseif (!wp_next_scheduled('dfr_retry_order_item', $args)) {
            wp_schedule_single_event($timestamp, 'dfr_retry_order_item', $args);
        }
    }

    public function retry_order_item($order_id, $item_id) {
        if (!function_exists('wc_get_order')) { return; }
        $order = wc_get_order(absint($order_id));
        if (!$this->order_has_verified_wallet_payment($order)) { return; }
        $lock = $this->acquire_order_lock($order->get_id(), 1);
        if (!$lock) { return; }
        try {
            $item = $order->get_item(absint($item_id));
            if ($item && !$item->get_meta('_dfr_remote_order_id', true)) {
                $this->fulfill_item($order, $item_id, $item);
            }
        } finally {
            $this->release_order_lock($lock);
        }
    }

    private function find_item_meta_value($item, $wanted) {
        $wanted_norm = strtolower(trim((string) $wanted));
        foreach ($item->get_meta_data() as $meta) {
            $key = (string) $meta->key;
            if (strpos($key, '_dfr_') === 0) {
                continue;
            }
            $key_norm = strtolower(trim(wp_strip_all_tags($key)));
            if ($key_norm === $wanted_norm || sanitize_key($key_norm) === sanitize_key($wanted_norm)) {
                $value = is_scalar($meta->value) ? (string) $meta->value : '';
                return sanitize_text_field($value);
            }
        }
        return '';
    }

    /**
     * Independent canonical-read checkpoints for one supplier order. No checkpoint
     * creates its successor, so a killed/running/duplicated worker cannot break the
     * rest of the plan. The first minute is dense; later top-up checks remain bounded
     * through the official ten-minute wait window.
     */
    private function reconciliation_plan_offsets() {
        return array(
            5, 10, 15, 20, 30, 45, 60,
            75, 90, 105, 120, 135, 150, 165, 180,
            210, 240, 270, 300, 330, 360, 420, 480, 540, 600,
        );
    }

    private function reconciliation_plan_id($order_id, $item_id, $remote) {
        $material = absint($order_id) . '|' . absint($item_id) . '|' . (string) $remote . '|v414';
        return substr(hash_hmac('sha256', $material, wp_salt('nonce')), 0, 24);
    }

    /** Schedule the complete plan up front. Returns true when at least one durable
     * checkpoint exists. The plan contains only local ids and the public ord-* id. */
    private function arm_reconciliation_plan($order_id, $item_id, $remote, $force = false) {
        if (!function_exists('wc_get_order')) { return false; }
        $order_id = absint($order_id);
        $item_id = absint($item_id);
        $remote = sanitize_text_field((string) $remote);
        if (!$order_id || !$item_id || !preg_match('/^ord-[0-9]+$/', $remote)) { return false; }

        $order = wc_get_order($order_id);
        $item = $order ? $order->get_item($item_id) : false;
        if (!$order || !$item || !hash_equals($remote, (string) $item->get_meta('_dfr_remote_order_id', true))) { return false; }
        $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
        if (in_array($status, array('failed','refunded'), true)) { return false; }
        if ($status === 'completed' && (!$this->item_requires_delivery_code($item) || $this->item_has_delivery_codes($item))) {
            $this->maybe_complete_woo_order($order, true);
            return true;
        }

        $plan_id = $this->reconciliation_plan_id($order_id, $item_id, $remote);
        $existing_id = (string) $item->get_meta('_dfr_reconcile_plan_id', true);
        $existing_deadline = absint($item->get_meta('_dfr_reconcile_plan_deadline', true));
        if (!$force && $existing_id !== '' && hash_equals($plan_id, $existing_id) && $existing_deadline > time() + 60) {
            return true;
        }

        $started = time();
        $offsets = $this->reconciliation_plan_offsets();
        $scheduled = 0;
        foreach ($offsets as $index => $offset) {
            $attempt = $index + 1;
            $args = array($order_id, $item_id, $remote, $attempt, $plan_id);
            $exists = false;
            if (function_exists('as_has_scheduled_action') && did_action('action_scheduler_init')) {
                $exists = (bool) as_has_scheduled_action('dfr_reconcile_plan_tick', $args, 'delicat-fazercards');
            }
            if ($exists) {
                $scheduled++;
                continue;
            }
            $action_id = 0;
            if (function_exists('as_schedule_single_action') && did_action('action_scheduler_init')) {
                $action_id = (int) as_schedule_single_action($started + (int) $offset, 'dfr_reconcile_plan_tick', $args, 'delicat-fazercards', true);
            }
            if ($action_id > 0) {
                $scheduled++;
            } elseif (!wp_next_scheduled('dfr_reconcile_plan_tick', $args)) {
                if (wp_schedule_single_event($started + (int) $offset, 'dfr_reconcile_plan_tick', $args)) { $scheduled++; }
            } else {
                $scheduled++;
            }
        }

        if ($scheduled > 0) {
            $item->update_meta_data('_dfr_reconcile_plan_id', $plan_id);
            $item->update_meta_data('_dfr_reconcile_plan_armed_at', gmdate('c', $started));
            $item->update_meta_data('_dfr_reconcile_plan_deadline', $started + (int) end($offsets));
            $item->update_meta_data('_dfr_reconcile_plan_checkpoints', $scheduled);
            $item->save();
            return true;
        }

        $this->mark_item_delay_reason($item, 'durable_plan_schedule_failed');
        return false;
    }

    /** Atomically enforce a short per-remote cooldown. This prevents a delayed queue
     * from releasing many overdue checkpoints into the supplier API at once. */
    private function claim_canonical_poll_slot($remote, $minimum_interval = 4) {
        global $wpdb;
        $remote = sanitize_text_field((string) $remote);
        if (!preg_match('/^ord-[0-9]+$/', $remote)) { return false; }
        $minimum_interval = max(1, min(30, (int) $minimum_interval));
        $hash = md5($remote);
        $lock_name = 'dfr_poll_slot_' . $hash;
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) === '1';
        if (!$locked) { return false; }
        try {
            $key = 'dfr_supplier_poll_last_' . $hash;
            $last = absint(get_option($key, 0));
            $now = time();
            if ($last > 0 && $last > $now - $minimum_interval) { return false; }
            update_option($key, $now, false);
            return true;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /** Reserve capacity below FazerCards' documented status-read ceiling. The fixed
     * one-minute window is shared by webhook, loopback, queue, watchdog and customer
     * traffic, leaving headroom for diagnostics and other account activity. */
    private function claim_supplier_status_budget() {
        global $wpdb;
        $lock_name = 'dfr_supplier_status_budget';
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) === '1';
        if (!$locked) { return false; }
        try {
            $key = 'dfr_supplier_status_budget_v1';
            $row = get_transient($key);
            $row = is_array($row) ? $row : array('started' => 0, 'count' => 0);
            $now = time();
            $started = absint($row['started'] ?? 0);
            $count = absint($row['count'] ?? 0);
            if (!$started || $started < $now - MINUTE_IN_SECONDS || $started > $now + 60) {
                $started = $now;
                $count = 0;
            }
            if ($count >= 100) { return false; }
            return (bool) set_transient($key, array('started' => $started, 'count' => $count + 1), 70);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /** Shared rate/serialization boundary for every FazerCards order-status reader,
     * including the optional reseller API bridge. This method is GET-only. */
    public function read_canonical_supplier_order($remote, $timeout = 5) {
        $remote = sanitize_text_field((string) $remote);
        if (!preg_match('/^ord-[0-9]+$/', $remote)) {
            return new WP_Error('dfr_invalid_remote_order_id', __('Invalid upstream order ID.', 'delicat-fazercards'));
        }
        if (!$this->claim_canonical_poll_slot($remote, 4) || !$this->claim_supplier_status_budget()) {
            return new WP_Error('dfr_canonical_read_busy', __('Canonical supplier read is already in progress.', 'delicat-fazercards'));
        }
        return $this->api()->get_order($remote, max(2, min(6, (int) $timeout)));
    }

    /** One checkpoint in the pre-armed plan. It performs GET-only reconciliation and
     * never submits, retries, or changes the bound supplier order. */
    public function reconcile_plan_tick($order_id, $item_id, $remote, $attempt = 0, $plan_id = '') {
        if (!function_exists('wc_get_order')) { return; }
        $order_id = absint($order_id);
        $item_id = absint($item_id);
        $attempt = max(1, min(100, (int) $attempt));
        $remote = sanitize_text_field((string) $remote);
        $plan_id = sanitize_key((string) $plan_id);
        $order = $order_id ? wc_get_order($order_id) : false;
        $item = $order ? $order->get_item($item_id) : false;
        if (!$order || !$item || !preg_match('/^ord-[0-9]+$/', $remote)) { return; }
        if (!hash_equals($remote, (string) $item->get_meta('_dfr_remote_order_id', true))) { return; }
        $stored_plan = sanitize_key((string) $item->get_meta('_dfr_reconcile_plan_id', true));
        if ($stored_plan === '' || $plan_id === '' || !hash_equals($stored_plan, $plan_id)) { return; }

        $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
        if (in_array($status, array('failed','refunded'), true)) { return; }
        if ($status === 'completed' && (!$this->item_requires_delivery_code($item) || $this->item_has_delivery_codes($item))) {
            $this->maybe_complete_woo_order($order, true);
            return;
        }
        $item->update_meta_data('_dfr_reconcile_plan_last_attempt', $attempt);
        $item->update_meta_data('_dfr_reconcile_plan_last_run', gmdate('c'));
        $item->save();
        $state = $this->reconcile_remote_once($order_id, $item_id, $remote, 5, 'plan');
        if (in_array($state, array('completed','delivered','failed','refunded'), true)) {
            $done_order = wc_get_order($order_id);
            $done_item = $done_order ? $done_order->get_item($item_id) : false;
            if ($done_item) {
                $done_item->update_meta_data('_dfr_reconcile_terminal_observed_at', gmdate('c'));
                $done_item->update_meta_data('_dfr_reconcile_terminal_source', 'plan');
                $done_item->save();
            }
        }
    }

    /**
     * Start reconciliation through three independent paths: authenticated server
     * loopback, PHP-FPM post-response work, and Action Scheduler as durable fallback.
     * This removes the first-delivery dependency on WP-Cron/queue-runner timing.
     */
    private function enqueue_fast_reconcile($order_id, $item_id, $remote, $round = 1) {
        $order_id = absint($order_id);
        $item_id = absint($item_id);
        $remote = sanitize_text_field((string) $remote);
        $round = max(1, min(5, (int) $round));
        if (!$order_id || !$item_id || !preg_match('/^ord-[0-9]+$/', $remote)) { return false; }
        // Arm every future canonical checkpoint before starting low-latency helpers.
        // Immediate paths may fail independently without losing the durable plan.
        $durable_plan = $this->arm_reconciliation_plan($order_id, $item_id, $remote);
        $args = array($order_id, $item_id, $remote, $round);
        if ($round === 1 && function_exists('wc_get_order')) {
            $timing_order = wc_get_order($order_id);
            $timing_item = $timing_order ? $timing_order->get_item($item_id) : false;
            if ($timing_item && !(string) $timing_item->get_meta('_dfr_timing_fast_queued', true)) {
                $this->mark_item_timing($timing_item, 'fast_queued');
            }
        }

        // v4.10: Action Scheduler is a durable fallback, not the first-line runner.
        // Dispatch a single-use authenticated loopback immediately and also remember the
        // order for a PHP-FPM post-response reconciliation. Either path can complete the
        // order without waiting for WP-Cron. A short debounce prevents duplicate loops.
        $this->remember_post_response_reconcile($order_id, $item_id, $remote);
        $loopback = $this->dispatch_reconcile_loopback($order_id, $item_id, $remote, $round);

        $scheduled = false;
        if (function_exists('as_enqueue_async_action') && did_action('action_scheduler_init')) {
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('dfr_fast_reconcile_remote_order', $args, 'delicat-fazercards')) {
                $scheduled = true;
            } else {
                $scheduled = (bool) as_enqueue_async_action('dfr_fast_reconcile_remote_order', $args, 'delicat-fazercards', true);
            }
        } elseif (!wp_next_scheduled('dfr_fast_reconcile_remote_order', $args)) {
            $scheduled = (bool) wp_schedule_single_event(time(), 'dfr_fast_reconcile_remote_order', $args);
            if ($scheduled && function_exists('spawn_cron')) { spawn_cron(time()); }
        } else {
            $scheduled = true;
        }
        return $durable_plan || $loopback || $scheduled;
    }

    private function remember_post_response_reconcile($order_id, $item_id, $remote) {
        if ($this->internal_reconcile_active || wp_doing_cron()) { return; }
        $key = absint($order_id) . ':' . absint($item_id) . ':' . (string) $remote;
        if (count($this->post_response_reconcile) < 4) {
            $this->post_response_reconcile[$key] = array(absint($order_id), absint($item_id), (string) $remote);
        }
    }

    /**
     * Fire a server-to-server request that does not depend on WordPress cron. The public
     * endpoint receives only a 256-bit single-use token; order identifiers are kept in a
     * short-lived server-side transient and are never trusted from the caller.
     */
    private function dispatch_reconcile_loopback($order_id, $item_id, $remote, $round = 1) {
        $order_id = absint($order_id);
        $item_id = absint($item_id);
        $remote = sanitize_text_field((string) $remote);
        $round = max(1, min(5, (int) $round));
        if (!$order_id || !$item_id || !preg_match('/^ord-[0-9]+$/', $remote)) { return false; }

        // A round-specific debounce prevents duplicate fan-out while still allowing the
        // current worker to hand the same supplier order to its next observation round.
        $debounce = 'dfr_loopback_dispatch_' . md5($remote . '|' . $round);
        if (get_transient($debounce)) { return true; }
        if (!set_transient($debounce, 1, 15)) { return false; }

        try {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (Throwable $e) {
            // This token authorizes a privileged supplier reconciliation path. Never
            // downgrade entropy if the platform CSPRNG is unavailable.
            delete_transient($debounce);
            return false;
        }
        if (strlen($token) < 32) {
            delete_transient($debounce);
            return false;
        }
        $claim_key = 'dfr_loopback_claim_' . hash('sha256', $token);
        if (!set_transient($claim_key, array(
            'order_id' => $order_id,
            'item_id'  => $item_id,
            'remote'   => $remote,
            'round'    => $round,
            'issued'   => time(),
        ), 120)) {
            delete_transient($debounce);
            return false;
        }

        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            $item = $order ? $order->get_item($item_id) : false;
            if ($item) { $this->mark_item_timing($item, 'loopback_dispatched'); }
        }

        $url = admin_url('admin-ajax.php');
        $response = wp_safe_remote_post($url, array(
            'timeout'            => 0.5,
            'blocking'           => false,
            'redirection'        => 0,
            'sslverify'          => true,
            'reject_unsafe_urls' => true,
            'headers'            => array('Cache-Control' => 'no-store'),
            'body'               => array(
                'action' => 'dfr_internal_reconcile',
                'token'  => $token,
            ),
        ));
        if (is_wp_error($response)) {
            delete_transient($claim_key);
            delete_transient($debounce);
            if (function_exists('wc_get_order')) {
                $failed_order = wc_get_order($order_id);
                $failed_item = $failed_order ? $failed_order->get_item($item_id) : false;
                if ($failed_item) { $this->mark_item_delay_reason($failed_item, 'loopback_dispatch_failed'); }
            }
            $this->log('warning', 'Immediate reconciliation loopback could not be dispatched; durable fallback remains queued', array(
                'order' => $order_id,
                'item'  => $item_id,
                'error' => $response->get_error_code(),
            ));
            return false;
        }
        return true;
    }

    private function internal_reconcile_request_allowed() {
        global $wpdb;
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $bucket = substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 24);
        $key = 'dfr_lb_rl_' . $bucket;
        // Serialize the read/increment/write so a burst of parallel unauthenticated
        // requests cannot all observe the same counter and bypass the PHP-level DoS
        // ceiling. Failure to acquire/store the limiter fails closed.
        $lock_name = 'dfr_lb_rl_' . $bucket;
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) === '1';
        if (!$locked) { return false; }
        try {
            $row = get_transient($key);
            $row = is_array($row) ? $row : array('started' => 0, 'count' => 0);
            $now = time();
            $started = absint($row['started'] ?? 0);
            $count = absint($row['count'] ?? 0);
            if (!$started || $started < $now - MINUTE_IN_SECONDS || $started > $now + 60) {
                $started = $now;
                $count = 0;
            }
            if ($count >= 300) { return false; }
            return (bool) set_transient($key, array('started' => $started, 'count' => $count + 1), MINUTE_IN_SECONDS + 10);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    public function ajax_internal_reconcile() {
        if (function_exists('nocache_headers') && !headers_sent()) { nocache_headers(); }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
        $content_length = isset($_SERVER['CONTENT_LENGTH']) ? absint($_SERVER['CONTENT_LENGTH']) : 0;
        if ($method !== 'POST' || $content_length > 4096 || !$this->internal_reconcile_request_allowed()) {
            wp_send_json_error(array('code' => 'forbidden'), 403);
        }
        $token = isset($_POST['token']) ? trim((string) wp_unslash($_POST['token'])) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{32,100}$/', $token)) {
            wp_send_json_error(array('code' => 'invalid'), 403);
        }
        $claim_key = 'dfr_loopback_claim_' . hash('sha256', $token);
        // Atomically consume the single-use claim. A MySQL named lock closes the tiny
        // race where two loopback requests could otherwise read the transient before
        // either one deletes it. The token itself is 256-bit random and never contains
        // an order id, customer id, product id or supplier credential.
        global $wpdb;
        $claim_lock = 'dfr_lb_' . substr(hash('sha256', $token), 0, 40);
        $claim_locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $claim_lock)) === '1';
        if (!$claim_locked) {
            wp_send_json_error(array('code' => 'busy'), 503);
        }
        try {
            $claim = get_transient($claim_key);
            if (!is_array($claim)) {
                wp_send_json_error(array('code' => 'expired'), 403);
            }
            // Consume before any supplier I/O. A second request with the same token sees
            // no claim after it acquires the lock and therefore cannot replay the read.
            delete_transient($claim_key);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $claim_lock));
        }
        $issued = absint($claim['issued'] ?? 0);
        $order_id = absint($claim['order_id'] ?? 0);
        $item_id = absint($claim['item_id'] ?? 0);
        $remote = sanitize_text_field((string) ($claim['remote'] ?? ''));
        $round = max(1, min(5, (int) ($claim['round'] ?? 1)));
        if (!$issued || $issued < time() - 90 || !$order_id || !$item_id || !preg_match('/^ord-[0-9]+$/', $remote)) {
            wp_send_json_error(array('code' => 'invalid_claim'), 403);
        }

        $this->internal_reconcile_active = true;
        if (function_exists('wc_get_order')) {
            $timing_order = wc_get_order($order_id);
            $timing_item = $timing_order ? $timing_order->get_item($item_id) : false;
            if ($timing_item) {
                if (!(string) $timing_item->get_meta('_dfr_timing_loopback_started', true)) {
                    $this->mark_item_timing($timing_item, 'loopback_started');
                }
                $this->mark_item_timing($timing_item, 'loopback_round', $round);
            }
        }

        // Each request is detached from checkout and hands off with another single-use
        // token. Canonical reads follow the supplier SDK's five-second wait cadence;
        // webhook, plan and browser signals share the same per-remote cooldown.
        $round_waits = array(
            1 => array(0),
            2 => array(5000000),
            3 => array(5000000),
            4 => array(5000000),
            5 => array(5000000),
        );
        $waits = $round_waits[$round];
        $terminal = false;
        $hard_stop = false;
        foreach ($waits as $pause) {
            if ($pause > 0) { usleep($pause); }
            $state = $this->reconcile_remote_once($order_id, $item_id, $remote, 3, 'loopback');
            if ($state === 'completed' || $state === 'failed' || $state === 'refunded' || $state === 'delivered') {
                $terminal = true;
                break;
            }
            if ($state === 'error' || $state === 'blocked') {
                $hard_stop = true;
                break;
            }
        }
        $this->internal_reconcile_active = false;

        if (!$terminal) {
            $chained = false;
            if (!$hard_stop && $round < 5) {
                $chained = $this->dispatch_reconcile_loopback($order_id, $item_id, $remote, $round + 1);
            }
            if ($hard_stop || !$chained) {
                // Durable safety net for transport errors, security-gate stops, hosts
                // that reject self-loopbacks, and supplier work lasting over ~20 seconds.
                $this->arm_reconciliation_plan($order_id, $item_id, $remote);
            }
        }
        wp_send_json_success(array('ok' => true, 'terminal' => $terminal, 'round' => $round));
    }

    /** One canonical supplier read + serialized local mutation. */
    private function reconcile_remote_once($order_id, $item_id, $remote, $timeout = 3, $source = 'direct') {
        if (!function_exists('wc_get_order')) { return 'blocked'; }
        $order_id = absint($order_id);
        $item_id = absint($item_id);
        $remote = sanitize_text_field((string) $remote);
        $order = wc_get_order($order_id);
        if (!$order || !$this->order_has_verified_wallet_payment($order)) { return 'blocked'; }
        $item = $order->get_item($item_id);
        if (!$item || !hash_equals($remote, (string) $item->get_meta('_dfr_remote_order_id', true))) { return 'blocked'; }

        $local_status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
        $needs_code = $this->item_requires_delivery_code($item);
        $has_code = $this->item_has_delivery_codes($item);
        if (in_array($local_status, array('failed','refunded'), true)) { return $local_status; }
        if ($local_status === 'completed' && (!$needs_code || $has_code)) {
            $this->maybe_complete_woo_order($order, true);
            return $needs_code ? 'delivered' : 'completed';
        }

        // Serialize supplier GETs too. v4.9 serialized only local writes, so the new
        // loopback/post-response/Action-Scheduler paths could otherwise all poll the same
        // remote order simultaneously.
        $poll_key = 'dfr_supplier_poll_' . md5($remote);
        $now = time();
        if (!add_option($poll_key, $now, '', false)) {
            $existing = (int) get_option($poll_key, 0);
            if ($existing > 0 && $existing >= $now - 10) { return 'busy'; }
            delete_option($poll_key);
            if (!add_option($poll_key, $now, '', false)) { return 'busy'; }
        }
        try {
            $started = microtime(true);
            $result = $this->read_canonical_supplier_order($remote, max(2, min(4, (int) $timeout)));
            $elapsed = max(0, (int) round((microtime(true) - $started) * 1000));
            $timing_order = wc_get_order($order_id);
            $item = $timing_order ? $timing_order->get_item($item_id) : false;
            if ($item) {
                $this->mark_item_timing($item, 'last_poll_ms', $elapsed);
                $this->mark_item_timing($item, $source . '_poll');
            }
            if (is_wp_error($result)) {
                if ($result->get_error_code() === 'dfr_canonical_read_busy') {
                    if ($item) { $this->mark_item_delay_reason($item, 'canonical_read_throttled'); }
                    return 'busy';
                }
                if ($item) { $this->mark_item_delay_reason($item, 'supplier_poll_transport'); }
                return 'error';
            }
        } finally {
            delete_option($poll_key);
        }

        $lock = $this->acquire_order_lock($order_id, 1);
        if (!$lock) {
            if ($item) { $this->mark_item_delay_reason($item, 'order_lock_contention'); }
            return 'busy';
        }
        try {
            $fresh_order = wc_get_order($order_id);
            if (!$fresh_order || !$this->order_has_verified_wallet_payment($fresh_order)) { return 'blocked'; }
            $fresh_item = $fresh_order->get_item($item_id);
            if (!$fresh_item || !hash_equals($remote, (string) $fresh_item->get_meta('_dfr_remote_order_id', true))) { return 'blocked'; }
            $this->apply_remote_payload($fresh_order, $item_id, $result);
        } finally {
            $this->release_order_lock($lock);
        }

        $check_order = wc_get_order($order_id);
        $check_item = $check_order ? $check_order->get_item($item_id) : false;
        if (!$check_item) { return 'blocked'; }
        $status = $this->normalize_supplier_status($check_item->get_meta('_dfr_remote_status', true));
        if (in_array($status, array('failed','refunded'), true)) {
            $check_item->update_meta_data('_dfr_reconcile_terminal_observed_at', gmdate('c'));
            $check_item->update_meta_data('_dfr_reconcile_terminal_source', substr(sanitize_key((string) $source), 0, 30));
            $check_item->save();
            return $status;
        }
        if ($status === 'completed' && (!$this->item_requires_delivery_code($check_item) || $this->item_has_delivery_codes($check_item))) {
            $check_item->update_meta_data('_dfr_reconcile_terminal_observed_at', gmdate('c'));
            $check_item->update_meta_data('_dfr_reconcile_terminal_source', substr(sanitize_key((string) $source), 0, 30));
            $check_item->save();
            $this->maybe_complete_woo_order($check_order, true);
            return $this->item_requires_delivery_code($check_item) ? 'delivered' : 'completed';
        }
        return $status ?: 'processing';
    }

    /**
     * PHP-FPM fallback that executes after the customer response has been flushed. This
     * is intentionally bounded and is skipped when FastCGI response finalization is not
     * available, so it cannot turn checkout into a long blocking request.
     */
    public function run_post_response_reconcile() {
        if (!$this->post_response_reconcile || $this->internal_reconcile_active || wp_doing_cron()) { return; }
        if (!function_exists('fastcgi_finish_request')) { return; }
        ignore_user_abort(true);
        @fastcgi_finish_request();
        $candidates = array_slice(array_values($this->post_response_reconcile), 0, 2);
        $this->post_response_reconcile = array();
        foreach ($candidates as $candidate) {
            [$order_id, $item_id, $remote] = $candidate;
            if (function_exists('wc_get_order')) {
                $o = wc_get_order($order_id);
                $i = $o ? $o->get_item($item_id) : false;
                if ($i) { $this->mark_item_timing($i, 'postresponse_started'); }
            }
            foreach (array(350000, 700000, 1200000) as $pause) {
                usleep($pause);
                $state = $this->reconcile_remote_once($order_id, $item_id, $remote, 3, 'postresponse');
                if (in_array($state, array('completed','delivered','failed','refunded','blocked'), true)) { break; }
            }
        }
    }

    public function add_reconcile_cron_schedule($schedules) {
        $schedules = is_array($schedules) ? $schedules : array();
        $schedules['dfr_every_minute'] = array(
            'interval' => MINUTE_IN_SECONDS,
            'display'  => __('Every minute (Delicat reconciliation)', 'delicat-fazercards'),
        );
        return $schedules;
    }

    /** Keep two independently persisted watchdog triggers. Either runner can repair
     * processing orders; a shared lease prevents duplicate supplier reads. */
    public function ensure_reconcile_watchdog() {
        if (!$this->has_api_key()) { return; }
        $as_args = array('action_scheduler');
        if (function_exists('as_schedule_recurring_action') && did_action('action_scheduler_init')) {
            $has_as = function_exists('as_has_scheduled_action')
                ? (bool) as_has_scheduled_action('dfr_reconcile_watchdog', $as_args, 'delicat-fazercards')
                : false;
            if (!$has_as) {
                as_schedule_recurring_action(time() + 15, MINUTE_IN_SECONDS, 'dfr_reconcile_watchdog', $as_args, 'delicat-fazercards', true);
            }
        }
        $wp_args = array('wp_cron');
        if (!wp_next_scheduled('dfr_reconcile_watchdog', $wp_args)) {
            wp_schedule_event(time() + 30, 'dfr_every_minute', 'dfr_reconcile_watchdog', $wp_args);
        }
        update_option('dfr_reconcile_watchdog_version', DFR_VERSION, false);
    }

    /** GET-only safety net. It is intentionally small and rate-bounded: each run scans
     * recent open orders and performs at most three canonical reads. */
    public function reconcile_watchdog($source = '') {
        if (!function_exists('wc_get_orders') || !$this->has_api_key()) { return; }
        $lease = 'dfr_reconcile_watchdog_lease';
        if (get_transient($lease)) { return; }
        if (!set_transient($lease, 1, 45)) { return; }

        $checked = 0;
        $candidates = 0;
        try {
            $orders = wc_get_orders(array(
                'status'  => array('processing','on-hold','completed'),
                'limit'   => 40,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'objects',
            ));
            foreach ((array) $orders as $order) {
                if (!$order || !$this->order_has_verified_wallet_payment($order)) { continue; }
                foreach ($order->get_items('line_item') as $item_id => $item) {
                    $remote = trim((string) $item->get_meta('_dfr_remote_order_id', true));
                    $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
                    if (in_array($status, array('failed','refunded'), true)) { continue; }
                    if (!preg_match('/^ord-[0-9]+$/', $remote)) {
                        // 4.15.2: a paid item the supplier never received.
                        $this->recover_unsubmitted_item($order, $item_id, $item);
                        continue;
                    }
                    $needs_code = $this->item_requires_delivery_code($item);
                    $has_code = $this->item_has_delivery_codes($item);
                    if ($status === 'completed' && (!$needs_code || $has_code)) {
                        $this->maybe_complete_woo_order($order, true);
                        continue;
                    }
                    $candidates++;
                    $this->arm_reconciliation_plan($order->get_id(), $item_id, $remote);
                    $last = strtotime((string) $item->get_meta('_dfr_last_remote_check', true));
                    if ($last !== false && $last > time() - 8) { continue; }
                    $this->reconcile_remote_once($order->get_id(), $item_id, $remote, 5, 'watchdog');
                    $checked++;
                    if ($checked >= 3) { break 2; }
                }
            }
            // The optional reseller bridge keeps its own order table, so let it use
            // the same watchdog lease and shared canonical-read budget as Woo orders.
            do_action('dfr_reconcile_watchdog_reseller', sanitize_key((string) $source));
            update_option('dfr_sync_watchdog_health_v1', array(
                'ran_at'     => gmdate('c'),
                'source'     => substr(sanitize_key((string) $source), 0, 30),
                'candidates' => min(1000, $candidates),
                'checked'    => min(3, $checked),
            ), false);
        } finally {
            delete_transient($lease);
        }
    }

    /**
     * Upgrade self-heal: dispatch recent Processing/On-hold remote items through the
     * cron-independent loopback path. It never POSTs a new supplier order.
     */
    /**
     * Re-submit a paid line item that never reached the supplier.
     *
     * 4.15.2. submit_supplier_order() gives up after six attempts on the 'create'
     * backoff — 3+6+10+20+35+60 seconds, about two minutes in total — and the
     * reconciliation watchdog then skipped the item entirely, because it requires
     * a `_dfr_remote_order_id` matching /^ord-[0-9]+$/ and an unsubmitted item has
     * none. A supplier outage lasting longer than those two minutes therefore left
     * the customer charged, the order sitting in Processing, and NO worker that
     * would ever try again. The only recovery was a merchant noticing by hand.
     *
     * Resubmission is safe because the supplier idempotency key is derived purely
     * from the order and item ids ('wc-<order>-item-<item>-v1'), so a retry returns
     * the original upstream order rather than creating a second one — which is
     * exactly why this is a retry and not a new purchase.
     *
     * Bounded: after the cap the item is left alone and the merchant is told once,
     * so a genuinely unfulfillable item cannot be retried forever.
     *
     * @param object $order   WooCommerce order.
     * @param int    $item_id Line item id.
     * @param object $item    Line item.
     * @return void
     */
    private function recover_unsubmitted_item($order, $item_id, $item) {
        if (!$order || !$item) { return; }
        // Only items we actually prepared for fulfillment; the encrypted snapshot
        // is what submit_supplier_order() replays.
        if ((string) $item->get_meta('_dfr_fulfill_request_enc', true) === '') { return; }
        // A failed Player-ID revalidation is a decision, not an outage.
        if ((string) $item->get_meta('_dfr_uid_revalidation_failed', true) !== '') { return; }
        if (!$this->item_service_type($item)) { return; }

        $cap = (int) apply_filters('dfr_unsubmitted_recovery_attempts', 48, $order, $item_id);
        $attempts = absint($item->get_meta('_dfr_resubmit_attempts', true));
        if ($attempts >= max(1, $cap)) {
            if (!$item->get_meta('_dfr_resubmit_exhausted_notified', true)) {
                $item->update_meta_data('_dfr_resubmit_exhausted_notified', gmdate('c'));
                $item->save();
                $order->add_order_note(sprintf(
                    /* translators: %d: line item id */
                    __('Digital gateway: item %d was paid but never accepted by the supplier after repeated retries. Fulfil it manually or refund the customer.', 'delicat-fazercards'),
                    (int) $item_id
                ));
                $this->log('error', 'Paid item never submitted to supplier; recovery exhausted', array(
                    'order_id' => $order->get_id(),
                    'item_id' => $item_id,
                    'attempts' => $attempts,
                ));
            }
            return;
        }

        $item->update_meta_data('_dfr_resubmit_attempts', $attempts + 1);
        $item->save();
        $this->log('warning', 'Re-submitting a paid item the supplier never received', array(
            'order_id' => $order->get_id(),
            'item_id' => $item_id,
            'attempt' => $attempts + 1,
        ));
        $this->schedule_order_item_retry($order->get_id(), $item_id, max(30, $this->adaptive_retry_delay(min(6, $attempts + 1), 'create')));
    }

    public function repair_stuck_reconcile_once() {
        $repair_version = (string) get_option('dfr_reconcile_repair_pending_v414', '') === '1'
            ? 'v414'
            : ((string) get_option('dfr_reconcile_repair_pending_v4131', '') === '1'
                ? 'v4131'
                : ((string) get_option('dfr_reconcile_repair_pending_v413', '') === '1' ? 'v413' : 'v410'));
        if ((string) get_option('dfr_reconcile_repair_pending_' . $repair_version, '') !== '1' || !function_exists('wc_get_orders')) { return; }
        $guard = 'dfr_reconcile_repair_guard_' . $repair_version;
        if (get_transient($guard)) { return; }
        set_transient($guard, 1, 15);
        $runs_key = 'dfr_reconcile_repair_runs_' . $repair_version;
        $runs = absint(get_option($runs_key, 0)) + 1;
        update_option($runs_key, $runs, false);
        $orders = wc_get_orders(array(
            'status' => array('processing','on-hold'),
            'limit' => 30,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ));
        $sent = 0;
        foreach ((array) $orders as $order) {
            if (!$order || !$this->order_has_verified_wallet_payment($order)) { continue; }
            foreach ($order->get_items('line_item') as $item_id => $item) {
                $remote = trim((string) $item->get_meta('_dfr_remote_order_id', true));
                $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
                if (!preg_match('/^ord-[0-9]+$/', $remote) || in_array($status, array('failed','refunded'), true)) { continue; }
                if ($status === 'completed' && (!$this->item_requires_delivery_code($item) || $this->item_has_delivery_codes($item))) {
                    $this->maybe_complete_woo_order($order, true);
                    continue;
                }
                $this->arm_reconciliation_plan($order->get_id(), $item_id, $remote);
                $this->dispatch_reconcile_loopback($order->get_id(), $item_id, $remote, 1);
                if (++$sent >= 5) { break 2; }
            }
        }
        if ($runs >= 6 || $sent === 0) {
            update_option('dfr_reconcile_repair_pending_' . $repair_version, 'done', false);
        }
    }

    /** Admin view is a recovery trigger only; the normal automated path does not need it. */
    public function admin_queue_order_reconcile($order) {
        if (!$order || !current_user_can('manage_woocommerce')) { return; }
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $remote = trim((string) $item->get_meta('_dfr_remote_order_id', true));
            $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
            if (!preg_match('/^ord-[0-9]+$/', $remote) || in_array($status, array('failed','refunded'), true)) { continue; }
            if ($status !== 'completed' || ($this->item_requires_delivery_code($item) && !$this->item_has_delivery_codes($item))) {
                $this->arm_reconciliation_plan($order->get_id(), $item_id, $remote);
                // An administrator opening the exact order is a safe opportunity for
                // one immediate canonical read. The shared slot prevents page-refresh
                // amplification; all other work stays asynchronous.
                $state = $this->reconcile_remote_once($order->get_id(), $item_id, $remote, 5, 'admin');
                if ($state === 'busy') {
                    $this->dispatch_reconcile_loopback($order->get_id(), $item_id, $remote);
                }
            }
        }
    }

    /**
     * Near-real-time canonical reconciliation for the first seconds after payment.
     * Runs in the background, so brief waits never hold the checkout response open.
     * Every iteration re-checks the verified wallet payment and canonical supplier
     * order before writing encrypted delivery state or completing WooCommerce.
     */
    public function fast_reconcile_remote_order($order_id, $item_id, $remote, $round = 1) {
        if (!function_exists('wc_get_order')) { return; }
        $order_id = absint($order_id);
        $item_id = absint($item_id);
        $remote = sanitize_text_field((string) $remote);
        $round = max(1, min(5, (int) $round));
        if (!$order_id || !$item_id || !preg_match('/^ord-[0-9]+$/', $remote)) { return; }

        $timing_order = wc_get_order($order_id);
        $timing_item = $timing_order ? $timing_order->get_item($item_id) : false;
        if ($timing_item && !(string) $timing_item->get_meta('_dfr_timing_fast_started', true)) {
            $queued_at = (string) $timing_item->get_meta('_dfr_timing_fast_queued', true);
            $started_at = gmdate('c');
            $this->mark_item_timing($timing_item, 'fast_started', $started_at);
            $queue_secs = $this->timing_seconds($queued_at, $started_at);
            if ($queue_secs !== null && $queue_secs >= 3) {
                $this->mark_item_delay_reason($timing_item, 'action_scheduler_queue_delay');
            }
        }

        // v4.10: this queue worker is only a durable safety net. Never sleep inside an
        // Action Scheduler worker; one canonical read lets the queue remain fair even on
        // shared hosting with concurrency=1. Immediate delivery is handled by loopback /
        // post-response reconciliation.
        $state = $this->reconcile_remote_once($order_id, $item_id, $remote, 4, 'action_scheduler');
        if (in_array($state, array('completed','delivered','failed','refunded','blocked'), true)) {
            delete_option('dfr_poll_attempt_' . md5($remote));
            return;
        }
        $this->arm_reconciliation_plan($order_id, $item_id, $remote);
    }

    public function poll_remote_order($order_id, $item_id, $remote) {
        if (!function_exists('wc_get_order')) { return; }
        $order_id = absint($order_id);
        $item_id = absint($item_id);
        $remote = sanitize_text_field((string) $remote);
        $order = $order_id ? wc_get_order($order_id) : false;
        $item = $order ? $order->get_item($item_id) : false;
        if (!$order || !$item || !preg_match('/^ord-[0-9]+$/', $remote)) { return; }
        if (!hash_equals($remote, (string) $item->get_meta('_dfr_remote_order_id', true))) { return; }

        // Compatibility target for actions created by older releases. Delegate to the
        // one canonical GET/apply pipeline and pre-arm the independent recovery plan.
        $this->arm_reconciliation_plan($order_id, $item_id, $remote);
        $state = $this->reconcile_remote_once($order_id, $item_id, $remote, 5, 'legacy_poll');
        if (in_array($state, array('completed','delivered','failed','refunded'), true)) {
            delete_option('dfr_poll_attempt_' . md5($remote));
        }
    }

    public function register_rest_routes() {
        register_rest_route('delicat-gateway/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Atomically claim a verified webhook replay key. A MySQL named lock closes the
     * small race that exists in a plain get_transient()+set_transient() pair while the
     * transient itself retains the bounded two-day replay window.
     */
    private function claim_webhook_event($dedupe) {
        global $wpdb;
        $dedupe = sanitize_key((string) $dedupe);
        if ($dedupe === '') { return new WP_Error('dfr_webhook_claim_invalid', 'Invalid webhook claim'); }
        $lock_name = 'dfr_wh_' . substr(hash('sha256', $dedupe), 0, 40);
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) === '1';
        if (!$locked) { return new WP_Error('dfr_webhook_claim_busy', 'Webhook claim busy'); }
        try {
            if (get_transient($dedupe)) { return false; }
            if (!set_transient($dedupe, 1, 2 * DAY_IN_SECONDS)) {
                return new WP_Error('dfr_webhook_claim_store', 'Webhook claim unavailable');
            }
            return true;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    public function handle_webhook(WP_REST_Request $request) {
        $secret = $this->webhook_secret();
        if ($secret === '') {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Webhook not configured'), 503);
        }

        $content_length = trim((string) $request->get_header('content-length'));
        if ($content_length !== '' && ctype_digit($content_length) && (int) $content_length > 262144) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Payload too large'), 413);
        }
        $raw = (string) $request->get_body();
        if ($raw === '' || strlen($raw) > 262144) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid payload'), 400);
        }

        $current_signature = trim((string) $request->get_header('x-webhook-signature'));
        $legacy_signature  = trim((string) $request->get_header('x-fazercards-signature'));
        $digest = hash_hmac('sha256', $raw, $secret);
        $valid_signature = false;

        // Current dedicated FazerCards webhook contract is strict:
        // X-Webhook-Signature: sha256=<64 lowercase hex chars>.
        if ($current_signature !== '') {
            $valid_signature = (bool) preg_match('/^sha256=[a-f0-9]{64}$/', $current_signature)
                && hash_equals('sha256=' . $digest, $current_signature);
        // Compatibility with the older Cookbook header is isolated to that header;
        // a raw digest is never accepted in the current X-Webhook-Signature header.
        } elseif ($legacy_signature !== '' && apply_filters('dfr_allow_legacy_webhook', false)) {
            // Legacy webhook authentication/schema lacks the current event timestamp
            // and event-id replay guarantees. It is disabled by default in hardened
            // builds and can be explicitly re-enabled only for a known legacy supplier.
            $valid_signature = (
                ((bool) preg_match('/^[a-f0-9]{64}$/', $legacy_signature) && hash_equals($digest, $legacy_signature)) ||
                ((bool) preg_match('/^sha256=[a-f0-9]{64}$/', $legacy_signature) && hash_equals('sha256=' . $digest, $legacy_signature))
            );
        }
        if (!$valid_signature) {
            $this->log('warning', 'Rejected webhook with invalid signature');
            return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid signature'), 401);
        }

        $event = json_decode($raw, true);
        if (!is_array($event)) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid JSON event'), 400);
        }

        // If FazerCards supplies redundant identity headers, require them to match the
        // HMAC-authenticated body. Proxies cannot silently splice one event's headers
        // onto another event's body while still triggering local work.
        $header_event = trim((string) $request->get_header('x-webhook-event'));
        $header_event_id = trim((string) $request->get_header('x-webhook-event-id'));
        if ($header_event !== '' && (!isset($event['event']) || !hash_equals($header_event, (string) $event['event']))) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Event header mismatch'), 400);
        }
        if ($header_event_id !== '' && (!isset($event['event_id']) || !hash_equals($header_event_id, (string) $event['event_id']))) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Event id header mismatch'), 400);
        }
        // A signature-valid callback (including the supplier test event) is safe to
        // record as non-sensitive health telemetry. Never store the body or headers.
        update_option('dfr_webhook_last_received_v1', gmdate('c'), false);

        $remote = '';
        $status = '';
        $event_id = '';
        $event_ts = 0;
        $current_schema = false;

        // Current documented schema.
        if (!empty($event['event']) && isset($event['data']) && is_array($event['data'])) {
            $current_schema = true;
            $event_name = sanitize_text_field((string) $event['event']);
            if ($event_name !== 'order.status_changed') {
                return new WP_REST_Response(array('ok' => true, 'ignored' => true), 200);
            }
            $remote = isset($event['data']['order_id']) ? sanitize_text_field((string) $event['data']['order_id']) : '';
            $status = isset($event['data']['status']) ? $this->normalize_supplier_status($event['data']['status']) : 'processing';
            $event_id = isset($event['event_id']) ? substr(sanitize_text_field((string) $event['event_id']), 0, 100) : '';
            if ($event_id === '' || empty($event['timestamp'])) {
                return new WP_REST_Response(array('ok' => false, 'error' => 'Missing event identity/timestamp'), 400);
            }
            $parsed = strtotime((string) $event['timestamp']);
            if ($parsed === false) {
                return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid event timestamp'), 400);
            }
            $event_ts = (int) $parsed;
        // Legacy/cookbook schema retained for backwards compatibility.
        } elseif (apply_filters('dfr_allow_legacy_webhook', false) && !empty($event['type']) && !empty($event['order']) && is_array($event['order'])) {
            $type = sanitize_key(str_replace('.', '_', (string) $event['type']));
            if (!in_array($type, array('order_completed', 'order_failed', 'order_refunded'), true)) {
                return new WP_REST_Response(array('ok' => true, 'ignored' => true), 200);
            }
            $remote = isset($event['order']['id']) ? sanitize_text_field((string) $event['order']['id']) : '';
            $status = $this->normalize_supplier_status(str_replace('order_', '', $type));
        } else {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Unsupported event shape'), 400);
        }

        if (!preg_match('/^ord-[0-9]+$/', $remote)) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid order id'), 400);
        }
        if ($current_schema) {
            if ($event_id === '' || !preg_match('/^[A-Za-z0-9-]{8,100}$/', $event_id) || $event_ts <= 0) {
                return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid event metadata'), 400);
            }
        }
        // Current FazerCards retries happen on a short schedule. Reject current-schema
        // events that are implausibly in the future or more than 24 hours old to reduce
        // signed-webhook replay/amplification. Background reconciliation still repairs
        // orders if the site was offline long enough to miss the webhook window.
        if ($event_ts > time() + 10 * MINUTE_IN_SECONDS || ($event_ts > 0 && $event_ts < time() - DAY_IN_SECONDS)) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid event timestamp'), 400);
        }

        $dedupe = $event_id !== ''
            ? 'dfr_webhook_event_' . hash('sha256', $event_id)
            : 'dfr_webhook_raw_' . hash('sha256', $raw);
        $claim = $this->claim_webhook_event($dedupe);
        if (is_wp_error($claim)) {
            $this->log('warning', 'Verified webhook replay claim unavailable', array('remote' => $remote));
            return new WP_REST_Response(array('ok' => false, 'error' => 'Webhook busy'), 503);
        }
        if (!$claim) {
            return new WP_REST_Response(array('ok' => true, 'duplicate' => true), 200);
        }

        // Fast path: after HMAC verification, immediately re-read the canonical
        // supplier order with a short timeout and synchronize WooCommerce in this same
        // request. The signed body is only a trigger; canonical supplier state remains
        // authoritative. This removes the old Action-Scheduler/30–60s visibility gap.
        $processed = $this->process_verified_webhook($remote, $status, $event_id, 0, true);
        if ($processed) {
            return new WP_REST_Response(array('ok' => true, 'processed' => true), 200);
        }

        // If the fast canonical read times out, queue only non-secret identifiers. The
        // worker retries safely; delivery codes never sit in Action Scheduler/WP-Cron args.
        $args = array($remote, $status, $event_id);
        $scheduled = false;
        if (function_exists('as_enqueue_async_action') && did_action('action_scheduler_init')) {
            $scheduled = (bool) as_enqueue_async_action('dfr_process_verified_webhook', $args, 'delicat-fazercards', true);
            if (!$scheduled && function_exists('as_has_scheduled_action')) {
                $scheduled = (bool) as_has_scheduled_action('dfr_process_verified_webhook', $args, 'delicat-fazercards');
            }
        } else {
            $scheduled = wp_schedule_single_event(time() + 1, 'dfr_process_verified_webhook', $args);
            if (!$scheduled && wp_next_scheduled('dfr_process_verified_webhook', $args)) { $scheduled = true; }
        }
        if (!$scheduled) {
            // Release the replay claim so the supplier's normal retry can recover.
            delete_transient($dedupe);
            $this->log('error', 'Verified FazerCards webhook could not be queued', array('remote' => $remote));
            return new WP_REST_Response(array('ok' => false, 'error' => 'Queue unavailable'), 503);
        }

        return new WP_REST_Response(array('ok' => true, 'queued' => true), 200);
    }

    private function schedule_verified_webhook_retry($remote, $status, $event_id, $attempt) {
        $attempt = max(1, (int) $attempt);
        if ($attempt > 5) { return false; }
        $args = array((string) $remote, (string) $status, (string) $event_id, $attempt);
        $when = time() + $this->jitter_delay($this->adaptive_retry_delay($attempt, 'webhook'));
        if (function_exists('as_schedule_single_action') && did_action('action_scheduler_init')) {
            return (bool) as_schedule_single_action($when, 'dfr_process_verified_webhook', $args, 'delicat-fazercards', true);
        }
        if (!wp_next_scheduled('dfr_process_verified_webhook', $args)) {
            return (bool) wp_schedule_single_event($when, 'dfr_process_verified_webhook', $args);
        }
        return true;
    }

    public function process_verified_webhook($remote, $status, $event_id = '', $attempt = 0, $fast = false) {
        $remote = sanitize_text_field((string) $remote);
        if (!preg_match('/^ord-[0-9]+$/', $remote)) { return; }
        $status = $this->normalize_supplier_status($status);
        $attempt = max(0, (int) $attempt);

        // Security rule: a signed webhook is an authenticated *trigger*, not the
        // source of truth for financial/delivery state. Always re-read the canonical
        // order from FazerCards before mutating WooCommerce, wallet or reseller state.
        // This also makes out-of-order/replayed signed events harmless.
        $full = $this->read_canonical_supplier_order($remote, $fast ? 4 : 6);
        if (is_wp_error($full) || !is_array($full)) {
            $this->log('warning', 'Webhook canonical order read failed; signed state was not applied', array(
                'remote' => $remote,
                'attempt' => $attempt,
                'error' => is_wp_error($full) ? $full->get_error_code() : 'invalid_payload',
            ));
            // Defense in depth: even a correctly signed webhook is only a trigger.
            // If the independent canonical read is unavailable, do not promote or
            // fail/refund local state from the webhook body alone. A compromised
            // webhook secret must not be sufficient to alter fulfillment state.
            $map = $this->get_remote_map($remote);
            if ($map && function_exists('wc_get_order')) {
                $order = wc_get_order(absint($map['order_id']));
                $item = $order ? $order->get_item(absint($map['item_id'])) : false;
                if ($order && $item && hash_equals($remote, (string) $item->get_meta('_dfr_remote_order_id', true)) && $this->order_has_verified_wallet_payment($order)) {
                    $this->enqueue_fast_reconcile($order->get_id(), $item->get_id(), $remote);
                }
            }
            if (!$fast) {
                $this->schedule_verified_webhook_retry($remote, $status, $event_id, $attempt + 1);
            }
            return false;
        }

        $payload = $full;
        $remote_order = isset($full['order']) && is_array($full['order']) ? $full['order'] : $full;
        $signed_status = $this->normalize_supplier_status($status);
        $canonical_status = isset($remote_order['status']) ? $this->normalize_supplier_status($remote_order['status']) : 'processing';
        // Canonical supplier state is the only authority for local financial/delivery
        // mutation. A signed terminal webhook that arrives ahead of the canonical read
        // simply causes another immediate reconciliation; it never becomes state itself.
        $status = $canonical_status;
        if (in_array($signed_status, array('completed','failed','refunded'), true) && $canonical_status === 'processing') {
            $map = $this->get_remote_map($remote);
            if ($map && function_exists('wc_get_order')) {
                $early_order = wc_get_order(absint($map['order_id']));
                $early_item = $early_order ? $early_order->get_item(absint($map['item_id'])) : false;
                if ($early_order && $early_item && hash_equals($remote, (string) $early_item->get_meta('_dfr_remote_order_id', true))) {
                    $this->enqueue_fast_reconcile($early_order->get_id(), $early_item->get_id(), $remote);
                }
            }
        }

        // Reseller API reconciliation receives only authenticated supplier data.
        do_action('dfr_remote_webhook_event', $remote, $status, $payload);

        $map = $this->get_remote_map($remote);
        if (!$map || !function_exists('wc_get_order')) { return false; }
        $order = wc_get_order(absint($map['order_id']));
        if (!$order) { return false; }
        $webhook_item = $order->get_item(absint($map['item_id']));
        if ($webhook_item && !(string) $webhook_item->get_meta('_dfr_timing_webhook_canonical', true)) {
            $this->mark_item_timing($webhook_item, 'webhook_canonical');
        }

        $lock = $this->acquire_order_lock($order->get_id(), 1);
        if (!$lock) {
            if (!$fast) { $this->schedule_verified_webhook_retry($remote, $status, $event_id, $attempt + 1); }
            return false;
        }
        try {
            // Re-load and bind the option-map back to immutable order-item metadata
            // while holding the order lock. A stale/corrupted map can never redirect a
            // valid supplier webhook into a different WooCommerce line item.
            $locked_order = wc_get_order($order->get_id());
            if (!$locked_order) { return false; }
            $mapped_item = $locked_order->get_item(absint($map['item_id']));
            $bound_remote = $mapped_item ? trim((string) $mapped_item->get_meta('_dfr_remote_order_id', true)) : '';
            if (!$mapped_item || $bound_remote === '' || !hash_equals($remote, $bound_remote)) {
                $this->log('error', 'Webhook local binding mismatch; canonical supplier state ignored', array(
                    'remote' => $remote,
                    'order' => absint($order->get_id()),
                    'item' => absint($map['item_id']),
                ));
                return false;
            }
            $order = $locked_order;
            $this->apply_remote_payload($order, absint($map['item_id']), $payload, $status);
        } finally {
            $this->release_order_lock($lock);
        }
        $item = $order->get_item(absint($map['item_id']));
        if ($item && in_array($status, array('completed','failed','refunded'), true)) {
            $deliverable = $status !== 'completed' || !$this->item_requires_delivery_code($item) || $this->item_has_delivery_codes($item);
            if ($deliverable) {
                $item->update_meta_data('_dfr_reconcile_terminal_observed_at', gmdate('c'));
                $item->update_meta_data('_dfr_reconcile_terminal_source', 'webhook');
                $item->save();
            }
        }
        if ($status === 'completed' && $item && $this->item_requires_delivery_code($item) && !$this->item_has_delivery_codes($item)) {
            $this->enqueue_fast_reconcile($order->get_id(), $item->get_id(), $remote);
        }
        return true;
    }

    private function apply_remote_payload($order, $item_id, array $payload, $forced_status = '') {
        $item = $order->get_item($item_id);
        if (!$item) {
            return;
        }

        $remote_order = isset($payload['order']) && is_array($payload['order']) ? $payload['order'] : $payload;
        $status = $forced_status !== '' ? $this->normalize_supplier_status($forced_status) : $this->normalize_supplier_status(isset($remote_order['status']) ? $remote_order['status'] : 'processing');
        $previous = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
        // A slower stale poll must never roll a terminal supplier state back to
        // processing. Completed may still legitimately advance to failed/refunded if
        // the canonical supplier order later reports that terminal outcome.
        if ($status === 'processing' && in_array($previous, array('completed','failed','refunded'), true)) {
            $status = $previous;
        } elseif (in_array($previous, array('failed','refunded'), true) && $status === 'completed') {
            $status = $previous;
        }
        $item->update_meta_data('_dfr_remote_status', $status);
        $item->update_meta_data('_dfr_last_remote_check', gmdate('c'));

        $codes = $this->extract_codes($remote_order);
        if ($codes) {
            $context = $this->code_crypto_context($order->get_id(), $item_id);
            $enc = DFR_Crypto::encrypt_context(wp_json_encode($codes), $context);
            if (!is_wp_error($enc)) {
                $item->update_meta_data('_dfr_codes_enc', $enc);
                $item->update_meta_data('_dfr_codes_crypto', 'aead-v2');
                $item->update_meta_data('_dfr_codes_count', count($codes));
                if (!$item->get_meta('_dfr_delivery_ready_at', true)) { $item->update_meta_data('_dfr_delivery_ready_at', gmdate('c')); }
                $item->update_meta_data('_dfr_timing_delivery_ready', gmdate('c'));
                $item->delete_meta_data('_dfr_delivery_waiting_since');

                /*
                 * 4.15.2: a supplier order that came back with fewer codes than the
                 * customer bought is a shortfall, not a delivery. Reconciliation
                 * keeps chasing it (see item_has_delivery_codes), but the merchant
                 * is told once as soon as it is visible, because the customer has
                 * already paid for units they have not received.
                 */
                $expected_codes = $this->item_expected_code_count($item);
                if ($expected_codes > 1 && count($codes) < $expected_codes && !$item->get_meta('_dfr_codes_short_notified', true)) {
                    $item->update_meta_data('_dfr_codes_short_notified', gmdate('c'));
                    $order->add_order_note(sprintf(
                        /* translators: 1: delivered code count, 2: purchased quantity, 3: item name */
                        __('Digital gateway: supplier delivered %1$d of %2$d codes for "%3$s". The shortfall is being retried; if it does not resolve, refund or fulfil the remainder manually.', 'delicat-fazercards'),
                        (int) count($codes),
                        (int) $expected_codes,
                        (string) $item->get_name()
                    ));
                    $this->log('warning', 'Short digital delivery from supplier', array(
                        'order_id' => $order->get_id(),
                        'item_id' => $item_id,
                        'delivered' => count($codes),
                        'expected' => $expected_codes,
                    ));
                }
            } else {
                $this->log('error', 'Delivery code encryption failed', array('order_id' => $order->get_id(), 'item_id' => $item_id, 'error' => $enc->get_error_message()));
            }
        } elseif ($status === 'completed' && $this->item_requires_delivery_code($item)) {
            if (!$item->get_meta('_dfr_delivery_waiting_since', true)) {
                $item->update_meta_data('_dfr_delivery_waiting_since', gmdate('c'));
            }
        }
        $item->save();

        if ($status === 'completed' && $codes) {
            $this->notify_customer_delivery_ready($order, $item, $codes);
        }

        if ($status !== $previous) {
            $remote = $item->get_meta('_dfr_remote_order_id', true);
            $order->add_order_note(sprintf(__('Upstream order %1$s status: %2$s.', 'delicat-fazercards'), $remote, $status));
        }

        if ($status === 'failed') {
            // Do not persist the provider's free-form failure text: upstream diagnostics can echo
            // customer Player IDs/account fields. The canonical supplier status is sufficient for
            // reconciliation; detailed machine error codes are kept only in redacted logs.
            $order->add_order_note(__('The upstream service reported that this order failed. No sensitive provider diagnostic was stored in the order note.', 'delicat-fazercards'));
        }

        $order->save();
        if ($status === 'completed') {
            $this->queue_completion_candidate($order->get_id());
        }
        $this->maybe_complete_woo_order($order);
    }

    private function extract_codes(array $data) {
        $candidates = array();
        $max_codes = 100; // Supplier order quantity is capped at 100.

        // Explicit code-like fields that are safe to recognize anywhere in a
        // completed digital-delivery payload. Deliberately exclude generic
        // `key` / `value` names here: those can also describe metadata and SKU
        // attributes and must never be mistaken for a customer's secret code.
        $single_keys = array(
            'code', 'pin', 'serial',
            'redeem_code', 'redeemcode', 'redemption_code', 'redemptioncode',
            'serial_number', 'serialnumber', 'card_code', 'cardcode',
            'gift_code', 'giftcode', 'gift_card_code', 'giftcardcode',
            'activation_key', 'activationkey', 'activation_code', 'activationcode',
            'license_key', 'licensekey', 'digital_code', 'digitalcode',
            'voucher_code', 'vouchercode'
        );
        $list_keys = array(
            // Current official SDK: gift-card delivery is order.cards[] and
            // game-key delivery is order.keys[]. Historical aliases remain
            // accepted for compatibility.
            'cards', 'keys', 'codes', 'pins', 'serials', 'vouchers',
            'gift_cards', 'giftcards', 'gift_codes', 'giftcodes',
            'activation_keys', 'activationkeys', 'license_keys', 'licensekeys',
            'digital_codes', 'digitalcodes'
        );

        $add = static function ($value) use (&$candidates, $max_codes) {
            if (count($candidates) >= $max_codes || !is_scalar($value)) { return; }
            $candidate = trim((string) $value);
            if ($candidate === '' || strlen($candidate) > 512) { return; }
            $candidates[] = sanitize_text_field($candidate);
        };

        // Scan an entry that is already inside a known delivery container. In
        // that narrow context, a field literally named `key` can legitimately be
        // the delivered game key. It is never accepted from unrelated metadata.
        $walk_delivery_entry = function ($value, $depth = 0, $container = '') use (&$walk_delivery_entry, $add, $single_keys, $max_codes) {
            if ($depth > 6) { return; }
            if (is_scalar($value)) { $add($value); return; }
            if (!is_array($value)) { return; }
            foreach ($value as $k => $v) {
                if ($depth > 6) { return; }
                $key = sanitize_key((string) $k);
                $allowed = in_array($key, $single_keys, true)
                    || ($container === 'keys' && $key === 'key')
                    || ($container === 'vouchers' && $key === 'voucher');
                if ($allowed && is_scalar($v)) {
                    $add($v);
                } elseif (is_array($v)) {
                    $walk_delivery_entry($v, $depth + 1, $container);
                }
            }
        };

        $walk = function ($value, $depth = 0) use (&$walk, $walk_delivery_entry, &$candidates, $single_keys, $list_keys, $add, $max_codes) {
            if ($depth > 10 || !is_array($value) || count($candidates) >= $max_codes) { return; }
            foreach ($value as $k => $v) {
                if (count($candidates) >= $max_codes) { return; }
                $key = sanitize_key((string) $k);
                if (in_array($key, $single_keys, true)) {
                    if (is_scalar($v)) { $add($v); }
                    elseif (is_array($v)) { $walk($v, $depth + 1); }
                    continue;
                }
                if (in_array($key, $list_keys, true) && is_array($v)) {
                    $container = in_array($key, array('keys','license_keys','licensekeys','activation_keys','activationkeys'), true) ? 'keys'
                        : (in_array($key, array('vouchers'), true) ? 'vouchers' : $key);
                    foreach ($v as $entry) {
                        if (count($candidates) >= $max_codes) { break; }
                        $walk_delivery_entry($entry, 0, $container);
                    }
                    continue;
                }
                if (is_array($v)) { $walk($v, $depth + 1); }
            }
        };
        $walk($data, 0);

        // GET /orders/:id is kind-dependent. Only for a code-based order do we
        // accept an explicitly scalar delivery/result/payload as a last-resort
        // compatibility shape; this is never applied to top-up/account metadata.
        $kind = sanitize_key((string) ($data['kind'] ?? ''));
        if (in_array($kind, array('gift_card', 'giftcard', 'game_key', 'gamekey'), true)) {
            foreach (array('delivery', 'result', 'payload') as $fallback_key) {
                if (count($candidates) >= $max_codes) { break; }
                if (isset($data[$fallback_key]) && is_scalar($data[$fallback_key])) { $add($data[$fallback_key]); }
            }
        }

        return array_slice(array_values(array_unique(array_filter($candidates))), 0, $max_codes);
    }

    private function item_service_type($item) {
        if (!$item) { return ''; }

        // v3.6+: prefer the order-item snapshot. Product mappings are mutable and products
        // can be deleted; delivery history must not depend on today's catalog state.
        if (is_callable(array($item, 'get_meta'))) {
            $snapshot = sanitize_key((string) $item->get_meta('_dfr_service_type', true));
            if (in_array($snapshot, array('topup', 'giftcard', 'gamekey'), true)) { return $snapshot; }
        }

        if (!is_callable(array($item, 'get_product'))) { return ''; }
        $product = $item->get_product();
        if (!$product) { return ''; }
        $variation_id = $product->get_parent_id() ? $product->get_id() : 0;
        $product_id = $product->get_parent_id() ?: $product->get_id();
        $type = $variation_id ? get_post_meta($variation_id, '_dfr_service_type', true) : '';
        if (!in_array($type, array('topup','giftcard','gamekey'), true)) {
            $type = get_post_meta($product_id, '_dfr_service_type', true);
        }
        return in_array($type, array('topup','giftcard','gamekey'), true) ? $type : '';
    }

    private function item_requires_delivery_code($item) {
        return in_array($this->item_service_type($item), array('giftcard', 'gamekey'), true);
    }

    /**
     * How many codes this line item owes the customer.
     *
     * One supplier order carries the whole line: submit_supplier_order() sends
     * 'quantity' => $item->get_quantity(), and extract_codes() collects a LIST
     * from cards[]/keys[] capped at 100 because, as that method's own comment
     * says, "Supplier order quantity is capped at 100." Quantity N therefore owes
     * N codes.
     *
     * @param object $item Order line item.
     * @return int 0 when the item is not code-based.
     */
    private function item_expected_code_count($item) {
        if (!$item || !$this->item_requires_delivery_code($item)) { return 0; }
        $qty = is_callable(array($item, 'get_quantity')) ? (int) $item->get_quantity() : 1;
        return max(1, min(100, $qty));
    }

    /**
     * How long a short delivery is chased before it is accepted and escalated.
     *
     * @return int Seconds.
     */
    private function short_delivery_grace() {
        return (int) apply_filters('dfr_short_delivery_grace', 6 * HOUR_IN_SECONDS);
    }

    private function item_delivered_code_count($item) {
        if (!$item) { return 0; }
        $count = absint($item->get_meta('_dfr_codes_count', true));
        if ($count > 0 && (string) $item->get_meta('_dfr_codes_enc', true) !== '') { return $count; }
        return count((array) $this->decrypt_item_codes($item));
    }

    /**
     * Is this item's digital delivery COMPLETE?
     *
     * 4.15.2: this returned true as soon as a single code existed, whatever the
     * quantity. Every caller uses it to decide whether to keep reconciling, so an
     * order for five gift cards that received one code was treated as delivered:
     * reconciliation stopped permanently, the customer kept the four they paid
     * for and never received, and nothing anywhere recorded a shortfall.
     *
     * A short delivery is now incomplete, so the existing reconciliation keeps
     * working. It cannot chase forever, though — if the supplier genuinely sends
     * fewer codes than units for some product, that would loop and re-poll
     * indefinitely. After a grace window the shortfall is accepted, stamped and
     * escalated to the merchant once, which is the difference between a bounded
     * retry and a silent loss.
     *
     * @param object $item Order line item.
     * @return bool
     */
    private function item_has_delivery_codes($item) {
        if (!$item) { return false; }
        $delivered = $this->item_delivered_code_count($item);
        if ($delivered < 1) { return false; }

        $expected = $this->item_expected_code_count($item);
        if ($expected <= 1 || $delivered >= $expected) {
            if ($item->get_meta('_dfr_codes_short_since', true)) {
                $item->delete_meta_data('_dfr_codes_short_since');
            }
            return true;
        }

        // Short. Keep reconciling until the grace window closes.
        $since = (string) $item->get_meta('_dfr_codes_short_since', true);
        if ($since === '') {
            $item->update_meta_data('_dfr_codes_short_since', gmdate('c'));
            if (is_callable(array($item, 'save'))) { $item->save(); }
            return false;
        }
        $started = strtotime($since);
        if ($started === false) {
            /* Corrupt stamp. Re-stamp rather than trusting it: returning false on
             * an unparseable value would chase this item forever, and returning
             * true would accept a shortfall that may never have been given its
             * grace window. Re-stamping does neither and self-heals. */
            $item->update_meta_data('_dfr_codes_short_since', gmdate('c'));
            if (is_callable(array($item, 'save'))) { $item->save(); }
            return false;
        }
        if ((time() - $started) < $this->short_delivery_grace()) {
            return false;
        }
        return true;
    }

    private function queue_item_delivery_refresh($order, $item_id, $item, $delay = 1) {
        if (!$order || !$item || !$this->item_requires_delivery_code($item) || $this->item_has_delivery_codes($item)) { return; }
        $remote = (string) $item->get_meta('_dfr_remote_order_id', true);
        $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
        if (!preg_match('/^ord-[0-9]+$/', $remote) || in_array($status, array('failed', 'refunded'), true)) { return; }
        $lock = 'dfr_delivery_queue_' . md5($remote);
        if (get_transient($lock)) { return; }
        set_transient($lock, 1, 4);
        // Immediate cron-independent refresh first; durable scheduler remains fallback.
        $this->dispatch_reconcile_loopback($order->get_id(), $item_id, $remote);
        $this->schedule_delivery_refresh($order->get_id(), $item_id, $remote, max(1, (int) $delay));
    }

    private function refresh_item_delivery_now($order, $item_id, $item) {
        if (!$order || !$item || !$this->item_requires_delivery_code($item) || $this->item_has_delivery_codes($item)) { return; }
        $remote = (string) $item->get_meta('_dfr_remote_order_id', true);
        $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
        if (!preg_match('/^ord-[0-9]+$/', $remote) || in_array($status, array('failed', 'refunded'), true)) { return; }

        $this->arm_reconciliation_plan($order->get_id(), $item_id, $remote);
        $this->reconcile_remote_once($order->get_id(), $item_id, $remote, 4, 'delivery');
    }

    public function reconcile_recent_deliveries($page = 1) {
        if (!function_exists('wc_get_orders') || !$this->has_api_key()) { return; }
        $page = max(1, min(4, absint($page)));
        $orders = wc_get_orders(array(
            'status'  => array('processing', 'completed', 'on-hold'),
            'limit'   => 50,
            'page'    => $page,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        ));
        $queued = 0;
        $delay = 2;
        foreach ((array) $orders as $order) {
            if (!$order) { continue; }
            foreach ($order->get_items('line_item') as $item_id => $item) {
                if (!$this->item_requires_delivery_code($item) || $this->item_has_delivery_codes($item)) { continue; }
                $remote = (string) $item->get_meta('_dfr_remote_order_id', true);
                $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
                if (!preg_match('/^ord-[0-9]+$/', $remote) || in_array($status, array('failed','refunded'), true)) { continue; }
                $this->queue_item_delivery_refresh($order, $item_id, $item, $delay);
                $queued++;
                $delay = min(180, $delay + 4);
                if ($queued >= 40) { break 2; }
            }
        }
        if ($page < 4 && count((array) $orders) === 50 && !wp_next_scheduled('dfr_reconcile_recent_deliveries', array($page + 1))) {
            wp_schedule_single_event(time() + 90, 'dfr_reconcile_recent_deliveries', array($page + 1));
        }
    }

    private function customer_can_view_order($order) {
        if (!$order || !is_user_logged_in()) { return false; }
        if (current_user_can('manage_woocommerce') || current_user_can('manage_options')) { return true; }
        $uid = (int) $order->get_user_id();
        return $uid > 0 && get_current_user_id() === $uid;
    }

    private function code_crypto_context($order_id, $item_id) {
        return 'delivery-code|order:' . absint($order_id) . '|item:' . absint($item_id);
    }

    private function decrypt_item_codes($item) {
        if (!$item) { return array(); }
        $enc = $item->get_meta('_dfr_codes_enc', true);
        if (!$enc) { return array(); }
        $order_id = is_callable(array($item, 'get_order_id')) ? (int) $item->get_order_id() : 0;
        $item_id = is_callable(array($item, 'get_id')) ? (int) $item->get_id() : 0;
        $context = $this->code_crypto_context($order_id, $item_id);
        $json = DFR_Crypto::decrypt_context($enc, $context);
        $codes = json_decode((string) $json, true);
        if (!is_array($codes)) { return array(); }
        $safe = array();
        foreach ($codes as $code) {
            if (is_scalar($code) && trim((string)$code) !== '') { $safe[] = sanitize_text_field((string)$code); }
        }
        $safe = array_values(array_unique($safe));

        // Transparently migrate v1 code envelopes to contextual AEAD-v2 after a
        // successful owner/admin read. No plaintext is written during migration.
        if ($safe && strpos((string)$enc, 'x2:') !== 0 && strpos((string)$enc, 'g2:') !== 0) {
            $upgraded = DFR_Crypto::encrypt_context(wp_json_encode($safe), $context);
            if (!is_wp_error($upgraded)) {
                $item->update_meta_data('_dfr_codes_enc', $upgraded);
                $item->update_meta_data('_dfr_codes_crypto', 'aead-v2');
                $item->update_meta_data('_dfr_codes_count', count($safe));
                $item->save();
            }
        }
        return $safe;
    }

    private function notify_customer_delivery_ready($order, $item, array $codes) {
        if (!$order || !$item || !$codes || $item->get_meta('_dfr_delivery_notified', true)) { return; }
        $type = $this->item_service_type($item);
        if (!in_array($type, array('giftcard','gamekey'), true)) { return; }
        $item->update_meta_data('_dfr_delivery_notified', gmdate('c'));
        $item->save();

        // Customer note deliberately does not contain the secret code. It produces a standard
        // WooCommerce customer-note notification and points the buyer to the authenticated order page.
        $order->add_order_note(
            __('Votre code numérique est prêt. Connectez-vous à votre compte Delicat Store puis ouvrez Mes commandes → Voir la commande pour l’afficher et le copier en toute sécurité.', 'delicat-fazercards'),
            true
        );
        $order->save();
    }

    private function schedule_delivery_refresh($order_id, $item_id, $remote, $delay = 5) {
        if ((int) $delay <= 5) { $this->enqueue_fast_reconcile($order_id, $item_id, $remote); }
        // Code delivery uses the same independently pre-scheduled canonical plan.
        $this->arm_reconciliation_plan($order_id, $item_id, $remote);
    }

    private function set_completion_block_reason($order, $reason) {
        if (!$order || !is_object($order)) { return; }
        $reason = substr(sanitize_key((string) $reason), 0, 120);
        if ($reason === '') { $reason = 'unknown'; }
        if ((string) $order->get_meta('_dfr_completion_block_reason', true) !== $reason) {
            $order->update_meta_data('_dfr_completion_block_reason', $reason);
            $order->update_meta_data('_dfr_completion_block_reason_at', gmdate('c'));
            $order->save_meta_data();
        }
    }

    private function clear_completion_block_reason($order) {
        if (!$order || !is_object($order)) { return; }
        if ((string) $order->get_meta('_dfr_completion_block_reason', true) !== '') {
            $order->delete_meta_data('_dfr_completion_block_reason');
            $order->delete_meta_data('_dfr_completion_block_reason_at');
            $order->save_meta_data();
        }
    }

    private function queue_completion_candidate($order_id) {
        $order_id = absint($order_id);
        if ($order_id) { $this->completion_candidates[$order_id] = true; }
    }

    private function schedule_local_completion_retry($order_id, $delay = 1) {
        $order_id = absint($order_id);
        if (!$order_id) { return; }
        $dedupe = 'dfr_finalize_queue_' . $order_id;
        if (get_transient($dedupe)) { return; }
        set_transient($dedupe, 1, 5);
        // A retry scheduled by the currently running finalizer must not be rejected as
        // a duplicate of itself. The callback accepts only the order ID; this token is
        // solely part of Action Scheduler/WP-Cron's uniqueness identity.
        $completion_order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        $retry_token = $completion_order ? max(1, (int) $completion_order->get_meta('_dfr_completion_retry_count', true)) : time();
        $args = array($order_id, $retry_token);
        if ((int) $delay <= 1 && function_exists('as_enqueue_async_action') && did_action('action_scheduler_init')) {
            as_enqueue_async_action('dfr_finalize_completed_order', $args, 'delicat-fazercards', true);
            return;
        }
        $timestamp = time() + max(1, (int) $delay);
        if (function_exists('as_schedule_single_action') && did_action('action_scheduler_init')) {
            as_schedule_single_action($timestamp, 'dfr_finalize_completed_order', $args, 'delicat-fazercards', true);
        } elseif (!wp_next_scheduled('dfr_finalize_completed_order', $args)) {
            wp_schedule_single_event($timestamp, 'dfr_finalize_completed_order', $args);
        }
    }

    public function finalize_completed_order($order_id) {
        if (!function_exists('wc_get_order')) { return false; }
        $order_id = absint($order_id);
        delete_transient('dfr_finalize_queue_' . $order_id);
        $order = wc_get_order($order_id);
        if (!$order) { return false; }
        return $this->maybe_complete_woo_order($order, true);
    }

    public function flush_completion_candidates() {
        if (!$this->completion_candidates || !function_exists('wc_get_order')) { return; }
        $ids = array_slice(array_keys($this->completion_candidates), 0, 25);
        $this->completion_candidates = array();
        foreach ($ids as $order_id) {
            $order = wc_get_order(absint($order_id));
            if (!$order || $order->has_status('completed')) { continue; }
            $this->maybe_complete_woo_order($order, true);
        }
    }

    private function maybe_complete_woo_order($order, $fresh_reload = false) {
        if (!$order || !is_object($order) || !method_exists($order, 'get_id')) { return false; }
        $order_id = absint($order->get_id());
        if (!$order_id) { return false; }

        // Always use a fresh WC_Order for the final decision. WooCommerce keeps line
        // items/status in object caches during nested payment/status hooks; using an old
        // instance here can see the supplier state from before item->save().
        if ($fresh_reload || function_exists('wc_get_order')) {
            $fresh = wc_get_order($order_id);
            if ($fresh) { $order = $fresh; }
        }
        if (method_exists($order, 'read_meta_data')) {
            $order->read_meta_data(true);
        }

        $automatic_flow = ($this->settings['live_orders_enabled'] === '1' && $this->settings['auto_fulfill_enabled'] === '1');
        if (!$automatic_flow && $this->settings['auto_complete_woo'] !== '1') {
            $this->set_completion_block_reason($order, 'auto_completion_disabled');
            return false;
        }
        if ($order->has_status('completed')) {
            $this->clear_completion_block_reason($order);
            return true;
        }
        if (!$order->has_status(array('processing', 'on-hold'))) {
            $this->set_completion_block_reason($order, 'woo_status_' . sanitize_key((string) $order->get_status()));
            return false;
        }

        // Re-verify the wallet debit before the irreversible completion transition.
        if (!$this->order_has_verified_wallet_payment($order)) {
            $this->set_completion_block_reason($order, 'wallet_payment_proof_failed');
            $this->log('error', 'WooCommerce completion blocked: wallet payment proof no longer matches', array('order' => $order_id));
            return false;
        }

        $items = $order->get_items('line_item');
        if (!$items) {
            $this->set_completion_block_reason($order, 'no_line_items');
            return false;
        }

        foreach ($items as $item_id => $item) {
            if (is_object($item) && method_exists($item, 'read_meta_data')) {
                $item->read_meta_data(true);
            }
            $type = $this->item_service_type($item);
            if (!in_array($type, array('topup','giftcard','gamekey'), true)) {
                $this->set_completion_block_reason($order, 'untracked_line_' . absint($item_id));
                return false;
            }
            $remote = trim((string) $item->get_meta('_dfr_remote_order_id', true));
            if (!preg_match('/^ord-[0-9]+$/', $remote)) {
                $this->set_completion_block_reason($order, 'missing_remote_' . absint($item_id));
                return false;
            }
            $remote_status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
            if ($remote_status !== 'completed') {
                $this->set_completion_block_reason($order, 'supplier_' . $remote_status . '_' . absint($item_id));
                return false;
            }
            if ($this->item_requires_delivery_code($item) && !$this->item_has_delivery_codes($item)) {
                $this->set_completion_block_reason($order, 'delivery_code_pending_' . absint($item_id));
                $this->queue_item_delivery_refresh($order, $item_id, $item, 1);
                return false;
            }
        }

        $this->queue_completion_candidate($order_id);
        $started = microtime(true);
        $this->mark_order_timing($order, 'completion_start');

        $updated = $order->update_status('completed', __('All digital items completed and delivered.', 'delicat-fazercards'));
        $hook_ms = max(0, (int) round((microtime(true) - $started) * 1000));
        $this->mark_order_timing($order, 'completion_hooks_ms', $hook_ms);

        // Never assume update_status() won. Reload HPOS/posts storage and verify the
        // persisted state. If another hook left it Processing, retry locally after the
        // current hook chain rather than contacting the supplier or requiring admin work.
        $persisted = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if ($updated && $persisted && $persisted->has_status('completed')) {
            $this->clear_completion_block_reason($persisted);
            // Write the completion timestamp only after HPOS/posts storage confirms the
            // actual WooCommerce status. v4.9.1 could stamp this before a failed write.
            $persisted->update_meta_data('_dfr_completed_at', gmdate('c'));
            $persisted->update_meta_data('_dfr_completion_verified_at', gmdate('c'));
            $persisted->delete_meta_data('_dfr_completion_retry_count');
            $persisted->save_meta_data();
            $this->maybe_add_slow_timing_note($persisted);
            unset($this->completion_candidates[$order_id]);
            return true;
        }

        $actual = $persisted ? sanitize_key((string) $persisted->get_status()) : 'reload_failed';
        $target = $persisted ?: $order;
        $this->set_completion_block_reason($target, 'status_write_not_persisted_' . $actual);
        $retry_count = absint($target->get_meta('_dfr_completion_retry_count', true)) + 1;
        $target->update_meta_data('_dfr_completion_retry_count', (string) $retry_count);
        $target->save_meta_data();
        $this->log('warning', 'WooCommerce completion did not persist; local finalizer will retry if safe', array(
            'order' => $order_id,
            'actual_status' => $actual,
            'retry' => $retry_count,
        ));
        // Bound retries so a third-party plugin that intentionally forces Processing
        // cannot create an endless Action Scheduler loop. Shutdown still gives the
        // current request one final local chance after all normal hooks finish.
        if ($retry_count <= 6) {
            $this->schedule_local_completion_retry($order_id, 1);
        }
        return false;
    }

    /**
     * One-time v4.9.2 local repair for orders that supplier fulfillment already marked
     * completed while WooCommerce remained Processing/On-hold because the historical
     * auto-complete toggle was disabled. No supplier request is made here. The normal
     * completion gate re-checks wallet ledger + cryptographic attestation, requires every
     * line to be a tracked Delicat item, requires every supplier state completed, and
     * requires encrypted codes for gift cards/game keys before changing the order status.
     */
    public function repair_completed_processing_orders_once() {
        if ((string) get_option('dfr_completion_repair_pending_v492', '') !== '1' && (string) get_option('dfr_completion_repair_pending_v491', '') !== '1') { return; }
        if (!function_exists('wc_get_orders')) { return; }

        // Claim once atomically enough for normal WordPress request concurrency. If the
        // process crashes, reset to pending so the next request retries the local pass.
        update_option('dfr_completion_repair_pending_v492', 'running', false);
        update_option('dfr_completion_repair_pending_v491', 'running', false);
        try {
            $orders = wc_get_orders(array(
                'status'  => array('processing', 'on-hold'),
                'limit'   => 100,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'objects',
            ));
            foreach ((array) $orders as $order) {
                if (!$order || !$this->order_has_verified_wallet_payment($order)) { continue; }
                $this->maybe_complete_woo_order($order);
            }
            update_option('dfr_completion_repair_pending_v492', 'done', false);
            update_option('dfr_completion_repair_pending_v491', 'done', false);
        } catch (Throwable $e) {
            update_option('dfr_completion_repair_pending_v492', '1', false);
            update_option('dfr_completion_repair_pending_v491', '1', false);
            $this->log('error', 'v4.9.2 completion repair pass failed', array('error' => $e->getMessage()));
        }
    }

    private function store_remote_map($remote, $order_id, $item_id) {
        $name = 'dfr_remote_' . md5($remote);
        $value = array('remote' => $remote, 'order_id' => (int) $order_id, 'item_id' => (int) $item_id);
        if (get_option($name, null) === null) {
            add_option($name, $value, '', false);
        } else {
            update_option($name, $value, false);
        }
    }

    private function get_remote_map($remote) {
        $value = get_option('dfr_remote_' . md5($remote), array());
        if (is_array($value) && !empty($value['remote']) && hash_equals((string) $value['remote'], (string) $remote)) {
            return $value;
        }

        // Self-heal a missing/corrupted lookup option from WooCommerce's canonical
        // order-item metadata. HPOS still stores line-item metadata in these Woo tables.
        // Fail closed on duplicates: a supplier id must bind to exactly one local item.
        if (!preg_match('/^ord-[0-9]+$/', (string) $remote)) { return array(); }
        global $wpdb;
        $items_table = $wpdb->prefix . 'woocommerce_order_items';
        $meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT oi.order_id, oi.order_item_id
             FROM {$meta_table} oim
             INNER JOIN {$items_table} oi ON oi.order_item_id=oim.order_item_id
             WHERE oim.meta_key='_dfr_remote_order_id' AND oim.meta_value=%s
             ORDER BY oi.order_item_id DESC LIMIT 2",
            (string) $remote
        ));
        if (!is_array($rows) || count($rows) !== 1) {
            if (is_array($rows) && count($rows) > 1) {
                $this->log('error', 'Remote order map recovery refused duplicate item bindings', array('remote' => $remote));
            }
            return array();
        }
        $recovered = array(
            'remote'   => (string) $remote,
            'order_id' => absint($rows[0]->order_id ?? 0),
            'item_id'  => absint($rows[0]->order_item_id ?? 0),
        );
        if (!$recovered['order_id'] || !$recovered['item_id']) { return array(); }
        $this->store_remote_map($remote, $recovered['order_id'], $recovered['item_id']);
        return $recovered;
    }

    /**
     * Legacy hook target kept as a no-op for backward compatibility.
     *
     * v3.8 deliberately never places decrypted gift-card/game-key secrets in the
     * server-rendered WooCommerce order HTML. Secrets are released only after an
     * explicit owner-only AJAX reveal request with a fresh WordPress nonce.
     */
    public function maybe_show_customer_codes($item_id, $item, $order) {
        return;
    }

    /**
     * While the owner is viewing an open digital order, use that authenticated page as
     * one more independent reconciliation signal. This covers top-ups as well as code
     * products and never exposes the API key, webhook secret, code, or supplier payload.
     */
    public function render_customer_sync_heartbeat($order) {
        if (!$order || !$this->customer_can_view_order($order)) { return; }
        if (!$order->has_status(array('processing','on-hold'))) { return; }

        $pending = false;
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $type = $this->item_service_type($item);
            if (!in_array($type, array('topup','giftcard','gamekey'), true)) { continue; }
            $remote = trim((string) $item->get_meta('_dfr_remote_order_id', true));
            if (!preg_match('/^ord-[0-9]+$/', $remote)) { continue; }
            $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
            if (in_array($status, array('failed','refunded'), true)) { continue; }
            if ($status === 'completed' && (!$this->item_requires_delivery_code($item) || $this->item_has_delivery_codes($item))) { continue; }
            $pending = true;
            $this->arm_reconciliation_plan($order->get_id(), $item_id, $remote);
        }
        if (!$pending) {
            $this->maybe_complete_woo_order($order, true);
            return;
        }

        $order_id = absint($order->get_id());
        $cfg = array(
            'ajax'     => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('dfr_order_sync_' . $order_id),
            'order'    => $order_id,
            'interval' => 5000,
            'attempts' => 120,
        );
        echo '<span class="dfr-order-sync-heartbeat" data-dfr-sync="' . esc_attr(wp_json_encode($cfg)) . '" hidden></span>';

        static $script_printed = false;
        if ($script_printed) { return; }
        $script_printed = true;
        ?>
<script>
(function(){
  if(window.__dfrOrderSyncHeartbeat)return;window.__dfrOrderSyncHeartbeat=true;
  function start(node){
    var c;try{c=JSON.parse(node.getAttribute('data-dfr-sync')||'{}')}catch(e){return}
    if(!c.ajax||!c.nonce||!c.order)return;
    var attempts=0,stopped=false,timer=0;
    function schedule(ms){if(stopped||attempts>=Number(c.attempts||120))return;clearTimeout(timer);timer=setTimeout(tick,ms)}
    function tick(){
      if(stopped||attempts>=Number(c.attempts||120))return;
      if(document.hidden){schedule(Number(c.interval||5000));return}
      attempts++;
      var body='action=dfr_customer_order_sync_status&nonce='+encodeURIComponent(c.nonce)+'&order='+encodeURIComponent(c.order);
      fetch(c.ajax,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(function(r){return r.json()})
        .then(function(j){
          if(!j||!j.success||!j.data){schedule(Number(c.interval||5000));return}
          if(j.data.reload||j.data.completed){stopped=true;window.location.reload();return}
          if(j.data.pending===false){stopped=true;return}
          schedule(Number(c.interval||5000));
        }).catch(function(){schedule(Number(c.interval||5000))});
    }
    document.addEventListener('visibilitychange',function(){if(!document.hidden&&!stopped)schedule(250)});
    schedule(1200);
  }
  function boot(){document.querySelectorAll('.dfr-order-sync-heartbeat[data-dfr-sync]').forEach(start)}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
</script>
        <?php
    }

    public function render_customer_delivery_panel($order) {
        if (($this->settings['customer_codes_enabled'] ?? '1') !== '1') { return; }
        if (!$order || !$this->customer_can_view_order($order)) { return; }

        $ready = array();
        $pending = array();
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $type = $this->item_service_type($item);
            if (!in_array($type, array('giftcard','gamekey'), true)) { continue; }

            if ($this->item_has_delivery_codes($item)) {
                $count = max(1, absint($item->get_meta('_dfr_codes_count', true)));
                $ready[] = array('name' => (string) $item->get_name(), 'count' => $count);
            } elseif ($item->get_meta('_dfr_remote_order_id', true)) {
                $pending[] = (string) $item->get_name();
                $this->queue_item_delivery_refresh($order, $item_id, $item, 1);
            }
        }
        if (!$ready && !$pending) { return; }

        $order_id = absint($order->get_id());
        echo '<section class="dfr-digital-delivery" data-dfr-order="' . $order_id . '" style="margin:22px 0;padding:18px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 8px 28px rgba(15,23,42,.06)">';
        echo '<h2 style="margin:0 0 6px;font-size:20px">🎁 ' . esc_html__('Livraison numérique', 'delicat-fazercards') . '</h2>';
        if ($ready) {
            echo '<p style="margin:0 0 12px;color:#15803d;font-weight:700">✓ ' . esc_html__('Votre code est disponible et protégé.', 'delicat-fazercards') . '</p>';
            foreach ($ready as $delivery) {
                $label = $delivery['count'] > 1
                    ? sprintf(__('%d codes disponibles', 'delicat-fazercards'), $delivery['count'])
                    : __('Code disponible', 'delicat-fazercards');
                echo '<div style="padding:10px 0;border-top:1px solid #edf2f7"><strong>' . esc_html($delivery['name']) . '</strong><div style="margin-top:5px;color:#15803d;font-size:13px;font-weight:700">✓ ' . esc_html($label) . '</div></div>';
            }
            echo '<button type="button" class="button dfr-secure-order-reveal" data-order="' . $order_id . '" style="margin-top:10px;min-height:42px;border-radius:12px;font-weight:800">🔒 ' . esc_html__('Afficher mon code cadeau', 'delicat-fazercards') . '</button>';
            echo '<div class="dfr-secure-order-output" aria-live="polite" style="margin-top:10px"></div>';
        }
        if ($pending) {
            echo '<p style="margin:12px 0 0;padding:10px 12px;border-radius:10px;background:#fff7ed;color:#9a3412">⏳ ' . esc_html__('Une partie de votre livraison est encore en cours. Cette page se mettra à jour dès que la livraison du code est confirmée.', 'delicat-fazercards') . '</p>';
        }
        echo '</section>';

        static $script_printed = false;
        if ($script_printed) { return; }
        $script_printed = true;
        $cfg = array(
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dfr_delica_codes'),
        );
        echo '<script>window.DFR_SECURE_DELIVERY=' . wp_json_encode($cfg) . ';</script>';
        ?>
<script>
(function(){
  if (window.__dfrSecureDeliveryBound) return;
  window.__dfrSecureDeliveryBound = true;
  function copyText(text, button){
    var done=function(){var old=button.textContent;button.textContent='✅ Copié';setTimeout(function(){button.textContent=old;},1300)};
    if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(done).catch(function(){});}
  }
  function refreshNonce(){
    return fetch(window.DFR_SECURE_DELIVERY.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=dfr_delica_nonce'})
      .then(function(r){return r.json()}).then(function(j){if(!j||!j.success||!j.data||!j.data.nonce)throw new Error('nonce');window.DFR_SECURE_DELIVERY.nonce=j.data.nonce;});
  }
  function reveal(button,retried){
    var section=button.closest('.dfr-digital-delivery'), out=section&&section.querySelector('.dfr-secure-order-output');
    if(!section||!out)return;
    button.disabled=true;button.textContent='⏳ Chargement sécurisé…';out.textContent='';
    var body='action=dfr_delica_reveal_codes&nonce='+encodeURIComponent(window.DFR_SECURE_DELIVERY.nonce)+'&order='+encodeURIComponent(button.dataset.order||'');
    fetch(window.DFR_SECURE_DELIVERY.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
      .then(function(r){return r.json()}).then(function(j){
        if(j&&j.success&&j.data&&Array.isArray(j.data.items)){
          button.style.display='none';
          j.data.items.forEach(function(it){
            var box=document.createElement('div');box.style.cssText='margin-top:9px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc';
            var name=document.createElement('div');name.textContent=it.name||'';name.style.cssText='font-size:12px;font-weight:800;color:#475569;margin-bottom:6px';box.appendChild(name);
            var row=document.createElement('div');row.style.cssText='display:flex;gap:8px;align-items:center;flex-wrap:wrap';
            var code=document.createElement('code');code.textContent=it.code||'';code.style.cssText='padding:8px 10px;border-radius:8px;background:#fff;border:1px solid #e2e8f0;word-break:break-all;user-select:all';row.appendChild(code);
            var cp=document.createElement('button');cp.type='button';cp.className='button';cp.textContent='📋 Copier';cp.addEventListener('click',function(){copyText(it.code||'',cp)});row.appendChild(cp);box.appendChild(row);out.appendChild(box);
          });
          return;
        }
        var code=j&&j.data&&j.data.code?j.data.code:'';
        if(code==='nonce'&&!retried){refreshNonce().then(function(){reveal(button,true)}).catch(function(){button.disabled=false;button.textContent='🔁 Réessayer';});return;}
        throw new Error(code||'reveal');
      }).catch(function(){button.disabled=false;button.textContent='🔁 Réessayer';out.textContent='Impossible d’afficher le code pour le moment. Réessayez.';});
  }
  document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('.dfr-secure-order-reveal');if(b)reveal(b,false)});
})();
</script>
        <?php
    }

    public function add_delivery_order_column($columns) {
        $out = array();
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ($key === 'order-status') { $out['dfr-delivery'] = __('Livraison', 'delicat-fazercards'); }
        }
        if (!isset($out['dfr-delivery'])) { $out['dfr-delivery'] = __('Livraison', 'delicat-fazercards'); }
        return $out;
    }

    public function render_delivery_order_column($order) {
        if (!$order) { echo '—'; return; }
        $has_code_product = false;
        $ready = false;
        $pending = false;
        foreach ($order->get_items('line_item') as $item) {
            $type = $this->item_service_type($item);
            if (!in_array($type, array('giftcard','gamekey'), true)) { continue; }
            $has_code_product = true;
            if ($this->item_has_delivery_codes($item)) { $ready = true; }
            elseif ($item->get_meta('_dfr_remote_order_id', true)) { $pending = true; }
        }
        if (!$has_code_product) { echo '—'; return; }
        if ($ready) { echo '<span style="font-weight:700;color:#15803d">✓ ' . esc_html__('Code disponible', 'delicat-fazercards') . '</span>'; return; }
        if ($pending) { echo '<span style="font-weight:600;color:#b45309">⏳ ' . esc_html__('En cours', 'delicat-fazercards') . '</span>'; return; }
        echo '<span style="color:#64748b">' . esc_html__('En attente du paiement', 'delicat-fazercards') . '</span>';
    }

    private function delica_history_order_belongs_to_user($order, $user_id = 0) {
        if (!$order || !is_object($order)) { return false; }
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        if (!$user_id) { return false; }
        if ((int) $order->get_customer_id() === $user_id) { return true; }
        $user = get_userdata($user_id);
        if (!$user || empty($user->user_email)) { return false; }
        $billing = strtolower(trim((string) $order->get_billing_email()));
        $account = strtolower(trim((string) $user->user_email));
        return $billing !== '' && $account !== '' && hash_equals($account, $billing);
    }


    /**
     * Secret digital delivery requires a WooCommerce order actually linked to the
     * logged-in account. Email-only ownership is intentionally insufficient by
     * default because core WordPress does not itself prove that a newly registered
     * account controls a historical guest-checkout email address. Sites with a
     * separate, enforced verified-email claim flow can opt in through the filter.
     */
    private function delica_history_can_access_secret($order, $user_id = 0) {
        if (!$order || !is_object($order) || !is_user_logged_in()) { return false; }
        if (current_user_can('manage_woocommerce') || current_user_can('manage_options')) { return true; }
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        if ($user_id < 1) { return false; }
        if ((int) $order->get_customer_id() === $user_id) { return true; }
        $allow_verified_guest_claim = (bool) apply_filters('dfr_allow_guest_email_delivery_access', false, $order, $user_id);
        return $allow_verified_guest_claim && $this->delica_history_order_belongs_to_user($order, $user_id);
    }

    /** Bound customer-triggered supplier refreshes so an account page cannot be
     * abused as an upstream request amplifier. The background reconciliation queue
     * remains the primary recovery mechanism. */
    private function customer_delivery_refresh_allowed($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        if ($user_id < 1) { return false; }
        $key = 'dfr_delivery_rl_' . $user_id;
        $lock_name = 'dfr_delivery_rl_' . $user_id;
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) === '1';
        if (!$locked) { return false; }
        try {
            $row = get_transient($key);
            $row = is_array($row) ? $row : array('count' => 0);
            if ((int) ($row['count'] ?? 0) >= 30) { return false; }
            $row['count'] = (int) ($row['count'] ?? 0) + 1;
            return (bool) set_transient($key, $row, 5 * MINUTE_IN_SECONDS);
        } finally {
            if ($locked) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name)); }
        }
    }

    /** The browser may ask every five seconds, but the canonical per-remote slot still
     * limits supplier reads. This account-level ceiling also prevents many tabs/orders
     * from turning an authenticated storefront session into an API amplifier. */
    private function customer_order_sync_allowed($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        if ($user_id < 1) { return false; }
        $key = 'dfr_order_sync_rl_' . $user_id;
        $lock_name = 'dfr_order_sync_rl_' . $user_id;
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) === '1';
        if (!$locked) { return false; }
        try {
            $row = get_transient($key);
            $count = is_array($row) ? absint($row['count'] ?? 0) : 0;
            if ($count >= 125) { return false; }
            return (bool) set_transient($key, array('count' => $count + 1), 10 * MINUTE_IN_SECONDS);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    private function send_private_json_security_headers() {
        if (function_exists('nocache_headers')) { nocache_headers(); }
        if (!headers_sent()) {
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
            header('X-LiteSpeed-Cache-Control: no-cache, no-store');
        }
    }

    /** Limit secret reveal/decryption calls independently from delivery refreshes. */
    private function customer_secret_reveal_allowed($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        if ($user_id < 1) { return false; }
        $key = 'dfr_reveal_rl_' . $user_id;
        $lock_name = 'dfr_reveal_rl_' . $user_id;
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) === '1';
        if (!$locked) { return false; }
        try {
            $row = get_transient($key);
            $count = is_array($row) ? absint($row['count'] ?? 0) : 0;
            if ($count >= 20) { return false; }
            return (bool) set_transient($key, array('count' => $count + 1), 5 * MINUTE_IN_SECONDS);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    private function delica_history_delivery_state($order) {
        $state = array('has' => false, 'ready' => false, 'pending' => false, 'waiting_code' => false, 'failed' => false, 'count' => 0);
        if (!$order) { return $state; }
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $type = $this->item_service_type($item);
            if (!in_array($type, array('giftcard', 'gamekey'), true)) { continue; }
            $state['has'] = true;
            $enc = (string) $item->get_meta('_dfr_codes_enc', true);
            if ($enc !== '') {
                $count = absint($item->get_meta('_dfr_codes_count', true));
                if ($count < 1) {
                    $codes = $this->decrypt_item_codes($item);
                    $count = count($codes);
                }
                if ($count > 0) {
                    // 4.15.2: short deliveries stay 'pending' on the customer's
                    // surfaces while they are still being chased, so the order does
                    // not read as fully delivered when it is not.
                    $expected = $this->item_expected_code_count($item);
                    if ($expected > 1 && $count < $expected && !$item->get_meta('_dfr_codes_short_since', true)) {
                        $state['pending'] = true;
                    } else {
                        $state['ready'] = true;
                    }
                    $state['count'] += $count;
                }
                continue;
            }
            $remote = (string) $item->get_meta('_dfr_remote_order_id', true);
            if ($remote === '') { continue; }
            $remote_status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
            if (in_array($remote_status, array('failed', 'refunded'), true)) {
                $state['failed'] = true;
                continue;
            }
            $state['pending'] = true;
            if ($remote_status === 'completed') { $state['waiting_code'] = true; }
            // A transaction-history page can contain hundreds of old rows. Queue
            // only a small newest-page budget here; the normal reconciliation worker
            // continues recovering the rest without flooding FazerCards.
            static $history_queue_budget = 0;
            if ($history_queue_budget < 8) {
                $this->queue_item_delivery_refresh($order, $item_id, $item, 1);
                $history_queue_budget++;
            }
        }
        return $state;
    }

    private function ensure_delica_history_assets() {
        static $done = false;
        if ($done) { return; }
        $done = true;
        wp_enqueue_script('jquery');
        $cfg = array('ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('dfr_delica_codes'));
        $js = 'window.DFR_DELICA_HISTORY=' . wp_json_encode($cfg) . ';' . <<<'JS'
(function(){
  function message(box,text,bad){var old=box.querySelector('.dfr-delica-msg');if(old)old.remove();var d=document.createElement('div');d.className='dfr-delica-msg';d.textContent=text;d.style.cssText='margin-top:8px;padding:9px 11px;border-radius:9px;font-size:13px;font-weight:650;background:'+(bad?'#fdeaea':'#ecfdf3')+';color:'+(bad?'#8a2b2b':'#166534')+';';box.appendChild(d)}
  function copy(text,b){var ok=function(){var o=b.textContent;b.textContent='✅ Copié';setTimeout(function(){b.textContent=o},1400)};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(ok)}else{var t=document.createElement('textarea');t.value=text;document.body.appendChild(t);t.select();try{document.execCommand('copy');ok()}catch(e){}t.remove()}}
  function render(box,items){var out=box.querySelector('.dfr-delica-out');out.innerHTML='';items.forEach(function(it){var c=document.createElement('div');c.style.cssText='margin-top:8px;padding:10px 12px;border:1px dashed #5D4B8E;border-radius:10px;background:#f7f3fb';var n=document.createElement('div');n.textContent=it.name||'Code numérique';n.style.cssText='font-size:12px;font-weight:800;color:#4a3179;margin-bottom:6px';c.appendChild(n);var code=document.createElement('code');code.textContent=it.code;code.style.cssText='display:inline-block;background:#fff;border:1px solid #e5dcef;border-radius:7px;padding:6px 9px;word-break:break-all;user-select:all';c.appendChild(code);var b=document.createElement('button');b.type='button';b.textContent='📋 Copier';b.style.cssText='margin-left:7px;border:0;border-radius:8px;background:#5D4B8E;color:#fff;padding:7px 10px;font-weight:750;cursor:pointer';b.addEventListener('click',function(){copy(it.code,b)});c.appendChild(b);out.appendChild(c)})}
  function readyMarkup(box,count){box.className='dfr-delica-history';box.innerHTML='<div style="margin-bottom:8px;font-size:12px;font-weight:800;color:#15803d">✓ '+(count>1?count+' codes disponibles':'Code disponible')+' · chiffré et protégé</div><button type="button" class="dfr-delica-reveal" style="min-height:42px;border:0;border-radius:12px;background:#5D4B8E;color:#fff;padding:10px 15px;font-weight:800;cursor:pointer">🔒 Afficher mon code cadeau</button><div class="dfr-delica-out"></div>'}
  function request(box,b,retried){b.disabled=true;b.textContent='⏳ Chargement…';var body='action=dfr_delica_reveal_codes&nonce='+encodeURIComponent(window.DFR_DELICA_HISTORY.nonce)+'&order='+encodeURIComponent(box.dataset.order);fetch(window.DFR_DELICA_HISTORY.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).then(function(r){return r.json()}).then(function(j){if(j&&j.success&&j.data&&j.data.items){render(box,j.data.items);b.style.display='none';return}var code=j&&j.data&&j.data.code?j.data.code:'error';if(code==='nonce'&&!retried){fetch(window.DFR_DELICA_HISTORY.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=dfr_delica_nonce'}).then(function(r){return r.json()}).then(function(n){if(n&&n.success&&n.data&&n.data.nonce){window.DFR_DELICA_HISTORY.nonce=n.data.nonce;request(box,b,true)}else{throw new Error('nonce')}}).catch(function(){b.disabled=false;b.textContent='🔁 Réessayer';message(box,'Session expirée. Rechargez la page puis réessayez.',true)});return}b.disabled=false;b.textContent='🔁 Réessayer';message(box,(j&&j.data&&j.data.message)||'Impossible de lire le code pour le moment.',true)}).catch(function(){b.disabled=false;b.textContent='🔁 Réessayer';message(box,'Connexion interrompue. Réessayez.',true)})}
  function refreshNonce(){return fetch(window.DFR_DELICA_HISTORY.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=dfr_delica_nonce'}).then(function(r){return r.json()}).then(function(n){if(n&&n.success&&n.data&&n.data.nonce){window.DFR_DELICA_HISTORY.nonce=n.data.nonce;return true}throw new Error('nonce')})}
  function checkPending(box,attempt,nonceRetried){if(!box||!box.isConnected||box.dataset.checking==='1')return;box.dataset.checking='1';var body='action=dfr_delica_delivery_status&nonce='+encodeURIComponent(window.DFR_DELICA_HISTORY.nonce)+'&order='+encodeURIComponent(box.dataset.order);fetch(window.DFR_DELICA_HISTORY.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).then(function(r){return r.json()}).then(function(j){box.dataset.checking='0';if(j&&j.success&&j.data){if(j.data.ready){readyMarkup(box,parseInt(j.data.count||1,10));return}if(j.data.failed){box.innerHTML='<div class="dfr-delica-msg" style="padding:9px 11px;border-radius:9px;background:#fdeaea;color:#8a2b2b;font-size:13px;font-weight:650">⚠️ La livraison fournisseur n’a pas été finalisée. Contactez le support avec ce numéro de commande.</div>';return}}var code=j&&j.data&&j.data.code?j.data.code:'';if(code==='nonce'&&!nonceRetried){box.dataset.checking='1';refreshNonce().then(function(){box.dataset.checking='0';checkPending(box,attempt,true)}).catch(function(){box.dataset.checking='0'});return}var delays=[2500,4000,7000,12000,20000,30000];if(attempt<delays.length)setTimeout(function(){checkPending(box,attempt+1,nonceRetried)},delays[attempt])}).catch(function(){box.dataset.checking='0';if(attempt<3)setTimeout(function(){checkPending(box,attempt+1,nonceRetried)},5000)})}
  document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('.dfr-delica-reveal');if(!b)return;e.preventDefault();e.stopPropagation();var box=b.closest('.dfr-delica-history');if(box)request(box,b,false)});
  function startVisible(){var boxes=Array.prototype.slice.call(document.querySelectorAll('.dfr-delica-pending[data-order]')).filter(function(box){return box.offsetParent!==null&&box.dataset.dfrPollStarted!=='1'}).slice(0,8);boxes.forEach(function(box,i){box.dataset.dfrPollStarted='1';setTimeout(function(){checkPending(box,0)},600+(i*500))})}
  document.addEventListener('click',function(e){if(e.target.closest&&e.target.closest('.delica-more-btn'))setTimeout(startVisible,120)});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',startVisible,{once:true});else startVisible();
})();
JS;
        wp_add_inline_script('jquery', $js, 'after');
    }

    public function sync_delica_transaction_rows($rows, $status, $user_id) {
        if (!is_array($rows) || !function_exists('wc_get_order') || !$user_id) { return $rows; }
        foreach ($rows as &$row) {
            if (empty($row['id']) || !isset($row['details'])) { continue; }
            $label = isset($row['label']) ? (string) $row['label'] : '';
            if (strpos($label, 'Commande') === false && strpos($label, 'Récompense') === false) { continue; }
            $order = wc_get_order(absint($row['id']));
            if (!$order || !$this->delica_history_order_belongs_to_user($order, $user_id)) { continue; }
            $state = $this->delica_history_delivery_state($order);
            if (!$state['has']) { continue; }
            if (!$this->delica_history_can_access_secret($order, $user_id)) {
                $row['details'] .= '<div style="margin-top:10px;padding:9px 11px;border-radius:10px;background:#f8fafc;color:#475569;font-size:13px;font-weight:650">🔐 ' . esc_html__('Pour protéger votre code, cette ancienne commande invitée doit être associée à votre compte par le support avant l’affichage de la livraison numérique.', 'delicat-fazercards') . '</div>';
                continue;
            }
            $this->ensure_delica_history_assets();
            if ($state['ready'] && $state['count'] > 0) {
                $word = $state['count'] > 1 ? $state['count'] . ' codes disponibles' : 'Code disponible';
                $row['details'] .= '<div class="dfr-delica-history" data-order="' . absint($order->get_id()) . '" style="margin-top:10px"><div style="margin-bottom:8px;font-size:12px;font-weight:800;color:#15803d">✓ ' . esc_html($word) . ' · chiffré et protégé</div><button type="button" class="dfr-delica-reveal" style="min-height:42px;border:0;border-radius:12px;background:#5D4B8E;color:#fff;padding:10px 15px;font-weight:800;cursor:pointer">🔒 Afficher mon code cadeau</button><div class="dfr-delica-out"></div></div>';
            } elseif ($state['failed']) {
                $row['details'] .= '<div style="margin-top:10px;padding:9px 11px;border-radius:10px;background:#fdeaea;color:#8a2b2b;font-size:13px;font-weight:650">⚠️ La livraison numérique n’a pas été finalisée. Contactez le support avec ce numéro de commande.</div>';
            } elseif ($state['pending']) {
                $msg = $state['waiting_code']
                    ? '✓ Transaction confirmée — récupération sécurisée de votre code en cours…'
                    : '⏳ Code cadeau en préparation — la livraison apparaîtra automatiquement ici dès confirmation.';
                $row['details'] .= '<div class="dfr-delica-history dfr-delica-pending" data-order="' . absint($order->get_id()) . '" style="margin-top:10px;padding:9px 11px;border-radius:10px;background:#fff7ed;color:#9a3412;font-size:13px;font-weight:650">' . esc_html($msg) . '</div>';
            }
        }
        unset($row);
        return $rows;
    }

    public function ajax_delica_delivery_status() {
        $this->send_private_json_security_headers();
        if (!is_user_logged_in()) { wp_send_json_error(array('code' => 'auth', 'message' => __('Session expirée.', 'delicat-fazercards')), 403); }
        if (!check_ajax_referer('dfr_delica_codes', 'nonce', false)) { wp_send_json_error(array('code' => 'nonce', 'message' => __('Session expirée.', 'delicat-fazercards')), 403); }
        if (!function_exists('wc_get_order')) { wp_send_json_error(array('code' => 'wc', 'message' => __('WooCommerce est indisponible.', 'delicat-fazercards')), 500); }
        $order_id = isset($_POST['order']) ? absint($_POST['order']) : 0;
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order || !$this->delica_history_can_access_secret($order)) { wp_send_json_error(array('code' => 'owner', 'message' => __('Cette commande n’est pas liée de façon sécurisée à votre compte.', 'delicat-fazercards')), 403); }

        if ($this->customer_delivery_refresh_allowed(get_current_user_id())) {
            foreach ($order->get_items('line_item') as $item_id => $item) {
                if (!$this->item_requires_delivery_code($item) || $this->item_has_delivery_codes($item)) { continue; }
                $this->refresh_item_delivery_now($order, $item_id, $item);
            }
        }
        $state = $this->delica_history_delivery_state($order);
        wp_send_json_success(array(
            'ready'        => !empty($state['ready']),
            'count'        => absint($state['count']),
            'pending'      => !empty($state['pending']),
            'waiting_code' => !empty($state['waiting_code']),
            'failed'       => !empty($state['failed']),
        ));
    }

    /** Owner-only status heartbeat for all supported product types. Every supplier
     * result is fetched server-side from the canonical GET /orders/{id} endpoint and
     * passed through the same wallet/binding/order-lock checks as background workers. */
    public function ajax_customer_order_sync_status() {
        $this->send_private_json_security_headers();
        if (!is_user_logged_in()) { wp_send_json_error(array('code' => 'auth'), 403); }
        if (!function_exists('wc_get_order')) { wp_send_json_error(array('code' => 'wc'), 500); }
        $order_id = isset($_POST['order']) ? absint($_POST['order']) : 0;
        if (!$order_id || !check_ajax_referer('dfr_order_sync_' . $order_id, 'nonce', false)) {
            wp_send_json_error(array('code' => 'nonce'), 403);
        }
        $order = wc_get_order($order_id);
        if (!$order || !$this->customer_can_view_order($order)) {
            wp_send_json_error(array('code' => 'owner'), 403);
        }

        $allowed = $this->customer_order_sync_allowed(get_current_user_id());
        $checked = 0;
        if ($allowed && $this->order_has_verified_wallet_payment($order)) {
            foreach ($order->get_items('line_item') as $item_id => $item) {
                $type = $this->item_service_type($item);
                if (!in_array($type, array('topup','giftcard','gamekey'), true)) { continue; }
                $remote = trim((string) $item->get_meta('_dfr_remote_order_id', true));
                if (!preg_match('/^ord-[0-9]+$/', $remote)) { continue; }
                $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
                if (in_array($status, array('failed','refunded'), true)) { continue; }
                if ($status === 'completed' && (!$this->item_requires_delivery_code($item) || $this->item_has_delivery_codes($item))) { continue; }
                $this->arm_reconciliation_plan($order_id, $item_id, $remote);
                if ($checked < 2) {
                    $state = $this->reconcile_remote_once($order_id, $item_id, $remote, 5, 'customer');
                    if ($state !== 'busy') { $checked++; }
                }
            }
        }

        $fresh = wc_get_order($order_id);
        $pending = false;
        $failed = false;
        if ($fresh) {
            foreach ($fresh->get_items('line_item') as $item) {
                $type = $this->item_service_type($item);
                if (!in_array($type, array('topup','giftcard','gamekey'), true)) { continue; }
                $remote = trim((string) $item->get_meta('_dfr_remote_order_id', true));
                if (!preg_match('/^ord-[0-9]+$/', $remote)) { continue; }
                $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
                if (in_array($status, array('failed','refunded'), true)) { $failed = true; continue; }
                if ($status !== 'completed' || ($this->item_requires_delivery_code($item) && !$this->item_has_delivery_codes($item))) {
                    $pending = true;
                }
            }
        }
        $woo_status = $fresh ? sanitize_key((string) $fresh->get_status()) : 'unknown';
        wp_send_json_success(array(
            'woo_status' => $woo_status,
            'completed'  => $woo_status === 'completed',
            'pending'    => $pending,
            'failed'     => $failed,
            'reload'     => !in_array($woo_status, array('processing','on-hold'), true),
        ));
    }

    public function ajax_delica_nonce() {
        $this->send_private_json_security_headers();
        if (!is_user_logged_in()) { wp_send_json_error(array('code' => 'auth', 'message' => __('Session expirée.', 'delicat-fazercards')), 403); }
        wp_send_json_success(array('nonce' => wp_create_nonce('dfr_delica_codes')));
    }

    public function ajax_delica_reveal_codes() {
        $this->send_private_json_security_headers();
        if (($this->settings['customer_codes_enabled'] ?? '1') !== '1') { wp_send_json_error(array('code' => 'disabled', 'message' => __('La livraison numérique est désactivée.', 'delicat-fazercards')), 403); }
        if (!is_user_logged_in()) { wp_send_json_error(array('code' => 'auth', 'message' => __('Connectez-vous pour afficher ce code.', 'delicat-fazercards')), 403); }
        if (!check_ajax_referer('dfr_delica_codes', 'nonce', false)) { wp_send_json_error(array('code' => 'nonce', 'message' => __('Session expirée.', 'delicat-fazercards')), 403); }
        if (!function_exists('wc_get_order')) { wp_send_json_error(array('code' => 'wc', 'message' => __('WooCommerce est indisponible.', 'delicat-fazercards')), 500); }
        $order_id = isset($_POST['order']) ? absint($_POST['order']) : 0;
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order || !$this->delica_history_can_access_secret($order)) { wp_send_json_error(array('code' => 'owner', 'message' => __('Cette commande n’est pas liée de façon sécurisée à votre compte.', 'delicat-fazercards')), 403); }
        if (!$this->customer_secret_reveal_allowed(get_current_user_id())) {
            wp_send_json_error(array('code' => 'rate', 'message' => __('Trop de demandes de code. Réessayez dans quelques minutes.', 'delicat-fazercards')), 429);
        }
        $items = array(); $failed = 0;
        foreach ($order->get_items('line_item') as $item) {
            $type = $this->item_service_type($item);
            if (!in_array($type, array('giftcard', 'gamekey'), true)) { continue; }
            $enc = (string) $item->get_meta('_dfr_codes_enc', true);
            if ($enc === '') { continue; }
            $codes = $this->decrypt_item_codes($item);
            if (!$codes) { $failed++; continue; }
            foreach ($codes as $code) { $items[] = array('name' => (string) $item->get_name(), 'code' => (string) $code); }
        }
        if (!$items && !$failed) { wp_send_json_error(array('code' => 'none', 'message' => __('Votre code est encore en préparation.', 'delicat-fazercards')), 409); }
        if (!$items && $failed) { wp_send_json_error(array('code' => 'decrypt', 'message' => __('Le coffre-fort n’a pas pu déchiffrer ce code. Contactez le support.', 'delicat-fazercards')), 500); }
        wp_send_json_success(array('items' => $items, 'failed' => $failed));
    }

}
