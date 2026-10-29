<?php
defined( 'ABSPATH' ) || exit;

function dipes_email_types() {
    return apply_filters(
        'dipes_email_types',
        array(
            'wp_new_user_admin'         => 'Admin — Nouvel utilisateur',
            'new_order'                 => 'Admin — Nouvelle commande',
            'cancelled_order'           => 'Admin — Commande annulée',
            'failed_order'              => 'Admin — Paiement échoué',
            'customer_on_hold_order'    => 'Client — Commande en attente',
            'customer_processing_order' => 'Client — Commande en traitement',
            'customer_completed_order'  => 'Client — Commande terminée',
            'customer_refunded_order'   => 'Client — Commande remboursée',
            'customer_invoice'          => 'Client — Facture / paiement',
            'customer_note'             => 'Client — Note de commande',
            'customer_failed_order'     => 'Client — Paiement échoué',
            'customer_cancelled_order'  => 'Client — Commande annulée',
            'customer_new_account'      => 'Client — Nouveau compte',
            'customer_reset_password'   => 'Client — Mot de passe oublié',
        )
    );
}

function dipes_default_email_copy() {
    return array(
        'wp_new_user_admin' => array(
            'subject' => 'Nouvel utilisateur — {site_name}', 'heading' => 'Nouvel utilisateur', 'preheader' => 'Une nouvelle inscription a été enregistrée sur {site_name}.',
            'badge' => 'Nouveau compte', 'intro_title' => 'Nouvelle inscription reçue.', 'intro_text' => 'Un nouveau client vient de créer un compte sur <strong>{site_name}</strong>. Son nom et son adresse e-mail sont affichés ci-dessous.',
            'cta_label' => 'Voir le profil client', 'cta_url' => '{edit_user_url}', 'accent' => '#2563eb',
        ),
        'new_order' => array(
            'subject' => 'Nouvelle commande #{order_number} — {site_name}', 'heading' => 'Nouvelle commande', 'preheader' => 'Une nouvelle commande vient d’être reçue.',
            'badge' => 'Nouvelle commande', 'intro_title' => 'Nouvelle commande reçue.', 'intro_text' => 'Une commande de <strong>{full_name}</strong> vient d’être enregistrée.',
            'cta_label' => 'Ouvrir la commande', 'cta_url' => '{admin_order_url}', 'accent' => '#2563eb',
        ),
        'cancelled_order' => array(
            'subject' => 'Commande #{order_number} annulée', 'heading' => 'Commande annulée', 'preheader' => 'Une commande vient d’être annulée.',
            'badge' => 'Annulée', 'intro_title' => 'Commande annulée.', 'intro_text' => 'La commande de <strong>{full_name}</strong> a été annulée.',
            'cta_label' => 'Ouvrir la commande', 'cta_url' => '{admin_order_url}', 'accent' => '#dc2626',
        ),
        'failed_order' => array(
            'subject' => 'Échec du paiement — commande #{order_number}', 'heading' => 'Paiement échoué', 'preheader' => 'Le paiement d’une commande a échoué.',
            'badge' => 'Paiement échoué', 'intro_title' => 'Paiement échoué.', 'intro_text' => 'Le paiement de la commande de <strong>{full_name}</strong> n’a pas abouti. Vérifiez la transaction avant toute relance.',
            'cta_label' => 'Ouvrir la commande', 'cta_url' => '{admin_order_url}', 'accent' => '#dc2626',
        ),
        'customer_on_hold_order' => array(
            'subject' => 'Votre commande #{order_number} est en attente', 'heading' => 'Commande en attente', 'preheader' => 'Votre commande est bien enregistrée et attend la confirmation du paiement.',
            'badge' => 'En attente', 'intro_title' => 'Commande bien enregistrée.', 'intro_text' => 'Bonjour {first_name}, votre commande est en attente de confirmation du paiement.',
            'cta_label' => 'Voir ma commande', 'cta_url' => '{order_url}', 'accent' => '#d97706',
        ),
        'customer_processing_order' => array(
            'subject' => 'Votre commande #{order_number} est confirmée', 'heading' => 'Commande confirmée', 'preheader' => 'Paiement reçu. Votre commande est en cours de traitement.',
            'badge' => 'Confirmée', 'intro_title' => 'Merci {first_name} !', 'intro_text' => 'Votre paiement a bien été reçu. Votre commande est maintenant en cours de traitement.',
            'cta_label' => 'Voir ma commande', 'cta_url' => '{order_url}', 'accent' => '#2563eb',
        ),
        'customer_completed_order' => array(
            'subject' => 'Votre commande #{order_number} est terminée', 'heading' => 'Commande terminée', 'preheader' => 'Votre commande a été livrée avec succès.',
            'badge' => 'Terminée', 'intro_title' => 'Votre commande est prête.', 'intro_text' => 'Bonjour {first_name}, votre commande a été livrée avec succès. Merci pour votre confiance.',
            'cta_label' => 'Voir ma commande', 'cta_url' => '{order_url}', 'accent' => '#16a34a',
        ),
        'customer_refunded_order' => array(
            'subject' => 'Remboursement de la commande #{order_number}', 'heading' => 'Remboursement effectué', 'preheader' => 'Un remboursement a été appliqué à votre commande.',
            'badge' => 'Remboursée', 'intro_title' => 'Remboursement confirmé.', 'intro_text' => 'Bonjour {first_name}, un remboursement a été appliqué à votre commande.',
            'cta_label' => 'Voir ma commande', 'cta_url' => '{order_url}', 'accent' => '#7c3aed',
        ),
        'customer_invoice' => array(
            'subject' => 'Détails de votre commande #{order_number}', 'heading' => 'Détails de votre commande', 'preheader' => 'Retrouvez votre facture et les détails de votre commande.',
            'badge' => 'Facture', 'intro_title' => 'Voici votre facture.', 'intro_text' => 'Bonjour {first_name}, retrouvez ci-dessous le détail complet de votre commande.',
            'cta_label' => '{payment_label}', 'cta_url' => '{payment_url}', 'accent' => '#0f766e',
        ),
        'customer_note' => array(
            'subject' => 'Nouvelle information — commande #{order_number}', 'heading' => 'Mise à jour de votre commande', 'preheader' => 'Une nouvelle information a été ajoutée à votre commande.',
            'badge' => 'Mise à jour', 'intro_title' => 'Une note a été ajoutée.', 'intro_text' => 'Bonjour {first_name}, notre équipe a ajouté une information à votre commande.',
            'cta_label' => 'Voir ma commande', 'cta_url' => '{order_url}', 'accent' => '#2563eb',
        ),
        'customer_failed_order' => array(
            'subject' => 'Paiement non abouti — commande #{order_number}', 'heading' => 'Paiement non abouti', 'preheader' => 'Nous n’avons pas pu finaliser votre paiement.',
            'badge' => 'Paiement échoué', 'intro_title' => 'Le paiement n’a pas abouti.', 'intro_text' => 'Bonjour {first_name}, nous n’avons pas pu finaliser votre commande avec le moyen de paiement utilisé. Vous pouvez réessayer en toute sécurité.',
            'cta_label' => 'Réessayer le paiement', 'cta_url' => '{payment_url}', 'accent' => '#dc2626',
        ),
        'customer_cancelled_order' => array(
            'subject' => 'Votre commande #{order_number} a été annulée', 'heading' => 'Commande annulée', 'preheader' => 'Votre commande a été annulée.',
            'badge' => 'Annulée', 'intro_title' => 'Commande annulée.', 'intro_text' => 'Bonjour {first_name}, votre commande #{order_number} a été annulée.',
            'cta_label' => 'Voir mes commandes', 'cta_url' => '{account_orders_url}', 'accent' => '#dc2626',
        ),
        'customer_new_account' => array(
            'subject' => 'Bienvenue chez {site_name} — votre compte est prêt', 'heading' => 'Bienvenue chez {site_name}', 'preheader' => 'Votre espace client est prêt. Sécurisez votre compte et commencez à l’utiliser.',
            'badge' => 'Compte créé', 'intro_title' => 'Bienvenue {first_name} 👋', 'intro_text' => 'Votre compte <strong>{site_name}</strong> a été créé avec succès. Vous pouvez maintenant suivre vos commandes, retrouver vos achats et gérer votre espace client en toute simplicité.',
            'cta_label' => '{account_primary_label}', 'cta_url' => '{account_primary_url}', 'accent' => '#16a34a',
        ),
        'customer_reset_password' => array(
            'subject' => 'Réinitialisez votre mot de passe — {site_name}', 'heading' => 'Réinitialisation du mot de passe', 'preheader' => 'Utilisez le lien sécurisé pour choisir un nouveau mot de passe.',
            'badge' => 'Sécurité', 'intro_title' => 'Réinitialisez votre mot de passe.', 'intro_text' => 'Bonjour {display_name}, une demande de réinitialisation a été faite pour votre compte <strong>{username}</strong>. Si vous n’êtes pas à l’origine de cette demande, ignorez simplement cet e-mail.',
            'cta_label' => 'Créer un nouveau mot de passe', 'cta_url' => '{reset_password_url}', 'accent' => '#7c3aed',
        ),
    );
}

