<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visual WooCommerce archive configuration.
 *
 * Important:
 * - This does not copy archive-product.php.
 * - WooCommerce remains responsible for the product query/loop/pagination.
 * - Config is opt-in per virtual archive target.
 * - Specific targets override the global archive design.
 */
final class Delicat_Builder_V9_Archive_Builder {
	public const OPTION = 'delicat_builder_v9_archive_builder';
	public const DRAFT_OPTION = 'delicat_builder_v9_archive_builder_drafts';

	private static ?array $configs_cache = null;
	private static ?array $drafts_cache = null;
	/* Resolved archive identity for this request. Only populated once the main
	 * query is settled -- see current_target(). */
	private static ?string $target_cache = null;
	private static ?array $active_cache = null;

	public static function boot(): void {
		add_action( 'admin_post_delicat_builder_v9_save_archive', array( __CLASS__, 'save' ) );
		add_filter( 'template_include', array( __CLASS__, 'force_native_template' ), PHP_INT_MAX );
		add_action( 'template_redirect', array( __CLASS__, 'protect_preview_request' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'prepare_cache_policy' ), 4 );
		add_action( 'wp_footer', array( __CLASS__, 'render_preview_badge' ), 999 );
		add_action( 'woocommerce_after_shop_loop', array( __CLASS__, 'render_trust_strip' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install_native_preset' ), 25 );
	}

	public static function defaults(): array {
		return array(
			'enabled'                   => 0,
			'title_mode'                => 'dynamic',
			'custom_title'              => '',
			'subtitle'                  => '',
			'show_header'               => 1,
			'show_description'          => 0,
			'header_bg'                 => '#fbf7ff',
			'header_border'             => '#eee7f8',
			'title_color'               => '#17152a',
			'subtitle_color'            => '#75718a',
			'accent_color'              => '#6d5dfc',
			'header_radius'             => 26,
			'header_padding_d'          => 34,
			'header_padding_m'          => 22,
			'header_margin_bottom'      => 26,
			'max_width'                 => 1320,
			'card_style'                => 'premium',
			'image_ratio'               => 'square',
			'grid_desktop'              => 4,
			'grid_tablet'               => 3,
			'grid_mobile'               => 2,
			'products_per_page'         => 24,
			'show_stock_badge'          => 1,
			'show_ordering'             => 1,
			'show_result_count'         => 1,
			'show_rating'               => 0,
			'compact_mobile'            => 1,
			'fast_mode'                 => 1,
			'touch_prefetch'            => 1,
			'cta_mode'                  => 'product',
			'grid_gap_d'                => 22,
			'grid_gap_m'                => 10,
			'card_radius'               => 20,
			'card_bg'                   => '',
			'card_border'               => '',
			'card_shadow'               => 'soft',
			'image_fit'                 => 'cover',
			'image_bg'                  => '',
			'title_lines'               => 2,
			'title_size_d'              => 15,
			'title_size_m'              => 13,
			'price_size_d'              => 16,
			'price_size_m'              => 14,
			'show_title'                => 1,
			'show_price'                => 1,
			'show_sale_badge'           => 1,
			'show_category_badge'       => 1,
			'show_cta'                  => 1,
			'cta_label'                 => __( 'Acheter', 'delicat-builder-v9' ),
			'cta_radius'                => 18,
			'show_pagination'           => 1,
			'pagination_style'          => 'pills',
			'hover_motion'              => 1,
		);
	}

	public static function configs(): array {
		if ( null !== self::$configs_cache ) {
			return self::$configs_cache;
		}
		$saved = get_option( self::OPTION, array() );
		self::$configs_cache = is_array( $saved ) ? $saved : array();
		return self::$configs_cache;
	}

	public static function get_config( string $target ): array {
		$target = self::sanitize_target( $target );
		$configs = self::configs();
		$saved = isset( $configs[ $target ] ) && is_array( $configs[ $target ] ) ? $configs[ $target ] : array();
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function drafts(): array {
		if ( null !== self::$drafts_cache ) {
			return self::$drafts_cache;
		}
		$saved = get_option( self::DRAFT_OPTION, array() );
		self::$drafts_cache = is_array( $saved ) ? $saved : array();
		return self::$drafts_cache;
	}

	public static function get_draft_config( string $target ): array {
		$target = self::sanitize_target( $target );
		$drafts = self::drafts();
		$saved = isset( $drafts[ $target ] ) && is_array( $drafts[ $target ] ) ? $drafts[ $target ] : array();
		return wp_parse_args( $saved, self::get_config( $target ) );
	}

	public static function has_draft( string $target ): bool {
		$target = self::sanitize_target( $target );
		$drafts = self::drafts();
		return isset( $drafts[ $target ] ) && is_array( $drafts[ $target ] );
	}

	public static function is_published( string $target ): bool {
		$config = self::get_config( $target );
		return ! empty( $config['enabled'] ) && ! empty( $config['force_native_template'] );
	}

	private static function preview_target(): string {
		if ( empty( $_GET['delicat_v9_preview'] ) || 'archive' !== sanitize_key( (string) wp_unslash( $_GET['delicat_v9_preview'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		if ( ! is_user_logged_in() || ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) ) {
			return '';
		}
		$target = self::sanitize_target( isset( $_GET['delicat_v9_target'] ) ? (string) wp_unslash( $_GET['delicat_v9_target'] ) : 'global' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce  = isset( $_GET['delicat_v9_nonce'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['delicat_v9_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $nonce, 'delicat_builder_v9_preview_archive_' . $target ) ) {
			return '';
		}
		return $target;
	}

	public static function preview_url( string $target ): string {
		$target = self::sanitize_target( $target );
		$url = self::target_url( $target );
		if ( '' === $url ) {
			return '';
		}
		return add_query_arg( array( 'delicat_v9_preview' => 'archive', 'delicat_v9_target' => $target, 'delicat_v9_nonce' => wp_create_nonce( 'delicat_builder_v9_preview_archive_' . $target ) ), $url );
	}

	public static function protect_preview_request(): void {
		if ( '' === self::preview_target() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		add_filter( 'wp_robots', array( __CLASS__, 'preview_robots' ), 999 );
	}

	public static function preview_robots( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();
		$robots['noindex'] = true;
		$robots['nofollow'] = true;
		return $robots;
	}

	/**
	 * Native catalog archives are excellent full-page-cache candidates, but only
	 * for clean anonymous requests. Cart/session/currency/query/geolocation
	 * variants are explicitly isolated so no shopper state can reach a CDN copy.
	 */
	public static function prepare_cache_policy(): void {
		if ( empty( self::active_config() ) ) {
			return;
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Performance', false ) || ! is_callable( array( 'Delicat_Builder_V9_Performance', 'settings' ) ) ) {
			return;
		}
		$performance = Delicat_Builder_V9_Performance::settings();
		if ( empty( $performance['server_page_cache_hint'] ) ) {
			return;
		}

		$clean = class_exists( 'Delicat_Builder_V9_Security', false )
			&& Delicat_Builder_V9_Security::public_cache_allowed();
		$location = sanitize_key( (string) get_option( 'woocommerce_default_customer_address', 'base' ) );
		// geolocation_ajax is WooCommerce's page-cache-compatible mode; only
		// plain server-side geolocation makes one public URL vary directly by IP.
		$geolocated = 'geolocation' === $location;

		if ( $clean && ! $geolocated ) {
			do_action( 'litespeed_control_set_cacheable', 'Delicat V9 native product archive' );
			if ( ! headers_sent() ) {
				header( 'X-Delicat-V9-Archive-Cache: public' );
			}
			return;
		}

		/* RC32: a signed-in customer's archive may live in LiteSpeed's private cache. */
		if ( ! $geolocated && class_exists( 'Delicat_Builder_V9_Security', false ) && is_callable( array( 'Delicat_Builder_V9_Security', 'private_cache_allowed' ) ) && Delicat_Builder_V9_Security::private_cache_allowed() ) {
			Delicat_Builder_V9_Security::hint_private_cache( 'Delicat V9 native archive (signed-in)' );
			if ( ! headers_sent() ) {
				header( 'X-Delicat-V9-Archive-Cache: private' );
			}
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
			header( 'X-Delicat-V9-Archive-Cache: bypass' );
		}
		do_action( 'litespeed_control_set_nocache', $geolocated ? 'Delicat V9 geolocation-aware archive' : 'Delicat V9 private/session archive' );
	}

	public static function force_native_template( $template ) {
		if ( ! is_string( $template ) ) {
			return $template;
		}
		try {
			if ( is_admin() || wp_doing_ajax() ) {
				return $template;
			}
			$target = self::current_target();
			if ( '' === $target ) {
				return $template;
			}
			$config = self::active_config();
			$preview = ! empty( $config['_preview'] );
			if ( ! $preview && ( ! class_exists( 'Delicat_Builder_V9_Core', false ) || ! is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) ) || ! Delicat_Builder_V9_Core::is_enabled() ) ) {
				return $template;
			}
			if ( ! $preview && ! self::is_published( $target ) && ! self::is_published( 'global' ) ) {
				return $template;
			}
			$native = DELICAT_BUILDER_V9_DIR . 'templates/native-archive.php';
			if ( is_readable( $native ) ) {
				return $native;
			}
		} catch ( Throwable $error ) {
			self::record_template_failure( $error, 'archive' );
		}
		return $template;
	}

	private static function record_template_failure( Throwable $error, string $context ): void {
		update_option(
			'delicat_builder_v9_last_template_failure',
			array(
				'time' => gmdate( 'c' ),
				'context' => sanitize_key( $context ),
				'file' => sanitize_file_name( basename( $error->getFile() ) ),
				'line' => absint( $error->getLine() ),
				'hash' => hash( 'sha256', $context . '|' . $error->getMessage() . '|' . $error->getFile() . '|' . $error->getLine() ),
			),
			false
		);
	}

	public static function render_preview_badge(): void {
		if ( '' === self::preview_target() ) {
			return;
		}
		echo '<div style="position:fixed;z-index:2147483000;left:12px;bottom:12px;padding:9px 12px;border-radius:999px;background:#111827;color:#fff;font:600 12px/1.2 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.25)">' . esc_html__( 'V9 PRIVATE PREVIEW · shoppers still see the current live archive', 'delicat-builder-v9' ) . '</div>';
	}

	public static function is_enabled( string $target ): bool {
		$config = self::get_config( $target );
		return ! empty( $config['enabled'] );
	}

	/**
	 * Which archive this request is, as a config key ('shop', 'cat:12', ...).
	 *
	 * Asked about eleven times per archive request -- by the cache policy, the
	 * template override, the Woo settings merge, the header, the trust strip,
	 * the page-title filter and the body classes -- and each answer cost up to
	 * three taxonomy_exists() checks and a get_term_by() slug lookup. Resolved
	 * once per request now.
	 *
	 * The memo is only filled in after the `wp` action. WooCommerce asks for
	 * loop settings from pre_get_posts, where the conditional tags are not
	 * final yet; caching that early answer would pin the wrong archive -- or
	 * an empty one -- for the rest of the request.
	 */
	public static function current_target(): string {
		if ( null !== self::$target_cache ) {
			return self::$target_cache;
		}
		$target = self::resolve_current_target();
		if ( did_action( 'wp' ) ) {
			self::$target_cache = $target;
		}
		return $target;
	}

	private static function resolve_current_target(): string {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return '';
		}

		// Woo may ask for loop settings while the main query is still being
		// constructed. Resolve taxonomy query vars first so per-category
		// products-per-page/grid settings are available early enough.
		foreach ( array(
			'product_cat'   => 'cat',
			'product_tag'   => 'tag',
			'product_brand' => 'brand',
		) as $taxonomy => $prefix ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$slug = get_query_var( $taxonomy );
			if ( is_string( $slug ) && '' !== $slug ) {
				$term = get_term_by( 'slug', sanitize_title( $slug ), $taxonomy );
				if ( $term instanceof WP_Term ) {
					return $prefix . ':' . absint( $term->term_id );
				}
			}
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				return 'cat:' . absint( $term->term_id );
			}
		}

		if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				return 'tag:' . absint( $term->term_id );
			}
		}

		if ( taxonomy_exists( 'product_brand' ) && is_tax( 'product_brand' ) ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				return 'brand:' . absint( $term->term_id );
			}
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'shop';
		}

		if ( is_post_type_archive( 'product' ) ) {
			return 'shop';
		}

		return '';
	}

	/**
	 * The published config governing this archive, or an empty array.
	 *
	 * Memoised on the same terms as current_target(): only once the main query
	 * is settled, because the answer depends on it.
	 */
	public static function active_config(): array {
		if ( null !== self::$active_cache ) {
			return self::$active_cache;
		}
		$config = self::resolve_active_config();
		if ( did_action( 'wp' ) ) {
			self::$active_cache = $config;
		}
		return $config;
	}

	private static function resolve_active_config(): array {
		$target = self::current_target();
		if ( '' === $target ) {
			return array();
		}

		$preview_target = self::preview_target();
		if ( '' !== $preview_target ) {
			$preview = self::get_draft_config( $preview_target );
			$preview['enabled'] = 1;
			$preview['_target'] = $preview_target;
			$preview['_source'] = 'draft-preview';
			$preview['_preview'] = 1;
			$preview['force_native_template'] = 1;
			return $preview;
		}

		$specific = self::get_config( $target );
		if ( self::is_published( $target ) ) {
			$specific['_target'] = $target;
			$specific['_source'] = 'specific';
			return $specific;
		}

		$global = self::get_config( 'global' );
		if ( self::is_published( 'global' ) ) {
			$global['_target'] = 'global';
			$global['_source'] = 'global';
			return $global;
		}

		return array();
	}

	public static function woo_overrides(): array {
		$config = self::active_config();
		if ( empty( $config ) ) {
			return array();
		}

		return array(
			'enabled'                       => 1,
			'archive_enabled'               => 1,
			'archive_fast_mode'             => empty( $config['fast_mode'] ) ? 0 : 1,
			'archive_products_per_page'     => absint( $config['products_per_page'] ?? 12 ),
			'archive_show_result_count'     => empty( $config['show_result_count'] ) ? 0 : 1,
			'archive_show_ordering'         => empty( $config['show_ordering'] ) ? 0 : 1,
			'archive_show_rating'           => empty( $config['show_rating'] ) ? 0 : 1,
			'archive_cta_mode'              => $config['cta_mode'] ?? 'product',
			'archive_touch_prefetch'        => empty( $config['touch_prefetch'] ) ? 0 : 1,
			'card_style'                    => $config['card_style'] ?? 'premium',
			'grid_desktop'                  => absint( $config['grid_desktop'] ?? 4 ),
			'grid_tablet'                   => absint( $config['grid_tablet'] ?? 3 ),
			'grid_mobile'                   => absint( $config['grid_mobile'] ?? 2 ),
			'image_ratio'                   => $config['image_ratio'] ?? 'square',
			'show_stock_badge'              => empty( $config['show_stock_badge'] ) ? 0 : 1,
			'max_width'                     => absint( $config['max_width'] ?? 1320 ),
			'accent_color'                  => $config['accent_color'] ?? '#6d5dfc',
			'compact_mobile'                => empty( $config['compact_mobile'] ) ? 0 : 1,
			'archive_grid_gap_d'             => max( 4, min( 48, absint( $config['grid_gap_d'] ?? 22 ) ) ),
			'archive_grid_gap_m'             => max( 4, min( 28, absint( $config['grid_gap_m'] ?? 10 ) ) ),
			'archive_card_radius'            => max( 0, min( 36, absint( $config['card_radius'] ?? 20 ) ) ),
			'archive_card_bg'                => sanitize_hex_color( (string) ( $config['card_bg'] ?? '' ) ) ?: '',
			'archive_card_border'            => sanitize_hex_color( (string) ( $config['card_border'] ?? '' ) ) ?: '',
			'archive_card_shadow'            => in_array( $config['card_shadow'] ?? '', array( 'none', 'soft', 'elevated' ), true ) ? $config['card_shadow'] : 'soft',
			'archive_image_fit'              => in_array( $config['image_fit'] ?? '', array( 'cover', 'contain' ), true ) ? $config['image_fit'] : 'cover',
			'archive_image_bg'               => sanitize_hex_color( (string) ( $config['image_bg'] ?? '' ) ) ?: '',
			'archive_title_lines'            => max( 1, min( 3, absint( $config['title_lines'] ?? 2 ) ) ),
			'archive_title_size_d'           => max( 12, min( 24, absint( $config['title_size_d'] ?? 15 ) ) ),
			'archive_title_size_m'           => max( 11, min( 20, absint( $config['title_size_m'] ?? 13 ) ) ),
			'archive_price_size_d'           => max( 12, min( 26, absint( $config['price_size_d'] ?? 16 ) ) ),
			'archive_price_size_m'           => max( 11, min( 22, absint( $config['price_size_m'] ?? 14 ) ) ),
			'archive_show_title'             => empty( $config['show_title'] ) ? 0 : 1,
			'archive_show_price'             => empty( $config['show_price'] ) ? 0 : 1,
			'archive_show_sale_badge'        => empty( $config['show_sale_badge'] ) ? 0 : 1,
			'archive_show_category_badge'    => empty( $config['show_category_badge'] ) ? 0 : 1,
			'archive_show_cta'               => empty( $config['show_cta'] ) ? 0 : 1,
			'archive_cta_label'              => sanitize_text_field( (string) ( $config['cta_label'] ?? __( 'Acheter', 'delicat-builder-v9' ) ) ),
			'archive_cta_radius'             => max( 8, min( 30, absint( $config['cta_radius'] ?? 18 ) ) ),
			'archive_show_pagination'        => empty( $config['show_pagination'] ) ? 0 : 1,
			'archive_pagination_style'       => in_array( $config['pagination_style'] ?? '', array( 'pills', 'minimal' ), true ) ? $config['pagination_style'] : 'pills',
			'archive_hover_motion'           => empty( $config['hover_motion'] ) ? 0 : 1,
			'archive_show_header'            => empty( $config['show_header'] ) ? 0 : 1,
		);
	}

	public static function sanitize_target( string $target ): string {
		$target = strtolower( trim( $target ) );
		if ( in_array( $target, array( 'global', 'shop' ), true ) ) {
			return $target;
		}
		if ( preg_match( '/^(cat|tag|brand):([1-9][0-9]*)$/', $target, $match ) ) {
			return $match[1] . ':' . absint( $match[2] );
		}
		return 'global';
	}

	public static function target_label( string $target ): string {
		$target = self::sanitize_target( $target );
		if ( 'global' === $target ) {
			return __( 'All Product Archives', 'delicat-builder-v9' );
		}
		if ( 'shop' === $target ) {
			return __( 'Shop / All Products', 'delicat-builder-v9' );
		}

		list( $type, $term_id ) = array_pad( explode( ':', $target, 2 ), 2, 0 );
		$taxonomy = array(
			'cat'   => 'product_cat',
			'tag'   => 'product_tag',
			'brand' => 'product_brand',
		)[ $type ] ?? '';

		if ( $taxonomy && taxonomy_exists( $taxonomy ) ) {
			$term = get_term( absint( $term_id ), $taxonomy );
			if ( $term instanceof WP_Term ) {
				return $term->name;
			}
		}

		return __( 'Product Archive', 'delicat-builder-v9' );
	}

	public static function target_url( string $target ): string {
		$target = self::sanitize_target( $target );
		if ( 'global' === $target || 'shop' === $target ) {
			if ( function_exists( 'wc_get_page_permalink' ) ) {
				$url = wc_get_page_permalink( 'shop' );
				return is_string( $url ) ? $url : '';
			}
			$url = get_post_type_archive_link( 'product' );
			return is_string( $url ) ? $url : '';
		}

		list( $type, $term_id ) = array_pad( explode( ':', $target, 2 ), 2, 0 );
		$taxonomy = array(
			'cat'   => 'product_cat',
			'tag'   => 'product_tag',
			'brand' => 'product_brand',
		)[ $type ] ?? '';
		if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}
		$link = get_term_link( absint( $term_id ), $taxonomy );
		return is_wp_error( $link ) ? '' : (string) $link;
	}

	public static function dynamic_title(): string {
		$target = self::current_target();
		if ( 'shop' === $target ) {
			return function_exists( 'woocommerce_page_title' ) ? woocommerce_page_title( false ) : __( 'Shop', 'delicat-builder-v9' );
		}
		$obj = get_queried_object();
		return $obj instanceof WP_Term ? $obj->name : __( 'Products', 'delicat-builder-v9' );
	}

	public static function render_header(): void {
		$config = self::active_config();
		if ( empty( $config ) ) {
			return;
		}
		if ( empty( $config['show_header'] ) ) {
			return;
		}

		$mode = in_array( $config['title_mode'] ?? 'dynamic', array( 'dynamic', 'custom', 'hidden' ), true )
			? $config['title_mode']
			: 'dynamic';

		$title = '';
		if ( 'dynamic' === $mode ) {
			$title = self::dynamic_title();
		} elseif ( 'custom' === $mode ) {
			$title = sanitize_text_field( (string) ( $config['custom_title'] ?? '' ) );
		}

		$subtitle = sanitize_text_field( (string) ( $config['subtitle'] ?? '' ) );
		$show_description = ! empty( $config['show_description'] );
		$description = '';
		if ( $show_description ) {
			$obj = get_queried_object();
			if ( $obj instanceof WP_Term ) {
				$description = term_description( $obj->term_id, $obj->taxonomy );
			}
		}

		if ( '' === $title && '' === $subtitle && '' === trim( wp_strip_all_tags( $description ) ) ) {
			return;
		}

		$style = sprintf(
			'--db-archive-bg:%1$s;--db-archive-border:%2$s;--db-archive-title:%3$s;--db-archive-subtitle:%4$s;--db-archive-accent:%5$s;--db-archive-radius:%6$dpx;--db-archive-pad-d:%7$dpx;--db-archive-pad-m:%8$dpx;--db-archive-mb:%9$dpx',
			sanitize_hex_color( $config['header_bg'] ?? '#07091d' ) ?: '#07091d',
			sanitize_hex_color( $config['header_border'] ?? '#20254b' ) ?: '#20254b',
			sanitize_hex_color( $config['title_color'] ?? '#ffffff' ) ?: '#ffffff',
			sanitize_hex_color( $config['subtitle_color'] ?? '#9da4b8' ) ?: '#9da4b8',
			sanitize_hex_color( $config['accent_color'] ?? '#6d5dfc' ) ?: '#6d5dfc',
			max( 0, min( 48, absint( $config['header_radius'] ?? 26 ) ) ),
			max( 12, min( 80, absint( $config['header_padding_d'] ?? 34 ) ) ),
			max( 10, min( 60, absint( $config['header_padding_m'] ?? 22 ) ) ),
			max( 0, min( 80, absint( $config['header_margin_bottom'] ?? 26 ) ) )
		);

		echo '<nav class="delicat-native-breadcrumbs" aria-label="' . esc_attr__( 'Fil d’Ariane', 'delicat-builder-v9' ) . '"><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Accueil', 'delicat-builder-v9' ) . '</a><span aria-hidden="true">›</span><strong>' . esc_html( $title ?: self::dynamic_title() ) . '</strong></nav>';
		echo '<section class="delicat-archive-builder-header" style="' . esc_attr( $style ) . '">';
		echo '<span class="delicat-archive-builder-header__accent" aria-hidden="true"></span>';
		echo '<span class="delicat-archive-builder-header__eyebrow">' . esc_html__( 'BOUTIQUE DELICAT', 'delicat-builder-v9' ) . '</span>';
		if ( '' !== $title ) {
			echo '<h1>' . esc_html( $title ) . '</h1>';
		}
		if ( '' !== $subtitle ) {
			echo '<p class="delicat-archive-builder-header__subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		if ( '' !== trim( wp_strip_all_tags( $description ) ) ) {
			echo '<div class="delicat-archive-builder-header__description">' . wp_kses_post( $description ) . '</div>';
		}
		echo '</section>';
		self::render_category_chips();
	}

	/** Native category navigation, cached through WordPress' term cache. */
	public static function render_category_chips(): void {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}
		$active = is_product_category() ? absint( get_queried_object_id() ) : 0;
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
		$chips = array(
			array( 'label' => 'Jeux', 'icon' => 'gamepad', 'slugs' => array( 'jeux', 'games', 'jeu' ), 'names' => array( 'Jeux', 'Games' ) ),
			array( 'label' => 'Gift Card', 'icon' => 'gift', 'slugs' => array( 'gift-card', 'gift-cards', 'cartes-cadeaux', 'carte-cadeau' ), 'names' => array( 'Gift Card', 'Gift Cards', 'Cartes cadeaux', 'Carte cadeau' ) ),
			array( 'label' => 'Abonnement', 'icon' => 'play', 'slugs' => array( 'abonnements', 'abonnement', 'subscriptions', 'streaming' ), 'names' => array( 'Abonnements', 'Abonnement', 'Subscriptions', 'Streaming' ) ),
		);
		echo '<nav class="delicat-native-category-chips" aria-label="' . esc_attr__( 'Catégories de produits', 'delicat-builder-v9' ) . '">';
		echo '<a class="' . ( 0 === $active ? 'is-active' : '' ) . '" href="' . esc_url( $shop ) . '" data-delicat-prefetch>' . self::category_icon_svg( 'bolt' ) . '<span>' . esc_html__( 'Tout voir', 'delicat-builder-v9' ) . '</span></a>';
		foreach ( $chips as $chip ) {
			$target = self::resolve_native_category( $chip['slugs'], $chip['names'], $shop );
			echo '<a class="' . ( $active > 0 && $active === $target['term_id'] ? 'is-active' : '' ) . '" href="' . esc_url( $target['url'] ) . '" data-delicat-prefetch>' . self::category_icon_svg( $chip['icon'] ) . '<span>' . esc_html( $chip['label'] ) . '</span></a>';
		}
		echo '</nav>';
	}

