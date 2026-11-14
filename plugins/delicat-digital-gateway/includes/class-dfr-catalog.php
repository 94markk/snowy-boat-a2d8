<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-delicat-player-region.php';

final class DFR_Catalog {
    private static $instance;

    /**
     * Interactive Player ID verification timing. One coherent budget replaces the
     * old 8s supplier timeout + 8s retry that could never finish before the
     * browser's 12s abort. FazerCards' SDKs default to 30s and the supplier
     * documents that publisher-side ID checks can add delay, so a single attempt
     * gets up to UID_INTERACTIVE_ATTEMPT_TIMEOUT seconds; a recovery attempt runs
     * only when at least UID_INTERACTIVE_MIN_RETRY_SECONDS remain in the budget.
     * The storefront fetch aborts a little after the whole budget.
     */
    const UID_INTERACTIVE_BUDGET_SECONDS = 18;
    const UID_INTERACTIVE_ATTEMPT_TIMEOUT = 12;
    const UID_INTERACTIVE_MIN_RETRY_SECONDS = 6;
    const UID_BROWSER_ABORT_MS = 22000;

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

    /**
     * Request-local memos: Player ID validation context and field schema per product id,
     * supplier offers (offer id => stock) per service|category.
     */
    private $uid_context_memo = array();
    private $field_spec_memo = array();
    private $availability_memo = array();

    const SYNC_GROUPS_OPTION = 'dfr_catalog_sync_groups_v1';
    const SYNC_HEALTH_OPTION = 'dfr_catalog_sync_health_v1';
    const SYNC_MAX_ROUNDS = 200;
    const MARGIN_OPTION = 'dfr_margin_guard_v1';
    const MARGIN_ALERTS_OPTION = 'dfr_margin_alerts_v1';
    const SCHEMA_CHANGES_OPTION = 'dfr_catalog_schema_changes_v1';

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
        add_action('wp_ajax_dfr_live_balance', array($this, 'ajax_live_balance'));
        add_action('dfr_balance_sync_event', array($this, 'refresh_balance'));
        add_action('dfr_remote_webhook_event', array($this, 'supplier_event_balance_refresh'), 20, 3);
        add_action('dfr_catalog_sync_continue', array($this, 'cron_sync_imported'));
        add_action('update_option_dfr_settings', array($this, 'reset_live_sync'));
        add_action('wp_ajax_dfr_catalog_categories', array($this, 'ajax_categories'));
        add_action('wp_ajax_dfr_catalog_offers', array($this, 'ajax_offers'));
        add_action('wp_ajax_dfr_catalog_import', array($this, 'ajax_import'));
        add_action('wp_ajax_dfr_catalog_sync_imported', array($this, 'ajax_sync_imported'));
        add_action('wp_ajax_dfr_verify_player_uid', array($this, 'ajax_verify_player_uid'));
        add_action('wp_ajax_nopriv_dfr_verify_player_uid', array($this, 'ajax_verify_player_uid'));
        add_action('wp_ajax_dfr_uid_nonce', array($this, 'ajax_uid_nonce'));
        add_action('wp_ajax_nopriv_dfr_uid_nonce', array($this, 'ajax_uid_nonce'));
        add_action('wp_ajax_dfr_clear_recent_player_ids', array($this, 'ajax_clear_recent_player_ids'));

        // Headless storefront bridge. WooCommerce and this gateway remain the
        // only authorities for field schemas and Player ID verification.
        add_filter('delicat_app_product_fields', array($this, 'app_api_product_fields'), 20, 2);
        add_filter('delicat_app_product_needs_player_id', array($this, 'app_api_needs_player_id'), 20, 2);
        add_action('rest_api_init', array($this, 'register_app_api_routes'));
        add_filter('rest_request_before_callbacks', array($this, 'capture_app_api_cart_request'), 20, 3);

        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action('init', array($this, 'ensure_cron'));
        add_action('dfr_catalog_sync_event', array($this, 'cron_sync_imported'));
        add_action('dfr_catalog_sync_start', array($this, 'run_sync_start'));
        add_action('dfr_uid_validation_games_refresh', array($this, 'refresh_uid_validation_games'));
        // A sync cycle runs in small batches; this hook continues it.
        add_action('dfr_catalog_sync_batch', array($this, 'run_sync_batch'), 10, 1);

        add_action('woocommerce_before_add_to_cart_button', array($this, 'render_native_fields'));
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_native_fields'), 10, 5);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_native_cart_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'display_native_cart_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_native_order_item_data'), 10, 4);
        add_filter('woocommerce_quantity_input_args', array($this, 'quantity_input_args'), 10, 2);
        add_filter('woocommerce_available_variation', array($this, 'available_variation_limits'), 10, 3);
        add_action('woocommerce_product_options_inventory_product_data', array($this, 'render_stock_sync_control'));
        add_action('woocommerce_admin_process_product_object', array($this, 'save_stock_sync_control'));
        add_action('woocommerce_variation_options_inventory', array($this, 'render_variation_stock_sync_control'), 10, 3);
        add_action('woocommerce_admin_process_variation_object', array($this, 'save_variation_stock_sync_control'), 10, 2);

