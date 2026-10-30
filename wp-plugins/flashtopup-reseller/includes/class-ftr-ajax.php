<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Front-end AJAX: check player id + place order. All API calls happen here,
 * server-side and signed — the API secret never reaches the browser.
 */
class FTR_Ajax
{
    public function __construct()
    {
        add_action('wp_ajax_ftr_check_id', array($this, 'check_id'));
        add_action('wp_ajax_nopriv_ftr_check_id', array($this, 'check_id'));
        add_action('wp_ajax_ftr_order', array($this, 'order'));
        add_action('wp_ajax_nopriv_ftr_order', array($this, 'order'));

        // Admin-only (stepped import + balance preview + diagnostics).
        add_action('wp_ajax_ftr_check_balance', array($this, 'check_balance'));
        add_action('wp_ajax_ftr_import_prepare', array($this, 'import_prepare'));
        add_action('wp_ajax_ftr_import_step', array($this, 'import_step'));
        add_action('wp_ajax_ftr_sync_prepare', array($this, 'sync_prepare'));
        add_action('wp_ajax_ftr_sync_step', array($this, 'sync_step'));
        add_action('wp_ajax_ftr_woo_selftest', array($this, 'woo_selftest'));
    }

    private function verify()
    {
        check_ajax_referer('ftr_front', 'nonce');
    }

