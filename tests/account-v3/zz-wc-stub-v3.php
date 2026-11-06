<?php
/**
 * TEST-ONLY WooCommerce + TeraWallet stub for the my-account-customer audit.
 * Mimics the WooCommerce 9.x semantics of the functions used by the audited files
 * (is_wc_endpoint_url, is_account_page, wc_get_account_endpoint_url, my-account template hooks).
 */


final class WooCommerce {
    public $query; public $session = null; public $cart = null;
    private static $inst;
    public static function instance() { return self::$inst ?: (self::$inst = new self()); }
    private function __construct() { $this->query = new DIPSTUB_WC_Query(); }
}
final class DIPSTUB_WC_Query {
    public $query_vars = [];
    public function __construct() {
        $this->query_vars = [
            'order-pay' => 'order-pay', 'order-received' => 'order-received',
            'orders' => 'orders', 'view-order' => 'view-order', 'downloads' => 'downloads',
            'edit-account' => 'edit-account', 'edit-address' => 'edit-address',
            'payment-methods' => 'payment-methods', 'lost-password' => 'lost-password',
            'customer-logout' => 'customer-logout', 'add-payment-method' => 'add-payment-method',
        ];
        if (get_option('dipstub_wallet', '1') === '1') $this->query_vars['woo-wallet'] = 'woo-wallet'; // TeraWallet registers via woocommerce_get_query_vars
    }
    public function get_query_vars() { return apply_filters('woocommerce_get_query_vars', $this->query_vars); }
}
function WC() { return WooCommerce::instance(); }

add_action('init', function () {
    foreach (WC()->query->get_query_vars() as $var) add_rewrite_endpoint($var, EP_ROOT | EP_PAGES);
}, 5);
add_filter('query_vars', function ($vars) { foreach (WC()->query->get_query_vars() as $v) $vars[] = $v; return $vars; });

function wc_get_page_id($page) { return (int) get_option('woocommerce_' . $page . '_page_id', -1); }
function wc_get_page_permalink($page) { $id = wc_get_page_id($page); return $id > 0 ? get_permalink($id) : home_url('/'); }
function wc_get_checkout_url() { return wc_get_page_permalink('checkout'); }
function is_account_page() {
    $page_id = wc_get_page_id('myaccount');
    return ($page_id && is_page($page_id)) || apply_filters('woocommerce_is_account_page', false);
}
function is_checkout() { $id = wc_get_page_id('checkout'); return ($id && is_page($id)); }
function is_order_received_page() { return false; }
function is_wc_endpoint_url($endpoint = false) {
    global $wp;
    $wc_endpoints = WC()->query->get_query_vars();
    if (false !== $endpoint) {
        if (!isset($wc_endpoints[$endpoint])) return false;
        $endpoint_var = $wc_endpoints[$endpoint];
        return isset($wp->query_vars[$endpoint_var]);
    }
    foreach ($wc_endpoints as $key => $value) { if (isset($wp->query_vars[$key])) return true; }
    return false;
}
function wc_get_endpoint_url($endpoint, $value = '', $permalink = '') {
    if (!$permalink) $permalink = get_permalink();
    $query_vars = WC()->query->get_query_vars();
    $endpoint = !empty($query_vars[$endpoint]) ? $query_vars[$endpoint] : $endpoint;
    if (get_option('permalink_structure')) {
        if (strstr($permalink, '?')) { $query_string = '?' . wp_parse_url($permalink, PHP_URL_QUERY); $permalink = current(explode('?', $permalink)); } else { $query_string = ''; }
        $url = trailingslashit($permalink);
        $url .= $value ? trailingslashit($endpoint) . user_trailingslashit($value) : user_trailingslashit($endpoint);
        $url .= $query_string;
    } else {
        $url = add_query_arg($endpoint, $value, $permalink);
    }
    return apply_filters('woocommerce_get_endpoint_url', $url, $endpoint, $value, $permalink);
}
function wc_get_account_endpoint_url($endpoint) {
    if ('dashboard' === $endpoint) return wc_get_page_permalink('myaccount');
    if ('customer-logout' === $endpoint) return wp_nonce_url(wc_get_endpoint_url('customer-logout', '', wc_get_page_permalink('myaccount')), 'customer-logout');
    return wc_get_endpoint_url($endpoint, '', wc_get_page_permalink('myaccount'));
}
function wc_price($price, $args = []) {
    $neg = $price < 0; $price = abs((float) $price);
    if (get_option('dipstub_htg')) return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">G</span>' . ($neg ? '-' : '') . number_format($price, 2, '.', ',') . '</bdi></span>';
    return '<span class="woocommerce-Price-amount amount"><bdi>' . ($neg ? '-' : '') . number_format($price, 2, ',', ' ') . '&nbsp;<span class="woocommerce-Price-currencySymbol">&euro;</span></bdi></span>';
}
function wc_add_notice($m, $t = 'success') { $GLOBALS['dipstub_notices'][] = [$t, $m]; }
function wc_get_order_status_name($status) { $s = ['pending'=>'En attente','processing'=>'En cours','completed'=>'Terminée','on-hold'=>'En attente','cancelled'=>'Annulée']; return $s[$status] ?? $status; }
function wc_format_datetime($date, $format = '') { return $date ? $date->date_i18n($format ?: get_option('date_format')) : ''; }
function wc_get_customer_order_count($uid) { $GLOBALS['dipstub_calls'][] = 'order_count'; return count(dipstub_orders_for($uid)); }
function wc_get_customer_total_spent($uid) { $GLOBALS['dipstub_calls'][] = 'total_spent'; $t = 0; foreach (dipstub_orders_for($uid) as $o) $t += $o->total; return $t; }

