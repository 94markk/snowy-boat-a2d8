<?php
defined( 'ABSPATH' ) || exit;

function dipes_admin_capable() {
    return current_user_can( 'manage_options' );
}

function dipes_preview_context() {
    $symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'G';
    return array(
        'site_name' => dipes_brand_name(),
        'first_name' => 'Louis', 'last_name' => 'Salomon', 'full_name' => 'Louis Salomon',
        'billing_email' => 'markenlouis@gmail.com', 'billing_phone' => '+509 3311-1283', 'billing_company' => 'Delicat Store',
        'order_number' => '8960', 'order_date' => 'August 11, 2026', 'order_total' => 'G590 HTG', 'order_total_html' => 'G590 HTG',
        'payment_method' => 'Payer avec Delicat Wallet', 'order_status' => 'En traitement',
        'order_url' => home_url( '/my-account/view-order/8960/' ), 'admin_order_url' => admin_url( 'post.php?post=8960&action=edit' ),
        'payment_url' => home_url( '/checkout/order-pay/8960/' ),
        'account_url' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ),
        'account_orders_url' => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/my-account/orders/' ),
        'username' => 'bethinaduclervil01', 'display_name' => 'Bethina', 'user_email' => 'bethinaduclervil01@gmail.com',
        'user_id' => '2451', 'user_role' => 'Client', 'registered_at' => 'August 11, 2026 4:11 PM', 'edit_user_url' => admin_url( 'user-edit.php?user_id=2451' ),
        'reset_password_url' => home_url( '/my-account/lost-password/?key=secure-preview' ),
        'set_password_url' => home_url( '/my-account/lost-password/?key=secure-new-account-preview' ),
        'account_primary_url' => home_url( '/my-account/lost-password/?key=secure-new-account-preview' ),
        'account_primary_label' => 'Créer mon mot de passe', 'needs_password_setup' => 'yes', 'account_status' => 'À sécuriser',
        'security_url' => home_url('/my-account/'), 'action_url' => home_url('/my-account/'), 'admin_identity_url' => admin_url('admin.php?page=dip-ui-stability'),
        'support_url' => dipes_get( 'support_url' ), 'support_email' => dipes_get( 'support_email' ) ?: get_option( 'admin_email' ),
        'year' => gmdate( 'Y' ),
    );
}

