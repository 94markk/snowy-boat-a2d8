<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RC51 request-aware native runtime loader.
 * Heavy renderers are parsed only for pages that can actually use them.
 */
final class Delicat_Builder_V9_Runtime_Router {
    private static array $loaded = array();

    public static function boot(): void {
        add_action( 'pre_get_posts', array(__CLASS__, 'route_archive_query'), 2 );
        add_action( 'wp', array(__CLASS__, 'route_guarded'), -100 );
        add_action( 'init', array(__CLASS__, 'register_woo_block_styles'), 30 );
    }

	private static function safe_mode(): bool {
		return is_callable( array( 'Delicat_Builder_V9_Core', 'is_safe_mode' ) )
			&& Delicat_Builder_V9_Core::is_safe_mode();
	}

    /**
     * WooCommerce reads loop_shop_per_page while preparing the main query,
     * before the later `wp` action. Load the archive owners at that precise
     * point so the configured native page size changes SQL itself instead of
     * being applied too late. No work runs for secondary/admin queries.
     */
    public static function route_archive_query( $query ): void {
        /* RC18: same Throwable boundary as route_guarded(); this file is
         * bootstrap-critical, so an escaped exception here would deactivate. */
        try {
            if ( is_admin() || wp_doing_ajax() || self::safe_mode() || ! $query instanceof WP_Query || ! $query->is_main_query() ) return;
            $archive = $query->is_post_type_archive( 'product' );
            $taxonomy = false;
            foreach ( array( 'product_cat', 'product_tag', 'product_brand' ) as $name ) {
                if ( taxonomy_exists($name) && $query->is_tax($name) ) { $taxonomy = true; break; }
            }
            if ( ! $archive && ! $taxonomy ) return;
            self::load('includes/class-delicat-builder-archive-builder.php','Delicat_Builder_V9_Archive_Builder');
            self::load('includes/class-delicat-builder-woo-ui.php','Delicat_Builder_V9_Woo_UI');
        } catch ( Throwable $error ) {
            update_option('delicat_builder_v9_safe_mode', 1, false);
            update_option('delicat_builder_v9_safe_mode_meta', array('version'=>DELICAT_BUILDER_V9_VERSION,'tripped'=>gmdate('c'),'context'=>'runtime_archive_route'), false);
            unset($error);
        }
    }

    private static function load( string $relative, string $class = '' ): void {
        if ( isset(self::$loaded[$relative]) ) return;
        self::$loaded[$relative] = true;
        $already = $class && class_exists($class, false);
        if ( ! $already && function_exists('delicat_builder_v9_safe_require') ) delicat_builder_v9_safe_require($relative);
        if ( $class && ! $already && class_exists($class, false) && is_callable(array($class,'boot')) ) {
            try { $class::boot(); } catch (Throwable $e) {
                if ( is_callable( array('Delicat_Builder_V9_Core','enter_safe_mode') ) ) {
                    Delicat_Builder_V9_Core::enter_safe_mode('runtime_router');
                } else {
                    update_option('delicat_builder_v9_safe_mode', 1, false);
                    update_option('delicat_builder_v9_safe_mode_meta', array('version'=>DELICAT_BUILDER_V9_VERSION,'tripped'=>gmdate('c'),'context'=>'runtime_router'), false);
                }
            }
        }
    }

    private static function raw_enabled( string $option, bool $default = false ): bool {
        $raw = get_option($option, array());
        if ( ! is_array($raw) || ! array_key_exists('enabled',$raw) ) return $default;
        return ! empty($raw['enabled']);
    }

    /** A route-specific optimization must never be able to take down WooCommerce. */
    public static function route_guarded(): void {
        try {
            self::route();
        } catch ( Throwable $error ) {
            update_option('delicat_builder_v9_safe_mode', 1, false);
            update_option('delicat_builder_v9_safe_mode_meta', array('version'=>DELICAT_BUILDER_V9_VERSION,'tripped'=>gmdate('c'),'context'=>'runtime_route'), false);
            unset($error);
        }
    }

