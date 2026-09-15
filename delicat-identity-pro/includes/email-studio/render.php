<?php
defined( 'ABSPATH' ) || exit;

function dipes_render_intro( $email_id, $ctx ) {
    $title = dipes_replace_tokens( dipes_email_setting( $email_id, 'intro_title', '' ), $ctx );
    $text  = dipes_replace_tokens( dipes_email_setting( $email_id, 'intro_text', '' ), $ctx );
    if ( ! $title && ! $text ) { return; }
    echo '<div class="desp-intro">';
    if ( $title ) { echo '<h2>' . wp_kses_post( $title ) . '</h2>'; }
    if ( $text ) { echo '<div class="desp-intro-text">' . wp_kses_post( wpautop( $text ) ) . '</div>'; }
    echo '</div>';
}

function dipes_render_notice( $email_id, $args, $ctx ) {
    if ( 'customer_note' === $email_id && ! empty( $args['customer_note'] ) ) {
        echo '<div class="desp-notice"><div class="desp-notice-label">Message de notre équipe</div><div class="desp-notice-content">' . wp_kses_post( wpautop( wptexturize( $args['customer_note'] ) ) ) . '</div></div>';
        return;
    }
    if ( 'customer_invoice' === $email_id && ! empty( $ctx['payment_url'] ) && isset( $args['order'] ) && is_object( $args['order'] ) && $args['order']->needs_payment() ) {
        echo '<div class="desp-notice"><div class="desp-notice-label">Paiement requis</div><div class="desp-notice-content">Votre commande est prête à être payée. Utilisez le bouton sécurisé ci-dessous pour finaliser le paiement.</div></div>';
    }
}

function dipes_render_summary( $email_id, $order, $ctx ) {
    if ( ! dipes_email_toggle( $email_id, 'show_summary', 'show_summary' ) || ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) ) { return; }

    $cells = array(
        array( 'Commande', '#' . $ctx['order_number'], 'desp-summary-order' ),
        array( 'Date', $ctx['order_date'], 'desp-summary-date' ),
        array( 'Total', $ctx['order_total'], 'desp-summary-total' ),
        array( 'Paiement', $ctx['payment_method'] ?: '—', 'desp-summary-payment' ),
    );

    echo '<table class="desp-summary" role="presentation" width="100%" cellpadding="0" cellspacing="0">';
    for ( $row = 0; $row < 2; $row++ ) {
        echo '<tr>';
        for ( $col = 0; $col < 2; $col++ ) {
            $cell = $cells[ ( $row * 2 ) + $col ];
            echo '<td class="desp-summary-cell ' . esc_attr( $cell[2] ) . '"><span>' . esc_html( $cell[0] ) . '</span><strong>' . wp_kses_post( $cell[1] ) . '</strong></td>';
        }
        echo '</tr>';
    }
    echo '</table>';
}

function dipes_render_progress( $email_id, $order ) {
    if ( ! dipes_email_toggle( $email_id, 'show_progress', 'show_progress' ) || ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) ) { return; }
    if ( in_array( $email_id, array( 'new_order', 'cancelled_order', 'failed_order', 'customer_failed_order', 'customer_cancelled_order' ), true ) ) { return; }

    $current = dipes_progress_step_for_order( $order, $email_id );
    $steps = array( 'Reçue', 'Payée', 'Traitement', 'Terminée' );

    echo '<div class="desp-progress-card"><div class="desp-section-head"><div><div class="desp-section-kicker">Suivi de votre commande</div><div class="desp-section-subtitle">Mise à jour automatique</div></div></div>';
    echo '<table class="desp-progress" role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>';
    foreach ( $steps as $i => $label ) {
        $step = $i + 1;
        $class = $step <= $current ? ' is-done' : '';
        $dot = $step < $current ? '✓' : ( $step === $current && 4 === $current ? '✓' : (string) $step );
        echo '<td class="desp-progress-step' . esc_attr( $class ) . '"><span class="desp-progress-dot">' . esc_html( $dot ) . '</span><small>' . esc_html( $label ) . '</small></td>';
    }
    echo '</tr></table></div>';
}

function dipes_render_cta( $email_id, $ctx ) {
    if ( ! dipes_email_toggle( $email_id, 'show_cta', 'show_cta' ) ) { return; }
    $label = dipes_replace_tokens( dipes_email_setting( $email_id, 'cta_label', '' ), $ctx );
    $url   = dipes_replace_tokens( dipes_email_setting( $email_id, 'cta_url', '' ), $ctx );
    if ( ! $label || ! $url ) { return; }
    echo '<p class="desp-button-wrap"><a class="desp-button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
}

