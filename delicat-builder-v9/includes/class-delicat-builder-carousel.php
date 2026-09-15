<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Carousel {

	/** RC80: one h1 per request. See normalize_heading() call site below. */
	private static $h1_claimed = false;

	public static function claim_h1(): bool {
		if ( self::$h1_claimed ) {
			return false;
		}
		self::$h1_claimed = true;
		return true;
	}

	public static function normalize_heading( string $requested ): string {
		$requested = strtolower( trim( $requested ) );
		if ( 'h1' === $requested ) {
			return self::claim_h1() ? 'h1' : 'h2';
		}
		return 'h2';
	}

	private const MAX_PRODUCTS = 24;
	private static int $rendered_carousels = 0;

	public static function boot(): void {
		add_shortcode( 'delicat_product_carousel', array( __CLASS__, 'shortcode' ) );
		// Compatibility shim for the retired early V9 shortcode. It prevents the
		// literal shortcode text from leaking while Homepage Studio replaces the
		// page with the modern Builder layout.
		add_shortcode( 'delicat_v9_carousel', array( __CLASS__, 'legacy_shortcode' ) );
	}

	public static function legacy_shortcode( array $atts = array() ): string {
		return '';
	}

	public static function shortcode( array $atts = array() ): string {
		if ( ! Delicat_Builder_V9_Core::is_enabled() ) {
			return '';
		}

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
			return current_user_can( 'manage_options' )
				? '<p class="delicat-builder-notice">' . esc_html__( 'Delicat Product Carousel requires WooCommerce.', 'delicat-builder-v9' ) . '</p>'
				: '';
		}

		$atts = shortcode_atts(
			array(
				'eyebrow'       => '',
				'title'         => '',
				'display_variant' => 'standard',
				'heading_level' => 'h2',
				'title_size_d'  => 0,
				'title_size_t'  => 0,
				'title_size_m'  => 0,
				'title_color'   => '',
				'subtitle'      => '',
				'subtitle_color'=> '',
				'gap_mode'      => 'global',
				'card_gap_d'    => 0,
				'card_gap_t'    => 0,
				'card_gap_m'    => 0,
				'section_gap_d' => 38,
				'section_gap_t' => 34,
				'section_gap_m' => 30,
				'product_name_d'=> 0,
				'product_name_t'=> 0,
				'product_name_m'=> 0,
				'media_title_d' => 30,
				'media_title_t' => 25,
				'media_title_m' => 20,
				'badge_mode'    => 'auto',
				'badge_bg'      => '',
				'badge_text'    => '',
				'status_badge_mode' => 'auto',
				'status_new_days'   => 30,
				'status_sales_min'  => 10,
				'pagination_mode'   => 'pages',
				'heart_mode'    => 'auto',
				'heart_bg'      => '',
				'heart_color'   => '',
				'heart_icon'    => 'inherit',
				'view_all_text' => '',
				'view_all_url'  => '',
				'tag_text'      => '',
				'category'      => '',
				'product_ids'   => '',
				'limit'       => 12,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'stock'       => 'instock',
				'style'       => 'delicat_jeux',
				'show_price'  => 'yes',
				'show_stock'  => 'yes',
				'show_cta'    => 'yes',
				'progressive' => 'yes',
			),
			$atts,
			'delicat_product_carousel'
		);

		$limit = min( self::MAX_PRODUCTS, max( 1, absint( $atts['limit'] ) ) );
		$order = 'ASC' === strtoupper( (string) $atts['order'] ) ? 'ASC' : 'DESC';
		$style = sanitize_key( (string) $atts['style'] );
		$style = in_array( $style, array( 'delicat_jeux', 'delicat_abonnement' ), true )
			? $style
			: 'delicat_jeux';
		$display_variant = in_array( $atts['display_variant'] ?? '', array( 'standard', 'ranking' ), true )
			? $atts['display_variant']
			: 'standard';

		/*
		 * RC45: "Les plus achetés" is a ranking rail ordered by popularity, but
		 * its Voir tout was seeded with the bare shop permalink — so tapping it
		 * left the ranking and landed on the default, unsorted catalogue. Carry
		 * the rail's own ordering across only when the link is still that
		 * untouched shop URL: a merchant-authored destination, an anchor, or a
		 * URL that already carries a query is never rewritten.
		 */
		$view_all_url = (string) ( $atts['view_all_url'] ?? '' );
		if (
			'ranking' === $display_variant
			&& 'popularity' === strtolower( (string) ( $atts['orderby'] ?? '' ) )
			&& '' !== trim( $view_all_url )
			&& false === strpos( $view_all_url, '?' )
			&& false === strpos( $view_all_url, '#' )
			&& function_exists( 'wc_get_page_permalink' )
		) {
			$shop_permalink = (string) wc_get_page_permalink( 'shop' );
			if ( '' !== $shop_permalink && untrailingslashit( $view_all_url ) === untrailingslashit( $shop_permalink ) ) {
				$view_all_url = add_query_arg( 'orderby', 'popularity', $view_all_url );
			}
		}
		$atts['view_all_url'] = $view_all_url;
		unset( $view_all_url );

		/* RC80 — one h1 per document.
		 *
		 * `heading_level` is a per-section field, so the live homepage ended up
		 * with the hero title and the "JEUX" rail both rendering as h1, while
		 * "Les plus achetés" rendered as h3 with no h2 above it. Screen readers
		 * and search engines both read that as a broken outline, and no CSS
		 * fixes it because the level is in the markup.
		 *
		 * The register is per-request, matching how a page is assembled: the
		 * hero claims the h1 first and any later section asking for one is
		 * demoted. A rail is never h3 either — h3 only means something under an
		 * h2 in the same section, and these rails are siblings. */
		$heading_level = self::normalize_heading( (string) ( $atts['heading_level'] ?? '' ) );
		$title_size_d = min( 64, max( 0, absint( $atts['title_size_d'] ?? 0 ) ) );
		$title_size_t = min( 56, max( 0, absint( $atts['title_size_t'] ?? 0 ) ) );
		$title_size_m = min( 48, max( 0, absint( $atts['title_size_m'] ?? 0 ) ) );
		$title_color = sanitize_hex_color( (string) ( $atts['title_color'] ?? '' ) ) ?: '';
		$subtitle_color = sanitize_hex_color( (string) ( $atts['subtitle_color'] ?? '' ) ) ?: '';
		$gap_mode = in_array( $atts['gap_mode'] ?? '', array( 'global', 'custom' ), true )
			? $atts['gap_mode']
			: 'global';
		$card_gap_d = min( 48, max( 0, absint( $atts['card_gap_d'] ?? 0 ) ) );
		$card_gap_t = min( 40, max( 0, absint( $atts['card_gap_t'] ?? 0 ) ) );
		$card_gap_m = min( 32, max( 0, absint( $atts['card_gap_m'] ?? 0 ) ) );
		$section_gap_d = min( 120, max( 0, absint( $atts['section_gap_d'] ?? 38 ) ) );
		$section_gap_t = min( 100, max( 0, absint( $atts['section_gap_t'] ?? 34 ) ) );
		$section_gap_m = min( 90, max( 0, absint( $atts['section_gap_m'] ?? 30 ) ) );
		$product_name_d = min( 36, max( 0, absint( $atts['product_name_d'] ?? 0 ) ) );
		$product_name_t = min( 32, max( 0, absint( $atts['product_name_t'] ?? 0 ) ) );
		$product_name_m = min( 28, max( 0, absint( $atts['product_name_m'] ?? 0 ) ) );
		$media_title_d = min( 52, max( 14, absint( $atts['media_title_d'] ?? 30 ) ) );
		$media_title_t = min( 44, max( 14, absint( $atts['media_title_t'] ?? 25 ) ) );
		$media_title_m = min( 36, max( 12, absint( $atts['media_title_m'] ?? 20 ) ) );
		$badge_mode = class_exists( 'Delicat_Builder_V9_Badges' )
			? Delicat_Builder_V9_Badges::sanitize_mode( $atts['badge_mode'] ?? 'auto' )
			: 'auto';
		$badge_bg = sanitize_hex_color( (string) ( $atts['badge_bg'] ?? '' ) ) ?: '';
		$badge_text = sanitize_hex_color( (string) ( $atts['badge_text'] ?? '' ) ) ?: '';
		$status_badge_mode = in_array( $atts['status_badge_mode'] ?? '', array( 'auto', 'sale', 'new', 'bestseller', 'off' ), true ) ? $atts['status_badge_mode'] : 'auto';
		$status_new_days = min( 120, max( 1, absint( $atts['status_new_days'] ?? 30 ) ) );
		$status_sales_min = min( 10000, max( 1, absint( $atts['status_sales_min'] ?? 10 ) ) );
		$pagination_mode = in_array( $atts['pagination_mode'] ?? '', array( 'pages', 'cards' ), true ) ? $atts['pagination_mode'] : 'pages';
		$heart_mode = in_array( $atts['heart_mode'] ?? '', array( 'auto', 'on', 'off' ), true ) ? $atts['heart_mode'] : 'auto';
		$heart_bg = sanitize_hex_color( (string) ( $atts['heart_bg'] ?? '' ) ) ?: '';
		$heart_color = sanitize_hex_color( (string) ( $atts['heart_color'] ?? '' ) ) ?: '';
		$heart_icon = sanitize_key( (string) ( $atts['heart_icon'] ?? 'inherit' ) );
		if ( ! in_array( $heart_icon, array( 'inherit', 'heart', 'heart_outline', 'star', 'bolt' ), true ) ) {
			$heart_icon = 'inherit';
		}

		$allowed_orderby = array( 'date', 'title', 'menu_order', 'modified', 'rand', 'popularity' );
		$orderby = in_array( $atts['orderby'], $allowed_orderby, true ) ? $atts['orderby'] : 'date';

		$explicit_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', preg_split( '/[^0-9]+/', (string) $atts['product_ids'] ) )
				)
			)
		);
		$explicit_ids = array_slice( $explicit_ids, 0, self::MAX_PRODUCTS );

		if ( ! empty( $explicit_ids ) ) {
			$product_ids = array();
			foreach ( $explicit_ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product instanceof WC_Product || 'publish' !== get_post_status( $product_id ) ) {
					continue;
				}
				if ( ! $product->is_visible() ) {
					continue;
				}
				$product_ids[] = $product_id;
			}
		} else {
			$needs_php_sort = in_array( $orderby, array( 'popularity', 'menu_order' ), true );
			$query_orderby = 'title' === $orderby ? 'name' : ( $needs_php_sort ? 'date' : $orderby );
			// Woo queries can include a product that later fails is_visible().
			// Fetch a small bounded cushion, then slice after visibility filtering
			// so the carousel does not appear randomly short in Chrome/other
			// browsers because the server emitted fewer eligible products.
			if ( 'popularity' === $orderby ) {
				// Popularity ranking needs a broader candidate pool to stay meaningful.
				$query_limit = min( 60, max( 30, $limit * 5 ) );
			} elseif ( 'menu_order' === $orderby ) {
				// RC51.25: menu_order is deterministic, so the old 5x overscan was
				// unnecessary DB/object-cache work on cold storefront requests.
				$query_limit = min( 36, max( 18, $limit * 3 ) );
			} else {
				$query_limit = min( self::MAX_PRODUCTS + 8, max( $limit, $limit + 8 ) );
			}

			$query_args = array(
				'status'  => 'publish',
				'limit'   => $query_limit,
				'orderby' => $query_orderby,
				'order'   => $needs_php_sort ? 'DESC' : $order,
				'return'  => 'objects',
			);

			$category = sanitize_title( (string) $atts['category'] );
			if ( '' !== $category ) {
				$query_args['category'] = array( $category );
			}

			if ( 'instock' === $atts['stock'] ) {
				$query_args['stock_status'] = 'instock';
			}

			$settings = Delicat_Builder_V9_Core::settings();
			$ttl = min( HOUR_IN_SECONDS, max( 60, absint( $settings['cache_ttl'] ?? 600 ) ) );
			$cache_key = Delicat_Builder_V9_Cache::key( 'carousel', array( 'query' => $query_args, 'semantic_orderby' => $orderby, 'semantic_order' => $order, 'requested_limit' => $limit ) );

			$query_callback = static function () use ( $query_args, $orderby, $order, $limit ): array {
				$products = wc_get_products( $query_args );
				if ( 'popularity' === $orderby ) {
					usort(
						$products,
						static function ( $a, $b ): int {
							$a_sales = $a instanceof WC_Product ? absint( $a->get_total_sales() ) : 0;
							$b_sales = $b instanceof WC_Product ? absint( $b->get_total_sales() ) : 0;
							return $a_sales === $b_sales
								? ( ( $b instanceof WC_Product ? $b->get_id() : 0 ) <=> ( $a instanceof WC_Product ? $a->get_id() : 0 ) )
								: ( $b_sales <=> $a_sales );
						}
					);
				} elseif ( 'menu_order' === $orderby ) {
					usort(
						$products,
						static function ( $a, $b ) use ( $order ): int {
							$a_order = $a instanceof WC_Product ? absint( $a->get_menu_order() ) : PHP_INT_MAX;
							$b_order = $b instanceof WC_Product ? absint( $b->get_menu_order() ) : PHP_INT_MAX;
							$cmp = $a_order <=> $b_order;
							return 'DESC' === $order ? -$cmp : $cmp;
						}
					);
				}
				return array_slice(
					array_values(
						array_filter(
							array_map(
								static function ( $product ) { return $product instanceof WC_Product && $product->is_visible() ? $product->get_id() : 0; },
								$products
							)
						)
					),
					0,
					$limit
				);
			};

			if ( class_exists( 'Delicat_Builder_V9_Query_Cache', false ) ) {
				$product_ids = Delicat_Builder_V9_Query_Cache::remember(
					'carousel_products',
					array( 'query' => $query_args, 'semantic_orderby' => $orderby, 'semantic_order' => $order, 'requested_limit' => $limit ),
					$ttl,
					$query_callback
				);
			} else {
				$product_ids = get_transient( $cache_key );
				if ( ! is_array( $product_ids ) ) {
					$product_ids = $query_callback();
					set_transient( $cache_key, $product_ids, $ttl );
				}
			}
		}

		$settings = Delicat_Builder_V9_Core::settings();
		$managed_homepage = false;
		if (
			function_exists( 'is_singular' )
			&& is_singular( 'page' )
			&& function_exists( 'delicat_builder_v9_is_managed_page' )
		) {
			$managed_homepage = delicat_builder_v9_is_managed_page( get_queried_object_id() );
		}

		if ( empty( $product_ids ) ) {
			return '';
		}

		$progressive = 'no' !== strtolower( (string) $atts['progressive'] )
			&& ! empty( $settings['carousel_progressive'] );
		$carousel_index = self::$rendered_carousels++;

		$initial = min(
			count( $product_ids ),
			max( 3, min( 12, absint( $settings['carousel_initial'] ?? 8 ) ) )
		);

		if ( ! $progressive ) {
			$initial = count( $product_ids );
		} elseif ( $managed_homepage ) {
			// RC21 Chrome-safe fallback: keep enough real server-rendered cards
			// that the carousel stays useful even if progressive JavaScript is
			// delayed. Deferred card images remain lazy/low priority.
			$managed_floor = 3;
			$initial = min(
				count( $product_ids ),
				max( 3, min( $managed_floor, absint( $settings['carousel_initial'] ?? 6 ) ) )
			);
		}
		$show_price = 'no' !== strtolower( (string) $atts['show_price'] );
		$show_stock = 'no' !== strtolower( (string) $atts['show_stock'] );
		$show_cta = 'no' !== strtolower( (string) $atts['show_cta'] );
		$uid = wp_unique_id( 'delicat-carousel-' );
		$design = class_exists( 'Delicat_Builder_V9_Design' ) ? Delicat_Builder_V9_Design::carousel_enhancements() : array(
			'pagination' => false,
			'shell'      => false,
			'bubble'     => false,
			'heart'      => false,
			'style'      => 'default',
			'gap_d'      => 18,
			'gap_t'      => 16,
			'gap_m'      => 14,
			'name_d'     => 18,
			'name_t'     => 17,
			'name_m'     => 16,
			'badge_bg'   => '#e9ddff',
			'badge_text' => '#6540d9',
			'heart_bg'   => '#ffffff',
			'heart_color'=> '#ff4d91',
			'heart_icon' => 'heart',
		);

		if ( 'global' === $gap_mode ) {
			$card_gap_d = absint( $design['gap_d'] ?? 18 );
			$card_gap_t = absint( $design['gap_t'] ?? 16 );
			$card_gap_m = absint( $design['gap_m'] ?? 14 );
		}
		$product_name_d = $product_name_d > 0 ? $product_name_d : absint( $design['name_d'] ?? 18 );
		$product_name_t = $product_name_t > 0 ? $product_name_t : absint( $design['name_t'] ?? 17 );
		$product_name_m = $product_name_m > 0 ? $product_name_m : absint( $design['name_m'] ?? 16 );
		$badge_bg = $badge_bg ?: ( sanitize_hex_color( (string) ( $design['badge_bg'] ?? '#e9ddff' ) ) ?: '#e9ddff' );
		$badge_text = $badge_text ?: ( sanitize_hex_color( (string) ( $design['badge_text'] ?? '#6540d9' ) ) ?: '#6540d9' );
		$heart_bg = $heart_bg ?: ( sanitize_hex_color( (string) ( $design['heart_bg'] ?? '#ffffff' ) ) ?: '#ffffff' );
		$heart_color = $heart_color ?: ( sanitize_hex_color( (string) ( $design['heart_color'] ?? '#ff4d91' ) ) ?: '#ff4d91' );
		if ( 'inherit' === $heart_icon && ! empty( $design['heart_icon'] ) ) {
			$heart_icon = sanitize_key( (string) $design['heart_icon'] );
		}
		if ( ! in_array( $heart_icon, array( 'heart', 'heart_outline', 'star', 'bolt' ), true ) ) {
			$heart_icon = 'heart';
		}

		$live_ids     = array_slice( $product_ids, 0, $initial );
		$deferred_ids = array_slice( $product_ids, $initial );

		$carousel_vars = array();
		if ( $title_size_d > 0 ) {
			$carousel_vars[] = '--dbv9-carousel-title-d:' . $title_size_d . 'px';
		}
		if ( $title_size_t > 0 ) {
			$carousel_vars[] = '--dbv9-carousel-title-t:' . $title_size_t . 'px';
		}
		if ( $title_size_m > 0 ) {
			$carousel_vars[] = '--dbv9-carousel-title-m:' . $title_size_m . 'px';
		}
		if ( '' !== $title_color ) {
			$carousel_vars[] = '--dbv9-carousel-title-color:' . $title_color;
		}
		if ( '' !== $subtitle_color ) {
			$carousel_vars[] = '--dbv9-carousel-subtitle-color:' . $subtitle_color;
		}
		$carousel_vars[] = '--dbv9-carousel-gap-d:' . $card_gap_d . 'px';
		$carousel_vars[] = '--dbv9-carousel-gap-t:' . $card_gap_t . 'px';
		$carousel_vars[] = '--dbv9-carousel-gap-m:' . $card_gap_m . 'px';
		$carousel_vars[] = '--dbv9-carousel-section-gap-d:' . $section_gap_d . 'px';
		$carousel_vars[] = '--dbv9-carousel-section-gap-t:' . $section_gap_t . 'px';
		$carousel_vars[] = '--dbv9-carousel-section-gap-m:' . $section_gap_m . 'px';
		$carousel_vars[] = '--dbv9-product-name-d:' . $product_name_d . 'px';
		$carousel_vars[] = '--dbv9-product-name-t:' . $product_name_t . 'px';
		$carousel_vars[] = '--dbv9-product-name-m:' . $product_name_m . 'px';
		$carousel_vars[] = '--dbv9-media-title-d:' . $media_title_d . 'px';
		$carousel_vars[] = '--dbv9-media-title-t:' . $media_title_t . 'px';
		$carousel_vars[] = '--dbv9-media-title-m:' . $media_title_m . 'px';
		$carousel_vars[] = '--dbv9-badge-bg:' . $badge_bg;
		$carousel_vars[] = '--dbv9-badge-text:' . $badge_text;
		$carousel_vars[] = '--dbv9-heart-bg:' . $heart_bg;
		$carousel_vars[] = '--dbv9-heart-color:' . $heart_color;

		ob_start();
		?>
		<section
			class="delicat-carousel delicat-carousel--<?php echo esc_attr( $style ); ?> delicat-carousel--variant-<?php echo esc_attr( $display_variant ); ?><?php echo ! empty( $design['shell'] ) ? ' delicat-carousel--shell' : ''; ?><?php echo 'neon_luxe' === ( $design['style'] ?? '' ) ? ' delicat-carousel--design-neon' : ''; ?>"
			data-delicat-carousel
			data-delicat-island="carousel"
			data-delicat-style="<?php echo esc_attr( $style ); ?>"
			data-delicat-display-variant="<?php echo esc_attr( $display_variant ); ?>"
			data-delicat-total="<?php echo esc_attr( (string) count( $product_ids ) ); ?>"
			data-delicat-source="<?php echo ! empty( $explicit_ids ) ? 'explicit' : 'query'; ?>"
			data-delicat-gap-mode="<?php echo esc_attr( $gap_mode ); ?>"
			data-delicat-pagination-mode="<?php echo esc_attr( $pagination_mode ); ?>"
			data-delicat-initial="<?php echo esc_attr( (string) $initial ); ?>"
			data-delicat-carousel-index="<?php echo esc_attr( (string) $carousel_index ); ?>"
			data-delicat-buy-label="<?php echo esc_attr__( 'Acheter', 'delicat-builder-v9' ); ?>"
			data-delicat-starting-label="<?php echo esc_attr__( 'À partir de', 'delicat-builder-v9' ); ?>"
			data-delicat-managed-homepage="<?php echo $managed_homepage ? '1' : '0'; ?>"
			<?php if ( ! empty( $carousel_vars ) ) : ?>style="<?php echo esc_attr( implode( ';', $carousel_vars ) ); ?>"<?php endif; ?>
			aria-labelledby="<?php echo esc_attr( $uid ); ?>-title"
		>
			<div class="delicat-carousel__head">
				<div class="delicat-carousel__heading">
					<?php if ( '' !== trim( (string) $atts['eyebrow'] ) ) : ?>
						<span class="delicat-carousel__eyebrow"><?php echo apply_filters( 'delicat_builder_v9_section_text', esc_html( $atts['eyebrow'] ) ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== trim( (string) $atts['title'] ) ) : ?>
						<<?php echo esc_attr( $heading_level ); ?> class="delicat-carousel__title" id="<?php echo esc_attr( $uid ); ?>-title">
							<?php echo apply_filters( 'delicat_builder_v9_section_text', esc_html( $atts['title'] ) ); ?>
						</<?php echo esc_attr( $heading_level ); ?>>
					<?php else : ?>
						<span class="screen-reader-text" id="<?php echo esc_attr( $uid ); ?>-title">
							<?php esc_html_e( 'Products', 'delicat-builder-v9' ); ?>
						</span>
					<?php endif; ?>
					<?php if ( '' !== trim( (string) $atts['subtitle'] ) ) : ?>
						<p class="delicat-carousel__subtitle"><?php echo apply_filters( 'delicat_builder_v9_section_text', esc_html( $atts['subtitle'] ) ); ?></p>
					<?php endif; ?>
				</div>

				<div class="delicat-carousel__head-actions">
					<?php if ( '' !== trim( (string) $atts['view_all_text'] ) && '' !== trim( (string) $atts['view_all_url'] ) ) : ?>
						<a class="delicat-carousel__view-all" href="<?php echo esc_url( $atts['view_all_url'] ); ?>" data-delicat-prefetch>
							<?php echo esc_html( $atts['view_all_text'] ); ?> <span aria-hidden="true">→</span>
						</a>
					<?php endif; ?>
				<div class="delicat-carousel__controls" aria-label="<?php esc_attr_e( 'Carousel controls', 'delicat-builder-v9' ); ?>">
					<button type="button" class="delicat-carousel__button" data-delicat-prev aria-label="<?php esc_attr_e( 'Previous products', 'delicat-builder-v9' ); ?>">‹</button>
					<button type="button" class="delicat-carousel__button" data-delicat-next aria-label="<?php esc_attr_e( 'Next products', 'delicat-builder-v9' ); ?>">›</button>
				</div>
				</div>
			</div>

			<div class="delicat-carousel__viewport" data-delicat-viewport>
				<div class="delicat-carousel__track" data-delicat-track tabindex="0" role="list">
					<?php
					/*
					 * RC44 warm guest path: the first visible cards used to rebuild WC_Product,
					 * permalink, image metadata, badges and price HTML on every homepage hit.
					 * Cache only public guest presentation HTML and key it through the Builder
					 * cache version, currency/country/tax/design context. Product/design changes
					 * therefore invalidate it automatically; logged-in favorite state bypasses it.
					 */
					$live_cards_cache_key = '';
					$live_cards_html = false;
					if ( $managed_homepage && ! is_user_logged_in() ) {
						$live_cards_cache_key = self::deferred_payload_cache_key(
							$live_ids,
							array(
								'kind'               => 'live-card-html-v1',
								'show_price'         => $show_price ? 1 : 0,
								'show_stock'         => $show_stock ? 1 : 0,
								'tag_text'           => (string) $atts['tag_text'],
								'badge_mode'         => $badge_mode,
								'status_badge_mode'  => $status_badge_mode,
								'status_new_days'    => $status_new_days,
								'status_sales_min'   => $status_sales_min,
								'style'              => $style,
								'heart_mode'         => $heart_mode,
								'heart_icon'         => $heart_icon,
								'show_cta'           => $show_cta ? 1 : 0,
								'priority_first'     => 0 === $carousel_index ? 1 : 0,
							)
						);
						$live_cards_html = $live_cards_cache_key ? get_transient( $live_cards_cache_key ) : false;
					}

					if ( ! is_string( $live_cards_html ) ) {
						ob_start();
						foreach ( $live_ids as $index => $product_id ) {
							echo self::product_card(
								$product_id,
								$index,
								$show_price,
								$show_stock,
								(string) $atts['tag_text'],
								$badge_mode,
								$status_badge_mode,
								$status_new_days,
								$status_sales_min,
								$style,
								$heart_mode,
								$heart_icon,
								$show_cta,
								$managed_homepage && 0 === $carousel_index && 0 === $index
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						$live_cards_html = (string) ob_get_clean();
						if ( $live_cards_cache_key ) {
							$ttl = max( 60, min( 1800, absint( $settings['cache_ttl'] ?? 600 ) ) );
							set_transient( $live_cards_cache_key, $live_cards_html, $ttl );
						}
					}

					echo $live_cards_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
			</div>

			<?php if ( ! empty( $deferred_ids ) ) : ?>
				<?php if ( $managed_homepage ) : ?>
					<?php
					/*
					 * RC39.5 warm-path optimization: managed-homepage deferred cards are
					 * public presentation data. Cache the complete guest payload per
					 * carousel/config/currency so repeat homepage requests do not rebuild
					 * every deferred WooCommerce product before first byte. Logged-in users
					 * bypass this cache to preserve personalized favorite state.
					 */
					$payload_cache_key = self::deferred_payload_cache_key(
						$deferred_ids,
						array(
							'initial'            => $initial,
							'show_price'         => $show_price ? 1 : 0,
							'tag_text'           => (string) $atts['tag_text'],
							'badge_mode'         => $badge_mode,
							'status_badge_mode'  => $status_badge_mode,
							'status_new_days'    => $status_new_days,
							'status_sales_min'   => $status_sales_min,
							'style'              => $style,
							'heart_mode'         => $heart_mode,
							'heart_icon'         => $heart_icon,
							'show_cta'           => $show_cta ? 1 : 0,
						)
					);
					$deferred_payload = $payload_cache_key ? get_transient( $payload_cache_key ) : false;

					if ( ! is_array( $deferred_payload ) ) {
						$deferred_payload = array();
						foreach ( $deferred_ids as $offset => $product_id ) {
							$payload = self::deferred_card_payload(
								$product_id,
								$initial + $offset,
								$show_price,
								(string) $atts['tag_text'],
								$badge_mode,
								$status_badge_mode,
								$status_new_days,
								$status_sales_min,
								$style,
								$heart_mode,
								$heart_icon,
								$show_cta
							);
							if ( ! empty( $payload ) ) {
								$deferred_payload[] = $payload;
							}
						}

						if ( $payload_cache_key ) {
							$ttl = max( 60, min( 1800, absint( $settings['cache_ttl'] ?? 600 ) ) );
							set_transient( $payload_cache_key, $deferred_payload, $ttl );
						}
					}
					?>
					<?php if ( ! empty( $deferred_payload ) ) : ?>
						<script type="application/json" data-delicat-carousel-deferred-json><?php
							echo wp_json_encode(
								$deferred_payload,
								JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?></script>
					<?php endif; ?>
				<?php else : ?>
					<template data-delicat-carousel-deferred>
						<?php
						foreach ( $deferred_ids as $offset => $product_id ) {
							echo self::product_card(
								$product_id,
								$initial + $offset,
								$show_price,
								$show_stock,
								(string) $atts['tag_text'],
								$badge_mode,
								$status_badge_mode,
								$status_new_days,
								$status_sales_min,
								$style,
								$heart_mode,
								$heart_icon,
								$show_cta,
								false
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</template>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! empty( $design['pagination'] ) && count( $product_ids ) > 1 ) : ?>
				<div class="delicat-carousel__pagination" data-delicat-pagination aria-label="<?php esc_attr_e( 'Carousel pagination', 'delicat-builder-v9' ); ?>"></div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function resolve_product_image_id( WC_Product $product ): int {
		$image_id = absint( $product->get_image_id() );
		if ( $image_id && Delicat_Builder_V9_Media::is_image_attachment( $image_id ) ) {
			return $image_id;
		}

		$post_thumb = absint( get_post_thumbnail_id( $product->get_id() ) );
		if ( $post_thumb && Delicat_Builder_V9_Media::is_image_attachment( $post_thumb ) ) {
			return $post_thumb;
		}

		if ( $product instanceof WC_Product_Variable ) {
			foreach ( array_slice( $product->get_children(), 0, 12 ) as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child instanceof WC_Product_Variation ) {
					continue;
				}
				$child_image = absint( $child->get_image_id() );
				if ( $child_image && Delicat_Builder_V9_Media::is_image_attachment( $child_image ) ) {
					return $child_image;
				}
			}
		}

		return 0;
	}

	private static function starting_price_parts( WC_Product $product ): array {
		if ( $product instanceof WC_Product_Variable ) {
			$price = $product->get_variation_price( 'min', true );
		} else {
			$price = wc_get_price_to_display( $product );
		}

		if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
			return array();
		}

		return array(
			's' => sanitize_text_field( get_woocommerce_currency_symbol() ),
			'n' => sanitize_text_field( wc_format_localized_price( (float) $price ) ),
		);
	}

	private static function starting_price_html( WC_Product $product ): string {
		$parts = self::starting_price_parts( $product );
		if ( empty( $parts ) ) {
			return '';
		}

		return '<span class="delicat-price-symbol">' . esc_html( $parts['s'] ) . '</span>'
			. '<span class="delicat-price-number">' . esc_html( $parts['n'] ) . '</span>';
	}

	private static function cart_icon(): string {
		return '<svg class="delicat-product-card__cta-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
			. '<path fill="currentColor" d="M7.2 18.2a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Zm9.2 0a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6ZM6.1 5.3l.5 2.1h11.7l-1.3 5.4a1.4 1.4 0 0 1-1.4 1.1H8.2a1.4 1.4 0 0 1-1.4-1.1L4.9 4.9H2.8a1 1 0 1 1 0-2h2.9l.4 2.4Zm1 4.1.6 2.5h7.5l.6-2.5H7.1Z"/>'
			. '</svg>';
	}

	private static function deferred_payload_cache_key( array $product_ids, array $config ): string {
		if ( is_user_logged_in() || ! class_exists( 'Delicat_Builder_V9_Cache' ) ) {
			return '';
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$country = '';
		if ( function_exists( 'WC' ) && WC() && WC()->customer instanceof WC_Customer ) {
			$country = (string) WC()->customer->get_billing_country();
			if ( '' === $country ) {
				$country = (string) WC()->customer->get_shipping_country();
			}
		}

		$design_context = class_exists( 'Delicat_Builder_V9_Design' )
			? Delicat_Builder_V9_Design::carousel_enhancements()
			: array();

		return Delicat_Builder_V9_Cache::key(
			'carousel_deferred_payload',
			array(
				'ids'         => array_values( array_map( 'absint', $product_ids ) ),
				'config'      => $config,
				'design'      => md5( wp_json_encode( $design_context ) ?: '' ),
				'locale'      => get_locale(),
				'currency'    => sanitize_key( $currency ),
				'country'     => sanitize_key( $country ),
				'tax_display' => sanitize_key( (string) get_option( 'woocommerce_tax_display_shop', 'excl' ) ),
			)
		);
	}

	/**
	 * pro.17: drawn, not typed.
	 *
	 * This returned one of four literal characters - the heart, the outline
	 * heart, the star, the lightning bolt - and printed it into every product
	 * card. They render as a different glyph on every phone, at whatever size
	 * the font decides, and on Android several arrive as coloured bitmaps that
	 * ignore the card's palette. The badge engine draws all four.
	 */
	private static function heart_symbol( string $icon = 'heart' ): string {
		return class_exists( 'Delicat_Builder_V9_Badges' )
			? Delicat_Builder_V9_Badges::heart( $icon )
			: '';
	}

	private static function deferred_card_payload(
		int $product_id,
		int $index,
		bool $show_price,
		string $tag_text = '',
		string $badge_mode = 'auto',
		string $status_badge_mode = 'auto',
		int $status_new_days = 30,
		int $status_sales_min = 10,
		string $style = '',
		string $heart_mode = 'auto',
		string $heart_icon = 'heart',
		bool $show_cta = true
	): array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! $product->is_visible() || 'publish' !== get_post_status( $product_id ) ) {
			return array();
		}

		$link = get_permalink( $product_id );
		if ( ! is_string( $link ) || '' === $link ) {
			return array();
		}

		$design = class_exists( 'Delicat_Builder_V9_Design' )
			? Delicat_Builder_V9_Design::carousel_enhancements()
			: array( 'cta' => true, 'tag' => true, 'bubble' => true, 'heart' => false );

		$style = in_array( $style, array( 'delicat_jeux', 'delicat_abonnement' ), true )
			? $style
			: 'delicat_jeux';

		/* pro.17: one engine decides both badges. $tag_text and $status_new_days
		 * are no longer consulted - free text could put arbitrary markup in a
		 * badge, and "new for 30 days" said nothing a shopper acts on. */
		$resolved = class_exists( 'Delicat_Builder_V9_Badges' )
			? Delicat_Builder_V9_Badges::for_product( $product, array( 'mode' => $badge_mode, 'sales_min' => $status_sales_min ) )
			: array( 'topic' => array(), 'status' => array() );
		$badge  = ! empty( $design['tag'] ) ? $resolved['topic'] : array();
		$status = $resolved['status'];

		$heart_mode = in_array( $heart_mode, array( 'auto', 'on', 'off' ), true ) ? $heart_mode : 'auto';
		$dynamic_heart = class_exists( 'Delicat_Builder_V9_Heart_Engine' ) && Delicat_Builder_V9_Heart_Engine::enabled();
		$heart_enabled = 'off' !== $heart_mode && (
			( 'on' === $heart_mode && $dynamic_heart )
			|| ( 'auto' === $heart_mode && ! empty( $design['heart'] ) && $dynamic_heart )
		);
		$is_favorite = $heart_enabled && is_user_logged_in()
			? Delicat_Builder_V9_Heart_Engine::is_favorite( $product_id )
			: false;
		$static_heart = ! $heart_enabled && ! empty( $design['bubble'] ) && 'off' !== $heart_mode;

		$image = array();
		$image_id = self::resolve_product_image_id( $product );
		if ( $image_id ) {
			$src = wp_get_attachment_image_src( $image_id, 'medium_large' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$image = array(
					'u' => esc_url_raw( $src[0] ),
					'w' => absint( $src[1] ?? 0 ),
					'h' => absint( $src[2] ?? 0 ),
				);
			}
		}
		if ( empty( $image['u'] ) && function_exists( 'wc_placeholder_img_src' ) ) {
			$image['u'] = esc_url_raw( wc_placeholder_img_src( 'woocommerce_thumbnail' ) );
		}

		$price = $show_price ? self::starting_price_parts( $product ) : array();

		return array(
			'i'   => $index,
			'id'  => $product_id,
			'u'   => esc_url_raw( $link ),
			'n'   => sanitize_text_field( $product->get_name() ),
			'im'  => $image,
			/* pro.17: the client payload carries the engine's own shape now -
			 * label, key, and the icon NAME rather than any markup. carousel.js
			 * looks the name up in the same table the server rendered from, so
			 * a card built in the browser and a card built on the server are
			 * the same card and neither can invent a badge. */
			'b'   => ! empty( $badge['label'] ) ? array(
				't' => sanitize_text_field( (string) $badge['label'] ),
				'k' => sanitize_key( (string) ( $badge['key'] ?? '' ) ),
				'o' => sanitize_key( (string) ( $badge['tone'] ?? 'topic' ) ),
				'ic' => sanitize_key( (string) ( $badge['icon'] ?? '' ) ),
			) : array(),
			'st'  => ! empty( $status['label'] ) ? array(
				't' => sanitize_text_field( (string) $status['label'] ),
				'k' => sanitize_key( (string) ( $status['key'] ?? '' ) ),
				'o' => sanitize_key( (string) ( $status['tone'] ?? 'status' ) ),
				'ic' => sanitize_key( (string) ( $status['icon'] ?? '' ) ),
			) : array(),
			'p'   => $price,
			'sub' => 'delicat_abonnement' === $style ? 1 : 0,
			'cta' => $show_cta ? 1 : 0,
			'he'  => $heart_enabled ? 1 : 0,
			'hf'  => $is_favorite ? 1 : 0,
			'hs'  => $static_heart ? 1 : 0,
			/* The name, not the mark: carousel.js draws it from the same table
			 * the server drew from, so neither can invent a shape. */
			'hi'  => sanitize_key( $heart_icon ),
		);
	}

	private static function product_card( int $product_id, int $index, bool $show_price, bool $show_stock, string $tag_text = '', string $badge_mode = 'auto', string $status_badge_mode = 'auto', int $status_new_days = 30, int $status_sales_min = 10, string $style = '', string $heart_mode = 'auto', string $heart_icon = 'heart', bool $show_cta = true, bool $priority_image = false ): string {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
			return '';
		}

		$link = get_permalink( $product_id );
		$image_id = self::resolve_product_image_id( $product );
		$design = class_exists( 'Delicat_Builder_V9_Design' )
			? Delicat_Builder_V9_Design::carousel_enhancements()
			: array( 'cta' => true, 'tag' => true, 'bubble' => true );

		$is_subscription = 'delicat_abonnement' === $style;
		if ( ! in_array( $style, array( 'delicat_jeux', 'delicat_abonnement' ), true ) ) {
			$style = 'delicat_jeux';
		}

		/* pro.17: the same resolution as the card renderer above, through the
		 * one engine. These two blocks had drifted: this one called the old
		 * engine without the class_exists guard the other one had, so a
		 * quarantined badge module fataled here and only warned there. */
		$resolved = class_exists( 'Delicat_Builder_V9_Badges' )
			? Delicat_Builder_V9_Badges::for_product( $product, array( 'mode' => $badge_mode, 'sales_min' => $status_sales_min ) )
			: array( 'topic' => array(), 'status' => array() );
		$badge        = ! empty( $design['tag'] ) ? $resolved['topic'] : array();
		$status_badge = $resolved['status'];

		$heart_mode = in_array( $heart_mode, array( 'auto', 'on', 'off' ), true ) ? $heart_mode : 'auto';
		$dynamic_heart_available = class_exists( 'Delicat_Builder_V9_Heart_Engine' ) && Delicat_Builder_V9_Heart_Engine::enabled();
		$heart_enabled = 'off' !== $heart_mode && (
			( 'on' === $heart_mode && $dynamic_heart_available )
			|| ( 'auto' === $heart_mode && ! empty( $design['heart'] ) && $dynamic_heart_available )
		);
		$heart_symbol = self::heart_symbol( $heart_icon );
		$is_favorite = $heart_enabled && is_user_logged_in()
			? Delicat_Builder_V9_Heart_Engine::is_favorite( $product_id )
			: false;

		$starting_price = $show_price ? self::starting_price_html( $product ) : '';
		$image_attrs = array(
			'class'                   => 'delicat-product-card__image',
			'loading'                 => $priority_image ? 'eager' : 'lazy',
			'decoding'                => 'async',
			'data-delicat-card-image' => '1',
			'sizes'                   => '(max-width:640px) 43vw, (max-width:960px) 30vw, 252px',
		);
		if ( $priority_image ) {
			$image_attrs['fetchpriority'] = 'high';
		}

		/* RC51.45: media-library attachments frequently ship without alt text,
		 * leaving empty alt="" on every card. Fall back to the product name so
		 * screen readers and image SEO get a real label; an author-set alt on
		 * the attachment always wins because we only fill the gap. */
		if ( $image_id > 0 ) {
			$existing_alt = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
			if ( '' === $existing_alt ) {
				$image_attrs['alt'] = wp_strip_all_tags( (string) $product->get_name() );
			}
		}

		ob_start();
		?>
		<article
			class="delicat-product-card"
			role="listitem"
			data-delicat-card
			data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
			data-delicat-card-index="<?php echo esc_attr( (string) $index ); ?>"
			data-delicat-card-style="<?php echo esc_attr( $style ); ?>"
		>
			<div class="delicat-product-card__media-wrap">
				<a class="delicat-product-card__media" href="<?php echo esc_url( $link ); ?>" data-delicat-prefetch data-delicat-product-link>
					<?php
					/* pro.17: rendered by the badge engine, which owns the
					 * markup, the class names and the drawn SVG icon. */
					echo Delicat_Builder_V9_Badges::render( $badge, 'topic' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render().
					?>

				<?php
				if ( $image_id && Delicat_Builder_V9_Media::is_image_attachment( $image_id ) ) {
					echo wp_get_attachment_image(
						$image_id,
						'medium_large',
						false,
						$image_attrs
					);
				} else {
					echo wc_placeholder_img(
						'woocommerce_thumbnail',
						array(
							'class'   => 'delicat-product-card__image',
							'loading' => 'lazy',
						)
					);
				}
				?>

					<?php echo Delicat_Builder_V9_Badges::render( $status_badge, 'status' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render(). ?>

					<?php /* RC39.3: product names belong in the content panel only. */ ?>
				</a>

				<?php if ( $heart_enabled ) : ?>
					<button
						type="button"
						class="delicat-product-card__bubble delicat-product-card__like<?php echo $is_favorite ? ' is-liked' : ''; ?>"
						data-delicat-heart
						data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
						aria-pressed="<?php echo $is_favorite ? 'true' : 'false'; ?>"
						aria-label="<?php echo esc_attr( $is_favorite ? __( 'Retirer des favoris', 'delicat-builder-v9' ) : __( 'Ajouter aux favoris', 'delicat-builder-v9' ) ); ?>"
					><?php echo Delicat_Builder_V9_Badges::favorite_mark( $heart_icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- drawn SVG from the badge engine's fixed table. ?></button>
				<?php elseif ( ! empty( $design['bubble'] ) && 'off' !== $heart_mode ) : ?>
					<span class="delicat-product-card__bubble" aria-hidden="true"><?php echo $heart_symbol; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- drawn SVG from the badge engine's fixed table. ?></span>
				<?php endif; ?>
			</div>

			<div class="delicat-product-card__body">
				<a class="delicat-product-card__name" href="<?php echo esc_url( $link ); ?>" data-delicat-prefetch data-delicat-product-link>
					<?php echo esc_html( $product->get_name() ); ?>
				</a>

				<?php if ( '' !== $starting_price ) : ?>
					<div class="delicat-product-card__price">
						<span><?php esc_html_e( 'À partir de', 'delicat-builder-v9' ); ?></span>
						<strong><?php echo wp_kses_post( $starting_price ); ?></strong>
					</div>
				<?php endif; ?>

				<?php if ( $show_cta ) : ?>
					<a class="delicat-product-card__cta" href="<?php echo esc_url( $link ); ?>" data-delicat-prefetch data-delicat-product-link data-delicat-instant-product>
						<?php echo self::cart_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php esc_html_e( 'Acheter', 'delicat-builder-v9' ); ?></span>
					</a>
				<?php endif; ?>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}
}
