<?php
/**
 * Module: Délicat Direct Variation Swatches for Store Builder
 * Plugin URI: https://delicastoreha.com
 * Description: Fast direct-variation swatches for WooCommerce with isolated layouts, single-pass selection, responsive cards, and native WooCommerce pricing/stock behavior.
 * Version: 9.0.0-rc.51.26
 * Author: Délicat Store
 * Author URI: https://delicastoreha.com
 * Text Domain: delicat-swatches
 * Requires at least: 6.0
 * Requires PHP: 8.3
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Delicat_Builder_V9_Swatches {
    const VERSION = '9.0.0-rc.51.44';
    const OPTION  = 'delicat_direct_swatches_settings';

    private static $instance = null;
    private $settings = null;
    private $booted = false;
    private $admin_hooks_registered = false;

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        if ( did_action( 'before_woocommerce_init' ) ) {
            $this->declare_compatibility();
        } else {
            add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
        }

        /*
         * RC51.14: Product-editor controls must never depend on the exact
         * WooCommerce/plugin load order. Register those hooks immediately;
         * they are harmless until WooCommerce fires them. This fixes installs
         * where the Swatch Studio selector disappeared from Product Data.
         * RC51.14 adds a dedicated tab that does not depend on General.
         */
        $this->register_admin_hooks();

        if ( did_action( 'plugins_loaded' ) ) {
            $this->boot();
        } else {
            add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
        }

        // Defensive retries for late-loaded/managed WooCommerce environments.
        add_action( 'woocommerce_loaded', array( $this, 'boot' ), 5 );
        add_action( 'init', array( $this, 'boot' ), 20 );
    }

    public function declare_compatibility() {
        if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }

    private function register_admin_hooks() {
        if ( $this->admin_hooks_registered ) { return; }
        $this->admin_hooks_registered = true;

        /*
         * RC54: priority 30, and it must not go back to the default.
         *
         * This hook is registered from the bootstrap's badge bridge at FILE
         * LOAD time — earlier than every other studio, which register during
         * plugins_loaded. At the default priority 10 that made this callback
         * run *before* Delicat_Builder_V9_Admin::menu(), so `add_menu_page`
         * had not yet populated $admin_page_hooks['delicat-builder-v9'].
         *
         * get_plugin_page_hookname() then falls back to the generic 'admin'
         * page type and registers `admin_page_delicat-direct-swatches`, while
         * every later lookup — the menu URL builder and admin.php itself —
         * resolves the parent correctly and asks for
         * `delicat-builder_page_delicat-direct-swatches`. has_action() fails on
         * that, so _wp_menu_output() drops the admin.php prefix and links to a
         * bare /wp-admin/delicat-direct-swatches, which is a 404, and
         * $screen->id is wrong so the studio's CSS and JS never enqueue.
         *
         * RC52 introduced this by moving the parent from `woocommerce`, whose
         * top-level menu WooCommerce registers at priority 9 — always in time.
         */
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 30 );
        add_action( 'admin_init', array( $this, 'save_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

        // Product-level preset selector. RC51.14 uses its own Product Data tab
        // because recent/custom WooCommerce editors can omit the General tab for
        // variable products. A JS mirror is still added to General when available.
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_data_tabs' ), 99 );
        add_action( 'woocommerce_product_data_panels', array( $this, 'product_data_panel' ), 20 );
        add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_preset' ), 20 );
        // Fallback persistence for stores where a third-party editor bypasses
        // woocommerce_admin_process_product_object but still uses Woo meta save.
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_preset_meta' ), 99 );

        // Variation-level badges / features.
        add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_delivery_fields' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_delivery_fields' ), 10, 2 );
    }

    public function boot() {
        $this->register_admin_hooks();
        if ( $this->booted ) { return; }
        if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) { return; }

        $this->booted = true;
        add_action( 'woocommerce_before_variations_form', array( $this, 'render' ), 8 );
        add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
    }

    private function defaults() {
        return array(
            'enabled'            => 'yes',
            'hide_native'        => 'yes',
            'show_reset'         => 'yes',
            'show_stock'         => 'yes',
            'show_recommended'   => 'yes',
            'auto_select_recommended' => 'no',
            'recommended_label'  => 'Recommandé',
            'recommended_bg'     => '#fff7d6',
            'recommended_color'  => '#8a5a00',
            'out_stock_popup'    => 'yes',
            'out_stock_title'    => 'Rupture de stock',
            'out_stock_message'  => 'Ce produit est en rupture de stock, mais vous pouvez nous contacter pour passer votre commande.',
            'out_stock_button'   => 'Commander sur WhatsApp',
            'out_stock_phone'    => '+50933111283',
            'out_stock_whatsapp_message' => 'Bonjour, je souhaite commander {product} – {variation}, actuellement en rupture de stock.',
            'out_stock_accent'   => '#25D366',
            'columns_desktop'    => 2,
            'columns_tablet'     => 2,
            'columns_mobile'     => 2,
            'panel_padding'      => 16,
            'panel_radius'       => 18,
            'panel_gap'          => 14,
            'column_gap'         => 10,
            'row_gap'            => 10,
            'card_min_height'    => 82,
            'card_padding'       => 12,
            'card_radius'        => 15,
            'title_price_gap'    => 5,
            'title_size'         => 14,
            'price_size'         => 13,
            'delivery_size'      => 10,
            'delivery_radius'    => 999,
            'delivery_padding_x' => 8,
            'delivery_padding_y' => 4,
            'alignment'          => 'center',
            'effect'             => 'soft',
            'panel_from'         => '#667eea',
            'panel_to'           => '#e36b38',
            'selected_from'      => '#284696',
            'selected_to'        => '#071548',
            'section_labels'     => "pa_recharge|RECHARGER\npa_autres-produits|AUTRES PRODUITS\npa_region|RÉGION DU COMPTE",
            'none_terms'         => 'aucun,none,skip,n-a,na',
            'attribute_priority' => 'pa_recharge,pa_autres-produits,pa_region',
            'show_filterbar'     => 'no',
            'filter_attributes'  => 'pa_region,pa_serveur,pa_server,pa_reseau,pa_network,pa_plateforme',

            // Delicat ABONNEMENT premium — product-selectable preset.
            'subscription_heading'            => 'Choisissez votre plan',
            'subscription_subtitle'           => 'Sélectionnez la durée qui vous convient.',
            'subscription_show_features'      => 'yes',
            'subscription_show_cta'           => 'yes',
            'subscription_show_discount'      => 'yes',
            'subscription_show_pager'         => 'yes',
            'subscription_show_trust'         => 'yes',
            'subscription_equal_height'       => 'yes',
            'subscription_cta_label'           => 'Choisir ce plan',
            'subscription_selected_label'      => 'Plan sélectionné',
            'subscription_trust_1'             => 'Paiement 100% sécurisé',
            'subscription_trust_2'             => 'Livraison rapide',
            'subscription_trust_3'             => 'Support 24/7',
            'subscription_columns_desktop'     => 3,
            'subscription_columns_tablet'      => 2,
            // Fractional mobile viewport: 1 / 1.5 / 2 / 2.5 / 3 visible cards.
            'subscription_columns_mobile'      => '1.5',
            'subscription_card_min_width'      => 220,
            'subscription_card_min_height'     => 286,
            'subscription_card_gap'            => 16,
            'subscription_mobile_peek'         => 28,
            'subscription_heading_size'        => 28,
            'subscription_title_size'          => 24,
            'subscription_price_size'          => 26,
            'subscription_plan_alignment'      => 'center',
            'subscription_cta_height'          => 48,
            'subscription_cta_radius'          => 14,
            'subscription_accent'              => '#ff1744',
            'subscription_accent_2'            => '#ff334f',
            'subscription_navy'                => '#07142e',
            'subscription_gold'                => '#f5b82e',
            'subscription_card_bg'             => '#ffffff',
            'subscription_text'                => '#0f172a',
            'subscription_muted'               => '#64748b',
            'subscription_border'              => '#e4e7ef',
            'subscription_section_bg'          => '#f7f8fc',
            'subscription_motion'              => 'smooth',
        );
    }

    private function settings() {
        if ( null === $this->settings ) {
            $saved = get_option( self::OPTION, array() );
            $this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
            // RC51.7: the old attribute-based region/server filter tabs were replaced
            // by the Product / Service / Region Switcher that links real products.
            $this->settings['show_filterbar'] = 'no';
        }
        return $this->settings;
    }

    private function get( $key ) {
        $s = $this->settings();
        return isset( $s[ $key ] ) ? $s[ $key ] : null;
    }

    public function action_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=delicat-direct-swatches' ) ) . '">' . esc_html__( 'Settings', 'delicat-swatches' ) . '</a>' );
        return $links;
    }

    /*
     * RC52: this page used to live under the WooCommerce menu, registered by a
     * class that Unified Modules only instantiates on Builder admin requests.
     * The entry therefore appeared under WooCommerce only while you were on a
     * *Builder* screen, and nowhere at all when browsing WooCommerce itself —
     * so the one place a merchant would look for it never showed it.
     *
     * It now registers once, under the Builder menu, with the other studios.
     * A single registration also keeps the screen id deterministic: two parents
     * would make `get_admin_page_parent()` pick whichever submenu array was
     * built first, and the asset loader below matches on screen id.
     */
    public function admin_menu() {
        add_submenu_page( 'delicat-builder-v9', 'Swatch Studio', 'Swatch Studio', 'manage_woocommerce', 'delicat-direct-swatches', array( $this, 'settings_page' ) );
    }

    public function admin_assets( $hook ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        /*
         * RC52: the screen id is derived from the parent menu, so moving this
         * page into the Builder menu changed it from
         * `woocommerce_page_delicat-direct-swatches` to
         * `delicat-builder_page_delicat-direct-swatches`. Matching only the old
         * id would have left the studio rendering with no stylesheet and no
         * script — a page that opens and cannot be used. Both ids are accepted
         * so the loader survives any future re-parenting too.
         */
        $studio_screens = array(
            'delicat-builder_page_delicat-direct-swatches',
            'woocommerce_page_delicat-direct-swatches',
        );
        $is_studio  = $screen && in_array( $screen->id, $studio_screens, true );
        $is_product = $screen && 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'post-new' ), true );
        if ( ! $is_studio && ! $is_product ) { return; }

        $base_url = defined( 'DELICAT_BUILDER_V9_URL' ) ? DELICAT_BUILDER_V9_URL : plugin_dir_url( dirname( __FILE__ ) );
        $base_dir = defined( 'DELICAT_BUILDER_V9_DIR' ) ? DELICAT_BUILDER_V9_DIR : trailingslashit( dirname( __DIR__ ) );

        if ( $is_studio ) {
            $css = 'assets/css/swatch-studio-admin.css';
            $js  = 'assets/js/swatch-studio-admin.js';
            $css_ver = is_readable( $base_dir . $css ) ? (string) filemtime( $base_dir . $css ) : self::VERSION;
            $js_ver  = is_readable( $base_dir . $js ) ? (string) filemtime( $base_dir . $js ) : self::VERSION;
            wp_enqueue_style( 'delicat-swatch-studio-admin', $base_url . $css, array(), $css_ver );
            wp_enqueue_script( 'delicat-swatch-studio-admin', $base_url . $js, array(), $js_ver, true );
        }

        if ( $is_product ) {
            $css = 'assets/css/swatch-product-admin.css';
            $js  = 'assets/js/swatch-product-admin.js';
            $css_ver = is_readable( $base_dir . $css ) ? (string) filemtime( $base_dir . $css ) : self::VERSION;
            $js_ver  = is_readable( $base_dir . $js ) ? (string) filemtime( $base_dir . $js ) : self::VERSION;
            wp_enqueue_style( 'delicat-swatch-product-admin', $base_url . $css, array(), $css_ver );
            wp_enqueue_script( 'delicat-swatch-product-admin', $base_url . $js, array(), $js_ver, true );

            $product_id = 0;
            if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only editor context.
                $product_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            } elseif ( isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post ) {
                $product_id = absint( $GLOBALS['post']->ID );
            }
            wp_localize_script( 'delicat-swatch-product-admin', 'DelicatSwatchProductAdmin', array(
                'fieldId'     => '_ddsw_product_preset',
                'label'       => 'Swatch Studio',
                'description' => 'Choisissez le style de variations pour ce produit.',
                'value'       => $product_id ? $this->product_preset( $product_id ) : 'default',
                'options'     => $this->product_presets(),
            ) );
        }
    }

    private function product_presets() {
        return array(
            'default'                      => 'Style global',
            'delicat_abonnement_premium'   => 'Delicat ABONNEMENT premium',
        );
    }

    private function product_preset( $product_id ) {
        $preset = sanitize_key( (string) get_post_meta( absint( $product_id ), '_ddsw_product_preset', true ) );
        return array_key_exists( $preset, $this->product_presets() ) ? $preset : 'default';
    }

    public function product_data_tabs( $tabs ) {
        if ( ! is_array( $tabs ) ) { $tabs = array(); }

        $tabs['delicat_swatches'] = array(
            'label'    => 'Swatch Studio',
            'target'   => 'delicat_swatches_product_data',
            'class'    => array( 'show_if_variable', 'delicat_swatches_tab' ),
            'priority' => 58,
        );

        return $tabs;
    }

    public function product_data_panel() {
        $product_id = 0;
        if ( isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post && 'product' === $GLOBALS['post']->post_type ) {
            $product_id = absint( $GLOBALS['post']->ID );
        } elseif ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only editor context.
            $product_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        echo '<div id="delicat_swatches_product_data" class="panel woocommerce_options_panel hidden">';
        echo '<div class="options_group delicat-swatch-product-settings">';
        echo '<div class="delicat-swatch-product-heading">';
        echo '<strong>Swatch Studio</strong>';
        echo '<span>Style des variations pour ce produit</span>';
        echo '</div>';

        if ( function_exists( 'woocommerce_wp_select' ) ) {
            woocommerce_wp_select( array(
                'id'            => '_ddsw_product_preset',
                'label'         => 'Preset du produit',
                'value'         => $product_id ? $this->product_preset( $product_id ) : 'default',
                'options'       => $this->product_presets(),
                'wrapper_class' => 'show_if_variable delicat-swatch-preset-field',
                'desc_tip'      => true,
                'description'   => 'Choisissez le style de variations uniquement pour ce produit. Delicat ABONNEMENT premium est conçu pour Netflix, streaming et autres abonnements.',
            ) );
        } else {
            $value = $product_id ? $this->product_preset( $product_id ) : 'default';
            echo '<p class="form-field delicat-swatch-preset-field show_if_variable">';
            echo '<label for="_ddsw_product_preset">Preset du produit</label>';
            echo '<select id="_ddsw_product_preset" name="_ddsw_product_preset" class="select short">';
            foreach ( $this->product_presets() as $key => $label ) {
                echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select>';
            echo '</p>';
        }

        echo '<div class="delicat-swatch-product-help">';
        echo '<div><b>Style global</b><span>Utilise le style Swatch Studio par défaut.</span></div>';
        echo '<div><b>Delicat ABONNEMENT premium</b><span>Cartes abonnement premium, badges, caractéristiques, prix et sélection native WooCommerce.</span></div>';
        echo '<a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=delicat-direct-swatches' ) ) . '">Ouvrir Swatch Studio</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    public function product_preset_field() {
        if ( ! function_exists( 'woocommerce_wp_select' ) ) { return; }

        $product_id = 0;
        if ( isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post && 'product' === $GLOBALS['post']->post_type ) {
            $product_id = absint( $GLOBALS['post']->ID );
        } elseif ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only editor context.
            $product_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        woocommerce_wp_select( array(
            'id'            => '_ddsw_product_preset',
            'label'         => 'Swatch Studio',
            'value'         => $product_id ? $this->product_preset( $product_id ) : 'default',
            'options'       => $this->product_presets(),
            'wrapper_class' => 'show_if_variable delicat-swatch-preset-field',
            'desc_tip'      => true,
            'description'   => 'Choisissez le style de variations pour ce produit. Delicat ABONNEMENT premium est conçu pour Netflix, streaming et autres abonnements.',
        ) );
    }

    public function save_product_preset( $product ) {
        if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_post', $product->get_id() ) ) { return; }
        if ( ! isset( $_POST['_ddsw_product_preset'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its product nonce before this hook.
        $preset = sanitize_key( wp_unslash( $_POST['_ddsw_product_preset'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! array_key_exists( $preset, $this->product_presets() ) ) { $preset = 'default'; }
        $product->update_meta_data( '_ddsw_product_preset', $preset );
    }

    public function save_product_preset_meta( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) { return; }
        if ( ! isset( $_POST['_ddsw_product_preset'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce already verifies its product save nonce before this hook.

        $preset = sanitize_key( wp_unslash( $_POST['_ddsw_product_preset'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! array_key_exists( $preset, $this->product_presets() ) ) { $preset = 'default'; }
        update_post_meta( $post_id, '_ddsw_product_preset', $preset );
    }

    private function checkbox_value( $input, $key ) {
        return isset( $input[ $key ] ) ? 'yes' : 'no';
    }

    private function int_value( $input, $key, $default, $min, $max ) {
        $value = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : $default;
        return min( $max, max( $min, $value ) );
    }

    public function save_settings() {
        if ( empty( $_POST['ddsw_save'] ) ) { return; }
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        check_admin_referer( 'ddsw_save_settings' );

        $input = isset( $_POST['ddsw'] ) && is_array( $_POST['ddsw'] ) ? wp_unslash( $_POST['ddsw'] ) : array();
        $d = $this->defaults();
        $out = $d;

        foreach ( array(
            'enabled', 'hide_native', 'show_reset', 'show_stock', 'show_recommended', 'auto_select_recommended',
            'out_stock_popup', 'show_filterbar', 'subscription_show_features', 'subscription_show_cta',
            'subscription_show_discount', 'subscription_show_pager', 'subscription_show_trust', 'subscription_equal_height'
        ) as $key ) {
            $out[ $key ] = $this->checkbox_value( $input, $key );
        }

        foreach ( array( 'columns_desktop', 'columns_tablet', 'columns_mobile', 'subscription_columns_desktop', 'subscription_columns_tablet' ) as $key ) {
            $out[ $key ] = $this->int_value( $input, $key, $d[ $key ], 1, 6 );
        }

        // Mobile subscription cards support deliberate fractional visibility.
        // Store as a string so 1.5 / 2.5 are never truncated by absint().
        $mobile_visible = isset( $input['subscription_columns_mobile'] ) ? (string) $input['subscription_columns_mobile'] : (string) $d['subscription_columns_mobile'];
        $out['subscription_columns_mobile'] = in_array( $mobile_visible, array( '1', '1.5', '2', '2.5', '3' ), true ) ? $mobile_visible : $d['subscription_columns_mobile'];

        foreach ( array(
            'panel_padding', 'panel_radius', 'panel_gap', 'column_gap', 'row_gap', 'card_min_height', 'card_padding',
            'card_radius', 'title_price_gap', 'title_size', 'price_size', 'delivery_size', 'delivery_radius',
            'delivery_padding_x', 'delivery_padding_y', 'subscription_card_min_width', 'subscription_card_min_height',
            'subscription_card_gap', 'subscription_mobile_peek', 'subscription_heading_size', 'subscription_title_size',
            'subscription_price_size', 'subscription_cta_height', 'subscription_cta_radius'
        ) as $key ) {
            $out[ $key ] = $this->int_value( $input, $key, $d[ $key ], 0, 400 );
        }

        foreach ( array(
            'panel_from', 'panel_to', 'selected_from', 'selected_to', 'recommended_bg', 'recommended_color',
            'subscription_accent', 'subscription_accent_2', 'subscription_navy', 'subscription_gold',
            'subscription_card_bg', 'subscription_text', 'subscription_muted', 'subscription_border', 'subscription_section_bg'
        ) as $key ) {
            $color = isset( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : '';
            $out[ $key ] = $color ? $color : $d[ $key ];
        }

        $out['alignment'] = in_array( $input['alignment'] ?? 'center', array( 'left', 'center', 'right' ), true ) ? $input['alignment'] : 'center';
        $out['subscription_plan_alignment'] = in_array( $input['subscription_plan_alignment'] ?? 'center', array( 'left', 'center', 'right' ), true ) ? $input['subscription_plan_alignment'] : 'center';
        $out['effect'] = in_array( $input['effect'] ?? 'soft', array( 'none', 'soft', '3d', 'glass', 'glow' ), true ) ? $input['effect'] : 'soft';
        $out['section_labels'] = sanitize_textarea_field( $input['section_labels'] ?? $d['section_labels'] );
        $out['none_terms'] = sanitize_text_field( $input['none_terms'] ?? $d['none_terms'] );
        $out['attribute_priority'] = sanitize_text_field( $input['attribute_priority'] ?? $d['attribute_priority'] );
        $out['filter_attributes'] = sanitize_text_field( $input['filter_attributes'] ?? $d['filter_attributes'] );
        $out['recommended_label'] = substr( sanitize_text_field( $input['recommended_label'] ?? $d['recommended_label'] ), 0, 32 );
        if ( '' === $out['recommended_label'] ) { $out['recommended_label'] = $d['recommended_label']; }

        foreach ( array(
            'subscription_heading' => 80,
            'subscription_subtitle' => 140,
            'subscription_cta_label' => 40,
            'subscription_selected_label' => 40,
            'subscription_trust_1' => 60,
            'subscription_trust_2' => 60,
            'subscription_trust_3' => 60,
        ) as $key => $limit ) {
            $value = substr( sanitize_text_field( $input[ $key ] ?? $d[ $key ] ), 0, $limit );
            $out[ $key ] = '' !== $value ? $value : $d[ $key ];
        }
        $out['subscription_motion'] = in_array( $input['subscription_motion'] ?? 'smooth', array( 'none', 'smooth', 'lift' ), true ) ? $input['subscription_motion'] : 'smooth';
        $out['out_stock_title'] = sanitize_text_field( $input['out_stock_title'] ?? $d['out_stock_title'] );
        $out['out_stock_message'] = sanitize_textarea_field( $input['out_stock_message'] ?? $d['out_stock_message'] );
        $out['out_stock_button'] = sanitize_text_field( $input['out_stock_button'] ?? $d['out_stock_button'] );
        $out['out_stock_phone'] = sanitize_text_field( $input['out_stock_phone'] ?? $d['out_stock_phone'] );
        $out['out_stock_whatsapp_message'] = sanitize_textarea_field( $input['out_stock_whatsapp_message'] ?? $d['out_stock_whatsapp_message'] );
        $accent = sanitize_hex_color( $input['out_stock_accent'] ?? '' );
        $out['out_stock_accent'] = $accent ?: $d['out_stock_accent'];

        update_option( self::OPTION, $out, false );
        $this->settings = $out;
        add_settings_error( 'ddsw_messages', 'saved', __( 'Settings saved.', 'delicat-swatches' ), 'updated' );
    }

    private function delivery_presets() {
        return array(
            ''          => 'Aucun badge',
            'instant'   => '⚡ Livraison instantanée',
            'five_min'  => '🕒 Dans environ 5 min',
            'manual'    => '👤 Validation manuelle',
            'custom'    => '✏️ Personnalisé',
        );
    }

    private function marketing_presets() {
        return array(
            ''            => 'Aucun badge',
            'popular'     => '🔥 Populaire',
            'best_choice' => '⭐ Meilleur choix',
            'best_value'  => '💎 Meilleure valeur',
            'promo'       => '🏷️ Promo',
            'new'         => '✨ Nouveau',
            'secure'      => '🛡️ Sécurisé',
            'bonus'       => '🎁 Bonus',
            'fastest'     => '⚡ Le + rapide',
            'custom'      => '✏️ Personnalisé',
        );
    }

    private function preset_text( $preset, $kind = 'delivery' ) {
        $map = 'marketing' === $kind ? $this->marketing_presets() : $this->delivery_presets();
        if ( ! isset( $map[ $preset ] ) || in_array( $preset, array( '', 'custom' ), true ) ) { return ''; }
        return (string) $map[ $preset ];
    }

    private function infer_delivery_preset( $saved, $text ) {
        $saved = sanitize_key( (string) $saved );
        if ( array_key_exists( $saved, $this->delivery_presets() ) && '' !== $saved ) { return $saved; }
        $needle = remove_accents( strtolower( (string) $text ) );
        if ( '' === trim( $needle ) ) { return ''; }
        if ( false !== strpos( $needle, 'instant' ) ) { return 'instant'; }
        if ( false !== strpos( $needle, '5 min' ) || false !== strpos( $needle, '5min' ) ) { return 'five_min'; }
        if ( false !== strpos( $needle, 'manuel' ) || false !== strpos( $needle, 'manual' ) ) { return 'manual'; }
        return 'custom';
    }

    private function infer_marketing_preset( $saved, $text ) {
        $saved = sanitize_key( (string) $saved );
        if ( array_key_exists( $saved, $this->marketing_presets() ) && '' !== $saved ) { return $saved; }
        $needle = remove_accents( strtolower( trim( (string) $text ) ) );
        if ( '' === $needle ) { return ''; }
        $checks = array(
            'populaire' => 'popular', 'popular' => 'popular',
            'meilleur choix' => 'best_choice', 'best choice' => 'best_choice',
            'meilleure valeur' => 'best_value', 'best value' => 'best_value',
            'promo' => 'promo', 'nouveau' => 'new', 'new' => 'new',
            'securise' => 'secure', 'secure' => 'secure',
            'bonus' => 'bonus', 'rapide' => 'fastest', 'fastest' => 'fastest',
        );
        foreach ( $checks as $fragment => $preset ) {
            if ( false !== strpos( $needle, $fragment ) ) { return $preset; }
        }
        return 'custom';
    }

    public function variation_delivery_fields( $loop, $variation_data, $variation ) {
        unset( $loop, $variation_data );
        $variation_id = absint( $variation->ID );
        if ( ! $variation_id ) { return; }

        $text = sanitize_text_field( get_post_meta( $variation_id, '_ddsw_delivery_text', true ) );
        $delivery_preset = $this->infer_delivery_preset( get_post_meta( $variation_id, '_ddsw_delivery_preset', true ), $text );
        $bg = sanitize_hex_color( get_post_meta( $variation_id, '_ddsw_delivery_bg', true ) ) ?: '#dcfce7';
        $color = sanitize_hex_color( get_post_meta( $variation_id, '_ddsw_delivery_color', true ) ) ?: '#166534';
        $recommended = 'yes' === get_post_meta( $variation_id, '_ddsw_recommended', true ) ? 'yes' : 'no';
        $recommended_label = sanitize_text_field( get_post_meta( $variation_id, '_ddsw_recommended_label', true ) );
        $badge2 = sanitize_text_field( get_post_meta( $variation_id, '_ddsw_badge2_text', true ) );
        $badge2_preset = $this->infer_marketing_preset( get_post_meta( $variation_id, '_ddsw_badge2_preset', true ), $badge2 );
        $badge2_bg = sanitize_hex_color( get_post_meta( $variation_id, '_ddsw_badge2_bg', true ) ) ?: '#eef2ff';
        $badge2_color = sanitize_hex_color( get_post_meta( $variation_id, '_ddsw_badge2_color', true ) ) ?: '#3730a3';
        $features = sanitize_textarea_field( get_post_meta( $variation_id, '_ddsw_features', true ) );

        echo '<div class="form-row form-row-full ddsw-delivery-admin" style="clear:both;padding:12px 14px;margin:10px 0;background:#f7f8ff;border:1px solid #dbe4f0;border-radius:12px;box-sizing:border-box">';
        echo '<input type="hidden" name="_ddsw_badges_present[' . esc_attr( $variation_id ) . ']" value="1">';
        echo '<strong style="display:block;margin-bottom:9px;font-size:13px">🏷️ Badges de cette variation</strong>';
        echo '<p style="margin:0 0 10px;color:#64748b">Ces badges appartiennent uniquement à cette variation et restent synchronisés avec WooCommerce.</p>';

        echo '<div style="padding:10px 0;border-top:1px dashed #dbe2ea">';
        echo '<strong style="display:block;margin-bottom:7px">🚚 Livraison</strong>';
        echo '<p class="form-field form-row form-row-full"><label for="_ddsw_delivery_preset_' . esc_attr( $variation_id ) . '">Type de badge</label><select id="_ddsw_delivery_preset_' . esc_attr( $variation_id ) . '" name="_ddsw_delivery_preset[' . esc_attr( $variation_id ) . ']" class="ddsw-delivery-preset" style="width:100%">';
        foreach ( $this->delivery_presets() as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $delivery_preset, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></p>';
        woocommerce_wp_text_input( array(
            'id' => '_ddsw_delivery_text_' . $variation_id,
            'name' => '_ddsw_delivery_text[' . $variation_id . ']',
            'value' => $text,
            'label' => 'Texte personnalisé',
            'placeholder' => '⚡ Livraison instantanée',
            'desc_tip' => true,
            'description' => 'Utilisé quand le type Personnalisé est choisi. Les presets remplissent automatiquement le texte.',
            'wrapper_class' => 'form-row form-row-full ddsw-delivery-custom-row',
        ) );
        echo '<p class="form-field form-row form-row-first"><label>Couleur du fond</label><input type="color" name="_ddsw_delivery_bg[' . esc_attr( $variation_id ) . ']" value="' . esc_attr( $bg ) . '"></p>';
        echo '<p class="form-field form-row form-row-last"><label>Couleur du texte</label><input type="color" name="_ddsw_delivery_color[' . esc_attr( $variation_id ) . ']" value="' . esc_attr( $color ) . '"></p>';
        echo '</div>';

        echo '<div style="clear:both;padding:10px 0;border-top:1px dashed #dbe2ea">';
        echo '<p class="form-field form-row form-row-full"><label for="_ddsw_recommended_' . esc_attr( $variation_id ) . '">⭐ Plan recommandé</label><label style="display:inline-flex;align-items:center;gap:7px;width:auto;margin:0"><input type="checkbox" id="_ddsw_recommended_' . esc_attr( $variation_id ) . '" name="_ddsw_recommended[' . esc_attr( $variation_id ) . ']" value="yes" ' . checked( $recommended, 'yes', false ) . '> Marquer cette variation comme recommandée</label><span class="description" style="display:block;margin-top:4px">Affiche le badge Recommandé sur cette variation.</span></p>';
        woocommerce_wp_text_input( array(
            'id' => '_ddsw_recommended_label_' . $variation_id,
            'name' => '_ddsw_recommended_label[' . $variation_id . ']',
            'value' => $recommended_label,
            'label' => 'Texte du badge recommandé',
            'placeholder' => 'Recommandé',
            'wrapper_class' => 'form-row form-row-full',
        ) );
        echo '</div>';

        echo '<div style="clear:both;padding:10px 0 0;border-top:1px dashed #dbe2ea">';
        echo '<strong style="display:block;margin-bottom:7px">🔥 Badge marketing</strong>';
        echo '<p class="form-field form-row form-row-full"><label for="_ddsw_badge2_preset_' . esc_attr( $variation_id ) . '">Type de badge</label><select id="_ddsw_badge2_preset_' . esc_attr( $variation_id ) . '" name="_ddsw_badge2_preset[' . esc_attr( $variation_id ) . ']" class="ddsw-marketing-preset" style="width:100%">';
        foreach ( $this->marketing_presets() as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $badge2_preset, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></p>';
        woocommerce_wp_text_input( array(
            'id' => '_ddsw_badge2_text_' . $variation_id,
            'name' => '_ddsw_badge2_text[' . $variation_id . ']',
            'value' => $badge2,
            'label' => 'Texte personnalisé',
            'placeholder' => '🔥 Populaire',
            'desc_tip' => true,
            'description' => 'Utilisé pour Personnalisé. Presets disponibles : Populaire, Meilleur choix, Meilleure valeur, Promo, Nouveau, Sécurisé, Bonus, Le + rapide.',
            'wrapper_class' => 'form-row form-row-full ddsw-marketing-custom-row',
        ) );
        echo '<p class="form-field form-row form-row-first"><label>Couleur du fond</label><input type="color" name="_ddsw_badge2_bg[' . esc_attr( $variation_id ) . ']" value="' . esc_attr( $badge2_bg ) . '"></p>';
        echo '<p class="form-field form-row form-row-last"><label>Couleur du texte</label><input type="color" name="_ddsw_badge2_color[' . esc_attr( $variation_id ) . ']" value="' . esc_attr( $badge2_color ) . '"></p>';
        echo '</div>';

        echo '<div style="clear:both;padding:10px 0 0;border-top:1px dashed #dbe2ea">';
        echo '<strong style="display:block;margin-bottom:7px">✓ Caractéristiques du plan</strong>';
        echo '<p class="form-field form-row form-row-full"><label for="_ddsw_features_' . esc_attr( $variation_id ) . '">Une caractéristique par ligne</label><textarea rows="4" id="_ddsw_features_' . esc_attr( $variation_id ) . '" name="_ddsw_features[' . esc_attr( $variation_id ) . ']" style="width:100%" placeholder="Qualité HD&#10;1 Profil&#10;PIN unique&#10;Livraison instantanée">' . esc_textarea( $features ) . '</textarea><span class="description">Affichées dans les cartes du preset Delicat ABONNEMENT premium. Maximum 8 lignes.</span></p>';
        echo '</div>';
        echo '<p style="clear:both;margin:8px 0 0;color:#64748b"><strong>Exemple :</strong> ⭐ Recommandé + 🔥 Populaire + ⚡ Livraison instantanée peuvent être affichés ensemble.</p>';
        echo '</div>';
    }

    public function save_variation_delivery_fields( $variation_id, $loop ) {
        unset( $loop );
        $variation_id = absint( $variation_id );
        if ( ! $variation_id || ! current_user_can( 'edit_products' ) ) { return; }

        $delivery_presets = isset( $_POST['_ddsw_delivery_preset'] ) ? (array) wp_unslash( $_POST['_ddsw_delivery_preset'] ) : array();
        $texts = isset( $_POST['_ddsw_delivery_text'] ) ? (array) wp_unslash( $_POST['_ddsw_delivery_text'] ) : array();
        $bgs = isset( $_POST['_ddsw_delivery_bg'] ) ? (array) wp_unslash( $_POST['_ddsw_delivery_bg'] ) : array();
        $colors = isset( $_POST['_ddsw_delivery_color'] ) ? (array) wp_unslash( $_POST['_ddsw_delivery_color'] ) : array();
        $recommended = isset( $_POST['_ddsw_recommended'] ) ? (array) wp_unslash( $_POST['_ddsw_recommended'] ) : array();
        $recommended_labels = isset( $_POST['_ddsw_recommended_label'] ) ? (array) wp_unslash( $_POST['_ddsw_recommended_label'] ) : array();
        $badge2_presets = isset( $_POST['_ddsw_badge2_preset'] ) ? (array) wp_unslash( $_POST['_ddsw_badge2_preset'] ) : array();
        $badge2_texts = isset( $_POST['_ddsw_badge2_text'] ) ? (array) wp_unslash( $_POST['_ddsw_badge2_text'] ) : array();
        $badge2_bgs = isset( $_POST['_ddsw_badge2_bg'] ) ? (array) wp_unslash( $_POST['_ddsw_badge2_bg'] ) : array();
        $badge2_colors = isset( $_POST['_ddsw_badge2_color'] ) ? (array) wp_unslash( $_POST['_ddsw_badge2_color'] ) : array();
        $features_input = isset( $_POST['_ddsw_features'] ) ? (array) wp_unslash( $_POST['_ddsw_features'] ) : array();

        $delivery_preset_submitted = array_key_exists( $variation_id, $delivery_presets );
        $delivery_preset = sanitize_key( $delivery_presets[ $variation_id ] ?? '' );
        if ( ! array_key_exists( $delivery_preset, $this->delivery_presets() ) ) { $delivery_preset = ''; }
        $custom_delivery = substr( sanitize_text_field( $texts[ $variation_id ] ?? '' ), 0, 80 );
        $delivery_text = 'custom' === $delivery_preset ? $custom_delivery : $this->preset_text( $delivery_preset, 'delivery' );
        if ( ! $delivery_preset_submitted && '' === $delivery_preset && '' !== $custom_delivery ) {
            // Backward compatibility for rows saved before the preset selector existed.
            $delivery_preset = 'custom';
            $delivery_text = $custom_delivery;
        }
        if ( '' === $delivery_text ) {
            delete_post_meta( $variation_id, '_ddsw_delivery_text' );
            delete_post_meta( $variation_id, '_ddsw_delivery_preset' );
        } else {
            update_post_meta( $variation_id, '_ddsw_delivery_text', $delivery_text );
            update_post_meta( $variation_id, '_ddsw_delivery_preset', $delivery_preset );
        }

        $bg = sanitize_hex_color( $bgs[ $variation_id ] ?? '' );
        $color = sanitize_hex_color( $colors[ $variation_id ] ?? '' );
        if ( $bg ) { update_post_meta( $variation_id, '_ddsw_delivery_bg', $bg ); }
        if ( $color ) { update_post_meta( $variation_id, '_ddsw_delivery_color', $color ); }

        if ( ! empty( $recommended[ $variation_id ] ) && 'no' !== $recommended[ $variation_id ] ) { update_post_meta( $variation_id, '_ddsw_recommended', 'yes' ); }
        else { delete_post_meta( $variation_id, '_ddsw_recommended' ); }
        $rec_label = substr( sanitize_text_field( $recommended_labels[ $variation_id ] ?? '' ), 0, 32 );
        if ( '' !== $rec_label ) { update_post_meta( $variation_id, '_ddsw_recommended_label', $rec_label ); }
        else { delete_post_meta( $variation_id, '_ddsw_recommended_label' ); }

        $badge2_preset_submitted = array_key_exists( $variation_id, $badge2_presets );
        $badge2_preset = sanitize_key( $badge2_presets[ $variation_id ] ?? '' );
        if ( ! array_key_exists( $badge2_preset, $this->marketing_presets() ) ) { $badge2_preset = ''; }
        $custom_badge2 = substr( sanitize_text_field( $badge2_texts[ $variation_id ] ?? '' ), 0, 48 );
        $badge2_text = 'custom' === $badge2_preset ? $custom_badge2 : $this->preset_text( $badge2_preset, 'marketing' );
        if ( ! $badge2_preset_submitted && '' === $badge2_preset && '' !== $custom_badge2 ) {
            $badge2_preset = 'custom';
            $badge2_text = $custom_badge2;
        }
        if ( '' === $badge2_text ) {
            delete_post_meta( $variation_id, '_ddsw_badge2_text' );
            delete_post_meta( $variation_id, '_ddsw_badge2_preset' );
        } else {
            update_post_meta( $variation_id, '_ddsw_badge2_text', $badge2_text );
            update_post_meta( $variation_id, '_ddsw_badge2_preset', $badge2_preset );
        }
        $badge2_bg = sanitize_hex_color( $badge2_bgs[ $variation_id ] ?? '' );
        $badge2_color = sanitize_hex_color( $badge2_colors[ $variation_id ] ?? '' );
        if ( $badge2_bg ) { update_post_meta( $variation_id, '_ddsw_badge2_bg', $badge2_bg ); }
        if ( $badge2_color ) { update_post_meta( $variation_id, '_ddsw_badge2_color', $badge2_color ); }

        $features_raw = sanitize_textarea_field( $features_input[ $variation_id ] ?? '' );
        $features_lines = preg_split( '/\r\n|\r|\n/', $features_raw );
        $features_lines = array_slice( array_values( array_filter( array_map( static function( $line ) {
            return substr( sanitize_text_field( trim( (string) $line ) ), 0, 72 );
        }, is_array( $features_lines ) ? $features_lines : array() ) ) ), 0, 8 );
        if ( ! empty( $features_lines ) ) { update_post_meta( $variation_id, '_ddsw_features', implode( "\n", $features_lines ) ); }
        else { delete_post_meta( $variation_id, '_ddsw_features' ); }
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $s = $this->settings();
        settings_errors( 'ddsw_messages' );
        ?>
        <div class="wrap ddsw-studio-wrap">
            <form method="post" class="ddsw-studio" data-ddsw-studio>
                <?php wp_nonce_field( 'ddsw_save_settings' ); ?>
                <input type="hidden" name="ddsw_save" value="1">

                <header class="ddsw-studio-topbar">
                    <div class="ddsw-studio-brand">
                        <span class="ddsw-studio-logo" aria-hidden="true">D</span>
                        <div>
                            <div class="ddsw-studio-kicker">Delicat Builder V9</div>
                            <h1>Swatch Studio <span>Premium</span></h1>
                            <p>Variations WooCommerce natives, presets par produit et aperçu instantané.</p>
                        </div>
                    </div>
                    <div class="ddsw-studio-actions">
                        <span class="ddsw-status"><i></i> Actif</span>
                        <a class="ddsw-ghost-button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">Produits</a>
                        <button class="button button-primary ddsw-save-button" type="submit">Enregistrer</button>
                    </div>
                </header>

                <div class="ddsw-studio-shell">
                    <nav class="ddsw-studio-nav" aria-label="Sections Swatch Studio">
                        <?php
                        $tabs = array(
                            'general'      => array( '⚙', 'Général' ),
                            'layout'       => array( '▦', 'Disposition' ),
                            'subscription' => array( '▣', 'Cartes abonnement' ),
                            'colors'       => array( '◐', 'Couleurs' ),
                            'type'         => array( 'T', 'Typographie' ),
                            'badges'       => array( '◇', 'Badges' ),
                            'motion'       => array( '✧', 'Animations' ),
                            'buttons'      => array( '▭', 'Boutons' ),
                            'mobile'       => array( '▯', 'Mobile' ),
                            'advanced'     => array( '⌘', 'Avancé' ),
                            'preview'      => array( '◉', 'Aperçu en direct' ),
                        );
                        foreach ( $tabs as $key => $tab ) {
                            echo '<button type="button" class="ddsw-nav-item' . ( 'general' === $key ? ' is-active' : '' ) . '" data-ddsw-tab-target="' . esc_attr( $key ) . '"><span class="ddsw-nav-icon" aria-hidden="true">' . esc_html( $tab[0] ) . '</span><span>' . esc_html( $tab[1] ) . '</span></button>';
                        }
                        ?>
                        <div class="ddsw-nav-help">
                            <strong>Preset par produit</strong>
                            <p>Dans le produit WooCommerce → <b>Swatch Studio</b>, choisissez <b>Delicat ABONNEMENT premium</b>. Le raccourci apparaît aussi dans Général lorsque cet onglet existe.</p>
                        </div>
                    </nav>

                    <main class="ddsw-studio-main">
                        <section class="ddsw-panel is-active" data-ddsw-panel="general">
                            <?php $this->studio_heading( 'Général', 'Contrôlez le moteur de variations sans remplacer WooCommerce.' ); ?>
                            <div class="ddsw-card-grid two">
                                <div class="ddsw-settings-card">
                                    <h3>Moteur</h3>
                                    <?php $this->studio_toggle( 'enabled', 'Activer les swatches', 'Affiche les cartes de variations sur les produits variables.', $s ); ?>
                                    <?php $this->studio_toggle( 'hide_native', 'Masquer les listes Woo natives', 'Les listes restent présentes dans le DOM pour WooCommerce mais sont masquées après initialisation.', $s ); ?>
                                    <?php $this->studio_toggle( 'show_reset', 'Afficher Réinitialiser', 'Permet de revenir à aucune variation sélectionnée.', $s ); ?>
                                    <?php $this->studio_toggle( 'show_stock', 'Afficher le stock faible', 'Affiche le stock quand il reste 5 unités ou moins.', $s ); ?>
                                </div>
                                <div class="ddsw-settings-card preset-card">
                                    <span class="ddsw-premium-chip">NOUVEAU PRESET</span>
                                    <h3>Delicat ABONNEMENT premium</h3>
                                    <p>Conçu pour Netflix, streaming, abonnements et plans mensuels. Carousel natif, cartes premium, badges, caractéristiques, réduction et état sélectionné.</p>
                                    <ol>
                                        <li>Ouvrez un produit variable.</li>
                                        <li>Dans l’onglet <b>Swatch Studio</b> du produit, sélectionnez le preset.</li>
                                        <li>Ajoutez les caractéristiques dans chaque variation.</li>
                                    </ol>
                                </div>
                            </div>
                        </section>

                        <section class="ddsw-panel" data-ddsw-panel="layout">
                            <?php $this->studio_heading( 'Disposition', 'Colonnes, espacements, formes et alignement du style global.' ); ?>
                            <div class="ddsw-settings-card">
                                <h3>Grille globale</h3>
                                <div class="ddsw-fields-grid two">
                                    <?php $this->studio_number( 'columns_desktop', 'Colonnes desktop', $s, 1, 6, '' ); ?>
                                    <?php $this->studio_number( 'columns_tablet', 'Colonnes tablette', $s, 1, 6, '' ); ?>
                                </div>
                            </div>
                            <div class="ddsw-settings-card">
                                <h3>Espacement & forme</h3>
                                <div class="ddsw-fields-grid three">
                                    <?php
                                    foreach ( array(
                                        'panel_padding' => 'Padding panneau', 'panel_radius' => 'Rayon panneau', 'panel_gap' => 'Espace sections',
                                        'column_gap' => 'Espace colonnes', 'row_gap' => 'Espace lignes', 'card_min_height' => 'Hauteur carte',
                                        'card_padding' => 'Padding carte', 'card_radius' => 'Rayon carte', 'title_price_gap' => 'Espace titre/prix'
                                    ) as $key => $label ) { $this->studio_number( $key, $label, $s ); }
                                    ?>
                                </div>
                                <div class="ddsw-fields-grid two">
                                    <?php $this->studio_select( 'alignment', 'Alignement', array( 'left' => 'Gauche', 'center' => 'Centre', 'right' => 'Droite' ), $s ); ?>
                                </div>
                            </div>
                        </section>

                        <section class="ddsw-panel" data-ddsw-panel="subscription">
                            <?php $this->studio_heading( 'Delicat ABONNEMENT premium', 'Le preset demandé pour certains produits, inspiré de vos maquettes Premium.' ); ?>
                            <div class="ddsw-settings-card">
                                <h3>En-tête</h3>
                                <div class="ddsw-fields-grid two">
                                    <?php $this->studio_text( 'subscription_heading', 'Titre', $s ); ?>
                                    <?php $this->studio_text( 'subscription_subtitle', 'Sous-titre', $s ); ?>
                                </div>
                            </div>
                            <div class="ddsw-settings-card">
                                <h3>Cartes abonnement</h3>
                                <div class="ddsw-fields-grid three">
                                    <?php $this->studio_number( 'subscription_columns_desktop', 'Plans visibles desktop', $s, 1, 6, '' ); ?>
                                    <?php $this->studio_number( 'subscription_columns_tablet', 'Plans visibles tablette', $s, 1, 6, '' ); ?>
                                    <?php $this->studio_number( 'subscription_card_gap', 'Écart entre cartes', $s ); ?>
                                    <?php $this->studio_number( 'subscription_card_min_height', 'Hauteur minimum', $s ); ?>
                                </div>
                                <div class="ddsw-toggle-grid">
                                    <?php $this->studio_toggle( 'subscription_equal_height', 'Hauteur égale', 'Aligne les cartes même avec des contenus différents.', $s ); ?>
                                    <?php $this->studio_toggle( 'subscription_show_features', 'Caractéristiques', 'Affiche les lignes enregistrées dans chaque variation.', $s ); ?>
                                    <?php $this->studio_toggle( 'subscription_show_discount', 'Réduction', 'Affiche le pourcentage calculé quand la variation est en promotion.', $s ); ?>
                                    <?php $this->studio_toggle( 'subscription_show_pager', 'Navigation & compteur', 'Ajoute les flèches et le compteur de plans.', $s ); ?>
                                    <?php $this->studio_toggle( 'subscription_show_trust', 'Bandeau de confiance', 'Ajoute Paiement sécurisé, Livraison rapide et Support humain.', $s ); ?>
                                    <?php $this->studio_toggle( 'subscription_show_cta', 'Bouton dans la carte', 'Affiche Choisir ce plan / Plan sélectionné.', $s ); ?>
                                </div>
                            </div>
                            <div class="ddsw-settings-card">
                                <h3>Bandeau de confiance</h3>
                                <div class="ddsw-fields-grid three">
                                    <?php $this->studio_text( 'subscription_trust_1', 'Élément 1', $s ); ?>
                                    <?php $this->studio_text( 'subscription_trust_2', 'Élément 2', $s ); ?>
                                    <?php $this->studio_text( 'subscription_trust_3', 'Élément 3', $s ); ?>
                                </div>
                            </div>
                        </section>

                        <section class="ddsw-panel" data-ddsw-panel="colors">
                            <?php $this->studio_heading( 'Couleurs', 'Toutes les couleurs ci-dessous sont réellement appliquées au storefront.' ); ?>
                            <div class="ddsw-settings-card">
                                <h3>Style global</h3>
                                <div class="ddsw-fields-grid four">
                                    <?php
                                    foreach ( array(
                                        'panel_from' => 'Panneau 1', 'panel_to' => 'Panneau 2', 'selected_from' => 'Sélection 1', 'selected_to' => 'Sélection 2'
                                    ) as $key => $label ) { $this->studio_color( $key, $label, $s ); }
                                    ?>
                                </div>
                            </div>
                            <div class="ddsw-settings-card">
                                <h3>Delicat ABONNEMENT premium</h3>
                                <div class="ddsw-fields-grid four">
                                    <?php
                                    foreach ( array(
                                        'subscription_accent' => 'Accent', 'subscription_accent_2' => 'Accent 2', 'subscription_navy' => 'Navy sélection',
                                        'subscription_gold' => 'Or', 'subscription_card_bg' => 'Carte', 'subscription_text' => 'Texte',
                                        'subscription_muted' => 'Texte secondaire', 'subscription_border' => 'Bordure', 'subscription_section_bg' => 'Fond section'
                                    ) as $key => $label ) { $this->studio_color( $key, $label, $s ); }
                                    ?>
                                </div>
                            </div>
                        </section>

                        <section class="ddsw-panel" data-ddsw-panel="type">
                            <?php $this->studio_heading( 'Typographie', 'Tailles du style global et du preset abonnement.' ); ?>
                            <div class="ddsw-settings-card">
                                <h3>Style global</h3>
                                <div class="ddsw-fields-grid three">
                                    <?php $this->studio_number( 'title_size', 'Titre variation', $s ); ?>
                                    <?php $this->studio_number( 'price_size', 'Prix', $s ); ?>
                                    <?php $this->studio_number( 'delivery_size', 'Badge livraison', $s ); ?>
                                </div>
                            </div>
                            <div class="ddsw-settings-card">
                                <h3>ABONNEMENT premium</h3>
                                <div class="ddsw-fields-grid three">
                                    <?php $this->studio_number( 'subscription_heading_size', 'Titre section', $s ); ?>
                                    <?php $this->studio_number( 'subscription_title_size', 'Titre plan', $s ); ?>
                                    <?php $this->studio_number( 'subscription_price_size', 'Prix plan', $s ); ?>
                                </div>
                            </div>
                        </section>

                        <section class="ddsw-panel" data-ddsw-panel="badges">
                            <?php $this->studio_heading( 'Badges', 'Recommandé, marketing, livraison et rupture de stock.' ); ?>
                            <div class="ddsw-card-grid two">
                                <div class="ddsw-settings-card">
                                    <h3>Plan recommandé</h3>
                                    <?php $this->studio_toggle( 'show_recommended', 'Afficher le badge Recommandé', 'Le badge est activé variation par variation.', $s ); ?>
                                    <?php $this->studio_toggle( 'auto_select_recommended', 'Sélection automatique', 'Sélectionne la variation recommandée au chargement.', $s ); ?>
                                    <?php $this->studio_text( 'recommended_label', 'Texte par défaut', $s ); ?>
                                    <div class="ddsw-fields-grid two">
                                        <?php $this->studio_color( 'recommended_bg', 'Fond', $s ); ?>
                                        <?php $this->studio_color( 'recommended_color', 'Texte', $s ); ?>
                                    </div>
                                </div>
                                <div class="ddsw-settings-card">
                                    <h3>Rupture de stock</h3>
                                    <?php $this->studio_toggle( 'out_stock_popup', 'Popup WhatsApp', 'Les variations épuisées ouvrent une fenêtre de contact.', $s ); ?>
                                    <?php $this->studio_text( 'out_stock_title', 'Titre', $s ); ?>
                                    <?php $this->studio_textarea( 'out_stock_message', 'Message', $s, 3 ); ?>
                                    <?php $this->studio_text( 'out_stock_button', 'Bouton', $s ); ?>
                                    <?php $this->studio_text( 'out_stock_phone', 'WhatsApp', $s ); ?>
                                    <?php $this->studio_textarea( 'out_stock_whatsapp_message', 'Message WhatsApp ({product}, {variation})', $s, 3 ); ?>
                                    <?php $this->studio_color( 'out_stock_accent', 'Couleur bouton', $s ); ?>
                                </div>
                            </div>
                            <div class="ddsw-settings-card info-card">
                                <h3>Badges par variation</h3>
                                <p>Les badges <b>Livraison</b>, <b>Recommandé</b> et <b>Marketing</b> restent configurés directement dans chaque variation WooCommerce. Le preset abonnement les repositionne automatiquement sans dupliquer les données.</p>
                            </div>
                            <div class="ddsw-settings-card">
                                <h3>Badge livraison — dimensions globales</h3>
                                <div class="ddsw-fields-grid three">
                                    <?php $this->studio_number( 'delivery_radius', 'Rayon', $s ); ?>
                                    <?php $this->studio_number( 'delivery_padding_x', 'Padding horizontal', $s ); ?>
                                    <?php $this->studio_number( 'delivery_padding_y', 'Padding vertical', $s ); ?>
                                </div>
                            </div>
                        </section>

                        <section class="ddsw-panel" data-ddsw-panel="motion">
                            <?php $this->studio_heading( 'Animations', 'Animations limitées à transform/opacity pour garder un rendu fluide.' ); ?>
                            <div class="ddsw-settings-card">
                                <div class="ddsw-fields-grid two">
                                    <?php $this->studio_select( 'effect', 'Effet des cartes globales', array( 'none' => 'Aucun', 'soft' => 'Ombre douce', '3d' => '3D', 'glass' => 'Glass', 'glow' => 'Glow' ), $s ); ?>
                                    <?php $this->studio_select( 'subscription_motion', 'Mouvement ABONNEMENT', array( 'none' => 'Aucun', 'smooth' => 'Doux', 'lift' => 'Élévation premium' ), $s ); ?>
                                </div>
                                <p class="ddsw-inline-note">Le site respecte automatiquement <code>prefers-reduced-motion</code>.</p>
                            </div>
                        </section>

                        <section class="ddsw-panel" data-ddsw-panel="buttons">
                            <?php $this->studio_heading( 'Boutons', 'Libellés et dimensions du CTA intégré dans les cartes abonnement.' ); ?>
                            <div class="ddsw-settings-card">
                                <div class="ddsw-fields-grid two">
                                    <?php $this->studio_text( 'subscription_cta_label', 'Bouton non sélectionné', $s ); ?>
                                    <?php $this->studio_text( 'subscription_selected_label', 'Bouton sélectionné', $s ); ?>
                                    <?php $this->studio_number( 'subscription_cta_height', 'Hauteur', $s ); ?>
                                    <?php $this->studio_number( 'subscription_cta_radius', 'Rayon', $s ); ?>
                                </div>
                            </div>
                        </section>

                        <section class="ddsw-panel" data-ddsw-panel="mobile">
                            <?php $this->studio_heading( 'Mobile', 'Contrôles dédiés aux petits écrans et au swipe natif.' ); ?>
                            <div class="ddsw-settings-card">
                                <div class="ddsw-fields-grid three">
                                    <?php $this->studio_number( 'columns_mobile', 'Colonnes style global', $s, 1, 6, '' ); ?>
                                    <?php $this->studio_select( 'subscription_columns_mobile', 'Plans visibles mobile', array( '1' => '1', '1.5' => '1.5', '2' => '2', '2.5' => '2.5', '3' => '3' ), $s ); ?>
                                    <?php $this->studio_select( 'subscription_plan_alignment', 'Titre & prix mobile', array( 'left' => 'Gauche', 'center' => 'Centre', 'right' => 'Droite' ), $s ); ?>
                                </div>
                                <p class="ddsw-inline-note">Choisissez exactement <b>1, 1.5, 2, 2.5 ou 3 plans visibles</b>. Le preset garde CSS scroll-snap + swipe natif, sans librairie carousel. La largeur minimale/ancien « aperçu suivant » reste conservée en base pour compatibilité mais n'impose plus la largeur mobile.</p>
                            </div>
                        </section>

                        <section class="ddsw-panel" data-ddsw-panel="advanced">
                            <?php $this->studio_heading( 'Avancé', 'Ordre et libellés des attributs sans modifier les variations WooCommerce.' ); ?>
                            <div class="ddsw-settings-card">
                                <?php $this->studio_textarea( 'section_labels', 'Sections — attribute-slug|TITRE, une ligne par attribut', $s, 6 ); ?>
                                <?php $this->studio_text( 'attribute_priority', 'Priorité des attributs, séparée par virgules', $s ); ?>
                                <?php $this->studio_text( 'none_terms', 'Termes à ignorer, séparés par virgules', $s ); ?>
                                <div class="ddsw-inline-note"><b>Régions / serveurs :</b> l’ancien filtre d’attributs est désactivé. Le Product / Service / Region Switcher reste l’unique moteur de changement de produit.</div>
                            </div>
                        </section>

                        <section class="ddsw-panel" data-ddsw-panel="preview">
                            <?php $this->studio_heading( 'Aperçu en direct', 'Modifiez les paramètres dans les autres onglets : cet aperçu se met à jour sans recharger.' ); ?>
                            <div class="ddsw-preview-stage" data-ddsw-preview>
                                <div class="ddsw-preview-heading">
                                    <span>✨</span>
                                    <div><h2><?php echo esc_html( $s['subscription_heading'] ); ?></h2><p><?php echo esc_html( $s['subscription_subtitle'] ); ?></p></div>
                                </div>
                                <div class="ddsw-preview-cards">
                                    <article class="ddsw-preview-plan">
                                        <b class="badge purple">★ Populaire</b><h3>1 mois</h3><strong>G450</strong>
                                        <ul><li>Qualité HD</li><li>1 Profil</li><li>PIN unique</li><li>Livraison instantanée</li></ul>
                                        <span class="cta">Choisir ce plan</span>
                                    </article>
                                    <article class="ddsw-preview-plan selected">
                                        <b class="badge gold">♛ Meilleur choix</b><h3>3 mois</h3><strong>G1,600</strong>
                                        <ul><li>Qualité HD</li><li>1 Profil</li><li>PIN unique</li><li>Livraison instantanée</li></ul>
                                        <span class="cta">Plan sélectionné</span>
                                    </article>
                                    <article class="ddsw-preview-plan">
                                        <b class="badge green">✓ Économique</b><h3>6 mois</h3><strong>G2,950</strong>
                                        <ul><li>Qualité HD</li><li>1 Profil</li><li>PIN unique</li><li>Livraison instantanée</li></ul>
                                        <span class="cta">Choisir ce plan</span>
                                    </article>
                                </div>
                                <div class="ddsw-preview-trust"><span>🛡 Paiement 100% sécurisé</span><span>⚡ Livraison rapide</span><span>◉ Support humain</span></div>
                            </div>
                        </section>
                    </main>
                </div>
                <div class="ddsw-mobile-save"><button class="button button-primary" type="submit">Enregistrer les paramètres</button></div>
            </form>
        </div>
        <?php
    }

    private function studio_heading( $title, $description ) {
        echo '<div class="ddsw-panel-heading"><div><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $description ) . '</p></div><span class="ddsw-panel-version">v' . esc_html( self::VERSION ) . '</span></div>';
    }

    private function studio_toggle( $key, $label, $description, $s ) {
        echo '<label class="ddsw-toggle-field"><span><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $description ) . '</small></span><span class="ddsw-switch"><input type="checkbox" name="ddsw[' . esc_attr( $key ) . ']" value="1" ' . checked( $s[ $key ], 'yes', false ) . '><i></i></span></label>';
    }

    private function studio_number( $key, $label, $s, $min = 0, $max = 400, $suffix = 'px' ) {
        echo '<label class="ddsw-field"><span>' . esc_html( $label ) . '</span><div class="ddsw-input-suffix"><input type="number" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" name="ddsw[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[ $key ] ) . '" data-ddsw-key="' . esc_attr( $key ) . '">' . ( '' !== $suffix ? '<em>' . esc_html( $suffix ) . '</em>' : '' ) . '</div></label>';
    }

    private function studio_color( $key, $label, $s ) {
        echo '<label class="ddsw-field color"><span>' . esc_html( $label ) . '</span><div class="ddsw-color-input"><input type="color" name="ddsw[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[ $key ] ) . '" data-ddsw-key="' . esc_attr( $key ) . '"><code>' . esc_html( $s[ $key ] ) . '</code></div></label>';
    }

    private function studio_text( $key, $label, $s ) {
        echo '<label class="ddsw-field"><span>' . esc_html( $label ) . '</span><input type="text" name="ddsw[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[ $key ] ) . '" data-ddsw-key="' . esc_attr( $key ) . '"></label>';
    }

    private function studio_textarea( $key, $label, $s, $rows = 4 ) {
        echo '<label class="ddsw-field full"><span>' . esc_html( $label ) . '</span><textarea rows="' . absint( $rows ) . '" name="ddsw[' . esc_attr( $key ) . ']" data-ddsw-key="' . esc_attr( $key ) . '">' . esc_textarea( $s[ $key ] ) . '</textarea></label>';
    }

    private function studio_select( $key, $label, $options, $s ) {
        echo '<label class="ddsw-field"><span>' . esc_html( $label ) . '</span><select name="ddsw[' . esc_attr( $key ) . ']" data-ddsw-key="' . esc_attr( $key ) . '">';
        foreach ( $options as $value => $name ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $s[ $key ], $value, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select></label>';
    }

    private function none_terms() {
        return array_values( array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', (string) $this->get( 'none_terms' ) ) ) ) ) );
    }

    private function priority() {
        return array_values( array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', (string) $this->get( 'attribute_priority' ) ) ) ) ) );
    }

    /**
     * RC51.7 — the old variation-attribute region/server tab engine is retired.
     * Regions/services are now linked as real WooCommerce products by the
     * Product / Service / Region Switcher, so variation cards remain dedicated
     * to actual packages/plans only.
     */
    private function filter_attributes() {
        return array();
    }

    private function labels() {
        $labels = array();
        $lines = preg_split( '/\r\n|\r|\n/', (string) $this->get( 'section_labels' ) );
        foreach ( $lines as $line ) {
            $parts = array_map( 'trim', explode( '|', $line, 2 ) );
            if ( 2 === count( $parts ) && $parts[0] !== '' ) {
                $labels[ sanitize_title( $parts[0] ) ] = $parts[1];
            }
        }
        return $labels;
    }

    private function display_name( $attribute, $value ) {
        if ( taxonomy_exists( $attribute ) ) {
            $term = get_term_by( 'slug', $value, $attribute );
            if ( $term && ! is_wp_error( $term ) ) { return $term->name; }
        }
        return wc_clean( rawurldecode( $value ) );
    }

    private function pick_primary_attribute( $attrs ) {
        $none = $this->none_terms();
        foreach ( $this->priority() as $preferred ) {
            if ( isset( $attrs[ $preferred ] ) && '' !== $attrs[ $preferred ] && ! in_array( sanitize_title( $attrs[ $preferred ] ), $none, true ) ) {
                return $preferred;
            }
        }
        foreach ( $attrs as $attribute => $value ) {
            if ( '' !== $value && ! in_array( sanitize_title( $value ), $none, true ) ) { return sanitize_title( $attribute ); }
        }
        return '';
    }

    private function variation_features( $variation_id ) {
        $raw = sanitize_textarea_field( get_post_meta( absint( $variation_id ), '_ddsw_features', true ) );
        if ( '' === trim( $raw ) ) { return array(); }
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        if ( ! is_array( $lines ) ) { return array(); }
        return array_slice( array_values( array_filter( array_map( static function( $line ) {
            return substr( sanitize_text_field( trim( (string) $line ) ), 0, 72 );
        }, $lines ) ) ), 0, 8 );
    }

    public function render() {
        if ( 'yes' !== $this->get( 'enabled' ) ) { return; }
        global $product;
        if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) { return; }

        $available = $product->get_children();
        if ( empty( $available ) ) { return; }
        // PRO21: fetch uncached variation posts/meta in bulk, avoiding one query
        // per variation while retaining WooCommerce's live product objects.
        if ( function_exists( '_prime_post_caches' ) ) {
            _prime_post_caches( array_map( 'absint', $available ), false, true );
        }

        $product_preset = $this->product_preset( $product->get_id() );
        $is_subscription_preset = 'delicat_abonnement_premium' === $product_preset;
        $labels = $this->labels();
        $groups = array();
        $none_terms   = $this->none_terms();
        $filter_attrs = $this->filter_attributes();
        $filter_tabs  = array();
        $has_out_of_stock = false;

        foreach ( $available as $variation_id ) {
            $variation_id = absint( $variation_id );
            $variation = $variation_id ? wc_get_product( $variation_id ) : null;
            if ( ! $variation instanceof WC_Product_Variation ) { continue; }

            $raw_attrs = array();
            foreach ( $variation->get_attributes() as $name => $value ) {
                $raw_attrs[ 'attribute_' . sanitize_title( $name ) ] = $value;
            }
            $attrs = array();
            foreach ( $raw_attrs as $name => $value ) {
                $attrs[ sanitize_title( preg_replace( '/^attribute_/', '', $name ) ) ] = (string) $value;
            }

            $primary = $this->pick_primary_attribute( $attrs );
            if ( '' === $primary || empty( $attrs[ $primary ] ) ) { continue; }
            $value = $attrs[ $primary ];
            $section = isset( $labels[ $primary ] ) ? $labels[ $primary ] : strtoupper( str_replace( '-', ' ', preg_replace( '/^pa_/', '', $primary ) ) );

            /* Legacy filter metadata retained internally for backward-safe parsing; RC51.7 does not render attribute filter tabs. */
            $card_filters = array();
            foreach ( $filter_attrs as $fa ) {
                if ( $fa === $primary ) { continue; }
                if ( isset( $attrs[ $fa ] ) && '' !== $attrs[ $fa ] ) {
                    $fa_slug = sanitize_title( $attrs[ $fa ] );
                    if ( in_array( $fa_slug, $none_terms, true ) ) { continue; }
                    $card_filters[ $fa ] = $fa_slug;
                    if ( ! isset( $filter_tabs[ $fa ][ $fa_slug ] ) ) {
                        $filter_tabs[ $fa ][ $fa_slug ] = $this->display_name( $fa, $attrs[ $fa ] );
                    }
                }
            }

            $regular = $variation->get_regular_price();
            $sale = $variation->get_sale_price();
            $current = $variation->get_price();
            $discount = 0;
            if ( '' !== $sale && '' !== $regular && (float) $regular > (float) $sale ) {
                $price_html = '<del>' . wc_price( $regular ) . '</del><ins>' . wc_price( $sale ) . '</ins>';
                $discount = min( 99, max( 1, (int) round( ( ( (float) $regular - (float) $sale ) / (float) $regular ) * 100 ) ) );
            } else {
                $price_html = '' !== $current ? wc_price( $current ) : '';
            }

            $variation_in_stock = $variation->is_in_stock();
            $variation_purchasable = $variation->is_purchasable();
            if ( ! $variation_in_stock || ! $variation_purchasable ) { $has_out_of_stock = true; }

            $groups[ $primary ]['label'] = $section;
            $groups[ $primary ]['items'][] = array(
                'variation_id' => $variation_id,
                'attributes'   => $attrs,
                'title'        => $this->display_name( $primary, $value ),
                'price_html'   => $price_html,
                'in_stock'     => $variation_in_stock,
                'purchasable'   => $variation_purchasable,
                'full_name'     => wp_strip_all_tags( $variation->get_name() ),
                'stock_qty'    => $variation->managing_stock() ? $variation->get_stock_quantity() : null,
                'delivery_text' => sanitize_text_field( get_post_meta( $variation_id, '_ddsw_delivery_text', true ) ),
                'delivery_bg'   => sanitize_hex_color( get_post_meta( $variation_id, '_ddsw_delivery_bg', true ) ) ?: '#dcfce7',
                'delivery_color'=> sanitize_hex_color( get_post_meta( $variation_id, '_ddsw_delivery_color', true ) ) ?: '#166534',
                'recommended'   => 'yes' === get_post_meta( $variation_id, '_ddsw_recommended', true ),
                'recommended_label' => sanitize_text_field( get_post_meta( $variation_id, '_ddsw_recommended_label', true ) ),
                'badge2_text'   => sanitize_text_field( get_post_meta( $variation_id, '_ddsw_badge2_text', true ) ),
                'badge2_bg'     => sanitize_hex_color( get_post_meta( $variation_id, '_ddsw_badge2_bg', true ) ) ?: '#eef2ff',
                'badge2_color'  => sanitize_hex_color( get_post_meta( $variation_id, '_ddsw_badge2_color', true ) ) ?: '#3730a3',
                'filters'       => $card_filters,
                'display_price' => (float) $variation->get_price(),
                'discount'      => $discount,
                'features'      => $this->variation_features( $variation_id ),
            );
        }

        if ( empty( $groups ) ) { return; }

        echo '<div class="ddsw-root ddsw-preset-' . esc_attr( str_replace( '_', '-', $product_preset ) ) . '" data-preset="' . esc_attr( $product_preset ) . '" data-hide-native="' . esc_attr( $this->get( 'hide_native' ) ) . '">';
        if ( 'yes' === $this->get( 'show_filterbar' ) && ! empty( $filter_tabs ) ) {
            foreach ( $filter_tabs as $fa => $tab_values ) {
                /* No tabs when the attribute is already a card section, or with a single value. */
                if ( isset( $groups[ $fa ] ) || count( $tab_values ) < 2 ) { continue; }
                $bar_label = isset( $labels[ $fa ] ) ? $labels[ $fa ] : strtoupper( str_replace( '-', ' ', preg_replace( '/^pa_/', '', $fa ) ) );
                echo '<div class="ddsw-filterbar" data-filter-attr="' . esc_attr( $fa ) . '">';
                echo '<span class="ddsw-filterbar-label">' . esc_html( $bar_label ) . '</span>';
                echo '<div class="ddsw-filter-tabs" role="tablist" aria-label="' . esc_attr( $bar_label ) . '">';
                foreach ( $tab_values as $tab_slug => $tab_label ) {
                    echo '<button type="button" class="ddsw-filter-tab" role="tab" aria-selected="false" data-filter-value="' . esc_attr( $tab_slug ) . '">' . esc_html( $tab_label ) . '</button>';
                }
                echo '</div></div>';
            }
        }
        foreach ( $groups as $attribute => $group ) {
            echo '<section class="ddsw-section' . ( $is_subscription_preset ? ' ddsw-subscription-section' : '' ) . '" data-section="' . esc_attr( $attribute ) . '">';
            if ( $is_subscription_preset ) {
                echo '<div class="ddsw-premium-heading"><span class="ddsw-premium-spark" aria-hidden="true">✦</span><div><h3>' . esc_html( $this->get( 'subscription_heading' ) ) . '</h3><p>' . esc_html( $this->get( 'subscription_subtitle' ) ) . '</p></div></div>';
            } else {
                echo '<h3 class="ddsw-section-title">' . esc_html( $group['label'] ) . '</h3>';
            }
            echo '<div class="ddsw-grid" role="radiogroup" aria-label="' . esc_attr( $group['label'] ) . '">';
            foreach ( $group['items'] as $item ) {
                $json = wp_json_encode( $item['attributes'] );
                $filters_json = wp_json_encode( empty( $item['filters'] ) ? (object) array() : $item['filters'] );
                $out_of_stock = ! $item['in_stock'] || ! $item['purchasable'];
                $recommended = ! empty( $item['recommended'] );
                $show_recommended = $recommended && 'yes' === $this->get( 'show_recommended' );
                $recommended_label = ! empty( $item['recommended_label'] ) ? $item['recommended_label'] : $this->get( 'recommended_label' );
                $has_badge2 = '' !== $item['badge2_text'];
                $classes = 'ddsw-card' . ( $recommended ? ' is-recommended' : '' ) . ( $show_recommended ? ' has-recommended-badge' : '' ) . ( $has_badge2 ? ' has-badge2' : '' ) . ( $out_of_stock ? ' is-disabled is-out-of-stock' : '' );
                echo '<button type="button" class="' . esc_attr( $classes ) . '" data-variation-id="' . esc_attr( $item['variation_id'] ) . '" data-variation-name="' . esc_attr( $item['full_name'] ) . '" data-display-price="' . esc_attr( $item['display_price'] ) . '" data-recommended="' . ( $recommended ? '1' : '0' ) . '" data-attributes="' . esc_attr( $json ) . '" data-filters="' . esc_attr( $filters_json ) . '" data-out-of-stock="' . ( $out_of_stock ? '1' : '0' ) . '" role="radio" aria-checked="false"' . ( $out_of_stock ? ' aria-disabled="true"' : '' ) . '>';
                if ( $show_recommended ) { echo '<span class="ddsw-recommended-badge">' . esc_html( $recommended_label ) . '</span>'; }
                if ( $has_badge2 ) { echo '<span class="ddsw-badge2" style="--ddsw-b2-bg:' . esc_attr( $item['badge2_bg'] ) . ';--ddsw-b2-color:' . esc_attr( $item['badge2_color'] ) . '">' . esc_html( $item['badge2_text'] ) . '</span>'; }
                echo '<span class="ddsw-title">' . esc_html( $item['title'] ) . '</span>';
                if ( $item['price_html'] ) {
                    echo '<span class="ddsw-price-row"><span class="ddsw-price">' . wp_kses_post( $item['price_html'] ) . '</span>';
                    if ( $is_subscription_preset && 'yes' === $this->get( 'subscription_show_discount' ) && ! empty( $item['discount'] ) ) { echo '<span class="ddsw-discount">-' . absint( $item['discount'] ) . '%</span>'; }
                    echo '</span>';
                }
                if ( $is_subscription_preset && 'yes' === $this->get( 'subscription_show_features' ) && ! empty( $item['features'] ) ) {
                    echo '<span class="ddsw-features" aria-label="Caractéristiques">';
                    foreach ( $item['features'] as $feature ) { echo '<span class="ddsw-feature"><i aria-hidden="true">✓</i>' . esc_html( $feature ) . '</span>'; }
                    echo '</span>';
                }
                if ( ! empty( $item['delivery_text'] ) ) {
                    echo '<span class="ddsw-delivery" style="--ddsw-delivery-bg:' . esc_attr( $item['delivery_bg'] ) . ';--ddsw-delivery-color:' . esc_attr( $item['delivery_color'] ) . '">' . esc_html( $item['delivery_text'] ) . '</span>';
                }
                if ( 'yes' === $this->get( 'show_stock' ) && null !== $item['stock_qty'] && $item['stock_qty'] > 0 && $item['stock_qty'] <= 5 ) {
                    echo '<span class="ddsw-stock">' . sprintf( esc_html__( 'Only %d left', 'delicat-swatches' ), absint( $item['stock_qty'] ) ) . '</span>';
                }
                if ( $is_subscription_preset && 'yes' === $this->get( 'subscription_show_cta' ) && ! $out_of_stock ) {
                    echo '<span class="ddsw-plan-cta" data-default-label="' . esc_attr( $this->get( 'subscription_cta_label' ) ) . '" data-selected-label="' . esc_attr( $this->get( 'subscription_selected_label' ) ) . '"><span class="ddsw-plan-cta-text">' . esc_html( $this->get( 'subscription_cta_label' ) ) . '</span><i aria-hidden="true">↗</i></span>';
                }
                if ( $out_of_stock ) { echo '<span class="ddsw-oos-label">Rupture</span>'; }
                echo '<span class="ddsw-check" aria-hidden="true">✓</span>';
                echo '</button>';
            }
            echo '</div>';
            if ( $is_subscription_preset && 'yes' === $this->get( 'subscription_show_pager' ) ) {
                echo '<div class="ddsw-premium-nav"><button type="button" class="ddsw-plan-prev" aria-label="Plan précédent">‹</button><span class="ddsw-plan-index">1 / ' . absint( count( $group['items'] ) ) . '</span><button type="button" class="ddsw-plan-next" aria-label="Plan suivant">›</button></div>';
            }
            echo '</section>';
        }
        if ( 'yes' === $this->get( 'show_reset' ) ) {
            echo '<button type="button" class="ddsw-reset">' . esc_html__( 'Clear selection', 'delicat-swatches' ) . '</button>';
        }
        echo '</div>';
        if ( $is_subscription_preset && 'yes' === $this->get( 'subscription_show_trust' ) ) {
            echo '<div class="ddsw-subscription-trust"><span><i aria-hidden="true">🛡</i>' . esc_html( $this->get( 'subscription_trust_1' ) ) . '</span><span><i aria-hidden="true">⚡</i>' . esc_html( $this->get( 'subscription_trust_2' ) ) . '</span><span><i aria-hidden="true">◉</i>' . esc_html( $this->get( 'subscription_trust_3' ) ) . '</span></div>';
        }
        if ( $has_out_of_stock && 'yes' === $this->get( 'out_stock_popup' ) ) {
            $phone = preg_replace( '/\D+/', '', (string) $this->get( 'out_stock_phone' ) );
            echo '<div class="ddsw-oos-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="ddsw-oos-title" data-phone="' . esc_attr( $phone ) . '" data-wa-message="' . esc_attr( $this->get( 'out_stock_whatsapp_message' ) ) . '" data-product="' . esc_attr( $product->get_name() ) . '" style="--ddsw-oos-accent:' . esc_attr( $this->get( 'out_stock_accent' ) ) . '">';
            echo '<div class="ddsw-oos-backdrop" data-ddsw-close></div><div class="ddsw-oos-dialog">';
            echo '<button type="button" class="ddsw-oos-close" data-ddsw-close aria-label="Fermer">×</button>';
            echo '<span class="ddsw-oos-icon" aria-hidden="true">!</span>';
            echo '<h3 id="ddsw-oos-title">' . esc_html( $this->get( 'out_stock_title' ) ) . '</h3>';
            echo '<p>' . esc_html( $this->get( 'out_stock_message' ) ) . '</p>';
            echo '<a class="ddsw-oos-whatsapp" href="#" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">✆</span> ' . esc_html( $this->get( 'out_stock_button' ) ) . '</a>';
            echo '</div></div>';
        }
    }

    public function assets() {
        if ( ! is_product() || 'yes' !== $this->get( 'enabled' ) ) { return; }
        $product_id = get_queried_object_id();
        $product = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
        if ( ! $product instanceof WC_Product ) { return; }

        /*
         * Native region/service switching replaces only the product fragment.
         * If the current product is simple but any configured switcher target is
         * variable, preload this tiny swatch runtime now so the fetched fragment
         * can initialize without a full WordPress page reload.
         */
        $needs_swatches = $product->is_type( 'variable' );
        if ( ! $needs_swatches && class_exists( 'Delicat_Builder_V9_Product_Switcher' ) ) {
            $switcher = Delicat_Builder_V9_Product_Switcher::settings_for( (int) $product_id );
            if ( 'yes' === ( $switcher['enabled'] ?? 'no' ) ) {
                foreach ( array_slice( (array) ( $switcher['options'] ?? array() ), 0, 30 ) as $item ) {
                    $target_id = absint( is_array( $item ) ? ( $item['product_id'] ?? 0 ) : 0 );
                    if ( ! $target_id || $target_id === (int) $product_id ) { continue; }
                    $target = wc_get_product( $target_id );
                    if ( $target instanceof WC_Product && $target->is_type( 'variable' ) ) {
                        $needs_swatches = true;
                        break;
                    }
                }
            }
        }
        if ( ! $needs_swatches ) { return; }
        $s = $this->settings();
        wp_register_style( 'delicat-direct-swatches', false, array(), self::VERSION );
        wp_enqueue_style( 'delicat-direct-swatches' );
        wp_add_inline_style( 'delicat-direct-swatches', $this->css( $s ) );

        wp_register_script( 'delicat-direct-swatches', false, array( 'jquery', 'wc-add-to-cart-variation' ), self::VERSION, true );
        wp_enqueue_script( 'delicat-direct-swatches' );
        wp_add_inline_script( 'delicat-direct-swatches', $this->js() );
    }

    private function css( $s ) {
        $align = in_array( $s['alignment'], array( 'left', 'center', 'right' ), true ) ? $s['alignment'] : 'center';
        $plan_align = in_array( $s['subscription_plan_alignment'] ?? 'center', array( 'left', 'center', 'right' ), true ) ? $s['subscription_plan_alignment'] : 'center';
        $plan_justify = 'left' === $plan_align ? 'flex-start' : ( 'right' === $plan_align ? 'flex-end' : 'center' );
        $mobile_visible_raw = (string) ( $s['subscription_columns_mobile'] ?? '1.5' );
        $mobile_visible = in_array( $mobile_visible_raw, array( '1', '1.5', '2', '2.5', '3' ), true ) ? (float) $mobile_visible_raw : 1.5;
        $desktop_visible = max( 1, min( 6, absint( $s['subscription_columns_desktop'] ) ) );
        $tablet_visible = max( 1, min( 6, absint( $s['subscription_columns_tablet'] ) ) );
        $subscription_gap = absint( $s['subscription_card_gap'] );
        $desktop_basis = 'calc(' . round( 100 / $desktop_visible, 5 ) . '% - ' . round( ( $subscription_gap * max( 0, $desktop_visible - 1 ) ) / $desktop_visible, 4 ) . 'px)';
        $tablet_basis = 'calc(' . round( 100 / $tablet_visible, 5 ) . '% - ' . round( ( $subscription_gap * max( 0, $tablet_visible - 1 ) ) / $tablet_visible, 4 ) . 'px)';
        // Number of full gaps that can be visible between whole/partial cards.
        $mobile_gap_count = max( 0, (int) ceil( $mobile_visible ) - 1 );
        $mobile_card_percent = round( 100 / $mobile_visible, 5 );
        $mobile_gap_adjust = round( ( $subscription_gap * $mobile_gap_count ) / $mobile_visible, 4 );
        $mobile_basis = 'calc(' . $mobile_card_percent . '% - ' . $mobile_gap_adjust . 'px)';
        $effect = sanitize_html_class( $s['effect'] );
        return '
        form.variations_form .ddsw-root{--ddsw-cols-d:' . absint($s['columns_desktop']) . ';--ddsw-cols-t:' . absint($s['columns_tablet']) . ';--ddsw-cols-m:' . absint($s['columns_mobile']) . ';--ddsw-col-gap:' . absint($s['column_gap']) . 'px;--ddsw-row-gap:' . absint($s['row_gap']) . 'px;--ddsw-card-h:' . absint($s['card_min_height']) . 'px;--ddsw-card-pad:' . absint($s['card_padding']) . 'px;--ddsw-card-r:' . absint($s['card_radius']) . 'px;--ddsw-text-gap:' . absint($s['title_price_gap']) . 'px;display:flex;flex-direction:column;gap:' . absint($s['panel_gap']) . 'px;width:100%;max-width:100%;box-sizing:border-box;margin:0 0 18px!important;padding:0!important}
        form.variations_form .ddsw-section{box-sizing:border-box;width:100%;max-width:100%;overflow:visible;padding:' . absint($s['panel_padding']) . 'px;border-radius:' . absint($s['panel_radius']) . 'px;background:linear-gradient(135deg,' . esc_attr($s['panel_from']) . ',' . esc_attr($s['panel_to']) . ')}
        form.variations_form .ddsw-section-title{display:block!important;margin:0 0 14px!important;padding:0!important;color:#fff!important;font-size:13px!important;font-weight:800!important;letter-spacing:.28em!important;line-height:1.3!important;text-transform:uppercase!important;text-align:left!important}
        form.variations_form .ddsw-grid{display:grid!important;grid-template-columns:repeat(var(--ddsw-cols-d),minmax(0,1fr))!important;column-gap:var(--ddsw-col-gap)!important;row-gap:var(--ddsw-row-gap)!important;width:100%!important;max-width:100%!important;margin:0!important;padding:0!important;box-sizing:border-box!important}
        form.variations_form .ddsw-card{position:relative!important;display:flex!important;flex-direction:column!important;align-items:' . ($align==='left'?'flex-start':($align==='right'?'flex-end':'center')) . '!important;justify-content:center!important;gap:var(--ddsw-text-gap)!important;min-width:0!important;width:100%!important;max-width:100%!important;min-height:var(--ddsw-card-h)!important;height:auto!important;padding:var(--ddsw-card-pad)!important;margin:0!important;border:2px solid #d8dbea!important;border-radius:var(--ddsw-card-r)!important;background:linear-gradient(180deg,#fff,#f8f9ff)!important;color:#071548!important;font:inherit!important;text-align:' . $align . '!important;white-space:normal!important;overflow:visible!important;box-sizing:border-box!important;cursor:pointer!important;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease,background .18s ease!important;-webkit-tap-highlight-color:transparent;touch-action:manipulation}
        form.variations_form .ddsw-title,form.variations_form .ddsw-price,form.variations_form .ddsw-stock,form.variations_form .ddsw-delivery{display:block!important;width:100%!important;max-width:100%!important;margin:0!important;padding:0!important;box-sizing:border-box!important;white-space:normal!important;overflow-wrap:anywhere!important;word-break:normal!important;line-height:1.25!important;text-align:inherit!important}
        form.variations_form .ddsw-title{font-size:' . absint($s['title_size']) . 'px!important;font-weight:800!important}
        form.variations_form .ddsw-price{font-size:' . absint($s['price_size']) . 'px!important;font-weight:800!important}
        form.variations_form .ddsw-price del{opacity:.56;text-decoration-line:line-through!important;text-decoration-thickness:2px!important;margin-right:5px!important}
        form.variations_form .ddsw-price ins{text-decoration:none!important}
        form.variations_form .ddsw-delivery{display:inline-flex!important;flex-wrap:nowrap!important;width:max-content!important;min-width:0!important;max-width:100%!important;align-self:' . ($align==='left'?'flex-start':($align==='right'?'flex-end':'center')) . '!important;align-items:center!important;justify-content:center!important;padding:' . absint($s['delivery_padding_y']) . 'px ' . absint($s['delivery_padding_x']) . 'px!important;border-radius:' . absint($s['delivery_radius']) . 'px!important;background:var(--ddsw-delivery-bg,#dcfce7)!important;color:var(--ddsw-delivery-color,#166534)!important;font-size:' . absint($s['delivery_size']) . 'px!important;font-weight:900!important;line-height:1!important;letter-spacing:.01em!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;box-shadow:inset 0 0 0 1px rgba(15,23,42,.10)!important}
        form.variations_form .ddsw-card.has-recommended-badge{padding-top:max(var(--ddsw-card-pad),30px)!important}
        form.variations_form .ddsw-card.has-badge2{padding-top:max(var(--ddsw-card-pad),30px)!important}
        form.variations_form .ddsw-badge2{position:absolute;top:0;left:50%;right:auto;z-index:5;display:inline-flex;align-items:center;max-width:calc(100% - 24px);padding:4px 8px;border-radius:999px;background:var(--ddsw-b2-bg,#eef2ff);color:var(--ddsw-b2-color,#3730a3);font-size:9px;font-weight:950;line-height:1;letter-spacing:.02em;box-shadow:0 4px 10px rgba(15,23,42,.10);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transform:translate(-50%,-50%)}
        form.variations_form .ddsw-card.has-recommended-badge.has-badge2 .ddsw-recommended-badge{left:35%;max-width:44%}
        form.variations_form .ddsw-card.has-recommended-badge.has-badge2 .ddsw-badge2{left:65%;max-width:44%}
        form.variations_form .ddsw-filterbar{display:flex;flex-direction:column;gap:10px;box-sizing:border-box;width:100%;padding:14px 16px;border:1px solid rgba(91,124,204,.5);border-radius:18px;background:linear-gradient(140deg,#0d1b4d 0%,#17295f 48%,#284696 100%);box-shadow:0 12px 30px rgba(13,27,77,.26),inset 0 1px 0 rgba(255,255,255,.12)}
        form.variations_form .ddsw-filterbar-label{display:block;margin:0;color:#a9c0f5;font-size:11px;font-weight:800;letter-spacing:.22em;line-height:1.2;text-transform:uppercase;text-align:left}
        form.variations_form .ddsw-filter-tabs{display:flex;gap:6px;box-sizing:border-box;max-width:100%;padding:4px;border-radius:999px;background:rgba(255,255,255,.10);overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
        form.variations_form .ddsw-filter-tabs::-webkit-scrollbar{display:none}
        form.variations_form .ddsw-filter-tab{flex:1 0 auto;min-width:0;min-height:42px;margin:0;padding:9px 16px;border:1px solid transparent;border-radius:999px;background:transparent;color:rgba(255,255,255,.85);font:inherit;font-size:13px;font-weight:800;line-height:1.1;white-space:nowrap;cursor:pointer;transition:background .22s ease,color .22s ease,box-shadow .22s ease,transform .22s ease;-webkit-tap-highlight-color:transparent;touch-action:manipulation}
        form.variations_form .ddsw-filter-tab:hover{color:#fff}
        form.variations_form .ddsw-filter-tab.is-active{background:linear-gradient(135deg,#ff8a4c,#ff5a1f);border-color:rgba(255,255,255,.25);color:#fff;box-shadow:0 8px 20px rgba(255,90,31,.38),inset 0 1px 0 rgba(255,255,255,.3);transform:translateY(-1px)}
        form.variations_form .ddsw-card.is-filtered-out{display:none!important}
        form.variations_form .ddsw-grid.ddsw-swap{animation:ddswSwap .28s ease}
        @keyframes ddswSwap{from{opacity:.35;transform:translateY(6px)}to{opacity:1;transform:none}}
        @media(max-width:600px){form.variations_form .ddsw-filterbar{padding:12px 13px;border-radius:16px}form.variations_form .ddsw-filter-tab{min-height:40px;padding:8px 13px;font-size:12px}}
        @media(prefers-reduced-motion:reduce){form.variations_form .ddsw-grid.ddsw-swap{animation:none!important}form.variations_form .ddsw-filter-tab{transition:none!important;transform:none!important}}
        form.variations_form .ddsw-recommended-badge{position:absolute;top:0;left:50%;z-index:5;display:inline-flex;align-items:center;max-width:calc(100% - 24px);padding:4px 8px;border-radius:999px;background:' . esc_attr($s['recommended_bg']) . ';color:' . esc_attr($s['recommended_color']) . ';font-size:9px;font-weight:950;line-height:1;letter-spacing:.02em;box-shadow:0 4px 10px rgba(15,23,42,.10);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transform:translate(-50%,-50%)}
        form.variations_form .ddsw-card.is-selected .ddsw-recommended-badge{background:rgba(255,255,255,.94);color:#6b4300}
        form.variations_form .ddsw-card.is-selected .ddsw-delivery{filter:saturate(1.08);box-shadow:inset 0 0 0 1px rgba(255,255,255,.4),0 2px 8px rgba(0,0,0,.12)!important}
        form.variations_form .ddsw-stock{font-size:10px!important;font-weight:800!important;color:#c72c41!important}
        form.variations_form .ddsw-card.is-selected{background:linear-gradient(135deg,' . esc_attr($s['selected_from']) . ',' . esc_attr($s['selected_to']) . ')!important;color:#fff!important;border-color:' . esc_attr($s['selected_to']) . '!important;transform:translateY(-1px)!important}
        form.variations_form .ddsw-card.is-disabled{opacity:.58!important;cursor:pointer!important;filter:grayscale(.35)!important} form.variations_form .ddsw-card.is-disabled:hover{opacity:.75!important;transform:translateY(-2px)!important} form.variations_form .ddsw-oos-label{position:absolute;left:8px;bottom:7px;display:inline-flex!important;width:auto!important;padding:3px 7px!important;border-radius:999px!important;background:#fee2e2!important;color:#b91c1c!important;font-size:9px!important;font-weight:900!important;line-height:1!important;text-transform:uppercase!important;letter-spacing:.04em!important}
        form.variations_form .ddsw-check{display:none;position:absolute;top:8px;right:8px;width:22px;height:22px;border-radius:50%;background:#fff;color:#284696;font-weight:900;line-height:22px;text-align:center}
        form.variations_form .ddsw-card.has-badge2 .ddsw-check{top:8px}
        form.variations_form .ddsw-card.is-selected .ddsw-check{display:block}
        form.variations_form .ddsw-reset{align-self:flex-start;border:0!important;background:transparent!important;color:#b42357!important;padding:0!important;margin:0!important;font-size:13px!important;cursor:pointer!important}
        form.variations_form.ddsw-ready table.variations,
        form.variations_form>.ddsw-root[data-hide-native="yes"]~table.variations,
        form.variations_form .ddsw-root[data-hide-native="yes"]+table.variations,
        form.variations_form:has(.ddsw-root[data-hide-native="yes"]) table.variations{display:none!important;visibility:hidden!important;height:0!important;min-height:0!important;margin:0!important;padding:0!important;border:0!important;overflow:hidden!important}
        form.variations_form.ddsw-ready{display:block!important;min-height:0!important;height:auto!important;max-height:none!important;overflow:visible!important;padding-bottom:0!important;margin-bottom:0!important}
        .delicat-native-product form.variations_form .ddsw-root{position:static!important;float:none!important;inset:auto!important;transform:none!important}
        .delicat-native-product .dnp-purchase form.variations_form{min-height:0!important;height:auto!important;overflow:visible!important}
        .delicat-native-product .dnp-purchase .ddsw-section{contain:none!important}
        form.variations_form .single_variation_wrap,form.variations_form .woocommerce-variation-add-to-cart{position:static!important;float:none!important;clear:none!important;width:100%!important;max-width:100%!important;height:auto!important;min-height:0!important;overflow:visible!important}
        form.variations_form .ddsw-card:hover{transform:translateY(-2px)}
        form.variations_form .ddsw-card.is-loading{pointer-events:none!important;opacity:.82!important}
        @media(prefers-reduced-motion:reduce){form.variations_form .ddsw-card{transition:none!important;transform:none!important}}
        form.variations_form .ddsw-root.ddsw-effect-none .ddsw-card{box-shadow:none!important}
        form.variations_form .ddsw-root.ddsw-effect-soft .ddsw-card,form.variations_form .ddsw-card{box-shadow:0 6px 16px rgba(15,23,42,.13)!important}
        form.variations_form .ddsw-root.ddsw-effect-3d .ddsw-card{box-shadow:0 5px 0 rgba(7,21,72,.16),0 11px 20px rgba(15,23,42,.13)!important}
        form.variations_form .ddsw-root.ddsw-effect-glass .ddsw-card{background:linear-gradient(180deg,rgba(255,255,255,.94),rgba(248,249,255,.86))!important} @media(min-width:900px) and (hover:hover) and (pointer:fine){html:not(.delicat-low-power) form.variations_form .ddsw-root.ddsw-effect-glass .ddsw-card{backdrop-filter:blur(5px)!important;-webkit-backdrop-filter:blur(5px)!important}}
        form.variations_form .ddsw-root.ddsw-effect-glow .ddsw-card{box-shadow:0 0 0 1px rgba(255,255,255,.35),0 0 20px rgba(40,70,150,.26)!important}
        @media(max-width:900px){form.variations_form .ddsw-grid{grid-template-columns:repeat(var(--ddsw-cols-t),minmax(0,1fr))!important}}
        @media(max-width:600px){form.variations_form .ddsw-grid{grid-template-columns:repeat(var(--ddsw-cols-m),minmax(0,1fr))!important}}

        /* Delicat ABONNEMENT premium — selected per product, WooCommerce remains authoritative. */
        form.variations_form .ddsw-root.ddsw-preset-delicat-abonnement-premium{--ddsw-sub-accent:' . esc_attr($s['subscription_accent']) . ';--ddsw-sub-accent2:' . esc_attr($s['subscription_accent_2']) . ';--ddsw-sub-navy:' . esc_attr($s['subscription_navy']) . ';--ddsw-sub-gold:' . esc_attr($s['subscription_gold']) . ';--ddsw-sub-card:' . esc_attr($s['subscription_card_bg']) . ';--ddsw-sub-text:' . esc_attr($s['subscription_text']) . ';--ddsw-sub-muted:' . esc_attr($s['subscription_muted']) . ';--ddsw-sub-border:' . esc_attr($s['subscription_border']) . ';--ddsw-sub-section:' . esc_attr($s['subscription_section_bg']) . ';--ddsw-sub-gap:' . absint($s['subscription_card_gap']) . 'px;gap:14px!important;margin-bottom:14px!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-subscription-section{padding:18px 0 8px!important;border:1px solid color-mix(in srgb,var(--ddsw-sub-border) 82%,transparent)!important;border-radius:22px!important;background:var(--ddsw-sub-section)!important;box-shadow:0 16px 45px rgba(15,23,42,.06)!important;overflow:hidden!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-premium-heading{display:flex!important;align-items:flex-start!important;gap:10px!important;padding:0 18px 16px!important;color:var(--ddsw-sub-text)!important;text-align:left!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-premium-spark{display:grid!important;place-items:center!important;flex:0 0 auto!important;width:30px!important;height:30px!important;border-radius:10px!important;background:linear-gradient(135deg,#fff3c8,#ffe3a0)!important;color:#f4a000!important;font-size:18px!important;box-shadow:inset 0 0 0 1px rgba(245,184,46,.22)!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-premium-heading h3{margin:0!important;padding:0!important;color:var(--ddsw-sub-text)!important;font-size:' . absint($s['subscription_heading_size']) . 'px!important;font-weight:950!important;letter-spacing:-.035em!important;line-height:1.08!important;text-transform:none!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-premium-heading p{margin:5px 0 0!important;color:var(--ddsw-sub-muted)!important;font-size:13px!important;font-weight:600!important;line-height:1.4!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-grid{display:flex!important;align-items:' . ( 'yes' === $s['subscription_equal_height'] ? 'stretch' : 'flex-start' ) . '!important;gap:var(--ddsw-sub-gap)!important;width:100%!important;max-width:100%!important;padding:12px 18px 12px!important;overflow-x:auto!important;overflow-y:hidden!important;scroll-snap-type:x mandatory!important;scroll-padding-inline:18px!important;overscroll-behavior-x:contain!important;-webkit-overflow-scrolling:touch!important;scrollbar-width:none!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-grid::-webkit-scrollbar{display:none!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card{flex:0 0 ' . esc_attr($desktop_basis) . '!important;align-items:stretch!important;justify-content:flex-start!important;scroll-snap-align:start!important;min-width:0!important;min-height:' . absint($s['subscription_card_min_height']) . 'px!important;padding:18px 16px 15px!important;border:1.5px solid var(--ddsw-sub-border)!important;border-radius:20px!important;background:linear-gradient(180deg,var(--ddsw-sub-card),color-mix(in srgb,var(--ddsw-sub-card) 94%,#eef2ff))!important;color:var(--ddsw-sub-text)!important;text-align:left!important;box-shadow:0 10px 28px rgba(15,23,42,.08)!important;overflow:visible!important;transition:' . ( 'none' === $s['subscription_motion'] ? 'none' : 'transform .2s ease,box-shadow .2s ease,border-color .2s ease,background .2s ease' ) . '!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.has-recommended-badge,form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.has-badge2{padding-top:30px!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-title{margin:0!important;color:inherit!important;font-size:' . absint($s['subscription_title_size']) . 'px!important;font-weight:950!important;letter-spacing:-.025em!important;line-height:1.1!important;text-align:' . esc_attr($plan_align) . '!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-price-row{display:flex!important;align-items:flex-end!important;justify-content:' . esc_attr($plan_justify) . '!important;flex-wrap:wrap!important;gap:6px!important;width:100%!important;margin:1px 0 6px!important;text-align:' . esc_attr($plan_align) . '!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-price{display:flex!important;align-items:baseline!important;justify-content:' . esc_attr($plan_justify) . '!important;flex-wrap:wrap!important;gap:5px!important;width:auto!important;color:var(--ddsw-sub-accent)!important;font-size:' . absint($s['subscription_price_size']) . 'px!important;font-weight:950!important;letter-spacing:-.025em!important;text-align:' . esc_attr($plan_align) . '!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-price ins{order:-2!important;color:inherit!important;font-size:1em!important;font-weight:950!important;line-height:1!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-price del{order:-1!important;color:var(--ddsw-sub-muted)!important;font-size:12px!important;font-weight:700!important;line-height:1.2!important;opacity:.72!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-discount{display:inline-flex!important;align-items:center!important;min-height:22px!important;padding:3px 7px!important;border-radius:999px!important;background:#ffe6ea!important;color:#dc1e3c!important;font-size:10px!important;font-weight:950!important;line-height:1!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-features{display:grid!important;gap:9px!important;width:100%!important;margin:11px 0 14px!important;padding:13px 0 0!important;border-top:1px solid color-mix(in srgb,var(--ddsw-sub-border) 85%,transparent)!important;text-align:left!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-feature{display:flex!important;align-items:flex-start!important;gap:8px!important;width:100%!important;color:inherit!important;font-size:12px!important;font-weight:650!important;line-height:1.3!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-feature i{display:grid!important;place-items:center!important;flex:0 0 auto!important;width:17px!important;height:17px!important;margin-top:0!important;border-radius:50%!important;background:#e9fbf1!important;color:#16a45e!important;font-size:10px!important;font-style:normal!important;font-weight:950!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-delivery{align-self:flex-start!important;margin-top:auto!important;max-width:100%!important;font-size:10px!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-plan-cta{display:flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;width:100%!important;min-height:' . absint($s['subscription_cta_height']) . 'px!important;margin-top:12px!important;padding:8px 12px!important;border:1.5px solid var(--ddsw-sub-accent)!important;border-radius:' . absint($s['subscription_cta_radius']) . 'px!important;background:transparent!important;color:var(--ddsw-sub-accent)!important;font-size:12px!important;font-weight:900!important;line-height:1.1!important;text-align:center!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-plan-cta i{font-style:normal!important;font-size:14px!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.is-selected{border-color:var(--ddsw-sub-gold)!important;background:linear-gradient(150deg,var(--ddsw-sub-navy),color-mix(in srgb,var(--ddsw-sub-navy) 78%,#24416a))!important;color:#fff!important;box-shadow:0 18px 42px rgba(7,20,46,.24),0 0 0 1px color-mix(in srgb,var(--ddsw-sub-gold) 35%,transparent)!important;transform:' . ( 'lift' === $s['subscription_motion'] ? 'translateY(-4px)' : ( 'smooth' === $s['subscription_motion'] ? 'translateY(-2px)' : 'none' ) ) . '!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.is-selected .ddsw-title{color:#fff!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.is-selected .ddsw-price{color:#ffd96a!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.is-selected .ddsw-price del{color:#c7d0df!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.is-selected .ddsw-feature i{background:color-mix(in srgb,var(--ddsw-sub-gold) 22%,transparent)!important;color:#ffd96a!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.is-selected .ddsw-plan-cta{border-color:transparent!important;background:linear-gradient(135deg,var(--ddsw-sub-accent),var(--ddsw-sub-accent2))!important;color:#fff!important;box-shadow:0 10px 24px color-mix(in srgb,var(--ddsw-sub-accent) 30%,transparent)!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-recommended-badge,form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-badge2{top:0!important;padding:6px 9px!important;border-radius:999px!important;font-size:9px!important;font-weight:950!important;box-shadow:0 4px 10px rgba(15,23,42,.10)!important;transform:translate(-50%,-50%)!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-recommended-badge{left:50%!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-badge2{left:50%!important;right:auto!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.has-recommended-badge.has-badge2 .ddsw-recommended-badge{left:35%!important;max-width:44%!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.has-recommended-badge.has-badge2 .ddsw-badge2{left:65%!important;max-width:44%!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-check{top:12px!important;right:12px!important;width:24px!important;height:24px!important;line-height:24px!important;background:var(--ddsw-sub-accent)!important;color:#fff!important;box-shadow:0 5px 14px color-mix(in srgb,var(--ddsw-sub-accent) 28%,transparent)!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.has-badge2 .ddsw-check{top:12px!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-oos-label{left:12px!important;bottom:12px!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-premium-nav{display:flex!important;align-items:center!important;justify-content:center!important;gap:9px!important;padding:2px 18px 8px!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-premium-nav button{display:grid!important;place-items:center!important;width:34px!important;height:34px!important;margin:0!important;padding:0!important;border:1px solid var(--ddsw-sub-border)!important;border-radius:50%!important;background:var(--ddsw-sub-card)!important;color:var(--ddsw-sub-text)!important;font-size:21px!important;font-weight:800!important;line-height:1!important;box-shadow:0 5px 12px rgba(15,23,42,.06)!important;cursor:pointer!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-premium-nav button:disabled{opacity:.36!important;cursor:default!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-plan-index{min-width:46px!important;color:var(--ddsw-sub-muted)!important;font-size:11px!important;font-weight:900!important;text-align:center!important}
        .ddsw-subscription-trust{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;width:100%!important;margin:0 0 18px!important;border:1px solid ' . esc_attr($s['subscription_border']) . '!important;border-radius:16px!important;background:' . esc_attr($s['subscription_border']) . '!important;overflow:hidden!important;box-shadow:0 10px 25px rgba(15,23,42,.05)!important}
        .ddsw-subscription-trust span{display:flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;min-height:58px!important;padding:10px 12px!important;background:' . esc_attr($s['subscription_card_bg']) . '!important;color:' . esc_attr($s['subscription_text']) . '!important;font-size:11px!important;font-weight:850!important;text-align:center!important}
        .ddsw-subscription-trust i{font-style:normal!important;font-size:15px!important}
        form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-reset{margin-left:18px!important;color:var(--ddsw-sub-accent)!important}
        @media(min-width:901px) and (hover:hover) and (pointer:fine){form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card:hover{transform:' . ( 'none' === $s['subscription_motion'] ? 'none' : ( 'lift' === $s['subscription_motion'] ? 'translateY(-4px)' : 'translateY(-2px)' ) ) . '!important;box-shadow:' . ( 'none' === $s['subscription_motion'] ? '0 10px 28px rgba(15,23,42,.08)' : ( 'lift' === $s['subscription_motion'] ? '0 20px 42px rgba(15,23,42,.14)' : '0 14px 32px rgba(15,23,42,.1)' ) ) . '!important}}
        @media(max-width:900px){form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card{flex-basis:' . esc_attr($tablet_basis) . '!important;width:' . esc_attr($tablet_basis) . '!important}}
        @media(max-width:600px){form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-subscription-section{border-radius:18px!important}form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-premium-heading{padding:0 14px 13px!important}form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-premium-heading h3{font-size:' . absint($s['subscription_heading_size']) . 'px!important}form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-grid{padding:12px 14px 11px!important;scroll-padding-inline:14px!important}form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card{flex:0 0 ' . esc_attr($mobile_basis) . '!important;width:' . esc_attr($mobile_basis) . '!important;min-width:0!important;max-width:none!important;min-height:' . absint($s['subscription_card_min_height']) . 'px!important;padding:17px 15px 14px!important;border-radius:18px!important}form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.has-recommended-badge,form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card.has-badge2{padding-top:26px!important}form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-title{font-size:' . absint($s['subscription_title_size']) . 'px!important;text-align:' . esc_attr($plan_align) . '!important}form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-price-row{justify-content:' . esc_attr($plan_justify) . '!important;text-align:' . esc_attr($plan_align) . '!important}form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-price{font-size:' . absint($s['subscription_price_size']) . 'px!important;text-align:' . esc_attr($plan_align) . '!important}.ddsw-subscription-trust{grid-template-columns:1fr!important}.ddsw-subscription-trust span{min-height:46px!important;justify-content:flex-start!important;text-align:left!important}}
        .dlc-theme-dark form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-subscription-section{background:#0b1220!important;border-color:#243149!important}.dlc-theme-dark form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-premium-heading h3{color:#f8fafc!important}.dlc-theme-dark form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-premium-heading p{color:#9aa8bc!important}.dlc-theme-dark form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card:not(.is-selected){background:linear-gradient(180deg,#111a2b,#0d1524)!important;border-color:#253249!important;color:#f8fafc!important}.dlc-theme-dark form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card:not(.is-selected) .ddsw-price del{color:#9aa8bc!important}.dlc-theme-dark .ddsw-subscription-trust{border-color:#263249!important;background:#263249!important}.dlc-theme-dark .ddsw-subscription-trust span{background:#101827!important;color:#edf3fb!important}
        @media(prefers-reduced-motion:reduce){form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-card,form.variations_form .ddsw-preset-delicat-abonnement-premium .ddsw-grid{scroll-behavior:auto!important;transition:none!important;transform:none!important}}

        .ddsw-oos-modal{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;padding:18px;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .22s ease,visibility .22s ease}.ddsw-oos-modal.is-open{opacity:1;visibility:visible;pointer-events:auto}.ddsw-oos-backdrop{position:absolute;inset:0;background:rgba(7,15,35,.62)}@media(min-width:900px) and (hover:hover) and (pointer:fine){html:not(.delicat-low-power) .ddsw-oos-backdrop{backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}}.ddsw-oos-dialog{position:relative;width:min(92vw,420px);padding:28px 22px 22px;border:1px solid rgba(255,255,255,.72);border-radius:24px;background:linear-gradient(160deg,rgba(255,255,255,.98),rgba(247,249,255,.95));color:#0f172a;text-align:center;box-shadow:0 28px 80px rgba(2,6,23,.35);transform:translateY(18px) scale(.96);transition:transform .34s cubic-bezier(.2,.9,.25,1)}.ddsw-oos-modal.is-open .ddsw-oos-dialog{transform:translateY(0) scale(1)}.ddsw-oos-close{position:absolute;top:10px;right:10px;width:34px;height:34px;border:0;border-radius:50%;background:#eef2f7;color:#334155;font-size:24px;line-height:1;cursor:pointer}.ddsw-oos-icon{display:grid;width:54px;height:54px;place-items:center;margin:0 auto 13px;border-radius:18px;background:#fff1f2;color:#e11d48;font-size:30px;font-weight:950;box-shadow:inset 0 0 0 1px #fecdd3}.ddsw-oos-dialog h3{margin:0 0 9px!important;color:#0f172a!important;font-size:22px!important;font-weight:900!important;line-height:1.2!important}.ddsw-oos-dialog p{margin:0 auto 18px!important;max-width:340px;color:#475569!important;font-size:14px!important;font-weight:600!important;line-height:1.55!important}.ddsw-oos-whatsapp{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:50px;padding:10px 16px;border-radius:15px;background:var(--ddsw-oos-accent,#25D366);color:#fff!important;text-decoration:none!important;font-size:15px;font-weight:900;box-shadow:0 12px 28px rgba(37,211,102,.28);transition:transform .18s ease,filter .18s ease}.ddsw-oos-whatsapp:hover{color:#fff!important;transform:translateY(-2px);filter:brightness(.96)}body.ddsw-modal-open{overflow:hidden!important}@media(prefers-reduced-motion:reduce){.ddsw-oos-modal,.ddsw-oos-dialog,.ddsw-oos-whatsapp{transition:none!important}}
        ';
    }

    private function js() {
        $effect = sanitize_html_class( (string) $this->get( 'effect' ) );
        $auto   = 'yes' === $this->get( 'auto_select_recommended' ) ? 'true' : 'false';
        $premium_motion = in_array( $this->get( 'subscription_motion' ), array( 'none', 'smooth', 'lift' ), true ) ? $this->get( 'subscription_motion' ) : 'smooth';
        $js = <<<'JS'
(function($){'use strict';
var AUTO_RECOMMENDED=__AUTO__;
var PREMIUM_MOTION='__PREMIUM_MOTION__';
function parseAttrs(el){try{return JSON.parse(el.getAttribute('data-attributes')||'{}');}catch(e){return {};}}
function formVariationReady(form){var v=form.querySelector('input.variation_id,input[name="variation_id"]');return v?parseInt(v.value||'0',10)>0:false;}
function emitPlan(form,card){
  var detail={variationId:parseInt(card.getAttribute('data-variation-id')||'0',10)||0,name:card.getAttribute('data-variation-name')||'',displayPrice:parseFloat(card.getAttribute('data-display-price')||'0')||0,recommended:card.getAttribute('data-recommended')==='1',attributes:parseAttrs(card)};
  try{form.dispatchEvent(new CustomEvent('dsb:plan_selected',{bubbles:true,detail:detail}));}catch(e){}
}
function syncPremiumState($root){
  if(String($root.attr('data-preset')||'')!=='delicat_abonnement_premium')return;
  $root.find('.ddsw-card').each(function(){var selected=this.classList.contains('is-selected');var cta=this.querySelector('.ddsw-plan-cta');if(!cta)return;var text=cta.querySelector('.ddsw-plan-cta-text');if(text){text.textContent=selected?(cta.getAttribute('data-selected-label')||'Plan sélectionné'):(cta.getAttribute('data-default-label')||'Choisir ce plan');}var icon=cta.querySelector('i');if(icon){icon.textContent=selected?'✓':'↗';}});
}
function initPremium($root){
  if(String($root.attr('data-preset')||'')!=='delicat_abonnement_premium')return;
  $root.find('.ddsw-subscription-section').each(function(){
    var section=this;if(section.getAttribute('data-ddsw-premium-init')==='1')return;section.setAttribute('data-ddsw-premium-init','1');
    var grid=section.querySelector('.ddsw-grid'),cards=grid?Array.prototype.slice.call(grid.querySelectorAll('.ddsw-card')):[];
    var prev=section.querySelector('.ddsw-plan-prev'),next=section.querySelector('.ddsw-plan-next'),index=section.querySelector('.ddsw-plan-index');
    if(!grid||!cards.length)return;
    var raf=0;
    function currentIndex(){var left=grid.scrollLeft,best=0,dist=Infinity;cards.forEach(function(card,i){var d=Math.abs(card.offsetLeft-grid.offsetLeft-left);if(d<dist){dist=d;best=i;}});return best;}
    function update(){raf=0;var i=currentIndex();if(index)index.textContent=(i+1)+' / '+cards.length;if(prev)prev.disabled=i<=0;if(next)next.disabled=i>=cards.length-1;}
    function go(delta){var i=Math.max(0,Math.min(cards.length-1,currentIndex()+delta));cards[i].scrollIntoView({behavior:(PREMIUM_MOTION==='none'||window.matchMedia('(prefers-reduced-motion: reduce)').matches)?'auto':'smooth',block:'nearest',inline:'start'});window.setTimeout(update,PREMIUM_MOTION==='none'?0:260);}
    if(prev)prev.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();go(-1);});
    if(next)next.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();go(1);});
    grid.addEventListener('scroll',function(){if(!raf)raf=window.requestAnimationFrame(update);},{passive:true});
    window.addEventListener('resize',update,{passive:true});update();
  });
  syncPremiumState($root);
}
function selectCard($form,$root,card){
  var form=$form.get(0); if(!form||!card)return;
  var attrs=parseAttrs(card),$selects=$form.find('select[name^="attribute_"]');
  var token=String(Date.now())+Math.random(); $form.data('ddswToken',token);
  $selects.each(function(){this.value='';});
  Object.keys(attrs).forEach(function(attr){var value=String(attrs[attr]||'');var selector='select[name="attribute_'+attr+'"],select[data-attribute_name="attribute_'+attr+'"]';var el=$form.find(selector).get(0);if(el){el.value=value;}});
  $root.find('.ddsw-card').removeClass('is-selected is-loading').attr('aria-checked','false');
  var $card=$(card).addClass('is-selected is-loading').attr('aria-checked','true');
  syncPremiumState($root);
  emitPlan(form,card);
  var first=$selects.get(0);
  if(first){$(first).trigger('change');}else{$form.trigger('check_variations');}
  window.setTimeout(function(){if($form.data('ddswToken')!==token)return;if(!formVariationReady(form)){$form.trigger('check_variations');} $card.removeClass('is-loading');},260);
}
function initFilters($form,$root){
  var bars=$root.find('.ddsw-filterbar');
  if(!bars.length)return;
  function findSelect(attr){return $form.find('select[name="attribute_'+attr+'"],select[data-attribute_name="attribute_'+attr+'"]').get(0);}
  function applyFilters(){
    var active={};
    bars.each(function(){var a=this.getAttribute('data-filter-attr');var t=this.querySelector('.ddsw-filter-tab.is-active');if(a&&t){active[a]=t.getAttribute('data-filter-value')||'';}});
    var deselect=false;
    $root.find('.ddsw-card').each(function(){
      var f={};try{f=JSON.parse(this.getAttribute('data-filters')||'{}');}catch(e){}
      var hide=false;
      Object.keys(active).forEach(function(a){if(active[a]&&f[a]&&f[a]!==active[a]){hide=true;}});
      if(hide&&this.classList.contains('is-selected')){deselect=true;}
      this.classList.toggle('is-filtered-out',hide);
    });
    return deselect;
  }
  function swapAnim(){$root.find('.ddsw-grid').each(function(){this.classList.remove('ddsw-swap');void this.offsetWidth;this.classList.add('ddsw-swap');});}
  function activate(bar,btn,animate){
    bar.querySelectorAll('.ddsw-filter-tab').forEach(function(t){var on=t===btn;t.classList.toggle('is-active',on);t.setAttribute('aria-selected',on?'true':'false');});
    if(animate){swapAnim();}
    var deselect=applyFilters();
    var attr=bar.getAttribute('data-filter-attr'),val=btn.getAttribute('data-filter-value')||'';
    var el=findSelect(attr);
    if(deselect){
      /* The selected card no longer belongs to this tab: clear every other
         attribute so WooCommerce resets price/summary, keep the filter value. */
      $form.find('select[name^="attribute_"]').each(function(){if(this!==el){this.value='';}});
      $root.find('.ddsw-card').removeClass('is-selected is-loading').attr('aria-checked','false');
    }
    if(el&&el.value!==val){el.value=val;$(el).trigger('change');}
    else if(deselect){$form.trigger('check_variations');}
  }
  $root.data('ddswApplyFilters',applyFilters);
  bars.each(function(){
    var bar=this;
    var el=findSelect(bar.getAttribute('data-filter-attr'));
    var current=el?String(el.value||''):'';
    var btn=null;
    if(current){
      var tabs=bar.querySelectorAll('.ddsw-filter-tab');
      for(var i=0;i<tabs.length;i++){if(tabs[i].getAttribute('data-filter-value')===current){btn=tabs[i];break;}}
    }
    if(!btn){btn=bar.querySelector('.ddsw-filter-tab');}
    if(btn){activate(bar,btn,false);}
    $(bar).off('.ddswFilter').on('click.ddswFilter','.ddsw-filter-tab',function(e){e.preventDefault();if(this.classList.contains('is-active'))return;activate(bar,this,true);});
  });
  $form.off('.ddswFilterSync').on('reset_data.ddswFilterSync',function(){
    window.setTimeout(function(){
      bars.each(function(){
        var a=this.getAttribute('data-filter-attr');var t=this.querySelector('.ddsw-filter-tab.is-active');if(!t)return;
        var el=findSelect(a);var v=t.getAttribute('data-filter-value')||'';
        if(el&&!el.value&&v){el.value=v;$(el).trigger('change');}
      });
      applyFilters();
    },0);
  });
}
function closeOos(modal){if(!modal)return;modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');document.body.classList.remove('ddsw-modal-open');}
function openOos(form,card){var modal=form.querySelector('.ddsw-oos-modal');if(!modal)return;var product=modal.getAttribute('data-product')||'',variation=card.getAttribute('data-variation-name')||'',template=modal.getAttribute('data-wa-message')||'',phone=(modal.getAttribute('data-phone')||'').replace(/\D/g,'');var msg=template.replace(/\{product\}/g,product).replace(/\{variation\}/g,variation);var link=modal.querySelector('.ddsw-oos-whatsapp');if(link){link.href=phone?'https://wa.me/'+phone+'?text='+encodeURIComponent(msg):'https://wa.me/?text='+encodeURIComponent(msg);}modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');document.body.classList.add('ddsw-modal-open');var close=modal.querySelector('.ddsw-oos-close');if(close)close.focus();}
function init(form){
  var $form=$(form),$root=$form.children('.ddsw-root').first();if(!$root.length)return;
  if(String($root.data('hide-native'))==='yes'){$form.addClass('ddsw-ready');}
  if($form.attr('data-ddsw-initialized')==='1')return;
  $form.attr('data-ddsw-initialized','1');$root.addClass('ddsw-effect-__EFFECT__');
  $root.off('.ddsw').on('click.ddsw','.ddsw-card',function(e){e.preventDefault();if(this.getAttribute('data-out-of-stock')==='1'){openOos(form,this);return;}if(this.disabled)return;selectCard($form,$root,this);})
    .on('keydown.ddsw','.ddsw-card',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();this.click();}})
    .on('click.ddsw','.ddsw-reset',function(e){e.preventDefault();var native=$form.find('.reset_variations').get(0);if(native){native.click();}else{$form.find('select[name^="attribute_"]').each(function(){this.value='';}).first().trigger('change');}$root.find('.ddsw-card').removeClass('is-selected is-loading').attr('aria-checked','false');syncPremiumState($root);});
  $form.off('.ddswSync').on('found_variation.ddswSync',function(e,variation){var id=parseInt(variation&&variation.variation_id,10)||0;$root.find('.ddsw-card').each(function(){var active=parseInt(this.getAttribute('data-variation-id'),10)===id;this.classList.toggle('is-selected',active);this.setAttribute('aria-checked',active?'true':'false');});$root.find('.ddsw-card').removeClass('is-loading');syncPremiumState($root);})
    .on('reset_data.ddswSync hide_variation.ddswSync',function(){$root.find('.ddsw-card').removeClass('is-selected is-loading').attr('aria-checked','false');syncPremiumState($root);});
  initFilters($form,$root);
  initPremium($root);
  if(AUTO_RECOMMENDED&&!formVariationReady(form)){
    var recommended=$root.find('.ddsw-card[data-recommended="1"][data-out-of-stock="0"]').not('.is-filtered-out').get(0);
    if(recommended){window.setTimeout(function(){if(form.isConnected&&!formVariationReady(form))recommended.click();},30);}
  }
}
function boot(){document.querySelectorAll('form.variations_form').forEach(init);}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',boot,{once:true});}else{boot();}
document.addEventListener('dsb:content-updated',boot);
$(document.body).on('updated_wc_div.ddsw wc_fragments_loaded.ddsw',boot).on('click.ddswOos','[data-ddsw-close]',function(){closeOos(this.closest('.ddsw-oos-modal'));});
$(document).on('keydown.ddswOos',function(e){if(e.key==='Escape'){document.querySelectorAll('.ddsw-oos-modal.is-open').forEach(closeOos);}});
})(jQuery);
JS;
        return str_replace( array( '__AUTO__', '__EFFECT__', '__PREMIUM_MOTION__' ), array( $auto, esc_js( $effect ), esc_js( $premium_motion ) ), $js );
    }
}