class DIPSTUB_DT extends DateTime { public function date_i18n($f) { return date_i18n($f, $this->getTimestamp()); } }
class DIPSTUB_Item { public $n; public function __construct($n) { $this->n = $n; } public function get_name() { return $this->n; } public function get_product() { return null; } }
class DIPSTUB_Meta { public $key, $value; public function __construct($k, $v) { $this->key = $k; $this->value = $v; } }
class DIPSTUB_VO_Item {
    public $d;
    public function __construct($d) { $this->d = $d + ['qty' => 1, 'subtotal' => 0, 'meta' => [], 'id' => 1]; }
    public function get_id() { return $this->d['id']; }
    public function get_name() { return $this->d['name']; }
    public function get_quantity() { return $this->d['qty']; }
    public function get_subtotal() { return $this->d['subtotal']; }
    public function get_subtotal_tax() { return 0; }
    public function get_total() { return $this->d['subtotal']; }
    public function get_product() { return null; }
    public function get_meta($k, $single = true) { return $this->d['meta'][$k] ?? ''; }
    public function get_meta_data() { $o = []; foreach ($this->d['meta'] as $k => $v) $o[] = new DIPSTUB_Meta($k, $v); return $o; }
}
class WC_Order {
    public $d, $id, $customer, $status, $total, $name;
    public function __construct($id, $customer = 0, $status = 'pending', $total = 0, $name = '') {
        $d = is_array($id) ? $id : ['id' => $id, 'customer' => $customer, 'status' => $status, 'total' => $total, 'name' => $name];
        $d += ['items' => [['name' => $d['name'] ?? 'Article', 'subtotal' => $d['total'] ?? 0]], 'created' => '2026-10-' . sprintf('%02d', 1 + ($d['id'] % 27)) . ' 10:24:00', 'paid' => null, 'completed' => null,
            'method' => 'moncash', 'method_title' => 'MonCash', 'txn' => '', 'note' => '', 'notes' => [], 'meta' => [], 'discount' => 0, 'refunded' => 0,
            'first' => 'Louis', 'last' => 'Marken Ricky', 'email' => 'client1@example.com', 'phone' => '+509 3311 1283'];
        $this->d = $d; $this->id = $d['id']; $this->customer = $d['customer']; $this->status = $d['status']; $this->total = $d['total']; $this->name = $d['name'] ?? '';
    }
    private function dt($v) { return $v ? new DIPSTUB_DT($v, wp_timezone()) : null; }
    public function get_id() { return $this->id; }
    public function get_customer_id() { return $this->customer; }
    public function get_status() { return $this->status; }
    public function has_status($s) { return in_array($this->status, (array) $s, true); }
    public function get_total() { return $this->total; }
    public function get_subtotal() { $t = 0; foreach ($this->d['items'] as $i) $t += $i['subtotal'] * ($i['qty'] ?? 1) / max(1, $i['qty'] ?? 1); return $t; }
    public function get_total_discount() { return $this->d['discount']; }
    public function get_shipping_total() { return 0; }
    public function get_fees() { return []; }
    public function get_total_tax() { return 0; }
    public function get_total_refunded() { return $this->d['refunded']; }
    public function get_currency() { return 'HTG'; }
    public function get_date_created() { return $this->dt($this->d['created']); }
    public function get_date_modified() { return $this->get_date_created(); }
    public function get_date_paid() { return $this->dt($this->d['paid']); }
    public function get_date_completed() { return $this->dt($this->d['completed']); }
    public function get_order_number() { return (string) $this->id; }
    public function get_order_key() { return 'wc_order_key' . $this->id; }
    public function get_billing_first_name() { return $this->d['first']; }
    public function get_billing_last_name() { return $this->d['last']; }
    public function get_billing_email() { return $this->d['email']; }
    public function get_billing_phone() { return $this->d['phone']; }
    public function get_payment_method() { return $this->d['method']; }
    public function get_payment_method_title() { return $this->d['method_title']; }
    public function get_transaction_id() { return $this->d['txn']; }
    public function get_customer_note() { return $this->d['note']; }
    public function get_meta($k, $single = true) { return $this->d['meta'][$k] ?? ''; }
    public function is_paid() { return in_array($this->status, ['processing', 'completed'], true); }
    public function needs_payment() { return in_array($this->status, ['pending', 'failed'], true) && $this->total > 0; }
    public function get_checkout_payment_url() { return home_url('/checkout/order-pay/' . $this->id . '/?pay_for_order=true&key=' . $this->get_order_key()); }
    public function get_items($type = '') { $o = []; foreach ($this->d['items'] as $n => $i) { $i['id'] = 100 + $n; $o[100 + $n] = new DIPSTUB_VO_Item($i); } return $o; }
    public function get_customer_order_notes() { $o = []; foreach ($this->d['notes'] as $n) $o[] = (object) ['comment_content' => $n[1], 'comment_date_gmt' => $n[0]]; return $o; }
    public function get_view_order_url() { return wc_get_endpoint_url('view-order', $this->id, wc_get_page_permalink('myaccount')); }
    public function get_formatted_order_total() { return wc_price($this->total); }
}
function dipstub_orders_for($uid) {
    $all = (array) get_option('dipstub_orders', []);
    $out = [];
    foreach ($all as $o) if ((int) $o['customer'] === (int) $uid) $out[] = new WC_Order($o);
    return $out;
}
function wc_get_order($id) {
    foreach ((array) get_option('dipstub_orders', []) as $o) if ((int) $o['id'] === (int) $id) return new WC_Order($o);
    return false;
}
function get_woocommerce_currency() { return 'HTG'; }
function wc_get_cart_url() { return home_url('/cart/'); }
function wc_get_template($template_name, $args = [], $template_path = '', $default_path = '') {
    $template = '/nonexistent/woocommerce/templates/' . $template_name;
    $template = apply_filters('wc_get_template', $template, $template_name, $args, $template_path, $default_path);
    if (!is_readable($template)) { echo '<p class="wc-default-template">WooCommerce template ' . esc_html($template_name) . '</p>'; return; }
    extract($args); include $template;
}
add_action('woocommerce_account_view-order_endpoint', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || (int) $order->get_customer_id() !== get_current_user_id()) { echo '<div class="woocommerce-error">Commande invalide.</div>'; return; }
    wc_get_template('myaccount/view-order.php', ['status' => null, 'order' => $order, 'order_id' => $order->get_id()]);
});
function woocommerce_order_details_table($order_id) { echo '<section class="woocommerce-order-details">WC details table</section>'; }
add_action('woocommerce_view_order', 'woocommerce_order_details_table', 10);
add_action('woocommerce_order_item_meta_end', function ($item_id, $item, $order) {
    $codes = $item->get_meta('_test_voucher');
    if ($codes && $order->get_status() === 'completed') echo '<div class="dst2t-vouchers"><strong>Voucher codes</strong><ul><li><code>' . esc_html($codes) . '</code> <small>Serial: 8812-44</small></li></ul></div>';
}, 10, 3);
function wc_get_orders($args) {
    $GLOBALS['dipstub_calls'][] = 'wc_get_orders:' . wp_json_encode($args);
    $orders = dipstub_orders_for($args['customer'] ?? 0);
    if (!empty($args['status'])) { $st = array_map(function ($s) { return preg_replace('/^wc-/', '', $s); }, (array) $args['status']); $orders = array_values(array_filter($orders, function ($o) use ($st) { return in_array($o->status, $st, true); })); }
    $total = count($orders);
    if (!empty($args['limit']) && $args['limit'] > 0) $orders = array_slice($orders, 0, $args['limit']);
    if (($args['return'] ?? '') === 'ids') $orders = array_map(function ($o) { return $o->id; }, $orders);
    if (!empty($args['paginate'])) return (object) ['orders' => $orders, 'total' => $total, 'max_num_pages' => 1];
    return $orders;
}

