<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce presentation engine.
 *
 * Beta 6 intentionally does not replace cart/checkout/account or purchase-form
 * templates. It styles and augments WooCommerce's existing hook-driven markup.
 */
final class Delicat_Builder_V9_Woo_UI {
	public const OPTION = 'delicat_builder_v9_woo_ui';

	private static bool $archive_active = false;
	private static bool $single_active  = false;
	private static bool $safe_native    = false;
	private static bool $inside_archive_product = false;
	private static int $archive_image_index = 0;
	private static ?array $settings_cache = null;
	private static ?array $effective_archive_cache = null;
	private static ?string $archive_sizes_cache = null;
	private static ?array $effective_single_cache = null;

	public static function boot(): void {
		add_action( 'wp', array( __CLASS__, 'detect_context' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 35 );
		add_action( 'wp_head', array( __CLASS__, 'preload_single_image' ), 3 );
		add_action( 'wp_head', array( __CLASS__, 'preload_archive_image' ), 3 );

		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 30 );
		add_filter( 'loop_shop_per_page', array( __CLASS__, 'archive_products_per_page' ), 30 );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'archive_image_attributes' ), 30, 3 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'archive_cta_link' ), 30, 3 );
		if ( class_exists( 'Delicat_Builder_V9_Archive_Builder' ) ) {
			if ( is_callable( array( 'Delicat_Builder_V9_Archive_Builder', 'show_default_page_title' ) ) ) {
				add_filter( 'woocommerce_show_page_title', array( 'Delicat_Builder_V9_Archive_Builder', 'show_default_page_title' ), 30 );
			}
			if ( is_callable( array( 'Delicat_Builder_V9_Archive_Builder', 'render_header' ) ) ) {
				add_action( 'woocommerce_before_shop_loop', array( 'Delicat_Builder_V9_Archive_Builder', 'render_header' ), 2 );
			}
		}
		add_filter( 'woocommerce_output_related_products_args', array( __CLASS__, 'related_products_args' ), 30 );
		add_filter( 'woocommerce_upsell_display_args', array( __CLASS__, 'upsell_products_args' ), 30 );
		add_filter( 'woocommerce_get_stock_html', array( __CLASS__, 'single_stock_html' ), 30, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'single_add_to_cart_text' ), 30, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'archive_add_to_cart_text' ), 30, 2 );
		add_filter( 'post_class', array( __CLASS__, 'product_post_class' ), 30, 3 );

		add_action( 'woocommerce_before_shop_loop_item', array( __CLASS__, 'archive_loop_item_open' ), 0 );
		add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'archive_loop_item_close' ), 999 );
		add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'archive_category_badge' ), 15 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'archive_stock_badge' ), 15 );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'archive_intro_open' ), 4 );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'archive_intro_close' ), 35 );

		add_action( 'woocommerce_before_single_product_summary', array( __CLASS__, 'single_gallery_marker_open' ), 1 );
		add_action( 'woocommerce_before_single_product_summary', array( __CLASS__, 'single_gallery_marker_close' ), 99 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_summary_marker_open' ), 1 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_summary_marker_close' ), 99 );
		add_action( 'woocommerce_before_variations_form', array( __CLASS__, 'render_native_option_heading' ), 5 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_native_availability' ), 11 );
		add_action( 'wp_footer', array( __CLASS__, 'render_native_mobile_dock' ), 24 );
		add_filter( 'woocommerce_single_product_zoom_enabled', array( __CLASS__, 'single_gallery_feature_enabled' ), 30 );
		add_filter( 'woocommerce_single_product_flexslider_enabled', array( __CLASS__, 'single_gallery_feature_enabled' ), 30 );
		add_filter( 'woocommerce_single_product_photoswipe_enabled', array( __CLASS__, 'single_gallery_feature_enabled' ), 30 );
	}

	public static function defaults(): array {
		return array(
			'enabled'              => 0,
			'archive_enabled'      => 1,
			'archive_fast_mode'    => 1,
			'archive_products_per_page' => 12,
			'archive_show_result_count' => 0,
			'archive_show_ordering' => 1,
			'archive_show_rating'  => 0,
			'archive_cta_mode'     => 'product',
			'archive_touch_prefetch' => 1,
			'single_enabled'       => 1,
			'card_style'           => 'premium',
			'grid_desktop'         => 4,
			'grid_tablet'          => 3,
			'grid_mobile'          => 2,
			'image_ratio'          => 'square',
			'show_stock_badge'     => 1,
			'sticky_summary'       => 1,
			'single_image_preload' => 1,
			'single_show_gallery'   => 1,
			'single_show_title'     => 1,
			'single_show_price'     => 1,
			'single_variation_style' => 'cards',
			'single_button_full_width' => 1,
			'max_width'            => 1320,
			'accent_color'         => '#6d5dfc',
			'compact_mobile'       => 1,
		);
	}

	public static function settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$saved = get_option( self::OPTION, array() );
		self::$settings_cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		return self::$settings_cache;
	}

	public static function effective_archive_settings(): array {
		if ( null !== self::$effective_archive_cache ) {
			return self::$effective_archive_cache;
		}
		$settings = self::settings();
		if ( class_exists( 'Delicat_Builder_V9_Archive_Builder' ) ) {
			$overrides = Delicat_Builder_V9_Archive_Builder::woo_overrides();
			if ( ! empty( $overrides ) ) {
				$settings = wp_parse_args( $overrides, $settings );
			}
		}
		self::$effective_archive_cache = $settings;
		return self::$effective_archive_cache;
	}

	public static function effective_single_settings(): array {
		if ( null !== self::$effective_single_cache ) {
			return self::$effective_single_cache;
		}
		$settings = self::settings();
		self::$effective_single_cache = $settings;
		return self::$effective_single_cache;
	}

	private static function native_safe_context(): bool {
		if ( ! Delicat_Builder_V9_Core::is_safe_mode() ) {
			return false;
		}

		if ( self::is_product_archive_context() && class_exists( 'Delicat_Builder_V9_Archive_Builder' ) ) {
			return ! empty( Delicat_Builder_V9_Archive_Builder::active_config() );
		}

		return false;
	}

	public static function enabled(): bool {
		if ( ! Delicat_Builder_V9_Core::is_enabled() || ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		if ( function_exists( 'is_product' ) && is_product() && class_exists( 'Delicat_Builder_V9_Native_Product', false ) && Delicat_Builder_V9_Native_Product::is_active_product() ) {
			return false;
		}

		$settings = self::settings();
		$native_context = false;

		if ( ! $native_context && self::is_product_archive_context() && class_exists( 'Delicat_Builder_V9_Archive_Builder' ) ) {
			$native_context = ! empty( Delicat_Builder_V9_Archive_Builder::active_config() );
		}

		// Publishing a native V9 template is itself an explicit opt-in for that
		// Woo context. Do not make it depend on the older Woo UI master toggle.
		if ( empty( $settings['enabled'] ) && ! $native_context ) {
			return false;
		}

		if ( Delicat_Builder_V9_Core::is_safe_mode() ) {
			return $native_context && self::native_safe_context();
		}

		return true;
	}

	public static function detect_context(): void {
		self::$archive_active = false;
		self::$single_active  = false;
		self::$safe_native    = false;

		if ( ! self::enabled() || is_admin() || wp_doing_ajax() ) {
			return;
		}

		self::$safe_native = Delicat_Builder_V9_Core::is_safe_mode();

		if ( self::is_product_archive_context() ) {
			$settings = self::effective_archive_settings();
			if ( ! empty( $settings['archive_enabled'] ) ) {
				self::$archive_active = true;
				self::$archive_image_index = 0;
				self::configure_archive_presentation();
				if ( class_exists( 'Delicat_Builder_V9_Archive_Builder' ) ) {
					Delicat_Builder_V9_Archive_Builder::configure_frontend();
				}
			}
		}

		// Native Product Builder is the sole V9 single-product presentation owner.
		// Generic Woo UI single-product hooks remain dormant to prevent double styling.
	}

	public static function archive_active(): bool {
		return self::$archive_active;
	}

	public static function archive_fast_active(): bool {
		return self::$archive_active && ! empty( self::effective_archive_settings()['archive_fast_mode'] );
	}

	public static function single_active(): bool {
		return self::$single_active;
	}

	private static function is_product_archive_context(): bool {
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return true;
		}
		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			return true;
		}
		if ( is_post_type_archive( 'product' ) ) {
			return true;
		}

		if ( is_search() ) {
			$post_type = get_query_var( 'post_type' );
			if ( 'product' === $post_type || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) ) {
				return true;
			}
		}

		return false;
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( self::$archive_active ) {
			$settings  = self::effective_archive_settings();
			$style     = self::sanitize_card_style( $settings['card_style'] ?? 'premium' );
			$ratio     = self::sanitize_image_ratio( $settings['image_ratio'] ?? 'square' );
			$desktop   = self::grid_value( $settings['grid_desktop'] ?? 4, 2, 6 );
			$tablet    = self::grid_value( $settings['grid_tablet'] ?? 3, 2, 4 );
			$mobile    = self::grid_value( $settings['grid_mobile'] ?? 2, 1, 2 );

			$classes[] = 'delicat-woo-ui';
			$classes[] = 'delicat-woo-archive';
			$classes[] = 'delicat-native-archive';
			$classes[] = 'delicat-woo-card-' . $style;
			$classes[] = 'delicat-woo-ratio-' . $ratio;
			$classes[] = 'delicat-woo-grid-d-' . $desktop;
			$classes[] = 'delicat-woo-grid-t-' . $tablet;
			$classes[] = 'delicat-woo-grid-m-' . $mobile;

			if ( ! empty( $settings['compact_mobile'] ) ) {
				$classes[] = 'delicat-woo-compact-mobile';
			}
			if ( ! empty( $settings['archive_fast_mode'] ) ) {
				$classes[] = 'delicat-woo-archive-fast';
			}
			$classes[] = 'delicat-woo-shadow-' . sanitize_html_class( (string) ( $settings['archive_card_shadow'] ?? 'soft' ) );
			$classes[] = 'delicat-woo-image-fit-' . sanitize_html_class( (string) ( $settings['archive_image_fit'] ?? 'cover' ) );
			$classes[] = 'delicat-woo-pagination-' . sanitize_html_class( (string) ( $settings['archive_pagination_style'] ?? 'pills' ) );
			if ( empty( $settings['archive_hover_motion'] ) ) {
				$classes[] = 'delicat-woo-hover-off';
			}
			if ( class_exists( 'Delicat_Builder_V9_Archive_Builder' ) && ! empty( Delicat_Builder_V9_Archive_Builder::active_config() ) ) {
				$classes[] = 'delicat-archive-builder-active';
			}
			if ( self::$safe_native ) {
				$classes[] = 'delicat-woo-safe-native';
			}
		}

		if ( self::$single_active ) {
			$settings = self::effective_single_settings();
			$classes[] = 'delicat-woo-ui';
			$classes[] = 'delicat-woo-single';
			if ( ! empty( $settings['sticky_summary'] ) ) {
				$classes[] = 'delicat-woo-sticky-summary';
			}
			if ( ! empty( $settings['single_native_builder'] ) ) {
				$classes[] = 'delicat-native-single';
				$classes[] = 'delicat-single-preset-' . sanitize_html_class( (string) ( $settings['single_preset'] ?? 'topup' ) );
				$classes[] = 'delicat-single-gallery-' . sanitize_html_class( (string) ( $settings['single_gallery_position'] ?? 'left' ) );
				$classes[] = 'delicat-single-cols-' . sanitize_html_class( str_replace( '-', '_', (string) ( $settings['single_column_balance'] ?? '48-52' ) ) );
				$classes[] = 'delicat-single-order-' . sanitize_html_class( (string) ( $settings['single_summary_order'] ?? 'commerce' ) );
				if ( ! empty( $settings['single_fast_mode'] ) ) {
					$classes[] = 'delicat-woo-single-fast';
				}
				if ( ! empty( $settings['single_defer_below_fold'] ) ) {
					$classes[] = 'delicat-woo-single-defer';
				}
				if ( ! empty( $settings['compact_mobile'] ) ) {
					$classes[] = 'delicat-woo-single-compact-mobile';
				}
				$classes[] = 'delicat-single-thumbs-' . max( 3, min( 6, absint( $settings['single_thumbnail_columns'] ?? 5 ) ) );
				$classes[] = 'delicat-single-tabs-' . sanitize_html_class( (string) ( $settings['single_tabs_style'] ?? 'pills' ) );
				$variation_style = in_array( $settings['single_variation_style'] ?? 'cards', array( 'native', 'cards', 'compact' ), true ) ? $settings['single_variation_style'] : 'cards';
				$classes[] = 'delicat-single-variations-' . sanitize_html_class( $variation_style );
				if ( ! empty( $settings['single_native_option_cards'] ) ) {
					$classes[] = 'delicat-single-native-options';
				}
				if ( ! empty( $settings['single_native_mobile_dock'] ) ) {
					$classes[] = 'delicat-single-native-dock';
				}
				if ( ! empty( $settings['single_button_full_width'] ) ) {
					$classes[] = 'delicat-single-button-full';
				}
				if ( empty( $settings['single_show_gallery'] ) ) {
					$classes[] = 'delicat-single-gallery-hidden';
				}
				if ( sanitize_hex_color( (string) ( $settings['single_gallery_bg'] ?? '' ) ) ) {
					$classes[] = 'delicat-single-custom-gallery-bg';
				}
				if ( sanitize_hex_color( (string) ( $settings['single_summary_bg'] ?? '' ) ) ) {
					$classes[] = 'delicat-single-custom-summary-bg';
				}
				if ( self::$safe_native ) {
					$classes[] = 'delicat-woo-safe-native';
				}
			}
		}

		return array_values( array_unique( $classes ) );
	}

