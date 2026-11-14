<?php
/** Custom packages. Identical module in both gateways; booted once. */
defined('ABSPATH') || exit;
// Conditional declaration avoids PHP early-binding making the first-load guard true.
if (!class_exists('Delicat_Custom_Packages', false)) {
final class Delicat_Custom_Packages {
    const RECIPE = '_delicat_package_recipe_v1';
    private static $booted = false;
    private static $splitting = false;
    public static function boot() {
        if (self::$booted) { return; }
        self::$booted = true;
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_delicat_package_save', array(__CLASS__, 'save'));
        add_filter('get_post_metadata', array(__CLASS__, 'mapping_meta'), 5, 4);
        add_filter('update_post_metadata', array(__CLASS__, 'guard_meta'), 1, 4);
        add_filter('add_post_metadata', array(__CLASS__, 'guard_meta'), 1, 4);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'cart_data'), 100, 4);
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'check_cart'), 50);
        add_filter('woocommerce_product_is_in_stock', array(__CLASS__, 'in_stock'), 50, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'split'), 999, 4);
        add_action('woocommerce_before_product_object_save', array(__CLASS__, 'preserve_options'), 50);
        // The Store API builds lines without the classic per-line hook. Fail closed.
        add_action('woocommerce_store_api_checkout_update_order_from_request', array(__CLASS__, 'block_unsupported_checkout'), 1, 2);
    }
    public static function recipe($id) {
        $r = get_post_meta(absint($id), self::RECIPE, true);
        return is_array($r) ? $r : array();
    }
    public static function mapping_meta($value, $id, $key, $single) {
        if (null !== $value || !preg_match('/^_(dst2t|dfr)_/', (string) $key)) { return $value; }
        $r = self::recipe($id);
        if (!$r || empty($r['parts'][0]['id'])) { return $value; }
        // No copied supplier ID in the database: native importer/sync queries cannot
        // mistake this custom variation for the original supplier variation.
        $source = absint($r['parts'][0]['id']);
        if ($source === (int) $id || self::recipe($source)) { return $value; }
        $m = get_post_meta($source, $key, $single);
        return $single ? array($m) : $m;
    }
    public static function guard_meta($check, $id, $key, $value) {
        if (null !== $check) { return $check; }
        return preg_match('/^_(dst2t|dfr)_/', (string) $key) && self::recipe($id) ? true : $check;
    }
    public static function source($id) {
        $p = wc_get_product($id);
        if (!$p || !$p->is_type('variation') || self::recipe($id)) { throw new RuntimeException('Choisissez une variation fournisseur originale.'); }
        $dst = get_post_meta($id, '_dst2t_enabled', true) === 'yes' && (int) get_post_meta($id, '_dst2t_item_id', true) > 0;
        $dfr = get_post_meta($id, '_dfr_service_type', true) === 'topup' && get_post_meta($id, '_dfr_offer_id', true) !== '';
        if ($dst && $dfr) { throw new RuntimeException('Une variation ne peut pas utiliser deux passerelles à la fois.'); }
        if ($dst && get_post_meta($id, '_dst2t_returns_voucher', true) !== 'yes') {
            $gateway = 'dst2t';
            $mapping = array('item' => (string) get_post_meta($id, '_dst2t_item_id', true), 'category' => (string) get_post_meta($id, '_dst2t_category_id', true), 'schema' => get_post_meta($id, '_dst2t_requirements_schema', true));
        } elseif ($dfr) {
            $gateway = 'dfr';
            $mapping = array('offer' => (string) get_post_meta($id, '_dfr_offer_id', true), 'category' => (string) get_post_meta($id, '_dfr_category_id', true), 'field_map' => get_post_meta($id, '_dfr_field_map', true));
        } else { throw new RuntimeException('Seules les recharges directes importées sont compatibles.'); }
        if (empty($mapping['category'])) { throw new RuntimeException('Catégorie fournisseur manquante.'); }
        return array('id' => (int) $id, 'parent' => $p->get_parent_id(), 'gateway' => $gateway, 'mapping' => $mapping);
    }
    public static function validate_recipe($r) {
        if (empty($r['parts']) || !is_array($r['parts']) || count($r['parts']) > 20) { throw new RuntimeException('Composition invalide.'); }
        $first = null; $count = 0; $out = array();
        foreach ($r['parts'] as $part) {
            $id = absint($part['id'] ?? 0); $qty = (int) ($part['qty'] ?? 0);
            if ($qty < 1 || $qty > 20) { throw new RuntimeException('Quantité de composant invalide (1 à 20).'); }
            $s = self::source($id);
            if (!$first) { $first = $s; }
            if ($s['parent'] !== $first['parent'] || $s['gateway'] !== $first['gateway'] || $s['mapping']['category'] !== $first['mapping']['category']) {
                throw new RuntimeException('Les composants doivent appartenir au même produit, fournisseur et serveur.');
            }
            if (!empty($part['source']) && $part['source'] !== $s) { throw new RuntimeException('Une offre a changé. Retirez le forfait du panier puis ajoutez-le à nouveau.'); }
            $out[] = array('id' => $id, 'qty' => $qty, 'source' => $s); $count += $qty;
        }
        if ($count < 2 || $count > 20) { throw new RuntimeException('Un forfait doit contenir de 2 à 20 recharges.'); }
        return array('parts' => $out, 'parent' => $first['parent'], 'gateway' => $first['gateway']);
    }
    public static function available($r, $quantity = 1) {
        $r = self::validate_recipe($r);
        if ($r['gateway'] === 'dfr' && $quantity !== 1) { throw new RuntimeException('Commandez chaque forfait séparément (quantité 1).'); }
        $needs = array();
        foreach ($r['parts'] as $part) { $needs[$part['id']] = ($needs[$part['id']] ?? 0) + $part['qty'] * $quantity; }
        foreach ($needs as $id => $qty) {
            $p = wc_get_product($id);
            if (!$p || !$p->is_purchasable() || !$p->is_in_stock() || !$p->has_enough_stock($qty)) { throw new RuntimeException('Un composant de ce forfait est indisponible.'); }
            if ($r['gateway'] === 'dfr') {
                $ok = apply_filters('delicat_dfr_cart_line_available', true, $p->get_parent_id(), $id, $qty);
                if (is_wp_error($ok)) { throw new RuntimeException('Un composant de ce forfait est indisponible.'); }
            } elseif (get_post_meta($id, '_dst2t_supplier_missing', true) === 'yes') { throw new RuntimeException('Un composant de ce forfait est indisponible.'); }
        }
        return $r;
    }
    public static function cart_data($data, $product_id, $variation_id, $quantity = 1) {
        $r = self::recipe($variation_id ?: $product_id);
        unset($data['delicat_package'], $data['delicat_package_key']);
        if ($r) {
            $data['delicat_package'] = self::available($r, max(1, (int) $quantity));
            $data['delicat_package_key'] = hash('sha256', wp_json_encode($data['delicat_package']));
        }
        return $data;
    }
    public static function check_cart() {
        if (!function_exists('WC') || !WC()->cart) { return; }
        $needs = array();
        try {
            foreach (WC()->cart->get_cart() as $row) {
                $id = $row['variation_id'] ?: $row['product_id']; $qty = max(1, (int) $row['quantity']);
                if (self::recipe($id) && empty($row['delicat_package'])) { throw new RuntimeException('Retirez cet ancien forfait du panier puis ajoutez-le à nouveau.'); }
                if (!empty($row['delicat_package'])) {
                    $r = self::available($row['delicat_package'], $qty);
                    foreach ($r['parts'] as $part) { $needs[$part['id']] = ($needs[$part['id']] ?? 0) + $qty * $part['qty']; }
                } else { $needs[$id] = ($needs[$id] ?? 0) + $qty; }
            }
            foreach ($needs as $id => $qty) {
                $p = wc_get_product($id);
                if ($p && (!$p->has_enough_stock($qty))) { throw new RuntimeException('Le stock disponible ne couvre pas tous les forfaits du panier.'); }
            }
        } catch (Throwable $e) { wc_add_notice($e->getMessage(), 'error'); }
    }
    public static function in_stock($stock, $p) {
        $r = self::recipe($p->get_id());
        if (!$r) { return $stock; }
        try { self::available($r); return $stock; } catch (Throwable $e) { return false; }
    }
    /** Equal allocation with the final part taking the remainder, including taxes. */
    public static function shares($amount, $count, $precision = 6) {
        if ($count < 1) { throw new RuntimeException('Nombre de composants invalide.'); }
        $scale = pow(10, $precision);
        $piece = floor(abs((float) $amount) * $scale / $count) / $scale;
        if ((float) $amount < 0) { $piece = -$piece; } $out = array_fill(0, $count, $piece);
        $out[$count - 1] = (float) $amount - $piece * ($count - 1);
        return $out;
    }
    public static function split($item, $key, $values, $order) {
        if (self::$splitting) { return; }
        $r = $values['delicat_package'] ?? array();
        if ($r && $order->get_id() && ($order->is_paid() || $item->get_id())) { throw new RuntimeException('Le forfait doit être préparé avant le paiement.'); }
        $id = $values['variation_id'] ?? 0;
        if (!$r && self::recipe($id)) { throw new RuntimeException('Forfait incomplet : ajoutez-le à nouveau au panier.'); }
        if (!$r) { return; }
        $trusted = self::recipe($id);
        if (!$trusted || self::validate_recipe($trusted) !== self::validate_recipe($r)) { throw new RuntimeException('Composition du forfait invalide. Ajoutez-le à nouveau au panier.'); }
        $r = self::available($r, max(1, (int) $item->get_quantity()));
        if ($r['parent'] !== (int) $values['product_id']) { throw new RuntimeException('Produit du forfait invalide.'); }
        // No provider call, order save, or payment occurs here. All parts are built
        // before native payment attestation binds the complete order.
        $ids = array(); foreach ($r['parts'] as $part) { for ($i = 0; $i < $part['qty']; $i++) { $ids[] = $part['id']; } }
        $count = count($ids); $base = clone $item; $totals = self::shares($base->get_total(), $count); $subtotals = self::shares($base->get_subtotal(), $count);
        $taxes = $base->get_taxes(); $tax_shares = array();
        foreach ($taxes as $kind => $rates) { foreach ($rates as $rate => $amount) { $tax_shares[$kind][$rate] = self::shares($amount, $count, wc_get_price_decimals()); } }
        $group = wp_generate_uuid4(); self::$splitting = true;
        try {
            foreach ($ids as $i => $source_id) {
                $line = $i === 0 ? $item : new WC_Order_Item_Product();
                if ($i === 0) {
                    $labels = array_values((array) ($values['dst2t']['labels'] ?? array()));
                    $labels[] = 'Nom du joueur'; $labels[] = 'Player account';
                    foreach ((array) ($values['dfr_fields'] ?? array()) as $f) { if (!empty($f['label'])) { $labels[] = $f['label']; } }
                    foreach ($line->get_meta_data() as $meta) { if (preg_match('/^_(dst2t|dfr)_/', $meta->key) || in_array($meta->key, $labels, true)) { $line->delete_meta_data($meta->key); } }
                }
                $p = wc_get_product($source_id); $line->set_product($p);
                $line->set_name($base->get_name() . ' · ' . ($i + 1) . '/' . $count);
                $line->set_quantity($base->get_quantity()); $line->set_total($totals[$i]); $line->set_subtotal($subtotals[$i]);
                $tax = array('total' => array(), 'subtotal' => array());
                foreach ($tax_shares as $kind => $rates) { foreach ($rates as $rate => $amounts) { $tax[$kind][$rate] = $amounts[$i]; } }
                $line->set_taxes($tax);
                $v = $values; unset($v['delicat_package']); $v['variation_id'] = $source_id; $v['data'] = $p; $v['variation'] = $p->get_variation_attributes();
                if ($r['gateway'] === 'dst2t') {
                    if (empty($v['dst2t']['requirements']) || empty($v['dst2t']['player_verified'])) { throw new RuntimeException('Vérifiez votre ID joueur avant de commander ce forfait.'); }
                    $map = DST2T_Plugin::instance()->products()->mapping($source_id);
                    $v['dst2t']['product_id'] = $source_id; $v['dst2t']['item_id'] = $map['item_id']; $v['dst2t']['category_id'] = $map['category_id']; $v['dst2t']['returns_voucher'] = false;
                    DST2T_Plugin::instance()->products()->create_order_line_item($line, $key . ':' . $i, $v, $order);
                } else {
                    $s = self::source($source_id);
                    $v['dfr_supplier_snapshot'] = array('v' => 1, 'type' => 'topup', 'category' => $s['mapping']['category'], 'offer' => $s['mapping']['offer']);
                    DFR_Catalog::instance()->save_native_order_item_data($line, $key . ':' . $i, $v, $order);
                    if ($line->get_meta('_dfr_checkout_snapshot_failed', true) || !$line->get_meta('_dfr_checkout_snapshot_enc', true)) { throw new RuntimeException('Impossible de sécuriser ce forfait. Réessayez.'); }
                }
                $package_product = wc_get_product($id);
                $package_label = $package_product ? implode(' / ', array_values($package_product->get_variation_attributes())) : $base->get_name();
                $line->add_meta_data('Forfait', $package_label, true);
                $line->add_meta_data('_delicat_package_group', $group, true);
                $line->add_meta_data('_delicat_package_variation', absint($id), true);
                $line->add_meta_data('_delicat_package_part', ($i + 1) . '/' . $count, true);
                if ($i > 0) { $order->add_item($line); }
            }
        } finally { self::$splitting = false; }
    }
    public static function block_unsupported_checkout($order, $request) {
        foreach ($order->get_items() as $item) {
            if (self::recipe($item->get_variation_id())) { throw new RuntimeException('Ce forfait nécessite le paiement WooCommerce classique.'); }
        }
    }
    public static function menu() { add_submenu_page('edit.php?post_type=product', 'Forfaits personnalisés', 'Forfaits personnalisés', 'manage_woocommerce', 'delicat-packages', array(__CLASS__, 'page')); }
    public static function page() {
        if (!current_user_can('manage_woocommerce')) { return; }
        $pid = absint($_GET['product_id'] ?? 0); $parent = wc_get_product($pid);
        echo '<div class="wrap"><h1>Forfaits personnalisés</h1><p>Créez une option comme 220 = 110 × 2 ou 451 = 110 + 341. Chaque recharge est envoyée au même joueur et suivie séparément.</p>';
        if (!empty($_GET['saved'])) { echo '<div class="notice notice-success"><p>Forfait créé. Modifiez son prix ou désactivez-le dans les variations du produit.</p></div>'; }
        echo '<form method="get"><input type="hidden" name="post_type" value="product"><input type="hidden" name="page" value="delicat-packages"><label>ID du produit importé <input type="number" name="product_id" min="1" required value="' . esc_attr($pid ?: '') . '"></label> <button class="button">Charger les options</button></form>';
        if (!$parent || !$parent->is_type('variable') || !current_user_can('edit_post', $pid)) { echo '<p>Entrez l’ID du produit variable (ex. Free Fire LATAM).</p></div>'; return; }
        $sources = array(); foreach ($parent->get_children() as $cid) { try { self::source($cid); $sources[$cid] = wc_get_product($cid); } catch (Throwable $e) {} }
        echo '<h2>' . esc_html($parent->get_name()) . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('delicat_package_save');
        echo '<input type="hidden" name="action" value="delicat_package_save"><input type="hidden" name="product_id" value="' . esc_attr($pid) . '"><p><label>Nom de l’option <input name="label" placeholder="451 Diamants" maxlength="100" required></label></p><p><label>Prix de vente (' . esc_html(get_woocommerce_currency()) . ') <input name="price" type="number" min="0.01" step="0.01" required></label></p><table class="widefat" style="max-width:850px"><thead><tr><th>Option fournisseur</th><th>Nombre de recharges</th></tr></thead><tbody>';
        for ($i = 0; $i < 6; $i++) {
            echo '<tr><td><select name="parts[' . $i . '][id]"><option value="0">— Choisir —</option>';
            foreach ($sources as $cid => $p) { echo '<option value="' . esc_attr($cid) . '">' . esc_html(implode(' / ', $p->get_attributes()) . ' (#' . $cid . ')') . '</option>'; }
            echo '</select></td><td><input name="parts[' . $i . '][qty]" type="number" min="1" max="20" value="1"></td></tr>';
        }
        echo '</tbody></table><p>Maximum : 20 recharges. Même fournisseur et même serveur uniquement. Le prix est manuel et reste indépendant des prix fournisseur.</p>';
        submit_button('Créer le forfait'); echo '</form><h2>Forfaits existants</h2><ul>';
        foreach ($parent->get_children() as $cid) {
            $r = self::recipe($cid); if (!$r) { continue; }
            $parts = array(); foreach ($r['parts'] as $part) { $p = wc_get_product($part['id']); $parts[] = ($p ? implode(' / ', $p->get_attributes()) : '#' . $part['id']) . ' × ' . $part['qty']; }
            echo '<li><strong>' . esc_html(implode(' / ', wc_get_product($cid)->get_attributes())) . '</strong> (#' . esc_html($cid) . ') : ' . esc_html(implode(' + ', $parts)) . '</li>';
        }
        echo '</ul><p>Pour changer une composition, créez une nouvelle option et désactivez l’ancienne. Les commandes existantes restent inchangées.</p></div>';
    }
    public static function save() {
        if (!current_user_can('manage_woocommerce')) { wp_die('Accès refusé.'); }
        check_admin_referer('delicat_package_save'); $pid = absint($_POST['product_id'] ?? 0);
        if (!current_user_can('edit_post', $pid)) { wp_die('Accès refusé.'); }
        try {
            $parts = array(); foreach ((array) wp_unslash($_POST['parts'] ?? array()) as $part) { if (!empty($part['id'])) { $parts[] = array('id' => absint($part['id']), 'qty' => (int) $part['qty']); } }
            $r = self::validate_recipe(array('parts' => $parts));
            if ($r['parent'] !== $pid) { throw new RuntimeException('Produit parent invalide.'); }
            $label = substr(sanitize_text_field(wp_unslash($_POST['label'] ?? '')), 0, 100); $price = wc_format_decimal(wp_unslash($_POST['price'] ?? ''));
            if ($label === '' || !is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0) { throw new RuntimeException('Nom ou prix invalide.'); }
            $parent = wc_get_product($pid); $attrs = array_filter($parent->get_attributes(), static function($a) { return $a->get_variation(); });
            if (count($attrs) !== 1) { throw new RuntimeException('Le produit doit avoir un seul attribut de variation (Package).'); }
            $attr = reset($attrs); if ($attr->is_taxonomy()) { throw new RuntimeException('Utilisez un attribut Package local pour ce produit importé.'); }
            foreach ($parent->get_children() as $cid) { if (in_array($label, wc_get_product($cid)->get_attributes(), true)) { throw new RuntimeException('Ce nom d’option existe déjà.'); } }
            $variation = new WC_Product_Variation(); $variation->set_parent_id($pid); $variation->set_status('publish'); $variation->set_virtual(true);
            $variation->set_attributes(array(sanitize_title($attr->get_name()) => $label)); $variation->set_regular_price($price); $variation->set_price($price);
            $tax_source = wc_get_product($r['parts'][0]['id']);
            $variation->set_tax_status($tax_source->get_tax_status()); $variation->set_tax_class($tax_source->get_tax_class());
            $variation->set_manage_stock(false); $variation->set_stock_status('instock'); $variation->update_meta_data(self::RECIPE, $r);
            $vid = $variation->save();
            $options = $attr->get_options(); $options[] = $label; $attr->set_options(array_values(array_unique($options))); $attrs = $parent->get_attributes(); $attrs[sanitize_title($attr->get_name())] = $attr;
            $parent->set_attributes($attrs); $parent->save(); WC_Product_Variable::sync($pid); wc_delete_product_transients($pid);
            wp_safe_redirect(admin_url('edit.php?post_type=product&page=delicat-packages&product_id=' . $pid . '&saved=' . $vid)); exit;
        } catch (Throwable $e) { wp_die(esc_html($e->getMessage()), 'Forfait non créé', array('back_link' => true)); }
    }
    public static function preserve_options($product) {
        if (!$product->is_type('variable') || !$product->get_id()) { return; }
        $ids = get_posts(array('post_type' => 'product_variation', 'post_parent' => $product->get_id(), 'post_status' => array('publish','private','draft'), 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => self::RECIPE));
        if (!$ids) { return; }
        $attrs = $product->get_attributes(); $changed = false;
        foreach ($attrs as $key => $attr) {
            if (!$attr->get_variation() || $attr->is_taxonomy()) { continue; }
            $options = $attr->get_options();
            foreach ($ids as $id) { $p = wc_get_product($id); if ($p) { $a = $p->get_attributes(); if (!empty($a[$key]) && !in_array($a[$key], $options, true)) { $options[] = $a[$key]; $changed = true; } } }
            $attr->set_options($options); $attrs[$key] = $attr;
        }
        if ($changed) { $product->set_attributes($attrs); }
    }
}
} // Conditional class declaration.
// Keep registration outside the guard so an older gateway owning the shared
// class still boots. The shared boot() method makes duplicate callbacks harmless.
add_action('plugins_loaded', array('Delicat_Custom_Packages', 'boot'), 30);
