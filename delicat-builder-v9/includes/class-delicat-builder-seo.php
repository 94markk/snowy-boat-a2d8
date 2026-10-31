<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 9.3.15 — Delicat SEO.
 *
 * The store runs without an SEO plugin, so its pages carried no meta
 * description, no Open Graph tags (WhatsApp / Facebook previews showed a bare
 * link), no breadcrumbs, no canonical URL on shop and category pages, and
 * search-result and filtered URLs were indexable duplicates. This module adds
 * those basics, all cache-safe (identical for every visitor):
 *
 *  - <meta name="description"> for the home page, products, categories, the
 *    shop and pages (custom text per product/page, or a clean fallback);
 *  - product and category titles that name the market ("… en Haïti");
 *  - Open Graph + Twitter Card tags with the product image, price and stock;
 *  - BreadcrumbList on products and categories, and an OnlineStore + WebSite
 *    graph on the home page (replacing the older Organization block);
 *  - canonical URLs on the shop and category archives;
 *  - noindex,follow on search results, filtered/sorted catalogue URLs and
 *    private wallet pages; max-snippet / max-video-preview elsewhere;
 *  - a leaner core sitemap (no author archives, no cart/checkout/account).
 *
 * It steps aside completely when Yoast, Rank Math, AIOSEO, SEOPress, The SEO
 * Framework, Slim SEO, SmartCrawl or Squirrly is active.
 */
final class Delicat_Builder_V9_SEO {

	const OPTION     = 'delicat_builder_v9_seo';
	const META_TITLE = '_dlc_seo_title';
	const META_DESC  = '_dlc_seo_description';
	const DESC_MAX   = 158;

	private static bool $booted = false;
	private static ?array $settings_cache = null;
	private static ?array $context = null;

	public static function defaults(): array {
		return array(
			'enabled'          => 1,
			'home_title'       => '',
			'home_description' => '',
			'product_suffix'   => 'en Haïti',
			'share_image'      => '',
			'same_as'          => '',
			'phone'            => '',
			'email'            => '',
		);
	}

