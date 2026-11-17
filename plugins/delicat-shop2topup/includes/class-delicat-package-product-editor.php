<?php
/** Per-product admin access, independent of the shared fulfillment module's load order. */
defined('ABSPATH') || exit;
if (!class_exists('Delicat_Package_Product_Editor', false)) {
final class Delicat_Package_Product_Editor {
    private static $booted = false;
    private static $processed = array();
    public static function boot() {
        if (self::$booted || !class_exists('Delicat_Custom_Packages', false)) { return; }
        self::$booted = true;
        add_filter('woocommerce_product_data_tabs', array(__CLASS__, 'tabs'));
        add_action('woocommerce_product_data_panels', array(__CLASS__, 'panel'));
        add_action('add_meta_boxes_product', array(__CLASS__, 'meta_box'));
        add_action('admin_notices', array(__CLASS__, 'notices'));
        add_action('edit_form_after_title', array(__CLASS__, 'banner'));
        // Core product save runs at priority 10. Read the persisted parent after it.
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_inline'), 50, 1);
    }
    private static function allowed($id) {
        return current_user_can('manage_woocommerce') && current_user_can('edit_post', $id);
    }
    public static function tabs($tabs) {
        if (!current_user_can('manage_woocommerce')) { return $tabs; }
        $tabs['delicat_packages'] = array('label' => 'Forfaits personnalisés', 'target' => 'delicat_packages_product_data', 'class' => array('show_if_variable'), 'priority' => 65);
        return $tabs;
    }
    public static function meta_box($post) {
        if (!$post || !self::allowed($post->ID)) { return; }
        add_meta_box('delicat_packages_editor', 'Forfaits personnalisés', array(__CLASS__, 'editor_box'), 'product', 'normal', 'high');
        add_meta_box('delicat_packages_shortcut', 'Forfaits personnalisés', array(__CLASS__, 'shortcut'), 'product', 'side', 'default');
    }
    public static function shortcut($post) {
        if (!self::allowed($post->ID)) { return; }
        echo '<p>Combinez les options importées : <strong>220 = 110 × 2</strong> ou <strong>451 = 110 + 341</strong>.</p>';
        echo '<p>Ouvrez le bloc <strong>Forfaits personnalisés</strong> sur cette fiche produit (produit variable).</p>';
        echo '<p><a class="button" href="' . esc_url(admin_url('edit.php?post_type=product&page=delicat-packages&product_id=' . absint($post->ID))) . '">Ouvrir le gestionnaire de forfaits</a></p>';
    }
    public static function banner($post) {
        if (!$post || $post->post_type !== 'product' || !self::allowed($post->ID)) { return; }
        echo '<div style="margin:12px 0;padding:14px;border-left:4px solid #635bff;background:#fff"><strong>Forfaits personnalisés</strong><p>220 = 110 × 2 · 451 = 110 + 341</p><a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=product&page=delicat-packages&product_id=' . absint($post->ID))) . '">Configurer les forfaits</a> <a class="button" href="#delicat_packages_editor">Voir le formulaire sur cette fiche</a><p style="margin-bottom:0">Module chargé · ' . esc_html(self::module_label()) . '</p></div>';
    }
    /** Neutral label: admin screens are shared and screenshotted, so the supplier is never named. */
    private static function module_label() {
        $label = class_exists('DST2T_Brand', false) ? DST2T_Brand::label() : 'Recharge';
        return trim($label . ' ' . (defined('DST2T_VERSION') ? DST2T_VERSION : ''));
    }
    public static function panel() {
        global $post;
        if (!$post || !self::allowed($post->ID)) { return; }
        echo '<div id="delicat_packages_product_data" class="panel woocommerce_options_panel hidden"><div style="padding:16px"><h3>Forfaits personnalisés</h3><p>Le formulaire est dans le bloc Forfaits personnalisés de cette fiche. Il reste accessible sur mobile sans onglet.</p><a class="button" href="#delicat_packages_editor">Aller au formulaire</a> <a class="button" href="' . esc_url(admin_url('edit.php?post_type=product&page=delicat-packages&product_id=' . absint($post->ID))) . '">Ouvrir le gestionnaire</a></div></div>';
    }
    public static function editor_box($post) {
        if (!$post || !self::allowed($post->ID)) { return; }
        $parent = wc_get_product($post->ID);
        echo '<div class="delicat-packages-editor" style="padding:8px;overflow-x:auto">';
        echo '<h3 style="margin-top:0">Forfaits personnalisés</h3><p>Ajoutez des options composées sans réimporter le produit. Exemple : 220 = 110 × 2 ; 451 = 110 + 341.</p>';
        if (!$parent || !$parent->is_type('variable')) { echo '<p>Enregistrez d’abord ce produit en <strong>Produit variable</strong> avec ses options fournisseur importées.</p></div>'; return; }
        $sources = array(); $existing = array();
        foreach ($parent->get_children() as $cid) {
            $r = Delicat_Custom_Packages::recipe($cid);
            if ($r) { $existing[$cid] = $r; continue; }
            try { Delicat_Custom_Packages::source($cid); $sources[$cid] = wc_get_product($cid); } catch (Throwable $e) {}
        }
        echo '<h4>Forfaits existants</h4>';
        if (!$existing) { echo '<p>Aucun forfait personnalisé pour ce produit.</p>'; }
        else {
            echo '<ul>';
            foreach ($existing as $cid => $r) {
                $p = wc_get_product($cid); if (!$p) { continue; } $parts = array();
                foreach ($r['parts'] as $part) { $source = wc_get_product($part['id']); $parts[] = ($source ? implode(' / ', $source->get_attributes()) : '#' . absint($part['id'])) . ' × ' . absint($part['qty']); }
                echo '<li><strong>' . esc_html(implode(' / ', $p->get_attributes())) . '</strong> : ' . esc_html(implode(' + ', $parts)) . ' — ' . esc_html($p->get_price() . ' ' . get_woocommerce_currency()) . '</li>';
            }
            echo '</ul><p>Le prix et l’activation se modifient dans l’onglet Variations.</p>';
        }
        if (!$sources) { echo '<p>Aucune option de recharge directe compatible. Importez d’abord les options originales (par exemple 110 et 341) dans ce produit.</p></div>'; return; }
        // This lives inside WordPress's existing post form: no nested form, action
        // override, AJAX dependency or required controls on unrelated product saves.
        wp_nonce_field('delicat_inline_package_' . $post->ID, 'delicat_inline_package_nonce', false);
        echo '<h4>Ajouter un forfait</h4><p><label style="float:none;width:auto;margin:0"><input type="checkbox" name="delicat_inline_package[create]" value="1"> Créer ce forfait lors de la mise à jour du produit</label></p>';
        echo '<p><label style="float:none;width:auto;margin:0" for="delicat-package-label">Nom de l’option</label><br><input style="float:none;width:100%;max-width:360px" id="delicat-package-label" name="delicat_inline_package[label]" maxlength="100" placeholder="451 Diamants"></p>';
        echo '<p><label style="float:none;width:auto;margin:0" for="delicat-package-price">Prix de vente (' . esc_html(get_woocommerce_currency()) . ')</label><br><input style="float:none;width:160px" id="delicat-package-price" name="delicat_inline_package[price]" type="number" min="0.01" step="0.01"></p>';
        echo '<table class="widefat"><thead><tr><th>Option fournisseur</th><th>Nombre de recharges</th></tr></thead><tbody>';
        for ($i = 0; $i < 6; $i++) {
            echo '<tr><td><select aria-label="Option fournisseur ' . ($i + 1) . '" style="float:none;width:100%" name="delicat_inline_package[parts][' . $i . '][id]"><option value="0">— Choisir —</option>';
            foreach ($sources as $cid => $p) { echo '<option value="' . esc_attr($cid) . '">' . esc_html(implode(' / ', $p->get_attributes()) . ' (#' . $cid . ')') . '</option>'; }
            echo '</select></td><td><input aria-label="Nombre de recharges ' . ($i + 1) . '" style="float:none;width:80px" type="number" min="1" max="20" value="1" name="delicat_inline_package[parts][' . $i . '][qty]"></td></tr>';
        }
        echo '</tbody></table><p>Même fournisseur et même serveur ; maximum 20 recharges. Cochez la case ci-dessus puis cliquez sur le bouton <strong>Mettre à jour</strong> du produit. Le nouveau forfait apparaîtra dans Variations après rechargement.</p></div>';
    }
    public static function save_inline($pid) {
        if (empty($_POST['delicat_inline_package']['create']) || isset(self::$processed[$pid])) { return; }
        if (!self::allowed($pid) || wp_is_post_autosave($pid) || wp_is_post_revision($pid)) { return; }
        $nonce = isset($_POST['delicat_inline_package_nonce']) && is_string($_POST['delicat_inline_package_nonce']) ? wp_unslash($_POST['delicat_inline_package_nonce']) : '';
        if (!wp_verify_nonce($nonce, 'delicat_inline_package_' . $pid)) { self::notice($pid, 'error', 'Forfait non créé : session expirée. Rechargez le produit puis réessayez.'); return; }
        self::$processed[$pid] = true;
        try {
            $data = wp_unslash($_POST['delicat_inline_package']);
            self::create($pid, $data);
            self::notice($pid, 'success', 'Forfait personnalisé créé. Retrouvez-le dans Variations.');
        } catch (Throwable $e) { self::notice($pid, 'error', 'Forfait non créé : ' . $e->getMessage()); }
    }
    private static function notice($pid, $type, $message) {
        set_transient('delicat_package_editor_' . get_current_user_id(), array('product' => absint($pid), 'type' => $type, 'message' => $message), 300);
    }
    public static function notices() {
        $key = 'delicat_package_editor_' . get_current_user_id(); $n = get_transient($key);
        if (!$n || absint($_GET['post'] ?? 0) !== $n['product'] || !self::allowed($n['product'])) { return; }
        delete_transient($key);
        echo '<div class="notice notice-' . ($n['type'] === 'success' ? 'success' : 'error') . ' is-dismissible"><p>' . esc_html($n['message']) . '</p></div>';
    }
    public static function create($pid, $data) {
        // Also enforce capabilities here so callers cannot bypass the save boundary.
        if (!self::allowed($pid)) { throw new RuntimeException('Accès refusé.'); }
        $parent = wc_get_product($pid);
        if (!$parent || !$parent->is_type('variable')) { throw new RuntimeException('Le produit doit être un produit variable déjà enregistré.'); }
        $parts = array();
        foreach ((array) ($data['parts'] ?? array()) as $part) { if (!empty($part['id'])) { $parts[] = array('id' => absint($part['id']), 'qty' => (int) ($part['qty'] ?? 0)); } }
        $r = Delicat_Custom_Packages::validate_recipe(array('parts' => $parts));
        if ($r['parent'] !== (int) $pid) { throw new RuntimeException('Les options doivent appartenir à ce produit.'); }
        $label = isset($data['label']) && is_string($data['label']) ? substr(sanitize_text_field($data['label']), 0, 100) : '';
        $price = isset($data['price']) && is_scalar($data['price']) ? wc_format_decimal($data['price']) : '';
        if ($label === '' || !is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0) { throw new RuntimeException('Entrez un nom et un prix de vente valides.'); }
        $attrs = array_filter($parent->get_attributes(), static function($a) { return $a->get_variation(); });
        if (count($attrs) !== 1) { throw new RuntimeException('Le produit doit avoir un seul attribut de variation (Package).'); }
        $attr = reset($attrs);
        if ($attr->is_taxonomy()) { throw new RuntimeException('Utilisez un attribut Package local pour ce produit importé.'); }
        foreach ($parent->get_children() as $cid) { $p = wc_get_product($cid); if ($p && in_array($label, $p->get_attributes(), true)) { throw new RuntimeException('Ce nom d’option existe déjà.'); } }
        $variation = new WC_Product_Variation(); $variation->set_parent_id($pid); $variation->set_status('publish'); $variation->set_virtual(true);
        $variation->set_attributes(array(sanitize_title($attr->get_name()) => $label)); $variation->set_regular_price($price); $variation->set_price($price);
        $source = wc_get_product($r['parts'][0]['id']); $variation->set_tax_status($source->get_tax_status()); $variation->set_tax_class($source->get_tax_class());
        $variation->set_manage_stock(false); $variation->set_stock_status('instock'); $variation->update_meta_data(Delicat_Custom_Packages::RECIPE, $r);
        $vid = $variation->save();
        if (!$vid) { throw new RuntimeException('Impossible d’enregistrer le forfait.'); }
        $options = $attr->get_options(); $options[] = $label; $attr->set_options(array_values(array_unique($options)));
        $attrs = $parent->get_attributes(); $attrs[sanitize_title($attr->get_name())] = $attr; $parent->set_attributes($attrs);
        $parent->save(); WC_Product_Variable::sync($pid); wc_delete_product_transients($pid);
        return $vid;
    }
}
} // Conditional declaration; register even when another updated gateway loaded it.
add_action('plugins_loaded', array('Delicat_Package_Product_Editor', 'boot'), 40);
