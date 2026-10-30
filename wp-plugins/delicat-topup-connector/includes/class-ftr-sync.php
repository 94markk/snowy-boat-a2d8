<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the store synchronized with the supplier without anyone clicking a
 * button:
 *
 *  - Catalog: a recurring background pass (default every 5 minutes) refreshes
 *    prices, stock, packages and fields of every imported product. Large
 *    catalogs are processed in time-boxed slices that resume on the next run.
 *  - Checkout: products in the cart are re-checked live before payment when
 *    their data is older than two minutes (FTR_Woo::refresh_cart_prices).
 *  - Catalog webhooks: the supplier currently documents only order.updated;
 *    product/service/price/stock events, if ever sent, trigger an immediate
 *    refresh of the affected product(s).
 *  - Orders: a sweep every minute polls the bulk status endpoint for every
 *    placed-but-not-final supplier order, on top of webhooks and per-order
 *    reconciliation, so WooCommerce reflects delivery within about a minute
 *    even when a webhook is lost.
 *
 * WP-Cron only runs when the site gets traffic. For exact timing, point a
 * real server cron job at wp-cron.php (instructions on the Sync tab).
 */
class FTR_Sync
{
    const CRON_CATALOG  = 'ftr_auto_catalog_sync';
    const CRON_ORDERS   = 'ftr_pending_orders_sweep';
    const CRON_PRODUCTS = 'ftr_sync_products';
    const STATE         = 'ftr_auto_sync_state';
    const LAST          = 'ftr_auto_sync_last';
    const SWEEP_LAST    = 'ftr_orders_sweep_last';
    const MISSING       = 'ftr_missing_counts';
    const LOCK          = 'ftr_catalog_sync_lock';
    const SWEEP_LOCK    = 'ftr_orders_sweep_lock';
    const PASS_MAX_AGE  = 3600;   // restart a catalog pass that never finished
    const SWEEP_WINDOW  = 259200; // poll orders placed in the last 3 days
    const MISSING_LIMIT = 2;      // consecutive full passes before a product is disabled

    public function __construct()
    {
        add_action(self::CRON_CATALOG, array(__CLASS__, 'run_catalog_sync'));
        add_action(self::CRON_ORDERS, array(__CLASS__, 'sweep_pending_orders'));
        add_action(self::CRON_PRODUCTS, array(__CLASS__, 'sync_codes'), 10, 1);
    }

    /* ---------------- Scheduling ---------------- */

    public static function cron_schedules($schedules)
    {
        $schedules['ftr_every_minute'] = array('interval' => 60, 'display' => 'Every minute (top-up sync)');
        $schedules['ftr_catalog_interval'] = array('interval' => self::interval(), 'display' => 'Top-up catalog sync interval');
        return $schedules;
    }

    public static function interval()
    {
        $s = ftr_settings();
        $minutes = isset($s['sync_interval']) ? (int) $s['sync_interval'] : 5;
        return max(1, min(1440, $minutes)) * 60;
    }

    /** (Re)create recurring events so they match the current settings. */
    public static function schedule()
    {
        $s = ftr_settings();

        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_CATALOG) : false;
        if (empty($s['auto_sync'])) {
            wp_clear_scheduled_hook(self::CRON_CATALOG);
        } elseif (!$event || (int) $event->interval !== self::interval()) {
            wp_clear_scheduled_hook(self::CRON_CATALOG);
            wp_schedule_event(time() + 30, 'ftr_catalog_interval', self::CRON_CATALOG);
        }

