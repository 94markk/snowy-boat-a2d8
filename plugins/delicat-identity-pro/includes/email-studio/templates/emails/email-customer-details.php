<?php
/**
 * Delicat Email Studio — Customer details card.
 * Keeps WooCommerce's filtered customer-detail fields but replaces the legacy presentation.
 */
defined( 'ABSPATH' ) || exit;

if ( empty( $fields ) || ! is_array( $fields ) ) {
    return;
}
?>
<table class="desp-customer-panel" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td class="desp-customer-panel-head" colspan="2">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td class="desp-customer-avatar" width="42" valign="middle"><span>✓</span></td>
                    <td valign="middle">
                        <div class="desp-customer-kicker">Commande sécurisée</div>
                        <div class="desp-customer-title">Coordonnées du client</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <?php foreach ( $fields as $field ) :
        if ( empty( $field['value'] ) ) { continue; }
        $label = isset( $field['label'] ) ? wp_strip_all_tags( $field['label'] ) : '';
        $value = isset( $field['value'] ) ? $field['value'] : '';
        $label_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
        $is_email = false !== strpos( $label_lc, 'email' ) || false !== strpos( $label_lc, 'e-mail' ) || false !== strpos( $label_lc, 'courriel' );
        $is_phone = false !== strpos( $label_lc, 'phone' ) || false !== strpos( $label_lc, 'téléphone' ) || false !== strpos( $label_lc, 'telephone' ) || false !== strpos( $label_lc, 'tel' );
        $plain_value = trim( wp_strip_all_tags( $value ) );
        if ( $is_email && is_email( $plain_value ) ) {
            $value = '<a class="desp-contact-link" href="mailto:' . esc_attr( $plain_value ) . '">' . esc_html( $plain_value ) . '</a>';
        } elseif ( $is_phone && $plain_value ) {
            $value = function_exists( 'wc_make_phone_clickable' ) ? wc_make_phone_clickable( $plain_value ) : esc_html( $plain_value );
        }
        ?>
        <tr class="desp-customer-row">
            <td class="desp-contact-label" width="34%" valign="top"><?php echo esc_html( $label ); ?></td>
            <td class="desp-contact-value" valign="top"><?php echo wp_kses_post( $value ); ?></td>
        </tr>
    <?php endforeach; ?>
</table>
