<?php
if (!defined('ABSPATH')) {
    exit;
}

final class DFR_API {
    const BASE_URL = 'https://api.fzr.cards/api/v2';
    const MAX_RESPONSE_BYTES = 2097152; // 2 MiB hard ceiling for supplier JSON.

    private $api_key;
    private $logger;

    public function __construct($api_key, $logger = null) {
        $this->api_key = trim((string) $api_key);
        $this->logger = $logger;
    }

    public function is_configured() {
        return $this->api_key !== '';
    }

    public function me() {
        return $this->request('GET', '/me');
    }

    public function balance() {
        return $this->request('GET', '/balance');
    }

    /** Read the webhook configuration owned by the authenticated FazerCards account. */
    public function webhook_settings() {
        return $this->request('GET', '/account/webhook');
    }

    /**
     * Point FazerCards at this store's signed webhook endpoint. The current API returns
     * an empty successful response for PUT, so explicitly allow that documented shape.
     */
    public function set_webhook($url, $enabled = true) {
        $url = esc_url_raw(trim((string) $url));
        if ($url === '' || !wp_http_validate_url($url) || strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return new WP_Error('dfr_invalid_webhook_url', __('The webhook URL must be a valid public HTTPS URL.', 'delicat-fazercards'));
        }
        return $this->request('PUT', '/account/webhook', array(), array(
            'url'     => $url,
            'enabled' => (bool) $enabled,
        ), '', 10, true);
    }

    /** Ask FazerCards to send its signed test event to the configured endpoint. */
    public function test_webhook() {
        // This endpoint deliberately returns HTTP 200 with {ok:false} when the receiver
        // was reached unsuccessfully; callers need that diagnostic payload intact.
        return $this->request('POST', '/account/webhook/test', array(), null, '', 10, false, true);
    }

    /** Read a bounded delivery history for diagnostics without exposing its secret. */
    public function webhook_deliveries($limit = 10) {
        return $this->request('GET', '/account/webhook/deliveries', array(
            'limit' => max(1, min(50, (int) $limit)),
        ));
    }

    public function topups($limit = 50, $cursor = '', $include_ui = false) {
        $query = array('limit' => max(1, min(100, (int) $limit)));
        if ((string) $cursor !== '') { $query['cursor'] = substr((string) $cursor, 0, 500); }
        if ($include_ui) { $query['include_ui'] = '1'; }
        return $this->request('GET', '/topups', $query);
    }

    public function topup_offers($category_id) {
        $category_id = $this->remote_id($category_id, 'category');
        if (is_wp_error($category_id)) { return $category_id; }
        return $this->request('GET', '/topups/offers', array('category_id' => $category_id));
    }

    /** Return the dynamic list of top-up categories supporting UID validation. */
    public function topup_validation_games() {
        return $this->request('GET', '/topups/validate-id');
    }

    public function validate_topup_id($category_id, array $fields) {
        $category_id = $this->remote_id($category_id, 'validation category');
        if (is_wp_error($category_id)) { return $category_id; }
        $clean = $this->clean_fields($fields);
        if (is_wp_error($clean)) { return $clean; }
        if (!$clean) {
            return new WP_Error('dfr_missing_validation_fields', __('Enter the player information before verification.', 'delicat-fazercards'));
        }
        // Player verification is an interactive storefront operation. Keep the
        // supplier read bounded so a slow upstream cannot freeze the product UI.
        return $this->request('POST', '/topups/validate-id', array(), array(
            'category_id' => $category_id,
            'fields'      => $clean,
        ), '', 8);
    }

    public function giftcards($limit = 50, $cursor = '', $include_ui = false) {
        $query = array('limit' => max(1, min(100, (int) $limit)));
        if ((string) $cursor !== '') { $query['cursor'] = substr((string) $cursor, 0, 500); }
        if ($include_ui) { $query['include_ui'] = '1'; }
        return $this->request('GET', '/giftcards', $query);
    }

    public function giftcard_cards($category_id) {
        $category_id = $this->remote_id($category_id, 'category');
        if (is_wp_error($category_id)) { return $category_id; }
        return $this->request('GET', '/giftcards/cards', array('category_id' => $category_id));
    }

    public function gamekeys($limit = 50, $cursor = '') {
        $query = array('limit' => max(1, min(100, (int) $limit)));
        if ((string) $cursor !== '') { $query['cursor'] = substr((string) $cursor, 0, 500); }
        return $this->request('GET', '/gamekeys', $query);
    }

    public function gamekey_keys($game_id) {
        $game_id = $this->remote_id($game_id, 'game');
        if (is_wp_error($game_id)) { return $game_id; }
        return $this->request('GET', '/gamekeys/keys', array('game_id' => $game_id));
    }

