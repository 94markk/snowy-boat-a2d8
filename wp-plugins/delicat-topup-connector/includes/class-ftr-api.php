<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Signed client for the supplier's Reseller API (v2).
 *
 * Auth: HMAC-SHA256 over a canonical request, sent as headers:
 *   X-FT-API-ID, X-FT-Timestamp, X-FT-Nonce, X-FT-Signature
 *   canonical = METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256(rawBody)
 * Every call is POST with a signed JSON body (endpoints accept POST).
 */
class FTR_API
{
    private $apiId;
    private $apiKey;
    private $baseUrl;
    private $sandbox;

    public function __construct($settings = null)
    {
        $s = $settings ? $settings : ftr_settings();
        $this->apiId   = trim((string) $s['api_id']);
        $this->apiKey  = trim((string) $s['api_key']);
        $this->baseUrl = rtrim((string) $s['base_url'], '/');
        $this->sandbox = !empty($s['sandbox']);
    }

    public function is_configured()
    {
        return $this->apiId !== '' && $this->apiKey !== '' && $this->baseUrl !== '';
    }

    /** Account profile. */
    public function profile()
    {
        return $this->request('/profile');
    }

    /** Wallet balance. */
    public function balance()
    {
        return $this->request('/balance');
    }

    /** Paginated product list (single page). */
    public function products($page = 1, $per_page = 200)
    {
        return $this->request('/products', array('page' => (int) $page, 'per_page' => (int) $per_page));
    }

    /**
     * Fetch the ENTIRE catalog by walking every page (the API caps per_page at
     * 500). Returns the same envelope as products(), with 'data' holding all
     * products merged. Without this, large catalogs were silently truncated at
     * the first 200 rows.
     *
     * Any failing page fails the whole call: a partial catalog must never be
     * mistaken for the full one, or synchronization would treat the products
     * on the missing pages as removed by the supplier.
     */
    public function all_products($per_page = 500, $max_pages = 60)
    {
        $per_page = max(1, min(500, (int) $per_page));
        $all = array();
        $last = null;

        for ($page = 1; $page <= (int) $max_pages; $page++) {
            $resp = $this->request('/products', array('page' => $page, 'per_page' => $per_page));
            $last = $resp;
            if (empty($resp['ok']) || !is_array($resp['data'])) {
                if ($page > 1) {
                    $resp['ok'] = false;
                    $resp['message'] = 'Catalog page ' . $page . ' failed: ' . (isset($resp['message']) ? $resp['message'] : 'unknown error');
                }
                return $resp;
            }

            $batch = self::list_of($resp['data']);
            $all = array_merge($all, $batch);

            // Prefer the server's pagination hint; fall back to a short page.
            $meta = isset($resp['meta']) && is_array($resp['meta']) ? $resp['meta'] : array();
            $hasMore = array_key_exists('has_more', $meta)
                ? (bool) $meta['has_more']
                : (count($batch) >= $per_page);
            if (!$hasMore || count($batch) === 0) {
                break;
            }
        }

        return array(
            'ok'      => true,
            'status'  => isset($last['status']) ? $last['status'] : 200,
            'data'    => $all,
            'message' => 'OK',
            'meta'    => isset($last['meta']) ? $last['meta'] : null,
            'raw'     => isset($last['raw']) ? $last['raw'] : array(),
        );
    }

    /** Services (packages) for a product. */
    public function services($product_code, $product_type, $page = 1, $per_page = 500)
    {
        return $this->request('/services', array(
            'product_code' => (string) $product_code,
            'product_type' => (string) $product_type,
            'page'         => (int) $page,
            'per_page'     => (int) $per_page,
        ));
    }

    /** Validate a player/account id. */
    public function check_id($validation_code, $user_id, $server_id = '')
    {
        $body = array(
            'validation_code' => (string) $validation_code,
            'user_id'         => (string) $user_id,
        );
        if ($server_id !== '') {
            $body['server_id'] = (string) $server_id;
        }
        return $this->request('/check-id', $body, array('timeout' => 20));
    }

    /** Place an order. $extra holds the dynamic field values (user_id, server_id, ...). */
    public function order($service_code, $reference_id, $quantity, $extra = array())
    {
        $body = array_merge(array(
            'service_code' => (string) $service_code,
            'reference_id' => (string) $reference_id,
            'quantity'     => (int) $quantity,
        ), is_array($extra) ? $extra : array());
        // Never retry an order placement automatically: a timeout may still
        // have created the order upstream. Reconciliation by reference_id
        // picks up the real outcome instead.
        return $this->request('/order', $body, array('retries' => 0, 'timeout' => 45));
    }

    /** Order status by order id or reference id. */
    public function order_status($order_id = '', $reference_id = '')
    {
        $body = array();
        if ($order_id !== '') {
            $body['order_id'] = (string) $order_id;
        }
        if ($reference_id !== '') {
            $body['reference_id'] = (string) $reference_id;
        }
        return $this->request('/order/status', $body);
    }

    /** Bulk order status — up to 50 order ids and/or reference ids at once. */
    public function order_status_bulk($order_ids = array(), $reference_ids = array())
    {
        $body = array();
        $order_ids = array_values(array_filter(array_map('strval', (array) $order_ids)));
        $reference_ids = array_values(array_filter(array_map('strval', (array) $reference_ids)));
        if (!empty($order_ids)) {
            $body['order_ids'] = array_slice($order_ids, 0, 50);
        }
        if (!empty($reference_ids)) {
            $body['reference_ids'] = array_slice($reference_ids, 0, 50);
        }
        return $this->request('/orders/status', $body);
    }

