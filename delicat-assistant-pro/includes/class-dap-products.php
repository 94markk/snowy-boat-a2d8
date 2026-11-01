<?php
/**
 * Product answers: matches what the customer typed against the catalogue
 * and replies with real prices, stock and links instead of a generic message.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DSA2_Products {

    const INDEX = 'dap_product_index';

    public static function init() {
        add_action( 'save_post_product', array( __CLASS__, 'flush' ) );
        add_action( 'woocommerce_update_product', array( __CLASS__, 'flush' ) );
        add_action( 'woocommerce_new_product', array( __CLASS__, 'flush' ) );
        add_action( 'trashed_post', array( __CLASS__, 'flush' ) );
    }

    public static function flush() { delete_transient( self::INDEX ); }

    /**
     * 6.6.3 — product names and categories the app never shows when the App API is not
     * there to say so (fallback of hidden_for_app()).
     */
    const APP_HIDDEN_PRODUCT_WORDS = '/\b(usdt|tether|crypto\w*|bitcoin|binance|exchange|finance|paypal|zelle|wise|meru)\b/i';

    /**
     * 6.6.3 — an automated answer stored in a conversation that names one of these is not
     * served to the app (DSA2_Data::client_messages()). Brand names only: « échange » or
     * « exchange » alone also mean replacing a defective item. WISE and Meru are the live
     * Exchange / Finance products whose names hold none of the other words (« Le prix de
     * WISE dépend du pack… »); the word boundaries keep « otherwise » and « demeure » out.
     */
    const APP_HIDDEN_ANSWER_WORDS = '/\b(usdt|tether|crypto\w*|bitcoin|binance|paypal|zelle|wise|meru)\b/i';

    /**
     * 6.6.3 — true when this request answers the Delicat app: the App API bridges its
     * /delicat-app/v1/ chat routes into this engine. False for the website widget. The
     * dsa2_app_surface filter can force either answer.
     */
    public static function app_surface() {
        $uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? $_GET['rest_route'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $app   = false !== strpos( $uri, '/delicat-app/v1/' ) || 0 === strpos( $route, '/delicat-app/v1/' );
        return (bool) apply_filters( 'dsa2_app_surface', $app );
    }

    /**
     * 6.6.3 — Google Play: a Personal developer account may not offer financial services,
     * so a product the app hides (the website's « Exchange » / « Finance » categories,
     * crypto) is never part of an answer served to the app. The App API's
     * Delicat_App_Catalog::product_is_hidden() is the one rule, owner setting included;
     * without it, the product name and the names and slugs of its categories and of their
     * parent categories are checked (Finance › Payoneer is Finance, as in the App API).
     * The website widget keeps answering about every public product.
     */
    public static function hidden_for_app( $id ) {
        $id = absint( $id );
        if ( ! $id || ! self::app_surface() ) return false;
        if ( class_exists( 'Delicat_App_Catalog' ) && is_callable( array( 'Delicat_App_Catalog', 'product_is_hidden' ) ) ) {
            return (bool) Delicat_App_Catalog::product_is_hidden( $id );
        }
        $names = array( function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '' );
        if ( function_exists( 'wp_get_post_terms' ) ) {
            $terms = wp_get_post_terms( $id, 'product_cat' );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $term ) {
                    if ( ! is_object( $term ) ) continue;
                    $names[] = ( $term->slug ?? '' ) . ' ' . ( $term->name ?? '' );
                    if ( empty( $term->term_id ) || ! function_exists( 'get_ancestors' ) || ! function_exists( 'get_term' ) ) continue;
                    foreach ( (array) get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' ) as $parent_id ) {
                        $parent = get_term( (int) $parent_id, 'product_cat' );
                        if ( is_object( $parent ) && ! is_wp_error( $parent ) ) $names[] = ( $parent->slug ?? '' ) . ' ' . ( $parent->name ?? '' );
                    }
                }
            }
        }
        $text = implode( ' ', $names );
        if ( function_exists( 'remove_accents' ) ) $text = remove_accents( $text );
        return (bool) preg_match( self::APP_HIDDEN_PRODUCT_WORDS, str_replace( array( '-', '_' ), ' ', $text ) );
    }

    public static function enabled() {
        if ( ! function_exists( 'wc_get_products' ) ) return false;
        $s = get_option( 'dap_settings', array() );
        return ! isset( $s['products_enabled'] ) || ! empty( $s['products_enabled'] );
    }

    public static function norm( $text ) {
        return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z0-9]+/', ' ', remove_accents( strtolower( (string) $text ) ) ) ) );
    }

    private static function stopwords() {
        return array( 'les', 'des', 'une', 'pour', 'avec', 'dans', 'sur', 'par', 'mon', 'mes', 'ton', 'the', 'and', 'for', 'you', 'your', 'with', 'nan', 'yon', 'pou', 'mwen', 'prix', 'price', 'combien', 'coute', 'konbyen', 'acheter', 'achte', 'buy' );
    }

    /** id => normalized title, cached (rebuilt on product save). */
    public static function index() {
        $idx = get_transient( self::INDEX );
        if ( is_array( $idx ) ) return $idx;
        $idx = array();
        if ( function_exists( 'wc_get_products' ) ) {
            $ids = wc_get_products( array( 'status' => 'publish', 'limit' => 400, 'return' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ) );
            foreach ( (array) $ids as $id ) {
                $title = get_the_title( $id );
                if ( ! $title ) continue;
                $idx[] = array( 'id' => (int) $id, 'n' => self::norm( $title ) );
            }
        }
        set_transient( self::INDEX, $idx, 6 * HOUR_IN_SECONDS );
        return $idx;
    }

    /** True when the wording itself asks about a product (price, availability, buying). */
    public static function is_product_question( $normalized ) {
        return (bool) preg_match( '/\b(prix|pri|tarif|coute|coûte|combien|konbyen|price|cost|how much|disponible|disponib|available|stock|vendez|vann|sell|acheter|achte|buy|produit|pwodwi|product|jeu|jwet|game|carte|kat|gift card)\b/', $normalized );
    }

    /**
     * Scores catalogue titles against the message.
     * A title token must appear in the message; longer tokens weigh more.
     */
    public static function find( $normalized, $limit = 4 ) {
        $message = self::norm( $normalized );
        if ( $message === '' ) return array();
        $words = array_unique( array_filter( explode( ' ', $message ) ) );
        if ( ! $words ) return array();
        $stop = self::stopwords();
        $scores = array();
        foreach ( self::index() as $row ) {
            $score = 0;
            foreach ( array_unique( explode( ' ', $row['n'] ) ) as $token ) {
                if ( strlen( $token ) < 3 || in_array( $token, $stop, true ) ) continue;
                if ( in_array( $token, $words, true ) ) $score += strlen( $token );
            }
            if ( $score >= 4 ) $scores[ $row['id'] ] = $score;
        }
        if ( ! $scores ) return array();
        arsort( $scores );
        return array_slice( array_keys( $scores ), 0, max( 1, (int) $limit ) );
    }

    /** Fallback WordPress search, used when the typed words match no title token. */
    public static function search( $term, $limit = 4 ) {
        if ( ! function_exists( 'wc_get_products' ) ) return array();
        $term = trim( wp_strip_all_tags( (string) $term ) );
        if ( DSA2_Compat::length( $term ) < 3 ) return array();
        // wc_get_products() silently ignores an 's' argument and returns the newest
        // products — which showed unrelated items as "matches". Use a real search.
        $query = new WP_Query( array(
            'post_type' => 'product', 'post_status' => 'publish',
            's' => DSA2_Compat::substr( $term, 0, 60 ), 'posts_per_page' => max( 1, (int) $limit ),
            'fields' => 'ids', 'no_found_rows' => true,
        ) );
        return array_map( 'absint', (array) $query->posts );
    }

    /**
     * Only genuinely public products may be surfaced from a client-supplied ID:
     * drafts, password-protected and catalog-hidden products stay private.
     */
    public static function is_public( $id ) {
        if ( ! function_exists( 'wc_get_product' ) ) return false;
        $product = wc_get_product( absint( $id ) );
        if ( ! $product || 'publish' !== $product->get_status() ) return false;
        $post = get_post( $product->get_id() );
        if ( $post && '' !== (string) $post->post_password ) return false;
        return in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog', 'search' ), true );
    }

    /**
     * WooCommerce price HTML may contain visually-hidden accessibility text such as
     * "Price range: ... through ...". wp_strip_all_tags() exposes that hidden copy and
     * joins it to the visible price. Build a compact, display-ready plain-text price instead.
     */
    private static function normalize_price_text( $html ) {
        $html = preg_replace(
            '#<(?:span|small)[^>]*class=(["\'])[^"\']*(?:screen-reader-text|screen-reader-response)[^"\']*\1[^>]*>.*?</(?:span|small)>#is',
            '',
            (string) $html
        );
        $text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
        $text = str_replace( "\xC2\xA0", ' ', $text );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return trim( (string) $text );
    }

    private static function money_text( $amount ) {
        if ( ! is_numeric( $amount ) ) return '';
        if ( function_exists( 'wc_price' ) ) return self::normalize_price_text( wc_price( (float) $amount ) );
        return trim( (string) $amount );
    }

    private static function plain_price( $product ) {
        if ( ! $product ) return '';

        if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' )
            && method_exists( $product, 'get_variation_price' ) ) {
            $min = $product->get_variation_price( 'min', true );
            $max = $product->get_variation_price( 'max', true );
            if ( '' !== (string) $min ) {
                $min_text = self::money_text( $min );
                $max_text = '' !== (string) $max ? self::money_text( $max ) : $min_text;
                return $max_text && $max_text !== $min_text ? $min_text . ' – ' . $max_text : $min_text;
            }
        }

        $raw = method_exists( $product, 'get_price' ) ? $product->get_price() : '';
        if ( '' !== (string) $raw ) {
            $display = function_exists( 'wc_get_price_to_display' )
                ? wc_get_price_to_display( $product, array( 'price' => (float) $raw ) )
                : (float) $raw;
            return self::money_text( $display );
        }

        return method_exists( $product, 'get_price_html' )
            ? self::normalize_price_text( $product->get_price_html() )
            : '';
    }

    private static function item( $id, $lang ) {
        if ( ! function_exists( 'wc_get_product' ) ) return null;
        // Same gate as a client-supplied id: title search and WP_Query both return
        // catalog-hidden products, which is how internal/licensed SKUs leaked into replies.
        if ( ! self::is_public( $id ) ) return null;
        if ( self::hidden_for_app( $id ) ) return null; // 6.6.3: Exchange / Finance / crypto stay off the app
        $product = wc_get_product( $id );
        if ( ! $product ) return null;

        $image = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
        if ( ! $image && function_exists( 'wc_placeholder_img_src' ) ) $image = wc_placeholder_img_src( 'woocommerce_thumbnail' );

        $desc = $product->get_short_description();
        if ( ! $desc ) $desc = $product->get_description();
        $desc = wp_trim_words( wp_strip_all_tags( (string) $desc ), 16, '…' );

        $in_stock = $product->is_in_stock();
        $stock_label = $in_stock
            ? ( $lang === 'en' ? 'In stock' : ( $lang === 'ht' ? 'Disponib' : 'Disponible' ) )
            : ( $lang === 'en' ? 'Out of stock' : ( $lang === 'ht' ? 'Pa disponib' : 'Épuisé' ) );

        return array(
            'id'      => (int) $id,
            'name'    => wp_strip_all_tags( $product->get_name() ),
            'price'   => self::plain_price( $product ),
            'desc'    => $desc,
            'image'   => $image ? esc_url_raw( $image ) : '',
            'url'     => $product->get_permalink(),
            'inStock' => (bool) $in_stock,
            'stock'   => $stock_label,
        );
    }

    /** product_list payload from a list of product ids. */
    public static function payload( $ids, $lang = 'fr', $term = '' ) {
        $items = array();
        foreach ( (array) $ids as $id ) {
            $item = self::item( $id, $lang );
            if ( $item ) $items[] = $item;
        }
        if ( ! $items ) return null;

        $title = count( $items ) === 1
            ? ( $lang === 'en' ? 'Product found' : ( $lang === 'ht' ? 'Pwodwi nou jwenn' : 'Produit trouvé' ) )
            : ( $lang === 'en' ? 'Products found' : ( $lang === 'ht' ? 'Pwodwi nou jwenn' : 'Produits trouvés' ) );

        return array(
            'type' => 'product_list',
            'body' => 'products:' . self::norm( $term . ' ' . implode( '-', wp_list_pluck( $items, 'id' ) ) ),
            'meta' => array(
                'title'   => $title,
                'intro'   => $lang === 'en' ? 'Tap a product to see every option and buy it.' : ( $lang === 'ht' ? 'Peze yon pwodwi pou wè tout opsyon yo epi achte.' : 'Touchez un produit pour voir les options et acheter.' ),
                'items'   => $items,
                'help'    => $lang === 'en' ? 'Not the right one?' : ( $lang === 'ht' ? 'Se pa sa ou t ap chèche?' : 'Ce n’est pas le bon produit ?' ),
                'buttons' => array(
                    array( 'label' => $lang === 'en' ? 'See all products' : ( $lang === 'ht' ? 'Gade tout pwodwi yo' : 'Voir tous les produits' ), 'action' => 'url', 'value' => 'shop' ),
                    array( 'label' => $lang === 'en' ? 'Ask an agent' : ( $lang === 'ht' ? 'Mande yon ajan' : 'Demander à un agent' ), 'action' => 'agent', 'value' => '' ),
                ),
                'chips' => self::chips( $items, $lang ),
            ),
        );
    }

    /**
     * Follow-up chips under a product answer. A single result gets "what goes with
     * this one"; a list gets the personalised suggestions.
     */
    private static function chips( $items, $lang ) {
        $chips = array(
            array( 'label' => $lang === 'en' ? 'How to order?' : ( $lang === 'ht' ? 'Kijan pou kòmande?' : 'Comment commander ?' ), 'action' => 'guide', 'value' => 'buy' ),
            array( 'label' => $lang === 'en' ? 'Top up my balance' : ( $lang === 'ht' ? 'Rechaje balans mwen' : 'Recharger mon solde' ), 'action' => 'guide', 'value' => 'recharge' ),
        );
        if ( class_exists( 'DSA2_Recommend' ) ) {
            $reco = DSA2_Recommend::chip( $lang, count( $items ) === 1 ? (int) $items[0]['id'] : 0 );
            if ( $reco ) array_unshift( $chips, $reco );
        }
        return $chips;
    }

    /** Featured (then newest) products for the "Products" quick action. */
    public static function highlights( $lang = 'fr', $limit = 4 ) {
        if ( ! function_exists( 'wc_get_products' ) ) return null;
        $ids = wc_get_products( array( 'status' => 'publish', 'limit' => $limit, 'return' => 'ids', 'featured' => true ) );
        if ( ! $ids ) {
            $ids = wc_get_products( array( 'status' => 'publish', 'limit' => $limit, 'return' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ) );
        }
        $payload = self::payload( $ids, $lang, 'highlights' );
        if ( ! $payload ) return null;
        $payload['meta']['title'] = $lang === 'en' ? 'Popular right now' : ( $lang === 'ht' ? 'Popilè kounye a' : 'Populaires en ce moment' );
        $payload['meta']['intro'] = $lang === 'en' ? 'Type a product name to see its price and availability.' : ( $lang === 'ht' ? 'Ekri non yon pwodwi pou wè pri l ak disponiblite l.' : 'Écrivez le nom d’un produit pour voir son prix et sa disponibilité.' );
        return $payload;
    }

    /** Full answer flow for a typed message. Returns a payload or null. */
    public static function reply( $normalized, $lang = 'fr' ) {
        if ( ! self::enabled() ) return null;
        // smart_reply() asks twice for the same message (once for price wording, once as a
        // late fallback). Without this the WP_Query fallback search ran twice per message.
        static $memo = array();
        $key = $lang . '|' . $normalized;
        if ( array_key_exists( $key, $memo ) ) return $memo[ $key ];
        $ids = self::find( $normalized );
        if ( ! $ids && self::is_product_question( $normalized ) ) {
            $ids = self::search( $normalized );
        }
        $memo[ $key ] = $ids ? self::payload( $ids, $lang, $normalized ) : null;
        return $memo[ $key ];
    }
}
