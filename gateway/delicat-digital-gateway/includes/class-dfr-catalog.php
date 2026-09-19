<?php
if (!defined('ABSPATH')) {
    exit;
}

final class DFR_Catalog {
    /** Rotating synchronization cursor: the last group key completed. */
    const SYNC_CURSOR_OPTION = 'dfr_catalog_sync_cursor';
    /** Ledger of group keys covered in the current full pass. */
    const SYNC_CYCLE_OPTION = 'dfr_catalog_sync_cycle';
    /** When every imported product was last provably synchronized. */
    const SYNC_LAST_FULL_CYCLE_OPTION = 'dfr_catalog_sync_last_full_cycle';
    /** Consecutive empty supplier responses per group, to resist a bad response. */
    const SYNC_EMPTY_STREAK_OPTION = 'dfr_catalog_sync_empty_streak';

    private static $instance;

    /**
     * Request-local bridge state for the authenticated Delicat App API cart.
     *
     * These values never persist beyond the current PHP request. The App API
     * token is validated by Delicat_App_Rest before this bridge is activated.
     */
    private $app_api_cart_request = false;
    private $app_api_cart_product_id = 0;
    private $app_api_cart_fields = array();
    private $app_api_uid_verified = array();

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot() {
        add_action('dfr_public_privacy_migration_batch', array($this, 'run_public_privacy_migration_batch'));
        $this->maybe_migrate_public_supplier_privacy();
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_dfr_catalog_status', array($this, 'ajax_status'));
        add_action('wp_ajax_dfr_catalog_categories', array($this, 'ajax_categories'));
        add_action('wp_ajax_dfr_catalog_offers', array($this, 'ajax_offers'));
        add_action('wp_ajax_dfr_catalog_import', array($this, 'ajax_import'));
        add_action('wp_ajax_dfr_catalog_sync_imported', array($this, 'ajax_sync_imported'));
        add_action('wp_ajax_dfr_verify_player_uid', array($this, 'ajax_verify_player_uid'));
        add_action('wp_ajax_nopriv_dfr_verify_player_uid', array($this, 'ajax_verify_player_uid'));

        // Headless storefront bridge. WooCommerce and this gateway remain the
        // only authorities for field schemas and Player ID verification.
        add_filter('delicat_app_product_fields', array($this, 'app_api_product_fields'), 20, 2);
        add_filter('delicat_app_product_needs_player_id', array($this, 'app_api_needs_player_id'), 20, 2);
        add_action('rest_api_init', array($this, 'register_app_api_routes'));
        add_filter('rest_request_before_callbacks', array($this, 'capture_app_api_cart_request'), 20, 3);

        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action('init', array($this, 'ensure_cron'));
        add_action('dfr_catalog_sync_event', array($this, 'cron_sync_imported'));

        add_action('woocommerce_before_add_to_cart_button', array($this, 'render_native_fields'));
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_native_fields'), 10, 5);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_native_cart_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'display_native_cart_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_native_order_item_data'), 10, 4);
        add_filter('woocommerce_quantity_input_args', array($this, 'quantity_input_args'), 10, 2);
        add_filter('woocommerce_available_variation', array($this, 'available_variation_limits'), 10, 3);
    }

    /** Expose this gateway's sanitized native fields through Delicat App API. */
    public function app_api_product_fields($fields, $product_id) {
        $fields = is_array($fields) ? array_values($fields) : array();
        $index = array();
        foreach ($fields as $position => $field) {
            if (is_array($field) && !empty($field['key'])) {
                $index[sanitize_key((string) $field['key'])] = (int) $position;
            }
        }

        foreach ($this->get_field_spec_for_product(absint($product_id)) as $field) {
            if (!is_array($field) || empty($field['key'])) { continue; }
            $key = sanitize_key((string) $field['key']);
            if ($key === '') { continue; }
            $role = $this->uid_field_role($key, isset($field['label']) ? (string) $field['label'] : '');
            $row = array(
                'key' => $key,
                'source' => 'dfr',
                'label' => isset($field['label']) ? sanitize_text_field((string) $field['label']) : $key,
                'type' => isset($field['type']) ? sanitize_key((string) $field['type']) : 'text',
                'required' => !empty($field['required']),
                'placeholder' => $role === 'player' ? __('Ex: 123456789', 'delicat-fazercards') : '',
                'help' => $role === 'player' ? __('Entrez exactement l’identifiant affiché dans le jeu.', 'delicat-fazercards') : '',
                'max_length' => 190,
                'options' => array(),
            );
            if (!empty($field['options']) && is_array($field['options'])) {
                foreach (array_slice($field['options'], 0, 100, true) as $value => $label) {
                    $value = substr(sanitize_text_field((string) $value), 0, 190);
                    if ($value === '') { continue; }
                    $row['options'][] = array(
                        'value' => $value,
                        'label' => substr(sanitize_text_field((string) $label), 0, 190),
                    );
                }
            }
            if (isset($index[$key])) {
                $fields[$index[$key]] = $row;
            } else {
                $index[$key] = count($fields);
                $fields[] = $row;
            }
        }
        return array_values($fields);
    }

    /** Tell API clients when a native field is a player/account identifier. */
    public function app_api_needs_player_id($needs_player_id, $product_id) {
        if ($needs_player_id) { return true; }
        return $this->uid_product_has_player_identity_field(absint($product_id));
    }

    /** Register authenticated Player ID verification beside the App API catalog. */
    public function register_app_api_routes() {
        if (!function_exists('register_rest_route')) { return; }
        register_rest_route('delicat-app/v1', '/catalog/product/(?P<id>\d+)/player/verify', array(
            'methods' => class_exists('WP_REST_Server') ? WP_REST_Server::CREATABLE : 'POST',
            'callback' => array($this, 'handle_app_api_player_verify'),
            'permission_callback' => array($this, 'guard_app_api_request'),
            'args' => array(
                'id' => array('sanitize_callback' => 'absint'),
            ),
        ));
    }

    /** Reuse the App API's HTTPS, kill-switch, version, token, and rate guards. */
    public function guard_app_api_request($request) {
        if (!class_exists('Delicat_App_Rest') || !is_callable(array('Delicat_App_Rest', 'guard_auth'))) {
            return new WP_Error('dfr_app_api_unavailable', __('The secure app bridge is unavailable.', 'delicat-fazercards'), array('status' => 503));
        }
        return Delicat_App_Rest::guard_auth($request);
    }

    /**
     * Verify Player ID through the existing supplier-backed engine.
     * No provider category, API key, or client-provided verified flag is trusted.
     */
    public function handle_app_api_player_verify($request) {
        $product_id = absint($request->get_param('id'));
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : false;
        if (!$product || ($product->get_parent_id() && !wc_get_product($product->get_parent_id()))) {
            return new WP_Error('dfr_app_product', __('Ce produit est introuvable.', 'delicat-fazercards'), array('status' => 404));
        }
        if ($product->get_parent_id()) { $product_id = absint($product->get_parent_id()); }

        if (class_exists('Delicat_App_Helpers') && !Delicat_App_Helpers::rate_limit('dfr_uid_verify:' . get_current_user_id(), 20, MINUTE_IN_SECONDS)) {
            return Delicat_App_Helpers::throttled();
        }

        $incoming = $request->get_param('fields');
        $incoming = is_array($incoming) ? $incoming : array();
        $fields = $this->sanitize_app_api_fields($product_id, $incoming);
        if (is_wp_error($fields)) { return $fields; }

        $result = $this->validate_reseller_topup_uid($product_id, $fields, true);
        if (is_wp_error($result)) { return $result; }
        if (empty($result['supported'])) {
            return rest_ensure_response(array(
                'success' => true,
                'supported' => false,
                'advisory' => !empty($result['advisory']),
                'message' => __('La vérification automatique n’est pas disponible pour ce produit. Vérifiez soigneusement vos informations.', 'delicat-fazercards'),
            ));
        }
        return rest_ensure_response(array(
            'success' => true,
            'supported' => true,
            'valid' => true,
            'player_name' => isset($result['player_name']) ? sanitize_text_field((string) $result['player_name']) : '',
            'region' => isset($result['region']) ? sanitize_text_field((string) $result['region']) : '',
        ));
    }

    /** Validate and normalize only fields declared by this product's DFR schema. */
    private function sanitize_app_api_fields($product_id, array $incoming) {
        $values = array();
        foreach ($this->get_field_spec_for_product($product_id) as $field) {
            $key = sanitize_key((string) ($field['key'] ?? ''));
            if ($key === '') { continue; }
            $candidate = isset($incoming[$key]) && !is_array($incoming[$key]) && !is_object($incoming[$key])
                ? trim(sanitize_text_field((string) $incoming[$key])) : '';
            if ($candidate === '') {
                if (!empty($field['required'])) {
                    return new WP_Error('dfr_app_required_field', sprintf(__('%s est obligatoire.', 'delicat-fazercards'), $field['label']), array('status' => 422, 'field' => $key));
                }
                continue;
            }
            if (strlen($candidate) > 190) {
                return new WP_Error('dfr_app_bad_field', sprintf(__('Valeur invalide pour %s.', 'delicat-fazercards'), $field['label']), array('status' => 422, 'field' => $key));
            }
            if ($field['type'] === 'number' && !is_numeric($candidate)) {
                return new WP_Error('dfr_app_bad_field', sprintf(__('%s doit être un nombre.', 'delicat-fazercards'), $field['label']), array('status' => 422, 'field' => $key));
            }
            if ($field['type'] === 'email' && !is_email($candidate)) {
                return new WP_Error('dfr_app_bad_field', sprintf(__('%s doit être une adresse e-mail valide.', 'delicat-fazercards'), $field['label']), array('status' => 422, 'field' => $key));
            }
            if ($field['type'] === 'select' && !empty($field['options']) && !array_key_exists($candidate, $field['options'])) {
                return new WP_Error('dfr_app_bad_field', sprintf(__('Valeur invalide pour %s.', 'delicat-fazercards'), $field['label']), array('status' => 422, 'field' => $key));
            }
            $values[$key] = substr($candidate, 0, 190);
        }
        return $values;
    }

    /**
     * Bridge the exact authenticated App API cart payload into Woo's native
     * add-to-cart hooks, where this gateway already validates and snapshots it.
     */
    public function capture_app_api_cart_request($response, $handler, $request) {
        $this->app_api_cart_request = false;
        $this->app_api_cart_product_id = 0;
        $this->app_api_cart_fields = array();
        $this->app_api_uid_verified = array();
        if (!$request instanceof WP_REST_Request || strtoupper((string) $request->get_method()) !== 'POST' || $request->get_route() !== '/delicat-app/v1/cart') {
            return $response;
        }
        if (!class_exists('Delicat_App_Rest') || get_current_user_id() <= 0) { return $response; }

        $product_id = absint($request->get_param('product_id'));
        $incoming = $request->get_param('fields');
        $incoming = is_array($incoming) ? $incoming : array();
        $values = array();
        foreach ($this->get_field_spec_for_product($product_id) as $field) {
            $key = sanitize_key((string) ($field['key'] ?? ''));
            if ($key === '' || !isset($incoming[$key]) || is_array($incoming[$key]) || is_object($incoming[$key])) { continue; }
            $value = substr(trim(sanitize_text_field((string) $incoming[$key])), 0, 190);
            if ($value === '') { continue; }
            $values[$key] = $value;
            $_POST['dfr_field_' . $key] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }
        $this->app_api_cart_request = true;
        $this->app_api_cart_product_id = $product_id;
        $this->app_api_cart_fields = $values;
        return $response;
    }

    private function is_app_api_cart_request($product_id) {
        return $this->app_api_cart_request
            && $this->app_api_cart_product_id > 0
            && $this->app_api_cart_product_id === absint($product_id)
            && get_current_user_id() > 0;
    }


    /**
     * v3.9.3 public supplier-privacy migration.
     *
     * The v3.9.1 migration was intentionally conservative but processed at most
     * 1,000 products and could leave an old supplier-derived slug/SKU in place
     * when a neutral replacement collided. v3.9.3 scans every imported product
     * in bounded background batches and always chooses a collision-safe neutral
     * public identifier. Private supplier mapping metadata is never renamed.
     */
    public function maybe_migrate_public_supplier_privacy() {
        if (get_option('dfr_public_privacy_migrated_v393', '0') === '1') {
            return;
        }
        if (!taxonomy_exists('product_cat') || !function_exists('wc_get_product')) {
            add_action('init', array($this, 'maybe_migrate_public_supplier_privacy'), 30);
            return;
        }

        if (get_option('dfr_public_privacy_terms_v393', '0') !== '1') {
            $this->migrate_public_supplier_terms();
            update_option('dfr_public_privacy_terms_v393', '1', false);
        }

        if (!get_option('dfr_public_privacy_migration_state_v393', false)) {
            add_option('dfr_public_privacy_migration_state_v393', array(
                'cursor' => 0,
                'processed' => 0,
                'failures' => array(),
                'started_at' => time(),
            ), '', false);
        }

        if (!wp_next_scheduled('dfr_public_privacy_migration_batch')) {
            wp_schedule_single_event(time() + 2, 'dfr_public_privacy_migration_batch');
        }
    }

    private function neutral_unique_term_slug($base, $term_id) {
        $base = sanitize_title((string) $base);
        if ($base === '') { $base = 'delicat-digital'; }
        $candidate = substr($base, 0, 180);
        $try = 0;
        while ($try < 100) {
            $collision = get_term_by('slug', $candidate, 'product_cat');
            if (!$collision || is_wp_error($collision) || (int) $collision->term_id === (int) $term_id) {
                return $candidate;
            }
            $try++;
            $suffix = '-' . (int) $term_id . ($try > 1 ? '-' . $try : '');
            $candidate = substr($base, 0, max(1, 190 - strlen($suffix))) . $suffix;
        }
        return 'delicat-category-' . (int) $term_id;
    }

