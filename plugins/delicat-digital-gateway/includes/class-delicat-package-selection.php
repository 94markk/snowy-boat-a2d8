<?php
/** Keep custom package values in WooCommerce's real variation selectors. */
defined('ABSPATH') || exit;
if (!class_exists('Delicat_Package_Selection', false)) {
final class Delicat_Package_Selection {
    const RECIPE = '_delicat_package_recipe_v1';
    const MIGRATION = 'delicat_package_selector_repair_v1';
    // Autoloaded index {format, parents: {parent id: [custom package variation ids]}}.
    // Storefront filters skip every product that is not in it, without a query.
    const INDEX = 'delicat_package_index_v1';
    // A stored value without this format (such as the empty map written by the
    // unreleased Gateway 4.25.0 / Shop2Topup 2.10.0 builds) counts as unknown and
    // is rebuilt from the database once.
    const INDEX_FORMAT = 2;
    const INDEX_CHECK = 'delicat_package_index_checked';
    private static $booted = false;
    private static $rows = array();
    /** @var array|null Parent id => variation ids; null until known (first request after an update). */
    private static $index = null;
    /** @var array Variation id => parent id, derived from $index. */
    private static $package_ids = array();
    public static function boot() {
        if (self::$booted) { return; }
        self::$booted = true;
        // Separate compatibility module: works even if an older gateway owns the
        // shared package class. Never force a variation ID or bypass availability.
        add_filter('woocommerce_product_get_attributes', array(__CLASS__, 'attributes'), 100, 2);
        add_filter('woocommerce_dropdown_variation_attribute_options_args', array(__CLASS__, 'dropdown'), 100);
        add_action('woocommerce_before_product_object_save', array(__CLASS__, 'persist'), 100);
        add_action('woocommerce_after_product_object_save', array(__CLASS__, 'after_save'), 100);
        add_action('woocommerce_new_product_variation', array(__CLASS__, 'invalidate'), 100, 2);
        add_action('woocommerce_update_product_variation', array(__CLASS__, 'invalidate'), 100, 2);
        add_action('admin_init', array(__CLASS__, 'repair_batch'));
        // Keep the index in step with every way a package recipe is written or removed.
        add_action('added_post_meta', array(__CLASS__, 'meta_written'), 10, 3);
        add_action('updated_post_meta', array(__CLASS__, 'meta_written'), 10, 3);
        add_action('deleted_post_meta', array(__CLASS__, 'meta_deleted'), 10, 3);
        add_action('deleted_post', array(__CLASS__, 'post_deleted'), 10, 1);
        if (null === self::index()) {
            // Built once from the database after post types and statuses exist.
            add_action('init', array(__CLASS__, 'rebuild_index'), 99);
        }
    }
    /** @return array|null Parent id => custom package variation ids, or null while unknown. */
    public static function index() {
        if (null === self::$index && function_exists('get_option')) {
            $stored = get_option(self::INDEX, null);
            if (is_array($stored) && isset($stored['format'], $stored['parents']) && self::INDEX_FORMAT === (int) $stored['format'] && is_array($stored['parents'])) { self::set_index($stored['parents'], false); }
        }
        return self::$index;
    }
    /** @return bool|null Whether this parent has custom packages; null while the index is unknown. */
    public static function has_packages($pid) {
        $index = self::index();
        return null === $index ? null : !empty($index[absint($pid)]);
    }
    /** @return bool|null Whether this id is a custom package variation; null while the index is unknown. */
    public static function is_package($id) {
        if (null === self::$index && null === self::index()) { return null; }
        return isset(self::$package_ids[(int) $id]);
    }
    private static function set_index($index, $save) {
        $clean = array(); $ids = array();
        foreach ((array) $index as $pid => $children) {
            $pid = absint($pid); if (!$pid) { continue; }
            foreach ((array) $children as $id) { $id = absint($id); if ($id && !isset($ids[$id])) { $clean[$pid][] = $id; $ids[$id] = $pid; } }
        }
        self::$index = $clean; self::$package_ids = $ids;
        if ($save && function_exists('update_option')) { update_option(self::INDEX, array('format' => self::INDEX_FORMAT, 'parents' => $clean), true); }
    }
    public static function rebuild_index() {
        if (!function_exists('get_posts')) { return; }
        // 'ids' is a plain id list on every WordPress version and cache path. Never
        // 'id=>parent': it returns parent ids (keyed 'post_parent:ID' from the query cache).
        $rows = get_posts(array('post_type' => 'product_variation', 'post_status' => array('publish','private','draft','pending','future','trash'), 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => self::RECIPE, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true));
        $ids = array();
        foreach ((array) $rows as $row) { $id = absint(is_object($row) ? $row->ID : $row); if ($id) { $ids[] = $id; } }
        // One query for the package posts not in the object cache, not one per parent lookup.
        if ($ids && function_exists('_prime_post_caches')) { _prime_post_caches($ids, false, false); }
        $index = array();
        foreach ($ids as $id) {
            $pid = absint(wp_get_post_parent_id($id));
            if ($pid) { $index[$pid][] = $id; }
        }
        self::set_index($index, true);
        if ($index && class_exists('Delicat_Custom_Packages', false) && method_exists('Delicat_Custom_Packages', 'hook_mapping')) { Delicat_Custom_Packages::hook_mapping(); }
    }
    private static function remember($id, $pid = 0) {
        $id = absint($id);
        $pid = $pid ? absint($pid) : ($id ? absint(wp_get_post_parent_id($id)) : 0);
        $index = self::index();
        // Unknown index: the pending rebuild reads the database, which already has it.
        if (!$id || !$pid || null === $index || (isset(self::$package_ids[$id]) && self::$package_ids[$id] === $pid)) { return; }
        if (isset(self::$package_ids[$id])) { self::forget($id); $index = self::index(); }
        $index[$pid][] = $id;
        self::set_index($index, true);
        if (class_exists('Delicat_Custom_Packages', false) && method_exists('Delicat_Custom_Packages', 'hook_mapping')) { Delicat_Custom_Packages::hook_mapping(); }
    }
    private static function forget($id) {
        $id = absint($id);
        if (null === self::index() || !isset(self::$package_ids[$id])) { return; }
        $pid = self::$package_ids[$id]; $index = self::$index;
        $index[$pid] = array_values(array_diff($index[$pid], array($id)));
        if (!$index[$pid]) { unset($index[$pid]); }
        self::set_index($index, true);
        unset(self::$rows[$pid]);
    }
    public static function meta_written($meta_id, $id, $key) {
        if (self::RECIPE === $key) { self::remember($id); }
    }
    public static function meta_deleted($meta_ids, $id, $key) {
        if (self::RECIPE === $key) { self::forget($id); }
    }
    public static function post_deleted($id) {
        if (self::is_package($id)) { self::forget($id); }
    }
    private static function rows($pid) {
        $pid = absint($pid);
        if (isset(self::$rows[$pid])) { return self::$rows[$pid]; }
        $ids = get_posts(array('post_type' => 'product_variation', 'post_parent' => $pid, 'post_status' => array('publish','private','draft'), 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => self::RECIPE, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true));
        $out = array();
        foreach ($ids as $id) {
            $recipe = get_post_meta($id, self::RECIPE, true);
            $variation = wc_get_product($id);
            if (!is_array($recipe) || empty($recipe['parts']) || !$variation || !$variation->is_type('variation') || $variation->get_parent_id() !== $pid) { continue; }
            $out[] = array('id' => (int) $id, 'status' => $variation->get_status(), 'attributes' => $variation->get_attributes('edit'));
        }
        self::$rows[$pid] = $out;
        return $out;
    }
    private static function merge($attributes, $product, $published_only = false) {
        if (!$product || !$product->is_type('variable') || !$product->get_id()) { return $attributes; }
        $rows = self::rows($product->get_id());
        if (!$rows) { return $attributes; }
        foreach ($attributes as $key => $attribute) {
            if (!$attribute->get_variation() || $attribute->is_taxonomy()) { continue; }
            $name = sanitize_title($attribute->get_name());
            $options = $attribute->get_options(); $changed = false;
            foreach ($rows as $row) {
                if ($published_only && $row['status'] !== 'publish') { continue; }
                $value = $row['attributes'][$name] ?? '';
                if (!is_string($value) || $value === '' || in_array($value, $options, true)) { continue; }
                // Preserve exact value: spaces, commas and '+' are significant to
                // WooCommerce for local attributes. Slugifying breaks card matching.
                $options[] = $value; $changed = true;
            }
            if ($changed) { $copy = clone $attribute; $copy->set_options($options); $attributes[$key] = $copy; }
        }
        return $attributes;
    }
    public static function attributes($attributes, $product) {
        // Most variable products have no custom package: no query for them.
        if ($product && is_object($product) && method_exists($product, 'get_id') && false === self::has_packages($product->get_id())) { return $attributes; }
        return self::merge($attributes, $product, true);
    }
    public static function dropdown($args) {
        $product = $args['product'] ?? null;
        if (!$product || !is_object($product) || !$product->is_type('variable')) { return $args; }
        if (false === self::has_packages($product->get_id())) { return $args; }
        $attribute_name = sanitize_title((string) ($args['attribute'] ?? ''));
        // Only extend a local variation attribute belonging to this product.
        $local = false;
        foreach ($product->get_attributes('edit') as $attribute) {
            if ($attribute->get_variation() && !$attribute->is_taxonomy() && sanitize_title($attribute->get_name()) === $attribute_name) { $local = true; break; }
        }
        if (!$local) { return $args; }
        $options = isset($args['options']) && is_array($args['options']) ? $args['options'] : array();
        // If WooCommerce requested defaults, keep all original options first.
        if (!$options) {
            foreach ($product->get_variation_attributes() as $key => $values) { if (sanitize_title($key) === $attribute_name) { $options = $values; break; } }
        }
        foreach (self::rows($product->get_id()) as $row) {
            if ($row['status'] !== 'publish') { continue; }
            $value = $row['attributes'][$attribute_name] ?? '';
            if (is_string($value) && $value !== '' && !in_array($value, $options, true)) { $options[] = $value; }
        }
        $args['options'] = $options;
        return $args;
    }
    public static function persist($product) {
        if (!$product || !$product->is_type('variable')) { return; }
        // Read edit context to compare actual stored options, not our view filter.
        $before = $product->get_attributes('edit');
        $after = self::merge($before, $product);
        if (self::option_map($before) !== self::option_map($after)) { $product->set_attributes($after); }
    }
    private static function option_map($attributes) {
        $out = array(); foreach ($attributes as $key => $attribute) { $out[$key] = $attribute->get_options(); } return $out;
    }
    public static function after_save($product) {
        if ($product && $product->is_type('variation')) { self::invalidate($product->get_id(), $product); }
    }
    public static function invalidate($id, $variation = null) {
        if (!is_array(get_post_meta($id, self::RECIPE, true))) { return; }
        $pid = $variation && is_object($variation) ? $variation->get_parent_id() : wp_get_post_parent_id($id);
        if (!$pid) { return; }
        self::remember($id, $pid);
        unset(self::$rows[absint($pid)]);
        wc_delete_product_transients($pid);
    }
    public static function repair_batch() {
        if (!current_user_can('manage_woocommerce') || !function_exists('wc_get_product')) { return; }
        // Daily self-check of the index from wp-admin (one query), never on the storefront.
        if (function_exists('get_transient') && !get_transient(self::INDEX_CHECK)) {
            set_transient(self::INDEX_CHECK, 1, DAY_IN_SECONDS);
            self::rebuild_index();
        }
        $state = get_option(self::MIGRATION, array('offset' => 0, 'done' => false));
        if (!empty($state['done'])) { return; }
        $offset = max(0, (int) ($state['offset'] ?? 0));
        $ids = get_posts(array('post_type' => 'product_variation', 'post_status' => array('publish','private','draft'), 'fields' => 'ids', 'posts_per_page' => 25, 'offset' => $offset, 'meta_key' => self::RECIPE, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true));
        $parents = array(); foreach ($ids as $id) { $pid = absint(wp_get_post_parent_id($id)); if ($pid) { $parents[$pid] = true; } }
        foreach (array_keys($parents) as $pid) {
            unset(self::$rows[$pid]); $parent = wc_get_product($pid);
            if (!$parent || !$parent->is_type('variable')) { continue; }
            $before = self::option_map($parent->get_attributes('edit'));
            self::persist($parent);
            if ($before !== self::option_map($parent->get_attributes('edit'))) { $parent->save(); }
            wc_delete_product_transients($pid);
        }
        update_option(self::MIGRATION, array('offset' => $offset + count($ids), 'done' => count($ids) < 25), false);
    }
}
}
add_action('plugins_loaded', array('Delicat_Package_Selection', 'boot'), 45);