    public static function route(): void {
        if ( is_admin() || wp_doing_ajax() || self::safe_mode() ) return;

        /*
         * RC44: evaluated before the branches below, because each of them
         * returns. On a storefront whose front page is also the WooCommerce
         * shop page, the shop branch returned first and the review module was
         * never loaded — so the "LAISSER UN AVIS" button rendered (its markup
         * is compiled into the page) with no script behind it, and clicking it
         * did nothing. The same applied to a front page that is a product
         * taxonomy archive.
         */
        $review_surface = ( function_exists('is_front_page') && is_front_page() )
            || ( function_exists('is_account_page') && is_account_page() )
            || ( function_exists('is_order_received_page') && is_order_received_page() )
            /* RC63: the native product page carries the reviews block and its
             * "Laisser un avis" button — same default as Reviews::enqueue(). */
            || ( function_exists('is_product') && is_product() );
        if ( apply_filters( 'delicat_builder_v9_reviews_surface', $review_surface ) ) {
            self::load('includes/class-delicat-builder-reviews.php','Delicat_Builder_V9_Reviews');
        }

        // Native Product Engine is checked first so generic Woo presentation layers
        // are never loaded when V9 owns the single-product document.
        if ( function_exists('is_product') && is_product() ) {
            self::load('includes/class-delicat-builder-product-switcher.php','Delicat_Builder_V9_Product_Switcher');
            self::load('includes/class-delicat-builder-native-product.php','Delicat_Builder_V9_Native_Product');
            $id = (int) get_queried_object_id();
            $pb = class_exists('Delicat_Builder_V9_Native_Product', false) && Delicat_Builder_V9_Native_Product::is_active_product($id);
            if ( $pb ) {
                self::load('includes/class-delicat-builder-swatches.php','Delicat_Builder_V9_Swatches');
                /* pro.17: the checkout sheet. It decides for itself whether the
                 * merchant has it switched on for this product; loading it here
                 * only means the class exists in time to enqueue. */
                self::load('includes/class-delicat-builder-checkout-sheet.php','Delicat_Builder_V9_Checkout_Sheet');
            } else {
                self::load('includes/class-delicat-builder-woo-ui.php','Delicat_Builder_V9_Woo_UI');
                self::load('includes/class-delicat-builder-purchase-ui.php','Delicat_Builder_V9_Purchase_UI');
                if ( self::raw_enabled('delicat_builder_v9_design', false) ) self::load('includes/class-delicat-builder-design.php','Delicat_Builder_V9_Design');
            }
            return;
        }

        if ( (function_exists('is_shop') && is_shop()) || (function_exists('is_product_taxonomy') && is_product_taxonomy()) ) {
            self::load('includes/class-delicat-builder-archive-builder.php','Delicat_Builder_V9_Archive_Builder');
            self::load('includes/class-delicat-builder-woo-ui.php','Delicat_Builder_V9_Woo_UI');
            if ( self::raw_enabled('delicat_builder_v9_design', false) ) self::load('includes/class-delicat-builder-design.php','Delicat_Builder_V9_Design');
            return;
        }

        if ( (function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout()) ) {
            self::load('includes/class-delicat-builder-purchase-ui.php','Delicat_Builder_V9_Purchase_UI');
            self::load('includes/class-delicat-builder-purchase-native.php','Delicat_Builder_V9_Purchase_Native');
        }

        /*
         * RC51.59: the Site layer only styles Mon compte, the order-received
         * "Merci" page, search results and the 404 page. The previous
         * `raw_enabled` clause was always true on this storefront, which parsed
         * and booted the module on every request. Route it precisely instead —
         * including search and order-received, which the old condition only
         * reached because of that accidental always-on clause.
         */
        $site_surface = ( function_exists('is_account_page') && is_account_page() )
            || ( function_exists('is_404') && is_404() )
            || is_search()
            || ( function_exists('is_wc_endpoint_url') && is_wc_endpoint_url( 'order-received' ) );
        if ( $site_surface ) {
            self::load('includes/class-delicat-builder-site.php','Delicat_Builder_V9_Site');
        }

        if ( function_exists('is_singular') && is_singular('page') ) {
            $id = (int) get_queried_object_id();
            $post = $id ? get_post($id) : null;
            $content = $post instanceof WP_Post ? (string)$post->post_content : '';
			$native_type = $id ? sanitize_key( (string)get_post_meta($id, '_delicat_builder_v9_native_page', true) ) : '';
			$native_shortcode = $content && (
				has_shortcode($content, 'delicat_native_support')
				|| has_shortcode($content, 'delicat_native_terms')
				|| has_shortcode($content, 'delicat_native_privacy')
				|| has_shortcode($content, 'delicat_v8_hero')
			);
			if ( in_array($native_type, array('support','terms','privacy'), true) || $native_shortcode ) {
				self::load('includes/class-delicat-builder-native-pages.php','Delicat_Builder_V9_Native_Pages');
			}
            $managed = $id && (bool)get_post_meta($id, '_delicat_builder_v9_enabled', true);
            $carousel = $content && has_shortcode($content, 'delicat_product_carousel');
			if ( $managed ) {
				self::load('includes/class-delicat-builder-reviews.php','Delicat_Builder_V9_Reviews');
			}
            if ( $managed || $carousel ) {
                self::load('includes/class-delicat-builder-pages.php','Delicat_Builder_V9_Pages');
                self::load('includes/class-delicat-builder-media.php','Delicat_Builder_V9_Media');
                self::load('includes/class-delicat-builder-compiler.php','Delicat_Builder_V9_Compiler');
                self::load('includes/class-delicat-builder-badges.php','Delicat_Builder_V9_Badges');
                self::load('includes/class-delicat-builder-schema.php','Delicat_Builder_V9_Schema');
                self::load('includes/class-delicat-builder-renderer.php','Delicat_Builder_V9_Renderer');
                self::load('includes/class-delicat-builder-assets.php','Delicat_Builder_V9_Assets');
                self::load('includes/class-delicat-builder-heart-engine.php','Delicat_Builder_V9_Heart_Engine');
                self::load('includes/class-delicat-builder-carousel.php','Delicat_Builder_V9_Carousel');
            }
        }
    }

