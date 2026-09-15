<?php
defined( 'ABSPATH' ) || exit;

/**
 * WordPress core new-user notification support.
 *
 * WordPress sends the administrator notification through wp_mail(), outside
 * WooCommerce's email renderer. This bridge gives that message the same
 * Delicat Email Studio visual system without changing who receives it or
 * when WordPress sends it.
 */

function dipes_wp_user_role_label( $user ) {
    if ( ! is_object( $user ) || empty( $user->roles ) ) {
        return 'Client';
    }
    $role_key = (string) reset( $user->roles );
    if ( function_exists( 'wp_roles' ) ) {
        $roles = wp_roles();
        if ( $roles && isset( $roles->roles[ $role_key ]['name'] ) ) {
            $name = $roles->roles[ $role_key ]['name'];
            return function_exists( 'translate_user_role' ) ? translate_user_role( $name ) : $name;
        }
    }
    return ucfirst( str_replace( array( '-', '_' ), ' ', $role_key ) );
}

function dipes_wp_normalize_user( $user ) {
    if ( $user instanceof WP_User ) {
        return $user;
    }
    if ( is_numeric( $user ) ) {
        $resolved = get_userdata( absint( $user ) );
        return $resolved instanceof WP_User ? $resolved : null;
    }
    if ( is_object( $user ) && ! empty( $user->ID ) ) {
        $resolved = get_userdata( absint( $user->ID ) );
        return $resolved instanceof WP_User ? $resolved : null;
    }
    if ( is_array( $user ) && ! empty( $user['ID'] ) ) {
        $resolved = get_userdata( absint( $user['ID'] ) );
        return $resolved instanceof WP_User ? $resolved : null;
    }
    return null;
}

function dipes_wp_user_admin_context( $user, $blogname = '' ) {
    $display_name = '';
    $email = '';
    $edit_url = '';
    $user = dipes_wp_normalize_user( $user );

    if ( $user instanceof WP_User ) {
        $first = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
        $last  = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );
        if ( ! $first ) { $first = trim( (string) get_user_meta( $user->ID, 'billing_first_name', true ) ); }
        if ( ! $last ) { $last = trim( (string) get_user_meta( $user->ID, 'billing_last_name', true ) ); }
        $display_name = trim( $first . ' ' . $last );
        if ( ! $display_name ) {
            $display_name = trim( (string) $user->display_name );
        }
        if ( ! $display_name ) {
            $display_name = (string) $user->user_login;
        }

        $email = sanitize_email( (string) $user->user_email );

        // Do not use get_edit_user_link() here. New-user notifications can be
        // generated during a front-end/anonymous request, and that helper
        // returns an empty string when the current request is not already an
        // administrator. The WordPress user-edit screen performs its own
        // authentication + edit_user capability checks when the link is opened.
        $edit_url = add_query_arg( 'user_id', absint( $user->ID ), admin_url( 'user-edit.php' ) );
    }

    return dipes_context(
        null,
        null,
        array(
            'site_name'       => $blogname ? wp_specialchars_decode( $blogname, ENT_QUOTES ) : dipes_brand_name(),
            'first_name'      => '',
            'last_name'       => '',
            'full_name'       => $display_name,
            'display_name'    => $display_name,
            'username'        => '',
            'user_email'      => $email,
            'user_id'         => '',
            'user_role'       => '',
            'registered_at'   => '',
            'edit_user_url'   => $edit_url,
            'users_admin_url' => admin_url( 'users.php' ),
        )
    );
}

function dipes_render_wp_user_panel( $ctx ) {
    $name  = trim( (string) ( $ctx['display_name'] ?? $ctx['full_name'] ?? '' ) );
    $email = sanitize_email( (string) ( $ctx['user_email'] ?? '' ) );

    echo '<table class="desp-customer-panel desp-user-panel" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">';
    echo '<tr><td class="desp-customer-panel-head" colspan="2"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>';
    echo '<td class="desp-customer-avatar" width="42" valign="middle"><span>+</span></td>';
    echo '<td valign="middle"><div class="desp-customer-kicker">Nouvelle inscription</div><div class="desp-customer-title">Profil du nouveau client</div></td>';
    echo '</tr></table></td></tr>';

    echo '<tr class="desp-customer-row"><td class="desp-contact-label" width="34%">Nom</td><td class="desp-contact-value">' . esc_html( $name ?: 'Client' ) . '</td></tr>';
    echo '<tr class="desp-customer-row"><td class="desp-contact-label">E-mail</td><td class="desp-contact-value">';
    if ( $email ) {
        echo '<a class="desp-contact-link" href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
    } else {
        echo '—';
    }
    echo '</td></tr>';
    echo '</table>';
}