private static function configure_archive_presentation(): void {
	$settings = self::effective_archive_settings();

	if ( empty( $settings['archive_show_result_count'] ) ) {
		remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
	}
	if ( empty( $settings['archive_show_ordering'] ) ) {
		remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
	}
	if ( empty( $settings['archive_show_rating'] ) ) {
		remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );
	}
	if ( empty( $settings['archive_show_title'] ) ) {
		remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );
	}
	if ( empty( $settings['archive_show_price'] ) ) {
		remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
	}
	if ( empty( $settings['archive_show_sale_badge'] ) ) {
		remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
	}
	if ( empty( $settings['archive_show_cta'] ) ) {
		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
	}
	if ( empty( $settings['archive_show_pagination'] ) ) {
		remove_action( 'woocommerce_after_shop_loop', 'woocommerce_pagination', 10 );
	}
}

private static function configure_single_presentation(): void {
	$settings = self::effective_single_settings();
	if ( empty( $settings['single_native_builder'] ) ) {
		return;
	}

	if ( empty( $settings['single_show_gallery'] ) ) {
		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
	}
	if ( empty( $settings['single_show_title'] ) ) {
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
	}
	if ( empty( $settings['single_show_price'] ) ) {
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
	}

	if ( empty( $settings['single_show_breadcrumb'] ) ) {
		remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
	}
	if ( empty( $settings['single_show_rating'] ) ) {
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );
	}
	if ( empty( $settings['single_show_short_description'] ) ) {
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
	}
	if ( empty( $settings['single_show_meta'] ) ) {
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
	}
	if ( empty( $settings['single_show_tabs'] ) ) {
		remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
	}
	if ( empty( $settings['single_show_upsells'] ) ) {
		remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
	}
	if ( empty( $settings['single_show_related'] ) ) {
		remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
	}
	if ( empty( $settings['single_show_sale_badge'] ) ) {
		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
	}
}