function dipes_default_block_order() {
    return array( 'intro', 'notice', 'summary', 'progress', 'cta', 'order_details', 'order_meta', 'customer_details', 'additional', 'custom' );
}

/**
 * A block order as a list of known, unique block ids, with every missing
 * default block appended. Accepts the hidden field's comma list or a flat
 * list of strings; anything else yields the default order.
 */
function dipes_sanitize_block_order( $order ) {
    $allowed = dipes_default_block_order();
    if ( is_string( $order ) ) { $order = explode( ',', $order ); }
    if ( ! is_array( $order ) ) { return $allowed; }
    $clean = array();
    foreach ( $order as $block ) {
        if ( ! is_string( $block ) ) { continue; }
        $block = sanitize_key( $block );
        if ( in_array( $block, $allowed, true ) && ! in_array( $block, $clean, true ) ) { $clean[] = $block; }
    }
    foreach ( $allowed as $block ) { if ( ! in_array( $block, $clean, true ) ) { $clean[] = $block; } }
    return $clean;
}

function dipes_defaults() {
    $preset = dipes_presets()['glass_dark']['tokens'];
    $emails = array();
    foreach ( dipes_default_email_copy() as $id => $copy ) {
        $emails[ $id ] = array_merge(
            $copy,
            array(
                'enabled' => 'yes', 'show_badge' => 'yes', 'show_summary' => 'inherit', 'show_progress' => 'inherit', 'show_cta' => 'inherit',
                'show_order_details' => 'yes', 'show_customer_details' => 'yes', 'show_additional' => 'yes', 'custom_html' => '',
                'block_order' => dipes_default_block_order(),
            )
        );
    }

    return array_merge(
        array(
            'schema_version' => 8,
            'preset' => 'glass_dark',
            'appearance_mode' => 'auto', 'visual_effect' => 'glass_neomorph', 'effect_strength' => 78, 'shadow_depth' => 72, 'glass_highlight' => 42,
            'brand_name' => '', 'logo_url' => '', 'logo_width' => 90, 'logo_align' => 'left', 'header_layout' => 'banner',
            'container_width' => 620, 'radius' => 22, 'card_radius' => 16, 'button_radius' => 14, 'body_padding' => 30,
            'font' => 'system', 'heading_font' => 'system', 'body_size' => 15, 'heading_size' => 29, 'small_size' => 11,
            'show_status_colors' => 'yes', 'show_summary' => 'yes', 'show_progress' => 'yes', 'show_cta' => 'yes', 'show_item_images' => 'yes',
            'show_sku' => 'no', 'show_item_meta' => 'yes', 'product_links' => 'yes', 'image_size' => 58, 'dark_mode' => 'yes',
            'support_enabled' => 'yes', 'support_title' => 'Besoin d’aide ?', 'support_text' => 'Notre équipe est disponible pour vous aider avec vos commandes et vos recharges.',
            'support_url' => '', 'support_label' => 'Contacter le support', 'whatsapp' => '', 'support_email' => '',
            'footer_custom_text' => '', 'footer_note' => 'Merci d’avoir choisi {site_name}.',
            'social_facebook' => '', 'social_instagram' => '', 'social_tiktok' => '',
            'custom_css' => '',
            'emails' => $emails,
        ),
        $preset
    );
}

