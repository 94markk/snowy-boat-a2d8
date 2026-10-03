<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purchase experience layer.
 *
 * Purchase Studio does not create cart/order/payment endpoints. It enhances the existing
 * WooCommerce forms and lets WooCommerce remain the authority for validation,
 * sessions, cart mutations, checkout and payments.
 */
final class Delicat_Builder_V9_Purchase_UI {
	public const OPTION = 'delicat_builder_v9_purchase_ui';

	private static ?array $settings_cache = null;
	private static bool $single_active   = false;
	private static bool $cart_active     = false;
	private static bool $checkout_active = false;

	public static function boot(): void {
		add_action( 'wp', array( __CLASS__, 'detect_context' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 40 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 40 );

		// Official WooCommerce quantity-input extension points.
		add_action( 'woocommerce_before_quantity_input_field', array( __CLASS__, 'quantity_minus' ), 10 );
		add_action( 'woocommerce_after_quantity_input_field', array( __CLASS__, 'quantity_plus' ), 10 );

		add_action( 'wp_footer', array( __CLASS__, 'mobile_purchase_dock' ), 25 );
		add_action( 'wp_footer', array( __CLASS__, 'aria_live_region' ), 26 );
	}

	public static function defaults(): array {
		return array(
			'enabled'              => 0,
			'quantity_buttons'     => 1,
			'cart_auto_update'     => 0,
			'mobile_purchase_dock' => 1,
			'notice_toasts'        => 0,
			'cart_style'           => 1,
			'checkout_style'       => 1,
			'native_cart'          => 1,
			'native_checkout'      => 1,
			'compact_checkout'     => 0,
			'accent_color'         => '#6d5dfc',
			'max_width'            => 1200,
			'mobile_dock_label'    => __( 'Acheter maintenant', 'delicat-builder-v9' ),
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

	public static function enabled(): bool {
		if ( ! Delicat_Builder_V9_Core::is_enabled() || Delicat_Builder_V9_Core::is_safe_mode() || ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		return ! empty( self::settings()['enabled'] );
	}

	public static function detect_context(): void {
		self::$single_active   = false;
		self::$cart_active     = false;
		self::$checkout_active = false;

		if ( ! self::enabled() || is_admin() || wp_doing_ajax() ) {
			return;
		}

		self::$single_active = function_exists( 'is_product' ) && is_product();
		if ( self::$single_active && class_exists( 'Delicat_Builder_V9_Native_Product', false ) && Delicat_Builder_V9_Native_Product::is_active_product() ) {
			self::$single_active = false;
		}
		self::$cart_active = function_exists( 'is_cart' ) && is_cart();
		$is_received = function_exists( 'is_order_received_page' ) && is_order_received_page();
		self::$checkout_active = function_exists( 'is_checkout' ) && is_checkout() && ! $is_received;
	}

	public static function single_active(): bool {
		return self::$single_active;
	}

	public static function cart_active(): bool {
		return self::$cart_active;
	}

	public static function checkout_active(): bool {
		return self::$checkout_active;
	}

	private static function native_cart_surface(): bool {
		return class_exists( 'Delicat_Builder_V9_Purchase_Native', false )
			&& is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'cart_surface_active' ) )
			&& Delicat_Builder_V9_Purchase_Native::cart_surface_active();
	}

	private static function native_checkout_surface(): bool {
		return class_exists( 'Delicat_Builder_V9_Purchase_Native', false )
			&& is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'checkout_shell_active' ) )
			&& Delicat_Builder_V9_Purchase_Native::checkout_shell_active();
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( ! self::enabled() ) {
			return $classes;
		}

		if ( self::$single_active ) {
			$classes[] = 'delicat-purchase-ui';
			$classes[] = 'delicat-purchase-single';
		}
		if ( self::$cart_active && ! self::native_cart_surface() && ! empty( self::settings()['cart_style'] ) ) {
			$classes[] = 'delicat-purchase-ui';
			$classes[] = 'delicat-purchase-cart';
		}
		if ( self::$checkout_active && ! self::native_checkout_surface() && ! empty( self::settings()['checkout_style'] ) ) {
			$classes[] = 'delicat-purchase-ui';
			$classes[] = 'delicat-purchase-checkout';
			if ( ! empty( self::settings()['compact_checkout'] ) ) {
				$classes[] = 'delicat-purchase-checkout-compact';
			}
		}
		return array_values( array_unique( $classes ) );
	}