    private function verify_admin()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You are not allowed to do this.'));
        }
        check_ajax_referer('ftr_admin', 'nonce');
    }

    private function product($code)
    {
        $products = get_option(FTR_PRODUCTS, array());
        return isset($products[$code]) ? $products[$code] : null;
    }

    /** Collect the dynamic field values (user_id, server_id, ...) declared by the product. */
    private function collect_fields($product)
    {
        $values = array();
        if (empty($product['fields']) || !is_array($product['fields'])) {
            return $values;
        }
        foreach ($product['fields'] as $f) {
            $name = isset($f['name']) ? $f['name'] : '';
            if ($name === '') {
                continue;
            }
            $values[$name] = isset($_POST['fields'][$name])
                ? sanitize_text_field(wp_unslash($_POST['fields'][$name]))
                : '';
        }
        return $values;
    }

    public function check_id()
    {
        $this->verify();
        $settings = ftr_settings();
        if (empty($settings['check_id'])) {
            wp_send_json_error(array('message' => 'ID check is disabled.'));
        }

        $code = isset($_POST['product_code']) ? sanitize_text_field(wp_unslash($_POST['product_code'])) : '';
        $product = $this->product($code);
        if (!$product) {
            wp_send_json_error(array('message' => 'Unknown product.'));
        }
        if (empty($product['validation_code']) || (isset($product['check_id_status']) && $product['check_id_status'] !== 'active')) {
            wp_send_json_error(array('message' => 'This product does not support ID checking.'));
        }

        $fields = $this->collect_fields($product);
        $user_id   = isset($fields['user_id']) ? $fields['user_id'] : (isset($_POST['user_id']) ? sanitize_text_field(wp_unslash($_POST['user_id'])) : '');
        $server_id = isset($fields['server_id']) ? $fields['server_id'] : (isset($_POST['server_id']) ? sanitize_text_field(wp_unslash($_POST['server_id'])) : '');

        if ($user_id === '') {
            wp_send_json_error(array('message' => 'Enter the User ID first.'));
        }

        $api = new FTR_API($settings);
        $res = $api->check_id($product['validation_code'], $user_id, $server_id);

        if (empty($res['ok'])) {
            wp_send_json_error(array('message' => $res['message']));
        }
        $name = isset($res['data']['account_name']) ? $res['data']['account_name'] : '';
        wp_send_json_success(array(
            'account_name' => $name,
            'message'      => $name !== '' ? ('Verified: ' . $name) : 'Account verified.',
        ));
    }

    public function order()
    {
        $this->verify();
        $settings = ftr_settings();

        $code = isset($_POST['product_code']) ? sanitize_text_field(wp_unslash($_POST['product_code'])) : '';
        $service_code = isset($_POST['service_code']) ? sanitize_text_field(wp_unslash($_POST['service_code'])) : '';
        $quantity = isset($_POST['quantity']) ? max(1, (int) $_POST['quantity']) : 1;

        $product = $this->product($code);
        if (!$product) {
            wp_send_json_error(array('message' => 'Unknown product.'));
        }
        if ($service_code === '') {
            wp_send_json_error(array('message' => 'Please choose a package.'));
        }

        // Validate required fields client-supplied against the product's field spec.
        $fields = $this->collect_fields($product);
        foreach ($product['fields'] as $f) {
            if (!empty($f['required']) && empty($fields[$f['name']])) {
                wp_send_json_error(array('message' => sprintf('Please fill "%s".', isset($f['label']) ? $f['label'] : $f['name'])));
            }
        }

        $reference_id = 'wp_' . get_current_blog_id() . '_' . time() . '_' . wp_generate_password(6, false, false);

        $api = new FTR_API($settings);
        $res = $api->order($service_code, $reference_id, $quantity, $fields);

        if (empty($res['ok'])) {
            wp_send_json_error(array('message' => $res['message']));
        }

        $d = is_array($res['data']) ? $res['data'] : array();
        wp_send_json_success(array(
            'order_id'     => isset($d['order_id']) ? $d['order_id'] : '',
            'reference_id' => $reference_id,
            'status'       => isset($d['order_status']) ? $d['order_status'] : (isset($d['status']) ? $d['status'] : 'Processing'),
            'amount'       => isset($d['amount']) ? $d['amount'] : '',
            'currency'     => isset($d['currency']) ? $d['currency'] : ($settings['currency'] ?? 'USD'),
            'sandbox'      => !empty($settings['sandbox']),
            'message'      => 'Order placed successfully.',
        ));
    }

    /* ---------------- Admin: balance preview ---------------- */

    public function check_balance()
    {
        $this->verify_admin();
        $api = new FTR_API();
        if (!$api->is_configured()) {
            wp_send_json_error(array('message' => 'Enter your API ID and secret on the Settings page first.'));
        }
        $res = $api->balance();
        if (empty($res['ok'])) {
            wp_send_json_error(array('message' => $res['message']));
        }
        $d = is_array($res['data']) ? $res['data'] : array();
        $s = ftr_settings();
        wp_send_json_success(array(
            'balance'  => isset($d['balance']) ? $d['balance'] : '0',
            'symbol'   => isset($d['currency_symbol']) ? $d['currency_symbol'] : '',
            'currency' => isset($d['currency']) ? $d['currency'] : ($s['currency'] ?? ''),
            'sandbox'  => !empty($s['sandbox']),
        ));
    }

    /* ---------------- Admin: stepped import with progress ---------------- */

    public function import_prepare()
    {
        $this->verify_admin();
        $selected      = isset($_POST['products']) && is_array($_POST['products']) ? array_map('sanitize_text_field', wp_unslash($_POST['products'])) : array();
        $with_services = !empty($_POST['with_services']);
        // WooCommerce products are variable products built from packages, so
        // packages are mandatory — always fetch them when WooCommerce is active.
        if (FTR_Woo::active()) {
            $with_services = true;
        }
        if (empty($selected)) {
            wp_send_json_error(array('message' => 'Select at least one product to import.'));
        }

        $api  = new FTR_API();
        $resp = $api->all_products(500);
        if (empty($resp['ok']) || !is_array($resp['data'])) {
            wp_send_json_error(array('message' => !empty($resp['message']) ? $resp['message'] : 'Could not load the product catalog.'));
        }

        $items = array();
        foreach ($resp['data'] as $p) {
            $code = isset($p['product_code']) ? (string) $p['product_code'] : '';
            if ($code === '' || !in_array($code, $selected, true)) {
                continue;
            }
            $items[$code] = array(
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
        if (empty($items)) {
            wp_send_json_error(array('message' => 'None of the selected products were found in the catalog.'));
        }

        // Persist to an option (more reliable than a transient under object caches).
        update_option('ftr_import_queue_' . get_current_user_id(), array('with_services' => $with_services, 'items' => $items), false);

        wp_send_json_success(array(
            'total' => count($items),
            'codes' => array_keys($items),
            'woo'   => FTR_Woo::active(),
        ));
    }

    public function import_step()
    {
        $this->verify_admin();
        $code  = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
        $queue = get_option('ftr_import_queue_' . get_current_user_id(), null);
        if (!is_array($queue) || empty($queue['items'][$code])) {
            wp_send_json_error(array('message' => 'Import session expired — please start the import again.'));
        }

        $item    = $queue['items'][$code];
        $error   = null;
        $api_msg = '';

        // Fetch the packages (services) for this game.
        if (!empty($queue['with_services'])) {
            $api   = new FTR_API();
            $sresp = $api->services($code, $item['product_type'], 1, 500);
            $api_msg = isset($sresp['message']) ? (string) $sresp['message'] : '';
            if (!empty($sresp['ok']) && isset($sresp['data']['service']) && is_array($sresp['data']['service'])) {
                foreach ($sresp['data']['service'] as $svc) {
                    if (empty($svc['service_code'])) {
                        continue;
                    }
                    $item['services'][] = array(
                        'service_code' => $svc['service_code'],
                        'service_name' => !empty($svc['service_name']) ? $svc['service_name'] : $svc['service_code'],
                        'price'        => isset($svc['price']) ? $svc['price'] : '0',
                        'currency'     => isset($svc['currency']) ? $svc['currency'] : 'USD',
                        'max_quantity' => isset($svc['max_quantity']) ? (int) $svc['max_quantity'] : 1,
                        'in_stock'     => isset($svc['in_stock']) ? (bool) $svc['in_stock'] : true,
                    );
                }
                if (empty($item['services'])) {
                    $error = 'The API returned 0 packages for this product — nothing to create.';
                }
            } elseif (empty($sresp['ok'])) {
                $error = 'Could not load packages: ' . ($api_msg !== '' ? $api_msg : 'unknown API error') . '.';
            } else {
                $error = 'The API returned no packages for this product.';
            }
        } else {
            $error = 'Tick "Import services & auto-create order fields" — packages are required to build the product.';
        }

        $services_loaded = count($item['services']);

        // Persist into the plugin's product store (used by Check-ID + shortcode).
        $store = get_option(FTR_PRODUCTS, array());
        if (!is_array($store)) {
            $store = array();
        }
        $store[$code] = $item;
        update_option(FTR_PRODUCTS, $store);

        // Create/update the WooCommerce variable product + variations.
        $variations = 0;
        if (FTR_Woo::active()) {
            if ($services_loaded > 0) {
                $r = FTR_Woo::sync_one($item);
                $variations = isset($r['variations']) ? (int) $r['variations'] : 0;
                $error = !empty($r['error']) ? $r['error'] : null;
            }
            // else: keep the "no packages" reason set above
        } else {
            $error = trim(($error ? $error . ' ' : '') . 'WooCommerce is not active — no shop product was created.');
        }

        wp_send_json_success(array(
            'name'        => $item['name'],
            'services'    => $services_loaded,
            'variations'  => $variations,
            'woo'         => FTR_Woo::active(),
            'api_message' => $api_msg,
            'error'       => $error,
        ));
    }

    /* ---------------- Admin: supplier catalog reconciliation ---------------- */

    private function catalog_item($p)
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

    private function identity_value($value)
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function identity_name($value)
    {
        $value = remove_accents(wp_strip_all_tags((string) $value));
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim((string) $value);
    }

    private function identity_key($item)
    {
        $name = $this->identity_name(isset($item['name']) ? $item['name'] : '');
        $type = sanitize_key(isset($item['product_type']) ? (string) $item['product_type'] : '');
        return $name !== '' ? ($type . '|' . $name) : '';
    }

    /**
     * Prepare a full refresh of already-imported products. Matching order:
     * current product_code -> stored supplier product_id -> unique name+type.
     * The last match is intentionally uniqueness-guarded to avoid attaching a
     * Woo product to the wrong supplier item when names are duplicated.
     */
    public function sync_prepare()
    {
        $this->verify_admin();
        $stored = get_option(FTR_PRODUCTS, array());
        if (!is_array($stored) || empty($stored)) {
            wp_send_json_error(array('message' => 'No imported products are available to synchronize.'));
        }

        $api = new FTR_API();
        $resp = $api->all_products(500);
        if (empty($resp['ok']) || !is_array($resp['data'])) {
            wp_send_json_error(array('message' => !empty($resp['message']) ? $resp['message'] : 'Could not load the supplier catalog.'));
        }

        $by_code = array();
        $by_id = array();
        $by_identity = array();
        foreach ($resp['data'] as $raw) {
            $item = $this->catalog_item($raw);
            if ($item['product_code'] === '') {
                continue;
            }
            $by_code[$item['product_code']] = $item;
            $sid = $this->identity_value($item['product_id']);
            if ($sid !== '') {
                if (!isset($by_id[$sid])) $by_id[$sid] = array();
                $by_id[$sid][] = $item;
            }
            $ikey = $this->identity_key($item);
            if ($ikey !== '') {
                if (!isset($by_identity[$ikey])) $by_identity[$ikey] = array();
                $by_identity[$ikey][] = $item;
            }
        }

        $queue = array();
        $missing = array();
        $remapped_products = 0;
        foreach ($stored as $old_code => $old_item) {
            if (!is_array($old_item)) continue;
            $old_code = (string) $old_code;
            $match = isset($by_code[$old_code]) ? $by_code[$old_code] : null;
            $match_reason = $match ? 'product_code' : '';

            if (!$match) {
                $old_id = $this->identity_value(isset($old_item['product_id']) ? $old_item['product_id'] : '');
                if ($old_id !== '' && isset($by_id[$old_id]) && count($by_id[$old_id]) === 1) {
                    $match = $by_id[$old_id][0];
                    $match_reason = 'product_id';
                }
            }
            if (!$match) {
                $ikey = $this->identity_key($old_item);
                if ($ikey !== '' && isset($by_identity[$ikey]) && count($by_identity[$ikey]) === 1) {
                    $match = $by_identity[$ikey][0];
                    $match_reason = 'name_type';
                }
            }

            if (!$match) {
                $missing[] = array('code' => $old_code, 'name' => isset($old_item['name']) ? (string) $old_item['name'] : $old_code);
                continue;
            }

            $match['_ftr_previous_product_code'] = $old_code;
            $match['_ftr_previous_product_id'] = isset($old_item['product_id']) ? $old_item['product_id'] : '';
            $match['_ftr_match_reason'] = $match_reason;
            if ($old_code !== $match['product_code'] || $this->identity_value(isset($old_item['product_id']) ? $old_item['product_id'] : '') !== $this->identity_value($match['product_id'])) {
                $remapped_products++;
            }
            $queue[] = $match;
        }

        update_option('ftr_sync_queue_' . get_current_user_id(), array('items' => $queue, 'missing' => $missing), false);
        wp_send_json_success(array(
            'total' => count($queue),
            'missing' => $missing,
            'remapped_products' => $remapped_products,
            'woo' => FTR_Woo::active(),
        ));
    }

    public function sync_step()
    {
        $this->verify_admin();
        $index = isset($_POST['index']) ? max(0, (int) $_POST['index']) : 0;
        $queue = get_option('ftr_sync_queue_' . get_current_user_id(), null);
        if (!is_array($queue) || !isset($queue['items'][$index]) || !is_array($queue['items'][$index])) {
            wp_send_json_error(array('message' => 'Synchronization session expired — start Synchronize again.'));
        }

        $item = $queue['items'][$index];
        $api = new FTR_API();
        $sresp = $api->services($item['product_code'], $item['product_type'], 1, 500);
        if (empty($sresp['ok']) || !isset($sresp['data']['service']) || !is_array($sresp['data']['service'])) {
            wp_send_json_error(array('message' => 'Could not refresh packages for ' . $item['name'] . ': ' . (!empty($sresp['message']) ? $sresp['message'] : 'unknown API error')));
        }

        foreach ($sresp['data']['service'] as $svc) {
            if (empty($svc['service_code'])) continue;
            $item['services'][] = array(
                'service_code' => $svc['service_code'],
                'service_name' => !empty($svc['service_name']) ? $svc['service_name'] : $svc['service_code'],
                'price'        => isset($svc['price']) ? $svc['price'] : '0',
                'currency'     => isset($svc['currency']) ? $svc['currency'] : 'USD',
                'max_quantity' => isset($svc['max_quantity']) ? (int) $svc['max_quantity'] : 1,
                'in_stock'     => isset($svc['in_stock']) ? (bool) $svc['in_stock'] : true,
            );
        }
        if (empty($item['services'])) {
            wp_send_json_error(array('message' => 'The supplier returned 0 packages for ' . $item['name'] . '. Existing WooCommerce packages were left untouched.'));
        }

        $previous_code = isset($item['_ftr_previous_product_code']) ? (string) $item['_ftr_previous_product_code'] : '';
        $woo_active = FTR_Woo::active();
        $result = $woo_active ? FTR_Woo::sync_one($item) : array('variations' => 0, 'error' => null);

        // Do not replace a known-good local mapping if Woo synchronization
        // failed. Without WooCommerce, the plugin can still operate through its
        // shortcode/API flow, so committing the refreshed supplier store is OK.
        if (!$woo_active || empty($result['error'])) {
            $store = get_option(FTR_PRODUCTS, array());
            if (!is_array($store)) $store = array();
            if ($previous_code !== '' && $previous_code !== $item['product_code']) {
                unset($store[$previous_code]);
            }
            $clean_store_item = $item;
            unset($clean_store_item['_ftr_previous_product_code'], $clean_store_item['_ftr_previous_product_id'], $clean_store_item['_ftr_match_reason']);
            $store[$item['product_code']] = $clean_store_item;
            update_option(FTR_PRODUCTS, $store, false);
        }
        wp_send_json_success(array(
            'name' => $item['name'],
            'product_code' => $item['product_code'],
            'supplier_product_id' => $this->identity_value($item['product_id']),
            'match_reason' => isset($item['_ftr_match_reason']) ? $item['_ftr_match_reason'] : '',
            'product_remapped' => ($previous_code !== '' && $previous_code !== $item['product_code']) || ($this->identity_value(isset($item['_ftr_previous_product_id']) ? $item['_ftr_previous_product_id'] : '') !== $this->identity_value($item['product_id'])),
            'services' => count($item['services']),
            'variations' => isset($result['variations']) ? (int) $result['variations'] : 0,
            'remapped_services' => isset($result['remapped_services']) ? (int) $result['remapped_services'] : 0,
            'orphaned_variations' => isset($result['orphaned_variations']) ? (int) $result['orphaned_variations'] : 0,
            'error' => isset($result['error']) ? $result['error'] : null,
        ));
    }

    /* ---------------- Admin: WooCommerce self-test ---------------- */

    public function woo_selftest()
    {
        $this->verify_admin();
        if (!FTR_Woo::active()) {
            wp_send_json_success(array(
                'ok'         => false,
                'woo_active' => false,
                'message'    => 'WooCommerce is NOT active. Install & activate WooCommerce, then run this test again.',
            ));
        }
        $r = FTR_Woo::selftest();
        $ok = !empty($r['product_id']) && empty($r['error']) && !empty($r['exists']);

        // Live API probe: fetch the first catalog product + its packages, so we
        // can tell whether the REAL import actually has packages to work with.
        $probe = array('ran' => false);
        $api = new FTR_API();
        if (!$api->is_configured()) {
            $probe['not_configured'] = true;
        } else {
            $presp = $api->products(1, 5);
            $probe['products_ok'] = !empty($presp['ok']);
            $probe['products_message'] = isset($presp['message']) ? $presp['message'] : '';
            if (!empty($presp['ok']) && is_array($presp['data']) && !empty($presp['data'])) {
                $first = $presp['data'][0];
                $pcode = isset($first['product_code']) ? (string) $first['product_code'] : '';
                $ptype = isset($first['product_type']) ? (string) $first['product_type'] : 'topup';
                $probe['first_product'] = isset($first['name']) ? $first['name'] : $pcode;
                $probe['first_type'] = $ptype;
                if ($pcode !== '') {
                    $sresp = $api->services($pcode, $ptype, 1, 500);
                    $probe['ran'] = true;
                    $probe['services_ok'] = !empty($sresp['ok']);
                    $probe['services_message'] = isset($sresp['message']) ? $sresp['message'] : '';
                    $probe['package_count'] = (!empty($sresp['ok']) && isset($sresp['data']['service']) && is_array($sresp['data']['service']))
                        ? count($sresp['data']['service']) : 0;
                }
            } else {
                $probe['catalog_empty'] = true;
            }
        }

        $r['probe'] = $probe;
        $r['ok'] = $ok;
        $r['message'] = $ok
            ? ('WooCommerce creation works (test product #' . $r['product_id'] . ', ' . $r['variations'] . ' variations). '
                . (!empty($probe['ran']) ? ('Live API: "' . $probe['first_product'] . '" returned ' . $probe['package_count'] . ' package(s).') : 'Live API packages were not probed.'))
            : ('Failed: ' . (!empty($r['error']) ? $r['error'] : 'the product was not created') . '.');
        wp_send_json_success($r);
    }
}