	public static function settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		foreach ( self::defaults() as $key => $default ) {
			$s[ $key ] = is_int( $default ) ? ( empty( $s[ $key ] ) ? 0 : 1 ) : ( is_scalar( $s[ $key ] ) ? (string) $s[ $key ] : '' );
		}
		return self::$settings_cache = $s;
	}

	/** Name of an active SEO plugin, or ''. */
	public static function other_seo_plugin(): string {
		$known = array(
			'Yoast SEO'           => defined( 'WPSEO_VERSION' ),
			'Rank Math'           => defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ),
			'All in One SEO'      => defined( 'AIOSEO_VERSION' ),
			'SEOPress'            => defined( 'SEOPRESS_VERSION' ),
			'The SEO Framework'   => defined( 'THE_SEO_FRAMEWORK_VERSION' ),
			'Slim SEO'            => defined( 'SLIM_SEO_VER' ),
			'SmartCrawl'          => defined( 'SMARTCRAWL_VERSION' ),
			'Squirrly SEO'        => defined( 'SQ_VERSION' ),
		);
		foreach ( $known as $name => $active ) {
			if ( $active ) {
				return $name;
			}
		}
		return '';
	}

	public static function active(): bool {
		return (bool) apply_filters( 'delicat_builder_v9_seo_enabled', ! empty( self::settings()['enabled'] ) && '' === self::other_seo_plugin() );
	}

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		/* Sitemaps are built on `init`; these callbacks check active() lazily. */
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'sitemap_provider' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_posts_args' ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
			add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 2 );
			return;
		}
		add_action( 'wp', array( __CLASS__, 'front' ) );
	}

	public static function front(): void {
		if ( ! self::active() || is_feed() || is_embed() || is_404() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		add_filter( 'document_title_parts', array( __CLASS__, 'title_parts' ), 30 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 3 );
		/* JSON-LD goes out just before WooCommerce prints its own, so a theme
		 * that already rendered WooCommerce breadcrumbs is not duplicated. */
		add_action( 'wp_footer', array( __CLASS__, 'structured_data' ), 5 );
		/* The OnlineStore graph below replaces the Pages module's Organization block. */
		add_filter( 'delicat_builder_v9_organization_structured_data', '__return_false' );
	}

	/* ------------------------------------------------------------------ */
	/* Titles                                                              */
	/* ------------------------------------------------------------------ */

	public static function title_parts( $parts ) {
		if ( ! is_array( $parts ) ) {
			return $parts;
		}
		$s = self::settings();
		if ( is_front_page() ) {
			return '' !== trim( $s['home_title'] ) ? array( 'title' => trim( $s['home_title'] ) ) : $parts;
		}
		if ( is_singular() ) {
			$custom = trim( (string) get_post_meta( (int) get_queried_object_id(), self::META_TITLE, true ) );
			if ( '' !== $custom ) {
				/* typed in the Delicat SEO box: used exactly as written */
				return array( 'title' => $custom );
			}
		}
		$suffix = trim( $s['product_suffix'] );
		if ( '' !== $suffix && isset( $parts['title'] ) && ( is_singular( 'product' ) || self::is_product_archive_term() ) ) {
			$title = (string) $parts['title'];
			if ( ! preg_match( '/ha[iï]ti/iu', $title ) ) {
				$parts['title'] = $title . ' ' . $suffix;
			}
		}
		return $parts;
	}

	private static function is_product_archive_term(): bool {
		return function_exists( 'is_product_taxonomy' ) && is_product_taxonomy();
	}

	/* ------------------------------------------------------------------ */
	/* Robots                                                              */
	/* ------------------------------------------------------------------ */

	private static function catalogue_query_args(): bool {
		foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			$key = (string) $key;
			if ( in_array( $key, array( 'orderby', 'min_price', 'max_price', 'rating_filter', 'add-to-cart', 'product_view', 'per_page' ), true ) || 0 === strpos( $key, 'filter_' ) || 0 === strpos( $key, 'query_type_' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function private_page(): bool {
		if ( ( function_exists( 'is_account_page' ) && is_account_page() ) || ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			return true;
		}
		if ( is_page() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post && in_array( $post->post_name, array( 'my-wallet', 'wallet', 'mon-portefeuille', 'portefeuille' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function robots( $robots ) {
		if ( ! is_array( $robots ) || ! empty( $robots['noindex'] ) ) {
			return $robots;
		}
		$shop_like = ( function_exists( 'is_shop' ) && is_shop() ) || self::is_product_archive_term();
		if ( is_search() || self::private_page() || ( $shop_like && self::catalogue_query_args() ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			return $robots;
		}
		$robots['max-snippet']       = '-1';
		$robots['max-video-preview'] = '-1';
		if ( empty( $robots['max-image-preview'] ) ) {
			$robots['max-image-preview'] = 'large';
		}
		return $robots;
	}

	/* ------------------------------------------------------------------ */
	/* Context                                                             */
	/* ------------------------------------------------------------------ */

	private static function clean( string $text, int $max = self::DESC_MAX ): string {
		$text = wp_strip_all_tags( strip_shortcodes( $text ), true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text || ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) <= $max ) {
			return $text;
		}
		$cut   = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max - 1 ) : substr( $text, 0, $max - 1 );
		$space = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ' ) : strrpos( $cut, ' ' );
		if ( false !== $space && $space > $max * 0.6 ) {
			$cut = function_exists( 'mb_substr' ) ? mb_substr( $cut, 0, $space ) : substr( $cut, 0, $space );
		}
		return rtrim( $cut, " \t,.;:–-" ) . '…';
	}

	private static function site_name(): string {
		return trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
	}

	private static function image_from_attachment( int $id ): array {
		if ( $id <= 0 ) {
			return array();
		}
		$src = wp_get_attachment_image_src( $id, 'large' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return array();
		}
		$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		return array(
			'url'    => (string) $src[0],
			'width'  => (int) ( $src[1] ?? 0 ),
			'height' => (int) ( $src[2] ?? 0 ),
			'alt'    => $alt,
		);
	}

	private static function fallback_image(): array {
		$s = self::settings();
		if ( '' !== trim( $s['share_image'] ) ) {
			return array( 'url' => esc_url_raw( trim( $s['share_image'] ) ), 'width' => 0, 'height' => 0, 'alt' => self::site_name() );
		}
		$logo = absint( get_theme_mod( 'custom_logo', 0 ) );
		$img  = self::image_from_attachment( $logo );
		if ( empty( $img ) ) {
			$img = self::image_from_attachment( absint( get_option( 'site_icon', 0 ) ) );
		}
		return $img;
	}

	private static function default_home_description(): string {
		$name    = self::site_name();
		$tagline = trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) );
		$base    = sprintf( '%s : recharges de jeux, cartes cadeaux et abonnements premium, livrés rapidement en Haïti.', $name );
		return '' !== $tagline ? $base . ' ' . rtrim( $tagline, '.!' ) . '.' : $base;
	}

	/** Everything the head needs for the current request (computed once). */
	public static function context(): array {
		if ( null !== self::$context ) {
			return self::$context;
		}
		$s   = self::settings();
		$ctx = array(
			'type'        => 'website',
			'title'       => wp_get_document_title(),
			'description' => '',
			'url'         => '',
			'canonical'   => '',
			'image'       => array(),
			'product'     => null,
			'crumbs'      => array(),
		);
		$name = self::site_name();
		if ( is_front_page() ) {
			$ctx['description'] = '' !== trim( $s['home_description'] ) ? trim( $s['home_description'] ) : self::default_home_description();
			$ctx['url']         = home_url( '/' );
		} elseif ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$custom = trim( (string) get_post_meta( $post->ID, self::META_DESC, true ) );
				$ctx['url'] = (string) ( wp_get_canonical_url( $post ) ?: get_permalink( $post ) );
				$ctx['image'] = self::image_from_attachment( (int) get_post_thumbnail_id( $post ) );
				if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $post->ID );
					if ( $product ) {
						$ctx['type'] = 'product';
						$text        = '' !== $custom ? $custom : (string) $product->get_short_description();
						if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
							$text = (string) $product->get_description();
						}
						$ctx['description'] = self::clean( $text );
						if ( '' === $ctx['description'] ) {
							$ctx['description'] = self::clean( sprintf( 'Achète %1$s sur %2$s, livré rapidement en Haïti. Commande en quelques clics et suis ta livraison depuis ton compte.', $product->get_name(), $name ) );
						}
						$image = self::image_from_attachment( (int) $product->get_image_id() );
						if ( ! empty( $image ) ) {
							if ( '' === $image['alt'] ) {
								$image['alt'] = $product->get_name();
							}
							$ctx['image'] = $image;
						}
						$price = function_exists( 'wc_get_price_to_display' ) ? (float) wc_get_price_to_display( $product ) : (float) $product->get_price();
						$ctx['product'] = array(
							'price'        => $price > 0 ? wc_format_decimal( $price, wc_get_price_decimals() ) : '',
							'currency'     => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
							'availability' => $product->is_in_stock() ? 'instock' : 'oos',
						);
						$ctx['crumbs'] = self::product_crumbs( $product );
					}
				} else {
					$ctx['type'] = 'post' === $post->post_type ? 'article' : 'website';
					$text        = '' !== $custom ? $custom : ( has_excerpt( $post ) ? (string) $post->post_excerpt : (string) $post->post_content );
					$ctx['description'] = self::clean( $text );
				}
			}
		} elseif ( self::is_product_archive_term() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$ctx['description'] = self::clean( (string) $term->description );
				if ( '' === $ctx['description'] ) {
					$ctx['description'] = self::clean( sprintf( '%1$s sur %2$s : recharges et produits numériques livrés rapidement en Haïti. Commande en quelques clics et suis ta livraison depuis ton compte.', $term->name, $name ) );
				}
				$link = get_term_link( $term );
				$ctx['url']       = is_wp_error( $link ) ? '' : self::paged_url( (string) $link );
				$ctx['canonical'] = self::catalogue_query_args() ? '' : $ctx['url'];
				$ctx['image']     = self::image_from_attachment( absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) ) );
				$ctx['crumbs']    = self::term_crumbs( $term );
			}
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			$custom  = $shop_id > 0 ? trim( (string) get_post_meta( $shop_id, self::META_DESC, true ) ) : '';
			$ctx['description'] = '' !== $custom ? $custom : self::clean( sprintf( 'Toute la boutique %s : recharges de jeux, cartes cadeaux et abonnements premium, livrés rapidement en Haïti.', $name ) );
			$link               = $shop_id > 0 ? (string) get_permalink( $shop_id ) : '';
			$ctx['url']         = '' !== $link ? self::paged_url( $link ) : '';
			$ctx['canonical']   = self::catalogue_query_args() || is_search() ? '' : $ctx['url'];
			$ctx['crumbs']      = array( array( 'Accueil', home_url( '/' ) ), array( $shop_id > 0 ? get_the_title( $shop_id ) : 'Boutique', $link ) );
		}
		if ( empty( $ctx['image'] ) ) {
			$ctx['image'] = self::fallback_image();
		}
		$ctx['description'] = self::clean( (string) $ctx['description'], 300 );
		return self::$context = (array) apply_filters( 'delicat_builder_v9_seo_context', $ctx );
	}

	private static function paged_url( string $base ): string {
		$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		if ( $paged < 2 ) {
			return $base;
		}
		global $wp_rewrite;
		if ( $wp_rewrite instanceof WP_Rewrite && $wp_rewrite->using_permalinks() ) {
			return trailingslashit( $base ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . $paged, 'paged' );
		}
		return add_query_arg( 'paged', $paged, $base );
	}

	private static function primary_term( WC_Product $product ): ?WP_Term {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return null;
		}
		/* The deepest category gives the most useful trail. */
		$best  = null;
		$depth = -1;
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term || 'uncategorized' === $term->slug || 'non-classe' === $term->slug ) {
				continue;
			}
			$d = count( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
			if ( $d > $depth ) {
				$best  = $term;
				$depth = $d;
			}
		}
		return $best;
	}

	private static function term_trail( WP_Term $term ): array {
		$out = array();
		foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( (int) $ancestor_id, $term->taxonomy );
			if ( $ancestor instanceof WP_Term ) {
				$link  = get_term_link( $ancestor );
				$out[] = array( $ancestor->name, is_wp_error( $link ) ? '' : (string) $link );
			}
		}
		$link  = get_term_link( $term );
		$out[] = array( $term->name, is_wp_error( $link ) ? '' : (string) $link );
		return $out;
	}

	private static function product_crumbs( WC_Product $product ): array {
		$crumbs = array( array( 'Accueil', home_url( '/' ) ) );
		$term   = self::primary_term( $product );
		if ( $term ) {
			$crumbs = array_merge( $crumbs, self::term_trail( $term ) );
		}
		$crumbs[] = array( $product->get_name(), (string) get_permalink( $product->get_id() ) );
		return $crumbs;
	}

	private static function term_crumbs( WP_Term $term ): array {
		return array_merge( array( array( 'Accueil', home_url( '/' ) ) ), self::term_trail( $term ) );
	}

	/* ------------------------------------------------------------------ */
	/* Head output                                                         */
	/* ------------------------------------------------------------------ */

	private static function meta( string $attr, string $key, string $value ): string {
		return '' === $value ? '' : '<meta ' . $attr . '="' . esc_attr( $key ) . '" content="' . esc_attr( $value ) . '" />' . "\n";
	}

	public static function head(): void {
		$ctx  = self::context();
		$out  = "<!-- Delicat SEO -->\n";
		$out .= self::meta( 'name', 'description', (string) $ctx['description'] );
		if ( '' !== $ctx['canonical'] ) {
			$out .= '<link rel="canonical" href="' . esc_url( $ctx['canonical'] ) . '" />' . "\n";
		}
		/* Jetpack prints its own Open Graph tags when its sharing module is on. */
		$open_graph = (bool) apply_filters( 'delicat_builder_v9_seo_open_graph', ! function_exists( 'jetpack_og_tags' ) );
		if ( ! $open_graph ) {
			echo $out . "<!-- /Delicat SEO -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			return;
		}
		$locale = str_replace( '-', '_', (string) get_bloginfo( 'language' ) );
		$title  = trim( wp_strip_all_tags( html_entity_decode( (string) $ctx['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		$url    = '' !== $ctx['url'] ? $ctx['url'] : ( '' !== $ctx['canonical'] ? $ctx['canonical'] : '' );
		$out   .= self::meta( 'property', 'og:locale', '' !== $locale ? $locale : 'fr_FR' );
		$out   .= self::meta( 'property', 'og:type', 'product' === $ctx['type'] ? 'product' : ( 'article' === $ctx['type'] ? 'article' : 'website' ) );
		$out   .= self::meta( 'property', 'og:site_name', self::site_name() );
		$out   .= self::meta( 'property', 'og:title', $title );
		$out   .= self::meta( 'property', 'og:description', (string) $ctx['description'] );
		$out   .= self::meta( 'property', 'og:url', $url );
		$img    = (array) $ctx['image'];
		if ( ! empty( $img['url'] ) ) {
			$out .= self::meta( 'property', 'og:image', (string) $img['url'] );
			if ( 0 === strpos( (string) $img['url'], 'https://' ) ) {
				$out .= self::meta( 'property', 'og:image:secure_url', (string) $img['url'] );
			}
			if ( ! empty( $img['width'] ) && ! empty( $img['height'] ) ) {
				$out .= self::meta( 'property', 'og:image:width', (string) (int) $img['width'] );
				$out .= self::meta( 'property', 'og:image:height', (string) (int) $img['height'] );
			}
			$out .= self::meta( 'property', 'og:image:alt', (string) ( $img['alt'] ?? '' ) );
		}
		if ( is_array( $ctx['product'] ) ) {
			$out .= self::meta( 'property', 'product:price:amount', (string) $ctx['product']['price'] );
			$out .= self::meta( 'property', 'product:price:currency', (string) $ctx['product']['currency'] );
			$out .= self::meta( 'property', 'product:availability', (string) $ctx['product']['availability'] );
		}
		$out .= self::meta( 'name', 'twitter:card', ! empty( $img['url'] ) ? 'summary_large_image' : 'summary' );
		$out .= self::meta( 'name', 'twitter:title', $title );
		$out .= self::meta( 'name', 'twitter:description', (string) $ctx['description'] );
		if ( ! empty( $img['url'] ) ) {
			$out .= self::meta( 'name', 'twitter:image', (string) $img['url'] );
		}
		echo $out . "<!-- /Delicat SEO -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped above.
	}

	/** BreadcrumbList (products, categories, shop) and the home page store graph. */
	public static function structured_data(): void {
		$ctx   = self::context();
		$graph = array();
		$wc_has_crumbs = false;
		if ( function_exists( 'WC' ) && isset( WC()->structured_data ) && is_callable( array( WC()->structured_data, 'get_data' ) ) ) {
			foreach ( (array) WC()->structured_data->get_data() as $node ) {
				if ( is_array( $node ) && 'BreadcrumbList' === ( $node['@type'] ?? '' ) ) {
					$wc_has_crumbs = true;
					break;
				}
			}
		}
		if ( ! empty( $ctx['crumbs'] ) && count( $ctx['crumbs'] ) > 1 ) {
			$items = array();
			foreach ( array_values( $ctx['crumbs'] ) as $i => $crumb ) {
				$item = array(
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => wp_strip_all_tags( (string) $crumb[0] ),
				);
				if ( '' !== (string) $crumb[1] ) {
					$item['item'] = (string) $crumb[1];
				}
				$items[] = $item;
			}
			if ( ! $wc_has_crumbs ) {
				$graph[] = array( '@type' => 'BreadcrumbList', 'itemListElement' => $items );
			}
		}
		if ( is_front_page() ) {
			$graph = array_merge( $graph, self::site_graph() );
		}
		if ( ! empty( $graph ) ) {
			echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from sanitised values.
		}
	}

	private static function same_as(): array {
		$s     = self::settings();
		$lines = preg_split( '/[\r\n,]+/', (string) $s['same_as'] );
		$older = get_option( 'delicat_builder_v9_same_as', array() );
		$list  = array_merge( is_array( $lines ) ? $lines : array(), is_array( $older ) ? $older : array() );
		$list  = apply_filters( 'delicat_builder_v9_organization_same_as', $list );
		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', array_map( 'trim', array_map( 'strval', (array) $list ) ) ) ) ) );
	}

	private static function site_graph(): array {
		$s    = self::settings();
		$home = home_url( '/' );
		$name = self::site_name();
		if ( '' === $name ) {
			return array();
		}
		$store = array(
			'@type'       => 'OnlineStore',
			'@id'         => $home . '#organization',
			'name'        => $name,
			'url'         => $home,
			'description' => self::clean( '' !== trim( $s['home_description'] ) ? $s['home_description'] : self::default_home_description(), 300 ),
			'areaServed'  => array( '@type' => 'Country', 'name' => 'Haïti' ),
		);
		$logo = self::image_from_attachment( absint( get_theme_mod( 'custom_logo', 0 ) ) );
		if ( empty( $logo ) ) {
			$logo = self::image_from_attachment( absint( get_option( 'site_icon', 0 ) ) );
		}
		if ( ! empty( $logo['url'] ) ) {
			$store['logo'] = array( '@type' => 'ImageObject', 'url' => $logo['url'] );
			if ( ! empty( $logo['width'] ) ) {
				$store['logo']['width']  = (int) $logo['width'];
				$store['logo']['height'] = (int) $logo['height'];
			}
		}
		$same_as = self::same_as();
		if ( ! empty( $same_as ) ) {
			$store['sameAs'] = $same_as;
		}
		$phone = trim( $s['phone'] );
		$email = sanitize_email( $s['email'] );
		if ( '' !== $phone || '' !== $email ) {
			$contact = array( '@type' => 'ContactPoint', 'contactType' => 'customer support' );
			if ( '' !== $phone ) {
				$contact['telephone'] = $phone;
			}
			if ( '' !== $email ) {
				$contact['email'] = $email;
			}
			$store['contactPoint'] = $contact;
		}
		$website = array(
			'@type'      => 'WebSite',
			'@id'        => $home . '#website',
			'name'       => $name,
			'url'        => $home,
			'inLanguage' => (string) get_bloginfo( 'language' ),
			'publisher'  => array( '@id' => $home . '#organization' ),
		);
		return array( $store, $website );
	}

	/* ------------------------------------------------------------------ */
	/* Sitemap                                                             */
	/* ------------------------------------------------------------------ */

	public static function sitemap_provider( $provider, $name ) {
		/* Author archives are thin pages that also expose usernames. */
		return ( 'users' === $name && self::active() ) ? false : $provider;
	}

	public static function sitemap_posts_args( $args, $post_type ) {
		if ( 'page' !== $post_type || ! is_array( $args ) || ! self::active() ) {
			return $args;
		}
		$exclude = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					$exclude[] = $id;
				}
			}
		}
		foreach ( array( 'my-wallet', 'wallet', 'mon-portefeuille', 'portefeuille' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page instanceof WP_Post ) {
				$exclude[] = (int) $page->ID;
			}
		}
		if ( ! empty( $exclude ) ) {
			$args['post__not_in'] = array_values( array_unique( array_merge( (array) ( $args['post__not_in'] ?? array() ), $exclude ) ) );
		}
		return $args;
	}

	/* ------------------------------------------------------------------ */
	/* Admin                                                               */
	/* ------------------------------------------------------------------ */

	public static function menu(): void {
		add_submenu_page( 'delicat-builder-v9', 'SEO', 'SEO', 'manage_options', 'delicat-builder-v9-seo', array( __CLASS__, 'page' ) );
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9_seo',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$same  = array();
		foreach ( (array) preg_split( '/[\r\n]+/', (string) ( $input['same_as'] ?? '' ) ) as $line ) {
			$url = esc_url_raw( trim( (string) $line ) );
			if ( '' !== $url ) {
				$same[] = $url;
			}
		}
		self::$settings_cache = null;
		return array(
			'enabled'          => empty( $input['enabled'] ) ? 0 : 1,
			'home_title'       => sanitize_text_field( (string) ( $input['home_title'] ?? '' ) ),
			'home_description' => sanitize_textarea_field( (string) ( $input['home_description'] ?? '' ) ),
			'product_suffix'   => sanitize_text_field( (string) ( $input['product_suffix'] ?? '' ) ),
			'share_image'      => esc_url_raw( (string) ( $input['share_image'] ?? '' ) ),
			'same_as'          => implode( "\n", array_slice( array_unique( $same ), 0, 12 ) ),
			'phone'            => sanitize_text_field( (string) ( $input['phone'] ?? '' ) ),
			'email'            => sanitize_email( (string) ( $input['email'] ?? '' ) ),
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$other = self::other_seo_plugin();
		$opt   = self::OPTION;
		$field = static function ( string $label, string $html, string $help = '' ): void {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . ( '' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inputs escaped by callers.
		};
		echo '<div class="wrap"><h1>Delicat SEO</h1>';
		if ( '' !== $other ) {
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( '%s est actif : il gère le référencement, Delicat SEO reste en retrait.', $other ) ) . '</p></div>';
		} else {
			echo '<p>Descriptions, titres, aperçus WhatsApp / Facebook (Open Graph), fil d’Ariane et données structurées pour Google. Chaque produit et chaque page a aussi sa boîte « Delicat SEO » dans l’éditeur.</p>';
		}
		echo '<form method="post" action="options.php">';
		settings_fields( 'delicat_builder_v9_seo' );
		echo '<table class="form-table" role="presentation">';
		$field( 'Activer', '<label><input type="checkbox" name="' . esc_attr( $opt ) . '[enabled]" value="1" ' . checked( ! empty( $s['enabled'] ), true, false ) . '> Activer Delicat SEO</label>' );
		$field( 'Titre de l’accueil', '<input class="large-text" type="text" maxlength="70" name="' . esc_attr( $opt ) . '[home_title]" value="' . esc_attr( $s['home_title'] ) . '" placeholder="Delicat Store – Recharge Free Fire, cartes cadeaux et abonnements en Haïti">', 'Environ 50 à 60 caractères. Vide : nom du site – slogan.' );
		$field( 'Description de l’accueil', '<textarea class="large-text" rows="3" maxlength="300" name="' . esc_attr( $opt ) . '[home_description]">' . esc_textarea( $s['home_description'] ) . '</textarea>', 'Environ 140 à 160 caractères, affichée sous le titre dans Google et dans les aperçus de liens.' );
		$field( 'Suffixe des titres produits', '<input type="text" maxlength="40" name="' . esc_attr( $opt ) . '[product_suffix]" value="' . esc_attr( $s['product_suffix'] ) . '">', 'Ajouté aux titres des produits et catégories qui ne citent pas déjà Haïti (ex. « Free Fire (LATAM) en Haïti – Delicat Store »). Vide pour ne rien ajouter.' );
		$field( 'Image de partage par défaut', '<input class="large-text" type="url" name="' . esc_attr( $opt ) . '[share_image]" value="' . esc_attr( $s['share_image'] ) . '" placeholder="https://…/partage-1200x630.jpg">', 'Utilisée quand une page n’a pas d’image (idéal : 1200 × 630 px). Vide : logo du site.' );
		$field( 'Profils officiels', '<textarea class="large-text" rows="4" name="' . esc_attr( $opt ) . '[same_as]" placeholder="https://www.facebook.com/…&#10;https://www.instagram.com/…&#10;https://www.tiktok.com/@…">' . esc_textarea( $s['same_as'] ) . '</textarea>', 'Une adresse par ligne (Facebook, Instagram, TikTok, fiche Google Business…). Aide Google à relier la marque à ses profils.' );
		$field( 'Téléphone / WhatsApp du support', '<input type="text" name="' . esc_attr( $opt ) . '[phone]" value="' . esc_attr( $s['phone'] ) . '" placeholder="+509 …">' );
		$field( 'E-mail du support', '<input type="email" name="' . esc_attr( $opt ) . '[email]" value="' . esc_attr( $s['email'] ) . '">' );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	public static function meta_boxes(): void {
		if ( '' !== self::other_seo_plugin() ) {
			return;
		}
		foreach ( array( 'product', 'page', 'post' ) as $type ) {
			add_meta_box( 'delicat-seo', 'Delicat SEO', array( __CLASS__, 'meta_box' ), $type, 'normal', 'default' );
		}
	}

	public static function meta_box( WP_Post $post ): void {
		wp_nonce_field( 'delicat_seo_' . $post->ID, 'delicat_seo_nonce' );
		$title = (string) get_post_meta( $post->ID, self::META_TITLE, true );
		$desc  = (string) get_post_meta( $post->ID, self::META_DESC, true );
		echo '<p><label for="delicat-seo-title"><strong>Titre Google</strong> <span class="description">(utilisé tel quel, environ 60 caractères ; vide : titre automatique)</span></label><br><input id="delicat-seo-title" class="widefat" type="text" maxlength="70" name="delicat_seo_title" value="' . esc_attr( $title ) . '" placeholder="Ex. Diamants Free Fire LATAM en Haïti – Livraison rapide"></p>';
		echo '<p><label for="delicat-seo-desc"><strong>Description Google</strong> <span class="description">(140 à 160 caractères ; vide : description courte du produit)</span></label><br><textarea id="delicat-seo-desc" class="widefat" rows="3" maxlength="300" name="delicat_seo_description" placeholder="Ex. Recharge Free Fire LATAM livrée en quelques minutes en Haïti. Paiement sécurisé, support WhatsApp.">' . esc_textarea( $desc ) . '</textarea></p>';
		echo '<p class="description" data-delicat-seo-count></p>';
		echo '<script>(function(){var t=document.getElementById("delicat-seo-title"),d=document.getElementById("delicat-seo-desc"),o=document.querySelector("[data-delicat-seo-count]");if(!t||!d||!o)return;function u(){o.textContent="Titre : "+t.value.length+" / 60 · Description : "+d.value.length+" / 160";}t.addEventListener("input",u);d.addEventListener("input",u);u();})();</script>';
	}

	public static function save_post( int $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'product', 'page', 'post' ), true ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! isset( $_POST['delicat_seo_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['delicat_seo_nonce'] ) ), 'delicat_seo_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$title = isset( $_POST['delicat_seo_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['delicat_seo_title'] ) ) : '';
		$desc  = isset( $_POST['delicat_seo_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['delicat_seo_description'] ) ) : '';
		'' !== $title ? update_post_meta( $post_id, self::META_TITLE, $title ) : delete_post_meta( $post_id, self::META_TITLE );
		'' !== $desc ? update_post_meta( $post_id, self::META_DESC, $desc ) : delete_post_meta( $post_id, self::META_DESC );
	}
}