public static function related_products_args( $args ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}
	if ( ! self::$single_active ) {
		return $args;
	}
	$settings = self::effective_single_settings();
	if ( empty( $settings['single_native_builder'] ) ) {
		return $args;
	}
	$count = absint( $settings['single_related_count'] ?? 4 );
	if ( ! in_array( $count, array( 2, 4, 6, 8 ), true ) ) {
		$count = 4;
	}
	$args['posts_per_page'] = $count;
	$columns = max( 2, min( 4, absint( $settings['single_related_columns'] ?? 4 ) ) );
	$args['columns'] = min( $columns, $count );
	return $args;
}

public static function upsell_products_args( $args ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}
	if ( ! self::$single_active ) {
		return $args;
	}
	$settings = self::effective_single_settings();
	if ( empty( $settings['single_native_builder'] ) ) {
		return $args;
	}
	$args['posts_per_page'] = max( 2, min( 8, absint( $settings['single_upsell_count'] ?? 4 ) ) );
	$args['columns'] = max( 2, min( 4, absint( $settings['single_upsell_columns'] ?? 4 ) ) );
	return $args;
}

public static function single_stock_html( $html, $product = null ) {
	if ( ! is_string( $html ) || ! self::$single_active ) {
		return $html;
	}
	$settings = self::effective_single_settings();
	return empty( $settings['single_show_stock'] ) ? '' : $html;
}

