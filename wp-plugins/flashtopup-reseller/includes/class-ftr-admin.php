<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI: connection settings, webhook info, conversion settings, import.
 *
 * Settings page is a single tabbed surface (Connection / WooCommerce /
 * Webhook / Diagnostics). Import page lives on its own URL because it has
 * a heavy catalog table.
 */
class FTR_Admin
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_ftr_save_settings', array($this, 'save_settings'));
        add_action('admin_post_ftr_import', array($this, 'import_products'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    public function enqueue($hook)
    {
        if (strpos((string) $hook, 'ftr-settings') === false && strpos((string) $hook, 'ftr-import') === false) {
            return;
        }
        wp_enqueue_style('ftr-admin', FTR_URL . 'assets/ft-admin.css', array(), FTR_VERSION);
        wp_enqueue_script('ftr-admin', FTR_URL . 'assets/ft-admin.js', array(), FTR_VERSION, true);
        wp_localize_script('ftr-admin', 'FTRAdmin', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('ftr_admin'),
            'importUrl'  => admin_url('admin.php?page=ftr-import'),
            'productsUrl' => admin_url('edit.php?post_type=product'),
        ));
    }

    public function menu()
    {
        add_menu_page(
            'FlashTopup Reseller',
            'FlashTopup',
            'manage_options',
            'ftr-settings',
            array($this, 'render_settings'),
            'dashicons-cart',
            58
        );
        add_submenu_page('ftr-settings', 'Settings', 'Settings', 'manage_options', 'ftr-settings', array($this, 'render_settings'));
        add_submenu_page('ftr-settings', 'Import Products', 'Import Products', 'manage_options', 'ftr-import', array($this, 'render_import'));
    }

    /* ---------------- Settings ---------------- */

    public function save_settings()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('ftr_settings')) {
            wp_die('Not allowed.');
        }
        $s = ftr_settings();
        $s['api_id']              = isset($_POST['api_id']) ? sanitize_text_field(wp_unslash($_POST['api_id'])) : '';
        $s['api_key']             = isset($_POST['api_key']) ? trim((string) wp_unslash($_POST['api_key'])) : '';
        $s['base_url']            = isset($_POST['base_url']) ? esc_url_raw(wp_unslash($_POST['base_url'])) : $s['base_url'];
        $s['sandbox']             = isset($_POST['sandbox']) ? 1 : 0;
        $s['check_id']            = isset($_POST['check_id']) ? 1 : 0;
        $s['auto_complete']       = isset($_POST['auto_complete']) ? 1 : 0;
        $s['markup']              = isset($_POST['markup']) ? max(0, (float) $_POST['markup']) : 0;
        $s['conversion_rate']     = isset($_POST['conversion_rate']) ? max(0, (float) $_POST['conversion_rate']) : 1.0;
        if ((float) $s['conversion_rate'] <= 0) {
            $s['conversion_rate'] = 1.0;
        }
        $s['conversion_currency'] = isset($_POST['conversion_currency']) ? strtoupper(substr(sanitize_text_field(wp_unslash($_POST['conversion_currency'])), 0, 6)) : 'USD';
        update_option(FTR_OPTION, $s);
        wp_safe_redirect(add_query_arg(array('page' => 'ftr-settings', 'tab' => isset($_POST['tab']) ? sanitize_key($_POST['tab']) : 'connection', 'ftr_msg' => 'saved'), admin_url('admin.php')));
        exit;
    }

    public function render_settings()
    {
        $s = ftr_settings();
        $msg = isset($_GET['ftr_msg']) ? sanitize_text_field(wp_unslash($_GET['ftr_msg'])) : '';
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'connection';
        if (!in_array($tab, array('connection', 'woocommerce', 'webhook', 'diagnostics'), true)) {
            $tab = 'connection';
        }

        // Live wallet preview (no creds = skip).
        $conn = null;
        if ($s['api_id'] !== '' && $s['api_key'] !== '') {
            $api = new FTR_API($s);
            $conn = $api->balance();
        }
        ?>
        <div class="wrap ftr-wrap">
            <header class="ftr-hero">
                <div class="ftr-hero-left">
                    <h1>FlashTopup Reseller</h1>
                    <p class="ftr-hero-sub">Sign, import, sell. Version <?php echo esc_html(FTR_VERSION); ?>.</p>
                </div>
                <div class="ftr-hero-right">
                    <?php $this->render_status_pill($s, $conn); ?>
                </div>
            </header>

            <?php if ($msg === 'saved') : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <nav class="ftr-tabs">
                <?php foreach (array(
                    'connection'  => 'Connection',
                    'woocommerce' => 'WooCommerce',
                    'webhook'     => 'Webhook',
                    'diagnostics' => 'Diagnostics',
                ) as $key => $label) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ftr-settings&tab=' . $key)); ?>"
                       class="ftr-tab <?php echo $tab === $key ? 'ftr-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
                <span class="ftr-tabs-flex"></span>
                <button type="button" class="button button-secondary" id="ftr-check-balance">Check wallet balance</button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ftr-import')); ?>" class="button button-primary">Import products</a>
            </nav>

            <?php
            switch ($tab) {
                case 'woocommerce':
                    $this->tab_woocommerce($s);
                    break;
                case 'webhook':
                    $this->tab_webhook($s);
                    break;
                case 'diagnostics':
                    $this->tab_diagnostics($s, $conn);
                    break;
                case 'connection':
                default:
                    $this->tab_connection($s);
                    break;
            }
            ?>
        </div>
        <?php
    }

    private function render_status_pill($s, $conn)
    {
        if ($s['api_id'] === '' || $s['api_key'] === '') {
            echo '<span class="ftr-pill ftr-pill-warn">Not configured</span>';
            return;
        }
        if ($conn && !empty($conn['ok'])) {
            $bal = ($conn['data']['currency_symbol'] ?? '') . ($conn['data']['balance'] ?? '0');
            echo '<span class="ftr-pill ftr-pill-ok">Connected · ' . esc_html($bal) . '</span>';
            if (!empty($s['sandbox'])) {
                echo ' <span class="ftr-pill ftr-pill-warn">Sandbox</span>';
            }
            return;
        }
        $msg = $conn['message'] ?? 'Unknown error';
        echo '<span class="ftr-pill ftr-pill-bad">Connection failed</span> <span class="ftr-muted">' . esc_html($msg) . '</span>';
    }

    /* ---- TAB: Connection ---- */

    private function tab_connection($s)
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ftr-card">
            <input type="hidden" name="action" value="ftr_save_settings" />
            <input type="hidden" name="tab" value="connection" />
            <?php wp_nonce_field('ftr_settings'); ?>

            <h2 class="ftr-card-title">API credentials</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="api_id">API ID</label></th>
                    <td><input name="api_id" id="api_id" type="text" class="regular-text" value="<?php echo esc_attr($s['api_id']); ?>" placeholder="RS..." /></td>
                </tr>
                <tr>
                    <th><label for="api_key">API Secret</label></th>
                    <td>
                        <input name="api_key" id="api_key" type="password" class="regular-text" value="<?php echo esc_attr($s['api_key']); ?>" autocomplete="off" />
                        <p class="description">From your FlashTopup reseller dashboard → API keys. Stored on your server only.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="base_url">API Base URL</label></th>
                    <td><input name="base_url" id="base_url" type="url" class="regular-text" value="<?php echo esc_attr($s['base_url']); ?>" /></td>
                </tr>
                <tr>
                    <th>Options</th>
                    <td>
                        <label><input type="checkbox" name="check_id" value="1" <?php checked($s['check_id'], 1); ?> /> Enable the "Check ID" button on product pages</label><br />
                        <label><input type="checkbox" name="sandbox" value="1" <?php checked($s['sandbox'], 1); ?> /> Sandbox mode (test orders without charging your wallet)</label>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save connection settings'); ?>
        </form>
        <?php
    }

    /* ---- TAB: WooCommerce / pricing ---- */

    private function tab_woocommerce($s)
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ftr-card">
            <input type="hidden" name="action" value="ftr_save_settings" />
            <input type="hidden" name="tab" value="woocommerce" />
            <?php wp_nonce_field('ftr_settings'); ?>

            <h2 class="ftr-card-title">Pricing and currency</h2>
            <p class="ftr-muted">
                Imported prices come in <strong>USD</strong>. Apply a conversion rate (e.g. <code>134.50</code> for USD→NPR)
                and an optional sell markup so prices display in your local currency at your margin.
                Your FlashTopup wallet is still debited in USD at the wholesale price.
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="conversion_rate">Conversion rate</label></th>
                    <td>
                        <input name="conversion_rate" id="conversion_rate" type="number" step="0.0001" min="0.0001" class="regular-text"
                               value="<?php echo esc_attr($s['conversion_rate']); ?>" />
                        <p class="description">Multiplier applied to every imported price. Use <code>1</code> to keep prices in USD.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="conversion_currency">Display currency code</label></th>
                    <td>
                        <input name="conversion_currency" id="conversion_currency" type="text" maxlength="6" class="small-text"
                               value="<?php echo esc_attr($s['conversion_currency']); ?>" />
                        <p class="description">Label only — does not change the rate. Examples: <code>NPR</code>, <code>INR</code>, <code>USD</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="markup">Sell markup (%)</label></th>
                    <td>
                        <input name="markup" id="markup" type="number" step="0.1" min="0" class="small-text"
                               value="<?php echo esc_attr($s['markup']); ?>" /> %
                        <p class="description">Added on top of (converted) wholesale price as the customer-facing margin.</p>
                    </td>
                </tr>
                <tr>
                    <th>Effective preview</th>
                    <td>
                        <code>customer pays</code> = <code>USD_price &times; <?php echo esc_html(number_format((float) $s['conversion_rate'], 4)); ?></code> &times;
                        <code>(1 + <?php echo esc_html(number_format((float) $s['markup'], 2)); ?>%)</code>
                        <span class="ftr-muted">→ displayed as</span> <code><?php echo esc_html($s['conversion_currency']); ?></code>
                    </td>
                </tr>
                <tr>
                    <th>Order auto-completion</th>
                    <td>
                        <label><input type="checkbox" name="auto_complete" value="1" <?php checked($s['auto_complete'], 1); ?> />
                        Mark WooCommerce orders <code>Completed</code> when the FlashTopup API, webhook, or status reconciliation reports Completed</label>
                        <p class="description"><strong>Enabled by default.</strong> WooCommerce completes the order only after every FlashTopup item is confirmed completed by the provider. Turn it off only for manual review.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save WooCommerce settings'); ?>
        </form>

        <div class="ftr-card">
            <h2 class="ftr-card-title">WooCommerce status</h2>
            <p>
                <?php if (FTR_Woo::active()) : ?>
                    <strong class="ftr-ok">Active</strong><?php echo defined('WC_VERSION') ? ' (v' . esc_html(WC_VERSION) . ')' : ''; ?>
                    &nbsp;&middot;&nbsp; FlashTopup products in shop: <strong><?php echo (int) FTR_Woo::ft_product_count(); ?></strong>
                    &nbsp;&middot;&nbsp; <a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>">View WooCommerce products</a>
                <?php else : ?>
                    <strong class="ftr-bad">Not active</strong>. Install &amp; activate <a href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')); ?>">WooCommerce</a> first.
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /* ---- TAB: Webhook ---- */

    private function tab_webhook($s)
    {
        $url = ftr_webhook_url();
        $log = get_option(FTR_WEBHOOK_LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        ?>
        <div class="ftr-card">
            <h2 class="ftr-card-title">Receive order-update webhooks</h2>
            <p class="ftr-muted">
                Copy this URL into the <strong>callback_url</strong> field of your FlashTopup reseller account
                (Reseller panel → Settings → Webhook). FlashTopup signs every event with HMAC-SHA256 using your
                API Secret; this plugin verifies the signature, a +/-5 minute timestamp window, and a single-use
                nonce before applying the update.
            </p>
            <div class="ftr-copy">
                <input id="ftr-webhook-url" type="text" readonly value="<?php echo esc_attr($url); ?>" />
                <button type="button" class="button button-primary" data-copy="ftr-webhook-url">Copy</button>
            </div>
            <p class="ftr-muted ftr-small">
                <strong>Heads up:</strong> the URL is reachable to the internet, but only requests signed with
                <em>your</em> API Secret are accepted. Foreign or replayed requests are rejected and counted in
                the log below.
            </p>
        </div>

        <div class="ftr-card">
            <h2 class="ftr-card-title">Recent webhook events</h2>
            <?php if (empty($log)) : ?>
                <p class="ftr-muted">No webhook events received yet.</p>
            <?php else : ?>
                <table class="widefat striped ftr-log">
                    <thead><tr><th style="width:160px">When</th><th style="width:120px">Outcome</th><th>Event / reason</th><th>Detail</th></tr></thead>
                    <tbody>
                    <?php foreach ($log as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html($entry['at'] ?? ''); ?></td>
                            <td>
                                <?php $cls = ($entry['outcome'] ?? '') === 'accepted' ? 'ftr-ok' : 'ftr-bad'; ?>
                                <span class="<?php echo esc_attr($cls); ?>"><?php echo esc_html($entry['outcome'] ?? ''); ?></span>
                            </td>
                            <td><code><?php echo esc_html($entry['event'] ?? ''); ?></code></td>
                            <td><code><?php echo esc_html(wp_json_encode($entry['extra'] ?? array())); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ---- TAB: Diagnostics ---- */

    private function tab_diagnostics($s, $conn)
    {
        $imported = get_option(FTR_PRODUCTS, array());
        ?>
        <div class="ftr-card">
            <h2 class="ftr-card-title">System status</h2>
            <table class="form-table" role="presentation">
                <tr><th>Plugin version</th><td><code><?php echo esc_html(FTR_VERSION); ?></code></td></tr>
                <tr><th>WordPress</th><td><code><?php echo esc_html(get_bloginfo('version')); ?></code></td></tr>
                <tr><th>WooCommerce</th><td><code><?php echo esc_html(defined('WC_VERSION') ? WC_VERSION : 'not installed'); ?></code></td></tr>
                <tr><th>PHP</th><td><code><?php echo esc_html(PHP_VERSION); ?></code></td></tr>
                <tr><th>cURL</th><td><code><?php echo esc_html(function_exists('curl_version') ? curl_version()['version'] : 'unavailable'); ?></code></td></tr>
                <tr><th>Webhook URL</th><td><code><?php echo esc_html(ftr_webhook_url()); ?></code></td></tr>
                <tr><th>API Base URL</th><td><code><?php echo esc_html($s['base_url']); ?></code></td></tr>
                <tr><th>API ID configured</th><td><?php echo $s['api_id'] !== '' ? '<strong class="ftr-ok">yes</strong>' : '<strong class="ftr-bad">no</strong>'; ?></td></tr>
                <tr><th>API Secret configured</th><td><?php echo $s['api_key'] !== '' ? '<strong class="ftr-ok">yes</strong>' : '<strong class="ftr-bad">no</strong>'; ?></td></tr>
                <tr><th>Sandbox mode</th><td><?php echo !empty($s['sandbox']) ? '<strong class="ftr-warn">ON</strong>' : 'off'; ?></td></tr>
                <tr><th>Imported products</th><td><?php echo (int) count($imported); ?></td></tr>
            </table>
            <p>
                <button type="button" class="button" id="ftr-selftest">Run import self-test</button>
                <span class="description">Creates one test product (no API call) and reports exactly what happens.</span>
            </p>
        </div>
        <?php
    }

    /* ---------------- Import (unchanged behaviour, lighter wrapper) ---------------- */

    public function import_products()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('ftr_import')) {
            wp_die('Not allowed.');
        }
        $selected = isset($_POST['products']) && is_array($_POST['products'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['products']))
            : array();
        $with_services = !empty($_POST['with_services']);
        $api = new FTR_API();

        $resp = $api->all_products(500);
        $catalog = (!empty($resp['ok']) && is_array($resp['data'])) ? $resp['data'] : array();

        $store = array();
        foreach ($catalog as $p) {
            $code = isset($p['product_code']) ? (string) $p['product_code'] : '';
            if ($code === '' || !in_array($code, $selected, true)) {
                continue;
            }
            $item = array(
                'product_code'    => $code,
                'product_id'      => $p['product_id'] ?? null,
                'name'            => $p['name'] ?? $code,
                'image_url'       => $p['image_url'] ?? '',
                'product_type'    => $p['product_type'] ?? 'topup',
                'validation_code' => $p['validation_code'] ?? '',
                'check_id_status' => $p['check_id_status'] ?? 'inactive',
                'fields'          => isset($p['fields']) && is_array($p['fields']) ? $p['fields'] : array(),
                'server'          => isset($p['server']) && is_array($p['server']) ? $p['server'] : array(),
                'services'        => array(),
            );
            if ($with_services) {
                $sresp = $api->services($code, $item['product_type'], 1, 500);
                if (!empty($sresp['ok']) && isset($sresp['data']['service']) && is_array($sresp['data']['service'])) {
                    foreach ($sresp['data']['service'] as $svc) {
                        $item['services'][] = array(
                            'service_code' => $svc['service_code'] ?? '',
                            'service_name' => $svc['service_name'] ?? '',
                            'price'        => $svc['price'] ?? '0',
                            'currency'     => $svc['currency'] ?? 'USD',
                            'max_quantity' => isset($svc['max_quantity']) ? (int) $svc['max_quantity'] : 1,
                            'in_stock'     => isset($svc['in_stock']) ? (bool) $svc['in_stock'] : true,
                        );
                    }
                }
            }
            $store[$code] = $item;
        }
        update_option(FTR_PRODUCTS, $store);
        $synced = FTR_Woo::active() ? FTR_Woo::sync($store) : 0;
        wp_safe_redirect(add_query_arg(array(
            'page' => 'ftr-import',
            'ftr_msg' => 'imported',
            'count' => count($store),
            'wc' => $synced,
        ), admin_url('admin.php')));
        exit;
    }

    public function render_import()
    {
        $api = new FTR_API();
        $msg = isset($_GET['ftr_msg']) ? sanitize_text_field(wp_unslash($_GET['ftr_msg'])) : '';
        $imported = get_option(FTR_PRODUCTS, array());

        $catalog = array();
        $error = '';
        if ($api->is_configured()) {
            $resp = $api->all_products(500);
            if (!empty($resp['ok']) && is_array($resp['data'])) {
                $catalog = $resp['data'];
            } else {
                $error = $resp['message'];
            }
        } else {
            $error = 'Configure your API ID and secret first.';
        }
        ?>
        <div class="wrap ftr-wrap">
            <header class="ftr-hero">
                <div class="ftr-hero-left">
                    <h1>Import products</h1>
                    <p class="ftr-hero-sub">Tick the games you want to sell, then Import. Each game becomes a WooCommerce variable product with one variation per package.</p>
                </div>
            </header>

            <?php if ($msg === 'imported') : $wc = (int) ($_GET['wc'] ?? 0); ?>
                <div class="notice notice-success is-dismissible"><p>
                    Imported <strong><?php echo (int) ($_GET['count'] ?? 0); ?></strong> product(s).
                    <?php if ($wc > 0) : ?>
                        Created/updated <strong><?php echo (int) $wc; ?></strong> WooCommerce variation(s).
                    <?php endif; ?>
                </p></div>
            <?php endif; ?>

            <?php if ($error) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if (!empty($catalog)) : ?>
                <form id="ftr-import-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ftr-card">
                    <input type="hidden" name="action" value="ftr_import" />
                    <?php wp_nonce_field('ftr_import'); ?>
                    <p>
                        <label><input type="checkbox" name="with_services" value="1" checked /> <strong>Import services &amp; auto-create order fields</strong></label>
                        &nbsp;|&nbsp;
                        <label><input type="checkbox" id="ftr-check-all" /> Select all</label>
                    </p>
                    <table class="widefat striped">
                        <thead><tr><th style="width:36px"></th><th>Product</th><th>Supplier ID</th><th>Type</th><th>Check&nbsp;ID</th><th>Fields</th></tr></thead>
                        <tbody>
                        <?php foreach ($catalog as $p) :
                            $code = $p['product_code'] ?? '';
                            if ($code === '') {
                                continue;
                            }
                            $checked = isset($imported[$code]);
                            $fields = isset($p['fields']) && is_array($p['fields']) ? $p['fields'] : array(); ?>
                            <tr>
                                <td><input type="checkbox" class="ftr-prod" name="products[]" value="<?php echo esc_attr($code); ?>" <?php checked($checked); ?> /></td>
                                <td>
                                    <?php if (!empty($p['image_url'])) : ?>
                                        <img src="<?php echo esc_url($p['image_url']); ?>" alt="" class="ftr-thumb" />
                                    <?php endif; ?>
                                    <strong><?php echo esc_html($p['name'] ?? $code); ?></strong>
                                    <br /><span class="description"><?php echo esc_html($code); ?></span>
                                </td>
                                <td><code><?php echo esc_html(isset($p['product_id']) ? (string) $p['product_id'] : '—'); ?></code></td>
                                <td><?php echo esc_html($p['product_type'] ?? ''); ?></td>
                                <td><?php echo (isset($p['check_id_status']) && $p['check_id_status'] === 'active') ? '✓' : '—'; ?></td>
                                <td><?php echo esc_html(implode(', ', wp_list_pluck($fields, 'label'))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:14px">
                        <button type="button" class="button button-primary button-hero" id="ftr-import-btn">Import selected products</button>
                        <?php if (!empty($imported)) : ?>
                            <button type="button" class="button button-hero" id="ftr-sync-btn">Synchronize imported products</button>
                        <?php endif; ?>
                        <span class="description" style="margin-left:8px">Synchronize refreshes supplier IDs, package codes, prices, stock and product fields without recreating matched WooCommerce products.</span>
                    </p>
                </form>
                <script>
                document.getElementById('ftr-check-all').addEventListener('change', function (e) {
                    document.querySelectorAll('.ftr-prod').forEach(function (c) { c.checked = e.target.checked; });
                });
                </script>
            <?php endif; ?>

            <?php if (!empty($imported)) : ?>
                <div class="ftr-card">
                    <h2 class="ftr-card-title">Imported (<?php echo (int) count($imported); ?>)</h2>
                    <p class="description"><?php echo esc_html(implode(', ', wp_list_pluck($imported, 'name'))); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