        if (!wp_next_scheduled(self::CRON_ORDERS)) {
            wp_schedule_event(time() + 60, 'ftr_every_minute', self::CRON_ORDERS);
        }
    }

    public static function unschedule()
    {
        wp_clear_scheduled_hook(self::CRON_CATALOG);
        wp_clear_scheduled_hook(self::CRON_ORDERS);
        wp_clear_scheduled_hook(self::CRON_PRODUCTS);
    }

    /** Run a sync job as soon as possible without blocking the current request. */
    public static function queue_now($hook, $args = array())
    {
        if (!wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event(time(), $hook, $args);
        }
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /* ---------------- Supplier data helpers ---------------- */

    public static function to_bool($value, $default = true)
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value > 0;
        }
        $v = strtolower(trim((string) $value));
        if (in_array($v, array('false', 'no', 'off', 'out_of_stock', 'outofstock', 'inactive', 'disabled', 'unavailable'), true)) {
            return false;
        }
        return true;
    }

    /** Catalog row → local store item (packages are filled in separately). */
    public static function catalog_item($p)
    {
        $code = isset($p['product_code']) ? trim((string) $p['product_code']) : '';
        return array(
            'product_code'    => $code,
            'product_id'      => isset($p['product_id']) ? $p['product_id'] : null,
            'name'            => isset($p['name']) ? $p['name'] : $code,
            'image_url'       => isset($p['image_url']) ? $p['image_url'] : '',
            'product_type'    => isset($p['product_type']) ? $p['product_type'] : 'topup',
            'validation_code' => isset($p['validation_code']) ? $p['validation_code'] : '',
            'check_id_status' => isset($p['check_id_status']) ? $p['check_id_status'] : 'inactive',
            'fields'          => isset($p['fields']) && is_array($p['fields']) ? $p['fields'] : array(),
            'server'          => isset($p['server']) && is_array($p['server']) ? $p['server'] : array(),
            'services'        => array(),
        );
    }

    /**
     * Extract packages from a /services response. Returns null when the
     * response is not usable (so callers can tell "error" from "empty").
     */
    public static function services_from_response($sresp)
    {
        if (empty($sresp['ok'])) {
            return null;
        }
        $data = isset($sresp['data']) ? $sresp['data'] : null;
        $list = null;
        if (is_array($data)) {
            foreach (array('service', 'services') as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    $list = $data[$key];
                    break;
                }
            }
            if ($list === null) {
                $list = FTR_API::list_of($data);
            }
        }
        if (!is_array($list)) {
            return null;
        }
        $out = array();
        foreach ($list as $svc) {
            if (!is_array($svc) || empty($svc['service_code'])) {
                continue;
            }
            $out[] = array(
                'service_code' => (string) $svc['service_code'],
                'service_name' => !empty($svc['service_name']) ? (string) $svc['service_name'] : (string) $svc['service_code'],
                'price'        => isset($svc['price']) ? $svc['price'] : '0',
                'currency'     => isset($svc['currency']) ? $svc['currency'] : 'USD',
                'max_quantity' => isset($svc['max_quantity']) ? (int) $svc['max_quantity'] : 1,
                'in_stock'     => self::to_bool(isset($svc['in_stock']) ? $svc['in_stock'] : (isset($svc['status']) ? $svc['status'] : null)),
            );
        }
        return $out;
    }

    private static function identity_value($value)
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function identity_name($value)
    {
        $value = remove_accents(wp_strip_all_tags((string) $value));
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim((string) $value);
    }

    private static function identity_key($item)
    {
        $name = self::identity_name(isset($item['name']) ? $item['name'] : '');
        $type = sanitize_key(isset($item['product_type']) ? (string) $item['product_type'] : '');
        return $name !== '' ? ($type . '|' . $name) : '';
    }

    /**
     * Match every already-imported product to the current supplier catalog.
     * Matching order: current product_code -> stored supplier product_id ->
     * unique name+type. The last match is uniqueness-guarded to avoid
     * attaching a Woo product to the wrong supplier item.
     *
     * @return array{items:array,missing:array,remapped_products:int}
     */
    public static function build_queue(array $catalog, array $stored)
    {
        $by_code = array();
        $by_id = array();
        $by_identity = array();
        foreach ($catalog as $raw) {
            if (!is_array($raw)) continue;
            $item = self::catalog_item($raw);
            if ($item['product_code'] === '') continue;
            $by_code[$item['product_code']] = $item;
            $sid = self::identity_value($item['product_id']);
            if ($sid !== '') {
                $by_id[$sid][] = $item;
            }
            $ikey = self::identity_key($item);
            if ($ikey !== '') {
                $by_identity[$ikey][] = $item;
            }
        }

        $queue = array();
        $missing = array();
        $remapped = 0;
        foreach ($stored as $old_code => $old_item) {
            if (!is_array($old_item)) continue;
            $old_code = (string) $old_code;
            $match = isset($by_code[$old_code]) ? $by_code[$old_code] : null;
            $reason = $match ? 'product_code' : '';

            if (!$match) {
                $old_id = self::identity_value(isset($old_item['product_id']) ? $old_item['product_id'] : '');
                if ($old_id !== '' && isset($by_id[$old_id]) && count($by_id[$old_id]) === 1) {
                    $match = $by_id[$old_id][0];
                    $reason = 'product_id';
                }
            }
            if (!$match) {
                $ikey = self::identity_key($old_item);
                if ($ikey !== '' && isset($by_identity[$ikey]) && count($by_identity[$ikey]) === 1) {
                    $match = $by_identity[$ikey][0];
                    $reason = 'name_type';
                }
            }
            if (!$match) {
                $missing[] = array('code' => $old_code, 'name' => isset($old_item['name']) ? (string) $old_item['name'] : $old_code);
                continue;
            }

            $match['_ftr_previous_product_code'] = $old_code;
            $match['_ftr_previous_product_id'] = isset($old_item['product_id']) ? $old_item['product_id'] : '';
            $match['_ftr_match_reason'] = $reason;
            if ($old_code !== $match['product_code'] || self::identity_value(isset($old_item['product_id']) ? $old_item['product_id'] : '') !== self::identity_value($match['product_id'])) {
                $remapped++;
            }
            $queue[] = $match;
        }

        return array('items' => $queue, 'missing' => $missing, 'remapped_products' => $remapped);
    }

    /**
     * Refresh one matched product: fetch its packages, update WooCommerce and
     * commit the local store entry. Shared by the manual Synchronize button,
     * the background pass and catalog webhooks.
     */
    public static function sync_item(array $item, $api = null)
    {
        $api = $api ? $api : new FTR_API();
        $services = self::services_from_response($api->services($item['product_code'], $item['product_type'], 1, 500));
        if ($services === null) {
            return array('name' => $item['name'], 'error' => 'Could not refresh packages for ' . $item['name'] . '.', 'fatal' => true);
        }
        if (empty($services)) {
            return array('name' => $item['name'], 'error' => 'The supplier returned 0 packages for ' . $item['name'] . '. Existing WooCommerce packages were left untouched.', 'fatal' => true);
        }
        $item['services'] = $services;

        $previous_code = isset($item['_ftr_previous_product_code']) ? (string) $item['_ftr_previous_product_code'] : '';
        $woo_active = FTR_Woo::active();
        $result = $woo_active ? FTR_Woo::sync_one($item) : array('variations' => 0, 'error' => null);

        // Do not replace a known-good local mapping if Woo synchronization
        // failed. Without WooCommerce the shortcode/API flow still works, so
        // committing the refreshed supplier store is fine.
        if (!$woo_active || empty($result['error'])) {
            $store = get_option(FTR_PRODUCTS, array());
            if (!is_array($store)) $store = array();
            if ($previous_code !== '' && $previous_code !== $item['product_code']) {
                unset($store[$previous_code]);
            }
            $clean = $item;
            unset($clean['_ftr_previous_product_code'], $clean['_ftr_previous_product_id'], $clean['_ftr_match_reason']);
            $store[$item['product_code']] = $clean;
            update_option(FTR_PRODUCTS, $store, false);
        }

        return array(
            'name' => $item['name'],
            'product_code' => $item['product_code'],
            'supplier_product_id' => self::identity_value($item['product_id']),
            'match_reason' => isset($item['_ftr_match_reason']) ? $item['_ftr_match_reason'] : '',
            'product_remapped' => ($previous_code !== '' && $previous_code !== $item['product_code']) || (self::identity_value(isset($item['_ftr_previous_product_id']) ? $item['_ftr_previous_product_id'] : '') !== self::identity_value($item['product_id'])),
            'services' => count($services),
            'variations' => isset($result['variations']) ? (int) $result['variations'] : 0,
            'remapped_services' => isset($result['remapped_services']) ? (int) $result['remapped_services'] : 0,
            'orphaned_variations' => isset($result['orphaned_variations']) ? (int) $result['orphaned_variations'] : 0,
            'error' => isset($result['error']) ? $result['error'] : null,
        );
    }

    /**
     * Products absent from the supplier catalog for MISSING_LIMIT consecutive
     * full passes are made unavailable (all variations out of stock). One
     * absent pass is tolerated so a supplier-side glitch cannot empty the shop.
     */
    private static function handle_missing(array $missing)
    {
        $previous = get_option(self::MISSING, array());
        if (!is_array($previous)) $previous = array();
        $counts = array();
        foreach ($missing as $m) {
            $code = (string) $m['code'];
            $counts[$code] = (isset($previous[$code]) ? (int) $previous[$code] : 0) + 1;
            if ($counts[$code] === self::MISSING_LIMIT && FTR_Woo::active()) {
                $n = FTR_Woo::disable_supplier_product($code);
                FTR_Woo::log('Product "' . $m['name'] . '" (' . $code . ') is no longer in the supplier catalog — ' . $n . ' package(s) set out of stock.');
            }
        }
        update_option(self::MISSING, $counts, false);
    }

    /* ---------------- Background catalog sync ---------------- */

    /**
     * One time-boxed slice of the background catalog pass. A pass starts with
     * a single catalog download, then refreshes products until the time
     * budget runs out; the next run continues where this one stopped.
     */
    public static function run_catalog_sync($budget = 20)
    {
        $budget = is_numeric($budget) && $budget > 0 ? (float) $budget : 20.0;
        $s = ftr_settings();
        if (get_transient(self::LOCK)) {
            return array('skipped' => 'locked');
        }
        set_transient(self::LOCK, 1, 120);

        $api = new FTR_API($s);
        $state = get_option(self::STATE, array());
        if (!is_array($state)) $state = array();
        if (!empty($state['started']) && (time() - (int) $state['started']) > self::PASS_MAX_AGE) {
            $state = array();
        }

        try {
            if (empty($state['queue'])) {
                $stored = get_option(FTR_PRODUCTS, array());
                if (!is_array($stored) || empty($stored)) {
                    self::record_last(array('message' => 'Nothing to synchronize — no imported products yet.'));
                    return array('done' => true);
                }
                if (!$api->is_configured()) {
                    self::record_last(array('error' => 'API credentials are not configured.'));
                    return array('error' => true);
                }
                $resp = $api->all_products(500);
                if (empty($resp['ok']) || !is_array($resp['data'])) {
                    self::record_last(array('error' => 'Catalog download failed: ' . (isset($resp['message']) ? $resp['message'] : 'unknown error')));
                    return array('error' => true);
                }
                $plan = self::build_queue($resp['data'], $stored);
                self::handle_missing($plan['missing']);
                $state = array(
                    'queue'   => $plan['items'],
                    'pos'     => 0,
                    'started' => time(),
                    'ok'      => 0,
                    'errors'  => array(),
                    'missing' => count($plan['missing']),
                );
                update_option(self::STATE, $state, false);
            }

            $start = microtime(true);
            $total = count($state['queue']);
            while ($state['pos'] < $total && (microtime(true) - $start) < $budget) {
                // Keep a reserve of the 1800-requests/10-min quota for order
                // status checks; the pass resumes on the next run.
                $remaining = FTR_API::rate_remaining();
                if ($remaining !== null && $remaining < 300) {
                    break;
                }
                $r = self::sync_item($state['queue'][$state['pos']], $api);
                $state['pos']++;
                if (!empty($r['error'])) {
                    $state['errors'][] = $r['error'];
                } else {
                    $state['ok']++;
                }
                update_option(self::STATE, $state, false);
            }

            if ($state['pos'] >= $total) {
                self::record_last(array(
                    'products' => $total,
                    'ok'       => (int) $state['ok'],
                    'errors'   => array_slice($state['errors'], 0, 10),
                    'missing'  => (int) $state['missing'],
                    'duration' => time() - (int) $state['started'],
                ));
                delete_option(self::STATE);
                return array('done' => true);
            }
            return array('done' => false, 'pos' => $state['pos'], 'total' => $total);
        } catch (\Throwable $e) {
            FTR_Woo::log('Background catalog sync error: ' . $e->getMessage(), 'error');
            self::record_last(array('error' => $e->getMessage()));
            return array('error' => true);
        } finally {
            delete_transient(self::LOCK);
        }
    }

    private static function record_last(array $info)
    {
        $info['at'] = time();
        update_option(self::LAST, $info, false);
    }

    /**
     * Immediate refresh of specific products (catalog webhook). Uses the
     * stored product metadata and refreshes its packages; product-level
     * changes (fields, check-ID) follow on the next background pass.
     */
    public static function sync_codes($codes)
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', (array) $codes))));
        $store = get_option(FTR_PRODUCTS, array());
        if (!is_array($store) || empty($store)) {
            return;
        }
        if (empty($codes)) {
            // Event without a product reference: start a full pass now.
            delete_option(self::STATE);
            self::run_catalog_sync(25);
            return;
        }
        $api = new FTR_API();
        foreach ($codes as $code) {
            if (!isset($store[$code]) || !is_array($store[$code])) {
                continue; // not imported here
            }
            $item = $store[$code];
            $item['services'] = array();
            $r = self::sync_item($item, $api);
            FTR_Woo::log('Webhook-triggered refresh of "' . $item['name'] . '": ' . (!empty($r['error']) ? $r['error'] : ($r['variations'] . ' package(s) updated.')), !empty($r['error']) ? 'error' : 'info');
        }
    }

    /** Map supplier service codes to imported product codes. */
    public static function product_codes_for_services(array $service_codes)
    {
        $codes = array();
        foreach (array_filter(array_map('strval', $service_codes)) as $svc) {
            $ids = get_posts(array(
                'post_type'   => array('product_variation', 'product'),
                'post_status' => 'any',
                'numberposts' => 1,
                'fields'      => 'ids',
                'meta_key'    => '_ftr_service_code',
                'meta_value'  => $svc,
            ));
            if (!empty($ids)) {
                $pc = (string) get_post_meta((int) $ids[0], '_ftr_product_code', true);
                if ($pc !== '') {
                    $codes[] = $pc;
                }
            }
        }
        return array_values(array_unique($codes));
    }

    /* ---------------- Pending order sweep ---------------- */

    /**
     * Poll the supplier for every placed order that is not final yet and
     * apply the result. Runs every minute; one bulk request covers up to 50
     * orders. Falls back to single lookups if the bulk endpoint fails.
     */
    public static function sweep_pending_orders()
    {
        if (!FTR_Woo::active() || get_transient(self::SWEEP_LOCK)) {
            return;
        }
        set_transient(self::SWEEP_LOCK, 1, 55);

        try {
            $items = self::pending_items(50);
            if (empty($items)) {
                update_option(self::SWEEP_LAST, array('at' => time(), 'checked' => 0, 'updated' => 0), false);
                return;
            }
            $api = new FTR_API();
            if (!$api->is_configured()) {
                return;
            }

            $by_ref = array();
            foreach ($items as $row) {
                $by_ref['wc_' . $row['order_id'] . '_' . $row['item_id']] = $row;
            }

            $updated = 0;
            $seen = array();
            $res = $api->order_status_bulk(array(), array_keys($by_ref));
            if (!empty($res['ok'])) {
                foreach (FTR_API::list_of($res['data']) as $entry) {
                    $ref = isset($entry['reference_id']) ? (string) $entry['reference_id'] : '';
                    $row = isset($by_ref[$ref]) ? $by_ref[$ref] : null;
                    if (!$row && !empty($entry['order_id'])) {
                        foreach ($by_ref as $candidate) {
                            if ($candidate['provider_id'] !== '' && $candidate['provider_id'] === (string) $entry['order_id']) {
                                $row = $candidate;
                                break;
                            }
                        }
                    }
                    if (!$row) continue;
                    $seen[$row['item_id']] = true;
                    if (FTR_Woo::apply_provider_update($row['order_id'], $row['item_id'], $entry, 'sync')) {
                        $updated++;
                    }
                }
            } else {
                // Bulk endpoint unavailable: check the 10 most recent singly.
                foreach (array_slice($items, 0, 10) as $row) {
                    $one = $api->order_status($row['provider_id'], 'wc_' . $row['order_id'] . '_' . $row['item_id']);
                    if (empty($one['ok'])) continue;
                    $d = is_array($one['data']) ? $one['data'] : array();
                    if (isset($d['data']) && is_array($d['data'])) $d = $d['data'];
                    $seen[$row['item_id']] = true;
                    if (FTR_Woo::apply_provider_update($row['order_id'], $row['item_id'], $d, 'sync')) {
                        $updated++;
                    }
                }
            }

            update_option(self::SWEEP_LAST, array('at' => time(), 'checked' => count($seen), 'updated' => $updated, 'error' => empty($res['ok']) ? (string) $res['message'] : ''), false);
        } catch (\Throwable $e) {
            FTR_Woo::log('Pending order sweep error: ' . $e->getMessage(), 'error');
        } finally {
            delete_transient(self::SWEEP_LOCK);
        }
    }

    /**
     * Line items that were sent to the supplier recently and are not yet
     * completed or failed. Order item tables are shared by HPOS and legacy
     * storage, so this works for both.
     */
    public static function pending_items($limit = 50)
    {
        global $wpdb;
        $since = time() - self::SWEEP_WINDOW;
        $sql = "SELECT oi.order_item_id AS item_id, oi.order_id AS order_id,
                       COALESCE(st.meta_value, '') AS status, COALESCE(po.meta_value, '') AS provider_id
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pa
                        ON pa.order_item_id = oi.order_item_id AND pa.meta_key = '_ftr_placed_at'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta st
                        ON st.order_item_id = oi.order_item_id AND st.meta_key = '_ftr_status'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta po
                        ON po.order_item_id = oi.order_item_id AND po.meta_key = '_ftr_order_id'
                WHERE oi.order_item_type = 'line_item'
                  AND CAST(pa.meta_value AS UNSIGNED) >= %d
                  AND (st.meta_value IS NULL OR st.meta_value NOT IN ('completed', 'failed'))
                ORDER BY oi.order_item_id DESC
                LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $since, (int) $limit), ARRAY_A);
        $out = array();
        foreach ((array) $rows as $r) {
            $out[] = array(
                'item_id'     => (int) $r['item_id'],
                'order_id'    => (int) $r['order_id'],
                'status'      => (string) $r['status'],
                'provider_id' => (string) $r['provider_id'],
            );
        }
        return $out;
    }
}
