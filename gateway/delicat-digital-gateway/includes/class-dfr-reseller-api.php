<?php
if (!defined('ABSPATH')) { exit; }

final class DFR_Reseller_API {
    const NS = 'delicat-reseller/v1';
    const SCHEMA_VERSION = '1.7';
    const PLAN = 'gold';

    private static $instance;
    private $clients_table;
    private $orders_table;
    private $plan_events_table;

    public static function instance() {
        if (!self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->clients_table = $wpdb->prefix . 'dfr_api_clients';
        $this->orders_table = $wpdb->prefix . 'dfr_api_orders';
        $this->plan_events_table = $wpdb->prefix . 'dfr_api_plan_events';
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $clients = $wpdb->prefix . 'dfr_api_clients';
        $orders = $wpdb->prefix . 'dfr_api_orders';
        $events = $wpdb->prefix . 'dfr_api_plan_events';

        dbDelta("CREATE TABLE {$clients} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            label varchar(120) NOT NULL DEFAULT 'Default',
            key_prefix varchar(24) NOT NULL,
            key_hash char(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            rate_limit_hour int unsigned NOT NULL DEFAULT 120,
            rate_window varchar(10) NULL,
            rate_count int unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            last_used_at datetime NULL,
            allowed_host varchar(255) NULL,
            install_secret_hash char(64) NULL,
            install_secret_enc longtext NULL,
            bound_at datetime NULL,
            client_type varchar(20) NOT NULL DEFAULT 'wordpress',
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            UNIQUE KEY key_hash (key_hash),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$orders} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            public_id varchar(48) NOT NULL,
            user_id bigint unsigned NOT NULL,
            idempotency_key varchar(128) NOT NULL,
            request_fingerprint char(64) NULL,
            product_id bigint unsigned NOT NULL,
            variation_id bigint unsigned NOT NULL DEFAULT 0,
            sku varchar(100) NOT NULL,
            quantity int unsigned NOT NULL DEFAULT 1,
            amount decimal(20,6) NOT NULL DEFAULT 0,
            currency varchar(8) NOT NULL DEFAULT 'HTG',
            status varchar(32) NOT NULL DEFAULT 'created',
            remote_order_id varchar(64) NULL,
            remote_status varchar(32) NULL,
            request_json longtext NULL,
            result_enc longtext NULL,
            wallet_debit_ref varchar(120) NULL,
            wallet_refund_ref varchar(120) NULL,
            woo_order_id bigint unsigned NULL,
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY user_idempotency (user_id,idempotency_key),
            KEY remote_order_id (remote_order_id),
            KEY woo_order_id (woo_order_id),
            KEY user_created (user_id,created_at),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            event varchar(40) NOT NULL,
            amount decimal(20,6) NOT NULL DEFAULT 0,
            currency varchar(8) NOT NULL DEFAULT 'HTG',
            wallet_ref varchar(120) NULL,
            period_start datetime NULL,
            period_end datetime NULL,
            note varchar(255) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_created (user_id,created_at),
            KEY event (event)
        ) {$charset};");

        update_option('dfr_reseller_schema_version', self::SCHEMA_VERSION, false);
        add_rewrite_endpoint('reseller-api', EP_ROOT | EP_PAGES);
        if (!wp_next_scheduled('dfr_gold_plan_renewal')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'dfr_gold_plan_renewal');
        }
        flush_rewrite_rules(false);
    }

    public static function deactivate() {
        foreach (array('dfr_gold_plan_renewal','dfr_reseller_poll_order','dfr_reseller_retry_supplier') as $hook) {
            wp_clear_scheduled_hook($hook);
            if (function_exists('as_unschedule_all_actions') && did_action('action_scheduler_init')) {
                as_unschedule_all_actions($hook, array(), 'delicat-fazercards');
            }
        }
    }