function dipes_settings() {
    static $cache = null;
    if ( null === $cache ) {
        $saved = get_option( DIPES_OPTION, null );
        if ( ! is_array( $saved ) ) {
            $legacy = get_option( DIPES_LEGACY_OPTION, array() );
            $saved = is_array( $legacy ) ? $legacy : array();
        }
        $cache = dipes_normalize_settings( $saved );
    }
    $settings = $cache;
    if ( ! empty( $GLOBALS['dipes_preview_overrides'] ) && is_array( $GLOBALS['dipes_preview_overrides'] ) ) {
        $settings = dipes_normalize_settings( array_replace_recursive( $settings, $GLOBALS['dipes_preview_overrides'] ) );
    }
    return apply_filters( 'dipes_settings', $settings );
}

function dipes_normalize_settings( $saved ) {
    $defaults = dipes_defaults();
    $saved = is_array( $saved ) ? $saved : array();
    $is_v2 = empty( $saved['schema_version'] ) || absint( $saved['schema_version'] ) < 3;

    if ( $is_v2 && $saved ) {
        // Convert the old child-theme v2 option shape before merging it into v3.
        $preset_map = array( 'aurora' => 'delicat', 'midnight' => 'midnight', 'clean' => 'clean', 'sunset' => 'sunset', 'emerald' => 'emerald', 'mono' => 'receipt', 'editorial' => 'clean', 'neo' => 'glass_light' );
        if ( isset( $saved['preset'] ) ) { $saved['preset'] = isset( $preset_map[ $saved['preset'] ] ) ? $preset_map[ $saved['preset'] ] : 'delicat'; }
        if ( isset( $saved['radius'] ) && ! is_numeric( $saved['radius'] ) ) {
            $radius_map = array( 'sharp' => 0, 'soft' => 18, 'round' => 28 );
            $saved['radius'] = isset( $radius_map[ $saved['radius'] ] ) ? $radius_map[ $saved['radius'] ] : 18;
            $saved['card_radius'] = max( 0, $saved['radius'] - 4 );
            $saved['button_radius'] = max( 0, $saved['radius'] - 6 );
        }
        if ( isset( $saved['font'] ) ) {
            $font_map = array( '' => 'system', 'system' => 'system', 'serif' => 'georgia', 'mono' => 'mono', 'rounded' => 'trebuchet' );
            $saved['font'] = isset( $font_map[ $saved['font'] ] ) ? $font_map[ $saved['font'] ] : 'system';
        }
        if ( isset( $saved['density'] ) ) { $saved['body_padding'] = 'compact' === $saved['density'] ? 24 : 34; }
        if ( isset( $saved['show_hero'] ) ) { $saved['show_summary'] = $saved['show_hero']; }
        if ( isset( $saved['show_support'] ) ) { $saved['support_enabled'] = $saved['show_support']; }
        if ( isset( $saved['status_colors'] ) ) { $saved['show_status_colors'] = $saved['status_colors']; }
        if ( isset( $saved['subtitle'] ) && $saved['subtitle'] && empty( $saved['footer_note'] ) ) { $saved['footer_note'] = $saved['subtitle']; }
    }


    $saved_schema = isset( $saved['schema_version'] ) ? absint( $saved['schema_version'] ) : 0;
    if ( $saved && $saved_schema < 5 ) {
        $old_account_defaults = array(
            'subject' => 'Bienvenue chez {site_name}',
            'heading' => 'Bienvenue chez {site_name}',
            'preheader' => 'Votre compte est prêt.',
            'badge' => 'Bienvenue',
            'intro_title' => 'Bienvenue {first_name} !',
            'intro_text' => 'Votre compte a été créé avec succès. Vous pouvez maintenant suivre vos commandes et gérer votre compte plus facilement.',
            'cta_label' => 'Accéder à mon compte',
            'cta_url' => '{account_url}',
        );
        $new_account_defaults = $defaults['emails']['customer_new_account'];
        if ( empty( $saved['emails']['customer_new_account'] ) || ! is_array( $saved['emails']['customer_new_account'] ) ) {
            $saved['emails']['customer_new_account'] = $new_account_defaults;
        } else {
            foreach ( $old_account_defaults as $key => $old_value ) {
                if ( ! array_key_exists( $key, $saved['emails']['customer_new_account'] ) || $saved['emails']['customer_new_account'][ $key ] === $old_value ) {
                    $saved['emails']['customer_new_account'][ $key ] = $new_account_defaults[ $key ];
                }
            }
        }
        $saved['schema_version'] = 5;
    }

    $saved_schema = isset( $saved['schema_version'] ) ? absint( $saved['schema_version'] ) : 0;
    if ( $saved && $saved_schema < 6 ) {
        $old_admin_defaults = array(
            'preheader' => 'Un nouveau compte vient d’être créé sur {site_name}.',
            'intro_text' => 'Un nouveau client vient de créer un compte sur <strong>{site_name}</strong>. Vérifiez ses informations ci-dessous.',
            'cta_label' => 'Voir le profil utilisateur',
            'cta_url' => '{edit_user_url}',
        );
        $new_admin_defaults = $defaults['emails']['wp_new_user_admin'];
        if ( empty( $saved['emails']['wp_new_user_admin'] ) || ! is_array( $saved['emails']['wp_new_user_admin'] ) ) {
            $saved['emails']['wp_new_user_admin'] = $new_admin_defaults;
        } else {
            foreach ( $old_admin_defaults as $key => $old_value ) {
                if ( ! array_key_exists( $key, $saved['emails']['wp_new_user_admin'] ) || $saved['emails']['wp_new_user_admin'][ $key ] === $old_value ) {
                    $saved['emails']['wp_new_user_admin'][ $key ] = $new_admin_defaults[ $key ];
                }
            }
        }
        $saved['schema_version'] = 6;
    }

    $saved_schema = isset( $saved['schema_version'] ) ? absint( $saved['schema_version'] ) : 0;
    if ( $saved && $saved_schema < 7 ) {
        // v6.9.1 privacy mode intentionally blanked all user information.
        // v6.9.2 restores only the two admin-requested identity fields (name
        // and e-mail) plus the protected WordPress profile URL. More sensitive
        // tokens such as username, phone, user ID, role and registration time
        // stay out of the admin message.
        $privacy_admin_defaults = array(
            'preheader' => 'Une nouvelle inscription a été enregistrée sur {site_name}.',
            'intro_text' => 'Un nouveau compte vient d’être créé sur <strong>{site_name}</strong>. Vous pouvez gérer les comptes depuis votre tableau de bord WordPress.',
            'cta_label' => 'Gérer les utilisateurs',
            'cta_url' => '{users_admin_url}',
        );
        $new_admin_defaults = $defaults['emails']['wp_new_user_admin'];
        if ( empty( $saved['emails']['wp_new_user_admin'] ) || ! is_array( $saved['emails']['wp_new_user_admin'] ) ) {
            $saved['emails']['wp_new_user_admin'] = $new_admin_defaults;
        } else {
            foreach ( $privacy_admin_defaults as $key => $old_value ) {
                if ( ! array_key_exists( $key, $saved['emails']['wp_new_user_admin'] ) || $saved['emails']['wp_new_user_admin'][ $key ] === $old_value ) {
                    $saved['emails']['wp_new_user_admin'][ $key ] = $new_admin_defaults[ $key ];
                }
            }
        }
        $saved['schema_version'] = 7;
    }

    $saved_schema = isset( $saved['schema_version'] ) ? absint( $saved['schema_version'] ) : 0;
    if ( $saved && $saved_schema < 8 ) {
        // v6.11: the invoice / payment-request button pays the order when it
        // needs payment ({payment_url} falls back to the order page otherwise).
        // Only the untouched previous defaults are replaced.
        if ( isset( $saved['emails']['customer_invoice'] ) && is_array( $saved['emails']['customer_invoice'] ) ) {
            $invoice = $saved['emails']['customer_invoice'];
            if ( isset( $invoice['cta_url'] ) && '{order_url}' === $invoice['cta_url'] ) {
                $saved['emails']['customer_invoice']['cta_url'] = $defaults['emails']['customer_invoice']['cta_url'];
                if ( isset( $invoice['cta_label'] ) && 'Voir ma commande' === $invoice['cta_label'] ) {
                    $saved['emails']['customer_invoice']['cta_label'] = $defaults['emails']['customer_invoice']['cta_label'];
                }
            }
        }
        $saved['schema_version'] = 8;
    }

    $out = array_replace_recursive( $defaults, $saved );
    if ( isset( $saved['accent'] ) && $saved['accent'] ) { $out['accent'] = $saved['accent']; $out['button_bg'] = $saved['accent']; }

    // array_replace_recursive() merges lists by index: a stored block order
    // shorter than the current one would keep the default tail (duplicated
    // and missing blocks). Re-validate every stored order instead, and never
    // let a corrupt (non-array) e-mail entry replace its defaults.
    if ( ! isset( $out['emails'] ) || ! is_array( $out['emails'] ) ) { $out['emails'] = $defaults['emails']; }
    foreach ( $out['emails'] as $id => $email ) {
        if ( ! is_array( $email ) ) {
            if ( isset( $defaults['emails'][ $id ] ) ) { $out['emails'][ $id ] = $defaults['emails'][ $id ]; } else { unset( $out['emails'][ $id ] ); }
            continue;
        }
        if ( isset( $saved['emails'][ $id ]['block_order'] ) ) {
            $out['emails'][ $id ]['block_order'] = dipes_sanitize_block_order( $saved['emails'][ $id ]['block_order'] );
        }
    }
    return $out;
}