public static function single_add_to_cart_text( $text, $product = null ) {
	if ( ! is_string( $text ) || ! self::$single_active ) {
		return $text;
	}
	$label = trim( (string) ( self::effective_single_settings()['single_add_to_cart_label'] ?? '' ) );
	return '' !== $label ? $label : $text;
}

public static function archive_add_to_cart_text( $text, $product = null ) {
	if ( ! is_string( $text ) || ! self::$archive_active ) {
		return $text;
	}
	$label = trim( (string) ( self::effective_archive_settings()['archive_cta_label'] ?? '' ) );
	return '' !== $label ? $label : $text;
}

public static function archive_products_per_page( $count ): int {
	$settings = self::effective_archive_settings();
	if ( empty( $settings['enabled'] ) || empty( $settings['archive_enabled'] ) ) {
		return max( 1, absint( $count ) );
	}
	return min( 24, max( 8, absint( $settings['archive_products_per_page'] ?? 12 ) ) );
}

public static function archive_loop_item_open(): void {
	if ( self::$archive_active ) {
		self::$inside_archive_product = true;
	}
}

public static function archive_loop_item_close(): void {
	self::$inside_archive_product = false;
}

	/**
	 * The `sizes` attribute for an archive card image.
	 *
	 * This was the fixed string "(max-width:640px) 46vw, (max-width:1024px)
	 * 31vw, 23vw", which describes the default grid -- 2 columns on a phone, 3
	 * on a tablet, 4 on desktop -- and only that grid. The columns are settings
	 * (mobile 1-2, tablet 2-4, desktop 2-6), so any other choice made `sizes` a
	 * lie and the browser chose the wrong candidate from srcset. A merchant
	 * setting one column per phone got images declared at 46vw for a slot twice
	 * that wide, and shipped visibly soft product photos; six desktop columns
	 * got the opposite, paying for pixels it then threw away.
	 *
	 * Derived from the same grid values body_class() uses, so the two cannot
	 * drift, and shared with preload_archive_image(): if the preload's
	 * imagesizes disagreed with the img's sizes, the browser would preload one
	 * candidate and then fetch a different one, downloading the LCP image
	 * twice.
	 *
	 * Breakpoints match the grid tiers in woo-ui.css and
	 * critical/woo-archive.css (<=640 phone, <=1024 tablet). The subtraction
	 * covers gutters and page padding; with the default columns the result is
	 * byte-identical to the string it replaces.
	 */
	private static function archive_image_sizes(): string {
		if ( null !== self::$archive_sizes_cache ) {
			return self::$archive_sizes_cache;
		}

		$settings = self::effective_archive_settings();
		$desktop  = self::grid_value( $settings['grid_desktop'] ?? 4, 2, 6 );
		$tablet   = self::grid_value( $settings['grid_tablet'] ?? 3, 2, 4 );
		$mobile   = self::grid_value( $settings['grid_mobile'] ?? 2, 1, 2 );

		$slot = static function ( int $columns, int $gutter ): int {
			return max( 5, (int) floor( 100 / max( 1, $columns ) ) - $gutter );
		};

		self::$archive_sizes_cache = sprintf(
			'(max-width:640px) %1$dvw, (max-width:1024px) %2$dvw, %3$dvw',
			$slot( $mobile, 4 ),
			$slot( $tablet, 2 ),
			$slot( $desktop, 2 )
		);
		return self::$archive_sizes_cache;
	}