	/** Stable inline icons avoid platform-dependent emoji rendering in the archive tabs. */
	private static function category_icon_svg( string $token ): string {
		$paths = array(
			'bolt'    => '<path d="M13 2 4.5 13h6L9 22l8.5-11h-6L13 2Z"/>',
			'gamepad' => '<path d="M7.5 8h9a4.5 4.5 0 0 1 4.2 6.1l-1 2.8a2.4 2.4 0 0 1-3.9 1l-1.6-1.4H9.8l-1.6 1.4a2.4 2.4 0 0 1-3.9-1l-1-2.8A4.5 4.5 0 0 1 7.5 8Z"/><path d="M7 12v4M5 14h4M16.5 12.5h.01M18.5 15h.01"/>',
			'gift'    => '<path d="M3 10h18v4H3zM5 14h14v7H5zM12 10v11M12 10H7.5a2.5 2.5 0 1 1 2.1-3.9L12 10Zm0 0h4.5a2.5 2.5 0 1 0-2.1-3.9L12 10Z"/>',
			'play'    => '<path d="m9 7 8 5-8 5V7Z"/>',
		);
		$path = $paths[ $token ] ?? $paths['bolt'];
		return '<svg class="delicat-native-category-chips__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/** Resolve the four storefront chips without exposing arbitrary categories. */
	private static function resolve_native_category( array $slugs, array $names, string $fallback ): array {
		$term = null;
		foreach ( $slugs as $slug ) {
			$candidate = get_term_by( 'slug', sanitize_title( $slug ), 'product_cat' );
			if ( $candidate instanceof WP_Term ) {
				$term = $candidate;
				break;
			}
		}
		if ( ! $term ) {
			foreach ( $names as $name ) {
				$candidate = get_term_by( 'name', $name, 'product_cat' );
				if ( $candidate instanceof WP_Term ) {
					$term = $candidate;
					break;
				}
			}
		}
		if ( ! $term ) {
			return array( 'term_id' => 0, 'url' => $fallback );
		}
		$link = get_term_link( $term );
		return array(
			'term_id' => absint( $term->term_id ),
			'url'     => is_wp_error( $link ) ? $fallback : $link,
		);
	}

	public static function render_trust_strip(): void {
		if ( empty( self::active_config() ) ) {
			return;
		}
		$bolt = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M13 2 5 14h6l-1 8 9-13h-6V2Z"/></svg>';
		$shield = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Zm-3 8 2 2 4-4"/></svg>';
		$headset = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 14v-2a8 8 0 0 1 16 0v2M6 18H5a2 2 0 0 1-2-2v-1a2 2 0 0 1 2-2h2v6c0 1.1.9 2 2 2h4m5-3h1a2 2 0 0 0 2-2v-1a2 2 0 0 0-2-2h-2v5Z"/></svg>';
		echo '<aside class="delicat-native-trust" aria-label="' . esc_attr__( 'Garanties Delicat', 'delicat-builder-v9' ) . '">'
			. '<span>' . $bolt . esc_html__( 'Livraison rapide', 'delicat-builder-v9' ) . '</span>'
			. '<span>' . $shield . esc_html__( 'Paiement sécurisé', 'delicat-builder-v9' ) . '</span>'
			. '<span>' . $headset . esc_html__( 'Support disponible', 'delicat-builder-v9' ) . '</span>'
			. '</aside>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** One-time native storefront preset. Existing per-category titles remain intact. */
	public static function maybe_install_native_preset(): void {
		$cli = defined( 'WP_CLI' ) && WP_CLI;
		if ( ! $cli && ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'delicat_builder_v9_native_archive_preset', '' ) === DELICAT_BUILDER_V9_VERSION ) {
			return;
		}
		$preset = array(
			'enabled' => 1, 'force_native_template' => 1, 'show_header' => 1,
			'header_bg' => '#fbf7ff', 'header_border' => '#eee7f8', 'title_color' => '#17152a',
			'subtitle_color' => '#75718a', 'accent_color' => '#705cff', 'header_radius' => 24,
			'products_per_page' => 24, 'show_result_count' => 1, 'show_ordering' => 1,
			'card_style' => 'premium', 'image_ratio' => 'landscape', 'grid_desktop' => 4,
			'grid_tablet' => 3, 'grid_mobile' => 2, 'compact_mobile' => 1, 'fast_mode' => 1,
			'touch_prefetch' => 1, 'cta_mode' => 'product', 'cta_label' => __( 'Choisir', 'delicat-builder-v9' ),
			'show_stock_badge' => 1, 'show_category_badge' => 1, 'show_pagination' => 1,
			'published_at' => time(),
		);
		$configs = self::configs();
		if ( empty( $configs ) ) {
			$configs = array( 'global' => array_merge( self::defaults(), $preset ) );
		} else {
			foreach ( $configs as $target => $config ) {
				if ( is_array( $config ) && ! empty( $config['enabled'] ) ) {
					$configs[ $target ] = wp_parse_args( $config, $preset );
				}
			}
			if ( empty( $configs['global'] ) || ! is_array( $configs['global'] ) ) {
				$configs['global'] = array_merge( self::defaults(), $preset );
			} else {
				$configs['global'] = wp_parse_args( $configs['global'], $preset );
			}
		}
		update_option( self::OPTION, $configs, false );
		update_option( 'delicat_builder_v9_native_archive_preset', DELICAT_BUILDER_V9_VERSION, false );
		self::$configs_cache = $configs;
		self::$target_cache = null;
		self::$active_cache = null;
		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) ) {
			Delicat_Builder_V9_Cache::bump_version();
			Delicat_Builder_V9_Cache::purge_product_archives();
		}
	}

	public static function show_default_page_title( $show ) {
		return empty( self::active_config() ) ? $show : false;
	}

	public static function configure_frontend(): void {
		if ( empty( self::active_config() ) ) {
			return;
		}

		remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
		remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );
	}

	private static function safe_max_width( $value ): int {
		$value = absint( $value );
		return in_array( $value, array( 1200, 1320, 1440 ), true ) ? $value : 1320;
	}

	private static function safe_card_style( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'premium', 'glass', 'minimal', 'gaming' ), true ) ? $value : 'premium';
	}

	private static function safe_image_ratio( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'square', 'portrait', 'landscape' ), true ) ? $value : 'square';
	}

	private static function safe_grid_value( $value, int $min, int $max ): int {
		return min( $max, max( $min, absint( $value ) ) );
	}

	public static function sanitize_config( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$clean = array(
			'enabled'              => empty( $input['enabled'] ) ? 0 : 1,
			'title_mode'           => in_array( $input['title_mode'] ?? '', array( 'dynamic', 'custom', 'hidden' ), true ) ? $input['title_mode'] : 'dynamic',
			'custom_title'         => sanitize_text_field( (string) ( $input['custom_title'] ?? '' ) ),
			'subtitle'             => sanitize_text_field( (string) ( $input['subtitle'] ?? '' ) ),
			'show_header'          => empty( $input['show_header'] ) ? 0 : 1,
			'show_description'     => empty( $input['show_description'] ) ? 0 : 1,
			'header_bg'            => sanitize_hex_color( (string) ( $input['header_bg'] ?? $defaults['header_bg'] ) ) ?: $defaults['header_bg'],
			'header_border'        => sanitize_hex_color( (string) ( $input['header_border'] ?? $defaults['header_border'] ) ) ?: $defaults['header_border'],
			'title_color'          => sanitize_hex_color( (string) ( $input['title_color'] ?? $defaults['title_color'] ) ) ?: $defaults['title_color'],
			'subtitle_color'       => sanitize_hex_color( (string) ( $input['subtitle_color'] ?? $defaults['subtitle_color'] ) ) ?: $defaults['subtitle_color'],
			'accent_color'         => sanitize_hex_color( (string) ( $input['accent_color'] ?? $defaults['accent_color'] ) ) ?: $defaults['accent_color'],
			'header_radius'        => max( 0, min( 48, absint( $input['header_radius'] ?? $defaults['header_radius'] ) ) ),
			'header_padding_d'     => max( 12, min( 80, absint( $input['header_padding_d'] ?? $defaults['header_padding_d'] ) ) ),
			'header_padding_m'     => max( 10, min( 60, absint( $input['header_padding_m'] ?? $defaults['header_padding_m'] ) ) ),
			'header_margin_bottom' => max( 0, min( 80, absint( $input['header_margin_bottom'] ?? $defaults['header_margin_bottom'] ) ) ),
			'max_width'            => self::safe_max_width( $input['max_width'] ?? $defaults['max_width'] ),
			'card_style'           => self::safe_card_style( $input['card_style'] ?? $defaults['card_style'] ),
			'image_ratio'          => self::safe_image_ratio( $input['image_ratio'] ?? $defaults['image_ratio'] ),
			'grid_desktop'         => self::safe_grid_value( $input['grid_desktop'] ?? $defaults['grid_desktop'], 2, 6 ),
			'grid_tablet'          => self::safe_grid_value( $input['grid_tablet'] ?? $defaults['grid_tablet'], 2, 4 ),
			'grid_mobile'          => self::safe_grid_value( $input['grid_mobile'] ?? $defaults['grid_mobile'], 1, 2 ),
			'products_per_page'    => min( 24, max( 8, absint( $input['products_per_page'] ?? $defaults['products_per_page'] ) ) ),
			'show_stock_badge'     => empty( $input['show_stock_badge'] ) ? 0 : 1,
			'show_ordering'        => empty( $input['show_ordering'] ) ? 0 : 1,
			'show_result_count'    => empty( $input['show_result_count'] ) ? 0 : 1,
			'show_rating'          => empty( $input['show_rating'] ) ? 0 : 1,
			'compact_mobile'       => empty( $input['compact_mobile'] ) ? 0 : 1,
			'fast_mode'            => empty( $input['fast_mode'] ) ? 0 : 1,
			'touch_prefetch'       => empty( $input['touch_prefetch'] ) ? 0 : 1,
			'cta_mode'             => in_array( $input['cta_mode'] ?? '', array( 'product', 'woo' ), true ) ? $input['cta_mode'] : 'product',
			'grid_gap_d'           => max( 4, min( 48, absint( $input['grid_gap_d'] ?? $defaults['grid_gap_d'] ) ) ),
			'grid_gap_m'           => max( 4, min( 28, absint( $input['grid_gap_m'] ?? $defaults['grid_gap_m'] ) ) ),
			'card_radius'          => max( 0, min( 36, absint( $input['card_radius'] ?? $defaults['card_radius'] ) ) ),
			'card_bg'              => sanitize_hex_color( (string) ( $input['card_bg'] ?? '' ) ) ?: '',
			'card_border'          => sanitize_hex_color( (string) ( $input['card_border'] ?? '' ) ) ?: '',
			'card_shadow'          => in_array( $input['card_shadow'] ?? '', array( 'none', 'soft', 'elevated' ), true ) ? $input['card_shadow'] : $defaults['card_shadow'],
			'image_fit'            => in_array( $input['image_fit'] ?? '', array( 'cover', 'contain' ), true ) ? $input['image_fit'] : $defaults['image_fit'],
			'image_bg'             => sanitize_hex_color( (string) ( $input['image_bg'] ?? '' ) ) ?: '',
			'title_lines'          => max( 1, min( 3, absint( $input['title_lines'] ?? $defaults['title_lines'] ) ) ),
			'title_size_d'         => max( 12, min( 24, absint( $input['title_size_d'] ?? $defaults['title_size_d'] ) ) ),
			'title_size_m'         => max( 11, min( 20, absint( $input['title_size_m'] ?? $defaults['title_size_m'] ) ) ),
			'price_size_d'         => max( 12, min( 26, absint( $input['price_size_d'] ?? $defaults['price_size_d'] ) ) ),
			'price_size_m'         => max( 11, min( 22, absint( $input['price_size_m'] ?? $defaults['price_size_m'] ) ) ),
			'show_title'           => empty( $input['show_title'] ) ? 0 : 1,
			'show_price'           => empty( $input['show_price'] ) ? 0 : 1,
			'show_sale_badge'      => empty( $input['show_sale_badge'] ) ? 0 : 1,
			'show_category_badge'  => empty( $input['show_category_badge'] ) ? 0 : 1,
			'show_cta'             => empty( $input['show_cta'] ) ? 0 : 1,
			'cta_label'            => wp_html_excerpt( sanitize_text_field( (string) ( $input['cta_label'] ?? $defaults['cta_label'] ) ), 40, '' ),
			'cta_radius'           => max( 8, min( 30, absint( $input['cta_radius'] ?? $defaults['cta_radius'] ) ) ),
			'show_pagination'      => empty( $input['show_pagination'] ) ? 0 : 1,
			'pagination_style'     => in_array( $input['pagination_style'] ?? '', array( 'pills', 'minimal' ), true ) ? $input['pagination_style'] : $defaults['pagination_style'],
			'hover_motion'         => empty( $input['hover_motion'] ) ? 0 : 1,
		);

		return $clean;
	}

	public static function save(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot edit product archives.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		$target = self::sanitize_target( isset( $_POST['target'] ) ? (string) wp_unslash( $_POST['target'] ) : 'global' );
		check_admin_referer( 'delicat_builder_v9_save_archive_' . $target );
		$intent = isset( $_POST['intent'] ) ? sanitize_key( (string) wp_unslash( $_POST['intent'] ) ) : 'draft';
		if ( ! in_array( $intent, array( 'draft', 'publish', 'unpublish' ), true ) ) {
			$intent = 'draft';
		}
		$input = isset( $_POST['config'] ) && is_array( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : array();
		$config = self::sanitize_config( $input );
		$config['enabled'] = 1;
		$config['draft_saved_at'] = time();
		$drafts = self::drafts();
		$drafts[ $target ] = $config;
		self::$drafts_cache = $drafts;
		self::$target_cache = null;
		self::$active_cache = null;
		update_option( self::DRAFT_OPTION, $drafts, false );

		$status = 'draft_saved';
		if ( 'publish' === $intent ) {
			$live = $config;
			$live['enabled'] = 1;
			$live['force_native_template'] = 1;
			$live['published_at'] = time();
			$configs = self::configs();
			$configs[ $target ] = $live;
			update_option( self::OPTION, $configs, false );
			self::$configs_cache = $configs;
			self::$target_cache = null;
			self::$active_cache = null;
			if ( class_exists( 'Delicat_Builder_V9_Woo_UI' ) && is_callable( array( 'Delicat_Builder_V9_Woo_UI', 'settings' ) ) ) {
				$woo = Delicat_Builder_V9_Woo_UI::settings();
				$woo['enabled'] = 1;
				$woo['archive_enabled'] = 1;
				update_option( Delicat_Builder_V9_Woo_UI::OPTION, $woo, false );
			}
			$status = 'published';
		} elseif ( 'unpublish' === $intent ) {
			$configs = self::configs();
			$live = isset( $configs[ $target ] ) && is_array( $configs[ $target ] ) ? $configs[ $target ] : self::defaults();
			$live['enabled'] = 0;
			$live['force_native_template'] = 0;
			$live['unpublished_at'] = time();
			$configs[ $target ] = $live;
			update_option( self::OPTION, $configs, false );
			self::$configs_cache = $configs;
			self::$target_cache = null;
			self::$active_cache = null;
			$status = 'unpublished';
		}
		if ( 'draft' !== $intent && class_exists( 'Delicat_Builder_V9_Cache' ) ) {
			if ( is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) { Delicat_Builder_V9_Cache::bump_version(); }
			if ( is_callable( array( 'Delicat_Builder_V9_Cache', 'purge_product_archives' ) ) ) { Delicat_Builder_V9_Cache::purge_product_archives(); }
		}
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit( 'archive_builder_' . $intent, 'info', array( 'target' => $target ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'delicat-builder-v9-product-archives', 'archive' => $target, 'status' => $status ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot edit product archives.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}

		$target = isset( $_GET['archive'] ) ? self::sanitize_target( (string) wp_unslash( $_GET['archive'] ) ) : '';
		if ( '' === $target ) {
			self::archive_picker();
			return;
		}

		self::archive_editor( $target );
	}

	private static function archive_picker(): void {
		$targets = array(
			array(
				'key'         => 'global',
				'label'       => __( 'All Product Archives', 'delicat-builder-v9' ),
				'description' => __( 'Default design inherited by Shop and product categories unless a specific archive overrides it.', 'delicat-builder-v9' ),
				'icon'        => '◫',
			),
			array(
				'key'         => 'shop',
				'label'       => __( 'Shop / All Products', 'delicat-builder-v9' ),
				'description' => __( 'The main WooCommerce product archive.', 'delicat-builder-v9' ),
				'icon'        => '▦',
			),
		);

		$categories = taxonomy_exists( 'product_cat' )
			? get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'number'     => 100,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			)
			: array();

		if ( is_array( $categories ) ) {
			foreach ( $categories as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$targets[] = array(
					'key'         => 'cat:' . $term->term_id,
					'label'       => $term->name,
					'description' => sprintf( __( 'Product category · %d products', 'delicat-builder-v9' ), absint( $term->count ) ),
					'icon'        => '▤',
				);
			}
		}


		foreach ( array(
			'product_tag' => array( 'prefix' => 'tag', 'label' => __( 'Product tag', 'delicat-builder-v9' ), 'icon' => '⌁' ),
			'product_brand' => array( 'prefix' => 'brand', 'label' => __( 'Product brand', 'delicat-builder-v9' ), 'icon' => '◇' ),
		) as $taxonomy => $meta ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 100, 'orderby' => 'name', 'order' => 'ASC' ) );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$targets[] = array(
					'key'         => $meta['prefix'] . ':' . $term->term_id,
					'label'       => $term->name,
					'description' => sprintf( __( '%1$s · %2$d products', 'delicat-builder-v9' ), $meta['label'], absint( $term->count ) ),
					'icon'        => $meta['icon'],
				);
			}
		}

		?>
		<div class="wrap delicat-page-dashboard delicat-archive-dashboard">
			<section class="delicat-page-dashboard__hero">
				<div class="delicat-page-dashboard__brand">
					<span class="delicat-page-dashboard__logo" aria-hidden="true">A</span>
					<div>
						<span class="delicat-page-dashboard__eyebrow"><?php echo esc_html( 'DELICAT BUILDER · ' . DELICAT_BUILDER_V9_VERSION ); ?></span>
						<h1><?php esc_html_e( 'Product Archive Builder', 'delicat-builder-v9' ); ?></h1>
						<p><?php esc_html_e( 'Build Shop and WooCommerce category archives without turning them into fake WordPress pages. WooCommerce keeps the real product query, pagination, prices, stock and checkout logic.', 'delicat-builder-v9' ); ?></p>
					</div>
				</div>
				<div class="delicat-page-dashboard__hero-actions">
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-editor' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Pages', 'delicat-builder-v9' ); ?></a>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-single-products' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Single Products', 'delicat-builder-v9' ); ?></a>
					<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-product-archives' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Product Archives', 'delicat-builder-v9' ); ?></a>
				</div>
			</section>

			<div class="delicat-archive-elementor-note">
				<strong><?php esc_html_e( 'Migrating from Elementor Theme Builder?', 'delicat-builder-v9' ); ?></strong>
				<span><?php esc_html_e( 'Your Elementor Product Archive is a Theme Builder template, not a WordPress page. Build the replacement here first. When you are satisfied, remove/disable the old Elementor Archive display condition so only one archive template controls the live URL.', 'delicat-builder-v9' ); ?></span>
			</div>

			<div class="delicat-native-woo-lock">
				<strong><?php esc_html_e( 'WooCommerce Engine Lock', 'delicat-builder-v9' ); ?></strong>
				<span><?php esc_html_e( 'Archive Builder styles the native WooCommerce loop. WooCommerce remains responsible for the product query, taxonomy filters, pagination, prices, stock and purchase destinations.', 'delicat-builder-v9' ); ?></span>
			</div>

			<section class="delicat-page-dashboard__workspace">
				<div class="delicat-page-toolbar">
					<div class="delicat-page-search">
						<span aria-hidden="true">⌕</span>
						<input type="search" id="delicat-archive-search" placeholder="<?php esc_attr_e( 'Search product archives…', 'delicat-builder-v9' ); ?>" autocomplete="off">
					</div>
				</div>

				<div class="delicat-archive-picker" id="delicat-archive-picker">
					<?php foreach ( $targets as $target ) :
						$key = self::sanitize_target( $target['key'] );
						$config = self::has_draft( $key ) ? self::get_draft_config( $key ) : self::get_config( $key );
						$enabled = self::is_published( $key );
						$has_draft = self::has_draft( $key );
						$inherited = ! $enabled && 'global' !== $key && self::is_published( 'global' );
						$url = self::target_url( $key );
						$editor_url = add_query_arg(
							array(
								'page'    => 'delicat-builder-v9-editor',
								'view'    => 'archives',
								'archive' => $key,
							),
							admin_url( 'admin.php' )
						);
						?>
						<article class="delicat-archive-card<?php echo $enabled ? ' is-enabled' : ''; ?>" data-delicat-archive-card data-search="<?php echo esc_attr( strtolower( $target['label'] . ' ' . $target['description'] ) ); ?>">
							<div class="delicat-archive-card__icon" aria-hidden="true"><?php echo esc_html( $target['icon'] ); ?></div>
							<div class="delicat-archive-card__body">
								<div class="delicat-archive-card__status">
									<span class="<?php echo ( $enabled || $inherited || $has_draft ) ? 'is-on' : 'is-off'; ?>"><i></i><?php
										echo $enabled
											? esc_html__( 'V9 default', 'delicat-builder-v9' )
											: ( $has_draft ? esc_html__( 'Draft ready to test', 'delicat-builder-v9' ) : ( $inherited ? esc_html__( 'Inherits V9 global', 'delicat-builder-v9' ) : esc_html__( 'Elementor / theme live', 'delicat-builder-v9' ) ) );
									?></span>
								</div>
								<h2><?php echo esc_html( $target['label'] ); ?></h2>
								<p><?php echo esc_html( $target['description'] ); ?></p>
								<div class="delicat-archive-card__meta">
									<span><?php echo esc_html( sprintf( '%d / %d / %d cols', absint( $config['grid_desktop'] ), absint( $config['grid_tablet'] ), absint( $config['grid_mobile'] ) ) ); ?></span>
									<span><?php echo esc_html( absint( $config['products_per_page'] ) . ' products' ); ?></span>
									<span><?php echo esc_html( ucfirst( str_replace( '_', ' ', $config['card_style'] ) ) ); ?></span>
								</div>
							</div>
							<div class="delicat-archive-card__actions">
								<a class="button button-primary" href="<?php echo esc_url( $editor_url ); ?>"><?php esc_html_e( 'Open Archive Builder', 'delicat-builder-v9' ); ?></a>
								<?php if ( $url ) : ?><a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview URL', 'delicat-builder-v9' ); ?></a><?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
		</div>
		<?php
	}

	private static function archive_editor( string $target ): void {
		$config = self::has_draft( $target ) ? self::get_draft_config( $target ) : self::get_config( $target );
		$label = self::target_label( $target );
		$live_url = self::target_url( $target );
		$preview_url = self::preview_url( $target );
		$published = self::is_published( $target );
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		?>
		<div class="wrap delicat-archive-editor">
			<?php if ( 'draft_saved' === $status ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Draft saved. Your Elementor/archive template is still live for shoppers.', 'delicat-builder-v9' ); ?></p></div>
			<?php elseif ( 'published' === $status ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'V9 is now the default WooCommerce archive for this target. Elementor remains stored for instant rollback.', 'delicat-builder-v9' ); ?></p></div>
			<?php elseif ( 'unpublished' === $status ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'V9 default disabled. The previous Elementor/theme archive is live again.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<div class="delicat-editor-topbar">
				<div class="delicat-editor-topbar__identity">
					<a class="delicat-editor-back" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-product-archives' ), admin_url( 'admin.php' ) ) ); ?>">←</a>
					<div>
						<span class="delicat-editor-kicker"><?php echo esc_html( 'PRODUCT ARCHIVE · ' . DELICAT_BUILDER_V9_VERSION ); ?></span>
						<h1><?php echo esc_html( $label ); ?></h1>
					</div>
				</div>
				<div class="delicat-editor-topbar__actions">
					<?php if ( $preview_url ) : ?><a class="button button-primary" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview V9 Draft', 'delicat-builder-v9' ); ?></a><?php endif; ?>
					<?php if ( $live_url ) : ?><a class="button" href="<?php echo esc_url( $live_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open Current Live Archive', 'delicat-builder-v9' ); ?></a><?php endif; ?>
				</div>
			</div>

			<div class="delicat-template-workflow <?php echo $published ? 'is-live' : 'is-draft'; ?>">
				<div><strong><?php echo $published ? esc_html__( 'V9 is currently DEFAULT', 'delicat-builder-v9' ) : esc_html__( 'Elementor/theme archive is currently LIVE', 'delicat-builder-v9' ); ?></strong><span><?php esc_html_e( 'V9 keeps the Elementor display condition untouched. Private preview bypasses it only for you; Publish as Default bypasses it for this Woo archive, and Rollback restores Elementor instantly.', 'delicat-builder-v9' ); ?></span></div>
				<div class="delicat-template-workflow__steps"><span>1. <?php esc_html_e( 'Build', 'delicat-builder-v9' ); ?></span><span>2. <?php esc_html_e( 'Preview privately', 'delicat-builder-v9' ); ?></span><span>3. <?php esc_html_e( 'Publish as default', 'delicat-builder-v9' ); ?></span><span>4. <?php esc_html_e( 'Rollback anytime', 'delicat-builder-v9' ); ?></span></div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="delicat-archive-editor__form" id="delicat-archive-form">
				<input type="hidden" name="action" value="delicat_builder_v9_save_archive">
				<input type="hidden" name="target" value="<?php echo esc_attr( $target ); ?>">
				<?php wp_nonce_field( 'delicat_builder_v9_save_archive_' . $target ); ?>

				<aside class="delicat-archive-editor__controls">
					<section class="delicat-archive-control-card">
						<h2><?php esc_html_e( 'Safe testing mode', 'delicat-builder-v9' ); ?></h2>
						<input type="hidden" name="config[enabled]" value="1">
						<p class="description"><?php esc_html_e( 'Save Draft and private Preview do not change the archive seen by shoppers. Publish as Default is the only action that switches the live renderer.', 'delicat-builder-v9' ); ?></p>
					</section>

					<section class="delicat-archive-control-card">
						<h2><?php esc_html_e( 'Archive header', 'delicat-builder-v9' ); ?></h2>
						<?php self::select_field( 'config[title_mode]', __( 'Title source', 'delicat-builder-v9' ), $config['title_mode'], array(
							'dynamic' => __( 'Dynamic archive title', 'delicat-builder-v9' ),
							'custom'  => __( 'Custom title', 'delicat-builder-v9' ),
							'hidden'  => __( 'Hide title', 'delicat-builder-v9' ),
						) ); ?>
						<?php self::text_field( 'config[custom_title]', __( 'Custom title', 'delicat-builder-v9' ), $config['custom_title'] ); ?>
						<?php self::text_field( 'config[subtitle]', __( 'Subtitle', 'delicat-builder-v9' ), $config['subtitle'] ); ?>
						<label class="delicat-switch-row delicat-switch-row--compact">
							<input type="checkbox" name="config[show_header]" value="1" <?php checked( ! empty( $config['show_header'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Show archive header', 'delicat-builder-v9' ); ?></strong></span>
						</label>
						<label class="delicat-switch-row delicat-switch-row--compact">
							<input type="checkbox" name="config[show_description]" value="1" <?php checked( ! empty( $config['show_description'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Show Woo category description', 'delicat-builder-v9' ); ?></strong></span>
						</label>
						<div class="delicat-archive-field-grid">
							<?php self::text_field( 'config[header_bg]', __( 'Background', 'delicat-builder-v9' ), $config['header_bg'], 'text', '#07091d' ); ?>
							<?php self::text_field( 'config[header_border]', __( 'Border', 'delicat-builder-v9' ), $config['header_border'], 'text', '#20254b' ); ?>
							<?php self::text_field( 'config[title_color]', __( 'Title color', 'delicat-builder-v9' ), $config['title_color'], 'text', '#ffffff' ); ?>
							<?php self::text_field( 'config[subtitle_color]', __( 'Subtitle color', 'delicat-builder-v9' ), $config['subtitle_color'], 'text', '#9da4b8' ); ?>
							<?php self::text_field( 'config[accent_color]', __( 'Accent', 'delicat-builder-v9' ), $config['accent_color'], 'text', '#6d5dfc' ); ?>
							<?php self::number_field( 'config[header_radius]', __( 'Radius', 'delicat-builder-v9' ), $config['header_radius'], 0, 48 ); ?>
							<?php self::number_field( 'config[header_padding_d]', __( 'Desktop padding', 'delicat-builder-v9' ), $config['header_padding_d'], 12, 80 ); ?>
							<?php self::number_field( 'config[header_padding_m]', __( 'Mobile padding', 'delicat-builder-v9' ), $config['header_padding_m'], 10, 60 ); ?>
							<?php self::number_field( 'config[header_margin_bottom]', __( 'Bottom gap', 'delicat-builder-v9' ), $config['header_margin_bottom'], 0, 80 ); ?>
						</div>
					</section>

					<section class="delicat-archive-control-card">
						<h2><?php esc_html_e( 'Product grid', 'delicat-builder-v9' ); ?></h2>
						<div class="delicat-archive-field-grid">
							<?php self::number_field( 'config[grid_desktop]', __( 'Desktop columns', 'delicat-builder-v9' ), $config['grid_desktop'], 2, 6 ); ?>
							<?php self::number_field( 'config[grid_tablet]', __( 'Tablet columns', 'delicat-builder-v9' ), $config['grid_tablet'], 2, 4 ); ?>
							<?php self::number_field( 'config[grid_mobile]', __( 'Mobile columns', 'delicat-builder-v9' ), $config['grid_mobile'], 1, 2 ); ?>
							<?php self::number_field( 'config[products_per_page]', __( 'Products/page', 'delicat-builder-v9' ), $config['products_per_page'], 8, 24 ); ?>
							<?php self::select_field( 'config[max_width]', __( 'Max width', 'delicat-builder-v9' ), (string) $config['max_width'], array(
								'1200' => '1200px',
								'1320' => '1320px',
								'1440' => '1440px',
							) ); ?>
						</div>
						<?php self::select_field( 'config[card_style]', __( 'Card style', 'delicat-builder-v9' ), $config['card_style'], array(
							'premium' => __( 'Premium', 'delicat-builder-v9' ),
							'glass'   => __( 'Glass', 'delicat-builder-v9' ),
							'minimal' => __( 'Minimal', 'delicat-builder-v9' ),
							'gaming'  => __( 'Gaming', 'delicat-builder-v9' ),
						) ); ?>
						<?php self::select_field( 'config[image_ratio]', __( 'Image ratio', 'delicat-builder-v9' ), $config['image_ratio'], array(
							'square'    => __( 'Square', 'delicat-builder-v9' ),
							'portrait'  => __( 'Portrait', 'delicat-builder-v9' ),
							'landscape' => __( 'Landscape', 'delicat-builder-v9' ),
						) ); ?>

						<?php self::select_field( 'config[image_fit]', __( 'Image fit', 'delicat-builder-v9' ), $config['image_fit'], array( 'cover' => __( 'Cover', 'delicat-builder-v9' ), 'contain' => __( 'Contain', 'delicat-builder-v9' ) ) ); ?>
						<div class="delicat-archive-field-grid">
							<?php self::number_field( 'config[grid_gap_d]', __( 'Grid gap desktop', 'delicat-builder-v9' ), $config['grid_gap_d'], 4, 48 ); ?>
							<?php self::number_field( 'config[grid_gap_m]', __( 'Grid gap mobile', 'delicat-builder-v9' ), $config['grid_gap_m'], 4, 28 ); ?>
							<?php self::number_field( 'config[card_radius]', __( 'Card radius', 'delicat-builder-v9' ), $config['card_radius'], 0, 36 ); ?>
							<?php self::number_field( 'config[cta_radius]', __( 'Button radius', 'delicat-builder-v9' ), $config['cta_radius'], 8, 30 ); ?>
						</div>
						<?php self::text_field( 'config[card_bg]', __( 'Card background', 'delicat-builder-v9' ), $config['card_bg'], 'text', __( 'Auto / theme surface', 'delicat-builder-v9' ) ); ?>
						<?php self::text_field( 'config[card_border]', __( 'Card border', 'delicat-builder-v9' ), $config['card_border'], 'text', __( 'Auto / theme border', 'delicat-builder-v9' ) ); ?>
						<?php self::text_field( 'config[image_bg]', __( 'Image background', 'delicat-builder-v9' ), $config['image_bg'], 'text', __( 'Auto', 'delicat-builder-v9' ) ); ?>
						<?php self::select_field( 'config[card_shadow]', __( 'Card shadow', 'delicat-builder-v9' ), $config['card_shadow'], array( 'none' => __( 'None', 'delicat-builder-v9' ), 'soft' => __( 'Soft', 'delicat-builder-v9' ), 'elevated' => __( 'Elevated', 'delicat-builder-v9' ) ) ); ?>

					</section>

					<section class="delicat-archive-control-card">
						<h2><?php esc_html_e( 'Woo controls', 'delicat-builder-v9' ); ?></h2>
						<?php self::checkbox_field( 'config[show_ordering]', __( 'Show sorting', 'delicat-builder-v9' ), $config['show_ordering'] ); ?>
						<?php self::checkbox_field( 'config[show_result_count]', __( 'Show result count', 'delicat-builder-v9' ), $config['show_result_count'] ); ?>
						<?php self::checkbox_field( 'config[show_rating]', __( 'Show ratings', 'delicat-builder-v9' ), $config['show_rating'] ); ?>
						<?php self::checkbox_field( 'config[show_stock_badge]', __( 'Show stock badge', 'delicat-builder-v9' ), $config['show_stock_badge'] ); ?>

						<?php self::checkbox_field( 'config[show_title]', __( 'Show product title', 'delicat-builder-v9' ), $config['show_title'] ); ?>
						<?php self::checkbox_field( 'config[show_price]', __( 'Show price', 'delicat-builder-v9' ), $config['show_price'] ); ?>
						<?php self::checkbox_field( 'config[show_sale_badge]', __( 'Show Woo sale badge', 'delicat-builder-v9' ), $config['show_sale_badge'] ); ?>
						<?php self::checkbox_field( 'config[show_category_badge]', __( 'Show category badge', 'delicat-builder-v9' ), $config['show_category_badge'] ); ?>
						<?php self::checkbox_field( 'config[show_cta]', __( 'Show buy button', 'delicat-builder-v9' ), $config['show_cta'] ); ?>
						<?php self::checkbox_field( 'config[show_pagination]', __( 'Show WooCommerce pagination', 'delicat-builder-v9' ), $config['show_pagination'] ); ?>
						<?php self::checkbox_field( 'config[hover_motion]', __( 'Desktop hover motion', 'delicat-builder-v9' ), $config['hover_motion'] ); ?>

						<?php self::checkbox_field( 'config[compact_mobile]', __( 'Compact mobile cards', 'delicat-builder-v9' ), $config['compact_mobile'] ); ?>
					</section>

					<section class="delicat-archive-control-card">
						<h2><?php esc_html_e( 'Card text & pagination', 'delicat-builder-v9' ); ?></h2>
						<div class="delicat-archive-field-grid">
							<?php self::number_field( 'config[title_lines]', __( 'Title lines', 'delicat-builder-v9' ), $config['title_lines'], 1, 3 ); ?>
							<?php self::number_field( 'config[title_size_d]', __( 'Title size desktop', 'delicat-builder-v9' ), $config['title_size_d'], 12, 24 ); ?>
							<?php self::number_field( 'config[title_size_m]', __( 'Title size mobile', 'delicat-builder-v9' ), $config['title_size_m'], 11, 20 ); ?>
							<?php self::number_field( 'config[price_size_d]', __( 'Price size desktop', 'delicat-builder-v9' ), $config['price_size_d'], 12, 26 ); ?>
							<?php self::number_field( 'config[price_size_m]', __( 'Price size mobile', 'delicat-builder-v9' ), $config['price_size_m'], 11, 22 ); ?>
						</div>
						<?php self::text_field( 'config[cta_label]', __( 'Buy button label', 'delicat-builder-v9' ), $config['cta_label'] ); ?>
						<?php self::select_field( 'config[pagination_style]', __( 'Pagination style', 'delicat-builder-v9' ), $config['pagination_style'], array( 'pills' => __( 'Pills', 'delicat-builder-v9' ), 'minimal' => __( 'Minimal', 'delicat-builder-v9' ) ) ); ?>
					</section>

					<section class="delicat-archive-control-card">
						<h2><?php esc_html_e( 'Speed profile', 'delicat-builder-v9' ); ?></h2>
						<?php self::checkbox_field( 'config[fast_mode]', __( 'Ultra Fast archive mode', 'delicat-builder-v9' ), $config['fast_mode'] ); ?>
						<?php self::checkbox_field( 'config[touch_prefetch]', __( 'Fast tap warm-up', 'delicat-builder-v9' ), $config['touch_prefetch'] ); ?>
						<?php self::select_field( 'config[cta_mode]', __( 'Buy button', 'delicat-builder-v9' ), $config['cta_mode'], array(
							'product' => __( 'Open product page — recommended', 'delicat-builder-v9' ),
							'woo'     => __( 'WooCommerce default add-to-cart', 'delicat-builder-v9' ),
						) ); ?>
					</section>

					<div class="delicat-archive-editor__savebar delicat-template-publishbar">
						<button type="submit" name="intent" value="draft" class="button button-hero"><?php esc_html_e( 'Save Draft', 'delicat-builder-v9' ); ?></button>
						<?php if ( $preview_url ) : ?><a class="button button-hero" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview Draft', 'delicat-builder-v9' ); ?></a><?php endif; ?>
						<button type="submit" name="intent" value="publish" class="button button-primary button-hero"><?php esc_html_e( 'Publish as Default', 'delicat-builder-v9' ); ?></button>
						<?php if ( $published ) : ?><button type="submit" name="intent" value="unpublish" class="button button-hero delicat-danger-soft"><?php esc_html_e( 'Rollback to Elementor', 'delicat-builder-v9' ); ?></button><?php endif; ?>
					</div>
				</aside>

				<main class="delicat-archive-editor__preview">
					<div class="delicat-archive-preview__label">
						<strong><?php esc_html_e( 'Live design preview', 'delicat-builder-v9' ); ?></strong>
						<span><?php esc_html_e( 'Visual approximation. Live Woo products remain dynamic.', 'delicat-builder-v9' ); ?></span>
					</div>
					<div class="delicat-archive-preview" id="delicat-archive-preview">
						<section class="delicat-archive-preview__header">
							<span></span>
							<h1 id="delicat-archive-preview-title"><?php echo esc_html( 'custom' === $config['title_mode'] && $config['custom_title'] ? $config['custom_title'] : $label ); ?></h1>
							<p id="delicat-archive-preview-subtitle"><?php echo esc_html( $config['subtitle'] ?: __( 'Discover our products and instant services.', 'delicat-builder-v9' ) ); ?></p>
						</section>
						<div class="delicat-archive-preview__tools"><span><?php esc_html_e( '12 products', 'delicat-builder-v9' ); ?></span><button type="button"><?php esc_html_e( 'Sort by', 'delicat-builder-v9' ); ?>⌄</button></div>
						<div class="delicat-archive-preview__grid">
							<?php for ( $i = 0; $i < 8; $i++ ) : ?>
								<article>
									<div class="delicat-archive-preview__image"><span><?php echo esc_html( (string) ( $i + 1 ) ); ?></span></div>
									<h3><?php echo esc_html( array( 'Free Fire', 'PUBG Mobile', 'Blood Strike', 'Roblox', 'Apple Gift Card', 'Netflix', 'PayPal', 'Crunchyroll' )[ $i ] ); ?></h3>
									<p>À partir de <strong>G<?php echo esc_html( (string) ( 80 + $i * 45 ) ); ?></strong></p>
									<button type="button"><?php esc_html_e( 'Acheter', 'delicat-builder-v9' ); ?></button>
								</article>
							<?php endfor; ?>
						</div>
					</div>
				</main>
			</form>
		</div>
		<?php
	}

	private static function text_field( string $name, string $label, $value, string $type = 'text', string $placeholder = '' ): void {
		printf(
			'<label class="delicat-archive-field"><span>%1$s</span><input type="%2$s" name="%3$s" value="%4$s" placeholder="%5$s"></label>',
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			esc_attr( $placeholder )
		);
	}

	private static function number_field( string $name, string $label, $value, int $min, int $max ): void {
		printf(
			'<label class="delicat-archive-field"><span>%1$s</span><input type="number" name="%2$s" value="%3$d" min="%4$d" max="%5$d"></label>',
			esc_html( $label ),
			esc_attr( $name ),
			absint( $value ),
			$min,
			$max
		);
	}

	private static function select_field( string $name, string $label, $value, array $options ): void {
		echo '<label class="delicat-archive-field"><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $option_label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $value, $key, false ) . '>' . esc_html( $option_label ) . '</option>';
		}
		echo '</select></label>';
	}

	private static function checkbox_field( string $name, string $label, $value ): void {
		echo '<label class="delicat-switch-row delicat-switch-row--compact"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . '><span><strong>' . esc_html( $label ) . '</strong></span></label>';
	}
}