function dipes_get( $key, $default = '' ) {
    $s = dipes_settings();
    return array_key_exists( $key, $s ) ? $s[ $key ] : $default;
}

function dipes_email_setting( $email_id, $key, $default = '' ) {
    if ( ! empty( $GLOBALS['dipes_runtime_email_overrides'][ $email_id ] ) && is_array( $GLOBALS['dipes_runtime_email_overrides'][ $email_id ] ) && array_key_exists( $key, $GLOBALS['dipes_runtime_email_overrides'][ $email_id ] ) ) {
        return $GLOBALS['dipes_runtime_email_overrides'][ $email_id ][ $key ];
    }
    $s = dipes_settings();
    if ( isset( $s['emails'][ $email_id ] ) && array_key_exists( $key, $s['emails'][ $email_id ] ) ) {
        return $s['emails'][ $email_id ][ $key ];
    }
    return $default;
}

function dipes_sanitize_color( $value, $fallback ) {
    $color = is_string( $value ) ? sanitize_hex_color( $value ) : '';
    return $color ? $color : $fallback;
}

function dipes_sanitize_html( $value ) {
    return is_scalar( $value ) ? wp_kses_post( (string) $value ) : '';
}

/** Constructs custom e-mail CSS may never contain (remote stylesheets, script-capable CSS). */
function dipes_css_forbidden_patterns() {
    return array(
        '/@import\b[^;]*;?/i',
        '/expression\s*\([^)]*\)?/i',
        '/(?:javascript|vbscript)\s*:/i',
        '/(?:behavior|-moz-binding)\s*:[^;}]*;?/i',
    );
}