	private static function needs_css(): bool {
		$settings = self::settings();

		/* RC19: the native cart/checkout own their notices; no toast layer there. */
		return self::$single_active
			|| ( self::$cart_active && ! self::native_cart_surface() && ! empty( $settings['cart_style'] ) )
			|| ( self::$checkout_active && ! self::native_checkout_surface() && ! empty( $settings['checkout_style'] ) );
	}

	private static function needs_js(): bool {
		$settings = self::settings();

		if ( self::$single_active ) {
			return ! empty( $settings['quantity_buttons'] )
				|| ! empty( $settings['mobile_purchase_dock'] )
				|| ! empty( $settings['notice_toasts'] );
		}

		if ( self::$cart_active ) {
			if ( self::native_cart_surface() ) {
				return false; /* RC19: native cart never mirrors notices. */
			}
			return ! empty( $settings['quantity_buttons'] )
				|| ! empty( $settings['cart_auto_update'] )
				|| ! empty( $settings['notice_toasts'] );
		}

		if ( self::$checkout_active ) {
			return ! self::native_checkout_surface() && ! empty( $settings['notice_toasts'] );
		}

		return false;
	}

	public static function enqueue_assets(): void {
		if ( ! self::enabled() || ( ! self::$single_active && ! self::$cart_active && ! self::$checkout_active ) ) {
			return;
		}

		$settings = self::settings();

		if ( self::needs_css() ) {
			$css_path = DELICAT_BUILDER_V9_DIR . 'assets/css/purchase-ui.css';
			if ( is_file( $css_path ) ) {
				wp_enqueue_style(
					'delicat-builder-v9-purchase-ui',
					DELICAT_BUILDER_V9_URL . 'assets/css/purchase-ui.css',
					array(),
					DELICAT_BUILDER_V9_VERSION
				);

				$accent = sanitize_hex_color( (string) ( $settings['accent_color'] ?? '#6d5dfc' ) ) ?: '#6d5dfc';
				$max = self::max_width( $settings['max_width'] ?? 1200 );
				wp_add_inline_style(
					'delicat-builder-v9-purchase-ui',
					sprintf(
						'.delicat-purchase-ui{--delicat-purchase-accent:%1$s;--delicat-purchase-max:%2$dpx}',
						$accent,
						$max
					)
				);
			}
		}

		if ( ! self::needs_js() ) {
			return;
		}

		$js_path = DELICAT_BUILDER_V9_DIR . 'assets/js/purchase.js';
		if ( ! is_file( $js_path ) ) {
			return;
		}

		wp_enqueue_script(
			'delicat-builder-v9-purchase',
			DELICAT_BUILDER_V9_URL . 'assets/js/purchase.js',
			array(),
			DELICAT_BUILDER_V9_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'single'           => self::$single_active,
			'cart'             => self::$cart_active,
			'checkout'         => self::$checkout_active,
			'quantityButtons'  => ! self::native_cart_surface() && ! empty( $settings['quantity_buttons'] ),
			'cartAutoUpdate'   => ! self::native_cart_surface() && ! empty( $settings['cart_auto_update'] ),
			'mobileDock'       => self::$single_active && ! empty( $settings['mobile_purchase_dock'] ) && ! ( class_exists( 'Delicat_Builder_V9_Woo_UI' ) && is_callable( array( 'Delicat_Builder_V9_Woo_UI', 'native_single_dock_enabled' ) ) && Delicat_Builder_V9_Woo_UI::native_single_dock_enabled() ),
			'noticeToasts'     => ! empty( $settings['notice_toasts'] ),
			'updateDelay'      => 500,
		);

		wp_add_inline_script(
			'delicat-builder-v9-purchase',
			'window.DelicaPurchaseV9=' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	private static function quantity_controls_allowed(): bool {
		if ( ! self::enabled() || empty( self::settings()['quantity_buttons'] ) ) {
			return false;
		}

		return self::$single_active || ( self::$cart_active && ! self::native_cart_surface() );
	}

	public static function quantity_minus(): void {
		if ( ! self::quantity_controls_allowed() ) {
			return;
		}

		echo '<button type="button" class="delicat-qty-button delicat-qty-minus" data-delicat-qty-minus aria-label="' . esc_attr__( 'Réduire la quantité', 'delicat-builder-v9' ) . '">−</button>';
	}

	public static function quantity_plus(): void {
		if ( ! self::quantity_controls_allowed() ) {
			return;
		}

		echo '<button type="button" class="delicat-qty-button delicat-qty-plus" data-delicat-qty-plus aria-label="' . esc_attr__( 'Augmenter la quantité', 'delicat-builder-v9' ) . '">+</button>';
	}

	public static function mobile_purchase_dock(): void {
		if ( class_exists( 'Delicat_Builder_V9_Woo_UI' ) && is_callable( array( 'Delicat_Builder_V9_Woo_UI', 'native_single_dock_enabled' ) ) && Delicat_Builder_V9_Woo_UI::native_single_dock_enabled() ) {
			return;
		}

		if (
			! self::$single_active
			|| ! self::enabled()
			|| empty( self::settings()['mobile_purchase_dock'] )
		) {
			return;
		}

		$product_id = get_queried_object_id();
		$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if (
			! $product->is_purchasable()
			|| ! $product->is_in_stock()
			|| ! in_array( $product->get_type(), array( 'simple', 'variable' ), true )
		) {
			return;
		}

		$settings = self::settings();
		$label = trim( (string) ( $settings['mobile_dock_label'] ?? '' ) );
		if ( '' === $label ) {
			$label = __( 'Acheter maintenant', 'delicat-builder-v9' );
		}

		$disabled = $product->is_type( 'variable' );
		?>
		<div class="delicat-mobile-purchase" data-delicat-mobile-purchase hidden>
			<div class="delicat-mobile-purchase__inner">
				<div class="delicat-mobile-purchase__meta">
					<strong class="delicat-mobile-purchase__title"><?php echo esc_html( $product->get_name() ); ?></strong>
					<span class="delicat-mobile-purchase__price" data-delicat-dock-price><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
				</div>
				<button
					type="button"
					class="delicat-mobile-purchase__button"
					data-delicat-dock-submit
					<?php disabled( $disabled ); ?>
				>
					<?php echo esc_html( $label ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	public static function aria_live_region(): void {
		if ( ! self::enabled() || empty( self::settings()['notice_toasts'] ) ) {
			return;
		}

		if ( ! self::$single_active && ! self::$cart_active && ! self::$checkout_active ) {
			return;
		}
		if ( ( self::$cart_active && self::native_cart_surface() ) || ( self::$checkout_active && self::native_checkout_surface() ) ) {
			return; /* RC19: no toast host on the native purchase surfaces. */
		}

		echo '<div class="delicat-purchase-live" data-delicat-purchase-live aria-live="polite" aria-atomic="true"></div>';
		echo '<div class="delicat-purchase-toasts" data-delicat-purchase-toasts aria-live="polite" aria-atomic="false"></div>';
	}

	public static function max_width( $value ): int {
		$value = absint( $value );
		return in_array( $value, array( 1080, 1200, 1320, 1440 ), true ) ? $value : 1200;
	}
}
