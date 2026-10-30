<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Signed client for the FlashTopup Reseller API (v2).
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
                // First page failing is a hard error; a later page failing just
                // stops paging and returns what we already have.
                if ($page === 1) {
                    return $resp;
                }
                break;
            }

            $batch = $resp['data'];
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
        return $this->request('/check-id', $body);
    }

    /** Place an order. $extra holds the dynamic field values (user_id, server_id, ...). */
    public function order($service_code, $reference_id, $quantity, $extra = array())
    {
        $body = array_merge(array(
            'service_code' => (string) $service_code,
            'reference_id' => (string) $reference_id,
            'quantity'     => (int) $quantity,
        ), is_array($extra) ? $extra : array());
        return $this->request('/order', $body);
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
     * Signed POST request. Returns array:
     *   array('ok' => bool, 'status' => int, 'data' => mixed, 'message' => string, 'raw' => array)
     */
    public function request($endpoint, $body = array())
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
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $this->fail($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json)) {
            return $this->fail('Unexpected response from the API (HTTP ' . $code . ').', $code);
        }

        // The API uses two envelope shapes: {success,data,error} and {status,message,data}.
        $ok = (isset($json['success']) && $json['success'] === true)
            || (isset($json['status']) && ($json['status'] === true || $json['status'] === 'success'));

        if ($code >= 400 || !$ok) {
            $msg = '';
            if (isset($json['error']['message'])) {
                $msg = $json['error']['message'];
            } elseif (isset($json['message']) && is_string($json['message'])) {
                $msg = $json['message'];
            }
            return array(
                'ok'      => false,
                'status'  => $code,
                'data'    => isset($json['data']) ? $json['data'] : null,
                'message' => $msg !== '' ? $msg : ('Request failed (HTTP ' . $code . ').'),
                'raw'     => $json,
            );
        }

        return array(
            'ok'      => true,
            'status'  => $code,
            'data'    => isset($json['data']) ? $json['data'] : null,
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
