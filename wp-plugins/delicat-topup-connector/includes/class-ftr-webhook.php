<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inbound webhook receiver.
 *
 * Endpoint: POST /wp-json/topup/v1/n/<private token>
 * The token is generated per install, the route is hidden from the public
 * REST index, and nothing in the URL names the supplier. The pre-1.8 URL
 * (/wp-json/<supplier>/v1/callback) is still accepted while the
 * "legacy_webhook" setting is on, so existing callbacks keep working until
 * the supplier panel is updated.
 *
 * The supplier signs every webhook with HMAC-SHA256 over the same canonical
 * request format the outgoing API uses (METHOD\nPATH\nTIMESTAMP\nNONCE\n
 * sha256(rawBody)) with the headers X-FT-API-ID, X-FT-Timestamp, X-FT-Nonce
 * and X-FT-Signature. We verify all four, enforce a +/- 5 minute timestamp
 * window, and store the nonce so a captured webhook can't be replayed.
 *
 * Order events are applied to the matching WooCommerce line (reference_id
 * wc_<orderId>_<itemId>). Catalog events (product/service/price/stock)
 * trigger an immediate refresh of the affected products.
 */
class FTR_Webhook
{
    const ROUTE_NAMESPACE  = 'topup/v1';
    const LEGACY_NAMESPACE = 'flashtopup/v1';
    const LEGACY_ROUTE     = '/callback';
    const NONCE_TTL = 600;             // matches the backend nonce window
    const TS_SKEW = 300;               // +/- 5 minutes
    const LOG_MAX = 50;                // ring-buffered events stored in option

    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register'));
    }

    public function register()
    {
        register_rest_route(self::ROUTE_NAMESPACE, '/n/(?P<token>[a-z0-9]{16,64})', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_private'),
            'permission_callback' => '__return_true', // signature verification IS the auth
            'show_in_index'       => false,
        ));

        $s = ftr_settings();
        if (!empty($s['legacy_webhook'])) {
            register_rest_route(self::LEGACY_NAMESPACE, self::LEGACY_ROUTE, array(
                'methods'             => 'POST',
                'callback'            => array($this, 'handle'),
                'permission_callback' => '__return_true',
                'show_in_index'       => false,
            ));
        }
    }

    public function handle_private(WP_REST_Request $request)
    {
        $s = ftr_settings();
        $token = isset($s['webhook_token']) ? (string) $s['webhook_token'] : '';
        if ($token === '' || !hash_equals($token, (string) $request->get_param('token'))) {
            // Same answer WordPress gives for an unknown route.
            return new WP_REST_Response(array('code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.', 'data' => array('status' => 404)), 404);
        }
        return $this->handle($request);
    }

    public function handle(WP_REST_Request $request)
    {
        $body = $request->get_body();
        $headers = $request->get_headers();
        $apiId     = self::header($headers, 'x_ft_api_id');
        $timestamp = self::header($headers, 'x_ft_timestamp');
        $nonce     = self::header($headers, 'x_ft_nonce');
        $signature = self::header($headers, 'x_ft_signature');

        if ($apiId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return self::reject(401, 'UNAUTHORIZED', 'Unauthorized.');
        }

        $settings = ftr_settings();
        if (trim((string) $settings['api_id']) === '' || trim((string) $settings['api_key']) === '') {
            return self::reject(503, 'NOT_CONFIGURED', 'Service unavailable.');
        }

        // Reject foreign senders.
        if (!hash_equals(trim((string) $settings['api_id']), $apiId)) {
            self::log_event('rejected', 'api_id_mismatch', array('api_id' => $apiId));
            return self::reject(401, 'UNAUTHORIZED', 'Unauthorized.');
        }

        // Time window.
        $now = time();
        $ts = (int) $timestamp;
        if ($ts <= 0 || abs($now - $ts) > self::TS_SKEW) {
            self::log_event('rejected', 'timestamp_skew', array('ts' => $timestamp, 'now' => $now));
            return self::reject(401, 'TIMESTAMP_OUT_OF_RANGE', 'Request expired.');
        }

        // Verify the signature. The supplier signs the path it POSTed to; try
        // the actual request path first, then the canonical REST paths (sites
        // using plain permalinks receive it as /?rest_route=...).
        $secret = trim((string) $settings['api_key']);
        $bodyHash = hash('sha256', $body);
        $valid = false;
        foreach (self::candidate_paths($request) as $path) {
            $canonical = "POST\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $bodyHash;
            if (hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            self::log_event('rejected', 'bad_signature', array());
            return self::reject(401, 'UNAUTHORIZED', 'Unauthorized.');
        }

        // Nonce replay protection (checked after the signature so unsigned
        // requests cannot burn nonces).
        $nonceKey = 'ftr_wh_nonce_' . hash('sha256', $apiId . '|' . $nonce);
        if (get_transient($nonceKey)) {
            self::log_event('rejected', 'nonce_replay', array('nonce' => $nonce));
            return self::reject(401, 'NONCE_REUSED', 'Duplicate request.');
        }
        set_transient($nonceKey, 1, self::NONCE_TTL);

        // Parse and apply.
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return self::reject(400, 'BAD_JSON', 'Invalid body.');
        }
        // Some deployments wrap the event fields in a data object. Merge it
        // defensively while preserving top-level signed values.
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = array_merge($payload['data'], $payload);
            unset($payload['data']);
        }
        $event = isset($payload['event']) && is_scalar($payload['event']) ? (string) $payload['event'] : '';
        $referenceId = isset($payload['reference_id']) && is_scalar($payload['reference_id']) ? (string) $payload['reference_id'] : '';
        $orderId = isset($payload['order_id']) && is_scalar($payload['order_id']) ? (string) $payload['order_id'] : '';

        if (self::is_catalog_event($event, $payload)) {
            $applied = $this->apply_catalog_event($payload);
            self::log_event('accepted', $event, array('applied' => $applied));
        } else {
            $applied = $this->apply_to_wc_order($payload);
            self::log_event('accepted', $event, array(
                'reference_id' => $referenceId,
                'order_id'     => $orderId,
                'order_status' => FTR_Woo::extract_provider_status($payload),
                'applied'      => $applied,
            ));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Webhook accepted',
            'data'    => array(
                'event'        => $event,
                'reference_id' => $referenceId,
                'applied'      => $applied,
            ),
        ), 200);
    }

    /** Paths the supplier may have signed for this request. */
    private static function candidate_paths(WP_REST_Request $request)
    {
        $paths = array();
        if (!empty($_SERVER['REQUEST_URI'])) {
            $p = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
            if (is_string($p) && $p !== '') {
                $paths[] = $p;
            }
        }
        $route = $request->get_route(); // e.g. /topup/v1/n/abc...
        $restPath = wp_parse_url(rest_url(ltrim($route, '/')), PHP_URL_PATH);
        if (is_string($restPath) && $restPath !== '') {
            $paths[] = $restPath;
        }
        $paths[] = '/wp-json' . $route;
        $paths[] = $route;
        return array_values(array_unique($paths));
    }

    /** Product/service/price/stock events, or payloads without an order reference. */
    private static function is_catalog_event($event, array $payload)
    {
        $event = strtolower($event);
        if ($event !== '' && preg_match('/^(product|products|service|services|catalog|catalogue|price|prices|stock|inventory)[._]/', $event)) {
            return true;
        }
        return $event === '' && empty($payload['reference_id']) && empty($payload['order_id'])
            && (!empty($payload['product_code']) || !empty($payload['service_code']) || !empty($payload['product_codes']));
    }

    private function apply_catalog_event(array $payload)
    {
        $codes = array();
        foreach (array('product_code', 'product_codes') as $key) {
            if (!empty($payload[$key])) {
                $codes = array_merge($codes, (array) $payload[$key]);
            }
        }
        $services = array();
        foreach (array('service_code', 'service_codes') as $key) {
            if (!empty($payload[$key])) {
                $services = array_merge($services, (array) $payload[$key]);
            }
        }
        if (!empty($services)) {
            $codes = array_merge($codes, FTR_Sync::product_codes_for_services($services));
        }
        $codes = array_values(array_unique(array_filter(array_map('strval', array_filter($codes, 'is_scalar')))));
        sort($codes);
        // Refresh right after this response is sent (keeps the webhook fast).
        FTR_Sync::queue_now(FTR_Sync::CRON_PRODUCTS, array($codes));
        return empty($codes) ? 'full_sync_queued' : 'sync_queued:' . implode(',', array_slice($codes, 0, 10));
    }

    /**
     * Find the WooCommerce order/item the reference_id refers to and update
     * status. reference_id shape (set in FTR_Woo::fulfill): wc_<orderId>_<itemId>.
     */
    private function apply_to_wc_order(array $payload)
    {
        if (!class_exists('WC_Order_Factory')) {
            return 'no_woocommerce';
        }
        $ref = isset($payload['reference_id']) && is_scalar($payload['reference_id']) ? (string) $payload['reference_id'] : '';
        $itemId = 0;
        $orderId = 0;
        if (preg_match('/^wc_(\d+)_(\d+)$/', $ref, $m)) {
            $orderId = (int) $m[1];
            $itemId = (int) $m[2];
        }
        if (!$orderId || !$itemId) {
            // Fallback for callbacks that contain the provider order ID but
            // omit/alter our reference_id. Search only our item meta.
            $providerLookup = isset($payload['order_id']) && is_scalar($payload['order_id']) ? sanitize_text_field((string) $payload['order_id']) : '';
            if ($providerLookup !== '') {
                global $wpdb;
                $itemId = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = '_ftr_order_id' AND meta_value = %s LIMIT 1",
                    $providerLookup
                ));
                if ($itemId) {
                    $orderId = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d LIMIT 1",
                        $itemId
                    ));
                }
            }
        }
        if (!$orderId || !$itemId) {
            return 'reference_unrecognized';
        }
        if (!wc_get_order($orderId)) {
            return 'wc_order_not_found';
        }

        $changed = FTR_Woo::apply_provider_update($orderId, $itemId, $payload, 'webhook');
        return $changed ? 'applied' : 'unchanged';
    }

    /* ----- helpers ----- */

    private static function header(array $headers, $key)
    {
        $v = $headers[$key] ?? $headers[strtolower($key)] ?? null;
        if (is_array($v)) {
            $v = reset($v);
        }
        return is_string($v) ? trim($v) : '';
    }

    private static function reject($status, $code, $message)
    {
        return new WP_REST_Response(array(
            'success' => false,
            'error'   => array('code' => $code, 'message' => $message),
        ), $status);
    }

    public static function log_event($outcome, $event, array $extra = array())
    {
        $log = get_option(FTR_WEBHOOK_LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        array_unshift($log, array(
            'at'      => current_time('mysql'),
            'outcome' => $outcome,
            'event'   => $event,
            'extra'   => $extra,
        ));
        $log = array_slice($log, 0, self::LOG_MAX);
        update_option(FTR_WEBHOOK_LOG, $log, false);
    }
}
