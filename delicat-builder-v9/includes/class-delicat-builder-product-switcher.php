<?php
/**
 * Delicat Product / Service / Region Switcher.
 *
 * Links several real WooCommerce products into one smooth storefront switcher.
 * Each option keeps WooCommerce authoritative for price, stock, fields,
 * variations and add-to-cart behavior; the frontend only swaps the rendered
 * product fragment from the target product page.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Delicat_Builder_V9_Product_Switcher {
    public const META = '_delicat_product_switcher_v1';
    public const OWNER_META = '_delicat_product_switcher_owner';
    public const VERSION = '9.3.7';
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) { return; }
        self::$booted = true;

        add_action( 'delicat_api_switcher_changed', array( __CLASS__, 'supplier_changed' ) );
        add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post_product', array( __CLASS__, 'save_product' ), 20, 3 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );

        // WooCommerce fires this immediately before the native add-to-cart form,
        // which places the switcher above variations inside Product Builder too.
        add_action( 'woocommerce_before_add_to_cart_form', array( __CLASS__, 'render_frontend' ), 2 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ), 24 );
    }

    public static function supplier_changed(): void {
        if ( class_exists( 'Delicat_Builder_V9_Cache', false ) ) {
            Delicat_Builder_V9_Cache::bump_version();
        }
        do_action( 'litespeed_purge_all' );
        if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
    }

    public static function candidate_ids( array $item ): array {
        $ids = array( is_scalar( $item['product_id'] ?? 0 ) ? absint( $item['product_id'] ?? 0 ) : 0 );
        $extra = $item['product_ids'] ?? array();
        if ( ! is_array( $extra ) ) { $extra = preg_split( '/[\s,;]+/', is_scalar( $extra ) ? (string) $extra : '' ); }
        $extra = array_filter( $extra, 'is_scalar' );
        return array_slice( array_values( array_unique( array_filter( array_merge( $ids, array_map( 'absint', $extra ) ) ) ) ), 0, 10 );
    }

    private static function linked_ids( array $options ): array {
        $ids = array();
        foreach ( $options as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            foreach ( self::candidate_ids( $item ) as $id ) {
                $ids[] = $id;
                if ( is_callable( array( 'Delicat_API_Switcher', 'group_for' ) ) ) { // 9.3.8: API Switcher < 1.3.0 has no group_for().
                    $g = Delicat_API_Switcher::group_for( $id );
                    if ( $g ) { $ids[] = absint( $g['fz'] ); $ids[] = absint( $g['s2t'] ); }
                }
            }
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    public static function defaults(): array {
        return array(
            'enabled'       => 'no',
            'type'          => 'services',
            'title'         => 'Choisissez votre région',
            'show_title'    => 'yes',
            'default'       => 'first',
            'style'         => 'pills',
            'animation'     => 'fade',
            'mobile_scroll' => 'yes',
            'update_url'    => 'yes',
            'preload'       => 'yes',
            'options'       => array(),
        );
    }

    public static function settings_for( int $product_id ): array {
        $raw = get_post_meta( $product_id, self::META, true );
        if ( ! is_array( $raw ) && is_callable( array( 'Delicat_API_Switcher', 'group_for' ) ) ) {
            $g = Delicat_API_Switcher::group_for( $product_id );
            if ( $g ) {
                foreach ( array( $g['fz'], $g['s2t'] ) as $sibling ) {
                    $candidate = get_post_meta( absint( $sibling ), self::META, true );
                    if ( is_array( $candidate ) ) { $raw = $candidate; break; }
                }
            }
        }
        $raw = is_array( $raw ) ? $raw : array();
        $out = wp_parse_args( $raw, self::defaults() );
        $out['options'] = is_array( $out['options'] ?? null ) ? $out['options'] : array();
        return $out;
    }

    public static function add_meta_box(): void {
        add_meta_box(
            'delicat-product-service-region-switcher',
            'Product, Service & Region Switcher',
            array( __CLASS__, 'meta_box' ),
            'product',
            'normal',
            'default'
        );
    }

    private static function option_row( array $item = array(), int $index = 0 ): void {
        $item = wp_parse_args( $item, array( 'label' => '', 'product_id' => 0, 'icon' => '' ) );
        $pid = absint( $item['product_id'] );
        $product_name = '';
        if ( $pid ) {
            $p = get_post( $pid );
            if ( $p instanceof WP_Post && 'product' === $p->post_type ) {
                $product_name = $p->post_title;
            }
        }
        ?>
        <div class="dpsr-admin-row" data-index="<?php echo esc_attr( $index ); ?>">
            <span class="dpsr-drag" title="Déplacer" aria-hidden="true">⋮⋮</span>
            <div class="dpsr-field dpsr-label-field">
                <label>Nom du bouton</label>
                <input type="text" maxlength="60" name="dpsr[options][<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $item['label'] ); ?>" placeholder="Free Fire (EU)">
            </div>
            <div class="dpsr-field dpsr-id-field">
                <label>ID principal WooCommerce</label>
                <input type="number" min="1" step="1" name="dpsr[options][<?php echo esc_attr( $index ); ?>][product_id]" value="<?php echo $pid ? esc_attr( $pid ) : ''; ?>" placeholder="8379">
                <label>Autres IDs fournisseurs (virgules)</label>
                <input type="text" maxlength="120" name="dpsr[options][<?php echo esc_attr( $index ); ?>][product_ids]" value="<?php echo esc_attr( implode( ', ', array_diff( self::candidate_ids( $item ), array( $pid ) ) ) ); ?>" placeholder="8450, 9120">
                <small class="dpsr-product-name"><?php echo esc_html( $product_name ); ?></small>
            </div>
            <div class="dpsr-field dpsr-icon-field">
                <label>Icône / emoji</label>
                <input type="text" maxlength="12" name="dpsr[options][<?php echo esc_attr( $index ); ?>][icon]" value="<?php echo esc_attr( $item['icon'] ); ?>" placeholder="⚡">
            </div>
            <button type="button" class="button-link-delete dpsr-remove">Supprimer</button>
        </div>
        <?php
    }

    public static function meta_box( WP_Post $post ): void {
        $s = self::settings_for( (int) $post->ID );
        wp_nonce_field( 'delicat_product_switcher_save', 'delicat_product_switcher_nonce' );
        ?>
        <div class="dpsr-admin-box">
            <label class="dpsr-enable"><input type="checkbox" name="dpsr[enabled]" value="yes" <?php checked( $s['enabled'], 'yes' ); ?>> <strong>Activer le Product / Service / Region Switcher</strong></label>
            <p class="description">Utilisez ce module pour USDT/Binance, ou pour un même service disponible dans plusieurs régions comme Free Fire Amérique, LATAM, Europe, MENA, Asie et Afrique. Chaque bouton charge son propre produit WooCommerce avec son prix, stock, champs, variations et panier.</p>

            <div class="dpsr-admin-grid">
                <div class="dpsr-field">
                    <label>Type de sélecteur</label>
                    <select name="dpsr[type]">
                        <option value="services" <?php selected( $s['type'], 'services' ); ?>>Services (USDT, Binance…)</option>
                        <option value="regions" <?php selected( $s['type'], 'regions' ); ?>>Régions / serveurs (Free Fire…)</option>
                        <option value="platforms" <?php selected( $s['type'], 'platforms' ); ?>>Plateformes / réseaux</option>
                        <option value="custom" <?php selected( $s['type'], 'custom' ); ?>>Personnalisé</option>
                    </select>
                </div>
                <div class="dpsr-field">
                    <label>Titre affiché au-dessus des boutons</label>
                    <input type="text" maxlength="80" name="dpsr[title]" value="<?php echo esc_attr( $s['title'] ); ?>" placeholder="Choisissez votre région">
                    <label class="dpsr-inline-check"><input type="checkbox" name="dpsr[show_title]" value="yes" <?php checked( $s['show_title'], 'yes' ); ?>> Afficher ce titre</label>
                </div>
                <div class="dpsr-field">
                    <label>Option ouverte par défaut</label>
                    <select name="dpsr[default]">
                        <option value="first" <?php selected( $s['default'], 'first' ); ?>>Premier service de la liste</option>
                        <option value="current" <?php selected( $s['default'], 'current' ); ?>>Produit actuellement ouvert</option>
                    </select>
                </div>
                <div class="dpsr-field">
                    <label>Style des boutons</label>
                    <select name="dpsr[style]">
                        <option value="pills" <?php selected( $s['style'], 'pills' ); ?>>Pilules modernes</option>
                        <option value="segmented" <?php selected( $s['style'], 'segmented' ); ?>>Segmenté premium</option>
                        <option value="cards" <?php selected( $s['style'], 'cards' ); ?>>Mini cartes</option>
                    </select>
                </div>
                <div class="dpsr-field">
                    <label>Animation</label>
                    <select name="dpsr[animation]">
                        <option value="fade" <?php selected( $s['animation'], 'fade' ); ?>>Fondu doux</option>
                        <option value="slide" <?php selected( $s['animation'], 'slide' ); ?>>Glissement doux</option>
                        <option value="none" <?php selected( $s['animation'], 'none' ); ?>>Aucune</option>
                    </select>
                </div>
                <div class="dpsr-field dpsr-checks">
                    <label><input type="checkbox" name="dpsr[mobile_scroll]" value="yes" <?php checked( $s['mobile_scroll'], 'yes' ); ?>> Défilement horizontal mobile</label>
                    <label><input type="checkbox" name="dpsr[update_url]" value="yes" <?php checked( $s['update_url'], 'yes' ); ?>> Mettre à jour l’URL sans recharger</label>
                    <label><input type="checkbox" name="dpsr[preload]" value="yes" <?php checked( $s['preload'], 'yes' ); ?>> Précharger intelligemment les autres services</label>
                </div>
            </div>

            <div class="dpsr-admin-head"><span>Nom du bouton</span><span>ID du produit WooCommerce</span><span>Icône / emoji</span><span></span></div>
            <div id="dpsr-admin-rows">
                <?php
                $items = array_slice( array_values( $s['options'] ), 0, 30 );
                if ( ! $items ) { $items = array( array( 'label' => '', 'product_id' => 0, 'icon' => '' ) ); }
                foreach ( $items as $i => $item ) { self::option_row( is_array( $item ) ? $item : array(), (int) $i ); }
                ?>
            </div>
            <div class="dpsr-admin-actions">
                <button type="button" class="button" id="dpsr-add-option">+ Ajouter une option</button>
                <button type="button" class="button" id="dpsr-free-fire">Préparer les régions Free Fire</button>
            </div>
            <p class="description">Jusqu’à 30 options et 10 IDs WooCommerce par option. Les produits reliés dans API Switcher suivent automatiquement le fournisseur actif, même si l’ID principal est en brouillon. Seuls les produits publiés, visibles, achetables et en stock apparaissent. Sans groupe API, le premier ID disponible est utilisé. Le bouton “Préparer les régions Free Fire” ajoute Amérique, LATAM, Europe, MENA, Asie et Afrique ; indiquez ensuite l’ID WooCommerce correspondant à chaque région.</p>
        </div>
        <script type="text/html" id="tmpl-dpsr-row"><?php self::option_row( array(), 999999 ); ?></script>
        <?php
    }

    private static function sanitize_settings( array $input ): array {
        $d = self::defaults();
        $type = sanitize_key( $input['type'] ?? 'services' );
        if ( ! in_array( $type, array( 'services', 'regions', 'platforms', 'custom' ), true ) ) { $type = 'services'; }
        $style = sanitize_key( $input['style'] ?? 'pills' );
        if ( ! in_array( $style, array( 'pills', 'segmented', 'cards' ), true ) ) { $style = 'pills'; }
        $animation = sanitize_key( $input['animation'] ?? 'fade' );
        if ( ! in_array( $animation, array( 'fade', 'slide', 'none' ), true ) ) { $animation = 'fade'; }
        $default = sanitize_key( $input['default'] ?? 'first' );
        if ( ! in_array( $default, array( 'first', 'current' ), true ) ) { $default = 'first'; }

        $options = array();
        $seen = array();
        $raw_options = isset( $input['options'] ) && is_array( $input['options'] ) ? $input['options'] : array();
        foreach ( array_slice( $raw_options, 0, 30 ) as $raw ) {
            if ( ! is_array( $raw ) ) { continue; }
            $ids = array();
            foreach ( self::candidate_ids( $raw ) as $id ) {
                $post = get_post( $id );
                if ( $post instanceof WP_Post && 'product' === $post->post_type && 'trash' !== $post->post_status && current_user_can( 'edit_post', $id ) ) { $ids[] = $id; }
            }
            $pid = $ids[0] ?? 0;
            $label = substr( sanitize_text_field( $raw['label'] ?? '' ), 0, 60 );
            $icon = substr( sanitize_text_field( $raw['icon'] ?? '' ), 0, 12 );
            if ( $pid <= 0 ) {
                // Keep prepared/incomplete rows in the editor so the merchant
                // can save a Free Fire region list before every product ID is known.
                if ( '' !== $label || '' !== $icon ) {
                    $options[] = array( 'label' => $label, 'product_id' => 0, 'icon' => $icon );
                }
                continue;
            }
            if ( isset( $seen[ $pid ] ) ) { continue; }
            $post = get_post( $pid );
            if ( ! $post instanceof WP_Post || 'product' !== $post->post_type || 'trash' === $post->post_status ) { continue; }
            $seen[ $pid ] = true;
            if ( '' === $label ) { $label = get_the_title( $pid ); }
            $options[] = array( 'label' => $label, 'product_id' => $pid, 'product_ids' => array_slice( $ids, 1 ), 'icon' => $icon );
        }

        return array(
            'enabled'       => ! empty( $input['enabled'] ) ? 'yes' : 'no',
            'type'          => $type,
            'title'         => substr( sanitize_text_field( $input['title'] ?? $d['title'] ), 0, 80 ),
            'show_title'    => ! empty( $input['show_title'] ) ? 'yes' : 'no',
            'default'       => $default,
            'style'         => $style,
            'animation'     => $animation,
            'mobile_scroll' => ! empty( $input['mobile_scroll'] ) ? 'yes' : 'no',
            'update_url'    => ! empty( $input['update_url'] ) ? 'yes' : 'no',
            'preload'       => ! empty( $input['preload'] ) ? 'yes' : 'no',
            'options'       => $options,
        );
    }

    public static function save_product( int $post_id, WP_Post $post, bool $update ): void {
        unset( $update );
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( wp_is_post_revision( $post_id ) || 'product' !== $post->post_type ) { return; }
        if ( ! current_user_can( 'edit_product', $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) { return; }
        if ( empty( $_POST['delicat_product_switcher_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['delicat_product_switcher_nonce'] ) ), 'delicat_product_switcher_save' ) ) { return; }

        $input = isset( $_POST['dpsr'] ) && is_array( $_POST['dpsr'] ) ? wp_unslash( $_POST['dpsr'] ) : array();
        $previous = self::settings_for( $post_id );
        $settings = self::sanitize_settings( $input );

        $old_ids = self::linked_ids( $previous['options'] );
        $new_ids = self::linked_ids( $settings['options'] );
        foreach ( array_diff( $old_ids, $new_ids ) as $removed_id ) {
            if ( absint( get_post_meta( $removed_id, self::OWNER_META, true ) ) === $post_id && current_user_can( 'edit_post', $removed_id ) ) {
                delete_post_meta( $removed_id, self::META );
                delete_post_meta( $removed_id, self::OWNER_META );
            }
        }

        update_post_meta( $post_id, self::META, $settings );
        update_post_meta( $post_id, self::OWNER_META, $post_id );

        // One configuration controls the complete linked group. Copying it to
        // listed products means every region/service keeps the same switcher
        // after a same-page transition without asking the merchant to configure
        // the same group repeatedly.
        foreach ( $new_ids as $target_id ) {
            if ( $target_id <= 0 || $target_id === $post_id ) { continue; }
            if ( current_user_can( 'edit_post', $target_id ) ) {
                update_post_meta( $target_id, self::META, $settings );
                update_post_meta( $target_id, self::OWNER_META, $post_id );
            }
        }

        if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
            Delicat_Builder_V9_Cache::bump_version();
        }
    }

    public static function admin_assets( string $hook ): void {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'product' !== $screen->post_type ) { return; }
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_enqueue_style( 'delicat-product-switcher-admin', DELICAT_BUILDER_V9_URL . 'assets/css/product-switcher-admin.css', array(), self::VERSION );
        wp_enqueue_script( 'delicat-product-switcher-admin', DELICAT_BUILDER_V9_URL . 'assets/js/product-switcher-admin.js', array( 'jquery', 'jquery-ui-sortable' ), self::VERSION, true );
    }

    private static function visible_options( array $settings, int $current_id ): array {
        $out = array();
        $seen = array();
        /* pro.46: up to 30 sibling products were each loaded with their own queries. */
        if ( class_exists( 'Delicat_Builder_V9_Query_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Query_Cache', 'prime_products' ) ) ) {
            $prime_ids = self::linked_ids( $settings['options'] );
            Delicat_Builder_V9_Query_Cache::prime_products( $prime_ids );
        }
        foreach ( array_slice( $settings['options'], 0, 30 ) as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $pid = 0;
            foreach ( self::candidate_ids( $item ) as $candidate ) {
                $target = is_callable( array( 'Delicat_API_Switcher', 'resolve_product' ) ) ? Delicat_API_Switcher::resolve_product( $candidate ) : $candidate;
                if ( ! $target ) { continue; }
                $product = wc_get_product( $target );
                if ( ! $product || 'publish' !== $product->get_status() || post_password_required( $target ) || ! $product->is_visible() || ! $product->is_in_stock() || ! $product->is_purchasable() ) { continue; }
                $pid = $target;
                break;
            }
            if ( ! $pid || isset( $seen[ $pid ] ) ) { continue; }
            $seen[ $pid ] = true;
            $out[] = array(
                'product_id' => $pid,
                'label'      => '' !== trim( (string) ( $item['label'] ?? '' ) ) ? (string) $item['label'] : $product->get_name(),
                'icon'       => (string) ( $item['icon'] ?? '' ),
                'url'        => is_callable( array( 'Delicat_API_Switcher', 'product_url' ) ) ? Delicat_API_Switcher::product_url( $pid ) : $product->get_permalink(),
                'active'     => $pid === $current_id,
            );
        }
        return $out;
    }

    public static function render_frontend(): void {
        if ( ! function_exists( 'wc_get_product' ) ) { return; }
        $current_id = get_the_ID();
        if ( ! $current_id && function_exists( 'get_queried_object_id' ) ) { $current_id = get_queried_object_id(); }
        $current_id = absint( $current_id );
        if ( ! $current_id ) { return; }
        $settings = self::settings_for( $current_id );
        if ( 'yes' !== $settings['enabled'] ) { return; }
        $options = self::visible_options( $settings, $current_id );
        if ( count( $options ) < 1 ) { return; }

        $has_active = false;
        foreach ( $options as $o ) { if ( ! empty( $o['active'] ) ) { $has_active = true; break; } }
        if ( ! $has_active && 'first' === $settings['default'] && isset( $options[0] ) ) { $options[0]['active'] = true; }

        $classes = array( 'dpsr-switcher', 'is-style-' . sanitize_html_class( $settings['style'] ), 'is-animation-' . sanitize_html_class( $settings['animation'] ) );
        if ( 'yes' === $settings['mobile_scroll'] ) { $classes[] = 'has-mobile-scroll'; }
        ?>
        <section class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-product-id="<?php echo esc_attr( $current_id ); ?>" data-update-url="<?php echo esc_attr( $settings['update_url'] ); ?>" data-preload="<?php echo esc_attr( $settings['preload'] ); ?>" data-animation="<?php echo esc_attr( $settings['animation'] ); ?>" aria-label="<?php echo esc_attr( $settings['title'] ?: 'Choisissez une option' ); ?>">
            <?php if ( 'yes' === $settings['show_title'] && '' !== trim( (string) $settings['title'] ) ) : ?>
                <div class="dpsr-switcher-title"><?php echo esc_html( $settings['title'] ); ?></div>
            <?php endif; ?>
            <div class="dpsr-switcher-track" role="tablist">
                <?php foreach ( $options as $item ) : ?>
                    <a class="dpsr-switcher-option<?php echo ! empty( $item['active'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $item['url'] ); ?>" data-product-id="<?php echo esc_attr( $item['product_id'] ); ?>" role="tab" aria-selected="<?php echo ! empty( $item['active'] ) ? 'true' : 'false'; ?>">
                        <?php if ( '' !== trim( $item['icon'] ) ) : ?><span class="dpsr-switcher-icon" aria-hidden="true"><?php echo esc_html( $item['icon'] ); ?></span><?php endif; ?>
                        <span><?php echo esc_html( $item['label'] ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="dpsr-switcher-progress" aria-hidden="true"><span></span></div>
            <?php if ( 'regions' === $settings['type'] ) : ?><p class="dpsr-region-warning">Assurez-vous de sélectionner la région correspondant à votre compte. Une recharge envoyée vers un mauvais serveur peut échouer.</p><?php endif; ?>
        </section>
        <?php
    }

    public static function frontend_assets(): void {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) { return; }
        $id = absint( get_queried_object_id() );
        if ( ! $id ) { return; }
        $settings = self::settings_for( $id );
        if ( 'yes' !== $settings['enabled'] || count( self::visible_options( $settings, $id ) ) < 1 ) { return; }

        // The target product can be variable even if the current one is simple.
        // Keeping Woo's native variation runtime available makes AJAX switching
        // safe without duplicating any cart or variation logic.
        if ( wp_script_is( 'wc-add-to-cart-variation', 'registered' ) ) {
            wp_enqueue_script( 'wc-add-to-cart-variation' );
        }
        wp_enqueue_style( 'delicat-product-switcher', DELICAT_BUILDER_V9_URL . 'assets/css/product-switcher.css', array(), self::VERSION );
        wp_enqueue_script( 'delicat-product-switcher', DELICAT_BUILDER_V9_URL . 'assets/js/product-switcher.js', array( 'jquery' ), self::VERSION, true );
    }
}

Delicat_Builder_V9_Product_Switcher::boot();