/** Remove the forbidden constructs until the string stops changing (nested tokens cannot rebuild them). */
function dipes_css_strip_forbidden( $css ) {
    $patterns = dipes_css_forbidden_patterns();
    for ( $i = 0; $i < 20; $i++ ) {
        $before = $css;
        $css = (string) preg_replace( $patterns, '', $css );
        if ( $css === $before ) { break; }
    }
    return $css;
}

/** What a CSS parser reads: comments dropped and backslash escapes decoded (\69mport => import). */
function dipes_css_decoded_probe( $css ) {
    $css = (string) preg_replace( '~/\*.*?(?:\*/|$)~s', '', $css );
    return (string) preg_replace_callback(
        '/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/s',
        static function ( $m ) {
            if ( isset( $m[1] ) && '' !== $m[1] ) {
                $cp = hexdec( $m[1] );
                return ( $cp > 0 && $cp < 128 ) ? chr( $cp ) : '~';
            }
            return isset( $m[2] ) ? $m[2] : '';
        },
        $css
    );
}

function dipes_css_has_forbidden( $css ) {
    foreach ( dipes_css_forbidden_patterns() as $pattern ) {
        if ( preg_match( $pattern, $css ) ) { return true; }
    }
    return false;
}

function dipes_sanitize_css( $value ) {
    // Callers (options.php, the preview handler) already pass unslashed data:
    // escapes such as content:"\201C" or .w-1\/2 must survive.
    if ( ! is_string( $value ) ) { return ''; }

    // Custom CSS lands inside a <style> block of every e-mail and of the admin
    // preview. CSS never needs '<' (child combinators use '>'), so dropping it
    // makes a </style> breakout or any tag impossible, however it is nested.
    $value = str_replace( array( '<', "\0" ), '', $value );
    $value = dipes_css_strip_forbidden( $value );

    // Escapes or comments can still spell a forbidden construct for the CSS
    // parser (@\69mport, javas\63ript:). Only then drop them and strip again.
    if ( dipes_css_has_forbidden( dipes_css_decoded_probe( $value ) ) ) {
        $value = (string) preg_replace( '~/\*.*?(?:\*/|$)~s', '', $value );
        $value = (string) preg_replace( '/\\\\(?:[0-9a-fA-F]{1,6}\s?|.)?/s', '', $value );
        $value = dipes_css_strip_forbidden( $value );
        if ( dipes_css_has_forbidden( dipes_css_decoded_probe( $value ) ) ) { return ''; }
    }

    return trim( $value );
}

