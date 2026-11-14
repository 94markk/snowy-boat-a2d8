<?php
/**
 * Delicat Email Studio — Billing/shipping address cards.
 * Preserves WooCommerce address extension hooks.
 */
defined( 'ABSPATH' ) || exit;

if ( ! isset( $order ) || ! is_a( $order, 'WC_Order' ) ) {
    return;
}

$billing  = $order->get_formatted_billing_address();
$shipping = $order->get_formatted_shipping_address();
$show_shipping = ! wc_ship_to_billing_address_only() && $order->needs_shipping_address() && $shipping;

// Digital orders often have no useful address. Avoid an empty/duplicated legacy block.
if ( ! $billing && ! $show_shipping && ! $order->get_billing_phone() && ! $order->get_billing_email() ) {
    return;
}
?>
<table class="desp-address-grid" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td class="desp-address-cell<?php echo $show_shipping ? ' desp-address-cell-left' : ''; ?>" valign="top" width="<?php echo $show_shipping ? '50%' : '100%'; ?>">
            <div class="desp-address-card">
                <div class="desp-address-kicker">Informations client</div>
                <div class="desp-address-title">Coordonnées du client</div>
                <div class="desp-address-text">
                    <?php echo wp_kses_post( $billing ? $billing : esc_html__( 'N/A', 'woocommerce' ) ); ?>
                    <?php if ( $order->get_billing_phone() ) : ?><br><a class="desp-contact-link" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $order->get_billing_phone() ) ); ?>"><?php echo esc_html( $order->get_billing_phone() ); ?></a><?php endif; ?>
                    <?php if ( $order->get_billing_email() ) : ?><br><a class="desp-contact-link" href="mailto:<?php echo esc_attr( $order->get_billing_email() ); ?>"><?php echo esc_html( $order->get_billing_email() ); ?></a><?php endif; ?>
                    <?php do_action( 'woocommerce_email_customer_address_section', 'billing', $order, $sent_to_admin, false ); ?>
                </div>
            </div>
        </td>
        <?php if ( $show_shipping ) : ?>
        <td class="desp-address-cell desp-address-cell-right" valign="top" width="50%">
            <div class="desp-address-card">
                <div class="desp-address-kicker">Livraison</div>
                <div class="desp-address-title">Adresse de livraison</div>
                <div class="desp-address-text">
                    <?php echo wp_kses_post( $shipping ); ?>
                    <?php if ( $order->get_shipping_phone() ) : ?><br><a class="desp-contact-link" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $order->get_shipping_phone() ) ); ?>"><?php echo esc_html( $order->get_shipping_phone() ); ?></a><?php endif; ?>
                    <?php do_action( 'woocommerce_email_customer_address_section', 'shipping', $order, $sent_to_admin, false ); ?>
                </div>
            </div>
        </td>
        <?php endif; ?>
    </tr>
</table>