function dipes_render_custom( $email_id, $ctx ) {
    $html = dipes_email_setting( $email_id, 'custom_html', '' );
    if ( ! $html ) { return; }
    echo '<div class="desp-custom">' . wp_kses_post( dipes_replace_tokens( $html, $ctx ) ) . '</div>';
}

function dipes_render_additional( $email_id, $additional_content, $ctx ) {
    if ( 'yes' !== dipes_email_setting( $email_id, 'show_additional', 'yes' ) || ! $additional_content ) { return; }
    $content = wp_kses_post( wpautop( wptexturize( dipes_replace_tokens( $additional_content, $ctx ) ) ) );
    echo '<table class="desp-message-card" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>';
    echo '<td class="desp-message-icon" width="46" valign="top"><span>i</span></td>';
    echo '<td class="desp-message-copy" valign="top"><div class="desp-message-kicker">Message de ' . esc_html( dipes_brand_name() ) . '</div><div class="desp-message-text">' . $content . '</div></td>';
    echo '</tr></table>';
}

function dipes_render_order_email( $args ) {
    $email = isset( $args['email'] ) ? $args['email'] : null;
    $order = isset( $args['order'] ) ? $args['order'] : null;
    $email_id = dipes_current_email_id( $email );
    $GLOBALS['dipes_current_email_id'] = $email_id;
    $GLOBALS['dipes_rendering_email'] = true;
    $ctx = dipes_context( $email, $order );
    $heading = isset( $args['email_heading'] ) ? $args['email_heading'] : dipes_email_setting( $email_id, 'heading', '' );

    do_action( 'woocommerce_email_header', $heading, $email );

    foreach ( dipes_current_block_order( $email_id ) as $block ) {
        switch ( $block ) {
            case 'intro': dipes_render_intro( $email_id, $ctx ); break;
            case 'notice': dipes_render_notice( $email_id, $args, $ctx ); break;
            case 'summary': dipes_render_summary( $email_id, $order, $ctx ); break;
            case 'progress': dipes_render_progress( $email_id, $order ); break;
            case 'cta': dipes_render_cta( $email_id, $ctx ); break;
            case 'order_details':
                if ( 'yes' === dipes_email_setting( $email_id, 'show_order_details', 'yes' ) && is_object( $order ) ) {
                    do_action( 'woocommerce_email_order_details', $order, ! empty( $args['sent_to_admin'] ), ! empty( $args['plain_text'] ), $email );
                }
                break;
            case 'order_meta':
                if ( is_object( $order ) ) { do_action( 'woocommerce_email_order_meta', $order, ! empty( $args['sent_to_admin'] ), ! empty( $args['plain_text'] ), $email ); }
                break;
            case 'customer_details':
                if ( 'yes' === dipes_email_setting( $email_id, 'show_customer_details', 'yes' ) && is_object( $order ) ) {
                    do_action( 'woocommerce_email_customer_details', $order, ! empty( $args['sent_to_admin'] ), ! empty( $args['plain_text'] ), $email );
                }
                break;
            case 'additional': dipes_render_additional( $email_id, isset( $args['additional_content'] ) ? $args['additional_content'] : '', $ctx ); break;
            case 'custom': dipes_render_custom( $email_id, $ctx ); break;
        }
    }

    do_action( 'woocommerce_email_footer', $email );
    unset( $GLOBALS['dipes_rendering_email'] );
}

function dipes_render_account_panel( $ctx ) {
    $rows = array(
        'Nom d’utilisateur' => isset( $ctx['username'] ) ? $ctx['username'] : '',
        'E-mail'            => isset( $ctx['user_email'] ) ? $ctx['user_email'] : '',
        'Statut'            => isset( $ctx['account_status'] ) ? $ctx['account_status'] : 'Compte actif',
    );

    echo '<table class="desp-customer-panel desp-account-panel" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">';
    echo '<tr><td class="desp-customer-panel-head" colspan="2"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>';
    echo '<td class="desp-customer-avatar" width="42" valign="middle"><span>✓</span></td>';
    echo '<td valign="middle"><div class="desp-customer-kicker">Votre espace client</div><div class="desp-customer-title">Informations du compte</div></td>';
    echo '</tr></table></td></tr>';

    foreach ( $rows as $label => $value ) {
        if ( '' === (string) $value ) { continue; }
        echo '<tr class="desp-customer-row"><td class="desp-contact-label" width="34%">' . esc_html( $label ) . '</td><td class="desp-contact-value">';
        if ( 'E-mail' === $label && is_email( $value ) ) {
            echo '<a class="desp-contact-link" href="mailto:' . esc_attr( sanitize_email( $value ) ) . '">' . esc_html( sanitize_email( $value ) ) . '</a>';
        } else {
            echo esc_html( $value );
        }
        echo '</td></tr>';
    }
    echo '</table>';
}

