<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * [topup_store] (legacy alias [flashtopup_reseller]) — renders imported
 * products with an order form that auto-builds the required input/select
 * fields, an optional Check-ID button and package selection.
 *
 * Visitors are sent to the WooCommerce product page to pay; only
 * administrators can place direct (wallet-debiting) test orders from here.
 * Images come from the local media library, never the supplier's servers.
 */
class FTR_Shortcode
{
    public function __construct()
    {
        add_shortcode('topup_store', array($this, 'render'));
        add_shortcode('flashtopup_reseller', array($this, 'render'));
    }

    /** Published WooCommerce product id for a supplier product code (0 if none). */
    private function woo_product($code)
    {
        if (!FTR_Woo::active()) {
            return 0;
        }
        $ids = get_posts(array(
            'post_type'   => 'product',
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => '_ftr_product_code',
            'meta_value'  => (string) $code,
        ));
        return !empty($ids) ? (int) $ids[0] : 0;
    }

    public function render($atts = array())
    {
        $settings = ftr_settings();
        $products = get_option(FTR_PRODUCTS, array());

        $is_admin = current_user_can('manage_options');
        if (!is_array($products)) {
            $products = array();
        }

        // Public-safe product data for the browser. Supplier image URLs are
        // replaced by the local WooCommerce image (or nothing).
        $public = array();
        foreach ($products as $code => $p) {
            if (!is_array($p) || empty($p['product_code'])) {
                continue;
            }
            $wc = $this->woo_product($p['product_code']);
            if (!$is_admin && FTR_Woo::active() && !$wc) {
                continue; // not published in the shop — nothing to sell
            }
            $image = '';
            $url = '';
            if ($wc) {
                $thumb = get_post_thumbnail_id($wc);
                $image = $thumb ? (string) wp_get_attachment_image_url($thumb, 'medium') : '';
                $url = (string) get_permalink($wc);
            }
            $services = array();
            foreach ((array) ($p['services'] ?? array()) as $svc) {
                $services[] = array(
                    'service_code' => $svc['service_code'] ?? '',
                    'service_name' => $svc['service_name'] ?? '',
                    'price'        => $svc['price'] ?? '0',
                    'max_quantity' => $svc['max_quantity'] ?? 1,
                    'in_stock'     => FTR_Sync::to_bool($svc['in_stock'] ?? null),
                );
            }
            $public[] = array(
                'product_code'    => $p['product_code'],
                'name'            => $p['name'] ?? $p['product_code'],
                'image_url'       => $image,
                'url'             => $url,
                'product_type'    => $p['product_type'] ?? '',
                'check_id'        => !empty($settings['check_id']) && isset($p['check_id_status']) && $p['check_id_status'] === 'active',
                'fields'          => is_array($p['fields'] ?? null) ? $p['fields'] : array(),
                'services'        => $services,
            );
        }

        FTR_Privacy::inline_style('topup-store', 'ft-reseller.css');
        FTR_Privacy::inline_script('topup-store', 'ft-reseller.js', 'FTR', array(
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ftr_front'),
            'checkAction' => 'topup_verify_player',
            'canOrder' => $is_admin,
            'products' => $public,
            'markup'   => (float) $settings['markup'],
            // Display pricing matches the WooCommerce import: USD * rate * (1+markup),
            // labelled in the reseller's display currency.
            'rate'     => isset($settings['conversion_rate']) && (float) $settings['conversion_rate'] > 0 ? (float) $settings['conversion_rate'] : 1.0,
            'currency' => !empty($settings['conversion_currency']) ? $settings['conversion_currency'] : ($settings['currency'] ?? 'USD'),
        ));

        if (empty($public)) {
            return '<div class="ftr-empty">No products available yet.' . ($is_admin ? ' (Admin: import products under Top-Up → Import Products and publish them.)' : '') . '</div>';
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