    /** Preserve Woo block styling without loading the 44 KB Release Center. */
    public static function register_woo_block_styles(): void {
        try {
            self::register_woo_block_styles_unguarded();
        } catch ( Throwable $error ) {
            unset($error); /* RC18: block styling is optional; never fatal in a critical file. */
        }
    }

    private static function register_woo_block_styles_unguarded(): void {
        if ( ! function_exists('wp_enqueue_block_style') || ! class_exists('WooCommerce') ) return;
        $woo = get_option('delicat_builder_v9_woo_ui', array());
        $purchase = get_option('delicat_builder_v9_purchase_ui', array());
        $map = array();
        if ( is_array($woo) && ! empty($woo['enabled']) && ! empty($woo['archive_enabled']) ) $map['woocommerce/product-collection'] = array('delicat-builder-v9-rc-product-collection','assets/css/blocks/product-collection.css');
        if ( is_array($purchase) && ! empty($purchase['enabled']) && empty($purchase['native_cart']) && ! empty($purchase['cart_style']) ) $map['woocommerce/cart'] = array('delicat-builder-v9-rc-cart-block','assets/css/blocks/cart.css');
        if ( is_array($purchase) && ! empty($purchase['enabled']) && empty($purchase['native_checkout']) && ! empty($purchase['checkout_style']) ) $map['woocommerce/checkout'] = array('delicat-builder-v9-rc-checkout-block','assets/css/blocks/checkout.css');
        foreach ($map as $block=>$spec) {
            $path = DELICAT_BUILDER_V9_DIR.$spec[1];
            if (!is_file($path)) continue;
            wp_enqueue_block_style($block,array('handle'=>$spec[0],'src'=>DELICAT_BUILDER_V9_URL.$spec[1],'path'=>$path,'ver'=>DELICAT_BUILDER_V9_VERSION));
        }
    }
}