public static function archive_image_attributes( $attr, $attachment = null, $size = '' ) {
	if ( is_array( $attr ) && self::$archive_active && self::$inside_archive_product ) {
		$index = self::$archive_image_index++;
		$attr['decoding'] = 'async';
		$attr['sizes'] = self::archive_image_sizes();

		if ( 0 === $index ) {
			$attr['loading'] = 'eager';
			$attr['fetchpriority'] = 'high';
		} else {
			$attr['loading'] = 'lazy';
			$attr['fetchpriority'] = 'low';
		}
		return $attr;
	}

	if ( self::$single_active ) {
		$product_id = absint( get_queried_object_id() );
		$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		if ( $product instanceof WC_Product && $attachment instanceof WP_Post && absint( $product->get_image_id() ) === absint( $attachment->ID ) ) {
			$attr['loading'] = 'eager';
			$attr['fetchpriority'] = 'high';
			$attr['decoding'] = 'async';
			$attr['sizes'] = '(max-width:768px) 100vw, 50vw';
		}
	}

	return $attr;
}

public static function preload_archive_image(): void {
	if ( ! self::archive_fast_active() || self::$safe_native ) {
		return;
	}

	global $wp_query;
	$posts = isset( $wp_query->posts ) && is_array( $wp_query->posts ) ? $wp_query->posts : array();
	$first = $posts[0] ?? null;
	$product_id = $first instanceof WP_Post ? absint( $first->ID ) : absint( $first );
	if ( $product_id <= 0 ) {
		return;
	}

	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$image_id = $product->get_image_id();
	if ( ! $image_id ) {
		return;
	}
	if ( class_exists( 'Delicat_Builder_V9_Media' ) && is_callable( array( 'Delicat_Builder_V9_Media', 'is_image_attachment' ) ) && ! Delicat_Builder_V9_Media::is_image_attachment( $image_id ) ) {
		return;
	}

	$url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
	if ( ! is_string( $url ) || '' === $url ) {
		return;
	}
	$srcset = wp_get_attachment_image_srcset( $image_id, 'woocommerce_thumbnail' );
	?>
	<link rel="preload" as="image" href="<?php echo esc_url( $url ); ?>" fetchpriority="high"<?php
	if ( $srcset ) {
		echo ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="' . esc_attr( self::archive_image_sizes() ) . '"';
	}
	?>>
	<?php
}

public static function archive_cta_link( $html, $product = null, $args = array() ) {
	/* RC18: third-party callers apply this filter with two arguments; a required
	 * typed third parameter was an ArgumentCountError/TypeError. */
	$args = is_array( $args ) ? $args : array();
	if ( ! is_string( $html ) || ! $product instanceof WC_Product || ! self::$archive_active || self::$safe_native || 'product' !== ( self::effective_archive_settings()['archive_cta_mode'] ?? 'product' ) ) {
		return $html;
	}

	$url = get_permalink( $product->get_id() );
	if ( ! is_string( $url ) || '' === $url ) {
		return $html;
	}

	$class_tokens = preg_split( '/\s+/', (string) ( $args['class'] ?? 'button' ) );
	$class_tokens = array_values( array_filter( array_map( 'sanitize_html_class', (array) $class_tokens ) ) );
	$classes = ! empty( $class_tokens ) ? implode( ' ', $class_tokens ) : 'button';
	$cart_icon = '<svg class="delicat-archive-product-cta__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 4.5h2l1.7 9.1a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20.5 8H6.2"/><circle cx="9.8" cy="19" r="1.2"/><circle cx="17.8" cy="19" r="1.2"/></svg>';
	return sprintf(
		'<a href="%1$s" class="%2$s delicat-archive-product-cta" data-product_id="%3$d" data-delicat-prefetch data-delicat-product-link data-delicat-instant-product>%4$s<span>%5$s</span></a>',
		esc_url( $url ),
		esc_attr( $classes ),
		absint( $product->get_id() ),
		$cart_icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html( trim( (string) ( self::effective_archive_settings()['archive_cta_label'] ?? __( 'Acheter', 'delicat-builder-v9' ) ) ) ?: __( 'Acheter', 'delicat-builder-v9' ) )
	);
}

public static function ultra_fast_archive_profile(): array {
	$current = self::settings();
	return wp_parse_args(
		array(
			'enabled'                    => 1,
			'archive_enabled'            => 1,
			'archive_fast_mode'          => 1,
			'archive_products_per_page'  => 12,
			'archive_show_result_count'  => 0,
			'archive_show_ordering'      => 1,
			'archive_show_rating'        => 0,
			'archive_cta_mode'           => 'product',
			'archive_touch_prefetch'     => 1,
			'card_style'                 => 'premium',
			'grid_desktop'               => 4,
			'grid_tablet'                => 3,
			'grid_mobile'                => 2,
			'compact_mobile'             => 1,
		),
		$current
	);
}

