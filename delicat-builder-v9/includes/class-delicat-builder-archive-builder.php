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
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'render_category_chips' ), 3 );
		add_action( 'woocommerce_no_products_found', array( __CLASS__, 'render_empty_header' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install_native_preset' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'trim_native_archive_assets' ), PHP_INT_MAX );
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
			'header_radius'             => 16,
			'header_padding_d'          => 18,
			'header_padding_m'          => 14,
			'header_margin_bottom'      => 14,
			'max_width'                 => 1320,
			'card_style'                => 'minimal',
			'image_ratio'               => 'landscape',
			'grid_desktop'              => 4,
			'grid_tablet'               => 3,
			'grid_mobile'               => 2,
			'products_per_page'         => 8,
			'show_stock_badge'          => 1,
			'show_ordering'             => 1,
			'show_result_count'         => 0,
			'show_rating'               => 0,
			'compact_mobile'            => 1,
			'fast_mode'                 => 1,
			'touch_prefetch'            => 0,
			'cta_mode'                  => 'product',
			'grid_gap_d'                => 10,
			'grid_gap_m'                => 7,
			'card_radius'               => 14,
			'card_bg'                   => '',
			'card_border'               => '',
			'card_shadow'               => 'none',
			'image_fit'                 => 'cover',
			'image_bg'                  => '',
			'title_lines'               => 2,
			'title_size_d'              => 14,
			'title_size_m'              => 12,
			'price_size_d'              => 13,
			'price_size_m'              => 12,
			'show_title'                => 1,
			'show_price'                => 1,
			'show_sale_badge'           => 1,
			'show_category_badge'       => 0,
			'show_cta'                  => 1,
			'cta_label'                 => __( 'Acheter', 'delicat-builder-v9' ),
			'cta_radius'                => 10,
			'show_pagination'           => 1,
			'pagination_style'          => 'pills',
			'hover_motion'              => 0,
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
			'archive_products_per_page'     => absint( $config['products_per_page'] ?? 8 ),
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

	public static function render_empty_header(): void {
		if ( empty( self::active_config() ) ) { return; }
		self::render_header();
		self::render_category_chips();
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

	}

	/** A single cached term query; no guessed slugs or dead category links. */
	public static function render_category_chips(): void {
		if ( empty( self::active_config() ) ) { return; }
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => 0, 'number' => 8, 'orderby' => 'name' ) );
		if ( is_wp_error( $terms ) ) { return; }
		$active = is_product_category() ? absint( get_queried_object_id() ) : 0;
		$ancestors = $active ? get_ancestors( $active, 'product_cat', 'taxonomy' ) : array();
		echo '<nav class="delicat-native-category-chips" aria-label="Catégories de produits">';
		echo '<a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '"' . ( is_shop() ? ' class="is-active" aria-current="page"' : '' ) . '>' . self::category_icon_svg( 'bolt' ) . '<span>Tout voir</span></a>';
		foreach ( $terms as $term ) {
			$url = get_term_link( $term );
			if ( is_wp_error( $url ) ) { continue; }
			$selected = $active === (int) $term->term_id;
			$branch = in_array( (int) $term->term_id, $ancestors, true );
			echo '<a href="' . esc_url( $url ) . '"' . ( $selected || $branch ? ' class="is-active"' : '' ) . ( $selected ? ' aria-current="page"' : '' ) . '>' . self::category_icon_svg( 'gamepad' ) . '<span>' . esc_html( $term->name ) . '</span></a>';
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
			'arrow'   => '<path d="M5 12h13M13 7l5 5-5 5"/>',
		);
		$path = $paths[ $token ] ?? $paths['bolt'];
		return '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" class="delicat-native-category-chips__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/**
	 * pro.49 archive v4-ultra card renderer.
	 *
	 * The archive still uses WooCommerce's main product query, prices, stock and
	 * permalinks, but it no longer runs content-product.php and every third-party
	 * loop hook once per product. That removes a large amount of PHP/HTML work on
	 * catalog pages while keeping WooCommerce authoritative.
	 */
	public static function render_product_card( $product_or_id, int $index = 0 ): void {
		$product = $product_or_id instanceof WC_Product
			? $product_or_id
			: ( function_exists( 'wc_get_product' ) ? wc_get_product( absint( $product_or_id ) ) : false );
		if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
			return;
		}

		$config = self::active_config();
		$id     = $product->get_id();
		$url    = get_permalink( $id );
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}

		$title       = $product->get_name();
		$image_id    = $product->get_image_id();
		$show_title  = ! array_key_exists( 'show_title', $config ) || ! empty( $config['show_title'] );
		$show_price  = ! array_key_exists( 'show_price', $config ) || ! empty( $config['show_price'] );
		$show_stock  = ! empty( $config['show_stock_badge'] );
		$show_sale   = ! array_key_exists( 'show_sale_badge', $config ) || ! empty( $config['show_sale_badge'] );
		$show_cat    = ! empty( $config['show_category_badge'] );
		$show_cta    = ! array_key_exists( 'show_cta', $config ) || ! empty( $config['show_cta'] );
		$cta_label   = trim( sanitize_text_field( (string) ( $config['cta_label'] ?? __( 'Choisir', 'delicat-builder-v9' ) ) ) );
		$cta_label   = '' !== $cta_label ? $cta_label : __( 'Choisir', 'delicat-builder-v9' );
		$in_stock    = $product->is_in_stock();
		$aria_label  = sprintf( __( 'Ouvrir %s', 'delicat-builder-v9' ), $title );
		$nav_attrs   = ! empty( $config['touch_prefetch'] )
			? ' data-delicat-prefetch data-delicat-product-link data-delicat-instant-product'
			: '';

		/* pro.49: one navigation target per product instead of three repeated
		 * anchors/listeners. The whole card is a link; Woo remains authoritative
		 * for product visibility, price, stock, URL and the archive query. */
		echo '<article class="delicat-product-card" data-product-id="' . esc_attr( (string) $id ) . '">';
		echo '<a class="delicat-product-card__link" href="' . esc_url( $url ) . '"' . $nav_attrs . ' aria-label="' . esc_attr( $aria_label ) . '">';
		echo '<div class="delicat-product-card__media">';

		if ( $image_id ) {
			$attrs = array(
				'class'    => 'delicat-product-card__image',
				'alt'      => (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ?: $title,
				'decoding' => 'async',
				'sizes'    => self::product_card_image_sizes( $config ),
			);
			if ( 0 === $index ) {
				$attrs['loading']       = 'eager';
				$attrs['fetchpriority'] = 'high';
			} else {
				$attrs['loading']       = 'lazy';
				$attrs['fetchpriority'] = 'low';
			}
			echo wp_get_attachment_image( $image_id, 'woocommerce_thumbnail', false, $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo wc_placeholder_img( 'woocommerce_thumbnail', array( 'class' => 'delicat-product-card__image', 'loading' => 0 === $index ? 'eager' : 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $show_sale && $product->is_on_sale() ) {
			echo '<span class="delicat-product-card__sale">' . esc_html__( 'Promo', 'delicat-builder-v9' ) . '</span>';
		}
		if ( $show_cat ) {
			$terms = get_the_terms( $id, 'product_cat' );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$term = reset( $terms );
				if ( $term instanceof WP_Term ) {
					echo '<span class="delicat-product-card__category">' . esc_html( $term->name ) . '</span>';
				}
			}
		}
		echo '</div>';

		echo '<div class="delicat-product-card__body">';
		if ( $show_title ) {
			echo '<h2 class="delicat-product-card__title">' . esc_html( $title ) . '</h2>';
		}
		if ( $show_price ) {
			$price_html = self::compact_price_html( $product );
			if ( '' !== $price_html ) {
				echo '<span class="delicat-product-card__price">' . wp_kses_post( $price_html ) . '</span>';
			}
		}
		if ( $show_stock ) {
			$status = $in_stock ? __( 'Disponible', 'delicat-builder-v9' ) : __( 'Indisponible', 'delicat-builder-v9' );
			echo '<span class="delicat-product-card__stock ' . ( $in_stock ? 'is-in' : 'is-out' ) . '"><span aria-hidden="true"></span>' . esc_html( $status ) . '</span>';
		}
		if ( $show_cta ) {
			echo '<span class="delicat-product-card__cta" aria-hidden="true"><span>' . esc_html( $cta_label ) . '</span>';
			echo self::category_icon_svg( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</span>';
		}
		echo '</div></a></article>';
	}

	/**
	 * Variable archives only need the already-synchronised minimum product price.
	 * Avoid generating WooCommerce's complete variation-price range for every
	 * card on a slow catalog request; the product page still shows every option.
	 */
	private static function compact_price_html( WC_Product $product ): string {
		if ( $product->is_type( 'variable' ) ) {
			$raw = (string) $product->get_price();
			if ( '' !== $raw && function_exists( 'wc_price' ) ) {
				$price = (float) $raw;
				if ( function_exists( 'wc_get_price_to_display' ) ) {
					$price = (float) wc_get_price_to_display( $product, array( 'price' => $price ) );
				}
				return '<span class="delicat-product-card__price-prefix">' . esc_html__( 'À partir de', 'delicat-builder-v9' ) . '</span> ' . wc_price( $price );
			}
		}
		return (string) $product->get_price_html();
	}

	private static function product_card_image_sizes( array $config ): string {
		$desktop = self::safe_grid_value( $config['grid_desktop'] ?? 4, 2, 6 );
		$tablet  = self::safe_grid_value( $config['grid_tablet'] ?? 3, 2, 4 );
		$mobile  = self::safe_grid_value( $config['grid_mobile'] ?? 2, 1, 2 );
		$slot = static function ( int $columns, int $gutter ): int {
			return max( 8, (int) floor( 100 / max( 1, $columns ) ) - $gutter );
		};
		return sprintf(
			'(max-width:640px) %1$dvw, (max-width:1024px) %2$dvw, %3$dvw',
			$slot( $mobile, 4 ),
			$slot( $tablet, 2 ),
			$slot( $desktop, 2 )
		);
	}

	/**
	 * Native archive does not render Elementor or Woo's classic catalog skin.
	 * Drop those bytes at the final enqueue priority. Core commerce/session JS is
	 * retained unless it is provably unnecessary for this route.
	 */
	public static function trim_native_archive_assets(): void {
		if ( is_admin() || wp_doing_ajax() || empty( self::active_config() ) ) {
			return;
		}

		if ( (bool) apply_filters( 'delicat_builder_v9_archive_trim_woo_styles', true ) ) {
			foreach ( array( 'woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen', 'wc-blocks-style', 'wc-blocks-packages-style', 'wc-blocks-components', 'wc-blocks-vendors-style', 'wc-blocks-cart-style', 'wc-blocks-checkout-style' ) as $handle ) {
				wp_dequeue_style( $handle );
			}
		}

		/* No Elementor document is rendered by templates/native-archive.php. */
		if ( (bool) apply_filters( 'delicat_builder_v9_archive_trim_elementor_assets', true ) ) {
			$styles = wp_styles();
			if ( $styles ) {
				foreach ( (array) $styles->queue as $handle ) {
					$registered = $styles->registered[ $handle ] ?? null;
					$src = is_object( $registered ) ? (string) $registered->src : '';
					if ( 0 === strpos( (string) $handle, 'elementor-' ) || false !== strpos( $src, '/elementor/' ) || false !== strpos( $src, '/elementor-pro/' ) ) {
						wp_dequeue_style( $handle );
					}
				}
			}
			$scripts = wp_scripts();
			if ( $scripts ) {
				foreach ( (array) $scripts->queue as $handle ) {
					$registered = $scripts->registered[ $handle ] ?? null;
					$src = is_object( $registered ) ? (string) $registered->src : '';
					if ( 0 === strpos( (string) $handle, 'elementor-' ) || false !== strpos( $src, '/elementor/' ) || false !== strpos( $src, '/elementor-pro/' ) ) {
						wp_dequeue_script( $handle );
					}
				}
			}
		}

		/* Direct cards always navigate to the product; archive AJAX add-to-cart is dead code. */
		wp_dequeue_script( 'wc-add-to-cart' );
	}

	/** One-time native storefront preset. Existing per-category titles remain intact. */
	public static function maybe_install_native_preset(): void {
		$cli = defined( 'WP_CLI' ) && WP_CLI;
		if ( ! $cli && ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'delicat_builder_v9_native_archive_preset', '' ) === 'archive-v4-ultra' ) {
			return;
		}
		$preset = array(
			'enabled' => 1, 'force_native_template' => 1, 'show_header' => 1,
			'header_bg' => '#fbf7ff', 'header_border' => '#eee7f8', 'title_color' => '#17152a',
			'subtitle_color' => '#75718a', 'accent_color' => '#705cff', 'header_radius' => 16,
			'products_per_page' => 8, 'show_result_count' => 0, 'show_ordering' => 1,
			'card_style' => 'minimal', 'image_ratio' => 'landscape', 'grid_desktop' => 4,
			'grid_tablet' => 3, 'grid_mobile' => 2, 'compact_mobile' => 1, 'fast_mode' => 1,
			'touch_prefetch' => 0, 'cta_mode' => 'product', 'cta_label' => __( 'Choisir', 'delicat-builder-v9' ),
			'show_stock_badge' => 1, 'show_category_badge' => 0, 'show_pagination' => 1,
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
		foreach ( $configs as &$config ) {
			if ( ! is_array( $config ) ) { continue; }
			$config = array_merge( $config, array( 'products_per_page' => 8, 'fast_mode' => 1, 'grid_mobile' => 2, 'grid_gap_d' => 10, 'grid_gap_m' => 7, 'card_radius' => 13, 'cta_radius' => 30, 'header_radius' => 14, 'header_padding_d' => 14, 'header_padding_m' => 10, 'header_margin_bottom' => 10, 'card_style' => 'minimal', 'image_ratio' => 'landscape', 'title_size_d' => 13, 'title_size_m' => 12, 'price_size_d' => 13, 'price_size_m' => 12, 'card_shadow' => 'none', 'hover_motion' => 0, 'show_result_count' => 0, 'show_category_badge' => 0, 'touch_prefetch' => 0 ) );
		}
		unset( $config );
		update_option( self::OPTION, $configs, false );
		update_option( 'delicat_builder_v9_native_archive_preset', 'archive-v4-ultra', false );
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
			'products_per_page'    => min( 12, max( 8, absint( $input['products_per_page'] ?? $defaults['products_per_page'] ) ) ),
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
		$targets = array( 'global' => 'Toutes les archives', 'shop' => 'Boutique' );
		foreach ( array( 'product_cat' => 'cat', 'product_tag' => 'tag', 'product_brand' => 'brand' ) as $taxonomy => $prefix ) {
			if ( ! taxonomy_exists( $taxonomy ) ) { continue; }
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 200 ) );
			if ( is_wp_error( $terms ) ) { continue; }
			foreach ( $terms as $term ) { $targets[ $prefix . ':' . $term->term_id ] = $term->name; }
		}
		echo '<div class="wrap"><h1>Catalogue · Archive Builder</h1><p>Personnalisez le catalogue global ou une catégorie. Les produits et les prix restent gérés par WooCommerce.</p><label for="delicat-archive-search">Rechercher une archive</label><p><input type="search" id="delicat-archive-search" placeholder="Nom de catégorie…"></p><div id="delicat-archive-picker" class="dca-editor-grid">';
		foreach ( $targets as $target => $label ) {
			$url = add_query_arg( array( 'page' => 'delicat-builder-v9-product-archives', 'archive' => $target ), admin_url( 'admin.php' ) );
			echo '<section data-delicat-archive-card data-search="' . esc_attr( $label ) . '"><h2>' . esc_html( $label ) . '</h2><p>' . ( self::is_published( $target ) ? 'Configuration publiée' : 'Configuration héritée' ) . '</p><a class="button" href="' . esc_url( $url ) . '">Personnaliser</a></section>';
		}
		echo '</div></div>';
	}

	private static function archive_editor( string $target ): void {
		$config = self::has_draft( $target ) ? self::get_draft_config( $target ) : self::get_config( $target );
		$checks = array( 'show_header' => 'Afficher le titre', 'show_description' => 'Description de catégorie', 'show_ordering' => 'Tri des produits', 'show_result_count' => 'Nombre de résultats', 'show_stock_badge' => 'Disponibilité', 'show_title' => 'Nom du produit', 'show_price' => 'Prix', 'show_sale_badge' => 'Promotion', 'show_cta' => 'Bouton du produit', 'show_pagination' => 'Pagination' );
		$fields = array( 'custom_title' => 'Titre personnalisé', 'subtitle' => 'Sous-titre', 'accent_color' => 'Couleur principale', 'cta_label' => 'Texte du bouton' );
		$numbers = array( 'products_per_page' => array( 'Produits par page', 8, 12 ), 'grid_desktop' => array( 'Colonnes ordinateur', 2, 6 ), 'grid_tablet' => array( 'Colonnes tablette', 2, 4 ), 'grid_mobile' => array( 'Colonnes téléphone', 1, 2 ), 'grid_gap_m' => array( 'Espacement mobile', 4, 28 ), 'title_size_m' => array( 'Taille du titre mobile', 11, 20 ), 'price_size_m' => array( 'Taille du prix mobile', 11, 22 ) );
		echo '<div class="wrap"><h1>Catalogue · ' . esc_html( self::target_label( $target ) ) . '</h1><p>Catalogue léger, rendu par WooCommerce. Enregistrez un brouillon puis ouvrez l’aperçu réel.</p>';
		if ( isset( $_GET['status'] ) ) { echo '<div class="notice notice-info"><p>Configuration enregistrée. Vérifiez le statut et l’aperçu avant de publier.</p></div>'; }
		echo '<p><strong>' . ( self::is_published( $target ) ? 'Publié' : 'Brouillon / modèle hérité' ) . '</strong></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dca-editor"><input type="hidden" name="action" value="delicat_builder_v9_save_archive"><input type="hidden" name="target" value="' . esc_attr( $target ) . '">';
		wp_nonce_field( 'delicat_builder_v9_save_archive_' . $target );
		// Preserve all settings not exposed by this compact editor.
		foreach ( self::defaults() as $key => $value ) {
			if ( isset( $checks[ $key ] ) || isset( $fields[ $key ] ) || isset( $numbers[ $key ] ) || 'title_mode' === $key ) { continue; }
			echo '<input type="hidden" name="config[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $config[ $key ] ) . '">';
		}
		echo '<div class="dca-editor-grid"><section><h2>Présentation</h2>';
		self::select_field( 'config[title_mode]', 'Source du titre', $config['title_mode'], array( 'dynamic' => 'Titre automatique', 'custom' => 'Personnalisé', 'hidden' => 'Masqué' ) );
		foreach ( $fields as $key => $label ) { self::text_field( 'config[' . $key . ']', $label, $config[ $key ] ); }
		echo '</section><section><h2>Responsive</h2>';
		foreach ( $numbers as $key => $args ) { self::number_field( 'config[' . $key . ']', $args[0], $config[ $key ], $args[1], $args[2] ); }
		echo '</section><section><h2>Éléments visibles</h2>';
		foreach ( $checks as $key => $label ) { self::checkbox_field( 'config[' . $key . ']', $label, $config[ $key ] ); }
		echo '</section></div><p><button class="button" name="intent" value="draft">Enregistrer le brouillon</button> <a class="button" target="_blank" rel="noopener" href="' . esc_url( self::preview_url( $target ) ) . '">Aperçu réel</a> <button class="button button-primary" name="intent" value="publish">Publier</button></p></form></div>';
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