function dipes_build_wp_new_user_admin_email( $user, $blogname = '' ) {
    $email_id = 'wp_new_user_admin';
    $ctx = dipes_wp_user_admin_context( $user, $blogname );
    $heading = dipes_replace_tokens( dipes_email_setting( $email_id, 'heading', 'Nouvel utilisateur' ), $ctx );

    $old_current = isset( $GLOBALS['dipes_current_email_id'] ) ? $GLOBALS['dipes_current_email_id'] : null;
    $old_render  = ! empty( $GLOBALS['dipes_rendering_email'] );
    $old_preview = ! empty( $GLOBALS['dipes_preview_mode'] );

    $GLOBALS['dipes_current_email_id'] = $email_id;
    $GLOBALS['dipes_rendering_email']  = true;
    $GLOBALS['dipes_preview_mode'] = true;

    ob_start();
    $email = null;
    $email_heading = $heading;
    include DIPES_DIR . 'templates/emails/email-header.php';
    dipes_render_intro( $email_id, $ctx );
    dipes_render_wp_user_panel( $ctx );
    dipes_render_cta( $email_id, $ctx );
    dipes_render_custom( $email_id, $ctx );
    include DIPES_DIR . 'templates/emails/email-footer.php';
    $html = ob_get_clean();

    if ( null === $old_current ) {
        unset( $GLOBALS['dipes_current_email_id'] );
    } else {
        $GLOBALS['dipes_current_email_id'] = $old_current;
    }
    if ( ! $old_render ) { unset( $GLOBALS['dipes_rendering_email'] ); }
    if ( ! $old_preview ) { unset( $GLOBALS['dipes_preview_mode'] ); }

    return $html;
}

function dipes_add_html_content_type_header( $headers ) {
    $was_array = is_array( $headers );
    $list = $was_array ? $headers : preg_split( '/\r?\n/', trim( (string) $headers ) );
    $list = array_values( array_filter( array_map( 'trim', (array) $list ) ) );
    $has_content_type = false;
    $has_from = false;
    foreach ( $list as $header ) {
        if ( 0 === stripos( (string) $header, 'Content-Type:' ) ) $has_content_type = true;
        if ( 0 === stripos( (string) $header, 'From:' ) ) $has_from = true;
    }
    if ( ! $has_content_type ) $list[] = 'Content-Type: text/html; charset=UTF-8';

    // Keep WordPress core notifications aligned with the WooCommerce sender
    // identity when the store has configured one. SMTP plugins can still
    // override the final transport/sender later in the normal wp_mail flow.
    if ( ! $has_from ) {
        $from_email = sanitize_email( get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) );
        $from_name  = sanitize_text_field( get_option( 'woocommerce_email_from_name', dipes_brand_name() ) );
        if ( is_email( $from_email ) ) $list[] = 'From: ' . $from_name . ' <' . $from_email . '>';
    }

    return $was_array ? $list : implode( "\r\n", $list );
}

add_filter(
    'wp_new_user_notification_email_admin',
    static function ( $notification, $user, $blogname ) {
        $email_id = 'wp_new_user_admin';
        if ( 'yes' !== dipes_email_setting( $email_id, 'enabled', 'yes' ) ) {
            return $notification;
        }

        $ctx = dipes_wp_user_admin_context( $user, $blogname );
        $subject = dipes_email_setting( $email_id, 'subject', 'Nouvel utilisateur — {site_name}' );

        $notification['subject'] = wp_strip_all_tags( dipes_replace_tokens( $subject, $ctx ) );
        $notification['message'] = dipes_build_wp_new_user_admin_email( $user, $blogname );
        $notification['headers'] = dipes_add_html_content_type_header( isset( $notification['headers'] ) ? $notification['headers'] : '' );

        return $notification;
    },
    99,
    3
);

/**
 * Extract the secure password-setup URL WordPress already generated for the
 * new-user notification. Reusing the existing key avoids generating a second
 * password-reset key and keeps the original registration flow intact.
 */
function dipes_wp_extract_new_user_setup_url( $message ) {
    $message = html_entity_decode( (string) $message, ENT_QUOTES, 'UTF-8' );
    if ( ! preg_match_all( '~https?://[^\s<>"\']+~i', $message, $matches ) ) {
        return '';
    }
    foreach ( $matches[0] as $url ) {
        $url = rtrim( $url, ".,;)>]" );
        if ( false !== strpos( $url, 'action=rp' ) || false !== strpos( $url, 'key=' ) ) {
            return esc_url_raw( $url );
        }
    }
    return '';
}