/** A scalar request/setting value as a string, or null for anything else (arrays, objects). */
function dipes_scalar_input( $input, $key ) {
    return is_array( $input ) && isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : null;
}

function dipes_sanitize_settings( $input ) {
    $defaults = dipes_defaults();
    $input = is_array( $input ) ? $input : array();
    $out = $defaults;

    // Every scalar setting is read through dipes_scalar_input(): an array
    // posted for a scalar field (malformed form, preview URL) falls back to
    // the default instead of reaching esc_url_raw()/preg_* as an array.
    $presets = dipes_presets();
    $preset = dipes_scalar_input( $input, 'preset' );
    $out['preset'] = null !== $preset && isset( $presets[ $preset ] ) ? sanitize_key( $preset ) : $defaults['preset'];

    $text_keys = array( 'brand_name','support_title','support_text','support_label','whatsapp','support_email','footer_note','social_facebook','social_instagram','social_tiktok' );
    foreach ( $text_keys as $key ) {
        $v = dipes_scalar_input( $input, $key );
        $out[ $key ] = null !== $v ? sanitize_text_field( $v ) : $defaults[ $key ];
    }
    foreach ( array( 'logo_url', 'support_url', 'social_facebook', 'social_instagram', 'social_tiktok' ) as $url_key ) {
        $v = dipes_scalar_input( $input, $url_key );
        $out[ $url_key ] = null !== $v ? esc_url_raw( $v ) : '';
    }
    $v = dipes_scalar_input( $input, 'footer_custom_text' );
    $out['footer_custom_text'] = null !== $v ? dipes_sanitize_html( $v ) : '';
    $v = dipes_scalar_input( $input, 'support_email' );
    $out['support_email'] = null !== $v ? sanitize_email( $v ) : '';
    $v = dipes_scalar_input( $input, 'whatsapp' );
    $out['whatsapp'] = null !== $v ? preg_replace( '/[^0-9+]/', '', $v ) : '';

    $color_keys = array( 'page_bg','container_bg','header_bg','header_text','text','muted','heading','border','accent','button_bg','button_text','footer_bg','footer_text_color','table_header_bg','table_header_text','card_bg','card_border' );
    foreach ( $color_keys as $key ) {
        $out[ $key ] = dipes_sanitize_color( dipes_scalar_input( $input, $key ), $defaults[ $key ] );
    }

    $number_bounds = array(
        'logo_width' => array( 40, 90 ), 'container_width' => array( 480, 760 ), 'radius' => array( 0, 32 ), 'card_radius' => array( 0, 32 ),
        'button_radius' => array( 0, 32 ), 'body_padding' => array( 16, 56 ), 'body_size' => array( 12, 20 ), 'heading_size' => array( 20, 44 ),
        'small_size' => array( 10, 16 ), 'image_size' => array( 36, 96 ), 'effect_strength' => array( 0, 100 ), 'shadow_depth' => array( 0, 100 ), 'glass_highlight' => array( 0, 100 ),
    );
    foreach ( $number_bounds as $key => $bounds ) {
        $v = dipes_scalar_input( $input, $key );
        $v = null !== $v ? absint( $v ) : $defaults[ $key ];
        $out[ $key ] = max( $bounds[0], min( $bounds[1], $v ) );
    }

    $selects = array(
        'logo_align' => array( 'left','center','right' ), 'header_layout' => array( 'banner','centered','minimal' ),
        'font' => array( 'system','arial','georgia','trebuchet','verdana','mono' ), 'heading_font' => array( 'system','arial','georgia','trebuchet','verdana','mono' ),
        'appearance_mode' => array( 'auto','light','dark' ), 'visual_effect' => array( 'glass_neomorph','glass','neomorph','flat' ),
    );
    foreach ( $selects as $key => $allowed ) {
        $out[ $key ] = isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ? $input[ $key ] : $defaults[ $key ];
    }

    $toggles = array( 'show_status_colors','show_summary','show_progress','show_cta','show_item_images','show_sku','show_item_meta','product_links','dark_mode','support_enabled' );
    foreach ( $toggles as $key ) {
        $out[ $key ] = isset( $input[ $key ] ) && 'yes' === $input[ $key ] ? 'yes' : 'no';
    }
    $out['custom_css'] = dipes_sanitize_css( dipes_scalar_input( $input, 'custom_css' ) );

    $email_defaults = dipes_default_email_copy();
    $allowed_blocks = dipes_default_block_order();
    foreach ( dipes_email_types() as $id => $label ) {
        $raw = isset( $input['emails'][ $id ] ) && is_array( $input['emails'][ $id ] ) ? $input['emails'][ $id ] : array();
        $base = isset( $defaults['emails'][ $id ] ) ? $defaults['emails'][ $id ] : array();
        $email = $base;

        $email['enabled'] = isset( $raw['enabled'] ) && 'yes' === $raw['enabled'] ? 'yes' : 'no';
        foreach ( array( 'subject','heading','preheader','badge','intro_title','cta_label','cta_url' ) as $key ) {
            $v = dipes_scalar_input( $raw, $key );
            $email[ $key ] = null !== $v ? sanitize_text_field( $v ) : $base[ $key ];
        }
        $v = dipes_scalar_input( $raw, 'intro_text' );
        $email['intro_text'] = null !== $v ? dipes_sanitize_html( $v ) : $base['intro_text'];
        $v = dipes_scalar_input( $raw, 'custom_html' );
        $email['custom_html'] = null !== $v ? dipes_sanitize_html( $v ) : '';
        $v = dipes_scalar_input( $raw, 'accent' );
        $email['accent'] = $v ? dipes_sanitize_color( $v, $base['accent'] ) : $base['accent'];
        foreach ( array( 'show_badge','show_order_details','show_customer_details','show_additional' ) as $key ) {
            $email[ $key ] = isset( $raw[ $key ] ) && 'yes' === $raw[ $key ] ? 'yes' : 'no';
        }
        foreach ( array( 'show_summary','show_progress','show_cta' ) as $key ) {
            $email[ $key ] = isset( $raw[ $key ] ) && in_array( $raw[ $key ], array( 'inherit','yes','no' ), true ) ? $raw[ $key ] : 'inherit';
        }
        $email['block_order'] = dipes_sanitize_block_order( isset( $raw['block_order'] ) ? $raw['block_order'] : $allowed_blocks );
        $out['emails'][ $id ] = $email;
    }

    $out['schema_version'] = 8;
    return apply_filters( 'dipes_sanitized_settings', $out, $input );
}

add_action( 'admin_init', static function () {
    register_setting( 'dipes_settings_group', DIPES_OPTION, array( 'type' => 'array', 'sanitize_callback' => 'dipes_sanitize_settings', 'default' => dipes_defaults() ) );
} );