function dipes_render_account_security( $ctx ) {
    $needs_setup = ! empty( $ctx['needs_password_setup'] ) && 'yes' === (string) $ctx['needs_password_setup'];
    $kicker = $needs_setup ? 'Sécurité du compte' : 'Compte prêt';
    $text = $needs_setup
        ? 'Une dernière étape : créez votre mot de passe personnel avec le bouton ci-dessous. Pour votre sécurité, ne partagez jamais ce lien ni votre mot de passe.'
        : 'Votre compte est actif et prêt à être utilisé. Vous pouvez accéder à votre espace client pour suivre vos commandes et gérer vos informations.';

    echo '<table class="desp-message-card desp-account-security" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>';
    echo '<td class="desp-message-icon" width="46" valign="top"><span>' . ( $needs_setup ? '!' : '✓' ) . '</span></td>';
    echo '<td class="desp-message-copy" valign="top"><div class="desp-message-kicker">' . esc_html( $kicker ) . '</div><div class="desp-message-text"><p>' . esc_html( $text ) . '</p></div></td>';
    echo '</tr></table>';
}

function dipes_render_account_email( $args, $type ) {
    $email = isset( $args['email'] ) ? $args['email'] : null;
    $GLOBALS['dipes_current_email_id'] = $type;
    $GLOBALS['dipes_rendering_email'] = true;
    $extra = array();

    if ( 'customer_new_account' === $type ) {
        $username = isset( $args['user_login'] ) ? (string) $args['user_login'] : '';
        $display = isset( $args['user_display_name'] ) ? (string) $args['user_display_name'] : $username;
        $set_url = isset( $args['set_password_url'] ) ? (string) $args['set_password_url'] : '';
        $user = null;

        if ( isset( $args['user_id'] ) && $args['user_id'] ) {
            $user = get_user_by( 'id', absint( $args['user_id'] ) );
        }
        if ( ! $user && $username ) {
            $user = get_user_by( 'login', $username );
        }

        $user_email = $user && ! empty( $user->user_email ) ? (string) $user->user_email : '';
        $first_name = $user ? trim( (string) get_user_meta( $user->ID, 'first_name', true ) ) : '';
        if ( ! $first_name ) { $first_name = $display ?: $username; }

        $password_generated = ! empty( $args['password_generated'] );
        $needs_setup = $password_generated && ! empty( $set_url );
        $account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
        $primary_url = $needs_setup ? $set_url : $account_url;
        $primary_label = $needs_setup ? 'Créer mon mot de passe' : 'Accéder à mon compte';

        $extra = array(
            'username' => $username,
            'display_name' => $display,
            'first_name' => $first_name ?: 'Client',
            'user_email' => $user_email,
            'set_password_url' => $set_url,
            'needs_password_setup' => $needs_setup ? 'yes' : 'no',
            'account_status' => $needs_setup ? 'À sécuriser' : 'Compte actif',
            'account_primary_url' => $primary_url,
            'account_primary_label' => $primary_label,
        );
    } else {
        $username = isset( $args['user_login'] ) ? $args['user_login'] : '';
        $display = isset( $args['user_display_name'] ) ? $args['user_display_name'] : $username;
        $reset_url = '';
        if ( isset( $args['reset_key'], $args['user_id'] ) && function_exists( 'wc_get_endpoint_url' ) ) {
            $reset_url = add_query_arg(
                array( 'key' => $args['reset_key'], 'id' => absint( $args['user_id'] ), 'login' => rawurlencode( $username ) ),
                wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
            );
        }
        $extra = array( 'username' => $username, 'display_name' => $display, 'first_name' => $display ?: 'Client', 'reset_password_url' => $reset_url );
    }

    $ctx = dipes_context( $email, null, $extra );
    $heading = isset( $args['email_heading'] ) ? $args['email_heading'] : dipes_email_setting( $type, 'heading', '' );
    do_action( 'woocommerce_email_header', $heading, $email );
    dipes_render_intro( $type, $ctx );
    if ( 'customer_new_account' === $type ) {
        dipes_render_account_panel( $ctx );
        dipes_render_account_security( $ctx );
    }
    dipes_render_cta( $type, $ctx );
    dipes_render_additional( $type, isset( $args['additional_content'] ) ? $args['additional_content'] : '', $ctx );
    dipes_render_custom( $type, $ctx );
    do_action( 'woocommerce_email_footer', $email );
    unset( $GLOBALS['dipes_rendering_email'] );
}