        // Supplier availability and the opt-in margin hold, checked where a line is bought:
        // Delicat App API add and checkout (filter, contract K32), website add-to-cart and
        // cart/checkout. Never through woocommerce_is_purchasable, which silently drops lines.
        add_filter('delicat_dfr_cart_line_available', array($this, 'cart_line_available'), 10, 4);
        add_action('woocommerce_check_cart_items', array($this, 'check_cart_supplier_availability'), 20);
        add_action('admin_post_dfr_margin_guard_save', array($this, 'save_margin_guard_settings'));
        add_action('init', array($this, 'maybe_tag_auto_created_terms'), 40);
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
     * Whether this gateway is the supplier of a product or variation.
     *
     * The service type is the same value order_has_gateway_items() tests before this
     * plugin takes a line at fulfilment, so a product that answers true here is exactly a
     * product this plugin would deliver.
     *
     * @param int $id Product or variation id.
     * @return bool
     */
    private function product_is_ours($id) {
        $id = absint($id);
        if ($id < 1) { return false; }
        return in_array((string) get_post_meta($id, '_dfr_service_type', true), array('topup', 'giftcard', 'gamekey'), true);
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
        $requested_id = $product_id;
        if ($product->get_parent_id()) { $product_id = absint($product->get_parent_id()); }

        // 4.21.0: this route verifies against THIS supplier's engine, so it must only ever
        // answer for this supplier's products. Nothing used to stop a product another
        // supplier fulfils (Shop2TopUp, meta _dst2t_*) from being sent here: the customer's
        // Player ID was checked by the wrong supplier, a pass or a fail meant nothing for
        // the supplier that would actually deliver, and every attempt spent a slot of this
        // gateway's per-customer verification budget. Checked BEFORE the rate limit, so a
        // misrouted call cannot cost the customer their real verifications.
        if (!$this->product_is_ours($requested_id) && !$this->product_is_ours($product_id)) {
            // Another supplier owns this product. 4.21.0 refused outright, and that was
            // wrong: the app shows ONE « Vérifier le compte joueur » button, so a
            // Shop2TopUp product was left with no way to verify at all. Each supplier has
            // its own player-ID API, so hand the check to whoever owns the product and
            // only refuse when nobody answers. Still before the rate limit, so a check
            // this gateway does not perform never spends its budget.
            //
            // A delegate returns the same shape this route returns: success, supported,
            // valid, player_name, region, message.
            $delegated = apply_filters('dfr_app_player_verify_delegate', null, $product_id, $requested_id, $request);
            if (null !== $delegated) { return $delegated; }
            return new WP_Error(
                'dfr_app_not_our_product',
                __('La vérification automatique n’est pas disponible pour ce produit.', 'delicat-fazercards'),
                array('status' => 409)
            );
        }

        if (class_exists('Delicat_App_Helpers') && !Delicat_App_Helpers::rate_limit('dfr_uid_verify:' . get_current_user_id(), 20, MINUTE_IN_SECONDS)) {
            return Delicat_App_Helpers::throttled();
        }

        $incoming = $request->get_param('fields');
        $incoming = is_array($incoming) ? $incoming : array();
        $fields = $this->sanitize_app_api_fields($product_id, $incoming);
        if (is_wp_error($fields)) { return $fields; }

        $result = $this->validate_reseller_topup_uid($product_id, $fields, true, 'app_verify');
        if (is_wp_error($result) && $result->get_error_code() === 'dapi_uid_region_mismatch') {
            return rest_ensure_response(array('success'=>true,'supported'=>true,'valid'=>false,'code'=>'region_mismatch','player_name'=>'','region'=>'','message'=>$this->uid_region_notice($result)));
        }
        if (is_wp_error($result) && $result->get_error_code() === 'dapi_uid_invalid') {
            // The supplier answered that this account does not exist: that is an answer, not an
            // outage, so this route reports it as a successful check with valid:false.
            //
            // 4.19.0 raised HTTP 422 dapi_uid_invalid here. App 4.2 understands both shapes, but
            // the installed 4.0.0 and 4.1.0 treat every error from this route as "verification
            // unavailable, allow" and let the sale through, which leaves the order unfulfillable.
            // The 4.19.0 classification is therefore carried in `code` instead of the status, and
            // the 422 is unchanged everywhere else it is raised (reseller API, app add-to-cart).
            return rest_ensure_response(array(
                'success' => true,
                'supported' => true,
                'valid' => false,
                'code' => 'dapi_uid_invalid',
                'player_name' => '',
                'region' => '',
                'message' => __('Ce compte est introuvable. Vérifiez l’identifiant.', 'delicat-fazercards'),
            ));
        }
        if (is_wp_error($result)) { return $this->app_uid_error($result); }
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
                $candidate = $this->select_value_from_label($field, $candidate);
            }
            if ($field['type'] === 'select' && !empty($field['options']) && !array_key_exists($candidate, $field['options'])) {
                return new WP_Error('dfr_app_bad_field', sprintf(__('Valeur invalide pour %s.', 'delicat-fazercards'), $field['label']), array('status' => 422, 'field' => $key));
            }
            $values[$key] = substr($candidate, 0, 190);
        }
        return $values;
    }

    /**
     * Until this release some select fields reached customers with the option label as its
     * value (« America » for os_usa). A screen still open from then sends the label: map it
     * back when exactly one option carries that label; anything else is returned unchanged.
     */
    private function select_value_from_label(array $field, $candidate) {
        $candidate = (string) $candidate;
        if (empty($field['options']) || !is_array($field['options'])) { return $candidate; }
        $matches = array();
        foreach ($field['options'] as $value => $label) {
            if ((string) $label === $candidate) { $matches[] = (string) $value; }
        }
        return count($matches) === 1 ? $matches[0] : $candidate;
    }

    /** A Player ID validation error with customer copy for the app; code and status unchanged. */
    private function app_uid_error($error) {
        if (!is_wp_error($error)) { return $error; }
        $messages = array(
            'dapi_uid_region_mismatch' => $this->uid_region_notice($error),
            'dapi_uid_invalid'        => __('Ce compte joueur est introuvable. Vérifiez l’identifiant.', 'delicat-fazercards'),
            'dapi_uid_fields_missing' => __('Les informations du compte joueur sont incomplètes.', 'delicat-fazercards'),
            'dapi_uid_upstream'       => __('La vérification du compte joueur est momentanément indisponible. Réessayez dans un instant.', 'delicat-fazercards'),
            'dapi_uid_throttled'      => __('Trop de vérifications en peu de temps. Réessayez dans une minute.', 'delicat-fazercards'),
        );
        $code = (string) $error->get_error_code();
        if (!isset($messages[$code])) { return $error; }
        return new WP_Error($code, $messages[$code], $error->get_error_data());
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
        $relabelled = false;
        foreach ($this->get_field_spec_for_product($product_id) as $field) {
            $key = sanitize_key((string) ($field['key'] ?? ''));
            if ($key === '' || !isset($incoming[$key]) || is_array($incoming[$key]) || is_object($incoming[$key])) { continue; }
            $value = substr(trim(sanitize_text_field((string) $incoming[$key])), 0, 190);
            if ($value === '') { continue; }
            if (($field['type'] ?? '') === 'select' && !empty($field['options']) && !array_key_exists($value, $field['options'])) {
                // App 4.0.0 screen opened before option values were fixed: label -> value.
                $from_label = $this->select_value_from_label($field, $value);
                if ($from_label !== $value) {
                    $value = $from_label;
                    $incoming[$key] = $from_label;
                    $relabelled = true;
                }
            }
            $values[$key] = $value;
            $_POST['dfr_field_' . $key] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }
        if ($relabelled) {
            // The App API cleans the same `fields` parameter: give it the value too.
            $request->set_param('fields', $incoming);
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
        // Every event of each hook, whatever its arguments (sync batches carry their round).
        // 4.19.0 scheduled the continue/balance events; clear them so an upgrade leaves nothing behind.
        foreach (array('dfr_catalog_sync_event', 'dfr_catalog_sync_batch', 'dfr_uid_validation_games_refresh', 'dfr_catalog_sync_continue', 'dfr_balance_sync_event', 'dfr_public_privacy_migration_batch') as $hook) {
            if (function_exists('wp_unschedule_hook')) { wp_unschedule_hook($hook); } else { wp_clear_scheduled_hook($hook); }
        }
        if (function_exists('as_unschedule_all_actions')) {
            foreach (array('dfr_catalog_sync_start', 'dfr_catalog_sync_batch', 'dfr_balance_sync_event') as $hook) {
                // Hook only: an args filter of array() would match argument-less jobs only.
                as_unschedule_all_actions($hook);
            }
        }
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
        $schedules['dfr_30_seconds'] = array('interval' => 30, 'display' => __('Every 30 seconds', 'delicat-fazercards'));
        $schedules['dfr_5_minutes'] = array('interval' => 5 * MINUTE_IN_SECONDS, 'display' => __('Every 5 minutes', 'delicat-fazercards'));
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
        $interval = !empty($settings['catalog_sync_interval']) && in_array($settings['catalog_sync_interval'], array('dfr_30_seconds', 'dfr_15_minutes', 'hourly', 'twicedaily', 'daily'), true)
            ? $settings['catalog_sync_interval'] : 'dfr_30_seconds';
        // The supplier balance is only displayed to administrators, refreshed on demand
        // in Catalog Studio and after every signed supplier event. A 30-second WP-Cron
        // poll (v4.23) added a supplier request to every cron run for nothing.
        if ($this->plugin()->api()->is_configured()) {
            $balance_next = wp_next_scheduled('dfr_balance_sync_event');
            if ($balance_next && wp_get_schedule('dfr_balance_sync_event') !== 'dfr_5_minutes') {
                wp_clear_scheduled_hook('dfr_balance_sync_event');
                $balance_next = false;
            }
            if (!$balance_next) {
                wp_schedule_event(time() + 60, 'dfr_5_minutes', 'dfr_balance_sync_event');
            }
        }
        $scheduled = wp_next_scheduled('dfr_catalog_sync_event');
        $current_schedule = $scheduled ? wp_get_schedule('dfr_catalog_sync_event') : false;

        if (!$enabled) {
            wp_clear_scheduled_hook('dfr_catalog_sync_continue');
            if ($scheduled) {
                wp_clear_scheduled_hook('dfr_catalog_sync_event');
            }
            return;
        }

        if (!$scheduled || $current_schedule !== $interval) {
            wp_clear_scheduled_hook('dfr_catalog_sync_event');
            wp_schedule_event(time() + 5, $interval, 'dfr_catalog_sync_event');
        }
    }

    public function reset_live_sync() {
        delete_transient('dfr_live_balance');
        delete_transient('dfr_balance_backoff');
        delete_transient('dfr_catalog_backoff');
        delete_option('dfr_catalog_sync_cursor');
    }

    public function refresh_balance() {
        if (!$this->plugin()->api()->is_configured()) { return new WP_Error('dfr_no_key', 'API not configured'); }
        $cached = get_transient('dfr_live_balance');
        if (is_array($cached) && time() - (int) $cached['checked_at'] < 10) { return $cached; }
        if (get_transient('dfr_balance_backoff')) { return new WP_Error('dfr_balance_wait', 'Balance refresh temporarily delayed'); }
        $lock = $this->acquire_db_lock('live_balance', 0);
        if ($lock === '') { return new WP_Error('dfr_balance_busy', 'Balance refresh in progress'); }
        try {
            $cached = get_transient('dfr_live_balance');
            if (is_array($cached) && time() - (int) $cached['checked_at'] < 10) { return $cached; }
            $data = $this->plugin()->api()->balance(6);
            if (is_wp_error($data) || !isset($data['balance']) || !is_numeric($data['balance'])) {
                $detail = is_wp_error($data) ? $data->get_error_data() : array();
                $delay = max(15, min(3600, (int) ($detail['retry_after'] ?? 30)));
                set_transient('dfr_balance_backoff', 1, $delay);
                return is_wp_error($data) ? $data : new WP_Error('dfr_bad_balance', 'Invalid supplier balance');
            }
            $data = array('balance' => (string) $data['balance'], 'currency' => sanitize_text_field($data['currency'] ?? 'USD'), 'checked_at' => time());
            set_transient('dfr_live_balance', $data, DAY_IN_SECONDS);
            return $data;
        } finally { $this->release_db_lock($lock); }
    }

    /** Drop the short balance cache as soon as an authenticated supplier event lands. */
    public function supplier_event_balance_refresh($remote, $status, $payload = array()) {
        delete_transient('dfr_live_balance');
        delete_transient('dfr_balance_backoff');
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('dfr_balance_sync_event', array(), 'delicat-fazercards', true, 150);
        } elseif (!wp_next_scheduled('dfr_balance_sync_event')) {
            wp_schedule_single_event(time(), 'dfr_balance_sync_event');
        }
    }

    /** Only imported, completely mapped products may be controlled by supplier stock. */
    private function supplier_stock_sync_enabled($product_id) {
        $product_id = absint($product_id);
        if (!$product_id || get_post_meta($product_id, '_dfr_imported', true) !== '1') { return false; }
        if ((string) get_post_meta($product_id, '_dfr_stock_sync_enabled', true) === '0') { return false; }
        $offer = (string) get_post_meta($product_id, '_dfr_offer_id', true);
        $service = $this->sanitize_service(get_post_meta($product_id, '_dfr_service_type', true));
        $category = $this->sanitize_remote_id(get_post_meta($product_id, '_dfr_category_id', true));
        $enabled = $offer !== '' && $service !== '' && $category !== '';
        return (bool) apply_filters('dfr_supplier_stock_sync_enabled', $enabled, $product_id);
    }

    public function render_stock_sync_control() {
        global $post;
        $id = $post ? absint($post->ID) : 0;
        if (!$id || get_post_meta($id, '_dfr_imported', true) !== '1' || !function_exists('woocommerce_wp_checkbox')) { return; }
        woocommerce_wp_checkbox(array(
            'id' => '_dfr_stock_sync_enabled',
            'label' => __('Supplier stock sync', 'delicat-fazercards'),
            'description' => __('Allow this mapped product to follow supplier availability. Disable it to manage stock only in WooCommerce.', 'delicat-fazercards'),
            'value' => get_post_meta($id, '_dfr_stock_sync_enabled', true) === '0' ? 'no' : 'yes',
        ));
    }

    public function save_stock_sync_control($product) {
        if (!$product || get_post_meta($product->get_id(), '_dfr_imported', true) !== '1') { return; }
        $product->update_meta_data('_dfr_stock_sync_enabled', isset($_POST['_dfr_stock_sync_enabled']) ? '1' : '0');
    }

    public function render_variation_stock_sync_control($loop, $variation_data, $variation) {
        $id = !empty($variation->ID) ? absint($variation->ID) : 0;
        if (!$id || get_post_meta($id, '_dfr_imported', true) !== '1') { return; }
        $checked = get_post_meta($id, '_dfr_stock_sync_enabled', true) === '0' ? '' : ' checked="checked"';
        echo '<label><input type="checkbox" name="dfr_variation_stock_sync[' . esc_attr($id) . ']" value="1"' . $checked . '> ' . esc_html__('Supplier stock sync', 'delicat-fazercards') . '</label>';
    }

    public function save_variation_stock_sync_control($variation, $index) {
        $id = $variation ? absint($variation->get_id()) : 0;
        if (!$id || get_post_meta($id, '_dfr_imported', true) !== '1') { return; }
        $posted = isset($_POST['dfr_variation_stock_sync']) && is_array($_POST['dfr_variation_stock_sync']) ? wp_unslash($_POST['dfr_variation_stock_sync']) : array();
        $variation->update_meta_data('_dfr_stock_sync_enabled', isset($posted[$id]) ? '1' : '0');
    }

    public function ajax_live_balance() {
        $this->require_admin_ajax();
        $data = $this->refresh_balance();
        if (is_wp_error($data)) {
            $last = get_transient('dfr_live_balance');
            if (!is_array($last)) { wp_send_json_error(array('message' => 'Balance temporarily unavailable'), 503); }
            $data = $last;
            $data['stale'] = true;
        } else { $data['stale'] = false; }
        $data['catalog_checked_at'] = (string) get_option('dfr_catalog_last_sync', '');
        wp_send_json_success($data);
    }

    public function ajax_status() {
        $this->require_admin_ajax();
        $api = $this->plugin()->api();
        $me = $api->me();
        if (is_wp_error($me)) {
            wp_send_json_error(array('message' => $me->get_error_message()), 400);
        }
        $balance = $this->refresh_balance();
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
        // The store's base currency: a multi-currency display filter is active in admin-ajax.
        $currency = strtoupper((string) get_option('woocommerce_currency', 'USD'));
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

        // Each mapping field an import writes is recorded by the plugin's meta guard; the run
        // itself (what was imported, by whom) is recorded here.
        $this->audit('catalog_import', array(
            'service' => $service,
            'category' => $category_id,
            'structure' => $import_structure,
            'created' => $created,
            'updated' => $updated,
            'variations_created' => $variation_created,
            'variations_updated' => $variation_updated,
            'warnings' => count($errors),
        ));

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
        $this->audit('catalog_sync_manual', array(
            'groups' => (int) ($result['groups'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'missing' => (int) ($result['missing'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
            'cost_changes' => (int) ($result['cost_changes'] ?? 0),
            'remaining' => (int) ($result['remaining'] ?? 0),
        ));
        wp_send_json_success($result);
    }

    public function cron_sync_imported() {
        if (($this->settings()['catalog_auto_sync'] ?? '0') !== '1' || get_transient('dfr_catalog_backoff')) { return; }
        // v4.24: hand the batch to Action Scheduler at low priority instead of running up
        // to 20 s of supplier I/O inside WP-Cron. WP-Cron runs its events one after the
        // other in a single request (including Action Scheduler's own queue runner and the
        // delivery watchdog), so the inline sync delayed every order-status fallback.
        if (function_exists('as_enqueue_async_action') && did_action('action_scheduler_init')) {
            try {
                if (function_exists('as_has_scheduled_action') && (as_has_scheduled_action('dfr_catalog_sync_start', array(), 'delicat-fazercards') || as_has_scheduled_action('dfr_catalog_sync_batch', null, 'delicat-fazercards'))) {
                    return;
                }
                if ((int) as_enqueue_async_action('dfr_catalog_sync_start', array(), 'delicat-fazercards', true, 200) > 0) { return; }
            } catch (Throwable $e) {
                // Inline below.
            }
        }
        $this->sync_imported_products(true);
    }

    /** Low-priority queue entry for one background sync batch. */
    public function run_sync_start() {
        if (($this->settings()['catalog_auto_sync'] ?? '0') !== '1' || get_transient('dfr_catalog_backoff')) { return; }
        $this->sync_imported_products(true);
    }

    /** Continue a sync cycle started by the recurring event or a manual run. */
    public function run_sync_batch($round = 1) {
        // A busy lock means another run holds the cycle; it schedules what follows.
        $this->sync_imported_products(true, max(1, absint($round)));
    }

    /** Append an entry to the plugin's administrator audit log. */
    private function audit($action, array $details) {
        $plugin = $this->plugin();
        if (is_callable(array($plugin, 'record_admin_audit'))) {
            $plugin->record_admin_audit($action, $details);
        }
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

        $fetched = $this->fetch_supplier_offers($service, $category_id);
        if (is_wp_error($fetched)) { return $fetched; }

        $existing = $this->existing_map($service, $category_id);
        $items = array();
        foreach ($fetched['items'] as $item) {
            $item['product_id'] = isset($existing[$item['id']]) ? (int) $existing[$item['id']] : 0;
            $items[] = $item;
        }
        $result = array(
            'service' => $service,
            'category_id' => $category_id,
            'category_name' => $fetched['category_name'],
            'fields' => $fetched['fields'],
            'complete' => !empty($fetched['complete']),
            'items' => $items,
        );
        set_transient($cache_key, $result, $this->cache_ttl());
        return $result;
    }

    /**
     * One supplier offer list, normalized, without store lookups or caching.
     *
     * @param int $timeout Seconds: the API default for admin imports, 8 for background sync,
     *                     5 for checkout availability checks.
     * @return array{category_name:string,fields:array,items:array}|WP_Error
     */
    private function fetch_supplier_offers($service, $category_id, $timeout = 20) {
        $api = $this->plugin()->api();
        if ($service === 'topup') {
            $raw = $api->topup_offers($category_id, $timeout);
            if (is_wp_error($raw)) { return $raw; }
            $rows = isset($raw['offers']) && is_array($raw['offers']) ? $raw['offers'] : array();
            $category_name = sanitize_text_field($raw['name'] ?? $category_id);
            $fields = isset($raw['fields']) && is_array($raw['fields']) ? $this->sanitize_field_spec($raw['fields']) : array();
        } elseif ($service === 'giftcard') {
            $raw = $api->giftcard_cards($category_id, $timeout);
            if (is_wp_error($raw)) { return $raw; }
            $rows = isset($raw['offers']) && is_array($raw['offers']) ? $raw['offers'] : array();
            $category_name = sanitize_text_field($raw['name'] ?? $category_id);
            $fields = array();
        } else {
            $raw = $api->gamekey_keys($category_id, $timeout);
            if (is_wp_error($raw)) { return $raw; }
            $rows = isset($raw['keys']) && is_array($raw['keys']) ? $raw['keys'] : array();
            $category_name = sanitize_text_field($raw['GameName'] ?? ($raw['name'] ?? $category_id));
            $fields = array();
        }

        $list_key = $service === 'gamekey' ? 'keys' : 'offers';
        if (!isset($raw[$list_key]) || !is_array($raw[$list_key])) {
            return new WP_Error('dfr_catalog_shape', 'Supplier offer list is missing or invalid; stock was preserved.');
        }
        $complete = empty($raw['meta']['has_more']) && empty($raw['meta']['next_cursor']) && empty($raw['meta']['truncated'])
            && empty($raw['has_more']) && empty($raw['next_cursor']) && empty($raw['truncated']);
        if (isset($raw['meta']['total']) && is_numeric($raw['meta']['total']) && (int) $raw['meta']['total'] > count($rows)) { $complete = false; }
        $identity_key = $service === 'gamekey' ? 'game_id' : 'category_id';
        if (isset($raw[$identity_key]) && (string) $raw[$identity_key] !== (string) $category_id) {
            return new WP_Error('dfr_catalog_identity', 'Supplier category mismatch; stock was preserved.');
        }
        $items = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { return new WP_Error('dfr_catalog_row', 'Invalid supplier offer; stock was preserved.'); }
            $id_key = $service === 'topup' ? 'offer_id' : ($service === 'giftcard' ? 'card_id' : 'key_id');
            $id = $this->sanitize_remote_id($row[$id_key] ?? '');
            if ($id === '') {
                return new WP_Error('dfr_catalog_id', 'Supplier offer has no valid ID; stock was preserved.');
            }
            // A missing, non-numeric or zero supplier price is not a cost: it is never used to
            // price a product or recorded as the supplier cost.
            $price_valid = isset($row['price_usd']) && is_numeric($row['price_usd']) && (float) $row['price_usd'] > 0;
            $price = $price_valid ? (float) $row['price_usd'] : 0.0;
            // Only a non-negative numeric quantity is authoritative. A negative sentinel means
            // « unknown », never zero, so the current WooCommerce state is preserved.
            $stock = isset($row['stock']) && is_numeric($row['stock']) && (float) $row['stock'] >= 0 ? (int) $row['stock'] : null;
            $items[] = array(
                'id' => $id,
                'name' => sanitize_text_field($row['name'] ?? $id),
                'price_usd' => number_format($price, 4, '.', ''),
                'price_valid' => $price_valid,
                'stock' => $stock,
                'available' => $this->offer_availability($row, $raw),
                'min_order_quantity' => isset($row['min_order_quantity']) ? max(1, (int) $row['min_order_quantity']) : 1,
                'max_order_quantity' => isset($row['max_order_quantity']) ? max(1, (int) $row['max_order_quantity']) : 1,
            );
        }
        return array(
            'category_name' => $category_name,
            'fields' => $fields,
            'complete' => $complete,
            'items' => $items,
        );
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
                $row['options'] = self::spec_options_map($field);
            }
            $safe[] = $row;
        }
        return $safe;
    }

    /**
     * Options of a select field as value => label, from any stored or supplier format:
     *  - a list of {value|id, label|name} objects (supplier answers, specs stored since 4.16);
     *  - a list of scalars (value = label);
     *  - a value => label map (specs stored before 4.16).
     * Reading a stored map as a list turned « os_usa => America » into « America => America »,
     * and array_slice() without preserve_keys renumbered numeric values. At most 100 options.
     *
     * Keep the result in memory only: a map whose values are exactly 0..n is indistinguishable
     * from a list. Store options with spec_for_storage().
     *
     * @return array<string|int,string>
     */
    public static function spec_options_map(array $field) {
        $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
        if (!$options) { return array(); }
        $is_list = function_exists('array_is_list') ? array_is_list($options) : array_keys($options) === range(0, count($options) - 1);
        $text = static function ($value) {
            return is_scalar($value) ? sanitize_text_field((string) $value) : '';
        };
        $map = array();
        foreach (array_slice($options, 0, 100, true) as $key => $option) {
            if ($is_list && is_array($option)) {
                $value = $text($option['value'] ?? ($option['id'] ?? ''));
                $label = $text($option['label'] ?? ($option['name'] ?? ''));
            } elseif ($is_list) {
                $value = $text($option);
                $label = $value;
            } else {
                $value = $text($key);
                $label = is_array($option) ? $text($option['label'] ?? ($option['name'] ?? '')) : $text($option);
            }
            if ($value === '') { continue; }
            $map[$value] = $label !== '' ? $label : $value;
        }
        return $map;
    }

    /**
     * Storage form of a sanitized field schema (spec version 2): the same top-level list of
     * fields, with select options as an explicit list of {value, label} objects.
     */
    private function spec_for_storage(array $fields) {
        $stored = array();
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['key'])) { continue; }
            $row = array(
                'key' => sanitize_key((string) $field['key']),
                'label' => (string) ($field['label'] ?? $field['key']),
                'type' => (string) ($field['type'] ?? 'text'),
                'required' => !empty($field['required']),
            );
            if (isset($field['options']) && is_array($field['options'])) {
                $row['options'] = array();
                // Iterate the in-memory map as it is; re-reading it would turn values 0..n into labels.
                foreach ($field['options'] as $value => $label) {
                    $row['options'][] = array('value' => (string) $value, 'label' => (string) $label);
                }
            }
            $stored[] = $row;
        }
        return $stored;
    }

    /** Stable hash of a sanitized field schema. */
    private function field_spec_hash(array $fields) {
        return hash('sha256', (string) wp_json_encode($this->spec_for_storage($fields)));
    }

    /**
     * Store a sanitized field schema as v2 JSON with its hash.
     *
     * @param string|null $hash Hash to remember (the supplier's own schema); default: of $fields.
     */
    private function store_field_spec($product_id, array $fields, $hash = null) {
        $product_id = absint($product_id);
        // update_post_meta() unslashes: without wp_slash() « \" » and « é » in the JSON broke labels.
        update_post_meta($product_id, '_dfr_field_spec_json', wp_slash((string) wp_json_encode($this->spec_for_storage($fields))));
        update_post_meta($product_id, '_dfr_field_spec_version', '2');
        update_post_meta($product_id, '_dfr_field_spec_hash', $hash !== null ? (string) $hash : $this->field_spec_hash($fields));
        unset($this->field_spec_memo[$product_id]);
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
                if (class_exists('Delicat_Custom_Packages', false) && Delicat_Custom_Packages::recipe($variation_id)) { continue; }
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
            // An existing variation keeps its attribute value whatever the title setting:
            // WooCommerce drops cart lines whose variation attributes changed. The supplier's
            // current name is kept in _dfr_supplier_item_name.
            if (isset($registry[$remote_id]) && (int) $registry[$remote_id]['variation_id'] > 0 && $registry[$remote_id]['label'] !== '') {
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

        // WooCommerce variation combinations must be unique. Only a new variation whose name
        // repeats another option shows the supplier ID in its label; existing variations keep
        // theirs, so carts holding them keep their lines.
        $existing_labels = array();
        $new_counts = array();
        foreach ($registry as $row) {
            $key = strtolower(trim((string) $row['label']));
            if ((int) $row['variation_id'] > 0) {
                $existing_labels[$key] = true;
            } else {
                $new_counts[$key] = isset($new_counts[$key]) ? $new_counts[$key] + 1 : 1;
            }
        }
        $options = array();
        foreach ($registry as $remote_id => &$row) {
            $key = strtolower(trim((string) $row['label']));
            if ((int) $row['variation_id'] <= 0 && (isset($existing_labels[$key]) || ($new_counts[$key] ?? 0) > 1)) {
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
            $this->store_field_spec($parent_id, $fields);
            $map = array();
            foreach ($fields as $field) {
                if (!empty($field['key']) && !empty($field['label'])) {
                    $map[] = sanitize_key($field['key']) . '=' . sanitize_text_field($field['label']);
                }
            }
            update_post_meta($parent_id, '_dfr_field_map', implode("\n", $map));
        } else {
            delete_post_meta($parent_id, '_dfr_field_spec_json');
            delete_post_meta($parent_id, '_dfr_field_spec_version');
            delete_post_meta($parent_id, '_dfr_field_spec_hash');
            delete_post_meta($parent_id, '_dfr_field_map');
            unset($this->field_spec_memo[(int) $parent_id]);
        }

        $variation_created = 0;
        $variation_updated = 0;
        $warnings = array();
        $variation_position = 0;
        if (!$parent_created && $previous_attribute_label !== '' && $previous_attribute_key !== $attribute_key) {
            $warnings[] = __('The variation attribute name changed: carts opened before this import lose these options.', 'delicat-fazercards');
        }

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
            $position = $variation_position++;
            if (!$variation_id) {
                // Only a new variation gets a position; the owner's order of existing ones stays.
                $variation->set_menu_order($position);
            }

            if ($row['selected'] && is_array($row['item'])) {
                $item = $row['item'];

                // Manual mode protects existing HTG prices and deliberately leaves new
                // variations unpriced until the merchant enters a selling price.
                if ($this->pricing_mode() === 'automatic') {
                    if (empty($item['price_valid'])) {
                        $warnings[] = sprintf(__('%s: the supplier returned no valid price; the WooCommerce price was left unchanged.', 'delicat-fazercards'), $row['label']);
                    } else {
                        $price = $this->calculate_store_price((float) $item['price_usd']);
                        if (is_wp_error($price)) {
                            $warnings[] = $row['label'] . ': ' . $price->get_error_message();
                            continue;
                        }
                        // Never a price of zero or below.
                        if ($price > 0) {
                            $variation->set_regular_price(wc_format_decimal($price, $this->store_price_decimals()));
                            $variation->set_price(wc_format_decimal($price, $this->store_price_decimals()));
                        }
                    }
                }

                $this->apply_supplier_availability($variation, $item, $service);
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
                if (!empty($item['price_valid'])) {
                    $this->record_supplier_cost($saved_id, (float) $item['price_usd']);
                }
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

        if ($parent_created) {
            // Supplier categories only for a new product: a re-import must neither remove the
            // owner's own categories nor re-add ones removed on purpose.
            $this->assign_product_category($parent_id, $service, $remote['category_name'], $category_id);
        }
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
        if ($this->pricing_mode() === 'automatic' && !empty($item['price_valid'])) {
            $price = $this->calculate_store_price((float) $item['price_usd']);
            if (is_wp_error($price)) {
                return $price;
            }
            // Never a price of zero or below; without a valid supplier price the price stays.
            if ($price > 0) {
                $product->set_regular_price(wc_format_decimal($price, $this->store_price_decimals()));
                $product->set_price(wc_format_decimal($price, $this->store_price_decimals()));
            }
        }

        $product->set_sold_individually($service === 'topup');
        $this->apply_supplier_availability($product, $item, $service);

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
        if (!empty($item['price_valid'])) {
            $this->record_supplier_cost($product_id, (float) $item['price_usd']);
        }
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
            $this->store_field_spec($product_id, $fields);
            $map = array();
            foreach ($fields as $field) {
                if (!empty($field['key']) && !empty($field['label'])) {
                    $map[] = sanitize_key($field['key']) . '=' . sanitize_text_field($field['label']);
                }
            }
            update_post_meta($product_id, '_dfr_field_map', implode("\n", $map));
        }

        if ($created) {
            // Supplier categories only for a new product (see upsert_variable_product()).
            $this->assign_product_category($product_id, $service, $remote['category_name'], $remote['category_id']);
        }
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
            if (!is_wp_error($root) && is_array($root)) {
                $this->tag_auto_created_term((int) $root['term_id']);
            }
        }
        if (is_wp_error($root)) {
            return;
        }
        $root_id = is_array($root) ? (int) $root['term_id'] : (is_object($root) ? (int) $root->term_id : (int) $root);
        $slug = 'delicat-' . $service . '-' . substr(md5($category_id), 0, 10);
        $child = get_term_by('slug', $slug, 'product_cat');
        if (!$child) {
            $insert = wp_insert_term(sanitize_text_field($category_name), 'product_cat', array('slug' => $slug, 'parent' => $root_id));
            if (is_wp_error($insert)) {
                return;
            }
            $child_id = (int) $insert['term_id'];
            $this->tag_auto_created_term($child_id);
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
        // The store's base currency, never the display currency: a multi-currency filter is
        // active in admin-ajax and cron, where a USD choice priced HTG products 1:1.
        $currency = strtoupper((string) get_option('woocommerce_currency', 'USD'));
        $rate = $currency === 'USD' ? 1.0 : (float) $settings['usd_to_store_rate'];
        if ($rate <= 0) {
            return new WP_Error('dfr_fx_required', sprintf(__('A USD → %s exchange rate is required.', 'delicat-fazercards'), $currency));
        }
        $markup_percent = max(0, min(1000, (float) $settings['catalog_markup_percent']));
        $fixed = max(0, (float) $settings['catalog_fixed_markup']);
        $price = ((float) $cost_usd * $rate) * (1 + ($markup_percent / 100)) + $fixed;
        $step = max(0, (float) $settings['catalog_rounding']);
        if ($step > 0) {
            // round() first: 150 / 5 is 30.000000000000004 in floating point.
            $price = ceil(round($price / $step, 6)) * $step;
        }
        return max(0, $price);
    }

    /** Only explicit boolean availability signals; unknown strings are never false. */
    private function offer_availability(array $row, array $category = array()) {
        $positive = false;
        foreach (array($category, $row) as $source) {
            foreach (array('available', 'is_available', 'enabled', 'is_active', 'in_stock') as $key) {
                if (!array_key_exists($key, $source)) { continue; }
                $value = $source[$key];
                if (in_array($value, array(false, 0, '0', 'false'), true)) { return false; }
                if (in_array($value, array(true, 1, '1', 'true'), true)) { $positive = true; }
            }
        }
        return $positive ? true : null;
    }

    private function apply_supplier_availability($product, array $item, $service) {
        if (!$product || !$this->supplier_stock_sync_enabled($product->get_id())) { return false; }
        $stock = $item['stock'] ?? null;
        $available = $item['available'] ?? null;
        // Only non-negative numeric stock is authoritative. No quantity is not zero.
        if ($stock !== null) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($available === false ? 0 : (int) $stock);
            $product->set_backorders('no');
            $product->set_stock_status($available === false || (int) $stock <= 0 ? 'outofstock' : 'instock');
        } else {
            // Unknown is not "in stock". Preserve WooCommerce state unless the
            // supplier sends an explicit availability boolean.
            if ($available === false) {
                $product->set_manage_stock(false);
                $product->set_stock_quantity(null);
                $product->set_stock_status('outofstock');
            } elseif ($available === true) {
                $product->set_manage_stock(false);
                $product->set_stock_quantity(null);
                $product->set_stock_status('instock');
            }
        }
        return true;
    }

    /** Price decimals of the store itself (the display filter may change wc_get_price_decimals()). */
    private function store_price_decimals() {
        return max(0, min(8, absint(get_option('woocommerce_price_num_decimals', 2))));
    }

    /** Mark a product category this gateway created (contract K35). */
    private function tag_auto_created_term($term_id) {
        $term_id = absint($term_id);
        if ($term_id > 0 && function_exists('update_term_meta')) {
            update_term_meta($term_id, '_dfr_auto_created', '1');
        }
    }

    /**
     * Tag, once, the categories earlier versions created: the « Delicat Digital » root and
     * its supplier children (slug delicat-<service>-<10 hex>). Term meta only, no term
     * update, so the app catalog revision does not move.
     */
    public function maybe_tag_auto_created_terms() {
        if (get_option('dfr_auto_terms_tagged_v1', '') === '1' || !taxonomy_exists('product_cat') || !function_exists('update_term_meta')) {
            return;
        }
        $roots = array();
        foreach (array(get_term_by('slug', 'delicat-digital', 'product_cat'), get_term_by('name', 'Delicat Digital', 'product_cat')) as $root) {
            if ($root && !is_wp_error($root) && (int) $root->parent === 0) { $roots[(int) $root->term_id] = true; }
        }
        foreach (array_keys($roots) as $root_id) {
            $this->tag_auto_created_term($root_id);
            $children = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $root_id, 'number' => 2000));
            if (is_wp_error($children)) { continue; }
            foreach ((array) $children as $child) {
                if (is_object($child) && preg_match('/^delicat-(topup|giftcard|gamekey)-[a-f0-9]{10}(-[0-9]+)?$/', (string) $child->slug)) {
                    $this->tag_auto_created_term((int) $child->term_id);
                }
            }
        }
        update_option('dfr_auto_terms_tagged_v1', '1', false);
    }

    /**
     * Catalog sync in bounded batches (4.16).
     *
     * A cycle visits every imported group (service + supplier category), oldest attempt
     * first: at most 5 groups per background run and 8 per manual run, within a 20 s budget;
     * the rest continues in the background (Action Scheduler, else WP-Cron). Products are
     * saved only when a property changed. The app's per-save catalog bumps are suspended
     * during the batch and one catalog_sync event follows (contract K34). A supplier error
     * leaves a group untouched (stale, not missing), an empty list never disables anything,
     * and disabling more than half of a group needs the same answer twice in a row.
     *
     * @param bool $cron  Background run: 8 s supplier timeout (a manual run keeps the default).
     * @param int  $round 0 starts or resumes a cycle; above 0 continues one.
     * @return array|WP_Error
     */
    private function sync_imported_products($cron = false, $round = 0) {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) {
            return new WP_Error('dfr_woocommerce_missing', __('WooCommerce is not active.', 'delicat-fazercards'));
        }
        if (get_transient('dfr_catalog_backoff')) { return new WP_Error('dfr_rate_limited', 'Supplier catalog refresh is backing off.'); }
        $lock = $this->acquire_db_lock('catalog_sync', 1);
        if ($lock === '') {
            return new WP_Error('dfr_sync_locked', __('A catalog sync is already running.', 'delicat-fazercards'));
        }

        $round = absint($round);
        $started = microtime(true);
        // Background batches stay short so the shared queue runner returns to paid
        // orders quickly; manual runs from Catalog Studio keep the larger budget.
        $budget = $cron ? 12.0 : 20.0;
        $timeout = $cron ? 8 : 20;
        $max_groups = $cron ? 5 : 8;
        $totals = array('groups' => 0, 'updated' => 0, 'missing' => 0, 'errors' => 0, 'cost_changes' => 0, 'pending_confirmation' => 0, 'schema_changes' => 0);
        $changed = false;
        add_filter('delicat_app_suspend_catalog_bumps', array($this, 'suspend_catalog_bumps'));
        try {
            $now = time();
            $index = $this->sync_group_index();
            $states = get_option(self::SYNC_GROUPS_OPTION, array());
            $states = is_array($states) ? array_intersect_key($states, $index) : array();
            $health = get_option(self::SYNC_HEALTH_OPTION, array());
            $health = is_array($health) ? $health : array();
            $cycle = absint($health['cycle_started'] ?? 0);

            $pending = $this->sync_pending_groups($index, $states, $cycle);
            if ($round === 0 && (!$cron || !$pending || $cycle === 0)) {
                // A manual run, the recurring event after the previous cycle ended, or the
                // very first background run: with cycle 0 every group reads as pending, so
                // without this a background sweep could never finish or restart a cycle.
                $cycle = $now;
                $pending = $this->sync_pending_groups($index, $states, $cycle);
            }

            $settings = $this->settings();
            $parents = array();
            $margin = array();
            foreach (array_keys($pending) as $key) {
                if ($totals['groups'] >= $max_groups || ($totals['groups'] > 0 && microtime(true) - $started > $budget)) {
                    break;
                }
                $group_state = isset($states[$key]) && is_array($states[$key]) ? $states[$key] : array();
                $group_result = $this->sync_group($index[$key], $settings, $timeout, $group_state);
                $states[$key] = $group_result['state'];
                unset($pending[$key]);
                $totals['groups']++;
                foreach (array('updated', 'missing', 'cost_changes', 'schema_changes') as $counter) {
                    $totals[$counter] += (int) $group_result[$counter];
                }
                if ($group_result['error'] !== '') { $totals['errors']++; }
                // A supplier Retry-After stops this batch; the next run waits it out (4.18).
                if ($group_result['error'] === 'dfr_rate_limited' && get_transient('dfr_catalog_backoff')) { break; }
                if ($group_result['pending_confirmation']) { $totals['pending_confirmation']++; }
                if ($group_result['changed']) { $changed = true; }
                foreach (array_keys($group_result['parents']) as $parent_id) { $parents[(int) $parent_id] = true; }
                foreach ($group_result['margin'] as $product_id => $entry) { $margin[(int) $product_id] = $entry; }
            }

            foreach (array_keys($parents) as $parent_id) {
                if (class_exists('WC_Product_Variable')) {
                    WC_Product_Variable::sync((int) $parent_id);
                }
                if (function_exists('wc_delete_product_transients')) {
                    wc_delete_product_transients((int) $parent_id);
                }
            }
            update_option(self::SYNC_GROUPS_OPTION, $states, false);
            $this->record_margin_alerts($margin);

            $remaining = count($pending);
            $health['cycle_started'] = $cycle;
            $health['last_run'] = $now;
            $health['groups_total'] = count($index);
            $health['remaining'] = $remaining;
            $health['errors'] = 0;
            $health['empty'] = 0;
            $health['pending_confirmation'] = 0;
            foreach ($states as $group_state) {
                if (!empty($group_state['error'])) { $health['errors']++; }
                if (!empty($group_state['empty'])) { $health['empty']++; }
                if (!empty($group_state['pending_missing'])) { $health['pending_confirmation']++; }
            }
            if ($totals['groups'] > $totals['errors']) { $health['last_success'] = $now; }
            if ($remaining === 0) { $health['cycle_finished'] = $now; }
            update_option(self::SYNC_HEALTH_OPTION, $health, false);
            // 4.17: « last sync » only moves when the batch had no supplier error, so a stale
            // catalog stays visible as stale. The health option carries the error counts.
            if ($totals['errors'] === 0) {
                update_option('dfr_catalog_last_sync', current_time('mysql', true), false);
            }
            update_option('dfr_catalog_last_batch_errors', $totals['errors'], false);

            if ($remaining > 0 && $round < self::SYNC_MAX_ROUNDS) {
                $this->schedule_sync_batch($round + 1, 5);
            }

            $mode = $this->pricing_mode();
            $result = array(
                'updated' => $totals['updated'],
                'missing' => $totals['missing'],
                'errors' => $totals['errors'],
                'cost_changes' => $totals['cost_changes'],
                'pending_confirmation' => $totals['pending_confirmation'],
                'schema_changes' => $totals['schema_changes'],
                'groups' => $totals['groups'],
                'remaining' => $remaining,
                'pricing_mode' => $mode,
                'message' => sprintf(__('%1$d products synchronized; %2$d supplier cost changes detected; %3$d missing items; %4$d category errors. WooCommerce prices %5$s.', 'delicat-fazercards'), $totals['updated'], $totals['cost_changes'], $totals['missing'], $totals['errors'], $mode === 'manual' ? __('were protected', 'delicat-fazercards') : __('follow the automatic pricing rules', 'delicat-fazercards')),
            );
            if ($remaining > 0) {
                $result['message'] .= ' ' . sprintf(_n('%d supplier category continues in the background.', '%d supplier categories continue in the background.', $remaining, 'delicat-fazercards'), $remaining);
            }
            $this->plugin()->log($totals['errors'] ? 'warning' : 'info', 'Catalog synchronization batch completed', $result);
            return $result;
        } finally {
            remove_filter('delicat_app_suspend_catalog_bumps', array($this, 'suspend_catalog_bumps'));
            $this->release_db_lock($lock);
            if ($changed && class_exists('Delicat_App_Sync_Hub')) {
                // One app catalog revision for the batch; the type must start with "catalog".
                do_action('delicat_app_content_changed', 'catalog_sync');
            }
        }
    }

    private function get_field_spec_for_product($product_id) {
        $product_id = absint($product_id);
        $settings = $this->settings();
        if (empty($settings['native_fields_enabled']) || $settings['native_fields_enabled'] !== '1') {
            return array();
        }
        if (get_post_meta($product_id, '_dfr_service_type', true) !== 'topup') {
            return array();
        }
        // Read many times per request (catalog fields, Player ID checks, cart hooks).
        if (array_key_exists($product_id, $this->field_spec_memo)) {
            return $this->field_spec_memo[$product_id];
        }
        $json = (string) get_post_meta($product_id, '_dfr_field_spec_json', true);
        $fields = json_decode($json, true);
        $spec = is_array($fields) ? $this->sanitize_field_spec($fields) : array();
        if (count($this->field_spec_memo) >= 500) { $this->field_spec_memo = array(); }
        $this->field_spec_memo[$product_id] = $spec;
        return $spec;
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

    /**
     * @param bool $allow_stale Product page display only (v4.24): when the 10-minute copy
     *                          has expired, answer with the last good list (kept a day) and
     *                          refresh it in the background, so no page view waits for the
     *                          supplier. Verification, cart and fulfilment never pass it.
     */
    private function uid_validation_games($force_refresh = false, $timeout = 20, $allow_stale = false) {
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
        if ($allow_stale && !$force_refresh) {
            $stale = get_transient('dfr_uid_validation_games_stale_v3');
            if (is_array($stale) && $stale) {
                if (!wp_next_scheduled('dfr_uid_validation_games_refresh')) {
                    wp_schedule_single_event(time(), 'dfr_uid_validation_games_refresh');
                }
                return $stale;
            }
        }
        // A failed list is not asked again for a minute (it used to be fetched twice per app
        // add, 20 s each). Verification is skipped while this lasts (fail open), so it stays
        // short. The caller's timeout keeps the storefront budget bounded.
        if (!$force_refresh && get_transient('dfr_uid_validation_games_down_v3')) {
            return array();
        }
        $result = $this->plugin()->api()->topup_validation_games($timeout);
        if (is_wp_error($result) || empty($result['ok']) || empty($result['items']) || !is_array($result['items'])) {
            set_transient('dfr_uid_validation_games_down_v3', 1, MINUTE_IN_SECONDS);
            return array();
        }
        delete_transient('dfr_uid_validation_games_down_v3');
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
        if ($items) { set_transient('dfr_uid_validation_games_stale_v3', $items, DAY_IN_SECONDS); }
        return $items;
    }

    /** Background refresh queued by a product page that displayed the last good list. */
    public function refresh_uid_validation_games() {
        $this->uid_validation_games(false, 10);
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

    /**
     * Extract canonical region markers from a supplier label/id (e.g. "Free Fire
     * LATAM", "free_fire_br"). FazerCards advertises many regional Free Fire
     * families; the validation namespace can likewise contain several same-game
     * entries. Region markers let the resolver prefer the entry that matches the
     * product's storefront region and otherwise fall back to the global entry.
     */
    private function uid_region_tokens($name, $category_id = '') {
        $value = strtolower(remove_accents(wp_strip_all_tags((string) $name . ' ' . (string) $category_id)));
        $tokens = preg_split('/[^a-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        $regions = array(
            'brazil' => array('brazil', 'brasil', 'br'),
            'latam' => array('latam', 'latin', 'sac', 'mexico', 'mx', 'argentina', 'chile', 'colombia', 'peru', 'caribbean'),
            'na' => array('na', 'usa', 'us', 'canada'),
            'europe' => array('europe', 'eu', 'uk'),
            'india' => array('india', 'ind'),
            'indonesia' => array('indonesia', 'idn'),
            'sea' => array('sea', 'malaysia', 'my', 'philippines', 'ph', 'thailand', 'th', 'vietnam', 'vn', 'singapore', 'sg'),
            'mena' => array('mena', 'uae', 'saudi', 'arabia', 'egypt', 'morocco', 'algeria', 'tunisia'),
            'cis' => array('cis', 'russia', 'ru', 'kazakhstan', 'kz'),
            'turkey' => array('turkey', 'tr'),
            'pakistan' => array('pakistan', 'pk'),
            'bangladesh' => array('bangladesh', 'bd'),
            'taiwan' => array('taiwan', 'tw'),
            'global' => array('global', 'worldwide', 'world', 'int', 'international'),
        );
        $found = array();
        foreach ((array) $tokens as $token) {
            foreach ($regions as $canonical => $aliases) {
                if (in_array($token, $aliases, true)) { $found[$canonical] = true; }
            }
        }
        return array_keys($found);
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
    private function uid_validation_context($product_id, $force_refresh = false, $timeout = 20, $allow_stale = false) {
        $product_id = absint($product_id);
        // Once per product per request: an app add resolved it twice (validation, then cart
        // data). A forced refresh (schema recovery) always resolves again and replaces the memo.
        // Only a resolved context is memoized, so a caller with a longer budget can still
        // succeed after a short-timeout caller came back empty. A context built from the
        // stale display list is never memoized for the checks that follow.
        if (!$force_refresh && array_key_exists($product_id, $this->uid_context_memo)) {
            return $this->uid_context_memo[$product_id];
        }
        $context = $this->resolve_uid_validation_context($product_id, $force_refresh, $timeout, $allow_stale);
        if (is_array($context) && !$allow_stale) { $this->uid_context_memo[$product_id] = $context; }
        return $context;
    }

    private function resolve_uid_validation_context($product_id, $force_refresh = false, $timeout = 20, $allow_stale = false) {
        if (!$this->uid_validation_enabled()) { return false; }
        $product_id = absint($product_id);
        if (!$product_id || get_post_meta($product_id, '_dfr_service_type', true) !== 'topup') { return false; }

        $order_category_id = substr(sanitize_text_field((string) get_post_meta($product_id, '_dfr_category_id', true)), 0, 190);
        if ($order_category_id === '') { return false; }
        $games = $this->uid_validation_games($force_refresh, $timeout, $allow_stale);
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

        $product_regions = $this->uid_region_tokens($supplier_name, $order_category_id);
        $product_specific = array_values(array_diff($product_regions, array('global')));
        $product_family = $this->uid_game_family_key($supplier_name, $order_category_id);
        $family_slug = preg_replace('/[^a-z0-9]+/', '_', $product_family);

        $scored = array();
        foreach ($games as $validation_id => $game) {
            if (!is_array($game)) { continue; }
            $field_map = $this->uid_validation_field_map($product_id, $game);
            if (!is_array($field_map)) { continue; }
            $score = $this->uid_match_score($supplier_name, $order_category_id, $game);
            if ($score < 80) { continue; }
            // Same-family candidates (score 100) are ranked by region affinity:
            // 0 = the candidate names the product's own region, 1 = region-neutral
            // (global) entry, 2 = a different region. A different-region validator
            // answers "player not found" for a perfectly valid ID.
            $candidate_regions = array_values(array_diff($this->uid_region_tokens($game['name'] ?? '', (string) $validation_id), array('global')));
            if ($candidate_regions && $product_specific && array_intersect($candidate_regions, $product_specific)) {
                $tier = 0;
            } elseif (!$candidate_regions) {
                $tier = 1;
            } else {
                $tier = 2;
            }
            $scored[$validation_id] = array(
                'score' => $score,
                'tier' => $tier,
                'canonical' => ($family_slug !== '' && strtolower((string) $validation_id) === $family_slug) ? 0 : 1,
                'game' => $game,
                'field_map' => $field_map,
            );
        }
        if (!$scored) { return false; }
        // Total ordering: PHP < 8.0 uasort() is not stable, so every tie must be
        // broken explicitly to keep the storefront, cart revalidation and the admin
        // diagnostic on the same validation category.
        uasort($scored, static function ($a, $b) {
            if ((int) $a['score'] !== (int) $b['score']) { return (int) $b['score'] <=> (int) $a['score']; }
            if ((int) $a['tier'] !== (int) $b['tier']) { return (int) $a['tier'] <=> (int) $b['tier']; }
            if ((int) $a['canonical'] !== (int) $b['canonical']) { return (int) $a['canonical'] <=> (int) $b['canonical']; }
            $ai = (string) $a['game']['category_id'];
            $bi = (string) $b['game']['category_id'];
            if (strlen($ai) !== strlen($bi)) { return strlen($ai) <=> strlen($bi); }
            return strcmp($ai, $bi);
        });
        $ids = array_keys($scored);
        $best_id = (string) $ids[0];
        $best = $scored[$best_id];
        $second_score = isset($ids[1]) ? (int) $scored[$ids[1]]['score'] : 0;
        // Require a unique, high-confidence winner. Family matches score 100;
        // fuzzy matches need a clear margin to avoid validating the wrong game.
        if ((int) $best['score'] < 88 || ((int) $best['score'] < 100 && ((int) $best['score'] - $second_score) < 12)) {
            return false;
        }
        // A same-family candidate that only exists for a *different* region is not
        // a safe validator when a region-neutral or region-matched one is absent
        // and the product itself is pinned to a region.
        if ((int) $best['score'] === 100 && (int) $best['tier'] === 2 && $product_specific) {
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
            return array('error_code' => '', 'http' => 0, 'provider_code' => '', 'provider_hint' => '');
        }
        $data = $error->get_error_data();
        return array(
            'error_code' => sanitize_key((string) $error->get_error_code()),
            'http' => is_array($data) ? absint($data['status'] ?? 0) : 0,
            'provider_code' => is_array($data) ? sanitize_key((string) ($data['provider_code'] ?? '')) : '',
            'provider_hint' => is_array($data) ? sanitize_key((string) ($data['provider_hint'] ?? '')) : '',
        );
    }

    /**
     * Reduce a failed POST /topups/validate-id to one documented outcome.
     *
     * FazerCards' contract: successful bodies carry ok:true; errors carry
     * ok:false + error (+ code). HTTP 400 is "validation or business rule; body
     * explains the problem" (for this route: the ID/account was not accepted),
     * 401/403 are key/plan access, 404 is an unknown resource id (stale
     * validation category), 409 conflict, 422 unprocessable, 429 throttling and
     * 5xx/transport are "retry with care".
     *
     * Outcomes:
     *  - rate_limited      supplier throttled this key (429)
     *  - player_rejected   supplier answered and did not accept this Player ID
     *  - schema_rejected   supplier rejected the category/field schema we sent
     *  - config            API key/plan cannot use the validation route
     *  - unavailable       transport failure, timeout, 5xx or unclassifiable error
     */
    private function uid_validation_outcome($error) {
        if (!is_wp_error($error)) { return ''; }
        $meta = $this->uid_validation_error_meta($error);
        $http = (int) $meta['http'];
        $provider = strtolower((string) $meta['provider_code']);
        $hint = strtolower((string) $meta['provider_hint']);
        $error_code = strtolower((string) $meta['error_code']);
        $message = strtolower((string) $error->get_error_message());

        if ($http === 429 || $error_code === 'dfr_rate_limited' || $hint === 'throttle') { return 'rate_limited'; }
        if (in_array($http, array(401, 403), true) || $hint === 'auth' || $error_code === 'dfr_missing_api_key') { return 'config'; }
        // Local/transport failures carry no supplier verdict at all.
        if (in_array($error_code, array('dfr_invalid_json', 'dfr_invalid_response_size', 'dfr_json_encode_failed', 'http_request_failed'), true)) { return 'unavailable'; }
        if (in_array($error_code, array('dfr_invalid_remote_id', 'dfr_invalid_path', 'dfr_missing_validation_fields', 'dfr_invalid_field_value', 'dfr_field_too_long'), true)) { return 'schema_rejected'; }

        if (Delicat_Player_Region_V2::is_region_error($provider) || Delicat_Player_Region_V2::is_region_error($error_code)) { return 'region_mismatch'; }

        $provider_says_player = $hint === 'player'
            || strpos($provider, 'invalid_uid') !== false || strpos($provider, 'invalid_player') !== false
            || strpos($provider, 'player_not_found') !== false || strpos($provider, 'invalid_id') !== false
            || strpos($provider, 'user_not_found') !== false || strpos($provider, 'uid_not_found') !== false
            || strpos($provider, 'account_not_found') !== false || strpos($provider, 'invalid_account') !== false
            || strpos($provider, 'wrong_region') !== false || strpos($provider, 'invalid_region') !== false
            // A bare not_found on HTTP 404 is the documented "unknown resource id"
            // (our category); on 400/2xx it is the supplier's answer about the player.
            || (strpos($provider, 'not_found') !== false && $http !== 404 && $hint !== 'schema');
        $provider_says_schema = $hint === 'schema'
            || strpos($provider, 'category') !== false || strpos($provider, 'field') !== false
            || strpos($provider, 'schema') !== false || strpos($provider, 'unsupported') !== false;

        if ($provider_says_schema && !$provider_says_player) { return 'schema_rejected'; }
        if ($provider_says_player) { return 'player_rejected'; }

        // Transport/timeout/5xx first: a WP_Error with no HTTP status is a network failure.
        if (strpos($message, 'timed out') !== false || strpos($message, 'timeout') !== false) { return 'unavailable'; }
        if ($http === 0 || in_array($http, array(408, 425, 500, 502, 503, 504), true) || $error_code === 'http_request_failed') { return 'unavailable'; }
        if ($hint === 'upstream') { return 'unavailable'; }

        // Documented: 400 = validation/business rule. The supplier processed the
        // request and refused the input; for validate-id that is the ID itself.
        if ($http === 400) { return 'player_rejected'; }
        // 404 = unknown resource id / unsupported route; 409/422 = unprocessable
        // input: the validation category or field keys we hold are stale.
        if (in_array($http, array(404, 409, 422), true)) { return 'schema_rejected'; }
        // A 2xx body with ok:false and no recognizable reason is still the
        // supplier's definitive answer for this input, not an outage.
        if ($http >= 200 && $http < 300) { return 'player_rejected'; }
        return 'unavailable';
    }

    /**
     * Classify whether a read-only Player ID validation can be retried once.
     * Authentication/plan errors, supplier throttling and a definitive
     * Player ID rejection are never amplified: retrying them only burns the
     * shared 120/min supplier quota and doubles storefront latency.
     */
    private function uid_validation_retry_mode($error) {
        if (!is_wp_error($error)) { return ''; }
        $outcome = $this->uid_validation_outcome($error);
        if ($outcome === 'schema_rejected') {
            // The validation list is documented as dynamic. A cached category or
            // field schema can become stale while the storefront is still open.
            return 'schema';
        }
        if ($outcome !== 'unavailable') { return ''; }
        $message = strtolower((string) $error->get_error_message());
        // Do not immediately repeat a full timeout; it would only double UI latency.
        if (strpos($message, 'timed out') !== false || strpos($message, 'timeout') !== false) { return ''; }
        return 'transient';
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

        switch ($this->uid_validation_outcome($error)) {
            case 'config':
                $classification = 'credentials_or_plan';
                $message = __('The FazerCards API is reachable, but this API key/account is not authorized for the requested validation service (HTTP 401/403: key or plan/product not enabled).', 'delicat-fazercards');
                break;
            case 'rate_limited':
                $classification = 'rate_limited';
                $message = __('The FazerCards Player ID service is reachable but is currently rate-limiting requests.', 'delicat-fazercards');
                $tone = 'warning';
                break;
            case 'player_rejected':
                $classification = 'player_rejected';
                $message = __('The FazerCards Player ID server responded normally, but it did not accept this Player ID (wrong ID, or the account belongs to another region than the selected validation category).', 'delicat-fazercards');
                $tone = 'warning';
                break;
            case 'schema_rejected':
                $classification = 'schema_rejected';
                $message = __('The validation server is reachable, but the current Free Fire validation category/field schema was rejected.', 'delicat-fazercards');
                $tone = 'warning';
                break;
            case 'unavailable':
                $classification = 'upstream_unavailable';
                $message = __('The FazerCards Player ID service or its upstream Free Fire validation provider is currently unavailable or not responding reliably.', 'delicat-fazercards');
                break;
        }

        return array(
            'classification' => $classification,
            'message' => $message,
            'tone' => $tone,
            'http' => max(0, min(599, $http)),
            'provider_code' => substr(sanitize_key($provider), 0, 80),
            'provider_hint' => substr(sanitize_key((string) ($meta['provider_hint'] ?? '')), 0, 40),
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

        // Storefront mapping: show which validation category each imported Free Fire
        // product actually resolves to. A product that resolves to a different
        // regional validator than the one tested here explains a healthy diagnostic
        // next to a failing product page. Uses the cached validation list only.
        $mapping_lines = array();
        $mapping_mismatch = false;
        $product_ids = get_posts(array(
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 200,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array(array('key' => '_dfr_service_type', 'value' => 'topup')),
        ));
        foreach ((array) $product_ids as $pid) {
            $pid = absint($pid);
            if (!$pid) { continue; }
            $supplier_name = (string) get_post_meta($pid, '_dfr_supplier_category_name', true);
            if ($supplier_name === '') { $supplier_name = (string) get_the_title($pid); }
            $order_cid = (string) get_post_meta($pid, '_dfr_category_id', true);
            if ($this->uid_game_family_key($supplier_name, $order_cid) !== 'free fire') { continue; }
            $ctx = $this->uid_validation_context($pid, false, 8);
            if (is_array($ctx)) {
                $resolved = (string) $ctx['validation_category_id'];
                if (strtolower($resolved) !== strtolower((string) $game['category_id'])) { $mapping_mismatch = true; }
                $mapping_lines[] = sprintf('#%d %s → %s (%s)', $pid, substr(sanitize_text_field(get_the_title($pid)), 0, 60), $resolved, sanitize_key((string) ($ctx['match'] ?? '')));
            } else {
                $mapping_mismatch = true;
                $mapping_lines[] = sprintf('#%d %s → %s', $pid, substr(sanitize_text_field(get_the_title($pid)), 0, 60), __('no validation category resolved (verification button hidden)', 'delicat-fazercards'));
            }
            if (count($mapping_lines) >= 25) { break; }
        }
        $report['steps'][] = array(
            'label' => __('Storefront mapping', 'delicat-fazercards'),
            'status' => $mapping_lines ? ($mapping_mismatch ? 'warning' : 'pass') : 'warning',
            'detail' => $mapping_lines
                ? implode('; ', $mapping_lines)
                : __('No imported Free Fire top-up product was found in WooCommerce.', 'delicat-fazercards'),
        );

        $result = $api->validate_topup_id($game['category_id'], array($player_field => $player_id), 20);
        if (is_wp_error($result) && $this->uid_validation_retry_mode($result) === 'transient') {
            $report['retried'] = true;
            $report['steps'][] = array(
                'label' => __('First Player ID request', 'delicat-fazercards'),
                'status' => 'warning',
                'detail' => __('The first read-only validation attempt had a transient supplier/network failure; one safe retry was attempted.', 'delicat-fazercards'),
            );
            $result = $api->validate_topup_id($game['category_id'], array($player_field => $player_id), 20);
        }

        if (!is_wp_error($result) && is_array($result) && !empty($result['ok']) && empty($result['valid'])) {
            // Documented success shape: {ok:true, category_id, valid, player_name, region?}.
            // valid:false is a normal, definitive supplier answer — the server is healthy.
            $report['overall'] = 'warning';
            $report['classification'] = 'player_rejected';
            $report['headline'] = __('FazerCards Player ID server is online.', 'delicat-fazercards');
            $report['message'] = __('The validation request completed (ok:true) but FazerCards answered valid:false for the entered Player ID. The supplier validator is healthy; the ID is wrong or belongs to a different region than the tested validation category.', 'delicat-fazercards');
            $report['steps'][] = array(
                'label' => __('Player ID validation', 'delicat-fazercards'),
                'status' => 'warning',
                'detail' => __('POST /topups/validate-id responded ok:true, valid:false. No order was created and no balance was debited.', 'delicat-fazercards'),
            );
            return $report;
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
    private function uid_region_notice($error) {
        $data = is_wp_error($error) ? $error->get_error_data() : array();
        return Delicat_Player_Region_V2::message(is_array($data) ? $data : array());
    }

    private function uid_check_response_region($product_id, array $fields, $result) {
        if (is_wp_error($result) || !is_array($result)) { return $result; }
        foreach (array('code','error_code','error') as $key) {
            if (isset($result[$key]) && is_scalar($result[$key]) && Delicat_Player_Region_V2::is_region_error($result[$key])) {
                return new WP_Error('dfr_region_mismatch', Delicat_Player_Region_V2::message(array('account_region'=>Delicat_Player_Region_V2::error_region($result))), array('status'=>422,'provider_code'=>'region_mismatch','provider_hint'=>'player','account_region'=>Delicat_Player_Region_V2::error_region($result)));
            }
        }
        $context = (string) get_post_meta($product_id, '_dfr_supplier_category_name', true) . ' ' . get_the_title($product_id);
        if (Delicat_Player_Region_V2::mismatch($context, $fields, $result)) {
            return new WP_Error('dfr_region_mismatch', Delicat_Player_Region_V2::message($result), array('status'=>422,'provider_code'=>'region_mismatch','provider_hint'=>'player','account_region'=>Delicat_Player_Region_V2::account_region($result)));
        }
        return $result;
    }

    private function uid_validate_product_fields_with_recovery($product_id, array $product_fields) {
        // Whole-operation budget. The product page aborts its fetch at
        // self::UID_BROWSER_ABORT_MS, so every supplier call (and the optional
        // one-shot recovery) must fit inside this window or its answer is lost and
        // the customer simply clicks again — which multiplies supplier traffic.
        $budget = self::UID_INTERACTIVE_BUDGET_SECONDS;
        $started = microtime(true);
        $remaining = static function () use ($budget, $started) {
            return $budget - (microtime(true) - $started);
        };

        $context = $this->uid_validation_context($product_id, false, 8);
        if (!is_array($context) || empty($context['validation_category_id']) || empty($context['game'])) {
            return new WP_Error('dfr_uid_context_unavailable', __('Player verification is not available for this product.', 'delicat-fazercards'), array('status' => 400));
        }

        $validation_fields = $this->uid_map_product_values_to_validation($product_id, $context['game'], $product_fields);
        if (is_wp_error($validation_fields) || !$validation_fields) {
            return new WP_Error('dfr_uid_field_map', __('Player verification information is missing or incompatible.', 'delicat-fazercards'), array('status' => 422));
        }

        $first_timeout = (int) max(4, min(self::UID_INTERACTIVE_ATTEMPT_TIMEOUT, floor($remaining())));
        $result = $this->plugin()->api()->validate_topup_id((string) $context['validation_category_id'], $validation_fields, $first_timeout);
        $result = $this->uid_check_response_region($product_id, $product_fields, $result);
        if (!is_wp_error($result)) {
            return array('context' => $context, 'validation_fields' => $validation_fields, 'result' => $result, 'recovery' => '');
        }

        $first = $result;
        $mode = $this->uid_validation_retry_mode($first);
        if ($mode === '' || $this->uid_validation_outcome($first) === 'region_mismatch') { return $first; }

        // A second attempt only makes sense when it can still complete before the
        // browser gives up. Fast failures (connection refused, immediate 5xx, stale
        // schema) leave plenty of budget; a slow failure consumed it.
        if ($remaining() < self::UID_INTERACTIVE_MIN_RETRY_SECONDS) { return $first; }

        if ($mode === 'schema') {
            $refresh_timeout = (int) max(3, min(8, floor($remaining() - 4)));
            $fresh_context = $this->uid_validation_context($product_id, true, $refresh_timeout);
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
            if ($remaining() < 4) { return $first; }
        }

        $retry_timeout = (int) max(4, min(self::UID_INTERACTIVE_ATTEMPT_TIMEOUT, floor($remaining())));
        $retry = $this->plugin()->api()->validate_topup_id((string) $context['validation_category_id'], $validation_fields, $retry_timeout);
        $retry = $this->uid_check_response_region($product_id, $product_fields, $retry);
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
     *
     * 4.16: a « not found » answer is remembered one minute for the same product and fields;
     * every check counts against one site-wide budget (uid_global_rate_limit_ok()).
     *
     * @param string $caller '' or 'reseller' (reseller API), 'app_verify' or 'app_add'. Only
     *                       an app add reuses the same customer's successful check of the last
     *                       10 minutes; the fulfilment re-check never goes through here.
     */
    public function validate_reseller_topup_uid($product_id, array $fields, $force = false, $caller = '') {
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

        // Every caller of this method is inside a request the customer is waiting on (app verify,
        // app add to cart, reseller API), so the validation list gets the same short interactive
        // budget the storefront uses, never the 20 s background one.
        $context = $this->uid_validation_context($product_id, false, 8);
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

        $caller = sanitize_key((string) $caller);
        $user_id = get_current_user_id();
        $result_key = $this->uid_result_cache_key($product_id, (string) $context['validation_category_id'], $validation_fields);
        // The supplier reported these exact fields as not found less than a minute ago.
        if (get_transient('dfr_uid_neg_' . $result_key)) {
            return $this->uid_invalid_error();
        }
        if ($caller === 'app_add' && $user_id > 0) {
            // The app checked this account moments ago (verify route): no second supplier call.
            $recent = get_transient('dfr_uid_ok_' . $this->uid_user_cache_key($user_id, $result_key));
            if (is_array($recent)) {
                return array(
                    'supported' => true,
                    'valid' => true,
                    'validation_category_id' => substr(sanitize_text_field((string) $context['validation_category_id']), 0, 190),
                    'player_name' => $this->uid_safe_player_name($recent['player_name'] ?? ''),
                    'region' => substr(sanitize_text_field((string) ($recent['region'] ?? '')), 0, 80),
                );
            }
            // POST /cart can be repeated with new Player IDs: the verify route's per-customer budget.
            if (class_exists('Delicat_App_Helpers') && is_callable(array('Delicat_App_Helpers', 'rate_limit'))
                && !Delicat_App_Helpers::rate_limit('dfr_uid_verify:' . $user_id, 20, MINUTE_IN_SECONDS)) {
                return new WP_Error('dapi_uid_throttled', __('Too many Player ID checks. Retry in a minute.', 'delicat-fazercards'), array('status' => 429));
            }
        }
        // One site-wide budget for every interactive check (website, app, reseller API), kept
        // under the supplier quota so the re-check of paid orders still gets through.
        if (!$this->uid_global_rate_limit_ok()) {
            return new WP_Error('dapi_uid_upstream', __('Player ID verification is temporarily unavailable. No wallet debit was attempted.', 'delicat-fazercards'), array('status' => 429));
        }

        $attempt = $this->uid_validate_product_fields_with_recovery($product_id, $fields);
        if (is_wp_error($attempt)) {
            $meta = $this->uid_validation_error_meta($attempt);
            $http = (int) $meta['http'];
            $outcome = $this->uid_validation_outcome($attempt);
            $this->plugin()->log($outcome === 'config' ? 'error' : 'warning', 'Reseller API Player ID validation request failed before wallet debit', array(
                'validation_category_id' => (string) $context['validation_category_id'],
                'outcome' => $outcome,
                'error' => $meta['error_code'],
                'provider_code' => $meta['provider_code'],
                'provider_hint' => $meta['provider_hint'],
                'http' => $http,
            ));
            switch ($outcome) {
                case 'region_mismatch':
                    return new WP_Error('dapi_uid_region_mismatch', $this->uid_region_notice($attempt), array('status'=>422,'account_region'=>(is_array($attempt->get_error_data()) ? ($attempt->get_error_data()['account_region'] ?? '') : '')));
                case 'player_rejected':
                    return new WP_Error('dapi_uid_invalid', __('Player ID could not be verified. Check the player/server information and try again.', 'delicat-fazercards'), array('status' => 422));
                case 'rate_limited':
                    return new WP_Error('dapi_uid_upstream', __('Player ID verification is temporarily unavailable. No wallet debit was attempted.', 'delicat-fazercards'), array('status' => 429));
                case 'schema_rejected':
                    return new WP_Error('dapi_uid_unsupported', __('Automatic Player ID verification is not available for this product right now. No wallet debit was attempted.', 'delicat-fazercards'), array('status' => 503));
                default:
                    return new WP_Error('dapi_uid_upstream', __('Player ID verification is temporarily unavailable. No wallet debit was attempted.', 'delicat-fazercards'), array('status' => 503));
            }
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
            // Remembered one minute: repeated adds of an unknown account stay local.
            set_transient('dfr_uid_neg_' . $result_key, 1, MINUTE_IN_SECONDS);
            $final_key = $this->uid_result_cache_key($product_id, (string) $context['validation_category_id'], $validation_fields);
            if ($final_key !== $result_key) {
                set_transient('dfr_uid_neg_' . $final_key, 1, MINUTE_IN_SECONDS);
            }
            return $this->uid_invalid_error();
        }

        $verified = array(
            'supported' => true,
            'valid' => true,
            'validation_category_id' => substr(sanitize_text_field((string) $context['validation_category_id']), 0, 190),
            'player_name' => isset($result['player_name']) ? $this->uid_safe_player_name($result['player_name']) : '',
            'region' => isset($result['region']) ? substr(sanitize_text_field((string) $result['region']), 0, 80) : '',
        );
        if ($user_id > 0) {
            // Signed-in customers only (the app). No Player ID is stored: the name is a keyed hash.
            set_transient('dfr_uid_ok_' . $this->uid_user_cache_key($user_id, $result_key), array(
                'player_name' => $verified['player_name'],
                'region' => $verified['region'],
            ), 10 * MINUTE_IN_SECONDS);
        }
        return $verified;
    }

    private function uid_invalid_error() {
        return new WP_Error('dapi_uid_invalid', __('Player ID could not be verified. Check the player/server information and try again.', 'delicat-fazercards'), array('status' => 422));
    }

    /** Keyed hash of product + validation category + fields: transient names are readable and Player IDs short. */
    private function uid_result_cache_key($product_id, $validation_category_id, array $validation_fields) {
        ksort($validation_fields);
        return substr(hash_hmac('sha256', 'identity-v3|' . absint($product_id) . '|' . (string) $validation_category_id . '|' . wp_json_encode($validation_fields), wp_salt('nonce')), 0, 40);
    }

    private function uid_user_cache_key($user_id, $result_key) {
        return substr(hash_hmac('sha256', absint($user_id) . '|' . (string) $result_key, wp_salt('nonce')), 0, 40);
    }

    /** Whether the supplier reported these account fields as not found in the last minute. No supplier call. */
    private function uid_recent_negative($product_id, array $fields) {
        $context = $this->uid_validation_context($product_id);
        if (!is_array($context) || empty($context['game']) || empty($context['validation_category_id'])) { return false; }
        $validation_fields = $this->uid_map_product_values_to_validation($product_id, $context['game'], $fields);
        if (is_wp_error($validation_fields) || !$validation_fields) { return false; }
        return (bool) get_transient('dfr_uid_neg_' . $this->uid_result_cache_key($product_id, (string) $context['validation_category_id'], $validation_fields));
    }

    private function uid_session_binding() {
        // 4.16: no IP address or browser string. A phone moving between Wi-Fi and mobile
        // data, a carrier NAT or a CDN changed them between « Vérifier » and « Ajouter au
        // panier ». Signed-in customers are bound to their login session, guests to their
        // WooCommerce session (ajax_verify_player_uid() makes sure its cookie exists).
        $parts = array();
        if (is_user_logged_in()) {
            $parts[] = 'u:' . get_current_user_id();
            $token = function_exists('wp_get_session_token') ? (string) wp_get_session_token() : '';
            if ($token !== '') { $parts[] = 't:' . hash('sha256', $token); }
        } elseif (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
            $cid = (string) WC()->session->get_customer_id();
            if ($cid !== '') { $parts[] = 'wc:' . $cid; }
        }
        if (!$parts) {
            $parts[] = 'net:' . hash_hmac('sha256', $this->uid_client_ip(), wp_salt('nonce'));
        }
        return hash_hmac('sha256', implode('|', $parts), wp_salt('auth'));
    }

    /** Client address; proxy headers only when the App API is configured to trust them. */
    private function uid_client_ip() {
        if (class_exists('Delicat_App_Helpers') && is_callable(array('Delicat_App_Helpers', 'client_ip'))) {
            return (string) Delicat_App_Helpers::client_ip();
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? filter_var(wp_unslash($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP) : false;
        return $ip ? (string) $ip : '0.0.0.0';
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

    /**
     * Per-visitor bucket: the account when signed in, else the client address. Never the
     * browser string, which a script rotates at will.
     */
    private function uid_rate_limit_key() {
        $subject = is_user_logged_in() ? 'u:' . get_current_user_id() : 'ip:' . $this->uid_client_ip();
        return 'dfr_uid_rl_' . substr(hash_hmac('sha256', $subject, wp_salt('nonce')), 0, 32);
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
            // Guests share an address behind a carrier NAT: a little more room for them.
            if ((int) ($row['count'] ?? 0) >= (is_user_logged_in() ? 20 : 30)) { return false; }
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
        // 4.16: shared by the website, the app (verify and add) and the reseller API; the
        // fulfilment re-check of paid orders is not counted and keeps the remaining quota.
        $limit = max(10, (int) apply_filters('dfr_uid_validation_budget_per_minute', 60));
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
            if ($count >= $limit) { return false; }
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

    private function uid_safe_player_name($value) {
        $name = sanitize_text_field((string) $value);
        // Bound by Unicode characters, never by bytes in the middle of a glyph.
        return preg_match('/\A.{0,120}/us', $name, $match) ? $match[0] : '';
    }

    private function uid_token_encode($product_id, $order_category_id, $validation_category_id, array $fields, $player_name = '', $region = '') {
        $payload = array(
            'v' => 5,
            'pid' => absint($product_id),
            'cid' => substr((string) $order_category_id, 0, 190),
            'vcid' => substr((string) $validation_category_id, 0, 190),
            'fh' => $this->uid_fields_hash($fields),
            'sid' => $this->uid_session_binding(),
            'name' => $this->uid_safe_player_name($player_name),
            'region' => substr(sanitize_text_field((string) $region), 0, 80),
            'iat' => time(),
            'exp' => time() + 10 * MINUTE_IN_SECONDS,
        );
        $body = rtrim(strtr(base64_encode(wp_json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
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
        if (!is_array($data) || (int) ($data['v'] ?? 0) !== 5) { return false; }
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

    /**
     * Fresh Player ID verification nonce (v4.24). A product page served from the LiteSpeed
     * cache can be days old while a nonce lives 12-24 h, so « Vérifier » kept answering
     * « session expired » and reloading served the same cached page. The storefront script
     * asks here once after a nonce refusal. It grants nothing a visitor cannot already get
     * by loading the page: same-origin only, never cached, and verification keeps its own
     * per-visitor and site-wide limits.
     */
    public function ajax_uid_nonce() {
        if (function_exists('nocache_headers') && !headers_sent()) { nocache_headers(); }
        if (!headers_sent()) { header('X-LiteSpeed-Cache-Control: no-cache, no-store'); }
        if (!$this->uid_validation_enabled() || !$this->uid_same_origin_request()) {
            wp_send_json_error(array('code' => 'forbidden'), 403);
        }
        wp_send_json_success(array('nonce' => wp_create_nonce('dfr_uid_verify')));
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
            $outcome = $this->uid_validation_outcome($attempt);
            $this->plugin()->log($outcome === 'config' ? 'error' : 'warning', 'Player ID validation request failed', array(
                'order_category_id' => $category_id,
                'validation_category_id' => $validation_category_id,
                'match' => isset($context['match']) ? $context['match'] : '',
                'outcome' => $outcome,
                'error' => $meta['error_code'],
                'provider_code' => $meta['provider_code'],
                'provider_hint' => $meta['provider_hint'],
                'http' => (int) $meta['http'],
            ));
            // Do not expose supplier credentials/plan details to unauthenticated visitors.
            // Rate limiting is safe to distinguish because it helps the customer recover.
            switch ($outcome) {
                case 'region_mismatch':
                    wp_send_json_error(array('message'=>$this->uid_region_notice($attempt), 'code'=>'region_mismatch'), 422);
                    break;
                case 'rate_limited':
                    wp_send_json_error(array(
                        'message' => __('Le service de vérification est très sollicité. Attendez une minute puis réessayez.', 'delicat-fazercards'),
                        'code' => 'upstream_rate_limit'
                    ), 429);
                    break;
                case 'player_rejected':
                    // FazerCards processed the request and did not accept this ID
                    // (documented HTTP 400 / ok:false). Retrying will not change the answer.
                    wp_send_json_error(array(
                        'message' => __('Player ID introuvable ou non reconnu. Vérifiez l’identifiant et la région du compte exactement comme dans le jeu, puis réessayez.', 'delicat-fazercards'),
                        'code' => 'invalid_uid'
                    ), 400);
                    break;
                case 'schema_rejected':
                    wp_send_json_error(array(
                        'message' => __('La vérification automatique n’est pas disponible pour ce produit pour le moment. Si le problème persiste, contactez le support Delicat.', 'delicat-fazercards'),
                        'code' => 'unsupported_schema'
                    ), 400);
                    break;
                case 'config':
                    wp_send_json_error(array(
                        'message' => __('Le service de vérification est temporairement indisponible pour ce produit. Contactez le support Delicat si le problème persiste.', 'delicat-fazercards'),
                        'code' => 'unavailable_config'
                    ), 503);
                    break;
                default:
                    wp_send_json_error(array(
                        'message' => __('Impossible de vérifier ce Player ID pour le moment. Réessayez dans quelques instants.', 'delicat-fazercards'),
                        'code' => 'upstream'
                    ), 502);
            }
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
            // Documented success shape {ok:true, valid:false}: a definitive supplier answer.
            $this->plugin()->log('info', 'Player ID validation answered valid:false', array(
                'validation_category_id' => $validation_category_id,
                'match' => isset($context['match']) ? $context['match'] : '',
            ));
            wp_send_json_error(array('message' => __('Player ID introuvable ou non reconnu. Vérifiez l’identifiant et la région du compte exactement comme dans le jeu, puis réessayez.', 'delicat-fazercards'), 'code' => 'invalid_uid'), 400);
        }
        $player_name = isset($result['player_name']) ? sanitize_text_field((string) $result['player_name']) : '';
        $region = isset($result['region']) ? sanitize_text_field((string) $result['region']) : '';
        // A guest's WooCommerce customer id is random until its session cookie exists: set it
        // now, so « Ajouter au panier » presents the session this token is bound to.
        if (!is_user_logged_in() && function_exists('WC') && WC() && isset(WC()->session) && WC()->session
            && is_callable(array(WC()->session, 'has_session')) && !WC()->session->has_session()
            && is_callable(array(WC()->session, 'set_customer_session_cookie'))) {
            WC()->session->set_customer_session_cookie(true);
        }
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

    private function recent_player_ids() {
        if (!is_user_logged_in()) { return array(); }
        $rows = get_user_meta(get_current_user_id(), '_dfr_recent_player_ids_v1', true);
        if (!is_array($rows)) { return array(); }
        $safe = array();
        foreach (array_slice($rows, 0, 5) as $row) {
            if (!is_array($row)) { continue; }
            $value = isset($row['value']) ? trim(sanitize_text_field((string) $row['value'])) : '';
            if ($value === '' || strlen($value) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) { continue; }
            $safe[] = array(
                'value' => $value,
                'label' => isset($row['label']) ? substr(sanitize_text_field((string) $row['label']), 0, 80) : '',
                'used_at' => isset($row['used_at']) ? absint($row['used_at']) : 0,
            );
        }
        return $safe;
    }

    private function remember_player_id($value, $label = '') {
        if (!is_user_logged_in()) { return; }
        $value = trim(sanitize_text_field((string) $value));
        if ($value === '' || strlen($value) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) { return; }
        $rows = $this->recent_player_ids();
        $next = array(array('value' => $value, 'label' => substr(sanitize_text_field((string) $label), 0, 80), 'used_at' => time()));
        foreach ($rows as $row) {
            if (!hash_equals((string) $row['value'], $value)) { $next[] = $row; }
            if (count($next) >= 5) { break; }
        }
        update_user_meta(get_current_user_id(), '_dfr_recent_player_ids_v1', array_slice($next, 0, 5));
    }

    public function ajax_clear_recent_player_ids() {
        if (!is_user_logged_in() || !check_ajax_referer('dfr_recent_player_ids', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Session expirée. Rechargez la page.', 'delicat-fazercards')), 403);
        }
        delete_user_meta(get_current_user_id(), '_dfr_recent_player_ids_v1');
        wp_send_json_success(array('cleared' => true));
    }

    private function material_glyph($name) {
        $paths = array(
            'sports_esports' => '<path d="M21.58 16.09 20.5 8.65A4 4 0 0 0 16.54 5H7.46A4 4 0 0 0 3.5 8.65l-1.08 7.44A2.5 2.5 0 0 0 6.7 18.2L9 16h6l2.3 2.2a2.5 2.5 0 0 0 4.28-2.11ZM9 11H7v2H5v-2H3V9h2V7h2v2h2v2Zm7.5 2a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm3-3A1.5 1.5 0 1 1 18 8.5 1.5 1.5 0 0 1 19.5 10Z"/>',
            'history' => '<path d="M13 3a9 9 0 0 0-8.2 5.3L2 5.5V13h7.5l-3-3A7 7 0 1 1 6 15H4a9 9 0 1 0 9-12Zm-1 5v5l4.2 2.5 1-1.7-3.2-1.9V8h-2Z"/>',
            'person' => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5Z"/>',
            'verified_user' => '<path d="m12 2 8 3v6c0 5.1-3.4 9.7-8 11-4.6-1.3-8-5.9-8-11V5l8-3Zm-1.1 13.6 5.7-5.7-1.4-1.4-4.3 4.3-2.1-2.1-1.4 1.4 3.5 3.5Z"/>',
            'delete' => '<path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12Zm3-9h2v8H9v-8Zm4 0h2v8h-2v-8Zm2.5-6-1-1h-5l-1 1H5v2h14V4h-3.5Z"/>',
            'expand_more' => '<path d="m7.4 8.6 4.6 4.6 4.6-4.6L18 10l-6 6-6-6 1.4-1.4Z"/>',
            'arrow_forward' => '<path d="m12 4-1.4 1.4 5.6 5.6H4v2h12.2l-5.6 5.6L12 20l8-8-8-8Z"/>',
        );
        if (!isset($paths[$name])) { return ''; }
        return '<svg class="dfr-glyph" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
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
        // Display only (which button to show): never hold the page for the supplier's list.
        // Without any saved list the fetch is bounded to 5 s instead of 20 s.
        $uid_context = $this->uid_validation_enabled() && $this->plugin()->has_api_key() ? $this->uid_validation_context($product_id, false, 5, true) : false;
        $can_verify = is_array($uid_context);
        $nonce = $can_verify ? wp_create_nonce('dfr_uid_verify') : '';
        $recent = $this->recent_player_ids();
        static $uid_style = false;
        if (!$uid_style) {
            $uid_style = true;
            echo <<<'HTML'
<style>
.dfr-native-fields{--dfr-purple:#7c3aed;--dfr-purple2:#9333ea;--dfr-ink:#171321;--dfr-muted:#706b7b;margin:16px 0;padding:18px;border:1px solid #ebe7f2;border-radius:24px;background:linear-gradient(145deg,#fff 0%,#fdfbff 100%);box-shadow:0 18px 48px -34px rgba(74,29,150,.48);font-family:inherit;isolation:isolate}.dfr-native-head{display:flex;align-items:center;gap:12px;margin-bottom:16px}.dfr-native-head-icon{display:grid;place-items:center;width:44px;height:44px;flex:0 0 44px;border-radius:15px;color:#fff;background:linear-gradient(135deg,var(--dfr-purple2),var(--dfr-purple));box-shadow:0 10px 22px -12px rgba(124,58,237,.8)}.dfr-native-head strong{display:block;color:var(--dfr-ink);font-size:17px;line-height:1.2}.dfr-native-head small{display:block;margin-top:4px;color:var(--dfr-muted);font-size:12.5px;line-height:1.35}.dfr-glyph{width:22px;height:22px;display:block;fill:currentColor}.dfr-recent{margin:0 0 17px;padding:13px;border:1px solid #ece7f4;border-radius:18px;background:rgba(248,246,253,.78)}.dfr-recent-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}.dfr-recent-title{display:flex;align-items:center;gap:8px;color:#322b3e;font-size:13px;font-weight:800}.dfr-recent-title .dfr-glyph{width:19px;height:19px;color:var(--dfr-purple)}.dfr-recent-clear{display:flex;align-items:center;gap:5px;padding:6px 7px;border:0;background:transparent;color:#8a3dc1;font:700 11px/1 inherit;cursor:pointer}.dfr-recent-clear .dfr-glyph{width:16px;height:16px}.dfr-recent-picker{position:relative}.dfr-recent-toggle{width:100%;min-height:48px;display:flex;align-items:center;gap:10px;padding:0 13px;border:1px solid #ded8e8;border-radius:14px;background:#fff;color:#383142;font:650 13px/1.25 inherit;text-align:left;cursor:pointer}.dfr-recent-toggle>.dfr-glyph:first-child{color:var(--dfr-purple);width:20px;height:20px}.dfr-recent-toggle>.dfr-glyph:last-child{margin-left:auto;width:18px;height:18px;transition:transform .2s ease}.dfr-recent-picker.is-open .dfr-recent-toggle>.dfr-glyph:last-child{transform:rotate(180deg)}.dfr-recent-menu{display:none;position:absolute;z-index:30;top:calc(100% + 6px);left:0;right:0;padding:6px;border:1px solid #e4deec;border-radius:14px;background:#fff;box-shadow:0 18px 45px -18px rgba(37,24,58,.35)}.dfr-recent-picker.is-open .dfr-recent-menu{display:block}.dfr-recent-id{width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border:0;border-radius:10px;background:transparent;color:#241d2d;font:750 13px/1.2 inherit;text-align:left;cursor:pointer}.dfr-recent-id:hover,.dfr-recent-id:focus-visible{background:#f5f1ff;color:#6d28d9}.dfr-recent-id small{color:#8b8495;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.dfr-native-field{margin:0 0 14px!important}.dfr-native-field label{display:block;margin:0 0 7px;color:#241e2e;font-size:14px;font-weight:800}.dfr-field-shell{position:relative}.dfr-field-shell>.dfr-glyph{position:absolute;left:15px;top:50%;width:20px;height:20px;color:#8450d8;transform:translateY(-50%);pointer-events:none}.dfr-native-fields input[data-dfr-field-key],.dfr-native-fields select[data-dfr-field-key]{width:100%!important;min-height:52px!important;margin:0!important;padding:0 44px!important;border:1px solid #dcd6e5!important;border-radius:16px!important;background:#fff!important;color:#211a2c!important;font:650 15px/1.2 inherit!important;box-shadow:0 1px 0 rgba(255,255,255,.8) inset!important;outline:none!important;transition:border-color .2s,box-shadow .2s,background .2s}.dfr-native-fields input[data-dfr-field-key]:focus,.dfr-native-fields select[data-dfr-field-key]:focus{border-color:#9b6de3!important;box-shadow:0 0 0 4px rgba(124,58,237,.11)!important}.dfr-native-fields input::placeholder{color:#aaa3b4}.dfr-uid-verify-btn{width:100%!important;min-height:52px!important;display:flex!important;align-items:center!important;justify-content:center!important;gap:10px!important;margin-top:3px!important;border:0!important;border-radius:16px!important;background:linear-gradient(135deg,var(--dfr-purple2),var(--dfr-purple))!important;color:#fff!important;font:850 14px/1 inherit!important;box-shadow:0 13px 28px -15px rgba(124,58,237,.85)!important;cursor:pointer!important;transition:transform .18s,box-shadow .18s,opacity .18s}.dfr-uid-verify-btn:active{transform:scale(.985)}.dfr-uid-verify-btn:disabled{opacity:.68;cursor:wait}.dfr-uid-verify-btn .dfr-glyph{width:20px;height:20px}.dfr-uid-status{display:none;margin-top:11px;padding:12px 13px;border-radius:14px;font-size:12.5px;font-weight:750;line-height:1.4}.dfr-uid-advisory{margin-top:10px;padding:12px 13px;border:1px solid #f0d59e;border-radius:14px;background:#fff9ec;color:#7a4a0c;font-size:12px;font-weight:700;line-height:1.45}@media(max-width:380px){.dfr-native-fields{padding:14px;border-radius:20px}.dfr-native-head-icon{width:40px;height:40px;flex-basis:40px;border-radius:13px}.dfr-native-head strong{font-size:15px}.dfr-native-head small{font-size:11.5px}.dfr-recent{padding:11px}.dfr-recent-clear{font-size:0}.dfr-recent-clear .dfr-glyph{width:18px;height:18px}.dfr-native-fields input[data-dfr-field-key],.dfr-native-fields select[data-dfr-field-key],.dfr-uid-verify-btn{min-height:48px!important;font-size:13px!important}}
</style>
HTML;
        }
        echo '<div class="dfr-native-fields" data-dfr-product="' . esc_attr($product_id) . '" data-recent-key="dfrRecentPlayerIdsV1">';
        echo '<div class="dfr-native-head"><span class="dfr-native-head-icon">' . $this->material_glyph('sports_esports') . '</span><span><strong>' . esc_html__('Informations du compte de jeu', 'delicat-fazercards') . '</strong><small>' . esc_html__('Saisissez les informations exactement comme dans le jeu.', 'delicat-fazercards') . '</small></span></div>';
        echo '<div class="dfr-recent" data-server-recent="' . esc_attr(wp_json_encode($recent)) . '" data-clear-ajax="' . esc_url(admin_url('admin-ajax.php')) . '" data-clear-nonce="' . esc_attr(wp_create_nonce('dfr_recent_player_ids')) . '"><div class="dfr-recent-top"><span class="dfr-recent-title">' . $this->material_glyph('history') . esc_html__('Mes 5 derniers UID / ID Joueur', 'delicat-fazercards') . '</span><button type="button" class="dfr-recent-clear" aria-label="' . esc_attr__('Effacer les identifiants récents', 'delicat-fazercards') . '">' . $this->material_glyph('delete') . '<span>' . esc_html__('Effacer', 'delicat-fazercards') . '</span></button></div><div class="dfr-recent-picker"><button type="button" class="dfr-recent-toggle" aria-expanded="false">' . $this->material_glyph('person') . '<span>' . esc_html__('Choisir un UID / ID récent', 'delicat-fazercards') . '</span>' . $this->material_glyph('expand_more') . '</button><div class="dfr-recent-menu" role="listbox"></div></div></div>';
        foreach ($fields as $field) {
            $name = 'dfr_field_' . $field['key'];
            echo '<p class="form-row form-row-wide dfr-native-field">';
            echo '<label for="' . esc_attr($name) . '">' . esc_html($field['label']) . (!empty($field['required']) ? ' <span class="required">*</span>' : '') . '</label><span class="dfr-field-shell">' . $this->material_glyph('person');
            if ($field['type'] === 'select' && !empty($field['options'])) {
                echo '<select id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" data-dfr-field-key="' . esc_attr($field['key']) . '" data-dfr-role="' . esc_attr($this->uid_field_role($field['key'], $field['label'])) . '" ' . (!empty($field['required']) ? 'required' : '') . '>';
                echo '<option value="">' . esc_html__('Choose…', 'delicat-fazercards') . '</option>';
                foreach ($field['options'] as $value => $label) {
                    echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
                }
                echo '</select>';
            } else {
                $type = in_array($field['type'], array('number', 'email'), true) ? $field['type'] : 'text';
                $placeholder = $this->uid_field_role($field['key'], $field['label']) === 'player' ? 'Ex: 123456789' : '';
                echo '<input type="' . esc_attr($type) . '" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" data-dfr-field-key="' . esc_attr($field['key']) . '" data-dfr-role="' . esc_attr($this->uid_field_role($field['key'], $field['label'])) . '" maxlength="190" autocomplete="off" placeholder="' . esc_attr($placeholder) . '" ' . (!empty($field['required']) ? 'required' : '') . '>';
            }
            echo '</span></p>';
        }
        if (!$can_verify && $this->uid_product_has_player_identity_field($product_id)) {
            echo '<div class="dfr-uid-advisory">' . esc_html__('Vérifiez soigneusement votre User ID / Player ID avant d’acheter. Une recharge envoyée vers un mauvais compte est non remboursable.', 'delicat-fazercards') . '</div>';
        }
        if ($can_verify) {
            echo '<input type="hidden" name="dfr_uid_verification_token" class="dfr-uid-token" value="">';
            echo '<div class="dfr-uid-verify" data-ajax="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr($nonce) . '" data-abort-ms="' . esc_attr((string) self::UID_BROWSER_ABORT_MS) . '">';
            echo '<button type="button" class="button dfr-uid-verify-btn">' . $this->material_glyph('verified_user') . '<span>' . esc_html__('Vérifier votre compte', 'delicat-fazercards') . '</span>' . $this->material_glyph('arrow_forward') . '</button>';
            echo '<div class="dfr-uid-status" role="status" aria-live="polite"></div>';
            echo '</div>';
            static $uid_script = false;
            if (!$uid_script) {
                $uid_script = true;
                echo <<<'HTML'
<script>
(function(){
  function safeRows(value){return Array.isArray(value)?value.filter(function(r){return r&&/^[A-Za-z0-9._:-]{1,64}$/.test(String(r.value||''));}).slice(0,5):[];}
  function localRows(wrap){try{return safeRows(JSON.parse(localStorage.getItem(wrap.dataset.recentKey)||'[]'));}catch(e){return [];}}
  function mergedRows(wrap){var recent=wrap.querySelector('.dfr-recent'),server=[];try{server=safeRows(JSON.parse(recent.dataset.serverRecent||'[]'));}catch(e){}var out=[];server.concat(localRows(wrap)).forEach(function(r){if(!out.some(function(x){return x.value===r.value;}))out.push(r);});return out.slice(0,5);}
  function renderRecent(wrap){var recent=wrap.querySelector('.dfr-recent'),menu=recent&&recent.querySelector('.dfr-recent-menu'),rows=mergedRows(wrap);if(!menu)return;menu.textContent='';rows.forEach(function(r){var b=document.createElement('button');b.type='button';b.className='dfr-recent-id';b.dataset.value=r.value;var id=document.createElement('span');id.textContent=r.value;b.appendChild(id);if(r.label){var sm=document.createElement('small');sm.textContent=r.label;b.appendChild(sm);}menu.appendChild(b);});recent.hidden=rows.length===0;}
  function saveRecent(wrap,value,label){value=String(value||'').trim();if(!/^[A-Za-z0-9._:-]{1,64}$/.test(value))return;var rows=localRows(wrap).filter(function(r){return r.value!==value;});rows.unshift({value:value,label:String(label||'').slice(0,80),used_at:Math.floor(Date.now()/1000)});try{localStorage.setItem(wrap.dataset.recentKey,JSON.stringify(rows.slice(0,5)));}catch(e){}renderRecent(wrap);}
  document.querySelectorAll('.dfr-native-fields').forEach(renderRecent);
  function setStatus(box, ok, text){
    var s=box.querySelector('.dfr-uid-status'); if(!s)return;
    s.style.display='block'; s.style.background=ok?'#eaf8ef':'#fff1f1'; s.style.color=ok?'#146b35':'#9b2727';
    s.style.border='1px solid '+(ok?'#bfe8cc':'#f1caca'); s.textContent=text;
  }
  function clearVerification(wrap){
    wrap._dfrVerifyRevision=(wrap._dfrVerifyRevision||0)+1;
    var token=wrap.querySelector('.dfr-uid-token'); if(token)token.value='';
    var box=wrap.querySelector('.dfr-uid-verify'); if(box){var s=box.querySelector('.dfr-uid-status');if(s)s.style.display='none';}
  }
  document.addEventListener('input',function(e){var wrap=e.target.closest&&e.target.closest('.dfr-native-fields');if(wrap&&e.target.matches('[data-dfr-field-key]'))clearVerification(wrap);});
  document.addEventListener('change',function(e){var wrap=e.target.closest&&e.target.closest('.dfr-native-fields');if(wrap&&e.target.matches('[data-dfr-field-key]'))clearVerification(wrap);});
  document.addEventListener('click',function(e){
    var toggle=e.target.closest&&e.target.closest('.dfr-recent-toggle');if(toggle){var picker=toggle.closest('.dfr-recent-picker'),open=!picker.classList.contains('is-open');picker.classList.toggle('is-open',open);toggle.setAttribute('aria-expanded',open?'true':'false');return;}
    var recentId=e.target.closest&&e.target.closest('.dfr-recent-id');if(recentId){var recentWrap=recentId.closest('.dfr-native-fields'),player=recentWrap&&recentWrap.querySelector('[data-dfr-role="player"]');if(player){player.value=recentId.dataset.value;player.dispatchEvent(new Event('input',{bubbles:true}));player.focus();}var rp=recentId.closest('.dfr-recent-picker');if(rp){rp.classList.remove('is-open');var rt=rp.querySelector('.dfr-recent-toggle');if(rt)rt.setAttribute('aria-expanded','false');}return;}
    var clear=e.target.closest&&e.target.closest('.dfr-recent-clear');if(clear){var cw=clear.closest('.dfr-native-fields'),cr=clear.closest('.dfr-recent');try{localStorage.removeItem(cw.dataset.recentKey);}catch(err){}cr.dataset.serverRecent='[]';renderRecent(cw);if(cr.dataset.clearNonce){var cb=new URLSearchParams();cb.set('action','dfr_clear_recent_player_ids');cb.set('nonce',cr.dataset.clearNonce);fetch(cr.dataset.clearAjax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:cb.toString()}).catch(function(){});}return;}
    var btn=e.target.closest&&e.target.closest('.dfr-uid-verify-btn'); if(!btn)return;
    var wrap=btn.closest('.dfr-native-fields'), box=btn.closest('.dfr-uid-verify'); if(!wrap||!box)return;
    var fields={}, missing=false;
    wrap.querySelectorAll('[data-dfr-field-key]').forEach(function(el){var v=(el.value||'').trim();if(el.required&&!v)missing=true;if(v)fields[el.getAttribute('data-dfr-field-key')]=v;});
    if(missing||Object.keys(fields).length===0){setStatus(box,false,'Entrez l’UID / Player ID et les champs requis avant la vérification.');return;}
    clearVerification(wrap);var revision=wrap._dfrVerifyRevision,product=wrap.dataset.dfrProduct;
    function isCurrent(){return wrap.isConnected&&revision===wrap._dfrVerifyRevision&&product===wrap.dataset.dfrProduct;}
    btn.disabled=true; var label=btn.querySelector('span'),old=label?label.textContent:''; if(label)label.textContent='Vérification…';
    var body=new URLSearchParams(); body.set('action','dfr_verify_player_uid');body.set('nonce',box.dataset.nonce);body.set('product_id',wrap.dataset.dfrProduct);
    Object.keys(fields).forEach(function(k){body.append('fields['+k+']',fields[k]);});
    var controller=window.AbortController?new AbortController():null;
    var abortMs=parseInt(box.dataset.abortMs||'22000',10)||22000; var timeout=controller?setTimeout(function(){controller.abort();},abortMs):null;
    function post(b){return fetch(box.dataset.ajax,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:b.toString(),signal:controller?controller.signal:undefined}).then(function(r){return r.json().catch(function(){return null;}).then(function(j){return {r:r,j:j};});});}
    var renewed=false;
    function renew(x){var d=x&&x.j&&!x.j.success&&x.j.data;if(renewed||!d||d.code!=='nonce')return x;renewed=true;var nb=new URLSearchParams();nb.set('action','dfr_uid_nonce');return post(nb).then(function(n){var nd=n&&n.j&&n.j.success&&n.j.data;if(!nd||!nd.nonce)return x;box.dataset.nonce=nd.nonce;body.set('nonce',nd.nonce);return post(body);});}
    post(body).then(renew)
      .then(function(x){
        if(!isCurrent())return;
        var d=x.j&&x.j.data?x.j.data:{};
        if(x.j&&x.j.success&&d.valid&&d.token){
          var t=wrap.querySelector('.dfr-uid-token');if(t)t.value=d.token;
          var msg='✅ Compte vérifié'; if(d.player_name)msg+=' — '+d.player_name; if(d.region)msg+=' ('+d.region+')';
          setStatus(box,true,msg);
          var player=wrap.querySelector('[data-dfr-role="player"]');if(player)saveRecent(wrap,player.value,d.player_name||d.region||'');
        }else{clearVerification(wrap);setStatus(box,false,(d&&d.message)?d.message:'Impossible de vérifier cet ID pour le moment.');}
      })
      .catch(function(err){if(!isCurrent())return;clearVerification(wrap);setStatus(box,false,(err&&err.name==='AbortError')?'La vérification prend trop de temps. Réessayez dans quelques instants.':'Connexion interrompue. Réessayez.');})
      .finally(function(){if(timeout)clearTimeout(timeout);btn.disabled=false;if(label)label.textContent=old;});
  });
  document.addEventListener('click',function(e){document.querySelectorAll('.dfr-recent-picker.is-open').forEach(function(p){if(!p.contains(e.target)){p.classList.remove('is-open');var t=p.querySelector('.dfr-recent-toggle');if(t)t.setAttribute('aria-expanded','false');}});});
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
                wc_add_notice(sprintf(__('La quantité doit être comprise entre %1$d et %2$d pour ce produit.', 'delicat-fazercards'), $min, $max), 'error');
                $passed = false;
            }
        }
        // Supplier offer gone or out of stock, or held by the margin guard: refused now rather
        // than after payment, when a supplier refusal puts the order on hold.
        if ($passed) {
            $line = $this->supplier_line($product_id, $variation_id);
            $unavailable = $line ? $this->line_availability_error($line, max(1, (int) $quantity)) : null;
            if ($unavailable) {
                wc_add_notice($unavailable->get_error_message(), 'error');
                $passed = false;
            }
        }
        $fields = $this->get_field_spec_for_product($product_id);
        foreach ($fields as $field) {
            $name = 'dfr_field_' . $field['key'];
            $value = isset($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';
            if (!empty($field['required']) && $value === '') {
                wc_add_notice(sprintf(__('Veuillez renseigner : %s.', 'delicat-fazercards'), $field['label']), 'error');
                $passed = false;
            }
            if ($value !== '' && $field['type'] === 'select' && !empty($field['options']) && !array_key_exists($value, $field['options'])) {
                wc_add_notice(sprintf(__('Valeur invalide pour %s.', 'delicat-fazercards'), $field['label']), 'error');
                $passed = false;
            }
            if ($value !== '' && $field['type'] === 'email' && !is_email($value)) {
                wc_add_notice(sprintf(__('Adresse e-mail invalide pour %s.', 'delicat-fazercards'), $field['label']), 'error');
                $passed = false;
            }
            if ($value !== '' && $field['type'] === 'number' && !is_numeric($value)) {
                wc_add_notice(sprintf(__('%s doit être un nombre.', 'delicat-fazercards'), $field['label']), 'error');
                $passed = false;
            }
        }
        if ($passed && $this->uid_validation_required() && $this->is_app_api_cart_request($product_id)) {
            $verified = $this->validate_reseller_topup_uid($product_id, $this->app_api_cart_fields, true, 'app_add');
            if (is_wp_error($verified)) {
                wc_add_notice($this->app_uid_error($verified)->get_error_message(), 'error');
                $passed = false;
            } elseif (!empty($verified['supported']) && !empty($verified['valid'])) {
                $this->app_api_uid_verified = array(
                    'product_id' => absint($product_id),
                    'fields_hash' => $this->uid_fields_hash($this->app_api_cart_fields),
                    'result' => $verified,
                );
            }
        } elseif ($passed && $this->uid_validation_enabled() && $this->is_app_api_cart_request($product_id)) {
            // The store does not require verification: still refuse an account the supplier
            // reported as not found moments ago (no new supplier call).
            if ($this->uid_recent_negative($product_id, $this->app_api_cart_fields)) {
                wc_add_notice($this->app_uid_error($this->uid_invalid_error())->get_error_message(), 'error');
                $passed = false;
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
        // Add-to-cart validation has already passed at this point. Persist only the
        // player-identity field, never server/zone/email values, and keep five.
        foreach ($fields as $field) {
            $key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
            if ($key === '' || $this->uid_field_role($key, $field['label'] ?? '') !== 'player' || empty($plain_fields[$key])) { continue; }
            $recent_label = is_array($verified) && !empty($verified['name']) ? (string) $verified['name'] : '';
            $this->remember_player_id((string) $plain_fields[$key], $recent_label);
            break;
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

    /* ---------------------------------------------------------------------
     * Supplier availability, margin guard and batched sync (4.16)
     * ------------------------------------------------------------------ */

    /**
     * `delicat_dfr_cart_line_available` (contract K32): true, or a WP_Error when a gateway
     * line cannot be sold now. Codes: delicat_item_unavailable (supplier offer gone or out of
     * stock) and delicat_item_on_hold (opt-in margin hold). A supplier error lets the sale
     * through: an outage must not block checkout, and a failed delivery is held for review.
     *
     * @param true|WP_Error $available
     * @return true|WP_Error
     */
    public function cart_line_available($available = true, $product_id = 0, $variation_id = 0, $quantity = 1) {
        if ($available !== true) { return $available; }
        $line = $this->supplier_line($product_id, $variation_id);
        if (!$line) { return $available; }
        $error = $this->line_availability_error($line, max(1, (int) $quantity));
        return $error ? $error : $available;
    }

    /** `woocommerce_check_cart_items`: the same check on the website cart and checkout. */
    public function check_cart_supplier_availability() {
        if (!function_exists('WC') || !WC() || !isset(WC()->cart) || !WC()->cart || !function_exists('wc_add_notice')) { return; }
        $lines = array();
        $quantities = array();
        foreach ((array) WC()->cart->get_cart() as $cart_item) {
            $line = $this->supplier_line($cart_item['product_id'] ?? 0, $cart_item['variation_id'] ?? 0);
            if (!$line) { continue; }
            $key = $line['mapping_id'];
            $lines[$key] = $line;
            $quantities[$key] = ($quantities[$key] ?? 0) + max(1, (int) ($cart_item['quantity'] ?? 1));
        }
        foreach ($lines as $key => $line) {
            $error = $this->line_availability_error($line, $quantities[$key]);
            if ($error) { wc_add_notice($error->get_error_message(), 'error'); }
        }
    }

    /**
     * Reseller API: the store's supplier availability check, with the margin hold applied to
     * the reseller's own unit price.
     *
     * @return WP_Error|null Null when the line can be sold.
     */
    public function reseller_line_error($product_id, $variation_id, $quantity, $unit_price) {
        $line = $this->supplier_line($product_id, $variation_id);
        if (!$line) { return null; }
        return $this->line_availability_error($line, max(1, (int) $quantity), (float) $unit_price);
    }

    /**
     * Supplier mapping of a store line: the variation's own values first, then its parent's.
     *
     * @return array{product_id:int,mapping_id:int,type:string,category:string,offer:string,name:string}|null
     */
    private function supplier_line($product_id, $variation_id = 0) {
        $product_id = absint($product_id);
        $variation_id = absint($variation_id);
        $mapping_id = $variation_id ?: $product_id;
        if ($mapping_id < 1) { return null; }
        $parent_id = $product_id;
        if ($parent_id === $mapping_id && get_post_type($mapping_id) === 'product_variation') {
            $parent_id = (int) wp_get_post_parent_id($mapping_id);
        }
        $read = static function ($key) use ($mapping_id, $parent_id) {
            $value = (string) get_post_meta($mapping_id, $key, true);
            if ($value === '' && $parent_id > 0 && $parent_id !== $mapping_id) {
                $value = (string) get_post_meta($parent_id, $key, true);
            }
            return $value;
        };
        $type = sanitize_key((string) get_post_meta($mapping_id, '_dfr_service_type', true));
        if (!in_array($type, array('topup', 'giftcard', 'gamekey'), true) && $parent_id > 0 && $parent_id !== $mapping_id) {
            $type = sanitize_key((string) get_post_meta($parent_id, '_dfr_service_type', true));
        }
        if (!in_array($type, array('topup', 'giftcard', 'gamekey'), true)) { return null; }
        $category = $this->sanitize_remote_id($read('_dfr_category_id'));
        $offer = $this->sanitize_remote_id($read('_dfr_offer_id'));
        if ($category === '' || $offer === '') { return null; }
        $product = function_exists('wc_get_product') ? wc_get_product($mapping_id) : false;
        $name = $product ? trim(wp_strip_all_tags((string) $product->get_name())) : '';
        return array(
            'product_id' => $parent_id > 0 ? $parent_id : $mapping_id,
            'mapping_id' => $mapping_id,
            'type' => $type,
            'category' => $category,
            'offer' => $offer,
            'name' => $name !== '' ? $name : __('Ce produit', 'delicat-fazercards'),
        );
    }

    /**
     * Why a mapped line cannot be sold now, or null. The margin hold first (local data), then
     * the supplier's live offer list (60 s cache per category, 5 s timeout). A supplier error
     * or an empty list proves nothing: the sale goes through.
     *
     * @param float|null $price Selling price to guard; null for the product's own price.
     */
    private function line_availability_error(array $line, $quantity, $price = null) {
        $held = $this->margin_hold_error($line, $price);
        if ($held) { return $held; }
        $offers = $this->live_supplier_offers($line['type'], $line['category']);
        if (!$offers) { return null; }
        if (!array_key_exists($line['offer'], $offers)) {
            return new WP_Error('delicat_item_unavailable', sprintf(__('« %s » n’est plus disponible pour le moment.', 'delicat-fazercards'), $line['name']), array('status' => 409));
        }
        $stock = $offers[$line['offer']];
        if ($line['type'] !== 'topup' && $stock !== null && (int) $stock < max(1, (int) $quantity)) {
            if ((int) $stock <= 0) {
                return new WP_Error('delicat_item_unavailable', sprintf(__('« %s » est en rupture de stock.', 'delicat-fazercards'), $line['name']), array('status' => 409));
            }
            return new WP_Error('delicat_item_unavailable', sprintf(__('Stock insuffisant pour « %1$s » : il en reste %2$d.', 'delicat-fazercards'), $line['name'], (int) $stock), array('status' => 409));
        }
        return null;
    }

    /** Supplier offer id => stock (null = not given) for a category; null when unknown. */
    private function live_supplier_offers($service, $category_id) {
        $key = $service . '|' . $category_id;
        if (array_key_exists($key, $this->availability_memo)) { return $this->availability_memo[$key]; }
        $offers = null;
        $transient = 'dfr_avail_' . md5($key);
        $cached = get_transient($transient);
        if (is_array($cached) && array_key_exists('offers', $cached)) {
            $offers = is_array($cached['offers']) && $cached['offers'] ? $cached['offers'] : null;
        } elseif ($this->plugin()->has_api_key()) {
            $fetched = $this->fetch_supplier_offers($service, $category_id, 5);
            if (is_wp_error($fetched)) {
                // Fail open, and do not ask again for 30 s.
                set_transient($transient, array('offers' => null), 30);
                $this->plugin()->log('warning', 'Supplier availability check failed; the sale is allowed', array(
                    'service' => $service,
                    'error' => sanitize_key((string) $fetched->get_error_code()),
                ));
            } else {
                $offers = array();
                foreach ($fetched['items'] as $item) { $offers[$item['id']] = $item['stock']; }
                set_transient($transient, array('offers' => $offers), MINUTE_IN_SECONDS);
                $offers = $offers ? $offers : null;
            }
        }
        $this->availability_memo[$key] = $offers;
        return $offers;
    }

    /** Margin guard settings: administrators only, saved by save_margin_guard_settings(). */
    private function margin_guard_settings() {
        $stored = get_option(self::MARGIN_OPTION, array());
        return wp_parse_args(is_array($stored) ? $stored : array(), array(
            'rate' => '0',       // store currency per USD; 0 = guard off
            'threshold' => '0',  // minimum margin, percent
            'email' => '1',
            'hold' => '0',       // opt-in: refuse sales under the minimum
        ));
    }

    /**
     * A selling price against the latest supplier cost recorded by import or sync.
     *
     * @return array{floor:float,cost_usd:float,price:float,below:bool}|null Null when the guard is
     *         off, the cost is unknown or the price is not set.
     */
    private function margin_state($mapping_id, $price) {
        $guard = $this->margin_guard_settings();
        $rate = (float) $guard['rate'];
        $price = (float) $price;
        if ($rate <= 0 || $price <= 0) { return null; }
        $cost = (float) get_post_meta(absint($mapping_id), '_dfr_supplier_cost_usd', true);
        if ($cost <= 0) { return null; }
        $floor = $cost * $rate * (1 + max(0.0, (float) $guard['threshold']) / 100);
        return array('floor' => round($floor, 2), 'cost_usd' => $cost, 'price' => $price, 'below' => $price + 0.00001 < $floor);
    }

    /** delicat_item_on_hold when the opt-in hold is on and the price is under the minimum. */
    private function margin_hold_error(array $line, $price = null) {
        if ($this->margin_guard_settings()['hold'] !== '1') { return null; }
        if ($price === null) {
            $product = function_exists('wc_get_product') ? wc_get_product($line['mapping_id']) : false;
            $price = $product ? (float) $product->get_price('edit') : 0.0;
        }
        $state = $this->margin_state($line['mapping_id'], $price);
        if (!$state || !$state['below']) { return null; }
        return new WP_Error('delicat_item_on_hold', sprintf(__('« %s » est momentanément indisponible. Réessayez plus tard.', 'delicat-fazercards'), $line['name']), array('status' => 409));
    }

    /** Margin alert for a synchronized product (store and reseller prices), or null. */
    private function margin_alert_entry($product) {
        $id = (int) $product->get_id();
        $price = (float) $product->get_price('edit');
        $retail = $this->margin_state($id, $price);
        $reseller_price = get_post_meta($id, '_dfr_reseller_enabled', true) === '1' ? (float) get_post_meta($id, '_dfr_reseller_price', true) : 0.0;
        $reseller = $reseller_price > 0 ? $this->margin_state($id, $reseller_price) : null;
        $retail_below = $retail && $retail['below'];
        $reseller_below = $reseller && $reseller['below'];
        if (!$retail_below && !$reseller_below) { return null; }
        $basis = $retail ? $retail : $reseller;
        return array(
            'name' => substr(trim(wp_strip_all_tags((string) $product->get_name())), 0, 120),
            'price' => $price,
            'reseller_price' => $reseller_price,
            'floor' => $basis['floor'],
            'cost_usd' => $basis['cost_usd'],
            'retail_below' => $retail_below,
            'reseller_below' => $reseller_below,
            'at' => gmdate('c'),
        );
    }

    /** Update the stored margin alerts (product id => entry, null clears) and mail new ones. */
    private function record_margin_alerts(array $updates) {
        if (!$updates) { return; }
        $alerts = get_option(self::MARGIN_ALERTS_OPTION, array());
        $alerts = is_array($alerts) ? $alerts : array();
        $new = array();
        foreach ($updates as $product_id => $entry) {
            $product_id = absint($product_id);
            if (!$product_id) { continue; }
            if (!is_array($entry)) {
                unset($alerts[$product_id]);
                continue;
            }
            if (!isset($alerts[$product_id])) { $new[$product_id] = $entry; }
            $alerts[$product_id] = $entry;
        }
        update_option(self::MARGIN_ALERTS_OPTION, array_slice($alerts, 0, 200, true), false);
        $guard = $this->margin_guard_settings();
        if ($new && $guard['email'] === '1') {
            $this->send_margin_alert_email($new, $guard);
        }
    }

    private function send_margin_alert_email(array $new, array $guard) {
        $to = (string) apply_filters('dfr_margin_alert_email', get_option('admin_email'));
        if (!is_email($to)) { return; }
        $currency = strtoupper((string) get_option('woocommerce_currency', 'HTG'));
        $lines = array();
        foreach (array_slice($new, 0, 30, true) as $product_id => $entry) {
            $prices = array();
            if (!empty($entry['retail_below'])) { $prices[] = sprintf('prix %s %s', number_format((float) $entry['price'], 2, '.', ' '), $currency); }
            if (!empty($entry['reseller_below'])) { $prices[] = sprintf('prix revendeur %s %s', number_format((float) $entry['reseller_price'], 2, '.', ' '), $currency); }
            $lines[] = sprintf('- %1$s (#%2$d) : %3$s ; minimum %4$s %5$s (coût %6$s USD)', (string) $entry['name'], $product_id, implode(', ', $prices), number_format((float) $entry['floor'], 2, '.', ' '), $currency, number_format((float) $entry['cost_usd'], 4, '.', ''));
        }
        $subject = sprintf('[Delicat] Marge insuffisante : %d produit(s)', count($new));
        $body = "Le coût fournisseur de ces produits, converti au taux de surveillance, dépasse le prix de vente moins la marge minimale :\n\n"
            . implode("\n", $lines) . "\n\n"
            . ($guard['hold'] === '1'
                ? "Le blocage est activé : ces ventes sont refusées tant que le prix n'est pas corrigé.\n"
                : "Les ventes continuent : corrigez les prix dans WooCommerce, ou activez le blocage dans Catalog Studio.\n")
            . "\n" . admin_url('admin.php?page=delicat-fazercards') . "\n";
        wp_mail($to, $subject, $body);
    }

    /** admin-post `dfr_margin_guard_save`: administrators only, nonce, audit log. */
    public function save_margin_guard_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'delicat-fazercards'), '', array('response' => 403));
        }
        check_admin_referer('dfr_margin_guard_save');
        $before = $this->margin_guard_settings();
        $after = array(
            'rate' => $this->decimal_setting($_POST['margin_rate'] ?? '0', 0, 1000000, 4),
            'threshold' => $this->decimal_setting($_POST['margin_threshold'] ?? '0', 0, 500, 2),
            'email' => !empty($_POST['margin_email']) ? '1' : '0',
            'hold' => !empty($_POST['margin_hold']) ? '1' : '0',
        );
        $changes = array();
        foreach ($after as $key => $value) {
            if ((string) ($before[$key] ?? '') !== (string) $value) {
                $changes[$key] = (string) ($before[$key] ?? '') . ' -> ' . (string) $value;
            }
        }
        update_option(self::MARGIN_OPTION, $after, false);
        if ($changes) {
            $this->audit('margin_guard_settings', $changes);
        }
        set_transient('dfr_admin_notice_' . get_current_user_id(), array('type' => 'success', 'message' => __('Surveillance de la marge enregistrée.', 'delicat-fazercards')), 60);
        wp_safe_redirect(admin_url('admin.php?page=delicat-fazercards'));
        exit;
    }

    private function decimal_setting($raw, $min, $max, $decimals) {
        $raw = is_scalar($raw) ? str_replace(',', '.', trim(sanitize_text_field(wp_unslash((string) $raw)))) : '';
        $value = is_numeric($raw) ? (float) $raw : 0.0;
        return number_format(max((float) $min, min((float) $max, $value)), (int) $decimals, '.', '');
    }

    /** `delicat_app_suspend_catalog_bumps` while a sync batch saves products (contract K34). */
    public function suspend_catalog_bumps($suspend = false) {
        return true;
    }

    /** Queue the next sync batch: Action Scheduler when available, else WP-Cron. */
    private function schedule_sync_batch($round, $delay = 5) {
        $args = array(absint($round));
        $when = time() + max(1, (int) $delay);
        if (function_exists('as_schedule_single_action') && did_action('action_scheduler_init')) {
            // Deduped on arguments only: the running batch must not suppress its successor.
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('dfr_catalog_sync_batch', $args, 'delicat-fazercards')) { return; }
            try {
                if ((int) as_schedule_single_action($when, 'dfr_catalog_sync_batch', $args, 'delicat-fazercards', false, 200) > 0) { return; }
            } catch (Throwable $e) {
                // WP-Cron below.
            }
        }
        if (!wp_next_scheduled('dfr_catalog_sync_batch', $args)) {
            wp_schedule_single_event($when, 'dfr_catalog_sync_batch', $args);
        }
    }

    /** Every imported (service, supplier category) pair: key => group. */
    private function sync_group_index() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT DISTINCT s.meta_value AS service, c.meta_value AS category_id
             FROM {$wpdb->postmeta} i
             INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id
             INNER JOIN {$wpdb->postmeta} s ON s.post_id = i.post_id AND s.meta_key = '_dfr_service_type'
             INNER JOIN {$wpdb->postmeta} c ON c.post_id = i.post_id AND c.meta_key = '_dfr_category_id'
             WHERE i.meta_key = '_dfr_imported' AND i.meta_value = '1'
               AND p.post_type IN ('product','product_variation')
               AND p.post_status IN ('publish','draft','private','pending')
             LIMIT 5000"
        );
        $index = array();
        foreach ((array) $rows as $row) {
            $service = $this->sanitize_service($row->service ?? '');
            $category_id = $this->sanitize_remote_id($row->category_id ?? '');
            if (!$service || $category_id === '') { continue; }
            $index[$service . '|' . $category_id] = array('service' => $service, 'category_id' => $category_id);
        }
        ksort($index);
        return $index;
    }

    /** Groups not attempted since the cycle started: key => last attempt, oldest first. */
    private function sync_pending_groups(array $index, array $states, $cycle) {
        $cycle = absint($cycle);
        $pending = array();
        foreach (array_keys($index) as $key) {
            $attempted = absint($states[$key]['attempted_at'] ?? 0);
            if ($cycle === 0 || $attempted < $cycle) { $pending[$key] = $attempted; }
        }
        asort($pending, SORT_NUMERIC);
        return $pending;
    }

    private function sync_group_products(array $group) {
        return array_map('intval', get_posts(array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => array('publish', 'draft', 'private', 'pending'),
            'posts_per_page' => 1000,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => '_dfr_imported', 'value' => '1'),
                array('key' => '_dfr_service_type', 'value' => $group['service']),
                array('key' => '_dfr_category_id', 'value' => $group['category_id']),
            ),
        )));
    }

    /**
     * Synchronize one supplier category with its imported products.
     *
     * @return array{state:array,updated:int,missing:int,cost_changes:int,schema_changes:int,error:string,pending_confirmation:bool,changed:bool,parents:array,margin:array}
     */
    private function sync_group(array $group, array $settings, $timeout, array $state) {
        $now = time();
        $out = array('state' => $state, 'updated' => 0, 'missing' => 0, 'cost_changes' => 0, 'schema_changes' => 0, 'error' => '', 'pending_confirmation' => false, 'changed' => false, 'parents' => array(), 'margin' => array());
        $out['state']['attempted_at'] = $now;

        $remote = $this->fetch_supplier_offers($group['service'], $group['category_id'], $timeout);
        if (is_wp_error($remote)) {
            // Stale, not missing: the products keep their state until the supplier answers.
            if ($remote->get_error_code() === 'dfr_rate_limited') {
                // 4.18: honour the supplier's Retry-After before the next group or batch.
                $detail = $remote->get_error_data();
                $retry = is_array($detail) ? (int) ($detail['retry_after'] ?? 0) : 0;
                set_transient('dfr_catalog_backoff', 1, max(30, $retry > 0 ? $retry : 60));
            }
            $out['error'] = substr(sanitize_key((string) $remote->get_error_code()), 0, 60);
            $out['state']['error'] = $out['error'];
            $out['state']['errors'] = absint($state['errors'] ?? 0) + 1;
            return $out;
        }
        $out['state']['error'] = '';
        $out['state']['errors'] = 0;

        $remote_map = array();
        foreach ($remote['items'] as $item) { $remote_map[$item['id']] = $item; }
        if (!$remote_map && empty($remote['complete'])) {
            // An empty list that is not explicitly complete (supplier glitch, renamed list key,
            // truncated page) never proves every product is gone: nothing is disabled, and the
            // health notice reports the group. 4.18's completeness signal is what makes an empty
            // answer authoritative, and a malformed or mismatched list is already a WP_Error.
            $out['error'] = 'empty_response';
            $out['state']['empty'] = 1;
            return $out;
        }
        $out['state']['empty'] = $remote_map ? 0 : 1;
        $out['state']['synced_at'] = $now;

        $product_ids = $this->sync_group_products($group);
        $offers = array();
        foreach ($product_ids as $product_id) {
            $offer_id = (string) get_post_meta($product_id, '_dfr_offer_id', true);
            // Variable parents intentionally have no offer ID; their children do.
            if ($offer_id !== '') { $offers[$product_id] = $offer_id; }
        }

        // Circuit breaker: marking more than half of a group missing needs the same answer twice.
        $missing_offers = array();
        $missing_products = 0;
        foreach ($offers as $offer_id) {
            if (!isset($remote_map[$offer_id])) {
                $missing_offers[(string) $offer_id] = true;
                $missing_products++;
            }
        }
        $apply_missing = true;
        if ($missing_products > 0 && count($offers) >= 2 && $missing_products * 2 > count($offers)) {
            $ids = array_map('strval', array_keys($missing_offers));
            sort($ids, SORT_STRING);
            $signature = hash('sha256', implode('|', $ids));
            if (!hash_equals((string) ($state['pending_missing'] ?? ''), $signature)) {
                $out['state']['pending_missing'] = $signature;
                $out['state']['pending_since'] = $now;
                $out['pending_confirmation'] = true;
                $apply_missing = false;
            }
        }
        if ($apply_missing) {
            $out['state']['pending_missing'] = '';
            $out['state']['pending_since'] = 0;
        }

        if ($group['service'] === 'topup' && !empty($remote['fields']) && is_array($remote['fields'])) {
            foreach ($product_ids as $product_id) {
                if (get_post_type($product_id) === 'product' && $this->refresh_field_spec($product_id, $remote['fields'])) {
                    $out['schema_changes']++;
                    $out['changed'] = true;
                }
            }
        }

        $pricing_mode = $this->pricing_mode();
        $price_sync = $pricing_mode === 'automatic' && ($settings['catalog_price_sync'] ?? '0') === '1';
        foreach ($offers as $product_id => $offer_id) {
            $product = wc_get_product($product_id);
            if (!$product) { continue; }
            $saved = false;
            if (!isset($remote_map[$offer_id])) {
                if (!$apply_missing) { continue; }
                if (empty($remote['complete'])) {
                    // 4.18: a partial, truncated or paginated answer never proves an offer is
                    // gone. The product keeps its state and the group is reported instead.
                    if ($out['error'] === '') { $out['error'] = 'incomplete_response'; }
                    continue;
                }
                if (get_post_meta($product_id, '_dfr_supplier_missing', true) !== '1') {
                    update_post_meta($product_id, '_dfr_supplier_missing', '1');
                    update_post_meta($product_id, '_dfr_supplier_missing_since', current_time('mysql', true));
                }
                if (($settings['catalog_disable_missing'] ?? '1') === '1' && $this->supplier_stock_sync_enabled($product_id)) {
                    // A managed quantity above zero puts the status back to « in stock » when
                    // WooCommerce saves, so the quantity goes to zero too.
                    if ($group['service'] !== 'topup' && $product->get_manage_stock()) {
                        $product->set_stock_quantity(0);
                    }
                    $product->set_backorders('no');
                    $product->set_stock_status('outofstock');
                    $saved = $this->save_if_changed($product);
                }
                $out['missing']++;
            } else {
                $item = $remote_map[$offer_id];
                $was_missing = get_post_meta($product_id, '_dfr_supplier_missing', true) === '1';
                if (!empty($item['price_valid']) && $this->record_supplier_cost($product_id, (float) $item['price_usd'])) {
                    $out['cost_changes']++;
                }
                // update_post_meta() writes nothing when the value is unchanged.
                update_post_meta($product_id, '_dfr_price_protection_mode', $pricing_mode);
                update_post_meta($product_id, '_dfr_supplier_stock', $item['stock'] === null ? '' : (string) $item['stock']);
                update_post_meta($product_id, '_dfr_min_order_quantity', max(1, (int) $item['min_order_quantity']));
                update_post_meta($product_id, '_dfr_max_order_quantity', max(1, (int) $item['max_order_quantity']));
                $this->touch_supplier_last_seen($product_id);
                if ($was_missing) {
                    delete_post_meta($product_id, '_dfr_supplier_missing');
                    delete_post_meta($product_id, '_dfr_supplier_missing_since');
                }

                if ($price_sync && !empty($item['price_valid'])) {
                    $price = $this->calculate_store_price((float) $item['price_usd']);
                    if (!is_wp_error($price) && $price > 0) {
                        $product->set_regular_price(wc_format_decimal($price, $this->store_price_decimals()));
                        $product->set_price(wc_format_decimal($price, $this->store_price_decimals()));
                    }
                }
                // 4.18 supplier availability, and it alone, decides the stock state here: the
                // per-product « Supplier stock sync » switch gates the write, an explicit false
                // blocks sales, an explicit zero disables backorders, and an unknown or
                // incomplete answer never restocks a product on its own. This is the owner's
                // rule and it is asserted by tests/sync-regression.php; the reappearance of an
                // offer only clears the « missing » flag above, it is not a restock signal.
                $this->apply_supplier_availability($product, $item, $group['service']);
                $saved = $this->save_if_changed($product);
                $out['updated']++;
                $out['margin'][$product_id] = $this->margin_alert_entry($product);
            }
            if ($saved) {
                $out['changed'] = true;
                if ($product->get_parent_id()) { $out['parents'][(int) $product->get_parent_id()] = true; }
            }
        }
        return $out;
    }

    /** Save only when a property changed: every save moves the app catalog revision and clears caches. */
    private function save_if_changed($product) {
        if (!is_object($product) || !is_callable(array($product, 'get_changes')) || !$product->get_changes()) {
            return false;
        }
        try {
            return (bool) $product->save();
        } catch (Throwable $e) {
            $this->plugin()->log('error', 'Catalog sync could not save a product', array('product' => (int) $product->get_id()));
            return false;
        }
    }

    /** Per-product « supplier last seen » (shown on the variation screen), written at most hourly. */
    private function touch_supplier_last_seen($product_id) {
        $last = (string) get_post_meta($product_id, '_dfr_supplier_last_seen', true);
        $seen = $last !== '' ? strtotime($last . ' UTC') : false;
        if ($seen === false || time() - $seen >= HOUR_IN_SECONDS) {
            update_post_meta($product_id, '_dfr_supplier_last_seen', current_time('mysql', true));
        }
    }

    /**
     * Follow the supplier's top-up field schema. Only _dfr_field_spec_json changes, only when
     * the live schema is not empty and differs. The owner's field map keeps its lines and gets
     * a line for each new key. A field the supplier dropped stays in the form, optional, so a
     * map line the owner kept cannot hold every order.
     *
     * @return bool True when the stored schema changed.
     */
    private function refresh_field_spec($product_id, array $fresh) {
        $product_id = absint($product_id);
        if (!$fresh || $product_id < 1) { return false; }
        $fresh_hash = $this->field_spec_hash($fresh);
        $stored_hash = (string) get_post_meta($product_id, '_dfr_field_spec_hash', true);
        if ($stored_hash !== '' && hash_equals($stored_hash, $fresh_hash)) { return false; }
        $decoded = json_decode((string) get_post_meta($product_id, '_dfr_field_spec_json', true), true);
        $current = is_array($decoded) ? $this->sanitize_field_spec($decoded) : array();
        if ($current && hash_equals($this->field_spec_hash($current), $fresh_hash)) {
            // Same schema, stored before hashes existed: remember the hash, keep the JSON.
            update_post_meta($product_id, '_dfr_field_spec_hash', $fresh_hash);
            return false;
        }

        $fresh_keys = array();
        foreach ($fresh as $field) { $fresh_keys[sanitize_key((string) ($field['key'] ?? ''))] = true; }
        $merged = $fresh;
        $removed = array();
        foreach ($current as $field) {
            $key = sanitize_key((string) ($field['key'] ?? ''));
            if ($key === '' || isset($fresh_keys[$key])) { continue; }
            $field['required'] = false;
            $merged[] = $field;
            $removed[] = $key;
        }
        $this->store_field_spec($product_id, $merged, $fresh_hash);

        $lines = array();
        $mapped = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) get_post_meta($product_id, '_dfr_field_map', true)) as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '=') === false) { continue; }
            $lines[] = $line;
            $mapped[sanitize_key(trim((string) strstr($line, '=', true)))] = true;
        }
        $added = array();
        foreach ($fresh as $field) {
            $key = sanitize_key((string) ($field['key'] ?? ''));
            $label = sanitize_text_field((string) ($field['label'] ?? ''));
            if ($key === '' || $label === '' || isset($mapped[$key]) || count($lines) >= 20) { continue; }
            $lines[] = $key . '=' . $label;
            $mapped[$key] = true;
            $added[] = $key;
        }
        if ($added) {
            update_post_meta($product_id, '_dfr_field_map', wp_slash(implode("\n", $lines)));
        }
        $this->note_schema_change($product_id, $added, $removed);
        return true;
    }

    /** Remember a supplier field change for the Catalog Studio notice (last 20). */
    private function note_schema_change($product_id, array $added, array $removed) {
        $notes = get_option(self::SCHEMA_CHANGES_OPTION, array());
        $notes = is_array($notes) ? $notes : array();
        array_unshift($notes, array(
            'product' => absint($product_id),
            'added' => array_slice(array_map('sanitize_key', $added), 0, 10),
            'removed' => array_slice(array_map('sanitize_key', $removed), 0, 10),
            'at' => time(),
        ));
        update_option(self::SCHEMA_CHANGES_OPTION, array_slice($notes, 0, 20), false);
    }

    /** Catalog Studio notices: sync health, supplier field changes, margin alerts. */
    private function render_catalog_health_notices(array $settings) {
        $warn = 'background:#fff7ed;border:1px solid #fed7aa;color:#9a3412';
        $messages = array();
        $health = get_option(self::SYNC_HEALTH_OPTION, array());
        $health = is_array($health) ? $health : array();
        if (($settings['catalog_auto_sync'] ?? '0') === '1') {
            $intervals = array('dfr_15_minutes' => 15 * MINUTE_IN_SECONDS, 'hourly' => HOUR_IN_SECONDS, 'twicedaily' => 12 * HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS);
            $every = $intervals[(string) ($settings['catalog_sync_interval'] ?? 'hourly')] ?? HOUR_IN_SECONDS;
            $last_success = absint($health['last_success'] ?? 0);
            if (!$last_success || time() - $last_success > 3 * $every + HOUR_IN_SECONDS) {
                $messages[] = __('Synchronisation fournisseur en retard : aucune catégorie n’a été mise à jour récemment. Le stock et les coûts peuvent être périmés.', 'delicat-fazercards');
            }
        }
        $errors = absint($health['errors'] ?? 0);
        if ($errors > 0) {
            $messages[] = sprintf(_n('%d catégorie fournisseur n’a pas répondu à sa dernière synchronisation : ses produits gardent leur état précédent.', '%d catégories fournisseur n’ont pas répondu à leur dernière synchronisation : leurs produits gardent leur état précédent.', $errors, 'delicat-fazercards'), $errors);
        }
        $empty = absint($health['empty'] ?? 0);
        if ($empty > 0) {
            $messages[] = sprintf(_n('%d catégorie fournisseur a renvoyé une liste vide : rien n’a été désactivé, vérifiez-la.', '%d catégories fournisseur ont renvoyé une liste vide : rien n’a été désactivé, vérifiez-les.', $empty, 'delicat-fazercards'), $empty);
        }
        $pending = absint($health['pending_confirmation'] ?? 0);
        if ($pending > 0) {
            $messages[] = sprintf(_n('%d catégorie fournisseur semble avoir perdu plus de la moitié de ses produits : ils seront marqués indisponibles si la prochaine synchronisation le confirme.', '%d catégories fournisseur semblent avoir perdu plus de la moitié de leurs produits : ils seront marqués indisponibles si la prochaine synchronisation le confirme.', $pending, 'delicat-fazercards'), $pending);
        }
        foreach ($messages as $message) {
            echo '<div class="dfr-notice" style="' . esc_attr($warn) . '">' . esc_html($message) . '</div>';
        }

        $recent = array();
        $changes = get_option(self::SCHEMA_CHANGES_OPTION, array());
        foreach (is_array($changes) ? $changes : array() as $change) {
            $product_id = is_array($change) ? absint($change['product'] ?? 0) : 0;
            if ($product_id && absint($change['at'] ?? 0) > time() - 14 * DAY_IN_SECONDS) { $recent[$product_id] = true; }
        }
        if ($recent) {
            $links = array();
            foreach (array_slice(array_keys($recent), 0, 10) as $product_id) {
                $title = get_the_title($product_id);
                $links[] = '<a href="' . esc_url(admin_url('post.php?post=' . $product_id . '&action=edit')) . '">' . esc_html($title !== '' ? $title : '#' . $product_id) . '</a>';
            }
            echo '<div class="dfr-notice" style="' . esc_attr($warn) . '">' . esc_html__('Le fournisseur a changé les champs demandés pour ces recharges : les nouveaux champs ont été ajoutés à la correspondance et les champs retirés sont devenus facultatifs. Vérifiez la correspondance :', 'delicat-fazercards') . ' ' . implode(', ', $links) . '</div>';
        }

        $alerts = get_option(self::MARGIN_ALERTS_OPTION, array());
        $alerts = is_array($alerts) ? array_filter($alerts, 'is_array') : array();
        if ($alerts) {
            $guard = $this->margin_guard_settings();
            $currency = strtoupper((string) get_option('woocommerce_currency', 'HTG'));
            $items = array();
            foreach (array_slice($alerts, 0, 10, true) as $product_id => $entry) {
                $edit_id = (int) wp_get_post_parent_id((int) $product_id);
                $items[] = '<li><a href="' . esc_url(admin_url('post.php?post=' . ($edit_id > 0 ? $edit_id : (int) $product_id) . '&action=edit')) . '">' . esc_html((string) ($entry['name'] ?? '#' . $product_id)) . '</a> — '
                    . esc_html(sprintf(__('minimum %1$s %2$s', 'delicat-fazercards'), number_format_i18n((float) ($entry['floor'] ?? 0), 2), $currency)) . '</li>';
            }
            echo '<div class="dfr-notice dfr-notice-error"><strong>' . esc_html(sprintf(_n('Marge insuffisante sur %d produit', 'Marge insuffisante sur %d produits', count($alerts), 'delicat-fazercards'), count($alerts))) . '</strong> — '
                . esc_html($guard['hold'] === '1' ? __('ces ventes sont refusées jusqu’à correction du prix.', 'delicat-fazercards') : __('les ventes continuent ; corrigez les prix ou activez le blocage.', 'delicat-fazercards'))
                . '<ul style="margin:6px 0 0 18px;list-style:disc">' . implode('', $items) . '</ul></div>';
        }
    }

    /** Margin guard form (Pricing & Sync tab). */
    private function render_margin_guard_form() {
        $guard = $this->margin_guard_settings();
        $base = strtoupper((string) get_option('woocommerce_currency', 'HTG'));
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dfr-settings-form" style="margin-top:12px">
            <?php wp_nonce_field('dfr_margin_guard_save'); ?>
            <input type="hidden" name="action" value="dfr_margin_guard_save">
            <div class="dfr-panel-head">
                <div>
                    <h2><?php esc_html_e('Surveillance de la marge', 'delicat-fazercards'); ?></h2>
                    <p><?php esc_html_e('Compare le dernier coût fournisseur connu (USD, mis à jour par la synchronisation) avec le prix de vente et le prix revendeur. Alerte d’abord ; le blocage des ventes est facultatif.', 'delicat-fazercards'); ?></p>
                </div>
            </div>
            <div class="dfr-form-grid">
                <label class="dfr-field"><span><?php echo esc_html(sprintf(__('Taux de surveillance (%s pour 1 USD)', 'delicat-fazercards'), $base)); ?></span><input type="number" min="0" step="0.0001" name="margin_rate" value="<?php echo esc_attr($guard['rate']); ?>"><small><?php esc_html_e('0 désactive la surveillance. Sert aussi en tarification manuelle.', 'delicat-fazercards'); ?></small></label>
                <label class="dfr-field"><span><?php esc_html_e('Marge minimale (%)', 'delicat-fazercards'); ?></span><input type="number" min="0" max="500" step="0.1" name="margin_threshold" value="<?php echo esc_attr($guard['threshold']); ?>"><small><?php esc_html_e('Alerte quand le prix est sous le coût converti augmenté de cette marge.', 'delicat-fazercards'); ?></small></label>
            </div>
            <div class="dfr-toggle-list">
                <label class="dfr-toggle-row"><span><strong><?php esc_html_e('Alerter l’administrateur par e-mail', 'delicat-fazercards'); ?></strong><small><?php esc_html_e('Un e-mail quand un produit passe sous la marge minimale.', 'delicat-fazercards'); ?></small></span><span class="dfr-switch"><input type="checkbox" name="margin_email" value="1" <?php checked($guard['email'], '1'); ?>><i></i></span></label>
                <label class="dfr-toggle-row is-danger"><span><strong><?php esc_html_e('Bloquer la vente sous la marge minimale', 'delicat-fazercards'); ?></strong><small><?php esc_html_e('Facultatif. Un taux erroné peut rendre de nombreux produits indisponibles : vérifiez les alertes avant d’activer.', 'delicat-fazercards'); ?></small></span><span class="dfr-switch"><input type="checkbox" name="margin_hold" value="1" <?php checked($guard['hold'], '1'); ?>><i></i></span></label>
            </div>
            <div class="dfr-save-row"><button class="button button-primary dfr-btn"><?php esc_html_e('Enregistrer la surveillance', 'delicat-fazercards'); ?></button><span><?php esc_html_e('Réservé aux administrateurs ; chaque modification est journalisée.', 'delicat-fazercards'); ?></span></div>
        </form>
        <?php
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
            <?php $this->render_catalog_health_notices($settings); ?>

            <div class="dfr-stat-grid">
                <div class="dfr-stat"><span><?php esc_html_e('API', 'delicat-fazercards'); ?></span><strong id="dfr-stat-api"><?php echo $this->plugin()->api()->is_configured() ? esc_html__('Configured', 'delicat-fazercards') : esc_html__('Missing key', 'delicat-fazercards'); ?></strong><small id="dfr-stat-plan"><?php esc_html_e('Run a connection test', 'delicat-fazercards'); ?></small></div>
                <div class="dfr-stat"><span><?php esc_html_e('Supplier balance', 'delicat-fazercards'); ?></span><strong id="dfr-stat-balance">—</strong><small id="dfr-balance-freshness"><?php esc_html_e('Automatic refresh · 10 seconds', 'delicat-fazercards'); ?></small></div>
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
                        <p><?php esc_html_e('Fast sync runs in rotating batches. Timing depends on the background runner, catalog size and supplier availability. Check the webhook delivery test for immediate order updates.', 'delicat-fazercards'); ?></p>
                        <?php $this->toggle('catalog_auto_sync', __('Automatic supplier cost & stock sync', 'delicat-fazercards'), __('Manual pricing mode updates supplier cost and stock only; your customer-facing WooCommerce prices remain untouched.', 'delicat-fazercards'), $settings); ?>
                    </div>
                    <label class="dfr-field dfr-field-small"><span><?php esc_html_e('Automatic sync interval', 'delicat-fazercards'); ?></span><select name="catalog_sync_interval"><option value="dfr_30_seconds" <?php selected($settings['catalog_sync_interval'],'dfr_30_seconds'); ?>>30 seconds (fast sync)</option><option value="dfr_15_minutes" <?php selected($settings['catalog_sync_interval'],'dfr_15_minutes'); ?>>15 minutes</option><option value="hourly" <?php selected($settings['catalog_sync_interval'],'hourly'); ?>>Hourly</option><option value="twicedaily" <?php selected($settings['catalog_sync_interval'],'twicedaily'); ?>>Twice daily</option><option value="daily" <?php selected($settings['catalog_sync_interval'],'daily'); ?>>Daily</option></select></label>
                    <div class="dfr-save-row"><button class="button button-primary dfr-btn"><?php esc_html_e('Save pricing protection', 'delicat-fazercards'); ?></button><span><?php esc_html_e('Last sync:', 'delicat-fazercards'); ?> <?php echo esc_html(get_option('dfr_catalog_last_sync', '—')); ?></span></div>
                </form>
                <?php $this->render_margin_guard_form(); ?>
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
                            <?php if (!empty($uid_diag['provider_hint'])): ?><code><?php echo esc_html('hint=' . sanitize_key((string) $uid_diag['provider_hint'])); ?></code><?php endif; ?>
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
