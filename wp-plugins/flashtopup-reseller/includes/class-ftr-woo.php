<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce integration. When WooCommerce is active, imported FlashTopup
 * products become native WooCommerce VARIABLE products (one virtual/digital
 * product per game, with each service package as a "Package" variation),
 * grouped by game category — so they appear in the shop automatically with no
 * shortcode. The auto-generated order fields (text/select) + Check-ID render on
 * the product page, travel through cart → order, and the FlashTopup order is
 * placed automatically when the WooCommerce order is paid.
 */
class FTR_Woo
{
    public function __construct()
    {
        if (!self::active()) {
            return;
        }
        add_action('woocommerce_before_add_to_cart_button', array($this, 'product_fields'));
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 2);
        add_filter('woocommerce_get_item_data', array($this, 'display_cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_line_item'), 10, 4);
        add_action('woocommerce_order_status_processing', array($this, 'fulfill'), 20, 1);
        add_action('woocommerce_order_status_completed', array($this, 'fulfill'), 20, 1);
        add_action('woocommerce_order_status_processing', array($this, 'sync_completed_status'), 999, 1);
        add_action('woocommerce_payment_complete', array($this, 'sync_completed_status'), 999, 1);
        // TeraWallet uses the stable WooCommerce gateway ID `wallet` even when
        // its checkout label is renamed (for example, "Delicat Wallet").
        // Run after all normal status callbacks so a late wallet write cannot
        // leave a provider-completed order stuck in Processing.
        add_action('woocommerce_order_status_changed', array($this, 'wallet_status_changed'), 9999, 4);
        add_action('ftr_wallet_completion_check', array($this, 'wallet_completion_check'), 10, 1);
        add_action('ftr_provider_item_status_updated', array($this, 'provider_item_status_updated'), 100, 3);
        add_action('ftr_reconcile_order', array($this, 'reconcile_order'), 10, 1);
        add_action('ftr_repair_completed_orders', array($this, 'repair_completed_orders'), 10, 0);
        add_action('wp_enqueue_scripts', array($this, 'assets'));
    }

    public static function active()
    {
        return class_exists('WooCommerce');
    }

    /**
     * Log to PHP error_log (wp-content/debug.log when WP_DEBUG_LOG is on) AND to
     * the WooCommerce logger — viewable under WooCommerce → Status → Logs,
     * source "flashtopup-reseller". Used so import failures are never silent.
     */
    public static function log($message, $level = 'info')
    {
        error_log('[FlashTopup Reseller] ' . $message);
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            if ($logger) {
                $logger->log($level === 'error' ? 'error' : 'info', (string) $message, array('source' => 'flashtopup-reseller'));
            }
        }
    }

    /**
     * Convert an upstream USD price to the WooCommerce display price.
     *   final = USD_price * conversion_rate * (1 + markup%)
     * Conversion_rate defaults to 1 (keep USD); markup defaults to 0.
     */
    private static function markup_price($price)
    {
        $s = ftr_settings();
        $n = (float) $price;
        $rate = isset($s['conversion_rate']) ? (float) $s['conversion_rate'] : 1.0;
        if ($rate <= 0) {
            $rate = 1.0;
        }
        $n = $n * $rate;
        if (!empty($s['markup'])) {
            $n = $n * (1 + ((float) $s['markup']) / 100);
        }
        return round($n, 2);
    }

    /* ---------------- Sync (import → Woo products) ---------------- */

    /**
     * Create/update WooCommerce products from the imported FlashTopup store.
     * One virtual VARIABLE product per game; each service becomes a variation
     * (idempotent, matched on _ftr_service_code). Returns variations synced.
     */
    public static function sync($store)
    {
        if (!self::active() || empty($store) || !is_array($store)) {
            return 0;
        }
        $total = 0;
        foreach ($store as $p) {
            $r = self::sync_one($p);
            $total += isset($r['variations']) ? (int) $r['variations'] : 0;
        }
        return $total;
    }

    /**
     * Sync ONE game into a single virtual VARIABLE product, with a variation per
     * service. Fully guarded with try/catch — on any failure it logs the reason
     * and returns it instead of fataling the whole request.
     *
     * @return array{name:string,product_id:int,variations:int,error:?string}
     */
    public static function sync_one($p)
    {
        $name = isset($p['name']) && $p['name'] !== '' ? (string) $p['name'] : (isset($p['product_code']) ? (string) $p['product_code'] : 'product');

        if (!self::active()) {
            return array('name' => $name, 'product_id' => 0, 'variations' => 0, 'error' => 'WooCommerce is not active.');
        }
        if (empty($p['services']) || !is_array($p['services'])) {
            return array('name' => $name, 'product_id' => 0, 'variations' => 0, 'error' => 'No packages/services to import (tick "Import services & auto-create order fields").');
        }

        try {
            $code   = isset($p['product_code']) ? (string) $p['product_code'] : '';
            $cat_id = self::ensure_category($name);
            $check  = (!empty($p['check_id_status']) && $p['check_id_status'] === 'active') ? 1 : 0;
            $fields = isset($p['fields']) && is_array($p['fields']) ? $p['fields'] : array();

            // Build the "Package" variation attribute from the service names.
            $options = array();
            foreach ($p['services'] as $svc) {
                $nm = isset($svc['service_name']) ? trim((string) $svc['service_name']) : '';
                if ($nm !== '') {
                    $options[$nm] = $nm; // dedupe by name
                }
            }
            $options = array_values($options);
            if (empty($options)) {
                return array('name' => $name, 'product_id' => 0, 'variations' => 0, 'error' => 'Services returned no names.');
            }

            // Reuse the existing Woo product even when the supplier changes its
            // product ID or product_code. Sync callers may pass the previous
            // identity from our local store, so supplier-side catalog migrations
            // repair the mapping instead of creating duplicate Woo products.
            $supplier_product_id = self::identity_value(isset($p['product_id']) ? $p['product_id'] : '');
            $previous_code = isset($p['_ftr_previous_product_code']) ? (string) $p['_ftr_previous_product_code'] : '';
            $previous_supplier_product_id = self::identity_value(isset($p['_ftr_previous_product_id']) ? $p['_ftr_previous_product_id'] : '');
            $parent_id = self::find_variable_for_sync($code, $supplier_product_id, $previous_code, $previous_supplier_product_id);
            $parent    = $parent_id ? wc_get_product($parent_id) : null;
            $is_new_parent = (!$parent || !$parent->is_type('variable'));
            if ($is_new_parent) {
                $parent = new WC_Product_Variable();
            }

            $attr = new WC_Product_Attribute();
            $attr->set_id(0); // 0 = a custom (per-product) attribute, not a global taxonomy
            $attr->set_name('Package');
            $attr->set_options($options);
            $attr->set_position(0);
            $attr->set_visible(true);
            $attr->set_variation(true);

            $parent->set_name($name);
            // Safety-first catalog workflow: every NEW supplier product starts
            // as a WooCommerce draft so an administrator can review pricing,
            // images, fields and packages before it becomes customer-visible.
            // Existing products deliberately keep their current status. This
            // prevents a supplier synchronization from republishing a product
            // that an administrator intentionally set to Draft/Private/Pending.
            if ($is_new_parent) {
                $parent->set_status('draft');
            }
            $parent->set_catalog_visibility('visible');
            $parent->set_virtual(true); // digital — no shipping
            $parent->set_attributes(array($attr));
            if ($cat_id) {
                $parent->set_category_ids(array($cat_id));
            }
            $parent_id = (int) $parent->save();
            if (!$parent_id) {
                throw new Exception('WooCommerce could not save the parent product.');
            }

            $old_product_code = (string) get_post_meta($parent_id, '_ftr_product_code', true);
            $old_supplier_product_id = self::identity_value(get_post_meta($parent_id, '_ftr_supplier_product_id', true));
            if ($old_product_code !== '' && $old_product_code !== $code) {
                update_post_meta($parent_id, '_ftr_previous_product_code', $old_product_code);
            }
            if ($old_supplier_product_id !== '' && $old_supplier_product_id !== $supplier_product_id) {
                update_post_meta($parent_id, '_ftr_previous_supplier_product_id', $old_supplier_product_id);
            }
            update_post_meta($parent_id, '_ftr_product_code', $code);
            update_post_meta($parent_id, '_ftr_supplier_product_id', $supplier_product_id);
            update_post_meta($parent_id, '_ftr_product_type', isset($p['product_type']) ? sanitize_key((string) $p['product_type']) : '');
            update_post_meta($parent_id, '_ftr_last_synced_at', time());
            update_post_meta($parent_id, '_ftr_validation_code', isset($p['validation_code']) ? $p['validation_code'] : '');
            update_post_meta($parent_id, '_ftr_check_id', $check);
            update_post_meta($parent_id, '_ftr_fields', wp_json_encode($fields));
            // Shared delivery contract with Delicat Secure Keys: API top-ups
            // must never be mistaken for stored PIN products or redirected.
            update_post_meta($parent_id, '_delicat_delivery_type', 'api_topup');
            update_post_meta($parent_id, '_delicat_keyed', 'no');
            update_post_meta($parent_id, '_delicat_free_fire_pin', 'no');

            // Reconcile variations by service_code first and by unique package
            // name second. The name fallback lets us repair a supplier-side
            // service-code/ID replacement without duplicating the variation.
            $existing = self::existing_variation_records($parent_id);
            $used_variations = array();
            $made = 0;
            $remapped = 0;
            foreach ($p['services'] as $svc) {
                $svc_code = isset($svc['service_code']) ? trim((string) $svc['service_code']) : '';
                $svc_name = isset($svc['service_name']) ? trim((string) $svc['service_name']) : '';
                if ($svc_code === '' || $svc_name === '') {
                    continue;
                }
                try {
                    $vid = isset($existing['by_code'][$svc_code]) ? (int) $existing['by_code'][$svc_code] : 0;
                    if (!$vid) {
                        $name_key = self::normalize_identity_name($svc_name);
                        if ($name_key !== '' && isset($existing['by_name'][$name_key]) && count($existing['by_name'][$name_key]) === 1) {
                            $candidate = (int) $existing['by_name'][$name_key][0];
                            if (!isset($used_variations[$candidate])) {
                                $vid = $candidate;
                            }
                        }
                    }
                    $var = $vid ? new WC_Product_Variation($vid) : new WC_Product_Variation();
                    $old_service_code = $vid ? (string) get_post_meta($vid, '_ftr_service_code', true) : '';
                    $var->set_parent_id($parent_id);
                    $var->set_attributes(array('package' => $svc_name));
                    $var->set_status('publish');
                    $var->set_virtual(true);
                    $var->set_regular_price((string) self::markup_price(isset($svc['price']) ? $svc['price'] : 0));
                    $var->set_stock_status((isset($svc['in_stock']) && $svc['in_stock'] === false) ? 'outofstock' : 'instock');
                    $var_id = (int) $var->save();
                    if ($var_id) {
                        if ($old_service_code !== '' && $old_service_code !== $svc_code) {
                            update_post_meta($var_id, '_ftr_previous_service_code', $old_service_code);
                            $remapped++;
                        }
                        update_post_meta($var_id, '_ftr_service_code', $svc_code);
                        update_post_meta($var_id, '_ftr_service_name', $svc_name);
                        update_post_meta($var_id, '_ftr_product_code', $code);
                        update_post_meta($var_id, '_ftr_supplier_product_id', $supplier_product_id);
                        update_post_meta($var_id, '_ftr_supplier_price', isset($svc['price']) ? (string) $svc['price'] : '0');
                        update_post_meta($var_id, '_ftr_supplier_currency', isset($svc['currency']) ? sanitize_text_field((string) $svc['currency']) : 'USD');
                        update_post_meta($var_id, '_ftr_max_quantity', isset($svc['max_quantity']) ? max(1, (int) $svc['max_quantity']) : 1);
                        update_post_meta($var_id, '_ftr_last_synced_at', time());
                        delete_post_meta($var_id, '_ftr_sync_orphaned');
                        update_post_meta($var_id, '_delicat_delivery_type', 'api_topup');
                        update_post_meta($var_id, '_delicat_keyed', 'no');
                        update_post_meta($var_id, '_delicat_free_fire_pin', 'no');
                        $used_variations[$var_id] = true;
                        $made++;
                    }
                } catch (\Throwable $e) {
                    self::log('Variation "' . $svc_name . '" failed for "' . $name . '": ' . $e->getMessage(), 'error');
                }
            }

            // A full sync must not leave removed supplier packages purchasable.
            // Keep the variation object for order-history integrity, but make it
            // unavailable and flag it for administrators/audits.
            $orphaned = 0;
            foreach ($existing['all'] as $existing_vid) {
                $existing_vid = (int) $existing_vid;
                if (!$existing_vid || isset($used_variations[$existing_vid])) {
                    continue;
                }
                try {
                    $stale = new WC_Product_Variation($existing_vid);
                    if ((int) $stale->get_parent_id() !== $parent_id) {
                        continue;
                    }
                    $stale->set_stock_status('outofstock');
                    $stale->save();
                    update_post_meta($existing_vid, '_ftr_sync_orphaned', 1);
                    update_post_meta($existing_vid, '_ftr_last_synced_at', time());
                    $orphaned++;
                } catch (\Throwable $e) {
                    self::log('Could not disable stale variation #' . $existing_vid . ': ' . $e->getMessage(), 'error');
                }
            }

            // Recalculate price range + variation lookup tables.
            if (class_exists('WC_Product_Variable')) {
                WC_Product_Variable::sync($parent_id);
            }
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($parent_id);
            }

            // Featured image — best effort, never fatal.
            self::maybe_set_image($parent_id, isset($p['image_url']) ? $p['image_url'] : '');

            self::log('Synced "' . $name . '" (#' . $parent_id . ') with ' . $made . ' variation(s), ' . $remapped . ' remapped service code(s), ' . $orphaned . ' stale variation(s) disabled.');

            $verify = wc_get_product($parent_id);
            $err = null;
            if (!$verify) {
                $err = 'Product saved but could not be retrieved (#' . $parent_id . ').';
            } elseif ($made === 0) {
                $err = 'Product created but no variations were added — check the package data.';
            }
            return array(
                'name' => $name,
                'product_id' => $parent_id,
                'variations' => $made,
                'remapped_services' => $remapped,
                'orphaned_variations' => $orphaned,
                'supplier_product_id' => $supplier_product_id,
                'product_code' => $code,
                'error' => $err,
            );
        } catch (\Throwable $e) {
            self::log('Import FAILED for "' . $name . '": ' . $e->getMessage(), 'error');
            return array('name' => $name, 'product_id' => 0, 'variations' => 0, 'error' => $e->getMessage());
        }
    }

    /** Count of FlashTopup parent products currently in WooCommerce. */
    public static function ft_product_count()
    {
        if (!self::active()) {
            return 0;
        }
        $q = get_posts(array(
            'post_type'   => 'product',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_key'    => '_ftr_product_code',
        ));
        return is_array($q) ? count($q) : 0;
    }

    /**
     * Create a real test product from hard-coded data (no API call) to prove the
     * WooCommerce creation path end-to-end. Returns a full diagnostic report.
     */
    public static function selftest()
    {
        $fake = array(
            'product_code'    => 'FTR-SELFTEST',
            'name'            => 'FlashTopup Self-Test (safe to delete)',
            'image_url'       => '',
            'product_type'    => 'topup',
            'validation_code' => '',
            'check_id_status' => 'inactive',
            'fields'          => array(array('name' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'required' => true)),
            'services'        => array(
                array('service_code' => 'FTR-ST-1', 'service_name' => 'Test Pack A', 'price' => '1.00', 'in_stock' => true),
                array('service_code' => 'FTR-ST-2', 'service_name' => 'Test Pack B', 'price' => '2.00', 'in_stock' => true),
            ),
        );
        $r = self::sync_one($fake);
        $pid = isset($r['product_id']) ? (int) $r['product_id'] : 0;
        return array(
            'woo_active' => self::active(),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'unknown',
            'product_id' => $pid,
            'variations' => isset($r['variations']) ? (int) $r['variations'] : 0,
            'error'      => isset($r['error']) ? $r['error'] : null,
            'exists'     => ($pid && wc_get_product($pid)) ? true : false,
            'edit_link'  => $pid ? get_edit_post_link($pid, 'raw') : '',
        );
    }

    private static function ensure_category($name)
    {
        $name = trim((string) $name);
        if ($name === '' || !taxonomy_exists('product_cat')) {
            return 0;
        }
        $term = term_exists($name, 'product_cat');
        if (!$term) {
            $term = wp_insert_term($name, 'product_cat');
        }
        if (is_wp_error($term)) {
            return 0;
        }
        return (int) (is_array($term) ? $term['term_id'] : $term);
    }

    private static function find_variable_by_code($code)
    {
        if ($code === '') {
            return 0;
        }
        $q = get_posts(array(
            'post_type'   => 'product',
            'post_status' => 'any',
            'numberposts' => 5,
            'fields'      => 'ids',
            'meta_key'    => '_ftr_product_code',
            'meta_value'  => $code,
        ));
        foreach ($q as $pid) {
            $pr = wc_get_product($pid);
            if ($pr && $pr->is_type('variable')) {
                return (int) $pid;
            }
        }
        return 0;
    }

    private static function identity_value($value)
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        return '';
    }

    private static function normalize_identity_name($value)
    {
        $value = remove_accents(wp_strip_all_tags((string) $value));
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim((string) $value);
    }

    /**
     * Locate an existing product using current + previous supplier identities.
     * Exact identifiers only are used here; fuzzy name matching is handled in
     * the admin reconciliation layer where catalog uniqueness can be checked.
     */
    private static function find_variable_for_sync($code, $supplier_product_id, $previous_code = '', $previous_supplier_product_id = '')
    {
        foreach (array_unique(array_filter(array($code, $previous_code), 'strlen')) as $candidate_code) {
            $pid = self::find_variable_by_code($candidate_code);
            if ($pid) {
                return $pid;
            }
        }
        foreach (array_unique(array_filter(array($supplier_product_id, $previous_supplier_product_id), 'strlen')) as $candidate_id) {
            $q = get_posts(array(
                'post_type'   => 'product',
                'post_status' => 'any',
                'numberposts' => 5,
                'fields'      => 'ids',
                'meta_key'    => '_ftr_supplier_product_id',
                'meta_value'  => $candidate_id,
            ));
            foreach ($q as $pid) {
                $pr = wc_get_product($pid);
                if ($pr && $pr->is_type('variable')) {
                    return (int) $pid;
                }
            }
        }
        return 0;
    }

    private static function existing_variation_records($parent_id)
    {
        $records = array('by_code' => array(), 'by_name' => array(), 'all' => array());
        $parent = wc_get_product($parent_id);
        if (!$parent || !$parent->is_type('variable')) {
            return $records;
        }
        foreach ($parent->get_children() as $vid) {
            $vid = (int) $vid;
            $sc = trim((string) get_post_meta($vid, '_ftr_service_code', true));
            $was_managed = ($sc !== '') || (bool) get_post_meta($vid, '_ftr_sync_orphaned', true);
            if (!$was_managed) {
                // Never alter manually-added/non-FlashTopup variations.
                continue;
            }
            $records['all'][] = $vid;
            if ($sc !== '') {
                $records['by_code'][$sc] = $vid;
            }
            $name = (string) get_post_meta($vid, '_ftr_service_name', true);
            if ($name === '') {
                $variation = wc_get_product($vid);
                if ($variation && is_callable(array($variation, 'get_attribute'))) {
                    $name = (string) $variation->get_attribute('package');
                }
            }
            $key = self::normalize_identity_name($name);
            if ($key !== '') {
                if (!isset($records['by_name'][$key])) {
                    $records['by_name'][$key] = array();
                }
                $records['by_name'][$key][] = $vid;
            }
        }
        return $records;
    }

    private static function existing_variations($parent_id)
    {
        $records = self::existing_variation_records($parent_id);
        return $records['by_code'];
    }

    private static function maybe_set_image($product_id, $url)
    {
        if (!$url || has_post_thumbnail($product_id) || get_post_meta($product_id, '_ftr_img_done', true)) {
            return;
        }
        // Best effort only — a slow/blocked download must never abort the import.
        try {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            if (!function_exists('media_sideload_image')) {
                return;
            }
            $att = media_sideload_image($url, $product_id, null, 'id');
            if (is_wp_error($att)) {
                self::log('Image skipped for #' . $product_id . ': ' . $att->get_error_message());
            } else {
                set_post_thumbnail($product_id, (int) $att);
            }
        } catch (\Throwable $e) {
            self::log('Image error for #' . $product_id . ': ' . $e->getMessage());
        }
        update_post_meta($product_id, '_ftr_img_done', 1); // don't retry every import
    }

    /* ---------------- Product page ---------------- */

    private function fields_for($product_id)
    {
        $raw = get_post_meta($product_id, '_ftr_fields', true);
        $fields = $raw ? json_decode($raw, true) : array();
        return is_array($fields) ? $fields : array();
    }

    public function product_fields()
    {
        global $product;
        if (!$product) {
            return;
        }
        $id = $product->get_id();
        if (!get_post_meta($id, '_ftr_product_code', true)) {
            return; // not a FlashTopup product
        }
        $fields = $this->fields_for($id);
        $check  = (int) get_post_meta($id, '_ftr_check_id', true) && !empty(ftr_settings()['check_id']);

        echo '<div class="ftr-wc-fields">';
        foreach ($fields as $f) {
            $name = isset($f['name']) ? $f['name'] : '';
            if ($name === '') {
                continue;
            }
            $label = isset($f['label']) ? $f['label'] : $name;
            $req = !empty($f['required']);
            $is_player = in_array(sanitize_key($name), array('user_id', 'player_id', 'userid', 'playerid'), true);
            $row_class = $is_player ? ' ftr-wc-player-row' : '';
            echo '<div class="form-row form-row-wide ftr-wc-row' . esc_attr($row_class) . '">';
            echo '<div class="ftr-wc-label-line"><label for="ftr-field-' . esc_attr($name) . '">' . esc_html($label) . ($req ? ' <span class="required">*</span>' : '') . '</label>';
            if ($is_player) {
                echo '<span class="ftr-wc-secure" aria-label="Secure verification"><span class="ftr-wc-shield" aria-hidden="true"></span>100% sécurisé</span>';
            }
            echo '</div>';
            echo '<div class="ftr-wc-control">';
            if ($is_player) {
                echo '<span class="ftr-wc-user-icon" aria-hidden="true"></span>';
            }
            if (($f['type'] ?? 'text') === 'select' && !empty($f['options'])) {
                echo '<select id="ftr-field-' . esc_attr($name) . '" class="ftr-wc-input" data-name="' . esc_attr($name) . '" name="ftr_field[' . esc_attr($name) . ']">';
                foreach ($f['options'] as $opt) {
                    echo '<option value="' . esc_attr($opt['value']) . '">' . esc_html(isset($opt['label']) ? $opt['label'] : $opt['value']) . '</option>';
                }
                echo '</select>';
            } else {
                $inputmode = $is_player ? ' inputmode="numeric"' : '';
                echo '<input id="ftr-field-' . esc_attr($name) . '" type="text" class="ftr-wc-input input-text" data-name="' . esc_attr($name) . '" name="ftr_field[' . esc_attr($name) . ']" placeholder="' . esc_attr(isset($f['placeholder']) ? $f['placeholder'] : '') . '"' . $inputmode . ' autocomplete="off" />';
            }
            if ($is_player) {
                echo '<button type="button" class="ftr-wc-clear" aria-label="Effacer le Player ID" hidden>&times;</button>';
            }
            echo '</div></div>';
        }
        if ($check) {
            echo '<div class="ftr-wc-row ftr-wc-check-row"><button type="button" class="button ftr-wc-checkid" data-product="' . esc_attr(get_post_meta($id, '_ftr_product_code', true)) . '"><span class="ftr-wc-check-icon" aria-hidden="true"></span><span class="ftr-wc-check-label">Vérifier l’ID</span></button><div class="ftr-wc-checkid-msg" role="status" aria-live="polite"></div></div>';
        }
        echo '</div>';
    }

    public function validate($passed, $product_id, $quantity)
    {
        if (!get_post_meta($product_id, '_ftr_product_code', true)) {
            return $passed;
        }
        foreach ($this->fields_for($product_id) as $f) {
            if (!empty($f['required'])) {
                $name = $f['name'];
                if (empty($_POST['ftr_field'][$name])) {
                    wc_add_notice(sprintf('Please fill "%s" before adding to cart.', isset($f['label']) ? $f['label'] : $name), 'error');
                    return false;
                }
            }
        }
        return $passed;
    }

    public function add_cart_item_data($cart_item_data, $product_id)
    {
        if (!get_post_meta($product_id, '_ftr_product_code', true) || empty($_POST['ftr_field'])) {
            return $cart_item_data;
        }
        $vals = array();
        foreach ((array) $_POST['ftr_field'] as $k => $v) {
            $vals[sanitize_key($k)] = sanitize_text_field(wp_unslash($v));
        }
        $cart_item_data['ftr_fields'] = $vals;
        // Make each (product, field-set) a distinct cart line.
        $cart_item_data['ftr_unique'] = md5(wp_json_encode($vals) . microtime());
        return $cart_item_data;
    }

    public function display_cart_item_data($items, $cart_item)
    {
        if (empty($cart_item['ftr_fields'])) {
            return $items;
        }
        $fields = $this->fields_for($cart_item['product_id']);
        $labels = array();
        foreach ($fields as $f) {
            $labels[$f['name']] = isset($f['label']) ? $f['label'] : $f['name'];
        }
        foreach ($cart_item['ftr_fields'] as $k => $v) {
            $items[] = array('key' => isset($labels[$k]) ? $labels[$k] : $k, 'value' => $v);
        }
        return $items;
    }

    public function save_line_item($item, $cart_item_key, $values, $order)
    {
        if (empty($values['ftr_fields'])) {
            return;
        }
        foreach ($values['ftr_fields'] as $k => $v) {
            $item->add_meta_data('_ftr_field_' . $k, $v, true);
        }
    }

    /* ---------------- Fulfillment ---------------- */

    public function fulfill($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $api = new FTR_API();

        foreach ($order->get_items() as $item_id => $item) {
            try {
                $product = $item->get_product();
                if (!$product) {
                    continue;
                }
                $service = get_post_meta($product->get_id(), '_ftr_service_code', true);
                if (!$service && method_exists($product, 'get_parent_id') && $product->get_parent_id()) {
                    $service = get_post_meta($product->get_parent_id(), '_ftr_service_code', true);
                }
                if (!$service) {
                    continue;
                }
                if (wc_get_order_item_meta($item_id, '_ftr_fulfilled', true)) {
                    continue; // already done — never double-charge
                }

                // Gather the saved order field values.
                $fields = array();
                foreach ($item->get_meta_data() as $m) {
                    $d = $m->get_data();
                    if (strpos($d['key'], '_ftr_field_') === 0) {
                        $fields[substr($d['key'], strlen('_ftr_field_'))] = $d['value'];
                    }
                }

                $reference = 'wc_' . $order_id . '_' . $item_id;
                $qty = max(1, (int) $item->get_quantity());
                $res = $api->order($service, $reference, $qty, $fields);

                if (!empty($res['ok'])) {
                    $d = is_array($res['data']) ? $res['data'] : array();
                    wc_update_order_item_meta($item_id, '_ftr_fulfilled', 1);
                    wc_update_order_item_meta($item_id, '_ftr_order_id', isset($d['order_id']) ? $d['order_id'] : '');
                    $provider_status = self::extract_provider_status($d);
                    wc_update_order_item_meta($item_id, '_ftr_status', $provider_status);
                    $note = 'FlashTopup order placed for "' . $item->get_name() . '" — ID ' . (isset($d['order_id']) ? $d['order_id'] : '?') . ', status ' . ($provider_status !== '' ? $provider_status : '?') . '.';
                    if (!empty($res['raw']['data']['codes']) && is_array($res['raw']['data']['codes'])) {
                        $codes = implode(', ', $res['raw']['data']['codes']);
                        wc_update_order_item_meta($item_id, '_ftr_codes', $codes);
                        $note .= ' Codes: ' . $codes;
                    }
                    $order->add_order_note($note);
                    self::log('Fulfilled order #' . $order_id . ' item ' . $item_id . ' (' . $service . ').');
                } else {
                    $order->add_order_note('FlashTopup fulfillment FAILED for "' . $item->get_name() . '": ' . $res['message']);
                    self::log('Fulfillment FAILED for order #' . $order_id . ' item ' . $item_id . ': ' . $res['message'], 'error');
                }
            } catch (\Throwable $e) {
                self::log('Fulfillment EXCEPTION for order #' . $order_id . ' item ' . $item_id . ': ' . $e->getMessage(), 'error');
            }
        }

        // The placement response may already be final. Complete immediately
        // when every FlashTopup line item is confirmed completed server-side.
        self::maybe_complete_order($order);

        // Webhooks remain the primary sync method, but a short server-side
        // reconciliation prevents orders being stuck in Processing when a
        // callback is delayed, blocked by cache/firewall, or not delivered.
        if ($order->get_status() !== 'completed') {
            self::schedule_reconciliation($order_id, 60);
        }
    }

    public function reconcile_order($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || in_array($order->get_status(), array('completed', 'cancelled', 'refunded', 'failed'), true)) {
            return;
        }

        $api = new FTR_API();
        $has_pending = false;

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product || !self::is_ftr_order_item($item)) {
                continue;
            }

            $provider_id = (string) wc_get_order_item_meta($item_id, '_ftr_order_id', true);
            $reference   = 'wc_' . $order_id . '_' . $item_id;
            $res = $api->order_status($provider_id, $reference);
            if (empty($res['ok'])) {
                $has_pending = true;
                self::log('Status reconciliation failed for order #' . $order_id . ' item ' . $item_id . ': ' . (isset($res['message']) ? $res['message'] : 'unknown error'), 'error');
                continue;
            }

            $d = is_array($res['data']) ? $res['data'] : array();
            if (isset($d['data']) && is_array($d['data'])) {
                $d = $d['data'];
            }
            $status = self::extract_provider_status($d);
            if ($status !== '') {
                wc_update_order_item_meta($item_id, '_ftr_status', $status);
            }
            if (!empty($d['order_id'])) {
                wc_update_order_item_meta($item_id, '_ftr_order_id', sanitize_text_field($d['order_id']));
            }
            if (!empty($d['codes']) && is_array($d['codes'])) {
                wc_update_order_item_meta($item_id, '_ftr_codes', implode(', ', array_map('sanitize_text_field', $d['codes'])));
            }
            do_action('ftr_provider_item_status_updated', $order_id, $item_id, $status);
            if ($status !== 'completed') {
                $has_pending = true;
            }
        }

        // Reload so HPOS/order-item caches cannot hide the status just saved.
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }
        self::maybe_complete_order($order);
        if ($order->get_status() !== 'completed' && $has_pending) {
            self::schedule_reconciliation($order_id, 300);
        }
    }

    /**
     * Read a provider status from the different response shapes used by the
     * order, status and webhook endpoints. This deliberately checks nested
     * data/order/result objects, but never trusts a browser value.
     */
    public static function extract_provider_status($payload)
    {
        if (!is_array($payload)) {
            return self::normalize_provider_status($payload);
        }

        foreach (array('order_status', 'status', 'state', 'delivery_status', 'transaction_status') as $key) {
            if (isset($payload[$key]) && !is_array($payload[$key]) && !is_object($payload[$key])) {
                $normalized = self::normalize_provider_status($payload[$key]);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        foreach (array('data', 'order', 'result', 'payload') as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $normalized = self::extract_provider_status($payload[$key]);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return '';
    }

    public static function normalize_provider_status($status)
    {
        $status = strtolower(trim((string) $status));
        $status = str_replace(array(' ', '-'), '_', $status);
        if (in_array($status, array('completed', 'complete', 'success', 'successful', 'delivered', 'fulfilled', 'done', 'validated', 'validate', 'valid', 'approved', 'confirmed'), true)) {
            return 'completed';
        }
        if (in_array($status, array('failed', 'failure', 'rejected', 'cancelled', 'canceled', 'error'), true)) {
            return 'failed';
        }
        if (in_array($status, array('processing', 'pending', 'queued', 'in_progress', 'accepted'), true)) {
            return 'processing';
        }
        return sanitize_key($status);
    }

    /**
     * Return true when an order line belongs to FlashTopup. Variation metadata
     * is checked first, then the parent product, so imported variable products
     * keep working even when WooCommerce returns only one of those objects.
     */
    public static function is_ftr_order_item($item)
    {
        if (!$item || !is_object($item)) {
            return false;
        }

        $ids = array();
        if (is_callable(array($item, 'get_variation_id'))) {
            $ids[] = (int) $item->get_variation_id();
        }
        if (is_callable(array($item, 'get_product_id'))) {
            $ids[] = (int) $item->get_product_id();
        }
        if (is_callable(array($item, 'get_product'))) {
            $product = $item->get_product();
            if ($product) {
                $ids[] = (int) $product->get_id();
                if (is_callable(array($product, 'get_parent_id'))) {
                    $ids[] = (int) $product->get_parent_id();
                }
            }
        }

        foreach (array_unique(array_filter($ids)) as $product_id) {
            if (get_post_meta($product_id, '_ftr_service_code', true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Keep the WooCommerce order state synchronized with the provider. A
     * Processing order is already WooCommerce's paid/fulfilment state, but
     * some wallet gateways do not populate date_paid immediately. Therefore
     * Processing itself is accepted as payment confirmation; On-hold still
     * requires WooCommerce to report the order as paid.
     */
    public static function maybe_complete_order($order)
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        $order_id = (int) $order->get_id();
        $fresh = wc_get_order($order_id);
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }

        $settings = ftr_settings();
        if (empty($settings['auto_complete']) || $order->get_status() === 'completed') {
            return false;
        }

        $found = false;
        foreach ($order->get_items() as $item_id => $item) {
            // Security: never auto-complete a mixed order. Every product line
            // must be a FlashTopup line and must be final on the provider.
            if (!self::is_ftr_order_item($item)) {
                return false;
            }
            $found = true;
            $status = self::normalize_provider_status(wc_get_order_item_meta($item_id, '_ftr_status', true));
            if ($status !== 'completed') {
                return false;
            }
        }

        $current = $order->get_status();
        $payment_confirmed = ($current === 'processing') || $order->is_paid() || (bool) $order->get_date_paid();
        if (!$found || !in_array($current, array('processing', 'on-hold'), true) || !$payment_confirmed) {
            return false;
        }

        $note = 'Completed automatically: every FlashTopup item is validated/completed on the provider server.';
        $order->update_status('completed', $note, true);

        // Verify the persisted state. This extra save is only a fallback for
        // gateways/object caches that write Processing again during the same
        // request; it remains compatible with HPOS and legacy order storage.
        $verified = wc_get_order($order_id);
        if ($verified instanceof WC_Order && $verified->get_status() !== 'completed') {
            $verified->set_status('completed');
            $verified->add_order_note($note);
            $verified->save();
        }

        if (function_exists('wc_delete_shop_order_transients')) {
            wc_delete_shop_order_transients($order_id);
        }

        $verified = wc_get_order($order_id);
        if ($verified instanceof WC_Order && $verified->get_status() === 'completed') {
            self::log('WooCommerce order #' . $order_id . ' marked Completed after provider confirmation.');
            do_action('ftr_woocommerce_order_synchronized', $order_id, 'completed');
            return true;
        }

        self::log('Provider is completed but WooCommerce order #' . $order_id . ' could not be persisted as Completed.', 'error');
        return false;
    }

    /**
     * Detect TeraWallet independently of its visible checkout label. The
     * official gateway ID is `wallet`; title checks are fallback protection
     * for cloned/renamed gateway implementations.
     */
    public static function is_terawallet_order($order)
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        $method = sanitize_key((string) $order->get_payment_method());
        if ($method === 'wallet') {
            return true;
        }

        $title = strtolower(wp_strip_all_tags((string) $order->get_payment_method_title()));
        foreach (array('terawallet', 'tera wallet', 'delicat wallet') as $needle) {
            if ($title !== '' && strpos($title, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Return true when the order contains at least one FlashTopup line. */
    private static function order_contains_ftr_items($order)
    {
        if (!$order instanceof WC_Order) {
            return false;
        }
        foreach ($order->get_items() as $item) {
            if (self::is_ftr_order_item($item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * TeraWallet can finish its own payment transaction very late in the same
     * request. Re-check after every wallet transition to Processing/On-hold,
     * then queue a second server-side pass to survive object-cache races.
     */
    public function wallet_status_changed($order_id, $from, $to, $order)
    {
        if (!in_array($to, array('processing', 'on-hold'), true)) {
            return;
        }
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order || !self::is_terawallet_order($order) || !self::order_contains_ftr_items($order)) {
            return;
        }

        if (!self::maybe_complete_order($order)) {
            self::schedule_wallet_completion_check((int) $order_id, 10);
        }
    }

    /** Delayed TeraWallet/order-cache synchronization pass. */
    public function wallet_completion_check($order_id)
    {
        $order = wc_get_order((int) $order_id);
        if (!$order || !self::is_terawallet_order($order) || !self::order_contains_ftr_items($order)) {
            return;
        }
        if (in_array($order->get_status(), array('completed', 'cancelled', 'refunded', 'failed'), true)) {
            return;
        }

        if (!self::maybe_complete_order($order)) {
            // The provider may still be processing; use the normal signed API
            // reconciliation path instead of trusting any browser-side state.
            self::schedule_reconciliation((int) $order_id, 30);
        }
    }

    /** Late status check after payment/Processing hooks have finished. */
    public function sync_completed_status($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order) {
            self::maybe_complete_order($order);
            if (self::is_terawallet_order($order) && self::order_contains_ftr_items($order) && $order->get_status() !== 'completed') {
                self::schedule_wallet_completion_check((int) $order_id, 10);
            }
        }
    }

    /** React immediately whenever a webhook or reconciliation updates an item. */
    public function provider_item_status_updated($order_id, $item_id, $status)
    {
        if (self::normalize_provider_status($status) !== 'completed') {
            return;
        }
        $order = wc_get_order($order_id);
        if ($order) {
            self::maybe_complete_order($order);
        }
    }

    /**
     * One-time upgrade repair for orders that already show _ftr_status=completed
     * on their line item but remained Processing because a wallet gateway did
     * not set date_paid. Safety checks inside maybe_complete_order still apply.
     */
    public function repair_completed_orders()
    {
        if (!self::active()) {
            return;
        }

        global $wpdb;
        $statuses = array('completed', 'complete', 'success', 'successful', 'delivered', 'fulfilled', 'done', 'validated', 'validate', 'valid', 'approved', 'confirmed');
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT DISTINCT oi.order_id
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id
                WHERE oi.order_item_type = 'line_item'
                  AND oim.meta_key = '_ftr_status'
                  AND LOWER(oim.meta_value) IN ($placeholders)
                ORDER BY oi.order_id DESC
                LIMIT 250";
        $order_ids = $wpdb->get_col($wpdb->prepare($sql, $statuses));

        foreach ((array) $order_ids as $order_id) {
            $order = wc_get_order((int) $order_id);
            if ($order && in_array($order->get_status(), array('processing', 'on-hold'), true)) {
                self::maybe_complete_order($order);
            }
        }
    }

    private static function schedule_wallet_completion_check($order_id, $delay)
    {
        $timestamp = time() + max(5, (int) $delay);
        $args = array((int) $order_id);
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!as_next_scheduled_action('ftr_wallet_completion_check', $args, 'flashtopup-reseller')) {
                as_schedule_single_action($timestamp, 'ftr_wallet_completion_check', $args, 'flashtopup-reseller');
            }
        } elseif (!wp_next_scheduled('ftr_wallet_completion_check', $args)) {
            wp_schedule_single_event($timestamp, 'ftr_wallet_completion_check', $args);
        }
    }

    private static function schedule_reconciliation($order_id, $delay)
    {
        $timestamp = time() + max(30, (int) $delay);
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!as_next_scheduled_action('ftr_reconcile_order', array((int) $order_id), 'flashtopup-reseller')) {
                as_schedule_single_action($timestamp, 'ftr_reconcile_order', array((int) $order_id), 'flashtopup-reseller');
            }
        } elseif (!wp_next_scheduled('ftr_reconcile_order', array((int) $order_id))) {
            wp_schedule_single_event($timestamp, 'ftr_reconcile_order', array((int) $order_id));
        }
    }

    public function assets()
    {
        if (function_exists('is_product') && is_product()) {
            wp_enqueue_style('ftr-woo', FTR_URL . 'assets/ft-woo.css', array(), FTR_VERSION);
            wp_enqueue_script('ftr-woo', FTR_URL . 'assets/ft-woo.js', array(), FTR_VERSION, true);
            wp_localize_script('ftr-woo', 'FTRWoo', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('ftr_front'),
            ));
        }
    }
}
