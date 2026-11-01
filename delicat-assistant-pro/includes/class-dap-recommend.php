<?php
/**
 * Product recommendations.
 *
 * Three sources, in order of how much the customer has told us:
 *   1. their own purchase history (what they buy again, and what sells well next to it)
 *   2. the product they are looking at right now (cross-sells, up-sells, same category)
 *   3. what the store sells most (guests, and anyone with no history)
 *
 * Everything is cached: a recommendation is never worth a slow chat. Per-customer
 * results live 30 minutes, per-product and store-wide results 12 and 6 hours.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DSA2_Recommend {

    const CACHE_PREFIX = 'dap_reco_503_';

    public static function init() {
        // A new sale changes what "best selling" means, but not often enough to
        // justify recomputing per request — the caches simply expire.
        add_action( 'woocommerce_update_product', array( __CLASS__, 'flush_store' ) );
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'flush_customer_of_order' ) );
    }

    public static function enabled() {
        if ( ! function_exists( 'wc_get_products' ) || ! DSA2_Products::enabled() ) return false;
        $s = get_option( 'dap_settings', array() );
        return ! isset( $s['recommend_enabled'] ) || ! empty( $s['recommend_enabled'] );
    }

    /**
     * 6.6.3 — lists built for the app leave out the products the app hides
     * (DSA2_Products::hidden_for_app()), so the app and the website cache apart: a list
     * the website built must not reach the app, and an app list must not shrink the site's.
     */
    private static function surface_key() {
        return DSA2_Products::app_surface() ? 'app_' : '';
    }

    /** Every list is cached once per language and surface, so a flush clears all six. */
    private static function forget( $base ) {
        $app = self::CACHE_PREFIX . 'app_' . substr( $base, strlen( self::CACHE_PREFIX ) );
        foreach ( array( 'fr', 'en', 'ht' ) as $lang ) {
            delete_transient( $base . '_' . $lang );
            delete_transient( $app . '_' . $lang );
        }
    }

    public static function flush_store() {
        self::forget( self::CACHE_PREFIX . 'top' );
    }

    public static function flush_customer_of_order( $order_id ) {
        if ( ! function_exists( 'wc_get_order' ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $uid = (int) $order->get_customer_id();
        if ( ! $uid ) return;
        // A new purchase is exactly when "recommended for you" is most wrong.
        self::forget( self::CACHE_PREFIX . 'u' . $uid );
        delete_transient( self::CACHE_PREFIX . 'h' . $uid );
    }

    private static function t( $lang, $fr, $en, $ht ) {
        return 'en' === $lang ? $en : ( 'ht' === $lang ? $ht : $fr );
    }

    /* ---------------------------------------------------------------- sources */

    /**
     * Best sellers, optionally restricted to categories and excluding ids.
     * Over-fetches, because is_public() drops hidden and protected products later.
     */
    public static function best_sellers( $limit = 4, $exclude = array(), $category_ids = array() ) {
        $args = array(
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => max( 1, (int) $limit ) * 3,
            'fields'              => 'ids',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'meta_key'            => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'orderby'             => 'meta_value_num',
            'order'               => 'DESC',
        );
        if ( $exclude ) $args['post__not_in'] = array_map( 'absint', $exclude );
        if ( $category_ids ) {
            $args['tax_query'] = array( array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array_map( 'absint', $category_ids ),
            ) );
        }
        $query = new WP_Query( $args );
        return array_map( 'absint', (array) $query->posts );
    }

    /** Newest products — last resort when nothing has sold yet. */
    private static function newest( $limit = 4, $exclude = array() ) {
        $query = new WP_Query( array(
            'post_type' => 'product', 'post_status' => 'publish',
            'posts_per_page' => max( 1, (int) $limit ) * 2, 'fields' => 'ids',
            'no_found_rows' => true, 'ignore_sticky_posts' => true,
            'orderby' => 'date', 'order' => 'DESC',
            'post__not_in' => $exclude ? array_map( 'absint', $exclude ) : array(),
        ) );
        return array_map( 'absint', (array) $query->posts );
    }

    /**
     * What this customer bought, most frequent first, plus the categories involved.
     * Reads at most 10 recent paid orders — enough to know their taste, cheap to run.
     */
    private static function history( $user_id ) {
        $cache = get_transient( self::CACHE_PREFIX . 'h' . (int) $user_id );
        if ( is_array( $cache ) ) return $cache;

        $bought = array(); $cats = array();
        if ( function_exists( 'wc_get_orders' ) ) {
            $orders = wc_get_orders( array(
                'customer_id' => (int) $user_id, 'limit' => 10,
                'status' => array( 'completed', 'processing' ),
                'orderby' => 'date', 'order' => 'DESC',
            ) );
            foreach ( (array) $orders as $order ) {
                if ( ! is_a( $order, 'WC_Order' ) ) continue;
                foreach ( $order->get_items() as $item ) {
                    $pid = (int) $item->get_product_id();
                    if ( ! $pid ) continue;
                    $bought[ $pid ] = isset( $bought[ $pid ] ) ? $bought[ $pid ] + 1 : 1;
                }
            }
            arsort( $bought );
            foreach ( array_slice( array_keys( $bought ), 0, 6 ) as $pid ) {
                $terms = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
                if ( is_wp_error( $terms ) ) continue;
                foreach ( (array) $terms as $term_id ) $cats[ (int) $term_id ] = true;
            }
        }
        $out = array( 'bought' => $bought, 'cats' => array_keys( $cats ) );
        set_transient( self::CACHE_PREFIX . 'h' . (int) $user_id, $out, 30 * MINUTE_IN_SECONDS );
        return $out;
    }

    /* --------------------------------------------------------------- payloads */

    /**
     * "Pour vous" — the personalised list.
     * Signed in with history: things they reorder, then best sellers in their categories.
     * Otherwise: the store's best sellers.
     */
    public static function for_customer( $lang = 'fr', $limit = 4 ) {
        if ( ! self::enabled() ) return null;
        $uid = get_current_user_id();
        $key = self::CACHE_PREFIX . self::surface_key() . ( $uid ? 'u' . $uid : 'top' ) . '_' . $lang;
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;

        $ids = array(); $personal = false;
        if ( $uid ) {
            $history = self::history( $uid );
            if ( ! empty( $history['bought'] ) ) {
                $personal = true;
                // Digital top-ups are repeat purchases: what they buy most comes first.
                $ids = array_slice( array_keys( $history['bought'] ), 0, 2 );
                if ( ! empty( $history['cats'] ) ) {
                    $ids = array_merge( $ids, self::best_sellers( $limit, $ids, $history['cats'] ) );
                }
            }
        }
        if ( count( $ids ) < $limit ) $ids = array_merge( $ids, self::best_sellers( $limit, $ids ) );
        if ( count( $ids ) < $limit ) $ids = array_merge( $ids, self::newest( $limit, $ids ) );
        $ids = array_slice( array_values( array_unique( array_map( 'absint', $ids ) ) ), 0, $limit * 2 );

        $payload = DSA2_Products::payload( $ids, $lang, 'reco' );
        if ( ! $payload ) return null;
        $payload['meta']['items'] = array_slice( $payload['meta']['items'], 0, $limit );
        $payload['body'] = 'reco:' . ( $personal ? 'you' : 'top' ) . ':' . implode( '-', wp_list_pluck( $payload['meta']['items'], 'id' ) );
        $payload['meta']['title'] = $personal
            ? self::t( $lang, 'Recommandé pour vous', 'Recommended for you', 'Rekòmande pou ou' )
            : self::t( $lang, 'Les plus achetés', 'Best sellers', 'Sa ki pi vann yo' );
        $payload['meta']['intro'] = $personal
            ? self::t( $lang, 'D’après vos achats récents.', 'Based on your recent orders.', 'Dapre sa ou te achte dènyèman.' )
            : ( is_user_logged_in()
                ? self::t( $lang, 'Ce que les clients Delicat prennent le plus.', 'What Delicat customers buy most.', 'Sa kliyan Delicat yo pi achte.' )
                : self::t( $lang, 'Connectez-vous pour des suggestions basées sur vos achats.', 'Sign in for suggestions based on your own orders.', 'Konekte pou jwenn sijesyon dapre pwòp acha w yo.' ) );
        $payload['meta']['help'] = self::t( $lang, 'Vous cherchez autre chose ?', 'Looking for something else?', 'W ap chèche yon lòt bagay?' );
        $payload['meta']['chips'] = self::chips( $lang );

        set_transient( $key, $payload, $personal ? 30 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );
        return $payload;
    }

    /**
     * "Souvent pris ensemble" — what goes with the product being viewed or found.
     * Cross-sells and up-sells set in WooCommerce win; then the same category.
     */
    public static function related( $product_id, $lang = 'fr', $limit = 4 ) {
        if ( ! self::enabled() ) return null;
        $product_id = absint( $product_id );
        if ( ! $product_id || ! DSA2_Products::is_public( $product_id ) || DSA2_Products::hidden_for_app( $product_id ) ) return null;

        $key = self::CACHE_PREFIX . self::surface_key() . 'p' . $product_id . '_' . $lang;
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;

        $product = wc_get_product( $product_id );
        if ( ! $product ) return null;
        $ids = array_merge( (array) $product->get_cross_sell_ids(), (array) $product->get_upsell_ids() );
        if ( count( $ids ) < $limit ) {
            $cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $cats ) && $cats ) $ids = array_merge( $ids, self::best_sellers( $limit, array_merge( array( $product_id ), $ids ), (array) $cats ) );
        }
        if ( count( $ids ) < $limit ) $ids = array_merge( $ids, self::best_sellers( $limit, array_merge( array( $product_id ), $ids ) ) );
        $ids = array_values( array_diff( array_unique( array_map( 'absint', $ids ) ), array( $product_id ) ) );
        if ( ! $ids ) return null;

        $payload = DSA2_Products::payload( array_slice( $ids, 0, $limit * 2 ), $lang, 'reco-p' );
        if ( ! $payload ) return null;
        $payload['meta']['items'] = array_slice( $payload['meta']['items'], 0, $limit );
        $payload['body'] = 'reco:p' . $product_id;
        $payload['meta']['title'] = self::t( $lang, 'Souvent pris ensemble', 'Often bought together', 'Yo konn pran yo ansanm' );
        $payload['meta']['intro'] = sprintf(
            self::t( $lang, 'Avec %s, les clients prennent aussi :', 'With %s, customers also take:', 'Ak %s, kliyan yo konn pran tou:' ),
            wp_strip_all_tags( $product->get_name() )
        );
        $payload['meta']['help'] = self::t( $lang, 'Besoin d’un conseil ?', 'Need advice?', 'Ou bezwen yon konsèy?' );
        $payload['meta']['chips'] = self::chips( $lang );

        set_transient( $key, $payload, 12 * HOUR_IN_SECONDS );
        return $payload;
    }

    private static function chips( $lang ) {
        return array(
            array( 'label' => self::t( $lang, 'Comment commander ?', 'How to order?', 'Kijan pou kòmande?' ), 'action' => 'guide', 'value' => 'buy' ),
            array( 'label' => self::t( $lang, 'Recharger mon solde', 'Top up my balance', 'Rechaje balans mwen' ), 'action' => 'guide', 'value' => 'recharge' ),
            array( 'label' => self::t( $lang, 'Tous les produits', 'All products', 'Tout pwodwi yo' ), 'action' => 'quick', 'value' => 'products' ),
        );
    }

    /** A chip that opens the suggestions, added under product and order answers. */
    public static function chip( $lang, $product_id = 0 ) {
        if ( ! self::enabled() ) return null;
        return $product_id
            ? array( 'label' => self::t( $lang, 'Produits similaires', 'Similar products', 'Pwodwi menm jan' ), 'action' => 'quick', 'value' => 'reco_' . absint( $product_id ) )
            : array( 'label' => self::t( $lang, 'Recommandé pour vous', 'Recommended for you', 'Rekòmande pou ou' ), 'action' => 'quick', 'value' => 'recommend' );
    }

    /** "que dois-je acheter", "sijere m yon bagay", "what should I buy"… */
    public static function is_recommendation_question( $normalized ) {
        return (bool) preg_match(
            '/(recommand|rekomand|conseill|konsey|suggest|sijere|sijesyon|que dois je (acheter|prendre)|quoi acheter|kisa pou m achte|kisa pou n achte|what should i (buy|get)|meilleur (produit|vente|offre)|pi bon (pwodwi|deal)|best seller|plus (achete|vendu)|popilè|populaire)/',
            $normalized
        );
    }
}