function dipes_email_css( $email = null ) {
    $s = dipes_settings();
    $id = dipes_current_email_id( $email );
    $accent = dipes_email_accent( $id );
    $font = dipes_font_stack( $s['font'] );
    $heading_font = dipes_font_stack( $s['heading_font'] );
    $align = 'centered' === $s['header_layout'] ? 'center' : ( is_rtl() ? 'right' : 'left' );

    $effect = isset( $s['visual_effect'] ) ? $s['visual_effect'] : 'glass_neomorph';
    $effect_strength = isset( $s['effect_strength'] ) ? max( 0, min( 100, absint( $s['effect_strength'] ) ) ) : 78;
    $shadow_depth = isset( $s['shadow_depth'] ) ? max( 0, min( 100, absint( $s['shadow_depth'] ) ) ) : 72;
    $glass_highlight = isset( $s['glass_highlight'] ) ? max( 0, min( 100, absint( $s['glass_highlight'] ) ) ) : 42;
    $shadow_alpha = number_format( 0.05 + ( $shadow_depth / 100 ) * 0.18, 3, '.', '' );
    $card_alpha = number_format( 0.03 + ( $shadow_depth / 100 ) * 0.11, 3, '.', '' );
    $glass_alpha = number_format( ( $glass_highlight / 100 ) * 0.075, 3, '.', '' );
    $inset_alpha = number_format( ( $effect_strength / 100 ) * 0.07, 3, '.', '' );
    $has_glass = in_array( $effect, array( 'glass_neomorph', 'glass' ), true );
    $has_neomorph = in_array( $effect, array( 'glass_neomorph', 'neomorph' ), true );
    $shell_shadow = $has_neomorph ? '0 28px 72px rgba(2,8,23,' . $shadow_alpha . '), inset 0 1px 0 rgba(255,255,255,' . $inset_alpha . ')' : 'none';
    $card_shadow  = $has_neomorph ? '0 14px 34px rgba(2,8,23,' . $card_alpha . '), inset 0 1px 0 rgba(255,255,255,' . $inset_alpha . ')' : 'none';
    $soft_glass   = $has_glass ? 'rgba(255,255,255,' . $glass_alpha . ')' : 'rgba(255,255,255,0)';

    $css = '';
    $css .= 'body{margin:0!important;padding:0!important;background:' . $s['page_bg'] . ';color:' . $s['text'] . ';font-family:' . $font . ';-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}';
    $css .= 'table{border-collapse:collapse;}img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;}a{color:' . $accent . ';text-decoration:none;}';
    $css .= 'a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important;font-size:inherit!important;font-family:inherit!important;font-weight:inherit!important;line-height:inherit!important}';
    $css .= '#desp-outer{width:100%;background:' . $s['page_bg'] . ';padding:30px 12px;}';
    $css .= '#desp-shell{width:100%;max-width:' . absint( $s['container_width'] ) . 'px;background:' . $s['container_bg'] . ';border-radius:' . absint( $s['radius'] ) . 'px;overflow:hidden;border:1px solid ' . $s['border'] . ';box-shadow:' . $shell_shadow . ';}';
    $css .= '#desp-accent-bar{height:4px;line-height:4px;font-size:0;background:' . $accent . ';}';

    $css .= '#desp-header{background:' . $s['header_bg'] . ';color:' . $s['header_text'] . ';padding:22px ' . absint( $s['body_padding'] ) . 'px 28px;text-align:' . $align . ';border-bottom:1px solid ' . $s['border'] . ';}';
    if ( 'minimal' === $s['header_layout'] ) {
        $css .= '#desp-header{background:' . $s['container_bg'] . ';color:' . $s['heading'] . ';}';
    }
    $css .= '#desp-logo{display:block;max-width:' . min( 90, absint( $s['logo_width'] ) ) . 'px!important;max-height:72px!important;width:auto!important;height:auto!important;}';
    $css .= '#desp-brand{font-size:20px;line-height:1.2;font-weight:900;color:inherit;letter-spacing:-.25px;}';
    $css .= '.desp-brand-row{width:100%;}.desp-brand-cell{vertical-align:middle}.desp-status-cell{vertical-align:middle;text-align:right;}';
    $css .= '.desp-hero{padding-top:24px}.desp-hero-kicker{margin:0 0 10px;color:' . $s['muted'] . ';font-size:' . absint( $s['small_size'] ) . 'px;line-height:1.2;font-weight:900;text-transform:uppercase;letter-spacing:1.2px;}';
    $css .= '#desp-header h1{margin:0;color:inherit;font-family:' . $heading_font . ';font-size:' . absint( $s['heading_size'] ) . 'px;line-height:1.08;letter-spacing:-.75px;font-weight:850;}';
    $css .= '.desp-status{display:inline-block;padding:10px 14px;border-radius:999px;background:' . $accent . ';color:#fff!important;font-size:11px;font-weight:900;line-height:1;text-transform:uppercase;letter-spacing:.95px;white-space:nowrap;box-shadow:0 10px 24px rgba(15,23,42,.14), inset 0 1px 0 rgba(255,255,255,.24);}';

    $css .= '#desp-body{padding:' . absint( $s['body_padding'] ) . 'px;background:' . $s['container_bg'] . ';color:' . $s['text'] . ';font-family:' . $font . ';font-size:' . absint( $s['body_size'] ) . 'px;line-height:1.62;}';
    $css .= '#desp-body p{margin:0 0 16px;}#desp-body h2,#desp-body h3{font-family:' . $heading_font . ';color:' . $s['heading'] . ';}';
    $css .= '#desp-body h2{font-size:23px;line-height:1.18;margin:0 0 10px;letter-spacing:-.35px;}#desp-body h3{font-size:15px;line-height:1.3;margin:0 0 10px;}';
    $css .= '#desp-body a{color:' . $accent . ';font-weight:800;text-decoration:none;}#desp-body h2 a,#desp-body h3 a{color:' . $s['heading'] . '!important;text-decoration:none!important;font-weight:850;}';

    $css .= '.desp-intro{margin:0 0 22px;padding:0 2px}.desp-intro h2{font-size:25px!important}.desp-intro-text{color:' . $s['text'] . ';font-size:' . max( 15, absint( $s['body_size'] ) ) . 'px}.desp-intro-text p:last-child{margin-bottom:0}';
    $css .= '.desp-section-kicker,.desp-notice-label{color:' . $s['muted'] . ';font-size:' . absint( $s['small_size'] ) . 'px;font-weight:900;text-transform:uppercase;letter-spacing:.95px;}';
    $css .= '.desp-section-subtitle{color:' . $s['muted'] . ';font-size:11px;margin-top:3px;}';

    $css .= '.desp-summary{margin:0 0 24px;border:1px solid ' . $s['card_border'] . ';background:' . $s['card_bg'] . ';background-image:linear-gradient(180deg,' . $soft_glass . ',rgba(255,255,255,0));border-radius:' . absint( $s['card_radius'] ) . 'px;overflow:hidden;box-shadow:' . $card_shadow . ';}';
    $css .= '.desp-summary-cell{width:50%;padding:16px 18px;vertical-align:top;border-bottom:1px solid ' . $s['card_border'] . ';}';
    $css .= '.desp-summary tr:first-child .desp-summary-cell:first-child,.desp-summary tr:last-child .desp-summary-cell:first-child{border-right:1px solid ' . $s['card_border'] . ';}';
    $css .= '.desp-summary tr:last-child .desp-summary-cell{border-bottom:0}.desp-summary-cell span{display:block;color:' . $s['muted'] . ';font-size:' . absint( $s['small_size'] ) . 'px;margin-bottom:5px;line-height:1.25}.desp-summary-cell strong{display:block;color:' . $s['heading'] . ';font-size:15px;line-height:1.3;font-weight:850;word-break:normal;}';
    $css .= '.desp-summary-cell strong .woocommerce-Price-amount,.desp-summary-cell strong .amount,.desp-summary-cell strong .woocommerce-Price-currencySymbol,.desp-summary-cell strong bdi,.desp-summary-cell strong span{display:inline!important;white-space:nowrap!important;}';
    $css .= '.desp-summary-total strong{white-space:nowrap;color:' . $accent . ';}';

    $css .= '.desp-notice,.desp-progress-card,.desp-custom,.desp-additional{margin:0 0 24px;padding:18px 20px;border-radius:' . absint( $s['card_radius'] ) . 'px;background:' . $s['card_bg'] . ';background-image:linear-gradient(180deg,' . $soft_glass . ',rgba(255,255,255,0));border:1px solid ' . $s['card_border'] . ';box-shadow:' . $card_shadow . ';}';
    $css .= '.desp-message-card,.desp-customer-panel,.desp-address-card{width:100%;margin:0 0 24px;border:1px solid ' . $s['card_border'] . ';border-radius:' . absint( $s['card_radius'] ) . 'px;background:' . $s['card_bg'] . ';background-image:linear-gradient(180deg,' . $soft_glass . ',rgba(255,255,255,0));box-shadow:' . $card_shadow . ';border-collapse:separate!important;border-spacing:0!important;overflow:hidden;}';
    $css .= '.desp-message-card td{border:0!important}.desp-message-icon{padding:18px 0 18px 18px!important}.desp-message-icon span,.desp-customer-avatar span{display:inline-block;width:30px;height:30px;line-height:30px;text-align:center;border-radius:50%;background:' . $accent . ';color:#fff!important;font-size:13px;font-weight:900;box-shadow:0 8px 20px rgba(15,23,42,.12), inset 0 1px 0 rgba(255,255,255,.22)}.desp-message-copy{padding:17px 18px 15px 12px!important}.desp-message-kicker,.desp-customer-kicker,.desp-address-kicker{color:' . $s['muted'] . ';font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.9px;line-height:1.2}.desp-message-text{margin-top:6px;color:' . $s['text'] . ';font-size:13px;line-height:1.65}.desp-message-text p{margin:0!important}.desp-message-text a{color:' . $accent . '!important;font-weight:850!important;text-decoration:none!important}';
    $css .= '.desp-customer-panel{margin-top:24px!important}.desp-customer-panel td{border:0!important}.desp-customer-panel-head{padding:18px 18px 14px!important;border-bottom:1px solid ' . $s['card_border'] . '!important}.desp-customer-avatar{padding:0 12px 0 0!important}.desp-customer-avatar span{background:' . $accent . ';}.desp-customer-title,.desp-address-title{margin-top:4px;color:' . $s['heading'] . ';font-size:16px;font-weight:900;line-height:1.25}.desp-customer-row td{border-bottom:1px solid ' . $s['card_border'] . '!important}.desp-customer-row:last-child td{border-bottom:0!important}.desp-contact-label{padding:13px 10px 13px 18px!important;color:' . $s['muted'] . ';font-size:11px;font-weight:800;line-height:1.4}.desp-contact-value{padding:13px 18px 13px 10px!important;color:' . $s['heading'] . ';font-size:13px;font-weight:800;line-height:1.5;word-break:break-word}.desp-contact-value a,.desp-contact-link{color:' . $accent . '!important;text-decoration:none!important;font-weight:850!important}';
    $css .= '.desp-address-grid{width:100%;margin:0 0 24px;border-collapse:separate!important;border-spacing:0!important}.desp-address-cell{vertical-align:top}.desp-address-cell-left{padding-right:6px!important}.desp-address-cell-right{padding-left:6px!important}.desp-address-card{margin:0!important;padding:17px 18px!important;box-sizing:border-box}.desp-address-text{margin-top:9px;color:' . $s['text'] . ';font-size:12px;line-height:1.65;font-style:normal}.desp-address-text a{color:' . $accent . '!important;text-decoration:none!important;font-weight:800!important}.additional-fields{margin:0 0 24px!important;padding:16px 18px 16px 36px!important;border:1px solid ' . $s['card_border'] . '!important;border-radius:' . absint( $s['card_radius'] ) . 'px!important;background:' . $s['card_bg'] . '!important;box-shadow:' . $card_shadow . '!important;color:' . $s['text'] . '!important}';
    $css .= '.desp-notice{border-left:4px solid ' . $accent . ';}.desp-notice-content{margin-top:6px}.desp-notice-content p:last-child{margin-bottom:0}';

    $css .= '.desp-progress{margin-top:17px;table-layout:fixed}.desp-progress-step{width:25%;text-align:center;color:' . $s['muted'] . ';font-size:11px;vertical-align:top;position:relative;}';
    $css .= '.desp-progress-dot{display:inline-block;width:30px;height:30px;line-height:30px;text-align:center;border-radius:50%;border:2px solid ' . $s['border'] . ';background:' . $s['container_bg'] . ';font-weight:900;margin-bottom:7px;color:' . $s['muted'] . ';box-shadow:inset 0 1px 1px rgba(255,255,255,.08), 0 6px 18px rgba(15,23,42,.08);}';
    $css .= '.desp-progress-step.is-done{color:' . $accent . ';}.desp-progress-step.is-done .desp-progress-dot{background:' . $accent . ';border-color:' . $accent . ';color:#fff!important}.desp-progress-step small{display:block;font-size:10px;line-height:1.2;font-weight:750;}';

    $css .= '.desp-button-wrap{text-align:center;margin:2px 0 30px!important}.desp-button{display:inline-block;min-width:210px;background:' . $s['button_bg'] . ';color:' . $s['button_text'] . '!important;text-decoration:none;font-weight:900;font-size:15px;line-height:1;padding:17px 24px;border-radius:' . absint( $s['button_radius'] ) . 'px;text-align:center;box-shadow:0 14px 28px rgba(15,23,42,.16), inset 0 1px 0 rgba(255,255,255,.20);}';

    $css .= '#desp-body .email-order-details,#desp-body table.td{width:100%;margin:18px 0 26px;border:1px solid ' . $s['card_border'] . '!important;border-radius:' . absint( $s['card_radius'] ) . 'px!important;overflow:hidden;background:' . $s['card_bg'] . ';background-image:linear-gradient(180deg,' . $soft_glass . ',rgba(255,255,255,0));border-collapse:separate!important;border-spacing:0!important;box-shadow:' . $card_shadow . ';}';
    $css .= '#desp-body .email-order-details th,#desp-body .email-order-details td,#desp-body table.td th,#desp-body table.td td{padding:14px 14px;border:0!important;border-bottom:1px solid ' . $s['card_border'] . '!important;color:' . $s['text'] . ';font-family:' . $font . ';font-size:14px;vertical-align:middle;}';
    $css .= '#desp-body .email-order-details thead th,#desp-body table.td thead th{background:' . $s['table_header_bg'] . ';color:' . $s['table_header_text'] . ';font-size:10px;text-transform:uppercase;letter-spacing:.9px;font-weight:900;}';
    $css .= '#desp-body .email-order-details h2,#desp-body table.td h2{margin:0 0 12px!important;}';
    $css .= '#desp-body .order_item:last-child td{border-bottom:1px solid ' . $s['card_border'] . '!important;}';
    $css .= '#desp-body .order_item td:first-child{width:68%;}#desp-body .order_item td:nth-child(2){width:12%;white-space:nowrap;}#desp-body .order_item td:last-child{width:20%;white-space:nowrap;font-weight:800;color:' . $s['heading'] . ';}';
    $css .= '#desp-body .order_item a{color:' . $accent . ' !important;font-weight:800;text-decoration:none;}';
    $css .= '#desp-body .email-order-item-thumbnail img,#desp-body .order_item img{border-radius:12px!important;height:auto!important;margin-right:12px!important;}';
    $css .= '#desp-body .email-order-item-meta,.wc-item-meta{color:' . $s['muted'] . ';font-size:12px!important;line-height:1.45!important;margin-top:5px!important;}';
    $css .= '#desp-body .wc-item-meta li,#desp-body .wc-item-meta p{margin:0!important;line-height:1.5!important;}';
    $css .= '#desp-body .order-totals th,#desp-body .order-totals td{background:' . $s['container_bg'] . ';font-size:13px;}';
    $css .= '#desp-body .order-totals-total th,#desp-body .order-totals-total td{font-weight:900!important;color:' . $s['heading'] . '!important;font-size:15px!important;border-bottom:0!important;}';

    $css .= '#addresses{width:100%;margin:22px 0 6px;border-collapse:separate!important;border-spacing:0 10px!important}#addresses td{vertical-align:top;}';
    $css .= '#addresses h3{margin-bottom:8px!important}.address{padding:16px!important;border-radius:' . absint( $s['card_radius'] ) . 'px!important;border:1px solid ' . $s['card_border'] . '!important;background:' . $s['card_bg'] . ';background-image:linear-gradient(180deg,' . $soft_glass . ',rgba(255,255,255,0));font-style:normal;color:' . $s['text'] . ';line-height:1.6;box-shadow:' . $card_shadow . ';}';

    $css .= '#desp-footer{background:' . $s['footer_bg'] . ';padding:28px ' . absint( $s['body_padding'] ) . 'px 30px;text-align:center;color:' . $s['footer_text_color'] . ';font-family:' . $font . ';font-size:' . absint( $s['small_size'] ) . 'px;line-height:1.6;border-top:1px solid ' . $s['border'] . ';}';
    $css .= '#desp-footer a{color:' . $accent . ';text-decoration:none}.desp-support{margin:0 0 20px;padding:18px 20px;border:1px solid ' . $s['card_border'] . ';background:' . $s['card_bg'] . ';background-image:linear-gradient(180deg,' . $soft_glass . ',rgba(255,255,255,0));border-radius:' . absint( $s['card_radius'] ) . 'px;text-align:left;box-shadow:' . $card_shadow . ';}.desp-support-title{font-size:16px;font-weight:900;color:' . $s['heading'] . ';margin:0 0 4px}.desp-support-text{margin:0 0 12px;color:' . $s['text'] . '}.desp-footer-links{margin:14px 0}.desp-social{margin:10px 0}.desp-social a{display:inline-block;margin:0 7px;font-weight:800}';

    $css .= '@media only screen and (max-width:680px){#desp-outer{padding:0!important}#desp-shell{border-radius:0!important;border-left:0!important;border-right:0!important;box-shadow:none!important}#desp-header,#desp-body,#desp-footer{padding-left:20px!important;padding-right:20px!important}.desp-status-cell{width:124px!important}.desp-status{font-size:10px!important;padding:8px 10px!important}#desp-logo{max-width:74px!important;max-height:64px!important}.desp-hero{padding-top:20px!important}#desp-header h1{font-size:' . max( 24, absint( $s['heading_size'] ) - 4 ) . 'px!important}.desp-intro h2{font-size:22px!important}.desp-summary-cell{padding:14px 13px!important}.desp-summary-cell strong{font-size:14px!important}.desp-progress-card{padding:17px 12px!important}.desp-progress-step small{font-size:9px!important}.desp-progress-dot{width:28px!important;height:28px!important;line-height:28px!important}.desp-button{display:block!important;min-width:0!important}.desp-support{padding:16px!important}#addresses td{display:block!important;width:100%!important;padding:0!important}.address{margin-bottom:10px!important}#desp-body .email-order-details th,#desp-body .email-order-details td,#desp-body table.td th,#desp-body table.td td{padding:12px 9px!important;font-size:12px!important}#desp-body .email-order-item-meta,.wc-item-meta{font-size:10px!important}#desp-body .order_item img{max-width:48px!important;height:auto!important;margin-right:8px!important}.desp-message-icon{padding-left:14px!important}.desp-message-copy{padding-right:14px!important}.desp-customer-panel-head{padding:15px!important}.desp-contact-label{padding:12px 7px 12px 15px!important;width:32%!important}.desp-contact-value{padding:12px 15px 12px 7px!important}.desp-address-cell{display:block!important;width:100%!important;padding:0!important}.desp-address-cell-left{padding-bottom:10px!important}}';

    $dark_rules = 'body,#desp-outer{background:#050a12!important}#desp-shell,#desp-body{background:#0f1726!important;border-color:#263247!important;color:#d7dfec!important;box-shadow:' . ( $has_neomorph ? '0 28px 72px rgba(0,0,0,.55), inset 0 1px 0 rgba(255,255,255,.03)' : 'none' ) . '!important}#desp-header{background:#0b1322!important;border-color:#263247!important;color:#ffffff!important}.desp-hero-kicker{color:#7f8ba0!important}#desp-body h2,#desp-body h3{color:#ffffff!important}#desp-body h2 a,#desp-body h3 a{color:#ffffff!important}.desp-intro-text{color:#d7dfec!important}.desp-summary,.desp-notice,.desp-progress-card,.desp-custom,.desp-additional,.desp-message-card,.desp-customer-panel,.desp-address-card,.additional-fields,.desp-support,.address,#desp-body .email-order-details,#desp-body table.td{background:#162135!important;border-color:#2a3850!important;color:#d7dfec!important;box-shadow:' . ( $has_neomorph ? '0 12px 32px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.03)' : 'none' ) . '!important}.desp-summary-cell{border-color:#2a3850!important}.desp-summary-cell strong{color:#ffffff!important}.desp-section-kicker,.desp-section-subtitle,.desp-summary-cell span{color:#8e9bb1!important}.desp-progress-dot{background:#0f1726!important;border-color:#3a4962!important;color:#9aa8bc!important}#desp-body .email-order-details th,#desp-body .email-order-details td,#desp-body table.td th,#desp-body table.td td{border-color:#2a3850!important;color:#d7dfec!important}#desp-body .email-order-details thead th,#desp-body table.td thead th{background:#1b2940!important;color:#dce5f2!important}#desp-body .order-totals th,#desp-body .order-totals td{background:#111b2c!important}.desp-support-title,.desp-customer-title,.desp-address-title,.desp-contact-value,#desp-body .order-totals-total th,#desp-body .order-totals-total td{color:#ffffff!important}.desp-support-text,.desp-message-text,.desp-address-text{color:#cbd5e1!important}.desp-contact-label,.desp-message-kicker,.desp-customer-kicker,.desp-address-kicker{color:#8e9bb1!important}#desp-footer{background:#09111f!important;border-color:#263247!important;color:#8e9bb1!important}';
    $appearance = isset( $s['appearance_mode'] ) ? $s['appearance_mode'] : 'auto';
    if ( 'dark' === $appearance ) {
        $css .= $dark_rules;
    } elseif ( 'auto' === $appearance && 'yes' === $s['dark_mode'] ) {
        $css .= '@media (prefers-color-scheme:dark){' . $dark_rules . '}';
    }

    if ( ! empty( $s['custom_css'] ) ) { $css .= "\n" . $s['custom_css']; }
    return apply_filters( 'dipes_email_css', $css, $email, $id );
}
