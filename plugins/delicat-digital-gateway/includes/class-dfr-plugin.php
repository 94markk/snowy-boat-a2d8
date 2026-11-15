<?php
if (!defined('ABSPATH')) {
    exit;
}

final class DFR_Plugin {
    private static $instance;
    private $settings = array();
    private $completion_candidates = array();
    private $post_response_reconcile = array();
    private $post_response_fulfill = array();
    // v4.24: loopbacks fired only after this request's own response is flushed, and
    // signed webhooks whose fast settlement lost a race (see handle_webhook()).
    private $deferred_loopbacks = array();
    private $post_response_webhooks = array();
    private $response_released = false;
    private $internal_reconcile_active = false;
    // Detached loopback rounds: 0 s, then 5 s x4, then 8/10/12 s (about a minute in
    // total) before the durable Action Scheduler plan takes over. v4.23 stopped at 20 s.
    const LOOPBACK_ROUNDS = 8;
    // True only while maybe_complete_woo_order() itself moves an order to Completed, so
    // the manual-completion guard can tell automatic completion from an admin action.
    private $internal_completion = false;
    // True only while mark_item_delivery_failed() puts an order On hold, so the hold
    // marker (_dfr_gateway_hold) survives the gateway's own status change.
    private $internal_status_change = false;
    // Delivery summaries of this request, keyed by a fingerprint of everything they read.
    private $delivery_summary_memo = array();
    // Supplier mapping changes by administrators, logged as one audit entry per request.
    private $mapping_audit = array();
    private $mapping_audit_total = 0;
    private $mapping_audit_hooked = false;

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
            'dfr_fulfill_paid_order',
            'dfr_send_delivery_failure_alert',
            'dfr_send_attestation_refusal_alert',
            'dfr_keyring_migration_batch',
        ) as $hook) {
            self::clear_wp_cron_hook($hook);
            if (function_exists('as_unschedule_all_actions') && did_action('action_scheduler_init')) {
                as_unschedule_all_actions($hook); // hook only: array() args would skip every job that has arguments
            }
        }
        if (class_exists('DFR_Catalog')) { DFR_Catalog::deactivate(); }
        flush_rewrite_rules(false);
    }

    /**
     * Remove every WP-Cron event of a hook, whatever its arguments. wp_clear_scheduled_hook()
     * only removes the events whose arguments equal its second parameter (none by default),
     * so the per-order fallbacks and the every-minute watchdog (args: wp_cron) outlived
     * deactivation and kept waking WP-Cron with no handler.
     */
    public static function clear_wp_cron_hook($hook) {
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook((string) $hook);
            return;
        }
        wp_clear_scheduled_hook((string) $hook);
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
            'catalog_auto_sync'        => '1',
            'catalog_sync_interval'    => 'dfr_30_seconds',
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
        // v4.24: the obsolete v1 list is dropped once per version change. Deleting it on
        // every request cost an uncached SELECT on each page view.
        if ((string) get_option('dfr_plugin_version', '') !== DFR_VERSION) {
            delete_transient('dfr_uid_validation_games_v1');
        }

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

        // v4.14.3 aligns Player ID verification with the documented FazerCards
        // contract (HTTP 400 / ok:false = the ID was not accepted, not an outage) and
        // resolves same-game regional validation categories deterministically.
        // Refresh the cached validation catalog once so the new resolver ranks a
        // freshly fetched list; no customer identifiers or secrets are persisted.
        if ($previous_version !== '' && version_compare($previous_version, '4.14.3', '<')) {
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

        // This requested release enables fast stock sync once; later merchant edits persist.
        if (!get_option('dfr_live_sync_migrated_v417', false)) {
            $current = (array) get_option('dfr_settings', array());
            $current['catalog_auto_sync'] = '1';
            $current['catalog_sync_interval'] = 'dfr_30_seconds';
            $current['catalog_disable_missing'] = '1';
            $current['auto_complete_woo'] = '1';
            update_option('dfr_settings', wp_parse_args($current, self::defaults()), false);
            update_option('dfr_live_sync_migrated_v417', '1', false);
        }

        if ((string) get_option('dfr_plugin_version', '') !== DFR_VERSION) {
            update_option('dfr_plugin_version', DFR_VERSION, false);
        }
    }

    /**
     * Options this plugin reads on every request (settings, one-time migration and
     * repair flags). Most are stored with autoload off, so without a persistent object
     * cache each one cost its own SELECT on every page view, about fifteen in all.
     */
    private static function per_request_option_names() {
        return array(
            'dfr_settings', 'dfr_plugin_version', 'dfr_wallet_attestation_required_after',
            'dfr_wallet_attestation_legacy_max_order_id', 'dfr_live_sync_migrated_v417',
            'dfr_reconcile_watchdog_version', 'dfr_completion_repair_pending_v492',
            'dfr_completion_repair_pending_v491', 'dfr_reconcile_repair_pending_v414',
            'dfr_reconcile_repair_pending_v4131', 'dfr_reconcile_repair_pending_v413',
            'dfr_reconcile_repair_pending_v410', 'dfr_public_privacy_migrated_v393',
            'dfr_auto_terms_tagged_v1', 'dfr_reseller_schema_version',
        );
    }

    public function boot() {
        // One query instead of one per option (WordPress 6.4+; a no-op with an object cache
        // that already holds them). Missing options are remembered as missing too.
        if (function_exists('wp_prime_option_caches')) {
            wp_prime_option_caches(self::per_request_option_names());
        }
        $this->maybe_upgrade_settings();
        $this->settings = wp_parse_args((array) get_option('dfr_settings', array()), self::defaults());
        $this->initialize_wallet_attestation_boundary();

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_notices', array($this, 'legacy_folder_privacy_notice'));
        add_action('admin_notices', array($this, 'render_admin_audit_log'));
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

        // v4.15: payment and thank-you hooks only QUEUE the supplier pass. Supplier I/O
        // (Player ID re-check + order creation, up to ~18 s per line) used to run inside
        // the checkout request, which made the app time out after the wallet was debited.
        // The queued pass starts at once through a single-use loopback and post-response
        // work, with a non-unique Action Scheduler job and the watchdog as durable fallback.
        add_action('woocommerce_order_status_processing', array($this, 'queue_fulfillment_from_hook'));
        add_action('woocommerce_payment_complete', array($this, 'queue_fulfillment_from_hook'));
        // Re-run only the local completion gate after every other normal payment-complete
        // callback has had a chance to finish. The supplier is never called here.
        add_action('woocommerce_payment_complete', array($this, 'finalize_completed_order'), PHP_INT_MAX, 1);
        add_action('woocommerce_order_payment_status_changed', array($this, 'queue_fulfillment_from_hook'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'render_customer_sync_heartbeat'), 4);
        add_action('woocommerce_order_details_after_order_table', array($this, 'render_customer_delivery_panel'), 5);
        add_filter('woocommerce_my_account_my_orders_columns', array($this, 'add_delivery_order_column'), 30);
        add_action('woocommerce_my_account_my_orders_column_dfr-delivery', array($this, 'render_delivery_order_column'));
        add_action('woocommerce_thankyou', array($this, 'queue_fulfillment_from_hook'), 5);
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'admin_queue_order_reconcile'), 5);
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_admin_delivery_failures'), 6);
        add_action('admin_post_dfr_retry_failed_delivery', array($this, 'admin_retry_failed_delivery'));
        add_action('admin_post_dfr_resolve_failed_delivery', array($this, 'admin_resolve_failed_delivery'));
        add_action('dfr_send_delivery_failure_alert', array($this, 'send_delivery_failure_alert'), 10, 2);
        add_action('dfr_send_attestation_refusal_alert', array($this, 'send_attestation_refusal_alert'), 10, 1);
        add_action('dfr_fulfill_paid_order', array($this, 'fulfill_paid_order_job'), 10, 2);
        add_action('wp_ajax_nopriv_dfr_internal_fulfill', array($this, 'ajax_internal_fulfill'));
        add_action('wp_ajax_dfr_internal_fulfill', array($this, 'ajax_internal_fulfill'));
        // The manual-review hold marker belongs to the gateway's own On hold only: any
        // other status change removes it first, so an On hold the owner sets still blocks.
        add_action('woocommerce_order_status_changed', array($this, 'clear_gateway_hold_on_status_change'), 1, 4);
        // An admin marking an undelivered digital order Completed must not look like a
        // delivery. Runs before the app API's status push (priority 20).
        add_action('woocommerce_order_status_changed', array($this, 'guard_manual_completion'), 5, 4);
        // Website checkout: one unit per top-up line and the supplier min/max per line.
        add_action('woocommerce_check_cart_items', array($this, 'validate_cart_supplier_quantities'));

        // Supplier product mapping is administrator-only whatever the route (meta box,
        // WooCommerce REST meta_data, CSV import), and every change is audit-logged.
        add_filter('update_post_metadata', array($this, 'guard_mapping_meta_update'), 10, 5);
        add_filter('add_post_metadata', array($this, 'guard_mapping_meta_add'), 10, 5);
        add_filter('update_post_metadata_by_mid', array($this, 'guard_mapping_meta_update_by_mid'), 10, 4);

        // Builder V9 maintenance lock: supplier callbacks and paid-order loopbacks stay open.
        add_filter('delicat_builder_v9_maintenance_open_rest_routes', array($this, 'maintenance_open_rest_routes'), 10, 2);
        add_filter('delicat_builder_v9_maintenance_allowed_ajax', array($this, 'maintenance_allowed_ajax'), 10, 2);

        // Key ring configured: move salt-bound envelopes to it in the background.
        add_action('dfr_keyring_migration_batch', array($this, 'run_keyring_migration_batch'), 10, 2);
        add_action('admin_init', array($this, 'maybe_start_keyring_migration'), 30);

        // Delicat App API bridge: delivered codes, delivery state, labels and timeline
        // for the native app. Every value passes the same owner/status/refund gate as
        // the website reveal.
        add_filter('delicat_app_order_codes', array($this, 'app_order_codes'), 20, 3);
        add_filter('delicat_app_order_delivery', array($this, 'app_order_delivery'), 10, 3);
        add_filter('delicat_app_order_status_label', array($this, 'app_order_status_label'), 10, 2);
        add_filter('delicat_app_order_timeline', array($this, 'app_order_timeline'), 10, 2);

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
        // Page output only. DONOTCACHEOBJECT is deliberately not set: the object
        // cache holds data, not this response, and drop-ins that honour it would
        // run the whole account page without Redis. Decrypted codes never reach
        // the page or the object cache; they are sent only by the private
        // dfr_delica_reveal_codes AJAX response.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
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

    /**
     * Supplier credentials, live ordering, pricing, Catalog Studio and the reseller
     * programme move money. Since 4.15 they are administrator-only (owner decision,
     * September 2026); shop managers keep order handling. Every change is logged, see
     * record_admin_audit().
     */
    public function can_manage() {
        return current_user_can('manage_options');
    }

    /**
     * Append one entry to the money-settings change log. Values of secret-like keys
     * are redacted; only the newest 300 entries are kept.
     *
     * @param string $action  Machine name of the change.
     * @param array  $details Scalar details (setting names, old/new non-secret values).
     */
    public function record_admin_audit($action, array $details = array()) {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $entry = array(
            'at'      => gmdate('c'),
            'user_id' => get_current_user_id(),
            'user'    => ($user && !empty($user->user_login)) ? sanitize_user($user->user_login) : '',
            'action'  => substr(sanitize_key((string) $action), 0, 60),
            'details' => $this->redact_for_admin($details),
            'ip'      => isset($_SERVER['REMOTE_ADDR']) ? substr(preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']), 0, 45) : '',
        );
        $log = get_option('dfr_admin_audit_log_v1', array());
        $log = is_array($log) ? $log : array();
        array_unshift($log, $entry);
        update_option('dfr_admin_audit_log_v1', array_slice($log, 0, 300), false);
    }

    /** Last changes, shown to administrators on the FazerCards and Reseller API screens. */
    public function render_admin_audit_log() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!in_array($page, array('delicat-fazercards', 'delicat-reseller-api'), true) || !$this->can_manage()) { return; }
        // 4.15: encryption health. Stored codes are encrypted with a key derived
        // from the WordPress salts unless a key ring is configured.
        $health = DFR_Crypto::salt_health();
        if ($health === 'changed') {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Chiffrement : les clés WordPress ont changé.', 'delicat-fazercards') . '</strong> '
                . esc_html__('Les codes livrés et secrets fournisseur enregistrés avant ce changement ne peuvent plus être déchiffrés. Restaurez les anciennes « salts » de wp-config.php (ou l’ancienne adresse du site) pour les récupérer.', 'delicat-fazercards') . '</p></div>';
        } elseif ($health !== 'keyring') {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Chiffrement : aucun trousseau de clés dédié.', 'delicat-fazercards') . '</strong> '
                . esc_html__('Les codes sont chiffrés avec une clé dérivée des « salts » WordPress : ne les changez pas et ne changez pas l’adresse du site sans avoir d’abord ajouté DELICAT_DFR_KEYS dans wp-config.php (voir README).', 'delicat-fazercards') . '</p></div>';
        } else {
            // A ring alone does not make the store salt-independent: data written before it
            // stays salt-bound until the background migration has moved it.
            $remaining = $this->keyring_legacy_count();
            $migration = get_option('dfr_keyring_migration_v1', array());
            $migration_done = is_array($migration) && ($migration['status'] ?? '') === 'done';
            if ($remaining > 0 && !$migration_done) {
                echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Chiffrement : trousseau de clés actif, migration en cours.', 'delicat-fazercards') . '</strong> '
                    . esc_html(sprintf(_n('%d valeur chiffrée dépend encore des « salts » WordPress. Elle est migrée en arrière-plan : ne changez ni les salts ni l’adresse du site avant la fin.', '%d valeurs chiffrées dépendent encore des « salts » WordPress. Elles sont migrées en arrière-plan : ne changez ni les salts ni l’adresse du site avant la fin.', $remaining, 'delicat-fazercards'), $remaining)) . '</p></div>';
            } elseif ($remaining > 0) {
                echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Chiffrement : migration terminée avec des valeurs illisibles.', 'delicat-fazercards') . '</strong> '
                    . esc_html(sprintf(_n('%d valeur chiffrée n’a pas pu être relue avec les salts actuels et dépend encore d’eux : ne changez pas les salts avant de l’avoir vérifiée.', '%d valeurs chiffrées n’ont pas pu être relues avec les salts actuels et dépendent encore d’eux : ne changez pas les salts avant de les avoir vérifiées.', $remaining, 'delicat-fazercards'), $remaining)) . '</p></div>';
            } elseif (!method_exists('DFR_Crypto', 'keyring_hmac')) {
                echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Chiffrement : données migrées vers le trousseau de clés.', 'delicat-fazercards') . '</strong> '
                    . esc_html__('Les preuves de paiement des commandes en cours dépendent encore des « salts » WordPress : ne les changez pas tant que des livraisons sont en cours.', 'delicat-fazercards') . '</p></div>';
            }
        }
        $log = get_option('dfr_admin_audit_log_v1', array());
        $log = is_array($log) ? array_slice($log, 0, 20) : array();
        echo '<div class="notice notice-info"><details><summary style="cursor:pointer;padding:6px 0"><strong>'
            . esc_html__('Journal des modifications (paramètres sensibles)', 'delicat-fazercards')
            . '</strong> — ' . esc_html(sprintf(_n('%d entrée récente', '%d entrées récentes', count($log), 'delicat-fazercards'), count($log)))
            . '</summary>';
        if (!$log) {
            echo '<p>' . esc_html__('Aucune modification enregistrée depuis la mise à jour 4.15.', 'delicat-fazercards') . '</p>';
        } else {
            echo '<table class="widefat striped" style="margin:6px 0 10px"><thead><tr><th>' . esc_html__('Date (UTC)', 'delicat-fazercards') . '</th><th>' . esc_html__('Utilisateur', 'delicat-fazercards') . '</th><th>' . esc_html__('Action', 'delicat-fazercards') . '</th><th>' . esc_html__('Détails', 'delicat-fazercards') . '</th></tr></thead><tbody>';
            foreach ($log as $entry) {
                if (!is_array($entry)) { continue; }
                $details = isset($entry['details']) ? wp_json_encode($entry['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
                echo '<tr><td>' . esc_html((string) ($entry['at'] ?? '')) . '</td><td>' . esc_html((string) ($entry['user'] ?? '')) . ' (#' . absint($entry['user_id'] ?? 0) . ')</td><td><code>' . esc_html((string) ($entry['action'] ?? '')) . '</code></td><td style="word-break:break-word">' . esc_html(substr((string) $details, 0, 400)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</details></div>';
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
            'manage_options',
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
        if (in_array($action, array('configure_webhook', 'test_webhook', 'diagnose_player_id_server', 'test_connection', 'check_balance', 'preview_catalog'), true)) {
            $this->record_admin_audit('fazercards_' . $action);
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
            $new['catalog_sync_interval'] = in_array($interval, array('dfr_30_seconds', 'dfr_15_minutes', 'hourly', 'twicedaily', 'daily'), true) ? $interval : 'hourly';
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

        $previous = wp_parse_args($this->settings, self::defaults());
        $changes = array();
        foreach ($new as $setting_key => $setting_value) {
            $before = isset($previous[$setting_key]) ? (string) $previous[$setting_key] : '';
            if ((string) $setting_value === $before) { continue; }
            if (in_array($setting_key, array('api_key_enc', 'webhook_secret_enc'), true)) {
                // Never log a secret, not even encrypted.
                $changes[str_replace('_enc', '', $setting_key) . '_state'] = (string) $setting_value === '' ? 'cleared' : 'replaced';
            } else {
                $changes[$setting_key] = substr($before, 0, 40) . ' -> ' . substr((string) $setting_value, 0, 40);
            }
        }

        update_option('dfr_settings', $new, false);
        $this->settings = $new;
        if ($changes) {
            $this->record_admin_audit('fazercards_settings_' . $scope, $changes);
        }
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
        // The mapping decides what supplier balance a sale spends: administrators only.
        if (!class_exists('WooCommerce') || !$this->can_manage()) {
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
        if (!$this->can_manage() || !current_user_can('edit_post', $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        $type = isset($_POST['dfr_service_type']) ? sanitize_key(wp_unslash($_POST['dfr_service_type'])) : '';
        if (!in_array($type, array('', 'topup', 'giftcard', 'gamekey'), true)) {
            $type = '';
        }
        $category = isset($_POST['dfr_category_id']) ? $this->sanitize_mapping_id(wp_unslash($_POST['dfr_category_id'])) : '';
        $offer = isset($_POST['dfr_offer_id']) ? $this->sanitize_mapping_id(wp_unslash($_POST['dfr_offer_id'])) : '';
        $map = isset($_POST['dfr_field_map']) ? $this->sanitize_field_map_text(wp_unslash($_POST['dfr_field_map'])) : '';

        // Each write goes through guard_mapping_meta_write(), which records the change.
        update_post_meta($post_id, '_dfr_service_type', $type);
        update_post_meta($post_id, '_dfr_category_id', $category);
        update_post_meta($post_id, '_dfr_offer_id', $offer);
        update_post_meta($post_id, '_dfr_field_map', $map);
    }

    /** Post meta that decides which supplier product a sale buys. */
    private function mapping_meta_keys() {
        return array('_dfr_service_type', '_dfr_category_id', '_dfr_offer_id', '_dfr_field_map');
    }

    /** `update_post_metadata` filter. */
    public function guard_mapping_meta_update($check, $object_id, $meta_key, $meta_value = null, $prev_value = null) {
        return $this->guard_mapping_meta_write($check, $object_id, $meta_key, $meta_value);
    }

    /** `add_post_metadata` filter. */
    public function guard_mapping_meta_add($check, $object_id, $meta_key, $meta_value = null, $unique = false) {
        return $this->guard_mapping_meta_write($check, $object_id, $meta_key, $meta_value);
    }

    /** `update_post_metadata_by_mid` filter: WooCommerce product objects save meta this way. */
    public function guard_mapping_meta_update_by_mid($check, $meta_id, $meta_value = null, $meta_key = '') {
        if ($check !== null || !is_string($meta_key) || !in_array($meta_key, $this->mapping_meta_keys(), true) || !function_exists('get_metadata_by_mid')) {
            return $check;
        }
        $row = get_metadata_by_mid('post', absint($meta_id));
        if (!$row || !isset($row->post_id)) { return $check; }
        return $this->guard_mapping_meta_write($check, absint($row->post_id), $meta_key, $meta_value);
    }

    /**
     * Supplier product mapping is money-critical (owner decision, September 2026): a shop
     * manager remapping a cheap product to an expensive supplier card spends supplier
     * balance. Only administrators and background jobs (no signed-in user: catalog sync,
     * cron) may change it. Administrator changes are audit-logged, one entry per request.
     *
     * @return mixed Null to let WordPress write, false to refuse.
     */
    private function guard_mapping_meta_write($check, $object_id, $meta_key, $meta_value) {
        if ($check !== null || !is_string($meta_key) || !in_array($meta_key, $this->mapping_meta_keys(), true)) {
            return $check;
        }
        $user_id = get_current_user_id();
        if ($user_id < 1) { return $check; }
        $object_id = absint($object_id);
        $old = (string) get_post_meta($object_id, $meta_key, true);
        $new = is_scalar($meta_value) || $meta_value === null ? (string) $meta_value : (string) wp_json_encode($meta_value);
        if ($old === $new) { return $check; }
        if (!$this->can_manage()) {
            $this->log('warning', 'Refused a supplier mapping change by a user without administrator rights', array('product' => $object_id, 'meta' => $meta_key, 'user' => $user_id));
            return false;
        }
        $this->mapping_audit_total++;
        if (count($this->mapping_audit) < 12) {
            $this->mapping_audit[] = array(
                'product' => $object_id,
                'meta'    => $meta_key,
                'from'    => substr(sanitize_text_field($old), 0, 80),
                'to'      => substr(sanitize_text_field($new), 0, 80),
            );
        }
        if (!$this->mapping_audit_hooked) {
            $this->mapping_audit_hooked = true;
            add_action('shutdown', array($this, 'flush_mapping_audit'), 5);
        }
        return $check;
    }

    /** One audit entry for every mapping change of the request (Catalog Studio imports write many). */
    public function flush_mapping_audit() {
        if (!$this->mapping_audit) { return; }
        $changes = $this->mapping_audit;
        $total = $this->mapping_audit_total;
        $this->mapping_audit = array();
        $this->mapping_audit_total = 0;
        $products = array();
        foreach ($changes as $change) { $products[absint($change['product'])] = true; }
        $this->record_admin_audit('product_mapping', array(
            'changes'  => $total,
            'products' => implode(',', array_slice(array_keys($products), 0, 20)),
            'sample'   => $changes,
        ));
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
        if (hash_equals($this->wallet_attestation_signature($order, $wallet_tx_id, (int) $version), $stored_sig)) {
            $this->maybe_add_ring_attestation($order, $wallet_tx_id, (int) $version);
            return true;
        }
        // The salt signature stops matching after a WordPress salt change. A key-ring
        // signature added while the salts were still valid keeps the proof. The original
        // signature is never rewritten.
        $kid = preg_replace('/[^A-Za-z0-9]/', '', (string) $order->get_meta('_dfr_wallet_attestation_kid', true));
        $ring_sig = strtolower(trim((string) $order->get_meta('_dfr_wallet_attestation_sig3', true)));
        if ($kid === '' || !preg_match('/^[a-f0-9]{64}$/', $ring_sig)) { return false; }
        $ring = $this->wallet_attestation_ring_signature($order, $wallet_tx_id, (int) $version, $kid);
        return is_array($ring) && hash_equals($ring['mac'], $ring_sig);
    }

    /**
     * HMAC of the attestation payload under the key ring (DELICAT_DFR_KEYS), when the
     * crypto class offers it. Null without a ring.
     *
     * @return array{kid:string,mac:string}|null
     */
    private function wallet_attestation_ring_signature($order, $wallet_tx_id, $version, $kid = '') {
        if (!class_exists('DFR_Crypto') || !method_exists('DFR_Crypto', 'keyring_hmac')) { return null; }
        $result = DFR_Crypto::keyring_hmac($this->wallet_attestation_payload($order, $wallet_tx_id, (int) $version), 'wallet-attestation', (string) $kid);
        if (!is_array($result) || empty($result['kid']) || !isset($result['mac'])) { return null; }
        $mac = strtolower((string) $result['mac']);
        $result_kid = preg_replace('/[^A-Za-z0-9]/', '', (string) $result['kid']);
        if ($result_kid === '' || !preg_match('/^[a-f0-9]{64}$/', $mac)) { return null; }
        return array('kid' => $result_kid, 'mac' => $mac);
    }

    /** Add the key-ring signature once, only after the salt signature verified. */
    private function maybe_add_ring_attestation($order, $wallet_tx_id, $version) {
        if (!$order || trim((string) $order->get_meta('_dfr_wallet_attestation_sig3', true)) !== '') { return; }
        $ring = $this->wallet_attestation_ring_signature($order, $wallet_tx_id, (int) $version);
        if (!$ring) { return; }
        $order->update_meta_data('_dfr_wallet_attestation_kid', $ring['kid']);
        $order->update_meta_data('_dfr_wallet_attestation_sig3', $ring['mac']);
        $order->save_meta_data();
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
            if (trim((string) $order->get_meta('_dfr_wallet_attestation_sig', true)) === '') {
                $this->flag_attestation_refused($order, 'ledger_proof_failed');
            }
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
            $this->flag_attestation_refused($order, 'fulfillment_intent_unavailable');
            return;
        }

        $order->update_meta_data('_dfr_wallet_attestation_v', '2');
        $order->update_meta_data('_dfr_wallet_attestation_tx', (string) $wallet_tx_id);
        $order->update_meta_data('_dfr_wallet_attestation_sig', $this->wallet_attestation_signature($order, $wallet_tx_id, 2));
        $order->update_meta_data('_dfr_wallet_attestation_at', gmdate('c'));
        // Second signature under the key ring, so a later salt change keeps the proof.
        $ring = $this->wallet_attestation_ring_signature($order, $wallet_tx_id, 2);
        if ($ring) {
            $order->update_meta_data('_dfr_wallet_attestation_kid', $ring['kid']);
            $order->update_meta_data('_dfr_wallet_attestation_sig3', $ring['mac']);
        }
        // A later wallet event proved what an earlier one could not.
        $order->delete_meta_data('_dfr_attestation_refused');
        $order->delete_meta_data('_dfr_attestation_refused_reason');
        $order->save_meta_data();

        // v4.15: the attestation is the moment fulfilment becomes allowed, so start it
        // here. Before, nothing started it except the website thank-you page or the app's
        // inline call: a customer whose connection dropped after paying left a paid order
        // that was never sent to the supplier and stayed "En cours" forever.
        $this->queue_fulfillment($order->get_id(), 'attestation');
    }

    /**
     * Return true only when WooCommerce has accepted a full TeraWallet payment,
     * its ledger contains the matching debit, and (for new v4.8+ orders) Delicat's
     * immutable payment-event attestation still matches the exact order facts.
     */
    private function order_has_verified_wallet_payment($order) {
        if (!$order || !is_object($order)) { return false; }
        if ((string) $order->get_payment_method('edit') !== 'wallet') { return false; }
        // is_paid() covers Processing and Completed only. The gateway's own manual-review
        // hold (one line failed for good) keeps the other paid lines moving; an On hold set
        // by the owner or another plugin carries no marker and still blocks. Ledger,
        // attestation and digest checks below never depended on the status.
        $status_ok = $order->is_paid() || $this->order_on_gateway_hold($order);
        if (!$status_ok || !$order->get_date_paid('edit')) { return false; }

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
     * Whether the current cart can only be paid with the wallet: it holds a product this
     * gateway delivers (restrict_digital_checkout_to_wallet() then offers TeraWallet only).
     * Public for the Delicat App API checkout.
     *
     * @return bool
     */
    public function cart_requires_wallet() {
        return $this->cart_contains_dfr_digital_item();
    }

    /** On hold by this gateway's own manual-review hold (see mark_item_delivery_failed()). */
    private function order_on_gateway_hold($order) {
        return $order && is_object($order) && $order->has_status('on-hold')
            && (string) $order->get_meta('_dfr_gateway_hold', true) !== '';
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
            '_dfr_delivery_enc',
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
        if (!$lock) {
            // Another worker holds the order. It may finish without reaching these lines,
            // so leave a durable follow-up instead of returning silently.
            $this->schedule_fulfillment_job($order_id, 15);
            return;
        }
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

    /**
     * @param WC_Order      $order      Order (callers hold its lock).
     * @param int           $item_id    Line id.
     * @param WC_Order_Item $item       Line.
     * @param bool          $from_retry True for the scheduled retry of this line.
     */
    private function fulfill_item($order, $item_id, $item, $from_retry = false) {
        if (!$order || !$item) { return; }
        if (class_exists('Delicat_Custom_Packages', false) && Delicat_Custom_Packages::recipe($item->get_variation_id())) {
            $this->mark_item_delivery_failed($order, $item_id, 'custom_package_checkout_unsupported');
            return;
        }
        // Both callers hold the order lock, but the line may come from an order loaded
        // before it, and WooCommerce caches line meta for the whole request. Another runner
        // (post-response pass, loopback, thank-you page) may have sent this line in the
        // meantime: read its meta from the database before deciding anything.
        if (is_callable(array($item, 'read_meta_data'))) { $item->read_meta_data(true); }
        if ($item->get_meta('_dfr_remote_order_id', true)) { return; }
        // A line already marked failed waits for the owner, and a line the team resolved by
        // hand (delivered or refunded) is closed. Status hooks, thank-you page reloads and
        // the watchdog must never re-submit it on their own.
        if (in_array((string) $item->get_meta('_dfr_delivery_state', true), array('failed', 'manual', 'refunded'), true)) { return; }
        // An order the team marked Completed by hand is closed: its remaining lines are never
        // bought on our own (they may have been delivered by hand). Moving the order back to
        // Processing sends them.
        if ($order->has_status('completed') && (string) $order->get_meta('_dfr_manual_completion_by', true) !== '') { return; }
        // After a failed POST a retry of this exact line is queued (3, 6, 10, 20, 35 s). A
        // status hook, the loopback or the watchdog must not race it; the retry itself runs.
        if (!$from_retry) {
            $queued_attempt = max(0, (int) $item->get_meta('_dfr_fulfill_attempt', true));
            $last_submit = strtotime((string) $item->get_meta('_dfr_timing_supplier_submit_last', true));
            if ($queued_attempt > 0 && $last_submit !== false && $last_submit > time() - 60
                && $this->has_pending_item_retry($order->get_id(), $item_id, $queued_attempt)) {
                return;
            }
        }
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
                $this->mark_item_delivery_failed($order, $item_id, 'integrity_check_failed');
                return;
            }
        }

        if (!$snapshot) {
            if ((string) $item->get_meta('_dfr_checkout_snapshot_failed', true) === '1') {
                $order->add_order_note(sprintf(__('FazerCards: secure checkout snapshot for item %d was unavailable. Supplier order was not sent.', 'delicat-fazercards'), $item_id));
                $this->log('error', 'Checkout fulfillment snapshot unavailable', array('order_id'=>$order->get_id(), 'item_id'=>$item_id));
                $this->mark_item_delivery_failed($order, $item_id, 'checkout_snapshot_unavailable');
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
                    $this->mark_item_delivery_failed($order, $item_id, 'integrity_check_failed');
                    return;
                }
                $checkout_context = 'checkout-snapshot|line:' . $line_context_id . '|product:' . $product_id . '|variation:' . $variation_id;
                $checkout_json = DFR_Crypto::decrypt_context($checkout_enc, $checkout_context);
                $checkout = json_decode((string) $checkout_json, true);
                if (!is_array($checkout) || (int) ($checkout['v'] ?? 0) !== 1) {
                    $order->add_order_note(sprintf(__('FazerCards: checkout mapping snapshot for item %d failed integrity validation. Supplier order was not sent.', 'delicat-fazercards'), $item_id));
                    $this->log('error', 'Checkout snapshot integrity failure', array('order_id'=>$order->get_id(), 'item_id'=>$item_id));
                    $this->mark_item_delivery_failed($order, $item_id, 'integrity_check_failed');
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
                    $this->mark_item_delivery_failed($order, $item_id, 'product_mapping_invalid');
                    return;
                }
                $enc = DFR_Crypto::encrypt_context(wp_json_encode($candidate), $this->fulfillment_context($order->get_id(), $item_id));
                if (is_wp_error($enc)) {
                    $order->add_order_note(__('FazerCards: checkout mapping could not be secured for fulfillment; supplier order was not sent.', 'delicat-fazercards'));
                    $this->mark_item_delivery_failed($order, $item_id, 'integrity_check_failed');
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
                $this->mark_item_delivery_failed($order, $item_id, 'uncertain_previous_attempt');
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
                $this->mark_item_delivery_failed($order, $item_id, 'product_mapping_missing');
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
                        $this->mark_item_delivery_failed($order, $item_id, 'required_field_missing');
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
                $this->mark_item_delivery_failed($order, $item_id, 'integrity_check_failed');
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

        // A top-up is delivered once whatever the quantity (create_topup has none), so a
        // line with quantity > 1 would be charged N times and delivered once. Hold it for
        // the owner before any supplier call (owner policy: manual review, no refund).
        if ($type === 'topup' && $quantity > 1) {
            $order->add_order_note(sprintf(__('FazerCards: item %1$d is a top-up with quantity %2$d. A top-up is delivered one unit per line, so nothing was sent to the supplier.', 'delicat-fazercards'), $item_id, $quantity));
            $this->mark_item_delivery_failed($order, $item_id, 'topup_quantity');
            return;
        }

        $attempt = max(0, (int) $item->get_meta('_dfr_fulfill_attempt', true)) + 1;
        $item->update_meta_data('_dfr_fulfill_attempt', $attempt);
        $item->save();
        // Player ID checks the supplier could not answer (rate limit, timeout, 5xx) retry
        // without using up the six attempts, so they are left out of this count.
        $counted_attempt = max(1, $attempt - absint($item->get_meta('_dfr_uid_check_transient_count', true)));

        $idempotency = 'wc-' . $order->get_id() . '-item-' . $item_id . '-v1';
        $api = $this->api();

        // For Player-ID products verified at checkout, revalidate server-to-server
        // immediately before spending supplier balance. This protects against stale or
        // tampered checkout state without exposing the FazerCards key to the browser.
        $validation_category = $type === 'topup' ? (string) ($snapshot['validation_category'] ?? '') : '';
        if ($type === 'topup' && $validation_category !== '' && ($validation_fields || $fields)) {
            $revalidation_fields = $validation_fields ?: $fields; // legacy snapshots used identical keys.
            // Background fulfillment is not bound by the storefront budget; allow the
            // supplier's slower publisher-side checks to complete before spending balance.
            $validation = $api->validate_topup_id($validation_category, $revalidation_fields, 20);
            if (is_wp_error($validation)) {
                $data = $validation->get_error_data();
                $http = is_array($data) ? absint($data['status'] ?? 0) : 0;
                $retry_after = is_array($data) ? absint($data['retry_after'] ?? 0) : 0;
                $terminal = ($http >= 400 && $http < 500 && !$this->supplier_http_is_retryable($http));
                if ($terminal) {
                    if ($this->line_has_fresh_remote_order($order, $item_id)) { return; }
                    $order->add_order_note(sprintf(__('Player ID revalidation returned a non-retryable error for item %d. No upstream balance was spent; review the mapping/UID before retrying manually.', 'delicat-fazercards'), $item_id));
                    $this->mark_item_delivery_failed($order, $item_id, 'player_id_check_rejected');
                    return;
                }
                // A drained supplier validation quota or an outage must not put paid top-ups
                // On hold within a minute: retry with backoff, bounded by wall-clock time.
                $since = strtotime((string) $item->get_meta('_dfr_uid_check_transient_since', true));
                if ($since === false || $since <= 0) {
                    $since = time();
                    $item->update_meta_data('_dfr_uid_check_transient_since', gmdate('c', $since));
                }
                $transient_count = absint($item->get_meta('_dfr_uid_check_transient_count', true)) + 1;
                $item->update_meta_data('_dfr_uid_check_transient_count', $transient_count);
                $item->save();
                if (time() - $since < 45 * MINUTE_IN_SECONDS) {
                    if ($transient_count === 1) {
                        $order->add_order_note(sprintf(__('Player ID revalidation could not be completed for item %d; upstream order was not sent yet.', 'delicat-fazercards'), $item_id));
                    }
                    $base = ($http === 429 && $retry_after > 0) ? max(1, min(3600, $retry_after)) : $this->uid_check_retry_delay($transient_count);
                    $this->mark_item_delay_reason($item, $http === 429 ? 'supplier_rate_limit' : 'uid_revalidation_transient');
                    $this->schedule_order_item_retry($order->get_id(), $item_id, $base);
                    return;
                }
                if ($this->line_has_fresh_remote_order($order, $item_id)) { return; }
                $order->add_order_note(sprintf(__('Player ID revalidation stayed unavailable for 45 minutes for item %d. No upstream balance was spent; the line is held for review.', 'delicat-fazercards'), $item_id));
                $this->mark_item_delivery_failed($order, $item_id, 'player_id_check_unavailable');
                return;
            }
            if (empty($validation['ok']) || empty($validation['valid'])) {
                if ($this->line_has_fresh_remote_order($order, $item_id)) { return; }
                $item->update_meta_data('_dfr_uid_revalidation_failed', gmdate('c'));
                $item->save();
                $order->add_order_note(sprintf(__('Player ID revalidation failed for item %d. No upstream balance was spent; review the customer UID before retrying manually.', 'delicat-fazercards'), $item_id));
                $order->save();
                $this->mark_item_delivery_failed($order, $item_id, 'player_id_invalid');
                return;
            }
            $item->delete_meta_data('_dfr_uid_revalidation_failed');
            $item->delete_meta_data('_dfr_uid_check_transient_since');
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
            $will_retry = !$terminal && $counted_attempt < 6;
            // A replay refused because another runner already created this supplier order
            // is not a failure: the line is delivered through that order.
            if (!$will_retry && $this->line_has_fresh_remote_order($order, $item_id)) {
                $this->log('warning', 'Supplier refused a replay of a line another worker already sent; line left as is', array('order_id' => $order->get_id(), 'item_id' => $item_id, 'http' => $http));
                return;
            }
            $order->add_order_note(sprintf(__('FazerCards API error for item %1$d: %2$s', 'delicat-fazercards'), $item_id, $result->get_error_message()));
            if ($will_retry) {
                $base = ($http === 429 && $retry_after > 0)
                    ? max(1, min(3600, $retry_after))
                    : $this->adaptive_retry_delay($counted_attempt, 'create');
                $this->mark_item_delay_reason($item, $http === 429 ? 'supplier_rate_limit' : ($http >= 500 || $http === 0 ? 'supplier_create_transport' : 'supplier_create_retryable'));
                $this->schedule_order_item_retry($order->get_id(), $item_id, $base);
            } else {
                // Terminal rejection, or six uncertain attempts. The Idempotency-Key stays
                // bound to this line, so the owner reviews it instead of a blind resend.
                $this->mark_item_delivery_failed($order, $item_id, $terminal ? 'supplier_rejected' : 'supplier_unreachable');
            }
            return;
        }

        $remote = isset($result['order']['id']) ? sanitize_text_field($result['order']['id']) : (isset($result['order_id']) ? sanitize_text_field($result['order_id']) : '');
        $status = $this->normalize_supplier_status(isset($result['order']['status']) ? $result['order']['status'] : (isset($result['status']) ? $result['status'] : 'processing'));
        if (!preg_match('/^ord-[0-9]+$/', $remote)) {
            if ($counted_attempt >= 6 && $this->line_has_fresh_remote_order($order, $item_id)) { return; }
            $order->add_order_note(__('FazerCards returned an unexpected order ID; the item will be retried safely with the same idempotency key.', 'delicat-fazercards'));
            if ($counted_attempt < 6) {
                $this->mark_item_delay_reason($item, 'supplier_create_unexpected_id');
                $this->schedule_order_item_retry($order->get_id(), $item_id, $this->adaptive_retry_delay($counted_attempt, 'create'));
            } else {
                $this->mark_item_delivery_failed($order, $item_id, 'supplier_unexpected_response');
            }
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
        // The token is ignored by the two-argument callback but makes each successor's
        // arguments differ from the currently running attempt. schedule_action() dedupes
        // on hook + ARGS, so the running attempt can no longer suppress its successor.
        $args = array($order_id, $item_id, $attempt_token);
        $this->schedule_action('dfr_retry_order_item', $args, time() + $this->jitter_delay(max(1, (int) $delay)));
    }

    public function retry_order_item($order_id, $item_id) {
        if (!function_exists('wc_get_order')) { return; }
        $order = wc_get_order(absint($order_id));
        if (!$order) { return; }
        if (!$this->order_has_verified_wallet_payment($order)) {
            // The proof can be briefly unreadable (ledger replica lag, object cache).
            // Try again a few times rather than ending the item's retry chain for good.
            $this->defer_order_item_retry($order, $item_id);
            return;
        }
        $lock = $this->acquire_order_lock($order->get_id(), 1);
        if (!$lock) {
            $this->defer_order_item_retry($order, $item_id);
            return;
        }
        try {
            $item = $order->get_item(absint($item_id));
            if ($item && !$item->get_meta('_dfr_remote_order_id', true)) {
                $this->fulfill_item($order, $item_id, $item, true);
            }
        } finally {
            $this->release_order_lock($lock);
        }
    }

    /**
     * True when this line has a supplier order id in the database, whatever the caller's
     * copy says. Checked before marking a line failed after a supplier attempt: another
     * runner may have created the order while this one's replay was refused. Not used by
     * apply_remote_payload(), which must still mark lines the supplier failed or refunded.
     */
    private function line_has_fresh_remote_order($order, $item_id) {
        if (!$order || !is_object($order)) { return false; }
        $fresh = $order->get_item(absint($item_id));
        if (!$fresh) { return false; }
        if (is_callable(array($fresh, 'read_meta_data'))) { $fresh->read_meta_data(true); }
        return trim((string) $fresh->get_meta('_dfr_remote_order_id', true)) !== '';
    }

    /** Backoff for Player ID checks the supplier could not answer (rate limit, timeout, 5xx). */
    private function uid_check_retry_delay($transient_count) {
        $transient_count = max(1, (int) $transient_count);
        if ($transient_count <= 6) {
            return $this->adaptive_retry_delay($transient_count, 'validation');
        }
        return min(300, 60 * ($transient_count - 5));
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
            // v4.15: with unique=true Action Scheduler compared only hook + group, so the
            // first checkpoint of ANY order blocked the other 24 checkpoints of every plan.
            if ($this->schedule_action('dfr_reconcile_plan_tick', $args, $started + (int) $offset)) { $scheduled++; }
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
        $round = max(1, min(self::LOOPBACK_ROUNDS, (int) $round));
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

        $scheduled = $this->schedule_action('dfr_fast_reconcile_remote_order', $args, 0);
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
        $round = max(1, min(self::LOOPBACK_ROUNDS, (int) $round));
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

        $response = $this->fire_loopback(array(
            'action' => 'dfr_internal_reconcile',
            'token'  => $token,
        ), array($claim_key, $debounce));
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

    /**
     * POST a single-use internal loopback to admin-ajax.php.
     *
     * v4.23 used a 0.5 s non-blocking request. WordPress reports no error for a
     * non-blocking call, and LiteSpeed (Hostinger) aborts a PHP request whose
     * client has already hung up, so whenever DNS/TLS/WordPress bootstrap of the
     * worker took longer than 0.5 s the fast lane died silently and the order
     * waited for WP-Cron/Action Scheduler (one to several minutes on a cached site).
     *
     * Now: (1) inside a user-facing request that can flush its response, the call
     * is deferred until after that flush, so the customer never waits for it;
     * (2) the worker answers as soon as it has consumed its claim
     * (release_loopback_caller()), so the longer timeout only bounds connect/TLS.
     *
     * @param array $body    POST body (action + token only).
     * @param array $cleanup Transient keys to delete if a deferred call cannot be sent.
     * @return true|WP_Error
     */
    private function fire_loopback(array $body, array $cleanup = array()) {
        if (!$this->response_released && !wp_doing_cron() && !$this->internal_reconcile_active && $this->can_finish_response()) {
            $this->deferred_loopbacks[] = array($body, $cleanup);
            return true;
        }
        $patient = $this->response_released || wp_doing_cron() || $this->internal_reconcile_active;
        $response = wp_safe_remote_post(admin_url('admin-ajax.php'), array(
            'timeout'            => $patient ? 4 : 1.5,
            'blocking'           => false,
            'redirection'        => 0,
            'sslverify'          => true,
            'reject_unsafe_urls' => true,
            'headers'            => array('Cache-Control' => 'no-store'),
            'body'               => $body,
        ));
        return is_wp_error($response) ? $response : true;
    }

    private function can_finish_response() {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    /** Flush this request's response and keep the PHP worker (PHP-FPM or LiteSpeed). */
    private function finish_response() {
        if ($this->response_released) { return true; }
        $finish = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request'
            : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : '');
        if ($finish === '') { return false; }
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) { @set_time_limit(120); }
        while (ob_get_level() > 0) {
            if (!@ob_end_flush()) { break; }
        }
        @call_user_func($finish);
        $this->response_released = true;
        return true;
    }

    /** Answer an internal loopback caller before any supplier I/O (see fire_loopback()). */
    private function release_loopback_caller() {
        if (!$this->can_finish_response()) { return false; }
        if (!headers_sent()) {
            status_header(202);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo '{"success":true,"data":{"accepted":true}}';
        return $this->finish_response();
    }

    /** Fire loopbacks that waited for this request's response to be flushed. */
    private function flush_deferred_loopbacks() {
        $pending = $this->deferred_loopbacks;
        $this->deferred_loopbacks = array();
        foreach ($pending as $entry) {
            [$body, $cleanup] = $entry;
            $sent = $this->fire_loopback($body, $cleanup);
            if (is_wp_error($sent)) {
                foreach ((array) $cleanup as $key) { delete_transient((string) $key); }
                $this->log('warning', 'Deferred loopback could not be dispatched; durable fallback remains queued', array('error' => $sent->get_error_code()));
            }
        }
    }

    /** Drop this worker's cached copy of an order another PHP worker may have written. */
    private function forget_cached_order($order_id, $item_id = 0) {
        $order_id = absint($order_id);
        if (!$order_id) { return; }
        if (function_exists('clean_post_cache')) { clean_post_cache($order_id); }
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($order_id, 'orders');
            wp_cache_delete('order-items-' . $order_id, 'orders');
            if ($item_id) { wp_cache_delete(absint($item_id), 'order_item_meta'); }
        }
        if (function_exists('wc_get_container') && class_exists('\Automattic\WooCommerce\Caches\OrderCache')) {
            try {
                wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($order_id);
            } catch (Throwable $e) {
                // Optional cache layer.
            }
        }
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
        $round = max(1, min(self::LOOPBACK_ROUNDS, (int) ($claim['round'] ?? 1)));
        if (!$issued || $issued < time() - 90 || !$order_id || !$item_id || !preg_match('/^ord-[0-9]+$/', $remote)) {
            wp_send_json_error(array('code' => 'invalid_claim'), 403);
        }

        // Let the caller hang up now: on LiteSpeed a caller that disconnects first
        // gets this worker aborted (see fire_loopback()).
        $this->release_loopback_caller();
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
            6 => array(8000000),
            7 => array(10000000),
            8 => array(12000000),
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
            if (!$hard_stop && $round < self::LOOPBACK_ROUNDS) {
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
        // Long-lived workers (post-response, loopback rounds) must see what another
        // worker or the webhook wrote since this request first loaded the order.
        $this->forget_cached_order($order_id, $item_id);
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
        if ((!$this->post_response_reconcile && !$this->post_response_fulfill && !$this->deferred_loopbacks && !$this->post_response_webhooks) || $this->internal_reconcile_active || wp_doing_cron()) { return; }
        // Hostinger serves PHP through LiteSpeed (LSAPI), which provides
        // litespeed_finish_request() instead of PHP-FPM's fastcgi_finish_request().
        if (!$this->finish_response()) { return; }

        // Detached workers first, so they run in parallel with the work below.
        $this->flush_deferred_loopbacks();

        // Queued supplier passes first: they create the supplier orders the reconcile
        // candidates below would otherwise wait for.
        $fulfil = array_slice(array_keys($this->post_response_fulfill), 0, 3);
        $this->post_response_fulfill = array();
        foreach ($fulfil as $fulfil_order_id) {
            $this->maybe_auto_fulfill_order(absint($fulfil_order_id));
        }
        // A pass above may have queued loopbacks of its own.
        $this->flush_deferred_loopbacks();

        $deadline = microtime(true) + 25;

        // Signed webhooks whose immediate settlement lost a race (supplier read
        // cooldown, order lock, supplier read-after-write lag). Re-run the same
        // canonical settlement here instead of waiting for the cron-driven queue.
        $webhooks = $this->post_response_webhooks;
        $this->post_response_webhooks = array();
        foreach ($webhooks as $hook_args) {
            [$remote, $status, $event_id] = $hook_args;
            foreach (array(4200000, 5000000, 6000000, 8000000) as $pause) {
                if (microtime(true) + ($pause / 1000000) > $deadline) { break; }
                usleep($pause);
                if ($this->process_verified_webhook($remote, $status, $event_id, 0, true)) {
                    if (function_exists('as_unschedule_action') && did_action('action_scheduler_init')) {
                        as_unschedule_action('dfr_process_verified_webhook', array($remote, $status, $event_id), 'delicat-fazercards');
                    }
                    break;
                }
            }
        }

        // Paced to the 4-second per-order supplier read slot (claim_canonical_poll_slot()):
        // v4.23 retried after 0.35/0.7/1.2 s, inside that cooldown, so only the first of
        // its three reads could ever reach the supplier.
        $candidates = array_slice(array_values($this->post_response_reconcile), 0, 2);
        $this->post_response_reconcile = array();
        foreach ($candidates as $candidate) {
            [$order_id, $item_id, $remote] = $candidate;
            if (function_exists('wc_get_order')) {
                $o = wc_get_order($order_id);
                $i = $o ? $o->get_item($item_id) : false;
                if ($i) { $this->mark_item_timing($i, 'postresponse_started'); }
            }
            foreach (array(350000, 4200000, 4500000, 5000000, 6000000) as $pause) {
                if (microtime(true) + ($pause / 1000000) > $deadline) { break; }
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
        // v4.24: the Action Scheduler lookup is a query on its actions table. It ran on every
        // storefront page view; it now runs where the queue itself runs (WP-Cron, admin and
        // admin-ajax, which includes Action Scheduler's async runner), every minute or so on
        // a live store. The WP-Cron entry below is an in-memory check and stays everywhere.
        $check_queue = wp_doing_cron() || is_admin() || (string) get_option('dfr_reconcile_watchdog_version', '') !== DFR_VERSION;
        if ($check_queue && function_exists('as_schedule_recurring_action') && did_action('action_scheduler_init')) {
            $has_as = function_exists('as_has_scheduled_action')
                ? (bool) as_has_scheduled_action('dfr_reconcile_watchdog', $as_args, 'delicat-fazercards')
                : false;
            if (!$has_as) {
                as_schedule_recurring_action(time() + 15, MINUTE_IN_SECONDS, 'dfr_reconcile_watchdog', $as_args, 'delicat-fazercards', true, self::action_priority('dfr_reconcile_watchdog'));
            }
        }
        $wp_args = array('wp_cron');
        if (!wp_next_scheduled('dfr_reconcile_watchdog', $wp_args)) {
            wp_schedule_event(time() + 30, 'dfr_every_minute', 'dfr_reconcile_watchdog', $wp_args);
        }
        if ((string) get_option('dfr_reconcile_watchdog_version', '') !== DFR_VERSION) {
            update_option('dfr_reconcile_watchdog_version', DFR_VERSION, false);
        }
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
        $kicked = 0;
        try {
            // v4.15: open orders of the last 7 days only. The old window was the 40 newest
            // orders INCLUDING completed ones, so on a busy day a stuck order fell out of
            // it within hours.
            $orders = wc_get_orders(array(
                'status'       => array('processing','on-hold'),
                'date_created' => '>' . (time() - 7 * DAY_IN_SECONDS),
                'limit'        => 60,
                'orderby'      => 'date',
                'order'        => 'DESC',
                'return'       => 'objects',
            ));
            foreach ((array) $orders as $order) {
                if (!$order || !$this->order_has_verified_wallet_payment($order)) { continue; }
                // Paid lines that never reached the supplier: every other recovery path
                // needs a supplier order id, so without this sweep they wait forever.
                if ($kicked < 2 && $this->order_has_unsent_paid_items($order)) {
                    $this->queue_fulfillment($order->get_id(), 'watchdog');
                    $kicked++;
                }
                foreach ($order->get_items('line_item') as $item_id => $item) {
                    $remote = trim((string) $item->get_meta('_dfr_remote_order_id', true));
                    $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
                    if (!preg_match('/^ord-[0-9]+$/', $remote) || in_array($status, array('failed','refunded'), true)) { continue; }
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
            // An order Completed by hand can still have a gift card or game key whose code
            // never arrived; after the ten-minute plan only website page views fetched it,
            // so an app-only customer never got it (4.14 scanned completed orders too).
            // GET-only, at most one read per line every ten minutes.
            if ($checked < 3) {
                $completed = wc_get_orders(array(
                    'status'       => array('completed'),
                    'date_created' => '>' . (time() - 7 * DAY_IN_SECONDS),
                    'limit'        => 40,
                    'orderby'      => 'date',
                    'order'        => 'DESC',
                    'return'       => 'objects',
                ));
                foreach ((array) $completed as $order) {
                    if (!$order || !$this->order_has_gateway_items($order)) { continue; }
                    foreach ($order->get_items('line_item') as $item_id => $item) {
                        if (!$this->item_requires_delivery_code($item)) { continue; }
                        if (in_array((string) $item->get_meta('_dfr_delivery_state', true), array('failed', 'manual', 'refunded'), true)) { continue; }
                        $remote = trim((string) $item->get_meta('_dfr_remote_order_id', true));
                        $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
                        if (!preg_match('/^ord-[0-9]+$/', $remote) || in_array($status, array('failed','refunded'), true)) { continue; }
                        if ($this->item_has_delivery_codes($item)) { continue; }
                        $last = max(
                            (int) strtotime((string) $item->get_meta('_dfr_last_remote_check', true)),
                            (int) strtotime((string) $item->get_meta('_dfr_timing_watchdog_poll', true))
                        );
                        if ($last > time() - 10 * MINUTE_IN_SECONDS) { continue; }
                        if (!$this->order_has_verified_wallet_payment($order)) { continue 2; }
                        $candidates++;
                        if ($this->reconcile_remote_once($order->get_id(), $item_id, $remote, 5, 'watchdog') === 'busy') { continue; }
                        $checked++;
                        if ($checked >= 3) { break 2; }
                    }
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
                'kicked'     => $kicked,
                'finish'     => function_exists('fastcgi_finish_request') ? 'fastcgi' : (function_exists('litespeed_finish_request') ? 'litespeed' : 'none'),
            ), false);
        } finally {
            delete_transient($lease);
        }
    }

    /**
     * Upgrade self-heal: dispatch recent Processing/On-hold remote items through the
     * cron-independent loopback path. It never POSTs a new supplier order.
     */
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
        $round = max(1, min(self::LOOPBACK_ROUNDS, (int) $round));
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
            if (!in_array($event_name, array('order.status_changed', 'order.created'), true)) {
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
        // v4.24: also retry in this worker right after the response is flushed (see
        // run_post_response_reconcile()); the queued job is cancelled if that succeeds.
        // Only for supplier orders bound to a local WooCommerce line: reseller API
        // mirror orders have their own bridge and must not spend the shared read budget.
        if ($this->can_finish_response() && count($this->post_response_webhooks) < 3 && $this->get_remote_map($remote)) {
            $this->post_response_webhooks[] = $args;
        }
        $scheduled = $this->schedule_action('dfr_process_verified_webhook', $args, 0);
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
        return $this->schedule_action('dfr_process_verified_webhook', $args, $when);
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
        if (!$map || !function_exists('wc_get_order')) {
            if (!$fast) { $this->schedule_verified_webhook_retry($remote, $signed_status, $event_id, $attempt + 1); }
            return false;
        }
        $order = wc_get_order(absint($map['order_id']));
        if (!$order) {
            if (!$fast) { $this->schedule_verified_webhook_retry($remote, $signed_status, $event_id, $attempt + 1); }
            return false;
        }
        $webhook_item = $order->get_item(absint($map['item_id']));
        if ($webhook_item && !(string) $webhook_item->get_meta('_dfr_timing_webhook_canonical', true)) {
            $this->mark_item_timing($webhook_item, 'webhook_canonical');
        }

        $lock = $this->acquire_order_lock($order->get_id(), 1);
        if (!$lock) {
            if (!$fast) { $this->schedule_verified_webhook_retry($remote, $signed_status, $event_id, $attempt + 1); }
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
            // A signed webhook can arrive after a wallet reversal. Record the supplier
            // status, but store or announce codes only while the payment proof holds.
            $proof_ok = $this->order_has_verified_wallet_payment($order);
            if (!$proof_ok) { $this->set_completion_block_reason($order, 'wallet_payment_proof_failed'); }
            $this->apply_remote_payload($order, absint($map['item_id']), $payload, $status, $proof_ok);
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
        if (in_array($signed_status, array('completed', 'failed', 'refunded'), true) && $canonical_status === 'processing' && !$fast) {
            $this->schedule_verified_webhook_retry($remote, $signed_status, $event_id, $attempt + 1);
        }
        return !(in_array($signed_status, array('completed', 'failed', 'refunded'), true) && $canonical_status === 'processing');
    }

    private function apply_remote_payload($order, $item_id, array $payload, $forced_status = '', $allow_codes = true) {
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

        // The line's own service type decides whether a lone root `code` is a delivery,
        // not only the supplier's `kind` field.
        $delivery = $allow_codes
            ? $this->extract_delivery($remote_order, $this->item_service_type($item))
            : array('codes' => array(), 'cards' => array());
        $codes = $delivery['codes'];
        if ($codes) {
            $context = $this->code_crypto_context($order->get_id(), $item_id);
            $enc = DFR_Crypto::encrypt_context(wp_json_encode($codes), $context);
            if (!is_wp_error($enc)) {
                $item->update_meta_data('_dfr_codes_enc', $enc);
                $item->update_meta_data('_dfr_codes_crypto', DFR_Crypto::has_keyring() ? 'aead-v3' : 'aead-v2');
                $item->update_meta_data('_dfr_codes_count', count($codes));
                // Field names per card (« Carte 1 — PIN »), next to the flat list.
                $this->store_delivery_structure($order->get_id(), $item_id, $item, $delivery['cards']);
                // Every caller verified the wallet proof before passing $allow_codes.
                // Remember it, so showing the code later does not depend only on a
                // live proof that a salt rotation could break.
                if (!$item->get_meta('_dfr_delivery_authorized_at', true)) { $item->update_meta_data('_dfr_delivery_authorized_at', gmdate('c')); }
                if (!$item->get_meta('_dfr_delivery_ready_at', true)) { $item->update_meta_data('_dfr_delivery_ready_at', gmdate('c')); }
                $item->update_meta_data('_dfr_timing_delivery_ready', gmdate('c'));
                $item->delete_meta_data('_dfr_delivery_waiting_since');
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
        if (in_array($status, array('failed', 'refunded'), true)) {
            // Idempotent: puts the order On hold for the owner and tells the customer once.
            $this->mark_item_delivery_failed($order, $item_id, $status === 'refunded' ? 'supplier_refunded' : 'supplier_failed');
        }
        if ($status === 'completed') {
            $this->queue_completion_candidate($order->get_id());
        }
        $this->maybe_complete_woo_order($order);
    }

    private function extract_codes(array $data, $service_type = '') {
        return $this->extract_delivery($data, $service_type)['codes'];
    }

    /**
     * Delivered codes of a supplier order payload, for other gateway classes (Reseller
     * API) so every reader applies the same typed rules.
     *
     * @param array  $data         Supplier order object (GET /orders/{id} `order`).
     * @param string $service_type Line type: topup, giftcard or gamekey ('' when unknown).
     * @return string[] Distinct codes, at most 100.
     */
    public function extract_delivery_codes(array $data, $service_type = '') {
        return $this->extract_codes($data, $service_type);
    }

    /**
     * Codes of a supplier payload: a flat list (distinct values, as stored since 3.x) and
     * the same values per card with their field names.
     *
     * @return array{codes:string[],cards:array<int,array<int,array{0:string,1:string}>>}
     */
    private function extract_delivery(array $data, $service_type = '') {
        $candidates = array();
        $cards = array();
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

        // Every accepted value, in payload order, and the card it belongs to.
        $add = static function ($value, $card, $source, $name) use (&$candidates, &$cards, $max_codes) {
            if (count($candidates) >= $max_codes || !is_scalar($value)) { return; }
            $candidate = trim((string) $value);
            if ($candidate === '' || strlen($candidate) > 512) { return; }
            $candidate = sanitize_text_field($candidate);
            $candidates[] = $candidate;
            if (!isset($cards[$card])) { $cards[$card] = array('source' => $source, 'fields' => array()); }
            $cards[$card]['fields'][] = array($name, $candidate);
        };

        // Scan an entry that is already inside a known delivery container. In
        // that narrow context, a field literally named `key` can legitimately be
        // the delivered game key. It is never accepted from unrelated metadata.
        $walk_delivery_entry = function ($value, $depth, $container, $card, $source, $name) use (&$walk_delivery_entry, $add, $single_keys) {
            if ($depth > 6) { return; }
            if (is_scalar($value)) { $add($value, $card, $source, $name); return; }
            if (!is_array($value)) { return; }
            foreach ($value as $k => $v) {
                if ($depth > 6) { return; }
                $key = sanitize_key((string) $k);
                $allowed = in_array($key, $single_keys, true)
                    || ($container === 'keys' && $key === 'key')
                    || ($container === 'vouchers' && $key === 'voucher');
                if ($allowed && is_scalar($v)) {
                    $add($v, $card, $source, $key);
                } elseif (is_array($v)) {
                    $walk_delivery_entry($v, $depth + 1, $container, $card, $source, $name);
                }
            }
        };

        // v4.15: a lone `code` / `pin` / `serial` key is a delivery only at the root of a
        // gift-card or game-key order. Deeper in the payload it describes something else
        // (product.code, category.code, error.code); accepting it used to complete the
        // order with a wrong "code" and stop polling before the real card arrived.
        $kind = sanitize_key((string) ($data['kind'] ?? ''));
        $kind_is_code = in_array($kind, array('gift_card', 'giftcard', 'game_key', 'gamekey'), true);
        $service_type = sanitize_key((string) $service_type);
        if (in_array($service_type, array('topup', 'giftcard', 'gamekey'), true)) {
            // The line type is known locally and wins. A top-up never takes a root code. A
            // gift card or game key does when the supplier's `kind` agrees or, without a
            // `kind`, only when the payload root is the order object itself: the `code` of a
            // flat API response is a status, not a card.
            $root_is_order = isset($data['id']) && is_scalar($data['id']) && preg_match('/^ord-[0-9]+$/', (string) $data['id']);
            $code_kind = $service_type !== 'topup' && ($kind !== '' ? $kind_is_code : (bool) $root_is_order);
        } else {
            $code_kind = $kind_is_code;
        }
        $entry_no = 0;
        $walk = function ($value, $depth = 0) use (&$walk, $walk_delivery_entry, &$candidates, &$entry_no, $single_keys, $list_keys, $add, $max_codes, $code_kind) {
            if ($depth > 10 || !is_array($value) || count($candidates) >= $max_codes) { return; }
            foreach ($value as $k => $v) {
                if (count($candidates) >= $max_codes) { return; }
                $key = sanitize_key((string) $k);
                if (in_array($key, $single_keys, true)) {
                    if ($depth === 0 && $code_kind && is_scalar($v)) { $add($v, 'root', 'root', $key); }
                    continue;
                }
                if (in_array($key, $list_keys, true) && is_array($v)) {
                    $container = in_array($key, array('keys','license_keys','licensekeys','activation_keys','activationkeys'), true) ? 'keys'
                        : (in_array($key, array('vouchers'), true) ? 'vouchers' : $key);
                    $entry_name = $container === 'keys' ? 'key'
                        : ($container === 'vouchers' ? 'voucher' : ($key === 'pins' ? 'pin' : ($key === 'serials' ? 'serial' : 'code')));
                    foreach ($v as $entry) {
                        if (count($candidates) >= $max_codes) { break; }
                        $entry_no++;
                        $walk_delivery_entry($entry, 0, $container, 'entry' . $entry_no, $key, $entry_name);
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
        if ($code_kind) {
            foreach (array('delivery', 'result', 'payload') as $fallback_key) {
                if (count($candidates) >= $max_codes) { break; }
                if (isset($data[$fallback_key]) && is_scalar($data[$fallback_key])) { $add($data[$fallback_key], 'root', 'root', 'code'); }
            }
        }

        $codes = array_slice(array_values(array_unique(array_filter($candidates))), 0, $max_codes);

        // Per card: a value echoed in another container (cards[].code repeated in codes[])
        // counts once and an identical card object counts once, but two cards of the same
        // container may share a value (two cards with PIN 0000).
        $structured = array();
        $first_source = array();
        $signatures = array();
        foreach ($cards as $card) {
            $fields = array();
            $in_card = array();
            foreach ($card['fields'] as $field) {
                $value = (string) $field[1];
                // '' and '0' never reach the flat list either (array_filter above).
                if ($value === '' || $value === '0' || isset($in_card[$value])) { continue; }
                if (isset($first_source[$value]) && $first_source[$value] !== $card['source']) { continue; }
                $in_card[$value] = true;
                $fields[] = array((string) $field[0], $value);
            }
            if (!$fields) { continue; }
            $signature = $card['source'] . '|' . wp_json_encode($fields);
            if (isset($signatures[$signature])) { continue; }
            $signatures[$signature] = true;
            foreach ($fields as $field) {
                if (!isset($first_source[$field[1]])) { $first_source[$field[1]] = $card['source']; }
            }
            $structured[] = $fields;
        }

        return array('codes' => $codes, 'cards' => $structured);
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

    private function item_has_delivery_codes($item) {
        if (!$item) { return false; }
        $count = absint($item->get_meta('_dfr_codes_count', true));
        if ($count > 0 && (string) $item->get_meta('_dfr_codes_enc', true) !== '') { return true; }
        return !empty($this->decrypt_item_codes($item));
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

    private function delivery_structure_context($order_id, $item_id) {
        return 'delivery-structure|order:' . absint($order_id) . '|item:' . absint($item_id);
    }

    /**
     * Keep the delivered values per card with their field names in separate encrypted
     * meta (_dfr_delivery_enc). _dfr_codes_enc and _dfr_codes_count keep their meaning.
     * Written only when it adds something: several cards or several fields.
     */
    private function store_delivery_structure($order_id, $item_id, $item, array $cards) {
        $useful = count($cards) > 1;
        foreach ($cards as $fields) {
            if (count($fields) > 1) { $useful = true; }
        }
        if ($useful) {
            $enc = DFR_Crypto::encrypt_context(wp_json_encode(array('v' => 1, 'cards' => array_values($cards))), $this->delivery_structure_context($order_id, $item_id));
            if (is_string($enc) && $enc !== '') {
                $item->update_meta_data('_dfr_delivery_enc', $enc);
                return;
            }
        }
        // Nothing to add, or it could not be sealed: the flat list alone is used.
        if ((string) $item->get_meta('_dfr_delivery_enc', true) !== '') {
            $item->delete_meta_data('_dfr_delivery_enc');
        }
    }

    /** @return array<int,array<int,array{name:string,value:string}>> Cards, or an empty array. */
    private function decrypt_item_delivery_structure($item) {
        if (!$item) { return array(); }
        $enc = (string) $item->get_meta('_dfr_delivery_enc', true);
        if ($enc === '') { return array(); }
        $order_id = is_callable(array($item, 'get_order_id')) ? (int) $item->get_order_id() : 0;
        $item_id = is_callable(array($item, 'get_id')) ? (int) $item->get_id() : 0;
        $data = json_decode((string) DFR_Crypto::decrypt_context($enc, $this->delivery_structure_context($order_id, $item_id)), true);
        if (!is_array($data) || (int) ($data['v'] ?? 0) !== 1 || !is_array($data['cards'] ?? null)) { return array(); }
        $cards = array();
        $total = 0;
        foreach (array_slice($data['cards'], 0, 100) as $card) {
            if (!is_array($card)) { continue; }
            $fields = array();
            foreach ($card as $field) {
                if ($total >= 100) { break; }
                if (!is_array($field) || !isset($field[0], $field[1]) || !is_scalar($field[1])) { continue; }
                $value = trim(sanitize_text_field((string) $field[1]));
                if ($value === '') { continue; }
                $fields[] = array('name' => sanitize_key((string) $field[0]), 'value' => $value);
                $total++;
            }
            if ($fields) { $cards[] = $fields; }
            if ($total >= 100) { break; }
        }
        return $cards;
    }

    /** Customer label of a supplier delivery field. */
    private function delivery_field_label($name) {
        $name = sanitize_key((string) $name);
        if ($name === 'pin') { return __('PIN', 'delicat-fazercards'); }
        if (in_array($name, array('serial', 'serial_number', 'serialnumber'), true)) { return __('Numéro de série', 'delicat-fazercards'); }
        if (in_array($name, array('key', 'activation_key', 'activationkey', 'license_key', 'licensekey'), true)) { return __('Clé', 'delicat-fazercards'); }
        if (in_array($name, array('activation_code', 'activationcode'), true)) { return __('Code d’activation', 'delicat-fazercards'); }
        return __('Code', 'delicat-fazercards');
    }

    /**
     * Delivered values of one line with customer labels: the product name alone for a
     * single code, « Carte 2 — PIN » when the supplier sent several cards or fields.
     * Decrypts: callers apply the visibility gate and read budget first.
     *
     * @return array<int,array{label:string,code:string}>
     */
    private function item_code_entries($item) {
        $name = wp_strip_all_tags((string) $item->get_name());
        $entries = array();
        $cards = $this->decrypt_item_delivery_structure($item);
        if ($cards) {
            $card_count = count($cards);
            foreach ($cards as $index => $fields) {
                $seen = array();
                foreach ($fields as $field) {
                    $label = $name;
                    if ($card_count > 1) {
                        /* translators: %d: card number */
                        $label .= ' — ' . sprintf(__('Carte %d', 'delicat-fazercards'), $index + 1);
                    }
                    if (count($fields) > 1) {
                        $field_label = $this->delivery_field_label($field['name']);
                        $seen[$field_label] = ($seen[$field_label] ?? 0) + 1;
                        $label .= ' — ' . $field_label . ($seen[$field_label] > 1 ? ' ' . $seen[$field_label] : '');
                    }
                    $entries[] = array('label' => $label, 'code' => $field['value']);
                }
            }
            return $entries;
        }
        $plain = array_values($this->decrypt_item_codes($item));
        $count = count($plain);
        foreach ($plain as $index => $value) {
            $entries[] = array('label' => $name . ($count > 1 ? ' — Code ' . ($index + 1) : ''), 'code' => (string) $value);
        }
        return $entries;
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

        // Transparently migrate older code envelopes (v1, or v2 once a key ring is
        // configured) after a successful owner/admin read. No plaintext is written.
        if ($safe && !DFR_Crypto::is_current((string) $enc)) {
            $upgraded = DFR_Crypto::encrypt_context(wp_json_encode($safe), $context);
            if (!is_wp_error($upgraded)) {
                $item->update_meta_data('_dfr_codes_enc', $upgraded);
                $item->update_meta_data('_dfr_codes_crypto', DFR_Crypto::has_keyring() ? 'aead-v3' : 'aead-v2');
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

        /**
         * A gift-card or game-key code was stored for this line (fires once per line).
         * The Delicat App API pushes "code prêt" and refreshes Mes commandes. Never
         * carries the code itself.
         */
        do_action('dfr_delivery_ready', absint($order->get_id()), absint($item->get_id()), count($codes));
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
        $this->schedule_action('dfr_finalize_completed_order', $args, (int) $delay <= 1 ? 0 : time() + (int) $delay);
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

        $has_gateway_line = false;
        foreach ($items as $item_id => $item) {
            if (is_object($item) && method_exists($item, 'read_meta_data')) {
                $item->read_meta_data(true);
            }
            $type = $this->item_service_type($item);
            if (!in_array($type, array('topup','giftcard','gamekey'), true)) {
                // v4.15: a line this gateway does not deliver no longer blocks completion
                // forever when it is already delivered (downloadable product, or another
                // fulfilment plugin says so through the filter).
                if ($this->non_gateway_line_fulfilled($item, $order)) { continue; }
                $this->set_completion_block_reason($order, 'untracked_line_' . absint($item_id));
                return false;
            }
            // A failed line the team resolved by hand (delivered it or refunded it) no longer
            // blocks the order, but it does not count as delivered by the gateway either.
            if (in_array((string) $item->get_meta('_dfr_delivery_state', true), array('manual', 'refunded'), true)) { continue; }
            $has_gateway_line = true;
            if ((string) $item->get_meta('_dfr_delivery_state', true) === 'failed') {
                $this->set_completion_block_reason($order, 'delivery_failed_' . absint($item_id));
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

        if (!$has_gateway_line) {
            // Never complete an order this gateway delivered nothing for.
            $this->set_completion_block_reason($order, 'no_gateway_lines');
            return false;
        }

        $this->queue_completion_candidate($order_id);
        $started = microtime(true);
        $this->mark_order_timing($order, 'completion_start');

        $this->internal_completion = true;
        try {
            $updated = $order->update_status('completed', __('All digital items completed and delivered.', 'delicat-fazercards'));
        } finally {
            $this->internal_completion = false;
        }
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
        $withheld = array();
        $is_staff = current_user_can('manage_woocommerce') || current_user_can('manage_options');
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $type = $this->item_service_type($item);
            if (!in_array($type, array('giftcard','gamekey'), true)) { continue; }

            if ($this->item_has_delivery_codes($item)) {
                // Same gate as the reveal, so « Code disponible » is never followed by a refusal.
                $reason = $is_staff ? '' : $this->customer_delivery_block_reason($order, $item_id, $item, get_current_user_id());
                if ($reason !== '') {
                    $withheld[] = $reason;
                    continue;
                }
                $count = max(1, absint($item->get_meta('_dfr_codes_count', true)));
                $ready[] = array('name' => (string) $item->get_name(), 'count' => $count);
            } elseif ($item->get_meta('_dfr_remote_order_id', true)) {
                $pending[] = (string) $item->get_name();
                $this->queue_item_delivery_refresh($order, $item_id, $item, 1);
            }
        }
        if (!$ready && !$pending && !$withheld) { return; }

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
        if ($withheld) {
            echo '<p style="margin:12px 0 0;padding:10px 12px;border-radius:10px;background:#fff7ed;color:#9a3412">' . esc_html($this->withheld_code_message($withheld)) . '</p>';
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
        $withheld = array();
        $is_staff = current_user_can('manage_woocommerce') || current_user_can('manage_options');
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $type = $this->item_service_type($item);
            if (!in_array($type, array('giftcard','gamekey'), true)) { continue; }
            $has_code_product = true;
            if ($this->item_has_delivery_codes($item)) {
                $reason = $is_staff ? '' : $this->customer_delivery_block_reason($order, $item_id, $item, get_current_user_id());
                if ($reason === '') { $ready = true; } else { $withheld[] = $reason; }
            }
            elseif ($item->get_meta('_dfr_remote_order_id', true)) { $pending = true; }
        }
        if (!$has_code_product) { echo '—'; return; }
        if ($ready) { echo '<span style="font-weight:700;color:#15803d">✓ ' . esc_html__('Code disponible', 'delicat-fazercards') . '</span>'; return; }
        if ($withheld) {
            $label = (in_array('on_hold', $withheld, true) && !array_intersect($withheld, array('refunded', 'cancelled')))
                ? __('En vérification', 'delicat-fazercards')
                : __('Code indisponible', 'delicat-fazercards');
            echo '<span style="font-weight:600;color:#9a3412">' . esc_html($label) . '</span>';
            return;
        }
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

    /**
     * @param WC_Order $order   Order.
     * @param int      $user_id Customer the row is shown to; codes they may not see right
     *                          now are reported as withheld instead of ready (staff excepted).
     */
    private function delica_history_delivery_state($order, $user_id = 0) {
        $state = array('has' => false, 'ready' => false, 'pending' => false, 'waiting_code' => false, 'failed' => false, 'count' => 0, 'withheld' => array());
        if (!$order) { return $state; }
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $is_staff = current_user_can('manage_woocommerce') || current_user_can('manage_options');
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $type = $this->item_service_type($item);
            if (!in_array($type, array('giftcard', 'gamekey'), true)) { continue; }
            $state['has'] = true;
            $enc = (string) $item->get_meta('_dfr_codes_enc', true);
            if ($enc !== '') {
                // Same gate as the reveal: never offer « Afficher » for a code it would refuse.
                $reason = $is_staff ? '' : $this->customer_delivery_block_reason($order, $item_id, $item, $user_id);
                if ($reason !== '') {
                    $state['withheld'][] = $reason;
                    continue;
                }
                $count = absint($item->get_meta('_dfr_codes_count', true));
                if ($count < 1) {
                    $codes = $this->decrypt_item_codes($item);
                    $count = count($codes);
                }
                if ($count > 0) { $state['ready'] = true; $state['count'] += $count; }
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
  function checkPending(box,attempt,nonceRetried){if(!box||!box.isConnected||box.dataset.checking==='1')return;box.dataset.checking='1';var body='action=dfr_delica_delivery_status&nonce='+encodeURIComponent(window.DFR_DELICA_HISTORY.nonce)+'&order='+encodeURIComponent(box.dataset.order);fetch(window.DFR_DELICA_HISTORY.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).then(function(r){return r.json()}).then(function(j){box.dataset.checking='0';if(j&&j.success&&j.data){if(j.data.ready){readyMarkup(box,parseInt(j.data.count||1,10));return}if(j.data.withheld){box.className='dfr-delica-history';box.innerHTML='';var w=document.createElement('div');w.className='dfr-delica-msg';w.style.cssText='padding:9px 11px;border-radius:9px;background:#fff7ed;color:#9a3412;font-size:13px;font-weight:650';w.textContent=j.data.message||'Code momentanément indisponible.';box.appendChild(w);return}if(j.data.failed){box.innerHTML='<div class="dfr-delica-msg" style="padding:9px 11px;border-radius:9px;background:#fdeaea;color:#8a2b2b;font-size:13px;font-weight:650">⚠️ La livraison fournisseur n’a pas été finalisée. Contactez le support avec ce numéro de commande.</div>';return}}var code=j&&j.data&&j.data.code?j.data.code:'';if(code==='nonce'&&!nonceRetried){box.dataset.checking='1';refreshNonce().then(function(){box.dataset.checking='0';checkPending(box,attempt,true)}).catch(function(){box.dataset.checking='0'});return}var delays=[2500,4000,7000,12000,20000,30000];if(attempt<delays.length)setTimeout(function(){checkPending(box,attempt+1,nonceRetried)},delays[attempt])}).catch(function(){box.dataset.checking='0';if(attempt<3)setTimeout(function(){checkPending(box,attempt+1,nonceRetried)},5000)})}
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
            $state = $this->delica_history_delivery_state($order, $user_id);
            if (!$state['has']) { continue; }
            if (!$this->delica_history_can_access_secret($order, $user_id)) {
                $row['details'] .= '<div style="margin-top:10px;padding:9px 11px;border-radius:10px;background:#f8fafc;color:#475569;font-size:13px;font-weight:650">🔐 ' . esc_html__('Pour protéger votre code, cette ancienne commande invitée doit être associée à votre compte par le support avant l’affichage de la livraison numérique.', 'delicat-fazercards') . '</div>';
                continue;
            }
            $this->ensure_delica_history_assets();
            if ($state['ready'] && $state['count'] > 0) {
                $word = $state['count'] > 1 ? $state['count'] . ' codes disponibles' : 'Code disponible';
                $row['details'] .= '<div class="dfr-delica-history" data-order="' . absint($order->get_id()) . '" style="margin-top:10px"><div style="margin-bottom:8px;font-size:12px;font-weight:800;color:#15803d">✓ ' . esc_html($word) . ' · chiffré et protégé</div><button type="button" class="dfr-delica-reveal" style="min-height:42px;border:0;border-radius:12px;background:#5D4B8E;color:#fff;padding:10px 15px;font-weight:800;cursor:pointer">🔒 Afficher mon code cadeau</button><div class="dfr-delica-out"></div></div>';
            } elseif ($state['withheld']) {
                $row['details'] .= '<div style="margin-top:10px;padding:9px 11px;border-radius:10px;background:#fff7ed;color:#9a3412;font-size:13px;font-weight:650">' . esc_html($this->withheld_code_message($state['withheld'])) . '</div>';
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
        $state = $this->delica_history_delivery_state($order, get_current_user_id());
        $withheld = !empty($state['withheld']) && empty($state['ready']);
        wp_send_json_success(array(
            'ready'        => !empty($state['ready']),
            'count'        => absint($state['count']),
            'pending'      => !empty($state['pending']),
            'waiting_code' => !empty($state['waiting_code']),
            'failed'       => !empty($state['failed']),
            // A code exists but cannot be shown right now (order being checked, refunded…).
            'withheld'     => $withheld,
            'message'      => $withheld ? $this->withheld_code_message($state['withheld']) : '',
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
        $is_staff = current_user_can('manage_woocommerce') || current_user_can('manage_options');
        $items = array(); $failed = 0; $withheld = array();
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $type = $this->item_service_type($item);
            if (!in_array($type, array('giftcard', 'gamekey'), true)) { continue; }
            $enc = (string) $item->get_meta('_dfr_codes_enc', true);
            if ($enc === '') { continue; }
            // v4.15: customers see a code only while its line is paid, not refunded and
            // not cancelled. Staff keep access for support, with an audit note below.
            $reason = $is_staff ? '' : $this->customer_delivery_block_reason($order, $item_id, $item, get_current_user_id());
            if ($reason !== '') { $withheld[] = $reason; continue; }
            $entries = $this->item_code_entries($item);
            if (!$entries) { $failed++; continue; }
            foreach ($entries as $entry) { $items[] = array('name' => $entry['label'], 'code' => $entry['code']); }
        }
        // The message says why: an order being checked is not a refunded or cancelled one.
        if (!$items && $withheld) { wp_send_json_error(array('code' => 'withheld', 'message' => $this->withheld_code_message($withheld)), 403); }
        if (!$items && !$failed) { wp_send_json_error(array('code' => 'none', 'message' => __('Votre code est encore en préparation.', 'delicat-fazercards')), 409); }
        if (!$items && $failed) { wp_send_json_error(array('code' => 'decrypt', 'message' => __('Le coffre-fort n’a pas pu déchiffrer ce code. Contactez le support.', 'delicat-fazercards')), 500); }
        if ($is_staff && (int) $order->get_customer_id() !== get_current_user_id()) {
            $staff = wp_get_current_user();
            $order->add_order_note(sprintf(
                __('Codes numériques affichés par %s (support). Aucune valeur n’est enregistrée dans cette note.', 'delicat-fazercards'),
                $staff && !empty($staff->user_login) ? sanitize_user($staff->user_login) : ('#' . get_current_user_id())
            ));
        }
        wp_send_json_success(array('items' => $items, 'failed' => $failed));
    }

    // ---------------------------------------------------------------------------------
    // v4.15 — scheduling, fulfilment queue, delivery failures, app bridge
    // ---------------------------------------------------------------------------------

    /**
     * Schedule one background action WITHOUT Action Scheduler's `unique` flag.
     *
     * AS checks `unique` on hook + group only, never on arguments. A pending or running
     * action of one order therefore silently blocked the same hook for every other
     * order, and a running retry blocked its own successor. Dedupe on hook + ARGS here,
     * and fall back to WP-Cron whenever Action Scheduler does not accept the job.
     *
     * @param string $hook      Action hook.
     * @param array  $args      Arguments that identify the job.
     * @param int    $timestamp Unix time; 0 means as soon as possible.
     * @return bool True when the job is queued (or already was).
     */
    private function schedule_action($hook, array $args, $timestamp = 0) {
        $group = 'delicat-fazercards';
        $timestamp = (int) $timestamp;
        $as = function_exists('as_schedule_single_action') && did_action('action_scheduler_init');
        if ($as && function_exists('as_has_scheduled_action') && as_has_scheduled_action($hook, $args, $group)) {
            return true;
        }
        $action_id = 0;
        $priority = self::action_priority($hook);
        if ($as) {
            try {
                if ($timestamp <= time() && function_exists('as_enqueue_async_action')) {
                    $action_id = (int) as_enqueue_async_action($hook, $args, $group, false, $priority);
                } else {
                    $action_id = (int) as_schedule_single_action(max(time(), $timestamp), $hook, $args, $group, false, $priority);
                }
            } catch (Throwable $e) {
                $action_id = 0;
            }
        }
        if ($action_id > 0) { return true; }
        if (wp_next_scheduled($hook, $args)) { return true; }
        $when = max(time(), $timestamp);
        $scheduled = (bool) wp_schedule_single_event($when, $hook, $args);
        if ($scheduled && $when <= time() + 5 && function_exists('spawn_cron')) { spawn_cron(); }
        return $scheduled;
    }

    /**
     * Action Scheduler priority (0 runs first, default 10; older Action Scheduler
     * releases ignore it). Paid-order delivery and supplier status jobs run before
     * catalog, balance and migration batches, which used to occupy the single queue
     * runner for up to 20 s per batch while a confirmed order waited behind them.
     */
    public static function action_priority($hook) {
        $urgent = array(
            'dfr_process_verified_webhook', 'dfr_fast_reconcile_remote_order', 'dfr_reconcile_plan_tick',
            'dfr_retry_order_item', 'dfr_fulfill_paid_order', 'dfr_finalize_completed_order',
            'dfr_reconcile_watchdog', 'dfr_poll_remote_order',
        );
        if (in_array((string) $hook, $urgent, true)) { return 0; }
        if (in_array((string) $hook, array('dfr_send_delivery_failure_alert', 'dfr_send_attestation_refusal_alert'), true)) { return 5; }
        return 150;
    }

    /** True when at least one line of the order is delivered by this gateway. */
    private function order_has_gateway_items($order) {
        if (!$order || !is_object($order)) { return false; }
        // Only wallet orders are delivered here. Reseller API mirror orders carry gateway
        // products too, but the reseller bridge delivers them and stores the supplier id on
        // the order: treating them as ours flagged every completion as manual and showed
        // them as « Paiement confirmé » in the app.
        if (is_callable(array($order, 'get_payment_method')) && (string) $order->get_payment_method('edit') !== 'wallet') { return false; }
        if ((string) $order->get_meta('_dfr_reseller_api_order', true) === '1') { return false; }
        foreach ($order->get_items('line_item') as $item) {
            if (in_array($this->item_service_type($item), array('topup','giftcard','gamekey'), true)) { return true; }
        }
        return false;
    }

    private function has_pending_item_retry($order_id, $item_id, $attempt) {
        $args = array(absint($order_id), absint($item_id), max(1, (int) $attempt));
        if (function_exists('as_has_scheduled_action') && did_action('action_scheduler_init')
            && as_has_scheduled_action('dfr_retry_order_item', $args, 'delicat-fazercards')) {
            return true;
        }
        return (bool) wp_next_scheduled('dfr_retry_order_item', $args);
    }

    /** Paid gateway lines that never reached the supplier and may be sent now. */
    private function order_has_unsent_paid_items($order) {
        if (!$order || !is_object($order)) { return false; }
        // Closed by the team with « Terminée »: fulfill_item() will not send its lines.
        if ($order->has_status('completed') && (string) $order->get_meta('_dfr_manual_completion_by', true) !== '') { return false; }
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!in_array($this->item_service_type($item), array('topup','giftcard','gamekey'), true)) { continue; }
            if (trim((string) $item->get_meta('_dfr_remote_order_id', true)) !== '') { continue; }
            if (in_array((string) $item->get_meta('_dfr_delivery_state', true), array('failed', 'manual', 'refunded'), true)) { continue; }
            if ((string) $item->get_meta('_dfr_uid_revalidation_failed', true) !== '') { continue; }
            $raw_attempt = (int) $item->get_meta('_dfr_fulfill_attempt', true);
            // Player ID rate-limit/transport retries do not use up the six attempts.
            $attempt = $raw_attempt - absint($item->get_meta('_dfr_uid_check_transient_count', true));
            if ($attempt >= 6) { continue; }
            $last = strtotime((string) $item->get_meta('_dfr_timing_supplier_submit_last', true));
            if ($last !== false && $last > time() - 60) { continue; } // an attempt is in flight
            // Retry jobs are keyed by the raw attempt number (see schedule_order_item_retry()).
            if ($raw_attempt > 0 && $this->has_pending_item_retry($order->get_id(), $item_id, $raw_attempt)) { continue; }
            return true;
        }
        return false;
    }

    /** Hook adapter for WooCommerce payment/status/thank-you actions. */
    public function queue_fulfillment_from_hook($order_id) {
        $this->queue_fulfillment(absint($order_id), function_exists('current_action') ? (string) current_action() : 'hook');
    }

    /**
     * Start the supplier pass for a paid order without supplier I/O in this request.
     *
     * Safe to call many times: the order lock, the stored supplier order id and the
     * supplier Idempotency-Key turn a duplicate pass into a no-op.
     *
     * @param int    $order_id Order id.
     * @param string $source   Diagnostic label (logs only).
     * @return bool True when at least one runner was started.
     */
    public function queue_fulfillment($order_id, $source = '') {
        $order_id = absint($order_id);
        if (!$order_id || !function_exists('wc_get_order')) { return false; }
        if ($this->settings['live_orders_enabled'] !== '1' || $this->settings['auto_fulfill_enabled'] !== '1') { return false; }
        $order = wc_get_order($order_id);
        if (!$order || (string) $order->get_payment_method('edit') !== 'wallet') { return false; }
        if (!$this->order_has_gateway_items($order)) { return false; }

        $started = false;
        // Fast runners only once the payment proof exists. TeraWallet fires
        // payment_complete before its attestation hook; a loopback started that early
        // would find no proof, stop, and burn the debounce window.
        if ($this->order_has_verified_wallet_payment($order)) {
            // 1. Post-response pass in this PHP worker (PHP-FPM or LiteSpeed).
            if (!$this->internal_reconcile_active && !wp_doing_cron() && count($this->post_response_fulfill) < 3) {
                $this->post_response_fulfill[$order_id] = true;
                $started = true;
            }
            // 2. Detached single-use loopback, for hosts that cannot finish the response.
            if ($this->dispatch_fulfill_loopback($order_id)) { $started = true; }
        }
        // 3. Durable fallback. The delay leaves the fast paths time to win; the job
        //    re-queues itself while paid lines are still unsent.
        if ($this->schedule_fulfillment_job($order_id, 20)) { $started = true; }
        if (!$started) {
            $this->log('warning', 'Fulfilment could not be queued', array('order' => $order_id, 'source' => substr(sanitize_key((string) $source), 0, 40)));
        }
        return $started;
    }

    private function schedule_fulfillment_job($order_id, $delay = 20) {
        $order_id = absint($order_id);
        if (!$order_id) { return false; }
        $round = 1;
        if (function_exists('wc_get_order')) {
            $job_order = wc_get_order($order_id);
            if ($job_order) { $round = max(1, (int) $job_order->get_meta('_dfr_fulfill_job_round', true) + 1); }
        }
        if ($round > 12) { return false; }
        return $this->schedule_action('dfr_fulfill_paid_order', array($order_id, $round), time() + max(1, (int) $delay));
    }

    /** Durable worker behind queue_fulfillment(). */
    public function fulfill_paid_order_job($order_id, $round = 1) {
        if (!function_exists('wc_get_order')) { return; }
        $order_id = absint($order_id);
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order) { return; }
        $order->update_meta_data('_dfr_fulfill_job_round', max(1, (int) $round));
        $order->save_meta_data();
        $this->maybe_auto_fulfill_order($order_id);
        $fresh = wc_get_order($order_id);
        if ($fresh && $this->order_has_unsent_paid_items($fresh)) {
            $this->schedule_fulfillment_job($order_id, min(300, 20 * max(1, (int) $round)));
        }
    }

    private function dispatch_fulfill_loopback($order_id) {
        $order_id = absint($order_id);
        if (!$order_id) { return false; }
        $debounce = 'dfr_fulfill_dispatch_' . $order_id;
        if (get_transient($debounce)) { return true; }
        if (!set_transient($debounce, 1, 10)) { return false; }
        try {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (Throwable $e) {
            delete_transient($debounce);
            return false;
        }
        $claim_key = 'dfr_fulfill_claim_' . hash('sha256', $token);
        if (!set_transient($claim_key, array('order_id' => $order_id, 'issued' => time()), 120)) {
            delete_transient($debounce);
            return false;
        }
        $response = $this->fire_loopback(array('action' => 'dfr_internal_fulfill', 'token' => $token), array($claim_key, $debounce));
        if (is_wp_error($response)) {
            delete_transient($claim_key);
            delete_transient($debounce);
            $this->log('warning', 'Fulfilment loopback could not be dispatched; durable job remains queued', array('order' => $order_id, 'error' => $response->get_error_code()));
            return false;
        }
        return true;
    }

    /** Single-use loopback runner. The token carries no identifier; the claim does. */
    public function ajax_internal_fulfill() {
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
        global $wpdb;
        $claim_key = 'dfr_fulfill_claim_' . hash('sha256', $token);
        $claim_lock = 'dfr_ff_' . substr(hash('sha256', $token), 0, 40);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $claim_lock)) !== '1') {
            wp_send_json_error(array('code' => 'busy'), 503);
        }
        $claim = get_transient($claim_key);
        if (is_array($claim)) { delete_transient($claim_key); }
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $claim_lock));
        if (!is_array($claim)) {
            wp_send_json_error(array('code' => 'expired'), 403);
        }
        $order_id = absint($claim['order_id'] ?? 0);
        $issued = absint($claim['issued'] ?? 0);
        if (!$order_id || !$issued || $issued < time() - 90) {
            wp_send_json_error(array('code' => 'invalid_claim'), 403);
        }
        $this->release_loopback_caller();
        $this->internal_reconcile_active = true;
        try {
            $this->maybe_auto_fulfill_order($order_id);
        } finally {
            $this->internal_reconcile_active = false;
        }
        wp_send_json_success(array('ok' => true));
    }

    private function defer_order_item_retry($order, $item_id) {
        $item = $order ? $order->get_item(absint($item_id)) : false;
        if (!$item || $item->get_meta('_dfr_remote_order_id', true)) { return; }
        $deferred = (int) $item->get_meta('_dfr_retry_deferred', true) + 1;
        if ($deferred > 10) { return; }
        $item->update_meta_data('_dfr_retry_deferred', $deferred);
        $item->save();
        // The third argument is ignored by the callback; it keeps each deferral distinct.
        $this->schedule_action('dfr_retry_order_item', array(absint($order->get_id()), absint($item_id), 'd' . $deferred), time() + $this->jitter_delay(10 * $deferred));
    }

    /** Downloadable lines, or lines another plugin reports delivered, do not block. */
    private function non_gateway_line_fulfilled($item, $order) {
        $product = is_callable(array($item, 'get_product')) ? $item->get_product() : null;
        // A "downloadable" product without a file (a voucher or account sent by hand) is not
        // delivered yet: it keeps the order open like any other manual line.
        $default = $product && is_callable(array($product, 'is_downloadable')) && $product->is_downloadable()
            && (!is_callable(array($product, 'has_file')) || $product->has_file());
        /**
         * Whether a line this gateway does not deliver counts as delivered.
         * Default: downloadable products that carry a file. Lines delivered by hand (for
         * example a streaming account) keep the order open until the owner completes it.
         *
         * @param bool          $fulfilled Default decision.
         * @param WC_Order_Item $item      Line.
         * @param WC_Order      $order     Order.
         */
        return (bool) apply_filters('dfr_non_digital_line_fulfilled', $default, $item, $order);
    }

    /**
     * A paid gateway line cannot be delivered automatically.
     *
     * Owner policy (September 2026): no automatic refund. The line is marked failed and
     * never resent on its own, the order goes On hold, the customer is told once that
     * the team is checking, and the owner gets an alert. The owner refunds or relaunches.
     *
     * @param WC_Order $order   Order.
     * @param int      $item_id Line id.
     * @param string   $reason  Machine code; never supplier text.
     */
    private function mark_item_delivery_failed($order, $item_id, $reason) {
        if (!$order || !is_object($order)) { return; }
        $item = $order->get_item(absint($item_id));
        // Already failed, or resolved by the team (delivered by hand / refunded): a late
        // supplier signal changes nothing.
        if (!$item || in_array((string) $item->get_meta('_dfr_delivery_state', true), array('failed', 'manual', 'refunded'), true)) { return; }
        $reason = substr(sanitize_key((string) $reason), 0, 60);
        if ($reason === '') { $reason = 'unknown'; }
        $item->update_meta_data('_dfr_delivery_state', 'failed');
        $item->update_meta_data('_dfr_delivery_failure', $reason);
        $item->update_meta_data('_dfr_delivery_failed_at', gmdate('c'));
        $item->save();
        $this->set_completion_block_reason($order, 'delivery_failed_' . absint($item_id));
        $this->log('error', 'Digital delivery failed; order held for manual review', array('order' => absint($order->get_id()), 'item' => absint($item_id), 'reason' => $reason));

        // Before the status change, so the app API sends its specific push and skips the
        // generic On-hold one.
        do_action('dfr_delivery_failed', absint($order->get_id()), absint($item_id), $reason);

        $order->add_order_note(sprintf(
            /* translators: %s: product name */
            __('La livraison de « %s » n’a pas pu être finalisée automatiquement. Notre équipe vérifie votre commande et revient vers vous rapidement. Aucun nouveau paiement n’est nécessaire.', 'delicat-fazercards'),
            wp_strip_all_tags((string) $item->get_name())
        ), true);
        $order->add_order_note(sprintf(
            'Delicat : livraison automatique impossible pour la ligne %1$d (raison : %2$s). Commande mise en attente pour vérification ; aucun remboursement automatique n’a été fait.',
            absint($item_id),
            $reason
        ));
        // Decide from the stored status: a runner holding an older copy must never move an
        // order that is Completed (or already On hold) in the database.
        if ($order->has_status('processing') && $this->persisted_order_status($order) === 'processing') {
            // The marker tells the payment proof this hold is the gateway's own review hold,
            // so the other paid lines keep being sent, reconciled and shown. It is set in
            // memory first: update_status() saves it, and later lines of the same pass see
            // it. Any status change made outside this block removes it again.
            $order->update_meta_data('_dfr_gateway_hold', gmdate('c'));
            $this->internal_status_change = true;
            try {
                $order->update_status('on-hold', __('Livraison numérique à vérifier.', 'delicat-fazercards'));
            } finally {
                $this->internal_status_change = false;
            }
        } else {
            $order->save();
        }
        // Mail goes out in the background: this can run inside a webhook or checkout call.
        $this->schedule_action('dfr_send_delivery_failure_alert', array(absint($order->get_id()), absint($item_id)), 0);
    }

    public function send_delivery_failure_alert($order_id, $item_id) {
        if (!function_exists('wc_get_order')) { return; }
        $order = wc_get_order(absint($order_id));
        $item = $order ? $order->get_item(absint($item_id)) : false;
        if (!$order || !$item || (string) $item->get_meta('_dfr_delivery_state', true) !== 'failed') { return; }
        if ((string) $item->get_meta('_dfr_delivery_alert_sent', true) !== '') { return; }
        $to = (string) apply_filters('dfr_delivery_failure_alert_email', get_option('admin_email'), $order);
        if (!is_email($to)) { return; }
        $link = admin_url('post.php?post=' . absint($order->get_id()) . '&action=edit');
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && is_callable(array('\Automattic\WooCommerce\Utilities\OrderUtil', 'get_order_admin_edit_url'))) {
            $link = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url(absint($order->get_id()));
        }
        $subject = sprintf('[Delicat] Livraison à vérifier — commande #%s', $order->get_order_number());
        $body = "Une livraison numérique n'a pas pu être finalisée automatiquement.\n\n"
            . 'Commande : #' . $order->get_order_number() . "\n"
            . 'Produit : ' . wp_strip_all_tags((string) $item->get_name()) . "\n"
            . 'Raison : ' . sanitize_key((string) $item->get_meta('_dfr_delivery_failure', true)) . "\n\n"
            . "La commande est en attente. Aucun remboursement automatique n'a été fait : vérifiez-la, puis remboursez le client ou relancez la livraison depuis la commande.\n\n"
            . $link . "\n";
        if (wp_mail($to, $subject, $body)) {
            $item->update_meta_data('_dfr_delivery_alert_sent', gmdate('c'));
            $item->save();
        }
    }

    /** Admin order screen: failed lines, with relaunch and resolution links. */
    public function render_admin_delivery_failures($order) {
        if (!$order || !is_object($order) || !current_user_can('manage_woocommerce')) { return; }
        $rows = array();
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if ((string) $item->get_meta('_dfr_delivery_state', true) !== 'failed') { continue; }
            $rows[] = array(
                absint($item_id),
                (string) $item->get_name(),
                sanitize_key((string) $item->get_meta('_dfr_delivery_failure', true)),
                trim((string) $item->get_meta('_dfr_remote_order_id', true)) !== '',
                $this->failed_line_relaunchable($item),
            );
        }
        $attestation_refused = (string) $order->get_meta('_dfr_attestation_refused', true) !== ''
            && trim((string) $order->get_meta('_dfr_wallet_attestation_sig', true)) === '';
        if (!$rows && !$attestation_refused) { return; }
        $order_id = absint($order->get_id());
        echo '<div class="dfr-delivery-failures" style="clear:both;margin:12px 0;padding:10px 12px;border-left:4px solid #d63638;background:#fcf0f1">';
        echo '<p style="margin:0 0 6px"><strong>' . esc_html__('Livraison numérique à vérifier', 'delicat-fazercards') . '</strong></p>';
        if ($attestation_refused) {
            echo '<p style="margin:4px 0 6px">' . esc_html__('La preuve du paiement par portefeuille a été refusée : rien n’a été envoyé au fournisseur. Vérifiez le débit TeraWallet du client, puis livrez à la main ou remboursez-le.', 'delicat-fazercards') . '</p>';
        }
        foreach ($rows as $row) {
            list($item_id, $name, $reason, $has_remote, $relaunchable) = $row;
            echo '<p style="margin:4px 0">' . esc_html($name) . ' — <code>' . esc_html($reason) . '</code></p>';
            if ($has_remote) {
                echo '<p style="margin:0 0 6px;color:#50575e">' . esc_html__('Le fournisseur a enregistré cette ligne puis l’a refusée ou remboursée : aucun renvoi automatique. Remboursez le client ou contactez le fournisseur.', 'delicat-fazercards') . '</p>';
            } elseif (!$relaunchable) {
                echo '<p style="margin:0 0 6px;color:#50575e">' . esc_html__('Renvoyer la même demande échouerait de nouveau pour cette raison : livrez à la main ou remboursez le client.', 'delicat-fazercards') . '</p>';
            }
            // Links, not forms: this box renders inside the order edit form.
            echo '<p style="margin:0 0 6px">';
            if ($relaunchable) {
                $url = wp_nonce_url(
                    add_query_arg(array('action' => 'dfr_retry_failed_delivery', 'order_id' => $order_id, 'item_id' => $item_id), admin_url('admin-post.php')),
                    'dfr_retry_failed_delivery_' . $order_id . '_' . $item_id
                );
                echo '<a class="button" href="' . esc_url($url) . '">' . esc_html__('Relancer la livraison', 'delicat-fazercards') . '</a> ';
                echo '<span style="color:#50575e">' . esc_html__('Renvoie la même demande au fournisseur (même clé d’idempotence, aucun double débit fournisseur).', 'delicat-fazercards') . '</span><br>';
            }
            foreach (array(
                'manual'   => __('Livré manuellement', 'delicat-fazercards'),
                'refunded' => __('Remboursé', 'delicat-fazercards'),
            ) as $resolution => $label) {
                $url = wp_nonce_url(
                    add_query_arg(array('action' => 'dfr_resolve_failed_delivery', 'order_id' => $order_id, 'item_id' => $item_id, 'resolution' => $resolution), admin_url('admin-post.php')),
                    'dfr_resolve_failed_delivery_' . $order_id . '_' . $item_id . '_' . $resolution
                );
                echo '<a class="button" style="margin-top:4px" href="' . esc_url($url) . '">' . esc_html($label) . '</a> ';
            }
            echo '</p>';
        }
        if ($this->order_on_gateway_hold($order)) {
            echo '<p style="margin:6px 0 0;color:#50575e">' . esc_html__('Commande en attente à cause de cet échec : les autres lignes payées continuent d’être livrées. Pour tout bloquer (par exemple un contrôle de fraude), passez la commande à un autre statut.', 'delicat-fazercards') . '</p>';
        }
        echo '</div>';
    }

    /**
     * A failed line may be resent as is only for causes a resend can clear: supplier or
     * network trouble, or a supplier refusal the owner can fix (balance, API key). Never
     * for integrity, mapping, Player ID or quantity failures, which fail again at once,
     * and never without the immutable request snapshot (an uncertain legacy attempt).
     */
    private function failed_line_relaunchable($item) {
        if (!$item || (string) $item->get_meta('_dfr_delivery_state', true) !== 'failed') { return false; }
        if (trim((string) $item->get_meta('_dfr_remote_order_id', true)) !== '') { return false; }
        $reason = sanitize_key((string) $item->get_meta('_dfr_delivery_failure', true));
        if (!in_array($reason, array('supplier_unreachable', 'supplier_unexpected_response', 'supplier_rejected', 'player_id_check_unavailable', 'player_id_check_rejected'), true)) {
            return false;
        }
        return (string) $item->get_meta('_dfr_fulfill_request_enc', true) !== ''
            || (string) $item->get_meta('_dfr_checkout_snapshot_enc', true) !== '';
    }

    public function admin_retry_failed_delivery() {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('Accès refusé.', 'delicat-fazercards'), 403); }
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $item_id = isset($_GET['item_id']) ? absint($_GET['item_id']) : 0;
        check_admin_referer('dfr_retry_failed_delivery_' . $order_id . '_' . $item_id);
        $order = ($order_id && function_exists('wc_get_order')) ? wc_get_order($order_id) : false;
        $item = $order ? $order->get_item($item_id) : false;
        $back = wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=wc-orders');
        if (!$order || !$item || !$this->failed_line_relaunchable($item)) {
            wp_safe_redirect(add_query_arg('dfr_retry', 'refused', $back));
            exit;
        }
        foreach (array('_dfr_delivery_state', '_dfr_delivery_failure', '_dfr_delivery_failed_at', '_dfr_delivery_alert_sent', '_dfr_uid_revalidation_failed', '_dfr_retry_deferred', '_dfr_uid_check_transient_since', '_dfr_uid_check_transient_count') as $meta_key) {
            $item->delete_meta_data($meta_key);
        }
        // Same snapshot, same Idempotency-Key: resetting the counter cannot change the request.
        $item->update_meta_data('_dfr_fulfill_attempt', 0);
        $item->save();
        $order->delete_meta_data('_dfr_fulfill_job_round');
        $order->delete_meta_data('_dfr_gateway_hold');
        $staff = wp_get_current_user();
        $order->add_order_note(sprintf(
            'Delicat : livraison relancée manuellement pour la ligne %1$d par %2$s.',
            $item_id,
            ($staff && !empty($staff->user_login)) ? sanitize_user($staff->user_login) : ('#' . get_current_user_id())
        ));
        if ($order->has_status('on-hold')) {
            // The customer was already told the team is checking: this internal relaunch
            // does not send WooCommerce's « commande en cours » e-mail a second time.
            $mute_processing_email = static function () { return false; };
            add_filter('woocommerce_email_enabled_customer_processing_order', $mute_processing_email, 999);
            try {
                $order->update_status('processing', __('Livraison relancée.', 'delicat-fazercards'));
            } finally {
                remove_filter('woocommerce_email_enabled_customer_processing_order', $mute_processing_email, 999);
            }
        } else {
            $order->save();
        }
        $this->queue_fulfillment($order_id, 'admin_retry');
        wp_safe_redirect(add_query_arg('dfr_retry', 'queued', $back));
        exit;
    }

    /**
     * The team resolved a failed line by hand: « Livré manuellement » or « Remboursé ».
     * The line is closed for good (never sent, shown as delivered or refunded in the app),
     * and the order completes through the normal gate when nothing else is open.
     */
    public function admin_resolve_failed_delivery() {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('Accès refusé.', 'delicat-fazercards'), 403); }
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $item_id = isset($_GET['item_id']) ? absint($_GET['item_id']) : 0;
        $resolution = isset($_GET['resolution']) ? sanitize_key(wp_unslash($_GET['resolution'])) : '';
        check_admin_referer('dfr_resolve_failed_delivery_' . $order_id . '_' . $item_id . '_' . $resolution);
        $order = ($order_id && function_exists('wc_get_order')) ? wc_get_order($order_id) : false;
        $item = $order ? $order->get_item($item_id) : false;
        $back = wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=wc-orders');
        if (!$order || !$item || !in_array($resolution, array('manual', 'refunded'), true)
            || (string) $item->get_meta('_dfr_delivery_state', true) !== 'failed') {
            wp_safe_redirect(add_query_arg('dfr_resolve', 'refused', $back));
            exit;
        }
        $item->update_meta_data('_dfr_delivery_state', $resolution);
        $item->update_meta_data('_dfr_delivery_resolved_at', gmdate('c'));
        $item->update_meta_data('_dfr_delivery_resolved_by', (string) absint(get_current_user_id()));
        $item->save();
        $staff = wp_get_current_user();
        $who = ($staff && !empty($staff->user_login)) ? sanitize_user($staff->user_login) : ('#' . get_current_user_id());
        $order->add_order_note(sprintf(
            $resolution === 'manual'
                ? 'Delicat : ligne %1$d marquée « livrée manuellement » par %2$s. Elle ne sera jamais envoyée au fournisseur.'
                : 'Delicat : ligne %1$d marquée « remboursée » par %2$s. Le remboursement lui-même se fait dans WooCommerce.',
            $item_id,
            $who
        ));
        $order->save();
        $this->record_admin_audit('delivery_line_resolved', array('order' => $order_id, 'item' => $item_id, 'resolution' => $resolution));
        // Every other line delivered: the normal gate (payment proof included) completes it.
        $this->maybe_complete_woo_order($order, true);
        wp_safe_redirect(add_query_arg('dfr_resolve', 'done', $back));
        exit;
    }

    /** Admin "Completed" on an undelivered digital order is recorded, never announced. */
    public function guard_manual_completion($order_id, $from, $to, $order = null) {
        if ($to !== 'completed' || $this->internal_completion) { return; }
        if (!is_object($order) && function_exists('wc_get_order')) { $order = wc_get_order($order_id); }
        if (!$order || !$this->order_has_gateway_items($order)) { return; }
        $summary = $this->delivery_summary($order);
        if (in_array($summary['state'], array('delivered', 'refunded'), true)) { return; }
        // A team member closing the order, as opposed to WooCommerce completing a paid
        // virtual order by itself during checkout.
        $staff_action = get_current_user_id() > 0 && (current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce'));
        // Measured before this closure is recorded: order_has_unsent_paid_items() ignores
        // orders the team closed (an earlier closure is replaced below).
        $order->delete_meta_data('_dfr_manual_completion_by');
        $has_unsent = $this->order_has_unsent_paid_items($order);
        $order->update_meta_data('_dfr_manual_completion_without_delivery', gmdate('c'));
        if ($staff_action) {
            $order->update_meta_data('_dfr_manual_completion_by', (string) get_current_user_id());
        }
        $order->save_meta_data();
        $order->add_order_note(__('Attention : commande marquée Terminée alors que la livraison numérique n’est pas confirmée pour toutes les lignes. Le client ne reçoit pas de notification « livrée ».', 'delicat-fazercards'));
        // Lines already at the supplier keep being checked (read-only), so a late code is
        // still stored and shown.
        $this->rearm_in_flight_lines($order);
        if (!$has_unsent) { return; }
        if ($staff_action) {
            // The team may have delivered these lines by hand: never buy them on our own.
            $order->add_order_note(__('Delicat : des lignes payées n’ont pas encore été envoyées au fournisseur. Elles ne partent pas automatiquement après « Terminée » : remettez la commande « En cours » si elles doivent encore être livrées.', 'delicat-fazercards'));
            return;
        }
        $this->queue_fulfillment(absint($order_id), 'manual_completion');
    }

    /**
     * May this customer see this line's delivered code right now?
     * See customer_delivery_block_reason().
     */
    private function customer_delivery_visible($order, $item_id, $item, $user_id) {
        return $this->customer_delivery_block_reason($order, $item_id, $item, $user_id) === '';
    }

    /**
     * Why this customer cannot see this line's delivered code right now; '' when they can.
     *
     * Owner of the order; order Processing or Completed, or On hold by this gateway's own
     * review hold (another line failed); line delivered, not failed and not refunded; an
     * amount-only refund no larger than the lines that were never delivered; payment proven.
     *
     * @return string '', 'owner', 'disabled', 'on_hold', 'cancelled', 'status', 'failed',
     *                'not_delivered', 'refunded' or 'payment'.
     */
    private function customer_delivery_block_reason($order, $item_id, $item, $user_id) {
        $user_id = absint($user_id);
        if (!$order || !$item || $user_id < 1) { return 'owner'; }
        if ((int) $order->get_customer_id() !== $user_id) { return 'owner'; }
        if (($this->settings['customer_codes_enabled'] ?? '1') !== '1') { return 'disabled'; }
        if (!$order->has_status(array('processing', 'completed'))) {
            if ($order->has_status(array('refunded', 'cancelled', 'failed'))) { return 'cancelled'; }
            if (!$order->has_status('on-hold')) { return 'status'; }
            // An On hold set by the owner or another plugin still hides every code.
            if (!$this->order_on_gateway_hold($order)) { return 'on_hold'; }
        }
        $line_state = (string) $item->get_meta('_dfr_delivery_state', true);
        if ($line_state === 'failed') { return 'failed'; }
        if ($line_state === 'refunded') { return 'refunded'; }
        if ($this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true)) !== 'completed') { return 'not_delivered'; }
        if (is_callable(array($order, 'get_qty_refunded_for_item'))) {
            if ((int) $order->get_qty_refunded_for_item(absint($item_id)) !== 0) { return 'refunded'; }
            $refunded_total = (float) $order->get_total_refunded();
            if ($refunded_total > 0) {
                $line_refund = false;
                $undelivered_total = 0.0;
                foreach ($order->get_items('line_item') as $other_id => $other) {
                    if ((int) $order->get_qty_refunded_for_item(absint($other_id)) !== 0) { $line_refund = true; break; }
                    if ($this->line_never_delivered($other)) { $undelivered_total += $this->line_paid_total($other); }
                }
                // An amount-only refund cannot be tied to a line. The natural manual refund
                // of a failed or never-sent line leaves delivered codes visible; anything
                // larger may include this line, so every code is hidden.
                if (!$line_refund && $refunded_total > $undelivered_total + 0.005) { return 'refunded'; }
            }
        } elseif ((float) $order->get_total_refunded() > 0) {
            return 'refunded';
        }
        if ((string) $item->get_meta('_dfr_delivery_authorized_at', true) !== '') { return ''; }
        if (!$this->order_has_verified_wallet_payment($order)) { return 'payment'; }
        // Pre-4.15 deliveries: remember the proof once, as apply_remote_payload() does for
        // new ones, so later views skip the ledger and attestation checks and a later salt
        // change cannot hide a code the customer was already entitled to.
        $item->update_meta_data('_dfr_delivery_authorized_at', gmdate('c'));
        $item->save();
        return '';
    }

    /** A gateway line whose supplier delivery never happened (failed, refunded or never sent). */
    private function line_never_delivered($item) {
        if (!is_object($item) || !in_array($this->item_service_type($item), array('topup', 'giftcard', 'gamekey'), true)) { return false; }
        if (in_array((string) $item->get_meta('_dfr_delivery_state', true), array('failed', 'refunded'), true)) { return true; }
        if (trim((string) $item->get_meta('_dfr_remote_order_id', true)) === '') { return true; }
        return in_array($this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true)), array('failed', 'refunded'), true);
    }

    /** What the customer paid for a line, tax included. */
    private function line_paid_total($item) {
        $total = is_callable(array($item, 'get_total')) ? (float) $item->get_total() : 0.0;
        $tax = is_callable(array($item, 'get_total_tax')) ? (float) $item->get_total_tax() : 0.0;
        return max(0.0, $total + $tax);
    }

    /** Customer copy for a code that exists but cannot be shown; reasons from customer_delivery_block_reason(). */
    private function withheld_code_message(array $reasons) {
        if (array_intersect($reasons, array('refunded', 'cancelled'))) {
            return __('Code indisponible : cette commande a été remboursée ou annulée. Contactez le support.', 'delicat-fazercards');
        }
        if (in_array('on_hold', $reasons, true)) {
            return __('Code momentanément indisponible : votre commande est en cours de vérification. Réessayez plus tard ou contactez le support.', 'delicat-fazercards');
        }
        return __('Code momentanément indisponible. Contactez le support avec ce numéro de commande.', 'delicat-fazercards');
    }

    /** App decrypt budget, separate from the website reveal bucket. */
    private function app_codes_budget_allowed($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        if ($user_id < 1) { return false; }
        $key = 'dfr_app_codes_rl_' . $user_id;
        $lock_name = 'dfr_app_codes_rl_' . $user_id;
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) !== '1') { return false; }
        try {
            $row = get_transient($key);
            $count = is_array($row) ? absint($row['count'] ?? 0) : 0;
            if ($count >= 60) { return false; }
            return (bool) set_transient($key, array('count' => $count + 1), 10 * MINUTE_IN_SECONDS);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * `delicat_app_order_codes` provider: gateway codes in the app's order detail.
     *
     * Before v4.15 nothing answered this filter for gateway orders, so a delivered gift
     * card showed "livrée" in the app with no code. The `pins` context (a bulk list of 25
     * orders polled by the app) never decrypts.
     */
    public function app_order_codes($codes, $order, $context = 'order') {
        $codes = is_array($codes) ? $codes : array();
        // Bulk contexts never decrypt and never spend the read budget; they get counts
        // from the delivery summary instead.
        if ($context === 'pins' || $context === 'summary' || !$order || !is_object($order)) { return $codes; }
        $user_id = get_current_user_id();
        if ($user_id < 1 || (int) $order->get_customer_id() !== $user_id) { return $codes; }
        $lines = array();
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!in_array($this->item_service_type($item), array('giftcard', 'gamekey'), true)) { continue; }
            if ((string) $item->get_meta('_dfr_codes_enc', true) === '') { continue; }
            if (!$this->customer_delivery_visible($order, $item_id, $item, $user_id)) { continue; }
            $lines[$item_id] = $item;
        }
        if (!$lines) { return $codes; }
        // The order screen re-reads the same order every few seconds while a sibling line
        // is still on its way. Charge the budget once per customer and order per window,
        // so watching one order can no longer make its delivered code vanish.
        $pass_key = 'dfr_app_codes_seen_' . absint($user_id) . '_' . absint($order->get_id());
        if (!get_transient($pass_key)) {
            if (!$this->app_codes_budget_allowed($user_id)) { return $codes; }
            set_transient($pass_key, 1, 10 * MINUTE_IN_SECONDS);
        }
        foreach ($lines as $item) {
            foreach ($this->item_code_entries($item) as $entry) {
                $codes[] = array(
                    'label'      => $entry['label'],
                    'code'       => $entry['code'],
                    'redeem_url' => '',
                    'status'     => 'delivered',
                );
            }
        }
        $note_key = 'dfr_app_code_note_' . absint($order->get_id());
        if (!get_transient($note_key)) {
            set_transient($note_key, 1, HOUR_IN_SECONDS);
            $order->add_order_note(__('Codes numériques affichés dans l’application par le client (aucune valeur enregistrée).', 'delicat-fazercards'));
        }
        return $codes;
    }

    /**
     * Delivery state of a gateway order, for the app and the push texts.
     *
     * @return array{state:string,label:string,message:string,codes_available:int,reference:string,items:array,can_contact_support:bool}
     */
    private function delivery_summary($order, $light = false) {
        // Lists ask twice per order (status label + summary) and details three times. The
        // key covers every value read below, so a change within the request (a code stored,
        // a status change during a push) is never answered from an old summary.
        $memo_key = $this->delivery_summary_fingerprint($order, $light);
        if ($memo_key !== '' && isset($this->delivery_summary_memo[$memo_key])) {
            return $this->delivery_summary_memo[$memo_key];
        }
        $summary = $this->build_delivery_summary($order, $light);
        if ($memo_key !== '') {
            if (count($this->delivery_summary_memo) >= 100) { $this->delivery_summary_memo = array(); }
            $this->delivery_summary_memo[$memo_key] = $summary;
        }
        return $summary;
    }

    /**
     * @param bool $light List/pins context: counts codes from stored counts, never decrypts.
     */
    private function build_delivery_summary($order, $light = false) {
        $labels = array(
            'awaiting_payment'    => array(__('En attente de paiement', 'delicat-fazercards'), __('Finalisez le paiement pour lancer la livraison.', 'delicat-fazercards')),
            'queued'              => array(__('Paiement confirmé', 'delicat-fazercards'), __('Préparation de votre livraison…', 'delicat-fazercards')),
            'supplier_processing' => array(__('En cours de livraison', 'delicat-fazercards'), __('Livraison en cours, généralement en moins d’une minute.', 'delicat-fazercards')),
            'waiting_code'        => array(__('En cours de livraison', 'delicat-fazercards'), __('Transaction confirmée — récupération sécurisée de votre code.', 'delicat-fazercards')),
            'delivered'           => array(__('Livrée', 'delicat-fazercards'), __('Votre commande est livrée.', 'delicat-fazercards')),
            'partial'             => array(__('Partiellement livrée', 'delicat-fazercards'), __('Une partie de votre commande est encore en préparation.', 'delicat-fazercards')),
            'needs_support'       => array(__('En vérification', 'delicat-fazercards'), __('La livraison n’a pas pu être finalisée automatiquement. Notre équipe vérifie votre commande ; aucun nouveau paiement n’est nécessaire.', 'delicat-fazercards')),
            'refunded'            => array(__('Remboursée', 'delicat-fazercards'), __('Cette commande a été remboursée.', 'delicat-fazercards')),
            'cancelled'           => array(__('Annulée', 'delicat-fazercards'), __('Cette commande a été annulée.', 'delicat-fazercards')),
        );
        $item_labels = array(
            'awaiting_payment'    => __('En attente de paiement', 'delicat-fazercards'),
            'queued'              => __('Préparation', 'delicat-fazercards'),
            'supplier_processing' => __('Envoi en cours', 'delicat-fazercards'),
            'waiting_code'        => __('Récupération du code', 'delicat-fazercards'),
            'delivered_code'      => __('Code disponible', 'delicat-fazercards'),
            'delivered_topup'     => __('Recharge livrée', 'delicat-fazercards'),
            'failed'              => __('Livraison à vérifier', 'delicat-fazercards'),
            'delivered_manual'    => __('Livrée par notre équipe', 'delicat-fazercards'),
            'refunded'            => __('Remboursée', 'delicat-fazercards'),
        );
        $paid = $order->is_paid() || $order->has_status(array('processing', 'completed', 'on-hold'));
        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $items = array();
        $counts = array();
        $codes_available = 0;
        $pending_manual = false;
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $type = $this->item_service_type($item);
            if (!in_array($type, array('topup','giftcard','gamekey'), true)) {
                if (!$order->has_status('completed') && !$this->non_gateway_line_fulfilled($item, $order)) { $pending_manual = true; }
                continue;
            }
            $remote = trim((string) $item->get_meta('_dfr_remote_order_id', true));
            $remote_status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
            $line_state = (string) $item->get_meta('_dfr_delivery_state', true);
            $needs_code = in_array($type, array('giftcard','gamekey'), true);
            // Lists never decrypt: a stored envelope counts as a code there.
            $has_code = $needs_code && ($light
                ? (string) $item->get_meta('_dfr_codes_enc', true) !== ''
                : $this->item_has_delivery_codes($item));
            $manual = false;
            if ($line_state === 'refunded') {
                $state = 'refunded';
            } elseif ($line_state === 'manual') {
                // Delivered by hand after a failure: settled, never « En vérification » again.
                $state = 'delivered';
                $manual = true;
            } elseif ($line_state === 'failed' || ($remote !== '' && in_array($remote_status, array('failed','refunded'), true))) {
                $state = 'failed';
            } elseif ($remote !== '' && $remote_status === 'completed' && (!$needs_code || $has_code)) {
                $state = 'delivered';
            } elseif ($remote !== '' && $remote_status === 'completed') {
                $state = 'waiting_code';
            } elseif ($remote !== '') {
                $state = 'supplier_processing';
            } else {
                $state = $paid ? 'queued' : 'awaiting_payment';
            }
            $counts[$state] = ($counts[$state] ?? 0) + 1;
            $visible = 0;
            if ($state === 'delivered' && !$manual && $needs_code && $has_code && $user_id > 0 && $this->customer_delivery_visible($order, $item_id, $item, $user_id)) {
                $visible = max(1, absint($item->get_meta('_dfr_codes_count', true)));
                $codes_available += $visible;
            }
            $label_key = $state === 'delivered'
                ? ($manual ? 'delivered_manual' : ($needs_code ? 'delivered_code' : 'delivered_topup'))
                : $state;
            $row = array(
                'item_id'         => absint($item_id),
                'type'            => $type,
                'state'           => $state,
                'label'           => $item_labels[$label_key] ?? '',
                'codes_available' => $visible,
            );
            $ready_at = (string) $item->get_meta('_dfr_delivery_ready_at', true);
            if ($ready_at !== '') { $row['ready_at'] = $ready_at; }
            $items[] = $row;
        }
        $total = array_sum($counts);
        // Lines the team refunded by hand are settled and leave the delivered count.
        $active = $total - (int) ($counts['refunded'] ?? 0);
        if ($order->has_status('refunded')) {
            $state = 'refunded';
        } elseif ($order->has_status(array('cancelled', 'failed'))) {
            $state = 'cancelled';
        } elseif (!empty($counts['failed'])) {
            $state = 'needs_support';
        } elseif ($total > 0 && $active === 0) {
            $state = 'refunded';
        } elseif ($active > 0 && (int) ($counts['delivered'] ?? 0) === $active) {
            $state = $pending_manual ? 'partial' : 'delivered';
        } elseif (!empty($counts['delivered'])) {
            $state = 'partial';
        } elseif (!empty($counts['waiting_code'])) {
            $state = 'waiting_code';
        } elseif (!empty($counts['supplier_processing'])) {
            $state = 'supplier_processing';
        } elseif (!empty($counts['queued'])) {
            $state = 'queued';
        } else {
            $state = 'awaiting_payment';
        }
        // The wallet debit could not be proven at payment time: nothing will be sent until
        // the team checks it, so « Préparation… » would be untrue.
        if (in_array($state, array('queued', 'awaiting_payment'), true)
            && (string) $order->get_meta('_dfr_attestation_refused', true) !== ''
            && trim((string) $order->get_meta('_dfr_wallet_attestation_sig', true)) === '') {
            $state = 'needs_support';
        }
        $message = $labels[$state][1];
        if ($state === 'delivered' && $codes_available > 0) {
            $message = __('Votre code est disponible ci-dessous.', 'delicat-fazercards');
        }
        return array(
            'state'               => $state,
            'label'               => $labels[$state][0],
            'message'             => $message,
            'codes_available'     => $codes_available,
            'reference'           => 'DS-' . $order->get_order_number(),
            'items'               => $items,
            'can_contact_support' => in_array($state, array('needs_support', 'partial', 'waiting_code'), true),
        );
    }

    /** `delicat_app_order_delivery`: full summary for detail/push, short one for lists. */
    public function app_order_delivery($value, $order, $context = 'detail') {
        if (!$order || !is_object($order) || !$this->order_has_gateway_items($order)) { return $value; }
        // 'summary' (order lists, /pins): counts only, never a decrypt or a read-budget charge.
        $summary = $this->delivery_summary($order, $context === 'summary');
        if ($context === 'summary') {
            return array('state' => $summary['state'], 'label' => $summary['label'], 'codes_available' => $summary['codes_available']);
        }
        return $summary;
    }

    /** `delicat_app_order_status_label`: say what delivery is doing, not just "Processing". */
    public function app_order_status_label($label, $order) {
        if (!$order || !is_object($order) || !$order->has_status(array('processing', 'on-hold', 'completed'))) { return $label; }
        if (!$this->order_has_gateway_items($order)) { return $label; }
        $summary = $this->delivery_summary($order, true);
        return in_array($summary['state'], array('queued', 'supplier_processing', 'waiting_code', 'partial', 'needs_support', 'delivered'), true)
            ? $summary['label']
            : $label;
    }

    /** `delicat_app_order_timeline`: delivery-aware steps; keys stay those app 4.0.0 knows. */
    public function app_order_timeline($steps, $order) {
        if (!is_array($steps) || !$order || !is_object($order) || !$this->order_has_gateway_items($order)) { return $steps; }
        $summary = $this->delivery_summary($order);
        $delivered = $summary['state'] === 'delivered';
        $sent = $delivered || in_array($summary['state'], array('supplier_processing', 'waiting_code', 'partial'), true);
        $has_code_line = false;
        $has_topup_line = false;
        foreach ($summary['items'] as $row) {
            if ($row['type'] === 'topup') { $has_topup_line = true; } else { $has_code_line = true; }
        }
        foreach ($steps as &$step) {
            if (!is_array($step) || empty($step['key'])) { continue; }
            if ($step['key'] === 'preparing') {
                $step['title'] = __('Envoi en cours', 'delicat-fazercards');
                $step['done'] = $sent;
                if ($summary['state'] === 'waiting_code') {
                    $step['subtitle'] = __('Transaction confirmée — récupération sécurisée de votre code.', 'delicat-fazercards');
                }
            } elseif ($step['key'] === 'delivered') {
                if ($has_code_line && !$has_topup_line) {
                    $step['title'] = __('Code disponible', 'delicat-fazercards');
                } elseif ($has_topup_line && !$has_code_line) {
                    $step['title'] = __('Recharge livrée', 'delicat-fazercards');
                }
                $step['done'] = $delivered;
            }
        }
        unset($step);
        if ($summary['state'] === 'needs_support') {
            $steps[] = array(
                'key'      => 'delivery_review',
                'title'    => __('Livraison à vérifier', 'delicat-fazercards'),
                'subtitle' => __('Notre équipe vérifie votre commande. Aucun nouveau paiement n’est nécessaire.', 'delicat-fazercards'),
                'done'     => true,
            );
        }
        return $steps;
    }

    /** Hash of every value delivery_summary() reads; '' when the order cannot be keyed. */
    private function delivery_summary_fingerprint($order, $light) {
        if (!$order || !is_object($order) || !method_exists($order, 'get_id') || !absint($order->get_id())) { return ''; }
        $parts = array(
            absint($order->get_id()),
            (string) $order->get_status(),
            $light ? 'light' : 'full',
            (string) get_current_user_id(),
            (string) ($this->settings['customer_codes_enabled'] ?? '1'),
            is_callable(array($order, 'get_total_refunded')) ? (string) $order->get_total_refunded() : '',
            (string) $order->get_meta('_dfr_gateway_hold', true),
            (string) $order->get_meta('_dfr_attestation_refused', true),
            (trim((string) $order->get_meta('_dfr_wallet_attestation_sig', true)) !== '') ? '1' : '0',
        );
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!is_object($item)) { continue; }
            $parts[] = implode('|', array(
                absint($item_id),
                $this->item_service_type($item),
                (string) $item->get_meta('_dfr_remote_order_id', true),
                (string) $item->get_meta('_dfr_remote_status', true),
                (string) $item->get_meta('_dfr_delivery_state', true),
                (string) $item->get_meta('_dfr_codes_count', true),
                strlen((string) $item->get_meta('_dfr_codes_enc', true)),
                (string) $item->get_meta('_dfr_delivery_authorized_at', true),
                (string) $item->get_meta('_dfr_delivery_ready_at', true),
                is_callable(array($order, 'get_qty_refunded_for_item')) ? (string) $order->get_qty_refunded_for_item(absint($item_id)) : '',
            ));
        }
        return hash('sha256', implode("\n", $parts));
    }

    /**
     * `woocommerce_order_status_changed`, priority 1. The manual-review hold marker belongs
     * to the gateway's own On hold only: any other status change removes it, so an On hold
     * the owner sets later (for example a fraud check) blocks again. Leaving On hold for
     * Processing also re-arms read-only checks for lines already at the supplier.
     */
    public function clear_gateway_hold_on_status_change($order_id, $from, $to, $order = null) {
        if ($this->internal_status_change) { return; }
        if (!is_object($order) && function_exists('wc_get_order')) { $order = wc_get_order($order_id); }
        if (!$order || !is_object($order)) { return; }
        if ((string) $order->get_meta('_dfr_gateway_hold', true) !== '') {
            $order->delete_meta_data('_dfr_gateway_hold');
            $order->save_meta_data();
        }
        if ($from === 'on-hold' && $to === 'processing' && $this->order_has_gateway_items($order)) {
            $this->rearm_in_flight_lines($order);
        }
    }

    /** Re-arm GET-only checks for lines already at the supplier whose status or code is still open. */
    private function rearm_in_flight_lines($order) {
        if (!$order || !is_object($order)) { return; }
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!in_array($this->item_service_type($item), array('topup', 'giftcard', 'gamekey'), true)) { continue; }
            $remote = trim((string) $item->get_meta('_dfr_remote_order_id', true));
            if (!preg_match('/^ord-[0-9]+$/', $remote)) { continue; }
            $status = $this->normalize_supplier_status($item->get_meta('_dfr_remote_status', true));
            if (in_array($status, array('failed', 'refunded'), true)) { continue; }
            if ($status === 'completed' && (!$this->item_requires_delivery_code($item) || $this->item_has_delivery_codes($item))) { continue; }
            $this->arm_reconciliation_plan($order->get_id(), $item_id, $remote);
        }
    }

    /**
     * Status as stored right now. WooCommerce caches orders for the whole request, so an
     * order another worker changed still shows its old status here; read the table.
     */
    private function persisted_order_status($order) {
        $order_id = (is_object($order) && method_exists($order, 'get_id')) ? absint($order->get_id()) : 0;
        if (!$order_id) { return ''; }
        global $wpdb;
        $status = null;
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
            $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
                && is_callable(array('\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled'))
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            if ($hpos) {
                $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id));
            } elseif (!empty($wpdb->posts)) {
                $status = $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id));
            }
        }
        if (is_string($status) && $status !== '') {
            return sanitize_key(strpos($status, 'wc-') === 0 ? substr($status, 3) : $status);
        }
        $fresh = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        return sanitize_key((string) ($fresh ? $fresh->get_status() : $order->get_status()));
    }

    /**
     * The wallet debit of a gateway order could not be proven at payment time. Nothing will
     * ever be sent on its own: flag the order, tell the customer once and alert the owner
     * once, instead of leaving « Paiement confirmé — Préparation… » forever.
     */
    private function flag_attestation_refused($order, $reason) {
        if (!$order || !is_object($order) || !$this->order_has_gateway_items($order)) { return; }
        if ((string) $order->get_meta('_dfr_attestation_refused', true) !== '') { return; }
        $order->update_meta_data('_dfr_attestation_refused', gmdate('c'));
        $order->update_meta_data('_dfr_attestation_refused_reason', substr(sanitize_key((string) $reason), 0, 60));
        $order->save_meta_data();
        $order->add_order_note(__('Votre commande doit être vérifiée par notre équipe avant la livraison. Nous revenons vers vous rapidement ; aucun nouveau paiement n’est nécessaire.', 'delicat-fazercards'), true);
        $this->schedule_action('dfr_send_attestation_refusal_alert', array(absint($order->get_id())), 0);
    }

    /** Owner e-mail for a refused wallet attestation, once per order. */
    public function send_attestation_refusal_alert($order_id) {
        if (!function_exists('wc_get_order')) { return; }
        $order = wc_get_order(absint($order_id));
        if (!$order || (string) $order->get_meta('_dfr_attestation_refused', true) === '') { return; }
        if ((string) $order->get_meta('_dfr_attestation_alert_sent', true) !== '') { return; }
        // A later wallet event proved the payment after all.
        if (trim((string) $order->get_meta('_dfr_wallet_attestation_sig', true)) !== '') { return; }
        $to = (string) apply_filters('dfr_delivery_failure_alert_email', get_option('admin_email'), $order);
        if (!is_email($to)) { return; }
        $link = admin_url('post.php?post=' . absint($order->get_id()) . '&action=edit');
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && is_callable(array('\Automattic\WooCommerce\Utilities\OrderUtil', 'get_order_admin_edit_url'))) {
            $link = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url(absint($order->get_id()));
        }
        $subject = sprintf('[Delicat] Paiement à vérifier — commande #%s', $order->get_order_number());
        $body = "Le débit du portefeuille de cette commande n'a pas pu être prouvé automatiquement. Rien n'a été envoyé au fournisseur.\n\n"
            . 'Commande : #' . $order->get_order_number() . "\n"
            . 'Raison : ' . sanitize_key((string) $order->get_meta('_dfr_attestation_refused_reason', true)) . "\n\n"
            . "Vérifiez le débit TeraWallet du client, puis livrez à la main ou remboursez-le. Aucun remboursement automatique n'a été fait.\n\n"
            . $link . "\n";
        if (wp_mail($to, $subject, $body)) {
            $order->update_meta_data('_dfr_attestation_alert_sent', gmdate('c'));
            $order->save_meta_data();
        }
    }

    /**
     * `woocommerce_check_cart_items`: quantity rules checked only on add are checked again
     * before checkout, for quantities changed later (cart page, shared session): one unit
     * per top-up line, and the supplier min/max of imported products.
     */
    public function validate_cart_supplier_quantities() {
        if (!function_exists('WC') || !WC() || !WC()->cart || !function_exists('wc_add_notice')) { return; }
        foreach ((array) WC()->cart->get_cart() as $cart_item) {
            $product_id = absint($cart_item['product_id'] ?? 0);
            $variation_id = absint($cart_item['variation_id'] ?? 0);
            $quantity = (int) ($cart_item['quantity'] ?? 0);
            $type = $variation_id ? sanitize_key((string) get_post_meta($variation_id, '_dfr_service_type', true)) : '';
            if (!in_array($type, array('topup','giftcard','gamekey'), true) && $product_id) {
                $type = sanitize_key((string) get_post_meta($product_id, '_dfr_service_type', true));
            }
            if (!in_array($type, array('topup','giftcard','gamekey'), true)) { continue; }
            $product = (isset($cart_item['data']) && is_object($cart_item['data']) && is_callable(array($cart_item['data'], 'get_name'))) ? $cart_item['data'] : null;
            $name = $product ? wp_strip_all_tags((string) $product->get_name()) : __('ce produit', 'delicat-fazercards');
            if ($type === 'topup' && $quantity > 1) {
                /* translators: %s: product name */
                wc_add_notice(sprintf(__('« %s » : une recharge se commande à l’unité. Pour en acheter une autre, ajoutez-la de nouveau au panier.', 'delicat-fazercards'), $name), 'error');
                continue;
            }
            // Same source as validate_native_fields(): the variation when it was imported, else the product.
            $limits_id = ($variation_id && get_post_meta($variation_id, '_dfr_imported', true) === '1') ? $variation_id : $product_id;
            if (!$limits_id || get_post_meta($limits_id, '_dfr_imported', true) !== '1') { continue; }
            $min = max(1, (int) get_post_meta($limits_id, '_dfr_min_order_quantity', true));
            $max = max($min, (int) get_post_meta($limits_id, '_dfr_max_order_quantity', true));
            if ($quantity < $min || $quantity > $max) {
                /* translators: 1: product name, 2: minimum, 3: maximum */
                wc_add_notice(sprintf(__('« %1$s » : la quantité doit être comprise entre %2$d et %3$d.', 'delicat-fazercards'), $name, $min, $max), 'error');
            }
        }
    }

    /** Builder V9 maintenance lock: the signed supplier webhook stays reachable. */
    public function maintenance_open_rest_routes($routes, $route = '') {
        $routes = is_array($routes) ? $routes : array();
        $routes[] = '/delicat-gateway/v1/webhook';
        return $routes;
    }

    /** Builder V9 maintenance lock: paid-order loopbacks (single-use random tokens) keep running. */
    public function maintenance_allowed_ajax($actions, $action = '') {
        $actions = is_array($actions) ? $actions : array();
        $actions[] = 'dfr_internal_reconcile';
        $actions[] = 'dfr_internal_fulfill';
        return $actions;
    }

    /** Item meta holding envelopes that the key-ring migration moves. */
    private function keyring_item_meta_keys() {
        return array('_dfr_codes_enc', '_dfr_delivery_enc', '_dfr_fulfill_request_enc', '_dfr_checkout_snapshot_enc');
    }

    /** Crypto context an item envelope was sealed with; '' when it cannot be rebuilt. */
    private function keyring_item_context($meta_key, $order_id, $item_id) {
        switch ((string) $meta_key) {
            case '_dfr_codes_enc':
                return $this->code_crypto_context($order_id, $item_id);
            case '_dfr_delivery_enc':
                return $this->delivery_structure_context($order_id, $item_id);
            case '_dfr_fulfill_request_enc':
                return $this->fulfillment_context($order_id, $item_id);
            case '_dfr_checkout_snapshot_enc':
                if (!function_exists('wc_get_order_item_meta')) { return ''; }
                $line_context_id = strtolower(trim((string) wc_get_order_item_meta($item_id, '_dfr_checkout_context_id', true)));
                if (!preg_match('/^[a-f0-9]{40}$/', $line_context_id)) { return ''; }
                $product_id = absint(wc_get_order_item_meta($item_id, '_product_id', true));
                $variation_id = absint(wc_get_order_item_meta($item_id, '_variation_id', true));
                return 'checkout-snapshot|line:' . $line_context_id . '|product:' . $product_id . '|variation:' . $variation_id;
        }
        return '';
    }

    /** Id of the primary key of DELICAT_DFR_KEYS; '' without a ring. */
    private function keyring_primary_id() {
        if (!class_exists('DFR_Crypto') || !DFR_Crypto::has_keyring()) { return ''; }
        if (method_exists('DFR_Crypto', 'primary_key_id')) {
            return preg_replace('/[^A-Za-z0-9]/', '', (string) DFR_Crypto::primary_key_id());
        }
        // The envelope names its key: x3:<kid>:...
        $probe = DFR_Crypto::encrypt_context('keyring-probe', 'keyring-probe');
        if (!is_string($probe) || !preg_match('/^[xg]3:([A-Za-z0-9]+):/', $probe, $match)) { return ''; }
        return $match[1];
    }

    /**
     * Salt-bound values still waiting for the key ring: item envelopes not sealed with the
     * primary ring key, plus stored supplier secrets. Cached five minutes.
     */
    private function keyring_legacy_count($fresh = false) {
        $kid = $this->keyring_primary_id();
        if ($kid === '') { return 0; }
        $cache_key = 'dfr_keyring_legacy_count_' . $kid;
        if (!$fresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) { return absint($cached); }
        }
        global $wpdb;
        $keys = $this->keyring_item_meta_keys();
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $count = absint($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key IN ({$placeholders}) AND meta_value <> '' AND meta_value NOT LIKE %s AND meta_value NOT LIKE %s",
            array_merge($keys, array($wpdb->esc_like('x3:' . $kid . ':') . '%', $wpdb->esc_like('g3:' . $kid . ':') . '%'))
        )));
        $stored = get_option('dfr_settings', array());
        foreach (array('api_key_enc' => 'DELICAT_FAZER_API_KEY', 'webhook_secret_enc' => 'DELICAT_FAZER_WEBHOOK_SECRET') as $setting_key => $constant) {
            if (defined($constant) && constant($constant)) { continue; }
            $value = (is_array($stored) && isset($stored[$setting_key])) ? (string) $stored[$setting_key] : '';
            if ($value !== '' && !DFR_Crypto::is_current($value)) { $count++; }
        }
        set_transient($cache_key, $count, 5 * MINUTE_IN_SECONDS);
        return $count;
    }

    /**
     * With a key ring configured, data written before it stays salt-bound until moved.
     * An administrator's visit starts or resumes the background move; cheap once done.
     */
    public function maybe_start_keyring_migration() {
        if (!class_exists('DFR_Crypto') || !DFR_Crypto::has_keyring() || !$this->can_manage()) { return; }
        if (get_transient('dfr_keyring_migration_kick')) { return; }
        $kid = $this->keyring_primary_id();
        if ($kid === '') { return; }
        $state = get_option('dfr_keyring_migration_v1', array());
        $same_key = is_array($state) && ($state['kid'] ?? '') === $kid;
        if ($same_key && ($state['status'] ?? '') === 'done') {
            set_transient('dfr_keyring_migration_kick', 1, HOUR_IN_SECONDS);
            return;
        }
        set_transient('dfr_keyring_migration_kick', 1, 10 * MINUTE_IN_SECONDS);
        $round = $same_key ? absint($state['round'] ?? 0) + 1 : 1;
        $this->schedule_action('dfr_keyring_migration_batch', array($kid, $round), time() + 5);
    }

    /**
     * One background batch: re-seal up to 100 salt-bound item envelopes (codes, delivery
     * structures, fulfilment and checkout snapshots) and the stored supplier secrets under
     * the primary ring key. Runs only while the salts still open v2 data. A value is
     * replaced only when it decrypts to something and is still the stored value; plaintext
     * is never written, and item meta is written directly (no order save, no status hook).
     * Re-sealing does not change the plaintext digests the wallet attestation binds.
     */
    public function run_keyring_migration_batch($kid = '', $round = 1) {
        if (!class_exists('DFR_Crypto') || !DFR_Crypto::has_keyring() || !function_exists('update_metadata')) { return; }
        $primary = $this->keyring_primary_id();
        if ($primary === '' || (string) $kid !== $primary) { return; }
        if (DFR_Crypto::salt_health() !== 'keyring') { return; }
        if (get_transient('dfr_keyring_migration_lock')) { return; }
        set_transient('dfr_keyring_migration_lock', 1, 2 * MINUTE_IN_SECONDS);
        try {
            $state = get_option('dfr_keyring_migration_v1', array());
            if (!is_array($state) || ($state['kid'] ?? '') !== $primary) {
                $state = array('kid' => $primary, 'last_meta_id' => 0, 'moved' => 0, 'unreadable' => 0, 'round' => 0, 'started_at' => gmdate('c'));
            }
            $state['status'] = 'running';
            $state['round'] = max(absint($state['round'] ?? 0), absint($round));

            $this->rewrap_setting_secrets();

            // Paid orders in flight get their key-ring attestation signature now, while the
            // salt signature still verifies (see maybe_add_ring_attestation()).
            if (absint($state['last_meta_id'] ?? 0) === 0 && method_exists('DFR_Crypto', 'keyring_hmac') && function_exists('wc_get_orders')) {
                $open_orders = wc_get_orders(array('status' => array('processing', 'on-hold'), 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects'));
                foreach ((array) $open_orders as $open_order) {
                    if ($open_order) { $this->order_has_verified_wallet_payment($open_order); }
                }
            }

            global $wpdb;
            $keys = $this->keyring_item_meta_keys();
            $placeholders = implode(',', array_fill(0, count($keys), '%s'));
            $meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
            $items_table = $wpdb->prefix . 'woocommerce_order_items';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT m.meta_id, m.order_item_id, m.meta_key, m.meta_value, i.order_id
                 FROM {$meta_table} m
                 INNER JOIN {$items_table} i ON i.order_item_id = m.order_item_id
                 WHERE m.meta_key IN ({$placeholders}) AND m.meta_id > %d
                 ORDER BY m.meta_id ASC LIMIT 100",
                array_merge($keys, array(absint($state['last_meta_id'] ?? 0)))
            ));
            $rows = is_array($rows) ? $rows : array();
            foreach ($rows as $row) {
                $state['last_meta_id'] = absint($row->meta_id);
                $value = (string) $row->meta_value;
                if ($value === '' || DFR_Crypto::is_current($value)) { continue; }
                $item_id = absint($row->order_item_id);
                $meta_key = (string) $row->meta_key;
                $context = $this->keyring_item_context($meta_key, absint($row->order_id), $item_id);
                $plain = $context !== '' ? DFR_Crypto::decrypt_context($value, $context) : '';
                if (!is_string($plain) || $plain === '') {
                    // Never overwrite what cannot be read; it stays counted as salt-bound.
                    $state['unreadable'] = absint($state['unreadable'] ?? 0) + 1;
                    continue;
                }
                $sealed = DFR_Crypto::encrypt_context($plain, $context);
                if (!is_string($sealed) || $sealed === '' || !DFR_Crypto::is_current($sealed)) { continue; }
                if (function_exists('wc_update_order_item_meta')) {
                    $updated = wc_update_order_item_meta($item_id, $meta_key, $sealed, $value);
                } else {
                    $updated = update_metadata('order_item', $item_id, $meta_key, $sealed, $value);
                }
                if (!$updated) { continue; }
                $state['moved'] = absint($state['moved'] ?? 0) + 1;
                if ($meta_key === '_dfr_codes_enc') {
                    if (function_exists('wc_update_order_item_meta')) {
                        wc_update_order_item_meta($item_id, '_dfr_codes_crypto', 'aead-v3');
                    } else {
                        update_metadata('order_item', $item_id, '_dfr_codes_crypto', 'aead-v3');
                    }
                }
            }

            if (count($rows) < 100) {
                $state['status'] = 'done';
                $state['finished_at'] = gmdate('c');
                update_option('dfr_keyring_migration_v1', $state, false);
                $this->record_admin_audit('fazercards_keyring_migration', array(
                    'moved'      => absint($state['moved'] ?? 0),
                    'unreadable' => absint($state['unreadable'] ?? 0),
                    'remaining'  => $this->keyring_legacy_count(true),
                ));
                return;
            }
            update_option('dfr_keyring_migration_v1', $state, false);
            $this->schedule_action('dfr_keyring_migration_batch', array($primary, absint($round) + 1), time() + 20);
        } finally {
            delete_transient('dfr_keyring_migration_lock');
        }
    }

    /**
     * Re-seal the stored supplier API key and webhook secret under the key ring. Skipped
     * for values defined as wp-config constants; never writes an empty value.
     */
    private function rewrap_setting_secrets() {
        if (!class_exists('DFR_Crypto') || !DFR_Crypto::has_keyring()) { return; }
        $stored = get_option('dfr_settings', array());
        if (!is_array($stored)) { return; }
        $rewrapped = array();
        foreach (array(
            'api_key_enc'        => array('supplier-api-key', 'DELICAT_FAZER_API_KEY'),
            'webhook_secret_enc' => array('supplier-webhook-secret', 'DELICAT_FAZER_WEBHOOK_SECRET'),
        ) as $setting_key => $spec) {
            if (defined($spec[1]) && constant($spec[1])) { continue; }
            $value = isset($stored[$setting_key]) ? (string) $stored[$setting_key] : '';
            if ($value === '' || DFR_Crypto::is_current($value)) { continue; }
            $plain = $this->decrypt_setting_secret($value, $spec[0]);
            if ($plain === '') { continue; }
            $sealed = DFR_Crypto::encrypt_context($plain, $spec[0]);
            if (!is_string($sealed) || $sealed === '' || !DFR_Crypto::is_current($sealed)) { continue; }
            $stored[$setting_key] = $sealed;
            $rewrapped[] = str_replace('_enc', '', $setting_key);
        }
        if (!$rewrapped) { return; }
        $settings = wp_parse_args($stored, self::defaults());
        update_option('dfr_settings', $settings, false);
        $this->settings = $settings;
        $this->record_admin_audit('fazercards_keyring_secrets', array('rewrapped' => implode(',', $rewrapped)));
    }

}