function dipes_wp_user_client_context( $user, $blogname = '', $setup_url = '' ) {
    $user = dipes_wp_normalize_user( $user );
    $display_name = $user instanceof WP_User ? trim( (string) $user->display_name ) : '';
    $first_name = $user instanceof WP_User ? trim( (string) get_user_meta( $user->ID, 'first_name', true ) ) : '';
    $last_name = $user instanceof WP_User ? trim( (string) get_user_meta( $user->ID, 'last_name', true ) ) : '';
    $full_name = trim( $first_name . ' ' . $last_name );
    if ( ! $full_name ) { $full_name = $display_name; }

    $registered = '';
    if ( $user instanceof WP_User && ! empty( $user->user_registered ) ) {
        $format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
        $registered = mysql2date( $format, $user->user_registered );
    }

    $account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
    $needs_setup = ! empty( $setup_url );

    return dipes_context(
        null,
        null,
        array(
            'site_name' => $blogname ? wp_specialchars_decode( $blogname, ENT_QUOTES ) : dipes_brand_name(),
            'first_name' => $first_name ?: ( $display_name ?: ( $user instanceof WP_User ? $user->user_login : 'Client' ) ),
            'last_name' => $last_name,
            'full_name' => $full_name ?: ( $user instanceof WP_User ? $user->user_login : 'Client' ),
            'display_name' => $display_name,
            'username' => $user instanceof WP_User ? $user->user_login : '',
            'user_email' => $user instanceof WP_User ? $user->user_email : '',
            'user_id' => $user instanceof WP_User ? (string) $user->ID : '',
            'user_role' => dipes_wp_user_role_label( $user ),
            'registered_at' => $registered,
            'set_password_url' => $setup_url,
            'needs_password_setup' => $needs_setup ? 'yes' : 'no',
            'account_status' => $needs_setup ? 'À sécuriser' : 'Compte actif',
            'account_url' => $account_url,
            'account_primary_url' => $needs_setup ? $setup_url : $account_url,
            'account_primary_label' => $needs_setup ? 'Créer mon mot de passe' : 'Accéder à mon compte',
        )
    );
}

function dipes_build_wp_new_user_client_email( $user, $blogname = '', $setup_url = '' ) {
    $email_id = 'customer_new_account';
    $ctx = dipes_wp_user_client_context( $user, $blogname, $setup_url );
    $heading = dipes_replace_tokens( dipes_email_setting( $email_id, 'heading', 'Bienvenue chez {site_name}' ), $ctx );

    $old_current = isset( $GLOBALS['dipes_current_email_id'] ) ? $GLOBALS['dipes_current_email_id'] : null;
    $old_render  = ! empty( $GLOBALS['dipes_rendering_email'] );
    $old_preview = ! empty( $GLOBALS['dipes_preview_mode'] );

    $GLOBALS['dipes_current_email_id'] = $email_id;
    $GLOBALS['dipes_rendering_email']  = true;
    $GLOBALS['dipes_preview_mode']     = true;

    ob_start();
    $email = null;
    $email_heading = $heading;
    include DIPES_DIR . 'templates/emails/email-header.php';
    dipes_render_intro( $email_id, $ctx );
    dipes_render_account_panel( $ctx );
    dipes_render_account_security( $ctx );
    dipes_render_cta( $email_id, $ctx );
    dipes_render_custom( $email_id, $ctx );
    include DIPES_DIR . 'templates/emails/email-footer.php';
    $html = ob_get_clean();

    if ( null === $old_current ) {
        unset( $GLOBALS['dipes_current_email_id'] );
    } else {
        $GLOBALS['dipes_current_email_id'] = $old_current;
    }
    if ( ! $old_render ) { unset( $GLOBALS['dipes_rendering_email'] ); }
    if ( ! $old_preview ) { unset( $GLOBALS['dipes_preview_mode'] ); }

    return $html;
}

add_filter(
    'wp_new_user_notification_email',
    static function ( $notification, $user, $blogname ) {
        $email_id = 'customer_new_account';
        if ( 'yes' !== dipes_email_setting( $email_id, 'enabled', 'yes' ) ) {
            return $notification;
        }

        $setup_url = dipes_wp_extract_new_user_setup_url( isset( $notification['message'] ) ? $notification['message'] : '' );
        $ctx = dipes_wp_user_client_context( $user, $blogname, $setup_url );
        $subject = dipes_email_setting( $email_id, 'subject', 'Bienvenue chez {site_name} — votre compte est prêt' );

        $notification['subject'] = wp_strip_all_tags( dipes_replace_tokens( $subject, $ctx ) );
        $notification['message'] = dipes_build_wp_new_user_client_email( $user, $blogname, $setup_url );
        $notification['headers'] = dipes_add_html_content_type_header( isset( $notification['headers'] ) ? $notification['headers'] : '' );

        return $notification;
    },
    99,
    3
);
