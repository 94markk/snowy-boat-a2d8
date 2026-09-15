<?php
defined( 'ABSPATH' ) || exit;

function dipes_presets() {
    return apply_filters(
        'dipes_presets',
        array(
            'glass_dark' => array(
                'label' => 'Delicat Glass Dark',
                'desc'  => 'Version sombre premium avec profondeur néomorphique, verre doux et rendu proche du markup Delicat.',
                'swatch' => array( '#050a12', '#152238', '#ff6847', '#ffffff' ),
                'tokens' => array(
                    'page_bg' => '#050a12', 'container_bg' => '#0f1929', 'header_bg' => '#0b1422', 'header_text' => '#ffffff',
                    'text' => '#cbd5e1', 'muted' => '#8290a6', 'heading' => '#ffffff', 'border' => '#24324a',
                    'accent' => '#ff6847', 'accent_text' => '#ffffff', 'accent_soft' => '#2c1c1a',
                    'button_bg' => '#ff6847', 'button_text' => '#ffffff', 'footer_bg' => '#09111f', 'footer_text_color' => '#8e9bb1',
                    'table_header_bg' => '#19263b', 'table_header_text' => '#dce5f2', 'card_bg' => '#152238', 'card_border' => '#2a3952',
                ),
            ),
            'glass_light' => array(
                'label' => 'Delicat Glass Light',
                'desc'  => 'Version claire premium avec néomorphisme doux, cartes lumineuses et très bonne lisibilité.',
                'swatch' => array( '#eef3fa', '#ffffff', '#ff6847', '#0f172a' ),
                'tokens' => array(
                    'page_bg' => '#eef3fa', 'container_bg' => '#f7f9fd', 'header_bg' => '#ffffff', 'header_text' => '#101828',
                    'text' => '#465466', 'muted' => '#7b8798', 'heading' => '#0f172a', 'border' => '#dbe5f0',
                    'accent' => '#ff6847', 'accent_text' => '#ffffff', 'accent_soft' => '#fff1eb',
                    'button_bg' => '#ff6847', 'button_text' => '#ffffff', 'footer_bg' => '#f3f6fb', 'footer_text_color' => '#6b7c93',
                    'table_header_bg' => '#edf2f8', 'table_header_text' => '#334155', 'card_bg' => '#ffffff', 'card_border' => '#dbe5f0',
                ),
            ),
            'nova' => array(
                'label' => 'Delicat Nova Dark',
                'desc'  => 'Premium sombre, compact, mobile-first et optimisé pour les commandes numériques.',
                'swatch' => array( '#07101d', '#152238', '#ff6847', '#ffffff' ),
                'tokens' => array(
                    'page_bg' => '#050a12', 'container_bg' => '#0f1929', 'header_bg' => '#0b1422', 'header_text' => '#ffffff',
                    'text' => '#cbd5e1', 'muted' => '#8290a6', 'heading' => '#ffffff', 'border' => '#24324a',
                    'accent' => '#ff6847', 'accent_text' => '#ffffff', 'accent_soft' => '#2c1c1a',
                    'button_bg' => '#ff6847', 'button_text' => '#ffffff', 'footer_bg' => '#09111f', 'footer_text_color' => '#8e9bb1',
                    'table_header_bg' => '#19263b', 'table_header_text' => '#dce5f2', 'card_bg' => '#152238', 'card_border' => '#2a3952',
                ),
            ),
            'delicat' => array(
                'label' => 'Delicat Signature',
                'desc'  => 'Élégant, premium, clair et orienté conversion.',
                'swatch' => array( '#0b1220', '#ff6b3d', '#ffffff', '#f4f7fb' ),
                'tokens' => array(
                    'page_bg' => '#eef2f7', 'container_bg' => '#ffffff', 'header_bg' => '#0b1220', 'header_text' => '#ffffff',
                    'text' => '#273244', 'muted' => '#718096', 'heading' => '#0f172a', 'border' => '#e5eaf0',
                    'accent' => '#ff6435', 'accent_text' => '#ffffff', 'accent_soft' => '#fff0ea',
                    'button_bg' => '#ff6435', 'button_text' => '#ffffff', 'footer_bg' => '#f8fafc', 'footer_text_color' => '#64748b',
                    'table_header_bg' => '#f8fafc', 'table_header_text' => '#334155', 'card_bg' => '#f8fafc', 'card_border' => '#e5eaf0',
                ),
            ),
            'clean' => array(
                'label' => 'Clean Commerce',
                'desc'  => 'Très lisible, minimal et professionnel.',
                'swatch' => array( '#ffffff', '#111827', '#2563eb', '#f8fafc' ),
                'tokens' => array(
                    'page_bg' => '#f3f4f6', 'container_bg' => '#ffffff', 'header_bg' => '#ffffff', 'header_text' => '#111827',
                    'text' => '#374151', 'muted' => '#6b7280', 'heading' => '#111827', 'border' => '#e5e7eb',
                    'accent' => '#2563eb', 'accent_text' => '#ffffff', 'accent_soft' => '#eff6ff',
                    'button_bg' => '#111827', 'button_text' => '#ffffff', 'footer_bg' => '#ffffff', 'footer_text_color' => '#6b7280',
                    'table_header_bg' => '#f9fafb', 'table_header_text' => '#374151', 'card_bg' => '#f9fafb', 'card_border' => '#e5e7eb',
                ),
            ),
            'midnight' => array(
                'label' => 'Midnight',
                'desc'  => 'Sombre, moderne, premium.',
                'swatch' => array( '#090d16', '#151c2c', '#8b5cf6', '#f8fafc' ),
                'tokens' => array(
                    'page_bg' => '#070b12', 'container_bg' => '#111827', 'header_bg' => '#0b1020', 'header_text' => '#f8fafc',
                    'text' => '#d6deea', 'muted' => '#94a3b8', 'heading' => '#ffffff', 'border' => '#263244',
                    'accent' => '#8b5cf6', 'accent_text' => '#ffffff', 'accent_soft' => '#231b3c',
                    'button_bg' => '#8b5cf6', 'button_text' => '#ffffff', 'footer_bg' => '#0b1020', 'footer_text_color' => '#94a3b8',
                    'table_header_bg' => '#172033', 'table_header_text' => '#e2e8f0', 'card_bg' => '#172033', 'card_border' => '#29354a',
                ),
            ),
            'emerald' => array(
                'label' => 'Emerald',
                'desc'  => 'Business, confiance et paiement sécurisé.',
                'swatch' => array( '#063c35', '#0f766e', '#ffffff', '#ecfdf5' ),
                'tokens' => array(
                    'page_bg' => '#edf7f4', 'container_bg' => '#ffffff', 'header_bg' => '#063c35', 'header_text' => '#ffffff',
                    'text' => '#29423e', 'muted' => '#6b7e7a', 'heading' => '#123d37', 'border' => '#dcebe7',
                    'accent' => '#0f766e', 'accent_text' => '#ffffff', 'accent_soft' => '#e7f8f4',
                    'button_bg' => '#0f766e', 'button_text' => '#ffffff', 'footer_bg' => '#f3faf8', 'footer_text_color' => '#60756f',
                    'table_header_bg' => '#f3faf8', 'table_header_text' => '#254c45', 'card_bg' => '#f3faf8', 'card_border' => '#dcebe7',
                ),
            ),
            'sunset' => array(
                'label' => 'Sunset',
                'desc'  => 'Vif, chaleureux et très visuel.',
                'swatch' => array( '#ff5f56', '#ff2d7d', '#ffffff', '#fff5f6' ),
                'tokens' => array(
                    'page_bg' => '#fff4f5', 'container_bg' => '#ffffff', 'header_bg' => '#ff5f56', 'header_text' => '#ffffff',
                    'text' => '#4b2b31', 'muted' => '#8b6670', 'heading' => '#32171d', 'border' => '#f4d9df',
                    'accent' => '#ff2d7d', 'accent_text' => '#ffffff', 'accent_soft' => '#fff0f6',
                    'button_bg' => '#ff2d7d', 'button_text' => '#ffffff', 'footer_bg' => '#fff7f8', 'footer_text_color' => '#8b6670',
                    'table_header_bg' => '#fff7f8', 'table_header_text' => '#603b45', 'card_bg' => '#fff7f8', 'card_border' => '#f4d9df',
                ),
            ),
            'receipt' => array(
                'label' => 'Receipt',
                'desc'  => 'Reçu simple, dense et très rapide à lire.',
                'swatch' => array( '#f5f5f4', '#1c1917', '#78716c', '#ffffff' ),
                'tokens' => array(
                    'page_bg' => '#f5f5f4', 'container_bg' => '#ffffff', 'header_bg' => '#ffffff', 'header_text' => '#1c1917',
                    'text' => '#44403c', 'muted' => '#78716c', 'heading' => '#1c1917', 'border' => '#d6d3d1',
                    'accent' => '#57534e', 'accent_text' => '#ffffff', 'accent_soft' => '#f5f5f4',
                    'button_bg' => '#1c1917', 'button_text' => '#ffffff', 'footer_bg' => '#fafaf9', 'footer_text_color' => '#78716c',
                    'table_header_bg' => '#fafaf9', 'table_header_text' => '#44403c', 'card_bg' => '#fafaf9', 'card_border' => '#d6d3d1',
                ),
            ),
        )
    );
}
