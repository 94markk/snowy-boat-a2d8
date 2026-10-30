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
        // Player-ID check (public). The old action name stays registered so
        // pages cached before the update keep working.
        foreach (array('topup_verify_player', 'ftr_check_id') as $action) {
            add_action('wp_ajax_' . $action, array($this, 'check_id'));
            add_action('wp_ajax_nopriv_' . $action, array($this, 'check_id'));
        }
        // Direct supplier orders debit the wallet without any customer
        // payment, so they are restricted to administrators. Customers buy
        // through WooCommerce checkout, which places the order once paid.
        add_action('wp_ajax_ftr_order', array($this, 'order'));

        // Admin-only (stepped import + balance preview + diagnostics).
        add_action('wp_ajax_ftr_check_balance', array($this, 'check_balance'));
        add_action('wp_ajax_ftr_import_prepare', array($this, 'import_prepare'));
        add_action('wp_ajax_ftr_import_step', array($this, 'import_step'));
        add_action('wp_ajax_ftr_sync_prepare', array($this, 'sync_prepare'));
        add_action('wp_ajax_ftr_sync_step', array($this, 'sync_step'));
        add_action('wp_ajax_ftr_woo_selftest', array($this, 'woo_selftest'));
        add_action('wp_ajax_ftr_sync_now', array($this, 'sync_now'));
    }

    /** Public JSON error with the supplier's name removed. */
    private function public_error($message)
    {
        wp_send_json_error(array('message' => FTR_Privacy::scrub($message)));
    }

    /**
     * Basic per-IP throttle for the public check endpoint so bots cannot use
     * this site to hammer the supplier API (which could get the key blocked).
     */
    private function throttle($bucket, $limit, $window)
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $key = 'ftr_rl_' . $bucket . '_' . md5($ip);
        $hits = (int) get_transient($key);
        if ($hits >= $limit) {
            return false;
        }
        set_transient($key, $hits + 1, $window);
        return true;
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
        if (!current_user_can('manage_options') && !$this->throttle('check', 15, 60)) {
            wp_send_json_error(array('message' => 'Too many attempts. Please wait a minute and try again.'));
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
            // Never forward raw supplier/API diagnostics to visitors.
            $msg = isset($res['raw']['message']) && is_string($res['raw']['message']) ? $res['raw']['message']
                : (isset($res['raw']['error']['message']) && is_string($res['raw']['error']['message']) ? $res['raw']['error']['message'] : '');
            if ($msg === '' || $res['status'] === 0 || $res['status'] >= 500 || $res['status'] === 401 || $res['status'] === 403) {
                $msg = 'Impossible de vérifier cet ID pour le moment. Réessayez.';
            }
            $this->public_error($msg);
        }
        $d = is_array($res['data']) ? $res['data'] : array();
        $name = '';
        foreach (array('account_name', 'username', 'nickname', 'name') as $k) {
            if (!empty($d[$k]) && is_scalar($d[$k])) {
                $name = sanitize_text_field((string) $d[$k]);
                break;
            }
        }
        wp_send_json_success(array(
            'account_name' => $name,
            'message'      => $name !== '' ? ('Vérifié : ' . $name) : 'Compte vérifié.',
        ));
    }

    public function order()
    {
        $this->verify();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Please buy this product through the shop checkout.'));
        }
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
        foreach ((array) $product['fields'] as $f) {
            if (!empty($f['required']) && isset($f['name']) && empty($fields[$f['name']])) {
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
            $items[$code] = FTR_Sync::catalog_item($p);
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
            $services = FTR_Sync::services_from_response($sresp);
            if ($services !== null) {
                $item['services'] = $services;
                if (empty($item['services'])) {
                    $error = 'The API returned 0 packages for this product — nothing to create.';
                }
            } else {
                $error = 'Could not load packages: ' . ($api_msg !== '' ? $api_msg : 'unknown API error') . '.';
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
        update_option(FTR_PRODUCTS, $store, false);

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

    /**
     * Prepare a full refresh of already-imported products (manual
     * "Synchronize" button). Matching rules live in FTR_Sync::build_queue().
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

        $plan = FTR_Sync::build_queue($resp['data'], $stored);
        update_option('ftr_sync_queue_' . get_current_user_id(), array('items' => $plan['items'], 'missing' => $plan['missing']), false);
        wp_send_json_success(array(
            'total' => count($plan['items']),
            'missing' => $plan['missing'],
            'remapped_products' => $plan['remapped_products'],
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

        $result = FTR_Sync::sync_item($queue['items'][$index]);
        if (!empty($result['fatal'])) {
            wp_send_json_error(array('message' => $result['error']));
        }
        wp_send_json_success($result);
    }

    /** Run one background-sync slice right now (Sync tab button). */
    public function sync_now()
    {
        $this->verify_admin();
        delete_option(FTR_Sync::STATE);
        $r = FTR_Sync::run_catalog_sync(25);
        FTR_Sync::sweep_pending_orders();
        $last = get_option(FTR_Sync::LAST, array());
        if (!empty($r['skipped'])) {
            wp_send_json_error(array('message' => 'A synchronization is already running. Try again in a minute.'));
        }
        if (!empty($r['error'])) {
            wp_send_json_error(array('message' => isset($last['error']) ? $last['error'] : 'Synchronization failed.'));
        }
        wp_send_json_success(array(
            'done'    => !empty($r['done']),
            'message' => !empty($r['done'])
                ? sprintf('Synchronized %d product(s), %d error(s), %d missing from the supplier catalog.', isset($last['ok']) ? $last['ok'] : 0, isset($last['errors']) ? count($last['errors']) : 0, isset($last['missing']) ? $last['missing'] : 0)
                : sprintf('Synchronized %d of %d products; the rest continue in the background.', isset($r['pos']) ? $r['pos'] : 0, isset($r['total']) ? $r['total'] : 0),
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
            if (!empty($presp['ok'])) {
                $presp['data'] = FTR_API::list_of($presp['data']);
            }
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
                    $pkgs = FTR_Sync::services_from_response($sresp);
                    $probe['package_count'] = is_array($pkgs) ? count($pkgs) : 0;
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