    public function get_order($remote_order_id, $timeout = 20) {
        $remote_order_id = trim((string) $remote_order_id);
        if (!preg_match('/^ord-[0-9]+$/', $remote_order_id)) {
            return new WP_Error('dfr_invalid_remote_order_id', __('Invalid upstream order ID.', 'delicat-fazercards'));
        }
        $result = $this->request('GET', '/orders/' . rawurlencode($remote_order_id), array(), null, '', $timeout);
        if (is_wp_error($result) || !is_array($result)) { return $result; }

        // Defense in depth: never bind delivery material from a malformed/misrouted
        // canonical response to a different WooCommerce order.
        $order = isset($result['order']) && is_array($result['order']) ? $result['order'] : $result;
        $returned_id = isset($order['id']) ? trim((string) $order['id']) : (isset($order['order_id']) ? trim((string) $order['order_id']) : '');
        if ($returned_id !== '' && !hash_equals($remote_order_id, $returned_id)) {
            $this->log('error', 'Canonical supplier order ID mismatch', array('requested_order_id' => $remote_order_id));
            return new WP_Error('dfr_remote_order_mismatch', __('Upstream order verification failed.', 'delicat-fazercards'));
        }
        return $result;
    }

    public function create_topup($category_id, $offer_id, array $fields, $idempotency_key) {
        $category_id = $this->remote_id($category_id, 'category');
        if (is_wp_error($category_id)) { return $category_id; }
        $offer_id = $this->remote_id($offer_id, 'offer');
        if (is_wp_error($offer_id)) { return $offer_id; }
        $fields = $this->clean_fields($fields);
        if (is_wp_error($fields)) { return $fields; }
        return $this->request('POST', '/topups/order', array(), array(
            'category_id' => $category_id,
            'offer_id'    => $offer_id,
            'fields'      => $fields,
        ), $idempotency_key, 10);
    }

    public function create_giftcard($category_id, $card_id, $quantity, $idempotency_key) {
        $category_id = $this->remote_id($category_id, 'category');
        if (is_wp_error($category_id)) { return $category_id; }
        $card_id = $this->remote_id($card_id, 'card');
        if (is_wp_error($card_id)) { return $card_id; }
        return $this->request('POST', '/giftcards/order', array(), array(
            'category_id' => $category_id,
            'card_id'     => $card_id,
            'quantity'    => max(1, min(100, (int) $quantity)),
        ), $idempotency_key, 10);
    }

    public function create_gamekey($game_id, $key_id, $quantity, $idempotency_key) {
        $game_id = $this->remote_id($game_id, 'game');
        if (is_wp_error($game_id)) { return $game_id; }
        $key_id = $this->remote_id($key_id, 'key');
        if (is_wp_error($key_id)) { return $key_id; }
        return $this->request('POST', '/gamekeys/order', array(), array(
            'game_id'  => $game_id,
            'key_id'   => $key_id,
            'quantity' => max(1, min(100, (int) $quantity)),
        ), $idempotency_key, 10);
    }

    private function remote_id($value, $label) {
        $value = trim((string) $value);
        // FazerCards public ids currently use letters/digits plus _, -, . and :.
        if ($value === '' || strlen($value) > 190 || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            return new WP_Error('dfr_invalid_remote_id', sprintf(__('Invalid upstream %s ID.', 'delicat-fazercards'), sanitize_text_field($label)));
        }
        return $value;
    }

    private function clean_fields(array $fields) {
        $clean = array();
        foreach (array_slice($fields, 0, 20, true) as $key => $value) {
            if (is_array($value) || is_object($value)) {
                return new WP_Error('dfr_invalid_field_value', __('Invalid upstream field value.', 'delicat-fazercards'));
            }
            $key = sanitize_key((string) $key);
            if ($key === '') { continue; }
            $value = trim(sanitize_text_field((string) $value));
            if (strlen($value) > 190) {
                return new WP_Error('dfr_field_too_long', __('An upstream order field is too long.', 'delicat-fazercards'));
            }
            if ($value !== '') { $clean[$key] = $value; }
        }
        return $clean;
    }

    private function request($method, $path, array $query = array(), $body = null, $idempotency_key = '', $timeout = 20, $allow_empty_success = false, $allow_ok_false = false) {
        if (!$this->is_configured()) {
            return new WP_Error('dfr_missing_api_key', __('Upstream API credentials are not configured.', 'delicat-fazercards'));
        }

        if (!preg_match('#^/[a-z0-9_?=&/.-]+$#i', $path)) {
            return new WP_Error('dfr_invalid_path', __('Invalid API path.', 'delicat-fazercards'));
        }

        $url = self::BASE_URL . $path;
        if ($query) {
            $safe_query = array();
            foreach ($query as $k => $v) {
                if (!is_scalar($v)) { continue; }
                $safe_query[sanitize_key((string)$k)] = sanitize_text_field((string)$v);
            }
            $url = add_query_arg($safe_query, $url);
        }

        $headers = array(
            'Accept'       => 'application/json',
            'X-API-Key'    => $this->api_key,
            'User-Agent'   => 'Delicat-Digital-Gateway/' . DFR_VERSION . '; ' . home_url('/'),
        );

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotency_key !== '') {
            $idempotency_key = substr(preg_replace('/[^A-Za-z0-9._:-]/', '-', (string) $idempotency_key), 0, 255);
            if ($idempotency_key === '') {
                return new WP_Error('dfr_invalid_idempotency_key', __('Invalid idempotency key.', 'delicat-fazercards'));
            }
            $headers['Idempotency-Key'] = $idempotency_key;
        }

