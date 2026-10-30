<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * [flashtopup_reseller] — renders imported products with an order form that
 * auto-builds the required input/select fields, an optional Check-ID button,
 * package selection and order placement.
 */
class FTR_Shortcode
{
    public function __construct()
    {
        add_shortcode('flashtopup_reseller', array($this, 'render'));
    }

    public function render($atts = array())
    {
        $settings = ftr_settings();
        $products = get_option(FTR_PRODUCTS, array());

        // Public-safe product data for the browser (no codes/secrets exposed
        // beyond product/service codes, which are required to place orders).
        $public = array();
        foreach ($products as $code => $p) {
            $public[] = array(
                'product_code'    => $p['product_code'],
                'name'            => $p['name'],
                'image_url'       => $p['image_url'],
                'product_type'    => $p['product_type'],
                'check_id'        => !empty($settings['check_id']) && isset($p['check_id_status']) && $p['check_id_status'] === 'active',
                'fields'          => $p['fields'],
                'services'        => $p['services'],
            );
        }

        wp_enqueue_style('ftr-front', FTR_URL . 'assets/ft-reseller.css', array(), FTR_VERSION);
        wp_enqueue_script('ftr-front', FTR_URL . 'assets/ft-reseller.js', array(), FTR_VERSION, true);
        wp_localize_script('ftr-front', 'FTR', array(
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ftr_front'),
            'products' => $public,
            'markup'   => (float) $settings['markup'],
            // Display pricing matches the WooCommerce import: USD * rate * (1+markup),
            // labelled in the reseller's display currency.
            'rate'     => isset($settings['conversion_rate']) && (float) $settings['conversion_rate'] > 0 ? (float) $settings['conversion_rate'] : 1.0,
            'currency' => !empty($settings['conversion_currency']) ? $settings['conversion_currency'] : ($settings['currency'] ?? 'USD'),
        ));

        if (empty($public)) {
            return '<div class="ftr-empty">No products available yet. (Admin: import products under FlashTopup → Import Products.)</div>';
        }

        ob_start();
        ?>
        <div id="ftr-app" class="ftr">
            <div class="ftr-toolbar">
                <h2 class="ftr-toolbar-title">Top-ups &amp; vouchers</h2>
                <span class="ftr-toolbar-spacer"></span>
                <div class="ftr-search">
                    <input type="search" id="ftr-search-input" placeholder="Search games…" aria-label="Search products" autocomplete="off" />
                </div>
                <span class="ftr-count" id="ftr-count"></span>
            </div>
            <div class="ftr-grid" id="ftr-grid"></div>
            <div class="ftr-empty ftr-hidden" id="ftr-noresults">No products match your search.</div>

            <div class="ftr-modal" id="ftr-modal" hidden>
                <div class="ftr-modal-backdrop" data-ftr-close></div>
                <div class="ftr-modal-card" role="dialog" aria-modal="true">
                    <button class="ftr-close" type="button" data-ftr-close aria-label="Close">&times;</button>
                    <div class="ftr-modal-head">
                        <img id="ftr-m-img" alt="" />
                        <div>
                            <div class="ftr-m-name" id="ftr-m-name"></div>
                            <div class="ftr-m-type" id="ftr-m-type"></div>
                        </div>
                    </div>

                    <label class="ftr-label">Package</label>
                    <select id="ftr-service" class="ftr-input"></select>

                    <div id="ftr-fields"></div>

                    <div id="ftr-checkid-row" class="ftr-checkid" hidden>
                        <button type="button" id="ftr-checkid" class="ftr-btn-soft">Check ID</button>
                        <span id="ftr-checkid-msg" class="ftr-checkid-msg"></span>
                    </div>

                    <label class="ftr-label">Quantity</label>
                    <div class="ftr-qty-row">
                        <button type="button" class="ftr-qty-btn" id="ftr-qty-minus" aria-label="Decrease quantity">&minus;</button>
                        <input id="ftr-qty" class="ftr-input" type="number" min="1" value="1" inputmode="numeric" />
                        <button type="button" class="ftr-qty-btn" id="ftr-qty-plus" aria-label="Increase quantity">+</button>
                    </div>

                    <div id="ftr-msg" class="ftr-msg" hidden></div>
                    <div id="ftr-success" hidden></div>

                    <button type="button" id="ftr-order" class="ftr-btn">Place order &middot; <span id="ftr-price"></span></button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