// TeraWallet stub
if (get_option('dipstub_wallet', '1') === '1') {
    function woo_wallet() { static $w; if (!$w) { $w = new stdClass(); $w->wallet = new DIPSTUB_Wallet(); } return $w; }
    class DIPSTUB_Wallet { public function get_wallet_balance($uid = '', $ctx = 'view') { return (float) get_user_meta($uid, '_dipstub_balance', true); } }
}

// [woocommerce_my_account] shortcode (simplified copy of WC templates/myaccount/*.php hook sequence)
add_shortcode('woocommerce_my_account', function () {
    if (!is_user_logged_in()) {
        return '<div class="woocommerce"><form class="woocommerce-form woocommerce-form-login login" method="post"><p>Login form stub</p></form></div>';
    }
    ob_start();
    echo '<div class="woocommerce">';
    echo '<div class="woocommerce-notices-wrapper"></div>';
    do_action('woocommerce_before_account_navigation');
    echo '<nav class="woocommerce-MyAccount-navigation" aria-label="Account pages"><ul>';
    $items = apply_filters('woocommerce_account_menu_items', ['dashboard'=>'Dashboard','orders'=>'Orders','downloads'=>'Downloads','edit-address'=>'Addresses','edit-account'=>'Account details','customer-logout'=>'Log out'], []);
    global $wp;
    foreach ($items as $endpoint => $label) {
        $current = isset($wp->query_vars[$endpoint]);
        if ('dashboard' === $endpoint && (isset($wp->query_vars['page']) || empty($wp->query_vars))) $current = true;
        if ('orders' === $endpoint && isset($wp->query_vars['view-order'])) $current = true;
        $classes = ['woocommerce-MyAccount-navigation-link', 'woocommerce-MyAccount-navigation-link--' . $endpoint];
        if ($current) $classes[] = 'is-active';
        echo '<li class="' . esc_attr(implode(' ', $classes)) . '"><a href="' . esc_url(wc_get_account_endpoint_url($endpoint)) . '"' . ($current ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a></li>';
    }
    echo '</ul></nav>';
    do_action('woocommerce_after_account_navigation');
    echo '<div class="woocommerce-MyAccount-content"><div class="woocommerce-notices-wrapper"></div>';
    // woocommerce_account_content()
    $done = false;
    if (!empty($wp->query_vars)) {
        foreach ($wp->query_vars as $key => $value) {
            if ('pagename' === $key) continue;
            if (has_action('woocommerce_account_' . $key . '_endpoint')) { do_action('woocommerce_account_' . $key . '_endpoint', $value); $done = true; break; }
        }
    }
    if (!$done) {
        $u = wp_get_current_user();
        echo '<p>Hello <strong>' . esc_html($u->display_name) . '</strong> (not <strong>' . esc_html($u->display_name) . '</strong>? <a href="#">Log out</a>)</p>';
        echo '<p>From your account dashboard you can view your recent orders.</p>';
        do_action('woocommerce_account_dashboard');
    }
    echo '</div></div>';
    return ob_get_clean();
});
add_action('woocommerce_account_orders_endpoint', function () {
    do_action('woocommerce_before_account_orders', true);
    echo '<table class="woocommerce-orders-table shop_table"><thead><tr><th>Commande</th><th>Date</th><th>État</th><th>Total</th></tr></thead><tbody>';
    foreach (dipstub_orders_for(get_current_user_id()) as $o) { echo '<tr><td data-title="Commande">#' . esc_html($o->id) . '</td><td data-title="Date">1 oct. 2026</td><td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="État">';
      if (has_action('woocommerce_my_account_my_orders_column_order-status')) do_action('woocommerce_my_account_my_orders_column_order-status', $o); else echo esc_html(wc_get_order_status_name($o->get_status()));
      echo '</td><td data-title="Total">' . wc_price($o->total) . ' ' . esc_html($o->name) . '</td><td><a class="woocommerce-button button view" href="' . esc_url($o->get_view_order_url()) . '">Voir</a></td></tr>'; }
    echo '</tbody></table>';
});
add_action('woocommerce_account_edit-account_endpoint', function () {
    do_action('woocommerce_before_edit_account_form');
    echo '<form class="woocommerce-EditAccountForm edit-account" method="post"><p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first"><label>' . esc_html__('First name', 'woocommerce') . '</label><input type="text" class="input-text"></p><p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last"><label>' . esc_html__('Last name', 'woocommerce') . '</label><input type="text"></p><p><button type="submit" class="button">' . esc_html__('Save changes', 'woocommerce') . '</button></p></form>';
});
add_action('woocommerce_account_woo-wallet_endpoint', function () { echo '<div class="woo-wallet-my-wallet-container"><p>Wallet stub content paragraph</p></div>'; });

// Products for the favourites rail (test only)
class DIPSTUB_Product { public $d; public function __construct($d) { $this->d = $d; }
  public function is_visible() { return true; } public function get_image_id() { return 0; } public function get_name() { return $this->d['name']; }
  public function get_permalink() { return home_url('/product/' . sanitize_title($this->d['name']) . '/'); } public function get_price_html() { return wc_price($this->d['price']); } }
function wc_get_products($args) { $all = (array) get_option('dipstub_products', []); $out = [];
  foreach ((array) ($args['include'] ?? []) as $id) { if (isset($all[$id])) $out[] = new DIPSTUB_Product($all[$id]); }
  return array_slice($out, 0, (int) ($args['limit'] ?? 10)); }

function wc_get_account_menu_items() {
  $items = ['dashboard'=>'Tableau de bord','orders'=>'Commandes','downloads'=>'Téléchargements','edit-address'=>'Adresses','edit-account'=>'Détails du compte','customer-logout'=>'Déconnexion'];
  if (get_option('dipstub_wallet', '1') === '1') $items['woo-wallet'] = 'Portefeuille';
  return apply_filters('woocommerce_account_menu_items', $items, []);
}