    /**
     * Normalize list payloads: the API may return a plain list, a keyed map or
     * a wrapper such as {items:[...]} / {products:[...]} / {orders:[...]}.
     */
    public static function list_of($data)
    {
        if (!is_array($data)) {
            return array();
        }
        foreach (array('items', 'products', 'orders', 'list', 'results', 'data') as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return self::list_of($data[$key]);
            }
        }
        $values = array_values($data);
        // A single object (associative array of scalars) is not a list.
        if (!empty($values) && !is_array($values[0])) {
            return array($data);
        }
        return array_values(array_filter($values, 'is_array'));
    }

    /**
     * Signed POST request. Returns array:
     *   array('ok' => bool, 'status' => int, 'data' => mixed, 'message' => string, 'raw' => array)
     */
    public function request($endpoint, $body = array(), $opts = array())
    {
        $retries = isset($opts['retries']) ? max(0, (int) $opts['retries']) : 1;
        $timeout = isset($opts['timeout']) ? max(5, (int) $opts['timeout']) : 30;
        $attempt = 0;
        do {
            $res = $this->send($endpoint, $body, $timeout);
            // Retry only transport failures and 5xx/429 responses.
            $transient = empty($res['ok']) && ($res['status'] === 0 || $res['status'] === 429 || $res['status'] >= 500);
            if (!$transient || $attempt >= $retries) {
                return $res;
            }
            $attempt++;
            usleep(400000 * $attempt);
        } while (true);
    }

    private function send($endpoint, $body, $timeout)
    {
        if (!$this->is_configured()) {
            return $this->fail('Plugin is not configured — set your API ID, secret and base URL.');
        }

        $url     = $this->baseUrl . $endpoint;
        $path    = wp_parse_url($url, PHP_URL_PATH);
        $rawBody = empty($body) ? '{}' : wp_json_encode($body);
        $ts      = (string) time();
        $nonce   = $this->nonce();

        $canonical = "POST\n" . $path . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $rawBody);
        $signature = hash_hmac('sha256', $canonical, $this->apiKey);

        $headers = array(
            'X-FT-API-ID'    => $this->apiId,
            'X-FT-Timestamp' => $ts,
            'X-FT-Nonce'     => $nonce,
            'X-FT-Signature' => $signature,
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
        );
        if ($this->sandbox) {
            $headers['X-FT-Sandbox'] = 'true';
        }

        $response = wp_remote_post($url, array(
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => $rawBody,
            'timeout' => $timeout,
        ));

        if (is_wp_error($response)) {
            return $this->fail('Could not reach the API server: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $rawText = (string) wp_remote_retrieve_body($response);
        $json = json_decode($rawText, true);
        if (!is_array($json)) {
            $hint = $code === 404 ? ' Check the API Base URL.' : ($code >= 500 ? ' The API server had an error.' : '');
            if ($code >= 200 && $code < 300 && stripos($rawText, '<html') !== false) {
                $hint = ' The server returned an HTML page instead of JSON — check the API Base URL.';
            }
            return $this->fail('Unexpected response from the API (HTTP ' . $code . ').' . $hint, $code);
        }

        // The API uses two envelope shapes: {success,data,error} and
        // {status,message,data}. Treat any 2xx as success unless the envelope
        // explicitly reports a failure, so responses without a success flag
        // (or with status:"ok"/200) are not misreported as connection errors.
        $explicitFail = (array_key_exists('success', $json) && ($json['success'] === false || $json['success'] === 'false' || $json['success'] === 0))
            || (isset($json['status']) && in_array(is_string($json['status']) ? strtolower($json['status']) : $json['status'], array(false, 'error', 'failed', 'fail', 'false'), true))
            || (!empty($json['error']) && empty($json['success']) && !isset($json['data']));
        $ok = $code >= 200 && $code < 300 && !$explicitFail;

        if (!$ok) {
            $msg = '';
            $errCode = '';
            if (isset($json['error']) && is_array($json['error'])) {
                $msg = isset($json['error']['message']) ? (string) $json['error']['message'] : '';
                $errCode = isset($json['error']['code']) ? (string) $json['error']['code'] : '';
            } elseif (isset($json['error']) && is_string($json['error'])) {
                $msg = $json['error'];
            }
            if ($msg === '' && isset($json['message']) && is_string($json['message'])) {
                $msg = $json['message'];
            }
            if ($msg === '') {
                $msg = 'Request failed';
            }
            $suffix = trim(($errCode !== '' ? $errCode . ', ' : '') . 'HTTP ' . $code);
            if ($code === 401 || $code === 403) {
                $msg .= ' — verify the API ID / API Secret, that this server\'s IP is whitelisted in the supplier panel, and that the server clock is correct';
            }
            return array(
                'ok'      => false,
                'status'  => $code,
                'data'    => isset($json['data']) ? $json['data'] : null,
                'message' => $msg . ' (' . $suffix . ').',
                'raw'     => $json,
            );
        }

        return array(
            'ok'      => true,
            'status'  => $code,
            'data'    => array_key_exists('data', $json) ? $json['data'] : $json,
            'message' => isset($json['message']) && is_string($json['message']) ? $json['message'] : 'OK',
            'meta'    => isset($json['meta']) ? $json['meta'] : null,
            'raw'     => $json,
        );
    }

    private function nonce()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(12)); // 24 url-safe hex chars
        }
        return wp_generate_password(24, false, false);
    }

    private function fail($message, $status = 0)
    {
        return array('ok' => false, 'status' => $status, 'data' => null, 'message' => $message, 'raw' => array());
    }
}