    public function boot() {
        if ((string) get_option('dfr_reseller_schema_version', '') !== self::SCHEMA_VERSION) {
            self::activate();
        } elseif (!wp_next_scheduled('dfr_gold_plan_renewal')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'dfr_gold_plan_renewal');
        }

        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_post_dispatch', array($this, 'protect_api_response_cache'), 10, 3);
        add_action('admin_menu', array($this, 'admin_menu'), 30);
        add_action('admin_post_dfr_api_client_action', array($this, 'admin_client_action'));
        add_action('admin_post_dfr_reseller_settings', array($this, 'save_admin_settings'));
        add_action('admin_post_dfr_reseller_catalog_save', array($this, 'save_reseller_catalog'));
        add_action('woocommerce_product_options_general_product_data', array($this, 'render_reseller_product_nonce'), 1);
        add_action('woocommerce_product_options_pricing', array($this, 'product_reseller_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_reseller_fields'), 20, 2);
        add_action('woocommerce_variation_options_pricing', array($this, 'variation_reseller_fields'), 20, 3);
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_reseller_fields'), 20, 2);
        add_action('dfr_remote_webhook_event', array($this, 'on_supplier_event'), 10, 3);
        add_action('dfr_reseller_poll_order', array($this, 'poll_supplier_order'), 10, 2);
        add_action('dfr_reseller_retry_supplier', array($this, 'retry_supplier_order'), 10, 1);
        add_action('dfr_reconcile_watchdog_reseller', array($this, 'reconcile_supplier_orders_watchdog'), 10, 1);
        add_action('dfr_gold_plan_renewal', array($this, 'process_gold_renewals'));
        add_action('init', array($this, 'account_endpoint'));
        add_filter('woocommerce_account_menu_items', array($this, 'account_menu'));
        add_action('woocommerce_account_reseller-api_endpoint', array($this, 'render_account_page'));
        add_action('template_redirect', array($this, 'handle_account_actions'));
    }

    public function account_endpoint() { add_rewrite_endpoint('reseller-api', EP_ROOT | EP_PAGES); }

    private function can_manage() {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    private function admin_forbidden() {
        wp_die(esc_html__('You do not have permission to manage the reseller API.', 'delicat-fazercards'), '', array('response' => 403));
    }

    public function account_menu($items) {
        if (!is_user_logged_in()) { return $items; }
        $uid = get_current_user_id();
        $logout = isset($items['customer-logout']) ? $items['customer-logout'] : null;
        unset($items['customer-logout']);
        $items['reseller-api'] = $this->is_approved($uid) ? __('API Revendeur', 'delicat-fazercards') : __('Devenir revendeur', 'delicat-fazercards');
        if ($logout !== null) { $items['customer-logout'] = $logout; }
        return $items;
    }

    private function is_approved($user_id) {
        return get_user_meta($user_id, '_dfr_reseller_approved', true) === '1' || user_can($user_id, 'manage_woocommerce') || user_can($user_id, 'manage_options');
    }

    private function pepper_hash($key) {
        return hash_hmac('sha256', (string) $key, wp_salt('auth'));
    }

    private function generate_api_key() {
        try {
            return 'dk_live_' . bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            // Fail closed: reseller API credentials authorize wallet spending, so
            // never downgrade to a weaker/unknown entropy source.
            return new WP_Error('dapi_entropy', __('Unable to generate a cryptographically secure API key on this server.', 'delicat-fazercards'));
        }
    }

    private function issue_key($user_id, $label = 'Default') {
        global $wpdb;
        if (!$this->is_approved($user_id) || get_user_meta($user_id, '_dfr_reseller_application_status', true) !== 'approved') {
            return new WP_Error('dapi_application_required', __('Reseller application must be approved before an API key can be issued.', 'delicat-fazercards'));
        }
        $raw = $this->generate_api_key();
        if (is_wp_error($raw)) { return $raw; }
        $prefix = substr($raw, 0, 18);
        $data = array(
            'user_id' => absint($user_id),
            'label' => sanitize_text_field($label),
            'key_prefix' => $prefix,
            'key_hash' => $this->pepper_hash($raw),
            'status' => 'active',
            'rate_limit_hour' => 120,
            'rate_window' => null,
            'rate_count' => 0,
            'created_at' => current_time('mysql', true),
            'last_used_at' => null,
        );
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->clients_table} WHERE user_id=%d", $user_id));
        if ($existing) {
            $ok = $wpdb->update(
                $this->clients_table,
                $data,
                array('id' => $existing),
                array('%d','%s','%s','%s','%s','%d','%s','%d','%s','%s'),
                array('%d')
            );
        } else {
            $ok = $wpdb->insert(
                $this->clients_table,
                $data,
                array('%d','%s','%s','%s','%s','%d','%s','%d','%s','%s')
            );
        }
        if ($ok === false) {
            return new WP_Error('dapi_key_store_failed', __('Could not securely store the API key.', 'delicat-fazercards'));
        }
        // Trial starts on the first successful authenticated API request, not merely
        // when a key is created. This prevents merchants from consuming a client's
        // free trial while the gateway is still disabled or before the client uses it.
        return $raw;
    }

    private function revoke_key($user_id) {
        global $wpdb;
        $wpdb->update($this->clients_table, array('status' => 'revoked'), array('user_id' => absint($user_id)), array('%s'), array('%d'));
    }

    /**
     * Keep a freshly issued reseller API key out of plaintext transients.
     *
     * WordPress transients are commonly persisted in wp_options when no external
     * object cache is configured. A raw dk_live_* credential must therefore never
     * be written to a transient. The one-time display envelope is bound to the
     * exact viewer account and expires quickly.
     */
    private function stash_one_time_api_key($transient_name, $viewer_user_id, $raw_key) {
        $viewer_user_id = absint($viewer_user_id);
        if ($viewer_user_id < 1 || !is_string($raw_key) || strpos($raw_key, 'dk_live_') !== 0) {
            return false;
        }
        $context = 'reseller-key-display|viewer:' . $viewer_user_id;
        $enc = DFR_Crypto::encrypt_context($raw_key, $context);
        if (is_wp_error($enc)) {
            return false;
        }
        return set_transient($transient_name, $enc, 120);
    }

    private function take_one_time_api_key($transient_name, $viewer_user_id) {
        $viewer_user_id = absint($viewer_user_id);
        $stored = get_transient($transient_name);
        delete_transient($transient_name);
        if ($viewer_user_id < 1 || !is_string($stored) || $stored === '') {
            return '';
        }
        $plain = DFR_Crypto::decrypt_context($stored, 'reseller-key-display|viewer:' . $viewer_user_id);
        if (!is_string($plain) || strpos($plain, 'dk_live_') !== 0 || strlen($plain) > 160) {
            return '';
        }
        return $plain;
    }



    private function stash_one_time_secret($transient_name, $viewer_user_id, $raw_secret, $prefix, $context_name) {
        $viewer_user_id = absint($viewer_user_id);
        if ($viewer_user_id < 1 || !is_string($raw_secret) || strpos($raw_secret, $prefix) !== 0) { return false; }
        $context = $context_name . '|viewer:' . $viewer_user_id;
        $enc = DFR_Crypto::encrypt_context($raw_secret, $context);
        if (is_wp_error($enc)) { return false; }
        return set_transient($transient_name, $enc, 120);
    }

    private function take_one_time_secret($transient_name, $viewer_user_id, $prefix, $context_name) {
        $viewer_user_id = absint($viewer_user_id);
        $stored = get_transient($transient_name);
        delete_transient($transient_name);
        if ($viewer_user_id < 1 || !is_string($stored) || $stored === '') { return ''; }
        $plain = DFR_Crypto::decrypt_context($stored, $context_name . '|viewer:' . $viewer_user_id);
        if (!is_string($plain) || strpos($plain, $prefix) !== 0 || strlen($plain) > 200) { return ''; }
        return $plain;
    }

    private function generate_external_signing_secret() {
        try { return 'ds_live_' . bin2hex(random_bytes(32)); }
        catch (Throwable $e) { return new WP_Error('dapi_entropy', 'Unable to generate a secure external signing secret.'); }
    }

    private function issue_external_signing_secret($user_id) {
        global $wpdb;
        $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->clients_table} WHERE user_id=%d", absint($user_id)));
        if (!$client || !$this->is_approved($user_id) || get_user_meta($user_id, '_dfr_reseller_application_status', true) !== 'approved') {
            return new WP_Error('dapi_application_required','Approve the reseller application and issue the API key first.');
        }
        if (empty($client->allowed_host)) { return new WP_Error('dapi_site_unbound','Assign the authorized website first.'); }
        $secret = $this->generate_external_signing_secret();
        if (is_wp_error($secret)) { return $secret; }
        $hash = hash_hmac('sha256', $secret, wp_salt('secure_auth'));
        $enc = DFR_Crypto::encrypt_context($secret, 'reseller-install|' . absint($client->id) . '|' . $client->allowed_host);
        if (is_wp_error($enc)) { return $enc; }
        $ok = $wpdb->update($this->clients_table, array(
            'client_type'=>'external', 'install_secret_hash'=>$hash, 'install_secret_enc'=>$enc, 'bound_at'=>current_time('mysql', true)
        ), array('id'=>absint($client->id)), array('%s','%s','%s','%s'), array('%d'));
        if ($ok === false) { return new WP_Error('dapi_secret_store','Could not store the external signing secret.'); }
        return $secret;
    }

    private function normalize_site_host($value) {
        $value = trim((string) $value);
        if ($value === '') { return ''; }
        if (strpos($value, '://') === false) { $value = 'https://' . $value; }
        $host = strtolower((string) wp_parse_url($value, PHP_URL_HOST));
        $host = rtrim($host, '.');
        if ($host === '' || strlen($host) > 253 || !preg_match('/^[a-z0-9.-]+$/', $host)) { return ''; }
        return $host;
    }

    private function client_signature_payload(WP_REST_Request $request, $timestamp) {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $parts = wp_parse_url($uri);
        $path = isset($parts['path']) ? $parts['path'] : $request->get_route();
        $query = isset($parts['query']) ? $parts['query'] : '';
        $target = $path . ($query !== '' ? '?' . $query : '');
        $request_id = strtolower(trim((string)$request->get_header('x-delicat-request')));
        return strtoupper($request->get_method()) . "\n" . $target . "\n" . (string) $timestamp . "\n" . $request_id . "\n" . hash('sha256', (string) $request->get_body());
    }

    /** Atomically claim a signed request id so parallel replays cannot both pass. */
    private function claim_signed_client_request($client_id, $request_id) {
        global $wpdb;
        $client_id = absint($client_id);
        $request_id = strtolower(trim((string) $request_id));
        if (!$client_id || !preg_match('/^[a-f0-9]{32}$/', $request_id)) { return false; }
        $replay_key = 'dfr_req_' . $client_id . '_' . $request_id;
        $lock_name = substr('dfr_req_lock_' . $client_id . '_' . $request_id, 0, 64);
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) === '1';
        if (!$locked) { return new WP_Error('dapi_replay_busy', 'Signed request verification is busy.', array('status'=>503)); }
        try {
            if (get_transient($replay_key)) { return false; }
            if (!set_transient($replay_key, '1', 10 * MINUTE_IN_SECONDS)) {
                return new WP_Error('dapi_replay_store', 'Signed request replay protection is unavailable.', array('status'=>503));
            }
            return true;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    private function bind_or_verify_site_installation($client, WP_REST_Request $request) {
        global $wpdb;
        $site = $this->normalize_site_host($request->get_header('x-delicat-site'));
        $instance = trim((string) $request->get_header('x-delicat-instance'));
        if (!$client->allowed_host) {
            return new WP_Error('dapi_site_unbound', 'This API key has not been assigned to a website yet.', array('status'=>403));
        }
        if (!$site || !hash_equals((string)$client->allowed_host, $site)) {
            return new WP_Error('dapi_site_forbidden', 'This API key is not authorized for this website.', array('status'=>403));
        }
        $client_type = !empty($client->client_type) ? sanitize_key((string)$client->client_type) : 'wordpress';
        if ($client_type === 'external') {
            $ts = trim((string)$request->get_header('x-delicat-timestamp'));
            $sig = strtolower(trim((string)$request->get_header('x-delicat-signature')));
            $request_id = strtolower(trim((string)$request->get_header('x-delicat-request')));
            if (!ctype_digit($ts) || abs(time() - (int)$ts) > 300 || !preg_match('/^[a-f0-9]{64}$/',$sig) || !preg_match('/^[a-f0-9]{32}$/',$request_id)) {
                return new WP_Error('dapi_signature_required','A fresh signed external request is required.',array('status'=>401));
            }
            if (empty($client->install_secret_hash) || empty($client->install_secret_enc)) {
                return new WP_Error('dapi_external_secret_required','This external client has no signing secret assigned.',array('status'=>403));
            }
            $secret = DFR_Crypto::decrypt_context((string)$client->install_secret_enc, 'reseller-install|' . absint($client->id) . '|' . $site);
            if (!$secret || strpos($secret,'ds_live_') !== 0 || !hash_equals((string)$client->install_secret_hash, hash_hmac('sha256',$secret,wp_salt('secure_auth')))) {
                return new WP_Error('dapi_binding_corrupt','External website signing configuration requires administrator repair.',array('status'=>503));
            }
            $expected = hash_hmac('sha256', $this->client_signature_payload($request, $ts), $secret);
            if (!hash_equals($expected,$sig)) { return new WP_Error('dapi_bad_signature','Website request signature is invalid.',array('status'=>401)); }
            $claim = $this->claim_signed_client_request($client->id, $request_id);
            if (is_wp_error($claim)) { return $claim; }
            if (!$claim) { return new WP_Error('dapi_replay','This signed website request has already been used.',array('status'=>409)); }
            return true;
        }
        if (empty($client->install_secret_hash)) {
            // First binding only: the WordPress connector proves that its installation
            // secret actually lives on the administrator-approved HTTPS website.
            if (!preg_match('/^di_[a-f0-9]{64}$/', $instance)) {
                return new WP_Error('dapi_instance_required', 'A valid Delicat site installation credential is required for first binding.', array('status'=>401));
            }
            $ihash = hash_hmac('sha256', $instance, wp_salt('secure_auth'));
            try { $challenge = bin2hex(random_bytes(24)); } catch (Throwable $e) { return new WP_Error('dapi_binding_failed','Secure website challenge is unavailable.',array('status'=>503)); }
            $proof_url = 'https://' . $site . '/wp-json/delicat-client/v1/site-proof?challenge=' . rawurlencode($challenge);
            $proof_res = wp_safe_remote_get($proof_url, array('timeout'=>12,'redirection'=>2,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>65536));
            if (is_wp_error($proof_res) || (int)wp_remote_retrieve_response_code($proof_res) !== 200) {
                return new WP_Error('dapi_site_proof_failed','The authorized website could not prove ownership of this API installation.',array('status'=>403));
            }
            $proof_data = json_decode((string)wp_remote_retrieve_body($proof_res), true);
            $expected_proof = hash_hmac('sha256', $challenge . '|' . $site, $instance);
            if (!is_array($proof_data) || empty($proof_data['proof']) || !hash_equals($expected_proof, strtolower((string)$proof_data['proof']))) {
                return new WP_Error('dapi_site_proof_failed','The authorized website returned an invalid installation proof.',array('status'=>403));
            }
            $enc = DFR_Crypto::encrypt_context($instance, 'reseller-install|' . absint($client->id) . '|' . $site);
            if (is_wp_error($enc)) {
                return new WP_Error('dapi_binding_failed','Secure website binding is unavailable.',array('status'=>503));
            }
            $ok = $wpdb->update($this->clients_table, array(
                'install_secret_hash'=>$ihash,
                'install_secret_enc'=>$enc,
                'bound_at'=>current_time('mysql', true),
            ), array('id'=>absint($client->id), 'install_secret_hash'=>null), array('%s','%s','%s'), array('%d','%s'));
            if ($ok !== 1) {
                $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->clients_table} WHERE id=%d", absint($client->id)));
                if (!$fresh || empty($fresh->install_secret_hash) || !hash_equals((string)$fresh->install_secret_hash, $ihash)) {
                    return new WP_Error('dapi_binding_race','This API key was bound by another installation at the same time.',array('status'=>409));
                }
            }
            return true;
        }

        // After first binding, never require the raw installation secret to travel in
        // every HTTP request. The client proves possession by HMAC; the server decrypts
        // its local copy and verifies a fresh timestamp/request-id/body signature.
        $ts = trim((string)$request->get_header('x-delicat-timestamp'));
        $sig = strtolower(trim((string)$request->get_header('x-delicat-signature')));
        $request_id = strtolower(trim((string)$request->get_header('x-delicat-request')));
        if (!ctype_digit($ts) || abs(time() - (int)$ts) > 300 || !preg_match('/^[a-f0-9]{64}$/',$sig) || !preg_match('/^[a-f0-9]{32}$/',$request_id)) {
            return new WP_Error('dapi_signature_required','A fresh signed website request is required.',array('status'=>401));
        }
        $secret = DFR_Crypto::decrypt_context((string)$client->install_secret_enc, 'reseller-install|' . absint($client->id) . '|' . $site);
        $secret_hash = $secret ? hash_hmac('sha256', $secret, wp_salt('secure_auth')) : '';
        if (!$secret || !hash_equals((string)$client->install_secret_hash, $secret_hash)) {
            return new WP_Error('dapi_binding_corrupt','Website binding requires administrator repair.',array('status'=>503));
        }
        // If an older connector still sends X-Delicat-Instance, verify it but do not
        // require it. This is backwards-compatible while reducing secret exposure for
        // upgraded connectors.
        if ($instance !== '' && (!preg_match('/^di_[a-f0-9]{64}$/', $instance) || !hash_equals((string)$client->install_secret_hash, hash_hmac('sha256', $instance, wp_salt('secure_auth'))))) {
            return new WP_Error('dapi_instance_forbidden','The supplied installation credential does not match this API binding.',array('status'=>403));
        }
        $expected = hash_hmac('sha256', $this->client_signature_payload($request, $ts), $secret);
        if (!hash_equals($expected, $sig)) {
            return new WP_Error('dapi_bad_signature','Website request signature is invalid.',array('status'=>401));
        }
        $claim = $this->claim_signed_client_request($client->id, $request_id);
        if (is_wp_error($claim)) { return $claim; }
        if (!$claim) { return new WP_Error('dapi_replay','This signed website request has already been used.',array('status'=>409)); }
        return true;
    }

    private function reseller_product_enabled($product_id) {
        return get_post_meta(absint($product_id), '_dfr_reseller_enabled', true) === '1';
    }

    private function reseller_product_price($product_id) {
        $v = (float) get_post_meta(absint($product_id), '_dfr_reseller_price', true);
        return $v > 0 ? $v : 0.0;
    }

    public function render_reseller_product_nonce() {
        if (!current_user_can('manage_woocommerce')) { return; }
        wp_nonce_field('dfr_save_reseller_product', 'dfr_reseller_product_nonce', false);
    }

    private function reseller_product_nonce_valid() {
        $nonce = isset($_POST['dfr_reseller_product_nonce']) ? sanitize_text_field(wp_unslash($_POST['dfr_reseller_product_nonce'])) : '';
        return $nonce !== '' && wp_verify_nonce($nonce, 'dfr_save_reseller_product');
    }

    public function product_reseller_fields() {
        global $post;
        if (!$post || get_post_meta($post->ID, '_dfr_imported', true) !== '1') { return; }
        echo '<div class="options_group"><p style="padding:8px 12px 0;font-weight:700">Delicat Reseller API</p>';
        woocommerce_wp_checkbox(array('id'=>'_dfr_reseller_enabled','label'=>'Allow reseller API','description'=>'Only enabled products are exposed to reseller clients.'));
        woocommerce_wp_text_input(array('id'=>'_dfr_reseller_price','label'=>'Reseller price (HTG)','type'=>'number','custom_attributes'=>array('step'=>'0.01','min'=>'0'),'description'=>'Private wholesale price charged from the reseller Delicat Wallet. It does not change your public WooCommerce price.'));
        echo '</div>';
    }

    public function save_product_reseller_fields($post_id, $post = null) {
        if (!current_user_can('manage_woocommerce') || !$this->reseller_product_nonce_valid() || get_post_meta($post_id, '_dfr_imported', true) !== '1') { return; }
        update_post_meta($post_id, '_dfr_reseller_enabled', !empty($_POST['_dfr_reseller_enabled']) ? '1' : '0');
        $price = isset($_POST['_dfr_reseller_price']) ? max(0, (float) wc_format_decimal(wp_unslash($_POST['_dfr_reseller_price']))) : 0;
        update_post_meta($post_id, '_dfr_reseller_price', wc_format_decimal($price, 2));
    }

    public function variation_reseller_fields($loop, $variation_data, $variation) {
        $id = absint($variation->ID);
        if (get_post_meta($id, '_dfr_imported', true) !== '1') { return; }
        woocommerce_wp_checkbox(array('id'=>'_dfr_reseller_enabled['.$loop.']','name'=>'_dfr_reseller_enabled['.$loop.']','value'=>get_post_meta($id,'_dfr_reseller_enabled',true),'label'=>'Allow reseller API','wrapper_class'=>'form-row form-row-first'));
        woocommerce_wp_text_input(array('id'=>'_dfr_reseller_price['.$loop.']','name'=>'_dfr_reseller_price['.$loop.']','value'=>get_post_meta($id,'_dfr_reseller_price',true),'label'=>'Reseller price (HTG)','type'=>'number','custom_attributes'=>array('step'=>'0.01','min'=>'0'),'wrapper_class'=>'form-row form-row-last'));
    }

    public function save_variation_reseller_fields($variation_id, $loop) {
        if (!current_user_can('manage_woocommerce') || !$this->reseller_product_nonce_valid() || get_post_meta($variation_id, '_dfr_imported', true) !== '1') { return; }
        $enabled = !empty($_POST['_dfr_reseller_enabled'][$loop]) ? '1' : '0';
        $price = isset($_POST['_dfr_reseller_price'][$loop]) ? max(0, (float) wc_format_decimal(wp_unslash($_POST['_dfr_reseller_price'][$loop]))) : 0;
        update_post_meta($variation_id, '_dfr_reseller_enabled', $enabled);
        update_post_meta($variation_id, '_dfr_reseller_price', wc_format_decimal($price,2));
    }

    public function save_reseller_catalog() {
        if (!$this->can_manage()) { $this->admin_forbidden(); }
        check_admin_referer('dfr_reseller_catalog_save');
        $ids = array_values(array_unique(array_map('absint', (array)($_POST['catalog_ids'] ?? array()))));
        foreach ($ids as $id) {
            if (!$id || get_post_meta($id, '_dfr_imported', true) !== '1') { continue; }
            $enabled = !empty($_POST['enabled'][$id]) ? '1' : '0';
            $price = isset($_POST['price'][$id]) ? max(0,(float)wc_format_decimal(wp_unslash($_POST['price'][$id]))) : 0;
            update_post_meta($id, '_dfr_reseller_enabled', $enabled);
            update_post_meta($id, '_dfr_reseller_price', wc_format_decimal($price,2));
        }
        wp_safe_redirect(admin_url('admin.php?page=delicat-reseller-api&catalog_saved=1'));
        exit;
    }

    private function reseller_settings() {
        return wp_parse_args((array) get_option('dfr_reseller_settings', array()), array(
            'enabled' => '0',
            'catalog_enabled' => '1',
            'require_htg' => '1',
            'max_order_amount' => '250000',
            'daily_spend_limit' => '250000',
            'gold_plan_enabled' => '1',
            'gold_plan_required' => '1',
            'gold_trial_days' => '6',
            'gold_monthly_price' => '0',
            'gold_period_days' => '30',
        ));
    }

    public function save_admin_settings() {
        if (!$this->can_manage()) { $this->admin_forbidden(); }
        check_admin_referer('dfr_reseller_settings');
        $s = $this->reseller_settings();
        if (array_key_exists('enabled', $_POST)) { $s['enabled'] = !empty($_POST['enabled']) ? '1' : '0'; }
        if (array_key_exists('catalog_enabled', $_POST)) { $s['catalog_enabled'] = !empty($_POST['catalog_enabled']) ? '1' : '0'; }
        if (array_key_exists('require_htg', $_POST)) { $s['require_htg'] = !empty($_POST['require_htg']) ? '1' : '0'; }
        if (array_key_exists('max_order_amount', $_POST)) { $s['max_order_amount'] = (string) max(1, min(100000000, (float) wc_format_decimal(wp_unslash($_POST['max_order_amount'])))); }
        if (array_key_exists('daily_spend_limit', $_POST)) { $s['daily_spend_limit'] = (string) max(1, min(100000000, (float) wc_format_decimal(wp_unslash($_POST['daily_spend_limit'])))); }
        if (array_key_exists('gold_plan_enabled', $_POST)) { $s['gold_plan_enabled'] = !empty($_POST['gold_plan_enabled']) ? '1' : '0'; }
        if (array_key_exists('gold_plan_required', $_POST)) { $s['gold_plan_required'] = !empty($_POST['gold_plan_required']) ? '1' : '0'; }
        // The requested free-trial period is fixed at six days to avoid accidental admin changes.
        $s['gold_trial_days'] = '6';
        $s['gold_period_days'] = '30';
        if (array_key_exists('gold_monthly_price', $_POST)) { $s['gold_monthly_price'] = (string) max(0, min(100000000, (float) wc_format_decimal(wp_unslash($_POST['gold_monthly_price'])))); }
        update_option('dfr_reseller_settings', $s, false);
        wp_safe_redirect(admin_url('admin.php?page=delicat-reseller-api&settings=1'));
        exit;
    }

    public function admin_menu() {
        add_submenu_page(
            class_exists('WooCommerce') ? 'woocommerce' : 'tools.php',
            __('Delicat Reseller API', 'delicat-fazercards'),
            __('Reseller API', 'delicat-fazercards'),
            class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options',
            'delicat-reseller-api',
            array($this, 'render_admin')
        );
    }

    public function admin_client_action() {
        if (!$this->can_manage()) { $this->admin_forbidden(); }
        check_admin_referer('dfr_api_client_action');
        $user_id = absint($_POST['user_id'] ?? 0);
        $action = sanitize_key(wp_unslash($_POST['client_action'] ?? ''));
        if (!$user_id || !get_user_by('id', $user_id)) {
            wp_safe_redirect(admin_url('admin.php?page=delicat-reseller-api&msg=baduser'));
            exit;
        }

        if ($action === 'bind_site') {
            if (!$this->is_approved($user_id) || get_user_meta($user_id, '_dfr_reseller_application_status', true) !== 'approved') { set_transient('dfr_api_admin_error_' . get_current_user_id(), 'Approve the reseller application before configuring the client.', 120); wp_safe_redirect(admin_url('admin.php?page=delicat-reseller-api')); exit; }
            $host = $this->normalize_site_host(wp_unslash($_POST['authorized_site'] ?? ''));
            $client_type = sanitize_key(wp_unslash($_POST['client_type'] ?? 'wordpress'));
            if (!in_array($client_type, array('wordpress','external'), true)) { $client_type = 'wordpress'; }
            global $wpdb;
            if (!$host) {
                set_transient('dfr_api_admin_error_' . get_current_user_id(), 'Enter a valid authorized reseller website domain.', 120);
            } else {
                // Changing an authorized host invalidates the old installation/signing
                // secret. The administrator must establish a fresh proof for the new host.
                $wpdb->update($this->clients_table, array(
                    'allowed_host'=>$host,
                    'install_secret_hash'=>null,
                    'install_secret_enc'=>null,
                    'bound_at'=>null,
                    'client_type'=>$client_type,
                ), array('user_id'=>$user_id), array('%s','%s','%s','%s','%s'), array('%d'));
            }
        } elseif ($action === 'approve') {
            update_user_meta($user_id, '_dfr_reseller_application_status', 'approved');
            update_user_meta($user_id, '_dfr_reseller_application_reviewed_at', time());
            update_user_meta($user_id, '_dfr_reseller_approved', '1');
        } elseif ($action === 'reject') {
            $this->revoke_key($user_id);
            delete_user_meta($user_id, '_dfr_reseller_approved');
            update_user_meta($user_id, '_dfr_reseller_application_status', 'rejected');
            update_user_meta($user_id, '_dfr_reseller_application_reviewed_at', time());
            update_user_meta($user_id, '_dfr_reseller_application_admin_note', sanitize_textarea_field(wp_unslash($_POST['admin_note'] ?? '')));
        } elseif ($action === 'request_info') {
            $this->revoke_key($user_id);
            delete_user_meta($user_id, '_dfr_reseller_approved');
            update_user_meta($user_id, '_dfr_reseller_application_status', 'needs_info');
            update_user_meta($user_id, '_dfr_reseller_application_admin_note', sanitize_textarea_field(wp_unslash($_POST['admin_note'] ?? '')));
        } elseif ($action === 'revoke') {
            $this->revoke_key($user_id);
            delete_user_meta($user_id, '_dfr_reseller_approved');
            update_user_meta($user_id, '_dfr_reseller_application_status', 'suspended');
            update_user_meta($user_id, '_dfr_gold_auto_renew', '0');
        } elseif ($action === 'issue') {
            if (!$this->is_approved($user_id) || get_user_meta($user_id, '_dfr_reseller_application_status', true) !== 'approved') {
                set_transient('dfr_api_admin_error_' . get_current_user_id(), 'Approve the reseller application before issuing an API key.', 120);
                wp_safe_redirect(admin_url('admin.php?page=delicat-reseller-api')); exit;
            }
            $host = $this->normalize_site_host(wp_unslash($_POST['authorized_site'] ?? ''));
            $client_type = sanitize_key(wp_unslash($_POST['client_type'] ?? 'wordpress'));
            if (!in_array($client_type, array('wordpress','external'), true)) { $client_type = 'wordpress'; }
            if (!$host) {
                set_transient('dfr_api_admin_error_' . get_current_user_id(), 'A valid authorized website is required before issuing an API key.', 120);
                wp_safe_redirect(admin_url('admin.php?page=delicat-reseller-api')); exit;
            }
            global $wpdb;
            $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->clients_table} WHERE user_id=%d", $user_id));
            $raw = $this->issue_key($user_id, 'Admin issued');
            if (!is_wp_error($raw)) {
                $same_binding = $before && (string)$before->allowed_host === $host && (string)$before->client_type === $client_type;
                if ($same_binding) {
                    // Rotating only the bearer key must not silently destroy an already
                    // established second-factor installation/signing secret.
                    $wpdb->update($this->clients_table, array('allowed_host'=>$host,'client_type'=>$client_type), array('user_id'=>$user_id), array('%s','%s'), array('%d'));
                } else {
                    $wpdb->update($this->clients_table, array('allowed_host'=>$host,'install_secret_hash'=>null,'install_secret_enc'=>null,'bound_at'=>null,'client_type'=>$client_type), array('user_id'=>$user_id), array('%s','%s','%s','%s','%s'), array('%d'));
                }
            }
            if (is_wp_error($raw)) {
                set_transient('dfr_api_admin_error_' . get_current_user_id(), $raw->get_error_message(), 120);
            } else {
                $viewer_id = get_current_user_id();
                if (!$this->stash_one_time_api_key('dfr_new_api_key_' . $viewer_id, $viewer_id, $raw)) {
                    set_transient('dfr_api_admin_error_' . $viewer_id, __('API key created, but secure one-time display storage was unavailable. Rotate the key and copy it immediately after fixing server encryption support.', 'delicat-fazercards'), 120);
                }
            }
        } elseif ($action === 'external_secret') {
            $secret = $this->issue_external_signing_secret($user_id);
            if (is_wp_error($secret)) { set_transient('dfr_api_admin_error_' . get_current_user_id(), $secret->get_error_message(), 120); }
            else { $viewer_id=get_current_user_id(); if (!$this->stash_one_time_secret('dfr_new_signing_secret_'.$viewer_id,$viewer_id,$secret,'ds_live_','reseller-signing-display')) { set_transient('dfr_api_admin_error_'.$viewer_id,'Signing secret created but secure one-time display storage failed. Rotate it after fixing server encryption.',120); } }
        } elseif ($action === 'trial') {
            if (!$this->is_approved($user_id)) { set_transient('dfr_api_admin_error_' . get_current_user_id(), 'Approve the application first.', 120); wp_safe_redirect(admin_url('admin.php?page=delicat-reseller-api')); exit; }
            $trial = $this->start_gold_trial($user_id, 'admin_started');
            if (is_wp_error($trial)) {
                set_transient('dfr_api_admin_error_' . get_current_user_id(), $trial->get_error_message(), 120);
            }
        } elseif ($action === 'grant_gold') {
            if (!$this->is_approved($user_id)) { set_transient('dfr_api_admin_error_' . get_current_user_id(), 'Approve the application first.', 120); wp_safe_redirect(admin_url('admin.php?page=delicat-reseller-api')); exit; }
            $this->grant_gold($user_id, 'Admin granted 30 days');
        } elseif ($action === 'cancel_renewal') {
            update_user_meta($user_id, '_dfr_gold_auto_renew', '0');
            $this->log_plan_event($user_id, 'auto_renew_disabled', 0, '', 0, 0, 'Disabled by administrator');
        }

        wp_safe_redirect(admin_url('admin.php?page=delicat-reseller-api&updated=1'));
        exit;
    }

    public function render_admin() {
        if (!$this->can_manage()) { return; }
        global $wpdb;
        $viewer_id = get_current_user_id();
        $new_key = $this->take_one_time_api_key('dfr_new_api_key_' . $viewer_id, $viewer_id);
        $new_signing_secret = $this->take_one_time_secret('dfr_new_signing_secret_' . $viewer_id, $viewer_id, 'ds_live_', 'reseller-signing-display');
        $admin_error = get_transient('dfr_api_admin_error_' . get_current_user_id());
        if ($admin_error) { delete_transient('dfr_api_admin_error_' . get_current_user_id()); }
        $clients = $wpdb->get_results("SELECT c.*,u.user_login,u.user_email FROM {$this->clients_table} c LEFT JOIN {$wpdb->users} u ON u.ID=c.user_id ORDER BY c.id DESC LIMIT 100");
        $orders = $wpdb->get_results("SELECT * FROM {$this->orders_table} ORDER BY id DESC LIMIT 20");
        $wallet_reconcile_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->orders_table} WHERE wallet_debit_ref LIKE 'PENDING:%' OR wallet_refund_ref LIKE 'PENDING:%'");
        $gold_reconcile_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->plan_events_table} WHERE event='gold_debit_pending'");
        $gold_pending = $gold_reconcile_count > 0 ? $wpdb->get_results("SELECT * FROM {$this->plan_events_table} WHERE event='gold_debit_pending' ORDER BY id DESC LIMIT 20") : array();
        $wallet_ok = $this->wallet_available();
        $application_users = get_users(array('meta_key'=>'_dfr_reseller_application_status','meta_compare'=>'EXISTS','number'=>200,'orderby'=>'registered','order'=>'DESC'));
        $rs = $this->reseller_settings();
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG';
        $rq = new WP_Query(array(
            'post_type'=>array('product','product_variation'),
            'post_status'=>array('publish','draft','private'),
            'posts_per_page'=>300,
            'fields'=>'ids',
            'meta_key'=>'_dfr_imported',
            'meta_value'=>'1',
            'orderby'=>'ID',
            'order'=>'ASC',
        ));
        $reseller_products = array();
        foreach ((array)$rq->posts as $rid) {
            $rp = wc_get_product($rid);
            if (!$rp || $rp->is_type('variable')) { continue; }
            $rname = $rp->get_name();
            if ($rp->is_type('variation') && $rp->get_parent_id()) {
                $parentp = wc_get_product($rp->get_parent_id());
                if ($parentp) { $rname = $parentp->get_name() . ' — ' . wc_get_formatted_variation($rp, true, false, true); }
            }
            $reseller_products[] = array(
                'id'=>$rid,
                'name'=>wp_strip_all_tags($rname),
                'retail'=>(float)$rp->get_price('edit'),
                'reseller'=>$this->reseller_product_price($rid),
                'enabled'=>$this->reseller_product_enabled($rid),
                'type'=>(string)get_post_meta($rid,'_dfr_service_type',true),
                'status'=>$rp->get_status(),
            );
        }
        ?>
        <div class="wrap dfr-api-wrap">
        <style>
        .dfr-api-wrap{max-width:1280px}.dfr-api-hero{background:linear-gradient(135deg,#0b1023,#2b1760);color:#fff;border-radius:24px;padding:30px;margin:18px 0;box-shadow:0 18px 50px rgba(20,20,50,.16);position:relative;overflow:hidden}.dfr-api-hero:after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;background:rgba(124,58,237,.28);filter:blur(20px);right:-90px;top:-120px}.dfr-api-hero h1{color:#fff;margin:7px 0 8px;font-size:31px}.dfr-api-hero p{font-size:15px;max-width:800px}.dfr-api-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0}.dfr-api-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.dfr-api-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:20px;box-shadow:0 8px 28px rgba(20,25,45,.06)}.dfr-api-card h2{margin-top:0}.dfr-api-card b.metric{font-size:22px;display:block;margin-top:6px}.dfr-pill{display:inline-flex;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#4338ca;font-weight:700}.dfr-good{background:#ecfdf5;color:#047857}.dfr-bad{background:#fff1f2;color:#be123c}.dfr-gold{background:linear-gradient(135deg,#fff7d6,#f4d36d);color:#684a00;border:1px solid #e7c456}.dfr-key{font-family:monospace;background:#101827;color:#fff;padding:14px;border-radius:12px;word-break:break-all}.dfr-table{width:100%;border-collapse:collapse}.dfr-table th,.dfr-table td{padding:12px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}.dfr-doc{background:#f8fafc;border-radius:12px;padding:14px;font-family:monospace;white-space:pre-wrap}.dfr-formrow{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.dfr-formrow label{display:grid;gap:6px}.dfr-plan-card{background:linear-gradient(145deg,#17120a,#2e2208);color:#fff;border-color:#4a360b;position:relative;overflow:hidden}.dfr-plan-card h2,.dfr-plan-card h3{color:#fff}.dfr-plan-card:after{content:"GOLD";position:absolute;right:-14px;top:5px;font-weight:900;font-size:72px;color:rgba(255,215,99,.08);transform:rotate(8deg)}.dfr-plan-stat{font-size:28px;font-weight:850;color:#ffd965}.dfr-muted{color:#64748b}.dfr-plan-card .dfr-muted{color:#d6cda8}@media(max-width:900px){.dfr-api-grid,.dfr-api-grid.two{grid-template-columns:1fr}.dfr-table{display:block;overflow:auto}}
        </style>
        <div class="dfr-api-hero"><span class="dfr-pill">Delicat Store</span><h1>Reseller API Gateway</h1><p>Wallet-funded white-label API. Clients pay in your WooCommerce currency while the upstream settlement layer remains private.</p></div>
        <?php if ($admin_error): ?><div class="notice notice-error"><p><?php echo esc_html($admin_error); ?></p></div><?php endif; ?>
        <?php if ($wallet_reconcile_count > 0): ?><div class="notice notice-warning"><p><strong><?php echo esc_html(sprintf(_n('%d reseller wallet operation requires reconciliation.', '%d reseller wallet operations require reconciliation.', $wallet_reconcile_count, 'delicat-fazercards'), $wallet_reconcile_count)); ?></strong> <?php echo esc_html__('Compare the affected API order with the TeraWallet ledger before any manual retry or refund.', 'delicat-fazercards'); ?></p></div><?php endif; ?>
        <?php if ($gold_reconcile_count > 0): ?><div class="notice notice-warning"><p><strong><?php echo esc_html(sprintf(_n('%d Gold-plan wallet operation requires reconciliation.', '%d Gold-plan wallet operations require reconciliation.', $gold_reconcile_count, 'delicat-fazercards'), $gold_reconcile_count)); ?></strong> <?php echo esc_html__('Do not manually charge the customer again. Compare the pending Gold journal with the TeraWallet ledger; confirmed debits are recovered without a second charge.', 'delicat-fazercards'); ?></p></div><?php endif; ?>
        <?php if ($new_key): ?><div class="notice notice-success"><p><strong>Copy this API key now. It will not be shown again.</strong></p><div class="dfr-key"><?php echo esc_html($new_key); ?></div></div><?php endif; ?>
        <?php if ($new_signing_secret): ?><div class="notice notice-success"><p><strong>External website signing secret — copy it now. It will not be shown again.</strong></p><div class="dfr-key"><?php echo esc_html($new_signing_secret); ?></div></div><?php endif; ?>

        <div class="dfr-api-grid two">
          <div class="dfr-api-card"><h2>Gateway controls</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dfr-formrow"><?php wp_nonce_field('dfr_reseller_settings'); ?><input type="hidden" name="action" value="dfr_reseller_settings"><label><span>Reseller API</span><select name="enabled"><option value="0" <?php selected($rs['enabled'],'0'); ?>>Disabled — safe default</option><option value="1" <?php selected($rs['enabled'],'1'); ?>>Enabled</option></select></label><label><span>Catalog</span><select name="catalog_enabled"><option value="1" <?php selected($rs['catalog_enabled'],'1'); ?>>Published imported products</option><option value="0" <?php selected($rs['catalog_enabled'],'0'); ?>>Hidden</option></select></label><label><span>Currency guard</span><select name="require_htg"><option value="1" <?php selected($rs['require_htg'],'1'); ?>>Require HTG</option><option value="0" <?php selected($rs['require_htg'],'0'); ?>>Use WooCommerce currency</option></select></label><label><span>Max product order</span><input type="number" name="max_order_amount" min="1" step="1" value="<?php echo esc_attr($rs['max_order_amount']); ?>"></label><label><span>Daily spend limit / reseller</span><input type="number" name="daily_spend_limit" min="1" step="1" value="<?php echo esc_attr($rs['daily_spend_limit']); ?>"></label><button class="button button-primary">Save settings</button></form></div>
          <div class="dfr-api-card dfr-plan-card"><span class="dfr-pill dfr-gold">Gold API Plan</span><h2>6-day free trial</h2><div class="dfr-plan-stat"><?php echo esc_html($rs['gold_monthly_price'] > 0 ? wc_price((float)$rs['gold_monthly_price'], array('currency'=>$currency)) : 'Price not set'); ?></div><p class="dfr-muted">30 days per paid period. Trial is one-time per approved user. API keys never restart the trial.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dfr-formrow"><?php wp_nonce_field('dfr_reseller_settings'); ?><input type="hidden" name="action" value="dfr_reseller_settings"><input type="hidden" name="enabled" value="<?php echo esc_attr($rs['enabled']); ?>"><input type="hidden" name="catalog_enabled" value="<?php echo esc_attr($rs['catalog_enabled']); ?>"><input type="hidden" name="require_htg" value="<?php echo esc_attr($rs['require_htg']); ?>"><input type="hidden" name="max_order_amount" value="<?php echo esc_attr($rs['max_order_amount']); ?>"><input type="hidden" name="daily_spend_limit" value="<?php echo esc_attr($rs['daily_spend_limit']); ?>"><label><span>Gold plan</span><select name="gold_plan_enabled"><option value="1" <?php selected($rs['gold_plan_enabled'],'1'); ?>>Enabled</option><option value="0" <?php selected($rs['gold_plan_enabled'],'0'); ?>>Disabled</option></select></label><label><span>Require active Gold/trial</span><select name="gold_plan_required"><option value="1" <?php selected($rs['gold_plan_required'],'1'); ?>>Required for catalog + orders</option><option value="0" <?php selected($rs['gold_plan_required'],'0'); ?>>Not required</option></select></label><label><span>Monthly price (<?php echo esc_html($currency); ?>)</span><input type="number" name="gold_monthly_price" min="0" step="0.01" value="<?php echo esc_attr($rs['gold_monthly_price']); ?>"></label><button class="button button-primary">Save Gold plan</button></form></div>
        </div>

        <div class="dfr-api-grid"><div class="dfr-api-card">Wallet engine<b class="metric"><?php echo $wallet_ok ? '<span class="dfr-pill dfr-good">Connected</span>' : '<span class="dfr-pill dfr-bad">Not detected</span>'; ?></b></div><div class="dfr-api-card">API base<b class="metric" style="font-size:14px"><?php echo esc_html(rest_url(self::NS)); ?></b></div><div class="dfr-api-card">Store currency<b class="metric"><?php echo esc_html($currency); ?></b></div></div>

        <div class="dfr-api-card"><h2>Reseller applications</h2><p class="dfr-muted">API keys cannot be issued until an application is explicitly approved by a Delicat Administrator.</p><table class="dfr-table"><thead><tr><th>Applicant</th><th>Business / website</th><th>Request</th><th>Status</th><th>Review</th></tr></thead><tbody><?php foreach($application_users as $au): $ast=(string)get_user_meta($au->ID,'_dfr_reseller_application_status',true); $aa=(array)get_user_meta($au->ID,'_dfr_reseller_application',true); ?><tr><td><strong><?php echo esc_html($au->display_name); ?></strong><br><small><?php echo esc_html($au->user_email); ?> · #<?php echo absint($au->ID); ?></small></td><td><?php echo esc_html($aa['business_name']??'—'); ?><br><code><?php echo esc_html($aa['website']??'—'); ?></code><br><small><?php echo esc_html(($aa['phone']??'').' '.($aa['country']??'')); ?></small></td><td><strong>Products:</strong> <?php echo esc_html($aa['requested_products']??'—'); ?><br><small><?php echo esc_html($aa['monthly_volume']??''); ?></small></td><td><span class="dfr-pill <?php echo $ast==='approved'?'dfr-good':''; ?>"><?php echo esc_html(ucwords(str_replace('_',' ',$ast))); ?></span></td><td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('dfr_api_client_action'); ?><input type="hidden" name="action" value="dfr_api_client_action"><input type="hidden" name="user_id" value="<?php echo absint($au->ID); ?>"><input type="text" name="admin_note" placeholder="Optional note" style="max-width:180px"><select name="client_action"><option value="approve">Approve application</option><option value="request_info">Request information</option><option value="reject">Reject</option><option value="revoke">Suspend API access</option></select><button class="button">Apply</button></form></td></tr><?php endforeach; ?></tbody></table></div>

        <div class="dfr-api-card"><h2>Approved reseller API management</h2><p class="dfr-muted"><strong>Strong authentication:</strong> every API request requires the API key plus an administrator-authorized website and a fresh HMAC-signed request. External clients also need a one-time <code>ds_live_…</code> signing secret.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dfr-formrow"><?php wp_nonce_field('dfr_api_client_action'); ?><input type="hidden" name="action" value="dfr_api_client_action"><label>WordPress User ID<input type="number" name="user_id" min="1" required></label><label>Client type<select name="client_type"><option value="wordpress">WordPress connector</option><option value="external">External/server connector</option></select></label><label>Authorized website<input type="text" name="authorized_site" placeholder="client.example.com"></label><label>Action<select name="client_action"><option value="issue">Issue / rotate API key</option><option value="bind_site">Bind / change authorized website</option><option value="external_secret">Issue / rotate external signing secret</option><option value="trial">Start 6-day trial</option><option value="grant_gold">Grant Gold for 30 days</option><option value="cancel_renewal">Disable Gold auto-renew</option><option value="revoke">Suspend API access</option></select></label><button class="button button-primary">Apply</button></form></div>
        <div class="dfr-api-card"><h2>API clients</h2><table class="dfr-table"><thead><tr><th>User</th><th>Key</th><th>Status</th><th>Gold plan</th><th>Rate limit</th><th>Last used</th></tr></thead><tbody><?php foreach($clients as $c): $ps=$this->gold_plan_state((int)$c->user_id); ?><tr><td><?php echo esc_html(($c->user_login ?: '#'.$c->user_id).' · '.($c->user_email ?: '')); ?></td><td><code><?php echo esc_html($c->key_prefix); ?>…</code></td><td><?php echo esc_html($c->status); ?></td><td><strong><?php echo esc_html(ucfirst($ps['status'])); ?></strong><?php if(!empty($ps['ends_at'])): ?><br><small><?php echo esc_html($this->format_ts($ps['ends_at'])); ?></small><?php endif; ?></td><td><?php echo absint($c->rate_limit_hour); ?>/hour</td><td><?php echo esc_html($c->last_used_at ?: 'Never'); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="dfr-api-card"><h2>Quick documentation</h2><div class="dfr-doc">API Base URL: https://delicastoreha.com/wp-json/delicat-reseller/v1
Authorization: Bearer dk_live_xxx
X-Delicat-Site: client.example.com
X-Delicat-Instance: di_...   # WordPress connector bootstrap/binding
X-Delicat-Timestamp: UNIX_TIMESTAMP
X-Delicat-Request: 32 lowercase hex chars
X-Delicat-Signature: HMAC-SHA256 request signature
GET  <?php echo esc_html(rest_url(self::NS.'/me')); ?>
GET  <?php echo esc_html(rest_url(self::NS.'/plan')); ?>
GET  <?php echo esc_html(rest_url(self::NS.'/balance')); ?>
GET  <?php echo esc_html(rest_url(self::NS.'/catalog')); ?>
GET  <?php echo esc_html(rest_url(self::NS.'/orders')); ?>
POST <?php echo esc_html(rest_url(self::NS.'/orders')); ?>  (Idempotency-Key required)
GET  <?php echo esc_html(rest_url(self::NS.'/orders/{order_id}')); ?></div></div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Gold plan
     * ------------------------------------------------------------------ */

    private function format_ts($ts) {
        $ts = absint($ts);
        return $ts ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts) : '';
    }

    private function gold_trial_days() { return 6; }
    private function gold_period_days() { return 30; }

    private function gold_plan_state($user_id) {
        $s = $this->reseller_settings();
        $now = time();
        $trial_started = absint(get_user_meta($user_id, '_dfr_gold_trial_started', true));
        $trial_ends = absint(get_user_meta($user_id, '_dfr_gold_trial_ends', true));
        $trial_used = get_user_meta($user_id, '_dfr_gold_trial_used', true) === '1' || $trial_started > 0;
        $paid_until = absint(get_user_meta($user_id, '_dfr_gold_paid_until', true));
        $auto = get_user_meta($user_id, '_dfr_gold_auto_renew', true) === '1';
        $status = 'eligible';
        $ends = 0;
        $active = false;

        if ($s['gold_plan_enabled'] !== '1' || $s['gold_plan_required'] !== '1') {
            $status = 'unrestricted';
            $active = true;
        } elseif ($paid_until > $now) {
            $status = 'active';
            $active = true;
            $ends = $paid_until;
        } elseif ($trial_ends > $now) {
            $status = 'trial';
            $active = true;
            $ends = $trial_ends;
        } elseif ($trial_used) {
            $status = 'expired';
        }

        return array(
            'name' => 'Gold',
            'status' => $status,
            'active' => $active,
            'trial_days' => 6,
            'trial_used' => $trial_used,
            'trial_started_at' => $trial_started,
            'trial_ends_at' => $trial_ends,
            'paid_until' => $paid_until,
            'ends_at' => $ends,
            'auto_renew' => $auto,
            'price' => wc_format_decimal((float)$s['gold_monthly_price'], 2),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG',
            'period_days' => 30,
        );
    }

    private function start_gold_trial($user_id, $source = 'client') {
        global $wpdb;
        $user_id = absint($user_id);
        $settings = $this->reseller_settings();
        if (!$user_id) {
            return new WP_Error('dapi_trial_user', __('Invalid account for Gold trial.', 'delicat-fazercards'));
        }
        if ($settings['gold_plan_enabled'] !== '1' || $settings['gold_plan_required'] !== '1') {
            return $this->gold_plan_state($user_id);
        }

        // Serialize trial activation so two simultaneous first API requests cannot
        // race and create duplicate trial-start audit events.
        $lock = substr('dfrgoldtrial_' . $user_id, 0, 64);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lock)) !== 1) {
            return new WP_Error('dapi_trial_busy', __('Gold trial is being initialized. Please retry shortly.', 'delicat-fazercards'));
        }
        try {
            $state = $this->gold_plan_state($user_id);
            if ($state['trial_used']) {
                return new WP_Error('dapi_trial_used', __('The one-time Gold free trial has already been used for this account.', 'delicat-fazercards'));
            }
            $now = time();
            $end = $now + ($this->gold_trial_days() * DAY_IN_SECONDS);
            update_user_meta($user_id, '_dfr_gold_trial_used', '1');
            update_user_meta($user_id, '_dfr_gold_trial_started', (string)$now);
            update_user_meta($user_id, '_dfr_gold_trial_ends', (string)$end);
            if (get_user_meta($user_id, '_dfr_gold_auto_renew', true) === '') {
                update_user_meta($user_id, '_dfr_gold_auto_renew', '0');
            }
            $this->log_plan_event($user_id, 'trial_started', 0, '', $now, $end, sanitize_text_field($source));
            return $this->gold_plan_state($user_id);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private function grant_gold($user_id, $note = '') {
        $state = $this->gold_plan_state($user_id);
        $now = time();
        $start = max($now, absint($state['paid_until']), absint($state['trial_ends_at']));
        $end = $start + ($this->gold_period_days() * DAY_IN_SECONDS);
        update_user_meta($user_id, '_dfr_gold_trial_used', '1');
        update_user_meta($user_id, '_dfr_gold_paid_until', (string)$end);
        $this->log_plan_event($user_id, 'gold_granted', 0, '', $start, $end, sanitize_text_field($note));
        return $this->gold_plan_state($user_id);
    }

    private function log_plan_event($user_id, $event, $amount = 0, $wallet_ref = '', $start = 0, $end = 0, $note = '') {
        global $wpdb;
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG';
        $wpdb->insert($this->plan_events_table, array(
            'user_id' => absint($user_id),
            'event' => sanitize_key($event),
            'amount' => (float)$amount,
            'currency' => sanitize_text_field($currency),
            'wallet_ref' => $wallet_ref ? sanitize_text_field((string)$wallet_ref) : null,
            'period_start' => $start ? gmdate('Y-m-d H:i:s', $start) : null,
            'period_end' => $end ? gmdate('Y-m-d H:i:s', $end) : null,
            'note' => $note ? substr(sanitize_text_field($note), 0, 255) : null,
            'created_at' => current_time('mysql', true),
        ), array('%d','%s','%f','%s','%s','%s','%s','%s','%s'));
    }

    /**
     * Create a durable Gold-plan debit journal BEFORE touching TeraWallet.
     *
     * This closes the crash window where a wallet debit could succeed but PHP
     * could terminate before the Gold entitlement was persisted. A later retry
     * must never blindly debit again while a pending journal exists.
     */
    private function create_gold_debit_journal($user_id, $amount, $start, $end, $source) {
        global $wpdb;
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG';
        $ok = $wpdb->insert($this->plan_events_table, array(
            'user_id' => absint($user_id),
            'event' => 'gold_debit_pending',
            'amount' => (float)$amount,
            'currency' => sanitize_text_field($currency),
            'wallet_ref' => null,
            'period_start' => gmdate('Y-m-d H:i:s', absint($start)),
            'period_end' => gmdate('Y-m-d H:i:s', absint($end)),
            'note' => substr(sanitize_text_field('Pending wallet debit · ' . $source), 0, 255),
            'created_at' => current_time('mysql', true),
        ), array('%d','%s','%f','%s','%s','%s','%s','%s','%s'));
        if (!$ok || !$wpdb->insert_id) {
            return new WP_Error('dapi_gold_journal', __('Gold plan could not create a durable wallet journal. No wallet debit was attempted.', 'delicat-fazercards'), array('status'=>503));
        }
        return absint($wpdb->insert_id);
    }

    private function get_pending_gold_debit($user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->plan_events_table} WHERE user_id=%d AND event='gold_debit_pending' ORDER BY id DESC LIMIT 1",
            absint($user_id)
        ));
    }

    /**
     * Recover a confirmed Gold debit without charging again.
     *
     * If wallet_ref is present, TeraWallet returned success and that reference
     * was durably stored before entitlement commit. We can therefore restore
     * the promised period safely. If there is no wallet_ref, the debit outcome
     * is uncertain and only an administrator should reconcile it against the
     * wallet ledger.
     */
    private function recover_pending_gold_debit($user_id) {
        global $wpdb;
        $pending = $this->get_pending_gold_debit($user_id);
        if (!$pending) { return false; }

        $end = !empty($pending->period_end) ? strtotime($pending->period_end . ' UTC') : 0;
        $current_paid = absint(get_user_meta($user_id, '_dfr_gold_paid_until', true));

        if ($end > 0 && $current_paid >= $end) {
            // Entitlement was committed but the final audit transition was
            // interrupted. It is safe to close the pending journal.
            $wpdb->update($this->plan_events_table, array(
                'event' => 'gold_reconciled',
                'note' => 'Recovered: entitlement was already committed.',
            ), array('id'=>absint($pending->id), 'event'=>'gold_debit_pending'), array('%s','%s'), array('%d','%s'));
            return false;
        }

        if (!empty($pending->wallet_ref) && $end > 0) {
            // Re-verify the durable TeraWallet debit before restoring entitlement.
            // A stored reference alone is not treated as proof of payment.
            $pending_currency = !empty($pending->currency) ? (string) $pending->currency : (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG');
            if (!$this->wallet_ledger_transaction_matches($user_id, $pending->wallet_ref, 'debit', (float) $pending->amount, $pending_currency)) {
                return new WP_Error('dapi_gold_reconciliation', __('A previous Gold transaction reference is not verifiable in the TeraWallet ledger. No second debit was attempted; administrator reconciliation is required.', 'delicat-fazercards'), array('status'=>503));
            }
            update_user_meta($user_id, '_dfr_gold_trial_used', '1');
            update_user_meta($user_id, '_dfr_gold_paid_until', (string)max($current_paid, $end));
            $stored = absint(get_user_meta($user_id, '_dfr_gold_paid_until', true));
            if ($stored < $end) {
                return new WP_Error('dapi_gold_reconciliation', __('A previous Gold wallet debit was confirmed but its entitlement could not be restored automatically. Administrator reconciliation is required.', 'delicat-fazercards'), array('status'=>503));
            }
            $updated = $wpdb->update($this->plan_events_table, array(
                'event' => 'gold_recovered',
                'note' => 'Recovered automatically from confirmed wallet debit journal.',
            ), array('id'=>absint($pending->id), 'event'=>'gold_debit_pending'), array('%s','%s'), array('%d','%s'));
            if ($updated === false) {
                // Entitlement is already active, so no duplicate debit can occur.
                // Leave the row pending as an admin-visible reconciliation signal.
                return $this->gold_plan_state($user_id);
            }
            return $this->gold_plan_state($user_id);
        }

        return new WP_Error('dapi_gold_reconciliation', __('A previous Gold wallet operation has an uncertain outcome. The account was not charged again. Administrator reconciliation is required.', 'delicat-fazercards'), array('status'=>503));
    }

    private function require_active_gold($user_id) {
        $s = $this->reseller_settings();
        if ($s['gold_plan_enabled'] !== '1' || $s['gold_plan_required'] !== '1') { return true; }
        $state = $this->gold_plan_state($user_id);
        if ($state['active']) { return true; }
        return new WP_Error('dapi_gold_required', __('An active Gold plan or 6-day Gold trial is required for catalog and ordering access.', 'delicat-fazercards'), array('status'=>402, 'plan'=>$state));
    }

    private function activate_gold_from_wallet($user_id, $source = 'manual') {
        global $wpdb;
        $s = $this->reseller_settings();
        $price = (float)$s['gold_monthly_price'];
        if ($s['gold_plan_enabled'] !== '1') {
            return new WP_Error('dapi_gold_disabled', __('Gold plan is currently disabled.', 'delicat-fazercards'), array('status'=>503));
        }
        if ($price <= 0) {
            return new WP_Error('dapi_gold_price', __('Gold plan price has not been configured by the store administrator.', 'delicat-fazercards'), array('status'=>503));
        }
        if (!$this->wallet_available()) {
            return new WP_Error('dapi_wallet_unavailable', __('Delicat Wallet is unavailable.', 'delicat-fazercards'), array('status'=>503));
        }
        if (!$this->wallet_lock($user_id)) {
            return new WP_Error('dapi_busy', __('Wallet is busy. Please retry shortly.', 'delicat-fazercards'), array('status'=>409));
        }
        try {
            $state = $this->gold_plan_state($user_id);
            // Prevent double clicks or duplicate renewal workers from charging an already-paid period twice.
            if ($state['status'] === 'active' && $state['paid_until'] > time()) {
                // If the prior request committed entitlement but died before
                // closing its audit row, close that row opportunistically.
                $this->recover_pending_gold_debit($user_id);
                return $this->gold_plan_state($user_id);
            }
            if ($source === 'auto' && $state['status'] === 'trial') {
                return $state;
            }

            // Never start a second debit while an earlier Gold wallet operation
            // has an unresolved outcome. Confirmed journals are recovered without
            // charging again; uncertain ones fail closed for administrator review.
            $recovered = $this->recover_pending_gold_debit($user_id);
            if (is_wp_error($recovered)) { return $recovered; }
            if (is_array($recovered) && !empty($recovered['active'])) { return $recovered; }

            $balance = $this->wallet_balance($user_id);
            if (is_wp_error($balance)) { return $balance; }
            if ($balance + 0.00001 < $price) {
                return new WP_Error('dapi_gold_balance', __('Insufficient Delicat Wallet balance for Gold plan renewal.', 'delicat-fazercards'), array('status'=>402,'balance'=>wc_format_decimal($balance,2),'required'=>wc_format_decimal($price,2),'currency'=>get_woocommerce_currency()));
            }
            $now = time();
            $start = max($now, absint($state['paid_until']), absint($state['trial_ends_at']));
            $end = $start + ($this->gold_period_days() * DAY_IN_SECONDS);

            // Durable intent is written BEFORE TeraWallet is touched. If PHP or
            // the database dies after the debit, a later request sees this row
            // and will not blindly debit the same subscription period again.
            $journal_id = $this->create_gold_debit_journal($user_id, $price, $start, $end, $source);
            if (is_wp_error($journal_id)) { return $journal_id; }

            $ref = woo_wallet()->wallet->debit($user_id, $price, sprintf('Delicat Reseller API Gold plan — %d days', $this->gold_period_days()), array(
                'for' => 'delicat_reseller_gold',
                'category' => 'subscription',
                'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG',
            ));
            if (!$ref) {
                $wpdb->update($this->plan_events_table, array(
                    'event' => 'gold_debit_failed',
                    'note' => 'TeraWallet debit returned failure; no entitlement granted.',
                ), array('id'=>absint($journal_id), 'event'=>'gold_debit_pending'), array('%s','%s'), array('%d','%s'));
                return new WP_Error('dapi_gold_debit', __('Gold plan wallet debit failed.', 'delicat-fazercards'), array('status'=>409));
            }

            // Persist the confirmed wallet reference while the journal is still
            // pending. This lets a later request safely restore entitlement if
            // execution stops between the debit and user-meta commit.
            $ref_saved = $wpdb->update($this->plan_events_table, array(
                'wallet_ref' => sanitize_text_field((string)$ref),
                'note' => 'Wallet debit confirmed; entitlement commit pending.',
            ), array('id'=>absint($journal_id), 'event'=>'gold_debit_pending'), array('%s','%s'), array('%d','%s'));
            if ($ref_saved === false || $ref_saved === 0) {
                return new WP_Error('dapi_gold_reconciliation', __('Gold wallet debit succeeded but its transaction reference could not be journaled. No supplier action was taken; administrator reconciliation is required.', 'delicat-fazercards'), array('status'=>503));
            }
            $ledger_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG';
            if (!$this->wallet_ledger_transaction_matches($user_id, $ref, 'debit', $price, $ledger_currency)) {
                // Keep the pending journal + reference so an eventual ledger write can be
                // reconciled safely; never grant entitlement or debit again blindly.
                return new WP_Error('dapi_gold_reconciliation', __('Gold wallet transaction could not be verified in the TeraWallet ledger. The debit will not be repeated automatically; administrator reconciliation is required.', 'delicat-fazercards'), array('status'=>503));
            }

            update_user_meta($user_id, '_dfr_gold_trial_used', '1');
            update_user_meta($user_id, '_dfr_gold_paid_until', (string)$end);
            if (absint(get_user_meta($user_id, '_dfr_gold_paid_until', true)) < $end) {
                return new WP_Error('dapi_gold_reconciliation', __('Gold wallet debit succeeded but the subscription entitlement could not be committed. The debit will not be repeated automatically; administrator reconciliation is required.', 'delicat-fazercards'), array('status'=>503));
            }

            $event = $source === 'auto' ? 'gold_renewed' : 'gold_activated';
            $finalized = $wpdb->update($this->plan_events_table, array(
                'event' => $event,
                'note' => substr(sanitize_text_field($source), 0, 255),
            ), array('id'=>absint($journal_id), 'event'=>'gold_debit_pending'), array('%s','%s'), array('%d','%s'));
            if ($finalized === false) {
                // Entitlement is already committed. Keep the pending row visible
                // for audit/reconciliation, but never charge the customer again.
                return $this->gold_plan_state($user_id);
            }
            return $this->gold_plan_state($user_id);
        } finally {
            $this->wallet_unlock($user_id);
        }
    }

    public function process_gold_renewals() {
        $s = $this->reseller_settings();
        if ($s['gold_plan_enabled'] !== '1' || (float)$s['gold_monthly_price'] <= 0) { return; }
        global $wpdb;
        $users = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='_dfr_gold_auto_renew' AND meta_value='1' LIMIT 500");
        foreach ($users as $uid) {
            $uid = absint($uid);
            if (!$uid || !$this->is_approved($uid)) { continue; }
            $state = $this->gold_plan_state($uid);
            if ($state['status'] === 'expired') {
                $this->activate_gold_from_wallet($uid, 'auto');
            }
        }
    }

    /* ---------------------------------------------------------------------
     * My Account
     * ------------------------------------------------------------------ */

    public function handle_account_actions() {
        if (!is_user_logged_in() || empty($_POST['dfr_account_api_action'])) { return; }
        $uid = get_current_user_id();
        $action = sanitize_key(wp_unslash($_POST['dfr_account_api_action']));
        if ($action === 'apply') {
            check_admin_referer('dfr_reseller_application');
            $website = $this->normalize_site_host(wp_unslash($_POST['website'] ?? ''));
            $business = sanitize_text_field(wp_unslash($_POST['business_name'] ?? ''));
            $contact = sanitize_text_field(wp_unslash($_POST['contact_name'] ?? ''));
            $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
            $country = sanitize_text_field(wp_unslash($_POST['country'] ?? ''));
            $volume = sanitize_text_field(wp_unslash($_POST['monthly_volume'] ?? ''));
            $products = sanitize_textarea_field(wp_unslash($_POST['requested_products'] ?? ''));
            $experience = sanitize_textarea_field(wp_unslash($_POST['experience'] ?? ''));
            if (!$website || !$business || !$contact || !$phone || empty($_POST['agree_terms'])) {
                set_transient('dfr_account_error_' . $uid, __('Complete all required application fields and accept the reseller terms.', 'delicat-fazercards'), 120);
            } else {
                update_user_meta($uid, '_dfr_reseller_application_status', 'pending');
                update_user_meta($uid, '_dfr_reseller_application_submitted_at', time());
                update_user_meta($uid, '_dfr_reseller_application', array('business_name'=>$business,'contact_name'=>$contact,'website'=>$website,'phone'=>$phone,'country'=>$country,'monthly_volume'=>$volume,'requested_products'=>$products,'experience'=>$experience));
                delete_user_meta($uid, '_dfr_reseller_approved');
                $this->revoke_key($uid);
                set_transient('dfr_account_notice_' . $uid, __('Application submitted. Delicat Administrator must approve it before API access is available.', 'delicat-fazercards'), 120);
            }
            wp_safe_redirect(wc_get_account_endpoint_url('reseller-api')); exit;
        }
        if (!$this->is_approved($uid)) { return; }
        check_admin_referer('dfr_account_api_key');
        $message = '';

        if ($action === 'rotate') {
            $raw = $this->issue_key($uid, 'Self service');
            if (is_wp_error($raw)) { $message = $raw->get_error_message(); }
            else {
                if (!$this->stash_one_time_api_key('dfr_account_new_key_' . $uid, $uid, $raw)) {
                    $message = __('API key created, but the secure one-time display could not be stored. Rotate the key after server encryption support is available.', 'delicat-fazercards');
                }
            }
        } elseif ($action === 'revoke') {
            $this->revoke_key($uid);
        } elseif ($action === 'activate_gold') {
            $res = $this->activate_gold_from_wallet($uid, 'manual');
            if (is_wp_error($res)) { $message = $res->get_error_message(); }
            else { set_transient('dfr_account_notice_' . $uid, __('Gold plan activated successfully.', 'delicat-fazercards'), 120); }
        } elseif ($action === 'auto_renew') {
            $enabled = !empty($_POST['gold_auto_renew']) ? '1' : '0';
            update_user_meta($uid, '_dfr_gold_auto_renew', $enabled);
            $this->log_plan_event($uid, $enabled === '1' ? 'auto_renew_enabled' : 'auto_renew_disabled', 0, '', 0, 0, 'Customer setting');
            set_transient('dfr_account_notice_' . $uid, $enabled === '1' ? __('Gold auto-renew enabled.', 'delicat-fazercards') : __('Gold auto-renew disabled.', 'delicat-fazercards'), 120);
        }

        if ($message) { set_transient('dfr_account_error_' . $uid, $message, 120); }
        wp_safe_redirect(wc_get_account_endpoint_url('reseller-api'));
        exit;
    }

    public function render_account_page() {
        $uid = get_current_user_id();
        if (!$this->is_approved($uid)) {
            $status = (string) get_user_meta($uid, '_dfr_reseller_application_status', true);
            $app = (array) get_user_meta($uid, '_dfr_reseller_application', true);
            $note = (string) get_user_meta($uid, '_dfr_reseller_application_admin_note', true);
            $notice = get_transient('dfr_account_notice_' . $uid); if ($notice) delete_transient('dfr_account_notice_' . $uid);
            $error = get_transient('dfr_account_error_' . $uid); if ($error) delete_transient('dfr_account_error_' . $uid);
            echo '<style>.dfr-apply{max-width:850px;margin:auto}.dfr-apply-card{background:#fff;border:1px solid #e7e8f0;border-radius:24px;padding:24px;box-shadow:0 18px 50px rgba(40,35,80,.08)}.dfr-apply-hero{background:linear-gradient(135deg,#11152b,#5426a8);color:#fff;border-radius:20px;padding:24px;margin-bottom:16px}.dfr-apply-hero h2{color:#fff;margin:0 0 8px}.dfr-apply-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.dfr-apply label{display:grid;gap:6px;font-weight:700}.dfr-apply input,.dfr-apply textarea{width:100%;border:1px solid #dfe1ea;border-radius:12px;padding:11px}.dfr-status{display:inline-flex;padding:7px 11px;border-radius:999px;background:#fff4cc;color:#735500;font-weight:800}@media(max-width:650px){.dfr-apply-grid{grid-template-columns:1fr}}</style><div class="dfr-apply"><div class="dfr-apply-hero"><h2>Delicat Reseller</h2><p>Apply for secure API access. Every application is reviewed by a Delicat Administrator before any API key can be issued.</p></div>';
            if ($notice) echo '<div class="woocommerce-message">'.esc_html($notice).'</div>'; if ($error) echo '<div class="woocommerce-error">'.esc_html($error).'</div>';
            if ($status === 'pending') { echo '<div class="dfr-apply-card"><span class="dfr-status">Application pending</span><h3>Under administrator review</h3><p>Your API, reseller prices and import catalog remain locked until Delicat approves this application.</p></div></div>'; return; }
            if ($status === 'rejected' || $status === 'needs_info' || $status === 'suspended') { echo '<div class="dfr-apply-card"><span class="dfr-status">'.esc_html(ucwords(str_replace('_',' ',$status))).'</span>'.($note?'<p><strong>Administrator note:</strong> '.esc_html($note).'</p>':'').'</div>'; }
            echo '<div class="dfr-apply-card"><h3>Reseller application</h3><form method="post">'; wp_nonce_field('dfr_reseller_application'); echo '<input type="hidden" name="dfr_account_api_action" value="apply"><div class="dfr-apply-grid"><label>Business / Store name *<input name="business_name" required value="'.esc_attr($app['business_name']??'').'"></label><label>Applicant name *<input name="contact_name" required value="'.esc_attr($app['contact_name']??'').'"></label><label>Website / domain *<input name="website" required placeholder="store.example.com" value="'.esc_attr($app['website']??'').'"></label><label>Phone / WhatsApp *<input name="phone" required value="'.esc_attr($app['phone']??'').'"></label><label>Country<input name="country" value="'.esc_attr($app['country']??'').'"></label><label>Expected monthly volume<input name="monthly_volume" placeholder="Example: 200 orders" value="'.esc_attr($app['monthly_volume']??'').'"></label></div><label style="margin-top:14px">Products requested<textarea name="requested_products" rows="3" placeholder="Example: Free Fire LATAM">'.esc_textarea($app['requested_products']??'').'</textarea></label><label style="margin-top:14px">Reselling experience<textarea name="experience" rows="3">'.esc_textarea($app['experience']??'').'</textarea></label><p><label style="display:flex;grid-template-columns:auto 1fr;align-items:start"><input type="checkbox" name="agree_terms" value="1" required> I confirm the information is accurate and agree to Delicat reseller/API rules, including secure server-side storage of the API key.</label></p><button class="button alt" type="submit">Submit application for review</button></form></div></div>'; return;
        }
        global $wpdb;
        $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->clients_table} WHERE user_id=%d", $uid));
        $new_key = $this->take_one_time_api_key('dfr_account_new_key_' . $uid, $uid);
        $notice = get_transient('dfr_account_notice_' . $uid); if ($notice) { delete_transient('dfr_account_notice_' . $uid); }
        $error = get_transient('dfr_account_error_' . $uid); if ($error) { delete_transient('dfr_account_error_' . $uid); }
        $base = untrailingslashit(rest_url(self::NS));
        $plan = $this->gold_plan_state($uid);
        $balance = $this->wallet_balance($uid);
        $balance_text = is_wp_error($balance) ? '—' : wc_price($balance, array('currency'=>$plan['currency']));

        echo '<style>.dfr-myapi{display:grid;gap:16px}.dfr-myapi-card{border:1px solid #e8e8ef;border-radius:18px;padding:20px;background:#fff;box-shadow:0 8px 28px rgba(30,35,60,.06)}.dfr-myapi-gold{background:linear-gradient(145deg,#0e1020,#2a1f08);color:#fff;border-color:#4b3a14}.dfr-myapi-gold h2,.dfr-myapi-gold h3{color:#fff}.dfr-myapi-badge{display:inline-flex;padding:6px 10px;border-radius:999px;background:#fff3bd;color:#6d5200;font-weight:800}.dfr-myapi-price{font-size:27px;font-weight:850;color:#ffd765}.dfr-myapi-meta{display:flex;gap:18px;flex-wrap:wrap;margin:12px 0}.dfr-myapi pre{padding:14px;background:#101827;color:#fff;border-radius:10px;overflow:auto}.dfr-myapi-actions{display:flex;gap:9px;flex-wrap:wrap;align-items:center}</style>';
        echo '<div class="dfr-myapi">';
        if ($notice) { echo '<div class="woocommerce-message">' . esc_html($notice) . '</div>'; }
        if ($error) { echo '<div class="woocommerce-error">' . esc_html($error) . '</div>'; }
        echo '<div class="dfr-myapi-card dfr-myapi-gold"><span class="dfr-myapi-badge">Gold API</span><h2>' . esc_html__('6-day free trial', 'delicat-fazercards') . '</h2><div class="dfr-myapi-price">' . ($plan['price'] > 0 ? wp_kses_post(wc_price($plan['price'], array('currency'=>$plan['currency']))) . ' / 30 days' : esc_html__('Monthly price not configured', 'delicat-fazercards')) . '</div><div class="dfr-myapi-meta"><span><strong>Status:</strong> ' . esc_html(ucfirst($plan['status'])) . '</span><span><strong>Wallet:</strong> ' . wp_kses_post($balance_text) . '</span>';
        if ($plan['ends_at']) { echo '<span><strong>Ends:</strong> ' . esc_html($this->format_ts($plan['ends_at'])) . '</span>'; }
        echo '</div><p>' . esc_html__('The trial is free and can be used only once. Rotating or recreating an API key does not restart it.', 'delicat-fazercards') . '</p>';
        if ($plan['status'] === 'expired' && (float)$plan['price'] > 0) {
            echo '<form method="post" class="dfr-myapi-actions">'; wp_nonce_field('dfr_account_api_key'); echo '<button class="button alt" name="dfr_account_api_action" value="activate_gold">' . sprintf(esc_html__('Activate Gold with Delicat Wallet — %s', 'delicat-fazercards'), wp_strip_all_tags(wc_price($plan['price'], array('currency'=>$plan['currency'])))) . '</button></form>';
        }
        if (in_array($plan['status'], array('trial','active'), true) && (float)$plan['price'] > 0) {
            echo '<form method="post" class="dfr-myapi-actions" style="margin-top:10px">'; wp_nonce_field('dfr_account_api_key'); echo '<label><input type="checkbox" name="gold_auto_renew" value="1" ' . checked($plan['auto_renew'], true, false) . '> ' . esc_html__('Auto-renew Gold from my Delicat Wallet', 'delicat-fazercards') . '</label><button class="button" name="dfr_account_api_action" value="auto_renew">' . esc_html__('Save auto-renew', 'delicat-fazercards') . '</button></form><p><small>' . esc_html__('No charge occurs during the free trial unless you manually activate Gold. If auto-renew is enabled, the configured Gold fee is debited after the trial/paid period expires.', 'delicat-fazercards') . '</small></p>';
        }
        echo '</div>';

        echo '<div class="dfr-myapi-card"><h2>Delicat Reseller API</h2><p>Use your Delicat Wallet balance to place API orders. Supplier credentials and wholesale costs are never returned.</p>';
        if ($new_key) { echo '<p><strong>Copy your new key now — it will not be shown again.</strong></p><pre>' . esc_html($new_key) . '</pre>'; }
        echo '<form method="post" class="dfr-myapi-actions">'; wp_nonce_field('dfr_account_api_key'); echo '<button class="button" name="dfr_account_api_action" value="rotate">' . esc_html($client ? 'Rotate API key' : 'Create API key — trial starts on first API use') . '</button> '; if ($client && $client->status === 'active') echo '<button class="button" name="dfr_account_api_action" value="revoke">Revoke key</button>'; echo '</form>';
        echo '<div class="dfr-client-security-note" style="margin-top:16px;padding:14px 16px;border:1px solid #e6e8ef;border-radius:14px;background:#f8f9fc"><strong>Secure API access</strong><p style="margin:6px 0 0">For security, authentication headers, signing details and private endpoint examples are not displayed in the customer portal. Keep the one-time API key private and configure it only in your approved server-side Delicat connector.</p></div></div></div>';
    }

    /* ---------------------------------------------------------------------
     * REST authentication and read endpoints
     * ------------------------------------------------------------------ */

    /**
     * Reseller REST responses contain wallet balances, order ownership and digital
     * delivery state. Explicitly prohibit browser/proxy caching and vary on the
     * Authorization header as a defense-in-depth signal for intermediary caches.
     */
    public function protect_api_response_cache($response, $server, $request) {
        if (!($request instanceof WP_REST_Request)) { return $response; }
        $route = (string) $request->get_route();
        if (strpos($route, '/' . self::NS . '/') !== 0) { return $response; }
        if ($response instanceof WP_HTTP_Response) {
            $response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Vary', 'Authorization');
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('Referrer-Policy', 'no-referrer');
            $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
        return $response;
    }

    public function register_routes() {
        $auth = array($this, 'permission_api');
        register_rest_route(self::NS, '/me', array('methods'=>'GET','callback'=>array($this,'route_me'),'permission_callback'=>$auth));
        register_rest_route(self::NS, '/plan', array('methods'=>'GET','callback'=>array($this,'route_plan'),'permission_callback'=>$auth));
        register_rest_route(self::NS, '/balance', array('methods'=>'GET','callback'=>array($this,'route_balance'),'permission_callback'=>$auth));
        register_rest_route(self::NS, '/catalog', array('methods'=>'GET','callback'=>array($this,'route_catalog'),'permission_callback'=>$auth));
        register_rest_route(self::NS, '/catalog/(?P<sku>[A-Za-z0-9._-]{3,100})', array('methods'=>'GET','callback'=>array($this,'route_catalog_item'),'permission_callback'=>$auth));
        register_rest_route(self::NS, '/validate-player', array('methods'=>'POST','callback'=>array($this,'route_validate_player'),'permission_callback'=>$auth));
        register_rest_route(self::NS, '/orders', array(
            array('methods'=>'GET','callback'=>array($this,'route_orders'),'permission_callback'=>$auth),
            array('methods'=>'POST','callback'=>array($this,'route_create_order'),'permission_callback'=>$auth),
        ));
        register_rest_route(self::NS, '/orders/(?P<id>dapiord_[A-Za-z0-9-]{10,40})', array('methods'=>'GET','callback'=>array($this,'route_order'),'permission_callback'=>$auth));
    }

    private function consume_rate_limit($client) {
        global $wpdb;
        $limit = max(10, min(10000, (int)$client->rate_limit_hour));
        $window = gmdate('YmdH');
        $now = current_time('mysql', true);
        // Atomic UPDATE prevents concurrent requests from reading the same transient count.
        $sql = $wpdb->prepare(
            "UPDATE {$this->clients_table}
             SET rate_count = CASE WHEN rate_window=%s THEN rate_count+1 ELSE 1 END,
                 rate_window=%s,
                 last_used_at=%s
             WHERE id=%d AND status='active' AND (rate_window IS NULL OR rate_window<>%s OR rate_count<%d)",
            $window, $window, $now, absint($client->id), $window, $limit
        );
        return (int)$wpdb->query($sql) === 1;
    }

    public function permission_api(WP_REST_Request $request) {
        global $wpdb;
        // Bearer keys authorize wallet-funded purchases. Refuse clear-text HTTP by
        // default so a reverse proxy/server misconfiguration cannot silently expose
        // a reseller credential in transit. Local/proxy test environments may opt in
        // explicitly only after providing equivalent TLS at the edge.
        if (!is_ssl() && !apply_filters('dfr_reseller_api_allow_insecure_http', false, $request)) {
            return new WP_Error('dapi_https_required', 'HTTPS is required for the Delicat Reseller API', array('status'=>426));
        }
        // All API requests are tiny JSON/HMAC messages. Bound the body before API-key
        // lookup/signature parsing so oversized requests cannot consume disproportionate
        // PHP memory/JSON work on a public REST endpoint.
        $max_body = 131072; // 128 KiB, far above any legitimate reseller payload.
        $declared_length = (int) $request->get_header('content-length');
        if ($declared_length > $max_body) {
            return new WP_Error('dapi_payload_too_large', 'Request body is too large', array('status'=>413));
        }
        $raw_body = (string) $request->get_body();
        if (strlen($raw_body) > $max_body) {
            return new WP_Error('dapi_payload_too_large', 'Request body is too large', array('status'=>413));
        }
        $settings = $this->reseller_settings();
        if ($settings['enabled'] !== '1') {
            return new WP_Error('dapi_disabled', 'Delicat Reseller API is disabled', array('status'=>503));
        }
        $header = trim((string)$request->get_header('authorization'));
        if (stripos($header, 'Bearer ') !== 0) {
            return new WP_Error('dapi_unauthorized', 'Authorization: Bearer API_KEY is required', array('status'=>401));
        }
        $raw = trim(substr($header, 7));
        if ($raw === '' || strpos($raw, 'dk_live_') !== 0 || strlen($raw) > 100 || !preg_match('/^dk_live_[A-Za-z0-9]+$/', $raw)) {
            return new WP_Error('dapi_unauthorized', 'Invalid API key', array('status'=>401));
        }
        $hash = $this->pepper_hash($raw);
        $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->clients_table} WHERE key_hash=%s AND status='active'", $hash));
        if (!$client || !$this->is_approved($client->user_id) || get_user_meta($client->user_id, '_dfr_reseller_application_status', true) !== 'approved') {
            return new WP_Error('dapi_unauthorized', 'Invalid or revoked API key', array('status'=>401));
        }
        // Strong authentication is mandatory for every reseller endpoint. Read routes
        // expose account identity, wallet balance, order history and (for completed
        // orders) digital delivery secrets, so a stolen bearer key alone is never enough.
        $has_binding = !empty($client->allowed_host) || !empty($client->install_secret_hash);
        if (!$has_binding) {
            return new WP_Error('dapi_strong_auth_required', 'This API key must be bound to an administrator-approved website before any API access is allowed.', array('status'=>403));
        }
        $binding = $this->bind_or_verify_site_installation($client, $request);
        if (is_wp_error($binding)) { return $binding; }
        if (!$this->consume_rate_limit($client)) {
            return new WP_Error('dapi_rate_limited', 'Rate limit reached', array('status'=>429));
        }
        // Existing v3.0 clients receive the one-time six-day trial on first authenticated use.
        $state = $this->gold_plan_state((int)$client->user_id);
        if ($state['status'] === 'eligible') {
            $this->start_gold_trial((int)$client->user_id, 'first_authenticated_use');
            $state = $this->gold_plan_state((int)$client->user_id);
        }
        $request->set_param('_dapi_user_id', (int)$client->user_id);
        $request->set_param('_dapi_plan', $state);
        return true;
    }

    private function api_user(WP_REST_Request $r) { return absint($r->get_param('_dapi_user_id')); }

    private function wallet_available() {
        return function_exists('woo_wallet') && is_object(woo_wallet()) && isset(woo_wallet()->wallet) && is_object(woo_wallet()->wallet) && method_exists(woo_wallet()->wallet,'get_wallet_balance') && method_exists(woo_wallet()->wallet,'debit') && method_exists(woo_wallet()->wallet,'credit');
    }

    private function wallet_balance($uid) {
        if (!$this->wallet_available()) { return new WP_Error('dapi_wallet_unavailable','Delicat Wallet is unavailable',array('status'=>503)); }
        return (float) woo_wallet()->wallet->get_wallet_balance($uid, 'edit', function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG');
    }

    /**
     * Verify a TeraWallet transaction against its durable ledger before an irreversible
     * supplier action, subscription entitlement or refund state transition is accepted.
     */
    private function wallet_ledger_transaction_matches($user_id, $transaction_id, $type, $amount, $currency) {
        global $wpdb;
        $user_id = absint($user_id);
        $transaction_id = absint($transaction_id);
        $type = sanitize_key((string) $type);
        $currency = strtoupper(trim((string) $currency));
        if (!$user_id || !$transaction_id || !in_array($type, array('debit','credit'), true)) { return false; }
        $table = $wpdb->base_prefix . 'woo_wallet_transactions';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE transaction_id=%d LIMIT 1", $transaction_id));
        if (!$row || !isset($row->user_id, $row->type)) { return false; }
        if (absint($row->user_id) !== $user_id || sanitize_key((string) $row->type) !== $type) { return false; }
        if (isset($row->deleted) && absint($row->deleted) !== 0) { return false; }

        if (isset($row->original_amount) && is_numeric($row->original_amount)) {
            $decimals = function_exists('wc_get_price_decimals') ? max(0, (int) wc_get_price_decimals()) : 2;
            $epsilon = 1 / pow(10, $decimals);
            if (abs((float) $row->original_amount - (float) $amount) >= $epsilon) { return false; }
        }
        if ($currency !== '' && isset($row->original_currency) && trim((string) $row->original_currency) !== '') {
            if (strtoupper(trim((string) $row->original_currency)) !== $currency) { return false; }
        }
        return true;
    }

    private function wallet_lock($uid) {
        global $wpdb;
        $name = substr('dfrwallet_' . absint($uid), 0, 64);
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $name)) === 1;
    }

    private function wallet_unlock($uid) {
        global $wpdb;
        $name = substr('dfrwallet_' . absint($uid), 0, 64);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    public function route_me($r) {
        $u = get_userdata($this->api_user($r));
        if (!$u) { return new WP_Error('dapi_user_missing','Account unavailable',array('status'=>401)); }
        return rest_ensure_response(array('ok'=>true,'account'=>array('id'=>$u->ID,'email'=>$u->user_email,'name'=>$u->display_name),'currency'=>get_woocommerce_currency(),'api_version'=>'v1','plan'=>$this->gold_plan_state($u->ID),'site_binding'=>array('authorized_host'=>$this->normalize_site_host($r->get_header('x-delicat-site')),'bound'=>true)));
    }

    public function route_plan($r) {
        return rest_ensure_response(array('ok'=>true,'plan'=>$this->gold_plan_state($this->api_user($r))));
    }

    public function route_balance($r) {
        $b = $this->wallet_balance($this->api_user($r));
        if (is_wp_error($b)) { return $b; }
        return rest_ensure_response(array('ok'=>true,'balance'=>wc_format_decimal($b,2),'currency'=>get_woocommerce_currency(),'plan'=>$this->gold_plan_state($this->api_user($r))));
    }

    /* ---------------------------------------------------------------------
     * Catalog
     * ------------------------------------------------------------------ */

    private function ensure_api_sku($id) {
        $sku = (string)get_post_meta($id, '_dfr_api_sku', true);
        if (!$sku) {
            $sku = 'dapi_' . absint($id);
            update_post_meta($id, '_dfr_api_sku', $sku);
        }
        return $sku;
    }

    private function catalog_record($p) {
        if (!$p || $p->is_type('variable')) { return null; }
        $id = $p->get_id();
        if (get_post_meta($id, '_dfr_imported', true) !== '1') { return null; }
        if (!$this->reseller_product_enabled($id)) { return null; }
        $parent = $p->get_parent_id() ?: $id;
        $service = (string)get_post_meta($id, '_dfr_service_type', true);
        if (!$service) { $service = (string)get_post_meta($parent, '_dfr_service_type', true); }
        if (!in_array($service, array('topup','giftcard','gamekey'), true)) { return null; }
        $retail_price = (float)$p->get_price('edit');
        $price = $this->reseller_product_price($id);
        if ($price <= 0) { return null; }
        $name = $p->get_name();
        if ($p->is_type('variation')) {
            $parentp = wc_get_product($parent);
            if ($parentp) { $name = $parentp->get_name() . ' — ' . wc_get_formatted_variation($p, true, false, true); }
        }
        $fields = array();
        if ($service === 'topup') {
            $raw = json_decode((string)get_post_meta($parent, '_dfr_field_spec_json', true), true);
            if (is_array($raw)) {
                foreach ($raw as $f) {
                    if (empty($f['key'])) { continue; }
                    $type = sanitize_key($f['type'] ?? 'text');
                    if (!in_array($type, array('text','number','email','select'), true)) { $type = 'text'; }
                    $row = array(
                        'key'=>sanitize_key($f['key']),
                        'label'=>sanitize_text_field($f['label'] ?? $f['key']),
                        'type'=>$type,
                        'required'=>array_key_exists('required', $f) ? !empty($f['required']) : true,
                    );
                    if ($type === 'select' && !empty($f['options']) && is_array($f['options'])) {
                        $row['options'] = array_values(array_map('sanitize_text_field', array_keys($f['options'])));
                    }
                    $fields[] = $row;
                }
            }
        }
        $player_verification = array('supported' => false);
        if ($service === 'topup' && class_exists('DFR_Catalog')) {
            $player_verification = DFR_Catalog::instance()->uid_validation_capability($id);
        }
        return array(
            'sku'=>$this->ensure_api_sku($id),
            'name'=>wp_strip_all_tags($name),
            'type'=>$service,
            'price'=>wc_format_decimal($price,2),
            'currency'=>get_woocommerce_currency(),
            'in_stock'=>$p->is_in_stock(),
            'min_quantity'=>max(1,(int)get_post_meta($id,'_dfr_min_order_quantity',true)),
            'max_quantity'=>max(1,(int)get_post_meta($id,'_dfr_max_order_quantity',true)),
            'fields'=>$fields,
            'player_verification'=>$player_verification,
        );
    }

    public function route_catalog($r) {
        $rs = $this->reseller_settings();
        if ($rs['enabled'] !== '1' || $rs['catalog_enabled'] !== '1') { return new WP_Error('dapi_catalog_disabled','API catalog is disabled',array('status'=>503)); }
        $plan = $this->require_active_gold($this->api_user($r)); if (is_wp_error($plan)) { return $plan; }
        $page = max(1, absint($r->get_param('page') ?: 1));
        $limit = max(1, min(100, absint($r->get_param('limit') ?: 50)));
        $type = sanitize_key((string)$r->get_param('type'));
        $meta = array(
            array('key'=>'_dfr_imported','value'=>'1'),
            array('key'=>'_dfr_offer_id','compare'=>'EXISTS'),
            array('key'=>'_dfr_reseller_enabled','value'=>'1'),
            array('key'=>'_dfr_reseller_price','value'=>0,'compare'=>'>','type'=>'DECIMAL'),
        );
        if (in_array($type, array('topup','giftcard','gamekey'), true)) { $meta[] = array('key'=>'_dfr_service_type','value'=>$type); }
        $q = new WP_Query(array('post_type'=>array('product','product_variation'),'post_status'=>'publish','posts_per_page'=>$limit,'paged'=>$page,'fields'=>'ids','meta_query'=>$meta,'orderby'=>'ID','order'=>'ASC'));
        $items = array();
        foreach ($q->posts as $id) {
            $p = wc_get_product($id);
            $rec = $this->catalog_record($p);
            if ($rec) { $items[] = $rec; }
        }
        return rest_ensure_response(array('ok'=>true,'items'=>$items,'page'=>$page,'pages'=>(int)$q->max_num_pages,'currency'=>get_woocommerce_currency(),'plan'=>$this->gold_plan_state($this->api_user($r))));
    }

    private function product_by_sku($sku) {
        global $wpdb;
        $sku = sanitize_text_field((string)$sku);
        $id = (int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_dfr_api_sku' AND meta_value=%s LIMIT 1", $sku));
        // Backward compatibility for v3.0 dapi_ID values, but only for genuine imported records.
        if (!$id && preg_match('/^dapi_(\d+)$/', $sku, $m)) {
            $candidate = (int)$m[1];
            if (get_post_meta($candidate, '_dfr_imported', true) === '1') {
                $id = $candidate;
                if (!get_post_meta($candidate, '_dfr_api_sku', true)) { update_post_meta($candidate, '_dfr_api_sku', $sku); }
            }
        }
        $p = $id ? wc_get_product($id) : false;
        return $p ?: false;
    }

    public function route_catalog_item($r) {
        $rs = $this->reseller_settings();
        if ($rs['enabled'] !== '1' || $rs['catalog_enabled'] !== '1') { return new WP_Error('dapi_catalog_disabled','API catalog is disabled',array('status'=>503)); }
        $plan = $this->require_active_gold($this->api_user($r)); if (is_wp_error($plan)) { return $plan; }
        $p = $this->product_by_sku(sanitize_text_field($r['sku']));
        if (!$p || $p->get_status() !== 'publish') { return new WP_Error('dapi_not_found','Catalog item not found',array('status'=>404)); }
        $rec = $this->catalog_record($p);
        if (!$rec) { return new WP_Error('dapi_not_available','Catalog item is not available',array('status'=>404)); }
        return rest_ensure_response(array('ok'=>true,'item'=>$rec));
    }

    /**
     * Pre-check a top-up account without debiting Delicat Wallet. This is the
     * reseller equivalent of the WooCommerce "Vérifier le compte joueur" button.
     */
    public function route_validate_player($r) {
        $rs = $this->reseller_settings();
        if ($rs['enabled'] !== '1' || $rs['catalog_enabled'] !== '1') {
            return new WP_Error('dapi_disabled', 'Delicat Reseller API is disabled', array('status'=>503));
        }
        $plan = $this->require_active_gold($this->api_user($r));
        if (is_wp_error($plan)) { return $plan; }
        $raw_body = (string) $r->get_body();
        if (strlen($raw_body) > 16384) { return new WP_Error('dapi_payload_too_large','Validation payload is too large',array('status'=>413)); }
        $body = $r->get_json_params();
        if (!is_array($body)) { return new WP_Error('dapi_bad_json','JSON body required',array('status'=>400)); }
        $sku = sanitize_text_field($body['sku'] ?? '');
        $p = $this->product_by_sku($sku);
        if (!$p || $p->get_status() !== 'publish' || $p->is_type('variable')) { return new WP_Error('dapi_sku_invalid','Invalid SKU',array('status'=>404)); }
        $rec = $this->catalog_record($p);
        if (!$rec || ($rec['type'] ?? '') !== 'topup') { return new WP_Error('dapi_uid_unsupported','This SKU is not a validation-capable top-up',array('status'=>422)); }
        $cap = isset($rec['player_verification']) && is_array($rec['player_verification']) ? $rec['player_verification'] : array('supported'=>false);
        if (empty($cap['supported'])) { return new WP_Error('dapi_uid_unsupported','Server-side player verification is not currently available for this SKU',array('status'=>422)); }
        $input = is_array($body['fields'] ?? null) ? $body['fields'] : array();
        $fields = $this->validate_topup_fields($rec['fields'], $input);
        if (is_wp_error($fields)) { return $fields; }
        if (!class_exists('DFR_Catalog')) { return new WP_Error('dapi_uid_unavailable','Player verification service is unavailable',array('status'=>503)); }
        $result = DFR_Catalog::instance()->validate_reseller_topup_uid($p->get_id(), $fields, true);
        if (is_wp_error($result)) { return $result; }
        if (empty($result['supported']) || empty($result['valid'])) { return new WP_Error('dapi_uid_invalid','Player account could not be verified',array('status'=>422)); }
        return rest_ensure_response(array(
            'ok'=>true,
            'valid'=>true,
            'sku'=>$sku,
            'player_name'=>sanitize_text_field((string)($result['player_name'] ?? '')),
            'region'=>sanitize_text_field((string)($result['region'] ?? '')),
        ));
    }

    /* ---------------------------------------------------------------------
     * Orders
     * ------------------------------------------------------------------ */

    public function route_orders($r) {
        global $wpdb;
        $uid = $this->api_user($r);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE user_id=%d ORDER BY id DESC LIMIT 100", $uid));
        return rest_ensure_response(array('ok'=>true,'items'=>array_map(array($this,'public_order'),$rows)));
    }

    public function route_order($r) {
        global $wpdb;
        $uid = $this->api_user($r);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE public_id=%s AND user_id=%d", sanitize_text_field($r['id']), $uid));
        if (!$row) { return new WP_Error('dapi_order_not_found','Order not found',array('status'=>404)); }
        return rest_ensure_response(array('ok'=>true,'order'=>$this->public_order($row,true)));
    }

    private function validate_topup_fields($specs, $input) {
        $fields = array();
        foreach ((array)$specs as $spec) {
            $k = sanitize_key($spec['key'] ?? '');
            if (!$k) { continue; }
            $required = !empty($spec['required']);
            $present = array_key_exists($k, $input) && !is_array($input[$k]) && !is_object($input[$k]);
            $v = $present ? trim(sanitize_text_field((string)$input[$k])) : '';
            if ($required && $v === '') { return new WP_Error('dapi_field_required','Missing required field: '.$k,array('status'=>400)); }
            if (!$required && $v === '') { continue; }
            if (strlen($v) > 200) { return new WP_Error('dapi_field_invalid','Field is too long: '.$k,array('status'=>400)); }
            $type = sanitize_key($spec['type'] ?? 'text');
            if ($type === 'number' && !is_numeric($v)) { return new WP_Error('dapi_field_invalid','Field must be numeric: '.$k,array('status'=>400)); }
            if ($type === 'email' && !is_email($v)) { return new WP_Error('dapi_field_invalid','Field must be a valid email: '.$k,array('status'=>400)); }
            if ($type === 'select' && !empty($spec['options']) && is_array($spec['options']) && !in_array($v, $spec['options'], true)) { return new WP_Error('dapi_field_invalid','Invalid option for field: '.$k,array('status'=>400)); }
            $fields[$k] = $v;
        }
        return $fields;
    }

    private function supplier_mapping($p) {
        $id = $p->get_id();
        $parent = $p->get_parent_id() ?: $id;
        $type = (string)get_post_meta($id, '_dfr_service_type', true); if (!$type) { $type = (string)get_post_meta($parent, '_dfr_service_type', true); }
        $category = (string)get_post_meta($id, '_dfr_category_id', true); if (!$category) { $category = (string)get_post_meta($parent, '_dfr_category_id', true); }
        $offer = (string)get_post_meta($id, '_dfr_offer_id', true); if (!$offer) { $offer = (string)get_post_meta($parent, '_dfr_offer_id', true); }
        if (!in_array($type, array('topup','giftcard','gamekey'), true) || $category === '' || $offer === '') {
            return new WP_Error('dapi_mapping','Supplier mapping is incomplete for this product',array('status'=>409));
        }
        return array('type'=>$type,'category'=>$category,'offer'=>$offer);
    }

    private function order_request_context($public_id) {
        return 'reseller-request|order:' . substr(sanitize_text_field((string) $public_id), 0, 48);
    }

    private function order_result_context($public_id) {
        return 'reseller-result|order:' . substr(sanitize_text_field((string) $public_id), 0, 48);
    }

    private function encode_order_request($data, $public_id) {
        return DFR_Crypto::encrypt_context(wp_json_encode($data), $this->order_request_context($public_id));
    }

    private function decode_order_request($stored, $public_id = '') {
        $stored = (string) $stored;
        if ($stored === '') { return array(); }

        // v3.8 contextual AEAD first. Then migrate-compatible generic v2/v1 envelopes.
        $plain = '';
        if ($public_id !== '') {
            $plain = DFR_Crypto::decrypt_context($stored, $this->order_request_context($public_id));
        }
        if ($plain === '') {
            $plain = DFR_Crypto::decrypt($stored);
        }
        // Fail closed. Historical release builds used encrypted envelopes; accepting
        // arbitrary plaintext here would let a database write primitive alter the
        // supplier request body used by a retry.
        if ($plain === '') { return array(); }
        $data = json_decode((string) $plain, true);
        return is_array($data) ? $data : array();
    }

    private function encode_order_result($data, $public_id) {
        return DFR_Crypto::encrypt_context(wp_json_encode($data), $this->order_result_context($public_id));
    }

    private function decode_order_result($stored, $public_id) {
        $stored = (string) $stored;
        if ($stored === '') { return array(); }
        $plain = DFR_Crypto::decrypt_context($stored, $this->order_result_context($public_id));
        if ($plain === '') { $plain = DFR_Crypto::decrypt($stored); }
        $data = json_decode((string) $plain, true);
        return is_array($data) ? $data : array();
    }

    private function retry_delay($seconds) {
        $seconds = max(1, (int) $seconds);
        $spread = max(1, (int) floor($seconds * 0.15));
        try { return max(1, $seconds + random_int(-$spread, $spread)); }
        catch (Throwable $e) { return $seconds; }
    }


    private function adaptive_supplier_delay($attempt, $profile = 'poll') {
        $attempt = max(1, (int) $attempt);
        $sets = array(
            'create' => array(3, 6, 10, 20, 35, 60),
            'poll'   => array(3, 5, 8, 12, 20, 30, 45, 60, 90, 120),
            'code'   => array(2, 3, 5, 8, 12, 20, 30, 45, 60),
        );
        $series = isset($sets[$profile]) ? $sets[$profile] : $sets['poll'];
        return (int) $series[min($attempt, count($series)) - 1];
    }


    private function supplier_http_is_retryable($http) {
        $http = (int) $http;
        if ($http <= 0 || $http >= 500) { return true; }
        return in_array($http, array(408, 409, 425, 429), true);
    }

    private function schedule_reseller_action($hook, array $args, $delay) {
        // Both polling and supplier-submit retries are safe to schedule quickly:
        // supplier creation reuses the same immutable request and Idempotency-Key.
        $effective = max(1, (int) $delay);
        $when = time() + $this->retry_delay($effective);
        if (function_exists('as_schedule_single_action') && did_action('action_scheduler_init')) {
            as_schedule_single_action($when, $hook, $args, 'delicat-fazercards', true);
        } elseif (!wp_next_scheduled($hook, $args)) {
            // WP-Cron is traffic driven; schedule the requested short retry without adding an artificial floor.
            wp_schedule_single_event(time() + $this->retry_delay($effective), $hook, $args);
        }
    }

    /**
     * Create the native WooCommerce mirror order for a reseller API purchase.
     * The order starts pending so Woo's normal New Order notification is not
     * emitted until the Delicat Wallet debit has been confirmed.
     */
    private function create_woocommerce_reseller_order($dbid, $product, $qty, $amount, $currency, $uid, $public, $idem, array $fields) {
        global $wpdb;
        if (!function_exists('wc_create_order') || !$product || !$uid) {
            return new WP_Error('dapi_wc_order_unavailable', __('WooCommerce order creation is unavailable.', 'delicat-fazercards'));
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT woo_order_id FROM {$this->orders_table} WHERE id=%d", absint($dbid)));
        if ($row && !empty($row->woo_order_id)) {
            $existing = wc_get_order(absint($row->woo_order_id));
            if ($existing) { return $existing; }
        }
        try {
            $order = wc_create_order(array(
                'customer_id' => absint($uid),
                'status'      => 'pending',
                'created_via' => 'delicat_reseller_api',
            ));
            if (is_wp_error($order) || !$order) {
                return is_wp_error($order) ? $order : new WP_Error('dapi_wc_order_create', __('WooCommerce order could not be created.', 'delicat-fazercards'));
            }
            $order->set_currency($currency ?: get_woocommerce_currency());
            $order->set_payment_method('delicat_reseller_wallet');
            $order->set_payment_method_title(__('Delicat Reseller Wallet', 'delicat-fazercards'));
            $order->set_customer_id(absint($uid));

            $user = get_userdata(absint($uid));
            if ($user) {
                if ($user->user_email) { $order->set_billing_email($user->user_email); }
                $first = (string) get_user_meta($uid, 'billing_first_name', true);
                $last  = (string) get_user_meta($uid, 'billing_last_name', true);
                if ($first === '') { $first = (string) get_user_meta($uid, 'first_name', true); }
                if ($last === '')  { $last  = (string) get_user_meta($uid, 'last_name', true); }
                if ($first !== '') { $order->set_billing_first_name($first); }
                if ($last !== '')  { $order->set_billing_last_name($last); }
                $phone = (string) get_user_meta($uid, 'billing_phone', true);
                if ($phone !== '') { $order->set_billing_phone($phone); }
            }

            $line_total = (float) $amount;
            $item_id = $order->add_product($product, absint($qty), array(
                'subtotal' => $line_total,
                'total'    => $line_total,
            ));
            if (!$item_id) {
                $order->delete(true);
                return new WP_Error('dapi_wc_order_item', __('WooCommerce order item could not be created.', 'delicat-fazercards'));
            }
            $item = $order->get_item($item_id);
            if ($item) {
                foreach ($fields as $key => $value) {
                    if (!is_scalar($value)) { continue; }
                    // Keep player/account identifiers private so generic customer
                    // emails, exports and storefront renderers do not expose them.
                    $private_key = '_dfr_player_' . sanitize_key((string) $key);
                    $item->add_meta_data($private_key, sanitize_text_field((string) $value), true);
                }
                $item->add_meta_data('_dfr_api_sku', sanitize_text_field($product->get_sku()), true);
                $item->save();
            }

            $order->update_meta_data('_dfr_reseller_api_order', '1');
            $order->update_meta_data('_dfr_reseller_public_id', sanitize_text_field($public));
            $order->update_meta_data('_dfr_reseller_db_id', absint($dbid));
            $order->update_meta_data('_dfr_reseller_idempotency_key', sanitize_text_field($idem));
            $order->update_meta_data('_dfr_reseller_wallet_status', 'pending');
            $order->update_meta_data('_dfr_reseller_client_user_id', absint($uid));
            $order->calculate_totals(false);
            $order->add_order_note(sprintf(__('Reseller API order %s created. Awaiting Delicat Wallet debit.', 'delicat-fazercards'), $public), false);
            $order->save();

            $stored = $wpdb->update($this->orders_table, array('woo_order_id'=>$order->get_id(),'updated_at'=>current_time('mysql', true)), array('id'=>absint($dbid)));
            if ($stored === false) {
                $order->add_order_note(__('Warning: API ledger link could not be persisted. Supplier fulfillment was blocked for reconciliation.', 'delicat-fazercards'), false);
                return new WP_Error('dapi_wc_link_failed', __('WooCommerce order link could not be persisted.', 'delicat-fazercards'));
            }
            return $order;
        } catch (Throwable $e) {
            return new WP_Error('dapi_wc_order_exception', __('WooCommerce order creation failed safely.', 'delicat-fazercards'));
        }
    }

    /** Mark the native WooCommerce order paid and let Woo's own status hooks
     * generate the normal New Order/admin notification exactly once. */
    private function mark_woocommerce_reseller_paid($dbid, $wallet_ref) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", absint($dbid)));
        if (!$row || empty($row->woo_order_id)) { return; }
        $order = wc_get_order(absint($row->woo_order_id));
        if (!$order) { return; }
        if ($order->get_meta('_dfr_reseller_wallet_status', true) === 'debited') { return; }
        $order->update_meta_data('_dfr_reseller_wallet_status', 'debited');
        $order->update_meta_data('_dfr_reseller_wallet_ref', sanitize_text_field((string) $wallet_ref));
        if (!$order->get_date_paid()) { $order->set_date_paid(time()); }
        if (!$order->get_transaction_id()) { $order->set_transaction_id(sanitize_text_field((string) $wallet_ref)); }
        $order->save();
        if (function_exists('wc_reduce_stock_levels')) { wc_reduce_stock_levels($order->get_id()); }
        // pending -> processing is one of WooCommerce's standard New Order email
        // trigger transitions. Do not manually call WC_Email_New_Order as that
        // would risk sending duplicate administrator notifications.
        if ($order->get_status() === 'pending') {
            $order->update_status('processing', __('Delicat Wallet payment confirmed. Supplier fulfillment started.', 'delicat-fazercards'), false);
        } else {
            $order->add_order_note(__('Delicat Wallet payment confirmed. Supplier fulfillment started.', 'delicat-fazercards'), false);
        }
    }

    /** Keep the WooCommerce mirror order aligned with the API/supplier ledger. */
    private function sync_woocommerce_reseller_order($order_row, $state, $note = '') {
        if (!$order_row || empty($order_row->woo_order_id) || !function_exists('wc_get_order')) { return; }
        $order = wc_get_order(absint($order_row->woo_order_id));
        if (!$order) { return; }
        $state = sanitize_key((string) $state);
        $target = '';
        if ($state === 'completed') { $target = 'completed'; }
        elseif ($state === 'failed') { $target = 'failed'; }
        elseif ($state === 'refunded') { $target = 'refunded'; }
        elseif ($state === 'supplier_unknown') { $target = 'on-hold'; }
        elseif (in_array($state, array('created','processing'), true)) { $target = 'processing'; }
        if ($target === '') { return; }

        $order->update_meta_data('_dfr_reseller_api_status', $state);
        if (!empty($order_row->remote_order_id)) { $order->update_meta_data('_dfr_remote_order_id', sanitize_text_field((string) $order_row->remote_order_id)); }
        if (!empty($order_row->remote_status)) { $order->update_meta_data('_dfr_remote_status', sanitize_text_field((string) $order_row->remote_status)); }
        $order->save();

        if ($order->get_status() !== $target) {
            $default_note = array(
                'completed' => __('Digital fulfillment completed.', 'delicat-fazercards'),
                'failed' => __('Digital fulfillment failed.', 'delicat-fazercards'),
                'refunded' => __('Reseller wallet refund completed.', 'delicat-fazercards'),
                'supplier_unknown' => __('Fulfillment status is uncertain. No duplicate supplier order or automatic second debit will be created.', 'delicat-fazercards'),
                'processing' => __('Digital fulfillment is processing.', 'delicat-fazercards'),
                'created' => __('Digital fulfillment is processing.', 'delicat-fazercards'),
            );
            $order->update_status($target, $note !== '' ? sanitize_text_field($note) : ($default_note[$state] ?? ''), false);
        } elseif ($note !== '') {
            $order->add_order_note(sanitize_text_field($note), false);
        }
    }

    public function route_create_order($r) {
        global $wpdb;
        $raw_body = (string) $r->get_body();
        if (strlen($raw_body) > 32768) {
            return new WP_Error('dapi_payload_too_large','Order payload is too large',array('status'=>413));
        }
        if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) { return new WP_Error('dapi_woocommerce_required','WooCommerce unavailable',array('status'=>503)); }
        $rs = $this->reseller_settings();
        if ($rs['enabled'] !== '1') { return new WP_Error('dapi_disabled','Delicat Reseller API ordering is disabled',array('status'=>503)); }
        if ($rs['require_htg'] === '1' && get_woocommerce_currency() !== 'HTG') { return new WP_Error('dapi_currency_guard','Store currency must be HTG before API ordering can be enabled',array('status'=>503)); }
        $uid = $this->api_user($r);
        $plan = $this->require_active_gold($uid);
        if (is_wp_error($plan)) {
            $state = $this->gold_plan_state($uid);
            if ($state['status'] === 'expired' && $state['auto_renew']) {
                $renew = $this->activate_gold_from_wallet($uid, 'auto');
                if (is_wp_error($renew)) { return $renew; }
                $plan = true;
            }
            if (is_wp_error($plan)) { return $plan; }
        }
        $core = DFR_Plugin::instance()->get_settings();
        if (($core['live_orders_enabled'] ?? '0') !== '1') { return new WP_Error('dapi_live_disabled','Live supplier ordering is disabled by the store administrator',array('status'=>503)); }
        if (!$this->wallet_available()) { return new WP_Error('dapi_wallet_unavailable','Delicat Wallet is unavailable',array('status'=>503)); }

        $idem = trim((string)$r->get_header('idempotency-key'));
        if (strlen($idem) < 8 || strlen($idem) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/',$idem)) { return new WP_Error('dapi_idempotency_required','A valid Idempotency-Key header (8-128 characters) is required',array('status'=>400)); }

        $body = $r->get_json_params();
        if (!is_array($body)) { return new WP_Error('dapi_bad_json','JSON body required',array('status'=>400)); }
        // Bind the idempotency token to the canonical request body. Reusing a token
        // for a different purchase is a conflict, never a successful replay.
        $fingerprint_body = array(
            'sku' => sanitize_text_field($body['sku'] ?? ''),
            'quantity' => array_key_exists('quantity', $body) ? $body['quantity'] : 1,
            'fields' => is_array($body['fields'] ?? null) ? $body['fields'] : array(),
        );
        if (isset($fingerprint_body['fields']) && is_array($fingerprint_body['fields'])) { ksort($fingerprint_body['fields']); }
        $request_fingerprint = hash('sha256', wp_json_encode($fingerprint_body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE user_id=%d AND idempotency_key=%s", $uid, $idem));
        if ($old) {
            if (!empty($old->request_fingerprint) && !hash_equals((string)$old->request_fingerprint, $request_fingerprint)) {
                return new WP_Error('dapi_idempotency_conflict','This Idempotency-Key was already used for a different request',array('status'=>409));
            }
            return rest_ensure_response(array('ok'=>true,'idempotent_replay'=>true,'order'=>$this->public_order($old,true)));
        }
        $sku = sanitize_text_field($body['sku'] ?? '');
        $qty_raw = array_key_exists('quantity', $body) ? $body['quantity'] : 1;
        if (is_array($qty_raw) || is_object($qty_raw) || !is_numeric($qty_raw) || (float)$qty_raw != (int)$qty_raw || (int)$qty_raw < 1 || (int)$qty_raw > 100) {
            return new WP_Error('dapi_quantity', 'Quantity must be a whole number between 1 and 100', array('status'=>400));
        }
        $qty = (int)$qty_raw;
        $p = $this->product_by_sku($sku);
        if (!$p || $p->get_status() !== 'publish' || $p->is_type('variable')) { return new WP_Error('dapi_sku_invalid','Invalid SKU',array('status'=>404)); }
        $rec = $this->catalog_record($p);
        if (!$rec || !$p->is_in_stock()) { return new WP_Error('dapi_unavailable','Product unavailable',array('status'=>409)); }
        $mapping = $this->supplier_mapping($p); if (is_wp_error($mapping)) { return $mapping; }
        $min = max(1,(int)$rec['min_quantity']); $max = max($min,(int)$rec['max_quantity']);
        if ($qty < $min || $qty > $max) { return new WP_Error('dapi_quantity','Quantity outside allowed range',array('status'=>400)); }
        $input = is_array($body['fields'] ?? null) ? $body['fields'] : array();
        $fields = $this->validate_topup_fields($rec['fields'], $input);
        if (is_wp_error($fields)) { return $fields; }

        // For validation-capable top-ups, perform a fresh server-to-server UID
        // check before any Delicat Wallet debit. The API client cannot bypass this
        // by claiming that a Player ID was already verified on its own frontend.
        $uid_validation = array('supported' => false);
        if (($mapping['type'] ?? '') === 'topup' && class_exists('DFR_Catalog')) {
            $uid_validation = DFR_Catalog::instance()->validate_reseller_topup_uid($p->get_id(), $fields);
            if (is_wp_error($uid_validation)) { return $uid_validation; }
        }

        if (!$this->reseller_product_enabled($p->get_id())) { return new WP_Error('dapi_unavailable','Product is not enabled for reseller access',array('status'=>404)); }
        $unit_reseller_price = $this->reseller_product_price($p->get_id());
        if ($unit_reseller_price <= 0) { return new WP_Error('dapi_price_invalid','Reseller price unavailable',array('status'=>409)); }
        $amount = round($unit_reseller_price * $qty, 2);
        if ($amount > (float)$rs['max_order_amount']) { return new WP_Error('dapi_order_limit','Order exceeds the reseller API maximum amount',array('status'=>400,'max_amount'=>wc_format_decimal($rs['max_order_amount'],2),'currency'=>get_woocommerce_currency())); }
        if ($amount <= 0) { return new WP_Error('dapi_price_invalid','Product price unavailable',array('status'=>409)); }

        $public = 'dapiord_' . wp_generate_password(20, false, false);
        $request_safe = array(
            'v' => 2,
            'sku' => $sku,
            'quantity' => $qty,
            'fields' => $fields,
            // Immutable supplier mapping snapshot. Safe retries must send the exact
            // same body with the same upstream Idempotency-Key even if a product is
            // edited/re-imported while the order is in flight.
            'mapping' => array(
                'type' => sanitize_key((string) $mapping['type']),
                'category' => substr(sanitize_text_field((string) $mapping['category']), 0, 190),
                'offer' => substr(sanitize_text_field((string) $mapping['offer']), 0, 190),
                'validation_category' => !empty($uid_validation['supported'])
                    ? substr(sanitize_text_field((string) ($uid_validation['validation_category_id'] ?? '')), 0, 190)
                    : '',
            ),
        );
        $request_enc = $this->encode_order_request($request_safe, $public);
        if (is_wp_error($request_enc)) { return new WP_Error('dapi_secure_storage','Secure order storage is unavailable; order was not charged.',array('status'=>503)); }

        if (!$this->wallet_lock($uid)) { return new WP_Error('dapi_busy','Wallet is busy, retry shortly',array('status'=>409)); }
        try {
            $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE user_id=%d AND idempotency_key=%s", $uid, $idem));
            if ($old) {
                if (!empty($old->request_fingerprint) && !hash_equals((string)$old->request_fingerprint, $request_fingerprint)) {
                    return new WP_Error('dapi_idempotency_conflict','This Idempotency-Key was already used for a different request',array('status'=>409));
                }
                return rest_ensure_response(array('ok'=>true,'idempotent_replay'=>true,'order'=>$this->public_order($old,true)));
            }
            // Daily reseller spend guard is checked while holding the same wallet lock
            // used for the debit, preventing concurrent requests from bypassing the cap.
            $day_start = gmdate('Y-m-d 00:00:00');
            $spent_today = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM {$this->orders_table} WHERE user_id=%d AND created_at >= %s AND wallet_debit_ref IS NOT NULL AND wallet_debit_ref NOT LIKE 'PENDING:%%' AND status NOT IN ('failed','refunded')",
                $uid, $day_start
            ));
            $daily_limit = max(1, (float) $rs['daily_spend_limit']);
            if (($spent_today + $amount) > ($daily_limit + 0.00001)) {
                return new WP_Error('dapi_daily_spend_limit','Daily reseller spending limit reached',array('status'=>429,'limit'=>wc_format_decimal($daily_limit,2),'spent'=>wc_format_decimal($spent_today,2),'currency'=>get_woocommerce_currency()));
            }
            $balance = $this->wallet_balance($uid);
            if (is_wp_error($balance)) { return $balance; }
            if ($balance + 0.00001 < $amount) { return new WP_Error('dapi_insufficient_wallet','Insufficient Delicat Wallet balance',array('status'=>402,'balance'=>wc_format_decimal($balance,2),'required'=>wc_format_decimal($amount,2),'currency'=>get_woocommerce_currency())); }
            $now = current_time('mysql', true);
            $ok = $wpdb->insert($this->orders_table, array(
                'public_id'=>$public,
                'user_id'=>$uid,
                'idempotency_key'=>$idem,
                'request_fingerprint'=>$request_fingerprint,
                'product_id'=>$p->get_parent_id() ?: $p->get_id(),
                'variation_id'=>$p->get_parent_id() ? $p->get_id() : 0,
                'sku'=>$sku,
                'quantity'=>$qty,
                'amount'=>$amount,
                'currency'=>get_woocommerce_currency(),
                'status'=>'created',
                'request_json'=>$request_enc,
                'created_at'=>$now,
                'updated_at'=>$now,
            ), array('%s','%d','%s','%s','%d','%d','%s','%d','%f','%s','%s','%s','%s','%s'));
            if (!$ok || !$wpdb->insert_id) { return new WP_Error('dapi_order_create_failed','Could not create order',array('status'=>500)); }
            $dbid = (int)$wpdb->insert_id;

            // Create the native WooCommerce mirror order BEFORE any wallet debit.
            // It remains pending (no normal New Order email yet) until the wallet
            // charge is durably confirmed.
            $wc_order = $this->create_woocommerce_reseller_order($dbid, $p, $qty, $amount, get_woocommerce_currency(), $uid, $public, $idem, $fields);
            if (is_wp_error($wc_order)) {
                $wpdb->update($this->orders_table, array('status'=>'failed','updated_at'=>$now), array('id'=>$dbid));
                return new WP_Error('dapi_wc_sync_failed', 'WooCommerce order synchronization failed; no wallet debit was attempted.', array('status'=>503));
            }

            // Persist an explicit pre-debit marker before touching the wallet. If PHP or
            // the database connection dies at the exact ledger boundary, the order is
            // left for reconciliation instead of being silently submitted to the
            // supplier with an untracked wallet debit.
            $debit_marker = 'PENDING:' . substr(hash_hmac('sha256', $public . '|' . $uid . '|' . $amount, wp_salt('auth')), 0, 48);
            $marked = $wpdb->update(
                $this->orders_table,
                array('wallet_debit_ref'=>$debit_marker,'status'=>'created','updated_at'=>$now),
                array('id'=>$dbid)
            );
            if ($marked === false) {
                // No wallet debit happened, so do not leave a fake WooCommerce sale
                // or trigger failure notifications for an order that never existed financially.
                if ($wc_order && !is_wp_error($wc_order)) { $wc_order->delete(true); }
                $wpdb->update($this->orders_table, array('woo_order_id'=>null), array('id'=>$dbid));
                return new WP_Error('dapi_wallet_journal_failed','Could not prepare the wallet journal; no wallet debit was attempted.',array('status'=>503));
            }

            $ref = woo_wallet()->wallet->debit($uid, $amount, sprintf('Delicat Reseller API order %s', $public), array(
                'for' => 'delicat_reseller_order',
                'category' => 'digital_goods',
                'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG',
                'order_id' => $public,
            ));
            if (!$ref) {
                $wpdb->update($this->orders_table, array('wallet_debit_ref'=>null,'status'=>'failed','updated_at'=>$now), array('id'=>$dbid));
                // Debit definitely failed: remove the pending Woo mirror so the
                // administrator receives notifications only for real purchases.
                if ($wc_order && !is_wp_error($wc_order)) { $wc_order->delete(true); }
                $wpdb->update($this->orders_table, array('woo_order_id'=>null), array('id'=>$dbid));
                return new WP_Error('dapi_wallet_debit_failed','Wallet debit failed',array('status'=>409));
            }
            $tracked = $wpdb->update(
                $this->orders_table,
                array('wallet_debit_ref'=>(string)$ref,'status'=>'processing','updated_at'=>$now),
                array('id'=>$dbid)
            );
            if ($tracked === false) {
                if ($wc_order && !is_wp_error($wc_order)) {
                    $wc_order->update_meta_data('_dfr_reseller_wallet_status', 'reconciliation_required');
                    $wc_order->add_order_note(__('Wallet debit may have succeeded but the API ledger could not be finalized. Supplier fulfillment is blocked; administrator reconciliation is required.', 'delicat-fazercards'), false);
                    $wc_order->save();
                    $wc_order->update_status('on-hold', '', false);
                }
                // The wallet may already be debited. Do not risk a second financial
                // side effect or an untracked supplier purchase. The PENDING marker
                // remains as the reconciliation signal when possible.
                DFR_Plugin::instance()->log('error', 'Reseller wallet debit could not be journaled; supplier order blocked', array('public_id'=>$public, 'user_id'=>$uid));
                return new WP_Error('dapi_wallet_reconciliation','Wallet debit state requires administrator reconciliation; supplier order was not submitted.',array('status'=>503));
            }

            // A truthy wallet return value is not sufficient authorization for an
            // irreversible supplier purchase. Re-read TeraWallet's durable ledger.
            $ledger_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG';
            if (!$this->wallet_ledger_transaction_matches($uid, $ref, 'debit', $amount, $ledger_currency)) {
                $wpdb->update($this->orders_table, array('status'=>'supplier_unknown','updated_at'=>current_time('mysql', true)), array('id'=>$dbid));
                if ($wc_order && !is_wp_error($wc_order)) {
                    $wc_order->update_meta_data('_dfr_reseller_wallet_status', 'reconciliation_required');
                    $wc_order->add_order_note(__('Delicat security: wallet debit reference could not be verified in the TeraWallet ledger. Supplier fulfillment was blocked; administrator reconciliation is required.', 'delicat-fazercards'), false);
                    $wc_order->save();
                    $wc_order->update_status('on-hold', '', false);
                }
                return new WP_Error('dapi_wallet_reconciliation','Wallet transaction could not be verified in the TeraWallet ledger; supplier order was not submitted.',array('status'=>503));
            }

            // Wallet debit is now durably journaled and independently ledger-verified.
            // Transition pending -> processing so WooCommerce fires its standard New Order/admin notification hooks.
            $this->mark_woocommerce_reseller_paid($dbid, $ref);

            $this->submit_supplier($dbid);
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $dbid));
            $this->sync_woocommerce_reseller_order($row, $row ? $row->status : 'processing');
            return new WP_REST_Response(array('ok'=>true,'order'=>$this->public_order($row,true)), 201);
        } finally {
            $this->wallet_unlock($uid);
        }
    }

    private function reseller_order_requires_code($order_row) {
        if (!$order_row) { return false; }
        $req = $this->decode_order_request((string) $order_row->request_json, (string) $order_row->public_id);
        $mapping = is_array($req['mapping'] ?? null) ? $req['mapping'] : array();
        $type = sanitize_key((string) ($mapping['type'] ?? ''));
        if ($type === '' && !empty($order_row->variation_id ?: $order_row->product_id)) {
            $product = wc_get_product($order_row->variation_id ?: $order_row->product_id);
            if ($product) {
                $legacy = $this->supplier_mapping($product);
                if (!is_wp_error($legacy)) { $type = sanitize_key((string) ($legacy['type'] ?? '')); }
            }
        }
        return in_array($type, array('giftcard', 'gamekey'), true);
    }

    private function reseller_order_has_codes($order_row) {
        if (!$order_row || empty($order_row->result_enc)) { return false; }
        $result = $this->decode_order_result((string) $order_row->result_enc, (string) $order_row->public_id);
        return !empty($result['codes']) && is_array($result['codes']);
    }

    private function submit_supplier($dbid) {
        global $wpdb;
        $o = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $dbid));
        if (!$o) { return; }
        $req = $this->decode_order_request($o->request_json, $o->public_id);
        if (!$req) {
            // If this row has ever attempted supplier submission, a missing/corrupt
            // request snapshot is financially uncertain: the upstream may already
            // have accepted the order before our response was lost. Never issue an
            // automatic wallet refund in that state. Leave it for reconciliation.
            if ((int) $o->attempts > 0) {
                $wpdb->update($this->orders_table, array('status'=>'supplier_unknown','updated_at'=>current_time('mysql', true)), array('id'=>$dbid));
                $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $dbid));
                $this->sync_woocommerce_reseller_order($fresh, 'supplier_unknown');
            } else {
                $this->fail_and_refund($dbid, 'Encrypted order request could not be read before supplier submission');
            }
            return;
        }
        $mapping = is_array($req['mapping'] ?? null) ? $req['mapping'] : array();

        // v3.0-v3.7 rows did not snapshot the supplier mapping. Reconstructing a
        // request from today's product metadata would be unsafe because the same
        // upstream idempotency key may already be bound to a different body. Leave
        // such uncertain legacy rows for manual reconciliation rather than resending
        // or refunding blindly.
        if (!$mapping) {
            $wpdb->update(
                $this->orders_table,
                array('status'=>'supplier_unknown','updated_at'=>current_time('mysql', true)),
                array('id'=>$dbid)
            );
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $dbid));
            $this->sync_woocommerce_reseller_order($fresh, 'supplier_unknown');
            return;
        }
        $type = sanitize_key((string) ($mapping['type'] ?? ''));
        $category = substr(sanitize_text_field((string) ($mapping['category'] ?? '')), 0, 190);
        $offer = substr(sanitize_text_field((string) ($mapping['offer'] ?? '')), 0, 190);
        if (!in_array($type, array('topup','giftcard','gamekey'), true) || $category === '' || $offer === '') {
            if ((int) $o->attempts > 0) {
                $wpdb->update($this->orders_table, array('status'=>'supplier_unknown','updated_at'=>current_time('mysql', true)), array('id'=>$dbid));
                $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $dbid));
                $this->sync_woocommerce_reseller_order($fresh, 'supplier_unknown');
            } else {
                $this->fail_and_refund($dbid, 'Supplier mapping invalid before supplier submission');
            }
            return;
        }
        $fields = is_array($req['fields'] ?? null) ? $req['fields'] : array();
        $idem = 'delicat-api-' . $o->public_id;
        $api = DFR_Plugin::instance()->api();
        if ($type === 'topup') { $result = $api->create_topup($category, $offer, $fields, $idem); }
        elseif ($type === 'giftcard') { $result = $api->create_giftcard($category, $offer, $o->quantity, $idem); }
        elseif ($type === 'gamekey') { $result = $api->create_gamekey($category, $offer, $o->quantity, $idem); }
        else { $result = new WP_Error('dapi_mapping','Supplier mapping invalid',array('status'=>400)); }

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $http = is_array($data) ? absint($data['status'] ?? 0) : 0;
            $attempts = min(255, (int)$o->attempts + 1);
            $terminal = ($http >= 400 && $http < 500 && !$this->supplier_http_is_retryable($http));
            $retry_after = is_array($data) ? absint($data['retry_after'] ?? 0) : 0;
            $wpdb->update($this->orders_table, array('attempts'=>$attempts,'status'=>$terminal ? 'failed' : 'supplier_unknown','updated_at'=>current_time('mysql',true)), array('id'=>$dbid));
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $dbid));
            $this->sync_woocommerce_reseller_order($fresh, $terminal ? 'failed' : 'supplier_unknown');
            if ($terminal) { $this->refund_order($dbid, 'Supplier rejected order'); }
            elseif ($attempts < 5) {
                $base = ($http === 429 && $retry_after > 0) ? max(1, min(3600, $retry_after)) : $this->adaptive_supplier_delay($attempts, 'create');
                // Include the attempt solely in the unique action identity. The hook
                // accepts the first argument, so a running retry cannot suppress its
                // own required successor.
                $this->schedule_reseller_action('dfr_reseller_retry_supplier', array($dbid, $attempts), $base);
            }
            return;
        }

        $remote = sanitize_text_field($result['order']['id'] ?? $result['order_id'] ?? '');
        $status = $this->normalize_status($result['order']['status'] ?? $result['status'] ?? 'processing');
        if (!preg_match('/^ord-[0-9]+$/', $remote)) {
            $attempts = min(255, (int)$o->attempts + 1);
            $wpdb->update($this->orders_table, array('attempts'=>$attempts,'status'=>'supplier_unknown','updated_at'=>current_time('mysql',true)), array('id'=>$dbid));
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $dbid));
            $this->sync_woocommerce_reseller_order($fresh, 'supplier_unknown');
            if ($attempts < 5) { $this->schedule_reseller_action('dfr_reseller_retry_supplier', array($dbid, $attempts), $this->adaptive_supplier_delay($attempts, 'create')); }
            return;
        }

        $requires_code = $this->reseller_order_requires_code($o);
        $delivery_ready = !$requires_code;
        $update = array('remote_order_id'=>$remote,'remote_status'=>$status,'status'=>$status,'updated_at'=>current_time('mysql',true));
        if ($status === 'completed') {
            // Preserve synchronous `order.cards[]` / `order.keys[]` from the create
            // response. Only fall back to GET /orders/:id when no delivery was present.
            $safe = $this->safe_result($result);
            // Do not block the API/checkout request on a second long canonical GET
            // when the supplier status becomes completed before the code is attached.
            // The short durable poll below retrieves the code as soon as it exists.
            $delivery_ready = !$requires_code || !empty($safe['codes']);
            if (!empty($safe)) {
                $enc = $this->encode_order_result($safe, $o->public_id);
                if (!is_wp_error($enc)) { $update['result_enc'] = $enc; }
            }
            // A code product is not client-terminal until the code is actually stored.
            if (!$delivery_ready) { $update['status'] = 'processing'; }
        }
        $wpdb->update($this->orders_table, $update, array('id'=>$dbid));
        $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $dbid));
        $this->sync_woocommerce_reseller_order($fresh, $fresh ? $fresh->status : $status);
        if (in_array($status, array('failed','refunded'), true)) {
            $this->refund_order($dbid, 'Supplier ' . $status);
        } elseif ($status !== 'completed' || !$delivery_ready) {
            $this->schedule_reseller_action('dfr_reseller_poll_order', array($dbid,1), $status === 'completed' ? 2 : 4);
        }
    }

    private function fail_and_refund($dbid, $reason) {
        global $wpdb;
        $wpdb->update($this->orders_table, array('status'=>'failed','updated_at'=>current_time('mysql',true)), array('id'=>absint($dbid)));
        $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", absint($dbid)));
        $this->sync_woocommerce_reseller_order($fresh, 'failed', $reason);
        $this->refund_order($dbid, $reason);
    }

    public function retry_supplier_order($dbid) {
        global $wpdb;
        $o = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", absint($dbid)));
        if (!$o || $o->remote_order_id || in_array($o->status, array('completed','failed','refunded'), true)) { return; }
        if ((int)$o->attempts >= 5) {
            $wpdb->update($this->orders_table, array('status'=>'supplier_unknown','updated_at'=>current_time('mysql',true)), array('id'=>$o->id));
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $o->id));
            $this->sync_woocommerce_reseller_order($fresh, 'supplier_unknown');
            return;
        }
        $this->submit_supplier($o->id);
    }

    public function poll_supplier_order($dbid, $attempt = 1) {
        global $wpdb;
        $o = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", absint($dbid)));
        if (!$o || !$o->remote_order_id) { return; }
        $res = DFR_Plugin::instance()->read_canonical_supplier_order($o->remote_order_id, 6);
        if (is_wp_error($res)) {
            if ($attempt < 12) {
                $data = $res->get_error_data();
                $retry_after = is_array($data) ? absint($data['retry_after'] ?? 0) : 0;
                $base = $retry_after > 0 ? max(1, min(3600, $retry_after)) : $this->adaptive_supplier_delay($attempt, 'poll');
                $this->schedule_reseller_action('dfr_reseller_poll_order', array($o->id,$attempt+1), $base);
            }
            return;
        }
        $status = $this->normalize_status($res['order']['status'] ?? $res['status'] ?? 'processing');
        $this->apply_supplier_update($o->remote_order_id, $status, $res);
        $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $o->id));
        if ($fresh && !in_array($fresh->status, array('completed','failed','refunded'), true)) {
            if ($attempt < 12) {
                if ($fresh->remote_status === 'completed' && $this->reseller_order_requires_code($fresh)) {
                    $delay = $this->adaptive_supplier_delay($attempt, 'code');
                } else {
                    $delay = $this->adaptive_supplier_delay($attempt, 'poll');
                }
                $this->schedule_reseller_action('dfr_reseller_poll_order', array($o->id,$attempt+1), $delay);
            } elseif ($fresh->remote_status === 'completed' && $this->reseller_order_requires_code($fresh) && !$this->reseller_order_has_codes($fresh)) {
                $wpdb->update($this->orders_table, array('status'=>'supplier_unknown','updated_at'=>current_time('mysql',true)), array('id'=>$fresh->id));
                $latest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $fresh->id));
                $this->sync_woocommerce_reseller_order($latest, 'supplier_unknown');
            }
        }
    }

    /** Recurring GET-only repair for reseller API orders when their original queue
     * successor or supplier webhook was missed. The shared canonical reader enforces
     * the same account-wide request budget as normal WooCommerce orders. */
    public function reconcile_supplier_orders_watchdog($source = '') {
        global $wpdb;
        if (!$this->orders_table) { return; }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$this->orders_table}
             WHERE remote_order_id IS NOT NULL AND remote_order_id<>''
               AND status IN ('processing','supplier_unknown')
             ORDER BY id DESC LIMIT %d",
            12
        ));
        $checked = 0;
        foreach ((array) $rows as $row) {
            $id = absint($row->id ?? 0);
            if (!$id) { continue; }
            $this->poll_supplier_order($id, 1);
            if (++$checked >= 2) { break; }
        }
    }

    public function on_supplier_event($remote, $status, $payload) { $this->apply_supplier_update($remote, $status, $payload); }

    private function apply_supplier_update($remote, $status, $payload) {
        global $wpdb;
        $o = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE remote_order_id=%s", $remote));
        if (!$o) { return; }
        $status = $this->normalize_status($status);
        $previous = $this->normalize_status($o->remote_status ?: $o->status);
        if ($status === 'processing' && in_array($previous, array('completed','failed','refunded'), true)) {
            $status = $previous;
        } elseif (in_array($previous, array('failed','refunded'), true) && $status === 'completed') {
            $status = $previous;
        }
        $requires_code = $this->reseller_order_requires_code($o);
        $delivery_ready = !$requires_code;
        $update = array('remote_status'=>$status,'status'=>$status,'updated_at'=>current_time('mysql',true));
        if ($status === 'completed') {
            // Re-authorize the original reseller wallet debit at the moment delivery
            // becomes terminal. This mirrors normal WooCommerce fulfillment: a stale,
            // reversed or corrupted local journal must never be enough to expose a
            // supplier code merely because the supplier order itself is completed.
            $debit_currency = !empty($o->currency) ? (string) $o->currency : (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG');
            if (empty($o->wallet_debit_ref) || !$this->wallet_ledger_transaction_matches($o->user_id, $o->wallet_debit_ref, 'debit', (float) $o->amount, $debit_currency)) {
                $update['remote_status'] = 'completed';
                $update['status'] = 'processing';
                $wpdb->update($this->orders_table, $update, array('id'=>$o->id));
                DFR_Plugin::instance()->log('error', 'Reseller delivery blocked: original wallet debit is not verifiable', array('public_id'=>$o->public_id, 'user_id'=>$o->user_id));
                return;
            }
            $safe = $this->safe_result($payload);
            // Canonical webhook/poll payload is enough for this pass. If delivery is
            // not attached yet, queue the short code poll instead of blocking here.
            $delivery_ready = !$requires_code || !empty($safe['codes']);
            if (!empty($safe)) {
                $enc = $this->encode_order_result($safe, $o->public_id);
                if (!is_wp_error($enc)) { $update['result_enc'] = $enc; }
            }
            if (!$delivery_ready) { $update['status'] = 'processing'; }
        }
        $wpdb->update($this->orders_table, $update, array('id'=>$o->id));
        $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $o->id));
        $this->sync_woocommerce_reseller_order($fresh, $fresh ? $fresh->status : $status);
        if (in_array($status, array('failed','refunded'), true)) {
            $this->refund_order($o->id, 'Supplier ' . $status);
        } elseif ($status === 'completed' && !$delivery_ready) {
            $this->schedule_reseller_action('dfr_reseller_poll_order', array($o->id,1), 2);
        }
    }

    private function normalize_status($s) {
        $s = sanitize_key((string)$s);
        if (in_array($s, array('completed','complete','success','succeeded'), true)) { return 'completed'; }
        if (in_array($s, array('failed','failure','error','cancelled','canceled'), true)) { return 'failed'; }
        if (in_array($s, array('refund','refunded'), true)) { return 'refunded'; }
        return 'processing';
    }

    private function safe_result($data) {
        $found = array('codes' => array(), 'status' => 'completed');
        $max_codes = 100;
        $single_keys = array(
            'code','pin','serial','redeem_code','redeemcode','redemption_code','redemptioncode',
            'serial_number','serialnumber','card_code','cardcode','gift_code','giftcode',
            'gift_card_code','giftcardcode','activation_key','activationkey','activation_code','activationcode',
            'license_key','licensekey','digital_code','digitalcode','voucher_code','vouchercode'
        );
        $list_keys = array(
            'cards','keys','codes','pins','serials','vouchers','gift_cards','giftcards','gift_codes','giftcodes',
            'activation_keys','activationkeys','license_keys','licensekeys','digital_codes','digitalcodes'
        );
        $add = static function ($value) use (&$found, $max_codes) {
            if (count($found['codes']) >= $max_codes || !is_scalar($value)) { return; }
            $candidate = trim((string) $value);
            if ($candidate === '' || strlen($candidate) > 512) { return; }
            $found['codes'][] = sanitize_text_field($candidate);
        };
        $walk_delivery = function ($node, $depth = 0, $container = '') use (&$walk_delivery, $single_keys, $add, &$found, $max_codes) {
            if ($depth > 6 || count($found['codes']) >= $max_codes) { return; }
            if (is_scalar($node)) { $add($node); return; }
            if (!is_array($node)) { return; }
            foreach ($node as $k => $v) {
                if (count($found['codes']) >= $max_codes) { return; }
                $key = sanitize_key((string)$k);
                $allowed = in_array($key, $single_keys, true)
                    || ($container === 'keys' && $key === 'key')
                    || ($container === 'vouchers' && $key === 'voucher');
                if ($allowed && is_scalar($v)) { $add($v); }
                elseif (is_array($v)) { $walk_delivery($v, $depth + 1, $container); }
            }
        };
        $scan = function($node, $depth = 0) use (&$scan, $walk_delivery, &$found, $single_keys, $list_keys, $add, $max_codes) {
            if (!is_array($node) || $depth > 10 || count($found['codes']) >= $max_codes) { return; }
            foreach ($node as $k => $v) {
                if (count($found['codes']) >= $max_codes) { return; }
                $key = sanitize_key((string)$k);
                if (in_array($key, $single_keys, true)) {
                    if (is_scalar($v)) { $add($v); }
                    elseif (is_array($v)) { $scan($v, $depth + 1); }
                } elseif (in_array($key, $list_keys, true) && is_array($v)) {
                    $container = in_array($key, array('keys','license_keys','licensekeys','activation_keys','activationkeys'), true) ? 'keys'
                        : (in_array($key, array('vouchers'), true) ? 'vouchers' : $key);
                    foreach ($v as $entry) {
                        if (count($found['codes']) >= $max_codes) { break; }
                        $walk_delivery($entry, 0, $container);
                    }
                } elseif (is_array($v)) {
                    $scan($v, $depth + 1);
                }
            }
        };
        $scan(is_array($data) ? $data : array());
        $found['codes'] = array_slice(array_values(array_unique(array_filter($found['codes']))), 0, $max_codes);
        if (!$found['codes']) { unset($found['codes']); }
        return $found;
    }

    private function refund_order($dbid, $reason) {
        global $wpdb;
        $o = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", absint($dbid)));
        if (!$o || $o->wallet_refund_ref || !$o->wallet_debit_ref || !$this->wallet_available()) { return; }
        if (!$this->wallet_lock($o->user_id)) { return; }
        try {
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", absint($dbid)));
            if (!$fresh || $fresh->wallet_refund_ref) { return; }
            // Never create wallet value unless the original debit can still be proven
            // in TeraWallet's durable ledger. This closes a defense-in-depth gap where
            // a corrupted/stale reseller journal containing only a truthy debit ref
            // could otherwise become sufficient to authorize a credit.
            $debit_currency = !empty($fresh->currency) ? (string) $fresh->currency : (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG');
            if (!$this->wallet_ledger_transaction_matches($fresh->user_id, $fresh->wallet_debit_ref, 'debit', (float) $fresh->amount, $debit_currency)) {
                DFR_Plugin::instance()->log('error', 'Reseller refund blocked: original wallet debit is not verifiable', array('public_id'=>$fresh->public_id, 'user_id'=>$fresh->user_id));
                return;
            }
            // Claim the refund slot durably before crediting. A process crash after
            // wallet->credit() must never make a later retry credit the same order a
            // second time. A stranded PENDING marker is intentionally fail-closed and
            // must be reconciled against the wallet ledger by an administrator.
            $refund_marker = 'PENDING:' . substr(hash_hmac('sha256', $fresh->public_id . '|refund|' . $fresh->amount, wp_salt('auth')), 0, 48);
            $claimed = $wpdb->update(
                $this->orders_table,
                array('wallet_refund_ref'=>$refund_marker,'updated_at'=>current_time('mysql',true)),
                array('id'=>$fresh->id, 'wallet_refund_ref'=>null)
            );
            if ($claimed !== 1) { return; }

            $ref = woo_wallet()->wallet->credit($fresh->user_id, (float)$fresh->amount, sprintf('Refund %s — %s', $fresh->public_id, sanitize_text_field($reason)), array(
                'for' => 'delicat_reseller_refund',
                'category' => 'refund',
                'currency' => $fresh->currency ?: (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG'),
                'order_id' => $fresh->public_id,
            ));
            if ($ref) {
                $refund_currency = !empty($fresh->currency) ? (string) $fresh->currency : (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG');
                if (!$this->wallet_ledger_transaction_matches($fresh->user_id, $ref, 'credit', (float) $fresh->amount, $refund_currency)) {
                    // Never credit a second time after a truthy-but-unverifiable wallet
                    // response. Preserve the reference as an admin reconciliation marker.
                    $wpdb->update($this->orders_table, array('wallet_refund_ref'=>'UNVERIFIED:' . sanitize_text_field((string)$ref),'updated_at'=>current_time('mysql',true)), array('id'=>$dbid, 'wallet_refund_ref'=>$refund_marker));
                    DFR_Plugin::instance()->log('error', 'Reseller wallet refund reference could not be verified in TeraWallet ledger', array('public_id'=>$fresh->public_id, 'user_id'=>$fresh->user_id));
                    return;
                }
                $stored = $wpdb->update($this->orders_table, array('wallet_refund_ref'=>(string)$ref,'status'=>'refunded','updated_at'=>current_time('mysql',true)), array('id'=>$dbid, 'wallet_refund_ref'=>$refund_marker));
                if ($stored === 1) {
                    $latest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders_table} WHERE id=%d", $dbid));
                    if ($latest && !empty($latest->woo_order_id)) {
                        $wc = wc_get_order(absint($latest->woo_order_id));
                        if ($wc) {
                            $wc->update_meta_data('_dfr_reseller_wallet_status', 'refunded');
                            $wc->update_meta_data('_dfr_reseller_wallet_refund_ref', sanitize_text_field((string) $ref));
                            $wc->save();
                        }
                    }
                    $this->sync_woocommerce_reseller_order($latest, 'refunded', sprintf(__('Delicat Wallet refund completed: %s', 'delicat-fazercards'), sanitize_text_field((string) $reason)));
                    // Mirror the already-completed wallet refund into WooCommerce's
                    // native refund ledger without refunding the payment a second time.
                    if ($latest && !empty($latest->woo_order_id) && function_exists('wc_create_refund')) {
                        $wc = wc_get_order(absint($latest->woo_order_id));
                        if ($wc && !$wc->get_meta('_dfr_native_refund_created', true)) {
                            $native_refund = wc_create_refund(array(
                                'order_id' => $wc->get_id(),
                                'amount' => (float) $latest->amount,
                                'reason' => sanitize_text_field((string) $reason),
                                'refund_payment' => false,
                                'restock_items' => false,
                            ));
                            if (!is_wp_error($native_refund)) {
                                $wc->update_meta_data('_dfr_native_refund_created', (string) $native_refund->get_id());
                                $wc->save();
                            } else {
                                DFR_Plugin::instance()->log('error', 'WooCommerce native refund mirror failed after wallet refund', array('public_id'=>$latest->public_id, 'woo_order_id'=>$latest->woo_order_id));
                            }
                        }
                    }
                }
                if ($stored !== 1) {
                    DFR_Plugin::instance()->log('error', 'Reseller wallet refund succeeded but journal finalization failed', array('public_id'=>$fresh->public_id, 'user_id'=>$fresh->user_id));
                }
            } else {
                // Credit definitely failed; release the slot so a later controlled
                // retry may attempt the refund again.
                $wpdb->update($this->orders_table, array('wallet_refund_ref'=>null,'updated_at'=>current_time('mysql',true)), array('id'=>$dbid, 'wallet_refund_ref'=>$refund_marker));
            }
        } finally {
            $this->wallet_unlock($o->user_id);
        }
    }

    private function public_order($o, $with_result = false) {
        $r = array(
            'id'=>$o->public_id,
            'sku'=>$o->sku,
            'quantity'=>(int)$o->quantity,
            'amount'=>wc_format_decimal($o->amount,2),
            'currency'=>$o->currency,
            'status'=>$o->status,
            'created_at'=>$o->created_at,
            'updated_at'=>$o->updated_at,
        );
        if ($with_result && $o->status === 'completed' && $o->result_enc) {
            $d = $this->decode_order_result($o->result_enc, $o->public_id);
            if (is_array($d) && $d) { $r['delivery'] = $d; }
        }
        if ($o->status === 'refunded') { $r['wallet_refunded'] = true; }
        return $r;
    }
}