        // Normal supplier operations may use the full timeout. Verified webhook
        // fast-path reads deliberately use a shorter bounded timeout so WooCommerce can
        // synchronize immediately without making the supplier webhook endpoint stall.
        $timeout = max(2, min(20, (int) $timeout));
        $args = array(
            'method'              => strtoupper($method),
            'headers'             => $headers,
            'timeout'             => $timeout,
            'redirection'         => 0,
            'sslverify'           => true,
            'reject_unsafe_urls'  => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'data_format'         => 'body',
        );

        if ($body !== null) {
            $json = wp_json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > 262144) {
                return new WP_Error('dfr_json_encode_failed', __('Could not safely encode the API request.', 'delicat-fazercards'));
            }
            $args['body'] = $json;
        }

        $this->log('debug', 'API request', array('method' => $method, 'path' => $path, 'timeout' => $timeout));
        $request_started = microtime(true);
        $response = wp_safe_remote_request($url, $args);
        $duration_ms = max(0, (int) round((microtime(true) - $request_started) * 1000));

        if (is_wp_error($response)) {
            $this->log('error', 'API transport error', array('path' => $path, 'duration_ms' => $duration_ms, 'error' => $response->get_error_message()));
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        if ($status === 429) {
            $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
            return new WP_Error('dfr_rate_limited', __('Upstream service rate limit reached. Retry later.', 'delicat-fazercards'), array(
                'status' => $status,
                'retry_after' => max(1, min(3600, $retry_after)),
            ));
        }

        if ($raw === '' && $allow_empty_success && $status >= 200 && $status < 300) {
            $this->log('debug', 'API success', array('status' => $status, 'path' => $path, 'duration_ms' => $duration_ms));
            return array('ok' => true);
        }
        if ($raw === '' || strlen($raw) > self::MAX_RESPONSE_BYTES) {
            return new WP_Error('dfr_invalid_response_size', __('The upstream service returned an empty or oversized response.', 'delicat-fazercards'), array('status' => $status));
        }
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return new WP_Error('dfr_invalid_json', __('The upstream service returned an invalid response.', 'delicat-fazercards'), array('status' => $status));
        }

        if ($status < 200 || $status >= 300 || (!$allow_ok_false && isset($decoded['ok']) && $decoded['ok'] === false)) {
            $provider_code = !empty($decoded['code']) ? sanitize_key((string)$decoded['code']) : '';
            $code = $provider_code !== '' ? 'dfr_' . $provider_code : 'dfr_api_error';
            // Provider human-readable errors may echo Player IDs or fulfillment input. Keep those
            // diagnostics out of WP_Error messages, order notes and logs; callers receive only bounded
            // machine metadata needed for retry/state decisions.
            $message = $provider_code !== ''
                ? sprintf(__('Upstream service rejected the request (HTTP %1$d, code %2$s).', 'delicat-fazercards'), $status, $provider_code)
                : sprintf(__('Upstream service error (HTTP %d).', 'delicat-fazercards'), $status);
            $this->log('warning', 'API error', array('status' => $status, 'path' => $path, 'provider_code' => $provider_code, 'duration_ms' => $duration_ms));
            return new WP_Error($code, $message, array('status' => $status, 'provider_code' => $provider_code));
        }

        $this->log('debug', 'API success', array('status' => $status, 'path' => $path, 'duration_ms' => $duration_ms));
        return $decoded;
    }

    private function log($level, $message, array $context = array()) {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $level, $message, $this->redact($context));
        }
    }

    private function redact($value) {
        $sensitive = array('api_key', 'apikey', 'token', 'authorization', 'code', 'codes', 'pin', 'pins', 'password', 'secret', 'serial', 'serials', 'serial_number', 'serialnumber', 'redeem_code', 'redeemcode', 'redemption_code', 'redemptioncode', 'gift_code', 'giftcode', 'gift_card_code', 'giftcardcode', 'card_code', 'cardcode', 'activation_key', 'activationkey', 'activation_code', 'activationcode', 'license_key', 'licensekey', 'digital_code', 'digitalcode', 'voucher_code', 'vouchercode', 'keys', 'vouchers', 'cards', 'fields', 'player_id', 'playerid', 'uid');
        if (is_array($value)) {
            $out = array();
            foreach ($value as $k => $v) {
                $key = strtolower((string) $k);
                if (in_array($key, $sensitive, true) || strpos($key, 'secret') !== false || strpos($key, 'token') !== false) {
                    $out[$k] = '[REDACTED]';
                } else {
                    $out[$k] = $this->redact($v);
                }
            }
            return $out;
        }
        return $value;
    }
}