function dipes_preview_order_table( $ctx ) {
    $image = DIPES_URL . 'assets/preview-freefire.jpg';
    $items = array(
        array( 'Free Fire (LATAM) – 341 Diamonds', 'Player ID: 10146926113 · Player account: ✓ ALPHA-♡', '×1', 'G445' ),
        array( 'Free Fire (LATAM) – 110 Diamonds', 'Player ID: 9799678630 · Player account: ✓ MR_FLANKY.', '×1', 'G145' ),
    );
    ?>
    <h2 style="margin-top:28px">Résumé de la commande</h2>
    <div class="desp-section-subtitle" style="margin:-4px 0 12px">Commande #<?php echo esc_html( $ctx['order_number'] ); ?> · <?php echo esc_html( $ctx['order_date'] ); ?></div>
    <table class="email-order-details td" cellspacing="0" cellpadding="0" width="100%" role="presentation">
        <thead><tr><th align="left">Produit</th><th align="center">Qté</th><th align="right">Prix</th></tr></thead>
        <tbody>
        <?php foreach ( $items as $item ) : ?>
            <tr class="order_item">
                <td>
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                        <td width="62" style="border:0!important;padding:0 10px 0 0!important"><img src="<?php echo esc_url( $image ); ?>" width="54" height="54" alt="" /></td>
                        <td style="border:0!important;padding:0!important"><strong><a href="#"><?php echo esc_html( $item[0] ); ?></a></strong><div class="email-order-item-meta"><?php echo esc_html( $item[1] ); ?></div></td>
                    </tr></table>
                </td>
                <td align="center"><?php echo esc_html( $item[2] ); ?></td>
                <td align="right"><?php echo esc_html( $item[3] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="order-totals"><th colspan="2" align="right">Sous-total</th><td align="right">G590</td></tr>
            <tr class="order-totals"><th colspan="2" align="right">Paiement</th><td align="right"><?php echo esc_html( $ctx['payment_method'] ); ?></td></tr>
            <tr class="order-totals order-totals-total"><th colspan="2" align="right">Total</th><td align="right"><?php echo esc_html( $ctx['order_total'] ); ?></td></tr>
        </tfoot>
    </table>
    <?php
}

function dipes_preview_summary( $email_id, $ctx ) {
    if ( ! dipes_email_toggle( $email_id, 'show_summary', 'show_summary' ) ) { return; }
    $cells = array(
        array( 'Commande', '#' . $ctx['order_number'], 'desp-summary-order' ),
        array( 'Date', $ctx['order_date'], 'desp-summary-date' ),
        array( 'Total', $ctx['order_total'], 'desp-summary-total' ),
        array( 'Paiement', $ctx['payment_method'], 'desp-summary-payment' ),
    );
    echo '<table class="desp-summary" role="presentation" width="100%" cellpadding="0" cellspacing="0">';
    for ( $row = 0; $row < 2; $row++ ) {
        echo '<tr>';
        for ( $col = 0; $col < 2; $col++ ) {
            $cell = $cells[ ( $row * 2 ) + $col ];
            echo '<td class="desp-summary-cell ' . esc_attr( $cell[2] ) . '"><span>' . esc_html( $cell[0] ) . '</span><strong>' . esc_html( $cell[1] ) . '</strong></td>';
        }
        echo '</tr>';
    }
    echo '</table>';
}

function dipes_preview_progress( $email_id ) {
    if ( ! dipes_email_toggle( $email_id, 'show_progress', 'show_progress' ) || in_array( $email_id, array('new_order','cancelled_order','failed_order','customer_failed_order','customer_cancelled_order'), true ) ) { return; }
    $current = false !== strpos( $email_id, 'completed' ) || false !== strpos( $email_id, 'refunded' ) ? 4 : ( false !== strpos( $email_id, 'processing' ) ? 3 : 2 );
    echo '<div class="desp-progress-card"><div class="desp-section-kicker">Suivi de votre commande</div><div class="desp-section-subtitle">Mise à jour automatique</div><table class="desp-progress" role="presentation" width="100%"><tr>';
    foreach ( array('Reçue','Payée','Traitement','Terminée') as $i => $label ) {
        $step = $i + 1;
        $class = $step <= $current ? ' is-done' : '';
        $dot = $step < $current ? '✓' : ( $step === $current && 4 === $current ? '✓' : (string) $step );
        echo '<td class="desp-progress-step' . esc_attr( $class ) . '"><span class="desp-progress-dot">' . esc_html( $dot ) . '</span><small>' . esc_html( $label ) . '</small></td>';
    }
    echo '</tr></table></div>';
}

function dipes_preview_body( $email_id, $ctx ) {
    if ( 'wp_new_user_admin' === $email_id ) {
        dipes_render_intro( $email_id, $ctx );
        dipes_render_wp_user_panel( $ctx );
        dipes_render_cta( $email_id, $ctx );
        dipes_render_custom( $email_id, $ctx );
        return;
    }

    if ( 'customer_new_account' === $email_id ) {
        dipes_render_intro( $email_id, $ctx );
        dipes_render_account_panel( $ctx );
        dipes_render_account_security( $ctx );
        dipes_render_cta( $email_id, $ctx );
        dipes_render_custom( $email_id, $ctx );
        return;
    }

    if ( 'customer_reset_password' === $email_id ) {
        dipes_render_intro( $email_id, $ctx );
        dipes_render_cta( $email_id, $ctx );
        dipes_render_custom( $email_id, $ctx );
        return;
    }

    if ( in_array( $email_id, array( 'identity_security_alert', 'identity_access_message', 'identity_admin_alert' ), true ) ) {
        dipes_render_intro( $email_id, $ctx );
        $label = 'identity_admin_alert' === $email_id ? 'Delicat Identity' : ( 'identity_access_message' === $email_id ? 'Accès sécurisé' : 'Sécurité du compte' );
        $message = 'identity_admin_alert' === $email_id ? 'Une action administrative liée à Delicat Identity nécessite votre attention.' : ( 'identity_access_message' === $email_id ? 'Utilisez cette notification sécurisée pour poursuivre l’action demandée.' : 'Une activité importante concernant la sécurité de votre compte a été enregistrée.' );
        echo '<div class="desp-notice"><div class="desp-notice-label">' . esc_html( $label ) . '</div><div class="desp-notice-content"><p>' . esc_html( $message ) . '</p></div></div>';
        dipes_render_cta( $email_id, $ctx );
        dipes_render_custom( $email_id, $ctx );
        return;
    }

    foreach ( dipes_current_block_order( $email_id ) as $block ) {
        switch ( $block ) {
            case 'intro': dipes_render_intro( $email_id, $ctx ); break;
            case 'notice':
                if ( 'customer_note' === $email_id ) {
                    echo '<div class="desp-notice"><div class="desp-notice-label">Message de notre équipe</div><div class="desp-notice-content">Votre recharge a été vérifiée et la livraison est en cours.</div></div>';
                } elseif ( 'customer_invoice' === $email_id ) {
                    echo '<div class="desp-notice"><div class="desp-notice-label">Paiement requis</div><div class="desp-notice-content">Finalisez le paiement de cette commande avec le bouton sécurisé.</div></div>';
                }
                break;
            case 'summary': dipes_preview_summary( $email_id, $ctx ); break;
            case 'progress': dipes_preview_progress( $email_id ); break;
            case 'cta': dipes_render_cta( $email_id, $ctx ); break;
            case 'order_details': if ( 'yes' === dipes_email_setting( $email_id, 'show_order_details', 'yes' ) ) { dipes_preview_order_table( $ctx ); } break;
            case 'order_meta': break;
            case 'customer_details':
                if ( 'yes' === dipes_email_setting( $email_id, 'show_customer_details', 'yes' ) ) {
                    echo '<table class="desp-customer-panel" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td class="desp-customer-panel-head" colspan="2"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td class="desp-customer-avatar" width="42" valign="middle"><span>✓</span></td><td valign="middle"><div class="desp-customer-kicker">Commande sécurisée</div><div class="desp-customer-title">Coordonnées du client</div></td></tr></table></td></tr><tr class="desp-customer-row"><td class="desp-contact-label" width="34%">Nom</td><td class="desp-contact-value">Delicat Store</td></tr><tr class="desp-customer-row"><td class="desp-contact-label">E-mail</td><td class="desp-contact-value"><a class="desp-contact-link" href="#">delicatstoreha@gmail.com</a></td></tr><tr class="desp-customer-row"><td class="desp-contact-label">Téléphone</td><td class="desp-contact-value"><a class="desp-contact-link" href="#">+509 3311-1283</a></td></tr></table>';
                }
                break;
            case 'additional':
                if ( 'yes' === dipes_email_setting( $email_id, 'show_additional', 'yes' ) ) {
                    dipes_render_additional( $email_id, 'Merci pour votre confiance. Conservez cet e-mail comme confirmation de votre commande.', $ctx );
                }
                break;
            case 'custom': dipes_render_custom( $email_id, $ctx ); break;
        }
    }
}

function dipes_build_preview( $email_id, $overrides = array() ) {
    $types = dipes_email_types();
    $email_id = isset( $types[ $email_id ] ) ? $email_id : 'customer_processing_order';
    $GLOBALS['dipes_preview_mode'] = true;
    $GLOBALS['dipes_preview_email_id'] = $email_id;
    $GLOBALS['dipes_preview_overrides'] = is_array( $overrides ) ? $overrides : array();
    $GLOBALS['dipes_preview_context'] = dipes_preview_context();

    $ctx = dipes_context( null );
    $heading = dipes_replace_tokens( dipes_email_setting( $email_id, 'heading', 'Aperçu' ), $ctx );
    ob_start();
    $email = null;
    $email_heading = $heading;
    include DIPES_DIR . 'templates/emails/email-header.php';
    dipes_preview_body( $email_id, $ctx );
    include DIPES_DIR . 'templates/emails/email-footer.php';
    $html = ob_get_clean();

    unset( $GLOBALS['dipes_preview_mode'], $GLOBALS['dipes_preview_email_id'], $GLOBALS['dipes_preview_context'], $GLOBALS['dipes_preview_overrides'] );
    return $html;
}

function dipes_preview_overrides_from_request( $email_id ) {
    $base = dipes_settings();
    $global = isset( $_REQUEST['g'] ) && is_array( $_REQUEST['g'] ) ? wp_unslash( $_REQUEST['g'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $email = isset( $_REQUEST['e'] ) && is_array( $_REQUEST['e'] ) ? wp_unslash( $_REQUEST['e'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    foreach ( $global as $key => $value ) { if ( array_key_exists( $key, $base ) && 'emails' !== $key ) { $base[ $key ] = $value; } }
    if ( isset( $base['emails'][ $email_id ] ) ) { $base['emails'][ $email_id ] = array_merge( $base['emails'][ $email_id ], $email ); }
    return dipes_sanitize_settings( $base );
}

add_action( 'admin_post_dipes_email_preview', static function () {
    if ( ! dipes_admin_capable() ) { wp_die( 'Accès refusé.' ); }
    check_admin_referer( 'dipes_email_preview' );
    $type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'customer_processing_order';
    $overrides = dipes_preview_overrides_from_request( $type );
    nocache_headers();
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'X-Frame-Options: SAMEORIGIN' );
    echo dipes_build_preview( $type, $overrides ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
} );

add_action( 'admin_post_dipes_email_test', static function () {
    if ( ! dipes_admin_capable() ) { wp_die( 'Accès refusé.' ); }
    check_admin_referer( 'dipes_email_test' );
    $type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'customer_processing_order';
    $to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : get_option( 'admin_email' );
    if ( ! is_email( $to ) ) { $to = get_option( 'admin_email' ); }
    $html = dipes_build_preview( $type );
    if ( class_exists( 'WC_Email' ) ) { $wc_email = new WC_Email(); $html = $wc_email->style_inline( $html ); }
    $ctx = dipes_preview_context();
    $subject = dipes_replace_tokens( dipes_email_setting( $type, 'subject', 'Test e-mail' ), $ctx );
    $sent = function_exists( 'WC' ) && WC()->mailer() ? WC()->mailer()->send( $to, '[TEST] ' . wp_strip_all_tags( $subject ), $html, 'Content-Type: text/html; charset=UTF-8' ) : wp_mail( $to, '[TEST] ' . wp_strip_all_tags( $subject ), $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    wp_safe_redirect( add_query_arg( array( 'page' => 'delicat-identity-emails', 'dipes_test' => $sent ? 'ok' : 'fail' ), admin_url( 'admin.php' ) ) );
    exit;
} );
