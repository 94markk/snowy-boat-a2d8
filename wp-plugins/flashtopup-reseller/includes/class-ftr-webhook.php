<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inbound webhook receiver.
 *
 * Endpoint: POST /wp-json/flashtopup/v1/callback
 *
 * The FlashTopup backend signs every webhook with HMAC-SHA256 over the same
 * canonical request format the outgoing API uses (METHOD\nPATH\nTIMESTAMP\n
 * NONCE\nsha256(rawBody)) and includes the headers X-FT-API-ID, X-FT-Timestamp,
 * X-FT-Nonce and X-FT-Signature.
 *
 * We verify all four, enforce a +/- 5 minute timestamp window, and store the
 * nonce as a transient so a captured webhook can't be replayed within its
 * lifetime. Verified events are applied to the matching WooCommerce order
 * (by reference_id, set to <wpOrderId>-<itemId>-... at order-placement time
 * in class-ftr-woo.php).
 */
class FTR_Webhook
{
    const ROUTE_NAMESPACE = 'flashtopup/v1';
    const ROUTE = '/callback';
    const NONCE_TTL = 600;             // matches the backend nonce window
    const TS_SKEW = 300;               // +/- 5 minutes
    const LOG_MAX = 50;                // ring-buffered events stored in option

    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register'));
    }

    public function register()
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE, array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle'),
            'permission_callback' => '__return_true', // signature verification IS the auth
        ));
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
            return self::reject(401, 'AUTH_FIELDS_MISSING', 'Missing X-FT-* authentication headers.');
        }

        $settings = ftr_settings();
        if (trim((string) $settings['api_id']) === '' || trim((string) $settings['api_key']) === '') {
            return self::reject(503, 'PLUGIN_NOT_CONFIGURED', 'Plugin has no API credentials configured.');
        }

        // Reject foreign senders.
        if (!hash_equals(trim((string) $settings['api_id']), $apiId)) {
            self::log_event('rejected', 'api_id_mismatch', array('api_id' => $apiId));
            return self::reject(401, 'API_ID_MISMATCH', 'API ID does not match this WordPress install.');
        }

        // Time window.
        $now = time();
        $ts = (int) $timestamp;
        if ($ts <= 0 || abs($now - $ts) > self::TS_SKEW) {
            self::log_event('rejected', 'timestamp_skew', array('ts' => $timestamp, 'now' => $now));
            return self::reject(401, 'TIMESTAMP_OUT_OF_RANGE', 'Webhook timestamp is outside the +/-5 minute window.');
        }

        // Nonce replay protection.
        $nonceKey = 'ftr_wh_nonce_' . hash('sha256', $apiId . '|' . $nonce);
        if (get_transient($nonceKey)) {
            self::log_event('rejected', 'nonce_replay', array('nonce' => $nonce));
            return self::reject(401, 'NONCE_REUSED', 'Nonce has already been used.');
        }
        set_transient($nonceKey, 1, self::NONCE_TTL);

        // Verify signature.
        $path = parse_url(rest_url(self::ROUTE_NAMESPACE . self::ROUTE), PHP_URL_PATH);
        $canonical = "POST\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
        $expected = hash_hmac('sha256', $canonical, trim((string) $settings['api_key']));
        if (!hash_equals($expected, $signature)) {
            self::log_event('rejected', 'bad_signature', array());
            return self::reject(401, 'INVALID_SIGNATURE', 'Webhook signature does not match.');
        }

        // Parse and apply.
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return self::reject(400, 'BAD_JSON', 'Webhook body is not valid JSON.');
        }
        // Some provider deployments wrap the event fields in a data object.
        // Merge it defensively while preserving top-level signed values.
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = array_merge($payload['data'], $payload);
            unset($payload['data']);
        }
        $event = (string) ($payload['event'] ?? '');
        $referenceId = (string) ($payload['reference_id'] ?? '');
        $orderId = (string) ($payload['order_id'] ?? '');
        $orderStatus = (string) ($payload['order_status'] ?? ($payload['status'] ?? ''));

        $applied = $this->apply_to_wc_order($payload);

        self::log_event('accepted', $event, array(
            'reference_id' => $referenceId,
            'order_id'     => $orderId,
            'order_status' => $orderStatus,
            'applied'      => $applied,
        ));

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

    /**
     * Find the WooCommerce order/item the reference_id refers to and update
     * status. reference_id shape (set in FTR_Woo::fulfill): wc_<orderId>_<itemId>.
     */
    private function apply_to_wc_order(array $payload)
    {
        if (!class_exists('WC_Order_Factory')) {
            return 'no_woocommerce';
        }
        $ref = (string) ($payload['reference_id'] ?? '');
        $itemId = 0;
        $orderId = 0;
        if (preg_match('/^wc_(\d+)_(\d+)$/', $ref, $m)) {
            $orderId = (int) $m[1];
            $itemId = (int) $m[2];
        }
        if (!$orderId || !$itemId) {
            // Fallback for callbacks that contain the provider order ID but
            // omit/alter our reference_id. Search only FlashTopup item meta.
            $providerLookup = sanitize_text_field((string) ($payload['order_id'] ?? ''));
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
        $order = wc_get_order($orderId);
        if (!$order) {
            return 'wc_order_not_found';
        }

        $status = FTR_Woo::extract_provider_status($payload);
        $providerOrderId = (string) ($payload['order_id'] ?? '');
        $codes = isset($payload['codes']) && is_array($payload['codes']) ? $payload['codes'] : array();
        $note = (string) ($payload['note'] ?? '');

        // Persist provider info on the line item so it shows in the admin.
        if ($providerOrderId !== '') {
            wc_update_order_item_meta($itemId, '_ftr_order_id', sanitize_text_field($providerOrderId));
        }
        if ($status !== '') {
            wc_update_order_item_meta($itemId, '_ftr_status', $status);
        }
        if (!empty($codes)) {
            wc_update_order_item_meta($itemId, '_ftr_codes', implode(', ', array_map('sanitize_text_field', $codes)));
        }
        if ($note !== '') {
            wc_update_order_item_meta($itemId, '_ftr_note', sanitize_text_field($note));
        }

        // Build an order note and optionally transition status.
        $serviceName = (string) ($payload['service_name'] ?? '');
        $body = sprintf(
            'FlashTopup %s: %s (%s, order_id=%s)%s',
            $status,
            $serviceName,
            $ref,
            $providerOrderId,
            !empty($codes) ? ' codes: ' . implode(', ', $codes) : ''
        );
        $order->add_order_note($body);

        do_action('ftr_provider_item_status_updated', $orderId, $itemId, $status);

        if ($status === 'completed') {
            // Reload the order after item-meta writes. This avoids stale object
            // caches (including HPOS environments) keeping it in Processing.
            $freshOrder = wc_get_order($orderId);
            FTR_Woo::maybe_complete_order($freshOrder ? $freshOrder : $order);
        } elseif ($status === 'failed' && !in_array($order->get_status(), array('failed', 'cancelled', 'refunded'), true)) {
            $order->add_order_note('FlashTopup reported FAILED — review and refund manually.');
        }

        return 'applied';
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