public static function apply_ultra_fast_archive_profile(): void {
	update_option( self::OPTION, self::ultra_fast_archive_profile(), false );
	self::$settings_cache = null;
	Delicat_Builder_V9_Cache::bump_version();
	Delicat_Builder_V9_Cache::purge_product_archives();
}

	public static function product_post_class( $classes, $css_class = array(), $post_id = 0 ) {
		$post_id = absint( $post_id );
		if ( ! is_array( $classes ) || ! self::$archive_active || 'product' !== get_post_type( $post_id ) ) {
			return $classes;
		}

		$classes[] = 'delicat-woo-product-card';
		return array_values( array_unique( $classes ) );
	}

	public static function enqueue_assets(): void {
		if ( ! self::$archive_active && ! self::$single_active ) {
			return;
		}

		$path = DELICAT_BUILDER_V9_DIR . 'assets/css/woo-ui.css';
		if ( ! is_file( $path ) ) {
			return;
		}

		wp_enqueue_style(
			'delicat-builder-v9-woo-ui',
			DELICAT_BUILDER_V9_URL . 'assets/css/woo-ui.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
		if ( self::$archive_active ) {
			$native_archive = DELICAT_BUILDER_V9_DIR . 'assets/css/native-archive.css';
			if ( is_file( $native_archive ) ) {
				wp_enqueue_style( 'delicat-builder-v9-native-archive', DELICAT_BUILDER_V9_URL . 'assets/css/native-archive.css', array( 'delicat-builder-v9-woo-ui' ), DELICAT_BUILDER_V9_VERSION );
			}
		}

		$settings = self::$archive_active ? self::effective_archive_settings() : self::effective_single_settings();
		$accent = sanitize_hex_color( (string) ( $settings['accent_color'] ?? '#6d5dfc' ) ) ?: '#6d5dfc';
		$max = self::max_width( $settings['max_width'] ?? 1320 );
		$radius = max( 10, min( 38, absint( $settings['single_surface_radius'] ?? 24 ) ) );

		if ( self::$archive_active ) {
			$card_bg = sanitize_hex_color( (string) ( $settings['archive_card_bg'] ?? '' ) ) ?: 'var(--delicat-woo-surface)';
			$card_border = sanitize_hex_color( (string) ( $settings['archive_card_border'] ?? '' ) ) ?: 'var(--delicat-woo-border)';
			$image_bg = sanitize_hex_color( (string) ( $settings['archive_image_bg'] ?? '' ) ) ?: 'rgba(128,128,128,.06)';
			$inline = sprintf(
				'.delicat-woo-ui{--delicat-woo-accent:%1$s;--delicat-woo-max:%2$dpx;--delicat-archive-gap-d:%3$dpx;--delicat-archive-gap-m:%4$dpx;--delicat-archive-card-radius:%5$dpx;--delicat-archive-card-bg:%6$s;--delicat-archive-card-border:%7$s;--delicat-archive-image-bg:%8$s;--delicat-archive-title-d:%9$dpx;--delicat-archive-title-m:%10$dpx;--delicat-archive-price-d:%11$dpx;--delicat-archive-price-m:%12$dpx;--delicat-archive-title-lines:%13$d;--delicat-archive-cta-radius:%14$dpx}',
				$accent, $max,
				max( 4, min( 48, absint( $settings['archive_grid_gap_d'] ?? 22 ) ) ),
				max( 4, min( 28, absint( $settings['archive_grid_gap_m'] ?? 10 ) ) ),
				max( 0, min( 36, absint( $settings['archive_card_radius'] ?? 20 ) ) ),
				$card_bg, $card_border, $image_bg,
				max( 12, min( 24, absint( $settings['archive_title_size_d'] ?? 15 ) ) ),
				max( 11, min( 20, absint( $settings['archive_title_size_m'] ?? 13 ) ) ),
				max( 12, min( 26, absint( $settings['archive_price_size_d'] ?? 16 ) ) ),
				max( 11, min( 22, absint( $settings['archive_price_size_m'] ?? 14 ) ) ),
				max( 1, min( 3, absint( $settings['archive_title_lines'] ?? 2 ) ) ),
				max( 8, min( 30, absint( $settings['archive_cta_radius'] ?? 18 ) ) )
			);
		} else {
			$gallery_bg = sanitize_hex_color( (string) ( $settings['single_gallery_bg'] ?? '' ) ) ?: 'transparent';
			$summary_bg = sanitize_hex_color( (string) ( $settings['single_summary_bg'] ?? '' ) ) ?: 'transparent';
			$inline = sprintf(
				'.delicat-woo-ui{--delicat-woo-accent:%1$s;--delicat-woo-max:%2$dpx;--delicat-single-radius:%3$dpx;--delicat-single-gap:%4$dpx;--delicat-single-gallery-padding:%5$dpx;--delicat-single-gallery-radius:%6$dpx;--delicat-single-image-radius:%7$dpx;--delicat-single-gallery-bg:%8$s;--delicat-single-summary-bg:%9$s;--delicat-single-summary-pad-d:%10$dpx;--delicat-single-summary-pad-m:%11$dpx;--delicat-single-title-d:%12$dpx;--delicat-single-title-m:%13$dpx;--delicat-single-price-d:%14$dpx;--delicat-single-price-m:%15$dpx;--delicat-single-button-radius:%16$dpx}',
				$accent, $max, $radius,
				max( 12, min( 80, absint( $settings['single_content_gap'] ?? 40 ) ) ),
				max( 0, min( 32, absint( $settings['single_gallery_padding'] ?? 10 ) ) ),
				max( 0, min( 48, absint( $settings['single_gallery_radius'] ?? 24 ) ) ),
				max( 0, min( 40, absint( $settings['single_gallery_image_radius'] ?? 18 ) ) ),
				$gallery_bg, $summary_bg,
				max( 0, min( 64, absint( $settings['single_summary_padding_d'] ?? 28 ) ) ),
				max( 0, min( 40, absint( $settings['single_summary_padding_m'] ?? 14 ) ) ),
				max( 28, min( 76, absint( $settings['single_title_size_d'] ?? 56 ) ) ),
				max( 24, min( 54, absint( $settings['single_title_size_m'] ?? 36 ) ) ),
				max( 18, min( 46, absint( $settings['single_price_size_d'] ?? 30 ) ) ),
				max( 17, min( 36, absint( $settings['single_price_size_m'] ?? 23 ) ) ),
				max( 8, min( 30, absint( $settings['single_button_radius'] ?? 16 ) ) )
			);
		}
		wp_add_inline_style( 'delicat-builder-v9-woo-ui', $inline );

		if ( self::$single_active && ! empty( $settings['single_native_builder'] ) ) {
			/* 9.1 RC8: the native-single fallback enqueues referenced files that
			 * never shipped (is_file-guarded no-ops since 51.5x). Retired; the
			 * Native Product engine owns single-product documents on this store. */

		}
	}

	public static function preload_single_image(): void {
		if ( ! self::$single_active ) {
			return;
		}

		$settings = self::effective_single_settings();
		if ( empty( $settings['single_image_preload'] ) ) {
			return;
		}

		$product_id = get_queried_object_id();
		$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$image_id = $product->get_image_id();
		if ( ! $image_id ) {
			return;
		}
		if ( class_exists( 'Delicat_Builder_V9_Media' ) && is_callable( array( 'Delicat_Builder_V9_Media', 'is_image_attachment' ) ) && ! Delicat_Builder_V9_Media::is_image_attachment( $image_id ) ) {
			return;
		}

		$url = wp_get_attachment_image_url( $image_id, 'woocommerce_single' );
		if ( ! $url ) {
			return;
		}

		$srcset = wp_get_attachment_image_srcset( $image_id, 'woocommerce_single' );
		$site_host  = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$image_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $image_host && $site_host && $image_host !== $site_host ) {
			$image_origin = (string) wp_parse_url( $url, PHP_URL_SCHEME ) . '://' . $image_host;
			if ( 0 === strpos( $image_origin, 'https://' ) || 0 === strpos( $image_origin, 'http://' ) ) {
				echo '<link rel="preconnect" href="' . esc_url( $image_origin ) . '">';
			}
		}
		?>
		<link rel="preload" as="image" href="<?php echo esc_url( $url ); ?>" fetchpriority="high"<?php
		if ( $srcset ) {
			echo ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="(max-width:768px) 100vw, 50vw"';
		}
		?>>
		<?php
	}

	public static function archive_category_badge(): void {
		if ( ! self::$archive_active || empty( self::effective_archive_settings()['archive_show_category_badge'] ) ) {
			return;
		}
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return;
		}
		$term = reset( $terms );
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		echo '<span class="delicat-archive-category-badge">' . esc_html( $term->name ) . '</span>';
	}

	public static function archive_stock_badge(): void {
		if ( ! self::$archive_active || empty( self::effective_archive_settings()['show_stock_badge'] ) ) {
			return;
		}

		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$in_stock = $product->is_in_stock();
		$class    = $in_stock ? 'is-in-stock' : 'is-out-of-stock';
		$label    = $in_stock ? __( 'Activation rapide', 'delicat-builder-v9' ) : __( 'Réapprovisionnement en cours', 'delicat-builder-v9' );

		printf(
			'<span class="delicat-woo-stock %1$s" aria-label="%2$s">%2$s</span>',
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	public static function archive_intro_open(): void {
		if ( ! self::$archive_active ) {
			return;
		}
		$settings = self::effective_archive_settings();
		if ( empty( $settings['archive_show_result_count'] ) && empty( $settings['archive_show_ordering'] ) ) {
			return;
		}
		echo '<div class="delicat-woo-archive-tools">';
	}

	public static function archive_intro_close(): void {
		if ( ! self::$archive_active ) {
			return;
		}
		$settings = self::effective_archive_settings();
		if ( empty( $settings['archive_show_result_count'] ) && empty( $settings['archive_show_ordering'] ) ) {
			return;
		}
		echo '</div>';
	}


	/**
	 * Keep the existing Player ID / delivery API completely outside Builder V9.
	 * This method only adds a label before WooCommerce's own variation controls.
	 */
	public static function render_native_option_heading(): void {
		if ( ! self::$single_active ) {
			return;
		}
		$settings = self::effective_single_settings();
		if ( empty( $settings['single_native_builder'] ) || empty( $settings['single_native_option_cards'] ) ) {
			return;
		}
		$heading = trim( (string) ( $settings['single_option_heading'] ?? '' ) );
		if ( '' === $heading ) {
			return;
		}
		echo '<div class="delicat-native-options-heading"><span>' . esc_html__( 'OPTIONS', 'delicat-builder-v9' ) . '</span><h2>' . esc_html( $heading ) . '</h2></div>';
	}

	public static function render_native_availability(): void {
		if ( ! self::$single_active ) {
			return;
		}
		$settings = self::effective_single_settings();
		if ( empty( $settings['single_native_builder'] ) || empty( $settings['single_show_native_availability'] ) ) {
			return;
		}
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		/* Product Fields' plan/total card already owns a live availability badge.
		 * Printing a second Woo status above it duplicates “Disponible” and can
		 * visually collide with compact custom-field layouts. */
		$dmc = get_post_meta( $product->get_id(), '_dmc_calc', true );
		if ( is_string( $dmc ) && '' !== $dmc ) {
			$decoded = json_decode( $dmc, true );
			if ( is_array( $decoded ) ) { $dmc = $decoded; }
		}
		$dmc_owns_status = is_array( $dmc ) && ! empty( $dmc['enabled'] ) && ! empty( $dmc['fields'] );
		$dmc_owns_status = (bool) apply_filters( 'delicat_builder_v9_product_fields_owns_availability', $dmc_owns_status, $product );
		if ( $dmc_owns_status ) {
			return;
		}
		if ( $product->is_in_stock() ) {
			echo '<div class="delicat-native-availability is-in-stock" aria-label="' . esc_attr__( 'Stock WooCommerce', 'delicat-builder-v9' ) . '"><span aria-hidden="true">✓</span>' . esc_html__( 'Disponible', 'delicat-builder-v9' ) . '</div>';
		}
	}

	/**
	 * Optimize only a truly single-image Woo gallery. Multi-image products retain
	 * WooCommerce zoom/slider/lightbox exactly as configured by the store.
	 */
	public static function single_gallery_feature_enabled( $enabled ) {
		if ( ! self::$single_active ) {
			return $enabled;
		}
		$settings = self::effective_single_settings();
		if ( empty( $settings['single_native_builder'] ) || empty( $settings['single_optimize_gallery'] ) ) {
			return $enabled;
		}
		global $product;
		if ( ! $product instanceof WC_Product ) {
			$product_id = absint( get_queried_object_id() );
			$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		}
		if ( ! $product instanceof WC_Product ) {
			return $enabled;
		}
		$gallery_ids = $product->get_gallery_image_ids();
		return empty( $gallery_ids ) ? false : $enabled;
	}

	public static function native_single_dock_enabled(): bool {
		if ( ! self::$single_active ) {
			return false;
		}
		$settings = self::effective_single_settings();
		return ! empty( $settings['single_native_builder'] ) && ! empty( $settings['single_native_mobile_dock'] );
	}

	/**
	 * Mobile dock is a presentation mirror only. It never mutates cart/session
	 * state itself. JS delegates clicks to the existing WooCommerce form buttons,
	 * so WooCommerce and the existing Player ID/API validation stay authoritative.
	 */
	public static function render_native_mobile_dock(): void {
		if ( ! self::native_single_dock_enabled() ) {
			return;
		}
		$product_id = absint( get_queried_object_id() );
		$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}
		$settings = self::effective_single_settings();
		$cart_label = trim( (string) ( $settings['single_dock_cart_label'] ?? '' ) );
		$buy_label  = trim( (string) ( $settings['single_dock_buy_label'] ?? '' ) );
		if ( '' === $cart_label ) {
			$cart_label = __( 'Ajouter au panier', 'delicat-builder-v9' );
		}
		if ( '' === $buy_label ) {
			$buy_label = __( 'Acheter maintenant', 'delicat-builder-v9' );
		}
		$disabled = $product->is_type( 'variable' );
		?>
		<div class="delicat-native-purchase-dock" data-delicat-native-dock hidden>
			<div class="delicat-native-purchase-dock__inner">
				<div class="delicat-native-purchase-dock__total">
					<span><?php esc_html_e( 'Total', 'delicat-builder-v9' ); ?></span>
					<strong data-delicat-native-dock-price><?php echo wp_kses_post( $product->get_price_html() ); ?></strong>
				</div>
				<div class="delicat-native-purchase-dock__actions">
					<button type="button" class="delicat-native-purchase-dock__cart" data-delicat-native-dock-cart <?php disabled( $disabled ); ?>><?php echo esc_html( $cart_label ); ?></button>
					<button type="button" class="delicat-native-purchase-dock__buy" data-delicat-native-dock-buy hidden><?php echo esc_html( $buy_label ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	public static function single_gallery_marker_open(): void {
		if ( self::$single_active && ! empty( self::effective_single_settings()['single_show_gallery'] ) ) {
			echo '<div class="delicat-woo-gallery-zone">';
		}
	}

	public static function single_gallery_marker_close(): void {
		if ( self::$single_active && ! empty( self::effective_single_settings()['single_show_gallery'] ) ) {
			echo '</div>';
		}
	}

	public static function single_summary_marker_open(): void {
		if ( self::$single_active ) {
			echo '<div class="delicat-woo-summary-zone">';
		}
	}

	public static function single_summary_marker_close(): void {
		if ( self::$single_active ) {
			echo '</div>';
		}
	}

	public static function sanitize_card_style( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'premium', 'glass', 'minimal', 'gaming' ), true ) ? $value : 'premium';
	}

	public static function sanitize_image_ratio( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'square', 'portrait', 'landscape' ), true ) ? $value : 'square';
	}

	public static function grid_value( $value, int $min, int $max ): int {
		return min( $max, max( $min, absint( $value ) ) );
	}

	public static function max_width( $value ): int {
		$value = absint( $value );
		return in_array( $value, array( 1200, 1320, 1440 ), true ) ? $value : 1320;
	}
}