    private function migrate_public_supplier_terms() {
        $legacy = get_term_by('slug', 'fazercards', 'product_cat');
        if (!$legacy) { $legacy = get_term_by('name', 'FazerCards', 'product_cat'); }
        if ($legacy && !is_wp_error($legacy)) {
            $slug = $this->neutral_unique_term_slug('delicat-digital', (int) $legacy->term_id);
            $description = preg_replace('/fazercards/i', 'Delicat Store', (string) $legacy->description);
            $name_collision = get_term_by('name', 'Delicat Digital', 'product_cat');
            $neutral_name = ($name_collision && !is_wp_error($name_collision) && (int) $name_collision->term_id !== (int) $legacy->term_id) ? 'Delicat Digital Source' : 'Delicat Digital';
            wp_update_term((int) $legacy->term_id, 'product_cat', array(
                'name' => $neutral_name,
                'slug' => $slug,
                'description' => $description,
            ));
        }

        $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 0));
        if (is_wp_error($terms)) { return; }
        foreach ($terms as $term) {
            $slug = (string) $term->slug;
            $name = (string) $term->name;
            $description = (string) $term->description;
            $needs_update = false;
            $args = array();

            if (strpos($slug, 'fzr-') === 0) {
                $base = 'delicat-' . ltrim(substr($slug, 4), '-');
                $args['slug'] = $this->neutral_unique_term_slug($base, (int) $term->term_id);
                $needs_update = true;
            }
            if (stripos($name, 'FazerCards') !== false) {
                $args['name'] = trim(preg_replace('/fazercards/i', 'Delicat Store', $name));
                $needs_update = true;
            }
            if (stripos($description, 'FazerCards') !== false) {
                $args['description'] = preg_replace('/fazercards/i', 'Delicat Store', $description);
                $needs_update = true;
            }
            if ($needs_update) {
                wp_update_term((int) $term->term_id, 'product_cat', $args);
            }
        }
    }

    private function neutral_unique_sku($old_sku, $product_id) {
        $base = stripos($old_sku, 'FZR-') === 0 ? 'DEL-' . substr($old_sku, 4) : $old_sku;
        $base = strtoupper(preg_replace('/[^A-Z0-9._-]/i', '-', (string) $base));
        $base = trim($base, '-');
        if ($base === '' || stripos($base, 'FZR') !== false || stripos($base, 'FAZER') !== false) {
            $base = 'DEL-MIG-' . (int) $product_id;
        }
        $candidate = substr($base, 0, 90);
        $try = 0;
        while ($try < 100) {
            $owner = function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($candidate) : 0;
            if (!$owner || $owner === (int) $product_id) { return $candidate; }
            $try++;
            $suffix = '-' . (int) $product_id . ($try > 1 ? '-' . $try : '');
            $candidate = substr($base, 0, max(1, 90 - strlen($suffix))) . $suffix;
        }
        return 'DEL-MIG-' . (int) $product_id . '-' . substr(hash('sha256', $old_sku . '|' . $product_id), 0, 8);
    }

    private function scrub_public_imported_product($product_id) {
        $product = wc_get_product((int) $product_id);
        if (!$product) { return false; }
        $changed = false;

        $name = (string) $product->get_name();
        if (stripos($name, 'FazerCards') !== false) {
            $product->set_name(trim(preg_replace('/fazercards/i', 'Delicat Store', $name)));
            $changed = true;
        }

        if (!$product->is_type('variation')) {
            $short = (string) $product->get_short_description();
            if (stripos($short, 'FazerCards') !== false) {
                $product->set_short_description(__('Livraison numérique automatique et sécurisée par Delicat Store.', 'delicat-fazercards'));
                $changed = true;
            }
            $desc = (string) $product->get_description();
            if (stripos($desc, 'FazerCards') !== false) {
                $service = sanitize_key((string) get_post_meta($product_id, '_dfr_service_type', true));
                $category = sanitize_text_field((string) get_post_meta($product_id, '_dfr_supplier_category_name', true));
                $labels = array(
                    'topup' => __('Game top-up', 'delicat-fazercards'),
                    'giftcard' => __('Gift card', 'delicat-fazercards'),
                    'gamekey' => __('Game key', 'delicat-fazercards'),
                );
                $label = isset($labels[$service]) ? $labels[$service] : __('Digital product', 'delicat-fazercards');
                $product->set_description(sprintf(__('Service numérique Delicat Store : %1$s / %2$s. Disponibilité synchronisée automatiquement.', 'delicat-fazercards'), $label, $category));
                $changed = true;
            }
        }

        $slug = method_exists($product, 'get_slug') ? (string) $product->get_slug() : '';
        if ($slug !== '' && (stripos($slug, 'fazercards') !== false || strpos($slug, 'fzr-') === 0)) {
            $neutral_slug = preg_replace('/fazercards/i', 'delicat', $slug);
            if (strpos($neutral_slug, 'fzr-') === 0) { $neutral_slug = 'delicat-' . substr($neutral_slug, 4); }
            $product->set_slug(sanitize_title($neutral_slug));
            $changed = true;
        }

        $sku = (string) $product->get_sku();
        if (stripos($sku, 'FZR-') === 0 || stripos($sku, 'FAZER') !== false) {
            $product->set_sku($this->neutral_unique_sku($sku, (int) $product_id));
            $changed = true;
        }

        if (!$changed) { return true; }
        try {
            $product->save();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function run_public_privacy_migration_batch() {
        if (get_option('dfr_public_privacy_migrated_v393', '0') === '1') { return; }
        if (!function_exists('wc_get_product')) {
            wp_schedule_single_event(time() + 30, 'dfr_public_privacy_migration_batch');
            return;
        }

        $lock = $this->acquire_db_lock('privacy_migration_v393', 0);
        if ($lock === '') {
            if (!wp_next_scheduled('dfr_public_privacy_migration_batch')) {
                wp_schedule_single_event(time() + 20, 'dfr_public_privacy_migration_batch');
            }
            return;
        }

        try {
            if (get_option('dfr_public_privacy_terms_v393', '0') !== '1') {
                $this->migrate_public_supplier_terms();
                update_option('dfr_public_privacy_terms_v393', '1', false);
            }
            $state = get_option('dfr_public_privacy_migration_state_v393', array());
            $state = is_array($state) ? $state : array();
            $cursor = isset($state['cursor']) ? absint($state['cursor']) : 0;
            $processed = isset($state['processed']) ? absint($state['processed']) : 0;
            $failures = isset($state['failures']) && is_array($state['failures']) ? array_map('absint', $state['failures']) : array();
            $batch_size = 150;

            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.ID > %d
                   AND p.post_type IN ('product','product_variation')
                   AND p.post_status IN ('publish','draft','private','pending','future')
                   AND pm.meta_key = '_dfr_imported'
                   AND pm.meta_value = '1'
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $cursor,
                $batch_size
            ));

            foreach ((array) $ids as $id) {
                $id = absint($id);
                if (!$id) { continue; }
                if (!$this->scrub_public_imported_product($id)) { $failures[$id] = $id; }
                else { unset($failures[$id]); }
                $cursor = max($cursor, $id);
                $processed++;
            }

            if (count($ids) >= $batch_size) {
                update_option('dfr_public_privacy_migration_state_v393', array(
                    'cursor' => $cursor,
                    'processed' => $processed,
                    'failures' => array_values($failures),
                    'started_at' => isset($state['started_at']) ? absint($state['started_at']) : time(),
                ), false);
                wp_schedule_single_event(time() + 5, 'dfr_public_privacy_migration_batch');
                return;
            }

            // Retry any isolated Woo save failures once the main cursor has completed.
            if (!empty($failures)) {
                $remaining = array();
                foreach (array_slice(array_values($failures), 0, $batch_size) as $failed_id) {
                    if (!$this->scrub_public_imported_product($failed_id)) { $remaining[$failed_id] = $failed_id; }
                }
                foreach (array_slice(array_values($failures), $batch_size) as $failed_id) { $remaining[$failed_id] = $failed_id; }
                if (!empty($remaining)) {
                    update_option('dfr_public_privacy_migration_state_v393', array(
                        'cursor' => $cursor,
                        'processed' => $processed,
                        'failures' => array_values($remaining),
                        'started_at' => isset($state['started_at']) ? absint($state['started_at']) : time(),
                    ), false);
                    wp_schedule_single_event(time() + 30, 'dfr_public_privacy_migration_batch');
                    return;
                }
            }

            update_option('dfr_public_privacy_migrated_v393', '1', false);
            update_option('dfr_public_privacy_migrated_v391', '1', false);
            delete_option('dfr_public_privacy_migration_state_v393');
        } finally {
            $this->release_db_lock($lock);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('dfr_catalog_sync_event');
        wp_clear_scheduled_hook('dfr_public_privacy_migration_batch');
    }

    private function plugin() {
        return DFR_Plugin::instance();
    }

    private function settings() {
        return $this->plugin()->get_settings();
    }

    private function acquire_db_lock($scope, $timeout = 1) {
        global $wpdb;
        $scope = preg_replace('/[^A-Za-z0-9_.:-]/', '-', (string) $scope);
        $name = substr('dfr_' . $scope, 0, 64);
        if ($name === 'dfr_') { return ''; }
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, max(0, (int) $timeout)));
        return (string) $got === '1' ? $name : '';
    }

    private function release_db_lock($name) {
        if ($name === '') { return; }
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', (string) $name));
    }

    private function pricing_mode() {
        $settings = $this->settings();
        return !empty($settings['catalog_pricing_mode']) && $settings['catalog_pricing_mode'] === 'automatic' ? 'automatic' : 'manual';
    }

    private function require_admin_ajax() {
        check_ajax_referer('dfr_catalog_nonce', 'nonce');
        if (!$this->plugin()->can_manage()) {
            wp_send_json_error(array('message' => __('Permission denied.', 'delicat-fazercards')), 403);
        }
    }

    public function enqueue_admin_assets($hook) {
        if (empty($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== 'delicat-fazercards') {
            return;
        }
        wp_enqueue_style('dfr-admin', DFR_URL . 'assets/admin.css', array(), DFR_VERSION);
        wp_enqueue_script('dfr-admin', DFR_URL . 'assets/admin.js', array(), DFR_VERSION, true);
        wp_localize_script('dfr-admin', 'DFRCatalog', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dfr_catalog_nonce'),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
            'topupStructure' => $this->settings()['topup_import_structure'],
            'variationAttribute' => $this->settings()['topup_variation_attribute'],
            'importStructures' => array(
                'topup' => $this->settings()['topup_import_structure'],
                'giftcard' => $this->settings()['giftcard_import_structure'],
                'gamekey' => $this->settings()['gamekey_import_structure'],
            ),
            'variationAttributes' => array(
                'topup' => $this->settings()['topup_variation_attribute'],
                'giftcard' => $this->settings()['giftcard_variation_attribute'],
                'gamekey' => $this->settings()['gamekey_variation_attribute'],
            ),
            'i18n' => array(
                'loading' => __('Loading…', 'delicat-fazercards'),
                'error' => __('Something went wrong.', 'delicat-fazercards'),
                'selectItems' => __('Select at least one product to import.', 'delicat-fazercards'),
                'importing' => __('Importing…', 'delicat-fazercards'),
                'syncing' => __('Synchronizing…', 'delicat-fazercards'),
            ),
        ));
    }

    public function cron_schedules($schedules) {
        if (!isset($schedules['dfr_15_minutes'])) {
            $schedules['dfr_15_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Every 15 minutes', 'delicat-fazercards'),
            );
        }
        return $schedules;
    }

    public function ensure_cron() {
        $settings = $this->settings();
        $enabled = !empty($settings['catalog_auto_sync']) && $settings['catalog_auto_sync'] === '1';
        $interval = !empty($settings['catalog_sync_interval']) && in_array($settings['catalog_sync_interval'], array('dfr_15_minutes', 'hourly', 'twicedaily', 'daily'), true)
            ? $settings['catalog_sync_interval'] : 'hourly';
        $scheduled = wp_next_scheduled('dfr_catalog_sync_event');
        $current_schedule = $scheduled ? wp_get_schedule('dfr_catalog_sync_event') : false;

        if (!$enabled) {
            if ($scheduled) {
                wp_clear_scheduled_hook('dfr_catalog_sync_event');
            }
            return;
        }

        if (!$scheduled || $current_schedule !== $interval) {
            wp_clear_scheduled_hook('dfr_catalog_sync_event');
            wp_schedule_event(time() + 60, $interval, 'dfr_catalog_sync_event');
        }
    }

    public function ajax_status() {
        $this->require_admin_ajax();
        $api = $this->plugin()->api();
        $me = $api->me();
        if (is_wp_error($me)) {
            wp_send_json_error(array('message' => $me->get_error_message()), 400);
        }
        $balance = $api->balance();
        $balance_data = is_wp_error($balance) ? array() : $balance;
        wp_send_json_success(array(
            'login' => isset($me['login']) ? sanitize_text_field($me['login']) : '',
            'plan' => isset($me['plan']) ? sanitize_text_field($me['plan']) : '',
            'subscriptionActive' => !empty($me['subscriptionActive']),
            'balance' => isset($balance_data['balance']) ? sanitize_text_field($balance_data['balance']) : '',
            'currency' => isset($balance_data['currency']) ? sanitize_text_field($balance_data['currency']) : 'USD',
        ));
    }

    public function ajax_categories() {
        $this->require_admin_ajax();
        $service = $this->sanitize_service(isset($_POST['service']) ? wp_unslash($_POST['service']) : '');
        if (!$service) {
            wp_send_json_error(array('message' => __('Invalid catalog service.', 'delicat-fazercards')), 400);
        }
        $cursor = isset($_POST['cursor']) ? substr(sanitize_text_field(wp_unslash($_POST['cursor'])), 0, 500) : '';
        $force = !empty($_POST['force']);
        $result = $this->get_categories($service, $cursor, $force);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()), 400);
        }
        wp_send_json_success($result);
    }

    public function ajax_offers() {
        $this->require_admin_ajax();
        $service = $this->sanitize_service(isset($_POST['service']) ? wp_unslash($_POST['service']) : '');
        $category_id = isset($_POST['category_id']) ? $this->sanitize_remote_id(wp_unslash($_POST['category_id'])) : '';
        if (!$service || $category_id === '') {
            wp_send_json_error(array('message' => __('Invalid catalog selection.', 'delicat-fazercards')), 400);
        }
        $force = !empty($_POST['force']);
        $result = $this->get_offers($service, $category_id, $force);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()), 400);
        }
        wp_send_json_success($result);
    }

    public function ajax_import() {
        $this->require_admin_ajax();
        if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) {
            wp_send_json_error(array('message' => __('WooCommerce must be active to import products.', 'delicat-fazercards')), 400);
        }

        $service = $this->sanitize_service(isset($_POST['service']) ? wp_unslash($_POST['service']) : '');
        $category_id = isset($_POST['category_id']) ? $this->sanitize_remote_id(wp_unslash($_POST['category_id'])) : '';
        $all = !empty($_POST['all']);
        $ids = array();
        if (!empty($_POST['item_ids'])) {
            $decoded = json_decode(wp_unslash($_POST['item_ids']), true);
            if (is_array($decoded)) {
                foreach (array_slice($decoded, 0, 100) as $id) {
                    $safe = $this->sanitize_remote_id($id);
                    if ($safe !== '') {
                        $ids[$safe] = true;
                    }
                }
            }
        }
        if (!$service || $category_id === '' || (!$all && !$ids)) {
            wp_send_json_error(array('message' => __('Choose products to import.', 'delicat-fazercards')), 400);
        }

        $lock = $this->acquire_db_lock('catalog_import_' . hash('sha256', $service . '|' . $category_id), 1);
        if ($lock === '') {
            wp_send_json_error(array('message' => __('Another catalog import is already running for this supplier category.', 'delicat-fazercards')), 409);
        }

        $remote = $this->get_offers($service, $category_id, true);
        if (is_wp_error($remote)) {
            $this->release_db_lock($lock);
            wp_send_json_error(array('message' => $remote->get_error_message()), 400);
        }

        $selected = array();
        foreach ($remote['items'] as $item) {
            if ($all || isset($ids[$item['id']])) {
                $selected[] = $item;
            }
        }
        if (!$selected) {
            $this->release_db_lock($lock);
            wp_send_json_error(array('message' => __('No matching FazerCards items were found.', 'delicat-fazercards')), 404);
        }
        if (count($selected) > 100) {
            $selected = array_slice($selected, 0, 100);
        }

        $settings = $this->settings();
        $currency = get_woocommerce_currency();
        if ($this->pricing_mode() === 'automatic') {
            $fx = $currency === 'USD' ? 1.0 : (float) $settings['usd_to_store_rate'];
            if ($currency !== 'USD' && $fx <= 0) {
                $this->release_db_lock($lock);
                wp_send_json_error(array('message' => sprintf(__('Automatic pricing requires a valid USD → %s exchange rate. Switch to Manual WooCommerce Pricing if you set customer prices yourself.', 'delicat-fazercards'), $currency)), 400);
            }
        }

        $created = 0;
        $updated = 0;
        $errors = array();
        $variation_created = 0;
        $variation_updated = 0;

        $import_structure = $this->import_structure_for_service($service, $settings);
        if ($import_structure === 'variable') {
            $result = $this->upsert_variable_product($service, $remote, $selected);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $created = !empty($result['parent_created']) ? 1 : 0;
                $updated = !empty($result['parent_created']) ? 0 : 1;
                $variation_created = isset($result['variations_created']) ? (int) $result['variations_created'] : 0;
                $variation_updated = isset($result['variations_updated']) ? (int) $result['variations_updated'] : 0;
                if (!empty($result['warnings']) && is_array($result['warnings'])) {
                    $errors = array_merge($errors, $result['warnings']);
                }
            }
        } else {
            foreach ($selected as $item) {
                $result = $this->upsert_product($service, $remote, $item);
                if (is_wp_error($result)) {
                    $errors[] = $item['name'] . ': ' . $result->get_error_message();
                } elseif (!empty($result['created'])) {
                    $created++;
                } else {
                    $updated++;
                }
            }
        }
        update_option('dfr_catalog_last_import', current_time('mysql', true), false);
        $this->release_db_lock($lock);

        if ($import_structure === 'variable' && !$errors) {
            $message = sprintf(__('Variable product ready: %1$d new variation(s), %2$d updated variation(s). Set each variation’s WooCommerce price in %3$s before publishing.', 'delicat-fazercards'), $variation_created, $variation_updated, $currency);
        } elseif ($import_structure === 'variable') {
            $message = sprintf(__('Variable product processed: %1$d new variation(s), %2$d updated variation(s). Review the warnings.', 'delicat-fazercards'), $variation_created, $variation_updated);
        } else {
            $message = sprintf(__('%1$d created, %2$d updated.', 'delicat-fazercards'), $created, $updated);
        }

        wp_send_json_success(array(
            'created' => $created,
            'updated' => $updated,
            'variations_created' => $variation_created,
            'variations_updated' => $variation_updated,
            'errors' => array_slice($errors, 0, 10),
            'message' => $message,
        ));
    }

    public function ajax_sync_imported() {
        $this->require_admin_ajax();
        $result = $this->sync_imported_products(false);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }
        wp_send_json_success($result);
    }

    public function cron_sync_imported() {
        $this->sync_imported_products(true);
    }

    private function sanitize_service($service) {
        $service = sanitize_key((string) $service);
        return in_array($service, array('topup', 'giftcard', 'gamekey'), true) ? $service : '';
    }

    private function sanitize_remote_id($id) {
        $id = trim((string) $id);
        if ($id === '' || strlen($id) > 190 || !preg_match('/^[A-Za-z0-9._:-]+$/', $id)) {
            return '';
        }
        return $id;
    }

    private function cache_ttl() {
        $settings = $this->settings();
        $ttl = isset($settings['catalog_cache_ttl']) ? (int) $settings['catalog_cache_ttl'] : 600;
        return max(300, min(900, $ttl));
    }

    private function get_categories($service, $cursor = '', $force = false) {
        $cache_key = 'dfr_cat_' . md5($service . '|' . $cursor);
        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $api = $this->plugin()->api();
        if ($service === 'topup') {
            $raw = $api->topups(50, $cursor, true);
        } elseif ($service === 'giftcard') {
            $raw = $api->giftcards(50, $cursor, false);
        } else {
            $raw = $api->gamekeys(50, $cursor);
        }
        if (is_wp_error($raw)) {
            return $raw;
        }

        $items = array();
        foreach (!empty($raw['items']) && is_array($raw['items']) ? $raw['items'] : array() as $row) {
            $id = $service === 'gamekey' ? ($row['game_id'] ?? '') : ($row['category_id'] ?? '');
            $id = $this->sanitize_remote_id($id);
            if ($id === '') {
                continue;
            }
            $items[] = array(
                'id' => $id,
                'name' => sanitize_text_field($row['name'] ?? $id),
                'note' => sanitize_text_field($row['note'] ?? ''),
                'region' => sanitize_text_field($row['region'] ?? ''),
                'platform' => sanitize_text_field($row['platform'] ?? ''),
            );
        }
        $meta = !empty($raw['meta']) && is_array($raw['meta']) ? $raw['meta'] : array();
        $result = array(
            'items' => $items,
            'meta' => array(
                'total' => isset($meta['total']) ? absint($meta['total']) : count($items),
                'next_cursor' => isset($meta['next_cursor']) ? sanitize_text_field((string) $meta['next_cursor']) : '',
                'has_more' => !empty($meta['has_more']),
            ),
        );
        set_transient($cache_key, $result, $this->cache_ttl());
        return $result;
    }

    private function get_offers($service, $category_id, $force = false) {
        $cache_key = 'dfr_off_' . md5($service . '|' . $category_id);
        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $api = $this->plugin()->api();
        if ($service === 'topup') {
            $raw = $api->topup_offers($category_id);
            if (is_wp_error($raw)) { return $raw; }
            $rows = isset($raw['offers']) && is_array($raw['offers']) ? $raw['offers'] : array();
            $category_name = sanitize_text_field($raw['name'] ?? $category_id);
            $fields = isset($raw['fields']) && is_array($raw['fields']) ? $this->sanitize_field_spec($raw['fields']) : array();
        } elseif ($service === 'giftcard') {
            $raw = $api->giftcard_cards($category_id);
            if (is_wp_error($raw)) { return $raw; }
            $rows = isset($raw['offers']) && is_array($raw['offers']) ? $raw['offers'] : array();
            $category_name = sanitize_text_field($raw['name'] ?? $category_id);
            $fields = array();
        } else {
            $raw = $api->gamekey_keys($category_id);
            if (is_wp_error($raw)) { return $raw; }
            $rows = isset($raw['keys']) && is_array($raw['keys']) ? $raw['keys'] : array();
            $category_name = sanitize_text_field($raw['GameName'] ?? ($raw['name'] ?? $category_id));
            $fields = array();
        }

        $existing = $this->existing_map($service, $category_id);
        $items = array();
        foreach ($rows as $row) {
            $id_key = $service === 'topup' ? 'offer_id' : ($service === 'giftcard' ? 'card_id' : 'key_id');
            $id = $this->sanitize_remote_id($row[$id_key] ?? '');
            if ($id === '') {
                continue;
            }
            $price = isset($row['price_usd']) && is_numeric($row['price_usd']) ? (float) $row['price_usd'] : 0.0;
            $stock = isset($row['stock']) && is_numeric($row['stock']) ? max(0, (int) $row['stock']) : null;
            $items[] = array(
                'id' => $id,
                'name' => sanitize_text_field($row['name'] ?? $id),
                'price_usd' => number_format($price, 4, '.', ''),
                'stock' => $stock,
                'min_order_quantity' => isset($row['min_order_quantity']) ? max(1, (int) $row['min_order_quantity']) : 1,
                'max_order_quantity' => isset($row['max_order_quantity']) ? max(1, (int) $row['max_order_quantity']) : 1,
                'product_id' => isset($existing[$id]) ? (int) $existing[$id] : 0,
            );
        }
        $result = array(
            'service' => $service,
            'category_id' => $category_id,
            'category_name' => $category_name,
            'fields' => $fields,
            'items' => $items,
        );
        set_transient($cache_key, $result, $this->cache_ttl());
        return $result;
    }

    private function sanitize_field_spec(array $fields) {
        $safe = array();
        foreach (array_slice($fields, 0, 20) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = sanitize_key($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $type = sanitize_key($field['type'] ?? 'text');
            if (!in_array($type, array('text', 'number', 'email', 'select'), true)) {
                $type = 'text';
            }
            $row = array(
                'key' => $key,
                'label' => sanitize_text_field($field['label'] ?? $key),
                'type' => $type,
                'required' => array_key_exists('required', $field) ? !empty($field['required']) : true,
            );
            if ($type === 'select' && !empty($field['options']) && is_array($field['options'])) {
                $row['options'] = array();
                foreach (array_slice($field['options'], 0, 100) as $option) {
                    if (is_scalar($option)) {
                        $value = sanitize_text_field((string) $option);
                        $row['options'][$value] = $value;
                    } elseif (is_array($option)) {
                        $value = sanitize_text_field((string) ($option['value'] ?? ($option['id'] ?? '')));
                        $label = sanitize_text_field((string) ($option['label'] ?? ($option['name'] ?? $value)));
                        if ($value !== '') {
                            $row['options'][$value] = $label;
                        }
                    }
                }
            }
            $safe[] = $row;
        }
        return $safe;
    }

    private function existing_map($service, $category_id) {
        if (!function_exists('wc_get_products')) {
            return array();
        }
        $ids = get_posts(array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => array('publish', 'draft', 'private', 'pending'),
            'posts_per_page' => 500,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => '_dfr_imported', 'value' => '1'),
                array('key' => '_dfr_service_type', 'value' => $service),
                array('key' => '_dfr_category_id', 'value' => $category_id),
            ),
        ));
        $map = array();
        foreach ($ids as $object_id) {
            $remote_id = (string) get_post_meta($object_id, '_dfr_offer_id', true);
            if ($remote_id !== '') {
                // Prefer the native variation mapping when a legacy simple import and
                // the new grouped variation both exist for the same supplier offer.
                if (!isset($map[$remote_id]) || get_post_type($object_id) === 'product_variation') {
                    $map[$remote_id] = (int) $object_id;
                }
            }
        }
        return $map;
    }

    private function import_structure_for_service($service, $settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $map = array(
            'topup'    => 'topup_import_structure',
            'giftcard' => 'giftcard_import_structure',
            'gamekey'  => 'gamekey_import_structure',
        );
        $key = isset($map[$service]) ? $map[$service] : '';
        if ($key === '') {
            return 'simple';
        }
        return !empty($settings[$key]) && $settings[$key] === 'simple' ? 'simple' : 'variable';
    }

    private function variation_attribute_for_service($service, $settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $keys = array(
            'topup'    => array('topup_variation_attribute', 'Package'),
            'giftcard' => array('giftcard_variation_attribute', 'Amount'),
            'gamekey'  => array('gamekey_variation_attribute', 'Option'),
        );
        if (!isset($keys[$service])) {
            return 'Option';
        }
        list($key, $fallback) = $keys[$service];
        $label = !empty($settings[$key]) ? sanitize_text_field($settings[$key]) : $fallback;
        $label = trim(wp_strip_all_tags($label));
        return $label !== '' ? substr($label, 0, 40) : $fallback;
    }

    private function variable_parent_short_description($service) {
        if ($service === 'topup') {
            return __('Choose a package, enter the required Player ID/account information, and complete checkout securely.', 'delicat-fazercards');
        }
        if ($service === 'giftcard') {
            return __('Choose the gift-card amount you want. The selected denomination is fulfilled securely after payment.', 'delicat-fazercards');
        }
        return __('Choose the game-key option or edition you want. The selected key is fulfilled securely after payment.', 'delicat-fazercards');
    }

    private function upsert_variable_product($service, array $remote, array $selected) {
        if (!class_exists('WC_Product_Variable') || !class_exists('WC_Product_Variation') || !class_exists('WC_Product_Attribute')) {
            return new WP_Error('dfr_variable_products_unavailable', __('WooCommerce variable-product classes are unavailable.', 'delicat-fazercards'));
        }
        $service = $this->sanitize_service($service);
        if (!$service || empty($remote['category_id']) || empty($remote['category_name']) || !$selected) {
            return new WP_Error('dfr_invalid_variable_import', __('The FazerCards catalog category is incomplete.', 'delicat-fazercards'));
        }

        $category_id = $this->sanitize_remote_id($remote['category_id']);
        if ($category_id === '') {
            return new WP_Error('dfr_invalid_category_id', __('Invalid FazerCards category ID.', 'delicat-fazercards'));
        }

        $parent_key = $service . '|' . $category_id . '|__variable__';
        $existing = get_posts(array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'private', 'pending'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => '_dfr_catalog_key',
            'meta_value' => $parent_key,
        ));
        $parent_id = $existing ? (int) $existing[0] : 0;
        $parent_created = $parent_id <= 0;
        $parent = $parent_id ? wc_get_product($parent_id) : new WC_Product_Variable();
        if (!$parent || (!$parent_created && !$parent->is_type('variable'))) {
            return new WP_Error('dfr_variable_parent_conflict', __('An existing FazerCards catalog object uses this group key but is not a variable WooCommerce product.', 'delicat-fazercards'));
        }

        $settings = $this->settings();
        $attribute_label = $this->variation_attribute_for_service($service, $settings);
        $attribute_key = sanitize_title($attribute_label);
        if ($attribute_key === '') {
            $attribute_label = 'Option';
            $attribute_key = 'option';
        }
        $previous_attribute_label = $parent_id ? sanitize_text_field(get_post_meta($parent_id, '_dfr_variation_attribute_label', true)) : '';
        $previous_attribute_key = $previous_attribute_label !== '' ? sanitize_title($previous_attribute_label) : $attribute_key;

        if ($parent_created || $settings['catalog_update_titles'] === '1') {
            $parent->set_name(sanitize_text_field($remote['category_name']));
        }
        if ($parent_created) {
            $status = in_array($settings['catalog_import_status'], array('draft', 'publish', 'private'), true) ? $settings['catalog_import_status'] : 'draft';
            // In Manual Pricing, brand-new variations intentionally start without a customer-facing price.
            // Keep the group in Draft until the merchant has reviewed and priced the variations.
            if ($this->pricing_mode() === 'manual' && $status === 'publish') {
                $status = 'draft';
            }
            $parent->set_status($status);
            $parent->set_virtual(true);
            $parent->set_catalog_visibility('visible');
            $parent->set_sold_individually($service === 'topup');
            $parent->set_description($this->build_description($service, $remote['category_name'], array()));
            $parent->set_short_description($this->variable_parent_short_description($service));
            if (!$parent->get_sku()) {
                $parent->set_sku('DEL-G-' . strtoupper(substr(hash('sha256', $parent_key), 0, 12)));
            }
        }

        // Build a full variation registry first. Selective imports add/update selected supplier
        // items without deleting variations that were already imported into this parent.
        $registry = array();
        if (!$parent_created && method_exists($parent, 'get_children')) {
            foreach ($parent->get_children() as $variation_id) {
                $remote_id = $this->sanitize_remote_id(get_post_meta($variation_id, '_dfr_offer_id', true));
                if ($remote_id === '') {
                    continue;
                }
                $variation = wc_get_product($variation_id);
                if (!$variation || !$variation->is_type('variation')) {
                    continue;
                }
                $attrs = $variation->get_attributes();
                $old_label = isset($attrs[$attribute_key]) ? sanitize_text_field($attrs[$attribute_key]) : '';
                if ($old_label === '' && $previous_attribute_key !== $attribute_key && isset($attrs[$previous_attribute_key])) {
                    $old_label = sanitize_text_field($attrs[$previous_attribute_key]);
                }
                if ($old_label === '') {
                    $old_label = sanitize_text_field(get_post_meta($variation_id, '_dfr_supplier_item_name', true));
                }
                $registry[$remote_id] = array(
                    'variation_id' => (int) $variation_id,
                    'label' => $old_label !== '' ? $old_label : $remote_id,
                    'selected' => false,
                    'item' => null,
                );
            }
        }

        foreach ($selected as $item) {
            $remote_id = $this->sanitize_remote_id($item['id'] ?? '');
            if ($remote_id === '') {
                continue;
            }
            $supplier_name = sanitize_text_field($item['name'] ?? $remote_id);
            if (isset($registry[$remote_id]) && $settings['catalog_update_titles'] !== '1' && $registry[$remote_id]['label'] !== '') {
                $label = $registry[$remote_id]['label'];
            } else {
                $label = $supplier_name !== '' ? $supplier_name : $remote_id;
            }
            $registry[$remote_id] = array(
                'variation_id' => isset($registry[$remote_id]['variation_id']) ? (int) $registry[$remote_id]['variation_id'] : 0,
                'label' => $label,
                'selected' => true,
                'item' => $item,
            );
        }
        if (!$registry) {
            return new WP_Error('dfr_no_variable_offers', __('No valid FazerCards items were available for the variable product.', 'delicat-fazercards'));
        }

        // Keep supplier catalog order for the selected set, then append existing unselected
        // variations. This makes denominations/packages appear predictably in WooCommerce.
        $ordered_registry = array();
        foreach ($selected as $item) {
            $remote_id = $this->sanitize_remote_id($item['id'] ?? '');
            if ($remote_id !== '' && isset($registry[$remote_id])) {
                $ordered_registry[$remote_id] = $registry[$remote_id];
            }
        }
        foreach ($registry as $remote_id => $row) {
            if (!isset($ordered_registry[$remote_id])) {
                $ordered_registry[$remote_id] = $row;
            }
        }
        $registry = $ordered_registry;

        // WooCommerce variation combinations must be unique. Only expose the supplier ID in
        // the visible option label when FazerCards itself returns duplicate names.
        $counts = array();
        foreach ($registry as $row) {
            $key = strtolower(trim((string) $row['label']));
            $counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;
        }
        $options = array();
        foreach ($registry as $remote_id => &$row) {
            $key = strtolower(trim((string) $row['label']));
            if (!empty($counts[$key]) && $counts[$key] > 1) {
                $row['label'] .= ' · ' . $remote_id;
            }
            $options[] = $row['label'];
        }
        unset($row);

        $attribute = new WC_Product_Attribute();
        $attribute->set_id(0);
        $attribute->set_name($attribute_label);
        $attribute->set_options(array_values(array_unique($options)));
        $attribute->set_position(0);
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $parent_attributes = array();
        foreach ((array) $parent->get_attributes('edit') as $existing_attribute) {
            if (!is_a($existing_attribute, 'WC_Product_Attribute')) {
                continue;
            }
            $existing_key = sanitize_title($existing_attribute->get_name());
            if ($existing_key === $attribute_key || $existing_key === $previous_attribute_key) {
                continue;
            }
            $parent_attributes[] = $existing_attribute;
        }
        $parent_attributes[] = $attribute;
        $parent->set_attributes($parent_attributes);
        $parent->set_manage_stock(false);
        $parent->set_stock_status('instock');
        $parent_id = $parent->save();
        if (!$parent_id) {
            return new WP_Error('dfr_variable_parent_save_failed', __('Could not save the WooCommerce variable product.', 'delicat-fazercards'));
        }

        update_post_meta($parent_id, '_dfr_imported', '1');
        update_post_meta($parent_id, '_dfr_catalog_key', $parent_key);
        update_post_meta($parent_id, '_dfr_import_structure', 'variable');
        update_post_meta($parent_id, '_dfr_service_type', $service);
        update_post_meta($parent_id, '_dfr_category_id', $category_id);
        delete_post_meta($parent_id, '_dfr_offer_id');
        update_post_meta($parent_id, '_dfr_variation_attribute_label', $attribute_label);
        update_post_meta($parent_id, '_dfr_supplier_category_name', sanitize_text_field($remote['category_name']));
        update_post_meta($parent_id, '_dfr_supplier_last_seen', current_time('mysql', true));
        update_post_meta($parent_id, '_dfr_price_protection_mode', $this->pricing_mode());

        if ($service === 'topup') {
            $fields = isset($remote['fields']) && is_array($remote['fields']) ? $remote['fields'] : array();
            update_post_meta($parent_id, '_dfr_field_spec_json', wp_json_encode($fields));
            $map = array();
            foreach ($fields as $field) {
                if (!empty($field['key']) && !empty($field['label'])) {
                    $map[] = sanitize_key($field['key']) . '=' . sanitize_text_field($field['label']);
                }
            }
            update_post_meta($parent_id, '_dfr_field_map', implode("\n", $map));
        } else {
            delete_post_meta($parent_id, '_dfr_field_spec_json');
            delete_post_meta($parent_id, '_dfr_field_map');
        }

        $variation_created = 0;
        $variation_updated = 0;
        $warnings = array();
        $variation_position = 0;

        foreach ($registry as $remote_id => $row) {
            $variation_id = (int) $row['variation_id'];
            $variation = $variation_id ? wc_get_product($variation_id) : new WC_Product_Variation();
            if (!$variation || ($variation_id && !$variation->is_type('variation'))) {
                $warnings[] = sprintf(__('Supplier item %s could not be loaded as a WooCommerce variation.', 'delicat-fazercards'), $remote_id);
                continue;
            }
            if (!$variation_id) {
                $variation->set_parent_id($parent_id);
                $variation->set_status('publish');
                $variation->set_virtual(true);
                $variation->set_manage_stock(false);
                $variation->set_stock_status('instock');
                $variation->set_sku('DEL-V-' . strtoupper(substr(hash('sha256', $service . '|' . $category_id . '|' . $remote_id), 0, 14)));
            }

            $variation_attributes = $variation_id ? (array) $variation->get_attributes() : array();
            if ($previous_attribute_key !== $attribute_key) {
                unset($variation_attributes[$previous_attribute_key]);
            }
            unset($variation_attributes[$attribute_key]);
            $variation_attributes[$attribute_key] = $row['label'];
            $variation->set_attributes($variation_attributes);
            $variation->set_menu_order($variation_position++);

            if ($row['selected'] && is_array($row['item'])) {
                $item = $row['item'];

                // Manual mode protects existing HTG prices and deliberately leaves new
                // variations unpriced until the merchant enters a selling price.
                if ($this->pricing_mode() === 'automatic') {
                    $price = $this->calculate_store_price((float) $item['price_usd']);
                    if (is_wp_error($price)) {
                        $warnings[] = $row['label'] . ': ' . $price->get_error_message();
                        continue;
                    }
                    $variation->set_regular_price(wc_format_decimal($price, wc_get_price_decimals()));
                    $variation->set_price(wc_format_decimal($price, wc_get_price_decimals()));
                }

                if ($service === 'topup') {
                    $variation->set_manage_stock(false);
                    $variation->set_stock_status('instock');
                } elseif ($item['stock'] !== null) {
                    $variation->set_manage_stock(true);
                    $variation->set_stock_quantity(max(0, (int) $item['stock']));
                    $variation->set_stock_status(((int) $item['stock']) > 0 ? 'instock' : 'outofstock');
                } else {
                    $variation->set_manage_stock(false);
                    $variation->set_stock_status('instock');
                }
            }

            $saved_id = $variation->save();
            if (!$saved_id) {
                $warnings[] = sprintf(__('Supplier item %s could not be saved as a WooCommerce variation.', 'delicat-fazercards'), $remote_id);
                continue;
            }

            if ($row['selected'] && is_array($row['item'])) {
                $item = $row['item'];
                update_post_meta($saved_id, '_dfr_imported', '1');
                update_post_meta($saved_id, '_dfr_catalog_key', $service . '|' . $category_id . '|' . $remote_id);
                update_post_meta($saved_id, '_dfr_import_structure', 'variation');
                update_post_meta($saved_id, '_dfr_service_type', $service);
                update_post_meta($saved_id, '_dfr_category_id', $category_id);
                update_post_meta($saved_id, '_dfr_offer_id', $remote_id);
                $this->record_supplier_cost($saved_id, (float) $item['price_usd']);
                update_post_meta($saved_id, '_dfr_price_protection_mode', $this->pricing_mode());
                update_post_meta($saved_id, '_dfr_supplier_stock', $item['stock'] === null ? '' : (string) $item['stock']);
                update_post_meta($saved_id, '_dfr_min_order_quantity', max(1, (int) $item['min_order_quantity']));
                update_post_meta($saved_id, '_dfr_max_order_quantity', max(1, (int) $item['max_order_quantity']));
                update_post_meta($saved_id, '_dfr_supplier_category_name', sanitize_text_field($remote['category_name']));
                update_post_meta($saved_id, '_dfr_supplier_item_name', sanitize_text_field($item['name']));
                update_post_meta($saved_id, '_dfr_supplier_last_seen', current_time('mysql', true));
                delete_post_meta($saved_id, '_dfr_supplier_missing');

                if ($variation_id) {
                    $variation_updated++;
                } else {
                    $variation_created++;
                }

                // Preserve merchant-customized legacy simple imports. Draft + unpriced legacy
                // records are hidden automatically so re-importing Netflix, Free Fire, etc.
                // does not leave duplicate catalog cards next to the new grouped product.
                $legacy = get_posts(array(
                    'post_type' => 'product',
                    'post_status' => array('publish', 'draft', 'private', 'pending'),
                    'posts_per_page' => 10,
                    'fields' => 'ids',
                    'no_found_rows' => true,
                    'meta_key' => '_dfr_catalog_key',
                    'meta_value' => $service . '|' . $category_id . '|' . $remote_id,
                ));
                foreach ($legacy as $legacy_id) {
                    $legacy_id = (int) $legacy_id;
                    if ($legacy_id === $parent_id) {
                        continue;
                    }
                    $legacy_product = wc_get_product($legacy_id);
                    if (!$legacy_product || !$legacy_product->is_type('simple')) {
                        continue;
                    }
                    update_post_meta($legacy_id, '_dfr_superseded_by_variable_product', $parent_id);
                    if ($legacy_product->get_status() === 'draft' && $legacy_product->get_regular_price() === '' && $legacy_product->get_sale_price() === '') {
                        $legacy_product->set_catalog_visibility('hidden');
                        $legacy_product->save();
                    } else {
                        $warnings[] = sprintf(
                            __('Legacy simple %1$s product #%2$d was preserved because it may contain merchant pricing/customization. Review it to avoid duplicate storefront products.', 'delicat-fazercards'),
                            $service === 'giftcard' ? __('gift-card', 'delicat-fazercards') : ($service === 'gamekey' ? __('game-key', 'delicat-fazercards') : __('top-up', 'delicat-fazercards')),
                            $legacy_id
                        );
                    }
                }
            }
        }

        $this->assign_product_category($parent_id, $service, $remote['category_name'], $category_id);
        if (class_exists('WC_Product_Variable')) {
            WC_Product_Variable::sync($parent_id);
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($parent_id);
        }
        clean_post_cache($parent_id);

        return array(
            'product_id' => $parent_id,
            'parent_created' => $parent_created,
            'variations_created' => $variation_created,
            'variations_updated' => $variation_updated,
            'warnings' => array_values(array_unique($warnings)),
        );
    }

    private function upsert_product($service, array $remote, array $item) {
        $catalog_key = $service . '|' . $remote['category_id'] . '|' . $item['id'];
        $existing = get_posts(array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'private', 'pending'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => '_dfr_catalog_key',
            'meta_value' => $catalog_key,
        ));
        $product_id = $existing ? (int) $existing[0] : 0;
        $created = $product_id <= 0;
        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();
        if (!$product) {
            return new WP_Error('dfr_product_load_failed', __('Could not load WooCommerce product.', 'delicat-fazercards'));
        }

        $settings = $this->settings();
        $title = $this->build_product_title($remote['category_name'], $item['name']);
        if ($created || $settings['catalog_update_titles'] === '1') {
            $product->set_name($title);
        }
        if ($created) {
            $status = in_array($settings['catalog_import_status'], array('draft', 'publish', 'private'), true) ? $settings['catalog_import_status'] : 'draft';
            // A newly imported product has no merchant-defined price in manual mode.
            // Keep it out of the storefront until the WooCommerce price is deliberately set.
            if ($this->pricing_mode() === 'manual' && $status === 'publish') {
                $status = 'draft';
            }
            $product->set_status($status);
            $product->set_virtual(true);
            $product->set_catalog_visibility('visible');
            $product->set_description($this->build_description($service, $remote['category_name'], $item));
            $product->set_short_description(__('Livraison numérique automatique et sécurisée par Delicat Store.', 'delicat-fazercards'));
        }

        // Customer-facing WooCommerce prices and supplier USD costs are separate.
        // In manual mode, imports and re-imports must never overwrite a price chosen by the merchant.
        if ($this->pricing_mode() === 'automatic') {
            $price = $this->calculate_store_price((float) $item['price_usd']);
            if (is_wp_error($price)) {
                return $price;
            }
            $product->set_regular_price(wc_format_decimal($price, wc_get_price_decimals()));
            $product->set_price(wc_format_decimal($price, wc_get_price_decimals()));
        }

        if ($service === 'topup') {
            $product->set_sold_individually(true);
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
        } else {
            $product->set_sold_individually(false);
            if ($item['stock'] !== null) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity((int) $item['stock']);
                $product->set_stock_status(((int) $item['stock']) > 0 ? 'instock' : 'outofstock');
            }
        }

        if ($created && !$product->get_sku()) {
            $product->set_sku('DEL-' . strtoupper(substr(hash('sha256', $catalog_key), 0, 14)));
        }
        $product_id = $product->save();
        if (!$product_id) {
            return new WP_Error('dfr_product_save_failed', __('Could not save WooCommerce product.', 'delicat-fazercards'));
        }

        update_post_meta($product_id, '_dfr_imported', '1');
        update_post_meta($product_id, '_dfr_catalog_key', $catalog_key);
        update_post_meta($product_id, '_dfr_service_type', $service);
        update_post_meta($product_id, '_dfr_category_id', $remote['category_id']);
        update_post_meta($product_id, '_dfr_offer_id', $item['id']);
        $this->record_supplier_cost($product_id, (float) $item['price_usd']);
        update_post_meta($product_id, '_dfr_price_protection_mode', $this->pricing_mode());
        update_post_meta($product_id, '_dfr_supplier_stock', $item['stock'] === null ? '' : (string) $item['stock']);
        update_post_meta($product_id, '_dfr_min_order_quantity', max(1, (int) $item['min_order_quantity']));
        update_post_meta($product_id, '_dfr_max_order_quantity', max(1, (int) $item['max_order_quantity']));
        update_post_meta($product_id, '_dfr_supplier_category_name', $remote['category_name']);
        update_post_meta($product_id, '_dfr_supplier_item_name', $item['name']);
        update_post_meta($product_id, '_dfr_supplier_last_seen', current_time('mysql', true));
        delete_post_meta($product_id, '_dfr_supplier_missing');

        if ($service === 'topup') {
            $fields = isset($remote['fields']) && is_array($remote['fields']) ? $remote['fields'] : array();
            update_post_meta($product_id, '_dfr_field_spec_json', wp_json_encode($fields));
            $map = array();
            foreach ($fields as $field) {
                if (!empty($field['key']) && !empty($field['label'])) {
                    $map[] = sanitize_key($field['key']) . '=' . sanitize_text_field($field['label']);
                }
            }
            update_post_meta($product_id, '_dfr_field_map', implode("\n", $map));
        }

        $this->assign_product_category($product_id, $service, $remote['category_name'], $remote['category_id']);
        return array('product_id' => $product_id, 'created' => $created);
    }

    private function build_product_title($category, $item) {
        $category = sanitize_text_field($category);
        $item = sanitize_text_field($item);
        if ($category === '' || strcasecmp($category, $item) === 0 || stripos($item, $category) === 0) {
            return $item;
        }
        return $category . ' — ' . $item;
    }

    private function build_description($service, $category, array $item) {
        $labels = array('topup' => __('Game top-up', 'delicat-fazercards'), 'giftcard' => __('Gift card', 'delicat-fazercards'), 'gamekey' => __('Game key', 'delicat-fazercards'));
        $text = sprintf(__('Service numérique Delicat Store : %1$s / %2$s. Disponibilité synchronisée automatiquement.', 'delicat-fazercards'), $labels[$service], sanitize_text_field($category));
        return wp_kses_post($text);
    }

    private function assign_product_category($product_id, $service, $category_name, $category_id) {
        if (!taxonomy_exists('product_cat')) {
            return;
        }
        $root = term_exists('Delicat Digital', 'product_cat');
        if (!$root) {
            $root = get_term_by('slug', 'delicat-digital', 'product_cat');
        }
        if (!$root) {
            $root = wp_insert_term('Delicat Digital', 'product_cat', array('slug' => 'delicat-digital'));
        }
        if (is_wp_error($root)) {
            return;
        }
        $root_id = is_array($root) ? (int) $root['term_id'] : (int) $root;
        $slug = 'delicat-' . $service . '-' . substr(md5($category_id), 0, 10);
        $child = get_term_by('slug', $slug, 'product_cat');
        if (!$child) {
            $insert = wp_insert_term(sanitize_text_field($category_name), 'product_cat', array('slug' => $slug, 'parent' => $root_id));
            if (is_wp_error($insert)) {
                return;
            }
            $child_id = (int) $insert['term_id'];
        } else {
            $child_id = (int) $child->term_id;
        }
        wp_set_object_terms($product_id, array($root_id, $child_id), 'product_cat', false);
    }

    private function record_supplier_cost($product_id, $new_cost) {
        $new_cost = max(0, (float) $new_cost);
        $old_raw = get_post_meta($product_id, '_dfr_supplier_cost_usd', true);
        $old_cost = is_numeric($old_raw) ? (float) $old_raw : null;
        $changed = $old_cost !== null && abs($new_cost - $old_cost) > 0.00005;

        if ($changed) {
            update_post_meta($product_id, '_dfr_supplier_cost_previous_usd', wc_format_decimal($old_cost, 4));
            update_post_meta($product_id, '_dfr_supplier_cost_delta_usd', wc_format_decimal($new_cost - $old_cost, 4));
            update_post_meta($product_id, '_dfr_supplier_cost_changed_at', current_time('mysql', true));
        }

        update_post_meta($product_id, '_dfr_supplier_cost_usd', wc_format_decimal($new_cost, 4));
        return $changed;
    }

    private function calculate_store_price($cost_usd) {
        $settings = $this->settings();
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        $rate = $currency === 'USD' ? 1.0 : (float) $settings['usd_to_store_rate'];
        if ($rate <= 0) {
            return new WP_Error('dfr_fx_required', sprintf(__('A USD → %s exchange rate is required.', 'delicat-fazercards'), $currency));
        }
        $markup_percent = max(0, min(1000, (float) $settings['catalog_markup_percent']));
        $fixed = max(0, (float) $settings['catalog_fixed_markup']);
        $price = ((float) $cost_usd * $rate) * (1 + ($markup_percent / 100)) + $fixed;
        $step = max(0, (float) $settings['catalog_rounding']);
        if ($step > 0) {
            $price = ceil($price / $step) * $step;
        }
        return max(0, $price);
    }

    /**
     * Every imported product, grouped by supplier service + category.
     *
     * 4.15.0: pages through the WHOLE catalogue. The previous single
     * posts_per_page=500 query silently excluded every product past the first
     * page from synchronization for the life of the install.
     *
     * @return array<string,array{service:string,category_id:string,products:int[]}>
     */
    private function imported_product_groups() {
        $groups = array();
        $per_page = 500;
        $paged = 1;
        $guard = 0;
        do {
            $ids = get_posts(array(
                'post_type' => array('product', 'product_variation'),
                'post_status' => array('publish', 'draft', 'private', 'pending'),
                'posts_per_page' => $per_page,
                'paged' => $paged,
                'fields' => 'ids',
                'no_found_rows' => true,
                'orderby' => 'ID',
                'order' => 'ASC',
                'meta_key' => '_dfr_imported',
                'meta_value' => '1',
                'suppress_filters' => false,
            ));
            foreach ($ids as $product_id) {
                $service = $this->sanitize_service(get_post_meta($product_id, '_dfr_service_type', true));
                $category_id = $this->sanitize_remote_id(get_post_meta($product_id, '_dfr_category_id', true));
                if (!$service || !$category_id) {
                    continue;
                }
                $key = $service . '|' . $category_id;
                if (!isset($groups[$key])) {
                    $groups[$key] = array('service' => $service, 'category_id' => $category_id, 'products' => array());
                }
                $groups[$key]['products'][] = (int) $product_id;
            }
            $fetched = count($ids);
            $paged++;
            $guard++;
        } while ($fetched === $per_page && $guard < 400); // 200k products, then stop rather than loop forever.

        return $groups;
    }

    /**
     * Which groups this run should process, starting after the cursor and
     * wrapping around the end.
     *
     * Pure and static so it can be tested directly: this is the function that
     * decides whether a catalogue is fully covered or silently truncated, and the
     * bug it replaces was invisible precisely because nothing exercised it.
     *
     * @param string[] $all_keys Every group key, in a stable order.
     * @param string   $cursor   Last group key completed, '' to start at the top.
     * @param int      $budget   Maximum groups this run.
     * @return string[]
     */
    public static function sync_batch_keys(array $all_keys, $cursor, $budget) {
        $total = count($all_keys);
        $budget = max(0, (int) $budget);
        if ($total === 0 || $budget === 0) {
            return array();
        }
        $start = 0;
        $cursor = (string) $cursor;
        if ($cursor !== '') {
            $pos = array_search($cursor, $all_keys, true);
            // An unknown cursor (the group was deleted, or the catalogue changed
            // shape) restarts from the top rather than skipping an arbitrary slice.
            $start = ($pos === false) ? 0 : (int) (($pos + 1) % $total);
        }
        $ordered = array_merge(array_slice($all_keys, $start), array_slice($all_keys, 0, $start));
        return array_slice($ordered, 0, min($budget, $total));
    }

    /**
     * The current coverage cycle. Reset whenever the number of groups changes,
     * because a ledger counted against a different total proves nothing.
     *
     * @param int $total_groups Groups currently known.
     * @return array{started:string,done:array<string,int>,total:int}
     */
    private function sync_cycle_state($total_groups) {
        $cycle = get_option(self::SYNC_CYCLE_OPTION, array());
        if (
            !is_array($cycle)
            || empty($cycle['started'])
            || !isset($cycle['done']) || !is_array($cycle['done'])
            || (int) ($cycle['total'] ?? -1) !== (int) $total_groups
        ) {
            $cycle = array('started' => current_time('mysql', true), 'done' => array(), 'total' => (int) $total_groups);
        }
        return $cycle;
    }

    /**
     * Synchronization coverage, for the admin screen and for anyone asking
     * "is every imported product actually being kept in step with the supplier?".
     *
     * @return array
     */
    public function sync_coverage() {
        $groups = $this->imported_product_groups();
        $total = count($groups);
        $products = 0;
        foreach ($groups as $group) { $products += count($group['products']); }
        $cycle = $this->sync_cycle_state($total);
        $last_full = get_option(self::SYNC_LAST_FULL_CYCLE_OPTION, array());
        return array(
            'groups' => $total,
            'products' => $products,
            'covered_this_cycle' => count($cycle['done']),
            'cycle_started' => (string) $cycle['started'],
            'last_full_cycle' => is_array($last_full) ? ($last_full['completed'] ?? '') : '',
            'cursor' => (string) get_option(self::SYNC_CURSOR_OPTION, ''),
        );
    }

    private function sync_imported_products($cron = false) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('dfr_woocommerce_missing', __('WooCommerce is not active.', 'delicat-fazercards'));
        }
        $lock = $this->acquire_db_lock('catalog_sync', 1);
        if ($lock === '') {
            return new WP_Error('dfr_sync_locked', __('A catalog sync is already running.', 'delicat-fazercards'));
        }

        try {
            $groups = $this->imported_product_groups();
            $all_keys = array_keys($groups);
            sort($all_keys, SORT_STRING);
            $total_groups = count($all_keys);

            /*
             * 4.15.0 — ROTATING COVERAGE.
             *
             * This used to read at most 500 imported products with no paging, then
             * take array_slice($groups, 0, 30) — the SAME first thirty category
             * groups on every single run. On a catalogue larger than that, the tail
             * was never synchronized at all: stock never changed and a product
             * withdrawn upstream was never detected, no matter how long the site
             * ran. Measured on a 1,800-product / 45-category store: 15 categories
             * and 1,300 products were permanently stale.
             *
             * Groups are now ordered deterministically and processed from a
             * persisted cursor that wraps around, so every group is reached within
             * ceil(total / budget) runs. The cycle ledger below turns that into a
             * fact the merchant can see rather than a promise.
             */
            $budget = (int) apply_filters('dfr_catalog_sync_group_budget', $cron ? 30 : 60, $cron, $total_groups);
            $budget = max(1, min(500, $budget));

            $cursor = (string) get_option(self::SYNC_CURSOR_OPTION, '');
            $batch_keys = self::sync_batch_keys($all_keys, $cursor, $budget);

            $cycle = $this->sync_cycle_state($total_groups);

            $selected = array();
            foreach ($batch_keys as $batch_key) {
                if (isset($groups[$batch_key])) {
                    $selected[$batch_key] = $groups[$batch_key];
                }
            }
            $groups = $selected;
            $updated = 0;
            $missing = 0;
            $errors = 0;
            $cost_changes = 0;
            $settings = $this->settings();
            $touched_variable_parents = array();

            $empty_streaks = get_option(self::SYNC_EMPTY_STREAK_OPTION, array());
            $empty_streaks = is_array($empty_streaks) ? $empty_streaks : array();
            $last_key = $cursor;
            $skipped_suspect = 0;
            $stopped_early = false;

            /*
             * 4.15.0 — a wall-clock budget. Each group is a live supplier HTTP call,
             * so a full batch can outrun max_execution_time on a shared host. A run
             * that dies mid-way leaves the cursor un-advanced and repeats the same
             * groups forever; stopping cleanly advances the cursor over what was
             * actually finished, so the next run continues from there.
             */
            $limit = (int) ini_get('max_execution_time');
            $time_budget = $limit > 0 ? max(10.0, $limit * 0.6) : 60.0;
            $time_budget = (float) apply_filters('dfr_catalog_sync_time_budget', $time_budget, $cron);
            $started_at = microtime(true);

            foreach ($groups as $group_key => $group) {
                if ((microtime(true) - $started_at) > $time_budget) {
                    $stopped_early = true;
                    break;
                }
                $remote = $this->get_offers($group['service'], $group['category_id'], true);
                if (is_wp_error($remote)) {
                    // A failed fetch proves nothing about the supplier's catalogue.
                    // Do NOT advance the cursor past it and do NOT treat its products
                    // as withdrawn; the next run retries this same group.
                    $errors++;
                    continue;
                }
                $remote_map = array();
                foreach ($remote['items'] as $item) {
                    $remote_map[$item['id']] = $item;
                }

                /*
                 * 4.15.0 — never let one odd response empty a whole category.
                 *
                 * Marking every product in a category as withdrawn is destructive and
                 * hard to notice, so an empty offer list for a category that still has
                 * imported products is treated as suspect the first two times and only
                 * believed on the third consecutive occurrence. A genuine
                 * discontinuation still converges; a transient supplier blip does not
                 * pull the catalogue out of stock.
                 */
                if (!$remote_map && $group['products']) {
                    $streak = (int) ($empty_streaks[$group_key] ?? 0) + 1;
                    $empty_streaks[$group_key] = $streak;
                    if ($streak < 3) {
                        $skipped_suspect++;
                        $this->plugin()->log('warning', 'Supplier returned an empty catalog for a category that still has imported products; treating as suspect', array(
                            'group' => $group_key,
                            'streak' => $streak,
                        ));
                        $last_key = $group_key;
                        $cycle['done'][$group_key] = 1;
                        continue;
                    }
                } else {
                    unset($empty_streaks[$group_key]);
                }
                $last_key = $group_key;
                $cycle['done'][$group_key] = 1;
                foreach ($group['products'] as $product_id) {
                    $offer_id = (string) get_post_meta($product_id, '_dfr_offer_id', true);
                    // Variable parents intentionally have no offer ID; their children do.
                    if ($offer_id === '') {
                        continue;
                    }
                    $product = wc_get_product($product_id);
                    if (!$product) {
                        continue;
                    }
                    if ($product->get_parent_id()) {
                        $touched_variable_parents[(int) $product->get_parent_id()] = true;
                    }
                    if (!isset($remote_map[$offer_id])) {
                        /*
                         * 4.15.0 — a withdrawn offer always leaves the shelf.
                         *
                         * Out-of-stock used to be conditional on the
                         * catalog_disable_missing setting, so with it off the store
                         * kept selling offers the supplier no longer had: the order
                         * is taken and paid, fulfillment then fails, and the merchant
                         * refunds by hand. Nothing is gained by continuing to offer
                         * an item that cannot be delivered, so this is now
                         * unconditional. The setting still controls the stronger
                         * action of unpublishing the product entirely.
                         */
                        $streak = (int) get_post_meta($product_id, '_dfr_supplier_miss_streak', true) + 1;
                        update_post_meta($product_id, '_dfr_supplier_miss_streak', $streak);
                        update_post_meta($product_id, '_dfr_supplier_missing', '1');
                        update_post_meta($product_id, '_dfr_supplier_missing_since', current_time('mysql', true));
                        $product->set_stock_status('outofstock');
                        if ($product->get_manage_stock()) {
                            $product->set_stock_quantity(0);
                        }
                        // Unpublishing is irreversible-ish for SEO and customer links,
                        // so it waits for a second consecutive confirmation.
                        if ($settings['catalog_disable_missing'] === '1' && $streak >= 2 && $product->get_status() === 'publish') {
                            $product->set_status('draft');
                        }
                        $product->save();
                        if ($product->get_parent_id()) {
                            $touched_variable_parents[(int) $product->get_parent_id()] = true;
                        }
                        $missing++;
                        continue;
                    }
                    delete_post_meta($product_id, '_dfr_supplier_miss_streak');
                    delete_post_meta($product_id, '_dfr_supplier_missing_since');
                    $item = $remote_map[$offer_id];
                    if ($this->record_supplier_cost($product_id, (float) $item['price_usd'])) {
                        $cost_changes++;
                    }
                    update_post_meta($product_id, '_dfr_price_protection_mode', $this->pricing_mode());
                    update_post_meta($product_id, '_dfr_supplier_stock', $item['stock'] === null ? '' : (string) $item['stock']);
        update_post_meta($product_id, '_dfr_min_order_quantity', max(1, (int) $item['min_order_quantity']));
        update_post_meta($product_id, '_dfr_max_order_quantity', max(1, (int) $item['max_order_quantity']));
                    update_post_meta($product_id, '_dfr_supplier_last_seen', current_time('mysql', true));
                    delete_post_meta($product_id, '_dfr_supplier_missing');

                    if ($this->pricing_mode() === 'automatic' && $settings['catalog_price_sync'] === '1') {
                        $price = $this->calculate_store_price((float) $item['price_usd']);
                        if (!is_wp_error($price)) {
                            $product->set_regular_price(wc_format_decimal($price, wc_get_price_decimals()));
                            $product->set_price(wc_format_decimal($price, wc_get_price_decimals()));
                        }
                    }
                    if ($group['service'] !== 'topup' && $item['stock'] !== null) {
                        $product->set_manage_stock(true);
                        $product->set_stock_quantity((int) $item['stock']);
                        $product->set_stock_status(((int) $item['stock']) > 0 ? 'instock' : 'outofstock');
                    } elseif ($group['service'] === 'topup') {
                        $product->set_stock_status('instock');
                    }
                    $product->save();
                    $updated++;
                }
            }
            foreach (array_keys($touched_variable_parents) as $parent_id) {
                if (class_exists('WC_Product_Variable')) {
                    WC_Product_Variable::sync((int) $parent_id);
                }
                if (function_exists('wc_delete_product_transients')) {
                    wc_delete_product_transients((int) $parent_id);
                }
            }
            update_option(self::SYNC_CURSOR_OPTION, (string) $last_key, false);
            update_option(self::SYNC_EMPTY_STREAK_OPTION, $empty_streaks, false);

            $covered = count($cycle['done']);
            $cycle_complete = ($total_groups > 0 && $covered >= $total_groups);
            if ($stopped_early) {
                $this->plugin()->log('info', 'Catalog sync stopped on its time budget; the next run resumes from the cursor', array(
                    'cursor' => (string) $last_key,
                    'covered_this_cycle' => $covered,
                    'groups_total' => $total_groups,
                ));
            }
            if ($cycle_complete) {
                update_option(self::SYNC_LAST_FULL_CYCLE_OPTION, array(
                    'completed' => current_time('mysql', true),
                    'started' => (string) $cycle['started'],
                    'groups' => (int) $total_groups,
                ), false);
                // Start the next pass from a clean ledger.
                $cycle = array('started' => current_time('mysql', true), 'done' => array(), 'total' => $total_groups);
            }
            update_option(self::SYNC_CYCLE_OPTION, $cycle, false);

            update_option('dfr_catalog_last_sync', current_time('mysql', true), false);
            $result = array(
                'updated' => $updated,
                'missing' => $missing,
                'errors' => $errors,
                'cost_changes' => $cost_changes,
                'groups_total' => $total_groups,
                'groups_this_run' => count($groups),
                'groups_covered_this_cycle' => $cycle_complete ? $total_groups : $covered,
                'cycle_complete' => $cycle_complete,
                'suspect_empty_categories' => $skipped_suspect,
                'stopped_early' => $stopped_early,
                'pricing_mode' => $this->pricing_mode(),
                'message' => sprintf(__('%1$d products synchronized; %2$d supplier cost changes detected; %3$d missing items; %4$d category errors. WooCommerce prices %5$s.', 'delicat-fazercards'), $updated, $cost_changes, $missing, $errors, $this->pricing_mode() === 'manual' ? __('were protected', 'delicat-fazercards') : __('follow the automatic pricing rules', 'delicat-fazercards')),
            );
            $this->plugin()->log('info', 'Catalog synchronization completed', $result);
            return $result;
        } finally {
            $this->release_db_lock($lock);
        }
    }

    private function get_field_spec_for_product($product_id) {
        $settings = $this->settings();
        if (empty($settings['native_fields_enabled']) || $settings['native_fields_enabled'] !== '1') {
            return array();
        }
        if (get_post_meta($product_id, '_dfr_service_type', true) !== 'topup') {
            return array();
        }
        $json = (string) get_post_meta($product_id, '_dfr_field_spec_json', true);
        $fields = json_decode($json, true);
        return is_array($fields) ? $this->sanitize_field_spec($fields) : array();
    }

    private function uid_validation_enabled() {
        $settings = $this->settings();
        return !empty($settings['uid_validation_enabled']) && $settings['uid_validation_enabled'] === '1';
    }

    private function uid_validation_required() {
        $settings = $this->settings();
        return $this->uid_validation_enabled() && !empty($settings['uid_validation_required']) && $settings['uid_validation_required'] === '1';
    }

    private function uid_product_has_player_identity_field($product_id) {
        foreach ($this->get_field_spec_for_product($product_id) as $field) {
            if (!is_array($field) || empty($field['key'])) { continue; }
            if ($this->uid_field_role((string) $field['key'], isset($field['label']) ? (string) $field['label'] : '') === 'player') {
                return true;
            }
        }
        return false;
    }

    private function uid_validation_games($force_refresh = false) {
        if (!$this->uid_validation_enabled() || !$this->plugin()->has_api_key()) {
            return array();
        }
        if ($force_refresh) {
            delete_transient('dfr_uid_validation_games_v3');
        }
        $cached = get_transient('dfr_uid_validation_games_v3');
        if (!$force_refresh && is_array($cached)) {
            return $cached;
        }
        $result = $this->plugin()->api()->topup_validation_games();
        if (is_wp_error($result) || empty($result['ok']) || empty($result['items']) || !is_array($result['items'])) {
            return array();
        }
        $items = array();
        foreach (array_slice($result['items'], 0, 500) as $item) {
            if (!is_array($item) || empty($item['category_id'])) { continue; }
            $cid = substr(sanitize_text_field((string) $item['category_id']), 0, 190);
            if ($cid === '') { continue; }
            $items[$cid] = array(
                'category_id' => $cid,
                'name' => isset($item['name']) ? substr(sanitize_text_field((string) $item['name']), 0, 190) : '',
                'fields' => !empty($item['fields']) && is_array($item['fields']) ? $this->sanitize_field_spec($item['fields']) : array(),
            );
        }
        // FazerCards documents this list as dynamic. Keep it fresh but never fetch
        // it on every product-page request.
        set_transient('dfr_uid_validation_games_v3', $items, 10 * MINUTE_IN_SECONDS);
        return $items;
    }

    /**
     * Normalize a supplier game/category label for validation-family matching.
     * FazerCards' validation catalog can use a canonical game category (for
     * example "Free Fire") while the sellable catalog contains regional
     * variants (for example "Free Fire LATAM"). The order category must stay
     * untouched; this normalized label is used only to resolve the validation
     * category returned by GET /topups/validate-id.
     */
    private function uid_normalize_game_name($value) {
        $value = remove_accents(wp_strip_all_tags((string) $value));
        $value = strtolower($value);
        $value = str_replace(array('&', '+'), ' and ', $value);
        $value = preg_replace('/[\(\[\{][^\)\]\}]*[\)\]\}]/u', ' ', $value);
        $value = str_replace(array('—', '–', '_', '/', '\\', '|', ':', '-'), ' ', $value);
        $value = preg_replace('/\bv\s*\d+\b/i', ' ', $value);
        $tokens = preg_split('/[^a-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        $drop = array(
            'latam','latin','america','usa','us','europe','eu','mena','global','worldwide',
            'brazil','br','argentina','chile','colombia','mexico','peru',
            'indonesia','malaysia','philippines','thailand','vietnam','singapore',
            'india','turkey','taiwan','japan','korea','hong','kong','cis','uae',
            'saudi','arabia','canada','region','server','topup','top','up','recharge',
            'diamonds','diamond','credits','credit','coins','coin','points','point'
        );
        $tokens = array_values(array_filter((array) $tokens, static function ($token) { return $token !== ''; }));
        while ($tokens && in_array((string) end($tokens), $drop, true)) {
            array_pop($tokens);
        }
        return trim(implode(' ', $tokens));
    }

    /**
     * Return a conservative canonical game family. This is used only to map a
     * sellable regional category to a category returned by GET /topups/validate-id;
     * it never changes the category/offer later used to place the real order.
     */
    private function uid_game_family_key($name, $category_id = '') {
        $haystack = trim($this->uid_normalize_game_name($name) . ' ' . $this->uid_normalize_game_name($category_id));
        $haystack = ' ' . preg_replace('/\s+/', ' ', $haystack) . ' ';
        $aliases = array(
            'free fire' => array('free fire max', 'garena free fire', 'free fire'),
            'pubg mobile' => array('playerunknowns battlegrounds mobile', 'pubg mobile', 'pubgm'),
            'mobile legends' => array('mobile legends bang bang', 'mobile legends', 'mlbb'),
            'call of duty mobile' => array('call of duty mobile', 'cod mobile', 'codm'),
            'genshin impact' => array('genshin impact', 'genshin'),
            'honkai star rail' => array('honkai star rail', 'star rail'),
            'honor of kings' => array('honor of kings', 'hok'),
            'arena breakout' => array('arena breakout'),
            'blood strike' => array('blood strike'),
            'brawl stars' => array('brawl stars'),
            'clash of clans' => array('clash of clans', 'coc'),
            'clash royale' => array('clash royale'),
            'league of legends wild rift' => array('league of legends wild rift', 'wild rift'),
            'valorant' => array('valorant'),
            'delta force' => array('delta force'),
            'zenless zone zero' => array('zenless zone zero', 'zzz'),
            'undawn' => array('undawn'),
        );
        foreach ($aliases as $family => $needles) {
            foreach ($needles as $needle) {
                $needle = trim($this->uid_normalize_game_name($needle));
                if ($needle !== '' && strpos($haystack, ' ' . $needle . ' ') !== false) {
                    return $family;
                }
            }
        }
        return trim($this->uid_normalize_game_name($name));
    }

    /** Map field-name variations without ever changing the outgoing FazerCards key. */
    private function uid_field_role($key, $label = '') {
        $value = strtolower(remove_accents((string) $key . ' ' . (string) $label));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        $compact = str_replace(' ', '', trim($value));
        $roles = array(
            'player' => array('playerid','playeruid','uid','userid','useruid','roleid','accountid','gameuid'),
            'server' => array('serverid','server','zoneid','zone','realm','realmid'),
            'region' => array('regionid','region','countryid','country'),
            'login'  => array('login','username','accountlogin','steamlogin'),
        );
        foreach ($roles as $role => $aliases) {
            foreach ($aliases as $alias) {
                if ($compact === $alias || strpos($compact, $alias) !== false) { return $role; }
            }
        }
        return '';
    }

    /**
     * Build validation_key => storefront_product_key. Exact API keys win;
     * otherwise only unique, conservative role/label aliases are accepted.
     */
    private function uid_validation_field_map($product_id, array $game) {
        $product_fields = $this->get_field_spec_for_product($product_id);
        $validation_fields = !empty($game['fields']) && is_array($game['fields']) ? $game['fields'] : array();
        if (!$product_fields || !$validation_fields) { return false; }

        $by_key = array();
        foreach ($product_fields as $field) {
            if (!is_array($field) || empty($field['key'])) { continue; }
            $key = sanitize_key((string) $field['key']);
            $by_key[$key] = array(
                'key' => $key,
                'label' => isset($field['label']) ? (string) $field['label'] : $key,
                'role' => $this->uid_field_role($key, isset($field['label']) ? $field['label'] : ''),
            );
        }
        $map = array();
        $used = array();
        foreach ($validation_fields as $spec) {
            if (!is_array($spec) || empty($spec['key'])) { continue; }
            $vkey = sanitize_key((string) $spec['key']);
            if ($vkey === '') { continue; }
            $required = array_key_exists('required', $spec) ? !empty($spec['required']) : true;

            if (isset($by_key[$vkey])) {
                $map[$vkey] = $vkey;
                $used[$vkey] = true;
                continue;
            }

            $vlabel = isset($spec['label']) ? (string) $spec['label'] : $vkey;
            $vrole = $this->uid_field_role($vkey, $vlabel);
            $candidates = array();
            foreach ($by_key as $pkey => $pfield) {
                if (isset($used[$pkey])) { continue; }
                if ($vrole !== '' && $pfield['role'] === $vrole) { $candidates[$pkey] = true; continue; }
                $a = preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents($vlabel)));
                $b = preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents($pfield['label'])));
                if ($a !== '' && $b !== '' && hash_equals($a, $b)) { $candidates[$pkey] = true; }
            }
            if (count($candidates) === 1) {
                $pkey = (string) array_key_first($candidates);
                $map[$vkey] = $pkey;
                $used[$pkey] = true;
                continue;
            }
            if ($required) { return false; }
        }
        return $map ?: false;
    }

    private function uid_validation_fields_compatible($product_id, array $game) {
        return is_array($this->uid_validation_field_map($product_id, $game));
    }

    private function uid_map_product_values_to_validation($product_id, array $game, array $product_values) {
        $map = $this->uid_validation_field_map($product_id, $game);
        if (!is_array($map)) { return new WP_Error('dfr_uid_field_map', __('Player verification fields could not be mapped safely.', 'delicat-fazercards')); }
        $validation_fields = array();
        $specs = !empty($game['fields']) && is_array($game['fields']) ? $game['fields'] : array();
        foreach ($specs as $spec) {
            if (!is_array($spec) || empty($spec['key'])) { continue; }
            $vkey = sanitize_key((string) $spec['key']);
            $required = array_key_exists('required', $spec) ? !empty($spec['required']) : true;
            $pkey = isset($map[$vkey]) ? sanitize_key((string) $map[$vkey]) : '';
            $value = $pkey !== '' && isset($product_values[$pkey]) && !is_array($product_values[$pkey]) && !is_object($product_values[$pkey])
                ? trim(sanitize_text_field((string) $product_values[$pkey])) : '';
            if ($required && $value === '') {
                return new WP_Error('dfr_uid_field_required', sprintf(__('Enter %s before verification.', 'delicat-fazercards'), isset($spec['label']) ? sanitize_text_field((string) $spec['label']) : $vkey));
            }
            if ($value !== '') {
                $type = sanitize_key((string) ($spec['type'] ?? 'text'));
                if ($type === 'number' && !is_numeric($value)) {
                    return new WP_Error('dfr_uid_field_type', __('A numeric player/server field is invalid.', 'delicat-fazercards'));
                }
                if ($type === 'email' && !is_email($value)) {
                    return new WP_Error('dfr_uid_field_type', __('A player account email is invalid.', 'delicat-fazercards'));
                }
                if ($type === 'select' && !empty($spec['options']) && is_array($spec['options']) && !array_key_exists($value, $spec['options'])) {
                    return new WP_Error('dfr_uid_field_option', __('A player/server option is invalid.', 'delicat-fazercards'));
                }
                $validation_fields[$vkey] = substr($value, 0, 190);
            }
        }
        return $validation_fields;
    }

    private function uid_match_score($supplier_name, $supplier_id, array $game) {
        $supplier_family = $this->uid_game_family_key($supplier_name, $supplier_id);
        $candidate_name = isset($game['name']) ? (string) $game['name'] : '';
        $candidate_id = isset($game['category_id']) ? (string) $game['category_id'] : '';
        $candidate_family = $this->uid_game_family_key($candidate_name, $candidate_id);
        if ($supplier_family !== '' && $candidate_family !== '' && hash_equals($supplier_family, $candidate_family)) { return 100; }

        $a = array_values(array_unique(preg_split('/\s+/', $this->uid_normalize_game_name($supplier_name . ' ' . $supplier_id), -1, PREG_SPLIT_NO_EMPTY)));
        $b = array_values(array_unique(preg_split('/\s+/', $this->uid_normalize_game_name($candidate_name . ' ' . $candidate_id), -1, PREG_SPLIT_NO_EMPTY)));
        $noise = array('mobile','game','games','online','official','garena');
        $a = array_values(array_diff($a, $noise));
        $b = array_values(array_diff($b, $noise));
        if (!$a || !$b) { return 0; }
        $intersection = count(array_intersect($a, $b));
        if ($intersection < 1) { return 0; }
        $union = count(array_unique(array_merge($a, $b)));
        $jaccard = $union > 0 ? $intersection / $union : 0;
        $containment = $intersection / min(count($a), count($b));
        return (int) round(100 * max($jaccard, $containment * 0.92));
    }

    /**
     * Resolve every imported top-up against the live FazerCards validation list.
     * Exact IDs win; then an explicit override; then a unique game-family match;
     * finally a high-confidence unique token match. The sellable regional ID is
     * never replaced and required validation fields must map safely.
     */
    private function uid_validation_context($product_id, $force_refresh = false) {
        if (!$this->uid_validation_enabled()) { return false; }
        $product_id = absint($product_id);
        if (!$product_id || get_post_meta($product_id, '_dfr_service_type', true) !== 'topup') { return false; }

        $order_category_id = substr(sanitize_text_field((string) get_post_meta($product_id, '_dfr_category_id', true)), 0, 190);
        if ($order_category_id === '') { return false; }
        $games = $this->uid_validation_games($force_refresh);
        if (!$games) { return false; }

        $make_context = function ($validation_id, array $game, $match) use ($product_id, $order_category_id) {
            $field_map = $this->uid_validation_field_map($product_id, $game);
            if (!is_array($field_map)) { return false; }
            return array(
                'order_category_id'      => $order_category_id,
                'validation_category_id' => (string) $validation_id,
                'game'                   => $game,
                'field_map'              => $field_map,
                'match'                  => $match,
            );
        };

        if (isset($games[$order_category_id])) {
            $ctx = $make_context($order_category_id, $games[$order_category_id], 'exact');
            if ($ctx) { return $ctx; }
        }

        $override = substr(sanitize_text_field((string) get_post_meta($product_id, '_dfr_uid_validation_category_id', true)), 0, 190);
        if ($override !== '' && isset($games[$override])) {
            $ctx = $make_context($override, $games[$override], 'override');
            if ($ctx) { return $ctx; }
        }

        $supplier_name = (string) get_post_meta($product_id, '_dfr_supplier_category_name', true);
        if ($supplier_name === '') { $supplier_name = (string) get_the_title($product_id); }

        $scored = array();
        foreach ($games as $validation_id => $game) {
            if (!is_array($game)) { continue; }
            $field_map = $this->uid_validation_field_map($product_id, $game);
            if (!is_array($field_map)) { continue; }
            $score = $this->uid_match_score($supplier_name, $order_category_id, $game);
            if ($score >= 80) {
                $scored[$validation_id] = array('score' => $score, 'game' => $game, 'field_map' => $field_map);
            }
        }
        if (!$scored) { return false; }
        uasort($scored, static function ($a, $b) { return (int) $b['score'] <=> (int) $a['score']; });
        $ids = array_keys($scored);
        $best_id = (string) $ids[0];
        $best = $scored[$best_id];
        $second_score = isset($ids[1]) ? (int) $scored[$ids[1]]['score'] : 0;
        // Require a unique, high-confidence winner. Family matches score 100;
        // fuzzy matches need a clear margin to avoid validating the wrong game.
        if ((int) $best['score'] < 88 || ((int) $best['score'] < 100 && ((int) $best['score'] - $second_score) < 12)) {
            return false;
        }
        return array(
            'order_category_id'      => $order_category_id,
            'validation_category_id' => $best_id,
            'game'                   => $best['game'],
            'field_map'              => $best['field_map'],
            'match'                  => (int) $best['score'] === 100 ? 'game-family' : 'high-confidence',
        );
    }

    private function uid_category_supported($product_id) {
        return is_array($this->uid_validation_context($product_id));
    }

    /** Return bounded, non-secret metadata for a supplier validation failure. */
    private function uid_validation_error_meta($error) {
        if (!is_wp_error($error)) {
            return array('error_code' => '', 'http' => 0, 'provider_code' => '');
        }
        $data = $error->get_error_data();
        return array(
            'error_code' => sanitize_key((string) $error->get_error_code()),
            'http' => is_array($data) ? absint($data['status'] ?? 0) : 0,
            'provider_code' => is_array($data) ? sanitize_key((string) ($data['provider_code'] ?? '')) : '',
        );
    }

    /**
     * Classify whether a read-only Player ID validation can be retried once.
     * Authentication/plan errors and supplier throttling are never amplified.
     */
    private function uid_validation_retry_mode($error) {
        if (!is_wp_error($error)) { return ''; }
        $meta = $this->uid_validation_error_meta($error);
        $http = (int) $meta['http'];
        $provider = strtolower((string) $meta['provider_code']);
        $error_code = strtolower((string) $meta['error_code']);
        $message = strtolower((string) $error->get_error_message());

        if (in_array($http, array(401, 403, 429), true)) { return ''; }
        if (strpos($provider, 'invalid_uid') !== false || strpos($provider, 'invalid_player') !== false || strpos($provider, 'player_not_found') !== false) {
            return '';
        }

        // The validation list is documented as dynamic. A cached category or field
        // schema can become stale while the storefront is still open.
        if (in_array($http, array(400, 404, 409, 422), true)) {
            return 'schema';
        }

        // Do not immediately repeat a full timeout; it would only double UI latency.
        if (strpos($message, 'timed out') !== false || strpos($message, 'timeout') !== false) {
            return '';
        }
        if ($http === 0 || in_array($http, array(408, 425, 500, 502, 503, 504), true) || $error_code === 'http_request_failed') {
            return 'transient';
        }
        return '';
    }


    /** Convert a supplier validation error to bounded, non-secret diagnostic metadata. */
    private function uid_server_diagnostic_error($error) {
        $meta = $this->uid_validation_error_meta($error);
        $http = (int) ($meta['http'] ?? 0);
        $provider = strtolower((string) ($meta['provider_code'] ?? ''));
        $error_code = strtolower((string) ($meta['error_code'] ?? ''));

        $classification = 'provider_error';
        $message = __('FazerCards returned an unexpected Player ID validation error.', 'delicat-fazercards');
        $tone = 'fail';

        if (in_array($http, array(401, 403), true)) {
            $classification = 'credentials_or_plan';
            $message = __('The FazerCards API is reachable, but this API key/account is not authorized for the requested validation service.', 'delicat-fazercards');
        } elseif ($http === 429) {
            $classification = 'rate_limited';
            $message = __('The FazerCards Player ID service is reachable but is currently rate-limiting requests.', 'delicat-fazercards');
            $tone = 'warning';
        } elseif (
            strpos($provider, 'invalid_uid') !== false ||
            strpos($provider, 'invalid_player') !== false ||
            strpos($provider, 'player_not_found') !== false ||
            strpos($provider, 'not_found') !== false
        ) {
            $classification = 'player_rejected';
            $message = __('The FazerCards Player ID server responded normally, but it rejected this Player ID.', 'delicat-fazercards');
            $tone = 'warning';
        } elseif (in_array($http, array(400, 404, 409, 422), true)) {
            $classification = 'schema_rejected';
            $message = __('The validation server is reachable, but the current Free Fire validation category/field schema was rejected.', 'delicat-fazercards');
            $tone = 'warning';
        } elseif ($http === 0 || in_array($http, array(408, 425, 500, 502, 503, 504), true) || $error_code === 'http_request_failed') {
            $classification = 'upstream_unavailable';
            $message = __('The FazerCards Player ID service or its upstream Free Fire validation provider is currently unavailable or not responding reliably.', 'delicat-fazercards');
        }

        return array(
            'classification' => $classification,
            'message' => $message,
            'tone' => $tone,
            'http' => max(0, min(599, $http)),
            'provider_code' => substr(sanitize_key($provider), 0, 80),
            'error_code' => substr(sanitize_key($error_code), 0, 80),
        );
    }

    /**
     * Read-only authenticated health check for the supplier's Free Fire Player ID server.
     * The supplied Player ID is used only in-memory for this request and is never persisted
     * in WordPress options, transients, logs, order metadata, or the diagnostic report.
     */
    public function run_player_id_server_diagnostic($player_id) {
        $report = array(
            'checked_at' => gmdate('c'),
            'overall' => 'fail',
            'classification' => 'not_run',
            'headline' => __('Player ID diagnostic did not complete.', 'delicat-fazercards'),
            'message' => '',
            'steps' => array(),
            'http' => 0,
            'provider_code' => '',
            'error_code' => '',
            'retried' => false,
            'version' => defined('DFR_VERSION') ? DFR_VERSION : '',
        );

        if (!$this->plugin()->can_manage()) {
            $report['classification'] = 'permission_denied';
            $report['message'] = __('You do not have permission to run this diagnostic.', 'delicat-fazercards');
            return $report;
        }

        $player_id = trim((string) $player_id);
        if ($player_id === '' || strlen($player_id) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $player_id)) {
            $report['classification'] = 'invalid_test_input';
            $report['message'] = __('Enter a valid Free Fire Player ID before running the test.', 'delicat-fazercards');
            return $report;
        }

        $api = $this->plugin()->api();
        if (!$api->is_configured()) {
            $report['classification'] = 'missing_api_key';
            $report['message'] = __('The FazerCards API key is not configured.', 'delicat-fazercards');
            return $report;
        }

        $me = $api->me();
        if (is_wp_error($me)) {
            $err = $this->uid_server_diagnostic_error($me);
            $report = array_merge($report, $err);
            $report['headline'] = __('FazerCards API authentication failed.', 'delicat-fazercards');
            $report['steps'][] = array(
                'label' => __('API authentication', 'delicat-fazercards'),
                'status' => 'fail',
                'detail' => __('GET /me did not complete successfully.', 'delicat-fazercards'),
            );
            return $report;
        }
        $report['steps'][] = array(
            'label' => __('API authentication', 'delicat-fazercards'),
            'status' => 'pass',
            'detail' => __('GET /me responded successfully.', 'delicat-fazercards'),
        );

        $validation_catalog = $api->topup_validation_games();
        if (is_wp_error($validation_catalog)) {
            $err = $this->uid_server_diagnostic_error($validation_catalog);
            $report = array_merge($report, $err);
            $report['headline'] = __('FazerCards validation catalog failed.', 'delicat-fazercards');
            $report['steps'][] = array(
                'label' => __('Validation catalog', 'delicat-fazercards'),
                'status' => 'fail',
                'detail' => __('GET /topups/validate-id failed.', 'delicat-fazercards'),
            );
            return $report;
        }

        $rows = array();
        if (!empty($validation_catalog['items']) && is_array($validation_catalog['items'])) {
            $rows = $validation_catalog['items'];
        } elseif (!empty($validation_catalog['data']['items']) && is_array($validation_catalog['data']['items'])) {
            $rows = $validation_catalog['data']['items'];
        }
        $report['steps'][] = array(
            'label' => __('Validation catalog', 'delicat-fazercards'),
            'status' => $rows ? 'pass' : 'fail',
            'detail' => $rows
                ? sprintf(__('GET /topups/validate-id returned %d validation-capable categories.', 'delicat-fazercards'), count($rows))
                : __('The validation endpoint responded but did not return a usable category list.', 'delicat-fazercards'),
        );
        if (!$rows) {
            $report['classification'] = 'validation_catalog_empty';
            $report['headline'] = __('Validation server responded with an unusable catalog.', 'delicat-fazercards');
            $report['message'] = __('This points to a FazerCards validation-catalog problem or an API response-format change.', 'delicat-fazercards');
            return $report;
        }

        $free_fire = array();
        foreach (array_slice($rows, 0, 500) as $row) {
            if (!is_array($row)) { continue; }
            $category_id = $this->sanitize_remote_id($row['category_id'] ?? '');
            if ($category_id === '') { continue; }
            $name = substr(sanitize_text_field((string) ($row['name'] ?? '')), 0, 190);
            if ($this->uid_game_family_key($name, $category_id) !== 'free fire') { continue; }
            $fields = !empty($row['fields']) && is_array($row['fields']) ? $this->sanitize_field_spec($row['fields']) : array();
            $priority = strtolower($category_id) === 'free_fire' ? 1000 : 100;
            if (stripos($name, 'free fire') !== false) { $priority += 50; }
            $free_fire[] = array(
                'category_id' => $category_id,
                'name' => $name !== '' ? $name : $category_id,
                'fields' => $fields,
                'priority' => $priority,
            );
        }

        if (!$free_fire) {
            $report['classification'] = 'free_fire_not_listed';
            $report['headline'] = __('Free Fire is missing from FazerCards validation.', 'delicat-fazercards');
            $report['message'] = __('The authenticated validation catalog is online, but it currently does not advertise a Free Fire Player ID validation category.', 'delicat-fazercards');
            $report['steps'][] = array(
                'label' => __('Free Fire capability', 'delicat-fazercards'),
                'status' => 'fail',
                'detail' => __('No Free Fire validation category was found.', 'delicat-fazercards'),
            );
            return $report;
        }

        usort($free_fire, static function ($a, $b) {
            return (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0);
        });
        $game = $free_fire[0];

        $player_field = '';
        foreach ($game['fields'] as $spec) {
            if (!is_array($spec) || empty($spec['key'])) { continue; }
            $key = sanitize_key((string) $spec['key']);
            $label = isset($spec['label']) ? (string) $spec['label'] : $key;
            if ($this->uid_field_role($key, $label) === 'player') {
                $player_field = $key;
                if (!empty($spec['required'])) { break; }
            }
        }

        if ($player_field === '') {
            $report['classification'] = 'free_fire_schema_missing_player_field';
            $report['headline'] = __('Free Fire validation schema is incomplete.', 'delicat-fazercards');
            $report['message'] = __('FazerCards lists Free Fire, but the returned schema has no recognizable Player ID/UID field.', 'delicat-fazercards');
            $report['steps'][] = array(
                'label' => __('Free Fire capability', 'delicat-fazercards'),
                'status' => 'fail',
                'detail' => sprintf(__('Category %s was found, but no Player ID field was advertised.', 'delicat-fazercards'), $game['category_id']),
            );
            return $report;
        }

        $report['steps'][] = array(
            'label' => __('Free Fire capability', 'delicat-fazercards'),
            'status' => 'pass',
            'detail' => sprintf(
                __('Using validation category %1$s with field %2$s.', 'delicat-fazercards'),
                $game['category_id'],
                $player_field
            ),
        );

        $result = $api->validate_topup_id($game['category_id'], array($player_field => $player_id));
        if (is_wp_error($result) && $this->uid_validation_retry_mode($result) === 'transient') {
            $report['retried'] = true;
            $report['steps'][] = array(
                'label' => __('First Player ID request', 'delicat-fazercards'),
                'status' => 'warning',
                'detail' => __('The first read-only validation attempt had a transient supplier/network failure; one safe retry was attempted.', 'delicat-fazercards'),
            );
            $result = $api->validate_topup_id($game['category_id'], array($player_field => $player_id));
        }

        if (is_wp_error($result)) {
            $err = $this->uid_server_diagnostic_error($result);
            $report = array_merge($report, $err);
            $report['overall'] = $err['tone'] === 'warning' ? 'warning' : 'fail';
            $report['headline'] = $err['classification'] === 'player_rejected'
                ? __('FazerCards Player ID server is online.', 'delicat-fazercards')
                : ($err['classification'] === 'schema_rejected'
                    ? __('FazerCards Player ID server is reachable but rejected the current schema.', 'delicat-fazercards')
                    : __('FazerCards Player ID server test failed.', 'delicat-fazercards'));
            $report['steps'][] = array(
                'label' => __('Player ID validation', 'delicat-fazercards'),
                'status' => $err['tone'] === 'warning' ? 'warning' : 'fail',
                'detail' => $err['message'],
            );
            return $report;
        }

        $report['overall'] = 'pass';
        $report['classification'] = $report['retried'] ? 'recovered_after_retry' : 'healthy';
        $report['headline'] = __('FazerCards Player ID server is working.', 'delicat-fazercards');
        $report['message'] = $report['retried']
            ? __('The Player ID validation succeeded on the safe retry, which suggests an intermittent upstream/network issue rather than a permanent configuration error.', 'delicat-fazercards')
            : __('Authentication, validation catalog discovery, Free Fire capability, and the Player ID validation request all succeeded.', 'delicat-fazercards');
        $report['steps'][] = array(
            'label' => __('Player ID validation', 'delicat-fazercards'),
            'status' => 'pass',
            'detail' => __('POST /topups/validate-id responded successfully. The test did not create an order or debit supplier balance.', 'delicat-fazercards'),
        );
        return $report;
    }

    /**
     * Validate the exact storefront fields and self-heal one stale/transient supplier
     * failure. Verification is read-only and can never debit a wallet or place an order.
     */
    private function uid_validate_product_fields_with_recovery($product_id, array $product_fields) {
        $context = $this->uid_validation_context($product_id);
        if (!is_array($context) || empty($context['validation_category_id']) || empty($context['game'])) {
            return new WP_Error('dfr_uid_context_unavailable', __('Player verification is not available for this product.', 'delicat-fazercards'), array('status' => 400));
        }

        $validation_fields = $this->uid_map_product_values_to_validation($product_id, $context['game'], $product_fields);
        if (is_wp_error($validation_fields) || !$validation_fields) {
            return new WP_Error('dfr_uid_field_map', __('Player verification information is missing or incompatible.', 'delicat-fazercards'), array('status' => 422));
        }

        $result = $this->plugin()->api()->validate_topup_id((string) $context['validation_category_id'], $validation_fields);
        if (!is_wp_error($result)) {
            return array('context' => $context, 'validation_fields' => $validation_fields, 'result' => $result, 'recovery' => '');
        }

        $first = $result;
        $mode = $this->uid_validation_retry_mode($first);
        if ($mode === '') { return $first; }

        if ($mode === 'schema') {
            $fresh_context = $this->uid_validation_context($product_id, true);
            if (is_array($fresh_context) && !empty($fresh_context['validation_category_id']) && !empty($fresh_context['game'])) {
                $fresh_fields = $this->uid_map_product_values_to_validation($product_id, $fresh_context['game'], $product_fields);
                if (!is_wp_error($fresh_fields) && $fresh_fields) {
                    $context = $fresh_context;
                    $validation_fields = $fresh_fields;
                } else {
                    return $first;
                }
            } else {
                return $first;
            }
        }

        $retry = $this->plugin()->api()->validate_topup_id((string) $context['validation_category_id'], $validation_fields);
        if (is_wp_error($retry)) { return $retry; }

        return array('context' => $context, 'validation_fields' => $validation_fields, 'result' => $retry, 'recovery' => $mode);
    }


    /** Public, non-secret capability descriptor for Delicat's reseller API/catalog. */
    public function uid_validation_capability($product_id) {
        $product_id = absint($product_id);
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : false;
        if ($product && $product->get_parent_id()) { $product_id = absint($product->get_parent_id()); }
        if (!$product_id || !$this->uid_validation_enabled()) { return array('supported' => false, 'required' => false); }
        $context = $this->uid_validation_context($product_id);
        if (!is_array($context) || empty($context['game'])) { return array('supported' => false, 'required' => false, 'advisory' => $this->uid_product_has_player_identity_field($product_id)); }
        $field_map = !empty($context['field_map']) && is_array($context['field_map']) ? $context['field_map'] : array();
        $required = array();
        foreach (!empty($context['game']['fields']) && is_array($context['game']['fields']) ? $context['game']['fields'] : array() as $spec) {
            if (!is_array($spec) || empty($spec['key'])) { continue; }
            $vkey = sanitize_key((string) $spec['key']);
            if ($vkey === '' || !isset($field_map[$vkey])) { continue; }
            if (array_key_exists('required', $spec) ? !empty($spec['required']) : true) {
                $required[] = sanitize_key((string) $field_map[$vkey]);
            }
        }
        return array(
            'supported' => true,
            'required' => $this->uid_validation_required(),
            'game' => isset($context['game']['name']) ? substr(sanitize_text_field((string) $context['game']['name']), 0, 120) : '',
            'required_fields' => array_values(array_unique(array_filter($required))),
            'returns_player_name' => true,
            'returns_region_when_available' => true,
        );
    }

    /**
     * Server-to-server UID verification for Delicat's reseller API.
     *
     * The reseller API never trusts a client-side "verified" flag. When the
     * store setting requires UID verification and FazerCards currently exposes a
     * validation category for this imported top-up, validate the exact sanitized
     * fields before any Delicat Wallet debit or supplier order is attempted.
     * Unsupported games remain sellable because FazerCards documents the
     * validation list as dynamic and not universal.
     */
    public function validate_reseller_topup_uid($product_id, array $fields, $force = false) {
        if (!$this->uid_validation_enabled() || (!$force && !$this->uid_validation_required())) {
            return array('supported' => false);
        }

        $product_id = absint($product_id);
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : false;
        if ($product && $product->get_parent_id()) {
            $product_id = absint($product->get_parent_id());
        }
        if (!$product_id) {
            return array('supported' => false);
        }

        $context = $this->uid_validation_context($product_id);
        if (!is_array($context) || empty($context['validation_category_id']) || empty($context['game'])) {
            // Live UID/name validation is dynamic and not available for every game.
            // Unsupported games remain sellable; clients are warned to verify the
            // account information carefully before purchase because direct-ID top-ups are final.
            return array('supported' => false, 'advisory' => $this->uid_product_has_player_identity_field($product_id));
        }

        // API clients submit the storefront/product field keys. Map them server-side
        // to the exact keys FazerCards returned for this validation category (for
        // example player_id -> uid or server_id -> zone_id). Never trust a client-
        // supplied validation category or arbitrary upstream field name.
        $validation_fields = $this->uid_map_product_values_to_validation($product_id, $context['game'], $fields);
        if (is_wp_error($validation_fields)) {
            return new WP_Error('dapi_uid_fields_missing', __('Player verification information is missing or incompatible.', 'delicat-fazercards'), array('status' => 422));
        }
        if (!$validation_fields) {
            return new WP_Error('dapi_uid_fields_missing', __('Player verification information is missing.', 'delicat-fazercards'), array('status' => 422));
        }

        $attempt = $this->uid_validate_product_fields_with_recovery($product_id, $fields);
        if (is_wp_error($attempt)) {
            $meta = $this->uid_validation_error_meta($attempt);
            $http = (int) $meta['http'];
            $this->plugin()->log('warning', 'Reseller API Player ID validation request failed before wallet debit', array(
                'validation_category_id' => (string) $context['validation_category_id'],
                'error' => $meta['error_code'],
                'provider_code' => $meta['provider_code'],
                'http' => $http,
            ));
            return new WP_Error(
                'dapi_uid_upstream',
                __('Player ID verification is temporarily unavailable. No wallet debit was attempted.', 'delicat-fazercards'),
                array('status' => $http === 429 ? 429 : 503)
            );
        }
        $context = $attempt['context'];
        $validation_fields = $attempt['validation_fields'];
        $result = $attempt['result'];
        if (!empty($attempt['recovery'])) {
            $this->plugin()->log('info', 'Player ID validation recovered after a safe retry', array(
                'mode' => sanitize_key((string) $attempt['recovery']),
                'validation_category_id' => (string) $context['validation_category_id'],
            ));
        }
        if (empty($result['ok'])) {
            $this->plugin()->log('warning', 'Reseller API Player ID validation returned an unsuccessful supplier response', array(
                'validation_category_id' => (string) $context['validation_category_id'],
                'code' => isset($result['code']) ? sanitize_key((string) $result['code']) : '',
            ));
            return new WP_Error('dapi_uid_upstream', __('Player ID verification is temporarily unavailable. No wallet debit was attempted.', 'delicat-fazercards'), array('status' => 503));
        }
        if (empty($result['valid'])) {
            return new WP_Error('dapi_uid_invalid', __('Player ID could not be verified. Check the player/server information and try again.', 'delicat-fazercards'), array('status' => 422));
        }

        return array(
            'supported' => true,
            'valid' => true,
            'validation_category_id' => substr(sanitize_text_field((string) $context['validation_category_id']), 0, 190),
            'player_name' => isset($result['player_name']) ? substr(sanitize_text_field((string) $result['player_name']), 0, 120) : '',
            'region' => isset($result['region']) ? substr(sanitize_text_field((string) $result['region']), 0, 80) : '',
        );
    }

    private function uid_session_binding() {
        $parts = array();
        if (is_user_logged_in()) {
            $parts[] = 'u:' . get_current_user_id();
            $token = function_exists('wp_get_session_token') ? (string) wp_get_session_token() : '';
            if ($token !== '') { $parts[] = 't:' . hash('sha256', $token); }
        } elseif (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
            $cid = (string) WC()->session->get_customer_id();
            if ($cid !== '') { $parts[] = 'wc:' . $cid; }
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
        $parts[] = 'net:' . hash_hmac('sha256', $ip . '|' . $ua, wp_salt('nonce'));
        return hash_hmac('sha256', implode('|', $parts), wp_salt('auth'));
    }

    private function uid_same_origin_request() {
        $home = wp_parse_url(home_url('/'));
        $site_host = strtolower((string) ($home['host'] ?? ''));
        $site_scheme = strtolower((string) ($home['scheme'] ?? 'https'));
        $site_port = isset($home['port']) ? (int) $home['port'] : ($site_scheme === 'https' ? 443 : 80);
        if ($site_host === '') { return true; }

        foreach (array('HTTP_ORIGIN','HTTP_REFERER') as $header) {
            if (empty($_SERVER[$header])) { continue; }
            $parts = wp_parse_url(wp_unslash($_SERVER[$header]));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0));
            if ($host === '' || $scheme === '' || !hash_equals($site_host, $host) || !hash_equals($site_scheme, $scheme) || $site_port !== $port) {
                return false;
            }
        }
        return true;
    }

    private function uid_rate_limit_key() {
        return 'dfr_uid_rl_' . substr(hash_hmac('sha256', $this->uid_session_binding(), wp_salt('nonce')), 0, 32);
    }

    private function uid_rate_limit_ok() {
        global $wpdb;
        $key = $this->uid_rate_limit_key();
        $lock_name = 'dfr_uid_' . substr(md5($key), 0, 24);
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) === '1';
        if (!$locked) { return false; }
        try {
            $row = get_transient($key);
            $row = is_array($row) ? $row : array('count' => 0);
            if ((int) ($row['count'] ?? 0) >= 20) { return false; }
            $row['count'] = (int) ($row['count'] ?? 0) + 1;
            return (bool) set_transient($key, $row, 5 * MINUTE_IN_SECONDS);
        } finally {
            if ($locked) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name)); }
        }
    }

    private function uid_global_rate_limit_ok() {
        global $wpdb;
        // Protect the private FazerCards validation quota even if an attacker rotates browser
        // sessions/IPs after obtaining public storefront nonces. This is deliberately lower than
        // the supplier's broad per-operation ceiling so normal checkout traffic has headroom.
        $lock_name = 'dfr_uid_global_v1';
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) === '1';
        if (!$locked) { return false; }
        try {
            $key = 'dfr_uid_global_window_v1';
            $now = time();
            $row = get_option($key, array());
            $row = is_array($row) ? $row : array();
            $started = absint($row['started'] ?? 0);
            $count = absint($row['count'] ?? 0);
            if (!$started || $started <= $now - MINUTE_IN_SECONDS || $started > $now + 60) {
                $started = $now;
                $count = 0;
            }
            if ($count >= 60) { return false; }
            $row = array('started' => $started, 'count' => $count + 1);
            update_option($key, $row, false);
            $confirmed = get_option($key, array());
            return is_array($confirmed)
                && absint($confirmed['started'] ?? 0) === $started
                && absint($confirmed['count'] ?? 0) === ($count + 1);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    private function uid_fields_hash(array $fields) {
        ksort($fields);
        return hash('sha256', wp_json_encode($fields, JSON_UNESCAPED_SLASHES));
    }

    private function uid_token_encode($product_id, $order_category_id, $validation_category_id, array $fields, $player_name = '', $region = '') {
        $payload = array(
            'v' => 3,
            'pid' => absint($product_id),
            'cid' => substr((string) $order_category_id, 0, 190),
            'vcid' => substr((string) $validation_category_id, 0, 190),
            'fh' => $this->uid_fields_hash($fields),
            'sid' => $this->uid_session_binding(),
            'name' => substr(sanitize_text_field((string) $player_name), 0, 120),
            'region' => substr(sanitize_text_field((string) $region), 0, 80),
            'iat' => time(),
            'exp' => time() + 10 * MINUTE_IN_SECONDS,
        );
        $body = rtrim(strtr(base64_encode(wp_json_encode($payload)), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, wp_salt('auth'));
        return $body . '.' . $sig;
    }

    private function uid_token_decode($token, $product_id, $order_category_id, $validation_category_id, array $fields) {
        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 2500 || strpos($token, '.') === false) { return false; }
        list($body, $sig) = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $body, wp_salt('auth'));
        if (!hash_equals($expected, (string) $sig)) { return false; }
        $pad = strlen($body) % 4;
        if ($pad) { $body .= str_repeat('=', 4 - $pad); }
        $json = base64_decode(strtr($body, '-_', '+/'), true);
        $data = $json !== false ? json_decode($json, true) : null;
        if (!is_array($data) || (int) ($data['v'] ?? 0) !== 3) { return false; }
        if ((int) ($data['pid'] ?? 0) !== absint($product_id)) { return false; }
        if (!hash_equals((string) ($data['cid'] ?? ''), (string) $order_category_id)) { return false; }
        if (!hash_equals((string) ($data['vcid'] ?? ''), (string) $validation_category_id)) { return false; }
        if (!hash_equals((string) ($data['sid'] ?? ''), $this->uid_session_binding())) { return false; }
        if ((int) ($data['exp'] ?? 0) < time() || (int) ($data['iat'] ?? 0) > time() + 60) { return false; }
        if (!hash_equals((string) ($data['fh'] ?? ''), $this->uid_fields_hash($fields))) { return false; }
        return $data;
    }

    private function posted_native_fields($product_id) {
        $values = array();
        foreach ($this->get_field_spec_for_product($product_id) as $field) {
            $name = 'dfr_field_' . $field['key'];
            if (!isset($_POST[$name])) { continue; }
            $value = trim(sanitize_text_field(wp_unslash($_POST[$name])));
            if ($value !== '') { $values[$field['key']] = substr($value, 0, 190); }
        }
        return $values;
    }

    public function ajax_verify_player_uid() {
        if (!$this->uid_validation_enabled()) {
            wp_send_json_error(array('message' => __('Player verification is disabled.', 'delicat-fazercards'), 'code' => 'disabled'), 403);
        }
        if (!$this->uid_same_origin_request()) {
            wp_send_json_error(array('message' => __('Invalid request origin.', 'delicat-fazercards'), 'code' => 'origin'), 403);
        }
        if (!check_ajax_referer('dfr_uid_verify', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security session expired. Refresh the page and try again.', 'delicat-fazercards'), 'code' => 'nonce'), 403);
        }
        if (!$this->uid_rate_limit_ok() || !$this->uid_global_rate_limit_ok()) {
            wp_send_json_error(array('message' => __('Too many verification attempts. Please wait a few minutes.', 'delicat-fazercards'), 'code' => 'rate_limit'), 429);
        }
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id || get_post_type($product_id) !== 'product' || get_post_meta($product_id, '_dfr_service_type', true) !== 'topup') {
            wp_send_json_error(array('message' => __('This product cannot use Player ID verification.', 'delicat-fazercards'), 'code' => 'product'), 400);
        }
        $context = $this->uid_validation_context($product_id);
        if (!is_array($context)) {
            wp_send_json_error(array('message' => __('La vérification du Player ID n’est pas disponible pour cette variante pour le moment.', 'delicat-fazercards'), 'code' => 'unsupported'), 400);
        }
        $category_id = (string) $context['order_category_id'];
        $validation_category_id = (string) $context['validation_category_id'];
        $spec = $this->get_field_spec_for_product($product_id);
        $product_fields = array();
        $incoming = isset($_POST['fields']) && is_array($_POST['fields']) ? wp_unslash($_POST['fields']) : array();
        foreach ($spec as $field) {
            $key = $field['key'];
            if (!array_key_exists($key, $incoming) || is_array($incoming[$key]) || is_object($incoming[$key])) { continue; }
            $value = trim(sanitize_text_field((string) $incoming[$key]));
            if ($value === '') { continue; }
            if (strlen($value) > 190) {
                wp_send_json_error(array('message' => sprintf(__('Invalid value for %s.', 'delicat-fazercards'), $field['label']), 'code' => 'field_length'), 400);
            }
            if ($field['type'] === 'number' && !is_numeric($value)) {
                wp_send_json_error(array('message' => sprintf(__('Invalid value for %s.', 'delicat-fazercards'), $field['label']), 'code' => 'field_type'), 400);
            }
            if ($field['type'] === 'email' && !is_email($value)) {
                wp_send_json_error(array('message' => sprintf(__('Invalid value for %s.', 'delicat-fazercards'), $field['label']), 'code' => 'field_type'), 400);
            }
            if ($field['type'] === 'select' && !empty($field['options']) && is_array($field['options']) && !array_key_exists($value, $field['options'])) {
                wp_send_json_error(array('message' => sprintf(__('Invalid value for %s.', 'delicat-fazercards'), $field['label']), 'code' => 'field_option'), 400);
            }
            $product_fields[$key] = substr($value, 0, 190);
        }
        foreach ($spec as $field) {
            if (!empty($field['required']) && empty($product_fields[$field['key']])) {
                wp_send_json_error(array('message' => sprintf(__('Enter %s before verification.', 'delicat-fazercards'), $field['label']), 'code' => 'missing_field'), 400);
            }
        }
        if (!$product_fields) {
            wp_send_json_error(array('message' => __('Entrez les informations du compte joueur avant la vérification.', 'delicat-fazercards'), 'code' => 'missing_fields'), 400);
        }

        // The validation category and field keys can differ from the sellable
        // regional catalog. Convert only through the server-side live schema.
        $validation_fields = $this->uid_map_product_values_to_validation($product_id, $context['game'], $product_fields);
        if (is_wp_error($validation_fields) || !$validation_fields) {
            wp_send_json_error(array('message' => __('Les informations du compte ne correspondent pas au format de vérification de ce jeu.', 'delicat-fazercards'), 'code' => 'field_map'), 400);
        }
        $attempt = $this->uid_validate_product_fields_with_recovery($product_id, $product_fields);
        if (is_wp_error($attempt)) {
            $meta = $this->uid_validation_error_meta($attempt);
            $this->plugin()->log('warning', 'Player ID validation request failed', array(
                'order_category_id' => $category_id,
                'validation_category_id' => $validation_category_id,
                'match' => isset($context['match']) ? $context['match'] : '',
                'error' => $meta['error_code'],
                'provider_code' => $meta['provider_code'],
                'http' => (int) $meta['http'],
            ));
            // Do not expose supplier credentials/plan details to unauthenticated visitors.
            // Rate limiting is safe to distinguish because it helps the customer recover.
            if ((int) $meta['http'] === 429) {
                wp_send_json_error(array(
                    'message' => __('Le service de vérification est très sollicité. Attendez une minute puis réessayez.', 'delicat-fazercards'),
                    'code' => 'upstream_rate_limit'
                ), 429);
            }
            wp_send_json_error(array(
                'message' => __('Impossible de vérifier ce Player ID pour le moment. Réessayez dans quelques instants.', 'delicat-fazercards'),
                'code' => 'upstream'
            ), 502);
        }
        $context = $attempt['context'];
        $category_id = (string) $context['order_category_id'];
        $validation_category_id = (string) $context['validation_category_id'];
        $validation_fields = $attempt['validation_fields'];
        $result = $attempt['result'];
        if (!empty($attempt['recovery'])) {
            $this->plugin()->log('info', 'Storefront Player ID validation recovered after a safe retry', array(
                'mode' => sanitize_key((string) $attempt['recovery']),
                'validation_category_id' => $validation_category_id,
            ));
        }
        if (empty($result['ok'])) {
            $this->plugin()->log('warning', 'Player ID validation returned an unsuccessful supplier response', array(
                'validation_category_id' => $validation_category_id,
                'code' => !empty($result['code']) ? sanitize_key((string) $result['code']) : '',
            ));
            wp_send_json_error(array(
                'message' => __('Impossible de vérifier ce Player ID pour le moment. Réessayez dans quelques instants.', 'delicat-fazercards'),
                'code' => 'upstream'
            ), 502);
        }
        if (empty($result['valid'])) {
            wp_send_json_error(array('message' => __('Player ID introuvable. Vérifiez l’identifiant et la région, puis réessayez.', 'delicat-fazercards'), 'code' => 'invalid_uid'), 400);
        }
        $player_name = isset($result['player_name']) ? sanitize_text_field((string) $result['player_name']) : '';
        $region = isset($result['region']) ? sanitize_text_field((string) $result['region']) : '';
        $token = $this->uid_token_encode($product_id, $category_id, $validation_category_id, $product_fields, $player_name, $region);
        wp_send_json_success(array(
            'valid' => true,
            'player_name' => $player_name,
            'region' => $region,
            'token' => $token,
            'expires_in' => 600,
            'game' => isset($context['game']['name']) ? substr(sanitize_text_field((string) $context['game']['name']), 0, 120) : '',
            'match' => isset($context['match']) ? sanitize_key((string) $context['match']) : '',
        ));
    }

    public function render_native_fields() {
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }
        $product_id = $product->get_id();
        $fields = $this->get_field_spec_for_product($product_id);
        if (!$fields) {
            return;
        }
        $uid_context = $this->uid_validation_enabled() && $this->plugin()->has_api_key() ? $this->uid_validation_context($product_id) : false;
        $can_verify = is_array($uid_context);
        $nonce = $can_verify ? wp_create_nonce('dfr_uid_verify') : '';
        echo '<div class="dfr-native-fields" data-dfr-product="' . esc_attr($product_id) . '" style="margin:16px 0;padding:16px;border:1px solid #e8e8ef;border-radius:18px;background:#fff;box-shadow:0 10px 28px -24px rgba(28,20,55,.45)">';
        echo '<strong style="display:block;margin-bottom:4px;font-size:15px">' . esc_html__('Informations de réception', 'delicat-fazercards') . '</strong>';
        echo '<small style="display:block;margin-bottom:14px;color:#777">' . esc_html__('Entrez les informations exactement comme elles apparaissent dans le jeu.', 'delicat-fazercards') . '</small>';
        foreach ($fields as $field) {
            $name = 'dfr_field_' . $field['key'];
            echo '<p class="form-row form-row-wide dfr-native-field" style="margin-bottom:12px">';
            echo '<label for="' . esc_attr($name) . '" style="font-weight:700;margin-bottom:6px;display:block">' . esc_html($field['label']) . (!empty($field['required']) ? ' <span class="required">*</span>' : '') . '</label>';
            if ($field['type'] === 'select' && !empty($field['options'])) {
                echo '<select id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" data-dfr-field-key="' . esc_attr($field['key']) . '" ' . (!empty($field['required']) ? 'required' : '') . ' style="width:100%;min-height:46px;border-radius:12px">';
                echo '<option value="">' . esc_html__('Choose…', 'delicat-fazercards') . '</option>';
                foreach ($field['options'] as $value => $label) {
                    echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
                }
                echo '</select>';
            } else {
                $type = in_array($field['type'], array('number', 'email'), true) ? $field['type'] : 'text';
                $placeholder = $this->uid_field_role($field['key'], $field['label']) === 'player' ? 'Ex: 123456789' : '';
                echo '<input type="' . esc_attr($type) . '" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" data-dfr-field-key="' . esc_attr($field['key']) . '" maxlength="190" autocomplete="off" placeholder="' . esc_attr($placeholder) . '" ' . (!empty($field['required']) ? 'required' : '') . ' style="width:100%;min-height:46px;border-radius:12px">';
            }
            echo '</p>';
        }
        if (!$can_verify && $this->uid_product_has_player_identity_field($product_id)) {
            echo '<div class="dfr-uid-advisory" style="margin-top:10px;padding:12px 13px;border-radius:12px;background:#fff8e9;border:1px solid #efd29b;color:#7a4a0c;font-size:13px;font-weight:700;line-height:1.45">⚠️ ' . esc_html__('Vérifiez soigneusement votre User ID / Player ID avant d’acheter. La vérification automatique du nom du joueur n’est pas disponible pour ce produit. Une recharge envoyée vers un mauvais compte est non remboursable.', 'delicat-fazercards') . '</div>';
        }
        if ($can_verify) {
            echo '<input type="hidden" name="dfr_uid_verification_token" class="dfr-uid-token" value="">';
            echo '<div class="dfr-uid-verify" data-ajax="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr($nonce) . '">';
            echo '<button type="button" class="button dfr-uid-verify-btn" style="width:100%;min-height:46px;border-radius:13px;font-weight:800;border:1px solid #5D4B8E;color:#5D4B8E;background:#f7f4ff">🔎 ' . esc_html__('Vérifier le compte joueur', 'delicat-fazercards') . '</button>';
            echo '<div class="dfr-uid-status" role="status" aria-live="polite" style="display:none;margin-top:10px;padding:11px 12px;border-radius:12px;font-size:13px;font-weight:650"></div>';
            echo '</div>';
            static $uid_script = false;
            if (!$uid_script) {
                $uid_script = true;
                echo <<<'HTML'
<script>
(function(){
  function setStatus(box, ok, text){
    var s=box.querySelector('.dfr-uid-status'); if(!s)return;
    s.style.display='block'; s.style.background=ok?'#eaf8ef':'#fff1f1'; s.style.color=ok?'#146b35':'#9b2727';
    s.style.border='1px solid '+(ok?'#bfe8cc':'#f1caca'); s.textContent=text;
  }
  function clearVerification(wrap){
    var token=wrap.querySelector('.dfr-uid-token'); if(token)token.value='';
    var box=wrap.querySelector('.dfr-uid-verify'); if(box){var s=box.querySelector('.dfr-uid-status');if(s)s.style.display='none';}
  }
  document.addEventListener('input',function(e){var wrap=e.target.closest&&e.target.closest('.dfr-native-fields');if(wrap&&e.target.matches('[data-dfr-field-key]'))clearVerification(wrap);});
  document.addEventListener('change',function(e){var wrap=e.target.closest&&e.target.closest('.dfr-native-fields');if(wrap&&e.target.matches('[data-dfr-field-key]'))clearVerification(wrap);});
  document.addEventListener('click',function(e){
    var btn=e.target.closest&&e.target.closest('.dfr-uid-verify-btn'); if(!btn)return;
    var wrap=btn.closest('.dfr-native-fields'), box=btn.closest('.dfr-uid-verify'); if(!wrap||!box)return;
    var fields={}, missing=false;
    wrap.querySelectorAll('[data-dfr-field-key]').forEach(function(el){var v=(el.value||'').trim();if(el.required&&!v)missing=true;if(v)fields[el.getAttribute('data-dfr-field-key')]=v;});
    if(missing||Object.keys(fields).length===0){setStatus(box,false,'Entrez l’UID / Player ID et les champs requis avant la vérification.');return;}
    btn.disabled=true; var old=btn.textContent; btn.textContent='⏳ Vérification…';
    var body=new URLSearchParams(); body.set('action','dfr_verify_player_uid');body.set('nonce',box.dataset.nonce);body.set('product_id',wrap.dataset.dfrProduct);
    Object.keys(fields).forEach(function(k){body.append('fields['+k+']',fields[k]);});
    var controller=window.AbortController?new AbortController():null;
    var timeout=controller?setTimeout(function(){controller.abort();},12000):null;
    fetch(box.dataset.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString(),signal:controller?controller.signal:undefined})
      .then(function(r){return r.json().catch(function(){return null;}).then(function(j){return {r:r,j:j};});})
      .then(function(x){
        var d=x.j&&x.j.data?x.j.data:{};
        if(x.j&&x.j.success&&d.valid&&d.token){
          var t=wrap.querySelector('.dfr-uid-token');if(t)t.value=d.token;
          var msg='✅ Compte vérifié'; if(d.player_name)msg+=' — '+d.player_name; if(d.region)msg+=' ('+d.region+')';
          setStatus(box,true,msg);
        }else{clearVerification(wrap);setStatus(box,false,(d&&d.message)?d.message:'Impossible de vérifier cet ID pour le moment.');}
      })
      .catch(function(err){clearVerification(wrap);setStatus(box,false,(err&&err.name==='AbortError')?'La vérification prend trop de temps. Réessayez dans quelques instants.':'Connexion interrompue. Réessayez.');})
      .finally(function(){if(timeout)clearTimeout(timeout);btn.disabled=false;btn.textContent=old;});
  });
})();
</script>
HTML;
            }
        }
        echo '</div>';
    }

    public function validate_native_fields($passed, $product_id, $quantity, $variation_id = 0, $variations = array()) {
        // Supplier min/max limits live on the selected variation for grouped catalogs.
        // Fall back to the parent/simple product for legacy imports.
        $quantity_product_id = $variation_id && get_post_meta($variation_id, '_dfr_imported', true) === '1'
            ? (int) $variation_id
            : (int) $product_id;
        if (get_post_meta($quantity_product_id, '_dfr_imported', true) === '1') {
            $min = max(1, (int) get_post_meta($quantity_product_id, '_dfr_min_order_quantity', true));
            $max = max($min, (int) get_post_meta($quantity_product_id, '_dfr_max_order_quantity', true));
            if ((int) $quantity < $min || (int) $quantity > $max) {
                wc_add_notice(sprintf(__('Quantity must be between %1$d and %2$d for this supplier product.', 'delicat-fazercards'), $min, $max), 'error');
                $passed = false;
            }
        }
        $fields = $this->get_field_spec_for_product($product_id);
        foreach ($fields as $field) {
            $name = 'dfr_field_' . $field['key'];
            $value = isset($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';
            if (!empty($field['required']) && $value === '') {
                wc_add_notice(sprintf(__('Please enter %s.', 'delicat-fazercards'), $field['label']), 'error');
                $passed = false;
            }
            if ($value !== '' && $field['type'] === 'select' && !empty($field['options']) && !array_key_exists($value, $field['options'])) {
                wc_add_notice(sprintf(__('Invalid value for %s.', 'delicat-fazercards'), $field['label']), 'error');
                $passed = false;
            }
            if ($value !== '' && $field['type'] === 'email' && !is_email($value)) {
                wc_add_notice(sprintf(__('Please enter a valid email for %s.', 'delicat-fazercards'), $field['label']), 'error');
                $passed = false;
            }
            if ($value !== '' && $field['type'] === 'number' && !is_numeric($value)) {
                wc_add_notice(sprintf(__('Please enter a valid number for %s.', 'delicat-fazercards'), $field['label']), 'error');
                $passed = false;
            }
        }
        if ($passed && $this->uid_validation_required() && $this->is_app_api_cart_request($product_id)) {
            $verified = $this->validate_reseller_topup_uid($product_id, $this->app_api_cart_fields, true);
            if (is_wp_error($verified)) {
                wc_add_notice($verified->get_error_message(), 'error');
                $passed = false;
            } elseif (!empty($verified['supported']) && !empty($verified['valid'])) {
                $this->app_api_uid_verified = array(
                    'product_id' => absint($product_id),
                    'fields_hash' => $this->uid_fields_hash($this->app_api_cart_fields),
                    'result' => $verified,
                );
            }
        } elseif ($passed && $this->uid_validation_required()) {
            $uid_context = $this->uid_validation_context($product_id);
            if (is_array($uid_context)) {
                $posted_fields = $this->posted_native_fields($product_id);
                $token = isset($_POST['dfr_uid_verification_token']) ? sanitize_text_field(wp_unslash($_POST['dfr_uid_verification_token'])) : '';
                if (!$this->uid_token_decode(
                    $token,
                    $product_id,
                    (string) $uid_context['order_category_id'],
                    (string) $uid_context['validation_category_id'],
                    $posted_fields
                )) {
                    wc_add_notice(__('Veuillez vérifier le Player ID avant d’ajouter cette recharge au panier.', 'delicat-fazercards'), 'error');
                    $passed = false;
                }
            }
        }
        return $passed;
    }

    public function quantity_input_args($args, $product) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return $args;
        }
        $product_id = $product->get_id();
        if (get_post_meta($product_id, '_dfr_imported', true) !== '1') {
            return $args;
        }
        $min = max(1, (int) get_post_meta($product_id, '_dfr_min_order_quantity', true));
        $max = max($min, (int) get_post_meta($product_id, '_dfr_max_order_quantity', true));
        $args['min_value'] = $min;
        $args['max_value'] = $max;
        return $args;
    }

    public function available_variation_limits($data, $parent, $variation) {
        if (!$variation || !is_a($variation, 'WC_Product_Variation')) {
            return $data;
        }
        $variation_id = $variation->get_id();
        if (get_post_meta($variation_id, '_dfr_imported', true) !== '1') {
            return $data;
        }
        $min = max(1, (int) get_post_meta($variation_id, '_dfr_min_order_quantity', true));
        $max = max($min, (int) get_post_meta($variation_id, '_dfr_max_order_quantity', true));
        $stock_max = $variation->get_max_purchase_quantity();
        if (is_numeric($stock_max) && (int) $stock_max > 0) {
            $max = min($max, (int) $stock_max);
        }
        $data['min_qty'] = $min;
        $data['max_qty'] = max($min, $max);
        return $data;
    }

    public function add_native_cart_data($cart_item_data, $product_id, $variation_id) {
        // Freeze the selected supplier mapping in the server-side WooCommerce cart
        // session. This prevents a later catalog sync/product edit from changing the
        // upstream offer between Add to cart and checkout/payment.
        $product_id = absint($product_id);
        $variation_id = absint($variation_id);
        $mapping_id = $variation_id ?: $product_id;
        if ($product_id > 0 && $mapping_id > 0) {
            $type = sanitize_key((string) get_post_meta($mapping_id, '_dfr_service_type', true));
            if (!in_array($type, array('topup','giftcard','gamekey'), true) && $mapping_id !== $product_id) {
                $type = sanitize_key((string) get_post_meta($product_id, '_dfr_service_type', true));
            }
            $category = $this->sanitize_remote_id((string) get_post_meta($mapping_id, '_dfr_category_id', true));
            if ($category === '' && $mapping_id !== $product_id) { $category = $this->sanitize_remote_id((string) get_post_meta($product_id, '_dfr_category_id', true)); }
            $offer = $this->sanitize_remote_id((string) get_post_meta($mapping_id, '_dfr_offer_id', true));
            if ($offer === '' && $mapping_id !== $product_id) { $offer = $this->sanitize_remote_id((string) get_post_meta($product_id, '_dfr_offer_id', true)); }
            if (in_array($type, array('topup','giftcard','gamekey'), true) && $category !== '' && $offer !== '') {
                $cart_item_data['dfr_supplier_snapshot'] = array(
                    'v' => 1,
                    'type' => $type,
                    'category' => $category,
                    'offer' => $offer,
                );
            }
        }

        $fields = $this->get_field_spec_for_product($product_id);
        if (!$fields) {
            return $cart_item_data;
        }
        $values = array();
        foreach ($fields as $field) {
            $name = 'dfr_field_' . $field['key'];
            if (!isset($_POST[$name])) {
                continue;
            }
            $value = sanitize_text_field(wp_unslash($_POST[$name]));
            if ($value !== '') {
                $values[$field['key']] = array('label' => $field['label'], 'value' => substr($value, 0, 190));
            }
        }
        if ($values) {
            $cart_item_data['dfr_fields'] = $values;
            // Authenticated App API retries rebuild the same authoritative cart
            // before checkout. Keep their item identity stable so Woo's cart hash
            // remains bound to the upstream idempotency claim. Website form adds
            // retain the historical unique-per-submit behaviour.
            $hash_salt = $this->is_app_api_cart_request($product_id)
                ? 'app-api|' . $product_id
                : 'website|' . microtime(true);
            $cart_item_data['dfr_fields_hash'] = hash('sha256', wp_json_encode($values) . '|' . $hash_salt);
        }
        $plain_fields = array();
        foreach ($values as $key => $row) { if (isset($row['value'])) { $plain_fields[$key] = (string) $row['value']; } }
        $uid_context = $this->uid_validation_context($product_id);
        $verified = false;
        if ($this->is_app_api_cart_request($product_id)
            && !empty($this->app_api_uid_verified['result'])
            && (int) ($this->app_api_uid_verified['product_id'] ?? 0) === $product_id
            && hash_equals((string) ($this->app_api_uid_verified['fields_hash'] ?? ''), $this->uid_fields_hash($plain_fields))) {
            $app_result = $this->app_api_uid_verified['result'];
            $verified = array(
                'name' => isset($app_result['player_name']) ? sanitize_text_field((string) $app_result['player_name']) : '',
                'region' => isset($app_result['region']) ? sanitize_text_field((string) $app_result['region']) : '',
                'vcid' => isset($app_result['validation_category_id']) ? sanitize_text_field((string) $app_result['validation_category_id']) : '',
                'iat' => time(),
            );
        } else {
            $token = isset($_POST['dfr_uid_verification_token']) ? sanitize_text_field(wp_unslash($_POST['dfr_uid_verification_token'])) : '';
            $verified = is_array($uid_context) ? $this->uid_token_decode(
                $token,
                $product_id,
                (string) $uid_context['order_category_id'],
                (string) $uid_context['validation_category_id'],
                $plain_fields
            ) : false;
        }
        if (is_array($verified)) {
            $validation_fields = is_array($uid_context) && !empty($uid_context['game'])
                ? $this->uid_map_product_values_to_validation($product_id, $uid_context['game'], $plain_fields)
                : array();
            if (is_wp_error($validation_fields)) { $validation_fields = array(); }
            $cart_item_data['dfr_uid_verified'] = array(
                'name' => isset($verified['name']) ? sanitize_text_field((string) $verified['name']) : '',
                'region' => isset($verified['region']) ? sanitize_text_field((string) $verified['region']) : '',
                'validation_category_id' => isset($verified['vcid']) ? substr(sanitize_text_field((string) $verified['vcid']), 0, 190) : '',
                'validation_fields' => is_array($validation_fields) ? $validation_fields : array(),
                'verified_at' => isset($verified['iat']) ? absint($verified['iat']) : time(),
            );
        }
        return $cart_item_data;
    }

    public function display_native_cart_data($item_data, $cart_item) {
        if (empty($cart_item['dfr_fields']) || !is_array($cart_item['dfr_fields'])) {
            return $item_data;
        }
        $native_fields = !empty($cart_item['delicat_native_fields']) && is_array($cart_item['delicat_native_fields'])
            ? $cart_item['delicat_native_fields'] : array();
        foreach ($cart_item['dfr_fields'] as $api_key => $field) {
            if (array_key_exists((string) $api_key, $native_fields)) { continue; }
            if (!empty($field['label']) && isset($field['value'])) {
                $item_data[] = array('key' => $field['label'], 'value' => $field['value']);
            }
        }
        if (!empty($cart_item['dfr_uid_verified']) && is_array($cart_item['dfr_uid_verified'])) {
            $name = isset($cart_item['dfr_uid_verified']['name']) ? sanitize_text_field((string) $cart_item['dfr_uid_verified']['name']) : '';
            $region = isset($cart_item['dfr_uid_verified']['region']) ? sanitize_text_field((string) $cart_item['dfr_uid_verified']['region']) : '';
            $value = '✅ ' . __('Verified', 'delicat-fazercards');
            if ($name !== '') { $value .= ' — ' . $name; }
            if ($region !== '') { $value .= ' (' . $region . ')'; }
            $item_data[] = array('key' => __('Player account', 'delicat-fazercards'), 'value' => $value);
        }
        return $item_data;
    }

    public function save_native_order_item_data($item, $cart_item_key, $values, $order) {
        $plain_fields = array();
        if (!empty($values['dfr_fields']) && is_array($values['dfr_fields'])) {
            foreach ($values['dfr_fields'] as $api_key => $field) {
                if (!empty($field['label']) && isset($field['value'])) {
                    $label = sanitize_text_field($field['label']);
                    $value = substr(sanitize_text_field($field['value']), 0, 190);
                    $item->add_meta_data($label, $value, true);
                    $safe_key = sanitize_key((string) $api_key);
                    if ($safe_key !== '' && $value !== '') { $plain_fields[$safe_key] = $value; }
                }
            }
        }

        $validation_category_id = '';
        $uid_validation_fields = array();
        if (!empty($values['dfr_uid_verified']) && is_array($values['dfr_uid_verified'])) {
            $validation_category_id = isset($values['dfr_uid_verified']['validation_category_id'])
                ? $this->sanitize_remote_id($values['dfr_uid_verified']['validation_category_id']) : '';
            if ($validation_category_id !== '') { $item->add_meta_data('_dfr_uid_validation_category_id', $validation_category_id, true); }
            if (!empty($values['dfr_uid_verified']['validation_fields']) && is_array($values['dfr_uid_verified']['validation_fields'])) {
                foreach (array_slice($values['dfr_uid_verified']['validation_fields'], 0, 20, true) as $vk => $vv) {
                    $vk = sanitize_key((string) $vk);
                    if ($vk === '' || is_array($vv) || is_object($vv)) { continue; }
                    $vv = trim(sanitize_text_field((string) $vv));
                    if ($vv !== '') { $uid_validation_fields[$vk] = substr($vv, 0, 190); }
                }
            }
            $item->add_meta_data('_dfr_uid_verified_at', absint($values['dfr_uid_verified']['verified_at'] ?? time()), true);

            $name = isset($values['dfr_uid_verified']['name']) ? sanitize_text_field((string) $values['dfr_uid_verified']['name']) : '';
            $region = isset($values['dfr_uid_verified']['region']) ? sanitize_text_field((string) $values['dfr_uid_verified']['region']) : '';
            $display = __('Verified', 'delicat-fazercards');
            if ($name !== '') { $display .= ' — ' . $name; }
            if ($region !== '') { $display .= ' (' . $region . ')'; }
            $item->add_meta_data(__('Player account', 'delicat-fazercards'), '✅ ' . $display, true);
        }

        /*
         * Freeze the supplier mapping and exact API-field values at checkout.
         * WooCommerce price/order data is already immutable at this point; the
         * supplier mapping must be immutable too. Otherwise an admin catalog sync or
         * product edit between checkout and payment could fulfill a different offer.
         *
         * This snapshot is authenticated-encrypted and later rewrapped into the
         * order-item-specific fulfillment context before the first supplier POST.
         */
        $product_id = absint($values['product_id'] ?? (is_callable(array($item, 'get_product_id')) ? $item->get_product_id() : 0));
        $variation_id = absint($values['variation_id'] ?? (is_callable(array($item, 'get_variation_id')) ? $item->get_variation_id() : 0));
        $mapping_id = $variation_id ?: $product_id;
        if ($product_id > 0 && $mapping_id > 0 && $order && is_callable(array($order, 'get_id'))) {
            $cart_mapping = !empty($values['dfr_supplier_snapshot']) && is_array($values['dfr_supplier_snapshot']) ? $values['dfr_supplier_snapshot'] : array();
            $type = sanitize_key((string) ($cart_mapping['type'] ?? ''));
            $category = $this->sanitize_remote_id((string) ($cart_mapping['category'] ?? ''));
            $offer = $this->sanitize_remote_id((string) ($cart_mapping['offer'] ?? ''));

            // Backward compatibility for carts created before v3.8 was installed.
            if (!in_array($type, array('topup','giftcard','gamekey'), true) || $category === '' || $offer === '') {
                $type = sanitize_key((string) get_post_meta($mapping_id, '_dfr_service_type', true));
                if (!in_array($type, array('topup','giftcard','gamekey'), true) && $mapping_id !== $product_id) {
                    $type = sanitize_key((string) get_post_meta($product_id, '_dfr_service_type', true));
                }
                $category = $this->sanitize_remote_id((string) get_post_meta($mapping_id, '_dfr_category_id', true));
                if ($category === '' && $mapping_id !== $product_id) { $category = $this->sanitize_remote_id((string) get_post_meta($product_id, '_dfr_category_id', true)); }
                $offer = $this->sanitize_remote_id((string) get_post_meta($mapping_id, '_dfr_offer_id', true));
                if ($offer === '' && $mapping_id !== $product_id) { $offer = $this->sanitize_remote_id((string) get_post_meta($product_id, '_dfr_offer_id', true)); }
            }
            if (in_array($type, array('topup','giftcard','gamekey'), true)) {
                if ($category !== '' && $offer !== '') {
                    $snapshot = array(
                        'v' => 1,
                        'type' => $type,
                        'category' => $category,
                        'offer' => $offer,
                        'quantity' => max(1, min(100, (int) (is_callable(array($item, 'get_quantity')) ? $item->get_quantity() : 1))),
                        'fields' => $plain_fields,
                        'validation_category' => $type === 'topup' ? $validation_category_id : '',
                        'validation_fields' => $type === 'topup' ? $uid_validation_fields : array(),
                    );
                    // This hook runs while WooCommerce is still constructing the order, before
                    // the order itself necessarily has a persistent ID. Bind the temporary
                    // checkout snapshot to the immutable cart-line key instead of order_id,
                    // then rewrap it under order_id + item_id immediately before fulfillment.
                    $line_context_id = substr(hash_hmac('sha256', (string) $cart_item_key, wp_salt('nonce')), 0, 40);
                    $context = 'checkout-snapshot|line:' . $line_context_id . '|product:' . $product_id . '|variation:' . $variation_id;
                    $enc = DFR_Crypto::encrypt_context(wp_json_encode($snapshot), $context);
                    if (!is_wp_error($enc)) {
                        $item->add_meta_data('_dfr_checkout_context_id', $line_context_id, true);
                        $item->add_meta_data('_dfr_checkout_snapshot_enc', $enc, true);
                        $item->add_meta_data('_dfr_service_type', $type, true);
                    } else {
                        // Fail closed later at fulfillment: mark that this line was a
                        // FazerCards item whose secure checkout snapshot could not be made.
                        $item->add_meta_data('_dfr_checkout_snapshot_failed', '1', true);
                    }
                }
            }
        }
    }

    public function render_admin_page() {
        if (!$this->plugin()->can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'delicat-fazercards'));
        }
        $settings = $this->settings();
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        $imported_count = (int) count(get_posts(array(
            'post_type' => 'product', 'post_status' => array('publish','draft','private','pending'), 'posts_per_page' => 500,
            'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => '_dfr_imported', 'meta_value' => '1',
        )));
        $notice = get_transient('dfr_admin_notice_' . get_current_user_id());
        if ($notice) { delete_transient('dfr_admin_notice_' . get_current_user_id()); }
        $webhook_url = rest_url('delicat-gateway/v1/webhook');
        $api_constant = defined('DELICAT_FAZER_API_KEY') && DELICAT_FAZER_API_KEY;
        $wh_constant = defined('DELICAT_FAZER_WEBHOOK_SECRET') && DELICAT_FAZER_WEBHOOK_SECRET;
        // The public Site URL is authoritative here; admin requests may terminate TLS
        // at a trusted reverse proxy where is_ssl() is not propagated to PHP.
        $https_ok = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_SCHEME)) === 'https';
        $crypto_ok = function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') || (function_exists('openssl_encrypt') && function_exists('openssl_get_cipher_methods') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true));
        $as_ok = function_exists('as_schedule_single_action') || function_exists('as_enqueue_async_action');
        $webhook_health = $this->plugin()->webhook_health();
        $uid_diag = get_transient('dfr_uid_server_diag_' . get_current_user_id());
        $uid_diag = is_array($uid_diag) ? $uid_diag : array();
        $webhook_ready = !empty($webhook_health) && ($webhook_health['enabled'] ?? '') === '1' && ($webhook_health['url_matches'] ?? '') === '1';
        $webhook_test_ok = !empty($webhook_health) && ($webhook_health['test_ok'] ?? '') === '1';
        $webhook_checked = !empty($webhook_health['checked_at']) ? (string) $webhook_health['checked_at'] : '';
        ?>
        <div class="wrap dfr-studio">
            <div class="dfr-hero">
                <div>
                    <span class="dfr-kicker">DELICAT STORE · SUPPLIER AUTOMATION</span>
                    <h1>Delicat Catalog Studio</h1>
                    <p><?php esc_html_e('Import supplier catalogs into WooCommerce, synchronize cost and stock, and keep fulfillment credentials isolated on the server.', 'delicat-fazercards'); ?></p>
                </div>
                <div class="dfr-hero-actions">
                    <button type="button" class="button dfr-btn dfr-btn-light" id="dfr-check-status"><?php esc_html_e('Test API', 'delicat-fazercards'); ?></button>
                    <span class="dfr-badge <?php echo $settings['live_orders_enabled'] === '1' ? 'is-live' : 'is-safe'; ?>"><?php echo $settings['live_orders_enabled'] === '1' ? esc_html__('Live ordering ON', 'delicat-fazercards') : esc_html__('Safe mode', 'delicat-fazercards'); ?></span>
                </div>
            </div>

            <?php if ($notice): ?>
                <div class="dfr-notice dfr-notice-<?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['message']); ?></div>
            <?php endif; ?>

            <div class="dfr-stat-grid">
                <div class="dfr-stat"><span><?php esc_html_e('API', 'delicat-fazercards'); ?></span><strong id="dfr-stat-api"><?php echo $this->plugin()->api()->is_configured() ? esc_html__('Configured', 'delicat-fazercards') : esc_html__('Missing key', 'delicat-fazercards'); ?></strong><small id="dfr-stat-plan"><?php esc_html_e('Run a connection test', 'delicat-fazercards'); ?></small></div>
                <div class="dfr-stat"><span><?php esc_html_e('Supplier balance', 'delicat-fazercards'); ?></span><strong id="dfr-stat-balance">—</strong><small><?php esc_html_e('Private upstream', 'delicat-fazercards'); ?></small></div>
                <div class="dfr-stat"><span><?php esc_html_e('Imported products', 'delicat-fazercards'); ?></span><strong><?php echo esc_html(number_format_i18n($imported_count)); ?></strong><small><?php esc_html_e('WooCommerce parent products', 'delicat-fazercards'); ?></small></div>
                <div class="dfr-stat"><span><?php esc_html_e('Customer currency', 'delicat-fazercards'); ?></span><strong><?php echo esc_html($currency); ?></strong><small><?php echo $settings['catalog_pricing_mode'] === 'automatic' ? esc_html__('Automatic pricing selected', 'delicat-fazercards') : esc_html__('WooCommerce prices protected', 'delicat-fazercards'); ?></small></div>
            </div>

            <nav class="dfr-tabs" aria-label="FazerCards Studio">
                <button class="dfr-tab is-active" data-tab="catalog"><?php esc_html_e('Catalog', 'delicat-fazercards'); ?></button>
                <button class="dfr-tab" data-tab="pricing"><?php esc_html_e('Pricing & Sync', 'delicat-fazercards'); ?></button>
                <button class="dfr-tab" data-tab="automation"><?php esc_html_e('Orders & Automation', 'delicat-fazercards'); ?></button>
                <button class="dfr-tab" data-tab="security"><?php esc_html_e('Security', 'delicat-fazercards'); ?></button>
            </nav>

            <section class="dfr-panel is-active" data-panel="catalog">
                <div class="dfr-panel-head">
                    <div><h2><?php esc_html_e('Catalog importer', 'delicat-fazercards'); ?></h2><p><?php esc_html_e('Browse supplier categories, inspect live supplier costs, and build WooCommerce products. Top-ups, gift cards, and game keys can each import as one variable product with supplier options as variations.', 'delicat-fazercards'); ?></p></div>
                    <button type="button" class="button dfr-btn" id="dfr-sync-imported"><?php esc_html_e('Sync imported products', 'delicat-fazercards'); ?></button>
                </div>
                <div class="dfr-service-grid">
                    <button class="dfr-service is-active" data-service="topup"><span>🎮</span><strong><?php esc_html_e('Game Top-ups', 'delicat-fazercards'); ?></strong><small><?php esc_html_e('Player ID products', 'delicat-fazercards'); ?></small></button>
                    <button class="dfr-service" data-service="giftcard"><span>🎁</span><strong><?php esc_html_e('Gift Cards', 'delicat-fazercards'); ?></strong><small><?php esc_html_e('Codes + supplier stock', 'delicat-fazercards'); ?></small></button>
                    <button class="dfr-service" data-service="gamekey"><span>🕹️</span><strong><?php esc_html_e('Game Keys', 'delicat-fazercards'); ?></strong><small><?php esc_html_e('PC / platform keys', 'delicat-fazercards'); ?></small></button>
                </div>
                <div class="dfr-catalog-shell">
                    <aside class="dfr-category-pane">
                        <div class="dfr-search"><input type="search" id="dfr-category-search" placeholder="<?php esc_attr_e('Search categories…', 'delicat-fazercards'); ?>"><button type="button" class="button" id="dfr-refresh-categories">↻</button></div>
                        <div id="dfr-category-list" class="dfr-category-list"><div class="dfr-empty"><?php esc_html_e('Choose a catalog to load categories.', 'delicat-fazercards'); ?></div></div>
                        <button type="button" class="button dfr-load-more" id="dfr-load-more" hidden><?php esc_html_e('Load more', 'delicat-fazercards'); ?></button>
                    </aside>
                    <main class="dfr-offer-pane">
                        <div id="dfr-offer-head" class="dfr-offer-head"><div><h3><?php esc_html_e('Select a category', 'delicat-fazercards'); ?></h3><p><?php esc_html_e('Supplier offers will appear here.', 'delicat-fazercards'); ?></p></div></div>
                        <div id="dfr-offer-list" class="dfr-offer-list"><div class="dfr-empty dfr-empty-large">📦<strong><?php esc_html_e('Nothing loaded yet', 'delicat-fazercards'); ?></strong><span><?php esc_html_e('Select a FazerCards category from the left.', 'delicat-fazercards'); ?></span></div></div>
                        <div class="dfr-import-bar" id="dfr-import-bar" hidden><span id="dfr-selected-count">0 selected</span><div><button type="button" class="button" id="dfr-import-category"><?php esc_html_e('Import category', 'delicat-fazercards'); ?></button><button type="button" class="button button-primary dfr-btn" id="dfr-import-selected"><?php esc_html_e('Import selected', 'delicat-fazercards'); ?></button></div></div>
                    </main>
                </div>
            </section>

            <section class="dfr-panel" data-panel="pricing">
                <form method="post" class="dfr-settings-form" id="dfr-pricing-form">
                    <?php wp_nonce_field('dfr_admin_action'); ?><input type="hidden" name="dfr_action" value="save_settings">
                    <input type="hidden" name="dfr_settings_scope" value="catalog">
                    <div class="dfr-panel-head">
                        <div>
                            <h2><?php esc_html_e('Pricing protection & catalog sync', 'delicat-fazercards'); ?></h2>
                            <p><?php esc_html_e('Keep the amount paid by your WooCommerce customer separate from the USD supplier cost charged to your FazerCards balance.', 'delicat-fazercards'); ?></p>
                        </div>
                        <span class="dfr-protection-badge"><?php esc_html_e('Woo price guard active', 'delicat-fazercards'); ?></span>
                    </div>

                    <div class="dfr-currency-flow" aria-label="Currency flow">
                        <div><span><?php esc_html_e('Customer checkout', 'delicat-fazercards'); ?></span><strong><?php echo esc_html($currency); ?></strong><small><?php esc_html_e('WooCommerce selling price', 'delicat-fazercards'); ?></small></div>
                        <i aria-hidden="true">→</i>
                        <div><span><?php esc_html_e('Supplier settlement', 'delicat-fazercards'); ?></span><strong>USD</strong><small><?php esc_html_e('FazerCards reseller balance', 'delicat-fazercards'); ?></small></div>
                        <div class="dfr-currency-lock"><b>🔒</b><span><?php esc_html_e('Independent currencies', 'delicat-fazercards'); ?><small><?php esc_html_e('The WooCommerce order total is never sent as the supplier price.', 'delicat-fazercards'); ?></small></span></div>
                    </div>

                    <div class="dfr-pricing-modes" role="radiogroup" aria-label="Pricing mode">
                        <label class="dfr-pricing-mode <?php echo $settings['catalog_pricing_mode'] !== 'automatic' ? 'is-selected' : ''; ?>">
                            <input type="radio" name="catalog_pricing_mode" value="manual" <?php checked($settings['catalog_pricing_mode'], 'manual'); ?>>
                            <span class="dfr-mode-icon">🛡️</span>
                            <span><strong><?php esc_html_e('Manual WooCommerce Pricing', 'delicat-fazercards'); ?></strong><em><?php esc_html_e('Recommended for Delicat', 'delicat-fazercards'); ?></em><small><?php esc_html_e('Import and sync supplier cost/stock, but never change the customer price you set in WooCommerce.', 'delicat-fazercards'); ?></small></span>
                        </label>
                        <label class="dfr-pricing-mode <?php echo $settings['catalog_pricing_mode'] === 'automatic' ? 'is-selected' : ''; ?>">
                            <input type="radio" name="catalog_pricing_mode" value="automatic" <?php checked($settings['catalog_pricing_mode'], 'automatic'); ?>>
                            <span class="dfr-mode-icon">⚙️</span>
                            <span><strong><?php esc_html_e('Automatic USD → Store Pricing', 'delicat-fazercards'); ?></strong><em><?php esc_html_e('Optional', 'delicat-fazercards'); ?></em><small><?php esc_html_e('Calculate WooCommerce prices from supplier USD cost, exchange rate and markup rules.', 'delicat-fazercards'); ?></small></span>
                        </label>
                    </div>

                    <div class="dfr-manual-protection" data-pricing-manual>
                        <strong>✓ <?php esc_html_e('Manual price protection enabled', 'delicat-fazercards'); ?></strong>
                        <p><?php esc_html_e('Existing WooCommerce regular/sale prices are preserved on catalog import, re-import and scheduled sync. New catalog products are kept as Draft until you set their WooCommerce price manually.', 'delicat-fazercards'); ?></p>
                    </div>

                    <div class="dfr-auto-pricing" data-pricing-auto>
                        <div class="dfr-auto-head"><strong><?php esc_html_e('Automatic price formula', 'delicat-fazercards'); ?></strong><small><?php esc_html_e('These controls are used only when Automatic Pricing is selected.', 'delicat-fazercards'); ?></small></div>
                        <div class="dfr-form-grid">
                            <label class="dfr-field"><span>USD → <?php echo esc_html($currency); ?> <?php esc_html_e('rate', 'delicat-fazercards'); ?></span><input type="number" min="0" step="0.0001" name="usd_to_store_rate" value="<?php echo esc_attr($settings['usd_to_store_rate']); ?>" <?php disabled($currency, 'USD'); ?>><small><?php echo $currency === 'USD' ? esc_html__('Fixed at 1 because the store uses USD.', 'delicat-fazercards') : esc_html__('Only required for automatic pricing.', 'delicat-fazercards'); ?></small></label>
                            <label class="dfr-field"><span><?php esc_html_e('Markup %', 'delicat-fazercards'); ?></span><input type="number" min="0" max="1000" step="0.01" name="catalog_markup_percent" value="<?php echo esc_attr($settings['catalog_markup_percent']); ?>"><small><?php esc_html_e('Percentage added after currency conversion.', 'delicat-fazercards'); ?></small></label>
                            <label class="dfr-field"><span><?php esc_html_e('Fixed markup', 'delicat-fazercards'); ?> (<?php echo esc_html($currency); ?>)</span><input type="number" min="0" step="0.01" name="catalog_fixed_markup" value="<?php echo esc_attr($settings['catalog_fixed_markup']); ?>"><small><?php esc_html_e('Optional flat amount added to every product.', 'delicat-fazercards'); ?></small></label>
                            <label class="dfr-field"><span><?php esc_html_e('Round price up to', 'delicat-fazercards'); ?></span><input type="number" min="0" step="0.01" name="catalog_rounding" value="<?php echo esc_attr($settings['catalog_rounding']); ?>"><small><?php esc_html_e('Example: 5 rounds upward to the next multiple of 5.', 'delicat-fazercards'); ?></small></label>
                        </div>
                        <div class="dfr-toggle-list dfr-auto-toggle">
                            <?php $this->toggle('catalog_price_sync', __('Synchronize WooCommerce selling prices', 'delicat-fazercards'), __('When enabled in automatic mode, supplier cost changes can recalculate the WooCommerce price.', 'delicat-fazercards'), $settings); ?>
                        </div>
                    </div>

                    <div class="dfr-builder-settings">
                        <div class="dfr-builder-head"><div><strong><?php esc_html_e('Catalog variation builder', 'delicat-fazercards'); ?></strong><small><?php esc_html_e('Automatically group supplier options into native WooCommerce variable products instead of creating a separate product for every denomination/package.', 'delicat-fazercards'); ?></small></div><span><?php esc_html_e('Native Woo variations', 'delicat-fazercards'); ?></span></div>

                        <div class="dfr-builder-service" data-builder-service="topup">
                            <div class="dfr-builder-service-title"><span>🎮</span><div><strong><?php esc_html_e('Game Top-ups', 'delicat-fazercards'); ?></strong><small><?php esc_html_e('Free Fire, Blood Strike and other package-based top-ups.', 'delicat-fazercards'); ?></small></div></div>
                            <div class="dfr-builder-grid">
                                <label class="dfr-builder-choice <?php echo $settings['topup_import_structure'] !== 'simple' ? 'is-selected' : ''; ?>">
                                    <input type="radio" name="topup_import_structure" value="variable" <?php checked($settings['topup_import_structure'], 'variable'); ?>>
                                    <span class="dfr-builder-icon">◫</span>
                                    <span><strong><?php esc_html_e('One variable product per game/category', 'delicat-fazercards'); ?></strong><em><?php esc_html_e('Recommended', 'delicat-fazercards'); ?></em><small><?php esc_html_e('110 Diamonds, 341 Diamonds, passes and memberships become variations of one Free Fire product.', 'delicat-fazercards'); ?></small></span>
                                </label>
                                <label class="dfr-builder-choice <?php echo $settings['topup_import_structure'] === 'simple' ? 'is-selected' : ''; ?>">
                                    <input type="radio" name="topup_import_structure" value="simple" <?php checked($settings['topup_import_structure'], 'simple'); ?>>
                                    <span class="dfr-builder-icon">▦</span>
                                    <span><strong><?php esc_html_e('Separate simple products', 'delicat-fazercards'); ?></strong><em><?php esc_html_e('Legacy', 'delicat-fazercards'); ?></em><small><?php esc_html_e('Every supplier package becomes a separate WooCommerce product.', 'delicat-fazercards'); ?></small></span>
                                </label>
                            </div>
                            <label class="dfr-field dfr-builder-attribute"><span><?php esc_html_e('Variation attribute', 'delicat-fazercards'); ?></span><input type="text" maxlength="40" name="topup_variation_attribute" value="<?php echo esc_attr($settings['topup_variation_attribute']); ?>"><small><?php esc_html_e('Recommended: Package.', 'delicat-fazercards'); ?></small></label>
                        </div>

                        <div class="dfr-builder-service" data-builder-service="giftcard">
                            <div class="dfr-builder-service-title"><span>🎁</span><div><strong><?php esc_html_e('Gift Cards', 'delicat-fazercards'); ?></strong><small><?php esc_html_e('Netflix, Apple, Google Play and other denomination-based cards.', 'delicat-fazercards'); ?></small></div></div>
                            <div class="dfr-builder-grid">
                                <label class="dfr-builder-choice <?php echo $settings['giftcard_import_structure'] !== 'simple' ? 'is-selected' : ''; ?>">
                                    <input type="radio" name="giftcard_import_structure" value="variable" <?php checked($settings['giftcard_import_structure'], 'variable'); ?>>
                                    <span class="dfr-builder-icon">◫</span>
                                    <span><strong><?php esc_html_e('One variable product per gift-card category', 'delicat-fazercards'); ?></strong><em><?php esc_html_e('Recommended', 'delicat-fazercards'); ?></em><small><?php esc_html_e('Example: Netflix (US) becomes one product with 15 USD, 20 USD, 25 USD, 30 USD, etc. as variations.', 'delicat-fazercards'); ?></small></span>
                                </label>
                                <label class="dfr-builder-choice <?php echo $settings['giftcard_import_structure'] === 'simple' ? 'is-selected' : ''; ?>">
                                    <input type="radio" name="giftcard_import_structure" value="simple" <?php checked($settings['giftcard_import_structure'], 'simple'); ?>>
                                    <span class="dfr-builder-icon">▦</span>
                                    <span><strong><?php esc_html_e('Separate product per denomination', 'delicat-fazercards'); ?></strong><em><?php esc_html_e('Legacy', 'delicat-fazercards'); ?></em><small><?php esc_html_e('15 USD, 20 USD, 25 USD and every other denomination remain separate products.', 'delicat-fazercards'); ?></small></span>
                                </label>
                            </div>
                            <label class="dfr-field dfr-builder-attribute"><span><?php esc_html_e('Variation attribute', 'delicat-fazercards'); ?></span><input type="text" maxlength="40" name="giftcard_variation_attribute" value="<?php echo esc_attr($settings['giftcard_variation_attribute']); ?>"><small><?php esc_html_e('Recommended: Amount or Value.', 'delicat-fazercards'); ?></small></label>
                        </div>

                        <div class="dfr-builder-service" data-builder-service="gamekey">
                            <div class="dfr-builder-service-title"><span>🕹️</span><div><strong><?php esc_html_e('Game Keys', 'delicat-fazercards'); ?></strong><small><?php esc_html_e('Group editions, platforms or available key options under one product.', 'delicat-fazercards'); ?></small></div></div>
                            <div class="dfr-builder-grid">
                                <label class="dfr-builder-choice <?php echo $settings['gamekey_import_structure'] !== 'simple' ? 'is-selected' : ''; ?>">
                                    <input type="radio" name="gamekey_import_structure" value="variable" <?php checked($settings['gamekey_import_structure'], 'variable'); ?>>
                                    <span class="dfr-builder-icon">◫</span>
                                    <span><strong><?php esc_html_e('One variable product per game-key category', 'delicat-fazercards'); ?></strong><em><?php esc_html_e('Recommended', 'delicat-fazercards'); ?></em><small><?php esc_html_e('Each supplier key/edition becomes a WooCommerce variation with its own stock, supplier ID and USD cost.', 'delicat-fazercards'); ?></small></span>
                                </label>
                                <label class="dfr-builder-choice <?php echo $settings['gamekey_import_structure'] === 'simple' ? 'is-selected' : ''; ?>">
                                    <input type="radio" name="gamekey_import_structure" value="simple" <?php checked($settings['gamekey_import_structure'], 'simple'); ?>>
                                    <span class="dfr-builder-icon">▦</span>
                                    <span><strong><?php esc_html_e('Separate product per key option', 'delicat-fazercards'); ?></strong><em><?php esc_html_e('Legacy', 'delicat-fazercards'); ?></em><small><?php esc_html_e('Keeps every supplier key option as its own WooCommerce product.', 'delicat-fazercards'); ?></small></span>
                                </label>
                            </div>
                            <label class="dfr-field dfr-builder-attribute"><span><?php esc_html_e('Variation attribute', 'delicat-fazercards'); ?></span><input type="text" maxlength="40" name="gamekey_variation_attribute" value="<?php echo esc_attr($settings['gamekey_variation_attribute']); ?>"><small><?php esc_html_e('Recommended: Option, Edition or Platform.', 'delicat-fazercards'); ?></small></label>
                        </div>
                    </div>

                    <div class="dfr-form-grid dfr-sync-settings">
                        <label class="dfr-field"><span><?php esc_html_e('New imported product status', 'delicat-fazercards'); ?></span><select name="catalog_import_status"><option value="draft" <?php selected($settings['catalog_import_status'],'draft'); ?>>Draft — recommended</option><option value="publish" <?php selected($settings['catalog_import_status'],'publish'); ?>>Publish</option><option value="private" <?php selected($settings['catalog_import_status'],'private'); ?>>Private</option></select><small><?php esc_html_e('Manual pricing will still force brand-new unpriced products to Draft for safety.', 'delicat-fazercards'); ?></small></label>
                        <label class="dfr-field"><span><?php esc_html_e('Catalog cache', 'delicat-fazercards'); ?></span><select name="catalog_cache_ttl"><option value="300" <?php selected($settings['catalog_cache_ttl'],'300'); ?>>5 minutes</option><option value="600" <?php selected($settings['catalog_cache_ttl'],'600'); ?>>10 minutes</option><option value="900" <?php selected($settings['catalog_cache_ttl'],'900'); ?>>15 minutes</option></select><small><?php esc_html_e('Reduces supplier requests while keeping catalog data fresh.', 'delicat-fazercards'); ?></small></label>
                    </div>
                    <div class="dfr-toggle-list">
                        <?php $this->toggle('catalog_disable_missing', __('Mark missing supplier products out of stock', 'delicat-fazercards'), __('Safer than continuing to sell an offer that disappeared from FazerCards.', 'delicat-fazercards'), $settings); ?>
                        <?php $this->toggle('catalog_update_titles', __('Update product titles during imports', 'delicat-fazercards'), __('Keep disabled if you customize WooCommerce product names.', 'delicat-fazercards'), $settings); ?>
                        <?php $this->toggle('native_fields_enabled', __('Native top-up fields for imported products', 'delicat-fazercards'), __('Renders FazerCards-required Player ID / account fields and stores them on the Woo order item.', 'delicat-fazercards'), $settings); ?>
                        <?php $this->toggle('uid_validation_enabled', __('Player ID / UID verification', 'delicat-fazercards'), __('Automatically enables secure server-side account verification for every imported top-up present in FazerCards’ live validation list, including regional/alias mappings and Player ID / UID / server-zone field variants. The supplier API key stays server-side.', 'delicat-fazercards'), $settings); ?>
                        <?php $this->toggle('uid_validation_required', __('Require verification before cart', 'delicat-fazercards'), __('For every top-up FazerCards reports as validation-capable, a fresh signed verification is required before Add to cart and the exact account fields are revalidated server-to-server before supplier balance is spent.', 'delicat-fazercards'), $settings); ?>
                        <?php $this->toggle('catalog_auto_sync', __('Automatic supplier cost & stock sync', 'delicat-fazercards'), __('Manual pricing mode updates supplier cost and stock only; your customer-facing WooCommerce prices remain untouched.', 'delicat-fazercards'), $settings); ?>
                    </div>
                    <label class="dfr-field dfr-field-small"><span><?php esc_html_e('Automatic sync interval', 'delicat-fazercards'); ?></span><select name="catalog_sync_interval"><option value="dfr_15_minutes" <?php selected($settings['catalog_sync_interval'],'dfr_15_minutes'); ?>>15 minutes</option><option value="hourly" <?php selected($settings['catalog_sync_interval'],'hourly'); ?>>Hourly</option><option value="twicedaily" <?php selected($settings['catalog_sync_interval'],'twicedaily'); ?>>Twice daily</option><option value="daily" <?php selected($settings['catalog_sync_interval'],'daily'); ?>>Daily</option></select></label>
                    <div class="dfr-save-row"><button class="button button-primary dfr-btn"><?php esc_html_e('Save pricing protection', 'delicat-fazercards'); ?></button><span><?php esc_html_e('Last sync:', 'delicat-fazercards'); ?> <?php echo esc_html(get_option('dfr_catalog_last_sync', '—')); ?></span></div>
                    <?php
                    /* 4.15.0: state plainly whether EVERY imported product is being
                       kept in step with the supplier, rather than only when the last
                       run happened. Before the rotating cursor, a large catalogue was
                       silently truncated to the first thirty categories forever and
                       nothing on this screen said so. */
                    $dfr_cov = $this->sync_coverage();
                    ?>
                    <p class="dfr-sync-coverage"><small>
                        <?php
                        echo esc_html(sprintf(
                            /* translators: 1: products, 2: categories */
                            __('Tracking %1$d imported products across %2$d supplier categories.', 'delicat-fazercards'),
                            (int) $dfr_cov['products'],
                            (int) $dfr_cov['groups']
                        ));
                        echo ' ';
                        if ($dfr_cov['groups'] > 0 && $dfr_cov['covered_this_cycle'] < $dfr_cov['groups']) {
                            echo esc_html(sprintf(
                                __('Current pass: %1$d of %2$d categories done.', 'delicat-fazercards'),
                                (int) $dfr_cov['covered_this_cycle'],
                                (int) $dfr_cov['groups']
                            ));
                            echo ' ';
                        }
                        if (!empty($dfr_cov['last_full_cycle'])) {
                            echo esc_html(sprintf(
                                __('Every imported product was last fully synchronized at %s UTC.', 'delicat-fazercards'),
                                (string) $dfr_cov['last_full_cycle']
                            ));
                        } else {
                            echo esc_html__('A first full pass over the whole catalog has not completed yet.', 'delicat-fazercards');
                        }
                        ?>
                    </small></p>
                </form>
            </section>

            <section class="dfr-panel" data-panel="automation">
                <form method="post" class="dfr-settings-form">
                    <?php wp_nonce_field('dfr_admin_action'); ?><input type="hidden" name="dfr_action" value="save_settings"><input type="hidden" name="dfr_settings_scope" value="automation">
                    <div class="dfr-panel-head"><div><h2><?php esc_html_e('Order automation', 'delicat-fazercards'); ?></h2><p><?php esc_html_e('Live supplier writes stay disabled until you explicitly enable them.', 'delicat-fazercards'); ?></p></div></div>
                    <div class="dfr-toggle-list">
                        <?php $this->toggle('live_orders_enabled', __('Enable live supplier ordering', 'delicat-fazercards'), __('This can charge your FazerCards balance. Keep OFF while testing catalog imports.', 'delicat-fazercards'), $settings, true); ?>
                        <?php $this->toggle('auto_fulfill_enabled', __('Automatically fulfill paid WooCommerce orders', 'delicat-fazercards'), __('Mapped/imported products are sent only after WooCommerce marks the order paid.', 'delicat-fazercards'), $settings); ?>
                        <?php $this->toggle('auto_complete_woo', __('Complete Woo orders after supplier completion', 'delicat-fazercards'), __('Automatically enforced when Live orders + Auto fulfillment are enabled. WooCommerce completes only after verified wallet payment and every tracked item is securely fulfilled.', 'delicat-fazercards'), $settings); ?>
                        <?php $this->toggle('customer_codes_enabled', __('Show delivered codes to the order owner', 'delicat-fazercards'), __('Codes remain encrypted at rest in order item metadata.', 'delicat-fazercards'), $settings); ?>
                        <?php $this->toggle('logging_enabled', __('Redacted WooCommerce technical logs', 'delicat-fazercards'), __('Secrets, tokens, PINs and delivered codes are filtered from logs.', 'delicat-fazercards'), $settings); ?>
                    </div>
                    <div class="dfr-save-row"><button class="button button-primary dfr-btn"><?php esc_html_e('Save automation', 'delicat-fazercards'); ?></button></div>
                </form>
            </section>

            <section class="dfr-panel" data-panel="security">
                <form method="post" class="dfr-settings-form">
                    <?php wp_nonce_field('dfr_admin_action'); ?><input type="hidden" name="dfr_action" value="save_settings"><input type="hidden" name="dfr_settings_scope" value="security">
                    <div class="dfr-panel-head"><div><h2><?php esc_html_e('Credentials & webhook security', 'delicat-fazercards'); ?></h2><p><?php esc_html_e('API requests are server-to-server. Credentials are never localized to JavaScript or returned by catalog AJAX.', 'delicat-fazercards'); ?></p></div></div>
                    <div class="dfr-form-grid">
                        <label class="dfr-field"><span><?php esc_html_e('FazerCards API key', 'delicat-fazercards'); ?></span><?php if ($api_constant): ?><input type="text" value="DELICAT_FAZER_API_KEY" disabled><small><?php esc_html_e('Loaded from wp-config.php — recommended.', 'delicat-fazercards'); ?></small><?php else: ?><input name="api_key" type="password" autocomplete="new-password" placeholder="<?php echo $this->plugin()->has_api_key() ? esc_attr__('Stored securely — enter to replace', 'delicat-fazercards') : esc_attr__('Paste API key', 'delicat-fazercards'); ?>"><small><?php esc_html_e('Encrypted before database storage.', 'delicat-fazercards'); ?></small><?php endif; ?></label>
                        <label class="dfr-field"><span><?php esc_html_e('Webhook secret', 'delicat-fazercards'); ?></span><?php if ($wh_constant): ?><input type="text" value="DELICAT_FAZER_WEBHOOK_SECRET" disabled><small><?php esc_html_e('Loaded from wp-config.php — recommended.', 'delicat-fazercards'); ?></small><?php else: ?><input name="webhook_secret" type="password" autocomplete="new-password" placeholder="<?php echo $this->plugin()->has_webhook_secret() ? esc_attr__('Stored securely — enter to replace', 'delicat-fazercards') : esc_attr__('Paste webhook secret', 'delicat-fazercards'); ?>"><small><?php esc_html_e('Used for constant-time HMAC signature verification.', 'delicat-fazercards'); ?></small><?php endif; ?></label>
                    </div>
                    <div class="dfr-webhook-box"><span><?php esc_html_e('Webhook URL', 'delicat-fazercards'); ?></span><code><?php echo esc_html($webhook_url); ?></code></div>
                    <div class="dfr-security-list">
                        <div>✓ <?php esc_html_e('Fixed HTTPS supplier host; redirects and unsafe URLs are rejected', 'delicat-fazercards'); ?></div>
                        <div>✓ <?php esc_html_e('Admin mutations require both WooCommerce capability checks and nonces', 'delicat-fazercards'); ?></div>
                        <div>✓ <?php esc_html_e('Immutable encrypted fulfillment snapshots keep retry bodies bound to their idempotency keys', 'delicat-fazercards'); ?></div>
                        <div>✓ <?php esc_html_e('Signed webhooks are verified on the raw body, deduplicated, then canonically synchronized immediately', 'delicat-fazercards'); ?></div>
                        <div>✓ <?php esc_html_e('Delivery codes and sensitive fulfillment fields use authenticated encryption at rest', 'delicat-fazercards'); ?></div>
                        <div>✓ <?php esc_html_e('Reseller API responses and authenticated account pages are explicitly no-store', 'delicat-fazercards'); ?></div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin:14px 0 6px">
                        <div class="dfr-webhook-box"><span><?php esc_html_e('Store HTTPS', 'delicat-fazercards'); ?></span><strong><?php echo $https_ok ? '✓ ' . esc_html__('Ready', 'delicat-fazercards') : '⚠ ' . esc_html__('Required for production', 'delicat-fazercards'); ?></strong></div>
                        <div class="dfr-webhook-box"><span><?php esc_html_e('Authenticated encryption', 'delicat-fazercards'); ?></span><strong><?php echo $crypto_ok ? '✓ ' . esc_html__('Available', 'delicat-fazercards') : '✕ ' . esc_html__('Unavailable', 'delicat-fazercards'); ?></strong></div>
                        <div class="dfr-webhook-box"><span><?php esc_html_e('Background queue', 'delicat-fazercards'); ?></span><strong><?php echo $as_ok ? '✓ Action Scheduler' : '↻ WP-Cron fallback'; ?></strong></div>
                        <div class="dfr-webhook-box"><span><?php esc_html_e('Credential location', 'delicat-fazercards'); ?></span><strong><?php echo ($api_constant && $wh_constant) ? '✓ wp-config.php' : esc_html__('Encrypted database storage', 'delicat-fazercards'); ?></strong></div>
                        <div class="dfr-webhook-box"><span><?php esc_html_e('Provider webhook', 'delicat-fazercards'); ?></span><strong><?php echo $webhook_ready ? '✓ ' . esc_html__('Enabled & URL matched', 'delicat-fazercards') : '⚠ ' . esc_html__('Not verified', 'delicat-fazercards'); ?></strong><?php if ($webhook_checked !== ''): ?><small><?php echo esc_html(sprintf(__('Checked %s', 'delicat-fazercards'), $webhook_checked)); ?></small><?php endif; ?></div>
                        <div class="dfr-webhook-box"><span><?php esc_html_e('Signed delivery test', 'delicat-fazercards'); ?></span><strong><?php echo $webhook_test_ok ? '✓ ' . esc_html__('Passed', 'delicat-fazercards') : '— ' . esc_html__('Run test', 'delicat-fazercards'); ?></strong><?php if (!empty($webhook_health['last_received_at'])): ?><small><?php echo esc_html(sprintf(__('Last signed callback %s', 'delicat-fazercards'), (string) $webhook_health['last_received_at'])); ?></small><?php elseif (!empty($webhook_health['consecutive_failures'])): ?><small><?php echo esc_html(sprintf(__('%d consecutive provider failures', 'delicat-fazercards'), absint($webhook_health['consecutive_failures']))); ?></small><?php endif; ?></div>
                    </div>
                    <div class="dfr-save-row"><button class="button button-primary dfr-btn"><?php esc_html_e('Save credentials', 'delicat-fazercards'); ?></button></div>
                </form>
                <form method="post" class="dfr-settings-form" style="margin-top:12px">
                    <?php wp_nonce_field('dfr_admin_action'); ?>
                    <div class="dfr-save-row">
                        <button class="button button-primary dfr-btn" name="dfr_action" value="configure_webhook"><?php esc_html_e('Configure & test FazerCards webhook', 'delicat-fazercards'); ?></button>
                        <button class="button dfr-btn" name="dfr_action" value="test_webhook"><?php esc_html_e('Test existing webhook', 'delicat-fazercards'); ?></button>
                        <span><?php esc_html_e('Uses the official account webhook API; the returned secret is encrypted locally and never displayed.', 'delicat-fazercards'); ?></span>
                    </div>
                </form>

                <form method="post" class="dfr-settings-form" style="margin-top:12px">
                    <?php wp_nonce_field('dfr_admin_action'); ?>
                    <div class="dfr-panel-head" style="margin-bottom:10px">
                        <div>
                            <h2><?php esc_html_e('Player ID server diagnostic', 'delicat-fazercards'); ?></h2>
                            <p><?php esc_html_e('Runs a read-only authenticated test: /me → validation catalog → Free Fire capability → Player ID validation. It never creates an order, never debits balance, and never stores the Player ID.', 'delicat-fazercards'); ?></p>
                        </div>
                    </div>
                    <div class="dfr-form-grid">
                        <label class="dfr-field">
                            <span><?php esc_html_e('Free Fire Player ID to test', 'delicat-fazercards'); ?></span>
                            <input name="dfr_diagnostic_player_id" type="text" inputmode="numeric" maxlength="64" autocomplete="off" placeholder="<?php esc_attr_e('Example: 1234567890', 'delicat-fazercards'); ?>" required>
                            <small><?php esc_html_e('Used in-memory for this diagnostic only; it is not persisted in WordPress.', 'delicat-fazercards'); ?></small>
                        </label>
                    </div>
                    <div class="dfr-save-row">
                        <button class="button button-primary dfr-btn" name="dfr_action" value="diagnose_player_id_server"><?php esc_html_e('Test FazerCards Player ID server', 'delicat-fazercards'); ?></button>
                    </div>
                </form>

                <?php if ($uid_diag): ?>
                    <?php
                    $diag_overall = in_array(($uid_diag['overall'] ?? ''), array('pass','warning','fail'), true) ? $uid_diag['overall'] : 'fail';
                    $diag_bg = $diag_overall === 'pass' ? '#ecfdf5' : ($diag_overall === 'warning' ? '#fff7ed' : '#fef2f2');
                    $diag_border = $diag_overall === 'pass' ? '#a7f3d0' : ($diag_overall === 'warning' ? '#fed7aa' : '#fecaca');
                    $diag_text = $diag_overall === 'pass' ? '#065f46' : ($diag_overall === 'warning' ? '#9a3412' : '#991b1b');
                    ?>
                    <div class="dfr-settings-form" style="margin-top:12px;border:1px solid <?php echo esc_attr($diag_border); ?>;background:<?php echo esc_attr($diag_bg); ?>;">
                        <div class="dfr-panel-head" style="margin-bottom:8px">
                            <div>
                                <h2 style="color:<?php echo esc_attr($diag_text); ?>;margin-bottom:4px"><?php echo esc_html((string) ($uid_diag['headline'] ?? __('Player ID diagnostic result', 'delicat-fazercards'))); ?></h2>
                                <?php if (!empty($uid_diag['message'])): ?><p style="color:<?php echo esc_attr($diag_text); ?>"><?php echo esc_html((string) $uid_diag['message']); ?></p><?php endif; ?>
                            </div>
                            <span class="dfr-badge <?php echo $diag_overall === 'pass' ? 'is-live' : 'is-safe'; ?>"><?php echo esc_html(strtoupper($diag_overall)); ?></span>
                        </div>
                        <div style="display:grid;gap:8px">
                            <?php foreach (!empty($uid_diag['steps']) && is_array($uid_diag['steps']) ? $uid_diag['steps'] : array() as $step): ?>
                                <?php
                                $step_status = in_array(($step['status'] ?? ''), array('pass','warning','fail'), true) ? $step['status'] : 'fail';
                                $step_icon = $step_status === 'pass' ? '✓' : ($step_status === 'warning' ? '⚠' : '✕');
                                ?>
                                <div class="dfr-webhook-box" style="background:#fff">
                                    <span><?php echo esc_html($step_icon . ' ' . (string) ($step['label'] ?? '')); ?></span>
                                    <strong><?php echo esc_html((string) ($step['detail'] ?? '')); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;font-size:12px">
                            <code><?php echo esc_html('classification=' . sanitize_key((string) ($uid_diag['classification'] ?? ''))); ?></code>
                            <?php if (!empty($uid_diag['http'])): ?><code><?php echo esc_html('http=' . absint($uid_diag['http'])); ?></code><?php endif; ?>
                            <?php if (!empty($uid_diag['provider_code'])): ?><code><?php echo esc_html('provider=' . sanitize_key((string) $uid_diag['provider_code'])); ?></code><?php endif; ?>
                            <?php if (!empty($uid_diag['retried'])): ?><code><?php esc_html_e('safe_retry=1', 'delicat-fazercards'); ?></code><?php endif; ?>
                            <?php if (!empty($uid_diag['checked_at'])): ?><code><?php echo esc_html('checked=' . substr(sanitize_text_field((string) $uid_diag['checked_at']), 0, 40)); ?></code><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
            <div id="dfr-toast" class="dfr-toast" role="status" aria-live="polite"></div>
        </div>
        <?php
    }

    private function toggle($name, $title, $description, $settings, $danger = false) {
        ?>
        <label class="dfr-toggle-row <?php echo $danger ? 'is-danger' : ''; ?>">
            <span><strong><?php echo esc_html($title); ?></strong><small><?php echo esc_html($description); ?></small></span>
            <span class="dfr-switch"><input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($settings[$name], '1'); ?>><i></i></span>
        </label>
        <?php
    }
}
